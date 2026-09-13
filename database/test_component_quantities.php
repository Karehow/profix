<?php
// Integration test. Creates and removes only a NEW profix_test_* database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/', $testDb)) {
    fwrite(STDERR, "Set PROFIX_DB_NAME to a new profix_test_* database.\n"); exit(1);
}
if (($argv[1] ?? '') === '--page') {
    session_save_path(sys_get_temp_dir());
    session_start();
    require_once __DIR__ . '/../config/borrow_service.php';
    $input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $_SESSION = ['user_id' => $input['user'], 'csrf_token' => 'test-token'];
    $_SESSION['cart'] = $input['cart'] ?? [];
    $_GET = $input['get'] ?? [];
    $_POST = $input['post'] ?? [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    if ($_POST) {
        $_POST['csrf_token'] = 'test-token';
        register_shutdown_function(static function () { echo json_encode($_SESSION, JSON_UNESCAPED_UNICODE); });
    }
    $pages = ['return' => 'staff/return_check.php', 'borrow' => 'user/borrow.php',
        'confirm' => 'user/confirm.php', 'history' => 'user/history.php', 'borrower_return' => 'user/return.php', 'requests' => 'staff/requests.php', 'compensation' => 'staff/compensation.php', 'stock' => 'staff/stock_discrepancies.php'];
    require __DIR__ . '/../' . $pages[$input['page']];
    exit;
}
function check_quantity(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject_quantity(callable $action): void {
    try { $action(); } catch (BorrowWorkflowException | InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected quantity rejection');
}
function quantity_page(array $input): string {
    $process = proc_open([PHP_BINARY, __FILE__, '--page', json_encode($input)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check_quantity(proc_close($process) === 0 && $errors === '', 'Page error: ' . $errors);
    return $output;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    mysqli_select_db($setup, $testDb);
    $sql = file_get_contents(__DIR__ . '/profix.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', $sql);
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); }
    while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/borrow_service.php';
    require_once __DIR__ . '/../config/schema.php';
    require_once __DIR__ . '/../config/set_availability.php';
    migrate_borrow_allocation($conn);
    migrate_borrow_allocation($conn);
    mysqli_query($conn, 'ALTER TABLE borrow_item_components DROP COLUMN quantity_borrowed');
    migrate_component_quantities($conn);
    migrate_component_quantities($conn);
    mysqli_query($conn, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,role) VALUES ('Staff','Test','0800000001','0800000001','staff'),('User','Test','0800000002','0800000002','user'),('User2','Test','0800000003','0800000003','user')");
    mysqli_query($conn, "INSERT INTO items (item_name,total_quantity,available_quantity,is_set) VALUES ('ชุดพาน',10,10,1)");
    mysqli_query($conn, "INSERT INTO item_components (parent_item_id,component_name,quantity_per_set,unit) VALUES (1,'พานทอง',1,'ใบ'),(1,'พานเงิน',1,'ใบ')");
    mysqli_query($conn, "INSERT INTO items (item_name,total_quantity,available_quantity,is_set) VALUES ('กระถางธูป',5,5,0)");
    $directCart = [2 => ['qty' => 2, 'excluded_components' => []]];
    foreach ([-1, 6, '1.5', 'abc', '', []] as $invalidQuantity) {
        $state = json_decode(quantity_page(['page'=>'borrow','user'=>2,'cart'=>$directCart,
            'post'=>['action'=>'update_quantity','item_id'=>2,'quantity'=>$invalidQuantity]]), true);
        check_quantity($state['cart'][2]['qty'] === 2 && !empty($state['borrow_error']), 'Invalid direct quantity changed cart');
    }
    foreach ([5, 1, 0] as $directQuantity) {
        $state = json_decode(quantity_page(['page'=>'borrow','user'=>2,'cart'=>$directCart,
            'post'=>['action'=>'update_quantity','item_id'=>2,'quantity'=>$directQuantity]]), true);
        check_quantity($directQuantity === 0 ? !isset($state['cart'][2]) : $state['cart'][2]['qty'] === $directQuantity, 'Direct quantity not saved');
    }
    $directHtml = quantity_page(['page'=>'borrow','user'=>2,'cart'=>$directCart]);
    check_quantity((bool)preg_match('/name="quantity"[^>]*max="5"[^>]*value="2"/', $directHtml), 'Editable quantity missing stock limit or cart value');
    $pickup = date('Y-m-d') . ' 08:00:00';
    $due = date('Y-m-d', strtotime('+1 day')) . ' 23:59:59';
    $stock = static fn() => (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT available_quantity FROM items WHERE item_id=1'))[0];
    $batches = static fn() => (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM return_batches'))[0];
    $submit = static fn($counts, $user = 2) => borrow_submit_cart($conn, $user,
        [1 => ['qty' => 1, 'component_quantities' => $counts]], $pickup, $due);
    foreach ([[1 => 0, 2 => 0], [1 => -1], [1 => '1.5'], [1 => '3oops'], [1 => []], [999 => 1], [1 => 11]] as $invalid) {
        reject_quantity(fn() => $submit($invalid));
    }
    mysqli_query($conn, 'UPDATE item_components SET is_available=0 WHERE component_id=2');
    reject_quantity(fn() => $submit([1 => 3, 2 => 1]));
    mysqli_query($conn, 'UPDATE item_components SET is_available=1');
    check_quantity((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM borrow_requests'))[0] === 0, 'Invalid submissions left requests');

    $cartResult = json_decode(quantity_page(['page' => 'borrow', 'user' => 2, 'post' => [
        'action' => 'update_set', 'item_id' => 1, 'borrow_mode' => 'components', 'component_quantities' => [1 => '3', 2 => '1']]]), true);
    $cart = $cartResult['cart'];
    check_quantity($cart[1]['qty'] === 3 && $cart[1]['component_quantities'][1] === 3, 'Cart did not retain exact counts');
    $borrowHtml = quantity_page(['page' => 'borrow', 'user' => 2, 'cart' => $cart]);
    check_quantity((bool) preg_match('/name="component_quantities\[1\]"[^>]*value="3"/', $borrowHtml), 'Borrow form lost saved quantity');
    $confirmHtml = quantity_page(['page' => 'confirm', 'user' => 2, 'cart' => $cart]);
    check_quantity(str_contains($confirmHtml, '4 ชิ้น') && str_contains($confirmHtml, 'ของย่อยตามจำนวนที่เลือก'), 'Confirmation totals incorrect');
    $removed = json_decode(quantity_page(['page' => 'borrow', 'user' => 2, 'cart' => $cart,
        'post' => ['action' => 'minus', 'item_id' => 1]]), true);
    check_quantity(empty($removed['cart']), 'Remove left inconsistent set quantity');

    $request = borrow_submit_cart($conn, 2, $cart, $pickup, $due);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_items WHERE request_id=$request"));
    $borrowItem = (int) $row['borrow_item_id'];
    check_quantity((int) $row['quantity_borrowed'] === 3 && $stock() === 10, 'Pending request allocation incorrect');
    $snapshots = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM borrow_item_components WHERE borrow_item_id=$borrowItem ORDER BY source_component_id"), MYSQLI_ASSOC);
    check_quantity(component_borrowed_quantity($snapshots[0], 3) === 3 && component_borrowed_quantity($snapshots[1], 3) === 1, 'Snapshot counts incorrect');
    $silverId = (int) $snapshots[1]['borrow_item_component_id'];
    // A second pending request cannot overbook after the first request is approved.
    $competing = $submit([1 => 8], 3);
    borrow_approve($conn, $request, 1);
    check_quantity($stock() === 7, 'Approval did not hold three sets');
    reject_quantity(fn() => borrow_approve($conn, $competing, 1));
    check_quantity($stock() === 7, 'Failed approval changed stock');
    borrow_cancel($conn, $competing, 3);
    borrow_handover($conn, $request, 1);
    $borrowerReturn = quantity_page(['page'=>'borrower_return','user'=>2]);
    check_quantity(str_contains($borrowerReturn, 'borrow-return.css') && str_contains($borrowerReturn, 'name="request_return"') && str_contains($borrowerReturn, '4 ชิ้น (ของย่อย)'), 'Borrower return page or actual component count failed');
    borrow_request_return($conn, $request, 2);
    $borrowerReturn = quantity_page(['page'=>'borrower_return','user'=>2]);
    check_quantity(str_contains($borrowerReturn, 'รอตรวจรับคืน') && !str_contains($borrowerReturn, 'name="request_return"'), 'Pending return still offers duplicate action');
    $otherReturn = quantity_page(['page'=>'borrower_return','user'=>3]);
    check_quantity(!str_contains($otherReturn, 'คำขอยืม #' . $request), 'Return page exposed another user request');
    $history = quantity_page(['page' => 'history', 'user' => 2]);
    check_quantity(str_contains($history, 'พานทอง 3 ใบ') && str_contains($history, 'พานเงิน 1 ใบ'), 'History multiplied actual counts');
    $matchingHistory = quantity_page(['page'=>'history', 'user'=>2, 'get'=>['q'=>'#' . $request, 'status'=>'return_requested', 'from'=>date('Y-m-d'), 'to'=>date('Y-m-d')]]);
    check_quantity(str_contains($matchingHistory, 'id="request-' . $request . '"') && str_contains($matchingHistory, 'borrow-history.css'), 'Combined history filters failed');
    foreach ([['q'=>'#' . $competing], ['status'=>'returned'], ['to'=>'2000-01-01']] as $filter) {
        $filteredHistory = quantity_page(['page'=>'history', 'user'=>2, 'get'=>$filter]);
        check_quantity(!str_contains($filteredHistory, 'class="request-card'), 'History filter or ownership failed');
    }
    $staff = quantity_page(['page' => 'requests', 'user' => 1, 'get' => ['status'=>'all']]);
    check_quantity(str_contains($staff, 'พานเงิน 1 ใบ'), 'Staff request count incorrect');
    $returnHtml = quantity_page(['page' => 'return', 'user' => 1]);
    check_quantity(str_contains($returnHtml, 'พานเงิน (ยืม 1 ใบ)'), 'Return form count incorrect');
    $returnPost = ['submit_inspection' => 1, 'request_id' => $request, 'borrow_item_id' => $borrowItem, 'returned_quantity' => 3];
    quantity_page(['page' => 'return', 'user' => 1, 'post' => array_replace($returnPost, ['returned_quantity' => 1])]);
    check_quantity($batches() === 0 && $stock() === 7, 'Partial mixed return accepted');
    $issue = ['component_id' => $silverId, 'damaged_quantity' => 2, 'lost_quantity' => 0,
        'action_required' => 'buy_replacement', 'description' => 'test damage'];
    quantity_page(['page' => 'return', 'user' => 1, 'post' => $returnPost + ['component_issues' => [$issue]]]);
    check_quantity($batches() === 0 && $stock() === 7, 'Damage above actual silver count accepted');
    quantity_page(['page' => 'return', 'user' => 1, 'post' => $returnPost]);
    check_quantity($batches() === 1 && $stock() === 10, 'Good return failed to restore stock');
    quantity_page(['page' => 'return', 'user' => 1, 'post' => $returnPost]);
    check_quantity($batches() === 1 && $stock() === 10, 'Double return added stock');

    $legacy = borrow_submit_cart($conn, 2, [1 => ['qty' => 1, 'excluded_components' => []]], $pickup, $due);
    $legacyRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT bc.* FROM borrow_item_components bc JOIN borrow_items bi ON bi.borrow_item_id=bc.borrow_item_id WHERE bi.request_id=$legacy LIMIT 1"));
    check_quantity($legacyRow['quantity_borrowed'] === null && component_borrowed_quantity($legacyRow, 1) === 1, 'Full-set compatibility broken');
    borrow_cancel($conn, $legacy, 2);
    $cancelled = $submit([1 => 3, 2 => 1]);
    borrow_approve($conn, $cancelled, 1);
    borrow_cancel($conn, $cancelled, 2);
    check_quantity($stock() === 10, 'Cancellation did not restore allocated stock');

    // No complete sets remain, but the same borrower can borrow the remaining silver pieces.
    $goldOnly = $submit([1 => 10, 2 => 0]);
    borrow_approve($conn, $goldOnly, 1);
    check_quantity($stock() === 0, 'Full-set count should be zero');
    $remaining = component_stock_rows($conn, 1);
    check_quantity((int) $remaining[0]['piece_available'] === 0 && (int) $remaining[1]['piece_available'] === 10, 'Unselected silver was held');
    // Upgrade a pre-component-stock partial loan, and ensure migration can be repeated.
    mysqli_query($conn, 'UPDATE item_components SET stock_total=NULL,stock_available=NULL');
    migrate_component_stock($conn);
    migrate_component_stock($conn);
    check_quantity((int) component_stock_rows($conn, 1)[1]['piece_available'] === 10, 'Migration lost unselected pieces');
    $remainingHtml = quantity_page(['page' => 'borrow', 'user' => 2]);
    check_quantity(str_contains($remainingHtml, 'ของไม่ครบชุด'), 'Incomplete warning missing');
    check_quantity((bool) preg_match('/id="full_1"[^>]*disabled/', $remainingHtml), 'Empty standard set still selectable');
    check_quantity((bool) preg_match('/data-bs-target="#setModal1"\s*>/', $remainingHtml), 'Remaining components inaccessible');
    $silverOnly = $submit([2 => 4]);
    borrow_approve($conn, $silverOnly, 1);
    check_quantity($stock() === 0 && (int) component_stock_rows($conn, 1)[1]['piece_available'] === 6, 'Second partial loan failed');
    reject_quantity(fn() => $submit([2 => 7], 3));
    borrow_cancel($conn, $goldOnly, 2);
    check_quantity($stock() === 6, 'Cancelling one loan released another loan stock');
    borrow_cancel($conn, $silverOnly, 2);
    check_quantity($stock() === 10, 'Piece stock not restored');

    // Multiple standard sets retain per-set counts and support partial returns.
    $standardCart = json_decode(quantity_page(['page' => 'borrow', 'user' => 2, 'post' => [
        'action' => 'update_set', 'item_id' => 1, 'borrow_mode' => 'full', 'set_quantity' => 4]]), true)['cart'];
    check_quantity($standardCart[1]['qty'] === 4, 'Multi-set cart quantity lost');
    $standard = borrow_submit_cart($conn, 2, $standardCart, $pickup, $due);
    borrow_approve($conn, $standard, 1);
    check_quantity($stock() === 6, 'Four standard sets not deducted');
    borrow_handover($conn, $standard, 1);
    borrow_request_return($conn, $standard, 2);
    $standardItem = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_id FROM borrow_items WHERE request_id=$standard"))[0];
    $standardReturn = ['submit_inspection' => 1, 'request_id' => $standard, 'borrow_item_id' => $standardItem, 'returned_quantity' => 2];
    quantity_page(['page' => 'return', 'user' => 1, 'post' => $standardReturn]);
    check_quantity($stock() === 8, 'Partial standard return incorrect');
    quantity_page(['page' => 'return', 'user' => 1, 'post' => $standardReturn]);
    check_quantity($stock() === 10, 'Final standard return incorrect');

    // Good pieces return immediately; replacing or retiring a broken piece affects only that component.
    foreach (['buy_replacement'] as $method) {
        $damagedRequest = $submit([1 => 3, 2 => 1]);
        borrow_approve($conn, $damagedRequest, 1);
        borrow_handover($conn, $damagedRequest, 1);
        borrow_request_return($conn, $damagedRequest, 2);
        $damagedItem = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_id FROM borrow_items WHERE request_id=$damagedRequest"))[0];
        $goldSnapshot = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_component_id FROM borrow_item_components WHERE borrow_item_id=$damagedItem AND source_component_id=1"))[0];
        quantity_page(['page' => 'return', 'user' => 1, 'post' => [
            'submit_inspection' => 1, 'request_id' => $damagedRequest, 'borrow_item_id' => $damagedItem, 'returned_quantity' => 3,
            'component_issues' => [['component_id' => $goldSnapshot, 'damaged_quantity' => 1, 'lost_quantity' => 0,
                'action_required' => $method, 'fine_amount' => 0, 'description' => 'Broken gold tray']]]]);
        $pieces = component_stock_rows($conn, 1);
        check_quantity((int) $pieces[0]['piece_available'] === 9 && (int) $pieces[1]['piece_available'] === 10, 'Good pieces held by damaged component');
        check_quantity(isset(incomplete_set_ids($conn)[1]), 'Damage not marked incomplete');
        $inspection = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT inspection_id FROM return_inspections WHERE borrow_item_id=$damagedItem AND damaged_quantity=1"))[0];
        quantity_page(['page' => 'compensation', 'user' => 1, 'post' => ['save_compensation' => 1, 'inspection_id' => $inspection, 'amount' => 0]]);
        $pieces = component_stock_rows($conn, 1);
        check_quantity((int) $pieces[0]['piece_available'] === ($method === 'buy_replacement' ? 10 : 9), 'Compensation stock incorrect');
        check_quantity((int) $pieces[1]['piece_total'] === 10 && (int) $pieces[1]['piece_available'] === 10, 'Compensation changed undamaged silver');
    }
    // Asked 100, found only 80 before handover: the borrower owes 80, warehouse owns the discrepancy.
    mysqli_query($conn, "INSERT INTO items (item_name,total_quantity,available_quantity,is_set) VALUES ('เก้าอี้ทดสอบจัดส่ง',100,100,0)");
    $chairId = mysqli_insert_id($conn);
    $chairStock = static fn() => (int) mysqli_fetch_row(mysqli_query($conn, "SELECT available_quantity FROM items WHERE item_id=$chairId"))[0];
    $chairRequest = borrow_submit_cart($conn, 2, [$chairId=>['qty'=>100]], $pickup, $due);
    $chairItem = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_id FROM borrow_items WHERE request_id=$chairRequest"))[0];
    borrow_approve($conn, $chairRequest, 1);
    reject_quantity(fn() => borrow_revise_allocation($conn, $chairRequest, 1, [$chairItem=>80], '', true, 0));
    reject_quantity(fn() => borrow_revise_allocation($conn, $chairRequest, 1, [$chairItem=>'80.5'], 'short', true, 0));
    borrow_revise_allocation($conn, $chairRequest, 1, [$chairItem=>80], 'ตรวจนับแล้วพบเพียง 80', true, 0);
    check_quantity($chairStock()===0, 'Missing warehouse stock became lendable');
    $staffAllocation = quantity_page(['page'=>'requests','user'=>1,'get'=>['status'=>'approved']]);
    check_quantity(str_contains($staffAllocation, 'name="quantities[' . $chairItem . ']"') && str_contains($staffAllocation, 'รอผู้ยืมยอมรับจำนวนใหม่'), 'Staff allocation controls missing');
    check_quantity(str_contains(quantity_page(['page'=>'stock','user'=>1]), '20 ชิ้น'), 'Warehouse review missing quantity');
    check_quantity(!str_contains(quantity_page(['page'=>'stock','user'=>2]), 'เก้าอี้ทดสอบจัดส่ง'), 'Borrower can view staff warehouse page');
    $allocation = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_items WHERE borrow_item_id=$chairItem"));
    check_quantity((int)$allocation['quantity_requested']===100 && (int)$allocation['quantity_borrowed']===80 && $allocation['quantity_handed_over']===null, 'Requested / allocated / handed over not separated');
    reject_quantity(fn() => borrow_handover($conn, $chairRequest, 1));
    reject_quantity(fn() => borrow_accept_allocation($conn, $chairRequest, 3, 1));
    reject_quantity(fn() => borrow_accept_allocation($conn, $chairRequest, 2, 0));
    $acceptHtml = quantity_page(['page'=>'history','user'=>2]);
    check_quantity(str_contains($acceptHtml, 'ยอมรับจำนวนที่จัดให้') && str_contains($acceptHtml,'100</strong>'), 'Borrower cannot review original quantity');
    quantity_page(['page'=>'history','user'=>2,'post'=>['action'=>'accept_allocation','request_id'=>$chairRequest,'allocation_version'=>1]]);
    reject_quantity(fn() => borrow_handover($conn, $chairRequest, 1, 0));
    quantity_page(['page'=>'requests','user'=>1,'post'=>['action_type'=>'handover','request_id'=>$chairRequest,'allocation_version'=>1,'count_confirmed'=>1]]);
    check_quantity((int)mysqli_fetch_row(mysqli_query($conn,"SELECT quantity_handed_over FROM borrow_items WHERE borrow_item_id=$chairItem"))[0]===80,'Actual handover amount incorrect');
    reject_quantity(fn() => borrow_revise_allocation($conn, $chairRequest, 1, [$chairItem=>70], 'late', false, 1));
    borrow_request_return($conn, $chairRequest, 2);
    $chairReturn = ['submit_inspection'=>1,'request_id'=>$chairRequest,'borrow_item_id'=>$chairItem,'returned_quantity'=>70];
    quantity_page(['page'=>'return','user'=>1,'post'=>$chairReturn]);
    check_quantity($chairStock()===70, 'Partial return stock incorrect');
    check_quantity(mysqli_fetch_row(mysqli_query($conn,"SELECT status FROM borrow_requests WHERE request_id=$chairRequest"))[0]==='return_requested', 'Partial return closed the loan');
    check_quantity((int)mysqli_fetch_row(mysqli_query($conn,"SELECT SUM(lost_quantity) FROM return_inspections WHERE borrow_item_id=$chairItem"))[0]===0,'Outstanding items falsely marked lost');
    check_quantity(str_contains(quantity_page(['page'=>'borrower_return','user'=>2]), 'ค้างคืน 10'), 'Remaining quantity missing from borrower page');
    $chairReturn['returned_quantity']=10;
    quantity_page(['page'=>'return','user'=>1,'post'=>$chairReturn]);
    check_quantity($chairStock()===80, 'Final return restored missing warehouse items');
    quantity_page(['page'=>'return','user'=>1,'post'=>$chairReturn]);
    check_quantity($chairStock()===80, 'Duplicate completed return changed stock');
    $discrepancy = (int) mysqli_fetch_row(mysqli_query($conn,"SELECT discrepancy_id FROM inventory_discrepancies WHERE request_id=$chairRequest"))[0];
    resolve_inventory_discrepancy($conn, $discrepancy, 1, 'found', 'พบครบ 20 ชิ้นในห้องเก็บของ');
    check_quantity($chairStock()===100,'Finding warehouse stock failed');
    reject_quantity(fn() => resolve_inventory_discrepancy($conn, $discrepancy, 1, 'found', 'duplicate'));

    // Reduced approval, stale acceptance, cancellation and write-off do not charge the borrower.
    $chairRequest = borrow_submit_cart($conn, 2, [$chairId=>['qty'=>100]], $pickup, $due);
    $chairItem = (int) mysqli_fetch_row(mysqli_query($conn,"SELECT borrow_item_id FROM borrow_items WHERE request_id=$chairRequest"))[0];
    borrow_approve($conn, $chairRequest, 1, 'จัดให้ 90 พบของไม่ครบ', [$chairItem=>90], true);
    borrow_accept_allocation($conn, $chairRequest, 2, 1);
    borrow_revise_allocation($conn, $chairRequest, 1, [$chairItem=>80], 'ผู้ยืมต้องการลดอีก', false, 1);
    reject_quantity(fn() => borrow_handover($conn, $chairRequest, 1));
    reject_quantity(fn() => borrow_accept_allocation($conn, $chairRequest, 2, 1));
    borrow_cancel($conn, $chairRequest, 2);
    check_quantity($chairStock()===90,'Cancellation released physically missing stock');
    $discrepancy = (int)mysqli_fetch_row(mysqli_query($conn,"SELECT discrepancy_id FROM inventory_discrepancies WHERE request_id=$chairRequest"))[0];
    resolve_inventory_discrepancy($conn, $discrepancy, 1, 'written_off', 'ยืนยันว่าหายในคลัง');
    check_quantity($chairStock()===90 && (int)mysqli_fetch_row(mysqli_query($conn,"SELECT total_quantity FROM items WHERE item_id=$chairId"))[0]===90,'Warehouse write-off incorrect');
    migrate_borrow_allocation($conn);
    check_quantity((int)mysqli_fetch_row(mysqli_query($conn,"SELECT quantity_requested FROM borrow_items WHERE borrow_item_id=$chairItem"))[0]===100,'Repeat migration overwrote requested quantity');
    echo "PASS: component workflow, allocations 100/80, acceptance, stale forms, actual handover, partial returns 70/10, warehouse discrepancy, cancellation, reconciliation and migrations.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
