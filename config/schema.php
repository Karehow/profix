<?php

/**
 * Database migrations for the borrowing system.
 *
 * Run this file through database/migrate.php during installation or deployment.
 * Web requests must not need CREATE/ALTER privileges.
 */

function schema_table_exists(mysqli $conn, string $table): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int) $count === 1;
}

function migrate_replacement_only(mysqli $conn): void
{
    // Never relabel an old financial receipt as a physical replacement.
    $receipts = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM damage_compensations WHERE method <> 'replacement' OR amount <> 0"));
    $closed = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM return_inspections WHERE is_resolved=1 AND (action_required='pay_fine' OR fine_amount<>0)"));
    if ((int) $receipts[0] > 0 || (int) $closed[0] > 0) {
        throw new RuntimeException('พบประวัติชดใช้ด้วยเงิน กรุณาเก็บประวัติเดิมแยกก่อนเปลี่ยนเป็นรับของทดแทนเท่านั้น');
    }
    schema_exec($conn, "UPDATE return_inspections SET action_required=CASE WHEN damaged_quantity+lost_quantity>0 THEN 'buy_replacement' ELSE 'none' END, fine_amount=0 WHERE is_resolved=0");
    schema_exec($conn, "ALTER TABLE return_inspections MODIFY action_required ENUM('none','buy_replacement') NOT NULL DEFAULT 'none'");
    schema_exec($conn, "ALTER TABLE damage_compensations MODIFY method ENUM('replacement') NOT NULL");
    foreach (['return_inspections' => ['chk_replacement_no_fine', 'fine_amount'], 'damage_compensations' => ['chk_replacement_no_amount', 'amount']] as $table => [$name, $column]) {
        $exists = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND CONSTRAINT_NAME='$name'"));
        if (!(int) $exists[0]) schema_exec($conn, "ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK (`$column` = 0)");
    }
}

function migrate_profile_photo(mysqli $conn): void
{
    schema_add_column($conn, 'users', 'profile_image_url', 'VARCHAR(255) NULL');
}

function migrate_replacement_evidence(mysqli $conn): void
{
    schema_add_column($conn, 'return_inspections', 'replacement_image_url', 'VARCHAR(255) NULL');
    schema_add_column($conn, 'return_inspections', 'replacement_note', 'TEXT NULL');
    schema_add_column($conn, 'return_inspections', 'replacement_submitted_at', 'DATETIME NULL');
}

