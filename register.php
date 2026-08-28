<?php 
    session_start();

    include ("config/db_connect.php");

    $error   = $_SESSION['error'] ?? "";
    $success = $_SESSION['success'] ?? "";

    unset($_SESSION['error'], $_SESSION['success']);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $firstname       = trim($_POST['firstname'] ?? '');
        $lastname        = trim($_POST['lastname'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // 1. Backend Validation for Empty Fields
        if (empty($firstname) || empty($lastname) || empty($email) || empty($username) || empty($password) || empty($confirmPassword)) {
            $_SESSION['error'] = "All fields are required.";
        } 
        // 2. Validate Email Format
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Please enter a valid email address.";
        }
        // 3. Match Passwords
        elseif ($password !== $confirmPassword) {
            $_SESSION['error'] = "Passwords do not match.";
        } 
        // 4. Check for existing username or email
        else {
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $_SESSION['error'] = "Username or Email already exists.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO users (firstname, lastname, email, username, password) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $firstname, $lastname, $email, $username, $hashedPassword);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Registration successful! You can now log in.";
                } else {
                    $_SESSION['error'] = "Error: " . $stmt->error;
                }
            }
        }
        
        header("Location: register.php");
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | YesParency</title>
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
                <a href="login.php" class="btn-warning-nav">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ========================= -->
<!-- REGISTER SECTION          -->
<!-- ========================= -->
<section class="register-section">
    <div class="register-wrapper">

        <!-- LEFT BRANDING PANEL -->
        <div class="register-panel-left">
            <div class="panel-logo">
                <img src="images/procure.jpg" alt="Logo">
                <div class="panel-logo-text">
                    <div class="name">YesParency</div>
                    <div class="sub">Southern Luzon State University</div>
                </div>
            </div>

            <div class="panel-tagline">
                <h2>
                    Join the<br><span>Transparent</span><br>Process.
                </h2>
                <p>
                    Create a user account to track procurement projects, view real-time bid schedules, and monitor public disclosures at SLSU.
                </p>
            </div>

            <div class="panel-features">
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-person-check"></i></div>
                    Free user account registration
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-folder2-open"></i></div>
                    Access public bid schedules &amp; notices
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-shield-check"></i></div>
                    Compliant with RA 9184 transparency
                </div>
            </div>
        </div>

        <!-- RIGHT FORM PANEL -->
        <div class="register-panel-right">

            <div class="register-header">
                <h3>Create your account</h3>
                <p>Fill in the details below to register as a user</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="POST">

                <!-- Row 1: Name -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstname">First Name</label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon-left"></i>
                            <input
                                type="text"
                                id="firstname"
                                name="firstname"
                                placeholder="Juan"
                                value="<?= isset($_POST['firstname']) ? htmlspecialchars($_POST['firstname']) : '' ?>"
                                required
                                autocomplete="given-name"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="lastname">Last Name</label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon-left"></i>
                            <input
                                type="text"
                                id="lastname"
                                name="lastname"
                                placeholder="dela Cruz"
                                value="<?= isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : '' ?>"
                                required
                                autocomplete="family-name"
                            >
                        </div>
                    </div>
                </div>

                <!-- Row 2: Email + Username -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope input-icon-left"></i>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="juan@email.com"
                                value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                required
                                autocomplete="email"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <i class="bi bi-at input-icon-left"></i>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="juandelacruz"
                                value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                                required
                                autocomplete="username"
                            >
                        </div>
                    </div>
                </div>

                <!-- Row 3: Passwords -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock input-icon-left"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Create a password"
                                required
                                autocomplete="new-password"
                                oninput="checkStrength(this.value)"
                            >
                            <button type="button" class="toggle-password" onclick="togglePass('password','icon-pw')" aria-label="Toggle password">
                                <i class="bi bi-eye" id="icon-pw"></i>
                            </button>
                        </div>
                        <div class="strength-bar">
                            <span id="s1"></span>
                            <span id="s2"></span>
                            <span id="s3"></span>
                            <span id="s4"></span>
                        </div>
                        <div class="strength-label" id="strength-label"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock-fill input-icon-left"></i>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Repeat your password"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="toggle-password" onclick="togglePass('confirm_password','icon-cpw')" aria-label="Toggle password">
                                <i class="bi bi-eye" id="icon-cpw"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-register">
                    <i class="bi bi-person-plus-fill"></i>
                    Create Account
                </button>

            </form>

            <div class="login-footer">
                Already have an account?
                <a href="login.php">Sign in here</a>
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
                <li><a href="login.php">Sign In</a></li>
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
        <div>&copy; <?= date('Y') ?> YesParency &mdash; Southern Luzon State University. All Rights Reserved.</div>
        <div>Compliant with R.A. 9184 Government Procurement Standards</div>
    </div>
</footer>

<script>
    // Toggle password visibility
    function togglePass(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }

    // Password strength indicator
    function checkStrength(val) {
        const bars   = [document.getElementById('s1'), document.getElementById('s2'),
                        document.getElementById('s3'), document.getElementById('s4')];
        const label  = document.getElementById('strength-label');
        const levels = ['weak', 'fair', 'good', 'strong'];
        const texts  = ['Weak', 'Fair', 'Good', 'Strong'];

        let score = 0;
        if (val.length >= 8)              score++;
        if (/[A-Z]/.test(val))            score++;
        if (/[0-9]/.test(val))            score++;
        if (/[^A-Za-z0-9]/.test(val))     score++;

        bars.forEach((b, i) => {
            b.className = '';
            if (i < score) b.classList.add(levels[score - 1]);
        });

        label.textContent = val.length ? texts[score - 1] || '' : '';
    }
</script>

</body>
</html>
