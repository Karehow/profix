<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/set_availability.php';
require_once __DIR__ . '/../config/component_quantities.php';
require_once __DIR__ . '/../config/component_inventory.php';
ensure_feature_tables($conn);
$current_user = require_roles($conn, ['user'], 'index.php');

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$search = trim($_GET['q'] ?? '');
$categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'available';
$sortOrders = ['available' => '(available_quantity <= 0) ASC, item_name ASC', 'latest' => 'created_at DESC, item_id DESC', 'name' => 'item_name ASC'];
if (!isset($sortOrders[$sort])) $sort = 'available';
$page = max(1, (int) ($_GET['page'] ?? 1));
$borrow_error = $_SESSION['borrow_error'] ?? '';
unset($_SESSION['borrow_error']);

// จัดการฟอร์มเพิ่ม-ลดจำนวน และการเลือกยืมทั้งชุด/เฉพาะชิ้นส่วนย่อย
if (isset($_POST['action'])) {
    require_csrf();
    $item_id = intval($_POST['item_id']);
    $act = is_scalar($_POST['action']) ? (string) $_POST['action'] : '';
    $item_q = mysqli_query($conn, "SELECT item_id, available_quantity, is_set FROM items WHERE item_id = $item_id AND is_active = 1 LIMIT 1");
    $item = $item_q ? mysqli_fetch_assoc($item_q) : null;
    
    // ปรับโครงสร้างข้อมูลเดิมใน Session (ถ้ามี) ให้รองรับการเก็บชิ้นส่วนที่ไม่เอา
    if (isset($_SESSION['cart'][$item_id]) && !is_array($_SESSION['cart'][$item_id])) {
        $_SESSION['cart'][$item_id] = [
            'qty' => $_SESSION['cart'][$item_id],
            'excluded_components' => []
        ];
    }

    if ($act === 'add' && $item && (int) $item['available_quantity'] > 0) {
        if ((int) $item['is_set'] === 1) {
            // ปุ่มเพิ่มแบบเร็วใช้ชุดมาตรฐาน; ปรับจำนวนหลายชุดได้ในหน้ารายละเอียด
            $missing_q = mysqli_query($conn, "SELECT 1 FROM item_components WHERE parent_item_id = $item_id AND is_available = 0 LIMIT 1");
            if (mysqli_num_rows($missing_q) > 0) {
                $_SESSION['borrow_error'] = 'ของในชุดไม่ครบ กรุณาเลือกเฉพาะของย่อยที่พร้อมให้ยืม';
            } else {
                $_SESSION['cart'][$item_id] = ['qty' => 1, 'excluded_components' => []];
            }
        } else {
            $current_qty = isset($_SESSION['cart'][$item_id]) ? (int) ($_SESSION['cart'][$item_id]['qty'] ?? 0) : 0;
            if ($current_qty < (int) $item['available_quantity']) {
                $_SESSION['cart'][$item_id] = ['qty' => $current_qty + 1, 'excluded_components' => []];
            }
        }
    } elseif ($act === 'update_quantity') {
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$item || (int) $item['is_set'] !== 0) {
            $_SESSION['borrow_error'] = 'ไม่พบสิ่งของที่พร้อมให้ยืม';
        } elseif ($quantity === false || $quantity < 0 || $quantity > (int) $item['available_quantity']) {
            $_SESSION['borrow_error'] = 'กรุณาระบุจำนวนเต็มตั้งแต่ 0 ถึงจำนวนคงเหลือ';
        } elseif ($quantity === 0) {
            unset($_SESSION['cart'][$item_id]);
        } else {
            $_SESSION['cart'][$item_id] = ['qty' => $quantity, 'excluded_components' => []];
        }
    } elseif ($act === 'minus') {
        if (isset($_SESSION['cart'][$item_id])) {
            if ($item && (int) $item['is_set'] === 1) $_SESSION['cart'][$item_id]['qty'] = 1;
            $_SESSION['cart'][$item_id]['qty']--;
            if ($_SESSION['cart'][$item_id]['qty'] <= 0) {
                unset($_SESSION['cart'][$item_id]);
            }
        }
    } elseif ($act === 'update_set') {
        if (!$item || (int) $item['is_set'] !== 1) {
            $_SESSION['borrow_error'] = 'รายการชุดนี้หมดหรือไม่พร้อมให้ยืมแล้ว';
        } else {
            $all_components = [];
            $component_rows = component_stock_rows($conn, $item_id);
            $unavailable_components = [];
            foreach ($component_rows as $component) {
                $all_components[] = (int) $component['component_id'];
                if (!(int) $component['is_available']) $unavailable_components[] = (int) $component['component_id'];
            }

            $borrow_mode = ($_POST['borrow_mode'] ?? 'full') === 'components' ? 'components' : 'full';
            if ($borrow_mode === 'components') {
                try {
                    $requested = $_POST['component_quantities'] ?? [];
                    if (!is_array($requested)) throw new InvalidArgumentException('ข้อมูลจำนวนของย่อยไม่ถูกต้อง');
                    $_SESSION['cart'][$item_id] = component_selection($component_rows, $requested, (int) $item['available_quantity']);
                } catch (InvalidArgumentException $e) {
                    $_SESSION['borrow_error'] = $e->getMessage();
                }
            } elseif ($unavailable_components) {
                $_SESSION['borrow_error'] = 'ของในชุดไม่ครบ กรุณาเลือกเฉพาะของย่อยที่พร้อมให้ยืม';
            } else {
                $fullQuantity = filter_var($_POST['set_quantity'] ?? 1, FILTER_VALIDATE_INT);
                $fullAvailable = $component_rows ? component_stock_summary($component_rows)['full_sets'] : (int) $item['available_quantity'];
                if ($fullQuantity === false || $fullQuantity < 1 || $fullQuantity > $fullAvailable) {
                    $_SESSION['borrow_error'] = 'จำนวนชุดมาตรฐานเกินคงเหลือหรือไม่ถูกต้อง';
                } else {
                    $_SESSION['cart'][$item_id] = ['qty' => $fullQuantity, 'excluded_components' => []];
                }
            }
        }
    }

    $redirect = 'borrow.php' . ($search !== '' ? '?q=' . urlencode($search) : '');
    if ($categoryId) $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'category_id=' . $categoryId;
    $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . http_build_query(['sort'=>$sort, 'page'=>$page]);
    header("Location: $redirect");
    exit();
}

