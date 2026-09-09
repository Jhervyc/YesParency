<?php

include("utils/protect-page.php");
include("utils/protect-secretariat.php");

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$userId = (int)$_GET['id'];

$conn->begin_transaction();

try{
    // Snapshot before change
    $snap = $conn->prepare("SELECT u.username, u.role, u.status, bp.business_name, bp.application_status FROM users u LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id WHERE u.user_id = ?");
    $snap->bind_param("i", $userId);
    $snap->execute();
    $old_data = $snap->get_result()->fetch_assoc();
    $snap->close();

    // Update user account
    $stmt = $conn->prepare("
        UPDATE users
        SET role = 'bidder',
            status = 'active'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    // Update application status
    $stmt = $conn->prepare("
        UPDATE bidder_profiles
        SET application_status = 'approved'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $bname = !empty($old_data['business_name']) ? $old_data['business_name'] : (!empty($old_data['username']) ? '@' . $old_data['username'] : "User #$userId");
    audit_log(
        $conn,
        'BIDDER_APPROVED',
        'users',
        $userId,
        "Approved bidder application for {$bname}",
        [
            'role' => $old_data['role'] ?? 'user',
            'status' => $old_data['status'] ?? 'pending',
            'application_status' => $old_data['application_status'] ?? 'pending'
        ],
        [
            'role' => 'bidder',
            'status' => 'active',
            'application_status' => 'approved'
        ]
    );

    $conn->commit();

    // Queue & send approval notification to the approved bidder only
    require_once __DIR__ . '/../utils/mailer.php';
    notify_bidder_approved($conn, $userId);

    header("Location: account-management.php");
    exit();

}catch(Exception $e){

    $conn->rollback();

    die("Failed to approve bidder.");

}