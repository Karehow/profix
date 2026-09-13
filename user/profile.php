<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/profile_photo.php';
ensure_feature_tables($conn);

$currentUser = require_roles($conn, ['user'], 'index.php');
$userId = (int) $currentUser['user_id'];

function load_borrower_profile(mysqli $conn, int $userId): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, phone_number, address_detail, latitude, longitude, profile_image_url
        FROM users WHERE user_id = ? AND role = 'user' AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $profile;
}

$user = load_borrower_profile($conn, $userId);
if (!$user) {
    $_SESSION = [];
    session_regenerate_id(true);
    app_redirect('index.php');
}

$error = '';
$flash = flash_take();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
    $address = trim((string) ($_POST['address_detail'] ?? ''));
    $latitudeInput = trim((string) ($_POST['latitude'] ?? ''));
    $longitudeInput = trim((string) ($_POST['longitude'] ?? ''));
    $newPhoto = null;
    $oldPhoto = null;

    try {
        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new RuntimeException('กรุณากรอกชื่อและนามสกุลให้ครบถ้วน (ไม่เกิน 100 ตัวอักษร)');
        }
        if (!valid_thai_phone($phone)) {
            throw new RuntimeException('กรุณากรอกเบอร์โทรศัพท์ไทย 9–10 หลัก เช่น 0812345678');
        }
        if ($address === '' || mb_strlen($address) > 5000) {
            throw new RuntimeException('กรุณากรอกที่อยู่หรือสถานที่นำของไปใช้ (ไม่เกิน 5,000 ตัวอักษร)');
        }
        if (($latitudeInput === '') !== ($longitudeInput === '')) {
            throw new RuntimeException('กรุณาระบุพิกัดละติจูดและลองจิจูดให้ครบทั้งสองค่า');
        }

        $latitude = parse_coordinate($latitudeInput, -90, 90);
        $longitude = parse_coordinate($longitudeInput, -180, 180);

        mysqli_begin_transaction($conn);
        try {
            $lockStmt = mysqli_prepare($conn, "SELECT user_id, profile_image_url FROM users
                WHERE user_id = ? AND role = 'user' AND is_active = 1 FOR UPDATE");
            mysqli_stmt_bind_param($lockStmt, 'i', $userId);
            mysqli_stmt_execute($lockStmt);
            $lockedUser = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));
            mysqli_stmt_close($lockStmt);
            if (!$lockedUser) {
                throw new RuntimeException('ไม่พบบัญชีผู้ใช้งานหรือบัญชีถูกปิดใช้งาน');
            }

            $duplicateStmt = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE normalized_phone = ? AND user_id <> ? LIMIT 1');
            mysqli_stmt_bind_param($duplicateStmt, 'si', $phone, $userId);
            mysqli_stmt_execute($duplicateStmt);
            $duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateStmt));
            mysqli_stmt_close($duplicateStmt);
            if ($duplicate) {
                throw new RuntimeException('เบอร์โทรศัพท์นี้ถูกใช้งานโดยบัญชีอื่นแล้ว');
            }

            $oldPhoto = $lockedUser['profile_image_url'];
            $newPhoto = profile_photo_store($_FILES['profile_photo'] ?? []);
            $photoUrl = $newPhoto ?? $oldPhoto;

            $updateStmt = mysqli_prepare($conn, 'UPDATE users
                SET first_name = ?, last_name = ?, phone_number = ?, normalized_phone = ?, address_detail = ?, latitude = ?, longitude = ?, profile_image_url = ?
                WHERE user_id = ? AND role = \'user\' AND is_active = 1');
            mysqli_stmt_bind_param(
                $updateStmt,
                'sssssddsi',
                $firstName,
                $lastName,
                $phone,
                $phone,
                $address,
                $latitude,
                $longitude,
                $photoUrl,
                $userId
            );
            if (!mysqli_stmt_execute($updateStmt)) {
                $statementError = mysqli_stmt_errno($updateStmt);
                mysqli_stmt_close($updateStmt);
                if ($statementError === 1062) {
                    throw new RuntimeException('เบอร์โทรศัพท์นี้ถูกใช้งานโดยบัญชีอื่นแล้ว');
                }
                throw new RuntimeException('ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
            }
            mysqli_stmt_close($updateStmt);

            write_audit_log($conn, $userId, 'update_profile', 'ผู้ยืมแก้ไขข้อมูลส่วนตัวและสถานที่ใช้งาน');
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            profile_photo_delete($newPhoto);
            throw $e;
        }

        if ($newPhoto !== null) profile_photo_delete($oldPhoto);
        $_SESSION['profile_image_url'] = $photoUrl;
        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
        flash_set('success', 'บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว');
        app_redirect('profile.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException
            ? 'พิกัดตำแหน่งไม่ถูกต้อง กรุณาปักหมุดบนแผนที่ใหม่'
            : $e->getMessage();
        $user = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone_number' => $phone,
            'address_detail' => $address,
            'latitude' => $latitudeInput,
            'longitude' => $longitudeInput,
            'profile_image_url' => $user['profile_image_url'],
        ];
    }
}

