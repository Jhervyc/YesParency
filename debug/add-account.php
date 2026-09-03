<?php
// seed_accounts.php
include "../config/db_connect.php";

// 1. Check Database Connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Ensure UTF-8 Encoding
$conn->set_charset("utf8mb4");

// Enable MySQLi exception reporting for clean try-catch handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 2. Define standard password hash for '123'
$hashedPassword = password_hash('123', PASSWORD_BCRYPT);

// -----------------------------------------------------------------
// SECTION A: INSERT 5 BIDDER ACCOUNTS + PROFILES
// -----------------------------------------------------------------
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

echo "<h2>Inserting Bidder Accounts...</h2>";

foreach ($bidders as $b) {
    $conn->begin_transaction();

    try {
        // Step A1: Insert into users table
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
        $userId = $conn->insert_id;
        $stmtUser->close();

        // Step A2: Insert into bidder_profiles table
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

        $conn->commit();
        echo "<p style='color:green;'>✔ Successfully created bidder: <strong>{$b['username']}</strong> (ID: {$userId})</p>";

    } catch (Exception $e) {
        $conn->rollback();
        echo "<p style='color:red;'>✘ Failed to create bidder {$b['username']}: " . $e->getMessage() . "</p>";
    }
}

// -----------------------------------------------------------------
// SECTION B: INSERT 10 STANDARD USER ACCOUNTS
// -----------------------------------------------------------------
$users = [
    ['firstname' => 'Juan',       'lastname' => 'Dela Cruz', 'email' => 'juan.delacruz@example.com', 'username' => 'juandc'],
    ['firstname' => 'Maria',      'lastname' => 'Santos',    'email' => 'maria.santos@example.com',  'username' => 'mariaser'],
    ['firstname' => 'Jose',       'lastname' => 'Rizal',     'email' => 'jose.rizal@example.com',    'username' => 'joser'],
    ['firstname' => 'Andres',     'lastname' => 'Bonifacio', 'email' => 'andres.b@example.com',      'username' => 'andresb'],
    ['firstname' => 'Emilio',     'lastname' => 'Aguinaldo', 'email' => 'emilio.a@example.com',      'username' => 'emilioa'],
    ['firstname' => 'Apolinario', 'lastname' => 'Mabini',    'email' => 'apolinario.m@example.com', 'username' => 'apolinariom'],
    ['firstname' => 'Gabriela',   'lastname' => 'Silang',    'email' => 'gabriela.s@example.com',   'username' => 'gabrielas'],
    ['firstname' => 'Melchora',   'lastname' => 'Aquino',    'email' => 'melchora.a@example.com',   'username' => 'tandangsora'],
    ['firstname' => 'Marcelo',    'lastname' => 'Del Pilar', 'email' => 'marcelo.d@example.com',    'username' => 'marcelodp'],
    ['firstname' => 'Antonio',    'lastname' => 'Luna',      'email' => 'antonio.luna@example.com',  'username' => 'antoniol']
];

echo "<h2>Inserting 10 Standard Users...</h2>";

$stmtUserOnly = $conn->prepare("
    INSERT INTO users (firstname, lastname, email, username, password, role, status)
    VALUES (?, ?, ?, ?, ?, 'user', 'active')
");

$successCount = 0;

foreach ($users as $u) {
    try {
        $stmtUserOnly->bind_param("sssss", 
            $u['firstname'], 
            $u['lastname'], 
            $u['email'], 
            $u['username'], 
            $hashedPassword
        );

        $stmtUserOnly->execute();
        $userId = $conn->insert_id;
        $successCount++;
        
        echo "<p style='color:green;'>✔ Created user: <strong>{$u['username']}</strong> (ID: {$userId})</p>";

    } catch (mysqli_sql_exception $e) {
        echo "<p style='color:red;'>✘ Failed to create standard user {$u['username']}: " . $e->getMessage() . "</p>";
    }
}

$stmtUserOnly->close();

// Close Connection once at the very end
$conn->close();

echo "<h3>All Done! Successfully inserted 5 bidders and {$successCount}/10 standard users. Default password for all accounts is: <code>123</code></h3>";
?>