9v<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$staffId = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    mysqli_begin_transaction($conn);
    try {
        if ($action === 'create') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $rawCost = filter_var($_POST['cost'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($rawCost === false || !is_finite((float) $rawCost) || (float) $rawCost < 0) {
                throw new BorrowWorkflowException('ค่าใช้จ่ายต้องเป็นตัวเลขที่ไม่ติดลบ');
            }
            $cost = (float) $rawCost;
            if ($itemId < 1 || $quantity < 1 || $description === '' || mb_strlen($description) > 2000) {
                throw new BorrowWorkflowException('กรุณากรอกรายการ จำนวน และอาการให้ถูกต้อง');
            }
            $itemStmt = mysqli_prepare($conn, 'SELECT available_quantity, is_active FROM items WHERE item_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($itemStmt, 'i', $itemId);
            mysqli_stmt_execute($itemStmt);
            $item = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt));
            mysqli_stmt_close($itemStmt);
            if (!$item || (int) $item['is_active'] !== 1 || $quantity > (int) $item['available_quantity']) {
                throw new BorrowWorkflowException('จำนวนพร้อมซ่อมไม่เพียงพอหรือรายการถูกปิดใช้งาน');
            }
            $insert = mysqli_prepare($conn, "INSERT INTO maintenance_records
                (item_id, quantity, description, cost, status, stock_effect_applied, reported_by_user_id)
                VALUES (?, ?, ?, ?, 'waiting', 1, ?)");
            mysqli_stmt_bind_param($insert, 'iisdi', $itemId, $quantity, $description, $cost, $staffId);
            if (!mysqli_stmt_execute($insert)) {
                throw new BorrowWorkflowException(mysqli_stmt_error($insert));
            }
            $maintenanceId = mysqli_insert_id($conn);
            mysqli_stmt_close($insert);
            inventory_adjust($conn, $itemId, -$quantity, 0, 'maintenance_hold', $staffId, null, null, null, $maintenanceId, 'นำออกจากยอดพร้อมยืมเพื่อซ่อม');
            write_audit_log($conn, $staffId, 'create_maintenance', 'งานซ่อม #' . $maintenanceId . ', จำนวน ' . $quantity);
            flash_set('success', 'เพิ่มงานซ่อมและกันของออกจากยอดพร้อมยืมแล้ว');
        } elseif ($action === 'update') {
            $maintenanceId = (int) ($_POST['maintenance_id'] ?? 0);
            $newStatus = (string) ($_POST['status'] ?? '');
            if (!in_array($newStatus, ['waiting', 'repairing', 'completed', 'retired'], true)) {
                throw new BorrowWorkflowException('สถานะงานซ่อมไม่ถูกต้อง');
            }
            $recordStmt = mysqli_prepare($conn, 'SELECT * FROM maintenance_records WHERE maintenance_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($recordStmt, 'i', $maintenanceId);
            mysqli_stmt_execute($recordStmt);
            $record = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStmt));
            mysqli_stmt_close($recordStmt);
            if (!$record) {
                throw new BorrowWorkflowException('ไม่พบงานซ่อม');
            }
            $oldStatus = $record['status'];
            if (in_array($oldStatus, ['completed', 'retired'], true)) {
                throw new BorrowWorkflowException('งานที่ปิดแล้วไม่สามารถแก้สถานะย้อนหลังได้');
            }
            if ($newStatus === $oldStatus) {
                throw new BorrowWorkflowException('สถานะไม่มีการเปลี่ยนแปลง');
            }
            $allowedTransitions = [
                'waiting' => ['repairing', 'completed', 'retired'],
                'repairing' => ['completed', 'retired'],
            ];
            if (!in_array($newStatus, $allowedTransitions[$oldStatus] ?? [], true)) {
                throw new BorrowWorkflowException('ไม่สามารถย้อนสถานะงานซ่อมไปขั้นก่อนหน้า');
            }
            $quantity = (int) $record['quantity'];
            $itemId = (int) $record['item_id'];
            $effectApplied = (int) $record['stock_effect_applied'] === 1;
            if ($newStatus === 'completed' && $effectApplied) {
                inventory_adjust($conn, $itemId, $quantity, 0, 'maintenance_completed', $staffId, null, null, null, $maintenanceId, 'ซ่อมเสร็จและคืนเข้ายอดพร้อมยืม');
                $effectApplied = false;
            } elseif ($newStatus === 'retired' && $effectApplied) {
                inventory_adjust($conn, $itemId, 0, -$quantity, 'maintenance_retired', $staffId, null, null, null, $maintenanceId, 'ตัดจำหน่ายของที่กันไว้ซ่อม');
                $effectApplied = false;
            }
            $completedAt = in_array($newStatus, ['completed', 'retired'], true) ? date('Y-m-d H:i:s') : null;
            $effect = $effectApplied ? 1 : 0;
            $update = mysqli_prepare($conn, 'UPDATE maintenance_records SET status = ?, stock_effect_applied = ?, updated_by_user_id = ?, completed_at = ? WHERE maintenance_id = ?');
            mysqli_stmt_bind_param($update, 'siisi', $newStatus, $effect, $staffId, $completedAt, $maintenanceId);
            if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
                throw new BorrowWorkflowException('ไม่สามารถอัปเดตงานซ่อมได้');
            }
            mysqli_stmt_close($update);
            write_audit_log($conn, $staffId, 'update_maintenance', 'งานซ่อม #' . $maintenanceId . ': ' . $oldStatus . ' → ' . $newStatus);
            flash_set('success', 'อัปเดตสถานะงานซ่อมและสต็อกแล้ว');
        } else {
            throw new BorrowWorkflowException('การดำเนินการไม่ถูกต้อง');
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถบันทึกงานซ่อมได้');
    }
    app_redirect('maintenance.php');
}

