<?php
require_once 'db-config.php';
$db = getDB();
$q = $db->query("DESCRIBE subscription_plans");
print_r($q->fetchAll());
?>
