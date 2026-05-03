<?php
require_once 'db-config.php';
try {
    $db = getDB();
    // Add price_yearly column if it doesn't exist
    $db->exec("ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS price_yearly DECIMAL(10,2) AFTER price");
    // Initialize price_yearly for existing plans
    $db->exec("UPDATE subscription_plans SET price_yearly = price * 10 WHERE price_yearly IS NULL OR price_yearly = 0");
    echo "Migration successful!";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
