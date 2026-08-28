<?php
    include "config/db_connect.php";
    session_start();

    if (isset($_SESSION['user_id'])) {
        switch($_SESSION["role"]){
            case "user":      header("Location: user/dashboard.php");      exit();
            case "bidder":    header("Location: bidder/dashboard.php");    exit();
            case "admin":     header("Location: admin/dashboard.php");     exit();
            case "superadmin":header("Location: superadmin/dashboard.php");exit();
        }
    }

    $error = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                switch($_SESSION["role"]){
                    case "user":      header("Location: user/dashboard.php");      exit();
                    case "bidder":    header("Location: bidder/dashboard.php");    exit();
                    case "admin":     header("Location: admin/dashboard.php");     exit();
                    case "superadmin":header("Location: superadmin/dashboard.php");exit();
                }
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
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Global stylesheet -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ========================= -->
<!-- NAVBAR                    -->
<!-- ========================= -->
<nav class="navbar">
    <div class="nav-container">
        <a class="navbar-brand" href="index.php">
            <img src="images/logo.png" alt="YesParency Logo">
            <div>
                <div class="brand-name">YesParency</div>
                <div class="brand-sub">SLSU Procurement Portal</div>
            </div>
        </a>
        <ul class="nav-menu">
            <li><a href="index.php#home" class="nav-link">Home</a></li>
            <li><a href="index.php#bid-schedule" class="nav-link">Bid Schedule</a></li>
            <li><a href="index.php#about" class="nav-link">About</a></li>
            <li style="margin-left: 16px;">
                <a href="register.php" class="btn-warning-nav">
                    <i class="bi bi-person-plus-fill"></i> Register
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ========================= -->
<!-- LOGIN                     -->
<!-- ========================= --> 
<section class="login-section">
    <div class="login-wrapper">

        <!-- LEFT PANEL -->
        <div class="login-panel-left">
            <div class="panel-logo">
                <img src="images/procure.jpg" alt="Logo">
                <div class="panel-logo-text">
                    <div class="name">YesParency</div>
                    <div class="sub">Southern Luzon State University</div>
                </div>
            </div>

            <div class="panel-tagline">
                <h2>
                    <span>Fair.</span> Secure.<br>Transparent.
                </h2>
                <p>
                    Access the SLSU Procurement Portal to manage bids,
                    track procurements, and participate in transparent
                    public procurement.
                </p>
            </div>

            <div class="panel-features">
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-shield-check"></i></div>
                    Secure and encrypted access
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-broadcast"></i></div>
                    Real-time live bid monitoring
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-file-earmark-check"></i></div>
                    Compliant with RA 9184
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-panel-right">
            <div class="login-header">
                <h3>Welcome back</h3>
                <p>Sign in to your YesParency account</p>
            </div>

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
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                            required
                            autocomplete="username"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock input-icon-left"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Sign In
                </button>
            </form>

            <div class="login-footer">
                Don't have an account yet?
                <a href="register.php">Create a User Account</a>
            </div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- FOOTER                    -->
<!-- ========================= -->
<style>
    .footer-new {
        background: #020c09;
        color: #a4b8ad;
        padding: 50px 24px 24px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        margin-top: 0;
    }
    .footer-new-grid {
        max-width: 1240px;
        margin: 0 auto 32px;
        display: grid;
        grid-template-columns: 1.5fr 1fr 1.2fr;
        gap: 40px;
    }
    @media (max-width: 768px) {
        .footer-new-grid { grid-template-columns: 1fr; gap: 24px; }
    }
    .footer-new .fn-brand-title {
        font-size: 17px;
        font-weight: 800;
        color: #ffc107;
        font-family: 'Space Grotesk', sans-serif;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .footer-new .fn-brand-desc {
        font-size: 12.5px;
        line-height: 1.6;
        color: #8fa699;
        max-width: 360px;
    }
    .footer-new .fn-col h5 {
        font-size: 12px;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .footer-new .fn-col ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .footer-new .fn-col ul a {
        color: #8fa699;
        text-decoration: none;
        font-size: 13px;
        transition: color 0.15s;
    }
    .footer-new .fn-col ul a:hover { color: #ffc107; }
    .footer-new .fn-bottom {
        max-width: 1240px;
        margin: 0 auto;
        padding-top: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 12px;
        flex-wrap: wrap;
        gap: 10px;
        color: #6c8276;
    }
</style>
<footer class="footer-new">
    <div class="footer-new-grid">

        <div>
            <div class="fn-brand-title">
                <i class="bi bi-transparency"></i> YesParency Portal
            </div>
            <p class="fn-brand-desc">
                Southern Luzon State University's official digital procurement transparency system. Empowering suppliers with fair competition and public accountability.
            </p>
        </div>

        <div class="fn-col">
            <h5>Navigation</h5>
            <ul>
                <li><a href="index.php#home">Home</a></li>
                <li><a href="index.php#bid-schedule">Bid Schedule</a></li>
                <li><a href="index.php#about">About System</a></li>
                <li><a href="register.php">Create Account</a></li>
            </ul>
        </div>

        <div class="fn-col">
            <h5>Governance</h5>
            <ul>
                <li><a href="https://www.philgeps.gov.ph" target="_blank" rel="noopener">PhilGEPS Portal</a></li>
                <li><a href="https://gppb.gov.ph" target="_blank" rel="noopener">GPPB R.A. 9184 Guidelines</a></li>
                <li><a href="https://slsu.edu.ph" target="_blank" rel="noopener">SLSU Official Website</a></li>
            </ul>
        </div>

    </div>

    <div class="fn-bottom">
        <div>&copy; <?= date('Y') ?> YesParency — Southern Luzon State University. All Rights Reserved.</div>
        <div>Compliant with R.A. 9184 Government Procurement Standards</div>
    </div>
</footer>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon  = document.getElementById('toggle-icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }
</script>

</body>
</html>
