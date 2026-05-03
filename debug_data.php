<?php
require_once 'db-config.php';
try {
    $db = getDB();
    echo "--- TENANTS ---\n";
    $q = $db->query("SELECT * FROM tenants");
    print_r($q->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\n--- SERVICES ---\n";
    $q = $db->query("SELECT * FROM services");
    print_r($q->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- USERS ---\n";
    $q = $db->query("SELECT user_id, tenant_id, name, email FROM users");
    print_r($q->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
