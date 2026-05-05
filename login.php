<?php
session_start();
ob_start();
require_once 'db-config.php';

$from = $_GET['from'] ?? '';
$tid = $_GET['tid'] ?? '';

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $from = $_GET['from'] ?? '';
    session_destroy();
    header("Location: login.php" . ($from ? "?from=" . urlencode($from) : ""));
    exit;
}

// Handle AJAX Login Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    header('Content-Type: application/json');
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    $from = $_GET['from'] ?? '';
    $tid = $_GET['tid'] ?? '';

    try {
        $db = getDB();
        
        // Super Admin Check
        // 1. Hardcoded check (Fallback/Emergency)
        if ($user === 'superadmin' && $pass === 'admin123') {
            $_SESSION['user_id'] = '1';
            $_SESSION['role'] = 'SUPER_ADMIN';
            $_SESSION['name'] = 'Main Admin';
            $_SESSION['shop_name'] = 'AutoFix Hub';
            $_SESSION['isLoggedIn'] = true;
            
            // LOG LOGIN
            try { $db->prepare("INSERT INTO audit_logs (activity_type, description) VALUES ('AUTH', 'Super Admin logged in (Hardcoded Emergency Account)')")->execute(); } catch(Exception $e){}

            session_write_close();
            echo json_encode(['status' => 'success', 'role' => 'SUPER_ADMIN', 'redirect' => 'dashboard.php']);
            exit;
        }

        // 2. Database check for Super Admins and Staff
        $stmt = $db->prepare("SELECT u.*, r.role_name, t.shop_name, t.status AS tenant_status
                               FROM users u 
                               JOIN roles r ON u.role_id = r.role_id 
                               LEFT JOIN tenants t ON u.tenant_id = t.tenant_id
                               WHERE u.email = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch();

        if ($row) {
            if (password_verify($pass, $row['password_hash'])) {
                $isOwnerOrManager = in_array(strtoupper($row['role_name']), ['OWNER', 'MANAGER', 'SUPER_ADMIN']);
                $isTenantSuspended = isset($row['tenant_status']) && strtoupper($row['tenant_status']) === 'SUSPENDED';

                // Check User Account Status
                if (strtoupper($row['status']) !== 'ACTIVE') {
                    // EXCEPTION: Allow Owners/Managers if their shop is suspended
                    if (!($isOwnerOrManager && $isTenantSuspended)) {
                        echo json_encode(['status' => 'error', 'message' => 'Account is deactivated. (Role: ' . $row['role_name'] . ', Shop Status: ' . $row['tenant_status'] . ')']);
                        exit;
                    }
                }

                // Check for Suspended Workshop Access Policy
                if ($isTenantSuspended && !$isOwnerOrManager) {
                    echo json_encode(['status' => 'error', 'message' => 'Workshop suspended. Please contact the owner.']);
                    exit;
                }

            // Check if it's a Super Admin (role_id 1 AND tenant_id is NULL)
            if ($row['role_id'] == 1 && ($row['tenant_id'] === null || $row['tenant_id'] == 0)) {
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['role'] = 'SUPER_ADMIN';
                $_SESSION['name'] = $row['name'];
                $_SESSION['shop_name'] = 'AutoFix Hub';
                $_SESSION['isLoggedIn'] = true;

                // LOG LOGIN
                try { $db->prepare("INSERT INTO audit_logs (user_id, activity_type, description) VALUES (?, 'AUTH', 'Super Admin logged in to Hub Console')")->execute([$row['user_id']]); } catch(Exception $e){}

                session_write_close();
                echo json_encode(['status' => 'success', 'role' => 'SUPER_ADMIN', 'redirect' => 'dashboard.php']);
                exit;
            }

            if ($tid && $row['tenant_id'] != $tid) {
                echo json_encode(['status' => 'error', 'message' => 'User not associated with this workshop.']);
                exit;
            }
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['tenant_id'] = $row['tenant_id'];
            $_SESSION['role'] = $row['role_name'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['shop_name'] = $row['shop_name'] ?? 'Super Admin';
            $_SESSION['isLoggedIn'] = true;

            // LOG LOGIN
            try {
                $logMsg = $row['name'] . " (" . strtoupper($row['role_name']) . ") logged in to " . ($row['shop_name'] ?? 'Workshop');
                $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, activity_type, description) VALUES (?, ?, 'AUTH', ?)")
                   ->execute([$row['tenant_id'], $row['user_id'], $logMsg]);
            } catch(Exception $e){}

            session_write_close();
            echo json_encode([
                'status' => 'success',
                'role' => $row['role_name'],
                'redirect' => (strtoupper($row['role_name']) === 'SUPER_ADMIN') ? 'dashboard.php' : 'tenant-dashboard.php'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid credentials (password mismatch).']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials (email not found).']);
    }
} catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'System error.']); }
exit;
}

