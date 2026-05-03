<?php
// AutoFix Hub - One-time Admin Fixer
require_once 'db-config.php';

try {
    $db = getDB();
    $new_email = 'superadmin';
    $password_hash = password_hash('admin123', PASSWORD_BCRYPT);

    // Update the record where role_id = 1 (SUPER_ADMIN)
    $stmt = $db->prepare("UPDATE users SET email = ?, password_hash = ? WHERE role_id = 1");
    $stmt->execute([$new_email, $password_hash]);

    if ($stmt->rowCount() > 0) {
        echo "<h2 style='color: green;'>✅ Success!</h2>";
        echo "<p>Super Admin account has been updated to:</p>";
        echo "<ul><li><b>Username:</b> superadmin</li><li><b>Password:</b> admin123</li></ul>";
    } else {
        // If no row was updated, maybe it doesn't exist yet, let's insert it
        $stmt = $db->prepare("INSERT IGNORE INTO users (tenant_id, role_id, name, email, password_hash, status) 
                               VALUES (NULL, 1, 'Main Super Admin', ?, ?, 'ACTIVE')");
        $stmt->execute([$new_email, $password_hash]);
        echo "<h2 style='color: green;'>✅ Success!</h2>";
        echo "<p>Super Admin account created with:</p>";
        echo "<ul><li><b>Username:</b> superadmin</li><li><b>Password:</b> admin123</li></ul>";
    }

    echo "<hr><p style='color: red;'><b>SECURITY:</b> Please delete this <u>update-admin.php</u> file immediately after running.</p>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
