<?php
require_once __DIR__ . '/../config/pin_reset.php';
ensure_feature_tables($conn);
header('Cache-Control: no-store');
// Discard unfinished challenges from the removed SMS integration.
unset($_SESSION['sms_pin_reset']);
$error = '';
$success = !empty($_SESSION['pin_reset_success']);
unset($_SESSION['pin_reset_success']);
$text = static fn(string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
$phone = $text('phone_number');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        if (!in_array($text('action'), ['reset_pin', 'legacy_reset'], true)) {
            throw new PinResetException('คำขอไม่ถูกต้อง กรุณาลองใหม่');
        }
        pin_reset_self_service($conn, $phone, $text('first_name'), $text('last_name'), $text('pin'), $text('pin_confirm'));
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['pin_reset_success'] = true;
        app_redirect('forgot_pin.php');
    } catch (Throwable $e) {
        $error = $e instanceof PinResetException ? $e->getMessage() : 'ไม่สามารถตั้งรหัสผ่านใหม่ได้ กรุณาลองใหม่หรือติดต่อเจ้าหน้าที่';
    }
}
?>
<!doctype html>
<html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ลืมรหัสผ่าน</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/auth.css">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="auth-page py-4"><main class="container" style="max-width:560px"><div class="auth-card">
<a class="auth-back" href="index.php">← กลับไปเข้าสู่ระบบ</a>
<div class="auth-support mb-4"><strong>กู้คืนการเข้าถึงบัญชี</strong>กรอกเบอร์โทรศัพท์ ชื่อ และนามสกุลให้ตรงกับข้อมูลที่สมัคร แล้วตั้งรหัสผ่านใหม่ได้เลย</div>
<h1 class="h4 fw-bold">ตั้งรหัสผ่านใหม่</h1>
<?php if ($success): ?>
<div class="alert alert-success" role="status">ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่</div>
<?php else: ?>
<p class="text-muted">สำหรับบัญชีผู้ยืม ไม่ต้องขอรหัสรีเซ็ตจากเจ้าหน้าที่</p>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= app_escape($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="reset_pin">
<div class="mb-3"><label for="phone" class="form-label">เบอร์โทรศัพท์ที่ลงทะเบียน</label><input id="phone" type="tel" name="phone_number" maxlength="20" autocomplete="tel" class="form-control" value="<?= app_escape($phone) ?>" required></div>
<div class="mb-3"><label for="firstName" class="form-label">ชื่อที่ลงทะเบียน</label><input id="firstName" name="first_name" maxlength="100" autocomplete="given-name" class="form-control" value="<?= app_escape($text('first_name')) ?>" required></div>
<div class="mb-3"><label for="lastName" class="form-label">นามสกุลที่ลงทะเบียน</label><input id="lastName" name="last_name" maxlength="100" autocomplete="family-name" class="form-control" value="<?= app_escape($text('last_name')) ?>" required></div>
<div class="mb-3"><label for="pin" class="form-label">รหัสผ่านใหม่ 6 หลัก</label><div class="auth-secret"><input id="pin" type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" class="form-control" required><button type="button" class="auth-reveal" data-password-toggle="pin" aria-controls="pin" aria-pressed="false" aria-label="แสดงรหัสผ่าน">แสดง</button></div></div>
<div class="mb-3"><label for="confirm" class="form-label">ยืนยันรหัสผ่านใหม่</label><div class="auth-secret"><input id="confirm" type="password" name="pin_confirm" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" class="form-control" required><button type="button" class="auth-reveal" data-password-toggle="confirm" aria-controls="confirm" aria-pressed="false" aria-label="แสดงรหัสผ่าน">แสดง</button></div></div>
<button class="auth-submit mb-3" type="submit">ตั้งรหัสผ่านใหม่</button>
</form>
<?php endif; ?>
<a href="index.php">กลับไปเข้าสู่ระบบ</a>
</div></main><script src="../assets/js/auth.js" defer></script></body></html>
