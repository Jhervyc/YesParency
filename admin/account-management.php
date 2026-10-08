<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");
include(__DIR__ . "/../includes/pagination.php");

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_total    = $conn->query("SELECT COUNT(*) FROM bidder_profiles")->fetch_row()[0];
$stat_approved = $conn->query("SELECT COUNT(*) FROM bidder_profiles WHERE application_status = 'approved'")->fetch_row()[0];
$stat_pending  = $conn->query("SELECT COUNT(*) FROM bidder_profiles WHERE application_status = 'pending'")->fetch_row()[0];
$stat_rejected = $conn->query("SELECT COUNT(*) FROM bidder_profiles WHERE application_status = 'rejected'")->fetch_row()[0];

// ── Search + filter ───────────────────────────────────────────────────────────
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['all','pending','approved','rejected'])
                 ? $_GET['status'] : 'all';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

// ── Build query ───────────────────────────────────────────────────────────────
$where   = ["(u.role = 'bidder' OR (u.role = 'user' AND u.status = 'pending'))"];
$params  = [];
$types   = '';

if ($status_filter !== 'all') {
    $where[]  = "bp.application_status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR bp.business_name LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'ssss';
}

$wsql = "WHERE " . implode(' AND ', $where);

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM users u LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id $wsql");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, (int)ceil($total_shown / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Rows
$lp = array_merge($params, [$per_page, $offset]);
$lt = $types . 'ii';
$stmt = $conn->prepare("
    SELECT u.user_id, u.firstname, u.lastname, u.username, u.email, u.role, u.status, u.profile_picture_url,
           bp.business_name, bp.application_status
    FROM users u
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    $wsql
    ORDER BY bp.application_status ASC, u.firstname ASC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($lt, ...$lp);
$stmt->execute();
$result = $stmt->get_result();

// ── AJAX: return only the list fragment ──────────────────────────────────────
if (isset($_GET['ajax'])) {
    include __DIR__ . '/partials/account-management-list.php';
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bidder Accounts | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-account-management.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Bidder Accounts</h2>
        <p>Review and manage bidder registrations and pending applications.</p>
    </div>

    <!-- ── Stat cards ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4">
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%;">
                <div class="ap2-ring-inner"><i class="bi bi-people clr-dark"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Total Bidders</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#219653; --pct:<?= $stat_total > 0 ? round($stat_approved/$stat_total*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-patch-check clr-green"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_approved ?></div>
                <div class="ap2-stat-lbl">Approved</div>
            </div>
        </div>
        <div class="ap2-stat <?= $stat_pending > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="--ring-color:<?= $stat_pending > 0 ? '#e67e22' : '#8B958E' ?>; --pct:<?= $stat_total > 0 ? round($stat_pending/$stat_total*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split <?= $stat_pending > 0 ? 'clr-amber' : 'clr-idle' ?>"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num <?= $stat_pending > 0 ? 'stat-num--warn' : '' ?>"><?= $stat_pending ?></div>
                <div class="ap2-stat-lbl">Pending</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#c23b3b; --pct:<?= $stat_total > 0 ? round($stat_rejected/$stat_total*100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-x-circle clr-red"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_rejected ?></div>
                <div class="ap2-stat-lbl">Rejected</div>
            </div>
        </div>
    </div>

    <!-- ── Bidder list panel ── -->
    <div class="ap2-card">

        <div class="ap2-card-head">
            <h3>Bidder Directory</h3>
        </div>

        <!-- ── Search + filter ── -->
        <form method="GET" action="" id="accountFilterForm" class="ap2-controls mb-16">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by name, username, or business..."
                    enterkeyhint="search" autocomplete="off"
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="ap2-filters filter-chips">
                <?php foreach (['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $val => $lbl): ?>
                    <button type="submit" name="status" value="<?= $val ?>"
                            class="ap2-filter-btn <?= $status_filter === $val ? 'active' : '' ?>">
                        <?= $lbl ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="ap2-go-btn">
                <i class="bi bi-search"></i> Search
            </button>
        </form>

        <!-- Column header -->
        <div class="ap2-table-head">
            <span>Bidder</span>
            <span>Actions</span>
        </div>

        <div id="accountListRoot" class="ajax-list-root">
            <?php include __DIR__ . '/partials/account-management-list.php'; ?>
        </div>

    </div><!-- /.ap2-card -->

</div>
</main>

<!-- Toasts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<script src="../js/ajax-list.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

    function toggleSidebar() {
        if (window.innerWidth <= 768) { sidebar.classList.toggle('mobile-open'); overlay.classList.toggle('active'); }
        else { document.body.classList.toggle('sidebar-collapsed'); }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    AjaxList.init({
        root:     '#accountListRoot',
        form:     '#accountFilterForm',
        endpoint: 'account-management.php',
    });

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
