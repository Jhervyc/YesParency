<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");

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
$total_pages = max(1, ceil($total_shown / $per_page));

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
        <form method="GET" action="" class="ap2-controls mb-16">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by name, username, or business..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="ap2-filters">
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

        <?php if ($total_shown === 0): ?>
            <div class="empty-state empty-state--lg">
                <i class="bi bi-people"></i>
                <p>No bidders found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.</p>
            </div>
        <?php else: ?>
            <?php while ($row = $result->fetch_assoc()):
                $appSt    = $row['application_status'] ?? null;
                $isPending = $appSt === 'pending';
                $badgeClass = match($appSt) {
                    'approved' => 'ap2-status-badge--approved',
                    'pending'  => 'ap2-status-badge--pending',
                    'rejected' => 'ap2-status-badge--rejected',
                    default    => 'ap2-status-badge--default',
                };
                $businessName = $row['business_name'] ?? 'No business profile';
                $initials     = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1));
                $avatarUrl    = !empty($row['profile_picture_url']) ? '../'.ltrim($row['profile_picture_url'],'/') : '';
                $safeFullName = htmlspecialchars(addslashes($row['firstname'].' '.$row['lastname']));
                $safeUsername = htmlspecialchars(addslashes($row['username']));
            ?>
            <div class="ap2-user-row <?= $isPending ? 'bsv-row-pending' : '' ?>">

                <div class="ap2-who-cell">
                    <div class="ap2-avatar ap2-avatar--<?= $row['role'] ?> avatar-cover">
                        <?php if (!empty($avatarUrl)): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>">
                        <?php else: ?>
                            <?= htmlspecialchars($initials) ?>
                        <?php endif; ?>
                    </div>
                    <div class="ap2-user-text">
                        <div class="ap2-user-name"><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></div>
                        <div class="ap2-user-sub">
                            @<?= htmlspecialchars($row['username']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-building fz-10"></i> <?= htmlspecialchars($businessName) ?>
                            &nbsp;·&nbsp;
                            <?= htmlspecialchars($row['email']) ?>
                        </div>
                    </div>
                </div>

                <div class="ap2-action-cell">
                    <span class="ap2-badge <?= $badgeClass ?>">
                        <?= strtoupper($appSt ?? 'UNKNOWN') ?>
                    </span>
                    <?php if ($isPending): ?>
                        <span class="ap2-self-label">
                            <i class="bi bi-hourglass-split"></i> Pending
                        </span>
                    <?php endif; ?>
                    <a href="bidder-profile.php?id=<?= $row['user_id'] ?>" class="proc-action-btn btn-view no-underline">
                        <i class="bi bi-eye"></i> View Profile
                    </a>
                </div>

            </div>
            <?php endwhile; ?>
        <?php endif; ?>

        <div class="ap2-card-foot ap2-card-foot--flex">
            <div>
                Showing <strong><?= min($total_shown, $offset+1) ?></strong>–<strong><?= min($total_shown, $offset+$per_page) ?></strong>
                of <strong><?= number_format($total_shown) ?></strong> bidder<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>
            <?php if ($total_pages > 1):
                $qs = array_filter(['search'=>$search, 'status'=>$status_filter!=='all'?$status_filter:null]);
                $qstr = $qs ? '&'.http_build_query($qs) : '';
            ?>
            <div class="pagination">
                <a href="?page=<?= max(1,$page-1) ?><?= $qstr ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="?page=<?= $p ?><?= $qstr ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qstr ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
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

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
