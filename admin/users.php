<?php

require_once __DIR__ . '/../config/app.php';
ensure_feature_tables($conn);
$currentUser = require_roles($conn, ['admin'], '../config/login.php');
$adminId = (int) $currentUser['user_id'];
$roles = ['user', 'staff', 'admin'];

function admin_user_text($value, string $label, int $max): string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    if ($text === '' || mb_strlen($text) > $max) {
        throw new RuntimeException('กรุณาระบุ' . $label . 'ไม่เกิน ' . $max . ' ตัวอักษร');
    }
    return $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    mysqli_begin_transaction($conn);
    try {
        if ($action === 'toggle_active') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $active = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($userId < 1 || ($userId === $adminId && $active === 0)) {
                throw new RuntimeException('ไม่สามารถปิดบัญชีที่กำลังใช้งานอยู่');
            }
            $lock = mysqli_prepare($conn, 'SELECT role, is_active FROM users WHERE user_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $userId);
            mysqli_stmt_execute($lock);
            $target = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$target) {
                throw new RuntimeException('ไม่พบบัญชีผู้ใช้');
            }
            if ($target['role'] === 'admin' && $active === 0) {
                $admins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"));
                if ((int) $admins['total'] <= 1) {
                    throw new RuntimeException('ต้องมีผู้ดูแลระบบที่เปิดใช้งานอย่างน้อย 1 บัญชี');
                }
            }
            $update = mysqli_prepare($conn, 'UPDATE users SET is_active = ? WHERE user_id = ?');
            mysqli_stmt_bind_param($update, 'ii', $active, $userId);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            write_audit_log($conn, $adminId, $active ? 'activate_user' : 'deactivate_user', 'ผู้ใช้ #' . $userId);
            flash_set('success', $active ? 'เปิดใช้งานบัญชีแล้ว' : 'ปิดใช้งานบัญชีแล้ว โดยยังเก็บประวัติทั้งหมดไว้');
        } elseif (in_array($action, ['create', 'update'], true)) {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $firstName = admin_user_text($_POST['first_name'] ?? '', 'ชื่อ', 100);
            $lastName = admin_user_text($_POST['last_name'] ?? '', 'นามสกุล', 100);
            $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
            $address = trim((string) ($_POST['address_detail'] ?? ''));
            $role = in_array($_POST['role'] ?? '', $roles, true) ? (string) $_POST['role'] : '';
            $username = trim((string) ($_POST['username'] ?? ''));
            $secret = (string) ($_POST['password'] ?? '');
            if (!valid_thai_phone($phone) || $role === '' || mb_strlen($address) > 5000) {
                throw new RuntimeException('เบอร์โทรศัพท์ บทบาท หรือที่อยู่ไม่ถูกต้อง');
            }

            $existing = null;
            if ($action === 'update') {
                $lock = mysqli_prepare($conn, 'SELECT * FROM users WHERE user_id = ? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'i', $userId);
                mysqli_stmt_execute($lock);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$existing) {
                    throw new RuntimeException('ไม่พบบัญชีที่ต้องการแก้ไข');
                }
                if ($userId === $adminId && $role !== 'admin') {
                    throw new RuntimeException('ไม่สามารถลดสิทธิ์บัญชีผู้ดูแลที่กำลังใช้งาน');
                }
            }

            $phoneCheck = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE normalized_phone = ? AND user_id <> ? LIMIT 1');
            mysqli_stmt_bind_param($phoneCheck, 'si', $phone, $userId);
            mysqli_stmt_execute($phoneCheck);
            $duplicatePhone = mysqli_fetch_assoc(mysqli_stmt_get_result($phoneCheck));
            mysqli_stmt_close($phoneCheck);
            if ($duplicatePhone) {
                throw new RuntimeException('เบอร์โทรศัพท์นี้ถูกใช้งานแล้ว');
            }

            $passwordHash = $existing['password'] ?? null;
            $pinHash = $existing['borrower_pin_hash'] ?? null;
            if ($role === 'user') {
                $username = '';
                $passwordHash = null;
                if ($secret !== '') {
                    if (!valid_pin($secret)) {
                        throw new RuntimeException('รหัสผ่าน ผู้ยืมต้องเป็นตัวเลข 6 หลัก');
                    }
                    $pinHash = password_hash($secret, PASSWORD_DEFAULT);
                } elseif ($action === 'create' || !$pinHash) {
                    throw new RuntimeException('กรุณากำหนด รหัสผ่าน 6 หลักสำหรับผู้ยืม');
                }
            } else {
                if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                    throw new RuntimeException('ชื่อผู้ใช้ต้องยาว 3–50 ตัว และใช้เฉพาะ A-Z, 0-9, จุด ขีด หรือขีดล่าง');
                }
                if ($secret !== '') {
                    if (strlen($secret) < 12) {
                        throw new RuntimeException('รหัสผ่านเจ้าหน้าที่ต้องมีอย่างน้อย 12 ตัวอักษร');
                    }
                    $passwordHash = password_hash($secret, PASSWORD_DEFAULT);
                } elseif ($action === 'create' || !$passwordHash || ($existing && $existing['role'] === 'user')) {
                    throw new RuntimeException('กรุณากำหนดรหัสผ่านอย่างน้อย 12 ตัวอักษร');
                }
                $pinHash = null;
            }
            $usernameValue = $username === '' ? null : $username;
            $addressValue = $address === '' ? null : $address;

            if ($action === 'create') {
                $insert = mysqli_prepare($conn, 'INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                mysqli_stmt_bind_param($insert, 'sssssssss', $usernameValue, $passwordHash, $pinHash, $firstName, $lastName, $phone, $phone, $addressValue, $role);
                if (!mysqli_stmt_execute($insert)) {
                    throw new RuntimeException(mysqli_stmt_errno($insert) === 1062 ? 'ชื่อผู้ใช้หรือเบอร์โทรศัพท์ซ้ำ' : mysqli_stmt_error($insert));
                }
                $userId = mysqli_insert_id($conn);
                mysqli_stmt_close($insert);
                write_audit_log($conn, $adminId, 'create_user', 'ผู้ใช้ #' . $userId . ', role=' . $role);
                flash_set('success', 'เพิ่มบัญชีผู้ใช้เรียบร้อยแล้ว');
            } else {
                $update = mysqli_prepare($conn, 'UPDATE users SET username = ?, password = ?, borrower_pin_hash = ?, first_name = ?, last_name = ?, phone_number = ?, normalized_phone = ?, address_detail = ?, role = ? WHERE user_id = ?');
                mysqli_stmt_bind_param($update, 'sssssssssi', $usernameValue, $passwordHash, $pinHash, $firstName, $lastName, $phone, $phone, $addressValue, $role, $userId);
                if (!mysqli_stmt_execute($update)) {
                    throw new RuntimeException(mysqli_stmt_errno($update) === 1062 ? 'ชื่อผู้ใช้หรือเบอร์โทรศัพท์ซ้ำ' : mysqli_stmt_error($update));
                }
                mysqli_stmt_close($update);
                write_audit_log($conn, $adminId, 'update_user', 'ผู้ใช้ #' . $userId . ', role ' . $existing['role'] . '→' . $role);
                flash_set('success', 'บันทึกข้อมูลผู้ใช้เรียบร้อยแล้ว');
            }
        } else {
            throw new RuntimeException('การดำเนินการไม่ถูกต้อง');
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        flash_set('danger', $e->getMessage());
    }
    app_redirect('users.php');
}

