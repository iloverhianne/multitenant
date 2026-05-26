<?php
/**
 * DATABASE REVERT SCRIPT
 * ----------------------
 * Reverts tables to MyISAM and removes all Foreign Key constraints.
 */

require_once 'db-config.php';

set_time_limit(300);

echo "<h1>Reverting Database to MyISAM...</h1><ul>";

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. DROP ALL FOREIGN KEYS
    echo "<h3>1. Dropping Foreign Keys...</h3>";
    
    // Fetch all foreign keys in the current database
    $stmt = $db->query("
        SELECT TABLE_NAME, CONSTRAINT_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fks as $fk) {
        try {
            $table = $fk['TABLE_NAME'];
            $constraint = $fk['CONSTRAINT_NAME'];
            $db->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint` track_fk_drop");
            echo "<li>[OK] Dropped FK <b>$constraint</b> from <b>$table</b>.</li>";
        } catch (Exception $e) {
            // Some might have already been dropped or are primary keys
            try {
                 $db->exec("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                 echo "<li>[OK] Dropped FK <b>{$fk['CONSTRAINT_NAME']}</b>.</li>";
            } catch(Exception $e2) {
                echo "<li>[SKIP] Could not drop {$fk['CONSTRAINT_NAME']}: " . $e2->getMessage() . "</li>";
            }
        }
    }

    // 2. CONVERT TABLES TO MyISAM
    $tablesToRevert = [
        'master_services', 'inventory_transactions', 'support_messages', 
        'tenant_payments', 'audit_logs', 'job_orders', 'repair_status_logs', 
        'system_backups', 'repair_parts', 'subscription_plans', 'inventory', 
        'shift_requests', 'parts', 'announcements', 'backups', 
        'tenant_subscriptions', 'job_order_parts', 'job_order_services', 
        'system_settings', 'tenants', 'users', 'customers', 'mechanics', 
        'vehicles', 'appointments', 'services', 'service_categories', 'roles', 'messages'
    ];

    echo "<h3>2. Converting tables back to MyISAM...</h3>";
    foreach ($tablesToRevert as $table) {
        try {
            $check = $db->query("SHOW TABLES LIKE '$table'")->fetch();
            if ($check) {
                $db->exec("ALTER TABLE `$table` ENGINE=MyISAM");
                echo "<li>[OK] Reverted <b>$table</b> to MyISAM.</li>";
            }
        } catch (Exception $e) {
            echo "<li>[SKIP] Table <b>$table</b> error: " . $e->getMessage() . "</li>";
        }
    }

    // 3. OPTIONAL: Convert NULLs back to 0 if needed (Legacy Support)
    echo "<h3>3. Restoring Legacy Values...</h3>";
    try {
        $db->exec("UPDATE users SET tenant_id = 0 WHERE tenant_id IS NULL AND role_id = 1");
        echo "<li>[OK] Restored tenant_id=0 for Super Admins.</li>";
    } catch (Exception $e) {
        echo "<li>[SKIP] Could not restore legacy values: " . $e->getMessage() . "</li>";
    }

    echo "</ul><h2 style='color:orange;'>Database Reverted Successfully!</h2>";
    echo "<p>All tables are back to MyISAM and Foreign Keys have been removed.</p>";

} catch (Exception $e) {
    echo "</ul><h2 style='color:red;'>Global Error:</h2><p>" . $e->getMessage() . "</p>";
}
