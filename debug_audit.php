<?php
require_once 'db-config.php';
try {
    $db = getDB();
    $logs = $db->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Laman ng Audit Trail (Last 10):</h3><pre>";
    print_r($logs);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
