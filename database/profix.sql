-- ProFix: temple equipment borrowing and return system
-- Fresh-install schema for MariaDB 10.4+ / MySQL 8.0+
-- Generated: 2026-09-05
--
-- This installer intentionally contains no user account or plaintext password.
-- After importing it, create the first administrator with:
--   php database/bootstrap_admin.php --username=admin --phone=0800000000
--
-- Existing installations must use `php database/migrate.php` instead of
-- re-importing this file so that current records are preserved.

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `profix`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `profix`;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) DEFAULT NULL,
  `password` VARCHAR(255) DEFAULT NULL,
  `borrower_pin_hash` VARCHAR(255) DEFAULT NULL,
  `pin_reset_hash` CHAR(64) DEFAULT NULL,
  `pin_reset_expires_at` DATETIME DEFAULT NULL,
  `auth_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `normalized_phone` VARCHAR(20) DEFAULT NULL,
  `address_detail` TEXT DEFAULT NULL,
  `profile_image_url` VARCHAR(255) DEFAULT NULL,
  `latitude` DECIMAL(10,8) DEFAULT NULL,
  `longitude` DECIMAL(11,8) DEFAULT NULL,
  `role` ENUM('user','staff','admin') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_normalized_phone` (`normalized_phone`),
  KEY `idx_user_name` (`first_name`,`last_name`),
  KEY `idx_users_role_active` (`role`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `categories` (`category_id`, `category_name`) VALUES
  (1, 'อุปกรณ์ครัวและภาชนะ'),
  (2, 'อุปกรณ์กลางแจ้ง โต๊ะ และเก้าอี้'),
  (3, 'อุปกรณ์งานบุญและศาสนพิธี'),
  (4, 'อุปกรณ์อำนวยความสะดวกและอื่น ๆ');

CREATE TABLE IF NOT EXISTS `items` (
  `item_id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT DEFAULT NULL,
  `item_name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `total_quantity` INT NOT NULL DEFAULT 0,
  `available_quantity` INT NOT NULL DEFAULT 0,
  `is_set` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_items_category` (`category_id`),
  KEY `idx_items_active_available` (`is_active`,`available_quantity`),
  CONSTRAINT `fk_items_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_components` (
  `component_id` INT NOT NULL AUTO_INCREMENT,
  `parent_item_id` INT NOT NULL,
  `component_name` VARCHAR(200) NOT NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 1,
  `stock_total` INT DEFAULT NULL,
  `stock_available` INT DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `quantity_per_set` INT NOT NULL DEFAULT 1,
  `unit` VARCHAR(50) NOT NULL DEFAULT 'ชิ้น',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`component_id`),
  KEY `idx_parent_item_id` (`parent_item_id`),
  CONSTRAINT `fk_item_components_parent`
    FOREIGN KEY (`parent_item_id`) REFERENCES `items` (`item_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `borrow_requests` (
  `request_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `borrow_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `requested_pickup_at` DATETIME DEFAULT NULL,
  `expected_return_date` DATETIME DEFAULT NULL,
  `actual_return_date` DATETIME DEFAULT NULL,
  `return_requested_at` DATETIME DEFAULT NULL,
  `status` ENUM(
    'pending_approval','approved','borrowed','return_requested',
    'returned','partially_damaged','rejected','cancelled'
  ) NOT NULL DEFAULT 'pending_approval',
  `approved_at` DATETIME DEFAULT NULL,
  `handed_over_at` DATETIME DEFAULT NULL,
  `approved_by_user_id` INT DEFAULT NULL,
  `handed_over_by_user_id` INT DEFAULT NULL,
  `decision_note` TEXT DEFAULT NULL,
  `allocation_version` INT NOT NULL DEFAULT 0,
  `accepted_allocation_version` INT DEFAULT NULL,
  `allocation_accepted_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `reservation_id` INT DEFAULT NULL,
  `settlement_status` ENUM('none','pending','resolved') NOT NULL DEFAULT 'none',
  `received_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_borrow_status_due` (`status`,`expected_return_date`),
  KEY `idx_borrow_user_status` (`user_id`,`status`),
  KEY `idx_borrow_created` (`created_at`),
  KEY `idx_borrow_approver` (`approved_by_user_id`),
  KEY `idx_borrow_handover` (`handed_over_by_user_id`),
  KEY `idx_borrow_receiver` (`received_by_user_id`),
  KEY `idx_borrow_reservation` (`reservation_id`),
  CONSTRAINT `fk_borrow_request_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_borrow_request_approver`
    FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_borrow_request_handover`
    FOREIGN KEY (`handed_over_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_borrow_request_receiver`
    FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `borrow_items` (
  `borrow_item_id` INT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `quantity_borrowed` INT NOT NULL DEFAULT 1,
  `quantity_returned` INT NOT NULL DEFAULT 0,
  `quantity_requested` INT DEFAULT NULL,
  `quantity_handed_over` INT DEFAULT NULL,
  PRIMARY KEY (`borrow_item_id`),
  KEY `idx_borrow_items_request` (`request_id`),
  KEY `idx_borrow_items_item` (`item_id`),
  CONSTRAINT `fk_borrow_items_request`
    FOREIGN KEY (`request_id`) REFERENCES `borrow_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_borrow_items_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `borrow_item_exclusions` (
  `exclusion_id` INT NOT NULL AUTO_INCREMENT,
  `borrow_item_id` INT NOT NULL,
  `component_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`exclusion_id`),
  UNIQUE KEY `uq_borrow_component` (`borrow_item_id`,`component_id`),
  KEY `idx_exclusion_component` (`component_id`),
  CONSTRAINT `fk_exclusion_borrow_item`
    FOREIGN KEY (`borrow_item_id`) REFERENCES `borrow_items` (`borrow_item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exclusion_component`
    FOREIGN KEY (`component_id`) REFERENCES `item_components` (`component_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `borrow_item_components` (
  `borrow_item_component_id` BIGINT NOT NULL AUTO_INCREMENT,
  `borrow_item_id` INT NOT NULL,
  `source_component_id` INT DEFAULT NULL,
  `component_name` VARCHAR(200) NOT NULL,
  `quantity_per_set` INT NOT NULL DEFAULT 1,
  `quantity_borrowed` INT DEFAULT NULL,
  `unit` VARCHAR(50) NOT NULL DEFAULT 'ชิ้น',
  `is_included` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`borrow_item_component_id`),
  UNIQUE KEY `uq_borrow_component_snapshot` (`borrow_item_id`,`source_component_id`),
  KEY `idx_borrow_component_source` (`source_component_id`),
  CONSTRAINT `fk_snapshot_borrow_item`
    FOREIGN KEY (`borrow_item_id`) REFERENCES `borrow_items` (`borrow_item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_snapshot_source_component`
    FOREIGN KEY (`source_component_id`) REFERENCES `item_components` (`component_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservations` (
  `reservation_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','cancelled','fulfilled','expired')
    NOT NULL DEFAULT 'pending',
  `reviewed_by_user_id` INT DEFAULT NULL,
  `review_note` TEXT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `borrow_request_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reservation_id`),
  KEY `idx_reservation_dates` (`item_id`,`start_date`,`end_date`),
  KEY `idx_reservation_user` (`user_id`,`status`),
  KEY `idx_reservation_reviewer` (`reviewed_by_user_id`),
  KEY `idx_reservation_request` (`borrow_request_id`),
  CONSTRAINT `fk_reservation_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservation_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  CONSTRAINT `fk_reservation_reviewer`
    FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reservation_borrow_request`
    FOREIGN KEY (`borrow_request_id`) REFERENCES `borrow_requests` (`request_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_batches` (
  `return_batch_id` BIGINT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `borrow_item_id` INT NOT NULL,
  `quantity_received` INT NOT NULL,
  `good_quantity` INT NOT NULL DEFAULT 0,
  `withheld_quantity` INT NOT NULL DEFAULT 0,
  `stock_resolution` ENUM('none','pending','restored','retired') NOT NULL DEFAULT 'none',
  `inspected_by_user_id` INT DEFAULT NULL,
  `inspected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`return_batch_id`),
  KEY `idx_return_batch_request` (`request_id`,`borrow_item_id`),
  KEY `idx_return_batch_resolution` (`stock_resolution`),
  KEY `idx_return_batch_staff` (`inspected_by_user_id`),
  CONSTRAINT `fk_return_batch_request`
    FOREIGN KEY (`request_id`) REFERENCES `borrow_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_batch_borrow_item`
    FOREIGN KEY (`borrow_item_id`) REFERENCES `borrow_items` (`borrow_item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_batch_staff`
    FOREIGN KEY (`inspected_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_inspections` (
  `inspection_id` INT NOT NULL AUTO_INCREMENT,
  `return_batch_id` BIGINT DEFAULT NULL,
  `borrow_item_id` INT NOT NULL,
  `borrow_item_component_id` BIGINT DEFAULT NULL,
  `quantity_received` INT NOT NULL DEFAULT 0,
  `component_id` INT DEFAULT NULL,
  `damaged_quantity` INT NOT NULL DEFAULT 0,
  `lost_quantity` INT NOT NULL DEFAULT 0,
  `damage_description` TEXT DEFAULT NULL,
  `damage_image_url` VARCHAR(500) DEFAULT NULL,
  `replacement_image_url` VARCHAR(255) DEFAULT NULL,
  `replacement_note` TEXT DEFAULT NULL,
  `replacement_submitted_at` DATETIME DEFAULT NULL,
  `action_required` ENUM('none','buy_replacement') NOT NULL DEFAULT 'none',
  `fine_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT `chk_replacement_no_fine` CHECK (`fine_amount` = 0),
  `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `inspected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`inspection_id`),
  KEY `idx_inspection_batch` (`return_batch_id`),
  KEY `idx_inspection_borrow_item` (`borrow_item_id`),
  KEY `idx_inspection_component_snapshot` (`borrow_item_component_id`),
  KEY `idx_inspection_component` (`component_id`),
  KEY `idx_inspection_resolution` (`is_resolved`,`action_required`),
  CONSTRAINT `fk_inspection_batch`
    FOREIGN KEY (`return_batch_id`) REFERENCES `return_batches` (`return_batch_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspection_borrow_item`
    FOREIGN KEY (`borrow_item_id`) REFERENCES `borrow_items` (`borrow_item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspection_snapshot`
    FOREIGN KEY (`borrow_item_component_id`) REFERENCES `borrow_item_components` (`borrow_item_component_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_component`
    FOREIGN KEY (`component_id`) REFERENCES `item_components` (`component_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `damage_compensations` (
  `compensation_id` INT NOT NULL AUTO_INCREMENT,
  `inspection_id` INT NOT NULL,
  `method` ENUM('replacement') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT `chk_replacement_no_amount` CHECK (`amount` = 0),
  `quantity_settled` INT NOT NULL DEFAULT 0,
  `received_date` DATETIME NOT NULL,
  `note` TEXT DEFAULT NULL,
  `received_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`compensation_id`),
  UNIQUE KEY `uq_compensation_inspection` (`inspection_id`),
  KEY `idx_compensation_staff` (`received_by_user_id`),
  CONSTRAINT `fk_compensation_inspection`
    FOREIGN KEY (`inspection_id`) REFERENCES `return_inspections` (`inspection_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compensation_staff`
    FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_records` (
  `maintenance_id` INT NOT NULL AUTO_INCREMENT,
  `item_id` INT NOT NULL,
  `component_id` INT DEFAULT NULL,
  `inspection_id` INT DEFAULT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `description` TEXT NOT NULL,
  `cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('waiting','repairing','completed','retired') NOT NULL DEFAULT 'waiting',
  `stock_effect_applied` TINYINT(1) NOT NULL DEFAULT 0,
  `reported_by_user_id` INT DEFAULT NULL,
  `updated_by_user_id` INT DEFAULT NULL,
  `reported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`maintenance_id`),
  KEY `idx_maintenance_status` (`status`,`item_id`),
  KEY `idx_maintenance_component` (`component_id`),
  KEY `idx_maintenance_inspection` (`inspection_id`),
  KEY `idx_maintenance_reporter` (`reported_by_user_id`),
  KEY `idx_maintenance_updater` (`updated_by_user_id`),
  CONSTRAINT `fk_maintenance_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  CONSTRAINT `fk_maintenance_component`
    FOREIGN KEY (`component_id`) REFERENCES `item_components` (`component_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_maintenance_inspection`
    FOREIGN KEY (`inspection_id`) REFERENCES `return_inspections` (`inspection_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_maintenance_reporter`
    FOREIGN KEY (`reported_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_maintenance_updater`
    FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `movement_id` BIGINT NOT NULL AUTO_INCREMENT,
  `item_id` INT NOT NULL,
  `request_id` INT DEFAULT NULL,
  `borrow_item_id` INT DEFAULT NULL,
  `return_batch_id` BIGINT DEFAULT NULL,
  `maintenance_id` INT DEFAULT NULL,
  `movement_type` VARCHAR(40) NOT NULL,
  `total_quantity_delta` INT NOT NULL DEFAULT 0,
  `quantity_delta` INT NOT NULL,
  `balance_before` INT NOT NULL,
  `balance_after` INT NOT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `created_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `idx_inventory_item_created` (`item_id`,`created_at`),
  KEY `idx_inventory_request` (`request_id`),
  KEY `idx_inventory_borrow_item` (`borrow_item_id`),
  KEY `idx_inventory_batch` (`return_batch_id`),
  KEY `idx_inventory_maintenance` (`maintenance_id`),
  KEY `idx_inventory_actor` (`created_by_user_id`),
  CONSTRAINT `fk_inventory_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  CONSTRAINT `fk_inventory_request`
    FOREIGN KEY (`request_id`) REFERENCES `borrow_requests` (`request_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_borrow_item`
    FOREIGN KEY (`borrow_item_id`) REFERENCES `borrow_items` (`borrow_item_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_return_batch`
    FOREIGN KEY (`return_batch_id`) REFERENCES `return_batches` (`return_batch_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_maintenance`
    FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_records` (`maintenance_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_actor`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `link_url` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_notification_user` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notification_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `log_id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `detail` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `borrow_request_events` (
  `event_id` BIGINT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `from_status` VARCHAR(40) DEFAULT NULL,
  `to_status` VARCHAR(40) NOT NULL,
  `actor_user_id` INT DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_request_event` (`request_id`,`created_at`),
  KEY `idx_request_event_actor` (`actor_user_id`),
  CONSTRAINT `fk_request_event_request`
    FOREIGN KEY (`request_id`) REFERENCES `borrow_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_request_event_actor`
    FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_key` CHAR(64) NOT NULL,
  `failure_count` INT NOT NULL DEFAULT 0,
  `first_failed_at` DATETIME NOT NULL,
  `last_failed_at` DATETIME NOT NULL,
  `locked_until` DATETIME DEFAULT NULL,
  PRIMARY KEY (`attempt_key`),
  KEY `idx_login_attempt_expiry` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(80) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`)
VALUES ('2026-09-04-complete-workflow');

CREATE TABLE IF NOT EXISTS inventory_discrepancies (
  discrepancy_id BIGINT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL,
  request_id INT NOT NULL, quantity INT NOT NULL, note VARCHAR(500) NOT NULL,
  created_by_user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolution ENUM('pending','found','written_off') NOT NULL DEFAULT 'pending',
  resolved_by_user_id INT NULL, resolved_at DATETIME NULL, resolution_note VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
