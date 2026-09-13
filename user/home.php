<?php
require_once __DIR__ . '/../config/notification_inbox.php';
ensure_feature_tables($conn);
$session_user = require_roles($conn, ['user'], 'index.php');
$user_id = (int) $session_user['user_id'];
$inbox = notification_inbox($conn, $user_id);

// ดึงข้อมูลผู้ใช้ปัจจุบัน
$user_stmt = mysqli_query($conn, "SELECT * FROM users WHERE user_id = $user_id LIMIT 1");
$user = mysqli_fetch_assoc($user_stmt);

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// สรุปจำนวนรายการยืมแยกตามสถานะ
$pending_q  = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM borrow_requests WHERE user_id = $user_id AND status = 'pending_approval'");
$approved_q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM borrow_requests WHERE user_id = $user_id AND status = 'approved'");
$borrowed_q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM borrow_requests WHERE user_id = $user_id AND status IN ('borrowed','return_requested')");

$pending_count  = mysqli_fetch_assoc($pending_q)['cnt'];
$approved_count = mysqli_fetch_assoc($approved_q)['cnt'];
$borrowed_count = mysqli_fetch_assoc($borrowed_q)['cnt'];

// ดึงรายการยืมที่กำลังดำเนินการอยู่ (ล่าสุด 5 รายการ)
$active_requests = mysqli_query($conn, "SELECT * FROM borrow_requests WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
require __DIR__ . '/../config/user_dashboard_view.php';
