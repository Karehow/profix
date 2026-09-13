<?php
require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$current_user = require_roles($conn, ['user'], 'index.php');
$user_id = (int) $current_user['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_return'])) {
    require_csrf();
    $request_id = (int) ($_POST['request_id'] ?? 0);
    try {
        borrow_request_return($conn, $request_id, $user_id);
        flash_set('success', 'แจ้งเจ้าหน้าที่ว่าพร้อมส่งคืนเรียบร้อยแล้ว');
    } catch (Throwable $e) {
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถแจ้งคืนได้');
    }
    app_redirect('return.php');
}

$requests_stmt = mysqli_prepare($conn, "SELECT br.* FROM borrow_requests br WHERE br.user_id = ? AND br.status IN ('borrowed', 'return_requested') ORDER BY br.expected_return_date ASC");
mysqli_stmt_bind_param($requests_stmt, 'i', $user_id);
mysqli_stmt_execute($requests_stmt);
$requests = mysqli_stmt_get_result($requests_stmt);
$flash = flash_take();
$inbox = ['unread' => (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications WHERE user_id=$user_id AND is_read=0"))[0]];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แจ้งคืนสิ่งของ</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/user-dashboard.css?v=3">
<link rel="stylesheet" href="../assets/css/borrow-return.css?v=1">
</head>
<body class="app-ui temple-dashboard return-page">
<?php $uiPage = 'user/return.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container return-main">
    <header class="return-hero"><span class="return-emblem" aria-hidden="true"><svg class="ui-icon"><use href="../assets/images/ui-icons.svg#return"></use></svg></span><div><h1>แจ้งคืนสิ่งของ</h1><p>กรุณานำสิ่งของมาส่งให้เจ้าหน้าที่ตรวจรับตามนัดหมาย</p></div></header>
    <div class="return-toolbar">
        <?php if ($flash): ?><div class="return-feedback alert alert-<?= app_escape($flash['type']) ?>" role="alert"><?= app_escape($flash['message']) ?></div><?php else: ?><p class="return-guide">ตรวจสอบรายการ แล้วแจ้งเจ้าหน้าที่เมื่อพร้อมนำสิ่งของมาคืน</p><?php endif; ?>
        <nav aria-label="ลิงก์ที่เกี่ยวข้อง"><a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a><a href="history.php" class="btn btn-outline-primary">ประวัติ</a></nav>
    </div>
    <?php if (!$requests || mysqli_num_rows($requests) === 0): ?>
        <div class="card shadow-sm border-0"><div class="card-body text-center py-5"><div class="fs-1">✓</div><h5 class="mt-2">ไม่มีรายการที่ต้องคืนในขณะนี้</h5><a href="history.php" class="btn btn-outline-primary mt-2">ดูประวัติการยืม</a></div></div>
    <?php endif; ?>
    <?php while ($request = mysqli_fetch_assoc($requests)): ?>
        <section class="card return-request shadow-sm border-0 mb-3">
            <?php $request_id = (int) $request['request_id']; ?>
            <div class="card-header bg-white d-flex justify-content-between align-items-center"><div><strong>คำขอยืม #<?= $request_id ?></strong><div class="small text-muted">กำหนดคืน <?= $request['expected_return_date'] ? date('d/m/Y', strtotime($request['expected_return_date'])) : '-' ?></div></div><span class="badge bg-<?= $request['status'] === 'borrowed' ? 'primary' : 'info text-dark' ?>"><?= $request['status'] === 'borrowed' ? 'กำลังยืม' : 'รอตรวจรับคืน' ?></span></div>
            <div class="card-body"><div class="return-items">
            <?php
            $items_stmt = mysqli_prepare($conn, 'SELECT i.item_name, i.image_url, i.is_set, bi.quantity_borrowed, bi.quantity_returned,
                (SELECT SUM(bic.quantity_borrowed) FROM borrow_item_components bic WHERE bic.borrow_item_id=bi.borrow_item_id) AS component_pieces
                FROM borrow_items bi JOIN items i ON i.item_id = bi.item_id WHERE bi.request_id = ?');
            mysqli_stmt_bind_param($items_stmt, 'i', $request_id);
            mysqli_stmt_execute($items_stmt);
            $items = mysqli_stmt_get_result($items_stmt);
            if (mysqli_num_rows($items) === 0): ?><p class="return-empty">ไม่มีรายการอุปกรณ์ในคำขอนี้</p><?php endif;
            while ($item = mysqli_fetch_assoc($items)): ?>
                <div class="return-item"><img src="<?= app_escape($item['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt="" loading="lazy"><strong><?= app_escape($item['item_name']) ?></strong><span class="return-quantity"><?= $item['component_pieces'] !== null ? (int) $item['component_pieces'] . ' ชิ้น (ของย่อย)' : (int) $item['quantity_borrowed'] . ((int) $item['is_set'] === 1 ? ' ชุด' : ' ชิ้น') ?><?php if (!(int) $item['is_set']): ?><small class="d-block">ตรวจรับแล้ว <?= (int) $item['quantity_returned'] ?> ชิ้น · ค้างคืน <?= max(0, (int) $item['quantity_borrowed']-(int) $item['quantity_returned']) ?> ชิ้น</small><?php endif; ?></span></div>
            <?php endwhile; mysqli_stmt_close($items_stmt); ?></div>
            <?php if ($request['status'] === 'borrowed'): ?><form method="post" class="return-submit" onsubmit="return confirm('ยืนยันว่าพร้อมนำสิ่งของทั้งหมดมาส่งคืนให้เจ้าหน้าที่ตรวจรับ?')"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="request_id" value="<?= $request_id ?>"><p>การแจ้งคืนจะเสร็จสมบูรณ์เมื่อเจ้าหน้าที่ตรวจรับสิ่งของแล้ว</p><button name="request_return" class="btn btn-success">แจ้งว่าพร้อมส่งคืน →</button></form><?php else: ?><div class="return-awaiting" role="status"><span aria-hidden="true">✓</span><p>แจ้งคืนแล้ว โปรดนำสิ่งของไปส่งเจ้าหน้าที่เพื่อตรวจรับ</p></div><?php endif; ?></div>
        </section>
    <?php endwhile; mysqli_stmt_close($requests_stmt); ?>
    <div class="return-closing"><p>ร่วมดูแลสิ่งของของวัด<br>คืนให้ครบ เพื่อให้ชุมชนได้ใช้ร่วมกัน</p><span aria-hidden="true">— ❧ —</span></div>
</main>
<footer class="dashboard-footer">© <?= date('Y') ?> ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด <span>ร่วมทำบุญ ร่วมดูแลด้วยใจ</span></footer>
<script src="../assets/js/user-dashboard.js" defer></script>
</body></html>
