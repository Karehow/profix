<?php if (!isset($current_admin)) { http_response_code(404); exit; }
$adminIcon = static function (string $name): void { ?><svg class="ui-icon" aria-hidden="true"><use href="../assets/images/ui-icons.svg#<?= app_escape($name) ?>"></use></svg><?php }; ?>
<section class="admin-welcome" aria-labelledby="adminWelcomeTitle">
<div><span class="admin-eyebrow">พื้นที่ผู้ดูแลระบบ · <?= date('d/m/Y') ?></span><h1 id="adminWelcomeTitle">ดูแลการยืมคืนให้เป็นเรื่องง่าย</h1><p>สวัสดี <?= app_escape($_SESSION['user_name'] ?? 'ผู้ดูแลระบบ') ?> ตรวจสอบงานที่รอดำเนินการและจัดการสิ่งของของวัดได้จากที่นี่</p></div>
<a class="btn btn-success" href="../staff/requests.php">จัดการคำขอยืม →</a>
</section>
<div class="admin-section-heading"><div><h2>งานที่รอดำเนินการ</h2><p>เลือกงานเพื่อเปิดหน้าจัดการ</p></div></div>
<nav class="admin-work-grid" aria-label="งานยืมคืน">
<?php foreach ([
    ['../staff/requests.php','list','รออนุมัติ',$pending_count,'คำขอ','gold'],
    ['../staff/requests.php','box','รอส่งมอบ',$approved_count,'คำขอ','green'],
    ['../staff/return_check.php','return','รอตรวจรับคืน',$return_requested_count,'คำขอ','blue'],
    ['../staff/compensation.php','check','รอยืนยันของทดแทน',$receipt_pending,'รายการ','purple']
] as [$href,$icon,$label,$count,$unit,$tone]): ?>
<a class="admin-work-card tone-<?= $tone ?>" href="<?= $href ?>"><span class="admin-work-icon"><?php $adminIcon($icon); ?></span><span class="admin-work-label"><?= $label ?></span><strong><?= (int) $count ?> <small><?= $unit ?></small></strong><span class="admin-work-arrow" aria-hidden="true">→</span></a>
<?php endforeach; ?></nav>
<section class="admin-overview" aria-label="สรุปสถานะระบบ">
<?php foreach ([['กำลังยืม / รอตรวจคืน',$borrowed_count,'คำขอ'],['เกินกำหนดคืน',$overdue_count,'คำขอ'],['รอชดใช้',$damaged_unresolved,'รายการ'],['ผู้ยืมที่เปิดใช้งาน',$total_users,'คน'],['เจ้าหน้าที่ / ผู้ดูแล',$total_staff,'คน'],['สิ่งของในคลัง',$total_items,'รายการ']] as [$label,$count,$unit]): ?>
<div><span><?= $label ?></span><strong><?= (int) $count ?> <small><?= $unit ?></small></strong></div>
<?php endforeach; ?>
</section>
