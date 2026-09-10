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
    <link rel="stylesheet" href="style.css">
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar_css(); ?>
<style>
* { box-sizing: border-box; }
body { background: #f4f8f5; font-family: 'Poppins', sans-serif; margin: 0; }

.auth-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 100px 16px 60px;
    background: #f4f8f5;
}

.auth-card {
    background: #fff;
    border: 1px solid #e2ece6;
    border-radius: 20px;
    padding: 40px 36px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 4px 32px rgba(6,37,27,.08);
}

.auth-logo {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 28px;
}
.auth-logo img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
.auth-logo-name { font-size: 16px; font-weight: 800; color: #06251b; font-family: 'Space Grotesk', sans-serif; }
.auth-logo-sub  { font-size: 11px; color: #88968d; }

.auth-card h3 { font-size: 22px; font-weight: 800; color: #06251b; margin: 0 0 4px; font-family: 'Space Grotesk', sans-serif; }
.auth-card > p { font-size: 13px; color: #63736a; margin: 0 0 24px; }

.alert-error {
    background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px;
    font-weight: 600; margin-bottom: 18px;
    display: flex; align-items: center; gap: 8px;
}

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: #06251b; margin-bottom: 6px; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.input-icon-left { position: absolute; left: 13px; color: #88968d; font-size: 14px; pointer-events: none; }
.input-wrapper input {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid #d4e0d8; border-radius: 10px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    color: #1a1a1a; outline: none; background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.input-wrapper input:focus { border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1); }
.toggle-password {
    position: absolute; right: 12px; background: none; border: none;
    color: #88968d; cursor: pointer; font-size: 14px; padding: 4px;
    display: flex; align-items: center;
}
.toggle-password:hover { color: #06251b; }

.btn-login {
    width: 100%; background: #06251b; color: #ffc107; border: none;
    padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 800;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; margin-top: 6px;
}
.btn-login:hover { background: #144937; color: #fff; transform: translateY(-1px); }

.login-footer { text-align: center; margin-top: 20px; font-size: 13px; color: #63736a; }
.login-footer a { color: #1f7a3d; font-weight: 700; text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }

@media (max-width: 480px) {
    .auth-card { padding: 28px 20px; }
}
</style>
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
            <div class="alert-error" style="background:#eaf7ee; color:#1f7a3d; border-color:#c9e8d3;">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($flash_success) ?>
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

            <button type="submit" name="login" class="btn-login">
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