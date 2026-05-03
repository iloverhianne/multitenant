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
$tenant_id_context = $tenant ? $tenant['tenant_id'] : null;

// AJAX Registration Hook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'register') {
    header('Content-Type: application/json');
    
    $fullname = $_POST['fullname'] ?? '';
    $mobile = $_POST['mobile'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $tenant_id = $_GET['tid'] ?? null;

    if (empty($fullname) || empty($mobile) || empty($email) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    if (empty($tenant_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Unable to determine shop context. Please refresh the page from the shop link.']);
        exit;
    }

    // Check if email already registered for this tenant
    $checkStmt = $db->prepare("SELECT customer_id FROM customers WHERE tenant_id = ? AND email = ? LIMIT 1");
    $checkStmt->execute([$tenant_id, $email]);
    if ($checkStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'This email is already registered with this shop.']);
        exit;
    }

    require_once 'mailer-service.php';
    
    // Generate 6-digit OTP
    $otp = sprintf("%06d", mt_rand(100000, 999999));
    
    // Send email via Mailer Service
    $mailSent = Mailer::sendOTP($email, $otp);
    
    if ($mailSent) {
        // Store transient data in session
        $_SESSION['customer_temp_reg'] = [
            'tenant_id' => $tenant_id,
            'fullname' => $fullname,
            'mobile' => $mobile,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'otp' => $otp
        ];
        
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email.']);
    } else {
        $debug = $_SESSION['debug_info'] ?? 'Unknown error';
        echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP email: ' . $debug]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customer Register | <?php echo htmlspecialchars($shop_name_display); ?></title>
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
        }

        @media (min-width: 480px) {
            .mobile-card {
                min-height: auto;
                height: auto;
                border: 1px solid var(--glass-border);
                border-radius: 30px;
                box-shadow: 0 40px 100px rgba(0, 0, 0, 0.6);
            }
        }

        .header-area { margin-bottom: 2.5rem; }
        
        h1 { font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px; line-height: 1.2; }
        p.subtitle { color: var(--text-dim); font-size: 0.9rem; line-height: 1.5;}

        .form-group { margin-bottom: 1.2rem; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper i.icon-left { position: absolute; left: 1.2rem; color: var(--text-dim); font-size: 1.1rem; z-index: 5; }

        .form-group input {
            width: 100%; background: var(--input-bg); border: 1px solid var(--glass-border);
            color: white; padding: 1.1rem 1.2rem 1.1rem 3.2rem; border-radius: 12px; font-size: 0.95rem;
            transition: 0.3s; outline: none;
        }

        .form-group input:focus { border-color: var(--accent); background: rgba(0, 0, 0, 0.6); }

        .btn-main {
            width: 100%; background: linear-gradient(135deg, var(--accent), #8b5cf6); color: white; border: none; padding: 1.2rem;
            border-radius: 16px; font-size: 1.1rem; font-weight: 800; cursor: pointer;
            transition: 0.3s; margin-top: 1rem; box-shadow: 0 10px 20px -5px var(--accent-glow);
        }
        .btn-main:active { transform: scale(0.98); }

        .bottom-links {
            text-align: center; margin-top: 2rem; color: var(--text-dim); font-size: 0.9rem;
        }
        .bottom-links a { color: var(--accent); font-weight: 700; text-decoration: none; margin-left: 5px; }
        
        #toast {
            position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 350px; text-align: center;
            background: rgba(239, 68, 68, 0.95); color: white; padding: 1rem;
            border-radius: 16px; font-weight: 600; transition: 0.4s; z-index: 1000;
        }
        #toast.show { bottom: 30px; }
    </style>
</head>
<body>

    <div class="mobile-card">
        <a href="shop.php?id=<?php echo urlencode($slug); ?>" style="color: var(--text-dim); text-decoration: none; font-size: 1.2rem; margin-bottom: 1rem; display: inline-block;">
            <i class="fas fa-arrow-left"></i>
        </a>
        
        <div class="header-area">
            <h1>Create Account</h1>
            <p class="subtitle">Join <strong><?php echo htmlspecialchars($shop_name_display); ?></strong> to track your car's repair history and book online.</p>
            <div style="font-size: 0.7rem; color: var(--accent); margin-top: 10px; border: 1px solid var(--accent); display: inline-block; padding: 2px 8px; border-radius: 5px;">
                Shop Context ID: <?php echo $tenant_id_context ?: 'Not Found'; ?>
            </div>
        </div>

        <form id="customerRegister">
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-user icon-left"></i>
                    <input type="text" id="fullname" placeholder="Full Name" required>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-mobile-alt icon-left"></i>
                    <input type="tel" id="mobile" placeholder="Mobile Number (e.g. 0917xxx)" required>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-envelope icon-left"></i>
                    <input type="email" id="email" placeholder="Email Address" required>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" id="password" placeholder="Create Password" required>
                </div>
            </div>

            <button type="submit" class="btn-main" id="regBtn">Create Account</button>
        </form>

        <div class="bottom-links" style="background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 20px; border: 1px solid var(--glass-border);">
            Already have an account? <br>
            <a href="customer-portal.php?id=<?php echo urlencode($slug); ?>" style="display:inline-block; margin-top:10px; background: var(--accent); color: white; padding: 8px 20px; border-radius: 100px; text-decoration: none; font-size: 0.8rem;">
                <i class="fas fa-mobile-screen-button"></i> Login via App
            </a>
        </div>
    </div>

    <div id="toast">Error message</div>

    <script>
        document.getElementById('customerRegister').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('regBtn');
            btn.innerText = 'Sending OTP...';
            btn.style.opacity = '0.7';

            const formData = new FormData();
            formData.append('fullname', document.getElementById('fullname').value);
            formData.append('mobile', document.getElementById('mobile').value);
            formData.append('email', document.getElementById('email').value);
            formData.append('password', document.getElementById('password').value);
            
            fetch('customer-register.php?action=register&tid=<?php echo urlencode($tenant_id_context ?? ''); ?>', {
                method: 'POST', body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    setTimeout(() => {
                        window.location.replace('customer-verify.php?id=<?php echo urlencode($slug); ?>');
                    }, 1500);
                } else {
                    showToast(data.message);
                    btn.innerText = 'Create Account';
                    btn.style.opacity = '1';
                }
            })
            .catch(err => {
                showToast('Network error.');
                btn.innerText = 'Create Account';
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
