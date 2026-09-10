<?php
session_start();
include("config/db_connect.php");

$error   = $_SESSION['reg_error']   ?? '';
$success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_error'], $_SESSION['reg_success']);

// Preserve form values on error
$old = $_SESSION['reg_old'] ?? [];
unset($_SESSION['reg_old']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name     = trim($_POST['company_name']     ?? '');
    $contact_person   = trim($_POST['contact_person']   ?? '');
    $email            = trim($_POST['email']            ?? '');
    $phone            = trim($_POST['phone']            ?? '');
    $tax_id_tin       = trim($_POST['tax_id_tin']       ?? '');
    $business_address = trim($_POST['business_address'] ?? '');
    $business_type    = trim($_POST['business_type']    ?? '');

    // Validation
    $errors = [];
    if (empty($company_name))   $errors[] = "Company / Organization name is required.";
    if (empty($contact_person)) $errors[] = "Contact person is required.";
    if (empty($email))          $errors[] = "Email address is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    if (empty($business_type))  $errors[] = "Please select a business type.";

    if (empty($errors)) {
        // Check for existing pending or approved request with same email
        $chk = $conn->prepare("SELECT id, status FROM invitation_requests WHERE email = ? AND status IN ('pending','approved') LIMIT 1");
        $chk->bind_param("s", $email);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            if ($existing['status'] === 'approved') {
                $errors[] = "An invitation has already been sent to this email. Please check your inbox.";
            } else {
                $errors[] = "A registration request from this email is already pending review.";
            }
        } else {
            // Also block if they already have a user account
            $chk2 = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $chk2->bind_param("s", $email);
            $chk2->execute();
            $chk2->store_result();
            if ($chk2->num_rows > 0) {
                $errors[] = "An account with this email already exists. Please log in.";
            }
            $chk2->close();
        }
    }

    if (empty($errors)) {
        $ins = $conn->prepare("
            INSERT INTO invitation_requests
                (company_name, contact_person, email, phone, tax_id_tin, business_address, business_type, requested_role, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'user', 'pending')
        ");
        $ins->bind_param(
            "sssssss",
            $company_name, $contact_person, $email,
            $phone, $tax_id_tin, $business_address, $business_type
        );

        if ($ins->execute()) {
            $_SESSION['reg_success'] = "Your request has been submitted! We'll review it and send you an invitation by email.";
        } else {
            $_SESSION['reg_error'] = "Something went wrong. Please try again.";
        }
        $ins->close();
    } else {
        $_SESSION['reg_error'] = implode(' ', $errors);
        // Preserve values
        $_SESSION['reg_old'] = compact(
            'company_name','contact_person','email',
            'phone','tax_id_tin','business_address','business_type'
        );
    }

    header("Location: register.php");
    exit();
}

