<?php
    // 1. Initialize session to gain access to current parameters
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 2. Unset all session variables
    $_SESSION = array();

    // 3. Delete the session cookie from the user's browser
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // 4. Destroy the session on the server
    session_destroy();

    // 5. Redirect to login with confirmation
    header("Location: login.php?logged_out=1");
    exit();
?>