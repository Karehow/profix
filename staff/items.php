<?php
require_once __DIR__ . '/../config/set_availability.php';
require_once __DIR__ . '/../config/borrow_service.php';

ensure_feature_tables($conn);
$staffUser = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$staffId = (int) $staffUser['user_id'];

function inventory_page_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new BorrowWorkflowException('ไม่สามารถเตรียมคำสั่งฐานข้อมูลได้: ' . mysqli_error($conn));
    }
    return $stmt;
}

function inventory_page_execute(mysqli_stmt $stmt): void
{
    if (!mysqli_stmt_execute($stmt)) {
        $message = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new BorrowWorkflowException($message ?: 'ไม่สามารถบันทึกข้อมูลได้');
    }
}

function inventory_page_int($value, string $label, int $minimum = 0): int
{
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        throw new BorrowWorkflowException($label . ' ต้องเป็นจำนวนเต็ม');
    }
    $number = (int) $raw;
    if ($number < $minimum) {
        throw new BorrowWorkflowException($label . ' ต้องไม่น้อยกว่า ' . $minimum);
    }
    return $number;
}

function inventory_page_text($value, string $label, int $maximumLength, bool $required = true): string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    if ($required && $text === '') {
        throw new BorrowWorkflowException('กรุณาระบุ' . $label);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > $maximumLength) {
        throw new BorrowWorkflowException($label . 'ยาวเกิน ' . $maximumLength . ' ตัวอักษร');
    }
    return $text;
}

function inventory_page_upload(array $file, string $prefix): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new BorrowWorkflowException('อัปโหลดรูปภาพไม่สำเร็จ');
    }
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new BorrowWorkflowException('รูปภาพต้องมีขนาดไม่เกิน 5 MB');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $mime = $temporaryPath !== '' ? mime_content_type($temporaryPath) : false;
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if ($mime === false || !isset($allowedTypes[$mime])) {
        throw new BorrowWorkflowException('รองรับเฉพาะรูป JPG, PNG, WEBP หรือ GIF');
    }

    $uploadDirectory = __DIR__ . '/../uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new BorrowWorkflowException('ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้');
    }
    $filename = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mime];
    if (!move_uploaded_file($temporaryPath, $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
        throw new BorrowWorkflowException('ไม่สามารถบันทึกรูปภาพได้');
    }
    return '../uploads/' . $filename;
}

function inventory_page_require_category(mysqli $conn, int $categoryId): void
{
    $stmt = inventory_page_prepare($conn, 'SELECT category_id FROM categories WHERE category_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $categoryId);
    inventory_page_execute($stmt);
    $category = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$category) {
        throw new BorrowWorkflowException('ไม่พบหมวดหมู่ที่เลือก');
    }
}

function inventory_page_item_has_history(mysqli $conn, int $itemId): bool
{
    $stmt = inventory_page_prepare($conn, 'SELECT EXISTS(SELECT 1 FROM borrow_items WHERE item_id = ? LIMIT 1) AS has_history');
    mysqli_stmt_bind_param($stmt, 'i', $itemId);
    inventory_page_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int) ($row['has_history'] ?? 0) === 1;
}

