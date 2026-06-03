<?php
session_start();
require_once 'db-config.php';
$db = getDB();

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Invalid or missing payment token.</h2></div>");
}

try {
    $stmt = $db->prepare("SELECT j.*, t.shop_name, t.gcash_name, t.gcash_number, t.logo_url, c.full_name, c.email, v.plate_no 
                          FROM repair_jobs j
                          LEFT JOIN tenants t ON j.tenant_id = t.tenant_id
                          LEFT JOIN customers c ON j.customer_id = c.customer_id
                          LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                          WHERE j.payment_token = ?");
    $stmt->execute([$token]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>No job found for this link, or the link is invalid.</h2></div>");
    }

    $paidStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE job_id = ? AND status IN ('SUCCESS', 'COMPLETED')");
    $paidStmt->execute([$job['job_id']]);
    $paidAmount = floatval($paidStmt->fetchColumn());
    $balance = max(0, floatval($job['total_amount']) - $paidAmount);
    
    // Check if there are pending payments
    $pendingStmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE job_id = ? AND status = 'PENDING'");
    $pendingStmt->execute([$job['job_id']]);
    $hasPending = $pendingStmt->fetchColumn() > 0;

} catch (Throwable $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($balance <= 0) {
            throw new Exception("This job is already fully paid.");
        }
        
        if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid screenshot/image of your receipt.");
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $fileExt = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $fileName = 'receipt_' . $job['job_id'] . '_' . time() . '.' . $fileExt;
        
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $uploadDir . $fileName)) {
            $proofPath = 'uploads/' . $fileName;
            
            // Insert pending payment
            $stmt = $db->prepare("INSERT INTO payments (tenant_id, customer_id, job_id, amount, payment_method, status, proof_image, payment_date) VALUES (?, ?, ?, ?, 'ONLINE', 'PENDING', ?, NOW())");
            $stmt->execute([$job['tenant_id'], $job['customer_id'], $job['job_id'], $balance, $proofPath]);
            
            $message = "<div class='alert success'>Thank you! Your payment receipt has been uploaded and is waiting for shop verification.</div>";
            $hasPending = true;
        } else {
            throw new Exception("Failed to upload the receipt file.");
        }
    } catch (Throwable $e) {
        $message = "<div class='alert error'>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$plateNo = $job['plate_no'] ?: $job['walkin_plate'] ?: 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Payment - <?php echo htmlspecialchars($job['shop_name']); ?></title>
    <style>
        :root {
            --bg-main: #f9fafb;
            --surface: #ffffff;
            --text-main: #111827;
            --text-dim: #6b7280;
            --accent: #4f46e5;
            --accent-hover: #4338ca;
            --success: #10b981;
            --border: #e5e7eb;
        }
        body { font-family: 'Inter', system-ui, sans-serif; background-color: var(--bg-main); color: var(--text-main); margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background-color: var(--surface); padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-width: 500px; width: 100%; box-sizing: border-box;}
        .shop-header { display: flex; flex-direction: column; align-items: center; margin-bottom: 25px; text-align: center; }
        .shop-header img { max-width: 100px; max-height: 100px; object-fit: contain; margin-bottom: 15px; border-radius: 8px; }
        .shop-header h2 { margin: 0; color: var(--text-main); }
        .details-box { background: var(--bg-main); border: 1px solid var(--border); padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .details-box p { margin: 8px 0; display: flex; justify-content: space-between; font-size: 0.95rem; }
        .details-box p strong { color: var(--text-main); }
        .balance { font-size: 1.25rem; color: var(--accent); font-weight: 800; text-align: center; margin: 15px 0; padding-top: 15px; border-top: 1px dashed var(--border); }
        .gcash-box { background: #0050E6; color: white; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .gcash-box h3 { margin: 0 0 10px 0; font-size: 1.1rem; }
        .gcash-box p { margin: 5px 0; font-size: 1.1rem; font-weight: bold; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9rem; }
        input[type="file"] { width: 100%; padding: 10px; border: 1px dashed var(--border); border-radius: 6px; box-sizing: border-box; background: var(--bg-main); }
        .btn-submit { width: 100%; background-color: var(--accent); color: white; padding: 12px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: background 0.2s; font-size: 1rem; }
        .btn-submit:hover { background-color: var(--accent-hover); }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; }
        .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; background: #fef3c7; color: #d97706; }
    </style>
</head>
<body>

<div class="container">
    <div class="shop-header">
        <?php if (!empty($job['logo_url'])): ?>
            <img src="<?php echo htmlspecialchars($job['logo_url']); ?>" alt="Shop Logo">
        <?php endif; ?>
        <h2><?php echo htmlspecialchars($job['shop_name']); ?></h2>
        <p style="color: var(--text-dim); margin: 5px 0 0 0;">Service Completed - Online Payment</p>
    </div>

    <?php echo $message; ?>

    <div class="details-box">
        <p><span>Customer:</span> <strong><?php echo htmlspecialchars($job['full_name']); ?></strong></p>
        <p><span>Vehicle Plate:</span> <strong><?php echo htmlspecialchars($plateNo); ?></strong></p>
        <div class="balance">
            Total Balance: PHP <?php echo number_format($balance, 2); ?>
        </div>
    </div>

    <?php if ($balance <= 0): ?>
        <div class="alert success" style="text-align: center; font-size: 1.1rem; font-weight: bold;">
            This job is fully paid. Thank you!
        </div>
    <?php elseif ($hasPending): ?>
        <div class="alert success" style="text-align: center;">
            <p><strong>Your payment receipt has been received.</strong></p>
            <p style="margin-bottom:0;">Please wait for the shop administrator to verify it.</p>
            <span class="status-badge" style="margin-top:10px;">Verification Pending</span>
        </div>
    <?php else: ?>
        
        <?php if (!empty($job['gcash_name']) || !empty($job['gcash_number'])): ?>
            <div class="gcash-box">
                <h3>Pay via GCash</h3>
                <p>Name: <?php echo htmlspecialchars($job['gcash_name'] ?: 'Not specified'); ?></p>
                <p>Number: <?php echo htmlspecialchars($job['gcash_number'] ?: 'Not specified'); ?></p>
            </div>
        <?php else: ?>
            <div class="alert error">
                The shop has not set up their GCash details yet. Please contact them directly for payment instructions.
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Upload Payment Receipt (Screenshot)</label>
                <input type="file" name="receipt" accept="image/*" required>
            </div>
            <button type="submit" class="btn-submit">Submit Payment Proof</button>
        </form>

    <?php endif; ?>

</div>

</body>
</html>
