<?php
require_once __DIR__ . '/../config/notification_inbox.php';

ensure_feature_tables($conn);
$current_admin = require_roles($conn, ['admin'], '../config/login.php');
$inbox = notification_inbox($conn, (int) $current_admin['user_id']);

function count_rows($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    $row = $result ? mysqli_fetch_assoc($result) : ['total' => 0];
    return (int) $row['total'];
}

function scalar_value($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    $row = $result ? mysqli_fetch_assoc($result) : ['total' => 0];
    return (float) $row['total'];
}

$total_users = count_rows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND is_active = 1");
$total_staff = count_rows($conn, "SELECT COUNT(*) AS total FROM users WHERE role IN ('staff', 'admin') AND is_active = 1");
$pending_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'pending_approval'");
$approved_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'approved'");
$borrowed_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status IN ('borrowed', 'return_requested')");
$return_requested_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'return_requested'");
$total_items = count_rows($conn, 'SELECT COUNT(*) AS total FROM items WHERE is_active = 1');
$low_stock_count = count_rows($conn, 'SELECT COUNT(*) AS total FROM items WHERE is_active = 1 AND available_quantity <= 0');
$total_stock = count_rows($conn, 'SELECT COALESCE(SUM(total_quantity), 0) AS total FROM items WHERE is_active = 1');
$available_stock = count_rows($conn, 'SELECT COALESCE(SUM(available_quantity), 0) AS total FROM items WHERE is_active = 1');
$overdue_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status IN ('borrowed', 'return_requested') AND expected_return_date < NOW()");
$damaged_unresolved = count_rows($conn, "SELECT COUNT(*) AS total FROM return_inspections WHERE action_required <> 'none' AND is_resolved = 0 AND (damaged_quantity > 0 OR lost_quantity > 0)");
$settlement_pending_count = count_rows($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE settlement_status = 'pending'");
$receipt_pending = count_rows($conn, "SELECT COUNT(*) AS total FROM return_inspections WHERE is_resolved=0 AND replacement_submitted_at IS NOT NULL");
$replacement_pending = count_rows($conn, "SELECT COUNT(*) AS total FROM return_inspections WHERE action_required = 'buy_replacement' AND is_resolved = 0");

$overdue_requests = mysqli_query($conn, "SELECT br.request_id, br.expected_return_date, u.first_name, u.last_name
    FROM borrow_requests br JOIN users u ON u.user_id = br.user_id
    WHERE br.status IN ('borrowed', 'return_requested') AND br.expected_return_date < NOW()
    ORDER BY br.expected_return_date ASC LIMIT 5");
$low_stock_items = mysqli_query($conn, "SELECT item_name, available_quantity, total_quantity FROM items
    WHERE is_active = 1 AND available_quantity <= 0 ORDER BY item_name LIMIT 5");

$recent_requests = mysqli_query($conn, "SELECT br.request_id, br.status, br.created_at, u.first_name, u.last_name
    FROM borrow_requests br
    LEFT JOIN users u ON u.user_id = br.user_id
    ORDER BY br.created_at DESC
    LIMIT 5");

// ข้อมูลสำหรับกราฟย้อนหลัง 6 เดือน
$thai_months = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
$monthly_labels = [];
$monthly_values = [];
$monthly_keys = [];
$first_chart_month = new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $month = $first_chart_month->modify("-$i months");
    $key = $month->format('Y-m');
    $monthly_keys[$key] = count($monthly_values);
    $monthly_labels[] = $thai_months[(int) $month->format('n')] . ' ' . ((int) $month->format('Y') + 543);
    $monthly_values[] = 0;
}
$monthly_start = $first_chart_month->modify('-5 months')->format('Y-m-01 00:00:00');
$monthly_q = mysqli_query($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM borrow_requests WHERE created_at >= '$monthly_start'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month_key");
while ($monthly_q && $month_row = mysqli_fetch_assoc($monthly_q)) {
    if (isset($monthly_keys[$month_row['month_key']])) {
        $monthly_values[$monthly_keys[$month_row['month_key']]] = (int) $month_row['total'];
    }
}

$status_definitions = [
    'pending_approval' => ['รออนุมัติ', '#d7a843'],
    'approved' => ['อนุมัติแล้ว รอรับของ', '#6d28d9'],
    'borrowed' => ['กำลังยืม', '#176b45'],
    'return_requested' => ['รอตรวจรับคืน', '#2f86a6'],
    'returned' => ['คืนเรียบร้อย', '#67a978'],
    'partially_damaged' => ['ชำรุด/สูญหาย', '#c8564c'],
    'rejected' => ['ไม่อนุมัติ', '#8a9690'],
    'cancelled' => ['ยกเลิก', '#b7bdb9'],
];
$status_totals = array_fill_keys(array_keys($status_definitions), 0);
$status_q = mysqli_query($conn, 'SELECT status, COUNT(*) AS total FROM borrow_requests GROUP BY status');
while ($status_q && $status_row = mysqli_fetch_assoc($status_q)) {
    if (array_key_exists($status_row['status'], $status_totals)) {
        $status_totals[$status_row['status']] = (int) $status_row['total'];
    }
}
$status_labels = [];
$status_values = [];
$status_colors = [];
foreach ($status_definitions as $status_key => $definition) {
    $status_labels[] = $definition[0];
    $status_values[] = $status_totals[$status_key];
    $status_colors[] = $definition[1];
}

$top_item_labels = [];
$top_item_values = [];
$top_items_q = mysqli_query($conn, "SELECT i.item_name, COALESCE(SUM(bi.quantity_borrowed), 0) AS total
    FROM borrow_items bi
    JOIN items i ON i.item_id = bi.item_id
    JOIN borrow_requests br ON br.request_id = bi.request_id
    WHERE br.status IN ('borrowed', 'return_requested', 'returned', 'partially_damaged')
    GROUP BY i.item_id, i.item_name
    ORDER BY total DESC, i.item_name ASC LIMIT 5");
while ($top_items_q && $top_item = mysqli_fetch_assoc($top_items_q)) {
    $top_item_labels[] = $top_item['item_name'];
    $top_item_values[] = (int) $top_item['total'];
}
if (empty($top_item_labels)) {
    $top_item_labels[] = 'ยังไม่มีข้อมูลการยืม';
    $top_item_values[] = 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผู้ดูแลระบบ - ระบบยืมคืนของวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css?v=1">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui admin-dashboard pb-5">
<?php $uiPage = 'admin/home.php'; require __DIR__ . '/../config/page_shell.php'; ?>


<main class="container">
    <?php require __DIR__ . '/../config/notification_inbox_view.php'; ?>
    <?php require __DIR__ . '/../config/admin_dashboard_overview.php'; ?>



    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h5 fw-bold mb-0">รายการที่ต้องติดตามทันที</h2><a class="btn btn-sm btn-outline-primary" href="../staff/requests.php">จัดการคำขอ</a></div>
                <div class="card-body p-0">
                    <?php if ($overdue_requests && mysqli_num_rows($overdue_requests) > 0): ?>
                        <div class="list-group list-group-flush"><?php while ($overdue = mysqli_fetch_assoc($overdue_requests)): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><strong>คำขอ #<?= (int) $overdue['request_id'] ?></strong><div class="small text-muted"><?= app_escape($overdue['first_name'] . ' ' . $overdue['last_name']) ?></div></div><span class="text-danger small">ครบกำหนด <?= app_escape(date('d/m/Y H:i', strtotime($overdue['expected_return_date']))) ?></span></div><?php endwhile; ?></div>
                    <?php else: ?><div class="p-4 text-center text-muted">ไม่มีรายการยืมที่เกินกำหนดคืน</div><?php endif; ?>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h5 fw-bold mb-0">สถานะคลังและของทดแทน</h2><a class="btn btn-sm btn-outline-warning" href="damage_report.php">PDF ของทดแทน (<?= $replacement_pending ?>)</a></div>
                <div class="card-body p-0">
                    <?php if ($low_stock_items && mysqli_num_rows($low_stock_items) > 0): ?><div class="list-group list-group-flush"><?php while ($item = mysqli_fetch_assoc($low_stock_items)): ?><div class="list-group-item d-flex justify-content-between"><span><?= app_escape($item['item_name']) ?></span><span class="badge bg-danger">ของหมด <?= (int) $item['available_quantity'] ?>/<?= (int) $item['total_quantity'] ?></span></div><?php endwhile; ?></div>
                    <?php else: ?><div class="p-4 text-center text-muted">ไม่มีรายการของหมดคลัง</div><?php endif; ?>
                    <div class="p-3 border-top small"><span class="badge bg-warning text-dark me-1"><?= $replacement_pending ?></span> รายการรอรับของทดแทน</div>
                </div>
            </section>
        </div>
    </div>

    <div class="admin-tools"><h2 class="h5 fw-bold">เครื่องมือผู้ดูแลระบบ</h2><form method="post" action="backup.php"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><button class="btn btn-outline-primary" type="submit">ดาวน์โหลดสำรองข้อมูล</button></form></div>
    <div class="row g-3 mb-3">
    <?php foreach ([['branding.php','temple','โลโก้และแบนเนอร์','ปรับภาพลักษณ์ของเว็บไซต์'],['users.php','users','บัญชีผู้ใช้งาน','จัดการผู้ยืมและสิทธิ์เจ้าหน้าที่'],['audit.php','clock','ประวัติกิจกรรม','ตรวจสอบการทำรายการในระบบ'],['reservations.php','calendar','การจองล่วงหน้า','ตรวจสอบและจัดการการจอง'],['../staff/maintenance.php','tool','ซ่อมบำรุง','ดูแลสิ่งของชำรุดและงานซ่อม'],['../staff/stock_discrepancies.php','box','ตรวจสอบของในคลัง','ติดตามจำนวนของที่ตรวจนับไม่ครบ']] as [$href,$icon,$label,$description]): ?>
    <div class="col-sm-6 col-xl-4"><a class="card action-card h-100 text-decoration-none" href="<?= $href ?>"><div class="card-body"><div class="fs-2"><?php $adminIcon($icon); ?></div><h3 class="h6 fw-bold mt-2"><?= $label ?></h3><p class="text-muted small mb-0"><?= $description ?></p></div></a></div>
    <?php endforeach; ?></div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><a class="text-decoration-none text-dark" href="damage_report.php"><div class="card action-card h-100 border-0 shadow-sm"><div class="card-body p-4"><div class="fs-2">📄</div><h3 class="h5 mt-2">รายงานของที่ต้องซื้อเติม</h3><p class="text-muted mb-0">ดาวน์โหลด PDF สรุปรายการชำรุดหรือสูญหายที่ต้องนำของมาทดแทน</p></div></div></a></div>
        <div class="col-md-4"><a class="text-decoration-none text-dark" href="../staff/requests.php"><div class="card action-card h-100 border-0 shadow-sm"><div class="card-body p-4"><div class="fs-2">📋</div><h3 class="h5 mt-2">จัดการคำขอยืม</h3><p class="text-muted mb-0">ตรวจสอบ อนุมัติ หรือปฏิเสธคำขอที่รอดำเนินการ</p></div></div></a></div>
        <div class="col-md-4"><a class="text-decoration-none text-dark" href="../staff/items.php"><div class="card action-card h-100 border-0 shadow-sm"><div class="card-body p-4"><div class="fs-2">📦</div><h3 class="h5 mt-2">จัดการคลังสิ่งของ</h3><p class="text-muted mb-0">มีสิ่งของ <?= $total_items ?> รายการ และสินค้าหมด <?= $low_stock_count ?> รายการ</p></div></div></a></div>
        <div class="col-md-4"><a class="text-decoration-none text-dark" href="../staff/return_check.php"><div class="card action-card h-100 border-0 shadow-sm"><div class="card-body p-4"><div class="fs-2">🔎</div><h3 class="h5 mt-2">ตรวจรับคืน</h3><p class="text-muted mb-0">บันทึกสภาพสิ่งของที่คืนและจำนวนที่เสียหาย</p></div></div></a></div>
    </div>

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h2 class="h5 fw-bold mb-0">คำขอยืมล่าสุด</h2></div>
        <div class="card-body p-0">
            <?php if ($recent_requests && mysqli_num_rows($recent_requests) > 0): ?>
                <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th class="ps-3">เลขที่</th><th>ผู้ยืม</th><th>สถานะ</th><th>วันที่ส่งคำขอ</th><th></th></tr></thead><tbody>
                <?php while ($request = mysqli_fetch_assoc($recent_requests)): ?>
                    <?php $request_status = (string) $request['status']; ?>
                    <tr><td class="ps-3">#<?= (int) $request['request_id'] ?></td><td><?= app_escape(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')) ?: '-') ?></td><td><span class="badge <?= app_escape(borrow_status_badge($request_status)) ?>"><?= app_escape(borrow_status_label($request_status)) ?></span></td><td><?= app_escape(date('d/m/Y H:i', strtotime($request['created_at']))) ?></td><td><a class="btn btn-sm btn-outline-primary" href="../staff/requests.php">ดูรายการ</a><?php if (in_array($request_status, ['approved', 'borrowed', 'return_requested', 'returned', 'partially_damaged'], true)): ?> <a class="btn btn-sm btn-outline-secondary" href="handover_pdf.php?request_id=<?= (int) $request['request_id'] ?>">เอกสาร PDF</a><?php endif; ?></td></tr>
                <?php endwhile; ?>
                </tbody></table></div>
            <?php else: ?>
                <div class="p-4 text-center text-muted">ยังไม่มีคำขอยืมในระบบ</div>
            <?php endif; ?>
        </div>
    </section>
    <section class="mb-4" aria-labelledby="dashboardChartsTitle">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <div>
                <h2 id="dashboardChartsTitle" class="h5 fw-bold mb-1">กราฟวิเคราะห์ภาพรวม</h2>
                <div class="small text-muted">ข้อมูลล่าสุดจากรายการยืมและคลังสิ่งของ</div>
            </div>
            <form id="dashboardPdfForm" method="post" action="dashboard_report_pdf.php" target="_blank">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="monthly_chart" id="monthlyChartImage">
                <input type="hidden" name="status_chart" id="statusChartImage">
                <input type="hidden" name="top_items_chart" id="topItemsChartImage">
                <button type="submit" id="exportDashboardPdf" class="btn btn-danger">📄 ส่งออกกราฟเป็น PDF</button>
            </form>
        </div>
        <div id="chartLoadError" class="alert alert-danger d-none">ไม่สามารถโหลด Chart.js ได้ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง</div>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><h3 class="h6 fw-bold mb-0">จำนวนคำขอยืมย้อนหลัง 6 เดือน</h3></div>
                    <div class="card-body"><div class="chart-wrap chart-wide"><canvas id="monthlyBorrowChart" aria-label="กราฟจำนวนคำขอยืมย้อนหลัง 6 เดือน" role="img"></canvas></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><h3 class="h6 fw-bold mb-0">สัดส่วนสถานะคำขอยืม</h3></div>
                    <div class="card-body"><div class="chart-wrap chart-wide"><canvas id="borrowStatusChart" aria-label="กราฟสัดส่วนสถานะคำขอยืม" role="img"></canvas></div></div>
                </div>
            </div>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h3 class="h6 fw-bold mb-0">สิ่งของที่ถูกยืมมากที่สุด 5 อันดับ</h3></div>
                    <div class="card-body"><div class="chart-wrap"><canvas id="topBorrowedItemsChart" aria-label="กราฟสิ่งของที่ถูกยืมมากที่สุด" role="img"></canvas></div></div>
                </div>
            </div>
        </div>
    </section>
<footer class="admin-footer">ระบบบริหารจัดการยืม–คืนสิ่งของวัด · ใช้ร่วมกันอย่างรู้คุณค่า</footer>
</main>
<script src="../assets/js/chart.umd.min.js"></script>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notification-popup.js?v=1" defer></script>
<script>
(function () {
    var exportButton = document.getElementById('exportDashboardPdf');
    if (typeof Chart === 'undefined') {
        document.getElementById('chartLoadError').classList.remove('d-none');
        exportButton.disabled = true;
        return;
    }

    Chart.defaults.font.family = "Tahoma, 'Segoe UI', sans-serif";
    Chart.defaults.color = '#52665b';
    var whiteBackground = {
        id: 'whiteBackground',
        beforeDraw: function (chart) {
            var ctx = chart.ctx;
            ctx.save();
            ctx.globalCompositeOperation = 'destination-over';
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, chart.width, chart.height);
            ctx.restore();
        }
    };
    Chart.register(whiteBackground);

    var commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        devicePixelRatio: 2,
        animation: { duration: 650 },
        plugins: { legend: { labels: { usePointStyle: true, boxWidth: 10 } } }
    };

    var monthlyChart = new Chart(document.getElementById('monthlyBorrowChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($monthly_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [{
                label: 'คำขอยืม',
                data: <?= json_encode($monthly_values) ?>,
                borderColor: '#176b45',
                backgroundColor: 'rgba(23, 107, 69, .14)',
                pointBackgroundColor: '#d7a843',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                tension: .35,
                fill: true
            }]
        },
        options: Object.assign({}, commonOptions, {
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e6eee8' } }, x: { grid: { display: false } } }
        })
    });

    var statusChart = new Chart(document.getElementById('borrowStatusChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($status_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [{ data: <?= json_encode($status_values) ?>, backgroundColor: <?= json_encode($status_colors) ?>, borderColor: '#ffffff', borderWidth: 3 }]
        },
        options: Object.assign({}, commonOptions, {
            cutout: '62%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 9, padding: 14 } } }
        })
    });

    var topItemsChart = new Chart(document.getElementById('topBorrowedItemsChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($top_item_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [{
                label: 'จำนวนที่ยืม',
                data: <?= json_encode($top_item_values) ?>,
                backgroundColor: ['#176b45', '#2b8158', '#4b9870', '#78af8a', '#a9c8b2'],
                borderRadius: 7,
                barThickness: 24
            }]
        },
        options: Object.assign({}, commonOptions, {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e6eee8' } }, y: { grid: { display: false } } }
        })
    });

    document.getElementById('dashboardPdfForm').addEventListener('submit', function (event) {
        event.preventDefault();
        exportButton.disabled = true;
        exportButton.textContent = 'กำลังสร้าง PDF...';
        monthlyChart.update('none');
        statusChart.update('none');
        topItemsChart.update('none');
        document.getElementById('monthlyChartImage').value = monthlyChart.toBase64Image('image/png', 1);
        document.getElementById('statusChartImage').value = statusChart.toBase64Image('image/png', 1);
        document.getElementById('topItemsChartImage').value = topItemsChart.toBase64Image('image/png', 1);
        this.submit();
        window.setTimeout(function () {
            exportButton.disabled = false;
            exportButton.textContent = '📄 ส่งออกกราฟเป็น PDF';
        }, 1200);
    });
})();
</script>
</body>
</html>