$items = mysqli_query($conn, 'SELECT item_id, item_name, available_quantity, is_set FROM items WHERE is_active = 1 AND available_quantity > 0 ORDER BY item_name');
$records = mysqli_query($conn, "SELECT m.*, i.item_name, i.is_set
    FROM maintenance_records m
    JOIN items i ON i.item_id = m.item_id
    ORDER BY FIELD(m.status,'waiting','repairing','completed','retired'), m.reported_at DESC");
$flash = flash_take();
$statusLabels = ['waiting' => 'รอซ่อม', 'repairing' => 'กำลังซ่อม', 'completed' => 'ซ่อมเสร็จ', 'retired' => 'ตัดจำหน่าย'];
?>
<!doctype html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ซ่อมบำรุงและตัดจำหน่าย</title><link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css"><?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'staff/maintenance.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container">
    <div class="d-flex justify-content-between align-items-center mb-4"><div><h3 class="fw-bold mb-1">ซ่อมบำรุงและตัดจำหน่าย</h3><span class="text-muted">รายการรอซ่อมจะถูกหักจากยอดพร้อมยืม และคืนเข้าคลังเมื่อซ่อมเสร็จ</span></div><a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a></div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
    <div class="card shadow-sm border-0 mb-4"><div class="card-body"><form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="create">
        <div class="col-md-4"><label class="form-label">รายการ</label><select name="item_id" class="form-select" required><option value="">-- เลือกรายการ --</option><?php while ($item = mysqli_fetch_assoc($items)): ?><option value="<?= (int) $item['item_id'] ?>"><?= app_escape($item['item_name']) ?> (พร้อมยืม <?= (int) $item['available_quantity'] ?> <?= (int) $item['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?>)</option><?php endwhile; ?></select></div>
        <div class="col-md-2"><label class="form-label">จำนวน</label><input name="quantity" type="number" min="1" value="1" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">อาการ/งานที่ต้องทำ</label><input name="description" maxlength="2000" class="form-control" required></div>
        <div class="col-md-2"><label class="form-label">ค่าใช้จ่าย</label><input name="cost" type="number" min="0" step="0.01" value="0" class="form-control"></div>
        <div class="col-12"><button class="btn btn-primary">เพิ่มงานซ่อม</button></div>
    </form></div></div>
    <div class="card shadow-sm border-0"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>รายการ</th><th>จำนวน</th><th>รายละเอียด</th><th>ค่าใช้จ่าย</th><th>วันที่แจ้ง</th><th>สถานะ/ดำเนินการ</th></tr></thead><tbody>
        <?php if (!$records || mysqli_num_rows($records) === 0): ?><tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีงานซ่อม</td></tr><?php endif; ?>
        <?php while ($m = mysqli_fetch_assoc($records)): ?><tr>
            <td>#<?= (int) $m['maintenance_id'] ?></td><td><strong><?= app_escape($m['item_name']) ?></strong></td><td><?= (int) $m['quantity'] ?> <?= (int) $m['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?></td><td><?= app_escape($m['description']) ?></td><td><?= number_format((float) $m['cost'], 2) ?></td><td><?= date('d/m/Y H:i', strtotime($m['reported_at'])) ?></td>
            <td><?php if (in_array($m['status'], ['waiting','repairing'], true)): ?><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="maintenance_id" value="<?= (int) $m['maintenance_id'] ?>"><select name="status" class="form-select form-select-sm"><?php if ($m['status'] === 'waiting'): ?><option value="waiting" selected>รอซ่อม</option><?php endif; ?><option value="repairing" <?= $m['status'] === 'repairing' ? 'selected' : '' ?>>กำลังซ่อม</option><option value="completed">ซ่อมเสร็จและคืนคลัง</option><option value="retired">ตัดจำหน่าย</option></select><button class="btn btn-sm btn-outline-primary" onclick="return confirm('ยืนยันการเปลี่ยนสถานะและปรับสต็อก?')">บันทึก</button></form><?php else: ?><span class="badge <?= $m['status'] === 'completed' ? 'bg-success' : 'bg-dark' ?>"><?= app_escape($statusLabels[$m['status']] ?? $m['status']) ?></span><?php endif; ?></td>
        </tr><?php endwhile; ?>
        </tbody>
    </table></div></div>
</main>
</body></html>
