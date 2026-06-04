<?php
session_start();
require_once 'mailer-service.php';

// Persist registration data in session so it survives refreshes/resends
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    // If it's a fresh POST from the registration form, reset the OTP flow
    if (!isset($_POST['resend'])) {
        unset($_SESSION['otp_code']);
        unset($_SESSION['mail_status']);
    }

    $business_proof_path = '';
    $id_photo_path = '';
    $uploadDir = __DIR__ . '/uploads/tenants/';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0755, true);

    if (!empty($_FILES['business_proof']['name'])) {
        $ext = pathinfo($_FILES['business_proof']['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($_FILES['business_proof']['tmp_name'], $uploadDir . $filename)) {
            $business_proof_path = 'uploads/tenants/' . $filename;
        }
    }

    if (!empty($_FILES['id_photo']['name'])) {
        $ext = pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'id_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($_FILES['id_photo']['tmp_name'], $uploadDir . $filename)) {
            $id_photo_path = 'uploads/tenants/' . $filename;
        } else {
            error_log("Failed to move id_photo: " . $_FILES['id_photo']['error']);
        }
    }

    $_SESSION['reg_data'] = [
        'shop_name' => $_POST['shop_name'] ?? '',
        'owner_name' => $_POST['owner_name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'contact' => $_POST['contact'] ?? '',
        'address' => $_POST['address'] ?? '',
        'plan' => $_POST['plan'] ?? '',
        'plan_id' => $_POST['plan_id'] ?? '',
        'billing_cycle' => $_POST['billing_cycle'] ?? 'monthly',
        'password' => $_POST['password'] ?? '',
        'id_type' => $_POST['id_type'] ?? '',
        'business_proof_url' => $business_proof_path,
        'id_photo_url' => $id_photo_path
    ];
}

$reg_data = $_SESSION['reg_data'] ?? [];
$shop_name = $reg_data['shop_name'] ?? '';
$owner_name = $reg_data['owner_name'] ?? '';
$email = $reg_data['email'] ?? '';
$contact = $reg_data['contact'] ?? '';
$address = $reg_data['address'] ?? '';
$plan = $reg_data['plan'] ?? '';
$plan_id = $reg_data['plan_id'] ?? '';
$billing_cycle = $reg_data['billing_cycle'] ?? 'monthly';
$password = $reg_data['password'] ?? '';
$id_type = $reg_data['id_type'] ?? '';
$business_proof_url = $reg_data['business_proof_url'] ?? '';
$id_photo_url = $reg_data['id_photo_url'] ?? '';

// Generate a REAL 6-digit random code if not exist or failed before
$shouldSend = !isset($_SESSION['otp_code']) ||
    isset($_POST['resend']) ||
    ($_SESSION['mail_status'] ?? '') !== 'sent';

