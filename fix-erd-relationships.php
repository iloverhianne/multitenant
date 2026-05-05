<?php
require_once 'db-config.php';

/**
 * DATABASE ERD RELATIONSHIP FIXER
 * -------------------------------
 * This script migrates the database from MyISAM to InnoDB,
 * cleans up invalid data (fake '0' IDs), normalizes service relationships,
 * and establishes proper FOREIGN KEY constraints for MySQL Workbench ERD.
 */

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h1>Database ERD Fixer Starting...</h1><ul>";

    // 1. CONVERT ALL TABLES TO InnoDB
    $tables = [
        'roles', 'users', 'customers', 'vehicles', 'services', 'master_services',
        'repair_jobs', 'appointments', 'repair_timeline', 'payments', 
        'mechanics', 'service_bays', 'inventory', 'tenants', 'subscription_plans'
    ];

    foreach ($tables as $table) {
        try {
            $db->exec("ALTER TABLE `$table` ENGINE=InnoDB");
            echo "<li>[OK] Converted <b>$table</b> to InnoDB.</li>";
        } catch (Exception $e) {
            echo "<li>[SKIP] Could not convert $table: " . $e->getMessage() . "</li>";
        }
    }

    // 2. CLEAN UP FAKE '0' IDs (Replace with NULL where optional)
    $cleanups = [
        'users' => ['tenant_id'],
        'repair_jobs' => ['service_id', 'bay_id', 'mechanic_id'],
        'appointments' => ['mechanic_id'],
        'payments' => ['job_id', 'appointment_id'],
        'service_bays' => ['current_job_id'],
        'vehicles' => ['customer_id'] // Should technically be NOT NULL, but let's be safe
    ];

    foreach ($cleanups as $table => $cols) {
        foreach ($cols as $col) {
            // First, make sure the column is nullable
            try {
                $db->exec("ALTER TABLE `$table` MODIFY `$col` INT NULL");
                $db->exec("UPDATE `$table` SET `$col` = NULL WHERE `$col` = '0' OR `$col` = ''");
                echo "<li>[OK] Cleaned up '0' values in <b>$table.$col</b></li>";
            } catch (Exception $e) {
                echo "<li>[ERROR] Cleaning $table.$col: " . $e->getMessage() . "</li>";
            }
        }
    }

    // 2.1 ADD user_id TO customers AND mechanics IF MISSING
    foreach (['customers', 'mechanics'] as $table) {
        try {
            $db->exec("ALTER TABLE `$table` ADD COLUMN user_id INT NULL AFTER tenant_id, ADD INDEX (user_id)");
            echo "<li>[OK] Added <b>user_id</b> column to $table.</li>";
        } catch (Exception $e) {
            echo "<li>[SKIP] user_id already exists or error in $table: " . $e->getMessage() . "</li>";
        }
    }

    // 3. FIX APPOINTMENTS.SERVICE_ID (Handle comma-separated IDs)
    // a. Create Junction Table for Appointments
    $db->exec("CREATE TABLE IF NOT EXISTS appointment_services (
        appointment_service_id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        service_id INT NOT NULL,
        INDEX (appointment_id),
        INDEX (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "<li>[OK] Created <b>appointment_services</b> junction table.</li>";

    // b. Migrate data from appointments.service_id (VARCHAR) to appointment_services
    $stmt = $db->query("SELECT appointment_id, service_id FROM appointments");
    while ($row = $stmt->fetch()) {
        $ids = explode(',', $row['service_id']);
        foreach ($ids as $id) {
            $id = trim($id);
            if (is_numeric($id) && $id > 0) {
                $db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)")
                   ->execute([$row['appointment_id'], $id]);
            }
        }
    }
    echo "<li>[OK] Migrated service data to junction table.</li>";

    // c. Clean up appointments table - remove the service_id column or make it nullable for now
    try {
        $db->exec("ALTER TABLE appointments MODIFY service_id VARCHAR(255) NULL");
        echo "<li>[OK] Modified <b>appointments.service_id</b> to be nullable.</li>";
    } catch (Exception $e) {}

    // b. Create Junction Table for Repair Jobs
    $db->exec("CREATE TABLE IF NOT EXISTS repair_job_services (
        job_service_id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        service_id INT NOT NULL,
        INDEX (job_id),
        INDEX (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "<li>[OK] Created <b>repair_job_services</b> junction table.</li>";

    // c. Migrate data from repair_jobs.service_id
    $stmt = $db->query("SELECT job_id, service_id FROM repair_jobs WHERE service_id IS NOT NULL AND service_id != 0");
    while ($row = $stmt->fetch()) {
        $db->prepare("INSERT IGNORE INTO repair_job_services (job_id, service_id) VALUES (?, ?)")
           ->execute([$row['job_id'], $row['service_id']]);
    }
    echo "<li>[OK] Migrated repair job service data.</li>";

    // 4. ADD FOREIGN KEY CONSTRAINTS
    $constraints = [
        'users' => [
            'role_id' => ['roles', 'role_id', 'RESTRICT'],
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE']
        ],
        'customers' => [
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE'],
            'user_id' => ['users', 'user_id', 'SET NULL']
        ],
        'mechanics' => [
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE'],
            'user_id' => ['users', 'user_id', 'SET NULL']
        ],
        'vehicles' => [
            'customer_id' => ['customers', 'customer_id', 'CASCADE'],
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE']
        ],
        'appointments' => [
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE'],
            'customer_id' => ['customers', 'customer_id', 'RESTRICT'],
            'vehicle_id' => ['vehicles', 'vehicle_id', 'RESTRICT']
        ],
        'appointment_services' => [
            'appointment_id' => ['appointments', 'appointment_id', 'CASCADE'],
            'service_id' => ['services', 'service_id', 'RESTRICT']
        ],
        'repair_jobs' => [
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE'],
            'customer_id' => ['customers', 'customer_id', 'RESTRICT'],
            'vehicle_id' => ['vehicles', 'vehicle_id', 'RESTRICT'],
            'mechanic_id' => ['mechanics', 'mechanic_id', 'SET NULL'],
            'bay_id' => ['service_bays', 'bay_id', 'SET NULL']
        ],
        'repair_job_services' => [
            'job_id' => ['repair_jobs', 'job_id', 'CASCADE'],
            'service_id' => ['services', 'service_id', 'RESTRICT']
        ],
        'repair_timeline' => [
            'job_id' => ['repair_jobs', 'job_id', 'CASCADE']
        ],
        'payments' => [
            'tenant_id' => ['tenants', 'tenant_id', 'CASCADE'],
            'customer_id' => ['customers', 'customer_id', 'RESTRICT'],
            'job_id' => ['repair_jobs', 'job_id', 'SET NULL'],
            'appointment_id' => ['appointments', 'appointment_id', 'SET NULL']
        ]
    ];

    foreach ($constraints as $table => $fks) {
        foreach ($fks as $col => $details) {
            list($parentTable, $parentCol, $onDelete) = $details;
            $constraintName = "fk_{$table}_{$col}";
            
            try {
                // Remove orphans first to ensure FK can be added
                $db->exec("DELETE t FROM `$table` t LEFT JOIN `$parentTable` p ON t.`$col` = p.`$parentCol` WHERE p.`$parentCol` IS NULL AND t.`$col` IS NOT NULL");
                
                $sql = "ALTER TABLE `$table` ADD CONSTRAINT `$constraintName` 
                        FOREIGN KEY (`$col`) REFERENCES `$parentTable` (`$parentCol`) 
                        ON UPDATE CASCADE ON DELETE $onDelete";
                $db->exec($sql);
                echo "<li>[OK] Added FK: <b>$table.$col</b> -> $parentTable.$parentCol</li>";
            } catch (Exception $e) {
                echo "<li>[ERROR] Failed FK: $table.$col. Error: " . $e->getMessage() . "</li>";
            }
        }
    }

    echo "</ul><h2 style='color:green;'>Database Fix Complete!</h2>";
    echo "<p>Please reverse engineer the database in MySQL Workbench to see the relationship lines.</p>";

} catch (Exception $e) {
    echo "<h2 style='color:red;'>Global Error:</h2><p>" . $e->getMessage() . "</p>";
}
