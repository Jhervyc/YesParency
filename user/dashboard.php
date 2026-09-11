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
        p.SLSU_ref_no,
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
    <!-- Dashboard styles -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <!-- Page-specific styles -->
    <link rel="stylesheet" href="../css/pages/user-dashboard.css">
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
            <span class="opp-status closing-soon opp-status--lg">
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
                        <i class="bi bi-folder2-open ic-dark"></i>
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
                                    <span><i class="bi bi-hash"></i> Ref: <?= htmlspecialchars($p['SLSU_ref_no'] ?: 'SLSU-BAC') ?></span>
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
                    <div class="dash-empty">
                        <i class="bi bi-inbox dash-empty-icon"></i>
                        <p class="dash-empty-text">No active public procurements at the moment.</p>
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
                        <i class="bi bi-lightning-charge-fill ic-gold"></i>
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
                        <i class="bi bi-info-circle-fill ic-dark"></i>
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
