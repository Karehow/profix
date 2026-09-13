<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['user'], 'index.php');
$userId = (int) $currentUser['user_id'];
reservation_expire_stale($conn);

function reservation_parse_datetime(string $value): string
{
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new BorrowWorkflowException('รูปแบบวันและเวลาไม่ถูกต้อง');
    }
    return $date->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $start = reservation_parse_datetime((string) ($_POST['start_date'] ?? ''));
            $end = reservation_parse_datetime((string) ($_POST['end_date'] ?? ''));
            $purpose = trim((string) ($_POST['purpose'] ?? ''));
            if (strtotime($start) < time() - 60 || strtotime($end) <= strtotime($start)) {
                throw new BorrowWorkflowException('เวลาเริ่มต้องไม่ย้อนหลังและเวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม');
            }
            if (strtotime($end) - strtotime($start) > 31 * 86400) {
                throw new BorrowWorkflowException('จองต่อครั้งได้ไม่เกิน 31 วัน');
            }
            if ($purpose === '' || mb_strlen($purpose) > 255) {
                throw new BorrowWorkflowException('กรุณาระบุวัตถุประสงค์ไม่เกิน 255 ตัวอักษร');
            }

            mysqli_begin_transaction($conn);
            try {
                $itemStmt = mysqli_prepare($conn, 'SELECT item_name, total_quantity, available_quantity, is_set, is_active FROM items WHERE item_id = ? FOR UPDATE');
                mysqli_stmt_bind_param($itemStmt, 'i', $itemId);
                mysqli_stmt_execute($itemStmt);
                $item = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt));
                mysqli_stmt_close($itemStmt);
                if (!$item || (int) $item['is_active'] !== 1 || $quantity < 1 || $quantity > (int) $item['total_quantity']) {
                    throw new BorrowWorkflowException('รายการหรือจำนวนที่ต้องการจองไม่ถูกต้อง');
                }
                $duplicateStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM reservations
                    WHERE user_id = ? AND item_id = ? AND status IN ('pending','approved')
                      AND start_date < ? AND end_date > ?");
                mysqli_stmt_bind_param($duplicateStmt, 'iiss', $userId, $itemId, $end, $start);
                mysqli_stmt_execute($duplicateStmt);
                $duplicate = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateStmt))['total'];
                mysqli_stmt_close($duplicateStmt);
                if ($duplicate > 0) {
                    throw new BorrowWorkflowException('คุณมีคำขอจองรายการนี้ในช่วงเวลาซ้อนกันอยู่แล้ว');
                }
                $insert = mysqli_prepare($conn, 'INSERT INTO reservations (user_id, item_id, quantity, start_date, end_date, purpose) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($insert, 'iiisss', $userId, $itemId, $quantity, $start, $end, $purpose);
                if (!mysqli_stmt_execute($insert)) {
                    throw new BorrowWorkflowException(mysqli_stmt_error($insert));
                }
                $reservationId = mysqli_insert_id($conn);
                mysqli_stmt_close($insert);
                write_audit_log($conn, $userId, 'create_reservation', 'การจอง #' . $reservationId);
                $staff = mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('staff','admin') AND is_active = 1");
                while ($staff && $recipient = mysqli_fetch_assoc($staff)) {
                    create_notification($conn, (int) $recipient['user_id'], 'มีคำขอจองใหม่', 'การจอง #' . $reservationId . ' รอตรวจสอบ', '../admin/reservations.php');
                }
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                throw $e;
            }
            flash_set('success', 'ส่งคำขอจองเรียบร้อยแล้ว เจ้าหน้าที่จะตรวจสอบจำนวนตามช่วงเวลา');
        } elseif ($action === 'cancel') {
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "SELECT status FROM reservations WHERE reservation_id = ? AND user_id = ? FOR UPDATE");
                mysqli_stmt_bind_param($stmt, 'ii', $reservationId, $userId);
                mysqli_stmt_execute($stmt);
                $reservation = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if (!$reservation || !in_array($reservation['status'], ['pending', 'approved'], true)) {
                    throw new BorrowWorkflowException('รายการนี้ไม่สามารถยกเลิกได้');
                }
                $update = mysqli_prepare($conn, "UPDATE reservations SET status = 'cancelled', cancelled_at = NOW() WHERE reservation_id = ? AND user_id = ? AND status IN ('pending','approved')");
                mysqli_stmt_bind_param($update, 'ii', $reservationId, $userId);
                mysqli_stmt_execute($update);
                if (mysqli_stmt_affected_rows($update) !== 1) {
                    throw new BorrowWorkflowException('สถานะการจองถูกเปลี่ยนโดยผู้ใช้อื่น');
                }
                mysqli_stmt_close($update);
                write_audit_log($conn, $userId, 'cancel_reservation', 'การจอง #' . $reservationId);
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                throw $e;
            }
            flash_set('success', 'ยกเลิกการจองแล้ว');
        } elseif ($action === 'convert') {
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $requestId = reservation_convert_to_borrow($conn, $reservationId, $userId);
            flash_set('success', 'สร้างคำขอยืม #' . $requestId . ' จากรายการจองแล้ว');
        } else {
            throw new BorrowWorkflowException('การดำเนินการไม่ถูกต้อง');
        }
    } catch (Throwable $e) {
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถบันทึกรายการจองได้');
    }
    app_redirect('reservations.php');
}

