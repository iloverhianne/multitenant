<?php
require_once 'db-config.php';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if (empty($token) || empty($action)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT rp_id, job_id, tenant_id FROM repair_parts WHERE approval_token = ? AND approval_status = 'PENDING'");
        $stmt->execute([$token]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($items) === 0) {
            throw new Exception("No pending items found or already processed.");
        }
        
        $jobId = $items[0]['job_id'];
        $tenantId = $items[0]['tenant_id'];
        
        if ($action === 'APPROVE') {
            $signature = $_POST['signature'] ?? '';
            if (empty($signature)) throw new Exception("Signature is required for approval.");
            
            $upd = $db->prepare("UPDATE repair_parts SET approval_status = 'APPROVED', customer_signature = ? WHERE approval_token = ? AND approval_status = 'PENDING'");
            $upd->execute([$signature, $token]);
            
            // Re-sync Job Total
            $db->prepare("UPDATE repair_jobs SET total_amount = (COALESCE((SELECT price FROM services WHERE service_id = repair_jobs.service_id), 0) + COALESCE((SELECT SUM(total_price) FROM repair_parts WHERE job_id = repair_jobs.job_id AND (approval_status = 'APPROVED' OR approval_status IS NULL)), 0)) WHERE job_id = ? AND tenant_id = ? AND status != 'SETTLED'")->execute([$jobId, $tenantId]);
            
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Additions approved successfully.']);
        } else if ($action === 'DENY') {
            $upd = $db->prepare("UPDATE repair_parts SET approval_status = 'DENIED' WHERE approval_token = ? AND approval_status = 'PENDING'");
            $upd->execute([$token]);
            
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Additions denied.']);
        } else {
            throw new Exception("Unknown action.");
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()]);
    }
    exit;
}

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Invalid or missing approval token.</h2></div>");
}

