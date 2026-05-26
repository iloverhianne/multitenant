<?php
session_start();
require_once 'db-config.php';

$db = null;
try {
    $db = getDB();
} catch (Exception $e) {
    die("Database connection failed.");
}

// 1. Fetch Tenant Context
$slug = $_GET['id'] ?? '';
$tenant = null;

if ($slug) {
    $fallback_id = is_numeric($slug) ? (int)$slug : 0;
    $stmt = $db->prepare("SELECT tenant_id, shop_name FROM tenants WHERE slug = ? OR tenant_id = ? LIMIT 1");
    $stmt->execute([$slug, $fallback_id]);
    $tenant = $stmt->fetch();
}

$shop_name_display = $tenant ? $tenant['shop_name'] : 'AutoFix Shop';

// Actual Security Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'verify') {
    header('Content-Type: application/json');
    $otp = $_POST['otp'] ?? '';
    
    // Check if OTP matches what was sent to their email and stored in session
    if (isset($_SESSION['customer_temp_reg']) && $_SESSION['customer_temp_reg']['otp'] === $otp) {
        
        $customer_data = $_SESSION['customer_temp_reg'];
        
        try {
            $stmt = $db->prepare("INSERT INTO customers (tenant_id, full_name, mobile, email, password_hash)
                                  VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $customer_data['tenant_id'],
                $customer_data['fullname'],
                $customer_data['mobile'],
                $customer_data['email'],
                $customer_data['password_hash']
            ]);
            
            // Clear transient data to prevent reuse
            unset($_SESSION['customer_temp_reg']);
            
            echo json_encode(['status' => 'success', 'message' => 'Email verified successfully! Profile activated.']);
        } catch (PDOException $e) {
            $logData = date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . " - Data: " . json_encode($customer_data) . "\n";
            file_put_contents('customer_error.log', $logData, FILE_APPEND);
            if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                echo json_encode(['status' => 'error', 'message' => 'This email is already registered with this shop.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error while creating your profile.']);
            }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP Code. Please check the email we sent you.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verify Email | <?php echo htmlspecialchars($shop_name_display); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-deep: #030712;
            --accent: #6366f1; /* Main Indigo */
            --accent-glow: rgba(99, 102, 241, 0.4);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
            --input-bg: rgba(0, 0, 0, 0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }

        body {
            background-color: var(--bg-deep);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: radial-gradient(circle at top right, rgba(99, 102, 241, 0.1) 0%, transparent 60%);
        }

        .mobile-card {
            width: 100%;
            max-width: 400px;
            min-height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            padding: 3rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        @media (min-width: 480px) {
            .mobile-card {
                min-height: auto;
                height: auto;
                border: 1px solid var(--glass-border);
                border-radius: 30px;
                box-shadow: 0 40px 100px rgba(0, 0, 0, 0.6);
                padding: 4rem 3rem;
            }
        }

        .icon-box {
            width: 80px; height: 80px; background: rgba(99, 102, 241, 0.1); border: 2px solid var(--accent);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem; margin: 0 auto 1.5rem; color: var(--accent);
            box-shadow: 0 0 30px var(--accent-glow);
        }

        h1 { font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 12px; line-height: 1.2; }
        p.subtitle { color: var(--text-dim); font-size: 0.95rem; line-height: 1.5; margin-bottom: 2rem; }

        .otp-container { display: flex; justify-content: center; gap: 8px; margin-bottom: 2rem; }
        .otp-input {
            width: 45px; height: 55px; font-size: 1.5rem; font-weight: 800; text-align: center;
            background: var(--input-bg); border: 1px solid var(--glass-border); color: white;
            border-radius: 12px; outline: none; transition: 0.3s;
        }
        .otp-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.2); }

        .btn-main {
            width: 100%; background: linear-gradient(135deg, var(--accent), #8b5cf6); color: white; border: none; padding: 1.2rem;
            border-radius: 16px; font-size: 1.1rem; font-weight: 800; cursor: pointer;
            transition: 0.3s; box-shadow: 0 10px 20px -5px var(--accent-glow); margin-bottom: 1.5rem;
        }
        .btn-main:active { transform: scale(0.98); }

        .resend-link { color: var(--text-dim); font-size: 0.9rem; text-decoration: none; font-weight: 600; }
        .resend-link span { color: var(--accent); }

        /* Success Overlay */
        .success-overlay {
            position: absolute; top:0; left:0; width:100%; height:100%; 
            background: rgba(3, 7, 18, 0.95); backdrop-filter: blur(10px);
            border-radius: inherit; display: none; flex-direction: column; 
            align-items: center; justify-content: center; text-align: center;
            padding: 2rem; z-index: 50; animation: fadeIn 0.4s ease forwards;
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        #toast {
            position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 350px; text-align: center;
            background: rgba(239, 68, 68, 0.95); color: white; padding: 1rem;
            border-radius: 16px; font-weight: 600; transition: 0.4s; z-index: 1000;
        }
        #toast.show { bottom: 30px; }
    </style>
</head>
<body>

    <div class="mobile-card" style="position:relative;">
        <div class="icon-box"><i class="fas fa-envelope-open-text"></i></div>
        
        <h1>Verify your Email</h1>
        <p class="subtitle">We've sent a secure 6-digit code to your registered email address. Enter it below to activate your account.</p>

        <form id="verifyForm">
            <!-- Hidden real input for the AJAX POST -->
            <input type="hidden" id="fullOtp" name="fullOtp" required>
            
            <div class="otp-container">
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
                <input type="tel" class="otp-input" maxlength="1" pattern="[0-9]" required>
            </div>

            <button type="submit" class="btn-main" id="verifyBtn">Verify & Activate</button>
        </form>

        <a href="javascript:void(0)" class="resend-link" onclick="showToast('Verification code resent!')">Didn't receive code? <span>Resend</span></a>

        <!-- Success Message (Shown via JS upon valid OTP) -->
        <div class="success-overlay" id="successScreen">
            <div class="icon-box" style="background: rgba(16, 185, 129, 0.1); border-color: #10b981; color: #10b981; box-shadow: 0 0 30px rgba(16, 185, 129, 0.4);">
                <i class="fas fa-check"></i>
            </div>
            <h1 style="color:white; margin-bottom: 10px;">Account Ready!</h1>
            <p style="color:var(--text-dim); margin-bottom: 2rem;">Your customer profile has been verified. Download the AutoFix Hub mobile app to book appointments and track your vehicle.</p>
            
            <?php
                // Construct the base URL for the APK download
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
                $download_url = rtrim($base_url, '/') . "/AutofixHub.apk"; // This is where the user should put their Android Studio APK
                
                // Use a free QR code generator API (QRServer) to visually render the link
                $qr_img_url = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode($download_url);
            ?>

            <div style="background: white; padding: 12px; border-radius: 20px; display: inline-block; margin-bottom: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <img src="<?php echo $qr_img_url; ?>" alt="Scan to Download APK" style="width: 150px; height: 150px; display: block; border-radius: 10px;">
            </div>

            <a href="<?php echo $download_url; ?>" class="btn-main" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; text-decoration:none; margin-bottom: 1rem; padding: 1rem;">
                <i class="fas fa-mobile-alt"></i> Download Mobile App
            </a>

            <div style="margin-bottom: 2rem;">
                <a href="<?php echo $download_url; ?>" style="color:var(--text-dim); font-size: 0.8rem; text-decoration: underline;">Or click here if download doesn't start</a>
            </div>

            <button onclick="window.location.replace('shop.php?id=<?php echo urlencode($slug); ?>')" class="btn-main" style="background: transparent; border: 1px solid var(--text-dim); color: var(--text-main); box-shadow:none; padding: 0.8rem;">Continue to Shop</button>
        </div>
    </div>

    <div id="toast">Error message</div>

    <script>
        // OTP Input Auto-Tab Logic
        const inputs = document.querySelectorAll('.otp-input');
        const hiddenOtp = document.getElementById('fullOtp');

        inputs.forEach((input, index) => {
            input.addEventListener('keyup', (e) => {
                const val = input.value;
                if (val.match(/[0-9]/)) {
                    if (index < inputs.length - 1) inputs[index + 1].focus();
                } else if (e.key === 'Backspace') {
                    if (index > 0) inputs[index - 1].focus();
                }
                
                // Collect value into hidden input
                let code = '';
                inputs.forEach(i => code += i.value);
                hiddenOtp.value = code;
            });
            
            // Limit to numeric only
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        });

        // Form Submit
        document.getElementById('verifyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('verifyBtn');
            const code = hiddenOtp.value;

            if(code.length !== 6) {
                showToast('Please enter all 6 digits.');
                return;
            }

            btn.innerText = 'Verifying...';
            btn.style.opacity = '0.7';

            const formData = new FormData();
            formData.append('otp', code);
            
            fetch('customer-verify.php?action=verify', {
                method: 'POST', body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    // Show success screen blocking the UI
                    document.getElementById('successScreen').style.display = 'flex';
                } else {
                    showToast(data.message);
                    btn.innerText = 'Verify & Activate';
                    btn.style.opacity = '1';
                }
            })
            .catch(err => {
                showToast('Network error.');
                btn.innerText = 'Verify & Activate';
                btn.style.opacity = '1';
            });
        });

        function showToast(msg) {
            const toast = document.getElementById('toast');
            toast.innerText = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
