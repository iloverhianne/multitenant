<?php
session_start();
require_once 'db-config.php';

echo "<h2>Debug Service Data</h2>";
echo "Current Session Tenant ID: " . ($_SESSION['tenant_id'] ?? 'NULL') . "<br>";
echo "Current Session User Name: " . ($_SESSION['name'] ?? 'NULL') . "<br>";

try {
    $db = getDB();
    
    echo "<h3>All Records in 'services' table:</h3>";
    $stmt = $db->query("SELECT * FROM services");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($services)) {
        echo "No services found in database at all.";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>ServiceID</th><th>TenantID</th><th>Name</th><th>Status</th><th>Created At</th></tr>";
        foreach ($services as $s) {
            $highlight = (isset($_SESSION['tenant_id']) && $s['tenant_id'] == $_SESSION['tenant_id']) ? "style='background: #e6fffa;'" : "";
            echo "<tr $highlight>";
            echo "<td>{$s['service_id']}</td>";
            echo "<td><b>{$s['tenant_id']}</b></td>";
            echo "<td>{$s['service_name']}</td>";
            echo "<td>{$s['status']}</td>";
            echo "<td>{$s['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p><i>Rows highlighted in green match your current session ID.</i></p>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
