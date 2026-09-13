<?php
require_once __DIR__ . '/app.php';

// ถ้า Login อยู่แล้ว ให้ส่งไปหน้าตามสิทธิ์
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && !empty($_SESSION['user_id'])
    && in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)
) {
    $currentUser = require_roles(
        $conn,
        ['admin', 'staff'],
        'login.php'
    );

    if ($currentUser['role'] === 'admin') {
        app_redirect('../admin/home.php');
    }

    app_redirect('../staff/home.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    // ตรวจ CSRF
    require_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {

        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน';

    } else {

        // ตรวจจำนวนครั้งที่ Login ผิด
        $attemptKey = auth_attempt_key('staff-login', $username);

        if (auth_is_locked($conn, $attemptKey)) {

            $error = 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่';

        } else {

            // ค้นหาผู้ใช้จากฐานข้อมูล
            $sql = "
                SELECT
                    user_id,
                    username,
                    password,
                    first_name,
                    last_name,
                    role,
                    is_active
                FROM users
                WHERE username = ?
                AND role IN ('admin', 'staff')
                AND is_active = 1
                LIMIT 1
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {

                $error = 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล';

            } else {

                mysqli_stmt_bind_param($stmt, 's', $username);
                mysqli_stmt_execute($stmt);

                $result = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($result);

                mysqli_stmt_close($stmt);

                /*
                 * ==========================================
                 * รองรับทั้งรหัสผ่านแบบเก่าและแบบ Hash
                 * ==========================================
                 */

                // Dummy hash กรณีไม่พบ username
                $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

                $storedPassword = $user
                    ? (string) ($user['password'] ?? '')
                    : $dummyHash;

                // 1. ตรวจแบบ password_hash()
                $hashPasswordMatches = password_verify(
                    $password,
                    $storedPassword
                );

                // 2. ตรวจรหัสผ่านแบบข้อความธรรมดาจากฐานข้อมูลเดิม
                $plainPasswordMatches = false;

                $storedPasswordInfo = password_get_info($storedPassword);
                // Legacy comparison must never treat a stored hash as a plaintext password.
                if ($user && !$hashPasswordMatches && $storedPasswordInfo['algoName'] === 'unknown') {
                    $plainPasswordMatches = hash_equals(
                        $storedPassword,
                        $password
                    );
                }

                // Login ผ่านถ้าแบบใดแบบหนึ่งถูก
                $passwordMatches =
                    $hashPasswordMatches ||
                    $plainPasswordMatches;

                if (!$user || !$passwordMatches) {

                    // บันทึก Login ผิด
                    auth_record_failure(
                        $conn,
                        $attemptKey
                    );

                    $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';

                } else {

                    /*
                     * ถ้าฐานข้อมูลเดิมเก็บรหัสผ่านแบบ Plain Text
                     * เช่น
                     *
                     * admin = 000000
                     * staff = 123456
                     *
                     * เมื่อ Login สำเร็จ
                     * จะเปลี่ยนเป็น Password Hash ให้อัตโนมัติ
                     */

                    if (
                        $plainPasswordMatches ||
                        (
                            $hashPasswordMatches &&
                            password_needs_rehash(
                                $storedPassword,
                                PASSWORD_DEFAULT
                            )
                        )
                    ) {

                        $newHash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $loginUserId = (int) $user['user_id'];

                        $rehash = mysqli_prepare(
                            $conn,
                            "UPDATE users
                             SET password = ?
                             WHERE user_id = ?"
                        );

                        if ($rehash) {

                            mysqli_stmt_bind_param(
                                $rehash,
                                'si',
                                $newHash,
                                $loginUserId
                            );

                            mysqli_stmt_execute($rehash);
                            mysqli_stmt_close($rehash);
                        }
                    }

                    // ล้างประวัติ Login ผิด
                    auth_clear_failures(
                        $conn,
                        $attemptKey
                    );

                    // สร้าง Session
                    start_authenticated_session($user);

                    // แยกหน้า Admin / Staff
                    if ($user['role'] === 'admin') {

                        app_redirect('../admin/home.php');

                    } else {

                        app_redirect('../staff/home.php');

                    }
                }
            }
        }
    }
}
?>

<?php require __DIR__ . '/staff_auth_view.php'; ?>
