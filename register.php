<?php 
    include ("config/db_connect.php");

    $error   = "";
    $success = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $firstname       = trim($_POST['firstname']);
        $lastname        = trim($_POST['lastname']);
        $email           = trim($_POST['email']);
        $username        = trim($_POST['username']);
        $password        = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        $status          = "active";

        if ($password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            /* Check existing username/email */
            $check = $conn->prepare(
                "SELECT user_id FROM users
                WHERE username = ? OR email = ?"
            );
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $error = "Username or Email already exists.";
            } else {
                /* Hash password */
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                /* Insert user */
                $stmt = $conn->prepare(
                    "INSERT INTO users
                    (firstname, lastname, email, username, password)
                    VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssss", $firstname, $lastname, $email, $username, $hashedPassword);

                if ($stmt->execute()) {
                    $success = "Registration successful! You can now log in.";
                } else {
                    $error = "Error: " . $stmt->error;
                }
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | YesParency</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons only (no Bootstrap CSS) -->
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
                    Register as a supplier and participate in SLSU's
                    open, fair, and compliant procurement system.
                </p>
            </div>

            <div class="panel-features">
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-person-check"></i></div>
                    Free supplier registration
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-folder2-open"></i></div>
                    Access all active bid opportunities
                </div>
                <div class="panel-feature-item">
                    <div class="feat-icon"><i class="bi bi-shield-check"></i></div>
                    Compliant with RA 9184
                </div>
            </div>
        </div>

        <!-- RIGHT FORM PANEL -->
        <div class="register-panel-right">

            <div class="register-header">
                <h3>Create your account</h3>
                <p>Fill in the details below to register as a bidder</p>
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

            <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="POST" novalidate>

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
