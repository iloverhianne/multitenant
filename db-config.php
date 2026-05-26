<?php
// AutoFix Hub - Database Configuration
// Replace these with your actual InfinityFree MySQL details

define('DB_HOST', 'sql311.infinityfree.com');
define('DB_USER', 'if0_41381938');
define('DB_PASS', 'jeybipogi123');
define('DB_NAME', 'if0_41381938_multi_tenant');

function getDB() {
    try {
        $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $conn->exec("SET time_zone = '+08:00'");
        return $conn;
    } catch (PDOException $e) {
        throw new Exception("Database Connection failed: " . $e->getMessage());
    }
}
