<?php // Shared dashboard inbox; $inbox is loaded for the authenticated user. ?>
<section id="notificationInbox" class="card border-0 shadow-sm mb-4" aria-labelledby="notificationInboxTitle">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div><h2 id="notificationInboxTitle" class="h5 fw-bold mb-1">🔔 กล่องข้อความแจ้งเตือน <span class="badge <?= $inbox['unread'] ? 'bg-danger' : 'bg-secondary' ?>"><?= $inbox['unread'] ?> ยังไม่อ่าน</span></h2><small class="text-muted">ติดตามคำขอและสถานะรายการของคุณ</small></div>
        <?php if ($inbox['unread'] > 0): ?>
        <form method="post" action="home.php"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="notification_action" value="read_all"><input type="hidden" name="through_id" value="<?= $inbox['latest_id'] ?>"><button class="btn btn-sm btn-outline-primary">อ่านแล้วทั้งหมด</button></form>
        <?php endif; ?>
    </div>
    <div class="list-group list-group-flush">
        <?php if (!$inbox['messages']): ?><div class="text-center text-muted p-4">ยังไม่มีข้อความแจ้งเตือน<br><small>เมื่อมีความเคลื่อนไหวของรายการ ข้อความจะแสดงที่นี่</small></div><?php endif; ?>
        <?php foreach ($inbox['messages'] as $notice): ?>
        <article class="list-group-item p-3 <?= (int) $notice['is_read'] === 0 ? 'bg-light border-start border-primary border-4' : '' ?>">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-1"><h3 class="h6 mb-0 fw-bold" style="overflow-wrap:anywhere"><?= app_escape($notice['title']) ?> <?php if ((int) $notice['is_read'] === 0): ?><span class="badge bg-primary">ใหม่</span><?php endif; ?></h3><time class="small text-muted" datetime="<?= app_escape(date('c', strtotime($notice['created_at']))) ?>"><?= app_escape(date('d/m/Y H:i', strtotime($notice['created_at']))) ?></time></div>
            <p class="small mb-2" style="white-space:pre-line;overflow-wrap:anywhere"><?= app_escape($notice['message']) ?></p>
            <form method="post" action="home.php" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="notification_id" value="<?= (int) $notice['notification_id'] ?>">
                <?php if (notification_target($notice['link_url']) !== 'home.php#notificationInbox'): ?><button name="notification_action" value="open" class="btn btn-sm btn-outline-primary" aria-label="ดูรายละเอียด: <?= app_escape($notice['title']) ?>">ดูรายละเอียด</button><?php endif; ?>
                <?php if ((int) $notice['is_read'] === 0): ?><button name="notification_action" value="read" class="btn btn-sm btn-outline-secondary">ทำเครื่องหมายว่าอ่านแล้ว</button><?php else: ?><span class="small text-muted">อ่านแล้ว</span><?php endif; ?>
            </form>
        </article>
        <?php endforeach; ?>
    </div>
    <?php if ($inbox['pages'] > 1): ?><nav class="card-footer bg-white d-flex justify-content-between align-items-center" aria-label="หน้าข้อความแจ้งเตือน"><div><?php if ($inbox['page'] > 1): ?><a class="btn btn-sm btn-outline-secondary" href="home.php?notice_page=<?= $inbox['page'] - 1 ?>#notificationInbox">ก่อนหน้า</a><?php endif; ?></div><span class="small text-muted">หน้า <?= $inbox['page'] ?> / <?= $inbox['pages'] ?></span><div><?php if ($inbox['page'] < $inbox['pages']): ?><a class="btn btn-sm btn-outline-secondary" href="home.php?notice_page=<?= $inbox['page'] + 1 ?>#notificationInbox">ถัดไป</a><?php endif; ?></div></nav><?php endif; ?>
</section>
<script src="../assets/js/notification-inbox.js" defer></script>
