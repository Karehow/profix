<?php
require_once __DIR__ . '/app.php';

class PinResetException extends RuntimeException {}

function pin_reset_self_service(mysqli $conn, string $phone, string $first, string $last, string $pin, string $confirm): void
{
    $phone = normalize_phone($phone);
    $first = trim($first);
    $last = trim($last);
    $attemptKey = auth_attempt_key('pin-reset-self', 'all');
    if (auth_is_locked($conn, $attemptKey)) throw new PinResetException('ลองไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่');
    if (!valid_pin($pin) || $pin !== $confirm) throw new PinResetException('รหัสผ่านต้องเป็นตัวเลข 6 หลักและกรอกตรงกัน');
    $identityFailed = false;
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT user_id, first_name, last_name FROM users WHERE normalized_phone = ? AND role = 'user' AND is_active = 1 FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 's', $phone);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!valid_thai_phone($phone) || $first === '' || $last === '' || !$user || !hash_equals(trim($user['first_name']), $first) || !hash_equals(trim($user['last_name']), $last)) {
            $identityFailed = true;
            throw new PinResetException('ข้อมูลไม่ตรงกับบัญชี กรุณาตรวจสอบเบอร์โทรศัพท์ ชื่อ และนามสกุล');
        }
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $userId = (int) $user['user_id'];
        $stmt = mysqli_prepare($conn, 'UPDATE users SET borrower_pin_hash = ?, pin_reset_hash = NULL, pin_reset_expires_at = NULL, auth_version = auth_version + 1 WHERE user_id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        write_audit_log($conn, $userId, 'reset_borrower_pin', 'ตั้งรหัสผ่านใหม่ด้วยข้อมูลบัญชีผู้ยืม');
        auth_clear_failures($conn, auth_attempt_key('borrower-login', $phone));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($identityFailed) auth_record_failure($conn, $attemptKey);
        throw $e;
    }
}

function pin_reset_issue(mysqli $conn, int $actorId, int $userId): string
{
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ? AND role IN ('staff','admin') AND is_active = 1 FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $actorId);
        mysqli_stmt_execute($stmt);
        $actor = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$actor) throw new PinResetException('ไม่มีสิทธิ์ออกรหัสรีเซ็ต');
        $code = strtoupper(bin2hex(random_bytes(8)));
        $hash = hash('sha256', $code);
        $stmt = mysqli_prepare($conn, "UPDATE users SET pin_reset_hash = ?, pin_reset_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
            WHERE user_id = ? AND role = 'user' AND is_active = 1");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
        mysqli_stmt_execute($stmt);
        $updated = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($updated !== 1) throw new PinResetException('ไม่พบบัญชีผู้ยืมที่เปิดใช้งาน');
        write_audit_log($conn, $actorId, 'issue_pin_reset', 'ยืนยันตัวตนและออกรหัสรีเซ็ตให้ผู้ยืม #' . $userId);
        mysqli_commit($conn);
        return $code;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function pin_reset_complete(mysqli $conn, string $phone, string $code, string $pin, string $confirm): void
{
    $phone = normalize_phone($phone);
    $code = strtoupper(trim($code));
    $attemptKey = auth_attempt_key('pin-reset', 'all');
    if (auth_is_locked($conn, $attemptKey)) throw new PinResetException('ลองไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่');
    if (!valid_thai_phone($phone) || !preg_match('/^[A-F0-9]{16}$/', $code)) {
        auth_record_failure($conn, $attemptKey);
        throw new PinResetException('เบอร์โทรศัพท์หรือรหัสรีเซ็ตไม่ถูกต้อง หรือรหัสหมดอายุแล้ว');
    }
    if (!valid_pin($pin) || $pin !== $confirm) throw new PinResetException('รหัสผ่าน ต้องเป็นตัวเลข 6 หลักและกรอกตรงกัน');
    $identityFailed = false;
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT user_id, pin_reset_hash, (pin_reset_expires_at > NOW()) AS valid_reset FROM users
            WHERE normalized_phone = ? AND role = 'user' AND is_active = 1 FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 's', $phone);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$user || !$user['valid_reset'] || !$user['pin_reset_hash'] || !hash_equals($user['pin_reset_hash'], hash('sha256', $code))) {
            $identityFailed = true;
            throw new PinResetException('เบอร์โทรศัพท์หรือรหัสรีเซ็ตไม่ถูกต้อง หรือรหัสหมดอายุแล้ว');
        }
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $userId = (int) $user['user_id'];
        $stmt = mysqli_prepare($conn, 'UPDATE users SET borrower_pin_hash = ?, pin_reset_hash = NULL, pin_reset_expires_at = NULL, auth_version = auth_version + 1 WHERE user_id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        write_audit_log($conn, $userId, 'reset_borrower_pin', 'ตั้ง รหัสผ่าน ใหม่ด้วยรหัสรีเซ็ต');
        auth_clear_failures($conn, $attemptKey);
        auth_clear_failures($conn, auth_attempt_key('borrower-login', $phone));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($identityFailed) auth_record_failure($conn, $attemptKey);
        throw $e;
    }
}
