<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/component_quantities.php';
require_once __DIR__ . '/../vendor/autoload.php';

ensure_feature_tables($conn);
$current_user = require_roles($conn, ['admin', 'staff'], '../config/login.php');

$request_id = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!$request_id) {
    http_response_code(400);
    exit('เลขที่คำขอไม่ถูกต้อง');
}

$stmt = mysqli_prepare($conn, "SELECT br.*, u.first_name, u.last_name, u.phone_number, u.address_detail,
        CONCAT_WS(' ', approver.first_name, approver.last_name) AS approver_name,
        CONCAT_WS(' ', handover.first_name, handover.last_name) AS handover_name
    FROM borrow_requests br
    JOIN users u ON u.user_id = br.user_id
    LEFT JOIN users approver ON approver.user_id = br.approved_by_user_id
    LEFT JOIN users handover ON handover.user_id = br.handed_over_by_user_id
    WHERE br.request_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $request_id);
mysqli_stmt_execute($stmt);
$request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$request) {
    http_response_code(404);
    exit('ไม่พบคำขอยืม');
}

$printable_statuses = ['approved', 'borrowed', 'return_requested', 'returned', 'partially_damaged'];
if (!in_array($request['status'], $printable_statuses, true)) {
    http_response_code(409);
    exit('ใบส่งมอบออกได้หลังคำขอได้รับอนุมัติแล้วเท่านั้น');
}

