<?php
require_once 'db-config.php';
$db = getDB();
echo "PLANS:\n";
var_dump($db->query("SELECT * FROM subscription_plans")->fetchAll(PDO::FETCH_ASSOC));
echo "\nSHOPS:\n";
var_dump($db->query("SELECT * FROM tenants")->fetchAll(PDO::FETCH_ASSOC));
