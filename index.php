<?php require_once __DIR__ . '/config/public_catalog.php'; ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ค้นหาสิ่งของของวัด ตรวจสอบจำนวนคงเหลือ และเข้าสู่ระบบเพื่อส่งคำขอยืม">
    <title>ระบบยืมคืนสิ่งของวัด</title>
    <link rel="stylesheet" href="bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/temple-theme.css">
    <?php require __DIR__ . '/config/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/borrow-catalog.css?v=1">
    <link rel="stylesheet" href="assets/css/public-catalog.css?v=1">
</head>
<body class="public-page borrow-page">
<a class="public-skip" href="#catalog">ข้ามไปยังรายการสิ่งของ</a>
<header class="public-header">
    <nav class="navbar navbar-expand-lg navbar-dark public-container" aria-label="เมนูหลัก">
        <a class="public-brand" href="index.php"><svg aria-hidden="true"><use href="assets/images/ui-icons.svg#temple"></use></svg><span>ระบบยืมคืนสิ่งของวัด<small>แบ่งปันสิ่งของ เพื่อกิจกรรมของชุมชน</small></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="เปิดเมนู"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="publicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">
                <li class="nav-item"><a class="nav-link" href="#catalog">สิ่งของสำหรับยืม</a></li>
                <li class="nav-item"><a class="nav-link" href="#how-it-works">วิธีการยืม</a></li>
                <li class="nav-item"><a class="nav-link" href="config/login.php">เจ้าหน้าที่</a></li>
                <li class="nav-item"><a class="public-account" href="<?= $escape($borrower_url) ?>"><?= $escape($borrower_label) ?> →</a></li>
            </ul>
        </div>
    </nav>
