<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['admin'], '../config/login.php');
$adminId = (int) $currentUser['user_id'];
reservation_expire_stale($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review'])) {
    require_csrf();
    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $decision = (string) ($_POST['status'] ?? '');
    $noteInput = $_POST['review_note'] ?? '';
    $note = trim(is_scalar($noteInput) ? (string) $noteInput : '');
    mysqli_begin_transaction($conn);
    try {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('ผลการพิจารณาไม่ถูกต้อง');
        }
        if (mb_strlen($note) > 1000) {
            throw new RuntimeException('หมายเหตุต้องไม่เกิน 1,000 ตัวอักษร');
        }
        if ($decision === 'rejected' && $note === '') {
            throw new RuntimeException('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
        }
        $stmt = mysqli_prepare($conn, "SELECT r.*, i.total_quantity, i.is_set, i.is_active
            FROM reservations r
            JOIN items i ON i.item_id = r.item_id
            WHERE r.reservation_id = ?
            FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $reservationId);
        mysqli_stmt_execute($stmt);
        $reservation = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$reservation || $reservation['status'] !== 'pending') {
            throw new RuntimeException('รายการนี้ไม่ได้อยู่ในสถานะรอตรวจสอบ');
        }
        if ($decision === 'approved' && strtotime($reservation['end_date']) <= time()) {
            throw new RuntimeException('ช่วงเวลาจองสิ้นสุดแล้ว กรุณาไม่อนุมัติรายการนี้');
        }

        if ($decision === 'approved') {
            if ((int) $reservation['is_active'] !== 1) {
                throw new RuntimeException('สิ่งของรายการนี้ปิดใช้งานแล้ว');
            }
            if ((int) $reservation['quantity'] < 1 || ((int) $reservation['is_set'] === 1 && (int) $reservation['quantity'] !== 1)) {
                throw new RuntimeException('จำนวนที่จองไม่ถูกต้อง');
            }
            $reservedItemId = (int) $reservation['item_id'];
            $reservationStart = (string) $reservation['start_date'];
            $reservationEnd = (string) $reservation['end_date'];
            $otherStmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(quantity), 0) AS total
                FROM reservations
                WHERE item_id = ? AND reservation_id <> ?
                  AND (
                      status = 'approved'
                      OR (status = 'fulfilled' AND EXISTS (
                          SELECT 1 FROM borrow_requests linked_request
                          WHERE linked_request.request_id = reservations.borrow_request_id
                            AND linked_request.status = 'pending_approval'
                      ))
                  )
                  AND start_date < ? AND end_date > ?");
            mysqli_stmt_bind_param($otherStmt, 'iiss', $reservedItemId, $reservationId, $reservationEnd, $reservationStart);
            mysqli_stmt_execute($otherStmt);
            $reserved = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($otherStmt))['total'];
            mysqli_stmt_close($otherStmt);

            $loanStmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(bi.quantity_borrowed - bi.quantity_returned), 0) AS total
                FROM borrow_items bi
                JOIN borrow_requests br ON br.request_id = bi.request_id
                WHERE bi.item_id = ? AND br.status IN ('approved','borrowed','return_requested')
                  AND COALESCE(br.requested_pickup_at, br.handed_over_at, br.borrow_date) < ?
                  AND (
                      br.expected_return_date > ?
                      OR (br.status IN ('borrowed','return_requested') AND br.expected_return_date <= NOW())
                  )");
            mysqli_stmt_bind_param($loanStmt, 'iss', $reservedItemId, $reservationEnd, $reservationStart);
            mysqli_stmt_execute($loanStmt);
            $activeLoans = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($loanStmt))['total'];
            mysqli_stmt_close($loanStmt);

            $maintenanceStmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(quantity), 0) AS total FROM maintenance_records
                WHERE item_id = ? AND stock_effect_applied = 1 AND status IN ('waiting','repairing')");
            mysqli_stmt_bind_param($maintenanceStmt, 'i', $reservedItemId);
            mysqli_stmt_execute($maintenanceStmt);
            $maintenance = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($maintenanceStmt))['total'];
            mysqli_stmt_close($maintenanceStmt);

            if ((int) $reservation['quantity'] + $reserved + $activeLoans + $maintenance > (int) $reservation['total_quantity']) {
                throw new RuntimeException('จำนวนไม่เพียงพอในช่วงเวลานี้ เนื่องจากชนกับการจอง การยืม หรือการซ่อม');
            }
        }

        $update = mysqli_prepare($conn, 'UPDATE reservations SET status = ?, reviewed_by_user_id = ?, review_note = ?, reviewed_at = NOW() WHERE reservation_id = ? AND status = \'pending\'');
        mysqli_stmt_bind_param($update, 'sisi', $decision, $adminId, $note, $reservationId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new RuntimeException('สถานะรายการถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        create_notification(
            $conn,
            (int) $reservation['user_id'],
            'ผลการจองล่วงหน้า',
            'การจอง #' . $reservationId . ' ' . ($decision === 'approved' ? 'ได้รับอนุมัติแล้ว สามารถสร้างคำขอยืมได้' : 'ไม่ได้รับอนุมัติ: ' . $note),
            '../user/reservations.php'
        );
        write_audit_log($conn, $adminId, 'review_reservation', 'การจอง #' . $reservationId . ' → ' . $decision . ($note !== '' ? ': ' . $note : ''));
        mysqli_commit($conn);
        flash_set('success', $decision === 'approved' ? 'อนุมัติการจองแล้ว' : 'บันทึกการไม่อนุมัติแล้ว');
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        flash_set('danger', $e->getMessage());
    }
    app_redirect('reservations.php');
}

