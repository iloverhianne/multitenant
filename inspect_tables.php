<?php
require_once 'db-config.php';
try {
    $db = getDB();
    function dumpTable($db, $table) {
        echo "Table: $table\n";
        $q = $db->query("DESCRIBE $table");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            print_r($row);
        }
        echo "\n";
    }
    dumpTable($db, 'subscription_plans');
    dumpTable($db, 'tenants');
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
