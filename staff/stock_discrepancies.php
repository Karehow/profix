<?php
require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['staff','admin'], '../config/login.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        resolve_inventory_discrepancy($conn, (int) ($_POST['id'] ?? 0), (int) $currentUser['user_id'], (string) ($_POST['resolution'] ?? ''), (string) ($_POST['note'] ?? ''));
        flash_set('success', 'บันทึกผลตรวจคลังแล้ว');
    } catch (Throwable $e) { flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'บันทึกไม่สำเร็จ'); }
    app_redirect('stock_discrepancies.php');
}
$rows = mysqli_query($conn, "SELECT d.*, i.item_name FROM inventory_discrepancies d JOIN items i ON i.item_id=d.item_id ORDER BY d.resolution='pending' DESC, d.discrepancy_id DESC");
$flash = flash_take();
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ตรวจสอบของในคลัง</title><link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css"><link rel="stylesheet" href="../assets/css/app-ui.css"><?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light"><?php $uiPage='staff/stock_discrepancies.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container-fluid p-4"><h1 class="h3">ตรวจสอบของในคลัง</h1><p>ของที่หาไม่พบก่อนส่งมอบ กันไว้จากยอดพร้อมยืม และไม่คิดเป็นของที่ผู้ยืมทำหาย</p>
<?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
<?php if (!mysqli_num_rows($rows)): ?><div class="alert alert-success">ไม่มีรายการรอตรวจสอบ</div><?php endif; ?>
<?php while ($row=mysqli_fetch_assoc($rows)): ?><section class="card mb-3"><div class="card-body"><h2 class="h5"><?= app_escape($row['item_name']) ?> — <?= (int) $row['quantity'] ?> ชิ้น</h2><p>พบระหว่างจัดคำขอ #<?= (int) $row['request_id'] ?>: <?= app_escape($row['note']) ?></p>
<?php if ($row['resolution']==='pending'): ?><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int) $row['discrepancy_id'] ?>"><div class="col-md-4"><label class="form-label">ผลตรวจครบทั้ง <?= (int) $row['quantity'] ?> ชิ้น</label><select class="form-select" name="resolution" required><option value="">เลือกผลตรวจ</option><option value="found">พบของครบและพร้อมใช้งาน — คืนเข้าคลัง</option><option value="written_off">ตรวจยืนยันสูญหาย — ตัดยอดทั้งหมด</option></select></div><div class="col-md-5"><label class="form-label">เหตุผล / ผลการตรวจ</label><input name="note" class="form-control" maxlength="500" required></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-success">บันทึกผลตรวจคลัง</button></div></form>
<?php else: ?><p class="text-success"><?= $row['resolution']==='found' ? 'พบของและคืนเข้าคลังแล้ว' : 'ตัดยอดสูญหายในคลังแล้ว' ?> · <?= app_escape($row['resolution_note']) ?></p><?php endif; ?>
</div></section><?php endwhile; ?></main></body></html>
