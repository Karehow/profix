<?php
require_once __DIR__ . '/app.php';

function notification_target(?string $link): string
{
    // Notification links are application pages, never external redirects.
    return $link && preg_match('~^\.\./(?:user|staff|admin)/[a-z_]+\.php(?:\?(?:request_id|inspection_id)=[1-9][0-9]*)?(?:#inspection-[1-9][0-9]*)?$~D', $link)
        ? $link : 'home.php#notificationInbox';
}

function notification_mark_read(mysqli $conn, int $userId, int $noticeId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT link_url, title, message FROM notifications WHERE notification_id = ? AND user_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $noticeId, $userId);
    mysqli_stmt_execute($stmt);
    $notice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$notice) return null;
    if ($notice['link_url'] === '../user/history.php'
        && $notice['title'] === 'ตรวจรับคืนแล้ว: มีรายการต้องชดใช้'
        && preg_match('/#([1-9][0-9]*)/', $notice['message'], $match)) {
        $notice['link_url'] = '../user/compensation.php?request_id=' . $match[1];
    }
    $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ? AND is_read = 0');
    mysqli_stmt_bind_param($stmt, 'ii', $noticeId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $notice;
}

function notification_mark_all_read(mysqli $conn, int $userId, int $throughId): void
{
    $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND notification_id <= ? AND is_read = 0');
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $throughId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function notification_inbox(mysqli $conn, int $userId): array
{
    // A dashboard must not reuse an HTTP-cached unread count after opening a notice.
    if (PHP_SAPI !== 'cli') {
        header('Cache-Control: private, no-store, max-age=0');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_action'])) {
        require_csrf();
        $action = $_POST['notification_action'];
        $target = 'home.php#notificationInbox';
        if ($action === 'read_all') {
            notification_mark_all_read($conn, $userId, (int) ($_POST['through_id'] ?? 0));
        } elseif ($action === 'read' || $action === 'open') {
            $notice = notification_mark_read($conn, $userId, (int) ($_POST['notification_id'] ?? 0));
            if ($action === 'open' && $notice) $target = notification_target($notice['link_url']);
        }
        app_redirect($target);
    }
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total, COALESCE(SUM(is_read = 0), 0) AS unread, COALESCE(MAX(notification_id), 0) AS latest_id FROM notifications WHERE user_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $counts = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $pages = max(1, (int) ceil((int) $counts['total'] / 10));
    $page = min($pages, max(1, (int) ($_GET['notice_page'] ?? 1)));
    $offset = ($page - 1) * 10;
    $stmt = mysqli_prepare($conn, 'SELECT notification_id, title, message, link_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 10 OFFSET ?');
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $offset);
    mysqli_stmt_execute($stmt);
    $messages = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return ['messages' => $messages, 'unread' => (int) $counts['unread'], 'latest_id' => (int) $counts['latest_id'], 'page' => $page, 'pages' => $pages];
}
