<?php
require_once __DIR__ . '/../config/pin_reset.php';
ensure_feature_tables($conn);
$actor = require_roles($conn, ['staff', 'admin'], '../config/login.php');
header('Cache-Control: no-store');
$error = '';
$issued = $_SESSION['issued_pin_reset'] ?? null;
unset($_SESSION['issued_pin_reset']);
$phone = normalize_phone(is_string($_GET['phone'] ?? null) ? $_GET['phone'] : '');
$borrower = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        if (($_POST['identity_verified'] ?? '') !== '1') throw new PinResetException('กรุณายืนยันว่าได้ตรวจสอบตัวตนผู้ยืมแล้ว');
        $userId = (int) ($_POST['user_id'] ?? 0);
        $code = pin_reset_issue($conn, (int) $actor['user_id'], $userId);
        $_SESSION['issued_pin_reset'] = ['user_id' => $userId, 'code' => $code];
        app_redirect('pin_reset.php');
    } catch (Throwable $e) {
        $error = $e instanceof PinResetException ? $e->getMessage() : 'ไม่สามารถออกรหัสรีเซ็ตได้ กรุณาลองใหม่';
    }
}
if (valid_thai_phone($phone)) {
    $stmt = mysqli_prepare($conn, "SELECT user_id, first_name, last_name, phone_number, address_detail FROM users WHERE normalized_phone = ? AND role = 'user' AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $phone);
    mysqli_stmt_execute($stmt);
    $borrower = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}
?>
<!doctype html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ช่วยเหลือผู้ยืมลืม รหัสผ่าน</title><link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css"><?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'staff/pin_reset.php'; require __DIR__ . '/../config/page_shell.php'; ?><main class="container" style="max-width:720px">
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h4 fw-bold mb-0">ช่วยเหลือผู้ยืมลืม รหัสผ่าน</h1><a href="<?= $actor['role'] === 'admin' ? '../admin/home.php' : 'home.php' ?>" class="btn btn-outline-primary">หน้าหลัก</a></div>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= app_escape($error) ?></div><?php endif; ?>
<?php if ($issued): ?><div class="alert alert-success" role="status">รหัสรีเซ็ตสำหรับผู้ยืม #<?= (int) $issued['user_id'] ?>: <strong class="d-block fs-3 font-monospace"><?= app_escape($issued['code']) ?></strong>แจ้งรหัสนี้ให้ผู้ยืมที่ยืนยันตัวตนแล้วนำไปกรอกที่เมนู “ลืม รหัสผ่าน” รหัสมีอายุ 15 นาที ใช้ได้ครั้งเดียว และแสดงเฉพาะครั้งนี้</div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body">
<p>ตรวจสอบตัวตนผู้ยืมต่อหน้าหรือผ่านช่องทางติดต่อเดิมที่เชื่อถือได้ก่อนออกรหัส อย่าใช้เพียงชื่อและเบอร์โทรศัพท์ที่ผู้ร้องขอแจ้งเป็นหลักฐาน</p>
<form method="get" class="mb-3"><label for="phone" class="form-label">ค้นหาด้วยเบอร์โทรศัพท์ที่ลงทะเบียน</label><div class="d-flex gap-2"><input id="phone" type="tel" name="phone" maxlength="20" value="<?= app_escape($phone) ?>" class="form-control" required><button class="btn btn-primary">ค้นหา</button></div></form>
<?php if ($borrower): ?>
<div class="border-top pt-3"><h2 class="h5"><?= app_escape($borrower['first_name'] . ' ' . $borrower['last_name']) ?></h2><p>เบอร์โทร: <?= app_escape($borrower['phone_number']) ?><br>ที่อยู่: <?= app_escape($borrower['address_detail']) ?></p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="user_id" value="<?= (int) $borrower['user_id'] ?>"><div class="form-check mb-3"><input id="verified" type="checkbox" name="identity_verified" value="1" class="form-check-input" required><label for="verified" class="form-check-label">ตรวจสอบแล้วว่าผู้ร้องขอเป็นเจ้าของบัญชีนี้</label></div><button class="btn btn-warning">ออกรหัสรีเซ็ต รหัสผ่าน</button><p class="small text-muted mt-2">การออกรหัสใหม่จะยกเลิกรหัสรีเซ็ตเดิม แต่ รหัสผ่าน เดิมยังใช้ได้จนกว่าผู้ยืมจะตั้ง รหัสผ่าน ใหม่</p></form></div>
<?php elseif (isset($_GET['phone'])): ?><div class="alert alert-warning">ไม่พบบัญชีผู้ยืมที่เปิดใช้งานสำหรับเบอร์นี้</div><?php endif; ?>
</div></div></main></body></html>
