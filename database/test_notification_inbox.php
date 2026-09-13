<?php
// Run against an isolated database server with PROFIX_DB_NAME=profix_test_notifications.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/', $testDb)) {
    fwrite(STDERR, "Set PROFIX_DB_NAME to a new profix_test_* database.\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
try {
    mysqli_select_db($setup, $testDb);
    mysqli_query($setup, 'CREATE TABLE notifications (notification_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, link_url VARCHAR(500) NULL, is_read TINYINT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    require_once __DIR__ . '/../config/notification_inbox.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = [];
    $inbox = notification_inbox($conn, 1);
    check($inbox['messages'] === [] && $inbox['unread'] === 0, 'Empty inbox failed');
    ob_start(); require __DIR__ . '/../config/notification_inbox_view.php'; $emptyHtml = ob_get_clean();
    check(str_contains($emptyHtml, 'ยังไม่มีข้อความแจ้งเตือน'), 'Empty state missing');
    for ($i = 0; $i < 12; $i++) create_notification($conn, 1, '<script>alert(1)</script>', 'Message ' . $i, '../user/history.php');
    create_notification($conn, 2, 'Admin notice', 'Private administrator message', '../staff/requests.php');
    $inbox = notification_inbox($conn, 1);
    check(count($inbox['messages']) === 10 && $inbox['pages'] === 2 && $inbox['unread'] === 12, 'Counts or pagination failed');
    check((int) $inbox['messages'][0]['notification_id'] === 12, 'Newest-first ordering failed');
    check(notification_inbox($conn, 1)['unread'] === 12, 'GET marked messages read');
    check(notification_mark_read($conn, 1, 13) === null, 'Cross-user access permitted');
    check(notification_inbox($conn, 2)['unread'] === 1, 'Other inbox modified');
    notification_mark_read($conn, 1, 12);
    check(notification_inbox($conn, 1)['unread'] === 11, 'Read action failed');
    $_GET['notice_page'] = 2;
    check(count(notification_inbox($conn, 1)['messages']) === 2, 'Second page missing');
    $_GET['notice_page'] = 999;
    check(notification_inbox($conn, 1)['page'] === 2, 'Page clamping failed');
    unset($_GET['notice_page']);
    create_notification($conn, 1, 'New arrival', 'Arrived after the page was loaded');
    notification_mark_all_read($conn, 1, 12);
    check(notification_inbox($conn, 1)['unread'] === 1, 'Read-all consumed a new arrival');
    check(notification_inbox($conn, 2)['unread'] === 1, 'Read-all crossed user boundary');
    foreach (['javascript:alert(1)', '//evil.example', 'https://evil.example', "../user/history.php\r\nLocation: https://evil.example"] as $bad) {
        check(notification_target($bad) === 'home.php#notificationInbox', 'Unsafe redirect accepted');
    }
    check(notification_target('../staff/requests.php') === '../staff/requests.php', 'Valid link rejected');
    $inbox = notification_inbox($conn, 1);
    ob_start(); require __DIR__ . '/../config/notification_inbox_view.php'; $html = ob_get_clean();
    check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Message escaping failed');
    check(str_contains($html, 'csrf_token') && !str_contains($html, 'Private administrator message'), 'Form or privacy check failed');
    create_notification($conn, 1, 'ตรวจรับคืนแล้ว: มีรายการต้องชดใช้', 'คำขอ #42 มีรายการชำรุดหรือสูญหาย', '../user/history.php');
    $legacyId = mysqli_insert_id($conn);
    check(notification_mark_read($conn, 1, $legacyId)['link_url'] === '../user/compensation.php?request_id=42', 'Legacy damage notice did not open evidence');
    check(notification_target('../staff/compensation.php#inspection-12') === '../staff/compensation.php#inspection-12', 'Staff inspection link rejected');
    echo "PASS: empty state, counts, pagination, GET preservation, ownership, read actions, new arrivals, safe links, legacy damage links and escaped rendering.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
