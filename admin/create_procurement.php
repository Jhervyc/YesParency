<?php 
    include("utils/protect-page.php");

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_procurement'])) {

        // 1. Sanitize and retrieve inputs
        $ref_no = isset($_POST['philgeps_ref_no']) ? trim($_POST['philgeps_ref_no']) : '';
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $abc = floatval($_POST['abc']);
        $mode = trim($_POST['procurement_mode']);
        
        // Handle optional dates cleanly (convert empty strings to NULL)
        $posting_date = !empty($_POST['posting_date']) ? $_POST['posting_date'] : NULL;
        $closing_date = !empty($_POST['closing_date']) ? $_POST['closing_date'] : NULL;
        $opening_date = !empty($_POST['opening_date']) ? $_POST['opening_date'] : NULL;

        $created_by = intval($_SESSION['user_id']);

        // 2. Check for duplicate PhilGEPS Ref No
        if (!empty($ref_no)) {
            $check_stmt = $conn->prepare("SELECT id FROM procurements WHERE philgeps_ref_no = ? LIMIT 1");
            $check_stmt->bind_param("s", $ref_no);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "PhilGEPS Reference Number already exists!";
                header("Location: create_procurement.php?error=duplicate_ref");
                exit();
            }
            $check_stmt->close();
        }

        // 3. Insert into Database using Prepared Statements
        $stmt = $conn->prepare("
            INSERT INTO procurements
            (
                philgeps_ref_no,
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
            
            if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                
                $upload_dir = "../uploads/procurements/";

                // Create folder if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $doc_stmt = $conn->prepare("
                    INSERT INTO procurement_documents (procurement_id, document_name, file_path) 
                    VALUES (?, ?, ?)
                ");

                foreach ($_FILES['documents']['name'] as $index => $original_name) {
                    $tmp_name = $_FILES['documents']['tmp_name'][$index];
                    $file_error = $_FILES['documents']['error'][$index];

                    if ($file_error === UPLOAD_ERR_OK) {
                        // Generate a unique filename to prevent overwriting existing files
                        $file_ext = pathinfo($original_name, PATHINFO_EXTENSION);
                        $unique_filename = time() . '_' . uniqid() . '.' . $file_ext;
                        $target_path = $upload_dir . $unique_filename;

                        if (move_uploaded_file($tmp_name, $target_path)) {
                            // Insert record into procurement_documents table
                            $doc_stmt->bind_param("iss", $procurement_id, $original_name, $target_path);
                            $doc_stmt->execute();
                        }
                    }
                }
                $doc_stmt->close();
            }

            header("Location: manage_lots.php?id=" . $procurement_id);
            exit();
        } else {
            $stmt->close();
            header("Location: create_procurement.php?error=insert_failed");
            exit();
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Procurement | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page header -->
        <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>Create Procurement</h2>
                <p>Fill in the details to draft a new procurement record.</p>
            </div>
            <a href="procurement.php" class="proc-action-btn manage" style="text-decoration:none;">
                <i class="bi bi-arrow-left"></i> Back to Procurements
            </a>
        </div>

        <form action="create_procurement.php" method="POST" enctype="multipart/form-data">

            <!-- ========================= -->
            <!-- SECTION 1: Info           -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">1</div>
                    <div>
                        <h4>Procurement Information</h4>
                        <p>Basic details about the procurement project.</p>
                    </div>
                </div>

                <div class="reg-grid">

                    <div class="form-group">
                        <label for="philgeps_ref_no">PhilGEPS Reference Number</label>
                        <div class="input-wrapper">
                            <i class="bi bi-hash input-icon-left"></i>
                            <input type="text" id="philgeps_ref_no" name="philgeps_ref_no"
                                   placeholder="e.g. SLSU-BAC-2026-001" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="procurement_mode">Procurement Mode</label>
                        <div class="input-wrapper">
                            <i class="bi bi-briefcase input-icon-left"></i>
                            <select id="procurement_mode" name="procurement_mode" required>
                                <option value="" disabled selected>Select mode</option>
                                <option value="Public Bidding">Public Bidding</option>
                                <option value="Limited Source Bidding">Limited Source Bidding</option>
                                <option value="Direct Contracting">Direct Contracting</option>
                                <option value="Repeat Order">Repeat Order</option>
                                <option value="Shopping">Shopping</option>
                                <option value="Negotiated Procurement">Negotiated Procurement</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group reg-full">
                        <label for="title">Project Title</label>
                        <div class="input-wrapper">
                            <i class="bi bi-file-earmark-text input-icon-left"></i>
                            <input type="text" id="title" name="title"
                                   placeholder="e.g. Supply and Delivery of Science Laboratory Equipment" required>
                        </div>
                    </div>

                    <div class="form-group reg-full">
                        <label for="description">Project Description</label>
                        <textarea id="description" name="description" rows="4"
                                  placeholder="Describe the scope, objectives, and specifications of the procurement project..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="abc">Approved Budget for the Contract (ABC)</label>
                        <div class="input-wrapper">
                            <i class="bi bi-cash input-icon-left"></i>
                            <input type="number" step="0.01" min="0" id="abc" name="abc"
                                   placeholder="0.00" required>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ========================= -->
            <!-- SECTION 2: Dates          -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">2</div>
                    <div>
                        <h4>Important Dates</h4>
                        <p>Set the posting, submission deadline, and bid opening schedule.</p>
                    </div>
                </div>

                <div class="reg-grid">

                    <div class="form-group">
                        <label for="posting_date">Posting Date</label>
                        <div class="input-wrapper">
                            <i class="bi bi-calendar input-icon-left"></i>
                            <input type="date" id="posting_date" name="posting_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="closing_date">Bid Submission Deadline</label>
                        <div class="input-wrapper">
                            <i class="bi bi-calendar-x input-icon-left"></i>
                            <input type="datetime-local" id="closing_date" name="closing_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="opening_date">Bid Opening Date</label>
                        <div class="input-wrapper">
                            <i class="bi bi-calendar-check input-icon-left"></i>
                            <input type="datetime-local" id="opening_date" name="opening_date" required>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ========================= -->
            <!-- SECTION 3: Documents      -->
            <!-- ========================= -->
            <div class="reg-section">
                <div class="reg-section-header">
                    <div class="reg-step-num">3</div>
                    <div>
                        <h4>Additional Documents</h4>
                        <p>Upload related files. Accepted: PDF, DOC, DOCX, ZIP. Multiple files allowed.</p>
                    </div>
                </div>

                <div class="create-doc-upload">
                    <label class="create-doc-label" for="documents">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span id="doc-label-text">Click to choose files or drag &amp; drop</span>
                        <small>PDF, DOC, DOCX, ZIP</small>
                        <input type="file" id="documents" name="documents[]"
                               multiple accept=".pdf,.doc,.docx,.zip"
                               onchange="showDocFiles(this)">
                    </label>
                    <ul class="doc-file-list" id="docFileList"></ul>
                </div>

            </div>

            <!-- Submit / Cancel -->
            <div style="display:flex; gap:12px; align-items:center; margin-top:4px;">
                <button type="submit" name="save_procurement" class="btn-register">
                    <i class="bi bi-floppy"></i> Save as Draft
                </button>
                <a href="procurement.php" class="proc-action-btn delete" style="text-decoration:none; padding: 13px 20px;">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
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


    // Show selected filenames in the doc list
    function showDocFiles(input) {
        const list  = document.getElementById('docFileList');
        const label = document.getElementById('doc-label-text');
        list.innerHTML = '';

        if (input.files.length === 0) {
            label.textContent = 'Click to choose files or drag & drop';
            return;
        }

        label.textContent = input.files.length + ' file(s) selected';

        Array.from(input.files).forEach((file, i) => {
            const li = document.createElement('li');
            li.innerHTML =
                '<i class="bi bi-file-earmark"></i>' +
                '<span>' + file.name + '</span>' +
                '<small>' + (file.size / 1024).toFixed(1) + ' KB</small>';
            list.appendChild(li);
        });
    }

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
