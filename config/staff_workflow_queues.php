<?php $queueCounts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(status='pending_approval') AS new_requests, SUM(status='approved') AS handover, SUM(status='return_requested') AS returns FROM borrow_requests")); ?>
<nav class="row g-2 mb-3" aria-label="คิวงานยืมคืน">
<div class="col-12 col-md-4"><a class="btn btn-outline-success w-100 py-3" href="requests.php?status=pending_approval">1. คำขอใหม่ <strong><?= (int) $queueCounts['new_requests'] ?></strong></a></div>
<div class="col-12 col-md-4"><a class="btn btn-outline-primary w-100 py-3" href="requests.php?status=approved">2. รอส่งมอบ <strong><?= (int) $queueCounts['handover'] ?></strong></a></div>
<div class="col-12 col-md-4"><a class="btn btn-outline-warning w-100 py-3" href="return_check.php">3. รอตรวจรับคืน <strong><?= (int) $queueCounts['returns'] ?></strong></a></div>
</nav>
