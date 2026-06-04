<?php require "db.php"; $db = new PDO("mysql:host=localhost;dbname=autofixhub", "root", ""); $stmt = $db->query("SELECT * FROM appointments"); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); ?>
