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
    <!-- Custom CSS: base -> shared components -> page-specific -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/pages/register.css">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
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
                    <label for="company_name">Company / Organization <span class="field-required">*</span></label>
                    <div class="input-wrapper">
                        <i class="bi bi-building input-icon-left"></i>
                        <input type="text" id="company_name" name="company_name"
                               placeholder="ABC Corporation"
                               value="<?= htmlspecialchars($old['company_name'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="contact_person">Contact Person <span class="field-required">*</span></label>
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
                    <label for="email">Email Address <span class="field-required">*</span></label>
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
                    <label for="business_type">Business Type <span class="field-required">*</span></label>
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

            <button type="submit" class="btn-auth-submit">
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
