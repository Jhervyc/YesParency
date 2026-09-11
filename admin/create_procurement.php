<?php 
    include("utils/protect-page.php");
    include("utils/protect-secretariat.php");

    $errors = [];

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_procurement'])) {

        // 1. Sanitize and retrieve inputs
        $ref_no = isset($_POST['slsu_ref_no']) ? trim($_POST['slsu_ref_no']) : '';
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $abc = isset($_POST['abc']) ? floatval($_POST['abc']) : 0.00;
        $mode = isset($_POST['procurement_mode']) ? trim($_POST['procurement_mode']) : '';
        
        // Handle optional dates cleanly (convert empty strings to NULL)
        $posting_date = !empty($_POST['posting_date']) ? $_POST['posting_date'] : NULL;
        $closing_date = !empty($_POST['closing_date']) ? $_POST['closing_date'] : NULL;
        $opening_date = !empty($_POST['opening_date']) ? $_POST['opening_date'] : NULL;

        $created_by = intval($_SESSION['user_id']);

        // Validation
        if (empty($ref_no)) {
            $errors[] = "SLSU Reference is required.";
        }
        if (empty($title)) {
            $errors[] = "Project Title is required.";
        }
        if ($abc <= 0) {
            $errors[] = "Approved Budget for the Contract (ABC) must be greater than 0.";
        }
        if (empty($mode)) {
            $errors[] = "Please select a valid Procurement Mode.";
        }
        if (empty($posting_date)) {
            $errors[] = "Posting Date is required.";
        }
        if (empty($closing_date)) {
            $errors[] = "Bid Submission Deadline (Closing Date) is required.";
        }
        if (empty($opening_date)) {
            $errors[] = "Bid Opening Date is required.";
        }

        // Validate date order if dates are provided
        if ($posting_date && $closing_date) {
            $postTs = strtotime($posting_date);
            $closeTs = strtotime($closing_date);
            if ($closeTs <= $postTs) {
                $errors[] = "Bid Submission Deadline must be scheduled after the Posting Date.";
            }
        }
        if ($closing_date && $opening_date) {
            $closeTs = strtotime($closing_date);
            $openTs = strtotime($opening_date);
            if ($openTs < $closeTs) {
                $errors[] = "Bid Opening Date cannot be earlier than the Bid Submission Deadline.";
            }
        }

        // 2. Check for duplicate SLSU Ref No
        if (!empty($ref_no)) {
            $check_stmt = $conn->prepare("SELECT id FROM procurements WHERE slsu_ref_no = ? LIMIT 1");
            $check_stmt->bind_param("s", $ref_no);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $errors[] = "SLSU Reference Number '" . htmlspecialchars($ref_no) . "' is already in use.";
            }
            $check_stmt->close();
        }

        // 3. Insert into Database if no errors
        if (empty($errors)) {
            $stmt = $conn->prepare("
                INSERT INTO procurements
                (
                    slsu_ref_no,
                    title,
                    description,
                    abc,
                    procurement_mode,
                    posting_date,
                    closing_date,
                    opening_date,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssdssssi",
                $ref_no,
                $title,
                $description,
                $abc,
                $mode,
                $posting_date,
                $closing_date,
                $opening_date,
                $created_by
            );

            if ($stmt->execute()) {
                $procurement_id = $conn->insert_id;
                $stmt->close();
                
                // Handle file uploads
                if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                    $upload_dir = "../uploads/procurements/";

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $doc_stmt = $conn->prepare("
                        INSERT INTO procurement_documents (procurement_id, document_category, document_name, file_path) 
                        VALUES (?, 'original', ?, ?)
                    ");

                    $uploaded_doc_names = [];
                    foreach ($_FILES['documents']['name'] as $index => $original_name) {
                        $tmp_name = $_FILES['documents']['tmp_name'][$index];
                        $file_error = $_FILES['documents']['error'][$index];

                        if ($file_error === UPLOAD_ERR_OK && !empty($original_name)) {
                            $file_ext = pathinfo($original_name, PATHINFO_EXTENSION);
                            $clean_orig = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $original_name);
                            $unique_filename = time() . '_' . uniqid() . '_' . $clean_orig;
                            $target_path = $upload_dir . $unique_filename;

                            if (move_uploaded_file($tmp_name, $target_path)) {
                                $doc_stmt->bind_param("iss", $procurement_id, $original_name, $target_path);
                                $doc_stmt->execute();
                                $uploaded_doc_names[] = $original_name;
                            }
                        }
                    }
                    $doc_stmt->close();
                } else {
                    $uploaded_doc_names = [];
                }

                // Audit log creation
                audit_log(
                    $conn,
                    'PROCUREMENT_CREATED',
                    'procurements',
                    $procurement_id,
                    "Created procurement project: {$title}",
                    null,
                    [
                        'title' => $title,
                        'slsu_ref_no' => $ref_no,
                        'abc' => $abc,
                        'procurement_mode' => $mode,
                        'posting_date' => $posting_date,
                        'closing_date' => $closing_date,
                        'opening_date' => $opening_date,
                        'uploaded_documents' => $uploaded_doc_names
                    ]
                );

                $_SESSION['alert_success'] = "Procurement project drafted successfully! You can now configure the lots and items.";
                header("Location: manage_lots.php?id=" . $procurement_id);
                exit();
            } else {
                $stmt->close();
                $errors[] = "Failed to create procurement record. Please check your database connection or inputs.";
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Procurement Project | YesParency Admin</title>
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
    <link rel="stylesheet" href="../css/pages/admin-create-procurement.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Page Top Header ── -->
    <div class="page-header-wrap">
        <div class="page-title-group">
            <h2></i> Create Procurement Project</h2>
            <p>Draft a competitive government bidding project or negotiated procurement workflow.</p>
        </div>
        <div>
            <a href="procurement.php" class="vp-back-link">
                <i class="bi bi-arrow-left"></i> Back to Procurements
            </a>
        </div>
    </div>

    <!-- ── Error Banner ── -->
    <?php if (!empty($errors)): ?>
        <div class="sb-error-banner">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <strong class="error-banner-title">Please correct the following errors before proceeding:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
    <form action="create_procurement.php" method="POST" enctype="multipart/form-data" id="procurementCreateForm">

        <div class="vp-grid-layout">

            <!-- ── LEFT COLUMN: Step 1, Step 2, Step 3 ── -->
            <div class="vp-left-col">

                <!-- ============================================ -->
                <!-- STEP 1: General Procurement Details         -->
                <!-- ============================================ -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">1</div>
                            <div class="step-title-text">
                                <h4>General Project Details</h4>
                                <p>Identification, procurement classification, and budget allocation.</p>
                            </div>
                        </div>
                    </div>

                    <div class="step-card-body">
                        <div class="form-grid-2col">

                            <!-- SLSU Ref No -->
                            <div class="field-group">
                                <label class="field-label" for="slsu_ref_no">
                                    SLSU / Project Reference No. <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="text" 
                                           id="slsu_ref_no" 
                                           name="slsu_ref_no"
                                           placeholder="e.g. SLSU-BAC-2026-001" 
                                           value="<?= htmlspecialchars($_POST['slsu_ref_no'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-hash input-icon"></i>
                                </div>
                                <span class="field-hint">Official reference identifier registered in SLSU</span>
                            </div>

                            <!-- Procurement Mode -->
                            <div class="field-group">
                                <label class="field-label" for="procurement_mode">
                                    Procurement Mode <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <select id="procurement_mode" name="procurement_mode" required>
                                        <option value="" disabled <?= empty($_POST['procurement_mode']) ? 'selected' : '' ?>>-- Select Procurement Mode --</option>
                                        <option value="Public Bidding" <?= (($_POST['procurement_mode'] ?? '') === 'Public Bidding') ? 'selected' : '' ?>>Public Bidding (Competitive)</option>
                                        <option value="Shopping" <?= (($_POST['procurement_mode'] ?? '') === 'Shopping') ? 'selected' : '' ?>>Shopping (Sec. 52)</option>
                                        <option value="Small Value Procurement" <?= (($_POST['procurement_mode'] ?? '') === 'Small Value Procurement') ? 'selected' : '' ?>>Small Value Procurement (SVP)</option>
                                    </select>
                                    <i class="bi bi-briefcase input-icon"></i>
                                </div>
                                <span class="field-hint">Method prescribed under R.A. 9184</span>
                            </div>

                            <!-- Project Title -->
                            <div class="field-group full-width">
                                <label class="field-label" for="title">
                                    Project Title <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="text" 
                                           id="title" 
                                           name="title"
                                           placeholder="e.g. Supply, Delivery, and Installation of Laboratory Testing Equipment" 
                                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-file-earmark-text input-icon"></i>
                                </div>
                            </div>

                            <!-- Approved Budget for the Contract (ABC) -->
                            <div class="field-group full-width">
                                <label class="field-label" for="abc">
                                    Approved Budget for the Contract (ABC in PHP) <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="number" 
                                           step="0.01" 
                                           min="0" 
                                           id="abc" 
                                           name="abc"
                                           placeholder="0.00" 
                                           value="<?= htmlspecialchars($_POST['abc'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-currency-dollar input-icon"></i>
                                </div>
                                <div class="currency-preview-pill" id="abcFormattedPreview">
                                    <i class="bi bi-cash-stack"></i>
                                    <span>Formatted: ₱ 0.00</span>
                                </div>
                            </div>

                            <!-- Description / Scope -->
                            <div class="field-group full-width">
                                <label class="field-label" for="description">
                                    Project Description &amp; Scope of Work
                                </label>
                                <div class="input-icon-box">
                                    <textarea id="description" 
                                              name="description" 
                                              rows="4"
                                              placeholder="Provide detailed technical specifications, project objectives, deliverables, and terms of execution..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    <i class="bi bi-card-text input-icon"></i>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- STEP 2: Key Milestones & Important Dates     -->
                <!-- ============================================ -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">2</div>
                            <div class="step-title-text">
                                <h4>Procurement Milestones &amp; Schedule</h4>
                                <p>Set the statutory posting period, proposal submission deadline, and opening dates.</p>
                            </div>
                        </div>
                    </div>

                    <div class="step-card-body">
                        <div class="form-grid-2col">

                            <!-- Posting Date -->
                            <div class="field-group">
                                <label class="field-label" for="posting_date">
                                    Posting Date <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="date" 
                                           id="posting_date" 
                                           name="posting_date" 
                                           value="<?= htmlspecialchars($_POST['posting_date'] ?? date('Y-m-d')) ?>" 
                                           required>
                                    <i class="bi bi-calendar-event input-icon"></i>
                                </div>
                                <span class="field-hint">SLSU publication date</span>
                            </div>

                            <!-- Bid Submission Deadline (Closing Date) -->
                            <div class="field-group">
                                <label class="field-label" for="closing_date">
                                    Submission Deadline <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="datetime-local" 
                                           id="closing_date" 
                                           name="closing_date" 
                                           value="<?= htmlspecialchars($_POST['closing_date'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-calendar-x input-icon"></i>
                                </div>
                                <span class="field-hint">Envelope closing cutoff date &amp; time</span>
                            </div>

                            <!-- Bid Opening Date -->
                            <div class="field-group full-width">
                                <label class="field-label" for="opening_date">
                                    Bid Opening Date &amp; Time <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="datetime-local" 
                                           id="opening_date" 
                                           name="opening_date" 
                                           value="<?= htmlspecialchars($_POST['opening_date'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-calendar-check input-icon"></i>
                                </div>
                                <span class="field-hint">Public bid opening and envelope decryption schedule</span>
                            </div>

                        </div>

                        <div class="guideline-tip-box">
                            <i class="bi bi-info-circle-fill"></i>
                            <p>
                                <strong>R.A. 9184 Timeline Standard:</strong> For Public Bidding, a minimum advertisement period of seven (7) calendar days is recommended prior to the Pre-Bid Conference and envelope submission deadline.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- STEP 3: Tender Documents & Bidding Specs    -->
                <!-- ============================================ -->
                <div class="submit-step-card">
                    <div class="step-card-header">
                        <div class="step-header-left">
                            <div class="step-badge">3</div>
                            <div class="step-title-text">
                                <h4>Bidding Documents &amp; Attachments</h4>
                                <p>Upload Terms of Reference, Bill of Quantities, Technical Specs, or PhilGEPS Notices.</p>
                            </div>
                        </div>
                    </div>

                    <div class="step-card-body">
                        <div class="doc-upload-container">
                            <label class="doc-drop-trigger" id="docDropArea">
                                <div class="doc-drop-icon">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                </div>
                                <div class="doc-drop-title">Drag &amp; drop bidding documents here or browse</div>
                                <div class="doc-drop-sub">Supported formats: PDF, DOC, DOCX, XLS, XLSX, ZIP (Max 25MB each)</div>
                                <span class="doc-browse-btn">
                                    <i class="bi bi-folder-plus"></i> Select Files
                                </span>
                                <input type="file" 
                                       id="documents" 
                                       name="documents[]" 
                                       multiple 
                                       accept=".pdf,.doc,.docx,.xls,.xlsx,.zip"
                                       class="visually-hidden-input"
                                       onchange="handleProcDocFiles(this)">
                            </label>

                            <!-- File preview list -->
                            <div class="file-pills-list" id="docFileList"></div>
                        </div>

                        <!-- Actions Bar -->
                        <div class="form-actions-bar">
                            <button type="submit" name="save_procurement" class="btn-submit-proposal" id="btnSubmitProc">
                                <i class="bi bi-floppy-fill"></i> Save &amp; Proceed to Lots
                            </button>
                            <a href="procurement.php" class="btn-cancel-proposal">
                                <i class="bi bi-x-lg"></i> Cancel
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── RIGHT COLUMN: Live Checklist & Summary Snapshot ── -->
            <div class="vp-right-col">

                <!-- Summary Card 1: Checklist & Progress -->
                <div class="sidebar-summary-card">
                    <div class="summary-card-head">
                        <h4><i class="bi bi-list-check"></i> Creation Checklist</h4>
                        <span class="progress-pct-badge" id="progress_pct">0%</span>
                    </div>
                    <div class="summary-card-body">
                        
                        <div class="progress-block">
                            <div class="progress-lbl-row">
                                <span>Completion Status</span>
                                <span id="progress_text" class="progress-text--ready">Incomplete</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill" id="progress_bar"></div>
                            </div>
                        </div>

                        <div class="checklist-group">
                            <div class="check-item" id="chk_ref">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: SLSU Ref No.</span>
                            </div>
                            <div class="check-item" id="chk_mode">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: Procurement Mode</span>
                            </div>
                            <div class="check-item" id="chk_title">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: Project Title</span>
                            </div>
                            <div class="check-item" id="chk_abc">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: Valid ABC Amount</span>
                            </div>
                            <div class="check-item" id="chk_desc">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: Scope / Description</span>
                            </div>
                            <div class="check-item" id="chk_dates">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 2: Milestone Dates</span>
                            </div>
                            <div class="check-item" id="chk_docs">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 3: Tender Attachments</span>
                            </div>
                        </div>

                        <div class="security-note-box">
                            <i class="bi bi-shield-lock clr-forest"></i>
                            All required fields (<span class="req-mark-note">*</span>) must be filled to advance to item configuration.
                        </div>

                    </div>
                </div>

                <!-- Summary Card 2: Live Snapshot Preview -->
                <div class="sidebar-summary-card">
                    <div class="summary-card-head">
                        <h4><i class="bi bi-eye-fill"></i> Project Snapshot</h4>
                    </div>
                    <div class="summary-card-body">
                        
                        <div class="preview-snapshot-card">
                            <div class="preview-snap-label">Reference Number</div>
                            <div class="preview-snap-val" id="snap_ref">None (Draft)</div>

                            <div class="preview-snap-label">Project Title</div>
                            <div class="preview-snap-val" id="snap_title">Untitled Project</div>

                            <div class="preview-snap-label">Approved Budget (ABC)</div>
                            <div class="preview-snap-val amount" id="snap_abc">₱ 0.00</div>

                            <div class="preview-snap-label">Mode of Procurement</div>
                            <div class="mb-12">
                                <span class="preview-badge" id="snap_mode"><i class="bi bi-tag-fill"></i> Not selected</span>
                            </div>

                            <div class="preview-snap-label">Submission Deadline</div>
                            <div class="preview-snap-val snap-deadline" id="snap_deadline">
                                Not specified
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Summary Card 3: 4-Step Procurement Lifecycle Guide -->
                <div class="sidebar-summary-card">
                    <div class="summary-card-head">
                        <h4><i class="bi bi-diagram-3"></i> Procurement Workflow</h4>
                    </div>
                    <div class="summary-card-body">
                        <div class="timeline-guide">
                            
                            <div class="timeline-guide-step">
                                <div class="timeline-guide-dot"></div>
                                <div class="timeline-guide-title">1. Create Project Details</div>
                                <div class="timeline-guide-sub">Draft reference numbers, ABC allocation, and milestone timelines.</div>
                            </div>

                            <div class="timeline-guide-step">
                                <div class="timeline-guide-dot"></div>
                                <div class="timeline-guide-title">2. Lot &amp; Item Breakdown</div>
                                <div class="timeline-guide-sub">Partition budget across specific lots, quantities, and items.</div>
                            </div>

                            <div class="timeline-guide-step">
                                <div class="timeline-guide-dot"></div>
                                <div class="timeline-guide-title">3. Public Tender Notice</div>
                                <div class="timeline-guide-sub">Publish project to registered bidders and PhilGEPS portal.</div>
                            </div>

                            <div class="timeline-guide-step">
                                <div class="timeline-guide-dot"></div>
                                <div class="timeline-guide-title">4. Bid Opening &amp; Evaluation</div>
                                <div class="timeline-guide-sub">Unlock cryptographic envelopes and conduct BAC evaluation.</div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>

        </div>

    </form>

</div>
</main>

<!-- Session alert toast -->
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
    // ── Helper: Format Currency ──
    function formatPHP(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '₱ 0.00';
        return '₱ ' + num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // ── Helper: Format Bytes ──
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // ── Helper: Format Date Display ──
    function formatDateTimeDisplay(dtStr) {
        if (!dtStr) return 'Not specified';
        const dt = new Date(dtStr);
        if (isNaN(dt.getTime())) return dtStr;
        return dt.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    // ── Document Upload Handling ──
    function handleProcDocFiles(input) {
        const list = document.getElementById('docFileList');
        list.innerHTML = '';

        if (!input.files || input.files.length === 0) {
            updateProgress();
            return;
        }

        Array.from(input.files).forEach((file, index) => {
            const pill = document.createElement('div');
            pill.className = 'file-preview-pill';

            // Determine file icon
            let iconClass = 'bi-file-earmark-fill';
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext === 'pdf') iconClass = 'bi-file-earmark-pdf-fill';
            else if (['doc', 'docx'].includes(ext)) iconClass = 'bi-file-earmark-word-fill';
            else if (['xls', 'xlsx'].includes(ext)) iconClass = 'bi-file-earmark-excel-fill';
            else if (ext === 'zip') iconClass = 'bi-file-earmark-zip-fill';

            pill.innerHTML = `
                <div class="file-pill-left">
                    <i class="bi ${iconClass}"></i>
                    <span class="file-pill-name" title="${file.name}">${file.name}</span>
                    <span class="file-pill-size">${formatBytes(file.size)}</span>
                </div>
                <button type="button" class="file-remove-btn" onclick="clearFileByIndex(${index})" title="Remove attachment">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            `;
            list.appendChild(pill);
        });

        updateProgress();
    }

    function clearFileByIndex(index) {
        const fileInput = document.getElementById('documents');
        const dt = new DataTransfer();
        const { files } = fileInput;

        for (let i = 0; i < files.length; i++) {
            if (i !== index) {
                dt.items.add(files[i]);
            }
        }

        fileInput.files = dt.files;
        handleProcDocFiles(fileInput);
    }

    // ── Drag and Drop Support ──
    const dropArea = document.getElementById('docDropArea');
    if (dropArea) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropArea.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropArea.classList.remove('dragover');
            }, false);
        });

        dropArea.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            const fileInput = document.getElementById('documents');

            if (files && files.length > 0) {
                fileInput.files = files;
                handleProcDocFiles(fileInput);
            }
        }, false);
    }

    // ── Live Progress & Checklist Calculation ──
    function updateProgress() {
        const refInput   = document.getElementById('slsu_ref_no');
        const modeInput  = document.getElementById('procurement_mode');
        const titleInput = document.getElementById('title');
        const abcInput   = document.getElementById('abc');
        const descInput  = document.getElementById('description');
        const postInput  = document.getElementById('posting_date');
        const closeInput = document.getElementById('closing_date');
        const openInput  = document.getElementById('opening_date');
        const docInput   = document.getElementById('documents');

        // Validation states
        const hasRef   = refInput && refInput.value.trim() !== '';
        const hasMode  = modeInput && modeInput.value !== '';
        const hasTitle = titleInput && titleInput.value.trim() !== '';
        const numAbc   = abcInput ? parseFloat(abcInput.value) : 0;
        const hasAbc   = !isNaN(numAbc) && numAbc > 0;
        const hasDesc  = descInput && descInput.value.trim() !== '';
        const hasDates = postInput && postInput.value !== '' && 
                         closeInput && closeInput.value !== '' && 
                         openInput && openInput.value !== '';
        const hasDocs  = docInput && docInput.files && docInput.files.length > 0;

        // Set checklist dots
        setCheckStatus('chk_ref', hasRef);
        setCheckStatus('chk_mode', hasMode);
        setCheckStatus('chk_title', hasTitle);
        setCheckStatus('chk_abc', hasAbc);
        setCheckStatus('chk_desc', hasDesc);
        setCheckStatus('chk_dates', hasDates);
        setCheckStatus('chk_docs', hasDocs);

        // Calculate progress percentage (6 core mandatory items)
        const coreItems = [hasRef, hasMode, hasTitle, hasAbc, hasDesc, hasDates];
        const completed = coreItems.filter(Boolean).length;
        const total = coreItems.length;
        const pct = Math.round((completed / total) * 100);

        // Update progress bar
        const barEl = document.getElementById('progress_bar');
        const pctEl = document.getElementById('progress_pct');
        const txtEl = document.getElementById('progress_text');

        if (barEl) barEl.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        if (txtEl) {
            if (pct === 100) {
                txtEl.textContent = 'Ready to Save';
                txtEl.classList.add('progress-text--ready');
                txtEl.classList.remove('progress-text--pending');
            } else {
                txtEl.textContent = completed + ' of ' + total + ' completed';
                txtEl.classList.add('progress-text--pending');
                txtEl.classList.remove('progress-text--ready');
            }
        }

        // Update Snapshot Card
        const snapRef      = document.getElementById('snap_ref');
        const snapTitle    = document.getElementById('snap_title');
        const snapAbc      = document.getElementById('snap_abc');
        const snapMode     = document.getElementById('snap_mode');
        const snapDeadline = document.getElementById('snap_deadline');
        const abcPreview   = document.getElementById('abcFormattedPreview');

        if (snapRef) snapRef.textContent = hasRef ? refInput.value.trim() : 'None (Draft)';
        if (snapTitle) snapTitle.textContent = hasTitle ? titleInput.value.trim() : 'Untitled Project';
        if (snapAbc) snapAbc.textContent = formatPHP(numAbc);
        if (abcPreview) abcPreview.querySelector('span').textContent = 'Formatted: ' + formatPHP(numAbc);

        if (snapMode) {
            if (hasMode) {
                snapMode.innerHTML = `<i class="bi bi-tag-fill"></i> ${modeInput.value}`;
            } else {
                snapMode.innerHTML = `<i class="bi bi-tag-fill"></i> Not selected`;
            }
        }

        if (snapDeadline) {
            snapDeadline.textContent = closeInput && closeInput.value ? formatDateTimeDisplay(closeInput.value) : 'Not specified';
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

    // Attach listeners
    document.querySelectorAll('#procurementCreateForm input, #procurementCreateForm select, #procurementCreateForm textarea').forEach(input => {
        input.addEventListener('input', updateProgress);
        input.addEventListener('change', updateProgress);
    });

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    // Initial load calculation
    document.addEventListener('DOMContentLoaded', () => {
        updateProgress();
    });
</script>

</body>
</html>