// คำนวณจำนวนชิ้นทั้งหมดในตะกร้า
$total_cart_count = 0;
foreach ($_SESSION['cart'] as $cart_item) {
    $total_cart_count += is_array($cart_item) ? ($cart_item['qty'] ?? 0) : intval($cart_item);
}

$itemsSql = 'SELECT * FROM items WHERE is_active = 1';
if ($categoryId) $itemsSql .= ' AND category_id = ' . $categoryId;
if ($search !== '') {
    $itemsSql .= ' AND (item_name LIKE ? OR description LIKE ?)';
}
$itemsSql .= ' ORDER BY ' . $sortOrders[$sort];

if ($search !== '') {
    $itemsStmt = mysqli_prepare($conn, $itemsSql);
    $searchLike = '%' . $search . '%';
    mysqli_stmt_bind_param($itemsStmt, 'ss', $searchLike, $searchLike);
    mysqli_stmt_execute($itemsStmt);
    $items_query = mysqli_stmt_get_result($itemsStmt);
} else {
    $items_query = mysqli_query($conn, $itemsSql);
}
$item_count = $items_query ? mysqli_num_rows($items_query) : 0;
$incomplete_sets = incomplete_set_ids($conn);
$catalogCategories = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.category_id,c.category_name,COUNT(i.item_id) AS item_count FROM categories c LEFT JOIN items i ON i.category_id=c.category_id AND i.is_active=1 GROUP BY c.category_id,c.category_name ORDER BY c.category_id'), MYSQLI_ASSOC);
$catalogTotal = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM items WHERE is_active=1'))[0];
$catalogUrl = static fn(array $params): string => 'borrow.php?' . http_build_query(array_merge(['q'=>$search, 'category_id'=>$categoryId, 'sort'=>$sort], $params));
$pageSize = 12;
$pageCount = max(1, (int) ceil($item_count / $pageSize));
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;
if ($item_count) mysqli_data_seek($items_query, $offset);
$visibleCount = 0;
$inbox = ['unread' => (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM notifications WHERE user_id=' . (int) $current_user['user_id'] . ' AND is_read=0'))[0]];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการสิ่งของ - ระบบยืมคืนวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <link rel="stylesheet" href="../assets/css/borrow.css">
<?php require __DIR__ . '/../config/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/user-dashboard.css?v=3">
<link rel="stylesheet" href="../assets/css/borrow-catalog.css?v=1">
</head>
<body class="app-ui temple-dashboard borrow-page">
<?php $uiPage = 'user/borrow.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<div class="container py-4">
    <section class="catalog-hero"><h1>รายการสิ่งของสำหรับยืม</h1><p>เลือกอุปกรณ์จากหมวดหมู่ หรือค้นหาชื่อสิ่งของที่ต้องการ</p></section>
    <?php if ($borrow_error !== ''): ?><div class="alert alert-danger" role="alert"><?= app_escape($borrow_error) ?></div><?php endif; ?>
    <form method="get" class="catalog-search" role="search">
        <label class="visually-hidden" for="itemSearch">ค้นหาสิ่งของ</label><input id="itemSearch" type="search" name="q" class="form-control" value="<?= app_escape($search) ?>" placeholder="ค้นหาชื่ออุปกรณ์ที่ต้องการ…">
        <label class="visually-hidden" for="catalogCategory">หมวดหมู่</label><select id="catalogCategory" name="category_id" class="form-select"><option value="0">ทุกหมวดหมู่</option><?php foreach ($catalogCategories as $category): ?><option value="<?= (int) $category['category_id'] ?>" <?= $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>><?= app_escape($category['category_name']) ?></option><?php endforeach; ?></select>
        <input type="hidden" name="sort" value="<?= app_escape($sort) ?>"><button class="btn btn-primary" type="submit">ค้นหา</button>
        <?php if ($search !== '' || $categoryId): ?><a href="borrow.php" class="catalog-clear">ล้างตัวกรอง</a><?php endif; ?>
    </form>
    <div class="catalog-layout">
    <nav class="catalog-categories" aria-label="หมวดหมู่อุปกรณ์">
        <a href="<?= app_escape($catalogUrl(['category_id'=>0])) ?>" <?= !$categoryId ? 'aria-current="page"' : '' ?>><span>ทั้งหมด<small><?= $catalogTotal ?> รายการ</small></span><span aria-hidden="true">›</span></a>
        <?php foreach ($catalogCategories as $category): ?><a href="<?= app_escape($catalogUrl(['category_id'=>(int) $category['category_id']])) ?>" <?= $categoryId === (int) $category['category_id'] ? 'aria-current="page"' : '' ?>><span><?= app_escape($category['category_name']) ?><small><?= (int) $category['item_count'] ?> รายการ</small></span><span aria-hidden="true">›</span></a><?php endforeach; ?>
    </nav>
    <section class="catalog-results" aria-labelledby="catalogTitle">
    <div class="catalog-toolbar"><h2 id="catalogTitle">อุปกรณ์<?= $search !== '' || $categoryId ? 'ที่พบ' : 'ทั้งหมด' ?> <small>(<?= $item_count ?> รายการ)</small></h2>
        <form method="get" class="catalog-sort"><input type="hidden" name="q" value="<?= app_escape($search) ?>"><input type="hidden" name="category_id" value="<?= $categoryId ?>"><label for="catalogSort" class="visually-hidden">เรียงลำดับ</label><select id="catalogSort" name="sort" class="form-select"><?php foreach (['available'=>'พร้อมให้ยืมก่อน', 'latest'=>'ล่าสุด', 'name'=>'ชื่ออุปกรณ์'] as $value=>$label): ?><option value="<?= $value ?>" <?= $sort === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-outline-primary">เรียง</button></form>
    </div>
    <div class="catalog-grid">
        <?php if ($item_count === 0): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm"><div class="card-body text-center py-5"><div class="fs-1 mb-2">🔍</div><h5 class="fw-bold">ไม่พบสิ่งของที่ค้นหา</h5><p class="text-muted mb-3">ลองใช้คำค้นหาอื่น หรือล้างคำค้นหาเพื่อดูสิ่งของทั้งหมด</p><a href="borrow.php" class="btn btn-outline-primary">ดูสิ่งของทั้งหมด</a></div></div>
            </div>
        <?php endif; ?>
        <?php while ($visibleCount++ < $pageSize && ($row = mysqli_fetch_assoc($items_query))): ?>
            <?php 
                $item_id = $row['item_id'];
                $cart_item = $_SESSION['cart'][$item_id] ?? ['qty' => 0, 'excluded_components' => []];
                
                // จัดการ Data Type ให้รองรับรูปแบบ Array
                $qty_in_cart = is_array($cart_item) ? ($cart_item['qty'] ?? 0) : intval($cart_item);
                $excluded_components = is_array($cart_item) ? ($cart_item['excluded_components'] ?? []) : [];
                $excluded_count = count($excluded_components);
                $selected_quantities = is_array($cart_item) ? ($cart_item['component_quantities'] ?? null) : null;
                $is_out_of_stock = (int) $row['available_quantity'] <= 0;
                $components = [];
                $full_sets_available = (int) $row['available_quantity'];
                if ((int) $row['is_set'] === 1) {
                    $components = component_stock_rows($conn, $item_id);
                    if ($components) {
                        $stock_summary = component_stock_summary($components);
                        $full_sets_available = $stock_summary['full_sets'];
                        $is_out_of_stock = $stock_summary['pieces'] === 0;
                        if ($stock_summary['incomplete']) $incomplete_sets[(int) $item_id] = true;
                    }
                }
            ?>
            <div class="catalog-item">
                <div class="card h-100 shadow-sm border-0 <?= $is_out_of_stock ? 'catalog-unavailable' : '' ?>">
                    <div class="catalog-image"><img src="<?= $row['image_url'] ? htmlspecialchars($row['image_url']) : '../assets/images/item-placeholder.svg' ?>" class="card-img-top" alt="<?= app_escape($row['item_name']) ?>" loading="lazy"><span class="catalog-stock <?= $is_out_of_stock ? 'is-empty' : '' ?>"><?= $is_out_of_stock ? 'ไม่พร้อมให้ยืม' : (isset($incomplete_sets[(int) $item_id]) ? 'เลือกของย่อยได้' : 'พร้อมให้ยืม') ?></span></div>
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <h5 class="card-title fw-bold"><?= htmlspecialchars($row['item_name']) ?></h5>
                            <p class="card-text text-muted small mb-2"><?= htmlspecialchars($row['description']) ?></p>
                            <?php if (isset($incomplete_sets[(int) $item_id])): ?>
                                <div class="alert alert-warning py-2 mb-2 text-center"><strong>ของไม่ครบชุด</strong><div class="small"><?= $is_out_of_stock ? 'ของย่อยหมดหรือไม่พร้อมให้ยืม' : 'ยังเลือกยืมของย่อยที่เหลือได้' ?></div></div>
                            <?php elseif ($is_out_of_stock): ?>
                                <div class="alert alert-danger py-2 mb-2 text-center fw-bold">ของหมด</div>
                            <?php else: ?>
                                <p class="mb-2 text-primary">คงเหลือ: <strong><?= $row['is_set'] ? $full_sets_available : $row['available_quantity'] ?></strong> <?= $row['is_set'] ? 'ชุดมาตรฐาน' : 'ชิ้น' ?></p>
                            <?php endif; ?>

                            <?php if ($row['is_set']): ?>
                                <button class="btn btn-sm btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#setModal<?= $item_id ?>" <?= $is_out_of_stock ? 'disabled' : '' ?>>
                                    ⚙️ เลือกยืมทั้งชุดหรือของย่อย
                                    <?php if ($qty_in_cart > 0 && ($excluded_count > 0 || $selected_quantities !== null)): ?>
                                        <span class="badge bg-warning text-dark ms-1">เฉพาะของย่อย</span>
                                    <?php elseif ($qty_in_cart > 0): ?>
                                        <span class="badge bg-success ms-1">ทั้งชุด</span>
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($row['is_set']): ?>
                            <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                                <span class="<?= $qty_in_cart > 0 ? 'text-success fw-bold' : 'text-muted' ?>"><?= $qty_in_cart > 0 ? ($selected_quantities !== null ? 'เลือกของย่อยรวม ' . array_sum($selected_quantities) . ' ชิ้น' : 'เลือกแล้ว ' . (int) $qty_in_cart . ' ชุด') : 'ยังไม่ได้เลือก' ?></span>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                                    <input type="hidden" name="action" value="minus">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" <?= $qty_in_cart <= 0 ? 'disabled' : '' ?>>นำออก</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                                    <input type="hidden" name="action" value="minus">
                                    <button type="submit" class="btn btn-outline-danger btn-sm px-3" <?= $qty_in_cart <= 0 ? 'disabled' : '' ?>>-</button>
                                </form>

                                <form method="POST" class="text-center mx-2 quantity-form">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="number" name="quantity" class="form-control text-center fw-bold fs-5" min="0" max="<?= (int) $row['available_quantity'] ?>" step="1" value="<?= (int) $qty_in_cart ?>" aria-label="จำนวนที่ต้องการยืม <?= app_escape($row['item_name']) ?>" inputmode="numeric" required <?= $is_out_of_stock && $qty_in_cart <= 0 ? 'disabled' : '' ?>>
                                    <button type="submit" class="btn btn-sm btn-link quantity-save" <?= $is_out_of_stock && $qty_in_cart <= 0 ? 'disabled' : '' ?>>บันทึก</button>
                                </form>

                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                                    <input type="hidden" name="action" value="add">
                                    <button type="submit" class="btn btn-outline-success btn-sm px-3" <?= $is_out_of_stock || $qty_in_cart >= $row['available_quantity'] ? 'disabled' : '' ?>>+</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Modal เลือกชิ้นส่วนย่อยในชุด -->
            <?php if ($row['is_set']): ?>
                <div class="modal fade" id="setModal<?= $item_id ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="update_set">
                                <input type="hidden" name="item_id" value="<?= $item_id ?>">

                                <div class="modal-header bg-light">
                                    <h5 class="modal-title fw-bold">📦 ชิ้นส่วนในชุด: <?= htmlspecialchars($row['item_name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info py-2 small">เลือกจำนวนชุดมาตรฐาน หรือปรับจำนวนของย่อยแต่ละชนิด ใส่ 0 หากไม่ต้องการ ระบบตัดเฉพาะจำนวนของย่อยที่ยืม</div>
                                    <?php
                                    $has_components = count($components) > 0;
                                    $has_unavailable = $full_sets_available < 1;
                                    $borrow_mode = $selected_quantities !== null || $excluded_count > 0 || $has_unavailable ? 'components' : 'full';
                                    ?>
                                    <div class="form-check border rounded p-3 ps-5 mb-2">
                                        <input class="form-check-input set-mode" type="radio" name="borrow_mode" value="full" id="full_<?= $item_id ?>" data-target="components_<?= $item_id ?>" <?= $borrow_mode === 'full' ? 'checked' : '' ?> <?= $has_unavailable ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="full_<?= $item_id ?>">ยืมชุดมาตรฐาน</label>
                                        <div class="small text-muted">รับของย่อยทุกชิ้นตามรายการของชุด</div>
                                        <label class="form-label mt-2" for="setQuantity_<?= $item_id ?>">จำนวนชุด (พร้อม <?= $full_sets_available ?> ชุด)</label>
                                        <input type="number" class="form-control standard-set-quantity" name="set_quantity" id="setQuantity_<?= $item_id ?>" min="1" max="<?= $full_sets_available ?>" value="<?= $selected_quantities === null && $qty_in_cart > 0 ? (int) $qty_in_cart : 1 ?>" required <?= $has_unavailable ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="form-check border rounded p-3 ps-5 mb-3 <?= !$has_components ? 'text-muted' : '' ?>">
                                        <input class="form-check-input set-mode" type="radio" name="borrow_mode" value="components" id="partial_<?= $item_id ?>" data-target="components_<?= $item_id ?>" <?= $borrow_mode === 'components' ? 'checked' : '' ?> <?= !$has_components ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="partial_<?= $item_id ?>">ยืมเฉพาะของย่อยในชุด</label>
                                        <div class="small text-muted">เช่น พานทอง 3 ใบ และพานเงิน 1 ใบ</div>
                                    </div>

                                    <div id="components_<?= $item_id ?>" class="list-group set-components">
                                        <?php if (!$has_components): ?>
                                            <div class="list-group-item text-muted">ชุดนี้ยังไม่มีรายการของย่อย จึงเลือกยืมได้เฉพาะทั้งชุด</div>
                                        <?php endif; ?>
                                        <?php foreach ($components as $comp):
                                            $comp_id = $comp['component_id'];
                                            $is_excluded = in_array($comp_id, $excluded_components);
                                        ?>
                                            <label class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                                <div class="d-flex align-items-center gap-2"><img src="<?= htmlspecialchars(($comp['image_url'] ?? '') ?: '../assets/images/item-placeholder.svg') ?>" alt="<?= htmlspecialchars($comp['component_name']) ?>" class="catalog-component-image" onerror="this.src='../assets/images/item-placeholder.svg'"><div class="form-check">
                                                    <span class="form-check-label">
                                                        <?= htmlspecialchars($comp['component_name']) ?>
                                                        <?php if (!(int) $comp['is_available']): ?><span class="badge bg-danger">ของย่อยหมด</span><?php endif; ?>
                                                    </span>
                                                    </div>
                                                </div>
                                                <div class="catalog-component-quantity">
                                                    <input class="form-control component-choice" type="number" name="component_quantities[<?= $comp_id ?>]" id="comp_<?= $comp_id ?>" min="0" step="1" max="<?= (int) $comp['is_available'] ? (int) $comp['piece_available'] : 0 ?>" value="<?= !(int) $comp['is_available'] ? 0 : (int) ($selected_quantities !== null ? ($selected_quantities[$comp_id] ?? 0) : ($is_excluded ? 0 : min((int) $comp['quantity_per_set'], (int) $comp['piece_available']))) ?>" data-unavailable="<?= !(int) $comp['is_available'] || (int) $comp['piece_available'] === 0 ? '1' : '0' ?>" aria-label="จำนวน <?= htmlspecialchars($comp['component_name']) ?>" <?= !(int) $comp['is_available'] || (int) $comp['piece_available'] === 0 ? 'disabled' : '' ?> required>
                                                    <small class="text-muted">เหลือ <?= (int) $comp['is_available'] ? (int) $comp['piece_available'] : 0 ?> <?= htmlspecialchars($comp['unit'] ?? 'ชิ้น') ?> (<?= (int) $comp['quantity_per_set'] ?>/ชุด)</small>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                                    <button type="submit" class="btn btn-success fw-bold">บันทึกรายการ</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endwhile; ?>
    </div>
    <?php if ($item_count): ?><nav class="catalog-pagination" aria-label="หน้ารายการอุปกรณ์"><span>แสดง <?= $offset + 1 ?>–<?= min($offset + $pageSize, $item_count) ?> จาก <?= $item_count ?> รายการ</span><div>
    <?php if ($page > 1): ?><a class="btn btn-outline-primary" href="<?= app_escape($catalogUrl(['page'=>$page-1])) ?>">← ก่อนหน้า</a><?php endif; ?>
    <span>หน้า <?= $page ?> / <?= $pageCount ?></span>
    <?php if ($page < $pageCount): ?><a class="btn btn-outline-primary" href="<?= app_escape($catalogUrl(['page'=>$page+1])) ?>">ถัดไป →</a><?php endif; ?>
    </div></nav><?php endif; ?>
    </section></div>
    <div class="catalog-cart-bar"><span>เลือกแล้ว <strong><?= (int) $total_cart_count ?></strong> ชิ้น / ชุด</span><a href="confirm.php" class="btn btn-success">เลือกวันและส่งคำขอ →</a></div>
</div>

<button type="button" class="borrow-back-to-top" aria-label="กลับขึ้นด้านบน" title="กลับขึ้นด้านบน" hidden>
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 19V5M5 12l7-7 7 7" />
    </svg>
</button>
<script src="../assets/js/borrow-back-to-top.js" defer></script><script src="../assets/js/user-dashboard.js" defer></script>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script>
// Keep the catalogue position after cart updates, including the set modal.
(function () {
    var key = 'profix:borrow-scroll:' + location.pathname + location.search;
    var saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(key));
        sessionStorage.removeItem(key);
    } catch (error) { /* Storage may be unavailable in private browsing. */ }
    if (saved && Number.isFinite(saved.y) && saved.y >= 0
        && Number.isFinite(saved.at) && Date.now() - saved.at < 60000) {
        var restore = function () { window.scrollTo({top: saved.y, left: 0, behavior: 'instant'}); };
        requestAnimationFrame(restore);
        // Images can change the document height while the page loads.
        if (document.readyState !== 'complete') window.addEventListener('load', restore, {once: true});
    }
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        if (!form.querySelector('[name="action"]')) return;
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            try {
                sessionStorage.setItem(key, JSON.stringify({y: window.scrollY, at: Date.now()}));
            } catch (error) { /* Cart submission still works without storage. */ }
        });
    });
})();
document.querySelectorAll('.quantity-form').forEach(function (form) {
    var input = form.querySelector('[name="quantity"]');
    var save = form.querySelector('.quantity-save');
    save.hidden = true;
    input.addEventListener('focus', function () { input.select(); });
    input.addEventListener('input', function () { save.hidden = input.value === input.defaultValue; });
});
document.querySelectorAll('.set-mode').forEach(function (radio) {
    function updateComponentChoices() {
        var container = document.getElementById(radio.dataset.target);
        if (!container) return;
        var selectedMode = document.querySelector('input[name="borrow_mode"][data-target="' + radio.dataset.target + '"]:checked');
        var standardQuantity = radio.closest('form').querySelector('.standard-set-quantity');
        standardQuantity.disabled = !selectedMode || selectedMode.value !== 'full' || Number(standardQuantity.max) < 1;
        container.querySelectorAll('.component-choice').forEach(function (checkbox) {
            checkbox.disabled = checkbox.dataset.unavailable === '1' || !selectedMode || selectedMode.value !== 'components';
        });
        container.classList.toggle('opacity-50', !selectedMode || selectedMode.value !== 'components');
    }
    radio.addEventListener('change', updateComponentChoices);
    updateComponentChoices();
});
</script>
</body>
</html>
