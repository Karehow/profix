<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$staffId = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = (string) ($_POST['action_type'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($requestId < 1) {
            throw new BorrowWorkflowException('เลขคำขอไม่ถูกต้อง');
        }
        if ($action === 'approve') {
            borrow_approve($conn, $requestId, $staffId, $note, is_array($_POST['quantities'] ?? null) ? $_POST['quantities'] : [], isset($_POST['stock_missing']));
            flash_set('success', 'อนุมัติคำขอและกันของไว้รอส่งมอบแล้ว');
        } elseif ($action === 'handover') {
            if (empty($_POST['count_confirmed'])) throw new BorrowWorkflowException('กรุณาตรวจนับร่วมกับผู้ยืมก่อนส่งมอบ');
            borrow_handover($conn, $requestId, $staffId, (int) ($_POST['allocation_version'] ?? -1));
            flash_set('success', 'ยืนยันการส่งมอบสิ่งของแล้ว');
        } elseif ($action === 'revise_allocation') {
            borrow_revise_allocation($conn, $requestId, $staffId, is_array($_POST['quantities'] ?? null) ? $_POST['quantities'] : [], $note, isset($_POST['stock_missing']), (int) ($_POST['allocation_version'] ?? -1));
            flash_set('success', 'บันทึกจำนวนจัดให้แล้ว ผู้ยืมต้องยอมรับจำนวนใหม่ก่อนส่งมอบ');
        } elseif ($action === 'reject') {
            borrow_reject($conn, $requestId, $staffId, $note);
            flash_set('success', 'บันทึกการไม่อนุมัติแล้ว');
        } elseif ($action === 'cancel') {
            borrow_cancel($conn, $requestId, $staffId, true, $note);
            flash_set('success', 'ยกเลิกคำขอและคืนยอดที่กันไว้แล้ว');
        } else {
            throw new BorrowWorkflowException('การดำเนินการไม่ถูกต้อง');
        }
    } catch (Throwable $e) {
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่');
    }
    app_redirect('requests.php?status=' . ($action === 'approve' || $action === 'revise_allocation' ? 'approved' : 'pending_approval'));
}

$allowedFilters = ['all', 'pending_approval', 'approved', 'borrowed', 'return_requested', 'returned', 'partially_damaged', 'rejected', 'cancelled'];
$requestedFilter = (string) ($_GET['status'] ?? 'pending_approval');
$filter = in_array($requestedFilter, $allowedFilters, true) ? $requestedFilter : 'all';
$where = $filter === 'all' ? '' : 'WHERE br.status = ?';
$sql = "SELECT br.*, u.first_name, u.last_name, u.phone_number, u.address_detail, u.latitude, u.longitude,
               approver.first_name AS approver_first_name, approver.last_name AS approver_last_name
        FROM borrow_requests br
        JOIN users u ON u.user_id = br.user_id
        LEFT JOIN users approver ON approver.user_id = br.approved_by_user_id
        $where
        ORDER BY FIELD(br.status,'pending_approval','approved','return_requested','borrowed','partially_damaged','returned','rejected','cancelled'),
                 br.created_at DESC";
if ($filter === 'all') {
    $requestsResult = mysqli_query($conn, $sql);
} else {
    $requestsStmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($requestsStmt, 's', $filter);
    mysqli_stmt_execute($requestsStmt);
    $requestsResult = mysqli_stmt_get_result($requestsStmt);
}
$requests = [];
$requestIds = [];
while ($requestsResult && $row = mysqli_fetch_assoc($requestsResult)) {
    $requests[(int) $row['request_id']] = $row;
    $requestIds[] = (int) $row['request_id'];
}

