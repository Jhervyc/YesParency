<?php
include "config/db_connect.php";

// Check Connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Ensure UTF-8 Encoding
$conn->set_charset("utf8mb4");

// 3. Define standard password hash for '123'
$hashedPassword = password_hash('123', PASSWORD_BCRYPT);

// 4. Define the 5 bidder accounts
$bidders = [
    [
        'firstname'     => 'April',
        'lastname'      => 'Cruz',
        'email'          => 'april@example.com',
        'username'       => 'april',
        'business_name'  => 'April Trading Corp.',
        'philgeps'       => 'PHILGEPS-2027-001',
        'tin'            => '100-000-001-000',
        'type'           => 'Corporation',
        'address'        => 'Lucena City, Quezon',
        'phone'          => '09171111111'
    ],
    [
        'firstname'     => 'Jhervy',
        'lastname'      => 'Reyes',
        'email'          => 'jhervy@example.com',
        'username'       => 'jhervy',
        'business_name'  => 'Jhervy Tech Solutions',
        'philgeps'       => 'PHILGEPS-2027-002',
        'tin'            => '100-000-002-000',
        'type'           => 'Sole Proprietorship',
        'address'        => 'Tayabas City, Quezon',
        'phone'          => '09172222222'
    ],
    [
        'firstname'     => 'Marvel',
        'lastname'      => 'Santos',
        'email'          => 'marvel@example.com',
        'username'       => 'marvel',
        'business_name'  => 'Marvel Supplies & Merchandise',
        'philgeps'       => 'PHILGEPS-2027-003',
        'tin'            => '100-000-003-000',
        'type'           => 'Sole Proprietorship',
        'address'        => 'Candelaria, Quezon',
        'phone'          => '09173333333'
    ],
    [
        'firstname'     => 'Mica',
        'lastname'      => 'Bautista',
        'email'          => 'mica@example.com',
        'username'       => 'mica',
        'business_name'  => 'Mica Builders & Construction',
        'philgeps'       => 'PHILGEPS-2027-004',
        'tin'            => '100-000-004-000',
        'type'           => 'Corporation',
        'address'        => 'Sariaya, Quezon',
        'phone'          => '09174444444'
    ],
    [
        'firstname'     => 'Adrian',
        'lastname'      => 'Mendoza',
        'email'          => 'adrian@example.com',
        'username'       => 'adrian',
        'business_name'  => 'Adrian Logistics Services',
        'philgeps'       => 'PHILGEPS-2027-005',
        'tin'            => '100-000-005-000',
        'type'           => 'Partnership',
        'address'        => 'Lucban, Quezon',
        'phone'          => '09175555555'
    ]
];

echo "<h2>Inserting Bidder Accounts via MySQLi...</h2>";

// 5. Execution using MySQLi Prepared Statements
foreach ($bidders as $b) {
    // Start Transaction
    $conn->begin_transaction();

    try {
        // Step A: Insert into `users` table
        $stmtUser = $conn->prepare("
            INSERT INTO users (firstname, lastname, email, username, password, role, status)
            VALUES (?, ?, ?, ?, ?, 'bidder', 'active')
        ");
        
        $stmtUser->bind_param("sssss", 
            $b['firstname'], 
            $b['lastname'], 
            $b['email'], 
            $b['username'], 
            $hashedPassword
        );

        $stmtUser->execute();
        $userId = $conn->insert_id; // Get generated user_id
        $stmtUser->close();

        // Step B: Insert into `bidder_profiles` table
        $stmtProfile = $conn->prepare("
            INSERT INTO bidder_profiles (
                user_id, business_name, philgeps_number, tin_number, 
                business_type, year_established, business_address, 
                business_email, business_phone, application_status
            ) VALUES (?, ?, ?, ?, ?, 2024, ?, ?, ?, 'approved')
        ");

        $stmtProfile->bind_param("isssssss", 
            $userId, 
            $b['business_name'], 
            $b['philgeps'], 
            $b['tin'], 
            $b['type'], 
            $b['address'], 
            $b['email'], 
            $b['phone']
        );

        $stmtProfile->execute();
        $stmtProfile->close();

        // Commit Transaction
        $conn->commit();
        echo "<p style='color:green;'>✔ Successfully created bidder: <strong>{$b['username']}</strong> (ID: {$userId})</p>";

    } catch (Exception $e) {
        // Rollback Transaction on error
        $conn->rollback();
        echo "<p style='color:red;'>✘ Failed to create {$b['username']}: " . $e->getMessage() . "</p>";
    }
}

// Close Database Connection
$conn->close();

echo "<h3>All Done! Password for all accounts is: <code>123</code></h3>";
?>