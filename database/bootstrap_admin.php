<?php

declare(strict_types=1);

/**
 * Create the first administrator without storing a default password in source.
 *
 * The safest default is to let this script generate a random password and print
 * it once. For automation, use PROFIX_BOOTSTRAP_PASSWORD or --password-stdin;
 * passwords are deliberately never accepted as command-line arguments.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function bootstrap_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function bootstrap_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function bootstrap_usage(): void
{
    echo <<<'TEXT'
Usage:
  php database/bootstrap_admin.php --username=admin --phone=0800000000 [options]

Options:
  --first-name=NAME     Administrator first name (default: ผู้ดูแล)
  --last-name=NAME      Administrator last name (default: ระบบ)
  --password-stdin      Read the password from the first line of standard input
  --help                Show this message

If neither --password-stdin nor PROFIX_BOOTSTRAP_PASSWORD is supplied, a strong
random password is generated and displayed once after the account is created.
Passwords must contain at least 12 characters.
TEXT;
    echo PHP_EOL;
}

$options = getopt('', [
    'username:',
    'phone:',
    'first-name::',
    'last-name::',
    'password-stdin',
    'help',
]);

if (isset($options['help'])) {
    bootstrap_usage();
    exit(0);
}

$username = trim((string) ($options['username'] ?? ''));
$phone = bootstrap_phone((string) ($options['phone'] ?? ''));
$firstName = trim((string) ($options['first-name'] ?? 'ผู้ดูแล'));
$lastName = trim((string) ($options['last-name'] ?? 'ระบบ'));

if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    bootstrap_usage();
    bootstrap_fail('ชื่อผู้ใช้ต้องยาว 3-50 ตัว และใช้เฉพาะ A-Z, a-z, 0-9, จุด ขีดกลาง หรือขีดล่าง');
}
if (!preg_match('/^0[0-9]{8,9}$/', $phone)) {
    bootstrap_fail('เบอร์โทรศัพท์ไม่ถูกต้อง (ตัวอย่าง 0800000000)');
}
if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
    bootstrap_fail('ชื่อและนามสกุลต้องมีค่าและยาวไม่เกิน 100 ตัวอักษร');
}

$environmentPassword = getenv('PROFIX_BOOTSTRAP_PASSWORD');
$readPasswordFromStdin = isset($options['password-stdin']);
if ($readPasswordFromStdin && $environmentPassword !== false && $environmentPassword !== '') {
    bootstrap_fail('เลือกใช้ --password-stdin หรือ PROFIX_BOOTSTRAP_PASSWORD อย่างใดอย่างหนึ่ง');
}

$generatedPassword = false;
if ($readPasswordFromStdin) {
    $input = fgets(STDIN);
    if ($input === false) {
        bootstrap_fail('ไม่พบรหัสผ่านจาก standard input');
    }
    $password = rtrim($input, "\r\n");
} elseif ($environmentPassword !== false && $environmentPassword !== '') {
    $password = $environmentPassword;
} else {
    $password = bin2hex(random_bytes(12));
    $generatedPassword = true;
}

if (strlen($password) < 12) {
    bootstrap_fail('รหัสผ่านผู้ดูแลต้องยาวอย่างน้อย 12 ตัวอักษร');
}
if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
    bootstrap_fail('รหัสผ่านต้องไม่มีอักขระควบคุม');
}

require_once __DIR__ . '/../config/db.php';

$requiredColumns = ['username', 'password', 'normalized_phone', 'is_active'];
foreach ($requiredColumns as $requiredColumn) {
    $column = mysqli_real_escape_string($conn, $requiredColumn);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$column'");
    if (!$result || mysqli_num_rows($result) !== 1) {
        bootstrap_fail('ฐานข้อมูลยังไม่พร้อม กรุณานำเข้า database/profix.sql หรือรัน php database/migrate.php ก่อน');
    }
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    bootstrap_fail('ไม่สามารถเข้ารหัสรหัสผ่านได้');
}

mysqli_begin_transaction($conn);
try {
    $duplicate = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE username = ? OR normalized_phone = ? LIMIT 1 FOR UPDATE');
    if (!$duplicate) {
        throw new RuntimeException(mysqli_error($conn));
    }
    mysqli_stmt_bind_param($duplicate, 'ss', $username, $phone);
    mysqli_stmt_execute($duplicate);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
    mysqli_stmt_close($duplicate);
    if ($existing) {
        throw new RuntimeException('ชื่อผู้ใช้หรือเบอร์โทรศัพท์นี้มีอยู่แล้ว ระบบจะไม่เขียนทับบัญชีเดิม');
    }

    $address = 'บัญชีผู้ดูแลระบบที่สร้างผ่าน CLI';
    $role = 'admin';
    $insert = mysqli_prepare($conn, 'INSERT INTO users
        (username, password, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    if (!$insert) {
        throw new RuntimeException(mysqli_error($conn));
    }
    mysqli_stmt_bind_param(
        $insert,
        'ssssssss',
        $username,
        $passwordHash,
        $firstName,
        $lastName,
        $phone,
        $phone,
        $address,
        $role
    );
    if (!mysqli_stmt_execute($insert)) {
        throw new RuntimeException(mysqli_stmt_error($insert));
    }
    $userId = mysqli_insert_id($conn);
    mysqli_stmt_close($insert);

    mysqli_commit($conn);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    bootstrap_fail('สร้างบัญชีผู้ดูแลไม่สำเร็จ: ' . $error->getMessage());
}

echo "สร้างบัญชีผู้ดูแลสำเร็จ (user_id: {$userId}, username: {$username})" . PHP_EOL;
if ($generatedPassword) {
    echo 'รหัสผ่านชั่วคราว (แสดงครั้งเดียว): ' . $password . PHP_EOL;
    echo 'กรุณาเข้าสู่ระบบและเปลี่ยนเป็นรหัสผ่านเฉพาะของผู้ดูแลทันที' . PHP_EOL;
} else {
    echo 'ใช้รหัสผ่านที่รับจากช่องทางปลอดภัยและจัดเก็บไว้ในตัวจัดการรหัสผ่าน' . PHP_EOL;
}

