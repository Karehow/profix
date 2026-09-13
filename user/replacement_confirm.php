<?php
require_once __DIR__ . '/../config/replacement_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['user'], 'index.php');
$userId = (int) $currentUser['user_id'];
$inspectionId = max(0, (int) ($_GET['inspection_id'] ?? 0));
$stmt = mysqli_prepare($conn, "SELECT ri.*, bi.request_id, i.item_name, i.image_url, i.is_set,
    COALESCE(bic.component_name, ic.component_name) AS component_name,
    dc.compensation_id, dc.quantity_settled, dc.received_date, dc.note AS receipt_note,
    CONCAT_WS(' ', receiver.first_name, receiver.last_name) AS receiver_name
    FROM return_inspections ri
    JOIN borrow_items bi ON bi.borrow_item_id=ri.borrow_item_id
    JOIN borrow_requests br ON br.request_id=bi.request_id
    JOIN items i ON i.item_id=bi.item_id
    LEFT JOIN borrow_item_components bic ON bic.borrow_item_component_id=ri.borrow_item_component_id
    LEFT JOIN item_components ic ON ic.component_id=ri.component_id
    LEFT JOIN damage_compensations dc ON dc.inspection_id=ri.inspection_id
    LEFT JOIN users receiver ON receiver.user_id=dc.received_by_user_id
    WHERE ri.inspection_id=? AND br.user_id=? AND (ri.damaged_quantity>0 OR ri.lost_quantity>0)");
mysqli_stmt_bind_param($stmt, 'ii', $inspectionId, $userId);
mysqli_stmt_execute($stmt);
$issue = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$issue) {
    http_response_code(404);
    exit('ไม่พบรายการของทดแทนของคุณ');
}
$error = '';
$note = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if (($_POST['confirm_replacement'] ?? '') !== '1') throw new BorrowWorkflowException('กรุณายืนยันว่ารายการและจำนวนของทดแทนถูกต้อง');
        replacement_submit($conn, $userId, $inspectionId, $_FILES['replacement_image'] ?? [], $note);
        flash_set('success', 'ส่งยืนยันของทดแทนแล้ว กรุณานำของให้เจ้าหน้าที่ตรวจรับ');
        app_redirect('replacement_confirm.php?inspection_id=' . $inspectionId);
    } catch (Throwable $e) {
        $error = $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถส่งยืนยันได้ กรุณาลองใหม่';
    }
}
$resolved = (int) $issue['is_resolved'] === 1;
$submitted = $issue['replacement_submitted_at'] !== null;
$quantity = (int) $issue['damaged_quantity'] + (int) $issue['lost_quantity'];
$unit = $issue['component_name'] || !(int) $issue['is_set'] ? 'ชิ้น' : 'ชุด';
$flash = flash_take();
?>
<!doctype html>
<html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ยืนยันของทดแทนคืนวัด</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<style>
.replacement-page{max-width:940px}.proof-image{width:100%;max-height:320px;object-fit:contain;background:#f5f7f3;border-radius:12px}.replacement-steps{list-style:none;padding:0;display:flex;gap:12px}.replacement-steps li{flex:1;padding:12px;border-radius:10px;background:#f0f1ed;color:#647067}.replacement-steps .done{background:#e6f4ea;color:#175d36}.item-picture{width:80px;height:80px;object-fit:cover;border-radius:12px}@media(max-width:575px){.replacement-steps{flex-direction:column;gap:6px}.replacement-steps li{padding:8px 12px}}
</style><?php require __DIR__ . '/../config/theme.php'; ?></head><body class="app-ui py-4">
<?php $uiPage = 'user/replacement_confirm.php'; require __DIR__ . '/../config/page_shell.php'; ?><main class="container replacement-page">
<a class="btn btn-outline-secondary btn-sm mb-3" href="compensation.php?request_id=<?= (int) $issue['request_id'] ?>#inspection-<?= $inspectionId ?>">กลับไปรายการชดใช้</a>
<header class="mb-4"><h1 class="h3 fw-bold">ยืนยันของทดแทนคืนวัด</h1><p class="text-muted mb-0">คำขอยืม #<?= (int) $issue['request_id'] ?> · รายการชดใช้ #<?= $inspectionId ?></p></header>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= app_escape($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>" role="status"><?= app_escape($flash['message']) ?></div><?php endif; ?>
<ol class="replacement-steps mb-4" aria-label="ขั้นตอนคืนของทดแทน"><li class="done">1. ตรวจรายการและจำนวน</li><li class="<?= $submitted || $resolved ? 'done' : '' ?>">2. ส่งยืนยันและนำของคืนวัด</li><li class="<?= $resolved ? 'done' : '' ?>">3. เจ้าหน้าที่ยืนยันรับของ</li></ol>
<?php if ($resolved): ?><div class="alert alert-success"><h2 class="h5 fw-bold">เจ้าหน้าที่ยืนยันรับของทดแทนแล้ว</h2><p class="mb-0">รายการนี้ชดใช้เรียบร้อยแล้ว</p></div>
<?php elseif ($submitted): ?><div class="alert alert-warning"><h2 class="h5 fw-bold">รอเจ้าหน้าที่ยืนยันรับของจริง</h2><p class="mb-0">ส่งยืนยันแล้ว กรุณานำของทดแทนให้เจ้าหน้าที่ตรวจสอบสภาพและจำนวนก่อนปิดรายการ</p></div><?php endif; ?>
<section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><div class="d-flex align-items-center gap-3 mb-3"><img class="item-picture" src="<?= app_escape($issue['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt=""><div><h2 class="h5 fw-bold mb-1"><?= app_escape($issue['item_name']) ?></h2><?php if ($issue['component_name']): ?><div class="text-primary"><?= app_escape($issue['component_name']) ?></div><?php endif; ?></div></div>
<div class="row g-3"><div class="col-sm-6"><div class="border rounded p-3 h-100"><span class="text-muted">จำนวนที่ต้องซื้อคืน</span><div class="h3 mb-0 mt-1"><?= $quantity ?> <span class="fs-6"><?= $unit ?></span></div></div></div><div class="col-sm-6"><div class="border rounded p-3 h-100"><span class="text-muted">วิธีชดใช้</span><div class="fw-bold mt-2">ซื้อของมาคืนวัด</div></div></div></div>
<details class="mt-3"><summary>ดูรายละเอียดและหลักฐานความเสียหาย</summary><p class="mt-2" style="white-space:pre-line"><?= app_escape($issue['damage_description'] ?: 'ไม่ได้ระบุรายละเอียดเพิ่มเติม') ?></p><p class="small">ชำรุด <?= (int) $issue['damaged_quantity'] ?> · สูญหาย <?= (int) $issue['lost_quantity'] ?></p><?php if ($issue['damage_image_url']): ?><a href="<?= app_escape($issue['damage_image_url']) ?>" target="_blank" rel="noopener"><img class="proof-image" src="<?= app_escape($issue['damage_image_url']) ?>" alt="หลักฐานความเสียหายจากเจ้าหน้าที่"></a><?php endif; ?></details>
</div></section>
<?php if (!$submitted && !$resolved): ?>
<section class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 fw-bold mb-3">ตรวจสอบของทดแทนก่อนส่งยืนยัน</h2>
<form method="post" id="replacementConfirmation">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<p class="text-muted">ไม่ต้องแนบรูป ตรวจสอบรายการและจำนวน แล้วส่งยืนยันได้เลย</p>
<label for="replacementNote" class="form-label">หมายเหตุ (ถ้ามี)</label><textarea id="replacementNote" name="note" class="form-control mb-3" rows="3" maxlength="2000"><?= app_escape($note) ?></textarea>
<div class="form-check mb-3"><input class="form-check-input" id="confirmReplacement" type="checkbox" name="confirm_replacement" value="1" required><label class="form-check-label" for="confirmReplacement">ตรวจสอบแล้วว่าของทดแทนตรงรายการ ครบ <?= $quantity ?> <?= $unit ?></label></div>
<p class="text-muted small">หลังส่งยืนยัน กรุณานำของให้เจ้าหน้าที่ตรวจรับ รายการจะเสร็จเมื่อเจ้าหน้าที่ยืนยันรับของจริง</p><button type="submit" class="btn btn-primary">ยืนยันส่งของทดแทนให้เจ้าหน้าที่ตรวจรับ</button>
</form></div></section>
<?php else: ?>
<section class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 fw-bold">หลักฐานการคืนของทดแทน</h2>
<?php if ($issue['replacement_image_url']): ?><a href="<?= app_escape($issue['replacement_image_url']) ?>" target="_blank" rel="noopener"><img class="proof-image my-3" src="<?= app_escape($issue['replacement_image_url']) ?>" alt="รูปของทดแทนที่ส่งยืนยันแล้ว"></a><?php endif; ?>
<?php if ($submitted): ?><p class="small text-muted">ส่งยืนยันเมื่อ <?= app_escape(date('d/m/Y H:i', strtotime($issue['replacement_submitted_at']))) ?></p><?php endif; ?>
<?php if ($issue['replacement_note']): ?><p style="white-space:pre-line"><?= app_escape($issue['replacement_note']) ?></p><?php endif; ?>
<?php if ($resolved && $issue['compensation_id']): ?><hr><dl class="row mb-0"><dt class="col-sm-4">เลขที่รับของทดแทน</dt><dd class="col-sm-8">#<?= (int) $issue['compensation_id'] ?></dd><dt class="col-sm-4">วันที่รับของจริง</dt><dd class="col-sm-8"><?= app_escape(date('d/m/Y H:i', strtotime($issue['received_date']))) ?></dd><dt class="col-sm-4">เจ้าหน้าที่ผู้รับ</dt><dd class="col-sm-8"><?= app_escape($issue['receiver_name'] ?: 'ไม่ได้ระบุ') ?></dd><dt class="col-sm-4">จำนวนที่รับ</dt><dd class="col-sm-8"><?= (int) $issue['quantity_settled'] ?> <?= $unit ?></dd><?php if ($issue['receipt_note']): ?><dt class="col-sm-4">หมายเหตุเจ้าหน้าที่</dt><dd class="col-sm-8" style="white-space:pre-line"><?= app_escape($issue['receipt_note']) ?></dd><?php endif; ?></dl><?php endif; ?>
</div></section><?php endif; ?>
<div class="mt-4"><a href="home.php" class="btn btn-outline-primary">กลับหน้าหลัก</a></div>
</main></body></html>
