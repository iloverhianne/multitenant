<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$shop_name = $_POST['shop_name'] ?? 'Your Shop';
$owner_name = $_POST['owner_name'] ?? 'Owner';
$email = $_POST['email'] ?? 'owner@email.com';
$plan = $_POST['plan'] ?? 'Pro Auto Shop';

// Generate these for display
$tenant_id_display = "TN-" . strtoupper(substr(str_replace(' ', '', $shop_name), 0, 3)) . "-" . rand(1000, 9999);
$subdomain = strtolower(str_replace(' ', '-', $shop_name)) . ".autofix.hub";

require_once 'db-config.php';

$success = false;
$error = '';

try {
    $db = getDB();
    
    // Auto-migrate columns (do this before transaction as DDL causes implicit commit)
    $migrations = [
        "ALTER TABLE tenants ADD COLUMN slug VARCHAR(100) DEFAULT NULL AFTER status",
        "ALTER TABLE tenants ADD COLUMN id_type VARCHAR(50) DEFAULT NULL AFTER slug",
        "ALTER TABLE tenants ADD COLUMN business_proof_url VARCHAR(255) DEFAULT NULL AFTER id_type",
        "ALTER TABLE tenants ADD COLUMN permit_expiry_date DATE DEFAULT NULL AFTER business_proof_url",
        "ALTER TABLE tenants ADD COLUMN dti_proof_url VARCHAR(255) DEFAULT NULL AFTER permit_expiry_date",
        "ALTER TABLE tenants ADD COLUMN dti_expiry_date DATE DEFAULT NULL AFTER dti_proof_url",
        "ALTER TABLE tenants ADD COLUMN id_photo_url VARCHAR(255) DEFAULT NULL AFTER dti_expiry_date",
        "ALTER TABLE tenants ADD COLUMN id_expiry_date DATE DEFAULT NULL AFTER id_photo_url"
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) {}
    }

    // 0. Check if email already exists to avoid duplicate entry error
    $checkStmt = $db->prepare("SELECT tenant_id FROM tenants WHERE email = ? LIMIT 1");
    $checkStmt->execute([$email]);
    if ($checkStmt->fetch()) {
        throw new Exception("This email is already registered as a shop owner. Please use a different email or log in.");
    }

    $db->beginTransaction();
    
    // 1. Insert into Tenants
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $shop_name)); // Create URL-friendly slug
    $slug = trim($slug, '-');
    $stmt = $db->prepare("INSERT INTO tenants (shop_name, owner_name, email, address, status, slug, id_type, business_proof_url, permit_expiry_date, dti_proof_url, dti_expiry_date, id_photo_url, id_expiry_date) VALUES (?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $shop_name, 
        $owner_name, 
        $email, 
        $_POST['address'] ?? '', 
        $slug,
        $_POST['id_type'] ?? '',
        $_POST['business_proof_url'] ?? '',
        $_POST['permit_expiry_date'] ?? null,
        $_POST['dti_proof_url'] ?? '',
        $_POST['dti_expiry_date'] ?? null,
        $_POST['id_photo_url'] ?? '',
        $_POST['id_expiry_date'] ?? null
    ]);
    $last_tenant_id = $db->lastInsertId();

    // 2. Identify Plan ID (Now using direct ID from POST)
    $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 2;
    $billing_cycle = $_POST['billing_cycle'] ?? 'monthly';

    // 3. Insert into Users (Owner Role ID = 2)
    $password_hash = password_hash($_POST['password'] ?? 'owner123', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (tenant_id, role_id, name, email, password_hash, status) VALUES (?, 2, ?, ?, ?, 'PENDING')");
    $stmt->execute([$last_tenant_id, $owner_name, $email, $password_hash]);

    // 4. Insert into Subscriptions
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime('+30 days'));
    $stmt = $db->prepare("INSERT INTO tenant_subscriptions (tenant_id, plan_id, billing_cycle, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
    $stmt->execute([$last_tenant_id, $plan_id, $billing_cycle, $start_date, $end_date]);
    $sub_id = $db->lastInsertId();

    // 5. Insert into Payments
    $price = $_POST['price'] ?? 0;
    $method = $_POST['payment_method'] ?? 'PAYMONGO';
    $ref = 'TRX-' . strtoupper(substr(md5(time()), 0, 9));
    $stmt = $db->prepare("INSERT INTO tenant_payments (tenant_id, subscription_id, amount, payment_method, transaction_reference, payment_status) VALUES (?, ?, ?, ?, ?, 'SUCCESS')");
    $stmt->execute([$last_tenant_id, $sub_id, $price, $method, $ref]);

    // 6. Log the registration
    $stmt = $db->prepare("INSERT INTO audit_logs (tenant_id, activity_type, description) VALUES (?, 'REGISTRATION', ?)");
    $stmt->execute([$last_tenant_id, "New Tenant Registered: $shop_name"]);

    // 7. Send 'Under Review' Notification to Tenant
    require_once 'mailer-service.php';
    $subject = "AutoFix Hub: Application Under Review";
    $message = "<html><body style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>";
    $message .= "<h2 style='color: #6366f1;'>Application Received!</h2>";
    $message .= "<p>Hi <b>" . htmlspecialchars($owner_name) . "</b>,</p>";
    $message .= "<p>Thank you for registering <b>" . htmlspecialchars($shop_name) . "</b> with AutoFix Hub.</p>";
    $message .= "<div style='padding: 15px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;'>";
    $message .= "Your application is currently <b>UNDER REVIEW</b> by our administrative team. We will verify your uploaded documents and notify you once your workshop hub is ready for activation.";
    $message .= "</div>";
    $message .= "<p>Typically, this process takes 24-48 hours. Stay tuned for our next email!</p>";
    $message .= "<p><br>Regards,<br><b>The AutoFix Hub Team</b></p>";
    $message .= "</body></html>";
    
    Mailer::sendHTML($email, $subject, $message);

    $db->commit();
    $success = true;
} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    $error = $e->getMessage();
}

