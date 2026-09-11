<?php 
include("utils/protect-page.php");

$userId = (int)$_SESSION["user_id"];

// 1. Fetch Existing Bidder Profile & Documents
$stmt = $conn->prepare("
    SELECT *
    FROM bidder_profiles
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$hasApplication = false;
$status = null;
$profile = null;

if ($result->num_rows > 0) {
    $hasApplication = true;
    $profile = $result->fetch_assoc();
    $status = strtolower($profile['application_status'] ?? 'pending');
}
$stmt->close();

// Fetch existing uploaded documents if application exists
$existing_docs = [];
if ($hasApplication) {
    $doc_stmt = $conn->prepare("SELECT * FROM bidder_documents WHERE user_id = ? ORDER BY upload_date ASC");
    $doc_stmt->bind_param("i", $userId);
    $doc_stmt->execute();
    $docs_res = $doc_stmt->get_result();
    while ($d = $docs_res->fetch_assoc()) {
        $existing_docs[$d['document_type']] = $d;
    }
    $doc_stmt->close();
}

$errors = [];

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_registration'])) {

    // Double check if profile already exists
    $check = $conn->prepare("SELECT profile_id FROM bidder_profiles WHERE user_id = ? LIMIT 1");
    $check->bind_param("i", $userId);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $errors[] = "You already have an existing bidder profile submitted.";
    }
    $check->close();

    $businessName    = trim($_POST['business-name'] ?? '');
    $philgepsNumber  = trim($_POST['philgeps-number'] ?? '');
    $tinNumber       = trim($_POST['tin-number'] ?? '');
    $businessType    = trim($_POST['business-type'] ?? '');
    $yearEstablished = intval($_POST['year'] ?? 0);
    $businessAddress = trim($_POST['business-address'] ?? '');
    $businessEmail   = trim($_POST['business-email'] ?? '');
    $businessPhone   = trim($_POST['business-phone'] ?? '');

    // Validation
    if (empty($businessName))    $errors[] = "Registered Business Name is required.";
    if (empty($philgepsNumber))  $errors[] = "PhilGEPS Registration Number is required.";
    if (empty($tinNumber))       $errors[] = "Tax Identification Number (TIN) is required.";
    if (empty($businessType))    $errors[] = "Business Organization Type is required.";
    if ($yearEstablished < 1900 || $yearEstablished > (int)date('Y')) $errors[] = "Please provide a valid Year Established.";
    if (empty($businessAddress)) $errors[] = "Registered Business Address is required.";
    if (empty($businessEmail) || !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid Business Email Address is required.";
    if (empty($businessPhone))   $errors[] = "Business Contact Phone number is required.";

    // Required Documents definition
    $documents = [
        'dti-sec-cda-certification' => 'dti_sec_cda',
        'mayor-business-permit'     => 'mayor_permit',
        'bir-certificate'           => 'bir_certificate',
        'philgeps-certificate'      => 'philgeps_certificate',
        'goverment-id'              => 'government_id'
    ];

    $docLabels = [
        'dti-sec-cda-certification' => 'DTI / SEC / CDA Registration Certificate',
        'mayor-business-permit'     => "Mayor's / Business Permit",
        'bir-certificate'           => 'BIR Certificate of Registration (Form 2303)',
        'philgeps-certificate'      => 'PhilGEPS Certificate of Registration',
        'goverment-id'              => 'Valid Government-Issued ID'
    ];

    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxFileSize = 20 * 1024 * 1024; // 20 MB

    foreach ($documents as $inputName => $docType) {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload the required document: " . $docLabels[$inputName] . ".";
        } else {
            $ext = strtolower(pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                $errors[] = "Invalid format for " . $docLabels[$inputName] . ". Allowed: PDF, JPG, PNG.";
            }
            if ($_FILES[$inputName]['size'] > $maxFileSize) {
                $errors[] = "File size for " . $docLabels[$inputName] . " exceeds the 20MB limit.";
            }
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        $savedFiles = [];

        try {
            // 1. Insert Bidder Profile
            $ins_stmt = $conn->prepare("
                INSERT INTO bidder_profiles (
                    user_id,
                    business_name,
                    philgeps_number,
                    tin_number,
                    business_type,
                    year_established,
                    business_address,
                    business_email,
                    business_phone,
                    application_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $ins_stmt->bind_param(
                "issssisss",
                $userId,
                $businessName,
                $philgepsNumber,
                $tinNumber,
                $businessType,
                $yearEstablished,
                $businessAddress,
                $businessEmail,
                $businessPhone
            );
            $ins_stmt->execute();
            $ins_stmt->close();

            // 2. Upload and Save Documents
            $uploadDir = "../uploads/bidders/" . $userId . "/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $docStmt = $conn->prepare("
                INSERT INTO bidder_documents (
                    user_id,
                    document_type,
                    file_name,
                    file_path
                ) VALUES (?, ?, ?, ?)
            ");

            foreach ($documents as $inputName => $docType) {
                $origFileName = basename($_FILES[$inputName]['name']);
                $cleanName    = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origFileName);
                $uniqueFile   = uniqid($docType . "_") . "_" . $cleanName;
                $destination  = $uploadDir . $uniqueFile;

                if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $destination)) {
                    $savedFiles[] = $destination;
                    $docStmt->bind_param("isss", $userId, $docType, $origFileName, $destination);
                    $docStmt->execute();
                } else {
                    throw new Exception("Failed to upload " . $docLabels[$inputName] . ".");
                }
            }
            $docStmt->close();

            // 3. Update User Status to Pending
            $user_stmt = $conn->prepare("UPDATE users SET status = 'pending' WHERE user_id = ?");
            $user_stmt->bind_param("i", $userId);
            $user_stmt->execute();
            $user_stmt->close();

            // Commit Transaction
            $conn->commit();

            // Redirect back to show submitted status
            header("Location: bidder-registration.php?registered=1");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($savedFiles as $f) {
                if (file_exists($f)) @unlink($f);
            }
            $errors[] = "Application submission failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bidder Accreditation &amp; Registration | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/user-bidder-registration.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">


    <?php if (!$hasApplication): ?>

        <!-- ── Project Hero Header Banner ── -->
        <div class="page-header">
            <h2>Supplier &amp; Bidder Accreditation Application</h2>
            <p>Register your business enterprise as an accredited government procurement supplier. </p>
        </div>

        <!-- ── Error Banner ── -->
        <?php if (!empty($errors)): ?>
            <div class="sb-error-banner">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <div>
                    <strong class="fs-14">Please correct the following errors before submitting:</strong>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" enctype="multipart/form-data" id="bidderRegForm">

            <div class="vp-grid-layout">

                <!-- ── LEFT COLUMN: Step 1, 2, 3 ── -->
                <div class="vp-left-col">

                    <!-- ============================================ -->
                    <!-- STEP 1: Business Information                -->
                    <!-- ============================================ -->
                    <div class="submit-step-card">
                        <div class="step-card-header">
                            <div class="step-header-left">
                                <div class="step-badge">1</div>
                                <div class="step-title-text">
                                    <h4>Registered Business Information</h4>
                                    <p>Provide legal enterprise details exactly as registered with DTI, SEC, or CDA.</p>
                                </div>
                            </div>
                        </div>

                        <div class="step-card-body">
                            <div class="form-grid-2col">

                                <div class="field-group full-width">
                                    <label class="field-label">Registered Business Name <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="text" name="business-name" id="field_bname" placeholder="e.g. Apex Engineering &amp; General Supplies Corp." value="<?= htmlspecialchars($_POST['business-name'] ?? '') ?>" required>
                                        <i class="bi bi-building input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">PhilGEPS Registration No. <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="text" name="philgeps-number" id="field_philgeps" placeholder="e.g. 2024-12345678" value="<?= htmlspecialchars($_POST['philgeps-number'] ?? '') ?>" required>
                                        <i class="bi bi-hash input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Tax Identification No. (TIN) <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="text" name="tin-number" id="field_tin" placeholder="e.g. 123-456-789-000" value="<?= htmlspecialchars($_POST['tin-number'] ?? '') ?>" required>
                                        <i class="bi bi-file-earmark-text input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Business Organization Type <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <select name="business-type" id="field_btype" required>
                                            <option value="">-- Select Organization Type --</option>
                                            <option value="Sole Proprietorship" <?= (($_POST['business-type'] ?? '') === 'Sole Proprietorship') ? 'selected' : '' ?>>Sole Proprietorship</option>
                                            <option value="Corporation" <?= (($_POST['business-type'] ?? '') === 'Corporation') ? 'selected' : '' ?>>Corporation</option>
                                            <option value="Partnership" <?= (($_POST['business-type'] ?? '') === 'Partnership') ? 'selected' : '' ?>>Partnership</option>
                                            <option value="Cooperative" <?= (($_POST['business-type'] ?? '') === 'Cooperative') ? 'selected' : '' ?>>Cooperative</option>
                                            <option value="Joint Venture" <?= (($_POST['business-type'] ?? '') === 'Joint Venture') ? 'selected' : '' ?>>Joint Venture</option>
                                        </select>
                                        <i class="bi bi-briefcase input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Year Established <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="number" name="year" id="field_year" placeholder="e.g. 2015" min="1900" max="<?= (int)date('Y') ?>" value="<?= htmlspecialchars($_POST['year'] ?? '') ?>" required>
                                        <i class="bi bi-calendar-event input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Official Business Email <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="email" name="business-email" id="field_email" placeholder="bids@company.com" value="<?= htmlspecialchars($_POST['business-email'] ?? '') ?>" required>
                                        <i class="bi bi-envelope input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Official Business Phone <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="tel" name="business-phone" id="field_phone" placeholder="+63 912 345 6789 / (02) 8123-4567" value="<?= htmlspecialchars($_POST['business-phone'] ?? '') ?>" required>
                                        <i class="bi bi-telephone input-icon"></i>
                                    </div>
                                </div>

                                <div class="field-group full-width">
                                    <label class="field-label">Registered Business Address <span class="req">*</span></label>
                                    <div class="input-icon-box">
                                        <input type="text" name="business-address" id="field_addr" placeholder="Unit / Bldg No., Street, Barangay, City / Municipality, Province, ZIP" value="<?= htmlspecialchars($_POST['business-address'] ?? '') ?>" required>
                                        <i class="bi bi-geo-alt input-icon"></i>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- ============================================ -->
                    <!-- STEP 2: Legal & Eligibility Documents       -->
                    <!-- ============================================ -->
                    <div class="submit-step-card">
                        <div class="step-card-header">
                            <div class="step-header-left">
                                <div class="step-badge">2</div>
                                <div class="step-title-text">
                                    <h4>Mandatory Eligibility Documents</h4>
                                    <p>Upload clear scanned copies in PDF, JPG, or PNG format. Maximum 20MB per document.</p>
                                </div>
                            </div>
                        </div>

                        <div class="step-card-body">

                            <!-- Doc 1: DTI / SEC / CDA -->
                            <div class="doc-upload-card" id="card_dti">
                                <div class="doc-card-head">
                                    <div class="doc-card-info">
                                        <div class="doc-icon-wrap"><i class="bi bi-file-earmark-text"></i></div>
                                        <div class="doc-text-wrap">
                                            <div class="doc-title">DTI / SEC / CDA Registration Certificate <span class="req">*</span></div>
                                            <div class="doc-desc">Certificate of business name or incorporation registration</div>
                                        </div>
                                    </div>
                                    <span class="doc-status-badge" id="badge_dti"><i class="bi bi-clock"></i> Pending Upload</span>
                                </div>
                                <label class="doc-drop-trigger">
                                    <span class="doc-trigger-text"><i class="bi bi-cloud-arrow-up"></i> Click or drag file here to upload (PDF, JPG, PNG)</span>
                                    <span class="doc-browse-btn"><i class="bi bi-upload"></i> Browse File</span>
                                    <input type="file" name="dti-sec-cda-certification" accept=".pdf,.jpg,.jpeg,.png" required onchange="handleDocChange(this, 'card_dti', 'badge_dti', 'prev_dti', 'name_dti', 'size_dti')">
                                </label>
                                <div class="file-preview-pill" id="prev_dti">
                                    <div class="file-pill-left">
                                        <i class="bi bi-file-earmark-check-fill"></i>
                                        <span class="file-pill-name" id="name_dti">filename.pdf</span>
                                        <span class="file-pill-size" id="size_dti">0 KB</span>
                                    </div>
                                    <button type="button" class="file-remove-btn" onclick="clearUploadedDoc('dti-sec-cda-certification', 'card_dti', 'badge_dti', 'prev_dti')" title="Remove file">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Doc 2: Mayor's Permit -->
                            <div class="doc-upload-card" id="card_mayor">
                                <div class="doc-card-head">
                                    <div class="doc-card-info">
                                        <div class="doc-icon-wrap"><i class="bi bi-building"></i></div>
                                        <div class="doc-text-wrap">
                                            <div class="doc-title">Mayor's / Business Permit (Current Year) <span class="req">*</span></div>
                                            <div class="doc-desc">Valid local government business permit or official receipt for renewal</div>
                                        </div>
                                    </div>
                                    <span class="doc-status-badge" id="badge_mayor"><i class="bi bi-clock"></i> Pending Upload</span>
                                </div>
                                <label class="doc-drop-trigger">
                                    <span class="doc-trigger-text"><i class="bi bi-cloud-arrow-up"></i> Click or drag file here to upload (PDF, JPG, PNG)</span>
                                    <span class="doc-browse-btn"><i class="bi bi-upload"></i> Browse File</span>
                                    <input type="file" name="mayor-business-permit" accept=".pdf,.jpg,.jpeg,.png" required onchange="handleDocChange(this, 'card_mayor', 'badge_mayor', 'prev_mayor', 'name_mayor', 'size_mayor')">
                                </label>
                                <div class="file-preview-pill" id="prev_mayor">
                                    <div class="file-pill-left">
                                        <i class="bi bi-file-earmark-check-fill"></i>
                                        <span class="file-pill-name" id="name_mayor">filename.pdf</span>
                                        <span class="file-pill-size" id="size_mayor">0 KB</span>
                                    </div>
                                    <button type="button" class="file-remove-btn" onclick="clearUploadedDoc('mayor-business-permit', 'card_mayor', 'badge_mayor', 'prev_mayor')" title="Remove file">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Doc 3: BIR Certificate -->
                            <div class="doc-upload-card" id="card_bir">
                                <div class="doc-card-head">
                                    <div class="doc-card-info">
                                        <div class="doc-icon-wrap"><i class="bi bi-receipt"></i></div>
                                        <div class="doc-text-wrap">
                                            <div class="doc-title">BIR Certificate of Registration (Form 2303) <span class="req">*</span></div>
                                            <div class="doc-desc">Bureau of Internal Revenue taxpayer identification and registration form</div>
                                        </div>
                                    </div>
                                    <span class="doc-status-badge" id="badge_bir"><i class="bi bi-clock"></i> Pending Upload</span>
                                </div>
                                <label class="doc-drop-trigger">
                                    <span class="doc-trigger-text"><i class="bi bi-cloud-arrow-up"></i> Click or drag file here to upload (PDF, JPG, PNG)</span>
                                    <span class="doc-browse-btn"><i class="bi bi-upload"></i> Browse File</span>
                                    <input type="file" name="bir-certificate" accept=".pdf,.jpg,.jpeg,.png" required onchange="handleDocChange(this, 'card_bir', 'badge_bir', 'prev_bir', 'name_bir', 'size_bir')">
                                </label>
                                <div class="file-preview-pill" id="prev_bir">
                                    <div class="file-pill-left">
                                        <i class="bi bi-file-earmark-check-fill"></i>
                                        <span class="file-pill-name" id="name_bir">filename.pdf</span>
                                        <span class="file-pill-size" id="size_bir">0 KB</span>
                                    </div>
                                    <button type="button" class="file-remove-btn" onclick="clearUploadedDoc('bir-certificate', 'card_bir', 'badge_bir', 'prev_bir')" title="Remove file">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Doc 4: PhilGEPS Certificate -->
                            <div class="doc-upload-card" id="card_philgeps">
                                <div class="doc-card-head">
                                    <div class="doc-card-info">
                                        <div class="doc-icon-wrap"><i class="bi bi-patch-check"></i></div>
                                        <div class="doc-text-wrap">
                                            <div class="doc-title">PhilGEPS Certificate of Registration <span class="req">*</span></div>
                                            <div class="doc-desc">PhilGEPS Red or Platinum Certificate of Registration and Membership</div>
                                        </div>
                                    </div>
                                    <span class="doc-status-badge" id="badge_philgeps"><i class="bi bi-clock"></i> Pending Upload</span>
                                </div>
                                <label class="doc-drop-trigger">
                                    <span class="doc-trigger-text"><i class="bi bi-cloud-arrow-up"></i> Click or drag file here to upload (PDF, JPG, PNG)</span>
                                    <span class="doc-browse-btn"><i class="bi bi-upload"></i> Browse File</span>
                                    <input type="file" name="philgeps-certificate" accept=".pdf,.jpg,.jpeg,.png" required onchange="handleDocChange(this, 'card_philgeps', 'badge_philgeps', 'prev_philgeps', 'name_philgeps', 'size_philgeps')">
                                </label>
                                <div class="file-preview-pill" id="prev_philgeps">
                                    <div class="file-pill-left">
                                        <i class="bi bi-file-earmark-check-fill"></i>
                                        <span class="file-pill-name" id="name_philgeps">filename.pdf</span>
                                        <span class="file-pill-size" id="size_philgeps">0 KB</span>
                                    </div>
                                    <button type="button" class="file-remove-btn" onclick="clearUploadedDoc('philgeps-certificate', 'card_philgeps', 'badge_philgeps', 'prev_philgeps')" title="Remove file">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Doc 5: Gov ID -->
                            <div class="doc-upload-card" id="card_govid">
                                <div class="doc-card-head">
                                    <div class="doc-card-info">
                                        <div class="doc-icon-wrap"><i class="bi bi-person-badge"></i></div>
                                        <div class="doc-text-wrap">
                                            <div class="doc-title">Valid Government-Issued ID <span class="req">*</span></div>
                                            <div class="doc-desc">Current government photo ID of the authorized managing officer / signatory</div>
                                        </div>
                                    </div>
                                    <span class="doc-status-badge" id="badge_govid"><i class="bi bi-clock"></i> Pending Upload</span>
                                </div>
                                <label class="doc-drop-trigger">
                                    <span class="doc-trigger-text"><i class="bi bi-cloud-arrow-up"></i> Click or drag file here to upload (PDF, JPG, PNG)</span>
                                    <span class="doc-browse-btn"><i class="bi bi-upload"></i> Browse File</span>
                                    <input type="file" name="goverment-id" accept=".pdf,.jpg,.jpeg,.png" required onchange="handleDocChange(this, 'card_govid', 'badge_govid', 'prev_govid', 'name_govid', 'size_govid')">
                                </label>
                                <div class="file-preview-pill" id="prev_govid">
                                    <div class="file-pill-left">
                                        <i class="bi bi-file-earmark-check-fill"></i>
                                        <span class="file-pill-name" id="name_govid">filename.pdf</span>
                                        <span class="file-pill-size" id="size_govid">0 KB</span>
                                    </div>
                                    <button type="button" class="file-remove-btn" onclick="clearUploadedDoc('goverment-id', 'card_govid', 'badge_govid', 'prev_govid')" title="Remove file">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ============================================ -->
                    <!-- STEP 3: Sworn Declarations & Review          -->
                    <!-- ============================================ -->
                    <div class="submit-step-card">
                        <div class="step-card-header">
                            <div class="step-header-left">
                                <div class="step-badge">3</div>
                                <div class="step-title-text">
                                    <h4>Sworn Declaration &amp; Compliance Warranty</h4>
                                    <p>Review and confirm all statutory declarations before submitting your application.</p>
                                </div>
                            </div>
                        </div>

                        <div class="step-card-body">

                            <div class="security-notice-card">
                                <i class="bi bi-shield-check"></i>
                                <p>
                                    <strong>Statutory Transparency Notice:</strong> Under R.A. 9184 (Government Procurement Reform Act) and R.A. 10173 (Data Privacy Act of 2012), all submitted documents are treated with utmost confidentiality and evaluated solely for bidder accreditation purposes by the SLSU Bids and Awards Committee.
                                </p>
                            </div>

                            <label class="declaration-item-card">
                                <input type="checkbox" name="dec_accuracy" class="dec-check" required onchange="updateProgress()">
                                <div class="declaration-content">
                                    <strong>Authenticity &amp; Accuracy Warranty</strong>
                                    I certify under oath that all information provided and all attached documents are true, correct, authentic, and complete.
                                </div>
                            </label>

                            <label class="declaration-item-card">
                                <input type="checkbox" name="dec_philgeps" class="dec-check" required onchange="updateProgress()">
                                <div class="declaration-content">
                                    <strong>PhilGEPS Standing Compliance</strong>
                                    I confirm that our business is in good standing with PhilGEPS and that our tax clearances and business permits are up to date.
                                </div>
                            </label>

                            <label class="declaration-item-card">
                                <input type="checkbox" name="dec_conflict" class="dec-check" required onchange="updateProgress()">
                                <div class="declaration-content">
                                    <strong>No Blacklisting &amp; Conflict of Interest</strong>
                                    I declare that our enterprise is not blacklisted by any government instrumentality and has no conflict of interest with SLSU-BAC officials.
                                </div>
                            </label>

                            <label class="declaration-item-card">
                                <input type="checkbox" name="dec_terms" class="dec-check" required onchange="updateProgress()">
                                <div class="declaration-content">
                                    <strong>Terms of Use &amp; E-Bidding Guidelines</strong>
                                    I have read and agree to YesParency's terms of service, electronic bidding rules, and procurement guidelines.
                                </div>
                            </label>

                            <label class="declaration-item-card">
                                <input type="checkbox" name="dec_audit" class="dec-check" required onchange="updateProgress()">
                                <div class="declaration-content">
                                    <strong>Audit Trail &amp; Verification Consent</strong>
                                    I consent to the recording of digital audit logs and authorize the BAC Secretariat to verify the validity of our submissions with issuing agencies.
                                </div>
                            </label>

                            <!-- Actions -->
                            <div class="form-actions-bar">
                                <button type="submit" name="submit_registration" class="btn-submit-proposal" id="btnSubmit">
                                    <i class="bi bi-send-check-fill"></i> Submit Accreditation Application
                                </button>
                                <a href="dashboard.php" class="btn-cancel-proposal">
                                    <i class="bi bi-x-lg"></i> Cancel
                                </a>
                            </div>

                        </div>
                    </div>

                </div>

                <!-- ── RIGHT COLUMN: Live Checklist & Information Guide ── -->
                <div class="vp-right-col">

                    <!-- Card 1: Live Progress & Checklist -->
                    <div class="sidebar-summary-card">
                        <div class="summary-card-head">
                            <h4><i class="bi bi-list-check"></i> Application Checklist</h4>
                            <span class="progress-pct-pill" id="progress_pct">0%</span>
                        </div>
                        <div class="summary-card-body">
                            
                            <div class="progress-block">
                                <div class="progress-lbl-row">
                                    <span>Completion Status</span>
                                    <span id="progress_text" class="progress-status-text">Incomplete</span>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill" id="progress_bar"></div>
                                </div>
                            </div>

                            <div class="checklist-group">
                                <div class="check-item" id="chk_binfo">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 1: Business Details</span>
                                </div>
                                <div class="check-item" id="chk_dti">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 2: DTI / SEC / CDA Cert</span>
                                </div>
                                <div class="check-item" id="chk_mayor">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 2: Mayor's / Business Permit</span>
                                </div>
                                <div class="check-item" id="chk_bir">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 2: BIR Certificate (2303)</span>
                                </div>
                                <div class="check-item" id="chk_philgeps">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 2: PhilGEPS Certificate</span>
                                </div>
                                <div class="check-item" id="chk_govid">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 2: Valid Government ID</span>
                                </div>
                                <div class="check-item" id="chk_dec">
                                    <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                    <span>Step 3: 5 Sworn Declarations</span>
                                </div>
                            </div>

                            <div class="checklist-footnote">
                                <i class="bi bi-info-circle clr-green"></i>
                                All sections marked with <span class="req">*</span> must be satisfied prior to submission.
                            </div>

                        </div>
                    </div>

                    <!-- Card 2: 4-Step Accreditation Guide -->
                    <div class="sidebar-summary-card">
                        <div class="summary-card-head">
                            <h4><i class="bi bi-diagram-3"></i> Accreditation Workflow</h4>
                        </div>
                        <div class="summary-card-body">
                            <div class="timeline-guide">
                                
                                <div class="timeline-guide-step">
                                    <div class="timeline-guide-dot"></div>
                                    <div class="timeline-guide-title">1. Online Application</div>
                                    <div class="timeline-guide-sub">Submit your business profile and 5 mandatory eligibility documents.</div>
                                </div>

                                <div class="timeline-guide-step">
                                    <div class="timeline-guide-dot"></div>
                                    <div class="timeline-guide-title">2. BAC Evaluation</div>
                                    <div class="timeline-guide-sub">The Secretariat reviews document authenticity and PhilGEPS registration standing.</div>
                                </div>

                                <div class="timeline-guide-step">
                                    <div class="timeline-guide-dot"></div>
                                    <div class="timeline-guide-title">3. Official Accreditation</div>
                                    <div class="timeline-guide-sub">Upon approval, your account role is upgraded to Verified Bidder.</div>
                                </div>

                                <div class="timeline-guide-step">
                                    <div class="timeline-guide-dot"></div>
                                    <div class="timeline-guide-title">4. Proposal Submission</div>
                                    <div class="timeline-guide-sub">Access encrypted bid envelopes and participate in university tenders.</div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </form>

    <?php else: ?>

        <!-- ============================================ -->
        <!-- APPLICATION STATUS VIEW (Already Submitted)  -->
        <!-- ============================================ -->

        <?php if ($status === 'pending'): ?>

            <!-- PENDING STATUS HERO -->
            <div class="status-hero-card">
                <h1 class="vp-hero-title">Your Bidder Accreditation is Under Review</h1>
                <p class="vp-hero-subtitle">
                    Thank you for registering. Your supplier application and submitted legal eligibility documents are currently undergoing formal verification by the SLSU Bids and Awards Committee Secretariat.
                </p>
            </div>

            <!-- STATUS TRACKER -->
            <div class="status-tracker-card">
                <h4 class="status-tracker-title">
                    <i class="bi bi-diagram-3-fill clr-green"></i> Accreditation Evaluation Progress
                </h4>
                <p class="status-tracker-sub">Live tracking of your accreditation review workflow.</p>

                <div class="tracker-steps-row">
                    
                    <div class="t-step completed">
                        <div class="t-step-circle"><i class="bi bi-check-lg"></i></div>
                        <div class="t-step-title">1. Submitted</div>
                        <div class="t-step-desc"><?= date('M j, Y', strtotime($profile['created_at'])) ?></div>
                    </div>

                    <div class="t-step active">
                        <div class="t-step-circle"><i class="bi bi-hourglass-split"></i></div>
                        <div class="t-step-title">2. Document Review</div>
                        <div class="t-step-desc">In Progress</div>
                    </div>

                    <div class="t-step">
                        <div class="t-step-circle">3</div>
                        <div class="t-step-title">3. BAC Verification</div>
                        <div class="t-step-desc">Pending</div>
                    </div>

                    <div class="t-step">
                        <div class="t-step-circle">4</div>
                        <div class="t-step-title">4. Approved Bidder</div>
                        <div class="t-step-desc">Portal Access</div>
                    </div>

                </div>
            </div>

        <?php elseif ($status === 'approved'): ?>

            <!-- APPROVED STATUS HERO -->
            <div class="status-hero-card approved">
                <h1 class="vp-hero-title">Congratulations! You Are an Accredited Bidder</h1>
                <p class="vp-hero-subtitle">
                    Your business enterprise has been officially verified by the SLSU Bids and Awards Committee. You may now access all competitive bidding opportunities and submit electronic bid proposals directly through the portal.
                </p>

                <div class="vp-hero-actions">
                    <a href="procurement.php" class="btn-submit-proposal btn-submit-proposal--light">
                        <i class="bi bi-folder2-open"></i> Browse Active Procurements
                    </a>
                    <a href="../bidder/dashboard.php" class="btn-submit-proposal btn-submit-proposal--gold">
                        <i class="bi bi-speedometer2"></i> Go to Bidder Dashboard
                    </a>
                </div>
            </div>

        <?php elseif ($status === 'rejected'): ?>

            <!-- REJECTED STATUS HERO -->
            <div class="status-hero-card rejected">
                <h1 class="vp-hero-title">Application Not Approved</h1>
                <p class="vp-hero-subtitle">
                    Following evaluation by the BAC Secretariat, your bidder accreditation could not be approved at this time due to incomplete or non-compliant eligibility documents. Please contact the BAC Secretariat for specific guidance or clarification.
                </p>

                <div class="vp-hero-actions">
                    <a href="dashboard.php" class="btn-submit-proposal btn-submit-proposal--light">
                        <i class="bi bi-arrow-left"></i> Return to Dashboard
                    </a>
                </div>
            </div>

        <?php endif; ?>

        <!-- ── Details Breakdown (Submitted Profile & Documents) ── -->
        <div class="details-breakdown-wrap">

            <div class="submit-step-card">
                <div class="step-card-header">
                    <div class="step-header-left">
                        <div class="step-badge"><i class="bi bi-building"></i></div>
                        <div class="step-title-text">
                            <h4>Registered Business Profile</h4>
                            <p>Information submitted for university procurement accreditation.</p>
                        </div>
                    </div>
                </div>
                <div class="step-card-body step-card-body--flush">
                    <table class="info-summary-table">
                        <tr>
                            <td>Business Name</td>
                            <td><?= htmlspecialchars($profile['business_name']) ?></td>
                        </tr>
                        <tr>
                            <td>PhilGEPS Number</td>
                            <td><?= htmlspecialchars($profile['philgeps_number']) ?></td>
                        </tr>
                        <tr>
                            <td>TIN</td>
                            <td><?= htmlspecialchars($profile['tin_number']) ?></td>
                        </tr>
                        <tr>
                            <td>Business Type</td>
                            <td><?= htmlspecialchars($profile['business_type']) ?></td>
                        </tr>
                        <tr>
                            <td>Year Established</td>
                            <td><?= htmlspecialchars($profile['year_established']) ?></td>
                        </tr>
                        <tr>
                            <td>Official Email</td>
                            <td><?= htmlspecialchars($profile['business_email']) ?></td>
                        </tr>
                        <tr>
                            <td>Phone Number</td>
                            <td><?= htmlspecialchars($profile['business_phone']) ?></td>
                        </tr>
                        <tr>
                            <td>Business Address</td>
                            <td><?= htmlspecialchars($profile['business_address']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Submitted Documents -->
            <div class="submit-step-card">
                <div class="step-card-header">
                    <div class="step-header-left">
                        <div class="step-badge"><i class="bi bi-file-earmark-check"></i></div>
                        <div class="step-title-text">
                            <h4>Submitted Eligibility Documents (<?= count($existing_docs) ?>)</h4>
                            <p>Uploaded certificates and proof of registration on file.</p>
                        </div>
                    </div>
                </div>
                <div class="step-card-body">
                    <div class="docs-review-grid">
                        <?php 
                        $docDisplayNames = [
                            'dti_sec_cda'          => 'DTI / SEC / CDA Certificate',
                            'mayor_permit'         => "Mayor's / Business Permit",
                            'bir_certificate'      => 'BIR Certificate (Form 2303)',
                            'philgeps_certificate' => 'PhilGEPS Certificate',
                            'government_id'        => 'Valid Government ID'
                        ];
                        foreach ($docDisplayNames as $key => $title): 
                            $hasDoc = isset($existing_docs[$key]);
                        ?>
                            <div class="doc-review-pill">
                                <i class="bi bi-file-earmark-check-fill"></i>
                                <div class="doc-review-text">
                                    <div class="doc-review-title"><?= $title ?></div>
                                    <div class="doc-review-meta">
                                        <?= $hasDoc ? htmlspecialchars($existing_docs[$key]['file_name']) : 'Not uploaded' ?>
                                    </div>
                                </div>
                                <span class="doc-review-status">
                                    Uploaded
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>
</main>

<script>
    // Sidebar responsive toggle
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');
    const body    = document.body;

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
    }

    // Interactive Drag-and-Drop / Browse Handler
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function handleDocChange(input, cardId, badgeId, prevId, nameId, sizeId) {
        const card  = document.getElementById(cardId);
        const badge = document.getElementById(badgeId);
        const name  = document.getElementById(nameId);
        const size  = document.getElementById(sizeId);

        if (input.files && input.files[0]) {
            const file = input.files[0];
            card.classList.add('has-file');
            badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Uploaded';
            name.textContent = file.name;
            size.textContent = formatBytes(file.size);
        } else {
            card.classList.remove('has-file');
            badge.innerHTML = '<i class="bi bi-clock"></i> Pending Upload';
        }
        updateProgress();
    }

    function clearUploadedDoc(inputName, cardId, badgeId, prevId) {
        const input = document.querySelector('input[name="' + inputName + '"]');
        if (input) {
            input.value = '';
        }
        const card  = document.getElementById(cardId);
        const badge = document.getElementById(badgeId);
        if (card) card.classList.remove('has-file');
        if (badge) badge.innerHTML = '<i class="bi bi-clock"></i> Pending Upload';
        updateProgress();
    }

    // Drag-over styling
    document.querySelectorAll('.doc-drop-trigger').forEach(dropzone => {
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            const input = dropzone.querySelector('input[type="file"]');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });

    // Real-time Checklist & Progress Calculation
    function updateProgress() {
        const bname = document.getElementById('field_bname');
        const phil  = document.getElementById('field_philgeps');
        const tin   = document.getElementById('field_tin');
        const btype = document.getElementById('field_btype');
        const year  = document.getElementById('field_year');
        const email = document.getElementById('field_email');
        const phone = document.getElementById('field_phone');
        const addr  = document.getElementById('field_addr');

        if (!bname) return; // Exit if in status view

        // 1. Business Info Check
        const hasBInfo = bname.value.trim() !== '' &&
                         phil.value.trim() !== '' &&
                         tin.value.trim() !== '' &&
                         btype.value !== '' &&
                         year.value.trim() !== '' &&
                         email.value.trim() !== '' &&
                         phone.value.trim() !== '' &&
                         addr.value.trim() !== '';

        setCheckStatus('chk_binfo', hasBInfo);

        // 2. Documents Check
        const dtiHasFile      = document.getElementById('card_dti').classList.contains('has-file');
        const mayorHasFile    = document.getElementById('card_mayor').classList.contains('has-file');
        const birHasFile      = document.getElementById('card_bir').classList.contains('has-file');
        const philgepsHasFile = document.getElementById('card_philgeps').classList.contains('has-file');
        const govidHasFile    = document.getElementById('card_govid').classList.contains('has-file');

        setCheckStatus('chk_dti', dtiHasFile);
        setCheckStatus('chk_mayor', mayorHasFile);
        setCheckStatus('chk_bir', birHasFile);
        setCheckStatus('chk_philgeps', philgepsHasFile);
        setCheckStatus('chk_govid', govidHasFile);

        // 3. Declarations Check
        const decChecks = document.querySelectorAll('.dec-check');
        let allDecs = true;
        decChecks.forEach(ch => {
            if (!ch.checked) allDecs = false;
        });
        setCheckStatus('chk_dec', allDecs);

        // Calculate Percentage (7 items total)
        const items = [hasBInfo, dtiHasFile, mayorHasFile, birHasFile, philgepsHasFile, govidHasFile, allDecs];
        const completed = items.filter(Boolean).length;
        const pct = Math.round((completed / items.length) * 100);

        const fillEl = document.getElementById('progress_fill');
        const pctEl  = document.getElementById('progress_pct');
        const barEl  = document.getElementById('progress_bar');
        const txtEl  = document.getElementById('progress_text');

        if (barEl) barEl.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        if (txtEl) {
            if (pct === 100) {
                txtEl.textContent = 'Ready to Submit';
                txtEl.style.color = '#1f7a3d';
            } else {
                txtEl.textContent = completed + ' of 7 items completed';
                txtEl.style.color = '#55665a';
            }
        }
    }

    function setCheckStatus(id, isDone) {
        const el = document.getElementById(id);
        if (!el) return;
        if (isDone) {
            el.classList.add('done');
        } else {
            el.classList.remove('done');
        }
    }

    // Attach listeners on inputs
    document.querySelectorAll('#bidderRegForm input, #bidderRegForm select').forEach(input => {
        input.addEventListener('input', updateProgress);
        input.addEventListener('change', updateProgress);
    });

    // Run on initial load
    document.addEventListener('DOMContentLoaded', updateProgress);
</script>

</body>
</html>
