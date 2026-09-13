<?php
require_once __DIR__ . '/component_quantities.php';

function component_stock_rows(mysqli $conn, int $itemId, bool $lock = false): array
{
    $stmt = mysqli_prepare($conn, 'SELECT c.*, COALESCE(c.stock_total, i.total_quantity * c.quantity_per_set) AS piece_total,
        COALESCE(c.stock_available, i.available_quantity * c.quantity_per_set) AS piece_available
        FROM item_components c JOIN items i ON i.item_id = c.parent_item_id
        WHERE c.parent_item_id = ? ORDER BY c.component_id' . ($lock ? ' FOR UPDATE' : ''));
    mysqli_stmt_bind_param($stmt, 'i', $itemId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function component_stock_summary(array $rows): array
{
    $full = $rows ? PHP_INT_MAX : 0;
    $total = $rows ? PHP_INT_MAX : 0;
    $pieces = 0;
    foreach ($rows as $row) {
        $per = max(1, (int) $row['quantity_per_set']);
        $ready = (int) $row['is_available'] ? (int) $row['piece_available'] : 0;
        $full = min($full, intdiv($ready, $per));
        $total = min($total, intdiv((int) $row['piece_total'], $per));
        $pieces += $ready;
    }
    $incomplete = false;
    foreach ($rows as $row) {
        if (!(int) $row['is_available'] || (int) $row['piece_available'] > $full * (int) $row['quantity_per_set']) $incomplete = true;
    }
    return ['full_sets' => $full, 'total_sets' => $total, 'pieces' => $pieces, 'incomplete' => $incomplete];
}

/** Called inside inventory_adjust's transaction, with the parent item locked. */
function component_inventory_adjust(mysqli $conn, int $itemId, array $rows, int $availableDelta, int $totalDelta,
    string $type, ?int $borrowItemId, ?int $batchId): array
{
    $deltas = [];
    foreach ($rows as $row) $deltas[(int) $row['component_id']] = [0, 0];
    $snapshotPerSet = [];
    if ($borrowItemId !== null) {
        $perRows = mysqli_query($conn, 'SELECT source_component_id, quantity_per_set FROM borrow_item_components WHERE borrow_item_id = ' . $borrowItemId);
        while ($perRow = mysqli_fetch_assoc($perRows)) $snapshotPerSet[(int) $perRow['source_component_id']] = (int) $perRow['quantity_per_set'];
    }
    if (in_array($type, ['borrow_reserved', 'borrow_cancelled', 'return_good'], true)) {
        $bi = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT quantity_borrowed FROM borrow_items WHERE borrow_item_id = ' . (int) $borrowItemId));
        $sets = (int) $bi['quantity_borrowed'];
        if ($type === 'return_good') {
            $batch = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT quantity_received FROM return_batches WHERE return_batch_id = ' . (int) $batchId));
            $sets = (int) $batch['quantity_received'];
        }
        $snapshots = mysqli_query($conn, 'SELECT * FROM borrow_item_components WHERE borrow_item_id = ' . (int) $borrowItemId);
        while ($snapshot = mysqli_fetch_assoc($snapshots)) {
            $id = (int) $snapshot['source_component_id'];
            if (!isset($deltas[$id])) continue;
            $count = component_borrowed_quantity($snapshot, $sets);
            $deltas[$id][0] += $type === 'borrow_reserved' ? -$count : $count;
        }
        if ($type === 'return_good') {
            $issues = mysqli_query($conn, 'SELECT ri.*, bc.source_component_id, bc.quantity_per_set AS snapshot_per_set
                FROM return_inspections ri LEFT JOIN borrow_item_components bc ON bc.borrow_item_component_id = ri.borrow_item_component_id
                WHERE ri.return_batch_id = ' . (int) $batchId);
            while ($issue = mysqli_fetch_assoc($issues)) {
                $count = (int) $issue['damaged_quantity'] + (int) $issue['lost_quantity'];
                $id = (int) ($issue['source_component_id'] ?? $issue['component_id']);
                if ($id && isset($deltas[$id])) $deltas[$id][0] -= $count;
                elseif (!$id && $count) {
                    foreach ($rows as $row) $deltas[$row['component_id']][0] -= $count * ($snapshotPerSet[$row['component_id']] ?? 0);
                }
            }
        }
    } elseif (in_array($type, ['compensation_replacement', 'compensation_retired'], true) && $batchId !== null) {
        $issues = mysqli_query($conn, 'SELECT ri.*, dc.method, bc.source_component_id
            FROM return_inspections ri JOIN damage_compensations dc ON dc.inspection_id = ri.inspection_id
            LEFT JOIN borrow_item_components bc ON bc.borrow_item_component_id = ri.borrow_item_component_id
            WHERE ri.return_batch_id = ' . (int) $batchId);
        while ($issue = mysqli_fetch_assoc($issues)) {
            $count = (int) $issue['damaged_quantity'] + (int) $issue['lost_quantity'];
            $id = (int) ($issue['source_component_id'] ?? $issue['component_id']);
            foreach ($rows as $row) {
                $componentId = (int) $row['component_id'];
                if ($id && $id !== $componentId) continue;
                $quantity = $id ? $count : $count * ($snapshotPerSet[$componentId] ?? 0);
                if ($issue['method'] === 'replacement') $deltas[$componentId][0] += $quantity;
                else $deltas[$componentId][1] -= $quantity;
            }
        }
    } else {
        foreach ($rows as $row) $deltas[$row['component_id']] = [$availableDelta * (int) $row['quantity_per_set'], $totalDelta * (int) $row['quantity_per_set']];
    }
    $update = mysqli_prepare($conn, 'UPDATE item_components SET stock_available = ?, stock_total = ? WHERE component_id = ?');
    foreach ($rows as &$row) {
        $id = (int) $row['component_id'];
        $available = (int) $row['piece_available'] + $deltas[$id][0];
        $total = (int) $row['piece_total'] + $deltas[$id][1];
        if ($available < 0 || $total < 0 || $available > $total) throw new RuntimeException('จำนวนของย่อยไม่เพียงพอหรือสต็อกไม่ถูกต้อง');
        mysqli_stmt_bind_param($update, 'iii', $available, $total, $id);
        mysqli_stmt_execute($update);
        $row['piece_available'] = $available;
        $row['piece_total'] = $total;
    }
    unset($row);
    mysqli_stmt_close($update);
    return component_stock_summary($rows);
}

function component_assert_stock(mysqli $conn, int $itemId, int $borrowItemId, int $reservedSets): void
{
    $rows = array_column(component_stock_rows($conn, $itemId, true), null, 'component_id');
    $snapshots = mysqli_query($conn, 'SELECT bc.*, bi.quantity_borrowed AS borrowed_sets FROM borrow_item_components bc
        JOIN borrow_items bi ON bi.borrow_item_id = bc.borrow_item_id WHERE bc.borrow_item_id = ' . $borrowItemId);
    while ($snapshot = mysqli_fetch_assoc($snapshots)) {
        $quantity = component_borrowed_quantity($snapshot, (int) $snapshot['borrowed_sets']);
        if (!$quantity) continue;
        $row = $rows[$snapshot['source_component_id']] ?? null;
        if (!$row || !(int) $row['is_available'] || $quantity + $reservedSets * (int) $row['quantity_per_set'] > (int) $row['piece_available']) {
            throw new RuntimeException('ของย่อย ' . $snapshot['component_name'] . ' คงเหลือไม่เพียงพอหลังหักยอดจอง');
        }
    }
}
