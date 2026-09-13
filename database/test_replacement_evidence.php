<?php
// Runs against a newly created profix_test_* database; never through Apache.
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || !preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) {
    http_response_code(404); exit;
}
if (PHP_SAPI === 'cli-server') {
    $secret = getenv('PROFIX_REPLACEMENT_TEST_TOKEN') ?: '';
    if (!$secret || !hash_equals($secret, $_SERVER['HTTP_X_TEST_TOKEN'] ?? '')) { http_response_code(403); exit; }
    require_once __DIR__ . '/../config/borrow_service.php';
    $_SESSION = ['user_id' => (int) ($_GET['actor'] ?? 2), 'csrf_token' => 'test-token'];
    $pages = ['user' => 'user/compensation.php', 'staff' => 'staff/compensation.php', 'return' => 'staff/return_check.php', 'confirm' => 'user/replacement_confirm.php'];
    require __DIR__ . '/../' . ($pages[$_GET['page'] ?? 'user'] ?? $pages['user']);
    exit;
}
function replacement_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$secret = bin2hex(random_bytes(20));
putenv('PROFIX_REPLACEMENT_TEST_TOKEN=' . $secret);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server = null;
$temporaryFiles = [];
$sessionDirectory = sys_get_temp_dir() . '/profix-replacement-sessions-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory);
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__ . '/profix.sql'));
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/borrow_service.php';
    require_once __DIR__ . '/../config/schema.php';
    require_once __DIR__ . '/../config/notification_inbox.php';
    // Verify upgrade from the previous schema and repeatability.
    mysqli_query($conn, 'ALTER TABLE return_inspections DROP COLUMN replacement_image_url, DROP COLUMN replacement_note, DROP COLUMN replacement_submitted_at');
    migrate_replacement_evidence($conn);
    migrate_replacement_evidence($conn);
    mysqli_query($conn, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,role) VALUES ('Staff','Test','0800000001','0800000001','staff'),('User','Test','0800000002','0800000002','user'),('Other','Test','0800000003','0800000003','user')");
    mysqli_query($conn, "INSERT INTO items (item_name,total_quantity,available_quantity) VALUES ('Replacement test item',10,10)");
    $request = borrow_submit_cart($conn, 2, [1 => ['qty' => 2]], date('Y-m-d') . ' 08:00:00', date('Y-m-d', strtotime('+1 day')) . ' 23:59:59');
    borrow_approve($conn, $request, 1);
    borrow_handover($conn, $request, 1);
    borrow_request_return($conn, $request, 2);
    $borrowItem = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT borrow_item_id FROM borrow_items WHERE request_id=$request"))[0];
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    replacement_check($socket !== false, 'Cannot reserve test port');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $log = tempnam(sys_get_temp_dir(), 'replacement-log-'); $temporaryFiles[] = $log;
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDirectory, '-d', 'upload_tmp_dir=' . $sessionDirectory, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=10M', '-S', $address, __FILE__], [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, dirname(__DIR__));
    replacement_check(is_resource($server), 'Cannot start test server'); fclose($pipes[0]);
    $http = static function (string $page, int $actor, ?array $post = null, string $query = '') use ($address, $secret): array {
        $curl = curl_init('http://' . $address . '/?page=' . $page . '&actor=' . $actor . $query);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['X-Test-Token: ' . $secret]]);
        if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        return [$status, (string) $body];
    };
    for ($attempt = 0; $attempt < 30; $attempt++) { if ($http('user',2)[0]) break; usleep(100000); }
    // A damaged partial return must notify before the entire request is returned.
    $returnPost = ['csrf_token'=>'test-token','submit_inspection'=>1,'request_id'=>$request,'borrow_item_id'=>$borrowItem,'returned_quantity'=>1,'damaged_quantity'=>1,'lost_quantity'=>0,'action_required'=>'buy_replacement','fine_amount'=>0,'damage_description'=>'Private damage detail'];
    foreach ([['action_required'=>'pay_fine'], ['fine_amount'=>100]] as $money) {
        $http('return', 1, array_merge($returnPost, $money));
        replacement_check((int)mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM return_inspections'))[0] === 0, 'Monetary inspection accepted');
    }
    $http('return', 1, $returnPost);
    $issue = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM return_inspections LIMIT 1'));
    replacement_check($issue !== null, 'Return inspection failed: ' . file_get_contents($log));
    $id = (int) $issue['inspection_id'];
    mysqli_query($conn, "ALTER TABLE return_inspections DROP CONSTRAINT chk_replacement_no_fine, MODIFY action_required ENUM('none','buy_replacement','pay_fine') NOT NULL DEFAULT 'none'");
    mysqli_query($conn, "UPDATE return_inspections SET action_required='pay_fine', fine_amount=100 WHERE inspection_id=$id");
    migrate_replacement_only($conn);
    migrate_replacement_only($conn);
    $converted = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM return_inspections WHERE inspection_id=$id"));
    replacement_check($converted['action_required'] === 'buy_replacement' && (float)$converted['fine_amount'] === 0.0 && !$converted['is_resolved'], 'Legacy pending conversion failed');
    try {
        mysqli_query($conn, "UPDATE return_inspections SET fine_amount=100 WHERE inspection_id=$id");
        throw new RuntimeException('Database accepted monetary inspection');
    } catch (mysqli_sql_exception $e) { /* Database rejects nonzero fines. */ }
    $link = '../user/compensation.php?request_id=' . $request;
    replacement_check(mysqli_num_rows(mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=2 AND link_url='$link'")) === 1, 'Partial return did not notify');
    replacement_check(notification_target($link) === $link, 'Deep link rejected');
    replacement_check(notification_target($link . '&redirect=https://example.com') === 'home.php#notificationInbox', 'Unexpected redirect parameter accepted');
    replacement_check(str_contains($http('user',2)[1], 'Private damage detail'), 'Owner cannot see evidence');
    replacement_check(!str_contains($http('user',3, null, '&request_id=' . $request)[1], 'Private damage detail'), 'Other user can see evidence');
    $png = tempnam(sys_get_temp_dir(), 'replacement-png-'); $temporaryFiles[] = $png;
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
    $bad = tempnam(sys_get_temp_dir(), 'replacement-bad-'); $temporaryFiles[] = $bad; file_put_contents($bad, '<?php echo "invalid";');
    $big = tempnam(sys_get_temp_dir(), 'replacement-big-'); $temporaryFiles[] = $big; file_put_contents($big, str_repeat('x', 5*1024*1024+1));
    $row = static fn() => mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM return_inspections WHERE inspection_id=$id"));
    $post = ['csrf_token'=>'test-token','inspection_id'=>$id];
    $confirmQuery = '&inspection_id=' . $id;
    $review = $http('confirm', 2, null, $confirmQuery);
    replacement_check($review[0] === 200 && str_contains($review[1], 'confirm_replacement') && !str_contains($review[1], 'type="file"'), 'Confirmation form should not request an image');
    replacement_check($http('confirm', 3, null, $confirmQuery)[0] === 404, 'Other user can view confirmation');
    replacement_check($http('confirm', 2, null, '&inspection_id=99999')[0] === 404, 'Missing confirmation is not 404');
    replacement_check($http('confirm', 2, ['csrf_token'=>'wrong','confirm_replacement'=>1], $confirmQuery)[0] === 419, 'Confirmation CSRF accepted');
    $http('confirm', 2, $post + ['replacement_image'=>new CURLFile($png,'image/png','ok.png')], $confirmQuery);
    replacement_check($row()['replacement_submitted_at'] === null, 'Submitted without confirmation checkbox');
    $http('confirm', 3, $post + ['confirm_replacement'=>1,'replacement_image'=>new CURLFile($png,'image/png','ok.png')], $confirmQuery);
    replacement_check($row()['replacement_submitted_at'] === null, 'Other user submitted confirmation');
    replacement_check($http('user', 2, ['csrf_token'=>'bad','inspection_id'=>$id])[0] === 419, 'CSRF accepted');
    foreach ([['replacement_image'=>new CURLFile($bad,'image/png','fake.png')], ['replacement_image'=>new CURLFile($big,'image/png','big.png')], ['replacement_image'=>new CURLFile($png,'image/png','ok.png'),'note'=>str_repeat('a',2001)]] as $invalid) {
        $http('user',2,array_merge($post,$invalid));
        replacement_check($row()['replacement_submitted_at'] === null, 'Invalid upload accepted');
    }
    $http('user',3,$post + ['replacement_image'=>new CURLFile($png,'image/png','ok.png')]);
    replacement_check($row()['replacement_submitted_at'] === null, 'Other user submitted evidence');
    $stock = (int) mysqli_fetch_row(mysqli_query($conn,'SELECT available_quantity FROM items WHERE item_id=1'))[0];
    $withoutImage = in_array('--without-image', $argv, true);
    $submission = ['confirm_replacement'=>1, 'note'=>'<script>alert(1)</script>'];
    if (!$withoutImage) $submission['replacement_image'] = new CURLFile($png,'image/png','ok.png');
    $http('confirm',2,$post + $submission, $confirmQuery);
    $pending = $row();
    replacement_check($pending['replacement_submitted_at'] !== null && $pending['action_required'] === 'buy_replacement' && (int)$pending['is_resolved'] === 0, 'Submission did not enter pending replacement state');
    replacement_check($withoutImage ? $pending['replacement_image_url'] === null : is_file(__DIR__ . '/' . $pending['replacement_image_url']), 'Incorrect optional image state');
    if ($withoutImage) {
        replacement_check(!str_contains($http('user',2)[1], 'src=' . chr(34) . chr(34)), 'Empty user image');
        replacement_check(!str_contains($http('staff',1)[1], 'src=' . chr(34) . chr(34)), 'Empty staff image');
    }
    $waiting = $http('confirm',2,null,$confirmQuery)[1];
    replacement_check(str_contains($waiting, 'รอเจ้าหน้าที่ยืนยันรับของจริง') && !str_contains($waiting, 'name="confirm_replacement"'), 'Pending confirmation state incorrect');
    replacement_check((int)mysqli_fetch_row(mysqli_query($conn,'SELECT available_quantity FROM items WHERE item_id=1'))[0] === $stock, 'Submission changed stock');
    replacement_check(mysqli_num_rows(mysqli_query($conn,"SELECT * FROM notifications WHERE user_id=1 AND title='รอยืนยันรับของทดแทน'")) === 1, 'Staff not notified');
    $html = $http('staff',1)[1];
    replacement_check(str_contains($html,'received_replacement') && str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)'), 'Staff proof/confirmation/escaping missing');
    $http('user',2,$post + ['replacement_image'=>new CURLFile($png,'image/png','again.png')]);
    replacement_check($row()['replacement_image_url'] === $pending['replacement_image_url'], 'Duplicate upload replaced evidence');
    $settle = ['csrf_token'=>'test-token','save_compensation'=>1,'inspection_id'=>$id,'amount'=>0];
    foreach ([['amount'=>100], ['method'=>'payment']] as $money) {
        $http('staff', 1, array_merge($settle, ['received_replacement'=>1], $money));
        replacement_check((int)$row()['is_resolved'] === 0, 'Monetary settlement accepted');
    }
    $http('staff',1,$settle);
    replacement_check((int)$row()['is_resolved'] === 0, 'Confirmed without physical receipt');
    $http('staff',3,$settle + ['received_replacement'=>1]);
    replacement_check((int)$row()['is_resolved'] === 0, 'Borrower confirmed receipt');
    $http('staff',1,$settle + ['received_replacement'=>1]);
    replacement_check((int)$row()['is_resolved'] === 1, 'Staff confirmation failed');
    $receipt = $http('confirm',2,null,$confirmQuery)[1];
    replacement_check(str_contains($receipt, 'เจ้าหน้าที่ยืนยันรับของทดแทนแล้ว') && str_contains($receipt, 'Staff Test') && str_contains($receipt, 'วันที่รับของจริง') && str_contains($receipt, '&lt;script&gt;') && !str_contains($receipt, 'name="confirm_replacement"'), 'User receipt details missing or unsafe');
    $receiptLink = '../user/replacement_confirm.php?inspection_id=' . $id;
    replacement_check(notification_target($receiptLink) === $receiptLink, 'Receipt link rejected');
    replacement_check(mysqli_num_rows(mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=2 AND link_url='$receiptLink'")) === 1, 'Receipt notification missing');
    try {
        mysqli_query($conn, 'UPDATE damage_compensations SET amount=100');
        throw new RuntimeException('Database accepted monetary receipt');
    } catch (mysqli_sql_exception $e) { /* Database rejects nonzero amounts. */ }
    replacement_check((int)mysqli_fetch_row(mysqli_query($conn,'SELECT available_quantity FROM items WHERE item_id=1'))[0] === $stock+1, 'Confirmed replacement did not restore stock');
    $http('staff',1,$settle + ['received_replacement'=>1]);
    replacement_check((int)mysqli_fetch_row(mysqli_query($conn,'SELECT COUNT(*) FROM damage_compensations'))[0] === 1, 'Duplicate settlement');
    replacement_check((int)mysqli_fetch_row(mysqli_query($conn,'SELECT available_quantity FROM items WHERE item_id=1'))[0] === $stock+1, 'Duplicate settlement changed stock');
    replacement_check(str_contains($http('user',2)[1],'เจ้าหน้าที่ยืนยันชดใช้แล้ว'), 'Owner completion status missing');
    replacement_check(!preg_match('/PHP (Warning|Fatal|Parse|Notice)/', file_get_contents($log)), 'PHP errors: ' . file_get_contents($log));
    echo "PASS: migration, partial-return notification, ownership, CSRF, real uploads, invalid files, duplicate submission, staff evidence, physical receipt, stock and duplicate settlement.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (isset($conn)) {
        $proofs = mysqli_query($conn, 'SELECT replacement_image_url FROM return_inspections WHERE replacement_image_url IS NOT NULL');
        while ($proof = mysqli_fetch_row($proofs)) {
            if (preg_match('~^\.\./uploads/replacement_[a-f0-9]{32}\.(jpg|png|webp)$~D', $proof[0])) {
                $file = __DIR__ . '/' . $proof[0];
                if (is_file($file)) unlink($file);
            }
        }
    }
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $file) unlink($file);
    rmdir($sessionDirectory);
    foreach ($temporaryFiles as $file) if (is_file($file)) unlink($file);
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