function migrate_borrow_allocation(mysqli $conn): void
{
    schema_add_column($conn, 'borrow_items', 'quantity_requested', 'INT NULL');
    schema_add_column($conn, 'borrow_items', 'quantity_handed_over', 'INT NULL');
    schema_add_column($conn, 'borrow_requests', 'allocation_version', 'INT NOT NULL DEFAULT 0');
    schema_add_column($conn, 'borrow_requests', 'accepted_allocation_version', 'INT NULL');
    schema_add_column($conn, 'borrow_requests', 'allocation_accepted_at', 'DATETIME NULL');
    schema_exec($conn, 'UPDATE borrow_items SET quantity_requested=quantity_borrowed WHERE quantity_requested IS NULL');
    schema_exec($conn, "UPDATE borrow_items bi JOIN borrow_requests br ON br.request_id=bi.request_id SET bi.quantity_handed_over=bi.quantity_borrowed WHERE bi.quantity_handed_over IS NULL AND br.status IN ('borrowed','return_requested','returned','partially_damaged')");
    schema_exec($conn, "CREATE TABLE IF NOT EXISTS inventory_discrepancies (
        discrepancy_id BIGINT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL,
        request_id INT NOT NULL, quantity INT NOT NULL, note VARCHAR(500) NOT NULL,
        created_by_user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolution ENUM('pending','found','written_off') NOT NULL DEFAULT 'pending',
        resolved_by_user_id INT NULL, resolved_at DATETIME NULL, resolution_note VARCHAR(500) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function migrate_component_quantities(mysqli $conn): void
{
    schema_add_column($conn, 'borrow_item_components', 'quantity_borrowed', 'INT DEFAULT NULL AFTER quantity_per_set');
}

function migrate_component_stock(mysqli $conn): void
{
    schema_add_column($conn, 'item_components', 'stock_total', 'INT DEFAULT NULL');
    schema_add_column($conn, 'item_components', 'stock_available', 'INT DEFAULT NULL');
    // Release unselected pieces previously held by the whole-set stock model.
    schema_exec($conn, "UPDATE item_components c JOIN items i ON i.item_id=c.parent_item_id
        SET c.stock_total = i.total_quantity*c.quantity_per_set,
            c.stock_available = LEAST(i.total_quantity*c.quantity_per_set, GREATEST(0,
                i.available_quantity*c.quantity_per_set + COALESCE((
                    SELECT SUM((bi.quantity_borrowed-bi.quantity_returned)*c.quantity_per_set -
                        CASE WHEN bc.is_included=0 THEN 0
                             WHEN bc.quantity_borrowed IS NOT NULL THEN IF(bi.quantity_returned=0,bc.quantity_borrowed,0)
                             ELSE bc.quantity_per_set*(bi.quantity_borrowed-bi.quantity_returned) END)
                    FROM borrow_items bi JOIN borrow_requests br ON br.request_id=bi.request_id
                    JOIN borrow_item_components bc ON bc.borrow_item_id=bi.borrow_item_id AND bc.source_component_id=c.component_id
                    WHERE bi.item_id=i.item_id AND br.status IN ('approved','borrowed','return_requested')
                ),0) + COALESCE((
                    SELECT SUM(rb.withheld_quantity*c.quantity_per_set - COALESCE((
                        SELECT SUM((ri.damaged_quantity+ri.lost_quantity)*IF(ri.component_id IS NULL,c.quantity_per_set,1))
                        FROM return_inspections ri WHERE ri.return_batch_id=rb.return_batch_id
                            AND (ri.component_id=c.component_id OR ri.component_id IS NULL)
                    ),0)) FROM return_batches rb JOIN borrow_items bi ON bi.borrow_item_id=rb.borrow_item_id
                    WHERE bi.item_id=i.item_id AND rb.stock_resolution='pending'
                ),0)))
        WHERE c.stock_total IS NULL OR c.stock_available IS NULL");
    schema_exec($conn, "UPDATE items i JOIN (
        SELECT parent_item_id, MIN(FLOOR(stock_total/quantity_per_set)) AS total_sets,
            MIN(IF(is_available=1,FLOOR(stock_available/quantity_per_set),0)) AS available_sets
        FROM item_components GROUP BY parent_item_id
    ) s ON s.parent_item_id=i.item_id SET i.total_quantity=s.total_sets, i.available_quantity=s.available_sets
    WHERE i.is_set=1");
}

function schema_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int) $count === 1;
}

function schema_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $table, $index);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int) $count > 0;
}

function schema_constraint_exists(mysqli $conn, string $table, string $constraint): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $table, $constraint);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int) $count > 0;
}

function schema_exec(mysqli $conn, string $sql): void
{
    if (!mysqli_query($conn, $sql)) {
        throw new RuntimeException(mysqli_error($conn) . "\nSQL: " . $sql);
    }
}

