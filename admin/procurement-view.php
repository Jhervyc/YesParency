<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

$procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);
if ($procurement_id === 0) {
    header("Location: bid_submissions.php");
    exit();
}

// ── Bid filter parameter ──────────────────────────────────────────────────────
$bid_filter = isset($_GET['bid_filter']) && in_array($_GET['bid_filter'], ['all','pending','submitted','rejected'])
              ? $_GET['bid_filter'] : 'all';

// ── Handle bid verify/reject ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_bid'])) {
    $bid_id     = intval($_POST['bid_id']);
    $new_status = $_POST['status_action'] === 'approve' ? 'submitted' : 'rejected';

    $u = $conn->prepare("UPDATE bids SET status = ? WHERE id = ?");
    $u->bind_param("si", $new_status, $bid_id);
    if ($u->execute()) {
        $_SESSION['alert_success'] = "Bid " . ($new_status === 'submitted' ? 'approved and verified' : 'marked as rejected') . " successfully.";
    } else {
        $_SESSION['alert_error'] = "Failed to update bid status.";
    }
    $u->close();
    header("Location: bid-submission-view.php?id=" . $procurement_id . ($bid_filter !== 'all' ? '&bid_filter='.$bid_filter : ''));
    exit();
}

// ── Fetch procurement ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $procurement_id);
$stmt->execute();
$proc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proc) {
    header("Location: bid_submissions.php?error=not_found");
    exit();
}

// ── Fetch lots ────────────────────────────────────────────────────────────────
$lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
$lot_stmt->bind_param("i", $procurement_id);
$lot_stmt->execute();
$lots_result = $lot_stmt->get_result();
$lots = [];
$total_lots_abc = 0;
while ($l = $lots_result->fetch_assoc()) {
    $total_lots_abc += (float)$l['abc'];
    $lots[] = $l;
}
$lot_stmt->close();

