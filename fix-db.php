<?php
require_once 'db-config.php';

try {
    $db = getDB();
    echo "<h2>Fixing Database Schema...</h2><ul>";

    // Fix Services Table
    $cols_to_add = [
        "status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE' AFTER price",
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status"
    ];

    foreach ($cols_to_add as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try {
            $db->query("SELECT $col_name FROM services LIMIT 1");
            echo "<li>Column '$col_name' already exists in 'services'.</li>";
        } catch (Exception $e) {
            $db->exec("ALTER TABLE services ADD COLUMN $col_def");
            echo "<li>[FIXED] Added column '$col_name' to 'services' table.</li>";
        }
    }

    echo "</ul><p><b>Done!</b> Please refresh your dashboard.</p>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
