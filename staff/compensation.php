<?php

require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['staff', 'admin'], '../config/login.php');
$staffId = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_compensation'])) {
    require_csrf();
    $inspectionId = (int) ($_POST['inspection_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $amountInput = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);

    mysqli_begin_transaction($conn);
    try {
        if ($inspectionId < 1 || $amountInput === false || (float) $amountInput !== 0.0 || ($_POST['method'] ?? 'replacement') !== 'replacement') {
            throw new BorrowWorkflowException('ข้อมูลการชดใช้ไม่ถูกต้อง');
        }
        if ((function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note)) > 2000) {
            throw new BorrowWorkflowException('หมายเหตุยาวเกิน 2,000 ตัวอักษร');
        }
        $amount = 0;

        // Lock the parent request first, matching the return-inspection lock order.
        // This prevents a return being recorded at the same time from deadlocking
        // with compensation for an earlier partial return of the same request.
        $lookupStmt = mysqli_prepare($conn, 'SELECT bi.request_id
            FROM return_inspections ri
            JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id
            WHERE ri.inspection_id = ?');
        mysqli_stmt_bind_param($lookupStmt, 'i', $inspectionId);
        mysqli_stmt_execute($lookupStmt);
        $lookup = mysqli_fetch_assoc(mysqli_stmt_get_result($lookupStmt));
        mysqli_stmt_close($lookupStmt);
        if (!$lookup) {
            throw new BorrowWorkflowException('ไม่พบรายการที่ต้องชดใช้');
        }
        $requestId = (int) $lookup['request_id'];

        $requestStmt = mysqli_prepare($conn, 'SELECT user_id FROM borrow_requests WHERE request_id = ? FOR UPDATE');
        mysqli_stmt_bind_param($requestStmt, 'i', $requestId);
        mysqli_stmt_execute($requestStmt);
        $requestRow = mysqli_fetch_assoc(mysqli_stmt_get_result($requestStmt));
        mysqli_stmt_close($requestStmt);
        if (!$requestRow) {
            throw new BorrowWorkflowException('ไม่พบคำขอยืมของรายการนี้');
        }
        $requestUserId = (int) $requestRow['user_id'];

        $stmt = mysqli_prepare($conn, "SELECT ri.*, rb.return_batch_id, rb.withheld_quantity, rb.stock_resolution,
                bi.item_id, bi.request_id, i.is_set
            FROM return_inspections ri
            JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id
            JOIN items i ON i.item_id = bi.item_id
            LEFT JOIN return_batches rb ON rb.return_batch_id = ri.return_batch_id
            WHERE ri.inspection_id = ?
            FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $inspectionId);
        mysqli_stmt_execute($stmt);
        $inspection = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$inspection || (int) $inspection['is_resolved'] === 1) {
            throw new BorrowWorkflowException('รายการนี้ได้รับการชดใช้แล้วหรือไม่พบข้อมูล');
        }
        if ((int) $inspection['request_id'] !== $requestId) {
            throw new BorrowWorkflowException('ข้อมูลคำขอยืมของรายการชดใช้ไม่สอดคล้องกัน');
        }
        $quantityNeeded = (int) $inspection['damaged_quantity'] + (int) $inspection['lost_quantity'];
        if ($quantityNeeded < 1) {
            throw new BorrowWorkflowException('รายการนี้ไม่มีจำนวนชำรุดหรือสูญหายที่ต้องชดใช้');
        }

        $method = 'replacement';
        if ($inspection['action_required'] !== 'buy_replacement') {
            throw new BorrowWorkflowException('รายการนี้ต้องกำหนดให้ซื้อของมาคืนวัดก่อน');
        }
        if ($method === 'replacement') {
            $amount = 0;
            if ($inspection['replacement_submitted_at'] !== null && ($_POST['received_replacement'] ?? '') !== '1') {
                throw new BorrowWorkflowException('กรุณายืนยันว่าได้รับของทดแทนจริงครบจำนวนแล้ว');
            }
        }

        $compensation = mysqli_prepare($conn, 'INSERT INTO damage_compensations
            (inspection_id, method, amount, quantity_settled, received_date, note, received_by_user_id)
            VALUES (?, ?, ?, ?, NOW(), ?, ?)');
        mysqli_stmt_bind_param($compensation, 'isdisi', $inspectionId, $method, $amount, $quantityNeeded, $note, $staffId);
        if (!mysqli_stmt_execute($compensation)) {
            $error = mysqli_stmt_errno($compensation) === 1062
                ? 'รายการนี้ได้รับการชดใช้ไปแล้ว'
                : mysqli_stmt_error($compensation);
            mysqli_stmt_close($compensation);
            throw new BorrowWorkflowException($error);
        }
        mysqli_stmt_close($compensation);

        $resolved = mysqli_prepare($conn, 'UPDATE return_inspections SET is_resolved = 1 WHERE inspection_id = ? AND is_resolved = 0');
        mysqli_stmt_bind_param($resolved, 'i', $inspectionId);
        mysqli_stmt_execute($resolved);
        if (mysqli_stmt_affected_rows($resolved) !== 1) {
            throw new BorrowWorkflowException('สถานะรายการถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($resolved);

        $batchId = $inspection['return_batch_id'] !== null ? (int) $inspection['return_batch_id'] : null;
        if ($batchId !== null) {
            $batchStmt = mysqli_prepare($conn, 'SELECT return_batch_id, withheld_quantity, stock_resolution FROM return_batches WHERE return_batch_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($batchStmt, 'i', $batchId);
            mysqli_stmt_execute($batchStmt);
            $batch = mysqli_fetch_assoc(mysqli_stmt_get_result($batchStmt));
            mysqli_stmt_close($batchStmt);
            if (!$batch) {
                throw new BorrowWorkflowException('ไม่พบชุดข้อมูลการคืน');
            }

            $issuesStmt = mysqli_prepare($conn, "SELECT
                    SUM(ri.is_resolved = 0) AS unresolved_count
                FROM return_inspections ri
                LEFT JOIN damage_compensations dc ON dc.inspection_id = ri.inspection_id
                WHERE ri.return_batch_id = ? AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)");
            mysqli_stmt_bind_param($issuesStmt, 'i', $batchId);
            mysqli_stmt_execute($issuesStmt);
            $issues = mysqli_fetch_assoc(mysqli_stmt_get_result($issuesStmt));
            mysqli_stmt_close($issuesStmt);

            if ((int) ($issues['unresolved_count'] ?? 0) === 0 && $batch['stock_resolution'] === 'pending') {
                $withheld = (int) $batch['withheld_quantity'];
                if ($withheld > 0) {
                    inventory_adjust($conn, (int) $inspection['item_id'], $withheld, 0, 'compensation_replacement', $staffId, (int) $inspection['request_id'], (int) $inspection['borrow_item_id'], $batchId, null, 'รับของทดแทนครบและนำกลับเข้าคลัง');
                }
                $resolution = 'restored';
                $batchUpdate = mysqli_prepare($conn, 'UPDATE return_batches SET stock_resolution = ?, resolved_at = NOW() WHERE return_batch_id = ? AND stock_resolution = \'pending\'');
                mysqli_stmt_bind_param($batchUpdate, 'si', $resolution, $batchId);
                mysqli_stmt_execute($batchUpdate);
                if (mysqli_stmt_affected_rows($batchUpdate) !== 1) {
                    throw new BorrowWorkflowException('มีผู้ปิดรายการชดใช้ชุดนี้แล้ว');
                }
                mysqli_stmt_close($batchUpdate);
            }
        } else {
            // Compatibility for records created before return batches existed.
            if ((int) $inspection['is_set'] !== 1 && $quantityNeeded > 0) {
                inventory_adjust($conn, (int) $inspection['item_id'], $quantityNeeded, 0, 'legacy_compensation_replacement', $staffId, (int) $inspection['request_id'], (int) $inspection['borrow_item_id'], null, null, 'ชดใช้รายการเดิมก่อนระบบ return batch');
            }
        }

        $pendingStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total
            FROM return_inspections ri
            JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id
            WHERE bi.request_id = ? AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0) AND ri.is_resolved = 0");
        mysqli_stmt_bind_param($pendingStmt, 'i', $requestId);
        mysqli_stmt_execute($pendingStmt);
        $pending = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($pendingStmt))['total'];
        mysqli_stmt_close($pendingStmt);
        if ($pending === 0) {
            $statusUpdate = mysqli_prepare($conn, "UPDATE borrow_requests SET settlement_status = 'resolved' WHERE request_id = ?");
            mysqli_stmt_bind_param($statusUpdate, 'i', $requestId);
            mysqli_stmt_execute($statusUpdate);
            mysqli_stmt_close($statusUpdate);
        }

        write_audit_log($conn, $staffId, 'record_compensation', 'ผลตรวจ #' . $inspectionId . ' วิธี ' . $method);
        create_notification($conn, $requestUserId, 'บันทึกการชดใช้แล้ว', 'เจ้าหน้าที่ยืนยันรับชดใช้ของคำขอ #' . $requestId . ' แล้ว', '../user/replacement_confirm.php?inspection_id=' . $inspectionId);
        mysqli_commit($conn);
        flash_set('success', 'บันทึกการชดใช้และปรับสต็อกเรียบร้อยแล้ว');
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        flash_set('danger', $e instanceof BorrowWorkflowException ? $e->getMessage() : 'ไม่สามารถบันทึกการชดใช้ได้');
    }
    app_redirect('compensation.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$recordSql = "SELECT ri.*, bi.request_id, i.item_name, i.image_url,
        COALESCE(bic.component_name, ic.component_name) AS component_name,
        u.first_name, u.last_name, rb.stock_resolution,
        dc.compensation_id, dc.method AS completed_method,
        dc.quantity_settled, dc.received_date, dc.note
    FROM return_inspections ri
    JOIN borrow_items bi ON bi.borrow_item_id = ri.borrow_item_id
    JOIN items i ON i.item_id = bi.item_id
    LEFT JOIN borrow_item_components bic ON bic.borrow_item_component_id = ri.borrow_item_component_id
    LEFT JOIN item_components ic ON ic.component_id = ri.component_id
    LEFT JOIN return_batches rb ON rb.return_batch_id = ri.return_batch_id
    JOIN borrow_requests br ON br.request_id = bi.request_id
    JOIN users u ON u.user_id = br.user_id
    LEFT JOIN damage_compensations dc ON dc.inspection_id = ri.inspection_id
    WHERE (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
      AND ri.action_required = 'buy_replacement'";
if ($search !== '') {
    $recordSql .= " AND (i.item_name LIKE ? OR COALESCE(bic.component_name, ic.component_name) LIKE ?
        OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR CAST(bi.request_id AS CHAR) LIKE ?)";
}
$recordSql .= ' ORDER BY ri.is_resolved ASC, (ri.replacement_submitted_at IS NOT NULL) DESC, ri.inspection_id DESC';
if ($search !== '') {
    $recordsStmt = mysqli_prepare($conn, $recordSql);
    $like = '%' . $search . '%';
    mysqli_stmt_bind_param($recordsStmt, 'ssss', $like, $like, $like, $like);
    mysqli_stmt_execute($recordsStmt);
    $records = mysqli_stmt_get_result($recordsStmt);
} else {
    $records = mysqli_query($conn, $recordSql);
}
$recordCount = $records ? mysqli_num_rows($records) : 0;
$flash = flash_take();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ติดตามรับของทดแทนคืนวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <style>.item-thumb{width:54px;height:54px;object-fit:cover;border:1px solid #dee2e6;border-radius:.4rem;background:#fff}</style>
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'staff/compensation.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h3 class="fw-bold mb-1">รับของทดแทนคืนวัด</h3><div class="text-muted">หนึ่งปัญหาบันทึกได้ครั้งเดียว และระบบปรับสต็อกเมื่อชดใช้ครบทั้งรอบคืน</div></div>
        <div><a href="home.php" class="btn btn-outline-primary">หน้าหลัก</a> <a href="return_check.php" class="btn btn-outline-secondary">ตรวจรับคืน</a></div>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>

    <form method="get" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-9"><label class="form-label" for="q">ค้นหารายการ</label><input id="q" name="q" class="form-control" value="<?= app_escape($search) ?>" placeholder="ชื่อของ ชื่อผู้ยืม หรือเลขคำขอ"></div>
            <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1">ค้นหา</button><?php if ($search !== ''): ?><a href="compensation.php" class="btn btn-outline-secondary">ล้าง</a><?php endif; ?></div>
        </div>
    </form>

    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>รายการ</th><th>ปัญหา</th><th>เงื่อนไข</th><th>สถานะ</th><th class="text-end">ดำเนินการ</th></tr></thead>
            <tbody>
            <?php if (!$records || mysqli_num_rows($records) === 0): ?>
                <tr><td colspan="5" class="text-center text-muted py-5"><?= $search === '' ? 'ยังไม่มีรายการที่ต้องชดใช้' : 'ไม่พบรายการที่ค้นหา' ?></td></tr>
            <?php else: while ($row = mysqli_fetch_assoc($records)): ?>
                <tr id="inspection-<?= (int) $row['inspection_id'] ?>">
                    <td><div class="d-flex gap-2 align-items-center"><img class="item-thumb" src="<?= app_escape($row['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt=""><div><strong><?= app_escape($row['item_name']) ?></strong><?php if ($row['component_name']): ?><div class="small text-primary">ของย่อย: <?= app_escape($row['component_name']) ?></div><?php endif; ?><small class="text-muted">คำขอ #<?= (int) $row['request_id'] ?> · <?= app_escape($row['first_name'] . ' ' . $row['last_name']) ?></small></div></div></td>
                    <td><span class="text-danger">ชำรุด <?= (int) $row['damaged_quantity'] ?></span> / <span class="text-dark">สูญหาย <?= (int) $row['lost_quantity'] ?></span><?php if ($row['damage_description']): ?><div class="small text-muted"><?= app_escape($row['damage_description']) ?></div><?php endif; ?></td>
                    <td><span class="badge bg-primary">ซื้อของมาคืนวัด</span></td>
                    <td><?php if ((int) $row['is_resolved'] === 1): ?><span class="badge bg-success">ชดใช้แล้ว</span><div class="small text-muted"><?= $row['received_date'] ? date('d/m/Y H:i', strtotime($row['received_date'])) : '' ?></div><?php elseif ($row['replacement_submitted_at']): ?><span class="badge bg-warning text-dark">รอยืนยันรับของจริง</span><?php else: ?><span class="badge bg-danger">รอชดใช้</span><?php endif; ?><?php if ($row['replacement_image_url']): ?><div class="mt-2"><a href="<?= app_escape($row['replacement_image_url']) ?>" target="_blank" rel="noopener">ดูรูปของทดแทน</a></div><?php endif; ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['is_resolved'] === 0): ?>
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#settle<?= (int) $row['inspection_id'] ?>">บันทึกรับชดใช้</button>
                        <?php else: ?><span class="small text-muted">บันทึกถาวรแล้ว</span><?php endif; ?>
                    </td>
                </tr>
                <?php if ((int) $row['is_resolved'] === 0): ?>
                <div class="modal fade" id="settle<?= (int) $row['inspection_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="inspection_id" value="<?= (int) $row['inspection_id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">ยืนยันรับชดใช้</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p class="mb-3"><strong><?= app_escape($row['item_name']) ?></strong><?= $row['component_name'] ? ' · ' . app_escape($row['component_name']) : '' ?></p>
                        <?php if ($row['damage_image_url']): ?><a href="<?= app_escape($row['damage_image_url']) ?>" target="_blank" rel="noopener">ดูหลักฐานของชำรุด</a><?php endif; ?>
                        <?php if ($row['replacement_submitted_at']): ?>
                            <h6 class="mt-3">หลักฐานของทดแทนจากผู้ยืม</h6>
                            <?php if ($row['replacement_image_url']): ?><a href="<?= app_escape($row['replacement_image_url']) ?>" target="_blank" rel="noopener"><img src="<?= app_escape($row['replacement_image_url']) ?>" alt="ของทดแทนที่ผู้ยืมส่ง" class="img-fluid rounded mb-2" style="max-height:280px"></a><?php endif; ?>
                            <p class="small text-muted">ส่งเมื่อ <?= app_escape($row['replacement_submitted_at']) ?></p>
                            <p style="white-space:pre-line"><?= app_escape($row['replacement_note']) ?></p>
                            <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="received-<?= (int) $row['inspection_id'] ?>" name="received_replacement" value="1" required><label class="form-check-label" for="received-<?= (int) $row['inspection_id'] ?>">ได้รับของทดแทนจริงครบจำนวน และตรวจสอบสภาพแล้ว</label></div>
                        <?php endif; ?>
                        
                            <div class="alert alert-info">ตรวจสอบว่าได้รับของทดแทนครบ <?= (int) $row['damaged_quantity'] + (int) $row['lost_quantity'] ?> ชิ้นก่อนยืนยัน</div>
                            <input type="hidden" name="amount" value="0">
                        
                        <label class="form-label">หมายเหตุ</label><textarea name="note" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="modal-footer"><button name="save_compensation" class="btn btn-primary" onclick="return confirm('ยืนยันข้อมูลนี้แล้วจะไม่สามารถแก้ไขย้อนหลังได้?')">ยืนยันรับชดใช้</button></div>
                </form></div></div></div>
                <?php endif; ?>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($search !== ''): ?><div class="small text-muted mt-2">พบ <?= $recordCount ?> รายการ</div><?php endif; ?>
</main>
<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
