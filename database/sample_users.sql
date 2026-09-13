-- บัญชีตัวอย่างสำหรับทดสอบครบ 3 สิทธิ์
-- ผู้ใช้งาน: เบอร์ 0800000101 / PIN 123456 (หน้า user/index.php)
-- เจ้าหน้าที่: staff_demo / Staff@123456 (หน้า config/login.php)
-- ผู้ดูแลระบบ: admin_demo / Admin@123456 (หน้า config/login.php)
-- รันซ้ำได้: หากชื่อผู้ใช้หรือเบอร์ซ้ำ จะไม่แก้ไขบัญชีเดิม
-- เก็บ PIN และรหัสผ่านเป็นแฮชที่รองรับ password_verify() ของ PHP

SET NAMES utf8mb4;
USE `profix`;

INSERT INTO `users`
    (`username`, `password`, `borrower_pin_hash`, `first_name`, `last_name`,
     `phone_number`, `normalized_phone`, `address_detail`, `role`, `is_active`)
VALUES
    (NULL, NULL,
     '$2y$10$xvsYGkTdmSA.kgjotuiIFOLbPFHGNioY7yUVBFlPrP991XU6qm8DC',
     'ผู้ใช้งาน', 'ทดสอบ', '0800000101', '0800000101',
     'ที่อยู่ตัวอย่างสำหรับทดสอบระบบ', 'user', 1),
    ('staff_demo',
     '$2y$10$m78YK8kEmIvkhuwoOredgOq1QgCObJyteFhFON5SRmHpd7OuP2IEK', NULL,
     'เจ้าหน้าที่', 'ทดสอบ', '0800000102', '0800000102',
     'สำนักงานวัด (ข้อมูลตัวอย่าง)', 'staff', 1),
    ('admin_demo',
     '$2y$10$1KajsY.2LLlVoQL6yI7pqO9.4U2BlXdGtGQZxf8ZdkX52e/6GYPCm', NULL,
     'ผู้ดูแลระบบ', 'ทดสอบ', '0800000103', '0800000103',
     'สำนักงานวัด (ข้อมูลตัวอย่าง)', 'admin', 1)
ON DUPLICATE KEY UPDATE `user_id` = `users`.`user_id`;
