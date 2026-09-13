<?php
if (!isset($conn, $error, $defaultLat)) { http_response_code(404); exit; }
$requestedMode = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? 'login') : ($_GET['mode'] ?? 'login');
$mode = in_array($requestedMode, ['register','activate_legacy'], true) ? $requestedMode : 'login';
$field = static fn(string $name): string => app_escape(is_string($_POST[$name] ?? null) ? $_POST[$name] : '');
$pinField = static function (string $id, string $name, string $label, bool $new = false, string $confirm = ''): void { ?>
    <label for="<?= $id ?>" class="form-label"><?= $label ?></label><div class="auth-secret"><input id="<?= $id ?>" name="<?= $name ?>" type="password" class="form-control" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="ตัวเลข 6 หลัก" autocomplete="<?= $new ? 'new-password' : 'current-password' ?>" <?= $confirm ? 'data-confirm-for="' . $confirm . '"' : '' ?> required><button type="button" class="auth-reveal" data-password-toggle="<?= $id ?>" aria-controls="<?= $id ?>" aria-pressed="false" aria-label="แสดง <?= $label ?>">แสดง</button></div>
<?php }; ?>
<!doctype html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $mode === 'register' ? 'สมัครสมาชิก' : ($mode === 'activate_legacy' ? 'ตั้ง รหัสผ่าน บัญชีเดิม' : 'เข้าสู่ระบบ') ?> · ระบบยืมคืนสิ่งของวัด</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/auth.css">
<?php if ($mode === 'register'): ?><link rel="stylesheet" href="../leaflet/dist/leaflet.css"><link rel="stylesheet" href="../assets/css/registration-address.css"><?php endif; ?>
<?php require __DIR__ . '/theme.php'; ?>
<?php if ($mode !== 'activate_legacy'): ?><link rel="stylesheet" href="../assets/css/login-background.css?v=4"><?php endif; ?>
</head><body class="auth-page<?= $mode !== 'activate_legacy' ? ' auth-login-page' : '' ?><?= $mode === 'register' ? ' auth-register-page' : '' ?>">
<header class="auth-header"><a class="auth-brand" href="../index.php"><span class="auth-brand-mark" aria-hidden="true">↔</span><span>ระบบยืมคืนสิ่งของวัด<small>TEMPLE BORROWING SERVICE</small></span></a><a class="auth-back" href="../index.php">← กลับหน้าหลัก</a></header>
<main class="auth-layout">
<?php if ($mode === 'activate_legacy'): ?><aside class="auth-story" aria-label="แนะนำการใช้งาน"><div class="auth-eyebrow">สะดวกสำหรับคุณ พร้อมสำหรับชุมชน</div><h1>ยืมของจากวัด<br>เริ่มต้นได้ง่าย ๆ</h1><p>เลือกสิ่งของที่ต้องการ ติดตามคำขอ<br>และแจ้งคืนได้ในที่เดียว</p><img class="auth-illustration" src="../assets/images/temple-welcome.svg" alt="ภาพประกอบวัดและกล่องสิ่งของพร้อมให้ยืม" width="480" height="290"><ol class="auth-guide"><li><span>1</span>สมัครด้วยเบอร์โทรศัพท์และตั้ง รหัสผ่าน</li><li><span>2</span>เลือกสิ่งของและส่งคำขอยืม</li><li><span>3</span>รออนุมัติ แล้วรับของกับเจ้าหน้าที่</li></ol></aside><?php endif; ?>
<section class="auth-card" aria-labelledby="authTitle">
<nav class="auth-nav" aria-label="บัญชีผู้ยืม"><a href="index.php" <?= $mode === 'login' ? 'aria-current="page"' : '' ?>>เข้าสู่ระบบ</a><a href="index.php?mode=register" <?= $mode === 'register' ? 'aria-current="page"' : '' ?>>สมัครสมาชิกใหม่</a></nav>
<?php if ($error): ?><div class="auth-error" role="alert" tabindex="-1" data-auth-error><strong>ยังดำเนินการไม่สำเร็จ</strong><br><?= app_escape($error) ?></div><?php endif; ?>
<?php if ($mode === 'login'): ?>
<h2 id="authTitle">ยินดีต้อนรับกลับ</h2><p class="auth-intro">ใช้เบอร์โทรศัพท์และ รหัสผ่าน ที่คุณลงทะเบียนไว้</p>
<form method="post" class="auth-login-fields"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="login">
<div><label for="loginPhone" class="form-label">เบอร์โทรศัพท์</label><input id="loginPhone" name="phone_number" type="tel" inputmode="tel" autocomplete="username" maxlength="20" class="form-control" placeholder="กรอกเบอร์โทรศัพท์ของคุณ" value="<?= $field('phone_number') ?>" required></div>
<div><?php $pinField('loginPin','pin','รหัสผ่าน 6 หลัก'); ?><div class="auth-row mt-2"><span class="form-text">รหัสผ่าน ที่ตั้งไว้ตอนสมัครสมาชิก</span><a class="auth-link" href="forgot_pin.php">ลืม รหัสผ่าน?</a></div></div>
<button class="auth-submit">เข้าสู่ระบบ →</button></form>
<div class="auth-support"><strong>เพิ่งใช้งานครั้งแรก?</strong>เลือก <a href="index.php?mode=register">สมัครสมาชิกใหม่</a> เพื่อสร้างบัญชีผู้ยืม<br>เคยยืมกับวัดแต่ยังไม่มี รหัสผ่าน? <a href="index.php?mode=activate_legacy">ตั้ง รหัสผ่าน บัญชีเดิม</a></div>
<p class="text-center mt-4 mb-0"><a class="auth-link" href="../config/login.php">สำหรับเจ้าหน้าที่และผู้ดูแลระบบ</a></p>
<?php elseif ($mode === 'register'): ?>
<h2 id="authTitle">สมัครเป็นผู้ยืม</h2><p class="auth-intro">กรอกข้อมูลของคุณเพียงครั้งเดียว เพื่อใช้ยืมสิ่งของจากวัด</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="register">
<p class="auth-required-note">กรอกข้อมูลให้ครบ หมู่ หมู่บ้าน และการปักหมุดแผนที่ไม่บังคับ</p>
<fieldset class="auth-section"><legend><span class="auth-section-number">01</span>ข้อมูลของคุณ</legend><div class="row">
<div class="col-sm-6"><label class="form-label" for="registerFirst">ชื่อ</label><input id="registerFirst" name="first_name" maxlength="100" autocomplete="given-name" class="form-control" placeholder="ชื่อจริง" value="<?= $field('first_name') ?>" required></div>
<div class="col-sm-6"><label class="form-label" for="registerLast">นามสกุล</label><input id="registerLast" name="last_name" maxlength="100" autocomplete="family-name" class="form-control" placeholder="นามสกุล" value="<?= $field('last_name') ?>" required></div>
<div class="col-12"><label class="form-label" for="registerPhone">เบอร์โทรศัพท์</label><input id="registerPhone" type="tel" name="phone_number" inputmode="tel" autocomplete="tel" maxlength="20" class="form-control" placeholder="เบอร์ที่ติดต่อคุณได้" value="<?= $field('phone_number') ?>" aria-describedby="phoneHelp" required><div id="phoneHelp" class="form-text">ใช้เบอร์นี้เข้าสู่ระบบและติดต่อเรื่องการยืมคืน</div></div>
</div></fieldset>
<fieldset class="auth-section"><legend><span class="auth-section-number">02</span>ตั้ง รหัสผ่าน สำหรับเข้าสู่ระบบ</legend><div class="row"><div class="col-sm-6"><?php $pinField('registerPin','pin','ตั้ง รหัสผ่าน 6 หลัก',true); ?></div><div class="col-sm-6"><?php $pinField('registerConfirm','pin_confirm','กรอก รหัสผ่าน อีกครั้ง',true,'registerPin'); ?></div></div><p class="form-text">ตั้งเป็นตัวเลข 6 หลักที่คุณจำได้ และเก็บ รหัสผ่าน ไว้เป็นความลับ</p></fieldset>
<fieldset class="auth-section"><legend><span class="auth-section-number">03</span>ที่อยู่ / สถานที่นำของไปใช้</legend><div class="row">
<div class="col-md-4"><label for="addressHouse" class="form-label">บ้านเลขที่</label><input id="addressHouse" name="house_number" maxlength="100" class="form-control" placeholder="เช่น 95" value="<?= $field('house_number') ?>" required ></div>
<div class="col-md-4"><label for="addressMoo" class="form-label">หมู่</label><input id="addressMoo" name="village_number" maxlength="20" class="form-control" placeholder="เช่น 4" value="<?= $field('village_number') ?>"  inputmode="numeric" pattern="[0-9๐-๙]+"></div>
<?php require __DIR__ . '/registration_village_field.php'; ?>
<div class="col-md-4"><label for="addressSubdistrict" class="form-label">ตำบล / แขวง</label><select id="addressSubdistrict" name="subdistrict_id" class="form-select" required><option value=""><?= $addressDistrict ? 'เลือกตำบล/แขวง' : 'กรุณาเลือกอำเภอ/เขตก่อน' ?></option><?php foreach ($addressDistrict['subdistricts'] ?? [] as $subdistrict): ?><option value="<?= app_escape($subdistrict['id']) ?>" <?= $addressSubdistrict && $addressSubdistrict['id'] === $subdistrict['id'] ? 'selected' : '' ?>><?= app_escape($subdistrict['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="addressDistrict" class="form-label">อำเภอ / เขต</label><select id="addressDistrict" name="district_id" class="form-select" required><option value="">เลือกอำเภอ</option><?php foreach ($addressProvince['districts'] as $districtOption): ?><option value="<?= app_escape($districtOption['id']) ?>" <?= ($addressDistrict['id'] ?? '') === $districtOption['id'] ? 'selected' : '' ?>><?= app_escape($districtOption['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="addressProvince" class="form-label">จังหวัด</label><select id="addressProvince" class="form-select" disabled><option value="<?= app_escape($addressProvince['id']) ?>" selected><?= app_escape($addressProvince['name']) ?></option></select><input type="hidden" name="province_id" value="<?= app_escape(REGISTRATION_PROVINCE_ID) ?>"></div>
<div class="col-md-4"><label for="addressPostcode" class="form-label">รหัสไปรษณีย์</label><input id="addressPostcode" class="form-control" value="<?= app_escape($addressSubdistrict['postcode'] ?? '') ?>" readonly placeholder="เติมให้อัตโนมัติ" aria-describedby="postcodeHelp"><div id="postcodeHelp" class="form-text">เติมให้เมื่อเลือกตำบล/แขวง</div></div>
</div>
<input type="hidden" id="latitude" name="latitude" value="<?= $field('latitude') ?>"><input type="hidden" id="longitude" name="longitude" value="<?= $field('longitude') ?>">
<section id="authMapDetails" class="auth-map registration-map" data-lat="<?= $defaultLat ?>" data-lng="<?= $defaultLng ?>" data-geocoder="https://photon.komoot.io/api/" aria-labelledby="addressMapTitle">
<h3 id="addressMapTitle">ตำแหน่งที่อยู่</h3><p class="form-text">ค้นหาหมู่บ้านก่อน แล้วค้นหาบ้านเลขที่ในบริเวณใกล้เคียง หากไม่พบจะค้นหาตำบล อำเภอ และจังหวัดตามลำดับ ผลค้นหาเป็นตำแหน่งโดยประมาณ ลากหมุดหรือแตะแผนที่เพื่อเลือกตำแหน่งบ้านจริงได้</p>
<button id="findAddressLocation" type="button" class="btn btn-outline-primary mb-3">ค้นหาตำแหน่งจากที่อยู่</button><p id="mapStatus" class="auth-map-help mb-3" role="status" aria-live="polite">เลือกอำเภอหรือตำบล แล้วกดค้นหาตำแหน่งได้</p><div id="map" aria-label="แผนที่เลือกตำแหน่งที่อยู่"></div><button id="confirmMapLocation" type="button" class="btn btn-outline-success mt-3" hidden>ตรวจสอบแล้ว ใช้ตำแหน่งหมุดนี้</button></section>
</fieldset><button class="auth-submit">สมัครสมาชิกและเริ่มใช้งาน →</button><p class="text-center mt-3 mb-0"><span class="form-text">มีบัญชีอยู่แล้ว?</span> <a class="auth-link" href="index.php">เข้าสู่ระบบ</a></p></form>
<?php else: ?>
<h2 id="authTitle">ตั้ง รหัสผ่าน ให้บัญชีเดิม</h2><p class="auth-intro">สำหรับผู้ที่เคยลงทะเบียนยืมกับวัด แต่ยังไม่ได้ตั้ง รหัสผ่าน</p>
<form method="post" class="auth-login-fields"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="activate_legacy">
<div><label for="legacyPhone" class="form-label">เบอร์โทรศัพท์ที่เคยลงทะเบียน</label><input id="legacyPhone" name="phone_number" type="tel" autocomplete="tel" maxlength="20" class="form-control" value="<?= $field('phone_number') ?>" required></div>
<div class="row"><div class="col-sm-6"><label for="legacyFirst" class="form-label">ชื่อเดิม</label><input id="legacyFirst" name="first_name" maxlength="100" class="form-control" autocomplete="given-name" value="<?= $field('first_name') ?>" required></div><div class="col-sm-6"><label for="legacyLast" class="form-label">นามสกุลเดิม</label><input id="legacyLast" name="last_name" maxlength="100" class="form-control" autocomplete="family-name" value="<?= $field('last_name') ?>" required></div></div>
<div><?php $pinField('legacyPin','pin','ตั้ง รหัสผ่าน 6 หลัก',true); ?></div><div><?php $pinField('legacyConfirm','pin_confirm','กรอก รหัสผ่าน อีกครั้ง',true,'legacyPin'); ?></div>
<button class="auth-submit">ยืนยันข้อมูลและตั้ง รหัสผ่าน</button></form><div class="auth-support">กรอกชื่อและเบอร์ให้ตรงกับข้อมูลเดิม หากยืนยันไม่ได้ กรุณาติดต่อเจ้าหน้าที่วัด</div>
<?php endif; ?>
</section></main><footer class="auth-footnote">ยืมอย่างใส่ใจ คืนให้ครบ เพื่อให้ชุมชนได้ใช้ร่วมกัน</footer>
<?php if ($mode === 'register'): ?><script type="application/json" id="thaiAddressData"><?= json_encode([$addressProvince], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><script src="../assets/js/thai-address.js?v=2" defer></script><script src="../leaflet/dist/leaflet.js" defer></script><?php endif; ?>
<?php if ($mode === 'register'): ?><script type="application/json" id="registrationVillageLocations"><?= json_encode(json_decode(file_get_contents(__DIR__ . '/../assets/data/registration-village-locations.json'), true, 512, JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script><script src="../assets/js/registration-villages.js?v=3" defer></script><script src="../assets/js/registration-address.js?v=5" defer></script><?php endif; ?>
<script src="../assets/js/auth.js?v=3" defer></script></body></html>
