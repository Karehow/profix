<?php foreach ($itemsByRequest[$requestId] ?? [] as $allocationItem): ?>
    <?php if (!(int) $allocationItem['is_set']): ?>
    <label class="small d-block mb-2"><?= app_escape($allocationItem['item_name']) ?> — ขอ <?= (int) ($allocationItem['quantity_requested'] ?? $allocationItem['quantity_borrowed']) ?> ชิ้น
    <span class="d-block">จัดให้ <input class="form-control form-control-sm" type="number" name="quantities[<?= (int) $allocationItem['borrow_item_id'] ?>]" min="1" max="<?= (int) $allocationItem['quantity_borrowed'] ?>" value="<?= (int) $allocationItem['quantity_borrowed'] ?>" required></span></label>
    <?php else: ?><p class="small">ชุด <?= app_escape($allocationItem['item_name']) ?>: ตรวจของย่อยตามรายการ หากไม่ครบให้ยกเลิกและเลือกของย่อยใหม่</p><?php endif; ?>
<?php endforeach; ?>
<label class="small mb-2"><input type="checkbox" name="stock_missing"> จำนวนที่ลดเกิดจากของในคลังหาไม่พบ และยังไม่ได้ปรับยอดคลัง (กันไว้รอตรวจสอบ)</label>
<p class="small text-muted">หากลดจำนวน ต้องใส่เหตุผล ผู้ยืมจะเลือกยอมรับหรือยกเลิกก่อนรับของ</p>
