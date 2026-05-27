<?php
require_once 'db-config.php';

$job_id = intval($_GET['job_id'] ?? 0);
$token = $_GET['token'] ?? '';

// Simple validation
if ($token !== md5($job_id . 'autofix_secret')) {
    die("Invalid link or missing permissions.");
}

$db = getDB();
// Fetch job and tenant details
$stmt = $db->prepare("
    SELECT j.*, t.*, c.full_name as customer_name, v.plate_no, v.make, v.model 
    FROM repair_jobs j
    JOIN tenants t ON j.tenant_id = t.tenant_id
    LEFT JOIN customers c ON j.customer_id = c.customer_id
    LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE j.job_id = ?
");
$stmt->execute([$job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) die("Job not found.");

// Fetch pending items
$pStmt = $db->prepare("
    SELECT rp.*, i.item_name, s.service_name 
    FROM repair_parts rp
    LEFT JOIN inventory i ON rp.item_id = i.item_id
    LEFT JOIN services s ON rp.service_id = s.service_id
    WHERE rp.job_id = ? AND rp.approval_status = 'PENDING_APPROVAL'
");
$pStmt->execute([$job_id]);
$pending_items = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX Approval/Rejection
if (isset($_GET['action']) && $_GET['action'] == 'process_approval' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $rp_id = intval($_POST['rp_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'APPROVED' or 'REJECTED'
    
    try {
        $db->beginTransaction();
        
        // Verify part belongs to job
        $verify = $db->prepare("SELECT * FROM repair_parts WHERE rp_id = ? AND job_id = ? AND approval_status = 'PENDING_APPROVAL'");
        $verify->execute([$rp_id, $job_id]);
        $part = $verify->fetch(PDO::FETCH_ASSOC);
        
        if (!$part) throw new Exception("Item not found or already processed.");
        
        if ($decision === 'APPROVED') {
            $db->prepare("UPDATE repair_parts SET approval_status = 'APPROVED' WHERE rp_id = ?")->execute([$rp_id]);
        } else if ($decision === 'REJECTED') {
            $db->prepare("UPDATE repair_parts SET approval_status = 'REJECTED' WHERE rp_id = ?")->execute([$rp_id]);
            // If it's a part, restore inventory
            if ($part['item_id']) {
                $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_id = ?")
                   ->execute([$part['quantity'], $part['item_id']]);
            }
        } else {
            throw new Exception("Invalid decision.");
        }
        
        // Recalculate job total ONLY with APPROVED parts
        $db->prepare("
            UPDATE repair_jobs j 
            SET total_amount = (
                COALESCE((SELECT price FROM services WHERE service_id = j.service_id), 0) + 
                COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = j.job_id AND approval_status = 'APPROVED'), 0)
            ) 
            WHERE j.job_id = ?
        ")->execute([$job_id]);
        
        $db->commit();
        echo json_encode(['status'=>'success']);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Agreement | <?php echo htmlspecialchars($job['shop_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --primary: <?php echo $job['primary_color'] ?: '#10b981'; ?>;
            --secondary: <?php echo $job['secondary_color'] ?: '#030712'; ?>;
            --radius: <?php echo $job['border_radius'] ?: '24px'; ?>;
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.05);
            --text-main: #ffffff;
            --text-dim: #9ca3af;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--secondary);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 600px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        h1 { margin-top: 0; color: var(--primary); text-align: center; font-weight: 600; }
        .info-box {
            background: rgba(0,0,0,0.2);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .info-box p { margin: 5px 0; color: var(--text-dim); }
        .info-box strong { color: var(--text-main); }
        .item-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        .item-card:hover { background: rgba(255,255,255,0.05); }
        .item-details h3 { margin: 0 0 5px 0; font-size: 1.1rem; }
        .item-details p { margin: 0; color: var(--text-dim); font-size: 0.9rem; }
        .price { font-size: 1.2rem; font-weight: 600; color: var(--primary); }
        .actions { display: flex; gap: 10px; margin-top: 10px; }
        button {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-approve { background: var(--primary); color: #fff; }
        .btn-approve:hover { filter: brightness(1.2); }
        .btn-reject { background: transparent; color: #ef4444; border: 1px solid #ef4444; }
        .btn-reject:hover { background: rgba(239, 68, 68, 0.1); }
        .empty-state { text-align: center; color: var(--text-dim); padding: 30px 0; }
    </style>
</head>
<body>

<div class="container">
    <h1><i class="fas fa-clipboard-check"></i> Additional Diagnosis</h1>
    <div class="info-box">
        <p><strong>Customer:</strong> <?php echo htmlspecialchars($job['customer_name']); ?></p>
        <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($job['make'] . ' ' . $job['model'] . ' (' . $job['plate_no'] . ')'); ?></p>
        <p><strong>Job Order ID:</strong> #<?php echo $job['job_id']; ?></p>
        <p>Our mechanic has identified additional items needing your attention. Please review and approve or decline the recommended parts/services below.</p>
    </div>

    <div id="items-container">
        <?php if(empty($pending_items)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                <h3>All Set!</h3>
                <p>There are no pending items requiring your approval at this time.</p>
            </div>
        <?php else: ?>
            <?php foreach($pending_items as $item): ?>
                <div class="item-card" id="item-<?php echo $item['rp_id']; ?>">
                    <div class="item-details">
                        <h3><?php echo htmlspecialchars($item['item_name'] ?? $item['service_name']); ?></h3>
                        <p>Quantity: <?php echo $item['quantity']; ?></p>
                        <div class="actions">
                            <button class="btn-approve" onclick="processItem(<?php echo $item['rp_id']; ?>, 'APPROVED')"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn-reject" onclick="processItem(<?php echo $item['rp_id']; ?>, 'REJECTED')"><i class="fas fa-times"></i> Decline</button>
                        </div>
                    </div>
                    <div class="price">
                        ₱<?php echo number_format($item['total_price'], 2); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    async function processItem(rp_id, decision) {
        if(!confirm(`Are you sure you want to ${decision.toLowerCase()} this item?`)) return;
        
        const fd = new FormData();
        fd.append('rp_id', rp_id);
        fd.append('decision', decision);

        try {
            const res = await fetch(`customer-agreement.php?job_id=<?php echo $job_id; ?>&token=<?php echo $token; ?>&action=process_approval`, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if(data.status === 'success') {
                const card = document.getElementById(`item-${rp_id}`);
                card.style.opacity = '0.5';
                card.style.pointerEvents = 'none';
                card.innerHTML = `<div style="padding: 10px; width: 100%; text-align: center; font-weight: bold; color: ${decision === 'APPROVED' ? 'var(--primary)' : '#ef4444'}">${decision}</div>`;
                
                setTimeout(() => {
                    card.remove();
                    if(document.querySelectorAll('.item-card').length === 0) {
                        document.getElementById('items-container').innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                                <h3>Thank You!</h3>
                                <p>All items have been reviewed. We will proceed accordingly.</p>
                            </div>
                        `;
                    }
                }, 1500);
            } else {
                alert(data.message || 'Error processing request.');
            }
        } catch (e) {
            alert('A network error occurred.');
        }
    }
</script>

</body>
</html>
