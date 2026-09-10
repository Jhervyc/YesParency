<?php
include("utils/protect-page.php");

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// ── Filters, Search, Sort & Pagination ────────────────────────────────────────
$search         = isset($_GET['search']) ? trim($_GET['search']) : '';
$mode_filter    = isset($_GET['mode']) ? trim($_GET['mode']) : 'all';
$status_filter  = isset($_GET['status']) ? trim($_GET['status']) : 'open';
$sort           = isset($_GET['sort']) ? trim($_GET['sort']) : 'closing_asc';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 10;
$offset         = ($page - 1) * $per_page;

// ── Calendar Events ───────────────────────────────────────────────────────────
$cal_result = $conn->query("
    SELECT id, title, slsu_ref_no, opening_date, closing_date, status
    FROM procurements
    WHERE status != 'draft' AND (opening_date IS NOT NULL OR closing_date IS NOT NULL)
    ORDER BY COALESCE(opening_date, closing_date) ASC
");
$cal_events = [];
if ($cal_result) {
    while ($c = $cal_result->fetch_assoc()) $cal_events[] = $c;
}

// ── Ranking of Procurements Based on Closing Date (Top 5 Closing Soonest) ───────
$ranking_sql = "
    SELECT 
        p.id,
        p.slsu_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
    FROM procurements p
    WHERE p.status = 'open' AND p.closing_date IS NOT NULL AND p.closing_date >= NOW()
    ORDER BY p.closing_date ASC
    LIMIT 5
";
$rank_stmt = $conn->query($ranking_sql);
$ranked_procs = $rank_stmt;

// ── Fetch Distinct Procurement Modes for Filter Dropdown ──────────────────────
$modes_res = $conn->query("SELECT DISTINCT procurement_mode FROM procurements WHERE status != 'draft' AND procurement_mode IS NOT NULL AND procurement_mode != '' ORDER BY procurement_mode ASC");
$avail_modes = [];
if ($modes_res) {
    while ($mr = $modes_res->fetch_assoc()) {
        $avail_modes[] = $mr['procurement_mode'];
    }
}
if (empty($avail_modes)) {
    $avail_modes = ['Public Bidding', 'Small Value Procurement', 'Shopping', 'Direct Contracting', 'Negotiated Procurement'];
}

// ── Query Construction for Main Tabular List ───────────────────────────────────
$where_parts = ["p.status != 'draft'"];
$params      = [];
$types       = '';

// Status Filter
if ($status_filter === 'open') {
    $where_parts[] = "p.status = 'open'";
} elseif ($status_filter === 'closed') {
    $where_parts[] = "p.status IN ('closed', 'awarded')";
}

// Procurement Mode Filter
if ($mode_filter !== 'all' && $mode_filter !== '') {
    $where_parts[] = "p.procurement_mode = ?";
    $params[] = $mode_filter;
    $types   .= 's';
}

// Search
if ($search !== '') {
    $like = '%' . $search . '%';
    $where_parts[] = "(p.title LIKE ? OR p.slsu_ref_no LIKE ? OR p.procurement_mode LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// Sorting
$order_sql = "ORDER BY p.closing_date ASC, p.id DESC";
if ($sort === 'closing_desc')  $order_sql = "ORDER BY p.closing_date DESC, p.id DESC";
elseif ($sort === 'abc_desc') $order_sql = "ORDER BY p.abc DESC, p.id DESC";
elseif ($sort === 'abc_asc')  $order_sql = "ORDER BY p.abc ASC, p.id DESC";
elseif ($sort === 'newest')   $order_sql = "ORDER BY p.id DESC";

// Count Total Matching Records
$count_sql  = "SELECT COUNT(*) FROM procurements p $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_shown = (int)$count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = max(1, ceil($total_shown / $per_page));

// Main Paged Query
$main_sql = "
    SELECT 
        p.id,
        p.slsu_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        p.status,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count,
        (SELECT COUNT(*) FROM procurement_documents d WHERE d.procurement_id = p.id) AS doc_count
    FROM procurements p
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
";
$main_params = array_merge($params, [$per_page, $offset]);
$main_types  = $types . 'ii';
$main_stmt   = $conn->prepare($main_sql);
$main_stmt->bind_param($main_types, ...$main_params);
$main_stmt->execute();
$procs_result = $main_stmt->get_result();

// ── Overall Summary Counts ─────────────────────────────────────────────────────
$stat_all_res   = $conn->query("SELECT COUNT(*) FROM procurements WHERE status != 'draft'");
$stat_all       = (int)($stat_all_res ? $stat_all_res->fetch_row()[0] : 0);

$stat_open_res  = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'");
$stat_open      = (int)($stat_open_res ? $stat_open_res->fetch_row()[0] : 0);

$stat_close_res = $conn->query("SELECT COUNT(*) FROM procurements WHERE status IN ('closed', 'awarded')");
$stat_close     = (int)($stat_close_res ? $stat_close_res->fetch_row()[0] : 0);

$stat_urgent_res= $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open' AND closing_date IS NOT NULL AND closing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)");
$stat_urgent    = (int)($stat_urgent_res ? $stat_urgent_res->fetch_row()[0] : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Opportunities | YesParency</title>
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
        /* ── Base Reset & Container ── */
        * {
            box-sizing: border-box;
        }

        .dash-content {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ── 60-40 Grid Layout ── */
        .proc-main-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .proc-main-layout {
                grid-template-columns: 1fr;
            }
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

        /* ── 100% Full Width Donut Stat Cards ── */
        .proc-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .proc-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .proc-stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .proc-stat-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .proc-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16,36,26,.09);
        }

        .proc-stat-ring {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
        }

        .proc-stat-inner {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .proc-stat-info {
            line-height: 1.2;
            min-width: 0;
        }

        .proc-stat-num {
            font-size: 22px;
            font-weight: 800;
            color: #06251b;
            line-height: 1.1;
        }

        .proc-stat-lbl {
            font-size: 11.5px;
            font-weight: 600;
            color: #88968d;
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Middle Section: Calendar (30%) + Scheduled Bids (70%) ── */
        .proc-mid-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 2.1fr);
            gap: 20px;
            align-items: stretch;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .proc-mid-layout {
                grid-template-columns: 1fr;
            }
        }

        .proc-mid-col {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .proc-mid-col .side-cal-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        /* ── Ranked Cards in 70% Column ── */
        .ranked-card-panel {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
        }

        .ranked-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .ranked-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ranked-item-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 20px;
            border-bottom: 1px solid #f2f5f3;
            transition: background .12s ease;
        }

        .ranked-item-row:last-child {
            border-bottom: none;
        }

        .ranked-item-row:hover {
            background: #fbfdfc;
        }

        .rank-badge-num {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #eef2f0;
            color: #06251b;
            font-weight: 800;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ranked-item-row:nth-child(1) .rank-badge-num {
            background: #ffc107;
            color: #06251b;
        }

        .ranked-item-row:nth-child(2) .rank-badge-num {
            background: #e0e6e3;
            color: #1a2a20;
        }

        .ranked-item-row:nth-child(3) .rank-badge-num {
            background: #ecd5b8;
            color: #5c3b1e;
        }

        .rank-main-info {
            flex: 1;
            min-width: 0;
        }

        .rank-item-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a2a20;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.35;
        }

        .rank-item-title a {
            color: inherit;
            text-decoration: none;
        }

        .rank-item-title a:hover {
            color: #1f7a3d;
            text-decoration: underline;
        }

        .rank-item-meta {
            font-size: 11.5px;
            color: #728277;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .rank-item-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .rank-right-col {
            text-align: right;
            flex-shrink: 0;
        }

        .rank-abc-val {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
        }

        .rank-urgency-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            margin-top: 3px;
            text-transform: uppercase;
        }

        .rank-urgency-pill.urgent {
            background: #feeceb;
            color: #c23b3b;
        }

        .rank-urgency-pill.normal {
            background: #eef7f1;
            color: #1f7a3d;
        }

        /* ── 30% Column Mini Calendar ── */
        .side-cal-panel {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .side-cal-header {
            padding: 16px 18px;
            background: #fafcfb;
            border-bottom: 1px solid #f0f4f2;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .side-cal-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .mini-cal-container {
            padding: 14px 18px;
        }

        .mini-cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .mini-cal-month {
            font-weight: 800;
            font-size: 13px;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
        }

        .mini-cal-btn {
            background: #eef2f0;
            border: none;
            width: 26px;
            height: 26px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #06251b;
            font-size: 11px;
            transition: all .15s;
        }

        .mini-cal-btn:hover {
            background: #06251b;
            color: #ffc107;
        }

        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            text-align: center;
        }

        .mini-cal-day-head {
            font-size: 10px;
            font-weight: 700;
            color: #88968d;
            padding: 4px 0;
            text-transform: uppercase;
        }

        .mini-cal-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: #1a1a1a;
            position: relative;
            cursor: default;
            transition: all .12s;
        }

        .mini-cal-cell.empty {
            visibility: hidden;
        }

        .mini-cal-cell.has-event {
            background: #f0f7f3;
            color: #06251b;
            font-weight: 800;
            cursor: pointer;
        }

        .mini-cal-cell.has-event:hover {
            background: #06251b;
            color: #ffc107;
        }

        .mini-cal-cell.is-today {
            border: 1.5px solid #1f7a3d;
        }

        .mini-cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #e67e22;
            position: absolute;
            bottom: 2px;
        }

        .mini-cal-cell:hover .mini-cal-dot {
            background: #ffc107;
        }

        /* ── Mini Calendar Events List ── */
        .side-events-list {
            padding: 12px 18px;
            background: #ffffff;
            overflow-y: auto;
        }

        .side-event-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid #f0f4f2;
            font-size: 11.5px;
            gap: 8px;
        }

        .side-event-item:last-child {
            border-bottom: none;
        }

        .side-event-title {
            font-weight: 700;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
        }

        .side-event-date {
            font-size: 9.5px;
            color: #88968d;
            font-weight: 600;
            white-space: nowrap;
        }

        /* ── Controls Filter Bar ── */
        .ap2-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
            overflow-x: auto;
        }

        .ap2-search-field {
            flex: 1;
            min-width: 200px;
            position: relative;
            display: flex;
            align-items: center;
        }

        .ap2-search-field i {
            position: absolute;
            left: 11px;
            color: #88968d;
            font-size: 13px;
            pointer-events: none;
        }

        .ap2-search-field input {
            width: 100%;
            padding: 9px 12px 9px 33px;
            border: 1.5px solid #e0e8e4;
            border-radius: 9px;
            font-size: 12px;
            font-family: 'Poppins', sans-serif;
            color: #1a1a1a;
            background: #fafcfb;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .ap2-search-field input:focus {
            border-color: #1f7a3d;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(31,122,61,0.08);
        }

        .proc-module-select select {
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
            flex-shrink: 0;
        }

        .proc-module-select select:focus {
            border-color: #06251b;
            background: #fff;
        }

        .proc-go-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #ffc107;
            color: #16241d;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 12.5px;
            border: none;
            padding: 8px 18px;
            border-radius: 9px;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .proc-go-btn:hover {
            background: #e6ac00;
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
            cursor: pointer;
        }

        .vp-back-link:hover {
            background: #06251b;
            color: #ffc107;
            border-color: #06251b;
        }

        /* ── Tabular List Table ── */
        .proc-table-panel {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .proc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            text-align: left;
        }

        .proc-table thead th {
            background: #fafcfb;
            padding: 13px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #55665a;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 1.5px solid #edf1ef;
            white-space: nowrap;
        }

        .proc-table tbody tr {
            border-bottom: 1px solid #f0f4f2;
            transition: background .12s ease;
        }

        .proc-table tbody tr:last-child {
            border-bottom: none;
        }

        .proc-table tbody tr:hover {
            background: #fbfdfc;
        }

        .proc-table td {
            padding: 14px 16px;
            vertical-align: middle;
        }

        .proc-ref-cell {
            font-weight: 700;
            color: #06251b;
            font-size: 11.5px;
            white-space: nowrap;
        }

        .proc-title-cell {
            font-weight: 700;
            color: #1a2a20;
            font-size: 13px;
            line-height: 1.35;
            max-width: 320px;
        }

        .proc-title-cell a {
            color: inherit;
            text-decoration: none;
        }

        .proc-title-cell a:hover {
            color: #1f7a3d;
            text-decoration: underline;
        }

        .proc-mode-tag {
            background: #f0f4f2;
            color: #384d40;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        .proc-abc-cell {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 13px;
            font-weight: 800;
            color: #06251b;
            white-space: nowrap;
        }

        .proc-deadline-cell {
            font-size: 11.5px;
            color: #63736a;
            white-space: nowrap;
            line-height: 1.3;
        }

        .proc-deadline-cell strong {
            color: #1a1a1a;
            display: block;
        }

        .proc-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            transition: all .15s ease;
            white-space: nowrap;
            background: #06251b;
            color: #ffc107;
            border: none;
            cursor: pointer;
        }

        .proc-action-btn:hover {
            background: #0a3a2a;
            color: #ffffff;
        }

        /* ── Event Viewer Modal ── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(6, 37, 27, 0.45);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            padding: 16px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            animation: modalPopIn .2s cubic-bezier(.34,1.56,.64,1);
        }

        @keyframes modalPopIn {
            from { transform: scale(0.94); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            background: #fafcfb;
            border-bottom: 1px solid #f0f4f2;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h4 {
            font-size: 15px;
            font-weight: 800;
            color: #06251b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            background: none;
            border: none;
            color: #88968d;
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            display: flex;
        }

        .modal-close:hover {
            color: #06251b;
            background: #eef2f0;
        }

        .modal-body {
            padding: 20px 22px;
            max-height: 380px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Procurements';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- Page Header -->
    <div class="page-header">
        <h2>Procurement Opportunities</h2>
        <p>Explore all active, scheduled, and concluded municipal bidding projects and contracts.</p>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TOP SECTION (100% WIDTH): Summary Stat Cards
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Summary Overview</div>
    <div class="proc-stats-grid">
        
        <!-- 1. Total Opportunities -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="proc-stat-inner">
                    <i class="bi bi-folder2-open" style="color:#06251b;"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num"><?= $stat_all ?></div>
                <div class="proc-stat-lbl">Total Listed</div>
            </div>
        </div>

        <!-- 2. Active / Open for Bidding -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="background:conic-gradient(#219653 0% <?= $stat_all > 0 ? round($stat_open / $stat_all * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="proc-stat-inner">
                    <i class="bi bi-check-circle" style="color:#219653;"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num" style="color:#219653;"><?= $stat_open ?></div>
                <div class="proc-stat-lbl">Open for Bidding</div>
            </div>
        </div>

        <!-- 3. Closing Soon (<= 3 days) -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="background:conic-gradient(#e67e22 0% <?= $stat_all > 0 ? round($stat_urgent / $stat_all * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="proc-stat-inner">
                    <i class="bi bi-hourglass-split" style="color:#e67e22;"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num" style="color:#e67e22;"><?= $stat_urgent ?></div>
                <div class="proc-stat-lbl">Closing Soon</div>
            </div>
        </div>

        <!-- 4. Closed / Awarded -->
        <div class="proc-stat-card">
            <div class="proc-stat-ring" style="background:conic-gradient(#2F6FED 0% <?= $stat_all > 0 ? round($stat_close / $stat_all * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="proc-stat-inner">
                    <i class="bi bi-archive" style="color:#2F6FED;"></i>
                </div>
            </div>
            <div class="proc-stat-info">
                <div class="proc-stat-num" style="color:#2F6FED;"><?= $stat_close ?></div>
                <div class="proc-stat-lbl">Closed / Concluded</div>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         MIDDLE SECTION: Calendar (30%) + Scheduled Activities List (70%)
         ══════════════════════════════════════════════════════════ -->
    <div class="proc-mid-layout">

        <!-- ── 30% Column: Mini Interactive Calendar ── -->
        <div class="proc-mid-col">
            <div class="sad-section-label">Bidding Calendar</div>
            
            <div class="side-cal-panel">
                <div class="side-cal-header">
                    <div class="side-cal-title">
                        <i class="bi bi-calendar3" style="color:#06251b;"></i>
                        <span>Schedule of Activities</span>
                    </div>
                </div>

                <div class="mini-cal-container" style="flex:1; display:flex; flex-direction:column; justify-content:space-between;">
                    <div class="mini-cal-nav">
                        <button type="button" class="mini-cal-btn" onclick="prevMonth()"><i class="bi bi-chevron-left"></i></button>
                        <div class="mini-cal-month" id="calMonthLabel">Loading...</div>
                        <button type="button" class="mini-cal-btn" onclick="nextMonth()"><i class="bi bi-chevron-right"></i></button>
                    </div>

                    <div class="mini-cal-grid" id="calGrid" style="flex:1;">
                        <!-- Filled by JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 70% Column: Scheduled Events List ── -->
        <div class="proc-mid-col">
            <div class="sad-section-label">Scheduled Activities</div>

            <div class="side-cal-panel">
                <div class="side-cal-header">
                    <div class="side-cal-title">
                        <i class="bi bi-clock-history" style="color:#1f7a3d;"></i>
                        <span>Activities for <span id="eventListMonthLabel">This Month</span></span>
                    </div>
                    <span id="calEventsCountBadge" style="font-size:11.5px; font-weight:700; color:#1f7a3d; background:#eef7f1; padding:3px 10px; border-radius:12px;">Events</span>
                </div>

                <!-- Event list for the selected month/day -->
                <div class="side-events-list" id="sideEventsList" style="flex:1; max-height:280px; overflow-y:auto; padding:12px 18px;">
                    <!-- Filled by JS -->
                </div>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         URGENT OPPORTUNITIES (100% WIDTH): Scheduled Bids & Deadlines
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">Scheduled Bids &amp; Deadlines</div>
    <div class="ranked-card-panel" style="margin-bottom: 24px;">
        <div class="ranked-card-header">
            <div class="ranked-card-title">
                <i class="bi bi-alarm" style="color:#e67e22;"></i>
                <span>Urgent Opportunities (Closing Soonest)</span>
            </div>
            <span style="font-size:11.5px; font-weight:700; color:#88968d;">Top 5 Deadlines</span>
        </div>

        <?php if ($ranked_procs && $ranked_procs->num_rows > 0): ?>
            <?php $rnk = 1; while ($rp = $ranked_procs->fetch_assoc()): 
                $days_left = ceil((strtotime($rp['closing_date']) - time()) / 86400);
                $is_urgent = ($days_left <= 3);
            ?>
                <div class="ranked-item-row">
                    <div class="rank-badge-num"><?= $rnk++ ?></div>
                    <div class="rank-main-info">
                        <div class="rank-item-title">
                            <a href="view_procurement.php?id=<?= $rp['id'] ?>" title="<?= htmlspecialchars($rp['title']) ?>">
                                <?= htmlspecialchars($rp['title']) ?>
                            </a>
                        </div>
                        <div class="rank-item-meta">
                            <span><i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($rp['slsu_ref_no'] ?: 'N/A') ?></span>
                            <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($rp['procurement_mode'] ?: 'Public Bidding') ?></span>
                            <span><i class="bi bi-calendar-x"></i> Deadline: <?= date('M j, Y', strtotime($rp['closing_date'])) ?></span>
                        </div>
                    </div>
                    <div class="rank-right-col">
                        <div class="rank-abc-val">₱<?= number_format((float)$rp['abc'], 2) ?></div>
                        <span class="rank-urgency-pill <?= $is_urgent ? 'urgent' : 'normal' ?>">
                            <i class="bi bi-clock"></i> <?= $days_left == 0 ? 'Closes Today' : ($days_left == 1 ? '1 day left' : $days_left . ' days left') ?>
                        </span>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="padding:32px 18px; text-align:center; color:#88968d; font-size:12.5px;">
                <i class="bi bi-inbox" style="font-size:28px; display:block; margin-bottom:6px;"></i>
                No active procurements currently closing soon.
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         BOTTOM SECTION (100% WIDTH): Tabular Procurement List
         ══════════════════════════════════════════════════════════ -->
    <div class="sad-section-label">All Opportunities</div>
    <div class="proc-table-panel">
        
        <!-- Controls: Search + Filter Tabs + Mode Dropdown + Sort -->
        <div style="padding: 16px 20px; border-bottom: 1px solid #edf1ef; background: #fafcfb;">
            <form method="GET" action="procurement.php" id="procFilterForm">
                
                <!-- Hidden inputs for sort -->
                <input type="hidden" name="sort" id="hiddenSort" value="<?= htmlspecialchars($sort) ?>">

                <div class="ap2-controls" style="display:flex; align-items:center; gap:10px; width:100%;">
                    
                    <!-- 1. Search Box -->
                    <div class="ap2-search-field">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search"
                            placeholder="Search by title, SLSU ref, or mode..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <!-- 2. Status Filter Tabs -->
                    <div class="ap2-filters" style="display:flex; gap:4px; flex-shrink:0;">
                        <?php
                        $status_tabs = [
                            'open'   => 'Open',
                            'all'    => 'All',
                            'closed' => 'Closed'
                        ];
                        foreach ($status_tabs as $val => $lbl):
                        ?>
                            <button type="submit" name="status" value="<?= $val ?>"
                                    class="ap2-filter-btn <?= $status_filter === $val ? 'active' : '' ?>">
                                <?= $lbl ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- 3. Procurement Mode Dropdown -->
                    <div class="proc-module-select" style="flex-shrink:0;">
                        <select name="mode" onchange="document.getElementById('procFilterForm').submit();">
                            <option value="all">All Modes</option>
                            <?php foreach ($avail_modes as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>" <?= $mode_filter === $m ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. Sort Dropdown -->
                    <div class="proc-module-select" style="flex-shrink:0;">
                        <select id="sortSelect" onchange="document.getElementById('hiddenSort').value=this.value; document.getElementById('procFilterForm').submit();">
                            <option value="closing_asc"  <?= $sort === 'closing_asc'  ? 'selected' : '' ?>>Closing Soonest</option>
                            <option value="closing_desc" <?= $sort === 'closing_desc' ? 'selected' : '' ?>>Closing Latest</option>
                            <option value="abc_desc"      <?= $sort === 'abc_desc'     ? 'selected' : '' ?>>ABC: High to Low</option>
                            <option value="abc_asc"       <?= $sort === 'abc_asc'      ? 'selected' : '' ?>>ABC: Low to High</option>
                            <option value="newest"        <?= $sort === 'newest'       ? 'selected' : '' ?>>Newest Listed</option>
                        </select>
                    </div>

                    <!-- 5. Search Button -->
                    <button type="submit" class="proc-go-btn" style="flex-shrink:0;">
                        <i class="bi bi-search"></i> Search
                    </button>

                </div>
            </form>
        </div>

        <!-- ── Main Table List ── -->
        <?php if ($total_shown === 0): ?>
            <div style="padding:48px 24px; text-align:center;">
                <i class="bi bi-search" style="font-size:32px; color:#88968d; display:block; margin-bottom:10px;"></i>
                <p style="font-size:14px; font-weight:700; color:#06251b; margin-bottom:4px;">No Matching Opportunities Found</p>
                <p style="font-size:12px; color:#6c776e; margin-bottom:16px;">We couldn't find any procurements matching your current search or filter criteria.</p>
                <a href="procurement.php" class="vp-back-link">
                    Reset Filters
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="proc-table">
                    <thead>
                        <tr>
                            <th style="width:140px;">SLSU Ref</th>
                            <th>Procurement Project Title</th>
                            <th>Procurement Mode</th>
                            <th>Approved Budget (ABC)</th>
                            <th>Deadline / Urgency</th>
                            <th style="text-align:right; min-width:110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $procs_result->fetch_assoc()):
                            $st = strtolower($row['status'] ?? 'open');
                            $isOpen = ($st === 'open');
                            $daysLeft = null;
                            if ($row['closing_date']) {
                                $daysLeft = ceil((strtotime($row['closing_date']) - time()) / 86400);
                            }
                        ?>
                            <tr>
                                <td class="proc-ref-cell">
                                    <i class="bi bi-hash"></i> <?= htmlspecialchars($row['slsu_ref_no'] ?: 'N/A') ?>
                                </td>
                                
                                <td class="proc-title-cell">
                                    <a href="view_procurement.php?id=<?= $row['id'] ?>">
                                        <?= htmlspecialchars($row['title']) ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="proc-mode-tag">
                                        <?= htmlspecialchars($row['procurement_mode'] ?: 'Public Bidding') ?>
                                    </span>
                                </td>

                                <td class="proc-abc-cell">
                                    ₱<?= number_format((float)$row['abc'], 2) ?>
                                </td>

                                <td class="proc-deadline-cell">
                                    <?php if ($row['closing_date']): ?>
                                        <strong><?= date('M j, Y · g:i A', strtotime($row['closing_date'])) ?></strong>
                                        <?php if ($isOpen && $daysLeft !== null): ?>
                                            <?php if ($daysLeft < 0): ?>
                                                <span style="color:#c23b3b; font-size:10px; font-weight:700;">Submission Closed</span>
                                            <?php elseif ($daysLeft == 0): ?>
                                                <span style="color:#c23b3b; font-size:10px; font-weight:700;">● Closes Today</span>
                                            <?php elseif ($daysLeft <= 3): ?>
                                                <span style="color:#e67e22; font-size:10px; font-weight:700;">▲ <?= $daysLeft ?> days left</span>
                                            <?php else: ?>
                                                <span style="color:#1f7a3d; font-size:10px; font-weight:600;"><?= $daysLeft ?> days remaining</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#88968d;">To be announced</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:right;">
                                    <a href="view_procurement.php?id=<?= $row['id'] ?>" class="proc-action-btn">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="ap2-pagination" style="padding:16px 20px;">
                    <div class="ap2-pagination-info">
                        Showing <strong><?= min($total_shown, $offset + 1) ?></strong> to <strong><?= min($total_shown, $offset + $per_page) ?></strong> of <strong><?= number_format($total_shown) ?></strong> items
                    </div>
                    <div class="ap2-pagination-links">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page - 1 ?>" class="ap2-page-link">
                                <i class="bi bi-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $p ?>"
                               class="ap2-page-link <?= $p === $page ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?= urlencode($status_filter) ?>&mode=<?= urlencode($mode_filter) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page + 1 ?>" class="ap2-page-link">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>
</main>

<!-- ══════════════════════════════════════════════════════════
     MODAL FOR CALENDAR EVENTS
     ══════════════════════════════════════════════════════════ -->
<div id="calEventModal" class="modal-backdrop" onclick="if(event.target===this)closeCalModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="bi bi-calendar-event"></i> <span id="calModalDate">Scheduled Activities</span></h4>
            <button type="button" class="modal-close" onclick="closeCalModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="calModalBody">
            <!-- Events populated by JS -->
        </div>
    </div>
</div>

<script>
// Raw calendar events passed from PHP
const calEvents = <?= json_encode($cal_events) ?>;

let currentCalDate = new Date();

function renderMiniCalendar() {
    const year  = currentCalDate.getFullYear();
    const month = currentCalDate.getMonth();

    const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    document.getElementById('calMonthLabel').textContent = `${monthNames[month]} ${year}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const lastDay       = new Date(year, month + 1, 0).getDate();

    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    // Day of week headers
    const days = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    days.forEach(d => {
        const dh = document.createElement('div');
        dh.className = 'mini-cal-day-head';
        dh.textContent = d;
        grid.appendChild(dh);
    });

    // Empty cells before day 1
    for (let i = 0; i < firstDayIndex; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'mini-cal-cell empty';
        grid.appendChild(emptyCell);
    }

    const todayStr = new Date().toISOString().split('T')[0];

    // Days 1..lastDay
    for (let day = 1; day <= lastDay; day++) {
        const cell = document.createElement('div');
        cell.className = 'mini-cal-cell';
        cell.textContent = day;

        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

        if (dateStr === todayStr) {
            cell.classList.add('is-today');
        }

        // Find events on this date
        const matches = calEvents.filter(e => {
            const openD  = e.opening_date ? e.opening_date.split(' ')[0] : null;
            const closeD = e.closing_date ? e.closing_date.split(' ')[0] : null;
            return openD === dateStr || closeD === dateStr;
        });

        if (matches.length > 0) {
            cell.classList.add('has-event');
            const dot = document.createElement('div');
            dot.className = 'mini-cal-dot';
            cell.appendChild(dot);

            cell.onclick = () => openDayEventsModal(dateStr, matches);
        }

        grid.appendChild(cell);
    }

    renderSideEventsList(month, year);
}

function renderSideEventsList(month, year) {
    const listEl = document.getElementById('sideEventsList');
    const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    const monthLabelEl = document.getElementById('eventListMonthLabel');
    const badgeEl = document.getElementById('calEventsCountBadge');

    if (monthLabelEl) monthLabelEl.textContent = `${monthNames[month]} ${year}`;
    listEl.innerHTML = '';

    const monthEvents = calEvents.filter(e => {
        const openD  = e.opening_date ? new Date(e.opening_date) : null;
        const closeD = e.closing_date ? new Date(e.closing_date) : null;
        return (openD && openD.getMonth() === month && openD.getFullYear() === year) ||
               (closeD && closeD.getMonth() === month && closeD.getFullYear() === year);
    });

    if (badgeEl) {
        badgeEl.textContent = `${monthEvents.length} Event${monthEvents.length !== 1 ? 's' : ''}`;
    }

    if (monthEvents.length === 0) {
        listEl.innerHTML = `
            <div style="text-align:center; padding:36px 20px; color:#88968d;">
                <i class="bi bi-calendar-x" style="font-size:28px; display:block; margin-bottom:6px;"></i>
                <p style="font-size:13px; font-weight:600; margin:0;">No scheduled activities listed for ${monthNames[month]} ${year}.</p>
            </div>
        `;
        return;
    }

    monthEvents.forEach(e => {
        const item = document.createElement('div');
        item.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:14px; padding:12px 14px; background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; margin-bottom:10px; transition:background .12s ease;';
        
        const d = e.opening_date || e.closing_date;
        const dateObj = new Date(d);
        const fMonth = dateObj.toLocaleDateString('en-US', { month:'short' }).toUpperCase();
        const fDay = dateObj.getDate();
        const isOpening = Boolean(e.opening_date);
        const typeBadge = isOpening
            ? '<span style="background:#e4f5ea; color:#1f7a3d; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase;">BID OPENING</span>'
            : '<span style="background:#fff3e0; color:#e67e22; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase;">SUBMISSION DEADLINE</span>';

        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px; min-width:0; flex:1;">
                <div style="width:40px; height:42px; border-radius:9px; background:#eef5f1; border:1px solid #d9e9df; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; flex-shrink:0; line-height:1.1;">
                    <span style="font-size:9px; font-weight:800; color:#1f7a3d;">${fMonth}</span>
                    <span style="font-size:14px; font-weight:800; color:#06251b; font-family:'Space Grotesk',sans-serif;">${fDay}</span>
                </div>
                <div style="min-width:0; flex:1;">
                    <div style="font-weight:700; font-size:13px; color:#1a2a20; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <a href="view_procurement.php?id=${e.id}" style="color:inherit; text-decoration:none;">${e.title}</a>
                    </div>
                    <div style="font-size:11px; color:#728277; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span><i class="bi bi-hash"></i> Ref: ${e.slsu_ref_no || 'N/A'}</span>
                        <span><i class="bi bi-clock"></i> ${dateObj.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                    </div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                ${typeBadge}
                <a href="view_procurement.php?id=${e.id}" class="proc-action-btn" style="font-size:11px; padding:5px 12px;">
                    <i class="bi bi-eye"></i> View
                </a>
            </div>
        `;
        listEl.appendChild(item);
    });
}

function prevMonth() {
    currentCalDate.setMonth(currentCalDate.getMonth() - 1);
    renderMiniCalendar();
}

function nextMonth() {
    currentCalDate.setMonth(currentCalDate.getMonth() + 1);
    renderMiniCalendar();
}

function openDayEventsModal(dateStr, events) {
    const modal = document.getElementById('calEventModal');
    const dateTitle = new Date(dateStr).toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
    document.getElementById('calModalDate').textContent = dateTitle;

    const body = document.getElementById('calModalBody');
    body.innerHTML = '';

    events.forEach(e => {
        const card = document.createElement('div');
        card.style.cssText = 'padding:12px; background:#fafcfb; border:1px solid #eaeeec; border-radius:12px; margin-bottom:10px;';
        
        let typeBadge = '';
        if (e.opening_date && e.opening_date.includes(dateStr)) {
            typeBadge = '<span style="background:#e4f5ea; color:#1f7a3d; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">BID OPENING</span>';
        } else {
            typeBadge = '<span style="background:#fff3e0; color:#e67e22; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">SUBMISSION DEADLINE</span>';
        }

        card.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                ${typeBadge}
                <span style="font-size:11px; color:#88968d; font-weight:600;">Ref: ${e.slsu_ref_no || 'N/A'}</span>
            </div>
            <div style="font-weight:700; font-size:13px; color:#1a2a20; margin-bottom:8px;">${e.title}</div>
            <a href="view_procurement.php?id=${e.id}" class="proc-action-btn" style="font-size:11px; padding:4px 10px;">
                <i class="bi bi-eye"></i> View Details
            </a>
        `;
        body.appendChild(card);
    });

    modal.classList.add('open');
}

function closeCalModal() {
    document.getElementById('calEventModal').classList.remove('open');
}

// Initial Render
renderMiniCalendar();
</script>

</body>
</html>