try {
    $stmt = $db->prepare("SELECT rp.*, COALESCE(i.item_name, s.service_name, 'Unknown Item') as item_name,
                                 j.tenant_id, COALESCE(v.plate_no, j.walkin_plate, 'N/A') as plate_no, c.full_name, t.shop_name 
                          FROM repair_parts rp 
                          LEFT JOIN inventory i ON rp.item_id = i.item_id
                          LEFT JOIN services s ON rp.service_id = s.service_id
                          LEFT JOIN repair_jobs j ON rp.job_id = j.job_id
                          LEFT JOIN vehicles v ON j.vehicle_id = v.vehicle_id
                          LEFT JOIN customers c ON j.customer_id = c.customer_id
                          LEFT JOIN tenants t ON rp.tenant_id = t.tenant_id
                          WHERE rp.approval_token = ?");
    $stmt->execute([$token]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) === 0) {
        die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>No items found for this link, or the link is invalid.</h2></div>");
    }

    $allPending = true;
    $allApproved = true;
    $allDenied = true;
    $totalAmount = 0;
    
    foreach ($items as $item) {
        if ($item['approval_status'] !== 'PENDING') $allPending = false;
        if ($item['approval_status'] !== 'APPROVED') $allApproved = false;
        if ($item['approval_status'] !== 'DENIED') $allDenied = false;
        $totalAmount += floatval($item['total_price']);
    }
    
    $tenantName = $items[0]['shop_name'];
    $plateNo = $items[0]['plate_no'];
    $customerName = trim($items[0]['full_name'] ?? '');
    
} catch (Throwable $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Approval - AutoFix Hub</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; margin: 0; padding: 20px; display:flex; justify-content:center; align-items:flex-start; min-height:100vh; }
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 30px; width: 100%; max-width: 500px; }
        .header { text-align: center; margin-bottom: 25px; }
        .header h1 { font-size: 1.5rem; color: #111827; margin:0 0 10px 0; }
        .header p { color: #6b7280; font-size: 0.95rem; margin:0; }
        .item-list { list-style: none; padding: 0; margin: 0 0 20px 0; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        .item-list li { display: flex; justify-content: space-between; padding: 15px; border-bottom: 1px solid #e5e7eb; }
        .item-list li:last-child { border-bottom: none; }
        .item-name { font-weight: 600; }
        .item-price { font-weight: 700; color: #10b981; }
        .total-row { display: flex; justify-content: space-between; padding: 15px; background: #f9fafb; font-weight: 800; font-size: 1.1rem; }
        
        .signature-section { margin-bottom: 20px; }
        .signature-label { display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
        .signature-clear { color: #ef4444; cursor: pointer; font-size: 0.8rem; background: none; border: none; }
        canvas { border: 2px dashed #d1d5db; border-radius: 8px; width: 100%; background: #fff; cursor: crosshair; touch-action: none; }
        
        .actions { display: flex; gap: 10px; margin-top: 20px; }
        button { flex: 1; padding: 14px; border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: 0.2s; }
        .btn-approve { background: #10b981; color: white; }
        .btn-approve:hover { background: #059669; }
        .btn-deny { background: #f3f4f6; color: #ef4444; border: 1px solid #fca5a5; }
        .btn-deny:hover { background: #fee2e2; }
        
        .status-alert { text-align: center; padding: 20px; border-radius: 8px; font-weight: 700; font-size: 1.1rem; }
        .status-approved { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-denied { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .status-mixed { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .loader { display:none; text-align:center; color:#6b7280; font-size:0.9rem; font-weight:600; margin-top:10px; }
    </style>
</head>
<body>

<div class="card">
    <div class="header">
        <h1>Review Requested Additions</h1>
        <p><strong><?php echo htmlspecialchars($tenantName); ?></strong> has requested to add the following items to your repair job (<?php echo htmlspecialchars($plateNo); ?>).</p>
    </div>

    <ul class="item-list">
        <?php foreach ($items as $item): ?>
            <li>
                <span class="item-name"><?php echo $item['quantity']; ?>x <?php echo htmlspecialchars($item['item_name']); ?></span>
                <span class="item-price">₱<?php echo number_format($item['total_price'], 2); ?></span>
            </li>
        <?php endforeach; ?>
        <div class="total-row">
            <span>Additional Cost:</span>
            <span style="color:#10b981;">₱<?php echo number_format($totalAmount, 2); ?></span>
        </div>
    </ul>

    <?php if (!$allPending): ?>
        <?php if ($allApproved): ?>
            <div class="status-alert status-approved">
                ✓ You have APPROVED these additions.
            </div>
        <?php elseif ($allDenied): ?>
            <div class="status-alert status-denied">
                ✕ You have DENIED these additions.
            </div>
        <?php else: ?>
            <div class="status-alert status-mixed">
                This request has already been processed.
            </div>
        <?php endif; ?>
    <?php else: ?>
        
        <?php $actionGet = $_GET['action'] ?? ''; ?>
        <?php if ($actionGet !== 'deny'): ?>
        <div class="signature-section">
            <div class="signature-label">
                <span>Please draw your signature to approve:</span>
                <button type="button" class="signature-clear" onclick="clearSignature()">Clear</button>
            </div>
            <canvas id="signatureCanvas" width="440" height="150"></canvas>
        </div>
        <?php endif; ?>

        <div class="actions" id="actionButtons">
            <?php if ($actionGet === 'deny'): ?>
                <button class="btn-deny" onclick="processAction('DENY')" style="width: 100%;">Confirm Rejection</button>
            <?php elseif ($actionGet === 'approve'): ?>
                <button class="btn-approve" onclick="processAction('APPROVE')" style="width: 100%;">Submit Approval</button>
            <?php else: ?>
                <button class="btn-deny" onclick="processAction('DENY')">Deny All</button>
                <button class="btn-approve" onclick="processAction('APPROVE')">Approve All</button>
            <?php endif; ?>
        </div>
        <div id="loader" class="loader">Processing... Please wait.</div>
        
        <script>
            // Signature Pad Logic
            const canvas = document.getElementById('signatureCanvas');
            let isDrawing = false;
            let hasSignature = false;
            let ctx = null;

            if (canvas) {
                ctx = canvas.getContext('2d');
                // Make line smoother
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#111827';

                function startPosition(e) {
                    isDrawing = true;
                    hasSignature = true;
                    draw(e);
                }

                function endPosition() {
                    isDrawing = false;
                    ctx.beginPath();
                }

                function draw(e) {
                    if (!isDrawing) return;
                    e.preventDefault();
                    
                    let clientX = e.clientX;
                    let clientY = e.clientY;
                    
                    if (e.touches && e.touches.length > 0) {
                        clientX = e.touches[0].clientX;
                        clientY = e.touches[0].clientY;
                    }
                    
                    const rect = canvas.getBoundingClientRect();
                    const x = clientX - rect.left;
                    const y = clientY - rect.top;

                    ctx.lineTo(x, y);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                }

                canvas.addEventListener('mousedown', startPosition);
                canvas.addEventListener('mouseup', endPosition);
                canvas.addEventListener('mousemove', draw);
                canvas.addEventListener('mouseleave', endPosition);

                canvas.addEventListener('touchstart', startPosition, {passive: false});
                canvas.addEventListener('touchend', endPosition);
                canvas.addEventListener('touchmove', draw, {passive: false});
            }

            window.clearSignature = function() {
                if (ctx && canvas) {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    hasSignature = false;
                }
            };

            window.processAction = function(action) {
                if (action === 'APPROVE' && !hasSignature) {
                    alert('Please draw your signature to approve the additions.');
                    return;
                }
                
                let confirmation = action === 'APPROVE' ? 
                    'Are you sure you want to APPROVE these additions? The cost will be added to your bill.' : 
                    'Are you sure you want to DENY these additions?';
                
                if (!confirm(confirmation)) return;
                
                document.getElementById('actionButtons').style.display = 'none';
                document.getElementById('loader').style.display = 'block';
                
                const signatureData = action === 'APPROVE' ? canvas.toDataURL('image/png') : '';
                
                const fd = new FormData();
                fd.append('token', '<?php echo $token; ?>');
                fd.append('action', action);
                fd.append('signature', signatureData);
                
                fetch('customer-approval.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(data => {
                        if (data.status === 'success') {
                            alert(data.message);
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                            document.getElementById('actionButtons').style.display = 'flex';
                            document.getElementById('loader').style.display = 'none';
                        }
                    }).catch(err => {
                        alert('Network error. Please try again.');
                        document.getElementById('actionButtons').style.display = 'flex';
                        document.getElementById('loader').style.display = 'none';
                    });
            };
        </script>
    <?php endif; ?>
</div>

</body>
</html>
