<?php
require_once 'db-config.php';
session_start();
$tenant_id = $_SESSION['tenant_id'];
$db = getDB();
$stmt = $db->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($res);
echo "</pre>";
?>
