<?php
require_once __DIR__ . '/borrow_service.php';

function replacement_submit(mysqli $conn, int $userId, int $inspectionId, array $file, string $note): void
{
    $uploadedPath = null;
    mysqli_begin_transaction($conn);
    try {
        // Match the settlement lock order: request, then inspection.
        $stmt = mysqli_prepare($conn, 'SELECT br.request_id FROM borrow_requests br JOIN borrow_items bi ON bi.request_id=br.request_id JOIN return_inspections ri ON ri.borrow_item_id=bi.borrow_item_id WHERE ri.inspection_id=? AND br.user_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $inspectionId, $userId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$request) throw new BorrowWorkflowException('ไม่พบรายการชดใช้ของคุณ');
        $requestId = (int) $request['request_id'];
        $stmt = mysqli_prepare($conn, 'SELECT user_id FROM borrow_requests WHERE request_id=? FOR UPDATE');
        mysqli_stmt_bind_param($stmt, 'i', $requestId);
        mysqli_stmt_execute($stmt);
        $owner = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$owner || (int) $owner['user_id'] !== $userId) throw new BorrowWorkflowException('ไม่พบรายการชดใช้ของคุณ');
        $stmt = mysqli_prepare($conn, 'SELECT * FROM return_inspections WHERE inspection_id=? FOR UPDATE');
        mysqli_stmt_bind_param($stmt, 'i', $inspectionId);
        mysqli_stmt_execute($stmt);
        $issue = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$issue || (int) $issue['is_resolved'] === 1 || (int) $issue['damaged_quantity'] + (int) $issue['lost_quantity'] < 1) {
            throw new BorrowWorkflowException('รายการนี้ไม่ต้องชดใช้หรือเจ้าหน้าที่ยืนยันแล้ว');
        }
        if ($issue['replacement_submitted_at'] !== null) throw new BorrowWorkflowException('ส่งยืนยันแล้ว กรุณารอเจ้าหน้าที่ยืนยันรับของจริง');
        if (mb_strlen($note, 'UTF-8') > 2000) throw new BorrowWorkflowException('หมายเหตุยาวเกิน 2,000 ตัวอักษร');
        $imageUrl = null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new BorrowWorkflowException('กรุณาแนบรูปของที่ซื้อคืนวัดให้สำเร็จ');
            }
            if (filesize($file['tmp_name']) > 5 * 1024 * 1024) throw new BorrowWorkflowException('รูปต้องมีขนาดไม่เกิน 5 MB');
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) throw new BorrowWorkflowException('รองรับเฉพาะรูป JPG, PNG และ WEBP');
            $directory = __DIR__ . '/../uploads';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Cannot create upload directory');
            $filename = 'replacement_' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
            $uploadedPath = $directory . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $uploadedPath)) throw new RuntimeException('Cannot save replacement image');
            $imageUrl = '../uploads/' . $filename;
        }
        $stmt = mysqli_prepare($conn, "UPDATE return_inspections SET replacement_image_url=?, replacement_note=?, replacement_submitted_at=NOW(), action_required='buy_replacement' WHERE inspection_id=?");
        mysqli_stmt_bind_param($stmt, 'ssi', $imageUrl, $note, $inspectionId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $staff = mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('staff','admin') AND is_active=1");
        while ($recipient = mysqli_fetch_assoc($staff)) {
            create_notification($conn, (int) $recipient['user_id'], 'รอยืนยันรับของทดแทน', 'ผู้ยืมส่งยืนยันของทดแทนสำหรับคำขอ #' . $requestId . ' กรุณาตรวจสอบรายการและยืนยันเมื่อได้รับของจริง', '../staff/compensation.php#inspection-' . $inspectionId);
        }
        write_audit_log($conn, $userId, 'submit_replacement', 'ส่งยืนยันของทดแทน ผลตรวจ #' . $inspectionId);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($uploadedPath !== null && is_file($uploadedPath)) unlink($uploadedPath);
        throw $e;
    }
}
