<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/component_inventory.php';
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$is_borrower = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user';
$borrower_url = $is_borrower ? 'user/home.php' : 'user/index.php';
$borrower_label = $is_borrower ? 'หน้าหลักของฉัน' : 'เข้าสู่ระบบผู้ยืม';
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
$categoryId = max(0, (int) (filter_var($_GET['category_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0));
$page = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$categories = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.category_id, c.category_name, COUNT(i.item_id) AS item_count FROM categories c LEFT JOIN items i ON i.category_id = c.category_id AND i.is_active = 1 GROUP BY c.category_id, c.category_name ORDER BY c.category_name'), MYSQLI_ASSOC);
$totalItems = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM items WHERE is_active = 1'))[0];
$where = 'i.is_active = 1 AND (? = 0 OR i.category_id = ?) AND (? = \'\' OR i.item_name LIKE ? OR i.description LIKE ?)';
$like = '%' . $search . '%';
$stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM items i WHERE ' . $where);
mysqli_stmt_bind_param($stmt, 'iisss', $categoryId, $categoryId, $search, $like, $like);
mysqli_stmt_execute($stmt);
$resultCount = (int) mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];
mysqli_stmt_close($stmt);
$pageCount = max(1, (int) ceil($resultCount / 12));
$page = min($page, $pageCount);
$offset = ($page - 1) * 12;
$stmt = mysqli_prepare($conn, 'SELECT i.item_id, i.item_name, i.description, i.image_url, i.is_set, i.available_quantity, c.category_name FROM items i LEFT JOIN categories c ON c.category_id = i.category_id WHERE ' . $where . ' ORDER BY i.item_id DESC LIMIT 12 OFFSET ' . $offset);
mysqli_stmt_bind_param($stmt, 'iisss', $categoryId, $categoryId, $search, $like, $like);
mysqli_stmt_execute($stmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
foreach ($items as &$item) {
    $item['quantity'] = max(0, (int) $item['available_quantity']);
    $item['ready'] = $item['quantity'] > 0;
    $item['partial'] = false;
    if ((int) $item['is_set'] === 1) {
        $components = component_stock_rows($conn, (int) $item['item_id']);
        if ($components) {
            $stock = component_stock_summary($components);
            $item['quantity'] = $stock['full_sets'];
            $item['ready'] = $stock['pieces'] > 0;
            $item['partial'] = $stock['incomplete'];
        }
    }
    $item['image'] = preg_replace('~^(?:\.\./)+~', '', (string) $item['image_url']) ?: 'assets/images/item-placeholder.svg';
}
unset($item);
$catalogUrl = static function (array $changes = []) use ($search, $categoryId): string {
    return 'index.php?' . http_build_query(array_merge(['q' => $search, 'category_id' => $categoryId], $changes)) . '#catalog';
};
