<?php
require_once __DIR__ . '/../config/replacement_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['user'], 'index.php');
$userId = (int) $currentUser['user_id'];
$requestId = max(0, (int) ($_GET['request_id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        replacement_submit($conn, $userId, (int) ($_POST['inspection_id'] ?? 0), $_FILES['replacement_image'] ?? [], trim((string) ($_POST['note'] ?? '')));
        flash_set('success', 'ส่งหลักฐานแล้ว กรุณานำของให้เจ้าหน้าที่ตรวจรับ รายการจะเสร็จเมื่อเจ้าหน้าที่ยืนยันว่าได้รับของจริง');
    } catch (Throwable $e) {
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถส่งหลักฐานได้ กรุณาลองอีกครั้ง');
    }
    app_redirect('compensation.php' . ($requestId ? '?request_id=' . $requestId : ''));
}
$stmt = mysqli_prepare($conn, 'SELECT ri.*, bi.request_id, i.item_name, COALESCE(bic.component_name, ic.component_name) AS component_name FROM return_inspections ri JOIN borrow_items bi ON bi.borrow_item_id=ri.borrow_item_id JOIN borrow_requests br ON br.request_id=bi.request_id JOIN items i ON i.item_id=bi.item_id LEFT JOIN borrow_item_components bic ON bic.borrow_item_component_id=ri.borrow_item_component_id LEFT JOIN item_components ic ON ic.component_id=ri.component_id WHERE br.user_id=? AND (?=0 OR br.request_id=?) AND (ri.damaged_quantity>0 OR ri.lost_quantity>0) ORDER BY ri.is_resolved, ri.inspection_id DESC');
mysqli_stmt_bind_param($stmt, 'iii', $userId, $requestId, $requestId);
mysqli_stmt_execute($stmt);
$issues = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
$flash = flash_take();
?>
<!doctype html>
<html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>หลักฐานของชำรุดและการชดใช้</title>
<link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<style>.evidence{display:block;max-width:100%;width:320px;max-height:280px;object-fit:contain;border-radius:.5rem;background:#f8f9fa}section{scroll-margin-top:1rem}</style><?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'user/compensation.php'; require __DIR__ . '/../config/page_shell.php'; ?><main class="container" style="max-width:960px">
<div class="d-flex flex-wrap justify-content-between gap-2 mb-4"><div><h1 class="h3 fw-bold">หลักฐานของชำรุดและการชดใช้</h1><p class="text-muted mb-0">ดูหลักฐาน ซื้อของมาคืนวัด และส่งยืนยันให้เจ้าหน้าที่ตรวจรับ</p></div><div><a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a> <a href="history.php" class="btn btn-outline-secondary">ประวัติการยืม</a></div></div>
<?php if ($flash): ?><div role="alert" class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
<?php if (!$issues): ?><div class="alert alert-info">ไม่พบรายการชดใช้ของคุณ<?= $requestId ? 'สำหรับคำขอนี้' : '' ?></div><?php endif; ?>
<?php foreach ($issues as $issue): $id = (int) $issue['inspection_id']; ?>
<section id="inspection-<?= $id ?>" class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
<div class="d-flex flex-wrap justify-content-between gap-2"><h2 class="h5 fw-bold"><?= app_escape($issue['item_name']) ?><?= $issue['component_name'] ? ' · ' . app_escape($issue['component_name']) : '' ?></h2><span class="badge <?= $issue['is_resolved'] ? 'bg-success' : ($issue['replacement_submitted_at'] ? 'bg-warning text-dark' : 'bg-danger') ?> align-self-start"><?= $issue['is_resolved'] ? 'เจ้าหน้าที่ยืนยันชดใช้แล้ว' : ($issue['replacement_submitted_at'] ? 'รอเจ้าหน้าที่ยืนยันรับของจริง' : 'รอชดใช้') ?></span></div>
<p class="text-muted">คำขอ #<?= (int) $issue['request_id'] ?> · ชำรุด <?= (int) $issue['damaged_quantity'] ?> · สูญหาย <?= (int) $issue['lost_quantity'] ?></p>
<h3 class="h6 fw-bold">หลักฐานจากเจ้าหน้าที่</h3><p style="white-space:pre-line"><?= app_escape($issue['damage_description'] ?: 'ไม่ได้ระบุรายละเอียดเพิ่มเติม') ?></p>
<?php if ($issue['damage_image_url']): ?><a href="<?= app_escape($issue['damage_image_url']) ?>" target="_blank" rel="noopener"><img class="evidence mb-3" src="<?= app_escape($issue['damage_image_url']) ?>" alt="หลักฐานสภาพชำรุดจากเจ้าหน้าที่"></a><?php else: ?><p class="text-muted">เจ้าหน้าที่ยังไม่ได้แนบรูปหลักฐาน</p><?php endif; ?>
<?php if ($issue['replacement_submitted_at']): ?>
<hr><h3 class="h6 fw-bold">รายการของทดแทนที่คุณส่ง</h3><?php if ($issue['replacement_image_url']): ?><a href="<?= app_escape($issue['replacement_image_url']) ?>" target="_blank" rel="noopener"><img class="evidence mb-2" src="<?= app_escape($issue['replacement_image_url']) ?>" alt="ของที่ซื้อคืนวัด"></a><?php endif; ?><p class="small text-muted">ส่งเมื่อ <?= app_escape($issue['replacement_submitted_at']) ?></p><p style="white-space:pre-line"><?= app_escape($issue['replacement_note']) ?></p>
<?php if (!$issue['is_resolved']): ?><div class="alert alert-warning mb-0">ส่งยืนยันแล้ว กรุณานำของทดแทนให้เจ้าหน้าที่ตรวจรับ รายการจะเสร็จเมื่อเจ้าหน้าที่ยืนยันรับของจริง</div><?php endif; ?>
<?php elseif (!$issue['is_resolved']): ?>
<p>ซื้อของทดแทนให้ตรงรายการ แล้วตรวจสอบจำนวนก่อนส่งยืนยัน ไม่จำเป็นต้องแนบรูป</p>
<?php endif; ?>
<a class="btn btn-primary mt-3" href="replacement_confirm.php?inspection_id=<?= $id ?>"><?= $issue['is_resolved'] ? 'ดูผลยืนยันรับของทดแทน' : ($issue['replacement_submitted_at'] ? 'ติดตามการยืนยันรับของ' : 'ซื้อของมาคืนวัด / ยืนยันของทดแทน') ?></a>
</div></section><?php endforeach; ?>
</main></body></html>
