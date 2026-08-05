<?php
include("db.php"); // Change this to your database connection file

// Users to insert
$users = [
    [
        "firstname" => "Super",
        "lastname"  => "Admin",
        "email"     => "superadmin@example.com",
        "username"  => "superadmin",
        "password"  => "super123",
        "role"      => "superadmin",
        "status"    => "active"
    ],
    [
        "firstname" => "System",
        "lastname"  => "Admin",
        "email"     => "admin@example.com",
        "username"  => "admin",
        "password"  => "admin123",
        "role"      => "admin",
        "status"    => "active"
    ],
    [
        "firstname" => "Sample",
        "lastname"  => "Bidder",
        "email"     => "bidder@example.com",
        "username"  => "bidder",
        "password"  => "bidder123",
        "role"      => "bidder",
        "status"    => "active"
    ],
    [
        "firstname" => "Regular",
        "lastname"  => "User",
        "email"     => "user@example.com",
        "username"  => "user",
        "password"  => "user123",
        "role"      => "user",
        "status"    => "active"
    ]
];

// Prepare statement
$stmt = $conn->prepare("
    INSERT INTO users
    (firstname, lastname, email, username, password, role, status)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

foreach ($users as $user) {

    // Hash password
    $hashedPassword = password_hash($user["password"], PASSWORD_DEFAULT);

    $stmt->bind_param(
        "sssssss",
        $user["firstname"],
        $user["lastname"],
        $user["email"],
        $user["username"],
        $hashedPassword,
        $user["role"],
        $user["status"]
    );

    if ($stmt->execute()) {
        echo "✔ {$user['role']} account created.<br>";
    } else {
        echo "✖ Failed to create {$user['role']}: {$stmt->error}<br>";
    }
}

$stmt->close();
$conn->close();
?>