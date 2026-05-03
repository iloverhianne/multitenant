<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $tenants = $db->query("SELECT tenant_id, shop_name FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($tenants);
} catch (Exception $e) {
    echo $e->getMessage();
}
