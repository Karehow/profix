<?php
require_once __DIR__ . '/../config/borrow_service.php';
ensure_feature_tables($conn);
$current_user = require_roles($conn, ['user'], 'index.php');
$user_id = (int) $current_user['user_id'];
if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    app_redirect('borrow.php');
}
$maximum_pickup_date = date('Y-m-d', strtotime('+7 days'));
$pickup_date = is_string($_POST['pickup_date'] ?? null) ? $_POST['pickup_date'] : ($_SESSION['borrow_dates']['pickup'] ?? date('Y-m-d'));
$return_date = is_string($_POST['expected_return_date'] ?? null) ? $_POST['expected_return_date'] : ($_SESSION['borrow_dates']['return'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $_SESSION['borrow_dates'] = ['pickup'=>$pickup_date, 'return'=>$return_date];
}

// จัดการการลบรายการออกจากตะกร้า
if (isset($_POST['remove_item'])) {
    require_csrf();
    $remove_id = intval($_POST['remove_item_id']);
    unset($_SESSION['cart'][$remove_id]);
    
    if (empty($_SESSION['cart'])) {
        header("Location: borrow.php");
        exit();
    }
    header("Location: confirm.php");
    exit();
}

// จัดการบันทึกคำขอยืมลงฐานข้อมูล
if (isset($_POST['submit_request'])) {
    require_csrf();
    // Apply ordinary-item edits as one validated cart update; set choices stay intact.
    $editedCart = $_SESSION['cart'];
    $quantities = $_POST['quantities'] ?? [];
    if (!is_array($quantities)) {
        $error = 'จำนวนสิ่งของไม่ถูกต้อง';
    } else {
        foreach ($quantities as $itemId => $inputQuantity) {
            $itemId = (int) $itemId;
            $quantity = filter_var($inputQuantity, FILTER_VALIDATE_INT);
            $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_set, is_active, available_quantity FROM items WHERE item_id=$itemId"));
            if (!isset($editedCart[$itemId]) || !$item || (int) $item['is_set'] !== 0 || !(int) $item['is_active']
                || $quantity === false || $quantity < 1 || $quantity > (int) $item['available_quantity']) {
                $error = 'จำนวนที่เลือกไม่ถูกต้องหรือเกินคงเหลือ กรุณาตรวจสอบรายการ';
                break;
            }
            $editedCart[$itemId] = ['qty'=>$quantity, 'excluded_components'=>[]];
        }
    }
    if (!isset($error)) $_SESSION['cart'] = $editedCart;
    $parsed_pickup = DateTime::createFromFormat('Y-m-d', $pickup_date);
    $parsed_date = DateTime::createFromFormat('Y-m-d', $return_date);
    if (isset($error)) {
        // Keep the original cart when a quantity edit fails validation.
    } elseif (!$parsed_pickup || $parsed_pickup->format('Y-m-d') !== $pickup_date || $pickup_date < date('Y-m-d') || $pickup_date > $maximum_pickup_date) {
        $error = 'กรุณาเลือกวันที่รับของที่ถูกต้อง';
    } elseif (!$parsed_date || $parsed_date->format('Y-m-d') !== $return_date || $return_date < $pickup_date) {
        $error = 'วันคืนต้องไม่อยู่ก่อนวันรับของ';
    } elseif (strtotime($return_date) - strtotime($pickup_date) > 31 * 86400) {
        $error = 'ระยะเวลายืมต่อคำขอได้ไม่เกิน 31 วัน';
    } else {
        try {
            $pickup_at = $pickup_date . ' 08:00:00';
            $expected_date = $return_date . ' 23:59:59';
            $request_id = borrow_submit_cart($conn, $user_id, $_SESSION['cart'], $pickup_at, $expected_date);
            unset($_SESSION['cart'], $_SESSION['borrow_dates']);
            flash_set('success', 'ส่งคำขอยืม #' . $request_id . ' แล้ว รอเจ้าหน้าที่ตรวจนับและจัดของ ติดตามขั้นตอนต่อไปได้ที่นี่');
            app_redirect('history.php#request-' . $request_id);
        } catch (Throwable $e) {
            $error = 'ไม่สามารถส่งคำขอได้: ' . ($e instanceof BorrowWorkflowException ? $e->getMessage() : 'กรุณาลองใหม่');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยืนยันคำขอยืม - ระบบยืมคืนวัด</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
    <link rel="stylesheet" href="../assets/css/borrow-confirm.css?v=1">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui confirm-page bg-light py-4">
<?php $uiPage = 'user/confirm.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<div class="container confirm-container">
    <nav class="confirm-steps" aria-label="ขั้นตอนยืม"><a href="borrow.php">1. เลือกของ</a><span aria-current="step">2. เลือกวันและส่งคำขอ</span></nav>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 fw-bold m-0">ตรวจรายการและส่งคำขอ</h1>
        <a href="borrow.php" class="btn btn-outline-secondary btn-sm">⬅️ กลับไปเลือกของเพิ่ม</a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="table-responsive mb-3">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>รายการสิ่งของ</th>
                            <th class="text-center">จำนวน</th>
                            <th class="text-center">รายละเอียดชุด</th>
                            <th class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
                        $query = mysqli_query($conn, "SELECT * FROM items WHERE item_id IN ($ids)");
                        
                        while ($row = mysqli_fetch_assoc($query)):
                            $item_id = $row['item_id'];
                            $cart_data = $_SESSION['cart'][$item_id];
                            
                            $qty = is_array($cart_data) ? ($cart_data['qty'] ?? 1) : intval($cart_data);
                            $excluded_components = is_array($cart_data) ? ($cart_data['excluded_components'] ?? []) : [];
                            $excluded_count = count($excluded_components);
                            $selected_quantities = is_array($cart_data) ? ($cart_data['component_quantities'] ?? null) : null;
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex gap-3 align-items-center"><img class="item-thumb" src="<?= htmlspecialchars($row['image_url'] ?: '../assets/images/item-placeholder.svg') ?>" alt="<?= htmlspecialchars($row['item_name']) ?>" onerror="this.src='../assets/images/item-placeholder.svg'"><div class="fw-bold"><?= htmlspecialchars($row['item_name']) ?></div></div>
                                    <small class="text-muted"><?= htmlspecialchars($row['description'] ?: 'ไม่มีรายละเอียด') ?></small>
                                </td>
                                <td class="text-center fw-bold fs-6">
                                    <?php if (!(int) $row['is_set']): ?><input type="number" form="borrowCheckout" name="quantities[<?= (int) $item_id ?>]" min="1" max="<?= (int) $row['available_quantity'] ?>" value="<?= (int) $qty ?>" class="form-control confirm-quantity" inputmode="numeric" aria-label="จำนวน <?= app_escape($row['item_name']) ?>" required><small>ชิ้น</small><?php else: ?><?= $selected_quantities !== null ? array_sum($selected_quantities) . ' ชิ้น' : $qty . ' ชุด' ?><?php endif; ?>
                                    <?php if ($selected_quantities !== null): ?><div class="small text-muted">ของย่อยตามจำนวนที่เลือก · คืนรายการนี้พร้อมกัน</div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['is_set']): ?>
                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#detailModal<?= $item_id ?>">
                                            🔍 ดูชิ้นส่วน
                                            <?php if ($excluded_count > 0 || $selected_quantities !== null): ?>
                                                <span class="badge bg-warning text-dark ms-1">เฉพาะของย่อย</span>
                                            <?php else: ?>
                                                <span class="badge bg-success ms-1">ชุดมาตรฐาน <?= (int) $qty ?> ชุด</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">ชิ้นเดียว</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline confirm-remove">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="remove_item_id" value="<?= $item_id ?>">
                                        <button type="submit" name="remove_item" class="btn btn-sm btn-outline-danger" title="ลบรายการนี้">
                                            🗑️ ลบ
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <!-- Modal แสดงรายละเอียดส่วนประกอบชุด -->
                            <?php if ($row['is_set']): ?>
                                <div class="modal fade" id="detailModal<?= $item_id ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-light">
                                                <h5 class="modal-title fw-bold">📦 รายละเอียดชุด: <?= htmlspecialchars($row['item_name']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="small text-muted mb-2">แสดงชิ้นส่วนที่เลือกรับ และชิ้นส่วนที่ถูกยกเว้นสำหรับรายการนี้:</p>
                                                <ul class="list-group">
                                                    <?php
                                                    $comp_q = mysqli_query($conn, "SELECT * FROM item_components WHERE parent_item_id = $item_id");
                                                    while ($comp_q && $comp = mysqli_fetch_assoc($comp_q)):
                                                        $comp_id = $comp['component_id'];
                                                        $is_excluded = in_array($comp_id, $excluded_components);
                                                        $selected_quantity = $selected_quantities !== null ? (int) ($selected_quantities[$comp_id] ?? 0) : ($is_excluded ? 0 : (int) $comp['quantity_per_set'] * (int) $qty);
                                                    ?>
                                                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 <?= $is_excluded ? 'bg-light text-muted' : '' ?>">
                                                            <div class="d-flex align-items-center gap-2"><img src="<?= htmlspecialchars(($comp['image_url'] ?? '') ?: '../assets/images/item-placeholder.svg') ?>" alt="<?= htmlspecialchars($comp['component_name']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:.4rem" onerror="this.src='../assets/images/item-placeholder.svg'"><div>
                                                                <?php if ($is_excluded): ?>
                                                                    <span class="badge bg-danger me-2">❌ ไม่รับ</span>
                                                                    <span class="text-decoration-line-through"><?= htmlspecialchars($comp['component_name']) ?></span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success me-2">รับ</span>
                                                                    <span class="fw-semibold"><?= htmlspecialchars($comp['component_name']) ?></span>
                                                                <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <span class="badge bg-secondary rounded-pill">
                                                                <?= $selected_quantity ?> <?= htmlspecialchars($comp['unit'] ?? 'ชิ้น') ?>
                                                            </span>
                                                        </li>
                                                    <?php endwhile; ?>
                                                </ul>
                                            </div>
                                            <div class="modal-footer">
                                                <a href="borrow.php" class="btn btn-sm btn-warning fw-bold">✏️ แก้ไขการเลือกชิ้นส่วนในหน้ายืม</a>
                                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">ปิด</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- ฟอร์มระบุวันรับของ วันคืน และยืนยัน -->
            <form method="POST" id="borrowCheckout" class="mt-4 pt-3 border-top">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="mb-3">
                    <label for="pickupDate" class="form-label fw-bold">วันที่ไปรับของ <span class="text-danger">*</span></label>
                    <input type="date" id="pickupDate" name="pickup_date" class="form-control" min="<?= date('Y-m-d') ?>" max="<?= $maximum_pickup_date ?>" value="<?= app_escape($pickup_date) ?>" aria-describedby="pickupDateHelp" required>
                    <small id="pickupDateHelp" class="text-muted">รับได้ตั้งแต่วันนี้ถึงล่วงหน้า 7 วัน</small>
                </div>
                <div class="mb-3">
                    <label for="returnDate" class="form-label fw-bold">📅 กำหนดวันที่จะนำมาคืน</label>
                    <input type="date" id="returnDate" name="expected_return_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= app_escape($return_date) ?>" required>
                    <div class="confirm-date-shortcuts" aria-label="เลือกวันคืนเร็ว"><button type="button" data-return-days="0">คืนวันเดียวกัน</button><button type="button" data-return-days="1">ยืม 1 วัน</button><button type="button" data-return-days="3">ยืม 3 วัน</button><button type="button" data-return-days="7">ยืม 7 วัน</button></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <span class="text-muted small">ส่งครั้งเดียว แล้วรอเจ้าหน้าที่อนุมัติ</span>
                    <button type="submit" name="submit_request" class="btn btn-success fw-bold px-4">
                        ส่งคำขอยืม →
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/borrow-confirm.js?v=1" defer></script>
</body>
</html>