// FETCH SHOP BRANDING & ACTUAL COLORS & SLUG
$shop_name = '';
$shop_logo = '';
$shop_slug = '';
$primary_color = '#6366f1'; 
$secondary_color = '#020617';

if (($tid ?? '')) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT shop_name, logo_url, slug, primary_color, secondary_color FROM tenants WHERE tenant_id = ?");
        $stmt->execute([$tid]);
        $res = $stmt->fetch();
        if ($res) {
            $shop_name = $res['shop_name'];
            $shop_logo = $res['logo_url'];
            $shop_slug = $res['slug'];
            if (!empty($res['primary_color'])) $primary_color = $res['primary_color'];
            if (!empty($res['secondary_color'])) $secondary_color = $res['secondary_color'];
        }
    } catch (Exception $e) {}
}

if (strpos($primary_color, '#') !== 0) $primary_color = '#' . $primary_color;
if (strpos($secondary_color, '#') !== 0) $secondary_color = '#' . $secondary_color;
$rgb = sscanf($primary_color, "#%02x%02x%02x");
$accentGlow = $rgb ? "rgba({$rgb[0]}, {$rgb[1]}, {$rgb[2]}, 0.4)" : "rgba(99, 102, 241, 0.4)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($from === 'superadmin') ? 'Admin Login | AutoFix Hub' : 'Staff Portal | ' . htmlspecialchars($shop_name ?: 'Workshop'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-deep: <?php echo $secondary_color; ?>;
            --accent: <?php echo $primary_color; ?>;
            --accent-glow: <?php echo $accentGlow; ?>;
            --glass: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { background: var(--bg-deep); color: var(--text-main); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 15px; position: relative; overflow-x: hidden; }

        .admin-split { width: 100%; max-width: 1050px; min-height: 650px; display: flex; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(40px); border-radius: 30px; border: 1px solid var(--glass-border); overflow: hidden; box-shadow: 0 40px 100px rgba(0,0,0,0.8); }
        .admin-left { flex: 1.2; padding: 3.5rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), transparent); border-right: 1px solid var(--glass-border); display: flex; flex-direction: column; justify-content: center; }
        .admin-right { flex: 1; padding: 4rem 3rem; display: flex; flex-direction: column; justify-content: center; background: rgba(0,0,0,0.2); position: relative; }

        .staff-split { width: 100%; max-width: 950px; min-height: 550px; display: flex; background: var(--glass); backdrop-filter: blur(25px); border-radius: 25px; border: 1px solid var(--glass-border); overflow: hidden; box-shadow: 0 50px 100px rgba(0,0,0,0.5); }
        .staff-left { flex: 1; background: url('https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?q=80&w=1000&auto=format&fit=crop') center center; background-size: cover; position: relative; padding: 2.5rem; display: flex; flex-direction: column; justify-content: flex-end; }
        .staff-left::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to top, var(--bg-deep) 0%, transparent 70%); opacity: 0.8; }
        .staff-right { flex: 1; padding: 4rem 3rem; display: flex; flex-direction: column; justify-content: center; background: rgba(0,0,0,0.2); position: relative; }

        .shop-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 2rem; }
        .shop-brand img { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; }
        .shop-brand .logo-alt { width: 34px; height: 34px; background: var(--accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; }
        .shop-brand span { font-weight: 800; font-size: 1.1rem; }

        .input-wrapper { position: relative; display: flex; align-items: center; margin-bottom: 1.2rem; }
        .input-wrapper i:not(.password-toggle) { position: absolute; left: 1.1rem; color: var(--text-dim); }
        .input-wrapper input { width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid var(--glass-border); color: white; padding: 1rem 3.5rem 1rem 3rem; border-radius: 12px; font-size: 0.95rem; outline: none; transition: 0.3s; }
        .input-wrapper input:focus { border-color: var(--accent); background: rgba(0, 0, 0, 0.6); }

        .btn-main { width: 100%; background: var(--accent); color: white; border: none; padding: 1.1rem; border-radius: 12px; font-size: 1rem; font-weight: 800; cursor: pointer; transition: 0.4s; box-shadow: 0 10px 25px var(--accent-glow); margin-top: 0.5rem; }
        .btn-main:hover { transform: translateY(-2px); box-shadow: 0 15px 35px var(--accent-glow); }

        .lang-switcher { position: absolute; top: 1.5rem; right: 2rem; display: flex; gap: 8px; background: rgba(255,255,255,0.05); padding: 4px 12px; border-radius: 100px; border: 1px solid var(--glass-border); }
        .lang-btn { font-size: 0.7rem; font-weight: 800; color: var(--text-dim); cursor: pointer; transition: 0.3s; }
        .lang-btn.active { color: var(--accent); }
        .lang-btn:hover { color: white; }

        .btn-back-nav { position: absolute; top: 1.5rem; left: 2.5rem; color: var(--text-dim); text-decoration: none; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; transition: 0.3s; }
        .btn-back-nav:hover { color: white; transform: translateX(-5px); }

        .password-toggle { position: absolute; right: 1.2rem; color: var(--text-dim); cursor: pointer; transition: 0.3s; z-index: 10; padding: 5px; }
        .password-toggle:hover { color: white; }

        #toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #ef4444; color: white; padding: 0.8rem 2rem; border-radius: 12px; font-weight: 600; opacity: 0; transition: 0.4s; z-index: 1000; }
        #toast.show { opacity: 1; transform: translateX(-50%) translateY(10px); }

        @media (max-width: 850px) { .admin-left, .staff-left, .btn-back-nav { display: none; } }
    </style>
</head>
<body>

    <?php if ($from === 'superadmin'): ?>
        <div class="admin-split">
            <div class="admin-left">
                <div style="font-size: 1.5rem; font-weight: 900; color: white;">AutoFix <span style="color:var(--accent);">Hub</span></div>
                <div>
                    <h1 style="font-size: 3.2rem; font-weight: 950; line-height: 1; margin-bottom: 2rem; letter-spacing: -2px; color: white;">The Ultimate <br><span style="color:var(--accent);">Command Center</span>.</h1>
                    <ul style="list-style:none; color: var(--text-dim); font-size: 1rem;">
                        <li style="margin-bottom:0.8rem;"><i class="fas fa-shield-alt" style="color:var(--accent); margin-right:10px;"></i> Multi-Tenant Isolation</li>
                        <li style="margin-bottom:0.8rem;"><i class="fas fa-chart-line" style="color:var(--accent); margin-right:10px;"></i> Real-time Analytics</li>
                    </ul>
                </div>
                <div style="color: var(--text-dim); font-size: 0.8rem;">&copy; <?php echo date('Y'); ?> AutoFix Hub</div>
            </div>
            <div class="admin-right">
                <a href="index.php" class="btn-back-nav"><i class="fas fa-arrow-left"></i> Back to Home</a>
                <div style="margin-bottom: 2rem;">
                    <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 5px;">Welcome Back</h1>
                    <p style="color: var(--text-dim); font-size: 0.95rem;">Access the Hub Central Console.</p>
                </div>
                <form id="loginForm">
                    <div style="margin-bottom: 1.2rem;">
                        <label style="display:block; font-size:0.75rem; font-weight:800; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Username</label>
                        <div class="input-wrapper"><i class="far fa-user"></i><input type="text" id="username" placeholder="e.g. superadmin" required></div>
                    </div>
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-size:0.75rem; font-weight:800; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Security Key</label>
                        <div class="input-wrapper"><i class="fas fa-lock"></i><input type="password" id="password" placeholder="••••••••" required><i class="far fa-eye password-toggle"></i></div>
                    </div>
                    <button type="submit" class="btn-main" id="loginBtn"><span>Login to Dashboard</span><div id="spinner" style="display:none; width:20px; height:20px; border:2px solid rgba(255,255,255,0.3); border-top:2px solid white; border-radius:50%; animation:spin 0.8s linear infinite; margin: 0 auto;"></div></button>
                </form>
                <div style="text-align:center; margin-top:2rem; font-size:0.85rem; color:var(--text-dim);">New partner? <a href="index.php#pricing" style="color:var(--accent); text-decoration:none; font-weight:700;">Register business</a></div>
            </div>
        </div>

    <?php else: ?>
        <div class="staff-split">
            <div class="staff-left">
                <div style="position:relative; z-index:2;">
                    <p id="txt-adv-mgmt" style="color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:2px; font-size:0.7rem; margin-bottom:8px;">Advanced Management</p>
                    <h2 id="txt-staff-portal-title" style="font-size:2.8rem; font-weight:900; line-height:1; letter-spacing:-1px;">Staff Portal</h2>
                </div>
            </div>
            <div class="staff-right">
                <!-- Back Link at Top -->
                <a href="shop.php?id=<?php echo urlencode($shop_slug ?: $tid); ?>" class="btn-back-nav" id="lnk-back">
                    <i class="fas fa-arrow-left"></i> <span id="txt-back">Back to Workshop</span>
                </a>

                <div class="lang-switcher">
                    <div class="lang-btn active" onclick="setLanguage('en')" id="btn-en">EN</div>
                    <div style="color: var(--glass-border)">|</div>
                    <div class="lang-btn" onclick="setLanguage('ph')" id="btn-ph">PH</div>
                </div>

                <div class="shop-brand">
                    <?php if ($shop_logo): ?><img src="<?php echo htmlspecialchars($shop_logo); ?>"><?php else: ?><div class="logo-alt"><?php echo $shop_name ? mb_substr($shop_name, 0, 1) : 'A'; ?></div><?php endif; ?>
                    <span><?php echo htmlspecialchars($shop_name ?: 'Workshop'); ?></span>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <h1 id="txt-signin-title" style="font-size: 2rem; font-weight: 900; margin-bottom: 5px; letter-spacing:-1px;">Sign In to Portal</h1>
                    <p id="txt-signin-sub" style="color: var(--text-dim); font-size:0.9rem;">Enter details to access workshop console.</p>
                </div>
                <form id="loginForm">
                    <div style="margin-bottom:1.2rem;">
                        <label id="lbl-staff-email" style="display:block; font-size:0.75rem; font-weight:800; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Staff Email</label>
                        <div class="input-wrapper"><i class="far fa-envelope"></i><input type="text" id="username" placeholder="name@workshop.com" required></div>
                    </div>
                    <div style="margin-bottom:1.5rem;">
                        <label id="lbl-security-key" style="display:block; font-size:0.75rem; font-weight:800; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Security Key</label>
                        <div class="input-wrapper"><i class="fas fa-key"></i><input type="password" id="password" placeholder="••••••••" required><i class="far fa-eye password-toggle"></i></div>
                    </div>
                    <button type="submit" class="btn-main" id="loginBtn"><span id="btn-text-signin">Sign In</span><div id="spinner-staff" style="display:none; width:20px; height:20px; border:2px solid rgba(255,255,255,0.3); border-top:2px solid white; border-radius:50%; animation:spin 0.8s linear infinite; margin: 0 auto;"></div></button>
                </form>
                <div style="text-align:center; margin-top:2rem; font-size:0.9rem; color:var(--text-dim);">
                    <span id="txt-forgot">Forgot credentials?</span> <a href="javascript:void(0)" onclick="showToast(currentLang === 'ph' ? 'Kontakin ang Manager' : 'Contact Manager')" style="color:var(--accent); text-decoration:none; font-weight:800;" id="lnk-recover">Recover Access</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="toast">Error</div>

    <script>
        let currentLang = 'en';
        const translations = {
            en: { adv_mgmt: "Advanced Management", staff_portal: "Staff Portal", signin_title: "Sign In to Portal", signin_sub: "Enter details to access workshop console.", lbl_email: "Staff Email", lbl_key: "Security Key", btn_signin: "Sign In", txt_forgot: "Forgot credentials?", lnk_recover: "Recover Access", txt_back: "Back to Workshop" },
            ph: { adv_mgmt: "Pamamahala ng Workshop", staff_portal: "Portal ng Staff", signin_title: "Mag-login sa Portal", signin_sub: "Ilagay ang detalye para makapasok sa console.", lbl_email: "Email ng Staff", lbl_key: "Susi sa Seguridad", btn_signin: "Mag-login", txt_forgot: "Nakalimutan ang login?", lnk_recover: "I-recover ang Access", txt_back: "Bumalik sa Workshop" }
        };

        function setLanguage(lang) {
            currentLang = lang; const t = translations[lang];
            const el = document.getElementById('txt-adv-mgmt');
            if(el) {
                el.innerText = t.adv_mgmt; document.getElementById('txt-staff-portal-title').innerText = t.staff_portal;
                document.getElementById('txt-signin-title').innerText = t.signin_title; document.getElementById('txt-signin-sub').innerText = t.signin_sub;
                document.getElementById('lbl-staff-email').innerText = t.lbl_email; document.getElementById('lbl-security-key').innerText = t.lbl_key;
                document.getElementById('btn-text-signin').innerText = t.btn_signin; document.getElementById('txt-forgot').innerText = t.txt_forgot;
                document.getElementById('lnk-recover').innerText = t.lnk_recover; document.getElementById('txt-back').innerText = t.txt_back;
                document.getElementById('btn-en').classList.toggle('active', lang === 'en'); document.getElementById('btn-ph').classList.toggle('active', lang === 'ph');
                localStorage.setItem('portal_lang', lang);
            }
        }
        if (localStorage.getItem('portal_lang')) setLanguage(localStorage.getItem('portal_lang'));

        document.querySelectorAll('.password-toggle').forEach(el => {
            el.addEventListener('click', function() {
                const input = this.previousElementSibling;
                input.type = input.type === 'password' ? 'text' : 'password';
                this.classList.toggle('fa-eye-slash');
            });
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const text = btn.querySelector('span');
            const spinner = btn.querySelector('.spinner') || btn.querySelector('div[id*="spinner"]');
            if(text) text.style.display = 'none'; spinner.style.display = 'block'; btn.style.pointerEvents = 'none';

            const formData = new FormData();
            formData.append('username', document.getElementById('username').value);
            formData.append('password', document.getElementById('password').value);
            const params = new URLSearchParams(window.location.search);
            fetch(`login.php?action=login&from=${params.get('from')||''}&tid=${params.get('tid')||''}`, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') { window.location.href = data.redirect; }
                else { showToast(data.message); if(text) text.style.display = 'inline-block'; spinner.style.display = 'none'; btn.style.pointerEvents = 'all'; }
            })
            .catch(err => { showToast('Connection error.'); if(text) text.style.display = 'inline-block'; spinner.style.display = 'none'; btn.style.pointerEvents = 'all'; });
        });

        // Handle URL errors
        window.addEventListener('load', () => {
            const params = new URLSearchParams(window.location.search);
            const error = params.get('error');
            if (error === 'suspended_workshop') {
                showToast(currentLang === 'ph' ? 'Suspended ang Workshop. Owner/Manager lang ang pwedeng pumasok.' : 'Workshop Suspended. Only Owners/Managers can access.');
            } else if (error === 'deactivated') {
                showToast(currentLang === 'ph' ? 'Deactivated ang iyong account.' : 'Your account has been deactivated.');
            }
        });


        function showToast(msg) {
            const t = document.getElementById('toast'); t.innerText = msg; t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(10px)';
            setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(0)'; }, 3000);
        }
    </script>
    <style> @keyframes spin { to { transform: rotate(360deg); } } </style>
</body>
</html>
