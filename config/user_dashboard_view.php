<?php
if (!isset($user, $inbox, $active_requests)) { http_response_code(404); exit; }
$categories = mysqli_fetch_all(mysqli_query($conn, "SELECT c.category_id, c.category_name, COUNT(i.item_id) AS item_count,
    (SELECT image_url FROM items p WHERE p.category_id=c.category_id AND p.is_active=1 AND p.image_url IS NOT NULL AND p.image_url<>'' ORDER BY p.item_id LIMIT 1) AS image_url
    FROM categories c LEFT JOIN items i ON i.category_id=c.category_id AND i.is_active=1
    GROUP BY c.category_id, c.category_name ORDER BY c.category_id"), MYSQLI_ASSOC);
$inventory = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total, COALESCE(SUM(available_quantity > 0),0) AS available FROM items WHERE is_active=1'));
$dashboardIcon = static function (string $name): void { ?><svg class="ui-icon" aria-hidden="true"><use href="../assets/images/ui-icons.svg#<?= app_escape($name) ?>"></use></svg><?php };
?>
<!doctype html>
<html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>หน้าหลักผู้ใช้งาน · ระบบยืมคืนสิ่งของวัด</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/temple-theme.css">
<link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/user-dashboard.css?v=3">
<link rel="stylesheet" href="../assets/css/notification-popup.css?v=2">
</head><body class="app-ui temple-dashboard">
<?php $uiPage = 'user/home.php'; require __DIR__ . '/page_shell.php'; ?>
<main class="container dashboard-main">
<?php if (isset($_GET['request_submitted'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><strong>ส่งคำขอยืมเรียบร้อยแล้ว</strong> <?php if (!empty($_GET['request_id'])): ?>เลขที่คำขอ #<?= (int) $_GET['request_id'] ?><?php endif; ?><div>ติดตามสถานะและรอเจ้าหน้าที่อนุมัติได้ในรายการด้านล่าง</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button></div>
<?php endif; ?>
<section class="dashboard-welcome" aria-labelledby="welcomeTitle">
<span class="dashboard-emblem"><?php $dashboardIcon('temple'); ?></span>
<div><p class="dashboard-eyebrow">ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด</p><h1 id="welcomeTitle">ยินดีต้อนรับเข้าสู่ระบบ</h1><p>สวัสดี <?= app_escape($user['first_name'] . ' ' . $user['last_name']) ?></p><em>ใช้งานง่าย สะดวก รวดเร็ว เพื่อกิจกรรมของชุมชน</em></div>
</section>
<form action="borrow.php" method="get" class="dashboard-search" role="search">
<label class="visually-hidden" for="dashboardSearch">ค้นหาอุปกรณ์</label><input id="dashboardSearch" name="q" type="search" class="form-control" placeholder="ค้นหาอุปกรณ์ที่ต้องการ…">
<label class="visually-hidden" for="dashboardCategory">หมวดหมู่</label><select id="dashboardCategory" name="category_id" class="form-select"><option value="0">ทุกหมวดหมู่</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>"><?= app_escape($category['category_name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">ค้นหาอุปกรณ์</button>
</form>
<nav class="dashboard-actions" aria-label="เมนูลัด">
<?php foreach ([['confirm.php','box','รายการที่เลือก','ตรวจรายการและส่งคำขอยืม','green'],['borrow.php','cart','เลือกยืมอุปกรณ์','ดูรายการและจำนวนที่พร้อมให้ยืม','gold'],['history.php','list','ประวัติการยืม–คืน','ติดตามคำขอและการคืนอุปกรณ์','blue'],['return.php','return','แจ้งคืนสิ่งของ','แจ้งพร้อมคืนและนำสิ่งของส่งให้เจ้าหน้าที่','purple']] as [$link,$icon,$title,$description,$color]): ?>
<a class="dashboard-action tone-<?= $color ?>" href="<?= $link ?>"><span class="dashboard-action-icon"><?php $dashboardIcon($icon); ?></span><div><h2><?= $title ?></h2><p><?= $description ?></p></div><span class="dashboard-arrow" aria-hidden="true">›</span></a>
<?php endforeach; ?></nav>
<div class="dashboard-columns"><div class="dashboard-primary">
<section class="dashboard-panel" aria-labelledby="categoryTitle"><div class="dashboard-panel-heading"><h2 id="categoryTitle"><?php $dashboardIcon('box'); ?> หมวดหมู่อุปกรณ์</h2><a href="borrow.php">ดูทั้งหมด →</a></div>
<div class="dashboard-categories"><?php foreach ($categories as $category): ?>
<a class="dashboard-category" href="borrow.php?category_id=<?= (int) $category['category_id'] ?>"><img src="<?= app_escape($category['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt="" loading="lazy"><strong><?= app_escape($category['category_name']) ?></strong><span><?= (int) $category['item_count'] ?> รายการ <b aria-hidden="true">›</b></span></a>
<?php endforeach; ?><?php if (!$categories): ?><p class="text-muted">ยังไม่มีหมวดหมู่อุปกรณ์ <a href="borrow.php">ดูอุปกรณ์ทั้งหมด</a></p><?php endif; ?></div></section>
<section class="dashboard-panel" aria-labelledby="recentTitle"><div class="dashboard-panel-heading"><h2 id="recentTitle"><?php $dashboardIcon('clock'); ?> รายการยืม–คืนล่าสุด</h2><a href="history.php">ดูทั้งหมด →</a></div>
<?php require __DIR__ . '/user_dashboard_requests.php'; ?>
</section>
</div><aside class="dashboard-secondary" aria-label="ข้อมูลสรุป">
<section class="dashboard-panel dashboard-summary"><h2><?php $dashboardIcon('box'); ?> สรุปอุปกรณ์ในระบบ</h2>
<dl><div><dt>พร้อมให้ยืม</dt><dd><?= (int) $inventory['available'] ?> <small>รายการ</small></dd></div><div><dt>ไม่มีของพร้อมให้ยืม</dt><dd><?= (int) $inventory['total'] - (int) $inventory['available'] ?> <small>รายการ</small></dd></div><div><dt>ทั้งหมด</dt><dd><?= (int) $inventory['total'] ?> <small>รายการ</small></dd></div></dl><p class="dashboard-caption">นับตามรายการอุปกรณ์ ไม่ใช่จำนวนชิ้น</p>
</section>
<section class="dashboard-panel dashboard-summary"><h2><?php $dashboardIcon('list'); ?> สถานะคำขอของคุณ</h2><dl><div><dt>รออนุมัติ</dt><dd><?= (int) $pending_count ?></dd></div><div><dt>อนุมัติแล้ว รอรับของ</dt><dd><?= (int) $approved_count ?></dd></div><div><dt>กำลังยืม / รอตรวจคืน</dt><dd><?= (int) $borrowed_count ?></dd></div></dl><a class="btn btn-outline-primary w-100" href="return.php">แจ้งคืนอุปกรณ์ →</a></section>
<section class="dashboard-panel dashboard-profile"><h2><?php $dashboardIcon('user'); ?> ข้อมูลผู้ใช้งาน</h2><div class="dashboard-person"><img src="<?= app_escape($_SESSION['profile_image_url'] ?? '../assets/images/profile-placeholder.svg') ?>" alt="รูปโปรไฟล์"><div><strong><?= app_escape($user['first_name'] . ' ' . $user['last_name']) ?></strong><small>ผู้ยืม</small></div></div><a href="profile.php">ข้อมูลส่วนตัว →</a><a href="compensation.php">คืนของทดแทน →</a><a href="history.php">ประวัติการยืม–คืน →</a></section>
</aside></div>
<?php require __DIR__ . '/notification_inbox_view.php'; ?>
</main><footer class="dashboard-footer">© <?= date('Y') ?> ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด <span>ร่วมทำบุญ ด้วยการแบ่งปัน</span></footer>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/user-dashboard.js" defer></script>
<script src="../assets/js/notification-popup.js?v=1" defer></script>
</body></html>
