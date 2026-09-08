<?php
include("utils/protect-page.php");

// ── Only audit database / data modifications (exclude authentication events like LOGIN/LOGOUT) ──
$mod_condition = "al.action NOT IN ('LOGIN', 'LOGOUT', 'AUTH')";

// ── Filters ───────────────────────────────────────────────────────────────────
$action_filter = isset($_GET['action']) ? strtoupper(trim($_GET['action'])) : 'all';
$module_filter = isset($_GET['module']) ? strtolower(trim($_GET['module'])) : 'all';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

$valid_actions = ['CREATE', 'UPDATE', 'DELETE'];
$valid_modules = ['procurements', 'bids', 'lots', 'users', 'announcements', 'settings', 'bid_opening'];

if ($action_filter !== 'all' && !in_array($action_filter, $valid_actions)) $action_filter = 'all';
if ($module_filter !== 'all' && !in_array($module_filter, $valid_modules)) $module_filter = 'all';

// ── Stats (Database Modifications) ───────────────────────────────────────────
$stat_total_res = $conn->query("SELECT COUNT(*) FROM audit_logs al WHERE $mod_condition");
$stat_total     = intval($stat_total_res ? $stat_total_res->fetch_row()[0] : 0);

$stat_create_res = $conn->query("SELECT COUNT(*) FROM audit_logs al WHERE (al.action IN ('CREATE', 'INSERT') OR al.action LIKE '%_CREATED' OR al.action LIKE '%SUBMITTED' OR al.action LIKE '%INVITED' OR al.action LIKE '%RECORDED') AND $mod_condition");
$stat_create     = intval($stat_create_res ? $stat_create_res->fetch_row()[0] : 0);

$stat_update_res = $conn->query("SELECT COUNT(*) FROM audit_logs al WHERE (al.action IN ('UPDATE', 'PUBLISH', 'APPROVE', 'REJECT', 'TOGGLE_MAINTENANCE') OR al.action LIKE '%_UPDATED' OR al.action LIKE '%_CHANGED' OR al.action LIKE '%_APPROVED' OR al.action LIKE '%_REJECTED' OR al.action LIKE '%_STARTED' OR al.action LIKE '%_ACTIVATED' OR al.action LIKE '%_DEACTIVATED' OR al.action LIKE '%_REORDERED') AND $mod_condition");
$stat_update     = intval($stat_update_res ? $stat_update_res->fetch_row()[0] : 0);

$stat_delete_res = $conn->query("SELECT COUNT(*) FROM audit_logs al WHERE (al.action = 'DELETE' OR al.action LIKE '%_DELETED' OR al.action LIKE '%_CANCELLED' OR al.action LIKE '%_REMOVED') AND $mod_condition");
$stat_delete     = intval($stat_delete_res ? $stat_delete_res->fetch_row()[0] : 0);

// ── Query Construction ────────────────────────────────────────────────────────
$where_parts = [$mod_condition];
$params      = [];
$types       = '';

if ($action_filter !== 'all') {
    if ($action_filter === 'CREATE') {
        $where_parts[] = "(al.action IN ('CREATE', 'INSERT') OR al.action LIKE '%_CREATED' OR al.action LIKE '%SUBMITTED' OR al.action LIKE '%INVITED' OR al.action LIKE '%RECORDED')";
    } elseif ($action_filter === 'UPDATE') {
        $where_parts[] = "(al.action IN ('UPDATE', 'PUBLISH', 'APPROVE', 'REJECT', 'TOGGLE_MAINTENANCE') OR al.action LIKE '%_UPDATED' OR al.action LIKE '%_CHANGED' OR al.action LIKE '%_APPROVED' OR al.action LIKE '%_REJECTED' OR al.action LIKE '%_STARTED' OR al.action LIKE '%_ACTIVATED' OR al.action LIKE '%_DEACTIVATED' OR al.action LIKE '%_REORDERED')";
    } elseif ($action_filter === 'DELETE') {
        $where_parts[] = "(al.action = 'DELETE' OR al.action LIKE '%_DELETED' OR al.action LIKE '%_CANCELLED' OR al.action LIKE '%_REMOVED')";
    }
}

if ($module_filter !== 'all') {
    $where_parts[] = 'al.module = ?';
    $params[]      = $module_filter;
    $types        .= 's';
}

