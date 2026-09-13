<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/notification_inbox.php';

ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$inbox = notification_inbox($conn, (int) $currentUser['user_id']);

function dashboard_count($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) return 0;
    $row = mysqli_fetch_row($result);
    return (int) ($row[0] ?? 0);
}

$pending_count = dashboard_count($conn, "SELECT COUNT(*) FROM borrow_requests WHERE status = 'pending_approval'");
$approved_count = dashboard_count($conn, "SELECT COUNT(*) FROM borrow_requests WHERE status = 'approved'");
$return_count = dashboard_count($conn, "SELECT COUNT(*) FROM borrow_requests WHERE status = 'return_requested'");
$overdue_count = dashboard_count($conn, "SELECT COUNT(*) FROM borrow_requests WHERE status IN ('borrowed', 'return_requested') AND expected_return_date < NOW()");
$damaged_count = dashboard_count($conn, "SELECT COUNT(*) FROM return_inspections WHERE (damaged_quantity > 0 OR lost_quantity > 0) AND is_resolved = 0");
$maintenance_count = dashboard_count($conn, "SELECT COUNT(*) FROM maintenance_records WHERE status IN ('waiting', 'repairing')");
$total_items = dashboard_count($conn, 'SELECT COUNT(*) FROM items WHERE is_active = 1');
$low_stock_count = dashboard_count($conn, 'SELECT COUNT(*) FROM items WHERE is_active = 1 AND available_quantity <= 0');