$business_types = [
    'Sole Proprietorship',
    'Corporation',
    'Partnership',
    'Cooperative',
    'Supplier / Vendor',
    'Contractor',
    'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Access | YesParency</title>
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
    width: 100%; max-width: 620px;
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
    border-radius: 10px; padding: 12px 16px; font-size: 13px; font-weight: 600;
    margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;
    line-height: 1.6;
}
.alert-success i { font-size: 20px; flex-shrink: 0; margin-top: 1px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 520px) { .form-row { grid-template-columns: 1fr; } }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 700; color: #06251b; margin-bottom: 6px; }
.form-group label .opt { color: #88968d; font-weight: 400; font-size: 11px; margin-left: 3px; }

.input-wrapper { position: relative; display: flex; align-items: center; }
.input-icon-left { position: absolute; left: 13px; color: #88968d; font-size: 14px; pointer-events: none; }
.input-wrapper input,
.input-wrapper select,
.input-wrapper textarea {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid #d4e0d8; border-radius: 10px;
    font-size: 13px; font-family: 'Poppins', sans-serif;
    color: #1a1a1a; outline: none; background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.input-wrapper input:focus,
.input-wrapper select:focus,
.input-wrapper textarea:focus {
    border-color: #1f7a3d; box-shadow: 0 0 0 3px rgba(31,122,61,.1);
}
.input-wrapper.textarea-wrap { align-items: flex-start; }
.input-wrapper.textarea-wrap i { margin-top: 12px; }
.input-wrapper textarea { resize: vertical; min-height: 78px; padding-top: 10px; }
.input-wrapper select { padding-left: 38px; cursor: pointer; appearance: none; }

.info-note {
    background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #ffc107;
    border-radius: 10px; padding: 12px 14px; font-size: 12px; color: #78350f;
    margin-bottom: 22px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.6;
}
.info-note i { font-size: 15px; color: #ffc107; flex-shrink: 0; margin-top: 1px; }

.btn-register {
    width: 100%; background: #06251b; color: #ffc107; border: none;
    padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 800;
    font-family: 'Poppins', sans-serif; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; margin-top: 6px;
}
.btn-register:hover { background: #144937; color: #fff; transform: translateY(-1px); }

.section-divider {
    font-size: 10.5px; font-weight: 800; color: #88968d; text-transform: uppercase;
    letter-spacing: .08em; margin: 6px 0 14px;
    display: flex; align-items: center; gap: 10px;
}
.section-divider::before, .section-divider::after {
    content: ''; flex: 1; height: 1px; background: #e2ece6;
}

.login-footer { text-align: center; margin-top: 20px; font-size: 13px; color: #63736a; }
.login-footer a { color: #1f7a3d; font-weight: 700; text-decoration: none; }
.login-footer a:hover { text-decoration: underline; }

.steps-strip {
    display: flex; align-items: center; gap: 0; margin-bottom: 28px;
    background: #f4f8f5; border-radius: 12px; padding: 14px 16px;
    counter-reset: step;
}
.step-item {
    display: flex; align-items: center; gap: 8px; flex: 1;
    font-size: 11.5px; font-weight: 600; color: #63736a; position: relative;
}
.step-item:not(:last-child)::after {
    content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
    width: 1px; height: 24px; background: #d4e0d8;
}
.step-num {
    width: 22px; height: 22px; border-radius: 50%; background: #06251b; color: #ffc107;
    font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-family: 'Space Grotesk', sans-serif;
}

@media (max-width: 520px) {
    .auth-card { padding: 28px 18px; }
    .steps-strip { flex-direction: column; gap: 8px; }
    .step-item:not(:last-child)::after { display: none; }
}
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

        <h3>Request Portal Access</h3>
        <p>Fill in your organization details to request an account invitation</p>

        <!-- How it works strip -->
        <div class="steps-strip">
            <div class="step-item"><span class="step-num">1</span> Submit Request</div>
            <div class="step-item"><span class="step-num">2</span> Admin Reviews</div>
            <div class="step-item"><span class="step-num">3</span> Get Invite Email</div>
            <div class="step-item"><span class="step-num">4</span> Create Account</div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-success">
                <i class="bi bi-envelope-check-fill"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>

        <div class="info-note">
            <i class="bi bi-info-circle-fill"></i>
            <div>Registration requires admin approval. Once reviewed, you will receive an email invitation with a link to set up your account.</div>
        </div>

        <form action="register.php" method="POST">

            <div class="section-divider">Organization Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label for="company_name">Company / Organization <span style="color:#e53935;">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-building input-icon-left"></i>
                        <input type="text" id="company_name" name="company_name"
                               placeholder="ABC Corporation"
                               value="<?= htmlspecialchars($old['company_name'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="contact_person">Contact Person <span style="color:#e53935;">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-person input-icon-left"></i>
                        <input type="text" id="contact_person" name="contact_person"
                               placeholder="Juan dela Cruz"
                               value="<?= htmlspecialchars($old['contact_person'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address <span style="color:#e53935;">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon-left"></i>
                        <input type="email" id="email" name="email"
                               placeholder="juan@company.com"
                               value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number <span class="opt">(optional)</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-telephone input-icon-left"></i>
                        <input type="text" id="phone" name="phone"
                               placeholder="+63 9XX XXX XXXX"
                               value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="business_type">Business Type <span style="color:#e53935;">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-briefcase input-icon-left"></i>
                        <select id="business_type" name="business_type" required>
                            <option value="" disabled <?= empty($old['business_type']) ? 'selected' : '' ?>>Select type...</option>
                            <?php foreach ($business_types as $bt): ?>
                                <option value="<?= htmlspecialchars($bt) ?>"
                                    <?= ($old['business_type'] ?? '') === $bt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($bt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="tax_id_tin">TIN / Registration No. <span class="opt">(optional)</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-file-earmark-text input-icon-left"></i>
                        <input type="text" id="tax_id_tin" name="tax_id_tin"
                               placeholder="000-000-000-000"
                               value="<?= htmlspecialchars($old['tax_id_tin'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="business_address">Business Address <span class="opt">(optional)</span></label>
                <div class="input-wrapper textarea-wrap">
                    <i class="bi bi-geo-alt input-icon-left"></i>
                    <textarea id="business_address" name="business_address"
                              placeholder="Street, City, Province, ZIP Code"><?= htmlspecialchars($old['business_address'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn-register">
                <i class="bi bi-send-fill"></i> Submit Access Request
            </button>

        </form>

        <?php endif; ?>

        <div class="login-footer">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>

    </div>
</div>

</body>
</html>
