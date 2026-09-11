<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");
require_once(__DIR__ . "/utils/audit_helper.php");
require_once(__DIR__ . "/../utils/bidder_document_helper.php");
require_once(__DIR__ . "/../utils/mailer.php");

if (!isset($_GET['id']) || intval($_GET['id']) <= 0) {
    $_SESSION['alert_error'] = "Invalid bidder requested.";
    header("Location: account-management.php");
    exit();
}

$target_user_id = intval($_GET['id']);

// ─────────────────────────────────────────────────────────────────────────────
// POST ACTIONS: Expiration Update, Document Reject, Demote, Approve, Reject
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. UPDATE EXPIRATION DATE
    if ($action === 'update_expiration') {
        $doc_id = intval($_POST['document_id'] ?? 0);
        $raw_date = trim($_POST['expiration_date'] ?? '');
        $exp_date = !empty($raw_date) ? $raw_date : null;

        // Verify document belongs to this user
        $chk = $conn->prepare("SELECT document_type, expiration_date, file_name FROM bidder_documents WHERE document_id = ? AND user_id = ?");
        $chk->bind_param("ii", $doc_id, $target_user_id);
        $chk->execute();
        $old_doc = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($old_doc) {
            $upd = $conn->prepare("UPDATE bidder_documents SET expiration_date = ? WHERE document_id = ?");
            $upd->bind_param("si", $exp_date, $doc_id);
            if ($upd->execute()) {
                $docLabel = REQUIRED_BIDDER_DOCS[$old_doc['document_type']] ?? $old_doc['document_type'];
                audit_log(
                    $conn,
                    'DOCUMENT_EXPIRATION_SET',
                    'bidder_documents',
                    $doc_id,
                    "Updated expiration date for {$docLabel} to " . ($exp_date ?: 'NULL (No Expiration)'),
                    ['expiration_date' => $old_doc['expiration_date']],
                    ['expiration_date' => $exp_date]
                );
                $_SESSION['alert_success'] = "Expiration date for {$docLabel} updated successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to update expiration date.";
            }
            $upd->close();
        } else {
            $_SESSION['alert_error'] = "Document not found.";
        }
        header("Location: bidder-profile.php?id=" . $target_user_id);
        exit();
    }

    // 2. REJECT DOCUMENT (Deletes physical file and database record)
    if ($action === 'reject_document') {
        $doc_id = intval($_POST['document_id'] ?? 0);

        $chk = $conn->prepare("SELECT document_type, file_path, file_name FROM bidder_documents WHERE document_id = ? AND user_id = ?");
        $chk->bind_param("ii", $doc_id, $target_user_id);
        $chk->execute();
        $target_doc = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($target_doc) {
            $docLabel = REQUIRED_BIDDER_DOCS[$target_doc['document_type']] ?? $target_doc['document_type'];

            // Delete physical file
            delete_bidder_physical_file($target_doc['file_path']);

            // Delete database row
            $del = $conn->prepare("DELETE FROM bidder_documents WHERE document_id = ? AND user_id = ?");
            $del->bind_param("ii", $doc_id, $target_user_id);
            if ($del->execute()) {
                audit_log(
                    $conn,
                    'DOCUMENT_REJECTED',
                    'bidder_documents',
                    $doc_id,
                    "Rejected and deleted document {$docLabel} for User #{$target_user_id}",
                    $target_doc,
                    null
                );
                $_SESSION['alert_success'] = "Document \"{$docLabel}\" was rejected and removed. The bidder can now re-upload a replacement.";
            } else {
                $_SESSION['alert_error'] = "Failed to remove document record.";
            }
            $del->close();
        } else {
            $_SESSION['alert_error'] = "Document record not found.";
        }
        header("Location: bidder-profile.php?id=" . $target_user_id);
        exit();
    }

    // 3. DEMOTE BIDDER BACK TO USER (Deletes bidder documents & profile; keeps user account)
    if ($action === 'demote_bidder') {
        $conn->begin_transaction();
        try {
            // Delete all uploaded physical document files
            delete_bidder_all_files($target_user_id);

            // Delete database records
            $d1 = $conn->prepare("DELETE FROM bidder_documents WHERE user_id = ?");
            $d1->bind_param("i", $target_user_id);
            $d1->execute();
            $d1->close();

            $d2 = $conn->prepare("DELETE FROM bidder_profiles WHERE user_id = ?");
            $d2->bind_param("i", $target_user_id);
            $d2->execute();
            $d2->close();

            $u1 = $conn->prepare("UPDATE users SET role = 'user' WHERE user_id = ?");
            $u1->bind_param("i", $target_user_id);
            $u1->execute();
            $u1->close();

            audit_log(
                $conn,
                'BIDDER_DEMOTED',
                'users',
                $target_user_id,
                "Demoted bidder #{$target_user_id} to user and wiped bidder documents and profile",
                ['role' => 'bidder'],
                ['role' => 'user']
            );

            $conn->commit();
            $_SESSION['alert_success'] = "Bidder has been demoted to user. All bidder documents and profile records were purged while retaining the user account.";
            header("Location: account-management.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['alert_error'] = "Failed to demote bidder: " . $e->getMessage();
            header("Location: bidder-profile.php?id=" . $target_user_id);
            exit();
        }
    }

    // 4. APPROVE BIDDER APPLICATION
    if ($action === 'approve_application') {
        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("UPDATE users SET role = 'bidder', status = 'active' WHERE user_id = ?");
            $s1->bind_param("i", $target_user_id);
            $s1->execute();
            $s1->close();

            $s2 = $conn->prepare("UPDATE bidder_profiles SET application_status = 'approved' WHERE user_id = ?");
            $s2->bind_param("i", $target_user_id);
            $s2->execute();
            $s2->close();

            audit_log(
                $conn,
                'BIDDER_APPROVED',
                'users',
                $target_user_id,
                "Approved bidder application for User #{$target_user_id}",
                ['role' => 'user', 'application_status' => 'pending'],
                ['role' => 'bidder', 'application_status' => 'approved']
            );

            $conn->commit();

            // Queue and dispatch email notification
            notify_bidder_approved($conn, $target_user_id);

            $_SESSION['alert_success'] = "Bidder application approved successfully. Notification email dispatched.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['alert_error'] = "Failed to approve bidder application.";
        }
        header("Location: bidder-profile.php?id=" . $target_user_id);
        exit();
    }

    // 5. REJECT BIDDER APPLICATION
    if ($action === 'reject_application') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("UPDATE users SET role = 'user' WHERE user_id = ?");
            $s1->bind_param("i", $target_user_id);
            $s1->execute();
            $s1->close();

            $s2 = $conn->prepare("UPDATE bidder_profiles SET application_status = 'rejected' WHERE user_id = ?");
            $s2->bind_param("i", $target_user_id);
            $s2->execute();
            $s2->close();

            audit_log(
                $conn,
                'BIDDER_REJECTED',
                'users',
                $target_user_id,
                "Rejected bidder application for User #{$target_user_id}",
                ['application_status' => 'pending'],
                ['application_status' => 'rejected', 'reason' => $reason]
            );

            $conn->commit();

            // Queue rejection email
            notify_bidder_rejected($conn, $target_user_id, $reason ?: null);

            $_SESSION['alert_success'] = "Bidder application marked as rejected. Notice email sent.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['alert_error'] = "Failed to reject bidder application.";
        }
        header("Location: bidder-profile.php?id=" . $target_user_id);
        exit();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FETCH BIDDER & DOCUMENTS DATA
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.user_id, u.firstname, u.lastname, u.email, u.username, u.role, u.status AS account_status,
           u.profile_picture_url, u.created_at AS user_created_at,
           bp.profile_id, bp.business_name, bp.philgeps_number, bp.tin_number,
           bp.business_type, bp.year_established, bp.business_address,
           bp.business_email, bp.business_phone, bp.application_status, bp.created_at AS application_date
    FROM users u
    LEFT JOIN bidder_profiles bp ON u.user_id = bp.user_id
    WHERE u.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $target_user_id);
