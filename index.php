<?php
include "config/db_connect.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── User Session Role Link ────────────────────────────────────────────────────
$user_dashboard_link = "login.php";
$user_logged_in = false;
$user_name = "";

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $user_logged_in = true;
    $user_name = $_SESSION['username'] ?? 'Account';
    switch ($_SESSION['role']) {
        case "user":       $user_dashboard_link = "user/dashboard.php"; break;
        case "bidder":     $user_dashboard_link = "bidder/dashboard.php"; break;
        case "admin":      $user_dashboard_link = "admin/dashboard.php"; break;
        case "superadmin": $user_dashboard_link = "admin/dashboard.php"; break;
    }
}

// ── Fetch Closest Bid Openings (Ranked by nearest opening date) ───────────────
$ranked_openings = [];
$all_schedules = [];
$total_active_procurements = 0;
$total_suppliers = 0;
$total_awarded = 0;
$live_session_hero = null;
$now = date('Y-m-d H:i:s');

if (isset($conn) && $conn instanceof mysqli) {
    // 0. Live session for hero
    $ls = $conn->query("
        SELECT bos.id AS session_id, bos.status AS session_status,
               bos.stream_path, bos.started_at,
               p.id AS proc_id, p.title AS proc_title, p.philgeps_ref_no, p.abc,
               p.procurement_mode,
               (SELECT COUNT(*) FROM lots WHERE lots.procurement_id = p.id) AS lots_count,
               (SELECT COUNT(*) FROM bids b WHERE b.procurement_id = p.id) AS bid_count
        FROM bid_opening_sessions bos
        JOIN procurements p ON bos.procurement_id = p.id
        WHERE bos.status IN ('started','eligibility','financial','awarding')
        ORDER BY bos.started_at DESC
        LIMIT 1
    ");
    if ($ls) $live_session_hero = $ls->fetch_assoc();
    // 1. Ranked Closest Bid Openings for Hero card (open only)
    $stmt = $conn->prepare("
        SELECT p.*,
               (SELECT COUNT(*) FROM lots WHERE lots.procurement_id = p.id) AS lots_count
        FROM procurements p
        WHERE p.status = 'open'
          AND p.opening_date IS NOT NULL
        ORDER BY 
            CASE 
                WHEN p.opening_date >= ? THEN 0 
                ELSE 1 
            END ASC,
            p.opening_date ASC
        LIMIT 3
    ");
    if ($stmt) {
        $stmt->bind_param("s", $now);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ranked_openings[] = $row;
        }
        $stmt->close();
    }

    // 2. Full Schedule for the Bid Schedule Section
    $sched_res = $conn->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM lots WHERE lots.procurement_id = p.id) AS lots_count
        FROM procurements p
        WHERE (p.status NOT IN ('cancelled', 'draft') OR p.status IS NULL)
        ORDER BY p.opening_date ASC, p.id DESC
        LIMIT 12
    ");
    if ($sched_res) {
        while ($row = $sched_res->fetch_assoc()) {
            $all_schedules[] = $row;
        }
    }

    // 3. Aggregate Stats
    $s1 = $conn->query("SELECT COUNT(*) AS cnt FROM procurements WHERE status = 'open' OR status IS NULL");
    if ($s1) { $total_active_procurements = (int)$s1->fetch_assoc()['cnt']; }

    $s2 = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role IN ('bidder', 'user')");
    if ($s2) { $total_suppliers = (int)$s2->fetch_assoc()['cnt']; }

    $s3 = $conn->query("SELECT COUNT(*) AS cnt FROM procurements WHERE status = 'awarded'");
    if ($s3) { $total_awarded = (int)$s3->fetch_assoc()['cnt']; }
}

// Fallback seed items if database has fewer than 3 items
if (count($ranked_openings) < 3) {
    $fallback_items = [
        [
            'id' => 101,
            'philgeps_ref_no' => 'SLSU-BAC-2026-003',
            'title' => 'Supply and Delivery of Science Laboratory Testing Equipment',
            'procurement_mode' => 'Public Bidding',
            'abc' => 2450000.00,
            'posting_date' => date('Y-m-d', strtotime('-5 days')),
            'closing_date' => date('Y-m-d H:i:s', strtotime('+2 days 09:00:00')),
            'opening_date' => date('Y-m-d H:i:s', strtotime('+2 days 10:00:00')),
            'status' => 'open',
            'lots_count' => 2
        ],
        [
            'id' => 102,
            'philgeps_ref_no' => 'SLSU-BAC-2026-007',
            'title' => 'Construction of Modern Multi-Purpose Academic Center',
            'procurement_mode' => 'Public Bidding',
            'abc' => 18750000.00,
            'posting_date' => date('Y-m-d', strtotime('-3 days')),
            'closing_date' => date('Y-m-d H:i:s', strtotime('+5 days 13:00:00')),
            'opening_date' => date('Y-m-d H:i:s', strtotime('+5 days 14:00:00')),
            'status' => 'open',
            'lots_count' => 1
        ],
        [
            'id' => 103,
            'philgeps_ref_no' => 'SLSU-BAC-2026-011',
            'title' => 'Supply, Delivery & Configuration of Campus ICT Infrastructure',
            'procurement_mode' => 'Competitive Bidding',
            'abc' => 3200000.00,
            'posting_date' => date('Y-m-d', strtotime('-1 days')),
            'closing_date' => date('Y-m-d H:i:s', strtotime('+9 days 11:30:00')),
            'opening_date' => date('Y-m-d H:i:s', strtotime('+9 days 13:30:00')),
            'status' => 'open',
            'lots_count' => 3
        ]
    ];

    foreach ($fallback_items as $fb) {
        if (count($ranked_openings) < 3) {
            $ranked_openings[] = $fb;
        }
        if (count($all_schedules) < 4) {
            $all_schedules[] = $fb;
        }
    }
}