$items = mysqli_query($conn, 'SELECT item_id, item_name, total_quantity, available_quantity, is_set FROM items WHERE is_active = 1 ORDER BY item_name');
$stmt = mysqli_prepare($conn, 'SELECT r.*, i.item_name, i.is_set FROM reservations r JOIN items i ON i.item_id = r.item_id WHERE r.user_id = ? ORDER BY r.created_at DESC');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$reservations = mysqli_stmt_get_result($stmt);
$flash = flash_take();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>จองสิ่งของล่วงหน้า</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'user/reservations.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container" style="max-width:1000px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h3 class="fw-bold mb-1">จองสิ่งของล่วงหน้า</h3><span class="text-muted">การจองที่อนุมัติแล้วสามารถสร้างเป็นคำขอยืมได้</span></div>
        <a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
    <div class="card shadow-sm border-0 mb-4"><div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <div class="col-md-6"><label class="form-label">รายการ</label><select name="item_id" class="form-select" required>
                <option value="">-- เลือกรายการ --</option>
                <?php while ($item = mysqli_fetch_assoc($items)): ?><option value="<?= (int) $item['item_id'] ?>"><?= app_escape($item['item_name']) ?> (พร้อมยืมขณะนี้ <?= (int) $item['available_quantity'] ?><?= (int) $item['is_set'] === 1 ? ' ชุด' : ' ชิ้น' ?>)</option><?php endwhile; ?>
            </select></div>
            <div class="col-md-2"><label class="form-label">จำนวน</label><input type="number" name="quantity" min="1" value="1" class="form-control" required><div class="form-text">ระบุจำนวนชุดมาตรฐานที่ต้องการ</div></div>
            <div class="col-md-4"><label class="form-label">วัตถุประสงค์</label><input name="purpose" maxlength="255" class="form-control" required placeholder="เช่น งานบุญขึ้นบ้านใหม่"></div>
            <div class="col-md-6"><label class="form-label">เริ่มใช้</label><input type="datetime-local" name="start_date" min="<?= date('Y-m-d\TH:i') ?>" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">สิ้นสุด</label><input type="datetime-local" name="end_date" min="<?= date('Y-m-d\TH:i') ?>" class="form-control" required></div>
            <div class="col-12"><button class="btn btn-primary">ส่งคำขอจอง</button></div>
        </form>
    </div></div>

    <div class="card shadow-sm border-0"><div class="card-header bg-white fw-bold">รายการจองของฉัน</div><div class="table-responsive">
        <table class="table align-middle mb-0"><thead class="table-light"><tr><th>รายการ</th><th>ช่วงเวลา</th><th>จำนวน</th><th>สถานะ/หมายเหตุ</th><th class="text-end">ดำเนินการ</th></tr></thead><tbody>
        <?php if (!$reservations || mysqli_num_rows($reservations) === 0): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีรายการจอง</td></tr><?php endif; ?>
        <?php while ($r = mysqli_fetch_assoc($reservations)): ?>
            <tr>
                <td><strong><?= app_escape($r['item_name']) ?></strong><div class="small text-muted"><?= app_escape($r['purpose']) ?></div></td>
                <td><?= date('d/m/Y H:i', strtotime($r['start_date'])) ?><br>ถึง <?= date('d/m/Y H:i', strtotime($r['end_date'])) ?></td>
                <td><?= (int) $r['quantity'] ?> <?= (int) $r['is_set'] === 1 ? 'ชุด' : 'ชิ้น' ?></td>
                <td><span class="badge <?= $r['status'] === 'approved' ? 'bg-success' : ($r['status'] === 'rejected' ? 'bg-danger' : 'bg-secondary') ?>"><?= app_escape(reservation_status_label($r['status'])) ?></span><?php if ($r['review_note']): ?><div class="small text-muted mt-1"><?= app_escape($r['review_note']) ?></div><?php endif; ?></td>
                <td class="text-end">
                    <?php if ($r['status'] === 'approved' && strtotime($r['end_date']) > time() && strtotime($r['start_date']) <= strtotime('+24 hours')): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('สร้างคำขอยืมจากการจองนี้?')"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="convert"><input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>"><button class="btn btn-sm btn-primary">สร้างคำขอยืม</button></form>
                    <?php elseif ($r['status'] === 'approved' && strtotime($r['start_date']) > strtotime('+24 hours')): ?>
                        <span class="small text-muted">สร้างคำขอยืมได้ภายใน 24 ชม. ก่อนเริ่มจอง</span>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['pending','approved'], true)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันยกเลิกการจอง?')"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>"><button class="btn btn-sm btn-outline-danger">ยกเลิก</button></form>
                    <?php elseif ($r['borrow_request_id']): ?><a class="btn btn-sm btn-outline-secondary" href="history.php#request-<?= (int) $r['borrow_request_id'] ?>">ดูคำขอ #<?= (int) $r['borrow_request_id'] ?></a><?php endif; ?>
                </td>
            </tr>
        <?php endwhile; mysqli_stmt_close($stmt); ?>
        </tbody></table>
    </div></div>
</main>
</body>
</html>
