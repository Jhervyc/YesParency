<?php
    // 1. Secure session cookie attributes (Must be set BEFORE session_start)
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);

        session_set_cookie_params([
            'lifetime' => 0,                  // Cookie expires when the browser closes
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // Enforce HTTPS if active
            'httponly' => true,               // Prevent JavaScript access (XSS defense)
            'samesite' => 'Lax'              // CSRF mitigation
        ]);

        session_start();
    }

    // 2. Load database configuration
    include ("../config/db_connect.php");

    // 3. Authentication & Role Validation (User specific)
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
        header("Location: ../login.php");
        exit();
    }

    // 4. Inactivity Timeout Guard (15 minutes / 900 seconds)
    $max_idle_time = 900;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_time)) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?error=session_timeout");
        exit();
    }
    $_SESSION['last_activity'] = time(); // Reset active timestamp on every request

    // 5. Hydrate Avatar
    if (!isset($_SESSION['profile_picture_url']) && isset($conn) && $conn instanceof mysqli) {
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