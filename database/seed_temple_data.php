<?php
/** CLI only: export additive SQL; --apply also imports into the configured database. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents(__DIR__ . '/temple_inventory.json'), true, 512, JSON_THROW_ON_ERROR);
$quote = static fn(string $value): string => "'" . mysqli_real_escape_string($conn, $value) . "'";
$statements = [];
foreach ($data['categories'] as $category) {
    $name = $quote($category);
    $statements[] = "INSERT INTO categories (category_name) SELECT $name WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = $name)";
}
foreach ($data['items'] as $item) {
    foreach ($item['photos'] as $photo) {
        if (!is_file(__DIR__ . '/../uploads/temple_260907_' . $photo . '.jpg')) {
            throw new RuntimeException('Missing source photo: ' . $photo);
        }
    }
    $name = $quote($item['name']);
    $category = $quote($data['categories'][$item['category']]);
    $url = $quote('../uploads/temple_260907_' . $item['photos'][0] . '.jpg');
    $quantity = (int) $item['quantity'];
    $isSet = empty($item['is_set']) ? 0 : 1;
    if ($quantity < 1) {
        throw new RuntimeException('Invalid demo quantity');
    }
    $description = $quote('อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ');
    $statements[] = "INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = $category ORDER BY category_id LIMIT 1), $name, $description, $url, $quantity, $quantity, $isSet, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = $url OR item_name = $name)";
    $statements[] = 'SET @temple_item_inserted = ROW_COUNT()';
    $statements[] = "INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', $quantity, $quantity, 0, $quantity, $description WHERE @temple_item_inserted = 1";
    foreach ($item['components'] ?? [] as $component) {
        $componentName = $quote($component['name']);
        $componentQuantity = (int) $component['quantity'];
        if ($componentQuantity < 1) {
            throw new RuntimeException('Invalid component quantity');
        }
        $unit = $quote($component['unit']);
        $componentUrl = isset($component['photo'])
            ? $quote('../uploads/temple_260907_' . (int) $component['photo'] . '.jpg') : 'NULL';
        $statements[] = "INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, $componentName, $componentQuantity, $unit, $componentUrl, 1 FROM items i WHERE i.image_url = $url AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = $componentName)";
    }
}
for ($i = 1; $i <= 5; $i++) {
    $phone = $quote('080000020' . $i);
    $firstName = $quote('ผู้ยืมตัวอย่าง ' . $i);
    $lastName = $quote('ทดสอบระบบ');
    $address = $quote('ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง');
    $hash = $quote(password_hash('246810', PASSWORD_DEFAULT));
    $statements[] = "INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, $hash, $firstName, $lastName, $phone, $phone, $address, 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = $phone OR phone_number = $phone)";
}
$sql = "-- Temple inventory: 29 items, 4 categories, 5 demo borrowers.\n"
    . "-- Stock quantities are simulated as requested. Existing records are preserved.\n"
    . "-- Demo phones: 0800000201-0800000205; PIN: 246810.\n"
    . "-- Select the target database before importing. Requires schema from profix.sql.\n"
    . "SET NAMES utf8mb4;\nSTART TRANSACTION;\n\n"
    . implode(";\n\n", $statements) . ";\n\nCOMMIT;\n";
$output = __DIR__ . '/seed_temple_data.sql';
if (file_put_contents($output, $sql) === false) {
    throw new RuntimeException('Cannot write SQL export');
}
echo "Exported: $output\n";
if (!in_array('--apply', $argv, true)) {
    echo "Pass --apply to import into the configured database.\n";
    exit;
}
$inserted = 0;
mysqli_begin_transaction($conn);
try {
    foreach ($statements as $statement) {
        mysqli_query($conn, $statement);
        $inserted += mysqli_affected_rows($conn);
    }
    mysqli_commit($conn);
    echo "Inserted $inserted rows (categories + items + inventory movements + users).\n";
} catch (Throwable $e) {
    mysqli_rollback($conn);
    throw $e;
}
