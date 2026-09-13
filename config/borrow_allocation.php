<?php
// These helpers run inside a transaction with the request locked by the caller.
function borrow_apply_allocation(mysqli $conn, array $request, int $staffId, array $quantities, string $note, bool $stockMissing): void
{
    $requestId = (int) $request['request_id'];
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT bi.*, i.is_set FROM borrow_items bi JOIN items i ON i.item_id=bi.item_id WHERE bi.request_id=$requestId ORDER BY bi.item_id FOR UPDATE"), MYSQLI_ASSOC);
    $known = array_column($rows, null, 'borrow_item_id');
    foreach ($quantities as $id => $value) {
        if (!isset($known[$id]) || filter_var($value, FILTER_VALIDATE_INT) === false) throw new BorrowWorkflowException('จำนวนจัดให้หรือรายการสิ่งของไม่ถูกต้อง');
    }
    $changes = [];
    foreach ($rows as $row) {
        $id = (int) $row['borrow_item_id'];
        $old = (int) $row['quantity_borrowed'];
        $new = isset($quantities[$id]) ? (int) $quantities[$id] : $old;
        if ($new < 1 || $new > $old) throw new BorrowWorkflowException('จำนวนจัดให้ต้องตั้งแต่ 1 ถึงจำนวนเดิม หากไม่มีของเลยให้ยกเลิกคำขอ');
        if ($new === $old) continue;
        if ((int) $row['is_set']) throw new BorrowWorkflowException('รายการชุดต้องตรวจของย่อยตามรายการเดิม หากชุดไม่ครบให้ยกเลิกและเลือกของย่อยใหม่');
        $note = borrow_validate_note($note, true);
        $difference = $old - $new;
        $itemId = (int) $row['item_id'];
        if ($request['status'] === 'approved') {
            inventory_adjust($conn, $itemId, $difference, 0, 'allocation_released', $staffId, $requestId, $id, null, null, $note);
        }
        if ($stockMissing) {
            inventory_adjust($conn, $itemId, -$difference, 0, 'warehouse_discrepancy', $staffId, $requestId, $id, null, null, $note);
            $stmt = mysqli_prepare($conn, 'INSERT INTO inventory_discrepancies (item_id,request_id,quantity,note,created_by_user_id) VALUES (?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'iiisi', $itemId, $requestId, $difference, $note, $staffId);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        }
        $stmt = mysqli_prepare($conn, 'UPDATE borrow_items SET quantity_requested=COALESCE(quantity_requested,quantity_borrowed), quantity_borrowed=? WHERE borrow_item_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $new, $id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        $changes[] = "รายการ #$id: $old → $new";
    }
    if ($changes) {
        $stmt = mysqli_prepare($conn, 'UPDATE borrow_requests SET allocation_version=allocation_version+1, accepted_allocation_version=NULL, allocation_accepted_at=NULL, decision_note=? WHERE request_id=?');
        mysqli_stmt_bind_param($stmt, 'si', $note, $requestId); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        $detail = implode(', ', $changes) . ': ' . $note;
        borrow_add_event($conn, $requestId, $request['status'], $request['status'], $staffId, $detail);
        write_audit_log($conn, $staffId, 'adjust_borrow_allocation', "คำขอ #$requestId $detail");
        create_notification($conn, (int) $request['user_id'], 'จำนวนสิ่งของที่จัดให้มีการเปลี่ยนแปลง', "คำขอ #$requestId $detail กรุณายอมรับจำนวนใหม่หรือยกเลิกก่อนรับของ", '../user/history.php#request-' . $requestId);
    }
}

function borrow_revise_allocation(mysqli $conn, int $requestId, int $staffId, array $quantities, string $note, bool $stockMissing, int $version): void
{
    mysqli_begin_transaction($conn);
    try {
        $request = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_requests WHERE request_id=$requestId FOR UPDATE"));
        if (!$request || $request['status'] !== 'approved' || (int) $request['allocation_version'] !== $version) throw new BorrowWorkflowException('คำขอเปลี่ยนแปลงแล้ว กรุณาโหลดหน้าใหม่');
        borrow_apply_allocation($conn, $request, $staffId, $quantities, $note, $stockMissing);
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function borrow_accept_allocation(mysqli $conn, int $requestId, int $userId, int $version): void
{
    mysqli_begin_transaction($conn);
    try {
        $request = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_requests WHERE request_id=$requestId AND user_id=$userId FOR UPDATE"));
        if (!$request || $request['status'] !== 'approved' || $version < 1 || (int) $request['allocation_version'] !== $version) throw new BorrowWorkflowException('จำนวนหรือสถานะเปลี่ยนแปลงแล้ว กรุณาตรวจสอบใหม่');
        if ((int) $request['accepted_allocation_version'] !== $version) {
            mysqli_query($conn, "UPDATE borrow_requests SET accepted_allocation_version=$version, allocation_accepted_at=NOW() WHERE request_id=$requestId");
            borrow_add_event($conn, $requestId, 'approved', 'approved', $userId, 'ผู้ยืมยอมรับจำนวนจัดให้ รุ่น ' . $version);
            write_audit_log($conn, $userId, 'accept_borrow_allocation', "คำขอ #$requestId รุ่น $version");
            if (!empty($request['approved_by_user_id'])) create_notification($conn, (int) $request['approved_by_user_id'], 'ผู้ยืมยอมรับจำนวนจัดให้แล้ว', "คำขอ #$requestId พร้อมตรวจนับและส่งมอบ", '../staff/requests.php?status=approved#request-' . $requestId);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

function resolve_inventory_discrepancy(mysqli $conn, int $id, int $staffId, string $resolution, string $note): void
{
    $note = borrow_validate_note($note, true);
    if (!in_array($resolution, ['found','written_off'], true)) throw new BorrowWorkflowException('กรุณาเลือกผลการตรวจคลัง');
    mysqli_begin_transaction($conn);
    try {
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inventory_discrepancies WHERE discrepancy_id=$id FOR UPDATE"));
        if (!$row || $row['resolution'] !== 'pending') throw new BorrowWorkflowException('รายการนี้ถูกจัดการแล้ว');
        $quantity = (int) $row['quantity'];
        inventory_adjust($conn, (int) $row['item_id'], $resolution === 'found' ? $quantity : 0, $resolution === 'written_off' ? -$quantity : 0, 'warehouse_' . $resolution, $staffId, (int) $row['request_id'], null, null, null, $note);
        $stmt = mysqli_prepare($conn, 'UPDATE inventory_discrepancies SET resolution=?, resolved_by_user_id=?, resolved_at=NOW(), resolution_note=? WHERE discrepancy_id=?');
        mysqli_stmt_bind_param($stmt, 'sisi', $resolution, $staffId, $note, $id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        write_audit_log($conn, $staffId, 'resolve_inventory_discrepancy', "รายการคลัง #$id $resolution: $note");
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}