function schema_add_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!schema_column_exists($conn, $table, $column)) {
        schema_exec($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function schema_add_index(mysqli $conn, string $table, string $index, string $columns, bool $unique = false): void
{
    $wantedColumns = array_map(
        static fn (string $column): string => strtolower(trim($column, " `\t\n\r\0\x0B")),
        preg_split('/\s*,\s*/', $columns) ?: []
    );
    $stmt = mysqli_prepare($conn, "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $definitions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $name = (string) $row['INDEX_NAME'];
        $definitions[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
        $definitions[$name]['columns'][] = strtolower((string) $row['COLUMN_NAME']);
    }
    mysqli_stmt_close($stmt);

    if (isset($definitions[$index])) {
        $definition = $definitions[$index];
        if ($definition['columns'] !== $wantedColumns || ($unique && !$definition['unique'])) {
            throw new RuntimeException("Index `$index` on `$table` exists with an incompatible definition.");
        }
        return;
    }

    // A differently named index over the same columns is already sufficient.
    // This matters when migrating SQL dumps that use an older index name.
    foreach ($definitions as $definition) {
        if ($definition['columns'] === $wantedColumns && (!$unique || $definition['unique'])) {
            return;
        }
    }

    schema_exec($conn, 'ALTER TABLE `' . $table . '` ADD ' . ($unique ? 'UNIQUE ' : '') . 'INDEX `' . $index . '` (' . $columns . ')');
}

function schema_enum_has_values(array $column, array $requiredValues): bool
{
    $definition = (string) ($column['Type'] ?? '');
    foreach ($requiredValues as $value) {
        if (strpos($definition, "'" . $value . "'") === false) {
            return false;
        }
    }
    return true;
}

function schema_normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '66') && strlen($digits) >= 11) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits !== '' ? $digits : null;
}

/**
 * Rebuild normalized phones with the same rule used by the application.
 * Values are checked before writing so a duplicate never leaves a half-migrated
 * user table. NULL is intentional for empty legacy phone numbers because a
 * unique index permits more than one unknown value.
 */
function schema_sync_normalized_phones(mysqli $conn): void
{
    mysqli_begin_transaction($conn);
    try {
        $result = mysqli_query($conn, 'SELECT user_id, phone_number FROM users ORDER BY user_id FOR UPDATE');
        if ($result === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        $phones = [];
        $owners = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $userId = (int) $row['user_id'];
            $normalized = schema_normalize_phone((string) ($row['phone_number'] ?? ''));
            if ($normalized !== null && isset($owners[$normalized])) {
                throw new RuntimeException(
                    'Cannot add a unique phone index: users #' . $owners[$normalized] . ' and #' . $userId . ' have the same normalized phone number.'
                );
            }
            if ($normalized !== null) {
                $owners[$normalized] = $userId;
            }
            $phones[$userId] = $normalized;
        }

        schema_exec($conn, 'UPDATE users SET normalized_phone = NULL');
        $stmt = mysqli_prepare($conn, 'UPDATE users SET normalized_phone = ? WHERE user_id = ?');
        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conn));
        }
        foreach ($phones as $userId => $normalized) {
            mysqli_stmt_bind_param($stmt, 'si', $normalized, $userId);
            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }
        }
        mysqli_stmt_close($stmt);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function migrate_component_availability(mysqli $conn): void
{
    schema_add_column($conn, 'item_components', 'is_available', 'TINYINT(1) NOT NULL DEFAULT 1');
}

function migrate_pin_reset(mysqli $conn): void
{
    schema_add_column($conn, 'users', 'pin_reset_hash', 'CHAR(64) NULL');
    schema_add_column($conn, 'users', 'pin_reset_expires_at', 'DATETIME NULL');
    schema_add_column($conn, 'users', 'auth_version', 'INT UNSIGNED NOT NULL DEFAULT 0');
}

