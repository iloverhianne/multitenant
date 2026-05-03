<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $db->prepare("UPDATE tenants SET ui_style = 'PREMIUM' WHERE tenant_id = 2")->execute();
    echo "Tenant 2 updated to PREMIUM style.";
} catch (Exception $e) {
    echo $e->getMessage();
}
