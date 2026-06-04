<?php
require 'database.php';
$stmt = $db->query("SELECT service_name, price FROM services");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