$stmt->execute();
$bidder = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bidder) {
    $_SESSION['alert_error'] = "Bidder user account not found.";
    header("Location: account-management.php");
    exit();
}

$doc_status = check_bidder_documents_status($conn, $target_user_id);
$uploaded_docs = $doc_status['all_docs'];

$initials = strtoupper(substr($bidder['firstname'] ?: 'B', 0, 1) . substr($bidder['lastname'] ?: 'P', 0, 1));
$avatar_url = !empty($bidder['profile_picture_url']) ? '../' . ltrim($bidder['profile_picture_url'], '/') : '';
$fullName = trim($bidder['firstname'] . ' ' . $bidder['lastname']) ?: $bidder['username'];
$businessName = $bidder['business_name'] ?: 'No Registered Business Name';

// Status pills
$role_badge = match($bidder['role']) {
    'bidder' => ['label' => 'Bidder', 'class' => 'bp-status-pill--green'],
    'user'   => ['label' => 'Standard User', 'class' => 'bp-status-pill--gray'],
    default  => ['label' => ucfirst($bidder['role']), 'class' => 'bp-status-pill--indigo'],
};

$acc_status_badge = match($bidder['account_status']) {
    'active'   => ['label' => 'Active Account', 'class' => 'bp-status-pill--green'],
    'inactive' => ['label' => 'Inactive Account', 'class' => 'bp-status-pill--red'],
    default    => ['label' => 'Pending Activation', 'class' => 'bp-status-pill--amber'],
};

