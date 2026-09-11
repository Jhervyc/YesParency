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
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-audit-trail.css">
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
    <div class="ap2-stats ap2-stats-4 mb-20">
        <!-- Total Modifications -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#06251b; --pct:100%;">
                <div class="ap2-ring-inner"><i class="bi bi-database-check clr-dark"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= number_format($stat_total) ?></div>
                <div class="ap2-stat-lbl">Modifications</div>
            </div>
        </div>
        <!-- Created / Added -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#219653; --pct:<?= $stat_total > 0 ? round($stat_create / $stat_total * 100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-plus-circle clr-create"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--create"><?= number_format($stat_create) ?></div>
                <div class="ap2-stat-lbl">Added / Created</div>
            </div>
        </div>
        <!-- Updated / Modified -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#2F6FED; --pct:<?= $stat_total > 0 ? round($stat_update / $stat_total * 100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-pencil-square clr-update"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--update"><?= number_format($stat_update) ?></div>
                <div class="ap2-stat-lbl">Updated / Modified</div>
            </div>
        </div>
        <!-- Deleted / Removed -->
        <div class="ap2-stat">
            <div class="ap2-ring" style="--ring-color:#c23b3b; --pct:<?= $stat_total > 0 ? round($stat_delete / $stat_total * 100) : 0 ?>%;">
                <div class="ap2-ring-inner"><i class="bi bi-trash3 clr-delete"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num ap2-stat-num--delete"><?= number_format($stat_delete) ?></div>
                <div class="ap2-stat-lbl">Deleted</div>
            </div>
        </div>
    </div>

    <!-- ── Log list panel ── -->
    <div class="proc-table-panel mb-24">
        <div class="filter-bar">
            <form method="GET" action="audit_trail.php" id="auditFilterForm" class="at-filter-form">
                <div class="ap2-search-field">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        placeholder="Search by description, record ID, or user..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="at-tabs">
                    <?php foreach (['all'=>'All','CREATE'=>'Created','UPDATE'=>'Updated','DELETE'=>'Deleted'] as $val => $lbl): ?>
                    <button type="submit" name="action" value="<?= $val ?>"
                            class="ap2-filter-btn <?= $action_filter === $val ? 'active' : '' ?>">
                        <?= $lbl ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <select name="module" class="filter-dropdowns" onchange="document.getElementById('auditFilterForm').submit()">
                    <option value="all" <?= $module_filter==='all'?'selected':'' ?>>All Modules</option>
                    <option value="procurements" <?= $module_filter==='procurements'?'selected':'' ?>>Procurements</option>
                    <option value="bids" <?= $module_filter==='bids'?'selected':'' ?>>Bids</option>
                    <option value="lots" <?= $module_filter==='lots'?'selected':'' ?>>Lots</option>
                    <option value="users" <?= $module_filter==='users'?'selected':'' ?>>Users</option>
                    <option value="announcements" <?= $module_filter==='announcements'?'selected':'' ?>>Announcements</option>
                    <option value="settings" <?= $module_filter==='settings'?'selected':'' ?>>Settings</option>
                    <option value="bid_opening" <?= $module_filter==='bid_opening'?'selected':'' ?>>Bid Opening</option>
                </select>
                <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>

        <?php if ($total_shown === 0): ?>
        <div class="anc-empty-state">
            <i class="bi bi-database-exclamation anc-empty-icon"></i>
            <div class="anc-empty-text">No audit records found<?= $search?' for "'.htmlspecialchars($search).'"':'' ?>.</div>
        </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="proc-table">
                <thead>
                    <tr>
                        <th class="col-w-42"></th>
                        <th>Description</th>
                        <th class="col-mode">Actor</th>
                        <th class="col-opening">Module</th>
                        <th class="col-abc">IP Address</th>
                        <th class="col-status">Time</th>
                        <th class="at-action-th">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()):
                    $logs_data[$row['log_id']] = $row;
                    $act = strtoupper($row['action']);
                    $mod = 'default'; $icon = 'bi-database';
                    $is_create = in_array($act,['CREATE','INSERT'])||str_ends_with($act,'_CREATED')||str_ends_with($act,'SUBMITTED')||str_ends_with($act,'INVITED')||str_ends_with($act,'RECORDED');
                    $is_update = in_array($act,['UPDATE','PUBLISH','APPROVE','REJECT','TOGGLE_MAINTENANCE'])||str_ends_with($act,'_UPDATED')||str_ends_with($act,'_CHANGED')||str_ends_with($act,'_APPROVED')||str_ends_with($act,'_REJECTED')||str_ends_with($act,'_STARTED')||str_ends_with($act,'_ACTIVATED')||str_ends_with($act,'_DEACTIVATED')||str_ends_with($act,'_REORDERED');
                    $is_delete = $act==='DELETE'||str_ends_with($act,'_DELETED')||str_ends_with($act,'_CANCELLED')||str_ends_with($act,'_REMOVED');
                    if ($is_create) { $mod='create'; $icon='bi-plus-circle'; }
                    elseif ($is_update) { $mod='update'; $icon='bi-pencil-square'; }
                    elseif ($is_delete) { $mod='delete'; $icon='bi-trash3'; }
                    $actorName = $row['username'] ? trim($row['firstname'].' '.$row['lastname']) : 'System';
                    $usernameStr = $row['username'] ? '@'.$row['username'] : '—';
                ?>
                <tr>
                    <td class="at-icon-col">
                        <div class="at-action-icon at-action-icon--<?= $mod ?>">
                            <i class="bi <?= $icon ?>"></i>
                        </div>
                    </td>
                    <td class="proc-title-cell">
                        <?= htmlspecialchars(mb_strimwidth($row['description'], 0, 70, '…')) ?>
                        <div class="at-action-sub">
                            <span class="proc-status-pill at-action-pill at-action-pill--<?= $mod ?>">
                                <?= htmlspecialchars($act) ?>
                            </span>
                        </div>
                    </td>
                    <td class="proc-deadline-cell col-mode">
                        <strong><?= htmlspecialchars($actorName) ?></strong>
                        <span class="clr-idle"><?= htmlspecialchars($usernameStr) ?></span>
                    </td>
                    <td class="proc-deadline-cell col-opening">
                        <strong><?= htmlspecialchars(ucfirst($row['module'])) ?></strong>
                        <?php if ($row['record_id']): ?><span class="clr-idle">#<?= intval($row['record_id']) ?></span><?php endif; ?>
                    </td>
                    <td class="proc-deadline-cell col-abc"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                    <td class="proc-deadline-cell col-status nowrap">
                        <?= date('M j, Y', strtotime($row['created_at'])) ?><br>
                        <span class="clr-idle"><?= date('g:i A', strtotime($row['created_at'])) ?></span>
                    </td>
                    <td class="text-right">
                        <button type="button" class="proc-action-btn btn-view" onclick="loadLogDetail(<?= $row['log_id'] ?>)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="table-foot">
            <div>
                Showing <strong><?= number_format(min($total_shown,$offset+1)) ?></strong>–<strong><?= number_format(min($total_shown,$offset+$per_page)) ?></strong>
                of <strong><?= number_format($total_shown) ?></strong> record<?= $total_shown!=1?'s':'' ?>
                <?= $search?' for "'.htmlspecialchars($search).'"':'' ?>
            </div>
            <?php if ($total_pages > 1):
                $qp = array_filter(['action'=>$action_filter!=='all'?$action_filter:null,'module'=>$module_filter!=='all'?$module_filter:null,'search'=>$search?:null]);
                $qs = $qp ? '?'.http_build_query($qp).'&' : '?';
            ?>
            <div class="pagination">
                <a href="<?= $qs ?>page=<?= max(1,$page-1) ?>" class="page-link <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p=max(1,min($page-2,$total_pages-4)); $p<=min($total_pages,$p+4); $p++): ?>
                <a href="<?= $qs ?>page=<?= $p ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="<?= $qs ?>page=<?= min($total_pages,$page+1) ?>" class="page-link <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.proc-table-panel -->

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
        let pillMod = 'default';
        const isCreate = ['CREATE', 'INSERT'].includes(act) || act.endsWith('_CREATED') || act.endsWith('SUBMITTED') || act.endsWith('INVITED') || act.endsWith('RECORDED');
        const isUpdate = ['UPDATE', 'PUBLISH', 'APPROVE', 'REJECT', 'TOGGLE_MAINTENANCE'].includes(act) || act.endsWith('_UPDATED') || act.endsWith('_CHANGED') || act.endsWith('_APPROVED') || act.endsWith('_REJECTED') || act.endsWith('_STARTED') || act.endsWith('_ACTIVATED') || act.endsWith('_DEACTIVATED') || act.endsWith('_REORDERED');
        const isDelete = act === 'DELETE' || act.endsWith('_DELETED') || act.endsWith('_CANCELLED') || act.endsWith('_REMOVED');

        if (isCreate) {
            pillMod = 'create';
        } else if (isUpdate) {
            pillMod = 'update';
        } else if (isDelete) {
            pillMod = 'delete';
        }

        const actor = log.username
            ? `${log.firstname || ''} ${log.lastname || ''} <span class="at-actor-fallback">(@${log.username})</span>`
            : '<span class="at-actor-fallback">System / Automated</span>';

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
                            <span class="sp-status-pill at-action-pill--${pillMod}">${act}</span>
                        </div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Module</label>
                        <div class="val capitalize">${log.module}</div>
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
                <div class="at-desc-text">
                    ${log.description}
                </div>
            </div>

            <!-- Actor & Device Info -->
            <div class="at-drawer-section">
                <div class="at-drawer-section-title"><i class="bi bi-person-gear"></i> Performed By & Network</div>
                <div class="at-drawer-grid">
                    <div class="at-drawer-field at-drawer-field--full">
                        <label>Actor</label>
                        <div class="val">${actor}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>Timestamp</label>
                        <div class="val at-val-sm">${logDate}</div>
                    </div>
                    <div class="at-drawer-field">
                        <label>IP Address</label>
                        <div class="val at-val-sm">${log.ip_address || '—'}</div>
                    </div>
                    ${log.user_agent ? `
                    <div class="at-drawer-field at-drawer-field--full">
                        <label>User Agent</label>
                        <div class="val at-val-xs">${log.user_agent}</div>
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
