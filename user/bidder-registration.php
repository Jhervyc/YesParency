<?php 
    include ("utils/protect-page.php");
    $userId = $_SESSION["user_id"];

    $stmt = $conn->prepare("
        SELECT application_status
        FROM bidder_profiles
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    $hasApplication = false;
    $status = null;

    if($result->num_rows > 0){
        $hasApplication = true;

        $row = $result->fetch_assoc();
        $status = $row['application_status'];
    }
    
    foreach ($_POST as $key => $value) {
        // Sanitize the value to prevent XSS attacks
        $clean_value = htmlspecialchars($value);
        
        echo "$key : $clean_value <br>";
    }  

    if($_SERVER["REQUEST_METHOD"] == "POST"){

        // checks if may bidders profile na, iwas bug 
        $check = $conn->prepare("
            SELECT profile_id
            FROM bidder_profiles
            WHERE user_id = ?
        ");

        $check->bind_param("i", $userId);
        $check->execute();
        
        if($check->get_result()->num_rows > 0){
            die("You already have a bidder profile.");
        }

        //creating bidders profile
        $businessName = $_POST['business-name'];
        $philgepsNumber = $_POST['philgeps-number'];
        $tinNumber = $_POST['tin-number'];
        $businessType = $_POST['business-type'];
        $year = $_POST['year'];
        $businessAddress = $_POST['business-address'];
        $businessEmail = $_POST['business-email'];
        $businessPhone = $_POST['business-phone'];

        $stmt = $conn->prepare("
            INSERT INTO bidder_profiles
            (
                user_id,
                business_name,
                philgeps_number,
                tin_number,
                business_type,
                year_established,
                business_address,
                business_email,
                business_phone
            )
            VALUES (?,?,?,?,?,?,?,?,?)
        ");

        $stmt->bind_param(
            "issssisss",
            $userId,
            $businessName,
            $philgepsNumber,
            $tinNumber,
            $businessType,
            $year,
            $businessAddress,
            $businessEmail,
            $businessPhone
        );

        $stmt->execute();

        //creating file path na paguuploadan
        $uploadDir = "../uploads/bidders/" . $userId . "/";

        if(!file_exists($uploadDir)){
            mkdir($uploadDir, 0777, true);
        }

        $documents = [
            'dti-sec-cda-certification' => 'dti_sec_cda',
            'mayor-business-permit' => 'mayor_permit',
            'bir-certificate' => 'bir_certificate',
            'philgeps-certificate' => 'philgeps_certificate',
            'goverment-id' => 'government_id'
        ];

        foreach($documents as $inputName => $documentType){

            if(isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] == 0){
                $fileName = uniqid() . "_" . basename($_FILES[$inputName]['name']);

                $destination = $uploadDir . $fileName;

                if(move_uploaded_file( $_FILES[$inputName]['tmp_name'],  $destination )){
                    $stmt = $conn->prepare("
                        INSERT INTO bidder_documents
                        (
                            user_id,
                            document_type,
                            file_name,
                            file_path
                        )
                        VALUES (?,?,?,?)
                    ");

                    $stmt->bind_param(
                        "isss",
                        $userId,
                        $documentType,
                        $fileName,
                        $destination
                    );

                    $stmt->execute();
                }else{
                    die("something went wrong");
                }
            }else{
                die("All required documents must be uploaded.");
            }
        }
    //update users roie
    $stmt = $conn->prepare("
        UPDATE users
        SET status = 'pending'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    header("Location: dashboard.php?application=submitted");
    exit();

    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bidder Registration | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<!-- Mobile overlay -->
<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- ========================= -->
<!-- SIDEBAR                   -->
<!-- ========================= -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Procurement System</div>
        </div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-broadcast"></i><span>Bid Opening Live</span>
        </a>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-calendar-event"></i><span>Bid Schedule</span>
        </a>
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-megaphone"></i><span>Announcements</span>
        </a>
        <div class="nav-section-label">Account</div>
        <a href="bidder-registration.php" class="nav-item active">
            <i class="bi bi-person-plus"></i><span>Register as Bidder</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole"><?= htmlspecialchars($_SESSION['role']) ?></div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- ========================= -->
<!-- TOPBAR                    -->
<!-- ========================= -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="topbar-title">Bidder Registration</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge">
            <i class="bi bi-bell"></i>
        </div>
        <div class="topbar-avatar">
            <i class="bi bi-person"></i>
        </div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <div class="page-header">
            <h2>Bidder Registration</h2>
            <p>Complete the form below to register as an accredited supplier for SLSU procurement.</p>
        </div>

        <?php if(!$hasApplication): ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" enctype="multipart/form-data">

            <!-- ========================= -->
            <!-- STEP 1: Business Nature   -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">1</div>
                    <div>
                        <h4>Business Information</h4>
                        <p>Provide your registered business details.</p>
                    </div>
                </div>

                <div class="reg-grid">
                    <div class="form-group">
                        <label>Registered Business Name</label>
                        <div class="input-wrapper">
                            <i class="bi bi-building input-icon-left"></i>
                            <input type="text" name="business-name" placeholder="e.g. ABC Supplies Co." required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>PhilGEPS Registration No.</label>
                        <div class="input-wrapper">
                            <i class="bi bi-hash input-icon-left"></i>
                            <input type="text" name="philgeps-number" placeholder="e.g. 1234567" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>TIN (Tax Identification No.)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-file-earmark-text input-icon-left"></i>
                            <input type="text" name="tin-number" placeholder="e.g. 000-000-000-000" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Business Type</label>
                        <div class="input-wrapper">
                            <i class="bi bi-briefcase input-icon-left"></i>
                            <input type="text" name="business-type" placeholder="e.g. Sole Proprietorship, Corporation" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Year Established</label>
                        <div class="input-wrapper">
                            <i class="bi bi-calendar input-icon-left"></i>
                            <input type="number" name="year" placeholder="e.g. 2010" min="1900" max="2026" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Business Email</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope input-icon-left"></i>
                            <input type="email" name="business-email" placeholder="info@company.com" required>
                        </div>
                    </div>

                    <div class="form-group reg-full">
                        <label>Registered Business Address</label>
                        <div class="input-wrapper">
                            <i class="bi bi-geo-alt input-icon-left"></i>
                            <input type="text" name="business-address" placeholder="Complete business address" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Business Phone</label>
                        <div class="input-wrapper">
                            <i class="bi bi-telephone input-icon-left"></i>
                            <input type="tel" name="business-phone" placeholder="+63 2 XXXX XXXX" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================= -->
            <!-- STEP 2: Documents         -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">2</div>
                    <div>
                        <h4>Eligibility Documents</h4>
                        <p>Upload all required documents. Accepted formats: PDF, JPG, PNG.</p>
                    </div>
                </div>

                <div class="reg-grid">

                    <div class="form-group reg-full">
                        <div class="file-upload-card">
                            <div class="file-upload-info">
                                <i class="bi bi-file-earmark-text"></i>
                                <div>
                                    <strong>DTI / SEC / CDA Registration Certificate</strong>
                                    <span>Proof of business registration</span>
                                </div>
                            </div>
                            <label class="file-upload-label">
                                <i class="bi bi-upload"></i> Choose File
                                <input type="file" name="dti-sec-cda-certification" accept=".pdf,.jpg,.png" required onchange="showFileName(this, 'fn-dti')">
                            </label>
                        </div>
                        <span class="file-name-display" id="fn-dti"></span>
                    </div>

                    <div class="form-group reg-full">
                        <div class="file-upload-card">
                            <div class="file-upload-info">
                                <i class="bi bi-building"></i>
                                <div>
                                    <strong>Mayor's / Business Permit (current year)</strong>
                                    <span>Valid local government permit</span>
                                </div>
                            </div>
                            <label class="file-upload-label">
                                <i class="bi bi-upload"></i> Choose File
                                <input type="file" name="mayor-business-permit" accept=".pdf,.jpg,.png" required onchange="showFileName(this, 'fn-mayor')">
                            </label>
                        </div>
                        <span class="file-name-display" id="fn-mayor"></span>
                    </div>

                    <div class="form-group reg-full">
                        <div class="file-upload-card">
                            <div class="file-upload-info">
                                <i class="bi bi-receipt"></i>
                                <div>
                                    <strong>BIR Certificate of Registration (Form 2303)</strong>
                                    <span>Bureau of Internal Revenue certificate</span>
                                </div>
                            </div>
                            <label class="file-upload-label">
                                <i class="bi bi-upload"></i> Choose File
                                <input type="file" name="bir-certificate" accept=".pdf,.jpg,.png" required onchange="showFileName(this, 'fn-bir')">
                            </label>
                        </div>
                        <span class="file-name-display" id="fn-bir"></span>
                    </div>

                    <div class="form-group reg-full">
                        <div class="file-upload-card">
                            <div class="file-upload-info">
                                <i class="bi bi-patch-check"></i>
                                <div>
                                    <strong>PhilGEPS Certificate of Registration</strong>
                                    <span>Philippine Government Electronic Procurement certificate</span>
                                </div>
                            </div>
                            <label class="file-upload-label">
                                <i class="bi bi-upload"></i> Choose File
                                <input type="file" name="philgeps-certificate" accept=".pdf,.jpg,.png" required onchange="showFileName(this, 'fn-philgeps')">
                            </label>
                        </div>
                        <span class="file-name-display" id="fn-philgeps"></span>
                    </div>

                    <div class="form-group reg-full">
                        <div class="file-upload-card">
                            <div class="file-upload-info">
                                <i class="bi bi-person-badge"></i>
                                <div>
                                    <strong>Valid Government-Issued ID of Authorized Representative</strong>
                                    <span>Must be current and clearly readable</span>
                                </div>
                            </div>
                            <label class="file-upload-label">
                                <i class="bi bi-upload"></i> Choose File
                                <input type="file" name="goverment-id" accept=".pdf,.jpg,.png" required onchange="showFileName(this, 'fn-govid')">
                            </label>
                        </div>
                        <span class="file-name-display" id="fn-govid"></span>
                    </div>

                </div>
            </div>

            <!-- ========================= -->
            <!-- STEP 3: Declaration       -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">3</div>
                    <div>
                        <h4>Review & Declaration</h4>
                        <p>Please read and confirm each declaration before submitting.</p>
                    </div>
                </div>

                <div class="declaration-list">
                    <label class="declaration-item">
                        <input type="checkbox" name="c1" required>
                        <div class="declaration-text">
                            <strong>Accuracy of Information</strong>
                            <span>I certify that all information and documents submitted are true and accurate.</span>
                        </div>
                    </label>
                    <label class="declaration-item">
                        <input type="checkbox" name="c1" required>
                        <div class="declaration-text">
                            <strong>PhilGEPS Registration Compliance</strong>
                            <span>I confirm my business is registered with PhilGEPS and all details are up to date.</span>
                        </div>
                    </label>
                    <label class="declaration-item">
                        <input type="checkbox" name="c1" required>
                        <div class="declaration-text">
                            <strong>No Conflict of Interest</strong>
                            <span>I declare there is no conflict of interest in my participation in SLSU procurement.</span>
                        </div>
                    </label>
                    <label class="declaration-item">
                        <input type="checkbox" name="c1" required>
                        <div class="declaration-text">
                            <strong>Terms of Use &amp; Privacy Policy</strong>
                            <span>I have read and agree to YesParency's terms of use and privacy policy.</span>
                        </div>
                    </label>
                    <label class="declaration-item">
                        <input type="checkbox" name="c1" required>
                        <div class="declaration-text">
                            <strong>Audit Trail Consent</strong>
                            <span>I consent to the recording of my activities for audit and transparency purposes.</span>
                        </div>
                    </label>
                </div>

                <div style="margin-top: 28px;">
                    <button type="submit" name="submit" class="btn-register">
                        <i class="bi bi-send-check"></i>
                        Submit Application
                    </button>
                </div>
            </div>

        </form>

        <?php else: ?>

        <!-- ========================= -->
        <!-- APPLICATION STATUS        -->
        <!-- ========================= -->
        <div class="status-card-wrap">

            <?php if($status == 'pending'): ?>
            <div class="app-status-card pending">
                <div class="app-status-icon"><i class="bi bi-hourglass-split"></i></div>
                <h3>Application Under Review</h3>
                <p>Your bidder application has been submitted and is currently being reviewed by the administrator. You will be notified once a decision has been made.</p>
                <a href="dashboard.php" class="btn-back-dash">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php elseif($status == 'approved'): ?>
            <div class="app-status-card approved">
                <div class="app-status-icon"><i class="bi bi-patch-check-fill"></i></div>
                <h3>Application Approved</h3>
                <p>Congratulations! You are now a verified bidder and may participate in SLSU bidding opportunities.</p>
                <a href="dashboard.php" class="btn-back-dash">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php elseif($status == 'rejected'): ?>
            <div class="app-status-card rejected">
                <div class="app-status-icon"><i class="bi bi-x-circle-fill"></i></div>
                <h3>Application Rejected</h3>
                <p>Unfortunately, your bidder application was not approved. Please contact the SLSU Procurement Office for more information.</p>
                <a href="dashboard.php" class="btn-back-dash">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            <?php endif; ?>

        </div>

        <?php endif; ?>

    </div>
</main>

<script>
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
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    // Show selected filename below the card without shifting layout
    function showFileName(input, targetId) {
        const el = document.getElementById(targetId);
        if (!el) return;

        if (input.files[0]) {
            el.innerHTML =
                '<i class="bi bi-paperclip"></i> ' +
                input.files[0].name +
                '<button type="button" class="file-remove-btn" onclick="removeFile(\'' + input.name + '\', \'' + targetId + '\')" aria-label="Remove file">' +
                '<i class="bi bi-x-circle-fill"></i></button>';
        } else {
            el.innerHTML = '';
        }
    }

    function removeFile(inputName, targetId) {
        // Reset the file input by replacing it with a fresh clone
        const label = document.querySelector('input[name="' + inputName + '"]').closest('label');
        const oldInput = label.querySelector('input[type="file"]');
        const newInput = oldInput.cloneNode(true);
        newInput.value = '';
        oldInput.replaceWith(newInput);

        // Clear the display
        const el = document.getElementById(targetId);
        if (el) el.innerHTML = '';
    }
</script>

</body>
</html>

