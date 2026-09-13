-- Temple inventory: 29 items, 4 categories, 5 demo borrowers.
-- Stock quantities are simulated as requested. Existing records are preserved.
-- Demo phones: 0800000201-0800000205; PIN: 246810.
-- Select the target database before importing. Requires schema from profix.sql.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO categories (category_name) SELECT 'อุปกรณ์ครัวและภาชนะ' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ');

INSERT INTO categories (category_name) SELECT 'อุปกรณ์กลางแจ้ง โต๊ะ และเก้าอี้' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'อุปกรณ์กลางแจ้ง โต๊ะ และเก้าอี้');

INSERT INTO categories (category_name) SELECT 'อุปกรณ์งานบุญและศาสนพิธี' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี');

INSERT INTO categories (category_name) SELECT 'อุปกรณ์อำนวยความสะดวกและอื่น ๆ' WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'อุปกรณ์อำนวยความสะดวกและอื่น ๆ');

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'กระทะอะลูมิเนียมสองหู', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_1.jpg', 8, 8, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_1.jpg' OR item_name = 'กระทะอะลูมิเนียมสองหู');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 8, 8, 0, 8, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ทัพพีและกระบวยตักอาหาร', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_2.jpg', 30, 30, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_2.jpg' OR item_name = 'ทัพพีและกระบวยตักอาหาร');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 30, 30, 0, 30, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ถ้วยชามลายดอกไม้', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_3.jpg', 120, 120, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_3.jpg' OR item_name = 'ถ้วยชามลายดอกไม้');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 120, 120, 0, 120, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'จานขาวขอบลาย', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_4.jpg', 200, 200, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_4.jpg' OR item_name = 'จานขาวขอบลาย');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 200, 200, 0, 200, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์กลางแจ้ง โต๊ะ และเก้าอี้' ORDER BY category_id LIMIT 1), 'ผ้าใบและโครงเต็นท์', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_5.jpg', 5, 5, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_5.jpg' OR item_name = 'ผ้าใบและโครงเต็นท์');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 5, 5, 0, 5, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'ตาลปัตรลายภาพพระ', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_7.jpg', 3, 3, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_7.jpg' OR item_name = 'ตาลปัตรลายภาพพระ');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 3, 3, 0, 3, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'โต๊ะไม้ขนาดเล็ก', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_8.jpg', 12, 12, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_8.jpg' OR item_name = 'โต๊ะไม้ขนาดเล็ก');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 12, 12, 0, 12, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'ตาลปัตรลายธรรมจักร', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_9.jpg', 5, 5, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_9.jpg' OR item_name = 'ตาลปัตรลายธรรมจักร');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 5, 5, 0, 5, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'โต๊ะหมู่บูชาลายทอง', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_10.jpg', 2, 2, 1, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_10.jpg' OR item_name = 'โต๊ะหมู่บูชาลายทอง');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 2, 2, 0, 2, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'โต๊ะหมู่บูชาลายทอง', 7, 'ตัว', NULL, 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_10.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'โต๊ะหมู่บูชาลายทอง');

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'โต๊ะฐานรอง', 1, 'ตัว', NULL, 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_10.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'โต๊ะฐานรอง');

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'พระพุทธรูป', 1, 'องค์', NULL, 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_10.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'พระพุทธรูป');

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'เชิงเทียน', 2, 'อัน', NULL, 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_10.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'เชิงเทียน');

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'เสื่อและพรมปูรองนั่ง', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_12.jpg', 20, 20, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_12.jpg' OR item_name = 'เสื่อและพรมปูรองนั่ง');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 20, 20, 0, 20, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ช้อนตักอาหารด้ามยาว', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_14.jpg', 100, 100, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_14.jpg' OR item_name = 'ช้อนตักอาหารด้ามยาว');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 100, 100, 0, 100, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'จานลายดอกไม้สีชมพู', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_15.jpg', 80, 80, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_15.jpg' OR item_name = 'จานลายดอกไม้สีชมพู');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 80, 80, 0, 80, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'เขียงไม้กลม', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_16.jpg', 10, 10, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_16.jpg' OR item_name = 'เขียงไม้กลม');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 10, 10, 0, 10, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ครกหิน', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_17.jpg', 8, 8, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_17.jpg' OR item_name = 'ครกหิน');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 8, 8, 0, 8, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'เตาแก๊สขาเหล็ก', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_18.jpg', 6, 6, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_18.jpg' OR item_name = 'เตาแก๊สขาเหล็ก');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 6, 6, 0, 6, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'แก้วน้ำอะลูมิเนียมมีหู', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_28.jpg', 150, 150, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_28.jpg' OR item_name = 'แก้วน้ำอะลูมิเนียมมีหู');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 150, 150, 0, 150, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์อำนวยความสะดวกและอื่น ๆ' ORDER BY category_id LIMIT 1), 'ลังพลาสติกใส่ภาชนะ', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_22.jpg', 30, 30, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_22.jpg' OR item_name = 'ลังพลาสติกใส่ภาชนะ');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 30, 30, 0, 30, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ฝาหม้ออะลูมิเนียม', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_23.jpg', 20, 20, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_23.jpg' OR item_name = 'ฝาหม้ออะลูมิเนียม');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 20, 20, 0, 20, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'หม้ออะลูมิเนียมสองหู', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_24.jpg', 15, 15, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_24.jpg' OR item_name = 'หม้ออะลูมิเนียมสองหู');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 15, 15, 0, 15, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ถังอะลูมิเนียมหูหิ้ว', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_25.jpg', 10, 10, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_25.jpg' OR item_name = 'ถังอะลูมิเนียมหูหิ้ว');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 10, 10, 0, 10, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ตะหลิว', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_27.jpg', 20, 20, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_27.jpg' OR item_name = 'ตะหลิว');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 20, 20, 0, 20, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'กระโถนอะลูมิเนียม', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_29.jpg', 12, 12, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_29.jpg' OR item_name = 'กระโถนอะลูมิเนียม');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 12, 12, 0, 12, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ช้อนสเตนเลส', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_31.jpg', 200, 200, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_31.jpg' OR item_name = 'ช้อนสเตนเลส');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 200, 200, 0, 200, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ถาดเคลือบลายดอกไม้', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_32.jpg', 30, 30, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_32.jpg' OR item_name = 'ถาดเคลือบลายดอกไม้');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 30, 30, 0, 30, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'กะละมังอะลูมิเนียม', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_34.jpg', 15, 15, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_34.jpg' OR item_name = 'กะละมังอะลูมิเนียม');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 15, 15, 0, 15, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'แก้วน้ำใส', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_36.jpg', 120, 120, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_36.jpg' OR item_name = 'แก้วน้ำใส');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 120, 120, 0, 120, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์ครัวและภาชนะ' ORDER BY category_id LIMIT 1), 'ถ้วยชามสีขาว', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_38.jpg', 150, 150, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_38.jpg' OR item_name = 'ถ้วยชามสีขาว');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 150, 150, 0, 150, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'ชุดพานเงินและพานทอง', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_40.jpg', 10, 10, 1, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_40.jpg' OR item_name = 'ชุดพานเงินและพานทอง');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 10, 10, 0, 10, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'พานเงิน', 1, 'ใบ', NULL, 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_40.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'พานเงิน');