$itemsByRequest = [];
if ($requestIds) {
    $ids = implode(',', $requestIds);
    $itemRows = mysqli_query($conn, "SELECT bi.*,
            i.item_name, i.image_url, i.is_set
        FROM borrow_items bi
        JOIN items i ON i.item_id = bi.item_id
        WHERE bi.request_id IN ($ids)
        ORDER BY bi.request_id, bi.borrow_item_id");
    while ($itemRows && $item = mysqli_fetch_assoc($itemRows)) {
        $item['components'] = [];
        $itemsByRequest[(int) $item['request_id']][(int) $item['borrow_item_id']] = $item;
    }
    $snapshotRows = mysqli_query($conn, "SELECT bic.*, bi.request_id
        FROM borrow_item_components bic
        JOIN borrow_items bi ON bi.borrow_item_id = bic.borrow_item_id
        WHERE bi.request_id IN ($ids)
        ORDER BY bic.borrow_item_id, bic.borrow_item_component_id");
    while ($snapshotRows && $component = mysqli_fetch_assoc($snapshotRows)) {
        $requestId = (int) $component['request_id'];
        $borrowItemId = (int) $component['borrow_item_id'];
        if (isset($itemsByRequest[$requestId][$borrowItemId])) {
            $itemsByRequest[$requestId][$borrowItemId]['components'][] = $component;
        }
    }
}
$flash = flash_take();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>จัดการคำขอยืมสิ่งของวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <link rel="stylesheet" href="../assets/css/borrow-workflow.css">
    <style>
        .item-thumb{width:58px;height:58px;object-fit:cover;border-radius:.5rem;border:1px solid #dee2e6;background:#fff}
        .request-card{scroll-margin-top:1rem}
    </style>
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'staff/requests.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">จัดการคำขอยืมสิ่งของวัด</h3>
            <div class="text-muted">ตรวจคำขอ → อนุมัติและกันของ → ยืนยันส่งมอบ → ตรวจรับคืน</div>
        </div>
        <div class="d-flex gap-2">
            <a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a>
            <a href="return_check.php" class="btn btn-warning">ตรวจรับคืน</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php require __DIR__ . '/../config/staff_workflow_queues.php'; ?>

    <form method="get" class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-end gap-2">
            <div>
                <label class="form-label small mb-1" for="statusFilter">กรองสถานะ</label>
                <select class="form-select" id="statusFilter" name="status">
                    <option value="all">ทั้งหมด</option>
                    <?php foreach (array_slice($allowedFilters, 1) as $status): ?>
                        <option value="<?= app_escape($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= app_escape(borrow_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary">แสดงรายการ</button>
        </div>
    </form>

    <?php if (!$requests): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">ไม่พบคำขอตามตัวกรองนี้</div></div>
    <?php endif; ?>

    <?php foreach ($requests as $requestId => $request): ?>
        <?php $requestItems = array_values($itemsByRequest[$requestId] ?? []); ?>
        <section class="card request-card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div>
                    <span class="fw-bold fs-5">คำขอ #<?= $requestId ?></span>
                    <span class="badge <?= borrow_status_badge($request['status']) ?> ms-2"><?= app_escape(borrow_status_label($request['status'])) ?></span>
                </div>
                <div class="small text-muted">ส่งคำขอ <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-3">
                        <div class="fw-semibold"><?= app_escape(trim($request['first_name'] . ' ' . $request['last_name'])) ?></div>
                        <div class="small text-muted">โทร <?= app_escape($request['phone_number']) ?></div>
                        <div class="small mt-2"><?= nl2br(app_escape($request['address_detail'] ?: '-')) ?></div>
                        <?php if ($request['latitude'] !== null && $request['longitude'] !== null): ?>
                            <a class="btn btn-sm btn-outline-secondary mt-2" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= urlencode($request['latitude']) ?>&mlon=<?= urlencode($request['longitude']) ?>#map=17/<?= urlencode($request['latitude']) ?>/<?= urlencode($request['longitude']) ?>">ดูพิกัด</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-5">
                        <?php foreach ($requestItems as $item): ?>
                            <div class="d-flex gap-3 border rounded p-2 mb-2">
                                <img class="item-thumb" src="<?= app_escape($item['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt="">
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong><?= app_escape($item['item_name']) ?></strong>
                                        <?php $exact_components = array_filter($item['components'], static fn($c) => isset($c['quantity_borrowed'])); ?>
                                        <span><?= $exact_components ? array_sum(array_column($exact_components, 'quantity_borrowed')) . ' ชิ้น (ของย่อย)' : (int) $item['quantity_borrowed'] . ((int) $item['is_set'] === 1 ? ' ชุด' : ' ชิ้น') ?></span>
                                    </div>
                                    <?php if ((int) $item['is_set'] === 1): ?>
                                        <div class="small mt-1">
                                            <?php if (!$item['components']): ?>
                                                <span class="text-muted">ไม่มีข้อมูลของย่อยในชุด</span>
                                            <?php else: ?>
                                                <?php foreach ($item['components'] as $component): ?>
                                                    <span class="badge <?= (int) $component['is_included'] === 1 ? 'bg-success' : 'bg-light text-muted text-decoration-line-through' ?> me-1 mb-1">
                                                        <?= app_escape($component['component_name']) ?> <?= component_borrowed_quantity($component, (int) $item['quantity_borrowed']) ?> <?= app_escape($component['unit']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php require __DIR__ . '/../config/borrow_quantity_summary.php'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-lg-2">
                        <div class="small text-muted">วันที่รับของที่ต้องการ</div>
                        <div class="fw-semibold"><?= $request['requested_pickup_at'] ? date('d/m/Y H:i', strtotime($request['requested_pickup_at'])) : 'เร็วที่สุด' ?></div>
                        <div class="small text-muted mt-2">กำหนดคืน</div>
                        <div class="fw-semibold <?= in_array($request['status'], ['borrowed','return_requested'], true) && strtotime($request['expected_return_date']) < time() ? 'text-danger' : '' ?>">
                            <?= $request['expected_return_date'] ? date('d/m/Y H:i', strtotime($request['expected_return_date'])) : '-' ?>
                        </div>
                        <?php if ($request['decision_note']): ?><div class="small text-muted mt-2">หมายเหตุ: <?= nl2br(app_escape($request['decision_note'])) ?></div><?php endif; ?>
                    </div>
                    <div class="col-lg-12 workflow-actions">
                        <?php if ($request['status'] === 'pending_approval'): ?>
                            <form method="post" class="mb-2" id="allocate<?= $requestId ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                <input type="hidden" name="action_type" value="approve">
                                <?php require __DIR__ . '/../config/allocation_fields.php'; ?>
                                <input class="form-control form-control-sm mb-1" name="note" maxlength="500" placeholder="หมายเหตุ (ถ้ามี)">
                                <button class="btn btn-success btn-sm w-100">อนุมัติและกันของ</button>
                            </form>
                            <form method="post" onsubmit="return confirm('ยืนยันว่าไม่อนุมัติคำขอนี้?')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                <input type="hidden" name="action_type" value="reject">
                                <input class="form-control form-control-sm mb-1" name="note" maxlength="500" required placeholder="เหตุผลที่ไม่อนุมัติ">
                                <button class="btn btn-outline-danger btn-sm w-100">ไม่อนุมัติ</button>
                            </form>
                        <?php elseif ($request['status'] === 'approved'): ?>
                            <details class="mb-3"><summary>ของไม่ครบ / ปรับจำนวนจัดให้</summary><form method="post" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                <input type="hidden" name="action_type" value="revise_allocation">
                                <input type="hidden" name="allocation_version" value="<?= (int) $request['allocation_version'] ?>">
                                <?php require __DIR__ . '/../config/allocation_fields.php'; ?>
                                <input class="form-control form-control-sm mb-2" name="note" maxlength="500" required placeholder="เหตุผลที่ปรับจำนวน">
                                <button class="btn btn-outline-primary btn-sm w-100">แจ้งจำนวนใหม่ให้ผู้ยืม</button>
                            </form></details>
                            <?php $awaitingAcceptance = (int) $request['allocation_version'] > (int) $request['accepted_allocation_version']; ?>
                            <p class="small <?= $awaitingAcceptance ? 'text-warning' : 'text-success' ?>"><?= $awaitingAcceptance ? 'รอผู้ยืมยอมรับจำนวนใหม่ในหน้ายืมของฉัน' : 'พร้อมตรวจนับและส่งมอบ' ?></p>
                            <form method="post" class="mb-2" onsubmit="return confirm('ตรวจนับของครบและส่งมอบให้ผู้ยืมแล้ว?')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                <input type="hidden" name="action_type" value="handover">
                                <input type="hidden" name="allocation_version" value="<?= (int) $request['allocation_version'] ?>">
                                <label class="small mb-2"><input type="checkbox" name="count_confirmed" required> ตรวจนับร่วมกับผู้ยืมครบตามจำนวนจัดให้แล้ว</label>
                                <button class="btn btn-primary btn-sm w-100" <?= $awaitingAcceptance ? 'disabled' : '' ?>>ยืนยันส่งมอบ</button>
                            </form>
                            <a class="btn btn-outline-secondary btn-sm w-100 mb-2" href="../admin/handover_pdf.php?request_id=<?= $requestId ?>">ใบส่งมอบ PDF</a>
                            <form method="post" onsubmit="return confirm('ยกเลิกคำขอและคืนของที่กันไว้?')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                <input type="hidden" name="action_type" value="cancel">
                                <input class="form-control form-control-sm mb-1" name="note" maxlength="500" required placeholder="เหตุผลที่ยกเลิก">
                                <button class="btn btn-outline-danger btn-sm w-100">ยกเลิก</button>
                            </form>
                        <?php elseif ($request['status'] === 'return_requested'): ?>
                            <a href="return_check.php" class="btn btn-warning btn-sm w-100">ไปตรวจรับคืน</a>
                        <?php elseif (in_array($request['status'], ['borrowed','returned','partially_damaged'], true)): ?>
                            <a class="btn btn-outline-secondary btn-sm w-100" href="../admin/handover_pdf.php?request_id=<?= $requestId ?>">เอกสาร PDF</a>
                        <?php else: ?>
                            <span class="text-muted small">ดำเนินการแล้ว</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
