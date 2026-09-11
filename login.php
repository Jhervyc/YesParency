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

    include "config/db_connect.php";

    // 2. Redirect authenticated users directly to their respective dashboard
    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        switch ($_SESSION["role"]) {
            case "user":       header("Location: user/dashboard.php");       exit();
            case "bidder":     header("Location: bidder/dashboard.php");     exit();
            case "admin":      header("Location: admin/dashboard.php");      exit();
            case "superadmin": header("Location: admin/dashboard.php"); exit();
        }
    }

    $error = "";

    // 3. Session Timeout & Flash Message Handling
    if (isset($_GET['error']) && $_GET['error'] === 'session_timeout') {
        $error = "Your session expired due to inactivity. Please log in again.";
    }

    $flash_success = $_SESSION['inv_success'] ?? '';
    unset($_SESSION['inv_success']);

    // 4. Authenticate Submission
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {
                        // Regenerate Session ID to mitigate Session Fixation attacks
                        session_regenerate_id(true);

                        $_SESSION['user_id']       = $user['user_id'];
                        $_SESSION['username']      = $user['username'];
                        $_SESSION['role']          = $user['role'];
                        $_SESSION['last_activity'] = time(); // Set initial activity timestamp

                        switch ($_SESSION["role"]) {
                            case "user":       header("Location: user/dashboard.php");       exit();
                            case "bidder":     header("Location: bidder/dashboard.php");     exit();
                            case "admin":      header("Location: admin/dashboard.php");      exit();
                            case "superadmin": header("Location: admin/dashboard.php"); exit();
                        }
                    }
                }
                $stmt->close();
            }
        }

        $error = "Invalid username or password.";
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS: base -> shared components -> page-specific -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/pages/login.css">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
</head>
<body>

<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar('login'); ?>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="images/logo.png" alt="YesParency">
            <div>
                <div class="auth-logo-name">YesParency</div>
                <div class="auth-logo-sub">SLSU Procurement Portal</div>
            </div>
        </div>

        <h3>Welcome back</h3>
        <p>Sign in to your YesParency account</p>

        <?php if ($flash_success): ?>
            <div class="alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= htmlspecialchars($flash_success) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" novalidate>
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="bi bi-person input-icon-left"></i>
                    <input type="text" id="username" name="username" placeholder="Enter your username"
                           value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                           required autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="bi bi-lock input-icon-left"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password"
                           required autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Toggle password visibility">
                        <i class="bi bi-eye" id="toggle-icon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" name="login" class="btn-auth-submit">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
        </form>

        <div class="login-footer">
            Don't have an account? <a href="register.php">Request Access</a>
            &nbsp;&bull;&nbsp;
            <a href="forgot-password.php">Forgot password?</a>
        </div>

    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('toggle-icon');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>