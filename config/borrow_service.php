<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/component_quantities.php';
require_once __DIR__ . '/component_inventory.php';
require_once __DIR__ . '/borrow_allocation.php';

class BorrowWorkflowException extends RuntimeException
{
}

function borrow_validate_note(string $note, bool $required = false, int $maximumLength = 500): string
{
    $note = trim($note);
    if ($required && $note === '') {
        throw new BorrowWorkflowException('กรุณาระบุเหตุผลประกอบการดำเนินการ');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($length > $maximumLength) {
        throw new BorrowWorkflowException('หมายเหตุต้องไม่เกิน ' . $maximumLength . ' ตัวอักษร');
    }
    return $note;
}

function borrow_parse_datetime(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date
        || $date->format('Y-m-d H:i:s') !== $value
        || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
    ) {
        throw new BorrowWorkflowException('รูปแบบวันและเวลาไม่ถูกต้อง');
    }
    return $date;
}

function db_require_query(mysqli $conn, string $sql): mysqli_result|bool
{
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        throw new BorrowWorkflowException(mysqli_error($conn));
    }
    return $result;
}

function borrow_add_event(mysqli $conn, int $requestId, ?string $from, string $to, int $actorId, string $note = ''): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO borrow_request_events (request_id, from_status, to_status, actor_user_id, note) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new BorrowWorkflowException('ไม่สามารถเตรียมบันทึกสถานะได้');
    }
    mysqli_stmt_bind_param($stmt, 'issis', $requestId, $from, $to, $actorId, $note);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new BorrowWorkflowException($error);
    }
    mysqli_stmt_close($stmt);
}

/**
 * Adjust total and ready-to-borrow quantities atomically and append a ledger row.
 * The caller owns the surrounding transaction.
 */
