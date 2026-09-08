<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);

if ($procurement_id === 0) {
    header("Location: procurement.php");
    exit();
}

// 1. Fetch Procurement Details
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$procurement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$procurement) {
    header("Location: procurement.php?error=not_found");
    exit();
}

// 2. Fetch Associated Lots
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

// 3. Fetch Uploaded Documents
$doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param("i", $procurement_id);
$doc_stmt->execute();
$docs_res = $doc_stmt->get_result();
$documents = [];
while ($d = $docs_res->fetch_assoc()) {
    $documents[] = $d;
}
$doc_stmt->close();

// 4. Handle Document Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc'])) {
    $doc_id = intval($_POST['doc_id']);
    $d = $conn->prepare("SELECT file_path, document_name FROM procurement_documents WHERE id = ? AND procurement_id = ?");
    $d->bind_param("ii", $doc_id, $procurement_id);
    $d->execute();
    $doc_row = $d->get_result()->fetch_assoc();
    $d->close();

    if ($doc_row) {
        if (file_exists($doc_row['file_path'])) {
            @unlink($doc_row['file_path']);
        }
        $del = $conn->prepare("DELETE FROM procurement_documents WHERE id = ?");
        $del->bind_param("i", $doc_id);
        if ($del->execute()) {
            $doc_name = $doc_row['document_name'] ?? "#$doc_id";
            audit_log(
                $conn,
                'PROCUREMENT_DOCUMENT_DELETED',
                'procurements',
                $procurement_id,
                "Deleted procurement document: {$doc_name}",
                ['document_id' => $doc_id, 'document_name' => $doc_name],
                null
            );
            $_SESSION['alert_success'] = "Document removed successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to remove document record.";
        }
        $del->close();
    } else {
        $_SESSION['alert_error'] = "Document not found.";
    }
    header("Location: review_procurement.php?id=" . $procurement_id);
    exit();
}

