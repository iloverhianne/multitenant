<?php
@session_start();
ob_start();
date_default_timezone_set('Asia/Manila');
require_once 'db-config.php';

// Force No-Cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

// Security Check
if (isset($_GET['logout'])) {
  $shop_id = $_SESSION['tenant_id'] ?? 0;
  if (isset($_SESSION['user_id']) && strtoupper($_SESSION['role'] ?? '') !== 'OWNER') {
    try {
      $db = getDB();
      $stmt = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'AUTH', ?)");
      $stmt->execute([$_SESSION['tenant_id'] ?? null, $_SESSION['user_id'], "User {$_SESSION['name']} ({$_SESSION['role']}) logged out from {$_SESSION['shop_name']}"]);
    } catch (Exception $e) {
    }
  }
  session_destroy();
  header("Location: shop.php?id=" . $shop_id);
  exit;
}

$allowed_roles = ['OWNER', 'MANAGER', 'CASHIER', 'MECHANIC'];
if (!isset($_SESSION['isLoggedIn']) || !in_array(strtoupper($_SESSION['role'] ?? ''), $allowed_roles)) {
  header('Location: login.php');
  exit;
}

$role = $_SESSION['role'];
$tenant_id = $_SESSION['tenant_id'];
$owner_name = $_SESSION['name'] ?? 'User';

// ACCOUNT SECURITY SYNC: Force logout if deactivated
try {
  $db_check = getDB();
  $stmt_check = $db_check->prepare("SELECT status, profile_pic FROM users WHERE user_id = ?");
  $stmt_check->execute([$_SESSION['user_id']]);
  $userData = $stmt_check->fetch(PDO::FETCH_ASSOC);
  if ($userData['status'] === 'INACTIVE') {
    session_destroy();
    header("Location: login.php?error=deactivated");
    exit;
  }
  $current_user_pic = $userData['profile_pic'] ?? '';

} catch (Exception $e) {
}

$shop_name = 'My Auto Shop';
$tenant_custom = [
  'primary_color' => '#10b981',
  'secondary_color' => '#030712',
  'logo_url' => '',
  'phone' => '',
  'description' => '',
  'address' => '',
  'ui_style' => 'GLASS',
  'border_radius' => '24px'
];

try {
  $db = getDB();

  // --- DATABASE ENGINE AUTO-HEAL ---
  try {
    // Customers
    $db->exec("CREATE TABLE IF NOT EXISTS customers (
      customer_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      full_name VARCHAR(100) NOT NULL,
      email VARCHAR(100),
      mobile VARCHAR(20),
      address TEXT,
      status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
      total_visits INT DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Vehicles
    $db->exec("CREATE TABLE IF NOT EXISTS vehicles (
      vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      customer_id INT NULL,
      plate_no VARCHAR(20) NOT NULL,
      model VARCHAR(100),
      make VARCHAR(50),
      year VARCHAR(10),
      color VARCHAR(30),
      vin VARCHAR(50),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Payments
    $db->exec("CREATE TABLE IF NOT EXISTS payments (
      payment_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      appointment_id INT NULL,
      job_id INT NULL,
      customer_id INT NULL,
      customer_name VARCHAR(100),
      amount DECIMAL(15,2) NOT NULL,
      payment_method VARCHAR(50) DEFAULT 'CASH',
      reference_no VARCHAR(100),
      status VARCHAR(20) DEFAULT 'COMPLETED',
      payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // --- SUBSCRIPTION ENGINE HEALER (V2) ---
    $db->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
      plan_id INT AUTO_INCREMENT PRIMARY KEY,
      plan_name VARCHAR(50) NOT NULL,
      price DECIMAL(10,2) NOT NULL,
      price_yearly DECIMAL(10,2) DEFAULT 0.00,
      max_users INT DEFAULT 5,
      max_service_bays INT DEFAULT 2,
      status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=MyISAM;");

    // Ensure plans exist (Only insert if missing to allow Super Admin changes to persist)
    $planCount = $db->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
    if ($planCount == 0) {
      $db->exec("INSERT INTO subscription_plans (plan_id, plan_name, price, price_yearly, max_users, max_service_bays, status) VALUES 
        (1, 'BASIC', 1999.00, 17990.00, 5, 2, 'ACTIVE'),
        (2, 'PRO', 6499.00, 58490.00, 20, 5, 'ACTIVE'),
        (3, 'ENTERPRISE', 24999.00, 224990.00, 100, 20, 'ACTIVE')");
    } else {
      // DB is already seeded. Super Admin now has full control over pricing in the subscription_plans table.
    }

    $db->exec("CREATE TABLE IF NOT EXISTS tenant_subscriptions (
      subscription_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      plan_id INT NOT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      status ENUM('ACTIVE', 'EXPIRED', 'UPGRADED', 'CANCELLED') DEFAULT 'ACTIVE',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (tenant_id), INDEX (plan_id)
    ) ENGINE=MyISAM;");

    try {
      $db->exec("ALTER TABLE tenant_subscriptions ADD COLUMN billing_cycle VARCHAR(20) DEFAULT 'monthly' AFTER plan_id");
    } catch (Exception $e) {
    }

    // Backfill a default subscription if none exists OR if it's invalid
    $checkSub = $db->prepare("SELECT COUNT(*) FROM tenant_subscriptions WHERE tenant_id = ? AND status = 'ACTIVE'");
    $checkSub->execute([$tenant_id]);
    if ($checkSub->fetchColumn() == 0) {
      $db->prepare("INSERT INTO tenant_subscriptions (tenant_id, plan_id, billing_cycle, start_date, end_date, status) VALUES (?, 2, 'monthly', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'ACTIVE')")
        ->execute([$tenant_id]);
    }
  } catch (Exception $e) { /* Silently heal */
  }

  // Fetch Tenant Data for Customization
  $stmt = $db->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
  $stmt->execute([$tenant_id]);
  $global_tenant_res = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($global_tenant_res) {
    $tenant_custom = array_merge($tenant_custom, array_filter($global_tenant_res, function ($val) {
      return $val !== null;
    }));
    // NORMALIZATION: Ensure colors have # for CSS and Color Inputs
    if (!empty($tenant_custom['primary_color']) && strpos($tenant_custom['primary_color'], '#') !== 0) {
      $tenant_custom['primary_color'] = '#' . $tenant_custom['primary_color'];
    }
    if (!empty($tenant_custom['secondary_color']) && strpos($tenant_custom['secondary_color'], '#') !== 0) {
      $tenant_custom['secondary_color'] = '#' . $tenant_custom['secondary_color'];
    }
    $shop_name = $global_tenant_res['shop_name'];
    $tenant_slug = $global_tenant_res['slug'] ?? $tenant_id;
  } else {
    $tenant_slug = $tenant_id;
  }

  // Fetch Current Subscription
  $active_subscription = null;
  try {
    $stmt = $db->prepare("
      SELECT s.*, p.plan_name, p.price, p.price_yearly, p.max_users, p.max_service_bays 
      FROM tenant_subscriptions s
      JOIN subscription_plans p ON s.plan_id = p.plan_id
      WHERE s.tenant_id = ? AND s.status = 'ACTIVE'
      ORDER BY s.subscription_id DESC LIMIT 1
    ");
    $stmt->execute([$tenant_id]);
    $active_subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    // Safety Fallback: If fetch failed but we just backfilled, retry once
    if (!$active_subscription) {
      $stmt->execute([$tenant_id]);
      $active_subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    }
  } catch (Exception $e) {
  }

  // Handle AJAX: Renew Subscription
  if (isset($_GET['action']) && $_GET['action'] === 'renew_subscription') {
    header('Content-Type: application/json');
    if (strtoupper($role) !== 'OWNER') {
      echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
      exit;
    }
    try {
      $db->beginTransaction();

      // Get current subscription to extend
      if (!$active_subscription) {
        throw new Exception("No active subscription found to renew.");
      }

      $sub_id = $active_subscription['subscription_id'];
      $current_end = $active_subscription['end_date'];
      $cycle = strtolower($active_subscription['billing_cycle'] ?? 'monthly');

      $duration = ($cycle === 'yearly') ? ' +1 year' : ' +30 days';
      $new_end = date('Y-m-d', strtotime($current_end . $duration));

      $amount = ($cycle === 'yearly') ? ($active_subscription['price_yearly'] ?? ($active_subscription['price'] * 12 * 0.8)) : $active_subscription['price'];
      $method = $_POST['method'] ?? 'OWNER_RENEWAL';
      $ref = 'RNW-' . strtoupper(substr(md5(time() . $tenant_id), 0, 9));

      // 1. Update Subscription End Date
      $stmt = $db->prepare("UPDATE tenant_subscriptions SET end_date = ? WHERE subscription_id = ?");
      $stmt->execute([$new_end, $sub_id]);

      // 2. Insert into Payments (reflected for Admin)
      $stmt = $db->prepare("INSERT INTO tenant_payments (tenant_id, subscription_id, amount, payment_method, transaction_reference, payment_status) VALUES (?, ?, ?, ?, ?, 'SUCCESS')");
      $stmt->execute([$tenant_id, $sub_id, $amount, $method, $ref]);

      // 3. Log the activity
      $stmt = $db->prepare("INSERT INTO audit_logs (tenant_id, activity_type, description) VALUES (?, 'SUBSCRIPTION', ?)");
      $stmt->execute([$tenant_id, "Subscription renewed until $new_end. Ref: $ref"]);

      $db->commit();
      echo json_encode(['status' => 'success', 'new_expiry' => date('F d, Y', strtotime($new_end)), 'ref' => $ref]);
    } catch (Exception $e) {
      $db->rollBack();
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  // Handle AJAX: Upgrade / Switch Plan
  if (isset($_GET['action']) && $_GET['action'] === 'upgrade_plan') {
    header('Content-Type: application/json');
    if (strtoupper($role) !== 'OWNER') {
      echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
      exit;
    }
    try {
      $new_plan_id = intval($_POST['plan_id'] ?? 0);
      if (!$new_plan_id)
        throw new Exception("Invalid plan selected.");

      // Get new plan details
      $planStmt = $db->prepare("SELECT * FROM subscription_plans WHERE plan_id = ?");
      $planStmt->execute([$new_plan_id]);
      $new_plan = $planStmt->fetch(PDO::FETCH_ASSOC);
      if (!$new_plan)
        throw new Exception("Plan not found.");

      $db->beginTransaction();

      $new_start = date('Y-m-d');
      $cycle = strtolower($_POST['billing_cycle'] ?? 'monthly');
      $interval = ($cycle === 'yearly') ? '+1 year' : '+30 days';
      $new_end = date('Y-m-d', strtotime($interval));
      $amount = ($cycle === 'yearly') ? ($new_plan['price_yearly'] > 0 ? $new_plan['price_yearly'] : ($new_plan['price'] * 12 * 0.8)) : $new_plan['price'];
      $method = $_POST['method'] ?? 'PLAN_UPGRADE';
      $ref = 'UPG-' . strtoupper(substr(md5(time() . $tenant_id), 0, 9));

      if ($active_subscription) {
        // Deactivate current subscription
        $db->prepare("UPDATE tenant_subscriptions SET status = 'UPGRADED' WHERE subscription_id = ?")
          ->execute([$active_subscription['subscription_id']]);
      }

      // Insert new subscription
      $db->prepare("INSERT INTO tenant_subscriptions (tenant_id, plan_id, billing_cycle, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')")
        ->execute([$tenant_id, $new_plan_id, $cycle, $new_start, $new_end]);

      // Record payment
      $db->prepare("INSERT INTO tenant_payments (tenant_id, subscription_id, amount, payment_method, transaction_reference, payment_status) VALUES (?, LAST_INSERT_ID(), ?, ?, ?, 'SUCCESS')")
        ->execute([$tenant_id, $amount, $method, $ref]);

      // Audit log
      $db->prepare("INSERT INTO audit_logs (tenant_id, activity_type, description) VALUES (?, 'SUBSCRIPTION', ?)")
        ->execute([$tenant_id, "Upgraded to {$new_plan['plan_name']} plan. Ref: $ref"]);

      $db->commit();
      echo json_encode(['status' => 'success', 'message' => "Successfully upgraded to {$new_plan['plan_name']}!", 'ref' => $ref]);
    } catch (Exception $e) {
      if ($db->inTransaction())
        $db->rollBack();
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  // Auto-migrate: Announcements Table
  try {
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
      announcement_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NULL,
      user_id INT NOT NULL,
      message TEXT NOT NULL,
      type ENUM('GLOBAL', 'SHOP') DEFAULT 'SHOP',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (tenant_id), INDEX (type)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

    // Seed with legacy data if needed (optional)
  } catch (Exception $e) {
  }

  // ROLE-BASED ANNOUNCEMENT LIST LOGIC
  $ann_title = "Recent Broadcasts";
  $ann_from = "AutoFix Hub Stream";

  $all_announcements = [];
  try {
    // Uniform query for both Owner and Staff to see all relevant messages
    $stmt = $db->prepare("SELECT a.*, u.name as author_name, r.role_name 
               FROM announcements a 
               LEFT JOIN users u ON a.user_id = u.user_id 
               LEFT JOIN roles r ON u.role_id = r.role_id 
               WHERE (a.tenant_id = ? AND a.type = 'SHOP') OR a.type = 'GLOBAL' 
               ORDER BY a.announcement_id DESC LIMIT 15");
    $stmt->execute([$tenant_id]);
    $all_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
  }

  $maxAnnId = 0;
  if (!empty($all_announcements))
    $maxAnnId = $all_announcements[0]['announcement_id'];

  // Handle AJAX: Fetch Latest Announcement ID for real-time badge
  if (isset($_GET['action']) && $_GET['action'] === 'fetch_latest_ann_id') {
    header('Content-Type: application/json');
    try {
      $stmt = $db->prepare("SELECT MAX(announcement_id) FROM announcements WHERE (tenant_id = ? AND type = 'SHOP') OR type = 'GLOBAL'");
      $stmt->execute([$tenant_id]);
      echo json_encode(['status' => 'success', 'latest_id' => (int) $stmt->fetchColumn()]);
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'latest_id' => 0]);
    }
    exit;
  }

  // Handle AJAX: Fetch Billing History
  if (isset($_GET['action']) && $_GET['action'] === 'fetch_billing_history') {
    header('Content-Type: application/json');
    try {
      $stmt = $db->prepare("SELECT * FROM tenant_payments WHERE tenant_id = ? ORDER BY payment_date DESC");
      $stmt->execute([$tenant_id]);
      echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
      echo json_encode([]);
    }
    exit;
  }

  $pending_jobs_count = 0;
  $appointments_today = 0;
  $today_revenue = 0;
  $active_jobs = [];
  $services_list = [];
  $bays_list = [];
  $mechanics_list = [];
  $inventory_list = [];
  $staff_list = [];
  $all_plans = [];
  try {
    $stmt = $db->query("SELECT * FROM subscription_plans WHERE status = 'ACTIVE' ORDER BY price ASC");
    $all_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
  }

  // --- 0. SEEDING (FOR DEMO/INITIAL SETUP) ---
  // Wrapped in check to prevent locking MyISAM tables during AJAX fetch calls
  if (isset($_GET['action']) && $_GET['action'] === 'send_support_message') {
    header('Content-Type: application/json');
    try {
      $msg = trim($_POST['message'] ?? '');
      if (empty($msg))
        throw new Exception("Message cannot be empty.");
      $db->prepare("INSERT INTO support_messages (tenant_id, sender_role, sender_id, message) VALUES (?, 'TENANT', ?, ?)")
        ->execute([$tenant_id, $_SESSION['user_id'], $msg]);
      echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  if (isset($_GET['action']) && $_GET['action'] === 'fetch_support_messages') {
    header('Content-Type: application/json');
    try {
      $msgs = $db->prepare("SELECT * FROM support_messages WHERE tenant_id = ? ORDER BY created_at ASC");
      $msgs->execute([$tenant_id]);
      echo json_encode(['status' => 'success', 'messages' => $msgs->fetchAll()]);
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  if (isset($_GET['action']) && $_GET['action'] === 'mark_support_read') {
    header('Content-Type: application/json');
    try {
      $db->prepare("UPDATE support_messages SET is_read = 1 WHERE tenant_id = ? AND sender_role = 'ADMIN' AND is_read = 0")
        ->execute([$tenant_id]);
      echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  if (isset($_GET['action']) && $_GET['action'] === 'update_my_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
      $user_id = $_SESSION['user_id'];
      if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $pUploadDir = 'uploads/profiles/';
        if (!is_dir($pUploadDir))
          mkdir($pUploadDir, 0777, true);
        $pExt = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $pFileName = 'profile_' . time() . '_' . uniqid() . '.' . $pExt;
        $pPath = $pUploadDir . $pFileName;
        move_uploaded_file($_FILES['profile_pic']['tmp_name'], $pPath);
        $db->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?")->execute([$pPath, $user_id]);
        echo json_encode(['status' => 'success', 'message' => 'Profile picture updated!']);
      } else {
        throw new Exception("No file uploaded or upload error.");
      }
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  if (!isset($_GET['action'])) {
    try {
      // Individual sections should be wrapped in try-catch to avoid blocking others

      // 0.A Vital Healers First
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS customers (customer_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, full_name VARCHAR(100), total_visits INT DEFAULT 0)");
        try {
          $db->exec("ALTER TABLE customers ADD COLUMN total_visits INT DEFAULT 0");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE customers ADD COLUMN points INT DEFAULT 0");
        } catch (Exception $e) {
        }
      } catch (Exception $e) {
      }

      try {
        $db->exec("CREATE TABLE IF NOT EXISTS appointments (
        appointment_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        customer_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        service_id INT NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('PENDING', 'CONFIRMED', 'CANCELLED', 'COMPLETED') DEFAULT 'PENDING',
        total_estimate DECIMAL(10,2) DEFAULT 0.00,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id), INDEX (customer_id), INDEX (vehicle_id), INDEX (service_id)
      ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
      } catch (Exception $e) {
      }

      try {
        $db->exec("CREATE TABLE IF NOT EXISTS vehicles (
        vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        tenant_id INT NOT NULL,
        plate_no VARCHAR(20) NOT NULL,
        make VARCHAR(50) NOT NULL,
        model VARCHAR(50) NOT NULL,
        year INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id), INDEX (tenant_id)
      ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
        // Patches for vehicles
        try {
          $db->exec("ALTER TABLE vehicles ADD COLUMN plate_no VARCHAR(50) AFTER tenant_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE vehicles ADD COLUMN make VARCHAR(50) AFTER plate_no");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE vehicles ADD COLUMN model VARCHAR(50) AFTER make");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE vehicles ADD COLUMN year_model INT AFTER model");
        } catch (Exception $e) {
        }
      } catch (Exception $e) {
      }

      try {
        $db->exec("CREATE TABLE IF NOT EXISTS services (
          service_id INT AUTO_INCREMENT PRIMARY KEY,
          tenant_id INT NOT NULL,
          master_id INT DEFAULT NULL,
          service_name VARCHAR(100) NOT NULL,
          description TEXT NULL,
          price DECIMAL(10,2) NOT NULL,
          category VARCHAR(50) NULL,
          estimated_time VARCHAR(50) NULL,
          status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (tenant_id)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
        try { $db->exec("ALTER TABLE services ADD COLUMN master_id INT DEFAULT NULL AFTER tenant_id"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE services ADD COLUMN category VARCHAR(50) NULL AFTER price"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE services ADD COLUMN estimated_time VARCHAR(50) NULL AFTER category"); } catch(Exception $e) {}
      } catch (Exception $e) {
      }

      // If bays are empty, add at least 2
      try {
        $checkBays = $db->prepare("SELECT COUNT(*) FROM service_bays WHERE tenant_id = ?");
        $checkBays->execute([$tenant_id]);
        if ($checkBays->fetchColumn() == 0) {
          $db->prepare("INSERT INTO service_bays (tenant_id, bay_name, status) VALUES (?, 'Bay 1', 'AVAILABLE'), (?, 'Bay 2', 'AVAILABLE')")->execute([$tenant_id, $tenant_id]);
        }
      } catch (Exception $e) {
      }

      // Ensure roles table exists and has default values
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS roles (role_id INT PRIMARY KEY, role_name VARCHAR(50))");
        $roles_check = $db->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        if ($roles_check == 0) {
          $db->exec("INSERT INTO roles (role_id, role_name) VALUES 
          (1, 'SUPER_ADMIN'), 
          (2, 'OWNER'), 
          (3, 'MANAGER'), 
          (4, 'CASHIER'), 
          (5, 'MECHANIC')");
        }
      } catch (Exception $e) {
      }

      // Ensure users table has needed columns and proper ID
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS users (user_id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) UNIQUE, password_hash VARCHAR(255))");
        try {
          $db->exec("ALTER TABLE users ADD COLUMN user_id INT AUTO_INCREMENT PRIMARY KEY");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN tenant_id INT NULL");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT 3");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'ACTIVE'");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN name VARCHAR(100) NULL");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
        } catch (\Throwable $e) {
        }
        // If table has 'password' instead of 'password_hash', rename or copy
        try {
          $db->exec("ALTER TABLE users CHANGE password password_hash VARCHAR(255)");
        } catch (\Throwable $e) {
        }
        // Staff Verification Enhancement
        try {
          $db->exec("ALTER TABLE users ADD COLUMN mobile VARCHAR(20) AFTER email");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN address TEXT AFTER mobile");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN id_type VARCHAR(50) AFTER address");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN id_file VARCHAR(255) AFTER id_type");
        } catch (\Throwable $e) {
        }
      } catch (\Throwable $e) {
      }

      // Robust Mechanics Table Heal
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS mechanics (mechanic_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL)");
        // Patch mechanics columns (InfinityFree compatible)
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN tenant_id INT NULL");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN user_id INT NULL AFTER mechanic_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN specialization VARCHAR(100) DEFAULT 'General'");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN status VARCHAR(20) DEFAULT 'AVAILABLE'");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN full_name VARCHAR(100) NULL AFTER tenant_id");
        } catch (Exception $e) {
        }

        // Robust SYNC: Use PHP iteration to ensure all staff with role 'MECHANIC' are in the masterfile
        try {
          // Find all users who should be mechanics
          $mechanicUsers = $db->prepare("SELECT user_id, tenant_id, name FROM users WHERE (role_id = 5 OR role_id IN (SELECT role_id FROM roles WHERE role_name = 'MECHANIC')) AND tenant_id IS NOT NULL");
          $mechanicUsers->execute();
          $all_m = $mechanicUsers->fetchAll(PDO::FETCH_ASSOC);

          foreach ($all_m as $mu) {
            if (empty($mu['user_id'])) {
              continue;
            }
            // Check if already in mechanics table using user_id 
            $mExists = $db->prepare("SELECT COUNT(*) FROM mechanics WHERE user_id = ? AND tenant_id = ?");
            $mExists->execute([$mu['user_id'], $mu['tenant_id']]);
            if ($mExists->fetchColumn() == 0) {
              try {
                $mIns = $db->prepare("INSERT INTO mechanics (tenant_id, user_id, full_name, specialization, status) VALUES (?, ?, ?, 'General Mechanic', 'AVAILABLE')");
                $mIns->execute([$mu['tenant_id'], $mu['user_id'], $mu['name']]);
              } catch (Exception $ins_e) {
                error_log("Mechanic Sync Error: " . $ins_e->getMessage());
              }
            }
          }
          // Backfill for existing user-linked mechanics without full_name
          $db->exec("UPDATE mechanics m JOIN users u ON m.user_id = u.user_id SET m.full_name = u.name WHERE (m.full_name IS NULL OR m.full_name = '') AND u.name IS NOT NULL");
        } catch (Exception $se) {
          error_log("Mechanic Sync Main Error: " . $se->getMessage());
        }
      } catch (Exception $e) {
      }

      // If mechanics are empty, add 1
      $checkMech = $db->prepare("SELECT COUNT(*) FROM mechanics WHERE tenant_id = ?");
      $checkMech->execute([$tenant_id]);
      if ($checkMech->fetchColumn() == 0) {
        $db->prepare("INSERT INTO mechanics (tenant_id, full_name, specialization, status) VALUES (?, 'Default Mechanic', 'General Service', 'AVAILABLE')")->execute([$tenant_id]);
      }
      // Customization patch systematically
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN logo_url TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN primary_color VARCHAR(20) DEFAULT '#10b981'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN secondary_color VARCHAR(20) DEFAULT '#030712'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN phone VARCHAR(20) NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN description TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN address TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN ui_style VARCHAR(20) DEFAULT 'GLASS'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN border_radius VARCHAR(10) DEFAULT '24px'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN hero_title VARCHAR(255) NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN hero_subtitle TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN banner_url TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN about_text TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN facebook_url TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN instagram_url TEXT NULL");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN opening_hours VARCHAR(255) DEFAULT 'Mon - Sat: 8:00 AM - 5:00 PM'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN plan_tier VARCHAR(20) DEFAULT 'PRO'");
      } catch (Exception $e) {
      }
      try {
        $db->exec("ALTER TABLE tenants ADD COLUMN staff_announcement TEXT NULL");
      } catch (Exception $e) {
      }

      // Payments Table Healer/Creation
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS services (service_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, master_id INT DEFAULT NULL, service_name VARCHAR(100), description TEXT, price DECIMAL(10,2), category VARCHAR(50), estimated_time VARCHAR(50), status ENUM('ACTIVE', 'INACTIVE'), created_at DATETIME)");
        try { $db->exec("ALTER TABLE services ADD COLUMN master_id INT DEFAULT NULL AFTER tenant_id"); } catch (Exception $e) {}
        $db->exec("CREATE TABLE IF NOT EXISTS payments (payment_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, amount DECIMAL(10,2), status VARCHAR(20))");
        try {
          $db->exec("ALTER TABLE payments ADD COLUMN appointment_id INT NULL AFTER customer_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD COLUMN job_id INT NULL AFTER appointment_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD COLUMN payment_type VARCHAR(20) DEFAULT 'FULL_PAYMENT' AFTER amount");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD COLUMN payment_date DATETIME NULL");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD INDEX (tenant_id)");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE payments ADD INDEX (customer_id)");
        } catch (Exception $e) {
        }
      } catch (Exception $e) {
      }

      // Appointments Table Heal/Creation
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS appointments (appointment_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, customer_id INT, vehicle_id INT, service_id INT, appointment_date DATE, appointment_time TIME, status ENUM('PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED'), total_estimate DECIMAL(10,2), created_at DATETIME)");
        try {    // APPOINTMENTS TABLE PATCHER
          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'service_id'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN service_id INT NULL");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'mechanic_id'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN mechanic_id INT NULL");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'bay_id'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN bay_id INT NULL");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'estimated_amount'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN estimated_amount DECIMAL(10,2) DEFAULT 0");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'status'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN status VARCHAR(20) DEFAULT 'PENDING'");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'payment_status'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN payment_status VARCHAR(20) DEFAULT 'UNPAID'");
          }

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'total_estimate'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN total_estimate DECIMAL(10,2) DEFAULT 0");
          }

          // Ensure mechanics and service_bays exist
          $db->exec("CREATE TABLE IF NOT EXISTS service_bays (
      bay_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT,
      bay_name VARCHAR(50),
      status ENUM('AVAILABLE', 'OCCUPIED', 'MAINTENANCE') DEFAULT 'AVAILABLE'
    )");

          $db->exec("CREATE TABLE IF NOT EXISTS mechanics (
      mechanic_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT,
      full_name VARCHAR(100),
      specialization VARCHAR(100),
      status ENUM('AVAILABLE', 'BUSY', 'OFF') DEFAULT 'AVAILABLE'
    )");

          $db->exec("CREATE TABLE IF NOT EXISTS repair_jobs (
      job_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      customer_id INT NULL,
      vehicle_id INT NULL,
      service_id INT NULL,
      appointment_id INT NULL,
      mechanic_id INT NULL,
      bay_id INT NULL,
      status VARCHAR(20) DEFAULT 'PENDING',
      payment_status VARCHAR(20) DEFAULT 'UNPAID',
      total_amount DECIMAL(10,2) DEFAULT 0.00,
      notes TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX (tenant_id),
      INDEX (customer_id),
      INDEX (mechanic_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

          // Aggressive FK cleanup for repair_jobs
          try {
            for ($i = 1; $i <= 10; $i++) {
              try {
                $db->exec("ALTER TABLE repair_jobs DROP FOREIGN KEY repair_jobs_ibfk_$i");
              } catch (Exception $e) {
              }
            }
            // Explicitly ensure MyISAM
            try {
              $db->exec("ALTER TABLE repair_jobs ENGINE=MyISAM");
            } catch (Exception $e) {
            }

            $cols = $db->query("SHOW COLUMNS FROM repair_jobs")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('appointment_id', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN appointment_id INT NULL AFTER service_id");
            if (!in_array('tenant_id', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN tenant_id INT NOT NULL AFTER job_id");
            if (!in_array('total_amount', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0.00 AFTER status");
            if (!in_array('payment_status', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN payment_status VARCHAR(20) DEFAULT 'UNPAID' AFTER total_amount");
          } catch (Exception $pe) {
          }

          $db->exec("CREATE TABLE IF NOT EXISTS repair_timeline (
      timeline_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      job_id INT NOT NULL,
      status_update VARCHAR(50),
      remarks TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (job_id),
      INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

          try {
            $db->exec("ALTER TABLE repair_timeline ADD COLUMN tenant_id INT NOT NULL AFTER timeline_id");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE repair_timeline ADD INDEX (tenant_id)");
          } catch (Exception $e) {
          }

          try {
            for ($i = 1; $i <= 10; $i++) {
              try {
                $db->exec("ALTER TABLE repair_timeline DROP FOREIGN KEY repair_timeline_ibfk_$i");
              } catch (Exception $e) {
              }
            }
            try {
              $db->exec("ALTER TABLE repair_timeline ENGINE=MyISAM");
            } catch (Exception $e) {
            }
          } catch (Exception $e) {
          }
        } catch (Exception $e) {
        }
      } catch (Exception $e) {
      }

      // Audit Logs Table Heal/Creation
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        user_id INT NULL,
        customer_id INT NULL,
        activity_type VARCHAR(50),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (user_id),
        INDEX (customer_id)
      ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
      } catch (Exception $e) {
      }
      // Robust Patch: Avoid ENUM truncation by using VARCHAR(50) for all status columns
      try {
        $db->exec("ALTER TABLE repair_jobs MODIFY COLUMN status VARCHAR(50) DEFAULT 'PENDING'");
        $db->exec("ALTER TABLE mechanics MODIFY COLUMN status VARCHAR(50) DEFAULT 'AVAILABLE'");
        $db->exec("ALTER TABLE service_bays MODIFY COLUMN status VARCHAR(50) DEFAULT 'AVAILABLE'");
        $db->exec("ALTER TABLE appointments MODIFY COLUMN status VARCHAR(50) DEFAULT 'PENDING'");
      } catch (Exception $e) {
      }

    } catch (Exception $seed_e) { /* silent: catch-all for seeding */
    }

    // --- 1. INITIAL FETCH FOR ALL MODULES ---
    try {
      // Services
      $stmt = $db->prepare("SELECT * FROM services WHERE tenant_id = ? ORDER BY service_id DESC");
      $stmt->execute([$tenant_id]);
      $services_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Service Bays
      $stmt = $db->prepare("SELECT * FROM service_bays WHERE tenant_id = ? ORDER BY bay_id ASC");
      $stmt->execute([$tenant_id]);
      $bays_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Mechanics
      $stmt = $db->prepare("SELECT m.*, 
               CASE 
                WHEN m.full_name IS NOT NULL AND m.full_name != '' THEN m.full_name 
                WHEN u.name IS NOT NULL AND u.name != '' THEN u.name 
                ELSE 'Expert Mechanic' 
               END as display_name 
               FROM mechanics m 
               LEFT JOIN users u ON m.user_id = u.user_id 
               WHERE m.tenant_id = ? 
               ORDER BY m.mechanic_id ASC");
      $stmt->execute([$tenant_id]);
      $mechanics_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Inventory
      $stmt = $db->prepare("SELECT * FROM inventory WHERE tenant_id = ? ORDER BY created_at DESC");
      $stmt->execute([$tenant_id]);
      $inventory_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Staff Accounts (from users table)
      $stmt = $db->prepare("SELECT user_id, name, email, role_id, status FROM users WHERE tenant_id = ? ORDER BY role_id ASC");
      $stmt->execute([$tenant_id]);
      $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) { /* silent */
    }
  } // End of !isset($_GET['action']) block

  // Identification for Personalized Views (Mechanic context)
  $my_mechanic_id = null;
  if ($role === 'MECHANIC') {
    try {
      // Priority 1: Search by user_id sync
      $mStmt = $db->prepare("SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?");
      $mStmt->execute([$_SESSION['user_id'], $tenant_id]);
      $my_mechanic_id = $mStmt->fetchColumn();

      // Priority 2: Fallback to Name sync (helpful for some older accounts)
      if (!$my_mechanic_id) {
        $mStmt2 = $db->prepare("SELECT mechanic_id FROM mechanics WHERE full_name = ? AND tenant_id = ?");
        $mStmt2->execute([$owner_name, $tenant_id]);
        $my_mechanic_id = $mStmt2->fetchColumn();
      }
    } catch (Exception $e) {
    }
  }

  // 2. AJAX Handlers
  if (isset($_GET['action'])) {
    // Aggressively clear any buffers to ensure clean JSON output
    while (ob_get_level()) {
      ob_end_clean();
    }
    ob_start();
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    try {
      if (empty($tenant_id))
        throw new Exception("Session expired.");

      // --- SERVICES ---
      if ($_GET['action'] === 'add_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $name = trim($_POST['service_name'] ?? '');
          $desc = trim($_POST['description'] ?? '');
          $price = floatval($_POST['price'] ?? 0);

          if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Service name is required.']);
            exit;
          }

          $masterId = !empty($_POST['master_id']) ? intval($_POST['master_id']) : null;

          if ($masterId) {
            $ms = $db->prepare("SELECT * FROM master_services WHERE master_id = ?");
            $ms->execute([$masterId]);
            $standard = $ms->fetch();
            if ($standard) {
               if ($price < $standard['min_price'] || $price > $standard['max_price']) {
                  throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($standard['min_price']) . " and ₱" . number_format($standard['max_price']));
               }
               // Force standard name and category if linked
               $name = $standard['service_name'];
            }
          }

          $stmt = $db->prepare("INSERT INTO services (tenant_id, master_id, service_name, description, price, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
          if ($stmt->execute([$tenant_id, $masterId, $name, $desc, $price])) {
            try {
              $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
              $log->execute([$tenant_id, $_SESSION['user_id'] ?? 0, "Staff " . ($_SESSION['name'] ?? 'Unknown') . " added new service: $name (₱$price)"]);
            } catch (Exception $logErr) {
            }

            echo json_encode(['status' => 'success', 'message' => 'Service added successfully.']);
          } else {
            echo json_encode(['status' => 'error', 'message' => 'Database insert failed.']);
          }
        } catch (Exception $ex) {
          echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $ex->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'edit_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $id = intval($_POST['service_id'] ?? 0);
          $name = trim($_POST['service_name'] ?? '');
          $desc = trim($_POST['description'] ?? '');
          $price = floatval($_POST['price'] ?? 0);
          $masterId = !empty($_POST['master_id']) ? intval($_POST['master_id']) : null;

          if (empty($name) || empty($id)) {
            echo json_encode(['status' => 'error', 'message' => 'Service ID and name are required.']);
            exit;
          }

          if ($masterId) {
            $ms = $db->prepare("SELECT * FROM master_services WHERE master_id = ?");
            $ms->execute([$masterId]);
            $standard = $ms->fetch();
            if ($standard) {
               if ($price < $standard['min_price'] || $price > $standard['max_price']) {
                  throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($standard['min_price']) . " and ₱" . number_format($standard['max_price']));
               }
               $name = $standard['service_name'];
            }
          }

          // AUTO-HEAL: Ensure description column exists
          try {
            $db->exec("ALTER TABLE services ADD COLUMN description TEXT AFTER service_name");
          } catch (Exception $e) {
          }

          $stmt = $db->prepare("UPDATE services SET master_id=?, service_name=?, description=?, price=? WHERE service_id=? AND tenant_id=?");
          if ($stmt->execute([$masterId, $name, $desc, $price, $id, $tenant_id])) {
            try {
              $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
              $log->execute([$tenant_id, $_SESSION['user_id'] ?? 0, "Staff " . ($_SESSION['name'] ?? 'Unknown') . " updated service: $name (ID: $id)"]);
            } catch (Exception $logErr) {
            }

            echo json_encode(['status' => 'success', 'message' => 'Service updated successfully.']);
          } else {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
          }
        } catch (Exception $ex) {
          echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $ex->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'delete_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['service_id'] ?? 0);
        if (empty($id)) {
          echo json_encode(['status' => 'error', 'message' => 'Service ID is required.']);
          exit;
        }
        $stmt = $db->prepare("DELETE FROM services WHERE service_id = ? AND tenant_id = ?");
        if ($stmt->execute([$id, $tenant_id])) {
          $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
          $log->execute([$tenant_id, $_SESSION['user_id'], "Staff {$_SESSION['name']} deleted service ID: $id"]);
          echo json_encode(['status' => 'success', 'message' => 'Service deleted successfully.']);
        } else {
          echo json_encode(['status' => 'error', 'message' => 'Failed to delete service.']);
        }
        exit;
      }
      if ($_GET['action'] === 'fetch_vehicles') {
        $stmt = $db->prepare("SELECT v.*, c.full_name as customer_name 
                   FROM vehicles v 
                   LEFT JOIN customers c ON v.customer_id = c.customer_id 
                   WHERE v.tenant_id = ? 
                   ORDER BY v.vehicle_id DESC");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }


      if ($_GET['action'] === 'add_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $name = trim($_POST['staff_name'] ?? '');
          $email = trim($_POST['email'] ?? '');
          $password = $_POST['password'] ?? '';
          $role_id = intval($_POST['role_id'] ?? 3);

          if (empty($name) || empty($email) || empty($password)) {
            throw new Exception('Full Name, Email, and Password are required fields.');
          }

          $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
          $check->execute([$email]);
          if ($check->fetchColumn() > 0) {
            throw new Exception('This email address is already registered in the system.');
          }

          $hash = password_hash($password, PASSWORD_BCRYPT);

          // Handle File Upload for ID
          $id_file_path = '';
          if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/staff_ids/';
            if (!is_dir($uploadDir))
              mkdir($uploadDir, 0777, true);
            $ext = pathinfo($_FILES['id_file']['name'], PATHINFO_EXTENSION);
            $fileName = 'id_' . time() . '_' . uniqid() . '.' . $ext;
            $id_file_path = $uploadDir . $fileName;
            move_uploaded_file($_FILES['id_file']['tmp_name'], $id_file_path);
          }

          $mobile = trim($_POST['mobile'] ?? '');
          $address = trim($_POST['address'] ?? '');
          $id_type = trim($_POST['id_type'] ?? '');

          // Robust Insert with column check
          $stmt = $db->prepare("INSERT INTO users (tenant_id, role_id, name, email, password_hash, mobile, address, id_type, id_file, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
          $stmt->execute([$tenant_id, $role_id, $name, $email, $hash, $mobile, $address, $id_type, $id_file_path]);
          $newUserId = $db->lastInsertId();

          if (!$newUserId)
            throw new Exception("Failed to retrieve new user ID.");

          // Log the activity
          try {
            $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
            $log->execute([$tenant_id, $_SESSION['user_id'] ?? 0, "Staff " . ($_SESSION['name'] ?? 'Owner') . " created new staff account: $email"]);
          } catch (Exception $e) {
          }

          // Mechanic Sync
          if ($role_id == 5) {
            $spec = trim($_POST['specialization'] ?? 'General Mechanic');
            $db->prepare("INSERT INTO mechanics (tenant_id, user_id, full_name, specialization, status) VALUES (?, ?, ?, ?, 'AVAILABLE')")
              ->execute([$tenant_id, $newUserId, $name, $spec]);
          }

          echo json_encode(['status' => 'success', 'message' => 'Staff account created successfully!']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_staff') {
        try {
          $stmt = $db->prepare("SELECT u.user_id, u.name, u.email, r.role_name, u.status FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.tenant_id = ? ORDER BY u.email DESC");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_staff_details') {
        try {
          $uid = intval($_GET['user_id'] ?? 0);
          $stmt = $db->prepare("SELECT u.user_id, u.name, u.email, r.role_name, u.status FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.tenant_id = ?");
          $stmt->execute([$uid, $tenant_id]);
          $user = $stmt->fetch(PDO::FETCH_ASSOC);
          echo json_encode(['status' => 'success', 'data' => $user]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'update_staff_status') {
        try {
          $uid = intval($_POST['user_id'] ?? 0);
          $new_status = $_POST['status'] ?? 'ACTIVE';
          $stmt = $db->prepare("UPDATE users SET status = ? WHERE user_id = ? AND tenant_id = ?");
          $stmt->execute([$new_status, $uid, $tenant_id]);
          echo json_encode(['status' => 'success', 'message' => 'Staff status updated.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      // --- BAYS ---
      if ($_GET['action'] === 'add_bay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $checkLimit = $db->prepare("SELECT COUNT(*) FROM service_bays WHERE tenant_id = ?");
        $checkLimit->execute([$tenant_id]);
        if ($checkLimit->fetchColumn() >= $bay_limit) {
          throw new Exception("Subscription Limit Reached: Your current plan ($plan_tier) allows a maximum of $bay_limit service bays. Please upgrade for more slots.");
        }

        $name = trim($_POST['bay_name'] ?? '');
        if (empty($name))
          throw new Exception("Bay name required.");
        $stmt = $db->prepare("INSERT INTO service_bays (tenant_id, bay_name, status) VALUES (?, ?, 'AVAILABLE')");
        $stmt->execute([$tenant_id, $name]);
        echo json_encode(['status' => 'success', 'message' => 'Service bay added.']);
        exit;
      }
      if ($_GET['action'] === 'fetch_bays') {
        $stmt = $db->prepare("SELECT b.*, 
                   (SELECT job_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY created_at DESC LIMIT 1) as active_job_id,
                   (SELECT status FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY created_at DESC LIMIT 1) as job_status,
                   (SELECT mechanic_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY created_at DESC LIMIT 1) as active_mechanic_id
                   FROM service_bays b 
                   WHERE b.tenant_id = ? 
                   ORDER BY b.bay_id ASC");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }

      if ($_GET['action'] === 'fetch_bay_details') {
        $bay_id = $_GET['id'] ?? 0;
        $stmt = $db->prepare("SELECT * FROM service_bays WHERE bay_id = ? AND tenant_id = ?");
        $stmt->execute([$bay_id, $tenant_id]);
        $bay = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bay) {
          echo json_encode(['status' => 'error', 'message' => 'Bay not found']);
          exit;
        }

        // Fetch current job
        $stmt = $db->prepare("SELECT r.*, v.plate_no, v.make, v.model, c.full_name as customer_name, s.service_name 
                   FROM repair_jobs r
                   LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                   LEFT JOIN customers c ON r.customer_id = c.customer_id
                   LEFT JOIN services s ON r.service_id = s.service_id
                   WHERE r.bay_id = ? AND r.tenant_id = ? AND r.status NOT IN ('COMPLETED', 'CANCELLED')
                   ORDER BY r.created_at DESC LIMIT 1");
        $stmt->execute([$bay_id, $tenant_id]);
        $current_job = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch history (last 5)
        $stmt = $db->prepare("SELECT r.*, v.plate_no, s.service_name, c.full_name as customer_name
                   FROM repair_jobs r
                   LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                   LEFT JOIN customers c ON r.customer_id = c.customer_id
                   LEFT JOIN services s ON r.service_id = s.service_id
                   WHERE r.bay_id = ? AND r.tenant_id = ? AND r.status IN ('COMPLETED', 'CANCELLED')
                   ORDER BY r.completed_at DESC LIMIT 5");
        $stmt->execute([$bay_id, $tenant_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'bay' => $bay,
          'current_job' => $current_job,
          'history' => $history
        ]);
        exit;
      }

      if ($_GET['action'] === 'fetch_mechanic_profile') {
        $mech_id = $_GET['mechanic_id'] ?? 0;
        $stmt = $db->prepare("SELECT * FROM mechanics WHERE mechanic_id = ? AND tenant_id = ?");
        $stmt->execute([$mech_id, $tenant_id]);
        $mech = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mech) {
          echo json_encode(['status' => 'error', 'message' => 'Mechanic not found']);
          exit;
        }

        // Fetch job history
        $stmt = $db->prepare("SELECT r.*, v.plate_no, s.service_name 
                   FROM repair_jobs r
                   LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                   LEFT JOIN services s ON r.service_id = s.service_id
                   WHERE r.mechanic_id = ? AND r.tenant_id = ?
                   ORDER BY r.created_at DESC LIMIT 10");
        $stmt->execute([$mech_id, $tenant_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'status' => 'success',
          'mechanic' => $mech,
          'history' => $history
        ]);
        exit;
      }

      // --- MECHANICS ---
      if ($_GET['action'] === 'add_mechanic' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['mechanic_name'] ?? '');
        $spec = trim($_POST['specialization'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
          echo json_encode(['status' => 'error', 'message' => 'Name, Email, and Password are required.']);
          exit;
        }

        try {
          $db->beginTransaction();
          // ... logic follows

          // 1. Create User Account
          $checkUser = $db->prepare("SELECT user_id FROM users WHERE email = ?");
          $checkUser->execute([$email]);
          if ($checkUser->fetch()) {
            throw new Exception("Email is already registered to another user.");
          }

          $passHash = password_hash($password, PASSWORD_DEFAULT);
          $userStmt = $db->prepare("INSERT INTO users (tenant_id, email, password_hash, name, role_id, status) VALUES (?, ?, ?, ?, 5, 'ACTIVE')");
          $userStmt->execute([$tenant_id, $email, $passHash, $name, 5]);
          $userId = $db->lastInsertId();

          // 2. Link to Mechanic Table
          $stmt = $db->prepare("INSERT INTO mechanics (tenant_id, full_name, specialization, status, user_id) VALUES (?, ?, ?, 'AVAILABLE', ?)");
          $stmt->execute([$tenant_id, $name, $spec, $userId]);

          $db->commit();

          // Log activity silently so missing log table doesn't break registration
          try {
            $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
            $log->execute([$tenant_id, $_SESSION['user_id'] ?? 0, "Registered new mechanic: $name"]);
          } catch (Exception $logErr) {
          }

          echo json_encode(['status' => 'success', 'message' => 'Mechanic registered successfully.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_mechanics') {
        try {
          // Optimized query to get name from either mechanics.full_name or users.name
          $query = "SELECT m.*, 
               CASE 
                WHEN m.full_name IS NOT NULL AND m.full_name != '' THEN m.full_name 
                WHEN u.name IS NOT NULL AND u.name != '' THEN u.name 
                ELSE 'Expert Mechanic' 
               END as display_name 
               FROM mechanics m 
               LEFT JOIN users u ON m.user_id = u.user_id 
               WHERE m.tenant_id = ? 
               ORDER BY m.mechanic_id DESC";
          $stmt = $db->prepare($query);
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          // Fallback query if full_name column is actually missing
          $stmt = $db->prepare("SELECT m.*, u.name as display_name FROM mechanics m LEFT JOIN users u ON m.user_id = u.user_id WHERE m.tenant_id = ?");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        exit;
      }

      // --- INVENTORY ---
      if ($_GET['action'] === 'add_inventory' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = trim($_POST['item_code'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $qty = intval($_POST['quantity'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);

        if (empty($code) || empty($name))
          throw new Exception("Code and Name required.");

        $stmt = $db->prepare("INSERT INTO inventory (tenant_id, item_code, item_name, brand, quantity, price, status) VALUES (?, ?, ?, ?, ?, ?, 'IN_STOCK')");
        if ($stmt->execute([$tenant_id, $code, $name, $brand, $qty, $price])) {
          $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
          $log->execute([$tenant_id, $_SESSION['user_id'], "Staff {$_SESSION['name']} added inventory: $name ($qty units)"]);
          echo json_encode(['status' => 'success', 'message' => 'Item added to inventory.']);
        }
        exit;
      }
      if ($_GET['action'] === 'fetch_inventory') {
        $stmt = $db->prepare("SELECT * FROM inventory WHERE tenant_id = ? ORDER BY item_id DESC");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }

      // --- REPORTS ---
      if ($_GET['action'] === 'get_revenue_report') {
        $stmt = $db->prepare("SELECT DATE(created_at) as date, SUM(amount) as total FROM payments WHERE tenant_id = ? AND status IN ('SUCCESS', 'COMPLETED') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
        $stmt->execute([$tenant_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results ?: []);
        exit;
      }
      if ($_GET['action'] === 'get_service_performance') {
        // We check if the service_id or a bridge table exists. 
        // For now, let's assume jobs are linked to a service. 
        // If the column is missing, we'll return an empty array for now to avoid crashing.
        try {
          $stmt = $db->prepare("SELECT s.service_name, COUNT(j.job_id) as count FROM repair_jobs j JOIN services s ON j.service_id = s.service_id WHERE j.tenant_id = ? GROUP BY s.service_name ORDER BY count DESC LIMIT 5");
          $stmt->execute([$tenant_id]);
          $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($results ?: []);
        } catch (Exception $e) {
          echo json_encode([]); // Return empty if query fails (e.g. missing column)
        }
        exit;
      }
      if ($_GET['action'] === 'get_inventory_report') {
        $stmt = $db->prepare("SELECT item_name, quantity FROM inventory WHERE tenant_id = ? AND quantity < 10 ORDER BY quantity ASC");
        $stmt->execute([$tenant_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results ?: []);
        exit;
      }
      if ($_GET['action'] === 'save_setting_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
          $field = $_POST['field'] ?? '';
          $value = $_POST['value'] ?? '';

          // Specific handling for Logo/Banner uploads if they are passed as field
          if (($field === 'logo_file' || $field === 'banner_file') && isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir))
              mkdir($uploadDir, 0755, true);
            $fileExt = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
            $prefix = ($field === 'logo_file') ? 'logo' : 'banner';
            $fileName = $prefix . '_tenant_' . $tenant_id . '_' . time() . '.' . $fileExt;

            if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . $fileName)) {
              $dbCol = ($field === 'logo_file') ? 'logo_url' : 'banner_url';
              $value = 'uploads/' . $fileName;
              $stmt = $db->prepare("UPDATE tenants SET $dbCol = ? WHERE tenant_id = ?");
              $stmt->execute([$value, $tenant_id]);

              // Audit Log
              $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'INFO', 'Updated $dbCol via upload')")
                ->execute([$tenant_id, $_SESSION['user_id']]);

              echo json_encode(['status' => 'success', 'message' => 'File uploaded successfully!', 'new_url' => $value]);
              exit;
            } else {
              throw new Exception("Failed to move uploaded file.");
            }
          }

          // Standard Field Update
          $allowedFields = [
            'shop_name',
            'description',
            'hero_title',
            'hero_subtitle',
            'about_text',
            'primary_color',
            'secondary_color',
            'ui_style',
            'border_radius',
            'logo_url',
            'banner_url',
            'staff_announcement',
            'phone',
            'opening_hours',
            'address',
            'facebook_url',
            'instagram_url'
          ];

          if (!in_array($field, $allowedFields)) {
            throw new Exception("Invalid setting field: " . $field);
          }

          // Special case for border_radius to add 'px'
          if ($field === 'border_radius' && is_numeric($value)) {
            $value = $value . 'px';
          }

          $stmt = $db->prepare("UPDATE tenants SET $field = ? WHERE tenant_id = ?");
          $stmt->execute([$value, $tenant_id]);

          // Sync Staff Announcement if that's what was updated
          if ($field === 'staff_announcement' && !empty($value)) {
            $checkLast = $db->prepare("SELECT message FROM announcements WHERE tenant_id = ? AND type = 'SHOP' ORDER BY announcement_id DESC LIMIT 1");
            $checkLast->execute([$tenant_id]);
            if ($checkLast->fetchColumn() !== $value) {
              $db->prepare("INSERT INTO announcements (tenant_id, user_id, message, type) VALUES (?, ?, ?, 'SHOP')")
                ->execute([$tenant_id, $_SESSION['user_id'], $value]);
            }
          }

          // Audit Log
          $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'INFO', 'Updated shop setting: $field')")
            ->execute([$tenant_id, $_SESSION['user_id']]);

          echo json_encode(['status' => 'success', 'message' => 'Setting updated successfully!']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'save_customization' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        file_put_contents('debug_save.txt', print_r($_POST, true));
        try {
          // Force ensure ALL columns exist (Standard MySQL approach)
          $cols = [
            'staff_announcement' => 'TEXT NULL',
            'primary_color' => 'VARCHAR(20) DEFAULT "#10b981"',
            'secondary_color' => 'VARCHAR(20) DEFAULT "#030712"',
            'phone' => 'VARCHAR(20) NULL',
            'description' => 'TEXT NULL',
            'address' => 'TEXT NULL',
            'ui_style' => 'VARCHAR(20) DEFAULT "GLASS"',
            'border_radius' => 'VARCHAR(10) DEFAULT "24px"',
            'hero_title' => 'VARCHAR(255) NULL',
            'hero_subtitle' => 'TEXT NULL',
            'banner_url' => 'TEXT NULL',
            'about_text' => 'TEXT NULL',
            'facebook_url' => 'TEXT NULL',
            'instagram_url' => 'TEXT NULL',
            'opening_hours' => 'VARCHAR(255) DEFAULT "Mon - Sat: 8:00 AM - 5:00 PM"',
            'logo_url' => 'TEXT NULL'
          ];
          foreach ($cols as $col => $def) {
            try {
              $db->exec("ALTER TABLE tenants ADD COLUMN $col $def");
            } catch (Exception $e) {
            }
          }

          $shopName = trim($_POST['shop_name'] ?? '');
          $primary = $_POST['primary_color'] ?? '#10b981';
          $secondary = $_POST['secondary_color'] ?? '#030712';

          // Normalize Hex (Ensure it starts with #)
          if (strpos($primary, '#') !== 0)
            $primary = '#' . $primary;
          if (strpos($secondary, '#') !== 0)
            $secondary = '#' . $secondary;
          $phone = trim($_POST['phone'] ?? '');
          $desc = trim($_POST['description'] ?? '');
          $addr = trim($_POST['address'] ?? '');
          $style = $_POST['ui_style'] ?? 'GLASS';
          $radius = ($_POST['border_radius_val'] ?? '24') . 'px';

          $heroTitle = trim($_POST['hero_title'] ?? '');
          $heroSub = trim($_POST['hero_subtitle'] ?? '');
          $about = trim($_POST['about_text'] ?? '');
          $hours = trim($_POST['opening_hours'] ?? 'Mon - Sat: 8:00 AM - 5:00 PM');
          $fb = trim($_POST['facebook_url'] ?? '');
          $ig = trim($_POST['instagram_url'] ?? '');
          $staffAnn = trim($_POST['staff_announcement'] ?? '');

          // Fetch current values for fallback
          $st = $db->prepare("SELECT logo_url, banner_url FROM tenants WHERE tenant_id = ?");
          $st->execute([$tenant_id]);
          $curr = $st->fetch();

          // Handle Logo
          $logo = trim($_POST['logo_url'] ?? $curr['logo_url'] ?? '');
          if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir))
              mkdir($uploadDir, 0755, true);
            $fileExt = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
            $fileName = 'logo_tenant_' . $tenant_id . '_' . time() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $fileName)) {
              $logo = 'uploads/' . $fileName;
            }
          }

          // Handle Banner
          $banner = trim($_POST['banner_url'] ?? $curr['banner_url'] ?? '');
          if (isset($_FILES['banner_file']) && $_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir))
              mkdir($uploadDir, 0755, true);
            $fileExt = pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION);
            $fileName = 'banner_tenant_' . $tenant_id . '_' . time() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['banner_file']['tmp_name'], $uploadDir . $fileName)) {
              $banner = 'uploads/' . $fileName;
            }
          }

          $stmt = $db->prepare("UPDATE tenants SET 
            shop_name = ?, logo_url = ?, primary_color = ?, secondary_color = ?, 
            phone = ?, description = ?, address = ?, ui_style = ?, border_radius = ?,
            hero_title = ?, hero_subtitle = ?, banner_url = ?, about_text = ?, 
            opening_hours = ?, facebook_url = ?, instagram_url = ?, staff_announcement = ?
            WHERE tenant_id = ?");
          $stmt->execute([
            $shopName,
            $logo,
            $primary,
            $secondary,
            $phone,
            $desc,
            $addr,
            $style,
            $radius,
            $heroTitle,
            $heroSub,
            $banner,
            $about,
            $hours,
            $fb,
            $ig,
            $staffAnn,
            $tenant_id
          ]);

          // Sync Announcements
          if (!empty($staffAnn)) {
            $checkLast = $db->prepare("SELECT message FROM announcements WHERE tenant_id = ? AND type = 'SHOP' ORDER BY announcement_id DESC LIMIT 1");
            $checkLast->execute([$tenant_id]);
            if ($checkLast->fetchColumn() !== $staffAnn) {
              $db->prepare("INSERT INTO announcements (tenant_id, user_id, message, type) VALUES (?, ?, ?, 'SHOP')")
                ->execute([$tenant_id, $_SESSION['user_id'], $staffAnn]);
            }
          }

          $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'INFO', 'Updated shop settings')")
            ->execute([$tenant_id, $_SESSION['user_id']]);

          echo json_encode(['status' => 'success', 'message' => 'Design and settings saved successfully!']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
      }

      // --- CUSTOMERS ---
      if ($_GET['action'] === 'fetch_customers') {
        ob_clean(); // Clear any previous output/warnings
        try {
          $db = getDB();
          // AUTO-HEAL: Ensure table exists
          $db->exec("CREATE TABLE IF NOT EXISTS customers (
            customer_id INT AUTO_INCREMENT PRIMARY KEY, 
            tenant_id INT, 
            full_name VARCHAR(100), 
            email VARCHAR(100), 
            mobile VARCHAR(20), 
            status VARCHAR(20) DEFAULT 'ACTIVE', 
            total_visits INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )");

          $stmt = $db->prepare("SELECT * FROM customers WHERE tenant_id = ? ORDER BY full_name ASC");
          $stmt->execute([$tenant_id]);
          $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

          // IF EMPTY, RETURN A SUCCESSFUL EMPTY RESPONSE FOR DEBUGGING
          if (empty($data)) {
            header('Content-Type: application/json');
            echo json_encode([]);
            exit;
          }

          header('Content-Type: application/json');
          echo json_encode($data);
        } catch (Exception $e) {
          header('Content-Type: application/json');
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $password = $_POST['password'] ?? '123456';

        if (empty($name) || empty($mobile))
          throw new Exception("Full name and mobile number are required.");
        if (empty($email))
          throw new Exception("Email address is required for registration.");

        // If email provided, check for uniqueness within this tenant
        $check = $db->prepare("SELECT COUNT(*) FROM customers WHERE email = ? AND tenant_id = ?");
        $check->execute([$email, $tenant_id]);
        if ($check->fetchColumn() > 0)
          throw new Exception("This email is already registered for this shop.");

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO customers (tenant_id, full_name, mobile, email, password_hash, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
        $stmt->execute([$tenant_id, $name, $mobile, $email, $hash]);

        $customerId = $db->lastInsertId();
        $logStmt = $db->prepare("INSERT INTO audit_logs (tenant_id, customer_id, activity_type, description) VALUES (?, ?, 'CUSTOMER_REG', ?)");
        $logStmt->execute([$tenant_id, $customerId, "New customer account $email was created by staff."]);

        echo json_encode(['status' => 'success', 'message' => 'Customer registered successfully.']);
        exit;
      }

      if ($_GET['action'] === 'edit_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['customer_id'] ?? 0);
        $name = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');

        if (empty($id) || empty($name) || empty($mobile))
          throw new Exception("Full name and mobile number are required.");

        $stmt = $db->prepare("UPDATE customers SET full_name = ?, mobile = ?, email = ? WHERE customer_id = ? AND tenant_id = ?");
        $stmt->execute([$name, $mobile, $email, $id, $tenant_id]);

        $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
        $log->execute([$tenant_id, $_SESSION['user_id'], "Staff {$_SESSION['name']} updated info for customer: $name (ID: $id)"]);

        echo json_encode(['status' => 'success', 'message' => 'Customer information updated successfully.']);
        exit;
      }

      if ($_GET['action'] === 'add_vehicle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $customerId = intval($_POST['customer_id'] ?? 0);
          $plate = strtoupper(trim($_POST['plate_no'] ?? ''));
          $make = trim($_POST['make'] ?? '');
          $model = trim($_POST['model'] ?? '');
          $year = intval($_POST['year'] ?? date('Y'));

          if (empty($plate) || empty($make) || $customerId == 0)
            throw new Exception("Owner, Plate No, and Make are required.");

          $stmt = $db->prepare("INSERT INTO vehicles (tenant_id, customer_id, plate_no, make, model, year_model) VALUES (?, ?, ?, ?, ?, ?)");
          $stmt->execute([$tenant_id, $customerId, $plate, $make, $model, $year]);

          echo json_encode(['status' => 'success', 'message' => 'Vehicle registered successfully.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_pending_count') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND status = 'PENDING'");
        $stmt->execute([$tenant_id]);
        echo json_encode(['count' => $stmt->fetchColumn()]);
        exit;
      }

      if ($_GET['action'] === 'fetch_all_appointments') {
        try {
          $stmt = $db->prepare("SELECT a.*, c.full_name as customer_name, v.plate_no, v.make, v.model, s.service_name, s.price as service_price 
                     FROM appointments a 
                     LEFT JOIN customers c ON a.customer_id = c.customer_id 
                     LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
                     LEFT JOIN services s ON a.service_id = s.service_id 
                     WHERE a.tenant_id = ? 
                     ORDER BY a.appointment_date DESC, a.appointment_time ASC LIMIT 100");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'update_appointment_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $id = intval($_POST['appointment_id'] ?? 0);
          $status = $_POST['status'] ?? '';
          $mechanic_id = !empty($_POST['mechanic_id']) ? intval($_POST['mechanic_id']) : null;
          $bay_id = !empty($_POST['bay_id']) ? intval($_POST['bay_id']) : null;

          if (!$id || !in_array($status, ['CONFIRMED', 'CANCELLED', 'COMPLETED', 'PENDING'])) {
            throw new Exception("Parameters missing: ID=$id Status=$status");
          }

          // 1. Basic Status Update
          $stmt = $db->prepare("UPDATE appointments SET status = ?, mechanic_id = ?, bay_id = ? WHERE appointment_id = ? AND tenant_id = ?");
          $stmt->execute([$status, $mechanic_id, $bay_id, $id, $tenant_id]);

          // 2. Extra steps only for confirmation
          if ($status === 'CONFIRMED') {
            $apptQ = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND tenant_id = ?");
            $apptQ->execute([$id, $tenant_id]);
            $appt = $apptQ->fetch(PDO::FETCH_ASSOC);

            if ($appt) {
              $price = $appt['total_estimate'] ?? ($appt['estimated_amount'] ?? 0);
              $jobStmt = $db->prepare("INSERT INTO repair_jobs (tenant_id, customer_id, vehicle_id, service_id, appointment_id, mechanic_id, bay_id, status, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)");
              $jobStmt->execute([
                $tenant_id,
                $appt['customer_id'],
                $appt['vehicle_id'],
                $appt['service_id'],
                $id,
                $mechanic_id,
                $bay_id,
                $price
              ]);
              $newJobId = $db->lastInsertId();

              $db->prepare("INSERT INTO repair_timeline (job_id, status_update, remarks) VALUES (?, 'PENDING', 'Repairs initialized from confirmed booking.')")->execute([$newJobId]);

              if ($mechanic_id)
                $db->prepare("UPDATE mechanics SET status = 'BUSY' WHERE mechanic_id = ?")->execute([$mechanic_id]);
              if ($bay_id)
                $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ?")->execute([$bay_id]);
            }
          }

          // 3. Log it
          try {
            $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
            $log->execute([$tenant_id, $_SESSION['user_id'] ?? null, "Updated appt #$id to $status"]);
          } catch (Exception $le) {
          }

          echo json_encode(['status' => 'success', 'message' => "Success: Appointment marked as $status."]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => "Server Error: " . $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_available_resources') {
        try {
          $prefId = intval($_GET['preferred_id'] ?? 0);
          // Optimized diagnostic query with fallbacks for missing columns
          $mechanicQuery = "SELECT mechanic_id, full_name, status FROM mechanics WHERE tenant_id = ?";

          $mStmt = $db->prepare($mechanicQuery);
          $mStmt->execute([$tenant_id]);

          $bays = $db->prepare("SELECT bay_id, bay_name, status FROM service_bays WHERE tenant_id = ? ORDER BY bay_id ASC");
          $bays->execute([$tenant_id]);

          $pending = $db->prepare("SELECT r.job_id, v.plate_no, s.service_name 
                      FROM repair_jobs r 
                      LEFT JOIN services s ON r.service_id = s.service_id 
                      LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                      WHERE r.tenant_id = ? AND r.status = 'PENDING' AND (r.bay_id IS NULL OR r.bay_id = 0)");
          $pending->execute([$tenant_id]);

          echo json_encode([
            'mechanics' => $mStmt->fetchAll(PDO::FETCH_ASSOC),
            'bays' => $bays->fetchAll(PDO::FETCH_ASSOC),
            'pending_jobs' => $pending->fetchAll(PDO::FETCH_ASSOC)
          ]);
        } catch (Exception $e) {
          error_log("fetch_available_resources ERROR: " . $e->getMessage());
          echo json_encode(['mechanics' => [], 'bays' => [], 'pending_jobs' => [], 'error' => $e->getMessage()]);
        }
        exit;
      }
      if ($_GET['action'] === 'fetch_unpaid_appointments') {
        $stmt = $db->prepare("SELECT a.*, c.full_name as customer_name, v.plate_no, s.service_name 
                   FROM appointments a 
                   JOIN customers c ON a.customer_id = c.customer_id 
                   JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
                   LEFT JOIN services s ON a.service_id = s.service_id 
                   WHERE a.tenant_id = ? AND a.payment_status = 'UNPAID' AND a.status = 'COMPLETED'");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }

      if ($_GET['action'] === 'fetch_job_orders') {
        try {
          $stmt = $db->prepare("SELECT j.*, v.plate_no, v.make, v.model, s.service_name, 
                     COALESCE(m.full_name, 'No Mechanic') as mechanic_name,
                     b.bay_name,
                     (SELECT remarks FROM repair_timeline WHERE job_id = j.job_id AND remarks != '' ORDER BY created_at DESC LIMIT 1) as latest_remarks
                     FROM repair_jobs j
                     LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                     LEFT JOIN services s ON j.service_id = s.service_id
                     LEFT JOIN mechanics m ON j.mechanic_id = m.mechanic_id
                     LEFT JOIN service_bays b ON j.bay_id = b.bay_id
                     WHERE j.tenant_id = ? AND j.status != 'CANCELLED'
                     ORDER BY j.created_at DESC");
          $stmt->execute([$tenant_id]);
          $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($res ?: []);
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_vehicle_history') {
        try {
          $vehicleId = intval($_GET['id'] ?? 0);
          $stmt = $db->prepare("SELECT j.*, s.service_name, m.full_name as mechanic_name, b.bay_name, v.plate_no, v.make, v.model
                     FROM repair_jobs j
                     LEFT JOIN services s ON j.service_id = s.service_id
                     LEFT JOIN mechanics m ON j.mechanic_id = m.mechanic_id
                     LEFT JOIN service_bays b ON j.bay_id = b.bay_id
                     LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                     WHERE j.vehicle_id = ? AND j.tenant_id = ?
                     ORDER BY j.created_at DESC");
          $stmt->execute([$vehicleId, $tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }


      if ($_GET['action'] === 'update_job_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $jobId = intval($_POST['job_id'] ?? 0);
          $newStatus = $_POST['status'] ?? '';
          $remarks = trim($_POST['remarks'] ?? '');
          $mechanicId = !empty($_POST['mechanic_id']) ? intval($_POST['mechanic_id']) : null;
          $bayId = !empty($_POST['bay_id']) ? intval($_POST['bay_id']) : null;

          if (!$jobId || empty($newStatus)) {
            throw new Exception("Invalid job data.");
          }

          // Enforce rule: Must have BOTH a mechanic and a bay to save updates
          if (empty($mechanicId) || empty($bayId)) {
            throw new Exception("Operation Denied: A mechanic and a service bay must be assigned to this job first.");
          }

          // Get old state to handle status transfers
          $oldJob = $db->prepare("SELECT mechanic_id, bay_id, status FROM repair_jobs WHERE job_id = ? AND tenant_id = ?");
          $oldJob->execute([$jobId, $tenant_id]);
          $old = $oldJob->fetch(PDO::FETCH_ASSOC);

          if (!$old) {
            throw new Exception("Job not found.");
          }

          // Critical Fix: Never wipe existing mechanic or bay if the submission didn't provide one
          if (empty($mechanicId))
            $mechanicId = $old['mechanic_id'];
          if (empty($bayId))
            $bayId = $old['bay_id'];

          $checklist = $_POST['checklist'] ?? null;

          // Elite Logic: Master Timers
          $timeUpdate = "";
          if ($newStatus === 'IN_PROGRESS')
            $timeUpdate = ", started_at = IFNULL(started_at, NOW())";
          if ($newStatus === 'COMPLETED')
            $timeUpdate = ", completed_at = NOW()";

          // Update the job
          $stmt = $db->prepare("UPDATE repair_jobs SET status = ?, mechanic_id = ?, bay_id = ?, checklist = ?, updated_at = NOW() $timeUpdate WHERE job_id = ? AND tenant_id = ?");
          $stmt->execute([$newStatus, $mechanicId, $bayId, $checklist, $jobId, $tenant_id]);

          $db->prepare("INSERT INTO repair_timeline (job_id, status_update, remarks, tenant_id) VALUES (?, ?, ?, ?)")
            ->execute([$jobId, $newStatus, $remarks, $tenant_id]);

          // Resource status management
          if ($newStatus === 'COMPLETED' || $newStatus === 'CANCELLED') {
            if ($mechanicId) {
              $db->prepare("UPDATE mechanics SET status = 'AVAILABLE' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
            }
            if ($bayId) {
              $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
            }
          } else {
            // If assigned new resources, make them BUSY/OCCUPIED
            if ($mechanicId) {
              $db->prepare("UPDATE mechanics SET status = 'BUSY' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
            }
            if ($bayId) {
              $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
            }

            // If changed from old resources, free the old ones
            if ($old['mechanic_id'] && $old['mechanic_id'] != $mechanicId) {
              $db->prepare("UPDATE mechanics SET status = 'AVAILABLE' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$old['mechanic_id'], $tenant_id]);
            }
            if ($old['bay_id'] && $old['bay_id'] != $bayId) {
              $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ?")->execute([$old['bay_id'], $tenant_id]);
            }
          }

          echo json_encode(['status' => 'success', 'message' => "Job order updated successfully with elite tracking."]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }


      if ($_GET['action'] === 'add_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $customerId = $_POST['customer_id'] ?? '0';
          $walkinName = trim($_POST['walkin_name'] ?? '');
          $amount = floatval($_POST['amount'] ?? 0);
          $method = trim($_POST['payment_method'] ?? 'CASH');
          $ref = trim($_POST['reference_no'] ?? '');

          if ($amount <= 0 || empty($customerId))
            throw new Exception("Select a customer and enter a valid amount.");

          // If Walk-in, we might want to create a guest customer or just log the name
          // For now, let's allow customerId to be 0 or null for Walk-ins in the DB if supported, 
          // or just use the name in a description.
          // BUT, to keep integrity, let's see if we should create a 'Walk-in' record.
          
          $finalCustomerId = is_numeric($customerId) ? intval($customerId) : null;
          if ($customerId === 'WALKIN' && !empty($walkinName)) {
              // Create a quick customer record for the walk-in
              $stmt = $db->prepare("INSERT INTO customers (tenant_id, full_name, mobile, status) VALUES (?, ?, 'WALKIN', 'ACTIVE')");
              $stmt->execute([$tenant_id, $walkinName]);
              $finalCustomerId = $db->lastInsertId();
          }

          if (!$finalCustomerId && $customerId !== 'WALKIN') throw new Exception("Invalid customer selected.");

          $jobId = !empty($_POST['job_id']) ? intval($_POST['job_id']) : null;
          $apptId = !empty($_POST['appointment_id']) ? intval($_POST['appointment_id']) : null;

          $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, job_id, appointment_id, amount, payment_method, reference_no, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW())");
          $stmt->execute([$tenant_id, $finalCustomerId, $jobId, $apptId, $amount, $method, $ref]);

          // If linked to a job, mark the job as PAID
          if ($jobId) {
            $db->prepare("UPDATE repair_jobs SET payment_status = 'PAID' WHERE job_id = ? AND tenant_id = ?")->execute([$jobId, $tenant_id]);
          }
          if ($apptId) {
            $db->prepare("UPDATE appointments SET payment_status = 'PAID' WHERE appointment_id = ? AND tenant_id = ?")->execute([$apptId, $tenant_id]);
          }

          // Increment customer visit log
          if ($finalCustomerId) {
            $up = $db->prepare("UPDATE customers SET total_visits = total_visits + 1 WHERE customer_id = ?");
            $up->execute([$finalCustomerId]);
          }

          echo json_encode(['status' => 'success', 'message' => 'Payment logged successfully! Visit counter updated.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'approve_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $pid = intval($_POST['payment_id'] ?? 0);
          $stmt = $db->prepare("UPDATE payments SET status = 'COMPLETED', payment_date = NOW() WHERE payment_id = ? AND tenant_id = ?");
          $stmt->execute([$pid, $tenant_id]);

          // Increment visit
          $stmt = $db->prepare("SELECT customer_id FROM payments WHERE payment_id = ?");
          $stmt->execute([$pid]);
          $cId = $stmt->fetchColumn();
          if ($cId) {
            $db->prepare("UPDATE customers SET total_visits = total_visits + 1 WHERE customer_id = ?")->execute([$cId]);
          }

          echo json_encode(['status' => 'success', 'message' => 'Payment approved successfully!']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_appointment_payment') {
        try {
          $aid = intval($_GET['appointment_id'] ?? 0);
          $stmt = $db->prepare("SELECT p.*, c.full_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.appointment_id = ? AND p.tenant_id = ? LIMIT 1");
          $stmt->execute([$aid, $tenant_id]);
          $payment = $stmt->fetch(PDO::FETCH_ASSOC);
          echo json_encode(['status' => 'success', 'payment' => $payment]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_audit_logs') {
        try {
          $stmt = $db->prepare("
            SELECT l.*, 
                COALESCE(u.name, c.full_name, 'System') as actor_name,
                u.email as staff_email
            FROM audit_logs l 
            LEFT JOIN users u ON l.user_id = u.user_id 
            LEFT JOIN customers c ON l.customer_id = c.customer_id
            WHERE l.tenant_id = ? 
             AND (u.role_id != 2 OR u.role_id IS NULL)
            ORDER BY l.log_id DESC LIMIT 100
          ");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_payments') {
        try {
          $stmt = $db->prepare("SELECT p.*, c.full_name as customer_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.tenant_id = ? ORDER BY p.payment_id DESC LIMIT 100");
          $stmt->execute([$tenant_id]);
          $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($data);
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_eod_summary') {
        try {
          $today = date('Y-m-d');
          
          // 1. Total Collection Today
          $stmt = $db->prepare("SELECT SUM(amount) as total FROM payments WHERE tenant_id = ? AND DATE(payment_date) = ?");
          $stmt->execute([$tenant_id, $today]);
          $total = $stmt->fetch()['total'] ?? 0;

          // 2. Breakdown by Method
          $stmt = $db->prepare("SELECT payment_method, SUM(amount) as total, COUNT(*) as count FROM payments WHERE tenant_id = ? AND DATE(payment_date) = ? GROUP BY payment_method");
          $stmt->execute([$tenant_id, $today]);
          $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

          // 3. Today's Transactions
          $stmt = $db->prepare("SELECT p.*, c.full_name as customer_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.tenant_id = ? AND DATE(p.payment_date) = ? ORDER BY p.payment_date DESC");
          $stmt->execute([$tenant_id, $today]);
          $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
            'date' => $today,
            'total' => $total,
            'breakdown' => $breakdown,
            'transactions' => $transactions
          ]);
        } catch (Exception $e) {
          echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_receipt_details') {
        try {
          $pid = intval($_GET['payment_id'] ?? 0);
          $stmt = $db->prepare("SELECT p.*, c.full_name, c.mobile FROM payments p JOIN customers c ON p.customer_id = c.customer_id WHERE p.payment_id = ? AND p.tenant_id = ?");
          $stmt->execute([$pid, $tenant_id]);
          echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'assign_bay_job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $bayId = intval($_POST['bay_id'] ?? 0);
          $vehicleId = intval($_POST['vehicle_id'] ?? 0);
          $serviceId = intval($_POST['service_id'] ?? 0);
          $mechanicId = intval($_POST['mechanic_id'] ?? 0);

          if (!$bayId || !$vehicleId || !$serviceId)
            throw new Exception("Please select all required fields.");

          // Fetch customer from vehicle
          $vQ = $db->prepare("SELECT customer_id FROM vehicles WHERE vehicle_id = ? AND tenant_id = ?");
          $vQ->execute([$vehicleId, $tenant_id]);
          $customerId = $vQ->fetchColumn();

          if (!$customerId)
            throw new Exception("Invalid vehicle or owner not found.");

          // Create Job Order
          $stmt = $db->prepare("INSERT INTO repair_jobs (tenant_id, customer_id, vehicle_id, service_id, mechanic_id, bay_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NOW())");
          $stmt->execute([$tenant_id, $customerId, $vehicleId, $serviceId, $mechanicId, $bayId]);
          $newJobId = $db->lastInsertId();

          // Update Statuses
          $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
          if ($mechanicId) {
            $db->prepare("UPDATE mechanics SET status = 'BUSY' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
          }

          // Log it
          $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
          $log->execute([$tenant_id, $_SESSION['user_id'] ?? null, "Assigned vehicle ID $vehicleId to Bay $bayId. Job #$newJobId created."]);

          echo json_encode(['status' => 'success', 'message' => 'Vehicle assigned! Job Order #' . str_pad($newJobId, 4, '0', STR_PAD_LEFT) . ' is now pending.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'edit_staff_ann' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        try {
          $u_role = strtoupper($_SESSION['role'] ?? '');
          if ($u_role !== 'OWNER' && $u_role !== 'MANAGER')
            throw new Exception("Unauthorized.");
          $msg = trim($_POST['announcement'] ?? '');
          if (empty($msg))
            throw new Exception("Message cannot be empty.");

          $stmt = $db->prepare("INSERT INTO announcements (tenant_id, user_id, message, type, created_at) VALUES (?, ?, ?, 'SHOP', NOW())");
          $stmt->execute([$tenant_id, $_SESSION['user_id'], $msg]);

          echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_vehicles') {
        try {
          $stmt = $db->prepare("SELECT v.*, c.full_name as owner_name FROM vehicles v LEFT JOIN customers c ON v.customer_id = c.customer_id WHERE v.tenant_id = ? ORDER BY v.vehicle_id DESC");
          $stmt->execute([$tenant_id]);
          $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($data ?: []);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_customer_details') {
        try {
          $customerId = intval($_GET['customer_id'] ?? 0);
          $stmt = $db->prepare("SELECT * FROM customers WHERE customer_id = ? AND tenant_id = ?");
          $stmt->execute([$customerId, $tenant_id]);
          $customer = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$customer)
            throw new Exception("Customer not found.");

          $vStmt = $db->prepare("SELECT * FROM vehicles WHERE customer_id = ? AND tenant_id = ?");
          $vStmt->execute([$customerId, $tenant_id]);
          $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);

          $aStmt = $db->prepare("SELECT a.*, s.service_name 
                     FROM appointments a 
                     LEFT JOIN services s ON a.service_id = s.service_id 
                     WHERE a.customer_id = ? AND a.tenant_id = ? 
                     ORDER BY a.appointment_date DESC");
          $aStmt->execute([$customerId, $tenant_id]);
          $appointments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode([
            'status' => 'success',
            'customer' => $customer,
            'vehicles' => $vehicles,
            'appointments' => $appointments
          ]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'log_db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $type = $_POST['type'] ?? 'SYSTEM';
        $activity = $_POST['activity'] ?? 'Unknown action';
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_id, $type, $activity]);
        echo json_encode(['status' => 'success']);
        exit;
      }

      // Redundant code removed.

      if ($_GET['action'] === 'fetch_job_details') {
        try {
          $id = intval($_GET['job_id'] ?? 0);
          $stmt = $db->prepare("SELECT j.*, c.full_name as customer_name, v.plate_no, v.make, v.model, s.service_name,
                     (SELECT remarks FROM repair_timeline WHERE job_id = j.job_id AND remarks != '' ORDER BY created_at DESC LIMIT 1) as latest_remarks
                     FROM repair_jobs j 
                     LEFT JOIN customers c ON j.customer_id = c.customer_id 
                     LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id 
                     LEFT JOIN services s ON j.service_id = s.service_id 
                     WHERE j.job_id = ? AND j.tenant_id = ?");
          $stmt->execute([$id, $tenant_id]);
          $job = $stmt->fetch(PDO::FETCH_ASSOC);

          $tStmt = $db->prepare("SELECT * FROM repair_timeline WHERE job_id = ? ORDER BY created_at DESC");
          $tStmt->execute([$id]);
          $timeline = $tStmt->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode(['status' => 'success', 'job' => $job, 'timeline' => $timeline]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_audit_logs') {
        $stmt = $db->prepare("SELECT a.*, c.full_name as customer_name, u.name as staff_name 
                   FROM audit_logs a 
                   LEFT JOIN customers c ON a.customer_id = c.customer_id 
                   LEFT JOIN users u ON a.user_id = u.user_id
                   WHERE a.tenant_id = ? 
                   ORDER BY a.created_at DESC LIMIT 100");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }

      if ($_GET['action'] === 'fetch_staff_details') {
        try {
          $uid = intval($_GET['user_id'] ?? 0);
          $stmt = $db->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.tenant_id = ?");
          $stmt->execute([$uid, $tenant_id]);
          $user = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$user) {
            echo json_encode(['status' => 'error', 'message' => "Account details not found for ID: $uid"]);
          } else {
            $user['status_success'] = true; // Flag for JS
            echo json_encode($user);
          }
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'update_staff_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = intval($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? 'ACTIVE';
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE user_id = ? AND tenant_id = ?");
        $stmt->execute([$status, $uid, $tenant_id]);
        echo json_encode(['status' => 'success', 'message' => "Account marked as $status"]);
        exit;
      }

      if ($_GET['action'] === 'reset_staff_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = intval($_POST['user_id'] ?? 0);
        $pass = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? AND tenant_id = ?");
        $stmt->execute([$pass, $uid, $tenant_id]);
        echo json_encode(['status' => 'success', 'message' => 'Password reset to default (123456).']);
        exit;
      }

      if ($_GET['action'] === 'fetch_mechanic_work_log') {
        try {
          if ($role !== 'MECHANIC' || !$my_mechanic_id)
            throw new Exception("Unauthorized access.");
          $stmt = $db->prepare("SELECT t.status_update, t.remarks, t.created_at, v.plate_no, v.make, v.model, j.started_at, j.completed_at, j.checklist
                     FROM repair_timeline t
                     JOIN repair_jobs j ON t.job_id = j.job_id
                     JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                     WHERE j.mechanic_id = ? AND j.tenant_id = ? AND t.status_update = 'COMPLETED'
                     ORDER BY t.created_at DESC LIMIT 50");
          $stmt->execute([$my_mechanic_id, $tenant_id]);
          echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_overview_stats') {
        try {
          $stmt = $db->prepare("SELECT COUNT(*) FROM service_bays WHERE tenant_id = ? AND status = 'AVAILABLE'");
          $stmt->execute([$tenant_id]);
          $avail_bays = $stmt->fetchColumn();

          $stmt = $db->prepare("SELECT COUNT(*) FROM repair_jobs WHERE tenant_id = ? AND status = 'PENDING'");
          $stmt->execute([$tenant_id]);
          $pending_jobs = $stmt->fetchColumn();

          $stmt = $db->prepare("SELECT SUM(amount) FROM payments WHERE tenant_id = ? AND status IN ('SUCCESS', 'COMPLETED') AND DATE(payment_date) = CURDATE()");
          $stmt->execute([$tenant_id]);
          $revenue = floatval($stmt->fetchColumn() ?: 0);

          // Calculate Unpaid Balance
          $stmt = $db->prepare("SELECT SUM(total_amount) FROM repair_jobs WHERE tenant_id = ? AND status = 'COMPLETED' AND (payment_status = 'UNPAID' OR payment_status IS NULL)");
          $stmt->execute([$tenant_id]);
          $unpaid = floatval($stmt->fetchColumn() ?: 0);

          echo json_encode(['avail_bays' => $avail_bays, 'pending_jobs' => $pending_jobs, 'revenue' => $revenue, 'unpaid_balance' => $unpaid]);
        } catch (Exception $e) {
          echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_dashboard_repair_jobs') {
        try {
          $stmt = $db->prepare("SELECT j.*, v.plate_no, v.make, v.model, s.service_name, c.full_name as owner_name, m.full_name as mechanic_name
                     FROM repair_jobs j
                     LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                     LEFT JOIN customers c ON j.customer_id = c.customer_id
                     LEFT JOIN services s ON j.service_id = s.service_id
                     LEFT JOIN mechanics m ON j.mechanic_id = m.mechanic_id
                     WHERE j.tenant_id = ? AND j.status != 'CANCELLED'
                     ORDER BY j.created_at DESC LIMIT 5");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      // Default response if no specific action matched
      echo json_encode(['status' => 'error', 'message' => 'Action ' . $_GET['action'] . ' not handled.']);
      exit;

    } catch (Exception $ax) {
      if (ob_get_level())
        ob_end_clean();
      header('Content-Type: application/json');
      echo json_encode(['status' => 'error', 'message' => $ax->getMessage()]);
      exit;
    }
  }



  try {
    if ($role === 'MECHANIC' && $my_mechanic_id) {
      $stmt = $db->prepare("SELECT COUNT(*) FROM repair_jobs WHERE tenant_id = ? AND mechanic_id = ? AND status = 'PENDING'");
      $stmt->execute([$tenant_id, $my_mechanic_id]);
    } else {
      $stmt = $db->prepare("SELECT COUNT(*) FROM repair_jobs WHERE tenant_id = ? AND status = 'PENDING'");
      $stmt->execute([$tenant_id]);
    }
    $pending_jobs_count = $stmt->fetchColumn();
  } catch (Exception $e) {
  }

  try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE()");
    $stmt->execute([$tenant_id]);
    $appointments_today = $stmt->fetchColumn();
  } catch (Exception $e) {
  }

  try {
    $stmt = $db->prepare("SELECT SUM(amount) FROM payments WHERE tenant_id = ? AND status IN ('SUCCESS', 'COMPLETED') AND DATE(payment_date) = CURDATE()");
    $stmt->execute([$tenant_id]);
    $today_revenue = $stmt->fetchColumn() ?? 0;
  } catch (Exception $e) {
  }

  try {
    if ($role === 'MECHANIC' && $my_mechanic_id) {
      $stmt = $db->prepare("SELECT j.job_id, v.plate_no, v.make, v.model, j.status, j.mechanic_id, j.bay_id, j.started_at FROM repair_jobs j LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id WHERE j.tenant_id = ? AND j.mechanic_id = ? AND j.status IN ('PENDING', 'IN_PROGRESS', 'WAITING_FOR_PARTS', 'COMPLETED') LIMIT 15");
      $stmt->execute([$tenant_id, $my_mechanic_id]);
    } else {
      $stmt = $db->prepare("SELECT j.job_id, v.plate_no, v.make, v.model, j.status, j.mechanic_id, j.bay_id, j.started_at FROM repair_jobs j LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id WHERE j.tenant_id = ? AND j.status IN ('PENDING', 'IN_PROGRESS', 'WAITING_FOR_PARTS', 'COMPLETED') LIMIT 15");
      $stmt->execute([$tenant_id]);
    }
    $active_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
  }

} catch (Exception $outer_e) {
  if (isset($_GET['action'])) {
    if (ob_get_length())
      ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $outer_e->getMessage()]);
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tenant Dashboard |
    <?php echo htmlspecialchars($shop_name); ?>
  </title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --bg-deep:
        <?php echo $tenant_custom['secondary_color'] ?: '#030712'; ?>
        !important;
      --accent:
        <?php echo $tenant_custom['primary_color'] ?: '#10b981'; ?>
        !important;
      --accent-rgb:
        <?php
        $hex = ($tenant_custom['primary_color'] ?? '') ?: '#10b981';
        $rgb = sscanf($hex, "#%02x%02x%02x");
        if ($rgb) {
          list($r, $g, $b) = $rgb;
        } else {
          $r = 16;
          $g = 185;
          $b = 129;
        }
        echo "$r, $g, $b";
        ?>
        !important;
      --accent-glow: rgba(var(--accent-rgb), 0.4);
      --radius:
        <?php echo $tenant_custom['border_radius'] ?: '24px'; ?>
      ;
      --glass:
        <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.03)'; ?>
      ;
      --glass-border:
        <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.08)'; ?>
      ;
      --glass-blur:
        <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'none' : 'blur(20px)'; ?>
      ;
      --text-main: #f8fafc;
      --text-dim: #94a3b8;
      --danger: #ef4444;
      --warning: #f59e0b;
    }

    /* Custom Scrollbar for Premium Feel */
    ::-webkit-scrollbar {
      width: 8px;
    }

    ::-webkit-scrollbar-track {
      background: var(--bg-deep);
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      border: 2px solid var(--bg-deep);
    }

    ::-webkit-scrollbar-thumb:hover {
      background: var(--accent);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Outfit', sans-serif;
    }

    /* Sleek Modern Scrollbar */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      transition: 0.3s;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: var(--accent);
    }

    body {
      background-color: var(--bg-deep);
      color: var(--text-main);
      display: flex;
      width: 100vw;
      height: 100vh;
      overflow: hidden;
      background-image: radial-gradient(circle at top right, var(--accent-glow), transparent 40%);
    }

    /* Modern input styling for all textareas/inputs */
    .modern-input {
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      padding: 1rem 1.25rem;
      color: #fff !important;
      font-size: 0.95rem;
      outline: none;
      transition: 0.3s;
    }

    .modern-input:focus {
      border-color: var(--accent);
      background: rgba(255, 255, 255, 0.08);
      box-shadow: 0 0 15px var(--accent-glow);
    }

    ::placeholder {
      color: rgba(255, 255, 255, 0.3) !important;
      opacity: 1;
    }

    /* Premium Dropdown Styling */
    select {
      -webkit-appearance: none !important;
      -moz-appearance: none !important;
      appearance: none !important;
      width: 100% !important;
      background-color: rgba(255, 255, 255, 0.05) !important;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
      background-repeat: no-repeat !important;
      background-position: right 1.2rem center !important;
      background-size: 1.1rem !important;
      border: 1px solid var(--glass-border) !important;
      border-radius: 14px !important;
      padding: 0.9rem 3rem 0.9rem 1.2rem !important;
      color: white !important;
      font-size: 0.95rem !important;
      font-weight: 600 !important;
      outline: none !important;
      cursor: pointer !important;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    select:focus {
      border-color: var(--accent) !important;
      background-color: rgba(255, 255, 255, 0.08) !important;
      box-shadow: 0 0 15px var(--accent-glow) !important;
    }

    select option {
      background-color: #0f172a !important;
      color: white !important;
    }

    /* Inline Save Button Styling */
    .feature-save-btn {
      background: var(--accent);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 6px 12px;
      font-size: 0.75rem;
      font-weight: 700;
      cursor: pointer;
      transition: 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-top: 8px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
    }

    .feature-save-btn:hover {
      transform: translateY(-2px);
      filter: brightness(1.1);
    }

    .feature-save-btn:active {
      transform: translateY(0);
    }

    .feature-save-btn.saving {
      opacity: 0.7;
      pointer-events: none;
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
      transform: translateX(-50%) translateY(5px);
    }

    .bookmark-tab {
      background: linear-gradient(180deg, var(--accent), var(--accent-glow));
      color: white;
      padding: 12px 24px 20px;
      border-radius: 0 0 20px 20px;
      font-weight: 800;
      font-size: 0.75rem;
      letter-spacing: 1px;
      box-shadow: 0 10px 30px var(--accent-glow);
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-top: none;
    }

    /* PREMIUM GRADIENT BUTTONS */
    .btn-gradient {
      background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
      background-size: 200% auto;
      color: white !important;
      border: none;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      font-size: 0.85rem;
      position: relative;
      overflow: hidden;
    }

    .btn-gradient:hover {
      transform: translateY(-3px) scale(1.02);
      box-shadow: 0 20px 40px var(--accent-glow);
      background-position: right center;
    }

    .btn-gradient:active {
      transform: translateY(0) scale(0.98);
    }

    .btn-gradient i {
      font-size: 1rem;
      transition: 0.3s;
    }

    .btn-gradient:hover i {
      transform: rotate(15deg) scale(1.2);
    }

    .btn-gradient::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: 0.5s;
    }

    .btn-gradient:hover::before {
      left: 100%;
    }

    .bookmark-tab span {
      font-size: 0.8rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      display: none;
    }

    .announcement-puller:hover .bookmark-tab span {
      display: block;
    }

    .ann-badge {
      width: 14px;
      height: 14px;
      background: #ff3333;
      border-radius: 50%;
      position: absolute;
      top: 6px;
      right: 6px;
      box-shadow: 0 0 15px rgba(255, 51, 51, 0.8);
      display: none;
      border: 2px solid white;
      z-index: 10;
    }

    .ann-badge.active {
      display: block;
      animation: pulse-badge-vibrant 1.2s infinite;
    }

    @keyframes pulse-badge-vibrant {
      0% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 51, 51, 0.7);
      }

      70% {
        transform: scale(1.3);
        box-shadow: 0 0 0 10px rgba(255, 51, 51, 0);
      }

      100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 51, 51, 0);
      }
    }

    .announcement-panel {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%) scale(0.9);
      width: 95%;
      max-width: 550px;
      background: #0f172a;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 28px;
      padding: 40px;
      z-index: 2147483647;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
      display: none;
      flex-direction: column;
      opacity: 0;
      visibility: hidden;
      transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .announcement-panel.active {
      display: flex;
      opacity: 1;
      visibility: visible;
      transform: translate(-50%, -50%) scale(1);
    }

    .announcement-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(8px);
      z-index: 2147483646;
      display: none;
      opacity: 0;
      transition: 0.4s;
    }

    .announcement-overlay.active {
      display: block;
      opacity: 1;
      pointer-events: auto;
    }

    /* Sidebar */
    .sidebar {
      width: 280px;
      min-width: 280px;
      background: rgba(15, 23, 42, 0.98);
      backdrop-filter: var(--glass-blur);
      border-right: 1px solid var(--glass-border);
      display: flex;
      flex-direction: column;
      padding: 2rem 0;
      position: fixed;
      left: 0;
      top: 0;
      height: 100vh;
      z-index: 5000;
      pointer-events: auto;
    }

    .main-content {
      flex: 1;
      margin-left: 280px;
      overflow-y: auto;
      padding: 3rem 4rem;
      position: relative;
      min-height: 100vh;
      z-index: 1;
    }

    .brand {
      padding: 0 2rem 2rem;
      font-size: 1.5rem;
      font-weight: 800;
      color: white;
      border-bottom: 1px solid var(--glass-border);
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .brand-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      background: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      overflow: hidden;
      box-shadow: 0 0 20px var(--accent-glow);
    }

    .brand-icon img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .nav-scroll {
      flex: 1;
      overflow-y: auto;
    }

    .nav-scroll::-webkit-scrollbar {
      width: 5px;
    }

    .nav-scroll::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
    }

    .nav-item {
      padding: 1rem 2rem;
      color: var(--text-dim);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 15px;
      font-weight: 500;
      transition: 0.3s;
      cursor: pointer !important;
      border-left: 3px solid transparent;
      pointer-events: auto !important;
      position: relative;
      z-index: 10;
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, 0.02);
      color: white;
    }

    .nav-item.active {
      background: linear-gradient(90deg, var(--accent-glow) 0%, transparent 100%);
      color: var(--accent);
      border-left-color: var(--accent);
      font-weight: 700;
    }

    .nav-item-link {
      padding: 1rem 2rem;
      color: var(--text-dim);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 15px;
      font-weight: 500;
      transition: 0.3s;
      border-left: 3px solid transparent;
    }

    .nav-item-link:hover {
      background: rgba(255, 255, 255, 0.02);
      color: var(--accent);
    }


    .nav-group-title {
      padding: 0 2rem;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: var(--text-dim);
      margin: 1.5rem 0 0.5rem;
      font-weight: 700;
    }

    /* Main Content */
    /* Sections removed float/flex conflicts */

    /* Header */
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2.5rem;
      position: relative;
      z-index: 1000 !important;
    }

    .header-bg {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 300px;
      background: linear-gradient(180deg, var(--accent-glow) 0%, transparent 100%);
      z-index: -1;
      pointer-events: none;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 15px;
      background: var(--glass);
      padding: 8px 20px 8px 8px;
      border-radius: 100px;
      border: 1px solid var(--glass-border);
    }

    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 800;
      font-size: 1.2rem;
    }

    /* Toast Notifications */
    .toast-container {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      z-index: 6000;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .toast-box {
      background: rgba(15, 23, 42, 0.95);
      backdrop-filter: blur(10px);
      border: 1px solid var(--glass-border);
      padding: 1rem 1.5rem;
      border-radius: 16px;
      color: white;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      animation: toastSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      min-width: 250px;
      border-left: 4px solid var(--accent);
    }

    @keyframes toastSlideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    .notif-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background: var(--danger);
      color: white;
      font-size: 0.6rem;
      min-width: 18px;
      height: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      border: 2px solid rgba(15, 23, 42, 1);
      font-weight: 800;
      box-shadow: 0 0 10px rgba(239, 68, 68, 0.4);
    }

    .nav-notif-btn {
      position: relative;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--glass-border);
      width: 45px;
      height: 45px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: 0.3s;
      color: var(--text-dim);
    }

    .nav-notif-btn:hover {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      transform: scale(1.05);
    }

    /* View Sections */
    .view-section {
      display: none;
      animation: fadeIn 0.4s ease;
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

    /* Stats Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2.5rem;
    }

    .stat-card {
      background: var(--glass);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius);
      padding: 1.5rem;
      transition: 0.3s;
    }

    .stat-card:hover {
      transform: translateY(-3px);
      border-color: var(--accent-glow);
      background: rgba(255, 255, 255, 0.05);
    }

    .stat-label {
      color: var(--text-dim);
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .stat-value {
      font-size: 2rem;
      font-weight: 800;
      color: white;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
    }

    /* UI Components */
    .glass-panel {
      background: var(--glass);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius);
      padding: 2rem;
      margin-bottom: 2rem;
      backdrop-filter: var(--glass-blur);
    }

    .btn-action {
      background: var(--accent);
      color: #000;
      border: none;
      padding: 0.8rem 1.5rem;
      border-radius: calc(var(--radius) * 0.5);
      font-weight: 700;
      cursor: pointer;
      transition: 0.3s;
      box-shadow: 0 4px 15px var(--accent-glow);
    }

    .btn-action:hover {
      background: #059669;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px var(--accent-glow);
    }

    .btn-outline {
      background: transparent;
      color: var(--text-main);
      border: 1px solid var(--glass-border);
      padding: 0.8rem 1.5rem;
      border-radius: calc(var(--radius) * 0.5);
      font-weight: 700;
      cursor: pointer;
      transition: 0.3s;
    }

    .btn-outline:hover {
      background: rgba(255, 255, 255, 0.05);
      border-color: rgba(255, 255, 255, 0.2);
    }

    /* Tables */
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table th,
    .data-table td {
      padding: 1.2rem 1rem;
      text-align: left;
      border-bottom: 1px solid var(--glass-border);
    }

    .data-table th {
      color: var(--text-dim);
      font-weight: 600;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .data-table tr:hover td {
      background: rgba(255, 255, 255, 0.02);
    }

    .badge {
      padding: 5px 12px;
      border-radius: 100px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
    }

    .badge-active {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .badge-pending {
      background: rgba(245, 158, 11, 0.1);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .badge-warning {
      background: rgba(255, 193, 7, 0.15);
      color: #ffc107;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .badge-danger {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .badge-info {
      background: rgba(6, 182, 212, 0.15);
      color: #06b6d4;
      border: 1px solid rgba(6, 182, 212, 0.3);
    }

    .search-input {
      width: 100%;
      max-width: 400px;
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid var(--glass-border);
      padding: 0.9rem 1.25rem;
      border-radius: 15px;
      color: white;
      outline: none;
      transition: 0.3s;
      margin-bottom: 1.5rem;
    }

    .search-input:focus {
      border-color: var(--accent);
      background: rgba(0, 0, 0, 0.4);
    }


    .form-group {
      margin-bottom: 1.2rem;
    }

    .form-group label {
      display: block;
      color: var(--text-dim);
      font-size: 0.9rem;
      margin-bottom: 0.5rem;
    }

    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="password"],
    .form-group input[type="number"],
    .form-group input[type="color"],
    .form-group textarea {
      width: 100%;
      padding: 0.8rem 1rem;
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid var(--glass-border);
      border-radius: calc(var(--radius) * 0.4);
      color: var(--text-main);
      font-size: 1rem;
      transition: border-color 0.3s;
    }

    .form-group input[type="color"] {
      height: 45px;
      padding: 5px;
    }

    .form-group input:focus,
    .form-group textarea:focus {
      border-color: var(--accent);
      outline: none;
    }

    .form-group textarea {
      resize: vertical;
      min-height: 80px;
    }

    /* Vehicle Grouping Styles */
    .vehicle-group-header {
      background: rgba(var(--accent-rgb), 0.05) !important;
      cursor: pointer;
      transition: 0.2s;
    }

    .vehicle-group-header:hover {
      background: rgba(var(--accent-rgb), 0.1) !important;
    }

    .vehicle-row {
      background: rgba(255, 255, 255, 0.02);
      display: none;
      /* Hidden by default */
    }

    .vehicle-row td {
      border-bottom: 1px solid var(--glass-border);
      padding-left: 2rem !important;
    }

    .chevron-icon {
      transition: transform 0.3s ease;
      color: var(--text-dim);
      margin-right: 10px;
    }

    .expanded .chevron-icon {
      transform: rotate(90deg);
      color: var(--accent);
    }

    /* NEW PREMIUM HOVER EFFECTS */
    .bay-card:hover {
      transform: translateY(-8px);
      border-color: rgba(var(--accent-rgb), 0.4) !important;
      box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4) !important;
    }

    .bay-assign-btn {
      position: relative;
      z-index: 1;
      isolation: isolate;
    }

    .bay-assign-btn:hover {
      border-color: var(--accent) !important;
      box-shadow: 0 10px 20px var(--accent-glow);
      color: white !important;
    }

    /* Modal System */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.85);
      z-index: 99999;
      overflow-y: auto;
    }

    .modal-card {
      background: #111827;
      border: 2px solid #374151;
      border-radius: 15px;
      max-width: 900px;
      width: 95%;
      margin: 5vh auto;
      padding: 30px;
      position: relative;
      z-index: 100000;
    }

    .btn-close-modal {
      position: absolute;
      top: 15px;
      right: 15px;
      background: #1f2937;
      border: 1px solid #374151;
      color: white;
      width: 35px;
      height: 35px;
      border-radius: 5px;
      cursor: pointer;
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .btn-close-modal:hover {
      background: var(--danger);
      color: white;
      border-color: var(--danger);
    }

    .modal-body::-webkit-scrollbar {
      width: 6px;
    }

    .modal-body::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
    }

    @keyframes modalPop {
      from {
        opacity: 0;
        transform: scale(0.9) translateY(20px);
      }

      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    /* Ensure modal is above everything */
    #upgradeModal {
      z-index: 99999999 !important;
    }

    .bay-assign-btn:hover .hover-overlay {
      opacity: 0.15 !important;
    }

    .bay-assign-btn:active {
      transform: scale(0.97);
    }
  </style>
</head>

<body style="overflow-x:hidden;">
  <?php
  if ($role === 'OWNER'):
    // 1. TRY TO FETCH REAL DATA FROM DB FIRST
    if (!isset($all_plans) || empty($all_plans)) {
      try {
        $stmt = $db->query("SELECT * FROM subscription_plans WHERE status = 'ACTIVE' ORDER BY price ASC");
        $all_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Exception $e) {
        $all_plans = [];
      }
    }

    if (!isset($active_subscription)) {
      try {
        $stmt = $db->prepare("SELECT s.*, p.plan_name FROM tenant_subscriptions s JOIN subscription_plans p ON s.plan_id = p.plan_id WHERE s.tenant_id = ? AND s.status = 'ACTIVE' ORDER BY s.subscription_id DESC LIMIT 1");
        $stmt->execute([$tenant_id]);
        $active_subscription = $stmt->fetch(PDO::FETCH_ASSOC);
      } catch (Exception $e) {
        $active_subscription = null;
      }
    }

    // 2. USE FALLBACKS ONLY IF DB IS EMPTY (Fail-safe)
    if (empty($all_plans)) {
      $all_plans = [
        ['plan_id' => 1, 'plan_name' => 'BASIC', 'price' => 0, 'max_users' => 3, 'max_service_bays' => 2],
        ['plan_id' => 2, 'plan_name' => 'PRO', 'price' => 1499, 'max_users' => 10, 'max_service_bays' => 5],
        ['plan_id' => 3, 'plan_name' => 'ENTERPRISE', 'price' => 3999, 'max_users' => 50, 'max_service_bays' => 20]
      ];
    }
    if (!$active_subscription) {
      $active_subscription = ['plan_id' => 2, 'plan_name' => 'PRO', 'status' => 'ACTIVE'];
    }
    ?>
    <div id="upgradeModal" class="modal-overlay"
      style="display: none; z-index: 2147483646 !important; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.9); align-items: center; justify-content: center;"
      onclick="if(event.target === this) this.style.display='none'">
      <div class="modal-card"
        style="z-index: 2147483647; max-width: 1000px; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); padding: 40px; border-radius: 24px; position: relative; width: 95%;">
        <button type="button" onclick="document.getElementById('upgradeModal').style.display='none'"
          style="position: absolute; top: 20px; right: 20px; background: none; border: none; color: #64748b; font-size: 2rem; cursor: pointer;">&times;</button>

        <div style="text-align:center; margin-bottom: 40px;">
          <h2 style="font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: 15px;">Scale Your Operations
          </h2>
          <div
            style="display:inline-flex; background:rgba(255,255,255,0.05); padding:6px; border-radius:100px; margin-top:10px;">
            <?php $user_cycle = strtolower($active_subscription['billing_cycle'] ?? 'monthly'); ?>
            <button onclick="window.toggleBillingCycle(false)" id="toggleMonthly"
              class="<?php echo ($user_cycle === 'monthly') ? 'active' : ''; ?>"
              style="padding:10px 25px; border-radius:100px; border:none; cursor:pointer; font-weight:700; background:transparent; color:#94a3b8;">Monthly</button>
            <button onclick="window.toggleBillingCycle(true)" id="toggleYearly"
              class="<?php echo ($user_cycle === 'yearly') ? 'active' : ''; ?>"
              style="padding:10px 25px; border-radius:100px; border:none; cursor:pointer; font-weight:700; background:transparent; color:#94a3b8;">Yearly
              <span
                style="background:#10b981; color:white; font-size:0.65rem; padding:2px 8px; border-radius:10px;">-20%</span></button>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px;">
          <?php
          if (!empty($all_plans)):
            foreach ($all_plans as $p):
              $is_plan_match = ($active_subscription && $active_subscription['plan_id'] == $p['plan_id']);
              $user_cycle = strtolower($active_subscription['billing_cycle'] ?? 'monthly');
              // Initially, we'll show based on the user's current cycle
              $is_current = ($is_plan_match && $user_cycle === 'monthly');
              // Wait, the toggle defaults to the user's cycle if we run the JS. 
              // Let's just set the PHP to a baseline and let JS fix it immediately.
              $monthly = $p['price'];
              $yearly = $p['price_yearly'] > 0 ? $p['price_yearly'] : ($monthly * 12 * 0.8);
              ?>
              <div class="plan-card" data-plan-id="<?php echo $p['plan_id']; ?>"
                data-is-match="<?php echo $is_plan_match ? 'true' : 'false'; ?>"
                style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255,255,255,0.05); padding: 35px; border-radius: 24px; text-align: center; position: relative;">
                <div class="active-badge"
                  style="display:none; position:absolute; top:15px; right:15px; background:var(--accent); color:white; padding:5px 12px; border-radius:100px; font-size:0.7rem; font-weight:900;">
                  ACTIVE</div>
                <h3 style="font-size:1.6rem; color:#fff; margin-bottom:10px;">
                  <?php echo htmlspecialchars($p['plan_name']); ?>
                </h3>
                <div style="margin-bottom:25px;">
                  <span class="plan-price-val" data-monthly="<?php echo $monthly; ?>" data-yearly="<?php echo $yearly; ?>"
                    style="font-size:2.8rem; font-weight:900; color:#fff;">₱
                    <?php echo number_format($monthly); ?>
                  </span>
                  <span class="plan-cycle-label" style="font-size:1rem; color:#64748b;">/mo</span>
                </div>
                <button class="upgrade-select-btn btn-action"
                  style="width:100%; padding:15px; border-radius:15px; font-weight:800;" type="button"
                  onclick="processUpgrade(<?php echo $p['plan_id']; ?>, '<?php echo addslashes($p['plan_name']); ?>', event)">
                  Select Plan
                </button>
              </div>
            <?php endforeach; else: ?>
            <div style="color:white; text-align:center; grid-column: 1 / -1; padding: 2rem;">No active plans
              available at the moment.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <script>
      window.openUpgradeModal = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        const modal = document.getElementById('upgradeModal');
        if (modal) {
          modal.style.setProperty('display', 'flex', 'important');
          modal.style.setProperty('z-index', '2147483647', 'important');
          modal.style.setProperty('visibility', 'visible', 'important');
          modal.style.setProperty('opacity', '1', 'important');
          window.toggleBillingCycle(false); // ALWAYS DEFAULT TO MONTHLY
        }
      };
      window.toggleBillingCycle = function (y) {
        const mBtn = document.getElementById('toggleMonthly');
        const yBtn = document.getElementById('toggleYearly');
        if (mBtn) {
          mBtn.style.background = !y ? '#6366f1' : 'rgba(255,255,255,0.05)';
          mBtn.style.color = !y ? 'white' : '#94a3b8';
          if (!y) mBtn.classList.add('active'); else mBtn.classList.remove('active');
        }
        if (yBtn) {
          yBtn.style.background = y ? '#6366f1' : 'rgba(255,255,255,0.05)';
          yBtn.style.color = y ? 'white' : '#94a3b8';
          if (y) yBtn.classList.add('active'); else yBtn.classList.remove('active');
        }
        document.querySelectorAll('.plan-price-val').forEach(el => {
          const v = y ? el.dataset.yearly : el.dataset.monthly;
          el.innerText = '₱' + parseFloat(v).toLocaleString();
        });
        document.querySelectorAll('.plan-cycle-label').forEach(el => el.innerText = y ? '/yr' : '/mo');
      };
    </script>
    <style>
      #toggleMonthly.active,
      #toggleYearly.active {
        background: var(--accent) !important;
        color: white !important;
      }
    </style>
  <?php endif; ?>
  <script>
    const sectionTitles = {
      'dashboard': { title: '<?php echo ucwords(strtolower($role)); ?> Dashboard', sub: 'Overview & Real-time Stats' },
      'appointments': { title: 'Service Appointments', sub: 'Manage bookings and schedules' },
      'job_orders': { title: 'Job Orders & Repairs', sub: 'Track ongoing workshop tasks' },
      'customers': { title: 'Customer Database', sub: 'Relationship management and history' },
      'vehicles': { title: 'Vehicle Registry', sub: 'Manage fleet and customer cars' },
      'payments': { title: 'Billing & Invoices', sub: 'Financial transactions and records' },
      'inventory': { title: 'Parts & Inventory', sub: 'Stock levels and supply chain' },
      'staff': { title: 'Staff Management', sub: 'Human resources and access roles' },
      'reports': { title: 'Business Analytics', sub: 'Performance metrics and growth' },
      'customization': { title: 'Shop Customization', sub: 'Brand identity and UI settings' },
      'subscription': { title: 'My Subscription', sub: 'Plan details and billing' },
      'my_profile': { title: 'Account Settings', sub: 'Personal profile and presence' }
    };

    window.navToView = function (viewId) {
      console.log("[NAV] Requesting View: " + viewId);
      if (window.closeUpgradeModal) window.closeUpgradeModal();

      // 1. Switch Active Section
      const sections = document.querySelectorAll('.view-section');
      sections.forEach(s => {
        s.classList.remove('active');
        s.style.display = 'none';
      });

      const target = document.getElementById(viewId);
      if (target) {
        target.classList.add('active');
        target.style.display = 'block';

        // Standard View for all views
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        if (sidebar) sidebar.style.display = 'flex';
        if (mainContent) {
          mainContent.style.marginLeft = '280px';
          mainContent.style.padding = '3rem 4rem';
        }

        // Update Page Title
        const pTitle = document.getElementById('pageTitle');
        const pSub = document.getElementById('pageSubtitle');
        const mainHeader = document.querySelector('header');

        if (pTitle && sectionTitles[viewId]) {
          pTitle.innerText = sectionTitles[viewId].title;
          if (pSub) pSub.innerText = sectionTitles[viewId].sub;

          // Ensure header is always visible
          if (mainHeader) mainHeader.style.display = 'flex';
        }
      }

      // 2. Update Sidebar State
      const navItems = document.querySelectorAll('.nav-item');
      navItems.forEach(n => n.classList.remove('active'));
      const activeNav = document.querySelector(`.nav-item[data-view="${viewId}"]`);
      if (activeNav) activeNav.classList.add('active');

      // 3. Trigger context-aware data refresh
      const refreshMap = {
        'dashboard': () => typeof dashboardOverviewRefresh === 'function' && dashboardOverviewRefresh(),
        'job_orders': () => typeof window.refreshJobOrders === 'function' && window.refreshJobOrders(),
        'appointments': () => typeof window.refreshAppointmentsList === 'function' && window.refreshAppointmentsList(),
        'staff': () => typeof window.refreshStaffList === 'function' && window.refreshStaffList(),
        'bays': () => typeof window.refreshBaysList === 'function' && window.refreshBaysList(),
        'inventory': () => typeof window.refreshInventoryList === 'function' && window.refreshInventoryList(),
        'payments': () => typeof window.refreshPaymentsList === 'function' && window.refreshPaymentsList(),
        'customers': () => typeof window.refreshAddCustomerList === 'function' && window.refreshAddCustomerList(),
        'vehicles': () => typeof refreshVehiclesList === 'function' && refreshVehiclesList(),
        'customer_logs': () => typeof loadAuditLogs === 'function' && loadAuditLogs(),
        'payments_history': () => typeof loadBillingHistory === 'function' && loadBillingHistory()
      };
      if (refreshMap[viewId]) refreshMap[viewId]();
    };



    // renewSubscription moved to bottom for better reliability


    // Removed launchUpgradeWizard from here to move it to the bottom for better reliability

    // Moved to top for reliability

    // Cleaned up old listeners

    window.closeUpgradeModal = function () {
      console.log('[SUBS] closeUpgradeModal called');
      const m = document.getElementById('upgradeModal');
      if (m) {
        m.style.display = 'none';
      }
    };

    window.processUpgrade = function (planId, planName, e) {
      const btn = e && e.currentTarget ? e.currentTarget : (event && event.currentTarget ? event.currentTarget : null);
      const originalHtml = btn ? btn.innerHTML : 'Select Plan';
      const isYearly = document.getElementById('toggleYearly').classList.contains('active');
      const cycleText = isYearly ? 'Yearly' : 'Monthly';

      // Extract price from the plan card in the DOM
      const card = btn ? btn.closest('.plan-card') : null;
      let price = 0;
      if (card) {
        const priceEl = card.querySelector('.plan-price-val');
        price = isYearly ? priceEl.getAttribute('data-yearly') : priceEl.getAttribute('data-monthly');
      }

      const confirmMsg = "Confirm your switch to the " + planName + " plan (" + cycleText + "). Please select your payment method below.";

      const executeUpgrade = (method) => {
        showPayMongoSimulation(price, method, planName + " (" + cycleText + ")", () => {
          if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
          }

          const fd = new FormData();
          fd.append('plan_id', planId);
          fd.append('billing_cycle', isYearly ? 'yearly' : 'monthly');
          fd.append('method', method);

          fetch('tenant-dashboard.php?action=upgrade_plan', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data.status === 'success') {
                alert("\u2705 " + data.message + "\nReference: " + data.ref);
                window.closeUpgradeModal();
                setTimeout(() => location.reload(), 1000);
              } else {
                alert("\u274C Error: " + data.message);
                if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
              }
            })
            .catch(() => {
              alert("\u274C Connection error. Please try again.");
              if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
            });
        });
      };

      // DYNAMIC MODAL (For Upgrade Payment Selection)
      const modalId = 'upgradePayModal_' + Date.now();
      const modalHTML = `
        <div id="${modalId}" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); backdrop-filter:blur(15px); z-index:2147483648; display:flex; align-items:center; justify-content:center; padding:20px;">
          <div style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:32px; padding:3rem; width:100%; max-width:480px; text-align:center; box-shadow:0 30px 60px rgba(0,0,0,0.8);">
            <div style="width:80px; height:80px; background:linear-gradient(135deg, #f97316 0%, #ef4444 100%); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 2rem; color:white; font-size:2.5rem; box-shadow:0 10px 25px rgba(249, 115, 22, 0.4);">
              <i class="fas fa-rocket"></i>
            </div>
            <h2 style="color:white; margin-bottom:0.8rem; font-size:1.8rem; font-weight:800;">Confirm Upgrade</h2>
            <p style="color:#94a3b8; margin-bottom:2rem; line-height:1.6;">${confirmMsg}</p>
            
            <div style="text-align:left; margin-bottom:2.5rem;">
              <label style="color:white; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; display:block; opacity:0.7;">Payment Method</label>
              <select id="upgMethod_${modalId}">
                <option value="GCASH" style="background:#111827;">GCash</option>
                <option value="MAYA" style="background:#111827;">Maya</option>
                <option value="BANK_TRANSFER" style="background:#111827;">Bank Transfer (BDO/BPI)</option>
                <option value="CARD" style="background:#111827;">Credit/Debit Card</option>
              </select>
            </div>

            <div style="display:flex; gap:15px; justify-content:center;">
              <button id="upgConfirmBtn_${modalId}" style="flex:2; padding:16px; background:#f97316; color:white; border:none; border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem; transition:0.3s; box-shadow:0 10px 20px rgba(249, 115, 22, 0.3);">Go to Payment</button>
              <button id="upgCancelBtn_${modalId}" style="flex:1; padding:16px; background:rgba(255,255,255,0.05); color:white; border:1px solid rgba(255,255,255,0.1); border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem;">Cancel</button>
            </div>
          </div>
        </div>`;
      document.body.insertAdjacentHTML('beforeend', modalHTML);

      document.getElementById('upgConfirmBtn_' + modalId).onclick = function () {
        const selectedMethod = document.getElementById('upgMethod_' + modalId).value;
        document.getElementById(modalId).remove();
        executeUpgrade(selectedMethod);
      };
      document.getElementById('upgCancelBtn_' + modalId).onclick = function () {
        document.getElementById(modalId).remove();
      };
    };
    window.loadBillingHistory = function () {
      const body = document.getElementById('billingHistoryTableBody');
      if (!body) return;
      body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:2rem;"><div class="spinner"></div></td></tr>';

      fetch('tenant-dashboard.php?action=fetch_billing_history')
        .then(res => res.json())
        .then(data => {
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem; opacity:0.5;">No billing history found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(p => `
            <tr>
              <td>${p.payment_date}</td>
              <td><code style="background:rgba(255,255,255,0.05); padding:2px 6px; border-radius:4px;">${p.transaction_reference}</code></td>
              <td>₱${parseFloat(p.amount).toLocaleString()}</td>
              <td><span class="badge" style="background:rgba(16,185,129,0.1); color:var(--success); border:1px solid rgba(16,185,129,0.2);">${p.payment_status}</span></td>
            </tr>
          `).join('');
        });
    };

    window.loadAuditLogs = function () {
      console.log("[AUDIT] Initiative started...");
      const body = document.getElementById('auditLogsTableBody');
      if (!body) {
        console.error("[AUDIT] Target element 'auditLogsTableBody' not found.");
        return;
      }
      body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem;"><div class="spinner" style="margin:0 auto 1rem;"></div><p>Synchronizing audit records...</p></td></tr>';

      fetch('tenant-dashboard.php?action=fetch_audit_logs&_t=' + Date.now())
        .then(res => res.json())
        .then(data => {
          console.log("[AUDIT] Data received:", data.length, "records");
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:5rem; opacity:0.5;"><i class="fas fa-fingerprint" style="font-size:3rem; margin-bottom:1.5rem; color:var(--accent);"></i><br>No system activities logged yet.</td></tr>';
            return;
          }
          body.innerHTML = data.map(l => {
            let actor = l.actor_name || 'System Agent';
            let badgeClass = 'badge-active';
            let icon = 'fa-bolt';

            if (l.activity_type === 'AUTH') { badgeClass = 'badge-pending'; icon = 'fa-key'; }
            if (l.activity_type === 'CRUD') { badgeClass = 'badge-active'; icon = 'fa-database'; }
            if (l.activity_type === 'SECURITY') { badgeClass = 'badge-danger'; icon = 'fa-shield-alt'; }
            if (l.activity_type === 'CUSTOMER_REG') { badgeClass = 'badge-active'; icon = 'fa-user-plus'; }
            if (l.activity_type === 'INFO') { badgeClass = 'badge-active'; icon = 'fa-info-circle'; }

            return `
              <tr class="hover-bright" style="border-bottom:1px solid rgba(255,255,255,0.03);">
                <td style="font-size:0.85rem; color:var(--text-dim); white-space:nowrap;">
                  <i class="fas fa-clock" style="margin-right:5px; opacity:0.5;"></i> ${new Date(l.created_at).toLocaleString()}
                </td>
                <td>
                  <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; font-size:0.7rem; color:var(--accent);">
                      <i class="fas fa-user-shield"></i>
                    </div>
                    <span style="font-weight:700;">${actor}</span>
                  </div>
                </td>
                <td>
                  <span class="badge ${badgeClass}" style="font-size:0.7rem;">
                    <i class="fas ${icon}" style="margin-right:4px;"></i> ${l.activity_type}
                  </span>
                </td>
                <td style="font-size:0.9rem; opacity:0.8; max-width:400px; line-height:1.4;">
                  ${l.description}
                </td>
              </tr>`;
          }).join('');
        }).catch(err => {
          console.error("[AUDIT] Fetch error:", err);
          body.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--danger); padding:3rem;">Communication failure with log server.</td></tr>';
        });
    };

    window.openModal = function (id) { const el = document.getElementById(id); if (el) el.style.display = 'flex'; };

    window.processAppointment = function (id, status) {
      // Existing process logic...
      const confirmMsg = status === 'CONFIRMED' ? "Accept this appointment and create a Job Order?" : "Are you sure you want to REJECT this appointment?";
      if (!confirm(confirmMsg)) return;

      const formData = new FormData();
      formData.append('appointment_id', id);
      formData.append('status', status);

      fetch('tenant-dashboard.php?action=update_appointment_status', {
        method: 'POST',
        body: formData
      }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
          showToast("Appointment processed successfully!");
          window.refreshAppointmentsList();
          if (status === 'CONFIRMED') window.refreshJobOrders();
        } else {
          alert("Error: " + (data.message || "Failed to update status"));
        }
      }).catch(e => alert("Network Error: Could not process request."));
    };

    window.viewPaymentDetails = function (appointmentId) {
      const body = document.getElementById('paymentProofContent');
      if (!body) return;
      body.innerHTML = '<div style="text-align:center; padding:3rem;"><div class="spinner" style="margin:0 auto 1rem;"></div><p>Retrieving payment record...</p></div>';
      window.openModal('paymentProofModal');

      fetch(`tenant-dashboard.php?action=fetch_appointment_payment&appointment_id=${appointmentId}`)
        .then(r => r.json()).then(data => {
          if (data.status === 'success' && data.payment) {
            const p = data.payment;
            body.innerHTML = `
              <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:20px; padding:2rem; text-align:center;">
                <div style="width:60px; height:60px; border-radius:50%; background:rgba(16,185,129,0.1); color:#10b981; display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; font-size:1.5rem;">
                  <i class="fas fa-check-double"></i>
                </div>
                <h3 style="margin:0; font-size:1.5rem; font-weight:800;">₱${parseFloat(p.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</h3>
                <div style="color:var(--text-dim); margin-top:5px; font-size:0.9rem;">Total Amount Received</div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:2rem; text-align:left;">
                  <div style="padding:15px; background:rgba(0,0,0,0.2); border-radius:15px; border:1px solid rgba(255,255,255,0.05);">
                    <small style="display:block; color:var(--text-dim); margin-bottom:5px; text-transform:uppercase; font-size:0.65rem; letter-spacing:1px;">Method</small>
                    <strong style="font-size:1rem; color:var(--accent);">${p.payment_method}</strong>
                  </div>
                  <div style="padding:15px; background:rgba(0,0,0,0.2); border-radius:15px; border:1px solid rgba(255,255,255,0.05);">
                    <small style="display:block; color:var(--text-dim); margin-bottom:5px; text-transform:uppercase; font-size:0.65rem; letter-spacing:1px;">Reference</small>
                    <strong style="font-size:0.9rem;">${p.reference_no || 'N/A'}</strong>
                  </div>
                </div>

                <div style="margin-top:1.5rem; padding:15px; background:rgba(255,255,255,0.02); border-radius:15px; text-align:left;">
                  <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="color:var(--text-dim); font-size:0.85rem;">Payer:</span>
                    <span style="font-weight:700;">${p.full_name || 'Customer'}</span>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-dim); font-size:0.85rem;">Date:</span>
                    <span style="font-size:0.85rem;">${new Date(p.payment_date).toLocaleString()}</span>
                  </div>
                </div>
                
                <div style="margin-top:2rem; font-size:0.75rem; color:var(--text-dim); line-height:1.4;">
                  <i class="fas fa-info-circle"></i> This payment was logged via the mobile application and is awaiting your confirmation of service.
                </div>
              </div>
            `;
          } else {
            body.innerHTML = `
              <div style="text-align:center; padding:3rem; opacity:0.6;">
                <i class="fas fa-receipt" style="font-size:3rem; margin-bottom:1rem;"></i>
                <h3>No Payment Found</h3>
                <p>There is no digital payment record linked to this appointment yet.</p>
              </div>
            `;
          }
        }).catch(e => {
          body.innerHTML = '<div style="color:var(--danger); text-align:center; padding:2rem;">Sync error. Try again.</div>';
        });
    };

    window.openMechanicProfile = function (mechanicId) {
      console.log("[SYSTEM] Priority call for Mechanic ID:", mechanicId);
      const body = document.getElementById('mechProfileContent');
      if (!body) return;
      body.innerHTML = '<div style="text-align:center; padding:3rem;"><div class="spinner" style="margin:0 auto 1rem;"></div><p>Syncing mechanic profile...</p></div>';
      window.openModal('mechanicProfileModal');

      const url = `tenant-dashboard.php?action=fetch_mechanic_profile&mechanic_id=${mechanicId}&_v=${new Date().getTime()}`;
      fetch(url).then(r => r.text()).then(text => {
        try {
          const jsonMatch = text.match(/\{[\s\S]*\}/);
          const data = JSON.parse(jsonMatch ? jsonMatch[0] : text);
          if (data.status === 'success') {
            const m = data.mechanic;
            let html = `
            <div style="display:flex; align-items:center; gap:20px; margin-bottom:2rem; padding-bottom:1rem; border-bottom:1px solid rgba(255,255,255,0.1);">
              <div style="width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; border:2px solid var(--accent); font-size:2rem; color:var(--accent);">
                <i class="fas fa-tools"></i>
              </div>
              <div>
                <h3 style="margin:0; font-size:1.4rem; letter-spacing:-0.5px;">${m.full_name}</h3>
                <div style="color:var(--text-dim); margin-top:2px;">${m.specialization || 'General Mechanic'}</div>
                <div style="margin-top:8px;">
                  <span class="badge ${m.status === 'AVAILABLE' ? 'badge-active' : 'badge-warning'}">
                    <i class="fas fa-circle" style="font-size:0.5rem; margin-right:4px;"></i> ${m.status || 'UNKNOWN'}
                  </span>
                </div>
              </div>
            </div>
            <h4 style="margin-bottom:1rem; display:flex; align-items:center; gap:8px;"><i class="fas fa-history" style="color:var(--accent)"></i> Recent Work History</h4>
            <div style="max-height:280px; overflow-y:auto; border-radius:15px; border:1px solid rgba(255,255,255,0.05); background:rgba(0,0,0,0.2);">
              <table class="data-table" style="width:100%; border:none;">
                <thead style="position:sticky; top:0; background:rgba(15,23,42,0.95); backdrop-filter:blur(10px); z-index:10;">
                  <tr>
                    <th style="font-size:0.75rem;">Timeline</th>
                    <th style="font-size:0.75rem;">Vehicle</th>
                    <th style="font-size:0.75rem;">Repair</th>
                    <th style="font-size:0.75rem;">Status</th>
                  </tr>
                </thead>
                <tbody>`;
            if (data.history && data.history.length > 0) {
              data.history.forEach(h => {
                const bdgStyle = h.status === 'COMPLETED' ? 'badge-active' : (h.status === 'IN_PROGRESS' ? 'badge-warning' : 'badge-pending');
                html += `
                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                  <td style="font-size:0.8rem; color:var(--text-dim);">${new Date(h.created_at).toLocaleDateString()}</td>
                  <td style="font-size:0.85rem; font-weight:700;">${h.plate_no}<br><span style="font-weight:400; font-size:0.75rem; color:var(--text-dim);">${h.make || ''}</span></td>
                  <td style="font-size:0.8rem;">${h.service_name || 'N/A'}</td>
                  <td><span class="badge ${bdgStyle}" style="font-size:0.65rem;">${h.status}</span></td>
                </tr>`;
              });
            } else {
              html += `<tr><td colspan="4" style="text-align:center; padding:3rem; color:var(--text-dim);">No recent assignments documented.</td></tr>`;
            }
            html += `</tbody></table></div>`;
            body.innerHTML = html;
          } else {
            body.innerHTML = `<div style="color:var(--danger); padding:2rem; text-align:center;">${data.message || "Account fetch failed"}</div>`;
          }
        } catch (e) {
          console.error("[PROFILE] Parsing error:", e);
          body.innerHTML = '<div style="color:var(--danger); padding:2rem; text-align:center;">Data parsing error.</div>';
        }
      }).catch(err => {
        console.error("[PROFILE] Fetch error:", err);
        body.innerHTML = '<div style="color:var(--danger); text-align:center; padding:2rem;">Connection error.</div>';
      });
    };

    window.startEditCustomer = function (id) {
      console.log("[SYSTEM] Fetching data for editing Customer ID:", id);
      const idF = document.getElementById('edit_customer_id');
      if (idF) idF.value = id;

      fetch(`tenant-dashboard.php?action=fetch_customer_details&customer_id=${id}`)
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success' && data.customer) {
            const c = data.customer;
            if (document.getElementById('edit_customer_name')) document.getElementById('edit_customer_name').value = c.full_name;
            if (document.getElementById('edit_customer_email')) document.getElementById('edit_customer_email').value = c.email || '';
            if (document.getElementById('edit_customer_mobile')) document.getElementById('edit_customer_mobile').value = c.mobile;
            window.openModal('editCustomerModal');
          } else {
            alert("API Error: " + (data.message || "Failed to load data."));
          }
        })
        .catch(err => {
          console.error("[EDIT] Sync Error:", err);
          alert("Sync Error.");
        });
    };
    window.closeModal = function (id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; };

    window.showToast = function (msg, type = 'success') {
      const container = document.getElementById('toastContainer'); if (!container) return;
      const toast = document.createElement('div'); toast.className = 'toast-box';
      toast.style.borderLeftColor = type === 'error' ? 'var(--danger)' : 'var(--accent)';
      toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}" style="color:${type === 'error' ? 'var(--danger)' : 'var(--accent)'}"></i> ${msg}`;
      container.appendChild(toast); setTimeout(() => toast.remove(), 4000);
    };

    // --- MASTER DATA SYNC ENGINE ---
    window.refreshServicesList = function () {
      const body = document.getElementById('servicesBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_services&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          if (!Array.isArray(data)) return;
          body.innerHTML = data.map(s => `<tr><td><strong>${s.service_name}</strong></td><td><small>${s.description || 'No desc'}</small></td><td>₱${parseFloat(s.price || 0).toLocaleString()}</td><td><span class="badge badge-active">ACTIVE</span></td><td><button class="btn-outline" onclick="window.editService('${s.service_id}','${(s.service_name || '').replace(/'/g, "\\'")}')">Edit</button></td></tr>`).join('');
        }).catch(e => console.error("Services load failed"));
    };

    // Consolidated customer refresh logic moved to main helper section

    window.refreshVehiclesList = function () {
      const body = document.getElementById('vehiclesBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_vehicles&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          if (!Array.isArray(data) || data.length === 0) { body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:2rem;">No vehicles registered.</td></tr>'; return; }

          const groups = {};
          data.forEach(v => {
            const owner = v.customer_name || 'Walk-in / Generic';
            if (!groups[owner]) groups[owner] = [];
            groups[owner].push(v);
          });

          let html = '';
          Object.keys(groups).forEach((owner, idx) => {
            const ownerId = 'grp-' + idx;
            const vehicles = groups[owner];
            html += `<tr class="owner-group-header" onclick="window.toggleVehicleGroup('${ownerId}')" style="cursor:pointer; background:rgba(255,255,255,0.01); border-bottom:1px solid rgba(255,255,255,0.03); transition:0.3s;">
                  <td colspan="4" style="padding:1.2rem 1.5rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                      <div style="display:flex; align-items:center; gap:15px;">
                        <div style="width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; color:var(--accent);">
                          <i class="fas fa-user-circle" style="font-size:1.2rem;"></i>
                        </div>
                        <div>
                          <strong style="font-size:1.1rem; color:#fff; display:block; letter-spacing:-0.2px;">${owner}</strong>
                          <span style="font-size:0.75rem; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px; font-weight:600;">Account Holder</span>
                        </div>
                      </div>
                      <div style="display:flex; align-items:center; gap:15px;">
                        <span class="badge" style="background:rgba(255,255,255,0.05); color:var(--accent); border:1px solid rgba(255,255,255,0.1); padding:5px 12px; font-size:0.75rem; font-weight:800; border-radius:30px;">
                          ${vehicles.length} ${vehicles.length > 1 ? 'VEHICLES' : 'VEHICLE'}
                        </span>
                        <i class="fas fa-chevron-right" id="icon-${ownerId}" style="transition:0.4s cubic-bezier(0.4, 0, 0.2, 1); color:rgba(255,255,255,0.2); font-size:0.9rem;"></i>
                      </div>
                    </div>
                  </td>
                 </tr>`;
            vehicles.forEach(v => {
              html += `<tr class="vehicle-child-${ownerId}" style="display:none; background:rgba(0,0,0,0.15); border-bottom:1px solid rgba(255,255,255,0.02);">
                    <td style="padding-left:4.5rem; padding-top:1.2rem; padding-bottom:1.2rem;">
                      <div style="display:flex; align-items:center; gap:12px;">
                        <i class="fas fa-car-side" style="color:var(--accent); opacity:0.6; font-size:0.9rem;"></i>
                        <code style="color:var(--accent); font-weight:900; font-size:1rem; letter-spacing:1px; background:rgba(16,185,129,0.05); padding:4px 10px; border-radius:8px; border:1px solid rgba(16,185,129,0.1);">${v.plate_no}</code>
                      </div>
                    </td>
                    <td><strong style="color:rgba(255,255,255,0.9); font-size:0.95rem;">${v.make || ''} ${v.model || ''}</strong></td>
                    <td><span style="font-weight:700; color:var(--text-dim);">${v.year_model || v.year || '---'}</span></td>
                    <td style="text-align:right; padding-right:1.5rem;">
                      <button class="btn-outline" style="padding:8px 20px; font-size:0.8rem; border-radius:12px; border:1px solid rgba(255,255,255,0.1); font-weight:800; text-transform:uppercase; letter-spacing:0.5px;" onclick="window.openVehicleProfile(${v.vehicle_id})">
                        <i class="fas fa-search" style="margin-right:6px; opacity:0.6;"></i> Profile
                      </button>
                    </td>
                   </tr>`;
            });
          });
          body.innerHTML = html;
        }).catch(e => { body.innerHTML = '<tr><td colspan="5">Sync Error</td></tr>'; });
    };

    window.toggleVehicleGroup = function (id) {
      const rows = document.querySelectorAll('.vehicle-child-' + id);
      const icon = document.getElementById('icon-' + id);
      rows.forEach(r => {
        if (r.style.display === 'none') {
          r.style.display = 'table-row';
          if (icon) icon.style.transform = 'rotate(90deg)';
        } else {
          r.style.display = 'none';
          if (icon) icon.style.transform = 'rotate(0deg)';
        }
      });
    };

    window.refreshPaymentsList = function () {
      const body = document.getElementById('completedPaymentsBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_payments&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          if (!Array.isArray(data) || data.length === 0) { body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;">No payments yet.</td></tr>'; return; }
          body.innerHTML = data.map(p => {
            const amt = parseFloat(p.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
            const method = (p.payment_method || 'CASH').toUpperCase();
            const ref = p.reference_no && p.reference_no !== 'Manual' ? p.reference_no : '---';
            return `<tr>
                  <td>#PY-${p.payment_id}</td>
                  <td><strong>${p.customer_name || 'Generic'}</strong></td>
                  <td>
                    <span style="font-weight:700; color:var(--text-main);">${method}</span><br>
                    <small style="color:var(--text-dim);">Ref: ${ref}</small>
                  </td>
                  <td style="color:var(--accent); font-weight:700;">₱${amt}</td>
                  <td style="font-size:0.85rem;">${p.payment_date || '---'}</td>
                  <td><span class="badge badge-active">PAID</span></td>
                </tr>`;
          }).join('');
        }).catch(e => { body.innerHTML = '<tr><td colspan="6" style="text-align:center;">Sync Error (Payments)</td></tr>'; });
    };

    window.editService = function (id, name, desc, price) {
      document.getElementById('edit_service_id').value = id;
      document.getElementById('edit_service_name').value = name;
      document.getElementById('edit_service_desc').value = desc === 'null' ? '' : desc;
      document.getElementById('edit_service_price').value = price;
      openModal('editServiceModal');
    };

    window.submitAddService = function () {
      const form = document.getElementById('addServiceForm');
      if (!form) return;

      const btn = form.querySelector('button');
      const originalText = btn.innerText;
      btn.innerText = 'Saving...';
      btn.disabled = true;

      const formData = new FormData(form);
      fetch('tenant-dashboard.php?action=add_service', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            showToast(data.message, 'success');
            closeModal('serviceModal');
            form.reset();
            if (typeof refreshServicesList === 'function') refreshServicesList();
            if (typeof dashboardOverviewRefresh === 'function') dashboardOverviewRefresh();
          } else {
            showToast(data.message, 'error');
          }
        })
        .catch(err => showToast('Network error pulse', 'error'))
        .finally(() => {
          btn.innerText = originalText;
          btn.disabled = false;
        });
    };

    window.saveEditService = function () {
      const id = document.getElementById('edit_service_id').value;
      const name = document.getElementById('edit_service_name').value;
      const desc = document.getElementById('edit_service_desc').value;
      const price = document.getElementById('edit_service_price').value;

      if (!name) return showToast('Service name is required', 'error');

      const fd = new FormData();
      fd.append('service_id', id);
      fd.append('service_name', name);
      fd.append('description', desc);
      fd.append('price', price);

      const btn = document.querySelector('#editServiceForm button[onclick*="saveEditService"]');
      const originalText = btn ? btn.innerText : 'Update Service';
      if (btn) { btn.innerText = 'Updating...'; btn.disabled = true; }

      fetch('tenant-dashboard.php?action=edit_service', { method: 'POST', body: fd })
        .then(res => res.text())
        .then(text => {
          try {
            const data = JSON.parse(text.trim());
            if (data.status === 'success') {
              showToast('Service updated successfully!', 'success');
              closeModal('editServiceModal');
              if (typeof refreshServicesList === 'function') refreshServicesList();
            } else {
              showToast(data.message || 'Error updating service', 'error');
            }
          } catch (e) {
            console.error("Edit Sync Error:", text);
            showToast('Server response error. Service might have saved, please refresh.', 'error');
          }
        })
        .catch(err => showToast('Network error updating service', 'error'))
        .finally(() => {
          if (btn) { btn.innerText = originalText; btn.disabled = false; }
        });
    };

    window.deleteService = function (id) {
      if (!confirm('Are you heavily certain you want to delete this service? This action is permanent.')) return;
      fetch('tenant-dashboard.php?action=delete_service', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `service_id=${id}`
      }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
          showToast('Service successfully deleted', 'success');
          refreshServicesList();
        } else {
          alert('Error: ' + data.message);
        }
      }).catch(e => alert('Network error while deleting service.'));
    };

    window.refreshMechanicsList = function () {
      const body = document.getElementById('mechanicsBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_mechanics').then(res => res.json()).then(data => {
        if (data.length === 0) {
          body.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-dim); padding: 2rem;">No mechanics registered yet.</td></tr>';
          return;
        }
        body.innerHTML = data.map(m => `
          <tr>
            <td><strong>${m.display_name}</strong></td>
            <td>${m.specialization}</td>
            <td><span class="badge ${m.status === 'AVAILABLE' ? 'badge-active' : 'badge-warning'}">${m.status}</span></td>
            <td><button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--accent); cursor:pointer; position:relative; z-index:10; pointer-events:auto !important;" onclick="window.openMechanicProfile(${m.mechanic_id})">View Profile</button></td>
          </tr>`).join('');
      });
    };


    window.refreshAppointmentsList = function () {
      const body = document.getElementById('appointmentsTableBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_all_appointments').then(res => res.json()).then(data => {
        if (!Array.isArray(data) || data.length === 0) {
          body.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--text-dim); padding:3rem;">No upcoming appointments found in the system.</td></tr>';
          return;
        }
        body.innerHTML = data.map(a => {
          const isPending = a.status === 'PENDING';
          const actionHtml = isPending ? `
            <div style="display:flex; flex-direction:column; gap:6px;">
              <div style="display:flex; gap:5px;">
                <button class="btn-outline" style="flex:1; padding:6px; font-size:0.7rem; border-color:#10b981; color:#10b981; background:rgba(16,185,129,0.05);" onclick="window.processAppointment(${a.appointment_id}, 'CONFIRMED')">Accept</button>
                <button class="btn-outline" style="flex:1; padding:6px; font-size:0.7rem; border-color:#ef4444; color:#ef4444; background:rgba(239,68,68,0.05);" onclick="window.processAppointment(${a.appointment_id}, 'CANCELLED')">Reject</button>
              </div>
              <button class="btn-outline" style="width:100%; padding:6px; font-size:0.7rem; border-color:var(--accent); color:var(--accent); background:rgba(255,255,255,0.02);" onclick="window.viewPaymentDetails(${a.appointment_id})">
                <i class="fas fa-receipt"></i> View Payment
              </button>
            </div>
          ` : `<div style="text-align:center;"><em style="font-size:0.75rem; color:var(--text-dim); opacity:0.6;"><i class="fas fa-check-circle"></i> ${a.status === 'CONFIRMED' ? 'Automated Tracking' : 'Archived Log'}</em></div>`;

          return `
          <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
            <td><div style="font-weight:700;">${new Date(a.appointment_date).toLocaleDateString()}</div><small style="color:var(--text-dim);">${a.appointment_time || '---'}</small></td>
            <td><strong>${a.customer_name}</strong></td>
            <td><code style="color:var(--accent);">${a.plate_no || 'N/A'}</code></td>
            <td>${a.service_name}</td>
            <td style="font-weight:700;">₱${parseFloat(a.service_price).toLocaleString()}</td>
            <td><span class="badge ${a.status === 'CONFIRMED' ? 'badge-active' : (a.status === 'CANCELLED' ? 'badge-danger' : 'badge-pending')}">${a.status}</span></td>
            <td>${actionHtml}</td>
          </tr>`;
        }).join('');
      });
    };

    window.dashboardOverviewRefresh = function () {
      fetch(`tenant-dashboard.php?action=fetch_overview_stats&_=${Date.now()}`).then(r => r.json()).then(data => {
        const pj = document.getElementById('stat-pending-jobs'), ab = document.getElementById('stat-avail-bays'), rv = document.getElementById('stat-revenue');
        if (pj) pj.innerHTML = `${data.pending_jobs} <i class="fas fa-car-crash"></i>`;
        if (ab) ab.innerHTML = `${data.avail_bays} <i class="fas fa-warehouse"></i>`;
        if (rv) rv.innerHTML = `₱${parseFloat(data.revenue).toLocaleString()} <i class="fas fa-coins"></i>`;
      });
    };

    window.refreshBaysList = function () {
      const grid = document.getElementById('baysGrid'); if (!grid) return;
      fetch('tenant-dashboard.php?action=fetch_bays')
        .then(r => r.json()).then(data => {
          grid.innerHTML = data.map(b => {
            const isAvail = b.status === 'AVAILABLE';
            const action = isAvail ? `openBayProfile(${b.bay_id})` : `openJobStatusModal(${b.active_job_id}, '${b.job_status}', ${b.active_mechanic_id}, ${b.bay_id})`;
            return `
            <div class="bay-card" style="padding:2.2rem 2rem; border-radius:28px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); position:relative; overflow:hidden;">
              <div style="position:absolute; top:-30px; right:-30px; width:120px; height:120px; background:${isAvail ? 'var(--accent)' : '#ef4444'}; opacity:0.05; filter:blur(40px);"></div>
              <span class="badge ${isAvail ? 'badge-active' : 'badge-danger'}" style="font-weight:800;">${(b.status && b.status.trim() !== '') ? b.status.toUpperCase() : 'OCCUPIED'}</span>
              <h2 style="margin:1.2rem 0 0.5rem; font-size:1.8rem; font-weight:900; letter-spacing:-1px;">${b.bay_name}</h2>
              <button class="btn-action" style="width:100%; margin-top:1.5rem; background:${isAvail ? 'var(--accent)' : 'rgba(239,68,68,0.1)'}; color:${isAvail ? 'white' : '#ef4444'}; border:1px solid ${isAvail ? 'transparent' : 'rgba(239,68,68,0.3)'}; padding:1rem; border-radius:15px; font-weight:800; cursor:pointer; transition:all 0.4s; box-shadow:${isAvail ? '0 8px 20px rgba(var(--accent-rgb), 0.2)' : 'none'}; display:flex; align-items:center; justify-content:center; gap:10px;" 
                  onmouseover="this.style.transform='translateY(-3px)'; ${isAvail ? '' : "this.style.background='#ef4444'; this.style.color='white';"}" 
                  onmouseout="this.style.transform='translateY(0)'; ${isAvail ? '' : "this.style.background='rgba(239,68,68,0.1)'; this.style.color='#ef4444';"}" 
                  onclick="${action}">
                ${isAvail ? '<i class="fas fa-eye"></i> View Bay Profile' : '<i class="fas fa-tools"></i> Repair Details'}
              </button>
            </div>`;
          }).join('');
        });
    };

    window.refreshJobOrders = function () {
      const body = document.getElementById('jobOrdersTableBody'); if (!body) return;
      console.log("[REFRESH] Active Jobs...");
      fetch('tenant-dashboard.php?action=fetch_job_orders&_v=' + Date.now())
        .then(r => r.text()).then(text => {
          try {
            const jsonMatch = text.match(/\[[\s\S]*\]/) || text.match(/\{[\s\S]*\}/);
            if (!jsonMatch) throw new Error("Invalid response format");
            const data = JSON.parse(jsonMatch[0]);

            if (!Array.isArray(data) || data.length === 0) {
              body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim);">No active job orders in the shop.</td></tr>';
              return;
            }
            body.innerHTML = data.map(j => `
              <tr>
                <td><strong>JO-${(j.job_id || 0).toString().padStart(4, '0')}</strong></td>
                <td>
                  <div style="font-weight:700; color:var(--text-main); font-size:1rem;">${j.plate_no || '---'}</div>
                  <div style="font-size:0.75rem; color:var(--text-dim);">${j.make || ''} ${j.model || ''}</div>
                  ${j.latest_remarks ? `<div style="font-size:0.75rem; background:rgba(255,255,255,0.05); padding:4px 8px; border-radius:6px; margin-top:5px; color:#94a3b8; border-left:2px solid var(--accent);"><strong>Remarks:</strong> ${j.latest_remarks}</div>` : ''}
                </td>
                <td>
                  <div style="font-weight:600;">${j.service_name || 'General Repair'}</div>
                  <small style="color:var(--text-dim); background:rgba(255,255,255,0.05); padding:4px 8px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
                    <i class="fas fa-wrench" style="color:var(--accent);"></i> ${j.mechanic_name || 'No Mechanic'} 
                    <i class="fas fa-warehouse" style="margin-left:8px; color:var(--accent);"></i> ${j.bay_name || 'No Bay'}
                  </small>
                </td>
                <td>
                  <span class="badge ${j.status === 'COMPLETED' ? 'badge-active' : (j.status === 'IN_PROGRESS' ? 'badge-warning' : 'badge-pending')}">
                    ${j.status || 'PENDING'}
                  </span>
                </td>
                 <td>
                   ${j.status !== 'COMPLETED' ? `
                   <button class="btn-outline" style="padding:4px 10px; font-size:0.75rem; border-color:var(--accent); color:var(--accent);" onclick="window.openJobStatusModal(${j.job_id}, '${j.status}', ${j.mechanic_id || 'null'}, ${j.bay_id || 'null'}, true)">
                    <i class="fas fa-user-cog"></i> Assign / Update
                   </button>
                   ` : '<span style="font-size:0.75rem; color:var(--text-dim); opacity:0.6;"><i class="fas fa-check-double"></i> Finalized</span>'}
                </td>
              </tr>`).join('');
          } catch (e) {
            console.error("Jobs Fetch Scrub Failed:", text);
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;">Data Error: Could not display jobs.</td></tr>';
          }
        }).catch(err => {
          body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;">Network Error: Could not reach server.</td></tr>';
        });
    };

    // GHOST LAYER PURGER
    let purgeCount = 0;
    const purger = setInterval(() => {
      const overlays = document.querySelectorAll('.announcement-overlay, .modal-overlay');
      overlays.forEach(o => {
        if (o.id === 'upgradeModal') return; // Never purge the upgrade modal
        if (o.style.display !== 'flex' && o.style.display !== 'block') {
          o.style.pointerEvents = 'none';
          o.style.zIndex = '-1';
        }
      });
      purgeCount++; if (purgeCount > 10) clearInterval(purger);
    }, 200);

    // Cleaned redundant form listener block
  </script>

  <!-- SIDEBAR NAV (Restored to top for consistent layout) -->
  <nav class="sidebar"
    style="position:fixed !important; left:0 !important; top:0 !important; z-index:2147483647 !important; pointer-events:auto !important; display:flex !important; opacity:1 !important; visibility:visible !important;">
    <div class="brand">
      <div class="brand-icon">
        <?php if (!empty($tenant_custom['logo_url'])): ?>
        <img src="<?php echo htmlspecialchars($tenant_custom['logo_url']); ?>" alt="Logo">
        <?php else: ?>
        <i class="fas fa-wrench"></i>
        <?php endif; ?>
      </div>
      <div style="line-height:1.2;">
        <div style="font-size:1.1rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:160px;">
          <?php echo htmlspecialchars($shop_name); ?>
        </div>
        <div style="font-size:0.7rem; color:var(--text-dim); font-weight:400;">
          <?php echo ucwords(strtolower($role)); ?> Portal
        </div>
        <div
          style="font-size:0.55rem; margin-top:5px; color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:1px; opacity:0.8;">
          Final System V80</div>
      </div>
    </div>

    <div class="nav-scroll" style="pointer-events:auto !important; position:relative; z-index:100;">
      <div class="nav-item active" data-view="dashboard" onclick="window.navToView('dashboard')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-home"></i> Dashboard
      </div>

      <div class="nav-group-title">Public Presence</div>
      <a href="shop.php?id=<?php echo urlencode($tenant_custom['slug'] ?? ''); ?>" target="_blank" class="nav-item-link"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-external-link-alt"></i> View My Website
      </a>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Shop Operations</div>
      <div class="nav-item" data-view="appointments" onclick="window.navToView('appointments')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-calendar-check"></i> Appointments
      </div>
      <div class="nav-item" data-view="job_orders" onclick="window.navToView('job_orders')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-tools"></i> Active Repairs
      </div>
      <div class="nav-item" data-view="bays" onclick="window.navToView('bays')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-warehouse"></i> Service Bays
      </div>
      <div class="nav-item" data-view="mechanics" onclick="window.navToView('mechanics')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-user-cog"></i> Mechanics
      </div>
      <div class="nav-item" data-view="services" onclick="window.navToView('services')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-list-ul"></i> Services & Pricing
      </div>
      <?php elseif ($role === 'CASHIER'): ?>
      <div class="nav-group-title">Cashier Portal</div>
      <div class="nav-item" data-view="customers" onclick="window.navToView('customers')">
        <i class="fas fa-users"></i> Customer Registry
      </div>
      <div class="nav-item" data-view="vehicles" onclick="window.navToView('vehicles')">
        <i class="fas fa-car"></i> Vehicle Masterfile
      </div>
      <div class="nav-item" data-view="payments" onclick="window.navToView('payments')">
        <i class="fas fa-money-bill-wave"></i> Payment Processing
      </div>
      <?php elseif ($role === 'MECHANIC'): ?>
      <div class="nav-group-title">My Station</div>
      <div class="nav-item" data-view="mechanic_history" onclick="window.navToView('mechanic_history')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-history"></i> My Work History
      </div>
      <div class="nav-item" data-view="inventory_lookup" onclick="window.navToView('inventory_lookup')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-boxes"></i> Parts Catalog
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">CRM</div>
      <div class="nav-item" data-view="customers" onclick="window.navToView('customers')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-users"></i> Customers
      </div>
      <div class="nav-item" data-view="vehicles" onclick="window.navToView('vehicles')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-car"></i> Vehicles
      </div>
      <div class="nav-item" data-view="payments" onclick="window.navToView('payments')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-money-check-alt"></i> Payments
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Inventory</div>
      <div class="nav-item" data-view="inventory" onclick="window.navToView('inventory')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-boxes"></i> Parts Inventory
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Administration</div>
      <div class="nav-item" data-view="staff" onclick="window.navToView('staff')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-user-shield"></i> Staff Accounts
      </div>
      <div class="nav-item" data-view="reports" onclick="window.navToView('reports')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-chart-pie"></i> Reports & Analytics
      </div>
      <?php endif; ?>

      <?php if ($role === 'OWNER'): ?>
      <div class="nav-group-title">Configuration</div>
      <div class="nav-item" data-view="customization" onclick="window.navToView('customization')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-paint-brush"></i> Shop Settings
      </div>
      <div class="nav-item" data-view="customer_logs" onclick="window.navToView('customer_logs')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-history"></i> Audit Trail
      </div>
      <div class="nav-item" data-view="subscription" onclick="window.navToView('subscription')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-credit-card"></i> My Subscription
      </div>
      <?php endif; ?>

      <div class="nav-group-title">My Account</div>
      <div class="nav-item" data-view="my_profile" onclick="window.navToView('my_profile')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-user-circle"></i> My Profile
      </div>
    </div>

    <div style="margin-top:auto; padding: 0 1.5rem 1.5rem;">
      <a href="?logout=1" class="nav-item"
        style="color:var(--danger); border-radius: 12px; background:rgba(239,68,68,0.05); justify-content:center; cursor:pointer;"
        onmouseenter="this.style.background='rgba(239,68,68,0.1)'"
        onmouseleave="this.style.background='rgba(239,68,68,0.05)'">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </nav>


  <!-- Announcement System (Moved to bottom for better z-layering) -->
  <div class="announcement-puller" id="annPuller" onclick="toggleAnnouncement()">
    <div class="bookmark-tab">
      <i class="fas fa-bullhorn" style="font-size: 1.2rem;"></i>
      <span>Broadcast</span>
      <div id="annBadge" class="ann-badge"></div>
      <i class="fas fa-chevron-down" style="font-size:0.7rem; opacity:0.6;"></i>
    </div>
  </div>
  <div class="announcement-overlay" id="annOverlay" onclick="toggleAnnouncement()"></div>
  <div class="announcement-panel" id="annPanel">
    <!-- Content of panel ... -->
    <div style="display:flex; align-items:center; gap:20px; margin-bottom:1.8rem;">
      <div
        style="width:55px; height:55px; border-radius:15px; background:rgba(var(--accent-rgb), 0.1); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.6rem;">
        <i class="fas fa-satellite-dish"></i>
      </div>
    </div>
    <div id="annList" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
      <?php if (empty($all_announcements)): ?>
      <p style="color:var(--text-dim); text-align:center; padding:2rem;">No recent broadcasts to show.</p>
      <?php else: ?>
      <?php foreach ($all_announcements as $ann): ?>
      <div
        style="background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); padding:1.2rem; border-radius:18px; color:var(--text-main); margin-bottom:1rem; border-left: 4px solid <?php echo ($ann['type'] === 'GLOBAL') ? 'var(--accent)' : 'var(--warning)'; ?>;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
          <div style="display:flex; flex-direction:column; gap:2px;">
            <span style="font-weight:800; font-size:0.9rem; color:white;">
              <i class="<?php echo ($ann['type'] === 'GLOBAL') ? 'fas fa-shield-alt' : 'fas fa-user-tie'; ?>"
                style="margin-right:6px; font-size:0.8rem; color:<?php echo ($ann['type'] === 'GLOBAL') ? 'var(--accent)' : 'var(--warning)'; ?>;"></i>
              <?php
              if ($ann['type'] === 'GLOBAL') {
                echo "SYSTEM BROADCAST";
              } else {
                echo ($ann['user_id'] == $_SESSION['user_id']) ? 'FROM YOU' : htmlspecialchars($ann['author_name']);
              }
              ?>
            </span>
            <span
              style="font-size:0.6rem; color:var(--text-dim); text-transform:uppercase; font-weight:700; letter-spacing:1px; margin-left: 23px;">
              <?php echo ($ann['type'] === 'GLOBAL') ? 'ADMINISTRATOR' : htmlspecialchars($ann['role_name'] ?? 'OWNER'); ?>
            </span>
          </div>
          <span style="font-size:0.65rem; color:var(--text-dim);">
            <?php echo date('M d, h:i A', strtotime($ann['created_at'])); ?>
          </span>
        </div>
        <div style="line-height:1.6; font-size:0.92rem; white-space: pre-wrap; padding-left: 23px;">
          <?php echo htmlspecialchars($ann['message']); ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if (strtoupper($role) === 'OWNER' || strtoupper($role) === 'MANAGER'): ?>
    <div id="staffAnnEditor" style="display:none; margin-top:0.5rem;">
      <textarea id="staffAnnInput" class="modern-input"
        style="min-height:120px; resize:none; font-size:0.95rem; background:rgba(0,0,0,0.2); border:1px solid var(--accent); padding:1.2rem; margin-bottom:1rem; color:white !important;"
        placeholder="Write message to your team..."></textarea>
      <div style="display:flex; gap:10px;">
        <button class="btn-gradient" style="flex:1; padding:12px; border-radius:12px; font-weight:700;"
          onclick="saveStaffAnnEdit()">Push Update</button>
        <button class="btn-white" style="padding:12px 20px; border-radius:12px;"
          onclick="cancelStaffAnnEdit()">Cancel</button>
      </div>
    </div>
    <div id="staffAnnControls" style="margin-top:1.5rem; text-align:right;">
      <button class="btn-outline"
        style="padding:8px 15px; border-radius:10px; font-size:0.75rem; border-color:var(--accent); color:var(--accent);"
        onclick="enableStaffAnnEdit()">
        <i class="fas fa-plus"></i> New Shop Broadcast
      </button>
    </div>
    <?php endif; ?>
    <div style="margin-top:2.2rem; text-align:center;">
      <button class="btn-outline" style="padding:12px 35px; border-radius:15px; font-weight:700;"
        onclick="toggleAnnouncement()">Close Updates</button>
    </div>
  </div>

  <!-- Confirm Appointment Modal -->
  <div id="confirmAppointmentModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:999999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:500px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Confirm Appointment</h3>
        <button onclick="document.getElementById('confirmAppointmentModal').style.display='none'"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="confirmApptForm">
        <input type="hidden" name="appointment_id" id="confirm_appt_id">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Assign
            Mechanic</label>
          <select name="mechanic_id" id="confirm_mechanic_id"></select>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Assign Service
            Bay</label>
          <select name="bay_id" id="confirm_bay_id"></select>
        </div>
        <button type="button" onclick="processAppointmentConfirmation()"
          style="width:100%; background:var(--accent); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer; box-shadow:0 4px 15px var(--accent-glow);">Confirm
          & Create Job Order</button>
      </form>
    </div>
  </div>

  <!-- Main Content Area -->
  <main class="main-content">
    <?php
    $isSuspended = false;
    if (isset($global_tenant_res) && is_array($global_tenant_res) && isset($global_tenant_res['status']) && strtoupper($global_tenant_res['status']) === 'SUSPENDED') {
      $isSuspended = true;
    }
    if ($isSuspended && in_array(strtoupper($role ?? ''), ['OWNER', 'MANAGER'])): ?>
    <div
      style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; border-radius: 16px; padding: 1.2rem 1.5rem; margin: 1.5rem 2rem 0; display: flex; align-items: center; gap: 15px; animation: slideDown 0.5s ease-out; position: relative; z-index: 10;">
      <div
        style="width: 45px; height: 45px; background: #ef4444; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.3rem; box-shadow: 0 10px 20px rgba(239, 68, 68, 0.2);">
        <i class="fas fa-exclamation-triangle"></i>
      </div>
      <div style="flex: 1;">
        <h4 style="margin: 0; color: #ef4444; font-weight: 800; font-size: 1.05rem; letter-spacing: -0.5px;">Public
          Website Suspended</h4>
        <p style="margin: 4px 0 0; color: #94a3b8; font-size: 0.88rem; line-height: 1.4;">Your business landing page is
          currently offline and showing a suspension notice. Please contact AutoFix Hub support or check your billing
          status to resolve this.</p>
      </div>
      <button onclick="window.navToView('subscription')"
        style="background: #ef4444; color: white; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 800; font-size: 0.75rem; cursor: pointer; transition: 0.3s;"
        onmouseover="this.style.transform='scale(1.05)'" onmouseleave="this.style.transform='scale(1)'">VIEW
        SUBSCRIPTION</button>
    </div>
    <?php endif; ?>
    <style>
      @keyframes slideDown {
        from {
          transform: translateY(-20px);
          opacity: 0;
        }

        to {
          transform: translateY(0);
          opacity: 1;
        }
      }
    </style>
    <!-- Removed header-bg for cleaner layout -->
    <header>
      <div>
        <h1 id="pageTitle" style="font-size: 2rem; font-weight: 800; letter-spacing: -1px;">
          <?php
          switch ($role) {
            case 'OWNER':
              echo 'Owner Dashboard';
              break;
            case 'MANAGER':
              echo 'Manager Dashboard';
              break;
            case 'CASHIER':
              echo 'Cashier Dashboard';
              break;
            case 'MECHANIC':
              echo 'Mechanic Dashboard';
              break;
            default:
              echo 'User Dashboard';
          }
          ?>
        </h1>
        <p style="color:var(--text-dim); margin-top:0.3rem;" id="pageSubtitle">
          <?php
          if ($role === 'MECHANIC')
            echo 'Track your assigned repair jobs and updates.';
          elseif ($role === 'OWNER' || $role === 'MANAGER')
            echo 'Overview of shop operations and business performance.';
          else
            echo 'Quick access to your daily shop operations.';
          ?>
        </p>
      </div>
      <div style="display:flex; align-items:center; gap:20px;">
        <?php if (in_array(strtoupper($role), ['OWNER', 'MANAGER'])): ?>
        <div class="nav-notif-btn" onclick="window.navToView('appointments')">
          <i class="fas fa-bell"></i>
          <div id="notifBadge" class="notif-badge" style="display:none;">0</div>
        </div>
        <?php endif; ?>
        <div class="user-profile">
          <div class="avatar">
            <?php echo strtoupper(substr($owner_name, 0, 1)); ?>
          </div>
          <div>
            <div style="font-weight:700; font-size:0.95rem;">
              <?php echo htmlspecialchars($owner_name); ?>
            </div>
            <div style="font-size:0.75rem; color:var(--text-dim);">
              <?php echo ucwords(strtolower($role)); ?>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- SEC 1: Dashboard -->
    <div id="dashboard" class="view-section active">
      <h1 style="margin-bottom: 2rem; font-weight: 800;">
        <?php echo ucwords(strtolower($role)); ?> Dashboard
      </h1>
      <div class="stats-grid">
        <?php if ($role === 'CASHIER'): ?>
        <div class="stat-card">
          <p class="stat-label">Total Collection Today</p>
          <div class="stat-value" id="stat-revenue" style="color:var(--accent);">₱
            <?php echo number_format($today_revenue, 2); ?> <i class="fas fa-cash-register"
              style="color:var(--accent); font-size:1.4rem;"></i>
          </div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Unpaid Balance</p>
          <div class="stat-value" id="stat-pending-payments" style="color:var(--danger);">
            ₱0.00 <i class="fas fa-file-invoice-dollar" style="color:var(--danger); font-size:1.4rem;"></i>
          </div>
        </div>
        <?php else: ?>
        <div class="stat-card">
          <p class="stat-label">Pending Repair Jobs</p>
          <div class="stat-value" id="stat-pending-jobs">
            <?php echo number_format($pending_jobs_count); ?> <i class="fas fa-car-crash"
              style="color:var(--warning); font-size:1.4rem;"></i>
          </div>
        </div>
        <div class="stat-card">
          <p class="stat-label">Bays Available</p>
          <div class="stat-value" id="stat-avail-bays">
            <?php
            $availBays = 0;
            foreach ($bays_list as $b) {
              if ($b['status'] === 'AVAILABLE')
                $availBays++;
            }
            echo number_format($availBays);
            ?>
            <i class="fas fa-warehouse" style="color:var(--accent); font-size:1.4rem;"></i>
          </div>
        </div>
        <?php if ($role !== 'MECHANIC'): ?>
        <div class="stat-card">
          <p class="stat-label">Today's Revenue</p>
          <div class="stat-value" id="stat-revenue">₱
            <?php echo number_format($today_revenue, 2); ?> <i class="fas fa-coins"
              style="color:#fcd34d; font-size:1.4rem;"></i>
          </div>
        </div>
        <?php endif; ?>
        <div class="stat-card">
          <p class="stat-label">Appointments Today</p>
          <div class="stat-value" id="stat-appointments-today">
            <?php echo number_format($appointments_today); ?> <i class="fas fa-calendar-check"
              style="color:#60a5fa; font-size:1.4rem;"></i>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- REMOVAL OF MANUAL PENDING VERIFICATION BLOCK COMPLETED -->
      <!-- Dashboard Main Section -->
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 1.5rem;">
          <h3>
            <?php
            if ($role === 'CASHIER')
              echo 'Pending Billing & Collectibles';
            elseif ($role === 'MECHANIC')
              echo 'My Active Job Orders';
            else
              echo 'Vehicles Currently in Service';
            ?>
          </h3>
          <?php if ($role === 'CASHIER'): ?>
          <button class="btn-action" style="padding: 0.5rem 1rem; font-size: 0.85rem;"
            onclick="window.navToView('payments')">
            Record New Payment
          </button>
          <?php else: ?>
          <button class="btn-action" style="padding: 0.5rem 1rem; font-size: 0.85rem;"
            onclick="<?php echo ($role === 'MECHANIC') ? 'openWorkLog()' : 'alert(\'Queue feature coming soon!\')'; ?>">
            <?php echo ($role === 'MECHANIC') ? 'View Work Log' : 'View Queue'; ?>
          </button>
          <?php endif; ?>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <?php if ($role === 'CASHIER'): ?>
                <th>Plate No.</th>
                <th>Vehicle / Owner</th>
                <th>Service Done</th>
                <th>Total Bill</th>
                <th>Status</th>
                <th>Action</th>
                <?php else: ?>
                <th>Plate No.</th>
                <th>Vehicle</th>
                <th>Status/Assigned</th>
                <th>Current Progress</th>
                <?php if ($role === 'MECHANIC'): ?>
                <th>Action</th>
                <?php endif; ?>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="dashboardRepairJobsBody">
              <tr>
                <td colspan="6" style="text-align:center; padding:2rem; color:var(--text-dim);">
                  <i class="fas fa-spinner fa-spin"></i> Loading repair sessions...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Appointments View -->
    <div id="appointments" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <div>
            <h3>Appointment Calendar</h3>
            <p style="color:var(--text-dim); font-size: 0.9rem;">Review and manage upcoming maintenance
              bookings from the mobile app.</p>
          </div>
          <button class="btn-action" onclick="refreshAppointmentsList()"><i class="fas fa-sync"></i> Refresh
            List</button>
        </div>

        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Schedule</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Service</th>
                <th>Estimate</th>
                <th>Status</th>
                <th>
                  <?php echo ($role === 'CASHIER' ? 'Booking Note' : 'Action'); ?>
                </th>
              </tr>
            </thead>
            <tbody id="appointmentsTableBody">
              <tr>
                <td colspan="7" style="text-align:center; padding:3rem; color:var(--text-dim);">Loading
                  bookings...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Job Orders View -->
    <div id="job_orders" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <div>
            <h3>Repair Job Orders</h3>
            <p style="color:var(--text-dim); font-size:0.9rem;">Track live progress of vehicles inside the
              shop.</p>
          </div>
          <button class="btn-action" onclick="refreshJobOrders()"><i class="fas fa-sync"></i> Refresh
            List</button>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>JO #</th>
                <th>Vehicle</th>
                <th>Service / Assignment</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="jobOrdersTableBody">
              <tr>
                <td colspan="5" style="text-align:center; color:var(--text-dim); padding:3rem;">Loading
                  active jobs...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Customers View -->
    <div id="customers" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3 style="display:flex; align-items:center; gap:12px;">
            Customer Registry
            <span id="customerTotalCount"
              style="font-size:0.9rem; background:rgba(255,255,255,0.1); padding:4px 12px; border-radius:20px; color:var(--accent); border:1px solid rgba(255,255,255,0.05);">0
              Total</span>
          </h3>
          <button class="btn-action" onclick="openModal('customerModal')"><i class="fas fa-user-plus"></i> New
            Customer</button>
        </div>
        <input type="text" class="search-input" placeholder="Search by name or email..."
          oninput="window.searchTable(this, 'customersBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Contact</th>
              <th>Total Visits</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="customersBody">
            <tr>
              <td colspan="5" style="text-align:center; color:var(--text-dim); padding:2rem;">Loading
                customers...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Vehicles View -->
    <div id="vehicles" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3><i class="fas fa-car" style="color:var(--accent)"></i> Fleet Management</h3>
          <button class="btn-action" onclick="openModal('vehicleModal')"><i class="fas fa-plus"></i> Register
            New Vehicle</button>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Plate #</th>
              <th>Brand / Model</th>
              <th>Year</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="vehiclesBody">
            <tr>
              <td colspan="3" style="text-align:center; padding:2rem; color:var(--text-dim);">
                <i class="fas fa-spinner fa-spin"></i> Initializing vehicle directory...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Payments View -->
    <div id="payments" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3><i class="fas fa-coins" style="color:var(--accent)"></i> Payment Monitoring</h3>
          <div style="display:flex; gap:10px;">
            <button class="btn-outline" onclick="showEODReport()"><i class="fas fa-file-invoice-dollar"></i>
              End of Day Summary</button>
            <button class="btn-outline" onclick="refreshPaymentsList()"><i class="fas fa-sync"></i> Sync
              Logs</button>
            <button class="btn-action" onclick="openModal('paymentModal')"><i class="fas fa-money-bill-wave"></i> Add
              Payment</button>
          </div>
        </div>
        <input type="text" class="search-input" placeholder="Search by payment ID, customer, or reference..."
          oninput="window.searchTable(this, 'completedPaymentsBody')">
        <!-- PENDING MOBILE APPROVALS REMOVED PER USER REQUEST -->
        <div id="pendingPaymentsContainer" style="display:none;"></div>

        <div style="margin-bottom:1.5rem;">
          <h4 style="color:var(--text-dim); margin-bottom:1rem;">Recent Payment History</h4>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID #</th>
              <th>Customer</th>
              <th>Reference (Type)</th>
              <th>Amount Paid</th>
              <th>Date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="completedPaymentsBody">
            <tr>
              <td colspan="6" style="text-align:center; padding:2rem;">Fetching payment logs...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- SEC 2: Service Bays -->
    <div id="bays" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 2rem;">
          <div>
            <h3>Service Bay Allocations</h3>
            <p style="color:var(--text-dim);">Track physical bays to prevent double booking. <span
                style="display:inline-block; margin-left:10px; background:rgba(255,255,255,0.05); padding:2px 10px; border-radius:10px; font-size:0.75rem; color:var(--accent);">Plan:
                <?php echo $plan_tier; ?> (Max
                <?php echo $bay_limit; ?> Bays)
              </span></p>
          </div>
          <button class="btn-action" onclick="openModal('bayModal')"><i class="fas fa-plus"></i> Register
            Bay</button>
        </div>
        <div id="baysGrid"
          style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
          <?php if (empty($bays_list)): ?>
          <p style="color:var(--text-dim);">No service bays registered.</p>
          <?php else: ?>
          <?php foreach ($bays_list as $bay): ?>
          <div
            style="border:1px solid <?php echo $bay['status'] === 'AVAILABLE' ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'; ?>; background:<?php echo $bay['status'] === 'AVAILABLE' ? 'rgba(16,185,129,0.05)' : 'rgba(239,68,68,0.05)'; ?>; padding:1.5rem; border-radius:12px;">
            <span class="badge <?php echo $bay['status'] === 'AVAILABLE' ? 'badge-active' : ''; ?>"
              style="<?php echo $bay['status'] !== 'AVAILABLE' ? 'background:#ef4444; color:white;' : ''; ?>">
              <?php echo $bay['status']; ?>
            </span>
            <h2 style="margin:1rem 0;">
              <?php echo htmlspecialchars($bay['bay_name']); ?>
            </h2>
            <button
              style="width:100%; background:var(--glass); border:1px solid var(--glass-border); color:white; padding:10px; border-radius:8px;"
              onclick="openAssignBayModal(<?php echo $bay['bay_id']; ?>, '<?php echo addslashes($bay['bay_name']); ?>')">
              <?php echo $bay['status'] === 'AVAILABLE' ? 'Assign Vehicle' : 'View Details'; ?>
            </button>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- SEC 3: Mechanics -->
    <div id="mechanics" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 2rem;">
          <div>
            <h3>Mechanic Masterfile</h3>
            <p style="color:var(--text-dim); margin-top:5px;">Maintain mechanic info & specialization.
            </p>
          </div>
          <button class="btn-action" onclick="openModal('mechanicModal')">+ Register Mechanic</button>
        </div>
        <input type="text" class="search-input" placeholder="Search mechanics by name or spec..."
          oninput="window.searchTable(this, 'mechanicsBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Specialization</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="mechanicsBody">
            <?php if (empty($mechanics_list)): ?>
            <tr>
              <td colspan="4" style="text-align:center; color:var(--text-dim); padding: 2rem;">No
                mechanics found.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($mechanics_list as $m): ?>
            <tr>
              <td><strong>
                  <?php echo htmlspecialchars($m['full_name']); ?>
                </strong></td>
              <td>
                <?php echo htmlspecialchars($m['specialization']); ?>
              </td>
              <td><span class="badge <?php echo $m['status'] === 'AVAILABLE' ? 'badge-active' : ''; ?>">
                  <?php echo $m['status']; ?>
                </span>
              </td>
              <td><button type="button" class="btn-outline"
                  style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--text-main); position:relative; z-index:999; pointer-events: auto !important; cursor: pointer;"
                  onclick="window.openMechanicProfile(<?php echo (int) $m['mechanic_id']; ?>)">View
                  Profile</button></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- SEC 4: Services & Pricing -->
    <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
    <div id="services" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 2rem;">
          <div>
            <h3>Service Catalog & Pricing</h3>
            <p style="color:var(--text-dim); margin-top:5px;">Create repair services, categorize, and
              set
              prices.</p>
          </div>
          <button class="btn-action" onclick="openModal('serviceModal')">+ Add Service</button>
        </div>
        <input type="text" class="search-input" placeholder="Search services by name or description..."
          oninput="window.searchTable(this, 'servicesBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Service Name</th>
              <th>Description</th>
              <th>Price</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="servicesBody">
            <?php if (empty($services_list)): ?>
            <tr>
              <td colspan="5" style="text-align:center; color:var(--text-dim); padding: 2rem;">No
                services
                defined yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($services_list as $s): ?>
            <tr>
              <td><strong>
                  <?php echo htmlspecialchars($s['service_name']); ?>
                </strong></td>
              <td style="font-size:0.85rem; color:var(--text-dim);">
                <?php echo !empty(trim($s['description'])) ? htmlspecialchars($s['description']) : '<em style="color:rgba(255,255,255,0.3); font-size:0.8rem;">No description provided</em>'; ?>
              </td>
              <td>₱
                <?php echo number_format($s['price'], 2); ?>
              </td>
              <td><span class="badge badge-active">
                  <?php echo $s['status']; ?>
                </span></td>
              <td>
                <button class="btn-outline"
                  onclick="editService(<?php echo $s['service_id']; ?>, '<?php echo addslashes($s['service_name']); ?>', '<?php echo addslashes($s['description']); ?>', <?php echo $s['price']; ?>)">Edit</button>
                <button class="btn-outline"
                  style="color:var(--danger); border-color:rgba(239,68,68,0.3); margin-left: 5px;"
                  onclick="deleteService(<?php echo $s['service_id']; ?>)">Delete</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>



    <!-- SEC 5: Inventory -->
    <div id="inventory" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 2rem;">
          <div>
            <h3>Parts & Inventory</h3>
            <p style="color:var(--text-dim); margin-top:5px;">Stock control of spare parts and low stock
              alerts.</p>
          </div>
          <button class="btn-action" onclick="openModal('inventoryModal')">+ Receive Delivery</button>
        </div>
        <input type="text" class="search-input" placeholder="Search item code, brand, or name..."
          oninput="window.searchTable(this, 'inventoryBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Item Code</th>
              <th>Name & Brand</th>
              <th>Qty on Hand</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="inventoryBody">
            <?php if (empty($inventory_list)): ?>
            <tr>
              <td colspan="5" style="text-align:center; color:var(--text-dim); padding: 2rem;">No
                inventory records found.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($inventory_list as $inv): ?>
            <tr>
              <td><code><?php echo htmlspecialchars($inv['item_code']); ?></code></td>
              <td><strong>
                  <?php echo htmlspecialchars($inv['item_name']); ?>
                </strong><br><small style="color:var(--text-dim)">
                  <?php echo htmlspecialchars($inv['brand']); ?>
                </small>
              </td>
              <td>
                <?php echo $inv['quantity']; ?> pcs
              </td>
              <td><span class="badge <?php echo $inv['status'] === 'IN_STOCK' ? 'badge-active' : ''; ?>">
                  <?php echo str_replace('_', ' ', $inv['status']); ?>
                </span>
              </td>
              <td><button class="btn-outline">Update Stock</button></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- SEC 6: Staff Accounts -->
    <div id="staff" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 2rem;">
          <div>
            <h3>Staff Accounts</h3>
            <p style="color:var(--text-dim); margin-top:5px;">Create logins with roles (Manager,
              Cashier,
              etc.).</p>
          </div>
          <button class="btn-action" onclick="openModal('staffModal')">+ Create Account</button>
        </div>
        <input type="text" class="search-input" placeholder="Search staff by name, email, or role..."
          oninput="window.searchTable(this, 'staffBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email Login</th>
              <th>Role</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="staffBody">
            <?php foreach ($staff_list as $staff):
              $role_name = 'Staff';
              if ($staff['role_id'] == 1)
                $role_name = 'Super Admin';
              if ($staff['role_id'] == 2)
                $role_name = 'Owner';
              if ($staff['role_id'] == 3)
                $role_name = 'Manager';
              if ($staff['role_id'] == 4)
                $role_name = 'Cashier';
              if ($staff['role_id'] == 5)
                $role_name = 'Mechanic';

              $isSelf = ($staff['user_id'] == $_SESSION['user_id']);
              $isTargetOwner = ($staff['role_id'] == 2);
              $currentUserRole = strtoupper($_SESSION['role'] ?? '');
              $cannotManage = $isSelf || ($currentUserRole === 'MANAGER' && $isTargetOwner);
              ?>
            <tr>
              <td><strong>
                  <?php echo htmlspecialchars($staff['name']); ?>
                </strong></td>
              <td>
                <?php echo htmlspecialchars($staff['email']); ?>
              </td>
              <td>
                <?php echo $role_name; ?>
              </td>
              <td><span class="badge <?php echo ($staff['status'] === 'ACTIVE') ? 'badge-active' : 'badge-danger'; ?>">
                  <?php echo $staff['status']; ?>
                </span></td>
              <td><button class="btn-outline staff-manage-btn"
                  style="display:inline-block; padding:8px 16px; font-size:0.75rem; border-radius:10px; border:2px solid var(--accent) !important; color:#000 !important; background:var(--accent) !important; position:relative; z-index:9999 !important; pointer-events:auto !important; cursor:pointer !important; font-weight:800; box-shadow:0 0 15px var(--accent-glow); <?php echo $cannotManage ? 'opacity:0.4; filter:grayscale(1); pointer-events:none !important;' : ''; ?>"
                  onclick="event.stopPropagation(); window.openStaffManageModal(<?php echo $staff['user_id']; ?>)">
                  <?php
                  if ($isSelf)
                    echo 'You';
                  else if ($isTargetOwner && $currentUserRole === 'MANAGER')
                    echo 'Owner';
                  else
                    echo 'Manage';
                  ?>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- STAFF MANAGEMENT ENGINE (Isolated for Reliability) -->
    <script>
      window.openStaffManageModal = function (userId) {
        // alert("ENGINE: Opening Staff " + userId);
        const modal = document.getElementById('staffManageModal');
        const content = document.getElementById('staffManageContent');
        if (!modal) return alert("Critical: Manage Modal missing from DOM");

        modal.style.display = 'flex';
        modal.style.zIndex = '2147483647'; // Maximum possible z-index

        if (content) {
          content.innerHTML = '<div style="text-align:center; padding:3rem;"><i class="fas fa-circle-notch fa-spin"></i> Loading...</div>';
        }

        fetch('tenant-dashboard.php?action=fetch_staff_details&user_id=' + userId)
          .then(r => r.json())
          .then(res => {
            if (res.status === 'success' && res.data) {
              const s = res.data;
              if (content) {
                content.innerHTML = `
                  <div style="text-align:center; margin-bottom:2.5rem; padding: 1.5rem; background:rgba(255,255,255,0.03); border-radius:24px; border:1px solid rgba(255,255,255,0.05);">
                    <div style="width:80px; height:80px; border-radius:24px; background:var(--accent); color:#000; display:flex; align-items:center; justify-content:center; font-size:2.2rem; font-weight:900; margin:0 auto 1.5rem; box-shadow:0 15px 35px rgba(0,0,0,0.3);">
                      ${(s.name || 'S').charAt(0).toUpperCase()}
                    </div>
                    <h4 style="margin:0; font-size:1.6rem; color:#fff; letter-spacing:-0.5px;">${s.name}</h4>
                    <div style="font-size:0.9rem; color:rgba(255,255,255,0.6); margin-top:5px; font-weight:500;">${s.email}</div>
                  </div>
                  
                  <div style="background:rgba(0,0,0,0.3); padding:2rem; border-radius:28px; border:1px solid rgba(255,255,255,0.08); box-shadow:inset 0 0 20px rgba(0,0,0,0.2);">
                    <label style="display:block; font-size:0.75rem; font-weight:800; color:rgba(255,255,255,0.5); margin-bottom:15px; text-transform:uppercase; letter-spacing:1.5px;">Operational Access</label>
                    <select id="staff_manage_status">
                      <option value="ACTIVE" ${s.status === 'ACTIVE' ? 'selected' : ''}>ACTIVE (Full Access)</option>
                      <option value="INACTIVE" ${s.status === 'INACTIVE' ? 'selected' : ''}>INACTIVE (Restricted)</option>
                    </select>
                    
                    <button onclick="window.updateStaffStatus(${s.user_id})" style="width:100%; background:var(--accent); color:#000; border:none; padding:1.2rem; border-radius:20px; font-weight:900; cursor:pointer; font-size:1.05rem; box-shadow:0 10px 25px var(--accent-glow); transition:0.3s; text-transform:uppercase; letter-spacing:1px;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 15px 30px var(--accent-glow)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 10px 25px var(--accent-glow)'">
                      Sync Permissions
                    </button>
                  </div>`;
              }
            }
          });
      };

      window.updateStaffStatus = function (userId) {
        const status = document.getElementById('staff_manage_status').value;
        const fd = new FormData();
        fd.append('user_id', userId);
        fd.append('status', status);
        fetch('tenant-dashboard.php?action=update_staff_status', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (res.status === 'success') {
              alert("Staff status updated!");
              location.reload();
            } else alert("Error: " + res.message);
          });
      };
    </script>

    <!-- SEC 7: Reports -->
    <div id="reports" class="view-section">
      <div class="glass-panel">
        <h3>Business Analytics & Reports</h3>
        <p style="color:var(--text-dim); margin-bottom: 2rem;">Generate insights on revenue, performance,
          and
          inventory.</p>
        <div class="stats-grid">
          <div class="stat-card" style="cursor:pointer; text-align:center;" onclick="showReport('revenue')">
            <i class="fas fa-coins" style="font-size:2rem; color:var(--accent); margin-bottom:1rem;"></i>
            <h4>Revenue Report</h4>
          </div>
          <div class="stat-card" style="cursor:pointer; text-align:center;" onclick="showReport('performance')">
            <i class="fas fa-wrench" style="font-size:2rem; color:var(--accent); margin-bottom:1rem;"></i>
            <h4>Service Performance</h4>
          </div>
          <div class="stat-card" style="cursor:pointer; text-align:center;" onclick="showReport('inventory')">
            <i class="fas fa-boxes" style="font-size:2rem; color:var(--accent); margin-bottom:1rem;"></i>
            <h4>Inventory Report</h4>
          </div>
        </div>
      </div>
    </div>

    <!-- REPORT MODAL -->
    <div id="reportModal"
      style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
      <div
        style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:850px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3 id="reportTitle" style="margin:0; font-size:1.6rem; font-weight:800; letter-spacing:-0.5px;">Report
            Details</h3>
          <button onclick="closeModal('reportModal')"
            style="background:rgba(255,255,255,0.05); border:none; color:white; width:40px; height:40px; border-radius:12px; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>

        <!-- Chart Area (Hidden by default) -->
        <div id="reportChartContainer"
          style="display:none; margin-bottom:2.5rem; background:rgba(0,0,0,0.2); padding:1.5rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
          <canvas id="revenueChart" height="150"></canvas>
        </div>

        <div id="reportContent" style="max-height: 450px; overflow-y: auto; color: white;">
          <!-- Content dynamic -->
        </div>
      </div>
    </div>
    <!-- RECEIPT MODAL -->
    <div id="receiptModal"
      style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(15px); z-index:99999; align-items:center; justify-content:center;">
      <div
        style="background:white; color:#111827; border-radius:12px; padding:2.5rem; width:100%; max-width:400px; position:relative; box-shadow:0 20px 50px rgba(0,0,0,0.5); font-family:'Inter', sans-serif;">
        <button onclick="closeModal('receiptModal')"
          style="position:absolute; top:20px; right:20px; background:none; border:none; color:#94a3b8; font-size:1.5rem; cursor:pointer;">&times;</button>

        <div id="receiptPreviewContent">
          <!-- Content Dynamic -->
        </div>

        <div style="margin-top:2rem; display:flex; gap:10px;">
          <button class="btn-action" style="flex:1; background:#111827;" onclick="executeThermalPrint()">
            <i class="fas fa-print"></i> Print Thermal
          </button>
          <button class="btn-outline" style="flex:1; border-color:#cbd5e1; color:#64748b;"
            onclick="closeModal('receiptModal')">
            Close Preview
          </button>
        </div>
      </div>
    </div>

    <!-- Vehicle History Modal -->
    <div id="vehicleHistoryModal"
      style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(15px); z-index:999999; align-items:center; justify-content:center;">
      <div
        style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:800px; max-height:85vh; overflow-y:auto; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <div>
            <h3 style="margin:0; font-size:1.6rem; letter-spacing:-0.5px;">Vehicle Service Lineage</h3>
            <p id="historyVehicleInfo" style="color:var(--text-dim); margin-top:6px; font-size:0.95rem;">
            </p>
          </div>
          <button onclick="closeModal('vehicleHistoryModal')"
            style="background:rgba(255,255,255,0.05); border:none; color:white; width:45px; height:45px; border-radius:15px; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;">&times;</button>
        </div>
        <div id="vehicleHistoryContent">
          <!-- History items will be injected here -->
        </div>
      </div>
    </div>


    <!-- SEC 9: Customization (Restricted to OWNER) -->
    <?php if ($role === 'OWNER'): ?>
    <div id="customization" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; gap: 2rem;">
          <!-- Left: Form -->
          <form id="customizationForm" action="#" method="POST" enctype="multipart/form-data" style="flex: 1;">
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Shop Name</label>
              <input type="text" name="shop_name" id="setting_shop_name"
                value="<?php echo htmlspecialchars($shop_name); ?>" placeholder="e.g. AutoFix Pro"
                onfocus="highlightInPreview('shop_name')">
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('shop_name')">
                <i class="fas fa-save"></i> Save Shop Name
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Business Description</label>
              <textarea name="description" id="setting_description" placeholder="Short tagline for your business..."
                onfocus="highlightInPreview('description')"><?php echo htmlspecialchars($tenant_custom['description'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('description')">
                <i class="fas fa-save"></i> Save Description
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Main Welcome Headline</label>
              <input type="text" name="hero_title" id="setting_hero_title"
                value="<?php echo htmlspecialchars($tenant_custom['hero_title'] ?? ''); ?>"
                placeholder="e.g. Expert Service at Your Fingertips" onfocus="highlightInPreview('hero_title')">
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('hero_title')">
                <i class="fas fa-save"></i> Save Headline
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Sub-headline / Intro Text</label>
              <textarea name="hero_subtitle" id="setting_hero_subtitle"
                placeholder="A short welcoming message below your headline..." style="min-height:50px;"
                onfocus="highlightInPreview('hero_subtitle')"><?php echo htmlspecialchars($tenant_custom['hero_subtitle'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('hero_subtitle')">
                <i class="fas fa-save"></i> Save Intro Text
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>About Us / History</label>
              <textarea name="about_text" id="setting_about_text"
                placeholder="Tell your customers about your shop history..." onfocus="highlightInPreview('about_text')"
                style="min-height:80px;"><?php echo htmlspecialchars($tenant_custom['about_text'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('about_text')">
                <i class="fas fa-save"></i> Save History
              </button>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 1.5rem;">
              <div class="form-group">
                <label>Accent Color</label>
                <input type="color" name="primary_color" id="setting_primary_color"
                  value="<?php echo htmlspecialchars($tenant_custom['primary_color'] ?: '#6366f1'); ?>"
                  onfocus="highlightInPreview('primary_color')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('primary_color')">
                  <i class="fas fa-save"></i> Save Color
                </button>
              </div>
              <div class="form-group">
                <label>Background</label>
                <input type="color" name="secondary_color" id="setting_secondary_color"
                  value="<?php echo htmlspecialchars($tenant_custom['secondary_color'] ?: '#030712'); ?>"
                  onfocus="highlightInPreview('secondary_color')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('secondary_color')">
                  <i class="fas fa-save"></i> Save Background
                </button>
              </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>UI Theme Style</label>
              <select name="ui_style" id="setting_ui_style" onfocus="highlightInPreview('ui_style')">
                <option value="GLASS" <?php echo ($tenant_custom['ui_style'] === 'GLASS') ? 'selected' : ''; ?>>Modern
                  Glass</option>
                <option value="PREMIUM" <?php echo ($tenant_custom['ui_style'] === 'PREMIUM') ? 'selected' : ''; ?>>
                  Premium Card</option>
                <option value="MINIMAL" <?php echo ($tenant_custom['ui_style'] === 'MINIMAL') ? 'selected' : ''; ?>>Dark
                  Minimal</option>
                <option value="VIBRANT" <?php echo ($tenant_custom['ui_style'] === 'VIBRANT') ? 'selected' : ''; ?>>
                  Vibrant Tech</option>
              </select>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('ui_style')">
                <i class="fas fa-save"></i> Save Theme
              </button>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Border Radius (Roundness)</label>
              <input type="range" name="border_radius_val" id="setting_border_radius" min="0" max="50"
                value="<?php echo str_replace('px', '', $tenant_custom['border_radius'] ?? '24'); ?>"
                style="width:100%;" onfocus="highlightInPreview('border_radius')">
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('border_radius')">
                <i class="fas fa-save"></i> Save Radius
              </button>
            </div>

            <div class="form-group"
              style="margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 12px; border: 1px dashed var(--glass-border);">
              <label>Logo Branding</label>
              <div style="display:flex; gap:10px; margin-bottom:10px;">
                <input type="file" name="logo_file" id="setting_logo_file" accept="image/*"
                  style="background:rgba(0,0,0,0.2); padding:0.6rem; color:var(--text-dim); flex:1;"
                  onfocus="highlightInPreview('logo_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSettingWithFile('logo_file')">
                  <i class="fas fa-upload"></i> Upload
                </button>
              </div>
              <div style="display:flex; gap:10px;">
                <input type="text" name="logo_url" id="setting_logo_url"
                  value="<?php echo htmlspecialchars($tenant_custom['logo_url'] ?? ''); ?>"
                  placeholder="...or enter Image URL" style="font-size:0.8rem; flex:1;"
                  onfocus="highlightInPreview('logo_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSingleSetting('logo_url')">
                  <i class="fas fa-link"></i> Save URL
                </button>
              </div>
            </div>
            <div class="form-group"
              style="margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 12px; border: 1px dashed var(--glass-border);">
              <label>Banner Background</label>
              <div style="display:flex; gap:10px; margin-bottom:10px;">
                <input type="file" name="banner_file" id="setting_banner_file" accept="image/*"
                  style="background:rgba(0,0,0,0.2); padding:0.6rem; color:var(--text-dim); flex:1;"
                  onfocus="highlightInPreview('banner_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSettingWithFile('banner_file')">
                  <i class="fas fa-upload"></i> Upload
                </button>
              </div>
              <div style="display:flex; gap:10px;">
                <input type="text" name="banner_url" id="setting_banner_url"
                  value="<?php echo htmlspecialchars($tenant_custom['banner_url'] ?? ''); ?>"
                  placeholder="...or enter Image URL" style="font-size:0.8rem; flex:1;"
                  onfocus="highlightInPreview('banner_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSingleSetting('banner_url')">
                  <i class="fas fa-link"></i> Save URL
                </button>
              </div>
            </div>

            <div
              style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; margin-bottom: 2rem;">
              <h4
                style="color:var(--accent); text-transform:uppercase; font-size:0.75rem; margin-bottom:1rem; letter-spacing:1px; font-weight:800;">
                <i class="fas fa-bullhorn"></i> Internal Team Broadcast
              </h4>
              <div class="form-group">
                <label style="font-size:0.75rem;">Staff Official Announcement</label>
                <textarea name="staff_announcement" id="setting_staff_announcement" class="modern-input"
                  style="min-height:80px; resize:none; font-size:0.85rem;"
                  placeholder="e.g. Mandatory meeting this Saturday at 5PM. All mechanics present."><?php echo htmlspecialchars($tenant_custom['staff_announcement'] ?? ''); ?></textarea>
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('staff_announcement')">
                  <i class="fas fa-satellite-dish"></i> Broadcast Update
                </button>
                <p style="font-size:0.7rem; color:var(--text-dim); margin-top:5px;">This message
                  will
                  appear only to your staff inside the animated pull-down bookmark.</p>
              </div>
            </div>

            <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem;">
              <h4 <i class="fas fa-bullhorn"></i> Broadcast to Team
                </button>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
              <div class="form-group">
                <label>Business Phone</label>
                <input type="text" name="phone" id="setting_phone"
                  value="<?php echo htmlspecialchars($tenant_custom['phone'] ?? ''); ?>" placeholder="e.g. 09123456789"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('phone')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('phone')">
                  <i class="fas fa-save"></i> Save Phone
                </button>
              </div>
              <div class="form-group">
                <label>Business Hours</label>
                <input type="text" name="opening_hours" id="setting_opening_hours"
                  value="<?php echo htmlspecialchars($tenant_custom['opening_hours'] ?? ''); ?>"
                  placeholder="e.g. Mon-Sat 8am-5pm" style="font-size:0.8rem;"
                  onfocus="highlightInPreview('opening_hours')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('opening_hours')">
                  <i class="fas fa-save"></i> Save Hours
                </button>
              </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Business Address</label>
              <textarea name="address" id="setting_address" placeholder="Full physical address of your shop..."
                style="min-height:50px; font-size:0.8rem;"
                onfocus="highlightInPreview('address')"><?php echo htmlspecialchars($tenant_custom['address'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('address')">
                <i class="fas fa-save"></i> Save Address
              </button>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
              <div class="form-group">
                <label><i class="fab fa-facebook"></i> Facebook Page URL</label>
                <input type="text" name="facebook_url" id="setting_facebook_url"
                  value="<?php echo htmlspecialchars($tenant_custom['facebook_url'] ?? ''); ?>"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('facebook_url')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('facebook_url')">
                  <i class="fas fa-save"></i> Save FB
                </button>
              </div>
              <div class="form-group">
                <label><i class="fab fa-instagram"></i> Instagram URL</label>
                <input type="text" name="instagram_url" id="setting_instagram_url"
                  value="<?php echo htmlspecialchars($tenant_custom['instagram_url'] ?? ''); ?>"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('instagram_url')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('instagram_url')">
                  <i class="fas fa-save"></i> Save IG
                </button>
              </div>
            </div>
          </form>

          <!-- Right: Live Preview -->
          <div style="flex: 2.5; position: sticky; top: 20px;">
            <div style="margin-bottom: 12px; display:flex; justify-content:space-between; align-items:center;">
              <h4 id="previewTitleText"
                style="text-transform:uppercase; font-size:0.75rem; letter-spacing:1px; color:var(--text-dim);">Website
                Preview (Desktop)</h4>
              <div style="display:flex; gap:15px; align-items:center;">
                <div
                  style="display:flex; background:rgba(0,0,0,0.3); padding:4px; border-radius:10px; border:1px solid rgba(255,255,255,0.1);">
                  <button type="button" onclick="window.setPreviewSize('desktop')" id="btnViewDesktop"
                    style="background:rgba(255,255,255,0.1); border:none; color:var(--accent); padding:6px 12px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-desktop"></i> <span style="font-size:0.65rem; font-weight:800;">DESKTOP</span>
                  </button>
                  <button type="button" onclick="window.setPreviewSize('mobile')" id="btnViewMobile"
                    style="background:transparent; border:none; color:var(--text-dim); padding:6px 12px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-mobile-alt"></i> <span style="font-size:0.65rem; font-weight:800;">MOBILE</span>
                  </button>
                </div>
                <i class="fas fa-sync" title="Refresh"
                  style="cursor:pointer; color:var(--text-dim); font-size:0.8rem; margin-left:5px;"
                  onclick="document.getElementById('livePreviewFrame').src += ''"></i>
              </div>
            </div>

            <!-- Browser Frame Mockup -->
            <div
              style="background: #1f2937; border-radius: 12px; overflow: hidden; box-shadow: 0 40px 80px -15px rgba(0,0,0,0.8); border: 1px solid rgba(255,255,255,0.1);">
              <div
                style="background: #111827; padding: 10px 15px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                <div style="display: flex; gap: 6px;">
                  <div style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></div>
                  <div style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b;"></div>
                  <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--accent);"></div>
                </div>
                <div
                  style="margin-left: 20px; background: rgba(255,255,255,0.05); border-radius: 6px; padding: 4px 15px; flex: 1; text-align: center; font-size: 0.7rem; color: var(--text-dim); border: 1px solid rgba(255,255,255,0.05); font-family: monospace;">
                  your-shop.com/<?php echo htmlspecialchars($tenant_slug); ?>
                </div>
              </div>

              <div style="height: 650px; background: #030712; position: relative;">
                <iframe id="livePreviewFrame" src="shop.php?id=<?php echo urlencode($tenant_slug); ?>&preview=1"
                  style="width: 100%; height: 100%; border:none;"></iframe>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- SEC 8: Subscription (OWNER only) -->
    <?php if ($role === 'OWNER'): ?>
    <div id="subscription" class="view-section">
      <div class="glass-panel" style="padding: 2.5rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 2.5rem;">
          <div>
            <h2 style="font-size: 2rem; margin-bottom: 0.5rem;">Subscription Management</h2>
            <p style="color:var(--text-dim); font-size:1rem;">Monitor your billing cycle and platform usage
              limits.</p>
          </div>
          <?php if ($active_subscription): ?>
          <button class="btn-gradient" id="renewBtnMain" onclick="renewSubscription(this, event)"
            style="padding: 1rem 2.5rem; border-radius: 18px; position: relative; z-index: 10;">
            <i class="fas fa-bolt"></i> Renew Subscription
          </button>

          <script>
            window.showPayMongoSimulation = function (amount, method, planName, onComplete) {
              const modalId = 'paymongo_' + Date.now();
              const modalHTML = `
                  <div id="${modalId}" style="position:fixed; top:0; left:0; width:100%; height:100%; background:#f4f7f9; z-index:2147483649; display:flex; flex-direction:column; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                    <!-- Header -->
                    <div style="background:white; padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0;">
                      <div style="display:flex; align-items:center; gap:10px;">
                        <div style="background:#6366f1; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:white; font-weight:bold;">P</div>
                        <span style="font-weight:800; font-size:1.2rem; color:#1e293b; letter-spacing:-0.5px;">paymongo</span>
                      </div>
                      <div style="color:#64748b; font-size:0.9rem;">Test Mode</div>
                    </div>

                    <div style="flex:1; display:flex; align-items:center; justify-content:center; padding:2rem;">
                      <div style="background:white; width:100%; max-width:400px; border-radius:20px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden; border:1px solid #e2e8f0;">
                        <div style="padding:2rem; text-align:center; border-bottom:1px solid #f1f5f9; background:#f8fafc;">
                          <div style="color:#64748b; font-size:0.85rem; text-transform:uppercase; font-weight:700; letter-spacing:1px; margin-bottom:0.5rem;">Amount to Pay</div>
                          <div style="font-size:2.5rem; font-weight:900; color:#1e293b;">₱${parseFloat(amount).toLocaleString()}</div>
                          <div style="font-size:0.9rem; color:#64748b; margin-top:0.5rem;">${planName}</div>
                        </div>

                        <div style="padding:2rem;">
                          <div style="margin-bottom:2rem;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:0.8rem; font-size:0.9rem;">
                              <span style="color:#64748b;">Payment Method</span>
                              <span style="font-weight:700; color:#1e293b;">${method}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:0.9rem;">
                              <span style="color:#64748b;">Reference</span>
                              <span style="font-weight:700; color:#1e293b;">PM-${Math.random().toString(36).substr(2, 9).toUpperCase()}</span>
                            </div>
                          </div>

                          <button id="payNow_${modalId}" style="width:100%; padding:1rem; background:#6366f1; color:white; border:none; border-radius:12px; font-weight:700; font-size:1.1rem; cursor:pointer; transition:0.3s; margin-bottom:1rem;">
                            Pay Now with ${method}
                          </button>
                      
                          <button id="cancelPay_${modalId}" style="width:100%; background:none; border:none; color:#94a3b8; font-size:0.9rem; cursor:pointer;">
                            Cancel Transaction
                          </button>
                        </div>
                      </div>
                    </div>

                    <div id="loading_${modalId}" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.95); z-index:10; flex-direction:column; align-items:center; justify-content:center;">
                      <div style="width:50px; height:50px; border:4px solid #f3f3f3; border-top:4px solid #6366f1; border-radius:50%; animation: spin 1s linear infinite; margin-bottom:1.5rem;"></div>
                      <div style="font-weight:700; color:#1e293b; font-size:1.2rem;">Authorizing Payment...</div>
                      <div style="color:#64748b; margin-top:0.5rem;">Please do not close this window</div>
                    </div>

                    <style>
                      @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                    </style>
                  </div>`;
                  document.body.insertAdjacentHTML('beforeend', modalHTML);

                  document.getElementById('payNow_' + modalId).onclick = function () {
                    const loading = document.getElementById('loading_' + modalId);
                    loading.style.display = 'flex';

                    setTimeout(() => {
                      document.getElementById(modalId).remove();
                      onComplete();
                    }, 2500);
                  };

                  document.getElementById('cancelPay_' + modalId).onclick = function () {
                    document.getElementById(modalId).remove();
                  };
                };

                window.renewSubscription = function (btn, e) {
                  console.log("[SUBS] CLICKED");

                  const confirmMsg = "Select your preferred payment method to extend your subscription.";
                  const originalHtml = btn ? btn.innerHTML : '<i class="fas fa-bolt"></i> Renew Subscription';

                  const proceedWithRenewal = (method) => {
                    <?php
                    $r_cycle = strtolower($active_subscription['billing_cycle'] ?? 'monthly');
                    $r_amount = ($r_cycle === 'yearly') ? ($active_subscription['price_yearly'] > 0 ? $active_subscription['price_yearly'] : ($active_subscription['price'] * 12 * 0.8)) : $active_subscription['price'];
                    ?>
                    const amount = "<?php echo $r_amount; ?>";

                    showPayMongoSimulation(amount, method, "Subscription Renewal", () => {
                      if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                      }

                      const fd = new FormData();
                      fd.append('method', method);

                      fetch('tenant-dashboard.php?action=renew_subscription', { method: 'POST', body: fd })
                        .then(res => res.json())
                        .then(data => {
                          if (data.status === 'success') {
                            alert("\u2705 Success! Subscription renewed via " + method);
                            location.reload();
                          } else {
                            alert("\u274C Error: " + data.message);
                            if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
                          }
                        })
                        .catch(err => {
                          alert("\u274C System Error: " + err.message);
                          if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
                        });
                    });
                  };

                  // DYNAMIC MODAL (With Payment Selection)
                  const modalId = 'dynamicRenewModal_' + Date.now();
                  const modalHTML = `
                  <div id="${modalId}" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); backdrop-filter:blur(15px); z-index:9999999; display:flex; align-items:center; justify-content:center; padding:20px;">
                    <div style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:32px; padding:3rem; width:100%; max-width:480px; text-align:center; box-shadow:0 30px 60px rgba(0,0,0,0.8);">
                      <div style="width:80px; height:80px; background:linear-gradient(135deg, #6366f1 0%, #a855f7 100%); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 2rem; color:white; font-size:2.5rem; box-shadow:0 10px 25px rgba(99, 102, 241, 0.4);">
                        <i class="fas fa-credit-card"></i>
                      </div>
                      <h2 style="color:white; margin-bottom:0.8rem; font-size:1.8rem; font-weight:800;">Renew Subscription</h2>
                      <p style="color:#94a3b8; margin-bottom:2rem; line-height:1.6;">${confirmMsg}</p>
                  
                      <div style="text-align:left; margin-bottom:2.5rem;">
                        <label style="color:white; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; display:block; opacity:0.7;">Payment Method</label>
                        <select id="payMethod_${modalId}">
                          <option value="GCASH" style="background:#111827;">GCash</option>
                          <option value="MAYA" style="background:#111827;">Maya</option>
                          <option value="BANK_TRANSFER" style="background:#111827;">Bank Transfer (BDO/BPI)</option>
                          <option value="CARD" style="background:#111827;">Credit/Debit Card</option>
                        </select>
                      </div>

                      <div style="display:flex; gap:15px; justify-content:center;">
                        <button id="btnConfirm_${modalId}" style="flex:2; padding:16px; background:#6366f1; color:white; border:none; border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem; transition:0.3s; box-shadow:0 10px 20px rgba(99, 102, 241, 0.3);">Go to Payment</button>
                        <button id="btnCancel_${modalId}" style="flex:1; padding:16px; background:rgba(255,255,255,0.05); color:white; border:1px solid rgba(255,255,255,0.1); border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem;">Cancel</button>
                      </div>
                    </div>
                  </div>`;
                  document.body.insertAdjacentHTML('beforeend', modalHTML);

                  document.getElementById('btnConfirm_' + modalId).onclick = function () {
                    const selectedMethod = document.getElementById('payMethod_' + modalId).value;
                    document.getElementById(modalId).remove();
                    proceedWithRenewal(selectedMethod);
                  };
                  document.getElementById('btnCancel_' + modalId).onclick = function () {
                    document.getElementById(modalId).remove();
                  };
                };
              </script>
            <?php endif; ?>
          </div>

          <div
            style="background: linear-gradient(135deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.01) 100%); border: 1px solid var(--glass-border); border-radius: 32px; padding: 3rem; position: relative; overflow: hidden;">
            <!-- Decorative Background Glow -->
            <div
              style="position:absolute; top:-100px; left:-100px; width:300px; height:300px; background:var(--accent); filter:blur(150px); opacity:0.1; pointer-events:none;">
            </div>

            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap: 4rem; position: relative; z-index: 1;">
              <!-- Left side: Plan & Expiry -->
              <div>
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:1.5rem;">
                  <span class="badge"
                    style="background:var(--success); color:white; font-size: 0.8rem; padding: 8px 18px; border-radius:100px; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2);">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $active_subscription['status'] ?? 'ACTIVE'; ?>
                  </span>
                  <span style="color:var(--text-dim); font-size:0.9rem; font-weight:600;">Since
                    <?php echo date('M d, Y', strtotime($active_subscription['start_date'] ?? 'today')); ?>
                  </span>
                </div>

                <h1
                  style="font-size: 3.5rem; font-weight: 900; margin-bottom: 1rem; letter-spacing: -2px; background: linear-gradient(to right, #fff, var(--text-dim)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                  <?php echo htmlspecialchars($active_subscription['plan_name'] ?? 'PRO'); ?>
                </h1>

                <div style="margin-bottom: 2rem;">
                  <div style="display:flex; justify-content:space-between; margin-bottom:0.8rem; font-size:0.95rem;">
                    <span style="color:var(--text-dim);">Subscription Progress</span>
                    <?php
                    $start = strtotime($active_subscription['start_date']);
                    $end = strtotime($active_subscription['end_date']);
                    $now = time();
                    $total = $end - $start;
                    $passed = $now - $start;
                    $percent = ($total > 0) ? min(100, max(0, round(($passed / $total) * 100))) : 0;
                    $days_left = round(($end - $now) / (60 * 60 * 24));
                    ?>
                    <span style="font-weight:700;">
                      <?php echo 100 - $percent; ?>% Time Remaining
                    </span>
                  </div>
                  <div
                    style="width:100%; height:12px; background:rgba(255,255,255,0.05); border-radius:100px; overflow:hidden; border:1px solid rgba(255,255,255,0.05);">
                    <div
                      style="width:<?php echo 100 - $percent; ?>%; height:100%; background:linear-gradient(to right, var(--accent), #a855f7); border-radius:100px; box-shadow: 0 0 15px var(--accent-glow);">
                    </div>
                  </div>
                  <p style="margin-top:1rem; color:var(--text-dim); font-size:0.95rem;">
                    Renews on <strong style="color:white;" id="expiryDisplay">
                      <?php echo date('F d, Y', strtotime($active_subscription['end_date'])); ?>
                    </strong>
                    (In
                    <?php echo $days_left; ?> days)
                  </p>
                </div>

                <div style="display:flex; gap:1.2rem; position:relative; z-index:999999;">
                  <button id="btnUpgradeMain" type="button" onclick="window.openUpgradeModal(event);"
                    style="padding: 1.1rem 2.5rem; border-radius:18px; font-weight:800; background: linear-gradient(135deg, #6366f1, #8b5cf6); color:white; border:none; cursor:pointer; font-size:1.1rem; display:inline-flex; align-items:center; gap:10px; position:relative; z-index:2147483647 !important; transition: all 0.3s; box-shadow: 0 10px 30px rgba(99,102,241,0.5); pointer-events: auto !important;">
                    <i class="fas fa-arrow-up"></i> Upgrade Plan
                  </button>
                  <button type="button" onclick="navToView('payments_history')"
                    onmouseover="this.style.background='rgba(255,255,255,0.1)'"
                    onmouseout="this.style.background='transparent'"
                    style="padding: 0.9rem 2rem; border-radius:14px; font-weight:700; background:transparent; color:white; border:1px solid rgba(255,255,255,0.2); cursor:pointer; font-size:1rem; display:inline-flex; align-items:center; gap:8px; position:relative; z-index:99; transition: background 0.2s;">
                    <i class="fas fa-file-invoice-dollar"></i> Billing History
                  </button>
                </div>
              </div>

              <!-- Right side: Quick Stats Cards -->
              <div style="display:flex; flex-direction:column; gap:1.5rem; justify-content:center;">
                <div
                  style="background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); padding:1.8rem; border-radius:24px; display:flex; align-items:center; gap:1.5rem; transition:0.3s; cursor:default;"
                  onmouseover="this.style.background='rgba(255,255,255,0.05)'"
                  onmouseout="this.style.background='rgba(255,255,255,0.03)'">
                  <div
                    style="width:50px; height:50px; background:rgba(99,102,241,0.1); border-radius:14px; display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
                    <i class="fas fa-users"></i>
                  </div>
                  <div>
                    <div style="font-size:1.5rem; font-weight:800;">
                      <?php echo $active_subscription['max_users'] ?? 0; ?>
                    </div>
                    <div
                      style="font-size:0.8rem; color:var(--text-dim); text-transform:uppercase; font-weight:700; letter-spacing:1px;">
                      Team Capacity</div>
                  </div>
                </div>

                <div
                  style="background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); padding:1.8rem; border-radius:24px; display:flex; align-items:center; gap:1.5rem; transition:0.3s; cursor:default;"
                  onmouseover="this.style.background='rgba(255,255,255,0.05)'"
                  onmouseout="this.style.background='rgba(255,255,255,0.03)'">
                  <div
                    style="width:50px; height:50px; background:rgba(16,185,129,0.1); border-radius:14px; display:flex; align-items:center; justify-content:center; color:var(--success); font-size:1.2rem;">
                    <i class="fas fa-car"></i>
                  </div>
                  <div>
                    <div style="font-size:1.5rem; font-weight:800;">
                      <?php echo $active_subscription['max_service_bays'] ?? 0; ?>
                    </div>
                    <div
                      style="font-size:0.8rem; color:var(--text-dim); text-transform:uppercase; font-weight:700; letter-spacing:1px;">
                      Service Bays</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>


    <?php endif; ?>

    <!-- SEC 8.5: Billing History (OWNER only) -->
    <?php if ($role === 'OWNER'): ?>
      <div id="payments_history" class="view-section">
        <div class="glass-panel">
          <div style="display:flex; align-items:center; gap:2rem; margin-bottom:2rem;">
            <button class="btn-outline" onclick="navToView('subscription')" style="padding: 0.6rem 1.2rem;">
              <i class="fas fa-arrow-left"></i> Back
            </button>
            <div>
              <h3 style="margin:0;">Billing & Payment History</h3>
              <p style="color:var(--text-dim); font-size:0.9rem; margin:0;">Review your past subscription
                renewals and transactions.</p>
            </div>
          </div>

          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Reference</th>
                  <th>Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="billingHistoryTableBody">
                <tr>
                  <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-dim);">Loading
                    history...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- SEC 9: Audit Trail (OWNER only) -->
    <?php if ($role === 'OWNER'): ?>
      <div id="customer_logs" class="view-section">
        <div class="glass-panel">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
            <div>
              <h3>Shop Audit Trail</h3>
              <p style="color:var(--text-dim); font-size:0.9rem;">Complete history of authentication, CRUD
                operations, and system events.</p>
            </div>
            <button class="btn-outline" onclick="loadAuditLogs()"><i class="fas fa-sync"></i> Refresh
              Logs</button>
          </div>

          <div style="overflow-x:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Timestamp</th>
                  <th>User / Actor</th>
                  <th>Type</th>
                  <th>Activity Description</th>
                </tr>
              </thead>
              <tbody id="auditLogsTableBody">
                <tr>
                  <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-dim);">Click
                    refresh to load logs...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?> <!-- END AUDIT LOGS -->

    <!-- Mechanic Work History Section -->
    <section id="mechanic_history" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
          <h2 style="font-size:1.8rem; font-weight:800;">My Work History</h2>
          <span style="font-size:0.8rem; color:var(--text-dim);">Last 50 entries</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Date/Time</th>
                <th style="min-width:200px;">Vehicle</th>
                <th>Status Update</th>
                <th>Work Remarks</th>
              </tr>
            </thead>
            <tbody id="mechanicHistoryTable">
              <tr>
                <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-dim);">Loading
                  your work history...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Mechanic Inventory Lookup Section -->
    <section id="inventory_lookup" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
          <h2 style="font-size:1.8rem; font-weight:800;">Parts Catalog</h2>
          <input type="text" class="search-input" style="max-width:300px; margin-bottom:0;"
            placeholder="Search parts..." onkeyup="filterInventoryLookup(this.value)">
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Part Name</th>
                <th>Brand</th>
                <th>In Stock</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="inventoryLookupTable">
              <tr>
                <td colspan="4" style="text-align:center; padding:3rem; color:var(--text-dim);">Loading
                  inventory list...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Assign Vehicle to Bay Modal (Premium Glassmorphism Overhaul) -->
    <div id="assignBayModal"
      style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(25px); z-index:999999; align-items:center; justify-content:center; animation: fadeInModal 0.4s ease-out;">
      <div
        style="background:rgba(15,23,42,0.8); border:1px solid rgba(255,255,255,0.12); border-radius:32px; padding:3rem; width:100%; max-width:550px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.8); position:relative; overflow:hidden;">
        <!-- Glow background effect -->
        <div
          style="position:absolute; top:-100px; right:-100px; width:300px; height:300px; background:var(--accent); opacity:0.1; filter:blur(100px); pointer-events:none;">
        </div>

        <div
          style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2.5rem; position:relative; z-index:2;">
          <div>
            <h3 id="assignBayTitle" style="margin:0; font-size:1.6rem; font-weight:800; letter-spacing:-0.5px;">
              Initialize
              Repair</h3>
            <p style="color:var(--text-dim); margin-top:5px; font-size:0.9rem;">Configure slot allocation
              for this service bay.</p>
          </div>
          <button onclick="document.getElementById('assignBayModal').style.display='none'"
            style="width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
            onmouseover="this.style.background='rgba(255,0,0,0.1)'"
            onmouseout="this.style.background='rgba(255,255,255,0.05)'">&times;</button>
        </div>

        <form id="assignBayForm" style="position:relative; z-index:2;">
          <input type="hidden" name="bay_id" id="assign_bay_id">

          <div style="margin-bottom:1.5rem;">
            <label
              style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px;">1.
              Client Machine</label>
            <div
              style="position:relative; background:#0f172a !important; border-radius:15px; border:1px solid rgba(255,255,255,0.1); min-height:55px; transition:0.3s; display:flex; align-items:center;">
              <i class="fas fa-car" style="position:absolute; left:1.2rem; color:var(--accent); z-index:10;"></i>
              <select name="vehicle_id" id="assign_vehicle_id" required></select>
              <i class="fas fa-chevron-down"
                style="position:absolute; right:1.2rem; color:rgba(255,255,255,0.5); font-size:0.8rem; pointer-events:none; z-index:10;"></i>
            </div>
          </div>

          <div style="margin-bottom:1.5rem;">
            <label
              style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px;">2.
              Repair Package</label>
            <div
              style="position:relative; background:#0f172a !important; border-radius:15px; border:1px solid rgba(255,255,255,0.1); min-height:55px; transition:0.3s; display:flex; align-items:center;">
              <i class="fas fa-tools" style="position:absolute; left:1.2rem; color:var(--accent); z-index:10;"></i>
              <select name="service_id" id="assign_service_id" required></select>
              <i class="fas fa-chevron-down"
                style="position:absolute; right:1.2rem; color:rgba(255,255,255,0.5); font-size:0.8rem; pointer-events:none; z-index:10;"></i>
            </div>
          </div>

          <div style="margin-bottom:2.5rem;">
            <label
              style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px;">3.
              Expert Assigned</label>
            <div
              style="position:relative; background:#0f172a !important; border-radius:15px; border:1px solid rgba(255,255,255,0.1); min-height:55px; transition:0.3s; display:flex; align-items:center;">
              <i class="fas fa-user-cog" style="position:absolute; left:1.2rem; color:var(--accent); z-index:10;"></i>
              <select name="mechanic_id" id="assign_mechanic_id"></select>
              <i class="fas fa-chevron-down"
                style="position:absolute; right:1.2rem; color:rgba(255,255,255,0.5); font-size:0.8rem; pointer-events:none; z-index:10;"></i>
            </div>
          </div>

          <button type="button" onclick="processBayAssignment()"
            style="width:100%; background:var(--accent); color:white; border:none; padding:1.2rem; border-radius:18px; font-weight:800; font-size:1.1rem; cursor:pointer; box-shadow:0 20px 40px var(--accent-glow); transition:0.3s; display:flex; align-items:center; justify-content:center; gap:12px;"
            onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 25px 50px var(--accent-glow)';"
            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 20px 40px var(--accent-glow)';">
            <i class="fas fa-bolt"></i> Establish Operational Flow
          </button>
        </form>
      </div>
    </div>


    <style>
      @keyframes fadeInModal {
        from {
          opacity: 0;
          transform: scale(0.95);
        }

        to {
          opacity: 1;
          transform: scale(1);
        }
      }
    </style>

    <!-- Management-Style Profile View -->
    <div id="my_profile" class="view-section" style="display: none; width: 100%; padding-top: 2rem;">
      <div class="glass-panel" style="padding: 3rem; width: 100%;">
        <!-- Header Area (Matching Staff Management) -->
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 3.5rem;">
          <div>
            <h2 style="margin: 0; font-size: 1.5rem; font-weight: 800; color: #fff;">Account Profile</h2>
            <p style="margin: 5px 0 0; color: var(--text-dim); font-size: 0.9rem;">Management of your personal presence
              and system roles.</p>
          </div>
          <div style="display: flex; gap: 10px;">
            <div
              style="padding: 8px 20px; background: rgba(var(--accent-rgb), 0.1); border: 1px solid rgba(var(--accent-rgb), 0.2); border-radius: 100px; color: var(--accent); font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">
              System Active
            </div>
          </div>
        </div>

        <!-- Content Area -->
        <div style="display: grid; grid-template-columns: 280px 1fr; gap: 4rem; align-items: center;">
          <!-- Left: Avatar Focus -->
          <div style="text-align: center; border-right: 1px solid rgba(255,255,255,0.05); padding-right: 4rem;">
            <div style="position: relative; width: 180px; height: 180px; margin: 0 auto 1.5rem;">
              <div
                style="width: 100%; height: 100%; border-radius: 40px; overflow: hidden; border: 4px solid rgba(var(--accent-rgb), 0.2); box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <?php if (!empty($current_user_pic)): ?>
                  <img src="<?php echo htmlspecialchars($current_user_pic); ?>"
                    style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                  <div
                    style="width: 100%; height: 100%; background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.1), transparent); display: flex; align-items: center; justify-content: center; font-size: 5rem; color: var(--accent); font-weight: 900;">
                    <?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?>
                  </div>
                <?php endif; ?>
              </div>
              <button onclick="document.getElementById('profile_pic_input_view').click()"
                style="position: absolute; bottom: -10px; right: -10px; width: 50px; height: 50px; border-radius: 15px; background: #fff; color: #000; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; box-shadow: 0 10px 20px rgba(0,0,0,0.2); transition: 0.3s;">
                <i class="fas fa-camera"></i>
              </button>
            </div>
            <h3 style="margin: 0; font-size: 1.4rem; font-weight: 700; color: #fff;">
              <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></h3>
            <p
              style="margin: 5px 0 0; color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px; font-weight: 800;">
              Member since 2024</p>
          </div>

          <!-- Right: Details List -->
          <div style="display: grid; gap: 2.5rem;">
            <div style="display: grid; grid-template-columns: 200px 1fr; align-items: center;">
              <span
                style="color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px;">Display
                Name</span>
              <span
                style="font-size: 1.2rem; font-weight: 600; color: #fff;"><?php echo htmlspecialchars($_SESSION['name'] ?? 'N/A'); ?></span>
            </div>

            <div style="display: grid; grid-template-columns: 200px 1fr; align-items: center;">
              <span
                style="color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px;">Security
                Role</span>
              <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.2rem; font-weight: 600; color: #fff;"><?php echo $role; ?></span>
                <i class="fas fa-shield-check" style="color: var(--accent); font-size: 1.1rem;"></i>
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 200px 1fr; align-items: center;">
              <span
                style="color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px;">Workshop
                ID</span>
              <span
                style="font-size: 1.1rem; font-weight: 600; color: var(--text-dim); font-family: monospace; letter-spacing: 1px;">#<?php echo str_pad($_SESSION['user_id'] ?? '0', 6, '0', STR_PAD_LEFT); ?></span>
            </div>

            <div
              style="margin-top: 1rem; padding: 1.5rem; background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius: 15px; display: flex; align-items: center; gap: 15px; color: var(--text-dim); font-size: 0.85rem;">
              <i class="fas fa-info-circle" style="color: var(--accent);"></i>
              To modify these details, please contact your System Administrator.
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- CONSOLIDATED DASHBOARD ENGINE -->
  <script>
    // Initialize Session Variables from PHP
    const userRole = '<?php echo $role; ?>';
    const currentUserId = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;

    // DEBUG: CLICK SNIFFER - To find out what's blocking you
    window.onclick = function (e) {
      console.log("CLICKED ELEMENT:", e.target);
      // If the user can't see the console, we'll alert it for 1 session only
      if (!window.hasAlertedClick) {
        // alert("Debug: You clicked on a <" + e.target.tagName + "> element with ID: " + (e.target.id || "None"));
        // window.hasAlertedClick = true;
      }
    };

    // --- GLOBAL UI HELPERS ---
    function getUrl(action) {
      return 'tenant-dashboard.php?action=' + action + '&_v=' + Date.now();
    }




    function updateAppointmentStatus(id, status) {
      const action = () => {
        const fd = new FormData();
        fd.append('appointment_id', id);
        fd.append('status', status);
        fetch(getUrl('update_appointment_status'), { method: 'POST', body: fd })
          .then(res => res.json())
          .then(data => {
            if (data.status === 'success') {
              showToast(`Appointment marked as ${status}`);
              if (typeof refreshAppointmentsList === 'function') refreshAppointmentsList();
            } else showAlert('Error', data.message, 'error');
          });
      };

      if (status === 'CANCELLED') {
        showConfirm('Cancel Booking', 'Are you sure you want to cancel this appointment?', action);
      } else {
        action();
      }
    }

    window.refreshStaffList = function () {
      fetch(getUrl('fetch_staff'))
        .then(res => res.text())
        .then(text => {
          try {
            const jsonMatch = text.match(/\[.*\]/s);
            if (!jsonMatch) throw new Error("No JSON found");
            const data = JSON.parse(jsonMatch[0]);
            const body = document.getElementById('staffBody');
            if (!body) return;

            if (data.length === 0) {
              body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No staff accounts found.</td></tr>`;
              return;
            }

            body.innerHTML = data.map(s => {
              const isSelf = (s.user_id == currentUserId);
              const isTargetOwner = (s.role_name && s.role_name.toUpperCase() === 'OWNER');
              // Managers cannot manage Owners
              const cannotManage = isSelf || (userRole.toUpperCase() === 'MANAGER' && isTargetOwner);

              return `
              <tr>
                <td><strong>${s.name}</strong></td>
                <td>${s.email}</td>
                <td><span class="badge badge-active" style="font-size:0.75rem; letter-spacing:1px;">${s.role_name || 'STAFF'}</span></td>
                <td><span class="badge ${s.status === 'ACTIVE' ? 'badge-active' : 'badge-danger'}">${s.status || 'ACTIVE'}</span></td>
                <td>
                  <button class="btn-outline staff-manage-btn" 
                    style="display:inline-block; padding:8px 16px; font-size:0.75rem; border-radius:10px; border:2px solid var(--accent) !important; color:#000 !important; background:var(--accent) !important; position:relative; z-index:9999 !important; pointer-events:auto !important; cursor:pointer !important; font-weight:800; box-shadow:0 0 15px var(--accent-glow); ${cannotManage ? 'opacity:0.4; filter:grayscale(1); pointer-events:none !important;' : ''}"
                    onclick="event.stopPropagation(); window.openStaffManageModal(${s.user_id});">
                    ${isSelf ? 'You' : (isTargetOwner ? 'Owner' : 'Manage')}
                  </button>
                </td>
              </tr>`;
            }).join('');
          } catch (e) {
            console.error("Staff Refresh Scrub Failed:", text);
            body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger);">Error rendering staff list.</td></tr>`;
          }
        });
    }



    window.updateStaffStatus = function (userId) {
      const status = document.getElementById('staff_manage_status').value;
      const fd = new FormData();
      fd.append('user_id', userId);
      fd.append('status', status);

      fetch('tenant-dashboard.php?action=update_staff_status', {
        method: 'POST',
        body: fd
      }).then(r => r.json()).then(res => {
        if (res.status === 'success') {
          showAlert('Success', 'Staff access updated successfully.', 'success');
          closeModal('staffManageModal');
          refreshStaffList();
        } else {
          showAlert('Error', res.message, 'error');
        }
      });
    };

    function refreshBaysList() {
      fetch(getUrl('fetch_bays'))
        .then(res => res.json()).then(data => {
          const grid = document.getElementById('baysGrid');
          if (!grid) return;
          if (data.length === 0) { grid.innerHTML = '<p style="color:var(--text-dim);">No service bays registered.</p>'; return; }
          grid.innerHTML = data.map(bay => {
            const isAvail = bay.status === 'AVAILABLE';
            const action = isAvail ? `openBayProfile(${bay.bay_id})` : `viewJobInBay(${bay.active_job_id}, '${bay.job_status}', ${bay.active_mechanic_id || 'null'}, ${bay.bay_id})`;
            return `
            <div class="bay-card" style="border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); padding:2.2rem 2rem; border-radius:28px; position:relative; overflow:hidden;">
              <div style="position:absolute; top:-30px; right:-30px; width:120px; height:120px; background:${isAvail ? 'var(--accent)' : '#ef4444'}; opacity:0.05; filter:blur(40px);"></div>
              <span class="badge ${isAvail ? 'badge-active' : ''}" style="${!isAvail ? 'background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2);' : ''} font-weight:800;">
                <i class="fas fa-${isAvail ? 'check-circle' : 'tools'}"></i> ${bay.status}
              </span>
              <h2 style="margin:1.2rem 0 0.5rem; font-size:1.8rem; font-weight:900; letter-spacing:-1px;">${bay.bay_name}</h2>
              <button class="btn-action" style="width:100%; margin-top:1.5rem; background:${isAvail ? 'var(--accent)' : 'rgba(239,68,68,0.1)'}; color:${isAvail ? 'white' : '#ef4444'}; border:1px solid ${isAvail ? 'transparent' : 'rgba(239,68,68,0.3)'}; padding:1rem; border-radius:15px; font-weight:800; cursor:pointer; transition:all 0.4s; box-shadow:${isAvail ? '0 8px 20px rgba(var(--accent-rgb), 0.2)' : 'none'}; display:flex; align-items:center; justify-content:center; gap:10px;" 
                  onmouseover="this.style.transform='translateY(-3px)'; ${isAvail ? '' : "this.style.background='#ef4444'; this.style.color='white';"}"
    onmouseout = "this.style.transform='translateY(0)'; ${isAvail ? '' : "this.style.background = 'rgba(239,68,68,0.1)'; this.style.color = '#ef4444'; "}"
    onclick = "${action}" >
      ${isAvail ? '<i class="fas fa-eye"></i> View Bay Profile' : '<i class="fas fa-tools"></i> Repair Details'}
              </button >
            </div>`;
          }).join('');
        });
    }

    function viewJobInBay(jobId, status, mechId, bayId) {
      if (!jobId) {
        alert("Could not locate active job for this bay. Please refresh.");
        return;
      }
      if (window.openJobStatusModal) {
        window.openJobStatusModal(jobId, status, mechId, bayId);
      }
    }
    console.log("SYNC: Refreshing overview metrics...");
    fetch(`tenant-dashboard.php?action=fetch_overview_stats&_=${Date.now()} `)
      .then(res => res.text())
      .then(text => {
        try {
          const jsonMatch = text.match(/\{.*\}/s);
          if (!jsonMatch) throw new Error("No JSON in response");
          const data = JSON.parse(jsonMatch[0]);

          const pj = document.getElementById('stat-pending-jobs');
          const ab = document.getElementById('stat-avail-bays');
          const rv = document.getElementById('stat-revenue');
          const at = document.getElementById('stat-appointments-today');

          if (pj && data.pending_jobs !== undefined) {
            pj.innerHTML = `${parseInt(data.pending_jobs).toLocaleString()} <i class="fas fa-car-crash"
      style="color:var(--warning); font-size:1.4rem;"></i>`;
          }
          if (ab && data.avail_bays !== undefined) {
            ab.innerHTML = `${parseInt(data.avail_bays).toLocaleString()} <i class="fas fa-warehouse"
      style="color:var(--accent); font-size:1.4rem;"></i>`;
          }
          if (rv && data.revenue !== undefined) {
            rv.innerHTML = `₱${parseFloat(data.revenue).toLocaleString(undefined, { minimumFractionDigits: 2 })} <i
      class="fas fa-coins" style="color:#fcd34d; font-size:1.4rem;"></i>`;
          }
          console.log("SYNC: Metrics updated successfully.");
        } catch (e) {
          console.error("Dashboard Stats Sync Failed:", e, text);
        }
      })
      .catch(err => console.error("Stats fetch network error:", err));
      };
    // Auto-refresh stats every 30 seconds for a truly "live" feel
    setInterval(window.dashboardOverviewRefresh, 30000);

    let currentReceiptData = null;
    function printReceipt(id) {
      fetch('tenant-dashboard.php?action=fetch_receipt_details&payment_id=' + id)
        .then(res => res.json()).then(p => {
          if (p.error) { showAlert("Error", "Error fetching receipt details.", "error"); return; }
          currentReceiptData = p;
          const body = document.getElementById('receiptPreviewContent');
          body.innerHTML = `
        <div style="text-align:center; margin-bottom:1.5rem;">
        <div
          style="width:60px; height:60px; background:#f3f4f6; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1rem;">
          <i class="fas fa-car-burst" style="font-size:1.8rem; color:#6366f1;"></i>
        </div>
        <h2 style="margin:0; letter-spacing:-1px; color:#111827;"><?php echo strtoupper($shop_name); ?></h2>
        <p style="color:#64748b; font-size:0.85rem; margin:5px 0;">Official Payment Receipt</p>
      </div>

      <div
        style="border-top:2px dashed #e2e8f0; border-bottom:2px dashed #e2e8f0; padding:1.5rem 0; margin-bottom:1.5rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:0.9rem;">
          <span style="color:#64748b;">Transaction ID</span>
          <span style="font-weight:700; color:#111827;">#PY-${id}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:0.9rem;">
          <span style="color:#64748b;">Customer</span>
          <span style="font-weight:600; color:#111827;">${p.full_name}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:0.9rem;">
          <span style="color:#64748b;">Method</span>
          <span style="font-weight:600; color:#111827;">${p.payment_method}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:0.9rem;">
          <span style="color:#64748b;">Reference</span>
          <span style="font-weight:600; color:#111827;">${p.reference_no || '---'}</span>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:0.9rem;">
          <span style="color:#64748b;">Date</span>
          <span style="font-weight:600; color:#111827;">${new Date(p.payment_date).toLocaleString()}</span>
        </div>
      </div>

      <div
        style="background:#f8fafc; border-radius:8px; padding:1.2rem; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:700; color:#64748b; font-size:0.85rem;">TOTAL PAID</span>
        <span
          style="font-size:1.5rem; font-weight:800; color:#6366f1;">₱${parseFloat(p.amount).toLocaleString(undefined,
            { minimumFractionDigits: 2 })}</span>
      </div>

      <p style="text-align:center; color:#94a3b8; font-size:0.75rem; margin-top:1.5rem;">
        This document serves as your official proof of payment. <br> Thank you for your business!
      </p>
    `;
          openModal('receiptModal');
        });
    }

    function executeThermalPrint() {
      if (!currentReceiptData) return;
      const p = currentReceiptData;
      const id = p.payment_id;
      const printWindow = window.open('', '_blank', 'width=400,height=600');
      printWindow.document.write(`
      < html >

      <head>
        <title>Receipt #PY-${id}</title>
        <style>
          body {
            font-family: 'Courier New', Courier, monospace;
            padding: 20px;
            color: #000;
            font-size: 13px;
            line-height: 1.2;
            width: 300px;
          }

          .text-center {
            text-align: center;
          }

          .separator {
            border-top: 1px dashed #000;
            margin: 10px 0;
          }

          h2 {
            margin: 5px 0;
          }
        </style>
      </head>

      <body onload="window.print(); window.close();">
        <div class="text-center">
          <h2><?php echo strtoupper($shop_name); ?></h2>
          <p><?php echo date('Y-m-d H:i'); ?></p>
        </div>
        <div class="separator"></div>
        <p>RECEIPT NO: PY-${id}</p>
        <p>CUSTOMER : ${p.full_name}</p>
        <p>METHOD : ${p.payment_method}</p>
        <p>REFERENCE : ${p.reference_no || '---'}</p>
        <div class="separator"></div>
        <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:16px;">
          <span>TOTAL:</span>
          <span>PHP ${parseFloat(p.amount).toFixed(2)}</span>
        </div>
        <div class="separator"></div>
        <p class="text-center" style="margin-top:20px;">Safe travels with AutoFix!</p>
      </body>

      </html >
      `);
      printWindow.document.close();
    }

    function approvePayment(id) {
      showConfirm("Approve Payment", "Confirm this transaction? This will officially record the payment and update the workshop backlog.", () => {
        const formData = new FormData();
        formData.append('payment_id', id);
        fetch('tenant-dashboard.php?action=approve_payment', { method: 'POST', body: formData })
          .then(res => res.json()).then(data => {
            if (data.status === 'success') {
              showToast(data.message);
              refreshPaymentsList();
            } else showAlert("Error", data.message, "error");
          });
      });
    }

    function toggleVehicleGroup(ownerId) {
      const rows = document.querySelectorAll(`.v-group-${ownerId}`);
      const header = document.querySelector(`.v-header-${ownerId}`);
      const isHidden = rows[0].style.display === 'none' || rows[0].style.display === '';

      rows.forEach(r => r.style.display = isHidden ? 'table-row' : 'none');
      if (isHidden) header.classList.add('expanded');
      else header.classList.remove('expanded');
    }



    function refreshInventoryList() {
      fetch('tenant-dashboard.php?action=fetch_inventory')
        .then(res => res.json()).then(data => {
          const body = document.getElementById('inventoryBody');
          if (data.length === 0) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-dim); padding: 2rem;">No inventory records found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(i => `
      <tr>
        <td><strong>${i.item_code}</strong></td>
        <td>${i.item_name} ${i.brand ? '(' + i.brand + ')' : ''}</td>
        <td>${i.quantity}</td>
        <td><span class="badge badge-active">${i.status}</span></td>
        <td><button class="btn-outline">Manage</button></td>
      </tr>
      `).join('');
        });
    }

    // searchTable moved to end of file for priority execution

    // searchTable moved to end of file for priority execution

    // showReport moved to end of file for global stability




    // Initialize Everything on Page Load
    document.addEventListener('DOMContentLoaded', () => {
      const runSafe = (fn) => {
        if (typeof window[fn] === 'function') {
          console.log("[RUN] Calling " + fn);
          window[fn]();
        } else if (typeof fn === 'function') {
          fn();
        } else {
          console.warn("[RUN] Skipping " + fn + " (Not found)");
        }
      };

      // Staggered loading for InfinityFree / Shared Hosting stability
      setTimeout(() => runSafe('refreshServicesList'), 50);
      setTimeout(() => runSafe('refreshStaffList'), 400);
      setTimeout(() => runSafe('refreshBaysList'), 800);
      setTimeout(() => runSafe('refreshMechanicsList'), 1200);
      setTimeout(() => runSafe('refreshAppointmentsList'), 1600);
      setTimeout(() => runSafe('refreshUnpaidAppointments'), 2000);
      setTimeout(() => runSafe('refreshPaymentsList'), 2400);
      setTimeout(() => runSafe('refreshVehiclesList'), 2800);
      setTimeout(() => runSafe('refreshAddCustomerList'), 3200);
      setTimeout(() => runSafe('refreshInventoryList'), 3600);
      setTimeout(() => runSafe('refreshJobOrders'), 4000);

      setTimeout(() => {
        if (typeof dashboardOverviewRefresh === 'function') dashboardOverviewRefresh();
      }, 4500);


      // Removed form binding from here to move to the very end for absolute reliability

      // Form binding loop moved to bottom
    });

    function openAssignBayModal(bayId, bayName) {
      document.getElementById('assign_bay_id').value = bayId;
      document.getElementById('assignBayTitle').innerText = `Assign Vehicle to ${bayName} `;

      // Show loading states
      const vS = document.getElementById('assign_vehicle_id');
      const sS = document.getElementById('assign_service_id');
      const mS = document.getElementById('assign_mechanic_id');

      vS.innerHTML = '<option>Loading vehicles...</option>';
      sS.innerHTML = '<option>Loading services...</option>';
      mS.innerHTML = '<option>Loading staff...</option>';

      openModal('assignBayModal');

      // Fetch Vehicles
      fetch('tenant-dashboard.php?action=fetch_vehicles')
        .then(res => res.json()).then(data => {
          vS.innerHTML = '<option value="">-- Select Vehicle --</option>';
          data.forEach(v => {
            vS.innerHTML += `<option value="${v.vehicle_id}">${v.plate_no} (${v.make} ${v.model}) - ${v.owner_name}</option>`;
          });
        });

      // Fetch Services
      fetch('tenant-dashboard.php?action=fetch_services')
        .then(res => res.json()).then(data => {
          sS.innerHTML = '<option value="">-- Select Service --</option>';
          data.forEach(s => {
            sS.innerHTML += `<option value="${s.service_id}">${s.service_name} (₱${parseFloat(s.price).toLocaleString()})</option>`;
          });
        });

      // Fetch Mechanics
      fetch('tenant-dashboard.php?action=fetch_available_resources')
        .then(res => res.json())
        .then(data => {
          mS.innerHTML = '<option value="">-- Assign Mechanic --</option>';
          if (!data.mechanics || data.mechanics.length === 0) {
            mS.innerHTML = '<option value="">No mechanics registered</option>';
            return;
          }
          data.mechanics.forEach(m => {
            mS.innerHTML += `<option value="${m.mechanic_id}">${m.full_name} (${m.specialization || 'General'})</option>`;
          });
        })
        .catch(err => {
          console.error("Mechanic fetch failed:", err);
          mS.innerHTML = '<option value="">Error loading staff</option>';
        });
    }

    function processBayAssignment() {
      const form = document.getElementById('assignBayForm');
      const fd = new FormData(form);
      const btn = form.querySelector('button');
      const originalText = btn.innerText;

      btn.disabled = true;
      btn.innerText = "Initializing...";

      fetch('tenant-dashboard.php?action=assign_bay_job', { method: 'POST', body: fd })
        .then(res => res.text())
        .then(text => {
          try {
            const jsonMatch = text.match(/\{.*\}/s);
            if (!jsonMatch) throw new Error("No JSON found");
            const data = JSON.parse(jsonMatch[0]);

            if (data.status === 'success') {
              alert(data.message);
              closeModal('assignBayModal');
              refreshBaysList();
              refreshJobOrders();
              if (typeof dashboardOverviewRefresh === 'function') dashboardOverviewRefresh();
            } else {
              alert("Operation Error: " + data.message);
            }
          } catch (e) {
            console.error("Bay Assign Scrub Failed:", text);
            if (text.includes('"status":"success"')) {
              alert("Vehicle Assigned Successfully!");
              location.reload();
            } else {
              alert("System Parsing Error. Check console.");
            }
          }
        })
        .catch(err => alert("Network Error: Could not reach server."))
        .finally(() => {
          btn.disabled = false;
          btn.innerText = originalText;
        });
    }

    // Logout handles via inline onclick now

    // REAL PREVIEW SYNC
    document.addEventListener('DOMContentLoaded', () => {
      const frame = document.getElementById('livePreviewFrame');
      const customizationForm = document.getElementById('customizationForm');

      window.setPreviewSize = function (type) {
        const frame = document.getElementById('livePreviewFrame');
        const container = frame.parentElement;
        const title = document.getElementById('previewTitleText');
        const btnDesktop = document.getElementById('btnViewDesktop');
        const btnMobile = document.getElementById('btnViewMobile');

        if (type === 'mobile') {
          frame.style.width = '375px';
          frame.style.margin = '0 auto';
          title.innerText = 'Website Preview (Mobile)';
          btnMobile.style.background = 'rgba(255,255,255,0.1)';
          btnMobile.style.color = 'var(--accent)';
          btnDesktop.style.background = 'transparent';
          btnDesktop.style.color = 'var(--text-dim)';
        } else {
          frame.style.width = '100%';
          frame.style.margin = '0';
          title.innerText = 'Website Preview (Desktop)';
          btnDesktop.style.background = 'rgba(255,255,255,0.1)';
          btnDesktop.style.color = 'var(--accent)';
          btnMobile.style.background = 'transparent';
          btnMobile.style.color = 'var(--text-dim)';
        }
      };

      const hexToRgb = (hex) => {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)} ` : "16, 185, 129";
      };

      const syncPreview = () => {
        const doc = frame.contentDocument || frame.contentWindow.document;

        // Sync Colors
        const accent = document.getElementById('prev_accent').value;
        const bg = document.getElementById('prev_bg').value;
        const accentRgb = hexToRgb(accent);
        const bgRgb = hexToRgb(bg);

        // Apply to Main Dashboard UI
        document.documentElement.style.setProperty('--accent', accent);
        document.documentElement.style.setProperty('--bg-deep', bg);
        document.documentElement.style.setProperty('--accent-rgb', accentRgb);
        document.documentElement.style.setProperty('--bg-rgb', bgRgb);

        if (!doc || !doc.documentElement) return;

        // Apply to Iframe Preview
        doc.documentElement.style.setProperty('--accent', accent);
        doc.documentElement.style.setProperty('--bg-deep', bg);
        doc.documentElement.style.setProperty('--accent-rgb', accentRgb);
        doc.documentElement.style.setProperty('--bg-rgb', bgRgb);

        // Sync Text Elements
        const map = {
          'prev_shop_name': '.logo span',
          'prev_hero_title': '.hero h1',
          'prev_hero_sub': '.hero p'
        };

        Object.entries(map).forEach(([inputId, selector]) => {
          const el = document.getElementById(inputId);
          if (!el) return;
          const val = el.value;
          const targets = doc.querySelectorAll(selector);
          targets.forEach(t => {
            if (selector === '.hero h1' && t.querySelector('span')) {
              // Keep the span structure if possible
              t.innerHTML = val.replace(/([^\s]+)$/, '<span>$1</span>');
            } else {
              t.innerText = val;
            }
          });
        });
      };

      customizationForm.addEventListener('input', (e) => {
        syncPreview();

        // If style or major setting changes, cache-bust the iframe
        if (e.target.name === 'ui_style') {
          const frame = document.getElementById('livePreviewFrame');
          if (frame) {
            const url = new URL(frame.src, window.location.href);
            url.searchParams.set('_v', Date.now());
            frame.src = url.toString();
          }
        }
      });

      // Handle Local Image Preview in Iframe
      document.getElementById('prev_logo_file').addEventListener('change', function (e) {
        if (this.files && this.files[0]) {
          const reader = new FileReader();
          reader.onload = (ex) => {
            const doc = frame.contentDocument || frame.contentWindow.document;
            const img = doc.querySelector('.logo img');
            if (img) img.src = ex.target.result;
          };
          reader.readAsDataURL(this.files[0]);
        }
      });

      document.getElementById('prev_banner_file').addEventListener('change', function (e) {
        if (this.files && this.files[0]) {
          const reader = new FileReader();
          reader.onload = (ex) => {
            const doc = frame.contentDocument || frame.contentWindow.document;
            const hero = doc.querySelector('.hero');
            if (hero) hero.style.backgroundImage = `linear-gradient(to bottom, rgba(0, 0, 0, 0.5), transparent),
      url(${ex.target.result})`;
          };
          reader.readAsDataURL(this.files[0]);
        }
      });

      // Re-sync after iframe loads
      frame.onload = syncPreview;
    });
  </script>

  <!-- ============================================================ -->
  <!-- MODALS — placed here at end of body, outside all containers -->
  <!-- This ensures position:fixed works correctly          -->
  <!-- ============================================================ -->

  <!-- Mechanic Work Log Modal -->
  <div id="workLogModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:999999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2rem; width:100%; max-width:600px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6); max-height:80vh; overflow:hidden; display:flex; flex-direction:column;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">My Work Updates</h3>
        <button onclick="closeModal('workLogModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="workLogContainer" style="flex:1; overflow-y:auto; padding-right:10px;">
        <p style="text-align:center; color:var(--text-dim); padding:2rem;">Loading logs...</p>
      </div>
    </div>
  </div>

  <!-- Service Modal -->
  <div id="serviceModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Add New Service</h3>
        <button onclick="closeModal('serviceModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addServiceForm" method="POST" action="tenant-dashboard.php?action=add_service">
        <input type="hidden" name="service_action" value="add_service">
        <div style="margin-bottom:1.2rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--accent); font-weight:700;">Standard Service (Admin Regulated)</label>
          <select name="master_id" onchange="window.syncMasterService(this, 'addServiceForm')"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid var(--accent); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
            <option value="">-- Custom / Not in List --</option>
            <?php
            try {
              $m_stmt = $db->query("SELECT * FROM master_services ORDER BY service_name ASC");
              while ($ms = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
                $json = htmlspecialchars(json_encode($ms));
                echo "<option value='{$ms['master_id']}' data-info='{$json}'>{$ms['service_name']}</option>";
              }
            } catch(Exception $e) {}
            ?>
          </select>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Service Name</label>
          <input type="text" name="service_name" required placeholder="e.g. Engine Oil Change"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Description</label>
          <textarea name="description" placeholder="What's included?"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; min-height:90px; resize:none; box-sizing:border-box;"></textarea>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Price
            (PHP)</label>
          <input type="number" step="0.01" name="price" required placeholder="0.00"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <button type="button" onclick="submitAddService()"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer;">Save
          Service</button>
      </form>
    </div>
  </div>

  <!-- Edit Service Modal -->
  <div id="editServiceModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Edit Service</h3>
        <button onclick="closeModal('editServiceModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="editServiceForm">
        <input type="hidden" name="service_id" id="edit_service_id">
        <div style="margin-bottom:1.2rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--accent); font-weight:700;">Standard Service (Admin Regulated)</label>
          <select name="master_id" onchange="window.syncMasterService(this, 'editServiceForm')"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid var(--accent); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
            <option value="">-- Custom / Not in List --</option>
            <?php
            try {
              $m_stmt = $db->query("SELECT * FROM master_services ORDER BY service_name ASC");
              while ($ms = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
                $json = htmlspecialchars(json_encode($ms));
                echo "<option value='{$ms['master_id']}' data-info='{$json}'>{$ms['service_name']}</option>";
              }
            } catch(Exception $e) {}
            ?>
          </select>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Service
            Name</label>
          <input type="text" name="service_name" id="edit_service_name" required
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Description</label>
          <textarea name="description" id="edit_service_desc"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; min-height:90px; resize:none; box-sizing:border-box;"></textarea>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Price
            (PHP)</label>
          <input type="number" step="0.01" name="price" id="edit_service_price" required
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <button type="button" onclick="window.saveEditService()"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer;">Update
          Service</button>
      </form>
    </div>
  </div>

  <!-- Staff Modal -->
  <div id="staffModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Create Staff Account</h3>
        <button onclick="closeModal('staffModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="staffMsg" style="display:none; padding:10px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem;">
      </div>
      <form id="addStaffForm" enctype="multipart/form-data">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Full
            Name</label>
          <input type="text" name="staff_name" required placeholder="e.g. Juan dela Cruz"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Email
            Address
            (Login)</label>
          <input type="email" name="email" required placeholder="juan@autoshop.com"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Temporary
            Password</label>
          <input type="text" name="password" required placeholder="TempPass123"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Mobile #</label>
            <input type="text" name="mobile" required placeholder="0912..."
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Role</label>
            <select name="role_id"
              onchange="const s = document.getElementById('mechanicSpecField'); if(this.value=='5') s.style.display='block'; else s.style.display='none';">
              <option value="3">Manager</option>
              <option value="4">Cashier</option>
              <option value="5">Mechanic</option>
            </select>
          </div>
        </div>

        <div id="mechanicSpecField" style="display:none; margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Mechanic Specialty</label>
          <select name="specialization">
            <option value="General Mechanic">General Mechanic</option>
            <option value="Engine Specialist">Engine Specialist</option>
            <option value="Electrical Specialist">Electrical Specialist</option>
            <option value="Suspension & Underchassis">Suspension & Underchassis</option>
            <option value="Body & Paint">Body & Paint</option>
            <option value="Aircon Specialist">Aircon Specialist</option>
          </select>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Complete Address</label>
          <input type="text" name="address" required placeholder="Street, City, Province"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem; display:grid; grid-template-columns:1fr 1.2fr; gap:10px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">ID Type</label>
            <select name="id_type">
              <option value="SSS">SSS / GSIS</option>
              <option value="PhilHealth">PhilHealth</option>
              <option value="DriversLicense">Driver's License</option>
              <option value="UMID">UMID</option>
              <option value="NationalID">National ID</option>
            </select>
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Upload Valid ID</label>
            <input type="file" name="id_file" accept="image/*"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.7rem 1rem; border-radius:10px; font-size:0.8rem; outline:none; box-sizing:border-box;">
          </div>
        </div>
        <button type="submit" class="btn-action" style="width:100%;">Create Account</button>
      </form>
    </div>
  </div>

  <!-- Customer Modal -->
  <div id="customerModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(20px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:rgba(15, 23, 42, 0.8); border:1px solid rgba(255,255,255,0.1); border-radius:30px; padding:3rem 2.5rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 40px 100px rgba(0,0,0,0.6); backdrop-filter:blur(20px);">
      <div style="text-align:center; margin-bottom:2rem;">
        <h1 style="font-size:1.8rem; font-weight:800; color:white; letter-spacing:-0.5px; margin-bottom:8px;">
          Register Customer</h1>
        <p style="color:var(--text-dim); font-size:0.9rem;">Add a new client to the
          <strong>
            <?php echo htmlspecialchars($shop_name); ?>
          </strong> database.
        </p>
      </div>

      <form id="addCustomerForm">
        <style>
          .modal-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            margin-bottom: 1.2rem;
          }

          .modal-input-wrapper i {
            position: absolute;
            left: 1.2rem;
            color: var(--text-dim);
            font-size: 1.1rem;
            z-index: 5;
          }

          .modal-input-wrapper input {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1.1rem 1.2rem 1.1rem 3.2rem;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: 0.3s;
            outline: none;
          }

          .modal-input-wrapper input:focus {
            border-color: var(--accent);
            background: rgba(0, 0, 0, 0.6);
          }
        </style>

        <div class="modal-input-wrapper">
          <i class="fas fa-user"></i>
          <input type="text" name="fullname" placeholder="Full Name" required>
        </div>

        <div class="modal-input-wrapper">
          <i class="fas fa-mobile-alt"></i>
          <input type="tel" name="mobile" placeholder="Mobile Number (e.g. 0917xxx)" required>
        </div>

        <div class="modal-input-wrapper">
          <i class="fas fa-envelope"></i>
          <input type="email" name="email" placeholder="Email Address" required>
        </div>

        <div class="modal-input-wrapper">
          <i class="fas fa-lock"></i>
          <input type="password" name="password" placeholder="Assign Password" required value="123456">
        </div>

        <div style="display:flex; gap:12px; margin-top:1.5rem;">
          <button type="button" onclick="closeModal('customerModal')"
            style="flex:1; background:rgba(255,255,255,0.05); color:white; border:1px solid rgba(255,255,255,0.1); padding:1.1rem; border-radius:16px; font-weight:700; cursor:pointer; transition:0.3s;">
            Cancel
          </button>
          <button type="submit"
            style="flex:2; background:linear-gradient(135deg, var(--accent), #8b5cf6); color:white; border:none; padding:1.1rem; border-radius:16px; font-size:1rem; font-weight:800; cursor:pointer; transition:0.3s; box-shadow: 0 10px 20px -5px var(--accent-glow);">
            Save Customer
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Bay Modal -->
  <div id="bayModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:400px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Register New Bay</h3>
        <button onclick="closeModal('bayModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addBayForm">
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Bay
            Name /
            Number</label>
          <input type="text" name="bay_name" required placeholder="e.g. Bay 3"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <button type="submit"
          style="width:100%; background:var(--accent); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; box-shadow:0 4px 15px var(--accent-glow);">Save
          Bay</button>
      </form>
    </div>
  </div>

  <!-- Mechanic Modal -->
  <div id="mechanicModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Register Mechanic</h3>
        <button onclick="closeModal('mechanicModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addMechanicForm" onsubmit="window.submitMechanicForm(event)"
        style="display:flex; flex-direction:column; gap:15px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Full
              Name</label>
            <input type="text" name="mechanic_name" required placeholder="e.g. Cardo Dalisay"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Specialization</label>
            <input type="text" name="specialization" required placeholder="Engine / Paint"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
        </div>

        <div
          style="padding:15px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:15px; margin:5px 0;">
          <h4
            style="margin:0 0 12px; font-size:0.8rem; color:var(--accent); text-transform:uppercase; letter-spacing:1px;">
            Login Credentials</h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div>
              <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Email
                Address</label>
              <input type="email" name="email" required placeholder="mechanic@shop.com"
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.85rem; outline:none; box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Password</label>
              <input type="password" name="password" required placeholder="••••••••"
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.85rem; outline:none; box-sizing:border-box;">
            </div>
          </div>
        </div>

        <button type="submit" id="mechanicSubmitBtn"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; margin-top:10px;">
          <i class="fas fa-save" style="margin-right:8px;"></i> Save & Create Account
        </button>
      </form>
      <script>
        window.submitMechanicForm = function (e) {
          e.preventDefault();
          const form = document.getElementById('addMechanicForm');
          const btn = document.getElementById('mechanicSubmitBtn');
          const originalText = btn.innerText;

          btn.innerText = "Processing...";
          btn.disabled = true;

          const fd = new FormData(form);
          fetch('tenant-dashboard.php?action=add_mechanic', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
              try {
                const data = JSON.parse(text.trim());
                if (data.status === 'success') {
                  showToast(data.message, 'success');
                  closeModal('mechanicModal');
                  form.reset();
                  if (typeof refreshMechanicsList === 'function') refreshMechanicsList();
                } else {
                  showToast("Error: " + data.message, 'error');
                }
              } catch (err) {
                console.error(text);
                showToast("Server Error: Invalid response.", 'error');
              }
            })
            .catch(err => showToast("Network Error.", 'error'))
            .finally(() => {
              btn.innerText = originalText;
              btn.disabled = false;
            });
        };
      </script>
    </div>
  </div>

  <!-- Mechanic Profile Modal (New) -->
  <div id="mechanicProfileModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(20px); z-index:99999; align-items:center; justify-content:center;">
    <div
      style="background:rgba(15, 23, 42, 0.9); border:1px solid rgba(255,255,255,0.15); border-radius:30px; padding:2.5rem; width:100%; max-width:550px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.7); position:relative; overflow:hidden;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:2;">
        <h3 style="margin:0; font-size:1.5rem; font-weight:800; letter-spacing:-0.5px;">Mechanic
          Profile View
        </h3>
        <button onclick="closeModal('mechanicProfileModal')"
          style="width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,0,0,0.1)'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'">&times;</button>
      </div>
      <div id="mechProfileContent" style="position:relative; z-index:2;">
        <!-- Result from Fetch -->
      </div>
    </div>
  </div>

  <!-- Payment Proof Viewer Modal (New) -->
  <div id="paymentProofModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(20px); z-index:99999; align-items:center; justify-content:center;">
    <div
      style="background:rgba(15, 23, 42, 0.9); border:1px solid rgba(255,255,255,0.15); border-radius:30px; padding:2.5rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.7); position:relative; overflow:hidden;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:2;">
        <h3 style="margin:0; font-size:1.5rem; font-weight:800; letter-spacing:-0.5px;">Payment Record
        </h3>
        <button onclick="closeModal('paymentProofModal')"
          style="width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,0,0,0.1)'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'">&times;</button>
      </div>
      <div id="paymentProofContent" style="position:relative; z-index:2;">
        <!-- Result from Fetch -->
      </div>
    </div>
  </div>

  <!-- Staff Management Modal (New) -->
  <div id="staffManageModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(20px); z-index:99999; align-items:center; justify-content:center;">
    <div
      style="background:rgba(15, 23, 42, 0.9); border:1px solid rgba(255,255,255,0.15); border-radius:30px; padding:2.5rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.7); position:relative; overflow:hidden;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:2;">
        <h3 style="margin:0; font-size:1.5rem; font-weight:800; letter-spacing:-0.5px;">Staff
          Account Management
        </h3>
        <button onclick="closeModal('staffManageModal')"
          style="width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,0,0,0.1)'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'">&times;</button>
      </div>
      <div id="staffManageContent" style="position:relative; z-index:2;">
        <!-- Result from Fetch -->
      </div>
    </div>
  </div>

  <!-- End of Day Summary Modal -->
  <div id="eodModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(20px); z-index:99999; align-items:center; justify-content:center;">
    <div
      style="background:#0f172a; border:1px solid rgba(255,255,255,0.15); border-radius:30px; padding:2rem; width:100%; max-width:600px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.7); position:relative; overflow:hidden;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1rem;">
        <div>
            <h3 style="margin:0; font-size:1.4rem; font-weight:800; color:white;">End of Day Summary</h3>
            <p id="eodDateText" style="margin:0; color:var(--text-dim); font-size:0.85rem;"></p>
        </div>
        <div style="display:flex; gap:10px;">
            <button onclick="window.printEOD()" style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:8px 15px; border-radius:10px; cursor:pointer; font-size:0.85rem;"><i class="fas fa-print"></i> Print</button>
            <button onclick="closeModal('eodModal')"
            style="width:35px; height:35px; border-radius:50%; background:rgba(255,0,0,0.1); border:none; color:#ef4444; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>
      </div>
      <div id="eodContent" style="max-height:60vh; overflow-y:auto; padding-right:5px;">
        <!-- Filled via JS -->
      </div>
    </div>
  </div>

  <!-- Inventory Modal -->
  <div id="inventoryModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:500px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Receive Inventory</h3>
        <button onclick="closeModal('inventoryModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addInventoryForm">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:1rem;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Item
              Code</label>
            <input type="text" name="item_code" required placeholder="OIL-01"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; box-sizing:border-box; outline:none;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Item
              Name</label>
            <input type="text" name="item_name" required placeholder="Synthetic Oil"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; box-sizing:border-box; outline:none;">
          </div>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Brand</label>
          <input type="text" name="brand" placeholder="e.g. Shell"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; box-sizing:border-box; outline:none;">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:1.5rem;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Quantity</label>
            <input type="number" name="quantity" required value="1"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; box-sizing:border-box; outline:none;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Price</label>
            <input type="number" step="0.01" name="price" required placeholder="0.00"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; box-sizing:border-box; outline:none;">
          </div>
        </div>
        <button type="submit"
          style="width:100%; background:linear-gradient(135deg,#f59e0b,#d97706); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer;">Add
          stock</button>
      </form>
    </div>
  </div>

  <!-- Vehicle Modal -->
  <div id="vehicleModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Register New Vehicle</h3>
        <button onclick="closeModal('vehicleModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addVehicleForm">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Owner
            (Select
            Customer)</label>
          <select name="customer_id" required>
            <option value="">-- Choose Owner --</option>
            <?php
            $stmt = $db->prepare("SELECT customer_id, full_name FROM customers WHERE tenant_id = ? ORDER BY full_name ASC");
            $stmt->execute([$tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              echo "<option value='{$row['customer_id']}'>{$row['full_name']}</option>";
            }
            ?>
          </select>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Plate
            Number</label>
          <input type="text" name="plate_no" required placeholder="e.g. ABC 1234"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box; text-transform:uppercase;">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:1.5rem;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Make
              (Brand)</label>
            <input type="text" name="make" required placeholder="Toyota"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Model</label>
            <input type="text" name="model" placeholder="Vios"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Year</label>
          <input type="number" name="year" value="<?php echo date('Y'); ?>"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <button type="submit"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer;">Register
          Vehicle</button>
      </form>
    </div>
  </div>
  <div id="customerProfileModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:850px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; border-bottom:1px solid var(--glass-border); padding-bottom:1rem;">
        <h3 style="margin:0; font-size:1.5rem;"><i class="fas fa-user-circle"></i> Customer
          Profile</h3>
        <button onclick="closeModal('customerProfileModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="profileModalContent">
        <!-- Data loaded via AJAX -->
      </div>
    </div>
  </div>
  <div id="bayProfileModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:850px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; border-bottom:1px solid var(--glass-border); padding-bottom:1rem;">
        <h3 style="margin:0; font-size:1.5rem;"><i class="fas fa-warehouse"></i> Bay Information
        </h3>
        <button onclick="closeModal('bayProfileModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="bayProfileModalContent">
        <!-- Data loaded via AJAX -->
      </div>
    </div>
  </div>
  <!-- Payment Modal -->
  <div id="paymentModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem;">Process Payment</h3>
        <button onclick="closeModal('paymentModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addPaymentForm">
        <input type="hidden" name="job_id" id="pay_job_id">
        <input type="hidden" name="appointment_id" id="pay_appointment_id">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Select
            Customer</label>
          <select name="customer_id" required onchange="window.toggleWalkInField(this.value)">
            <option value="">-- Choose Customer --</option>
            <option value="WALKIN" style="color:var(--accent); font-weight:700;">+ Walk-in / New Customer</option>
            <?php
            $stmt = $db->prepare("SELECT customer_id, full_name FROM customers WHERE tenant_id = ? ORDER BY full_name ASC");
            $stmt->execute([$tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              echo "<option value='{$row['customer_id']}'>{$row['full_name']}</option>";
            }
            ?>
          </select>
        </div>
        <div id="walkinField" style="display:none; margin-bottom:1.5rem; background:rgba(var(--accent-rgb), 0.05); padding:1rem; border-radius:12px; border:1px solid rgba(var(--accent-rgb), 0.2);">
          <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:var(--accent); font-weight:700;">Customer Name (Walk-in)</label>
          <input type="text" name="walkin_name" placeholder="Enter full name of customer"
            style="width:100%; background:rgba(0,0,0,0.3); border:1px solid var(--accent); color:white; padding:0.8rem 1rem; border-radius:10px; font-size:1rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Amount
            (PHP)</label>
          <input type="number" name="amount" id="pay_amount" required step="0.01" placeholder="0.00"
            style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:1.1rem; font-weight:700; outline:none;">
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Apply
            Discount</label>
          <select id="pay_discount" onchange="calculateFinalAmount()">
            <option value="0">No Discount</option>
            <option value="20">Senior Citizen / PWD (20%)</option>
            <option value="10">Loyalty Discount (10%)</option>
          </select>
          <small id="discountLabel"
            style="color:var(--warning); display:none; margin-top:5px; font-weight:600;"></small>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:1.5rem;">
          <div>
            <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Payment
              Method</label>
            <select name="payment_method">
              <option value="CASH">CASH</option>
              <option value="GCASH">GCASH</option>
              <option value="BANK">BANK TRANSFER</option>
            </select>
          </div>
          <div>
            <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Ref
              No.</label>
            <input type="text" name="reference_no" placeholder="Optional"
              style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none;">
          </div>
        </div>
        <button type="submit"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1.2rem; border-radius:15px; font-weight:700; cursor:pointer; font-size:1rem; box-shadow:0 10px 20px rgba(99,102,241,0.2); transition:0.3s;">
          Complete Payment & Close Job
        </button>
      </form>
    </div>
  </div>


  <!-- Job Status Modal -->
  <div id="jobStatusModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:2rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 id="jobModalTitle" style="margin:0; font-size:1.3rem;">
          <?php echo ($role === 'MECHANIC') ? 'Work Progress Update' : 'Job Assignment'; ?>
        </h3>
        <button onclick="closeModal('jobStatusModal')"
          style="background:none; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="jobStatusForm">
        <input type="hidden" name="job_id" id="status_job_id">

        <!-- Job Summary Header -->
        <div id="jobDetailsSummary"
          style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); padding:1.2rem; border-radius:18px; margin-bottom:1.5rem; display:none; border-left: 4px solid var(--accent);">
          <div
            style="font-size:0.7rem; color:var(--text-dim); text-transform:uppercase; letter-spacing:1.5px; font-weight:700; margin-bottom:8px;">
            Repair Overview</div>
          <div style="font-weight:800; color:white; font-size:1.1rem; letter-spacing:-0.3px;" id="summary_vehicle">
            Loading...</div>
          <div style="font-size:0.85rem; color:var(--accent); font-weight:600; margin-top:2px;" id="summary_service">
            Please wait</div>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Current
            Status</label>
          <select name="status" id="job_current_status" required>
            <option value="PENDING">PENDING (Awaiting Bay)</option>
            <option value="IN_PROGRESS">IN PROGRESS (Work Started)</option>
            <option value="COMPLETED">COMPLETED (Finished)</option>
            <option value="CANCELLED">CANCELLED (Stop Work)</option>
          </select>
        </div>
        <div
          style="gap:10px; margin-bottom:1rem; <?php echo ($role === 'MECHANIC') ? 'display:none;' : 'display:grid; grid-template-columns:1fr 1fr;'; ?>">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Assign
              Mechanic</label>
            <select <?php echo ($role !== 'MECHANIC') ? 'name="mechanic_id"' : ''; ?> id="status_mechanic_id"></select>
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Assign
              Bay</label>
            <select <?php echo ($role !== 'MECHANIC') ? 'name="bay_id"' : ''; ?> id="status_bay_id"></select>
          </div>
        </div>

        <!-- Hidden inputs for mechanic role so values don't get lost since selects are disabled -->
        <?php if ($role === 'MECHANIC'): ?>
        <input type="hidden" name="mechanic_id" id="status_mechanic_id_hidden">
        <input type="hidden" name="bay_id" id="status_bay_id_hidden">
        <?php endif; ?>

        <div style="<?php echo ($role !== 'MECHANIC') ? 'display:none;' : ''; ?> margin-bottom:1.5rem;">
          <label
            style="display:block; margin-bottom:10px; font-size:0.85rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Vehicle
            Inspection Checklist</label>
          <div
            style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:rgba(255,255,255,0.02); padding:1rem; border-radius:12px; border:1px solid rgba(255,255,255,0.05);">
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Engine Fluid"> Engine
              Fluid</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Tire Pressure"> Tire
              Pressure</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Battery Level"> Battery
              Level</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Brake System"> Brake
              System</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Headlights"> Lighting
              System</label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;"><input
                type="checkbox" class="ann-chk" value="Suspension"> Suspension</label>
          </div>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Work
            Remarks /
            Updates</label>
          <textarea name="remarks" id="status_remarks" placeholder="e.g. Parts arrived, starting engine repair..."
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:10px; padding:1rem; min-height:80px;"></textarea>
        </div>
        <div id="jobModalActions" style="margin-top:2rem;">
          <button type="button" id="editJobBtn" onclick="toggleJobStatusEdit(true)"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px;">
            <i class="fas fa-edit"></i> Edit Job Assignment
          </button>

          <button type="submit" id="saveJobBtn"
            style="width:100%; background:var(--accent); color:white; border:none; padding:1.2rem; border-radius:15px; font-weight:700; cursor:pointer; display:none; align-items:center; justify-content:center; gap:12px; box-shadow:0 10px 25px var(--accent-glow); transition:0.3s;">
            <i class="fas fa-check-circle" style="font-size:1.1rem;"></i> <span>
              <?php echo ($role === 'MECHANIC') ? 'Save Progress' : 'Save Updates'; ?>
            </span>
          </button>
        </div>
      </form>

      <script>
        console.log('Main Dashboard Engine Initialized');
        console.log('Job Script Initialized'); // Diagnostic check
        function dashboardOverviewRefresh() {
          fetch('tenant-dashboard.php?action=fetch_overview_stats')
            .then(r => r.json()).then(data => {
              if (document.getElementById('stat_avail_bays')) document.getElementById('stat_avail_bays').innerText = data.avail_bays;
              if (document.getElementById('stat_pending_jobs')) document.getElementById('stat_pending_jobs').innerText = data.pending_jobs;
              if (document.getElementById('stat_revenue')) document.getElementById('stat_revenue').innerText = '₱' + parseFloat(data.revenue).toLocaleString();
              if (document.getElementById('stat_pending-payments')) {
                document.getElementById('stat_pending-payments').innerText = '₱' + parseFloat(data.unpaid_balance).toLocaleString();
              }
            });
          refreshDashboardJobs();
        }

        function refreshDashboardJobs() {
          const body = document.getElementById('dashboardRepairJobsBody');
          if (!body) return;

          fetch('tenant-dashboard.php?action=fetch_dashboard_repair_jobs')
            .then(r => r.json())
            .then(data => {
              if (data.length === 0) {
                body.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--text-dim); padding:3rem;">No active repair sessions found.</td></tr>`;
                return;
              }

              let html = '';
              const role = '<?php echo $role; ?>';
              data.forEach(job => {
                if (role === 'CASHIER') {
                  const bill = parseFloat(job.total_amount || 0).toLocaleString();
                  const pStatus = job.payment_status || 'UNPAID';
                  const pBadgeClass = pStatus === 'PAID' ? 'badge-active' : 'badge-pending';
                  
                  const jStatus = job.status || 'PENDING';
                  let jBadgeClass = 'badge-pending';
                  if (jStatus === 'COMPLETED') jBadgeClass = 'badge-active';
                  else if (jStatus === 'IN_PROGRESS' || jStatus === 'STARTED') jBadgeClass = 'badge-info';
                  else if (jStatus === 'CANCELLED') jBadgeClass = 'badge-danger';

                  const actionBtn = pStatus === 'PAID' ? `<span style="color:var(--accent); font-weight:700;"><i class="fas fa-check-circle"></i> Settled</span>` : `<button class="btn-action" style="padding:4px 12px; font-size:0.75rem" onclick="window.openPaymentForJob(${job.job_id}, ${job.customer_id}, ${job.total_amount})">Collect</button>`;
                  
                  html += `<tr>
                    <td><strong>${job.plate_no}</strong></td>
                    <td>${job.make} ${job.model}<br><small style="color:var(--text-dim)">Owner: ${job.owner_name}</small></td>
                    <td>${job.service_name}</td>
                    <td>₱${bill}</td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                            <span class="badge ${pBadgeClass}" style="font-size:0.65rem;">Payment: ${pStatus}</span>
                            <span class="badge ${jBadgeClass}" style="font-size:0.6rem; opacity:0.8;">Service: ${jStatus}</span>
                        </div>
                    </td>
                    <td>${actionBtn}</td>
                  </tr>`;
                } else {
                  const statusClass = job.status === 'COMPLETED' ? 'badge-active' : (job.status === 'IN_PROGRESS' ? 'badge-processing' : 'badge-pending');
                  html += `<tr>
                    <td><strong>${job.plate_no}</strong></td>
                    <td>${job.make} ${job.model}</td>
                    <td><i class="fas fa-user-cog" style="color:var(--accent)"></i> ${job.mechanic_name}</td>
                    <td><span class="badge ${statusClass}">${job.status}</span></td>
                    ${role === 'MECHANIC' ? `<td><button class="btn-action" style="padding:4px 12px; font-size:0.75rem" onclick="openJobStatusModal(${job.job_id}, '${job.status}', ${job.mechanic_id}, ${job.bay_id}, true)">Update</button></td>` : ''}
                  </tr>`;
                }
              });
              body.innerHTML = html;
            });
        }

        window.showEODReport = function() {
          const content = document.getElementById('eodContent');
          const dateText = document.getElementById('eodDateText');
          content.innerHTML = '<div style="text-align:center; padding:3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top:1rem;">Calculating collection totals...</p></div>';
          openModal('eodModal');

          fetch('tenant-dashboard.php?action=fetch_eod_summary')
            .then(r => r.json())
            .then(data => {
              dateText.innerText = new Date(data.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
              
              let breakdownHtml = '';
              data.breakdown.forEach(m => {
                breakdownHtml += `
                  <div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; border:1px solid rgba(255,255,255,0.05);">
                    <p style="color:var(--text-dim); font-size:0.75rem; text-transform:uppercase; margin-bottom:5px;">${m.payment_method}</p>
                    <p style="font-size:1.1rem; font-weight:700; color:white;">₱${parseFloat(m.total).toLocaleString()}</p>
                    <small style="color:var(--accent)">${m.count} trans.</small>
                  </div>
                `;
              });

              let transHtml = '';
              data.transactions.forEach(t => {
                transHtml += `
                  <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.03);">
                    <div>
                        <p style="margin:0; font-weight:600; font-size:0.9rem;">${t.customer_name || 'Walk-in Customer'}</p>
                        <small style="color:var(--text-dim)">#PY-${t.payment_id} • ${t.payment_method}</small>
                    </div>
                    <p style="margin:0; font-weight:700; color:var(--accent);">₱${parseFloat(t.amount).toLocaleString()}</p>
                  </div>
                `;
              });

              content.innerHTML = `
                <div style="background:var(--accent-gradient); padding:1.5rem; border-radius:20px; text-align:center; margin-bottom:1.5rem;">
                    <p style="color:white; opacity:0.8; font-size:0.85rem; margin-bottom:5px;">Total Collection Today</p>
                    <h2 style="color:white; font-size:2rem; margin:0; font-weight:900;">₱${parseFloat(data.total).toLocaleString()}</h2>
                </div>
                
                <h4 style="margin-bottom:1rem; color:white; font-size:0.9rem;">Method Breakdown</h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:2rem;">
                    ${breakdownHtml}
                </div>

                <h4 style="margin-bottom:1rem; color:white; font-size:0.9rem;">Transaction History (Today)</h4>
                <div>
                    ${transHtml || '<p style="text-align:center; color:var(--text-dim); padding:1rem;">No transactions recorded today.</p>'}
                </div>
              `;
            });
        };

        window.printEOD = function() {
            const date = document.getElementById('eodDateText').innerText;
            const content = document.getElementById('eodContent').innerHTML;
            const shopName = '<?php echo addslashes($shop_name); ?>';
            const win = window.open('', '_blank');
            win.document.write(`
                <html>
                    <head>
                        <title>EOD Summary - ${date}</title>
                        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                        <style>
                            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
                            * { margin: 0; padding: 0; box-sizing: border-box; }
                            body { font-family: 'Inter', sans-serif; padding: 60px; color: #1e293b; background: #fff; line-height: 1.6; }
                            
                            .no-print-btn {
                                position: fixed;
                                bottom: 30px;
                                right: 30px;
                                background: #10b981;
                                color: white;
                                border: none;
                                padding: 15px 25px;
                                border-radius: 50px;
                                font-weight: 700;
                                cursor: pointer;
                                box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                z-index: 9999;
                                transition: 0.3s;
                            }
                            .no-print-btn:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(16, 185, 129, 0.4); }

                            @media print {
                                .no-print-btn { display: none !important; }
                                body { padding: 30px; }
                                .hero-stat { border: 2px solid #e2e8f0; }
                            }
                            
                            .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 50px; border-bottom: 3px solid #10b981; padding-bottom: 25px; }
                            .shop-info h1 { font-size: 2.2rem; font-weight: 800; color: #0f172a; letter-spacing: -1.5px; margin-bottom: 4px; }
                            .shop-info p { color: #64748b; font-size: 1rem; font-weight: 500; }
                            
                            .report-meta { text-align: right; }
                            .report-meta h2 { font-size: 1.1rem; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 5px; }
                            .report-meta p { color: #94a3b8; font-size: 0.9rem; font-weight: 600; }

                            .hero-stat { background: #f8fafc; border-radius: 24px; padding: 40px; text-align: center; margin-bottom: 40px; border: 1px solid #e2e8f0; position: relative; overflow: hidden; }
                            .hero-stat::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: linear-gradient(90deg, #10b981, #3b82f6); }
                            .hero-stat p { color: #64748b; font-size: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
                            .hero-stat h2 { font-size: 3.5rem; font-weight: 900; color: #0f172a; letter-spacing: -2px; }

                            .section-header { font-size: 1.1rem; font-weight: 800; color: #334155; margin-bottom: 20px; padding-left: 12px; border-left: 4px solid #10b981; }
                            
                            .method-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 50px; }
                            .method-card { border: 1px solid #f1f5f9; background: #fff; padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
                            .method-card .label { color: #94a3b8; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; }
                            .method-card .val { font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 4px; }
                            .method-card .count { color: #10b981; font-size: 0.85rem; font-weight: 600; }

                            table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 50px; }
                            th { text-align: left; background: #f8fafc; padding: 15px 20px; font-size: 0.8rem; color: #475569; text-transform: uppercase; font-weight: 800; border-bottom: 2px solid #e2e8f0; }
                            td { padding: 18px 20px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; color: #334155; }
                            tr:nth-child(even) { background: #fafafa; }
                            .row-customer { font-weight: 700; color: #0f172a; }
                            .row-meta { font-size: 0.8rem; color: #94a3b8; margin-top: 4px; }
                            .row-price { font-weight: 800; color: #10b981; text-align: right; font-size: 1.1rem; }

                            .footer { margin-top: 80px; padding-top: 30px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
                            .footer-text { color: #94a3b8; font-size: 0.85rem; }
                            .footer-stamp { background: #f1f5f9; color: #475569; padding: 6px 15px; border-radius: 100px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }

                            @media print {
                                body { padding: 30px; }
                                .hero-stat { border: 2px solid #e2e8f0; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <div class="shop-info">
                                <h1>${shopName}</h1>
                                <p>Automotive Hub Management System</p>
                            </div>
                            <div class="report-meta">
                                <h2>End of Day Summary</h2>
                                <p>${date}</p>
                            </div>
                        </div>

                        <div class="hero-stat">
                            <p>Total Collection Summary</p>
                            <h2>₱${document.querySelector('#eodContent h2').innerText.replace('₱', '')}</h2>
                        </div>

                        <h3 class="section-header">Method Breakdown</h3>
                        <div class="method-grid">
                            ${Array.from(document.querySelectorAll('#eodContent div[style*="grid-template-columns"] > div')).map(div => `
                                <div class="method-card">
                                    <div class="label">${div.querySelector('p:first-child').innerText}</div>
                                    <div class="val">${div.querySelector('p:nth-child(2)').innerText}</div>
                                    <div class="count">${div.querySelector('small').innerText}</div>
                                </div>
                            `).join('')}
                        </div>

                        <h3 class="section-header">Transaction History</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Customer & Details</th>
                                    <th style="text-align:right;">Amount Settled</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${Array.from(document.querySelectorAll('#eodContent div:last-child > div[style*="display:flex"]')).map(div => {
                                    const name = div.querySelector('p:first-child').innerText;
                                    const meta = div.querySelector('small').innerText;
                                    const price = div.querySelector('p:last-child').innerText;
                                    return `
                                        <tr>
                                            <td>
                                                <div class="row-customer">${name}</div>
                                                <div class="row-meta">${meta}</div>
                                            </td>
                                            <td class="row-price">${price}</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>

                        <div class="footer">
                            <div class="footer-text">
                                <p>Generated by AutoFix Hub V100 Master Engine</p>
                                <p>&copy; ${new Date().getFullYear()} ${shopName}</p>
                            </div>
                            <div class="footer-stamp">
                                Verified Internal Report • ${new Date().toLocaleTimeString()}
                            </div>
                        </div>

                        <button class="no-print-btn" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </body>
                </html>
            `);
            win.document.close();
            setTimeout(() => { win.print(); }, 500);
        };

        window.toggleWalkInField = function(val) {
          const field = document.getElementById('walkinField');
          if (field) {
            field.style.display = (val === 'WALKIN') ? 'block' : 'none';
            const input = field.querySelector('input');
            if (val === 'WALKIN') {
              input.setAttribute('required', 'required');
              input.focus();
            } else {
              input.removeAttribute('required');
            }
          }
        };

        window.syncMasterService = function(el, formId) {
          const form = document.getElementById(formId);
          const opt = el.options[el.selectedIndex];
          const nameInput = form.querySelector('input[name="service_name"]');
          const priceInput = form.querySelector('input[name="price"]');
          
          if (opt.value) {
            const info = JSON.parse(opt.getAttribute('data-info'));
            nameInput.value = info.service_name;
            nameInput.readOnly = true;
            nameInput.style.opacity = "0.6";
            
            priceInput.min = info.min_price;
            priceInput.max = info.max_price;
            priceInput.placeholder = `Price must be ₱${parseFloat(info.min_price).toLocaleString()} - ₱${parseFloat(info.max_price).toLocaleString()}`;
            
            // Visual hint
            let hint = form.querySelector('.price-hint');
            if (!hint) {
               hint = document.createElement('p');
               hint.className = 'price-hint';
               hint.style.fontSize = '0.75rem';
               hint.style.marginTop = '4px';
               priceInput.parentNode.appendChild(hint);
            }
            hint.innerHTML = `<i class="fas fa-info-circle"></i> Price Boundary: <b style="color:var(--success);">₱${parseFloat(info.min_price).toLocaleString()}</b> to <b style="color:var(--error);">₱${parseFloat(info.max_price).toLocaleString()}</b>`;
          } else {
            nameInput.readOnly = false;
            nameInput.style.opacity = "1";
            priceInput.removeAttribute('min');
            priceInput.removeAttribute('max');
            priceInput.placeholder = "0.00";
            const hint = form.querySelector('.price-hint');
            if (hint) hint.remove();
          }
        };

        window.openPaymentForJob = function (jobId, customerId, amount) {
          const custSelect = document.querySelector('#addPaymentForm select[name="customer_id"]');
          const amtInput = document.getElementById('pay_amount');
          const jidInput = document.getElementById('pay_job_id');

          if (custSelect) {
              custSelect.value = customerId;
              window.toggleWalkInField(customerId);
          }
          if (amtInput) amtInput.value = amount;
          if (jidInput) jidInput.value = jobId;

          openModal('paymentModal');
        }

        function toggleJobStatusEdit(editMode) {
          const editBtn = document.getElementById('editJobBtn');
          const saveBtn = document.getElementById('saveJobBtn');
          const mechSel = document.getElementById('status_mechanic_id');
          const baySel = document.getElementById('status_bay_id');
          const statusSel = document.getElementById('job_current_status');
          const remarksField = document.getElementById('status_remarks');

          if (editMode) {
            if (editBtn) editBtn.style.display = 'none';
            if (saveBtn) { saveBtn.style.display = 'flex'; }
            if (mechSel) mechSel.disabled = false;
            if (baySel) baySel.disabled = false;
            if (remarksField) remarksField.disabled = false;

            // Status remains disabled until both Mechanic and Bay are selected
            if (statusSel) {
              const hasMech = mechSel && mechSel.value && mechSel.value !== "";
              const hasBay = baySel && baySel.value && baySel.value !== "";
              statusSel.disabled = !(hasMech && hasBay);
            }
          } else {
            if (editBtn) editBtn.style.display = 'flex';
            if (saveBtn) saveBtn.style.display = 'none';
            if (mechSel) mechSel.disabled = true;
            if (baySel) baySel.disabled = true;
            if (statusSel) statusSel.disabled = true;
            if (remarksField) remarksField.disabled = true;
          }
        }

        function openJobStatusModal(id, currentStatus, currentMechId, currentBayId, editMode = false) {
          console.log("MODAL_TRIGGERED", { id, currentStatus, currentMechId, currentBayId });

          const jidInput = document.getElementById('status_job_id');
          const statusSelect = document.getElementById('job_current_status');
          const summaryBox = document.getElementById('jobDetailsSummary');
          const modalTitle = document.getElementById('jobModalTitle');

          if (!jidInput || !statusSelect) {
            alert("Interface Error: Job Status Form inputs not found.");
            return;
          }

          if (modalTitle) {
            modalTitle.innerText = ('<?php echo addslashes($role); ?>' === 'MECHANIC') ? 'Repair Progress Update' : 'Repair Status & Editing';
          }

          jidInput.value = id;
          if (statusSelect) {
            statusSelect.value = currentStatus;
            const hiddenStatus = document.getElementById('job_current_status_hidden');
            if (hiddenStatus) hiddenStatus.value = currentStatus;
          }

          // Set UI state — editMode=true opens directly editable, false = view-first
          toggleJobStatusEdit(editMode);

          // Fetch extra details for summary
          if (summaryBox) {
            summaryBox.style.display = 'block';
            document.getElementById('summary_vehicle').innerText = "Loading vehicle...";
            document.getElementById('summary_service').innerText = "---";

            fetch(`tenant-dashboard.php?action=fetch_job_details&job_id=${id} `)
              .then(res => res.json())
              .then(data => {
                if (data.status === 'success' && data.job) {
                  document.getElementById('summary_vehicle').innerText = `${data.job.plate_no} - ${data.job.make} ${data.job.model} `;
                  document.getElementById('summary_service').innerText = data.job.service_name;

                  // Also populate the remarks field if available
                  const remField = document.getElementById('status_remarks');
                  if (remField) remField.value = data.job.latest_remarks || "";
                }
              }).catch(e => console.error("Summary fetch failed", e));
          }

          const mS = document.getElementById('status_mechanic_id');
          const bS = document.getElementById('status_bay_id');

          if (mS) {
            mS.innerHTML = '<option value="">Loading tools...</option>';
            mS.onchange = () => {
              const statusSel = document.getElementById('job_current_status');
              if (statusSel) statusSel.disabled = !(mS.value && bS && bS.value);
            };
          }
          if (bS) {
            bS.innerHTML = '<option value="">Loading slots...</option>';
            bS.onchange = () => {
              const statusSel = document.getElementById('job_current_status');
              if (statusSel) statusSel.disabled = !(bS.value && mS && mS.value);
            };
          }

          const modalEl = document.getElementById('jobStatusModal');
          if (modalEl) {
            modalEl.style.display = 'flex';
            modalEl.style.zIndex = '999999';
          } else {
            alert("Modal ID 'jobStatusModal' not found!");
          }

          fetch(`tenant-dashboard.php?action=fetch_available_resources&preferred_id=${currentBayId || 0}& _t=${Date.now()} `)
            .then(res => res.text())
            .then(text => {
              console.log("Raw API Output for fetch_available_resources:", text);
              const jsonMatch = text.match(/\{[\s\S]*\}/);
              if (!jsonMatch) {
                console.error("Could not parse JSON. Raw API response:", text);
                alert("API Error: " + text.substring(0, 50));
                throw new Error("Invalid resource JSON: " + text.substring(0, 50));
              }
              const data = JSON.parse(jsonMatch[0]);

              if (data.error) {
                alert("Database Error: " + data.error);
                console.error("DB Error:", data.error);
              }

              let mHtml = '<option value="">-- No Mechanic --</option>';
              if (data.mechanics) {
                data.mechanics.forEach(m => {
                  const sel = (m.mechanic_id == currentMechId) ? 'selected' : '';
                  const isBusy = (m.status && m.status.toUpperCase() !== 'AVAILABLE') && !sel;
                  const dis = isBusy ? 'disabled' : '';
                  const lbl = isBusy ? `${m.full_name} (Busy)` : m.full_name;
                  mHtml += `<option value="${m.mechanic_id}" ${sel} ${dis}> ${lbl}</option>`;
                });
              }
              if (mS) mS.innerHTML = mHtml;

              let bHtml = '<option value="">-- No Bay --</option>';
              if (data.bays) {
                data.bays.forEach(b => {
                  const sel = (b.bay_id == currentBayId) ? 'selected' : '';
                  const isOcc = (b.status && b.status.toUpperCase() !== 'AVAILABLE') && !sel;
                  const dis = isOcc ? 'disabled' : '';
                  const lbl = isOcc ? `${b.bay_name} (Occupied)` : b.bay_name;
                  bHtml += `<option value="${b.bay_id}" ${sel} ${dis}> ${lbl}</option>`;
                });
              }
              if (bS) bS.innerHTML = bHtml;

              // Support hidden IDs since selects are disabled for mechanics
              const mHidden = document.getElementById('status_mechanic_id_hidden');
              if (mHidden) mHidden.value = currentMechId || '';
              const bHidden = document.getElementById('status_bay_id_hidden');
              if (bHidden) bHidden.value = currentBayId || '';

              // Final UI Check: Enable status if job already has resources after fetch
              const statusSel = document.getElementById('job_current_status');
              if (statusSel) statusSel.disabled = !(mS.value && bS && bS.value);
            }).catch(err => console.error("Resource fetch error:", err));
        }
        window.openJobStatusModal = openJobStatusModal;



        // Real-time badge logic
        // Standardized Badge Logic
        function checkAnnBadge() {
          const storageKey = 'lastAnnId_<?php echo $tenant_id; ?>_<?php echo $_SESSION['user_id'] ?? 0; ?>';
          const lastSeen = localStorage.getItem(storageKey) || 0;

          fetch('tenant-dashboard.php?action=fetch_latest_ann_id')
            .then(r => r.json())
            .then(data => {
              const badge = document.getElementById('annBadge');
              if (badge && data.latest_id && data.latest_id > parseInt(lastSeen)) {
                badge.classList.add('active');
              }
            }).catch(e => console.log('[SUBS] Badge sync fail'));
        }
        checkAnnBadge();
        setInterval(checkAnnBadge, 15000); // Check every 15s

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
            icon.style.color = 'var(--danger)';
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
          icon.style.color = '#f59e0b';
          icon.style.background = 'rgba(245, 158, 11, 0.1)';
          cancelBtn.style.display = 'block';
          btn.onclick = () => { onConfirm(); closeNotiModal(); };
          document.getElementById('notificationModal').style.display = 'flex';
        }

        function closeNotiModal() { document.getElementById('notificationModal').style.display = 'none'; }

        function toggleAnnouncement() {
          try {
            const panel = document.getElementById('annPanel');
            const overlay = document.getElementById('annOverlay');
            if (panel) panel.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');

            if (panel && panel.classList.contains('active')) {
              // Mark as read safely
              try {
                localStorage.setItem('lastAnnId_<?php echo $tenant_id; ?>_<?php echo $_SESSION['user_id'] ?? 0; ?>', '<?php echo $maxAnnId; ?>');
              } catch (e) { }
              const badge = document.getElementById('annBadge');
              if (badge) badge.classList.remove('active');
            } else {
              if (typeof cancelStaffAnnEdit === 'function') {
                cancelStaffAnnEdit();
              }
            }
          } catch (err) {
            console.error("Broadcast toggle issue: ", err);
          }
        }

        // --- NEW MECHANIC DASHBOARD FUNCTIONS ---
        function refreshMechanicStation() {
          // Load Work History
          fetch('tenant-dashboard.php?action=fetch_mechanic_work_log')
            .then(r => r.json())
            .then(res => {
              if (res.status === 'success') {
                let html = '';
                res.data.forEach(log => {
                  html += `<tr>
                <td style="font-size:0.75rem;">${new Date(log.created_at).toLocaleString()}</td>
                <td><div style="font-weight:700;">${log.plate_no}</div><div style="font-size:0.75rem; color:var(--text-dim);">${log.make} ${log.model}</div></td>
                <td><span class="badge ${log.status_update === 'COMPLETED' ? 'badge-active' : 'badge-pending'}">${log.status_update}</span></td>
                <td style="max-width:300px; font-size:0.85rem;">${log.remarks || '-'}</td>
              </tr>`;
                });
                document.getElementById('mechanicHistoryTable').innerHTML = html || '<tr><td colspan="4" style="text-align:center;">No history found.</td></tr>';
              }
            });

          // Load Inventory Lookup (Read-only)
          fetch('tenant-dashboard.php?action=fetch_inventory')
            .then(r => r.json())
            .then(res => {
              let html = '';
              res.forEach(item => {
                const low = parseInt(item.quantity) < 10;
                html += `<tr class="inventory-lookup-row">
              <td style="font-weight:700;">${item.item_name}</td>
              <td>${item.brand || '-'}</td>
              <td>${item.quantity}</td>
              <td><span class="badge ${low ? 'badge-pending' : 'badge-active'}">${low ? 'LOW STOCK' : 'STOCKED'}</span></td>
            </tr>`;
              });
              document.getElementById('inventoryLookupTable').innerHTML = html || '<tr><td colspan="4" style="text-align:center;">No inventory found.</td></tr>';
            });
        }

        function filterInventoryLookup(query) {
          const rows = document.querySelectorAll('.inventory-lookup-row');
          const q = query.toLowerCase();
          rows.forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
          });
        }

        // Removed duplicated checkAnnBadge

        function enableStaffAnnEdit() {
          const display = document.getElementById('annList');
          const editor = document.getElementById('staffAnnEditor');
          const ctrl = document.getElementById('staffAnnControls');
          if (display) display.style.display = 'none';
          if (editor) editor.style.display = 'block';
          if (ctrl) ctrl.style.display = 'none';
        }

        function cancelStaffAnnEdit() {
          const display = document.getElementById('annList');
          const editor = document.getElementById('staffAnnEditor');
          const ctrl = document.getElementById('staffAnnControls');
          if (display) display.style.display = 'block';
          if (editor) editor.style.display = 'none';
          if (ctrl) ctrl.style.display = 'block';
        }

        function saveStaffAnnEdit() {
          const val = document.getElementById('staffAnnInput').value;
          if (!val.trim()) return alert("Please type an announcement.");

          const fd = new FormData();
          fd.append('announcement', val);

          fetch('tenant-dashboard.php?action=edit_staff_ann', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data.status === 'success') {
                location.reload();
              } else alert(data.message);
            });
        }

        // --- ELITE REPAIR LOGIC ---
        function handleStatusSubmit(e) {
          e.preventDefault();
          const form = e.target;
          const fd = new FormData(form);

          // VALIDATION: MUST assign resources first
          const mId = document.getElementById('status_mechanic_id')?.value;
          const bId = document.getElementById('status_bay_id')?.value;
          if (!mId || !bId) {
            return showAlert('Assignment Required', 'Please assign both a Mechanic and a Service Bay before saving updates.', 'error');
          }

          // Collect checklist
          const checked = [];
          document.querySelectorAll('.ann-chk:checked').forEach(c => checked.push(c.value));
          fd.append('checklist', checked.join(', '));

          fetch('tenant-dashboard.php?action=update_job_status', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data.status === 'success') {
                showToast("Job status successfully updated!");
                closeModal('jobStatusModal');
                if (typeof refreshJobOrders === 'function') refreshJobOrders();
                if (typeof refreshBaysList === 'function') refreshBaysList();
                if (typeof refreshMechanicsList === 'function') refreshMechanicsList();
                if (typeof dashboardOverviewRefresh === 'function') dashboardOverviewRefresh();
              } else showAlert('Validation Error', data.message, 'error');
            });
        }
        const jsf = document.getElementById('jobStatusForm');
        if (jsf) {
          jsf.addEventListener('submit', handleStatusSubmit);
        }

        function updateTimers() {
          document.querySelectorAll('.active-timer').forEach(el => {
            const startStr = el.getAttribute('data-start');
            if (!startStr) return;
            const start = new Date(startStr).getTime();
            const now = new Date().getTime();
            const diff = now - start;

            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);

            el.innerText = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
          });
        }
        setInterval(updateTimers, 1000);

        window.saveSingleSetting = function (field) {
          const input = document.getElementById('setting_' + field);
          if (!input) return;

          const value = input.value;
          const btn = event.currentTarget;
          const originalHtml = btn.innerHTML;

          btn.classList.add('saving');
          btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

          const fd = new FormData();
          fd.append('field', field);
          fd.append('value', value);

          fetch('tenant-dashboard.php?action=save_setting_item', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data.status === 'success') {
                showToast("Feature updated successfully!");
                if (['primary_color', 'secondary_color', 'ui_style', 'border_radius', 'logo_url', 'banner_url', 'shop_name'].includes(field)) {
                  const frame = document.getElementById('livePreviewFrame');
                  if (frame) frame.src = frame.src;
                }
              } else {
                showToast(data.message, 'error');
              }
            })
            .catch(err => showToast("Connection error", "error"))
            .finally(() => {
              btn.classList.remove('saving');
              btn.innerHTML = originalHtml;
            });
        };

        window.saveSettingWithFile = function (field) {
          const input = document.getElementById('setting_' + field);
          if (!input || !input.files[0]) return showToast("Please select a file first", "error");

          const btn = event.currentTarget;
          const originalHtml = btn.innerHTML;

          btn.classList.add('saving');
          btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

          const fd = new FormData();
          fd.append('field', field);
          fd.append(field, input.files[0]);

          fetch('tenant-dashboard.php?action=save_setting_item', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data.status === 'success') {
                showToast("File uploaded and saved!");
                const urlInput = document.getElementById('setting_' + field.replace('_file', '_url'));
                if (urlInput) urlInput.value = data.new_url;

                const frame = document.getElementById('livePreviewFrame');
                if (frame) frame.src = frame.src;
              } else {
                showToast(data.message, 'error');
              }
            })
            .catch(err => showToast("Upload error", "error"))
            .finally(() => {
              btn.classList.remove('saving');
              btn.innerHTML = originalHtml;
            });
        };

        window.highlightInPreview = function (field) {
          const frame = document.getElementById('livePreviewFrame');
          if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage({ action: 'highlight', field: field }, '*');
          }
        };
      </script>

      <div class="modal-overlay" id="notificationModal" style="z-index: 9999; display: none;">
        <div class="modal-card"
          style="max-width: 450px; text-align: center; padding: 3rem 2.5rem; background: rgba(10,10,20,0.95); border: 1px solid var(--glass-border); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
          <div id="notiIcon"
            style="width: 80px; height: 80px; background: rgba(99, 102, 241, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; font-size: 2.5rem; color: var(--accent); transition: 0.3s;">
            <i class="fa-solid fa-circle-info"></i>
          </div>
          <h2 id="notiTitle" style="margin-bottom: 1rem; font-size: 1.6rem; font-weight: 800; color: white;">
            Notice
          </h2>
          <p id="notiMessage" style="color: var(--text-dim); margin-bottom: 2.5rem; line-height: 1.6; font-size: 1rem;">
            Message goes
            here.</p>
          <div id="notiActions" style="display: flex; gap: 1rem; justify-content: center;">
            <button id="notiConfirmBtn" class="btn-action"
              style="min-width: 120px; padding: 0.9rem 2rem; border-radius: 12px; font-weight: 800; cursor: pointer; background: var(--accent); border:none; color:white;">Confirm</button>
            <button id="notiCancelBtn" class="btn-outline"
              style="min-width: 120px; padding: 0.9rem 2rem; border-radius: 12px; font-weight: 800; display: none;"
              onclick="closeNotiModal()">Cancel</button>
          </div>
        </div>
      </div>

      <!-- CLEANED OUT DUPLICATED ADD AND EDIT SERVICE MODALS -->





      <!-- Toast UI -->
      <div id="toastContainer" class="toast-container"></div>
      <script>
        // V100 MASTER ENGINE: PURGED DUPLICATES
        console.log("[SYSTEM] V100 MASTER ENGINE ONLINE.");

        window.openAssignBayModal = function (id, name) {
          document.getElementById('assign_bay_id').value = id;
          openModal('assignBayModal');
          const jS = document.getElementById('assign_job_id');
          const mS = document.getElementById('assign_mechanic_id');
          jS.innerHTML = 'Loading...'; mS.innerHTML = 'Loading...';
          fetch('tenant-dashboard.php?action=fetch_available_resources')
            .then(r => r.json()).then(res => {
              jS.innerHTML = '<option value="">-- Select Pending Job --</option>';
              res.pending_jobs.forEach(j => { jS.innerHTML += `<option value="${j.job_id}">${j.plate_no} - ${j.service_name}</option>`; });
              mS.innerHTML = '<option value="">-- Auto-Assign --</option>';
              res.mechanics.forEach(m => { mS.innerHTML += `<option value="${m.mechanic_id}">${m.full_name}</option>`; });
            });
        };

        window.setPreviewSize = function (type) {
          const frame = document.getElementById('livePreviewFrame');
          const title = document.getElementById('previewTitleText');
          const btnDesktop = document.getElementById('btnViewDesktop');
          const btnMobile = document.getElementById('btnViewMobile');

          if (type === 'mobile') {
            frame.style.width = '375px';
            frame.style.margin = '0 auto';
            frame.style.display = 'block';
            title.innerText = 'Website Preview (Mobile)';
            btnMobile.style.background = 'rgba(255,255,255,0.1)';
            btnMobile.style.color = 'var(--accent)';
            btnDesktop.style.background = 'transparent';
            btnDesktop.style.color = 'var(--text-dim)';
          } else {
            frame.style.width = '100%';
            frame.style.margin = '0';
            title.innerText = 'Website Preview (Desktop)';
            btnDesktop.style.background = 'rgba(255,255,255,0.1)';
            btnDesktop.style.color = 'var(--accent)';
            btnMobile.style.background = 'transparent';
            btnMobile.style.color = 'var(--text-dim)';
          }
        };

        document.addEventListener('DOMContentLoaded', () => {
          ['assignBayForm'].forEach(fid => {
            const f = document.getElementById(fid);
            if (f) {
              f.addEventListener('submit', function (e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]'); btn.disabled = true;
                const action = fid === 'assignBayForm' ? 'assign_bay_job' : 'update_job_status';
                fetch('tenant-dashboard.php?action=' + action, { method: 'POST', body: new FormData(this) })
                  .then(r => r.json()).then(data => {
                    if (data.status === 'success') { showToast("Success!"); closeModal(fid.replace('Form', 'Modal')); refreshBaysList(); refreshJobOrders(); }
                    else { showToast(data.message, 'error'); }
                  }).finally(() => btn.disabled = false);
              });
            }
          });
        });
      </script>

      <script>
        // FORCING SIDEBAR TO THE VISUAL FRONT
        window.addEventListener('load', () => {
          const sb = document.querySelector('.sidebar');
          if (sb) {
            sb.style.zIndex = '2147483647';
            sb.style.pointerEvents = 'auto';
            console.log("[SYSTEM] Navigation Sidebar Locked to Top Stack.");
          }
        });

        // GLOBAL PRIORITY SYNC FOR CUSTOMERS
        window.refreshAddCustomerList = function () {
          const body = document.getElementById('customersBody');
          if (!body) return;

          const current = body.innerHTML.toLowerCase();
          if (current.includes('loading') || current.trim() === '') {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:var(--accent);"></i><br><br>Retrieving customer records...</td></tr>';
          }

          fetch('tenant-dashboard.php?action=fetch_customers&_cache=' + Date.now())
            .then(r => r.text())
            .then(text => {
              try {
                const start = text.indexOf('[');
                const end = text.lastIndexOf(']') + 1;
                const data = JSON.parse(text.substring(start, end));

                const countEl = document.getElementById('customerTotalCount');
                if (countEl) countEl.innerText = `${data.length} Total`;

                if (!Array.isArray(data) || data.length === 0) {
                  body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim); opacity:0.6;"><i class="fas fa-user-friends" style="font-size:3rem; margin-bottom:1rem;"></i><br>Database is empty. No customers found.</td></tr>';
                  return;
                }

                body.innerHTML = data.map(c => `
          <tr class="hover-bright">
                      <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                          <div style="width:38px; height:38px; border-radius:50%; background:var(--accent); color:white; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:0.8rem;">
                            ${(c.full_name || 'C').charAt(0).toUpperCase()}
                          </div>
                          <strong>${c.full_name || '---'}</strong>
                        </div>
                      </td>
                      <td>
                        <div style="font-size:0.9rem; font-weight:600;">${c.mobile || '---'}</div>
                        <div style="font-size:0.75rem; color:var(--text-dim);">${c.email || 'No email'}</div>
                      </td>
                      <td><span style="font-weight:700; color:var(--text-main);">${c.total_visits || 0}</span> visit(s)</td>
                      <td><span class="badge badge-active">${c.status || 'ACTIVE'}</span></td>
                      <td><button class="btn-outline" style="padding:6px 12px; font-size:0.8rem;" onclick="window.openCustomerProfile(${c.customer_id})"><i class="fas fa-search"></i> Profile</button></td>
                    </tr>`).join('');
              } catch (e) {
                body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:2rem; color:var(--danger);">Sync failed. Click "New Customer" to add manually.</td></tr>';
              }
            }).catch(e => { body.innerHTML = '<tr><td colspan="5">Connection error.</td></tr>'; });
        };

        window.openCustomerProfile = function (customerId) {
          const body = document.getElementById('profileModalContent');
          if (!body) return;
          body.innerHTML = '<div style="text-align:center; padding:5rem;"><i class="fas fa-spinner fa-spin" style="font-size:3rem; color:var(--accent);"></i><br><br>Analyzing customer history...</div>';
          openModal('customerProfileModal');

          fetch(`tenant-dashboard.php?action=fetch_customer_details&customer_id=${customerId}`)
            .then(res => res.json())
            .then(data => {
              if (data.status === 'error') {
                body.innerHTML = `<div style="color:var(--danger); padding:2rem; text-align:center;">${data.message}</div>`;
                return;
              }

              const c = data.customer;
              const vehiclesHtml = data.vehicles.length ? data.vehicles.map(v => `
                <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.05); padding:1rem; border-radius:15px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-weight:800; color:var(--text-main); font-size:1.1rem;">${v.plate_no}</div>
                    <div style="font-size:0.85rem; opacity:0.7;">${v.make} ${v.model} (${v.year || ''})</div>
                  </div>
                  <button class="btn-outline" style="padding:4px 10px; font-size:0.7rem;" onclick="window.openVehicleProfile(${v.vehicle_id})">View History</button>
                </div>
              `).join('') : '<div style="text-align:center; padding:1rem; opacity:0.5; border:1px dashed rgba(255,255,255,0.1); border-radius:10px;">No vehicles found</div>';

              const apptsHtml = data.appointments.length ? data.appointments.map(a => `
                <div style="padding:1rem; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-size:0.95rem; font-weight:700;">${a.service_name || 'Repair Job'}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">${new Date(a.appointment_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                  </div>
                  <span class="badge badge-active" style="font-size:0.7rem;">${a.status}</span>
                </div>
              `).join('') : '<div style="text-align:center; padding:2rem; opacity:0.5;">No service history records.</div>';

              body.innerHTML = `
                <div style="display:grid; grid-template-columns:320px 1fr; gap:2.5rem;">
                  <div style="background:rgba(255,255,255,0.02); padding:2rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                    <div style="width:100px; height:100px; border-radius:25px; background:var(--accent); margin:0 auto 1.5rem; display:flex; align-items:center; justify-content:center; font-size:3rem; font-weight:900; color:white; box-shadow:0 10px 20px rgba(0,0,0,0.3);">
                      ${c.full_name.charAt(0).toUpperCase()}
                    </div>
                    <h3 style="text-align:center; margin-bottom:0.5rem; font-size:1.5rem;">${c.full_name}</h3>
                    <div style="text-align:center; margin-bottom:2rem;"><span class="badge badge-active">LIFETIME CUSTOMER</span></div>
                    
                    <div style="display:flex; flex-direction:column; gap:15px;">
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-envelope" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem;">${c.email || 'No email set'}</span></div>
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-phone" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem; font-weight:700;">${c.mobile}</span></div>
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-map-marker-alt" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.85rem; opacity:0.8;">${c.address || 'Address not provided'}</span></div>
                    </div>
                    <div style="margin-top:2.5rem;">
                      <h4 style="font-size:0.8rem; text-transform:uppercase; letter-spacing:1px; color:var(--text-dim); margin-bottom:1rem;">Registered Vehicles</h4>
                      ${vehiclesHtml}
                    </div>
                  </div>
                  <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                      <h4 style="margin:0; font-size:1.2rem; font-weight:800;"><i class="fas fa-calendar-check" style="color:var(--accent); margin-right:8px;"></i> Recent Activities</h4>
                      <div style="font-size:0.8rem; color:var(--text-dim);">Total Visits: <span style="color:var(--accent); font-weight:800;">${c.total_visits || 0}</span></div>
                    </div>
                    <div style="background:rgba(0,0,0,0.2); border-radius:20px; border:1px solid rgba(255,255,255,0.05); min-height:400px; max-height:500px; overflow-y:auto;">
                      ${apptsHtml}
                    </div>
                  </div>
                </div>
              `;
            }).catch(err => {
              body.innerHTML = '<div style="text-align:center; padding:3rem; color:var(--danger);">Error connecting to API.</div>';
            });
        };

        // GLOBAL PRIORITY SEARCH
        window.openVehicleProfile = function (vehicleId) {
          const body = document.getElementById('profileModalContent'); // Reuse customer modal layout or use another if available
          if (!body) return;
          body.innerHTML = '<div style="text-align:center; padding:5rem;"><i class="fas fa-spinner fa-spin" style="font-size:3rem; color:var(--accent);"></i><br><br>Fetching vehicle history...</div>';
          openModal('customerProfileModal'); // Reusing the same large profile modal for consistency

          fetch(`tenant-dashboard.php?action=fetch_vehicle_history&id=${vehicleId}`)
            .then(res => res.json())
            .then(data => {
              if (!data.length) {
                body.innerHTML = '<div style="text-align:center; padding:5rem;">No history records found for this vehicle.</div>';
                return;
              }
              const v = data[0];
              const historyHtml = data.map(h => `
                <div style="padding:1rem; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-size:0.95rem; font-weight:700;">${h.service_name || 'Repair Job'}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">${new Date(h.created_at).toLocaleDateString()} - Mechanic: ${h.mechanic_name}</div>
                  </div>
                  <span class="badge badge-active">${h.status}</span>
                </div>
              `).join('');

              body.innerHTML = `
                <div style="display:grid; grid-template-columns:320px 1fr; gap:2.5rem;">
                  <div style="background:rgba(255,255,255,0.02); padding:2rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                    <div style="width:100px; height:100px; border-radius:25px; background:var(--accent); margin:0 auto 1.5rem; display:flex; align-items:center; justify-content:center; font-size:3rem; font-weight:900; color:white; box-shadow:0 10px 20px rgba(0,0,0,0.3);">
                      <i class="fas fa-car"></i>
                    </div>
                    <h3 style="text-align:center; margin-bottom:0.5rem; font-size:1.5rem;">${v.plate_no}</h3>
                    <p style="text-align:center; color:var(--text-dim);">${v.make} ${v.model} (${v.year_model || ''})</p>
                    
                    <div style="margin-top:2rem; padding:1rem; background:rgba(0,0,0,0.2); border-radius:12px;">
                       <small style="color:var(--text-dim); text-transform:uppercase; font-size:0.7rem;">Vehicle Info</small>
                       <div style="margin-top:10px; font-size:0.9rem;"><strong>Plate:</strong> ${v.plate_no}</div>
                       <div style="margin-top:5px; font-size:0.9rem;"><strong>Make:</strong> ${v.make}</div>
                       <div style="margin-top:5px; font-size:0.9rem;"><strong>Model:</strong> ${v.model}</div>
                    </div>
                  </div>
                  <div>
                    <h4 style="margin:0 0 1.5rem; font-size:1.2rem; font-weight:800;"><i class="fas fa-history" style="color:var(--accent); margin-right:8px;"></i> Service History</h4>
                    <div style="background:rgba(0,0,0,0.2); border-radius:20px; border:1px solid rgba(255,255,255,0.05); min-height:400px; max-height:500px; overflow-y:auto;">
                      ${historyHtml}
                    </div>
                  </div>
                </div>
              `;
            });
        };

        window.searchTable = function (input, bodyId) {
          const filter = input.value.toLowerCase().trim();
          const body = document.getElementById(bodyId);
          if (!body) return;
          const rows = body.getElementsByTagName('tr');
          for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            // Skip loading or empty rows
            if (row.cells.length === 1 && (row.innerText.includes('Loading') || row.innerText.includes('No '))) continue;
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? "" : "none";
          }
        };

        window.openBayProfile = function (bayId) {
          const body = document.getElementById('bayProfileModalContent');
          if (!body) return;

          body.innerHTML = '<div style="text-align:center; padding:3rem;"><div class="spinner" style="margin:0 auto 1rem;"></div><p>Gathering bay intelligence...</p></div>';
          openModal('bayProfileModal');

          fetch(`tenant-dashboard.php?action=fetch_bay_details&id=${bayId} `)
            .then(res => res.json())
            .then(data => {
              if (data.status === 'error') throw new Error(data.message);

              const b = data.bay;
              const isAvail = b.status === 'AVAILABLE';
              const current = data.current_job;
              const history = data.history;

              let historyHtml = history.length ? history.map(h => `
                <div style="padding:1rem; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-size:0.95rem; font-weight:700;">${h.service_name}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">${h.plate_no} - ${h.customer_name}</div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-size:0.8rem; font-weight:700;">₱${parseFloat(h.total_amount || 0).toLocaleString()}</div>
                    <div style="font-size:0.7rem; opacity:0.6;">${new Date(h.completed_at).toLocaleDateString()}</div>
                  </div>
                </div>
              `).join('') : '<div style="text-align:center; padding:2rem; opacity:0.5;">No recent history.</div>';

              let currentJobHtml = current ? `
                <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:1.5rem; border-radius:20px; margin-bottom:2rem;">
                  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <span class="badge badge-active">ACTIVE REPAIR</span>
                    <span style="font-size:0.8rem; font-weight:700; color:var(--accent);">JO-${current.job_id.toString().padStart(4, '0')}</span>
                  </div>
                  <h4 style="margin:0; font-size:1.2rem;">${current.service_name}</h4>
                  <div style="margin-top:10px; font-size:0.9rem; opacity:0.8;">
                    <i class="fas fa-car" style="margin-right:8px;"></i> ${current.plate_no} (${current.make} ${current.model})<br>
                    <i class="fas fa-user" style="margin-right:8px;"></i> ${current.customer_name}
                  </div>
                  <button onclick="closeModal('bayProfileModal'); window.openJobStatusModal(${current.job_id}, '${current.status}', ${current.mechanic_id}, ${b.bay_id})" 
                      style="width:100%; margin-top:1.5rem; background:var(--accent); color:white; border:none; padding:0.8rem; border-radius:12px; font-weight:700; cursor:pointer;">
                    Control Repair Stream
                  </button>
                </div>
              ` : `
                <div style="background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.1); padding:2rem; border-radius:20px; text-align:center; margin-bottom:2rem;">
                  <i class="fas fa-check-circle" style="font-size:2.5rem; color:var(--accent); opacity:0.3; margin-bottom:1rem;"></i>
                  <h4 style="margin:0;">Bay is Ready</h4>
                  <p style="font-size:0.85rem; color:var(--text-dim); margin:10px 0 1.5rem;">This slot is currently optimal for a new service assignment.</p>
                  <button onclick="closeModal('bayProfileModal'); openAssignBayModal(${b.bay_id}, '${b.bay_name}')" 
                      style="background:var(--accent); color:white; border:none; padding:0.8rem 2rem; border-radius:12px; font-weight:800; cursor:pointer; box-shadow:0 10px 20px var(--accent-glow);">
                    Establish Operational Flow
                  </button>
                </div>
              `;

              body.innerHTML = `
          <div style="display:grid; grid-template-columns:300px 1fr; gap:2.5rem;">
                    <div style="background:rgba(255,255,255,0.02); padding:2rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                      <div style="width:80px; height:80px; border-radius:20px; background:var(--accent); margin:0 auto 1.5rem; display:flex; align-items:center; justify-content:center; font-size:2.5rem; font-weight:900; color:white; box-shadow:0 10px 20px rgba(0,0,0,0.3);">
                        ${b.bay_name.charAt(0).toUpperCase()}
                      </div>
                      <h3 style="text-align:center; margin-bottom:0.5rem; font-size:1.5rem;">${b.bay_name}</h3>
                      <div style="text-align:center; margin-bottom:2rem;"><span class="badge ${isAvail ? 'badge-active' : ''}">${b.status}</span></div>
                      
                      <div style="display:flex; flex-direction:column; gap:15px;">
                        <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-tools" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem;">Service Ready</span></div>
                        <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-clock" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem;">24/7 Monitoring</span></div>
                      </div>
                    </div>
                    <div>
                      <h4 style="font-size:0.8rem; text-transform:uppercase; letter-spacing:1.5px; color:var(--text-dim); margin-bottom:1.2rem;">Operational Status</h4>
                      ${currentJobHtml}
                      
                      <h4 style="font-size:0.8rem; text-transform:uppercase; letter-spacing:1.5px; color:var(--text-dim); margin-bottom:1.2rem;">Utilization History</h4>
                      <div style="background:rgba(255,255,255,0.01); border-radius:20px; border:1px solid rgba(255,255,255,0.05); overflow:hidden;">
                        ${historyHtml}
                      </div>
                    </div>
                  </div>
          `;
            }).catch(err => {
              body.innerHTML = `<div style="text-align:center; padding:3rem; color:var(--danger);">${err.message}</div>`;
            });
        };

        window.openVehicleHistory = function (vehicleId) {
          const body = document.getElementById('vehicleHistoryContent');
          const info = document.getElementById('historyVehicleInfo');
          if (!body) return;

          body.innerHTML = '<div style="text-align:center; padding:5rem;"><div class="spinner" style="margin:0 auto 1.5rem;"></div><p style="opacity:0.6;">Retrieving vehicle lineage...</p></div>';
          openModal('vehicleHistoryModal');

          fetch(`tenant-dashboard.php?action=fetch_vehicle_history&id=${vehicleId} `)
            .then(res => res.json())
            .then(data => {
              if (!data || data.length === 0) {
                body.innerHTML = '<div style="text-align:center; padding:5rem; opacity:0.5;"><i class="fas fa-history" style="font-size:3rem; margin-bottom:1.5rem;"></i><br>No service history records found for this unit.</div>';
                return;
              }

              const first = data[0];
              info.innerHTML = `<i class="fas fa-car" style = "color:var(--accent); margin-right:8px;" ></i> ${first.plate_no} • ${first.make} ${first.model} `;

              body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:1.2rem;">
            ${data.map(h => {
                let badgeClass = 'badge-pending';
                if (h.status === 'COMPLETED') badgeClass = 'badge-active';
                if (h.status === 'IN_PROGRESS') badgeClass = 'badge-warning';

                return `
                <div class="hover-bright" style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:20px; padding:1.8rem; transition:0.3s;">
                  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
                    <div>
                      <span class="badge ${badgeClass}" style="font-size:0.7rem; padding:4px 10px;">${h.status.replace('_', ' ')}</span>
                      <h4 style="margin:12px 0 6px; font-size:1.2rem; font-weight:800;">${h.service_name}</h4>
                      <div style="font-size:0.85rem; color:var(--text-dim); display:flex; align-items:center; gap:10px;">
                        <span>JO-${h.job_id.toString().padStart(4, '0')}</span>
                        <span style="opacity:0.3;">|</span>
                        <span>${new Date(h.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}</span>
                      </div>
                    </div>
                    <div style="text-align:right;">
                      <div style="font-size:1.3rem; font-weight:900; color:var(--text-main);">₱${parseFloat(h.total_amount || 0).toLocaleString()}</div>
                      <div style="font-size:0.75rem; color:${h.payment_status === 'PAID' ? 'var(--accent)' : 'var(--warning)'}; margin-top:6px; font-weight:700;">
                        <i class="fas fa-${h.payment_status === 'PAID' ? 'check-circle' : 'exclamation-circle'}"></i> ${h.payment_status || 'UNPAID'}
                      </div>
                    </div>
                  </div>
                  <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; padding-top:1.5rem; border-top:1px solid rgba(255,255,255,0.03); font-size:0.9rem;">
                    <div style="display:flex; align-items:center; gap:10px; color:var(--text-dim);">
                      <i class="fas fa-user-cog" style="color:var(--accent); width:16px;"></i> 
                      <span style="color:white; font-weight:600;">${h.mechanic_name || 'System Auto-Assign'}</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; color:var(--text-dim);">
                      <i class="fas fa-warehouse" style="color:var(--accent); width:16px;"></i> 
                      <span style="color:white; font-weight:600;">${h.bay_name || 'General Service Area'}</span>
                    </div>
                  </div>
                </div>
              `}).join('')
                }
            </div>
          `;
            })
            .catch(err => {
              body.innerHTML = `<div style="text-align:center; padding:5rem; color:var(--danger);"><i class="fas fa-exclamation-triangle" style="font-size:3rem; margin-bottom:1rem;"></i><br>Intelligence breach: ${err.message}</div>`;
            });
        };

        // Execute immediate refresh check
        setTimeout(() => {
          if (typeof window.refreshBaysList === 'function') window.refreshBaysList();
        }, 1000);
      </script>
    </div>
  </div>

  <!-- Chat Support Widget -->
  <div id="supportChatWidget" style="position:fixed; bottom:30px; right:30px; z-index:9999;">
    <!-- Unified Chat Button -->
    <div id="chatBubble" onclick="toggleChat()"
      style="display:flex; align-items:center; gap:0; cursor:pointer; position:relative; background:var(--accent); color:white; padding:8px 8px 8px 18px; border-radius:100px; font-weight:800; font-size:0.9rem; box-shadow:0 10px 30px var(--accent-glow); transition:0.3s;">
      <span style="margin-right:12px;">Support Chat</span>
      <div
        style="width:45px; height:45px; background:rgba(255,255,255,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white;">
        <i class="fas fa-comment-dots" style="font-size:1.2rem;"></i>
      </div>
      <span id="tenantChatBadge"
        style="display:none; position:absolute; top:-10px; right:-5px; background:#ff4757; color:white; font-size:0.7rem; font-weight:900; min-width:24px; height:24px; border-radius:12px; align-items:center; justify-content:center; border:2px solid var(--bg-deep); box-shadow:0 0 15px rgba(255, 71, 87, 0.5); animation: chatPulse 2s infinite;">0</span>
    </div>
    <style>
      @keyframes chatPulse {
        0% {
          transform: scale(1);
          box-shadow: 0 0 0 0 rgba(255, 71, 87, 0.7);
        }

        70% {
          transform: scale(1.1);
          box-shadow: 0 0 0 10px rgba(255, 71, 87, 0);
        }

        100% {
          transform: scale(1);
          box-shadow: 0 0 0 0 rgba(255, 71, 87, 0);
        }
      }
    </style>

    <!-- Chat Window -->
    <div id="chatWindow"
      style="position:absolute; bottom:80px; right:0; width:350px; height:500px; background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; display:none; flex-direction:column; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,0.5); backdrop-filter:blur(20px);">
      <!-- Header -->
      <div
        style="background:var(--accent); padding:1.5rem; color:white; display:flex; justify-content:space-between; align-items:center;">
        <div>
          <h4 style="margin:0; font-size:1rem; font-weight:800;">Support Chat</h4>
          <span style="font-size:0.7rem; opacity:0.8;">Platform Administrator</span>
        </div>
        <i class="fas fa-times" onclick="toggleChat()" style="cursor:pointer; opacity:0.7;"></i>
      </div>
      <!-- Messages -->
      <div id="chatMessages"
        style="flex:1; padding:1.2rem; overflow-y:auto; display:flex; flex-direction:column; gap:12px; background:rgba(0,0,0,0.2);">
        <div style="text-align:center; padding:2rem; color:var(--text-dim); font-size:0.8rem;">
          How can we help you today?
        </div>
      </div>
      <!-- Input -->
      <div
        style="padding:1.2rem; border-top:1px solid var(--glass-border); display:flex; align-items:flex-end; gap:10px;">
        <textarea id="chatInput" placeholder="Type a message..." rows="1"
          style="flex:1; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:12px; padding:0.8rem; color:white; font-size:0.9rem; outline:none; resize:none; font-family:inherit; min-height:45px; max-height:120px;"></textarea>
        <button onclick="sendMessage()"
          style="background:var(--accent); border:none; width:45px; height:45px; border-radius:12px; color:white; cursor:pointer; transition:0.3s; flex-shrink:0;">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
    </div>
  </div>

  <script>
    let chatOpen = false;
    let lastMsgCount = 0;

    function toggleChat() {
      chatOpen = !chatOpen;
      const win = document.getElementById('chatWindow');
      win.style.display = chatOpen ? 'flex' : 'none';
      if (chatOpen) {
        fetch('tenant-dashboard.php?action=mark_support_read'); // Mark as read on open
        document.getElementById('tenantChatBadge').style.display = 'none';
        fetchMessages();
        setTimeout(() => {
          const box = document.getElementById('chatMessages');
          box.scrollTop = box.scrollHeight;
          document.getElementById('chatInput').focus();
        }, 100);
      }
    }

    function fetchMessages() {
      fetch('tenant-dashboard.php?action=fetch_support_messages')
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            const newCount = data.messages.filter(m => m.sender_role === 'ADMIN' && m.is_read == 0).length;

            // Show badge if window closed and there are unread admin messages
            const badge = document.getElementById('tenantChatBadge');
            if (!chatOpen && newCount > 0) {
              badge.innerText = newCount;
              badge.style.display = 'flex';
            } else if (chatOpen) {
              badge.style.display = 'none';
            }

            renderMessages(data.messages);
          }
        });
    }

    // Background polling every 10 seconds for notifications
    setInterval(fetchMessages, 10000);

    function renderMessages(msgs) {
      const box = document.getElementById('chatMessages');
      const wasAtBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 10;

      box.innerHTML = msgs.length === 0 ? '<div style="text-align:center; padding:2rem; color:var(--text-dim); font-size:0.8rem;">How can we help you today?</div>' : '';

      msgs.forEach(m => {
        const isMe = m.sender_role === 'TENANT';
        const div = document.createElement('div');
        div.style.alignSelf = isMe ? 'flex-end' : 'flex-start';
        div.style.maxWidth = '80%';
        div.style.padding = '10px 14px';
        div.style.borderRadius = isMe ? '16px 16px 0 16px' : '16px 16px 16px 0';
        div.style.background = isMe ? 'var(--accent)' : 'rgba(255,255,255,0.08)';
        div.style.color = 'white';
        div.style.fontSize = '0.85rem';
        div.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
        div.innerText = m.message;
        box.appendChild(div);
      });

      if (wasAtBottom) box.scrollTop = box.scrollHeight;
    }

    document.getElementById('chatInput').onkeypress = (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    };

    // Auto-resize textarea
    const tx = document.getElementById('chatInput');
    tx.addEventListener("input", OnInput, false);
    function OnInput() {
      this.style.height = 'auto';
      this.style.height = (this.scrollHeight) + "px";
    }

    function sendMessage() {
      const input = document.getElementById('chatInput');
      const msg = input.value.trim();
      if (!msg) return;

      const fd = new FormData();
      fd.append('message', msg);

      input.value = '';
      input.style.height = '45px'; // Reset height
      fetch('tenant-dashboard.php?action=send_support_message', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') fetchMessages();
          else alert(data.message);
        });
    }

    document.getElementById('chatInput').onkeypress = (e) => { if (e.key === 'Enter') sendMessage(); };

    // Handle external triggers (e.g. from shop.php suspension)
    window.addEventListener('load', () => {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('triggerChat') === 'suspension') {
        if (!chatOpen) toggleChat();
        const shopName = "<?php echo addslashes($shop_name); ?>";
        const input = document.getElementById('chatInput');
        input.value = `Hello, I'm inquiring about the suspension of my shop (${shopName}). How can I resolve this?`;
        input.focus();

        // Clear the URL param without refreshing
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
      }
    });

    // Initial fetch
    fetchMessages();
  </script>


  <style>
    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>


  <script>
    // Bind the Profile Update Form in the View
    document.addEventListener('DOMContentLoaded', () => {
      const pFormView = document.getElementById('updateProfileFormView');
      if (pFormView) {
        pFormView.addEventListener('submit', function (e) {
          e.preventDefault();
          const fd = new FormData(this);

          showToast("Uploading profile picture...", "info");

          fetch('tenant-dashboard.php?action=update_my_profile', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
              if (res.status === 'success') {
                showToast(res.message, 'success');
                setTimeout(() => location.reload(), 800);
              } else {
                showToast(res.message, 'error');
              }
            })
            .catch(err => {
              showToast("Connection error", "error");
            });
        });
      }
    });
  </script>


  <script>
    window.revenueChartInstance = null;

    window.showReport = function (type) {
      console.log("SHOW REPORT CALLED:", type);
      const titleEl = document.getElementById('reportTitle');
      const contentEl = document.getElementById('reportContent');
      const chartContainer = document.getElementById('reportChartContainer');

      if (!contentEl || !titleEl || !chartContainer) {
        console.error("Report elements not found!");
        return;
      }

      contentEl.innerHTML = '<p style="text-align:center; padding:3rem; opacity:0.6;">Loading report data...</p>';
      chartContainer.style.display = 'none';

      if (typeof window.openModal === 'function') {
        window.openModal('reportModal');
      } else {
        const modal = document.getElementById('reportModal');
        if (modal) modal.style.display = 'flex';
      }

      if (window.revenueChartInstance) {
        window.revenueChartInstance.destroy();
        window.revenueChartInstance = null;
      }

      let action = '';
      if (type === 'revenue') { action = 'get_revenue_report'; titleEl.innerText = '7-Day Revenue Analytics'; }
      if (type === 'performance') { action = 'get_service_performance'; titleEl.innerText = 'Service Performance (Top 5)'; }
      if (type === 'inventory') { action = 'get_inventory_report'; titleEl.innerText = 'Low Stock Alert (Stock < 10)'; }

      fetch('tenant-dashboard.php?action=' + action)
        .then(res => res.json())
        .then(data => {
          if (!Array.isArray(data) || data.length === 0) {
            contentEl.innerHTML = '<p style="text-align:center; padding:3rem; color:var(--text-dim);">No data available for this report.</p>';
            return;
          }

          if (type === 'revenue') {
            chartContainer.style.display = 'block';
            const ctx = document.getElementById('revenueChart').getContext('2d');

            if (typeof Chart !== 'undefined') {
              window.revenueChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                  labels: data.map(row => row.date),
                  datasets: [{
                    label: 'Daily Revenue (₱)',
                    data: data.map(row => row.total),
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 5
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: {
                    y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: 'rgba(255,255,255,0.5)' } },
                    x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.5)' } }
                  }
                }
              });
            } else {
              console.error("Chart.js is not loaded!");
            }
          }

          let html = '<table class="data-table"><thead><tr>';
          if (type === 'revenue') {
            html += '<th>Date</th><th>Total Revenue</th></tr></thead><tbody>';
            data.forEach(row => {
              html += `<tr><td>${row.date}</td><td style="font-weight:800; color:var(--accent);">₱${parseFloat(row.total || 0).toLocaleString()}</td></tr>`;
            });
          } else if (type === 'performance') {
            html += '<th>Service Name</th><th>Total Jobs</th></tr></thead><tbody>';
            data.forEach(row => {
              html += `<tr><td>${row.service_name || 'Unknown'}</td><td>${row.count || 0} jobs</td></tr>`;
            });
          } else if (type === 'inventory') {
            html += '<th>Item Name</th><th>Remaining Qty</th></tr></thead><tbody>';
            data.forEach(row => {
              html += `<tr><td>${row.item_name}</td><td style="color:var(--danger);">${row.quantity} units left</td></tr>`;
            });
          }
          html += '</tbody></table>';
          contentEl.innerHTML = html;
        })
        .catch(err => {
          console.error(err);
          contentEl.innerHTML = '<p style="color:var(--danger); text-align:center; padding:2rem;">System Error: Could not load reports. Please ensure your database is updated.</p>';
        });
    };
  </script>

  <script>
    // FINAL RELIABILITY BINDING ENGINE
    (function () {
      const forms = {
        'addServiceForm': 'add_service',
        'editServiceForm': 'edit_service',
        'addStaffForm': 'add_staff',
        'addBayForm': 'add_bay',
        'addMechanicForm': 'add_mechanic',
        'addInventoryForm': 'add_inventory',
        'addCustomerForm': 'add_customer',
        'editCustomerForm': 'edit_customer',
        'addPaymentForm': 'add_payment',
        'addVehicleForm': 'add_vehicle',
        'customizationForm': 'save_customization'
      };

      Object.entries(forms).forEach(([formId, action]) => {
        const formEl = document.getElementById(formId);
        if (formEl) {
          formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]') || this.querySelector('button');
            const originalText = btn.innerText;
            btn.innerText = (action === 'save_customization') ? 'Saving...' : 'Processing...';
            btn.disabled = true;

            const formData = new FormData(this);
            const url = `tenant-dashboard.php?action=${action}`;

            fetch(url, { method: 'POST', body: formData })
              .then(res => res.text())
              .then(text => {
                try {
                  const jsonMatch = text.match(/\{.*\}/s);
                  if (!jsonMatch) throw new Error("Invalid server response format");
                  const data = JSON.parse(jsonMatch[0]);
                  const msgId = (formId === 'addStaffForm') ? 'staffMsg' : null;

                  if (data.status === 'success') {
                    if (msgId) {
                      const m = document.getElementById(msgId);
                      m.style.display = 'block';
                      m.style.background = 'rgba(16,185,129,0.1)';
                      m.style.color = '#10b981';
                      m.innerText = data.message;
                    }
                    if (action === 'save_customization') {
                      window.location.reload();
                    } else {
                      if (!msgId) showToast(data.message, 'success');
                      const modalName = formId.replace('add', '').replace('Form', 'Modal');
                      const finalModalId = modalName.charAt(0).toLowerCase() + modalName.slice(1);

                      setTimeout(() => {
                        closeModal(finalModalId);
                        if (msgId) document.getElementById(msgId).style.display = 'none';
                        this.reset();
                        // Refresh appropriate list
                        if (typeof window.refreshStaffList === 'function' && action === 'add_staff') refreshStaffList();
                        else if (typeof window.refreshServicesList === 'function' && action === 'add_service') refreshServicesList();
                        else location.reload(); // Hard fallback for other lists
                      }, msgId ? 1500 : 200);
                    }
                  } else {
                    if (msgId) {
                      const m = document.getElementById(msgId);
                      m.style.display = 'block';
                      m.style.background = 'rgba(239,68,68,0.1)';
                      m.style.color = '#ef4444';
                      m.innerText = data.message;
                    } else {
                      showToast("Error: " + data.message, 'error');
                    }
                  }
                } catch (e) {
                  console.error("Execute JS Error:", e, text);
                  // If JS parsing fails, let the browser do a normal submit as fallback if possible
                  // (Though we already called preventDefault, so we can't easily revert here)
                  showToast("System Error: Server returned an invalid response.", 'error');
                }
              })
              .catch(err => {
                console.error("Network Error:", err);
                showToast("Network Error: Could not connect to server.", 'error');
              })
              .finally(() => {
                btn.innerText = originalText;
                btn.disabled = false;
              });
          });
        }
      });
    })();
  </script>
</body>

</html>