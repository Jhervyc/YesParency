<?php
include("utils/protect-page.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
$user_id        = (int)$_SESSION['user_id'];
$user_role      = $_SESSION['role'] ?? 'user';

// Secretariat & Admin Permission Check
if (!isset($_SESSION['admin_type']) && isset($conn) && isset($_SESSION['user_id'])) {
    $at_stmt = $conn->prepare("SELECT admin_type FROM admin_roles WHERE user_id = ?");
    if ($at_stmt) {
        $at_stmt->bind_param("i", $_SESSION['user_id']);
        $at_stmt->execute();
        $at_row = $at_stmt->get_result()->fetch_assoc();
        $_SESSION['admin_type'] = $at_row['admin_type'] ?? 'SECRETARIAT';
        $at_stmt->close();
    }
}
$admin_type = $_SESSION['admin_type'] ?? 'SECRETARIAT';
$can_manage_docs = ($user_role === 'superadmin' || $admin_type === 'SECRETARIAT');

if (!function_exists('resolve_proc_doc_url')) {
    function resolve_proc_doc_url($file_path, $context = 'admin') {
        $raw = trim($file_path ?? '');
        if (empty($raw)) return '#';
        if (preg_match('/^https?:\/\//i', $raw)) return $raw;

        $rel = ltrim($raw, '/');
        while (strpos($rel, '../') === 0) {
            $rel = substr($rel, 3);
        }
        if (strpos($rel, 'uploads/') !== 0) {
            $rel = 'uploads/procurements/' . $rel;
        }

        if (in_array($context, ['admin', 'bidder', 'user'])) {
            return '../' . $rel;
        }
        return $rel;
    }
}

// ── Handle Document Upload & Delete POST Actions ──
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_upload_associated_doc'])) {
        if (!$can_manage_docs) {
            $_SESSION['alert_error'] = "Unauthorized. Only Secretariat or Administrators can upload associated documents.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $doc_name = trim($_POST['document_name'] ?? '');
        if (empty($doc_name)) {
            $_SESSION['alert_error'] = "Please provide a document title/name.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        if (empty($_FILES['associated_document']['name']) || $_FILES['associated_document']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['alert_error'] = "Please select a valid file to upload.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $file = $_FILES['associated_document'];
        $max_size = 50 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            $_SESSION['alert_error'] = "File size exceeds the 50MB limit.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $orig_name = basename($file['name']);
        $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'zip', 'rar', '7z', 'png', 'jpg', 'jpeg'];
        if (!in_array($file_ext, $allowed_exts)) {
            $_SESSION['alert_error'] = "Invalid file type. Allowed formats: " . implode(', ', $allowed_exts);
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $upload_dir = "../uploads/procurements/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $clean_orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
        $unique_filename = time() . '_' . uniqid() . '_' . $clean_orig;
        $target_path = $upload_dir . $unique_filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $ins = $conn->prepare("INSERT INTO procurement_documents (procurement_id, document_category, document_name, file_path) VALUES (?, 'associated', ?, ?)");
            $ins->bind_param("iss", $procurement_id, $doc_name, $target_path);
            if ($ins->execute()) {
                $inserted_id = $ins->insert_id;
                audit_log(
                    $conn,
                    'PROCUREMENT_DOCUMENT_UPLOADED',
                    'procurement_documents',
                    $inserted_id,
                    "Uploaded associated document '{$doc_name}' for procurement project #{$procurement_id}",
                    [],
                    [
                        'procurement_id'    => $procurement_id,
                        'document_name'     => $doc_name,
                        'document_category' => 'associated',
                        'file_path'         => $target_path
                    ]
                );
                $_SESSION['alert_success'] = "Associated document '{$doc_name}' uploaded successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to record document in database.";
            }
            $ins->close();
        } else {
            $_SESSION['alert_error'] = "Failed to save the uploaded file to server.";
        }

        header("Location: view_procurement.php?id=" . $procurement_id);
        exit();
    }

    if (isset($_POST['action_delete_associated_doc'])) {
        if (!$can_manage_docs) {
            $_SESSION['alert_error'] = "Unauthorized. Only Secretariat or Administrators can remove associated documents.";
            header("Location: view_procurement.php?id=" . $procurement_id);
            exit();
        }

        $doc_id = intval($_POST['doc_id'] ?? 0);
        $d_stmt = $conn->prepare("SELECT id, document_name, file_path, document_category FROM procurement_documents WHERE id = ? AND procurement_id = ? LIMIT 1");
        $d_stmt->bind_param("ii", $doc_id, $procurement_id);
        $d_stmt->execute();
        $doc_row = $d_stmt->get_result()->fetch_assoc();
        $d_stmt->close();

        if ($doc_row) {
            if (!empty($doc_row['file_path']) && file_exists($doc_row['file_path'])) {
                @unlink($doc_row['file_path']);
            }
            $del = $conn->prepare("DELETE FROM procurement_documents WHERE id = ?");
            $del->bind_param("i", $doc_id);
            if ($del->execute()) {
                audit_log(
                    $conn,
                    'PROCUREMENT_DOCUMENT_DELETED',
                    'procurement_documents',
                    $doc_id,
                    "Deleted {$doc_row['document_category']} document '{$doc_row['document_name']}' from procurement #{$procurement_id}",
                    ['document_name' => $doc_row['document_name'], 'document_category' => $doc_row['document_category']],
                    []
                );
                $_SESSION['alert_success'] = "Document '{$doc_row['document_name']}' removed successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to remove document record.";
            }
            $del->close();
        } else {
            $_SESSION['alert_error'] = "Document not found.";
        }

        header("Location: view_procurement.php?id=" . $procurement_id);
        exit();
    }
}

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// 1. Fetch Procurement Details
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? AND status != 'draft' LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    header("Location: procurement.php");
    exit();
}

