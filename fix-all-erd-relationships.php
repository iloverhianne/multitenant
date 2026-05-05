<?php
/**
 * FINAL ERD RELATIONSHIP FIXER
 * ----------------------------
 * Fixes all remaining disconnected tables in the MySQL Workbench ERD.
 */

require_once 'db-config.php';

// Set long execution time as this might take a while
set_time_limit(300);

echo "<h1>Final ERD Relationship Fixer Starting...</h1><ul>";

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. CONVERT ALL RELEVANT TABLES TO InnoDB
    $tablesToConvert = [
        'master_services', 'inventory_transactions', 'support_messages', 
        'tenant_payments', 'audit_logs', 'job_orders', 'repair_status_logs', 
        'system_backups', 'repair_parts', 'subscription_plans', 'inventory', 
        'shift_requests', 'parts', 'announcements', 'backups', 
        'tenant_subscriptions', 'job_order_parts', 'job_order_services', 
        'system_settings', 'tenants', 'users', 'customers', 'mechanics', 
        'vehicles', 'appointments', 'services', 'service_categories'
    ];

    echo "<h3>1. Converting tables to InnoDB...</h3>";
    foreach ($tablesToConvert as $table) {
        try {
            // Check if table exists first
            $check = $db->query("SHOW TABLES LIKE '$table'")->fetch();
            if ($check) {
                $db->exec("ALTER TABLE `$table` ENGINE=InnoDB");
                echo "<li>[OK] Converted <b>$table</b> to InnoDB.</li>";
            }
        } catch (Exception $e) {
            echo "<li>[SKIP] Table <b>$table</b> error: " . $e->getMessage() . "</li>";
        }
    }

    // Helper functions for safety
    function columnExists($db, $table, $column) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    function ensureIndex($db, $table, $column) {
        try {
            // Check if index exists
            $stmt = $db->query("SHOW INDEX FROM `$table` WHERE Column_name = '$column'");
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE `$table` ADD INDEX (`$column`)");
                echo "<li>[INDEX] Added index to <b>$table.$column</b></li>";
            }
        } catch (Exception $e) {
            echo "<li>[ERROR] Could not add index to $table.$column: " . $e->getMessage() . "</li>";
        }
    }

    function fixIncompatibleTypes($db, $childTable, $childCol, $parentTable, $parentCol) {
        try {
            $childInfo = $db->query("SHOW COLUMNS FROM `$childTable` LIKE '$childCol'")->fetch();
            $parentInfo = $db->query("SHOW COLUMNS FROM `$parentTable` LIKE '$parentCol'")->fetch();
            
            if ($childInfo && $parentInfo) {
                $childType = strtoupper($childInfo['Type']);
                $parentType = strtoupper($parentInfo['Type']);
                
                if ($childType !== $parentType) {
                    // Try to match child to parent
                    $db->exec("ALTER TABLE `$childTable` MODIFY `$childCol` $parentType");
                    echo "<li>[TYPE] Normalized <b>$childTable.$childCol</b> type to $parentType to match parent.</li>";
                }
            }
        } catch (Exception $e) {
            echo "<li>[ERROR] Type fix for $childTable.$childCol: " . $e->getMessage() . "</li>";
        }
    }

    function addSafeForeignKey($db, $childTable, $childCol, $parentTable, $parentCol, $onDelete = 'RESTRICT') {
        if (!columnExists($db, $childTable, $childCol) || !columnExists($db, $parentTable, $parentCol)) {
            return;
        }

        $constraintName = "fk_{$childTable}_{$childCol}";
        
        try {
            // 1. Ensure InnoDB
            $db->exec("ALTER TABLE `$childTable` ENGINE=InnoDB");
            $db->exec("ALTER TABLE `$parentTable` ENGINE=InnoDB");

            // 2. Fix 0 to NULL for optional fields (if nullable)
            $colInfo = $db->query("SHOW COLUMNS FROM `$childTable` LIKE '$childCol'")->fetch();
            if ($colInfo['Null'] === 'YES') {
                $db->exec("UPDATE `$childTable` SET `$childCol` = NULL WHERE `$childCol` = 0 OR `$childCol` = ''");
            }

            // 3. Fix incompatible types
            fixIncompatibleTypes($db, $childTable, $childCol, $parentTable, $parentCol);

            // 4. Ensure Index
            ensureIndex($db, $childTable, $childCol);

            // 5. Clean Orphans
            $orphanCount = $db->query("SELECT COUNT(*) FROM `$childTable` child 
                                     LEFT JOIN `$parentTable` parent ON child.`$childCol` = parent.`$parentCol` 
                                     WHERE child.`$childCol` IS NOT NULL AND parent.`$parentCol` IS NULL")->fetchColumn();
            
            if ($orphanCount > 0) {
                echo "<li>[WARNING] Found $orphanCount orphans in <b>$childTable.$childCol</b>. Cleaning...</li>";
                $db->exec("DELETE child FROM `$childTable` child 
                          LEFT JOIN `$parentTable` parent ON child.`$childCol` = parent.`$parentCol` 
                          WHERE child.`$childCol` IS NOT NULL AND parent.`$parentCol` IS NULL");
            }

            // 6. Check if FK already exists
            $fkCheck = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                  WHERE TABLE_NAME = '$childTable' AND COLUMN_NAME = '$childCol' 
                                  AND REFERENCED_TABLE_NAME = '$parentTable'")->fetch();
            
            if (!$fkCheck) {
                $sql = "ALTER TABLE `$childTable` ADD CONSTRAINT `$constraintName` 
                        FOREIGN KEY (`$childCol`) REFERENCES `$parentTable` (`$parentCol`) 
                        ON UPDATE CASCADE ON DELETE $onDelete";
                $db->exec($sql);
                echo "<li>[OK] Added FK: <b>$childTable.$childCol</b> -> $parentTable.$parentCol</li>";
            } else {
                echo "<li>[SKIP] FK already exists for <b>$childTable.$childCol</b></li>";
            }
        } catch (Exception $e) {
            echo "<li>[ERROR] Failed FK: $childTable.$childCol. Error: " . $e->getMessage() . "</li>";
        }
    }

    echo "<h3>2. Establishing Foreign Key Relationships...</h3>";

    // 2.1 TENANT PAYMENTS & SUBSCRIPTIONS
    addSafeForeignKey($db, 'tenant_payments', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'tenant_payments', 'subscription_id', 'tenant_subscriptions', 'subscription_id', 'RESTRICT');
    addSafeForeignKey($db, 'tenant_payments', 'plan_id', 'subscription_plans', 'plan_id', 'RESTRICT');

    addSafeForeignKey($db, 'tenant_subscriptions', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'tenant_subscriptions', 'plan_id', 'subscription_plans', 'plan_id', 'RESTRICT');

    // 2.2 AUDIT LOGS & ANNOUNCEMENTS
    addSafeForeignKey($db, 'audit_logs', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'audit_logs', 'user_id', 'users', 'user_id', 'RESTRICT');
    
    addSafeForeignKey($db, 'announcements', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'announcements', 'user_id', 'users', 'user_id', 'RESTRICT');

    // 2.3 JOB ORDERS (The Core workshop table)
    addSafeForeignKey($db, 'job_orders', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'job_orders', 'customer_id', 'customers', 'customer_id', 'RESTRICT');
    addSafeForeignKey($db, 'job_orders', 'vehicle_id', 'vehicles', 'vehicle_id', 'RESTRICT');
    addSafeForeignKey($db, 'job_orders', 'mechanic_id', 'mechanics', 'mechanic_id', 'SET NULL');
    addSafeForeignKey($db, 'job_orders', 'appointment_id', 'appointments', 'appointment_id', 'SET NULL');
    addSafeForeignKey($db, 'job_orders', 'user_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'job_orders', 'created_by', 'users', 'user_id', 'SET NULL');

    // 2.4 JOB ORDER DETAILS (Parts & Services)
    addSafeForeignKey($db, 'job_order_parts', 'job_order_id', 'job_orders', 'job_order_id', 'CASCADE');
    addSafeForeignKey($db, 'job_order_parts', 'part_id', 'parts', 'part_id', 'RESTRICT');
    
    addSafeForeignKey($db, 'job_order_services', 'job_order_id', 'job_orders', 'job_order_id', 'CASCADE');
    addSafeForeignKey($db, 'job_order_services', 'service_id', 'services', 'service_id', 'RESTRICT');

    // 2.5 REPAIR LOGS & STATUS
    addSafeForeignKey($db, 'repair_status_logs', 'job_order_id', 'job_orders', 'job_order_id', 'CASCADE');
    addSafeForeignKey($db, 'repair_status_logs', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'repair_status_logs', 'user_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'repair_status_logs', 'updated_by', 'users', 'user_id', 'SET NULL');

    addSafeForeignKey($db, 'repair_parts', 'job_order_id', 'job_orders', 'job_order_id', 'CASCADE');
    addSafeForeignKey($db, 'repair_parts', 'part_id', 'parts', 'part_id', 'RESTRICT');
    addSafeForeignKey($db, 'repair_parts', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');

    // 2.6 INVENTORY & PARTS
    addSafeForeignKey($db, 'parts', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'parts', 'created_by', 'users', 'user_id', 'SET NULL');

    addSafeForeignKey($db, 'inventory', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'inventory', 'part_id', 'parts', 'part_id', 'RESTRICT');

    addSafeForeignKey($db, 'inventory_transactions', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'inventory_transactions', 'inventory_id', 'inventory', 'inventory_id', 'CASCADE');
    addSafeForeignKey($db, 'inventory_transactions', 'part_id', 'parts', 'part_id', 'CASCADE');
    addSafeForeignKey($db, 'inventory_transactions', 'job_order_id', 'job_orders', 'job_order_id', 'SET NULL');
    addSafeForeignKey($db, 'inventory_transactions', 'user_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'inventory_transactions', 'performed_by', 'users', 'user_id', 'SET NULL');

    // 2.7 SUPPORT & MESSAGES
    addSafeForeignKey($db, 'support_messages', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'support_messages', 'user_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'support_messages', 'sender_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'support_messages', 'receiver_id', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'support_messages', 'customer_id', 'customers', 'customer_id', 'SET NULL');

    // 2.8 SHIFT REQUESTS
    addSafeForeignKey($db, 'shift_requests', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'shift_requests', 'mechanic_id', 'mechanics', 'mechanic_id', 'CASCADE');
    addSafeForeignKey($db, 'shift_requests', 'processed_by', 'users', 'user_id', 'SET NULL');

    // 2.9 MASTER SERVICES & SYSTEM
    addSafeForeignKey($db, 'master_services', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'master_services', 'category_id', 'service_categories', 'category_id', 'RESTRICT');
    addSafeForeignKey($db, 'master_services', 'created_by', 'users', 'user_id', 'SET NULL');

    addSafeForeignKey($db, 'system_settings', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'system_settings', 'updated_by', 'users', 'user_id', 'SET NULL');

    addSafeForeignKey($db, 'system_backups', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'system_backups', 'created_by', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'system_backups', 'user_id', 'users', 'user_id', 'SET NULL');

    addSafeForeignKey($db, 'backups', 'tenant_id', 'tenants', 'tenant_id', 'CASCADE');
    addSafeForeignKey($db, 'backups', 'created_by', 'users', 'user_id', 'SET NULL');
    addSafeForeignKey($db, 'backups', 'user_id', 'users', 'user_id', 'SET NULL');

    echo "</ul><h2 style='color:green;'>Database Relationship Fix Complete!</h2>";
    echo "<p>All target tables are now InnoDB and proper foreign keys have been added where columns were found.</p>";
    echo "<p>Please reverse engineer the database again in MySQL Workbench to see the relationship lines.</p>";

} catch (Exception $e) {
    echo "</ul><h2 style='color:red;'>Global Error:</h2><p>" . $e->getMessage() . "</p>";
}