function run_application_migrations(mysqli $conn): void
{
    $requiredCoreTables = [
        'users',
        'categories',
        'items',
        'item_components',
        'borrow_requests',
        'borrow_items',
        'return_inspections',
        'damage_compensations',
    ];
    $missingCoreTables = array_values(array_filter(
        $requiredCoreTables,
        static fn (string $table): bool => !schema_table_exists($conn, $table)
    ));
    if ($missingCoreTables) {
        throw new RuntimeException(
            'Base schema is incomplete (missing: ' . implode(', ', $missingCoreTables) . '). Import database/profix.sql before running migrations.'
        );
    }

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(80) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS borrow_item_exclusions (
        exclusion_id INT AUTO_INCREMENT PRIMARY KEY,
        borrow_item_id INT NOT NULL,
        component_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_borrow_component (borrow_item_id, component_id),
        INDEX idx_exclusion_component (component_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        link_url VARCHAR(500) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification_user (user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS audit_logs (
        log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        detail TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS reservations (
        reservation_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        item_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        start_date DATETIME NOT NULL,
        end_date DATETIME NOT NULL,
        purpose VARCHAR(255) NULL,
        status ENUM('pending','approved','rejected','cancelled','fulfilled','expired') NOT NULL DEFAULT 'pending',
        reviewed_by_user_id INT NULL,
        review_note TEXT NULL,
        reviewed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        borrow_request_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reservation_dates (item_id, start_date, end_date),
        INDEX idx_reservation_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS maintenance_records (
        maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        component_id INT NULL,
        inspection_id INT NULL,
        quantity INT NOT NULL DEFAULT 1,
        description TEXT NOT NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('waiting','repairing','completed','retired') NOT NULL DEFAULT 'waiting',
        stock_effect_applied TINYINT(1) NOT NULL DEFAULT 0,
        reported_by_user_id INT NULL,
        updated_by_user_id INT NULL,
        reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_maintenance_status (status, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (schema_table_exists($conn, 'borrow_requests')) {
        $status = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM borrow_requests LIKE 'status'"));
        $borrowStatuses = ['pending_approval', 'approved', 'borrowed', 'return_requested', 'returned', 'partially_damaged', 'rejected', 'cancelled'];
        if ($status && !schema_enum_has_values($status, $borrowStatuses)) {
            schema_exec($conn, "ALTER TABLE borrow_requests MODIFY status ENUM('pending_approval','approved','borrowed','return_requested','returned','partially_damaged','rejected','cancelled') NOT NULL DEFAULT 'pending_approval'");
        }
        schema_add_column($conn, 'borrow_requests', 'requested_pickup_at', 'DATETIME NULL AFTER borrow_date');
        schema_add_column($conn, 'borrow_requests', 'approved_at', 'DATETIME NULL AFTER status');
        schema_add_column($conn, 'borrow_requests', 'handed_over_at', 'DATETIME NULL AFTER approved_at');
        schema_add_column($conn, 'borrow_requests', 'handed_over_by_user_id', 'INT NULL AFTER approved_by_user_id');
        schema_add_column($conn, 'borrow_requests', 'decision_note', 'TEXT NULL AFTER handed_over_by_user_id');
        schema_add_column($conn, 'borrow_requests', 'cancelled_at', 'DATETIME NULL AFTER decision_note');
        schema_add_column($conn, 'borrow_requests', 'reservation_id', 'INT NULL AFTER cancelled_at');
        schema_add_column($conn, 'borrow_requests', 'settlement_status', "ENUM('none','pending','resolved') NOT NULL DEFAULT 'none' AFTER reservation_id");
        schema_add_column($conn, 'borrow_requests', 'return_requested_at', 'DATETIME NULL AFTER actual_return_date');
        schema_add_index($conn, 'borrow_requests', 'idx_borrow_status_due', '`status`, `expected_return_date`');
        schema_add_index($conn, 'borrow_requests', 'idx_borrow_user_status', '`user_id`, `status`');
        schema_add_index($conn, 'borrow_requests', 'idx_borrow_created', '`created_at`');
        schema_add_index($conn, 'borrow_requests', 'idx_borrow_reservation', '`reservation_id`');
    }

    if (schema_table_exists($conn, 'return_inspections')) {
        schema_add_column($conn, 'return_inspections', 'quantity_received', 'INT NOT NULL DEFAULT 0 AFTER borrow_item_id');
        schema_add_column($conn, 'return_inspections', 'return_batch_id', 'BIGINT NULL AFTER inspection_id');
        schema_add_column($conn, 'return_inspections', 'borrow_item_component_id', 'BIGINT NULL AFTER borrow_item_id');
        schema_add_index($conn, 'return_inspections', 'idx_inspection_batch', '`return_batch_id`');
        schema_add_index($conn, 'return_inspections', 'idx_inspection_component_snapshot', '`borrow_item_component_id`');
    }

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS return_batches (
        return_batch_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        borrow_item_id INT NOT NULL,
        quantity_received INT NOT NULL,
        good_quantity INT NOT NULL DEFAULT 0,
        withheld_quantity INT NOT NULL DEFAULT 0,
        stock_resolution ENUM('none','pending','restored','retired') NOT NULL DEFAULT 'none',
        inspected_by_user_id INT NULL,
        inspected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX idx_return_batch_request (request_id, borrow_item_id),
        INDEX idx_return_batch_resolution (stock_resolution)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS borrow_item_components (
        borrow_item_component_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        borrow_item_id INT NOT NULL,
        source_component_id INT NULL,
        component_name VARCHAR(200) NOT NULL,
        quantity_per_set INT NOT NULL DEFAULT 1,
        unit VARCHAR(50) NOT NULL DEFAULT 'ชิ้น',
        is_included TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_borrow_component_snapshot (borrow_item_id, source_component_id),
        INDEX idx_borrow_component_source (source_component_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS inventory_movements (
        movement_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        request_id INT NULL,
        borrow_item_id INT NULL,
        return_batch_id BIGINT NULL,
        maintenance_id INT NULL,
        movement_type VARCHAR(40) NOT NULL,
        total_quantity_delta INT NOT NULL DEFAULT 0,
        quantity_delta INT NOT NULL,
        balance_before INT NOT NULL,
        balance_after INT NOT NULL,
        note VARCHAR(500) NULL,
        created_by_user_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inventory_item_created (item_id, created_at),
        INDEX idx_inventory_request (request_id),
        INDEX idx_inventory_batch (return_batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (schema_table_exists($conn, 'inventory_movements')) {
        schema_add_column($conn, 'inventory_movements', 'total_quantity_delta', 'INT NOT NULL DEFAULT 0 AFTER movement_type');
    }

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS borrow_request_events (
        event_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        from_status VARCHAR(40) NULL,
        to_status VARCHAR(40) NOT NULL,
        actor_user_id INT NULL,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_request_event (request_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    schema_exec($conn, "CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_key CHAR(64) NOT NULL PRIMARY KEY,
        failure_count INT NOT NULL DEFAULT 0,
        first_failed_at DATETIME NOT NULL,
        last_failed_at DATETIME NOT NULL,
        locked_until DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (schema_table_exists($conn, 'users')) {
        schema_add_column($conn, 'users', 'normalized_phone', 'VARCHAR(20) NULL AFTER phone_number');
        schema_add_column($conn, 'users', 'borrower_pin_hash', 'VARCHAR(255) NULL AFTER password');
        schema_add_column($conn, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
        schema_sync_normalized_phones($conn);
        schema_add_index($conn, 'users', 'uq_users_normalized_phone', '`normalized_phone`', true);
    }

    if (schema_table_exists($conn, 'items')) {
        schema_add_column($conn, 'items', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_set');
        schema_add_index($conn, 'items', 'idx_items_active_available', '`is_active`, `available_quantity`');
    }

    if (schema_table_exists($conn, 'reservations')) {
        $reservationStatus = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM reservations LIKE 'status'"));
        $reservationStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'fulfilled', 'expired'];
        if ($reservationStatus && !schema_enum_has_values($reservationStatus, $reservationStatuses)) {
            schema_exec($conn, "ALTER TABLE reservations MODIFY status ENUM('pending','approved','rejected','cancelled','fulfilled','expired') NOT NULL DEFAULT 'pending'");
        }
        schema_add_column($conn, 'reservations', 'reviewed_at', 'DATETIME NULL AFTER review_note');
        schema_add_column($conn, 'reservations', 'cancelled_at', 'DATETIME NULL AFTER reviewed_at');
        schema_add_column($conn, 'reservations', 'borrow_request_id', 'INT NULL AFTER cancelled_at');
        schema_add_index($conn, 'reservations', 'idx_reservation_borrow_request', '`borrow_request_id`');
    }

    if (schema_table_exists($conn, 'maintenance_records')) {
        schema_add_column($conn, 'maintenance_records', 'inspection_id', 'INT NULL AFTER component_id');
        schema_add_column($conn, 'maintenance_records', 'quantity', 'INT NOT NULL DEFAULT 1 AFTER inspection_id');
        schema_add_column($conn, 'maintenance_records', 'stock_effect_applied', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    }

    if (schema_table_exists($conn, 'damage_compensations')) {
        schema_add_column($conn, 'damage_compensations', 'quantity_settled', 'INT NOT NULL DEFAULT 0 AFTER amount');
    }

    // Older screens could save a compensation action even when no damaged or
    // lost quantity was recorded. Such a row has no issue to settle and is not
    // creatable by the current workflow, so close it without deleting its
    // descriptive/image history.
    schema_exec($conn, "UPDATE return_inspections
        SET action_required = 'none', fine_amount = 0.00, is_resolved = 1
        WHERE damaged_quantity = 0
          AND lost_quantity = 0
          AND (action_required <> 'none' OR fine_amount <> 0 OR is_resolved = 0)");

    // Derive settlement state for historical inspections created before the
    // request-level settlement column existed.
    schema_exec($conn, "UPDATE borrow_requests br
        SET br.settlement_status = 'pending'
        WHERE EXISTS (
            SELECT 1
            FROM borrow_items bi
            JOIN return_inspections ri ON ri.borrow_item_id = bi.borrow_item_id
            WHERE bi.request_id = br.request_id
              AND ri.is_resolved = 0
              AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
        )");
    schema_exec($conn, "UPDATE borrow_requests br
        SET br.settlement_status = 'resolved'
        WHERE br.settlement_status = 'none'
          AND EXISTS (
              SELECT 1
              FROM borrow_items bi
              JOIN return_inspections ri ON ri.borrow_item_id = bi.borrow_item_id
              WHERE bi.request_id = br.request_id
                AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
          )
          AND NOT EXISTS (
              SELECT 1
              FROM borrow_items bi
              JOIN return_inspections ri ON ri.borrow_item_id = bi.borrow_item_id
              WHERE bi.request_id = br.request_id
                AND ri.is_resolved = 0
                AND (ri.damaged_quantity > 0 OR ri.lost_quantity > 0)
          )");

    // Snapshot components for historical borrow rows created by older versions.
    if (schema_table_exists($conn, 'borrow_items') && schema_table_exists($conn, 'item_components')) {
        schema_exec($conn, "INSERT IGNORE INTO borrow_item_components
            (borrow_item_id, source_component_id, component_name, quantity_per_set, unit, is_included)
            SELECT bi.borrow_item_id, ic.component_id, ic.component_name, ic.quantity_per_set, COALESCE(ic.unit, 'ชิ้น'),
                   IF(bie.exclusion_id IS NULL, 1, 0)
            FROM borrow_items bi
            JOIN items i ON i.item_id = bi.item_id AND i.is_set = 1
            JOIN item_components ic ON ic.parent_item_id = bi.item_id
            LEFT JOIN borrow_item_exclusions bie ON bie.borrow_item_id = bi.borrow_item_id AND bie.component_id = ic.component_id");
    }

    // Hash legacy staff/admin passwords in-place while retaining their current password value.
    if (schema_table_exists($conn, 'users')) {
        $result = mysqli_query($conn, "SELECT user_id, password FROM users WHERE role IN ('staff','admin') AND password IS NOT NULL AND password <> ''");
        while ($result && $row = mysqli_fetch_assoc($result)) {
            $info = password_get_info((string) $row['password']);
            if (($info['algoName'] ?? 'unknown') === 'unknown') {
                $hash = password_hash((string) $row['password'], PASSWORD_DEFAULT);
                $id = (int) $row['user_id'];
                $stmt = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE user_id = ?');
                mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    migrate_profile_photo($conn);
    migrate_replacement_evidence($conn);
    migrate_replacement_only($conn);
    schema_exec($conn, "INSERT IGNORE INTO schema_migrations (version) VALUES ('2026-09-04-complete-workflow')");
    schema_exec($conn, "INSERT IGNORE INTO schema_migrations (version) VALUES ('2026-09-05-workflow-integrity')");
    migrate_pin_reset($conn);
    migrate_component_availability($conn);
    migrate_component_quantities($conn);
    migrate_component_stock($conn);
    migrate_borrow_allocation($conn);
}