// 2. Fetch User & Bidder Profile Status
$prof_stmt = $conn->prepare("SELECT profile_id, business_name, application_status FROM bidder_profiles WHERE user_id = ? LIMIT 1");
$prof_stmt->bind_param("i", $user_id);
$prof_stmt->execute();
$user_profile = $prof_stmt->get_result()->fetch_assoc();
$prof_stmt->close();

$app_status = strtolower($user_profile['application_status'] ?? 'none');

// Bid summary for this procurement
$bid_summary_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')   AS pending,
        SUM(status = 'submitted') AS submitted,
        SUM(status = 'rejected')  AS rejected,
        SUM(status = 'opened')    AS opened
    FROM bids WHERE procurement_id = ?
");
$bid_summary_stmt->bind_param("i", $procurement_id);
$bid_summary_stmt->execute();
$bid_summary = $bid_summary_stmt->get_result()->fetch_assoc();
$bid_summary_stmt->close();

// 3. Fetch Associated Lots
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_res = $lot_stmt->get_result();
$lots = [];
$total_lots_abc = 0;
while ($l = $lots_res->fetch_assoc()) {
    $lots[] = $l;
    $total_lots_abc += (float)$l['abc'];
}
$lot_stmt->close();

// 4. Fetch Downloadable Documents (Categorized into Original and Associated)
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$docs_res = $doc_stmt->get_result();
$original_documents   = [];
$associated_documents = [];
while ($d = $docs_res->fetch_assoc()) {
    if (($d['document_category'] ?? 'original') === 'associated') {
        $associated_documents[] = $d;
    } else {
        $original_documents[] = $d;
    }
}
$doc_stmt->close();

// The existing Bidding Documents & Files section displays only original documents
$documents = $original_documents;

$p_status = strtolower($procurement['status'] ?? 'open');
$is_open  = ($p_status === 'open');