$app_status = $bidder['application_status'] ?? null;
$app_status_badge = match($app_status) {
    'approved' => ['label' => 'Application Approved', 'class' => 'bp-status-pill--green'],
    'rejected' => ['label' => 'Application Rejected', 'class' => 'bp-status-pill--red'],
    'pending'  => ['label' => 'Application Pending', 'class' => 'bp-status-pill--amber'],
    default    => ['label' => 'No Application', 'class' => 'bp-status-pill--gray-muted'],
};

$compliance_badge = $doc_status['is_valid']
    ? ['label' => 'All 5 Documents Valid', 'icon' => 'bi-patch-check-fill', 'class' => 'bp-status-pill--green']
    : ['label' => (count($doc_status['expired']) > 0 ? count($doc_status['expired']) . ' Expired Doc(s)' : '') . (count($doc_status['missing']) > 0 ? ' · ' . count($doc_status['missing']) . ' Missing' : ''), 'icon' => 'bi-exclamation-triangle-fill', 'class' => 'bp-status-pill--red'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fullName) ?> | Bidder Profile | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-bidder-profile.css">

</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php 
$topbar_title = 'Bidder Profile';
include("components/topbar.php"); 
?>

<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Breadcrumb & Back Bar ── -->
    <div class="bp-breadcrumb-bar">
        <div class="bp-breadcrumbs">
            <a href="account-management.php"><i class="bi bi-people"></i> Bidder Directory</a>
            <span>/</span>
            <span class="clr-dark">Profile: <?= htmlspecialchars($fullName) ?> (@<?= htmlspecialchars($bidder['username']) ?>)</span>
        </div>
        <a href="account-management.php" class="bp-back-link">
            <i class="bi bi-arrow-left"></i> Back to Bidder Accounts
        </a>
    </div>

    <!-- ── Hero Profile Header Card ── -->
    <div class="bp-hero-card">
        <div class="bp-hero-left">
            <div class="bp-avatar">
                <?php if (!empty($avatar_url)): ?>
                    <img src="<?= htmlspecialchars($avatar_url) ?>" alt="<?= htmlspecialchars($initials) ?>">
                <?php else: ?>
                    <?= htmlspecialchars($initials) ?>
                <?php endif; ?>
            </div>
            <div class="bp-hero-info">
                <div class="bp-hero-name"><?= htmlspecialchars($fullName) ?></div>
                <div class="bp-hero-sub">
                    <span><i class="bi bi-at"></i><?= htmlspecialchars($bidder['username']) ?></span>
                    <span>·</span>
                    <span><i class="bi bi-envelope"></i> <?= htmlspecialchars($bidder['email']) ?></span>
                    <span>·</span>
                    <span><i class="bi bi-building"></i> <?= htmlspecialchars($businessName) ?></span>
                </div>
                <div class="bp-badges-bar">
                    <!-- Role badge -->
                    <span class="bp-status-pill <?= $role_badge['class'] ?>">
                        <i class="bi bi-person-badge"></i> <?= $role_badge['label'] ?>
                    </span>
                    <!-- Account status badge -->
                    <span class="bp-status-pill <?= $acc_status_badge['class'] ?>">
                        <i class="bi bi-shield-check"></i> <?= $acc_status_badge['label'] ?>
                    </span>
                    <!-- Bidder application status badge -->
                    <span class="bp-status-pill <?= $app_status_badge['class'] ?>">
                        <i class="bi bi-file-earmark-check"></i> <?= $app_status_badge['label'] ?>
                    </span>
                    <!-- Document compliance status badge -->
                    <span class="bp-status-pill <?= $compliance_badge['class'] ?>">
                        <i class="bi <?= $compliance_badge['icon'] ?>"></i> <?= $compliance_badge['label'] ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="bp-hero-actions">
            <?php if ($bidder['role'] === 'user' && !empty($app_status)): ?>
                <button type="button" class="bp-btn bp-btn-approve" onclick="openApproveModal()">
                    <i class="bi bi-check-circle-fill"></i> Approve Application
                </button>
                <button type="button" class="bp-btn bp-btn-reject" onclick="openRejectModal()">
                    <i class="bi bi-x-circle-fill"></i> Reject Application
                </button>
            <?php elseif ($bidder['role'] === 'bidder'): ?>
                <button type="button" class="bp-btn bp-btn-demote" onclick="openDemoteModal()">
                    <i class="bi bi-person-down"></i> Demote to User
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Main Grid ── -->
    <div class="bp-grid">

        <!-- ── Left Column: Complete Profile & Business Info ── -->
        <div class="bp-col-left">

            <!-- Business Information Card -->
            <div class="bp-card">
                <div class="bp-card-head">
                    <span class="bp-card-title">
                        <i class="bi bi-building-check clr-forest"></i> Business Enterprise
                    </span>
                    <span class="meta-label-sm">Profile</span>
                </div>
                <div class="bp-card-body">
                    <div class="bp-fields-list">
                        <div class="bp-field-item">
                            <span class="bp-field-label">Registered Business Name</span>
                            <span class="bp-field-val"><?= htmlspecialchars($businessName) ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">PhilGEPS Registration No.</span>
                            <span class="bp-field-val field-val--mono">
                                <?= htmlspecialchars($bidder['philgeps_number'] ?: 'Not Specified') ?>
                            </span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Tax Identification Number (TIN)</span>
                            <span class="bp-field-val field-val--mono">
                                <?= htmlspecialchars($bidder['tin_number'] ?: 'Not Specified') ?>
                            </span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Business Structure / Type</span>
                            <span class="bp-field-val"><?= htmlspecialchars($bidder['business_type'] ?: 'Not Specified') ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Year Established</span>
                            <span class="bp-field-val"><?= htmlspecialchars($bidder['year_established'] ?: 'Not Specified') ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Official Business Address</span>
                            <span class="bp-field-val"><?= nl2br(htmlspecialchars($bidder['business_address'] ?: 'Not Specified')) ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Business Contact Email</span>
                            <span class="bp-field-val"><?= htmlspecialchars($bidder['business_email'] ?: $bidder['email']) ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Business Contact Phone</span>
                            <span class="bp-field-val"><?= htmlspecialchars($bidder['business_phone'] ?: 'Not Specified') ?></span>
                        </div>

                        <div class="bp-field-item">
                            <span class="bp-field-label">Application Submitted On</span>
                            <span class="bp-field-val">
                                <?= !empty($bidder['application_date']) ? date('F j, Y · g:i A', strtotime($bidder['application_date'])) : (!empty($bidder['user_created_at']) ? date('F j, Y', strtotime($bidder['user_created_at'])) : 'N/A') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Account Details Card -->
            <div class="bp-card">
                <div class="bp-card-head">
                    <span class="bp-card-title">
                        <i class="bi bi-person-vcard clr-blue"></i> User Account
                    </span>
                    <span class="meta-label-sm">Security</span>
                </div>
                <div class="bp-card-body">
                    <div class="bp-fields-list">
                        <div class="bp-field-item">
                            <span class="bp-field-label">Full Representative Name</span>
                            <span class="bp-field-val"><?= htmlspecialchars($fullName) ?></span>
                        </div>
                        <div class="bp-field-item">
                            <span class="bp-field-label">User Account ID</span>
                            <span class="bp-field-val">#<?= $target_user_id ?></span>
                        </div>
                        <div class="bp-field-item">
                            <span class="bp-field-label">System Role</span>
                            <span class="bp-field-val"><?= ucfirst($bidder['role']) ?></span>
                        </div>
                        <div class="bp-field-item">
                            <span class="bp-field-label">Account Status</span>
                            <span class="bp-field-val"><?= ucfirst($bidder['account_status']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Right Column: Uploaded Documents & Expiration Review ── -->
        <div class="bp-col-right">

            <div class="bp-card">
                <div class="bp-card-head">
                    <span class="bp-card-title">
                        <i class="bi bi-file-earmark-lock2 clr-forest"></i> Legal Eligibility Documents &amp; Review
                    </span>
                    <span class="bp-status-pill <?= $compliance_badge['class'] ?>">
                        <i class="bi <?= $compliance_badge['icon'] ?>"></i> <?= count($uploaded_docs) ?>/<?= count(REQUIRED_BIDDER_DOCS) ?> Uploaded
                    </span>
                </div>
                <div class="bp-card-body">

                    <p class="card-desc-text">
                        Review all submitted compliance files. Secretariat members can set or revise the official <strong>expiration date</strong> for each document. Rejecting a document permanently purges the physical file and allows the bidder to upload a replacement.
                    </p>

                    <div class="bp-doc-list">
                        <?php foreach (REQUIRED_BIDDER_DOCS as $docType => $docLabel):
                            $hasDoc = isset($uploaded_docs[$docType]);
                            $doc = $hasDoc ? $uploaded_docs[$docType] : null;
                            $valInfo = $hasDoc ? get_document_validity_info($doc['expiration_date'] ?? null) : null;

                            $filePath = $hasDoc ? $doc['file_path'] : '';
                            if ($hasDoc && !str_starts_with($filePath, '../') && !str_starts_with($filePath, 'http')) {
                                $fileHref = '../' . ltrim($filePath, '/');
                            } else {
                                $fileHref = $filePath;
                            }
                            $ext = $hasDoc ? strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION)) : '';
                            $isPdf = $ext === 'pdf';

                            $valBadgeClass = 'bp-status-pill--gray';
                            if ($hasDoc) {
                                if ($valInfo['is_expired']) {
                                    $valBadgeClass = 'bp-status-pill--red';
                                } elseif (($valInfo['urgency'] ?? '') === 'urgent') {
                                    $valBadgeClass = 'bp-status-pill--red';
                                } elseif (($valInfo['urgency'] ?? '') === 'warning') {
                                    $valBadgeClass = 'bp-status-pill--amber';
                                } elseif ($valInfo['status'] === 'no_expiration') {
                                    $valBadgeClass = 'bp-status-pill--gray';
                                } else {
                                    $valBadgeClass = 'bp-status-pill--green';
                                }
                            }
                        ?>
                        <div class="bp-doc-card <?= $hasDoc ? '' : 'missing' ?>">

                            <div class="bp-doc-top">
                                <div class="bp-doc-header-left">
                                    <div class="bp-doc-icon <?= $hasDoc ? ($isPdf ? 'pdf' : 'img') : 'missing' ?>">
                                        <i class="bi <?= $hasDoc ? ($isPdf ? 'bi-file-earmark-pdf-fill' : 'bi-file-earmark-image-fill') : 'bi-file-earmark-x' ?>"></i>
                                    </div>
                                    <div class="bp-doc-title-box">
                                        <div class="bp-doc-name"><?= htmlspecialchars($docLabel) ?></div>
                                        <div class="bp-doc-meta">
                                            <?php if ($hasDoc): ?>
                                                <span><i class="bi bi-clock"></i> Uploaded <?= date('M j, Y · g:i A', strtotime($doc['upload_date'])) ?></span>
                                                <span>·</span>
                                                <a href="<?= htmlspecialchars($fileHref) ?>" target="_blank" class="bp-doc-link" title="Open and inspect uploaded file">
                                                    <i class="bi bi-box-arrow-up-right"></i> View File
                                                </a>
                                            <?php else: ?>
                                                <span class="doc-missing-note"><i class="bi bi-exclamation-circle-fill"></i> Not Uploaded / Pending Re-upload</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <?php if ($hasDoc): ?>
                                        <span class="bp-status-pill <?= $valBadgeClass ?>">
                                            <i class="bi <?= $valInfo['is_expired'] ? 'bi-x-circle-fill' : ($valInfo['status'] === 'valid' ? 'bi-check-circle-fill' : 'bi-info-circle-fill') ?>"></i>
                                            <?= htmlspecialchars($valInfo['label']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="bp-status-pill bp-status-pill--red">
                                            Missing Slot
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($hasDoc): ?>
                            <div class="bp-doc-form-row">
                                <!-- Set Expiration Form -->
                                <form method="POST" action="" class="bp-exp-form">
                                    <input type="hidden" name="action" value="update_expiration">
                                    <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">

                                    <label class="exp-label">
                                        <i class="bi bi-calendar-event"></i> Expiration:
                                    </label>
                                    <input type="date" name="expiration_date" class="bp-date-input"
                                           value="<?= htmlspecialchars($doc['expiration_date'] ?? '') ?>"
                                           title="Set official expiration date. Leave blank for documents with no expiration.">

                                    <button type="submit" class="bp-save-btn">
                                        <i class="bi bi-check-lg"></i> Save Date
                                    </button>
                                </form>

                                <!-- Reject Document Button -->
                                <button type="button" class="bp-reject-doc-btn"
                                        onclick="openRejectDocModal(<?= $doc['document_id'] ?>, '<?= htmlspecialchars(addslashes($docLabel)) ?>')">
                                    <i class="bi bi-trash3-fill"></i> Reject &amp; Delete File
                                </button>
                            </div>
                            <?php else: ?>
                            <div class="doc-missing-hint">
                                <i class="bi bi-info-circle"></i> The bidder must upload this document from their Account Settings before submitting bids.
                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

        </div>

    </div>

</div>
</main>

<!-- ══════════════════════════════════════════════════════════════
     MODALS: REJECT DOCUMENT, DEMOTE, APPROVE, REJECT APPLICATION
     ══════════════════════════════════════════════════════════════ -->

<!-- Reject Document Modal -->
<div id="rejectDocModal" class="modal-backdrop">
    <div class="bp-modal-box">
        <div class="bp-modal-body">
            <div class="bp-modal-icon modal-icon--red">
                <i class="bi bi-trash3-fill"></i>
            </div>
            <div class="bp-modal-title">Reject &amp; Delete Document</div>
            <div class="bp-modal-desc">
                Are you sure you want to reject <strong id="modalDocLabel" class="clr-dark"></strong>?<br><br>
                This will <strong>permanently delete the uploaded physical file</strong> and remove its record, allowing the bidder to upload a corrected document.
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject_document">
                <input type="hidden" id="modalDocId" name="document_id" value="">
                <div class="bp-modal-actions">
                    <button type="button" class="bp-btn-modal-cancel" onclick="closeModal('rejectDocModal')">Cancel</button>
                    <button type="submit" class="bp-btn btn-danger-solid">
                        <i class="bi bi-trash3"></i> Yes, Reject &amp; Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Demote Bidder Modal -->
<div id="demoteModal" class="modal-backdrop">
    <div class="bp-modal-box">
        <div class="bp-modal-body">
            <div class="bp-modal-icon modal-icon--amber">
                <i class="bi bi-person-down"></i>
            </div>
            <div class="bp-modal-title">Demote Bidder to Standard User</div>
            <div class="bp-modal-desc">
                This will revoke bidder privileges for <strong><?= htmlspecialchars($fullName) ?></strong> and revert the account back to a regular user.<br><br>
                <span class="modal-warn-text">Important:</span> All uploaded bidder documents and business profile records will be permanently erased. The underlying user login account will remain intact.
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="demote_bidder">
                <div class="bp-modal-actions">
                    <button type="button" class="bp-btn-modal-cancel" onclick="closeModal('demoteModal')">Cancel</button>
                    <button type="submit" class="bp-btn btn-warn-solid">
                        <i class="bi bi-check-circle"></i> Yes, Demote to User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Application Modal -->
<div id="approveModal" class="modal-backdrop">
    <div class="bp-modal-box">
        <div class="bp-modal-body">
            <div class="bp-modal-icon modal-icon--green">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="bp-modal-title">Approve Bidder Application</div>
            <div class="bp-modal-desc">
                Approve the supplier registration for <strong><?= htmlspecialchars($businessName) ?></strong>?<br><br>
                The user account role will be upgraded to <strong>Bidder</strong> and an official approval email will be sent to <strong><?= htmlspecialchars($bidder['email']) ?></strong>.
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="approve_application">
                <div class="bp-modal-actions">
                    <button type="button" class="bp-btn-modal-cancel" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="bp-btn bp-btn-approve">
                        <i class="bi bi-patch-check-fill"></i> Yes, Approve Bidder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Application Modal -->
<div id="rejectModal" class="modal-backdrop">
    <div class="bp-modal-box">
        <div class="bp-modal-body">
            <div class="bp-modal-icon modal-icon--red">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="bp-modal-title">Reject Bidder Application</div>
            <div class="bp-modal-desc">
                Mark the bidder registration for <strong><?= htmlspecialchars($businessName) ?></strong> as rejected. An email will notify the applicant.
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject_application">
                <div class="form-left-block">
                    <label class="form-label-block">
                        Optional Feedback / Reason for Rejection:
                    </label>
                    <textarea name="rejection_reason" class="bp-date-input textarea-full" placeholder="Specify why the application was declined (e.g., incomplete permits, illegible documents)..."></textarea>
                </div>
                <div class="bp-modal-actions">
                    <button type="button" class="bp-btn-modal-cancel" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="bp-btn btn-danger-solid">
                        <i class="bi bi-x-circle"></i> Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error toast-alert--solid-red" id="toastAlert">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
function openRejectDocModal(docId, docLabel) {
    document.getElementById('modalDocId').value = docId;
    document.getElementById('modalDocLabel').textContent = docLabel;
    document.getElementById('rejectDocModal').classList.add('open');
}

function openDemoteModal() {
    document.getElementById('demoteModal').classList.add('open');
}

function openApproveModal() {
    document.getElementById('approveModal').classList.add('open');
}

function openRejectModal() {
    document.getElementById('rejectModal').classList.add('open');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('open');
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('open');
    }
});

const toast = document.getElementById('toastAlert');
if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
