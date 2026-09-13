<?php
// Set PROFIX_DB_NAME=profix_test_sets; creates and removes only that NEW test database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/', $testDb)) {
    fwrite(STDERR, "Set PROFIX_DB_NAME to a new profix_test_* database.\n"); exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $action): void {
    try { $action(); } catch (BorrowWorkflowException $e) { return; }
    throw new RuntimeException('Expected unavailable component rejection');
}
try {
    mysqli_select_db($setup, $testDb);
    $sql = file_get_contents(__DIR__ . '/profix.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', $sql);
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); }
    while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/borrow_service.php';
    require_once __DIR__ . '/../config/set_availability.php';
    require_once __DIR__ . '/../config/schema.php';
    mysqli_query($conn, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,role) VALUES ('Staff','Test','0800000001','0800000001','staff'),('User','Test','0800000002','0800000002','user')");
    mysqli_query($conn, "INSERT INTO items (item_name,total_quantity,available_quantity,is_set) VALUES ('Set',1,1,1)");
    mysqli_query($conn, "INSERT INTO item_components (parent_item_id,component_name,quantity_per_set) VALUES (1,'A',2),(1,'B',1)");
    mysqli_query($conn, 'ALTER TABLE item_components DROP COLUMN is_available');
    migrate_component_availability($conn);
    migrate_component_availability($conn);
    check(incomplete_set_ids($conn) === [], 'Migration changed available components');
    $pickup = date('Y-m-d') . ' 08:00:00';
    $returned = date('Y-m-d', strtotime('+1 day')) . ' 23:59:59';
    mysqli_query($conn, 'UPDATE item_components SET is_available = 0 WHERE component_id = 2');
    check(isset(incomplete_set_ids($conn)[1]), 'Manual empty flag not detected');
    rejects(fn() => borrow_submit_cart($conn, 2, [1 => ['qty' => 1, 'excluded_components' => []]], $pickup, $returned));
    check((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM borrow_requests'))[0] === 0, 'Failed full loan not rolled back');
    $partial = borrow_submit_cart($conn, 2, [1 => ['qty' => 1, 'excluded_components' => [2]]], $pickup, $returned);
    mysqli_query($conn, 'UPDATE item_components SET is_available = 0 WHERE component_id = 1');
    rejects(fn() => borrow_approve($conn, $partial, 1));
    check((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT available_quantity FROM items WHERE item_id = 1'))[0] === 1, 'Failed approval changed stock');
    mysqli_query($conn, 'UPDATE item_components SET is_available = 1');
    check(incomplete_set_ids($conn) === [], 'Pending request counted as borrowed');
    borrow_approve($conn, $partial, 1);
    check(isset(incomplete_set_ids($conn)[1]), 'Approved partial loan not detected');
    inventory_adjust($conn, 1, 1, 1, 'manual_adjustment', 1);
    check(component_stock_summary(component_stock_rows($conn, 1))['full_sets'] === 1, 'Spare full set unavailable');
    check(isset(incomplete_set_ids($conn)[1]), 'Leftover pieces should remain marked incomplete beside spare full set');
    inventory_adjust($conn, 1, -1, -1, 'manual_adjustment', 1);
    borrow_cancel($conn, $partial, 2);
    check(incomplete_set_ids($conn) === [], 'Cancelled partial loan still incomplete');
    $full = borrow_submit_cart($conn, 2, [1 => ['qty' => 1, 'excluded_components' => []]], $pickup, $returned);
    borrow_approve($conn, $full, 1);
    check(incomplete_set_ids($conn) === [], 'Full loan confused with partial loan');
    mysqli_query($conn, 'UPDATE item_components SET is_available = 0 WHERE component_id = 1');
    rejects(fn() => borrow_handover($conn, $full, 1));
    mysqli_query($conn, 'UPDATE item_components SET is_available = 1');
    borrow_handover($conn, $full, 1);
    $borrowItem = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_id FROM borrow_items WHERE request_id = $full"))[0];
    mysqli_query($conn, "UPDATE borrow_items SET quantity_returned = quantity_borrowed WHERE borrow_item_id = $borrowItem");
    mysqli_query($conn, "UPDATE borrow_requests SET status = 'partially_damaged' WHERE request_id = $full");
    mysqli_query($conn, "INSERT INTO return_inspections (borrow_item_id,component_id,damaged_quantity,action_required) VALUES ($borrowItem,1,1,'buy_replacement')");
    mysqli_query($conn, 'UPDATE item_components SET stock_available = 1 WHERE component_id IN (1,2)');
    check(isset(incomplete_set_ids($conn)[1]), 'Unresolved component damage not detected');
    mysqli_query($conn, 'UPDATE return_inspections SET is_resolved = 1');
    mysqli_query($conn, 'UPDATE items SET available_quantity = 1 WHERE item_id = 1');
    mysqli_query($conn, 'UPDATE item_components SET stock_available = stock_total');
    check(incomplete_set_ids($conn) === [], 'Restored set still incomplete');
    mysqli_query($conn, 'UPDATE item_components SET is_available = 0 WHERE component_id = 2');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SESSION = ['user_id' => 2, 'role' => 'user'];
    ob_start(); require __DIR__ . '/../user/borrow.php'; $borrowHtml = ob_get_clean();
    check(str_contains($borrowHtml, 'ของไม่ครบชุด') && str_contains($borrowHtml, 'data-unavailable="1"'), 'Borrow page missing component warning');
    check((bool) preg_match('/id="full_1"[^>]*disabled/', $borrowHtml), 'Full-set choice not disabled');
    check((bool) preg_match('/id="comp_2"[^>]*disabled/', $borrowHtml), 'Empty component choice not disabled');
    $_SESSION = ['user_id' => 1, 'role' => 'staff'];
    ob_start(); require __DIR__ . '/../staff/items.php'; $staffHtml = ob_get_clean();
    check(str_contains($staffHtml, 'ของในชุดไม่ครบ') && str_contains($staffHtml, 'name="component_unavailable" value="1" checked'), 'Staff availability control missing');
    echo "PASS: migration, manual availability, partial/full loans, validation, rollback, approval, cancellation, spare sets, handover, damaged components and restoration.\n";
} finally { mysqli_query($setup, "DROP DATABASE `$testDb`"); }
