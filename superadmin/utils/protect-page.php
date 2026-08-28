<?php
    include ("../config/db_connect.php");

    session_start();

    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] != 'superadmin') {
        header("Location: ../login.php");
        exit();
    }

    // Hydrate avatar if not yet set in session
    if (!isset($_SESSION['profile_picture_url']) && isset($conn)) {
        $av_stmt = $conn->prepare("SELECT profile_picture_url FROM users WHERE user_id = ?");
        if ($av_stmt) {
            $av_stmt->bind_param("i", $_SESSION['user_id']);
            $av_stmt->execute();
            $av_row = $av_stmt->get_result()->fetch_assoc();
            $_SESSION['profile_picture_url'] = $av_row['profile_picture_url'] ?? '';
            $av_stmt->close();
        }
    }
?>