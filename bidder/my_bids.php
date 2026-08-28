<?php
include("utils/protect-page.php");

$bidder_id = intval($_SESSION['user_id']);

// 1. Fetch All Bids by this Bidder
$sql = "
    SELECT 
        b.id AS bid_id,
        b.submission_date,
        b.status AS bid_status,
        p.id AS procurement_id,
        p.title AS procurement_title,
        p.philgeps_ref_no,
        p.abc AS procurement_abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status AS procurement_status
    FROM bids b
    JOIN procurements p ON b.procurement_id = p.id
    WHERE b.bidder_id = ?
    ORDER BY b.submission_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bidder_id);
$stmt->execute();
$bids_result = $stmt->get_result();

$my_bids = [];
$stats = [
    'total'     => 0,
    'pending'   => 0,
    'submitted' => 0,
    'opened'    => 0,
    'awarded'   => 0,
    'rejected'  => 0,
];

while ($row = $bids_result->fetch_assoc()) {
    $bid_id = $row['bid_id'];
    $s      = strtolower($row['bid_status']);

    // Track stats
    $stats['total']++;
    if (isset($stats[$s])) {
        $stats[$s]++;
    }

    // Fetch associated lots for this bid
    $lots_stmt = $conn->prepare("
        SELECT l.id, l.lot_number, l.lot_title, l.abc 
        FROM bid_lots bl
        JOIN lots l ON bl.lot_id = l.id
        WHERE bl.bid_id = ?
        ORDER BY l.lot_number ASC
    ");
    $lots_stmt->bind_param("i", $bid_id);
    $lots_stmt->execute();
    $lots_res = $lots_stmt->get_result();
    $bid_lots = [];
    $bid_total_abc = 0;
    while ($lot = $lots_res->fetch_assoc()) {
        $bid_lots[] = $lot;
        $bid_total_abc += (float)$lot['abc'];
    }
    $lots_stmt->close();

    // Fetch associated documents for this bid
    $docs_stmt = $conn->prepare("
        SELECT id, document_type, document_name, file_path 
        FROM bid_documents 
        WHERE bid_id = ?
        ORDER BY id ASC
    ");
    $docs_stmt->bind_param("i", $bid_id);
    $docs_stmt->execute();
    $docs_res = $docs_stmt->get_result();
    $bid_docs = [];
    while ($doc = $docs_res->fetch_assoc()) {
        $bid_docs[] = $doc;
    }
    $docs_stmt->close();

    $row['lots']          = $bid_lots;
    $row['total_bid_abc'] = $bid_total_abc;
    $row['documents']     = $bid_docs;
    $my_bids[]            = $row;
}
$stmt->close();

// Calculations for Conic Gradient Stats
$stat_total = $stats['total'];
$pct_pending   = $stat_total > 0 ? round(($stats['pending'] / $stat_total) * 100) : 0;
$pct_verified  = $stat_total > 0 ? round((($stats['submitted'] + $stats['opened']) / $stat_total) * 100) : 0;
$pct_awarded   = $stat_total > 0 ? round(($stats['awarded'] / $stat_total) * 100) : 0;

// Status styling configurations
$status_config = [
    'pending'   => [
        'label'    => 'Pending Verification',
        'barColor' => '#f59e0b',
        'badge_bg' => '#fef3c7',
        'badge_fg' => '#92400e',
        'iconBg'   => '#fffbeb',
        'iconFg'   => '#d97706',
        'icon'     => 'bi-hourglass-split'
    ],
    'submitted' => [
        'label'    => 'Verified & Submitted',
        'barColor' => '#10b981',
        'badge_bg' => '#d1fae5',
        'badge_fg' => '#065f46',
        'iconBg'   => '#ecfdf5',
        'iconFg'   => '#059669',
        'icon'     => 'bi-check-circle-fill'
    ],
    'opened'    => [
        'label'    => 'Bids Opened',
        'barColor' => '#2F6FED',
        'badge_bg' => '#E7EEFE',
        'badge_fg' => '#1e40af',
        'iconBg'   => '#eff6ff',
        'iconFg'   => '#2563eb',
        'icon'     => 'bi-broadcast'
    ],
    'awarded'   => [
        'label'    => 'Contract Awarded',
        'barColor' => '#1f7a3d',
        'badge_bg' => '#e4f5ea',
        'badge_fg' => '#1f7a3d',
        'iconBg'   => '#f0fdf4',
        'iconFg'   => '#15803d',
        'icon'     => 'bi-trophy-fill'
    ],
    'rejected'  => [
        'label'    => 'Disqualified / Rejected',
        'barColor' => '#ef4444',
        'badge_bg' => '#fee2e2',
        'badge_fg' => '#991b1b',
        'iconBg'   => '#fef2f2',
        'iconFg'   => '#dc2626',
        'icon'     => 'bi-x-circle-fill'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Submitted Proposals | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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

        /* ── Section Label (matching audit_trail.php) ── */
        .sad-section-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #88968d;
            margin-bottom: 10px;
        }

        /* ── Dropdown / Module Select ── */
        .module-select-wrap select {
            border: 1.5px solid #eaeeec;
            background: #eef2f0;
            color: #16241d;
            font-family: 'Poppins', sans-serif;
            font-size: 12.5px;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 9px;
            outline: none;
            cursor: pointer;
            transition: all .15s;
        }

        .module-select-wrap select:focus {
            border-color: #06251b;
            background: #fff;
        }

        .ap2-go-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #ffc107;
            color: #16241d;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 12.5px;
            border: none;
            padding: 8px 16px;
            border-radius: 9px;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }

        .ap2-go-btn:hover {
            background: #e6ac00;
            color: #06251b;
        }

        /* ── Collapsible Bid Row Accordion ── */
        .bid-accordion-item {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 14px;
            margin-bottom: 10px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(16,36,26,.02);
            transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .bid-accordion-item:hover {
            border-color: #c4d7cc;
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.05);
        }

        .bid-accordion-item.is-open {
            border-color: #06251b;
            box-shadow: 0 4px 16px rgba(6, 37, 27, 0.08);
        }

        /* Status Bar strip on left side of row */
        .bid-status-bar {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            transition: width .2s ease;
        }

        .bid-accordion-item.is-open .bid-status-bar {
            width: 6px;
        }

        /* Compact Header Row (Clickable) */
        .bid-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px 14px 22px;
            cursor: pointer;
            user-select: none;
            gap: 14px;
            background: #ffffff;
            transition: background .15s ease;
        }

        .bid-row-header:hover {
            background: #fafcfb;
        }

        .bid-row-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .bid-row-avatar {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .bid-row-info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .bid-row-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 3px;
        }

        .bid-row-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #06251b;
            text-decoration: none;
            transition: color .15s;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 520px;
            display: inline-block;
        }

        .bid-row-title:hover {
            color: #1f7a3d;
            text-decoration: underline;
        }

        .bid-ref-tag {
            background: #f0f4f2;
            color: #06251b;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: 'Space Grotesk', sans-serif;
            cursor: pointer;
            transition: all .15s;
            flex-shrink: 0;
        }

        .bid-ref-tag:hover {
            background: #06251b;
            color: #ffc107;
        }

        .bid-row-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 11.5px;
            color: #88968d;
            flex-wrap: wrap;
        }

        .bid-row-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .bid-row-meta strong {
            color: #1a1a1a;
            font-weight: 700;
        }

        .bid-row-right {
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
            white-space: nowrap;
        }

        .bid-toggle-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f0f4f2;
            color: #06251b;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            transition: all .2s ease;
            flex-shrink: 0;
        }

        .bid-toggle-btn:hover {
            background: #06251b;
            color: #ffc107;
        }

        .bid-accordion-item.is-open .bid-toggle-btn {
            background: #06251b;
            color: #ffc107;
            transform: rotate(180deg);
        }

        /* Collapsible Dropdown Content Body */
        .bid-dropdown-content {
            display: none;
            padding: 0 20px 20px 22px;
            border-top: 1px dashed #e8eee9;
            background: #fafcfb;
            animation: fadeInDropdown .25s ease;
        }

        .bid-accordion-item.is-open .bid-dropdown-content {
            display: block;
        }

        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .bid-dropdown-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
            gap: 16px;
            margin-top: 16px;
            min-width: 0;
            max-width: 100%;
        }

        @media (max-width: 860px) {
            .bid-dropdown-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .bid-panel-box {
            background: #ffffff;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }

        .bid-panel-head {
            font-size: 11px;
            font-weight: 700;
            color: #88968d;
            text-transform: uppercase;
            letter-spacing: .4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            min-width: 0;
        }

        .bid-panel-head strong {
            color: #06251b;
        }

        /* Lots List */
        .bid-lot-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            max-width: 100%;
        }

        .bid-lot-item {
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 8px;
            padding: 7px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }

        .bid-lot-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1 1 0;
            overflow: hidden;
        }

        .bid-lot-badge {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0;
        }

        .bid-lot-title {
            font-weight: 600;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            flex: 1 1 auto;
            display: block;
        }

        .bid-lot-abc {
            font-weight: 800;
            color: #1f7a3d;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 12px;
            flex-shrink: 0;
            white-space: nowrap;
        }

        /* Docs List */
        .bid-doc-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            max-width: 100%;
        }

        .bid-doc-item {
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 8px;
            padding: 7px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 11.5px;
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }

        .bid-doc-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1 1 0;
            overflow: hidden;
        }

        .bid-doc-name {
            font-weight: 600;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            flex: 1 1 auto;
            display: block;
        }

        .bid-sealed-badge {
            background: #eef7f1;
            color: #1f7a3d;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .bid-receipt-view-btn {
            background: #f0f4f2;
            color: #06251b;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all .15s;
            flex-shrink: 0;
        }

        .bid-receipt-view-btn:hover {
            background: #06251b;
            color: #ffc107;
        }

        /* Dropdown Action Bar */
        .bid-dropdown-actions {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #edf1ee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .bid-dropdown-meta {
            font-size: 12px;
            color: #6c776e;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .bid-dropdown-meta strong {
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
        }

        /* Quick collapse toggle button */
        .btn-toggle-all {
            background: none;
            border: none;
            color: #1f7a3d;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all .15s;
        }

        .btn-toggle-all:hover {
            background: #eef7f1;
            text-decoration: underline;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>

<?php 
$topbar_title = 'My Submitted Proposals';
include("components/topbar.php"); 
?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Title & Header -->
    <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
            <h2>My Submitted Proposals</h2>
            <p>Monitor your project applications, verification status, and sealed bid packages.</p>
        </div>
        <a href="procurement.php" class="vp-back-link">
            <i class="bi bi-folder2-open"></i> Browse Opportunities
        </a>
    </div>

    <!-- ── Stat Cards (Matching admin/audit_trail.php ring pattern) ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4" style="margin-bottom:20px;">
        <!-- Total Proposals -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-inbox-fill" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Total Proposals</div>
            </div>
        </div>

        <!-- Pending Review -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#f59e0b 0% <?= $pct_pending ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:#f59e0b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#d97706;"><?= number_format($stats['pending']) ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>

        <!-- Verified / Active -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#10b981 0% <?= $pct_verified ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-shield-check" style="color:#10b981;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#059669;"><?= number_format($stats['submitted'] + $stats['opened']) ?></div>
                <div class="ap2-stat-lbl">Verified / Active</div>
            </div>
        </div>

        <!-- Awarded Contracts -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#1f7a3d 0% <?= $pct_awarded ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-trophy-fill" style="color:#1f7a3d;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#1f7a3d;"><?= number_format($stats['awarded']) ?></div>
                <div class="ap2-stat-lbl">Awarded</div>
            </div>
        </div>
    </div>

    <!-- ── Proposals List Panel (Matching admin/audit_trail.php panel & controls) ── -->
    <div class="sp-panel sp-list-panel" style="background:#fff; border:1px solid #eaeeec; border-radius:18px; padding:18px; box-shadow:0 1px 2px rgba(16,36,26,.03);">
        
        <!-- ── Search + Filter Bar (1 Row Only matching admin/audit_trail.php) ── -->
        <div class="ap2-controls" style="display:flex; align-items:center; gap:10px; flex-wrap:nowrap; padding:0 0 16px 0; margin-bottom:14px; border-bottom:1px solid #f0f4f2; overflow-x:auto;">
            <div class="ap2-search-field" style="flex:1; min-width:200px;">
                <i class="bi bi-search"></i>
                <input type="text" id="bidSearchInput"
                    placeholder="Search by proposal title or PhilGEPS reference..."
                    oninput="handleSearch()"
                    onkeydown="if(event.key==='Enter'){event.preventDefault(); handleSearch();}">
            </div>

            <!-- Action / Status Filters -->
            <div class="ap2-filters" style="display:flex; gap:6px; flex-shrink:0;">
                <button type="button" class="ap2-filter-btn active" onclick="filterBids('all', this)">
                    All
                </button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('pending', this)">
                    Pending
                </button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('submitted', this)">
                    Verified
                </button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('opened', this)">
                    Opened
                </button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('awarded', this)">
                    Awarded
                </button>
                <?php if ($stats['rejected'] > 0): ?>
                    <button type="button" class="ap2-filter-btn" onclick="filterBids('rejected', this)">
                        Rejected
                    </button>
                <?php endif; ?>
            </div>

            <!-- Sort dropdown -->
            <div class="module-select-wrap" style="flex-shrink:0;">
                <select id="sortSelect" onchange="handleSort()">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="highest_abc">Highest Value</option>
                    <option value="lowest_abc">Lowest Value</option>
                </select>
            </div>

            <!-- Search Button (Matching admin/audit_trail.php) -->
            <button type="button" class="ap2-go-btn" onclick="handleSearch()" style="flex-shrink:0;">
                <i class="bi bi-search"></i> Search
            </button>
        </div>

        <!-- Proposals List Content -->
        <?php if (empty($my_bids)): ?>

            <div class="empty-state" style="padding:48px 24px; text-align:center;">
                <i class="bi bi-inbox" style="font-size:36px; color:#88968d; display:block; margin-bottom:10px;"></i>
                <p style="font-size:14px; font-weight:700; color:#06251b; margin-bottom:4px;">No Bid Proposals Submitted Yet</p>
                <p style="font-size:12px; color:#6c776e; margin-bottom:16px;">You haven't participated in any procurement opportunities yet.</p>
                <a href="procurement.php" class="vp-back-link" style="text-decoration:none;">
                    <i class="bi bi-folder2-open"></i> Browse Open Opportunities
                </a>
            </div>

        <?php else: ?>

            <div id="bidsListContainer">
                <?php foreach ($my_bids as $index => $bid):
                    $s       = strtolower($bid['bid_status']);
                    $cfg     = $status_config[$s] ?? [
                        'label'    => ucfirst($s),
                        'barColor' => '#8B958E',
                        'badge_bg' => '#EEF0ED',
                        'badge_fg' => '#8B958E',
                        'iconBg'   => '#f0f4f2',
                        'iconFg'   => '#06251b',
                        'icon'     => 'bi-circle'
                    ];
                    $title_text = $bid['procurement_title'];
                    $ref_text   = $bid['philgeps_ref_no'] ?? 'N/A';
                    $total_abc  = (float)$bid['total_bid_abc'];
                    $date_time  = strtotime($bid['submission_date']);
                ?>

                    <div class="bid-accordion-item"
                         id="bidItem-<?= $bid['bid_id'] ?>"
                         data-status="<?= htmlspecialchars($s) ?>"
                         data-search="<?= htmlspecialchars(strtolower($title_text . ' ' . $ref_text)) ?>"
                         data-date="<?= $date_time ?>"
                         data-abc="<?= $total_abc ?>">

                        <!-- Status bar indicator -->
                        <div class="bid-status-bar" style="background:<?= $cfg['barColor'] ?>;"></div>

                        <!-- Compact Header Row (Dropdown Toggle) -->
                        <div class="bid-row-header" onclick="toggleBidAccordion(<?= $bid['bid_id'] ?>, event)">
                            <div class="bid-row-left">
                                <!-- Action Avatar -->
                                <div class="bid-row-avatar" style="background:<?= $cfg['iconBg'] ?>; color:<?= $cfg['iconFg'] ?>;">
                                    <i class="bi <?= $cfg['icon'] ?>"></i>
                                </div>

                                <div class="bid-row-info">
                                    <div class="bid-row-title-wrap">
                                        <a href="view_procurement.php?id=<?= $bid['procurement_id'] ?>"
                                           class="bid-row-title"
                                           onclick="event.stopPropagation();"
                                           title="<?= htmlspecialchars($title_text) ?>">
                                            <?= htmlspecialchars($title_text) ?>
                                        </a>

                                        <span class="bid-ref-tag" onclick="event.stopPropagation(); copyRef('<?= htmlspecialchars($ref_text) ?>');" title="Click to copy reference">
                                            <i class="bi bi-hash"></i> <?= htmlspecialchars($ref_text) ?>
                                            <i class="bi bi-copy" style="font-size:9px; opacity:0.7;"></i>
                                        </span>
                                    </div>

                                    <div class="bid-row-meta">
                                        <span><i class="bi bi-layers"></i> Applied: <strong><?= count($bid['lots']) ?> <?= count($bid['lots']) === 1 ? 'Lot' : 'Lots' ?></strong></span>
                                        <span><i class="bi bi-cash-stack"></i> Total ABC: <strong>₱<?= number_format($total_abc, 2) ?></strong></span>
                                        <span><i class="bi bi-clock"></i> Submitted: <?= date("M j, Y · g:i A", $date_time) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="bid-row-right">
                                <span class="bid-status-pill" style="background:<?= $cfg['badge_bg'] ?>; color:<?= $cfg['badge_fg'] ?>;">
                                    <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                                </span>
                                <button type="button" class="bid-toggle-btn" aria-label="Toggle details">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Dropdown Detailed Content -->
                        <div class="bid-dropdown-content">
                            <div class="bid-dropdown-grid">
                                
                                <!-- Applied Lots Panel -->
                                <div class="bid-panel-box">
                                    <div class="bid-panel-head">
                                        <span><i class="bi bi-layers-fill" style="color:#1f7a3d;"></i> Applied Project Lots</span>
                                        <strong><?= count($bid['lots']) ?> <?= count($bid['lots']) === 1 ? 'Lot' : 'Lots' ?></strong>
                                    </div>

                                    <div class="bid-lot-list">
                                        <?php if (!empty($bid['lots'])): ?>
                                            <?php foreach ($bid['lots'] as $lot): ?>
                                                <div class="bid-lot-item">
                                                    <div class="bid-lot-left">
                                                        <span class="bid-lot-badge">LOT <?= htmlspecialchars($lot['lot_number']) ?></span>
                                                        <span class="bid-lot-title" title="<?= htmlspecialchars($lot['lot_title']) ?>">
                                                            <?= htmlspecialchars($lot['lot_title']) ?>
                                                        </span>
                                                    </div>
                                                    <span class="bid-lot-abc">₱<?= number_format($lot['abc'], 2) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="font-size:12px; color:#88968d; font-style:italic;">No lots recorded.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Submitted Documents Panel -->
                                <div class="bid-panel-box">
                                    <div class="bid-panel-head">
                                        <span><i class="bi bi-shield-lock-fill" style="color:#1f7a3d;"></i> Submitted Package &amp; Files</span>
                                        <strong><?= count($bid['documents']) ?> <?= count($bid['documents']) === 1 ? 'File' : 'Files' ?></strong>
                                    </div>

                                    <div class="bid-doc-list">
                                        <?php if (!empty($bid['documents'])): ?>
                                            <?php foreach ($bid['documents'] as $doc):
                                                $isReceipt = ($doc['document_type'] === 'other');
                                                $docIcon   = $isReceipt ? 'bi-receipt' : ($doc['document_type'] === 'eligibility' ? 'bi-file-earmark-check-fill' : 'bi-file-earmark-bar-graph-fill');
                                                $iconColor = $isReceipt ? '#d97706' : ($doc['document_type'] === 'eligibility' ? '#1f7a3d' : '#2F6FED');
                                            ?>
                                                <div class="bid-doc-item">
                                                    <div class="bid-doc-left">
                                                        <i class="bi <?= $docIcon ?>" style="color:<?= $iconColor ?>; font-size:14px; flex-shrink:0;"></i>
                                                        <span class="bid-doc-name" title="<?= htmlspecialchars($doc['document_name']) ?>">
                                                            <?= htmlspecialchars($doc['document_name']) ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if ($isReceipt && !empty($doc['file_path'])): ?>
                                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="bid-receipt-view-btn" onclick="event.stopPropagation();">
                                                            <i class="bi bi-eye"></i> View Receipt
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="bid-sealed-badge">
                                                            <i class="bi bi-lock-fill"></i> Sealed AES-256
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="font-size:12px; color:#88968d; font-style:italic;">No documents recorded.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>

                            <!-- Dropdown Actions Footer -->
                            <div class="bid-dropdown-actions">
                                <div class="bid-dropdown-meta">
                                    <span>Procurement Mode: <strong><?= htmlspecialchars($bid['procurement_mode'] ?? 'Public Bidding') ?></strong></span>
                                    <span>&bull;</span>
                                    <span>Total Value: <strong>₱<?= number_format($total_abc, 2) ?></strong></span>
                                </div>

                                <div style="display:flex; align-items:center; gap:8px;">
                                    <a href="view_procurement.php?id=<?= $bid['procurement_id'] ?>" class="vp-back-link" style="font-size:12px; padding:6px 12px;" onclick="event.stopPropagation();">
                                        View Full Procurement Details <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                    </div>

                <?php endforeach; ?>
            </div>

            <!-- Empty Search Filter Match State -->
            <div class="empty-state" id="noFilterMatchMsg" style="display:none; padding:48px 24px; text-align:center;">
                <i class="bi bi-search" style="font-size:32px; color:#88968d; display:block; margin-bottom:10px;"></i>
                <p style="font-size:14px; font-weight:700; color:#06251b; margin-bottom:4px;">No Matching Proposals Found</p>
                <p style="font-size:12px; color:#6c776e; margin-bottom:16px;">We couldn't find any proposals matching your current search or status filter.</p>
                <button type="button" class="vp-back-link" onclick="resetFilters()">
                    Reset Filters
                </button>
            </div>

        <?php endif; ?>

    </div><!-- /.sp-panel -->

</div>
</main>

<!-- Session Toast Alert -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert" style="position:fixed; bottom:24px; right:24px; z-index:9999; background:#06251b; color:#ffc107; padding:12px 20px; border-radius:12px; font-weight:700; box-shadow:0 8px 24px rgba(0,0,0,0.2); display:flex; align-items:center; gap:8px;">
        <i class="bi bi-check-circle-fill"></i>
        <span><?= htmlspecialchars($_SESSION['alert_success']) ?></span>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert" style="position:fixed; bottom:24px; right:24px; z-index:9999; background:#c23b3b; color:#ffffff; padding:12px 20px; border-radius:12px; font-weight:700; box-shadow:0 8px 24px rgba(0,0,0,0.2); display:flex; align-items:center; gap:8px;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= htmlspecialchars($_SESSION['alert_error']) ?></span>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<!-- Copy Notification Toast -->
<div id="copyToast" style="display:none; position:fixed; bottom:24px; right:24px; z-index:9999; background:#06251b; color:#ffc107; padding:12px 20px; border-radius:12px; font-weight:700; box-shadow:0 8px 24px rgba(0,0,0,0.2); align-items:center; gap:8px;">
    <i class="bi bi-check-circle-fill"></i> <span id="copyToastMsg">Copied!</span>
</div>

<script>
    let currentStatusFilter = 'all';
    let allExpanded = false;

    function copyRef(ref) {
        if (!ref || ref === 'N/A') return;
        navigator.clipboard.writeText(ref).then(() => {
            const toast = document.getElementById('copyToast');
            const msg   = document.getElementById('copyToastMsg');
            if (!toast) return;
            msg.textContent = `Reference ${ref} copied to clipboard!`;
            toast.style.display = 'flex';
            setTimeout(() => { toast.style.display = 'none'; }, 2500);
        });
    }

    // Toggle single bid accordion dropdown
    function toggleBidAccordion(bidId, event) {
        if (event) event.stopPropagation();
        const targetItem = document.getElementById('bidItem-' + bidId);
        if (!targetItem) return;

        const wasOpen = targetItem.classList.contains('is-open');

        // Close other items (clean accordion behavior)
        document.querySelectorAll('.bid-accordion-item.is-open').forEach(item => {
            if (item !== targetItem) item.classList.remove('is-open');
        });

        // Toggle clicked item
        if (wasOpen) {
            targetItem.classList.remove('is-open');
        } else {
            targetItem.classList.add('is-open');
        }
    }

    // Auto-close open accordion when clicking outside of it
    document.addEventListener('click', (e) => {
        const clickedInside = e.target.closest('.bid-accordion-item');
        if (!clickedInside) {
            document.querySelectorAll('.bid-accordion-item.is-open').forEach(item => {
                item.classList.remove('is-open');
            });
        }
    });

    function filterBids(status, tabElement) {
        currentStatusFilter = status;

        // Update active tab styles
        document.querySelectorAll('.ap2-filter-btn').forEach(t => t.classList.remove('active'));
        if (tabElement) tabElement.classList.add('active');

        applyFilters();
    }

    function handleSearch() {
        applyFilters();
    }

    function handleSort() {
        const sortVal = document.getElementById('sortSelect')?.value || 'newest';
        const container = document.getElementById('bidsListContainer');
        if (!container) return;

        const items = Array.from(container.querySelectorAll('.bid-accordion-item'));

        items.sort((a, b) => {
            const dateA = parseInt(a.getAttribute('data-date') || 0);
            const dateB = parseInt(b.getAttribute('data-date') || 0);
            const abcA  = parseFloat(a.getAttribute('data-abc') || 0);
            const abcB  = parseFloat(b.getAttribute('data-abc') || 0);

            if (sortVal === 'newest') return dateB - dateA;
            if (sortVal === 'oldest') return dateA - dateB;
            if (sortVal === 'highest_abc') return abcB - abcA;
            if (sortVal === 'lowest_abc') return abcA - abcB;
            return 0;
        });

        items.forEach(item => container.appendChild(item));
    }

    function applyFilters() {
        const query = (document.getElementById('bidSearchInput')?.value || '').toLowerCase().trim();
        const items = document.querySelectorAll('.bid-accordion-item');
        const emptyMsg = document.getElementById('noFilterMatchMsg');
        let visibleCount = 0;

        items.forEach(item => {
            const itemStatus = item.getAttribute('data-status') || '';
            const itemSearch = item.getAttribute('data-search') || '';

            const matchesStatus = (currentStatusFilter === 'all') || (itemStatus === currentStatusFilter);
            const matchesQuery  = !query || itemSearch.includes(query);

            if (matchesStatus && matchesQuery) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        if (emptyMsg) {
            emptyMsg.style.display = (visibleCount === 0 && items.length > 0) ? 'block' : 'none';
        }
    }

    function resetFilters() {
        const searchInput = document.getElementById('bidSearchInput');
        if (searchInput) searchInput.value = '';

        const allTab = document.querySelector('.ap2-filter-btn');
        filterBids('all', allTab);
    }

    // Auto-hide alert toasts after 4 seconds
    document.addEventListener('DOMContentLoaded', () => {
        const toast = document.getElementById('toastAlert');
        if (toast) {
            setTimeout(() => {
                toast.style.transition = 'opacity 0.4s ease';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, 4000);
        }
    });
</script>

</body>
</html>