if ($shouldSend) {
    if (!isset($_SESSION['otp_code']) || isset($_POST['resend'])) {
        $generated_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_code'] = $generated_code;
    } else {
        $generated_code = $_SESSION['otp_code'];
    }

    // Clear old diagnostic info
    unset($_SESSION['api_error']);
    unset($_SESSION['debug_info']);

    // Attempt to send
    $mailSent = Mailer::sendOTP($email, $generated_code);
    $_SESSION['mail_status'] = $mailSent ? 'sent' : 'failed';
} else {
    $generated_code = $_SESSION['otp_code'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.3);
            --text-dim: rgba(255, 255, 255, 0.6);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
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

        .verify-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 3rem;
            border-radius: 32px;
            width: 100%;
            max-width: 450px;
            text-align: center;
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }

        p {
            color: var(--text-dim);
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .email-highlight {
            color: var(--accent);
            font-weight: 700;
        }

        .otp-container {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .otp-input {
            width: 50px;
            height: 60px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: white;
            font-size: 1.5rem;
            font-weight: 800;
            text-align: center;
            outline: none;
            transition: all 0.3s;
        }

        .otp-input:focus {
            border-color: var(--accent);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 15px var(--accent-glow);
        }

        .btn-verify {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            padding: 1.2rem;
            border-radius: 16px;
            font-weight: 800;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px var(--accent-glow);
        }

        .resend {
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: var(--text-dim);
        }

        .resend a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 700;
        }

        .error-msg {
            color: #ff4d4d;
            font-size: 0.85rem;
            margin-top: 1rem;
            display: none;
        }
    </style>
</head>

<body>

    <div class="verify-card">
        <?php if (isset($_SESSION['mail_status']) && $_SESSION['mail_status'] == 'sent'): ?>
            <div
                style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 10px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <span>✅</span> Verification code sent successfully!
            </div>
        <?php elseif (isset($_SESSION['mail_status']) && $_SESSION['mail_status'] == 'failed'): ?>
            <div
                style="background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 20px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.5rem; text-align: left; border: 1px solid rgba(239, 68, 68, 0.3);">
                <p style="margin: 0 0 10px 0; font-weight: 800; font-size: 1rem;">❌ Email Sending Failed</p>

                <div style="padding: 10px; background: rgba(0,0,0,0.3); border-radius: 8px; margin-top: 10px;">
                    <p style="margin: 0; font-weight: 700; color: #fff;">System Check:</p>
                    <ul style="margin: 5px 0; padding-left: 20px; opacity: 0.8;">
                        <li>cURL Enabled: <?php echo function_exists('curl_init') ? '✅' : '❌'; ?></li>
                        <li>URL Fopen: <?php echo ini_get('allow_url_fopen') ? '✅' : '❌'; ?></li>
                        <li>API Key Set: <?php echo (SMTP_PASS != 'your-password' && SMTP_PASS != '') ? '✅' : '❌'; ?></li>
                    </ul>
                </div>

                <?php if (isset($_SESSION['debug_info'])): ?>
                    <p style="margin: 10px 0 0 0; font-weight: 700; color: #fff;">Technical Error:</p>
                    <div
                        style="font-family: monospace; font-size: 0.7rem; margin-top: 5px; background: #000; padding: 10px; border-radius: 5px; word-break: break-all; max-height: 100px; overflow-y: auto;">
                        <?php echo htmlspecialchars($_SESSION['debug_info']); ?>
                    </div>
                <?php endif; ?>

                <button onclick="window.location.reload()"
                    style="width: 100%; margin-top: 15px; background: #ef4444; color: white; border: none; padding: 8px; border-radius: 8px; cursor: pointer; font-weight: 700;">Try
                    Again</button>
            </div>
        <?php endif; ?>

        <div style="font-size: 3rem; margin-bottom: 1.5rem;">✉️</div>
        <h2>Verify Your Email</h2>
        <p>We've sent a 6-digit verification code to <br><span
                class="email-highlight"><?php echo htmlspecialchars($email); ?></span></p>

        <form id="otpForm" action="checkout.php" method="POST">
            <!-- Pass through all registration data -->
            <input type="hidden" name="shop_name" value="<?php echo htmlspecialchars($shop_name); ?>">
            <input type="hidden" name="owner_name" value="<?php echo htmlspecialchars($owner_name); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="contact" value="<?php echo htmlspecialchars($contact); ?>">
            <input type="hidden" name="address" value="<?php echo htmlspecialchars($address); ?>">
            <input type="hidden" name="plan" value="<?php echo htmlspecialchars($plan); ?>">
            <input type="hidden" name="plan_id" value="<?php echo htmlspecialchars($plan_id); ?>">
            <input type="hidden" name="billing_cycle" value="<?php echo htmlspecialchars($billing_cycle); ?>">
            <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
            <input type="hidden" name="id_type" value="<?php echo htmlspecialchars($id_type); ?>">
            <input type="hidden" name="business_proof_url" value="<?php echo htmlspecialchars($business_proof_url); ?>">
            <input type="hidden" name="id_photo_url" value="<?php echo htmlspecialchars($id_photo_url); ?>">

            <div class="otp-container">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 1)"
                    onkeydown="moveBack(this, 0)">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 2)"
                    onkeydown="moveBack(this, 1)">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 3)"
                    onkeydown="moveBack(this, 2)">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 4)"
                    onkeydown="moveBack(this, 3)">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 5)"
                    onkeydown="moveBack(this, 4)">
                <input type="text" class="otp-input" maxlength="1" oninput="moveNext(this, 6)"
                    onkeydown="moveBack(this, 5)">
            </div>

            <p id="errorMsg" class="error-msg">Invalid verification code. Please try again.</p>

            <button type="button" onclick="validateOTP()" class="btn-verify">Verify & Proceed to Payment</button>
        </form>

        <div class="resend">
            Didn't receive the code?
            <form method="POST" style="display:inline;">
                <input type="hidden" name="resend" value="1">
                <button type="submit"
                    style="background:none; border:none; color:var(--accent); cursor:pointer; font-weight:700; font-family:inherit; padding:0; font-size:inherit; text-decoration:underline;">Resend
                    Code</button>
            </form>
        </div>
    </div>

    <script>
        function moveNext(input, index) {
            if (input.value.length === 1 && index < 6) {
                document.querySelectorAll('.otp-input')[index].focus();
            }
        }

        function moveBack(input, index) {
            if (event.key === "Backspace" && input.value.length === 0 && index >= 0) {
                document.querySelectorAll('.otp-input')[index].focus();
            }
        }

        function validateOTP() {
            let otp = "";
            document.querySelectorAll('.otp-input').forEach(input => {
                otp += input.value;
            });

            // Verify against the real generated code
            const serverCode = "<?php echo $generated_code; ?>";
            if (otp === serverCode) {
                document.getElementById('otpForm').submit();
            } else {
                const errorEl = document.getElementById('errorMsg');
                errorEl.style.display = 'block';

                // Shake effect
                const card = document.querySelector('.verify-card');
                card.style.animation = 'none';
                card.offsetHeight; /* trigger reflow */
                card.style.animation = 'shake 0.5s';
            }
        }
    </script>

    <style>
        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-10px);
            }

            75% {
                transform: translateX(10px);
            }
        }
    </style>
</body>

</html>