// Deadline & Urgency Calculation
$diff_days     = null;
$is_urgent     = false;
$deadline_text = 'To Be Announced';

if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
    if ($is_open && $diff_days <= 3 && $diff_days >= 0) {
        $is_urgent = true;
    }
}

// Status styles
$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#e4f5ea';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#1f7a3d';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared styles -->
    <link rel="stylesheet" href="../style.css">
    <!-- Dashboard styles -->
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Breadcrumb & Back Bar ── */
        .vp-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .vp-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #88968d;
            font-weight: 600;
        }

        .vp-breadcrumbs a {
            color: #1f7a3d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .vp-breadcrumbs a:hover {
            text-decoration: underline;
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
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            cursor: pointer;
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
        }

        /* ── Project Header Hero Banner ── */
        .vp-hero-card {
            background: linear-gradient(135deg, #06251b 0%, #0c3d2c 60%, #14593f 100%);
            border-radius: 20px;
            padding: 26px 30px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(6, 37, 27, 0.16);
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .vp-hero-card::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.15) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .vp-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .vp-hero-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hero-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: .3px;
        }

        .hero-pill.ref {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hero-pill.urgent {
            background: rgba(235, 87, 87, 0.25);
            border: 1px solid rgba(235, 87, 87, 0.5);
            color: #ff8a80;
        }

        .vp-hero-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.3;
            margin-bottom: 20px;
            letter-spacing: -0.3px;
        }

        /* Hero Key Metrics Row */
        .vp-hero-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .vp-hero-metric-item {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 12px 16px;
        }

        .vp-hero-metric-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #d1e5db;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .vp-hero-metric-lbl i {
            color: #ffc107;
        }

        .vp-hero-metric-val {
            font-size: 17px;
            font-weight: 800;
            color: #ffffff;
            font-family: 'Space Grotesk', sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .vp-hero-metric-val.gold {
            color: #ffc107;
        }

        /* ── Main Two-Column Layout (65% + 35%) ── */
        .vp-grid-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 30px;
        }

        .vp-left-col,
        .vp-right-col {
            min-width: 0;
            overflow: hidden;
        }

        @media (max-width: 1040px) {
            .vp-grid-layout {
                grid-template-columns: 1fr;
            }
        }

        /* ── Section Cards ── */
        .vp-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .vp-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .vp-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vp-card-count {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .vp-card-body {
            padding: 20px;
        }

        /* ── Key Spec Fields Grid ── */
        .spec-fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 18px;
        }

        @media (max-width: 600px) {
            .spec-fields-grid {
                grid-template-columns: 1fr;
            }
        }

        .spec-field-item {
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            padding: 12px 14px;
        }

        .spec-field-lbl {
            font-size: 10.5px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 3px;
        }

        .spec-field-val {
            font-size: 13px;
            font-weight: 700;
            color: #1a2a20;
            line-height: 1.3;
        }

        /* ── Lots List Table ── */
        .lots-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .lots-table th {
            background: #f4f8f5;
            padding: 10px 14px;
            font-size: 11px;
            font-weight: 700;
            color: #495b50;
            text-transform: uppercase;
            letter-spacing: .03em;
            border-bottom: 1px solid #eaeeec;
            text-align: left;
        }

        .lots-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f2f5f3;
            vertical-align: middle;
        }

        .lots-table tr:last-child td {
            border-bottom: none;
        }

        /* ── Timeline Activity List ── */
        .timeline-list {
            position: relative;
            padding-left: 24px;
        }

        .timeline-list::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e0e8e4;
        }

        .timeline-step {
            position: relative;
            margin-bottom: 18px;
        }

        .timeline-step:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -24px;
            top: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid #1f7a3d;
        }

        .timeline-step.urgent .timeline-dot {
            border-color: #e67e22;
        }

        .timeline-lbl {
            font-size: 12.5px;
            font-weight: 700;
            color: #1a2a20;
            margin-bottom: 2px;
        }

        .timeline-date {
            font-size: 11.5px;
            color: #728277;
        }

        /* ── Documents List ── */
        .doc-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            margin-bottom: 10px;
            gap: 12px;
        }

        .doc-item-row:last-child {
            margin-bottom: 0;
        }

        .doc-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .doc-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #eef5f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .doc-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #18261e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-dl-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #06251b;
            color: #ffc107;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            text-decoration: none;
            transition: all .15s;
            flex-shrink: 0;
        }

        .doc-dl-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
        }

        /* ── Action / Accreditation Sidebar Card ── */
        .accredit-cta-box {
            background: #f7faf8;
            border: 1.5px dashed #c0d8cb;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
        }

        .accredit-cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: #06251b;
            color: #ffc107;
            font-weight: 700;
            font-size: 13px;
            padding: 12px 18px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .15s ease;
            margin-top: 12px;
        }

        .accredit-cta-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
            transform: translateY(-1px);
        }
        /* ── Associated Documents & Modal Styles ── */
        .btn-associated-doc {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0284c7;
            color: #ffffff;
            font-size: 11.5px;
            font-weight: 700;
            padding: 6px 13px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all .15s ease;
            text-decoration: none;
        }
        .btn-associated-doc:hover {
            background: #0369a1;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
        }
        .badge-associated-pill {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            margin-left: 6px;
            vertical-align: middle;
        }
        .doc-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff0f0;
            border: 1px solid #fecaca;
            color: #ef4444;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
            transition: all .15s ease;
        }
        .doc-delete-btn:hover {
            background: #ef4444;
            color: #ffffff;
            border-color: #ef4444;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(6, 37, 27, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            transition: opacity .2s ease;
        }
        .modal-backdrop.open {
            display: flex;
            opacity: 1;
        }
        .modal-dialog-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 540px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalPopIn .2s ease;
        }
        @keyframes modalPopIn {
            from { transform: scale(0.96) translateY(8px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }
        .modal-head {
            padding: 18px 24px;
            background: #06251b;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .modal-head h3 {
            font-size: 16px;
            font-weight: 800;
            color: #ffc107;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close-btn {
            background: none;
            border: none;
            color: #d1e5db;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            transition: color .15s;
        }
        .modal-close-btn:hover {
            color: #ffffff;
        }
        .modal-body {
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 24px;
            background: #fafcfb;
            border-top: 1px solid #eaeeec;
        }
        .form-group-custom {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group-custom label {
            font-size: 12px;
            font-weight: 700;
            color: #2b3a31;
        }
        .form-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .form-input-wrap i {
            position: absolute;
            left: 12px;
            color: #88968d;
        }
        .form-input-wrap input {
            width: 100%;
            height: 40px;
            padding-left: 36px;
            padding-right: 12px;
            border: 1px solid #cfdbd4;
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            color: #06251b;
            transition: border-color .15s;
        }
        .form-input-wrap input:focus {
            border-color: #0284c7;
            outline: none;
        }
        .toast-alert {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            animation: toastIn .3s ease;
        }
        .toast-alert.success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #43a047; }
        .toast-alert.error   { background: #ffebee; color: #c62828; border-left: 4px solid #e53935; }
        @keyframes toastIn {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Procurement Details';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Navigation & Back Bar ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <span>/</span>
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'Project Details') ?></span>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Opportunities
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref">
                    <i class="bi bi-hash"></i> SLSU: <?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'SLSU-BAC') ?>
                </span>
                <span class="hero-pill" style="background:<?= $status_badge_bg ?>; color:<?= $status_badge_fg ?>;">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                </span>
                <?php if ($is_urgent): ?>
                    <span class="hero-pill urgent">
                        <i class="bi bi-alarm"></i> CLOSING IN <?= $diff_days == 0 ? 'TODAY' : ($diff_days . ' DAYS') ?>
                    </span>
                <?php endif; ?>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($can_manage_docs): ?>
                    <button type="button" class="btn-associated-doc" onclick="openAssociatedDocModal()">
                        <i class="bi bi-paperclip"></i> Associated Documents
                    </button>
                <?php endif; ?>
                <span style="font-size:12px; color:#d1e5db;">
                    <i class="bi bi-building"></i> Southern Luzon State University - BAC
                </span>
            </div>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <!-- Key Metrics Row -->
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-briefcase"></i> Procurement Mode</div>
                <div class="vp-hero-metric-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val" style="font-size:15px;"><?= $deadline_text ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-calendar-event"></i> Bid Opening Date</div>
                <div class="vp-hero-metric-val" style="font-size:15px;">
                    <?= !empty($procurement['opening_date']) ? date('M j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Scheduled' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Layout (65% + 35%) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Specifications, Lots & Documents ── -->
        <div class="vp-left-col">

            <!-- 1. General Project Details -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle" style="color:#06251b;"></i>
                        <span>Project Overview &amp; Description</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    
                    <div class="spec-fields-grid">
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">SLSU Reference No.</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b;"><?= htmlspecialchars($procurement['slsu_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-item">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-item" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b; line-height:1.4;"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-item" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val" style="font-size:16px; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#1f7a3d;">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                        </div>
                    </div>

                    <div style="font-size:11px; font-weight:700; color:#88968d; text-transform:uppercase; margin-bottom:6px;">Description</div>
                    <div style="background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; padding:16px; font-size:13px; color:#2d3a32; line-height:1.6; white-space:pre-wrap;">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed technical specification summary specified for this procurement package. Please consult the official bidding documents below.') ?>
                    </div>

                </div>
            </div>

            <!-- 2. Lots Breakdown -->
            <?php if (!empty($lots)): ?>
                <div class="vp-card">
                    <div class="vp-card-head">
                        <div class="vp-card-title">
                            <i class="bi bi-boxes" style="color:#1f7a3d;"></i>
                            <span>Project Lots &amp; Components</span>
                        </div>
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="vp-card-body" style="padding:0;">
                        <div style="overflow-x:auto;">
                            <table class="lots-table">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">Lot #</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th style="text-align:right;">Approved Budget (ABC)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td style="font-weight:700; color:#06251b;">Lot <?= htmlspecialchars($lot['lot_number']) ?></td>
                                            <td style="font-weight:600; color:#1a2a20;"><?= htmlspecialchars($lot['lot_title'] ?? $lot['title'] ?? '—') ?></td>
                                            <td style="color:#63736a; font-size:12px;"><?= htmlspecialchars($lot['description'] ?? '—') ?></td>
                                            <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                <tfoot>
                                    <tr style="background:#f9fbf9; border-top:2px solid #eaeeec; font-weight:700;">
                                        <td colspan="3" style="text-align:right; font-size:12px; color:#495b50;">Total Lots ABC:</td>
                                        <td style="text-align:right; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                            ₱<?= number_format((float)$total_lots_abc, 2) ?>
                                        </td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3. Official Downloadable Documents (Original Category Only) -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-file-earmark-arrow-down" style="color:#1565c0;"></i>
                        <span>Official Bidding Documents &amp; Notices</span>
                    </div>
                    <span class="vp-card-count"><?= count($original_documents) ?> File<?= count($original_documents) != 1 ? 's' : '' ?></span>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($original_documents)): ?>
                        <?php foreach ($original_documents as $doc): 
                            $dl_url = resolve_proc_doc_url($doc['file_path'], 'admin');
                        ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                                    <div style="min-width:0;">
                                        <div class="doc-title" title="<?= htmlspecialchars($doc['document_name']) ?>"><?= htmlspecialchars($doc['document_name']) ?></div>
                                        <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($dl_url) ?>" download class="doc-dl-btn">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                            <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                            No original bidding documents uploaded yet for this opportunity.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. Associated Documents (Associated Category Only) -->
            <div class="vp-card" style="margin-top:20px;">
                <div class="vp-card-head" style="display:flex; justify-content:space-between; align-items:center;">
                    <div class="vp-card-title">
                        <i class="bi bi-paperclip" style="color:#0284c7;"></i>
                        <span>Associated Documents</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="vp-card-count"><?= count($associated_documents) ?> File<?= count($associated_documents) != 1 ? 's' : '' ?></span>
                        <?php if ($can_manage_docs): ?>
                            <button type="button" class="btn-associated-doc" onclick="openAssociatedDocModal()">
                                <i class="bi bi-plus-circle"></i> Associated Documents
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($associated_documents)): ?>
                        <?php foreach ($associated_documents as $adoc): 
                            $adl_url = resolve_proc_doc_url($adoc['file_path'], 'admin');
                            $aext = strtolower(pathinfo($adoc['document_name'], PATHINFO_EXTENSION));
                            $aicon = 'bi-file-earmark-pdf';
                            if (in_array($aext, ['doc','docx'])) $aicon = 'bi-file-earmark-word';
                            elseif (in_array($aext, ['xls','xlsx'])) $aicon = 'bi-file-earmark-excel';
                            elseif (in_array($aext, ['zip','rar','7z'])) $aicon = 'bi-file-earmark-zip';
                            elseif (in_array($aext, ['jpg','jpeg','png'])) $aicon = 'bi-file-earmark-image';
                        ?>
                            <div class="doc-item-row">
                                <div class="doc-left">
                                    <div class="doc-icon"><i class="bi <?= $aicon ?>" style="color:#0284c7;"></i></div>
                                    <div style="min-width:0;">
                                        <div class="doc-title" title="<?= htmlspecialchars($adoc['document_name']) ?>">
                                            <?= htmlspecialchars($adoc['document_name']) ?>
                                            <span class="badge-associated-pill">Associated</span>
                                        </div>
                                        <div style="font-size:11px; color:#88968d;">Uploaded: <?= date('M j, Y · g:i A', strtotime($adoc['uploaded_at'])) ?></div>
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <a href="<?= htmlspecialchars($adl_url) ?>" download class="doc-dl-btn" style="background:#0284c7; color:#ffffff;">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <?php if ($can_manage_docs): ?>
                                        <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to delete this associated document?');">
                                            <input type="hidden" name="action_delete_associated_doc" value="1">
                                            <input type="hidden" name="doc_id" value="<?= (int)$adoc['id'] ?>">
                                            <button type="submit" class="doc-delete-btn" title="Delete Associated Document">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding:18px 0; color:#88968d; font-size:12.5px;">
                            <i class="bi bi-folder-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                            No associated documents (bid bulletins, addenda, notices) uploaded yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Accreditation CTA & Key Timelines ── -->
        <div class="vp-right-col">

            <!-- 1. Bid Summary -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-inbox-fill" style="color:#06251b;"></i>
                        <span>Bid Summary</span>
                    </div>
                    <a href="bid_submissions.php?procurement_id=<?= $procurement_id ?>" style="font-size:11px; font-weight:700; color:#1f7a3d; background:#eef7f1; padding:4px 10px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; transition:all .15s;" onmouseover="this.style.background='#06251b';this.style.color='#ffc107';" onmouseout="this.style.background='#eef7f1';this.style.color='#1f7a3d';">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="vp-card-body">
                    <?php
                    $total     = (int)($bid_summary['total']     ?? 0);
                    $pending   = (int)($bid_summary['pending']   ?? 0);
                    $submitted = (int)($bid_summary['submitted'] ?? 0);
                    $rejected  = (int)($bid_summary['rejected']  ?? 0);
                    $opened    = (int)($bid_summary['opened']    ?? 0);
                    ?>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
                        <div style="background:#f7faf8; border:1px solid #edf1ee; border-radius:12px; padding:12px 14px; text-align:center;">
                            <div style="font-size:22px; font-weight:800; color:#06251b; font-family:'Space Grotesk',sans-serif;"><?= $total ?></div>
                            <div style="font-size:10px; font-weight:700; color:#88968d; text-transform:uppercase; letter-spacing:.3px; margin-top:2px;">Total Bids</div>
                        </div>
                        <div style="background:#fffbf0; border:1px solid #fae099; border-radius:12px; padding:12px 14px; text-align:center;">
                            <div style="font-size:22px; font-weight:800; color:#e67e22; font-family:'Space Grotesk',sans-serif;"><?= $pending ?></div>
                            <div style="font-size:10px; font-weight:700; color:#88968d; text-transform:uppercase; letter-spacing:.3px; margin-top:2px;">Pending</div>
                        </div>
                        <div style="background:#f0f7ff; border:1px solid #c3daf7; border-radius:12px; padding:12px 14px; text-align:center;">
                            <div style="font-size:22px; font-weight:800; color:#2F6FED; font-family:'Space Grotesk',sans-serif;"><?= $submitted ?></div>
                            <div style="font-size:10px; font-weight:700; color:#88968d; text-transform:uppercase; letter-spacing:.3px; margin-top:2px;">Submitted</div>
                        </div>
                        <div style="background:#f2faf4; border:1px solid #b7e3c4; border-radius:12px; padding:12px 14px; text-align:center;">
                            <div style="font-size:22px; font-weight:800; color:#1f7a3d; font-family:'Space Grotesk',sans-serif;"><?= $opened ?></div>
                            <div style="font-size:10px; font-weight:700; color:#88968d; text-transform:uppercase; letter-spacing:.3px; margin-top:2px;">Opened</div>
                        </div>
                    </div>
                    <?php if ($total === 0): ?>
                        <div style="text-align:center; font-size:12px; color:#88968d; padding:8px 0;">
                            <i class="bi bi-inbox" style="font-size:20px; color:#c7d2cb; display:block; margin-bottom:4px;"></i>
                            No bids received yet.
                        </div>
                    <?php else: ?>
                        <a href="bid_submissions.php?procurement_id=<?= $procurement_id ?>" style="display:flex; align-items:center; justify-content:center; gap:7px; background:#06251b; color:#ffc107; font-size:12.5px; font-weight:700; padding:10px; border-radius:10px; text-decoration:none; transition:all .15s;" onmouseover="this.style.background='#144937'" onmouseout="this.style.background='#06251b';">
                            <i class="bi bi-eye-fill"></i> Review Bid Submissions
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Bidding Timeline & Schedule -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-check" style="color:#1f7a3d;"></i>
                        <span>Schedule of Activities</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="timeline-list">
                        
                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Advertisement / Posting Date</div>
                            <div class="timeline-date"><?= date('F j, Y', strtotime($procurement['created_at'])) ?></div>
                        </div>

                        <div class="timeline-step <?= $is_urgent ? 'urgent' : '' ?>">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Submission &amp; Receipt of Bids</div>
                            <div class="timeline-date"><?= $deadline_text ?></div>
                        </div>

                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Opening of Bid Proposals</div>
                            <div class="timeline-date">
                                <?= !empty($procurement['opening_date']) ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Announced' ?>
                            </div>
                        </div>

                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-lbl">Bid Evaluation &amp; Post-Qualification</div>
                            <div class="timeline-date">Following Bid Opening Session</div>
                        </div>

                    </div>
                </div>
            </div>


        </div>

    </div>

</div>
</main>

<?php if ($can_manage_docs): ?>
<!-- ══ ASSOCIATED DOCUMENTS MODAL (Secretariat / Admin) ══ -->
<div id="associatedDocModal" class="modal-backdrop" onclick="if(event.target===this)closeAssociatedDocModal()">
    <div class="modal-dialog-box">
        <div class="modal-head">
            <h3><i class="bi bi-paperclip"></i> Associated Documents</h3>
            <button type="button" class="modal-close-btn" onclick="closeAssociatedDocModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="view_procurement.php?id=<?= $procurement_id ?>">
            <input type="hidden" name="action_upload_associated_doc" value="1">
            <div class="modal-body">
                <p style="font-size:12.5px; color:#55665a; margin:0 0 4px 0; line-height:1.5;">
                    Upload an associated document for this procurement project (e.g., Supplemental Bid Bulletin, Minutes of Pre-Bid Conference, Addendum, or Postponement Notice).
                </p>

                <div class="form-group-custom">
                    <label for="docNameInput">Document Name / Title <span style="color:#e53e3e;">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-tag"></i>
                        <input type="text" id="docNameInput" name="document_name" required placeholder="e.g., Supplemental Bid Bulletin No. 1">
                    </div>
                </div>

                <div class="form-group-custom">
                    <label>Document File <span style="color:#e53e3e;">*</span></label>
                    <label for="associatedFileInput" id="fileDropZone" style="display:flex; flex-direction:column; align-items:center; gap:8px; padding:22px 16px; border:2px dashed #cfdbd4; border-radius:12px; background:#fafcfb; cursor:pointer; transition:all .2s; text-align:center;" onmouseover="this.style.borderColor='#0284c7'" onmouseout="this.style.borderColor='#cfdbd4'">
                        <i class="bi bi-cloud-arrow-up" style="font-size:28px; color:#0284c7;"></i>
                        <span id="fileChosenLabel" style="font-size:13px; font-weight:600; color:#06251b;">Click to select file (PDF, DOCX, XLSX, etc.)</span>
                        <span style="font-size:11px; color:#88968d;">Supported: PDF, Word, Excel, Images, ZIP — Max 50MB</span>
                        <input type="file" id="associatedFileInput" name="associated_document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.zip,.rar,.7z,.png,.jpg,.jpeg" style="display:none;" onchange="handleAssociatedFileSelect(this)">
                    </label>
                </div>

                <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:10px 14px; display:flex; align-items:flex-start; gap:8px;">
                    <i class="bi bi-info-circle-fill" style="color:#0284c7; font-size:15px; margin-top:2px;"></i>
                    <span style="font-size:11.5px; color:#0369a1; line-height:1.4;">
                        This document will be saved with category: <strong>associated</strong>. It will appear under the Associated Documents panel for bidders and public visitors.
                    </span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" onclick="closeAssociatedDocModal()" class="vp-back-link" style="border:1px solid #eaeeec; cursor:pointer; background:#fff;">Cancel</button>
                <button type="submit" class="btn-associated-doc" style="padding:9px 20px; font-size:13px;">
                    <i class="bi bi-cloud-arrow-up"></i> Upload Document
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Toast alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill" style="color:#2ecc71; font-size:16px;"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill" style="color:#e74c3c; font-size:16px;"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
function openAssociatedDocModal() {
    const modal = document.getElementById('associatedDocModal');
    if (modal) modal.classList.add('open');
}
function closeAssociatedDocModal() {
    const modal = document.getElementById('associatedDocModal');
    if (modal) modal.classList.remove('open');
}
function handleAssociatedFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        document.getElementById('fileChosenLabel').textContent = file.name + ' (' + sizeMb + ' MB)';
        const docNameInput = document.getElementById('docNameInput');
        if (docNameInput && !docNameInput.value.trim()) {
            const nameWithoutExt = file.name.replace(/\.[^/.]+$/, "");
            docNameInput.value = nameWithoutExt;
        }
    }
}
// Toast auto-hide
const toast = document.getElementById('toastAlert');
if (toast) {
    setTimeout(() => {
        toast.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}
</script>

</body>
</html>
