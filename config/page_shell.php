<?php
// Include only after the page has authenticated its user and opened <body>.
if (!isset($uiPage) || empty($_SESSION['user_id'])) { return; }
$uiRole = $_SESSION['role'] ?? 'user';
$uiRoleName = ['user'=>'ผู้ยืม','staff'=>'เจ้าหน้าที่','admin'=>'ผู้ดูแลระบบ'][$uiRole] ?? 'ผู้ใช้งาน';
$uiHome = in_array($uiRole, ['staff','admin'], true) ? $uiRole . '/home.php' : 'user/home.php';
$uiMenus = $uiRole === 'user' ? [
    ['ยืมสิ่งของ', [['user/borrow.php','เลือกยืมสิ่งของ','box'],['user/history.php','การยืมของฉัน','list']]],
    ['บัญชี', [['user/profile.php','ข้อมูลส่วนตัว','user']]],
] : [
    ['ภาพรวม', [[$uiHome,'หน้าหลัก','home']]],
    ['งานยืมคืน', [['staff/requests.php','คำขอยืมและส่งมอบ','list'],['staff/return_check.php','ตรวจรับคืน','return'],['staff/compensation.php','รับของทดแทน','check'],['staff/items.php','คลังสิ่งของ','box'],['staff/stock_discrepancies.php','ตรวจสอบของในคลัง','box'],['staff/maintenance.php','งานซ่อมบำรุง','tool'],['staff/pin_reset.php','ช่วยเหลือเรื่อง รหัสผ่าน','user']]],
];
if ($uiRole === 'admin') $uiMenus[] = ['ดูแลระบบ', [['admin/branding.php','โลโก้และแบนเนอร์','temple'],['admin/reservations.php','จัดการการจอง','calendar'],['admin/users.php','บัญชีผู้ใช้งาน','users'],['admin/audit.php','ประวัติกิจกรรม','clock']]];
$uiTitle = 'ระบบยืมคืนสิ่งของวัด';
foreach ($uiMenus as [, $links]) foreach ($links as [$path,$label]) if ($path === $uiPage) $uiTitle=$label;
if ($uiPage === 'user/replacement_confirm.php') $uiTitle='ยืนยันของทดแทน';
$uiName = trim((string) ($_SESSION['user_name'] ?? '')) ?: $uiRoleName;
$uiIcon = static function (string $name): void { ?><svg class="ui-icon" aria-hidden="true"><use href="../assets/images/ui-icons.svg#<?= $name ?>"></use></svg><?php };
?>
<a class="ui-skip" href="#pageContent">ข้ามไปเนื้อหา</a>
<?php if (in_array($uiPage, ['user/home.php', 'user/borrow.php', 'user/history.php', 'user/return.php'], true)): ?>
<header class="ui-topbar dashboard-topbar">
<div class="ui-topbar-start"><a class="dashboard-brand" href="home.php"><span class="dashboard-brand-mark"><?php $uiIcon('temple'); ?></span><span>ระบบยืมคืนสิ่งของวัด<small>ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด</small></span></a><button class="ui-menu-toggle" type="button" aria-label="เปิดเมนูหลัก" aria-controls="siteSidebar" aria-expanded="false"><?php $uiIcon('menu'); ?></button></div>
<div class="ui-account"><a class="dashboard-notice" href="<?= $uiPage === 'user/home.php' ? '#notificationInbox' : 'home.php#notificationInbox' ?>" aria-label="การแจ้งเตือน <?= (int) ($inbox['unread'] ?? 0) ?> รายการยังไม่อ่าน"><svg class="ui-icon dashboard-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4M12 2v1"></path></svg><?php if (!empty($inbox['unread'])): ?><span><?= (int) $inbox['unread'] ?></span><?php endif; ?></a><a class="dashboard-account-link" href="profile.php" aria-label="ข้อมูลส่วนตัว"><span class="ui-avatar"><img src="<?= app_escape($_SESSION['profile_image_url'] ?? '../assets/images/profile-placeholder.svg') ?>" alt=""></span><span><strong><?= app_escape($uiName) ?></strong><small><?= $uiRoleName ?></small></span></a><form action="../config/logout.php?next=user" method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><button class="dashboard-logout" type="submit" aria-label="ออกจากระบบ"><?php $uiIcon('logout'); ?> <span>ออกจากระบบ</span></button></form></div>
</header>
<?php elseif (in_array($uiRole, ['admin', 'staff'], true)): ?>
<?php
$managementUnread = (int) ($inbox['unread'] ?? 0);
if (!isset($inbox['unread']) && isset($conn)) {
    $noticeStmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $noticeUserId = (int) $_SESSION['user_id'];
    mysqli_stmt_bind_param($noticeStmt, 'i', $noticeUserId);
    mysqli_stmt_execute($noticeStmt);
    $managementUnread = (int) mysqli_fetch_row(mysqli_stmt_get_result($noticeStmt))[0];
    mysqli_stmt_close($noticeStmt);
}
?>
<header class="ui-topbar management-topbar">
<div class="ui-topbar-start"><a class="management-brand" href="../<?= $uiHome ?>"><span class="management-brand-mark"><?php $uiIcon('temple'); ?></span><span>ระบบยืมคืนสิ่งของวัด<small>ระบบบริหารจัดการยืม–คืนอุปกรณ์ของวัด</small></span></a><button class="ui-menu-toggle" type="button" aria-label="เปิดเมนูหลัก" aria-controls="siteSidebar" aria-expanded="false"><?php $uiIcon('menu'); ?></button></div>
<div class="ui-account"><a class="dashboard-notice" href="<?= $uiPage === $uiHome ? '#notificationInbox' : '../' . $uiHome . '#notificationInbox' ?>" aria-label="การแจ้งเตือน <?= $managementUnread ?> รายการยังไม่อ่าน"><svg class="ui-icon dashboard-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4M12 2v1"></path></svg><?php if ($managementUnread > 0): ?><span><?= $managementUnread ?></span><?php endif; ?></a><span class="ui-avatar"><img src="<?= app_escape($_SESSION['profile_image_url'] ?? '../assets/images/profile-placeholder.svg') ?>" alt=""></span><div><strong><?= app_escape($uiName) ?></strong><small><?= $uiRoleName ?></small></div><form action="../config/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><button class="management-logout" type="submit" aria-label="ออกจากระบบ"><?php $uiIcon('logout'); ?><span>ออกจากระบบ</span></button></form></div>
</header>
<?php else: ?>
<header class="ui-topbar"><div class="ui-topbar-start"><button class="ui-menu-toggle" type="button" aria-label="เปิดเมนูหลัก" aria-controls="siteSidebar" aria-expanded="false"><?php $uiIcon('menu'); ?></button><a class="text-decoration-none" href="../<?= $uiHome ?>" aria-label="กลับหน้าหลัก"><span class="ui-topbar-label">ระบบยืมคืนสิ่งของวัด</span><span class="ui-topbar-title"><?= app_escape($uiTitle) ?></span></a></div><div class="ui-account"><span class="ui-avatar" aria-hidden="true"><?php if (!empty($_SESSION['profile_image_url'])): ?><img src="<?= app_escape($_SESSION['profile_image_url']) ?>" alt=""><?php else: ?><?= app_escape(mb_substr($uiName,0,1,'UTF-8')) ?><?php endif; ?></span><div><strong><?= app_escape($uiName) ?></strong><small><?= $uiRoleName ?></small></div></div></header>
<?php endif; ?>
<button class="ui-menu-backdrop" type="button" aria-label="ปิดเมนู" hidden></button>
<aside id="siteSidebar" class="ui-sidebar" aria-label="เมนูหลัก">
<a class="ui-brand" href="../<?= $uiHome ?>"><span class="ui-brand-icon"><?php $uiIcon('temple'); ?></span><span>ยืมคืนสิ่งของวัด<small>พื้นที่<?= $uiRoleName ?></small></span></a>
<nav class="ui-navigation" aria-label="เมนู<?= $uiRoleName ?>">
<?php foreach ($uiMenus as [$group,$links]): ?><div class="ui-nav-group"><p><?= $group ?></p><?php foreach ($links as [$path,$label,$icon]): $active = $path === $uiPage || ($path === 'user/compensation.php' && $uiPage === 'user/replacement_confirm.php'); ?><a href="../<?= $path ?>" <?= $active ? 'aria-current="page"' : '' ?>><?php $uiIcon($icon); ?><span><?= $label ?></span></a><?php endforeach; ?></div><?php endforeach; ?>
</nav><div class="ui-sidebar-footer"><a href="../index.php"><?php $uiIcon('home'); ?>หน้าเว็บไซต์</a><form action="../config/logout.php<?= $uiRole === 'user' ? '?next=user' : '' ?>" method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><button type="submit"><?php $uiIcon('logout'); ?>ออกจากระบบ</button></form></div>
</aside><div id="pageContent" tabindex="-1" class="ui-content-start"></div>
<script src="../assets/js/app-ui.js" defer></script>