$requestedStatus = (string) ($_GET['status'] ?? 'all');
$filter = in_array($requestedStatus, ['all','pending','approved','rejected','cancelled','fulfilled','expired'], true)
    ? $requestedStatus : 'all';
$sql = "SELECT r.*, i.item_name, i.is_set, u.first_name, u.last_name, u.phone_number
    FROM reservations r
    JOIN items i ON i.item_id = r.item_id
    JOIN users u ON u.user_id = r.user_id";
if ($filter !== 'all') {
    $sql .= ' WHERE r.status = ?';
}
$sql .= " ORDER BY FIELD(r.status,'pending','approved','fulfilled','rejected','cancelled','expired'), r.start_date";
if ($filter === 'all') {
    $rows = mysqli_query($conn, $sql);
} else {
    $rowsStmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($rowsStmt, 's', $filter);
    mysqli_stmt_execute($rowsStmt);
    $rows = mysqli_stmt_get_result($rowsStmt);
}
$flash = flash_take();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>จัดการการจองล่วงหน้า</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'admin/reservations.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h3 class="fw-bold mb-1">จัดการการจองล่วงหน้า</h3><span class="text-muted">ระบบตรวจจำนวนจากรายการจอง การยืม และของที่อยู่ระหว่างซ่อมในช่วงเดียวกัน</span></div>
        <a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
    <form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body d-flex gap-2 align-items-end"><div><label class="form-label small mb-1">สถานะ</label><select name="status" class="form-select"><option value="all">ทั้งหมด</option><?php foreach (['pending','approved','fulfilled','rejected','cancelled','expired'] as $status): ?><option value="<?= $status ?>" <?= $filter === $status ? 'selected' : '' ?>><?= app_escape(reservation_status_label($status)) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">กรอง</button></div></form>
    <div class="card shadow-sm border-0"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>ผู้จอง</th><th>รายการ</th><th>ช่วงเวลา</th><th>จำนวน</th><th>สถานะ/หมายเหตุ</th><th>การดำเนินการ</th></tr></thead>
        <tbody>
        <?php if (!$rows || mysqli_num_rows($rows) === 0): ?><tr><td colspan="6" class="text-center text-muted py-5">ไม่พบรายการจอง</td></tr><?php endif; ?>
        <?php while ($r = mysqli_fetch_assoc($rows)): ?>
            <tr>
                <td><strong><?= app_escape($r['first_name'] . ' ' . $r['last_name']) ?></strong><div class="small text-muted"><?= app_escape($r['phone_number']) ?></div></td>
                <td><?= app_escape($r['item_name']) ?><div class="small text-muted"><?= app_escape($r['purpose']) ?></div></td>
                <td><?= date('d/m/Y H:i', strtotime($r['start_date'])) ?><br>ถึง <?= date('d/m/Y H:i', strtotime($r['end_date'])) ?><?php if (strtotime($r['end_date']) <= time() && $r['status'] === 'pending'): ?><div class="text-danger small">ช่วงเวลาสิ้นสุดแล้ว</div><?php endif; ?></td>
                <td><?= (int) $r['quantity'] ?> <?= (int) $r['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?></td>
                <td><span class="badge <?= $r['status'] === 'approved' ? 'bg-success' : ($r['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= app_escape(reservation_status_label($r['status'])) ?></span><?php if ($r['review_note']): ?><div class="small text-muted mt-1"><?= app_escape($r['review_note']) ?></div><?php endif; ?><?php if ($r['borrow_request_id']): ?><div class="small mt-1">คำขอยืม #<?= (int) $r['borrow_request_id'] ?></div><?php endif; ?></td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="d-grid gap-1" style="min-width:230px">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                            <select name="status" class="form-select form-select-sm"><option value="approved">อนุมัติ</option><option value="rejected">ไม่อนุมัติ</option></select>
                            <input name="review_note" maxlength="1000" class="form-control form-control-sm" placeholder="หมายเหตุ (จำเป็นเมื่อไม่อนุมัติ)">
                            <button name="review" class="btn btn-sm btn-primary" onclick="return confirm('ยืนยันผลการพิจารณา?')">บันทึกผล</button>
                        </form>
                    <?php else: ?><span class="text-muted small">พิจารณาแล้ว</span><?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table></div></div>
</main>
</body>
</html>
