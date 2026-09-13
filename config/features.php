<?php

/**
 * Shared, non-schema feature helpers.
 * Schema changes live in database/migrate.php and are never performed by a web request.
 */

function ensure_feature_tables($conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $required = [
        'notifications',
        'audit_logs',
        'reservations',
        'borrow_item_exclusions',
        'borrow_item_components',
        'return_batches',
        'inventory_movements',
        'inventory_discrepancies',
        'borrow_request_events',
        'login_attempts',
    ];
    foreach ($required as $table) {
        $escaped = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '$escaped'");
        if (!$result || mysqli_num_rows($result) !== 1) {
            http_response_code(503);
            exit('ระบบฐานข้อมูลยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php database/migrate.php');
        }
    }

    $requiredColumns = [
        'users' => ['normalized_phone', 'borrower_pin_hash', 'is_active', 'pin_reset_hash', 'pin_reset_expires_at', 'auth_version'],
        'items' => ['is_active'],
        'item_components' => ['is_available', 'stock_total', 'stock_available'],
        'borrow_item_components' => ['quantity_borrowed'],
        'borrow_items' => ['quantity_requested', 'quantity_handed_over'],
        'borrow_requests' => ['requested_pickup_at', 'approved_at', 'handed_over_at', 'settlement_status', 'return_requested_at', 'allocation_version', 'accepted_allocation_version', 'allocation_accepted_at'],
        'return_inspections' => ['return_batch_id', 'borrow_item_component_id', 'replacement_image_url', 'replacement_note', 'replacement_submitted_at'],
        'damage_compensations' => ['quantity_settled'],
    ];
    $columnStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            mysqli_stmt_bind_param($columnStmt, 'ss', $table, $column);
            mysqli_stmt_execute($columnStmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($columnStmt));
            if ((int) ($row['total'] ?? 0) !== 1) {
                mysqli_stmt_close($columnStmt);
                http_response_code(503);
                exit('ระบบฐานข้อมูลยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php database/migrate.php');
            }
        }
    }
    mysqli_stmt_close($columnStmt);
}

function create_notification($conn, $userId, $title, $message, $link = null): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare notification.');
    }
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $title, $message, $link);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not create notification: ' . $error);
    }
    mysqli_stmt_close($stmt);
}

function write_audit_log($conn, $userId, $action, $detail = ''): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO audit_logs (user_id, action, detail) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare audit log.');
    }
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $action, $detail);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not write audit log: ' . $error);
    }
    mysqli_stmt_close($stmt);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    $postedValue = $_POST['csrf_token'] ?? '';
    $postedToken = is_string($postedValue) ? $postedValue : '';
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(419);
        exit('คำขอหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง');
    }
}
