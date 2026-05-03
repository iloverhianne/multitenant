<?php
// AutoFix Hub Landing Page
?>
<?php
require_once 'db-config.php';

// Fetch active subscription plans from DB
try {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM subscription_plans WHERE status = 'ACTIVE' ORDER BY price ASC");
    $plans = $stmt->fetchAll();
} catch (Exception $e) {
    $plans = []; // Fallback empty
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoFix Hub | The Future of Car Repair Shop Management</title>
    <meta name="description"
        content="Scale your auto repair business with AutoFix Hub. The ultimate multi-tenant platform for service booking, inventory management, and business analytics.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg-deep: #030712;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.4);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        h1,
        h2,
        h3,
        .logo {
            font-family: 'Outfit', sans-serif;
        }

        a {
            text-decoration: none;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-deep);
            color: var(--text-main);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Scroll Progress Bar */
        #scroll-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(to right, var(--accent), #a855f7);
            z-index: 2001;
            transition: width 0.1s;
        }

        /* Animated Background Gradients */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        .bg-glow-1 {
            position: fixed;
            top: -10%;
            left: -10%;
            width: 60%;
            height: 60%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            z-index: -1;
            filter: blur(100px);
            animation: move-glow 20s infinite alternate;
        }

        .bg-glow-2 {
            position: fixed;
            bottom: -10%;
            right: -10%;
            width: 60%;
            height: 60%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            z-index: -1;
            filter: blur(100px);
            animation: move-glow 25s infinite alternate-reverse;
        }

        @keyframes move-glow {
            from {
                transform: translate(0, 0);
            }

            to {
                transform: translate(5%, 5%);
            }
        }

        .logo {
            font-size: 1.3rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: var(--accent);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 15px var(--accent-glow);
            font-size: 1rem;
            font-weight: 900;
        }

        .logo span {
            color: var(--accent);
        }

        /* Navigation */
        nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 1.5rem 10%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            transition: all 0.4s ease;
            background: rgba(3, 7, 18, 0.2);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid transparent;
        }

        nav.scrolled {
            padding: 1rem 10%;
            background: rgba(3, 7, 18, 0.9);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .nav-links {
            display: flex;
            gap: 2.5rem;
            align-items: center;
            flex: 1;
            justify-content: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dim);
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            position: relative;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: white;
        }

        .nav-actions {
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-shrink: 0;
        }

        .btn-nav,
        .btn-register {
            padding: 0.6rem 1.5rem !important;
            border-radius: 12px !important;
            font-weight: 800 !important;
            font-size: 0.85rem !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .btn-nav {
            background: white;
            color: black !important;
        }

        .btn-register {
            background: var(--accent);
            color: white !important;
            box-shadow: 0 10px 20px var(--accent-glow);
        }

        .btn-nav:hover,
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        /* Hero Section */
        .hero {
            padding: 12rem 10% 8rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 80vh;
            position: relative;
            text-align: center;
        }

        .hero-content {
            z-index: 10;
            max-width: 900px;
            margin: 0 auto;
        }

        .badge-new {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent);
            padding: 0.6rem 1.2rem;
            border-radius: 100px;
            font-size: 0.8rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2rem;
            border: 1px solid rgba(99, 102, 241, 0.2);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            backdrop-filter: blur(5px);
        }

        .hero h1 {
            font-size: clamp(3rem, 7vw, 6.5rem);
            font-weight: 900;
            line-height: 1;
            margin-bottom: 2.5rem;
            letter-spacing: -4px;
            text-transform: uppercase;
        }

        .hero h1 span {
            background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: block;
        }

        .hero p {
            font-size: 1.25rem;
            color: var(--text-dim);
            margin: 0 auto 4rem;
            max-width: 700px;
            line-height: 1.8;
            border: none;
            padding: 0;
        }

        .hero-btns {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #7c3aed);
            color: white;
            padding: 1rem 2rem;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 800;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 15px 30px -5px rgba(99, 102, 241, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-primary:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 25px 50px -10px rgba(99, 102, 241, 0.6);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-secondary {
            background: var(--glass);
            color: white;
            padding: 1rem 2rem;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 800;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--glass-border);
            transition: all 0.4s;
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .hero-visual {
            flex: 1.2;
            position: relative;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            min-width: 500px;
        }

        .visual-wrapper {
            position: relative;
            width: 100%;
            max-width: 800px;
            transform-style: preserve-3d;
            perspective: 2000px;
        }

        .dashboard-mockup {
            width: 100%;
            border-radius: 24px;
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transform: rotateY(-12deg) rotateX(5deg);
            animation: float-3d 6s ease-in-out infinite;
            z-index: 2;
            position: relative;
        }

        @keyframes float-3d {

            0%,
            100% {
                transform: rotateY(-12deg) rotateX(5deg) translateY(0);
            }

            50% {
                transform: rotateY(-8deg) rotateX(3deg) translateY(-20px);
            }
        }

        .visual-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 120%;
            height: 120%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.2) 0%, transparent 70%);
            transform: translate(-50%, -50%);
            z-index: 1;
            filter: blur(80px);
            pointer-events: none;
        }

        .floating-card {
            position: absolute;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 1.2rem;
            z-index: 10;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5);
            animation: float-card 8s ease-in-out infinite;
            min-width: 180px;
        }

        .card-1 {
            top: 0%;
            right: -5%;
            animation-delay: 1s;
        }

        .card-2 {
            bottom: 5%;
            left: -5%;
            animation-delay: 3s;
        }

        @keyframes float-card {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-15px) rotate(2deg);
            }
        }

        /* Features Section */
        .features {
            padding: 10rem 10%;
            text-align: center;
            position: relative;
        }

        .section-header {
            margin-bottom: 6rem;
        }

        .section-header h2 {
            font-size: 3.5rem;
            font-weight: 900;
            letter-spacing: -2px;
            margin-bottom: 1.5rem;
            line-height: 1.1;
        }

        .section-header h2 span {
            background: linear-gradient(135deg, #818cf8, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-header p {
            color: var(--text-dim);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.8;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2.5rem;
        }

        .feature-card {
            padding: 3rem 2.5rem;
            transition: all 0.4s;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            border-color: var(--accent);
            box-shadow: 0 0 30px var(--accent-glow);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2.5rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 3.5rem 2.5rem;
            border-radius: 32px;
            text-align: left;
            transition: all 0.5s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative;
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        .feature-card:hover {
            transform: translateY(-12px);
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 40px 80px -20px rgba(0, 0, 0, 0.6);
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2.5rem;
            color: var(--accent);
            font-size: 1.8rem;
            border: 1px solid rgba(99, 102, 241, 0.2);
            transition: all 0.5s;
        }

        .feature-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 0.8rem;
            letter-spacing: -0.2px;
        }

        .feature-card p {
            color: var(--text-dim);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* How it Works */
        .how-it-works {
            padding: 12rem 10%;
            background: radial-gradient(circle at 0% 0%, rgba(99, 102, 241, 0.03), transparent 50%),
                radial-gradient(circle at 100% 100%, rgba(236, 72, 153, 0.03), transparent 50%);
        }

        .steps-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-top: 6rem;
            position: relative;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            z-index: 2;
            padding: 2rem;
            transition: all 0.4s;
        }

        .step-number {
            width: 90px;
            height: 90px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 900;
            color: white;
            margin-bottom: 2.5rem;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5);
            transition: all 0.4s;
        }

        .step:hover .step-number {
            border-color: var(--accent);
            box-shadow: 0 20px 40px -10px var(--accent-glow);
            transform: rotate(10deg);
        }

        .step h4 {
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -0.5px;
        }

        .step p {
            color: var(--text-dim);
            font-size: 1rem;
            line-height: 1.7;
        }

        /* Pricing Section */
        .pricing {
            padding: 10rem 10%;
            position: relative;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2.5rem;
            align-items: flex-start;
        }

        .pricing-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 4.5rem 3rem;
            border-radius: 40px;
            display: flex;
            flex-direction: column;
            transition: all 0.6s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative;
            backdrop-filter: blur(20px);
        }

        .pricing-card.popular {
            background: rgba(99, 102, 241, 0.03);
            border-color: rgba(99, 102, 241, 0.3);
            transform: scale(1.05);
            z-index: 5;
            box-shadow: 0 0 40px rgba(99, 102, 241, 0.1);
        }

        .popular-badge {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--accent), #a855f7);
            color: white;
            padding: 0.6rem 1.5rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            box-shadow: 0 10px 20px var(--accent-glow);
            z-index: 10;
        }

        .pricing-card h4 {
            font-size: 1rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 3px;
            font-weight: 900;
            margin-bottom: 2.5rem;
            text-align: center;
        }

        .price {
            font-size: 2.8rem;
            font-weight: 900;
            margin-bottom: 0.6rem;
            display: flex;
            align-items: baseline;
            justify-content: center;
            letter-spacing: -1.2px;
        }

        .price span {
            font-size: 0.9rem;
            color: var(--text-dim);
            font-weight: 600;
            margin-left: 0.3rem;
            letter-spacing: 0;
        }

        .pricing-features {
            list-style: none;
            margin: 2.5rem 0 auto;
        }

        .pricing-features li {
            font-size: 0.95rem;
            color: var(--text-dim);
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pricing-features li::before {
            content: '✓';
            width: 24px;
            height: 24px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 900;
        }

        .btn-pricing {
            width: 100%;
            margin-top: 4rem;
            padding: 1.3rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 800;
            font-size: 1.1rem;
            text-align: center;
            transition: all 0.4s;
        }

        .pricing-card:not(.popular) .btn-pricing {
            background: white;
            color: black;
        }

        .pricing-card.popular .btn-pricing {
            background: var(--accent);
            color: white;
            box-shadow: 0 15px 30px var(--accent-glow);
        }

        .pricing-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 3rem;
        }

        .toggle-btn {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--text-dim);
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .toggle-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            box-shadow: 0 5px 15px var(--accent-glow);
        }

        .save-badge {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .btn-pricing:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }

        /* Testimonials */
        .testimonials {
            padding: 12rem 10%;
            text-align: center;
            background: radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.05), transparent 70%);
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3rem;
            margin-top: 7rem;
        }

        .testimonial-card {
            background: rgba(255, 255, 255, 0.02);
            padding: 4.5rem 3.5rem;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            text-align: left;
            position: relative;
            transition: all 0.4s;
        }

        .testimonial-card:hover {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-10px);
        }

        .quote-icon {
            font-size: 5rem;
            color: rgba(99, 102, 241, 0.1);
            position: absolute;
            top: 1.5rem;
            left: 2.5rem;
            line-height: 1;
            font-family: serif;
        }

        .testimonial-card p {
            font-size: 1.15rem;
            font-style: italic;
            color: var(--text-main);
            margin-bottom: 2.5rem;
            line-height: 1.8;
            position: relative;
            z-index: 1;
            font-weight: 400;
        }

        .client {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .client-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), #a855f7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
        }

        .client-info h5 {
            font-size: 1.15rem;
            font-weight: 800;
            color: white;
        }

        .client-info span {
            color: var(--text-dim);
            font-size: 0.9rem;
        }



        /* Footer */
        footer {
            padding: 6rem 10% 4rem;
            background: rgba(0, 0, 0, 0.3);
            border-top: 1px solid var(--glass-border);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 6rem;
            margin-bottom: 8rem;
        }

        .footer-col h5 {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 2.5rem;
            color: white;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 1.2rem;
        }

        .footer-links a {
            text-decoration: none;
            color: var(--text-dim);
            transition: all 0.3s;
            display: inline-block;
        }

        .footer-links a:hover {
            color: var(--accent);
            transform: translateX(10px);
        }

        .social-links {
            display: flex;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .social-icon {
            width: 50px;
            height: 50px;
            background: var(--glass);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            border: 1px solid var(--glass-border);
            transition: all 0.3s;
        }

        .social-icon:hover {
            background: var(--accent);
            border-color: var(--accent);
            transform: translateY(-5px);
        }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 4rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-dim);
            font-size: 0.95rem;
        }

        /* Responsive Improvements */
        @media (max-width: 1400px) {
            .hero h1 {
                font-size: 4rem;
            }

            .features-grid,
            .pricing-grid,
            .testimonials-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .pricing-card.popular {
                transform: none;
                margin-top: 2rem;
            }

            .steps-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .steps-container::before {
                display: none;
            }
        }

        @media (max-width: 1100px) {
            .hero {
                flex-direction: column;
                text-align: center;
                padding-top: 12rem;
            }

            .hero-content {
                max-width: 100%;
                order: 1;
            }

            .hero-btns {
                justify-content: center;
            }

            .hero-visual {
                width: 100%;
                display: flex;
                justify-content: center;
                order: 2;
                margin-top: 4rem;
            }

            .hero-visual img {
                width: 80%;
            }

            .footer-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 4rem;
            }
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 3.2rem;
            }

            .features-grid,
            .pricing-grid,
            .testimonials-grid,
            .steps-container {
                grid-template-columns: 1fr;
            }

            .nav-links {
                display: none;
            }

            .cta-section {
                padding: 5rem 2.5rem;
            }

            .cta-section h2 {
                font-size: 2.8rem;
            }

            .newsletter-form {
                flex-direction: column;
            }

            .btn-submit {
                padding: 1.5rem;
            }
        }

        /* Registration Modal Styling */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(3, 7, 18, 0.9);
            backdrop-filter: blur(15px);
            z-index: 3000;
            display: none;
            justify-content: center;
            align-items: flex-start;
            /* Changed from center to allow scrolling from top */
            padding: 40px 20px;
            /* Added vertical padding for better scrolling */
            overflow-y: auto;
        }

        .modal-card {
            background: var(--bg-deep);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            width: 100%;
            max-width: 550px;
            /* Slightly reduced */
            padding: 2.2rem;
            /* Reduced from 3rem */
            position: relative;
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.8);
            animation: modalFadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-card h3 {
            font-size: 2rem;
            font-weight: 900;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }

        .modal-card p {
            color: var(--text-dim);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1rem;
            /* Reduced from 1.5rem */
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.8);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 1rem;
            background: #0f172a;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: white;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        .form-group select optgroup {
            background: #0f172a;
            color: var(--accent);
            font-weight: 700;
            padding: 10px;
        }

        .form-group select option {
            background: #0f172a;
            color: white;
            padding: 10px;
        }

        .form-group input:focus {
            border-color: var(--accent);
            background: rgba(255, 255, 255, 0.08);
        }

        .btn-close-modal {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s;
            z-index: 10;
        }

        .btn-close-modal:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(90deg);
        }
    </style>
