<?php

include("utils/protect-page.php");

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$userId = (int)$_GET['id'];

$conn->begin_transaction();

try{

    // Update user account
    $stmt = $conn->prepare("
        UPDATE users
        SET role = 'bidder',
            status = 'active'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    // Update application status
    $stmt = $conn->prepare("
        UPDATE bidder_profiles
        SET application_status = 'approved'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $conn->commit();

    header("Location: account.php");
    exit();

}catch(Exception $e){

    $conn->rollback();

    die("Failed to approve bidder.");

}