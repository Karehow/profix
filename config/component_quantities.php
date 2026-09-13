<?php

/** Exact piece counts plus a set-equivalent count for the existing return-batch model. */
function component_selection(array $components, array $requested, int $availableSets): array
{
    $known = array_column($components, null, 'component_id');
    $quantities = [];
    $sets = 0;
    foreach ($requested as $id => $raw) {
        if (!isset($known[$id])) {
            throw new InvalidArgumentException('ข้อมูลของย่อยไม่อยู่ในชุดนี้');
        }
        if ((!is_int($raw) && !is_string($raw)) || !preg_match('/^\d+$/D', (string) $raw)
            || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 0) {
            throw new InvalidArgumentException('จำนวนของย่อยต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป');
        }
        $quantity = (int) $raw;
        $component = $known[$id];
        $perSet = (int) $component['quantity_per_set'];
        $availablePieces = isset($component['piece_available']) ? (int) $component['piece_available'] : $availableSets * $perSet;
        if ($perSet < 1 || $quantity > $availablePieces
            || ($quantity > 0 && !(int) $component['is_available'])) {
            throw new InvalidArgumentException('จำนวน ' . $component['component_name'] . ' เกินคงเหลือหรือไม่พร้อมให้ยืม');
        }
        $quantities[(int) $id] = $quantity;
        if ($quantity > 0) $sets = max($sets, intdiv($quantity - 1, $perSet) + 1);
    }
    if ($sets < 1) throw new InvalidArgumentException('กรุณาระบุจำนวนของย่อยอย่างน้อย 1 ชิ้น');
    foreach ($known as $id => $component) $quantities[$id] ??= 0;
    return ['qty' => $sets, 'component_quantities' => $quantities,
        'excluded_components' => array_keys(array_filter($quantities, static fn($qty) => $qty === 0))];
}

function component_borrowed_quantity(array $component, int $sets): int
{
    if (!(int) $component['is_included']) return 0;
    return isset($component['quantity_borrowed']) ? (int) $component['quantity_borrowed']
        : (int) $component['quantity_per_set'] * $sets;
}

/** Exact mixed quantities cannot be distributed across an arbitrary subset of sets. */
function component_return_limit(array $component, int $returnedSets, int $remainingSets): int
{
    if (isset($component['quantity_borrowed']) && $returnedSets !== $remainingSets) {
        throw new InvalidArgumentException('รายการที่เลือกจำนวนของย่อยต้องตรวจรับคืนพร้อมกันทั้งหมด');
    }
    return component_borrowed_quantity($component, $returnedSets);
}
