<?php

require_once __DIR__ . '/../config/app.php';

ensure_feature_tables($conn);
$current_admin = require_roles($conn, ['admin'], '../config/login.php');

$action_labels = [
    'submit_borrow_request' => 'ส่งคำขอยืม',
    'approve_borrow_request' => 'อนุมัติและกันสต็อก',
    'handover_borrow_request' => 'ยืนยันส่งมอบ',
    'reject_borrow_request' => 'ไม่อนุมัติคำขอ',
    'cancel_borrow_request' => 'ยกเลิกคำขอ',
    'request_return' => 'ผู้ยืมแจ้งคืน',
    'inspect_return' => 'ตรวจรับคืน',
    'record_compensation' => 'บันทึกการชดใช้',
    'review_reservation' => 'ตรวจสอบการจอง',
    'convert_reservation_to_borrow' => 'เปลี่ยนการจองเป็นคำขอยืม',
    'create_reservation' => 'สร้างการจอง',
    'cancel_reservation' => 'ยกเลิกการจอง',
    'create_user' => 'เพิ่มผู้ใช้',
    'update_user' => 'แก้ไขผู้ใช้',
    'activate_user' => 'เปิดใช้งานผู้ใช้',
    'deactivate_user' => 'ระงับผู้ใช้',
    'register_borrower' => 'ลงทะเบียนผู้ยืม',
    'activate_borrower_pin' => 'ตั้ง รหัสผ่าน ให้บัญชีเดิม',
    'update_profile' => 'แก้ไขข้อมูลผู้ยืม',
    'create_item' => 'เพิ่มรายการสิ่งของ',
    'update_item' => 'แก้ไขรายการสิ่งของ',
    'activate_item' => 'เปิดใช้รายการสิ่งของ',
    'deactivate_item' => 'ปิดใช้รายการสิ่งของ',
    'create_item_component' => 'เพิ่มชิ้นส่วนในชุด',
    'update_item_component' => 'แก้ไขชิ้นส่วนในชุด',
    'delete_item_component' => 'ลบชิ้นส่วนในชุด',
    'create_maintenance' => 'แจ้งซ่อมบำรุง',
    'update_maintenance' => 'อัปเดตงานซ่อมบำรุง',
    'download_database_backup' => 'ดาวน์โหลดข้อมูลสำรอง',
];

$selected_action = trim((string) ($_GET['action'] ?? ''));
if ($selected_action !== '' && !array_key_exists($selected_action, $action_labels)) {
    $selected_action = '';
}

if ($selected_action !== '') {
    $stmt = mysqli_prepare($conn, 'SELECT a.*, u.first_name, u.last_name, u.role
        FROM audit_logs a
        LEFT JOIN users u ON u.user_id = a.user_id
        WHERE a.action = ?
        ORDER BY a.created_at DESC, a.log_id DESC LIMIT 200');
    mysqli_stmt_bind_param($stmt, 's', $selected_action);
    mysqli_stmt_execute($stmt);
    $logs = mysqli_stmt_get_result($stmt);
} else {
    $stmt = mysqli_prepare($conn, 'SELECT a.*, u.first_name, u.last_name, u.role
        FROM audit_logs a
        LEFT JOIN users u ON u.user_id = a.user_id
        ORDER BY a.created_at DESC, a.log_id DESC LIMIT 200');
    mysqli_stmt_execute($stmt);
    $logs = mysqli_stmt_get_result($stmt);
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ประวัติกิจกรรมระบบ</title>
    <link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css">
<?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'admin/audit.php'; require __DIR__ . '/../config/page_shell.php'; ?>
<main class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">ประวัติกิจกรรมระบบ</h1>
            <span class="text-muted">บันทึกการดำเนินการสำคัญล่าสุดสูงสุด 200 รายการ</span>
        </div>
        <a href="home.php" class="btn btn-outline-primary">กลับหน้าผู้ดูแล</a>
    </div>

    <form method="get" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-8">
                <label for="action" class="form-label">กรองประเภทกิจกรรม</label>
                <select class="form-select" id="action" name="action">
                    <option value="">ทุกกิจกรรม</option>
                    <?php foreach ($action_labels as $action => $label): ?>
                        <option value="<?= app_escape($action) ?>" <?= $selected_action === $action ? 'selected' : '' ?>><?= app_escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit">แสดงผล</button>
                <a href="audit.php" class="btn btn-outline-secondary">ล้างตัวกรอง</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>วันเวลา</th><th>ผู้ดำเนินการ</th><th>การดำเนินการ</th><th>รายละเอียด</th></tr></thead>
                <tbody>
                <?php if ($logs && mysqli_num_rows($logs) > 0): ?>
                    <?php while ($log = mysqli_fetch_assoc($logs)): ?>
                        <?php
                            $actor_name = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                            $action = (string) $log['action'];
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= app_escape(date('d/m/Y H:i', strtotime($log['created_at']))) ?></td>
                            <td><?= app_escape($actor_name ?: 'ระบบ/บัญชีที่ถูกลบ') ?><?php if (!empty($log['role'])): ?><div class="small text-muted"><?= app_escape($log['role']) ?> · #<?= (int) $log['user_id'] ?></div><?php endif; ?></td>
                            <td><span class="badge bg-secondary"><?= app_escape($action_labels[$action] ?? $action) ?></span></td>
                            <td><?= nl2br(app_escape($log['detail'] ?: '-')) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="text-center text-muted py-5">ไม่พบประวัติตามตัวกรองที่เลือก</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
<?php mysqli_stmt_close($stmt); ?>
