<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['user'], 'index.php');
$userId = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'request_return') {
            borrow_request_return($conn, $requestId, $userId);
            flash_set('success', 'แจ้งพร้อมส่งคืนแล้ว กรุณานำของให้เจ้าหน้าที่ตรวจรับ');
        } elseif ($action === 'accept_allocation') {
            borrow_accept_allocation($conn, $requestId, $userId, (int) ($_POST['allocation_version'] ?? -1));
            flash_set('success', 'ยอมรับจำนวนจัดให้แล้ว กรุณาตรวจนับร่วมกับเจ้าหน้าที่ตอนรับของ');
        } elseif ($action === 'cancel') {
            borrow_cancel($conn, $requestId, $userId, false, 'ผู้ยืมยกเลิกคำขอ');
            flash_set('success', 'ยกเลิกคำขอเรียบร้อยแล้ว');
        } else {
            throw new BorrowWorkflowException('การดำเนินการไม่ถูกต้อง');
        }
    } catch (Throwable $e) {
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถดำเนินการได้');
    }
    app_redirect('history.php#request-' . $requestId);
}

$requestedStatus = (string) ($_GET['status'] ?? 'all');
$statusFilter = in_array($requestedStatus, ['all','active','completed','pending_approval','approved','borrowed','return_requested','returned','partially_damaged','rejected','cancelled'], true)
    ? $requestedStatus : 'all';
$where = 'br.user_id = ?';
$filterParams = [$userId];
$filterTypes = 'i';
if ($statusFilter === 'active') {
    $where .= " AND br.status IN ('pending_approval','approved','borrowed','return_requested')";
} elseif ($statusFilter === 'completed') {
    $where .= " AND br.status IN ('returned','partially_damaged','rejected','cancelled')";
} elseif ($statusFilter !== 'all') {
    $where .= ' AND br.status = ?';
    $filterParams[] = $statusFilter;
    $filterTypes .= 's';
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$validDate = static function ($value): string {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) return '';
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : '';
};
$dateFrom = $validDate($_GET['from'] ?? '');
$dateTo = $validDate($_GET['to'] ?? '');
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
if ($dateFrom !== '') { $where .= ' AND br.created_at >= ?'; $filterParams[] = $dateFrom . ' 00:00:00'; $filterTypes .= 's'; }
if ($dateTo !== '') { $where .= ' AND br.created_at <= ?'; $filterParams[] = $dateTo . ' 23:59:59'; $filterTypes .= 's'; }
if ($search !== '') {
    $where .= ' AND (CAST(br.request_id AS CHAR) = ? OR EXISTS (SELECT 1 FROM borrow_items bi JOIN items i ON i.item_id=bi.item_id WHERE bi.request_id=br.request_id AND i.item_name LIKE ?))';
    $filterParams[] = ltrim($search, '#');
    $filterParams[] = '%' . $search . '%';
    $filterTypes .= 'ss';
}
$sql = "SELECT br.* FROM borrow_requests br WHERE $where ORDER BY br.created_at DESC";
$requestsStmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($requestsStmt, $filterTypes, ...$filterParams);
mysqli_stmt_execute($requestsStmt);
$requests = mysqli_stmt_get_result($requestsStmt);
$flash = flash_take();
$historySummary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total,
    COALESCE(SUM(status IN ('borrowed','return_requested')),0) AS borrowing,
    COALESCE(SUM(status IN ('returned','partially_damaged')),0) AS returned,
    COALESCE(SUM(status='partially_damaged' AND settlement_status<>'resolved'),0) AS damaged
    FROM borrow_requests WHERE user_id=$userId"));
