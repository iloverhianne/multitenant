<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db-config.php';

try {
    $db = getDB();
    echo "<h3>Starting Migration...</h3>";
    
    // Add approval_status
    try {
        $db->exec("ALTER TABLE repair_parts ADD COLUMN approval_status VARCHAR(20) DEFAULT 'APPROVED'");
        echo "<p style='color:green;'>[SUCCESS] Added approval_status column.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] approval_status might already exist: " . $e->getMessage() . "</p>";
    }
    
    // Add approval_token
    try {
        $db->exec("ALTER TABLE repair_parts ADD COLUMN approval_token VARCHAR(64) NULL");
        echo "<p style='color:green;'>[SUCCESS] Added approval_token column.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] approval_token might already exist: " . $e->getMessage() . "</p>";
    }
    
    // Add customer_signature
    try {
        $db->exec("ALTER TABLE repair_parts ADD COLUMN customer_signature LONGTEXT NULL");
        echo "<p style='color:green;'>[SUCCESS] Added customer_signature column.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] customer_signature might already exist: " . $e->getMessage() . "</p>";
    }

    // Add GCash settings to tenants
    try {
        $db->exec("ALTER TABLE tenants ADD COLUMN gcash_name VARCHAR(100) NULL, ADD COLUMN gcash_number VARCHAR(50) NULL");
        echo "<p style='color:green;'>[SUCCESS] Added gcash_name and gcash_number to tenants.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] GCash columns might already exist: " . $e->getMessage() . "</p>";
    }

    // Add proof_image to payments
    try {
        $db->exec("ALTER TABLE payments ADD COLUMN proof_image VARCHAR(255) NULL");
        echo "<p style='color:green;'>[SUCCESS] Added proof_image to payments.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] proof_image might already exist: " . $e->getMessage() . "</p>";
    }

    // Add payment_token to repair_jobs
    try {
        $db->exec("ALTER TABLE repair_jobs ADD COLUMN payment_token VARCHAR(64) NULL");
        echo "<p style='color:green;'>[SUCCESS] Added payment_token to repair_jobs.</p>";
    } catch (Exception $e) {
        echo "<p style='color:orange;'>[INFO] payment_token might already exist: " . $e->getMessage() . "</p>";
    }
    
    echo "<h3>Migration Complete! Pwede mo na i-test ulit yung feature.</h3>";
} catch (Exception $e) {
    echo "<p style='color:red;'>[ERROR] General error: " . $e->getMessage() . "</p>";
}
?>
