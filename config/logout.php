<?php
require_once __DIR__ . '/session.php';
$next = ($_GET['next'] ?? '') === 'user' ? '../user/index.php' : 'login.php';

// ล้างข้อมูล Session ทั้งหมด
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// ส่งกลับไปยังหน้า Login
header('Cache-Control: no-store');
header('Location: ' . $next);
exit();