$inbox = ['unread'=>(int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM notifications WHERE user_id=$userId AND is_read=0"))[0]];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ประวัติการยืมคืน</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?>
<link rel="stylesheet" href="../assets/css/user-dashboard.css?v=3">
<link rel="stylesheet" href="../assets/css/borrow-history.css?v=1">
</head>
<body class="app-ui temple-dashboard history-page">
<?php $uiPage = 'user/history.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container">
    <header class="history-heading"><div><h1>ประวัติและสถานะการยืม</h1><p>ข้อมูลทั้งหมดแสดงจากบัญชีที่เข้าสู่ระบบ ไม่ต้องค้นหาด้วยเบอร์โทรศัพท์</p></div><nav aria-label="ตำแหน่งหน้า"><a href="home.php">หน้าหลัก</a><span aria-hidden="true">›</span><span>ประวัติและสถานะ</span></nav></header>
    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="การยืมของฉัน"><a href="borrow.php" class="btn btn-success">เลือกยืมสิ่งของ</a><a href="confirm.php" class="btn btn-outline-success">รายการที่เลือก</a><a href="return.php" class="btn btn-outline-success">แจ้งคืน / ดูยอดค้างคืน</a><a href="compensation.php" class="btn btn-outline-success">คืนของทดแทน</a><a href="reservations.php" class="btn btn-outline-success">จองล่วงหน้า</a></nav>
    <div class="history-stats" aria-label="สรุปคำขอทั้งหมดของคุณ">
    <?php foreach ([['total','ทั้งหมด','list','neutral'],['borrowing','กำลังยืม','clock','blue'],['returned','คืนแล้ว','check','green'],['damaged','ชำรุด / สูญหาย รอชดใช้','return','red']] as [$key,$label,$icon,$tone]): ?>
    <div class="history-stat history-stat-<?= $tone ?>"><span class="history-stat-icon"><svg class="ui-icon" aria-hidden="true"><use href="../assets/images/ui-icons.svg#<?= $icon ?>"></use></svg></span><div><h2><?= $label ?></h2><strong><?= (int) $historySummary[$key] ?></strong><small>รายการ</small></div><span class="history-stat-bars" aria-hidden="true">▂▃▅▇</span></div>
    <?php endforeach; ?></div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>" role="alert"><?= app_escape($flash['message']) ?></div><?php endif; ?>
    <form method="get" class="history-filters">
        <div><label for="historyStatus">กรองสถานะ</label><select id="historyStatus" name="status" class="form-select"><option value="all">ทั้งหมด</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>กำลังดำเนินการ</option><option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option><?php foreach (['pending_approval','approved','borrowed','return_requested','returned','partially_damaged','rejected','cancelled'] as $status): ?><option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= app_escape(borrow_status_label($status)) ?></option><?php endforeach; ?></select></div>
        <div><label for="historyFrom">วันที่ส่งคำขอ ตั้งแต่</label><input id="historyFrom" type="date" name="from" class="form-control" value="<?= app_escape($dateFrom) ?>"></div>
        <div><label for="historyTo">ถึงวันที่</label><input id="historyTo" type="date" name="to" class="form-control" value="<?= app_escape($dateTo) ?>"></div>
        <div class="history-search"><label for="historySearch">ค้นหารายการ</label><input id="historySearch" type="search" name="q" class="form-control" value="<?= app_escape($search) ?>" placeholder="ชื่ออุปกรณ์ หรือเลขคำขอ เช่น #8"></div>
        <button class="btn btn-primary" type="submit">กรองข้อมูล</button><a class="btn btn-outline-primary" href="history.php">รีเซ็ต</a>
    </form>
    <?php if (!$requests || mysqli_num_rows($requests) === 0): ?><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">ไม่พบประวัติการยืมตามตัวกรองนี้</div></div><?php endif; ?>
    <?php while ($request = mysqli_fetch_assoc($requests)): $requestId = (int) $request['request_id']; ?>
        <details open id="request-<?= $requestId ?>" class="request-card card border-0 shadow-sm mb-3">
            <summary class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div><strong class="fs-5">คำขอ #<?= $requestId ?></strong><span class="badge <?= borrow_status_badge($request['status']) ?> ms-2"><?= app_escape(borrow_status_label($request['status'])) ?></span><?php if ($request['status'] === 'partially_damaged'): ?><span class="badge <?= $request['settlement_status'] === 'resolved' ? 'bg-success' : 'bg-warning text-dark' ?> ms-1"><?= $request['settlement_status'] === 'resolved' ? 'ชดใช้ครบแล้ว' : 'รอชดใช้' ?></span><?php endif; ?></div>
                <div class="small text-muted">ส่งคำขอ <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?> <span class="history-chevron" aria-hidden="true">⌄</span></div>
            </summary>
            <div class="card-body">
                <div class="row g-2 small mb-3">
                    <div class="col-md-4"><span class="text-muted">ต้องการรับ:</span> <?= $request['requested_pickup_at'] ? date('d/m/Y H:i', strtotime($request['requested_pickup_at'])) : '-' ?></div>
                    <div class="col-md-4"><span class="text-muted">ส่งมอบจริง:</span> <?= $request['handed_over_at'] ? date('d/m/Y H:i', strtotime($request['handed_over_at'])) : '-' ?></div>
                    <div class="col-md-4"><span class="text-muted">กำหนดคืน:</span> <span class="<?= $request['expected_return_date'] && in_array($request['status'], ['borrowed','return_requested'], true) && strtotime($request['expected_return_date']) < time() ? 'text-danger fw-bold' : '' ?>"><?= $request['expected_return_date'] ? date('d/m/Y H:i', strtotime($request['expected_return_date'])) : '-' ?></span></div>
                </div>
                <?php if ($request['decision_note']): ?><div class="alert alert-light border py-2 small">หมายเหตุเจ้าหน้าที่: <?= nl2br(app_escape($request['decision_note'])) ?></div><?php endif; ?>
                <div class="table-responsive"><table class="table table-bordered align-middle"><thead class="table-light"><tr><th>รายการ</th><th>จำนวน</th><th>ของย่อยในชุด</th><th>ผลตรวจรับ</th></tr></thead><tbody>
                <?php
                $itemsStmt = mysqli_prepare($conn, 'SELECT bi.*, i.item_name, i.image_url, i.is_set,
                    (SELECT SUM(bc.quantity_borrowed) FROM borrow_item_components bc WHERE bc.borrow_item_id=bi.borrow_item_id) AS component_piece_count
                    FROM borrow_items bi JOIN items i ON i.item_id = bi.item_id WHERE bi.request_id = ? ORDER BY bi.borrow_item_id');
                mysqli_stmt_bind_param($itemsStmt, 'i', $requestId);
                mysqli_stmt_execute($itemsStmt);
                $items = mysqli_stmt_get_result($itemsStmt);
                if (mysqli_num_rows($items) === 0): ?><tr><td colspan="4" class="history-empty">ไม่มีรายการอุปกรณ์ในคำขอนี้</td></tr><?php endif;
                while ($item = mysqli_fetch_assoc($items)):
                    $borrowItemId = (int) $item['borrow_item_id'];
                ?>
                    <tr>
                        <td><div class="d-flex align-items-center gap-2"><img class="item-thumb" src="<?= app_escape($item['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt=""><strong><?= app_escape($item['item_name']) ?></strong></div></td>
                        <td><?= $item['component_piece_count'] !== null ? (int) $item['component_piece_count'] . ' ชิ้น (ของย่อย)' : (int) $item['quantity_borrowed'] . ((int) $item['is_set'] === 1 ? ' ชุด' : ' ชิ้น') ?><?php require __DIR__ . '/../config/borrow_quantity_summary.php'; ?><div class="small text-muted">ตรวจรับแล้ว <?= $item['component_piece_count'] !== null ? ((int) $item['quantity_returned'] > 0 ? (int) $item['component_piece_count'] : 0) . ' ชิ้น' : (int) $item['quantity_returned'] ?></div></td>
                        <td>
                            <?php if ((int) $item['is_set'] === 1):
                                $componentsStmt = mysqli_prepare($conn, 'SELECT component_name, quantity_per_set, quantity_borrowed, unit, is_included FROM borrow_item_components WHERE borrow_item_id = ? ORDER BY is_included DESC, borrow_item_component_id');
                                mysqli_stmt_bind_param($componentsStmt, 'i', $borrowItemId);
                                mysqli_stmt_execute($componentsStmt);
                                $components = mysqli_stmt_get_result($componentsStmt);
                                if (mysqli_num_rows($components) === 0): ?><span class="text-muted small">ไม่มี snapshot</span><?php else: while ($component = mysqli_fetch_assoc($components)): ?><span class="badge <?= (int) $component['is_included'] === 1 ? 'bg-success' : 'bg-light text-muted text-decoration-line-through' ?> me-1 mb-1"><?= app_escape($component['component_name']) ?> <?= component_borrowed_quantity($component, (int) $item['quantity_borrowed']) ?> <?= app_escape($component['unit']) ?></span><?php endwhile; endif; mysqli_stmt_close($componentsStmt); ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $issuesStmt = mysqli_prepare($conn, "SELECT ri.*, COALESCE(bic.component_name, ic.component_name) AS component_name, dc.method, dc.amount, dc.received_date
                                FROM return_inspections ri
                                LEFT JOIN borrow_item_components bic ON bic.borrow_item_component_id = ri.borrow_item_component_id
                                LEFT JOIN item_components ic ON ic.component_id = ri.component_id
                                LEFT JOIN damage_compensations dc ON dc.inspection_id = ri.inspection_id
                                WHERE ri.borrow_item_id = ? ORDER BY ri.inspection_id");
                            mysqli_stmt_bind_param($issuesStmt, 'i', $borrowItemId);
                            mysqli_stmt_execute($issuesStmt);
                            $issues = mysqli_stmt_get_result($issuesStmt);
                            $issueCount = 0;
                            while ($issue = mysqli_fetch_assoc($issues)):
                                if ((int) $issue['damaged_quantity'] === 0 && (int) $issue['lost_quantity'] === 0) continue;
                                $issueCount++;
                            ?>
                                <div class="border rounded p-2 mb-1 small"><strong><?= app_escape($issue['component_name'] ?: ((int) $item['is_set'] === 1 ? 'ทั้งชุด/ตัวชุดหลัก' : 'ตัวสิ่งของ')) ?></strong><br>ชำรุด <?= (int) $issue['damaged_quantity'] ?> · สูญหาย <?= (int) $issue['lost_quantity'] ?><br>ซื้อของมาคืนวัด · <span class="<?= (int) $issue['is_resolved'] === 1 ? 'text-success' : 'text-danger' ?>"><?= (int) $issue['is_resolved'] === 1 ? 'ชดใช้แล้ว' : 'รอชดใช้' ?></span><?php if ($issue['damage_description']): ?><div class="text-muted"><?= app_escape($issue['damage_description']) ?></div><?php endif; ?><?php if ($issue['damage_image_url']): ?><a href="<?= app_escape($issue['damage_image_url']) ?>" target="_blank" rel="noopener">ดูรูปหลักฐาน</a><?php endif; ?></div>
                                <a class="btn btn-sm btn-outline-primary mb-2" href="compensation.php?request_id=<?= $requestId ?>#inspection-<?= (int) $issue['inspection_id'] ?>">ดูหลักฐาน / ยืนยันของทดแทน</a>
                            <?php endwhile; mysqli_stmt_close($issuesStmt); ?>
                            <?php if ($issueCount === 0): ?><?= (int) $item['quantity_returned'] > 0 ? '<span class="text-success small">ไม่พบความเสียหาย</span>' : '<span class="text-muted small">ยังไม่ตรวจรับ</span>' ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; mysqli_stmt_close($itemsStmt); ?>
                </tbody></table></div>
                <?php if ($request['status'] === 'approved' && (int) $request['allocation_version'] > 0): ?>
                <div class="alert alert-warning mt-3">
                    <strong>เจ้าหน้าที่ปรับจำนวนจัดให้</strong><p class="mb-2"><?= app_escape($request['decision_note'] ?? '') ?></p>
                    <?php if ((int) $request['accepted_allocation_version'] !== (int) $request['allocation_version']): ?>
                    <p>ตรวจสอบจำนวนด้านบน เลือกยอมรับหรือยกเลิกคำขอ ก่อนเดินทางรับของ</p>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="request_id" value="<?= $requestId ?>"><input type="hidden" name="action" value="accept_allocation"><input type="hidden" name="allocation_version" value="<?= (int) $request['allocation_version'] ?>"><button class="btn btn-success">ยอมรับจำนวนที่จัดให้</button></form>
                    <?php else: ?><span>ยอมรับจำนวนแล้ว รอตรวจนับและรับของกับเจ้าหน้าที่</span><?php endif; ?>
                </div><?php endif; ?>
                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <?php if (in_array($request['status'], ['pending_approval','approved'], true)): ?><form method="post" onsubmit="return confirm('ยืนยันยกเลิกคำขอนี้?')"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?= $requestId ?>"><button class="btn btn-outline-danger">ยกเลิกคำขอ</button></form><?php endif; ?>
                    <?php if ($request['status'] === 'borrowed'): ?><form method="post" onsubmit="return confirm('ยืนยันว่าพร้อมนำสิ่งของทั้งหมดมาส่งคืน?')"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="request_return"><input type="hidden" name="request_id" value="<?= $requestId ?>"><button class="btn btn-warning">แจ้งพร้อมส่งคืน</button></form><?php endif; ?>
                    <?php if ($request['status'] === 'return_requested'): ?><span class="alert alert-info py-2 mb-0">แจ้งแล้ว รอเจ้าหน้าที่ตรวจรับ</span><?php endif; ?>
                </div>
            </div>
        </details>
    <?php endwhile; mysqli_stmt_close($requestsStmt); ?>
</main>
<footer class="dashboard-footer">© <?= date('Y') ?> ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด <span>ร่วมแบ่งปัน ใช้สิ่งของอย่างรับผิดชอบ</span></footer>
<script src="../assets/js/user-dashboard.js" defer></script>
</body></html>
