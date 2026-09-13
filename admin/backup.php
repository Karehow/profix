<?php

require_once __DIR__ . '/../config/app.php';

ensure_feature_tables($conn);
$current_admin = require_roles($conn, ['admin'], '../config/login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('กรุณาดาวน์โหลดข้อมูลสำรองจากปุ่มในหน้าผู้ดูแลระบบ');
}
require_csrf();

$admin_id = (int) $current_admin['user_id'];
write_audit_log($conn, $admin_id, 'download_database_backup', 'ดาวน์โหลดไฟล์สำรองฐานข้อมูล SQL ทั้งระบบ');

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="profix-backup-' . date('Ymd-His') . '.sql"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

echo "-- ProFix database backup\n";
echo '-- Generated: ' . date('c') . "\n";
echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "START TRANSACTION;\n\n";

mysqli_query($conn, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
mysqli_query($conn, 'START TRANSACTION WITH CONSISTENT SNAPSHOT');

$tables = mysqli_query($conn, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
if (!$tables) {
    http_response_code(500);
    exit("-- ไม่สามารถอ่านรายชื่อตารางได้\n");
}

while ($table_row = mysqli_fetch_row($tables)) {
    $table = (string) $table_row[0];
    $quoted_table = '`' . str_replace('`', '``', $table) . '`';
    $create_result = mysqli_query($conn, 'SHOW CREATE TABLE ' . $quoted_table);
    $create_row = $create_result ? mysqli_fetch_row($create_result) : null;
    if (!$create_row || empty($create_row[1])) {
        echo '-- ข้ามตารางที่อ่านโครงสร้างไม่ได้: ' . str_replace(["\r", "\n"], '', $table) . "\n";
        continue;
    }

    echo 'DROP TABLE IF EXISTS ' . $quoted_table . ";\n";
    echo $create_row[1] . ";\n\n";

    $data = mysqli_query($conn, 'SELECT * FROM ' . $quoted_table);
    if (!$data) {
        echo '-- ไม่สามารถอ่านข้อมูลตาราง ' . str_replace(["\r", "\n"], '', $table) . "\n\n";
        continue;
    }

    while ($row = mysqli_fetch_assoc($data)) {
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
            $values[] = $value === null
                ? 'NULL'
                : "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
        }
        echo 'INSERT INTO ' . $quoted_table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
    }
    mysqli_free_result($data);
    echo "\n";
}

echo "COMMIT;\n";
echo "SET FOREIGN_KEY_CHECKS=1;\n";
mysqli_commit($conn);
exit;
