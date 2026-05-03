<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $tables = ['tenants', 'customers', 'vehicles', 'services'];
    
    echo "<h2>Table Structures:</h2>";
    foreach ($tables as $table) {
        echo "<h3>Table: $table</h3><pre>";
        try {
            $stmt = $db->query("DESCRIBE $table");
            print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            echo "Error describing $table: " . $e->getMessage();
        }
        echo "</pre><hr>";
    }
} catch (Exception $e) {
    echo "Connection Error: " . $e->getMessage();
}
?>