$users = mysqli_query($conn, "SELECT * FROM users ORDER BY is_active DESC, FIELD(role,'admin','staff','user'), first_name, last_name");
$flash = flash_take();
?>
<!doctype html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>จัดการผู้ใช้</title><link rel="stylesheet" href="../bootstrap-5.0.2-dist/css/bootstrap.min.css"><link rel="stylesheet" href="../assets/css/temple-theme.css">
    <link rel="stylesheet" href="../assets/css/app-ui.css"><?php require __DIR__ . '/../config/theme.php'; ?></head>
<body class="app-ui bg-light py-4">
<?php $uiPage = 'admin/users.php'; require __DIR__ . '/../config/page_shell.php'; ?><main class="container">
    <div class="d-flex justify-content-between align-items-center mb-4"><div><h3 class="fw-bold mb-1">จัดการผู้ใช้</h3><span class="text-muted">ปิดบัญชีแทนการลบ เพื่อเก็บประวัติการยืมและการตรวจรับ</span></div><a href="home.php" class="btn btn-outline-primary">หน้าหลักผู้ดูแล</a></div>
    <?php if ($flash): ?><div class="alert alert-<?= app_escape($flash['type']) ?>"><?= app_escape($flash['message']) ?></div><?php endif; ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-bold">เพิ่มผู้ใช้</div><div class="card-body">
        <form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="create">
            <div class="col-md-3"><label class="form-label">ชื่อ</label><input name="first_name" maxlength="100" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">นามสกุล</label><input name="last_name" maxlength="100" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">เบอร์โทรศัพท์</label><input name="phone_number" maxlength="20" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">บทบาท</label><select name="role" class="form-select account-role"><?php foreach ($roles as $role): ?><option value="<?= $role ?>"><?= $role === 'user' ? 'ผู้ยืม' : ($role === 'staff' ? 'เจ้าหน้าที่' : 'ผู้ดูแลระบบ') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">ชื่อผู้ใช้ (เจ้าหน้าที่/แอดมิน)</label><input name="username" maxlength="50" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">รหัสผ่าน (ผู้ยืมใช้ตัวเลข 6 หลัก)</label><input name="password" type="password" class="form-control" required><div class="form-text">บัญชีเจ้าหน้าที่/แอดมินต้องไม่น้อยกว่า 12 ตัวอักษร</div></div>
            <div class="col-md-4"><label class="form-label">ที่อยู่</label><input name="address_detail" maxlength="5000" class="form-control"></div>
            <div class="col-12"><button class="btn btn-primary">เพิ่มผู้ใช้</button></div>
        </form>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>ชื่อ</th><th>บัญชี</th><th>โทรศัพท์</th><th>บทบาท</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead><tbody>
        <?php while ($user = mysqli_fetch_assoc($users)): ?>
            <tr class="<?= (int) $user['is_active'] === 0 ? 'table-secondary' : '' ?>">
                <td class="fw-semibold"><?= app_escape($user['first_name'] . ' ' . $user['last_name']) ?></td><td><?= app_escape($user['username'] ?: '-') ?></td><td><?= app_escape($user['phone_number']) ?></td>
                <td><span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : ($user['role'] === 'staff' ? 'bg-primary' : 'bg-secondary') ?>"><?= app_escape($user['role']) ?></span></td>
                <td><span class="badge <?= (int) $user['is_active'] === 1 ? 'bg-success' : 'bg-dark' ?>"><?= (int) $user['is_active'] === 1 ? 'ใช้งาน' : 'ปิดบัญชี' ?></span></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit<?= (int) $user['user_id'] ?>">แก้ไข</button>
                    <?php if ((int) $user['user_id'] !== $adminId): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>"><input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? 0 : 1 ?>"><button class="btn btn-sm <?= (int) $user['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" onclick="return confirm('ยืนยันเปลี่ยนสถานะบัญชี?')"><?= (int) $user['is_active'] === 1 ? 'ปิดบัญชี' : 'เปิดบัญชี' ?></button></form><?php endif; ?>
                </td>
            </tr>
            <div class="modal fade" id="edit<?= (int) $user['user_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                <div class="modal-header"><h5 class="modal-title">แก้ไขผู้ใช้</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3">
                    <div class="col-6"><label class="form-label">ชื่อ</label><input name="first_name" maxlength="100" class="form-control" value="<?= app_escape($user['first_name']) ?>" required></div>
                    <div class="col-6"><label class="form-label">นามสกุล</label><input name="last_name" maxlength="100" class="form-control" value="<?= app_escape($user['last_name']) ?>" required></div>
                    <div class="col-6"><label class="form-label">โทรศัพท์</label><input name="phone_number" maxlength="20" class="form-control" value="<?= app_escape($user['phone_number']) ?>" required></div>
                    <div class="col-6"><label class="form-label">บทบาท</label><select name="role" class="form-select"><?php foreach ($roles as $role): ?><option value="<?= $role ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= app_escape($role) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label">ชื่อผู้ใช้</label><input name="username" maxlength="50" class="form-control" value="<?= app_escape($user['username'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label">รหัสผ่านใหม่</label><input name="password" type="password" class="form-control"><div class="form-text">เว้นว่างเพื่อใช้ค่าเดิม; รหัสเจ้าหน้าที่/แอมินขั้นต่ำ 12 ตัว</div></div>
                    <div class="col-12"><label class="form-label">ที่อยู่</label><textarea name="address_detail" class="form-control" rows="2"><?= app_escape($user['address_detail'] ?? '') ?></textarea></div>
                </div></div><div class="modal-footer"><button class="btn btn-primary">บันทึก</button></div>
            </form></div></div></div>
        <?php endwhile; ?>
        </tbody>
    </table></div></div>
</main><script src="../bootstrap-5.0.2-dist/js/bootstrap.bundle.min.js"></script></body></html>
