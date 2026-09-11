<?php
include("utils/protect-page.php");
require_once(__DIR__ . "/../utils/procurement_mode_helper.php");

$bidder_id = intval($_SESSION['user_id']);

// 1. Fetch All Bids by this Bidder
$sql = "
    SELECT 
        b.id AS bid_id,
        b.bid_type,
        b.submission_date,
        b.status AS bid_status,
        p.id AS procurement_id,
        p.title AS procurement_title,
        p.slsu_ref_no,
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
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/bidder-my-bids.css">
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
    <div class="page-header page-header--split">
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
    <div class="ap2-stats ap2-stats-4 mb-20">
        <!-- Total Proposals -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%">
                <div class="ap2-ring-inner"><i class="bi bi-inbox-fill"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Total Proposals</div>
            </div>
        </div>

        <!-- Pending Review -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#f59e0b; --pct:<?= $pct_pending ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-amber"><?= number_format($stats['pending']) ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>

        <!-- Verified / Active -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#10b981; --pct:<?= $pct_verified ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-shield-check"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-emerald"><?= number_format($stats['submitted'] + $stats['opened']) ?></div>
                <div class="ap2-stat-lbl">Verified / Active</div>
            </div>
        </div>

        <!-- Awarded Contracts -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#1f7a3d; --pct:<?= $pct_awarded ?>%">
                <div class="ap2-ring-inner"><i class="bi bi-trophy-fill"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num clr-forest"><?= number_format($stats['awarded']) ?></div>
                <div class="ap2-stat-lbl">Awarded</div>
            </div>
        </div>
    </div>

    <!-- ── Proposals List Panel (Matching admin/bid_opening.php panel & controls) ── -->
    <div class="proc-table-panel mb-24">

        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" id="bidSearchInput"
                    placeholder="Search by proposal title or SLSU reference..."
                    oninput="handleSearch()"
                    onkeydown="if(event.key==='Enter'){event.preventDefault(); handleSearch();}">
            </div>
            <div class="filter-status-group">
                <button type="button" class="ap2-filter-btn active" onclick="filterBids('all', this)">All</button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('pending', this)">Pending</button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('submitted', this)">Verified</button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('opened', this)">Opened</button>
                <button type="button" class="ap2-filter-btn" onclick="filterBids('awarded', this)">Awarded</button>
                <?php if ($stats['rejected'] > 0): ?>
                    <button type="button" class="ap2-filter-btn" onclick="filterBids('rejected', this)">Rejected</button>
                <?php endif; ?>
            </div>
            <select id="sortSelect" onchange="handleSort()" class="filter-dropdowns sort-select">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="highest_abc">Highest Value</option>
                <option value="lowest_abc">Lowest Value</option>
            </select>
            <button type="button" class="ap2-go-btn" onclick="handleSearch()">
                <i class="bi bi-search"></i> Search
            </button>
        </div>

        <!-- Proposals List Content -->
        <?php if (empty($my_bids)): ?>

            <div class="empty-state empty-state--pad">
                <i class="bi bi-inbox empty-state-icon"></i>
                <p class="empty-state-title">No Bid Proposals Submitted Yet</p>
                <p class="empty-state-desc">You haven't participated in any procurement opportunities yet.</p>
                <a href="procurement.php" class="vp-back-link vp-back-link--flush">
                    Browse Open Opportunities
                </a>
            </div>

        <?php else: ?>

            <div id="bidsListContainer" class="bids-list-container">
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
                    $status_class = in_array($s, ['pending','submitted','opened','awarded','rejected']) ? $s : 'default';
                    $title_text = $bid['procurement_title'];
                    $ref_text   = $bid['slsu_ref_no'] ?? 'N/A';
                    $total_abc  = (float)$bid['total_bid_abc'];
                    $date_time  = strtotime($bid['submission_date']);
                ?>

                    <div class="bid-accordion-item"
                         id="bidItem-<?= $bid['bid_id'] ?>"
                         data-status="<?= htmlspecialchars($s) ?>"
                         data-search="<?= htmlspecialchars(strtolower($title_text . ' ' . $ref_text)) ?>"
                         data-date="<?= $date_time ?>"
                         data-abc="<?= $total_abc ?>">

                        <!-- Compact Header Row (Dropdown Toggle) -->
                        <div class="bid-row-header" onclick="toggleBidAccordion(<?= $bid['bid_id'] ?>, event)">
                            <div class="bid-row-left">
                                <!-- Action Avatar -->
                                <div class="bid-row-avatar bid-row-avatar--<?= $status_class ?>">
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
                                            <i class="bi bi-copy bid-copy-icon"></i>
                                        </span>

                                        <?php if (($bid['bid_type'] ?? 'bid') === 'quotation'): ?>
                                        <span class="quotation-tag">
                                            <i class="bi bi-file-earmark-text-fill"></i> Quotation
                                        </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="bid-row-meta">
                                        <span><i class="bi bi-layers"></i> Applied: <strong><?= count($bid['lots']) ?> <?= count($bid['lots']) === 1 ? 'Lot' : 'Lots' ?></strong></span>
                                        <span><i class="bi bi-cash-stack"></i> Total ABC: <strong>₱<?= number_format($total_abc, 2) ?></strong></span>
                                        <span><i class="bi bi-clock"></i> Submitted: <?= date("M j, Y · g:i A", $date_time) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="bid-row-right">
                                <span class="bid-status-pill bid-status-pill--<?= $status_class ?>">
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
                                        <span><i class="bi bi-layers-fill clr-forest"></i> Applied Project Lots</span>
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
                                            <div class="text-italic-muted">No lots recorded.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Submitted Documents Panel -->
                                <div class="bid-panel-box">
                                    <div class="bid-panel-head">
                                        <span><i class="bi bi-shield-lock-fill clr-forest"></i> Submitted Package &amp; Files</span>
                                        <strong><?= count($bid['documents']) ?> <?= count($bid['documents']) === 1 ? 'File' : 'Files' ?></strong>
                                    </div>

                                    <div class="bid-doc-list">
                                        <?php if (!empty($bid['documents'])): ?>
                                            <?php foreach ($bid['documents'] as $doc):
                                                $isReceipt = ($doc['document_type'] === 'other');
                                                $docIcon   = $isReceipt ? 'bi-receipt' : ($doc['document_type'] === 'eligibility' ? 'bi-file-earmark-check-fill' : 'bi-file-earmark-bar-graph-fill');
                                                $docIconClass = $isReceipt ? 'clr-amber' : ($doc['document_type'] === 'eligibility' ? 'clr-forest' : 'clr-blue');
                                            ?>
                                                <div class="bid-doc-item">
                                                    <div class="bid-doc-left">
                                                        <i class="bi <?= $docIcon ?> bid-doc-icon <?= $docIconClass ?>"></i>
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
                                            <div class="text-italic-muted">No documents recorded.</div>
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

                                <div class="dropdown-actions-links">
                                    <a href="view_procurement.php?id=<?= $bid['procurement_id'] ?>" class="vp-back-link vp-back-link--sm" onclick="event.stopPropagation();">
                                        View Full Procurement Details <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                    </div>

                <?php endforeach; ?>
            </div>

            <!-- Empty Search Filter Match State -->
            <div class="empty-state empty-state--pad" id="noFilterMatchMsg" hidden>
                <i class="bi bi-search empty-state-icon"></i>
                <p class="empty-state-title">No Matching Proposals Found</p>
                <p class="empty-state-desc">We couldn't find any proposals matching your current search or status filter.</p>
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
    <div class="toast-alert success fixed-toast fixed-toast--success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <span><?= htmlspecialchars($_SESSION['alert_success']) ?></span>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error fixed-toast fixed-toast--error" id="toastAlert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= htmlspecialchars($_SESSION['alert_error']) ?></span>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<!-- Copy Notification Toast -->
<div id="copyToast" class="fixed-toast fixed-toast--success" hidden>
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
