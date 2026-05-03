<?php
// AutoFix Hub - System & Database Diagnostic Tool
session_start();
require_once 'db-config.php';

$checks = [];

// 1. Check PHP Version & Extensions
$checks['php_version'] = [
    'name' => 'PHP Version',
    'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'OK' : 'WARNING',
    'message' => 'Current: ' . PHP_VERSION . ' (Recommended: 7.4+)'
];

$checks['pdo_mysql'] = [
    'name' => 'PDO MySQL Extension',
    'status' => extension_loaded('pdo_mysql') ? 'OK' : 'FAIL',
    'message' => extension_loaded('pdo_mysql') ? 'Supported' : 'Missing! Please enable in PHP settings.'
];

// 2. Check Database Connection
try {
    $db = getDB();
    $checks['db_connection'] = [
        'name' => 'Database Connection',
        'status' => 'OK',
        'message' => 'Successfully connected to ' . DB_NAME
    ];

    // 3. Check Core Tables
    $tables = ['roles', 'tenants', 'users', 'subscription_plans', 'tenant_subscriptions', 'tenant_payments', 'audit_logs'];
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) as total FROM $table");
            $count = $stmt->fetch()['total'];
            $checks["table_$table"] = [
                'name' => "Table: $table",
                'status' => 'OK',
                'message' => "Found ($count records)"
            ];
        } catch (Exception $e) {
            $checks["table_$table"] = [
                'name' => "Table: $table",
                'status' => 'FAIL',
                'message' => 'Missing or Error: ' . $e->getMessage()
            ];
        }
    }

    // 4. Check Super Admin Account
    $admin = $db->query("SELECT * FROM users WHERE email = 'superadmin'")->fetch();
    $checks['superadmin_account'] = [
        'name' => 'Super Admin Account',
        'status' => $admin ? 'OK' : 'FAIL',
        'message' => $admin ? 'Username "superadmin" exists' : 'Not found! Run login.php or setup script.'
    ];

} catch (Exception $e) {
    $checks['db_connection'] = [
        'name' => 'Database Connection',
        'status' => 'FAIL',
        'message' => $e->getMessage()
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Check | AutoFix Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #020617; color: white; padding: 3rem; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 24px; padding: 2rem; }
        h1 { font-weight: 800; letter-spacing: -1px; margin-bottom: 2rem; }
        .check-item { display: flex; justify-content: space-between; align-items: center; padding: 1.2rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .status-badge { padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; }
        .status-OK { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-FAIL { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .status-WARNING { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .message { font-size: 0.9rem; color: rgba(255,255,255,0.5); margin-top: 4px; }
        .footer { margin-top: 2rem; text-align: center; }
        .btn { display: inline-block; background: #6366f1; color: white; padding: 0.8rem 1.5rem; text-decoration: none; border-radius: 12px; font-weight: 700; margin: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛠️ System Health Check</h1>
        <div class="card">
            <?php foreach ($checks as $c): ?>
                <div class="check-item">
                    <div>
                        <div style="font-weight: 700;"><?php echo $c['name']; ?></div>
                        <div class="message"><?php echo $c['message']; ?></div>
                    </div>
                    <span class="status-badge status-<?php echo $c['status']; ?>"><?php echo $c['status']; ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="footer">
            <a href="login.php" class="btn">Go to Login</a>
            <a href="index.php" class="btn" style="background: rgba(255,255,255,0.1);">Back to Home</a>
        </div>
    </div>
</body>
</html>
