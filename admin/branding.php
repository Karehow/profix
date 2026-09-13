<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/branding.php';
ensure_feature_tables($conn);
$admin = require_roles($conn, ['admin'], '../config/login.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $changes = [];
        foreach (['logo', 'banner'] as $key) {
            if (!empty($_POST['reset_' . $key])) $changes[$key] = null;
            elseif (isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE) $changes[$key] = branding_upload($_FILES[$key]);
        }
        if (!$changes) throw new RuntimeException('กรุณาเลือกรูปที่ต้องการเปลี่ยน');
        branding_save($changes);
        app_redirect('branding.php?saved=1');
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'ไม่สามารถบันทึกได้ กรุณาลองใหม่'; }
}
$branding = branding_settings();
?>
<!doctype html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>โลโก้และแบนเนอร์</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css"><link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/branding-settings.css?v=2"></head><body class="app-ui">
<?php $uiPage = 'admin/branding.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container"><h1 class="h3">โลโก้และแบนเนอร์</h1><p class="text-muted">เปลี่ยนโลโก้ส่วนหัวและภาพแบนเนอร์ของเว็บ รวมถึงภาพพื้นหลังหน้าเข้าสู่ระบบ</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= app_escape($error) ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success" role="status">บันทึกแล้ว รูปใหม่จะแสดงเมื่อเปิดหรือรีเฟรชหน้าเว็บ</div><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<div class="row g-4">
<?php foreach (['logo'=>'โลโก้', 'banner'=>'แบนเนอร์ / ภาพพื้นหลัง'] as $key=>$label): ?>
<section class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h5"><?= $label ?></h2>
<?php $defaultImage = '../assets/images/' . ($key === 'logo' ? 'temple-logo.svg' : 'login-temple.jpg'); ?>
<?php if ($key === 'logo'): ?>
<p class="form-text">เปลี่ยนรูปไอคอนวัดด้านซ้ายของชื่อระบบบนแถบด้านบน ตามตัวอย่างนี้</p>
<div class="branding-header-preview"><img class="branding-header-logo" id="preview-logo" src="<?= app_escape(isset($branding['logo']) ? '../' . $branding['logo'] : $defaultImage) ?>" data-default-src="<?= $defaultImage ?>" alt="โลโก้ส่วนหัวปัจจุบัน"><span>ระบบยืมคืนสิ่งของวัด<small>ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด</small></span></div>
<?php else: ?>
<img class="branding-preview banner" id="preview-banner" src="<?= app_escape(isset($branding['banner']) ? '../' . $branding['banner'] : $defaultImage) ?>" data-default-src="<?= $defaultImage ?>" alt="แบนเนอร์ปัจจุบัน">
<?php endif; ?>
<label class="form-label" for="<?= $key ?>">เลือกรูป<?= $label ?>ใหม่</label><input class="form-control" type="file" id="<?= $key ?>" name="<?= $key ?>" accept="image/jpeg,image/png,image/webp" data-branding-preview="preview-<?= $key ?>">
<p class="form-text">JPG, PNG หรือ WEBP ไม่เกิน 5 MB<?= $key === 'logo' ? ' แนะนำภาพสี่เหลี่ยมพื้นหลังโปร่งใส' : ' แนะนำภาพแนวนอน' ?></p>
<label class="form-check"><input class="form-check-input" type="checkbox" name="reset_<?= $key ?>" value="1">ใช้<?= $label ?>เริ่มต้น</label>
</div></div></section><?php endforeach; ?></div><button class="btn btn-success mt-4" type="submit">บันทึกรูปภาพ</button></form></main>
<script src="../assets/js/branding-settings.js?v=2" defer></script></body></html>
