<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/../utils/mailer.php");

$admin_id = intval($_SESSION['user_id']);

// ── Approve request ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $req_id = intval($_POST['req_id'] ?? 0);

    $row = $conn->prepare("SELECT * FROM invitation_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $row->bind_param("i", $req_id);
    $row->execute();
    $req = $row->get_result()->fetch_assoc();
    $row->close();

    if (!$req) {
        $_SESSION['alert_error'] = "Request not found or already processed.";
        header("Location: invitation_requests.php"); exit();
    }

    // Generate token + expiry (7 days)
    $token      = bin2hex(random_bytes(32));     // 64 hex chars
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $conn->begin_transaction();
    try {
        // Insert into user_invitations
        $ins = $conn->prepare("
            INSERT INTO user_invitations (email, role, token, status, expires_at)
            VALUES (?, 'user', ?, 'pending', ?)
        ");
        $ins->bind_param("sss", $req['email'], $token, $expires_at);
        $ins->execute();
        $ins->close();

        // Update invitation_requests
        $upd = $conn->prepare("
            UPDATE invitation_requests
            SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $upd->bind_param("ii", $admin_id, $req_id);
        $upd->execute();
        $upd->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['alert_error'] = "Failed to approve request: " . $e->getMessage();
        header("Location: invitation_requests.php"); exit();
    }

    // Send invite email
    notify_invitation_approved($conn, $req['email'], $req['contact_person'], $req['company_name'], $token, $expires_at);

    audit_log($conn, 'INVITATION_APPROVED', 'invitation_requests', $req_id,
        "Approved invitation request from {$req['company_name']} ({$req['email']}); token sent",
        ['status' => 'pending'],
        ['status' => 'approved', 'reviewed_by' => $admin_id, 'expires_at' => $expires_at]
    );

    $_SESSION['alert_success'] = "Request approved. Invitation email sent to {$req['email']}.";
    header("Location: invitation_requests.php"); exit();
}

// ── Resend invite ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $req_id = intval($_POST['req_id'] ?? 0);

    $row = $conn->prepare("SELECT ir.*, ui.token, ui.expires_at AS inv_expires FROM invitation_requests ir LEFT JOIN user_invitations ui ON ui.email = ir.email AND ui.status = 'pending' WHERE ir.id = ? AND ir.status = 'approved' LIMIT 1");
    $row->bind_param("i", $req_id);
    $row->execute();
    $req = $row->get_result()->fetch_assoc();
    $row->close();

    if (!$req) {
        $_SESSION['alert_error'] = "Cannot resend: request not found or not yet approved.";
        header("Location: invitation_requests.php"); exit();
    }

    // Regenerate token + extend expiry
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    // Update or insert the invitation
    $upsert = $conn->prepare("
        INSERT INTO user_invitations (email, role, token, status, expires_at)
        VALUES (?, 'user', ?, 'pending', ?)
        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), status = 'pending'
    ");
    // user_invitations has no unique on email by default — use replace by email lookup
    // Delete old pending invites for this email, then insert fresh
    $del = $conn->prepare("DELETE FROM user_invitations WHERE email = ? AND status = 'pending'");
    $del->bind_param("s", $req['email']);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT INTO user_invitations (email, role, token, status, expires_at) VALUES (?, 'user', ?, 'pending', ?)");
    $ins->bind_param("sss", $req['email'], $token, $expires_at);
    $ins->execute();
    $ins->close();

    notify_invitation_approved($conn, $req['email'], $req['contact_person'], $req['company_name'], $token, $expires_at);

    audit_log($conn, 'INVITATION_RESENT', 'invitation_requests', $req_id,
        "Resent invitation to {$req['company_name']} ({$req['email']})",
        [], ['token_regenerated' => true, 'expires_at' => $expires_at]
    );

    $_SESSION['alert_success'] = "Invitation resent to {$req['email']}.";
    header("Location: invitation_requests.php"); exit();
}

// ── Reject request ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $req_id     = intval($_POST['req_id'] ?? 0);
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    $row = $conn->prepare("SELECT * FROM invitation_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $row->bind_param("i", $req_id);
    $row->execute();
    $req = $row->get_result()->fetch_assoc();
    $row->close();

    if (!$req) {
        $_SESSION['alert_error'] = "Request not found or already processed.";
        header("Location: invitation_requests.php"); exit();
    }

    $upd = $conn->prepare("
        UPDATE invitation_requests
        SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $notes_val = $admin_notes ?: null;
    $upd->bind_param("sii", $notes_val, $admin_id, $req_id);
    $upd->execute();
    $upd->close();

    notify_invitation_rejected($conn, $req['email'], $req['contact_person'], $req['company_name'], $notes_val);

    audit_log($conn, 'INVITATION_REJECTED', 'invitation_requests', $req_id,
        "Rejected invitation request from {$req['company_name']} ({$req['email']})",
        ['status' => 'pending'],
        ['status' => 'rejected', 'admin_notes' => $admin_notes, 'reviewed_by' => $admin_id]
    );

    $_SESSION['alert_success'] = "Request rejected. Notification sent to {$req['email']}.";
    header("Location: invitation_requests.php"); exit();
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_total    = (int)$conn->query("SELECT COUNT(*) FROM invitation_requests")->fetch_row()[0];
$stat_pending  = (int)$conn->query("SELECT COUNT(*) FROM invitation_requests WHERE status='pending'")->fetch_row()[0];
$stat_approved = (int)$conn->query("SELECT COUNT(*) FROM invitation_requests WHERE status='approved'")->fetch_row()[0];
$stat_rejected = (int)$conn->query("SELECT COUNT(*) FROM invitation_requests WHERE status='rejected'")->fetch_row()[0];

// ── Filters + pagination ──────────────────────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$status_filter = in_array($_GET['status'] ?? '', ['all','pending','approved','rejected']) ? $_GET['status'] : 'all';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

$where  = [];
$params = [];
$types  = '';

if ($status_filter !== 'all') {
    $where[]  = "ir.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = "(ir.company_name LIKE ? OR ir.contact_person LIKE ? OR ir.email LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $conn->prepare("SELECT COUNT(*) FROM invitation_requests ir $wsql");
if ($types) $cnt->bind_param($types, ...$params);
$cnt->execute();
$total_shown = (int)$cnt->get_result()->fetch_row()[0];
$cnt->close();
$total_pages = max(1, ceil($total_shown / $per_page));

$lp  = array_merge($params, [$per_page, $offset]);
$lt  = $types . 'ii';
$rstmt = $conn->prepare("
    SELECT ir.*,
           u.username AS reviewer_username
    FROM invitation_requests ir
    LEFT JOIN users u ON ir.reviewed_by = u.user_id
    $wsql
    ORDER BY FIELD(ir.status,'pending','approved','rejected'), ir.created_at DESC
    LIMIT ? OFFSET ?
");
$rstmt->bind_param($lt, ...$lp);
$rstmt->execute();
$requests = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rstmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Requests | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <style>
        * { box-sizing: border-box; }
        .dash-content { max-width: 100%; overflow-x: hidden; }

        /* Status pills */
        .req-status-pill {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10.5px; font-weight: 800; padding: 3px 9px;
            border-radius: 20px; text-transform: uppercase; white-space: nowrap;
        }
        .req-status-pill.pending  { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .req-status-pill.approved { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .req-status-pill.rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Action buttons */
        .req-btn {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11.5px; font-weight: 700; padding: 5px 11px;
            border-radius: 8px; border: none; cursor: pointer; transition: all .15s;
            text-decoration: none;
        }
        .req-btn.approve { background: #1f7a3d; color: #fff; }
        .req-btn.approve:hover { background: #16602f; }
        .req-btn.reject  { background: #fff; color: #b91c1c; border: 1.5px solid #fecaca; }
        .req-btn.reject:hover  { background: #fef2f2; border-color: #b91c1c; }
        .req-btn.resend  { background: #fffbeb; color: #b45309; border: 1.5px solid #fde68a; }
        .req-btn.resend:hover  { background: #fef3c7; }

        /* Table */
        .req-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .req-table thead tr { background: #fafcfb; border-bottom: 2px solid #eaeeec; }
        .req-table thead th { padding: 11px 14px; text-align: left; font-size: 10.5px; font-weight: 800; color: #88968d; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
        .req-table tbody tr { border-bottom: 1px solid #f4f6f5; transition: background .1s; }
        .req-table tbody tr:last-child { border-bottom: none; }
        .req-table tbody tr:hover { background: #fafcfb; }
        .req-table td { padding: 13px 14px; vertical-align: middle; }

        /* Company cell */
        .req-company { font-size: 13px; font-weight: 700; color: #06251b; }
        .req-contact { font-size: 11.5px; color: #6c776e; margin-top: 2px; }

        /* Notes textarea in reject modal */
        .rej-modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(6,37,27,.5); backdrop-filter: blur(4px);
            z-index: 9999; align-items: center; justify-content: center; padding: 16px;
        }
        .rej-modal-backdrop.open { display: flex; }
        .rej-modal {
            background: #fff; border-radius: 18px; padding: 28px 28px 24px;
            width: 100%; max-width: 460px;
            box-shadow: 0 12px 40px rgba(6,37,27,.18);
        }
        .rej-modal h4 { font-size: 17px; font-weight: 800; color: #06251b; margin: 0 0 6px; }
        .rej-modal p  { font-size: 12.5px; color: #6c776e; margin: 0 0 18px; line-height: 1.6; }
        .rej-modal textarea {
            width: 100%; border: 1.5px solid #dbe2df; border-radius: 10px;
            padding: 10px 12px; font-family: 'Poppins', sans-serif; font-size: 12.5px;
            color: #06251b; resize: vertical; min-height: 90px; outline: none;
            transition: border-color .15s;
        }
        .rej-modal textarea:focus { border-color: #1f7a3d; }
        .rej-modal-actions { display: flex; gap: 10px; margin-top: 16px; justify-content: flex-end; }
        .btn-cancel-rej { background: #f4f6f5; color: #06251b; border: none; border-radius: 9px; padding: 9px 18px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
        .btn-cancel-rej:hover { background: #e0e8e4; }
        .btn-confirm-rej { background: #b91c1c; color: #fff; border: none; border-radius: 9px; padding: 9px 18px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
        .btn-confirm-rej:hover { background: #991b1b; }

        /* Pagination */
        .pag-wrap { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px solid #f0f4f2; font-size: 12px; color: #6c776e; flex-wrap: wrap; gap: 8px; }
        .pag-links { display: flex; gap: 4px; flex-wrap: wrap; }
        .pag-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; color: #06251b; border: 1px solid #eaeeec; background: #fff; transition: all .15s; }
        .pag-btn:hover { background: #06251b; color: #ffc107; border-color: #06251b; }
        .pag-btn.active { background: #06251b; color: #ffc107; border-color: #06251b; }
        .pag-btn.disabled { opacity: .4; pointer-events: none; }
    </style>
</head>
<body class="dash-body">

<?php
$active_nav = 'invitation_requests.php';
include("components/sidebar.php");
?>
<?php
$topbar_title = 'Invitation Requests';
include("components/topbar.php");
?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <div class="page-header">
        <h2>Invitation Requests</h2>
        <p>Review and respond to portal access requests. Approved requests receive an invitation email.</p>
    </div>

    <!-- ── Stat Cards ── -->
    <div class="sad-section-label">Summary</div>
    <div class="ap2-stats ap2-stats-4" style="margin-bottom:20px;">
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#06251b 0% 100%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-envelope-paper" style="color:#06251b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_total ?></div>
                <div class="ap2-stat-lbl">Total Requests</div>
            </div>
        </div>
        <div class="ap2-stat <?= $stat_pending > 0 ? 'bsv-stat-warn' : '' ?>">
            <div class="ap2-ring" style="background:conic-gradient(<?= $stat_pending > 0 ? '#e67e22' : '#8B958E' ?> 0% <?= $stat_total > 0 ? round($stat_pending/$stat_total*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-hourglass-split" style="color:<?= $stat_pending > 0 ? '#e67e22' : '#8B958E' ?>;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num" style="color:<?= $stat_pending > 0 ? '#e67e22' : 'inherit' ?>"><?= $stat_pending ?></div>
                <div class="ap2-stat-lbl">Pending Review</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#219653 0% <?= $stat_total > 0 ? round($stat_approved/$stat_total*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-patch-check" style="color:#219653;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_approved ?></div>
                <div class="ap2-stat-lbl">Approved</div>
            </div>
        </div>
        <div class="ap2-stat">
            <div class="ap2-ring" style="background:conic-gradient(#c23b3b 0% <?= $stat_total > 0 ? round($stat_rejected/$stat_total*100) : 0 ?>%, #e7ece9 0%);">
                <div class="ap2-ring-inner"><i class="bi bi-x-circle" style="color:#c23b3b;"></i></div>
            </div>
            <div class="ap2-stat-text">
                <div class="ap2-stat-num"><?= $stat_rejected ?></div>
                <div class="ap2-stat-lbl">Rejected</div>
            </div>
        </div>
    </div>

    <!-- ── List panel ── -->
    <div class="ap2-card">
        <div class="ap2-card-head">
            <h3>Access Requests</h3>
        </div>

        <!-- Search + filter -->
        <form method="GET" action="" class="ap2-controls" style="margin-bottom:16px;">
            <div class="ap2-search-field">
                <i class="bi bi-search"></i>
                <input type="text" name="search"
                       placeholder="Search by company, contact, or email..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="ap2-filters">
                <?php foreach (['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $val => $lbl): ?>
                    <button type="submit" name="status" value="<?= $val ?>"
                            class="ap2-filter-btn <?= $status_filter === $val ? 'active' : '' ?>">
                        <?= $lbl ?>
                        <?php if ($val === 'pending' && $stat_pending > 0): ?>
                            <span style="background:#e67e22; color:#fff; font-size:10px; font-weight:800; padding:1px 6px; border-radius:10px; margin-left:3px;"><?= $stat_pending ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="ap2-go-btn"><i class="bi bi-search"></i> Search</button>
        </form>

        <?php if (empty($requests)): ?>
            <div style="padding:52px 20px; text-align:center; color:#88968d;">
                <i class="bi bi-envelope-paper" style="font-size:32px; color:#c7d2cb; display:block; margin-bottom:8px;"></i>
                <div style="font-size:13px; font-weight:700;">
                    No <?= $status_filter !== 'all' ? $status_filter . ' ' : '' ?>requests found<?= $search ? ' for "'.htmlspecialchars($search).'"' : '' ?>.
                </div>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="req-table">
                <thead>
                    <tr>
                        <th>Organization</th>
                        <th>Email</th>
                        <th>Business Type</th>
                        <th>TIN</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th style="text-align:right; min-width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $req):
                    $status = $req['status'];
                ?>
                <tr>
                    <td>
                        <div class="req-company"><?= htmlspecialchars($req['company_name']) ?></div>
                        <div class="req-contact"><i class="bi bi-person"></i> <?= htmlspecialchars($req['contact_person']) ?></div>
                        <?php if (!empty($req['phone'])): ?>
                            <div class="req-contact"><i class="bi bi-telephone"></i> <?= htmlspecialchars($req['phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#06251b; font-weight:600; font-size:12.5px;">
                        <?= htmlspecialchars($req['email']) ?>
                    </td>
                    <td style="font-size:12px; color:#55665a;">
                        <?= htmlspecialchars($req['business_type'] ?? '—') ?>
                    </td>
                    <td style="font-size:12px; color:#6c776e;">
                        <?= htmlspecialchars($req['tax_id_tin'] ?? '—') ?>
                    </td>
                    <td style="white-space:nowrap; font-size:12px; color:#6c776e;">
                        <?= date('M j, Y', strtotime($req['created_at'])) ?>
                        <?php if ($status !== 'pending' && $req['reviewed_at']): ?>
                            <div style="font-size:10.5px; color:#88968d; margin-top:2px;">
                                <?= $status === 'approved' ? 'Approved' : 'Rejected' ?> <?= date('M j, Y', strtotime($req['reviewed_at'])) ?>
                                <?php if ($req['reviewer_username']): ?>
                                    by @<?= htmlspecialchars($req['reviewer_username']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="req-status-pill <?= $status ?>">
                            <i class="bi bi-circle-fill" style="font-size:5px;"></i>
                            <?= ucfirst($status) ?>
                        </span>
                        <?php if (!empty($req['admin_notes']) && $status === 'rejected'): ?>
                            <div style="font-size:10.5px; color:#88968d; margin-top:4px; font-style:italic; max-width:160px; word-break:break-word;">
                                "<?= htmlspecialchars(mb_strimwidth($req['admin_notes'], 0, 60, '...')) ?>"
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($status === 'pending'): ?>
                            <!-- Approve form -->
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action"  value="approve">
                                <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                                <button type="submit" class="req-btn approve"
                                        onclick="return confirm('Approve request from <?= htmlspecialchars(addslashes($req['company_name'])) ?>?')">
                                    <i class="bi bi-check-circle-fill"></i> Approve
                                </button>
                            </form>
                            <!-- Reject — opens modal -->
                            <button type="button" class="req-btn reject"
                                    onclick="openRejectModal(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['company_name'])) ?>')">
                                <i class="bi bi-x-circle-fill"></i> Reject
                            </button>

                        <?php elseif ($status === 'approved'): ?>
                            <!-- Resend invite -->
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action"  value="resend">
                                <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                                <button type="submit" class="req-btn resend"
                                        onclick="return confirm('Re-generate and resend the invitation to <?= htmlspecialchars(addslashes($req['email'])) ?>?')">
                                    <i class="bi bi-envelope-arrow-up-fill"></i> Resend Invite
                                </button>
                            </form>

                        <?php else: ?>
                            <span style="font-size:11.5px; color:#88968d; font-style:italic;">No actions</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pag-wrap">
            <div><?= $total_shown ?> request<?= $total_shown !== 1 ? 's' : '' ?> — Page <?= $page ?> of <?= $total_pages ?></div>
            <div class="pag-links">
                <?php
                $qs = http_build_query(array_filter(['search'=>$search,'status'=>$status_filter]));
                $qs = $qs ? '&'.$qs : '';
                ?>
                <a href="?page=<?= max(1,$page-1) ?><?= $qs ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for($p=1; $p<=$total_pages; $p++): ?>
                    <a href="?page=<?= $p ?><?= $qs ?>" class="pag-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages,$page+1) ?><?= $qs ?>" class="pag-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
        <?php else: ?>
        <div class="pag-wrap">
            <div><?= $total_shown ?> request<?= $total_shown !== 1 ? 's' : '' ?> found</div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /.ap2-card -->

</div>
</main>

<!-- ── Reject Modal ── -->
<div class="rej-modal-backdrop" id="rejectModal">
    <div class="rej-modal">
        <h4><i class="bi bi-x-circle-fill" style="color:#b91c1c; margin-right:6px;"></i> Reject Request</h4>
        <p id="rejectModalDesc">Provide an optional reason for the rejection. This will be included in the email sent to the applicant.</p>
        <form method="POST" action="" id="rejectForm">
            <input type="hidden" name="action"  value="reject">
            <input type="hidden" name="req_id" id="rejectReqId">
            <textarea name="admin_notes" id="rejectNotes" placeholder="Optional: reason for rejection (visible to applicant)..."></textarea>
            <div class="rej-modal-actions">
                <button type="button" class="btn-cancel-rej" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-confirm-rej"><i class="bi bi-x-circle-fill"></i> Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Toasts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?></div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert"><i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?></div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
const toast = document.getElementById('toastAlert');
if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

function openRejectModal(reqId, companyName) {
    document.getElementById('rejectReqId').value  = reqId;
    document.getElementById('rejectModalDesc').textContent =
        'Rejecting request from "' + companyName + '". Provide an optional reason — it will be emailed to the applicant.';
    document.getElementById('rejectNotes').value  = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>
</body>
</html>
