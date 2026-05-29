<?php
require_once 'db-config.php';
$shop_name = $_POST['shop_name'] ?? 'Your Shop';
$owner_name = $_POST['owner_name'] ?? 'Owner';
$email = $_POST['email'] ?? '';
$plan_id = $_POST['plan'] ?? 2;
$billing_cycle = $_POST['billing_cycle'] ?? 'monthly';
$password = $_POST['password'] ?? '';

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE plan_id = ?");
    $stmt->execute([$plan_id]);
    $plan_data = $stmt->fetch();
    
    if ($plan_data) {
        $plan_name = $plan_data['plan_name'];
        $plan_price = ($billing_cycle === 'yearly') ? $plan_data['price_yearly'] : $plan_data['price'];
    } else {
        $plan_name = "Pro Auto Shop";
        $plan_price = 6499;
    }
} catch (Exception $e) {
    $plan_name = "Pro Auto Shop";
    $plan_price = 6499;
}
$period = ucfirst($billing_cycle);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Checkout | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --accent: #6366f1;
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
            background-image: radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 50%),
                              radial-gradient(circle at 90% 80%, rgba(79, 70, 229, 0.05) 0%, transparent 50%);
        }

        .checkout-container {
            width: 100%;
            max-width: 900px;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 50px 100px -20px rgba(0,0,0,0.5);
        }

        .payment-methods {
            padding: 3rem;
        }

        .order-summary {
            background: rgba(255,255,255,0.02);
            padding: 3rem;
            border-left: 1px solid var(--glass-border);
        }

        .paymongo-logo {
            color: #6366f1;
            font-weight: 800;
            font-size: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h2 { margin-bottom: 0.5rem; font-weight: 800; }
        p { color: var(--text-dim); margin-bottom: 2rem; }

        .method-item {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--glass-border);
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .method-item:hover {
            border-color: var(--accent);
            background: rgba(99, 102, 241, 0.1);
        }

        .method-item.active {
            border-color: var(--accent);
            background: rgba(99, 102, 241, 0.15);
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.2);
        }

        .method-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #000;
        }

        .card-details {
            margin-top: 2rem;
            display: none;
        }

        .card-details.active { display: block; }

        .form-group { margin-bottom: 1rem; }
        label { display: block; font-size: 0.8rem; margin-bottom: 0.5rem; color: var(--text-dim); }
        input {
            width: 100%;
            padding: 1rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: white;
            box-sizing: border-box;
        }

        .btn-pay {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            padding: 1.2rem;
            border-radius: 16px;
            font-weight: 800;
            font-size: 1.1rem;
            margin-top: 2rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .total {
            font-size: 1.5rem;
            font-weight: 800;
            margin-top: 2rem;
            display: flex;
            justify-content: space-between;
        }

        @media (max-width: 768px) {
            .checkout-container { grid-template-columns: 1fr; }
            .order-summary { border-left: none; border-top: 1px solid var(--glass-border); }
        }
    </style>
</head>
<body>

    <div class="checkout-container">
        <div class="payment-methods">
            <a href="index.php#pricing" style="color: var(--text-dim); text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; margin-bottom: 2rem; transition: color 0.3s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='var(--text-dim)'">
                <span style="font-size: 1.2rem;">←</span> Back to Pricing / Change Plan
            </a>
            <div class="paymongo-logo">
                <span style="font-size: 1.8rem;">📦</span> PayMongo CheckOut
            </div>
            <h2>Select Payment Method</h2>
            <p>Secure SSL Encrypted Environment</p>

            <div class="method-item active" onclick="selectMethod('card', this)">
                <div class="method-icon" style="background: #fff; color: #0056b3;">VISA</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700;">Credit / Debit Card</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">Visa, Mastercard, AMEX</div>
                </div>
            </div>

            <div class="method-item" onclick="selectMethod('gcash', this)">
                <div class="method-icon" style="background: #2854b4; color: #fff;">G</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700;">GCash</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">Direct E-Wallet Transfer</div>
                </div>
            </div>

            <div class="method-item" onclick="selectMethod('maya', this)">
                <div class="method-icon" style="background: #000; color: #20ff74;">M</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700;">Maya</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">Digital Wallet Payment</div>
                </div>
            </div>

            <div id="cardDetails" class="card-details active">
                <div class="form-group">
                    <label>CARD NUMBER</label>
                    <input type="text" placeholder="XXXX XXXX XXXX XXXX" value="4111 1111 1111 1111">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>EXPIRY</label>
                        <input type="text" placeholder="MM/YY" value="12/28">
                    </div>
                    <div class="form-group">
                        <label>CVC</label>
                        <input type="text" placeholder="XXX" value="123">
                    </div>
                </div>
            </div>

            <form action="onboarding-success.php" method="POST">
                <input type="hidden" name="shop_name" value="<?php echo htmlspecialchars($shop_name); ?>">
                <input type="hidden" name="owner_name" value="<?php echo htmlspecialchars($owner_name); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="plan" value="<?php echo htmlspecialchars($plan_name); ?>">
                <input type="hidden" name="plan_id" value="<?php echo htmlspecialchars($plan_id); ?>">
                <input type="hidden" name="billing_cycle" value="<?php echo htmlspecialchars($billing_cycle); ?>">
                <input type="hidden" name="price" value="<?php echo htmlspecialchars($plan_price); ?>">
                <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
                <input type="hidden" name="address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                <input type="hidden" name="id_type" value="<?php echo htmlspecialchars($_POST['id_type'] ?? ''); ?>">
                <input type="hidden" name="business_proof_url" value="<?php echo htmlspecialchars($_POST['business_proof_url'] ?? ''); ?>">
                <input type="hidden" name="permit_expiry_date" value="<?php echo htmlspecialchars($_POST['permit_expiry_date'] ?? ''); ?>">
                <input type="hidden" name="dti_proof_url" value="<?php echo htmlspecialchars($_POST['dti_proof_url'] ?? ''); ?>">
                <input type="hidden" name="dti_expiry_date" value="<?php echo htmlspecialchars($_POST['dti_expiry_date'] ?? ''); ?>">
                <input type="hidden" name="id_photo_url" value="<?php echo htmlspecialchars($_POST['id_photo_url'] ?? ''); ?>">
                <input type="hidden" name="id_expiry_date" value="<?php echo htmlspecialchars($_POST['id_expiry_date'] ?? ''); ?>">
                <input type="hidden" name="payment_method" id="hiddenPaymentMethod" value="card">
                
                <button type="submit" class="btn-pay" id="payButton">
                    Pay ₱<?php echo number_format($plan_price, 2); ?> Now
                </button>
            </form>
        </div>

        <div class="order-summary">
            <h3>Order Summary</h3>
            <div style="margin: 2.5rem 0;">
                <div class="summary-item">
                    <div>
                        <div style="font-weight: 700;"><?php echo htmlspecialchars($plan_name); ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-dim);">Subscription (<?php echo $period; ?>)</div>
                    </div>
                    <strong>₱<?php echo number_format($plan_price, 2); ?></strong>
                </div>
                <div class="summary-item">
                    <div>Onboarding Fee</div>
                    <strong>₱0.00</strong>
                </div>
                <div class="summary-item">
                    <div>System Tax (12%)</div>
                    <strong>Included</strong>
                </div>
            </div>

            <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 16px; margin-bottom: 2rem;">
                <div style="font-size: 0.8rem; color: var(--text-dim); margin-bottom: 0.5rem;">TO BE PROVISIONED FOR:</div>
                <div style="font-weight: 700; color: var(--accent); text-transform: uppercase;"><?php echo htmlspecialchars($shop_name); ?></div>
                <div style="font-size: 0.9rem;"><?php echo htmlspecialchars($owner_name); ?></div>
            </div>

            <div class="total">
                <span>Total Due</span>
                <span>₱<?php echo number_format($plan_price, 2); ?></span>
            </div>
            
            <div style="margin-top: 3rem; text-align: center;">
                <img src="https://paymongo.com/wp-content/uploads/2021/04/PayMongo-Checkout-Final-Logo-2.png" style="width: 150px; opacity: 0.3; filter: grayscale(1);">
            </div>
        </div>
    </div>

    <script>
        function selectMethod(method, element) {
            document.querySelectorAll('.method-item').forEach(i => i.classList.remove('active'));
            element.classList.add('active');
            
            const cardDetails = document.getElementById('cardDetails');
            const payButton = document.getElementById('payButton');
            
            if (method === 'card') {
                cardDetails.classList.add('active');
                payButton.innerText = 'Pay ₱<?php echo number_format($plan_price, 2); ?> Now';
                document.getElementById('hiddenPaymentMethod').value = 'card';
            } else {
                cardDetails.classList.remove('active');
                payButton.innerText = 'Authorize ' + method.toUpperCase() + ' Payment';
                document.getElementById('hiddenPaymentMethod').value = method;
            }
        }
    </script>
</body>
</html>