// ── Fetch bids with lots and documents ───────────────────────────────────────
if ($bid_filter !== 'all') {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname, u.profile_picture_url
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ? AND b.status = ?
        ORDER BY b.submission_date DESC
    ");
    $bid_stmt->bind_param("is", $procurement_id, $bid_filter);
} else {
    $bid_stmt = $conn->prepare("
        SELECT b.id AS bid_id, b.submission_date, b.status AS bid_status,
               u.username, u.email, u.firstname, u.lastname, u.profile_picture_url
        FROM bids b
        JOIN users u ON b.bidder_id = u.user_id
        WHERE b.procurement_id = ?
        ORDER BY b.submission_date DESC
    ");
    $bid_stmt->bind_param("i", $procurement_id);
}
$bid_stmt->execute();
$bids_result = $bid_stmt->get_result();

$bids = [];
while ($b = $bids_result->fetch_assoc()) {
    $bid_id = $b['bid_id'];

    // Applied lots
    $bl = $conn->prepare("
        SELECT l.lot_number, l.lot_title, l.abc
        FROM bid_lots bl JOIN lots l ON bl.lot_id = l.id
        WHERE bl.bid_id = ?
        ORDER BY l.lot_number ASC
    ");
    $bl->bind_param("i", $bid_id);
    $bl->execute();
    $b['lots'] = $bl->get_result()->fetch_all(MYSQLI_ASSOC);
    $bl->close();

    // Documents
    $bd = $conn->prepare("SELECT * FROM bid_documents WHERE bid_id = ? ORDER BY uploaded_at ASC");
    $bd->bind_param("i", $bid_id);
    $bd->execute();
    $b['docs'] = $bd->get_result()->fetch_all(MYSQLI_ASSOC);
    $bd->close();

    $bids[] = $b;
}
$bid_stmt->close();

// ── Bid stats (always counts across ALL bids, regardless of filter) ───────────
$stats_stmt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM bids
    WHERE procurement_id = ?
    GROUP BY status
");
$stats_stmt->bind_param("i", $procurement_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$total_bids = 0; $pending_bids = 0; $verified_bids = 0; $rejected_bids = 0;
while ($row = $stats_result->fetch_assoc()) {
    $total_bids += $row['cnt'];
    if ($row['status'] === 'pending')   $pending_bids  = $row['cnt'];
    if ($row['status'] === 'submitted') $verified_bids = $row['cnt'];
    if ($row['status'] === 'rejected')  $rejected_bids = $row['cnt'];
}
$stats_stmt->close();

// Status & Timing Details
$p_status = strtolower($proc['status'] ?? 'open');
$status_badge_bg = ['open'=>'#e4f5ea', 'draft'=>'#eef0ed', 'closed'=>'#e7eefe', 'awarded'=>'#fcf1cf', 'cancelled'=>'#ffebee'][$p_status] ?? '#e4f5ea';
$status_badge_fg = ['open'=>'#1f7a3d', 'draft'=>'#6c776e', 'closed'=>'#2F6FED', 'awarded'=>'#b78103', 'cancelled'=>'#c23b3b'][$p_status] ?? '#1f7a3d';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement: <?= htmlspecialchars($proc['title']) ?> | YesParency</title>
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
        /* ── Base Layout & Utility ── */
        * {
            box-sizing: border-box;
        }

        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ── Breadcrumb & Top Bar ── */
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

        /* ── Hero Banner ── */
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

        .hero-pill.status {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
        }

        .vp-hero-title {
            font-size: 23px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.35;
            margin-bottom: 20px;
            letter-spacing: -0.3px;
        }

        /* Hero Key Metrics */
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

        /* ── Modern Stat Ring Cards ── */
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-ring-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(16,36,26,.02);
            transition: all .2s ease;
        }

        .stat-ring-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16,36,26,.06);
            border-color: #d2ded7;
        }

        .stat-ring-wrap {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
        }

        .stat-ring-inner {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .stat-ring-info {
            min-width: 0;
        }

        .stat-ring-num {
            font-size: 20px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1.1;
        }

        .stat-ring-lbl {
            font-size: 11.5px;
            font-weight: 600;
            color: #718278;
            margin-top: 3px;
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

        /* ── Card Containers ── */
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

        /* ── Filter Tabs Bar ── */
        .bids-filter-bar {
            padding: 14px 20px 0;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            border-bottom: 1px solid #eaeeec;
            background: #ffffff;
        }

        .bid-filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 700;
            color: #63736a;
            text-decoration: none;
            border-bottom: 2.5px solid transparent;
            margin-bottom: -1px;
            transition: all .15s ease;
        }

        .bid-filter-tab:hover {
            color: #06251b;
        }

        .bid-filter-tab.active {
            color: #1f7a3d;
            border-bottom-color: #1f7a3d;
        }

        .bid-tab-count {
            font-size: 10.5px;
            padding: 1px 7px;
            border-radius: 10px;
            background: #f0f4f2;
            color: #63736a;
        }

        .bid-filter-tab.active .bid-tab-count {
            background: #eef7f1;
            color: #1f7a3d;
            font-weight: 800;
        }

        /* ── Bid Submissions List Items ── */
        .bids-container {
            display: flex;
            flex-direction: column;
        }

        .bid-item-card {
            border-bottom: 1px solid #f0f4f2;
            transition: background .15s ease;
        }

        .bid-item-card:last-child {
            border-bottom: none;
        }

        .bid-item-header {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            cursor: pointer;
            user-select: none;
            transition: background .15s ease;
        }

        .bid-item-header:hover {
            background: #f7faf8;
        }

        .bidder-avatar-wrap {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #eef7f1;
            color: #1f7a3d;
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid #dce8e0;
        }

        .bidder-avatar-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .bidder-profile-info {
            flex: 1 1 auto;
            min-width: 0;
        }

        .bidder-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #06251b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bidder-email {
            font-size: 11.5px;
            color: #88968d;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bid-header-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .bid-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            letter-spacing: .3px;
        }

        .bid-status-pill.pending {
            background: #fff4d9;
            color: #97710a;
            border: 1px solid #fae1a0;
        }

        .bid-status-pill.submitted {
            background: #e4f5ea;
            color: #1f7a3d;
            border: 1px solid #c2e8ce;
        }

        .bid-status-pill.rejected {
            background: #ffebee;
            color: #c23b3b;
            border: 1px solid #f8c9c9;
        }

        .bid-timestamp {
            font-size: 11.5px;
            color: #63736a;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-view-submission-modal {
            background: #f7faf8;
            color: #06251b;
            border: 1px solid #dce4e0;
            border-radius: 9px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: all .15s ease;
        }

        .btn-view-submission-modal:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
        }

        /* ── Modals & Details ── */
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
            max-width: 440px;
            text-align: center;
            padding: 28px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: modalPopIn .2s ease;
        }

        .modal-dialog-large {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 720px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            animation: modalPopIn .2s ease;
            padding: 0;
            text-align: left;
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

        .modal-body-content {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .modal-bidder-card {
            background: #f7faf8;
            border: 1px solid #eaeeec;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .modal-bid-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 650px) {
            .modal-bid-grid {
                grid-template-columns: 1fr;
            }
        }

        .modal-section-card {
            background: #fbfdfc;
            border: 1px solid #edf1ee;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .modal-section-title {
            font-size: 11px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-section-title i {
            color: #1f7a3d;
            font-size: 13px;
        }

        .lot-chip-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .lot-chip-item {
            background: #ffffff;
            border: 1px solid #e2ece6;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #06251b;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .doc-sealed-pill {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 12px;
            color: #4a5e54;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
        }

        .doc-sealed-badge {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .btn-receipt-view {
            background: #06251b;
            color: #ffc107;
            font-size: 12px;
            font-weight: 700;
            padding: 9px 14px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .15s ease;
        }

        .btn-receipt-view:hover {
            background: #144937;
            color: #ffffff;
        }

        .btn-action-verify {
            width: 100%;
            padding: 10px 14px;
            font-size: 12.5px;
            font-weight: 700;
            border-radius: 9px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .15s ease;
        }

        .btn-action-verify.approve {
            background: #1f7a3d;
            color: #ffffff;
        }

        .btn-action-verify.approve:hover {
            background: #16602f;
            box-shadow: 0 4px 10px rgba(31, 122, 61, 0.2);
        }

        .btn-action-verify.reject {
            background: #fff0f0;
            color: #c23b3b;
            border: 1px solid #f5c6c6;
        }

        .btn-action-verify.reject:hover {
            background: #fde2e2;
        }

        .verify-done-tag {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .verify-done-tag.submitted {
            color: #1f7a3d;
            background: #eef7f1;
            border-color: #d1ead8;
        }

        .verify-done-tag.rejected {
            color: #c23b3b;
            background: #fff2f2;
            border-color: #f7caca;
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

        /* ── Spec Fields Grid ── */
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
            <a href="procurement.php"><i class="bi bi-inbox"></i> Procurements</a>
            <span>/</span>
            <span style="color:#06251b;">Review Submissions</span>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Procurement
        </a>
    </div>

    <!-- ── Project Header Hero Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <?php if (!empty($proc['philgeps_ref_no'])): ?>
                    <span class="hero-pill ref" onclick="copyPhilgeps('<?= htmlspecialchars($proc['philgeps_ref_no']) ?>')" title="Click to copy Reference No.">
                        <i class="bi bi-hash"></i> REF: <?= htmlspecialchars($proc['philgeps_ref_no']) ?>
                        <i class="bi bi-copy" style="font-size:10px; opacity:0.8;"></i>
                    </span>
                <?php endif; ?>
                
                <span class="hero-pill mode">
                    <i class="bi bi-sliders"></i> <?= htmlspecialchars($proc['procurement_mode'] ?: 'Public Bidding') ?>
                </span>

                <span class="hero-pill status" style="background:rgba(255,255,255,0.18);">
                    <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= strtoupper($p_status) ?>
                </span>
            </div>

            <span style="font-size:12px; color:#d1e5db;">
                <i class="bi bi-building"></i> Southern Luzon State University
            </span>
        </div>

        <h1 class="vp-hero-title"><?= htmlspecialchars($proc['title']) ?></h1>

        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-currency-exchange"></i> Approved Budget (ABC)</div>
                <div class="vp-hero-metric-val gold">₱<?= number_format((float)$proc['abc'], 2) ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-boxes"></i> Associated Lots</div>
                <div class="vp-hero-metric-val"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-inbox-fill"></i> Total Bids Received</div>
                <div class="vp-hero-metric-val"><?= $total_bids ?> Proposal<?= $total_bids !== 1 ? 's' : '' ?></div>
            </div>

            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-clock-history"></i> Submission Deadline</div>
                <div class="vp-hero-metric-val" style="font-size:13px; font-weight:700;">
                    <?= !empty($proc['closing_date']) ? date('M j, Y · g:i A', strtotime($proc['closing_date'])) : 'Not Set' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4" style="margin-bottom:20px;">
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-inbox-fill" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $total_bids ?></div>
                <div class="ap2-stat-lbl">Total Bids</div>
            </div>
        </div>
        <div class="ap2-stat <?= $pending_bids > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="background:conic-gradient(<?= $pending_bids > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= $total_bids > 0 ? round($pending_bids/$total_bids*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:<?= $pending_bids > 0 ? '#e67e22' : '#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $pending_bids > 0 ? '#e67e22' : 'inherit' ?>"><?= $pending_bids ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% <?= $total_bids > 0 ? round($verified_bids/$total_bids*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-shield-check" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $verified_bids ?></div>
                <div class="ap2-stat-lbl">Verified &amp; Sealed</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#c23b3b 0% <?= $total_bids > 0 ? round($rejected_bids/$total_bids*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-x-circle" style="color:#c23b3b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $rejected_bids ?></div>
                <div class="ap2-stat-lbl">Rejected</div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Grid Layout (1.7fr + 1fr) ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Bids Desk & Project Details ── -->
        <div class="vp-left-col">

            <!-- 1. Bid Submissions Desk -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-people" style="color:#06251b;"></i>
                        <span>Bidder Proposal Submissions</span>
                    </div>
                    <span class="vp-card-count"><?= count($bids) ?> Showing</span>
                </div>

                <!-- Filter Tabs -->
                <div class="bids-filter-bar">
                    <?php
                    $filterTabs = [
                        'all'       => ['label' => 'All Bids',            'count' => $total_bids],
                        'pending'   => ['label' => 'Pending Verification', 'count' => $pending_bids],
                        'submitted' => ['label' => 'Verified & Sealed',    'count' => $verified_bids],
                        'rejected'  => ['label' => 'Rejected',            'count' => $rejected_bids],
                    ];
                    foreach ($filterTabs as $val => $tab):
                        $isActive = ($bid_filter === $val);
                    ?>
                        <a href="bid-submission-view.php?id=<?= $procurement_id ?>&bid_filter=<?= $val ?>"
                           class="bid-filter-tab <?= $isActive ? 'active' : '' ?>">
                            <span><?= $tab['label'] ?></span>
                            <span class="bid-tab-count"><?= $tab['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Submissions List -->
                <?php if (empty($bids)): ?>
                    <div style="padding:40px 20px; text-align:center; color:#88968d;">
                        <i class="bi bi-inbox" style="font-size:36px; display:block; margin-bottom:8px; opacity:0.6;"></i>
                        <div style="font-size:14px; font-weight:700; color:#06251b;">No Bids Found</div>
                        <div style="font-size:12px; margin-top:2px;">There are no proposals matching the current filter selection.</div>
                    </div>
                <?php else: ?>
                    <div class="bids-container">
                        <?php foreach ($bids as $bid):
                            $bs = $bid['bid_status'];
                            $initials = strtoupper(substr($bid['firstname'],0,1).substr($bid['lastname'],0,1));
                            $avatar_url = !empty($bid['profile_picture_url']) ? '../' . ltrim($bid['profile_picture_url'], '/') : '';
                            
                            $receipt  = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'other'))[0] ?? null;
                            $elig     = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'eligibility'))[0] ?? null;
                            $fin      = array_values(array_filter($bid['docs'], fn($d) => $d['document_type'] === 'financial'))[0] ?? null;
                            
                            $json_data = htmlspecialchars(json_encode($bid), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="bid-item-card" id="bid-card-<?= $bid['bid_id'] ?>">
                                
                                <!-- Header Row (Click to open details modal) -->
                                <div class="bid-item-header" onclick='openBidDetailModal(<?= $json_data ?>)'>
                                    <div class="bidder-avatar-wrap">
                                        <?php if (!empty($avatar_url)): ?>
                                            <img src="<?= htmlspecialchars($avatar_url) ?>" alt="<?= htmlspecialchars($bid['firstname']) ?>">
                                        <?php else: ?>
                                            <?= htmlspecialchars($initials) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="bidder-profile-info">
                                        <div class="bidder-name"><?= htmlspecialchars($bid['firstname'] . ' ' . $bid['lastname']) ?></div>
                                        <div class="bidder-email"><i class="bi bi-envelope"></i> <?= htmlspecialchars($bid['email']) ?></div>
                                    </div>

                                    <div class="bid-header-meta">
                                        <span class="bid-status-pill <?= $bs ?>">
                                            <i class="bi bi-circle-fill" style="font-size:6px;"></i> <?= strtoupper($bs === 'submitted' ? 'VERIFIED' : $bs) ?>
                                        </span>
                                        <div class="bid-timestamp">
                                            <i class="bi bi-clock"></i> <?= date('M j, Y · g:i A', strtotime($bid['submission_date'])) ?>
                                        </div>
                                        <button type="button" class="btn-view-submission-modal" onclick='event.stopPropagation(); openBidDetailModal(<?= $json_data ?>)'>
                                            <i class="bi bi-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>

            <!-- 2. Project Specifications & Scope Overview -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle" style="color:#06251b;"></i>
                        <span>Procurement Specifications &amp; Overview</span>
                    </div>
                </div>
                <div class="vp-card-body">
                    <div class="spec-fields-grid">
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">PhilGEPS Reference No.</div>
                            <div class="spec-field-val"><?= htmlspecialchars($proc['philgeps_ref_no'] ?: 'N/A') ?></div>
                        </div>
                        <div class="spec-field-box">
                            <div class="spec-field-lbl">Procurement Mode</div>
                            <div class="spec-field-val"><?= htmlspecialchars($proc['procurement_mode'] ?: 'Public Bidding') ?></div>
                        </div>
                        <div class="spec-field-box" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Project Title</div>
                            <div class="spec-field-val" style="font-weight:700; color:#06251b; line-height:1.4;"><?= htmlspecialchars($proc['title']) ?></div>
                        </div>
                        <div class="spec-field-box" style="grid-column: 1 / -1;">
                            <div class="spec-field-lbl">Approved Budget for the Contract (ABC)</div>
                            <div class="spec-field-val" style="font-size:16px; font-weight:800; font-family:'Space Grotesk',sans-serif; color:#1f7a3d;">₱<?= number_format((float)$proc['abc'], 2) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($proc['description'])): ?>
                        <div style="font-size:11px; font-weight:700; color:#88968d; text-transform:uppercase; margin-bottom:6px;">Description / Technical Scope</div>
                        <div class="desc-text-box">
<?= htmlspecialchars($proc['description']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Associated Lots Table -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-boxes" style="color:#1f7a3d;"></i>
                        <span>Project Lots Breakdown</span>
                    </div>
                    <span class="vp-card-count"><?= count($lots) ?> Lot<?= count($lots) !== 1 ? 's' : '' ?></span>
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
                        <div style="padding:24px; text-align:center; color:#88968d; font-size:12px;">No lots configured for this procurement.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Activity & Timeline ── -->
        <div class="vp-right-col">

            <!-- Card 1: Key Dates & Milestones Timeline -->
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
                            $has_posted = !empty($proc['posting_date']);
                            $post_passed = $has_posted && (strtotime($proc['posting_date']) <= time());
                        ?>
                        <div class="timeline-item <?= $post_passed ? 'passed' : ($has_posted ? 'active' : '') ?>">
                            <div class="timeline-dot"><i class="bi bi-<?= $post_passed ? 'check-lg' : 'megaphone' ?>"></i></div>
                            <div class="timeline-content-box">
                                <div class="timeline-title-row">
                                    <span class="timeline-title">1. Posting Date</span>
                                    <span class="timeline-badge <?= $post_passed ? 'past' : 'future' ?>"><?= $post_passed ? 'Published' : 'Scheduled' ?></span>
                                </div>
                                <div class="timeline-date">
                                    <?= $has_posted ? date('F j, Y', strtotime($proc['posting_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Submission Deadline -->
                        <?php
                            $has_closing = !empty($proc['closing_date']);
                            $close_passed = $has_closing && (strtotime($proc['closing_date']) <= time());
                            $close_current = $has_closing && !$close_passed && ($p_status === 'open');
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
                                    <?= $has_closing ? date('F j, Y · g:i A', strtotime($proc['closing_date'])) : 'To Be Announced' ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Bid Opening Date -->
                        <?php
                            $has_opening = !empty($proc['opening_date']);
                            $open_passed = $has_opening && (strtotime($proc['opening_date']) <= time());
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
                                    <?= $has_opening ? date('F j, Y · g:i A', strtotime($proc['opening_date'])) : 'To Be Announced' ?>
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

<!-- ============================================== -->
<!-- BID SUBMISSION DETAILS MODAL                   -->
<!-- ============================================== -->
<div id="bidDetailModal" class="modal-backdrop" onclick="if(event.target===this)closeBidDetailModal()">
    <div class="modal-dialog-large">
        
        <!-- Modal Head -->
        <div class="modal-head">
            <h3><i class="bi bi-file-earmark-person-fill"></i> Bid Proposal Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeBidDetailModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal Body Content -->
        <div class="modal-body-content">
            
            <!-- Bidder Summary Header Card -->
            <div class="modal-bidder-card">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div id="modalAvatar" class="bidder-avatar-wrap" style="width:48px; height:48px; font-size:16px;"></div>
                    <div>
                        <div id="modalBidderNameDisplay" style="font-size:15px; font-weight:800; color:#06251b;"></div>
                        <div id="modalBidderEmailDisplay" style="font-size:12px; color:#88968d; margin-top:2px;"></div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div id="modalStatusBadge"></div>
                    <div id="modalTimestamp" style="font-size:11.5px; color:#63736a; margin-top:4px; font-weight:600;"></div>
                </div>
            </div>

            <!-- Two-Column Sections Grid -->
            <div class="modal-bid-grid">
                
                <!-- Applied Lots -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-boxes"></i> Applied Lots
                    </div>
                    <div id="modalLotsList" class="lot-chip-list"></div>
                </div>

                <!-- Payment Proof / Receipt -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-receipt"></i> Official Payment Receipt
                    </div>
                    <div id="modalReceiptBox" style="display:flex; flex-direction:column; gap:8px;"></div>
                </div>

                <!-- Encrypted Envelopes (Two-Envelope Security) -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-shield-lock"></i> Encrypted Bidding Envelopes
                    </div>
                    <div class="doc-sealed-pill">
                        <span><i class="bi bi-file-earmark-lock2"></i> Technical &amp; Eligibility</span>
                        <span class="doc-sealed-badge"><i class="bi bi-lock-fill"></i> Sealed</span>
                    </div>
                    <div class="doc-sealed-pill">
                        <span><i class="bi bi-cash-stack"></i> Financial Proposal</span>
                        <span class="doc-sealed-badge"><i class="bi bi-lock-fill"></i> Sealed</span>
                    </div>
                    <div style="font-size:11px; color:#88968d; line-height:1.4; margin-top:2px;">
                        <i class="bi bi-info-circle"></i> Unlocks automatically upon the declared Bid Opening schedule.
                    </div>
                </div>

                <!-- Verification & Clearance Action -->
                <div class="modal-section-card">
                    <div class="modal-section-title">
                        <i class="bi bi-check2-circle"></i> BAC Clearance &amp; Verification
                    </div>
                    <div id="modalVerificationActionBox" style="display:flex; flex-direction:column; gap:8px;"></div>
                </div>

            </div>

        </div>

        <!-- Modal Footer -->
        <div class="modal-foot">
            <button type="button" onclick="closeBidDetailModal()" class="vp-back-link" style="border:1px solid #eaeeec; cursor:pointer;">
                Close
            </button>
        </div>

    </div>
</div>

<!-- ============================================== -->
<!-- VERIFY / REJECT ACTION CONFIRMATION MODAL      -->
<!-- ============================================== -->
<div id="verifyModal" class="modal-backdrop" onclick="if(event.target===this)closeVerifyModal()">
    <div class="modal-dialog-box">
        <div id="modalIconWrap" style="width:54px; height:54px; border-radius:50%; background:#e8f5e9; color:#1f7a3d; font-size:24px; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <i class="bi bi-check-circle-fill" id="modalIcon"></i>
        </div>

        <h3 id="modalTitle" style="font-size:18px; font-weight:800; color:#06251b; margin:0 0 6px;">Approve Bid Proposal</h3>
        <p id="modalDesc" style="font-size:12.5px; color:#6c776e; line-height:1.5; margin:0 0 16px;">
            Are you sure you want to verify and approve the bid submission from this bidder?
        </p>

        <div id="modalBidderName" style="background:#fafcfb; border:1px solid #eaeeec; border-radius:10px; padding:10px 14px; font-size:12px; font-weight:700; color:#06251b; margin-bottom:20px;"></div>

        <form method="POST" action="" id="verifyForm">
            <input type="hidden" name="action_verify_bid" value="1">
            <input type="hidden" name="bid_id" id="modalBidId" value="">
            <input type="hidden" name="status_action" id="modalStatusAction" value="approve">
            
            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button" onclick="closeVerifyModal()" class="vp-back-link" style="padding:10px 20px; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="modalSubmitBtn" class="btn-action-verify approve" style="width:auto; padding:10px 24px; font-size:13px;">
                    <i class="bi bi-check-circle-fill"></i> Confirm Approval
                </button>
            </div>
        </form>
    </div>
</div>

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

    // Open Bid Detail Modal with Dynamic Data
    function openBidDetailModal(bid) {
        const fullName = (bid.firstname || '') + ' ' + (bid.lastname || '');
        const initials = ((bid.firstname ? bid.firstname.charAt(0) : 'B') + (bid.lastname ? bid.lastname.charAt(0) : 'P')).toUpperCase();
        
        // Avatar
        const avatarEl = document.getElementById('modalAvatar');
        if (bid.profile_picture_url) {
            const pic = '../' + bid.profile_picture_url.replace(/^\/+/, '');
            avatarEl.innerHTML = '<img src="' + escapeHtml(pic) + '" alt="' + escapeHtml(fullName) + '" style="width:100%;height:100%;object-fit:cover;">';
        } else {
            avatarEl.textContent = initials;
        }

        // Names & Emails
        document.getElementById('modalBidderNameDisplay').textContent = fullName;
        document.getElementById('modalBidderEmailDisplay').innerHTML = '<i class="bi bi-envelope"></i> ' + escapeHtml(bid.email);

        // Status Badge
        const statusEl = document.getElementById('modalStatusBadge');
        let statusClass = bid.bid_status || 'pending';
        let statusLabel = statusClass === 'submitted' ? 'VERIFIED' : statusClass.toUpperCase();
        statusEl.innerHTML = '<span class="bid-status-pill ' + escapeHtml(statusClass) + '"><i class="bi bi-circle-fill" style="font-size:6px;"></i> ' + escapeHtml(statusLabel) + '</span>';

        // Timestamp
        const dateObj = new Date(bid.submission_date);
        document.getElementById('modalTimestamp').innerHTML = '<i class="bi bi-clock"></i> ' + escapeHtml(bid.submission_date);

        // Lots List
        const lotsContainer = document.getElementById('modalLotsList');
        lotsContainer.innerHTML = '';
        if (bid.lots && bid.lots.length > 0) {
            bid.lots.forEach(lot => {
                const item = document.createElement('div');
                item.className = 'lot-chip-item';
                item.innerHTML = '<span><strong>Lot #' + escapeHtml(lot.lot_number) + '</strong> — ' + escapeHtml(lot.lot_title) + '</span>'
                               + '<span style="font-family:\'Space Grotesk\',sans-serif; color:#1f7a3d; font-weight:700;">₱' + Number(lot.abc).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>';
                lotsContainer.appendChild(item);
            });
        } else {
            lotsContainer.innerHTML = '<span style="font-size:12px; color:#aaa;">No specific lots recorded</span>';
        }

        // Receipt
        const receiptBox = document.getElementById('modalReceiptBox');
        receiptBox.innerHTML = '';
        const receipt = bid.docs ? bid.docs.find(d => d.document_type === 'other') : null;
        if (receipt) {
            const rawName = receipt.document_name || 'Official_Receipt.pdf';
            const shortName = truncateFileName(rawName, 26);
            receiptBox.innerHTML = '<div style="font-size:12px; color:#06251b; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;" title="' + escapeHtml(rawName) + '">'
                                 + '<i class="bi bi-file-earmark-check"></i> ' + escapeHtml(shortName) + '</div>'
                                 + '<a href="' + escapeHtml(receipt.file_path) + '" target="_blank" class="btn-receipt-view">'
                                 + '<i class="bi bi-eye"></i> View Official Receipt</a>';
        } else {
            receiptBox.innerHTML = '<span style="font-size:12px; color:#aaa;">No receipt uploaded</span>';
        }

        // Verification Actions
        const verifyBox = document.getElementById('modalVerificationActionBox');
        verifyBox.innerHTML = '';
        if (bid.bid_status === 'pending') {
            const approveBtn = document.createElement('button');
            approveBtn.type = 'button';
            approveBtn.className = 'btn-action-verify approve';
            approveBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Approve & Verify Bid';
            approveBtn.onclick = function() {
                confirmBidAction(bid.bid_id, 'approve', fullName);
            };

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'btn-action-verify reject';
            rejectBtn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Reject Submission';
            rejectBtn.onclick = function() {
                confirmBidAction(bid.bid_id, 'reject', fullName);
            };

            verifyBox.appendChild(approveBtn);
            verifyBox.appendChild(rejectBtn);
        } else if (bid.bid_status === 'submitted') {
            verifyBox.innerHTML = '<div class="verify-done-tag submitted"><i class="bi bi-shield-check-fill" style="font-size:18px;"></i>'
                                + '<div><div>Verified &amp; Sealed</div><small style="font-weight:400; font-size:10.5px; opacity:0.8;">Cleared for bid opening</small></div></div>';
        } else {
            verifyBox.innerHTML = '<div class="verify-done-tag rejected"><i class="bi bi-x-circle-fill" style="font-size:18px;"></i>'
                                + '<div><div>Submission Rejected</div><small style="font-weight:400; font-size:10.5px; opacity:0.8;">Disqualified by BAC</small></div></div>';
        }

        document.getElementById('bidDetailModal').classList.add('open');
    }

    function closeBidDetailModal() {
        document.getElementById('bidDetailModal').classList.remove('open');
    }

    // Modal Confirmation for Bid Verification
    function confirmBidAction(bidId, action, bidderName) {
        document.getElementById('modalBidId').value = bidId;
        document.getElementById('modalStatusAction').value = action;
        document.getElementById('modalBidderName').textContent = bidderName;

        const iconWrap = document.getElementById('modalIconWrap');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const desc = document.getElementById('modalDesc');
        const btn = document.getElementById('modalSubmitBtn');

        if (action === 'approve') {
            iconWrap.style.background = '#e8f5e9';
            iconWrap.style.color = '#1f7a3d';
            icon.className = 'bi bi-check-circle-fill';
            title.textContent = 'Approve Bid Proposal';
            desc.textContent = 'Are you sure you want to verify and approve this bid proposal? It will be cleared for the opening schedule.';
            btn.className = 'btn-action-verify approve';
            btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Confirm Approval';
        } else {
            iconWrap.style.background = '#ffebee';
            iconWrap.style.color = '#c23b3b';
            icon.className = 'bi bi-x-circle-fill';
            title.textContent = 'Reject Bid Proposal';
            desc.textContent = 'Are you sure you want to reject this bid proposal? The bidder will be notified of disqualification.';
            btn.className = 'btn-action-verify reject';
            btn.innerHTML = '<i class="bi bi-x-circle-fill"></i> Confirm Rejection';
        }

        document.getElementById('verifyModal').classList.add('open');
    }

    function closeVerifyModal() {
        document.getElementById('verifyModal').classList.remove('open');
    }

    // Utility escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Truncate file name with ellipsis
    function truncateFileName(fileName, maxLength = 26) {
        if (!fileName) return '';
        if (fileName.length <= maxLength) return fileName;
        const lastDotIndex = fileName.lastIndexOf('.');
        if (lastDotIndex > 0 && (fileName.length - lastDotIndex) <= 6) {
            const ext = fileName.substring(lastDotIndex);
            const base = fileName.substring(0, lastDotIndex);
            const keepChars = maxLength - ext.length - 3;
            if (keepChars >= 3) {
                return base.substring(0, keepChars) + '...' + ext;
            }
        }
        return fileName.substring(0, maxLength - 3) + '...';
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
