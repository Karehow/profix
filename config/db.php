<?php

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/session.php';

// Production should provide these values through the web-server environment and
// use a dedicated account with SELECT/INSERT/UPDATE/DELETE only.
$host = getenv('PROFIX_DB_HOST') ?: 'localhost';
$user = getenv('PROFIX_DB_USER') ?: 'root';
$pass = getenv('PROFIX_DB_PASSWORD') ?: '';
$dbname = getenv('PROFIX_DB_NAME') ?: 'profix';

// เชื่อมต่อแบบ mysqli (ไม่ใช้ PDO) และไม่เผยรายละเอียดเซิร์ฟเวอร์แก่ผู้ใช้
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = mysqli_connect($host, $user, $pass, $dbname);
} catch (Throwable $e) {
    error_log('ProFix database connection failed: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
    }
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่าหรือติดต่อผู้ดูแลระบบ');
}

// ตั้งค่าชุดอักขระเป็น utf8mb4
mysqli_set_charset($conn, "utf8mb4");
mysqli_query($conn, "SET time_zone = '+07:00'");
mysqli_query($conn, "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

// Refresh authorization from the database on every request. A changed role or
// disabled/deleted account must take effect without trusting stale session data.
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $sessionUserId = (int) $_SESSION['user_id'];
    $hasActiveColumn = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
    $activeExpression = ($hasActiveColumn && mysqli_num_rows($hasActiveColumn) === 1) ? 'is_active' : '1 AS is_active';
    $hasVersionColumn = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'auth_version'");
    $versionExpression = mysqli_num_rows($hasVersionColumn) === 1 ? 'auth_version' : '0 AS auth_version';
    $sessionStmt = mysqli_prepare($conn, "SELECT role, $activeExpression, $versionExpression FROM users WHERE user_id = ? LIMIT 1");
    if ($sessionStmt) {
        mysqli_stmt_bind_param($sessionStmt, 'i', $sessionUserId);
        mysqli_stmt_execute($sessionStmt);
        $sessionUser = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
        mysqli_stmt_close($sessionStmt);
        if (!$sessionUser || (int) $sessionUser['is_active'] !== 1
            || ($sessionUser['role'] === 'user' && (int) $sessionUser['auth_version'] !== (int) ($_SESSION['auth_version'] ?? 0))) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['role'] = $sessionUser['role'];
            $_SESSION['last_activity_at'] = time();
        }
    }
}
?>
