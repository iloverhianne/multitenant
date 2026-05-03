<?php
// AutoFix Hub - PREMIUM DARK EDITION (ORANGE ACCENTS)
require_once 'db-config.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM subscription_plans WHERE status = 'ACTIVE' ORDER BY price ASC");
    $plans = $stmt->fetchAll();
} catch (Exception $e) {
    $plans = []; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoFix Hub | Premium Workshop OS</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff4d1c;
            --primary-glow: rgba(255, 77, 28, 0.3);
            --bg-deep: #020617;
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        h1, h2, h3, .logo { font-family: 'Outfit', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-color: var(--bg-deep); color: var(--text-main); line-height: 1.6; overflow-x: hidden; }

        .bg-glow { position: fixed; width: 600px; height: 600px; border-radius: 50%; background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%); z-index: -1; filter: blur(100px); opacity: 0.4; }
        .glow-1 { top: -200px; left: -200px; }
        .glow-2 { bottom: -200px; right: -200px; }

        nav {
            position: fixed; top: 0; width: 100%; padding: 1.5rem 10%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 1000; transition: 0.3s;
        }
        nav.scrolled { padding: 1rem 10%; background: rgba(2, 6, 23, 0.85); backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border); }
        .logo { font-size: 1.5rem; font-weight: 900; color: white; text-decoration: none; letter-spacing: -1px; display: flex; align-items: center; gap: 8px; }
        .logo span { color: var(--primary); }

        .btn-cta { background: var(--primary); color: white !important; padding: 0.8rem 1.8rem; border-radius: 12px; font-weight: 800; box-shadow: 0 10px 20px var(--primary-glow); transition: 0.3s; }
        .btn-cta:hover { transform: translateY(-3px); }

        /* Hero */
        .hero { padding: 12rem 10% 6rem; min-height: 85vh; display: flex; align-items: center; gap: 4rem; }
        .hero-content { flex: 1.2; z-index: 2; }
        .hero h1 { font-size: 4.8rem; font-weight: 900; line-height: 1; margin-bottom: 2rem; letter-spacing: -3px; color: white; }
        .hero h1 span { color: var(--primary); text-shadow: 0 0 30px var(--primary-glow); }
        .hero p { font-size: 1.2rem; color: var(--text-dim); margin-bottom: 3.5rem; max-width: 600px; }
        .hero-btns { display: flex; gap: 1.5rem; }
        .btn-hero-primary { background: var(--primary); color: white; padding: 1.2rem 2.5rem; border-radius: 15px; text-decoration: none; font-weight: 800; transition: 0.3s; box-shadow: 0 15px 30px var(--primary-glow); }
        
        .hero-image { flex: 1; position: relative; z-index: 1; }
        .hero-image img { width: 140%; transform: translateX(-10%); animation: float 6s ease-in-out infinite; }
        @keyframes float { 0%, 100% { transform: translateX(-10%) translateY(0); } 50% { transform: translateX(-10%) translateY(-25px); } }

        /* Pricing */
        .pricing { padding: 8rem 10%; background: #010510; }
        .plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 3rem; }
        .plan-card { background: var(--glass); padding: 5rem 3.5rem; border-radius: 45px; border: 1px solid var(--glass-border); transition: 0.6s; position: relative; overflow: hidden; }
        .plan-card:hover { transform: translateY(-20px); border-color: var(--primary); background: rgba(255, 255, 255, 0.05); }
        .price { font-size: 4rem; font-weight: 900; margin-bottom: 0.5rem; color: white; }
        .price span { font-size: 1.2rem; color: var(--text-dim); }
        .btn-plan { width: 100%; padding: 1.5rem; background: var(--primary); color: white; border-radius: 20px; text-decoration: none; font-weight: 800; text-align: center; display: block; margin-top: 3rem; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 2, 10, 0.95); backdrop-filter: blur(20px); z-index: 3000; display: none; justify-content: center; align-items: flex-start; padding: 50px 20px; overflow-y: auto; }
        .modal-card { background: var(--bg-deep); border: 1px solid var(--glass-border); border-radius: 40px; width: 100%; max-width: 620px; padding: 4rem 3.5rem; position: relative; }
        .form-group input { background: #0f172a; border: 1px solid var(--glass-border); color: white; padding: 1.2rem; border-radius: 15px; width: 100%; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="bg-glow glow-1"></div>
    <div class="bg-glow glow-2"></div>

    <nav id="navbar">
        <a href="#" class="logo">AutoFix <span>Hub</span></a>
        <div class="nav-actions">
            <a href="login.php?from=superadmin" class="btn-cta">Admin Login</a>
        </div>
    </nav>

    <header class="hero">
        <div class="hero-content">
            <h1>Scale Your <span>Repair Empire</span></h1>
            <p>The premium multi-tenant platform for automotive entrepreneurs. Provision independent workshop portals in seconds.</p>
            <div class="hero-btns">
                <a href="#pricing" class="btn-hero-primary">Get Started Now</a>
            </div>
        </div>
        <div class="hero-image">
            <img src="assets/img/hero-car.png" alt="Car Visual">
        </div>
    </header>

    <section class="pricing" id="pricing">
        <div class="plans-grid">
            <?php foreach ($plans as $plan): ?>
                <div class="plan-card">
                    <h4><?php echo htmlspecialchars($plan['plan_name']); ?></h4>
                    <div class="price">₱<?php echo number_format($plan['price'], 0); ?><span>/mo</span></div>
                    <a href="javascript:void(0)" onclick="openModal(<?php echo $plan['plan_id']; ?>)" class="btn-plan">Choose Plan</a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div id="registerModal" class="modal-overlay">
        <div class="modal-card">
            <button onclick="closeModal()" style="position:absolute; top:2rem; right:2rem; background:none; border:none; color:white; font-size:2rem; cursor:pointer;">&times;</button>
            <h3 style="font-size: 2.2rem; margin-bottom: 2rem;">Onboard Your Shop</h3>
            <form action="verify-email.php" method="POST" enctype="multipart/form-data">
                <div class="form-group"><input type="text" name="shop_name" placeholder="Shop Name" required></div>
                <div class="form-group"><input type="text" name="owner_name" placeholder="Owner Name" required></div>
                <div class="form-group"><input type="email" name="email" placeholder="Business Email" required></div>
                <div class="form-group"><input type="password" name="password" placeholder="Password" required></div>
                <input type="hidden" name="plan_id" id="hiddenPlanId">
                <button type="submit" class="btn-plan" style="border:none; cursor:pointer;">Verify & Proceed</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { 
            document.getElementById('registerModal').style.display = 'flex'; 
            document.getElementById('hiddenPlanId').value = id;
        }
        function closeModal() { document.getElementById('registerModal').style.display = 'none'; }
        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if (window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });
    </script>
</body>
</html>