$item_stmt = mysqli_prepare($conn, 'SELECT bi.borrow_item_id, bi.quantity_borrowed, bi.quantity_returned, bi.quantity_requested, bi.quantity_handed_over,
        i.item_name, i.is_set
    FROM borrow_items bi
    JOIN items i ON i.item_id = bi.item_id
    WHERE bi.request_id = ?
    ORDER BY bi.borrow_item_id');
mysqli_stmt_bind_param($item_stmt, 'i', $request_id);
mysqli_stmt_execute($item_stmt);
$item_result = mysqli_stmt_get_result($item_stmt);
$items = [];
while ($row = mysqli_fetch_assoc($item_result)) {
    $row['components'] = [];
    $items[(int) $row['borrow_item_id']] = $row;
}
mysqli_stmt_close($item_stmt);

if (!$items) {
    http_response_code(409);
    exit('คำขอนี้ไม่มีรายการสิ่งของ');
}

$component_stmt = mysqli_prepare($conn, 'SELECT bic.borrow_item_id, bic.component_name,
        bic.quantity_per_set, bic.quantity_borrowed, bic.unit, bic.is_included
    FROM borrow_item_components bic
    JOIN borrow_items bi ON bi.borrow_item_id = bic.borrow_item_id
    WHERE bi.request_id = ?
    ORDER BY bic.borrow_item_id, bic.borrow_item_component_id');
mysqli_stmt_bind_param($component_stmt, 'i', $request_id);
mysqli_stmt_execute($component_stmt);
$component_result = mysqli_stmt_get_result($component_stmt);
while ($component = mysqli_fetch_assoc($component_result)) {
    $borrow_item_id = (int) $component['borrow_item_id'];
    if (isset($items[$borrow_item_id])) {
        $items[$borrow_item_id]['components'][] = $component;
    }
}
mysqli_stmt_close($component_stmt);

$table_rows = '';
$sequence = 1;
foreach ($items as $item) {
    $quantity_borrowed = (int) $item['quantity_borrowed'];
    $detail_lines = [];
    $excluded_lines = [];
    foreach ($item['components'] as $component) {
        $per_set = (int) $component['quantity_per_set'];
        $total_component_quantity = component_borrowed_quantity($component, $quantity_borrowed);
        $component_text = app_escape($component['component_name']) . ' × ' . number_format($total_component_quantity) . ' ' . app_escape($component['unit']);
        if ($quantity_borrowed > 1 && !isset($component['quantity_borrowed'])) {
            $component_text .= ' (' . number_format($per_set) . ' ต่อชุด)';
        }
        if ((int) $component['is_included'] === 1) {
            $detail_lines[] = $component_text;
        } else {
            $excluded_lines[] = $component_text;
        }
    }

    $item_detail = '<strong>' . app_escape($item['item_name']) . '</strong>';
    if ((int) $item['is_set'] === 1) {
        if ($detail_lines) {
            $item_detail .= '<div class="component"><b>ชิ้นส่วนที่ส่งมอบ:</b> ' . implode(', ', $detail_lines) . '</div>';
        }
        if ($excluded_lines) {
            $item_detail .= '<div class="excluded"><b>ไม่รวม:</b> ' . implode(', ', $excluded_lines) . '</div>';
        }
    }

    $exact_components = array_filter($item['components'], static fn($c) => isset($c['quantity_borrowed']));
    $quantity_label = $exact_components ? array_sum(array_column($exact_components, 'quantity_borrowed')) . ' ชิ้น (ของย่อย)'
        : number_format($quantity_borrowed) . ((int) $item['is_set'] === 1 ? ' ชุด' : ' ชิ้น');
    if (!(int) $item['is_set']) {
        $item_detail .= '<div>ขอยืมเดิม ' . (int) ($item['quantity_requested'] ?? $quantity_borrowed) . ' ชิ้น · จัดให้ ' . $quantity_borrowed . ' ชิ้น</div>';
        $item_detail .= '<div>ส่งมอบจริง: ' . ($item['quantity_handed_over'] === null ? 'ยังไม่ส่งมอบ' : (int) $item['quantity_handed_over'] . ' ชิ้น') . '</div>';
    }
    $table_rows .= '<tr>'
        . '<td class="center">' . $sequence++ . '</td>'
        . '<td>' . $item_detail . '</td>'
        . '<td class="center">' . $quantity_label . '</td>'
        . '<td></td></tr>';
}

$format_datetime = static function ($value): string {
    return $value ? date('d/m/Y H:i', strtotime((string) $value)) . ' น.' : '-';
};
$generated_at = date('d/m/') . ((int) date('Y') + 543) . date(' H:i') . ' น.';
$borrower_name = trim($request['first_name'] . ' ' . $request['last_name']);
$approved_by = trim((string) $request['approver_name']) ?: '-';
$handed_over_by = trim((string) $request['handover_name']) ?: '-';

$html = '<!doctype html><html lang="th"><head><meta charset="UTF-8"><style>
    body{font-family:garuda;font-size:13pt;color:#1f2937}h1{text-align:center;font-size:23pt;margin:0 0 3px}.subtitle{text-align:center;color:#64748b;margin-bottom:18px}.meta{width:100%;border-collapse:collapse;margin-bottom:12px}.meta td{border:0;padding:3px 4px;vertical-align:top}.items{width:100%;border-collapse:collapse;margin-top:12px}.items td,.items th{border:1px solid #475569;padding:7px;vertical-align:top}.items th{background:#e2e8f0}.center{text-align:center}.component{font-size:10.5pt;color:#334155;margin-top:4px}.excluded{font-size:10pt;color:#9f1239;margin-top:3px}.signatures{width:100%;margin-top:48px;border-collapse:collapse}.signatures td{width:50%;text-align:center;border:0;padding:4px}.footer{margin-top:24px;font-size:9pt;color:#64748b;text-align:center}
    </style></head><body><h1>ใบรับ-ส่งมอบสิ่งของ</h1><div class="subtitle">ระบบยืมคืนสิ่งของวัด · เลขที่คำขอ #' . (int) $request_id . '</div>'
    . '<table class="meta"><tr><td><b>ผู้ยืม:</b> ' . app_escape($borrower_name) . '</td><td><b>โทรศัพท์:</b> ' . app_escape($request['phone_number']) . '</td></tr>'
    . '<tr><td colspan="2"><b>สถานที่ใช้งาน/ที่อยู่:</b> ' . nl2br(app_escape($request['address_detail'] ?: '-')) . '</td></tr>'
    . '<tr><td><b>สถานะ:</b> ' . app_escape(borrow_status_label($request['status'])) . '</td><td><b>วันที่ต้องการรับ:</b> ' . app_escape($format_datetime($request['requested_pickup_at'])) . '</td></tr>'
    . '<tr><td><b>ผู้อนุมัติ:</b> ' . app_escape($approved_by) . '</td><td><b>อนุมัติเมื่อ:</b> ' . app_escape($format_datetime($request['approved_at'])) . '</td></tr>'
    . '<tr><td><b>ผู้ส่งมอบ:</b> ' . app_escape($handed_over_by) . '</td><td><b>ส่งมอบเมื่อ:</b> ' . app_escape($format_datetime($request['handed_over_at'])) . '</td></tr></table>'
    . '<table class="items"><thead><tr><th style="width:8%">ลำดับ</th><th>รายการ</th><th style="width:15%">จำนวน</th><th style="width:23%">สภาพ/หมายเหตุเมื่อรับ</th></tr></thead><tbody>' . $table_rows . '</tbody></table>'
    . '<p><b>กำหนดคืน:</b> ' . app_escape($format_datetime($request['expected_return_date'])) . '</p>'
    . '<table class="signatures"><tr><td>ลงชื่อ _________________________<br>ผู้ยืม<br><br>วันที่ ____/____/________</td><td>ลงชื่อ _________________________<br>เจ้าหน้าที่ผู้ส่งมอบ<br><br>วันที่ ____/____/________</td></tr></table>'
    . '<div class="footer">พิมพ์เอกสารเมื่อ ' . app_escape($generated_at) . ' โดย ' . app_escape($_SESSION['user_name'] ?? '-') . '</div></body></html>';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $pdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'garuda',
        'margin_left' => 12,
        'margin_right' => 12,
        'margin_top' => 12,
        'margin_bottom' => 12,
    ]);
    $pdf->SetTitle('ใบรับ-ส่งมอบสิ่งของ คำขอ #' . $request_id);
    $html = str_replace('</style>', file_get_contents(__DIR__ . '/../assets/css/report.css') . '</style>', $html);
    $pdf->WriteHTML($html);
    $pdf->Output('handover-request-' . $request_id . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
} catch (Throwable $e) {
    http_response_code(500);
    exit('ไม่สามารถสร้างเอกสาร PDF ได้ กรุณาลองใหม่อีกครั้ง');
}

exit;
