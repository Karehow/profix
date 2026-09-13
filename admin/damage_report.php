<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

ensure_feature_tables($conn);
$current_admin = require_roles($conn, ['admin'], '../config/login.php');

$sql = "SELECT ri.inspection_id, ri.return_batch_id, ri.damaged_quantity, ri.lost_quantity,
        ri.damage_description, ri.inspected_at, i.item_name, i.is_set,
        COALESCE(bic.component_name, ic.component_name) AS component_name,
        CASE
            WHEN COALESCE(bic.component_name, ic.component_name) IS NOT NULL THEN COALESCE(bic.unit, ic.unit, 'ชิ้น')
            WHEN i.is_set = 1 THEN 'ชุด'
            ELSE 'ชิ้น'
        END AS issue_unit,
        br.request_id, br.settlement_status, u.first_name, u.last_name
    FROM return_inspections ri
    JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id
    JOIN items i ON i.item_id = bi.item_id
    LEFT JOIN borrow_item_components bic ON bic.borrow_item_component_id = ri.borrow_item_component_id
    LEFT JOIN item_components ic ON ic.component_id = ri.component_id
    JOIN borrow_requests br ON br.request_id = bi.request_id
    JOIN users u ON u.user_id = br.user_id
    WHERE ri.action_required = 'buy_replacement' AND ri.is_resolved = 0
      AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
    ORDER BY i.item_name, ri.inspected_at DESC";
$result = mysqli_query($conn, $sql);
$rows = [];
$pendingRequestIds = [];
while ($result && $row = mysqli_fetch_assoc($result)) {
    $row['needed'] = (int) $row['damaged_quantity'] + (int) $row['lost_quantity'];
    $pendingRequestIds[(int) $row['request_id']] = true;
    $rows[] = $row;
}

$tableRows = '';
foreach ($rows as $index => $row) {
    $item = app_escape($row['item_name']);
    if (!empty($row['component_name'])) {
        $item .= '<br><span class="muted">ชิ้นส่วนตามวันที่ยืม: ' . app_escape($row['component_name']) . '</span>';
    }
    $tableRows .= '<tr>'
        . '<td class="center">' . ($index + 1) . '</td>'
        . '<td>' . $item . '</td>'
        . '<td class="center">' . (int) $row['damaged_quantity'] . '</td>'
        . '<td class="center">' . (int) $row['lost_quantity'] . '</td>'
        . '<td class="center"><b>' . $row['needed'] . '</b> ' . app_escape($row['issue_unit']) . '</td>'
        . '<td>' . app_escape($row['damage_description'] ?: '-') . '<br><span class="muted">ตรวจเมื่อ ' . app_escape(date('d/m/Y H:i', strtotime($row['inspected_at']))) . '</span></td>'
        . '<td>คำขอ #' . (int) $row['request_id'] . '<br><span class="muted">' . app_escape($row['first_name'] . ' ' . $row['last_name']) . '</span></td>'
        . '</tr>';
}
if ($tableRows === '') $tableRows = '<tr><td colspan="7" class="empty">ไม่มีรายการที่ต้องจัดซื้อทดแทน</td></tr>';

$html = '<!doctype html><html lang="th"><head><meta charset="UTF-8"><style>
    body { font-family: garuda; font-size: 12pt; color: #212529; }
    h1 { font-size: 21pt; margin: 0 0 4px; } .subtitle { color: #555; margin-bottom: 16px; }
    .summary { background: #fff3cd; border: 1px solid #f0d98a; padding: 10px; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; } th { background: #343a40; color: #fff; padding: 7px; text-align: left; }
    td { border: 1px solid #bbb; padding: 6px; vertical-align: top; } .center { text-align: center; } .muted { color: #666; font-size: 10pt; }
    .empty { text-align: center; padding: 22px; color: #666; } .footer { margin-top: 18px; color: #666; font-size: 10pt; }
    </style></head><body><h1>รายงานรายการที่ต้องจัดซื้อทดแทน</h1><div class="subtitle">ระบบยืม-คืนสิ่งของวัด · วันที่ออกรายงาน ' . date('d/m/Y H:i') . ' น.</div>'
    . '<div class="summary"><b>สรุป:</b> พบ ' . count($rows) . ' รายการ จาก ' . count($pendingRequestIds) . ' คำขอยืมที่ยังไม่ปิดยอดชดใช้ โปรดจัดหาตามจำนวนและหน่วยของแต่ละรายการในตาราง</div>'
    . '<table><thead><tr><th style="width:5%">ลำดับ</th><th style="width:26%">รายการ</th><th style="width:10%">ชำรุด</th><th style="width:10%">สูญหาย</th><th style="width:12%">ต้องเติม</th><th style="width:22%">รายละเอียด</th><th style="width:15%">ผู้ยืม</th></tr></thead><tbody>' . $tableRows . '</tbody></table>'
    . '<div class="footer">รายงานนี้แสดงเฉพาะรายการที่เจ้าหน้าที่กำหนดให้ “นำของมาทดแทน” และยังไม่ได้รับชดใช้</div></body></html>';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'default_font' => 'garuda']);
    $mpdf->SetTitle('รายงานรายการที่ต้องจัดซื้อทดแทน');
    $html = str_replace('</style>', file_get_contents(__DIR__ . '/../assets/css/report.css') . '</style>', $html);
    $mpdf->WriteHTML($html);
    $mpdf->Output('damage-replacement-report-' . date('Ymd-His') . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
} catch (Throwable $e) {
    http_response_code(500);
    exit('ไม่สามารถสร้างรายงาน PDF ได้ กรุณาลองใหม่อีกครั้ง');
}
exit();
