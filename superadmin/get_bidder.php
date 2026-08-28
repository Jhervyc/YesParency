<?php

include("utils/protect-page.php");

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$userId = (int)$_GET['id'];

/*
|--------------------------------------------------------------------------
| USER + BIDDER PROFILE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.*,
        bp.*
    FROM users u
    LEFT JOIN bidder_profiles bp
        ON u.user_id = bp.user_id
    WHERE u.user_id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();

if($result->num_rows == 0){
    die("User not found.");
}

$data = $result->fetch_assoc();

/*
|--------------------------------------------------------------------------
| DOCUMENTS
|--------------------------------------------------------------------------
*/

$docStmt = $conn->prepare("
    SELECT *
    FROM bidder_documents
    WHERE user_id = ?
");

$docStmt->bind_param("i", $userId);
$docStmt->execute();
$docResult = $docStmt->get_result();

/*
|--------------------------------------------------------------------------
| OUTPUT
|--------------------------------------------------------------------------
*/

// Status pill class
$statusClass = 'closed';
if ($data['status'] === 'pending')  $statusClass = 'upcoming';
if ($data['status'] === 'active')   $statusClass = 'open';
if ($data['role']   === 'bidder')   $statusClass = 'open';

// Document type labels
$docLabels = [
    'dti_sec_cda'        => 'DTI / SEC / CDA Certificate',
    'mayor_permit'       => "Mayor's / Business Permit",
    'bir_certificate'    => 'BIR Certificate (Form 2303)',
    'philgeps_certificate' => 'PhilGEPS Certificate',
    'government_id'      => 'Government-Issued ID',
];
$avatarUrl = !empty($data['profile_picture_url']) ? '../' . ltrim($data['profile_picture_url'], '/') : '';
$initials  = strtoupper(substr($data['firstname'] ?? 'B', 0, 1) . substr($data['lastname'] ?? 'P', 0, 1));
?>

<!-- Bidder name + status -->
<div class="gb-header">
    <div class="gb-avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center; border-radius:50%;">
        <?php if (!empty($avatarUrl)): ?>
            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($initials) ?>" style="width:100%; height:100%; object-fit:cover;">
        <?php else: ?>
            <i class="bi bi-person-fill"></i>
        <?php endif; ?>
    </div>
    <div>
        <div class="gb-name">
            <?= htmlspecialchars($data['firstname'] . ' ' . $data['lastname']) ?>
        </div>
        <span class="proc-status-pill <?= $statusClass ?>">
            <?= $data['role'] === 'bidder' ? 'Approved Bidder' : htmlspecialchars($data['status']) ?>
        </span>
    </div>
</div>

<!-- Account Info -->
<div class="gb-section">
    <div class="gb-section-title"><i class="bi bi-person-badge"></i> Account Information</div>
    <div class="gb-fields">
        <div class="gb-field">
            <span class="gb-label">Role</span>
            <span class="gb-value"><?= htmlspecialchars($data['role']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">Status</span>
            <span class="gb-value"><?= htmlspecialchars($data['status']) ?></span>
        </div>
        <div class="gb-field gb-field-full">
            <span class="gb-label">Email</span>
            <span class="gb-value"><?= htmlspecialchars($data['email']) ?></span>
        </div>
    </div>
</div>

<!-- Business Info -->
<?php if (!empty($data['business_name'])): ?>
<div class="gb-section">
    <div class="gb-section-title"><i class="bi bi-building"></i> Business Information</div>
    <div class="gb-fields">
        <div class="gb-field gb-field-full">
            <span class="gb-label">Business Name</span>
            <span class="gb-value"><?= htmlspecialchars($data['business_name']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">PhilGEPS Number</span>
            <span class="gb-value"><?= htmlspecialchars($data['philgeps_number']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">TIN Number</span>
            <span class="gb-value"><?= htmlspecialchars($data['tin_number']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">Business Type</span>
            <span class="gb-value"><?= htmlspecialchars($data['business_type']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">Year Established</span>
            <span class="gb-value"><?= htmlspecialchars($data['year_established']) ?></span>
        </div>
        <div class="gb-field gb-field-full">
            <span class="gb-label">Business Address</span>
            <span class="gb-value"><?= htmlspecialchars($data['business_address']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">Business Email</span>
            <span class="gb-value"><?= htmlspecialchars($data['business_email']) ?></span>
        </div>
        <div class="gb-field">
            <span class="gb-label">Business Phone</span>
            <span class="gb-value"><?= htmlspecialchars($data['business_phone']) ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Documents -->
<div class="gb-section">
    <div class="gb-section-title"><i class="bi bi-paperclip"></i> Uploaded Documents</div>

    <?php if ($docResult->num_rows === 0): ?>
        <div class="gb-empty">
            <i class="bi bi-file-earmark-x"></i> No documents uploaded.
        </div>
    <?php else: ?>
        <div class="gb-doc-list">
        <?php while ($doc = $docResult->fetch_assoc()):
            $label = $docLabels[$doc['document_type']] ?? ucwords(str_replace('_', ' ', $doc['document_type']));
            $doc_href = $doc['file_path'];
            if (strpos($doc_href, '../') === 0) {
                // already has ../
            } elseif (strpos($doc_href, 'uploads/') === 0) {
                $doc_href = '../' . $doc_href;
            }
        ?>
            <div class="gb-doc-item">
                <div class="gb-doc-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                <div class="gb-doc-info">
                    <span><?= htmlspecialchars($label) ?></span>
                </div>
                <a href="<?= htmlspecialchars($doc_href) ?>" target="_blank" class="gb-doc-link">
                    <i class="bi bi-box-arrow-up-right"></i> View
                </a>
            </div>
        <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Approve / Reject -->
<?php if ($data['role'] === 'user'): ?>
<div class="gb-actions">
    <a href="approve_bidder.php?id=<?= $userId ?>" class="gb-btn-approve">
        <i class="bi bi-check-circle-fill"></i> Approve Bidder
    </a>
    <a href="reject_bidder.php?id=<?= $userId ?>" class="gb-btn-reject">
        <i class="bi bi-x-circle-fill"></i> Reject
    </a>
</div>
<?php endif; ?>
