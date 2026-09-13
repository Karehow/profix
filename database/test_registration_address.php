<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) { fwrite(STDERR, "Use a new profix_test_* database.\n"); exit(1); }
if (($argv[1] ?? '') === '--page') {
    session_save_path(sys_get_temp_dir());
    session_start();
    $_SESSION = ['csrf_token'=>'test-token'];
    register_shutdown_function(static function () { session_destroy(); });
    $_POST = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $_GET = ['mode' => $argv[3] ?? 'register'];
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    require __DIR__ . '/../user/index.php';
    exit;
}
function address_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function address_page(array $post, string $mode = 'register'): string {
    $process = proc_open([PHP_BINARY, __FILE__, '--page', json_encode($post), $mode], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    address_check(proc_close($process) === 0 && $errors === '', 'Registration PHP error: ' . $errors);
    return $html;
}
require_once __DIR__ . '/../config/thai_address.php';
$data = thai_address_data();
address_check(count($data) === 77, 'Incomplete province dataset');
foreach ($data as $province) {
    address_check((bool)$province['districts'], 'Province has no districts');
    foreach ($province['districts'] as $district) {
        foreach ($district['subdistricts'] as $subdistrict) {
            address_check((bool)preg_match('/^[0-9]{5}$/D', $subdistrict['postcode']), 'Invalid ZIP code');
        }
    }
}
$province = $data[0]; $district = $province['districts'][0]; $subdistrict = $district['subdistricts'][0];
$post = ['csrf_token'=>'test-token','action'=>'register','first_name'=>'ทดสอบ','last_name'=>'ที่อยู่','phone_number'=>'0800000991','pin'=>'123456','pin_confirm'=>'123456','address_detail'=>'12/3 หมู่ 4','province_id'=>$province['id'],'district_id'=>$district['id'],'subdistrict_id'=>$subdistrict['id'],'postcode'=>'99999'];
$fullAddress = thai_address_format($post);
address_check(str_contains($fullAddress, 'แขวงพระบรมมหาราชวัง เขตพระนคร กรุงเทพมหานคร 10200'), 'Bangkok address format incorrect');
address_check(!str_contains($fullAddress, '99999'), 'Client ZIP code trusted');
$other = $data[1]; $otherDistrict = $other['districts'][0]; $otherSubdistrict = $otherDistrict['subdistricts'][0];
$regional = thai_address_format(array_merge($post, ['province_id'=>$other['id'],'district_id'=>$otherDistrict['id'],'subdistrict_id'=>$otherSubdistrict['id']]));
address_check(str_contains($regional, 'ตำบล') && str_contains($regional, 'อำเภอ') && str_contains($regional, 'จังหวัด' . $other['name']), 'Regional address format incorrect');
[$province, $district] = thai_address_selection(['province_id' => REGISTRATION_PROVINCE_ID, 'district_id' => REGISTRATION_DISTRICT_ID]);
address_check($province['name'] === 'นครราชสีมา', 'Registration province is incorrect');
address_check($district['name'] === 'จักราช', 'Registration district is incorrect');
$subdistrict = $district['subdistricts'][0];
$post = array_merge($post, ['province_id'=>$province['id'], 'district_id'=>$district['id'], 'subdistrict_id'=>$subdistrict['id']]);
$fullAddress = thai_address_format($post);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__.'/profix.sql'));
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result=mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    $html = address_page([]);
    address_check((bool)preg_match('/<select id="addressProvince"[^>]* disabled>/', $html), 'Registration province is not locked');
    address_check((bool)preg_match('/<select id="addressDistrict" name="district_id"[^>]* required>/', $html) && !preg_match('/<select id="addressDistrict"[^>]* disabled>/', $html), 'Registration district must be editable');
    preg_match('/<script type="application\/json" id="thaiAddressData">(.*?)<\/script>/s', $html, $addressJson);
    $registrationData = json_decode($addressJson[1], true, 512, JSON_THROW_ON_ERROR);
    address_check(count($registrationData) === 1 && $registrationData[0]['id'] === REGISTRATION_PROVINCE_ID, 'Other provinces exposed in registration');
    address_check(count($registrationData[0]['districts']) === count($province['districts']), 'Province districts not exposed in registration');
    $differentDistrict = $province['districts'][0];
    address_check($differentDistrict['id'] !== REGISTRATION_DISTRICT_ID, 'Invalid district test requires another district');
    address_page(array_merge($post, ['district_id' => $differentDistrict['id']]));
    address_check((int)mysqli_fetch_row(mysqli_query($setup, 'SELECT COUNT(*) FROM users'))[0] === 0, 'Mismatched district/subdistrict registered a user');
    address_check(str_contains($html, 'data-confirm-for="registerPin"') && str_contains($html, 'data-password-toggle="registerPin"'), 'Registration PIN controls missing');
    $loginHtml = address_page([], 'login');
    address_check(str_contains($loginHtml, 'id="loginPhone"') && !str_contains($loginHtml, 'id="addressProvince"') && !str_contains($loginHtml, 'loginModal'), 'Login form is not directly accessible');
    $legacyHtml = address_page([], 'activate_legacy');
    address_check(str_contains($legacyHtml, 'id="legacyPhone"') && str_contains($legacyHtml, 'name="action" value="activate_legacy"'), 'Legacy activation inaccessible');
    foreach (['addressProvince','addressDistrict','addressSubdistrict','addressPostcode'] as $field) address_check(str_contains($html, 'id="'.$field.'"'), 'Address field missing');
    foreach ([['province_id'=>$other['id'], 'district_id'=>$otherDistrict['id'], 'subdistrict_id'=>$otherSubdistrict['id']], ['province_id'=>'99999'], ['district_id'=>$otherDistrict['id']], ['subdistrict_id'=>$otherSubdistrict['id']], ['subdistrict_id'=>''], ['province_id'=>[]], ['address_detail'=>''], ['address_detail'=>str_repeat('ก',1001)]] as $invalid) {
        address_page(array_merge($post, $invalid));
        address_check((int)mysqli_fetch_row(mysqli_query($setup, 'SELECT COUNT(*) FROM users'))[0] === 0, 'Invalid address registered a user');
    }
    foreach (['addressHouse','addressMoo','addressVillage','findAddressLocation'] as $id) address_check(str_contains($html, 'id="'.$id.'"'), 'New address control missing');
    $post = array_merge($post, ['house_number'=>'95/2', 'village_number'=>'4', 'village_name'=>'หนองบัว']);
    $fullAddress = thai_address_format($post);
    address_check(str_contains($fullAddress, '95/2 หมู่ 4 หมู่บ้านหนองบัว'), 'Split fields not composed');
    $selectedVillage = array_merge($post, ['village_choice'=>$subdistrict['id'].'-1','village_number'=>'999','village_name'=>'ค่าปลอม']);
    $selectedAddress = thai_address_format($selectedVillage);
    address_check(str_contains($selectedAddress, 'หมู่ 1 หมู่บ้าน'.registration_villages()[$subdistrict['id']][0]) && !str_contains($selectedAddress, 'ค่าปลอม'), 'Village dropdown did not validate and derive canonical name / moo');
    foreach (['300611-1', $subdistrict['id'].'-999'] as $invalidChoice) {
        address_page(array_merge($post, ['village_choice'=>$invalidChoice]));
        address_check((int)mysqli_fetch_row(mysqli_query($setup, 'SELECT COUNT(*) FROM users'))[0] === 0, 'Invalid village choice registered a user');
    }
    address_check(str_contains($html, 'id="addressVillageSelect"') && str_contains($html, 'registration-villages.js'), 'Village dropdown missing');
    foreach ([['house_number'=>''], ['house_number'=>[]], ['village_number'=>'abc'], ['village_name'=>str_repeat('ก',201)]] as $invalid) {
        address_page(array_merge($post, $invalid));
        address_check((int)mysqli_fetch_row(mysqli_query($setup, 'SELECT COUNT(*) FROM users'))[0] === 0, 'Invalid structured address registered a user');
    }
    $retry = address_page(array_merge($post, ['pin_confirm'=>'654321']));
    foreach (['95/2','4','หนองบัว'] as $value) address_check(str_contains($retry, 'value="'.$value.'"'), 'Split address lost on retry');
    foreach ([$province['id'], $district['id'], $subdistrict['id']] as $id) address_check(str_contains($retry, 'value="'.$id.'" selected'), 'Selection not retained after PIN error');
    $post = $selectedVillage;
    $fullAddress = $selectedAddress;
    address_page($post);
    $registered = mysqli_fetch_assoc(mysqli_query($setup, 'SELECT * FROM users LIMIT 1'));
    address_check($registered && $registered['address_detail'] === $fullAddress && password_verify('123456', $registered['borrower_pin_hash']), 'Registration did not save the full validated address');
    address_page(['csrf_token'=>'test-token','action'=>'login','phone_number'=>'0800000991','pin'=>'123456']);
    address_check((int)mysqli_fetch_row(mysqli_query($setup,'SELECT COUNT(*) FROM users'))[0] === 1, 'Login changed registration');
    $anotherPost = array_merge($post, ['phone_number'=>'0800000992', 'district_id'=>$differentDistrict['id'], 'subdistrict_id'=>$differentDistrict['subdistricts'][0]['id'], 'village_choice'=>'other', 'village_name'=>'หมู่บ้านทดสอบ', 'village_number'=>'2']);
    $anotherRetry = address_page(array_merge($anotherPost, ['pin_confirm'=>'654321']));
    address_check(str_contains($anotherRetry, 'value="'.$differentDistrict['id'].'" selected'), 'Selected district lost after validation error');
    address_page($anotherPost);
    $anotherUser = mysqli_fetch_assoc(mysqli_query($setup, "SELECT address_detail FROM users WHERE normalized_phone='0800000992'"));
    address_check($anotherUser && $anotherUser['address_detail'] === thai_address_format($anotherPost), 'Another district registration failed');
    echo "PASS: local dataset, cascading address validation, ZIP protection, Bangkok/regional formatting, registration, retained selections and existing login.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
