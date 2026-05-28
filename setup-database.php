<?php
require_once 'db-config.php';

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h2>Starting Database Migration...</h2><ul>";

    /**
     * NOTE: We are using ENGINE=MyISAM and removing explicit FOREIGN KEY constraints.
     * This is because your hosting environment (InfinityFree) uses MyISAM by default, 
     * which does not support Foreign Keys. The relationship will be handled by the 
     * PHP logic (as we already do in the dashboard).
     */

    // 0. ROLES TABLE
    $sql0 = "CREATE TABLE IF NOT EXISTS roles (
        role_id INT PRIMARY KEY,
        role_name VARCHAR(50) NOT NULL
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql0);
    $db->exec("INSERT IGNORE INTO roles (role_id, role_name) VALUES (1, 'SUPER_ADMIN'), (2, 'OWNER'), (3, 'MANAGER'), (4, 'CASHIER'), (5, 'MECHANIC')");
    echo "<li>[OK] <b>roles</b> table checked/created/seeded.</li>";

    // 0.1 USERS TABLE
    $sql01 = "CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        role_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (role_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql01);
    echo "<li>[OK] <b>users</b> table checked/created.</li>";

    // 1. CUSTOMERS TABLE
    $sql1 = "CREATE TABLE IF NOT EXISTS customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        email VARCHAR(100),
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        UNIQUE KEY unique_customer_tenant (tenant_id, email)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql1);
    echo "<li>[OK] <b>customers</b> table checked/created.</li>";
    
    // Patch for existing customers table
    try { $db->exec("ALTER TABLE customers ADD UNIQUE KEY unique_customer_tenant (tenant_id, email)"); } catch(Exception $e) {}

    // 2. VEHICLES TABLE
    $sql2 = "CREATE TABLE IF NOT EXISTS vehicles (
        vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        tenant_id INT NOT NULL,
        plate_no VARCHAR(20) NOT NULL,
        make VARCHAR(50) NOT NULL,
        model VARCHAR(50) NOT NULL,
        year INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id),
        INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql2);
    echo "<li>[OK] <b>vehicles</b> table checked/created.</li>";

    // 3. SERVICES TABLE
    $sql3 = "CREATE TABLE IF NOT EXISTS services (
        service_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        min_price DECIMAL(10,2) NULL,
        max_price DECIMAL(10,2) NULL,
        status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql3);
    
    // Patch for existing table
    try { $db->exec("ALTER TABLE services ADD COLUMN min_price DECIMAL(10,2) NULL, ADD COLUMN max_price DECIMAL(10,2) NULL"); } catch(Exception $e) {}

    echo "<li>[OK] <b>services</b> table checked/created/patched.</li>";

    // 4. REPAIR JOBS TABLE
    $sql4 = "CREATE TABLE IF NOT EXISTS repair_jobs (
        job_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        customer_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        service_id INT NULL,
        bay_id INT NULL,
        mechanic_id INT NULL,
        status ENUM('PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED') DEFAULT 'PENDING',
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (customer_id),
        INDEX (vehicle_id),
        INDEX (service_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql4);

    // Patch for existing table
    try { $db->exec("ALTER TABLE repair_jobs ADD COLUMN service_id INT NULL AFTER vehicle_id, ADD INDEX (service_id)"); } catch(Exception $e) {}

    echo "<li>[OK] <b>repair_jobs</b> table checked/created/patched.</li>";

    // 5. APPOINTMENTS TABLE
    $sql5 = "CREATE TABLE IF NOT EXISTS appointments (
        appointment_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        customer_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        service_id INT NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('PENDING', 'CONFIRMED', 'CANCELLED', 'COMPLETED') DEFAULT 'PENDING',
        total_estimate DECIMAL(10,2) DEFAULT 0.00,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (customer_id),
        INDEX (vehicle_id),
        INDEX (service_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql5);
    echo "<li>[OK] <b>appointments</b> table checked/created.</li>";

    // 6. REPAIR TIMELINE TABLE
    $sql6 = "CREATE TABLE IF NOT EXISTS repair_timeline (
        timeline_id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        status_update VARCHAR(50) NOT NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (job_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql6);
    echo "<li>[OK] <b>repair_timeline</b> table checked/created.</li>";

    // 7. PAYMENTS TABLE
    $sql7 = "CREATE TABLE IF NOT EXISTS payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        customer_id INT NOT NULL,
        job_id INT NULL,
        appointment_id INT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        payment_type ENUM('DOWNPAYMENT', 'FULL_PAYMENT') NOT NULL,
        status ENUM('PENDING', 'SUCCESS', 'FAILED') DEFAULT 'PENDING',
        transaction_ref VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (customer_id),
        INDEX (job_id),
        INDEX (appointment_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql7);
    echo "<li>[OK] <b>payments</b> table checked/created.</li>";

    // 8. MECHANICS TABLE
    $sql8 = "CREATE TABLE IF NOT EXISTS mechanics (
        mechanic_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        specialization VARCHAR(100),
        status ENUM('AVAILABLE', 'BUSY', 'OFF_DUTY') DEFAULT 'AVAILABLE',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql8);
    echo "<li>[OK] <b>mechanics</b> table checked/created.</li>";

    // 9. SERVICE BAYS TABLE
    $sql9 = "CREATE TABLE IF NOT EXISTS service_bays (
        bay_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        bay_name VARCHAR(50) NOT NULL,
        status ENUM('AVAILABLE', 'OCCUPIED', 'MAINTENANCE') DEFAULT 'AVAILABLE',
        current_job_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql9);
    echo "<li>[OK] <b>service_bays</b> table checked/created.</li>";

    // 10. INVENTORY TABLE
    $sql10 = "CREATE TABLE IF NOT EXISTS inventory (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        item_code VARCHAR(50) NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        brand VARCHAR(50),
        quantity INT DEFAULT 0,
        price DECIMAL(10,2) NOT NULL,
        status ENUM('IN_STOCK', 'LOW_STOCK', 'OUT_OF_STOCK') DEFAULT 'IN_STOCK',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql10);
    echo "<li>[OK] <b>inventory</b> table checked/created.</li>";

    echo "</ul><h3 style='color:green;'>Migration complete! Ready for Mobile App backend API.</h3>";

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>Migration Failed:</h3><p>" . $e->getMessage() . "</p>";
}