function inventory_page_reserved_peak(mysqli $conn, int $itemId): int
{
    $stmt = inventory_page_prepare($conn, "SELECT r.start_date, r.end_date, r.quantity
        FROM reservations r
        LEFT JOIN borrow_requests br ON br.request_id = r.borrow_request_id
        WHERE r.item_id = ?
          AND (
              r.status = 'approved'
              OR (r.status = 'fulfilled' AND br.status = 'pending_approval')
          )
          AND r.end_date > NOW()");
    mysqli_stmt_bind_param($stmt, 'i', $itemId);
    inventory_page_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $events = [];
    while ($reservation = mysqli_fetch_assoc($result)) {
        $quantity = max(0, (int) $reservation['quantity']);
        $events[] = ['at' => strtotime($reservation['start_date']), 'delta' => $quantity];
        $events[] = ['at' => strtotime($reservation['end_date']), 'delta' => -$quantity];
    }
    mysqli_stmt_close($stmt);
    usort($events, static function (array $left, array $right): int {
        return $left['at'] <=> $right['at'] ?: $left['delta'] <=> $right['delta'];
    });
    $running = 0;
    $peak = 0;
    foreach ($events as $event) {
        $running += $event['delta'];
        $peak = max($peak, $running);
    }
    return $peak;
}

$mutation = null;
foreach (['add_item', 'edit_item', 'toggle_item', 'add_component', 'edit_component', 'delete_component'] as $candidate) {
    if (isset($_POST[$candidate])) {
        $mutation = $candidate;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mutation !== null) {
    require_csrf();
    $newUploadPath = null;
    try {
        if ($mutation === 'add_item') {
            $name = inventory_page_text($_POST['item_name'] ?? '', 'ชื่อสิ่งของ', 200);
            $description = inventory_page_text($_POST['description'] ?? '', 'รายละเอียด', 5000, false);
            $categoryId = inventory_page_int($_POST['category_id'] ?? '', 'หมวดหมู่', 1);
            $total = inventory_page_int($_POST['total_quantity'] ?? '', 'จำนวนทั้งหมด', 1);
            $isSet = isset($_POST['is_set']) ? 1 : 0;
            inventory_page_require_category($conn, $categoryId);
            $imagePath = inventory_page_upload($_FILES['item_image'] ?? [], 'item');
            $newUploadPath = $imagePath !== '' ? __DIR__ . '/../uploads/' . basename($imagePath) : null;

            mysqli_begin_transaction($conn);
            $stmt = inventory_page_prepare($conn, 'INSERT INTO items (item_name, category_id, description, image_url, total_quantity, available_quantity, is_set, is_active) VALUES (?, ?, ?, ?, 0, 0, ?, 1)');
            mysqli_stmt_bind_param($stmt, 'sissi', $name, $categoryId, $description, $imagePath, $isSet);
            inventory_page_execute($stmt);
            $itemId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            inventory_adjust($conn, $itemId, $total, $total, 'stock_created', $staffId, null, null, null, null, 'เพิ่มรายการสิ่งของเข้าคลัง');
            write_audit_log($conn, $staffId, 'create_item', 'รายการ #' . $itemId . ' จำนวน ' . $total);
            mysqli_commit($conn);
            flash_set('success', 'เพิ่มสิ่งของเรียบร้อยแล้ว');
        } elseif ($mutation === 'edit_item') {
            $itemId = inventory_page_int($_POST['item_id'] ?? '', 'รหัสสิ่งของ', 1);
            $name = inventory_page_text($_POST['item_name'] ?? '', 'ชื่อสิ่งของ', 200);
            $description = inventory_page_text($_POST['description'] ?? '', 'รายละเอียด', 5000, false);
            $categoryId = inventory_page_int($_POST['category_id'] ?? '', 'หมวดหมู่', 1);
            $desiredTotal = inventory_page_int($_POST['total_quantity'] ?? '', 'จำนวนทั้งหมด', 0);
            $isSet = isset($_POST['is_set']) ? 1 : 0;
            inventory_page_require_category($conn, $categoryId);
            $imagePath = inventory_page_upload($_FILES['item_image'] ?? [], 'item');
            $newUploadPath = $imagePath !== '' ? __DIR__ . '/../uploads/' . basename($imagePath) : null;

            mysqli_begin_transaction($conn);
            $lock = inventory_page_prepare($conn, 'SELECT total_quantity, available_quantity, is_set FROM items WHERE item_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $itemId);
            inventory_page_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$current) {
                throw new BorrowWorkflowException('ไม่พบสิ่งของที่ต้องการแก้ไข');
            }
            if ((int) $current['is_set'] !== $isSet && inventory_page_item_has_history($conn, $itemId)) {
                throw new BorrowWorkflowException('เปลี่ยนชนิดชุดไม่ได้ เนื่องจากรายการนี้มีประวัติการยืมแล้ว');
            }
            if ((int) $current['is_set'] === 1 && $isSet === 0) {
                $componentCountStmt = inventory_page_prepare($conn, 'SELECT COUNT(*) AS total FROM item_components WHERE parent_item_id = ?');
                mysqli_stmt_bind_param($componentCountStmt, 'i', $itemId);
                inventory_page_execute($componentCountStmt);
                $componentCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($componentCountStmt))['total'] ?? 0);
                mysqli_stmt_close($componentCountStmt);
                if ($componentCount > 0) {
                    throw new BorrowWorkflowException('เปลี่ยนเป็นของชิ้นเดียวไม่ได้ กรุณานำชิ้นส่วนออกจากชุดก่อน');
                }
            }

            $metadataSql = 'UPDATE items SET item_name = ?, category_id = ?, description = ?, is_set = ?';
            if ($imagePath !== '') {
                $metadataSql .= ', image_url = ?';
            }
            $metadataSql .= ' WHERE item_id = ?';
            $stmt = inventory_page_prepare($conn, $metadataSql);
            if ($imagePath !== '') {
                mysqli_stmt_bind_param($stmt, 'sisisi', $name, $categoryId, $description, $isSet, $imagePath, $itemId);
            } else {
                mysqli_stmt_bind_param($stmt, 'sisii', $name, $categoryId, $description, $isSet, $itemId);
            }
            inventory_page_execute($stmt);
            mysqli_stmt_close($stmt);

            // Preserve units currently borrowed, reserved for handover, damaged, or in maintenance.
            // Editing the catalogue may add/remove only units that are physically ready in storage.
            $unavailable = (int) $current['total_quantity'] - (int) $current['available_quantity'];
            if ($desiredTotal < $unavailable) {
                throw new BorrowWorkflowException('จำนวนทั้งหมดใหม่ต่ำกว่าจำนวนที่กำลังยืม/ซ่อม/พักไว้ (' . $unavailable . ')');
            }
            $totalDelta = $desiredTotal - (int) $current['total_quantity'];
            $availableDelta = $totalDelta;
            $desiredAvailable = (int) $current['available_quantity'] + $availableDelta;
            $reservedPeak = inventory_page_reserved_peak($conn, $itemId);
            if ($desiredAvailable < $reservedPeak) {
                throw new BorrowWorkflowException('ลดจำนวนไม่ได้ เพราะมียอดจองที่อนุมัติไว้สูงสุด ' . $reservedPeak . ' หน่วยในช่วงเวลาเดียวกัน');
            }
            if ($totalDelta !== 0 || $availableDelta !== 0) {
                inventory_adjust($conn, $itemId, $availableDelta, $totalDelta, 'manual_adjustment', $staffId, null, null, null, null, 'ปรับยอดจากหน้าคลัง');
            }
            write_audit_log($conn, $staffId, 'update_item', 'รายการ #' . $itemId . '; total delta ' . $totalDelta . '; available delta ' . $availableDelta);
            mysqli_commit($conn);
            flash_set('success', 'แก้ไขสิ่งของและบันทึกความเคลื่อนไหวสต็อกแล้ว');
        } elseif ($mutation === 'toggle_item') {
            $itemId = inventory_page_int($_POST['item_id'] ?? '', 'รหัสสิ่งของ', 1);
            $isActive = (string) ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
            mysqli_begin_transaction($conn);
            $lock = inventory_page_prepare($conn, 'SELECT is_active FROM items WHERE item_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $itemId);
            inventory_page_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$current) {
                throw new BorrowWorkflowException('ไม่พบสิ่งของที่ต้องการเปลี่ยนสถานะ');
            }
            if ((int) $current['is_active'] === $isActive) {
                throw new BorrowWorkflowException('สถานะสิ่งของไม่มีการเปลี่ยนแปลง');
            }
            if ($isActive === 0) {
                $openRequestStmt = inventory_page_prepare($conn, "SELECT EXISTS(
                        SELECT 1
                        FROM borrow_items bi
                        JOIN borrow_requests br ON br.request_id = bi.request_id
                        WHERE bi.item_id = ? AND br.status IN ('pending_approval', 'approved')
                        LIMIT 1
                    ) AS has_open_request");
                mysqli_stmt_bind_param($openRequestStmt, 'i', $itemId);
                inventory_page_execute($openRequestStmt);
                $openRequest = mysqli_fetch_assoc(mysqli_stmt_get_result($openRequestStmt));
                mysqli_stmt_close($openRequestStmt);
                if ((int) ($openRequest['has_open_request'] ?? 0) === 1) {
                    throw new BorrowWorkflowException('ปิดรายการไม่ได้ เพราะมีคำขอรออนุมัติหรือรอส่งมอบ กรุณาจัดการคำขอก่อน');
                }
            }
            if ($isActive === 0 && inventory_page_reserved_peak($conn, $itemId) > 0) {
                throw new BorrowWorkflowException('ปิดรายการไม่ได้ เพราะมีการจองที่อนุมัติแล้ว กรุณายกเลิกการจองก่อน');
            }
            $update = inventory_page_prepare($conn, 'UPDATE items SET is_active = ? WHERE item_id = ?');
            mysqli_stmt_bind_param($update, 'ii', $isActive, $itemId);
            inventory_page_execute($update);
            mysqli_stmt_close($update);
            write_audit_log($conn, $staffId, $isActive === 1 ? 'activate_item' : 'deactivate_item', 'รายการ #' . $itemId);
            mysqli_commit($conn);
            flash_set('success', $isActive === 1 ? 'เปิดให้ยืมรายการนี้แล้ว' : 'ปิดรายการนี้จากหน้ายืมแล้ว');
        } elseif ($mutation === 'add_component') {
            $isAvailable = isset($_POST['component_unavailable']) ? 0 : 1;
            $parentId = inventory_page_int($_POST['parent_item_id'] ?? '', 'รหัสชุด', 1);
            $componentName = inventory_page_text($_POST['component_name'] ?? '', 'ชื่อรายการย่อย', 200);
            $quantityPerSet = inventory_page_int($_POST['quantity_per_set'] ?? '', 'จำนวนต่อชุด', 1);
            $unit = inventory_page_text($_POST['unit'] ?? '', 'หน่วยนับ', 50);
            $imagePath = inventory_page_upload($_FILES['component_image'] ?? [], 'component');
            $newUploadPath = $imagePath !== '' ? __DIR__ . '/../uploads/' . basename($imagePath) : null;

            mysqli_begin_transaction($conn);
            $parentStmt = inventory_page_prepare($conn, 'SELECT is_set FROM items WHERE item_id = ? AND is_active = 1 FOR UPDATE');
            mysqli_stmt_bind_param($parentStmt, 'i', $parentId);
            inventory_page_execute($parentStmt);
            $parent = mysqli_fetch_assoc(mysqli_stmt_get_result($parentStmt));
            mysqli_stmt_close($parentStmt);
            if (!$parent || (int) $parent['is_set'] !== 1) {
                throw new BorrowWorkflowException('เพิ่มชิ้นส่วนได้เฉพาะสิ่งของชนิดชุดที่เปิดใช้งาน');
            }
            $stmt = inventory_page_prepare($conn, 'INSERT INTO item_components (parent_item_id, component_name, image_url, quantity_per_set, unit, is_available) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'issisi', $parentId, $componentName, $imagePath, $quantityPerSet, $unit, $isAvailable);
            inventory_page_execute($stmt);
            $componentId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            inventory_adjust($conn, $parentId, 0, 0, 'component_catalog_changed', $staffId);
            write_audit_log($conn, $staffId, 'create_item_component', 'ชิ้นส่วน #' . $componentId . ' ของรายการ #' . $parentId);
            mysqli_commit($conn);
            flash_set('success', 'เพิ่มชิ้นส่วนในชุดเรียบร้อยแล้ว');
        } elseif ($mutation === 'edit_component') {
            $isAvailable = isset($_POST['component_unavailable']) ? 0 : 1;
            $componentId = inventory_page_int($_POST['component_id'] ?? '', 'รหัสชิ้นส่วน', 1);
            $componentName = inventory_page_text($_POST['component_name'] ?? '', 'ชื่อรายการย่อย', 200);
            $quantityPerSet = inventory_page_int($_POST['quantity_per_set'] ?? '', 'จำนวนต่อชุด', 1);
            $unit = inventory_page_text($_POST['unit'] ?? '', 'หน่วยนับ', 50);
            $imagePath = inventory_page_upload($_FILES['component_image'] ?? [], 'component');
            $newUploadPath = $imagePath !== '' ? __DIR__ . '/../uploads/' . basename($imagePath) : null;

            mysqli_begin_transaction($conn);
            $lock = inventory_page_prepare($conn, 'SELECT component_id, parent_item_id FROM item_components WHERE component_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $componentId);
            inventory_page_execute($lock);
            $component = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$component) {
                throw new BorrowWorkflowException('ไม่พบชิ้นส่วนที่ต้องการแก้ไข');
            }
            inventory_adjust($conn, (int) $component['parent_item_id'], 0, 0, 'component_catalog_changed', $staffId);
            $sql = 'UPDATE item_components SET component_name = ?, quantity_per_set = ?, unit = ?, is_available = ?';
            if ($imagePath !== '') {
                $sql .= ', image_url = ?';
            }
            $sql .= ' WHERE component_id = ?';
            $stmt = inventory_page_prepare($conn, $sql);
            if ($imagePath !== '') {
                mysqli_stmt_bind_param($stmt, 'sisisi', $componentName, $quantityPerSet, $unit, $isAvailable, $imagePath, $componentId);
            } else {
                mysqli_stmt_bind_param($stmt, 'sisii', $componentName, $quantityPerSet, $unit, $isAvailable, $componentId);
            }
            inventory_page_execute($stmt);
            mysqli_stmt_close($stmt);
            write_audit_log($conn, $staffId, 'update_item_component', 'ชิ้นส่วน #' . $componentId . '; พร้อมให้ยืม=' . $isAvailable);
            inventory_adjust($conn, (int) $component['parent_item_id'], 0, 0, 'component_catalog_changed', $staffId);
            mysqli_commit($conn);
            flash_set('success', 'แก้ไขชิ้นส่วนเรียบร้อยแล้ว โดยประวัติการยืมเดิมยังใช้ข้อมูล snapshot');
        } elseif ($mutation === 'delete_component') {
            $componentId = inventory_page_int($_POST['component_id'] ?? '', 'รหัสชิ้นส่วน', 1);
            mysqli_begin_transaction($conn);
            $lock = inventory_page_prepare($conn, 'SELECT component_id, parent_item_id, component_name FROM item_components WHERE component_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $componentId);
            inventory_page_execute($lock);
            $component = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$component) {
                throw new BorrowWorkflowException('ไม่พบชิ้นส่วนที่ต้องการลบ');
            }
            if (inventory_page_item_has_history($conn, (int) $component['parent_item_id'])) {
                throw new BorrowWorkflowException('ลบชิ้นส่วนไม่ได้ เนื่องจากชุดนี้มีประวัติการยืมแล้ว ให้แก้ชื่อหรือเก็บรายการไว้เพื่อรักษาประวัติ');
            }
            $maintenanceStmt = inventory_page_prepare($conn, 'SELECT EXISTS(SELECT 1 FROM maintenance_records WHERE component_id = ? LIMIT 1) AS has_history');
            mysqli_stmt_bind_param($maintenanceStmt, 'i', $componentId);
            inventory_page_execute($maintenanceStmt);
            $maintenance = mysqli_fetch_assoc(mysqli_stmt_get_result($maintenanceStmt));
            mysqli_stmt_close($maintenanceStmt);
            if ((int) ($maintenance['has_history'] ?? 0) === 1) {
                throw new BorrowWorkflowException('ลบชิ้นส่วนไม่ได้ เนื่องจากมีประวัติซ่อมบำรุง');
            }
            $delete = inventory_page_prepare($conn, 'DELETE FROM item_components WHERE component_id = ?');
            mysqli_stmt_bind_param($delete, 'i', $componentId);
            inventory_page_execute($delete);
            if (mysqli_stmt_affected_rows($delete) !== 1) {
                mysqli_stmt_close($delete);
                throw new BorrowWorkflowException('ไม่สามารถลบชิ้นส่วนได้');
            }
            mysqli_stmt_close($delete);
            write_audit_log($conn, $staffId, 'delete_item_component', 'ชิ้นส่วน #' . $componentId . ' ' . $component['component_name']);
            inventory_adjust($conn, (int) $component['parent_item_id'], 0, 0, 'component_catalog_changed', $staffId);
            mysqli_commit($conn);
            flash_set('success', 'ลบชิ้นส่วนเรียบร้อยแล้ว');
        }
    } catch (Throwable $e) {
        try {
            mysqli_rollback($conn);
        } catch (Throwable $ignored) {
        }
        if ($newUploadPath !== null && is_file($newUploadPath)) {
            @unlink($newUploadPath);
        }
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถบันทึกข้อมูลได้');
    }
    app_redirect('items.php');
}

$categoriesQ = db_require_query($conn, 'SELECT category_id, category_name FROM categories ORDER BY category_name ASC');
$categories_list = [];
while ($category = mysqli_fetch_assoc($categoriesQ)) {
    $categories_list[] = $category;
}

$itemsQ = db_require_query($conn, 'SELECT i.*, c.category_name FROM items i LEFT JOIN categories c ON i.category_id = c.category_id ORDER BY i.item_id DESC');
$items_list = [];
while ($item = mysqli_fetch_assoc($itemsQ)) {
    $items_list[] = $item;
}
$flash = flash_take();
$incomplete_sets = incomplete_set_ids($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการรายการสิ่งของ - เจ้าหน้าที่</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <style>
        .item-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-top-left-radius: .25rem;
            border-top-right-radius: .25rem;
            background-color: #e9ecef;
        }
        .component-img { width: 42px; height: 42px; object-fit: cover; border-radius: .4rem; border: 1px solid #dee2e6; background: #fff; }
    </style>
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light pb-5">
<?php $uiPage = 'staff/items.php'; require __DIR__ . '/../config/page_shell.php'; ?>



<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0">📦 จัดการคลังสิ่งของวัด</h4>
        <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addItemModal">
            + เพิ่มสิ่งของใหม่
        </button>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= app_escape($flash['type']) ?>" role="alert"><?= app_escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($items_list as $item): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm h-100 border-0">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars($item['image_url']) ?>" class="item-img" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.src='../assets/images/item-placeholder.svg'">
                    <?php else: ?>
                        <div class="item-img d-flex align-items-center justify-content-center text-muted">
                            <span>🖼️ ไม่มีรูปภาพ</span>
                        </div>
                    <?php endif; ?>

                    <div class="card-body">
                        <div class="mb-2">
                            <span class="badge bg-primary me-1"><?= htmlspecialchars($item['category_name'] ?? 'ไม่ระบุหมวดหมู่') ?></span>
                            <?php if ($item['is_set']): ?>
                                <span class="badge bg-info text-dark">ประเภทชุด</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">ชิ้นเดียว</span>
                            <?php endif; ?>
                            <span class="badge <?= (int) $item['is_active'] === 1 ? 'bg-success' : 'bg-dark' ?>"><?= (int) $item['is_active'] === 1 ? 'เปิดให้ยืม' : 'ปิดใช้งาน' ?></span>
                        </div>

                        <h5 class="card-title fw-bold mb-1"><?= htmlspecialchars($item['item_name']) ?></h5>
                        <?php if (isset($incomplete_sets[(int) $item['item_id']])): ?><div class="alert alert-warning py-2 mb-2"><strong>ของในชุดไม่ครบ</strong><div class="small">มีของย่อยหมด ถูกยืม/กันไว้ หรือรอจัดการชำรุด/สูญหาย</div></div><?php endif; ?>
                        <p class="text-muted small mb-3"><?= htmlspecialchars($item['description'] ?: 'ไม่มีรายละเอียด') ?></p>
                        
                        <div class="alert alert-light border py-2 mb-3">
                            <small class="text-muted d-block">จำนวนคงเหลือ / ทั้งหมด</small>
                            <span class="fs-5 fw-bold text-dark"><?= $item['available_quantity'] ?></span> 
                            <span class="text-muted">/ <?= $item['total_quantity'] ?> <?= (int) $item['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?></span>
                        </div>

                        <div class="d-grid mb-2">
                            <button class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#editItemModal<?= $item['item_id'] ?>">
                                ✏️ แก้ไขข้อมูลสิ่งของ
                            </button>
                        </div>
                        <form method="post" class="d-grid mb-2" onsubmit="return confirm('ยืนยันการเปลี่ยนสถานะรายการนี้?')">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                            <input type="hidden" name="is_active" value="<?= (int) $item['is_active'] === 1 ? 0 : 1 ?>">
                            <button type="submit" name="toggle_item" class="btn btn-sm <?= (int) $item['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= (int) $item['is_active'] === 1 ? 'ปิดไม่ให้ยืม' : 'เปิดให้ยืม' ?></button>
                        </form>

                        <?php if ($item['is_set']): ?>
                            <hr>
                            <h6 class="fw-bold small">ส่วนประกอบในชุด (ต่อ 1 ชุด):</h6>
                            <ul class="list-group mb-3">
                                <?php
                                $item_id = (int) $item['item_id'];
                                $comps = mysqli_query($conn, "SELECT * FROM item_components WHERE parent_item_id = $item_id");
                                while ($c = mysqli_fetch_assoc($comps)):
                                ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-1 small gap-2">
                                        <div class="d-flex align-items-center gap-2"><img src="<?= htmlspecialchars($c['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" class="component-img" alt="<?= htmlspecialchars($c['component_name']) ?>" onerror="this.src='../assets/images/item-placeholder.svg'"><span><?= htmlspecialchars($c['component_name']) ?></span></div>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="badge bg-light text-dark border me-1"><?= $c['quantity_per_set'] ?> <?= htmlspecialchars($c['unit']) ?></span>
                                            <?php if (!(int) $c['is_available']): ?><span class="badge bg-danger">ของย่อยหมด</span><?php endif; ?>
                                            
                                            <!-- ปุ่มเปิด Modal แก้ไขชิ้นส่วนย่อย -->
                                            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-1" data-bs-toggle="modal" data-bs-target="#editCompModal<?= $c['component_id'] ?>" title="แก้ไข">
                                                ✏️
                                            </button>

                                            <!-- ปุ่มลบชิ้นส่วนย่อย -->
                                             <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบชิ้นส่วนย่อยนี้?');">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="component_id" value="<?= $c['component_id'] ?>">
                                                <button type="submit" name="delete_component" class="btn btn-sm btn-outline-danger py-0 px-1" title="ลบ">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </li>

                                    <!-- Modal แก้ไขชิ้นส่วนย่อย -->
                                    <div class="modal fade" id="editCompModal<?= $c['component_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                 <form method="POST" enctype="multipart/form-data">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <div class="modal-header bg-warning">
                                                        <h5 class="modal-title fw-bold text-dark">แก้ไขชิ้นส่วนย่อย: <?= htmlspecialchars($c['component_name']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body text-start">
                                                        <input type="hidden" name="component_id" value="<?= $c['component_id'] ?>">
                                                        <label class="d-block mb-3"><input type="checkbox" name="component_unavailable" value="1" <?= !(int) $c['is_available'] ? 'checked' : '' ?>> ของย่อยรายการนี้หมด / ไม่พร้อมให้ยืม</label>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">ชื่อรายการย่อย</label>
                                                            <input type="text" name="component_name" class="form-control" value="<?= htmlspecialchars($c['component_name']) ?>" required>
                                                        </div>
                                                        <div><label class="form-label fw-semibold">รูปภาพชิ้นส่วน</label><?php if (!empty($c['image_url'])): ?><img src="<?= htmlspecialchars($c['image_url']) ?>" class="component-img d-block mb-2" alt="<?= htmlspecialchars($c['component_name']) ?>"><?php endif; ?><input type="file" name="component_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"><small class="text-muted">ไม่เลือกไฟล์หากต้องการใช้รูปเดิม (สูงสุด 5 MB)</small></div>
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">จำนวนต่อ 1 ชุด</label>
                                                                <input type="number" name="quantity_per_set" class="form-control" value="<?= $c['quantity_per_set'] ?>" min="1" required>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">หน่วยนับ</label>
                                                                <input type="text" name="unit" class="form-control" value="<?= htmlspecialchars($c['unit']) ?>" required>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" name="edit_component" class="btn btn-warning fw-bold">บันทึกการแก้ไข</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </ul>
                            <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#addCompModal<?= $item['item_id'] ?>">
                                + เพิ่มชิ้นส่วนย่อยในชุดนี้
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Modal แก้ไขข้อมูลสิ่งของหลัก -->
            <div class="modal fade" id="editItemModal<?= $item['item_id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                         <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <div class="modal-header bg-warning">
                                <h5 class="modal-title fw-bold text-dark">แก้ไขสิ่งของ: <?= htmlspecialchars($item['item_name']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">ชื่อสิ่งของ</label>
                                    <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($item['item_name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">หมวดหมู่สิ่งของ</label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">-- เลือกหมวดหมู่ --</option>
                                        <?php foreach ($categories_list as $cat): ?>
                                            <option value="<?= $cat['category_id'] ?>" <?= $cat['category_id'] == $item['category_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['category_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">รายละเอียด</label>
                                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description']) ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">รูปภาพสิ่งของ (อัปโหลดใหม่หากต้องการเปลี่ยน)</label>
                                    <?php if (!empty($item['image_url'])): ?>
                                        <div class="mb-2">
                                            <img src="<?= htmlspecialchars($item['image_url']) ?>" style="height: 80px; object-fit: cover;" class="rounded border">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="item_image" class="form-control" accept="image/*">
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">จำนวนพร้อมยืม (ระบบคำนวณ)</label>
                                        <input type="number" class="form-control" value="<?= $item['available_quantity'] ?>" readonly>
                                        <div class="form-text">แก้ผ่านการยืม คืน ซ่อม หรือปรับจำนวนทั้งหมด</div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">จำนวนทั้งหมด</label>
                                        <input type="number" name="total_quantity" class="form-control" value="<?= $item['total_quantity'] ?>" min="0" required>
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="is_set" value="1" id="isSetEdit<?= $item['item_id'] ?>" <?= $item['is_set'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="isSetEdit<?= $item['item_id'] ?>">
                                        ของชนิดนี้เป็นแบบชุด (ประกอบด้วยชิ้นย่อยๆ)
                                    </label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="edit_item" class="btn btn-warning fw-bold">บันทึกการแก้ไข</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal เพิ่มส่วนประกอบย่อย -->
            <?php if ($item['is_set']): ?>
                <div class="modal fade" id="addCompModal<?= $item['item_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                             <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title fw-bold">เพิ่มชิ้นส่วนในชุด: <?= htmlspecialchars($item['item_name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="parent_item_id" value="<?= $item['item_id'] ?>">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">ชื่อรายการย่อย (เช่น แจกัน, ช้อน, จาน)</label>
                                        <input type="text" name="component_name" class="form-control" required placeholder="เช่น แจกันดอกไม้">
                                        <label class="d-block mt-2"><input type="checkbox" name="component_unavailable" value="1"> ของย่อยรายการนี้หมด / ไม่พร้อมให้ยืม</label>
                                    </div>
                                    <div class="mb-1"><label class="form-label fw-semibold">รูปภาพชิ้นส่วน</label><input type="file" name="component_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"><small class="text-muted">รองรับ JPG, PNG, WEBP และ GIF ขนาดไม่เกิน 5 MB</small></div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">จำนวนชิ้นต่อ 1 ชุด</label>
                                            <input type="number" name="quantity_per_set" class="form-control" value="1" min="1" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">หน่วยนับ</label>
                                            <input type="text" name="unit" class="form-control" value="ชิ้น" required placeholder="เช่น ชิ้น, ใบ, เส้น">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" name="add_component" class="btn btn-primary">บันทึกชิ้นส่วนย่อย</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal เพิ่มสิ่งของใหม่ -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
             <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">เพิ่มสิ่งของวัดใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ชื่อสิ่งของ</label>
                        <input type="text" name="item_name" class="form-control" required placeholder="เช่น ชุดโต๊ะหมู่บูชา">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">หมวดหมู่สิ่งของ</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">-- เลือกหมวดหมู่ --</option>
                            <?php foreach ($categories_list as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">รายละเอียด</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="รายละเอียดหรือเงื่อนไขการใช้งาน"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">รูปภาพสิ่งของ</label>
                        <input type="file" name="item_image" class="form-control" accept="image/*">
                        <small class="text-muted">รองรับไฟล์ประเภท JPG, PNG, WEBP</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">จำนวนทั้งหมดที่มี</label>
                        <input type="number" name="total_quantity" class="form-control" value="1" min="1" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_set" value="1" id="isSetCheck">
                        <label class="form-check-label" for="isSetCheck">
                            ของชนิดนี้เป็นแบบชุด (ประกอบด้วยชิ้นย่อยๆ)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_item" class="btn btn-primary fw-bold">บันทึกข้อมูลสิ่งของ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
