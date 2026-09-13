<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

ensure_feature_tables($conn);
$current_admin = require_roles($conn, ['admin'], '../config/login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('home.php');
}
require_csrf();

function report_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    $row = $result ? mysqli_fetch_assoc($result) : ['total' => 0];
    return (int) $row['total'];
}

function validate_chart_image($value) {
    if (!is_string($value) || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $value, $matches)) {
        return null;
    }
    if (strlen($matches[1]) > 7 * 1024 * 1024) {
        return null;
    }
    $binary = base64_decode($matches[1], true);
    if ($binary === false || strlen($binary) < 8 || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }
    return 'data:image/png;base64,' . $matches[1];
}

$monthly_chart = validate_chart_image($_POST['monthly_chart'] ?? '');
$status_chart = validate_chart_image($_POST['status_chart'] ?? '');
$top_items_chart = validate_chart_image($_POST['top_items_chart'] ?? '');
if (!$monthly_chart || !$status_chart || !$top_items_chart) {
    http_response_code(422);
    exit('ข้อมูลกราฟไม่ถูกต้อง กรุณากลับไปหน้า Admin แล้วส่งออกใหม่อีกครั้ง');
}

$total_users = report_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND is_active = 1");
$total_requests = report_count($conn, 'SELECT COUNT(*) AS total FROM borrow_requests');
$pending_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'pending_approval'");
$approved_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'approved'");
$borrowed_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status IN ('borrowed', 'return_requested')");
$return_requested_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status = 'return_requested'");
$overdue_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE status IN ('borrowed', 'return_requested') AND expected_return_date < NOW()");
$settlement_pending_count = report_count($conn, "SELECT COUNT(*) AS total FROM borrow_requests WHERE settlement_status = 'pending'");
$total_stock = report_count($conn, 'SELECT COALESCE(SUM(total_quantity), 0) AS total FROM items WHERE is_active = 1');
$available_stock = report_count($conn, 'SELECT COALESCE(SUM(available_quantity), 0) AS total FROM items WHERE is_active = 1');
$out_of_stock = report_count($conn, 'SELECT COUNT(*) AS total FROM items WHERE is_active = 1 AND available_quantity <= 0');
$unresolved_damage = report_count($conn, "SELECT COUNT(*) AS total FROM return_inspections WHERE action_required <> 'none' AND is_resolved = 0 AND (damaged_quantity > 0 OR lost_quantity > 0)");

$generated_at = date('d/m/') . (date('Y') + 543) . date(' H:i') . ' น.';
$admin_name = app_escape($_SESSION['user_name'] ?? 'ผู้ดูแลระบบ');
$html = '<!doctype html><html lang="th"><head><meta charset="UTF-8"><style>
    body{font-family:garuda;font-size:12pt;color:#243c30}h1{font-size:22pt;color:#0d4b30;margin:0 0 3px}h2{font-size:15pt;color:#176b45;margin:14px 0 7px}.muted{color:#6e7e75}.header{border-bottom:2px solid #d7a843;padding-bottom:10px;margin-bottom:12px}.metrics{width:100%;border-collapse:separate;border-spacing:7px}.metric{border:1px solid #dbe8df;background:#f5faf6;border-radius:7px;padding:9px}.metric b{display:block;font-size:18pt;color:#176b45}.chart{border:1px solid #dbe8df;border-radius:8px;padding:8px;margin-bottom:10px;background:#fff}.chart img{display:block;width:100%;height:auto}.half{width:49%;vertical-align:top}.footer{margin-top:12px;border-top:1px solid #dbe8df;padding-top:7px;font-size:9pt;color:#718078;text-align:center}
    </style></head><body>'
    . '<div class="header"><h1>รายงานกราฟภาพรวมระบบยืมคืนสิ่งของวัด</h1><div class="muted">ออกรายงาน ' . $generated_at . ' · ผู้จัดทำ ' . $admin_name . '</div></div>'
    . '<table class="metrics"><tr>'
    . '<td class="metric"><span>ผู้ยืมทั้งหมด</span><b>' . number_format($total_users) . '</b></td>'
    . '<td class="metric"><span>คำขอยืมทั้งหมด</span><b>' . number_format($total_requests) . '</b></td>'
    . '<td class="metric"><span>รออนุมัติ</span><b>' . number_format($pending_count) . '</b></td>'
    . '<td class="metric"><span>อนุมัติแล้ว รอส่งมอบ</span><b>' . number_format($approved_count) . '</b></td>'
    . '</tr><tr>'
    . '<td class="metric"><span>ของอยู่กับผู้ยืม</span><b>' . number_format($borrowed_count) . '</b></td>'
    . '<td class="metric"><span>แจ้งคืน รอตรวจรับ</span><b>' . number_format($return_requested_count) . '</b></td>'
    . '<td class="metric"><span>เกินกำหนดคืน</span><b>' . number_format($overdue_count) . '</b></td>'
    . '<td class="metric"><span>คำขอรอปิดยอดชดใช้</span><b>' . number_format($settlement_pending_count) . '</b></td>'
    . '</tr><tr>'
    . '<td class="metric"><span>ของพร้อมใช้</span><b>' . number_format($available_stock) . '/' . number_format($total_stock) . '</b></td>'
    . '<td class="metric"><span>รายการของหมด</span><b>' . number_format($out_of_stock) . '</b></td>'
    . '<td class="metric"><span>ปัญหาชำรุด/สูญหายรอชดใช้</span><b>' . number_format($unresolved_damage) . '</b></td>'
    . '<td class="metric"><span>วันที่รายงาน</span><b style="font-size:12pt">' . $generated_at . '</b></td>'
    . '</tr></table>'
    . '<h2>จำนวนคำขอยืมย้อนหลัง 6 เดือน</h2><div class="chart"><img src="' . $monthly_chart . '"></div>'
    . '<table style="width:100%;border-collapse:separate;border-spacing:8px"><tr>'
    . '<td class="half"><h2>สัดส่วนสถานะคำขอยืม</h2><div class="chart"><img src="' . $status_chart . '"></div></td>'
    . '<td class="half"><h2>สิ่งของที่ถูกยืมมากที่สุด</h2><div class="chart"><img src="' . $top_items_chart . '"></div></td>'
    . '</tr></table><div class="footer">รายงานสร้างจากข้อมูลในระบบ ณ เวลาที่ส่งออก</div></body></html>';

try {
    $pdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'default_font' => 'garuda',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 10,
        'margin_bottom' => 10,
    ]);
    $pdf->SetTitle('รายงานกราฟภาพรวมระบบยืมคืนสิ่งของวัด');
    $html = str_replace('</style>', file_get_contents(__DIR__ . '/../assets/css/report.css') . '</style>', $html);
    $pdf->WriteHTML($html);
    $pdf->Output('dashboard-report-' . date('Ymd-His') . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
} catch (Throwable $e) {
    http_response_code(500);
    exit('ไม่สามารถสร้าง PDF ได้ กรุณาลองใหม่อีกครั้ง');
}
