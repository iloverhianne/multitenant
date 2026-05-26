<?php
session_start();
require_once 'db-config.php';

// Get the slug from URL, fallback to demo if not set
$slug = $_GET['id'] ?? 'demo';

// Fetch Tenant Details using the SLUG
try {
    $db = getDB();

    // Auto-heal missing customization columns systematically
    try { $db->exec("ALTER TABLE tenants ADD COLUMN logo_url TEXT NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN primary_color VARCHAR(20) DEFAULT '#6366f1'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN secondary_color VARCHAR(20) DEFAULT '#030712'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN phone VARCHAR(20) NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN description TEXT NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN address TEXT NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN ui_style VARCHAR(20) DEFAULT 'GLASS'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE tenants ADD COLUMN border_radius VARCHAR(10) DEFAULT '24px'"); } catch(Exception $e) {}

    $stmt = $db->prepare("SELECT tenant_id, shop_name, owner_name, email, address, status, logo_url, primary_color, secondary_color, phone, description, ui_style, border_radius, hero_title, hero_subtitle, banner_url, about_text, opening_hours, facebook_url, instagram_url FROM tenants WHERE slug = ? OR tenant_id = ? LIMIT 1");
    // Fallback allowing direct tenant_id in URL just in case
    $fallback_id = is_numeric($slug) ? (int)$slug : 0;
    $stmt->execute([$slug, $fallback_id]);
    $shop = $stmt->fetch();

    if (!$shop) {
        die("<h1 style='color:white; font-family:sans-serif; text-align:center; margin-top:50px;'>Shop Not Found</h1>");
    }

    if (strtoupper($shop['status']) === 'SUSPENDED') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Website Suspended | AutoFix Hub</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
            <style>
                body { background-color: #020617; color: white; font-family: 'Outfit', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; overflow: hidden; }
                .container { text-align: center; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); padding: 4rem; border-radius: 40px; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 50px 100px rgba(0,0,0,0.5); max-width: 600px; margin: 20px; }
                .icon { font-size: 5rem; color: #ef4444; margin-bottom: 2rem; animation: pulse 2s infinite; }
                h1 { font-size: 3rem; font-weight: 900; margin-bottom: 1rem; letter-spacing: -2px; line-height: 1; }
                p { color: #94a3b8; font-size: 1.2rem; line-height: 1.6; margin-bottom: 2.5rem; }
                .btn { background: linear-gradient(135deg, #ef4444, #b91c1c); color: white; padding: 1rem 2rem; border-radius: 15px; text-decoration: none; font-weight: 800; display: inline-flex; align-items: center; gap: 10px; transition: 0.3s; }
                .btn:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3); }
                @keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.05); opacity: 0.8; } 100% { transform: scale(1); opacity: 1; } }
            </style>
        </head>
        <body>
            <div class="container">
                <i class="fas fa-exclamation-triangle icon"></i>
                <h1>Website Suspended</h1>
                <p>Access to <b><?php echo htmlspecialchars($shop['shop_name']); ?></b> has been temporarily suspended by the system administrator. Please contact the workshop manager or support for assistance.</p>
                <div style="display:flex; gap:15px; justify-content:center; flex-wrap:wrap;">
                    <a href="tenant-dashboard.php?triggerChat=suspension" class="btn"><i class="fas fa-comment-dots"></i> Contact Support</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if (strtoupper($shop['status']) === 'PENDING' && !isset($_GET['preview'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Application Under Review | AutoFix Hub</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
            <style>
                body { background-color: #020617; color: white; font-family: 'Outfit', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; overflow: hidden; }
                .container { text-align: center; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); padding: 4rem; border-radius: 40px; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 50px 100px rgba(0,0,0,0.5); max-width: 600px; margin: 20px; }
                .icon { font-size: 5rem; color: #f59e0b; margin-bottom: 2rem; animation: float 3s ease-in-out infinite; }
                h1 { font-size: 3rem; font-weight: 900; margin-bottom: 1rem; letter-spacing: -2px; line-height: 1; }
                p { color: #94a3b8; font-size: 1.2rem; line-height: 1.6; margin-bottom: 2.5rem; }
                .status-badge { display: inline-block; padding: 8px 20px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; border-radius: 100px; font-weight: 800; font-size: 0.9rem; letter-spacing: 1px; margin-bottom: 1.5rem; border: 1px solid rgba(245, 158, 11, 0.2); }
                @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-20px); } 100% { transform: translateY(0px); } }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="status-badge">PENDING APPROVAL</div>
                <i class="fas fa-clock-rotate-left icon"></i>
                <h1>Under Review</h1>
                <p>The application for <b><?php echo htmlspecialchars($shop['shop_name']); ?></b> is currently being reviewed by our administrative team. We are verifying the documents to ensure a safe experience for all users.</p>
                <p style="font-size: 0.9rem; color: #64748b;">Please check back later or wait for our email notification.</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if (strtoupper($shop['status']) === 'REJECTED' && !isset($_GET['preview'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Application Rejected | AutoFix Hub</title>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
            <style>
                body { background-color: #020617; color: white; font-family: 'Outfit', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; overflow: hidden; }
                .container { text-align: center; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); padding: 4rem; border-radius: 40px; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 50px 100px rgba(0,0,0,0.5); max-width: 600px; margin: 20px; }
                .icon { font-size: 5rem; color: #ef4444; margin-bottom: 2rem; }
                h1 { font-size: 3rem; font-weight: 900; margin-bottom: 1rem; letter-spacing: -2px; line-height: 1; }
                p { color: #94a3b8; font-size: 1.2rem; line-height: 1.6; margin-bottom: 2.5rem; }
            </style>
        </head>
        <body>
            <div class="container">
                <i class="fas fa-times-circle icon"></i>
                <h1>Application Rejected</h1>
                <p>We regret to inform you that the registration for <b><?php echo htmlspecialchars($shop['shop_name']); ?></b> was not approved. Please contact support if you have questions.</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($shop['status'] !== 'active' && strtoupper($shop['status']) !== 'ACTIVE' && !isset($_GET['preview'])) {
        die("<h1 style='color:white; font-family:sans-serif; text-align:center; margin-top:50px;'>This shop is currently unavailable.</h1>");
    }

    // Normalize Colors
    if (!empty($shop['primary_color']) && strpos($shop['primary_color'], '#') !== 0) $shop['primary_color'] = '#' . $shop['primary_color'];
    if (!empty($shop['secondary_color']) && strpos($shop['secondary_color'], '#') !== 0) $shop['secondary_color'] = '#' . $shop['secondary_color'];

    // Fetch real services
    $services_stmt = $db->prepare("SELECT service_name as name, price, description as 'desc' FROM services WHERE tenant_id = ? AND status = 'ACTIVE' ORDER BY service_id DESC");
    $services_stmt->execute([$shop['tenant_id']]);
    $real_services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error connecting to database: " . $e->getMessage());
}

$display_services = $real_services;
$theme = $shop['ui_style'] ?: 'GLASS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($shop['shop_name']); ?> | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --bg-deep: <?php echo htmlspecialchars($shop['secondary_color'] ?: '#030712'); ?> !important;
            --accent: <?php echo htmlspecialchars($shop['primary_color'] ?: '#6366f1'); ?> !important;
            --accent-rgb: <?php 
                $hex = ($shop['primary_color'] ?? '') ?: '#6366f1';
                $rgb = sscanf($hex, "#%02x%02x%02x");
                if($rgb) { list($r, $g, $b) = $rgb; } else { $r=99; $g=102; $b=241; }
                echo "$r, $g, $b";
            ?> !important;
            --bg-rgb: <?php 
                $hex = ($shop['secondary_color'] ?? '') ?: '#030712';
                $rgb = sscanf($hex, "#%02x%02x%02x");
                if($rgb) { list($r, $g, $b) = $rgb; } else { $r=3; $g=7; $b=18; }
                echo "$r, $g, $b";
            ?> !important;
            --gradient: linear-gradient(135deg, var(--accent), rgba(var(--accent-rgb), 0.8)) !important;
            --accent-glow: rgba(var(--accent-rgb), 0.4) !important;
            --radius: <?php echo $shop['border_radius'] ?: '30px'; ?> !important;
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #ffffff;
            --text-dim: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        /* Custom Dark Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { 
            background: rgba(255,255,255,0.1); 
            border-radius: 10px; 
            border: 2px solid var(--bg-deep); 
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        
        body { background-color: var(--bg-deep); color: var(--text-main); min-height: 100vh; overflow-x: hidden; scroll-behavior: smooth; }

        /* Banner Support */
        .hero {
            <?php if (!empty($shop['banner_url'])): ?>
            background-image: linear-gradient(to bottom, rgba(var(--bg-rgb), 0.7), var(--bg-deep)), url('<?php echo htmlspecialchars($shop['banner_url']); ?>') !important;
            background-size: cover !important;
            background-position: center !important;
            <?php endif; ?>
        }

        @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }
        @keyframes glow { 0% { box-shadow: 0 0 20px var(--accent-glow); } 50% { box-shadow: 0 0 40px var(--accent-glow); } 100% { box-shadow: 0 0 20px var(--accent-glow); } }

        /* Shared Nav */
        nav {
            padding: 1.2rem 5%; display: flex; justify-content: space-between; align-items: center;
            background: rgba(3, 7, 18, 0.85); backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border);
            position: sticky; top: 0; z-index: 1000; gap: 20px;
        }
        .logo { font-size: 1.4rem; font-weight: 800; display: flex; align-items: center; gap: 12px; min-width: max-content; }
        .logo span { background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: 1.6rem; letter-spacing: -1px; }
        
        .nav-links { display: flex; align-items: center; gap: 2rem; }
        .nav-links a { color: #f1f5f9; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: 0.3s cubic-bezier(0.16, 1, 0.3, 1); white-space: nowrap; }
        .nav-links a:hover { color: #ffffff; transform: translateY(-2px); text-shadow: 0 0 10px rgba(255,255,255,0.3); }
        
        .btn-register { 
            background: var(--gradient); color: #ffffff !important; padding: 12px 28px; border-radius: 100px; 
            font-weight: 900; text-decoration: none; box-shadow: 0 10px 30px var(--accent-glow);
            transition: 0.5s cubic-bezier(0.16, 1, 0.3, 1); border: none; animation: glow 4s infinite;
            display: inline-block; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;
            white-space: nowrap;
        }
        .btn-register:hover { transform: translateY(-4px) scale(1.05); box-shadow: 0 20px 40px var(--accent-glow); color: #ffffff !important; opacity: 0.9; }

        .btn-login-nav {
            border: 1px solid var(--glass-border);
            padding: 12px 24px;
            border-radius: 100px;
            color: #ffffff;
            font-size: 0.8rem;
            font-weight: 800;
            text-decoration: none;
            transition: 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
        }
        .btn-login-nav:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
        }
        <?php if(isset($_GET['preview'])): ?>
        .btn-register, .btn-login-nav { pointer-events: none !important; cursor: default !important; }
        <?php endif; ?>

        /* Mobile specific */
        @media (max-width: 768px) {
            nav { padding: 1rem 5%; }
            .nav-links a:not(.btn-register):not(.btn-login-nav) { display: none; } /* Hide other links on mobile */
            .logo span { font-size: 1.1rem; }
            .btn-register { padding: 10px 20px; font-size: 0.75rem; }
            .btn-login-nav { padding: 10px 20px; font-size: 0.75rem; }
            .nav-links { gap: 12px; }
        }

        /* --- THEME: GLASS (DEFAULT) --- */
        <?php if ($theme === 'GLASS'): ?>
        .hero { position: relative; padding: 160px 8% 120px; text-align: center; background: radial-gradient(circle at 50% -20%, var(--accent-glow), transparent 70%); }
        <?php if (!empty($shop['banner_url'])): ?>
        .hero { background: linear-gradient(rgba(var(--bg-rgb), 0.8), var(--bg-deep)), url('<?php echo htmlspecialchars($shop['banner_url']); ?>') no-repeat center center / cover !important; }
        <?php endif; ?>
        .hero::before { content: ''; position: absolute; top: 10%; left: 10%; width: 300px; height: 300px; background: var(--accent); filter: blur(150px); opacity: 0.1; }
        .hero h1 { font-size: 5.5rem; font-weight: 950; letter-spacing: -3.5px; margin-bottom: 2rem; line-height: 0.95; }
        .hero h1 span { background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .container { max-width: 1300px; margin: 0 auto; padding: 40px 8%; display: grid; grid-template-columns: 2fr 1fr; gap: 4rem; }
        .service-card { 
            background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); border-radius: var(--radius); 
            padding: 3rem; margin-bottom: 2rem; position: relative; transition: 0.4s;
            backdrop-filter: blur(10px);
        }
        .service-card:hover { transform: translateY(-10px); background: rgba(255,255,255,0.05); border-color: var(--accent); }
        .s-price { position: absolute; top: 3rem; right: 3rem; font-weight: 900; font-size: 1.6rem; color: var(--accent); }
        .service-card h3 { padding-right: 80px; }
        .info-panel { background: var(--glass); border: 1px solid var(--glass-border); border-radius: var(--radius); padding: 3.5rem; height: fit-content; position: sticky; top: 120px; }
        <?php endif; ?>

        /* --- THEME: PREMIUM --- */
        <?php if ($theme === 'PREMIUM'): ?>
        .hero { 
            padding: 220px 10% 150px; text-align: left; 
            background: linear-gradient(to right, var(--bg-deep) 40%, rgba(0,0,0,0.4)), url('<?php echo htmlspecialchars($shop['banner_url'] ?: "https://images.unsplash.com/photo-1625047509168-a7026f36ae04?q=80&w=2000&auto=format&fit=crop"); ?>'); 
            background-size: cover; background-position: center; position: relative; 
        }
        .hero::after { content: ''; position: absolute; bottom: 0; left: 0; width: 100%; height: 200px; background: linear-gradient(to top, var(--bg-deep), transparent); }
        .hero h1 { font-size: 7.5rem; font-weight: 950; line-height: 0.85; letter-spacing: -6px; max-width: 900px; text-transform: uppercase; }
        .hero h1 span { color: var(--accent); text-shadow: 0 0 50px var(--accent-glow); }
        .container { max-width: 1500px; margin: 0 auto; padding: 100px 10%; }
        .services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 3rem; }
        .service-card { 
            background: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.05); border-radius: 50px; 
            padding: 5rem; transition: 0.6s cubic-bezier(0.16, 1, 0.3, 1); position: relative; overflow: hidden;
        }
        .service-card:hover { transform: translateY(-20px) scale(1.02); background: rgba(255,255,255,0.03); border-color: var(--accent); }
        .service-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: var(--gradient); opacity: 0; transition: 0.6s; z-index: 0; }
        .service-card:hover::before { opacity: 0.05; }
        .s-price { font-size: 3.5rem; font-weight: 950; color: var(--accent); margin-bottom: 1.5rem; position: relative; z-index: 1; display: block; line-height: 1; }
        .s-name { font-size: 2.2rem; font-weight: 800; margin-bottom: 1.5rem; position: relative; z-index: 1; }
        .s-desc { font-size: 1.15rem; line-height: 1.8; color: var(--text-dim); position: relative; z-index: 1; }
        .info-panel { 
            margin-top: 120px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 4rem; 
            background: rgba(255,255,255,0.02); padding: 5rem; border-radius: 60px; border: 1px solid var(--glass-border);
        }
        <?php endif; ?>

        /* --- THEME: MINIMAL --- */
        <?php if ($theme === 'MINIMAL'): ?>
        body { background: var(--bg-deep); }
        .hero { padding: 150px 8% 50px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .hero h1 { font-size: 4rem; font-weight: 300; letter-spacing: -1px; color: #eee; }
        .container { max-width: 1000px; margin: 0 auto; padding: 60px 8%; }
        .service-card { border-bottom: 1px solid #222; padding: 30px 0; display: flex; justify-content: space-between; align-items: center; }
        .service-card:hover { background: #111; padding-left: 20px; transition: 0.3s; }
        .s-price { font-size: 1.5rem; font-weight: 700; color: white; }
        .info-panel { margin-top: 60px; border-top: 1px solid #222; padding-top: 40px; display: flex; gap: 40px; }
        <?php endif; ?>

        /* --- THEME: VIBRANT --- */
        <?php if ($theme === 'VIBRANT'): ?>
        .hero { 
            padding: 120px 8%; text-align: center; 
            background: linear-gradient(45deg, var(--bg-deep), var(--accent)); 
            clip-path: polygon(0 0, 100% 0, 100% 85%, 0 100%);
        }
        .hero h1 { font-size: 5rem; font-weight: 900; text-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .container { max-width: 1200px; margin: -50px auto 0; padding: 40px 8%; }
        .services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        .service-card { background: white; color: black; border-radius: 20px; padding: 2.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .service-card h3 { color: #111; }
        .s-price { font-size: 2rem; font-weight: 900; color: var(--accent); }
        .info-panel { background: white; color: black; padding: 3rem; border-radius: 20px; margin-top: 40px; }
        .info-panel label { color: var(--accent); }
        <?php endif; ?>

        /* Common Footer */
        footer { padding: 80px 8%; text-align: center; border-top: 1px solid var(--glass-border); color: var(--text-dim); }
        .footer-logo { font-size: 1.5rem; font-weight: 900; color: white; margin-bottom: 1rem; }
    </style>
</head>
<body>

    <nav>
        <div class="logo" id="preview_shop_name">
            <?php if (!empty($shop['logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($shop['logo_url']); ?>" alt="logo" id="preview_logo" 
                     style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
            <?php endif; ?>
            <span><?php echo htmlspecialchars($shop['shop_name']); ?></span>
        </div>
        <div class="nav-links">
            <a href="#services">Services</a>
            <a href="#about">About</a>
            <a href="customer-portal.php?id=<?php echo urlencode($slug); ?>" class="btn-login-nav">Customer App</a>
            <a href="customer-register.php?id=<?php echo urlencode($slug); ?>" class="btn-register">Register</a>
            <a href="login.php?from=tenant&tid=<?php echo urlencode($shop['tenant_id']); ?>" style="opacity: 0.6; font-size: 0.7rem; color: var(--text-dim); text-decoration: none;">Staff</a>
        </div>
    </nav>

    <?php if ($theme === 'GLASS'): ?>
    <header class="hero" id="preview_banner">
        <?php if (!empty($shop['hero_title'])): ?>
            <h1 id="preview_hero_title"><span><?php echo htmlspecialchars($shop['hero_title']); ?></span></h1>
        <?php else: ?>
            <h1 id="preview_hero_title">Expert Service at<br><span><?php echo htmlspecialchars($shop['shop_name']); ?></span></h1>
        <?php endif; ?>
        <p id="preview_hero_subtitle" style="color:var(--text-dim); max-width:600px; margin:0 auto; font-size:1.2rem;"><?php echo htmlspecialchars($shop['hero_subtitle'] ?: ($shop['description'] ?: "Premium car care and maintenance for your high-performance vehicle.")); ?></p>
        <a href="#services" class="btn-register" style="display:inline-block; margin-top:2rem; padding:15px 40px;">Get Started</a>
    </header>
    <main class="container">
        <div id="services">
            <h2 style="font-size:2.5rem; margin-bottom:2rem;">Our Services</h2>
            <?php foreach($display_services as $s): ?>
                <div class="service-card">
                    <div class="s-price">₱<?php echo number_format($s['price'], 0); ?></div>
                    <h3 style="margin-bottom:0.5rem; font-size:1.5rem;"><?php echo htmlspecialchars($s['name']); ?></h3>
                    <p style="color:var(--text-dim);"><?php echo htmlspecialchars($s['desc']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="about" class="info-panel" style="background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.05); padding:3rem; border-radius:30px;">
            <div style="margin-bottom: 3rem;" id="preview_about_text">
                <h3 style="color:var(--accent); font-size:2rem; font-weight:900; margin-bottom:1.5rem; letter-spacing:-1px;">About Our Shop</h3>
                <?php if (!empty($shop['about_text'])): ?>
                    <p style="color:var(--text-main); line-height:1.8; font-size:1.1rem; opacity:0.85;"><?php echo nl2br(htmlspecialchars($shop['about_text'])); ?></p>
                <?php else: ?>
                    <p style="color:var(--text-main); line-height:1.8; font-size:1.1rem; opacity:0.85;">Welcome to our shop! We are dedicated to providing the highest quality automotive services with precision and care. Our team of expert mechanics is committed to ensuring your vehicle stays in peak performance and safety. We treat every car as if it were our own.</p>
                <?php endif; ?>
            </div>

            <h3 style="font-size:1.5rem; font-weight:800; margin-bottom:2rem; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-id-card" style="color:var(--accent);"></i> Contact Information
            </h3>
            
            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="background:rgba(255,255,255,0.03); padding:1.5rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                    <label style="display:block; text-transform:uppercase; font-size:0.7rem; color:var(--accent); font-weight:900; margin-bottom:8px; letter-spacing:1px;">
                        <i class="fas fa-map-marker-alt" style="margin-right:5px;"></i> Shop Address
                    </label>
                    <p id="preview_address" style="font-size:0.95rem; line-height:1.5;"><?php echo htmlspecialchars($shop['address'] ?: '123 Auto Sector Street, Metro Manila, Philippines'); ?></p>
                </div>

                <div style="background:rgba(255,255,255,0.03); padding:1.5rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                    <label style="display:block; text-transform:uppercase; font-size:0.7rem; color:var(--accent); font-weight:900; margin-bottom:8px; letter-spacing:1px;">
                        <i class="fas fa-phone-alt" style="margin-right:5px;"></i> Email & Phone
                    </label>
                    <p id="preview_phone" style="font-size:0.95rem; line-height:1.5;">
                        <span style="display:block; font-weight:700;"><?php echo htmlspecialchars($shop['phone'] ?: '+63 912 345 6789'); ?></span>
                        <span style="opacity:0.6; font-size:0.85rem;"><?php echo htmlspecialchars($shop['email']); ?></span>
                    </p>
                </div>

                <div style="background:rgba(255,255,255,0.03); padding:1.5rem; border-radius:20px; border:1px solid rgba(255,255,255,0.05);">
                    <label style="display:block; text-transform:uppercase; font-size:0.7rem; color:var(--accent); font-weight:900; margin-bottom:8px; letter-spacing:1px;">
                        <i class="fas fa-clock" style="margin-right:5px;"></i> Opening Hours
                    </label>
                    <p id="preview_opening_hours" style="font-size:0.95rem; font-weight:700;"><?php echo htmlspecialchars($shop['opening_hours'] ?: 'Mon - Sat: 8:00 AM - 5:00 PM'); ?></p>
                </div>
            </div>
        </div>
    </main>
    <?php endif; ?>

    <?php if ($theme === 'PREMIUM'): ?>
    <header class="hero" id="preview_banner">
        <h1 id="preview_hero_title">UNMATCHED<br><span>PRECISION.</span></h1>
        <p id="preview_hero_subtitle" style="margin-top:20px; font-size:1.4rem; color: #ddd;"><?php echo htmlspecialchars($shop['shop_name']); ?>: Where elite engineering meets luxury care.</p>
    </header>
    <main class="container">
        <h2 style="font-size:4rem; letter-spacing:-2px; margin-bottom:4rem;">PREMIUM SERVICES</h2>
        <div class="services-grid" id="services">
            <?php foreach($display_services as $s): ?>
                <div class="service-card">
                    <div class="s-price">₱<?php echo number_format($s['price'], 0); ?></div>
                    <h3 class="s-name"><?php echo htmlspecialchars($s['name']); ?></h3>
                    <p class="s-desc"><?php echo htmlspecialchars($s['desc']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="info-panel" id="about">
            <div><label style="color:var(--accent); font-weight:900;">LOCATION</label><p id="preview_address"><?php echo htmlspecialchars($shop['address']); ?></p></div>
            <div><label style="color:var(--accent); font-weight:900;">CONTACT</label><p id="preview_phone"><?php echo htmlspecialchars($shop['phone']); ?><br><?php echo htmlspecialchars($shop['email']); ?></p></div>
            <div><label style="color:var(--accent); font-weight:900;">HOURS</label><p id="preview_opening_hours">Mon - Sun: 8am - 8pm</p></div>
        </div>
    </main>
    <?php endif; ?>

    <?php if ($theme === 'MINIMAL'): ?>
    <header class="hero" id="preview_banner">
        <h1 id="preview_hero_title"><?php echo htmlspecialchars($shop['shop_name']); ?>.</h1>
        <p id="preview_hero_subtitle" style="color:#666; margin-top:10px;"><?php echo htmlspecialchars($shop['description']); ?></p>
    </header>
    <main class="container">
        <div id="services">
            <?php foreach($display_services as $s): ?>
                <div class="service-card">
                    <div>
                        <h3><?php echo htmlspecialchars($s['name']); ?></h3>
                        <p style="color:#666;"><?php echo htmlspecialchars($s['desc']); ?></p>
                    </div>
                    <div class="s-price">₱<?php echo number_format($s['price'], 0); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="about" class="info-panel">
            <div><p style="color:#666;">Contact</p><b id="preview_phone"><?php echo htmlspecialchars($shop['email']); ?><br><?php echo htmlspecialchars($shop['phone']); ?></b></div>
            <div><p style="color:#666;">Visit</p><b id="preview_address"><?php echo htmlspecialchars($shop['address']); ?></b></div>
        </div>
    </main>
    <?php endif; ?>

    <?php if ($theme === 'VIBRANT'): ?>
    <header class="hero" id="preview_banner">
        <h1 id="preview_hero_title">WE REVIVE YOUR RIDE!</h1>
        <p id="preview_hero_subtitle" style="font-weight:700; background:rgba(0,0,0,0.2); display:inline-block; padding:10px 20px; border-radius:10px;"><?php echo htmlspecialchars($shop['shop_name']); ?> POWERED</p>
    </header>
    <main class="container">
        <div class="services-grid" id="services">
            <?php foreach($display_services as $s): ?>
                <div class="service-card">
                    <h3 style="color:var(--accent); margin-bottom:10px;"><?php echo htmlspecialchars($s['name']); ?></h3>
                    <p style="margin-bottom:20px;"><?php echo htmlspecialchars($s['desc']); ?></p>
                    <div class="s-price">₱<?php echo number_format($s['price'], 0); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="about" class="info-panel">
            <h2 style="margin-bottom:20px;">Come Visit Us!</h2>
            <p><b>Address:</b> <span id="preview_address"><?php echo htmlspecialchars($shop['address']); ?></span></p>
            <p><b>Phone:</b> <span id="preview_phone"><?php echo htmlspecialchars($shop['phone']); ?></span></p>
        </div>
    </main>
    <?php endif; ?>

    <footer>
        <div class="footer-logo"><?php echo htmlspecialchars($shop['shop_name']); ?></div>
        <p>&copy; <?php echo date('Y'); ?>. All Rights Reserved. Powered by AutoFix Hub.</p>
    </footer>

    <style>
        /* Interactive Highlight Effect */
        .highlight-pulse {
            animation: highlight-glow 2s infinite !important;
            border-radius: 8px;
            position: relative;
            z-index: 10001;
        }

        @keyframes highlight-glow {
            0% { box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0.7); outline: 2px solid transparent; }
            50% { box-shadow: 0 0 0 15px rgba(var(--accent-rgb), 0); outline: 2px solid var(--accent); }
            100% { box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0); outline: 2px solid transparent; }
        }
        
        .highlight-text {
            background: rgba(var(--accent-rgb), 0.2) !important;
            transition: 0.3s;
            border-radius: 4px;
        }
    </style>

    <script>
        window.addEventListener('message', function(event) {
            const data = event.data;
            if (data.action === 'highlight') {
                // Remove existing highlights
                document.querySelectorAll('.highlight-pulse, .highlight-text').forEach(el => {
                    el.classList.remove('highlight-pulse', 'highlight-text');
                });

                const field = data.field;
                let target = null;

                const mapping = {
                    'shop_name': '#preview_shop_name',
                    'description': '#preview_hero_subtitle',
                    'hero_title': '#preview_hero_title',
                    'hero_subtitle': '#preview_hero_subtitle',
                    'about_text': '#preview_about_text, #about',
                    'phone': '#preview_phone',
                    'address': '#preview_address',
                    'logo_url': '#preview_logo',
                    'banner_url': '#preview_banner',
                    'opening_hours': '#preview_opening_hours',
                    'facebook_url': 'footer',
                    'instagram_url': 'footer',
                    'primary_color': 'body',
                    'secondary_color': 'body'
                };

                if (mapping[field]) {
                    const el = document.querySelector(mapping[field]);
                    if (el) {
                        if (el.tagName === 'P' || el.tagName === 'SPAN' || el.tagName === 'H1' || el.tagName === 'H2' || el.tagName === 'H3') {
                            el.classList.add('highlight-text');
                        } else {
                            el.classList.add('highlight-pulse');
                        }
                        
                        // Enhanced scroll behavior
                        setTimeout(() => {
                            el.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center',
                                inline: 'nearest'
                            });
                        }, 50);
                    }
                }
            }
        });
    </script>

</body>
</html>
