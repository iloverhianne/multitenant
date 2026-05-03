<?php
require_once 'db-config.php';
$db = getDB();
$res = $db->query("DESC tenants");
echo "<pre>";
print_r($res->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
?>
