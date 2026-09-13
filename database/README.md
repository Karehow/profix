# การติดตั้งและอัปเกรดฐานข้อมูล

ไฟล์ในโฟลเดอร์นี้แบ่งการใช้งานออกเป็นสองกรณีอย่างชัดเจน:

- `profix.sql` ใช้ติดตั้งฐานข้อมูลใหม่เท่านั้น มีโครงสร้างล่าสุดและหมวดหมู่เริ่มต้น แต่ไม่มีบัญชีผู้ใช้หรือรหัสผ่านตัวอย่าง
- `migrate.php` ใช้อัปเกรดฐานข้อมูลเดิมโดยเก็บข้อมูลยืมคืนและข้อมูลผู้ใช้ที่มีอยู่
- `bootstrap_admin.php` ใช้สร้างบัญชีผู้ดูแลคนแรกจาก command line โดยเก็บเฉพาะ password hash
- `migrate_profile_photo.php` เพิ่มช่องรูปโปรไฟล์สำหรับฐานข้อมูลเดิม ใช้ `php database/migrate_profile_photo.php` หรือรัน `migrate.php` เพื่ออัปเกรดทั้งหมด
- ทดสอบรูปโปรไฟล์ด้วย `$env:PROFIX_DB_NAME='profix_test_profile_photo'; php database/test_profile_photo.php` และการเข้าสู่ระบบเจ้าหน้าที่ด้วย `$env:PROFIX_DB_NAME='profix_test_staff_login'; php database/test_staff_login.php` ทั้งสองชุดสร้างฐานข้อมูลใหม่และลบฐานข้อมูลทดสอบเมื่อเสร็จ
- `migrate_component_stock.php` อัปเกรดให้ยืมหลายชุดและตัดสต็อกแยกของย่อย ดูวิธีใช้และทดสอบใน [COMPONENT_STOCK.md](COMPONENT_STOCK.md)
- `migrate_replacement_only.php` จำกัดให้ชดใช้ด้วยของทดแทนเท่านั้น แปลงรายการที่ยังไม่ปิดเป็นซื้อของมาคืนวัด และห้ามบันทึกยอดเงินในฐานข้อมูล หากพบประวัติรับเงินที่ปิดแล้วจะหยุดเพื่อไม่เขียนทับประวัติเดิม ใช้ `php database/migrate_replacement_only.php`
- `migrate_replacement_evidence.php` เพิ่มรูปของทดแทน หมายเหตุ และเวลาที่ผู้ยืมส่งหลักฐาน ใช้ `php database/migrate_replacement_evidence.php` สำหรับอัปเกรดเฉพาะส่วนนี้ หรือรัน `migrate.php` เพื่ออัปเกรดทั้งหมด ผู้ใช้เปิดแจ้งเตือนของชำรุดเพื่อส่งรูปได้ที่ `user/compensation.php` เจ้าหน้าที่ตรวจรูปและยืนยันรับของจริงที่ `staff/compensation.php` การส่งรูปยังไม่ปิดรายการหรือเพิ่มสต็อก ชดใช้ได้เฉพาะซื้อของมาคืนวัด และเจ้าหน้าที่ต้องตรวจรับก่อนชดใช้เสร็จ
- ทดสอบขั้นตอนส่งรูปด้วย `$env:PROFIX_DB_NAME='profix_test_replacement'; php database/test_replacement_evidence.php` ชุดทดสอบสร้างฐานข้อมูลใหม่และลบเฉพาะฐานข้อมูลทดสอบนั้นเมื่อเสร็จ

## ติดตั้งระบบใหม่บน XAMPP

1. เปิด Apache และ MySQL/MariaDB จาก XAMPP Control Panel
2. นำเข้า `database/profix.sql` ผ่าน phpMyAdmin เมนู Import หรือใช้ MySQL client:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -e "source C:/xampp/htdocs/profix/database/profix.sql"
   ```

   หากบัญชีฐานข้อมูลมีรหัสผ่าน ให้เพิ่ม `-p` แล้วกรอกรหัสผ่านเมื่อโปรแกรมถาม ห้ามใส่รหัสผ่านต่อท้าย `-p` เพราะอาจถูกบันทึกในประวัติคำสั่ง

3. สร้างผู้ดูแลคนแรก:

   ```powershell
   php database/bootstrap_admin.php --username=admin --phone=0800000000 --first-name="ผู้ดูแล" --last-name="ระบบ"
   ```

   โปรแกรมจะสุ่มรหัสผ่านที่เดายากและแสดงเพียงครั้งเดียว ให้บันทึกลง password manager แล้วเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบครั้งแรก

4. ตรวจสคีมาอีกครั้ง (คำสั่งนี้รันซ้ำได้):

   ```powershell
   php database/migrate.php
   ```

## อัปเกรดฐานข้อมูลที่มีข้อมูลอยู่แล้ว

อย่านำเข้า `profix.sql` ทับฐานข้อมูลที่ใช้งานอยู่ ให้สำรองข้อมูลก่อน แล้วรัน:

```powershell
php database/migrate.php
```

Migration จะเพิ่มตาราง/คอลัมน์ที่ขาด ทำ snapshot ของส่วนประกอบชุดสำหรับรายการเก่า และแปลงรหัสผ่านเจ้าหน้าที่แบบเก่าที่ตรวจพบให้เป็น password hash โดยไม่ลบประวัติเดิม

## กำหนดรหัสผ่านผู้ดูแลเอง

ไม่รองรับ `--password=...` เพื่อป้องกันรหัสผ่านปรากฏใน process list หรือ shell history ใช้วิธีใดวิธีหนึ่งแทน:

- ปล่อยให้สคริปต์สุ่มรหัสผ่าน (แนะนำ)
- ส่งรหัสผ่านความยาวอย่างน้อย 12 ตัวผ่าน standard input ด้วย `--password-stdin`
- กำหนด environment variable `PROFIX_BOOTSTRAP_PASSWORD` ชั่วคราว แล้วลบทันทีหลังใช้งาน

สคริปต์จะปฏิเสธการเขียนทับเมื่อพบชื่อผู้ใช้หรือเบอร์โทรศัพท์ซ้ำ

## ค่าการเชื่อมต่อ

ระบบรองรับ environment variables ต่อไปนี้ หากไม่กำหนดจะใช้ค่า XAMPP ในเครื่อง (`localhost`, `root`, รหัสผ่านว่าง และฐานข้อมูล `profix`):

- `PROFIX_DB_HOST`
- `PROFIX_DB_USER`
- `PROFIX_DB_PASSWORD`
- `PROFIX_DB_NAME`

สำหรับระบบจริงควรสร้างบัญชีฐานข้อมูลเฉพาะแอปและให้เฉพาะสิทธิ์ที่จำเป็น ส่วนบัญชีที่มีสิทธิ์ `CREATE`/`ALTER` ควรใช้เฉพาะตอนติดตั้งหรือ migration เท่านั้น
