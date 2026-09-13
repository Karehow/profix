<?php if (!(int) $item['is_set']): ?>
<div class="small mt-1">
    <div>ขอยืม <strong><?= (int) ($item['quantity_requested'] ?? $item['quantity_borrowed']) ?></strong> · จัดให้ <strong><?= $request['status'] === 'pending_approval' ? 'รอตรวจนับ' : (int) $item['quantity_borrowed'] ?></strong></div>
    <div>ส่งมอบจริง <strong><?= $item['quantity_handed_over'] === null ? 'ยังไม่ส่งมอบ' : (int) $item['quantity_handed_over'] ?></strong></div>
    <?php if ($item['quantity_handed_over'] !== null): ?><div class="text-warning">ค้างคืน <strong><?= max(0, (int) $item['quantity_handed_over'] - (int) $item['quantity_returned']) ?></strong> ชิ้น</div><?php endif; ?>
</div>
<?php endif; ?>