if ($search !== '') {
    $like          = '%' . $search . '%';
    $where_parts[] = '(al.description LIKE ? OR u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR al.record_id LIKE ?)';
    $params[]      = $like;
    $params[]      = $like;
    $params[]      = $like;
    $params[]      = $like;
    $params[]      = $like;
    $types        .= 'sssss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// Total matching records
$count_sql  = "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id $where_sql";
$count_stmt = mysqli_prepare($conn, $count_sql);
if ($params) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$total_shown = intval(mysqli_stmt_get_result($count_stmt)->fetch_row()[0] ?? 0);
$total_pages = max(1, ceil($total_shown / $per_page));

// Paged query
$main_sql = "
    SELECT al.log_id, al.action, al.module, al.record_id, al.description,
           al.old_values, al.new_values, al.ip_address, al.user_agent, al.created_at,
           u.user_id, u.username, u.firstname, u.lastname, u.role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
";
$params_paged = array_merge($params, [$per_page, $offset]);
$types_paged  = $types . 'ii';
$stmt         = mysqli_prepare($conn, $main_sql);
if ($params_paged) mysqli_stmt_bind_param($stmt, $types_paged, ...$params_paged);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Prepare records in an array for rendering and side-drawer detail view
$logs_data = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        /* Drawer and detail specific additions */
        .at-drawer-section {
            background: #fff;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 14px;
            border: 1px solid #eaeeec;
            box-shadow: 0 1px 4px rgba(0,0,0,.03);
        }
        .at-drawer-section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #9aa8a1;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .at-drawer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }
        .at-drawer-field label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #9aa8a1;
            margin-bottom: 2px;
        }
        .at-drawer-field .val {
            font-size: 13px;
            font-weight: 600;
            color: #182019;
            word-break: break-word;
        }
        .at-json-box {
            background: #f4f7f5;
            border: 1px solid #e2e9e5;
            border-radius: 10px;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 11.5px;
            color: #1f3a2c;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 220px;
            overflow-y: auto;
        }
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
    </style>
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- MAIN -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Audit Trail</h2>
        <p>Review database modifications, created records, updates, and deletions across all system modules.</p>
    </div>

    <!-- ── Stat cards (matching account-management.php) ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4" style="margin-bottom:20px;">
        <!-- Total Modifications -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-database-check" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Modifications</div>
            </div>
        </div>
        <!-- Created / Added -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% <?= $stat_total > 0 ? round($stat_create / $stat_total * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-plus-circle" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#219653;"><?= number_format($stat_create) ?></div>
                <div class="ap2-stat-lbl">Added / Created</div>
            </div>
        </div>
        <!-- Updated / Modified -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#2F6FED 0% <?= $stat_total > 0 ? round($stat_update / $stat_total * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-pencil-square" style="color:#2F6FED;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#2F6FED;"><?= number_format($stat_update) ?></div>
                <div class="ap2-stat-lbl">Updated / Modified</div>
            </div>
        </div>
        <!-- Deleted / Removed -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#c23b3b 0% <?= $stat_total > 0 ? round($stat_delete / $stat_total * 100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-trash3" style="color:#c23b3b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:#c23b3b;"><?= number_format($stat_delete) ?></div>
                <div class="ap2-stat-lbl">Deleted</div>
            </div>
        </div>
    </div>

    <!-- ── Log list panel (matching account-management.php) ── -->
    <div class="sp-panel sp-list-panel">
        <!-- ── Search + filter bar ── -->
        <form method="GET" action="audit_trail.php" class="ap2-controls" style="margin-bottom:16px;">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                    placeholder="Search by description, record ID, or user..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>

            <!-- Action Filters -->
            <div class="ap2-filters">
                <?php
                $action_tabs = [
                    'all'    => 'All Actions',
                    'CREATE' => 'Created',
                    'UPDATE' => 'Updated',
                    'DELETE' => 'Deleted'
                ];
                foreach ($action_tabs as $val => $lbl):
                ?>
                    <button type="submit" name="action" value="<?= $val ?>"
                            class="ap2-filter-btn <?= $action_filter === $val ? 'active' : '' ?>">
                        <?= $lbl ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Module dropdown filter -->
            <div class="module-select-wrap">
                <select name="module" onchange="this.form.submit()">
                    <option value="all" <?= $module_filter === 'all' ? 'selected' : '' ?>>All Modules</option>
                    <option value="procurements" <?= $module_filter === 'procurements' ? 'selected' : '' ?>>Procurements</option>
                    <option value="bids" <?= $module_filter === 'bids' ? 'selected' : '' ?>>Bids</option>
                    <option value="lots" <?= $module_filter === 'lots' ? 'selected' : '' ?>>Lots</option>
                    <option value="users" <?= $module_filter === 'users' ? 'selected' : '' ?>>Users</option>
                    <option value="announcements" <?= $module_filter === 'announcements' ? 'selected' : '' ?>>Announcements</option>
                    <option value="settings" <?= $module_filter === 'settings' ? 'selected' : '' ?>>Settings</option>
                    <option value="bid_opening" <?= $module_filter === 'bid_opening' ? 'selected' : '' ?>>Bid Opening</option>
                </select>
            </div>

            <button type="submit" class="ap2-go-btn">
                <i class="bi bi-search"></i> Search
            </button>
        </form>

        <?php if ($total_shown === 0): ?>
            <div class="empty-state" style="padding:48px;">
                <i class="bi bi-database-exclamation"></i>
                <p>No audit trail records found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
            </div>
        <?php else: ?>

        <div class="proc-table-list">
        <?php while ($row = $result->fetch_assoc()):
            $logs_data[$row['log_id']] = $row;
            $act = strtoupper($row['action']);

            // Left bar and styling based on database modification type
            $barColor = '#8B958E';
            $pillBg   = '#EEF0ED';
            $pillFg   = '#8B958E';
            $iconBg   = '#f0f4f2';
            $iconFg   = '#06251b';
            $icon     = 'bi-database';

            $is_create = in_array($act, ['CREATE', 'INSERT']) || str_ends_with($act, '_CREATED') || str_ends_with($act, 'SUBMITTED') || str_ends_with($act, 'INVITED') || str_ends_with($act, 'RECORDED');
            $is_update = in_array($act, ['UPDATE', 'PUBLISH', 'APPROVE', 'REJECT', 'TOGGLE_MAINTENANCE']) || str_ends_with($act, '_UPDATED') || str_ends_with($act, '_CHANGED') || str_ends_with($act, '_APPROVED') || str_ends_with($act, '_REJECTED') || str_ends_with($act, '_STARTED') || str_ends_with($act, '_ACTIVATED') || str_ends_with($act, '_DEACTIVATED') || str_ends_with($act, '_REORDERED');
            $is_delete = $act === 'DELETE' || str_ends_with($act, '_DELETED') || str_ends_with($act, '_CANCELLED') || str_ends_with($act, '_REMOVED');

            if ($is_create) {
                $barColor = '#219653';
                $pillBg   = '#D9F2DF';
                $pillFg   = '#1f7a3d';
                $iconBg   = '#E4F5EA';
                $iconFg   = '#219653';
                $icon     = 'bi-plus-circle';
            } elseif ($is_update) {
                $barColor = '#2F6FED';
                $pillBg   = '#E7EEFE';
                $pillFg   = '#2F6FED';
                $iconBg   = '#E7EEFE';
                $iconFg   = '#2F6FED';
                $icon     = 'bi-pencil-square';
            } elseif ($is_delete) {
                $barColor = '#c23b3b';
                $pillBg   = '#FBE1E1';
                $pillFg   = '#c23b3b';
                $iconBg   = '#FBE1E1';
                $iconFg   = '#c23b3b';
                $icon     = 'bi-trash3';
            }

            $actorName = $row['username']
                ? ($row['firstname'] . ' ' . $row['lastname'])
                : 'System / Automated';
            $usernameStr = $row['username'] ? '@' . $row['username'] : 'System';
            $timeStr     = date('M j, Y · g:i A', strtotime($row['created_at']));
            $moduleName  = ucfirst($row['module']);
        ?>
            <div class="proc-row">
                <div class="proc-row-status-bar" style="background:<?= $barColor ?>"></div>

                <div class="proc-row-body">
                    <div class="proc-row-main" style="display:flex; align-items:center; gap:14px;">
                        <!-- Action Icon Avatar -->
                        <div class="ap2-avatar"
                             style="width:40px; height:40px; border-radius:11px; flex-shrink:0; background:<?= $iconBg ?>; color:<?= $iconFg ?>; display:flex; align-items:center; justify-content:center; font-size:16px;">
                            <i class="bi <?= $icon ?>"></i>
                        </div>

                        <div style="min-width:0;">
                            <div class="proc-row-title">
                                <?= htmlspecialchars($row['description']) ?>
                            </div>
                            <div class="proc-row-meta">
                                <span><i class="bi bi-person"></i><?= htmlspecialchars($actorName) ?> (<?= htmlspecialchars($usernameStr) ?>)</span>
                                <span><i class="bi bi-folder2-open"></i><?= htmlspecialchars($moduleName) ?><?= $row['record_id'] ? ' #' . intval($row['record_id']) : '' ?></span>
                                <span><i class="bi bi-clock"></i><?= $timeStr ?></span>
                                <span><i class="bi bi-hdd-network"></i><?= htmlspecialchars($row['ip_address'] ?? '—') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="proc-row-actions">
                        <span class="sp-status-pill" style="background:<?= $pillBg ?>; color:<?= $pillFg ?>">
                            <?= htmlspecialchars($act) ?>
                        </span>
                        <button type="button" class="proc-action-btn review" onclick="loadLogDetail(<?= $row['log_id'] ?>)">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
        </div>

        <?php endif; ?>

        <!-- Footer / Pagination -->
        <div class="sp-list-foot" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div>
                Showing <?= number_format(min($total_shown, $offset + 1)) ?>–<?= number_format(min($total_shown, $offset + $per_page)) ?> of <?= number_format($total_shown) ?> record<?= $total_shown != 1 ? 's' : '' ?>
                <?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <?php
                $qp = array_filter([
                    'action' => $action_filter !== 'all' ? $action_filter : null,
                    'module' => $module_filter !== 'all' ? $module_filter : null,
                    'search' => $search ?: null
                ]);
                $qs = $qp ? '?' . http_build_query($qp) . '&' : '?';
                ?>
                <div style="display:inline-flex; gap:5px;">
                    <a href="<?= $qs ?>page=<?= max(1, $page - 1) ?>"
                       class="ap2-filter-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                       style="padding:6px 12px; font-size:12px; text-decoration:none; <?= $page <= 1 ? 'opacity:0.4; pointer-events:none;' : '' ?>">
                        <i class="bi bi-chevron-left"></i> Prev
                    </a>
                    <?php
                    $sp = max(1, min($page - 2, $total_pages - 4));
                    $ep = min($total_pages, $sp + 4);
                    for ($p = $sp; $p <= $ep; $p++):
                    ?>
                        <a href="<?= $qs ?>page=<?= $p ?>"
                           class="ap2-filter-btn <?= $p === $page ? 'active' : '' ?>"
                           style="padding:6px 12px; font-size:12px; text-decoration:none;">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                    <a href="<?= $qs ?>page=<?= min($total_pages, $page + 1) ?>"
                       class="ap2-filter-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"
                       style="padding:6px 12px; font-size:12px; text-decoration:none; <?= $page >= $total_pages ? 'opacity:0.4; pointer-events:none;' : '' ?>">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.sp-panel.sp-list-panel -->

</div>
</main>

<!-- ── SIDE DRAWER (Matching account-management.php structure) ── -->
<div id="drawerOverlay" onclick="closeModal()"></div>
<div id="sideModal">
    <div class="smd-header">
        <div class="smd-header-left">
            <div class="smd-header-icon"><i class="bi bi-journal-check"></i></div>
            <div>
                <div class="smd-header-title">Log Record Details</div>
                <div class="smd-header-sub">Database modification audit info</div>
            </div>
        </div>
        <button class="smd-close" onclick="closeModal()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div id="modalContent" class="smd-body">
        <div class="smd-placeholder">
            <div class="smd-placeholder-icon"><i class="bi bi-journal-text"></i></div>
            <p>Select an audit entry from the list<br>to view full modification details.</p>
        </div>
    </div>
</div>

<script>
    // Cached logs payload for client-side instant drawer render
    const logsPayload = <?= json_encode($logs_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function formatJSON(str) {
        if (!str || str === 'null') return null;
        try {
            const obj = typeof str === 'string' ? JSON.parse(str) : str;
            return JSON.stringify(obj, null, 2);
        } catch(e) {
            return str;
        }
    }

    function loadLogDetail(logId) {
        const log = logsPayload[logId];
        const content = document.getElementById('modalContent');
        if (!log) {
            content.innerHTML = '<div class="smd-placeholder"><div class="smd-placeholder-icon"><i class="bi bi-exclamation-circle"></i></div><p>Log details not found.</p></div>';
            return;
        }

        const act = (log.action || '').toUpperCase();
        let pillBg = '#EEF0ED', pillFg = '#8B958E';
        const isCreate = ['CREATE', 'INSERT'].includes(act) || act.endsWith('_CREATED') || act.endsWith('SUBMITTED') || act.endsWith('INVITED') || act.endsWith('RECORDED');
        const isUpdate = ['UPDATE', 'PUBLISH', 'APPROVE', 'REJECT', 'TOGGLE_MAINTENANCE'].includes(act) || act.endsWith('_UPDATED') || act.endsWith('_CHANGED') || act.endsWith('_APPROVED') || act.endsWith('_REJECTED') || act.endsWith('_STARTED') || act.endsWith('_ACTIVATED') || act.endsWith('_DEACTIVATED') || act.endsWith('_REORDERED');
        const isDelete = act === 'DELETE' || act.endsWith('_DELETED') || act.endsWith('_CANCELLED') || act.endsWith('_REMOVED');

        if (isCreate) {
            pillBg = '#D9F2DF'; pillFg = '#1f7a3d';
        } else if (isUpdate) {
            pillBg = '#E7EEFE'; pillFg = '#2F6FED';
        } else if (isDelete) {
            pillBg = '#FBE1E1'; pillFg = '#c23b3b';
        }

        const actor = log.username
            ? `${log.firstname || ''} ${log.lastname || ''} <span style="color:#9aa8a1">(@${log.username})</span>`
            : '<span style="color:#9aa8a1">System / Automated</span>';

        const logDate = new Date(log.created_at).toLocaleString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });

        const oldJson = formatJSON(log.old_values);
        const newJson = formatJSON(log.new_values);

        let html = `
            <!-- Action & Module Overview -->
            <div class="at-drawer-section">
                <div class="at-drawer-section-title"><i class="bi bi-info-circle"></i> Change Overview</div>
                <div class="at-drawer-grid">
                    <div class="at-drawer-field">
                        <label>Action</label>
                        <div class="val">
                            <span class="sp-status-pill" style="background:${pillBg}; color:${pillFg}">${act}</span>
                        </div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Module</label>
                        <div class="val" style="text-transform:capitalize;">${log.module}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Record ID</label>
                        <div class="val">#${log.record_id || 'N/A'}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Log ID</label>
                        <div class="val">#${log.log_id}</div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="at-drawer-section">
                <div class="at-drawer-section-title"><i class="bi bi-card-text"></i> Summary / Description</div>
                <div style="font-size:13.5px; font-weight:600; color:#06251b; line-height:1.5;">
                    ${log.description}
                </div>
            </div>

            <!-- Actor & Device Info -->
            <div class="at-drawer-section">
                <div class="at-drawer-section-title"><i class="bi bi-person-gear"></i> Performed By & Network</div>
                <div class="at-drawer-grid">
                    <div class="at-drawer-field" style="grid-column:1 / -1;">
                        <label>Actor</label>
                        <div class="val">${actor}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Timestamp</label>
                        <div class="val" style="font-size:12px;">${logDate}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>IP Address</label>
                        <div class="val" style="font-size:12px;">${log.ip_address || '—'}</div>
                    </div>
                    ${log.user_agent ? `
                    <div class="at-drawer-field" style="grid-column:1 / -1;">
                        <label>User Agent</label>
                        <div class="val" style="font-size:11px; color:#6C776E;">${log.user_agent}</div>
                    </div>` : ''}
                </div>
            </div>
        `;

        // Before & After JSON Diffs
        if (oldJson) {
            html += `
                <div class="at-drawer-section">
                    <div class="at-drawer-section-title"><i class="bi bi-clock-history"></i> Previous State (Old Values)</div>
                    <div class="at-json-box">${oldJson}</div>
                </div>
            `;
        }

        if (newJson) {
            html += `
                <div class="at-drawer-section">
                    <div class="at-drawer-section-title"><i class="bi bi-check2-circle"></i> Modified / New State (New Values)</div>
                    <div class="at-json-box">${newJson}</div>
                </div>
            `;
        }

        content.innerHTML = html;
        document.getElementById('sideModal').classList.add('active');
        document.getElementById('drawerOverlay').classList.add('active');
    }

    function closeModal() {
        document.getElementById('sideModal').classList.remove('active');
        document.getElementById('drawerOverlay').classList.remove('active');
    }

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
