<?php
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || !preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) {
    http_response_code(404); exit;
}
if (PHP_SAPI === 'cli-server') {
    $secret = getenv('PROFIX_PHOTO_TEST_TOKEN') ?: '';
    if (!$secret || !hash_equals($secret, $_SERVER['HTTP_X_TEST_TOKEN'] ?? '')) { http_response_code(403); exit; }
    require_once __DIR__ . '/../config/app.php';
    $_SESSION = ['user_id' => (int) ($_GET['actor'] ?? 1), 'csrf_token' => 'test-token'];
    require __DIR__ . '/../user/profile.php';
    exit;
}
function photo_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$secret = bin2hex(random_bytes(20));
putenv('PROFIX_PHOTO_TEST_TOKEN=' . $secret);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect(getenv('PROFIX_DB_HOST') ?: 'localhost', getenv('PROFIX_DB_USER') ?: 'root', getenv('PROFIX_DB_PASSWORD') ?: '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server = null;
$directory = sys_get_temp_dir() . '/profix-photo-test-' . bin2hex(random_bytes(8));
mkdir($directory);
require_once __DIR__ . '/../config/profile_photo.php';
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__ . '/profix.sql'));
    mysqli_multi_query($setup, str_replace('USE `profix`;', '', $sql));
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    require_once __DIR__ . '/../config/schema.php';
    mysqli_query($setup, 'ALTER TABLE users DROP COLUMN profile_image_url');
    migrate_profile_photo($setup);
    migrate_profile_photo($setup);
    mysqli_query($setup, "INSERT INTO users (first_name,last_name,phone_number,normalized_phone,address_detail,role) VALUES ('Photo','Test','0800000001','0800000001','Test address','user'),('Other','Test','0800000002','0800000002','Other address','user')");
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $log = $directory . '/server.log';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $directory, '-d', 'upload_tmp_dir=' . $directory, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=10M', '-S', $address, __FILE__], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, dirname(__DIR__));
    photo_check(is_resource($server), 'Cannot start server'); fclose($pipes[0]);
    $http = static function (?array $post = null, int $actor = 1) use ($address, $secret): array {
        $curl = curl_init('http://' . $address . '/?actor=' . $actor);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['X-Test-Token: ' . $secret]]);
        if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        return [$status, (string) $body];
    };
    for ($i = 0; $i < 30; $i++) { if ($http()[0]) break; usleep(100000); }
    photo_check(str_contains($http()[1], 'multipart/form-data'), 'Upload form missing');
    $post = ['csrf_token'=>'test-token','first_name'=>'Photo','last_name'=>'Test','phone_number'=>'0800000001','address_detail'=>'Test address','latitude'=>'','longitude'=>''];
    $row = static fn() => mysqli_fetch_assoc(mysqli_query($setup, 'SELECT * FROM users WHERE user_id=1'));
    photo_check($http(array_merge($post, ['csrf_token'=>'wrong']))[0] === 419, 'CSRF accepted');
    photo_check($http($post, 0)[0] === 302 && $row()['profile_image_url'] === null, 'Anonymous upload accepted');
    $im = imagecreatetruecolor(800, 600);
    imagepng($im, $directory . '/valid.png');
    imagejpeg($im, $directory . '/valid.jpg');
    imagewebp($im, $directory . '/valid.webp');
    imagedestroy($im);
    file_put_contents($directory . '/fake.png', '<?php echo "not an image";');
    file_put_contents($directory . '/large.png', str_repeat('x', 5 * 1024 * 1024 + 1));
    foreach (['fake.png', 'large.png'] as $name) {
        $http($post + ['profile_photo'=>new CURLFile($directory . '/' . $name, 'image/png', $name)]);
        photo_check($row()['profile_image_url'] === null, 'Invalid file accepted');
    }
    $old = null;
    foreach (['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'] as $ext=>$mime) {
        $response = $http($post + ['user_id'=>2,'profile_photo'=>new CURLFile($directory . '/valid.' . $ext, $mime, 'photo.' . $ext)]);
        photo_check($response[0] === 302, 'Upload failed: ' . $response[1]);
        $url = $row()['profile_image_url'];
        photo_check(is_string($url) && preg_match('~^\.\./uploads/profile_[a-f0-9]{32}\.jpg$~D', $url), 'Invalid saved URL');
        $info = getimagesize(__DIR__ . '/' . $url);
        photo_check($info[0] === 512 && $info[1] === 384 && $info['mime'] === 'image/jpeg', 'Image was not normalized');
        photo_check(str_contains($http()[1], $url), 'Saved image not rendered');
        if ($old) photo_check(!is_file(__DIR__ . '/' . $old), 'Old photo not cleaned up');
        $old = $url;
    }
    $http($post);
    photo_check($row()['profile_image_url'] === $old, 'Editing without photo removed it');
    $http(array_merge($post, ['phone_number'=>'0800000002','profile_photo'=>new CURLFile($directory . '/valid.png', 'image/png', 'photo.png')]));
    photo_check($row()['profile_image_url'] === $old && is_file(__DIR__ . '/' . $old), 'Validation error lost saved photo');
    photo_check(mysqli_fetch_row(mysqli_query($setup, 'SELECT profile_image_url FROM users WHERE user_id=2'))[0] === null, 'Another account modified');
    photo_check(!preg_match('/PHP (Warning|Fatal|Parse|Notice)/', file_get_contents($log)), 'PHP errors: ' . file_get_contents($log));
    echo "PASS: migration, multipart JPG/PNG/WebP uploads, resizing, persistence, replacement cleanup, invalid files, size limit, CSRF and ownership.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $result = mysqli_query($setup, 'SELECT profile_image_url FROM users');
    while ($photo = mysqli_fetch_row($result)) profile_photo_delete($photo[0]);
    foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
