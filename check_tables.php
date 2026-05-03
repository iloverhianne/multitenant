<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $tables = ['customers', 'vehicles', 'services', 'repair_jobs', 'appointments', 'repair_timeline', 'payments'];
    echo "Checking tables:\n";
    foreach ($tables as $table) {
        try {
            $db->query("SELECT 1 FROM $table LIMIT 1");
            echo "[OK] $table exists.\n";
        } catch (Exception $e) {
            echo "[FAIL] $table does not exist or error: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
