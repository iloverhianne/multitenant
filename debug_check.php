<?php
require_once 'db-config.php';
$db = getDB();

echo "--- SUBSCRIPTION PLANS ---\n";
$plans = $db->query("SELECT plan_id, plan_name, price, price_yearly FROM subscription_plans")->fetchAll(PDO::FETCH_ASSOC);
print_r($plans);

echo "\n--- TENANT SUBSCRIPTIONS (Latest 5) ---\n";
$subs = $db->query("SELECT s.*, t.shop_name FROM tenant_subscriptions s JOIN tenants t ON s.tenant_id = t.tenant_id ORDER BY s.subscription_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($subs);

echo "\n--- TENANTS (MotoHub) ---\n";
$motohub = $db->query("SELECT * FROM tenants WHERE shop_name LIKE '%MotoHub%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($motohub);
