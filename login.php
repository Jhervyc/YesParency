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
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons only (no Bootstrap CSS) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Login styles -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ========================= -->
<!-- NAVBAR                    -->
<!-- ========================= -->
<nav class="navbar">
    <div class="nav-container">
        <a class="navbar-brand" href="index.php">
            <img src="images/procure.jpg" alt="YesParency Logo">
            <div>
                <div class="brand-name">YesParency</div>
                <div class="brand-sub">Procurement System</div>
            </div>
        </a>
        <ul class="nav-menu">
            <li><a href="index.php" class="nav-link">Home</a></li>
            <li><a href="#" class="nav-link">Bid Calendar</a></li>
            <li><a href="#" class="nav-link">Announcements</a></li>
            <li><a href="#" class="nav-link">Benefits</a></li>
            <li><a href="#" class="nav-link">About</a></li>
            <li style="margin-left: 16px;">
                <a href="login.php" class="btn-warning-nav">
                    <i class="bi bi-box-arrow-in-right"></i> Login
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
                <a href="register.php">Register as Bidder</a>
            </div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- FOOTER                    -->
<!-- ========================= -->
<footer class="footer">
    <div class="footer-grid" style="max-width:1200px; margin:0 auto; padding:0 20px;">

        <div>
            <div class="footer-brand">
                <img src="images/procure.jpg" alt="SLSU Logo" class="logo">
                <div>
                    <h3>YesParency</h3>
                    <p>Southern Luzon State University<br>Procurement Office</p>
                </div>
            </div>
        </div>

        <div>
            <h5>Quick Links</h5>
            <ul>
                <li><a href="#">Home</a></li>
                <li><a href="#">Bid Opportunities</a></li>
                <li><a href="#">Bid Results</a></li>
                <li><a href="#">Announcements</a></li>
                <li><a href="#">Contact</a></li>
            </ul>
        </div>

        <div>
            <h5>Suppliers</h5>
            <ul>
                <li><a href="#">Register</a></li>
                <li><a href="#">Supplier Guide</a></li>
                <li><a href="#">Requirements</a></li>
                <li><a href="#">FAQs</a></li>
            </ul>
        </div>

        <div>
            <h5>Contact Us</h5>
            <ul class="contact-list">
                <li><i class="bi bi-geo-alt"></i> Southern Luzon State University, Lucban, Quezon</li>
                <li><i class="bi bi-telephone"></i> 0000-000</li>
                <li><i class="bi bi-envelope"></i> procurement@slsu.edu.ph</li>
            </ul>
        </div>

        <div>
            <h5>Connect With Us</h5>
            <div class="social-links">
                <a href="https://www.facebook.com/profile.php?id=61573070853148" aria-label="Facebook">
                    <i class="bi bi-facebook"></i>
                </a>
                <a href="#" aria-label="Website">
                    <i class="bi bi-globe"></i>
                </a>
                <a href="mailto:slsuprocurement@slsu.edu.ph" aria-label="Email">
                    <i class="bi bi-envelope-fill"></i>
                </a>
            </div>
        </div>

    </div>

    <div style="max-width:1200px; margin:0 auto; padding:0 20px;">
        <hr>
        <div class="footer-bottom">
            <p>© 2026 YesParency. All Rights Reserved.</p>
            <p>Developed for the Southern Luzon State University Procurement Office.</p>
        </div>
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
