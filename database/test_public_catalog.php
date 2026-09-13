<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = getenv('PROFIX_DB_NAME') ?: '';
if (!preg_match('/^profix_test_[a-z0-9_]+$/D', $testDb)) exit(1);
if (($argv[1] ?? '') === '--page') {
    session_save_path(sys_get_temp_dir());
    session_start();
    parse_str($argv[2] ?? '', $_GET);
    require __DIR__ . '/../index.php';
    session_destroy();
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$setup = mysqli_connect('localhost', 'root', '');
mysqli_query($setup, "CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function catalog_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function catalog_render(string $query): string {
    $process = proc_open([PHP_BINARY, __FILE__, '--page', $query], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    catalog_check(proc_close($process) === 0 && $errors === '', 'Render errors: ' . $errors);
    return $html;
}
try {
    mysqli_select_db($setup, $testDb);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `profix`.*?;/s', '', file_get_contents(__DIR__ . '/profix.sql'));
    $sql = str_replace('USE `profix`;', '', $sql);
    mysqli_multi_query($setup, $sql);
    do { if ($result = mysqli_store_result($setup)) mysqli_free_result($result); } while (mysqli_more_results($setup) && mysqli_next_result($setup));
    mysqli_query($setup, "INSERT INTO items (category_id,item_name,total_quantity,available_quantity,is_active,image_url) VALUES (1,'Public Visible',5,4,1,'../uploads/example.jpg'),(2,'Public Other',2,2,1,NULL),(1,'Public Inactive',1,1,0,NULL),(1,'Public Empty',1,0,1,NULL)");
    $html = catalog_render('q=Public&category_id=1');
    catalog_check(str_contains($html, 'Public Visible') && !str_contains($html, 'Public Other') && !str_contains($html, 'Public Inactive'), 'Search/category/active filter');
    catalog_check(str_contains($html, 'src="uploads/example.jpg"'), 'Root image path');
    catalog_check(str_contains($html, 'aria-disabled="true"'), 'Unavailable stock action');
    catalog_check(str_contains($html, 'เข้าสู่ระบบเพื่อยืม') && !str_contains($html, 'action="add"'), 'Guest authentication link');
    catalog_check(str_contains(catalog_render('q=NoSuchItem123'), 'ไม่พบสิ่งของ'), 'Empty search');
    catalog_check(!str_contains(catalog_render('q=%22%3E%3Cscript%3E'), '"><script>'), 'Escaping search input');
    for ($i=0; $i<13; $i++) mysqli_query($setup, "INSERT INTO items (category_id,item_name,total_quantity,available_quantity,is_active) VALUES (1,'Paging $i',1,1,1)");
    $html = catalog_render('q=Paging&page=2&category_id=1');
    catalog_check(substr_count($html, '<article class="catalog-item">') === 1 && str_contains($html, 'page=1') && str_contains($html, 'q=Paging'), 'Pagination and filter preservation');
    catalog_check(str_contains(catalog_render('q[]=bad&category_id[]=bad&page[]=bad'), '<!doctype html>'), 'Malformed query handling');
    echo "PASS: public catalogue search, categories, active-only items, stock, image paths, guest links, escaping, pagination and empty state.\n";
} finally {
    mysqli_query($setup, "DROP DATABASE `$testDb`");
}