$hasSavedCoordinates = $user['latitude'] !== null && $user['latitude'] !== ''
    && $user['longitude'] !== null && $user['longitude'] !== '';
$defaultLat = $hasSavedCoordinates ? (float) $user['latitude'] : 14.97221552;
$defaultLng = $hasSavedCoordinates ? (float) $user['longitude'] : 102.14875030;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ข้อมูลส่วนตัว - ระบบยืมคืนสิ่งของวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="stylesheet" href="../leaflet/dist/leaflet.css">
    <style>
        #profileMap { height: 320px; border-radius: 12px; }
        .profile-card { max-width: 850px; margin: auto; }
    </style>
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light pb-5">
<?php $uiPage = 'user/profile.php'; require __DIR__ . '/../config/page_shell.php'; ?>


<main class="container">
    <div class="profile-card">
        <div class="mb-4">
            <h1 class="h3 fw-bold mb-1">ข้อมูลส่วนตัว</h1>
            <p class="text-muted mb-0">แก้ไขข้อมูลติดต่อและตำแหน่งสถานที่นำสิ่งของไปใช้งาน</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= app_escape($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= app_escape($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= app_escape($error) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
            <input type="hidden" name="csrf_token" value="<?= app_escape(csrf_token()) ?>">
            <div class="card-body p-4">
                <div class="profile-photo-editor mb-4 pb-4 border-bottom">
                    <div class="profile-photo-frame">
                        <img id="profilePhotoPreview" src="<?= app_escape($user['profile_image_url'] ?: '../assets/images/profile-placeholder.svg') ?>" alt="รูปโปรไฟล์ของคุณ" width="112" height="112">
                    </div>
                    <div class="profile-photo-controls">
                        <label for="profilePhoto" class="form-label fw-bold">รูปโปรไฟล์</label>
                        <input type="file" class="form-control" id="profilePhoto" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="profilePhotoHelp profilePhotoStatus">
                        <div class="form-text" id="profilePhotoHelp">JPG, PNG หรือ WebP ไม่เกิน 5 MB เลือกรูปแล้วกดบันทึกข้อมูลด้านล่าง</div>
                        <div id="profilePhotoStatus" class="small mt-2" role="status" aria-live="polite"></div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="firstName">ชื่อ <span class="text-danger">*</span></label>
                        <input id="firstName" name="first_name" class="form-control" maxlength="100" value="<?= app_escape($user['first_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="lastName">นามสกุล <span class="text-danger">*</span></label>
                        <input id="lastName" name="last_name" class="form-control" maxlength="100" value="<?= app_escape($user['last_name']) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="phone">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
                        <input id="phone" type="tel" inputmode="tel" name="phone_number" class="form-control" maxlength="20" value="<?= app_escape($user['phone_number']) ?>" placeholder="0812345678" required>
                        <div class="form-text">ใช้เบอร์นี้เข้าสู่ระบบร่วมกับ รหัสผ่าน ของคุณ</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">ที่อยู่/สถานที่นำของไปใช้งาน <span class="text-danger">*</span></label>
                        <textarea id="address" name="address_detail" class="form-control" rows="3" maxlength="5000" required><?= app_escape($user['address_detail'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">ตำแหน่งบนแผนที่</label>
                        <p class="small text-muted mb-2">คลิกบนแผนที่หรือลากหมุดเพื่อเปลี่ยนตำแหน่ง</p>
                        <div id="profileMap"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="latitude">ละติจูด</label>
                        <input id="latitude" name="latitude" class="form-control" value="<?= app_escape((string) ($user['latitude'] ?? '')) ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="longitude">ลองจิจูด</label>
                        <input id="longitude" name="longitude" class="form-control" value="<?= app_escape((string) ($user['longitude'] ?? '')) ?>" readonly>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white p-3 d-flex justify-content-end gap-2">
                <a href="home.php" class="btn btn-outline-secondary">ยกเลิก</a>
                <button type="submit" class="btn btn-primary px-4">บันทึกข้อมูล</button>
            </div>
        </form>
    </div>
</main>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../leaflet/dist/leaflet.js"></script>
<script src="../assets/js/profile-photo.js" defer></script>
<script>
const initialLat = <?= json_encode($defaultLat, JSON_PRESERVE_ZERO_FRACTION) ?>;
const initialLng = <?= json_encode($defaultLng, JSON_PRESERVE_ZERO_FRACTION) ?>;
const map = L.map('profileMap').setView([initialLat, initialLng], 14);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);
const marker = L.marker([initialLat, initialLng], { draggable: true }).addTo(map);

function setPosition(lat, lng) {
    marker.setLatLng([lat, lng]);
    document.getElementById('latitude').value = Number(lat).toFixed(8);
    document.getElementById('longitude').value = Number(lng).toFixed(8);
}

map.on('click', event => setPosition(event.latlng.lat, event.latlng.lng));
marker.on('dragend', () => {
    const point = marker.getLatLng();
    setPosition(point.lat, point.lng);
});
</script>
</body>
</html>