INSERT INTO item_components (parent_item_id, component_name, quantity_per_set, unit, image_url, is_available) SELECT i.item_id, 'พานทอง', 1, 'ใบ', '../uploads/temple_260907_40.jpg', 1 FROM items i WHERE i.image_url = '../uploads/temple_260907_40.jpg' AND i.is_set = 1 AND NOT EXISTS (SELECT 1 FROM item_components c WHERE c.parent_item_id = i.item_id AND c.component_name = 'พานทอง');

INSERT INTO items (category_id, item_name, description, image_url, total_quantity, available_quantity, is_set, is_active) SELECT (SELECT category_id FROM categories WHERE category_name = 'อุปกรณ์งานบุญและศาสนพิธี' ORDER BY category_id LIMIT 1), 'กระถางธูปสีทอง', 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ', '../uploads/temple_260907_41.jpg', 5, 5, 0, 1 WHERE NOT EXISTS (SELECT 1 FROM items WHERE image_url = '../uploads/temple_260907_41.jpg' OR item_name = 'กระถางธูปสีทอง');

SET @temple_item_inserted = ROW_COUNT();

INSERT INTO inventory_movements (item_id, movement_type, total_quantity_delta, quantity_delta, balance_before, balance_after, note) SELECT LAST_INSERT_ID(), 'initial_stock', 5, 5, 0, 5, 'อุปกรณ์ของวัด ระบุรายการจากภาพถ่าย จำนวนสต็อกเป็นข้อมูลจำลองสำหรับทดสอบระบบ' WHERE @temple_item_inserted = 1;

INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, '$2y$10$hHUxPtJt0YrOLWVDjcoLM.PF89Q70asYA6gzWX8A/fMv9eV22uzyG', 'ผู้ยืมตัวอย่าง 1', 'ทดสอบระบบ', '0800000201', '0800000201', 'ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง', 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = '0800000201' OR phone_number = '0800000201');

INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, '$2y$10$d490LjyBjxUsp4Gm1862luh7WyZiLFiVVmB/patq/25C6iyvzIrW.', 'ผู้ยืมตัวอย่าง 2', 'ทดสอบระบบ', '0800000202', '0800000202', 'ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง', 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = '0800000202' OR phone_number = '0800000202');

INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, '$2y$10$Jj.9vA73F6tf7usG5e8JCePiEKneoolX7Q5y1Z5scjyA1ZgflEoW6', 'ผู้ยืมตัวอย่าง 3', 'ทดสอบระบบ', '0800000203', '0800000203', 'ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง', 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = '0800000203' OR phone_number = '0800000203');

INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, '$2y$10$h6CfWbIVyfwEEuWO9sRJJ.0/ym1oq6N3HruzvnyXE5.qd2TITKl1O', 'ผู้ยืมตัวอย่าง 4', 'ทดสอบระบบ', '0800000204', '0800000204', 'ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง', 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = '0800000204' OR phone_number = '0800000204');

INSERT INTO users (username, password, borrower_pin_hash, first_name, last_name, phone_number, normalized_phone, address_detail, role, is_active) SELECT NULL, NULL, '$2y$10$edoOpsyy3fbgUoKOUhQmDOgAbNonJzSyEiEBZq.TPbOw6ksRz5mhe', 'ผู้ยืมตัวอย่าง 5', 'ทดสอบระบบ', '0800000205', '0800000205', 'ข้อมูลสมมติสำหรับทดสอบ ไม่ใช่ข้อมูลบุคคลจริง', 'user', 1 WHERE NOT EXISTS (SELECT 1 FROM users WHERE normalized_phone = '0800000205' OR phone_number = '0800000205');

COMMIT;