function inventory_adjust(
    mysqli $conn,
    int $itemId,
    int $availableDelta,
    int $totalDelta,
    string $movementType,
    int $actorId,
    ?int $requestId = null,
    ?int $borrowItemId = null,
    ?int $returnBatchId = null,
    ?int $maintenanceId = null,
    string $note = ''
): int {
    $stmt = mysqli_prepare($conn, 'SELECT total_quantity, available_quantity, is_set FROM items WHERE item_id = ? FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $itemId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        throw new BorrowWorkflowException('ไม่พบรายการสิ่งของ');
    }

    $before = (int) $row['available_quantity'];
    $newTotal = (int) $row['total_quantity'] + $totalDelta;
    $after = $before + $availableDelta;
    $components = (int) $row['is_set'] ? component_stock_rows($conn, $itemId, true) : [];
    if ($components) {
        $summary = component_inventory_adjust($conn, $itemId, $components, $availableDelta, $totalDelta, $movementType, $borrowItemId, $returnBatchId);
        $newTotal = $summary['total_sets'];
        $after = $summary['full_sets'];
        $totalDelta = $newTotal - (int) $row['total_quantity'];
        $availableDelta = $after - $before;
    }
    if ($newTotal < 0 || $after < 0 || $after > $newTotal) {
        throw new BorrowWorkflowException('จำนวนคงเหลือไม่ถูกต้องหรือไม่เพียงพอ');
    }

    $update = mysqli_prepare($conn, 'UPDATE items SET total_quantity = ?, available_quantity = ? WHERE item_id = ?');
    mysqli_stmt_bind_param($update, 'iii', $newTotal, $after, $itemId);
    if (!mysqli_stmt_execute($update)) {
        $error = mysqli_stmt_error($update);
        mysqli_stmt_close($update);
        throw new BorrowWorkflowException($error ?: 'ไม่สามารถปรับสต็อกได้');
    }
    mysqli_stmt_close($update);

    $ledger = mysqli_prepare($conn, 'INSERT INTO inventory_movements
        (item_id, request_id, borrow_item_id, return_batch_id, maintenance_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param(
        $ledger,
        'iiiiisiiiisi',
        $itemId,
        $requestId,
        $borrowItemId,
        $returnBatchId,
        $maintenanceId,
        $movementType,
        $totalDelta,
        $availableDelta,
        $before,
        $after,
        $note,
        $actorId
    );
    if (!mysqli_stmt_execute($ledger)) {
        $error = mysqli_stmt_error($ledger);
        mysqli_stmt_close($ledger);
        throw new BorrowWorkflowException($error ?: 'ไม่สามารถบันทึกความเคลื่อนไหวสต็อกได้');
    }
    mysqli_stmt_close($ledger);
    return $after;
}

function borrow_reserved_quantity(mysqli $conn, int $itemId, string $startAt, string $endAt, ?int $excludeReservationId = null): int
{
    $sql = "SELECT COALESCE(SUM(quantity), 0) AS total FROM reservations
        WHERE item_id = ?
          AND (
              status = 'approved'
              OR (status = 'fulfilled' AND EXISTS (
                  SELECT 1 FROM borrow_requests linked_request
                  WHERE linked_request.request_id = reservations.borrow_request_id
                    AND linked_request.status = 'pending_approval'
              ))
          )
          AND start_date < ? AND end_date > ?";
    if ($excludeReservationId !== null) {
        $sql .= ' AND reservation_id <> ?';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if ($excludeReservationId !== null) {
        mysqli_stmt_bind_param($stmt, 'issi', $itemId, $endAt, $startAt, $excludeReservationId);
    } else {
        mysqli_stmt_bind_param($stmt, 'iss', $itemId, $endAt, $startAt);
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int) ($row['total'] ?? 0);
}

/**
 * Keep reservation state truthful without requiring a separate cron job.
 * This is safe to run repeatedly and only closes reservations whose usage
 * window has already ended.
 */
function reservation_expire_stale(mysqli $conn): void
{
    $stmt = mysqli_prepare($conn, "UPDATE reservations
        SET status = 'expired'
        WHERE status IN ('pending', 'approved') AND end_date <= NOW()");
    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        $error = $stmt ? mysqli_stmt_error($stmt) : mysqli_error($conn);
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
        throw new BorrowWorkflowException($error ?: 'ไม่สามารถอัปเดตสถานะการจองที่หมดอายุได้');
    }
    mysqli_stmt_close($stmt);
}

/**
 * Lock and validate a borrower before creating or approving a request.
 * Locking the user row also serializes two submissions by the same borrower,
 * preventing duplicate active requests from passing their checks together.
 */
function borrow_assert_user_can_request(mysqli $conn, int $userId): void
{
    $userStmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ? AND role = 'user' AND is_active = 1 FOR UPDATE");
    mysqli_stmt_bind_param($userStmt, 'i', $userId);
    mysqli_stmt_execute($userStmt);
    $validUser = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
    mysqli_stmt_close($userStmt);
    if (!$validUser) {
        throw new BorrowWorkflowException('บัญชีผู้ยืมไม่พร้อมใช้งาน');
    }

    $blockStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM borrow_requests br
        WHERE br.user_id = ? AND (
            (br.status IN ('borrowed','return_requested') AND br.expected_return_date < NOW())
            OR br.settlement_status = 'pending'
            OR EXISTS (
                SELECT 1
                FROM borrow_items bi
                JOIN return_inspections ri ON ri.borrow_item_id = bi.borrow_item_id
                WHERE bi.request_id = br.request_id
                  AND ri.is_resolved = 0
                  AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
            )
        )");
    mysqli_stmt_bind_param($blockStmt, 'i', $userId);
    mysqli_stmt_execute($blockStmt);
    $blocked = mysqli_fetch_assoc(mysqli_stmt_get_result($blockStmt));
    mysqli_stmt_close($blockStmt);
    if ((int) ($blocked['total'] ?? 0) > 0) {
        throw new BorrowWorkflowException('ไม่สามารถส่งคำขอใหม่ได้ เนื่องจากมีของเกินกำหนดหรือรายการชดใช้ที่ยังไม่เสร็จ');
    }
}

function borrow_assert_components_available(mysqli $conn, int $requestId): void
{
    $stmt = mysqli_prepare($conn, 'SELECT c.component_name FROM borrow_items bi
        JOIN borrow_item_components bc ON bc.borrow_item_id = bi.borrow_item_id AND bc.is_included = 1
        JOIN item_components c ON c.component_id = bc.source_component_id
        WHERE bi.request_id = ? AND c.is_available = 0 FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $missing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($missing) throw new BorrowWorkflowException('ของในชุดไม่ครบ: ' . $missing['component_name'] . ' หมดหรือไม่พร้อมให้ยืม');
}

function borrow_snapshot_components(mysqli $conn, int $borrowItemId, int $itemId, array $excludedIds, ?array $quantities = null): void
{
    $excludedIds = array_values(array_unique(array_map('intval', $excludedIds)));
    $componentStmt = mysqli_prepare($conn, 'SELECT component_id, component_name, quantity_per_set, unit, is_available FROM item_components WHERE parent_item_id = ? ORDER BY component_id FOR UPDATE');
    mysqli_stmt_bind_param($componentStmt, 'i', $itemId);
    mysqli_stmt_execute($componentStmt);
    $components = mysqli_stmt_get_result($componentStmt);
    $knownIds = [];
    $included = 0;
    while ($component = mysqli_fetch_assoc($components)) {
        $sourceId = (int) $component['component_id'];
        $knownIds[] = $sourceId;
        $isIncluded = in_array($sourceId, $excludedIds, true) ? 0 : 1;
        $actualQuantity = $quantities !== null ? (int) ($quantities[$sourceId] ?? 0) : null;
        if ($actualQuantity !== null) $isIncluded = $actualQuantity > 0 ? 1 : 0;
        if ($isIncluded && !(int) $component['is_available']) {
            mysqli_stmt_close($componentStmt);
            throw new BorrowWorkflowException('ของในชุดไม่ครบ: ' . $component['component_name'] . ' หมด กรุณาเลือกเฉพาะของย่อยที่พร้อม');
        }
        $included += $isIncluded;
        $name = (string) $component['component_name'];
        $qty = (int) $component['quantity_per_set'];
        $unit = (string) ($component['unit'] ?: 'ชิ้น');
        $insert = mysqli_prepare($conn, 'INSERT INTO borrow_item_components (borrow_item_id, source_component_id, component_name, quantity_per_set, unit, is_included, quantity_borrowed) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($insert, 'iisisii', $borrowItemId, $sourceId, $name, $qty, $unit, $isIncluded, $actualQuantity);
        if (!mysqli_stmt_execute($insert)) {
            $error = mysqli_stmt_error($insert);
            mysqli_stmt_close($insert);
            throw new BorrowWorkflowException($error);
        }
        mysqli_stmt_close($insert);

        if (!$isIncluded) {
            $legacy = mysqli_prepare($conn, 'INSERT INTO borrow_item_exclusions (borrow_item_id, component_id) VALUES (?, ?)');
            mysqli_stmt_bind_param($legacy, 'ii', $borrowItemId, $sourceId);
            mysqli_stmt_execute($legacy);
            mysqli_stmt_close($legacy);
        }
    }
    mysqli_stmt_close($componentStmt);

    if (count(array_diff($excludedIds, $knownIds)) > 0 || (count($knownIds) > 0 && $included === 0)) {
        throw new BorrowWorkflowException('ข้อมูลของย่อยในชุดไม่ถูกต้อง');
    }
}

function borrow_submit_cart(mysqli $conn, int $userId, array $cart, string $pickupAt, string $expectedReturn): int
{
    if (!$cart) {
        throw new BorrowWorkflowException('กรุณาเลือกสิ่งของอย่างน้อย 1 รายการ');
    }
    $pickupDateTime = borrow_parse_datetime($pickupAt);
    $returnDateTime = borrow_parse_datetime($expectedReturn);
    if ($returnDateTime <= $pickupDateTime) {
        throw new BorrowWorkflowException('ช่วงเวลายืมและคืนไม่ถูกต้อง');
    }
    $today = new DateTimeImmutable('today');
    $pickupDay = $pickupDateTime->setTime(0, 0);
    $returnDay = $returnDateTime->setTime(0, 0);
    if ($pickupDay < $today) {
        throw new BorrowWorkflowException('วันรับของต้องไม่เป็นวันที่ย้อนหลัง');
    }
    if ($pickupDay > $today->modify('+7 days')) {
        throw new BorrowWorkflowException('คำขอยืมปกติเลือกรับของล่วงหน้าได้ไม่เกิน 7 วัน หากนานกว่านี้กรุณาใช้การจองล่วงหน้า');
    }
    if ((int) $pickupDay->diff($returnDay)->format('%a') > 31) {
        throw new BorrowWorkflowException('ระยะเวลายืมต่อคำขอได้ไม่เกิน 31 วัน');
    }

    mysqli_begin_transaction($conn);
    try {
        borrow_assert_user_can_request($conn, $userId);

        $requestStmt = mysqli_prepare($conn, "INSERT INTO borrow_requests (user_id, borrow_date, requested_pickup_at, expected_return_date, status) VALUES (?, NOW(), ?, ?, 'pending_approval')");
        mysqli_stmt_bind_param($requestStmt, 'iss', $userId, $pickupAt, $expectedReturn);
        if (!mysqli_stmt_execute($requestStmt)) {
            throw new BorrowWorkflowException(mysqli_stmt_error($requestStmt));
        }
        $requestId = mysqli_insert_id($conn);
        mysqli_stmt_close($requestStmt);

        ksort($cart, SORT_NUMERIC);
        foreach ($cart as $rawItemId => $cartData) {
            $itemId = (int) $rawItemId;
            $rawQuantity = is_array($cartData) ? ($cartData['qty'] ?? 0) : $cartData;
            if ((!is_int($rawQuantity) && !is_string($rawQuantity)) || filter_var($rawQuantity, FILTER_VALIDATE_INT) === false) {
                throw new BorrowWorkflowException('จำนวนชุดต้องเป็นจำนวนเต็ม');
            }
            $quantity = (int) $rawQuantity;
            $excluded = is_array($cartData) && is_array($cartData['excluded_components'] ?? null)
                ? $cartData['excluded_components']
                : [];

            $itemStmt = mysqli_prepare($conn, 'SELECT item_id, available_quantity, is_set, is_active FROM items WHERE item_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($itemStmt, 'i', $itemId);
            mysqli_stmt_execute($itemStmt);
            $item = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt));
            mysqli_stmt_close($itemStmt);
            if ($item && (int) $item['is_set']) {
                $stockRows = component_stock_rows($conn, $itemId, true);
                if ($stockRows) $item['available_quantity'] = component_stock_summary($stockRows)['full_sets'];
            }
            $componentQuantities = null;
            $legacyPartial = $item && (int) $item['is_set'] && $excluded !== [];
            if ($legacyPartial) {
                $legacyCounts = [];
                foreach ($stockRows as $component) {
                    $legacyCounts[$component['component_id']] = in_array((int) $component['component_id'], array_map('intval', $excluded), true)
                        ? 0 : (int) $component['quantity_per_set'] * $quantity;
                }
                try {
                    component_selection($stockRows, $legacyCounts, (int) $item['available_quantity']);
                } catch (InvalidArgumentException $e) {
                    throw new BorrowWorkflowException($e->getMessage());
                }
            }
            if (is_array($cartData) && array_key_exists('component_quantities', $cartData)) {
                if (!$item || !(int) $item['is_set'] || !is_array($cartData['component_quantities'])) {
                    throw new BorrowWorkflowException('ข้อมูลจำนวนของย่อยไม่ถูกต้อง');
                }
                try {
                    $selection = component_selection(component_stock_rows($conn, $itemId, true), $cartData['component_quantities'], (int) $item['available_quantity']);
                } catch (InvalidArgumentException $e) {
                    throw new BorrowWorkflowException($e->getMessage());
                }
                $quantity = $selection['qty'];
                $componentQuantities = $selection['component_quantities'];
                $excluded = $selection['excluded_components'];
            }
            if (!$item || (int) $item['is_active'] !== 1 || $quantity < 1 || ($componentQuantities === null && !$legacyPartial && $quantity > (int) $item['available_quantity'])) {
                throw new BorrowWorkflowException('มีรายการของหมด ปิดใช้งาน หรือจำนวนคงเหลือไม่เพียงพอ');
            }

            $duplicateStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM borrow_requests br
                JOIN borrow_items bi ON bi.request_id = br.request_id
                WHERE br.user_id = ? AND bi.item_id = ? AND br.status IN ('pending_approval','approved','borrowed','return_requested')");
            mysqli_stmt_bind_param($duplicateStmt, 'ii', $userId, $itemId);
            mysqli_stmt_execute($duplicateStmt);
            $duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateStmt));
            mysqli_stmt_close($duplicateStmt);
            if ((int) ($duplicate['total'] ?? 0) > 0 && !(int) $item['is_set']) {
                throw new BorrowWorkflowException('มีคำขอรายการเดียวกันที่กำลังดำเนินการอยู่แล้ว');
            }

            $borrowItemStmt = mysqli_prepare($conn, 'INSERT INTO borrow_items (request_id, item_id, quantity_borrowed, quantity_requested) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($borrowItemStmt, 'iiii', $requestId, $itemId, $quantity, $quantity);
            if (!mysqli_stmt_execute($borrowItemStmt)) {
                throw new BorrowWorkflowException(mysqli_stmt_error($borrowItemStmt));
            }
            $borrowItemId = mysqli_insert_id($conn);
            mysqli_stmt_close($borrowItemStmt);

            if ((int) $item['is_set'] === 1) {
                borrow_snapshot_components($conn, $borrowItemId, $itemId, $excluded, $componentQuantities);
            }
        }

        borrow_add_event($conn, $requestId, null, 'pending_approval', $userId, 'ผู้ยืมส่งคำขอ');
        write_audit_log($conn, $userId, 'submit_borrow_request', 'คำขอ #' . $requestId);

        $staff = mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('staff','admin') AND is_active = 1");
        while ($staff && $recipient = mysqli_fetch_assoc($staff)) {
            create_notification($conn, (int) $recipient['user_id'], 'มีคำขอยืมใหม่', 'คำขอ #' . $requestId . ' รอการตรวจสอบ', '../staff/requests.php');
        }

        mysqli_commit($conn);
        return $requestId;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($e instanceof BorrowWorkflowException) {
            throw $e;
        }
        throw new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function borrow_approve(mysqli $conn, int $requestId, int $staffId, string $note = '', array $quantities = [], bool $stockMissing = false): void
{
    $note = borrow_validate_note($note);
    mysqli_begin_transaction($conn);
    try {
        $requestStmt = mysqli_prepare($conn, "SELECT request_id, user_id, status, requested_pickup_at, expected_return_date, reservation_id FROM borrow_requests WHERE request_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($requestStmt, 'i', $requestId);
        mysqli_stmt_execute($requestStmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($requestStmt));
        mysqli_stmt_close($requestStmt);
        if (!$request || $request['status'] !== 'pending_approval') {
            throw new BorrowWorkflowException('คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }

        borrow_assert_user_can_request($conn, (int) $request['user_id']);

        $linkedReservation = null;
        $linkedReservationId = null;
        if ($request['reservation_id'] !== null) {
            $linkedReservationId = (int) $request['reservation_id'];
            if ($linkedReservationId < 1) {
                throw new BorrowWorkflowException('ข้อมูลการจองที่เชื่อมกับคำขอไม่ถูกต้อง');
            }
            $reservationStmt = mysqli_prepare($conn, "SELECT reservation_id, user_id, item_id, quantity, status, borrow_request_id
                FROM reservations
                WHERE reservation_id = ?
                FOR UPDATE");
            mysqli_stmt_bind_param($reservationStmt, 'i', $linkedReservationId);
            mysqli_stmt_execute($reservationStmt);
            $linkedReservation = mysqli_fetch_assoc(mysqli_stmt_get_result($reservationStmt));
            mysqli_stmt_close($reservationStmt);
            if (
                !$linkedReservation
                || $linkedReservation['status'] !== 'fulfilled'
                || (int) $linkedReservation['borrow_request_id'] !== $requestId
                || (int) $linkedReservation['user_id'] !== (int) $request['user_id']
            ) {
                throw new BorrowWorkflowException('ข้อมูลการจองที่เชื่อมกับคำขอไม่สอดคล้องกัน');
            }
        }

        borrow_apply_allocation($conn, $request, $staffId, $quantities, $note, $stockMissing);
        $pickupAt = $request['requested_pickup_at'] ?: date('Y-m-d H:i:s');
        $returnAt = $request['expected_return_date'];
        if (!$returnAt || strtotime($returnAt) <= time()) {
            throw new BorrowWorkflowException('กำหนดคืนผ่านไปแล้ว กรุณาปฏิเสธและให้ผู้ยืมส่งคำขอใหม่');
        }

        borrow_assert_components_available($conn, $requestId);
        $itemsStmt = mysqli_prepare($conn, 'SELECT bi.borrow_item_id, bi.item_id, bi.quantity_borrowed, bi.quantity_requested, i.available_quantity, i.is_active, i.is_set FROM borrow_items bi JOIN items i ON i.item_id = bi.item_id WHERE bi.request_id = ? ORDER BY bi.item_id FOR UPDATE');
        mysqli_stmt_bind_param($itemsStmt, 'i', $requestId);
        mysqli_stmt_execute($itemsStmt);
        $itemsResult = mysqli_stmt_get_result($itemsStmt);
        $items = [];
        while ($item = mysqli_fetch_assoc($itemsResult)) {
            if ($linkedReservation && (
                (int) $linkedReservation['item_id'] !== (int) $item['item_id']
                || (int) $linkedReservation['quantity'] !== (int) ($item['quantity_requested'] ?? $item['quantity_borrowed'])
            )) {
                throw new BorrowWorkflowException('รายการสิ่งของไม่ตรงกับการจองที่ได้รับอนุมัติ');
            }
            $reserved = borrow_reserved_quantity($conn, (int) $item['item_id'], $pickupAt, $returnAt, $linkedReservationId);
            $hasComponents = (int) $item['is_set'] && component_stock_rows($conn, (int) $item['item_id'], true);
            if ($hasComponents) component_assert_stock($conn, (int) $item['item_id'], (int) $item['borrow_item_id'], $reserved);
            if ((int) $item['is_active'] !== 1 || (int) $item['quantity_borrowed'] < 1 || (!$hasComponents && (int) $item['quantity_borrowed'] + $reserved > (int) $item['available_quantity'])) {
                throw new BorrowWorkflowException('สต็อกไม่พอเมื่อหักรายการจองในช่วงเวลาเดียวกัน');
            }
            $items[] = $item;
        }
        mysqli_stmt_close($itemsStmt);
        if (!$items) {
            throw new BorrowWorkflowException('คำขอนี้ไม่มีรายการสิ่งของ');
        }
        if ($linkedReservation && count($items) !== 1) {
            throw new BorrowWorkflowException('คำขอจากการจองต้องมีสิ่งของเพียงรายการเดียว');
        }

        foreach ($items as $item) {
            inventory_adjust(
                $conn,
                (int) $item['item_id'],
                -(int) $item['quantity_borrowed'],
                0,
                'borrow_reserved',
                $staffId,
                $requestId,
                (int) $item['borrow_item_id'],
                null,
                null,
                'อนุมัติคำขอและกันของรอส่งมอบ'
            );
        }

        $update = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'approved', approved_by_user_id = ?, approved_at = NOW(), decision_note = ? WHERE request_id = ? AND status = 'pending_approval'");
        mysqli_stmt_bind_param($update, 'isi', $staffId, $note, $requestId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            mysqli_stmt_close($update);
            throw new BorrowWorkflowException('สถานะคำขอถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        borrow_add_event($conn, $requestId, 'pending_approval', 'approved', $staffId, $note);
        create_notification($conn, (int) $request['user_id'], 'คำขอยืมได้รับอนุมัติ', 'คำขอ #' . $requestId . ' อนุมัติแล้ว กรุณามารับของกับเจ้าหน้าที่', '../user/history.php');
        write_audit_log($conn, $staffId, 'approve_borrow_request', 'คำขอ #' . $requestId);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function borrow_handover(mysqli $conn, int $requestId, int $staffId, ?int $version = null): void
{
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT user_id, status, expected_return_date, allocation_version, accepted_allocation_version FROM borrow_requests WHERE request_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $requestId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$request || $request['status'] !== 'approved') {
            throw new BorrowWorkflowException('คำขอนี้ไม่อยู่ในสถานะรอส่งมอบ');
        }
        if ($version !== null && $version !== (int) $request['allocation_version']) throw new BorrowWorkflowException('จำนวนจัดให้เปลี่ยนแปลงแล้ว กรุณาโหลดหน้าใหม่');
        if ((int) $request['allocation_version'] > 0 && (int) $request['accepted_allocation_version'] !== (int) $request['allocation_version']) {
            throw new BorrowWorkflowException('รอผู้ยืมยอมรับจำนวนที่จัดให้ล่าสุดก่อนส่งมอบ');
        }
        if (!$request['expected_return_date'] || strtotime($request['expected_return_date']) <= time()) {
            throw new BorrowWorkflowException('เลยกำหนดคืนแล้ว ไม่สามารถส่งมอบได้');
        }
        borrow_assert_components_available($conn, $requestId);
        mysqli_query($conn, "UPDATE borrow_items SET quantity_requested=COALESCE(quantity_requested,quantity_borrowed), quantity_handed_over=quantity_borrowed WHERE request_id=$requestId");
        $update = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'borrowed', borrow_date = NOW(), handed_over_at = NOW(), handed_over_by_user_id = ? WHERE request_id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($update, 'ii', $staffId, $requestId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new BorrowWorkflowException('สถานะคำขอถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        borrow_add_event($conn, $requestId, 'approved', 'borrowed', $staffId, 'เจ้าหน้าที่ส่งมอบของแล้ว');
        create_notification($conn, (int) $request['user_id'], 'รับสิ่งของแล้ว', 'คำขอ #' . $requestId . ' ถูกยืนยันการส่งมอบแล้ว', '../user/history.php');
        write_audit_log($conn, $staffId, 'handover_borrow_request', 'คำขอ #' . $requestId);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function borrow_reject(mysqli $conn, int $requestId, int $staffId, string $note): void
{
    $note = borrow_validate_note($note, true);
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT user_id, status FROM borrow_requests WHERE request_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'i', $requestId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$request || $request['status'] !== 'pending_approval') {
            throw new BorrowWorkflowException('คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }
        $update = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'rejected', approved_by_user_id = ?, approved_at = NOW(), decision_note = ? WHERE request_id = ? AND status = 'pending_approval'");
        mysqli_stmt_bind_param($update, 'isi', $staffId, $note, $requestId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new BorrowWorkflowException('สถานะคำขอถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        borrow_add_event($conn, $requestId, 'pending_approval', 'rejected', $staffId, $note);
        create_notification($conn, (int) $request['user_id'], 'คำขอยืมไม่ได้รับอนุมัติ', 'คำขอ #' . $requestId . ': ' . $note, '../user/history.php');
        write_audit_log($conn, $staffId, 'reject_borrow_request', 'คำขอ #' . $requestId . ': ' . $note);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function borrow_cancel(mysqli $conn, int $requestId, int $actorId, bool $staffOverride = false, string $note = ''): void
{
    $note = borrow_validate_note($note, $staffOverride);
    mysqli_begin_transaction($conn);
    try {
        $sql = 'SELECT user_id, status, reservation_id FROM borrow_requests WHERE request_id = ?' . ($staffOverride ? '' : ' AND user_id = ?') . ' FOR UPDATE';
        $stmt = mysqli_prepare($conn, $sql);
        if ($staffOverride) {
            mysqli_stmt_bind_param($stmt, 'i', $requestId);
        } else {
            mysqli_stmt_bind_param($stmt, 'ii', $requestId, $actorId);
        }
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$request || !in_array($request['status'], ['pending_approval', 'approved'], true)) {
            throw new BorrowWorkflowException('ยกเลิกได้เฉพาะคำขอที่รออนุมัติหรือรอรับของ');
        }
        $oldStatus = $request['status'];
        if ($oldStatus === 'approved') {
            $itemsStmt = mysqli_prepare($conn, 'SELECT borrow_item_id, item_id, quantity_borrowed FROM borrow_items WHERE request_id = ? ORDER BY item_id FOR UPDATE');
            mysqli_stmt_bind_param($itemsStmt, 'i', $requestId);
            mysqli_stmt_execute($itemsStmt);
            $items = mysqli_stmt_get_result($itemsStmt);
            while ($item = mysqli_fetch_assoc($items)) {
                inventory_adjust($conn, (int) $item['item_id'], (int) $item['quantity_borrowed'], 0, 'borrow_cancelled', $actorId, $requestId, (int) $item['borrow_item_id'], null, null, 'คืนของที่กันไว้จากการยกเลิก');
            }
            mysqli_stmt_close($itemsStmt);
        }
        $update = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'cancelled', cancelled_at = NOW(), decision_note = CONCAT_WS('\n', NULLIF(decision_note, ''), ?) WHERE request_id = ? AND status = ?");
        mysqli_stmt_bind_param($update, 'sis', $note, $requestId, $oldStatus);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new BorrowWorkflowException('สถานะคำขอถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        borrow_add_event($conn, $requestId, $oldStatus, 'cancelled', $actorId, $note);
        write_audit_log($conn, $actorId, 'cancel_borrow_request', 'คำขอ #' . $requestId);
        if ($staffOverride) {
            create_notification($conn, (int) $request['user_id'], 'คำขอยืมถูกยกเลิก', 'คำขอ #' . $requestId . ($note !== '' ? ': ' . $note : ''), '../user/history.php');
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function borrow_request_return(mysqli $conn, int $requestId, int $userId): void
{
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "SELECT status FROM borrow_requests WHERE request_id = ? AND user_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, 'ii', $requestId, $userId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$request || $request['status'] !== 'borrowed') {
            throw new BorrowWorkflowException('รายการนี้ไม่อยู่ในสถานะที่แจ้งคืนได้');
        }
        $update = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'return_requested', return_requested_at = NOW() WHERE request_id = ? AND user_id = ? AND status = 'borrowed'");
        mysqli_stmt_bind_param($update, 'ii', $requestId, $userId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new BorrowWorkflowException('สถานะคำขอถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);
        borrow_add_event($conn, $requestId, 'borrowed', 'return_requested', $userId, 'ผู้ยืมแจ้งพร้อมคืนของ');
        write_audit_log($conn, $userId, 'request_return', 'คำขอ #' . $requestId);
        $staff = mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('staff','admin') AND is_active = 1");
        while ($staff && $recipient = mysqli_fetch_assoc($staff)) {
            create_notification($conn, (int) $recipient['user_id'], 'มีรายการรอตรวจรับคืน', 'คำขอ #' . $requestId . ' แจ้งพร้อมส่งคืนแล้ว', '../staff/return_check.php');
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}

function reservation_convert_to_borrow(mysqli $conn, int $reservationId, int $userId): int
{
    mysqli_begin_transaction($conn);
    try {
        // Keep the same eligibility and duplicate-request guarantees as a
        // normal cart submission. The user lock must be acquired before the
        // item lock to match borrow_submit_cart's lock order.
        borrow_assert_user_can_request($conn, $userId);

        $reservationStmt = mysqli_prepare($conn, "SELECT r.*, i.is_set, i.is_active, i.total_quantity
            FROM reservations r
            JOIN items i ON i.item_id = r.item_id
            WHERE r.reservation_id = ? AND r.user_id = ?
            FOR UPDATE");
        mysqli_stmt_bind_param($reservationStmt, 'ii', $reservationId, $userId);
        mysqli_stmt_execute($reservationStmt);
        $reservation = mysqli_fetch_assoc(mysqli_stmt_get_result($reservationStmt));
        mysqli_stmt_close($reservationStmt);
        if (!$reservation || $reservation['status'] !== 'approved') {
            throw new BorrowWorkflowException('รายการจองนี้ยังไม่อนุมัติหรือถูกใช้งานแล้ว');
        }
        if ((int) $reservation['is_active'] !== 1 || strtotime($reservation['end_date']) <= time()) {
            throw new BorrowWorkflowException('รายการจองหมดอายุหรือสิ่งของปิดใช้งานแล้ว');
        }
        if (strtotime($reservation['start_date']) > strtotime('+24 hours')) {
            throw new BorrowWorkflowException('สร้างคำขอยืมจากการจองได้ภายใน 24 ชั่วโมงก่อนเวลาเริ่มจอง');
        }
        $quantity = (int) $reservation['quantity'];
        if ($quantity < 1 || $quantity > (int) $reservation['total_quantity']) {
            throw new BorrowWorkflowException('จำนวนของในระบบไม่เพียงพอ กรุณาติดต่อเจ้าหน้าที่');
        }
        $reservedItemId = (int) $reservation['item_id'];
        $reservationStart = (string) $reservation['start_date'];
        $reservationEnd = (string) $reservation['end_date'];

        $duplicateStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM borrow_requests br
            JOIN borrow_items bi ON bi.request_id = br.request_id
            WHERE br.user_id = ? AND bi.item_id = ? AND br.status IN ('pending_approval','approved','borrowed','return_requested')");
        mysqli_stmt_bind_param($duplicateStmt, 'ii', $userId, $reservedItemId);
        mysqli_stmt_execute($duplicateStmt);
        $duplicate = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateStmt))['total'];
        mysqli_stmt_close($duplicateStmt);
        if ($duplicate > 0) {
            throw new BorrowWorkflowException('มีคำขอยืมสิ่งของรายการนี้ที่กำลังดำเนินการอยู่แล้ว');
        }

        $requestStmt = mysqli_prepare($conn, "INSERT INTO borrow_requests
            (user_id, borrow_date, requested_pickup_at, expected_return_date, status, reservation_id)
            VALUES (?, NOW(), ?, ?, 'pending_approval', ?)");
        mysqli_stmt_bind_param($requestStmt, 'issi', $userId, $reservationStart, $reservationEnd, $reservationId);
        if (!mysqli_stmt_execute($requestStmt)) {
            throw new BorrowWorkflowException(mysqli_stmt_error($requestStmt));
        }
        $requestId = mysqli_insert_id($conn);
        mysqli_stmt_close($requestStmt);

        $borrowItemStmt = mysqli_prepare($conn, 'INSERT INTO borrow_items (request_id, item_id, quantity_borrowed, quantity_requested) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($borrowItemStmt, 'iiii', $requestId, $reservedItemId, $quantity, $quantity);
        if (!mysqli_stmt_execute($borrowItemStmt)) {
            throw new BorrowWorkflowException(mysqli_stmt_error($borrowItemStmt));
        }
        $borrowItemId = mysqli_insert_id($conn);
        mysqli_stmt_close($borrowItemStmt);
        if ((int) $reservation['is_set'] === 1) {
            borrow_snapshot_components($conn, $borrowItemId, $reservedItemId, []);
        }

        $update = mysqli_prepare($conn, "UPDATE reservations SET status = 'fulfilled', borrow_request_id = ? WHERE reservation_id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($update, 'ii', $requestId, $reservationId);
        mysqli_stmt_execute($update);
        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new BorrowWorkflowException('สถานะการจองถูกเปลี่ยนโดยผู้ใช้อื่น');
        }
        mysqli_stmt_close($update);

        borrow_add_event($conn, $requestId, null, 'pending_approval', $userId, 'สร้างจากการจอง #' . $reservationId);
        write_audit_log($conn, $userId, 'convert_reservation_to_borrow', 'การจอง #' . $reservationId . ' → คำขอ #' . $requestId);
        $staff = mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('staff','admin') AND is_active = 1");
        while ($staff && $recipient = mysqli_fetch_assoc($staff)) {
            create_notification($conn, (int) $recipient['user_id'], 'การจองถูกสร้างเป็นคำขอยืม', 'คำขอ #' . $requestId . ' จากการจอง #' . $reservationId . ' รอตรวจสอบ', '../staff/requests.php');
        }
        mysqli_commit($conn);
        return $requestId;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof BorrowWorkflowException ? $e : new BorrowWorkflowException($e->getMessage(), 0, $e);
    }
}
