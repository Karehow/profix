<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/features.php';

function app_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

function require_roles(mysqli $conn, array $roles, string $loginPath): array
{
    if (empty($_SESSION['user_id'])) {
        app_redirect($loginPath);
    }
    $userId = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, 'SELECT user_id, first_name, last_name, role, is_active, profile_image_url FROM users WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$user || (int) $user['is_active'] !== 1 || !in_array($user['role'], $roles, true)) {
        $_SESSION = [];
        session_regenerate_id(true);
        app_redirect($loginPath);
    }
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['profile_image_url'] = $user['profile_image_url'];
    return $user;
}

function start_authenticated_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 0);
    $_SESSION['last_activity_at'] = time();
    csrf_token();
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function valid_thai_phone(string $phone): bool
{
    return (bool) preg_match('/^0[0-9]{8,9}$/', $phone);
}

function valid_pin(string $pin): bool
{
    return (bool) preg_match('/^[0-9]{6}$/', $pin);
}

function parse_coordinate($value, float $minimum, float $maximum): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('พิกัดไม่ถูกต้อง');
    }
    $number = (float) $value;
    if ($number < $minimum || $number > $maximum) {
        throw new InvalidArgumentException('พิกัดอยู่นอกช่วงที่ถูกต้อง');
    }
    return $number;
}

function borrow_status_label(string $status): string
{
    return [
        'pending_approval' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว รอรับของ',
        'borrowed' => 'กำลังยืม',
        'return_requested' => 'รอตรวจรับคืน',
        'returned' => 'คืนเรียบร้อย',
        'partially_damaged' => 'คืนแล้ว มีชำรุด/สูญหาย',
        'rejected' => 'ไม่อนุมัติ',
        'cancelled' => 'ยกเลิก',
    ][$status] ?? $status;
}

function borrow_status_badge(string $status): string
{
    return [
        'pending_approval' => 'bg-warning text-dark',
        'approved' => 'bg-success',
        'borrowed' => 'bg-primary',
        'return_requested' => 'bg-info text-dark',
        'returned' => 'bg-success',
        'partially_damaged' => 'bg-danger',
        'rejected' => 'bg-secondary',
        'cancelled' => 'bg-dark',
    ][$status] ?? 'bg-secondary';
}

function reservation_status_label(string $status): string
{
    return [
        'pending' => 'รอตรวจสอบ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        'cancelled' => 'ยกเลิก',
        'fulfilled' => 'สร้างคำขอยืมแล้ว',
        'expired' => 'หมดอายุ',
    ][$status] ?? $status;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_take(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function auth_attempt_key(string $scope, string $identifier): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    return hash('sha256', $scope . '|' . strtolower($identifier) . '|' . $ip);
}

function auth_is_locked(mysqli $conn, string $key): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT locked_until FROM login_attempts WHERE attempt_key = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row && $row['locked_until'] !== null && strtotime($row['locked_until']) > time();
}

function auth_record_failure(mysqli $conn, string $key): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (attempt_key, failure_count, first_failed_at, last_failed_at, locked_until)
        VALUES (?, 1, NOW(), NOW(), NULL)
        ON DUPLICATE KEY UPDATE
            locked_until = IF(
                first_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NULL,
                IF(failure_count + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL)
            ),
            failure_count = IF(first_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failure_count + 1),
            first_failed_at = IF(first_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), first_failed_at),
            last_failed_at = NOW()");
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function auth_clear_failures(mysqli $conn, string $key): void
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM login_attempts WHERE attempt_key = ?');
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
