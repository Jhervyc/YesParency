<?php
include("utils/protect-page.php");

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// ── 1. Fetch User & Bidder Profile ─────────────────────────────────────────────
$user_stmt = $conn->prepare("
    SELECT u.*, bp.profile_id, bp.business_name, bp.application_status, bp.created_at AS applied_at
    FROM users u
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE u.user_id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name      = trim(($user_data['firstname'] ?? '') . ' ' . ($user_data['lastname'] ?? '')) ?: htmlspecialchars($_SESSION['username'] ?? 'User');
$username       = htmlspecialchars($user_data['username'] ?? $_SESSION['username'] ?? 'user');
$email          = htmlspecialchars($user_data['email'] ?? '');
$app_status     = strtolower($user_data['application_status'] ?? 'none');
$business_name  = $user_data['business_name'] ?? '';
$has_applied    = !empty($user_data['profile_id']);

// ── 2. Procurement Statistics ──────────────────────────────────────────────────
$total_open_res  = $conn->query("SELECT COUNT(*) FROM procurements WHERE status = 'open'");
$total_open      = ($total_open_res && $row = $total_open_res->fetch_row()) ? (int)$row[0] : 0;

$upcoming_res    = $conn->query("SELECT COUNT(*) FROM procurements WHERE opening_date >= NOW() AND status = 'open'");
$upcoming_count  = ($upcoming_res && $row = $upcoming_res->fetch_row()) ? (int)$row[0] : 0;

// ── 3. Unread Notifications Count ──────────────────────────────────────────────


// ── 4. Open Procurements List ──────────────────────────────────────────────────
$open_procs_result = $conn->query("
    SELECT 
        p.id,
        p.philgeps_ref_no,
        p.title,
        p.abc,
        p.procurement_mode,
        p.closing_date,
        p.opening_date,
        (SELECT COUNT(*) FROM lots l WHERE l.procurement_id = p.id) AS lot_count
    FROM procurements p
    WHERE p.status = 'open'
    ORDER BY p.closing_date ASC
    LIMIT 5
");



function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard | YesParency</title>
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
        /* ── Hero Greeting Card ── */
        .user-hero-card {
            background: linear-gradient(135deg, #06251b 0%, #0a3a2a 65%, #104e39 100%);
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px -6px rgba(6, 37, 27, 0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-hero-card::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.12) 0%, rgba(255, 193, 7, 0) 70%);
            pointer-events: none;
        }

        .hero-top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .hero-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .hero-badge {
            background: rgba(255, 193, 7, 0.18);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .hero-sub {
            font-size: 13.5px;
            color: #d1e5db;
            line-height: 1.5;
            max-width: 680px;
            margin-bottom: 20px;
        }

        .hero-pills {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hero-pill {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 7px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hero-pill i {
            color: #ffc107;
        }



        /* ── Accreditation Status Banner ── */
        .accredit-banner {
            border-radius: 16px;
            padding: 18px 22px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            border: 1px solid transparent;
        }

        .accredit-banner.not-registered {
            background: linear-gradient(135deg, #f0f7f3 0%, #e2efe7 100%);
            border-color: #cbe3d4;
        }

        .accredit-banner.pending {
            background: linear-gradient(135deg, #fffbf0 0%, #fdf0cf 100%);
            border-color: #fae099;
        }

        .accredit-banner.approved {
            background: linear-gradient(135deg, #f2faf4 0%, #dcf1e3 100%);
            border-color: #b7e3c4;
        }

        .accredit-banner.rejected {
            background: linear-gradient(135deg, #fdf4f4 0%, #fbe1e1 100%);
            border-color: #f5c2c2;
        }

        .accredit-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .accredit-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .not-registered .accredit-icon { background: #06251b; color: #ffc107; }
        .pending .accredit-icon { background: #e67e22; color: #fff; }
        .approved .accredit-icon { background: #219653; color: #fff; }
        .rejected .accredit-icon { background: #c23b3b; color: #fff; }

        .accredit-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            margin-bottom: 2px;
        }

        .accredit-desc {
            font-size: 12px;
            color: #526359;
            line-height: 1.4;
        }

        .accredit-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
            transition: all .2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        .accredit-btn.primary {
            background: #06251b;
            color: #ffc107;
            border: 1px solid #06251b;
        }

        .accredit-btn.primary:hover {
            background: #0a3a2a;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .accredit-btn.portal {
            background: #219653;
            color: #ffffff;
            border: 1px solid #219653;
        }

        .accredit-btn.portal:hover {
            background: #1b7e45;
            transform: translateY(-1px);
        }

        /* ── Main Layout Grid ── */
        .user-dash-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .user-dash-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ── Panel Cards ── */
        .dash-card {
            background: #ffffff;
            border: 1px solid #eaeeec;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.08);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .dash-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f4f2;
            background: #fafcfb;
        }

        .dash-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #06251b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dash-card-link {
            font-size: 11.5px;
            font-weight: 700;
            color: #1f7a3d;
            background: #eef7f1;
            padding: 5px 12px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .15s ease;
        }

        .dash-card-link:hover {
            background: #06251b;
            color: #ffc107;
        }

        /* ── Opportunity List Rows ── */
        .opp-row {
            padding: 14px 20px;
            border-bottom: 1px solid #f2f5f3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            transition: background .12s ease;
        }

        .opp-row:last-child {
            border-bottom: none;
        }

        .opp-row:hover {
            background: #fbfdfc;
        }

        .opp-main {
            min-width: 0;
            flex: 1;
        }

        .opp-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a2a20;
            margin-bottom: 4px;
            line-height: 1.35;
        }

        .opp-meta {
            font-size: 11.5px;
            color: #728277;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .opp-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .opp-side {
            text-align: right;
            flex-shrink: 0;
        }

        .opp-abc {
            font-size: 13.5px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
            margin-bottom: 3px;
        }

        .opp-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.3px;
        }

        .opp-status.open {
            background: #e4f5ea;
            color: #1f7a3d;
        }

        .opp-status.closing-soon {
            background: #fff3e0;
            color: #e67e22;
        }

        /* ── Schedule List Items ── */
        .sched-item {
            padding: 14px 20px;
            border-bottom: 1px solid #f2f5f3;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .sched-item:last-child {
            border-bottom: none;
        }

        .sched-date-box {
            width: 44px;
            height: 48px;
            border-radius: 10px;
            background: #eef5f1;
            border: 1px solid #d9e9df;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            text-align: center;
            line-height: 1.1;
        }

        .sched-month {
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            color: #1f7a3d;
        }

        .sched-day {
            font-size: 16px;
            font-weight: 800;
            color: #06251b;
            font-family: 'Space Grotesk', sans-serif;
        }

        .sched-info {
            min-width: 0;
            flex: 1;
        }

        .sched-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #18261e;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sched-sub {
            font-size: 11px;
            color: #728277;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Quick Actions Grid ── */
        .qa-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            padding: 16px 20px;
        }

        .qa-btn {
            background: #fafcfb;
            border: 1px solid #eaeeec;
            border-radius: 12px;
            padding: 14px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 8px;
            text-decoration: none;
            transition: all .15s ease;
        }

        .qa-btn:hover {
            background: #06251b;
            border-color: #06251b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(6, 37, 27, 0.15);
        }

        .qa-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eef5f1;
            color: #06251b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all .15s ease;
        }

        .qa-btn:hover .qa-icon {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }

        .qa-label {
            font-size: 11.5px;
            font-weight: 700;
            color: #16241d;
            line-height: 1.2;
            transition: color .15s ease;
        }

        .qa-btn:hover .qa-label {
            color: #ffffff;
        }

        /* ── Steps Accordion / Guide Box ── */
        .guide-box {
            padding: 16px 20px;
            background: #fafcfb;
            border-radius: 12px;
            margin: 16px 20px;
            border: 1px solid #edf1ef;
        }

        .guide-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .guide-item:last-child {
            margin-bottom: 0;
        }

        .guide-num {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #06251b;
            color: #ffc107;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .guide-text {
            font-size: 11.5px;
            color: #4b5a51;
            line-height: 1.4;
        }
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'User Dashboard';
include("components/topbar.php"); 
?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Hero Greeting Card ── -->
    <div class="user-hero-card">
        <div class="hero-top-row">
            <div class="hero-title">
                <span>Welcome back, <?= htmlspecialchars($full_name) ?></span>
            </div>
            <div class="hero-badge">
                <i class="bi bi-shield-check"></i> General User Portal
            </div>
        </div>
        <p class="hero-sub">
            Explore active SLSU procurement opportunities, review bidding schedules, and track official bulletins. To submit proposals, register your business as an accredited bidder.
        </p>
        <div class="hero-pills">
            <div class="hero-pill">
                <i class="bi bi-at"></i> @<?= $username ?>
            </div>
            <div class="hero-pill">
                <i class="bi bi-calendar3"></i> <?= date('F j, Y') ?>
            </div>
            <div class="hero-pill">
                <i class="bi bi-folder2-open"></i> <?= $total_open ?> Active Opportunities
            </div>
        </div>
    </div>

    <!-- ── 4 Donut Stat Summary Cards ── -->

    <!-- ── Accreditation Status Banner ── -->
    <?php if ($app_status === 'approved'): ?>
        <div class="accredit-banner approved">
            <div class="accredit-left">
                <div class="accredit-icon"><i class="bi bi-patch-check-fill"></i></div>
                <div>
                    <div class="accredit-title">Accredited Bidder Status Active</div>
                    <div class="accredit-desc">Your business profile <strong><?= htmlspecialchars($business_name) ?></strong> is verified. You can participate and submit digital proposals.</div>
                </div>
            </div>
            <a href="../bidder/dashboard.php" class="accredit-btn portal">
                <i class="bi bi-box-arrow-in-right"></i> Open Bidder Portal
            </a>
        </div>
    <?php elseif ($app_status === 'pending'): ?>
        <div class="accredit-banner pending">
            <div class="accredit-left">
                <div class="accredit-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="accredit-title">Bidder Application Under Review</div>
                    <div class="accredit-desc">Your accreditation application for <strong><?= htmlspecialchars($business_name) ?></strong> is currently being validated by the BAC Secretariat.</div>
                </div>
            </div>
            <span class="opp-status closing-soon" style="font-size:11px; padding:6px 12px;">
                <i class="bi bi-clock-history"></i> Verification in Progress
            </span>
        </div>
    <?php elseif ($app_status === 'rejected'): ?>
        <div class="accredit-banner rejected">
            <div class="accredit-left">
                <div class="accredit-icon"><i class="bi bi-exclamation-octagon-fill"></i></div>
                <div>
                    <div class="accredit-title">Application Requires Attention</div>
                    <div class="accredit-desc">Your previous registration was not approved. Please review requirements and resubmit your documents.</div>
                </div>
            </div>
            <a href="bidder-registration.php" class="accredit-btn primary">
                <i class="bi bi-arrow-repeat"></i> Update Application
            </a>
        </div>
    <?php else: ?>
        <div class="accredit-banner not-registered">
            <div class="accredit-left">
                <div class="accredit-icon"><i class="bi bi-person-plus-fill"></i></div>
                <div>
                    <div class="accredit-title">Participate in SLSU Procurement as an Accredited Supplier</div>
                    <div class="accredit-desc">Register your business to download bidding documents, submit online price proposals, and receive direct BAC awards.</div>
                </div>
            </div>
            <a href="bidder-registration.php" class="accredit-btn primary">
                <i class="bi bi-person-plus"></i> Register as Bidder
            </a>
        </div>
    <?php endif; ?>

    <!-- ── Two-Column Grid ── -->
    <div class="user-dash-grid">

        <!-- ── LEFT COLUMN: Procurements & Schedule ── -->
        <div>

            <!-- 1. Active Procurements Panel -->
            <div class="dash-card">
                <div class="dash-card-head">
                    <div class="dash-card-title">
                        <i class="bi bi-folder2-open" style="color:#06251b;"></i>
                        <span>Active Procurement Projects</span>
                    </div>
                    <a href="procurement.php" class="dash-card-link">View all →</a>
                </div>

                <?php if ($open_procs_result && $open_procs_result->num_rows > 0): ?>
                    <?php while ($p = $open_procs_result->fetch_assoc()): 
                        $isUrgent = false;
                        $closeTime = $p['closing_date'] ? strtotime($p['closing_date']) : null;
                        if ($closeTime && ($closeTime - time() < 86400 * 3) && $closeTime > time()) {
                            $isUrgent = true;
                        }
                    ?>
                        <div class="opp-row">
                            <div class="opp-main">
                                <div class="opp-title"><?= htmlspecialchars($p['title']) ?></div>
                                <div class="opp-meta">
                                    <span><i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($p['philgeps_ref_no'] ?: 'SLSU-BAC') ?></span>
                                    <span><i class="bi bi-briefcase"></i> <?= htmlspecialchars($p['procurement_mode'] ?: 'Public Bidding') ?></span>
                                    <?php if ($p['closing_date']): ?>
                                        <span><i class="bi bi-calendar-x"></i> Closes: <?= date('M j, Y', strtotime($p['closing_date'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="opp-side">
                                <div class="opp-abc">₱<?= number_format((float)$p['abc'], 2) ?></div>
                                <?php if ($isUrgent): ?>
                                    <span class="opp-status closing-soon"><i class="bi bi-hourglass-split"></i> Closing Soon</span>
                                <?php else: ?>
                                    <span class="opp-status open"><i class="bi bi-check-circle"></i> Open</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding:36px 20px; text-align:center; color:#88968d;">
                        <i class="bi bi-inbox" style="font-size:32px; display:block; margin-bottom:8px;"></i>
                        <p style="font-size:13px; font-weight:600;">No active public procurements at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 2. Upcoming Bid Schedule -->
           

        </div>

        <!-- ── RIGHT COLUMN: Quick Actions, Notifications, Guide ── -->
        <div>

            <!-- 1. Quick Actions -->
            <div class="dash-card">
                <div class="dash-card-head">
                    <div class="dash-card-title">
                        <i class="bi bi-lightning-charge-fill" style="color:#ffc107;"></i>
                        <span>Quick Actions</span>
                    </div>
                </div>
                <div class="qa-grid">
                    <a href="bidder-registration.php" class="qa-btn">
                        <div class="qa-icon"><i class="bi bi-person-plus"></i></div>
                        <div class="qa-label">Register as Bidder</div>
                    </a>
                    <a href="procurement.php" class="qa-btn">
                        <div class="qa-icon"><i class="bi bi-folder2-open"></i></div>
                        <div class="qa-label">Procurements</div>
                    </a>
                    <a href="settings.php" class="qa-btn">
                        <div class="qa-icon"><i class="bi bi-gear"></i></div>
                        <div class="qa-label">Account Settings</div>
                    </a>
                </div>
            </div>

            <!-- 2. Recent Notices & Bulletins -->
           
            <!-- 3. Supplier Accreditation Checklist Guide -->
            <div class="dash-card">
                <div class="dash-card-head">
                    <div class="dash-card-title">
                        <i class="bi bi-info-circle-fill" style="color:#06251b;"></i>
                        <span>Accreditation Steps</span>
                    </div>
                </div>
                <div class="guide-box">
                    <div class="guide-item">
                        <div class="guide-num">1</div>
                        <div class="guide-text"><strong>Fill Business Information:</strong> Enter your registered company details, PhilGEPS number, and TIN.</div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-num">2</div>
                        <div class="guide-text"><strong>Upload Legal Documents:</strong> Provide Mayor's Permit, DTI/SEC/CDA, and valid Government ID.</div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-num">3</div>
                        <div class="guide-text"><strong>BAC Secretariat Evaluation:</strong> Once validated, your account will be activated for electronic bidding.</div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
</main>

</body>
</html>
