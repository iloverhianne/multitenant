<?php
require_once 'db-config.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $tenant_id = 1; // Assuming tenant 1 for test
    $stmt = $db->prepare("SELECT v.*, c.full_name AS owner_name FROM vehicles v LEFT JOIN customers c ON v.customer_id = c.customer_id WHERE v.tenant_id = ? ORDER BY v.created_at DESC");
    $stmt->execute([$tenant_id]);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($res);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
