<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) exit(1);
if (($argv[1] ?? '') === '--page') {
    session_save_path(sys_get_temp_dir());
    session_start();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = ['user_id'=>1, 'role'=>'user', 'user_name'=>'Dashboard User', 'auth_version'=>0, 'last_activity_at'=>time()];
    if (($argv[2] ?? '') === 'borrow') {
        $_GET = ['category_id'=>1, 'q'=>'Dashboard'];
        require __DIR__ . '/../user/borrow.php';
    } else {
        require __DIR__ . '/../user/home.php';
    }
    session_destroy();
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function dashboard_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__ . '/profix.sql'));
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/notification_inbox.php';
    ensure_feature_tables($conn);
    mysqli_query($conn, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,role) VALUES ('Dashboard','User','0800000001','0800000001','user')");
    mysqli_query($conn, "INSERT INTO items (category_id,item_name,total_quantity,available_quantity,is_active) VALUES (1,'Dashboard Visible',5,4,1),(2,'Dashboard Other Category',2,2,1),(1,'Dashboard Inactive',1,1,0)");
    create_notification($conn, 1, 'Dashboard notification', 'Visible notice');
    foreach (['home','borrow'] as $page) {
        $process = proc_open([PHP_BINARY, __FILE__, '--page', $page], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        dashboard_check(proc_close($process) === 0 && $errors === '', 'Render errors: ' . $errors);
        if ($page === 'home') {
            dashboard_check(str_contains($html, 'user-dashboard.css') && !str_contains($html, '<style>'), 'External stylesheet missing');
            foreach (['borrow.php','confirm.php','history.php','reservations.php','return.php','compensation.php','profile.php','notificationInbox','csrf_token','Dashboard notification'] as $expected) dashboard_check(str_contains($html, $expected), 'Missing action: ' . $expected);
            dashboard_check(str_contains($html, 'ยังไม่มีรายการยืมสิ่งของในระบบ'), 'Empty request state missing');
        } else {
            dashboard_check(str_contains($html, 'Dashboard Visible'), 'Matching category/search item missing');
            dashboard_check(!str_contains($html, 'Dashboard Other Category') && !str_contains($html, 'Dashboard Inactive'), 'Category filter or active filter failed');
            dashboard_check(str_contains($html, 'name="category_id" value="1"'), 'Search loses category');
        }
    }
    echo "PASS: dashboard render, actions, notifications, empty state, external CSS, category/search filter and active-item eligibility.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
