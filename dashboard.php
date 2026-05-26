<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Manila');

require_once 'db-config.php';
require_once 'mailer-service.php';

/**
 * Generates a full database backup SQL file
 */
function generateBackup($db, $type = 'MANUAL')
{
    try {
        $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception("Failed to create backup directory: " . $backupDir);
            }
        }

        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        if (empty($tables)) {
            throw new Exception("No tables found in database to backup.");
        }

        $sql = "-- AutoFix Hub Database Backup ($type)\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $row2 = $db->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM);
            $sql .= "\n\n" . $row2[1] . ";\n\n";

            $result = $db->query("SELECT * FROM $table");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $sql .= "INSERT INTO $table VALUES(";
                for ($j = 0; $j < count($row); $j++) {
                    if (isset($row[$j])) {
                        $val = addslashes($row[$j]);
                        $val = str_replace("\n", "\\n", $val);
                        $sql .= '"' . $val . '"';
                    } else {
                        $sql .= 'NULL';
                    }
                    if ($j < (count($row) - 1)) {
                        $sql .= ',';
                    }
                }
                $sql .= ");\n";
            }
        }
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;";

        $filename = 'backup_' . ($type === 'AUTO_LOGIN' ? 'login_' : '') . date('Y-m-d_His') . '.sql';
        $filepath = $backupDir . $filename;
        if (file_put_contents($filepath, $sql) === false) {
            throw new Exception("Failed to write backup file: " . $filepath);
        }
        $filesize = filesize($filepath);

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO backups (filename, file_size, status, created_at) VALUES (?, ?, 'SUCCESS', ?)");
        $stmt->execute([$filename, $filesize, $now]);

        // Log to audit trail
        $sizeDisplay = ($filesize / 1024 > 1024) ? round($filesize / 1048576, 2) . ' MB' : round($filesize / 1024, 2) . ' KB';
        $logMsg = ($type === 'AUTO_LOGIN' ? "Automatic login snapshot" : "Manual database snapshot") . " created: $filename ($sizeDisplay)";
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('SYSTEM', ?, ?)")
            ->execute([$logMsg, $now]);

        return ['status' => 'success', 'filename' => $filename, 'message' => 'Backup created successfully'];
    } catch (Exception $e) {
        error_log("Backup Error ($type): " . $e->getMessage());
        $now = date('Y-m-d H:i:s');
        try {
            $db->prepare("INSERT INTO backups (filename, file_size, status, created_at) VALUES (?, 0, 'FAILED', ?)")
                ->execute(['ERROR: ' . substr($e->getMessage(), 0, 200), $now]);
        } catch (Exception $e2) {
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}



try {
    $db = getDB();
    // Migrations / One-time Patches should be handled via a dedicated script or conditional check
    // Removed old hardcoded price patches that were overwriting Super Admin changes.
} catch (Exception $e) {
}


// Security Check
if (!isset($_SESSION['isLoggedIn']) || strtoupper($_SESSION['role']) !== 'SUPER_ADMIN') {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Auto Backup on Login (Triggers exactly once per successful login event)
if (isset($_SESSION['pending_auto_backup']) && $_SESSION['pending_auto_backup'] === true) {
    generateBackup($db, 'AUTO_LOGIN');
    unset($_SESSION['pending_auto_backup']);
}



// Auto-migrate: Add 'slug' and 'business_proof_url' columns to tenants table if they don't exist
try {
    $db->exec("ALTER TABLE tenants ADD COLUMN slug VARCHAR(100) DEFAULT NULL AFTER status");
} catch (PDOException $e) {
}

try {
    $db->exec("ALTER TABLE tenants ADD COLUMN business_proof_url VARCHAR(255) DEFAULT NULL AFTER slug");
} catch (PDOException $e) {
}

try {
    $db->exec("ALTER TABLE tenants ADD COLUMN id_type VARCHAR(100) DEFAULT NULL AFTER business_proof_url");
} catch (PDOException $e) {
}

try {
    $db->exec("ALTER TABLE tenants ADD COLUMN id_photo_url VARCHAR(255) DEFAULT NULL AFTER id_type");
} catch (PDOException $e) {
}

// Auto-migrate: Add 'features' column to subscription_plans if missing
try {
    $db->exec("ALTER TABLE subscription_plans ADD COLUMN features TEXT NULL");
} catch (PDOException $e) {
}

// Auto-migrate: Add 'avatar_url' column to users table if missing
try {
    $db->exec("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {
}

// Auto-migrate: Add 'price_yearly' column to subscription_plans if missing
try {
    $db->exec("ALTER TABLE subscription_plans ADD COLUMN price_yearly DECIMAL(10,2) DEFAULT 0.00 AFTER price");
} catch (PDOException $e) {
}

// Subscription Plan Initialization
try {
    // 0. Only insert defaults if the table is empty to allow manual edits to persist
    $planCount = $db->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
    if ($planCount == 0) {
        $db->exec("INSERT INTO subscription_plans (plan_id, plan_name, price, price_yearly, max_users, max_service_bays, status) VALUES 
            (1, 'BASIC', 1999, 17990, 5, 2, 'active'),
            (2, 'PRO', 6499, 58490, 20, 5, 'active'),
            (3, 'ENTERPRISE', 24999, 224990, 100, 20, 'active')");
    } else {
        // Heal: If monthly price was changed but yearly was left at default, sync it
        $db->exec("UPDATE subscription_plans SET price_yearly = price * 12 * 0.8 WHERE price_yearly = 17990 AND price != 1999");
    }

    // 0. Cleanup Payment Statuses: Standardize all completed payments to 'SUCCESS'
    $db->exec("UPDATE tenant_payments SET payment_status = 'SUCCESS' WHERE UPPER(payment_status) = 'PAID' OR payment_status IS NULL OR payment_status = ''");

    /* 
       REMOVED: Hardcoded pricing sync. 
       We now rely on the plan_id and billing_cycle saved during the actual transaction 
       to prevent issues when the Super Admin changes plan prices.
    */
    /*
    $db->exec("UPDATE tenant_subscriptions s 
               JOIN (
                   SELECT p1.tenant_id, p1.amount 
                   FROM tenant_payments p1
                   WHERE p1.payment_id = (SELECT p2.payment_id FROM tenant_payments p2 WHERE p2.tenant_id = p1.tenant_id ORDER BY p2.payment_date DESC LIMIT 1)
               ) pay ON s.tenant_id = pay.tenant_id
               SET 
                 s.plan_id = CASE 
                    WHEN pay.amount IN (24999, 249990) THEN 3 
                    WHEN pay.amount IN (6499, 64990) THEN 2
                    WHEN pay.amount IN (1999, 19990) THEN 1
                    ELSE 1 
                 END,
                 s.billing_cycle = CASE
                    WHEN pay.amount IN (19990, 64990, 249990) THEN 'yearly'
                    ELSE 'monthly'
                 END");
    */

    // 2. Status Sync: Always keep ACTIVE subscriptions for active tenants
    $db->exec("UPDATE tenant_subscriptions s JOIN tenants t ON s.tenant_id = t.tenant_id SET s.status = 'ACTIVE' WHERE t.status = 'active' OR t.status = 'ACTIVE'");

    // 3. Subscription Heal: Ensure every active/pending tenant has at least a BASIC subscription if none exists
    $db->exec("INSERT INTO tenant_subscriptions (tenant_id, plan_id, billing_cycle, start_date, end_date, status) 
               SELECT t.tenant_id, 1, 'monthly', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'ACTIVE'
               FROM tenants t
               LEFT JOIN tenant_subscriptions s ON t.tenant_id = s.tenant_id
               WHERE (UPPER(t.status) IN ('ACTIVE', 'PENDING')) AND s.subscription_id IS NULL");

    // 4. Plan ID Heal: Fix any subscriptions with missing or invalid plan IDs (default to BASIC)
    $db->exec("UPDATE tenant_subscriptions SET plan_id = 1 WHERE plan_id IS NULL OR plan_id = 0 OR plan_id NOT IN (SELECT plan_id FROM subscription_plans)");

    // 4. Ensure billing_cycle exists
    $db->exec("ALTER TABLE tenant_subscriptions ADD COLUMN IF NOT EXISTS billing_cycle VARCHAR(50) DEFAULT 'monthly'");

    // 5. Create Chat Support table
    $db->exec("CREATE TABLE IF NOT EXISTS support_messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        sender_role ENUM('ADMIN', 'TENANT') NOT NULL,
        sender_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // System Settings Table Heal
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL
    )");

    // Backups Table
    $db->exec("CREATE TABLE IF NOT EXISTS backups (
        backup_id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        file_size BIGINT DEFAULT 0,
        status ENUM('SUCCESS', 'FAILED') DEFAULT 'SUCCESS',
        created_at DATETIME NOT NULL
    )");

    // Seed defaults if empty
    $checkSet = $db->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
    if ($checkSet == 0) {
        $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES 
            ('app_name', 'AutoFix Hub'),
            ('support_email', 'support@autofixhub.com'),
            ('maintenance_mode', 'off'),
            ('max_storage_gb', '5'),
            ('default_staff_role', 'mechanic'),
            ('auto_approve_tenant', 'on')
        ");
    }

    // Clear Audit Logs Action
    // Price Standards Table Heal
    $db->exec("CREATE TABLE IF NOT EXISTS master_services (
        master_id INT AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(100) UNIQUE NOT NULL,
        category VARCHAR(50),
        min_price DECIMAL(10,2) DEFAULT 0,
        max_price DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed Master Services if empty or contains old data
    $checkOld = $db->query("SELECT COUNT(*) FROM master_services WHERE service_name = 'Car Wash & Wax'")->fetchColumn();
    $checkMs = $db->query("SELECT COUNT(*) FROM master_services")->fetchColumn();

    if ($checkMs == 0 || $checkOld > 0) {
        // If old data exists, clear it to apply new professional standards
        if ($checkOld > 0) {
            $db->exec("TRUNCATE TABLE master_services");
        }
        $db->exec("INSERT INTO master_services (service_name, category, min_price, max_price) VALUES 
            ('Engine Oil Change (Synthetic)', 'Maintenance', 2500, 6500),
            ('Engine Oil Change (Mineral)', 'Maintenance', 1200, 2500),
            ('Brake Pad Replacement (Front)', 'Repairs', 1500, 4500),
            ('Brake Pad Replacement (Rear)', 'Repairs', 1500, 4500),
            ('Brake Disc Resurfacing', 'Repairs', 800, 2500),
            ('Engine Overhaul (General)', 'Major Repairs', 35000, 150000),
            ('Engine Overhaul (Top)', 'Major Repairs', 15000, 45000),
            ('Transmission Fluid Replacement', 'Maintenance', 3500, 8500),
            ('Wheel Alignment & Balancing', 'Maintenance', 1200, 3500),
            ('Aircon General Cleaning', 'Aircon', 2500, 6000),
            ('Freon Charging', 'Aircon', 800, 2000),
            ('OBD2 Scanning & Diagnostics', 'Electrical', 500, 2500),
            ('Alternator Repair/Replacement', 'Electrical', 3500, 12000),
            ('Starter Motor Replacement', 'Electrical', 2500, 8500),
            ('Radiator Repair/Cleaning', 'Cooling System', 1200, 4000),
            ('Suspension Bushing Replacement', 'Suspension', 3500, 15000),
            ('Shock Absorber Replacement', 'Suspension', 4500, 25000),
            ('Clutch Lining Replacement', 'Major Repairs', 8500, 25000),
            ('Timing Belt Replacement', 'Maintenance', 6500, 18000),
            ('Spark Plug Replacement (Set)', 'Maintenance', 800, 4500)
        ");
    }

    if (isset($_GET['action']) && $_GET['action'] === 'save_master_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
            $id = $_POST['master_id'] ?? null;
            $name = $_POST['service_name'];
            $cat = $_POST['category'];
            $min = floatval($_POST['min_price']);
            $max = floatval($_POST['max_price']);

            if ($id) {
                $stmt = $db->prepare("UPDATE master_services SET service_name=?, category=?, min_price=?, max_price=? WHERE master_id=?");
                $stmt->execute([$name, $cat, $min, $max, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO master_services (service_name, category, min_price, max_price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $cat, $min, $max]);
            }
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_master_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
            $id = $_POST['id'];
            $db->prepare("DELETE FROM master_services WHERE master_id = ?")->execute([$id]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'fetch_master_services') {
        header('Content-Type: application/json');
        $stmt = $db->query("SELECT * FROM master_services ORDER BY service_name ASC");
        echo json_encode($stmt->fetchAll());
        exit;
    }



    if (isset($_GET['action']) && $_GET['action'] === 'clear_logs_db') {
        header('Content-Type: application/json');
        $db->exec("DELETE FROM audit_logs");
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('SECURITY', 'Platform Audit Trail purged by Super Admin', ?)")->execute([$now]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'edit_announcement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
            $msg = $_POST['announcement'] ?? '';
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'announcement'");
            $stmt->execute([$msg]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }


} catch (PDOException $e) {
}

// Handle Tenant Approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'approve_tenant') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];

        // 1. Get Tenant Info
        $tenant = $db->query("SELECT * FROM tenants WHERE tenant_id = " . (int) $id)->fetch();
        if (!$tenant)
            throw new Exception("Tenant not found.");

        // 2. Update Statuses
        $db->beginTransaction();
        $db->prepare("UPDATE tenants SET status = 'active' WHERE tenant_id = ?")->execute([$id]);
        $db->prepare("UPDATE users SET status = 'ACTIVE' WHERE tenant_id = ?")->execute([$id]);
        $db->prepare("UPDATE tenant_subscriptions SET status = 'ACTIVE' WHERE tenant_id = ?")->execute([$id]);

        // 3. Log Action
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)")->execute(["Super Admin APPROVED tenant: " . $tenant['shop_name'], $now]);

        $db->commit();

        // 4. Send Email
        $shop_name = $tenant['shop_name'];
        $slug = $tenant['slug'];
        $email = $tenant['email'];
        $shop_url = "https://" . $_SERVER['HTTP_HOST'] . "/shop.php?id=" . $slug;
        $admin_url = "https://" . $_SERVER['HTTP_HOST'] . "/login.php";

        $subject = "Registration Approved: Welcome to AutoFix Hub!";
        $html = "
            <div style='font-family:sans-serif; max-width:600px; padding:20px; border:1px solid #eee; border-radius:10px;'>
                <h2 style='color:#6366f1;'>Your Shop is Now Active!</h2>
                <p>Hello <b>" . htmlspecialchars($tenant['owner_name']) . "</b>,</p>
                <p>Great news! Your registration for <b>" . htmlspecialchars($shop_name) . "</b> has been reviewed and approved by our team.</p>
                
                <div style='background:#f9f9f9; padding:15px; border-radius:8px; margin:20px 0;'>
                    <p style='margin:5px 0;'><b>Public Shop Link:</b> <a href='$shop_url'>$shop_url</a></p>
                    <p style='margin:5px 0;'><b>Admin Login:</b> <a href='$admin_url'>$admin_url</a></p>
                    <p style='margin:5px 0;'><b>Username:</b> $email</p>
                </div>

                <p>You can now log in to your dashboard to set up your mechanics, services, and inventory.</p>
                <p style='color:#666; font-size:12px;'>AutoFix Hub Platform Team</p>
            </div>
        ";
        Mailer::sendHTML($email, $subject, $html);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($db->inTransaction())
            $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Tenant Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'reject_tenant') {
    header('Content-Type: application/json');
    try {
        // DEBUG: Capture raw ID and any errors
        $id = (int) $_POST['id'];
        $reason = $_POST['reason'] ?? 'Documentation verification failed or incomplete.';

        // 1. Get Tenant Info
        $stmt = $db->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            echo json_encode(['status' => 'error', 'message' => "Tenant ID $id not found in DB."]);
            exit;
        }

        // 2. Update Statuses
        $db->beginTransaction();
        try {
            $id_int = (int) $id;
            $newName = "[REJECTED] " . $tenant['shop_name'];
            // Use 'suspended' as a fallback if 'rejected' is being blocked, plus the name prefix
            $q1 = $db->prepare("UPDATE tenants SET status = 'rejected', shop_name = ? WHERE tenant_id = ?");
            $ok1 = $q1->execute([$newName, $id_int]);

            $db->prepare("UPDATE users SET status = 'DEACTIVATED' WHERE tenant_id = ?")->execute([$id_int]);
            $db->prepare("UPDATE tenant_subscriptions SET status = 'CANCELLED' WHERE tenant_id = ?")->execute([$id_int]);

            if (!$ok1) {
                $err = $q1->errorInfo();
                throw new Exception("SQL Error: " . $err[2]);
            }

            // 3. Log Action
            $now = date('Y-m-d H:i:s');
            $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)")->execute(["Super Admin REJECTED tenant: " . $tenant['shop_name'], $now]);

            $db->commit();
        } catch (Exception $dbErr) {
            $db->rollBack();
            throw $dbErr;
        }

        // 4. Send Email (Decoupled to prevent rollback on mail failure)
        try {
            $email = $tenant['email'];
            $subject = "AutoFix Hub: Application Status Update";
            $html = "
                <div style='font-family:sans-serif; max-width:600px; padding:20px; border:1px solid #eee; border-radius:10px;'>
                    <h2 style='color:#ef4444;'>Application Status: Rejected</h2>
                    <p>Hello <b>" . htmlspecialchars($tenant['owner_name']) . "</b>,</p>
                    <p>Thank you for your interest in AutoFix Hub. After reviewing your application for <b>" . htmlspecialchars($tenant['shop_name']) . "</b>, we regret to inform you that we cannot approve your registration at this time.</p>
                    
                    <div style='background:#fef2f2; padding:15px; border-radius:8px; margin:20px 0; border-left: 4px solid #ef4444;'>
                        <p style='margin:0;'><b>Reason for Rejection:</b><br>$reason</p>
                    </div>

                    <p>If you believe this was an error or would like to re-submit with corrected documents, please contact our support team.</p>
                    <p style='color:#666; font-size:12px;'>AutoFix Hub Platform Team</p>
                </div>
            ";
            Mailer::sendHTML($email, $subject, $html);
        } catch (Exception $mailErr) {
            // Log mail error but don't fail the whole process
            $db->prepare("INSERT INTO audit_logs (activity_type, description) VALUES ('ERROR', ?)")->execute(["Rejection email failed for " . $tenant['shop_name'] . ": " . $mailErr->getMessage()]);
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction())
            $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Plan Edit/Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'edit_plan') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'] ?? null;
        $name = $_POST['name'] ?? 'Plan';
        $mPrice = $_POST['monthlyPrice'] ?? 0;
        $yPrice = $_POST['yearlyPrice'] ?? 0;
        $maxUsers = $_POST['maxUsers'] ?? 0;
        $maxBays = $_POST['maxBays'] ?? 0;
        $features = $_POST['features'] ?? '';

        if ($id && is_numeric($id)) {
            // UPDATE EXISTING
            $stmt = $db->prepare("UPDATE subscription_plans SET plan_name = ?, price = ?, price_yearly = ?, max_users = ?, max_service_bays = ?, features = ? WHERE plan_id = ?");
            $stmt->execute([$name, $mPrice, $yPrice, $maxUsers, $maxBays, $features, $id]);
            $plan_affected_rows = $stmt->rowCount();

            if ($plan_affected_rows === 0) {
                // If no rows were affected, it means the ID wasn't found or data was identical
                // We'll treat "identical data" as success, but let's log it for debugging
                $activity = "Super Admin tried to update plan: $name (ID: $id) - No changes or ID not found";
            } else {
                $activity = "Super Admin updated subscription plan: $name (ID: $id)";
            }
            $final_id = $id;
        } else {
            // INSERT NEW TIER
            $stmt = $db->prepare("INSERT INTO subscription_plans (plan_name, price, price_yearly, max_users, max_service_bays, status, features) VALUES (?, ?, ?, ?, ?, 'active', ?)");
            $stmt->execute([$name, $mPrice, $yPrice, $maxUsers, $maxBays, $features]);
            $plan_affected_rows = $stmt->rowCount();
            $final_id = $db->lastInsertId();
            $activity = "Super Admin CREATED new subscription tier: $name (ID: $final_id)";
        }

        // Audit Log for Super Admin
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)");
        $stmt->execute([$activity, $now]);

        echo json_encode(['status' => 'success', 'debug_id' => $final_id, 'affected_rows' => $plan_affected_rows]);
    } catch (Exception $e) {
        error_log("SuperAdmin Error (edit_plan): " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Plan Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_plan') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'] ?? null;
        if (!$id)
            throw new Exception("Plan ID is required.");

        // Safeguard: Check if any tenants are using this plan
        $check = $db->prepare("SELECT COUNT(*) FROM tenant_subscriptions WHERE plan_id = ? AND status = 'ACTIVE'");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("Cannot delete this tier. There are active tenants currently subscribed to it.");
        }

        // Delete the plan
        $stmt = $db->prepare("DELETE FROM subscription_plans WHERE plan_id = ?");
        $stmt->execute([$id]);

        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)")
            ->execute(["Super Admin deleted subscription tier ID: $id", $now]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Fetch Backup History
if (isset($_GET['action']) && $_GET['action'] === 'fetch_backups') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->query("SELECT * FROM backups ORDER BY created_at DESC");
        $backups = $stmt->fetchAll();
        $totalSize = $db->query("SELECT SUM(file_size) FROM backups WHERE status='SUCCESS'")->fetchColumn() ?: 0;
        $lastBackup = $db->query("SELECT created_at FROM backups WHERE status='SUCCESS' ORDER BY created_at DESC LIMIT 1")->fetchColumn() ?: 'None';

        $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        $dirExists = is_dir($backupDir);
        $isWritable = $dirExists ? is_writable($backupDir) : is_writable(__DIR__);

        echo json_encode([
            'status' => 'success',
            'backups' => $backups,
            'totalSize' => $totalSize,
            'lastBackup' => $lastBackup,
            'debug' => [
                'dirExists' => $dirExists,
                'isWritable' => $isWritable,
                'path' => $backupDir
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Create Manual Backup
if (isset($_GET['action']) && $_GET['action'] === 'create_backup') {
    header('Content-Type: application/json');
    $result = generateBackup($db, 'MANUAL');
    echo json_encode($result);
    exit;
}

// Action: Delete Backup
if (isset($_GET['action']) && $_GET['action'] === 'delete_backup') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'] ?? 0;
        $stmt = $db->prepare("SELECT filename FROM backups WHERE backup_id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();

        if ($filename && file_exists('backups/' . $filename)) {
            unlink('backups/' . $filename);
        }

        $stmt = $db->prepare("DELETE FROM backups WHERE backup_id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_settings') {
    header('Content-Type: application/json');
    try {
        $db->beginTransaction();
        foreach ($_POST as $key => $val) {
            if (in_array($key, ['admin_password', 'admin_password_confirm', 'admin_name', 'admin_email']))
                continue;
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $val, $val]);
        }

        // Handle Super Admin Profile update
        $admin_id = $_SESSION['user_id'] ?? null;
        if ($admin_id) {
            $admin_name = trim($_POST['admin_name'] ?? '');
            $admin_email = trim($_POST['admin_email'] ?? '');
            
            if (empty($admin_name) || empty($admin_email)) {
                throw new Exception("Display Name and Email are required.");
            }

            // Check if email already used by another admin/staff
            $checkEmail = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
            $checkEmail->execute([$admin_email, $admin_id]);
            if ($checkEmail->fetchColumn() > 0) {
                throw new Exception("Email address is already in use.");
            }

            // Handle Avatar File Upload
            $avatar_url = null;
            if (isset($_FILES['admin_avatar_file']) && $_FILES['admin_avatar_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileExt = pathinfo($_FILES['admin_avatar_file']['name'], PATHINFO_EXTENSION);
                $fileName = 'avatar_admin_' . $admin_id . '_' . time() . '.' . $fileExt;
                if (move_uploaded_file($_FILES['admin_avatar_file']['tmp_name'], $uploadDir . $fileName)) {
                    $avatar_url = 'uploads/' . $fileName;
                }
            }

            if ($avatar_url) {
                $updUser = $db->prepare("UPDATE users SET name = ?, email = ?, avatar_url = ? WHERE user_id = ?");
                $updUser->execute([$admin_name, $admin_email, $avatar_url, $admin_id]);
            } else {
                $updUser = $db->prepare("UPDATE users SET name = ?, email = ? WHERE user_id = ?");
                $updUser->execute([$admin_name, $admin_email, $admin_id]);
            }

            // Sync active session variables
            $_SESSION['name'] = $admin_name;
        }



        // Handle Admin Password Change
        if (!empty($_POST['admin_password'])) {
            $pass = $_POST['admin_password'];
            $confirm = $_POST['admin_password_confirm'];
            if ($pass !== $confirm)
                throw new Exception("Passwords do not match.");
            if (strlen($pass) < 8)
                throw new Exception("Password must be at least 8 characters.");

            $hash = password_hash($pass, PASSWORD_BCRYPT);

            // SUPER ROBUST: Update by both ID and Email
            $admin_id = $_SESSION['user_id'] ?? null;
            $upd = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? OR email = 'superadmin'");
            $upd->execute([$hash, $admin_id]);

            $now = date('Y-m-d H:i:s');
            $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('SECURITY', 'Super Admin changed their account password', ?)")->execute([$now]);
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('INFO', 'Super Admin updated global system settings', ?)")->execute([$now]);
        $db->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($db->inTransaction())
            $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Add Super Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_super_admin') {
    header('Content-Type: application/json');
    try {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $pass = $_POST['password'];
        $hash = password_hash($pass, PASSWORD_BCRYPT);

        $db->prepare("INSERT INTO users (role_id, name, email, password_hash, status, tenant_id) VALUES (1, ?, ?, ?, 'ACTIVE', 0)")
            ->execute([$name, $email, $hash]);

        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)")->execute(['Super Admin added a new management account: ' . $email, $now]);
        echo json_encode(['status' => 'success', 'message' => 'New Super Admin added!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Delete Super Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_super_admin') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'];
        if ($id == ($_SESSION['user_id'] ?? ''))
            throw new Exception("You cannot delete yourself!");

        $email = $db->query("SELECT email FROM users WHERE user_id = " . (int) $id)->fetchColumn();
        $db->prepare("DELETE FROM users WHERE user_id = ? AND role_id = 1")->execute([$id]);
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)")->execute(['Super Admin removed management account: ' . $email, $now]);
        echo json_encode(['status' => 'success', 'message' => 'Admin removed.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Fetch Admin Chat Messages (Grouped by Tenant)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_support_groups') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->query("SELECT t.tenant_id, t.shop_name, t.logo_url, 
                            (SELECT message FROM support_messages sm WHERE sm.tenant_id = t.tenant_id ORDER BY created_at DESC LIMIT 1) as last_msg,
                            (SELECT COUNT(*) FROM support_messages sm WHERE sm.tenant_id = t.tenant_id AND sm.is_read = 0 AND sm.sender_role = 'TENANT') as unread_count,
                            (SELECT MAX(created_at) FROM support_messages sm WHERE sm.tenant_id = t.tenant_id) as last_chat_time,
                            (SELECT COALESCE(u.avatar_url, u.profile_pic) 
                             FROM support_messages sm 
                             JOIN users u ON sm.sender_id = u.user_id 
                             WHERE sm.tenant_id = t.tenant_id AND sm.sender_role = 'TENANT' 
                             ORDER BY sm.created_at DESC LIMIT 1) as tenant_avatar
                            FROM tenants t 
                            WHERE t.status IN ('ACTIVE', 'SUSPENDED')
                            ORDER BY last_chat_time DESC, t.shop_name ASC");
        echo json_encode(['status' => 'success', 'groups' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Fetch Detailed Chat with Tenant
if (isset($_GET['action']) && $_GET['action'] === 'fetch_tenant_chat') {
    header('Content-Type: application/json');
    try {
        $tid = $_GET['tenant_id'];
        $db->prepare("UPDATE support_messages SET is_read = 1 WHERE tenant_id = ? AND sender_role = 'TENANT'")->execute([$tid]);
        $stmt = $db->prepare("SELECT sm.*, t.logo_url, 
                            CASE 
                                WHEN sm.sender_role = 'ADMIN' THEN COALESCE(u.avatar_url, u.profile_pic, (SELECT avatar_url FROM users WHERE role_id = 1 AND avatar_url IS NOT NULL LIMIT 1))
                                ELSE COALESCE(u.avatar_url, u.profile_pic)
                            END AS sender_avatar 
                            FROM support_messages sm 
                            LEFT JOIN tenants t ON sm.tenant_id = t.tenant_id 
                            LEFT JOIN users u ON sm.sender_id = u.user_id 
                            WHERE sm.tenant_id = ? 
                            ORDER BY sm.created_at ASC");
        $stmt->execute([$tid]);
        echo json_encode(['status' => 'success', 'messages' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action: Send Support Reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'send_support_reply') {
    header('Content-Type: application/json');
    try {
        $tid = $_POST['tenant_id'];
        $msg = trim($_POST['message'] ?? '');
        if (empty($msg))
            throw new Exception("Message empty");
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO support_messages (tenant_id, sender_role, sender_id, message, created_at) VALUES (?, 'ADMIN', ?, ?, ?)")
            ->execute([$tid, $_SESSION['user_id'], $msg, $now]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Generic AJAX Logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'log_db') {
    header('Content-Type: application/json');
    try {
        $type = $_POST['type'] ?? 'CRUD';
        $activity = $_POST['activity'];
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$type, $activity, $now]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// Handle AJAX Shop Edit (UNIFIED Super Admin Tenant Mgmt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'edit_shop') {
    header('Content-Type: application/json');
    try {
        $id = $_POST['id'] ?? 0;
        $shopName = $_POST['shop_name'] ?? '';
        $ownerName = $_POST['owner_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $newStatus = strtolower($_POST['status'] ?? 'active');
        $expiry = empty($_POST['expiry']) ? null : $_POST['expiry'];
        $plan_id = $_POST['plan_id'] ?? null;
        $billing = $_POST['billing_cycle'] ?? 'monthly';

        // Pre-fetch for verification
        $stmt = $db->prepare("SELECT status, shop_name, email, owner_name FROM tenants WHERE tenant_id = ?");
        $stmt->execute([$id]);
        $oldTenant = $stmt->fetch();
        if (!$oldTenant)
            throw new Exception("Tenant not found.");

        // Map missing data if it was a quick action
        if (empty($shopName))
            $shopName = $oldTenant['shop_name'];
        if (empty($ownerName))
            $ownerName = $oldTenant['owner_name'];
        if (empty($email))
            $email = $oldTenant['email'];

        $statusChanged = (strtoupper($oldTenant['status']) !== strtoupper($newStatus));

        // Update Tenants table
        $stmt = $db->prepare("UPDATE tenants SET shop_name=?, owner_name=?, email=?, status=? WHERE tenant_id=?");
        $stmt->execute([$shopName, $ownerName, $email, strtoupper($newStatus), $id]);

        // Update Subscription table if provided
        if ($plan_id) {
            // Update the latest subscription regardless of its current status
            $stmt = $db->prepare("UPDATE tenant_subscriptions SET plan_id=?, billing_cycle=?, end_date=?, status=? WHERE tenant_id=?");
            $stmt->execute([$plan_id, $billing, $expiry, strtoupper($newStatus), $id]);
        }

        // Update Users table status to match Tenant status
        $stmt = $db->prepare("UPDATE users SET status=? WHERE tenant_id=?");
        $stmt->execute([strtoupper($newStatus), $id]);

        // Audit Logging
        $verb = strtoupper($newStatus);
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO audit_logs (activity_type, description, created_at) VALUES ('CRUD', ?, ?)");
        $stmt->execute(["Super admin updated tenant: $shopName (ID: $id) status to $verb", $now]);

        // --- GUARANTEED EMAIL NOTIFICATION ---
        $st = strtoupper(trim($newStatus));
        $to = $email;
        $subject = "AutoFix Hub: Account Management Update";
        $color = '#3b82f6';
        $msgBody = "";
        $extraInfo = "";

        // Fetch slug for the link
        $stmt = $db->prepare("SELECT slug FROM tenants WHERE tenant_id = ?");
        $stmt->execute([$id]);
        $slugData = $stmt->fetch();
        $slug = $slugData['slug'] ?? '';
        $shopUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/shop.php?id=" . $slug;

        if (strpos($st, 'ACTIVE') !== false) {
            $color = '#10b981';
            $subject = "Welcome to AutoFix Hub: Account Approved! 🎉";
            $msgBody = "Great news! Your workshop application for <b>" . htmlspecialchars($shopName) . "</b> has been <b>APPROVED</b>. You can now start managing your services, staff, and customers.";
            $extraInfo = "
                <div style='margin-top: 20px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px;'>
                    <h3 style='margin-top:0; color:#166534;'>Access Details:</h3>
                    <p style='margin: 5px 0;'><b>Your Workshop URL:</b> <a href='$shopUrl' style='color:#10b981; font-weight:700;'>$shopUrl</a></p>
                    <p style='margin: 5px 0;'><b>Admin Email:</b> $email</p>
                    <p style='margin: 5px 0;'><b>Password:</b> <i>The password you created during registration</i></p>
                    <a href='http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/login.php?tid=$id' style='display:inline-block; margin-top:15px; padding:10px 20px; background:#10b981; color:white; text-decoration:none; border-radius:8px; font-weight:800;'>Login to Console</a>
                </div>
            ";
        } elseif (strpos($st, 'SUSPEND') !== false) {
            $color = '#ef4444';
            $subject = "AutoFix Hub: Account Suspended";
            $msgBody = "Your workshop account has been <b>SUSPENDED</b>. This is usually due to a verification issue or pending billing concern.";
            $extraInfo = "<p style='color:#ef4444;'>Please contact our support team at support@autofixhub.ph to resolve this matter.</p>";
        } elseif (strpos($st, 'REJECT') !== false) {
            $color = '#ef4444';
            $subject = "AutoFix Hub: Application Rejected";
            $msgBody = "We regret to inform you that your application for <b>" . htmlspecialchars($shopName) . "</b> has been <b>REJECTED</b> after manual review.";
            $extraInfo = "<p>Please ensure your business permits and ID photos are clear and valid before trying again.</p>";
        } else {
            $color = '#f59e0b';
            $msgBody = "Your account status has been updated to: <b>$st</b>. Please contact administration for more details.";
        }

        $message = "<html><body style='font-family: \"Outfit\", Arial, sans-serif; padding: 40px; color: #1e293b; background: #f8fafc;'>";
        $message .= "<div style='max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.05);'>";
        $message .= "<div style='text-align: center; margin-bottom: 30px;'><h1 style='color: #6366f1; margin:0;'>AutoFix <span style='color:#333;'>Hub</span></h1></div>";
        $message .= "<h2 style='color: $color; font-size: 1.5rem; margin-bottom: 20px;'>$subject</h2>";
        $message .= "<p>Hi <b>" . htmlspecialchars($ownerName) . "</b>,</p>";
        $message .= "<div style='font-size: 1.1rem; line-height: 1.6; color: #475569;'>$msgBody</div>";
        $message .= $extraInfo;
        $message .= "<p style='margin-top: 40px; font-size: 0.9rem; color: #94a3b8; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 20px;'>";
        $message .= "&copy; " . date('Y') . " AutoFix Hub Platform. All rights reserved.</p>";
        $message .= "</div></body></html>";

        $emailSent = Mailer::sendHTML($to, $subject, $message);

        echo json_encode([
            'status' => 'success',
            'email_sent' => $emailSent,
            'debug_status' => $st,
            'is_match' => (strpos($st, 'SUSPEND') !== false)
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Removed redundant system_backups logic - unified under backups table


// Ensure audit_logs table has customer_id for monitoring
try {
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        user_id INT NULL,
        customer_id INT NULL,
        activity_type VARCHAR(50),
        description TEXT,
        created_at DATETIME NOT NULL,
        INDEX (tenant_id),
        INDEX (user_id),
        INDEX (customer_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
    $db->exec("ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS customer_id INT NULL AFTER user_id");
} catch (Exception $e) {
}

// Fetch Real Data for JS Seeding
try {
    // Robust check for pending count (handles case sensitivity)
    $pending_tenant_count = $db->query("SELECT COUNT(*) FROM tenants 
                                       WHERE (status IS NULL OR status = '' OR TRIM(UPPER(status)) = 'PENDING')
                                       AND shop_name NOT LIKE '[REJECTED]%'
                                       AND TRIM(LOWER(IFNULL(status, ''))) NOT IN ('rejected', 'archived', 'cancelled')")->fetchColumn() ?: 0;

    $shops_db = $db->query("SELECT t.*, t.status as status, t.shop_name as name, t.owner_name as owner, IFNULL(p.plan_name, 'TRIAL') as planName, s.plan_id as planId, s.end_date as expiry,
                             s.billing_cycle, p.price as monthlyPrice, p.price_yearly as yearlyPrice,
                             (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.tenant_id) as bookings,
                             (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.tenant_id) as customer_count,
                             (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.tenant_id) as staff_count,
                             (SELECT SUM(amount) FROM tenant_payments tp WHERE tp.tenant_id = t.tenant_id AND (UPPER(tp.payment_status) = 'PAID' OR UPPER(tp.payment_status) = 'SUCCESS')) as revenue,
                             (SELECT MAX(created_at) FROM audit_logs al WHERE al.tenant_id = t.tenant_id) as last_activity
                             FROM tenants t 
                             LEFT JOIN tenant_subscriptions s ON s.subscription_id = (SELECT subscription_id FROM tenant_subscriptions WHERE tenant_id = t.tenant_id ORDER BY subscription_id DESC LIMIT 1)
                             LEFT JOIN subscription_plans p ON s.plan_id = p.plan_id 
                             WHERE TRIM(LOWER(IFNULL(t.status, ''))) NOT IN ('rejected', 'archived', 'cancelled')
                             AND t.shop_name NOT LIKE '[REJECTED]%'
                             GROUP BY t.tenant_id
                             ORDER BY t.tenant_id DESC")->fetchAll() ?: [];
} catch (Exception $e) {
    $shops_db = [];
}

try {
    $payments_db = $db->query("SELECT p.*, p.payment_status as status, 
                               IFNULL(t.shop_name, 'Unknown Tenant') as shopName, 
                               t.status as tenantStatus,
                               p.transaction_reference as ref, p.payment_date as date 
                               FROM tenant_payments p 
                               LEFT JOIN tenants t ON p.tenant_id = t.tenant_id 
                               ORDER BY p.payment_date DESC")->fetchAll() ?: [];
} catch (Exception $e) {
    $payments_db = [];
}

try {
    $logs_db = $db->query("SELECT a.*, a.created_at as time, a.activity_type as type, a.description as activity, t.shop_name, u.name as staff_name 
                            FROM audit_logs a 
                            LEFT JOIN tenants t ON a.tenant_id = t.tenant_id 
                            LEFT JOIN users u ON a.user_id = u.user_id
                            ORDER BY a.created_at DESC LIMIT 100")->fetchAll() ?: [];
} catch (Exception $e) {
    $logs_db = [];
}

try {
    $plans_db = $db->query("SELECT *, plan_id as id, plan_name as name, price as monthlyPrice, price_yearly as yearlyPrice, max_users as maxUsers, max_service_bays as maxBays, status FROM subscription_plans ORDER BY price ASC")->fetchAll() ?: [];
} catch (Exception $e) {
    $plans_db = [];
}

try {
    $backups_db = $db->query("SELECT *, backup_id as id, created_at as date, backup_type as type FROM system_backups ORDER BY created_at DESC")->fetchAll() ?: [];
} catch (Exception $e) {
    $backups_db = [];
}

try {
    $admins_db = $db->query("SELECT user_id as id, name, email, status FROM users WHERE role_id = 1 AND (tenant_id IS NULL OR tenant_id = 0)")->fetchAll() ?: [];
} catch (Exception $e) {
    $admins_db = [];
}

try {
    $settings_db = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Exception $e) {
    $settings_db = [];
}

// Special AJAX endpoint for real-time count
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_count') {
    header('Content-Type: application/json');
    try {
        $count = $db->query("SELECT COUNT(*) FROM tenants 
                             WHERE (status IS NULL OR status = '' OR TRIM(UPPER(status)) = 'PENDING')
                             AND shop_name NOT LIKE '[REJECTED]%'
                             AND TRIM(LOWER(IFNULL(status, ''))) NOT IN ('rejected', 'archived', 'cancelled')")->fetchColumn() ?: 0;
        echo json_encode(['count' => (int) $count]);
    } catch (Exception $e) {
        echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
    }
    exit;
}

$user_counts = ['active' => 0, 'inactive' => 0];
try {
    $users_raw = $db->query("SELECT status, COUNT(*) as cnt FROM users GROUP BY status")->fetchAll();
    foreach ($users_raw as $ur) {
        if (strtoupper($ur['status']) === 'ACTIVE')
            $user_counts['active'] += $ur['cnt'];
        else
            $user_counts['inactive'] += $ur['cnt'];
    }
} catch (Exception $e) {
}

// Global Totals for Reports
try {
    $global_appts = $db->query("SELECT COUNT(*) FROM appointments")->fetchColumn() ?: 0;
    $global_custs = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn() ?: 0;
    $global_logs = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn() ?: 0;

    $cust_raw = $db->query("SELECT status, COUNT(*) as cnt FROM customers GROUP BY status")->fetchAll();
    foreach ($cust_raw as $cr) {
        if (strtoupper($cr['status']) === 'ACTIVE')
            $user_counts['active'] += $cr['cnt'];
        else
            $user_counts['inactive'] += $cr['cnt'];
    }

    $logs_activity = $db->query("SELECT SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as daily, SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as monthly FROM audit_logs")->fetch();
    if (!$logs_activity)
        $logs_activity = ['daily' => 0, 'monthly' => 0];

    $logs_activity['global_appts'] = $global_appts;
    $logs_activity['global_custs'] = $global_custs;
    $logs_activity['global_logs'] = $global_logs;
    $logs_activity['manila_today'] = date('Y-m-d');
} catch (Exception $e) {
    $logs_activity = ['daily' => 0, 'monthly' => 0, 'global_appts' => 0, 'global_custs' => 0, 'global_logs' => 0, 'manila_today' => date('Y-m-d')];
}

// Daily Trends for Dashboard Charts (Last 14 Days)
$dashboard_trends = [];
try {
    $dashboard_trends = $db->query("
        SELECT d.date, 
               (SELECT COUNT(*) FROM tenants t WHERE DATE(t.created_at) = d.date) as new_tenants,
               (SELECT IFNULL(SUM(amount), 0) FROM tenant_payments tp WHERE DATE(tp.payment_date) = d.date AND (UPPER(tp.payment_status) = 'PAID' OR UPPER(tp.payment_status) = 'SUCCESS')) as sales,
               (SELECT COUNT(*) FROM audit_logs al WHERE DATE(al.created_at) = d.date) as activities
        FROM (
            SELECT CURDATE() - INTERVAL t.n DAY as date
            FROM (SELECT 0 as n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14) t
        ) d
        ORDER BY d.date ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $dashboard_trends = [];
}

// Retrieve active superadmin user details
$session_user_id = $_SESSION['user_id'] ?? 1;
try {
    $admin_data = $db->prepare("SELECT name, email, avatar_url FROM users WHERE user_id = ?");
    $admin_data->execute([$session_user_id]);
    $admin_row = $admin_data->fetch();
} catch (Exception $e) {
    $admin_row = null;
}
$admin_name = $admin_row['name'] ?? $_SESSION['name'] ?? 'Main Admin';
$admin_avatar = $admin_row['avatar_url'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <style>
        /* Premium Native Date Picker Styling */
        input[type="date"] {
            color-scheme: var(--date-picker-scheme);
            cursor: pointer;
            border: 1px solid var(--glass-border);
            background: var(--input-bg);
            color: var(--date-picker-color);
            transition: 0.3s;
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
        }

        input[type="date"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 10px var(--accent-glow);
            background: var(--input-bg);
            outline: none;
        }

        /* Force Native Calendar Icon to High Visibility */
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: var(--date-picker-scheme)==='light' ? invert(0): invert(0);
            cursor: pointer;
            opacity: 0.7;
            transition: 0.2s;
        }

        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        /* Make the placeholder text visible */
        input[type="date"]::-webkit-datetime-edit {
            color: var(--date-picker-color);
        }

        input[type="date"]::-webkit-datetime-edit-fields-wrapper {
            color: var(--date-picker-color);
        }

        input[type="date"]::-webkit-datetime-edit-text {
            color: var(--text-dim);
            opacity: 0.5;
        }

        input[type="date"]::-webkit-datetime-edit-month-field,
        input[type="date"]::-webkit-datetime-edit-day-field,
        input[type="date"]::-webkit-datetime-edit-year-field {
            color: var(--date-picker-color);
        }

        :root {
            --bg-deep: #030712;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.4);
            --gradient: linear-gradient(135deg, #6366f1, #312e81);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
            --error: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --info: #3b82f6;
            --card-bg: rgba(255, 255, 255, 0.03);
            --input-bg: rgba(0, 0, 0, 0.2);
            --modal-bg: rgba(15, 23, 42, 0.95);
            --sidebar-bg: rgba(0, 0, 0, 0.2);
            --scrollbar-thumb: rgba(255, 255, 255, 0.15);
            --scrollbar-track: rgba(0, 0, 0, 0.3);
            --date-picker-color: #ffffff;
            --date-picker-scheme: dark;
        }



        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        /* Modern translucent scrollbars with improved visibility */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
            transition: background 0.3s;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }

        body {
            background-color: var(--bg-deep);
            color: var(--text-main);
            min-height: 100vh;
            display: grid;
            grid-template-columns: 280px 1fr;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.2) rgba(0, 0, 0, 0.3);
        }

        body.sidebar-collapsed {
            grid-template-columns: 85px 1fr;
        }

        /* Sidebar Styling */
        .sidebar {
            background: var(--sidebar-bg);
            border-right: 1px solid var(--glass-border);
            padding: 0;
            display: flex;
            flex-direction: column;
            backdrop-filter: blur(20px);
            position: sticky;
            top: 0;
            height: 100vh;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 5000;
            overflow: visible;
        }

        .sidebar-inner {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
            padding: 2.5rem 1.5rem;
            overflow-y: auto;
            scrollbar-width: none; /* Hide scrollbar for Firefox */
            -ms-overflow-style: none;  /* Hide scrollbar for IE and Edge */
        }

        /* Hide scrollbar for Chrome, Safari and Opera */
        .sidebar-inner::-webkit-scrollbar {
            display: none;
        }

        /* Sidebar Trigger (Floating Arrow) */
        .sidebar-trigger {
            position: absolute;
            right: -14px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            background: var(--accent);
            border: 1px solid var(--glass-border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            cursor: pointer;
            z-index: 5001;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }

        .sidebar-trigger:hover {
            transform: translateY(-50%) scale(1.15);
            background: #fff;
            box-shadow: 0 0 15px var(--accent);
        }

        .sidebar-trigger i {
            transition: transform 0.3s ease;
        }

        body.sidebar-collapsed .sidebar-trigger i {
            transform: rotate(180deg);
        }

        /* Hide labels in collapsed state */
        body.sidebar-collapsed .nav-label,
        body.sidebar-collapsed .brand-logo span,
        body.sidebar-collapsed .badge-pending,
        body.sidebar-collapsed .admin-profile-details {
            display: none !important;
        }

        body.sidebar-collapsed .sidebar {
            padding: 0;
        }

        body.sidebar-collapsed .sidebar-inner {
            padding: 2.5rem 0.5rem;
            align-items: center;
        }

        body.sidebar-collapsed .brand-logo {
            padding-left: 0;
            justify-content: center;
            display: flex;
            font-size: 1.2rem;
            margin-bottom: 1.5rem !important;
        }

        body.sidebar-collapsed .admin-sidebar-profile {
            padding: 0 !important;
            justify-content: center;
            margin-bottom: 2rem !important;
        }

        body.sidebar-collapsed .nav-item {
            justify-content: center;
            padding: 0.9rem 0;
            width: 50px;
        }

        body.sidebar-collapsed .nav-item i {
            font-size: 1.25rem;
            margin: 0;
        }

        .brand-logo {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            padding-left: 1rem;
        }

        .brand-logo span {
            color: var(--accent);
        }

        .nav-menu {
            flex: 1;
            list-style: none;
        }

        .nav-item {
            padding: 0.9rem 1.25rem;
            border-radius: 12px;
            color: var(--text-dim);
            text-decoration: none;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-item:hover {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent);
        }

        .nav-item.active {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .badge-pending {
            background: #ff4757;
            color: white;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 900;
            margin-left: auto;
            box-shadow: 0 0 15px rgba(255, 71, 87, 0.6);
            animation: pulse-badge 1.5s infinite;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .notif-dot {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            background: #ff4757;
            border-radius: 50%;
            border: 2px solid #1a1a1a;
            box-shadow: 0 0 10px #ff4757;
            display: none;
        }

        @keyframes pulse-badge {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0px rgba(255, 71, 87, 0.7);
            }

            50% {
                transform: scale(1.15);
                box-shadow: 0 0 15px rgba(255, 71, 87, 0.9);
            }

            100% {
                transform: scale(1);
                box-shadow: 0 0 0px rgba(255, 71, 87, 0.7);
            }
        }

        /* Verification Dossier Styles */
        .dossier-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 1.5rem;
        }

        .dossier-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 1.5rem;
        }

        .dossier-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }

        .dossier-img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 12px;
            cursor: zoom-in;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: 0.3s;
        }

        .dossier-img:hover {
            border-color: var(--accent);
            transform: scale(1.02);
        }

        .dossier-info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .dossier-info-val {
            font-weight: 700;
            color: var(--text-main);
        }

        /* Main Content */
        .main-content {
            padding: 3.5rem;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        .view-section {
            display: none;
            animation: fadeIn 0.4s ease-out;
        }

        .view-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-10px);
            }

            100% {
                transform: translateY(0px);
            }
        }

        @keyframes glow {
            0% {
                box-shadow: 0 0 20px var(--accent-glow);
            }

            50% {
                box-shadow: 0 0 40px var(--accent-glow);
            }

            100% {
                box-shadow: 0 0 20px var(--accent-glow);
            }
        }

        /* Shared Dashboard Components */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            padding: 1.8rem;
            border-radius: 28px;
            position: relative;
            overflow: hidden;
            transition: 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.8), 0 0 40px -10px rgba(99, 102, 241, 0.2);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
            opacity: 0.3;
            z-index: -1;
        }

        .stat-icon {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            font-size: 1.6rem;
            color: var(--accent);
            opacity: 0.9;
            filter: drop-shadow(0 0 10px var(--accent));
            z-index: 10;
        }

        .stat-label {
            color: var(--text-dim);
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 10;
            position: relative;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--text-main);
            display: flex;
            align-items: baseline;
            gap: 8px;
            letter-spacing: -1px;
            z-index: 10;
            position: relative;
        }

        .glass-panel {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 1rem;
            color: var(--text-dim);
            font-size: 0.75rem;
            text-transform: uppercase;
            border-bottom: 1px solid var(--glass-border);
        }

        .data-table td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--glass-border);
            font-size: 0.9rem;
        }

        /* Badges - Enhanced Visibility */
        .badge {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            border: 1px solid transparent;
        }

        .badge-active {
            background: #059669;
            color: white;
            border-color: #10b981;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }

        .badge-error {
            background: #dc2626;
            color: white;
            border-color: #ef4444;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }

        .badge-warning {
            background: #d97706;
            color: white;
            border-color: #f59e0b;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            animation: pulse-border 2s infinite;
        }

        .badge-info {
            background: #2563eb;
            color: white;
            border-color: #3b82f6;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }

        @keyframes pulse-border {
            0% {
                border-color: rgba(245, 158, 11, 0.5);
            }

            50% {
                border-color: #f59e0b;
            }

            100% {
                border-color: rgba(245, 158, 11, 0.5);
            }
        }

        /* Controls */
        .btn-action {
            background: var(--accent);
            color: white;
            border: none;
            padding: 0.7rem 1.2rem;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.85rem;
        }

        .btn-action:hover {
            background: #4f46e5;
            transform: translateY(-2px);
        }

        .search-container {
            margin-bottom: 2.5rem;
            display: flex;
            gap: 1rem;
            align-items: stretch;
        }

        .search-input {
            flex: 1;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            padding: 1.1rem 1.5rem;
            border-radius: 18px;
            color: var(--text-main);
            outline: none;
            transition: 0.3s;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .search-input:focus {
            border-color: var(--accent);
            background: var(--input-bg);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .search-input option {
            background: var(--bg-deep);
            color: var(--text-main);
            padding: 10px;
        }

        .premium-table-card {
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: var(--input-bg);
        }

        .data-table th {
            text-align: left;
            padding: 1.5rem;
            color: var(--text-dim);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 800;
            border-bottom: 1px solid var(--glass-border);
        }

        .data-table td {
            padding: 1.8rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 0.95rem;
            vertical-align: middle;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background: var(--glass);
        }

        /* Modals */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(3, 7, 18, 0.85);
            /* Slightly translucent for both */
            backdrop-filter: blur(16px);
            z-index: 2000;
            display: none;
            justify-content: center;
            align-items: flex-start;
            padding: 4rem 1.5rem;
            overflow-y: auto;
        }



        .modal-card {
            background: var(--modal-bg);
            border: 1px solid var(--glass-border);
            border-radius: 30px;
            width: 100%;
            max-width: 650px;
            padding: 3rem 2.5rem;
            margin: auto;
            position: relative;
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.5), 0 0 80px -30px var(--accent-glow);
            animation: modalSlideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(28px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: var(--accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.4rem;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 0 30px rgba(99, 102, 241, 0.5);
        }

        .btn-close-modal {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.4);
            font-size: 1.8rem;
            cursor: pointer;
            line-height: 1;
            transition: 0.2s;
        }

        .btn-close-modal:hover {
            color: white;
        }

        .form-group {
            margin-bottom: 1.4rem;
            text-align: left;
        }

        .form-group label,
        .form-label-top {
            display: block;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.7rem;
            letter-spacing: 0.02em;
        }

        .form-group-inline {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .form-group-inline label {
            min-width: 70px;
        }

        .modern-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            color: var(--text-main);
            padding: 1.2rem 1.5rem;
            border-radius: 16px;
            font-size: 0.97rem;
            outline: none;
            transition: 0.3s;
        }

        .modern-input:focus {
            background-color: var(--input-bg);
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        select.modern-input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1.2rem center;
            background-size: 1.1rem;
            padding-right: 3rem;
        }

        /* Password Toggle Support */
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .toggle-password {
            position: absolute;
            right: 1.2rem;
            color: var(--text-dim);
            cursor: pointer;
            z-index: 10;
            transition: 0.3s;
        }

        .toggle-password:hover {
            color: var(--accent);
        }

        .modern-input::placeholder {
            color: var(--text-dim);
            opacity: 0.5;
        }

        .modern-input option {
            background: var(--bg-deep);
            color: var(--text-main);
        }

        .modal-footer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
            margin-top: 2rem;
        }

        .btn-white {
            background: var(--input-bg);
            color: var(--text-main);
            border: 1px solid var(--glass-border);
            padding: 1.2rem;
            border-radius: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.3s;
            font-size: 1rem;
        }

        .btn-white:hover {
            background: var(--glass);
        }

        .btn-gradient {
            background: var(--gradient);
            color: #ffffff !important;
            border: none;
            padding: 1.2rem;
            border-radius: 100px;
            font-weight: 900;
            cursor: pointer;
            transition: 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 1rem;
            box-shadow: 0 10px 30px var(--accent-glow);
            animation: glow 4s infinite;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-gradient:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px var(--accent-glow);
            opacity: 0.9;
        }

        .input-with-prefix {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-prefix {
            position: absolute;
            left: 1.3rem;
            color: var(--text-dim);
            font-size: 0.95rem;
            z-index: 5;
        }

        .modern-input.has-prefix {
            padding-left: 3.2rem;
        }

        /* Analytics */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-box {
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2rem;
            height: 350px;
            position: relative;
        }

        /* Log Specifics */
        .log-type-crud {
            color: var(--info);
        }

        .log-type-auth {
            color: var(--warning);
        }

        .log-type-security {
            color: var(--error);
            font-weight: 800;
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .plan-card {
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            padding: 3rem;
            transition: 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .plan-card:hover {
            transform: translateY(-12px);
            border-color: rgba(99, 102, 241, 0.4);
            background: rgba(255, 255, 255, 0.03);
            box-shadow: 0 40px 80px -20px rgba(0, 0, 0, 0.6);
        }

        .plan-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: var(--gradient);
            opacity: 0.5;
        }

        .plan-price {
            font-size: 2.8rem;
            font-weight: 900;
            margin: 2rem 0;
            color: var(--text-main);
            letter-spacing: -2px;
        }

        .plan-price span {
            font-size: 1rem;
            color: var(--text-dim);
            font-weight: 500;
            letter-spacing: 0;
        }

        .plan-features {
            list-style: none;
            margin-bottom: 3rem;
            flex: 1;
        }

        .plan-features li {
            font-size: 0.95rem;
            color: var(--text-dim);
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .plan-features li i {
            color: var(--accent);
            font-size: 0.8rem;
            background: rgba(99, 102, 241, 0.1);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pricing-switcher {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            padding: 6px;
            border-radius: 100px;
            display: flex;
            position: relative;
            width: 320px;
            margin: 0 auto 4rem;
            cursor: pointer;
        }

        .switch-option {
            flex: 1;
            text-align: center;
            padding: 12px 0;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-dim);
            z-index: 2;
            transition: 0.3s;
            position: relative;
        }

        .switch-option.active {
            color: black;
        }

        .switch-slider {
            position: absolute;
            top: 6px;
            left: 6px;
            width: calc(50% - 6px);
            height: calc(100% - 12px);
            background: white;
            border-radius: 100px;
            transition: 0.5s cubic-bezier(0.18, 0.89, 0.32, 1.28);
            z-index: 1;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .pricing-switcher.is-yearly .switch-slider {
            transform: translateX(100%);
        }

        /* Fix Select Options Customization in Dark Mode */
        select option {
            background-color: #0f172a;
            color: white;
            font-weight: 500;
            font-size: 1rem;
        }

        /* Differentiated Sales Card Design */
        .sales-stats-new-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .sales-stats-sub-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .sales-premium-card {
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            border-left: 4px solid var(--accent);
            border-radius: 20px;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-main);
            transition: transform 0.2s, background 0.2s;
        }

        .sales-premium-card:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-2px);
        }

        .sales-premium-card .card-content {
            flex: 1;
        }

        .sales-premium-card .p-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-dim);
            margin-bottom: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sales-premium-card .p-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .sales-premium-card .p-icon-box {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--accent);
            opacity: 0.8;
            background: var(--accent-glow);
        }

        /* Card Specific Accents */
        .card-blue {
            border-left-color: #3b82f6;
        }

        .card-green {
            border-left-color: #10b981;
        }

        .card-orange {
            border-left-color: #f59e0b;
        }

        .card-purple {
            border-left-color: #818cf8;
        }

        .text-blue {
            color: #3b82f6 !important;
        }

        .text-green {
            color: #10b981 !important;
        }

        .text-orange {
            color: #f59e0b !important;
        }

        .text-purple {
            color: #818cf8 !important;
        }

        /* Announcement Bookmark Styling */
        .announcement-puller {
            position: fixed;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000002;
            cursor: pointer;
            transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .announcement-puller:hover {
            top: 2px;
        }

        .bookmark-tab {
            background: var(--accent);
            color: white;
            padding: 8px 18px;
            border-radius: 0 0 15px 15px;
            font-weight: 800;
            font-size: 0.65rem;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.4);
            text-transform: uppercase;
            position: relative;
        }

        .announcement-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(12px);
            z-index: 1000000;
            display: none;
            opacity: 0;
            transition: 0.4s;
        }

        .announcement-panel {
            position: fixed;
            top: -180vh;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 550px;
            background: var(--modal-bg);
            border: 1px solid var(--glass-border);
            border-top: none;
            border-radius: 0 0 32px 32px;
            padding: 2.5rem;
            z-index: 1000001;
            transition: 0.8s cubic-bezier(0.23, 1, 0.32, 1);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(15px);
        }

        .announcement-panel.active {
            top: 0 !important;
        }

        .announcement-overlay.active {
            display: block;
            opacity: 1;
        }
    </style>
</head>

<body>

    <aside class="sidebar">
        <!-- Sidebar Toggle -->
        <div class="sidebar-trigger" onclick="window.toggleSidebar()">
            <i class="fas fa-chevron-left"></i>
        </div>

        <div class="sidebar-inner">
            <div class="brand-logo">AutoFix <span>Hub</span></div>

            <!-- Super Admin Profile Widget -->
            <div class="admin-sidebar-profile" style="padding: 0 1rem; margin-bottom: 2.5rem; display: flex; align-items: center; gap: 12px; transition: all 0.3s ease;">
                <div class="admin-avatar-container" style="position: relative; flex-shrink: 0; width: 48px; height: 48px; border-radius: 50%; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.1); box-shadow: 0 4px 15px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--accent), #6366f1);">
                    <?php if ($admin_avatar): ?>
                        <img src="<?php echo htmlspecialchars($admin_avatar); ?>" id="sidebarAdminAvatar" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <span id="sidebarAdminInitials" style="font-weight: 800; font-size: 1.1rem; color: white;"><?php echo mb_strtoupper(mb_substr($admin_name, 0, 1)); ?></span>
                    <?php endif; ?>
                    <div class="status-dot" style="position: absolute; bottom: 2px; right: 2px; width: 10px; height: 10px; border-radius: 50%; background: #10b981; border: 2px solid var(--sidebar-bg); box-shadow: 0 0 10px #10b981;"></div>
                </div>
                <div class="admin-profile-details" style="min-width: 0; flex: 1;">
                    <h4 id="sidebarAdminName" style="margin: 0; font-size: 0.95rem; font-weight: 800; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($admin_name); ?></h4>
                    <p style="margin: 2px 0 0 0; font-size: 0.72rem; font-weight: 600; color: var(--accent); text-transform: uppercase; letter-spacing: 0.5px;">Super Admin</p>
                </div>
            </div>

            <nav class="nav-menu">
                <div class="nav-item active" data-view="dashboard">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span class="nav-label">Dashboard</span>
                </div>

                <div class="nav-item" data-view="shops"
                    style="display:flex; align-items:center; justify-content:space-between; width:100%;">
                    <div style="display:flex; align-items:center; gap:12px; position:relative;">
                        <div style="position:relative;">
                            <i class="fa-solid fa-building-user"></i>
                            <div id="sidebar-notif-dot" class="notif-dot"
                                style="display: <?php echo ($pending_tenant_count > 0) ? 'block' : 'none'; ?>;"></div>
                        </div>
                        <span class="nav-label">Tenant Management</span>
                    </div>
                    <span id="sidebar-pending-badge" class="badge-pending"
                        style="display: <?php echo ($pending_tenant_count > 0) ? 'block' : 'none'; ?>;">
                        <?php echo $pending_tenant_count; ?>
                    </span>
                </div>

                <div class="nav-item" data-view="plans">
                    <i class="fa-solid fa-credit-card"></i>
                    <span class="nav-label">Subscriptions</span>
                </div>
                <div class="nav-item" data-view="payments">
                    <i class="fa-solid fa-sack-dollar"></i>
                    <span class="nav-label">Sales Report</span>
                </div>
                <div class="nav-item" data-view="price_standards">
                    <i class="fa-solid fa-tags"></i>
                    <span class="nav-label">Price Standards</span>
                </div>
                <div class="nav-item" data-view="reports">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="nav-label">Reports</span>
                </div>
                <div class="nav-item" data-view="logs">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span class="nav-label">Audit Logs</span>
                </div>
                <div class="nav-item" data-view="backup">
                    <i class="fa-solid fa-database"></i>
                    <span class="nav-label">Backup</span>
                </div>
                <div class="nav-item" data-view="settings">
                    <i class="fa-solid fa-gears"></i>
                    <span class="nav-label">Settings</span>
                </div>

                <div class="nav-item" data-view="chat"
                    style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; border-top:1px solid var(--glass-border); padding-top:1.5rem;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <i class="fa-solid fa-headset"></i>
                        <span class="nav-label">Chat Support</span>
                    </div>
                    <span id="globalChatBadge"
                        style="display:none; background:var(--error); color:white; font-size:0.6rem; padding:2px 6px; border-radius:10px;">0</span>
                </div>
            </nav>


            <div class="nav-item" id="logoutBtn"
                style="color: var(--error); margin-top: 0.5rem; display:flex; align-items:center; gap:12px;">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span class="nav-label">Logout Account</span>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <!-- Dashboard & Analytics -->
        <div id="dashboard" class="view-section active">
            <h1 style="margin-bottom: 2.5rem;">Platform Analytics</h1>
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fa-solid fa-store stat-icon"></i>
                    <p class="stat-label">Total Tenants</p>
                    <div class="stat-value" id="stat-shops">0</div>
                    <div
                        style="margin-top: 15px; font-size: 0.8rem; color: var(--success); font-weight:700; position:relative; z-index:10;">
                        <i class="fa-solid fa-arrow-trend-up"></i> Growing platform
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fa-solid fa-users stat-icon"
                        style="color:var(--info); filter: drop-shadow(0 0 10px rgba(59,130,246,0.6));"></i>
                    <p class="stat-label">Active / Inactive Users</p>
                    <div class="stat-value">
                        <span id="stat-active-users" style="color:var(--success);">0</span>
                        <span
                            style="color:var(--glass-border); font-size:1.6rem; font-weight:300; margin:0 12px;">/</span>
                        <span id="stat-inactive-users" style="color:var(--error);">0</span>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fa-solid fa-bolt stat-icon"
                        style="color:var(--warning); filter: drop-shadow(0 0 10px rgba(245,158,11,0.6));"></i>
                    <p class="stat-label">Daily / Monthly Log</p>
                    <div class="stat-value">
                        <span id="stat-daily-act" style="color:var(--text-main);">0</span>
                        <span
                            style="color:var(--glass-border); font-size:1.6rem; font-weight:300; margin:0 12px;">/</span>
                        <span id="stat-monthly-act" style="color:var(--warning);">0</span>
                    </div>
                </div>
                <div class="stat-card"
                    style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), var(--glass)); border-color: rgba(16, 185, 129, 0.3);">
                    <i class="fa-solid fa-wallet stat-icon"
                        style="color:var(--success); filter: drop-shadow(0 0 10px rgba(16,185,129,0.8));"></i>
                    <p class="stat-label" style="color:var(--text-dim);">System Revenue</p>
                    <div class="stat-value" id="stat-revenue" style="color:var(--text-main); font-size: 2.2rem;">₱0
                    </div>
                </div>
            </div>

            <div class="chart-grid" style="grid-template-columns: 2fr 1fr;">
                <div class="chart-box" id="salesContainer" style="height: 380px;">
                    <div class="chart-loader"
                        style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--text-dim); font-size:0.8rem;">
                        Loading Sales...</div>
                    <canvas id="salesTrendsChart"></canvas>
                </div>
                <div class="chart-box" id="activityContainer" style="height: 380px;">
                    <div class="chart-loader"
                        style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--text-dim); font-size:0.8rem;">
                        Loading Activity...</div>
                    <canvas id="tenantActivityChart"></canvas>
                </div>
            </div>
            <div class="chart-grid" style="grid-template-columns: 1fr; margin-top: -0.5rem;">
                <div class="chart-box" id="growthContainer"
                    style="height: 320px; box-shadow: inset 0 0 30px rgba(99,102,241,0.05);">
                    <div class="chart-loader"
                        style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--text-dim); font-size:0.8rem;">
                        Loading Growth...</div>
                    <canvas id="userGrowthChart"></canvas>
                </div>
            </div>
        </div>

        <div id="shops" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:3rem;">
                <div>
                    <h1 style="font-size: 2.5rem; letter-spacing: -1.5px; font-weight: 900; margin-bottom: 0.5rem;">
                        Network Hub</h1>
                    <p style="color: var(--text-dim); font-size: 1.1rem;">Manage, verify, and monitor your multi-tenant
                        ecosystem.</p>
                </div>
                <button class="btn-gradient" style="padding: 1rem 2.5rem;" onclick="openShopModal()">+ Register New
                    Hub</button>
            </div>

            <!-- New: Action Bar for Pending Requests -->
            <div id="pendingAlertSection"
                style="display:none; background: linear-gradient(90deg, rgba(245, 158, 11, 0.1), transparent); border-left: 4px solid var(--warning); padding: 1.5rem 2rem; border-radius: 20px; margin-bottom: 2.5rem; justify-content: space-between; align-items: center;">
                <div style="display:flex; align-items:center; gap:2.5rem;">
                    <div
                        style="width:80px; height:80px; border-radius:50%; background:rgba(245, 158, 11, 0.1); display:flex; align-items:center; justify-content:center; font-size:2rem; color:var(--warning); border:1px solid rgba(245, 158, 11, 0.2); box-shadow: 0 0 30px rgba(245, 158, 11, 0.1);">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.4rem; font-weight: 900; margin-bottom: 0.3rem;">Pending Verification
                            Requests
                        </h3>
                        <p style="color: var(--text-dim); font-size: 0.95rem;">There are <b id="pendingCountText"
                                style="color:var(--warning);">0</b> new tenants awaiting your manual business proof
                            review.</p>
                    </div>
                </div>
                <button class="btn-gradient"
                    style="background:var(--warning); color:#000; font-weight:900; padding: 0.8rem 2rem; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.3);"
                    onclick="reviewPendingDossiers()">Review Requests</button>
            </div>

            <div class="search-container">
                <div style="position:relative; flex:1;">
                    <i class="fa-solid fa-magnifying-glass"
                        style="position:absolute; left:1.5rem; top:50%; transform:translateY(-50%); color:var(--text-dim);"></i>
                    <input type="text" class="search-input" style="padding-left:3.5rem; width:100%;" id="shopSearch"
                        placeholder="Search by Shop Name, Owner, or Email..." onkeyup="refreshUI()">
                </div>
                <select class="search-input" style="flex:0.3; cursor:pointer;" id="shopSortOrder"
                    onchange="renderShops()">
                    <option value="newest">Sort: Newest First</option>
                    <option value="oldest">Sort: Oldest First</option>
                    <option value="customers">Sort: Highest User Base</option>
                    <option value="bookings">Sort: Most Bookings</option>
                    <option value="revenue">Sort: Highest Revenue</option>
                </select>
            </div>

            <!-- Enhanced Shop List -->
            <div class="premium-table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Hub Profile</th>
                            <th>Active Subscription</th>
                            <th style="text-align:center;">Engagement</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:right;">Control Center</th>
                        </tr>
                    </thead>
                    <tbody id="shopsTableBody"></tbody>
                </table>
            </div>
        </div>

        <div id="price_standards" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="font-size: 2.2rem; letter-spacing: -1px; font-weight: 800;">Global Price Standards</h1>
                    <p style="color: var(--text-dim);">Set price ceilings and floors for services to ensure
                        platform-wide consistency.</p>
                </div>
                <button class="btn-gradient" onclick="openMasterServiceModal()">+ Add Standard Service</button>
            </div>

            <div class="premium-table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Category</th>
                            <th>Min Price</th>
                            <th>Max Price</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="masterServicesTableBody">
                        <!-- Filled by JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 1.2: Subscriptions -->
        <div id="plans" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <h1>Subscription Tiers</h1>
                <button class="btn-action" onclick="openPlanModal()">+ New Tier</button>
            </div>
            <div class="pricing-switcher" id="pricingSwitcher" onclick="togglePricing()">
                <div class="switch-slider"></div>
                <div class="switch-option active" id="opt-monthly">Monthly Billing</div>
                <div class="switch-option" id="opt-yearly">Yearly <span
                        style="color:var(--success); font-size:0.75rem; background:rgba(16, 185, 129, 0.1); padding:2px 8px; border-radius:100px; margin-left:5px;">-25%</span>
                </div>
            </div>
            <div class="plans-grid" id="plansGrid"></div>
        </div>

        <!-- Section: Reports -->
        <div id="reports" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main);">System Reports</h1>
                    <p style="color:var(--text-dim);">Generate and export tenant activity, user registrations, and
                        system usage.</p>
                </div>
                <div style="display:flex; gap:12px;">
                    <select id="reportFilterTenant" onchange="renderReports()"
                        style="background: var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding: 0.8rem 1rem; border-radius:12px; font-family:inherit; cursor:pointer;">
                        <option value="all">All Management Hubs</option>
                    </select>
                    <select id="reportFilterDate" onchange="renderReports()"
                        style="background: var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding: 0.8rem 1rem; border-radius:12px; font-family:inherit; cursor:pointer;">
                        <option value="all">All Time</option>
                        <option value="30">Last 30 Days</option>
                        <option value="7">Last 7 Days</option>
                        <option value="today">Today</option>
                    </select>
                    <div style="display:flex; gap:0.8rem; align-items:center;">
                        <button class="btn-action"
                            style="background:#ef4444; color:var(--text-main); border-radius:30px; padding:0.8rem 2.2rem; border:none; font-weight:800; display:flex; align-items:center; gap:10px; transition: 0.3s; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);"
                            onmouseover="this.style.transform='translateY(-2px)';"
                            onmouseout="this.style.transform='translateY(0)';" onclick="downloadReportsPDF()">
                            <i class="fa-solid fa-file-pdf"></i> EXPORT PDF
                        </button>
                    </div>
                </div>
            </div>

            <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom:3rem;">
                <div class="stat-card"
                    style="border-left: 4px solid var(--accent); padding: 2.5rem; background: rgba(255,255,255,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <p class="stat-label"
                                style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.7rem; color:var(--text-dim);">
                                New Registrations</p>
                            <div class="stat-value" id="reportStatUsers"
                                style="font-size:2.5rem; margin:1rem 0; color:var(--text-main);">0
                            </div>
                        </div>
                        <div
                            style="width:45px; height:45px; background:rgba(99,102,241,0.1); border-radius:12px; display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
                            <i class="fa-solid fa-users-line"></i>
                        </div>
                    </div>
                    <div style="font-size:0.8rem; color:var(--text-dim); display:flex; align-items:center; gap:6px;">
                        <span style="color:var(--success);"><i class="fa-solid fa-arrow-trend-up"></i> Platform</span>
                        Growth customers
                    </div>
                </div>
                <div class="stat-card"
                    style="border-left: 4px solid var(--warning); padding: 2.5rem; background: rgba(255,255,255,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <p class="stat-label"
                                style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.7rem; color:var(--text-dim);">
                                System Interactions</p>
                            <div class="stat-value" id="reportStatLogs"
                                style="font-size:2.5rem; margin:1rem 0; color:var(--text-main);">0</div>
                        </div>
                        <div
                            style="width:45px; height:45px; background:rgba(245,158,11,0.1); border-radius:12px; display:flex; align-items:center; justify-content:center; color:var(--warning); font-size:1.2rem;">
                            <i class="fa-solid fa-bolt"></i>
                        </div>
                    </div>
                    <div id="reportInteractionPeriod" style="font-size:0.8rem; color:var(--text-dim);">Real-time
                        filtered timeframe</div>
                </div>
                <div class="stat-card"
                    style="border-left: 4px solid var(--success); padding: 2.5rem; background: rgba(255,255,255,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <p class="stat-label"
                                style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.7rem; color:var(--text-dim);">
                                Active Actions</p>
                            <div class="stat-value" id="reportStatAppointments"
                                style="font-size:2.5rem; margin:1rem 0; color:var(--text-main);">0</div>
                        </div>
                        <div
                            style="width:45px; height:45px; background:rgba(16,185,129,0.1); border-radius:12px; display:flex; align-items:center; justify-content:center; color:var(--success); font-size:1.2rem;">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                    </div>
                    <div style="font-size:0.8rem; color:var(--text-dim);">Total tenant service actions</div>
                </div>
            </div>

            <div class="glass-panel" style="padding:1.5rem;">
                <h3 style="color:var(--text-main); margin-bottom:0.5rem;">Tenant Activity Report</h3>
                <p style="color:var(--text-dim); font-size:0.9rem; margin-bottom:1.5rem;">Per-tenant breakdown of
                    members, users, revenue, and last activity</p>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Shop Name</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Status</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Users</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Members</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Revenue</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Last Activity</th>
                                <th
                                    style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">
                                    Joined</th>
                            </tr>
                        </thead>
                        <tbody id="reportsTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="glass-panel" style="margin-bottom:2rem; padding:2rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                    <h3 style="color:var(--text-main); font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                        <i class="fa-solid fa-chart-area" style="color:var(--accent);"></i> Platform Usage &
                        Registration Trends</h3>
                    <div style="font-size:0.8rem; color:var(--text-dim);">Last 14 days activity visualization</div>
                </div>
                <div style="height:320px;"><canvas id="systemUsageTrendsChart"></canvas></div>
            </div>
        </div>

        <!-- Section 1.5: Sales Report -->
        <div id="payments" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main);">Sales Report</h1>
                    <p style="color:var(--text-dim);">Detailed performance analytics and transaction records.</p>
                </div>
            </div>

            <div class="sales-stats-sub-grid">
                <div class="sales-premium-card card-orange">
                    <div class="card-content">
                        <div class="p-label">Today's Sales</div>
                        <div class="p-value" id="stat-today-revenue" style="color:#f59e0b;">₱0.00</div>
                    </div>
                    <div class="p-icon-box" style="background:rgba(245,158,11,0.1); color:#f59e0b;"><i
                            class="fa-solid fa-calendar-day"></i></div>
                </div>
                <div class="sales-premium-card card-blue">
                    <div class="card-content">
                        <div class="p-label">This Week</div>
                        <div class="p-value" id="stat-week-revenue" style="color:#3b82f6;">₱0.00</div>
                    </div>
                    <div class="p-icon-box" style="background:rgba(59,130,246,0.1); color:#3b82f6;"><i
                            class="fa-solid fa-calendar-week"></i></div>
                </div>
                <div class="sales-premium-card card-purple">
                    <div class="card-content">
                        <div class="p-label" id="label-month-rev">This Month</div>
                        <div class="p-value" id="stat-month-revenue" style="color:#818cf8;">₱0.00</div>
                    </div>
                    <div class="p-icon-box" style="background:rgba(129,140,248,0.1); color:#818cf8;"><i
                            class="fa-solid fa-calendar-days"></i></div>
                </div>
                <div class="sales-premium-card card-green">
                    <div class="card-content">
                        <div class="p-label" id="label-total-rev">Total Revenue</div>
                        <div class="p-value" id="stat-total-revenue" style="color:#10b981;">₱0.00</div>
                    </div>
                    <div class="p-icon-box" style="background:rgba(16,185,129,0.1); color:#10b981;"><i
                            class="fa-solid fa-vault"></i></div>
                </div>
            </div>

            <!-- New Insights Row -->
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.5rem; margin-bottom:2rem;">
                <div class="glass-panel" style="margin-bottom:0; padding:1.5rem; position:relative;">
                    <h3
                        style="color:var(--text-main); margin-bottom:1.5rem; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                        <i class="fa-solid fa-chart-line" style="color:var(--accent);"></i> Sales Trends</h3>
                    <div style="height:250px;"><canvas id="salesReportTrendsChart"></canvas></div>
                </div>
                <div class="glass-panel" style="margin-bottom:0; padding:1.5rem;">
                    <h3
                        style="color:var(--text-main); margin-bottom:1.5rem; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                        <i class="fa-solid fa-rocket" style="color:var(--success);"></i> Top Tenants</h3>
                    <div id="topTenantsPerfList" style="display:flex; flex-direction:column; gap:1rem;">
                        <p style="color:var(--text-dim); font-size:0.85rem;">Loading top performers...</p>
                    </div>
                </div>
            </div>

            <div class="glass-panel" style="padding:1.5rem; margin-bottom:2rem;">
                <h3
                    style="color:var(--text-main); margin-bottom:1.5rem; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fa-solid fa-table-list" style="color:var(--info);"></i> Revenue by Plan</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Plan Name</th>
                                <th>Active Subscriptions</th>
                                <th>Monthly Price</th>
                                <th>Yearly Price</th>
                                <th style="text-align:right;">Est. Monthly Revenue</th>
                                <th style="text-align:right;">Est. Yearly Revenue</th>
                            </tr>
                        </thead>
                        <tbody id="revenueByPlanTable"></tbody>
                    </table>
                </div>
            </div>

            <h3 style="color:var(--text-main); margin-bottom: 1rem; font-size: 1.1rem;">Transaction History</h3>
            <div class="search-container"
                style="display:flex; flex-direction:column; gap:1.5rem; background:rgba(255,255,255,0.02); padding:1.8rem; border-radius:18px; border:1px solid var(--glass-border);">
                <!-- Row 1: Search & Status Controls -->
                <div style="display:flex; gap:1.5rem; flex-wrap:wrap; align-items:flex-end;">
                    <div style="flex:2.5; min-width:300px;">
                        <p
                            style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.6rem; font-weight: 800; text-transform: uppercase;">
                            Search History</p>
                        <input type="text" class="search-input" style="width:100%;" id="paymentSearch"
                            placeholder="Search by Shop, Reference, or Amount...">
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <p
                            style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.6rem; font-weight: 800; text-transform: uppercase;">
                            Status Filter</p>
                        <select class="search-input" style="width:100%;" id="paymentStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="PAID">Success / Paid</option>
                            <option value="PENDING">Pending Approval</option>
                            <option value="FAILED">Failed / Denied</option>
                        </select>
                    </div>
                    <button class="btn-action"
                        style="background:#ef4444; color:var(--text-main); border-radius:30px; padding:0.85rem 2.2rem; border:none; font-weight:800; display:flex; align-items:center; gap:10px; transition: 0.3s; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);"
                        onmouseover="this.style.transform='translateY(-2px)';"
                        onmouseout="this.style.transform='translateY(0)';" onclick="downloadSalesPDF()">
                        <i class="fa-solid fa-file-pdf"></i> EXPORT PDF
                    </button>
                </div>

                <!-- Row 2: Advanced Date Filtering -->
                <div
                    style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1.5rem; padding-top:1.5rem; border-top:1px solid rgba(255,255,255,0.05);">
                    <div style="display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-end;">
                        <div style="display:flex; flex-direction:column; gap:0.6rem;">
                            <p
                                style="font-size: 0.75rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; margin:0;">
                                Filter by Date Range</p>
                            <div style="display:flex; align-items:center; gap:0.8rem;">
                                <span style="font-size: 0.7rem; font-weight: 800; color: #64748b;">FROM</span>
                                <input type="date" class="search-input" id="paymentDateFrom"
                                    onchange="document.getElementById('paymentSpecificDate').value='';"
                                    style="padding: 0.6rem 0.8rem; font-size: 0.85rem; border-radius: 10px; width:160px;">
                                <span style="font-size: 0.7rem; font-weight: 800; color: #64748b;">TO</span>
                                <input type="date" class="search-input" id="paymentDateTo"
                                    onchange="document.getElementById('paymentSpecificDate').value='';"
                                    style="padding: 0.6rem 0.8rem; font-size: 0.85rem; border-radius: 10px; width:160px;">
                            </div>
                        </div>

                        <div style="width:1px; height:40px; background:rgba(255,255,255,0.1);"></div>

                        <div style="display:flex; flex-direction:column; gap:0.6rem;">
                            <p
                                style="font-size: 0.75rem; color: var(--accent); font-weight: 800; text-transform: uppercase; margin:0;">
                                Or Pick Specific Day</p>
                            <div style="display:flex; align-items:center; gap:0.8rem;">
                                <input type="date" class="search-input" id="paymentSpecificDate"
                                    onchange="clearPaymentRange()"
                                    style="padding: 0.6rem 0.8rem; font-size: 0.85rem; border-radius: 10px; border:1.1px solid var(--accent); width:200px;">
                            </div>
                        </div>

                        <button onclick="renderPayments()"
                            style="background:var(--accent); color:var(--text-main); border:none; padding: 0.85rem 1.8rem; border-radius:12px; font-size:0.85rem; font-weight:900; cursor:pointer; height:45px; transition:0.3s; display:flex; align-items:center; gap:8px; box-shadow: 0 4px 15px var(--accent-glow);"
                            onmouseover="this.style.transform='translateY(-2px)'"
                            onmouseout="this.style.transform='translateY(0)'">
                            <i class="fa-solid fa-filter"></i> APPLY FILTERS
                        </button>
                    </div>

                    <button onclick="downloadReportsCSV()"
                        style="background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding: 0.85rem 1.5rem; border-radius:12px; font-size:0.75rem; font-weight:800; cursor:pointer; display:flex; align-items:center; gap:8px; height:45px; transition:0.3s;">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </button>
                    <button onclick="resetPaymentFilters()"
                        style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:var(--text-main); padding: 0.85rem 1.5rem; border-radius:12px; font-size:0.75rem; font-weight:800; cursor:pointer; display:flex; align-items:center; gap:8px; height:45px; transition:0.3s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.1)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                        <i class="fa-solid fa-rotate-right"></i> RESET FILTERS
                    </button>
                </div>
            </div>

            <div class="glass-panel">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Shop / Tenant</th>
                                <th>Amount</th>
                                <th style="text-align:center;">Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="paymentsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Section 1.7: Tenant Activity & Audit Logs -->
        <div id="logs" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main);">System Activity & Audit Logs</h1>
                    <p style="color:var(--text-dim);">Monitoring security and platform CRUD operations.</p>
                </div>
                <button class="btn-action" style="background:var(--error);" onclick="clearAllLogs()">Clear Audit
                    Trail</button>
            </div>
            <div class="search-container">
                <input type="text" class="search-input" id="logSearch" placeholder="Filter logs..."
                    onkeyup="renderLogs()">
                <select class="search-input" style="flex:0.3;" id="logTypeFilter" onchange="renderLogs()">
                    <option value="all">All Types</option>
                    <option value="CRUD">CRUD</option>
                    <option value="AUTH">Auth</option>
                    <option value="SECURITY">Security</option>
                </select>
            </div>
            <div class="glass-panel">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Source</th>
                            <th>Activity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Section 1.9: Backup -->
        <div id="backup" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main); font-size:2.2rem; font-weight:900; letter-spacing:-1px;">System
                        Backup</h1>
                    <p style="color:var(--text-dim); margin-top:5px;">Database and filesystem snapshots.</p>
                </div>
                <button class="btn-action"
                    style="background:var(--success); border-radius:12px; padding:12px 24px; font-weight:800;"
                    onclick="createManualBackup()">+ Create Manual Backup</button>
            </div>
            <div class="stats-grid"
                style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-bottom:2rem;">
                <div class="stat-card"
                    style="background:rgba(16, 185, 129, 0.05); border-color:rgba(16, 185, 129, 0.2); padding:2rem; border-radius:24px;">
                    <p class="stat-label"
                        style="color:var(--success); text-transform:uppercase; font-size:0.75rem; font-weight:900; letter-spacing:1px;">
                        Last Successful Backup</p>
                    <div class="stat-value"
                        style="color:var(--text-main); font-size:1.5rem; font-weight:900; margin-top:10px;"
                        id="lastBackupTime">None</div>
                </div>
                <div class="stat-card"
                    style="background:rgba(255,255,255,0.02); border-color:var(--glass-border); padding:2rem; border-radius:24px;">
                    <p class="stat-label"
                        style="text-transform:uppercase; font-size:0.75rem; font-weight:900; letter-spacing:1px; color:var(--text-dim);">
                        Total Backup Size</p>
                    <div class="stat-value"
                        style="color:var(--text-main); font-size:1.5rem; font-weight:900; margin-top:10px;"
                        id="totalBackupSize">0.0 KB</div>
                </div>
            </div>
            <div class="glass-panel" style="border-radius:24px; overflow:hidden;">
                <table class="data-table">
                    <thead style="background:rgba(255,255,255,0.02);">
                        <tr>
                            <th
                                style="padding:1.5rem; text-align:left; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim);">
                                Backup ID</th>
                            <th
                                style="padding:1.5rem; text-align:left; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim);">
                                Date/Time</th>
                            <th
                                style="padding:1.5rem; text-align:left; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim);">
                                Type</th>
                            <th
                                style="padding:1.5rem; text-align:left; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim);">
                                Status</th>
                            <th
                                style="padding:1.5rem; text-align:left; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim);">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody id="backupListContainer"></tbody>
                </table>
            </div>
        </div>
        </div>

        <!-- Section 1.10: Settings -->
        <!-- Section 1.8: Chat Support Hub -->
        <div id="chat" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main); font-size:2.2rem; font-weight:900; letter-spacing:-1px;">Chat
                        Support Hub</h1>
                    <p style="color:var(--text-dim); margin-top:5px;">Direct communication with workshop owners.</p>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 350px 1fr; gap:2rem; height: calc(100vh - 250px);">
                <!-- Conversation List -->
                <div class="glass-panel" style="padding:0; overflow:hidden; display:flex; flex-direction:column;">
                    <div
                        style="padding:1.5rem; border-bottom:1px solid var(--glass-border); display:flex; flex-direction:column; gap:1rem;">
                        <div style="font-weight:800; font-size:0.9rem; text-transform:uppercase; color:var(--accent);">
                            Active Conversations
                        </div>
                        <!-- Search Bar -->
                        <div style="position:relative;">
                            <i class="fas fa-search"
                                style="position:absolute; left:1rem; top:50%; transform:translateY(-50%); color:var(--text-dim); font-size:0.8rem;"></i>
                            <input type="text" id="chatSearchInput" onkeyup="filterChatGroups()"
                                placeholder="Search shop name..."
                                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--glass-border); border-radius:12px; padding:0.7rem 1rem 0.7rem 2.5rem; color:var(--text-main); font-size:0.85rem; outline:none;">
                        </div>
                    </div>
                    <div id="chatGroupsList" style="flex:1; overflow-y:auto;">
                        <!-- Groups will be loaded here -->
                        <div style="text-align:center; padding:3rem; color:var(--text-dim);">
                            <i class="fas fa-spinner fa-spin" style="font-size:2rem; margin-bottom:1rem;"></i><br>
                            Loading chats...
                        </div>
                    </div>
                </div>

                <!-- Chat Window -->
                <div class="glass-panel" id="adminChatWindow"
                    style="padding:0; overflow:hidden; display:none; flex-direction:column;">
                    <div id="adminChatHeader"
                        style="padding:1.5rem; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center; background:rgba(99, 102, 241, 0.05);">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div id="chatHeaderAvatar"
                                style="width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg, var(--accent), #6366f1); display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden; box-shadow:0 3px 10px rgba(0,0,0,0.2);">
                                <span style="font-weight:900; font-size:0.9rem; color:white;">?</span>
                            </div>
                            <div>
                                <h3 id="chatTargetName"
                                    style="color:var(--text-main); margin:0; font-size:1.1rem; font-weight:900;">Shop Name
                                </h3>
                                <span style="font-size:0.75rem; color:var(--accent);">Connected</span>
                            </div>
                        </div>
                    </div>
                    <div id="adminChatMessages"
                        style="flex:1; padding:2rem; overflow-y:auto; display:flex; flex-direction:column; gap:1.5rem; background:rgba(0,0,0,0.1);">
                        <!-- Messages loaded here -->
                    </div>
                    <div
                        style="padding:1.5rem; border-top:1px solid var(--glass-border); display:flex; gap:1.5rem; background:rgba(0,0,0,0.2);">
                        <input type="text" id="adminChatInput" class="modern-input" placeholder="Type your reply..."
                            style="flex:1;">
                        <button class="btn-action" style="padding:0 2.5rem; border-radius:16px;"
                            onclick="sendAdminReply()">
                            <i class="fas fa-paper-plane" style="margin-right:10px;"></i> Send
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div class="glass-panel" id="chatEmptyState"
                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:var(--text-dim);">
                    <i class="fa-solid fa-comments" style="font-size:4rem; margin-bottom:1.5rem; opacity:0.1;"></i>
                    <h3 style="color:var(--text-main);">Select a conversation</h3>
                    <p>Choose a shop from the left to start chatting.</p>
                </div>
            </div>
        </div>

        <div id="settings" class="view-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem;">
                <div>
                    <h1 style="color:var(--text-main);">System Configuration</h1>
                    <p style="color:var(--text-dim);">Global settings, branding, limits, and permissions.</p>
                </div>
                <button class="btn-action" onclick="saveConfiguration(this)">Save Configuration</button>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div class="glass-panel">
                    <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; color: var(--accent);">Branding & Platform</h3>
                    <div class="form-group">
                        <label>Platform Name</label>
                        <input type="text" class="modern-input" id="settingAppName" value="AutoFix Hub" readonly
                            style="opacity: 0.7; cursor: not-allowed; border-color: rgba(255,255,255,0.05);">
                    </div>
                    <div class="form-group">
                        <label>Support Email</label>
                        <input type="email" class="modern-input" id="settingSupportEmail"
                            value="support@autofixhub.com">
                    </div>
                    <div class="form-group">
                        <label>Maintenance Mode</label>
                        <select class="modern-input" id="settingMaintenance">
                            <option value="off">Off - Live</option>
                            <option value="on">On - Offline for DB Migration</option>
                        </select>
                    </div>
                </div>
                <div class="glass-panel">
                    <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; color: var(--accent);">Default Limits & Roles
                    </h3>
                    <div class="form-group">
                        <label>Max Storage per Tenant (GB)</label>
                        <input type="number" class="modern-input" id="settingMaxStorage" value="5">
                    </div>
                    <div class="form-group">
                        <label>Default Role for New Staff</label>
                        <select class="modern-input" id="settingDefaultRole">
                            <option value="mechanic">Mechanic</option>
                            <option value="receptionist">Receptionist</option>
                            <option value="manager">Shop Manager</option>
                        </select>
                    </div>
                    <div class="form-group" style="padding-top: 1rem;">
                        <label style="display:flex; align-items:center; gap: 10px; cursor:pointer;">
                            <input type="checkbox" id="settingAutoApprove" checked
                                style="width:18px; height:18px; accent-color:var(--accent);">
                            Auto-Approve New Tenant Registrations
                        </label>
                    </div>
                </div>
            </div>

            <!-- Admin Team Management -->
            <div class="glass-panel" style="margin-top:2rem;">
                <h3
                    style="margin-bottom: 2rem; display: flex; align-items: center; gap: 12px; font-size: 1.1rem; color: var(--accent);">
                    <i class="fa-solid fa-user-shield"></i> Admin Team Management
                </h3>

                <div
                    style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); border-radius: 20px; padding: 1rem; margin-bottom: 2.5rem;">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr
                                style="text-align:left; color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; letter-spacing:1px;">
                                <th style="padding:1rem;">Name</th>
                                <th style="padding:1rem;">Username</th>
                                <th style="padding:1rem; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="adminListBody">
                            <?php foreach ($admins_db as $adm): ?>
                                <tr style="border-top:1px solid var(--glass-border);">
                                    <td style="padding:1rem; font-weight:700; color:var(--text-main);">
                                        <?php echo htmlspecialchars($adm['name']); ?></td>
                                    <td style="padding:1rem; color:var(--text-dim);">
                                        <?php echo htmlspecialchars($adm['email']); ?></td>
                                    <td style="padding:1rem; text-align:right;">
                                        <?php if ($adm['id'] != ($_SESSION['user_id'] ?? '')): ?>
                                            <button class="btn-action"
                                                style="background:var(--error); padding:6px 12px; font-size:0.75rem;"
                                                onclick="deleteAdmin(<?php echo $adm['id']; ?>, '<?php echo htmlspecialchars($adm['name']); ?>')">
                                                Remove
                                            </button>
                                        <?php else: ?>
                                            <span
                                                style="font-size:0.7rem; color:var(--accent); font-weight:800; text-transform:uppercase;">You
                                                (Main)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div
                    style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.1); border-radius: 24px; padding: 1.5rem;">
                    <h4 style="margin-bottom:1.5rem; font-size:0.9rem; color:var(--text-main);">Add New Administrator
                    </h4>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:1.5rem; align-items:end;">
                        <div class="form-group">
                            <label style="font-size:0.7rem; color:var(--text-dim);">Full Name</label>
                            <input type="text" id="newAdminName" class="modern-input" placeholder="e.g. Juan">
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.7rem; color:var(--text-dim);">Username / ID</label>
                            <input type="text" id="newAdminEmail" class="modern-input" placeholder="e.g. admin_juan">
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.7rem; color:var(--text-dim);">Initial Password</label>
                            <input type="password" id="newAdminPass" class="modern-input" placeholder="Min 8 chars">
                        </div>
                        <button class="btn-action" style="padding:1rem 2rem; border-radius:12px;"
                            onclick="addAdmin(this)">
                            Add Account
                        </button>
                    </div>
                </div>
            </div>

            <!-- Profile Management -->
            <div class="glass-panel" style="margin-top:2rem; border-top: 1px solid var(--accent);">
                <h3
                    style="margin-bottom: 2rem; font-size: 1.1rem; color: var(--accent); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-user-gear"></i> Super Admin Profile & Security
                </h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:2rem;">
                    
                    <div class="form-group" style="grid-column: span 2; display: flex; align-items: center; gap: 20px; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 16px; border: 1px dashed var(--glass-border);">
                        <div id="settingsAdminAvatarPreview" style="width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), #6366f1); display: flex; align-items: center; justify-content: center; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.1); box-shadow: 0 4px 15px rgba(0,0,0,0.3); flex-shrink: 0;">
                            <?php if ($admin_avatar): ?>
                                <img src="<?php echo htmlspecialchars($admin_avatar); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="font-weight: 800; font-size: 1.5rem; color: white;"><?php echo mb_strtoupper(mb_substr($admin_name, 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1;">
                            <label style="color:var(--text-main); font-weight:700; display:block; margin-bottom: 6px;">Profile Photo</label>
                            <input type="file" id="settingAdminAvatarFile" accept="image/*" class="modern-input" style="background: rgba(0,0,0,0.2); padding: 8px 12px; font-size: 0.85rem;">
                            <span style="font-size: 0.72rem; color: var(--text-dim); margin-top: 4px; display: block;">Recommended size: Square image. JPG, PNG or WebP.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="color:var(--text-main); font-weight:700;">Display Name</label>
                        <input type="text" class="modern-input" id="settingAdminName"
                            value="<?php echo htmlspecialchars($admin_name); ?>" required style="background: rgba(0,0,0,0.2);">
                    </div>
                    <div class="form-group">
                        <label style="color:var(--text-main); font-weight:700;">Email Address</label>
                        <input type="email" class="modern-input" id="settingAdminEmail"
                            value="<?php echo htmlspecialchars($admin_row['email'] ?? ''); ?>" required style="background: rgba(0,0,0,0.2);">
                    </div>

                    <div class="form-group">
                        <label style="color:var(--text-main); font-weight:700;">New Password</label>
                        <input type="password" class="modern-input" id="settingAdminPassword"
                            placeholder="Leave blank to keep current" style="background: rgba(0,0,0,0.2);">
                    </div>
                    <div class="form-group">
                        <label style="color:var(--text-main); font-weight:700;">Confirm Password</label>
                        <input type="password" class="modern-input" id="settingAdminPasswordConfirm"
                            placeholder="••••••••" style="background: rgba(0,0,0,0.2);">
                    </div>
                    <div style="grid-column: span 2; display: flex; justify-content: center; margin-top: 1rem;">
                        <button class="btn-action"
                            style="background:var(--accent); padding: 1rem 3rem; border-radius: 14px; font-weight: 800; font-size: 0.9rem; box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3); transition: 0.3s; border: none; cursor: pointer;"
                            onclick="saveConfiguration(this)">
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modals -->
    <!-- Section 1.4: Tenant Modal -->
    <div class="modal-overlay" id="shopModal">
        <div class="modal-card">
            <button class="btn-close-modal" onclick="closeShopModal()">&times;</button>
            <div class="logo-icon">A</div>
            <h2 id="shopModalTitle"
                style="margin-bottom:0.5rem; font-size:1.8rem; font-weight:800; color:var(--text-main); text-align:center;">
                Onboard
                Your Shop</h2>
            <p id="shopModalSub"
                style="color:var(--text-dim); margin-bottom:2.5rem; text-align:center; font-size:0.95rem;">Join the 500+
                tenants scaling their business with AutoFix Hub.</p>
            <form id="shopForm" action="verify-email.php" method="POST">
                <input type="hidden" id="shopId" name="shopId">

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                    <div class="form-group">
                        <label>Shop Name</label>
                        <input type="text" id="shopName" name="shop_name" class="modern-input"
                            placeholder="e.g. Manila Auto Hub" required>
                    </div>
                    <div class="form-group">
                        <label>Owner Name</label>
                        <input type="text" id="shopOwner" name="owner_name" class="modern-input" placeholder="Full Name"
                            required>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                    <div class="form-group">
                        <label>Business Email</label>
                        <input type="email" id="shopEmail" name="email" class="modern-input"
                            placeholder="owner@shop.com" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" id="shopContact" name="contact" class="modern-input"
                            placeholder="0917 XXX XXXX" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Shop Address</label>
                    <input type="text" id="shopAddress" name="address" class="modern-input"
                        placeholder="Unit, Street, City, ZIP" required>
                </div>

                <div id="passwordFields" style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                    <div class="form-group">
                        <label>Create Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="shopPassword" name="password" class="modern-input"
                                placeholder="Min. 8 characters">
                            <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('shopPassword', this)"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="shopConfirmPassword" class="modern-input"
                                placeholder="Re-type password">
                            <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('shopConfirmPassword', this)"></i>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Subscription Plan</label>
                    <select id="shopPlan" class="modern-input" required onchange="updateHiddenPlanFields()"></select>
                </div>

                <div id="adminFields" style="display:none;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                        <div class="form-group">
                            <label>Status</label>
                            <select id="shopStatus" class="modern-input">
                                <option value="active">Active</option>
                                <option value="pending">Pending</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Expiry Date</label>
                            <input type="date" id="shopExpiry" class="modern-input">
                        </div>
                    </div>
                </div>

                <input type="hidden" name="plan_id" id="hiddenPlanId">
                <input type="hidden" name="billing_cycle" id="hiddenBillingCycle">
                <button type="submit" id="shopSubmitBtn" class="btn-gradient"
                    style="width:100%; margin-top:1.5rem;">Verify Email &amp; Proceed</button>
                <button type="button" onclick="closeShopModal()"
                    style="width:100%; margin-top:0.8rem; background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:0.9rem; padding:0.6rem;">Cancel</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="planModal">
        <div class="modal-card">
            <button class="btn-close-modal" onclick="closePlanModal()">&times;</button>
            <div class="logo-icon" style="background:linear-gradient(135deg,#6366f1,#a855f7);">✦</div>
            <h2
                style="margin-bottom:0.5rem; font-size:1.8rem; font-weight:800; color:var(--text-main); text-align:center;">
                Edit
                Subscription Plan</h2>
            <p style="color:var(--text-dim); margin-bottom:2.5rem; text-align:center; font-size:0.95rem;">Changes will
                reflect immediately on the landing page.</p>
            <form id="planForm">
                <input type="hidden" id="planId">

                <div class="form-group">
                    <label>Plan Name</label>
                    <div class="input-with-prefix">
                        <i class="fa-solid fa-tag input-prefix"></i>
                        <input type="text" id="planName" class="modern-input has-prefix" required
                            placeholder="e.g. Pro Auto Shop">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                    <div class="form-group">
                        <label>Monthly Price</label>
                        <div class="input-with-prefix">
                            <span class="input-prefix" style="font-weight:800; color:var(--text-main);">₱</span>
                            <input type="number" id="monthlyPrice" class="modern-input has-prefix" required
                                oninput="document.getElementById('yearlyPrice').value = Math.round(this.value * 12 * 0.8)">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Yearly Price</label>
                        <div class="input-with-prefix">
                            <span class="input-prefix" style="font-weight:800; color:var(--text-main);">₱</span>
                            <input type="number" id="yearlyPrice" class="modern-input has-prefix" required>
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
                    <div class="form-group">
                        <label>Max Users</label>
                        <div class="input-with-prefix">
                            <i class="fa-solid fa-users input-prefix"></i>
                            <input type="number" id="maxUsers" class="modern-input has-prefix" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Service Bays</label>
                        <div class="input-with-prefix">
                            <i class="fa-solid fa-car-side input-prefix"></i>
                            <input type="number" id="maxBays" class="modern-input has-prefix" required>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 2rem;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 0.8rem;">
                        <label style="margin-bottom:0;">Tier Features (One per line)</label>
                        <select id="featureTemplate" class="modern-input"
                            style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.75rem; height: auto; border-radius: 8px;"
                            onchange="applyFeatureTemplate()">
                            <option value="">-- Apply a Template --</option>
                            <option value="basic">Workshop Starter</option>
                            <option value="pro">Professional Hub</option>
                            <option value="enterprise">Ultimate Enterprise</option>
                            <option value="premium">Custom High-Performance</option>
                        </select>
                    </div>
                    <textarea id="planFeatures" class="modern-input"
                        style="min-height: 140px; font-size: 0.85rem; padding: 1.2rem; line-height: 1.6; resize: none; border-radius: 18px;"
                        placeholder="Real-time Dashboard&#10;Appointment Booking&#10;Custom Domain Branding"></textarea>
                </div>

                <div class="modal-footer-grid">
                    <button type="button" class="btn-white" onclick="closePlanModal()">Cancel</button>
                    <button type="submit" class="btn-gradient">Deploy Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Modal (Alert/Confirm) -->
    <div class="modal-overlay" id="notificationModal" style="z-index: 9999; display: none;">
        <div class="modal-card"
            style="max-width: 450px; text-align: center; padding: 3rem 2.5rem; background: var(--modal-bg); border: 1px solid var(--glass-border); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
            <div id="notiIcon"
                style="width: 80px; height: 80px; background: var(--accent-glow); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; font-size: 2.5rem; color: var(--accent); transition: 0.3s;">
                <i class="fa-solid fa-circle-info"></i>
            </div>
            <h2 id="notiTitle"
                style="margin-bottom: 1rem; font-size: 1.6rem; font-weight: 800; color: var(--text-main);">Notice
            </h2>
            <p id="notiMessage"
                style="color: var(--text-dim); margin-bottom: 2.5rem; line-height: 1.6; font-size: 1rem;">Message goes
                here.</p>
            <div id="notiActions" style="display: flex; gap: 1rem; justify-content: center;">
                <button id="notiConfirmBtn" class="btn-gradient"
                    style="min-width: 120px; padding: 0.9rem 2rem; border-radius: 12px; font-weight: 800; cursor: pointer;">Confirm</button>
                <button id="notiCancelBtn" class="btn-white"
                    style="min-width: 120px; padding: 0.9rem 2rem; border-radius: 12px; font-weight: 800; display: none;"
                    onclick="closeNotiModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- NEW: Verification Dossier Modal (Comprehensive Review) -->
    <div id="dossierModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 1000px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 2rem;">
                <div>
                    <h2 id="dossierTitle"
                        style="font-size: 2.2rem; letter-spacing: -1.5px; font-weight: 900; color:var(--text-main);">
                        Applicant
                        Dossier</h2>
                    <p id="dossierSubtitle"
                        style="color: var(--accent); font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 2px;">
                        Manual Identity & Business Verification</p>
                </div>
                <button class="btn-action"
                    style="width:45px; height:45px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.05); color:white; border:none; font-size: 1.5rem;"
                    onclick="closeDossierModal()">&times;</button>
            </div>

            <div class="dossier-grid">
                <!-- Left: Applicant Information -->
                <div class="dossier-card">
                    <div class="dossier-label">Core Business Information</div>
                    <div class="dossier-info-row"><span>Shop Name</span><span class="dossier-info-val"
                            id="dos-shop">N/A</span></div>
                    <div class="dossier-info-row"><span>Owner</span><span class="dossier-info-val"
                            id="dos-owner">N/A</span></div>
                    <div class="dossier-info-row"><span>Primary Email</span><span class="dossier-info-val"
                            id="dos-email">N/A</span></div>
                    <div class="dossier-info-row"><span>Business Address</span><span class="dossier-info-val"
                            id="dos-address">N/A</span></div>

                    <div class="dossier-label" style="margin-top: 1.5rem;">Identity Credentials</div>
                    <div class="dossier-info-row"><span>Document Type</span><span class="dossier-info-val"
                            id="dos-id-type" style="color:var(--warning);">Checking...</span></div>
                    <img id="dos-id-img" src="" class="dossier-img" style="margin-top: 10px;"
                        onclick="window.open(this.src)">
                </div>

                <!-- Right: Business Proof -->
                <div class="dossier-card">
                    <div class="dossier-label">Business Registration / Permit</div>
                    <div id="dos-proof-container"
                        style="height: 100%; min-height: 400px; background: rgba(0,0,0,0.3); border-radius: 12px; display:flex; align-items:center; justify-content:center; overflow: hidden; border: 1px solid rgba(255,255,255,0.05);">
                        <!-- Dynamic Proof (Image or PDF) -->
                    </div>
                </div>
            </div>

            <div
                style="margin-top: 2.5rem; padding-top: 2rem; border-top: 1px solid var(--glass-border); display:flex; justify-content:flex-end; gap: 1rem;">
                <button class="btn-action"
                    style="padding: 1rem 2rem; border-radius: 14px; background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); font-weight: 800;"
                    onclick="closeDossierModal()">Close Review</button>
                <div id="dossierActions">
                    <!-- Dynamic Buttons -->
                </div>
            </div>
        </div>
    </div>

    <!-- Announcement Bookmark -->
    <div class="announcement-puller" onclick="toggleAnnouncement()">
        <div class="bookmark-tab">
            <i class="fas fa-bullhorn" style="font-size:0.9rem;"></i> SYSTEM BROADCAST <i class="fas fa-chevron-down"
                style="font-size:0.7rem; opacity:0.6;"></i>
        </div>
    </div>

    <div class="announcement-overlay" id="annOverlay" onclick="toggleAnnouncement()"></div>
    <div class="announcement-panel" id="annPanel">
        <div style="display:flex; align-items:center; gap:20px; margin-bottom:1.8rem;">
            <div
                style="width:55px; height:55px; border-radius:15px; background:rgba(var(--accent-glow), 0.1); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.6rem;">
                <i class="fas fa-satellite-dish"></i>
            </div>
            <div style="flex:1;">
                <h3 style="margin:0; font-size:1.4rem; letter-spacing:-0.5px; font-weight:800;">Platform News Feed</h3>
                <span
                    style="font-size:0.7rem; color:var(--text-dim); text-transform:uppercase; font-weight:700; letter-spacing:1px;">Edit
                    Broadcast to All Shops</span>
            </div>
            <button class="btn-outline"
                style="padding:8px 12px; font-size:0.75rem; border-radius:10px; border-color:var(--accent); color:var(--accent);"
                id="editAnnBtn" onclick="enableAnnEdit()"><i class="fas fa-pencil-alt"></i></button>
        </div>

        <div id="annDisplay"
            style="background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); padding:1.8rem; border-radius:20px; color:var(--text-main); line-height:1.7; font-size:1rem; border-left: 4px solid var(--accent); white-space: pre-wrap;">
            <?php
            try {
                echo htmlspecialchars($db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'announcement'")->fetchColumn() ?: "No active broadcast.");
            } catch (Exception $e) {
                echo "No active broadcast.";
            }
            ?>
        </div>

        <textarea id="annEditor" class="modern-input"
            style="display:none; min-height:150px; background:rgba(0,0,0,0.3); border:1px solid var(--accent); padding:1.5rem; margin-bottom:1rem; font-size:1rem; line-height:1.6; resize:none; color:white !important;"></textarea>

        <div style="margin-top:2.2rem; text-align:center; display:flex; gap:10px; justify-content:center;">
            <button class="btn-outline" style="padding:12px 35px; border-radius:15px; font-weight:700;"
                onclick="toggleAnnouncement()">Close Messenger</button>
            <button class="btn-gradient" id="annSaveBtn"
                style="display:none; padding:12px 35px; border-radius:15px; font-weight:700;"
                onclick="saveAnnEdit()">Public Broadcast <i class="fas fa-paper-plane"
                    style="margin-left:8px;"></i></button>
        </div>
    </div>

    <div class="modal-overlay" id="proofModal">
        <div class="modal-card" style="max-width: 800px;">
            <button class="btn-close-modal" onclick="closeProofModal()">&times;</button>
            <div class="logo-icon" style="background: var(--info);">P</div>
            <h2 style="margin-bottom:0.5rem; font-size:1.8rem; font-weight:800; color:white; text-align:center;">
                Business Proof</h2>
            <p style="color:var(--text-dim); margin-bottom:2rem; text-align:center; font-size:0.95rem;">Review the
                uploaded document before approving registration.</p>

            <div id="proofContainer"
                style="background: rgba(255,255,255,0.02); border-radius: 20px; overflow: hidden; border: 1px solid var(--glass-border); text-align: center; min-height: 400px; display: flex; align-items: center; justify-content: center;">
                <!-- Image or PDF will be injected here -->
            </div>

            <div style="margin-top: 2rem;">
                <button class="btn-gradient" style="width:100%; border-radius: 16px;" onclick="closeProofModal()">Close
                    Document</button>
            </div>
        </div>
    </div>

    <!-- Master Service Modal -->
    <div class="modal-overlay" id="masterServiceModal">
        <div class="modal-card" style="max-width: 550px;">
            <button class="btn-close-modal" onclick="closeMasterServiceModal()">&times;</button>
            <div class="logo-icon" style="background: var(--accent);"><i class="fas fa-tags"></i></div>
            <h2 id="msModalTitle"
                style="margin-bottom:0.5rem; font-size:1.8rem; font-weight:800; color:white; text-align:center;">
                Standard Service</h2>
            <p style="color:var(--text-dim); margin-bottom:2rem; text-align:center; font-size:0.9rem;">Set the global
                pricing boundary for this automotive service.</p>

            <form id="masterServiceForm" onsubmit="saveMasterService(event)">
                <input type="hidden" name="master_id" id="ms_id">
                <div style="margin-bottom: 1.5rem;">
                    <label
                        style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-dim); font-weight:600;">Service
                        Name</label>
                    <input type="text" name="service_name" id="ms_name" required class="modern-input"
                        placeholder="e.g. Oil Change (Synthetic)">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label
                        style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--text-dim); font-weight:600;">Category</label>
                    <input type="text" name="category" id="ms_cat" required class="modern-input"
                        placeholder="e.g. Maintenance">
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-bottom:2rem;">
                    <div>
                        <label
                            style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--success); font-weight:600;">Min
                            Price (Floor)</label>
                        <input type="number" name="min_price" id="ms_min" required class="modern-input" value="0"
                            step="0.01">
                    </div>
                    <div>
                        <label
                            style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:var(--error); font-weight:600;">Max
                            Price (Ceiling)</label>
                        <input type="number" name="max_price" id="ms_max" required class="modern-input" value="0"
                            step="0.01">
                    </div>
                </div>
                <button type="submit" class="btn-gradient"
                    style="width:100%; padding:1.2rem; font-weight:800; border-radius:15px;">SAVE SERVICE
                    STANDARD</button>
            </form>
        </div>
    </div>
    </div>
    </div>

    <script>
        const DB_VER = "6.5_DYNAMIC_PLANS";
        let plans = [], shops = [], logs = [], payments = [], backups = [], reports = [], revenueChart = null, distChart = null, isYearly = false;

        function loadDB() {
            // Force hide loaders as an immediate fallback
            document.querySelectorAll('.chart-loader').forEach(el => el.style.display = 'none');

            try {
                // Seed from Database (PHP) and normalize status
                const rawShops = <?php echo json_encode($shops_db); ?> || [];
                shops = (Array.isArray(rawShops) ? rawShops : []).map(s => ({
                    id: s.tenant_id,
                    ...s,
                    status: (s.status || '').toLowerCase(),
                    name: s.name || 'Unknown Shop',
                    bookings: parseInt(s.bookings || 0),
                    customers: parseInt(s.customer_count || 0),
                    users: parseInt(s.staff_count || 0),
                    revenue: parseFloat(s.revenue || 0),
                    lastActivity: s.last_activity,
                    joined: s.created_at
                }));

                payments = <?php echo json_encode($payments_db); ?> || [];
                const dbLogs = <?php echo json_encode($logs_db); ?> || [];
                window.logs = (Array.isArray(dbLogs) ? dbLogs : []).map(L => ({
                    id: L.log_id || L.id,
                    tenant_id: L.tenant_id,
                    shop_name: L.shop_name,
                    activity: L.activity || L.activity_description || 'No Activity Data',
                    type: L.type || L.activity_type || 'LOG',
                    time: L.time || L.created_at,
                    staff_name: L.staff_name
                }));
                logs = window.logs;

                // Map DB plans to match the expected format in UI
                const dbPlans = <?php echo json_encode($plans_db); ?> || [];
                plans = (Array.isArray(dbPlans) ? dbPlans : []).map(p => ({
                    id: parseInt(p.id),
                    name: p.name,
                    monthlyPrice: parseFloat(p.monthlyPrice),
                    yearlyPrice: parseFloat(p.yearlyPrice),
                    maxUsers: parseInt(p.maxUsers),
                    maxBays: parseInt(p.maxBays),
                    status: (p.status || 'active').toLowerCase()
                }));

                // Real Backups from DB
                backups = <?php echo json_encode($backups_db); ?> || [];
                window.serverActivity = <?php echo json_encode($logs_activity); ?> || { daily: 0, monthly: 0, global_appts: 0, global_custs: 0, global_logs: 0 };
                window.serverUserCounts = <?php echo json_encode($user_counts); ?> || { active: 0, inactive: 0 };
                window.systemSettings = <?php echo json_encode($settings_db); ?> || {};
                window.dashboardTrends = <?php echo json_encode($dashboard_trends); ?> || [];
                window.adminAvatarUrl = <?php echo json_encode($admin_avatar); ?>;

                reports = shops.map(s => ({
                    name: s.name,
                    status: s.status,
                    bookings: s.bookings || 0,
                    revenue: s.revenue || 0
                }));

                console.log("Database initialized successfully", { shops: shops.length, plans: plans.length });
                if (plans.length === 0) console.warn("No plans found in database!");
                if (shops.length > 0) console.log("Sample Shop Data:", shops[0]);
            } catch (err) {
                console.error("Critical error loading database variables:", err);
                shops = []; payments = []; logs = []; plans = []; backups = [];
                window.serverActivity = { daily: 0, monthly: 0, global_appts: 0, global_custs: 0, global_logs: 0 };
            }

            // Centralized UI refresh
            refreshUI();

            // Centralized UI refresh
            refreshUI();
        }

        function saveToStorage() {
            localStorage.setItem('autofix_plans', JSON.stringify(plans));
            localStorage.setItem('autofix_shops', JSON.stringify(shops));
            localStorage.setItem('autofix_logs', JSON.stringify(logs));
            localStorage.setItem('autofix_payments', JSON.stringify(payments));
            refreshUI();
        }

        function logAction(source, user, activity, type = 'CRUD') {
            const formData = new FormData();
            formData.append('type', type);
            formData.append('activity', activity);

            fetch('dashboard.php?action=log_db', {
                method: 'POST',
                body: formData
            }).then(() => {
                // Also update local list for immediate display
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const seconds = String(now.getSeconds()).padStart(2, '0');
                const timeStr = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
                logs.unshift({ time: timeStr, source: `${source} (${user})`, activity, type });
                if (logs.length > 100) logs.pop();
                renderLogs();
            });
        }

        function refreshUI() {
            // 1. Force hide loaders first thing to ensure no stuck screen
            try {
                document.querySelectorAll('.chart-loader').forEach(el => el.style.display = 'none');
            } catch (e) { }

            const safeStats = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.innerText = val;
            };

            try {
                const today = new Date();
                const validShops = Array.isArray(shops) ? shops : [];
                const validPayments = Array.isArray(payments) ? payments : [];

                const activeShops = validShops.filter(s => s && s.status === 'active' && new Date(s.expiry) > today);
                const totalRev = validPayments.reduce((sum, p) => {
                    const ps = (p && p.status) ? p.status.trim().toUpperCase() : '';
                    return sum + (p && (ps === 'PAID' || ps === 'SUCCESS') ? parseFloat(p.amount || 0) : 0);
                }, 0);

                safeStats('stat-shops', validShops.length);
                safeStats('stat-revenue', `₱${totalRev.toLocaleString()}`);
                if (window.serverUserCounts) {
                    safeStats('stat-active-users', window.serverUserCounts.active || 0);
                    safeStats('stat-inactive-users', window.serverUserCounts.inactive || 0);
                }
                if (window.serverActivity) {
                    safeStats('stat-daily-act', window.serverActivity.daily || 0);
                    safeStats('stat-monthly-act', window.serverActivity.monthly || 0);
                }

                // Individual renderers
                try { renderShops(); } catch (e) { console.error("Shops render error", e); }
                try { renderSettings(); } catch (e) { console.error("Settings render error", e); }
                try { renderPlans(); } catch (e) { console.error("Plans render error", e); }
                try { renderLogs(); } catch (e) { console.error("Logs render error", e); }
                try { renderPayments(); } catch (e) { console.error("Payments render error", e); }
                try { renderReports(); } catch (e) { console.error("Reports render", e); }
                // try { renderBackups(); } catch (e) { console.error("Backups render error", e); }
                try { renderMasterServices(); } catch (e) { console.error("Master Services render", e); }

                // Final Chart Update
                setTimeout(() => {
                    try { updateCharts(); } catch (e) { console.error("Chart auto-update error", e); }
                }, 300);

            } catch (e) {
                console.error("UI Refresh critical error:", e);
                // Last ditch effort to hide loaders
                document.querySelectorAll('.chart-loader').forEach(el => el.style.display = 'none');
            }
        }

        function formatTimestamp(ts) {
            if (!ts) return 'N/A';
            const d = new Date(ts.replace(' ', 'T')); // Handles ISO and simple date-time
            if (isNaN(d.getTime())) return ts; // Fallback if parsing fails

            return d.toLocaleString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function archiveTenant(id, newStatus = 'inactive') {
            const verb = newStatus === 'active' ? 're-activate' : 'archive';
            if (!confirm(`Are you sure you want to ${verb} this tenant?`)) return;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('status', newStatus);

            fetch('dashboard.php?action=edit_shop', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update Memory & Refresh UI without full reload
                        const s = shops.find(sh => sh.tenant_id == id);
                        if (s) s.status = newStatus;

                        showToast(`Tenant ${newStatus === 'active' ? 're-activated' : 'archived'} successfully.`);
                        refreshUI();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        function showToast(msg) {
            const toast = document.createElement('div');
            toast.style.cssText = "position:fixed; bottom:30px; right:30px; background:var(--success); color:white; padding:1rem 2rem; border-radius:12px; z-index:9999; font-weight:700; box-shadow:0 10px 30px rgba(0,0,0,0.4); animation:slideUp 0.3s ease;";
            toast.innerText = msg;
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = '1s'; setTimeout(() => toast.remove(), 1000); }, 3000);
        }

        function renderPayments() {
            const body = document.getElementById('paymentsTableBody');
            body.innerHTML = '';

            let totalSum = 0;
            let monthSum = 0;
            let weekSum = 0;
            let daySum = 0;
            let shopRev = {};
            let shopTnx = {};

            const manilaTodayStr = window.serverActivity ? window.serverActivity.manila_today : new Date().toISOString().split('T')[0];
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth();
            const sevenDaysAgo = new Date();
            sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);

            // 1. Calculate GLOBAL Stats first (independent of filters)
            payments.forEach(p => {
                const ps = (p.status || '').trim().toUpperCase();
                const ts = (p.tenantStatus || '').trim().toUpperCase();
                const isRejected = (ps === 'REJECTED' || ps === 'FAILED' || ts === 'REJECTED' || (p.shopName || '').includes('[REJECTED]'));
                const isPaid = (ps === 'PAID' || ps === 'SUCCESS' || ps === 'COMPLETED') && !isRejected;

                if (isPaid) {
                    const amt = parseFloat(p.amount) || 0;
                    const pDateStr = p.date || '';
                    const pDateObj = new Date(pDateStr.replace(' ', 'T'));

                    totalSum += amt;
                    if (pDateStr.startsWith(manilaTodayStr)) daySum += amt;
                    // Calendar Month Logic: Same Year AND Same Month
                    if (pDateObj.getFullYear() === currentYear && pDateObj.getMonth() === currentMonth) {
                        monthSum += amt;
                    }
                    if (pDateObj >= sevenDaysAgo) weekSum += amt;

                    shopRev[p.shopName] = (shopRev[p.shopName] || 0) + amt;
                }
                shopTnx[p.shopName] = (shopTnx[p.shopName] || 0) + 1;
            });

            // 2. Filter for TABLE display - Extract correct hidden format from inputs
            const q = (document.getElementById('paymentSearch').value || '').toLowerCase();
            const f = document.getElementById('paymentStatusFilter').value;

            // Get values from inputs (Flatpickr keeps the database format in the value attribute)
            const dateFrom = document.getElementById('paymentDateFrom').value;
            const dateTo = document.getElementById('paymentDateTo').value;
            const specDay = document.getElementById('paymentSpecificDate').value;

            let filtered = payments.filter(p => {
                const matchesSearch = p.shopName.toLowerCase().includes(q) || p.ref.toLowerCase().includes(q) || p.amount.toString().includes(q);
                const pStatus = (p.status || '').trim().toUpperCase();
                let matchesStatus = (f === 'all' || (f === 'PAID' ? (pStatus === 'PAID' || pStatus === 'SUCCESS') : pStatus === (f || '').trim().toUpperCase()));

                let matchesDateRange = true;
                const pDateFull = p.date || '';
                const pDatePart = pDateFull.split(' ')[0]; // YYYY-MM-DD

                if (specDay && specDay.trim() !== '') {
                    // Highest priority: Match specific day exactly
                    matchesDateRange = (pDatePart === specDay);
                } else {
                    // Fallback to range or show all if both are empty
                    if (dateFrom && pDatePart < dateFrom) matchesDateRange = false;
                    if (dateTo && pDatePart > dateTo) matchesDateRange = false;
                }
                return matchesSearch && matchesStatus && matchesDateRange;
            });

            window.currentFilteredPayments = filtered;

            filtered.forEach(p => {
                const ps = (p.status || 'PENDING').trim().toUpperCase();
                const ts = (p.tenantStatus || '').trim().toUpperCase();
                const isPaid = (ps === 'PAID' || ps === 'SUCCESS' || ps === 'COMPLETED');
                const isRejected = (ps === 'REJECTED' || ps === 'FAILED' || ts === 'REJECTED' || (p.shopName || '').includes('[REJECTED]'));
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-size:0.75rem; color:var(--text-dim);">${formatTimestamp(p.date)}</td>
                    <td><code style="background:var(--input-bg); padding:3px 8px; border-radius:5px; font-size:0.8rem; color:var(--accent);">极 ${p.ref}</code></td>
                    <td><div style="font-weight:700; color:var(--text-main);">${p.shopName}</div></td>
                    <td><b style="color:var(--text-main);">₱${parseFloat(p.amount).toLocaleString()}</b></td>
                    <td style="text-align:center;">
                        <span style="font-size:0.7rem; font-weight:800; color:var(--text-dim); background:var(--input-bg); padding:4px 10px; border-radius:8px; border:1px solid var(--glass-border);">
                            ${(p.payment_method || 'PAYMONGO').toUpperCase()}
                        </span>
                    </td>
                    <td><span class="badge ${isRejected ? 'badge-error' : (isPaid ? 'badge-active' : (ps === 'PENDING' ? 'badge-warning' : 'badge-error'))}">${isRejected ? 'REJECTED' : (p.status || ps)}</span></td>
                `;
                body.appendChild(tr);
            });

            // Update UI Stats
            const updateStat = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
            const updateLabel = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };

            const isDateFiltered = dateFrom || dateTo || specDay;
            let displaySumGlobal = totalSum;
            let displaySumMonth = monthSum;
            let displayTxnCount = payments.length;

            if (isDateFiltered) {
                let filteredSum = 0;
                let filteredTnxList = filtered.filter(p => {
                    const ps = (p.status || '').toUpperCase();
                    const ts = (p.tenantStatus || '').toUpperCase();
                    const isRejected = (ps === 'REJECTED' || ps === 'FAILED' || ts === 'REJECTED' || (p.shopName || '').includes('[REJECTED]'));
                    const isPaid = (ps === 'PAID' || ps === 'SUCCESS' || ps === 'COMPLETED') && !isRejected;

                    if (isPaid) {
                        filteredSum += parseFloat(p.amount);
                        return true;
                    }
                    return false;
                });

                displaySumGlobal = filteredSum;
                displaySumMonth = filteredSum;
                displayTxnCount = filtered.length;

                updateLabel('label-month-rev', specDay ? 'Day Total' : 'Filtered Total');
                updateLabel('label-total-rev', specDay ? 'Selected Day' : 'Range Revenue');
                updateStat('stat-total-txns', `${displayTxnCount} (Filtered)`);
            } else {
                updateLabel('label-month-rev', 'This Month');
                updateLabel('label-total-rev', 'Total Revenue');
                updateStat('stat-total-txns', displayTxnCount);
            }

            updateStat('stat-total-revenue', `₱${displaySumGlobal.toLocaleString(undefined, { minimumFractionDigits: 2 })}`);
            updateStat('stat-today-revenue', `₱${daySum.toLocaleString(undefined, { minimumFractionDigits: 2 })}`);
            updateStat('stat-week-revenue', `₱${weekSum.toLocaleString(undefined, { minimumFractionDigits: 2 })}`);
            updateStat('stat-month-revenue', `₱${displaySumMonth.toLocaleString(undefined, { minimumFractionDigits: 2 })}`);

            const completedCount = payments.filter(p => { const s = (p.status || '').toUpperCase(); return s === 'PAID' || s === 'SUCCESS'; }).length;
            const pendingCount = payments.filter(p => (p.status || '').toUpperCase() === 'PENDING').length;
            updateStat('stat-total-completed', isDateFiltered ? filtered.filter(p => { const s = (p.status || '').toUpperCase(); return s === 'PAID' || s === 'SUCCESS'; }).length : completedCount);
            updateStat('stat-total-pending', isDateFiltered ? filtered.filter(p => (p.status || '').toUpperCase() === 'PENDING').length : pendingCount);

            // Find Top Shop by Revenue
            let topShop = "N/A";
            let maxRev = 0;
            for (let s in shopRev) {
                if (shopRev[s] > maxRev) { maxRev = shopRev[s]; topShop = s; }
            }
            if (document.getElementById('topShopRev')) {
                document.getElementById('topShopRev').innerText = topShop === "N/A" ? "No Data" : `${topShop} (₱${maxRev.toLocaleString()})`;
            }

            if (document.getElementById('runRate')) {
                const rr = (monthSum / 30) * 30.44;
                document.getElementById('runRate').innerText = `₱${rr.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
            }

            // --- New Advanced Report Insights ---
            renderTopTenants(shopRev, shopTnx);
            renderRevenueByPlan();
            updateSalesReportChart();
        }

        function resetPaymentFilters() {
            document.getElementById('paymentDateFrom').value = '';
            document.getElementById('paymentDateTo').value = '';
            document.getElementById('paymentSpecificDate').value = '';

            document.getElementById('paymentSearch').value = '';
            document.getElementById('paymentStatusFilter').value = 'all';
            renderPayments();
            showToast('Filters Reset');
        }

        function clearPaymentRange() {
            document.getElementById('paymentDateFrom').value = '';
            document.getElementById('paymentDateTo').value = '';
        }

        function renderTopTenants(revObj, tnxObj) {
            const list = document.getElementById('topTenantsPerfList');
            if (!list) return;
            list.innerHTML = '';

            const sorted = Object.entries(revObj).sort((a, b) => b[1] - a[1]).slice(0, 5);
            if (sorted.length === 0) { list.innerHTML = '<p style="color:var(--text-dim);">No sales data available yet.</p>'; return; }

            sorted.forEach(([name, rev], idx) => {
                const item = document.createElement('div');
                item.style = 'display:flex; justify-content:space-between; align-items:center; padding-bottom:0.8rem; border-bottom:1px solid rgba(255,255,255,0.05);';
                item.innerHTML = `
                    <div style="display:flex; align-items:center; gap:0.8rem;">
                        <span style="width:24px; height:24px; border-radius:50%; background:var(--glass-border); display:flex; align-items:center; justify-content:center; font-size:0.7rem;">${idx + 1}</span>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem;">${name}</div>
                            <div style="color:var(--text-dim); font-size:0.75rem;">${tnxObj[name] || 0} Transactions</div>
                        </div>
                    </div>
                    <div style="font-weight:800; color:var(--success);">₱${rev.toLocaleString()}</div>
                `;
                list.appendChild(item);
            });
        }

        function renderRevenueByPlan() {
            const body = document.getElementById('revenueByPlanTable');
            if (!body) return;
            body.innerHTML = '';

            (plans || []).forEach(p => {
                const activeShopsPerPlan = (shops || []).filter(s => s.planId == p.id && (s.status === 'active' || s.status === 'ACTIVE'));
                const monthlyCount = activeShopsPerPlan.filter(s => (s.billing_cycle || 'monthly').toLowerCase() === 'monthly').length;
                const yearlyCount = activeShopsPerPlan.filter(s => (s.billing_cycle || '').toLowerCase() === 'yearly').length;
                const totalCount = activeShopsPerPlan.length;

                // Accurate Monthly Projection: (Monthly Tenants * Monthly Price) + (Yearly Tenants * Yearly Price / 12)
                const monthlyRev = (monthlyCount * p.monthlyPrice) + (yearlyCount * (p.yearlyPrice / 12));
                // Accurate Yearly Projection: (Monthly Tenants * Monthly Price * 12) + (Yearly Tenants * Yearly Price)
                const yearlyRev = (monthlyCount * p.monthlyPrice * 12) + (yearlyCount * p.yearlyPrice);

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><b>${p.name}</b></td>
                    <td>
                        <div style="font-size:0.8rem; color:var(--text-dim); margin-bottom:4px;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--success); margin-right:4px;"></span>
                            ${monthlyCount} Monthly
                        </div>
                        <div style="font-size:0.8rem; color:var(--text-dim); margin-bottom:8px;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--accent); margin-right:4px;"></span>
                            ${yearlyCount} Yearly
                        </div>
                        <span class="badge badge-active" style="display:inline-block; min-width:80px; text-align:center;">${totalCount} TOTAL</span>
                    </td>
                    <td>₱${p.monthlyPrice.toLocaleString()}<br><span style="font-size:0.75rem; color:var(--text-dim);">/ month</span></td>
                    <td>₱${p.yearlyPrice.toLocaleString()}<br><span style="font-size:0.75rem; color:var(--text-dim);">/ year</span></td>
                    <td style="color:var(--success); font-weight:800; text-align:right;">₱${monthlyRev.toLocaleString(undefined, { maximumFractionDigits: 0 })}</td>
                    <td style="color:var(--accent); font-weight:800; text-align:right;">₱${yearlyRev.toLocaleString(undefined, { maximumFractionDigits: 0 })}</td>
                `;
                body.appendChild(tr);
            });
        }

        let salesReportChart = null;
        function updateSalesReportChart() {
            if (typeof Chart === 'undefined') return;
            const ctx = document.getElementById('salesReportTrendsChart');
            if (!ctx) return;

            const now = new Date();
            const labels = [];
            const data = [];

            for (let i = 6; i >= 0; i--) {
                const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
                labels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));

                const daySum = (payments || []).reduce((sum, p) => {
                    const pDate = new Date(p.date.replace(' ', 'T').split('.')[0]);
                    const ps = (p.status || '').trim().toUpperCase();
                    return (pDate.toDateString() === d.toDateString() && (ps === 'PAID' || ps === 'SUCCESS')) ? sum + parseFloat(p.amount) : sum;
                }, 0);
                data.push(daySum);
            }

            if (salesReportChart) salesReportChart.destroy();
            salesReportChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue Growth',
                        data: data,
                        borderColor: '#818cf8',
                        backgroundColor: 'rgba(129, 140, 248, 0.15)',
                        fill: true,
                        tension: 0.5,
                        borderWidth: 4,
                        pointRadius: 6,
                        pointBackgroundColor: '#818cf8',
                        pointBorderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#94a3b8', font: { size: 10 }, callback: v => '₱' + v.toLocaleString() }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8', font: { size: 10 } }
                        }
                    }
                }
            });
        }

        function renderReports() {
            const tenantFilterEl = document.getElementById('reportFilterTenant');
            const dateFilterEl = document.getElementById('reportFilterDate');
            if (!tenantFilterEl || !dateFilterEl) return;

            // 1. Populate Tenant Filter if empty
            if (tenantFilterEl.options.length <= 1 && Array.isArray(shops) && shops.length > 0) {
                shops.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.tenant_id;
                    opt.innerText = s.name;
                    tenantFilterEl.appendChild(opt);
                });
            }

            const selectedTenantId = tenantFilterEl.value;
            const dateFilter = dateFilterEl.value;

            // 2. Filter Database for Chart/Stats (from logs)
            const now = new Date();
            const logSet = Array.isArray(window.logs) ? window.logs : (typeof logs !== 'undefined' ? logs : []);
            let filteredLogs = logSet.filter(L => {
                const matchesTenant = selectedTenantId === 'all' || L.tenant_id == selectedTenantId;
                const logDate = new Date(L.time.replace(' ', 'T').split('.')[0]);
                let matchesDate = true;
                if (dateFilter === '30') matchesDate = (now - logDate) / (1000 * 60 * 60 * 24) <= 30;
                else if (dateFilter === '7') matchesDate = (now - logDate) / (1000 * 60 * 60 * 24) <= 7;
                else if (dateFilter === 'today') matchesDate = logDate.toDateString() === now.toDateString();
                return matchesTenant && matchesDate;
            });

            // 3. Update Stats (Top Cards)
            let totalAppts = filteredLogs.filter(l => l.activity && (l.activity.toLowerCase().includes('booking') || l.activity.toLowerCase().includes('appointment'))).length;
            let totalUsers = filteredLogs.filter(l => l.activity && (l.activity.toLowerCase().includes('registered') || l.activity.toLowerCase().includes('customer'))).length;

            if (dateFilter === 'all' && selectedTenantId === 'all') {
                totalAppts = shops.reduce((sum, s) => sum + (parseInt(s.bookings) || 0), 0);
                totalUsers = shops.reduce((sum, s) => sum + (parseInt(s.customers) || 0), 0);
            }

            document.getElementById('reportStatAppointments').innerText = totalAppts.toLocaleString();
            document.getElementById('reportStatUsers').innerText = totalUsers.toLocaleString();
            document.getElementById('reportStatLogs').innerText = filteredLogs.length.toLocaleString();

            const InteractionLabel = document.getElementById('reportInteractionPeriod');
            if (InteractionLabel) InteractionLabel.innerText = dateFilter === 'all' ? 'All Time' : (dateFilter === 'today' ? 'Activity Today' : `${dateFilter} Days Period`);

            // 4. Populate Tenant Activity Report Table (as per screenshot)
            const body = document.getElementById('reportsTableBody');
            if (!body) return;
            body.innerHTML = '';

            let reportShops = Array.isArray(shops) ? [...shops] : [];
            if (selectedTenantId !== 'all') {
                reportShops = reportShops.filter(s => s.tenant_id == selectedTenantId);
            }

            if (reportShops.length === 0) {
                body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-dim);">No tenants found matching criteria.</td></tr>';
            }

            let grandUsers = 0;
            let grandBookings = 0;
            let grandRevenue = 0;

            reportShops.forEach(s => {
                const tr = document.createElement('tr');
                const lastActStr = s.lastActivity ? formatTimestamp(s.lastActivity) : '—';
                const joinedStr = s.joined ? formatTimestamp(s.joined).split(',')[0] : '—';
                const st = (s.status || '').toLowerCase();

                const u = parseInt(s.users || 0);
                const bVal = parseInt(s.customers || 0);
                const r = parseFloat(s.revenue || 0);

                grandUsers += u;
                grandBookings += bVal;
                grandRevenue += r;

                tr.innerHTML = `
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:32px; height:32px; background:var(--glass); border:1px solid var(--glass-border); border-radius:10px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.8rem; color:var(--accent);">${(s.name || ' ').charAt(0)}</div>
                            <div style="font-weight:800; color:var(--text-main); font-size:0.95rem;">${s.name}</div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:${st === 'active' ? 'var(--success)' : (st === 'pending' ? 'var(--warning)' : 'var(--error)')}; box-shadow:0 0 10px ${st === 'active' ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)'};"></span>
                            <span style="font-weight:700; font-size:0.8rem; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">${st}</span>
                        </div>
                    </td>
                    <td><b style="color:var(--text-main); font-size:1rem;">${u.toLocaleString()}</b></td>
                    <td><b style="color:var(--accent); font-size:1rem;">${bVal.toLocaleString()}</b></td>
                    <td>
                        <div style="background:var(--success-glow); padding:6px 12px; border-radius:10px; border:1px solid rgba(16,185,129,0.1); display:inline-block;">
                            <b style="color:var(--success); font-size:0.95rem;">₱${r.toLocaleString(undefined, { minimumFractionDigits: 2 })}</b>
                        </div>
                    </td>
                    <td style="font-size:0.8rem; color:var(--text-dim); font-weight:500;">${lastActStr}</td>
                    <td style="font-size:0.8rem; color:var(--text-dim); font-weight:500;">${joinedStr}</td>
                `;
                body.appendChild(tr);
            });

            // Add Grand Total Row
            if (reportShops.length > 0) {
                const totalTr = document.createElement('tr');
                totalTr.style.background = 'rgba(99, 102, 241, 0.05)';
                totalTr.style.borderTop = '2px solid var(--accent)';
                totalTr.innerHTML = `
                    <td colspan="2" style="text-align:right; font-weight:900; color:var(--accent); letter-spacing:1px; text-transform:uppercase; font-size:0.75rem; padding-right:2rem;">GRAND TOTAL</td>
                    <td><b style="color:var(--text-main); font-size:1.1rem;">${grandUsers.toLocaleString()}</b></td>
                    <td><b style="color:var(--accent); font-size:1.1rem;">${grandBookings.toLocaleString()}</b></td>
                    <td><b style="color:var(--success); font-size:1.1rem;">₱${grandRevenue.toLocaleString(undefined, { minimumFractionDigits: 2 })}</b></td>
                    <td colspan="2"></td>
                `;
                body.appendChild(totalTr);
            }

            updateSystemUsageChart(filteredLogs);
        }

        let systemUsageChartInst = null;
        function updateSystemUsageChart(dataset) {
            const ctx = document.getElementById('systemUsageTrendsChart');
            if (!ctx) return;

            const dateFilter = document.getElementById('reportFilterDate').value;
            const selectedTenantId = document.getElementById('reportFilterTenant').value;

            let chartLabels = [];
            let chartActivity = [];
            let chartRegs = [];

            // If "All Tenants", use the PRE-FETCHED REAL DATABASE TRENDS (accurate historical data)
            if (selectedTenantId === 'all' && window.reportTrends && window.reportTrends.length > 0) {
                window.reportTrends.forEach(d => {
                    const dateObj = new Date(d.date);
                    chartLabels.push(dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                    chartActivity.push(d.activities);
                    chartRegs.push(d.registrations);
                });
            } else {
                // Specific tenant or filtered set: Calculate from the logs we have
                const now = new Date();
                for (let i = 14; i >= 0; i--) {
                    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
                    chartLabels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));

                    const actions = dataset.filter(l => {
                        const lDate = new Date(l.time.replace(' ', 'T').split('.')[0]);
                        return lDate.toDateString() === d.toDateString();
                    }).length;

                    const registrations = dataset.filter(l => {
                        const lDate = new Date(l.time.replace(' ', 'T').split('.')[0]);
                        const isReg = l.activity && (l.activity.toLowerCase().includes('registered') || l.activity.toLowerCase().includes('customer'));
                        return lDate.toDateString() === d.toDateString() && isReg;
                    }).length;

                    chartActivity.push(actions);
                    chartRegs.push(registrations);
                }
            }

            if (systemUsageChartInst) systemUsageChartInst.destroy();
            systemUsageChartInst = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [
                        { label: 'Platform Usage', data: chartActivity, backgroundColor: 'rgba(99, 102, 241, 0.5)', borderRadius: 8 },
                        { label: 'New Hubs', data: chartRegs, backgroundColor: 'rgba(16, 185, 129, 0.8)', borderRadius: 8, type: 'line', borderColor: '#10b981', tension: 0.4, borderWidth: 3 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { color: 'white', font: { size: 10 } } } },
                    scales: {
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8', font: { size: 10 } } },
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 } } }
                    }
                }
            });
        }

        function downloadCSV(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function generateReport() {
            renderReports();
            showToast('System analytics refreshed!');
        }

        function downloadReportsPDF() {
            try {
                if (typeof window.jspdf === 'undefined') { alert("PDF Library not loaded."); return; }
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF('landscape');
                const now = new Date();

                // 1. Executive Master Header (Professional)
                doc.setFillColor(31, 41, 55); doc.rect(0, 0, 297, 40, 'F');
                doc.setFontSize(26); doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold');
                doc.text("AutoFix Hub", 14, 18);
                doc.setFontSize(10); doc.setFont('helvetica', 'normal');
                doc.text("TENANT ANALYTICS & SYSTEM PERFORMANCE REPORT", 14, 28);
                doc.text(`Exported: ${now.toLocaleString()}`, 14, 34);

                // 2. High-Contrast Summary Cards
                const sReg = document.getElementById('reportStatUsers').innerText;
                const sInt = document.getElementById('reportStatLogs').innerText;
                const sAct = document.getElementById('reportStatAppointments').innerText;

                doc.setDrawColor(203, 213, 225); doc.setLineWidth(0.5);

                // Card 1: Registrations
                doc.setFillColor(255, 255, 255); doc.roundedRect(14, 45, 85, 30, 3, 3, 'FD');
                doc.setFontSize(8); doc.setTextColor(71, 85, 105); doc.setFont('helvetica', 'bold'); doc.text("NEW HUB REGISTRATIONS", 20, 55);
                doc.setFontSize(20); doc.setTextColor(0, 0, 0); doc.text(sReg, 20, 68);

                // Card 2: Interactions
                doc.setFillColor(255, 255, 255); doc.roundedRect(106, 45, 85, 30, 3, 3, 'FD');
                doc.setFontSize(8); doc.setTextColor(71, 85, 105); doc.setFont('helvetica', 'bold'); doc.text("SYSTEM INTERACTIONS", 112, 55);
                doc.setFontSize(20); doc.setTextColor(0, 0, 0); doc.text(sInt, 112, 68);

                // Card 3: Actions
                doc.setFillColor(255, 255, 255); doc.roundedRect(198, 45, 85, 30, 3, 3, 'FD');
                doc.setFontSize(8); doc.setTextColor(71, 85, 105); doc.setFont('helvetica', 'bold'); doc.text("TENANT ACTIONS", 204, 55);
                doc.setFontSize(20); doc.setTextColor(0, 0, 0); doc.text(sAct, 204, 68);

                // 3. Detailed Hub Performance Directory
                let startY = 85;
                doc.setFontSize(14); doc.setTextColor(17, 24, 39); doc.setFont('helvetica', 'bold');
                doc.text("HUB PERFORMANCE DIRECTORY", 14, startY);

                const tS = document.getElementById('reportFilterTenant');
                const dS = document.getElementById('reportFilterDate');
                const filteredShops = (shops || []).filter(s => {
                    const matchesTenant = tS.value === 'all' || s.tenant_id == tS.value;
                    if (!matchesTenant) return false;
                    if (dS.value === 'all') return true;
                    if (!s.joined) return true;
                    const jDate = new Date(s.joined.replace(' ', 'T').split('.')[0]);
                    if (isNaN(jDate.getTime())) return true;
                    if (dS.value === 'today') return jDate.toDateString() === now.toDateString();
                    if (dS.value === '7') return (now - jDate) / (1000 * 60 * 60 * 24) <= 7;
                    if (dS.value === '30') return (now - jDate) / (1000 * 60 * 60 * 24) <= 30;
                    return true;
                });

                const sCols = ["HUB NAME", "OWNER", "STATUS", "PLAN TYPE", "USERS", "BOOKINGS", "TOTAL REVENUE", "JOINED"];
                const sRows = filteredShops.map(s => [
                    s.name || 'N/A', s.owner || 'N/A', (s.status || 'ACTIVE').toUpperCase(),
                    (s.plan_name || 'BASIC').toUpperCase(), s.users || 0, s.bookings || 0,
                    'PHP ' + (parseFloat(s.revenue || 0).toLocaleString()),
                    s.joined ? new Date(s.joined).toLocaleDateString() : 'N/A'
                ]);

                const pdfGrandUsers = filteredShops.reduce((sum, s) => sum + parseInt(s.users || 0), 0);
                const pdfGrandBookings = filteredShops.reduce((sum, s) => sum + parseInt(s.bookings || 0), 0);
                const pdfGrandRevenue = filteredShops.reduce((sum, s) => sum + parseFloat(s.revenue || 0), 0);

                doc.autoTable({
                    startY: startY + 5,
                    head: [sCols],
                    body: sRows,
                    foot: [['TOTAL', '', '', '', pdfGrandUsers.toLocaleString(), pdfGrandBookings.toLocaleString(), 'PHP ' + pdfGrandRevenue.toLocaleString(), '']],
                    theme: 'grid',
                    headStyles: { fillColor: [31, 41, 55], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 10 },
                    footStyles: { fillColor: [241, 245, 249], textColor: [0, 0, 0], fontStyle: 'bold', fontSize: 9 },
                    styles: { fontSize: 8.5, cellPadding: 3, textColor: [17, 24, 39] },
                    alternateRowStyles: { fillColor: [249, 250, 251] },
                    columnStyles: { 6: { fontStyle: 'bold', halign: 'right' }, 0: { fontStyle: 'bold' } }
                });

                // 4. Daily Trends Table (Last 14 Days)
                let trendY = doc.lastAutoTable.finalY + 15;
                if (trendY > 150) { doc.addPage(); trendY = 20; }

                doc.setFontSize(14); doc.setTextColor(17, 24, 39); doc.setFont('helvetica', 'bold');
                doc.text("HISTORICAL GROWTH & ACTIVITY TRENDS (LAST 14 DAYS)", 14, trendY);

                const trendCols = ["DATE", "NEW HUBS", "TOTAL SYSTEM OPS", "REVENUE GENERATED"];
                const trendRows = (window.dashboardTrends || []).reverse().map(t => [
                    new Date(t.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
                    t.new_tenants || 0,
                    t.activities || 0,
                    'PHP ' + parseFloat(t.sales || 0).toLocaleString()
                ]);

                doc.autoTable({
                    startY: trendY + 5,
                    head: [trendCols],
                    body: trendRows,
                    theme: 'striped',
                    headStyles: { fillColor: [79, 70, 229], textColor: [255, 255, 255], fontSize: 10 },
                    styles: { fontSize: 9, cellPadding: 4, textColor: [31, 41, 55] },
                    columnStyles: {
                        1: { halign: 'center' }, 2: { halign: 'center' },
                        3: { fontStyle: 'bold', halign: 'right' }
                    }
                });

                doc.save(`AutoFix_Analytics_Report_${now.toISOString().split('T')[0]}.pdf`);
                showToast('Analytics Report Exported!');
            } catch (err) {
                console.error("PDF Export Error:", err);
                alert("Error generating report: " + err.message);
            }
        }

        function downloadReportsCSV() {
            const tenantFilter = document.getElementById('reportFilterTenant').value;
            const dateFilter = document.getElementById('reportFilterDate').value;

            // Re-filter to get exactly what's on screen
            const now = new Date();
            let filtered = (window.logs || []).filter(L => {
                const matchesTenant = tenantFilter === 'all' || L.tenant_id == tenantFilter;
                const d = new Date(L.time.replace(' ', 'T').split('.')[0]);
                let matchesDate = true;
                if (dateFilter === 'today') matchesDate = d.toDateString() === now.toDateString();
                else if (dateFilter === '7') matchesDate = (now - d) / (1000 * 60 * 60 * 24) <= 7;
                else if (dateFilter === '30') matchesDate = (now - d) / (1000 * 60 * 60 * 24) <= 30;
                return matchesTenant && matchesDate;
            });

            let csv = 'Timestamp,Shop Name,Activity Type,Description\n';
            filtered.forEach(L => {
                csv += `"${L.time}","${L.shop_name || 'System'}","${L.type}","${L.activity.replace(/"/g, '""')}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = `AutoFix_System_Report_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            showToast('CSV Exported!');
        }



        function renderSettings() {
            const s = window.systemSettings || {};
            const setVal = (id, key) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (el.type === 'checkbox') el.checked = (s[key] === 'on' || s[key] === true);
                else el.value = s[key] || el.value;
            };

            setVal('settingAppName', 'app_name');
            setVal('settingSupportEmail', 'support_email');
            setVal('settingMaintenance', 'maintenance_mode');
            setVal('settingMaxStorage', 'max_storage_gb');
            setVal('settingDefaultRole', 'default_staff_role');
            setVal('settingAutoApprove', 'auto_approve_tenant');

            // Apply branding to UI
            if (s['app_name']) document.querySelectorAll('.brand-text span').forEach(el => el.innerText = s['app_name']);
        }

        function saveConfiguration(btnClicked) {
            const btn = btnClicked || document.querySelector('.btn-action[onclick^="saveConfiguration"]');
            const originalText = btn.innerText;

            // Password change validation
            const pass = document.getElementById('settingAdminPassword').value;
            const confirm = document.getElementById('settingAdminPasswordConfirm').value;

            if (pass) {
                if (pass.length < 8) {
                    alert('Password must be at least 8 characters long.');
                    return;
                }
                if (pass !== confirm) {
                    alert('Passwords do not match!');
                    return;
                }
            }

            btn.innerText = '⌛ Saving...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('app_name', document.getElementById('settingAppName').value);
            formData.append('support_email', document.getElementById('settingSupportEmail').value);
            formData.append('maintenance_mode', document.getElementById('settingMaintenance').value);
            formData.append('max_storage_gb', document.getElementById('settingMaxStorage').value);
            formData.append('default_staff_role', document.getElementById('settingDefaultRole').value);
            formData.append('auto_approve_tenant', document.getElementById('settingAutoApprove').checked ? 'on' : 'off');
            
            formData.append('admin_name', document.getElementById('settingAdminName').value);
            formData.append('admin_email', document.getElementById('settingAdminEmail').value);
            
            const avatarFile = document.getElementById('settingAdminAvatarFile').files[0];
            if (avatarFile) {
                formData.append('admin_avatar_file', avatarFile);
            }

            if (pass) {
                formData.append('admin_password', pass);
                formData.append('admin_password_confirm', confirm);
            }

            fetch('dashboard.php?action=save_settings', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast('System settings updated globally!');
                        btn.innerHTML = '✅ Saved';
                        btn.style.background = 'var(--success)';
                        setTimeout(() => {
                            btn.innerText = originalText;
                            btn.style.background = '';
                            location.reload();
                        }, 1500);
                    } else {
                        alert('Save failed: ' + data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    alert('Network error while saving settings.');
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        function addAdmin(btn) {
            const name = document.getElementById('newAdminName').value;
            const email = document.getElementById('newAdminEmail').value;
            const pass = document.getElementById('newAdminPass').value;

            if (!name || !email || !pass) return alert("Please fill in all admin fields.");

            const originalText = btn.innerText;
            btn.innerText = 'Adding...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('name', name);
            fd.append('email', email);
            fd.append('password', pass);

            fetch('dashboard.php?action=add_super_admin', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message);
                        setTimeout(() => location.reload(), 1000);
                    } else alert('Error: ' + data.message);
                })
                .finally(() => { btn.innerText = originalText; btn.disabled = false; });
        }

        function deleteAdmin(id, name) {
            showConfirm('Remove Admin', `Are you sure you want to remove ${name} from the management team? They will lose all platform access immediately.`, () => {
                const fd = new FormData();
                fd.append('id', id);
                fetch('dashboard.php?action=delete_super_admin', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast(data.message);
                            setTimeout(() => location.reload(), 1000);
                        } else alert('Error: ' + data.message);
                    });
            });
        }

        function downloadSalesCSV() {
            let csv = 'Date,Reference,Shop Name,Amount,Status\n';
            payments.forEach(p => {
                csv += `"${p.date}","${p.ref}","${p.shopName}","${p.amount}","${p.status}"\n`;
            });
            const timestamp = new Date().toISOString().split('T')[0];
            downloadCSV(csv, `AutoFix_Sales_Report_${timestamp}.csv`);
            showToast('Sales report exported!');
        }

        function downloadSalesPDF() {
            if (typeof jspdf === 'undefined') { alert("PDF library is still loading, please wait..."); return; }
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();

            // Premium Executive Report Styling (Indigo & Graphite)
            const primaryColor = [79, 70, 229];    // Indigo-600
            const secondaryColor = [71, 85, 105];  // Slate-600
            const dangerColor = [220, 38, 38];     // Red-600
            const successColor = [22, 163, 74];   // Green-600
            const accentBg = [248, 250, 252];      // App Gray

            // 1. Header & Branding Section
            doc.setFillColor(primaryColor[0], primaryColor[1], primaryColor[2]);
            doc.rect(0, 0, 210, 40, 'F'); // Header Banner

            doc.setFontSize(26);
            doc.setTextColor(255, 255, 255);
            doc.setFont('helvetica', 'bold');
            doc.text("AutoFix Hub", 14, 20);

            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text("EXECUTIVE SALES PERFORMANCE REPORT", 14, 28);
            doc.text(`Doc ID: SALES-${new Date().getTime()}`, 14, 34);

            // 2. Report Context (Top Right) --- Using Human Readable Formats
            const pdfGenDate = new Date().toLocaleString('en-US', {
                month: 'long', day: 'numeric', year: 'numeric',
                hour: 'numeric', minute: '2-digit', hour12: true
            });

            doc.setFontSize(9);
            doc.text(`Generated By: Super Admin`, 196, 18, { align: 'right' });
            doc.text(`Date & Time: ${pdfGenDate}`, 196, 24, { align: 'right' });
            doc.text(`Status: ${document.getElementById('paymentStatusFilter').value.toUpperCase()} Transactions`, 196, 30, { align: 'right' });

            // Dynamic Reporting Window Summary with Word-based Months
            const dFrom = document.getElementById('paymentDateFrom').value;
            const dTo = document.getElementById('paymentDateTo').value;
            const sDay = document.getElementById('paymentSpecificDate').value;

            function readableDate(val) {
                if (!val) return '';
                const d = new Date(val);
                return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            }

            let reportWindow = "Lifetime (Cumulative)";
            if (sDay) {
                reportWindow = `Single Day: ${readableDate(sDay)}`;
            } else if (dFrom || dTo) {
                reportWindow = `Range: ${readableDate(dFrom) || 'Start'} to ${readableDate(dTo) || 'Present'}`;
            }
            doc.setFontSize(8);
            doc.setFont('helvetica', 'bold');
            doc.text(`COVERAGE: ${reportWindow}`, 196, 36, { align: 'right' });

            // 3. Executive Summary Block (Data Cards)
            const dataToExport = window.currentFilteredPayments || payments || [];
            let totalRevenue = 0;
            let paidCount = 0;
            let pendingCount = 0;

            dataToExport.forEach(p => {
                const s = (p.status || '').toUpperCase();
                const amt = parseFloat(p.amount) || 0;
                if (s === 'SUCCESS' || s === 'PAID') {
                    totalRevenue += amt;
                    paidCount++;
                } else if (s === 'PENDING') {
                    pendingCount++;
                }
            });

            // Card 1: Net Revenue
            doc.setFillColor(255, 255, 255);
            doc.setDrawColor(241, 245, 249);
            doc.roundedRect(14, 45, 60, 25, 3, 3, 'FD');
            doc.setFontSize(8);
            doc.setTextColor(secondaryColor[0], secondaryColor[1], secondaryColor[2]);
            doc.text("NET REVENUE (PAID)", 18, 52);
            doc.setFontSize(14);
            doc.setTextColor(primaryColor[0], primaryColor[1], primaryColor[2]);
            doc.setFont('helvetica', 'bold');
            doc.text(`PHP ${totalRevenue.toLocaleString()}`, 18, 62);

            // Card 2: Transactions
            doc.setFillColor(255, 255, 255);
            doc.roundedRect(80, 45, 55, 25, 3, 3, 'FD');
            doc.setFontSize(8);
            doc.setTextColor(secondaryColor[0], secondaryColor[1], secondaryColor[2]);
            doc.setFont('helvetica', 'normal');
            doc.text("PAID DELIVERIES", 84, 52);
            doc.setFontSize(14);
            doc.setTextColor(15, 23, 42); // Slate-900
            doc.text(`${paidCount} Successful`, 84, 62);

            // Card 3: Avg Transaction
            const avg = paidCount > 0 ? (totalRevenue / paidCount) : 0;
            doc.setFillColor(255, 255, 255);
            doc.roundedRect(141, 45, 55, 25, 3, 3, 'FD');
            doc.setFontSize(8);
            doc.setTextColor(secondaryColor[0], secondaryColor[1], secondaryColor[2]);
            doc.text("AVG TRANSACTION VALUE", 145, 52);
            doc.setFontSize(14);
            doc.text(`PHP ${Math.round(avg).toLocaleString()}`, 145, 62);

            // 4. Data Table
            const tableHeaders = [["DATE / TIME", "REFERENCE", "TENANT / SHOP", "AMOUNT", "STATUS"]];
            const tableData = dataToExport.map(p => {
                const s = (p.status || '').toUpperCase();

                // Ensure 12-hour format for PDF
                let formattedDate = p.date;
                try {
                    const d = new Date(p.date.replace(' ', 'T'));
                    if (!isNaN(d.getTime())) {
                        formattedDate = d.toLocaleString('en-US', {
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric',
                            hour: 'numeric',
                            minute: '2-digit',
                            hour12: true
                        });
                    }
                } catch (e) { }

                return [
                    formattedDate,
                    p.ref,
                    p.shopName,
                    `PHP ${parseFloat(p.amount).toLocaleString()}`,
                    s
                ];
            });

            // Summary Footer Row
            tableData.push([
                { content: 'REPORT TOTALS', colSpan: 3, styles: { halign: 'right', fontStyle: 'bold', fillColor: [248, 250, 252] } },
                { content: `PHP ${totalRevenue.toLocaleString()}`, styles: { fontStyle: 'bold', fillColor: primaryColor, textColor: 255 } },
                { content: `${paidCount} Paid / ${pendingCount} Pend`, styles: { fillColor: [248, 250, 252], fontStyle: 'bold', halign: 'center' } }
            ]);

            doc.autoTable({
                startY: 78,
                head: tableHeaders,
                body: tableData,
                theme: 'striped',
                headStyles: { fillColor: [30, 41, 59], textColor: 255, fontStyle: 'bold', halign: 'center', fontSize: 9 },
                columnStyles: {
                    0: { cellWidth: 35, fontSize: 8 },
                    1: { cellWidth: 38, fontSize: 8 },
                    2: { fontStyle: 'bold' },
                    3: { halign: 'right', fontStyle: 'bold', textColor: primaryColor },
                    4: { halign: 'center', fontSize: 8 }
                },
                alternateRowStyles: { fillColor: [252, 253, 255] },
                margin: { left: 14, right: 14 },
                didDrawPage: (data) => {
                    // Footer branding
                    doc.setFontSize(8);
                    doc.setTextColor(148, 163, 184);
                    doc.text(`AutoFix Hub Elite Management - Confidence in Every Transaction`, 14, 288);
                    doc.text(`Page ${doc.internal.getNumberOfPages()}`, 190, 288);
                }
            });

            doc.save(`Performance_Report_${new Date().toISOString().split('T')[0]}.pdf`);
            showToast('Executive Report Exported!');
        }



        const chartFont = { family: "'Outfit', sans-serif", size: 11 };
        const gridColor = 'rgba(255, 255, 255, 0.05)';

        function updateCharts() {
            document.querySelectorAll('.chart-loader').forEach(l => l.style.display = 'none');
            if (typeof Chart === 'undefined') return;

            try {
                const canvasSales = document.getElementById('salesTrendsChart');
                const canvasGrowth = document.getElementById('userGrowthChart');
                const canvasActivity = document.getElementById('tenantActivityChart');

                if (window.salesChartInst) window.salesChartInst.destroy();
                if (window.growthChartInst) window.growthChartInst.destroy();
                if (window.activityChartInst) window.activityChartInst.destroy();

                const trends = window.dashboardTrends || [];
                const labels = trends.map(t => new Date(t.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));

                // 1. Sales Trends (Daily Revenue)
                if (canvasSales) {
                    const salesData = trends.map(t => parseFloat(t.sales || 0));
                    window.salesChartInst = new Chart(canvasSales.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Daily Revenue',
                                data: salesData,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                fill: true, tension: 0.4, borderWidth: 3, pointRadius: 4
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false }, title: { display: true, text: 'Revenue Trends (Last 14 Days)', color: 'white' } },
                            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } }
                        }
                    });
                }

                // 2. User Growth (New Tenants per Day)
                if (canvasGrowth) {
                    const tenantData = trends.map(t => parseInt(t.new_tenants || 0));
                    window.growthChartInst = new Chart(canvasGrowth.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'New Tenant Signups',
                                data: tenantData,
                                backgroundColor: '#6366f1',
                                borderRadius: 5
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false }, title: { display: true, text: 'Tenant Growth (Daily Registrations)', color: 'white' } },
                            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8', stepSize: 1 }, beginAtZero: true }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } }
                        }
                    });
                }

                // 3. Overall Activity (Total System Logs per Day)
                if (canvasActivity) {
                    const activityData = trends.map(t => parseInt(t.activities || 0));
                    window.activityChartInst = new Chart(canvasActivity.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'System Interactions',
                                data: activityData,
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                fill: true, tension: 0.4, borderWidth: 3
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false }, title: { display: true, text: 'Platform Usage (Audit Logs Count)', color: '#94a3b8' } },
                            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } }, x: { grid: { display: false }, ticks: { color: '#64748b' } } }
                        }
                    });
                }
            } catch (e) { console.error("Dashboard Chart Error:", e); }
        }
        function renderMasterServices() {
            fetch('dashboard.php?action=fetch_master_services')
                .then(r => r.json())
                .then(data => {
                    const tbody = document.getElementById('masterServicesTableBody');
                    if (!tbody) return;
                    tbody.innerHTML = data.map(s => `
                        <tr>
                            <td><div style="font-weight:700; color:var(--text-main);">${s.service_name}</div></td>
                            <td><span class="badge" style="background:var(--input-bg); color:var(--text-dim); border:1px solid var(--glass-border);">${s.category}</span></td>
                            <td style="color:var(--success); font-weight:700;">₱${parseFloat(s.min_price).toLocaleString()}</td>
                            <td style="color:var(--error); font-weight:700;">₱${parseFloat(s.max_price).toLocaleString()}</td>
                            <td style="text-align:right;">
                                <button class="btn-action" style="background:var(--accent-glow); color:var(--accent); border-radius:8px; padding:5px 10px; border:none;" 
                                    onclick='editMasterService(${JSON.stringify(s)})'><i class="fas fa-edit"></i></button>
                                <button class="btn-action" style="background:rgba(239,68,68,0.1); color:var(--error); border-radius:8px; padding:5px 10px; border:none; margin-left:5px;" 
                                    onclick="deleteMasterService(${s.master_id})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `).join('');
                });
        }

        window.openMasterServiceModal = function () {
            document.getElementById('msModalTitle').innerText = "Add Standard Service";
            document.getElementById('masterServiceForm').reset();
            document.getElementById('ms_id').value = "";
            document.getElementById('masterServiceModal').style.display = "flex";
        };

        window.closeMasterServiceModal = function () {
            document.getElementById('masterServiceModal').style.display = "none";
        };

        window.editMasterService = function (s) {
            document.getElementById('msModalTitle').innerText = "Edit Standard Service";
            document.getElementById('ms_id').value = s.master_id;
            document.getElementById('ms_name').value = s.service_name;
            document.getElementById('ms_cat').value = s.category;
            document.getElementById('ms_min').value = s.min_price;
            document.getElementById('ms_max').value = s.max_price;
            document.getElementById('masterServiceModal').style.display = "flex";
        };

        window.saveMasterService = function (e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch('dashboard.php?action=save_master_service', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(res => {
                if (res.status === 'success') {
                    closeMasterServiceModal();
                    renderMasterServices();
                } else {
                    alert(res.message);
                }
            });
        };

        window.deleteMasterService = function (id) {
            if (!confirm("Are you sure you want to remove this service standard? Shops using it will no longer be restricted.")) return;
            const formData = new FormData();
            formData.append('id', id);
            fetch('dashboard.php?action=delete_master_service', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(res => {
                if (res.status === 'success') renderMasterServices();
            });
        };

        function renderShops() {
            const query = document.getElementById('shopSearch').value.toLowerCase();
            const sort = document.getElementById('shopSortOrder').value;
            const body = document.getElementById('shopsTableBody');
            body.innerHTML = '';

            // Calculate revenue per shop first if needed for sorting
            const revenueMap = {};
            const paymentList = Array.isArray(payments) ? payments : [];
            paymentList.forEach(p => {
                const ps = (p.status || '').trim().toUpperCase();
                if (p && (ps === 'PAID' || ps === 'SUCCESS')) {
                    revenueMap[p.tenant_id] = (revenueMap[p.tenant_id] || 0) + parseFloat(p.amount || 0);
                }
            });

            // Sort and Render
            const shopList = Array.isArray(shops) ? shops : [];

            // Dynamic Pending Hub Count (for Sidebar Notification)
            const globalPending = shopList.filter(s => {
                const st = (s.status || '').trim().toUpperCase();
                const name = (s.name || '').trim().toUpperCase();
                // Exclude anyone already marked as REJECTED in name or status
                if (name.includes('[REJECTED]') || st === 'REJECTED') return false;
                return st === 'PENDING' || st === '';
            }).length;
            const sBadge = document.getElementById('sidebar-pending-badge');
            if (sBadge) {
                sBadge.textContent = globalPending;
                sBadge.style.display = globalPending > 0 ? 'block' : 'none';
            }

            // Update Pending Alert
            const pendingAlert = document.getElementById('pendingAlertSection');
            const pendingText = document.getElementById('pendingCountText');
            if (pendingAlert && pendingText) {
                if (globalPending > 0) {
                    pendingAlert.style.display = 'flex';
                    pendingText.innerText = globalPending;
                    showToast('New Tenant Application Pending!');
                } else {
                    pendingAlert.style.display = 'none';
                }
            }

            let filtered = shopList.filter(s => {
                if (!s) return false;
                const st = (s.status || '').trim().toUpperCase();
                if (st === 'REJECTED' || st === 'ARCHIVED') return false;
                return (s.name || '').toLowerCase().includes(query) || (s.owner || '').toLowerCase().includes(query) || (s.email || '').toLowerCase().includes(query);
            });

            // Sorting Logic
            filtered.sort((a, b) => {
                if (sort === 'newest') return parseInt(b.tenant_id) - parseInt(a.tenant_id);
                if (sort === 'oldest') return parseInt(a.tenant_id) - parseInt(b.tenant_id);
                if (sort === 'customers') return (b.customers || 0) - (a.customers || 0);
                if (sort === 'bookings') return (b.bookings || 0) - (a.bookings || 0);
                if (sort === 'revenue') return (revenueMap[b.tenant_id] || 0) - (revenueMap[a.tenant_id] || 0);
                return 0;
            });

            filtered.forEach(s => {
                const tr = document.createElement('tr');
                let st = (s.status || 'pending').toLowerCase();

                // Dossier Review Button (Always show for admin oversight)
                const dossierBtn = `<button class="btn-action" style="padding: 0.6rem 1rem; border-radius: 10px; font-size: 0.75rem; background: rgba(59,130,246,0.1); color:#3b82f6; border:1px solid rgba(59,130,246,0.2); margin-right:8px;" onclick="openDossier(${s.tenant_id})"><i class="fa-solid fa-address-card"></i> View Dossier</button>`;

                let actionsHTML = ``;
                if (st === 'pending') {
                    actionsHTML = `
                        ${dossierBtn}
                        <button class="btn-action" style="padding: 0.7rem 1.2rem; border-radius: 12px; font-size: 0.8rem; font-weight: 800; background: var(--success); color:white; border:none; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);" onclick="approveTenant(${s.tenant_id})">APPROVE HUB</button>
                        <button class="btn-action" style="padding: 0.7rem 1.2rem; border-radius: 12px; font-size: 0.8rem; font-weight: 800; background: rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); margin-left:8px;" onclick="rejectTenant(${s.tenant_id})">REJECT</button>
                    `;
                } else if (st === 'active') {
                    actionsHTML = `
                        ${dossierBtn}
                        <button class="btn-action" style="padding: 0.6rem 1rem; border-radius: 10px; font-size: 0.75rem; background: rgba(245,158,11,0.1); color:var(--warning); border:1px solid rgba(245,158,11,0.2);" onclick="quickTenantAction(${s.tenant_id}, 'suspended')"><i class="fa-solid fa-ban"></i> Suspend</button>
                    `;
                } else if (st === 'suspended') {
                    actionsHTML = `
                        ${dossierBtn}
                        <button class="btn-action" style="padding: 0.6rem 1rem; border-radius: 10px; font-size: 0.75rem; background: rgba(16,185,129,0.1); color:var(--success); border:1px solid rgba(16,185,129,0.2);" onclick="quickTenantAction(${s.tenant_id}, 'active')"><i class="fa-solid fa-unlock"></i> Re-activate</button>
                    `;
                } else {
                    actionsHTML = `
                        ${dossierBtn}
                        <button class="btn-action" style="padding: 0.6rem 1rem; border-radius: 10px; font-size: 0.75rem; background: var(--accent);" onclick="quickTenantAction(${s.tenant_id}, 'active')">Restore</button>
                    `;
                }

                if (st !== 'pending') {
                    actionsHTML += `<button class="btn-action" style="padding: 0.6rem 1rem; border-radius: 10px; font-size: 0.75rem; background: rgba(255,255,255,0.03); color:white; border: 1px solid var(--glass-border); margin-left: 8px;" onclick="editShop(${s.tenant_id})"><i class="fa-solid fa-pen-to-square"></i> Edit</button>`;
                }

                const cycle = (s.billing_cycle || 'monthly').toLowerCase();
                // Ensure planId is compared as numbers
                const currentPlan = plans.find(p => parseInt(p.id) === parseInt(s.planId || s.plan_id || 0));
                const planName = (currentPlan ? currentPlan.name : (s.planName || 'TRIAL')).toUpperCase();
                const price = currentPlan ? (cycle === 'yearly' ? currentPlan.yearlyPrice : currentPlan.monthlyPrice) : 0;
                const formattedPrice = price > 0 ? '₱' + parseFloat(price).toLocaleString() : 'FREE';

                const s_color = (st === 'pending' ? 'badge-warning' : (st === 'active' ? 'badge-active' : (st === 'suspended' ? 'badge-info' : 'badge-error')));
                const s_text = (st || 'unknown').toUpperCase();

                const shopUrl = `shop.php?id=${s.slug || s.tenant_id}`;

                tr.innerHTML = `
                    <td>
                        <div style="display:flex; align-items:center; gap:1.2rem;">
                            <div style="width:50px; height:50px; background:var(--glass); border:1px solid var(--glass-border); border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:900; color:var(--accent); font-size:1.2rem; box-shadow:0 10px 20px -5px rgba(0,0,0,0.2);">
                                ${s.name.charAt(0)}
                            </div>
                            <div>
                                <a href="${shopUrl}" target="_blank" style="text-decoration:none; display:flex; align-items:center; gap:6px;">
                                    <div style="font-weight:800; font-size:1.15rem; color:var(--text-main);">${s.name}</div>
                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.7rem; color:var(--accent);"></i>
                                </a>
                                <div style="font-size:0.8rem; color:var(--text-dim); margin-top:4px; display:flex; align-items:center; gap:10px;">
                                    <span><i class="fa-solid fa-user-circle" style="opacity:0.6;"></i> ${s.owner}</span>
                                    <span style="opacity:0.2;">|</span>
                                    <span><i class="fa-solid fa-envelope-open" style="opacity:0.6;"></i> ${s.email}</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="background:var(--accent-glow); padding:10px 15px; border-radius:12px; border:1px solid rgba(99,102,241,0.1); display:inline-block;">
                            <div style="font-weight:900; color:var(--accent); font-size:0.7rem; text-transform:uppercase; letter-spacing:1px;">${planName}</div>
                            <div style="font-size:1.15rem; color:var(--text-main); font-weight:900; margin-top:4px;">${formattedPrice} <span style="font-size:0.7rem; color:var(--text-dim); font-weight:500;">/ ${cycle}</span></div>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:inline-flex; flex-direction:column; align-items:center; background:var(--glass); padding:8px 15px; border-radius:12px; border:1px solid var(--glass-border);">
                            <div style="font-size:1.2rem; font-weight:900; color:var(--text-main);">${s.customers || 0}</div>
                            <div style="font-size:0.65rem; color:var(--text-dim); font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-top:2px;">Users</div>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <div class="badge ${s_color}" style="min-width:110px; padding:10px; font-size:0.7rem;">
                            ${s_text}
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex; justify-content:flex-end; align-items:center;">
                            ${actionsHTML}
                        </div>
                    </td>
                `;
                body.appendChild(tr);
            });
        }

        function scrollToPending() {
            const table = document.querySelector('.data-table');
            if (table) table.scrollIntoView({ behavior: 'smooth' });
        }

        function viewProof(url) {
            const container = document.getElementById('proofContainer');
            if (url.toLowerCase().endsWith('.pdf')) {
                container.innerHTML = `<embed src="${url}" type="application/pdf" width="100%" height="600px" />`;
            } else {
                container.innerHTML = `<img src="${url}" style="max-width: 100%; max-height: 600px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);" />`;
            }
            document.getElementById('proofModal').style.display = 'flex';
        }

        function closeProofModal() {
            document.getElementById('proofModal').style.display = 'none';
            document.getElementById('proofContainer').innerHTML = '';
        }

        // --- Verification Dossier Logic ---
        function openDossier(id) {
            const s = (shops || []).find(sh => sh.tenant_id == id);
            if (!s) return;

            document.getElementById('dos-shop').textContent = s.name || 'N/A';
            document.getElementById('dos-owner').textContent = s.owner || 'N/A';
            document.getElementById('dos-email').textContent = s.email || 'N/A';
            document.getElementById('dos-address').textContent = s.address || 'N/A';
            document.getElementById('dos-id-type').textContent = (s.id_type || s.idType || 'NOT PROVIDED').toUpperCase();

            // ID Photo
            const idImg = document.getElementById('dos-id-img');
            if (s.id_photo_url) {
                idImg.src = s.id_photo_url;
                idImg.style.display = 'block';
            } else {
                idImg.style.display = 'none';
            }

            // Business Proof (PDF or Image)
            const proofContainer = document.getElementById('dos-proof-container');
            proofContainer.innerHTML = '';
            if (s.business_proof_url) {
                const url = s.business_proof_url.toLowerCase();
                if (url.endsWith('.pdf')) {
                    proofContainer.innerHTML = `<embed src="${s.business_proof_url}" type="application/pdf" width="100%" height="100%" />`;
                } else {
                    proofContainer.innerHTML = `<img src="${s.business_proof_url}" style="max-width:100%; max-height:450px; object-fit:contain; border-radius:8px;" onclick="window.open(this.src)">`;
                }
            } else {
                proofContainer.innerHTML = `<div style="color:var(--text-dim); font-size:0.8rem;">No business proof uploaded</div>`;
            }

            // Actions
            const actions = document.getElementById('dossierActions');
            const st = (s.status || 'pending').toLowerCase();

            if (st === 'pending') {
                actions.innerHTML = `
                    <button class="btn-action" style="padding: 1rem 2.5rem; border-radius: 14px; background: var(--success); color: white; border: none; font-weight: 900; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);" onclick="approveTenant(${s.tenant_id}); closeDossierModal();">APPROVE THIS HUB</button>
                `;
            } else {
                actions.innerHTML = `
                    <div style="background:rgba(255,255,255,0.05); padding:1rem 2rem; border-radius:12px; border:1px solid var(--glass-border); color:var(--text-dim); font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
                        <i class="fa-solid fa-check-circle" style="color:var(--success); margin-right:8px;"></i> Hub status: ${st}
                    </div>
                `;
            }

            document.getElementById('dossierModal').style.display = 'flex';
        }

        function closeDossierModal() {
            document.getElementById('dossierModal').style.display = 'none';
        }

        function reviewPendingDossiers() {
            const pending = (shops || []).filter(s => {
                const st = (s.status || '').trim().toUpperCase();
                return st === 'PENDING' || st === '';
            });

            if (pending.length > 0) {
                openDossier(pending[0].tenant_id);
            } else {
                showToast("No pending registrations to review.");
            }
        }

        function approveTenant(id) {
            const s = shops.find(sh => sh.tenant_id == id);
            showConfirm('Approve Hub', `Activate ${s.name}? This will send their login credentials via email and allow them to start operations immediately.`, () => {
                const formData = new FormData();
                formData.append('id', id);

                fetch('dashboard.php?action=approve_tenant', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast(`${s.name} is now ACTIVE! Welcome email sent.`);
                            // Update local state
                            const sIdx = shops.findIndex(sh => sh.tenant_id == id);
                            if (sIdx !== -1) shops[sIdx].status = 'ACTIVE';
                            refreshUI();
                        } else showAlert('Error', data.message, 'error');
                    })
                    .catch(err => showAlert('Connection Error', 'Network error. Approval failed.', 'error'));
            });
        }

        function rejectTenant(id) {
            const s = shops.find(sh => sh.tenant_id == id);
            showConfirm('Reject Hub', `Are you sure you want to REJECT ${s.name}? This will notify the applicant and cancel their subscription.`, () => {
                const reason = prompt("Enter reason for rejection (optional):", "Documentation verification failed or incomplete.");
                if (reason === null) return; // Cancelled prompt

                const formData = new FormData();
                formData.append('id', id);
                formData.append('reason', reason);

                fetch('dashboard.php?action=reject_tenant', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast(`${s.name} has been REJECTED.`);
                            setTimeout(() => location.reload(), 1000);
                        } else showAlert('Error', data.message, 'error');
                    })
                    .catch(err => showAlert('Connection Error', 'Network error. Rejection failed.', 'error'));
            });
        }

        function quickTenantAction(id, newStatus) {
            const s = shops.find(sh => sh.tenant_id == id);
            if (!s) return;
            const formData = new FormData();
            formData.append('id', id);
            formData.append('shop_name', s.name);
            formData.append('owner_name', s.owner);
            formData.append('email', s.email);
            formData.append('status', newStatus);
            formData.append('expiry', s.expiry || '');
            formData.append('plan_id', s.planId || '');
            formData.append('billing_cycle', s.billing_cycle || 'monthly');

            showConfirm('Status Update', `Are you sure you want to mark ${s.name} as ${newStatus.toUpperCase()}?`, () => {
                fetch('dashboard.php?action=edit_shop', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Status updated successfully');
                            s.status = newStatus;
                            refreshUI();
                        } else showAlert('Error', data.message, 'error');
                    });
            });
        }

        function renderPlans() {
            const grid = document.getElementById('plansGrid'); grid.innerHTML = '';
            if (!plans || plans.length === 0) return;
            plans.forEach(p => {
                const price = isYearly ? p.yearlyPrice : p.monthlyPrice;

                // Define premium features per plan tier (Prioritize DB field, fallback to hardcoded logic)
                let tierFeatures = [];
                if (p.features && p.features.trim() !== '') {
                    tierFeatures = p.features.split('\n').map(f => f.trim()).filter(f => f !== '');
                } else {
                    const pName = p.name.toUpperCase();
                    if (pName.includes('BASIC')) {
                        tierFeatures = ["Real-time Dashboard", "Appointment Booking", "Customer History Tracking", "Email Maintenance Reminders", "Basic Sales Analytics"];
                    } else if (pName.includes('PRO')) {
                        tierFeatures = ["Everything in Basic", "Inventory Management", "Multi-Shop Management Hub", "Full Audit Trail Logging", "Automated Billing Engine", "SMS Appointment Alerts"];
                    } else { // ENTERPRISE
                        tierFeatures = ["Everything in PRO", "Priority 24/7 Support", "Custom Domain Branding", "Unlimited Data Retention", "API Integration Access", "Dedicated Success Manager"];
                    }
                }

                const div = document.createElement('div'); div.className = 'plan-card';
                div.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-weight:900; color:var(--accent); font-size:1.1rem; letter-spacing:0.1em; text-transform:uppercase;">${p.name}</div>
                        <span class="badge ${p.status === 'active' ? 'badge-active' : 'badge-error'}" style="padding:4px 12px; font-size:0.65rem;">${p.status}</span>
                    </div>
                    <div class="plan-price">₱${price.toLocaleString()}<span>/${isYearly ? 'year' : 'month'}</span></div>
                    <ul class="plan-features" style="border-top:1px solid var(--glass-border); padding-top:2rem;">
                        <li style="color:var(--text-main); font-weight:700;"><i class="fa-solid fa-users" style="color:var(--accent);"></i> Up to <b>${p.maxUsers}</b> Staff Users</li>
                        <li style="color:var(--text-main); font-weight:700; margin-bottom:1.5rem;"><i class="fa-solid fa-car-on" style="color:var(--accent);"></i> <b>${p.maxBays}</b> Active Service Bays</li>
                        ${tierFeatures.map(f => `<li style="color:var(--text-dim);"><i class="fa-solid fa-circle-check" style="color:var(--success); font-size:0.7rem; opacity:0.8;"></i> ${f}</li>`).join('')}
                    </ul>
                    <div style="display:flex; gap:10px; margin-top:auto;">
                        <button class="btn-action" style="flex:1; padding:0.9rem; border-radius:15px; background:var(--input-bg); color:var(--text-main); border:1px solid var(--glass-border); font-weight:800; transition:0.3s;" onmouseover="this.style.background='var(--accent)'; this.style.color='white';" onmouseout="this.style.background='var(--input-bg)'; this.style.color='var(--text-main)';" onclick="editPlan(${p.id})">
                            <i class="fa-solid fa-gear" style="margin-right:8px; opacity:0.7;"></i> EDIT
                        </button>
                        <button class="btn-action" style="padding:0.9rem 1.2rem; border-radius:15px; background:rgba(239,68,68,0.1); color:var(--error); border:1px solid rgba(239,68,68,0.2); transition:0.3s;" onmouseover="this.style.background='var(--error)'; this.style.color='white';" onmouseout="this.style.background='rgba(239,68,68,0.1)'; this.style.color='var(--error)';" onclick="deletePlan(${p.id}, '${p.name}')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                `;
                grid.appendChild(div);
            });
        }

        function renderLogs() {
            const q = document.getElementById('logSearch').value.toLowerCase();
            const f = document.getElementById('logTypeFilter').value;
            const body = document.getElementById('logsTableBody');
            body.innerHTML = '';
            logs.filter(l => (l.shop_name?.toLowerCase().includes(q) || l.activity.toLowerCase().includes(q)) && (f === 'all' || l.type === f)).forEach(l => {
                const tr = document.createElement('tr');

                // Normalizing name to Super Admin if it's the old Main Admin
                let staffNameClean = (l.staff_name || '').replace(/Main Admin/gi, 'Super Admin').replace(/User /gi, '');
                let activityClean = (l.activity || '').replace(/Main Admin/gi, 'Super Admin').replace(/User /gi, '');

                const staffStr = staffNameClean ? ` <span style="font-size:0.75rem; font-weight:500; color:var(--text-dim); opacity:0.8;">via ${staffNameClean}</span>` : '';

                const source = l.shop_name
                    ? `<div style="display:flex; flex-direction:column;"><span style="color:var(--accent); font-weight:800; font-size:0.9rem;">${l.shop_name}</span>${staffStr}</div>`
                    : `<div style="display:flex; flex-direction:column;"><span style="color:var(--text-main); font-weight:800; font-size:0.9rem;">System</span>${staffStr}</div>`;

                const typeStr = (l.type || 'INFO').toUpperCase();
                let badgeClass = 'badge-info';
                if (typeStr === 'SECURITY') badgeClass = 'badge-error';
                else if (typeStr === 'AUTH') badgeClass = 'badge-warning';
                else if (typeStr === 'REGISTRATION') badgeClass = 'badge-active';
                else if (typeStr === 'CRUD') badgeClass = 'badge-active';

                tr.innerHTML = `
                    <td style="font-size:0.8rem; color:var(--text-dim); font-weight:500;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-regular fa-clock" style="font-size:0.7rem; opacity:0.5;"></i>
                            ${formatTimestamp(l.time)}
                        </div>
                    </td>
                    <td>${source}</td>
                    <td style="color:rgba(255,255,255,0.85); font-size:0.95rem; font-weight:500;">${activityClean}</td>
                    <td style="text-align:right;">
                        <span class="badge ${badgeClass}" style="min-width:110px; text-align:center; font-size:0.65rem;">${typeStr}</span>
                    </td>
                `;
                body.appendChild(tr);
            });
        }


        function togglePricing() {
            isYearly = !isYearly;
            const switcher = document.getElementById('pricingSwitcher');
            const optM = document.getElementById('opt-monthly');
            const optY = document.getElementById('opt-yearly');
            if (isYearly) {
                switcher.classList.add('is-yearly');
                optY.classList.add('active');
                optM.classList.remove('active');
            } else {
                switcher.classList.remove('is-yearly');
                optM.classList.add('active');
                optY.classList.remove('active');
            }
            renderPlans();
        }
        function openShopModal() {
            document.getElementById('shopModalTitle').innerText = 'Onboard Your Shop';
            document.getElementById('shopModalSub').innerText = 'Join the 500+ tenants scaling their business with AutoFix Hub.';
            document.getElementById('shopForm').reset();
            document.getElementById('shopId').value = '';

            // Build options for both Monthly and Yearly
            let planOptions = '';
            plans.forEach(p => {
                planOptions += `<option value="${p.id}_monthly">${p.name} - ₱${p.monthlyPrice}/mo</option>`;
                planOptions += `<option value="${p.id}_yearly">${p.name} - ₱${p.yearlyPrice}/yr</option>`;
            });
            document.getElementById('shopPlan').innerHTML = planOptions;

            document.getElementById('passwordFields').style.display = 'grid';
            document.getElementById('adminFields').style.display = 'none';
            document.getElementById('shopSubmitBtn').innerText = 'Verify Email & Proceed';
            document.getElementById('shopSubmitBtn').style.width = '100%';
            updateHiddenPlanFields();

            document.getElementById('shopModal').style.display = 'flex';
        }
        function closeShopModal() { document.getElementById('shopModal').style.display = 'none'; }

        function updateHiddenPlanFields() {
            const planSelect = document.getElementById('shopPlan');
            if (planSelect.value) {
                const parts = planSelect.value.split('_');
                document.getElementById('hiddenPlanId').value = parts[0];
                document.getElementById('hiddenBillingCycle').value = parts[1] || 'monthly';
            }
        }

        function openPlanModal(isEdit = false) {
            if (!isEdit) {
                document.getElementById('planId').value = '';
                document.getElementById('planName').value = '';
                document.getElementById('monthlyPrice').value = '';
                document.getElementById('yearlyPrice').value = '';
                document.getElementById('maxUsers').value = '';
                document.getElementById('maxBays').value = '';
                document.getElementById('planFeatures').value = '';
                document.querySelector('#planModal h2').innerText = 'Add New Subscription Tier';
            } else {
                document.querySelector('#planModal h2').innerText = 'Edit Subscription Plan';
            }
            document.getElementById('planModal').style.display = 'flex';
        }

        function showAlert(title, message, type = 'info') {
            document.getElementById('notiTitle').innerText = title;
            document.getElementById('notiMessage').innerText = message;
            const icon = document.getElementById('notiIcon');
            const btn = document.getElementById('notiConfirmBtn');
            const cancelBtn = document.getElementById('notiCancelBtn');
            cancelBtn.style.display = 'none';
            btn.onclick = closeNotiModal;
            if (type === 'error') {
                icon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                icon.style.color = 'var(--error)';
                icon.style.background = 'rgba(239, 68, 68, 0.1)';
            } else if (type === 'success') {
                icon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                icon.style.color = 'var(--success)';
                icon.style.background = 'rgba(16, 185, 129, 0.1)';
            } else {
                icon.innerHTML = '<i class="fa-solid fa-circle-info"></i>';
                icon.style.color = 'var(--accent)';
                icon.style.background = 'rgba(99, 102, 241, 0.1)';
            }
            document.getElementById('notificationModal').style.display = 'flex';
        }

        function showConfirm(title, message, onConfirm) {
            document.getElementById('notiTitle').innerText = title;
            document.getElementById('notiMessage').innerText = message;
            const icon = document.getElementById('notiIcon');
            const btn = document.getElementById('notiConfirmBtn');
            const cancelBtn = document.getElementById('notiCancelBtn');
            icon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
            icon.style.color = 'var(--warning)';
            icon.style.background = 'rgba(245, 158, 11, 0.1)';
            cancelBtn.style.display = 'block';
            btn.onclick = () => { onConfirm(); closeNotiModal(); };
            document.getElementById('notificationModal').style.display = 'flex';
        }

        function closeNotiModal() { document.getElementById('notificationModal').style.display = 'none'; }

        // ANNOUNCEMENT HUB
        function toggleAnnouncement() {
            const panel = document.getElementById('annPanel');
            const overlay = document.getElementById('annOverlay');
            panel.classList.toggle('active');
            overlay.classList.toggle('active');
            // Reset editor state when closing
            if (!panel.classList.contains('active')) {
                document.getElementById('annDisplay').style.display = 'block';
                document.getElementById('annEditor').style.display = 'none';
                document.getElementById('annSaveBtn').style.display = 'none';
                document.getElementById('editAnnBtn').style.display = 'block';
            }
        }

        function enableAnnEdit() {
            const display = document.getElementById('annDisplay');
            const editor = document.getElementById('annEditor');
            editor.value = display.innerText;
            display.style.display = 'none';
            editor.style.display = 'block';
            document.getElementById('annSaveBtn').style.display = 'block';
            document.getElementById('editAnnBtn').style.display = 'none';
        }

        function saveAnnEdit() {
            const val = document.getElementById('annEditor').value;
            const btn = document.getElementById('annSaveBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Broadcasting...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('announcement', val);

            fetch('dashboard.php?action=edit_announcement', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('annDisplay').innerText = val;
                        document.getElementById('annDisplay').style.display = 'block';
                        document.getElementById('annEditor').style.display = 'none';
                        document.getElementById('annSaveBtn').style.display = 'none';
                        document.getElementById('editAnnBtn').style.display = 'block';
                        showToast('Broadcast successfully updated to all shop portals!');
                    } else alert(data.message);
                })
                .finally(() => {
                    btn.innerHTML = 'Public Broadcast <i class="fas fa-paper-plane" style="margin-left:8px;"></i>';
                    btn.disabled = false;
                });
        }
        function closePlanModal() { document.getElementById('planModal').style.display = 'none'; }

        function applyFeatureTemplate() {
            const t = document.getElementById('featureTemplate').value;
            const area = document.getElementById('planFeatures');
            if (t === 'basic') {
                area.value = "Real-time Dashboard\nAppointment Booking\nCustomer History Tracking\nEmail Maintenance Reminders\nBasic Sales Analytics";
            } else if (t === 'pro') {
                area.value = "Everything in Basic\nInventory Management\nMulti-Shop Management Hub\nFull Audit Trail Logging\nAutomated Billing Engine\nSMS Appointment Alerts";
            } else if (t === 'enterprise') {
                area.value = "Everything in PRO\nPriority 24/7 Support\nCustom Domain Branding\nUnlimited Data Retention\nAPI Integration Access\nDedicated Success Manager";
            } else if (t === 'premium') {
                area.value = "All Enterprise Features\nDedicated Account Manager\nCustom CRM Integration\nWhite-label Mobile App\nAnnual Business Strategy Review\nVIP Workshop Onboarding";
            }
            document.getElementById('featureTemplate').value = ""; // Reset
        }
        function clearAllLogs() {
            showConfirm('Clear Audit Trail', 'Are you sure you want to permanently clear all system activity logs? This action is irreversible and will remove all history except for this entry.', () => {
                fetch('dashboard.php?action=clear_logs_db', { method: 'POST' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Audit trail cleared successfully.');
                            const now = new Date();
                            const year = now.getFullYear();
                            const month = String(now.getMonth() + 1).padStart(2, '0');
                            const day = String(now.getDate()).padStart(2, '0');
                            const hours = String(now.getHours()).padStart(2, '0');
                            const minutes = String(now.getMinutes()).padStart(2, '0');
                            const seconds = String(now.getSeconds()).padStart(2, '0');
                            const timeStr = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
                            logs = [{
                                time: timeStr,
                                source: 'System',
                                activity: 'Platform Audit Trail purged by Super Admin',
                                type: 'SECURITY',
                                staff_name: ''
                            }];
                            refreshUI();
                        } else showAlert('Error', data.message, 'error');
                    })
                    .catch(err => showAlert('Error', 'Failed to clear logs from database.', 'error'));
            });
        }

        document.getElementById('shopForm').addEventListener('submit', function (e) {
            const id = document.getElementById('shopId').value;
            if (id) {
                // This is an EDIT, handle via AJAX
                e.preventDefault();
                updateHiddenPlanFields();

                const formData = new FormData();
                formData.append('id', id);
                formData.append('shop_name', document.getElementById('shopName').value);
                formData.append('owner_name', document.getElementById('shopOwner').value);
                formData.append('email', document.getElementById('shopEmail').value);
                formData.append('status', document.getElementById('shopStatus').value);
                formData.append('expiry', document.getElementById('shopExpiry').value);
                formData.append('plan_id', document.getElementById('hiddenPlanId').value);
                formData.append('billing_cycle', document.getElementById('hiddenBillingCycle').value);

                const btn = document.getElementById('shopSubmitBtn');
                btn.innerText = 'Updating...';
                btn.disabled = true;

                fetch('dashboard.php?action=edit_shop', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Tenant successfully updated');
                            // Quick memory update so UI refreshes without full reload
                            const idx = shops.findIndex(s => s.tenant_id == id);
                            if (idx !== -1) {
                                shops[idx].name = document.getElementById('shopName').value;
                                shops[idx].owner = document.getElementById('shopOwner').value;
                                shops[idx].email = document.getElementById('shopEmail').value;
                                shops[idx].status = document.getElementById('shopStatus').value.toLowerCase();
                                shops[idx].expiry = document.getElementById('shopExpiry').value;
                                shops[idx].planId = document.getElementById('hiddenPlanId').value;
                                shops[idx].billing_cycle = document.getElementById('hiddenBillingCycle').value;
                            }
                            refreshUI();
                            closeShopModal();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(err => alert('Network error. Database sync failed.'))
                    .finally(() => { btn.innerText = 'Save Changes'; btn.disabled = false; });

            } else {
                // This is a NEW registration, allow natural form submission to verify-email.php
                const pass = document.getElementById('shopPassword').value;
                const confirm = document.getElementById('shopConfirmPassword').value;
                if (pass !== confirm) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return;
                }
            }
        });

        document.getElementById('planForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const id = document.getElementById('planId').value;
            const name = document.getElementById('planName').value;
            const mPrice = document.getElementById('monthlyPrice').value;
            const yPrice = document.getElementById('yearlyPrice').value;
            const maxU = document.getElementById('maxUsers').value;
            const maxB = document.getElementById('maxBays').value;
            const feat = document.getElementById('planFeatures').value;

            // REAL SYNC TO DATABASE
            const formData = new FormData();
            formData.append('id', id);
            formData.append('name', name);
            formData.append('monthlyPrice', mPrice);
            formData.append('yearlyPrice', yPrice);
            formData.append('maxUsers', maxU);
            formData.append('maxBays', maxB);
            formData.append('features', feat);

            fetch('dashboard.php?action=edit_plan', {
                method: 'POST',
                body: formData
            })
                .then(res => res.text()) // Get text first for debug if not JSON
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.status === 'success') {
                            if (!id) {
                                // Reload page to get new ID from server for new plan
                                location.reload();
                                return;
                            }
                            const idx = plans.findIndex(p => p.id == id);
                            if (idx !== -1) {
                                plans[idx] = {
                                    ...plans[idx],
                                    name,
                                    monthlyPrice: parseFloat(mPrice),
                                    yearlyPrice: parseFloat(yPrice),
                                    maxUsers: parseInt(maxU),
                                    maxBays: parseInt(maxB),
                                    features: feat
                                };
                            }
                            showToast('Subscription tier saved!');
                            refreshUI();
                            closePlanModal();
                        } else {
                            alert('DB Error: ' + data.message);
                        }
                    } catch (err) {
                        console.error('Invalid server response:', text);
                        alert('Server communication error. Check console.');
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    alert('Network error. Database sync failed.');
                });
        });

        function editShop(id) {
            const s = shops.find(sh => (sh.id == id || sh.tenant_id == id));
            document.getElementById('shopModalTitle').innerText = 'Manage Hub Settings';
            document.getElementById('shopModalSub').innerText = 'Configure subscription status and hub expiry dates.';
            document.getElementById('shopId').value = s.tenant_id;
            document.getElementById('shopName').value = s.name;
            document.getElementById('shopOwner').value = s.owner;
            document.getElementById('shopEmail').value = s.email;

            let planOptions = '';
            plans.forEach(p => {
                const isMonthlySel = (p.id == s.planId && (!s.billing_cycle || s.billing_cycle === 'monthly')) ? 'selected' : '';
                const isYearlySel = (p.id == s.planId && s.billing_cycle === 'yearly') ? 'selected' : '';
                planOptions += `<option value="${p.id}_monthly" ${isMonthlySel}>${p.name} - ₱${p.monthlyPrice}/mo</option>`;
                planOptions += `<option value="${p.id}_yearly" ${isYearlySel}>${p.name} - ₱${p.yearlyPrice}/yr</option>`;
            });
            document.getElementById('shopPlan').innerHTML = planOptions;

            document.getElementById('shopStatus').value = s.status.toLowerCase();
            document.getElementById('shopExpiry').value = s.expiry;

            document.getElementById('passwordFields').style.display = 'none';
            document.getElementById('adminFields').style.display = 'block';
            document.getElementById('shopSubmitBtn').innerText = 'Save Changes';
            document.getElementById('shopSubmitBtn').style.width = '100%';

            document.getElementById('shopModal').style.display = 'flex';
        }

        function editPlan(id) {
            const p = plans.find(pl => pl.id === id);
            document.getElementById('planId').value = p.id;
            document.getElementById('planName').value = p.name;
            document.getElementById('monthlyPrice').value = p.monthlyPrice;
            document.getElementById('yearlyPrice').value = p.yearlyPrice;
            document.getElementById('maxUsers').value = p.maxUsers;
            document.getElementById('maxBays').value = p.maxBays;
            document.getElementById('planFeatures').value = p.features || "";
            openPlanModal(true);
        }

        function deletePlan(id, name) {
            showConfirm('Delete Pricing Tier', `Are you sure you want to PERMANENTLY DELETE the '${name}' subscription tier? This cannot be undone and will prevent new tenants from selecting this plan.`, () => {
                const formData = new FormData();
                formData.append('id', id);

                fetch('dashboard.php?action=delete_plan', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Plan deleted successfully.');
                            location.reload();
                        } else {
                            showAlert('Delete Failed', data.message, 'error');
                        }
                    })
                    .catch(err => showAlert('Connection Error', 'Network error. Database sync failed.', 'error'));
            });
        }
        document.querySelectorAll('.nav-item[data-view]').forEach(item => { item.addEventListener('click', function () { document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active')); document.querySelectorAll('.view-section').forEach(s => s.classList.remove('active')); this.classList.add('active'); document.getElementById(this.getAttribute('data-view')).classList.add('active'); refreshUI(); }); });
        document.getElementById('logoutBtn').addEventListener('click', () => {
            sessionStorage.removeItem('isLoggedIn');
            window.location.replace('login.php?action=logout&from=superadmin');
        });
        // Real-time Pending Hub Notification Poller
        function checkPendingNotifications() {
            fetch('dashboard.php?action=get_pending_count')
                .then(r => r.json())
                .then(data => {
                    const count = data.count || 0;
                    const badge = document.getElementById('sidebar-pending-badge');
                    const dot = document.getElementById('sidebar-notif-dot');

                    if (badge) {
                        const currentBadgeVal = parseInt(badge.textContent) || 0;
                        badge.textContent = count;
                        badge.style.display = count > 0 ? 'block' : 'none';

                        // Show Toast ONLY if new applications arrived since last check
                        if (count > currentBadgeVal) {
                            showToast('📢 New Tenant Application Received!');
                        }
                    }
                    if (dot) dot.style.display = count > 0 ? 'block' : 'none';
                });
        }

        // Poll every 30 seconds
        setInterval(checkPendingNotifications, 30000);

        // --- SIDEBAR TOGGLE LOGIC ---
        window.toggleSidebar = function () {
            const body = document.body;
            body.classList.toggle('sidebar-collapsed');
            const isCollapsed = body.classList.contains('sidebar-collapsed');
            localStorage.setItem('superadmin_sidebar_collapsed', isCollapsed);
        };

        // Persistent Sidebar State Restoration
        (function () {
            const isCollapsed = localStorage.getItem('superadmin_sidebar_collapsed') === 'true';
            if (isCollapsed) {
                document.body.classList.add('sidebar-collapsed');
            }
        })();

        window.addEventListener('load', () => {
            loadDB();
            checkPendingNotifications(); // Initial check
            refreshChatGroups(); // Initial chat load
            setInterval(refreshChatGroups, 10000); // Poll chat groups every 10s

            // Avatar change preview listener
            const avatarInput = document.getElementById('settingAdminAvatarFile');
            if (avatarInput) {
                avatarInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(evt) {
                            const previewDiv = document.getElementById('settingsAdminAvatarPreview');
                            if (previewDiv) {
                                previewDiv.innerHTML = `<img src="${evt.target.result}" style="width: 100%; height: 100%; object-fit: cover;">`;
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        });

        let activeChatTenantId = null;
        let activeChatTenantLogo = null;
        let chatPollInterval = null;
        let allChatGroups = [];

        function refreshChatGroups() {
            fetch('dashboard.php?action=fetch_support_groups')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        allChatGroups = data.groups;
                        filterChatGroups(); // Render with current filter

                        const totalUnread = data.groups.reduce((sum, g) => sum + parseInt(g.unread_count), 0);

                        // Show notification if total unread count increased
                        const badge = document.getElementById('globalChatBadge');
                        if (badge) {
                            const prevCount = parseInt(badge.innerText) || 0;
                            if (totalUnread > prevCount) {
                                showToast('💬 New Support Message Received!');
                            }
                            badge.innerText = totalUnread;
                            badge.style.display = totalUnread > 0 ? 'block' : 'none';
                        }
                    }
                });
        }

        function filterChatGroups() {
            const query = (document.getElementById('chatSearchInput').value || '').toLowerCase();
            const filtered = allChatGroups.filter(g => g.shop_name.toLowerCase().includes(query));
            renderChatGroups(filtered);
        }

        function renderChatGroups(groups) {
            const list = document.getElementById('chatGroupsList');
            list.innerHTML = '';

            if (groups.length === 0) {
                list.innerHTML = '<div style="text-align:center; padding:3rem; color:var(--text-dim);">No support conversations yet.</div>';
                return;
            }

            groups.forEach(g => {
                const item = document.createElement('div');
                item.className = 'hover-bright';
                item.style.padding = '1.2rem';
                item.style.borderBottom = '1px solid var(--glass-border)';
                item.style.cursor = 'pointer';
                item.style.background = (activeChatTenantId == g.tenant_id) ? 'rgba(99, 102, 241, 0.1)' : 'transparent';

                const targetPfp = g.tenant_avatar || g.logo_url;
                const logoHtml = targetPfp
                    ? `<img src="${targetPfp}" style="width:36px; height:36px; border-radius:50%; object-fit:cover; flex-shrink:0; box-shadow:0 2px 8px rgba(0,0,0,0.2);">`
                    : `<div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, var(--accent), #6366f1); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-weight:900; font-size:0.8rem; color:white; box-shadow:0 2px 8px rgba(0,0,0,0.2);">${(g.shop_name || '?')[0].toUpperCase()}</div>`;

                item.innerHTML = `
                    <div style="display:flex; gap:12px; align-items:center;">
                        ${logoHtml}
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
                                <div style="font-weight:800; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${g.shop_name}</div>
                                ${g.unread_count > 0 ? `<span style="background:var(--error); color:white; font-size:0.6rem; padding:2px 6px; border-radius:10px; flex-shrink:0;">${g.unread_count}</span>` : ''}
                            </div>
                            <div style="font-size:0.8rem; color:var(--text-dim); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                ${g.last_msg || 'No messages yet'}
                            </div>
                        </div>
                    </div>
                `;

                item.onclick = () => openChatWithTenant(g.tenant_id, g.shop_name, targetPfp);
                list.appendChild(item);
            });
        }

        function openChatWithTenant(tid, name, logoUrl) {
            activeChatTenantId = tid;
            activeChatTenantLogo = logoUrl || null;
            document.getElementById('chatEmptyState').style.display = 'none';
            document.getElementById('adminChatWindow').style.display = 'flex';
            document.getElementById('chatTargetName').innerText = name;

            // Update header avatar
            const headerAv = document.getElementById('chatHeaderAvatar');
            if (headerAv) {
                if (logoUrl) {
                    headerAv.innerHTML = `<img src="${logoUrl}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
                } else {
                    headerAv.innerHTML = `<span style="font-weight:900; font-size:0.9rem; color:white;">${(name || '?')[0].toUpperCase()}</span>`;
                    headerAv.style.background = 'linear-gradient(135deg, var(--accent), #6366f1)';
                }
            }

            // Highlight in list
            refreshChatGroups();

            fetchTenantMessages();
            if (chatPollInterval) clearInterval(chatPollInterval);
            chatPollInterval = setInterval(fetchTenantMessages, 3000);
        }

        function fetchTenantMessages() {
            if (!activeChatTenantId) return;
            fetch(`dashboard.php?action=fetch_tenant_chat&tenant_id=${activeChatTenantId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderAdminMessages(data.messages);
                    }
                });
        }

        function formatChatTime(dateStr) {
            if (!dateStr) {
                const now = new Date();
                return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            const date = new Date(dateStr.replace(/-/g, '/'));
            if (isNaN(date.getTime())) {
                const now = new Date();
                return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function renderAdminMessages(msgs) {
            const box = document.getElementById('adminChatMessages');
            const wasAtBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 50;

            box.innerHTML = '';
            msgs.forEach((m, idx) => {
                const isAdmin = m.sender_role === 'ADMIN';

                // Time Gap Divider (30 minutes or more since last message)
                if (idx > 0) {
                    const currentVal = new Date(m.created_at.replace(/-/g, '/'));
                    const prevVal = new Date(msgs[idx - 1].created_at.replace(/-/g, '/'));
                    if (!isNaN(currentVal.getTime()) && !isNaN(prevVal.getTime())) {
                        const diffMins = (currentVal - prevVal) / 60000;
                        if (diffMins >= 30) {
                            const divider = document.createElement('div');
                            divider.style.display = 'flex';
                            divider.style.alignItems = 'center';
                            divider.style.width = '100%';
                            divider.style.margin = '10px 0';
                            divider.style.color = 'rgba(255,255,255,0.25)';
                            divider.style.fontSize = '0.75rem';
                            divider.innerHTML = `
                                <div style="flex:1; height:1px; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);"></div>
                                <span style="padding:0 12px; font-weight:600; letter-spacing:0.5px; color:rgba(255,255,255,0.45);">${formatChatTime(m.created_at)}</span>
                                <div style="flex:1; height:1px; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);"></div>
                            `;
                            box.appendChild(divider);
                        }
                    }
                }

                // Message row wrapper
                const row = document.createElement('div');
                row.style.display = 'flex';
                row.style.flexDirection = isAdmin ? 'row-reverse' : 'row';
                row.style.alignItems = 'flex-start';
                row.style.gap = '10px';
                row.style.width = '100%';

                // Avatar
                const avatar = document.createElement('div');
                avatar.style.width = '32px';
                avatar.style.height = '32px';
                avatar.style.borderRadius = '50%';
                avatar.style.display = 'flex';
                avatar.style.alignItems = 'center';
                avatar.style.justifyContent = 'center';
                avatar.style.flexShrink = '0';
                avatar.style.boxShadow = '0 3px 10px rgba(0,0,0,0.15)';
                avatar.style.overflow = 'hidden';

                if (isAdmin) {
                    const adminPfp = m.sender_avatar || window.adminAvatarUrl;
                    if (adminPfp) {
                        avatar.style.background = 'transparent';
                        avatar.innerHTML = `<img src="${adminPfp}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
                    } else {
                        avatar.style.background = 'linear-gradient(135deg, #6366f1, #4f46e5)';
                        avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>`;
                    }
                } else {
                    const tenantPfp = m.sender_avatar || m.logo_url;
                    if (tenantPfp) {
                        avatar.style.background = 'transparent';
                        avatar.innerHTML = `<img src="${tenantPfp}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
                    } else {
                        avatar.style.background = 'linear-gradient(135deg, #3b82f6, #1d4ed8)';
                        avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v-2"></path><circle cx="12" cy="7" r="4"></circle></svg>`;
                    }
                }

                // Content container
                const contentContainer = document.createElement('div');
                contentContainer.style.display = 'flex';
                contentContainer.style.flexDirection = 'column';
                contentContainer.style.alignItems = isAdmin ? 'flex-end' : 'flex-start';
                contentContainer.style.maxWidth = '70%';

                const bubble = document.createElement('div');
                bubble.style.padding = '12px 18px';
                bubble.style.borderRadius = isAdmin ? '20px 20px 0 20px' : '20px 20px 20px 0';
                bubble.style.background = isAdmin ? 'var(--accent)' : 'var(--input-bg)';
                bubble.style.border = isAdmin ? 'none' : '1px solid var(--glass-border)';
                bubble.style.color = isAdmin ? 'white' : 'var(--text-main)';
                bubble.style.fontSize = '0.9rem';
                bubble.innerText = m.message;

                const timeVal = formatChatTime(m.created_at);
                const timeSpan = document.createElement('span');
                timeSpan.style.fontSize = '0.7rem';
                timeSpan.style.color = 'var(--text-dim)';
                timeSpan.style.marginTop = '4px';
                timeSpan.style.padding = '0 6px';
                timeSpan.innerText = timeVal;

                contentContainer.appendChild(bubble);
                contentContainer.appendChild(timeSpan);
                row.appendChild(avatar);
                row.appendChild(contentContainer);
                box.appendChild(row);
            });

            if (wasAtBottom) box.scrollTop = box.scrollHeight;
        }

        function togglePasswordVisibility(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function sendAdminReply() {
            const input = document.getElementById('adminChatInput');
            if (!input) return;
            const msg = input.value.trim();
            if (!msg || !activeChatTenantId) return;

            const fd = new FormData();
            fd.append('tenant_id', activeChatTenantId);
            fd.append('message', msg);

            input.value = '';
            fetch('dashboard.php?action=send_support_reply', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') fetchTenantMessages();
                    else alert(data.message);
                });
        }

        const chatInput = document.getElementById('adminChatInput');
        if (chatInput) {
            chatInput.onkeypress = (e) => { if (e.key === 'Enter') sendAdminReply(); };
        }

        // Backup Management
        function refreshBackups() {
            fetch('dashboard.php?action=fetch_backups')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderBackups(data.backups);
                        const lastTime = data.lastBackup && data.lastBackup !== 'None' ? formatTimestamp(data.lastBackup) : 'None';
                        document.getElementById('lastBackupTime').innerText = lastTime;

                        const totalBytes = parseFloat(data.totalSize || 0);
                        let sizeStr = totalBytes > 1048576
                            ? (totalBytes / 1048576).toFixed(2) + ' MB'
                            : (totalBytes / 1024).toFixed(2) + ' KB';
                        document.getElementById('totalBackupSize').innerText = sizeStr;
                    }
                });
        }

        function renderBackups(list) {
            const container = document.getElementById('backupListContainer');
            if (!container) return;
            container.innerHTML = '';

            if (!list || list.length === 0) {
                container.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim);">No server snapshots available.</td></tr>';
                return;
            }

            list.forEach(b => {
                const tr = document.createElement('tr');
                const bytes = parseFloat(b.file_size || 0);
                const sizeDisplay = bytes > 1048576
                    ? (bytes / 1048576).toFixed(2) + ' MB'
                    : (bytes / 1024).toFixed(2) + ' KB';

                tr.innerHTML = `
                    <td style="padding:1.5rem; color:var(--accent); font-weight:800;">#${b.backup_id}</td>
                    <td style="padding:1.5rem; color:var(--text-main); font-weight:500;">${formatTimestamp(b.created_at)}</td>
                    <td style="padding:1.5rem;">
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <span style="background:rgba(255,255,255,0.05); padding:4px 10px; border-radius:6px; font-size:0.7rem; color:var(--text-main); display:inline-block; width:fit-content;">DATABASE DUMP</span>
                            <span style="font-size:0.7rem; color:var(--text-dim); opacity:0.7;">Size: ${sizeDisplay}</span>
                        </div>
                    </td>
                    <td style="padding:1.5rem;"><span class="badge badge-active" style="font-size:0.65rem;">● ${b.status}</span></td>
                    <td style="padding:1.5rem;">
                        <div style="display:flex; gap:10px; justify-content:flex-end;">
                            <a href="backups/${b.filename}" download class="btn-action" style="padding:0.6rem 1rem; border-radius:10px; background:var(--accent); color:white; text-decoration:none; font-size:0.75rem; font-weight:700;">
                                <i class="fas fa-download" style="margin-right:5px;"></i> Download
                            </a>
                            <button class="btn-action" style="padding:0.6rem 1rem; border-radius:10px; background:rgba(255,255,255,0.05); color:var(--text-main); border:1px solid var(--glass-border); font-size:0.75rem;" onclick="showRestoreHint('${b.filename}')">
                                <i class="fas fa-undo" style="margin-right:5px;"></i> Restore
                            </button>
                            <button onclick="deleteBackup(${b.backup_id})" class="btn-action" style="padding:0.6rem; border-radius:10px; background:rgba(239,68,68,0.1); color:var(--error); border:1px solid rgba(239,68,68,0.2);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                container.appendChild(tr);
            });
        }

        function showRestoreHint(file) {
            showAlert('Restore Instructions', `1. Download the file: ${file}\n2. Access your Database Manager (phpMyAdmin).\n3. Select your AutoFix database.\n4. Use the 'Import' tab to upload this file.\n\nNote: Automated restoration is disabled to prevent accidental data loss.`);
        }

        function createManualBackup() {
            const btn = event.target.closest('button');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

            fetch('dashboard.php?action=create_backup')
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    if (data.status === 'success') {
                        showToast('✅ Database snapshot created!');
                        refreshBackups();
                    } else {
                        showAlert('Backup Failed', data.message, 'error');
                    }
                });
        }

        function deleteBackup(id) {
            showConfirm('Delete Snapshot', 'Are you sure you want to delete this backup file? This cannot be undone.', () => {
                const fd = new FormData();
                fd.append('id', id);
                fetch('dashboard.php?action=delete_backup', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showToast('Backup deleted.');
                            refreshBackups();
                        }
                    });
            });
        }

        // --- View Refresh Override ---
        const originalRefreshUI = window.refreshUI;
        window.refreshUI = function () {
            if (typeof originalRefreshUI === 'function') originalRefreshUI();
            const activeViewEl = document.querySelector('.view-section.active');
            if (activeViewEl && activeViewEl.id === 'backup') {
                refreshBackups();
            }
        };

        // Initial load for backup if visible
        window.addEventListener('DOMContentLoaded', () => {
            const activeViewEl = document.querySelector('.view-section.active');
            if (activeViewEl && activeViewEl.id === 'backup') refreshBackups();
        });


    </script>
</body>

</html>