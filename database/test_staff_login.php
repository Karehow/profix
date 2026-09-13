<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) { fwrite(STDERR, "Use a new profix_test_* database.\n"); exit(1); }
if (($argv[1] ?? '') === '--attempt') {
    session_save_path(sys_get_temp_dir());
    session_start();
    $_SESSION = ['csrf_token'=>'test-token'];
    $_POST = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    register_shutdown_function(static function () {
        ob_end_clean();
        echo json_encode(['user_id'=>$_SESSION['user_id'] ?? null, 'role'=>$_SESSION['role'] ?? null]);
        session_destroy();
    });
    require __DIR__ . '/../config/login.php';
    exit;
}
function login_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function login_attempt(string $username, string $password, string $csrf = 'test-token'): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--attempt'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode(['login'=>1,'username'=>$username,'password'=>$password,'csrf_token'=>$csrf])); fclose($pipes[0]);
    $json = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    login_check(proc_close($process) === 0 && $errors === '', 'Login PHP error: ' . $errors);
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__ . '/profix.sql'));
    mysqli_multi_query($setup, str_replace('USE `profix`;', '', $sql));
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    $password = 'Test-only-secret-123!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($setup, "INSERT INTO users (username,password,first_name,last_name,phone_number,role) VALUES ('test-staff',?,'Staff','Test','0800000001','staff'),('test-admin',?,'Admin','Test','0800000002','admin'),('legacy',?,'Legacy','Test','0800000003','staff')");
    mysqli_stmt_bind_param($stmt, 'sss', $hash, $hash, $password);
    mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
    login_check(login_attempt('test-staff', $hash)['user_id'] === null, 'Stored hash accepted as password');
    login_check(login_attempt('test-staff', 'wrong')['user_id'] === null, 'Incorrect password accepted');
    login_check(login_attempt('test-staff', $password, 'wrong')['user_id'] === null, 'CSRF accepted');
    login_check(login_attempt('test-staff', $password)['role'] === 'staff', 'Valid staff login failed');
    login_check(login_attempt('test-admin', $password)['role'] === 'admin', 'Valid admin login failed');
    login_check(login_attempt('legacy', $password)['role'] === 'staff', 'Legacy login failed');
    $upgraded = mysqli_fetch_row(mysqli_query($setup, "SELECT password FROM users WHERE username='legacy'"))[0];
    login_check($upgraded !== $password && password_verify($password, $upgraded), 'Legacy password not upgraded');
    mysqli_query($setup, "UPDATE users SET is_active=0 WHERE username='test-admin'");
    login_check(login_attempt('test-admin', $password)['user_id'] === null, 'Disabled account accepted');
    login_check(login_attempt('missing', $password)['user_id'] === null, 'Unknown account accepted');
    for ($i=0; $i<5; $i++) login_attempt('test-staff', 'wrong');
    login_check(login_attempt('test-staff', $password)['user_id'] === null, 'Login throttling failed');
    echo "PASS: staff/admin login, hash rejection, incorrect credentials, CSRF, legacy password upgrade, disabled accounts and throttling.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
