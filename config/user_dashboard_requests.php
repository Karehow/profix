<?php if (!isset($active_requests)) { http_response_code(404); exit; } ?>
            <?php if (mysqli_num_rows($active_requests) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">เลขคำขอ</th>
                                <th>วันที่ทำรายการ</th>
                                <th>กำหนดวันคืน</th>
                                <th>สถานะ</th>
                                <th class="text-end pe-3">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($req = mysqli_fetch_assoc($active_requests)): ?>
                                <tr>
                                    <td class="ps-3 fw-bold">#<?= $req['request_id'] ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                                    <td><?= $req['expected_return_date'] ? date('d/m/Y', strtotime($req['expected_return_date'])) : '-' ?></td>
                                    <td>
                                        <?php
                                        $status_badge = [
                                            'pending_approval' => '<span class="badge bg-warning text-dark">รออนุมัติ</span>',
                                            'approved'         => '<span class="badge bg-success">อนุมัติแล้ว รอรับของ</span>',
                                            'borrowed'         => '<span class="badge bg-primary">กำลังยืมอยู่</span>',
                                            'return_requested' => '<span class="badge bg-info text-dark">รอตรวจรับคืน</span>',
                                            'returned'         => '<span class="badge bg-success">คืนเรียบร้อย</span>',
                                            'partially_damaged'=> '<span class="badge bg-danger">ชำรุด/สูญหาย</span>',
                                            'rejected'         => '<span class="badge bg-secondary">ไม่อนุมัติ</span>',
                                            'cancelled'        => '<span class="badge bg-dark">ยกเลิก</span>'
                                        ];
                                        echo $status_badge[$req['status']] ?? '<span class="badge bg-dark">' . $req['status'] . '</span>';
                                        ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <a href="history.php" class="btn btn-sm btn-outline-secondary">
                                            รายละเอียด
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted">
                    ยังไม่มีรายการยืมสิ่งของในระบบ <a href="borrow.php" class="text-primary text-decoration-none">เริ่มยืมสิ่งของวัดได้ที่นี่</a>
                </div>
            <?php endif; ?>
