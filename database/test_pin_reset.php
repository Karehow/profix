<?php
// Run against an isolated MariaDB instance only:
// PROFIX_DB_HOST=127.0.0.1:3308 PROFIX_DB_NAME=profix_test_pin_reset php database/test_pin_reset.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/', $testDb)) {
    fwrite(STDERR, "Set PROFIX_DB_NAME to a new profix_test_* database.\n");
    exit(1);
}
if (($argv[1] ?? '') === '--session-probe') {
    session_save_path(sys_get_temp_dir());
    session_start();
    require_once __DIR__ . '/../config/session.php';
    $_SESSION['user_id'] = 2;
    $_SESSION['role'] = 'user';
    $_SESSION['auth_version'] = (int) $argv[2];
    require_once __DIR__ . '/../config/db.php';
    $expectedActive = (int) $argv[2] === 1;
    $result = isset($_SESSION['user_id']) === $expectedActive ? 0 : 1;
    session_destroy();
    exit($result);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
// CREATE without IF NOT EXISTS deliberately refuses to touch an existing database.
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $action): void {
    try { $action(); } catch (PinResetException $e) { return; }
    throw new RuntimeException('Expected reset rejection');
}
try {
    mysqli_select_db($setup, $testDb);
    $sql = file_get_contents(__DIR__ . '/profix.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', $sql);
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); }
    while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/pin_reset.php';
    require_once __DIR__ . '/../config/schema.php';
    mysqli_query($conn, 'ALTER TABLE users DROP COLUMN pin_reset_hash, DROP COLUMN pin_reset_expires_at, DROP COLUMN auth_version');
    migrate_pin_reset($conn);
    migrate_pin_reset($conn);
    ensure_feature_tables($conn);
    mysqli_query($conn, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,role) VALUES
        ('Staff','Test','0800000001','0800000001','staff'),
        ('Borrower','Test','0800000002','0800000002','user'),
        ('Disabled','Test','0800000003','0800000003','user')");
    mysqli_query($conn, 'UPDATE users SET is_active = 0 WHERE user_id = 3');
    rejects(fn() => pin_reset_issue($conn, 2, 2));
    rejects(fn() => pin_reset_issue($conn, 1, 1));
    rejects(fn() => pin_reset_issue($conn, 1, 3));
    $first = pin_reset_issue($conn, 1, 2);
    $code = pin_reset_issue($conn, 1, 2);
    rejects(fn() => pin_reset_complete($conn, '0800000002', $first, '123456', '123456'));
    rejects(fn() => pin_reset_complete($conn, '0800000099', $code, '123456', '123456'));
    rejects(fn() => pin_reset_complete($conn, '0800000002', $code, '123456', '654321'));
    pin_reset_complete($conn, '+66 80 000 0002', strtolower($code), '012345', '012345');
    $user = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM users WHERE user_id = 2'));
    check(password_verify('012345', $user['borrower_pin_hash']), 'New PIN hash failed');
    check($user['pin_reset_hash'] === null && $user['pin_reset_expires_at'] === null, 'Code not consumed');
    check((int) $user['auth_version'] === 1, 'Sessions not invalidated');
    foreach ([0, 1] as $version) {
        $process = proc_open([PHP_BINARY, __FILE__, '--session-probe', (string) $version], [], $pipes);
        check(is_resource($process) && proc_close($process) === 0, 'Session version check failed');
    }
    rejects(fn() => pin_reset_complete($conn, '0800000002', $code, '123456', '123456'));
    $expired = pin_reset_issue($conn, 1, 2);
    mysqli_query($conn, 'UPDATE users SET pin_reset_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE user_id = 2');
    rejects(fn() => pin_reset_complete($conn, '0800000002', $expired, '123456', '123456'));
    $disabled = pin_reset_issue($conn, 1, 2);
    mysqli_query($conn, 'UPDATE users SET is_active = 0 WHERE user_id = 2');
    rejects(fn() => pin_reset_complete($conn, '0800000002', $disabled, '123456', '123456'));
    mysqli_query($conn, 'UPDATE users SET is_active = 1 WHERE user_id = 2');
    auth_clear_failures($conn, auth_attempt_key('pin-reset', 'all'));
    mysqli_query($conn, 'RENAME TABLE audit_logs TO audit_logs_test_unavailable');
    $failed = false;
    try { pin_reset_complete($conn, '0800000002', $disabled, '987654', '987654'); }
    catch (mysqli_sql_exception $e) { $failed = true; }
    finally { mysqli_query($conn, 'RENAME TABLE audit_logs_test_unavailable TO audit_logs'); }
    check($failed, 'Expected audit failure');
    $unchanged = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM users WHERE user_id = 2'));
    check(password_verify('012345', $unchanged['borrower_pin_hash']) && $unchanged['pin_reset_hash'] !== null, 'Reset did not roll back');
    pin_reset_complete($conn, '0800000002', $disabled, '987654', '987654');
    auth_clear_failures($conn, auth_attempt_key('pin-reset', 'all'));
    $activeCode = pin_reset_issue($conn, 1, 2);
    for ($i = 0; $i < 5; $i++) rejects(fn() => pin_reset_complete($conn, '0800000002', '0000000000000000', '123456', '123456'));
    check(auth_is_locked($conn, auth_attempt_key('pin-reset', 'all')), 'Rate limit failed');
    rejects(fn() => pin_reset_complete($conn, '0800000002', $activeCode, '123456', '123456'));
    rejects(fn() => pin_reset_self_service($conn, '0800000002', 'Wrong', 'Test', '123456', '123456'));
    rejects(fn() => pin_reset_self_service($conn, '0800000001', 'Staff', 'Test', '123456', '123456'));
    rejects(fn() => pin_reset_self_service($conn, '0800000003', 'Disabled', 'Test', '123456', '123456'));
    rejects(fn() => pin_reset_self_service($conn, '0800000002', 'Borrower', 'Test', '123456', '654321'));
    $versionBefore = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT auth_version FROM users WHERE user_id = 2'))[0];
    pin_reset_self_service($conn, '+66 80 000 0002', ' Borrower ', 'Test', '654321', '654321');
    $selfReset = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM users WHERE user_id = 2'));
    check(password_verify('654321', $selfReset['borrower_pin_hash']), 'Self-service reset failed');
    check((int) $selfReset['auth_version'] === $versionBefore + 1 && $selfReset['pin_reset_hash'] === null, 'Self-service session/code revocation failed');
    for ($i = 0; $i < 5; $i++) rejects(fn() => pin_reset_self_service($conn, '0800000002', 'Wrong', 'Test', '123456', '123456'));
    rejects(fn() => pin_reset_self_service($conn, '0800000002', 'Borrower', 'Test', '123456', '123456'));
    echo "PASS: self-service identity, role, disabled account, PIN confirmation, reset, session revocation and throttling.\n";
    $logs = mysqli_query($conn, 'SELECT detail FROM audit_logs');
    while ($log = mysqli_fetch_assoc($logs)) {
        foreach ([$first, $code, $expired, $disabled, '012345'] as $secret) check(!str_contains($log['detail'], $secret), 'Secret in audit log');
    }
    echo "PASS: migrations, permissions, replacement, invalid identity, PIN confirmation, reset, session revocation, replay, expiry, disabled account, rate limit, rollback and audit secrecy.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
