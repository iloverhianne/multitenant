<?php
require_once 'db-config.php';
try {
    $db = getDB();
    
    // Drop the global unique email index
    echo "Attempting to drop global unique email index...<br>";
    try {
        $db->exec("ALTER TABLE customers DROP INDEX email");
        echo "[OK] Global unique email index removed.<br>";
    } catch (Exception $e) {
        echo "[INFO] Global email index might not exist or already removed: " . $e->getMessage() . "<br>";
    }

    // Ensure our multi-tenant unique index exists
    echo "Ensuring unique_customer_tenant index exists...<br>";
    try {
        $db->exec("ALTER TABLE customers ADD UNIQUE KEY unique_customer_tenant (tenant_id, email)");
        echo "[OK] Multi-tenant unique index ensured.<br>";
    } catch (Exception $e) {
        echo "[INFO] Multi-tenant index already exists.<br>";
    }

    echo "<h2>Fix Complete!</h2>";
    echo "<p>You can now use the same email for different shops (tenants).</p>";
    echo "<a href='check-db-schema.php'>View updated schema</a>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