// 5. Handle Edit Procurement (with optional new file uploads)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_procurement'])) {
    $title          = trim($_POST['title'] ?? '');
    $philgeps       = trim($_POST['philgeps_ref_no'] ?? '');
    $mode           = trim($_POST['procurement_mode'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $abc            = floatval($_POST['abc'] ?? 0);
    $posting_date   = $_POST['posting_date']  ?: null;
    $closing_date   = $_POST['closing_date']  ?: null;
    $opening_date   = $_POST['opening_date']  ?: null;

    $upd = $conn->prepare("
        UPDATE procurements
        SET title = ?, philgeps_ref_no = ?, procurement_mode = ?,
            description = ?, abc = ?,
            posting_date = ?, closing_date = ?, opening_date = ?
        WHERE id = ?
    ");
    $upd->bind_param("ssssdsssi",
        $title, $philgeps, $mode,
        $description, $abc,
        $posting_date, $closing_date, $opening_date,
        $procurement_id
    );

    if ($upd->execute()) {
        $uploaded_new_docs = [];
        // Handle new file uploads
        if (!empty($_FILES['new_documents']['name'][0])) {
            $upload_dir = "../uploads/procurements/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $ins = $conn->prepare("INSERT INTO procurement_documents (procurement_id, document_name, file_path) VALUES (?, ?, ?)");
            foreach ($_FILES['new_documents']['tmp_name'] as $i => $tmp) {
                if ($_FILES['new_documents']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $orig   = basename($_FILES['new_documents']['name'][$i]);
                $unique = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
                $target = $upload_dir . $unique;
                if (move_uploaded_file($tmp, $target)) {
                    $ins->bind_param("iss", $procurement_id, $orig, $target);
                    $ins->execute();
                    $uploaded_new_docs[] = $orig;
                }
            }
            $ins->close();
        }

        // Audit log procurement update
        audit_log(
            $conn,
            'PROCUREMENT_UPDATED',
            'procurements',
            $procurement_id,
            "Updated procurement project: {$title}",
            [
                'title' => $procurement['title'],
                'philgeps_ref_no' => $procurement['philgeps_ref_no'],
                'procurement_mode' => $procurement['procurement_mode'],
                'description' => $procurement['description'],
                'abc' => (float)$procurement['abc'],
                'posting_date' => $procurement['posting_date'],
                'closing_date' => $procurement['closing_date'],
                'opening_date' => $procurement['opening_date']
            ],
            [
                'title' => $title,
                'philgeps_ref_no' => $philgeps,
                'procurement_mode' => $mode,
                'description' => $description,
                'abc' => $abc,
                'posting_date' => $posting_date,
                'closing_date' => $closing_date,
                'opening_date' => $opening_date,
                'new_documents' => $uploaded_new_docs
            ]
        );

        $_SESSION['alert_success'] = "Procurement updated successfully.";
    } else {
        $_SESSION['alert_error'] = "Failed to update procurement.";
    }
    $upd->close();
    header("Location: review_procurement.php?id=" . $procurement_id);
    exit();
}

// 6. Handle Final Publish Trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_publish'])) {
    if (empty($lots)) {
        $_SESSION['alert_error'] = "Cannot publish a procurement with zero lots. Please add at least one lot first.";
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }

    $pub_stmt = $conn->prepare("UPDATE procurements SET status = 'open' WHERE id = ?");
    $pub_stmt->bind_param("i", $procurement_id);

    if ($pub_stmt->execute()) {
        $pub_stmt->close();

        // Audit log procurement publish
        audit_log(
            $conn,
            'PROCUREMENT_STATUS_CHANGED',
            'procurements',
            $procurement_id,
            "Published procurement project: {$procurement['title']} (draft -> open)",
            ['status' => $procurement['status'] ?? 'draft'],
            ['status' => 'open']
        );

        $_SESSION['alert_success'] = "Procurement has been successfully published and is now open for bidding!";
        header("Location: procurement.php");
        exit();
    } else {
        $pub_stmt->close();
        $_SESSION['alert_error'] = "Failed to update procurement status.";
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }
}

// Status & Deadline Computations
$p_status = strtolower($procurement['status'] ?? 'draft');
$is_open  = ($p_status === 'open');

$diff_days     = null;
$deadline_text = 'To Be Announced';

if (!empty($procurement['closing_date'])) {
    $dl_time   = strtotime($procurement['closing_date']);
    $diff_days = ceil(($dl_time - time()) / 86400);
    $deadline_text = date('F j, Y · g:i A', $dl_time);
}

$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#eef0ed';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#6c776e';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review &amp; Publish: <?= htmlspecialchars($procurement['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* ── Base Reset & Container ── */
        * {
            box-sizing: border-box;
        }

        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ── Breadcrumb & Top Navigation Bar ── */
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
            transition: color .15s;
        }

        .vp-breadcrumbs a:hover {
            text-decoration: underline;
            color: #06251b;
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
            cursor: pointer;
            transition: all .15s;
        }

        .hero-pill.ref:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .hero-pill.mode {
            background: rgba(255, 193, 7, 0.2);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
        }

        .hero-pill.draft {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #d1e5db;
        }

        .hero-pill.open {
            background: rgba(33, 150, 83, 0.25);
            border: 1px solid rgba(33, 150, 83, 0.5);
            color: #81c784;
        }

        .vp-hero-title {
            font-size: 23px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.35;
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
            font-size: 16.5px;
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

        /* ── Main Two-Column Layout (1.7fr + 1fr) ── */
        .vp-grid-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            align-items: start;
            margin-bottom: 30px;
            min-width: 0;
            max-width: 100%;
        }

        .vp-left-col,
        .vp-right-col {
            min-width: 0;
            max-width: 100%;
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
            gap: 10px;
            flex-wrap: wrap;
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

        .spec-field-box {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 11px 14px;
        }

        .spec-field-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }

        .spec-field-val {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
        }

        .desc-text-box {
            background: #fbfdfc;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 16px;
            font-size: 12.5px;
            color: #3b4d42;
            line-height: 1.65;
            white-space: pre-line;
        }

        /* ── Lots Table ── */
        .lots-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            text-align: left;
        }

        .lots-data-table thead th {
            background: #fafcfb;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #55665a;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-bottom: 1px solid #edf1ee;
        }

        .lots-data-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f4f7f5;
            vertical-align: middle;
        }

        .lots-data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .lots-data-table tfoot td {
            background: #f7faf8;
            padding: 12px 16px;
            border-top: 2px solid #eaeeec;
            font-weight: 800;
            color: #06251b;
        }

        .lot-badge {
            background: #eef7f1;
            color: #1f7a3d;
            font-weight: 800;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        /* ── Documents Cards Grid ── */
        .docs-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }

        .doc-item-card {
            background: #fafcfb;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: all .15s;
        }

        .doc-item-card:hover {
            background: #ffffff;
            border-color: #1f7a3d;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.05);
        }

        .doc-item-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .doc-file-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .doc-file-info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .doc-file-name {
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-file-sub {
            font-size: 10.5px;
            color: #88968d;
            margin-top: 1px;
        }

        .doc-view-btn {
            background: #06251b;
            color: #ffc107;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            transition: all .15s;
        }

        .doc-view-btn:hover {
            background: #144937;
            color: #ffffff;
        }

        /* ── Right Column: Milestones Timeline ── */
        .vp-timeline {
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            padding-left: 28px;
        }

        .vp-timeline::before {
            content: '';
            position: absolute;
            top: 6px;
            bottom: 6px;
            left: 10px;
            width: 2px;
            background: #eaeeec;
        }

        .timeline-item {
            position: relative;
        }

        .timeline-dot {
            position: absolute;
            left: -28px;
            top: 3px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #ffffff;
            border: 2.5px solid #d0dcd5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #88968d;
            transition: all .2s;
        }

        .timeline-item.active .timeline-dot {
            background: #ffc107;
            border-color: #e0a800;
            color: #06251b;
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.25);
        }

        .timeline-item.passed .timeline-dot {
            background: #1f7a3d;
            border-color: #1f7a3d;
            color: #ffffff;
        }

        .timeline-content-box {
            background: #f7faf8;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 10px 14px;
        }

        .timeline-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 2px;
        }

        .timeline-title {
            font-size: 12px;
            font-weight: 700;
            color: #06251b;
        }

        .timeline-badge {
            font-size: 9.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .timeline-badge.past {
            background: #eaeeec;
            color: #63736a;
        }

        .timeline-badge.current {
            background: #fff4d9;
            color: #b78103;
        }

        .timeline-badge.future {
            background: #eef7f1;
            color: #1f7a3d;
        }

        .timeline-date {
            font-size: 11.5px;
            color: #55665a;
            font-weight: 600;
        }

        /* ── Action Buttons & Right Sidebar ── */
        .action-card-body {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-publish-main {
            width: 100%;
            background: #1f7a3d;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 800;
            padding: 13px 18px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(31, 122, 61, 0.22);
            transition: all .2s;
        }

        .btn-publish-main:hover {
            background: #16602f;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(31, 122, 61, 0.28);
        }

        .btn-publish-main:disabled {
            background: #a3c9b1;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-action-outline {
            width: 100%;
            background: #ffffff;
            color: #06251b;
            border: 1.5px solid #dce4e0;
            font-size: 13px;
            font-weight: 700;
            padding: 11px 16px;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all .15s;
        }

        .btn-action-outline:hover {
            background: #f0f7f2;
            border-color: #1f7a3d;
            color: #1f7a3d;
        }

        /* ── Modals ── */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(6, 37, 27, 0.55);
            backdrop-filter: blur(4px);
            z-index: 9999;
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
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            animation: modalPopIn .2s ease;
        }

        @keyframes modalPopIn {
            from { transform: scale(0.96) translateY(8px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .modal-head {
            padding: 20px 24px;
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
            font-size: 18px;
            cursor: pointer;
            transition: color .15s;
        }

        .modal-close-btn:hover {
            color: #ffffff;
        }

        .modal-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px;
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
            font-size: 14px;
            pointer-events: none;
        }

        .form-input-wrap input,
        .form-input-wrap select,
        .form-input-wrap textarea {
            width: 100%;
            background: #ffffff;
            border: 1.5px solid #dce4e0;
            border-radius: 10px;
            padding: 10px 14px 10px 36px;
            font-size: 13px;
            font-family: inherit;
            color: #06251b;
            outline: none;
            transition: all .2s;
        }

        .form-input-wrap input:focus,
        .form-input-wrap select:focus,
        .form-input-wrap textarea:focus {
            border-color: #1f7a3d;
            box-shadow: 0 0 0 3px rgba(31, 122, 61, 0.12);
        }

        .modal-doc-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 12px;
            background: #f7faf8;
            border: 1px solid #eaeeec;
            border-radius: 8px;
        }

        /* Toast Alerts */
        .toast-alert {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 10000;
            background: #06251b;
            color: #ffffff;
            padding: 14px 20px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            animation: slideInToast .3s ease;
        }

        .toast-alert.success {
            border-left: 4px solid #2ecc71;
        }

        .toast-alert.error {
            border-left: 4px solid #e74c3c;
        }

        @keyframes slideInToast {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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

    <!-- ── Top Navigation Bar & Breadcrumbs ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span style="color:#06251b;">Review &amp; Publish</span>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Procurements
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <?php if (!empty($procurement['philgeps_ref_no'])): ?>
                    <span class="hero-pill ref" onclick="copyPhilgeps('<?= htmlspecialchars($procurement['philgeps_ref_no']) ?>')" title="Click to copy Reference No.">
                        <i class="bi bi-hash"></i> REF: <?= htmlspecialchars($procurement['philgeps_ref_no']) ?>
                        <i class="bi bi-copy" style="font-size:10px; opacity:0.8;"></i>
                    </span>
                <?php endif; ?>
                
                <span class="hero-pill mode">
                    <i class="bi bi-sliders"></i> <?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?>
                </span>

                <span class="hero-pill <?= $p_status ?>">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                </span>
            </div>

            <span style="font-size:12px; color:#d1e5db;">
                <i class="bi bi-building"></i> Southern Luzon State University
            </span>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($procurement['title']) ?></h1>

        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-currency-exchange"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-boxes"></i> Associated Lots</div>
                <div class="vp-hero-metric-val"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-file-earmark-text"></i> Documents Attached</div>
                <div class="vp-hero-metric-val"><?= count($documents) ?> File<?= count($documents) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val" style="font-size:13px; font-weight:700;">
                    <?= !empty($procurement['closing_date']) ? date('M j, Y · g:i A', strtotime($procurement['closing_date'])) : 'Not Set' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Specifications, Lots & Documents ── -->
        <div class="vp-left-col">

            <!-- 1. Project Overview & Description -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle" style="color:#06251b;"></i>
                        <span>Project Overview &amp; Description</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    
                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">PhilGEPS Reference No.</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['philgeps_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($procurement['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-box" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b; line-height:1.4;"><?= htmlspecialchars($procurement['title']) ?></div>
                        </div>
                        <div class="spec-field-box" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val" style="font-size:16px; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#1f7a3d;">₱<?= number_format((float)$procurement['abc'], 2) ?></div>
                        </div>
                    </div>

                    <div style="font-size:11px; font-weight:700; color:#88968d; text-transform:uppercase; margin-bottom:6px;">Description / Scope of Work</div>
                    <div class="desc-text-box">
<?= htmlspecialchars($procurement['description'] ?: 'No detailed technical specification summary specified for this procurement package.') ?>
                    </div>

                </div>
            </div>

            <!-- 2. Lots Breakdown -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-boxes" style="color:#1f7a3d;"></i>
                        <span>Project Lots &amp; Components</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></span>
                        <a href="manage_lots.php?id=<?= $procurement_id ?>" class="vp-back-link" style="padding:4px 10px; font-size:11px;">
                            <i class="bi bi-pencil-square"></i> Manage Lots
                        </a>
                    </div>
                </div>
                <div class="vp-card-body" style="padding:0;">
                    <?php if (!empty($lots)): ?>
                        <div style="overflow-x:auto;">
                            <table class="lots-data-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Lot No.</th>
                                        <th>Lot Title</th>
                                        <th>Description</th>
                                        <th style="text-align:right; width:160px;">ABC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lots as $lot): ?>
                                        <tr>
                                            <td><span class="lot-badge">Lot <?= htmlspecialchars($lot['lot_number']) ?></span></td>
                                            <td style="font-weight:700; color:#06251b;"><?= htmlspecialchars($lot['lot_title']) ?></td>
                                            <td style="color:#6c776e; font-size:12px;"><?= htmlspecialchars($lot['description'] ?: '—') ?></td>
                                            <td style="text-align:right; font-weight:700; font-family:'Space Grotesk',sans-serif; color:#06251b;">
                                                ₱<?= number_format((float)$lot['abc'], 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if (count($lots) > 1): ?>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" style="text-align:right;">Total Lots Approved Budget (ABC)</td>
                                            <td style="text-align:right; font-family:'Space Grotesk',sans-serif; font-size:14px; color:#1f7a3d;">
                                                ₱<?= number_format($total_lots_abc, 2) ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding:24px; text-align:center; background:#fffcf2; border:1px dashed #f5c6c6; border-radius:12px; margin:16px;">
                            <i class="bi bi-exclamation-triangle" style="font-size:24px; color:#e67e22;"></i>
                            <div style="font-size:13px; font-weight:700; color:#06251b; margin:6px 0 2px;">No Lots Configured Yet</div>
                            <div style="font-size:11.5px; color:#6c776e; margin-bottom:12px;">You must add at least one lot before this procurement can be published.</div>
                            <a href="manage_lots.php?id=<?= $procurement_id ?>" class="btn-publish-main" style="display:inline-flex; width:auto; padding:8px 18px; font-size:12px;">
                                <i class="bi bi-plus-circle"></i> Add Project Lots Now
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Downloadable Bidding Documents -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-folder2" style="color:#06251b;"></i>
                        <span>Bidding Documents &amp; Attachments</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="vp-card-count"><?= count($documents) ?> File<?= count($documents) !== 1 ? 's' : '' ?></span>
                        <button type="button" onclick="openEditModal()" class="vp-back-link" style="padding:4px 10px; font-size:11px; cursor:pointer;">
                            <i class="bi bi-cloud-upload"></i> Upload Files
                        </button>
                    </div>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($documents)): ?>
                        <div class="docs-cards-grid">
                            <?php foreach ($documents as $doc): ?>
                                <div class="doc-item-card">
                                    <div class="doc-item-left">
                                        <div class="doc-file-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                                        <div class="doc-file-info">
                                            <div class="doc-file-name" title="<?= htmlspecialchars($doc['document_name']) ?>">
                                                <?= htmlspecialchars($doc['document_name']) ?>
                                            </div>
                                            <div class="doc-file-sub">
                                                <?= date('M j, Y · g:i A', strtotime($doc['uploaded_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="doc-view-btn">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding:20px; text-align:center; color:#88968d; font-size:12.5px;">
                            <i class="bi bi-file-earmark-x" style="font-size:24px; display:block; margin-bottom:4px;"></i>
                            No official bidding documents uploaded yet. You can attach documents via the Edit Procurement modal.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Actions, Schedule Timeline & Admin Info ── -->
        <div class="vp-right-col">

            <!-- Card 1: Review & Publishing Action Center -->
            <div class="vp-card">
                <div class="vp-card-head" style="background:#06251b; color:#ffffff;">
                    <div class="vp-card-title" style="color:#ffc107;">
                        <i class="bi bi-send-check"></i>
                        <span>Publishing &amp; Action Center</span>
                    </div>
                    <span style="font-size:10.5px; font-weight:700; background:rgba(255,255,255,0.15); color:#ffffff; padding:2px 8px; border-radius:10px;">
                        <?= strtoupper($p_status) ?>
                    </span>
                </div>
                <div class="vp-card-body">
                    <div class="action-card-body">
                        
                        <div style="font-size:12px; color:#55665a; line-height:1.5; background:#f7faf8; border:1px solid #edf1ee; border-radius:12px; padding:12px 14px;">
                            <?php if ($p_status === 'draft'): ?>
                                <i class="bi bi-info-circle-fill" style="color:#1f7a3d;"></i>
                                <strong>Draft Mode:</strong> This procurement is not visible to public users or bidders. Verify all details, lots, and documents before publishing.
                            <?php else: ?>
                                <i class="bi bi-check-circle-fill" style="color:#1f7a3d;"></i>
                                <strong>Active:</strong> This procurement is published and publicly visible.
                            <?php endif; ?>
                        </div>

                        <?php if ($p_status === 'draft'): ?>
                            <form method="POST" action="" id="publishForm" style="margin:0;">
                                <input type="hidden" name="confirm_publish" value="1">
                                <button type="button" class="btn-publish-main" onclick="openPublishConfirm()" <?= empty($lots) ? 'disabled title="Add at least 1 lot first"' : '' ?>>
                                    <i class="bi bi-send-check-fill"></i> Confirm &amp; Publish Project
                                </button>
                            </form>
                        <?php endif; ?>

                        <button type="button" onclick="openEditModal()" class="btn-action-outline">
                            <i class="bi bi-pencil-square"></i> Edit Procurement Details
                        </button>

                        <a href="manage_lots.php?id=<?= $procurement_id ?>" class="btn-action-outline">
                            <i class="bi bi-boxes"></i> Manage / Edit Lots
                        </a>

                    </div>
                </div>
            </div>

            <!-- Card 2: Procurement Milestones & Schedule Timeline -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-calendar-event" style="color:#1f7a3d;"></i>
                        <span>Procurement Milestones</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="vp-timeline">
                        
                        <!-- 1. Posting Date -->
                        <?php
                            $has_posted = !empty($procurement['posting_date']);
                            $post_passed = $has_posted && (strtotime($procurement['posting_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $post_passed ? 'passed' : ($has_posted ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $post_passed ? 'check-lg' : 'megaphone' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">1. Posting Date</span>
                                    <span class="timeline-badge <?= $post_passed ? 'past' : 'future' ?>"><?= $post_passed ? 'Published' : 'Scheduled' ?></span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_posted ? date('F j, Y', strtotime($procurement['posting_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Submission Deadline -->
                        <?php
                            $has_closing = !empty($procurement['closing_date']);
                            $close_passed = $has_closing && (strtotime($procurement['closing_date']) <= time());
                            $close_current = $has_closing && !$close_passed && $is_open;
                        ?>
                        <div class="timeline-item <?= $close_passed ? 'passed' : ($close_current ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $close_passed ? 'check-lg' : 'clock-history' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">2. Submission Deadline</span>
                                    <span class="timeline-badge <?= $close_passed ? 'past' : ($close_current ? 'current' : 'future') ?>">
                                        <?= $close_passed ? 'Closed' : ($close_current ? 'Active' : 'Pending') ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_closing ? date('F j, Y · g:i A', strtotime($procurement['closing_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Bid Opening Date -->
                        <?php
                            $has_opening = !empty($procurement['opening_date']);
                            $open_passed = $has_opening && (strtotime($procurement['opening_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $open_passed ? 'passed' : ($has_opening ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $open_passed ? 'check-lg' : 'envelope-open' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">3. Bid Opening &amp; Evaluation</span>
                                    <span class="timeline-badge <?= $open_passed ? 'past' : 'future' ?>">
                                        <?= $open_passed ? 'Completed' : 'Upcoming' ?>
                                    </span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_opening ? date('F j, Y · g:i A', strtotime($procurement['opening_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
</main>

<!-- ========================= -->
<!-- EDIT PROCUREMENT MODAL    -->
<!-- ========================= -->
<div id="editProcModal" class="modal-backdrop" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-dialog-box">

        <!-- Modal Header -->
        <div class="modal-head">
            <h3><i class="bi bi-pencil-square"></i> Edit Procurement Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal Form -->
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="edit_procurement" value="1">
            
            <div class="modal-body">

                <!-- Title -->
                <div class="form-group-custom">
                    <label>Project Title <span style="color:#e53935;">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-folder2"></i>
                        <input type="text" name="title" value="<?= htmlspecialchars($procurement['title']) ?>" required placeholder="e.g. Supply and Delivery of Office Equipment">
                    </div>
                </div>

                <!-- PhilGEPS + Mode -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                    <div class="form-group-custom">
                        <label>PhilGEPS Reference No.</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-hash"></i>
                            <input type="text" name="philgeps_ref_no" value="<?= htmlspecialchars($procurement['philgeps_ref_no'] ?? '') ?>" placeholder="e.g. 10928374">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Procurement Mode</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-sliders"></i>
                            <select name="procurement_mode">
                                <?php
                                $modes = ['Public Bidding', 'Competitive Bidding', 'Small Value Procurement', 'Shopping', 'Negotiated Procurement', 'Direct Contracting', 'Limited Source Bidding', 'Repeat Order'];
                                foreach ($modes as $m): ?>
                                    <option value="<?= $m ?>" <?= $procurement['procurement_mode'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ABC -->
                <div class="form-group-custom">
                    <label>Approved Budget for the Contract (ABC) <span style="color:#e53935;">*</span></label>
                    <div class="form-input-wrap">
                        <i class="bi bi-currency-exchange"></i>
                        <input type="number" name="abc" step="0.01" min="0" value="<?= htmlspecialchars($procurement['abc']) ?>" required placeholder="0.00">
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group-custom">
                    <label>Description / Technical Specifications</label>
                    <div class="form-input-wrap">
                        <textarea name="description" rows="3" style="padding-left:14px;" placeholder="Brief description and technical scope..."><?= htmlspecialchars($procurement['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Dates Grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                    <div class="form-group-custom">
                        <label>Posting Date</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar"></i>
                            <input type="date" name="posting_date" value="<?= htmlspecialchars($procurement['posting_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Submission Deadline</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar-x"></i>
                            <input type="datetime-local" name="closing_date" value="<?= $procurement['closing_date'] ? date('Y-m-d\TH:i', strtotime($procurement['closing_date'])) : '' ?>">
                        </div>
                    </div>
                    <div class="form-group-custom">
                        <label>Bid Opening Date</label>
                        <div class="form-input-wrap">
                            <i class="bi bi-calendar-check"></i>
                            <input type="datetime-local" name="opening_date" value="<?= $procurement['opening_date'] ? date('Y-m-d\TH:i', strtotime($procurement['opening_date'])) : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Existing Documents Section -->
                <div style="border-top:1px solid #edf1ee; padding-top:14px;">
                    <label style="font-size:12px; font-weight:700; color:#06251b; display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                        <i class="bi bi-paperclip"></i> Existing Attached Documents (<?= count($documents) ?>)
                    </label>

                    <?php if (!empty($documents)): ?>
                        <div style="display:flex; flex-direction:column; gap:6px;" id="existingDocsList">
                            <?php foreach ($documents as $md): ?>
                                <div class="modal-doc-row" id="doc-row-<?= $md['id'] ?>">
                                    <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:0;">
                                        <i class="bi bi-file-earmark-text" style="color:#1f7a3d; font-size:15px; flex-shrink:0;"></i>
                                        <a href="<?= htmlspecialchars($md['file_path']) ?>" target="_blank" style="font-size:12px; font-weight:600; color:#06251b; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?= htmlspecialchars($md['document_name']) ?>
                                        </a>
                                    </div>
                                    <button type="button" onclick="removeDoc(<?= $md['id'] ?>)" style="background:#fff0f0; border:1px solid #f5c6c6; color:#c23b3b; border-radius:6px; padding:3px 8px; font-size:11px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:4px; flex-shrink:0;">
                                        <i class="bi bi-trash3"></i> Remove
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:11.5px; color:#88968d; padding:6px 0;">No documents uploaded yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Upload New Files -->
                <div>
                    <label style="font-size:12px; font-weight:700; color:#06251b; display:block; margin-bottom:6px;">Upload Additional Documents</label>
                    <!-- Hidden accumulator — this is what actually gets submitted -->
                    <input type="file" id="newDocsReal" name="new_documents[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" style="display:none;">
                    <!-- Visible trigger -->
                    <label for="newDocsPicker" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px; border:2px dashed #cfdbd4; border-radius:12px; background:#fafcfb; cursor:pointer; transition:all .2s;" onmouseover="this.style.borderColor='#1f7a3d'" onmouseout="this.style.borderColor='#cfdbd4'">
                        <i class="bi bi-cloud-arrow-up" style="font-size:24px; color:#1f7a3d;"></i>
                        <span style="font-size:12.5px; font-weight:600; color:#06251b;">Click to browse files (PDF, DOCX, XLSX, PNG, JPG)</span>
                        <span style="font-size:11px; color:#88968d;">Multiple files — pick as many times as needed</span>
                        <input type="file" id="newDocsPicker" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" style="display:none;" onchange="stackNewDocs(this)">
                    </label>
                    <div id="newFilePreview" style="margin-top:8px; display:flex; flex-direction:column; gap:5px;"></div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-foot">
                <button type="button" onclick="closeEditModal()" class="vp-back-link" style="border:1px solid #eaeeec; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn-publish-main" style="width:auto; padding:9px 20px; font-size:13px;">
                    <i class="bi bi-check2"></i> Save Changes
                </button>
            </div>

        </form>

    </div>
</div>

<!-- ========================= -->
<!-- PUBLISH CONFIRM MODAL     -->
<!-- ========================= -->
<div id="publishConfirmModal" class="modal-backdrop" onclick="if(event.target===this)closePublishConfirm()">
    <div class="modal-dialog-box" style="max-width:440px; text-align:center; padding:28px;">
        
        <div style="width:54px; height:54px; border-radius:50%; background:#e8f5e9; color:#1f7a3d; font-size:24px; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <i class="bi bi-send-check-fill"></i>
        </div>

        <h3 style="font-size:18px; font-weight:800; color:#06251b; margin:0 0 6px;">Publish Procurement?</h3>
        <p style="font-size:12.5px; color:#6c776e; line-height:1.5; margin:0 0 16px;">
            This procurement will become active and publicly visible to all accredited bidders and observers.
        </p>

        <div style="background:#fafcfb; border:1px solid #eaeeec; border-radius:10px; padding:10px 14px; font-size:12px; font-weight:700; color:#06251b; margin-bottom:20px; word-break:break-word;">
            <?= htmlspecialchars($procurement['title']) ?>
        </div>

        <div style="display:flex; gap:10px; justify-content:center;">
            <button type="button" onclick="closePublishConfirm()" class="vp-back-link" style="padding:10px 20px; cursor:pointer;">
                Cancel
            </button>
            <button type="button" onclick="document.getElementById('publishForm').submit()" class="btn-publish-main" style="width:auto; padding:10px 24px;">
                <i class="bi bi-send-check-fill"></i> Yes, Publish Now
            </button>
        </div>

    </div>
</div>

<!-- Hidden delete doc form -->
<form id="deleteDocForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="delete_doc" value="1">
    <input type="hidden" id="deleteDocId" name="doc_id" value="">
</form>

<!-- Toast alert notifications -->
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
    // Toast Alert auto-hide
    const toast = document.getElementById('toastAlert');
    if (toast) {
        setTimeout(() => {
            toast.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    // Modal Triggers
    function openEditModal()  { document.getElementById('editProcModal').classList.add('open'); }
    function closeEditModal() { document.getElementById('editProcModal').classList.remove('open'); }

    function openPublishConfirm()  { document.getElementById('publishConfirmModal').classList.add('open'); }
    function closePublishConfirm() { document.getElementById('publishConfirmModal').classList.remove('open'); }

    // Remove Document Handler
    function removeDoc(docId) {
        if (!confirm('Are you sure you want to remove this document?')) return;
        document.getElementById('deleteDocId').value = docId;
        document.getElementById('deleteDocForm').submit();
    }

    // File stacking for new attachments
    function stackNewDocs(picker) {
        const real = document.getElementById('newDocsReal');
        const dt   = new DataTransfer();

        // Keep existing accumulated files
        Array.from(real.files).forEach(f => dt.items.add(f));

        // Add new picks, skip exact name duplicates
        const existing = new Set(Array.from(real.files).map(f => f.name));
        Array.from(picker.files).forEach(f => {
            if (!existing.has(f.name)) dt.items.add(f);
        });

        real.files   = dt.files;
        picker.value = '';
        renderNewDocs();
    }

    function renderNewDocs() {
        const real    = document.getElementById('newDocsReal');
        const preview = document.getElementById('newFilePreview');
        preview.innerHTML = '';

        Array.from(real.files).forEach((f, idx) => {
            const size = f.size > 1048576
                ? (f.size / 1048576).toFixed(1) + ' MB'
                : (f.size / 1024).toFixed(0) + ' KB';

            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 10px;background:#eef7f1;border:1px solid #d4ece0;border-radius:8px;font-size:11.5px;color:#06251b;';
            row.innerHTML = `
                <i class="bi bi-file-earmark-check-fill" style="color:#1f7a3d;flex-shrink:0;"></i>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
                <span style="color:#6c776e;font-size:10.5px;flex-shrink:0;">${size}</span>
                <button type="button" onclick="removeNewDoc(${idx})" style="background:none;border:none;color:#c23b3b;cursor:pointer;padding:2px 4px;font-size:15px;display:flex;align-items:center;flex-shrink:0;" title="Remove">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            `;
            preview.appendChild(row);
        });
    }

    function removeNewDoc(idx) {
        const real = document.getElementById('newDocsReal');
        const dt   = new DataTransfer();
        Array.from(real.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
        real.files = dt.files;
        renderNewDocs();
    }

    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Copy Reference Number
    function copyPhilgeps(text) {
        navigator.clipboard.writeText(text).then(() => {
            const toast = document.createElement('div');
            toast.className = 'toast-alert success';
            toast.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#2ecc71;"></i> PhilGEPS Reference No. copied to clipboard!';
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, 2500);
        });
    }
</script>

</body>
</html>
