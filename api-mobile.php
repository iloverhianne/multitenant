<?php
// api-mobile.php - Android App REST API Endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow Android App to connect
header('Access-Control-Allow-Methods: GET, POST');

require_once 'db-config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$tenant_id = $_GET['tid'] ?? $_POST['tid'] ?? null;

// If action is login and we don't have a tid, it's okay (global login).
if (!$action) {
    echo json_encode(['status' => 'error', 'message' => 'Missing action parameter.']);
    exit;
}

try {
    $db = getDB();
    if ($db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // ------------------------------------------------------------------
    // ANDROID LOGIN: Authenticate Customer and return ID for session
    // ------------------------------------------------------------------
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mobile = $_POST['mobile'] ?? '';
        $email = $_POST['email'] ?? ''; 
        $password = $_POST['password'] ?? '';

        // Fetch all matching customers across all tenants
        if ($tenant_id) {
            $stmt = $db->prepare("SELECT c.customer_id, c.tenant_id, c.full_name, c.email, c.password_hash, t.shop_name 
                                  FROM customers c 
                                  JOIN tenants t ON c.tenant_id = t.tenant_id
                                  WHERE c.tenant_id = ? AND (c.mobile = ? OR c.email = ?) AND c.status = 'ACTIVE'");
            $stmt->execute([$tenant_id, $mobile, $email]);
        } else {
            $stmt = $db->prepare("SELECT c.customer_id, c.tenant_id, c.full_name, c.email, c.password_hash, t.shop_name 
                                  FROM customers c 
                                  JOIN tenants t ON c.tenant_id = t.tenant_id
                                  WHERE (c.mobile = ? OR c.email = ?) AND c.status = 'ACTIVE'");
            $stmt->execute([$mobile, $email]);
        }
        
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $valid_shops = [];

        foreach ($matches as $m) {
            if (password_verify($password, $m['password_hash'])) {
                $valid_shops[] = [
                    'tenant_id' => (string)$m['tenant_id'],
                    'shop_name' => $m['shop_name'],
                    'customer_id' => (string)$m['customer_id'],
                    'name' => $m['full_name']
                ];
            }
        }

        if (count($valid_shops) > 0) {
            // If only one shop, we can still provide the top-level fields for backward compatibility
            $first = $valid_shops[0];
            echo json_encode([
                'status' => 'success',
                'customer_id' => $first['customer_id'],
                'name' => $first['name'],
                'email' => $email, // The one they logged in with
                'tenant_id' => $first['tenant_id'],
                'shop_name' => $first['shop_name'],
                'role' => 'CUSTOMER',
                'shops' => $valid_shops,
                'message' => 'Login successful',
                'token' => bin2hex(random_bytes(16))
            ]);
            exit;
        }

        // If no customer matches, check USERS table for STAFF/MECHANIC
        $stmtUser = $db->prepare("SELECT u.user_id, u.tenant_id, u.name, u.email, u.password_hash, r.role_name, t.shop_name 
                                  FROM users u 
                                  JOIN roles r ON u.role_id = r.role_id
                                  JOIN tenants t ON u.tenant_id = t.tenant_id
                                  WHERE (u.email = ?) AND u.status = 'ACTIVE'");
        $stmtUser->execute([$email]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            echo json_encode([
                'status' => 'success',
                'customer_id' => (string)$user['user_id'], // Map user_id to customer_id for session
                'name' => $user['name'],
                'email' => $email,
                'tenant_id' => (string)$user['tenant_id'],
                'shop_name' => $user['shop_name'],
                'role' => $user['role_name'],
                'message' => 'Staff login successful',
                'token' => bin2hex(random_bytes(16))
            ]);
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => 'Invalid email/mobile or password.']);
        exit;
    }

    // Protect endpoints requiring Customer Auth below:
    $customer_id = $_POST['customer_id'] ?? $_GET['customer_id'] ?? null;

    // --- PRIORITY: VEHICLE REGISTRATION ---
    if ($action === 'add_vehicle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $plate_no = trim($_POST['plate_no'] ?? '');
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = trim($_POST['year'] ?? '');

        if (!$tenant_id || !$customer_id) {
            echo json_encode(['status' => 'error', 'message' => "Logged-in session lost. TID: '$tenant_id', CID: '$customer_id'"]);
            exit;
        }

        if (empty($plate_no) || empty($make) || empty($model)) {
            echo json_encode(['status' => 'error', 'message' => 'Plate number, make, and model are required.']);
            exit;
        }

        $year_val = !empty($year) ? intval($year) : null;
        // Adjusted to match your screenshot: using year_model
        $stmt = $db->prepare("INSERT INTO vehicles (tenant_id, customer_id, plate_no, make, model, year_model, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$tenant_id, $customer_id, $plate_no, $make, $model, $year_val]);
        
        echo json_encode(['status' => 'success', 'message' => 'Vehicle registered successfully.']);
        exit;
    }


    // ------------------------------------------------------------------
    // MODULE 2.5.2: Service Appointment Booking
    // ------------------------------------------------------------------

    // List available catalog services for the App drop-down (Accepts GET or POST)
    if ($action === 'get_services') {
        $stmt = $db->prepare("SELECT service_id, service_name, description, price FROM services WHERE tenant_id = ? AND status = 'ACTIVE'");
        $stmt->execute([$tenant_id]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $services]);
        exit;
    }

    // [POST] Create an Appointment Book request from Android 
    if ($action === 'book_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service_id = $_POST['service_id'];
        $vehicle_id = $_POST['vehicle_id'] ?? 0;
        $date = $_POST['date'];
        $time = $_POST['time'];
        $estimate = $_POST['estimate'] ?? 0;
        $mechanic_id = $_POST['mechanic_id'] ?? null;
        $bay_id = $_POST['bay_id'] ?? null;

        // --- DATABASE AUTO-HEAL ---
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS appointments (appointment_id INT AUTO_INCREMENT PRIMARY KEY)");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS tenant_id INT NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS customer_id INT NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS vehicle_id INT DEFAULT 0");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS service_id INT NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS appointment_date DATE NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS appointment_time TIME NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS estimated_amount DECIMAL(10,2) DEFAULT 0.00");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS mechanic_id INT NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS bay_id INT NULL");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'PENDING'");
            $db->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'UNPAID'");
        } catch (Exception $e) { /* silent fail on heal */ }

        try {
            $estimate_val = floatval($estimate);
            $stmt = $db->prepare("INSERT INTO appointments (tenant_id, customer_id, vehicle_id, service_id, appointment_date, appointment_time, estimated_amount, mechanic_id, bay_id, status, payment_status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 'PAID')");
            $stmt->execute([$tenant_id, $customer_id, $vehicle_id, $service_id, $date, $time, $estimate_val, $mechanic_id, $bay_id]);
            $new_id = $db->lastInsertId();
            
            echo json_encode(['status' => 'success', 'message' => 'Appointment booked successfully.', 'appointment_id' => $new_id]);
        } catch (Exception $be) {
            echo json_encode(['status' => 'error', 'message' => "System exception: " . $be->getMessage()]);
        }
        exit;
    }

    // New: Get Available Slots and Waiting Time
    if ($action === 'get_availability') {
        // Available Mechanics
        $stmtMech = $db->prepare("SELECT COUNT(*) as available FROM mechanics WHERE tenant_id = ? AND status = 'AVAILABLE'");
        $stmtMech->execute([$tenant_id]);
        $available_mechanics = $stmtMech->fetch(PDO::FETCH_ASSOC)['available'];

        // Available Bays
        $stmtBays = $db->prepare("SELECT COUNT(*) as available FROM service_bays WHERE tenant_id = ? AND status = 'AVAILABLE'");
        $stmtBays->execute([$tenant_id]);
        $available_bays = $stmtBays->fetch(PDO::FETCH_ASSOC)['available'];

        // Waiting Time Logic: 
        // Pending/In-Progress Jobs / Available Mechanics * constant (e.g. 30 mins per job)
        $stmtJobs = $db->prepare("SELECT COUNT(*) as active_jobs FROM repair_jobs WHERE tenant_id = ? AND status IN ('PENDING', 'IN_PROGRESS')");
        $stmtJobs->execute([$tenant_id]);
        $active_jobs = $stmtJobs->fetch(PDO::FETCH_ASSOC)['active_jobs'];

        $total_mechanics = $db->prepare("SELECT COUNT(*) as total FROM mechanics WHERE tenant_id = ? AND status != 'OFF_DUTY'");
        $total_mechanics->execute([$tenant_id]);
        $count_mechanics = $total_mechanics->fetch(PDO::FETCH_ASSOC)['total'];

        $wait_time_mins = 0;
        if ($count_mechanics > 0) {
            // Very simple wait time estimate: 45 mins per active job divided by capacity
            $wait_time_mins = ceil(($active_jobs * 45) / max(1, $count_mechanics));
        }

        echo json_encode([
            'status' => 'success',
            'available_mechanics' => (int)$available_mechanics,
            'available_bays' => (int)$available_bays,
            'waiting_time' => $wait_time_mins . " mins",
            'active_jobs' => (int)$active_jobs
        ]);
        exit;
    }

    // New: Get Mechanics and Bays for Dropdowns
    if ($action === 'get_mechanics_and_bays') {
        $stmtMech = $db->prepare("SELECT m.mechanic_id, u.name AS full_name, m.specialization 
                                  FROM mechanics m 
                                  JOIN users u ON m.user_id = u.user_id
                                  WHERE m.tenant_id = ? AND m.status != 'OFF_DUTY'");
        $stmtMech->execute([$tenant_id]);
        $mechanics = $stmtMech->fetchAll(PDO::FETCH_ASSOC);

        $stmtBays = $db->prepare("SELECT bay_id, bay_name FROM service_bays WHERE tenant_id = ? AND status = 'AVAILABLE'");
        $stmtBays->execute([$tenant_id]);
        $bays = $stmtBays->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'mechanics' => $mechanics,
            'bays' => $bays
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // MODULE 2.5.3: Online Payment (PayMongo via Android WebView Mock)
    // ------------------------------------------------------------------
    // [POST] Mocked PayMongo Payment Completion Recording
    if ($action === 'record_payment') {
        $amt = floatval($_POST['amount'] ?? 0);
        $method = $_POST['method'] ?? 'Online'; 
        $status = 'PENDING_VERIFICATION'; // For Cashier to confirm
        $apt_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : (isset($_POST['ref_id']) ? intval($_POST['ref_id']) : 0);

        $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, amount, payment_method, status, appointment_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$tenant_id, $customer_id, $amt, $method, $status, $apt_id]);
        
        echo json_encode(['status' => 'success', 'message' => 'Payment recorded. Pending Cashier verification.']);
        exit;
    }

    if ($action === 'create_payment_intent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = (float)$_POST['amount'];
        $pay_type = $_POST['type'] ?? 'DOWNPAYMENT'; // DOWNPAYMENT or FULL_PAYMENT
        $ref_id = $_POST['ref_id'] ?? null;          // job_id or appointment_id
        
        // In a real scenario, curl to https://api.paymongo.com/v1/links here to generate GCash Link
        // For now, save Payment Row locally as PENDING
        $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, ".($pay_type == 'DOWNPAYMENT' ? 'appointment_id' : 'job_id').", amount, payment_method, payment_type, status, transaction_ref) 
                               VALUES (?, ?, ?, ?, 'PAYMONGO_GCASH', ?, 'PENDING', ?)");
        
        $mock_txn_ref = 'PAY-' . strtoupper(uniqid());
        $stmt->execute([$tenant_id, $customer_id, $ref_id, $amount, $pay_type, $mock_txn_ref]);

        // Return the mocked URL that points to a generic Gcash checkout screen or our webhook
        echo json_encode([
            'status' => 'success', 
            'checkout_url' => "https://pay.paymongo.com/mock-link/{$mock_txn_ref}", 
            'transaction_id' => $mock_txn_ref
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // MODULE 2.5.4: Repair Status Tracking (Real-time Timeline)
    // ------------------------------------------------------------------
    if ($action === 'track_repair') {
        $job_id = $_GET['job_id'] ?? $_POST['job_id'] ?? null;

        // Get Job Head Details
        $stmt1 = $db->prepare("SELECT j.status, j.total_amount, j.notes, v.plate_no, v.make, v.model
                               FROM repair_jobs j 
                               JOIN vehicles v ON j.vehicle_id = v.vehicle_id 
                               WHERE j.job_id = ? AND j.customer_id = ?");
        $stmt1->execute([$job_id, $customer_id]);
        $job = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            echo json_encode(['status' => 'error', 'message' => 'Job not found.']); exit;
        }

        // Fetch Timeline records for Android RecyclerView
        $stmt2 = $db->prepare("SELECT status_update, remarks, created_at FROM repair_timeline WHERE job_id = ? ORDER BY created_at DESC");
        $stmt2->execute([$job_id]);
        $timeline = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'jobInfo' => $job, 'timeline' => $timeline]);
        exit;
    }

    // ------------------------------------------------------------------
    // MODULE 2.5.5: Service & Payment History
    // ------------------------------------------------------------------
    if ($action === 'get_history') {
        try {
            // --- 0. Auto-Healed Data Structure ---
            $repairs = [];
            
            // --- 1. Pending (Appointments) ---
            $stmt1 = $db->prepare("SELECT a.*, v.plate_no, s.service_name 
                                    FROM appointments a 
                                    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id 
                                    LEFT JOIN services s ON a.service_id = s.service_id
                                    WHERE a.customer_id = ?");
            $stmt1->execute([$customer_id]);
            foreach ($stmt1->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $repairs[] = [
                    'job_id' => $row['appointment_id'],
                    'plate_no' => $row['plate_no'] ?? 'N/A',
                    'status' => $row['status'],
                    'total_amount' => $row['estimated_amount'] ?? '0.00',
                    'date' => $row['appointment_date'],
                    'service_name' => $row['service_name'] ?? 'Booking'
                ];
            }

            // --- 2. History (Repair Jobs) ---
            $stmt2 = $db->prepare("SELECT j.*, v.plate_no FROM repair_jobs j 
                                    LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                                    WHERE j.customer_id = ?");
            $stmt2->execute([$customer_id]);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $repairs[] = [
                    'job_id' => $row['job_id'],
                    'plate_no' => $row['plate_no'] ?? 'N/A',
                    'status' => $row['status'],
                    'total_amount' => $row['total_amount'] ?? '0.00',
                    'date' => $row['created_at'] ?? $row['date'] ?? date('Y-m-d'),
                    'service_name' => 'Repair'
                ];
            }

            // --- 3. Payments ---
            $payments = [];
            $stmtP = $db->prepare("SELECT * FROM payments WHERE customer_id = ?");
            $stmtP->execute([$customer_id]);
            foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payments[] = [
                    'amount' => $row['amount'],
                    'payment_method' => $row['payment_method'] ?? 'CASH',
                    'payment_type' => $row['payment_type'] ?? 'FULL',
                    'status' => $row['status'],
                    'date' => $row['created_at'] ?? $row['payment_date'] ?? date('Y-m-d')
                ];
            }

            echo json_encode(['status' => 'success', 'repairs' => $repairs, 'payments' => $payments, 'message' => 'History Sync OK']);
            exit;
        } catch (Exception $e) {
            die(json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $e->getMessage()]));
        }
    }

    // ------------------------------------------------------------------
    // MODULE 2.5.6: Garage / Vehicle Management
    // ------------------------------------------------------------------
    
    // [GET] List all vehicles for a specific customer
    if ($action === 'get_garage') {
        $stmt = $db->prepare("SELECT vehicle_id, plate_no, make, model, year_model FROM vehicles WHERE tenant_id = ? AND customer_id = ? ORDER BY created_at DESC");
        $stmt->execute([$tenant_id, $customer_id]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $vehicles]);
        exit;
    }


    // ------------------------------------------------------------------
    // MODULE 2.5.7: Loyalty & Rewards
    // ------------------------------------------------------------------
    // --- MODULE 2.5.7: Loyalty & Rewards ---
    if ($action === 'loyalty_status') {
        // Ensure points column exists
        try { $db->exec("ALTER TABLE customers ADD COLUMN points INT DEFAULT 0"); } catch (Exception $ex) {}
        
        $stmt = $db->prepare("SELECT points FROM customers WHERE customer_id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $tenant_id]);
        $pts = (int)($stmt->fetchColumn() ?: 0);
        
        $level = "BRONZE MEMBER";
        if ($pts > 500) $level = "SILVER MEMBER";
        if ($pts > 1500) $level = "GOLD MEMBER";

        echo json_encode([
            'status' => 'success',
            'points' => $pts,
            'member_level' => $level,
            'next_tier' => ($pts <= 500 ? "500 pts to Silver" : ($pts <= 1500 ? "1500 pts to Gold" : "Max Tier reached"))
        ]);
        exit;
    }

    // Fallback if action is unknown
    echo json_encode(['status' => 'error', 'message' => "Invalid or unknown API request. Action: '$action', Method: ".$_SERVER['REQUEST_METHOD']]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System exception: ' . $e->getMessage()]);
}
?>