if ($total_active_procurements === 0) $total_active_procurements = count($all_schedules);
if ($total_suppliers === 0) $total_suppliers = 48;
if ($total_awarded === 0) $total_awarded = 76;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YesParency | SLSU Procurement Transparency System</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Global stylesheet -->
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Base Enhancements ── */
        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 80px;
        }

        body.index-page {
            background: #041b13;
            color: #222;
            font-family: 'Poppins', sans-serif;
            overflow-x: hidden;
        }

        /* ── Navbar ── */
        .navbar {
            background: rgba(6,37,27,.97);
            backdrop-filter: blur(10px);
            padding: 13px 0;
            position: fixed; top: 0; left: 0;
            width: 100%; z-index: 1000;
            border-bottom: 2px solid rgba(255,193,7,.4);
        }
        .navbar.scrolled {
            background: #06251b;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            border-bottom-color: #ffc107;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-menu {
            list-style: none;
            display: flex;
            align-items: center;
            gap: 24px;
            margin: 0;
            padding: 0;
        }

        .nav-link {
            color: #e5ece8 !important;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            position: relative;
            padding: 6px 2px;
            transition: color 0.2s ease;
        }

        .nav-link::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -2px;
            width: 0%;
            height: 2.5px;
            background: #ffc107;
            border-radius: 2px;
            transition: width 0.25s ease;
        }

        .nav-link:hover {
            color: #ffc107 !important;
        }

        .nav-link:hover::after,
        .nav-link.active::after {
            width: 100%;
        }

        .btn-warning-nav {
            background: linear-gradient(135deg, #ffc107 0%, #f59e0b 100%);
            border: none;
            color: #06251b;
            padding: 9px 22px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
            transition: all 0.2s ease;
        }

        .btn-warning-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
            color: #06251b;
        }

        /* ── Hero Section ── */
        .hero {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 0px 24px 80px;
            background: radial-gradient(circle at 10% 20%, rgba(31, 122, 61, 0.22) 0%, transparent 50%),
                        radial-gradient(circle at 90% 80%, rgba(255, 193, 7, 0.12) 0%, transparent 45%),
                        #041b13;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            gap: 40px;
            align-items: center;
            position: relative;
            z-index: 2;
            width: 100%;
        }

        @media (max-width: 980px) {
            .hero-container {
                grid-template-columns: 1fr;
                gap: 40px;
            }
        }

        .hero-content h1 {
            font-size: 44px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.18;
            letter-spacing: -0.5px;
            margin-bottom: 20px;
            font-family: 'Space Grotesk', sans-serif;
        }

        .hero-content h1 .gold-highlight {
            color: #ffc107;
            text-shadow: 0 0 30px rgba(255, 193, 7, 0.25);
        }

        .hero-content p {
            font-size: 15px;
            color: #b7c7be;
            line-height: 1.65;
            margin-bottom: 30px;
            max-width: 520px;
        }

        .hero-btns {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn-hero-primary {
            background: #ffc107;
            color: #06251b;
            font-size: 14px;
            font-weight: 800;
            padding: 13px 28px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 18px rgba(255, 193, 7, 0.3);
            transition: all 0.2s ease;
        }

        .btn-hero-primary:hover {
            background: #ffffff;
            color: #06251b;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.25);
        }

        .btn-hero-outline {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 24px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-hero-outline:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: #ffc107;
            color: #ffc107;
            transform: translateY(-2px);
        }

        /* ── HERO RIGHT CARD: Ranking of Closest Bid Openings (Compact Design) ── */
        .hero-ranking-card {
            background: linear-gradient(145deg, #092f23 0%, #06251b 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            padding: 18px 20px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.38);
            position: relative;
            overflow: hidden;
            max-width: 440px;
            margin-left: auto;
        }

        .hero-ranking-card::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 140px;
            height: 140px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.16) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .ranking-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .ranking-head-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ranking-fire-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: rgba(255, 193, 7, 0.16);
            color: #ffc107;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .ranking-card-head h3 {
            font-size: 13.5px;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ranking-card-head p {
            font-size: 10.5px;
            color: #9cb3a6;
            margin: 1px 0 0;
        }

        .ranking-live-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(34, 197, 94, 0.18);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.4);
            font-size: 9.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 16px;
            letter-spacing: 0.4px;
        }

        .ranking-pulse-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 6px #4ade80;
            animation: pulseGreen 1.5s infinite;
        }

        @keyframes pulseGreen {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* ── Ranked Items List ── */
        .ranked-items-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
            margin-bottom: 12px;
        }

        .ranked-item-row {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            transition: all 0.15s ease;
            position: relative;
        }

        .ranked-item-row:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 193, 7, 0.3);
            transform: translateX(3px);
        }

        .rank-medal-badge {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .rank-medal-badge.rank-1 {
            background: linear-gradient(135deg, #ffd700 0%, #b8860b 100%);
            color: #06251b;
            box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
        }

        .rank-medal-badge.rank-2 {
            background: linear-gradient(135deg, #e0e0e0 0%, #9e9e9e 100%);
            color: #06251b;
        }

        .rank-medal-badge.rank-3 {
            background: linear-gradient(135deg, #cd7f32 0%, #8c531b 100%);
            color: #ffffff;
        }

        .rank-medal-badge.rank-default {
            background: rgba(255, 255, 255, 0.1);
            color: #d1e5db;
        }

        .ranked-item-details {
            flex: 1 1 auto;
            min-width: 0;
        }

        .ranked-item-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-bottom: 2px;
            flex-wrap: wrap;
        }

        .ranked-ref-badge {
            font-size: 10px;
            color: #ffc107;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .ranked-countdown-pill {
            font-size: 9.5px;
            font-weight: 700;
            padding: 1.5px 6px;
            border-radius: 4px;
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .ranked-countdown-pill.urgent {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.4);
        }

        .ranked-item-title {
            font-size: 12px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
            margin-bottom: 5px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ranked-item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 10.5px;
            color: #a4b8ad;
            flex-wrap: wrap;
        }

        .ranked-meta-date {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #d1e5db;
        }

        .ranked-meta-abc {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            color: #4ade80;
            font-size: 11px;
        }

        .ranking-card-foot {
            text-align: center;
            padding-top: 2px;
        }

        .btn-view-schedule-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: #ffc107;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 8px;
            background: rgba(255, 193, 7, 0.08);
            border: 1px solid rgba(255, 193, 7, 0.2);
            transition: all 0.15s ease;
        }

        .btn-view-schedule-link:hover {
            background: #ffc107;
            color: #06251b;
        }

        /* ── STATS SECTION (Clean White Background) ── */
        .stats-section {
            background: #ffffff;
            border-top: 1px solid #e7ede9;
            border-bottom: 1px solid #e7ede9;
            padding: 50px 24px;
        }

        .stats-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .stat-card-item {
            background: #fafcfb;
            border: 1.5px solid #e2ece6;
            border-radius: 18px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(6, 37, 27, 0.02);
        }

        .stat-card-item:hover {
            transform: translateY(-3px);
            border-color: #1f7a3d;
            box-shadow: 0 8px 20px rgba(6, 37, 27, 0.06);
            background: #ffffff;
        }

        .stat-icon-wrap {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px;
            transition: transform 0.2s ease;
        }

        .stat-card-item:hover .stat-icon-wrap {
            transform: scale(1.08);
            background: #1f7a3d;
            color: #ffffff;
        }

        .stat-card-num {
            font-size: 32px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .stat-card-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1f7a3d;
            margin-bottom: 3px;
        }

        .stat-card-sub {
            font-size: 11.5px;
            color: #63736a;
        }

        /* ── SECTION HEADINGS ── */
        .section-wrap {
            padding: 85px 24px;
            position: relative;
        }

        .section-header-center {
            text-align: center;
            max-width: 680px;
            margin: 0 auto 50px;
        }

        .section-main-heading {
            font-size: 32px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1.25;
            margin-bottom: 12px;
        }

        .section-lead-p {
            font-size: 14px;
            color: #55665a;
            line-height: 1.6;
            margin: 0;
        }

        /* ── BID SCHEDULE SECTION (Bright Light Background) ── */
        .bid-schedule-section {
            background: #f7faf8;
            border-bottom: 1px solid #e7ede9;
        }

        .schedule-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 22px;
        }

        @media (max-width: 480px) {
            .schedule-grid {
                grid-template-columns: 1fr;
            }
        }

        .schedule-card {
            background: #ffffff;
            border: 1.5px solid #e2ece6;
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(6, 37, 27, 0.04);
        }

        .schedule-card:hover {
            border-color: #1f7a3d;
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(6, 37, 27, 0.09);
        }

        .schedule-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 14px;
        }

        .schedule-status-badge {
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .schedule-status-badge.open {
            background: #eef7f1;
            color: #1f7a3d;
            border: 1px solid #c2e8ce;
        }

        .schedule-status-badge.upcoming {
            background: #fff8e6;
            color: #b78103;
            border: 1px solid #fadc8c;
        }

        .schedule-status-badge.closed {
            background: #f0f4f2;
            color: #63736a;
            border: 1px solid #d5ded9;
        }

        .schedule-ref {
            font-size: 11.5px;
            color: #63736a;
            font-weight: 700;
        }

        .schedule-title {
            font-size: 15.5px;
            font-weight: 800;
            color: #06251b;
            line-height: 1.4;
            margin-bottom: 16px;
        }

        .schedule-timeline-box {
            background: #fafcfb;
            border: 1px solid #edf2ef;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .timeline-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            gap: 10px;
        }

        .timeline-label {
            color: #63736a;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        .timeline-label i {
            color: #1f7a3d;
            font-size: 13px;
        }

        .timeline-val {
            font-weight: 700;
            color: #06251b;
            text-align: right;
        }

        .schedule-card-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 14px;
            border-top: 1px solid #edf2ef;
        }

        .schedule-abc-val {
            font-size: 15px;
            font-weight: 800;
            color: #1f7a3d;
            font-family: 'Space Grotesk', sans-serif;
        }

        .btn-schedule-action {
            background: #06251b;
            color: #ffc107;
            font-size: 12px;
            font-weight: 800;
            padding: 7px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 6px rgba(6, 37, 27, 0.12);
        }

        .btn-schedule-action:hover {
            background: #144937;
            color: #ffffff;
            transform: translateY(-1px);
        }

        /* ── ABOUT SECTION (Pure White Background) ── */
        .about-section {
            background: #ffffff;
            border-top: 1px solid #e7ede9;
        }

        .about-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 40px;
            align-items: center;
        }

        @media (max-width: 900px) {
            .about-grid {
                grid-template-columns: 1fr;
            }
        }

        .about-text-card h2 {
            font-size: 32px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1.25;
            margin-bottom: 18px;
        }

        .about-text-card p {
            font-size: 14px;
            color: #4a5c52;
            line-height: 1.7;
            margin-bottom: 22px;
        }

        .about-features-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 28px;
        }

        .about-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .feature-icon-box {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: #eef7f1;
            color: #1f7a3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .feature-item-text h5 {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            margin: 0 0 2px;
        }

        .feature-item-text p {
            font-size: 12px;
            color: #63736a;
            margin: 0;
            line-height: 1.45;
        }

        .about-info-box {
            background: linear-gradient(145deg, #06251b 0%, #0c3d2c 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 32px;
            color: #ffffff;
            box-shadow: 0 16px 40px rgba(6, 37, 27, 0.22);
        }

        .about-info-box h3 {
            font-size: 18px;
            font-weight: 800;
            color: #ffc107;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .about-contact-list {
            list-style: none;
            padding: 0;
            margin: 0 0 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .about-contact-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13px;
            color: #d1e5db;
        }

        .about-contact-list li i {
            color: #ffc107;
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* ── FOOTER ── */
        .footer-index {
            background: #020c09;
            color: #a4b8ad;
            padding: 60px 24px 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .footer-index-grid {
            max-width: 1200px;
            margin: 0 auto 40px;
            display: grid;
            grid-template-columns: 1.5fr 1fr 1.2fr;
            gap: 40px;
        }

        @media (max-width: 768px) {
            .footer-index-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }
        }

        .footer-brand-title {
            font-size: 18px;
            font-weight: 800;
            color: #ffc107;
            font-family: 'Space Grotesk', sans-serif;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-brand-desc {
            font-size: 12.5px;
            line-height: 1.6;
            color: #8fa699;
            max-width: 360px;
        }

        .footer-nav-col h5 {
            font-size: 13.5px;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .footer-nav-col ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .footer-nav-col ul a {
            color: #8fa699;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.15s;
        }

        .footer-nav-col ul a:hover {
            color: #ffc107;
        }

        .footer-bottom-bar {
            max-width: 1200px;
            margin: 0 auto;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            flex-wrap: wrap;
            gap: 12px;
            color: #6c8276;
        }

        /* ════════════════════════════════
           MOBILE-FIRST RESPONSIVE
           ════════════════════════════════ */

        /* Hamburger (hidden on desktop) */
        .nav-hamburger {
            display: none;
            background: none; border: none; color: #e5ece8;
            font-size: 22px; cursor: pointer; padding: 4px;
        }

        @media (max-width: 768px) {

            /* Navbar */
            .nav-menu {
                display: none;
                position: fixed; top: 62px; left: 0; right: 0;
                background: #06251b;
                flex-direction: column;
                gap: 0;
                padding: 10px 0 16px;
                border-bottom: 2px solid rgba(255,193,7,.4);
                z-index: 999;
            }
            .nav-menu.open { display: flex; }
            .nav-menu li { width: 100%; }
            .nav-menu .nav-link {
                display: block; padding: 12px 24px;
                font-size: 14px; border-bottom: 1px solid rgba(255,255,255,.05);
            }
            .nav-menu li:last-child { padding: 12px 24px 0; }
            .nav-menu .btn-warning-nav { width: calc(100% - 0px); justify-content: center; }
            .nav-hamburger { display: block; }

            /* Hero */
            .hero {
                min-height: auto;
                padding: 100px 16px 60px;
            }
            .hero-container {
                grid-template-columns: 1fr;
                gap: 32px;
            }
            .hero-content h1 { font-size: 30px; }
            .hero-content p  { font-size: 14px; }
            .hero-btns { flex-direction: column; gap: 10px; }
            .btn-hero-primary, .btn-hero-outline {
                width: 100%; justify-content: center; font-size: 13px; padding: 12px 20px;
            }
            .hero-ranking-card {
                max-width: 100%; margin-left: 0;
            }

            /* Stats */
            .stats-section { padding: 36px 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card-item { padding: 18px 14px; }
            .stat-card-num  { font-size: 26px; }

            /* Sections */
            .section-wrap { padding: 52px 16px; }
            .section-main-heading { font-size: 24px; }
            .section-header-center { margin-bottom: 32px; }

            /* Schedule cards */
            .schedule-grid { grid-template-columns: 1fr; gap: 14px; }
            .schedule-title { font-size: 14px; }

            /* About */
            .about-grid { grid-template-columns: 1fr; gap: 28px; }
            .about-text-card h2 { font-size: 24px; }
            .about-info-box { padding: 22px 18px; }

            /* Footer */
            .footer-index { padding: 40px 16px 24px; }
            .footer-index-grid { grid-template-columns: 1fr; gap: 24px; }
            .footer-bottom-bar { flex-direction: column; text-align: center; gap: 6px; }
        }

        @media (max-width: 480px) {
            .hero-content h1 { font-size: 26px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card-num { font-size: 22px; }
            .section-main-heading { font-size: 21px; }
        }
    </style>
</head>
<body class="index-page">

<!-- ========================= -->
<!-- NAVBAR                    -->
<!-- ========================= -->
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar('home', $user_logged_in, $user_dashboard_link); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inject hamburger into navbar
    const navMenu = document.querySelector('.nav-menu');
    const navContainer = document.querySelector('.nav-container');
    const btn = document.createElement('button');
    btn.className = 'nav-hamburger';
    btn.innerHTML = '<i class="bi bi-list"></i>';
    btn.setAttribute('aria-label', 'Toggle menu');
    navContainer.appendChild(btn);
    btn.addEventListener('click', function() {
        navMenu.classList.toggle('open');
        btn.innerHTML = navMenu.classList.contains('open')
            ? '<i class="bi bi-x-lg"></i>'
            : '<i class="bi bi-list"></i>';
    });
});
</script>

<!-- ========================= -->
<!-- HERO SECTION              -->
<!-- ========================= -->
<section class="hero" id="home">
    <div class="hero-container">

        <!-- Left: Hero Text & Call to Actions -->
        <div class="hero-content">

            <h1>
                Fair, Open &amp;<br>
                <span class="gold-highlight">Transparent</span><br>
                Bidding for SLSU
            </h1>

            <p>
                YesParency empowers Southern Luzon State University with digital integrity, public disclosure, and live cryptographic procurement monitoring for all goods, infrastructure, and consulting services.
            </p>

            <div class="hero-btns">
                <a href="register.php" class="btn-hero-primary">
                    <i class="bi bi-person-plus-fill"></i> Create User Account
                </a>
                <a href="bid_schedule.php" class="btn-hero-outline">
                    <i class="bi bi-calendar3"></i> View Procurement
                </a>
            </div>
        </div>

        <!-- Right: Live Session OR Closest Bid Openings -->
        <?php if ($live_session_hero): 
            $phase_labels = ['started'=>'Opening Started','eligibility'=>'Eligibility Phase','financial'=>'Financial Phase','awarding'=>'Awarding Phase'];
            $phase = $phase_labels[$live_session_hero['session_status']] ?? ucfirst($live_session_hero['session_status']);
            $phase_steps = ['started'=>0,'eligibility'=>1,'financial'=>2,'awarding'=>3];
            $cur_step = $phase_steps[$live_session_hero['session_status']] ?? 0;
        ?>
        <div class="hero-ranking-card" style="background:#0a1f16; border:1px solid #1f4a30; padding:28px;">
            <!-- Live header -->
            <div class="ranking-card-head" style="border-bottom:1px solid #1f4a30; padding-bottom:16px; margin-bottom:20px;">
                <div class="ranking-head-left">
                    <div class="ranking-fire-icon" style="background:#dc2626; color:#fff; width:38px; height:38px; border-radius:10px; font-size:18px;">
                        <i class="bi bi-broadcast-pin"></i>
                    </div>
                    <div>
                        <h3 style="color:#fff; font-size:16px;">Current Live Session</h3>
                        <p style="font-size:12px; color:#9cb3a6;">Bid opening in progress</p>
                    </div>
                </div>
                <div style="display:inline-flex; align-items:center; gap:6px; background:#14532d; color:#4ade80; border:1px solid #166534; font-size:11px; font-weight:800; padding:5px 12px; border-radius:20px; letter-spacing:.4px;">
                    <span class="ranking-pulse-dot"></span> Live
                </div>
            </div>

            <!-- Ref + Title -->
            <div style="margin-bottom:20px;">
                <div style="font-size:12px; font-weight:700; color:#ffc107; letter-spacing:.3px; margin-bottom:8px;">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($live_session_hero['philgeps_ref_no'] ?: 'SLSU-BAC') ?>
                </div>
                <div style="font-size:18px; font-weight:800; color:#fff; line-height:1.35; margin-bottom:8px;">
                    <?= htmlspecialchars($live_session_hero['proc_title']) ?>
                </div>
                <div style="font-size:12px; color:#9cb3a6;">
                    <?= htmlspecialchars($live_session_hero['procurement_mode'] ?: 'Public Bidding') ?>
                    &nbsp;·&nbsp; ABC: <span style="color:#4ade80; font-weight:700;">₱<?= number_format((float)$live_session_hero['abc'], 2) ?></span>
                </div>
            </div>

            <!-- Phase stepper -->
            <div style="display:flex; align-items:center; gap:0; margin-bottom:22px; font-size:12px; font-weight:700;">
                <?php
                $steps = ['Eligibility','Financial','Awarding'];
                foreach ($steps as $i => $s):
                    $done    = $cur_step > $i + 1;
                    $current = $cur_step == $i + 1;
                ?>
                <div style="display:flex; align-items:center; gap:0; flex:1;">
                    <div style="
                        display:flex; align-items:center; justify-content:center;
                        width:30px; height:30px; border-radius:50%; flex-shrink:0;
                        background:<?= $done ? '#1f7a3d' : ($current ? '#ffc107' : '#1a3428') ?>;
                        color:<?= $done ? '#fff' : ($current ? '#06251b' : '#4a6a55') ?>;
                        font-size:11px; font-weight:800; font-family:'Space Grotesk',sans-serif;
                        border:2px solid <?= $done ? '#1f7a3d' : ($current ? '#ffc107' : '#2a5040') ?>;">
                        <?= $done ? '<i class="bi bi-check2"></i>' : ($i + 1) ?>
                    </div>
                    <span style="margin-left:6px; color:<?= $current ? '#ffc107' : ($done ? '#9cb3a6' : '#4a6a55') ?>;">
                        <?= $s ?>
                    </span>
                    <?php if ($i < count($steps) - 1): ?>
                    <div style="flex:1; height:2px; margin:0 10px; background:<?= $done ? '#1f7a3d' : '#1a3428' ?>;"></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Stats row -->
            <div style="display:flex; align-items:center; gap:20px; margin-bottom:22px; font-size:12.5px; color:#9cb3a6; font-weight:600; flex-wrap:wrap; background:#071510; border-radius:12px; padding:14px 18px;">
                <span><i class="bi bi-people" style="color:#4ade80;"></i> <strong style="color:#fff;"><?= (int)$live_session_hero['bid_count'] ?></strong> bids submitted</span>
                <span style="opacity:.3;">·</span>
                <span><i class="bi bi-boxes" style="color:#ffc107;"></i> <strong style="color:#fff;"><?= (int)$live_session_hero['lots_count'] ?></strong> lot<?= $live_session_hero['lots_count'] != 1 ? 's' : '' ?></span>
                <span style="opacity:.3;">·</span>
                <span><i class="bi bi-clock" style="color:#9cb3a6;"></i> <?= $live_session_hero['started_at'] ? date('g:i A', strtotime($live_session_hero['started_at'])) : 'Just started' ?></span>
            </div>

            <!-- Join button -->
            <a href="live.php" style="
                display:flex; align-items:center; justify-content:center; gap:9px;
                background:#ffc107; color:#06251b;
                font-size:14px; font-weight:800; padding:15px 20px; border-radius:12px;
                text-decoration:none; transition:all .2s ease; font-family:'Poppins',sans-serif;
                box-shadow:0 4px 18px rgba(255,193,7,.25);">
                Join Live Session &nbsp;<i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <?php else: ?>
        <!-- No live session — show closest bid openings -->
        <div class="hero-ranking-card">
            <div class="ranking-card-head">
                <div class="ranking-head-left">
                    <div class="ranking-fire-icon">
                        <i class="bi bi-stopwatch-fill"></i>
                    </div>
                    <div>
                        <h3>Closest Bid Openings</h3>
                        <p>Nearest public opening schedules</p>
                    </div>
                </div>
                <div class="ranking-live-pill">
                    <span class="ranking-pulse-dot"></span> LIVE QUEUE
                </div>
            </div>

            <div class="ranked-items-list">
                <?php 
                $rank = 1;
                foreach ($ranked_openings as $item): 
                    $medalClass = 'rank-default';
                    if ($rank === 1) $medalClass = 'rank-1';
                    elseif ($rank === 2) $medalClass = 'rank-2';
                    elseif ($rank === 3) $medalClass = 'rank-3';

                    $openingTs = !empty($item['opening_date']) ? strtotime($item['opening_date']) : time();
                    $diffSecs  = $openingTs - time();
                    $diffDays  = round($diffSecs / 86400);

                    if ($diffSecs <= 0 && $diffSecs > -86400)     { $countdownText = "Opening Today"; $isUrgent = true; }
                    elseif ($diffDays == 1)                        { $countdownText = "Tomorrow";      $isUrgent = true; }
                    elseif ($diffDays > 1)                         { $countdownText = "In {$diffDays} Days"; $isUrgent = ($diffDays <= 3); }
                    else                                           { $countdownText = "Concluded";     $isUrgent = false; }
                ?>
                    <div class="ranked-item-row">
                        <div class="rank-medal-badge <?= $medalClass ?>">#<?= $rank ?></div>
                        <div class="ranked-item-details">
                            <div class="ranked-item-top">
                                <span class="ranked-ref-badge"><i class="bi bi-hash"></i> <?= htmlspecialchars($item['philgeps_ref_no']) ?></span>
                                <span class="ranked-countdown-pill <?= $isUrgent ? 'urgent' : '' ?>">
                                    <i class="bi bi-clock-history"></i> <?= $countdownText ?>
                                </span>
                            </div>
                            <div class="ranked-item-title" title="<?= htmlspecialchars($item['title']) ?>">
                                <?= htmlspecialchars($item['title']) ?>
                            </div>
                            <div class="ranked-item-meta">
                                <span class="ranked-meta-date">
                                    <i class="bi bi-calendar-event"></i> <?= date('M d, Y · g:i A', $openingTs) ?>
                                </span>
                                <span class="ranked-meta-abc">₱ <?= number_format((float)$item['abc'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                <?php $rank++; endforeach; ?>
            </div>

            <div class="ranking-card-foot">
                <a href="bid_schedule.php" class="btn-view-schedule-link">
                    View All Procurement <i class="bi bi-arrow-right" style="font-size:14px;"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- ========================= -->
<!-- STATS METRICS BAR         -->
<!-- ========================= -->
<section class="stats-section">
    <div class="stats-grid">

        <div class="stat-card-item">
            <div class="stat-icon-wrap">
                <i class="bi bi-folder2-open"></i>
            </div>
            <div class="stat-card-num"><?= $total_active_procurements ?></div>
            <div class="stat-card-title">Active Projects</div>
            <div class="stat-card-sub">Accepting supplier proposals</div>
        </div>

        <div class="stat-card-item">
            <div class="stat-icon-wrap">
                <i class="bi bi-building-check"></i>
            </div>
            <div class="stat-card-num"><?= $total_suppliers ?></div>
            <div class="stat-card-title">Registered Suppliers</div>
            <div class="stat-card-sub">Accredited bidder network</div>
        </div>

        <div class="stat-card-item">
            <div class="stat-icon-wrap">
                <i class="bi bi-award"></i>
            </div>
            <div class="stat-card-num"><?= $total_awarded ?></div>
            <div class="stat-card-title">Awarded Contracts</div>
            <div class="stat-card-sub">Publicly disclosed awards</div>
        </div>

        <div class="stat-card-item">
            <div class="stat-icon-wrap">
                <i class="bi bi-broadcast"></i>
            </div>
            <div class="stat-card-num">100%</div>
            <div class="stat-card-title">BAC Openness</div>
            <div class="stat-card-sub">Transparent public decryption</div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- BID OPENINGS TEASER       -->
<!-- ========================= -->
<section class="section-wrap bid-schedule-section" id="bid-schedule">
    <div class="section-header-center">
        <h2 class="section-main-heading">Upcoming Bid Openings</h2>
        <p class="section-lead-p">
            The nearest public procurement opening schedules administered by the SLSU Bids and Awards Committee.
        </p>
    </div>

    <div class="schedule-grid" style="max-width:1240px; margin:0 auto;">
        <?php foreach (array_slice($ranked_openings, 0, 3) as $sched):
            $statusStr = strtolower($sched['status'] ?? 'open');
            $openDate  = !empty($sched['opening_date'])  ? date('M d, Y · g:i A', strtotime($sched['opening_date']))  : 'To Be Announced';
            $closeDate = !empty($sched['closing_date'])  ? date('M d, Y · g:i A', strtotime($sched['closing_date'])) : 'Not Specified';
            $isFallback = ($sched['id'] >= 101 && $sched['id'] <= 103);
        ?>
        <div class="schedule-card">
            <div>
                <div class="schedule-card-top">
                    <span class="schedule-status-badge <?= $statusStr === 'open' ? 'open' : ($statusStr === 'closed' ? 'closed' : 'upcoming') ?>">
                        <i class="bi bi-circle-fill" style="font-size:7px;"></i> <?= ucfirst($statusStr) ?>
                    </span>
                    <span class="schedule-ref"><i class="bi bi-hash"></i> <?= htmlspecialchars($sched['philgeps_ref_no'] ?: 'SLSU-BAC') ?></span>
                </div>

                <div class="schedule-title"><?= htmlspecialchars($sched['title']) ?></div>

                <div class="schedule-timeline-box">
                    <div class="timeline-row">
                        <span class="timeline-label"><i class="bi bi-calendar-check"></i> Bid Opening:</span>
                        <span class="timeline-val" style="color:#d97706;"><?= $openDate ?></span>
                    </div>
                    <div class="timeline-row">
                        <span class="timeline-label"><i class="bi bi-clock-history"></i> Submission Cutoff:</span>
                        <span class="timeline-val"><?= $closeDate ?></span>
                    </div>
                </div>
            </div>

            <div class="schedule-card-foot">
                <div>
                    <div style="font-size:10px; color:#8fa699; text-transform:uppercase; font-weight:700;">Approved Budget (ABC)</div>
                    <div class="schedule-abc-val">₱ <?= number_format((float)$sched['abc'], 2) ?></div>
                </div>
                <?php if ($isFallback): ?>
                    <a href="login.php" class="btn-schedule-action">
                        <i class="bi bi-box-arrow-in-right"></i> View Details
                    </a>
                <?php else: ?>
                    <a href="bid_view.php?id=<?= (int)$sched['id'] ?>" class="btn-schedule-action">
                        <i class="bi bi-eye"></i> View Details
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- View All CTA -->
    <div style="text-align:center; margin-top:36px;">
        <a href="bid_schedule.php" style="
            display:inline-flex; align-items:center; gap:9px;
            background:#06251b; color:#ffc107;
            font-size:13.5px; font-weight:800;
            padding:13px 30px; border-radius:12px;
            text-decoration:none;
            box-shadow:0 4px 16px rgba(6,37,27,.18);
            transition:all .2s ease;">
            <i class="bi bi-calendar3"></i> View All Procurement
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>
</section>

<!-- ========================= -->
<!-- ABOUT SECTION             -->
<!-- ========================= -->
<section class="section-wrap about-section" id="about">
    <div class="about-grid">

        <!-- Left: Text -->
        <div class="about-text-card">
            <h2>Integrity, Accountability, and Digital Transparency</h2>
            <p>
                YesParency is the official online procurement transparency platform of <strong>Southern Luzon State University (SLSU)</strong>. Developed to uphold Republic Act No. 9184 (Government Procurement Reform Act), the system provides seamless public disclosure and cryptographic security across all procurement lifecycle stages.
            </p>

            <div class="about-features-list">
                <div class="about-feature-item">
                    <div class="feature-icon-box"><i class="bi bi-shield-lock-fill"></i></div>
                    <div class="feature-item-text">
                        <h5>Cryptographic Bid Vault</h5>
                        <p>Bidder submissions and financial proposals are sealed with hash encryption until the designated opening ceremony.</p>
                    </div>
                </div>

                <div class="about-feature-item">
                    <div class="feature-icon-box"><i class="bi bi-camera-video-fill"></i></div>
                    <div class="feature-item-text">
                        <h5>Public Opening Audits</h5>
                        <p>Live timestamps, transparent document logs, and verified evaluation checklists accessible to all accredited stakeholders.</p>
                    </div>
                </div>

                <div class="about-feature-item">
                    <div class="feature-icon-box"><i class="bi bi-file-earmark-check-fill"></i></div>
                    <div class="feature-item-text">
                        <h5>PhilGEPS Synchronization</h5>
                        <p>Direct alignment with national procurement notices, standard bidding document terms, and awarding transparency.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: BAC Contact & Office Information -->
        <div class="about-info-box">
            <h3><i class="bi bi-building"></i> BAC Secretariat Office</h3>
            <p style="font-size:13px; color:#b7c7be; line-height:1.6; margin-bottom:20px;">
                For inquiries regarding ongoing bidding opportunities, submission guidelines, or vendor accreditation requirements, please reach out to the Bids and Awards Committee:
            </p>

            <ul class="about-contact-list">
                <li>
                    <i class="bi bi-geo-alt-fill"></i>
                    <div>
                        <strong>Address:</strong><br>
                        Bids &amp; Awards Committee Secretariat, Southern Luzon State University Main Campus, Lucban, Quezon 4328
                    </div>
                </li>
                <li>
                    <i class="bi bi-envelope-at-fill"></i>
                    <div>
                        <strong>Email:</strong><br>
                        procurement@slsu.edu.ph
                    </div>
                </li>
                <li>
                    <i class="bi bi-clock-fill"></i>
                    <div>
                        <strong>Office Hours:</strong><br>
                        Monday – Friday, 8:00 AM – 5:00 PM (PST)
                    </div>
                </li>
            </ul>

            <div style="border-top:1px solid rgba(255,255,255,0.1); padding-top:16px;">
                <a href="register.php" class="btn-hero-primary" style="width:100%; justify-content:center;">
                    <i class="bi bi-person-check-fill"></i> Create Your User Account Today
                </a>
            </div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- FOOTER                    -->
<!-- ========================= -->
<footer class="footer-index">
    <div class="footer-index-grid">

        <div>
            <div class="footer-brand-title">
                <i class="bi bi-transparency"></i> YesParency Portal
            </div>
            <p class="footer-brand-desc">
                Southern Luzon State University's official digital procurement transparency system. Empowering suppliers with fair competition and public accountability.
            </p>
        </div>

        <div class="footer-nav-col">
            <h5>Navigation</h5>
            <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="bid_schedule.php">Procurement</a></li>
                <li><a href="#about">About System</a></li>
                <li><a href="login.php">Bidder Portal</a></li>
            </ul>
        </div>

        <div class="footer-nav-col">
            <h5>Governance</h5>
            <ul>
                <li><a href="https://www.philgeps.gov.ph" target="_blank" rel="noopener">PhilGEPS Portal</a></li>
                <li><a href="https://gppb.gov.ph" target="_blank" rel="noopener">GPPB R.A. 9184 Guidelines</a></li>
                <li><a href="https://slsu.edu.ph" target="_blank" rel="noopener">SLSU Official Website</a></li>
            </ul>
        </div>

    </div>

    <div class="footer-bottom-bar">
        <div>&copy; <?= date('Y') ?> YesParency — Southern Luzon State University. All Rights Reserved.</div>
        <div>Compliant with R.A. 9184 Government Procurement Standards</div>
    </div>
</footer>

<!-- ── Navbar Scroll & Active Link Script ── -->
<script>
    window.addEventListener('scroll', () => {
        const nav = document.getElementById('mainNavbar');
        if (window.scrollY > 40) {
            nav.classList.add('scrolled');
        } else {
            nav.classList.remove('scrolled');
        }

        // Active link highlighting
        const sections = document.querySelectorAll('section[id]');
        const scrollY = window.pageYOffset;

        sections.forEach(current => {
            const sectionHeight = current.offsetHeight;
            const sectionTop = current.offsetTop - 120;
            const sectionId = current.getAttribute('id');
            const link = document.querySelector('.nav-menu a[href*=' + sectionId + ']');

            if (link) {
                if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            }
        });
    });
</script>

</body>
</html>
