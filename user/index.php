<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/thai_address.php';
ensure_feature_tables($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'user') {
        app_redirect('home.php');
    }
    if (($_SESSION['role'] ?? '') === 'admin') {
        app_redirect('../admin/home.php');
    }
    if (($_SESSION['role'] ?? '') === 'staff') {
        app_redirect('../staff/home.php');
    }
}

$error = '';
$defaultLat = 14.97221552;
$defaultLng = 102.14875030;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
            throw new RuntimeException('กรุณาออกจากระบบเจ้าหน้าที่ก่อนเข้าสู่บัญชีผู้ยืม');
        }

        if ($action === 'login') {
            $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
            $pin = (string) ($_POST['pin'] ?? '');
            if (!valid_thai_phone($phone) || !valid_pin($pin)) {
                throw new RuntimeException('กรุณากรอกเบอร์โทรศัพท์และ PIN 6 หลักให้ถูกต้อง');
            }
            $attemptKey = auth_attempt_key('borrower-login', $phone);
            if (auth_is_locked($conn, $attemptKey)) {
                throw new RuntimeException('เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่');
            }
            $stmt = mysqli_prepare($conn, "SELECT user_id, first_name, last_name, role, is_active, borrower_pin_hash, auth_version
                FROM users WHERE normalized_phone = ? AND role = 'user' AND is_active = 1 LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $phone);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $hash = $user && $user['borrower_pin_hash'] ? (string) $user['borrower_pin_hash'] : $dummyHash;
            if (!$user || !$user['borrower_pin_hash'] || !password_verify($pin, $hash)) {
                auth_record_failure($conn, $attemptKey);
                throw new RuntimeException($user && !$user['borrower_pin_hash']
                    ? 'บัญชีเดิมยังไม่มี PIN กรุณาใช้เมนู “ตั้ง PIN สำหรับบัญชีเดิม”'
                    : 'เบอร์โทรศัพท์หรือ PIN ไม่ถูกต้อง');
            }
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $newPinHash = password_hash($pin, PASSWORD_DEFAULT);
                $loginUserId = (int) $user['user_id'];
                $rehash = mysqli_prepare($conn, 'UPDATE users SET borrower_pin_hash = ? WHERE user_id = ?');
                mysqli_stmt_bind_param($rehash, 'si', $newPinHash, $loginUserId);
                mysqli_stmt_execute($rehash);
                mysqli_stmt_close($rehash);
            }
            auth_clear_failures($conn, $attemptKey);
            start_authenticated_session($user);
            app_redirect('home.php');
        }

        if ($action === 'activate_legacy') {
            $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $pin = (string) ($_POST['pin'] ?? '');
            $pinConfirm = (string) ($_POST['pin_confirm'] ?? '');
            if (!valid_thai_phone($phone) || $firstName === '' || $lastName === '' || !valid_pin($pin) || $pin !== $pinConfirm) {
                throw new RuntimeException('ข้อมูลยืนยันตัวตนหรือ PIN ไม่ถูกต้อง');
            }
            $attemptKey = auth_attempt_key('borrower-activation', $phone);
            if (auth_is_locked($conn, $attemptKey)) {
                throw new RuntimeException('ยืนยันบัญชีไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีหรือติดต่อเจ้าหน้าที่');
            }
            $activationIdentityFailed = false;
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "SELECT user_id, first_name, last_name, role, is_active, borrower_pin_hash, auth_version
                    FROM users WHERE normalized_phone = ? AND role = 'user' AND is_active = 1 FOR UPDATE");
                mysqli_stmt_bind_param($stmt, 's', $phone);
                mysqli_stmt_execute($stmt);
                $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if (!$user || $user['borrower_pin_hash'] || trim($user['first_name']) !== $firstName || trim($user['last_name']) !== $lastName) {
                    $activationIdentityFailed = true;
                    throw new RuntimeException('ไม่สามารถยืนยันบัญชีเดิมได้ กรุณาติดต่อเจ้าหน้าที่');
                }
                $pinHash = password_hash($pin, PASSWORD_DEFAULT);
                $legacyUserId = (int) $user['user_id'];
                $update = mysqli_prepare($conn, 'UPDATE users SET borrower_pin_hash = ? WHERE user_id = ? AND borrower_pin_hash IS NULL');
                mysqli_stmt_bind_param($update, 'si', $pinHash, $legacyUserId);
                mysqli_stmt_execute($update);
                if (mysqli_stmt_affected_rows($update) !== 1) {
                    throw new RuntimeException('บัญชีนี้ตั้ง PIN แล้ว กรุณาเข้าสู่ระบบตามปกติ');
                }
                mysqli_stmt_close($update);
                write_audit_log($conn, (int) $user['user_id'], 'activate_borrower_pin', 'ตั้ง PIN ให้บัญชีเดิม');
                auth_clear_failures($conn, $attemptKey);
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                if ($activationIdentityFailed) {
                    auth_record_failure($conn, $attemptKey);
                }
                throw $e;
            }
            start_authenticated_session($user);
            app_redirect('home.php');
        }

        if ($action === 'register') {
            if (($_POST['province_id'] ?? '') !== REGISTRATION_PROVINCE_ID) {
                throw new RuntimeException('สมัครสมาชิกได้เฉพาะที่อยู่ในจังหวัดนครราชสีมา');
            }
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
            $address = thai_address_format($_POST);
            $pin = (string) ($_POST['pin'] ?? '');
            $pinConfirm = (string) ($_POST['pin_confirm'] ?? '');
            $latitude = parse_coordinate($_POST['latitude'] ?? null, -90, 90);
            $longitude = parse_coordinate($_POST['longitude'] ?? null, -180, 180);
            if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
                throw new RuntimeException('กรุณากรอกชื่อและนามสกุลให้ถูกต้อง');
            }
            if (!valid_thai_phone($phone)) {
                throw new RuntimeException('กรุณากรอกเบอร์โทรศัพท์ไทย 9–10 หลัก');
            }
            if (!valid_pin($pin) || $pin !== $pinConfirm) {
                throw new RuntimeException('PIN ต้องเป็นตัวเลข 6 หลักและกรอกตรงกัน');
            }
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "INSERT INTO users
                    (first_name, last_name, phone_number, normalized_phone, borrower_pin_hash, address_detail, latitude, longitude, role, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', 1)");
                mysqli_stmt_bind_param($stmt, 'ssssssdd', $firstName, $lastName, $phone, $phone, $pinHash, $address, $latitude, $longitude);
                if (!mysqli_stmt_execute($stmt)) {
                    $message = mysqli_stmt_errno($stmt) === 1062 ? 'เบอร์โทรศัพท์นี้ลงทะเบียนแล้ว กรุณาเข้าสู่ระบบ' : 'ไม่สามารถลงทะเบียนได้';
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($message);
                }
                $userId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                write_audit_log($conn, $userId, 'register_borrower', 'ลงทะเบียนผู้ยืม');
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                throw $e;
            }
            $user = ['user_id' => $userId, 'first_name' => $firstName, 'last_name' => $lastName, 'role' => 'user'];
            start_authenticated_session($user);
            app_redirect('home.php');
        }

        throw new RuntimeException('การดำเนินการไม่ถูกต้อง');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
[$addressProvince, $addressDistrict, $addressSubdistrict] = thai_address_selection(array_merge($_POST, ['province_id' => REGISTRATION_PROVINCE_ID, 'district_id' => $_POST['district_id'] ?? REGISTRATION_DISTRICT_ID]));
?>
<?php require __DIR__ . '/../config/user_auth_view.php'; ?>