$recent_pending = mysqli_query($conn, "SELECT br.request_id, br.created_at, br.expected_return_date,
                                              u.first_name, u.last_name, u.phone_number
                                       FROM borrow_requests br
                                       INNER JOIN users u ON u.user_id = br.user_id
                                       WHERE br.status = 'pending_approval'
                                       ORDER BY br.created_at ASC LIMIT 5");
$recent_pending_count = $recent_pending ? mysqli_num_rows($recent_pending) : 0;
$display_name = trim((string) ($_SESSION['user_name'] ?? '')) ?: 'เจ้าหน้าที่';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลักเจ้าหน้าที่ - ระบบยืมคืนของวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <style>
        .staff-hero { border-radius: 20px; color: #fff; }
        .staff-hero .hero-copy { max-width: 720px; }
        .staff-hero .btn { white-space: nowrap; }
        .dashboard-card { min-height: 132px; }
        .metric-icon, .menu-icon { display: inline-grid; place-items: center; width: 46px; height: 46px; border-radius: 13px; background: #eef6f0; font-size: 1.4rem; }
        .metric-number { font-size: clamp(1.65rem, 3vw, 2.25rem); line-height: 1; }
        .task-card { transition: transform .2s ease, box-shadow .2s ease; }
        .task-card:hover { transform: translateY(-3px); }
        .task-card .card-body { min-height: 176px; }
        .section-title { color: #173f2b; }
        .empty-state { padding: 3rem 1rem; }
        @media (max-width: 991.98px) { .navbar-nav { padding-top: .75rem; align-items: stretch !important; } .navbar-nav .btn { display: block; margin-top: .4rem; } }
    </style>
<?php require __DIR__ . '/../config/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/staff-banner.css?v=1"></head>
<body class="app-ui bg-light pb-5">
<?php $uiPage = 'staff/home.php'; require __DIR__ . '/../config/page_shell.php'; ?>


<main class="container">
<?php require __DIR__ . '/../config/notification_inbox_view.php'; ?>
    <section class="staff-banner staff-hero p-4 p-lg-5 mb-4 shadow-sm">
        <div class="row align-items-center g-4">
            <div class="col-lg-8 hero-copy">
                <div class="small text-uppercase fw-bold text-warning mb-2">Staff dashboard</div>
                <h1 class="h2 fw-bold mb-2">สวัสดี คุณ<?= app_escape($display_name) ?></h1>
                <p class="mb-0 text-white-50">ติดตามคำขอยืม ตรวจรับคืน และงานคลังที่ต้องดำเนินการได้จากหน้าเดียว</p>
            </div>
            <div class="col-lg-4 text-lg-end"><a href="requests.php" class="btn btn-warning btn-lg fw-bold shadow-sm">ตรวจคำขอรออนุมัติ <span class="badge bg-dark ms-1"><?= $pending_count ?></span></a></div>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="summary-title">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div><h2 id="summary-title" class="h5 fw-bold section-title mb-1">ภาพรวมงานวันนี้</h2><p class="small text-muted mb-0">รายการที่ควรตรวจสอบและดำเนินการ</p></div>
            <span class="small text-muted"><?= date('d/m/Y') ?></span>
        </div>
        <div class="row g-3">
            <div class="col-6 col-lg-4 col-xl"><a href="requests.php?status=pending_approval" class="text-decoration-none text-dark"><div class="card metric-card dashboard-card h-100 border-0 shadow-sm border-start border-warning border-4"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="small text-muted mb-2">รออนุมัติ</div><div class="metric-number fw-bold text-warning"><?= $pending_count ?></div></div><span class="metric-icon">📋</span></div></div></div></a></div>
            <div class="col-6 col-lg-4 col-xl"><a href="requests.php?status=approved" class="text-decoration-none text-dark"><div class="card metric-card dashboard-card h-100 border-0 shadow-sm border-start border-primary border-4"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="small text-muted mb-2">รอส่งมอบ</div><div class="metric-number fw-bold text-primary"><?= $approved_count ?></div></div><span class="metric-icon">🤝</span></div></div></div></a></div>
            <div class="col-6 col-lg-4 col-xl"><a href="return_check.php" class="text-decoration-none text-dark"><div class="card metric-card dashboard-card h-100 border-0 shadow-sm border-start border-info border-4"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="small text-muted mb-2">แจ้งคืนแล้ว</div><div class="metric-number fw-bold text-info"><?= $return_count ?></div></div><span class="metric-icon">↩️</span></div></div></div></a></div>
            <div class="col-6 col-lg-4 col-xl"><a href="requests.php" class="text-decoration-none text-dark"><div class="card metric-card dashboard-card h-100 border-0 shadow-sm border-start border-danger border-4"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="small text-muted mb-2">เกินกำหนดคืน</div><div class="metric-number fw-bold text-danger"><?= $overdue_count ?></div></div><span class="metric-icon">⏰</span></div></div></div></a></div>
            <div class="col-6 col-lg-4 col-xl"><a href="maintenance.php" class="text-decoration-none text-dark"><div class="card metric-card dashboard-card h-100 border-0 shadow-sm border-start border-secondary border-4"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="small text-muted mb-2">กำลังซ่อม/รอซ่อม</div><div class="metric-number fw-bold text-secondary"><?= $maintenance_count ?></div></div><span class="metric-icon">🔧</span></div></div></div></a></div>
        </div>
    </section>

    <section class="mb-4" aria-labelledby="menu-title">
        <h2 id="menu-title" class="h5 fw-bold section-title mb-3">เมนูจัดการ</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-3"><a href="requests.php" class="text-decoration-none text-dark"><div class="card action-card task-card h-100 border-0 shadow-sm"><div class="card-body p-4"><span class="menu-icon">✅</span><h3 class="h5 fw-bold mt-3">อนุมัติคำขอยืม</h3><p class="small text-muted mb-0">ตรวจข้อมูลผู้ยืม สถานที่ใช้งาน และอนุมัติหรือปฏิเสธคำขอ</p></div></div></a></div>
            <div class="col-md-6 col-xl-3"><a href="return_check.php" class="text-decoration-none text-dark"><div class="card action-card task-card h-100 border-0 shadow-sm"><div class="card-body p-4"><span class="menu-icon">🔍</span><h3 class="h5 fw-bold mt-3">ตรวจรับคืน</h3><p class="small text-muted mb-0">บันทึกจำนวนและสภาพสิ่งของ พร้อมจัดการรายการชำรุดหรือสูญหาย</p></div></div></a></div>
            <div class="col-md-6 col-xl-3"><a href="items.php" class="text-decoration-none text-dark"><div class="card action-card task-card h-100 border-0 shadow-sm"><div class="card-body p-4"><span class="menu-icon">📦</span><h3 class="h5 fw-bold mt-3">คลังสิ่งของ</h3><p class="small text-muted mb-0">จัดการสิ่งของ <?= $total_items ?> ชนิด และตรวจรายการหมดคลัง <?= $low_stock_count ?> รายการ</p></div></div></a></div>
            <div class="col-md-6 col-xl-3"><a href="compensation.php" class="text-decoration-none text-dark"><div class="card action-card task-card h-100 border-0 shadow-sm"><div class="card-body p-4"><span class="menu-icon">📦</span><h3 class="h5 fw-bold mt-3">รับของทดแทน</h3><p class="small text-muted mb-0">ติดตามของชำรุดหรือสูญหาย <?= $damaged_count ?> รายการ และบันทึกการชดใช้</p></div></div></a></div>
        </div>
    </section>

    <section class="card border-0 shadow-sm" aria-labelledby="pending-title">
        <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div><h2 id="pending-title" class="h5 fw-bold mb-1">คำขอที่รอตรวจสอบ</h2><div class="small text-muted">เรียงจากคำขอที่เข้ามาก่อน</div></div>
            <a href="requests.php" class="btn btn-sm btn-outline-primary">ดูคำขอทั้งหมด</a>
        </div>
        <div class="card-body p-0">
            <?php if ($recent_pending_count > 0): ?>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th class="ps-3">เลขที่</th><th>ผู้ขอยืม</th><th>ติดต่อ</th><th>ส่งคำขอเมื่อ</th><th>กำหนดคืน</th><th class="text-end pe-3">ดำเนินการ</th></tr></thead>
                    <tbody><?php while ($request = mysqli_fetch_assoc($recent_pending)): ?><tr>
                        <td class="ps-3 fw-bold">#<?= (int) $request['request_id'] ?></td>
                        <td><?= app_escape(trim($request['first_name'] . ' ' . $request['last_name'])) ?></td>
                        <td><?= app_escape($request['phone_number'] ?: '-') ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></td>
                        <td><?= $request['expected_return_date'] ? date('d/m/Y', strtotime($request['expected_return_date'])) : '-' ?></td>
                        <td class="text-end pe-3"><a href="requests.php" class="btn btn-sm btn-warning">ตรวจสอบ</a></td>
                    </tr><?php endwhile; ?></tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state text-center"><div class="fs-1 mb-2">🎉</div><div class="fw-bold">ไม่มีคำขอที่รออนุมัติ</div><div class="small text-muted">งานส่วนนี้เรียบร้อยแล้วในขณะนี้</div></div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notification-popup.js?v=1" defer></script>
</body>
</html>
