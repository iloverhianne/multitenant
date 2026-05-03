<?php
require_once 'db-config.php';
try {
    $db = getDB();
    // Update all users who are linked in the mechanics table to role 5
    $sql = "UPDATE users u JOIN mechanics m ON u.user_id = m.user_id SET u.role_id = 5 WHERE u.role_id != 5";
    $affected = $db->exec($sql);
    echo "Fixed roles for $affected mechanics.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
