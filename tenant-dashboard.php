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

  // Fetch Mechanic Shift if applicable
  $my_shift = null;
  $my_pending_shift_request = null;
  if (strtoupper($role) === 'MECHANIC') {
    $stmt_m = $db_check->prepare("SELECT shift_start, shift_end, shift_days FROM mechanics WHERE user_id = ? AND tenant_id = ?");
    $stmt_m->execute([$_SESSION['user_id'], $tenant_id]);
    $my_shift = $stmt_m->fetch(PDO::FETCH_ASSOC);

    // Also fetch latest shift request status (only if not seen yet OR if it's still pending)
    $stmt_sr = $db_check->prepare("SELECT * FROM shift_requests WHERE mechanic_id = (SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?) AND tenant_id = ? AND (status = 'PENDING' OR is_seen = 0) ORDER BY created_at DESC LIMIT 1");
    $stmt_sr->execute([$_SESSION['user_id'], $tenant_id, $tenant_id]);
    $my_pending_shift_request = $stmt_sr->fetch(PDO::FETCH_ASSOC);

    // If it's processed (APPROVED/REJECTED) but not seen, mark it as seen NOW so next time it's gone
    if ($my_pending_shift_request && $my_pending_shift_request['status'] !== 'PENDING' && $my_pending_shift_request['is_seen'] == 0) {
      $db_check->prepare("UPDATE shift_requests SET is_seen = 1 WHERE request_id = ?")->execute([$my_pending_shift_request['request_id']]);
    }
  }

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
      status VARCHAR(20) DEFAULT 'COMPLETED'
    )");

    // Shift Requests Table
    $db->exec("CREATE TABLE IF NOT EXISTS shift_requests (
      request_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      mechanic_id INT NOT NULL,
      requested_start TIME NOT NULL,
      requested_end TIME NOT NULL,
      reason TEXT,
      status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
      is_seen TINYINT(1) DEFAULT 0,
      processed_by INT NULL,
      processed_at TIMESTAMP NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (tenant_id), INDEX (mechanic_id)
    )");

    try {
      $db->exec("ALTER TABLE shift_requests ADD COLUMN is_seen TINYINT(1) DEFAULT 0 AFTER status");
    } catch (Exception $e) {
    }

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

  $plan_tier = $active_subscription['plan_name'] ?? 'Free Tier';
  $bay_limit = intval($active_subscription['max_service_bays'] ?? 2);

  // Calculate Low Stock Count for Sidebar Badge
  $low_stock_count = 0;
  try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE tenant_id = ? AND quantity < 5");
    $stmt->execute([$tenant_id]);
    $low_stock_count = intval($stmt->fetchColumn());
  } catch (Exception $e) {
  }

  // Shift Requests Data for Server-Side Rendering
  $pending_shift_requests_count = 0;
  $pending_shift_requests_list = [];
  if (in_array($role, ['OWNER', 'MANAGER'])) {
    try {
      $stmt = $db->prepare("SELECT sr.*, COALESCE(m.full_name, 'Unknown Mechanic') as full_name FROM shift_requests sr LEFT JOIN mechanics m ON sr.mechanic_id = m.mechanic_id WHERE sr.tenant_id = ? AND sr.status = 'PENDING' ORDER BY sr.created_at DESC");
      $stmt->execute([$tenant_id]);
      $pending_shift_requests_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $pending_shift_requests_count = count($pending_shift_requests_list);
    } catch (Exception $e) {
    }
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
      
      $now = date('Y-m-d H:i:s');
      $db->prepare("INSERT INTO support_messages (tenant_id, sender_role, sender_id, message, created_at) VALUES (?, 'TENANT', ?, ?, ?)")
        ->execute([$tenant_id, $_SESSION['user_id'], $msg, $now]);

      // --- AUTO-REPLY LOGIC ---
      $sentAuto = false;
      try {
        $sendAutoReply = false;
        
        // Fetch last two messages in the chat.
        // The first one [0] is the message we just inserted.
        // The second one [1] is the previous message in the chat history.
        $stmtLastTwo = $db->prepare("SELECT sender_role, message, created_at FROM support_messages WHERE tenant_id = ? ORDER BY message_id DESC LIMIT 2");
        $stmtLastTwo->execute([$tenant_id]);
        $lastTwo = $stmtLastTwo->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($lastTwo) < 2) {
          // No previous messages at all
          $sendAutoReply = true;
        } else {
          $prevMsg = $lastTwo[1];
          // If the last message prior to this was already from ADMIN (like another auto-reply), do not trigger another one
          if ($prevMsg['sender_role'] !== 'ADMIN') {
            // Find the most recent admin message anywhere in the chat
            $stmtLastAdmin = $db->prepare("SELECT created_at, message FROM support_messages WHERE tenant_id = ? AND sender_role = 'ADMIN' ORDER BY message_id DESC LIMIT 1");
            $stmtLastAdmin->execute([$tenant_id]);
            $lastAdmin = $stmtLastAdmin->fetch(PDO::FETCH_ASSOC);
            
            if (!$lastAdmin) {
              $sendAutoReply = true;
            } else {
              // Send auto-reply only if the last admin message was more than 30 minutes ago
              $timeDiff = time() - strtotime($lastAdmin['created_at']);
              if ($timeDiff > 1800) {
                $sendAutoReply = true;
              }
            }
          }
        }
        
        // Rate-limit auto-replies to once every 5 minutes to prevent spam
        if ($sendAutoReply) {
          $stmtRecentAuto = $db->prepare("SELECT created_at FROM support_messages WHERE tenant_id = ? AND sender_role = 'ADMIN' AND message LIKE '%[Auto-Reply]%' ORDER BY message_id DESC LIMIT 1");
          $stmtRecentAuto->execute([$tenant_id]);
          $lastAutoTime = $stmtRecentAuto->fetchColumn();
          if ($lastAutoTime) {
            $autoTimeDiff = time() - strtotime($lastAutoTime);
            if ($autoTimeDiff < 300) {
              $sendAutoReply = false;
            }
          }
        }
        
        if ($sendAutoReply) {
          $autoMsg = "🤖 [Auto-Reply] Hello! Thank you for reaching out to AutoFix Hub Support. Our administrators are currently offline or attending to other requests. We have received your message and will get back to you as soon as possible. Thank you for your patience!";
          $db->prepare("INSERT INTO support_messages (tenant_id, sender_role, sender_id, message, is_read, created_at) VALUES (?, 'ADMIN', 0, ?, 0, ?)")
            ->execute([$tenant_id, $autoMsg, $now]);
          $sentAuto = true;
        }
      } catch (Exception $autoErr) {
        // Silently fail if auto-reply fails so it doesn't block the user's message
      }

      echo json_encode(['status' => 'success', 'auto_reply' => $sentAuto]);
    } catch (Exception $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
  }

  if (isset($_GET['action']) && $_GET['action'] === 'fetch_support_messages') {
    header('Content-Type: application/json');
    try {
      $msgs = $db->prepare("SELECT sm.*, t.logo_url, 
                            CASE 
                                WHEN sm.sender_role = 'ADMIN' THEN COALESCE(u.avatar_url, u.profile_pic, (SELECT avatar_url FROM users WHERE role_id = 1 AND avatar_url IS NOT NULL LIMIT 1))
                                ELSE COALESCE(u.avatar_url, u.profile_pic)
                            END AS sender_avatar 
                            FROM support_messages sm 
                            LEFT JOIN tenants t ON sm.tenant_id = t.tenant_id 
                            LEFT JOIN users u ON sm.sender_id = u.user_id 
                            WHERE sm.tenant_id = ? 
                            ORDER BY sm.created_at ASC");
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

        // Auto-patch if column missing
        try {
          $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER name");
        } catch (Exception $e) {
        }

        $pExt = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $pFileName = 'profile_' . $user_id . '_' . time() . '.' . $pExt;
        $pPath = $pUploadDir . $pFileName;

        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $pPath)) {
          $db->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?")->execute([$pPath, $user_id]);
          @ob_clean();
          echo json_encode(['status' => 'success', 'message' => 'Profile picture updated!']);
        } else {
          throw new Exception("Failed to move uploaded file.");
        }
      } else {
        throw new Exception("No file uploaded or upload error.");
      }
    } catch (Exception $e) {
      @ob_clean();
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

        try {
          $db->exec("ALTER TABLE appointments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        } catch (Exception $e) {
        }

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
          min_price DECIMAL(10,2) NULL,
          max_price DECIMAL(10,2) NULL,
          category VARCHAR(50) NULL,
          estimated_time VARCHAR(50) NULL,
          status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (tenant_id)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
        try {
          $db->exec("ALTER TABLE services ADD COLUMN master_id INT DEFAULT NULL AFTER tenant_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE services ADD COLUMN min_price DECIMAL(10,2) NULL, ADD COLUMN max_price DECIMAL(10,2) NULL");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE services ADD COLUMN category VARCHAR(50) NULL AFTER price");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE services ADD COLUMN estimated_time VARCHAR(50) NULL AFTER category");
        } catch (Exception $e) {
        }
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
          $db->exec("ALTER TABLE users ADD COLUMN name VARCHAR(100) AFTER email");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER name");
        } catch (\Throwable $e) {
        }
        try {
          $db->exec("ALTER TABLE users ADD COLUMN status ENUM('ACTIVE','INACTIVE') DEFAULT 'ACTIVE'");
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
          $db->exec("ALTER TABLE mechanics ADD COLUMN shift_start TIME DEFAULT '08:00:00'");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN shift_end TIME DEFAULT '17:00:00'");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE mechanics ADD COLUMN full_name VARCHAR(100) NULL AFTER tenant_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("CREATE TABLE IF NOT EXISTS shift_requests (
            request_id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            mechanic_id INT NOT NULL,
            requested_start TIME NOT NULL,
            requested_end TIME NOT NULL,
            reason TEXT,
            status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_by INT NULL,
            processed_at TIMESTAMP NULL
          )");
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
        $db->exec("CREATE TABLE IF NOT EXISTS services (service_id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, master_id INT DEFAULT NULL, service_name VARCHAR(100), description TEXT, price DECIMAL(10,2), min_price DECIMAL(10,2) NULL, max_price DECIMAL(10,2) NULL, category VARCHAR(50), estimated_time VARCHAR(50), status ENUM('ACTIVE', 'INACTIVE'), created_at DATETIME)");
        try {
          $db->exec("ALTER TABLE services ADD COLUMN master_id INT DEFAULT NULL AFTER tenant_id");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE services ADD COLUMN min_price DECIMAL(10,2) NULL, ADD COLUMN max_price DECIMAL(10,2) NULL");
        } catch (Exception $e) {
        }
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

          $res = $db->query("SHOW COLUMNS FROM appointments LIKE 'requested_mechanic_id'");
          if (!$res->fetch()) {
            $db->exec("ALTER TABLE appointments ADD COLUMN requested_mechanic_id INT NULL");
          }

          // Heal Vehicles Status
          try {
            $db->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ACTIVE'");
          } catch (Exception $e) {
          }

          // Data Migration Patch: Recover requested_mechanic_id from mechanic_id for ALL existing records where it's missing.
          // This fixes the "(None)" issue for older bookings that were already confirmed or pending.
          $db->exec("UPDATE appointments SET requested_mechanic_id = mechanic_id 
                     WHERE (requested_mechanic_id IS NULL OR requested_mechanic_id = 0) AND (mechanic_id IS NOT NULL AND mechanic_id != 0)");

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

          // --- BILLING ACCURACY PATCH ---
          // Recalculate total_amount for all repair_jobs based on Service Price + Parts Total
          $db->exec("UPDATE repair_jobs j 
                     SET j.total_amount = (
                        COALESCE((SELECT price FROM services WHERE service_id = j.service_id), 0) + 
                        COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = j.job_id), 0)
                     ) WHERE j.status != 'SETTLED'");

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

          $db->exec("CREATE TABLE IF NOT EXISTS repair_parts (
            rp_id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            job_id INT NOT NULL,
            item_id INT NULL,
            service_id INT NULL,
            quantity INT DEFAULT 1,
            unit_price DECIMAL(10,2) DEFAULT 0,
            total_price DECIMAL(10,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )");
          try {
            $db->exec("ALTER TABLE repair_parts ADD COLUMN service_id INT NULL AFTER item_id");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE repair_parts MODIFY item_id INT NULL");
          } catch (Exception $e) {
          }

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
            if (!in_array('walkin_name', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN walkin_name VARCHAR(100) NULL AFTER notes");
            if (!in_array('walkin_plate', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN walkin_plate VARCHAR(20) NULL AFTER walkin_name");
            if (!in_array('walkin_model', $cols))
              $db->exec("ALTER TABLE repair_jobs ADD COLUMN walkin_model VARCHAR(50) NULL AFTER walkin_plate");

            // Fix nullable columns for Walk-ins
            $db->exec("ALTER TABLE repair_jobs MODIFY COLUMN customer_id INT NULL");
            $db->exec("ALTER TABLE repair_jobs MODIFY COLUMN vehicle_id INT NULL");
          } catch (Exception $pe) {
          }

          $db->exec("CREATE TABLE IF NOT EXISTS repair_timeline (
      timeline_id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      user_id INT NOT NULL DEFAULT 0,
      job_id INT NOT NULL,
      status_update VARCHAR(50),
      remarks TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (job_id),
      INDEX (tenant_id),
      INDEX (user_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

          try {
            $db->exec("ALTER TABLE repair_timeline ADD COLUMN user_id INT NOT NULL DEFAULT 0 AFTER tenant_id");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE repair_timeline ADD INDEX (user_id)");
          } catch (Exception $e) {
          }

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
        // Inventory Table Auto-Heal
        $db->exec("CREATE TABLE IF NOT EXISTS inventory (
          item_id INT AUTO_INCREMENT PRIMARY KEY,
          tenant_id INT NOT NULL,
          item_code VARCHAR(50),
          item_name VARCHAR(100) NOT NULL,
          brand VARCHAR(50),
          quantity INT DEFAULT 0,
          unit VARCHAR(20) DEFAULT 'pcs',
          price DECIMAL(10,2) DEFAULT 0,
          stock_threshold INT DEFAULT 5,
          status VARCHAR(20) DEFAULT 'IN_STOCK',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Ensure columns exist if table was created previously with older schema
        try {
          $db->exec("ALTER TABLE inventory ADD COLUMN price DECIMAL(10,2) DEFAULT 0");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE inventory ADD COLUMN stock_threshold INT DEFAULT 5");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE inventory ADD COLUMN item_code VARCHAR(50)");
        } catch (Exception $e) {
        }

        // Inventory History/Logs Table Auto-Heal
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_history (
          history_id INT AUTO_INCREMENT PRIMARY KEY,
          tenant_id INT NOT NULL,
          item_id INT NOT NULL,
          item_name VARCHAR(100) NOT NULL,
          transaction_type VARCHAR(20) NOT NULL,
          quantity_changed INT NOT NULL,
          new_quantity INT NOT NULL,
          notes VARCHAR(255),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Triggers for automatic logging
        try {
          $db->exec("DROP TRIGGER IF EXISTS after_inventory_update");
          $db->exec("CREATE TRIGGER after_inventory_update AFTER UPDATE ON inventory FOR EACH ROW
            BEGIN
              IF OLD.quantity <> NEW.quantity THEN
                INSERT INTO inventory_history (tenant_id, item_id, item_name, transaction_type, quantity_changed, new_quantity, notes)
                VALUES (NEW.tenant_id, NEW.item_id, NEW.item_name, IF(NEW.quantity > OLD.quantity, 'ADD', 'SUBTRACT'), ABS(NEW.quantity - OLD.quantity), NEW.quantity, 'Stock level modified');
              END IF;
            END");
        } catch (Exception $e) {}

        try {
          $db->exec("DROP TRIGGER IF EXISTS after_inventory_insert");
          $db->exec("CREATE TRIGGER after_inventory_insert AFTER INSERT ON inventory FOR EACH ROW
            BEGIN
              IF NEW.quantity > 0 THEN
                INSERT INTO inventory_history (tenant_id, item_id, item_name, transaction_type, quantity_changed, new_quantity, notes)
                VALUES (NEW.tenant_id, NEW.item_id, NEW.item_name, 'ADD', NEW.quantity, NEW.quantity, 'Initial stock');
              END IF;
            END");
        } catch (Exception $e) {}

        // Repair Parts Link (Inventory -> Job Order)
        $db->exec("CREATE TABLE IF NOT EXISTS repair_parts (
          rp_id INT AUTO_INCREMENT PRIMARY KEY,
          tenant_id INT NOT NULL,
          job_id INT NOT NULL,
          item_id INT NOT NULL,
          quantity INT DEFAULT 1,
          unit_price DECIMAL(10,2) DEFAULT 0,
          total_price DECIMAL(10,2) DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Patch if subtotal exists instead
        try {
          $db->exec("ALTER TABLE repair_parts CHANGE subtotal total_price DECIMAL(10,2)");
        } catch (Exception $e) {
        }
        try {
          $db->exec("ALTER TABLE repair_parts ADD COLUMN tenant_id INT NOT NULL AFTER rp_id");
        } catch (Exception $e) {
        }


        // Audit Logs
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

        // Add shift_days to mechanics if missing
        $checkCol = $db->query("SHOW COLUMNS FROM mechanics LIKE 'shift_days'");
        if (!$checkCol->fetch()) {
          $db->exec("ALTER TABLE mechanics ADD COLUMN shift_days VARCHAR(255) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat'");
        }

        $checkReqCol = $db->query("SHOW COLUMNS FROM shift_requests LIKE 'requested_days'");
        if (!$checkReqCol->fetch()) {
          $db->exec("ALTER TABLE shift_requests ADD COLUMN requested_days VARCHAR(255) NULL");
        }
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

      // Service Bays with active job info
      $stmt = $db->prepare("SELECT b.*, 
                   (SELECT job_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as active_job_id,
                   (SELECT status FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as job_status,
                   (SELECT mechanic_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as active_mechanic_id,
                   (SELECT COALESCE(v.plate_no, r.walkin_plate) FROM repair_jobs r LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id WHERE r.bay_id = b.bay_id AND r.tenant_id = b.tenant_id AND r.status IN ('PENDING', 'IN_PROGRESS') ORDER BY r.created_at DESC LIMIT 1) as plate_no,
                   (SELECT m.full_name FROM repair_jobs r JOIN mechanics m ON r.mechanic_id = m.mechanic_id WHERE r.bay_id = b.bay_id AND r.tenant_id = b.tenant_id AND r.status IN ('PENDING', 'IN_PROGRESS') ORDER BY r.created_at DESC LIMIT 1) as mechanic_name
                   FROM service_bays b 
                   WHERE b.tenant_id = ? 
                   ORDER BY b.bay_id ASC");
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
      try {
        $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER name");
      } catch (Exception $e) {
      }
      $stmt = $db->prepare("SELECT user_id, name, email, profile_pic, role_id, status FROM users WHERE tenant_id = ? ORDER BY role_id ASC");
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

      // --- PING TEST ---
      if ($_GET['action'] === 'test_ping') {
        echo "PONG";
        exit;
      }

      // --- DASHBOARD JOBS HANDLER ---
      if ($_GET['action'] === 'fetch_dashboard_jobs_diagnostic') {
        @ob_clean();
        header('Content-Type: application/json');
        try {
          $query = "SELECT 
                      j.job_id, 
                      j.status, 
                      j.mechanic_id,
                      j.bay_id,
                      j.appointment_id,
                      COALESCE(v.plate_no, v2.plate_no, j.walkin_plate, 'N/A') AS plate_no,
                      COALESCE(v.make, v2.make, '') AS make,
                      COALESCE(v.model, v2.model, j.walkin_model, '---') AS model,
                      COALESCE(s.service_name, 'General Repair') AS service_name,
                      COALESCE(m.full_name, u.name, 'Unassigned') AS mechanic_name,
                      j.created_at,
                      j.total_amount,
                      j.customer_id,
                      COALESCE(c.full_name, j.walkin_name, 'Walk-in') AS customer_name
                    FROM repair_jobs j
                    LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                    LEFT JOIN vehicles v2 ON v2.vehicle_id = (
                        SELECT v3.vehicle_id FROM vehicles v3 
                        WHERE v3.customer_id = j.customer_id 
                        LIMIT 1
                    ) AND v.plate_no IS NULL
                    LEFT JOIN services s ON j.service_id = s.service_id
                    LEFT JOIN mechanics m ON j.mechanic_id = m.mechanic_id AND m.tenant_id = j.tenant_id
                    LEFT JOIN users u ON m.user_id = u.user_id AND u.tenant_id = j.tenant_id
                    LEFT JOIN customers c ON j.customer_id = c.customer_id";

          $currentRole = strtoupper($_SESSION['role'] ?? '');

          if ($currentRole === 'CASHIER' || $currentRole === 'STAFF') {
            // CASHIER: Only show jobs that are COMPLETED and ready for payment collection
            $query .= " WHERE j.tenant_id = ? AND j.status = 'COMPLETED'";
          } elseif ($currentRole === 'MECHANIC') {
            // MECHANIC: User only wants to see jobs that are currently being worked on
            $query .= " WHERE j.tenant_id = ? AND j.status = 'IN_PROGRESS'";
          } else {
            // ADMIN: Needs to see the full queue (Upcoming and Active)
            $query .= " WHERE j.tenant_id = ? AND j.status IN ('PENDING', 'IN_PROGRESS')";
          }

          $params = [$tenant_id];

          if ($currentRole === 'MECHANIC') {
            $mStmt = $db->prepare("SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?");
            $mStmt->execute([$_SESSION['user_id'], $tenant_id]);
            $my_mid = $mStmt->fetchColumn();
            if ($my_mid) {
              $query .= " AND j.mechanic_id = ?";
              $params[] = $my_mid;
            } else {
              @ob_clean();
              echo json_encode([]);
              exit;
            }
          }
          $query .= " ORDER BY j.updated_at DESC LIMIT 20";
          $stmt = $db->prepare($query);
          $stmt->execute($params);
          $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($res ?: [], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (Throwable $e) {
          @ob_clean();
          echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_settlement_history') {
        @ob_clean();
        header('Content-Type: application/json');
        try {
          $query = "SELECT 
                      j.job_id, 
                      j.status, 
                      COALESCE(v.plate_no, v2.plate_no, j.walkin_plate, 'N/A') AS plate_no,
                      COALESCE(v.make, v2.make, '') AS make,
                      COALESCE(v.model, v2.model, j.walkin_model, '---') AS model,
                      COALESCE(s.service_name, 'General Repair') AS service_name,
                      j.created_at,
                      j.total_amount,
                      j.customer_id,
                      COALESCE(c.full_name, j.walkin_name, 'Walk-in') AS customer_name
                    FROM repair_jobs j
                    LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                    LEFT JOIN vehicles v2 ON v2.vehicle_id = (SELECT v3.vehicle_id FROM vehicles v3 WHERE v3.customer_id = j.customer_id LIMIT 1) AND v.plate_no IS NULL
                    LEFT JOIN services s ON j.service_id = s.service_id
                    LEFT JOIN customers c ON j.customer_id = c.customer_id
                    WHERE j.tenant_id = ? AND (j.status IN ('COMPLETED', 'SETTLED')) AND j.payment_status = 'PAID'
                    ORDER BY j.updated_at DESC LIMIT 50";
          $stmt = $db->prepare($query);
          $stmt->execute([$tenant_id]);
          $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($res ?: []);
        } catch (Throwable $e) {
          echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
      }

      // --- SERVICES ---
      if ($_GET['action'] === 'add_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $name = trim($_POST['service_name'] ?? '');
          $desc = trim($_POST['description'] ?? '');
          $price = floatval($_POST['price'] ?? 0);
          $min_price = (isset($_POST['min_price']) && $_POST['min_price'] !== '') ? floatval($_POST['min_price']) : null;
          $max_price = (isset($_POST['max_price']) && $_POST['max_price'] !== '') ? floatval($_POST['max_price']) : null;

          if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Service name is required.']);
            exit;
          }

          $masterId = !empty($_POST['master_id']) ? intval($_POST['master_id']) : null;

<<<<<<< HEAD
          if ($enablePriceLimits && $min_price !== null && $max_price !== null) {
              if ($price < $min_price || $price > $max_price) {
                throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($min_price) . " and ₱" . number_format($max_price));
=======
          if ($masterId) {
            $ms = $db->prepare("SELECT * FROM master_services WHERE master_id = ?");
            $ms->execute([$masterId]);
            $standard = $ms->fetch();
            if ($standard) {
              if ($price < $standard['min_price'] || $price > $standard['max_price']) {
                throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($standard['min_price']) . " and ₱" . number_format($standard['max_price']));
>>>>>>> b06e76f6ed1f975805a82d2ac66b7861d774451c
              }
          }

          $stmt = $db->prepare("INSERT INTO services (tenant_id, master_id, service_name, description, price, min_price, max_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
          if ($stmt->execute([$tenant_id, $masterId, $name, $desc, $price, $min_price, $max_price])) {
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
          $min_price = (isset($_POST['min_price']) && $_POST['min_price'] !== '') ? floatval($_POST['min_price']) : null;
          $max_price = (isset($_POST['max_price']) && $_POST['max_price'] !== '') ? floatval($_POST['max_price']) : null;
          $masterId = !empty($_POST['master_id']) ? intval($_POST['master_id']) : null;

          if (empty($name) || empty($id)) {
            echo json_encode(['status' => 'error', 'message' => 'Service ID and name are required.']);
            exit;
          }

<<<<<<< HEAD
          $checkTenant = $db->prepare("SELECT enable_price_limits FROM tenants WHERE tenant_id = ?");
          $checkTenant->execute([$tenant_id]);
          $enablePriceLimits = intval($checkTenant->fetchColumn() ?? 1);

          if ($enablePriceLimits && $min_price !== null && $max_price !== null) {
              if ($price < $min_price || $price > $max_price) {
                throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($min_price) . " and ₱" . number_format($max_price));
=======
          if ($masterId) {
            $ms = $db->prepare("SELECT * FROM master_services WHERE master_id = ?");
            $ms->execute([$masterId]);
            $standard = $ms->fetch();
            if ($standard) {
              if ($price < $standard['min_price'] || $price > $standard['max_price']) {
                throw new Exception("Price Out of Bounds! This service must be between ₱" . number_format($standard['min_price']) . " and ₱" . number_format($standard['max_price']));
>>>>>>> b06e76f6ed1f975805a82d2ac66b7861d774451c
              }
          }

          // AUTO-HEAL: Ensure description column exists
          try {
            $db->exec("ALTER TABLE services ADD COLUMN description TEXT AFTER service_name");
          } catch (Exception $e) {
          }

          $stmt = $db->prepare("UPDATE services SET master_id=?, service_name=?, description=?, price=?, min_price=?, max_price=? WHERE service_id=? AND tenant_id=?");
          if ($stmt->execute([$masterId, $name, $desc, $price, $min_price, $max_price, $id, $tenant_id])) {
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
        try {
          $stmt = $db->prepare("SELECT v.*, c.full_name as customer_name 
                    FROM vehicles v 
                    LEFT JOIN customers c ON v.customer_id = c.customer_id 
                    WHERE v.tenant_id = ? 
                    ORDER BY v.vehicle_id DESC");
          $stmt->execute([$tenant_id]);
          $res = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

          ob_clean(); // BURAHIN ANG ANUMANG WHITESPACE O WARNINGS SA TAAS
          header('Content-Type: application/json');
          echo json_encode($res);
        } catch (Exception $e) {
          ob_clean();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_receipt_data') {
        try {
          $id = intval($_GET['job_id'] ?? 0);
          $stmt = $db->prepare("SELECT j.*, 
                               COALESCE(c.full_name, j.walkin_name) as customer, 
                               COALESCE(v.plate_no, v2.plate_no, j.walkin_plate) as plate_no, 
                               COALESCE(v.make, v2.make, '') as make, 
                               COALESCE(v.model, v2.model, j.walkin_model) as model, 
                               s.service_name 
                               FROM repair_jobs j 
                               LEFT JOIN customers c ON j.customer_id = c.customer_id 
                               LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id 
                               LEFT JOIN vehicles v2 ON v2.vehicle_id = (SELECT v3.vehicle_id FROM vehicles v3 WHERE v3.customer_id = j.customer_id LIMIT 1) AND v.plate_no IS NULL 
                               LEFT JOIN services s ON j.service_id = s.service_id 
                               WHERE j.job_id = ? AND j.tenant_id = ?");
          $stmt->execute([$id, $tenant_id]);
          $j = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$j)
            exit("Receipt not found.");

          $shop = $db->prepare("SELECT shop_name, address, phone FROM tenants WHERE tenant_id = ?");
          $shop->execute([$tenant_id]);
          $s = $shop->fetch(PDO::FETCH_ASSOC);
          $sn = $s['shop_name'] ?? 'Auto Shop';

          ob_clean();
          echo "<div style='text-align:center; margin-bottom:1.5rem; border-bottom:1px dashed #ccc; padding-bottom:1rem;'>
                  <h2 style='margin:0;'>$sn</h2>
                  <p style='font-size:0.8rem; color:#666; margin:5px 0;'>" . ($s['address'] ?? '') . "</p>
                  <p style='font-size:0.8rem; color:#666; margin:0;'>Tel: " . ($s['phone'] ?? '') . "</p>
                </div>
                <div style='font-size:0.9rem; line-height:1.6;'>
                  <p><strong>Job ID:</strong> #{$j['job_id']}</p>
                  <p><strong>Date:</strong> " . date('M d, Y h:i A', strtotime($j['created_at'])) . "</p>
                  <p><strong>Customer:</strong> " . ($j['customer'] ?: $j['walkin_name']) . "</p>
                  <p><strong>Plate:</strong> " . ($j['plate_no'] ?: $j['walkin_plate']) . "</p>
                  <div style='margin:1rem 0; border-top:1px solid #eee; border-bottom:1px solid #eee; padding:10px 0;'>
                    <div style='display:flex; justify-content:space-between;'>
                      <span>{$j['service_name']}</span>
                      <strong>₱" . number_format($j['total_amount'], 2) . "</strong>
                    </div>
                  </div>
                  <div style='display:flex; justify-content:space-between; font-size:1.1rem; font-weight:bold;'>
                    <span>TOTAL:</span>
                    <span>₱" . number_format($j['total_amount'], 2) . "</span>
                  </div>
                </div>
                <div style='margin-top:2rem; text-align:center; font-size:0.8rem; color:#999;'>
                  <p>Thank you for choosing $sn!</p>
                  <p>This is your official service receipt.</p>
                </div>";
          exit;
        } catch (Exception $e) {
          exit("Error.");
        }
      }

      if ($_GET['action'] === 'fetch_services') {
        $stmt = $db->prepare("SELECT s.*, m.min_price, m.max_price FROM services s LEFT JOIN master_services m ON s.master_id = m.master_id WHERE s.tenant_id = ? ORDER BY s.service_name ASC");
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
          try {
            $db->exec("ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE users ADD COLUMN address TEXT NULL");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE users ADD COLUMN id_type VARCHAR(50) NULL");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE users ADD COLUMN id_file VARCHAR(255) NULL");
          } catch (Exception $e) {
          }

          $stmt = $db->prepare("INSERT INTO users (tenant_id, name, email, password_hash, role_id, mobile, address, id_type, id_file, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
          $stmt->execute([$tenant_id, $name, $email, $hash, $role_id, $mobile, $address, $id_type, $id_file_path]);
          $newUserId = $db->lastInsertId();

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

      if ($_GET['action'] === 'fetch_mechanic_history') {
        try {
          $mStmt = $db->prepare("SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?");
          $mStmt->execute([$_SESSION['user_id'], $tenant_id]);
          $my_mid = $mStmt->fetchColumn();
          if (!$my_mid) {
            echo json_encode([]);
            exit;
          }

          $stmt = $db->prepare("SELECT t.*, 
                                 COALESCE(v.plate_no, v2.plate_no, j.walkin_plate, 'N/A') as plate_no, 
                                 COALESCE(v.make, v2.make, '') as make, 
                                 COALESCE(v.model, v2.model, j.walkin_model, '---') as model, 
                                 j.status as current_status
                     FROM repair_timeline t
                     JOIN repair_jobs j ON t.job_id = j.job_id
                     LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                     LEFT JOIN vehicles v2 ON v2.vehicle_id = (SELECT v3.vehicle_id FROM vehicles v3 WHERE v3.customer_id = j.customer_id LIMIT 1) AND v.plate_no IS NULL
                     WHERE t.user_id = ? AND t.tenant_id = ? AND t.status_update = 'COMPLETED'
                     ORDER BY t.created_at DESC LIMIT 50");
          $stmt->execute([$_SESSION['user_id'], $tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_inventory_lookup') {
        try {
          $stmt = $db->prepare("SELECT item_name, brand, quantity, stock_threshold, status FROM inventory WHERE tenant_id = ? ORDER BY item_name ASC");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }


      if ($_GET['action'] === 'fetch_staff') {
        ob_clean(); // Ensure no extra output
        try {
          // Database Patch (Ensure column exists)
          try {
            $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER name");
          } catch (Exception $e) {
          }

          $stmt = $db->prepare("SELECT u.user_id, u.name, u.email, u.profile_pic, r.role_name, u.status FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.tenant_id = ? ORDER BY u.email DESC");
          $stmt->execute([$tenant_id]);
          header('Content-Type: application/json');
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          header('Content-Type: application/json');
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_staff_details') {
        try {
          // Ensure columns exist (Database Patch)
          try {
            $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email");
          } catch (Exception $e) {
          }
          try {
            $db->exec("ALTER TABLE users ADD COLUMN address TEXT NULL AFTER phone");
          } catch (Exception $e) {
          }

          $uid = intval($_GET['user_id'] ?? 0);
          $stmt = $db->prepare("SELECT u.user_id, u.name, u.email, u.phone, u.address, u.profile_pic, r.role_name, u.status, u.created_at FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ? AND u.tenant_id = ?");
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
                   (SELECT job_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as active_job_id,
                   (SELECT status FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as job_status,
                   (SELECT mechanic_id FROM repair_jobs WHERE bay_id = b.bay_id AND tenant_id = b.tenant_id AND status IN ('PENDING', 'IN_PROGRESS') ORDER BY created_at DESC LIMIT 1) as active_mechanic_id,
                   (SELECT COALESCE(v.plate_no, r.walkin_plate) FROM repair_jobs r LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id WHERE r.bay_id = b.bay_id AND r.tenant_id = b.tenant_id AND r.status IN ('PENDING', 'IN_PROGRESS') ORDER BY r.created_at DESC LIMIT 1) as plate_no,
                   (SELECT m.full_name FROM repair_jobs r JOIN mechanics m ON r.mechanic_id = m.mechanic_id WHERE r.bay_id = b.bay_id AND r.tenant_id = b.tenant_id AND r.status IN ('PENDING', 'IN_PROGRESS') ORDER BY r.created_at DESC LIMIT 1) as mechanic_name
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

        // Fetch current job (only truly active ones)
        $stmt = $db->prepare("SELECT r.*, 
                   COALESCE(v.plate_no, r.walkin_plate) as plate_no, 
                   COALESCE(v.make, '') as make, 
                   COALESCE(v.model, r.walkin_model) as model, 
                   COALESCE(c.full_name, r.walkin_name) as customer_name, 
                   s.service_name 
                   FROM repair_jobs r
                   LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                   LEFT JOIN customers c ON r.customer_id = c.customer_id
                   LEFT JOIN services s ON r.service_id = s.service_id
                   WHERE r.bay_id = ? AND r.tenant_id = ? AND r.status IN ('PENDING', 'IN_PROGRESS')
                   ORDER BY r.created_at DESC LIMIT 1");
        $stmt->execute([$bay_id, $tenant_id]);
        $current_job = $stmt->fetch(PDO::FETCH_ASSOC);

        // Auto-sync bay status based on actual active jobs
        if ($current_job) {
          $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ? AND tenant_id = ? AND status != 'OCCUPIED'")->execute([$bay_id, $tenant_id]);
          $bay['status'] = 'OCCUPIED';
        } else {
          $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ? AND status = 'OCCUPIED'")->execute([$bay_id, $tenant_id]);
          $bay['status'] = 'AVAILABLE';
        }

        // Fetch history (last 5) - include SETTLED jobs too
        $stmt = $db->prepare("SELECT r.*, 
                   COALESCE(v.plate_no, r.walkin_plate) as plate_no, 
                   s.service_name, 
                   COALESCE(c.full_name, r.walkin_name) as customer_name
                   FROM repair_jobs r
                   LEFT JOIN vehicles v ON r.vehicle_id = v.vehicle_id
                   LEFT JOIN customers c ON r.customer_id = c.customer_id
                   LEFT JOIN services s ON r.service_id = s.service_id
                   WHERE r.bay_id = ? AND r.tenant_id = ? AND r.status IN ('COMPLETED', 'CANCELLED', 'SETTLED')
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
        @ob_clean();
        header('Content-Type: application/json');
        $mech_id = $_GET['mechanic_id'] ?? 0;
        $stmt = $db->prepare("SELECT m.*, 
                   (SELECT COUNT(*) FROM repair_jobs WHERE mechanic_id = m.mechanic_id AND status = 'IN_PROGRESS' AND tenant_id = m.tenant_id) as active_jobs_count
                   FROM mechanics m 
                   WHERE m.mechanic_id = ? AND m.tenant_id = ?");
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
        $shift_start = $_POST['shift_start'] ?? '08:00:00';
        $shift_end = $_POST['shift_end'] ?? '17:00:00';
        $shift_days_arr = $_POST['shift_days'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $shift_days = is_array($shift_days_arr) ? implode(',', $shift_days_arr) : trim($shift_days_arr);
        if (empty($shift_days))
          $shift_days = 'Mon,Tue,Wed,Thu,Fri,Sat';

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
          $userStmt = $db->prepare("INSERT INTO users (tenant_id, email, password_hash, name, role_id, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
          $userStmt->execute([$tenant_id, $email, $passHash, $name, 5]);
          $userId = $db->lastInsertId();

          // 2. Link to Mechanic Table
          $stmt = $db->prepare("INSERT INTO mechanics (tenant_id, full_name, specialization, status, user_id, shift_start, shift_end, shift_days) VALUES (?, ?, ?, 'AVAILABLE', ?, ?, ?, ?)");
          $stmt->execute([$tenant_id, $name, $spec, $userId, $shift_start, $shift_end, $shift_days]);

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
               END as display_name,
               (SELECT COUNT(*) FROM repair_jobs WHERE mechanic_id = m.mechanic_id AND status = 'IN_PROGRESS' AND tenant_id = m.tenant_id) as active_jobs_count
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

      if ($_GET['action'] === 'update_mechanic_shift' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          // Role Check
          $auth_role = strtoupper($_SESSION['role'] ?? '');
          if ($auth_role !== 'OWNER' && $auth_role !== 'MANAGER') {
            throw new Exception("Unauthorized: Only owners and managers can update shifts.");
          }

          $id = intval($_POST['mechanic_id'] ?? 0);
          $start = $_POST['shift_start'] ?? '08:00:00';
          $end = $_POST['shift_end'] ?? '17:00:00';
          $shift_days_arr = $_POST['shift_days'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
          $shift_days = is_array($shift_days_arr) ? implode(',', $shift_days_arr) : trim($shift_days_arr);
          if (empty($shift_days))
            $shift_days = 'Mon,Tue,Wed,Thu,Fri,Sat';

          if (!$id)
            throw new Exception("Mechanic ID is required.");
          $stmt = $db->prepare("UPDATE mechanics SET shift_start = ?, shift_end = ?, shift_days = ? WHERE mechanic_id = ? AND tenant_id = ?");
          $stmt->execute([$start, $end, $shift_days, $id, $tenant_id]);
          echo json_encode(['status' => 'success', 'message' => 'Shift schedule updated successfully.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'update_mechanic_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $auth_role = strtoupper($_SESSION['role'] ?? '');
          if ($auth_role !== 'OWNER' && $auth_role !== 'MANAGER') {
            throw new Exception("Unauthorized: Only owners and managers can update mechanic availability.");
          }

          $id = intval($_POST['mechanic_id'] ?? 0);
          $new_status = $_POST['status'] ?? 'AVAILABLE';
          
          if (!$id) {
            throw new Exception("Mechanic ID is required.");
          }
          
          if (!in_array($new_status, ['AVAILABLE', 'UNAVAILABLE'])) {
            throw new Exception("Invalid status specified.");
          }

          $stmt = $db->prepare("UPDATE mechanics SET status = ? WHERE mechanic_id = ? AND tenant_id = ?");
          $stmt->execute([$new_status, $id, $tenant_id]);

          // Write activity log
          try {
            $stmtName = $db->prepare("SELECT CASE WHEN full_name IS NOT NULL AND full_name != '' THEN full_name ELSE 'Expert Mechanic' END FROM mechanics WHERE mechanic_id = ?");
            $stmtName->execute([$id]);
            $mech_name = $stmtName->fetchColumn() ?: "Mechanic #$id";
            $log = $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'CRUD', ?)");
            $log->execute([$tenant_id, $_SESSION['user_id'] ?? 0, "Updated mechanic ($mech_name) status to: $new_status"]);
          } catch (Exception $logErr) {
          }

          echo json_encode(['status' => 'success', 'message' => 'Mechanic status updated successfully.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'request_shift_change' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $mechanic_user_id = $_SESSION['user_id'];
          // Get mechanic_id for this user
          $stmt = $db->prepare("SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?");
          $stmt->execute([$mechanic_user_id, $tenant_id]);
          $m_id = $stmt->fetchColumn();
          if (!$m_id)
            throw new Exception("Mechanic record not found.");

          $start = $_POST['shift_start'] ?? '08:00:00';
          $end = $_POST['shift_end'] ?? '17:00:00';
          $reason = trim($_POST['reason'] ?? '');
          $shift_days_arr = $_POST['shift_days'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
          $shift_days = is_array($shift_days_arr) ? implode(',', $shift_days_arr) : trim($shift_days_arr);
          if (empty($shift_days))
            $shift_days = 'Mon,Tue,Wed,Thu,Fri,Sat';

          $stmt = $db->prepare("INSERT INTO shift_requests (tenant_id, mechanic_id, requested_start, requested_end, requested_days, reason) VALUES (?, ?, ?, ?, ?, ?)");
          $stmt->execute([$tenant_id, $m_id, $start, $end, $shift_days, $reason]);

          echo json_encode(['status' => 'success', 'message' => 'Shift change request submitted.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_shift_requests') {
        $stmt = $db->prepare("SELECT sr.*, COALESCE(m.full_name, 'Unknown Mechanic') as full_name FROM shift_requests sr LEFT JOIN mechanics m ON sr.mechanic_id = m.mechanic_id WHERE sr.tenant_id = ? AND sr.status = 'PENDING' ORDER BY sr.created_at DESC");
        $stmt->execute([$tenant_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
      }

      if ($_GET['action'] === 'process_shift_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $auth_role = strtoupper($_SESSION['role'] ?? '');
          if ($auth_role !== 'OWNER' && $auth_role !== 'MANAGER')
            throw new Exception("Unauthorized.");

          $req_id = intval($_POST['request_id']);
          $status = $_POST['status']; // APPROVED or REJECTED

          $db->beginTransaction();
          $stmt = $db->prepare("UPDATE shift_requests SET status = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE request_id = ? AND tenant_id = ?");
          $stmt->execute([$status, $_SESSION['user_id'], $req_id, $tenant_id]);

          if ($status === 'APPROVED') {
            $stmt = $db->prepare("SELECT requested_start, requested_end, requested_days, mechanic_id FROM shift_requests WHERE request_id = ?");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch();
            if ($req) {
              $upd = $db->prepare("UPDATE mechanics SET shift_start = ?, shift_end = ?, shift_days = COALESCE(?, shift_days) WHERE mechanic_id = ?");
              $upd->execute([$req['requested_start'], $req['requested_end'], $req['requested_days'] ?: null, $req['mechanic_id']]);
            }
          }
          $db->commit();
          echo json_encode(['status' => 'success', 'message' => "Request $status successfully."]);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      // --- INVENTORY ---
      if ($_GET['action'] === 'add_inventory' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $item_code = trim($_POST['item_code'] ?? '');
          $item_name = trim($_POST['item_name'] ?? '');
          $brand = trim($_POST['brand'] ?? '');
          $qty = intval($_POST['quantity'] ?? 0);
          $price = floatval($_POST['price'] ?? 0);

          if (empty($item_name))
            throw new Exception("Item name is required.");

          $stmt = $db->prepare("INSERT INTO inventory (tenant_id, item_code, item_name, brand, quantity, price, stock_threshold, status) VALUES (?, ?, ?, ?, ?, ?, 5, 'IN_STOCK')");
          $stmt->execute([$tenant_id, $item_code, $item_name, $brand, $qty, $price]);

          echo json_encode(['status' => 'success', 'message' => 'Item added to inventory.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }



      if ($_GET['action'] === 'remove_part_from_job') {
        try {
          $rpId = intval($_GET['rp_id'] ?? 0);

          // Get details to return stock
          $stmt = $db->prepare("SELECT item_id, quantity FROM repair_parts WHERE rp_id = ?");
          $stmt->execute([$rpId]);
          $part = $stmt->fetch(PDO::FETCH_ASSOC);

          if ($part) {
            $stmt = $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?");
            $stmt->execute([$part['quantity'], $part['item_id']]);

            $stmt = $db->prepare("DELETE FROM repair_parts WHERE rp_id = ?");
            $stmt->execute([$rpId]);
          }

          echo json_encode(['status' => 'success', 'message' => 'Part removed and stock returned.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }



      // --- REPAIR JOBS ENGINE (NEW) ---
      if ($_GET['action'] === 'update_job_part_qty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $rp_id = intval($_POST['rp_id'] ?? 0);
          $new_qty = intval($_POST['quantity'] ?? 0);
          if ($new_qty < 1)
            throw new Exception("Quantity must be at least 1.");

          $db->beginTransaction();
          $stmt = $db->prepare("SELECT rp.*, i.quantity as stock FROM repair_parts rp JOIN inventory i ON rp.item_id = i.item_id WHERE rp.rp_id = ? AND rp.tenant_id = ?");
          $stmt->execute([$rp_id, $tenant_id]);
          $rp = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$rp)
            throw new Exception("Record not found.");

          $diff = $new_qty - $rp['quantity'];
          if ($diff > 0 && $rp['stock'] < $diff)
            throw new Exception("Not enough stock.");

          $new_total = $rp['unit_price'] * $new_qty;
          $db->prepare("UPDATE repair_parts SET quantity = ?, total_price = ? WHERE rp_id = ?")->execute([$new_qty, $new_total, $rp_id]);
          $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?")->execute([$diff, $rp['item_id']]);

          $db->commit();
          echo json_encode(['status' => 'success', 'message' => 'Quantity updated.']);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'get_job_details') {
        try {
          $jid = intval($_GET['id'] ?? 0);
          $stmt = $db->prepare("SELECT j.*, 
                                COALESCE(v.plate_no, v2.plate_no, j.walkin_plate, 'N/A') as plate_number, 
                                COALESCE(v.model, v2.model, j.walkin_model, 'Walk-in') as vehicle_model, 
                                s.service_name,
                                s.price as service_price 
                                FROM repair_jobs j 
                                LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id 
                                LEFT JOIN vehicles v2 ON j.customer_id = v2.customer_id AND v.plate_no IS NULL
                                LEFT JOIN services s ON j.service_id = s.service_id 
                                WHERE j.job_id = ? AND j.tenant_id = ?");
          $stmt->execute([$jid, $tenant_id]);
          echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode(null);
        }
        exit;
      }

      // Redundant fetch_available_resources removed (Moved to consolidated handler below)


      if ($_GET['action'] === 'add_part_to_job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $jid = intval($_POST['job_id'] ?? 0);
          $iid = intval($_POST['item_id'] ?? 0);
          $qty = intval($_POST['quantity'] ?? 1);

          $db->beginTransaction();
          // Check stock
          $iStmt = $db->prepare("SELECT quantity, item_name, price FROM inventory WHERE item_id = ? AND tenant_id = ?");
          $iStmt->execute([$iid, $tenant_id]);
          $item = $iStmt->fetch(PDO::FETCH_ASSOC);

          if (!$item || $item['quantity'] < $qty)
            throw new Exception("Insufficient stock for " . ($item['item_name'] ?? 'item'));

          // Add to repair_parts
          $pStmt = $db->prepare("INSERT INTO repair_parts (job_id, tenant_id, item_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
          $total = $item['price'] * $qty;
          $pStmt->execute([$jid, $tenant_id, $iid, $qty, $item['price'], $total]);

          // Deduct from inventory
          $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ?")->execute([$qty, $iid]);

          $db->commit();

          // Sync Job Total for accuracy
          try {
            $db->prepare("UPDATE repair_jobs j SET total_amount = (COALESCE((SELECT price FROM services WHERE service_id = j.service_id), 0) + COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = j.job_id), 0)) WHERE j.job_id = ? AND j.tenant_id = ? AND j.status != 'SETTLED'")->execute([$jid, $tenant_id]);
          } catch (Exception $ex) {
          }

          echo json_encode(['status' => 'success', 'message' => 'Part added to job.']);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'add_service_to_job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $jid = intval($_POST['job_id'] ?? 0);
          $sid = intval($_POST['service_id'] ?? 0);

          $db->beginTransaction();

          // Check if already added
          $check = $db->prepare("SELECT rp_id FROM repair_parts WHERE job_id = ? AND service_id = ? AND tenant_id = ?");
          $check->execute([$jid, $sid, $tenant_id]);
          if ($check->fetch()) {
            throw new Exception("This service has already been added to this job.");
          }

          $sStmt = $db->prepare("SELECT service_name, price FROM services WHERE service_id = ? AND tenant_id = ?");
          $sStmt->execute([$sid, $tenant_id]);
          $service = $sStmt->fetch(PDO::FETCH_ASSOC);
          if (!$service)
            throw new Exception("Service not found.");

          $pStmt = $db->prepare("INSERT INTO repair_parts (job_id, tenant_id, service_id, quantity, unit_price, total_price) VALUES (?, ?, ?, 1, ?, ?)");
          $pStmt->execute([$jid, $tenant_id, $sid, $service['price'], $service['price']]);

          $db->commit();

          // Sync Job Total
          $db->prepare("UPDATE repair_jobs j SET total_amount = (COALESCE((SELECT price FROM services WHERE service_id = j.service_id), 0) + COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = j.job_id), 0)) WHERE j.job_id = ? AND j.tenant_id = ? AND j.status != 'SETTLED'")->execute([$jid, $tenant_id]);

          echo json_encode(['status' => 'success', 'message' => 'Service added to job.']);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'remove_part_from_job') {
        try {
          $rp_id = intval($_GET['rp_id'] ?? 0);
          $db->beginTransaction();

          $sStmt = $db->prepare("SELECT item_id, quantity FROM repair_parts WHERE rp_id = ? AND tenant_id = ?");
          $sStmt->execute([$rp_id, $tenant_id]);
          $rp = $sStmt->fetch(PDO::FETCH_ASSOC);

          if ($rp) {
            $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?")->execute([$rp['quantity'], $rp['item_id']]);
            $db->prepare("DELETE FROM repair_parts WHERE rp_id = ?")->execute([$rp_id]);
          }

          $db->commit();

          // Sync Job Total for accuracy
          try {
            $db->prepare("UPDATE repair_jobs j SET total_amount = (COALESCE((SELECT price FROM services WHERE service_id = j.service_id), 0) + COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = j.job_id), 0)) WHERE j.job_id = ? AND j.tenant_id = ? AND j.status != 'SETTLED'")->execute([$jid, $tenant_id]);
          } catch (Exception $ex) {
          }

          echo json_encode(['status' => 'success', 'message' => 'Part removed.']);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_job_parts') {
        try {
          $jid = intval($_GET['job_id'] ?? 0);
          $stmt = $db->prepare("SELECT rp.*, COALESCE(i.item_name, s.service_name, 'Unknown Item') as item_name 
                                FROM repair_parts rp 
                                LEFT JOIN inventory i ON rp.item_id = i.item_id 
                                LEFT JOIN services s ON rp.service_id = s.service_id
                                WHERE rp.job_id = ? AND rp.tenant_id = ?");
          $stmt->execute([$jid, $tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_inventory') {
        try {
          $stmt = $db->prepare("SELECT * FROM inventory WHERE tenant_id = ? ORDER BY item_id DESC");
          $stmt->execute([$tenant_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'adjust_stock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $itemId = intval($_POST['item_id'] ?? 0);
          $newQty = intval($_POST['quantity'] ?? 0);
          $newPrice = floatval($_POST['price'] ?? 0);

          $stmt = $db->prepare("UPDATE inventory SET quantity = ?, price = ?, stock_threshold = 5 WHERE item_id = ? AND tenant_id = ?");
          $stmt->execute([$newQty, $newPrice, $itemId, $tenant_id]);

          echo json_encode(['status' => 'success', 'message' => 'Stock adjusted successfully.']);
        } catch (Exception $e) {
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_item_details') {
        try {
          $itemId = intval($_GET['item_id'] ?? 0);
          $stmt = $db->prepare("SELECT * FROM inventory WHERE item_id = ? AND tenant_id = ?");
          $stmt->execute([$itemId, $tenant_id]);
          echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
        }
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
        // 1. Low stock alerts
        $stmt1 = $db->prepare("SELECT item_name, quantity FROM inventory WHERE tenant_id = ? AND quantity < 5 ORDER BY quantity ASC");
        $stmt1->execute([$tenant_id]);
        $low_stock = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // 2. Stock movement history/log
        $stmt2 = $db->prepare("SELECT item_name, transaction_type, quantity_changed, new_quantity, notes, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as date FROM inventory_history WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt2->execute([$tenant_id]);
        $movement_history = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
          'low_stock' => $low_stock ?: [],
          'history' => $movement_history ?: []
        ]);
        exit;
      }
      if ($_GET['action'] === 'get_mechanic_performance') {
        try {
          $stmt = $db->prepare("
            SELECT 
              m.full_name, 
              COALESCE(m.specialization, 'General') as specialization, 
              COUNT(j.job_id) as count, 
              COALESCE(SUM(j.total_amount), 0) as total_revenue,
              COALESCE(AVG(j.total_amount), 0) as avg_job_cost
            FROM mechanics m
            LEFT JOIN repair_jobs j ON j.mechanic_id = m.mechanic_id AND j.status IN ('COMPLETED', 'SETTLED') 
            WHERE m.tenant_id = ?
            GROUP BY m.mechanic_id, m.full_name, m.specialization 
            ORDER BY count DESC, total_revenue DESC 
            LIMIT 10
          ");
          $stmt->execute([$tenant_id]);
          $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($results ?: []);
        } catch (Exception $e) {
          echo json_encode([]);
        }
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

          @ob_clean();
          echo json_encode([
            'status' => 'success',
            'message' => 'Setting updated successfully!',
            'field' => $field,
            'value' => $value
          ]);

          // Audit log
          try {
            $db->prepare("INSERT INTO audit_logs (tenant_id, activity_type, description) VALUES (?, 'SETTINGS', ?)")
              ->execute([$tenant_id, "Updated $field to $value"]);
          } catch (Exception $le) {
          }
        } catch (Exception $e) {
          @ob_clean();
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

          $stmt = $db->prepare("SELECT * FROM customers WHERE tenant_id = ? AND mobile != 'WALKIN' ORDER BY full_name ASC");
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
        while (ob_get_level())
          ob_end_clean();
        header('Content-Type: application/json');
        $tenant_id = $_SESSION['tenant_id'] ?? 0;
        if (!$tenant_id) {
          echo json_encode([]);
          exit;
        }
        try {
          $sort = $_GET['sort'] ?? 'latest';
          $orderBy = "COALESCE(a.created_at, a.appointment_id) DESC";
          if ($sort === 'date')
            $orderBy = "a.appointment_date DESC, a.appointment_time DESC";

          $search = $_GET['search'] ?? '';
          $searchQuery = "";
          $params = [$tenant_id];

          if (!empty($search)) {
            $searchQuery = " AND (c.full_name LIKE ? OR v.plate_no LIKE ? OR v2.plate_no LIKE ? OR s.service_name LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
          }

          $stmt = $db->prepare("SELECT GROUP_CONCAT(a.appointment_id) as appointment_id, 
                      a.appointment_date, a.appointment_time, a.status, a.created_at,
                      c.full_name as customer_name, 
                      COALESCE(v.plate_no, v2.plate_no) as plate_no,
                      COALESCE(v.make, v2.make) as make,
                      COALESCE(v.model, v2.model) as model,
                      GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                      m_assigned.full_name as assigned_mechanic_name,
                      m_requested.full_name as requested_mechanic_name
                      FROM appointments a 
                      LEFT JOIN customers c ON a.customer_id = c.customer_id 
                      LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
                      LEFT JOIN vehicles v2 ON a.customer_id = v2.customer_id AND v.vehicle_id IS NULL
                      LEFT JOIN services s ON a.service_id = s.service_id 
                      LEFT JOIN mechanics m_assigned ON a.mechanic_id = m_assigned.mechanic_id AND m_assigned.tenant_id = a.tenant_id
                      LEFT JOIN mechanics m_requested ON a.requested_mechanic_id = m_requested.mechanic_id AND m_requested.tenant_id = a.tenant_id
                      WHERE a.tenant_id = ? AND a.status != 'COMPLETED' AND a.status != 'CANCELLED' $searchQuery
                      GROUP BY a.customer_id, a.vehicle_id, a.appointment_date, a.appointment_time, a.status
                      ORDER BY $orderBy LIMIT 100");
          $stmt->execute($params);
          $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($data);
        } catch (Exception $e) {
          echo json_encode([]);
        }
        exit;
      }

      if ($_GET['action'] === 'update_appointment_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $ids_raw = $_POST['appointment_id'] ?? '0';
          $ids = array_map('intval', explode(',', $ids_raw));
          $first_id = $ids[0];
          $status = $_POST['status'] ?? '';
          $mechanic_id = !empty($_POST['mechanic_id']) ? intval($_POST['mechanic_id']) : null;
          $bay_id = !empty($_POST['bay_id']) ? intval($_POST['bay_id']) : null;

          if (!$first_id || !in_array($status, ['CONFIRMED', 'CANCELLED', 'COMPLETED', 'PENDING'])) {
            throw new Exception("Parameters missing: ID=$ids_raw Status=$status");
          }

          // Validation: If assigning resources, check availability
          if ($status === 'CONFIRMED' && $bay_id) {
            $bayCheck = $db->prepare("SELECT status FROM service_bays WHERE bay_id = ? AND tenant_id = ?");
            $bayCheck->execute([$bay_id, $tenant_id]);
            if ($bayCheck->fetchColumn() === 'OCCUPIED') {
              throw new Exception("Selection Error: The chosen service bay is already occupied.");
            }
          }
          if ($status === 'CONFIRMED' && $mechanic_id) {
            // 1. Check Shift
            $mechCheck = $db->prepare("SELECT full_name, shift_start, shift_end FROM mechanics WHERE mechanic_id = ? AND tenant_id = ?");
            $mechCheck->execute([$mechanic_id, $tenant_id]);
            $mInfo = $mechCheck->fetch();

            if ($mInfo) {
              // Get appointment info
              $appInfo = $db->prepare("SELECT appointment_date, appointment_time FROM appointments WHERE appointment_id = ?");
              $appInfo->execute([$first_id]);
              $a = $appInfo->fetch();

              if ($a) {
                $aTime = $a['appointment_time'];
                if ($aTime < $mInfo['shift_start'] || $aTime > $mInfo['shift_end']) {
                  $sStr = date('h:i A', strtotime($mInfo['shift_start']));
                  $eStr = date('h:i A', strtotime($mInfo['shift_end']));
                  throw new Exception("Shift Error: Mechanic {$mInfo['full_name']} only works from $sStr to $eStr.");
                }

                // 2. Check Overlap (Existing Confirmed Appointments at same date/time)
                $overlapCheck = $db->prepare("SELECT COUNT(*) FROM appointments WHERE mechanic_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'CONFIRMED' AND appointment_id NOT IN ($ids_raw)");
                $overlapCheck->execute([$mechanic_id, $a['appointment_date'], $aTime]);
                if ($overlapCheck->fetchColumn() > 0) {
                  throw new Exception("Collision Error: Mechanic {$mInfo['full_name']} already has a confirmed appointment at this time.");
                }
              }
            }
          }

          // 1. Basic Status Update
          $stmt = $db->prepare("UPDATE appointments SET status = ?, mechanic_id = ?, bay_id = ? WHERE appointment_id IN ($ids_raw) AND tenant_id = ?");
          $stmt->execute([$status, $mechanic_id, $bay_id, $tenant_id]);

          // 2. Confirmation logic: Decoupled from Job Creation
          if ($status === 'CONFIRMED') {
            // Just confirmed the schedule
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

      if ($_GET['action'] === 'start_repair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
          $ids_raw = $_POST['appointment_id'] ?? '0';
          $ids = array_map('intval', explode(',', $ids_raw));
          $first_id = $ids[0];
          if (!$first_id)
            throw new Exception("Invalid Appointment ID");

          $db->beginTransaction();

          // 1. Fetch first appointment details for base Job Order
          $apptQ = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND tenant_id = ?");
          $apptQ->execute([$first_id, $tenant_id]);
          $appt = $apptQ->fetch(PDO::FETCH_ASSOC);

          if (!$appt)
            throw new Exception("Appointment not found.");
          if ($appt['status'] === 'COMPLETED')
            throw new Exception("Already started or completed.");

          // 2. Create Job Order (Pick first service as main)
          $price = $appt['total_estimate'] ?? 0;
          $jobStmt = $db->prepare("INSERT INTO repair_jobs (tenant_id, customer_id, vehicle_id, service_id, appointment_id, mechanic_id, bay_id, status, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)");
          $jobStmt->execute([
            $tenant_id,
            $appt['customer_id'],
            $appt['vehicle_id'],
            $appt['service_id'],
            $first_id,
            $appt['mechanic_id'],
            $appt['bay_id'],
            $price
          ]);
          $newJobId = $db->lastInsertId();

          // 3. Mark ALL grouped appointments as COMPLETED
          $db->prepare("UPDATE appointments SET status = 'COMPLETED' WHERE appointment_id IN ($ids_raw)")->execute();

          // 4. Initial Timeline
          $db->prepare("INSERT INTO repair_timeline (job_id, status_update, remarks, tenant_id, user_id) VALUES (?, 'PENDING', 'Repairs initialized from manual check-in.', ?, ?)")
            ->execute([$newJobId, $tenant_id, $_SESSION['user_id']]);

          $db->commit();
          echo json_encode(['status' => 'success', 'message' => "Job Order #$newJobId created. Repair started!"]);
        } catch (Exception $e) {
          if ($db->inTransaction())
            $db->rollBack();
          echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
      }

      if ($_GET['action'] === 'fetch_available_resources') {
        try {
          $prefMechId = intval($_GET['current_mechanic_id'] ?? 0);
          $prefBayId = intval($_GET['preferred_id'] ?? 0);

          // Consolidated resource fetching: Show only mechanics with 0 IN_PROGRESS jobs (plus the current one) and who are NOT UNAVAILABLE (unless currently assigned)
          $mechanicQuery = "SELECT m.mechanic_id, COALESCE(m.full_name, u.name, 'Expert Mechanic') as full_name, m.shift_start, m.shift_end, m.shift_days
                            FROM mechanics m 
                            LEFT JOIN users u ON m.user_id = u.user_id
                            WHERE m.tenant_id = ? 
                            AND (m.status != 'UNAVAILABLE' OR m.mechanic_id = ?)
                            AND (
                                (SELECT COUNT(*) FROM repair_jobs WHERE mechanic_id = m.mechanic_id AND status = 'IN_PROGRESS' AND tenant_id = m.tenant_id) = 0
                                OR m.mechanic_id = ?
                            )
                            ORDER BY m.full_name ASC";
          $mStmt = $db->prepare($mechanicQuery);
          $mStmt->execute([$tenant_id, $prefMechId, $prefMechId]);

          // Filter out OCCUPIED bays, but keep the current/preferred one selectable
          $bayQuery = "SELECT bay_id, bay_name, status FROM service_bays WHERE tenant_id = ? AND (status = 'AVAILABLE' OR bay_id = ?) ORDER BY bay_id ASC";
          $bays = $db->prepare($bayQuery);
          $bays->execute([$tenant_id, $prefBayId]);

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
      if ($_GET['action'] === 'fetch_my_upcoming_appointments') {
        try {
          // Identify mechanic_id for the current user
          $stmt_m = $db->prepare("SELECT mechanic_id FROM mechanics WHERE user_id = ? AND tenant_id = ?");
          $stmt_m->execute([$_SESSION['user_id'], $tenant_id]);
          $m_id = $stmt_m->fetchColumn();

          if (!$m_id) {
            echo json_encode([]);
            exit;
          }

          // Fetch CONFIRMED appointments assigned to this mechanic that are today or in the future
          $stmt = $db->prepare("SELECT a.*, c.full_name as customer_name, 
                      COALESCE(v.plate_no, v2.plate_no) as plate_no,
                      COALESCE(v.make, v2.make) as make,
                      COALESCE(v.model, v2.model) as model,
                      s.service_name 
                      FROM appointments a 
                      LEFT JOIN customers c ON a.customer_id = c.customer_id 
                      LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
                      LEFT JOIN vehicles v2 ON a.customer_id = v2.customer_id AND v.vehicle_id IS NULL
                      LEFT JOIN services s ON a.service_id = s.service_id 
                      WHERE a.tenant_id = ? AND a.mechanic_id = ? 
                      AND a.status = 'CONFIRMED'
                      AND (a.appointment_date >= CURDATE())
                      ORDER BY a.appointment_date ASC, a.appointment_time ASC");
          $stmt->execute([$tenant_id, $m_id]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
          echo json_encode([]);
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
        while (ob_get_level())
          ob_end_clean();
        header('Content-Type: application/json');
        try {
          // ELITE JO QUERY: Now with Super Forgiving Vehicle Fallback
          $stmt = $db->prepare("SELECT j.*, 
                      COALESCE(c.full_name, j.walkin_name) as customer_name,
                      COALESCE(v.plate_no, v2.plate_no, j.walkin_plate) as plate_no,
                      COALESCE(v.make, v2.make) as make,
                      COALESCE(v.model, v2.model, j.walkin_model) as model,
                      s.service_name, 
                      COALESCE(m.full_name, 'No Mechanic') as mechanic_name,
                      b.bay_name,
                      (SELECT remarks FROM repair_timeline WHERE job_id = j.job_id AND remarks != '' ORDER BY created_at DESC LIMIT 1) as latest_remarks
                      FROM repair_jobs j
                      LEFT JOIN customers c ON j.customer_id = c.customer_id
                      LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                      LEFT JOIN vehicles v2 ON v2.vehicle_id = (
                        SELECT v3.vehicle_id FROM vehicles v3 
                        WHERE v3.customer_id = j.customer_id 
                        LIMIT 1
                      ) AND v.plate_no IS NULL
                      LEFT JOIN services s ON j.service_id = s.service_id
                      LEFT JOIN mechanics m ON j.mechanic_id = m.mechanic_id
                      LEFT JOIN service_bays b ON j.bay_id = b.bay_id
                      WHERE j.tenant_id = ? AND j.status NOT IN ('CANCELLED', 'SETTLED')
                      GROUP BY j.job_id
                      ORDER BY j.updated_at DESC");
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

          // Get old state to handle status transfers and missing fields
          $oldJob = $db->prepare("SELECT mechanic_id, bay_id, status FROM repair_jobs WHERE job_id = ? AND tenant_id = ?");
          $oldJob->execute([$jobId, $tenant_id]);
          $old = $oldJob->fetch(PDO::FETCH_ASSOC);

          if (!$old) {
            throw new Exception("Job not found.");
          }

          // Use old values if new ones are not provided (Critical for Mechanic Role/Locked UI)
          if (empty($mechanicId))
            $mechanicId = $old['mechanic_id'];
          if (empty($bayId))
            $bayId = $old['bay_id'];

          // Enforce rule: Must have BOTH a mechanic and a bay to save updates
          if (empty($mechanicId) || empty($bayId)) {
            throw new Exception("Operation Denied: A mechanic and a service bay must be assigned to this job first.");
          }

          // Validation: If resource changed, check availability
          if ($mechanicId != $old['mechanic_id']) {
            $mechCheck = $db->prepare("SELECT status FROM mechanics WHERE mechanic_id = ? AND tenant_id = ?");
            $mechCheck->execute([$mechanicId, $tenant_id]);
            if ($mechCheck->fetchColumn() === 'BUSY') {
              throw new Exception("Selection Error: The newly selected mechanic is currently busy.");
            }
          }
          if ($bayId != $old['bay_id']) {
            $bayCheck = $db->prepare("SELECT status FROM service_bays WHERE bay_id = ? AND tenant_id = ?");
            $bayCheck->execute([$bayId, $tenant_id]);
            if ($bayCheck->fetchColumn() === 'OCCUPIED') {
              throw new Exception("Selection Error: The newly selected service bay is already occupied.");
            }
          }

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

          $db->prepare("INSERT INTO repair_timeline (job_id, status_update, remarks, tenant_id, user_id) VALUES (?, ?, ?, ?, ?)")
            ->execute([$jobId, $newStatus, $remarks, $tenant_id, $_SESSION['user_id']]);

          // Resource status management: Atomic Sync
          if ($newStatus === 'COMPLETED' || $newStatus === 'CANCELLED' || $newStatus === 'PENDING') {
            if ($mechanicId)
              $db->prepare("UPDATE mechanics SET status = 'AVAILABLE' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
            if ($bayId)
              $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
          } else if ($newStatus === 'IN_PROGRESS') {
            if ($mechanicId)
              $db->prepare("UPDATE mechanics SET status = 'BUSY' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
            if ($bayId)
              $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
          }

          // Handle assignment swap: Free up previous resources if they were changed
          if ($old && ($old['mechanic_id'] != $mechanicId || $old['bay_id'] != $bayId)) {
            if ($old['mechanic_id'] && $old['mechanic_id'] != $mechanicId) {
              $db->prepare("UPDATE mechanics SET status = 'AVAILABLE' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$old['mechanic_id'], $tenant_id]);
            }
            if ($old['bay_id'] && $old['bay_id'] != $bayId) {
              $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ?")->execute([$old['bay_id'], $tenant_id]);
            }
          }

          echo json_encode(['status' => 'success', 'message' => "Job status and resources updated."]);
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

          $finalCustomerId = is_numeric($customerId) ? intval($customerId) : null;
          $finalWalkinName = null;

          if ($customerId === 'WALKIN') {
            if (empty($walkinName))
              throw new Exception("Please provide a name for the walk-in customer.");
            $finalCustomerId = null;
            $finalWalkinName = $walkinName;
          }

          if (!$finalCustomerId && $customerId !== 'WALKIN')
            throw new Exception("Invalid customer selected.");

          $jobId = !empty($_POST['job_id']) ? intval($_POST['job_id']) : null;
          $apptId = !empty($_POST['appointment_id']) ? intval($_POST['appointment_id']) : null;

          $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, walkin_name, job_id, appointment_id, amount, payment_method, reference_no, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW())");
          $stmt->execute([$tenant_id, $finalCustomerId, $finalWalkinName, $jobId, $apptId, $amount, $method, $ref]);

          // Handle Inventory & Official Parts logging
          $fullDataJson = $_POST['parts_json'] ?? '{"parts":[], "services":[]}';
          $fullData = json_decode($fullDataJson, true);
          $parts = $fullData['parts'] ?? [];

          if (is_array($parts) && !empty($parts)) {
            foreach ($parts as $p) {
              $itemId = intval($p['item_id'] ?? 0);
              $qty = intval($p['quantity'] ?? 0);
              if ($itemId > 0 && $qty > 0) {
                $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE item_id = ? AND tenant_id = ?")->execute([$qty, $itemId, $tenant_id]);
                $stmtPrice = $db->prepare("SELECT price FROM inventory WHERE item_id = ?");
                $stmtPrice->execute([$itemId]);
                $uPrice = floatval($stmtPrice->fetchColumn() ?: 0);
                if ($jobId) {
                  $db->prepare("INSERT INTO repair_parts (tenant_id, job_id, item_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$tenant_id, $jobId, $itemId, $qty, $uPrice, $uPrice * $qty]);
                }
              }
            }
          }

          // If linked to a job, mark the job as PAID/SETTLED and FREE RESOURCES
          if ($jobId) {
            $db->prepare("UPDATE repair_jobs SET payment_status = 'PAID', status = 'SETTLED', total_amount = ? WHERE job_id = ? AND tenant_id = ?")->execute([$amount, $jobId, $tenant_id]);
            
            // Release Bay & Mechanic
            $stmtRes = $db->prepare("SELECT bay_id, mechanic_id FROM repair_jobs WHERE job_id = ?");
            $stmtRes->execute([$jobId]);
            $resData = $stmtRes->fetch(PDO::FETCH_ASSOC);
            if ($resData) {
              if ($resData['bay_id']) {
                $db->prepare("UPDATE service_bays SET status = 'AVAILABLE' WHERE bay_id = ? AND tenant_id = ?")->execute([$resData['bay_id'], $tenant_id]);
              }
              if ($resData['mechanic_id']) {
                $db->prepare("UPDATE mechanics SET status = 'AVAILABLE' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$resData['mechanic_id'], $tenant_id]);
              }
            }
          }
          if ($apptId) {
            $db->prepare("UPDATE appointments SET payment_status = 'PAID' WHERE appointment_id = ? AND tenant_id = ?")->execute([$apptId, $tenant_id]);
          }

          if ($finalCustomerId) {
            $db->prepare("UPDATE customers SET total_visits = total_visits + 1 WHERE customer_id = ?")->execute([$finalCustomerId]);
          }

          echo json_encode(['status' => 'success', 'message' => 'Payment of ₱' . number_format($amount, 2) . ' logged successfully!']);
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
          $stmt = $db->prepare("SELECT p.*, COALESCE(c.full_name, p.walkin_name, 'Guest') as full_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.appointment_id = ? AND p.tenant_id = ? LIMIT 1");
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
          @ob_clean();
          header('Content-Type: application/json');

          $stmt = $db->prepare("SELECT p.*, COALESCE(c.full_name, p.walkin_name, 'Guest') as customer_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.tenant_id = ? ORDER BY p.payment_id DESC LIMIT 100");
          $stmt->execute([$tenant_id]);
          $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($data);
        } catch (Exception $e) {
          @ob_clean();
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
          $stmt = $db->prepare("SELECT p.*, COALESCE(c.full_name, p.walkin_name, 'Guest') as customer_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.customer_id WHERE p.tenant_id = ? AND DATE(p.payment_date) = ? ORDER BY p.payment_date DESC");
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

          if (!$bayId || !$serviceId)
            throw new Exception("Please select a service bay and repair package.");

          // Validate Bay Availability
          $bayCheck = $db->prepare("SELECT status FROM service_bays WHERE bay_id = ? AND tenant_id = ?");
          $bayCheck->execute([$bayId, $tenant_id]);
          if ($bayCheck->fetchColumn() === 'OCCUPIED') {
            throw new Exception("Operation Denied: This service bay is currently occupied.");
          }

          if (!$vehicleId) {
            $walkinName = trim($_POST['new_customer_name'] ?? '');
            $walkinPlate = trim($_POST['new_plate_no'] ?? '');
            $walkinModel = trim($_POST['new_model'] ?? '');

            if (!$walkinName || !$walkinPlate) {
              throw new Exception("Please select an existing machine or enter walk-in details (Name & Plate).");
            }
            $customerId = null;
            $vehicleId = null;
          } else {
            // Fetch customer from vehicle
            $vQ = $db->prepare("SELECT customer_id FROM vehicles WHERE vehicle_id = ? AND tenant_id = ?");
            $vQ->execute([$vehicleId, $tenant_id]);
            $customerId = $vQ->fetchColumn();

            if (!$customerId)
              throw new Exception("Invalid vehicle or owner not found.");
          }

          // Create Job Order
          $stmt = $db->prepare("INSERT INTO repair_jobs (tenant_id, customer_id, vehicle_id, service_id, mechanic_id, bay_id, status, walkin_name, walkin_plate, walkin_model, created_at) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, NOW())");
          $stmt->execute([$tenant_id, $customerId, $vehicleId, $serviceId, $mechanicId, $bayId, $walkinName ?? null, $walkinPlate ?? null, $walkinModel ?? null]);
          $newJobId = $db->lastInsertId();

          // Update Statuses (REMOVED: Only update to BUSY/OCCUPIED when work starts via update_job_status)
          /* 
          $db->prepare("UPDATE service_bays SET status = 'OCCUPIED' WHERE bay_id = ? AND tenant_id = ?")->execute([$bayId, $tenant_id]);
          if ($mechanicId) {
            $db->prepare("UPDATE mechanics SET status = 'BUSY' WHERE mechanic_id = ? AND tenant_id = ?")->execute([$mechanicId, $tenant_id]);
          }
          */

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

      // Duplicate fetch_vehicles removed - consolidated at the top AJAX block


      if ($_GET['action'] === 'fetch_customer_details') {
        try {
          $customerId = intval($_GET['customer_id'] ?? 0);
          $stmt = $db->prepare("SELECT * FROM customers WHERE customer_id = ? AND tenant_id = ?");
          $stmt->execute([$customerId, $tenant_id]);
          $customer = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$customer)
            throw new Exception("Customer not found.");

          $vStmt = $db->prepare("SELECT * FROM vehicles WHERE customer_id = ? AND tenant_id = ? AND status = 'ACTIVE'");
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


      // Default response if no specific action matched
      echo json_encode(['status' => 'error', 'message' => 'Action ' . $_GET['action'] . ' not handled.']);
      exit;

    } catch (Throwable $ax) {
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
      $stmt = $db->prepare("SELECT j.job_id, v.plate_no, v.make, v.model, j.status, j.mechanic_id, j.bay_id, j.started_at FROM repair_jobs j LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id WHERE j.tenant_id = ? AND j.mechanic_id = ? AND j.status = 'IN_PROGRESS' ORDER BY j.created_at DESC LIMIT 15");
      $stmt->execute([$tenant_id, $my_mechanic_id]);
    } else {
      $stmt = $db->prepare("SELECT j.job_id, v.plate_no, v.make, v.model, j.status, j.mechanic_id, j.bay_id, j.started_at FROM repair_jobs j LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id WHERE j.tenant_id = ? AND j.status = 'IN_PROGRESS' ORDER BY j.created_at DESC LIMIT 15");
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
  <script>
    // V100 CORE ENGINE: INITIALIZING...
    window.onerror = function (msg, url, lineNo, columnNo, error) {
      const errDiv = document.createElement('div');
      errDiv.style.cssText = 'position:fixed; bottom:20px; right:20px; background:rgba(239,68,68,0.9); color:white; padding:15px; border-radius:10px; z-index:999999; font-size:0.8rem; max-width:300px; border:1px solid white; box-shadow: 0 10px 25px rgba(0,0,0,0.5);';
      errDiv.innerHTML = '<strong>System Diagnostic:</strong><br>' + msg + '<br><small>Line: ' + lineNo + ' | URL: ' + (url ? url.split("/").pop() : "inline") + '</small>';
      document.body.appendChild(errDiv);
      console.error("[CRITICAL]", { msg, url, lineNo, error });
      setTimeout(() => errDiv.remove(), 12000);
      return false;
    };

    window.safeValue = (id) => {
      const el = document.getElementById(id);
      return el ? el.value : "";
    };
    window.setSafeValue = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.value = val;
    };

    // --- NAVIGATION & PAYMENT ENGINE (HOISTED) ---
    window.basePaymentAmount = 0;

    window.addCustomServiceRow = function () {
      fetch('tenant-dashboard.php?action=fetch_services&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          const list = document.getElementById('paymentPartsList'); if (!list) return;
          const row = document.createElement('div');
          row.className = 'service-row';
          row.style = 'display:flex; gap:8px; align-items:center; padding-bottom:8px; border-bottom:1px solid rgba(255,255,255,0.05);';

          let options = data.map(s => `<option value="${s.service_name}" data-price="${s.price}">${s.service_name} (₱${parseFloat(s.price).toLocaleString()})</option>`).join('');

          row.innerHTML = `
            <select class="svc-name" style="flex:2; font-size:0.8rem; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:8px; padding:6px;" onchange="const p=this.options[this.selectedIndex].getAttribute('data-price'); const pIn=this.parentElement.querySelector('.svc-price'); pIn.value=p; pIn.readOnly=(this.value!=='CUSTOM'); window.syncPaymentParts()">
              <option value="">-- Select Service --</option>
              ${options}
              <option value="CUSTOM">-- Custom Service --</option>
            </select>
            <input type="number" placeholder="Price" class="svc-price" min="0" readonly style="flex:1; width:80px; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:8px; padding:6px;" oninput="window.syncPaymentParts()">
            <button type="button" onclick="this.parentElement.remove(); window.syncPaymentParts();" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:1.2rem; line-height:1;">&times;</button>
          `;
          list.appendChild(row);

          // Handle "Custom" selection to allow typing
          const sel = row.querySelector('select');
          sel.addEventListener('change', function () {
            if (this.value === 'CUSTOM') {
              // Replace select with an input? Or just let them type? 
              // Actually, I'll just change the select to a text input if they choose CUSTOM
              const input = document.createElement('input');
              input.type = 'text';
              input.className = 'svc-name';
              input.placeholder = 'Type custom service...';
              input.style = this.style.cssText;
              input.style.flex = '2';
              input.oninput = window.syncPaymentParts;
              this.replaceWith(input);
              input.focus();
            }
          });
        });
    };

    window.showPaymentPartsSelector = function () {
      fetch('tenant-dashboard.php?action=fetch_inventory&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          const list = document.getElementById('paymentPartsList'); if (!list) return;
          const row = document.createElement('div');
          row.className = 'part-row';
          row.style = 'display:flex; gap:8px; align-items:center; padding-bottom:8px; border-bottom:1px solid rgba(255,255,255,0.05);';
          let options = data.map(i => {
            const qty = parseInt(i.quantity || 0);
            const stockLabel = qty > 0 ? `(${qty} in stock)` : '(OUT OF STOCK)';
            const disabled = qty > 0 ? '' : 'disabled style="opacity:0.5;"';
            return `<option value="${i.item_id}" data-price="${i.price}" ${disabled}>${i.item_name} - ₱${parseFloat(i.price).toLocaleString()} ${stockLabel}</option>`;
          }).join('');
          row.innerHTML = `
            <select style="flex:2; font-size:0.8rem; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:8px; padding:6px;" onchange="window.syncPaymentParts()">
              <option value="">-- Select Part --</option>
              ${options}
            </select>
            <input type="number" value="1" min="1" style="flex:0.5; width:50px; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:8px; padding:6px;" onchange="window.syncPaymentParts()">
            <button type="button" onclick="this.parentElement.remove(); window.syncPaymentParts();" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:1.2rem; line-height:1;">&times;</button>
          `;
          list.appendChild(row);
        });
    };

    window.syncPaymentParts = function () {
      const list = document.getElementById('paymentPartsList'); if (!list) return;
      let extraTotal = 0;
      const parts = [];
      const services = [];

      // Process Parts
      list.querySelectorAll('.part-row').forEach(row => {
        const sel = row.querySelector('select'), qtyInput = row.querySelector('input');
        if (sel && sel.value) {
          const opt = sel.options[sel.selectedIndex], price = parseFloat(opt.getAttribute('data-price') || 0), qty = parseInt(qtyInput.value || 1);
          parts.push({ item_id: sel.value, quantity: qty });
          extraTotal += price * qty;
        }
      });

      // Process Services
      list.querySelectorAll('.service-row').forEach(row => {
        const nameEl = row.querySelector('.svc-name'), priceInput = row.querySelector('.svc-price');
        const name = nameEl.value;
        if (name && priceInput.value) {
          const price = parseFloat(priceInput.value || 0);
          services.push({ name: name, price: price });
          extraTotal += price;
        }
      });

      const partsJsonInput = document.getElementById('pay_parts_json');
      if (partsJsonInput) partsJsonInput.value = JSON.stringify({ parts, services });

      // Calculate Final with Discount
      const base = window.basePaymentAmount || 0;
      const subtotal = base + extraTotal;
      const discEl = document.getElementById('pay_discount');
      const discountPct = discEl ? parseFloat(discEl.value || 0) : 0;
      const final = subtotal * (1 - (discountPct / 100));

      const amtInput = document.getElementById('pay_amount');
      const amtHidden = document.getElementById('pay_amount_hidden');
      if (amtInput) amtInput.value = final.toFixed(2);
      if (amtHidden) amtHidden.value = final.toFixed(2);
    };

    window.openRecordPaymentModal = function (jobId, customerId, customerName, amount) {
      console.log("[PAYMENT] Opening Modal. Job:", jobId, "Base Amount:", amount);
      const form = document.getElementById('addPaymentForm');
      if (form) form.reset();

      window.basePaymentAmount = parseFloat(amount || 0);
      const jidInput = document.getElementById('pay_job_id');
      if (jidInput) jidInput.value = jobId || '';

      const amtInput = document.getElementById('pay_amount');
      const amtHidden = document.getElementById('pay_amount_hidden');
      if (amtInput) amtInput.value = window.basePaymentAmount.toFixed(2);
      if (amtHidden) amtHidden.value = window.basePaymentAmount.toFixed(2);

      const list = document.getElementById('paymentPartsList');
      if (list) list.innerHTML = '';

      // Handle Walk-in vs Registered Customer
      const custSelect = form.querySelector('select[name="customer_id"]');
      if (custSelect) {
        if (!customerId || customerId === 'null' || customerId === 'undefined' || customerId === 'WALKIN') {
          custSelect.value = 'WALKIN';
          if (typeof window.toggleWalkInField === 'function') window.toggleWalkInField('WALKIN');
          const walkinInput = form.querySelector('input[name="walkin_name"]');
          if (walkinInput) walkinInput.value = customerName || '';
        } else {
          custSelect.value = customerId;
          if (typeof window.toggleWalkInField === 'function') window.toggleWalkInField(customerId);
        }
      }

      // Force initial sync to ensure base amount is captured
      window.syncPaymentParts();

      // RECEIPT BREAKDOWN LOGIC
      const receiptContainer = document.getElementById('paymentReceiptDetails');
      const breakdownContent = document.getElementById('receiptBreakdownContent');
      if (receiptContainer && breakdownContent) {
          if (jobId) {
              receiptContainer.style.display = 'block';
              breakdownContent.innerHTML = '<div style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading Breakdown...</div>';
              
              fetch(`tenant-dashboard.php?action=get_job_details&id=${jobId}`)
                  .then(r => r.json())
                  .then(job => {
                      if (!job) throw new Error("Job not found");
                      
                      let baseServiceName = job.service_name || 'Service Package';
                      let baseServicePrice = parseFloat(job.service_price) || 0;
                      
                      fetch(`tenant-dashboard.php?action=fetch_job_parts&job_id=${jobId}`)
                          .then(r => r.json())
                          .then(parts => {
                              let html = '<table style="width:100%; border-collapse:collapse; margin-bottom:10px;">';
                              html += '<tr><th style="text-align:left; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:5px;">Description</th>';
                              html += '<th style="text-align:right; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:5px;">Amount</th></tr>';
                              
                              // Base Service
                              html += `<tr><td style="padding:5px 0;">${baseServiceName}</td>`;
                              html += `<td style="text-align:right; padding:5px 0;">₱${baseServicePrice.toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>`;
                              
                              let subtotal = baseServicePrice;
                              
                              // Additional Parts
                              parts.forEach(p => {
                                  if(p.approval_status === 'APPROVED') {
                                      let itemTotal = parseFloat(p.total_price) || 0;
                                      subtotal += itemTotal;
                                      html += `<tr><td style="padding:5px 0; color:#94a3b8; font-size:0.8rem;">+ ${p.item_name} (x${p.quantity})</td>`;
                                      html += `<td style="text-align:right; padding:5px 0; color:#94a3b8; font-size:0.8rem;">₱${itemTotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>`;
                                  }
                              });
                              
                              html += `<tr><th style="text-align:left; border-top:1px dashed rgba(255,255,255,0.2); padding-top:5px; margin-top:5px;">Job Subtotal</th>`;
                              html += `<th style="text-align:right; border-top:1px dashed rgba(255,255,255,0.2); padding-top:5px; margin-top:5px;">₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</th></tr>`;
                              
                              html += '</table>';
                              breakdownContent.innerHTML = html;
                          });
                  }).catch(err => {
                      breakdownContent.innerHTML = '<div style="color:var(--danger); text-align:center;">Failed to load breakdown.</div>';
                  });
          } else {
              receiptContainer.style.display = 'none';
          }
      }

      if (typeof window.openModal === 'function') window.openModal('paymentModal');
    };

    window.calculateFinalAmount = function () { window.syncPaymentParts(); };

    // --- NAVIGATION ENGINE (HOISTED) ---
    const sectionTitles = {
      'dashboard': { title: '<?php echo ucwords(strtolower($role)); ?> Dashboard', sub: 'Overview & Real-time Stats' },
      'appointments': { title: 'Service Appointments', sub: 'Manage bookings and schedules' },
      'job_orders': { title: 'Job Orders & Repairs', sub: 'Track ongoing workshop tasks' },
      'customers': { title: 'Customer Database', sub: 'Relationship management and history' },
      'vehicles': { title: 'Vehicle Registry', sub: 'Manage fleet and customer cars' },
      'payments': { title: 'Billing & Invoices', sub: 'Financial transactions and records' },
      'inventory': { title: 'Parts & Inventory', sub: 'Stock levels and supply chain' },
      'staff': { title: 'Staff Management', sub: 'Human resources and access roles' },
      'bays': { title: 'Service Bay Management', sub: 'Allocate and track workshop physical space' },
      'mechanics': { title: 'Mechanic Masterfile', sub: 'Personnel records and specializations' },
      'services': { title: 'Service Catalog & Pricing', sub: 'Manage offerings and labor rates' },
      'reports': { title: 'Business Analytics', sub: 'Performance metrics and growth' },
      'customization': { title: 'Shop Customization', sub: 'Brand identity and UI settings' },
      'subscription': { title: 'My Subscription', sub: 'Plan details and billing' },
      'my_profile': { title: 'Account Settings', sub: 'Personal profile and presence' },
      'mechanic_history': { title: 'My Work History', sub: 'Log of your recent repair activities' },
      'mechanic_appointments': { title: 'Upcoming Appointments', sub: 'Your assigned future service bookings' },
      'inventory_lookup': { title: 'Parts Catalog', sub: 'View available parts and stock levels' },
      'settled_jobs': { title: 'Settled Jobs History', sub: 'Completed and cancelled repair records' }
    };

    window.navToView = function (viewId) {
      console.log("[NAV] Switching to view:", viewId);

      // Auto-close any open modals when switching tabs
      if (typeof window.closeAllModals === 'function') window.closeAllModals();

      const sections = document.querySelectorAll('.view-section');
      sections.forEach(s => { s.classList.remove('active'); s.style.display = 'none'; });
      const target = document.getElementById(viewId);
      if (target) {
        target.classList.add('active');
        target.style.display = 'block';
        const pTitle = document.getElementById('pageTitle');
        const pSub = document.getElementById('pageSubtitle');
        if (pTitle && sectionTitles[viewId]) {
          pTitle.innerText = sectionTitles[viewId].title;
          if (pSub) pSub.innerText = sectionTitles[viewId].sub;
        }
        document.querySelectorAll('.nav-item').forEach(item => {
          if (item.getAttribute('data-view') === viewId) item.classList.add('active');
          else item.classList.remove('active');
        });
        if (window.innerWidth <= 1024) document.body.classList.add('sidebar-collapsed');

        if (viewId === 'dashboard') {
          if (typeof window.refreshOverviewStats === 'function') window.refreshOverviewStats();
          if (typeof window.refreshDashboardJobs === 'function') window.refreshDashboardJobs();
          if (typeof window.refreshShiftRequests === 'function') window.refreshShiftRequests();
        }
        if (viewId === 'customers') { if (typeof window.refreshAddCustomerList === 'function') window.refreshAddCustomerList(); }
        if (viewId === 'appointments') { if (typeof window.refreshAppointmentsList === 'function') window.refreshAppointmentsList(); }
        if (viewId === 'job_orders') { if (typeof window.refreshJobOrders === 'function') window.refreshJobOrders(); }
        if (viewId === 'settled_jobs') { if (typeof window.refreshSettledJobs === 'function') window.refreshSettledJobs(); }
        if (viewId === 'bays') { if (typeof window.refreshBaysList === 'function') window.refreshBaysList(); }
        if (viewId === 'mechanics') { if (typeof window.refreshMechanicsList === 'function') window.refreshMechanicsList(); }
        if (viewId === 'services') { if (typeof window.refreshServicesList === 'function') window.refreshServicesList(); }
        if (viewId === 'inventory') { if (typeof window.refreshInventoryList === 'function') window.refreshInventoryList(); }
        if (viewId === 'inventory_lookup') { if (typeof window.refreshInventoryLookup === 'function') window.refreshInventoryLookup(); }
        if (viewId === 'payments') { if (typeof window.refreshPaymentsList === 'function') window.refreshPaymentsList(); }
        if (viewId === 'vehicles') { if (typeof window.refreshVehiclesList === 'function') window.refreshVehiclesList(); }
        if (viewId === 'mechanic_appointments') { if (typeof window.refreshMyUpcomingAppointments === 'function') window.refreshMyUpcomingAppointments(); }
        if (viewId === 'mechanic_history') { if (typeof window.refreshMechanicHistory === 'function') window.refreshMechanicHistory(); }
        if (viewId === 'staff') {
          if (typeof window.renderStaffTable === 'function') window.renderStaffTable();
          if (typeof window.refreshShiftRequests === 'function') window.refreshShiftRequests();
        }
        if (viewId === 'customer_logs') { if (typeof loadAuditLogs === 'function') loadAuditLogs(); }
        if (viewId === 'payments_history') { if (typeof window.loadBillingHistory === 'function') window.loadBillingHistory(); }
      } else {
        console.warn("[NAV] View not found:", viewId);
      }
    };

    // --- GLOBAL UTILITIES (HOISTED) ---
    window.executeThermalPrint = function () {
      const content = document.getElementById('receiptPreviewContent').innerHTML;
      const win = window.open('', '', 'height=600,width=400');
      win.document.write('<html><head><title>Print Receipt</title>');
      win.document.write('<style>body { font-family: monospace; padding: 20px; width: 300px; } button { display: none; } </style>');
      win.document.write('</head><body>');
      win.document.write(content);
      win.document.write('</body></html>');
      win.document.close();
      win.focus();
      setTimeout(() => { win.print(); win.close(); }, 500);
    };

    window.viewJobReceipt = function (id) {
      const preview = document.getElementById('receiptPreviewContent');
      if (!preview) return;
      preview.innerHTML = '<div style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading Receipt...</div>';
      openModal('receiptModal');
      fetch('tenant-dashboard.php?action=fetch_receipt_data&job_id=' + id)
        .then(r => r.text()).then(html => { preview.innerHTML = html; })
        .catch(err => { preview.innerHTML = '<div style="color:red">Error loading receipt.</div>'; });
    };

    window.openMechanicProfile = function (mechanicId) {
      if (!mechanicId) return;
      const content = document.getElementById('mechProfileContent');
      if (content) content.innerHTML = '<div style="text-align:center; padding:3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Fetching profile...</div>';
      openModal('mechanicProfileModal');
      fetch('tenant-dashboard.php?action=fetch_mechanic_profile&mechanic_id=' + mechanicId)
        .then(r => r.json()).then(data => {
          if (!content) return;
          if (data.status === 'error') { content.innerHTML = '<div style="color:red; padding:2rem;">' + data.message + '</div>'; return; }

          const m = data.mechanic;
          const history = data.history || [];

          let html = `
            <div style="display:grid; grid-template-columns:1fr; gap:20px; padding:10px;">
                <div style="text-align:center; padding-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.1);">
                    <div style="width:80px; height:80px; background:linear-gradient(135deg, var(--accent), #3b82f6); border-radius:50%; margin:0 auto 15px; display:flex; align-items:center; justify-content:center; font-size:2rem; color:white; font-weight:bold;">
                        ${m.full_name.charAt(0)}
                    </div>
                    <h2 style="margin:0; font-size:1.4rem;">${m.full_name}</h2>
                    <p style="color:var(--text-dim); font-size:0.9rem; margin:5px 0;">${m.specialization || 'General Mechanic'}</p>
                    <div class="badge badge-active" style="margin-top:10px;">${m.active_jobs_count || 0} Active Jobs</div>
                </div>
                <div>
                    <h4 style="margin:0 0 10px; color:var(--accent); font-size:0.8rem; text-transform:uppercase; letter-spacing:1px;">Shift Details</h4>
                    <div style="display:flex; justify-content:space-between; margin-bottom:20px; background:var(--input-bg); padding:10px; border-radius:12px; border:1px solid var(--glass-border);">
                        <span style="font-size:0.85rem; color:var(--text-main);"><i class="fas fa-clock" style="color:var(--accent);"></i> ${m.shift_start} - ${m.shift_end}</span>
                        <span style="font-size:0.85rem; color:var(--text-main);"><i class="fas fa-calendar-alt" style="color:var(--accent);"></i> ${m.shift_days}</span>
                    </div>

                    <h4 style="margin:0 0 10px; color:var(--accent); font-size:0.8rem; text-transform:uppercase; letter-spacing:1px;">Recent Assignments</h4>
                    <div style="max-height:180px; overflow-y:auto; border-radius:12px; background:var(--input-bg); border:1px solid var(--glass-border);">
                        ${history.length ? history.map(h => `
                            <div style="padding:12px; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-weight:bold; font-size:0.85rem; color:var(--text-main);">${h.plate_no}</div>
                                    <div style="color:var(--text-dim); font-size:0.75rem;">${h.service_name}</div>
                                </div>
                                <span style="font-size:0.7rem; padding:2px 8px; border-radius:20px; background:${h.status === 'COMPLETED' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)'}; color:${h.status === 'COMPLETED' ? '#10b981' : '#f59e0b'}; border:1px solid currentColor;">${h.status}</span>
                            </div>
                        `).join('') : '<div style="color:var(--text-dim); padding:20px; text-align:center; font-size:0.85rem;">No recent history.</div>'}
                    </div>
                </div>
            </div>
          `;
          content.innerHTML = html;
        }).catch(err => {
          if (content) content.innerHTML = '<div style="color:red; padding:2rem; text-align:center;">Sync failed. Please try again.</div>';
        });
    };

    window.editService = function (id, name, desc, price, masterId = null, minPrice = null, maxPrice = null) {
      if (document.getElementById('edit_service_id')) document.getElementById('edit_service_id').value = id;
      if (document.getElementById('edit_service_name')) document.getElementById('edit_service_name').value = name;
      if (document.getElementById('edit_service_desc')) document.getElementById('edit_service_desc').value = (desc === 'null' || !desc) ? '' : desc;
      if (document.getElementById('edit_service_min_price')) document.getElementById('edit_service_min_price').value = (minPrice === null || minPrice === 'null') ? '' : minPrice;
      if (document.getElementById('edit_service_max_price')) document.getElementById('edit_service_max_price').value = (maxPrice === null || maxPrice === 'null') ? '' : maxPrice;

      const priceInput = document.getElementById('edit_service_price');
      const form = document.getElementById('editServiceForm');
      const submitBtn = form ? form.querySelector('button[onclick*="saveEditService"]') : null;
      let hint = form ? form.querySelector('.price-hint') : null;

      if (!hint && form && priceInput) {
        hint = document.createElement('div');
        hint.className = 'price-hint';
        hint.style.fontSize = '0.75rem';
        hint.style.marginTop = '5px';
        priceInput.parentNode.appendChild(hint);
      }

      if (priceInput) {
        priceInput.value = price;
        if (minPrice !== null && maxPrice !== null && parseFloat(minPrice) > 0) {
          priceInput.min = minPrice;
          priceInput.max = maxPrice;
          priceInput.placeholder = `Range: ₱${parseFloat(minPrice).toLocaleString()} - ₱${parseFloat(maxPrice).toLocaleString()}`;

          const validate = () => {
            const val = parseFloat(priceInput.value || 0);
            const isInvalid = (val < parseFloat(minPrice) || val > parseFloat(maxPrice));
            if (hint) {
              hint.style.color = isInvalid ? '#ef4444' : 'var(--accent)';
              hint.innerHTML = `<i class="fas fa-${isInvalid ? 'exclamation-triangle' : 'info-circle'}"></i> Recommended Price: ₱${parseFloat(minPrice).toLocaleString()} - ₱${parseFloat(maxPrice).toLocaleString()}`;
            }
            if (submitBtn) {
              submitBtn.disabled = isInvalid;
              submitBtn.style.opacity = isInvalid ? '0.5' : '1';
              submitBtn.style.cursor = isInvalid ? 'not-allowed' : 'pointer';
            }
          };
          priceInput.oninput = validate;
          validate();
        } else {
          priceInput.removeAttribute('min');
          priceInput.removeAttribute('max');
          priceInput.placeholder = "";
          priceInput.oninput = null;
          if (hint) hint.innerHTML = '';
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
          }
        }
      }

      const masterInput = document.getElementById('edit_service_master_id');
      if (masterInput) masterInput.value = masterId || "";
      openModal('editServiceModal');
    };


    window.processAppointment = function (id, status, reqMechName = null) {
      if (status === 'CONFIRMED') {
        const idF = document.getElementById('confirm_appt_id');
        if (idF) idF.value = id;
        const display = document.getElementById('requested_mechanic_display');
        const text = document.getElementById('requested_mechanic_name_text');
        if (display && text) {
          if (reqMechName && reqMechName.trim() !== '' && reqMechName !== 'null') {
            text.innerText = reqMechName;
            display.style.display = 'block';
          } else display.style.display = 'none';
        }
        const mS = document.getElementById('confirm_mechanic_id');
        const bS = document.getElementById('confirm_bay_id');
        if (mS && bS) {
          mS.innerHTML = '<option>Loading...</option>';
          bS.innerHTML = '<option>Loading...</option>';
          fetch('tenant-dashboard.php?action=fetch_available_resources')
            .then(r => r.json()).then(data => {
              mS.innerHTML = '<option value="">-- Assign Mechanic --</option>';
              (data.mechanics || []).forEach(m => {
                const shift = (m.shift_start && m.shift_end)
                  ? ` — ${new Date('1970-01-01T'+m.shift_start).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})} – ${new Date('1970-01-01T'+m.shift_end).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}${m.shift_days ? ' | '+m.shift_days.replace(/,/g,'·') : ''}` : '';
                mS.innerHTML += `<option value="${m.mechanic_id}">${m.full_name}${shift}</option>`;
              });
              bS.innerHTML = '<option value="">-- Assign Bay --</option>';
              (data.bays || []).forEach(b => { bS.innerHTML += `<option value="${b.bay_id}">${b.bay_name}</option>`; });
            });
        }
        window.openModal('confirmAppointmentModal');
      } else {
        if (!confirm('Are you sure you want to ' + status.toLowerCase() + ' this appointment?')) return;
        const fd = new FormData();
        fd.append('appointment_id', id);
        fd.append('status', status);
        fetch('tenant-dashboard.php?action=update_appointment_status', { method: 'POST', body: fd })
          .then(r => r.json()).then(data => {
            if (data.status === 'success') {
              if (typeof showToast === 'function') showToast("Appointment " + status.toLowerCase());
              location.reload();
            } else alert(data.message);
          });
      }
    };

    window.processAppointmentConfirmation = function () {
      const id = safeValue('confirm_appt_id');
      const mid = safeValue('confirm_mechanic_id');
      const bid = safeValue('confirm_bay_id');

      if (!mid || !bid) {
        alert("Please assign both a mechanic and a bay.");
        return;
      }

      const fd = new FormData();
      fd.append('appointment_id', id);
      fd.append('status', 'CONFIRMED');
      fd.append('mechanic_id', mid);
      fd.append('bay_id', bid);

      const btn = document.querySelector('#confirmApptForm button');
      const originalText = btn ? btn.innerText : 'Confirm';
      if (btn) {
        btn.innerText = 'Processing...';
        btn.disabled = true;
      }

      fetch('tenant-dashboard.php?action=update_appointment_status', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            if (typeof showToast === 'function') showToast("Appointment confirmed and Job Order created!");
            location.reload();
          } else {
            alert(data.message);
            if (btn) {
              btn.innerText = originalText;
              btn.disabled = false;
            }
          }
        })
        .catch(err => {
          alert("Network error. Please try again.");
          if (btn) {
            btn.innerText = originalText;
            btn.disabled = false;
          }
        });
    };

    window.deleteService = function (id) {
      if (!confirm('Are you sure you want to delete this service?')) return;
      fetch('tenant-dashboard.php?action=delete_service&service_id=' + id, { method: 'POST' })
        .then(r => r.json()).then(res => {
          if (res.status === 'success') {
            alert('Service deleted!');
            if (window.refreshServicesList) window.refreshServicesList();
          } else alert(res.message || 'Delete failed');
        });
    };

    // --- ULTIMATE JOB STATUS ENGINE (UNIQUE NAMESPACE) ---
    window.handleJobClick = function (id, status, mid, bid, edit, focus) {
      console.log("!!! JOB_CLICK_TRACE !!!", { id, status, mid, bid, edit, focus });
      const editMode = (edit === true || edit === 'true' || edit === '1');
      const focusParts = (focus === true || focus === 'true' || focus === '1');

      if (typeof window.openRepairJobStatusModal_Final === 'function') {
        window.openRepairJobStatusModal_Final(id, status, mid, bid, editMode, focusParts);
      } else {
        alert("System still initializing... please wait a moment.");
      }
      return false;
    };

    window.openRepairJobStatusModal_Final = function (id, currentStatus, currentMechId, currentBayId, editMode = false, focusParts = false) {
      console.log("MODAL_OPEN_START_UNIQUE", { id, currentStatus, currentMechId, currentBayId, editMode });
      try {
        const modalEl = document.getElementById('repairJobStatusModal_Final');
        if (!modalEl) { alert("DOM Error: #repairJobStatusModal_Final NOT FOUND!"); return; }

        window.scrollTo({ top: 0, behavior: 'smooth' });

        modalEl.style.setProperty('display', 'flex', 'important');
        modalEl.style.setProperty('z-index', '2147483647', 'important');
        modalEl.style.setProperty('opacity', '1', 'important');
        modalEl.style.setProperty('visibility', 'visible', 'important');
        modalEl.style.pointerEvents = 'auto';

        const jidInput = document.getElementById('status_job_id');
        const statusSelect = document.getElementById('job_current_status');
        if (jidInput) jidInput.value = id;
        if (statusSelect) {
          statusSelect.value = currentStatus;
          const pendingOpt = statusSelect.querySelector('option[value="PENDING"]');
          if (pendingOpt) {
            if (currentStatus === 'IN_PROGRESS' || currentStatus === 'COMPLETED') {
              pendingOpt.style.display = 'none';
            } else {
              pendingOpt.style.display = 'block';
            }
          }
        }

        const mH = document.getElementById('status_mechanic_id_hidden');
        const bH = document.getElementById('status_bay_id_hidden');
        if (mH) mH.value = currentMechId || '';
        if (bH) bH.value = currentBayId || '';

        const sVehicle = document.getElementById('summary_vehicle');
        const sService = document.getElementById('summary_service');
        if (sVehicle) sVehicle.innerText = "Loading details...";
        if (sService) sService.innerText = "Please wait...";

        fetch(`tenant-dashboard.php?action=get_job_details&id=${id}`)
          .then(r => r.json()).then(job => {
            if (job) {
              if (sVehicle) sVehicle.innerText = `${job.plate_number || 'N/A'} - ${job.vehicle_model || 'Unknown'}`;
              if (sService) sService.innerText = job.service_name || 'General Repair';
              window.currentJobServicePrice = parseFloat(job.service_price) || 0;
              const remField = document.getElementById('status_remarks');
              if (remField) remField.value = job.remarks || '';
              const checkboxes = document.querySelectorAll('.ann-chk');
              const savedChecks = (job.checklist || "").split(',');
              checkboxes.forEach(cb => { cb.checked = savedChecks.includes(cb.value); });

              // REFRESH BILLING - Now that window.currentJobServicePrice is set
              if (window.refreshJobPartsList) window.refreshJobPartsList(id);
            }
          });

        const mS = document.getElementById('status_mechanic_id');
        const bS = document.getElementById('status_bay_id');
        if (mS) mS.innerHTML = '<option>Loading...</option>';
        if (bS) bS.innerHTML = '<option>Loading...</option>';
        fetch(`tenant-dashboard.php?action=fetch_available_resources&preferred_id=${currentBayId || 0}&current_mechanic_id=${currentMechId || 0}`)
          .then(r => r.json()).then(data => {
            if (mS) {
              let mHtml = '<option value="">-- Select Mechanic --</option>';
              mHtml += (data.mechanics || []).map(m => {
                const shift = (m.shift_start && m.shift_end)
                  ? ` — ${new Date('1970-01-01T'+m.shift_start).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})} – ${new Date('1970-01-01T'+m.shift_end).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}${m.shift_days ? ' | '+m.shift_days.replace(/,/g,'·') : ''}` : '';
                return `<option value="${m.mechanic_id}" ${m.mechanic_id == currentMechId ? 'selected' : ''}>${m.full_name}${shift}</option>`;
              }).join('');
              if (!mS.value && currentMechId && currentMechId != 0) mHtml += `<option value="${currentMechId}" selected>Current Mechanic</option>`;
              mS.innerHTML = mHtml;
            }
            if (bS) {
              let bHtml = '<option value="">-- Select Service Bay --</option>';
              bHtml += (data.bays || []).map(b => `<option value="${b.bay_id}" ${b.bay_id == currentBayId ? 'selected' : ''}>${b.bay_name}</option>`).join('');
              if (!bS.value && currentBayId && currentBayId != 0) bHtml += `<option value="${currentBayId}" selected>Current Bay</option>`;
              bS.innerHTML = bHtml;
            }
          });

        if (window.toggleJobStatusEdit) window.toggleJobStatusEdit(editMode);
      } catch (e) { console.error("Modal Open Error:", e); alert("Modal Error: " + e.message); }
    };

    window.toggleJobStatusEdit = function (editing) {
      const mS = document.getElementById('status_mechanic_id');
      const bS = document.getElementById('status_bay_id');
      const sS = document.getElementById('job_current_status');
      const eb = document.getElementById('editJobBtn');
      const sb = document.getElementById('saveJobBtn');
      const userRole = '<?php echo strtoupper($role); ?>';
      const isMech = userRole === 'MECHANIC';
      const currentStatus = sS ? sS.value : '';
      const isCompleted = (currentStatus === 'COMPLETED');
      if (mS) mS.disabled = !editing || isCompleted || isMech;
      if (bS) bS.disabled = !editing || isCompleted || isMech;
      if (eb) eb.style.display = editing ? 'none' : 'flex';
      if (sb) sb.style.display = editing ? 'flex' : 'none';

      // Dynamic Status Enforcer: If they select IN_PROGRESS or COMPLETED, hide PENDING
      if (sS) {
        sS.onchange = function () {
          const val = this.value;
          const pendingOpt = this.querySelector('option[value="PENDING"]');
          if (pendingOpt && (val === 'IN_PROGRESS' || val === 'COMPLETED')) {
            pendingOpt.style.display = 'none';
          }
        };
      }
    };

    // --- PARTS & MATERIALS ENGINE ---
    window.togglePartResults = function () {
      const list = document.getElementById('partResultsList');
      if (list) list.style.display = list.style.display === 'none' ? 'block' : 'none';
    };

    window.showPartResults = function () {
      const list = document.getElementById('partResultsList');
      if (list) {
        window.loadPartSelector();
        list.style.display = 'block';
      }
    };


    window.selectPartForJob = function (id, name, price, stock) {
      const inp = document.getElementById('partComboboxInput');
      const hiddenId = document.getElementById('selectedPartId');
      if (inp) inp.value = name;
      if (hiddenId) hiddenId.value = id;
      window.togglePartResults();
      // Focus quantity for speed
      const qty = document.getElementById('partQty');
      if (qty) qty.focus();
    };


    window.refreshJobPartsList = function (jid) {
      const list = document.getElementById('jobPartsList');
      const bill = document.getElementById('totalPartsBill');
      if (!list) return;
      fetch(`tenant-dashboard.php?action=fetch_job_parts&job_id=${jid}`)
        .then(r => r.json()).then(data => {
          window.currentlyAddedServiceIds = data.filter(p => p.service_id).map(p => parseInt(p.service_id));
          if (!data.length) {
            list.innerHTML = '<div style="text-align:center; color:var(--text-dim); font-size:0.8rem; padding:20px;">No parts recorded.</div>';
            if (bill) bill.innerText = '₱0.00';
            const overall = document.getElementById('totalOverallBill');
            if (overall) {
              const sP = window.currentJobServicePrice || 0;
              overall.innerText = '₱' + sP.toLocaleString(undefined, { minimumFractionDigits: 2 });
            }
            return;
          }
          let total = 0;
          list.innerHTML = data.map(p => {
            const itemTotal = parseFloat(p.total_price) || 0;
            const itemUnit = parseFloat(p.unit_price) || 0;
            total += itemTotal;
            return `<div style="display:flex; justify-content:space-between; align-items:center; background:var(--input-bg); padding:10px 15px; border-radius:12px; border:1px solid var(--glass-border); margin-bottom:8px;">
                      <div style="flex:1;">
                        <div style="font-weight:700; font-size:0.85rem; color:var(--text-main);">${p.item_name}</div>
                        <div style="font-size:0.7rem; color:var(--text-dim);">${p.service_id ? 'Labor / Service' : `${p.quantity} units @ ₱${itemUnit.toLocaleString()}`}</div>
                      </div>
                      <div style="display:flex; align-items:center; gap:12px;">
                        <div style="font-weight:800; color:var(--accent); font-size:0.9rem;">₱${itemTotal.toLocaleString()}</div>
                        <i class="fas fa-times-circle" onclick="window.removePartFromJob(${p.rp_id}, ${jid})" style="color:var(--danger); cursor:pointer;"></i>
                      </div>
                    </div>`;
          }).join('');
          if (bill) bill.innerText = '₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2 });
          const overall = document.getElementById('totalOverallBill');
          if (overall) {
            const sP = window.currentJobServicePrice || 0;
            const combined = total + sP;
            overall.innerText = '₱' + combined.toLocaleString(undefined, { minimumFractionDigits: 2 });
          }
        });
    };

    window.addPartToJob = function () {
      const jidEl = document.getElementById('status_job_id');
      const iidEl = document.getElementById('selectedPartId');
      const qtyEl = document.getElementById('partQty');
      if (!jidEl || !iidEl || !qtyEl) return;
      
      const jid = jidEl.value;
      const iid = iidEl.value;
      const qty = qtyEl.value;
      if (!jid || !iid || !qty) return alert("Please select a part and quantity");
      const fd = new FormData();
      fd.append('job_id', jid);
      fd.append('item_id', iid);
      fd.append('quantity', qty);
      fetch('tenant-dashboard.php?action=add_part_to_job', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast(data.message);
            window.refreshJobPartsList(jid);
            document.getElementById('selectedPartId').value = '';
            document.getElementById('partComboboxInput').value = '';
            window.loadPartSelector();
          } else alert(data.message);
        });
    };

    window.removePartFromJob = function (rpId, jid) {
      if (!confirm("Remove this part from the job?")) return;
      fetch(`tenant-dashboard.php?action=remove_part_from_job&rp_id=${rpId}`)
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast(data.message);
            window.refreshJobPartsList(jid);
            window.loadPartSelector();
          } else alert(data.message);
        });
    };

    window.loadPartSelector = function () {
      const list = document.getElementById('partResultsList');
      if (!list) return;
      fetch('tenant-dashboard.php?action=fetch_inventory')
        .then(r => r.json()).then(data => {
          window.allPartsCache = data;
          window.filterPartCombobox("");
        });
    };

    window.editPartQty = function (rp_id, currentQty, jid) {
      const newQty = prompt("Enter new quantity:", currentQty);
      if (newQty === null || newQty === "" || parseInt(newQty) === currentQty) return;
      const q = parseInt(newQty);
      if (isNaN(q) || q < 1) return showToast("Invalid quantity", "error");
      const fd = new FormData();
      fd.append('rp_id', rp_id);
      fd.append('quantity', q);
      fetch('tenant-dashboard.php?action=update_job_part_qty', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast(data.message, 'success');
            window.refreshJobPartsList(jid);
          } else {
            showAlert("Error", data.message, "error");
          }
        });
    };


    window.filterPartCombobox = function (q) {
      const query = (q || "").toLowerCase().trim();
      const list = document.getElementById('partResultsList');
      if (!list || !window.allPartsCache) return;

      const filtered = window.allPartsCache.filter(p =>
        (p.item_name || "").toLowerCase().includes(query) ||
        (p.brand || "").toLowerCase().includes(query) ||
        (p.item_code || "").toLowerCase().includes(query)
      );

      list.innerHTML = filtered.map(p => {
        const itemPrice = parseFloat(p.price || 0);
        const qty = parseInt(p.quantity || 0);
        const isOut = qty <= 0;
        return `<div onclick="${isOut ? '' : `window.selectPartForJob(${p.item_id}, '${(p.item_name || "").replace(/'/g, "\\'")}', ${itemPrice}, ${qty})`}" 
                     style="padding:12px 15px; cursor:${isOut ? 'not-allowed' : 'pointer'}; border-bottom:1px solid rgba(255,255,255,0.05); transition:0.2s; opacity:${isOut ? '0.5' : '1'};"
                     onmouseover="${isOut ? '' : "this.style.background='rgba(255,255,255,0.05)'"}" onmouseout="this.style.background='transparent'">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <div style="font-weight:700; font-size:0.85rem; color:white;">${p.item_name} ${isOut ? '<span style="color:var(--danger); font-size:0.6rem; margin-left:5px;">OUT OF STOCK</span>' : ''}</div>
                <div style="font-size:0.7rem; color:var(--text-dim);">${p.brand || 'No Brand'} • ${qty} in stock</div>
              </div>
              <div style="font-weight:800; color:var(--accent); font-size:0.9rem;">₱${itemPrice.toLocaleString()}</div>
            </div>
          </div>`;
      }).join('') || '<div style="padding:20px; text-align:center; color:var(--text-dim); font-size:0.85rem;">No parts found.</div>';
    };

    window.filterServiceCombobox = function (q) {
      const query = (q || "").toLowerCase().trim();
      const list = document.getElementById('serviceResultsList');
      if (!list || !window.allServicesCache) return;

      const filtered = window.allServicesCache.filter(s => {
        const matches = (s.service_name || "").toLowerCase().includes(query);
        const alreadyAdded = (window.currentlyAddedServiceIds || []).includes(parseInt(s.service_id));
        return matches && !alreadyAdded;
      });

      list.innerHTML = filtered.map(s => {
        const price = parseFloat(s.price || 0);
        return `<div onclick="window.selectServiceForJob(${s.service_id}, '${(s.service_name || "").replace(/'/g, "\\'")}', ${price})" 
                     style="padding:12px 15px; cursor:pointer; border-bottom:1px solid rgba(255,255,255,0.05); transition:0.2s;"
                     onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <div style="font-weight:700; font-size:0.85rem; color:white;">${s.service_name}</div>
                <div style="font-size:0.7rem; color:var(--text-dim);">${s.category || 'Service'}</div>
              </div>
              <div style="font-weight:800; color:var(--accent); font-size:0.9rem;">₱${price.toLocaleString()}</div>
            </div>
          </div>`;
      }).join('') || '<div style="padding:20px; text-align:center; color:var(--text-dim); font-size:0.85rem;">No services found.</div>';
    };

    window.selectServiceForJob = function (id, name, price) {
      document.getElementById('selectedServiceId').value = id;
      document.getElementById('serviceComboboxInput').value = name;
      document.getElementById('serviceResultsList').style.display = 'none';
    };

    window.addServiceToJob = function () {
      const jidEl = document.getElementById('status_job_id');
      const sidEl = document.getElementById('selectedServiceId');
      if (!jidEl || !sidEl) return;
      
      const jid = jidEl.value;
      const sid = sidEl.value;
      if (!jid || !sid) return alert("Please select a service first.");
      const fd = new FormData();
      fd.append('job_id', jid);
      fd.append('service_id', sid);
      fetch('tenant-dashboard.php?action=add_service_to_job', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast(data.message);
            window.refreshJobPartsList(jid);
            document.getElementById('selectedServiceId').value = '';
            document.getElementById('serviceComboboxInput').value = '';
          } else alert(data.message);
        });
    };

    window.loadServiceSelector = function () {
      fetch('tenant-dashboard.php?action=fetch_services')
        .then(r => r.json()).then(data => {
          window.allServicesCache = data;
          window.filterServiceCombobox("");
        });
    };

    // Close floating lists when clicking outside
    window.addEventListener('click', function (e) {
      const partList = document.getElementById('partResultsList');
      const partInput = document.getElementById('partComboboxInput');
      const partArrow = document.getElementById('comboboxArrow');
      const serviceList = document.getElementById('serviceResultsList');
      const serviceInput = document.getElementById('serviceComboboxInput');

      if (partList && !partList.contains(e.target) && e.target !== partInput && e.target !== partArrow) {
        partList.style.display = 'none';
      }
      if (serviceList && !serviceList.contains(e.target) && e.target !== serviceInput) {
        serviceList.style.display = 'none';
      }
    });


    // --- REPAIR STATUS SUBMIT ENGINE ---
    window.handleStatusSubmit = function (e) {
      if (e) e.preventDefault();
      const form = e ? e.target : document.getElementById('jobStatusForm');
      if (!form) return;
      const fd = new FormData(form);
      const mS = document.getElementById('status_mechanic_id');
      const bS = document.getElementById('status_bay_id');
      const mH = document.getElementById('status_mechanic_id_hidden');
      const bH = document.getElementById('status_bay_id_hidden');
      const mId = (mS && mS.value) ? mS.value : (mH ? mH.value : '');
      const bId = (bS && bS.value) ? bS.value : (bH ? bH.value : '');
      if (!mId || !bId) return window.showAlert('Assignment Required', 'Please assign a mechanic and bay.', 'error');
      if (!fd.has('mechanic_id')) fd.append('mechanic_id', mId);
      if (!fd.has('bay_id')) fd.append('bay_id', bId);
      const checked = [];
      document.querySelectorAll('.ann-chk:checked').forEach(c => checked.push(c.value));
      fd.append('checklist', checked.join(', '));
      fetch('tenant-dashboard.php?action=update_job_status', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast("Job status updated!");
            window.closeModal('repairJobStatusModal_Final');
            if (typeof refreshJobOrders === 'function') refreshJobOrders();
            if (typeof refreshDashboardJobs === 'function') refreshDashboardJobs();
          } else window.showAlert('Error', data.message, 'error');
        });
    };

    window.highlightInPreview = function (field) {
      const frame = document.getElementById('livePreviewFrame');
      if (frame && frame.contentWindow) {
        frame.contentWindow.postMessage({ action: 'highlight', field: field }, '*');
      }
    };

    window.saveSingleSetting = function (field, btn) {
      console.log("[SETTINGS] Saving field:", field);
      const input = document.getElementById('setting_' + field);
      if (!input) { console.error("Input not found for", field); return; }
      const value = input.value;
      
      if (!btn) btn = event ? (event.currentTarget || event.target) : null;
      if (!btn) { console.error("Button element not found"); return; }
      
      const originalHtml = btn.innerHTML;
      btn.classList.add('saving');
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
      const fd = new FormData();
      fd.append('field', field);
      fd.append('value', value);
      
      fetch('tenant-dashboard.php?action=save_setting_item', { method: 'POST', body: fd })
        .then(async r => {
          const text = await r.text();
          try {
            return JSON.parse(text);
          } catch(e) {
            console.error("Server returned non-JSON:", text);
            throw new Error("Server Error: Invalid response format.");
          }
        }).then(data => {
          if (data.status === 'success') {
            showToast("Feature updated successfully!");
            // Fields that require a live preview refresh
            const visualFields = [
              'primary_color', 'secondary_color', 'ui_style', 'border_radius', 
              'logo_url', 'banner_url', 'shop_name', 'hero_title', 
              'hero_subtitle', 'about_text', 'description'
            ];
            if (visualFields.includes(field)) {
              const frame = document.getElementById('livePreviewFrame');
              if (frame) frame.src = frame.src + '&v=' + Date.now();
            }
          } else showToast(data.message, 'error');
        }).catch(err => {
          console.error("Save Error:", err);
          showToast(err.message || "Connection error", "error");
        })
        .finally(() => {
          btn.classList.remove('saving');
          btn.innerHTML = originalHtml;
        });
    };

    window.saveSettingWithFile = function (field, btn) {
      const input = document.getElementById('setting_' + field);
      if (!input || !input.files[0]) return showToast("Please select a file first", "error");
      
      if (!btn) btn = event ? (event.currentTarget || event.target) : null;
      const originalHtml = btn ? btn.innerHTML : '';
      btn.classList.add('saving');
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
      const fd = new FormData();
      fd.append('field', field);
      fd.append(field, input.files[0]);
      fetch('tenant-dashboard.php?action=save_setting_item', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
          if (data.status === 'success') {
            showToast("File uploaded and saved!");
            const urlInput = document.getElementById('setting_' + field.replace('_file', '_url'));
            if (urlInput) urlInput.value = data.url;
            const frame = document.getElementById('livePreviewFrame');
            if (frame) frame.src = frame.src + '&v=' + Date.now();
          } else showToast(data.message, 'error');
        }).catch(err => showToast("Upload error", "error"))
        .finally(() => {
          btn.classList.remove('saving');
          btn.innerHTML = originalHtml;
        });
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
      const selectedCycle = y ? 'yearly' : 'monthly';
      document.querySelectorAll('.plan-card').forEach(card => {
        const isPlanMatch = card.dataset.isMatch === 'true';
        const activeCycle = card.dataset.activeCycle; // This is the user's actual cycle
        const badge = card.querySelector('.active-badge');
        const btn = card.querySelector('.upgrade-select-btn');
        const isFullMatch = isPlanMatch && activeCycle === selectedCycle;

        if (badge) badge.style.display = isFullMatch ? 'block' : 'none';
        if (btn) {
          if (isFullMatch) {
            btn.innerText = 'Current Plan';
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.pointerEvents = 'none';
          } else {
            btn.innerText = 'Select Plan';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
          }
        }
      });
      document.querySelectorAll('.plan-price-val').forEach(el => {
        const v = y ? el.dataset.yearly : el.dataset.monthly;
        el.innerText = '₱' + parseFloat(v).toLocaleString();
      });
      document.querySelectorAll('.plan-cycle-label').forEach(el => el.innerText = y ? '/yr' : '/mo');
    };


    window.openUpgradeModal = function (e) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      const modal = document.getElementById('upgradeModal');
      if (modal) {
        modal.style.setProperty('display', 'flex', 'important');
        modal.style.setProperty('z-index', '2147483647', 'important');
        modal.style.setProperty('visibility', 'visible', 'important');
        modal.style.setProperty('opacity', '1', 'important');
        window.toggleBillingCycle(false);
      }
    };

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
            .then(r => r.json()).then(data => {
              if (data.status === 'success') {
                alert("✅ " + data.message + "\nReference: " + data.ref);
                window.closeUpgradeModal();
                setTimeout(() => location.reload(), 1000);
              } else {
                alert("❌ Error: " + data.message);
                if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
              }
            }).catch(() => {
              alert("❌ Connection error. Please try again.");
              if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
            });
        });
      };

      const modalId = 'upgradePayModal_' + Date.now();
      const modalHTML = `
        <div id="${modalId}" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); backdrop-filter:blur(15px); z-index:2147483648; display:flex; align-items:center; justify-content:center; padding:20px;">
          <div style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:32px; padding:3rem; width:100%; max-width:480px; text-align:center; box-shadow:0 30px 60px rgba(0,0,0,0.8);">
            <div style="width:80px; height:80px; background:linear-gradient(135deg, var(--accent) 0%, rgba(var(--accent-rgb), 0.7) 100%); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 2rem; color:white; font-size:2.5rem; box-shadow:0 10px 25px var(--accent-glow);"><i class="fas fa-rocket"></i></div>
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
              <button id="upgConfirmBtn_${modalId}" style="flex:2; padding:16px; background:var(--accent); color:white; border:none; border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem; transition:0.3s; box-shadow:0 10px 20px var(--accent-glow);">Go to Payment</button>
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
      document.getElementById('upgCancelBtn_' + modalId).onclick = function () { document.getElementById(modalId).remove(); };
    };

    window.loadBillingHistory = function () {
      const body = document.getElementById('billingHistoryTableBody');
      if (!body) return;
      body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:2rem;"><div class="spinner"></div></td></tr>';
      fetch('tenant-dashboard.php?action=fetch_billing_history')
        .then(res => res.json()).then(data => {
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem; opacity:0.5;">No billing history found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(p => `
            <tr>
              <td>${p.payment_date}</td>
              <td><code style="background:var(--input-bg); padding:2px 6px; border-radius:4px; color:var(--text-main);">${p.transaction_reference}</code></td>
              <td>₱${parseFloat(p.amount).toLocaleString()}</td>
              <td><span class="badge" style="background:rgba(16,185,129,0.1); color:var(--success); border:1px solid rgba(16,185,129,0.2);">${p.payment_status}</span></td>
            </tr>`).join('');
        });
    };

    window.loadAuditLogs = function () {
      console.log("[AUDIT] Initiative started...");
      const body = document.getElementById('auditLogsTableBody');
      if (!body) return;
      body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem;"><div class="spinner" style="margin:0 auto 1rem;"></div><p>Synchronizing audit records...</p></td></tr>';
      fetch('tenant-dashboard.php?action=fetch_audit_logs&_t=' + Date.now())
        .then(res => res.json()).then(data => {
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
            if (l.activity_type === 'SUBSCRIPTION') { badgeClass = 'badge-active'; icon = 'fa-rocket'; }

            return `
              <tr class="hover-bright" style="border-bottom:1px solid var(--glass-border);">
                <td style="font-size:0.85rem; color:var(--text-dim); font-weight:700;">${new Date(l.created_at).toLocaleString()}</td>
                <td>
                  <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:28px; height:28px; border-radius:50%; background:var(--input-bg); display:flex; align-items:center; justify-content:center; font-size:0.75rem; color:var(--accent);">
                      <i class="fas fa-user-circle"></i>
                    </div>
                    <span style="font-weight:700; color:var(--text-main);">${actor}</span>
                  </div>
                </td>
                <td>
                  <span class="badge ${badgeClass}" style="font-size:0.7rem; padding:4px 10px;">
                    <i class="fas ${icon}" style="margin-right:5px;"></i> ${l.activity_type}
                  </span>
                </td>
                <td style="font-size:0.85rem; color:var(--text-dim); line-height:1.5; max-width:400px;">${l.description || '-'}</td>
              </tr>`;
          }).join('');
        });
    };






    // --- INITIALIZATION ---
    document.addEventListener('DOMContentLoaded', () => {
      const jsf = document.getElementById('jobStatusForm');
      if (jsf) {
        jsf.addEventListener('submit', window.handleStatusSubmit);
      }
    });



    window.prepareAddServiceModal = function () {
      const form = document.getElementById('addServiceForm');
      if (form) {
        form.reset();
        const nameInp = form.querySelector('input[name="service_name"]');
        if (nameInp) {
          nameInp.readOnly = false;
          nameInp.style.opacity = "1";
        }
        const masterSelect = form.querySelector('select[name="master_id"]');
        if (masterSelect) {
          masterSelect.disabled = false;
          masterSelect.style.opacity = "1";
        }
        const hint = form.querySelector('.price-hint');
        if (hint) hint.remove();

        const submitBtn = form.querySelector('button[onclick*="Service"]');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = "1";
        }
      }
      openModal('addServiceModal');
    };

    window.syncMasterService = function (el, formId) {
      const form = document.getElementById(formId);
      if (!form) return;
      const priceInput = form.querySelector('input[name="price"]');
      const nameInput = form.querySelector('input[name="service_name"]');
      const submitBtn = form.querySelector('button[onclick*="submit"]');
      const hint = form.querySelector('.price-hint') || document.createElement('div');

      const opt = el.options[el.selectedIndex];
      const min = parseFloat(opt.dataset.min || 0);
      const max = parseFloat(opt.dataset.max || 0);

      // Auto-fill Service Name if a master service is selected
      if (nameInput) {
        if (el.value) {
          nameInput.value = opt.text;
          nameInput.readOnly = true;
          nameInput.style.opacity = "0.7";
        } else {
          nameInput.readOnly = false;
          nameInput.style.opacity = "1";
        }
      }

      const validate = () => {
        if (!priceInput || isNaN(min) || min === 0) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; submitBtn.style.cursor = 'pointer'; }
          return;
        }
        const val = parseFloat(priceInput.value || 0);
        const isInvalid = (val < min || val > max);

        hint.style.color = isInvalid ? '#ef4444' : 'var(--accent)';
        hint.innerHTML = `<i class="fas fa-${isInvalid ? 'exclamation-triangle' : 'info-circle'}"></i> Recommended Price: ₱${min.toLocaleString()} - ₱${max.toLocaleString()}`;

        if (submitBtn) {
          submitBtn.disabled = isInvalid;
          submitBtn.style.opacity = isInvalid ? '0.5' : '1';
          submitBtn.style.cursor = isInvalid ? 'not-allowed' : 'pointer';
        }
      };

      if (priceInput) {
        priceInput.min = min;
        priceInput.max = max;
        priceInput.placeholder = (min > 0) ? `Range: ₱${min.toLocaleString()} - ₱${max.toLocaleString()}` : "0.00";

        hint.className = 'price-hint';
        hint.style.fontSize = '0.75rem';
        hint.style.marginTop = '5px';
        if (min > 0) {
          if (!form.querySelector('.price-hint')) priceInput.parentNode.appendChild(hint);
          priceInput.oninput = validate;
          validate();
        } else {
          if (form.querySelector('.price-hint')) form.querySelector('.price-hint').remove();
          if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; submitBtn.style.cursor = 'pointer'; }
        }
      }
    };

    window.refreshPaymentsList = function () {
      const b = document.getElementById('completedPaymentsBody'); if (!b) return;
      b.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Fetching logs...</td></tr>';
      fetch('tenant-dashboard.php?action=fetch_payments&_t=' + Date.now())
        .then(r => r.text()).then(t => {
          try {
            const s = t.indexOf('['), e = t.lastIndexOf(']') + 1;
            const data = JSON.parse(t.substring(s, e));
            if (!data.length) { b.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;">No logs found.</td></tr>'; return; }
            b.innerHTML = data.map(p => `
              <tr>
                <td>#${p.payment_id}</td>
                <td><strong>${p.customer_name}</strong></td>
                <td><small>${p.reference_no || '---'}</small> (${p.payment_method})</td>
                <td>₱${parseFloat(p.amount || 0).toLocaleString()}</td>
                <td>${new Date(p.payment_date).toLocaleDateString()}</td>
                <td><span class="badge badge-active">${p.status}</span></td>
              </tr>
            `).join('');
          } catch (e) { b.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;">Sync failed.</td></tr>'; }
        });
    };




    window.openModal = function (id) {
      const el = document.getElementById(id);
      if (el) {
        el.style.display = 'flex';
        const firstInput = el.querySelector('input, select, textarea');
        if (firstInput) setTimeout(() => firstInput.focus(), 100);
      }
    };

    window.toggleAnnouncement = function () {
      const ann = document.getElementById('announcement-banner');
      if (ann) {
        ann.style.display = (ann.style.display === 'none' || ann.style.display === '') ? 'flex' : 'none';
      }
    };
    window.toggleVehicleGroup = function (id) {
      document.querySelectorAll('.vehicle-child-' + id).forEach(r => {
        r.style.display = (r.style.display === 'none') ? 'table-row' : 'none';
      });
    };

    // refreshAppointmentsList
    window.refreshAppointmentsList = function () {
      console.log("[DEBUG] refreshAppointmentsList triggered at " + new Date().toLocaleTimeString());
      const body = document.getElementById('appointmentsTableBody'); if (!body) return;

      // Immediate UI Feedback
      body.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:3rem; color:var(--accent);"><i class="fas fa-spinner fa-spin"></i> Refreshing bookings...</td></tr>';

      const sort = document.getElementById('appointmentSortFilter')?.value || 'latest';
      const search = document.getElementById('appointmentSearchInput')?.value || '';

      fetch(`tenant-dashboard.php?action=fetch_all_appointments&sort=${sort}&search=${encodeURIComponent(search)}&_=${Date.now()}`)
        .then(res => res.text())
        .then(text => {
          try {
            // Find JSON part
            const start = text.indexOf('[');
            const end = text.lastIndexOf(']') + 1;
            if (start === -1 || end === 0) {
              // IF NO JSON FOUND, SHOW RAW ERROR IN TABLE
              body.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#ef4444; padding:3rem;"><i class="fas fa-bug"></i> Server Error:<br><pre style="background:rgba(0,0,0,0.3); padding:1rem; border-radius:10px; margin-top:1rem; font-size:0.7rem; color:#f87171; white-space:pre-wrap;">${text.substring(0, 500)}</pre></td></tr>`;
              return;
            }
            const data = JSON.parse(text.substring(start, end));

            if (!Array.isArray(data) || data.length === 0) {
              body.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--text-dim); padding:3rem;">No upcoming appointments found in the system.</td></tr>';
              return;
            }
            body.innerHTML = data.map(a => {
              const isPending = a.status === 'PENDING';
              const isConfirmed = a.status === 'CONFIRMED';

              // Date/Time Formatter
              let displayDate = a.appointment_date;
              let displayTime = a.appointment_time;
              try {
                if (a.appointment_date && a.appointment_date !== '0000-00-00') {
                  displayDate = new Date(a.appointment_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
                if (a.appointment_time && a.appointment_time !== '00:00:00') {
                  const [h, m] = a.appointment_time.split(':');
                  const hh = parseInt(h);
                  const ampm = hh >= 12 ? 'PM' : 'AM';
                  displayTime = ((hh % 12) || 12) + ':' + m + ' ' + ampm;
                }
              } catch (e) { console.error("Format Error", e); }

              let actionHtml = '';
              if (isPending) {
                actionHtml = `
              <div style="display:flex; gap:8px;">
                <button class="btn-action" style="flex:1; padding:8px; font-size:0.75rem; background:#10b981; color:white; border:none; border-radius:10px;" onclick="window.processAppointment(${a.appointment_id}, 'CONFIRMED', '${(a.requested_mechanic_name || '').replace(/'/g, "\\'")}')">
                  <i class="fas fa-check"></i> Approve
                </button>
                <button class="btn-action" style="flex:1; padding:8px; font-size:0.75rem; background:#ef4444; color:white; border:none; border-radius:10px;" onclick="window.processAppointment(${a.appointment_id}, 'CANCELLED')">
                  <i class="fas fa-times"></i> Reject
                </button>
              </div>`;
              } else if (isConfirmed) {
                actionHtml = `
              <div style="display:flex; flex-direction:column; gap:8px;">
                <button class="btn-action" style="padding:8px; font-size:0.75rem; background:var(--accent); color:white; border:none; border-radius:10px; font-weight:700;" onclick="window.startRepairFromAppointment(${a.appointment_id})">
                  <i class="fas fa-play-circle"></i> Start Repair
                </button>
                <button class="btn-outline" style="padding:4px; font-size:0.65rem; border-color:rgba(239,68,68,0.3); color:#ef4444;" onclick="window.processAppointment(${a.appointment_id}, 'CANCELLED')">
                   Cancel Appointment
                </button>
              </div>`;
              } else {
                actionHtml = `<div style="text-align:center;"><em style="font-size:0.75rem; color:var(--text-dim); opacity:0.6;"><i class="fas fa-history"></i> Archived</em></div>`;
              }
              return `
          <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
            <td>
              <div style="font-weight:800; color:var(--text-main); font-size:0.9rem;">${displayDate}</div>
              <div style="font-size:0.75rem; color:var(--accent); font-weight:700;"><i class="far fa-clock"></i> ${displayTime}</div>
            </td>
            <td><strong>${a.customer_name}</strong></td>
            <td>
              <div style="font-weight:700; color:var(--accent);">${a.plate_no || 'No Plate'}</div>
              <small style="color:var(--text-dim);">${a.make ? a.make + ' ' + a.model : 'No Vehicle Info'}</small>
            </td>
            <td><span style="font-size:0.85rem; font-weight:700;">${a.service_name || 'General Repair'}</span></td>
            <td><i class="fas fa-user-check" style="color:${a.requested_mechanic_name ? 'var(--accent)' : 'var(--text-dim)'}; opacity:0.6;"></i> ${a.requested_mechanic_name || '(None)'}</td>
            <td><span class="badge ${a.status === 'PENDING' ? 'badge-pending' : (a.status === 'CONFIRMED' ? 'badge-active' : 'badge-danger')}">${a.status}</span></td>
            <td>${actionHtml}</td>
          </tr>`;
            }).join('');
          } catch (e) {
            console.error("Parse Error:", e, text);
            body.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#ef4444; padding:3rem;"><i class="fas fa-exclamation-circle"></i> Error parsing bookings.</td></tr>';
          }
        })
        .catch(err => {
          console.error("Fetch Error:", err);
          body.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#ef4444; padding:3rem;"><i class="fas fa-wifi"></i> Connection error.</td></tr>';
        });
    };

    window.refreshVehiclesList = function () {
      const b = document.getElementById('vehiclesBody'); if (!b) return;
      const search = document.getElementById('vehicleSearchInput')?.value || '';
      b.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
      fetch(`tenant-dashboard.php?action=fetch_vehicles&search=${encodeURIComponent(search)}&_=${Date.now()}`)
        .then(res => res.text()).then(text => {
          try {
            const s = text.indexOf('['), e = text.lastIndexOf(']') + 1;
            const data = JSON.parse(text.substring(s, e));
            if (!data.length) { b.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem;">No vehicles found.</td></tr>'; return; }
            const groups = {};
            data.forEach(v => {
              const o = v.customer_name || 'Generic';
              if (!groups[o]) groups[o] = [];
              groups[o].push(v);
            });
            let html = '';
            Object.keys(groups).forEach((o, i) => {
              const gid = 'vg-' + i;
              html += `<tr onclick="window.toggleVehicleGroup('${gid}')" style="cursor:pointer; background:rgba(255,255,255,0.02);">
                        <td colspan="4" style="padding:1rem;"><strong>${o}</strong> (${groups[o].length} units)</td>
                    </tr>`;
              groups[o].forEach(v => {
                html += `<tr class="vehicle-child-${gid}" style="display:none; background:rgba(0,0,0,0.1);">
                            <td style="padding-left:3rem;"><code>${v.plate_no}</code></td>
                            <td>${v.make} ${v.model}</td>
                            <td>${v.year_model || v.year || '---'}</td>
                            <td><button class="btn-outline" onclick="window.openVehicleProfile(${v.vehicle_id})">View</button></td>
                        </tr>`;
              });
            });
            b.innerHTML = html;
          } catch (err) { b.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem;">Data parsing error.</td></tr>'; }
        });
    };

    function showToast(msg, type = 'success') {
      const container = document.getElementById('toastContainer');
      if (!container) return;
      const toast = document.createElement('div');
      toast.className = 'toast-box';
      toast.style.borderLeftColor = type === 'error' ? 'var(--danger)' : 'var(--accent)';
      toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}" style="color:${type === 'error' ? 'var(--danger)' : 'var(--accent)'}"></i> ${msg}`;
      container.appendChild(toast);
      setTimeout(() => toast.remove(), 4000);
    }
    window.showToast = showToast;

    function closeModal(id) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    }
    window.closeModal = closeModal;

    // Close ALL open modals at once (used when switching sidebar tabs)
    window.closeAllModals = function () {
      document.querySelectorAll('[id$="Modal"]').forEach(el => {
        if (el.style.display === 'flex' || el.style.display === 'block') {
          el.style.display = 'none';
        }
      });
    };

    function closeNotiModal() {
      const m = document.getElementById('notificationModal');
      if (m) m.style.display = 'none';
    }
    window.closeNotiModal = closeNotiModal;

    function showAlert(title, message, type = 'info') {
      const modal = document.getElementById('notificationModal');
      if (!modal) return;
      document.getElementById('notiTitle').innerText = title;
      document.getElementById('notiMessage').innerText = message;
      const icon = document.getElementById('notiIcon');
      const btn = document.getElementById('notiConfirmBtn');
      const cancelBtn = document.getElementById('notiCancelBtn');
      if (cancelBtn) cancelBtn.style.display = 'none';
      if (btn) {
        btn.innerText = 'Okay';
        btn.onclick = closeNotiModal;
      }
      if (icon) {
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
      }
      modal.style.display = 'flex';
    }
    window.showAlert = showAlert;

    function showConfirm(title, message, onConfirm) {
      const modal = document.getElementById('notificationModal');
      if (!modal) { if (confirm(message)) onConfirm(); return; }
      document.getElementById('notiTitle').innerText = title;
      document.getElementById('notiMessage').innerText = message;
      const btn = document.getElementById('notiConfirmBtn');
      const cbtn = document.getElementById('notiCancelBtn');
      if (cbtn) cbtn.style.display = 'block';
      if (btn) {
        btn.innerText = 'Confirm';
        btn.onclick = () => { onConfirm(); closeNotiModal(); };
      }
      modal.style.display = 'flex';
    }
    window.showConfirm = showConfirm;

    console.log('HIGH_PRIORITY_ENGINE_LOADED');
    window.refreshShiftRequests = function () {
      fetch('tenant-dashboard.php?action=fetch_shift_requests')
        .then(r => r.json())
        .then(data => {
          const body = document.getElementById('shiftRequestsBody');
          const section = document.getElementById('shiftRequestsSection');
          const badge = document.getElementById('shiftRequestBadge');
          const staffBody = document.getElementById('staffShiftRequestsBody');
          const staffSection = document.getElementById('staffShiftRequestsSection');
          if (!data || data.length === 0) {
            if (section) section.style.display = 'none';
            if (staffSection) staffSection.style.display = 'none';
            return;
          }
          if (section) section.style.display = 'block';
          if (staffSection) staffSection.style.display = 'block';
          if (badge) { badge.innerText = data.length; badge.style.display = 'inline-block'; }
          const mapReq = (req, isStaff) => {
            const start = new Date('2000-01-01 ' + req.requested_start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const end = new Date('2000-01-01 ' + req.requested_end).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            return `
              <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                <td style="padding:${isStaff ? '10px 15px' : '15px 20px'};">
                  <div style="font-weight:700; color:var(--text-main);">${req.full_name}</div>
                  ${isStaff ? '' : `<div style="font-size:0.75rem; color:var(--text-dim);">Mechanic ID: #${req.mechanic_id}</div>`}
                </td>
                <td style="padding:${isStaff ? '10px 15px' : '15px 20px'};">
                  <div style="display:flex; align-items:center; gap:8px; color:var(--accent); font-weight:700;">
                    <i class="far fa-clock"></i> ${start} – ${end}
                  </div>
                  ${req.requested_days ? `<div style="font-size:0.75rem; color:var(--text-dim); margin-top:4px;"><i class="fas fa-calendar-week" style="margin-right:4px; color:var(--accent);"></i> ${req.requested_days.split(',').join(' · ')}</div>` : ''}
                </td>
                ${isStaff ? '' : `<td style="padding:15px 20px;"><div style="font-size:0.85rem; color:var(--text-dim); max-width:250px;">${req.reason}</div></td>`}
                <td style="padding:${isStaff ? '10px 15px' : '15px 20px'}; text-align:right;">
                  <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button onclick="window.processShiftRequest(${req.request_id}, 'APPROVED')" class="btn-action" style="padding:6px 14px; font-size:0.75rem; background:var(--success); border:none; color:white; font-weight:800; border-radius:10px; cursor:pointer;">Approve</button>
                    <button onclick="window.processShiftRequest(${req.request_id}, 'REJECTED')" class="btn-action" style="padding:6px 14px; font-size:0.75rem; background:var(--danger); border:none; color:white; font-weight:800; border-radius:10px; cursor:pointer;">Reject</button>
                  </div>
                </td>
              </tr>`;
          };
          if (body) body.innerHTML = data.map(req => mapReq(req, false)).join('');
          if (staffBody) staffBody.innerHTML = data.map(req => mapReq(req, true)).join('');
        });
    };
    window.processShiftRequest = function (requestId, status) {
      if (!confirm(`Are you sure you want to ${status.toLowerCase()} this request?`)) return;
      const fd = new FormData();
      fd.append('request_id', requestId);
      fd.append('status', status);
      fetch('tenant-dashboard.php?action=process_shift_request', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            if (typeof showToast === 'function') showToast(data.message, 'success'); else alert(data.message);
            window.refreshShiftRequests();
            if (typeof window.refreshMechanicsList === 'function') window.refreshMechanicsList();
          } else {
            if (typeof showToast === 'function') showToast(data.message, 'error'); else alert(data.message);
          }
        });
    };
  </script>
  <style>
    @keyframes pulse {
      0% {
        opacity: 1;
        transform: scale(1);
      }

      50% {
        opacity: 0.8;
        transform: scale(1.05);
      }

      100% {
        opacity: 1;
        transform: scale(1);
      }
    }

    :root {
      --bg-deep: <?php echo $tenant_custom['secondary_color'] ?: '#030712'; ?>;
      --accent: <?php echo $tenant_custom['primary_color'] ?: '#10b981'; ?>;
      --accent-rgb: <?php
        $hex = ($tenant_custom['primary_color'] ?? '') ?: '#10b981';
        $rgb = sscanf($hex, "#%02x%02x%02x");
        if ($rgb) { list($r, $g, $b) = $rgb; } else { $r = 16; $g = 185; $b = 129; }
        echo "$r, $g, $b";
        ?>;
      --accent-glow: rgba(var(--accent-rgb), 0.4);
      --radius: <?php echo $tenant_custom['border_radius'] ?: '24px'; ?>;
      --glass: <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.03)'; ?>;
      --glass-border: <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.08)'; ?>;
      --glass-blur: <?php echo ($tenant_custom['ui_style'] === 'SOLID') ? 'none' : 'blur(20px)'; ?>;
      --text-main: #f8fafc;
      --text-dim: #94a3b8;
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --card-bg: rgba(255, 255, 255, 0.03);
      --input-bg: rgba(255, 255, 255, 0.05);
    }

    [data-theme="light"] {
      --bg-deep: #f1f5f9;
      --text-main: #0f172a;
      --text-dim: #64748b;
      --glass: rgba(255, 255, 255, 0.8);
      --glass-border: rgba(0, 0, 0, 0.1);
      --glass-blur: blur(20px);
      --card-bg: #ffffff;
      --input-bg: #ffffff;
      --accent-glow: rgba(var(--accent-rgb), 0.15);
    }

    [data-theme="light"] .modern-input,
    [data-theme="light"] select {
      background-color: var(--input-bg) !important;
      color: var(--text-main) !important;
      border-color: var(--glass-border) !important;
    }

    [data-theme="light"] select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%230f172a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    }

    [data-theme="light"] .glass-panel,
    [data-theme="light"] .view-section,
    [data-theme="light"] .stat-card,
    [data-theme="light"] .modal-content,
    [data-theme="light"] .sidebar,
    [data-theme="light"] .card,
    [data-theme="light"] .glass-card {
      background: var(--glass) !important;
      color: var(--text-main) !important;
      border-color: var(--glass-border) !important;
    }

    [data-theme="light"] .glass-panel h1,
    [data-theme="light"] .glass-panel h2,
    [data-theme="light"] .glass-panel h3,
    [data-theme="light"] .glass-panel h4,
    [data-theme="light"] .glass-panel strong,
    [data-theme="light"] .view-section h1,
    [data-theme="light"] .view-section h2,
    [data-theme="light"] .view-section h3,
    [data-theme="light"] .view-section h4,
    [data-theme="light"] .view-section strong,
    [data-theme="light"] .modal-content h1,
    [data-theme="light"] .modal-content h2,
    [data-theme="light"] .modal-content h3,
    [data-theme="light"] .modal-content h4,
    [data-theme="light"] .modal-content strong,
    [data-theme="light"] .data-table td,
    [data-theme="light"] .data-table td strong {
      color: var(--text-main) !important;
    }

    [data-theme="light"] .data-table th {
      background: rgba(0, 0, 0, 0.03) !important;
      color: var(--text-dim) !important;
    }

    [data-theme="light"] .data-table tr:hover {
      background: rgba(0, 0, 0, 0.01) !important;
    }

    [data-theme="light"] .nav-item:not(.active) {
      color: var(--text-dim);
    }

    [data-theme="light"] .nav-group-title {
      color: var(--text-dim);
      opacity: 0.7;
    }

    [data-theme="light"] .brand {
      color: var(--text-main);
    }

    [data-theme="light"] .brand-icon {
      background: white;
      color: var(--accent);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    [data-theme="light"] #themeToggle,
    [data-theme="light"] a[href="?logout=1"] {
      background: rgba(0, 0, 0, 0.05) !important;
      border: 1px solid rgba(0, 0, 0, 0.05) !important;
    }

    [data-theme="light"] ::placeholder {
      color: var(--text-dim) !important;
      opacity: 0.5;
    }

    [data-theme="light"] {
      --bg-deep: #f1f5f9;
      --text-main: #0f172a;
      --text-dim: #475569;
      --glass: rgba(255, 255, 255, 0.9);
      --glass-border: rgba(0, 0, 0, 0.1);
      --accent-glow: rgba(var(--accent-rgb), 0.2);
      --card-bg: #ffffff;
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
      background: var(--input-bg);
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      padding: 1rem 1.25rem;
      color: var(--text-main) !important;
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
      background-color: var(--input-bg) !important;
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
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .main-content {
      flex: 1;
      margin-left: 280px;
      overflow-y: auto;
      padding: 3rem 4rem;
      position: relative;
      min-height: 100vh;
      z-index: 1;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Collapsed Sidebar Styles */
    body.sidebar-collapsed .sidebar {
      width: 85px;
      min-width: 85px;
    }

    body.sidebar-collapsed .main-content {
      margin-left: 85px;
    }

    body.sidebar-collapsed .brand div:not(.brand-icon),
    body.sidebar-collapsed .nav-group-title,
    body.sidebar-collapsed .nav-item .nav-label,
    body.sidebar-collapsed .nav-item-link .nav-label,
    body.sidebar-collapsed .nav-item span:not(.notif-badge) {
      display: none;
    }

    body.sidebar-collapsed .nav-item,
    body.sidebar-collapsed .nav-item-link {
      justify-content: center;
      padding: 1rem;
      gap: 0;
    }

    body.sidebar-collapsed .nav-item i,
    body.sidebar-collapsed .nav-item-link i {
      font-size: 1.25rem;
      margin: 0;
    }

    body.sidebar-collapsed .brand {
      justify-content: center;
      padding: 0 0 2rem 0;
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
      background: #059669;
      box-shadow: 0 0 15px var(--accent-glow);
    }

    body.sidebar-collapsed .sidebar-trigger {
      /* Adjust if needed for collapsed state */
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
      color: var(--text-main);
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
      background: var(--input-bg);
      border: 1px solid var(--glass-border);
      padding: 0.9rem 1.25rem;
      border-radius: 15px;
      color: var(--text-main);
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
                data-active-cycle="<?php echo $user_cycle; ?>"
                style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255,255,255,0.05); padding: 35px; border-radius: 24px; text-align: center; position: relative;">
                <div class="active-badge"
                  style="<?php echo $is_current ? 'display:block;' : 'display:none;'; ?> position:absolute; top:15px; right:15px; background:var(--accent); color:white; padding:5px 12px; border-radius:100px; font-size:0.7rem; font-weight:900;">
                  CURRENT PLAN</div>
                <h3 style="font-size:1.6rem; color:#fff; margin-bottom:10px;">
                  <?php echo htmlspecialchars($p['plan_name']); ?>
                </h3>
                <div style="margin-bottom:25px;">
                  <span class="plan-price-val" data-monthly="<?php echo $monthly; ?>" data-yearly="<?php echo $yearly; ?>"
                    style="font-size:2.8rem; font-weight:900; color:#fff;">₱
                    <?php echo ($user_cycle === 'yearly') ? number_format($yearly) : number_format($monthly); ?>
                  </span>
                  <span class="plan-cycle-label" style="font-size:1rem; color:#64748b;">
                    <?php echo ($user_cycle === 'yearly') ? '/yr' : '/mo'; ?>
                  </span>
                </div>
                <button class="upgrade-select-btn btn-action"
                  style="width:100%; padding:15px; border-radius:15px; font-weight:800; <?php echo $is_current ? 'opacity:0.5; pointer-events:none;' : ''; ?>"
                  type="button" <?php echo $is_current ? 'disabled' : ''; ?> onclick="processUpgrade(
            <?php echo $p['plan_id']; ?>, '
            <?php echo addslashes($p['plan_name']); ?>', event)">
                  <?php echo $is_current ? 'Current Plan' : 'Select Plan'; ?>
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
      // Upgrade Modal Logic moved to top engine

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
    window.toggleSidebar = function () {
      document.body.classList.toggle('sidebar-collapsed');
      const isCollapsed = document.body.classList.contains('sidebar-collapsed');
      localStorage.setItem('sidebarCollapsed', isCollapsed);

      // Update Icon
      const icon = document.querySelector('#sidebarToggle i');
      if (icon) {
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
      }
    };

    window.toggleTheme = function () {
      const html = document.documentElement;
      const current = html.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      localStorage.setItem('tenant_theme', next);

      const icon = document.querySelector('#theme-toggle-btn i');
      if (icon) icon.className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    };

    // Restore Sidebar & Theme State on Load
    document.addEventListener('DOMContentLoaded', () => {
      const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
      if (isCollapsed) {
        document.body.classList.add('sidebar-collapsed');
        const icon = document.querySelector('#sidebarToggle i');
        if (icon) icon.className = 'fas fa-chevron-right';
      }

      const savedTheme = localStorage.getItem('tenant_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', savedTheme);
      const icon = document.querySelector('#theme-toggle-btn i');
      if (icon) icon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    });

    // Billing logic moved to top engine


    // loadAuditLogs moved to top engine


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

    // --- MASTER DATA SYNC ENGINE ---
    window.refreshServicesList = function () {
      const body = document.getElementById('servicesBody'); if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_services&_t=' + Date.now())
        .then(r => r.json()).then(data => {
          if (!Array.isArray(data)) return;
          body.innerHTML = data.map(s => {
            const safeName = (s.service_name || '').replace(/'/g, "\\'");
            const safeDesc = (s.description || '').replace(/'/g, "\\'");
            return `<tr>
              <td><strong>${s.service_name}</strong></td>
              <td><small>${s.description || 'No description'}</small></td>
              <td>₱${parseFloat(s.price || 0).toLocaleString()}</td>
              <td><span class="badge badge-active">ACTIVE</span></td>
              <td>
                <button class="btn-outline" onclick="window.editService(${s.service_id}, '${safeName}', '${safeDesc}', ${s.price}, ${s.master_id || 'null'}, ${s.min_price || 'null'}, ${s.max_price || 'null'})">Edit</button>
                <button class="btn-outline" style="color:var(--danger); border-color:rgba(239,68,68,0.3); margin-left:5px;" onclick="window.deleteService(${s.service_id})">Delete</button>
              </td>
            </tr>`;
          }).join('');
        }).catch(e => console.error("Services load failed"));
    };

    window.prepareAddServiceModal = function () {
      const form = document.getElementById('addServiceForm');
      if (form) {
        form.reset();
        const nameInp = form.querySelector('input[name="service_name"]');
        if (nameInp) {
          nameInp.readOnly = false;
          nameInp.style.opacity = "1";
        }
        const masterSelect = form.querySelector('select[name="master_id"]');
        if (masterSelect) {
          masterSelect.disabled = false;
          masterSelect.style.opacity = "1";
        }
        const hint = form.querySelector('.price-hint');
        if (hint) hint.remove();

        const submitBtn = form.querySelector('button[onclick*="Service"]');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = "1";
          submitBtn.style.cursor = "pointer";
        }
      }
      openModal('serviceModal');
    };

    window.submitAddService = function () {
      const form = document.getElementById('addServiceForm');
      if (!form) return;

      const btn = form.querySelector('button');
      const originalText = btn.innerText;
      btn.innerText = 'Saving...';
      btn.disabled = true;

      const priceInput = form.querySelector('input[name="price"]');
      if (priceInput.min && priceInput.max) {
        const val = parseFloat(priceInput.value);
        const min = parseFloat(priceInput.min);
        const max = parseFloat(priceInput.max);
        if (val < min || val > max) {
          showToast(`Price must be between ₱${min.toLocaleString()} and ₱${max.toLocaleString()}`, 'error');
          return;
        }
      }

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
      const idEl = document.getElementById('edit_service_id');
      const nameEl = document.getElementById('edit_service_name');
      const descEl = document.getElementById('edit_service_desc');
      const priceEl = document.getElementById('edit_service_price');

      if (!idEl || !nameEl || !priceEl) {
        showToast('System Error: Missing form fields in Edit Modal', 'error');
        return;
      }

      const id = idEl.value;
      const name = nameEl.value;
      const desc = descEl ? descEl.value : '';
      const price = priceEl.value;

      const priceInput = document.querySelector('#editServiceForm input[name="price"]');
      if (priceInput && priceInput.min && priceInput.max) {
        const val = parseFloat(priceInput.value);
        const min = parseFloat(priceInput.min);
        const max = parseFloat(priceInput.max);
        if (val < min || val > max) {
          showToast(`Price must be between ₱${min.toLocaleString()} and ₱${max.toLocaleString()}`, 'error');
          return;
        }
      }

      const fd = new FormData();
      fd.append('service_id', id);
      fd.append('service_name', name);
      fd.append('description', desc);
      fd.append('price', price);
      const masterInput = document.getElementById('edit_service_master_id');
      if (masterInput && masterInput.value) fd.append('master_id', masterInput.value);
      const minPriceEl = document.getElementById('edit_service_min_price');
      const maxPriceEl = document.getElementById('edit_service_max_price');
      if (minPriceEl && minPriceEl.value !== '') fd.append('min_price', minPriceEl.value);
      if (maxPriceEl && maxPriceEl.value !== '') fd.append('max_price', maxPriceEl.value);

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
        body.innerHTML = data.map(m => {
          const isBusy = (parseInt(m.active_jobs_count) > 0);
          let statusLabel = 'AVAILABLE';
          let badgeClass = 'badge-active';
          if (m.status === 'UNAVAILABLE') {
            statusLabel = 'UNAVAILABLE';
            badgeClass = 'badge-danger';
          } else if (isBusy) {
            statusLabel = 'BUSY';
            badgeClass = 'badge-warning';
          }

          const formatT = (t) => {
            if (!t) return '08:00 AM';
            let [h, min] = t.split(':');
            h = parseInt(h);
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return `${h}:${min} ${ampm}`;
          };
          const shiftDaysStr = m.shift_days ? m.shift_days.split(',').join(' · ') : 'Mon · Tue · Wed · Thu · Fri · Sat';
          const shiftStr = `<div style="line-height:1.6;"><span style="font-size:0.8rem; color:var(--text-main); font-weight:600;"><i class="far fa-clock" style="color:var(--accent); margin-right:4px;"></i>${formatT(m.shift_start)} – ${formatT(m.shift_end)}</span><br><span style="font-size:0.7rem; color:var(--text-dim);">${shiftDaysStr}</span></div>`;

          const toggleBtn = m.status === 'UNAVAILABLE' 
            ? `<button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:#10b981; color:#10b981; cursor:pointer; position:relative; z-index:10; pointer-events:auto !important;" onclick="window.toggleMechanicStatus(${m.mechanic_id}, 'AVAILABLE')">Set Available</button>`
            : isBusy
              ? `<button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:#ef4444; color:#ef4444; cursor:not-allowed; opacity:0.5; position:relative; z-index:10; pointer-events:none !important;" disabled title="Cannot set unavailable while busy">Set Unavailable</button>`
              : `<button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:#ef4444; color:#ef4444; cursor:pointer; position:relative; z-index:10; pointer-events:auto !important;" onclick="window.toggleMechanicStatus(${m.mechanic_id}, 'UNAVAILABLE')">Set Unavailable</button>`;

          return `
          <tr>
            <td><strong>${m.display_name}</strong></td>
            <td>${m.specialization}</td>
            <td>${shiftStr}</td>
            <td><span class="badge ${badgeClass}">${statusLabel}</span></td>
            <td>
              <div style="display:flex; gap:6px;">
                <button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--text-main); cursor:pointer; position:relative; z-index:10; pointer-events:auto !important;" onclick="window.openMechanicProfile(${m.mechanic_id})">View Profile</button>
                <?php if (strtoupper($role) === 'OWNER' || strtoupper($role) === 'MANAGER'): ?>
                <button type="button" class="btn-outline" style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--text-main); cursor:pointer; position:relative; z-index:10; pointer-events:auto !important;" onclick="window.openEditShiftModal(${m.mechanic_id}, '${m.shift_start}', '${m.shift_end}', '${m.display_name.replace(/'/g, "\\'")}', '${m.shift_days || 'Mon,Tue,Wed,Thu,Fri,Sat'}')">Edit Shift</button>
                ${toggleBtn}
                <?php endif; ?>
              </div>
            </td>
          </tr>`;
        }).join('');
      });
    };

    window.toggleMechanicStatus = function (mechanicId, newStatus) {
      if (!confirm(`Are you sure you want to set this mechanic as ${newStatus.toLowerCase()}?`)) return;

      const fd = new FormData();
      fd.append('mechanic_id', mechanicId);
      fd.append('status', newStatus);

      fetch('tenant-dashboard.php?action=update_mechanic_status', {
        method: 'POST',
        body: fd
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast(data.message, 'success');
          window.refreshMechanicsList();
        } else {
          showToast(data.message || 'Failed to update mechanic status.', 'error');
        }
      })
      .catch(err => {
        console.error("Status Sync Error:", err);
        showToast('Network error updating status.', 'error');
      });
    };


    // refreshAppointmentsList — canonical version defined earlier at line ~4417 (with full error handling)

    window.startRepairFromAppointment = function (id) {
      if (!confirm("Are you sure you want to start this repair now? This will create a Job Order and move it to Active Repairs.")) return;

      const formData = new FormData();
      formData.append('appointment_id', id);

      fetch('tenant-dashboard.php?action=start_repair', {
        method: 'POST',
        body: formData
      }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
          showToast(data.message);
          if (typeof refreshAppointmentsList === 'function') refreshAppointmentsList();
          if (typeof window.refreshJobOrders === 'function') window.refreshJobOrders();
          if (typeof window.dashboardOverviewRefresh === 'function') window.dashboardOverviewRefresh();
        } else {
          alert('Error: ' + data.message);
        }
      }).catch(e => alert('Network error. Check connection.'));
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
            // Safety: A bay is available if DB says so OR if it has no truly active job (PENDING/IN_PROGRESS)
            const isAvail = (b.status === 'AVAILABLE' || !b.active_job_id);
            const displayStatus = isAvail ? 'AVAILABLE' : b.status.toUpperCase();
            
            const action = isAvail ? `openBayProfile(${b.bay_id})` : `window.handleJobClick(${b.active_job_id}, '${b.job_status}', ${b.active_mechanic_id}, ${b.bay_id}, true, false)`;

            // NEW: Dynamic Info for Occupied Bays
            const occupiedInfo = !isAvail ? `
                <div style="margin-top:1rem; padding:12px; background:rgba(255,255,255,0.03); border-radius:15px; border:1px solid rgba(255,255,255,0.05);">
                  <div style="font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:4px;">In Service</div>
                  <div style="font-weight:800; color:var(--accent); font-size:1.1rem;">${b.plate_no || 'N/A'}</div>
                  <div style="font-size:0.8rem; color:var(--text-dim); margin-top:5px;"><i class="fas fa-wrench" style="font-size:0.7rem;"></i> ${b.mechanic_name || 'Unassigned'}</div>
                </div>
              ` : '';

            return `
              <div class="bay-card ${!isAvail ? 'clickable' : ''}" 
                   onclick="${!isAvail ? action : ''}"
                   style="padding:2rem; border-radius:28px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); position:relative; overflow:hidden; display:flex; flex-direction:column; justify-content:space-between; min-height:280px; cursor:${!isAvail ? 'pointer' : 'default'}; transition:all 0.3s;">
                <div style="position:absolute; top:-30px; right:-30px; width:120px; height:120px; background:${isAvail ? 'var(--accent)' : '#ef4444'}; opacity:0.05; filter:blur(40px);"></div>
                <div>
                  <span class="badge ${isAvail ? 'badge-active' : 'badge-danger'}" style="font-weight:800;">${displayStatus}</span>
                  <h2 style="margin:1rem 0 0.5rem; font-size:1.6rem; font-weight:900; letter-spacing:-1px;">${b.bay_name}</h2>
                  ${occupiedInfo}
                </div>
                ${isAvail ? `
                <button class="btn-action" style="width:100%; margin-top:1.5rem; background:var(--accent); color:white; border:none; padding:0.9rem; border-radius:15px; font-weight:800; cursor:pointer; transition:all 0.4s; display:flex; align-items:center; justify-content:center; gap:10px;" 
                    onmouseover="this.style.transform='translateY(-3px)';" 
                    onmouseout="this.style.transform='translateY(0)';" 
                    onclick="event.stopPropagation(); ${action}">
                  <i class="fas fa-eye"></i> View Bay
                </button>` : ''}
              </div>`;

          }).join('');
        });
    };

    window.refreshJobOrders = function () {
      const body = document.getElementById('jobOrdersTableBody'); if (!body) return;
      console.log("[REFRESH] Active Jobs...");
      body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim);"><i class="fas fa-spinner fa-spin"></i> Loading active jobs...</td></tr>';
      fetch('tenant-dashboard.php?action=fetch_job_orders&_v=' + Date.now())
        .then(r => r.text()).then(text => {
          try {
            const start = text.indexOf('[');
            const end = text.lastIndexOf(']') + 1;
            if (start === -1 || end === 0) throw new Error('No JSON found: ' + text.substring(0, 200));
            const data = JSON.parse(text.substring(start, end));

            if (!Array.isArray(data) || data.length === 0) {
              body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim);">No active job orders in the shop.</td></tr>';
              return;
            }
            body.innerHTML = data.map(j => `
              <tr>
                <td>
                  <strong>JO-${(j.job_id || 0).toString().padStart(4, '0')}</strong><br>
                  <span style="font-size:0.6rem; font-weight:900; padding:2px 6px; border-radius:4px; margin-top:4px; display:inline-block; background:${j.appointment_id > 0 ? 'rgba(59,130,246,0.1)' : 'rgba(16,185,129,0.1)'}; color:${j.appointment_id > 0 ? '#3b82f6' : '#10b981'}; border:1px solid ${j.appointment_id > 0 ? 'rgba(59,130,246,0.2)' : 'rgba(16,185,129,0.2)'};">
                    ${j.appointment_id > 0 ? 'APPOINTMENT' : 'WALK-IN'}
                  </span>
                </td>
                <td>
                  <div style="font-weight:700; color:var(--text-main); font-size:1rem;">${j.plate_no || '---'}</div>
                  <div style="font-size:0.75rem; color:var(--text-dim);">${j.make || ''} ${j.model || ''}</div>
                  <div style="font-size:0.8rem; color:var(--accent); font-weight:600; margin-top:3px;"><i class="fas fa-user"></i> ${j.customer_name || 'Walking Customer'}</div>
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
                  <button class="btn-outline job-status-btn" style="padding:4px 10px; font-size:0.75rem; border-color:var(--accent); color:var(--accent); cursor:pointer !important;"
                          onclick="window.handleJobClick(${j.job_id}, '${j.status}', ${j.mechanic_id || 0}, ${j.bay_id || 0}, true, false)"
                          data-jid="${j.job_id}" data-status="${j.status}" data-mid="${j.mechanic_id || 0}" data-bid="${j.bay_id || 0}" data-edit="true" data-focus="false">
                    <i class="fas fa-user-cog"></i> Assign / Update
                  </button>
                  ` : '<span style="font-size:0.75rem; color:var(--text-dim); opacity:0.6;"><i class="fas fa-check-double"></i> Finalized</span>'}
                </td>
              </tr>`).join('');
          } catch (e) {
            console.error("[refreshJobOrders] Parse Error:", e.message, "\nRaw response:", text.substring(0, 500));
            body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;"><i class="fas fa-exclamation-circle"></i> Data Error: Could not display jobs.<br><small style="opacity:0.6;">${e.message}</small></td></tr>`;
          }
        }).catch(err => {
          console.error("[refreshJobOrders] Network Error:", err);
          body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;"><i class="fas fa-wifi"></i> Network Error: Could not reach server.</td></tr>';
        });
    };

    window.refreshMechanicHistory = function () {
      const body = document.getElementById('mechanicHistoryTable');
      if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_mechanic_history')
        .then(r => r.json())
        .then(data => {
          if (!data.length) {
            body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:2rem;">No history found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(log => `
             <tr>
               <td><small>${new Date(log.created_at).toLocaleString()}</small></td>
               <td><strong>${log.plate_no || 'N/A'}</strong><br><small style="color:var(--text-dim)">${log.make || ''} ${log.model || ''}</small></td>
               <td><span class="badge ${log.status_update === 'COMPLETED' ? 'badge-active' : 'badge-info'}" style="font-size:0.65rem;">${log.status_update}</span></td>
               <td style="font-size:0.9rem;">${log.remarks || '---'}</td>
             </tr>
           `).join('');
        });
    };

    window.refreshInventoryLookup = function () {
      const body = document.getElementById('inventoryLookupTable');
      if (!body) return;
      fetch('tenant-dashboard.php?action=fetch_inventory_lookup')
        .then(r => r.json())
        .then(data => {
          window.allInventoryLookup = data;
          renderInventoryLookup(data);
        });
    };

    function renderInventoryLookup(data) {
      const body = document.getElementById('inventoryLookupTable');
      if (!body) return;
      if (!data.length) {
        body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:2rem;">No parts found.</td></tr>';
        return;
      }
      body.innerHTML = data.map(i => {
        let statusText = 'IN STOCK';
        let statusClass = 'badge-active';
        const qty = parseInt(i.quantity);
        if (qty < 5) {
          statusText = qty <= 0 ? 'OUT OF STOCK' : 'LOW STOCK';
          statusClass = 'badge-danger';
        }

        return `
        <tr>
          <td><strong>${i.item_name}</strong></td>
          <td>${i.brand || '---'}</td>
          <td><span style="font-weight:700; color:${statusClass === 'badge-danger' ? 'var(--danger)' : 'var(--accent)'};">${i.quantity}</span> pcs</td>
          <td><span class="badge ${statusClass}">${statusText}</span></td>
        </tr>
      `;
      }).join('');
    }

    window.filterInventoryLookup = function (query) {
      if (!window.allInventoryLookup) return;
      const q = query.toLowerCase();
      const filtered = window.allInventoryLookup.filter(i =>
        i.item_name.toLowerCase().includes(q) ||
        (i.brand && i.brand.toLowerCase().includes(q))
      );
      renderInventoryLookup(filtered);
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

    <!-- Sidebar Toggle (Floating Arrow) -->
    <button id="sidebarToggle" onclick="window.toggleSidebar()" class="sidebar-trigger">
      <i class="fas fa-chevron-left"></i>
    </button>

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
        <i class="fas fa-home"></i> <span class="nav-label">Dashboard</span>
      </div>

      <div class="nav-group-title">Public Presence</div>
      <a href="shop.php?id=<?php echo urlencode($tenant_custom['slug'] ?? ''); ?>" target="_blank" class="nav-item-link"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-external-link-alt"></i> <span class="nav-label">View My Website</span>
      </a>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Shop Operations</div>
      <div class="nav-item" data-view="appointments" onclick="window.navToView('appointments')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-calendar-check"></i> <span class="nav-label">Appointments</span>
      </div>
      <div class="nav-item" data-view="job_orders" onclick="window.navToView('job_orders')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-tools"></i> <span class="nav-label">Active Repairs</span>
      </div>
      <div class="nav-item" data-view="bays" onclick="window.navToView('bays')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-warehouse"></i> <span class="nav-label">Service Bays</span>
      </div>
      <div class="nav-item" data-view="mechanics" onclick="window.navToView('mechanics')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-user-cog"></i> <span class="nav-label">Mechanics</span>
      </div>
      <div class="nav-item" data-view="services" onclick="window.navToView('services')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-list-ul"></i> <span class="nav-label">Services & Pricing</span>
      </div>
      <?php elseif ($role === 'CASHIER'): ?>
      <div class="nav-group-title">Cashier Portal</div>
      <div class="nav-item" data-view="customers" onclick="window.navToView('customers')">
        <i class="fas fa-users"></i> <span class="nav-label">Customer Registry</span>
      </div>
      <div class="nav-item" data-view="vehicles"
        onclick="window.navToView('vehicles'); if(typeof window.refreshVehiclesList==='function') window.refreshVehiclesList();">
        <i class="fas fa-car"></i> <span class="nav-label">Vehicle Masterfile</span>
      </div>
      <div class="nav-item" data-view="payments" onclick="window.navToView('payments')">
        <i class="fas fa-money-bill-wave"></i> <span class="nav-label">Payment Processing</span>
      </div>
      <?php if ($role === 'CASHIER'): ?>
      <div class="nav-item" data-view="settled_jobs" onclick="window.navToView('settled_jobs')">
        <i class="fas fa-history"></i> <span class="nav-label">Settled Jobs History</span>
      </div>
      <?php endif; ?>
      <?php elseif ($role === 'MECHANIC'): ?>
      <div class="nav-group-title">My Station</div>
      <div class="nav-item" data-view="mechanic_appointments" onclick="window.navToView('mechanic_appointments')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-calendar-check"></i> <span class="nav-label">Upcoming Appointments</span>
      </div>
      <div class="nav-item" data-view="mechanic_history" onclick="window.navToView('mechanic_history')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-history"></i> <span class="nav-label">My Work History</span>
      </div>
      <div class="nav-item" data-view="inventory_lookup" onclick="window.navToView('inventory_lookup')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-boxes"></i> <span class="nav-label">Parts Catalog</span>
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">CRM</div>
      <div class="nav-item" data-view="customers" onclick="window.navToView('customers')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-users"></i> <span class="nav-label">Customers</span>
      </div>
      <div class="nav-item" data-view="vehicles"
        onclick="window.navToView('vehicles'); if(typeof window.refreshVehiclesList==='function') window.refreshVehiclesList();"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-car"></i> <span class="nav-label">Vehicles</span>
      </div>
      <div class="nav-item" data-view="payments" onclick="window.navToView('payments')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-money-check-alt"></i> <span class="nav-label">Payments</span>
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Inventory</div>
      <div class="nav-item" data-view="inventory" onclick="window.navToView('inventory')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
          <span><i class="fas fa-boxes"></i> <span class="nav-label">Parts Inventory</span></span>
          <?php if ($low_stock_count > 0): ?>
          <span
            style="background:linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color:white; font-size:0.6rem; padding:2px 8px; border-radius:8px; font-weight:900; box-shadow:0 0 12px rgba(239, 68, 68, 0.5); animation: pulse 1.5s infinite; display:flex; align-items:center; gap:5px; border:1px solid rgba(255,255,255,0.1);"
            title="<?php echo $low_stock_count; ?> items low on stock">
            <i class="fas fa-exclamation-triangle" style="font-size:0.7rem;"></i>
            <?php echo $low_stock_count; ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (in_array($role, ['OWNER', 'MANAGER'])): ?>
      <div class="nav-group-title">Administration</div>
      <div class="nav-item" data-view="staff" onclick="window.navToView('staff')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
          <span><i class="fas fa-user-shield"></i> <span class="nav-label">Staff Accounts</span></span>
          <?php if ($pending_shift_requests_count > 0): ?>
          <span
            style="background:linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%); color:white; font-size:0.6rem; padding:2px 8px; border-radius:8px; font-weight:900; box-shadow:0 0 12px rgba(99, 102, 241, 0.4); display:flex; align-items:center; gap:5px; border:1px solid rgba(255,255,255,0.1);"
            title="<?php echo $pending_shift_requests_count; ?> pending shift requests">
            <i class="fas fa-clock" style="font-size:0.7rem;"></i>
            <?php echo $pending_shift_requests_count; ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="nav-item" data-view="reports" onclick="window.navToView('reports')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-chart-pie"></i> <span class="nav-label">Reports & Analytics</span>
      </div>
      <?php endif; ?>

      <?php if ($role === 'OWNER'): ?>
      <div class="nav-group-title">Configuration</div>
      <div class="nav-item" data-view="customization" onclick="window.navToView('customization')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-paint-brush"></i> <span class="nav-label">Shop Settings</span>
      </div>
      <div class="nav-item" data-view="customer_logs" onclick="window.navToView('customer_logs')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-history"></i> <span class="nav-label">Audit Trail</span>
      </div>
      <div class="nav-item" data-view="subscription" onclick="window.navToView('subscription')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-credit-card"></i> <span class="nav-label">My Subscription</span>
      </div>
      <?php endif; ?>

      <div class="nav-group-title">My Account</div>
      <div class="nav-item" data-view="my_profile" onclick="window.navToView('my_profile')"
        onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <i class="fas fa-user-circle"></i> <span class="nav-label">My Profile</span>
      </div>
      <?php if ($role === 'OWNER' || $role === 'MANAGER'): ?>
      <div class="nav-item" onclick="toggleChat()" onmouseenter="this.style.background='rgba(255,255,255,0.05)'"
        onmouseleave="this.style.background='transparent'">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
          <span><i class="fas fa-headset"></i> <span class="nav-label">Chat Support</span></span>
          <span id="sidebarChatBadge"
            style="display:none; background:#ff4757; color:white; font-size:0.6rem; padding:2px 7px; border-radius:10px; font-weight:900; box-shadow:0 0 10px rgba(255,71,87,0.4); animation:pulse 2s infinite;">0</span>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:auto; padding: 0 1.5rem 1.5rem; display:flex; flex-direction:column; gap:10px;">
      <div class="nav-item" id="themeToggle" onclick="window.toggleTheme()"
        style="border-radius: 12px; background:rgba(255,255,255,0.05); justify-content:center; cursor:pointer;"
        onmouseenter="this.style.background='rgba(255,255,255,0.1)'"
        onmouseleave="this.style.background='rgba(255,255,255,0.05)'">
        <i class="fas fa-moon"></i> <span class="nav-label">Dark Mode</span>
      </div>

      <a href="?logout=1" class="nav-item"
        style="color:var(--danger); border-radius: 12px; background:rgba(239,68,68,0.05); justify-content:center; cursor:pointer;"
        onmouseenter="this.style.background='rgba(239,68,68,0.1)'"
        onmouseleave="this.style.background='rgba(239,68,68,0.05)'">
        <i class="fas fa-sign-out-alt"></i> <span class="nav-label">Logout</span>
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
    <div class="header-section" style="margin-bottom: 2rem;">
      <h1><i class="fas fa-magic"></i> Shop Customization</h1>
      <p style="color:var(--text-dim);">Personalize your shop's digital identity and customer experience.</p>
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
        <div id="requested_mechanic_display"
          style="margin-bottom:1.5rem; padding:12px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); border-radius:12px; display:none;">
          <div
            style="font-size:0.7rem; color:var(--accent); font-weight:800; text-transform:uppercase; margin-bottom:4px;">
            Customer Requested:</div>
          <div id="requested_mechanic_name_text" style="font-weight:700; color:white; font-size:0.95rem;"></div>
        </div>
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
      <div style="display:flex; align-items:center; gap:20px;">
        <h1 id="pageTitle" style="font-size: 2rem; font-weight: 800; letter-spacing: -1px; margin:0;">
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
      </div>
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
          <div class="avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
            <?php if (!empty($current_user_pic)): ?>
            <img src="<?php echo htmlspecialchars($current_user_pic); ?>"
              style="width:100%; height:100%; object-fit:cover;">
            <?php else: ?>
            <?php echo strtoupper(substr($owner_name, 0, 1)); ?>
            <?php endif; ?>
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
      <!-- Removed redundant H1 title to fix the "double header" issue -->
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
          <p class="stat-label">
            <?php echo ($role === 'MECHANIC') ? 'My Assigned Jobs' : 'Pending Repair Jobs'; ?>
          </p>
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

      <?php if ($role === 'OWNER' || $role === 'MANAGER'): ?>
      <!-- SHIFT CHANGE REQUESTS (OWNER/MANAGER ONLY) -->
      <div id="shiftRequestsSection"
        style="margin-top: 3rem; <?php echo ($pending_shift_requests_count > 0) ? '' : 'display: none;'; ?>">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
          <h3 style="margin:0; font-weight:800; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-clock-rotate-left" style="color:var(--accent);"></i> Pending Shift Requests
            <span id="shiftRequestBadge"
              style="background:var(--danger); color:white; font-size:0.7rem; padding:2px 8px; border-radius:10px; display:none;">0</span>
          </h3>
        </div>
        <div class="glass-panel" style="padding:0; overflow:hidden;">
          <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead style="background:rgba(255,255,255,0.02); border-bottom:1px solid rgba(255,255,255,0.05);">
              <tr>
                <th
                  style="padding:15px 20px; text-align:left; color:var(--text-dim); font-weight:600; text-transform:uppercase; letter-spacing:1px; font-size:0.7rem;">
                  Mechanic</th>
                <th
                  style="padding:15px 20px; text-align:left; color:var(--text-dim); font-weight:600; text-transform:uppercase; letter-spacing:1px; font-size:0.7rem;">
                  Requested Shift</th>
                <th
                  style="padding:15px 20px; text-align:left; color:var(--text-dim); font-weight:600; text-transform:uppercase; letter-spacing:1px; font-size:0.7rem;">
                  Reason</th>
                <th
                  style="padding:15px 20px; text-align:right; color:var(--text-dim); font-weight:600; text-transform:uppercase; letter-spacing:1px; font-size:0.7rem;">
                  Actions</th>
              </tr>
            </thead>
            <tbody id="shiftRequestsBody">
              <?php foreach ($pending_shift_requests_list as $req): ?>
              <tr style="border-bottom:1px solid var(--glass-border);">
                <td style="padding:15px 20px;">
                  <div style="font-weight:700; color:var(--text-main);">
                    <?php echo htmlspecialchars($req['full_name']); ?>
                  </div>
                  <div style="font-size:0.75rem; color:var(--text-dim);">Mechanic ID:
                    #
                    <?php echo $req['mechanic_id']; ?>
                  </div>
                </td>
                <td style="padding:15px 20px;">
                  <div style="color:var(--accent); font-weight:700; font-size:0.9rem;"><i class="far fa-clock"
                      style="margin-right:4px;"></i>
                    <?php echo date('h:i A', strtotime($req['requested_start'])); ?>
                    &ndash;
                    <?php echo date('h:i A', strtotime($req['requested_end'])); ?>
                  </div>
                  <?php if (!empty($req['requested_days'])): ?>
                  <div style="font-size:0.72rem; color:var(--text-dim); margin-top:3px;"><i class="fas fa-calendar-week"
                      style="color:var(--accent); margin-right:4px;"></i>
                    <?php echo implode(' &middot; ', array_map('trim', explode(',', $req['requested_days']))); ?>
                  </div>
                  <?php endif; ?>
                </td>
                <td style="padding:15px 20px;">
                  <div style="font-size:0.85rem; color:var(--text-dim); max-width:250px;">
                    <?php echo htmlspecialchars($req['reason']); ?>
                  </div>
                </td>
                <td style="padding:15px 20px; text-align:right;">
                  <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button onclick="window.processShiftRequest(<?php echo $req['request_id']; ?>, 'APPROVED')"
                      class="btn-action"
                      style="padding:8px 16px; font-size:0.8rem; background:var(--success); border:none; color:white; font-weight:800; border-radius:12px; cursor:pointer;">Approve</button>
                    <button onclick="window.processShiftRequest(<?php echo $req['request_id']; ?>, 'REJECTED')"
                      class="btn-action"
                      style="padding:8px 16px; font-size:0.8rem; background:var(--danger); border:none; color:white; font-weight:800; border-radius:12px; cursor:pointer;">Reject</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

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
            onclick="<?php echo ($role === 'MECHANIC') ? 'navToView(\'mechanic_history\')' : 'alert(\'Queue feature coming soon!\')'; ?>">
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
                <th>Service</th>
                <th>Assigned Mechanic</th>
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

    <?php if ($role === 'CASHIER'): ?>
    <!-- Settled Jobs History View -->
    <div id="settled_jobs" class="view-section">
      <div class="glass-panel" style="padding:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3><i class="fas fa-history" style="color:var(--accent); margin-right:10px;"></i> Settled Jobs History</h3>
          <div style="display:flex; gap:10px;">
            <button class="btn-action" onclick="window.printSettledHistory()"
              style="background:#10b981; border:none; padding: 0.5rem 1rem; font-size: 0.85rem;">
              <i class="fas fa-print"></i> Print Report
            </button>
            <button class="btn-outline" style="padding: 0.5rem 1rem; font-size: 0.85rem;"
              onclick="window.refreshSettledJobs()">
              <i class="fas fa-sync"></i> Refresh List
            </button>
          </div>
        </div>

        <div class="table-container">
          <table id="settledJobsTable" style="width: 100%;">
            <thead>
              <tr>
                <th>PLATE NO.</th>
                <th>VEHICLE / OWNER</th>
                <th>SERVICE DONE</th>
                <th>DATE COMPLETED</th>
                <th>TOTAL BILL</th>
                <th>STATUS</th>
                <th>ACTION</th>
              </tr>
            </thead>
            <tbody id="settledJobsBody">
              <tr>
                <td colspan="6" style="text-align:center; padding:2rem; color:var(--text-dim);">
                  <i class="fas fa-spinner fa-spin"></i> Loading settled jobs...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Mechanic Upcoming Appointments View -->
    <div id="mechanic_appointments" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; margin-bottom: 1.5rem;">
          <h3><i class="fas fa-calendar-alt" style="color:var(--accent); margin-right:10px;"></i> My Upcoming
            Appointments</h3>
          <button class="btn-action" style="padding: 0.5rem 1rem; font-size: 0.85rem;"
            onclick="window.refreshMyUpcomingAppointments()">
            <i class="fas fa-sync"></i> Refresh
          </button>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Service Requested</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="myUpcomingAppointmentsBody">
              <tr>
                <td colspan="5" style="text-align:center; padding:2rem; color:var(--text-dim);">
                  <i class="fas fa-spinner fa-spin"></i> Loading appointments...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div id="appointments" class="view-section">
      <div class="glass-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
          <div>
            <h3>Appointment Calendar</h3>
            <p style="color:var(--text-dim); font-size: 0.9rem;">Review and manage upcoming maintenance bookings from
              the mobile app.</p>
          </div>
          <div style="display:flex; align-items:center; gap:15px;">
            <select id="appointmentSortFilter" onchange="refreshAppointmentsList()"
              style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem 1rem; border-radius:12px; font-weight:700; outline:none; cursor:pointer;">
              <option value="latest">Sort: Latest Bookings</option>
              <option value="date">Sort: By Appointment Date</option>
            </select>
            <button class="btn-action" onclick="refreshAppointmentsList()"><i class="fas fa-sync"></i> Refresh
              List</button>
          </div>
        </div>

        <div style="margin-bottom: 2rem; display: flex; align-items: center; gap: 15px;">
          <div style="position:relative; width: 350px;">
            <i class="fas fa-search"
              style="position:absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
            <input type="text" id="appointmentSearchInput" placeholder="Search customer, plate..."
              onkeyup="refreshAppointmentsList()"
              style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem 1rem 0.8rem 2.5rem; border-radius:12px; font-weight:500; outline:none; width: 100%;">
          </div>
          <div style="color:var(--text-dim); font-size: 0.8rem; font-style: italic;">
            <i class="fas fa-info-circle"></i> Filter by customer name, plate number, or service.
          </div>
        </div>

        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Schedule</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Service</th>
                <th>Requested Mech</th>
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
          <div style="display:flex; gap:10px;">
            <button class="btn-outline" onclick="window.refreshVehiclesList()"><i class="fas fa-sync"></i> Sync
              Registry</button>
            <button class="btn-action" onclick="openModal('vehicleModal')"><i class="fas fa-plus"></i> Register New
              Vehicle</button>
          </div>
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
              <td colspan="4" style="text-align:center; padding:5rem; color:var(--text-dim);">
                <i class="fas fa-spinner fa-spin" style="font-size:2rem; margin-bottom:1rem;"></i><br>
                Initializing vehicle directory...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Payments View -->
    <div id="payments" class="view-section" style="display:none; min-height: 600px; opacity: 1 !important;">
      <div class="glass-panel" style="display: block !important;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
          <h3><i class="fas fa-coins" style="color:var(--accent)"></i> Payment Monitoring</h3>
          <div style="display:flex; gap:10px;">
            <button class="btn-outline" onclick="showEODReport()"><i class="fas fa-file-invoice-dollar"></i>
              End of Day Summary</button>
            <button class="btn-outline" onclick="refreshPaymentsList()"><i class="fas fa-sync"></i> Sync
              Logs</button>
            <?php if (in_array($role, ['MANAGER', 'CASHIER'])): ?>
            <button class="btn-action" onclick="openModal('paymentModal')"><i class="fas fa-money-bill-wave"></i> Add
              Payment</button>
            <?php endif; ?>
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
                <?php if (count($bays_list) >= $bay_limit): ?>
                <span onclick="window.openUpgradeModal(event)"
                  style="margin-left:12px; color:#111827; cursor:pointer; font-weight:900; font-size:0.65rem; background:linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding:4px 12px; border-radius:8px; box-shadow:0 0 15px rgba(251, 191, 36, 0.5); animation: pulse 1.5s infinite; text-transform:uppercase; letter-spacing:1px; display:inline-flex; align-items:center; gap:5px; border:none;">
                  <i class="fas fa-crown" style="font-size:0.7rem;"></i> Upgrade?
                </span>
                <?php endif; ?>
              </span></p>
          </div>
          <button class="btn-action" <?php echo (count($bays_list) >= $bay_limit) ? 'disabled style="opacity:0.5; cursor:not-allowed; filter:grayscale(1);" title="Subscription Limit Reached"' : ''; ?>
            onclick="openModal('bayModal')">
            <i class="fas fa-plus"></i> Register Bay
          </button>
        </div>
        <div id="baysGrid"
          style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
          <?php if (empty($bays_list)): ?>
          <p style="color:var(--text-dim);">No service bays registered.</p>
          <?php else: ?>
          <?php foreach ($bays_list as $bay):
            // Safety: If status is OCCUPIED but no active job exists, treat as AVAILABLE
            $is_avail = ($bay['status'] === 'AVAILABLE' || empty($bay['active_job_id']));
            $display_status = $is_avail ? 'AVAILABLE' : strtoupper($bay['status']);
            $action = $is_avail ? "openBayProfile(" . (int) $bay['bay_id'] . ")" : "window.handleJobClick(" . ($bay['active_job_id'] ?? 0) . ", '" . ($bay['job_status'] ?? 'PENDING') . "', " . ($bay['active_mechanic_id'] ?? 'null') . ", " . (int) $bay['bay_id'] . ", true, false)";
            ?>
          <div class="bay-card <?php echo !$is_avail ? 'clickable' : ''; ?>"
            onclick="<?php echo !$is_avail ? $action : ''; ?>"
            style="border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); padding:2rem; border-radius:28px; position:relative; overflow:hidden; display:flex; flex-direction:column; justify-content:space-between; min-height:280px; cursor:<?php echo !$is_avail ? 'pointer' : 'default'; ?>; transition:all 0.3s;">
            <div
              style="position:absolute; top:-30px; right:-30px; width:120px; height:120px; background:<?php echo $is_avail ? 'var(--accent)' : '#ef4444'; ?>; opacity:0.05; filter:blur(40px);">
            </div>
            <div>
              <span class="badge <?php echo $is_avail ? 'badge-active' : 'badge-danger'; ?>" style="font-weight:800;">
                <?php echo $display_status; ?>
              </span>
              <h2 style="margin:1.2rem 0 0.5rem; font-size:1.8rem; font-weight:900; letter-spacing:-1px;">
                <?php echo htmlspecialchars($bay['bay_name']); ?>
              </h2>

              <?php if (!$is_avail): ?>
              <div
                style="margin-top:1rem; padding:12px; background:rgba(255,255,255,0.03); border-radius:15px; border:1px solid rgba(255,255,255,0.05);">
                <div
                  style="font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:4px;">
                  In Service</div>
                <div style="font-weight:800; color:var(--accent); font-size:1.1rem;">
                  <?php echo htmlspecialchars($bay['plate_no'] ?? 'N/A'); ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-dim); margin-top:5px;"><i class="fas fa-wrench"
                    style="font-size:0.7rem;"></i>
                  <?php echo htmlspecialchars($bay['mechanic_name'] ?? 'Unassigned'); ?>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <?php if ($is_avail): ?>
            <button class="btn-action"
              style="width:100%; margin-top:1.5rem; background:var(--accent); color:white; border:none; padding:1rem; border-radius:15px; font-weight:800; cursor:pointer;"
              onclick="event.stopPropagation(); <?php echo $action; ?>">
              <i class="fas fa-eye"></i> View Bay
            </button>
            <?php endif; ?>
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
        </div>
        <input type="text" class="search-input" placeholder="Search mechanics by name or spec..."
          oninput="window.searchTable(this, 'mechanicsBody')">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Specialization</th>
              <th>Shift Hours</th>
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
              <td>
                <div style="line-height:1.6;">
                  <span style="font-size:0.8rem; font-weight:700; color:white;">
                    <i class="far fa-clock" style="color:var(--accent); margin-right:4px;"></i>
                    <?php echo date('h:i A', strtotime($m['shift_start'] ?? '08:00:00')); ?> –
                    <?php echo date('h:i A', strtotime($m['shift_end'] ?? '17:00:00')); ?>
                  </span><br>
                  <span style="font-size:0.7rem; color:var(--text-dim);">
                    <?php echo implode(' · ', array_map('trim', explode(',', $m['shift_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat'))); ?>
                  </span>
                </div>
              </td>
              <td><span class="badge <?php echo $m['status'] === 'AVAILABLE' ? 'badge-active' : ''; ?>">
                  <?php echo $m['status']; ?>
                </span>
              </td>
              <td>
                <div style="display:flex; gap:6px;">
                  <button type="button" class="btn-outline"
                    style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--text-main); position:relative; z-index:999; pointer-events: auto !important; cursor: pointer;"
                    onclick="window.openMechanicProfile(<?php echo (int) $m['mechanic_id']; ?>)">View Profile</button>
                  <?php if (strtoupper($role) === 'OWNER' || strtoupper($role) === 'MANAGER'): ?>
                  <button type="button" class="btn-outline"
                    style="padding:6px 12px; font-size:0.75rem; border-color:var(--accent); color:var(--text-main); position:relative; z-index:999; pointer-events: auto !important; cursor: pointer;"
                    onclick="window.openEditShiftModal(<?php echo (int) $m['mechanic_id']; ?>, '<?php echo $m['shift_start']; ?>', '<?php echo $m['shift_end']; ?>', '<?php echo htmlspecialchars($m['full_name']); ?>', '<?php echo $m['shift_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat'; ?>')">Edit
                    Shift</button>
                  <?php endif; ?>
                </div>
              </td>
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
          <button class="btn-action" onclick="window.prepareAddServiceModal()">+ Add Service</button>
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
                  onclick="editService(<?php echo $s['service_id']; ?>, '<?php echo addslashes($s['service_name']); ?>', '<?php echo addslashes($s['description']); ?>', <?php echo $s['price']; ?>, <?php echo $s['master_id'] ?? 'null'; ?>, <?php echo $s['min_price'] ?? 'null'; ?>, <?php echo $s['max_price'] ?? 'null'; ?>)">Edit</button>
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
        <!-- SHIFT CHANGE REQUESTS (INTEGRATED FOR VISIBILITY) -->
        <div id="staffShiftRequestsSection"
          style="margin-bottom: 2.5rem; <?php echo ($pending_shift_requests_count > 0) ? '' : 'display: none;'; ?> padding: 1.5rem; border-radius: 20px; border: 2px solid var(--accent); background: rgba(var(--accent-rgb), 0.05);">
          <h3
            style="margin: 0 0 1.5rem 0; font-weight: 800; display: flex; align-items: center; gap: 10px; color: #fff; font-size: 1.1rem;">
            <i class="fas fa-clock-rotate-left" style="color: var(--accent);"></i> Pending Shift Requests
          </h3>
          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
              <thead style="background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.05);">
                <tr>
                  <th
                    style="padding: 10px 15px; text-align: left; color: var(--text-dim); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">
                    Mechanic</th>
                  <th
                    style="padding: 10px 15px; text-align: left; color: var(--text-dim); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">
                    Requested Shift</th>
                  <th
                    style="padding: 10px 15px; text-align: right; color: var(--text-dim); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">
                    Actions</th>
                </tr>
              </thead>
              <tbody id="staffShiftRequestsBody">
                <?php foreach ($pending_shift_requests_list as $req): ?>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                  <td style="padding:10px 15px;">
                    <div style="font-weight:700; color:#fff;">
                      <?php echo htmlspecialchars($req['full_name']); ?>
                    </div>
                  </td>
                  <td style="padding:10px 15px;">
                    <div style="color:var(--accent); font-weight:700; font-size:0.85rem;">
                      <?php echo date('h:i A', strtotime($req['requested_start'])); ?> -
                      <?php echo date('h:i A', strtotime($req['requested_end'])); ?>
                    </div>
                  </td>
                  <td style="padding:10px 15px; text-align:right;">
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                      <button onclick="window.processShiftRequest(<?php echo $req['request_id']; ?>, 'APPROVED')"
                        class="btn-action"
                        style="padding:6px 14px; font-size:0.75rem; background:var(--success); border:none; color:white; font-weight:800; border-radius:10px; cursor:pointer;">Approve</button>
                      <button onclick="window.processShiftRequest(<?php echo $req['request_id']; ?>, 'REJECTED')"
                        class="btn-action"
                        style="padding:6px 14px; font-size:0.75rem; background:var(--danger); border:none; color:white; font-weight:800; border-radius:10px; cursor:pointer;">Reject</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

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
              <td>
                <div style="display:flex; align-items:center; gap:12px; padding:0.5rem 0;">
                  <div
                    style="width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                    <?php if (!empty($staff['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($staff['profile_pic']); ?>"
                      style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                    <span style="font-size:0.85rem; font-weight:800; color:var(--accent);">
                      <?php echo strtoupper(substr($staff['name'] ?? 'U', 0, 1)); ?>
                    </span>
                    <?php endif; ?>
                  </div>
                  <strong style="color:#fff;">
                    <?php echo htmlspecialchars($staff['name']); ?>
                  </strong>
                </div>
              </td>
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
                  <!-- Header: Compact Profile Header -->
                  <div style="display:flex; align-items:center; gap:15px; margin-bottom:1.5rem; padding:1.2rem; background:rgba(255,255,255,0.03); border-radius:18px; border:1px solid rgba(255,255,255,0.05);">
                    <div style="width:55px; height:55px; border-radius:15px; background:var(--accent); color:#000; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:900; flex-shrink:0; box-shadow:0 8px 20px var(--accent-glow); overflow:hidden;">
                      ${s.profile_pic ? `<img src="${s.profile_pic}" style="width:100%; height:100%; object-fit:cover;">` : (s.name || 'S').charAt(0).toUpperCase()}
                    </div>
                    <div style="flex:1; min-width:0;">
                      <h4 style="margin:0; font-size:1.2rem; color:#fff; letter-spacing:-0.4px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${s.name}</h4>
                      <div style="font-size:0.75rem; color:rgba(255,255,255,0.45); margin-bottom:4px;">${s.email}</div>
                      <span style="background:rgba(99,102,241,0.12); color:var(--accent); font-size:0.6rem; font-weight:800; padding:3px 10px; border-radius:12px; letter-spacing:0.8px; text-transform:uppercase; border:1px solid rgba(99,102,241,0.25);">
                        <i class="fas fa-id-badge" style="margin-right:4px;"></i>${s.role || 'STAFF'}
                      </span>
                    </div>
                  </div>

                  <!-- Info Row: Compact 2-column stats -->
                  <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:1rem;">
                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:12px; padding:0.8rem; text-align:center;">
                      <div style="font-size:0.6rem; color:rgba(255,255,255,0.4); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px; font-weight:700;">Account Status</div>
                      <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.75rem; font-weight:800; color:${s.status === 'ACTIVE' ? '#10b981' : '#ef4444'};">
                        <span style="width:6px; height:6px; border-radius:50%; background:${s.status === 'ACTIVE' ? '#10b981' : '#ef4444'}; display:inline-block;"></span>
                        ${s.status || 'UNKNOWN'}
                      </span>
                    </div>
                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:12px; padding:0.8rem; text-align:center;">
                      <div style="font-size:0.6rem; color:rgba(255,255,255,0.4); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px; font-weight:700;">Date Added</div>
                      <span style="font-size:0.75rem; font-weight:700; color:white;">${s.created_at ? new Date(s.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</span>
                    </div>
                  </div>

                  <!-- Contact Details: Compact single-row or narrow cards -->
                  <div style="margin-bottom:1rem; display:flex; flex-direction:column; gap:8px;">
                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:12px; padding:0.8rem; display:flex; align-items:center; gap:12px;">
                      <i class="fas fa-phone-alt" style="color:#10b981; font-size:0.9rem; width:15px; text-align:center;"></i>
                      <div style="flex:1;">
                        <span style="font-size:0.6rem; color:rgba(255,255,255,0.35); text-transform:uppercase; font-weight:700; display:block; margin-bottom:1px;">Contact</span>
                        <div style="color:white; font-weight:700; font-size:0.85rem;">${s.phone || '<span style="color:rgba(255,255,255,0.15); font-weight:400;">Not Provided</span>'}</div>
                      </div>
                    </div>

                    <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:12px; padding:0.8rem; display:flex; align-items:center; gap:12px;">
                      <i class="fas fa-map-marker-alt" style="color:var(--accent); font-size:0.9rem; width:15px; text-align:center;"></i>
                      <div style="flex:1;">
                        <span style="font-size:0.6rem; color:rgba(255,255,255,0.35); text-transform:uppercase; font-weight:700; display:block; margin-bottom:1px;">Address</span>
                        <div style="color:white; font-weight:700; font-size:0.8rem; line-height:1.3; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">${s.address || '<span style="color:rgba(255,255,255,0.15); font-weight:400;">No address on file</span>'}</div>
                      </div>
                    </div>
                  </div>

                  <!-- Operational Access Panel -->
                  <div style="background:rgba(0,0,0,0.2); padding:1.2rem; border-radius:18px; border:1px solid rgba(255,255,255,0.06);">
                    <label style="display:block; font-size:0.65rem; font-weight:800; color:rgba(255,255,255,0.4); margin-bottom:10px; text-transform:uppercase; letter-spacing:1.2px;">
                      <i class="fas fa-shield-alt" style="margin-right:4px; color:var(--accent);"></i>Permissions
                    </label>
                    <select id="staff_manage_status" style="margin-bottom:0.8rem; padding:0.6rem; font-size:0.85rem; border-radius:10px;">
                      <option value="ACTIVE" ${s.status === 'ACTIVE' ? 'selected' : ''}>ACTIVE (Full Access)</option>
                      <option value="INACTIVE" ${s.status === 'INACTIVE' ? 'selected' : ''}>INACTIVE (Restricted)</option>
                    </select>
                    <button onclick="window.updateStaffStatus(${s.user_id})"
                      style="width:100%; background:var(--accent); color:#000; border:none; padding:0.8rem; border-radius:12px; font-weight:900; cursor:pointer; font-size:0.85rem; box-shadow:0 6px 15px var(--accent-glow); transition:0.3s; text-transform:uppercase; letter-spacing:0.8px;"
                      onmouseover="this.style.transform='translateY(-1px)'"
                      onmouseout="this.style.transform='translateY(0)'">
                      <i class="fas fa-sync" style="margin-right:5px;"></i>Sync Status
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
        <p style="color:var(--text-dim); margin-bottom: 2rem;">Generate insights on revenue, service performance,
          inventory, and mechanic rankings.</p>
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
          <div class="stat-card" style="cursor:pointer; text-align:center;" onclick="showReport('mechanic')">
            <i class="fas fa-user-cog" style="font-size:2rem; color:var(--accent); margin-bottom:1rem;"></i>
            <h4>Mechanic Performance Reports</h4>
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
          <h3 id="reportTitle" style="margin:0; font-size:1.6rem; font-weight:800; letter-spacing:-0.5px;">Report Details</h3>
          <div style="display:flex; align-items:center; gap:12px;">
            <button id="btnExportPDF" onclick="exportReportPDF()"
              style="background:var(--accent); border:none; color:white; padding:0.6rem 1.2rem; border-radius:12px; font-weight:700; font-size:0.85rem; cursor:pointer; display:flex; align-items:center; gap:8px; transition:0.2s; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
              <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <button onclick="closeModal('reportModal')"
              style="background:rgba(255,255,255,0.05); border:none; color:white; width:40px; height:40px; border-radius:12px; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
          </div>
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
          <button class="btn-action"
            style="flex:1; background:var(--accent); color:white; border:none; border-radius:12px; font-weight:700;"
            onclick="window.executeThermalPrint()">
            <i class="fas fa-print"></i> Print Receipt
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
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('shop_name', this)">
                <i class="fas fa-save"></i> Save Shop Name
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Business Description</label>
              <textarea name="description" id="setting_description" placeholder="Short tagline for your business..."
                onfocus="highlightInPreview('description')"><?php echo htmlspecialchars($tenant_custom['description'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('description', this)">
                <i class="fas fa-save"></i> Save Description
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Main Welcome Headline</label>
              <input type="text" name="hero_title" id="setting_hero_title"
                value="<?php echo htmlspecialchars($tenant_custom['hero_title'] ?? ''); ?>"
                placeholder="e.g. Expert Service at Your Fingertips" onfocus="highlightInPreview('hero_title')">
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('hero_title', this)">
                <i class="fas fa-save"></i> Save Headline
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Sub-headline / Intro Text</label>
              <textarea name="hero_subtitle" id="setting_hero_subtitle"
                placeholder="A short welcoming message below your headline..." style="min-height:50px;"
                onfocus="highlightInPreview('hero_subtitle')"><?php echo htmlspecialchars($tenant_custom['hero_subtitle'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('hero_subtitle', this)">
                <i class="fas fa-save"></i> Save Intro Text
              </button>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Shop About Context</label>
              <textarea name="about_text" id="setting_about_text"
                placeholder="Tell your customers about your shop history..." onfocus="highlightInPreview('about_text')"
                style="min-height:80px;"><?php echo htmlspecialchars($tenant_custom['about_text'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('about_text', this)">
                <i class="fas fa-save"></i> Save About Text
              </button>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 1.5rem;">
              <div class="form-group">
                <label>Accent Color</label>
                <input type="color" name="primary_color" id="setting_primary_color"
                  value="<?php echo htmlspecialchars($tenant_custom['primary_color'] ?: '#6366f1'); ?>"
                  onfocus="highlightInPreview('primary_color')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('primary_color', this)">
                  <i class="fas fa-save"></i> Save Color
                </button>
              </div>
              <div class="form-group">
                <label>Background</label>
                <input type="color" name="secondary_color" id="setting_secondary_color"
                  value="<?php echo htmlspecialchars($tenant_custom['secondary_color'] ?: '#030712'); ?>"
                  onfocus="highlightInPreview('secondary_color')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('secondary_color', this)">
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
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('ui_style', this)">
                <i class="fas fa-save"></i> Save Theme
              </button>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Border Radius (Roundness)</label>
              <input type="range" name="border_radius_val" id="setting_border_radius" min="0" max="50"
                value="<?php echo str_replace('px', '', $tenant_custom['border_radius'] ?? '24'); ?>"
                style="width:100%;" onfocus="highlightInPreview('border_radius')">
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('border_radius', this)">
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
                  onclick="saveSettingWithFile('logo_file', this)">
                  <i class="fas fa-upload"></i> Upload
                </button>
              </div>
              <div style="display:flex; gap:10px;">
                <input type="text" name="logo_url" id="setting_logo_url"
                  value="<?php echo htmlspecialchars($tenant_custom['logo_url'] ?? ''); ?>"
                  placeholder="...or enter Image URL" style="font-size:0.8rem; flex:1;"
                  onfocus="highlightInPreview('logo_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSingleSetting('logo_url', this)">
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
                  onclick="saveSettingWithFile('banner_file', this)">
                  <i class="fas fa-upload"></i> Upload
                </button>
              </div>
              <div style="display:flex; gap:10px;">
                <input type="text" name="banner_url" id="setting_banner_url"
                  value="<?php echo htmlspecialchars($tenant_custom['banner_url'] ?? ''); ?>"
                  placeholder="...or enter Image URL" style="font-size:0.8rem; flex:1;"
                  onfocus="highlightInPreview('banner_url')">
                <button type="button" class="feature-save-btn" style="margin-top:0;"
                  onclick="saveSingleSetting('banner_url', this)">
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
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('staff_announcement', this)">
                  <i class="fas fa-satellite-dish"></i> Broadcast Update
                </button>
                <p style="font-size:0.7rem; color:var(--text-dim); margin-top:5px;">This message
                  will
                  appear only to your staff inside the animated pull-down bookmark.</p>
              </div>
            </div>

            <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem;">
               <h4 style="color:var(--accent); text-transform:uppercase; font-size:0.75rem; margin-bottom:1rem; letter-spacing:1px; font-weight:800;">
                 <i class="fas fa-address-book"></i> Public Contact Info
               </h4>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
              <div class="form-group">
                <label>Business Phone</label>
                <input type="text" name="phone" id="setting_phone"
                  value="<?php echo htmlspecialchars($tenant_custom['phone'] ?? ''); ?>" placeholder="e.g. 09123456789"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('phone')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('phone', this)">
                  <i class="fas fa-save"></i> Save Phone
                </button>
              </div>
              <div class="form-group">
                <label>Business Hours</label>
                <input type="text" name="opening_hours" id="setting_opening_hours"
                  value="<?php echo htmlspecialchars($tenant_custom['opening_hours'] ?? ''); ?>"
                  placeholder="e.g. Mon-Sat 8am-5pm" style="font-size:0.8rem;"
                  onfocus="highlightInPreview('opening_hours')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('opening_hours', this)">
                  <i class="fas fa-save"></i> Save Hours
                </button>
              </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
              <label>Business Address</label>
              <textarea name="address" id="setting_address" placeholder="Full physical address of your shop..."
                style="min-height:50px; font-size:0.8rem;"
                onfocus="highlightInPreview('address')"><?php echo htmlspecialchars($tenant_custom['address'] ?? ''); ?></textarea>
              <button type="button" class="feature-save-btn" onclick="saveSingleSetting('address', this)">
                <i class="fas fa-save"></i> Save Address
              </button>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
              <div class="form-group">
                <label><i class="fab fa-facebook"></i> Facebook Page URL</label>
                <input type="text" name="facebook_url" id="setting_facebook_url"
                  value="<?php echo htmlspecialchars($tenant_custom['facebook_url'] ?? ''); ?>"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('facebook_url')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('facebook_url', this)">
                  <i class="fas fa-save"></i> Save FB
                </button>
              </div>
              <div class="form-group">
                <label><i class="fab fa-instagram"></i> Instagram URL</label>
                <input type="text" name="instagram_url" id="setting_instagram_url"
                  value="<?php echo htmlspecialchars($tenant_custom['instagram_url'] ?? ''); ?>"
                  style="font-size:0.8rem;" onfocus="highlightInPreview('instagram_url')">
                <button type="button" class="feature-save-btn" onclick="saveSingleSetting('instagram_url', this)">
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
                  your-shop.com/
                  <?php echo htmlspecialchars($tenant_slug); ?>
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
                                                                                                      <div id="${modalId}" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter:blur(15px); z-index:9999999; display:flex; align-items:center; justify-content:center; padding:20px;">
                                                                                                        <div style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:32px; padding:3rem; width:100%; max-width:480px; text-align:center; box-shadow:0 30px 60px rgba(0,0,0,0.5);">
                                                                                                          <div style="width:80px; height:80px; background:linear-gradient(135deg, #6366f1 0%, #a855f7 100%); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 2rem; color:white; font-size:2.5rem; box-shadow:0 10px 25px rgba(99, 102, 241, 0.4);">
                                                                                                            <i class="fas fa-credit-card"></i>
                                                                                                          </div>
                                                                                                          <h2 style="color:var(--text-main); margin-bottom:0.8rem; font-size:1.8rem; font-weight:800;">Renew Subscription</h2>
                                                                                                          <p style="color:var(--text-dim); margin-bottom:2rem; line-height:1.6;">${confirmMsg}</p>
                  
                                                                                                          <div style="text-align:left; margin-bottom:2.5rem;">
                                                                                                            <label style="color:var(--text-main); font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; display:block; opacity:0.7;">Payment Method</label>
                                                                                                            <select id="payMethod_${modalId}" class="modern-input">
                                                                                                              <option value="GCASH">GCash</option>
                                                                                                              <option value="MAYA">Maya</option>
                                                                                                              <option value="BANK_TRANSFER">Bank Transfer (BDO/BPI)</option>
                                                                                                              <option value="CARD">Credit/Debit Card</option>
                                                                                                            </select>
                                                                                                          </div>

                                                                                                          <div style="display:flex; gap:15px; justify-content:center;">
                                                                                                            <button id="btnConfirm_${modalId}" style="flex:2; padding:16px; background:#6366f1; color:white; border:none; border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem; transition:0.3s; box-shadow:0 10px 20px rgba(99, 102, 241, 0.3);">Go to Payment</button>
                                                                                                            <button id="btnCancel_${modalId}" style="flex:1; padding:16px; background:var(--input-bg); color:var(--text-main); border:1px solid var(--glass-border); border-radius:16px; font-weight:800; cursor:pointer; font-size:1rem;">Cancel</button>
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
                  style="font-size: 3.5rem; font-weight: 900; margin-bottom: 1rem; letter-spacing: -2px; background: linear-gradient(to right, var(--text-main), var(--text-dim)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
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
                    style="width:100%; height:12px; background:var(--input-bg); border-radius:100px; overflow:hidden; border:1px solid var(--glass-border);">
                    <div
                      style="width:<?php echo 100 - $percent; ?>%; height:100%; background:linear-gradient(to right, var(--accent), #a855f7); border-radius:100px; box-shadow: 0 0 15px var(--accent-glow);">
                    </div>
                  </div>
                  <p style="margin-top:1rem; color:var(--text-dim); font-size:0.95rem;">
                    Renews on <strong style="color:var(--text-main);" id="expiryDisplay">
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
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <label
                style="font-size:0.85rem; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px;">1.
                Client Machine</label>
              <button type="button" onclick="window.toggleQuickRegister()" id="quickRegBtn"
                style="background:none; border:none; color:var(--accent); font-size:0.75rem; font-weight:700; cursor:pointer; text-decoration:underline;">
                + Register New
              </button>
            </div>

            <div id="existingVehicleGroup"
              style="position:relative; background:#0f172a !important; border-radius:15px; border:1px solid rgba(255,255,255,0.1); min-height:55px; transition:0.3s; display:flex; align-items:center;">
              <i class="fas fa-car" style="position:absolute; left:1.2rem; color:var(--accent); z-index:10;"></i>
              <select name="vehicle_id" id="assign_vehicle_id"></select>
              <i class="fas fa-chevron-down"
                style="position:absolute; right:1.2rem; color:rgba(255,255,255,0.5); font-size:0.8rem; pointer-events:none; z-index:10;"></i>
            </div>

            <div id="quickRegisterGroup"
              style="display:none; flex-direction:column; gap:0.8rem; background:rgba(255,255,255,0.03); padding:1.2rem; border-radius:15px; border:1px solid rgba(var(--accent-rgb), 0.2);">
              <input type="text" name="new_customer_name" placeholder="Full Customer Name"
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; outline:none;">
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.8rem;">
                <input type="text" name="new_plate_no" placeholder="Plate Number"
                  style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; outline:none;">
                <input type="text" name="new_model" placeholder="Model (e.g. Vios)"
                  style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; outline:none;">
              </div>
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
            style="width:100%; background:linear-gradient(135deg, var(--accent), #059669); color:white; border:none; padding:1.2rem; border-radius:15px; font-weight:800; font-size:1rem; cursor:pointer; box-shadow:0 15px 35px var(--accent-glow); display:flex; align-items:center; justify-content:center; gap:12px; transition:0.3s;">
            <i class="fas fa-play-circle"></i> Start Walk-in Repair
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
    <div id="my_profile" class="view-section" style="display: none; width: 100%; padding-top: 1rem;">
      <div class="glass-panel" style="padding: 2.5rem 3rem 3rem; width: 100%;">
        <!-- Header Area (Matching Staff Management) -->
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 2rem;">
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
        <div style="display: grid; grid-template-columns: 280px 1fr; gap: 4rem; align-items: start;">
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
              <form id="updateProfileFormView" style="display:none;">
                <input type="file" id="profile_pic_input_view" name="profile_pic" accept="image/*"
                  onchange="document.getElementById('updateProfileBtn_view').click()">
                <button type="submit" id="updateProfileBtn_view"></button>
              </form>
            </div>
            <h3 style="margin: 0; font-size: 1.4rem; font-weight: 700; color: #fff;">
              <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>
            </h3>
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
              <span style="font-size: 1.2rem; font-weight: 600; color: #fff;">
                <?php echo htmlspecialchars($_SESSION['name'] ?? 'N/A'); ?>
              </span>
            </div>

            <div style="display: grid; grid-template-columns: 200px 1fr; align-items: center;">
              <span
                style="color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px;">Security
                Role</span>
              <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.2rem; font-weight: 600; color: #fff;">
                  <?php echo $role; ?>
                </span>
                <i class="fas fa-shield-check" style="color: var(--accent); font-size: 1.1rem;"></i>
              </div>
            </div>

            <div style="display: grid; grid-template-columns: 200px 1fr; align-items: center;">
              <span
                style="color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px;">Workshop
                ID</span>
              <span
                style="font-size: 1.1rem; font-weight: 600; color: var(--text-dim); font-family: monospace; letter-spacing: 1px;">#
                <?php echo str_pad($_SESSION['user_id'] ?? '0', 6, '0', STR_PAD_LEFT); ?>
              </span>
            </div>

            <div
              style="margin-top: 1rem; padding: 1.5rem; background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius: 15px; display: flex; align-items: center; gap: 15px; color: var(--text-dim); font-size: 0.85rem;">
              <i class="fas fa-info-circle" style="color: var(--accent);"></i>
              To modify these details, please contact your System Administrator.
            </div>

            <?php if (strtoupper($role) === 'MECHANIC' && $my_shift): ?>
              <div style="margin-top: 2rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                  <div style="display:flex; align-items:center; gap:12px;">
                    <h4 style="margin: 0; color: #fff; font-size: 1.1rem; font-weight: 700;">Working Hours</h4>
                    <?php if ($my_pending_shift_request): ?>
                      <?php
                      $s = $my_pending_shift_request['status'];
                      $color = '#64748b'; // default
                      $bg = 'rgba(100,116,139,0.1)';
                      $icon = 'clock';
                      if ($s === 'PENDING') {
                        $color = '#fbbf24';
                        $bg = 'rgba(251,191,36,0.1)';
                        $icon = 'hourglass-half';
                      }
                      if ($s === 'APPROVED') {
                        $color = '#10b981';
                        $bg = 'rgba(16,185,129,0.1)';
                        $icon = 'check-circle';
                      }
                      if ($s === 'REJECTED') {
                        $color = '#ef4444';
                        $bg = 'rgba(239,68,68,0.1)';
                        $icon = 'times-circle';
                      }
                      ?>
                      <span class="badge"
                        style="background: <?php echo $bg; ?>; color: <?php echo $color; ?>; border: 1px solid <?php echo str_replace('0.1', '0.2', $bg); ?>; font-size:0.7rem; padding:4px 10px; display:inline-flex; align-items:center; gap:5px;">
                        <i class="fas fa-<?php echo $icon; ?>"></i>
                        <?php echo $s; ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <button onclick="openModal('shiftRequestModal')" class="btn-action"
                    style="padding: 10px 20px; font-size: 0.85rem; border-radius:15px; background:var(--accent); color:white; border:none; font-weight:700; cursor:pointer; transition:all 0.3s; box-shadow: 0 4px 15px rgba(var(--accent-rgb), 0.2);"
                    onmouseover="this.style.transform='translateY(-2px)';"
                    onmouseout="this.style.transform='translateY(0)';">Request Change</button>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                  <div
                    style="background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.1), rgba(var(--accent-rgb), 0.02)); padding: 2rem; border-radius: 28px; border: 1px solid rgba(var(--accent-rgb), 0.1); position:relative; overflow:hidden;">
                    <div
                      style="position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:var(--accent); opacity:0.05; filter:blur(30px); border-radius:50%;">
                    </div>
                    <div
                      style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; font-weight:800; letter-spacing:1px; margin-bottom: 12px;">
                      Shift Start</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #fff; letter-spacing:-0.5px;">
                      <?php echo date('h:i A', strtotime($my_shift['shift_start'])); ?>
                    </div>
                    <i class="fas fa-sun"
                      style="position:absolute; bottom:15px; right:15px; font-size:1.2rem; opacity:0.1; color:var(--accent);"></i>
                  </div>
                  <div
                    style="background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.1), rgba(var(--accent-rgb), 0.02)); padding: 2rem; border-radius: 28px; border: 1px solid rgba(var(--accent-rgb), 0.1); position:relative; overflow:hidden;">
                    <div
                      style="position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:var(--accent); opacity:0.05; filter:blur(30px); border-radius:50%;">
                    </div>
                    <div
                      style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; font-weight:800; letter-spacing:1px; margin-bottom: 12px;">
                      Shift End</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #fff; letter-spacing:-0.5px;">
                      <?php echo date('h:i A', strtotime($my_shift['shift_end'])); ?>
                    </div>
                    <i class="fas fa-moon"
                      style="position:absolute; bottom:15px; right:15px; font-size:1.2rem; opacity:0.1; color:var(--accent);"></i>
                  </div>
                </div>

                <div
                  style="margin-top: 20px; background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.1), rgba(var(--accent-rgb), 0.02)); padding: 1.5rem 2rem; border-radius: 28px; border: 1px solid rgba(var(--accent-rgb), 0.1); position:relative; overflow:hidden; display:flex; align-items:center; gap:20px;">
                  <div
                    style="position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:var(--accent); opacity:0.05; filter:blur(30px); border-radius:50%;">
                  </div>
                  <div
                    style="width:45px; height:45px; border-radius:12px; background:rgba(var(--accent-rgb), 0.1); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
                    <i class="fas fa-calendar-week"></i>
                  </div>
                  <div>
                    <div
                      style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; font-weight:800; letter-spacing:1px; margin-bottom: 4px;">
                      Scheduled Work Days</div>
                    <div style="font-size: 0.95rem; font-weight: 700; color: #fff;">
                      <?php echo implode(' · ', array_map('trim', explode(',', $my_shift['shift_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat'))); ?>
                    </div>
                  </div>
                </div>

                <?php if ($my_pending_shift_request && $my_pending_shift_request['status'] === 'PENDING'): ?>
                  <div
                    style="margin-top: 1.5rem; padding: 1.2rem; background: rgba(251,191,36,0.05); border: 1px solid rgba(251,191,36,0.1); border-radius: 18px; font-size: 0.85rem; color: #fbbf24; display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fas fa-history" style="margin-top: 3px;"></i>
                    <div>
                      <div style="font-weight: 700; margin-bottom: 4px;">Pending Change Request</div>
                      Requested New Hours:
                      <strong>
                        <?php echo date('h:i A', strtotime($my_pending_shift_request['requested_start'])); ?> -
                        <?php echo date('h:i A', strtotime($my_pending_shift_request['requested_end'])); ?>
                      </strong>
                    </div>
                  </div>
                <?php elseif ($my_pending_shift_request && $my_pending_shift_request['status'] === 'APPROVED'): ?>
                  <div
                    style="margin-top: 1.5rem; padding: 1.2rem; background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.1); border-radius: 18px; font-size: 0.85rem; color: #10b981; display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fas fa-check-circle" style="margin-top: 3px;"></i>
                    <div>
                      <div style="font-weight: 700; margin-bottom: 4px;">Request Approved</div>
                      Your schedule has been updated to the new requested hours.
                    </div>
                  </div>
                <?php elseif ($my_pending_shift_request && $my_pending_shift_request['status'] === 'REJECTED'): ?>
                  <div
                    style="margin-top: 1.5rem; padding: 1.2rem; background: rgba(239,68,68,0.05); border: 1px solid rgba(239,68,68,0.1); border-radius: 18px; font-size: 0.85rem; color: #ef4444; display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fas fa-times-circle" style="margin-top: 3px;"></i>
                    <div>
                      <div style="font-weight: 700; margin-bottom: 4px;">Request Rejected</div>
                      Your shift change request was declined. Please coordinate with management.
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
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

    window.renderStaffTable = function () {
      const body = document.getElementById('staffBody');
      if (!body) return;

      fetch(getUrl('fetch_staff'))
        .then(res => res.json())
        .then(data => {
          if (!Array.isArray(data) || data.length === 0) {
            body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--text-dim); padding:2rem;">No staff accounts found.</td></tr>`;
            return;
          }

          body.innerHTML = data.map(s => {
            const isSelf = (s.user_id == currentUserId);
            const isTargetOwner = (s.role_name && s.role_name.toUpperCase() === 'OWNER');
            const cannotManage = isSelf || (userRole.toUpperCase() === 'MANAGER' && isTargetOwner);

            return `
              <tr style="transition:0.3s;">
                <td style="padding:1rem 0.5rem;">
                  <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                      ${s.profile_pic ? `<img src="${s.profile_pic}" style="width:100%; height:100%; object-fit:cover;">` : `<span style="font-size:0.85rem; font-weight:800; color:var(--accent);">${(s.name || 'U').charAt(0).toUpperCase()}</span>`}
                    </div>
                    <strong style="font-size:0.95rem; color:#fff;">${s.name}</strong>
                  </div>
                </td>
                <td style="color:var(--text-dim); font-size:0.9rem;">${s.email}</td>
                <td><span class="badge" style="background:rgba(255,255,255,0.05); color:var(--accent); font-size:0.75rem; border:1px solid rgba(255,255,255,0.1);">${s.role_name || 'STAFF'}</span></td>
                <td><span class="badge ${s.status === 'ACTIVE' ? 'badge-active' : 'badge-danger'}">${s.status || 'ACTIVE'}</span></td>
                <td>
                  <button class="btn-outline staff-manage-btn" 
                    style="display:inline-block; padding:8px 16px; font-size:0.75rem; border-radius:10px; border:2px solid var(--accent) !important; color:#000 !important; background:var(--accent) !important; font-weight:800; box-shadow:0 0 15px var(--accent-glow); ${cannotManage ? 'opacity:0.4; filter:grayscale(1); pointer-events:none !important;' : ''}"
                    onclick="event.stopPropagation(); window.openStaffManageModal(${s.user_id});">
                    ${isSelf ? 'You' : (isTargetOwner ? 'Owner' : 'Manage')}
                  </button>
                </td>
              </tr>`;
          }).join('');
        }).catch(err => {
          console.error("Staff Refresh Failed:", err);
          body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;">Error loading staff list.</td></tr>`;
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
          renderStaffTable();
        } else {
          showAlert('Error', res.message, 'error');
        }
      });
    };

    // Redundant Bay logic removed (Moved to top engine)

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
    // Auto-refresh stats every 30 seconds for a truly "live" feel
    setInterval(window.dashboardOverviewRefresh, 30000);

    // Add to initial dash refresh
    const oldRefresh = window.dashboardOverviewRefresh;
    window.dashboardOverviewRefresh = function () {
      if (typeof oldRefresh === 'function') oldRefresh();
      window.refreshShiftRequests();
    };

    // Receipt and Thermal Print logic moved to global utilities (hoisted)


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

    // Consolidated toggleVehicleGroup moved to window scope above (line 5335)



    function refreshInventoryList() {
      fetch('tenant-dashboard.php?action=fetch_inventory')
        .then(res => res.json()).then(data => {
          const body = document.getElementById('inventoryBody');
          if (data.length === 0) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-dim); padding: 2rem;">No inventory records found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(i => {
            const isLow = parseInt(i.quantity) < 5;
            const statusClass = isLow ? 'badge-danger' : 'badge-active';
            const statusText = isLow ? 'LOW STOCK' : 'IN STOCK';
            return `
              <tr>
                <td><strong>${i.item_code}</strong></td>
                <td>${i.item_name} ${i.brand ? '(' + i.brand + ')' : ''}</td>
                <td>${i.quantity}</td>
                <td><span class="badge ${statusClass}">${statusText}</span></td>
                <td><button class="btn-outline" onclick="window.openStockAdjustmentModal(${i.item_id})">Manage</button></td>
              </tr>`;
          }).join('');
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
      setTimeout(() => runSafe('renderStaffTable'), 400);
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
        const accentEl = document.getElementById('setting_primary_color');
        const bgEl = document.getElementById('setting_secondary_color');
        if (!accentEl || !bgEl) return;

        const accent = accentEl.value;
        const bg = bgEl.value;
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
          'setting_shop_name': '.logo span',
          'setting_hero_title': '.hero h1',
          'setting_hero_subtitle': '.hero p'
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

      if (customizationForm) {
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
      }

      const logoFile = document.getElementById('prev_logo_file');
      if (logoFile) {
        logoFile.addEventListener('change', function (e) {
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
      }

      const bannerFile = document.getElementById('prev_banner_file');
      if (bannerFile) {
        bannerFile.addEventListener('change', function (e) {
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
      }

      // Re-sync after iframe loads
      if (frame) frame.onload = syncPreview;
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
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">My Work Updates</h3>
        <button onclick="closeModal('workLogModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Add New Service</h3>
        <button onclick="closeModal('serviceModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addServiceForm" method="POST" action="tenant-dashboard.php?action=add_service">
        <input type="hidden" name="service_action" value="add_service">
        <div style="margin-bottom:1.2rem;">
          <label
            style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--accent); font-weight:700;">Standard
            Service (Admin Regulated)</label>
          <select name="master_id" onchange="window.syncMasterService(this, 'addServiceForm')"
            style="width:100%; background:var(--input-bg); border:1px solid var(--accent); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
            <option value="">-- Custom / Not in List --</option>
            <?php
            try {
              $m_stmt = $db->query("SELECT * FROM master_services ORDER BY service_name ASC");
              while ($ms = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
                $min = $ms['min_price'] ?? 0;
                $max = $ms['max_price'] ?? 0;
                echo "<option value='{$ms['master_id']}' data-min='{$min}' data-max='{$max}'>{$ms['service_name']}</option>";
              }
            } catch (Exception $e) {
            }
            ?>
          </select>
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Service Name</label>
          <input type="text" name="service_name" required placeholder="e.g. Engine Oil Change"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Description</label>
          <textarea name="description" placeholder="What's included?"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; min-height:90px; resize:none; box-sizing:border-box;"></textarea>
        </div>
        <div style="margin-bottom:1.5rem; display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Price (PHP)</label>
            <input type="number" step="0.01" name="price" required placeholder="0.00"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Min Price</label>
            <input type="number" step="0.01" name="min_price" placeholder="Optional"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Max Price</label>
            <input type="number" step="0.01" name="max_price" placeholder="Optional"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Edit Service</h3>
        <button onclick="closeModal('editServiceModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="editServiceForm">
        <input type="hidden" name="service_id" id="edit_service_id">
        <input type="hidden" name="master_id" id="edit_service_master_id">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Service
            Name</label>
          <input type="text" name="service_name" id="edit_service_name" required
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Description</label>
          <textarea name="description" id="edit_service_desc"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; min-height:90px; resize:none; box-sizing:border-box;"></textarea>
        </div>
        <div style="margin-bottom:1.5rem; display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Price (PHP)</label>
            <input type="number" step="0.01" name="price" id="edit_service_price" required
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Min Price</label>
            <input type="number" step="0.01" name="min_price" id="edit_service_min_price" placeholder="Optional"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Max Price</label>
            <input type="number" step="0.01" name="max_price" id="edit_service_max_price" placeholder="Optional"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Create Staff Account</h3>
        <button onclick="closeModal('staffModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="staffMsg" style="display:none; padding:10px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem;">
      </div>
      <form id="addStaffForm" enctype="multipart/form-data">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Full
            Name</label>
          <input type="text" name="staff_name" required placeholder="e.g. Juan dela Cruz"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Email
            Address
            (Login)</label>
          <input type="email" name="email" required placeholder="juan@autoshop.com"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Temporary
            Password</label>
          <input type="text" name="password" required placeholder="TempPass123"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="margin-bottom:1rem; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Mobile #</label>
            <input type="text" name="mobile" required placeholder="0912..."
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
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
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:400px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Register New Bay</h3>
        <button onclick="closeModal('bayModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addBayForm">
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Bay
            Name /
            Number</label>
          <input type="text" name="bay_name" required placeholder="e.g. Bay 3"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
        </div>
        <button type="submit"
          style="width:100%; background:var(--accent); color:white; border:none; padding:1rem; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; box-shadow:0 4px 15px var(--accent-glow);">Save
          Bay</button>
      </form>
    </div>
  </div>

  <!-- Mechanic Modal -->
  <div id="mechanicModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center; color-scheme: dark;">
    <div
      style="background:rgba(17, 24, 39, 0.8); border:1px solid rgba(255,255,255,0.15); border-radius:32px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.8); position:relative; overflow:hidden;">
      <div
        style="position:absolute; top:-50px; right:-50px; width:150px; height:150px; background:var(--accent); filter:blur(100px); opacity:0.15; pointer-events:none;">
      </div>
      <div
        style="position:absolute; bottom:-50px; left:-50px; width:150px; height:150px; background:#8b5cf6; filter:blur(100px); opacity:0.15; pointer-events:none;">
      </div>

      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:1;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div
            style="width:45px; height:45px; border-radius:14px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
            <i class="fas fa-user-gear"></i>
          </div>
          <h3 style="margin:0; font-size:1.5rem; font-weight:800; letter-spacing:-0.5px;">Register Mechanic</h3>
        </div>
        <button onclick="closeModal('mechanicModal')"
          style="width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.05); border:none; color:#94a3b8; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.color='#94a3b8'">&times;</button>
      </div>
      <form id="addMechanicForm" onsubmit="window.submitMechanicForm(event)"
        style="display:flex; flex-direction:column; gap:15px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Full
              Name</label>
            <input type="text" name="mechanic_name" required placeholder="e.g. Cardo Dalisay"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Specialization</label>
            <input type="text" name="specialization" required placeholder="Engine / Paint"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
        </div>

        <div
          style="padding:15px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:15px; margin:5px 0;">
          <h4
            style="margin:0 0 12px; font-size:0.8rem; color:var(--accent); text-transform:uppercase; letter-spacing:1px;">
            Work Shift Schedule</h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div>
              <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Shift Start</label>
              <input type="time" name="shift_start" required value="08:00"
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.85rem; outline:none; box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Shift End</label>
              <input type="time" name="shift_end" required value="17:00"
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.85rem; outline:none; box-sizing:border-box;">
            </div>
          </div>
          <div style="margin-top:12px;">
            <label style="display:block; margin-bottom:8px; font-size:0.8rem; color:#94a3b8;">Work Days</label>
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
              <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
                <label
                  style="display:flex; align-items:center; gap:4px; font-size:0.8rem; background:rgba(255,255,255,0.05); padding:4px 8px; border-radius:6px; cursor:pointer;">
                  <input type="checkbox" name="shift_days[]" value="<?php echo $d; ?>" <?php echo $d !== 'Sun' ? 'checked' : ''; ?> style="accent-color:var(--accent);">
                  <?php echo $d; ?>
                </label>
              <?php endforeach; ?>
            </div>
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
          style="width:100%; background:linear-gradient(135deg, var(--accent), #8b5cf6); color:white; border:none; padding:1.1rem; border-radius:16px; font-size:1.1rem; font-weight:800; cursor:pointer; margin-top:10px; box-shadow: 0 10px 20px rgba(99,102,241,0.2); transition: 0.3s; display:flex; align-items:center; justify-content:center; gap:10px;">
          <i class="fas fa-user-plus"></i> Save & Create Account
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
                  if (typeof renderStaffTable === 'function') renderStaffTable();
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

  <!-- Edit Mechanic Shift Modal -->
  <div id="editShiftModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center; color-scheme: dark;">
    <div
      style="background:rgba(17, 24, 39, 0.85); border:1px solid rgba(255,255,255,0.15); border-radius:32px; padding:2.5rem; width:100%; max-width:420px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.8); position:relative; overflow:hidden;">
      <div
        style="position:absolute; top:-30px; left:-30px; width:100px; height:100px; background:var(--accent); filter:blur(80px); opacity:0.1; pointer-events:none;">
      </div>

      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:1;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div
            style="width:45px; height:45px; border-radius:14px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
            <i class="far fa-clock"></i>
          </div>
          <h3 style="margin:0; font-size:1.4rem; font-weight:800; letter-spacing:-0.5px;">Edit Shift</h3>
        </div>
        <button onclick="closeModal('editShiftModal')"
          style="width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.05); border:none; color:#94a3b8; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.color='#94a3b8'">&times;</button>
      </div>

      <div
        style="margin-bottom:1.5rem; padding:12px; background:rgba(99,102,241,0.05); border-radius:16px; border:1px solid rgba(99,102,241,0.1);">
        <p style="margin:0; font-size:0.85rem; color:#94a3b8; text-align:center;">
          Updating schedule for <strong id="editShiftName" style="color:white; font-weight:800;"></strong>
        </p>
      </div>

      <form id="editShiftForm" onsubmit="window.submitEditShift(event)"
        style="display:flex; flex-direction:column; gap:20px; position:relative; z-index:1;">
        <input type="hidden" name="mechanic_id" id="editShiftId">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
          <div class="input-group">
            <label
              style="display:flex; align-items:center; gap:6px; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">
              <i class="fas fa-sun" style="font-size:0.75rem; color:var(--accent);"></i> Shift Start
            </label>
            <div style="position:relative;">
              <input type="time" name="shift_start" id="editShiftStart" required
                style="width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem 2.5rem 1rem 1rem; border-radius:16px; font-size:1rem; font-weight:600; outline:none; transition:0.3s; box-sizing:border-box;"
                onfocus="this.style.borderColor='var(--accent)'; this.style.background='rgba(0,0,0,0.5)'"
                onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.background='rgba(0,0,0,0.3)'">
              <i class="fas fa-chevron-down"
                style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:var(--text-dim); cursor:pointer; font-size:0.8rem;"
                onclick="try { this.previousElementSibling.showPicker(); } catch(e) { this.previousElementSibling.focus(); }"></i>
            </div>
          </div>
          <div class="input-group">
            <label
              style="display:flex; align-items:center; gap:6px; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">
              <i class="fas fa-moon" style="font-size:0.75rem; color:#818cf8;"></i> Shift End
            </label>
            <div style="position:relative;">
              <input type="time" name="shift_end" id="editShiftEnd" required
                style="width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem 2.5rem 1rem 1rem; border-radius:16px; font-size:1rem; font-weight:600; outline:none; transition:0.3s; box-sizing:border-box;"
                onfocus="this.style.borderColor='var(--accent)'; this.style.background='rgba(0,0,0,0.5)'"
                onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.background='rgba(0,0,0,0.3)'">
              <i class="fas fa-chevron-down"
                style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:var(--text-dim); cursor:pointer; font-size:0.8rem;"
                onclick="try { this.previousElementSibling.showPicker(); } catch(e) { this.previousElementSibling.focus(); }"></i>
            </div>
          </div>
        </div>

        <div class="input-group">
          <label
            style="display:flex; align-items:center; gap:6px; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">
            <i class="fas fa-calendar-week" style="font-size:0.75rem; color:var(--accent);"></i> Work Days
          </label>
          <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
              <label
                style="display:flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; background:rgba(255,255,255,0.05); padding:8px 12px; border-radius:10px; cursor:pointer; border:1px solid rgba(255,255,255,0.05); transition:0.2s;"
                onmouseover="this.style.background='rgba(255,255,255,0.1)'"
                onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                <input type="checkbox" name="shift_days[]" value="<?php echo $d; ?>" class="edit-shift-day-cb"
                  style="accent-color:var(--accent); transform:scale(1.1);">
                <?php echo $d; ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="submit" id="editShiftBtn"
          style="width:100%; background:linear-gradient(135deg, var(--accent), #8b5cf6); color:white; border:none; padding:1.1rem; border-radius:18px; font-size:1.1rem; font-weight:800; cursor:pointer; box-shadow:0 10px 20px rgba(99,102,241,0.2); transition:0.3s; display:flex; align-items:center; justify-content:center; gap:10px;">
          <i class="fas fa-save"></i> Update Schedule
        </button>
      </form>
    </div>
  </div>

  <!-- Mechanic Shift Change Request Modal -->
  <div id="shiftRequestModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center; color-scheme: dark;">
    <div
      style="background:rgba(17, 24, 39, 0.85); border:1px solid rgba(255,255,255,0.15); border-radius:32px; padding:2.5rem; width:100%; max-width:420px; margin:1rem; box-shadow:0 50px 100px rgba(0,0,0,0.8); position:relative; overflow:hidden;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; position:relative; z-index:1;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div
            style="width:45px; height:45px; border-radius:14px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.2rem;">
            <i class="fas fa-file-signature"></i>
          </div>
          <h3 style="margin:0; font-size:1.4rem; font-weight:800; letter-spacing:-0.5px;">Request Shift Change</h3>
        </div>
        <button onclick="closeModal('shiftRequestModal')"
          style="width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.05); border:none; color:#94a3b8; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.color='#94a3b8'">&times;</button>
      </div>

      <form id="shiftRequestForm" onsubmit="window.submitShiftRequest(event)"
        style="display:flex; flex-direction:column; gap:20px; position:relative; z-index:1;">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
          <div class="input-group">
            <label
              style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">Requested
              Start</label>
            <input type="time" name="shift_start" required value="08:00"
              style="width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem; border-radius:16px; font-size:1rem; font-weight:600; outline:none; box-sizing:border-box;">
          </div>
          <div class="input-group">
            <label
              style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">Requested
              End</label>
            <input type="time" name="shift_end" required value="17:00"
              style="width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem; border-radius:16px; font-size:1rem; font-weight:600; outline:none; box-sizing:border-box;">
          </div>
        </div>

        <div>
          <label
            style="display:flex; align-items:center; gap:6px; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">
            <i class="fas fa-calendar-week" style="color:var(--accent); font-size:0.75rem;"></i> Requested Days
          </label>
          <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
              <label
                style="display:flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; background:rgba(255,255,255,0.05); padding:8px 12px; border-radius:10px; cursor:pointer; border:1px solid rgba(255,255,255,0.05); transition:0.2s;"
                onmouseover="this.style.background='rgba(255,255,255,0.1)'"
                onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                <input type="checkbox" name="shift_days[]" value="<?php echo $d; ?>" <?php echo $d !== 'Sun' ? 'checked' : ''; ?> style="accent-color:var(--accent); transform:scale(1.1);">
                <?php echo $d; ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <label style="display:block; margin-bottom:10px; font-size:0.85rem; font-weight:600; color:#94a3b8;">Reason
            for Change</label>
          <textarea name="reason" placeholder="Explain why you need to change your shift..." required
            style="width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem; border-radius:16px; font-size:0.95rem; outline:none; min-height:100px; resize:none; box-sizing:border-box;"></textarea>
        </div>

        <button type="submit" id="shiftRequestBtn"
          style="width:100%; background:linear-gradient(135deg, var(--accent), #8b5cf6); color:white; border:none; padding:1.1rem; border-radius:18px; font-size:1.1rem; font-weight:800; cursor:pointer; box-shadow:0 10px 20px rgba(99,102,241,0.2); transition:0.3s; display:flex; align-items:center; justify-content:center; gap:10px;">
          <i class="fas fa-paper-plane"></i> Submit Request
        </button>
      </form>
    </div>
  </div>

  <script>
    window.submitShiftRequest = function (e) {
      e.preventDefault();
      const btn = document.getElementById('shiftRequestBtn');
      const originalText = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
      btn.disabled = true;

      const fd = new FormData(document.getElementById('shiftRequestForm'));
      fetch('tenant-dashboard.php?action=request_shift_change', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            showToast(data.message, 'success');
            closeModal('shiftRequestModal');
            document.getElementById('shiftRequestForm').reset();
          } else {
            showToast(data.message, 'error');
          }
        })
        .catch(() => showToast("Network error", 'error'))
        .finally(() => {
          btn.innerHTML = originalText;
          btn.disabled = false;
        });
    };
  </script>


  <script>
    window.openEditShiftModal = function (id, start, end, name, daysStr) {
      const idEl = document.getElementById('editShiftId');
      const startEl = document.getElementById('editShiftStart');
      const endEl = document.getElementById('editShiftEnd');
      const nameEl = document.getElementById('editShiftName');
      
      if (idEl) idEl.value = id;
      if (startEl) startEl.value = start;
      if (endEl) endEl.value = end;
      if (nameEl) nameEl.innerText = name;

      const selectedDays = (daysStr || 'Mon,Tue,Wed,Thu,Fri,Sat').split(',');
      document.querySelectorAll('.edit-shift-day-cb').forEach(cb => {
        cb.checked = selectedDays.includes(cb.value);
      });

      window.openModal('editShiftModal');
    };

    window.submitEditShift = function (e) {
      e.preventDefault();
      const btn = document.getElementById('editShiftBtn');
      const originalText = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
      btn.disabled = true;

      const fd = new FormData(document.getElementById('editShiftForm'));
      fetch('tenant-dashboard.php?action=update_mechanic_shift', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            showToast(data.message, 'success');
            closeModal('editShiftModal');
            setTimeout(() => location.reload(), 1000);
          } else {
            showToast(data.message, 'error');
          }
        })
        .catch(() => showToast('Network error', 'error'))
        .finally(() => {
          btn.innerHTML = originalText;
          btn.disabled = false;
        });
    };
  </script>

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
      <div id="staffManageContent"
        style="position:relative; z-index:2; max-height:70vh; overflow-y:auto; padding-right:5px;">
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
          <button onclick="window.printEOD()"
            style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:8px 15px; border-radius:10px; cursor:pointer; font-size:0.85rem;"><i
              class="fas fa-print"></i> Print</button>
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:520px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Receive Inventory Delivery</h3>
        <button onclick="closeModal('inventoryModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addInventoryForm" style="display:flex; flex-direction:column; gap:12px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Item Code</label>
            <input type="text" name="item_code" placeholder="SKU-123" required
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Brand</label>
            <input type="text" name="brand" placeholder="e.g. Bosch" required
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
        </div>
        <div>
          <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Item Name</label>
          <input type="text" name="item_name" required placeholder="e.g. 5W-40 Synthetic Oil"
            style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Cost Price (₱)</label>
            <input type="number" step="0.01" name="price" placeholder="0.00" required
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Initial Qty</label>
            <input type="number" name="quantity" value="1" required
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.8rem; border-radius:10px; font-size:0.9rem; outline:none; box-sizing:border-box;">
          </div>
        </div>
        <div style="font-size:0.75rem; color:var(--text-dim); display:flex; align-items:center; gap:6px; margin:0.5rem 0 1rem 0;">
          <i class="fas fa-info-circle" style="color:var(--accent);"></i>
          <span>Low stock alert is fixed at <strong>less than 5</strong> items.</span>
        </div>
        <button type="submit"
          style="width:100%; background:var(--accent); color:white; border:none; padding:1.2rem; border-radius:12px; font-size:1.1rem; font-weight:800; cursor:pointer; margin-top:10px; box-shadow:0 10px 25px var(--accent-glow); transition:0.3s;">
          Add to Stock
        </button>
      </form>
    </div>
  </div>

  <!-- Stock Adjustment Modal -->
  <div id="stockAdjustmentModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:450px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 id="adjItemName" style="margin:0; font-size:1.2rem; color:var(--accent);">Adjust Stock</h3>
        <button onclick="closeModal('stockAdjustmentModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="stockAdjustmentForm" style="display:flex; flex-direction:column; gap:15px;">
        <input type="hidden" name="item_id" id="adj_item_id">

        <div>
          <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Physical Qty on Hand</label>
          <input type="number" name="quantity" id="adj_quantity" required
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem; border-radius:12px; font-size:1.1rem; font-weight:800; outline:none; box-sizing:border-box;">
          <p style="font-size:0.7rem; color:var(--text-dim); margin-top:5px;">Update this if the actual count in your
            shelf is different.</p>
        </div>

        <div>
          <label style="display:block; margin-bottom:6px; font-size:0.8rem; color:#94a3b8;">Selling Price (₱)</label>
          <input type="number" step="0.01" name="price" id="adj_price" required
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem; border-radius:12px; font-size:1rem; outline:none; box-sizing:border-box;">
        </div>
        <div style="font-size:0.75rem; color:var(--text-dim); display:flex; align-items:center; gap:6px; margin:0.5rem 0 1rem 0;">
          <i class="fas fa-info-circle" style="color:var(--accent);"></i>
          <span>Low stock alert is fixed at <strong>less than 5</strong> items.</span>
        </div>

        <button type="submit"
          style="width:100%; background:var(--accent); color:white; border:none; padding:1.1rem; border-radius:15px; font-size:1rem; font-weight:800; cursor:pointer; margin-top:10px; box-shadow:0 15px 30px var(--accent-glow);">
          Apply Adjustments
        </button>
      </form>
    </div>
  </div>

  <script>
    window.openStockAdjustmentModal = function (itemId) {
      fetch(`tenant-dashboard.php?action=fetch_item_details&item_id=${itemId}`)
        .then(r => r.json()).then(item => {
          if (item) {
            const idEl = document.getElementById('adj_item_id');
            const qtyEl = document.getElementById('adj_quantity');
            const prEl = document.getElementById('adj_price');
            const nameEl = document.getElementById('adjItemName');

            if (nameEl) nameEl.innerText = `Adjust: ${item.item_name}`;
            if (idEl) idEl.value = item.item_id;
            if (qtyEl) qtyEl.value = item.quantity;
            if (prEl) prEl.value = item.price;
            openModal('stockAdjustmentModal');
          }
        });
    };

    // Standard binding for the new form
    document.addEventListener('DOMContentLoaded', () => {
      const adjForm = document.getElementById('stockAdjustmentForm');
      if (adjForm) {
        adjForm.addEventListener('submit', function (e) {
          e.preventDefault();
          const fd = new FormData(this);
          const btn = this.querySelector('button[type="submit"]');
          const orig = btn.innerText;
          btn.innerText = "Applying...";
          btn.disabled = true;

          fetch('tenant-dashboard.php?action=adjust_stock', { method: 'POST', body: fd })
            .then(r => r.json()).then(data => {
              if (data.status === 'success') {
                showToast(data.message, 'success');
                closeModal('stockAdjustmentModal');
                window.refreshInventoryList();
              } else {
                showAlert("Error", data.message, 'error');
              }
            })
            .finally(() => {
              btn.innerText = orig;
              btn.disabled = false;
            });
        });
      }
    });
  </script>

  <!-- Vehicle Modal -->
  <div id="vehicleModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 style="margin:0; font-size:1.3rem; color:var(--text-main);">Register New Vehicle</h3>
        <button onclick="closeModal('vehicleModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <form id="addVehicleForm">
        <div style="margin-bottom:1rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Owner
            (Select
            Customer)</label>
          <select name="customer_id" required>
            <option value="">-- Choose Owner --</option>
            <?php
            $stmt = $db->prepare("SELECT customer_id, full_name FROM customers WHERE tenant_id = ? AND status = 'ACTIVE' ORDER BY full_name ASC");
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
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Model</label>
            <input type="text" name="model" placeholder="Vios"
              style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
          </div>
        </div>
        <div style="margin-bottom:1.5rem;">
          <label style="display:block; margin-bottom:6px; font-size:0.85rem; color:#94a3b8;">Year</label>
          <input type="number" name="year" value="<?php echo date('Y'); ?>"
            style="width:100%; background:var(--input-bg); border:1px solid var(--glass-border); color:var(--text-main); padding:0.9rem 1rem; border-radius:10px; font-size:0.95rem; outline:none; box-sizing:border-box;">
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
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:850px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; border-bottom:1px solid var(--glass-border); padding-bottom:1rem;">
        <h3 style="margin:0; font-size:1.5rem;"><i class="fas fa-user-circle"></i> Customer
          Profile</h3>
        <button onclick="closeModal('customerProfileModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
      </div>
      <div id="profileModalContent">
        <!-- Data loaded via AJAX -->
      </div>
    </div>
  </div>
  <div id="bayProfileModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:var(--bg-deep); border:1px solid var(--glass-border); border-radius:24px; padding:2.5rem; width:100%; max-width:850px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6);">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; border-bottom:1px solid var(--glass-border); padding-bottom:1rem;">
        <h3 style="margin:0; font-size:1.5rem;"><i class="fas fa-warehouse"></i> Bay Information
        </h3>
        <button onclick="closeModal('bayProfileModal')"
          style="background:none; border:none; color:var(--text-main); font-size:1.8rem; cursor:pointer; line-height:1;">&times;</button>
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
      style="background:#111827; border:1px solid rgba(255,255,255,0.12); border-radius:24px; padding:1.5rem; width:100%; max-width:440px; margin:1rem; box-shadow:0 40px 80px rgba(0,0,0,0.6); max-height:90vh; overflow-y:auto;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem;">
        <h3 style="margin:0; font-size:1.2rem; font-weight:800; letter-spacing:-0.5px;">Process Payment</h3>
        <button onclick="closeModal('paymentModal')"
          style="background:rgba(255,255,255,0.05); border:none; color:white; width:32px; height:32px; border-radius:8px; font-size:1.5rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
      </div>
      <form id="addPaymentForm">
        <input type="hidden" name="job_id" id="pay_job_id">
        <input type="hidden" name="appointment_id" id="pay_appointment_id">

        <div style="margin-bottom:0.8rem;">
          <label
            style="display:block; margin-bottom:5px; font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Select
            Customer</label>
          <select name="customer_id" required onchange="window.toggleWalkInField(this.value)"
            style="padding:0.7rem; font-size:0.9rem;">
            <option value="">-- Choose Customer --</option>
            <option value="WALKIN" style="color:var(--accent); font-weight:700;">+ Walk-in / New Customer</option>
            <?php
            $stmt = $db->prepare("SELECT customer_id, full_name FROM customers WHERE tenant_id = ? AND mobile != 'WALKIN' AND status = 'ACTIVE' ORDER BY full_name ASC");
            $stmt->execute([$tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              echo "<option value='{$row['customer_id']}'>{$row['full_name']}</option>";
            }
            ?>
          </select>
        </div>

        <div id="walkinField"
          style="display:none; margin-bottom:0.8rem; background:rgba(var(--accent-rgb), 0.05); padding:0.8rem; border-radius:12px; border:1px solid rgba(var(--accent-rgb), 0.15);">
          <label
            style="display:block; margin-bottom:6px; font-size:0.75rem; color:var(--accent); font-weight:800;">CUSTOMER
            NAME (WALK-IN)</label>
          <input type="text" name="walkin_name" placeholder="Enter full name"
            style="width:100%; background:rgba(0,0,0,0.3); border:1px solid var(--accent); color:white; padding:0.7rem; border-radius:10px; font-size:0.95rem; outline:none;">
        </div>

        <div
          style="margin-bottom:0.8rem; background:rgba(255,255,255,0.02); padding:0.8rem; border-radius:14px; border:1px solid rgba(255,255,255,0.05);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <label style="font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Items /
              Parts / Services</label>
            <div style="display:flex; gap:5px;">
              <button type="button" onclick="window.addCustomServiceRow()"
                style="background:rgba(255,255,255,0.1); color:#fff; border:none; border-radius:6px; padding:3px 8px; font-size:0.65rem; font-weight:800; cursor:pointer;">+
                SERVICE</button>
              <button type="button" onclick="window.showPaymentPartsSelector()"
                style="background:var(--accent); color:#000; border:none; border-radius:6px; padding:3px 8px; font-size:0.65rem; font-weight:800; cursor:pointer;">+
                PART</button>
            </div>
          </div>
          <div id="paymentPartsList"
            style="display:flex; flex-direction:column; gap:6px; max-height:100px; overflow-y:auto;"></div>
          <input type="hidden" name="parts_json" id="pay_parts_json" value="[]">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:0.8rem;">
          <div>
            <label
              style="display:block; margin-bottom:5px; font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Amount
              (PHP)</label>
            <input type="number" id="pay_amount" required step="0.01" placeholder="0.00"
              style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:var(--accent); padding:0.7rem; border-radius:10px; font-size:1rem; font-weight:800; outline:none; pointer-events:none;"
              tabindex="-1">
            <input type="hidden" name="amount" id="pay_amount_hidden">
          </div>
          <div>
            <label
              style="display:block; margin-bottom:5px; font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Discount</label>
            <select id="pay_discount" onchange="calculateFinalAmount()" style="padding:0.7rem; font-size:0.85rem;">
              <option value="0">None</option>
              <option value="20">Senior/PWD (20%)</option>
              <option value="10">Loyalty (10%)</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1.2fr; gap:12px; margin-bottom:1.2rem;">
          <div>
            <label
              style="display:block; margin-bottom:5px; font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Method</label>
            <select name="payment_method" style="padding:0.7rem; font-size:0.85rem;">
              <option value="CASH">CASH</option>
              <option value="GCASH">GCASH</option>
              <option value="BANK">BANK</option>
            </select>
          </div>
          <div>
            <label
              style="display:block; margin-bottom:5px; font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Ref
              No.</label>
            <input type="text" name="reference_no" placeholder="Optional"
              style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.7rem; border-radius:10px; font-size:0.85rem; outline:none;">
          </div>
        </div>

        <button type="submit"
          style="width:100%; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; padding:1rem; border-radius:14px; font-weight:800; cursor:pointer; font-size:0.95rem; box-shadow:0 8px 16px rgba(99,102,241,0.25); transition:0.3s; text-transform:uppercase; letter-spacing:0.5px;">
          Complete Payment
        </button>
      </form>
    </div>
  </div>


  <!-- Job Status Modal (Renamed to Avoid Conflicts) -->
  <div id="repairJobStatusModal_Final"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(12px); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:28px; padding:2.5rem; width:100%; max-width:850px; max-height:90vh; overflow-y:auto; margin:1rem; box-shadow:0 40px 100px rgba(0,0,0,0.7); position:relative;">
      <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:1.5rem;">
        <div>
          <h3 id="jobModalTitle" style="margin:0; font-size:1.5rem; letter-spacing:-0.5px;">
            <?php echo ($role === 'MECHANIC') ? 'Work Progress' : 'Service Management'; ?>
          </h3>
          <p style="color:var(--text-dim); font-size:0.85rem; margin-top:4px;">Manage repair lifecycle and inventory
            consumption.</p>
        </div>
        <button onclick="closeModal('repairJobStatusModal_Final')"
          style="background:rgba(255,255,255,0.05); border:none; color:white; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:0.3s;"
          onmouseover="this.style.background='rgba(255,255,255,0.1)'"
          onmouseout="this.style.background='rgba(255,255,255,0.05)'">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <form id="jobStatusForm">
        <input type="hidden" name="job_id" id="status_job_id">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:2.5rem;">
          <!-- LEFT COLUMN: Status & Assignment -->
          <div style="display:flex; flex-direction:column; gap:1.5rem;">
            <!-- Job Summary Header -->
            <div id="jobDetailsSummary"
              style="background:linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.1); padding:1.5rem; border-radius:20px; border-left: 5px solid var(--accent);">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span
                  style="font-size:0.7rem; color:var(--text-dim); text-transform:uppercase; letter-spacing:1.5px; font-weight:800;">Repair
                  Target</span>
                <span id="summary_origin"
                  style="font-size:0.6rem; font-weight:900; padding:2px 6px; border-radius:4px; display:none;"></span>
              </div>
              <div style="font-weight:800; color:white; font-size:1.2rem; letter-spacing:-0.5px;" id="summary_vehicle">
                Loading...</div>
              <div style="font-size:0.9rem; color:var(--accent); font-weight:700; margin-top:4px;" id="summary_service">
                Please wait</div>
            </div>

            <div>
              <label
                style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8; font-weight:600;">Operational
                Status</label>
              <select name="status" id="job_current_status" required
                onchange="if(window.toggleJobStatusEdit) window.toggleJobStatusEdit(document.getElementById('saveJobBtn').style.display === 'flex')"
                style="width:100%; padding:1rem; border-radius:12px; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; font-weight:600;">
                <option value="PENDING">PENDING (Awaiting Bay)</option>
                <option value="IN_PROGRESS">IN PROGRESS (Work Started)</option>
                <option value="COMPLETED">COMPLETED (Finished)</option>
                <option value="CANCELLED">CANCELLED (Stop Work)</option>
              </select>
            </div>

            <div
              style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; <?php echo ($role === 'MECHANIC') ? 'display:none;' : ''; ?>">
              <div>
                <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Mechanic</label>
                <select <?php echo ($role !== 'MECHANIC') ? 'name="mechanic_id"' : ''; ?> id="status_mechanic_id"
                  style="width:100%;"></select>
              </div>
              <div>
                <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Service Bay</label>
                <select <?php echo ($role !== 'MECHANIC') ? 'name="bay_id"' : ''; ?> id="status_bay_id"
                  style="width:100%;"></select>
              </div>
            </div>

            <?php if ($role === 'MECHANIC'): ?>
              <input type="hidden" name="mechanic_id" id="status_mechanic_id_hidden">
              <input type="hidden" name="bay_id" id="status_bay_id_hidden">
            <?php endif; ?>

            <div>
              <label style="display:block; margin-bottom:8px; font-size:0.85rem; color:#94a3b8;">Work Remarks</label>
              <textarea name="remarks" id="status_remarks" placeholder="Update on findings..."
                style="width:100%; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); color:white; border-radius:12px; padding:1rem; min-height:100px; outline:none; box-sizing:border-box; font-size:0.9rem;"></textarea>
            </div>
          </div>

          <!-- RIGHT COLUMN: Inspection & Parts -->
          <div style="display:flex; flex-direction:column; gap:1.5rem;">
            <div style="<?php echo ($role !== 'MECHANIC') ? 'display:none;' : ''; ?>">
              <label
                style="display:block; margin-bottom:12px; font-size:0.8rem; color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Inspection
                Checklist</label>
              <div
                style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:rgba(255,255,255,0.02); padding:1.2rem; border-radius:18px; border:1px solid rgba(255,255,255,0.05);">
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Engine Fluid"> Engine</label>
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Tire Pressure"> Tires</label>
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Battery Level"> Battery</label>
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Brake System"> Brakes</label>
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Headlights"> Lighting</label>
                <label style="display:flex; align-items:center; gap:10px; font-size:0.85rem; cursor:pointer;"><input
                    type="checkbox" class="ann-chk" value="Suspension"> Suspension</label>
              </div>
            </div>

            <!-- Parts Consumption Integration -->
            <div
              style="flex:1; display:flex; flex-direction:column; border-top:1px solid rgba(255,255,255,0.05); padding-top:1.5rem;">
              <label
                style="display:block; margin-bottom:12px; font-size:0.8rem; color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Parts
                & Materials</label>

              <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:1rem;">
                <div id="partComboboxWrapper" style="position:relative;">
                  <div style="position:relative;">
                    <i class="fas fa-search"
                      style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:0.8rem; color:var(--text-dim);"></i>
                    <input type="text" id="partComboboxInput" placeholder="Search or select part..." autocomplete="off"
                      style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:1rem 2.5rem 1rem 2.5rem; border-radius:15px; font-size:0.95rem; outline:none; box-sizing:border-box; transition:0.3s; cursor:pointer;"
                      onfocus="window.showPartResults()" oninput="window.filterPartCombobox(this.value)">
                    <input type="hidden" id="selectedPartId" value="">
                    <i class="fas fa-chevron-down" id="comboboxArrow"
                      onclick="event.stopPropagation(); window.togglePartResults()"
                      style="position:absolute; right:15px; top:50%; transform:translateY(-50%); font-size:0.8rem; color:var(--text-dim); cursor:pointer; transition:0.3s; padding:10px; margin-right:-10px;"></i>
                  </div>
                  <!-- Floating Results List -->
                  <div id="partResultsList"
                    style="display:none; position:absolute; top:110%; left:0; width:100%; background:#1f2937; border:1px solid rgba(255,255,255,0.1); border-radius:15px; max-height:250px; overflow-y:auto; z-index:10000; box-shadow:0 20px 40px rgba(0,0,0,0.5); padding:8px;">
                    <!-- Items injected here -->
                  </div>
                </div>
                <div style="display:flex; gap:10px;">
                  <div
                    style="flex:1; display:flex; align-items:center; gap:8px; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.1); padding:0 10px; border-radius:10px;">
                    <span style="font-size:0.75rem; color:var(--text-dim);">Qty:</span>
                    <input type="number" id="partQty" value="1" min="1"
                      style="flex:1; background:none; border:none; color:white; padding:0.8rem 0; font-weight:800; outline:none; text-align:center;">
                  </div>
                  <button type="button" onclick="window.addPartToJob()"
                    style="flex:1.5; background:var(--accent); color:white; border:none; border-radius:10px; padding:0.8rem; font-weight:800; cursor:pointer; box-shadow:0 5px 15px var(--accent-glow);">Add
                    to Job</button>
                </div>
              </div>

              <!-- Services & Labor Section (Moved Up) -->
              <div style="margin-bottom:1.5rem;">
                <label
                  style="display:block; margin-bottom:12px; font-size:0.8rem; color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Services
                  & Labor</label>
                <div style="display:flex; gap:10px;">
                  <div id="serviceComboboxWrapper" style="position:relative; flex:1;">
                    <input type="text" id="serviceComboboxInput" placeholder="Add another service..." autocomplete="off"
                      style="width:100%; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:0.8rem 1rem; border-radius:12px; font-size:0.9rem; outline:none;"
                      onfocus="document.getElementById('serviceResultsList').style.display='block'; window.loadServiceSelector();"
                      oninput="window.filterServiceCombobox(this.value)">
                    <input type="hidden" id="selectedServiceId" value="">
                    <div id="serviceResultsList"
                      style="display:none; position:absolute; top:110%; left:0; width:100%; background:#1f2937; border:1px solid rgba(255,255,255,0.1); border-radius:12px; max-height:200px; overflow-y:auto; z-index:10001; padding:5px;">
                    </div>
                  </div>
                  <button type="button" onclick="window.addServiceToJob()"
                    style="background:rgba(255,255,255,0.1); color:white; border:none; border-radius:12px; padding:0 15px; font-weight:700; cursor:pointer;">Add</button>
                </div>
              </div>

              <div id="jobPartsList"
                style="flex:1; display:flex; flex-direction:column; gap:8px; min-height:120px; max-height:220px; overflow-y:auto; background:rgba(0,0,0,0.15); border-radius:15px; padding:12px; border:1px solid rgba(255,255,255,0.03);">
                <div style="text-align:center; color:var(--text-dim); font-size:0.8rem; padding:20px;">No parts
                  recorded.</div>
              </div>

              <div
                style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding:12px; background:rgba(16,185,129,0.05); border-radius:12px; border:1px solid rgba(16,185,129,0.1);">
                <span style="font-size:0.85rem; color:var(--text-dim); font-weight:600;">Total Parts Value:</span>
                <span id="totalPartsBill" style="font-size:1.1rem; font-weight:900; color:var(--accent);">₱0.00</span>
              </div>

              <div
                style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding:12px; background:var(--accent); border-radius:12px; box-shadow:0 10px 20px rgba(var(--accent-rgb), 0.2);">
                <div style="display:flex; flex-direction:column;">
                  <span style="font-size:0.85rem; color:white; font-weight:700;">Overall Bill:</span>
                  <span style="font-size:0.65rem; color:rgba(255,255,255,0.7);">Incl. Service & Parts</span>
                </div>
                <span id="totalOverallBill" style="font-size:1.2rem; font-weight:900; color:white;">₱0.00</span>
              </div>


            </div>
          </div>
        </div>

        <div id="jobModalActions" style="margin-top:2.5rem; display:flex; gap:1rem;">
          <button type="button" id="editJobBtn" onclick="window.toggleJobStatusEdit(true)"
            style="flex:1; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:1.2rem; border-radius:15px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px;">
            <i class="fas fa-user-cog"></i> Update Assignment
          </button>

          <button type="submit" id="saveJobBtn"
            style="flex:2; background:linear-gradient(135deg, var(--accent), #059669); color:white; border:none; padding:1.2rem; border-radius:15px; font-weight:800; font-size:1rem; cursor:pointer; display:none; align-items:center; justify-content:center; gap:12px; box-shadow:0 15px 35px var(--accent-glow); transition:0.3s;">
            <i class="fas fa-save"></i> <span>Commit Progress</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.onerror = function (msg, url, line) {
      alert("GLOBAL JS ERROR: " + msg + " at " + line);
      return false;
    };
    console.log('Main Dashboard Engine Initialized');
    console.log('FINAL_VER_ALPHA_ACTIVE'); // CACHE BUSTER

    // Ultimate Job Status Engine logic moved to the top for immediate availability


    window.openJobStatusModal = openRepairJobStatusModal_Final;
    window.openRepairJobStatusModal_Final = openRepairJobStatusModal_Final;
    window.toggleJobStatusEdit = toggleJobStatusEdit;
    // ULTIMATE GLOBAL EXPORTS - ENSURE AVAILABILITY
    // window.showAlert = showAlert; // Already global
    // window.showToast = showToast; // Already global
    // window.closeModal = closeModal; // Already global
    window.dashboardOverviewRefresh = dashboardOverviewRefresh;
    window.refreshDashboardJobs = refreshDashboardJobs;

    // --- ULTIMATE GLOBAL DELEGATION ---
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.job-status-btn');
      if (btn) {
        console.log("[ULTIMATE-CLICK] Captured via delegation", btn.dataset);
        const d = btn.dataset;
        if (typeof window.handleJobClick === 'function') {
          window.handleJobClick(d.jid, d.status, d.mid, d.bid, d.edit === 'true', d.focus === 'true');
        } else {
          window.openJobStatusModal(d.jid, d.status, d.mid, d.bid, d.edit === 'true', d.focus === 'true');
        }
        e.preventDefault();
        e.stopPropagation();
      }
    }, true); // Capture phase to beat any other listeners

    document.addEventListener('DOMContentLoaded', () => {
      console.log("DOM Loaded - Triggering Refresh");
      if (typeof dashboardOverviewRefresh === 'function') dashboardOverviewRefresh();
    });
    // Immediate fallback call
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      dashboardOverviewRefresh();
      if (typeof refreshDashboardJobs === 'function') refreshDashboardJobs();
    }

    function dashboardOverviewRefresh() {
      console.log("[DASH] Overview Refresh Triggered");
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 5000);

      fetch('tenant-dashboard.php?action=fetch_overview_stats&_t=' + Date.now(), { signal: controller.signal })
        .then(r => r.json()).then(data => {
          clearTimeout(timeoutId);
          console.log("[DASH] Stats Received", data);
          if (document.getElementById('stat_avail_bays')) document.getElementById('stat_avail_bays').innerText = data.avail_bays;
          if (document.getElementById('stat_pending_jobs')) document.getElementById('stat_pending_jobs').innerText = data.pending_jobs;
          if (document.getElementById('stat_revenue')) document.getElementById('stat_revenue').innerText = '₱' + parseFloat(data.revenue).toLocaleString();
          if (document.getElementById('stat_pending-payments')) {
            document.getElementById('stat_pending-payments').innerText = '₱' + parseFloat(data.unpaid_balance).toLocaleString();
          }
        }).catch(err => console.log("[DASH] Stats Timeout/Error"));
      refreshDashboardJobs();
    }

    async function refreshDashboardJobs() {
      if (window.isFetchingJobs) return;
      window.isFetchingJobs = true;

      const body = document.getElementById('dashboardRepairJobsBody');
      const completedBody = document.getElementById('completedJobsBody');
      if (!body) {
        window.isFetchingJobs = false;
        return;
      }

      console.log("[ULTRA-DASH] Syncing...");

      try {
        const url = window.location.origin + window.location.pathname + '?action=fetch_dashboard_jobs_diagnostic&_t=' + Date.now();
        const response = await fetch(url);
        const text = await response.text();

        const jsonMatch = text.match(/\[[\s\S]*\]/) || text.match(/\{[\s\S]*\}/);
        if (!jsonMatch) throw new Error("Server response malformed.");
        const data = JSON.parse(jsonMatch[0]);

        if (data.error || data.status === 'error') throw new Error(data.error || data.message || "Unknown error");

        let activeHtml = '';
        let completedHtml = '';
        const role = '<?php echo strtoupper($_SESSION['role'] ?? ''); ?>';
        console.log("[DEBUG] Current Role:", role);

        if (!Array.isArray(data) || data.length === 0) {
          body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-dim);">No active assignments found.</td></tr>';
          if (completedBody) completedBody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-dim);">No history found.</td></tr>';
          window.isFetchingJobs = false;
          return;
        }

        data.forEach(job => {
          const status = (job.status || '').toUpperCase();
          const isCashier = role === 'CASHIER' || role === 'STAFF'; // Add staff check just in case

          // FOR CASHIER/STAFF: Hide PENDING and REJECTED
          if (isCashier && (status === 'PENDING' || status === 'REJECTED')) return;

          const statusClass = status === 'COMPLETED' || status === 'SETTLED' ? 'badge-active' : (status === 'IN_PROGRESS' ? 'badge-info' : 'badge-pending');
          const isMech = role === 'MECHANIC';

          const isApp = parseInt(job.appointment_id) > 0;
          const originBadge = isApp
            ? `<span style="font-size:0.65rem; background:rgba(59,130,246,0.1); color:#3b82f6; padding:2px 6px; border-radius:4px; border:1px solid rgba(59,130,246,0.2); margin-left:8px; vertical-align:middle;">APPOINTMENT</span>`
            : `<span style="font-size:0.65rem; background:rgba(16,185,129,0.1); color:#10b981; padding:2px 6px; border-radius:4px; border:1px solid rgba(16,185,129,0.2); margin-left:8px; vertical-align:middle;">WALK-IN</span>`;

          let row = `<tr>
                <td>
                  <div style="display:flex; align-items:center;">
                    <strong>${job.plate_no || 'N/A'}</strong>
                    ${originBadge}
                  </div>
                </td>`;

          if (isCashier) {
            row += `
                <td>
                  <div style="font-weight:600;">${job.customer_name || 'Walk-in'}</div>
                  <div style="font-size:0.75rem; color:var(--text-dim);">${(job.make || '') + ' ' + (job.model || '---')}</div>
                </td>
                <td><span style="font-size:0.85rem; font-weight:700;">${job.service_name || 'General Repair'}</span></td>
                <td style="font-weight:800; color:var(--accent);">₱${parseFloat(job.total_amount || 0).toLocaleString()}</td>
                <td><span class="badge ${statusClass}">${status}</span></td>
                <td>
                   <button class="btn-action" style="padding:6px 12px; font-size:0.75rem; background:#10b981; border:none;" 
                           onclick="window.openPaymentForJob('${job.job_id}', '${job.customer_id || ''}', '${job.total_amount || 0}', '${job.customer_name || ''}')">
                     <i class="fas fa-cash-register"></i> Collect
                   </button>
                </td>`;
          } else {
            row += `
                <td>${(job.make || '') + ' ' + (job.model || '---')}</td>
                <td><span style="font-size:0.85rem; font-weight:700;">${job.service_name || 'General Repair'}</span></td>
                <td><i class="fas fa-user-cog" style="color:var(--accent)"></i> ${job.mechanic_name || 'Unassigned'}</td>
                <td><span class="badge ${statusClass}">${status}</span></td>
                ${isMech ? `
                <td>
                    <div style="display:flex; gap:8px;">
                        <button class="btn-action job-status-btn" style="padding:6px 12px; font-size:0.75rem; min-width:110px;" 
                                onclick="window.handleJobClick('${job.job_id}', '${status}', '${job.mechanic_id || 0}', '${job.bay_id || 0}', 'true', 'false')">
                            <i class="fas fa-sync-alt"></i> Update Status
                        </button>
                    </div>
                </td>` : ''}`;
          }
          row += `</tr>`;
          activeHtml += row;
        });

        body.innerHTML = activeHtml || '<tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-dim);">No active jobs.</td></tr>';
        window.isFetchingJobs = false;
      } catch (err) {
        window.isFetchingJobs = false;
        console.error("[ULTRA-DASH] Error:", err);
      }
    }

    async function refreshSettledJobs() {
      const body = document.getElementById('settledJobsBody');
      if (!body) return;

      body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-dim);"><i class="fas fa-spinner fa-spin"></i> Loading settled history...</td></tr>';

      try {
        const response = await fetch('tenant-dashboard.php?action=fetch_settlement_history&_t=' + Date.now());
        const data = await response.json();

        if (data.error) throw new Error(data.error);

        let html = '';
        data.forEach(job => {
          const status = (job.status || 'PENDING').toUpperCase();
          const statusClass = status === 'COMPLETED' || status === 'SETTLED' ? 'badge-active' : (status === 'CANCELLED' || status === 'REJECTED' ? 'badge-danger' : 'badge-pending');

          html += `<tr>
                <td><strong>${job.plate_no || 'N/A'}</strong></td>
                <td>
                  <div style="font-weight:600;">${job.customer_name || 'Walk-in'}</div>
                  <div style="font-size:0.75rem; color:var(--text-dim);">${(job.make || '') + ' ' + (job.model || '---')}</div>
                </td>
                <td><span style="font-size:0.85rem; font-weight:700;">${job.service_name || 'General Repair'}</span></td>
                <td style="font-weight:800; color:var(--accent);">₱${parseFloat(job.total_amount || 0).toLocaleString()}</td>
                <td><span class="badge ${statusClass}">${status}</span></td>
                <td>
                  <button class="btn-action" style="padding:6px 12px; font-size:0.75rem; background:rgba(59,130,246,0.1); color:#3b82f6; border:1px solid rgba(59,130,246,0.2);" 
                    onclick="window.viewJobReceipt(${job.job_id})">
                    <i class="fas fa-file-invoice"></i> Receipt
                  </button>
                </td>
              </tr>`;
        });

        body.innerHTML = html || '<tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-dim);">No settled jobs found in history.</td></tr>';
      } catch (err) {
        console.error("Settled Jobs Error:", err);
        body.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--danger); padding:2rem;">' + err.message + '</td></tr>';
      }
    }
    window.printSettledHistory = function () {
      const table = document.getElementById('settledJobsTable');
      if (!table) return;

      const shopName = "<?php echo addslashes($shop_name ?? 'Auto Shop'); ?>";
      const printWindow = window.open('', '_blank', 'width=1000,height=800');

      const tableClone = table.cloneNode(true);
      // Remove action columns/buttons from print
      const headers = tableClone.querySelectorAll('th');
      const rows = tableClone.querySelectorAll('tr');

      let grandTotal = 0;
      rows.forEach((row, index) => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 5) {
          // TOTAL BILL is index 4
          const amountText = cells[4].innerText.replace(/[^0-9.]/g, '');
          grandTotal += parseFloat(amountText) || 0;
        }
      });

      // Remove action columns/buttons from print
      headers[headers.length - 1].remove();
      rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) cells[cells.length - 1].remove();
      });

      printWindow.document.write(`
        <html>
          <head>
            <title>Settled Jobs History Report</title>
            <style>
              body { font-family: sans-serif; padding: 20px; color: #333; }
              header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
              table { width: 100%; border-collapse: collapse; margin-top: 20px; }
              th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
              th { background: #f4f4f4; }
              .badge { display: none; } /* Hide badges in print if they look bad, or style them */
              .total-cell { font-weight: bold; }
              @media print {
                .no-print { display: none; }
              }
            </style>
          </head>
          <body>
            <header>
              <h1>${shopName}</h1>
              <h2>Settled Jobs History Report</h2>
              <p>Generated on: ${new Date().toLocaleString()}</p>
            </header>
            ${tableClone.outerHTML}
            
            <div style="margin-top: 20px; text-align: right; padding: 15px; border: 2px solid #333; background: #f9f9f9;">
              <span style="font-size: 1.2rem; font-weight: bold; margin-right: 20px;">GRAND TOTAL:</span>
              <span style="font-size: 1.5rem; font-weight: 900; color: #10b981;">₱${grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
            </div>

            <div style="margin-top: 50px; text-align: right; font-size: 0.9rem;">
              <p>__________________________</p>
              <p>Authorized Signature</p>
            </div>
            <script>
              window.onload = function() { window.print(); window.close(); }
            <\/script>
          </body>
        </html>
      `);
      printWindow.document.close();
    };
    window.refreshSettledJobs = refreshSettledJobs;

    // Auto-retry every 10s if stuck
    setInterval(() => {
      const body = document.getElementById('dashboardRepairJobsBody');
      if (body && body.innerText.includes('Loading')) {
        console.log("[ULTRA-DASH] Stuck detected, retrying...");
        refreshDashboardJobs();
      }
    }, 10000);

    window.refreshMyUpcomingAppointments = function () {
      const body = document.getElementById('myUpcomingAppointmentsBody');
      if (!body) return;

      fetch('tenant-dashboard.php?action=fetch_my_upcoming_appointments')
        .then(r => r.json())
        .then(data => {
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-dim); padding:3rem;">No upcoming assigned appointments.</td></tr>';
            return;
          }

          body.innerHTML = data.map(a => {
            const d = new Date(a.appointment_date + ' ' + a.appointment_time);
            const dateStr = d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
            const timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            return `
                <tr>
                  <td>
                    <div style="font-weight:800; color:var(--text-main);">${dateStr}</div>
                    <div style="font-size:0.8rem; color:var(--accent); font-weight:700;"><i class="far fa-clock"></i> ${timeStr}</div>
                  </td>
                  <td>
                    <div style="font-weight:700; color:var(--text-main);">${a.customer_name}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">Customer ID: #${a.customer_id}</div>
                  </td>
                  <td>
                    <div style="font-weight:700;">${a.plate_no || '---'}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">${a.make || ''} ${a.model || ''}</div>
                  </td>
                  <td>
                    <span style="font-size:0.85rem; font-weight:700; color:var(--accent);">${a.service_name || 'General Service'}</span>
                  </td>
                  <td>
                    <span class="badge badge-info" style="font-size:0.65rem; padding:4px 10px; border-radius:10px;">CONFIRMED</span>
                  </td>
                </tr>
              `;
          }).join('');
        })
        .catch(err => {
          console.error("Fetch Appts Error:", err);
          body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:2rem;">Failed to load appointments.</td></tr>';
        });
    };

    // Auto-refresh for mechanic
    if (typeof userRole !== 'undefined' && userRole === 'MECHANIC') {
      setTimeout(window.refreshMyUpcomingAppointments, 500);
      setInterval(window.refreshMyUpcomingAppointments, 30000);
    }

    window.showEODReport = function () {
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

    window.printEOD = function () {
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

    window.toggleWalkInField = function (val) {
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


    window.openPaymentForJob = function (jobId, customerId, amount, walkinName = '') {
      console.log("[PAYMENT] Open from Jobs. Job:", jobId, "Base:", amount);
      const custSelect = document.querySelector('#addPaymentForm select[name="customer_id"]');
      const amtInput = document.getElementById('pay_amount');
      const amtHidden = document.getElementById('pay_amount_hidden');
      const jidInput = document.getElementById('pay_job_id');
      const partsList = document.getElementById('paymentPartsList');

      window.basePaymentAmount = parseFloat(amount || 0);

      if (partsList) partsList.innerHTML = '';
      const partsJsonInput = document.getElementById('pay_parts_json');
      if (partsJsonInput) partsJsonInput.value = '[]';

      if (custSelect) {
        custSelect.value = customerId;
        window.toggleWalkInField(customerId);
        if (customerId === 'WALKIN') {
          const wInput = document.querySelector('#addPaymentForm input[name="walkin_name"]');
          if (wInput) wInput.value = walkinName;
        }
      }
      if (amtInput) amtInput.value = window.basePaymentAmount.toFixed(2);
      if (amtHidden) amtHidden.value = window.basePaymentAmount.toFixed(2);
      if (jidInput) jidInput.value = jobId;

      window.syncPaymentParts();
      openModal('paymentModal');
    }


    // Utilities removed (redundant)

    // Utilities removed (redundant)



    // Redundant modal logic removed


    // togglePartResults removed

    // showPartResults and refreshJobPartsList removed

    // Redundant logic removed


    // Duplicates removed (Handled by top engine)




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

    // Utilities removed (redundant)


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
            const low = parseInt(item.quantity) < 5;
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
      const inputEl = document.getElementById('staffAnnInput');
      if (!inputEl) return;
      const val = inputEl.value;
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

    // Duplicate handleStatusSubmit removed (Handled by top engine)

    const jsf = document.getElementById('jobStatusForm');
    if (jsf) {
      jsf.addEventListener('submit', window.handleStatusSubmit);
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

    // Settings Logic moved to top engine


    // Live Preview Logic moved to top engine

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
      setSafeValue('assign_bay_id', id);
      const title = document.getElementById('assignBayTitle');
      if (title) title.innerText = 'Check-in: ' + name;

      // Reset Quick Register state
      const qGroup = document.getElementById('quickRegisterGroup');
      const eGroup = document.getElementById('existingVehicleGroup');
      const qBtn = document.getElementById('quickRegBtn');
      if (qGroup) qGroup.style.display = 'none';
      if (eGroup) eGroup.style.display = 'flex';
      if (qBtn) {
        qBtn.innerText = '+ Register New';
        qBtn.style.color = 'var(--accent)';
      }

      // Reset inputs
      const form = document.getElementById('assignBayForm');
      if (form) {
        form.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
      }

      openModal('assignBayModal');

      const vS = document.getElementById('assign_vehicle_id');
      const sS = document.getElementById('assign_service_id');
      const mS = document.getElementById('assign_mechanic_id');

      if (vS) vS.innerHTML = '<option>Loading...</option>';
      if (sS) sS.innerHTML = '<option>Loading...</option>';
      if (mS) mS.innerHTML = '<option>Loading...</option>';

      Promise.all([
        fetch('tenant-dashboard.php?action=fetch_vehicles').then(r => r.json()),
        fetch('tenant-dashboard.php?action=fetch_services').then(r => r.json()),
        fetch('tenant-dashboard.php?action=fetch_available_resources').then(r => r.json())
      ]).then(([vehicles, services, res]) => {
        if (vS) {
          vS.innerHTML = '<option value="">-- Select Machine --</option>';
          (vehicles || []).forEach(v => { vS.innerHTML += `<option value="${v.vehicle_id}">${v.plate_no} (${v.model})</option>`; });
        }
        if (sS) {
          sS.innerHTML = '<option value="">-- Select Repair --</option>';
          (services || []).forEach(s => { sS.innerHTML += `<option value="${s.service_id}">${s.service_name}</option>`; });
        }
        if (mS) {
          mS.innerHTML = '<option value="">-- Auto-Assign --</option>';
          (res.mechanics || []).forEach(m => {
            const shift = (m.shift_start && m.shift_end)
              ? ` — ${new Date('1970-01-01T'+m.shift_start).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})} – ${new Date('1970-01-01T'+m.shift_end).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}${m.shift_days ? ' | '+m.shift_days.replace(/,/g,'·') : ''}` : '';
            mS.innerHTML += `<option value="${m.mechanic_id}">${m.full_name}${shift}</option>`;
          });
        }
      });
    };

    window.processBayAssignment = function () {
      const form = document.getElementById('assignBayForm');
      if (!form) return;

      const fd = new FormData(form);
      const btn = form.querySelector('button[onclick*="processBayAssignment"]');
      const originalHtml = btn ? btn.innerHTML : 'Start Walk-in Repair';

      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Initializing...';
      }

      fetch('tenant-dashboard.php?action=assign_bay_job', {
        method: 'POST',
        body: fd
      }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
          showToast(data.message);
          closeModal('assignBayModal');
          if (window.refreshBaysList) window.refreshBaysList();
          if (window.refreshJobOrders) window.refreshJobOrders();
          if (window.dashboardOverviewRefresh) window.dashboardOverviewRefresh();
          // Reload if critical
          if (data.reload) location.reload();
        } else {
          alert(data.message || "Failed to assign bay.");
        }
      }).catch(err => {
        console.error("Assignment Error:", err);
        alert("Connection Error. Please check network.");
      }).finally(() => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalHtml;
        }
      });
    };

    window.toggleQuickRegister = function () {
      const qGroup = document.getElementById('quickRegisterGroup');
      const eGroup = document.getElementById('existingVehicleGroup');
      const btn = document.getElementById('quickRegBtn');
      const vSelect = document.getElementById('assign_vehicle_id');

      if (qGroup.style.display === 'none') {
        qGroup.style.display = 'flex';
        eGroup.style.display = 'none';
        btn.innerText = '← Select Existing';
        btn.style.color = '#94a3b8';
        if (vSelect) vSelect.value = '';
      } else {
        qGroup.style.display = 'none';
        eGroup.style.display = 'flex';
        btn.innerText = '+ Register New';
        btn.style.color = 'var(--accent)';
      }
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
      [].forEach(fid => {
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
    window.currentUserRole = '<?php echo $role; ?>';
    // FORCING SIDEBAR TO THE VISUAL FRONT
    window.addEventListener('load', () => {
      const sb = document.querySelector('.sidebar');
      if (sb) {
        sb.style.zIndex = '2147483647';
        sb.style.pointerEvents = 'auto';
        sb.style.display = 'flex';
        console.log("[SYSTEM] Navigation Sidebar Locked to Top Stack.");
      }
    });

    // Navigation engine moved to top for reliability


    window.toggleSidebar = function () {
      document.body.classList.toggle('sidebar-collapsed');
      const icon = document.querySelector('#sidebarToggle i');
      if (icon) {
        if (document.body.classList.contains('sidebar-collapsed')) {
          icon.className = 'fas fa-chevron-right';
        } else {
          icon.className = 'fas fa-chevron-left';
        }
      }
      localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed'));
    };

    window.toggleTheme = function () {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const target = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', target);
      localStorage.setItem('tenant_theme', target);
      updateThemeUI();
    };

    function updateThemeUI() {
      const theme = document.documentElement.getAttribute('data-theme') || 'dark';
      const btn = document.getElementById('themeToggle');
      if (btn) {
        const icon = btn.querySelector('i');
        const label = btn.querySelector('.nav-label');
        if (theme === 'light') {
          icon.className = 'fas fa-sun';
          label.innerText = 'Light Mode';
        } else {
          icon.className = 'fas fa-moon';
          label.innerText = 'Dark Mode';
        }
      }
    }

    // Initialize Theme
    const savedTheme = localStorage.getItem('tenant_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    document.addEventListener('DOMContentLoaded', updateThemeUI);

    // Initialize View on Load
    document.addEventListener('DOMContentLoaded', () => {
      // Default view logic
      const activeSection = document.querySelector('.view-section.active');
      if (!activeSection) {
        const dash = document.getElementById('dashboard');
        if (dash) dash.classList.add('active');
      }

      // Ensure sidebar is visible on load for large screens
      if (window.innerWidth > 1024) {
        document.body.classList.remove('sidebar-collapsed');
      } else {
        document.body.classList.add('sidebar-collapsed');
      }
    });

    window.dashboardOverviewRefresh = function () {
      if (typeof window.refreshOverviewStats === 'function') window.refreshOverviewStats();
      if (typeof window.refreshDashboardJobs === 'function') window.refreshDashboardJobs();
    };

    window.refreshOverviewStats = function () {
      fetch('tenant-dashboard.php?action=fetch_overview_stats')
        .then(r => r.json())
        .then(data => {
          if (data.error) return;
          const elBays = document.getElementById('stat-avail-bays');
          const elJobs = document.getElementById('stat-pending-jobs');
          const elRev = document.getElementById('stat-revenue');
          const elUnpaid = document.getElementById('stat-pending-payments');
          const elApp = document.getElementById('stat-appointments-today');

          if (elBays) elBays.innerHTML = `${data.avail_bays} <i class="fas fa-warehouse" style="color:var(--accent); font-size:1.4rem;"></i>`;
          if (elJobs) elJobs.innerHTML = `${data.pending_jobs} <i class="fas fa-car-crash" style="color:var(--warning); font-size:1.4rem;"></i>`;
          if (elRev) elRev.innerHTML = `₱${parseFloat(data.revenue).toLocaleString(undefined, { minimumFractionDigits: 2 })} <i class="fas fa-coins" style="color:#fcd34d; font-size:1.4rem;"></i>`;
          if (elUnpaid) elUnpaid.innerHTML = `₱${parseFloat(data.unpaid_balance).toLocaleString(undefined, { minimumFractionDigits: 2 })} <i class="fas fa-file-invoice-dollar" style="color:var(--danger); font-size:1.4rem;"></i>`;
          if (elApp && data.appointments_today !== undefined) elApp.innerHTML = `${data.appointments_today} <i class="fas fa-calendar-check" style="color:#60a5fa; font-size:1.4rem;"></i>`;
        });
    };

    window.refreshDashboardJobs = function () {
      const body = document.getElementById('dashboardRepairJobsBody');
      if (!body) return;

      fetch('tenant-dashboard.php?action=fetch_dashboard_jobs_diagnostic')
        .then(r => r.json())
        .then(data => {
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-dim);">No active jobs found.</td></tr>';
            return;
          }

          body.innerHTML = data.map(j => {
            if (window.currentUserRole === 'CASHIER') {
              return `
                <tr class="hover-bright">
                  <td><strong>${j.plate_no}</strong></td>
                  <td>${j.make} ${j.model}<br><small style="opacity:0.5;">${j.customer_name}</small></td>
                  <td>${j.service_name}</td>
                  <td style="color:white; font-weight:700;">₱${parseFloat(j.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                  <td><span class="badge ${j.status === 'COMPLETED' ? 'badge-active' : (j.status === 'IN_PROGRESS' ? 'badge-info' : 'badge-pending')}">${j.status}</span></td>
                  <td>
                    <button class="btn-action" style="padding:5px 15px; font-size:0.75rem; background:var(--accent); color:white; border:none; border-radius:8px; cursor:pointer;" 
                      onclick="window.openRecordPaymentModal(${j.job_id}, '${j.customer_id}', '${j.customer_name}', ${j.total_amount})">
                      Collect
                    </button>
                  </td>
                </tr>
              `;
            } else {
              return `
                <tr class="hover-bright">
                  <td><strong>${j.plate_no}</strong></td>
                  <td>${j.make} ${j.model}<br><small style="opacity:0.5;">${j.customer_name}</small></td>
                  <td>${j.service_name}</td>
                  <td><small style="color:var(--accent);">${j.mechanic_name}</small></td>
                  <td><span class="badge ${j.status === 'COMPLETED' ? 'badge-active' : (j.status === 'IN_PROGRESS' ? 'badge-info' : 'badge-pending')}">${j.status}</span></td>
                  ${window.currentUserRole === 'MECHANIC' ? `<td><button class="btn-outline" onclick="window.handleJobClick(${j.job_id}, '${j.status}', ${j.mechanic_id}, ${j.bay_id})">Manage</button></td>` : ''}
                </tr>
              `;
            }
          }).join('');
        });
    };

    window.refreshSettledJobs = function () {
      const body = document.getElementById('settledJobsBody');
      if (!body) return;
      body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin"></i></td></tr>';

      fetch('tenant-dashboard.php?action=fetch_settlement_history')
        .then(r => r.json())
        .then(data => {
          if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-dim);">No history found.</td></tr>';
            return;
          }
          body.innerHTML = data.map(j => {
            const statusClass = (j.status === 'COMPLETED' || j.status === 'SETTLED') ? 'badge-active' : (j.status === 'CANCELLED' ? 'badge-danger' : 'badge-pending');
            const displayModel = (j.make || j.model) ? `${j.make} ${j.model}`.trim() : 'Manual Entry';
            return `
              <tr class="hover-bright">
                <td style="padding: 1.2rem 1rem;"><span style="background:var(--glass); padding:6px 12px; border-radius:8px; border:1px solid var(--glass-border); font-family:monospace; color:var(--accent); font-weight:800; font-size:0.85rem; letter-spacing:1px;">${j.plate_no}</span></td>
                <td style="padding: 1.2rem 1rem;"><div style="font-weight:700; font-size:0.95rem; margin-bottom:4px; color:var(--text-main);">${displayModel}</div><div style="opacity:0.6; font-size:0.75rem; display:flex; align-items:center; gap:5px;"><i class="fas fa-user-circle"></i> ${j.customer_name}</div></td>
                <td style="padding: 1.2rem 1rem;"><span style="color:var(--text-dim); font-size:0.85rem; background:var(--glass); padding:4px 8px; border-radius:6px;">${j.service_name}</span></td>
                <td style="padding: 1.2rem 1rem;"><small style="color:var(--text-dim);"><i class="far fa-clock"></i> ${new Date(j.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</small></td>
                <td style="padding: 1.2rem 1rem; font-weight:800; color:var(--text-main); font-size:1.1rem;">₱${parseFloat(j.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                <td style="padding: 1.2rem 1rem;"><span class="badge ${statusClass}" style="padding: 6px 12px; font-size: 0.7rem; font-weight: 800;">${j.status}</span></td>
                <td style="padding: 1.2rem 1rem;">
                  <button class="btn-action" style="padding:8px 16px; font-size:0.75rem; background:rgba(var(--accent-rgb), 0.1); color:var(--accent); border:1px solid rgba(var(--accent-rgb), 0.2); border-radius:10px; cursor:pointer; font-weight:700; transition:0.3s;" 
                    onmouseover="this.style.background='var(--accent)'; this.style.color='white';" 
                    onmouseout="this.style.background='rgba(var(--accent-rgb), 0.1)'; this.style.color='var(--accent)';"
                    onclick="window.viewJobReceipt(${j.job_id})">
                    <i class="fas fa-file-invoice"></i> Receipt
                  </button>
                </td>
              </tr>
            `;
          }).join('');
        });
    };


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
                <div style="background:var(--input-bg); border:1px solid var(--glass-border); padding:1rem; border-radius:15px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-weight:800; color:var(--text-main); font-size:1.1rem;">${v.plate_no}</div>
                    <div style="font-size:0.85rem; color:var(--text-dim); opacity:0.8;">${v.make} ${v.model} (${v.year || ''})</div>
                  </div>
                  <button class="btn-outline" style="padding:4px 10px; font-size:0.7rem;" onclick="window.openVehicleProfile(${v.vehicle_id})">View History</button>
                </div>
              `).join('') : '<div style="text-align:center; padding:1rem; opacity:0.5; border:1px dashed rgba(255,255,255,0.1); border-radius:10px;">No vehicles found</div>';

          const apptsHtml = data.appointments.length ? data.appointments.map(a => `
                <div style="padding:1rem; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-size:0.95rem; font-weight:700; color:var(--text-main);">${a.service_name || 'Repair Job'}</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">${new Date(a.appointment_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                  </div>
                  <span class="badge badge-active" style="font-size:0.7rem;">${a.status}</span>
                </div>
              `).join('') : '<div style="text-align:center; padding:2rem; opacity:0.5;">No service history records.</div>';

          body.innerHTML = `
                <div style="display:grid; grid-template-columns:320px 1fr; gap:2.5rem;">
                  <div style="background:var(--input-bg); padding:2rem; border-radius:20px; border:1px solid var(--glass-border);">
                    <div style="width:100px; height:100px; border-radius:25px; background:var(--accent); margin:0 auto 1.5rem; display:flex; align-items:center; justify-content:center; font-size:3rem; font-weight:900; color:white; box-shadow:0 10px 20px rgba(0,0,0,0.1);">
                      ${c.full_name.charAt(0).toUpperCase()}
                    </div>
                    <h3 style="text-align:center; margin-bottom:0.5rem; font-size:1.5rem; color:var(--text-main);">${c.full_name}</h3>
                    <div style="text-align:center; margin-bottom:2rem;"><span class="badge badge-active">LIFETIME CUSTOMER</span></div>
                    
                    <div style="display:flex; flex-direction:column; gap:15px;">
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-envelope" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem; color:var(--text-main);">${c.email || 'No email set'}</span></div>
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-phone" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.9rem; font-weight:700; color:var(--text-main);">${c.mobile}</span></div>
                      <div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-map-marker-alt" style="width:20px; color:var(--accent);"></i> <span style="font-size:0.85rem; opacity:0.8; color:var(--text-main);">${c.address || 'Address not provided'}</span></div>
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
                    <div style="background:var(--input-bg); border-radius:20px; border:1px solid var(--glass-border); min-height:400px; max-height:500px; overflow-y:auto;">
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
                <div style="padding:1rem; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <div style="font-size:0.95rem; font-weight:700; color:var(--text-main);">${h.service_name || 'Repair Job'}</div>
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
      window.openModal('bayProfileModal');

      fetch(`tenant-dashboard.php?action=fetch_bay_details&id=${bayId}`)
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
                <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:1.5rem; border-radius:20px; margin-bottom:2rem; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
                  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <span class="badge badge-active">ACTIVE REPAIR</span>
                    <span style="font-size:0.8rem; font-weight:700; color:var(--accent);">JO-${current.job_id.toString().padStart(4, '0')}</span>
                  </div>
                  <h4 style="margin:0; font-size:1.2rem;">${current.service_name}</h4>
                  <div style="margin-top:10px; font-size:0.9rem; opacity:0.8;">
                    <i class="fas fa-car" style="margin-right:8px;"></i> ${current.plate_no} ${ (current.make || current.model) ? `(${[current.make, current.model].filter(Boolean).join(' ')})` : '' }<br>
                    <i class="fas fa-user" style="margin-right:8px;"></i> ${current.customer_name}
                  </div>
                  <button class="job-status-btn" onclick="window.closeModal('bayProfileModal'); window.handleJobClick(${current.job_id}, '${current.status}', ${current.mechanic_id}, ${b.bay_id}, true, false)" 
                      data-jid="${current.job_id}" data-status="${current.status}" data-mid="${current.mechanic_id}" data-bid="${b.bay_id}" data-edit="true" data-focus="false"
                      style="width:100%; margin-top:1.5rem; background:var(--accent); color:white; border:none; padding:0.8rem; border-radius:12px; font-weight:700; cursor:pointer;">
                    Control Repair Stream
                  </button>
                </div>
              ` : `
                <div style="background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.1); padding:1.5rem; border-radius:20px; text-align:center; margin-bottom:2rem;">
                  <i class="fas fa-check-circle" style="font-size:2rem; color:var(--success); opacity:0.5; margin-bottom:10px;"></i>
                  <h4 style="margin:0; color:var(--success);">Bay is Ready</h4>
                  <p style="font-size:0.8rem; color:var(--text-dim); margin-top:5px;">Currently optimal for new assignments.</p>
                  <button onclick="window.closeModal('bayProfileModal'); window.openAssignBayModal(${b.bay_id}, '${b.bay_name}')" 
                      style="width:100%; margin-top:1.2rem; background:var(--accent); color:white; border:none; padding:0.9rem; border-radius:12px; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; transition:0.3s;">
                    <i class="fas fa-sign-in-alt"></i> Process Walk-in Check-in
                  </button>
                </div>
              `;

          body.innerHTML = `
                <div style="display:grid; grid-template-columns:260px 1fr; gap:2rem;">
                    <div style="background:rgba(255,255,255,0.02); padding:1.5rem; border-radius:24px; border:1px solid rgba(255,255,255,0.05); height:fit-content;">
                      <div style="width:70px; height:70px; border-radius:18px; background:var(--accent); margin:0 auto 1.2rem; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:900; color:white; box-shadow:0 10px 20px rgba(0,0,0,0.3);">
                        ${b.bay_name.charAt(0).toUpperCase()}
                      </div>
                      <h3 style="text-align:center; margin-bottom:0.5rem; font-size:1.3rem;">${b.bay_name}</h3>
                      <div style="text-align:center; margin-bottom:1.5rem;"><span class="badge ${isAvail ? 'badge-active' : ''}" style="padding:6px 12px;">${b.status}</span></div>
                      
                      <div style="display:flex; flex-direction:column; gap:12px; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.05);">
                        <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem; opacity:0.8;"><i class="fas fa-tools" style="width:16px; color:var(--accent);"></i> <span>Service Ready</span></div>
                        <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem; opacity:0.8;"><i class="fas fa-clock" style="width:16px; color:var(--accent);"></i> <span>Live Monitoring</span></div>
                      </div>
                    </div>
                    <div style="display:flex; flex-direction:column;">
                      <div style="margin-bottom:1.5rem;">
                        <h4 style="font-size:0.75rem; text-transform:uppercase; letter-spacing:1.5px; color:var(--accent); margin-bottom:1rem; font-weight:800;">Operational Status</h4>
                        ${currentJobHtml}
                      </div>
                      
                      <div style="flex:1;">
                        <h4 style="font-size:0.75rem; text-transform:uppercase; letter-spacing:1.5px; color:var(--text-dim); margin-bottom:1rem; font-weight:800;">Utilization History</h4>
                        <div style="background:rgba(255,255,255,0.01); border-radius:20px; border:1px solid rgba(255,255,255,0.05); overflow:hidden;">
                          ${historyHtml}
                        </div>
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



    window.toggleWalkInField = function (val) {
      const field = document.getElementById('walkinField');
      if (field) field.style.display = (val === 'WALKIN') ? 'block' : 'none';
    };

    // Execute immediate refresh check
    setTimeout(() => {
      if (typeof window.refreshBaysList === 'function') window.refreshBaysList();
    }, 1000);
  </script>
  </div>
  </div>

  <!-- Chat Support Centered Modal -->
  <div id="chatOverlay" onclick="toggleChat()" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:9998; transition:opacity 0.3s ease;">
  </div>
  <div id="supportChatWidget" style="position:fixed; top:50%; left:50%; width:90%; max-width:650px; height:80vh; max-height:680px; z-index:9999; background:rgba(18,18,24,0.92); backdrop-filter:blur(25px); -webkit-backdrop-filter:blur(25px); border:1px solid var(--glass-border); border-radius:24px; box-shadow:0 25px 60px rgba(0,0,0,0.65); display:flex; flex-direction:column; transform:translate(-50%, -50%) scale(0.92); opacity:0; pointer-events:none; visibility:hidden; transition:all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
    <style>
      :root {
        --accent: #6366f1;
        --bg-dark: rgba(0,0,0,0.4);
        --glass-bg: rgba(255,255,255,0.08);
        --glass-border: rgba(255,255,255,0.12);
        --text-primary: #fff;
        --text-dim: #cbd5e1;
      }
      .chat-header {
        background: var(--accent);
        padding: 1.6rem 2rem;
        color: var(--text-primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top-left-radius: 24px;
        border-top-right-radius: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      }
      .chat-header h4 {
        margin:0; font-size:1.15rem; font-weight:800; display:flex; align-items:center; gap:10px;
      }
      .chat-header span {
        font-size:0.78rem; opacity:0.85; letter-spacing:0.5px;
      }
      .chat-close {
        width:38px; height:38px; border-radius:50%;
        background:rgba(255,255,255,0.12);
        display:flex; align-items:center; justify-content:center;
        cursor:pointer; transition:background 0.2s;
      }
      .chat-close:hover {background:rgba(255,255,255,0.25);}
      .chat-messages {
        flex:1; padding:1.6rem; overflow-y:auto;
        display:flex; flex-direction:column; gap:14px;
        background:rgba(0,0,0,0.15);
      }
      .chat-msg {
        display:flex; align-items:flex-start; gap:12px; animation:fadeIn 0.3s ease;
      }
      .chat-msg.me {flex-direction:row-reverse;}
      .avatar {
        width:36px; height:36px; border-radius:50%;
        overflow:hidden; box-shadow:0 3px 10px rgba(0,0,0,0.25);
        background:var(--glass-bg);
        flex-shrink:0; display:flex; align-items:center; justify-content:center;
      }
      .avatar img {width:100%; height:100%; object-fit:cover;}
      .msg-bubble {
        max-width:70%; padding:0.85rem 1.2rem;
        border-radius:16px; background:var(--glass-bg);
        color:var(--text-primary); font-size:0.92rem;
        box-shadow:inset 0 2px 8px rgba(0,0,0,0.2);
      }
      .msg-bubble.me {background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff;}
      .chat-input {
        padding:1.5rem 1.9rem; border-top:1px solid var(--glass-border);
        display:flex; align-items:center; gap:14px; background:rgba(0,0,0,0.1);
        border-bottom-left-radius:24px; border-bottom-right-radius:24px;
      }
      #chatInput {
        flex:1; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border);
        border-radius:14px; padding:0.85rem 1.2rem; color:var(--text-primary);
        font-size:0.92rem; outline:none; resize:none; min-height:46px; max-height:120px;
        transition:0.2s; box-shadow:inset 0 2px 8px rgba(0,0,0,0.2);
      }
      .send-btn {
        background:var(--accent); border:none; width:46px; height:46px; border-radius:14px;
        color:#fff; cursor:pointer; transition:transform 0.2s ease-out; display:flex; align-items:center; justify-content:center;
        box-shadow:0 4px 15px rgba(0,0,0,0.2);
      }
      .send-btn:hover {transform:scale(1.08);}
      @keyframes fadeIn {0%{opacity:0;transform:translateY(6px);}100%{opacity:1;transform:translateY(0);}}
    </style>
    <!-- Header -->
    <div class="chat-header">
      <div>
        <h4><i class="fas fa-headset"></i>Support Center</h4>
        <span>AutoFix Hub Platform Assistance</span>
      </div>
      <div class="chat-close" onclick="toggleChat()"><i class="fas fa-times" style="font-size:1.1rem; color:white;"></i></div>
    </div>
    <!-- Messages -->
    <div id="chatMessages" class="chat-messages">
      <div class="msg-placeholder" style="text-align:center; padding:2rem; color:var(--text-dim); font-size:0.8rem;">
        How can we help you today?
      </div>
    </div>
    <!-- Input -->
    <div class="chat-input">
      <textarea id="chatInput" placeholder="Type your message here..." rows="1"></textarea>
      <button class="send-btn" onclick="sendMessage()"><i class="fas fa-paper-plane" style="font-size:1.1rem;"></i></button>
    </div>
    <!-- Hidden badge for JS compatibility -->
    <span id="tenantChatBadge" style="display:none;">0</span>
  </div>

  <script>
    let chatOpen = false;
    let lastMsgCount = 0;

    function toggleChat() {
      chatOpen = !chatOpen;
      const panel = document.getElementById('supportChatWidget');
      const overlay = document.getElementById('chatOverlay');
      
      if (chatOpen) {
        overlay.style.display = 'block';
        setTimeout(() => {
          overlay.style.opacity = '1';
          panel.style.opacity = '1';
          panel.style.visibility = 'visible';
          panel.style.pointerEvents = 'auto';
          panel.style.transform = 'translate(-50%, -50%) scale(1)';
        }, 10);
        
        fetch('tenant-dashboard.php?action=mark_support_read'); // Mark as read on open
        document.getElementById('tenantChatBadge').style.display = 'none';
        const sBadge = document.getElementById('sidebarChatBadge');
        if (sBadge) sBadge.style.display = 'none';
        fetchMessages();
        setTimeout(() => {
          const box = document.getElementById('chatMessages');
          box.scrollTop = box.scrollHeight;
          document.getElementById('chatInput').focus();
        }, 100);
      } else {
        panel.style.opacity = '0';
        panel.style.visibility = 'hidden';
        panel.style.pointerEvents = 'none';
        panel.style.transform = 'translate(-50%, -50%) scale(0.92)';
        overlay.style.opacity = '0';
        setTimeout(() => {
          overlay.style.display = 'none';
        }, 300);
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
            const sBadge = document.getElementById('sidebarChatBadge');
            if (!chatOpen && newCount > 0) {
              badge.innerText = newCount;
              badge.style.display = 'flex';
              if (sBadge) { sBadge.innerText = newCount; sBadge.style.display = 'inline-flex'; }
            } else if (chatOpen) {
              badge.style.display = 'none';
              if (sBadge) sBadge.style.display = 'none';
            }

            renderMessages(data.messages);
          }
        });
    }

    // Background polling every 10 seconds for notifications
    setInterval(fetchMessages, 10000);

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

    function renderMessages(msgs) {
      const box = document.getElementById('chatMessages');
      const wasAtBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 10;

      box.innerHTML = msgs.length === 0 ? '<div style="text-align:center; padding:2rem; color:var(--text-dim); font-size:0.8rem;">How can we help you today?</div>' : '';

      msgs.forEach((m, idx) => {
        const isMe = m.sender_role === 'TENANT';
        const isRobot = !isMe && m.message.includes('[Auto-Reply]');
        
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
              divider.style.margin = '20px 0';
              divider.style.color = 'rgba(255,255,255,0.25)';
              divider.style.fontSize = '0.72rem';
              divider.innerHTML = `
                <div style="flex:1; height:1px; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);"></div>
                <span style="padding:0 12px; font-weight:600; letter-spacing:0.5px; color:rgba(255,255,255,0.45);">${formatChatTime(m.created_at)}</span>
                <div style="flex:1; height:1px; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);"></div>
              `;
              box.appendChild(divider);
            }
          }
        }

        const row = document.createElement('div');
        row.style.display = 'flex';
        row.style.flexDirection = isMe ? 'row-reverse' : 'row';
        row.style.alignItems = 'flex-start';
        row.style.gap = '10px';
        row.style.marginBottom = '15px';
        row.style.width = '100%';

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
        
        if (isMe) {
          const myPfp = m.sender_avatar || m.logo_url;
          if (myPfp) {
            avatar.style.background = 'transparent';
            avatar.innerHTML = `<img src="${myPfp}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
          } else {
            avatar.style.background = 'linear-gradient(135deg, #3b82f6, #1d4ed8)';
            avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v-2"></path><circle cx="12" cy="7" r="4"></circle></svg>`;
          }
        } else if (isRobot) {
          avatar.style.background = 'linear-gradient(135deg, #10b981, #047857)';
          avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4M8 16h.01M16 16h.01"></path></svg>`;
        } else {
          const adminPfp = m.sender_avatar;
          if (adminPfp) {
            avatar.style.background = 'transparent';
            avatar.innerHTML = `<img src="${adminPfp}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
          } else {
            avatar.style.background = 'linear-gradient(135deg, #6366f1, #4f46e5)';
            avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>`;
          }
        }

        const contentContainer = document.createElement('div');
        contentContainer.style.display = 'flex';
        contentContainer.style.flexDirection = 'column';
        contentContainer.style.alignItems = isMe ? 'flex-end' : 'flex-start';
        contentContainer.style.maxWidth = '75%';

        const bubble = document.createElement('div');
        bubble.style.padding = '10px 14px';
        bubble.style.borderRadius = isMe ? '16px 16px 0 16px' : '16px 16px 16px 0';
        bubble.style.background = isMe ? 'var(--accent)' : 'rgba(255,255,255,0.08)';
        bubble.style.color = 'white';
        bubble.style.fontSize = '0.85rem';
        bubble.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
        bubble.style.lineHeight = '1.4';
        bubble.innerText = isRobot ? m.message.replace(/🤖\s*\[Auto-Reply\]\s*/i, '') : m.message;

        const timeVal = formatChatTime(m.created_at);
        const timeSpan = document.createElement('span');
        timeSpan.style.fontSize = '0.7rem';
        timeSpan.style.color = 'rgba(255,255,255,0.4)';
        timeSpan.style.marginTop = '4px';
        timeSpan.style.padding = '0 4px';
        timeSpan.innerText = isRobot ? `🤖 Auto-Reply • ${timeVal}` : timeVal;

        contentContainer.appendChild(bubble);
        contentContainer.appendChild(timeSpan);
        row.appendChild(avatar);
        row.appendChild(contentContainer);
        box.appendChild(row);
      });

      if (wasAtBottom) box.scrollTop = box.scrollHeight;
    }

    // Inject typing indicator CSS
    if (!document.getElementById('typingIndicatorStyles')) {
      const style = document.createElement('style');
      style.id = 'typingIndicatorStyles';
      style.innerHTML = `
        @keyframes typingBlink {
          0% { opacity: 0.2; }
          20% { opacity: 1; }
          100% { opacity: 0.2; }
        }
        .typing-dot {
          font-weight: bold;
          font-size: 1.2rem;
          line-height: 0.8;
        }
      `;
      document.head.appendChild(style);
    }

    function showTypingIndicator() {
      const box = document.getElementById('chatMessages');
      if (document.getElementById('chatTypingIndicator')) return;
      
      const row = document.createElement('div');
      row.id = 'chatTypingIndicator';
      row.style.display = 'flex';
      row.style.flexDirection = 'row';
      row.style.alignItems = 'flex-start';
      row.style.gap = '10px';
      row.style.marginBottom = '15px';
      row.style.width = '100%';

      const avatar = document.createElement('div');
      avatar.style.width = '32px';
      avatar.style.height = '32px';
      avatar.style.borderRadius = '50%';
      avatar.style.display = 'flex';
      avatar.style.alignItems = 'center';
      avatar.style.justifyContent = 'center';
      avatar.style.flexShrink = '0';
      avatar.style.background = 'linear-gradient(135deg, #6366f1, #4f46e5)';
      avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>`;

      const bubble = document.createElement('div');
      bubble.style.padding = '10px 14px';
      bubble.style.borderRadius = '16px 16px 16px 0';
      bubble.style.background = 'rgba(255,255,255,0.08)';
      bubble.style.color = 'rgba(255,255,255,0.6)';
      bubble.style.fontSize = '0.85rem';
      bubble.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
      bubble.style.display = 'flex';
      bubble.style.alignItems = 'center';
      bubble.style.gap = '5px';
      bubble.innerHTML = `
        <span>Support is typing</span>
        <span class="typing-dot" style="animation: typingBlink 1.4s infinite both; animation-delay: 0s;">.</span>
        <span class="typing-dot" style="animation: typingBlink 1.4s infinite both; animation-delay: 0.2s;">.</span>
        <span class="typing-dot" style="animation: typingBlink 1.4s infinite both; animation-delay: 0.4s;">.</span>
      `;

      row.appendChild(avatar);
      row.appendChild(bubble);
      box.appendChild(row);
      box.scrollTop = box.scrollHeight;
    }

    function removeTypingIndicator() {
      const el = document.getElementById('chatTypingIndicator');
      if (el) el.remove();
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

      // Optimistic Render: add tenant's message immediately
      const box = document.getElementById('chatMessages');
      
      const row = document.createElement('div');
      row.style.display = 'flex';
      row.style.flexDirection = 'row-reverse';
      row.style.alignItems = 'flex-start';
      row.style.gap = '10px';
      row.style.marginBottom = '15px';
      row.style.width = '100%';

      const avatar = document.createElement('div');
      avatar.style.width = '32px';
      avatar.style.height = '32px';
      avatar.style.borderRadius = '50%';
      avatar.style.display = 'flex';
      avatar.style.alignItems = 'center';
      avatar.style.justifyContent = 'center';
      avatar.style.flexShrink = '0';
      avatar.style.background = 'linear-gradient(135deg, #3b82f6, #1d4ed8)';
      avatar.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px; color:white;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v-2"></path><circle cx="12" cy="7" r="4"></circle></svg>`;

      const contentContainer = document.createElement('div');
      contentContainer.style.display = 'flex';
      contentContainer.style.flexDirection = 'column';
      contentContainer.style.alignItems = 'flex-end';
      contentContainer.style.maxWidth = '75%';

      const bubble = document.createElement('div');
      bubble.style.padding = '10px 14px';
      bubble.style.borderRadius = '16px 16px 0 16px';
      bubble.style.background = 'var(--accent)';
      bubble.style.color = 'white';
      bubble.style.fontSize = '0.85rem';
      bubble.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
      bubble.innerText = msg;

      const timeVal = formatChatTime(null);
      const timeSpan = document.createElement('span');
      timeSpan.style.fontSize = '0.7rem';
      timeSpan.style.color = 'rgba(255,255,255,0.4)';
      timeSpan.style.marginTop = '4px';
      timeSpan.style.padding = '0 4px';
      timeSpan.innerText = timeVal;

      contentContainer.appendChild(bubble);
      contentContainer.appendChild(timeSpan);
      row.appendChild(avatar);
      row.appendChild(contentContainer);

      if (box.innerHTML.includes('How can we help you today?')) {
        box.innerHTML = '';
      }
      
      box.appendChild(row);
      box.scrollTop = box.scrollHeight;

      input.value = '';
      input.style.height = '45px'; // Reset height
      
      fetch('tenant-dashboard.php?action=send_support_message', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            if (data.auto_reply) {
              setTimeout(() => {
                showTypingIndicator();
                setTimeout(() => {
                  removeTypingIndicator();
                  fetchMessages();
                }, 1500);
              }, 400);
            } else {
              fetchMessages();
            }
          } else {
            alert(data.message);
          }
        });
    }

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
    window.globalShopName = "<?php echo addslashes($shop_name ?? 'AutoFix Hub Shop'); ?>";
    window.currentReportType = null;
    window.currentReportData = null;

    window.exportReportPDF = function () {
      const type = window.currentReportType;
      const data = window.currentReportData;
      if (!data) {
        alert("No data available to export.");
        return;
      }

      const shopName = window.globalShopName || "AutoFix Hub Shop";

      if (typeof html2pdf === 'undefined') {
        const btn = document.getElementById('btnExportPDF');
        const origText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading PDF Engine...';
        btn.disabled = true;

        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        script.onload = () => {
          btn.innerHTML = origText;
          btn.disabled = false;
          runExport();
        };
        script.onerror = () => {
          btn.innerHTML = origText;
          btn.disabled = false;
          alert("Failed to load PDF engine. Please check your internet connection.");
        };
        document.head.appendChild(script);
      } else {
        runExport();
      }

      function runExport() {
        const printDiv = document.createElement('div');
        printDiv.style.padding = '40px';
        printDiv.style.fontFamily = "'Inter', sans-serif";
        printDiv.style.color = '#1e293b';
        printDiv.style.background = '#ffffff';

        let reportTitle = '';
        if (type === 'revenue') reportTitle = '7-Day Revenue Analytics';
        else if (type === 'performance') reportTitle = 'Service Performance Report';
        else if (type === 'inventory') reportTitle = 'Inventory Status & Stock Log';
        else if (type === 'mechanic') reportTitle = 'Mechanic Performance Report';

        let html = `
          <div style="border-bottom: 2px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
              <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; font-family:'Inter', sans-serif;">${reportTitle}</h1>
              <p style="margin: 5px 0 0 0; font-size: 14px; color: #64748b;">Business Intelligence & Performance Report</p>
            </div>
            <div style="text-align: right;">
              <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b; font-family:'Inter', sans-serif;">${shopName}</h3>
              <p style="margin: 5px 0 0 0; font-size: 12px; color: #64748b;">Generated: ${new Date().toLocaleString()}</p>
            </div>
          </div>
        `;

        if (type === 'revenue' && window.revenueChartInstance) {
          try {
            const canvas = document.createElement('canvas');
            canvas.width = 600;
            canvas.height = 250;
            canvas.style.display = 'none';
            document.body.appendChild(canvas);
            
            const tempCtx = canvas.getContext('2d');
            const tempChart = new Chart(tempCtx, {
              type: 'line',
              data: {
                labels: data.map(row => row.date),
                datasets: [{
                  label: 'Daily Revenue (₱)',
                  data: data.map(row => row.total),
                  borderColor: '#1d4ed8',
                  backgroundColor: 'rgba(29, 78, 216, 0.05)',
                  borderWidth: 3,
                  fill: true,
                  tension: 0.4,
                  pointBackgroundColor: '#1d4ed8',
                  pointRadius: 4
                }]
              },
              options: {
                responsive: false,
                plugins: { legend: { display: false } },
                scales: {
                  y: { grid: { color: '#f1f5f9' }, ticks: { color: '#475569' } },
                  x: { grid: { display: false }, ticks: { color: '#475569' } }
                }
              }
            });

            const chartImg = tempChart.toBase64Image();
            tempChart.destroy();
            canvas.remove();

            html += `
              <div style="margin-bottom: 30px; text-align: center;">
                <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 15px; text-align: left; font-family:'Inter', sans-serif;">Revenue Trend (Last 7 Days)</h3>
                <img src="${chartImg}" style="width: 100%; max-height: 250px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px;" />
              </div>
            `;
          } catch (e) {
            console.error("Failed to generate PDF chart:", e);
          }
        }

        if (type !== 'inventory') {
          html += `
            <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 15px; font-family:'Inter', sans-serif;">Report Data Table</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 13px; font-family:'Inter', sans-serif;">
              <thead>
                <tr style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
          `;

          if (type === 'revenue') {
            html += `
                  <th style="padding: 12px; color: #475569; font-weight: 700;">Date</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700; text-align: right;">Total Revenue</th>
                </tr>
              </thead>
              <tbody>
            `;
            let grandTotal = 0;
            data.forEach(row => {
              const val = parseFloat(row.total || 0);
              grandTotal += val;
              html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 12px; color: #334155;">${row.date}</td>
                  <td style="padding: 12px; color: #1e3a8a; font-weight: 700; text-align: right;">₱${val.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
              `;
            });
            html += `
                <tr style="background-color: #f8fafc; border-top: 2px solid #e2e8f0; font-weight: 800;">
                  <td style="padding: 12px; color: #1e293b;">Grand Total</td>
                  <td style="padding: 12px; color: #1e3a8a; text-align: right; font-size: 15px;">₱${grandTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
            `;
          } else if (type === 'performance') {
            html += `
                  <th style="padding: 12px; color: #475569; font-weight: 700;">Service Name</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700; text-align: right;">Total Jobs Completed</th>
                </tr>
              </thead>
              <tbody>
            `;
            data.forEach(row => {
              html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 12px; color: #334155;">${row.service_name || 'Unknown'}</td>
                  <td style="padding: 12px; color: #334155; font-weight: 700; text-align: right;">${row.count || 0} jobs</td>
                </tr>
              `;
            });
          } else if (type === 'mechanic') {
            html += `
                  <th style="padding: 12px; color: #475569; font-weight: 700; width: 60px;">Rank</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700;">Mechanic Name</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700;">Specialization</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700; text-align: right;">Completed Jobs</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700; text-align: right;">Revenue Generated</th>
                  <th style="padding: 12px; color: #475569; font-weight: 700; text-align: right;">Avg / Job</th>
                </tr>
              </thead>
              <tbody>
            `;
            let totalJobs = 0, totalRev = 0;
            data.forEach((row, index) => {
              const medal = index === 0 ? '🥇 ' : (index === 1 ? '🥈 ' : (index === 2 ? '🥉 ' : ''));
              const jobs = parseInt(row.count || 0);
              const rev = parseFloat(row.total_revenue || 0);
              const avg = parseFloat(row.avg_job_cost || 0);
              totalJobs += jobs;
              totalRev += rev;
              html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 12px; color: #64748b; font-weight: 600;">#${index + 1}</td>
                  <td style="padding: 12px; color: #334155; font-weight: 700;">${medal}${row.full_name}</td>
                  <td style="padding: 12px; color: #64748b; font-style: italic;">${row.specialization || 'General'}</td>
                  <td style="padding: 12px; color: #1e3a8a; font-weight: 700; text-align: right;">${jobs}</td>
                  <td style="padding: 12px; color: #166534; font-weight: 700; text-align: right;">₱${rev.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                  <td style="padding: 12px; color: #475569; text-align: right;">₱${avg.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
              `;
            });
            html += `
                <tr style="background-color: #f8fafc; border-top: 2px solid #e2e8f0; font-weight: 800;">
                  <td colspan="3" style="padding: 12px; color: #1e293b;">Team Totals</td>
                  <td style="padding: 12px; color: #1e3a8a; text-align: right;">${totalJobs} jobs</td>
                  <td style="padding: 12px; color: #166534; text-align: right;">₱${totalRev.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                  <td style="padding: 12px; color: #475569; text-align: right;">₱${totalJobs > 0 ? (totalRev/totalJobs).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) : '0.00'}</td>
                </tr>
            `;
          }
          html += `
              </tbody>
            </table>
          `;
        } else {
          const lowStock = data.low_stock || [];
          const history = data.history || [];

          html += `
            <h3 style="font-size: 14px; font-weight: 700; color: #dc2626; margin-bottom: 15px; font-family:'Inter', sans-serif;">Low Stock Alerts (Stock < 5)</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 13px; font-family:'Inter', sans-serif;">
              <thead>
                <tr style="background-color: #fef2f2; border-bottom: 2px solid #fca5a5; text-align: left;">
                  <th style="padding: 12px; color: #991b1b; font-weight: 700;">Item Name</th>
                  <th style="padding: 12px; color: #991b1b; font-weight: 700; text-align: right;">Remaining Qty</th>
                </tr>
              </thead>
              <tbody>
          `;

          if (lowStock.length === 0) {
            html += `<tr><td colspan="2" style="padding: 12px; color: #64748b; text-align: center;">All stock levels are healthy!</td></tr>`;
          } else {
            lowStock.forEach(row => {
              html += `
                <tr style="border-bottom: 1px solid #fee2e2;">
                  <td style="padding: 12px; color: #334155;">${row.item_name}</td>
                  <td style="padding: 12px; color: #b91c1c; font-weight: 700; text-align: right;">${row.quantity} units left</td>
                </tr>
              `;
            });
          }

          html += `
              </tbody>
            </table>

            <h3 style="font-size: 14px; font-weight: 700; color: #1e3a8a; margin-bottom: 15px; margin-top: 30px; font-family:'Inter', sans-serif;">Stock Movement Log (Additions & Subtractions)</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 13px; font-family:'Inter', sans-serif;">
              <thead>
                <tr style="background-color: #f0fdf4; border-bottom: 2px solid #bbf7d0; text-align: left;">
                  <th style="padding: 12px; color: #166534; font-weight: 700;">Date</th>
                  <th style="padding: 12px; color: #166534; font-weight: 700;">Item Name</th>
                  <th style="padding: 12px; color: #166534; font-weight: 700;">Action</th>
                  <th style="padding: 12px; color: #166534; font-weight: 700; text-align: right;">New Stock Level</th>
                </tr>
              </thead>
              <tbody>
          `;

          if (history.length === 0) {
            html += `<tr><td colspan="4" style="padding: 12px; color: #64748b; text-align: center;">No stock movements recorded yet.</td></tr>`;
          } else {
            history.forEach(row => {
              const actionSign = row.transaction_type === 'ADD' ? '+' : '-';
              const actionColor = row.transaction_type === 'ADD' ? '#16a34a' : '#dc2626';
              const actionText = row.transaction_type === 'ADD' ? 'Added' : 'Subtracted';

              html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 12px; color: #64748b; font-size: 12px;">${row.date}</td>
                  <td style="padding: 12px; color: #334155;">${row.item_name}</td>
                  <td style="padding: 12px; color: ${actionColor}; font-weight: 700;">
                    ${actionSign}${row.quantity_changed} (${row.notes || actionText})
                  </td>
                  <td style="padding: 12px; color: #334155; font-weight: 600; text-align: right;">${row.new_quantity}</td>
                </tr>
              `;
            });
          }

          html += `
              </tbody>
            </table>
          `;
        }

        html += `
          <div style="border-top: 1px solid #e2e8f0; padding-top: 15px; margin-top: 40px; text-align: center; font-size: 11px; color: #94a3b8;">
            This document is an official system-generated business intelligence report for ${shopName}. All figures are audited and live.
          </div>
        `;

        printDiv.innerHTML = html;

        const opt = {
          margin:       0.5,
          filename:     `${reportTitle.toLowerCase().replace(/[^a-z0-9]/g, '_')}_${new Date().toISOString().slice(0,10)}.pdf`,
          image:        { type: 'jpeg', quality: 0.98 },
          html2canvas:  { scale: 2, useCORS: true },
          jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(printDiv).save().then(() => {
          printDiv.remove();
        });
      }
    };

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
      if (type === 'inventory') { action = 'get_inventory_report'; titleEl.innerText = 'Inventory Status & Stock Log'; }
      if (type === 'mechanic') { action = 'get_mechanic_performance'; titleEl.innerText = 'Mechanic Performance Report'; }

      fetch('tenant-dashboard.php?action=' + action)
        .then(res => res.json())
        .then(data => {
          const hasData = (type === 'inventory') 
            ? (data.low_stock && data.low_stock.length > 0 || data.history && data.history.length > 0)
            : (Array.isArray(data) && data.length > 0);

          if (!hasData) {
            contentEl.innerHTML = '<p style="text-align:center; padding:3rem; color:var(--text-dim);">No data available for this report.</p>';
            return;
          }

          // Cache report info for PDF export
          window.currentReportType = type;
          window.currentReportData = data;

          if (type === 'revenue') {
            chartContainer.style.display = 'block';
            const ctx = document.getElementById('revenueChart').getContext('2d');

            if (typeof Chart !== 'undefined') {
              const theme = document.documentElement.getAttribute('data-theme') || 'dark';
              const gridColor = theme === 'light' ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
              const textColor = theme === 'light' ? '#64748b' : 'rgba(255,255,255,0.5)';

              window.revenueChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                  labels: data.map(row => row.date),
                  datasets: [{
                    label: 'Daily Revenue (₱)',
                    data: data.map(row => row.total),
                    borderColor: 'var(--accent)',
                    backgroundColor: 'rgba(var(--accent-rgb), 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'var(--accent)',
                    pointRadius: 5
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: {
                    y: { grid: { color: gridColor }, ticks: { color: textColor } },
                    x: { grid: { display: false }, ticks: { color: textColor } }
                  }
                }
              });
            } else {
              console.error("Chart.js is not loaded!");
            }
          }

          let html = '';
          if (type === 'revenue') {
            html += '<table class="data-table"><thead><tr><th>Date</th><th>Total Revenue</th></tr></thead><tbody>';
            data.forEach(row => {
              html += `<tr><td>${row.date}</td><td style="font-weight:800; color:var(--accent);">₱${parseFloat(row.total || 0).toLocaleString()}</td></tr>`;
            });
            html += '</tbody></table>';
          } else if (type === 'performance') {
            html += '<table class="data-table"><thead><tr><th>Service Name</th><th>Total Jobs</th></tr></thead><tbody>';
            data.forEach(row => {
              html += `<tr><td>${row.service_name || 'Unknown'}</td><td>${row.count || 0} jobs</td></tr>`;
            });
            html += '</tbody></table>';
          } else if (type === 'mechanic') {
            // Summary stat cards
            let totalJobs = 0, totalRev = 0;
            data.forEach(r => { totalJobs += parseInt(r.count || 0); totalRev += parseFloat(r.total_revenue || 0); });
            const topMech = data.length > 0 ? data[0].full_name : 'N/A';

            html = `
              <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem;">
                <div style="background:rgba(var(--accent-rgb),0.08); border:1px solid rgba(var(--accent-rgb),0.2); border-radius:16px; padding:1.2rem; text-align:center;">
                  <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--text-dim); margin-bottom:6px;">Top Mechanic</div>
                  <div style="font-size:1.3rem; font-weight:900; color:var(--accent);">🥇 ${topMech}</div>
                </div>
                <div style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.2); border-radius:16px; padding:1.2rem; text-align:center;">
                  <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--text-dim); margin-bottom:6px;">Total Jobs Done</div>
                  <div style="font-size:1.3rem; font-weight:900; color:#10b981;">${totalJobs}</div>
                </div>
                <div style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:16px; padding:1.2rem; text-align:center;">
                  <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--text-dim); margin-bottom:6px;">Total Revenue</div>
                  <div style="font-size:1.3rem; font-weight:900; color:#3b82f6;">₱${totalRev.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
                </div>
              </div>

              <h4 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: var(--accent); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-trophy"></i> Performance Leaderboard
              </h4>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Rank</th>
                    <th>Mechanic</th>
                    <th>Specialization</th>
                    <th style="text-align: right;">Jobs</th>
                    <th style="text-align: right;">Revenue</th>
                    <th style="text-align: right;">Avg/Job</th>
                  </tr>
                </thead>
                <tbody>
            `;
            data.forEach((row, index) => {
              const medal = index === 0 ? '🥇 ' : (index === 1 ? '🥈 ' : (index === 2 ? '🥉 ' : ''));
              const jobs = parseInt(row.count || 0);
              const rev = parseFloat(row.total_revenue || 0);
              const avg = parseFloat(row.avg_job_cost || 0);
              const rowBg = index < 3 ? 'background:rgba(var(--accent-rgb),0.03);' : '';
              html += `
                <tr style="${rowBg}">
                  <td style="font-weight:700; color:var(--text-dim);">#${index + 1}</td>
                  <td><strong>${medal}${row.full_name}</strong></td>
                  <td style="opacity:0.7; font-style:italic;">${row.specialization || 'General'}</td>
                  <td style="color:var(--accent); font-weight:800; text-align:right;">${jobs}</td>
                  <td style="color:#10b981; font-weight:700; text-align:right;">₱${rev.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                  <td style="color:var(--text-dim); text-align:right;">₱${avg.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
              `;
            });
            html += '</tbody></table>';
          } else if (type === 'inventory') {
            const lowStock = data.low_stock || [];
            const history = data.history || [];

            html = `
              <div style="margin-bottom: 2rem;">
                <h4 style="margin: 0 0 1rem 0; font-size: 1.1rem; color: #f87171; display: flex; align-items: center; gap: 8px;">
                  <i class="fas fa-exclamation-triangle"></i> Low Stock Alerts (Stock < 5)
                </h4>
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Item Name</th>
                      <th style="text-align: right;">Remaining Qty</th>
                    </tr>
                  </thead>
                  <tbody>
            `;

            if (lowStock.length === 0) {
              html += `<tr><td colspan="2" style="text-align:center; opacity:0.6; padding:1.5rem;">All stock levels are healthy!</td></tr>`;
            } else {
              lowStock.forEach(row => {
                html += `<tr><td>${row.item_name}</td><td style="color:var(--danger); font-weight:700; text-align: right;">${row.quantity} units left</td></tr>`;
              });
            }

            html += `
                  </tbody>
                </table>
              </div>

              <div>
                <h4 style="margin: 2.5rem 0 1rem 0; font-size: 1.1rem; color: var(--accent); display: flex; align-items: center; gap: 8px;">
                  <i class="fas fa-history"></i> Stock Movement Log
                </h4>
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Item Name</th>
                      <th>Action</th>
                      <th style="text-align: right;">New Stock</th>
                    </tr>
                  </thead>
                  <tbody>
            `;

            if (history.length === 0) {
              html += `<tr><td colspan="4" style="text-align:center; opacity:0.6; padding:1.5rem;">No stock movements recorded yet.</td></tr>`;
            } else {
              history.forEach(row => {
                const actionColor = row.transaction_type === 'ADD' ? '#10b981' : '#f87171';
                const actionSign = row.transaction_type === 'ADD' ? '+' : '-';
                const actionText = row.transaction_type === 'ADD' ? 'Added' : 'Subtracted';
                
                html += `
                  <tr>
                    <td style="font-size: 0.85rem; opacity: 0.7;">${row.date}</td>
                    <td>${row.item_name}</td>
                    <td style="color:${actionColor}; font-weight:700;">
                      ${actionSign}${row.quantity_changed} (${row.notes || actionText})
                    </td>
                    <td style="text-align: right; font-weight:600;">${row.new_quantity}</td>
                  </tr>
                `;
              });
            }

            html += `
                  </tbody>
                </table>
              </div>
            `;
          }
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
                        if (typeof window.renderStaffTable === 'function' && action === 'add_staff') renderStaffTable();
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

      // INITIAL DATA LOAD
    })();

    window.addEventListener('load', () => {
      console.log("[RUNTIME] Initializing Dashboard Modules...");
      const runSafe = (fnName) => {
        try {
          if (typeof window[fnName] === 'function') window[fnName]();
        } catch (e) {
          console.warn(`[MODULE_LOAD_FAIL] ${fnName}:`, e.message);
        }
      };

      runSafe('refreshShiftRequests');
      runSafe('refreshVehiclesList');
      runSafe('refreshAppointmentsList');
      runSafe('refreshDashboardJobs');
      runSafe('refreshServicesList');
      runSafe('refreshJobOrders');
      runSafe('refreshBaysList');
      runSafe('refreshSettledJobs');
      runSafe('refreshPaymentsList');
    });
  </script>
</body>

</html>