<?php 
    include("utils/protect-page.php");

    $errors = [];

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_procurement'])) {

        // 1. Sanitize and retrieve inputs
        $ref_no = isset($_POST['philgeps_ref_no']) ? trim($_POST['philgeps_ref_no']) : '';
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
            $errors[] = "PhilGEPS Reference Number / Project Ref is required.";
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

        // 2. Check for duplicate PhilGEPS Ref No
        if (!empty($ref_no)) {
            $check_stmt = $conn->prepare("SELECT id FROM procurements WHERE philgeps_ref_no = ? LIMIT 1");
            $check_stmt->bind_param("s", $ref_no);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $errors[] = "PhilGEPS Reference Number '" . htmlspecialchars($ref_no) . "' is already in use.";
            }
            $check_stmt->close();
        }

        // 3. Insert into Database if no errors
        if (empty($errors)) {
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
                
                // Handle file uploads
                if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                    $upload_dir = "../uploads/procurements/";

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

                        if ($file_error === UPLOAD_ERR_OK && !empty($original_name)) {
                            $file_ext = pathinfo($original_name, PATHINFO_EXTENSION);
                            $clean_orig = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $original_name);
                            $unique_filename = time() . '_' . uniqid() . '_' . $clean_orig;
                            $target_path = $upload_dir . $unique_filename;

                            if (move_uploaded_file($tmp_name, $target_path)) {
                                $doc_stmt->bind_param("iss", $procurement_id, $original_name, $target_path);
                                $doc_stmt->execute();
                            }
                        }
                    }
                    $doc_stmt->close();
                }

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
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Base Container & Reset ── */
        * {
            box-sizing: border-box;
        }

        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        .vp-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #06251b;
            font-size: 12.5px;
            font-weight: 700;
            background: #ffffff;
            border: 1px solid #eaeeec;
            padding: 8px 16px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
            transform: translateY(-1px);
        }

        /* ── Page Header / Banner ── */
        .page-header-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 22px;
        }

        .page-title-group h2 {
            font-size: 22px;
            font-weight: 800;
            color: #06251b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-group p {
            font-size: 13px;
            color: #63736a;
            margin: 4px 0 0;
        }

        /* ── Two-Column Layout (1.7fr + 1fr) ── */
        .vp-grid-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 30px;
            min-width: 0;
            max-width: 100%;
        }

        .vp-left-col {
            min-width: 0;
            max-width: 100%;
        }

        .vp-right-col {
            min-width: 0;
            max-width: 100%;
            position: sticky;
            top: 20px;
        }

        @media (max-width: 1060px) {
            .vp-grid-layout {
                grid-template-columns: 1fr;
            }
            .vp-right-col {
                position: static;
            }
        }

        /* ── Step Cards ── */
        .submit-step-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            overflow: hidden;
            min-width: 0;
            max-width: 100%;
            transition: border-color .2s;
        }

        .submit-step-card:focus-within {
            border-color: #1f7a3d;
        }

        .step-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
            gap: 14px;
            flex-wrap: wrap;
        }

        .step-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .step-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #06251b;
            color: #ffc107;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(6, 37, 27, 0.15);
        }

        .step-title-text {
            min-width: 0;
            flex: 1 1 auto;
        }

        .step-title-text h4 {
            font-size: 15px;
            font-weight: 800;
            color: #06251b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step-title-text p {
            font-size: 12px;
            color: #6c776e;
            margin: 3px 0 0;
        }

        .step-card-body {
            padding: 22px;
            min-width: 0;
            max-width: 100%;
        }

        /* ── Input Form Elements ── */
        .form-grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            min-width: 0;
        }

        @media (max-width: 720px) {
            .form-grid-2col {
                grid-template-columns: 1fr;
            }
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
            min-width: 0;
        }

        .field-group.full-width {
            grid-column: 1 / -1;
            margin-bottom: 14px;
        }

        .field-label {
            font-size: 12px;
            font-weight: 700;
            color: #2b3a31;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .field-label .req {
            color: #e53935;
            font-weight: 800;
        }

        .field-hint {
            font-size: 11px;
            color: #88968d;
            font-weight: 400;
            line-height: 1.35;
        }

        .input-icon-box {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            min-width: 0;
        }

        .input-icon-box i.input-icon {
            position: absolute;
            left: 14px;
            color: #88968d;
            font-size: 15px;
            pointer-events: none;
            transition: color .15s;
            z-index: 1;
        }

        .input-icon-box input,
        .input-icon-box select,
        .input-icon-box textarea {
            width: 100%;
            min-width: 0;
            background: #ffffff;
            border: 1.5px solid #dce4e0;
            border-radius: 10px;
            padding: 10px 14px 10px 40px;
            font-size: 13px;
            font-family: inherit;
            color: #06251b;
            font-weight: 500;
            transition: all .2s ease;
            outline: none;
            color-scheme: light;
        }

        .input-icon-box input[type="date"],
        .input-icon-box input[type="datetime-local"] {
            padding-right: 12px;
            font-size: 12.5px;
            font-family: inherit;
        }

        .input-icon-box textarea {
            padding: 12px 14px 12px 40px;
            resize: vertical;
            min-height: 90px;
            line-height: 1.5;
        }

        .input-icon-box textarea + i.input-icon {
            top: 14px;
        }

        .input-icon-box select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236c776e' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            cursor: pointer;
        }

        .input-icon-box input:focus,
        .input-icon-box select:focus,
        .input-icon-box textarea:focus {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 3px rgba(31, 122, 61, 0.12);
            background: #fafcfb;
        }

        .input-icon-box input:focus + i.input-icon,
        .input-icon-box select:focus + i.input-icon,
        .input-icon-box textarea:focus + i.input-icon {
            color: #1f7a3d;
        }

        /* ── Currency Badge Helper ── */
        .currency-preview-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            margin-top: 6px;
            border: 1px solid #d3ebd9;
            transition: all .2s;
        }

        /* ── Notice / Tip Box ── */
        .guideline-tip-box {
            background: #f4faf6;
            border: 1px solid #cde6d5;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-top: 8px;
        }

        .guideline-tip-box i {
            font-size: 18px;
            color: #1f7a3d;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .guideline-tip-box p {
            font-size: 12px;
            color: #294734;
            line-height: 1.5;
            margin: 0;
        }

        /* ── File Upload Section & Drag Drop Trigger ── */
        .doc-upload-container {
            background: #ffffff;
            border: 1.5px solid #e2e9e5;
            border-radius: 14px;
            padding: 18px;
            transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .doc-drop-trigger {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fafcfb;
            border: 2px dashed #cfdbd4;
            border-radius: 12px;
            padding: 24px 20px;
            cursor: pointer;
            transition: all .2s;
            text-align: center;
            user-select: none;
        }

        .doc-drop-trigger:hover,
        .doc-drop-trigger.dragover {
            border-color: #1f7a3d;
            background: #f0f7f2;
        }

        .doc-drop-icon {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 10px;
            transition: transform .2s;
        }

        .doc-drop-trigger:hover .doc-drop-icon {
            transform: scale(1.08);
            background: #1f7a3d;
            color: #ffffff;
        }

        .doc-drop-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #06251b;
            margin-bottom: 3px;
        }

        .doc-drop-sub {
            font-size: 11.5px;
            color: #88968d;
            margin-bottom: 12px;
        }

        .doc-browse-btn {
            background: #06251b;
            color: #ffc107;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .15s;
            box-shadow: 0 2px 6px rgba(6, 37, 27, 0.12);
        }

        .doc-drop-trigger:hover .doc-browse-btn {
            background: #144937;
            color: #ffffff;
        }

        /* ── File Item Pills List ── */
        .file-pills-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 14px;
        }

        .file-preview-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fbfdfc;
            border: 1px solid #d7e4dc;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 12.5px;
            animation: fadeInFile .2s ease;
        }

        @keyframes fadeInFile {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .file-pill-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            overflow: hidden;
        }

        .file-pill-left i {
            color: #1f7a3d;
            font-size: 16px;
            flex-shrink: 0;
        }

        .file-pill-name {
            font-weight: 700;
            color: #06251b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-pill-size {
            font-size: 11px;
            color: #88968d;
            background: #edf3f0;
            padding: 2px 7px;
            border-radius: 4px;
            flex-shrink: 0;
            font-weight: 600;
        }

        .file-remove-btn {
            background: none;
            border: none;
            color: #c23b3b;
            cursor: pointer;
            padding: 4px;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .15s;
            flex-shrink: 0;
        }

        .file-remove-btn:hover {
            color: #d32f2f;
            transform: scale(1.15);
        }

        /* ── Submit Action Bar ── */
        .form-actions-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 10px;
            padding-top: 18px;
            border-top: 1px solid #edf1ee;
        }

        .btn-submit-proposal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #06251b;
            color: #ffc107;
            font-size: 14px;
            font-weight: 800;
            padding: 13px 28px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 4px 14px rgba(6, 37, 27, 0.18);
        }

        .btn-submit-proposal:hover {
            background: #144937;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6, 37, 27, 0.26);
        }

        .btn-cancel-proposal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #ffffff;
            color: #6c776e;
            border: 1px solid #eaeeec;
            font-size: 13px;
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            transition: all .15s ease;
        }

        .btn-cancel-proposal:hover {
            background: #f7faf8;
            color: #c23b3b;
            border-color: #f0cece;
        }

        /* ── Right Column Sidebar Cards ── */
        .sidebar-summary-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .summary-card-head {
            background: #06251b;
            color: #ffffff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-card-head h4 {
            font-size: 14px;
            font-weight: 800;
            color: #ffc107;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-card-body {
            padding: 20px;
        }

        /* ── Live Completion Progress ── */
        .progress-block {
            margin-bottom: 18px;
        }

        .progress-lbl-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
            margin-bottom: 6px;
        }

        .progress-track {
            width: 100%;
            height: 8px;
            background: #eaeeec;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1f7a3d 0%, #2ecc71 100%);
            width: 0%;
            border-radius: 10px;
            transition: width .3s ease;
        }

        /* ── Checklist Items ── */
        .checklist-group {
            display: flex;
            flex-direction: column;
            gap: 9px;
            margin-bottom: 18px;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #63736a;
            font-weight: 600;
            transition: color .15s;
        }

        .check-item.done {
            color: #06251b;
        }

        .check-dot {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #f0f4f2;
            border: 1.5px solid #cfdbd4;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: transparent;
            flex-shrink: 0;
            transition: all .2s;
        }

        .check-item.done .check-dot {
            background: #1f7a3d;
            border-color: #1f7a3d;
            color: #ffffff;
        }

        /* ── Live Snapshot Preview Card ── */
        .preview-snapshot-card {
            background: #fafcfb;
            border: 1px solid #e4eae6;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .preview-snap-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #88968d;
            margin-bottom: 4px;
        }

        .preview-snap-val {
            font-size: 13.5px;
            font-weight: 700;
            color: #06251b;
            margin-bottom: 12px;
            word-break: break-word;
            line-height: 1.35;
        }

        .preview-snap-val.amount {
            font-size: 16px;
            font-family: 'Space Grotesk', sans-serif;
            color: #1f7a3d;
            font-weight: 800;
        }

        .preview-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #eef7f1;
            color: #1f7a3d;
            border: 1px solid #d0e7d7;
        }

        /* ── 4-Step Guide Timeline ── */
        .timeline-guide {
            position: relative;
            padding-left: 20px;
        }

        .timeline-guide::before {
            content: '';
            position: absolute;
            top: 6px;
            bottom: 6px;
            left: 5px;
            width: 2px;
            background: #eaeeec;
        }

        .timeline-guide-step {
            position: relative;
            margin-bottom: 14px;
        }

        .timeline-guide-step:last-child {
            margin-bottom: 0;
        }

        .timeline-guide-dot {
            position: absolute;
            left: -19px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ffffff;
            border: 2.5px solid #1f7a3d;
        }

        .timeline-guide-title {
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
        }

        .timeline-guide-sub {
            font-size: 11px;
            color: #88968d;
            margin-top: 1px;
            line-height: 1.4;
        }

        /* ── Error Banner ── */
        .sb-error-banner {
            background: #fdf2f2;
            border: 1px solid #f8d7da;
            border-left: 4px solid #dc3545;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            color: #842029;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.06);
        }

        .sb-error-banner i {
            font-size: 20px;
            color: #dc3545;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .sb-error-banner ul {
            margin: 6px 0 0;
            padding-left: 18px;
            font-size: 12.5px;
            line-height: 1.6;
        }
    </style>
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
                <strong style="font-size:14px;">Please correct the following errors before proceeding:</strong>
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

                            <!-- PhilGEPS Ref No -->
                            <div class="field-group">
                                <label class="field-label" for="philgeps_ref_no">
                                    PhilGEPS / Project Reference No. <span class="req">*</span>
                                </label>
                                <div class="input-icon-box">
                                    <input type="text" 
                                           id="philgeps_ref_no" 
                                           name="philgeps_ref_no"
                                           placeholder="e.g. SLSU-BAC-2026-001" 
                                           value="<?= htmlspecialchars($_POST['philgeps_ref_no'] ?? '') ?>" 
                                           required>
                                    <i class="bi bi-hash input-icon"></i>
                                </div>
                                <span class="field-hint">Official reference identifier registered in PhilGEPS</span>
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
                                        <option value="Limited Source Bidding" <?= (($_POST['procurement_mode'] ?? '') === 'Limited Source Bidding') ? 'selected' : '' ?>>Limited Source Bidding</option>
                                        <option value="Direct Contracting" <?= (($_POST['procurement_mode'] ?? '') === 'Direct Contracting') ? 'selected' : '' ?>>Direct Contracting (Single Source)</option>
                                        <option value="Repeat Order" <?= (($_POST['procurement_mode'] ?? '') === 'Repeat Order') ? 'selected' : '' ?>>Repeat Order</option>
                                        <option value="Shopping" <?= (($_POST['procurement_mode'] ?? '') === 'Shopping') ? 'selected' : '' ?>>Shopping (Sec. 52)</option>
                                        <option value="Negotiated Procurement" <?= (($_POST['procurement_mode'] ?? '') === 'Negotiated Procurement') ? 'selected' : '' ?>>Negotiated Procurement</option>
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
                                <span class="field-hint">PhilGEPS publication date</span>
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
                                       style="display:none;" 
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
                        <span style="font-size:11px; background:rgba(255,255,255,0.15); padding:2px 8px; border-radius:10px; font-weight:700;" id="progress_pct">0%</span>
                    </div>
                    <div class="summary-card-body">
                        
                        <div class="progress-block">
                            <div class="progress-lbl-row">
                                <span>Completion Status</span>
                                <span id="progress_text" style="color:#1f7a3d;">Incomplete</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill" id="progress_bar"></div>
                            </div>
                        </div>

                        <div class="checklist-group">
                            <div class="check-item" id="chk_ref">
                                <div class="check-dot"><i class="bi bi-check-lg"></i></div>
                                <span>Step 1: PhilGEPS Ref No.</span>
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

                        <div style="font-size:11.5px; color:#6c776e; line-height:1.5; background:#fafcfb; border:1px solid #eaeeec; border-radius:10px; padding:10px 12px;">
                            <i class="bi bi-shield-lock" style="color:#1f7a3d;"></i>
                            All required fields (<span style="color:#e53935; font-weight:700;">*</span>) must be filled to advance to item configuration.
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
                            <div style="margin-bottom:12px;">
                                <span class="preview-badge" id="snap_mode"><i class="bi bi-tag-fill"></i> Not selected</span>
                            </div>

                            <div class="preview-snap-label">Submission Deadline</div>
                            <div class="preview-snap-val" id="snap_deadline" style="margin-bottom:0; font-size:12px; color:#63736a;">
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
        const refInput   = document.getElementById('philgeps_ref_no');
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
                txtEl.style.color = '#1f7a3d';
            } else {
                txtEl.textContent = completed + ' of ' + total + ' completed';
                txtEl.style.color = '#55665a';
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
