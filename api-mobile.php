<?php
/**
 * api-mobile.php - COMPLETE MASTER BACKEND FOR AUTOFIX APP
 * Handles: Login, Garage, Services, Mechanics, Slots, Booking, History, Tracking, Loyalty, Chat.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

error_reporting(0); // Disable direct output to avoid breaking JSON
ini_set('display_errors', 0);

// Global error/exception handler to prevent 500 crashes
set_exception_handler(function ($t) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Fatal: ' . $t->getMessage(),
        'file' => basename($t->getFile()),
        'line' => $t->getLine()
    ]);
    exit;
});

if (file_exists('db-config.php')) {
    require_once 'db-config.php';
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Configuration file (db-config.php) is missing on the server.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$tid = $_GET['tid'] ?? $_POST['tid'] ?? '1';
$cid = $_POST['customer_id'] ?? $_GET['customer_id'] ?? null;


try {
    $db = getDB();
    if (!$db) {
        throw new Exception("Database connection failed to initialize.");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 0. PRIORITY ACTIONS ---
    if ($action === 'delete_vehicle_mobile' || $action === 'remove_vehicle') {
        $vid = $_REQUEST['vehicleId'] ?? $_REQUEST['vehicle_id'] ?? '';
        $cidParam = $_REQUEST['customerId'] ?? $_REQUEST['customer_id'] ?? '';
        $tid = $_REQUEST['tid'] ?? $_REQUEST['tenant_id'] ?? '1';

        if (empty($vid)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing vehicle_id.']);
            exit;
        }

        // Permanent Delete - Using customer_id as the primary security check
        try {
            // We use both vehicle_id and customer_id to ensure the owner is the one deleting it
            $stmt = $db->prepare("DELETE FROM vehicles WHERE vehicle_id = ? AND customer_id = ?");
            $stmt->execute([$vid, $cidParam]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Vehicle permanently removed.']);
            } else {
                // If no rows affected, check if it exists at all
                $check = $db->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_id = ?");
                $check->execute([$vid]);
                if ($check->fetchColumn() == 0) {
                    echo json_encode(['status' => 'success', 'message' => 'Already removed.']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or vehicle not found.']);
                }
            }
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
            exit;
        }
    }

    // --- 1. LOGIN ---
    if ($action === 'login') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $mobile = $_POST['mobile'] ?? '';

        $stmt = $db->prepare("SELECT c.customer_id, c.tenant_id, c.full_name, c.email, c.password_hash, t.shop_name FROM customers c JOIN tenants t ON c.tenant_id = t.tenant_id WHERE (c.mobile = ? OR c.email = ?) AND c.status = 'ACTIVE'");
        $stmt->execute([$mobile, $email]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $valid_shops = [];
        foreach ($matches as $m) {
            if (password_verify($password, $m['password_hash'])) {
                $valid_shops[] = ['tenant_id' => (string) $m['tenant_id'], 'shop_name' => $m['shop_name'], 'customer_id' => (string) $m['customer_id'], 'name' => $m['full_name']];
            }
        }
        if (count($valid_shops) > 0) {
            $f = $valid_shops[0];
            echo json_encode(['status' => 'success', 'customer_id' => $f['customer_id'], 'name' => $f['name'], 'email' => $email, 'tenant_id' => $f['tenant_id'], 'shop_name' => $f['shop_name'], 'role' => 'CUSTOMER', 'shops' => $valid_shops]);
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
        exit;
    }

    // --- 2. GARAGE ---
    if ($action === 'get_garage') {
        // Exclude soft-deleted vehicles
        try {
            $stmt = $db->prepare("SELECT vehicle_id, plate_no, make, model, year_model FROM vehicles WHERE customer_id = ? AND (status IS NULL OR status NOT IN ('DELETED', 'INACTIVE', 'REMOVED', 'ARCHIVED')) ORDER BY created_at DESC");
            $stmt->execute([$cid]);
            $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $vehicles]);
        } catch (Exception $e) {
            // Fallback if status column doesn't exist
            $stmt = $db->prepare("SELECT vehicle_id, plate_no, make, model, year_model FROM vehicles WHERE customer_id = ? ORDER BY created_at DESC");
            $stmt->execute([$cid]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        exit;
    }
    if ($action === 'add_vehicle') {
        $stmt = $db->prepare("INSERT INTO vehicles (tenant_id, customer_id, plate_no, make, model, year_model, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$tid, $cid, $_POST['plate_no'], $_POST['make'], $_POST['model'], $_POST['year']]);
        echo json_encode(['status' => 'success', 'message' => 'Vehicle added.']);
        exit;
    }

    // --- 3. BOOKING ASSETS ---
    if ($action === 'get_services') {
        $stmt = $db->prepare("SELECT service_id, service_name, description, price FROM services WHERE tenant_id = ? AND status = 'ACTIVE'");
        $stmt->execute([$tid]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'get_mechanics') {
        $stmt = $db->prepare("SELECT user_id, name FROM users WHERE tenant_id = ? AND role_id = (SELECT role_id FROM roles WHERE role_name = 'MECHANIC' LIMIT 1) AND status = 'ACTIVE'");
        $stmt->execute([$tid]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'get_slots') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $db->prepare("SELECT count(*) as booked FROM appointments WHERE tenant_id = ? AND appointment_date = ? AND status != 'CANCELLED'");
        $stmt->execute([$tid, $date]);
        $booked = (int) $stmt->fetchColumn();
        $total_bays = 5; // Default bays
        echo json_encode(['status' => 'success', 'available_slots' => max(0, $total_bays - $booked)]);
        exit;
    }

    // --- 3.1. NEW: GET SCHEDULES (Dynamic with duration and overlap check) ---
    if ($action === 'get_schedules') {
        $date = $_GET['date'] ?? $_POST['date'] ?? '';
        $serviceIds = $_GET['service_id'] ?? $_POST['service_id'] ?? '';
        $tid = $_GET['tid'] ?? $_POST['tid'] ?? '1';

        if (empty($date) || empty($serviceIds)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'message' => 'service_id and appointment_date are required.'
            ]);
            exit;
        }

        // A. Calculate the total duration of the selected services
        $durationMinutes = 60;
        try {
            $serviceArray = explode(',', $serviceIds);
            if (count($serviceArray) > 0) {
                $placeholders = implode(',', array_fill(0, count($serviceArray), '?'));
                // Just count the services and multiply by 60 for now to be safe
                $stmt = $db->prepare("SELECT COUNT(*) FROM services WHERE service_id IN ($placeholders) AND tenant_id = ?");
                $stmt->execute(array_merge($serviceArray, [$tid]));
                $count = (int) $stmt->fetchColumn();
                $durationMinutes = max(60, $count * 60);
            }
        } catch (Throwable $e) {
            $durationMinutes = 60;
        }

        // B. Fetch all booked appointments for this date to filter in PHP (more robust)
        $bookedAppointments = [];
        try {
            $stmt = $db->prepare("SELECT mechanic_id, appointment_time FROM appointments WHERE tenant_id = ? AND appointment_date = ? AND status IN ('PENDING', 'CONFIRMED', 'ONGOING', 'APPROVED')");
            $stmt->execute([$tid, $date]);
            $bookedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }

        // C. Generate standard shop hours slots (e.g., 8:00 AM to 5:00 PM)
        $startTime = strtotime("08:00:00");
        $endTime = strtotime("17:00:00");
        $interval = 3600; // 1 hour intervals

        $timeSlots = [];
        $slotIdCounter = 1;

        // Get total mechanics - Flexible check
        $totalMechs = 5;
        try {
            $stmtM = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.tenant_id = ? AND r.role_name = 'MECHANIC' AND u.status = 'ACTIVE'");
            $stmtM->execute([$tid]);
            $totalMechs = (int) $stmtM->fetchColumn();
            if ($totalMechs <= 0) {
                // Fallback to direct role column
                $stmtM = $db->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'Mechanic' AND status = 'ACTIVE'");
                $stmtM->execute([$tid]);
                $totalMechs = (int) $stmtM->fetchColumn();
            }
        } catch (Throwable $e) {
            $totalMechs = 5;
        }

        for ($t = $startTime; $t < $endTime; $t += $interval) {
            $slotStartTs = $t;
            $slotEndTs = $t + ($durationMinutes * 60);

            $dbStart = date("H:i:s", $slotStartTs);
            $dbEnd = date("H:i:s", $slotEndTs);
            $displayTime = date("g:i A", $slotStartTs) . " - " . date("g:i A", $slotEndTs);

            // Overlap check in PHP
            $bookedMechanicIds = [];
            foreach ($bookedAppointments as $appt) {
                $apptStartTs = strtotime($appt['appointment_time']);
                if (!$apptStartTs)
                    continue;
                $apptEndTs = $apptStartTs + 3600; // Assume 1 hour per appt

                // Overlap: StartA < EndB AND EndA > StartB
                if ($apptStartTs < $slotEndTs && $apptEndTs > $slotStartTs) {
                    if (!empty($appt['mechanic_id'])) {
                        $bookedMechanicIds[$appt['mechanic_id']] = true;
                    }
                }
            }

            $availableCount = max(0, $totalMechs - count($bookedMechanicIds));

            $timeSlots[] = [
                'schedule_id' => $slotIdCounter,
                'time_slot_id' => $slotIdCounter,
                'start_time' => $dbStart,
                'end_time' => $dbEnd,
                'display_time' => $displayTime,
                'available_mechanics_count' => $availableCount,
                'time_range' => $displayTime . ($availableCount <= 0 ? " (Fully Booked)" : "")
            ];
            $slotIdCounter++;
        }

        echo json_encode([
            'success' => true,
            'status' => 'success',
            'time_slots' => $timeSlots,
            'schedules' => $timeSlots,
            'message' => empty($timeSlots) ? "No schedule available for the selected service/date." : ""
        ]);
        exit;
    }

    // --- 3.5. NEW: GET AVAILABLE MECHANICS (Filtering booked ones with overlap check) ---
    if ($action === 'get_available_mechanics' || $action === 'get_mechanics_and_bays') {
        $date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
        $timeRange = $_GET['time'] ?? $_POST['time'] ?? '';
        $tid = $_GET['tid'] ?? $_POST['tid'] ?? '1';

        if (empty($date) || empty($timeRange)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'date and time are required.']);
            exit;
        }

        $slotStartTs = 0;
        $slotEndTs = 0;
        if (strpos($timeRange, ' - ') !== false) {
            $parts = explode(' - ', $timeRange);
            $slotStartTs = strtotime($parts[0]);
            $slotEndTs = isset($parts[1]) ? strtotime($parts[1]) : ($slotStartTs + 3600);
        } else {
            $slotStartTs = strtotime($timeRange);
            $slotEndTs = $slotStartTs + 3600;
        }

        // Fetch all booked appointments to filter in PHP
        $unavailableIds = [];
        try {
            $stmt = $db->prepare("SELECT mechanic_id, appointment_time FROM appointments WHERE tenant_id = ? AND appointment_date = ? AND status IN ('PENDING', 'CONFIRMED', 'ONGOING', 'APPROVED')");
            $stmt->execute([$tid, $date]);
            $booked = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($booked as $b) {
                $apptStartTs = strtotime($b['appointment_time']);
                if (!$apptStartTs)
                    continue;
                $apptEndTs = $apptStartTs + 3600;
                if ($apptStartTs < $slotEndTs && $apptEndTs > $slotStartTs) {
                    if (!empty($b['mechanic_id'])) {
                        $unavailableIds[] = $b['mechanic_id'];
                    }
                }
            }
        } catch (Throwable $e) {
        }

        // Fetch available mechanics - Flexible check
        $mechanics = [];
        try {
            // Plan A: JOIN roles
            $query = "SELECT u.user_id as mechanic_id, u.name as full_name, r.role_name as specialization FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.tenant_id = ? AND r.role_name = 'MECHANIC' AND u.status = 'ACTIVE'";
            $params = [$tid];
            if (!empty($unavailableIds)) {
                $placeholders = implode(',', array_fill(0, count($unavailableIds), '?'));
                $query .= " AND u.user_id NOT IN ($placeholders)";
                $params = array_merge($params, array_values($unavailableIds));
            }
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e1) {
            try {
                // Plan B: role column
                $query = "SELECT user_id as mechanic_id, name as full_name, role as specialization FROM users WHERE tenant_id = ? AND role = 'Mechanic' AND status = 'ACTIVE'";
                $params = [$tid];
                if (!empty($unavailableIds)) {
                    $placeholders = implode(',', array_fill(0, count($unavailableIds), '?'));
                    $query .= " AND user_id NOT IN ($placeholders)";
                    $params = array_merge($params, array_values($unavailableIds));
                }
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {
                $mechanics = [];
            }
        }

        // Fetch bays
        $bays = [];
        try {
            $stmtB = $db->prepare("SELECT bay_id, name as bay_name FROM bays WHERE tenant_id = ?");
            $stmtB->execute([$tid]);
            $bays = $stmtB->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            for ($i = 1; $i <= 5; $i++) {
                $bays[] = ['bay_id' => (string) $i, 'bay_name' => "Bay $i"];
            }
        }

        echo json_encode([
            'success' => true,
            'status' => 'success',
            'mechanics' => $mechanics,
            'bays' => $bays
        ]);
        exit;
    }

    // --- 4. BOOKING EXECUTION ---
    if ($action === 'book_appointment') {
        $appTime = $_POST['time'] ?? '';
        // If time is a range (e.g. "9:00 AM - 10:00 AM"), extract only the start time for the database
        if (strpos($appTime, ' - ') !== false) {
            $parts = explode(' - ', $appTime);
            $appTime = date("H:i:s", strtotime($parts[0]));
        }

        $stmt = $db->prepare("INSERT INTO appointments (tenant_id, customer_id, vehicle_id, service_id, mechanic_id, appointment_date, appointment_time, estimated_amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())");
        $stmt->execute([$tid, $cid, $_POST['vehicle_id'], $_POST['service_id'], $_POST['mechanic_id'], $_POST['date'], $appTime, $_POST['estimate']]);
        echo json_encode(['status' => 'success', 'appointment_id' => $db->lastInsertId()]);
        exit;
    }

    if ($action === 'record_payment') {
        $amt = $_POST['amount'] ?? '0';
        $type = $_POST['type'] ?? 'DOWNPAYMENT';
        $method = $_POST['method'] ?? 'GCash';
        $refId = $_POST['ref_id'] ?? null; // Usually the appointment_id

        // 1. Insert into payments table
        $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, amount, payment_method, payment_type, status, ref_id, created_at) VALUES (?, ?, ?, ?, ?, 'SUCCESS', ?, NOW())");
        $stmt->execute([$tid, $cid, $amt, $method, $type, $refId]);

        // 2. Update Appointment Status if refId is provided
        if ($refId) {
            // Update paid_amount and set status to CONFIRMED
            $stmtU = $db->prepare("UPDATE appointments SET paid_amount = IFNULL(paid_amount, 0) + ?, status = 'CONFIRMED' WHERE appointment_id = ?");
            $stmtU->execute([$amt, $refId]);

            // Add Loyalty Points (1 Pt per 100 PHP)
            $points = floor((float) $amt / 100);
            if ($points > 0) {
                $stmtL = $db->prepare("UPDATE customers SET points = points + ? WHERE customer_id = ?");
                $stmtL->execute([$points, $cid]);
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Payment recorded.']);
        exit;
    }

    // --- 5. HISTORY & REPAIRS ---
    if ($action === 'get_history') {
        $repairs = [];
        $stmt = $db->prepare("SELECT a.*, v.plate_no, s.service_name, u.name as mechanic_name FROM appointments a LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id LEFT JOIN services s ON a.service_id = s.service_id LEFT JOIN users u ON a.mechanic_id = u.user_id WHERE a.customer_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
        $stmt->execute([$cid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $repairs[] = [
                'job_id' => (string) $r['appointment_id'],
                'plate_no' => $r['plate_no'] ?? 'N/A',
                'service_name' => $r['service_name'] ?? 'General Service',
                'status' => $r['status'],
                'date' => $r['appointment_date'],
                'time' => $r['appointment_time'],
                'mechanic' => $r['mechanic_name'] ?? 'Assigned Soon',
                'total_amount' => $r['estimated_amount'],
                'paid_amount' => $r['paid_amount'] ?? '0.00'
            ];
        }
        $payments = [];
        $stmtP = $db->prepare("SELECT * FROM payments WHERE customer_id = ? ORDER BY created_at DESC");
        $stmtP->execute([$cid]);
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $payments[] = [
                'payment_id' => $p['payment_id'] ?? $p['id'] ?? '0',
                'amount' => $p['amount'] ?? '0.00',
                'payment_method' => $p['payment_method'] ?? $p['method'] ?? 'Online',
                'status' => $p['status'] ?? 'SUCCESS',
                'created_at' => $p['created_at'] ?? $p['date'] ?? date('Y-m-d H:i:s'),
                'payment_type' => $p['payment_type'] ?? $p['type'] ?? 'PAYMENT'
            ];
        }
        echo json_encode(['status' => 'success', 'repairs' => $repairs, 'payments' => $payments]);
        exit;
    }

    // --- 6. TRACKING ---
    if ($action === 'get_repair_status') {
        $job_id = $_GET['job_id'] ?? '';
        $stmt = $db->prepare("SELECT status, updated_at FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$job_id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'current_status' => $res['status'] ?? 'UNKNOWN', 'last_update' => $res['updated_at'] ?? '']);
        exit;
    }

    // --- 7. LOYALTY ---
    if ($action === 'loyalty_status') {
        $stmt = $db->prepare("SELECT points FROM customers WHERE customer_id = ?");
        $stmt->execute([$cid]);
        $points = (int) $stmt->fetchColumn();
        $tier = "BRONZE";
        $next = "SILVER";
        $target = 500;
        if ($points >= 500) {
            $tier = "SILVER";
            $next = "GOLD";
            $target = 1500;
        }
        if ($points >= 1500) {
            $tier = "GOLD";
            $next = "PLATINUM";
            $target = 5000;
        }
        echo json_encode(['status' => 'success', 'points' => $points, 'member_level' => $tier, 'next_tier' => $next, 'points_to_next' => max(0, $target - $points)]);
        exit;
    }

    // --- 8. CHAT ---
    if ($action === 'get_messages') {
        $stmt = $db->prepare("SELECT * FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND tenant_id = ? ORDER BY created_at ASC");
        $stmt->execute([$cid, $cid, $tid]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // --- 9. CATCH ALL ---
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Invalid or unknown API request. Action: '$action'"]);
    exit;

} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'System error: ' . $t->getMessage(), 'file' => basename($t->getFile()), 'line' => $t->getLine()]);
}
?>