// Keep the UI part as it was, but remove the localStorage script
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Success | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --accent: #10b981; /* Success Green */
            --text-dim: rgba(255,255,255,0.6);
            --glass: rgba(255,255,255,0.03);
            --glass-border: rgba(255,255,255,0.08);
        }

        body {
            background-color: var(--bg);
            color: white;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
        }

        .success-card {
            width: 100%;
            max-width: 600px;
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 40px;
            padding: 4rem;
            text-align: center;
            position: relative;
            box-shadow: 0 50px 100px -20px rgba(0,0,0,0.8);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .check-icon {
            width: 100px;
            height: 100px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 2.5rem;
            border: 2px solid rgba(16, 185, 129, 0.2);
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(16, 185, 129, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        h1 { font-size: 2.5rem; font-weight: 800; margin-bottom: 1rem; letter-spacing: -1px; }
        .subtitle { color: var(--text-dim); font-size: 1.1rem; line-height: 1.6; margin-bottom: 3rem; }

        .tenant-info {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2rem;
            text-align: left;
            margin-bottom: 3rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        .info-row span:first-child { color: var(--text-dim); }
        .info-row span:last-child { font-weight: 700; color: white; }

        .btn-dashboard {
            display: block;
            background: white;
            color: black;
            text-decoration: none;
            padding: 1.5rem;
            border-radius: 100px;
            font-weight: 800;
            font-size: 1.1rem;
            transition: all 0.3s;
        }

        .btn-dashboard:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(255,255,255,0.2);
        }

        .email-status {
            margin-top: 2rem;
            font-size: 0.85rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .confetti {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0; left: 0;
            pointer-events: none;
            z-index: -1;
        }
    </style>
</head>
<body>

    <div class="success-card">
        <?php if ($success): ?>
            <div class="check-icon" style="background:rgba(99,102,241,0.1); color:#6366f1; border-color:rgba(99,102,241,0.2);">🕒</div>
            <h1>Application Submitted!</h1>
            <p class="subtitle">Thank you for joining the AutoFix Network, <strong><?php echo htmlspecialchars($owner_name); ?></strong>. Your application is now <b>UNDER REVIEW</b> by our team.</p>

            <div class="tenant-info">
                <div class="info-row">
                    <span>Application ID:</span>
                    <span style="color: #6366f1;"><?php echo $tenant_id_display; ?></span>
                </div>
                <div class="info-row">
                    <span>Proposed Shop URL:</span>
                    <a href="shop.php?id=<?php echo urlencode($slug ?? 'demo'); ?>" style="color: #10b981; font-weight: 700; text-decoration: none;">autofixhub.ph/shop/<?php echo htmlspecialchars($slug ?? 'demo'); ?></a>
                </div>
                <div class="info-row">
                    <span>Plan Selected:</span>
                    <span><?php echo htmlspecialchars($plan); ?></span>
                </div>
                <div class="info-row">
                    <span>Registration Email:</span>
                    <span><?php echo htmlspecialchars($email); ?></span>
                </div>
                <div class="info-row">
                    <span>Status:</span>
                    <span style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 2px 8px; border-radius: 4px; font-weight: 800;">PENDING APPROVAL</span>
                </div>
            </div>

            <a href="index.php" class="btn-dashboard">Back to Home</a>

            <div class="email-status">
                <span>✉️</span> Confirmation and next steps sent to <?php echo htmlspecialchars($email); ?>
            </div>
        <?php else: ?>
            <div class="check-icon" style="background:rgba(239,68,68,0.1); color:#ef4444; border-color:rgba(239,68,68,0.2);">!</div>
            <h1 style="color:#ef4444;">Setup Failed</h1>
            <p class="subtitle">We encountered an error while provisioning your workshop.</p>
            <div style="background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; color: #ffaaaa; font-family: monospace; font-size: 0.9rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <a href="index.php#pricing" class="btn-dashboard">Try Again</a>
        <?php endif; ?>
    </div>

    <!-- Fireworks simulation background -->
    <div class="confetti"></div>

</body>
</html>