</head>

<body>
    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>
    <div id="scroll-progress"></div>

    <nav id="navbar">
        <a href="#" class="logo">
            <div class="logo-icon">A</div>
            AutoFix <span>Hub</span>
        </a>
        <div class="nav-links">
            <a href="#features">Solutions</a>
            <a href="#how-it-works">Onboarding</a>
            <a href="#pricing">Pricing</a>
        </div>
        <div class="nav-actions">
            <a href="login.php?from=superadmin" class="btn-nav">Sign In</a>
            <a href="javascript:void(0)" onclick="openModal()" class="btn-register">Join Platform</a>
        </div>
    </nav>

    <main>
        <!-- Hero Section -->
        <section class="hero">
            <div class="hero-content">
                <div class="badge-new reveal">
                    <span>New</span> Engine v4.0 Active
                </div>
                <h1 class="reveal" style="transition-delay: 0.1s;">Scale Your <span>Repair Empire</span></h1>
                <p class="reveal" style="transition-delay: 0.2s;">The ultimate multi-tenant platform for independent
                    auto shops. Provision your independent dashboard, manage technicians, and scale operations globally.
                </p>

                <div class="hero-btns reveal" style="transition-delay: 0.3s;">
                    <a href="#pricing" class="btn-primary">Provision Your Hub <span
                            style="font-size: 1.2rem;">→</span></a>
                    <a href="#features" class="btn-secondary">Explore Solutions</a>
                </div>

            </div>
        </section>



        <!-- Multi-Tenant Value Proposition -->
        <section class="features" id="tenant-value" style="padding-bottom: 0;">
            <div class="section-header">
                <span class="badge-new"
                    style="background: rgba(16, 185, 129, 0.1); color: var(--success); border-color: rgba(16, 185, 129, 0.2);">Project
                    Focus: Multi-Tenancy</span>
                <h2>One Platform, <span>Limitless Brands</span></h2>
                <p>Our architecture allows you to run multiple independent businesses on a single infrastructure, each
                    with their own secure database and custom branding.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">⛓️</div>
                    <h3>Total Isolation</h3>
                    <p>Your data is cryptographically isolated. No tenant can ever see another's clients, inventory, or
                        financial reports.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🎨</div>
                    <h3>White-Label Ready</h3>
                    <p>Customize your shop's portal with your own logo, color palette, and personalized service
                        categories.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🚀</div>
                    <h3>Instant Deployment</h3>
                    <p>No servers to maintain. Get your repair shop's digital management system live in under 60
                        seconds.</p>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="features" id="features">
            <div class="section-header">
                <span
                    style="font-size: 0.75rem; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: var(--accent); margin-bottom: 1rem; display: block;">Solutions
                    Ecosystem</span>
                <h2 style="font-weight: 800; font-size: 3.5rem; letter-spacing: -2px;">Intelligent <span>Shop
                        Management</span></h2>
                <p>A comprehensive suite of tools designed for high-performance automotive networks.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">👤</div>
                    <h3>Multi-Tenant ID</h3>
                    <p>Secure login portals with role-based access control per shop domain.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🔧</div>
                    <h3>Bay Management</h3>
                    <p>Monitor real-time garage flow and technician throughput instantly.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📑</div>
                    <h3>Smart Ledger</h3>
                    <p>Automated fiscal settlement with dynamic parts-to-labor ratio audits.</p>
                </div>
            </div>
        </section>

        <!-- How it Works -->
        <section class="how-it-works" id="how-it-works">
            <div class="section-header">
                <h2>Join as a Tenant</h2>
                <p>Our onboarding process is designed for rapid automotive business growth.</p>
            </div>
            <div class="steps-container">
                <div class="step">
                    <div class="step-number">01</div>
                    <h4>Request Access</h4>
                    <p>Submit your shop details and choose a subscription plan that fits your current volume.</p>
                </div>
                <div class="step">
                    <div class="step-number">02</div>
                    <h4>Provision Hub</h4>
                    <p>The system automatically carves out a private database instance for your specific repair shop.
                    </p>
                </div>
                <div class="step">
                    <div class="step-number">03</div>
                    <h4>Import Assets</h4>
                    <p>Setup your bay count, mechanics, and inventory. Your independent portal is now active.</p>
                </div>
                <div class="step">
                    <div class="step-number">04</div>
                    <h4>Open for Business</h4>
                    <p>Go live! Your shop now has a premium dashboard to manage every aspect of the repair lifecycle.
                    </p>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section class="features" id="about" style="background: rgba(255,255,255,0.01);">
            <div class="section-header">
                <h2>Our Mission</h2>
                <p>Empowering independent repair shops with global-standard technology.</p>
            </div>
            <div
                style="max-width: 700px; margin: 0 auto; text-align: center; color: var(--text-dim); font-size: 1.1rem; line-height: 1.8;">
                <p>AutoFix Hub was built to bridge the gap between traditional craftsmanship and modern digital
                    efficiency. We provide a secure, multi-tenant ecosystem where every shop owner can access
                    enterprise-level management tools without the enterprise-level costs.</p>
            </div>
        </section>

        <!-- Pricing -->
        <section class="pricing" id="pricing">
            <div class="section-header">
                <h2>Enterprise Pricing</h2>
                <p>Transparent tiers designed to scale with your shop's complexity and volume.</p>
            </div>

            <div class="pricing-toggle">
                <button class="toggle-btn active" id="btn-monthly" onclick="setBilling('monthly')">Bill Monthly</button>
                <button class="toggle-btn" id="btn-yearly" onclick="setBilling('yearly')">Bill Yearly</button>
                <div class="save-badge">Save ~17%</div>
            </div>

            <div class="pricing-grid">
                <?php if (empty($plans)): ?>
                    <p style="grid-column: 1/-1; text-align: center; color: var(--text-dim);">No plans available at the
                        moment.</p>
                <?php else: ?>
                    <?php foreach ($plans as $plan):
                        $isPopular = (strpos($plan['plan_name'], 'PRO') !== false);
                        ?>
                        <div class="pricing-card <?php echo $isPopular ? 'popular' : ''; ?>">
                            <?php if ($isPopular): ?>
                                <span class="popular-badge">High Performance</span>
                            <?php endif; ?>
                            <h4>
                                <?php echo htmlspecialchars($plan['plan_name']); ?>
                            </h4>
                            <div class="price" id="price-<?php echo $plan['plan_id']; ?>">₱
                                <?php echo number_format($plan['price'], 0); ?><span
                                    id="period-<?php echo $plan['plan_id']; ?>">/mo</span>
                            </div>
                            <p id="savings-<?php echo $plan['plan_id']; ?>"
                                style="color: var(--success); font-size: 0.8rem; margin-top: -0.5rem; height: 1.2rem; display: none;">
                                Savings Applied
                            </p>
                            <ul class="pricing-features">
                                <li>
                                    <?php echo $plan['max_users']; ?> Staff Accounts
                                </li>
                                <li>
                                    <?php echo $plan['max_service_bays']; ?> Active Service Bays
                                </li>
                                <li>Priority Support Enabled</li>
                                <li>Cloud Data Backup</li>
                                <li>Zero Transaction Fees</li>
                            </ul>
                            <a href="javascript:void(0)" onclick="handlePricingClick(<?php echo $plan['plan_id']; ?>)"
                                class="btn-primary" style="margin-top: 2.5rem; width: 100%; text-align: center;">Choose Plan</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- CTA Section -->
        <section class="cta-section">
            <div class="glass-card"
                style="max-width: 1200px; margin: 0 auto; text-align: center; padding: 6rem 4rem; border-radius: 48px;">
                <h2 style="font-size: 3.5rem; font-weight: 900; margin-bottom: 3.5rem;">Ready to <span>Scale?</span>
                </h2>
                <div style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap;">
                    <a href="javascript:void(0)" onclick="openModal()" class="btn-primary"
                        style="padding: 1.2rem 3rem;">Provision Your Hub Now</a>
                    <a href="#pricing" class="btn-secondary" style="padding: 1.2rem 3rem;">View Tiers</a>
                </div>
            </div>
        </section>

    </main>

    <footer>
        <div class="footer-grid">
            <div class="footer-col" style="grid-column: span 1;">
                <a href="#" class="logo">
                    <div class="logo-icon">A</div>
                    AutoFix <span>Hub</span>
                </a>
                <p style="margin-top: 2rem; color: var(--text-dim); line-height: 1.8; font-size: 1rem;">Leading the
                    digital transformation of the automotive repair industry. Premium, scalable, and built for
                    performance.</p>
                <div class="social-links">
                    <a href="#" class="social-icon">𝕏</a>
                    <a href="#" class="social-icon">f</a>
                    <a href="#" class="social-icon">in</a>
                    <a href="#" class="social-icon">📷</a>
                </div>
            </div>
            <div class="footer-col">
                <h5>Platform</h5>
                <ul class="footer-links">
                    <li><a href="#pricing">Become a Tenant</a></li>
                    <li><a href="#features">Technical Specs</a></li>
                    <li><a href="#how-it-works">Implementation</a></li>
                    <li><a href="#pricing">Pricing Plans</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h5>Company</h5>
                <ul class="footer-links">
                    <li><a href="#">About Vision</a></li>
                    <li><a href="#">Partner Network</a></li>
                    <li><a href="#">Legal & Privacy</a></li>
                    <li><a href="#">Contact Support</a></li>
                </ul>
            </div>
            <div class="footer-col" style="padding-left: 2rem; border-left: 1px solid rgba(255,255,255,0.05);">
                <h5>HQ Location</h5>
                <p style="color: var(--text-dim); line-height: 1.8; font-size: 0.95rem;">
                    National University Baliwag<br>
                    SM Baliwag Complex, DRT Hwy<br>
                    Pagala, Baliwag, Bulacan
                </p>
                <p style="margin-top: 1.5rem; color: white; font-weight: 700; font-size: 1.1rem;">+63 (2) 8888-AUTO</p>
                <a href="#pricing" class="btn-primary"
                    style="margin-top: 2rem; padding: 0.8rem 1.5rem; font-size: 0.9rem; width: 100%;">Get Started
                    Now</a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 AutoFix Hub Platform Enterprise. Global Rights Reserved.</p>
            <div style="display: flex; gap: 2rem;">
                <a href="#" style="color: var(--text-dim); text-decoration: none; font-size: 0.85rem;">Privacy
                    Policy</a>
                <a href="#" style="color: var(--text-dim); text-decoration: none; font-size: 0.85rem;">Terms of
                    Service</a>
            </div>
        </div>
    </footer>

    <!-- Registration Modal -->
    <div id="registerModal" class="modal-overlay">
        <div class="modal-card">
            <button class="btn-close-modal" onclick="closeModal()">&times;</button>
            <div class="logo-icon" style="margin-bottom: 0.8rem;">A</div>
            <h3 style="font-size: 1.8rem; margin-bottom: 0.5rem;">Onboard Your Shop</h3>
            <p style="margin-bottom: 1.5rem;">Join the 500+ tenants scaling their business with AutoFix Hub.</p>

            <form action="verify-email.php" method="POST" enctype="multipart/form-data">
                <!-- ... existing fields ... -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Shop Name</label>
                        <input type="text" name="shop_name" placeholder="e.g. Manila Auto Hub" required>
                    </div>
                    <div class="form-group">
                        <label>Owner Name</label>
                        <input type="text" name="owner_name" placeholder="Full Name" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Business Email</label>
                        <input type="email" name="email" placeholder="owner@shop.com" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" name="contact" placeholder="0917 XXX XXXX" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Shop Address</label>
                    <input type="text" name="address" placeholder="Unit, Street, City, ZIP" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Create Password</label>
                        <input type="password" name="password" placeholder="Min. 8 characters" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-type password" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Subscription Plan</label>
                        <select name="plan" id="planSelect" required>
                            <optgroup label="Monthly Billing" id="opt-monthly">
                                <?php foreach ($plans as $plan): ?>
                                    <option value="<?php echo $plan['plan_id']; ?>" data-cycle="monthly">
                                        <?php echo htmlspecialchars($plan['plan_name']); ?> - ₱
                                        <?php echo number_format($plan['price'], 0); ?>/mo
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Yearly Billing" id="opt-yearly">
                                <?php foreach ($plans as $plan): ?>
                                    <option value="<?php echo $plan['plan_id']; ?>" data-cycle="yearly">
                                        <?php echo htmlspecialchars($plan['plan_name']); ?> - ₱
                                        <?php echo number_format($plan['price_yearly'], 0); ?>/yr
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Identification Type</label>
                        <select name="id_type" required>
                            <option value="">-- Choose ID Type --</option>
                            <option value="SSS">SSS ID</option>
                            <option value="UMID">UMID (Multi-Purpose ID)</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="Philippine Passport">Philippine Passport</option>
                            <option value="PhilHealth">PhilHealth ID</option>
                            <option value="Voter's ID">Voter's ID</option>
                            <option value="PRC ID">PRC License</option>
                            <option value="National ID">National ID (PhilSys)</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Business Proof (BIR/Permit)</label>
                        <input type="file" name="business_proof" accept="image/*,.pdf" style="padding: 0.8rem;"
                            required>
                    </div>
                    <div class="form-group">
                        <label>Owner's ID Photo</label>
                        <input type="file" name="id_photo" accept="image/*" style="padding: 0.8rem;" required>
                    </div>
                </div>

                <input type="hidden" name="plan_id" id="hiddenPlanId">
                <input type="hidden" name="billing_cycle" id="hiddenBillingCycle">
                <button type="submit" class="btn-primary"
                    style="width: 100%; border: none; cursor: pointer; padding: 1.2rem;">Verify Email & Proceed</button>
                <p style="font-size: 0.8rem; text-align: center; margin-top: 1rem; color: var(--text-dim);">
                    Secured by <strong>PayMongo</strong> payment gateway
                </p>
            </form>
        </div>
    </div>

    <script>
        const basePlans = <?php echo json_encode($plans); ?>;
        window.currentBilling = 'monthly'; // Track active cycle globally

        // Modal Logic
        function openModal(planId = null, billingCycle = null) {
            document.getElementById('registerModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            const select = document.getElementById('planSelect');
            const cycleToUse = billingCycle || window.currentBilling;

            if (planId) {
                const plan = basePlans.find(p => p.plan_id == planId);
                if (plan) {
                    // 1. Find the specific option matching both ID and Cycle
                    const options = Array.from(select.options);
                    const targetOption = options.find(opt => opt.value == plan.plan_id && opt.dataset.cycle === cycleToUse);

                    if (targetOption) {
                        targetOption.selected = true; // This handles same-value options correctly
                    }

                    // 2. Update hidden fields
                    document.getElementById('hiddenPlanId').value = plan.plan_id;
                    document.getElementById('hiddenBillingCycle').value = cycleToUse;
                }
            }
        }

        function closeModal() {
            document.getElementById('registerModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal on outside click
        window.onclick = function (event) {
            const modal = document.getElementById('registerModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Update hidden fields on manual change
        document.getElementById('planSelect').addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            document.getElementById('hiddenPlanId').value = this.value;
            document.getElementById('hiddenBillingCycle').value = selected.dataset.cycle || 'monthly';
        });

        // Navbar Scroll Effect
        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            const progress = document.getElementById('scroll-progress');

            // Scroll Progress
            const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
            const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrolled = (winScroll / height) * 100;
            progress.style.width = scrolled + "%";

            // Navbar background
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });

        // Staggered Scroll Reveal
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.reveal').forEach((el) => {
            observer.observe(el);
        });

        // Force Hero elements to active immediately
        window.addEventListener('load', () => {
            document.querySelectorAll('.hero .reveal').forEach((el) => {
                el.classList.add('active');
            });
        });

        // Scroll Spy Logic
        const sections = document.querySelectorAll('section[id]');
        const navItems = document.querySelectorAll('.nav-links a[href^="#"]');

        window.addEventListener('scroll', () => {
            let current = "";
            const pageOffset = window.pageYOffset + 200; // Trigger point offset

            sections.forEach((section) => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.offsetHeight;

                // Check if scroll position is within the top and bottom of the section
                if (pageOffset >= sectionTop && pageOffset < (sectionTop + sectionHeight)) {
                    current = section.getAttribute("id");
                }
            });

            navItems.forEach((link) => {
                link.classList.remove('active');
                if (current && link.getAttribute('href').substring(1) === current) {
                    link.classList.add('active');
                }
            });
        });
        // Pricing Logic
        function handlePricingClick(planId) {
            openModal(planId, window.currentBilling);
        }

        function setBilling(type) {
            window.currentBilling = type;
            const isYearly = (type === 'yearly');

            // Adjust buttons
            const btnM = document.getElementById('btn-monthly');
            const btnY = document.getElementById('btn-yearly');
            if (btnM) btnM.classList.toggle('active', !isYearly);
            if (btnY) btnY.classList.toggle('active', isYearly);

            // Update Prices on Cards
            basePlans.forEach(plan => {
                const priceEl = document.getElementById('price-' + plan.plan_id);
                const savingsEl = document.getElementById('savings-' + plan.plan_id);

                if (priceEl) {
                    const price = isYearly ? plan.price_yearly : plan.price;
                    priceEl.innerHTML = `₱${Number(price).toLocaleString()}<span>/${isYearly ? 'yr' : 'mo'}</span>`;
                }
                if (savingsEl) {
                    savingsEl.style.display = isYearly ? 'block' : 'none';
                }
            });
        }
    </script>
</body>

</html>