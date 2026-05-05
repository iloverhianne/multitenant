<?php
require_once 'db_connect.php';
header('Content-Type: application/json');
try {
    $stmt = $db->query("SELECT * FROM inventory LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'columns' => array_keys($row ?: [])]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
