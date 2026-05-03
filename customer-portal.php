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
    $fallback_id = is_numeric($slug) ? (int) $slug : 0;
    $stmt = $db->prepare("SELECT tenant_id, shop_name, slug FROM tenants WHERE slug = ? OR tenant_id = ? LIMIT 1");
    $stmt->execute([$slug, $fallback_id]);
    $tenant = $stmt->fetch();
}

$shop_name_display = $tenant ? $tenant['shop_name'] : 'AutoFix Shop';
$tenant_id_context = $tenant ? $tenant['tenant_id'] : null;

// Construct the base URL for the APK download
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
$download_url = rtrim($base_url, '/') . "/AutofixHub.apk"; 
$qr_img_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($download_url);

// AJAX Block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Web login is disabled. Please use the mobile app.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mobile Access Only | <?php echo htmlspecialchars($shop_name_display); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-deep: #030712;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.4);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-deep);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: radial-gradient(circle at top center, rgba(99, 102, 241, 0.15) 0%, transparent 80%);
            overflow-x: hidden;
        }

        .mobile-card {
            width: 100%;
            max-width: 450px;
            min-height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            padding: 3rem 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
        }

        @media (min-width: 480px) {
            .mobile-card {
                min-height: auto;
                height: auto;
                border: 1px solid var(--glass-border);
                border-radius: 40px;
                box-shadow: 0 40px 100px rgba(0, 0, 0, 0.8);
                margin: 2rem;
                padding: 4rem 3rem;
            }
        }

        .icon-badge {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, var(--accent), #a855f7);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 35px var(--accent-glow);
            transform: rotate(-5deg);
            animation: float 4s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(-5deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
        }

        h1 {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -1px;
            margin-bottom: 10px;
            background: linear-gradient(to right, #fff, var(--text-dim));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .tag {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent);
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: 1px solid rgba(99, 102, 241, 0.2);
            margin-bottom: 1.5rem;
        }

        p.description {
            color: var(--text-dim);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }

        .qr-section {
            background: white;
            padding: 20px;
            border-radius: 30px;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            position: relative;
        }

        .qr-section img {
            width: 180px;
            height: 180px;
            display: block;
        }

        .qr-label {
            color: #1e293b;
            font-weight: 800;
            font-size: 0.8rem;
            margin-top: 10px;
        }

        .download-btn {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            color: white;
            padding: 1.2rem;
            border-radius: 20px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-weight: 700;
            transition: 0.3s;
            margin-bottom: 1rem;
        }

        .download-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--text-dim);
        }

        .download-btn i {
            font-size: 1.2rem;
            color: var(--accent);
        }

        .back-link {
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }

        .back-link:hover {
            color: white;
        }

        .steps {
            text-align: left;
            width: 100%;
            background: rgba(255, 255, 255, 0.02);
            padding: 1.5rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            border: 1px solid var(--glass-border);
        }

        .step-item {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 0.9rem;
            color: var(--text-dim);
        }

        .step-item:last-child { margin-bottom: 0; }

        .step-num {
            width: 20px;
            height: 20px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 900;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .step-text strong { color: white; }
    </style>
</head>

<body>
    <div class="mobile-card">
        <div class="tag">Mobile Exclusive</div>
        <div class="icon-badge">
            <i class="fas fa-mobile-screen-button"></i>
        </div>

        <h1><?php echo htmlspecialchars($shop_name_display); ?></h1>
        <p class="description">
            To provide the best experience and real-time repair tracking, our Customer Portal is now <strong>exclusively available</strong> on the mobile app.
        </p>

        <div class="qr-section">
            <img src="<?php echo $qr_img_url; ?>" alt="Scan to Download App">
            <div class="qr-label">SCAN TO DOWNLOAD APK</div>
        </div>

        <div class="steps">
            <div class="step-item">
                <div class="step-num">1</div>
                <div class="step-text">Scan the <strong>QR Code</strong> above or click download.</div>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <div class="step-text">Install the <strong>AutoFix Hub</strong> APK on your Android device.</div>
            </div>
            <div class="step-item">
                <div class="step-num">3</div>
                <div class="step-text">Login to track your <strong>Service History</strong> in real-time.</div>
            </div>
        </div>

        <a href="<?php echo $download_url; ?>" class="download-btn">
            <i class="fas fa-download"></i>
            Download App Directly
        </a>

        <div style="margin-top: 2rem; padding: 1.5rem; border-radius: 20px; background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); width: 100%;">
            <p style="font-size: 0.9rem; color: var(--text-dim); margin-bottom: 12px;">No account yet?</p>
            <a href="customer-register.php?id=<?php echo urlencode($slug); ?>" style="color: var(--accent); text-decoration: none; font-weight: 800; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-user-plus"></i> Create Account on Web
            </a>
        </div>

        <a href="shop.php?id=<?php echo urlencode($slug); ?>" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Shop Home
        </a>
    </div>
</body>

</html>