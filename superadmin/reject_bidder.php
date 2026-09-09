<?php

include("utils/protect-page.php");

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$userId = (int)$_GET['id'];

$conn->begin_transaction();

try{

    // Revert to normal user
    $stmt = $conn->prepare("
        UPDATE users
        SET role = 'user',
            status = 'active'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    // Update application status
    $stmt = $conn->prepare("
        UPDATE bidder_profiles
        SET application_status = 'rejected'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $conn->commit();

    // Queue & send rejection notification to the specific bidder only
    require_once __DIR__ . '/../utils/mailer.php';
    notify_bidder_rejected($conn, $userId);

    header("Location: account.php");
    exit();

}catch(Exception $e){

    $conn->rollback();

    die("Failed to reject bidder.");

}