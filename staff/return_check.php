<?php
require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$current_user = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$staff_id = (int) $current_user['user_id'];

// ประมวลผลการตรวจรับคืน
if (isset($_POST['submit_inspection'])) {
    require_csrf();
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $borrow_item_id = (int) ($_POST['borrow_item_id'] ?? 0);
    $returned_qty = (int) ($_POST['returned_quantity'] ?? 0);
    $image_url = '';
    $uploaded_path = null;

    mysqli_begin_transaction($conn);
    try {
        if ($request_id < 1 || $borrow_item_id < 1) {
            throw new BorrowWorkflowException('ข้อมูลคำขอหรือรายการที่รับคืนไม่ถูกต้อง');
        }
        // Lock the request first so two staff members cannot finish different rows concurrently.
        $request_stmt = mysqli_prepare($conn, "SELECT user_id, status FROM borrow_requests WHERE request_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($request_stmt, 'i', $request_id);
        mysqli_stmt_execute($request_stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($request_stmt));
        mysqli_stmt_close($request_stmt);
        if (!$request || $request['status'] !== 'return_requested') {
            throw new BorrowWorkflowException('คำขอนี้ไม่อยู่ในสถานะรอตรวจรับคืน');
        }

        $item_stmt = mysqli_prepare($conn, 'SELECT bi.*, i.is_set FROM borrow_items bi JOIN items i ON i.item_id = bi.item_id WHERE bi.borrow_item_id = ? AND bi.request_id = ? FOR UPDATE');
        mysqli_stmt_bind_param($item_stmt, 'ii', $borrow_item_id, $request_id);
        mysqli_stmt_execute($item_stmt);
        $borrow_item = mysqli_fetch_assoc(mysqli_stmt_get_result($item_stmt));
        mysqli_stmt_close($item_stmt);
        $remaining_qty = $borrow_item ? (int) $borrow_item['quantity_borrowed'] - (int) $borrow_item['quantity_returned'] : 0;
        if (!$borrow_item || $returned_qty < 1 || $returned_qty > $remaining_qty) {
            throw new BorrowWorkflowException('จำนวนรับคืนไม่ถูกต้องหรือมีผู้บันทึกรายการนี้แล้ว');
        }

        $is_set = (int) $borrow_item['is_set'] === 1;
        $exact_selection = false;
        if ($is_set) {
            $exactRows = mysqli_query($conn, "SELECT * FROM borrow_item_components WHERE borrow_item_id = $borrow_item_id AND quantity_borrowed IS NOT NULL FOR UPDATE");
            while ($exactComponent = mysqli_fetch_assoc($exactRows)) {
                $exact_selection = true;
                try {
                    component_return_limit($exactComponent, $returned_qty, $remaining_qty);
                } catch (InvalidArgumentException $e) {
                    throw new BorrowWorkflowException($e->getMessage());
                }
            }
        }
        $component_issues = [];
        $damaged_qty = 0;
        $lost_qty = 0;
        $description = trim((string) ($_POST['damage_description'] ?? ''));
        $action_req = (string) ($_POST['action_required'] ?? 'none');
        $fine_input = filter_var($_POST['fine_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($fine_input === false || !is_finite((float) $fine_input) || (float) $fine_input < 0) {
            throw new BorrowWorkflowException('จำนวนเงินค่าชดใช้ไม่ถูกต้อง');
        }
        if ((float) $fine_input !== 0.0) throw new BorrowWorkflowException('ชดใช้ได้เฉพาะซื้อของมาคืนวัด');
        $fine_amount = 0;

        if ($is_set) {
            $posted_issues = is_array($_POST['component_issues'] ?? null) ? $_POST['component_issues'] : [];
            if (count($posted_issues) > 100) {
                throw new BorrowWorkflowException('จำนวนรายการชิ้นส่วนมากเกินไป');
            }
            $used_components = [];
            foreach ($posted_issues as $posted_issue) {
                if (!is_array($posted_issue)) {
                    continue;
                }
                $component_value = (string) ($posted_issue['component_id'] ?? '');
                $issue_damaged = max(0, (int) ($posted_issue['damaged_quantity'] ?? 0));
                $issue_lost = max(0, (int) ($posted_issue['lost_quantity'] ?? 0));
                if ($component_value === '' && $issue_damaged === 0 && $issue_lost === 0) {
                    continue;
                }
                $is_main_issue = $component_value === 'main';
                $snapshot_id = $is_main_issue ? null : (int) $component_value;
                $component_key = $is_main_issue ? 'main' : (string) $snapshot_id;
                if ((!$is_main_issue && $snapshot_id < 1) || isset($used_components[$component_key]) || $issue_damaged + $issue_lost < 1) {
                    throw new BorrowWorkflowException('รายการของย่อยซ้ำหรือจำนวนปัญหาไม่ถูกต้อง');
                }

                $source_component_id = null;
                if ($is_main_issue) {
                    if ($exact_selection) throw new BorrowWorkflowException('กรุณาระบุของย่อยที่ชำรุดหรือสูญหายแยกแต่ละชนิด');
                    if ($issue_damaged + $issue_lost > $returned_qty) {
                        throw new BorrowWorkflowException('จำนวนตัวชุดหลักที่มีปัญหาเกินจำนวนรับคืน');
                    }
                } else {
                    $snapshot_stmt = mysqli_prepare($conn, 'SELECT source_component_id, quantity_per_set, quantity_borrowed, is_included FROM borrow_item_components WHERE borrow_item_component_id = ? AND borrow_item_id = ? AND is_included = 1 FOR UPDATE');
                    mysqli_stmt_bind_param($snapshot_stmt, 'ii', $snapshot_id, $borrow_item_id);
                    mysqli_stmt_execute($snapshot_stmt);
                    $snapshot = mysqli_fetch_assoc(mysqli_stmt_get_result($snapshot_stmt));
                    mysqli_stmt_close($snapshot_stmt);
                    $maximum = $snapshot ? component_return_limit($snapshot, $returned_qty, $remaining_qty) : 0;
                    if (!$snapshot || $issue_damaged + $issue_lost > $maximum) {
                        throw new BorrowWorkflowException('ของย่อยไม่อยู่ในชุดที่ส่งมอบหรือจำนวนเกินจริง');
                    }
                    $source_component_id = $snapshot['source_component_id'] !== null ? (int) $snapshot['source_component_id'] : null;
                }

                $issue_action = (string) ($posted_issue['action_required'] ?? '');
                $issue_fine_input = filter_var($posted_issue['fine_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
                if ($issue_fine_input === false || !is_finite((float) $issue_fine_input) || (float) $issue_fine_input < 0) {
                    throw new BorrowWorkflowException('จำนวนเงินค่าชดใช้ของชิ้นส่วนไม่ถูกต้อง');
                }
                if ((float) $issue_fine_input !== 0.0) throw new BorrowWorkflowException('ชดใช้ได้เฉพาะซื้อของมาคืนวัด');
                $issue_fine = 0;
                $issue_description = trim((string) ($posted_issue['description'] ?? ''));
                if ($issue_action !== 'buy_replacement') {
                    throw new BorrowWorkflowException('กรุณาเลือกวิธีชดใช้ของย่อยทุกชิ้นที่มีปัญหา');
                }
                if ($issue_description === '') {
                    throw new BorrowWorkflowException('กรุณาระบุรายละเอียดความเสียหายทุกชิ้น');
                }
                if ((function_exists('mb_strlen') ? mb_strlen($issue_description, 'UTF-8') : strlen($issue_description)) > 2000) {
                    throw new BorrowWorkflowException('รายละเอียดความเสียหายยาวเกิน 2,000 ตัวอักษร');
                }
                $used_components[$component_key] = true;
                $component_issues[] = [
                    'snapshot_id' => $snapshot_id,
                    'component_id' => $source_component_id,
                    'damaged_quantity' => $issue_damaged,
                    'lost_quantity' => $issue_lost,
                    'description' => $issue_description,
                    'action_required' => $issue_action,
                    'fine_amount' => $issue_fine,
                ];
            }
            if (isset($used_components['main']) && count($component_issues) > 1) {
                throw new BorrowWorkflowException('ไม่สามารถแจ้งทั้งชุดหลักพร้อมของย่อย เพราะจะนับความเสียหายซ้ำ');
            }
        } else {
            $damaged_qty = max(0, (int) ($_POST['damaged_quantity'] ?? 0));
            $lost_qty = max(0, (int) ($_POST['lost_quantity'] ?? 0));
            if ($damaged_qty + $lost_qty > $returned_qty) {
                throw new BorrowWorkflowException('จำนวนชำรุดและสูญหายรวมกันเกินจำนวนรับคืน');
            }
            if ($damaged_qty + $lost_qty > 0) {
                if ($action_req !== 'buy_replacement') {
                    throw new BorrowWorkflowException('กรุณาเลือกวิธีชดใช้');
                }
                if ($description === '') {
                    throw new BorrowWorkflowException('กรุณาระบุรายละเอียดความเสียหาย');
                }
                if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 2000) {
                    throw new BorrowWorkflowException('รายละเอียดความเสียหายยาวเกิน 2,000 ตัวอักษร');
                }
            } else {
                $action_req = 'none';
                $fine_amount = 0;
            }
        }

        if (isset($_FILES['damage_image']) && ($_FILES['damage_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['damage_image']['error'] !== UPLOAD_ERR_OK || $_FILES['damage_image']['size'] > 5 * 1024 * 1024) {
                throw new BorrowWorkflowException('ไฟล์หลักฐานอัปโหลดไม่สำเร็จหรือมีขนาดเกิน 5 MB');
            }
            $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = mime_content_type($_FILES['damage_image']['tmp_name']);
            if (!isset($allowed_types[$mime])) {
                throw new BorrowWorkflowException('รองรับรูปหลักฐานเฉพาะ JPG, PNG และ WEBP');
            }
            $upload_dir = __DIR__ . '/../uploads';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                throw new BorrowWorkflowException('ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้');
            }
            $filename = 'damage_' . bin2hex(random_bytes(12)) . '.' . $allowed_types[$mime];
            $uploaded_path = $upload_dir . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($_FILES['damage_image']['tmp_name'], $uploaded_path)) {
                $uploaded_path = null;
                throw new BorrowWorkflowException('ไม่สามารถบันทึกรูปหลักฐานได้');
            }
            $uploaded_path = $upload_dir . DIRECTORY_SEPARATOR . $filename;
            $image_url = '../uploads/' . $filename;
        }

        $has_issues = $is_set ? !empty($component_issues) : ($damaged_qty + $lost_qty > 0);
        $good_qty = $is_set ? ($has_issues ? 0 : $returned_qty) : $returned_qty - $damaged_qty - $lost_qty;
        $withheld_qty = $returned_qty - $good_qty;
        $resolution = $has_issues ? 'pending' : 'none';
        $batch_stmt = mysqli_prepare($conn, 'INSERT INTO return_batches (request_id, borrow_item_id, quantity_received, good_quantity, withheld_quantity, stock_resolution, inspected_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($batch_stmt, 'iiiiisi', $request_id, $borrow_item_id, $returned_qty, $good_qty, $withheld_qty, $resolution, $staff_id);
        if (!mysqli_stmt_execute($batch_stmt)) {
            throw new BorrowWorkflowException(mysqli_stmt_error($batch_stmt));
        }
        $return_batch_id = mysqli_insert_id($conn);
        mysqli_stmt_close($batch_stmt);

        $inspection_rows = $is_set && $has_issues ? $component_issues : [[
            'snapshot_id' => null,
            'component_id' => null,
            'damaged_quantity' => $damaged_qty,
            'lost_quantity' => $lost_qty,
            'description' => $description,
            'action_required' => $action_req,
            'fine_amount' => $fine_amount,
        ]];
        foreach ($inspection_rows as $row_index => $inspection_row) {
            $row_received_qty = $row_index === 0 ? $returned_qty : 0;
            $snapshot_id = $inspection_row['snapshot_id'];
            $component_id = $inspection_row['component_id'];
            $row_damaged = (int) $inspection_row['damaged_quantity'];
            $row_lost = (int) $inspection_row['lost_quantity'];
            $row_description = (string) $inspection_row['description'];
            $row_action = (string) $inspection_row['action_required'];
            $row_fine = (float) $inspection_row['fine_amount'];
            $inspection_stmt = mysqli_prepare($conn, 'INSERT INTO return_inspections (return_batch_id, borrow_item_id, borrow_item_component_id, quantity_received, component_id, damaged_quantity, lost_quantity, damage_description, damage_image_url, action_required, fine_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($inspection_stmt, 'iiiiiiisssd', $return_batch_id, $borrow_item_id, $snapshot_id, $row_received_qty, $component_id, $row_damaged, $row_lost, $row_description, $image_url, $row_action, $row_fine);
            if (!mysqli_stmt_execute($inspection_stmt)) {
                throw new BorrowWorkflowException(mysqli_stmt_error($inspection_stmt));
            }
            mysqli_stmt_close($inspection_stmt);
        }

        $update_item = mysqli_prepare($conn, 'UPDATE borrow_items SET quantity_returned = quantity_returned + ? WHERE borrow_item_id = ? AND quantity_returned + ? <= quantity_borrowed');
        mysqli_stmt_bind_param($update_item, 'iii', $returned_qty, $borrow_item_id, $returned_qty);
        mysqli_stmt_execute($update_item);
        if (mysqli_stmt_affected_rows($update_item) !== 1) {
            throw new BorrowWorkflowException('ยอดรับคืนถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update_item);

        if ($good_qty > 0 || $is_set) {
            inventory_adjust($conn, (int) $borrow_item['item_id'], $good_qty, 0, 'return_good', $staff_id, $request_id, $borrow_item_id, $return_batch_id, null, 'รับคืนในสภาพพร้อมยืม');
        }

        $remaining_stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM borrow_items WHERE request_id = ? AND quantity_returned < quantity_borrowed');
        mysqli_stmt_bind_param($remaining_stmt, 'i', $request_id);
        mysqli_stmt_execute($remaining_stmt);
        $remaining = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($remaining_stmt))['total'];
        mysqli_stmt_close($remaining_stmt);
        if ($remaining === 0) {
            $damage_stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total, COALESCE(SUM(ri.is_resolved = 0), 0) AS unresolved FROM return_inspections ri JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id WHERE bi.request_id = ? AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)');
            mysqli_stmt_bind_param($damage_stmt, 'i', $request_id);
            mysqli_stmt_execute($damage_stmt);
            $damage_summary = mysqli_fetch_assoc(mysqli_stmt_get_result($damage_stmt));
            $has_damage = (int) ($damage_summary['total'] ?? 0) > 0;
            $has_unresolved_damage = (int) ($damage_summary['unresolved'] ?? 0) > 0;
            mysqli_stmt_close($damage_stmt);
            $status = $has_damage ? 'partially_damaged' : 'returned';
            $settlement_status = $has_damage ? ($has_unresolved_damage ? 'pending' : 'resolved') : 'none';
            $request_update = mysqli_prepare($conn, 'UPDATE borrow_requests SET status = ?, settlement_status = ?, received_by_user_id = ?, actual_return_date = NOW() WHERE request_id = ? AND status = \'return_requested\'');
            mysqli_stmt_bind_param($request_update, 'ssii', $status, $settlement_status, $staff_id, $request_id);
            mysqli_stmt_execute($request_update);
            if (mysqli_stmt_affected_rows($request_update) !== 1) {
                throw new BorrowWorkflowException('ไม่สามารถปิดคำขอรับคืนได้');
            }
            mysqli_stmt_close($request_update);
            borrow_add_event($conn, $request_id, 'return_requested', $status, $staff_id, $has_damage ? 'ตรวจรับครบ มีรายการชำรุดหรือสูญหาย' : 'ตรวจรับครบและสภาพปกติ');
            $title = $has_damage ? 'ตรวจรับคืนแล้ว: มีรายการต้องชดใช้' : 'ตรวจรับคืนเรียบร้อย';
            $detail = $has_damage ? 'คำขอ #' . $request_id . ' มีรายการชำรุดหรือสูญหาย โปรดตรวจสอบเงื่อนไขการชดใช้' : 'คำขอ #' . $request_id . ' ตรวจรับคืนครบถ้วนแล้ว';
            if (!$has_issues) {
                create_notification($conn, (int) $request['user_id'], $title, $detail, $has_damage ? '../user/compensation.php?request_id=' . $request_id : '../user/history.php');
            }
        }
        if ($has_issues) {
            create_notification($conn, (int) $request['user_id'], 'พบของชำรุดหรือสูญหาย: กรุณาชดใช้', 'คำขอ #' . $request_id . ' มีรายการชำรุดหรือสูญหาย กดดูหลักฐานและส่งรูปของที่ซื้อคืนวัดให้เจ้าหน้าที่ตรวจรับ', '../user/compensation.php?request_id=' . $request_id);
        }
        write_audit_log($conn, $staff_id, 'inspect_return', 'คำขอ #' . $request_id . ', รายการ #' . $borrow_item_id . ', batch #' . $return_batch_id);
        $outstanding = $remaining_qty - $returned_qty;
        if ($outstanding > 0) {
            create_notification($conn, (int) $request['user_id'], 'รับคืนบางส่วนแล้ว ยังมีของค้างคืน', 'คำขอ #' . $request_id . ' รายการ #' . $borrow_item_id . ' ตรวจรับครั้งนี้ ' . $returned_qty . ' ค้างคืนอีก ' . $outstanding . ' กรุณานำส่วนที่เหลือมาคืน', '../user/history.php#request-' . $request_id);
        }
        mysqli_commit($conn);
        flash_set('success', 'บันทึกการตรวจรับคืนเรียบร้อยแล้ว' . ($outstanding > 0 ? ' รายการนี้ยังค้างคืน ' . $outstanding . ' ไม่ถือว่าสูญหายจนกว่าจะตรวจยืนยัน' : ''));
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($uploaded_path !== null && is_file($uploaded_path)) {
            @unlink($uploaded_path);
        }
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถบันทึกการตรวจรับได้');
    }
    app_redirect('return_check.php');
}

$borrowed_requests = mysqli_query($conn, "SELECT br.*, u.first_name, u.last_name 
                                           FROM borrow_requests br 
                                           JOIN users u ON br.user_id = u.user_id 
                                           WHERE br.status = 'return_requested'
                                           ORDER BY br.return_requested_at ASC, br.borrow_date ASC");
$flash = flash_take();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ตรวจรับการคืนสิ่งของ - เจ้าหน้าที่</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <style>
        .return-item-image { width: 72px; height: 72px; object-fit: cover; border-radius: .5rem; border: 1px solid #dee2e6; background: #fff; }
    </style>
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'staff/return_check.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<div class="container">
    <?php require __DIR__ . '/../config/staff_workflow_queues.php'; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-1">ตรวจรับคืนสิ่งของ</h4><div class="text-muted small">แสดงเฉพาะรายการที่ผู้ยืมแจ้งว่าพร้อมส่งคืนแล้ว</div></div>
        <div>
            <a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a>
            <a href="requests.php" class="btn btn-outline-secondary">รายการคำขอ</a>
            <a href="compensation.php" class="btn btn-outline-warning">รับของทดแทน</a>
            <a href="../config/logout.php" class="btn btn-outline-danger">ออกจากระบบ</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <?php
        $error_messages = [
            'invalid' => 'ไม่พบคำขอหรือสถานะคำขอไม่พร้อมตรวจรับ',
            'quantity' => 'จำนวนที่รับคืนหรือจำนวนความเสียหายไม่ถูกต้อง',
            'component' => 'กรุณาตรวจสอบชิ้นส่วน จำนวน หรือรายการชิ้นส่วนที่เลือกซ้ำ',
            'action' => 'กรุณาเลือกเงื่อนไขชดใช้ให้ครบทุกรายการที่ชำรุดหรือสูญหาย',
            'save' => 'ไม่สามารถบันทึกผลตรวจรับได้ กรุณาลองใหม่อีกครั้ง',
        ];
        ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_messages[$_GET['error']] ?? 'ไม่สามารถบันทึกผลตรวจรับได้ กรุณาตรวจสอบข้อมูลและลองใหม่อีกครั้ง') ?></div>
    <?php endif; ?>

    <?php if (!$borrowed_requests || mysqli_num_rows($borrowed_requests) === 0): ?>
        <div class="card shadow-sm border-0"><div class="card-body text-center py-5"><div class="fs-1 mb-2">✓</div><h5>ไม่มีรายการรอตรวจรับคืน</h5><p class="text-muted mb-0">เมื่อผู้ยืมกดแจ้งพร้อมส่งคืน รายการจะปรากฏในหน้านี้</p></div></div>
    <?php endif; ?>
    <?php while ($req = mysqli_fetch_assoc($borrowed_requests)): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white font-weight-bold">
                คำขอยืม #<?= $req['request_id'] ?> - ผู้ยืม: <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>
                <span class="badge bg-info text-dark ms-2">รอตรวจรับ</span>
            </div>
            <div class="card-body">
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>รายการสิ่งของ</th>
                            <th>จำนวนยืม</th>
                            <th>การตรวจรับสิ่งของ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $req_id = (int) $req['request_id'];
                        $items_stmt = mysqli_prepare($conn, "SELECT bi.*, i.item_name, i.image_url, i.is_set,
                            (SELECT SUM(bc.quantity_borrowed) FROM borrow_item_components bc WHERE bc.borrow_item_id=bi.borrow_item_id) AS component_piece_count
                                                        FROM borrow_items bi
                                                        JOIN items i ON bi.item_id = i.item_id
                                                        WHERE bi.request_id = ?");
                        mysqli_stmt_bind_param($items_stmt, 'i', $req_id);
                        mysqli_stmt_execute($items_stmt);
                        $items_q = mysqli_stmt_get_result($items_stmt);
                        while ($bi = mysqli_fetch_assoc($items_q)):
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="<?= htmlspecialchars($bi['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" class="return-item-image" alt="<?= htmlspecialchars($bi['item_name']) ?>" onerror="this.src='../assets/images/item-placeholder.svg'">
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($bi['item_name']) ?></div>
                                            <?php if ($bi['is_set']): ?><span class="badge bg-info text-dark">ชุดอุปกรณ์</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= $bi['component_piece_count'] !== null ? (int) $bi['component_piece_count'] . ' ชิ้น (ของย่อย)' : (int) $bi['quantity_borrowed'] . ($bi['is_set'] ? ' ชุด' : ' ชิ้น') ?></td>
                                <td>
                                    <?php
                                    $remaining_qty = max(0, (int) $bi['quantity_borrowed'] - (int) $bi['quantity_returned']);
                                    ?>
                                    <?php if ($bi['quantity_returned'] >= $bi['quantity_borrowed']): ?>
                                        <span class="badge bg-success">ตรวจรับการคืนเรียบร้อยแล้ว</span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#checkModal<?= $bi['borrow_item_id'] ?>">
                                            ตรวจรับรายการนี้
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Modal ตรวจรับ / แจ้งชำรุด -->
                            <?php
                            $available_components = [];
                            $has_exact_quantities = false;
                            if ($bi['is_set']) {
                                $borrow_item_id = (int) $bi['borrow_item_id'];
                                $comp_stmt = mysqli_prepare($conn, "SELECT
                                        borrow_item_component_id AS component_id,
                                        component_name,
                                        quantity_per_set,
                                        quantity_borrowed,
                                        is_included,
                                        unit
                                    FROM borrow_item_components
                                    WHERE borrow_item_id = ? AND is_included = 1
                                    ORDER BY component_name");
                                mysqli_stmt_bind_param($comp_stmt, 'i', $borrow_item_id);
                                mysqli_stmt_execute($comp_stmt);
                                $comp_q = mysqli_stmt_get_result($comp_stmt);
                                while ($comp_q && $comp = mysqli_fetch_assoc($comp_q)) $available_components[] = $comp;
                                mysqli_stmt_close($comp_stmt);
                                $has_exact_quantities = count(array_filter($available_components, static fn($c) => isset($c['quantity_borrowed']))) > 0;
                            }
                            ?>
                            <div class="modal fade" id="checkModal<?= $bi['borrow_item_id'] ?>" tabindex="-1">
                                <div class="modal-dialog <?= $bi['is_set'] ? 'modal-xl' : '' ?>">
                                    <div class="modal-content">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">ตรวจรับ: <?= htmlspecialchars($bi['item_name']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                                <input type="hidden" name="borrow_item_id" value="<?= $bi['borrow_item_id'] ?>">
                                                <input type="hidden" name="item_id" value="<?= $bi['item_id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">จำนวนที่ตรวจรับครั้งนี้ (<?= !$has_exact_quantities && (int) $bi['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?>) — รวมที่ยืนยันสูญหาย ถ้ามี</label>
                                                    <?php if ($has_exact_quantities): ?>
                                                        <input type="hidden" name="returned_quantity" value="<?= $remaining_qty ?>">
                                                        <div class="form-control bg-light"><?= (int) $bi['component_piece_count'] ?> ชิ้น ตามรายการของย่อย</div>
                                                    <?php else: ?>
                                                        <input type="number" name="returned_quantity" class="form-control" value="<?= $remaining_qty ?>" min="1" max="<?= $remaining_qty ?>" required>
                                                    <?php endif; ?>
                                                    <?php if ($has_exact_quantities): ?><div class="small text-muted mt-2">ตรวจรับของย่อยทั้งหมดตามจำนวนที่ส่งมอบ หากขาดหรือเสียหายให้ระบุของย่อยแต่ละชนิดด้านล่าง</div><?php endif; ?>
                                                </div>
                                                <?php if (!(int) $bi['is_set']): ?><div class="alert alert-info small">ค้างตรวจรับ <?= $remaining_qty ?> ชิ้น — หากนำมาคืนเพียง 70 ชิ้น ให้กรอก 70 ส่วนที่เหลือจะค้างคืนต่อไป ช่องสูญหายใช้เฉพาะเมื่อตรวจยืนยันว่าหายแล้ว</div><?php endif; ?>
                                                <div class="alert alert-success py-2 small">จำนวนที่ยืม <strong><?= $has_exact_quantities ? (int) $bi['component_piece_count'] . ' ชิ้น' : (int) $bi['quantity_borrowed'] . ($bi['is_set'] ? ' ชุด' : ' ชิ้น') ?></strong> — ของสภาพดีจะกลับเข้าคลังทันทีหลังบันทึก</div>

                                                <?php if ($bi['is_set']): ?>
                                                    <div class="alert alert-info py-2 small">
                                                        ระบุของย่อยที่ชำรุดหรือสูญหายได้หลายรายการ ของย่อยที่คืนสภาพดีจะพร้อมให้ยืมต่อ ส่วนที่มีปัญหาจะรอชดใช้
                                                    </div>
                                                    <?php if (empty($available_components)): ?>
                                                        <div class="alert alert-warning">ไม่พบรายการของย่อย สามารถเลือกแจ้งปัญหาที่ “ทั้งชุด/ตัวชุดหลัก” ได้</div>
                                                    <?php endif; ?>
                                                        <div id="componentIssues<?= $bi['borrow_item_id'] ?>" class="component-issues" data-next-index="1">
                                                            <div class="component-issue-row border rounded p-3 mb-3 bg-light">
                                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                                    <strong>ชิ้นส่วนที่มีปัญหา</strong>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-component-issue">ลบรายการ</button>
                                                                </div>
                                                                <div class="row g-2">
                                                                    <div class="col-lg-4">
                                                                        <label class="form-label">ชิ้นส่วน</label>
                                                                        <select name="component_issues[0][component_id]" class="form-select component-select">
                                                                            <option value="">-- เลือกชิ้นส่วน --</option>
                                                                            <?php if (!$has_exact_quantities): ?><option value="main">ทั้งชุด/ตัวชุดหลัก</option><?php endif; ?>
                                                                            <?php foreach ($available_components as $component): ?>
                                                                                <option value="<?= (int) $component['component_id'] ?>"><?= htmlspecialchars($component['component_name']) ?> (ยืม <?= component_borrowed_quantity($component, (int) $bi['quantity_borrowed']) ?> <?= htmlspecialchars($component['unit'] ?? 'ชิ้น') ?>)</option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-6 col-lg-2"><label class="form-label">ชำรุด</label><input type="number" name="component_issues[0][damaged_quantity]" class="form-control damaged-input" value="0" min="0"></div>
                                                                    <div class="col-6 col-lg-2"><label class="form-label">สูญหาย</label><input type="number" name="component_issues[0][lost_quantity]" class="form-control lost-input" value="0" min="0"></div>
                                                                    <div class="col-lg-4">
                                                                        <label class="form-label">เงื่อนไขชดใช้</label>
                                                                        <select name="component_issues[0][action_required]" class="form-select action-select">
                                                                            
                                                                            <option value="buy_replacement">ซื้อของมาคืน</option>
                                                                            
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-lg-8"><label class="form-label">รายละเอียด</label><input name="component_issues[0][description]" class="form-control description-input" maxlength="2000" placeholder="เช่น แตก ร้าว ใช้งานไม่ได้"></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn btn-outline-primary btn-sm add-component-issue" data-target="componentIssues<?= $bi['borrow_item_id'] ?>" data-template="componentIssueTemplate<?= $bi['borrow_item_id'] ?>">+ เพิ่มชิ้นส่วนที่ชำรุด/สูญหาย</button>
                                                        <template id="componentIssueTemplate<?= $bi['borrow_item_id'] ?>">
                                                            <div class="component-issue-row border rounded p-3 mb-3 bg-light">
                                                                <div class="d-flex justify-content-between align-items-center mb-2"><strong>ชิ้นส่วนที่มีปัญหา</strong><button type="button" class="btn btn-sm btn-outline-danger remove-component-issue">ลบรายการ</button></div>
                                                                <div class="row g-2">
                                                                    <div class="col-lg-4"><label class="form-label">ชิ้นส่วน</label><select name="component_issues[__INDEX__][component_id]" class="form-select component-select"><option value="">-- เลือกชิ้นส่วน --</option><?php if (!$has_exact_quantities): ?><option value="main">ทั้งชุด/ตัวชุดหลัก</option><?php endif; ?><?php foreach ($available_components as $component): ?><option value="<?= (int) $component['component_id'] ?>"><?= htmlspecialchars($component['component_name']) ?> (ยืม <?= component_borrowed_quantity($component, (int) $bi['quantity_borrowed']) ?> <?= htmlspecialchars($component['unit'] ?? 'ชิ้น') ?>)</option><?php endforeach; ?></select></div>
                                                                    <div class="col-6 col-lg-2"><label class="form-label">ชำรุด</label><input type="number" name="component_issues[__INDEX__][damaged_quantity]" class="form-control damaged-input" value="0" min="0"></div>
                                                                    <div class="col-6 col-lg-2"><label class="form-label">สูญหาย</label><input type="number" name="component_issues[__INDEX__][lost_quantity]" class="form-control lost-input" value="0" min="0"></div>
                                                                    <div class="col-lg-4"><label class="form-label">เงื่อนไขชดใช้</label><select name="component_issues[__INDEX__][action_required]" class="form-select action-select"><option value="buy_replacement">ซื้อของมาคืน</option></select></div>
                                                                    <div class="col-lg-8"><label class="form-label">รายละเอียด</label><input name="component_issues[__INDEX__][description]" class="form-control description-input" maxlength="2000" placeholder="เช่น แตก ร้าว ใช้งานไม่ได้"></div>
                                                                </div>
                                                            </div>
                                                        </template>
                                                <?php else: ?>
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label">จำนวนที่ชำรุด</label>
                                                            <input type="number" name="damaged_quantity" class="form-control" value="0" min="0" max="<?= $remaining_qty ?>">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">จำนวนที่สูญหาย</label>
                                                            <input type="number" name="lost_quantity" class="form-control" value="0" min="0" max="<?= $remaining_qty ?>">
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">รายละเอียดความเสียหาย</label>
                                                        <textarea name="damage_description" class="form-control" rows="2" maxlength="2000"></textarea>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">เงื่อนไขชดใช้ <span class="text-danger">(เลือกเมื่อมีชำรุด/สูญหาย)</span></label>
                                                        <select name="action_required" class="form-select">
                                                            <option value="none">ไม่มี (คืนตามปกติ)</option>
                                                            <option value="buy_replacement">ซื้อของมาคืน</option>
                                                            
                                                        </select>
                                                    </div>

                                                    
                                                <?php endif; ?>

                                                <div class="mb-3">
                                                    <label class="form-label">รูปภาพหลักฐานความเสียหาย <?= $bi['is_set'] ? '(ใช้ร่วมกับรายการของย่อยทั้งหมด)' : '' ?></label>
                                                    <input type="file" name="damage_image" class="form-control" accept="image/*">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" name="submit_inspection" class="btn btn-primary">บันทึกตรวจรับ</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; mysqli_stmt_close($items_stmt); ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endwhile; ?>
</div>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('click', function (event) {
    var addButton = event.target.closest('.add-component-issue');
    if (addButton) {
        var container = document.getElementById(addButton.dataset.target);
        var template = document.getElementById(addButton.dataset.template);
        if (!container || !template) return;
        var nextIndex = parseInt(container.dataset.nextIndex || '1', 10);
        var html = template.innerHTML.split('__INDEX__').join(String(nextIndex));
        container.insertAdjacentHTML('beforeend', html);
        container.dataset.nextIndex = String(nextIndex + 1);
        return;
    }

    var removeButton = event.target.closest('.remove-component-issue');
    if (removeButton) removeButton.closest('.component-issue-row').remove();
});

document.querySelectorAll('form').forEach(function (form) {
    if (!form.querySelector('.component-issues')) return;
    form.addEventListener('submit', function (event) {
        var usedComponents = new Set();
        var errorMessage = '';
        form.querySelectorAll('.component-issue-row').forEach(function (row) {
            if (errorMessage) return;
            var componentId = row.querySelector('.component-select').value;
            var damaged = parseInt(row.querySelector('.damaged-input').value || '0', 10);
            var lost = parseInt(row.querySelector('.lost-input').value || '0', 10);
            var action = row.querySelector('.action-select').value;
            var description = row.querySelector('.description-input').value.trim();
            if (!componentId && damaged === 0 && lost === 0) return;
            if (!componentId) errorMessage = 'กรุณาเลือกชื่อชิ้นส่วนที่มีปัญหา';
            else if (usedComponents.has(componentId)) errorMessage = 'ไม่สามารถเลือกชิ้นส่วนเดิมซ้ำได้';
            else if (damaged + lost < 1) errorMessage = 'กรุณาระบุจำนวนชำรุดหรือสูญหายอย่างน้อย 1 ชิ้น';
            else if (!action) errorMessage = 'กรุณาเลือกเงื่อนไขชดใช้ของแต่ละชิ้นส่วน';
            else if (!description) errorMessage = 'กรุณาระบุรายละเอียดความเสียหายของทุกชิ้นส่วน';
            usedComponents.add(componentId);
        });
        if (!errorMessage && usedComponents.has('main') && usedComponents.size > 1) {
            errorMessage = 'ไม่สามารถแจ้งทั้งชุดหลักพร้อมกับชิ้นส่วนย่อย';
        }
        if (errorMessage) {
            event.preventDefault();
            window.alert(errorMessage);
        }
    });
});
</script>
</body>
</html>
