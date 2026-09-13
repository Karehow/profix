<?php
require_once __DIR__ . '/component_inventory.php';

function incomplete_set_ids(mysqli $conn): array
{
    $result = mysqli_query($conn, 'SELECT item_id FROM items WHERE is_set = 1');
    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $itemId = (int) $row['item_id'];
        if (component_stock_summary(component_stock_rows($conn, $itemId))['incomplete']) $ids[$itemId] = true;
    }
    mysqli_free_result($result);
    return $ids;
}