</header>
<main class="public-container public-main">
    <section class="catalog-hero public-hero" aria-labelledby="welcomeTitle">
        <span class="public-kicker">ยินดีต้อนรับสู่ระบบยืมคืนสิ่งของวัด</span>
        <h1 id="welcomeTitle">เลือกสิ่งของที่ต้องการ<br>เพื่อกิจกรรมของชุมชน</h1>
        <p>ค้นหาสิ่งของ ตรวจสอบจำนวนที่พร้อมให้ยืม<br>และติดตามการยืมคืนได้ในที่เดียว</p>
        <div class="public-actions"><a class="btn btn-primary" href="#catalog">ดูสิ่งของสำหรับยืม →</a><?php if (!$is_borrower): ?><a class="btn btn-outline-primary" href="user/index.php?mode=register">สมัครสมาชิก</a><?php else: ?><a class="btn btn-outline-primary" href="user/history.php">ติดตามการยืมของฉัน</a><?php endif; ?></div>
    </section>
    <section id="catalog" aria-labelledby="catalogTitle">
        <form class="catalog-search" action="index.php#catalog" method="get" role="search">
            <label class="visually-hidden" for="publicSearch">ค้นหาชื่อสิ่งของ</label>
            <input class="form-control" type="search" id="publicSearch" name="q" value="<?= $escape($search) ?>" placeholder="ค้นหาสิ่งของที่ต้องการ…" maxlength="150">
            <label class="visually-hidden" for="publicCategory">หมวดหมู่</label>
            <select class="form-select" id="publicCategory" name="category_id"><option value="0">ทุกหมวดหมู่</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>" <?= $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>><?= $escape($category['category_name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary" type="submit">ค้นหา</button>
            <?php if ($search !== '' || $categoryId): ?><a class="catalog-clear" href="index.php#catalog">ล้างการค้นหาและตัวกรอง</a><?php endif; ?>
        </form>
        <div class="catalog-layout">
            <nav class="catalog-categories" aria-label="หมวดหมู่สิ่งของ">
                <a href="<?= $escape($catalogUrl(['category_id' => 0])) ?>" <?= !$categoryId ? 'aria-current="page"' : '' ?>><span>ทั้งหมด<small><?= $totalItems ?> รายการ</small></span><span aria-hidden="true">›</span></a>
                <?php foreach ($categories as $category): ?><a href="<?= $escape($catalogUrl(['category_id' => (int) $category['category_id']])) ?>" <?= $categoryId === (int) $category['category_id'] ? 'aria-current="page"' : '' ?>><span><?= $escape($category['category_name']) ?><small><?= (int) $category['item_count'] ?> รายการ</small></span><span aria-hidden="true">›</span></a><?php endforeach; ?>
            </nav>
            <div class="catalog-results">
                <div class="catalog-toolbar"><h2 id="catalogTitle">สิ่งของสำหรับยืม <small>(<?= $resultCount ?> รายการ)</small></h2><span class="public-note">เจ้าหน้าที่ตรวจนับจำนวนอีกครั้งก่อนส่งมอบ</span></div>
                <div class="catalog-grid">
                    <?php foreach ($items as $item): ?>
                    <article class="catalog-item"><div class="card h-100">
                        <div class="catalog-image"><img class="card-img-top" src="<?= $escape($item['image']) ?>" alt="<?= $escape($item['item_name']) ?>" loading="lazy"><span class="catalog-stock <?= !$item['ready'] ? 'is-empty' : ($item['partial'] ? 'is-partial' : '') ?>"><?= !$item['ready'] ? 'ไม่พร้อมให้ยืม' : ($item['partial'] ? 'เลือกยืมของย่อยได้' : 'พร้อมให้ยืม') ?></span></div>
                        <div class="card-body d-flex flex-column"><span class="public-category-tag"><?= $escape($item['category_name'] ?? 'อื่น ๆ') ?></span><h3 class="card-title"><?= $escape($item['item_name']) ?></h3><p class="card-text text-muted"><?= $escape($item['description'] ?? '') ?></p>
                            <p class="public-quantity mt-auto">คงเหลือ <?= (int) $item['quantity'] ?> <?= (int) $item['is_set'] ? 'ชุดครบ' : 'ชิ้น' ?></p>
                            <?php if ($item['ready']): ?><a class="btn btn-primary w-100" href="<?= $escape($is_borrower ? 'user/borrow.php?q=' . rawurlencode($item['item_name']) : 'user/index.php') ?>"><?= $is_borrower ? 'เลือกยืมสิ่งของ' : 'เข้าสู่ระบบเพื่อยืม' ?></a><?php else: ?><span class="btn btn-light disabled w-100" aria-disabled="true">ยังไม่พร้อมให้ยืม</span><?php endif; ?>
                        </div>
                    </div></article>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><div class="public-empty col-12"><h3>ไม่พบสิ่งของ</h3><p>ลองเปลี่ยนคำค้นหาหรือเลือกหมวดหมู่อื่น</p><a class="btn btn-outline-primary" href="index.php#catalog">ดูสิ่งของทั้งหมด</a></div><?php endif; ?>
                </div>
                <nav class="catalog-pagination" aria-label="หน้ารายการสิ่งของ"><span><?= $resultCount ? 'แสดง ' . ($offset + 1) . '–' . min($offset + 12, $resultCount) . ' จาก ' . $resultCount . ' รายการ' : '0 รายการ' ?></span><div><?php if ($page > 1): ?><a class="btn btn-outline-primary" href="<?= $escape($catalogUrl(['page' => $page - 1])) ?>">← ก่อนหน้า</a><?php endif; ?><span>หน้า <?= $page ?> / <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="btn btn-outline-primary" href="<?= $escape($catalogUrl(['page' => $page + 1])) ?>">ถัดไป →</a><?php endif; ?></div></nav>
            </div>
        </div>
    </section>
    <section class="public-guide" id="how-it-works" aria-labelledby="guideTitle">
        <span class="public-kicker">เริ่มต้นได้ง่าย ๆ</span><h2 id="guideTitle">ยืมและคืนใน 3 ขั้นตอน</h2>
        <div class="public-steps" id="services">
            <article><span class="public-step-number">1</span><h3>เลือกของและส่งคำขอ</h3><p>เข้าสู่ระบบ เลือกสิ่งของ ระบุจำนวนและวันที่ต้องการใช้ แล้วส่งคำขอยืม</p></article>
            <article><span class="public-step-number">2</span><h3>ตรวจสอบและรับของ</h3><p>ติดตามผลอนุมัติ หากจำนวนเปลี่ยนให้ยืนยันรายการใหม่ แล้วตรวจนับร่วมกับเจ้าหน้าที่ตอนรับของ</p></article>
            <article><span class="public-step-number">3</span><h3>แจ้งคืนและส่งมอบ</h3><p>แจ้งพร้อมคืนในระบบ นำของให้เจ้าหน้าที่ตรวจรับ และติดตามรายการที่ยังค้างคืน</p></article>
        </div>
        <div class="public-guide-cta"><div><h3>พร้อมใช้งานแล้ว?</h3><p>เข้าสู่ระบบเพื่อเลือกสิ่งของและติดตามคำขอของคุณ</p></div><a class="btn btn-primary" href="<?= $escape($borrower_url) ?>"><?= $escape($borrower_label) ?> →</a></div>
    </section>
</main>
<footer class="public-footer"><div class="public-container"><strong>ระบบยืมคืนสิ่งของวัด</strong><span>ร่วมดูแลสิ่งของ ใช้อย่างรู้คุณค่า คืนตรงเวลา</span></div></footer>
<script src="bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
