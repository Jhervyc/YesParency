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
               p.id AS proc_id, p.title AS proc_title, p.slsu_ref_no, p.abc,
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
            'slsu_ref_no' => 'SLSU-BAC-2026-003',
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
            'slsu_ref_no' => 'SLSU-BAC-2026-007',
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
            'slsu_ref_no' => 'SLSU-BAC-2026-011',
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
    <!-- Custom CSS: base -> shared components -> page-specific -->
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/pages/index.css">

</head>
<body class="index-page">

<!-- ========================= -->
<!-- NAVBAR                    -->
<!-- ========================= -->
<?php require_once __DIR__ . '/includes/navbar.php'; render_public_navbar('home', $user_logged_in, $user_dashboard_link); ?>

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
        <div class="hero-ranking-card hero-ranking-card--live">
            <!-- Live header -->
            <div class="ranking-card-head ranking-card-head--live">
                <div class="ranking-head-left">
                    <div class="ranking-fire-icon ranking-fire-icon--live">
                        <i class="bi bi-broadcast-pin"></i>
                    </div>
                    <div>
                        <h3 class="live-session-heading">Current Live Session</h3>
                        <p class="live-session-subheading">Bid opening in progress</p>
                    </div>
                </div>
                <div class="live-status-pill">
                    <span class="ranking-pulse-dot"></span> Live
                </div>
            </div>

            <!-- Ref + Title -->
            <div class="live-session-info">
                <div class="live-session-ref">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($live_session_hero['slsu_ref_no'] ?: 'SLSU-BAC') ?>
                </div>
                <div class="live-session-title">
                    <?= htmlspecialchars($live_session_hero['proc_title']) ?>
                </div>
                <div class="live-session-meta">
                    <?= htmlspecialchars($live_session_hero['procurement_mode'] ?: 'Public Bidding') ?>
                    &nbsp;·&nbsp; ABC: <span class="live-session-abc">₱<?= number_format((float)$live_session_hero['abc'], 2) ?></span>
                </div>
            </div>

            <!-- Phase stepper -->
            <div class="phase-stepper">
                <?php
                $steps = ['Eligibility','Financial','Awarding'];
                foreach ($steps as $i => $s):
                    $done    = $cur_step > $i + 1;
                    $current = $cur_step == $i + 1;
                    $circleState = $done ? 'is-done' : ($current ? 'is-current' : '');
                    $labelState  = $done ? 'is-done' : ($current ? 'is-current' : '');
                ?>
                <div class="phase-step">
                    <div class="phase-step-circle <?= $circleState ?>">
                        <?= $done ? '<i class="bi bi-check2"></i>' : ($i + 1) ?>
                    </div>
                    <span class="phase-step-label <?= $labelState ?>">
                        <?= $s ?>
                    </span>
                    <?php if ($i < count($steps) - 1): ?>
                    <div class="phase-step-connector <?= $done ? 'is-done' : '' ?>"></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Stats row -->
            <div class="live-session-stats">
                <span><i class="bi bi-people live-stat-icon--mint"></i> <strong><?= (int)$live_session_hero['bid_count'] ?></strong> bids submitted</span>
                <span class="live-stat-sep">·</span>
                <span><i class="bi bi-boxes live-stat-icon--gold"></i> <strong><?= (int)$live_session_hero['lots_count'] ?></strong> lot<?= $live_session_hero['lots_count'] != 1 ? 's' : '' ?></span>
                <span class="live-stat-sep">·</span>
                <span><i class="bi bi-clock"></i> <?= $live_session_hero['started_at'] ? date('g:i A', strtotime($live_session_hero['started_at'])) : 'Just started' ?></span>
            </div>

            <!-- Join button -->
            <a href="live.php" class="btn-join-live">
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
                                <span class="ranked-ref-badge"><i class="bi bi-hash"></i> <?= htmlspecialchars($item['slsu_ref_no']) ?></span>
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
                    View All Procurement <i class="bi bi-arrow-right icon-14"></i>
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

    <div class="schedule-grid">
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
                        <i class="bi bi-circle-fill"></i> <?= ucfirst($statusStr) ?>
                    </span>
                    <span class="schedule-ref"><i class="bi bi-hash"></i> <?= htmlspecialchars($sched['slsu_ref_no'] ?: 'SLSU-BAC') ?></span>
                </div>

                <div class="schedule-title"><?= htmlspecialchars($sched['title']) ?></div>

                <div class="schedule-timeline-box">
                    <div class="timeline-row">
                        <span class="timeline-label"><i class="bi bi-calendar-check"></i> Bid Opening:</span>
                        <span class="timeline-val timeline-val--opening"><?= $openDate ?></span>
                    </div>
                    <div class="timeline-row">
                        <span class="timeline-label"><i class="bi bi-clock-history"></i> Submission Cutoff:</span>
                        <span class="timeline-val"><?= $closeDate ?></span>
                    </div>
                </div>
            </div>

            <div class="schedule-card-foot">
                <div>
                    <div class="schedule-abc-label">Approved Budget (ABC)</div>
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
    <div class="schedule-cta-wrap">
        <a href="bid_schedule.php" class="btn-view-all-cta">
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
            <p class="about-info-desc">
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

            <div class="about-cta-wrap">
                <a href="register.php" class="btn-hero-primary btn-hero-primary--block">
                    <i class="bi bi-person-check-fill"></i> Create Your User Account Today
                </a>
            </div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- FOOTER                    -->
<!-- ========================= -->
<?php require_once __DIR__ . '/includes/footer.php'; render_public_footer(); ?>

<!-- ── Active Link Highlighting (scroll-spy for the #home/#about anchors) ── -->
<!-- Navbar's own scrolled-background toggle lives in includes/navbar.php. -->
<script>
    window.addEventListener('scroll', () => {
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
