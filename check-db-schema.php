<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $stmt = $db->query("SHOW CREATE TABLE customers");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<h1>Database Schema for Customers</h1>";
    echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";
    
    $stmt2 = $db->query("SHOW INDEX FROM customers");
    $indexes = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "<h2>Current Indexes</h2><table border='1'>";
    echo "<tr><th>Table</th><th>Non_unique</th><th>Key_name</th><th>Column_name</th></tr>";
    foreach($indexes as $idx) {
        echo "<tr><td>{$idx['Table']}</td><td>{$idx['Non_unique']}</td><td>{$idx['Key_name']}</td><td>{$idx['Column_name']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
