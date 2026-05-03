<?php
// debug_customers.php - Management Tool
require_once 'db-config.php';
$db = getDB();

// Handle Delete Action
if (isset($_GET['delete_id'])) {
    $stmt = $db->prepare("DELETE FROM customers WHERE customer_id = ?");
    $stmt->execute([$_GET['delete_id']]);
    header("Location: debug_customers.php?msg=Deleted");
    exit;
}

try {
    $stmt = $db->query("SELECT c.*, t.shop_name FROM customers c LEFT JOIN tenants t ON c.tenant_id = t.tenant_id");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<html><head><style>
        body { font-family: sans-serif; padding: 20px; background: #f4f4f9; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        th { background: #6366f1; color: white; }
        tr:nth-child(even) { background: #fafafa; }
        .btn-del { color: #ef4444; text-decoration: none; font-weight: bold; }
        .shop-tag { background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style></head><body>";

    echo "<h1>Customer Database Management</h1>";
    if (isset($_GET['msg']))
        echo "<p style='color:green;'>Action successful!</p>";

    if (empty($customers)) {
        echo "<p>No customers found.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Shop (Tenant ID)</th><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr>";
        foreach ($customers as $c) {
            $shop = htmlspecialchars($c['shop_name'] ?? 'Unknown') . " (ID: {$c['tenant_id']})";
            echo "<tr>
                    <td>{$c['customer_id']}</td>
                    <td><span class='shop-tag'>{$shop}</span></td>
                    <td>" . htmlspecialchars($c['full_name']) . "</td>
                    <td>" . htmlspecialchars($c['email']) . "</td>
                    <td>{$c['status']}</td>
                    <td><a href='?delete_id={$c['customer_id']}' class='btn-del' onclick='return confirm(\"Delete this user?\")'>Delete</a></td>
                  </tr>";
        }
        echo "</table>";
    }
    echo "<br><a href='index.php'>Back to Home</a>";
    echo "</body></html>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
