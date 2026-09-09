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
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 100px 16px 60px;
}

.auth-card {
    background: #fff; border: 1px solid #e2ece6;
    border-radius: 20px; padding: 40px 36px;
    width: 100%; max-width: 560px;
    box-shadow: 0 4px 32px rgba(6,37,27,.08);
}

.auth-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; }
.auth-logo img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
.auth-logo-name { font-size: 16px; font-weight: 800; color: #06251b; font-family: 'Space Grotesk', sans-serif; }
.auth-logo-sub  { font-size: 11px; color: #88968d; }

.auth-card h3 { font-size: 22px; font-weight: 800; color: #06251b; margin: 0 0 4px; font-family: 'Space Grotesk', sans-serif; }
.auth-card > p { font-size: 13px; color: #63736a; margin: 0 0 24px; }

.alert-error {
    background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px; font-weight: 600;
    margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
}
.alert-success {
    background: #eaf7ee; color: #1f7a3d; border: 1px solid #c9e8d3;
    border-radius: 10px; padding: 10px 14px; font-size: 12.5px; font-weight: 600;
    margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: #06251b; margin-bottom: 6px; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.input-icon-left { position: absolute; left: 13px; color: #88968d; font-size: 14px; pointer-events: none; }
.input-wrapper input {
    width: 100%; padding: 10px 42px 10px 38px;
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

.strength-bar { display: flex; gap: 4px; margin-top: 6px; }
.strength-bar span { flex: 1; height: 3px; border-radius: 4px; background: #e2ece6; transition: background .2s; }
.strength-bar span.weak   { background: #ef4444; }
.strength-bar span.fair   { background: #f97316; }
.strength-bar span.good   { background: #eab308; }
.strength-bar span.strong { background: #22c55e; }
.strength-label { font-size: 11px; font-weight: 700; color: #63736a; margin-top: 3px; }

.btn-register {
    width: 100%; background: #06251b; color: #ffc107; border: none;
    padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 800;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; margin-top: 6px;
}
.btn-register:hover { background: #144937; color: #fff; transform: translateY(-1px); }

.login-footer { text-align: center; margin-top: 20px; font-size: 13px; color: #63736a; }
.login-footer a { color: #1f7a3d; font-weight: 700; text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }

@media (max-width: 480px) { .auth-card { padding: 28px 20px; } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar('register'); ?>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="images/logo.png" alt="YesParency">
            <div>
                <div class="auth-logo-name">YesParency</div>
                <div class="auth-logo-sub">SLSU Procurement Portal</div>
            </div>
        </div>

        <h3>Create your account</h3>
        <p>Fill in the details below to register as a user</p>

        <?php if ($error): ?>
            <div class="alert-error"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="POST">

            <div class="form-row">
                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <div class="input-wrapper">
                        <i class="bi bi-person input-icon-left"></i>
                        <input type="text" id="firstname" name="firstname" placeholder="Juan"
                               value="<?= isset($_POST['firstname']) ? htmlspecialchars($_POST['firstname']) : '' ?>"
                               required autocomplete="given-name">
                    </div>
                </div>
                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <div class="input-wrapper">
                        <i class="bi bi-person input-icon-left"></i>
                        <input type="text" id="lastname" name="lastname" placeholder="dela Cruz"
                               value="<?= isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : '' ?>"
                               required autocomplete="family-name">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon-left"></i>
                        <input type="email" id="email" name="email" placeholder="juan@email.com"
                               value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                               required autocomplete="email">
                    </div>
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="bi bi-at input-icon-left"></i>
                        <input type="text" id="username" name="username" placeholder="juandelacruz"
                               value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                               required autocomplete="username">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock input-icon-left"></i>
                        <input type="password" id="password" name="password" placeholder="Create a password"
                               required autocomplete="new-password" oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-password" onclick="togglePass('password','icon-pw')">
                            <i class="bi bi-eye" id="icon-pw"></i>
                        </button>
                    </div>
                    <div class="strength-bar"><span id="s1"></span><span id="s2"></span><span id="s3"></span><span id="s4"></span></div>
                    <div class="strength-label" id="strength-label"></div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock-fill input-icon-left"></i>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password"
                               required autocomplete="new-password">
                        <button type="button" class="toggle-password" onclick="togglePass('confirm_password','icon-cpw')">
                            <i class="bi bi-eye" id="icon-cpw"></i>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-register">
                <i class="bi bi-person-plus-fill"></i> Create Account
            </button>

        </form>

        <div class="login-footer">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>

    </div>
</div>

<script>
function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function checkStrength(val) {
    const bars  = ['s1','s2','s3','s4'].map(id => document.getElementById(id));
    const label = document.getElementById('strength-label');
    const levels = ['weak','fair','good','strong'];
    const texts  = ['Weak','Fair','Good','Strong'];
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    bars.forEach((b, i) => { b.className = i < score ? levels[score - 1] : ''; });
    label.textContent = val.length ? (texts[score - 1] || '') : '';
}
</script>
</body>
</html>

