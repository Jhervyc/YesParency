<?php
    include("utils/protect-page.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($procurement_id === 0) {
        header("Location: procurement.php");
        exit();
    }

    // 1. Fetch Procurement Details
    $stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $procurement_id);
    $stmt->execute();
    $procurement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$procurement) {
        header("Location: procurement.php?error=not_found");
        exit();
    }

    // 2. Fetch Associated Lots
    $lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
    $lot_stmt->bind_param("i", $procurement_id);
    $lot_stmt->execute();
    $lots = $lot_stmt->get_result();

    // 3. Fetch Uploaded Documents
    $doc_stmt = $conn->prepare("SELECT * FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
    $doc_stmt->bind_param("i", $procurement_id);
    $doc_stmt->execute();
    $documents = $doc_stmt->get_result();

    // 4. Handle Document Delete
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc'])) {
        $doc_id = intval($_POST['doc_id']);
        $d = $conn->prepare("SELECT file_path FROM procurement_documents WHERE id = ? AND procurement_id = ?");
        $d->bind_param("ii", $doc_id, $procurement_id);
        $d->execute();
        $doc_row = $d->get_result()->fetch_assoc();
        $d->close();

        if ($doc_row) {
            if (file_exists($doc_row['file_path'])) {
                @unlink($doc_row['file_path']);
            }
            $del = $conn->prepare("DELETE FROM procurement_documents WHERE id = ?");
            $del->bind_param("i", $doc_id);
            $del->execute();
            $del->close();
            $_SESSION['alert_success'] = "Document removed.";
        } else {
            $_SESSION['alert_error'] = "Document not found.";
        }
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }

    // 5. Handle Edit Procurement (with optional new file uploads)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_procurement'])) {
        $title          = trim($_POST['title'] ?? '');
        $philgeps       = trim($_POST['philgeps_ref_no'] ?? '');
        $mode           = trim($_POST['procurement_mode'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $abc            = floatval($_POST['abc'] ?? 0);
        $posting_date   = $_POST['posting_date']  ?: null;
        $closing_date   = $_POST['closing_date']  ?: null;
        $opening_date   = $_POST['opening_date']  ?: null;

        $upd = $conn->prepare("
            UPDATE procurements
            SET title = ?, philgeps_ref_no = ?, procurement_mode = ?,
                description = ?, abc = ?,
                posting_date = ?, closing_date = ?, opening_date = ?
            WHERE id = ?
        ");
        $upd->bind_param("ssssdsssi",
            $title, $philgeps, $mode,
            $description, $abc,
            $posting_date, $closing_date, $opening_date,
            $procurement_id
        );

        if ($upd->execute()) {
            // Handle new file uploads
            if (!empty($_FILES['new_documents']['name'][0])) {
                $upload_dir = "../uploads/procurements/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

                $ins = $conn->prepare("INSERT INTO procurement_documents (procurement_id, document_name, file_path) VALUES (?, ?, ?)");
                foreach ($_FILES['new_documents']['tmp_name'] as $i => $tmp) {
                    if ($_FILES['new_documents']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $orig   = basename($_FILES['new_documents']['name'][$i]);
                    $unique = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
                    $target = $upload_dir . $unique;
                    if (move_uploaded_file($tmp, $target)) {
                        $ins->bind_param("iss", $procurement_id, $orig, $target);
                        $ins->execute();
                    }
                }
                $ins->close();
            }
            $_SESSION['alert_success'] = "Procurement updated successfully.";
        } else {
            $_SESSION['alert_error'] = "Failed to update procurement.";
        }
        $upd->close();
        header("Location: review_procurement.php?id=" . $procurement_id);
        exit();
    }

    // 5. Handle Final Publish Trigger
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_publish'])) {
        if ($lots->num_rows === 0) {
            $_SESSION['alert_error'] = "Cannot publish a procurement with zero lots. Please add at least one lot first.";
            header("Location: review_procurement.php?id=" . $procurement_id);
            exit();
        }

        $pub_stmt = $conn->prepare("UPDATE procurements SET status = 'open' WHERE id = ?");
        $pub_stmt->bind_param("i", $procurement_id);

        if ($pub_stmt->execute()) {
            $pub_stmt->close();
            $_SESSION['alert_success'] = "Procurement has been successfully published!";
            header("Location: procurement.php");
            exit();
        } else {
            $pub_stmt->close();
            $_SESSION['alert_error'] = "Failed to update procurement status.";
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Procurement | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        .modal-doc-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f7faf8;
            border: 1px solid #eaeeec;
            border-radius: 8px;
        }
    </style>
</head>
<body class="dash-body">

<div class="dash-overlay" id="dashOverlay" onclick="closeSidebar()"></div>

<!-- ========================= -->
<!-- SIDEBAR                   -->
<!-- ========================= -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="../index.php">
        <img src="../images/procure.jpg" alt="YesParency">
        <div class="sidebar-brand-text">
            <div class="name">YesParency</div>
            <div class="sub">Super Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item active"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>

        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item"><i class="bi bi-people"></i><span>Bidder Accounts</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-journal-text"></i><span>Audit Trail</span></a>

        <div class="nav-section-label">Super Admin</div>
        <a href="user-role-management.php" class="nav-item"><i class="bi bi-person-gear"></i><span>User & Role Management</span></a>

        <div class="nav-section-label">System</div>
        <a href="settings.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Super Administrator</div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout">
            <i class="bi bi-box-arrow-left"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- ========================= -->
<!-- TOPBAR                    -->
<!-- ========================= -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="topbar-title">Procurement > Review & Publish</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <!-- Page header -->
        <div class="page-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div>
                <h2>Review & Publish Procurement</h2>
                <p>Check all details carefully before publishing. This will make the procurement publicly visible.</p>
            </div>
            <a href="procurement.php" class="proc-action-btn manage" style="text-decoration:none;">
                <i class="bi bi-arrow-left"></i> Back to Procurements
            </a>
        </div>

        <!-- ========================= -->
        <!-- GENERAL INFO              -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-file-earmark-text"></i> General Information
            </div>
            <div class="review-grid">
                <div class="review-field">
                    <span class="review-label">PhilGEPS Reference No.</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['philgeps_ref_no'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Procurement Mode</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['procurement_mode']) ?></span>
                </div>
                <div class="review-field review-field-full">
                    <span class="review-label">Project Title</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['title']) ?></span>
                </div>
                <div class="review-field review-field-full">
                    <span class="review-label">Description</span>
                    <span class="review-value" style="white-space:pre-line;"><?= htmlspecialchars($procurement['description'] ?? 'None') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Approved Budget (ABC)</span>
                    <span class="review-value review-value-accent">₱<?= number_format($procurement['abc'], 2) ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Status</span>
                    <span class="proc-status-pill <?= strtolower($procurement['status']) ?>" style="display:inline-block;">
                        <?= htmlspecialchars($procurement['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ========================= -->
        <!-- DATES                     -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-calendar3"></i> Important Dates
            </div>
            <div class="review-grid">
                <div class="review-field">
                    <span class="review-label">Posting Date</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['posting_date'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Bid Submission Deadline</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['closing_date'] ?? 'N/A') ?></span>
                </div>
                <div class="review-field">
                    <span class="review-label">Bid Opening Date</span>
                    <span class="review-value"><?= htmlspecialchars($procurement['opening_date'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>

        <!-- ========================= -->
        <!-- DOCUMENTS                 -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-paperclip"></i> Uploaded Documents
                <span class="review-count"><?= $documents->num_rows ?></span>
            </div>

            <?php if ($documents->num_rows > 0): ?>
                <div class="doc-file-list">
                    <?php while ($doc = $documents->fetch_assoc()): ?>
                        <li>
                            <i class="bi bi-file-earmark-pdf"></i>
                            <span>
                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" style="color:#06251b; font-weight:600; text-decoration:none;">
                                    <?= htmlspecialchars($doc['document_name']) ?>
                                </a>
                            </span>
                            <small><i class="bi bi-box-arrow-up-right" style="font-size:11px;"></i> View</small>
                        </li>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="review-empty">
                    <i class="bi bi-file-earmark-x"></i> No documents uploaded for this procurement.
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= -->
        <!-- LOTS                      -->
        <!-- ========================= -->
        <div class="review-section">
            <div class="review-section-title">
                <i class="bi bi-layers"></i> Associated Lots
                <span class="review-count"><?= $lots->num_rows ?></span>
            </div>

            <?php if ($lots->num_rows > 0): ?>
                <?php
                    $total_lots_abc = 0;
                    $lots_data = [];
                    while ($lot = $lots->fetch_assoc()) {
                        $total_lots_abc += $lot['abc'];
                        $lots_data[] = $lot;
                    }
                ?>
                <div style="overflow-x:auto;">
                    <table class="awards-table">
                        <thead>
                            <tr>
                                <th>Lot No.</th>
                                <th>Lot Title</th>
                                <th>Description</th>
                                <th>ABC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lots_data as $lot): ?>
                            <tr>
                                <td><strong>Lot <?= htmlspecialchars($lot['lot_number']) ?></strong></td>
                                <td><?= htmlspecialchars($lot['lot_title']) ?></td>
                                <td style="color:#888; font-size:13px;"><?= htmlspecialchars($lot['description'] ?: '—') ?></td>
                                <td>₱<?= number_format($lot['abc'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right; font-weight:700; color:#06251b;">Total Lots ABC</td>
                                <td style="font-weight:700; color:#06251b;">₱<?= number_format($total_lots_abc, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="review-empty warn">
                    <i class="bi bi-exclamation-triangle"></i>
                    No lots configured yet. You must add at least one lot before publishing.
                    <a href="manage_lots.php?id=<?= $procurement_id ?>" style="color:#06251b; font-weight:600; margin-left:8px;">Add Lots →</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= -->
        <!-- ACTION BAR                -->
        <!-- ========================= -->
        <div class="review-action-bar">
            <a href="manage_lots.php?id=<?= $procurement_id ?>" class="proc-action-btn review" style="text-decoration:none; padding:12px 20px;">
                <i class="bi bi-pencil-square"></i> Edit Lots
            </a>

            <button onclick="openEditModal()" class="proc-action-btn review" style="border:none; cursor:pointer; padding:12px 20px;">
                <i class="bi bi-pencil"></i> Edit Procurement
            </button>

            <form method="POST" action="" id="publishForm">
                <button type="button" class="btn-publish-final" onclick="openPublishConfirm()">
                    <i class="bi bi-send-check"></i> Confirm & Publish
                </button>
                <input type="hidden" name="confirm_publish" value="1">
            </form>
        </div>

    </div>
</main>

<!-- ========================= -->
<!-- EDIT PROCUREMENT MODAL    -->
<!-- ========================= -->
<div id="editProcModal" class="modal-backdrop" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-box" style="max-width:680px; width:95%; max-height:90vh; overflow-y:auto; border-radius:18px; padding:0;">

        <!-- Modal header -->
        <div class="smd-header">
            <div class="smd-header-left">
                <div class="smd-header-icon"><i class="bi bi-pencil-square"></i></div>
                <div>
                    <div class="smd-header-title">Edit Procurement</div>
                    <div class="smd-header-sub">Update general info, dates and documents</div>
                </div>
            </div>
            <button class="smd-close" onclick="closeEditModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal form -->
        <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="edit_procurement" value="1">
        <div style="padding:24px; display:flex; flex-direction:column; gap:18px;">

            <!-- Title -->
            <div class="form-group">
                <label>Project Title</label>
                <div class="input-wrapper">
                    <i class="bi bi-folder2 input-icon-left"></i>
                    <input type="text" name="title" value="<?= htmlspecialchars($procurement['title']) ?>" required>
                </div>
            </div>

            <!-- PhilGEPS + Mode -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>PhilGEPS Reference No.</label>
                    <div class="input-wrapper">
                        <i class="bi bi-hash input-icon-left"></i>
                        <input type="text" name="philgeps_ref_no" value="<?= htmlspecialchars($procurement['philgeps_ref_no'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Procurement Mode</label>
                    <div class="input-wrapper">
                        <i class="bi bi-sliders input-icon-left"></i>
                        <select name="procurement_mode">
                            <?php
                            $modes = ['Competitive Bidding','Shopping','Negotiated Procurement','Direct Contracting','Limited Source Bidding','Repeat Order'];
                            foreach ($modes as $m): ?>
                                <option value="<?= $m ?>" <?= $procurement['procurement_mode'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ABC -->
            <div class="form-group">
                <label>Approved Budget for the Contract (ABC)</label>
                <div class="input-wrapper">
                    <i class="bi bi-currency-exchange input-icon-left"></i>
                    <input type="number" name="abc" step="0.01" min="0" value="<?= htmlspecialchars($procurement['abc']) ?>" required>
                </div>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" style="width:100%; padding:12px 14px; border:1.5px solid #e0e0e0; border-radius:10px; font-family:'Poppins',sans-serif; font-size:13.5px; resize:vertical; outline:none; box-sizing:border-box;"><?= htmlspecialchars($procurement['description'] ?? '') ?></textarea>
            </div>

            <!-- Dates -->
            <div style="display:flex; gap:16px; overflow-x:auto; padding-bottom:4px;">
                <div class="form-group" style="min-width:200px; flex:1;">
                    <label>Posting Date</label>
                    <div class="input-wrapper">
                        <i class="bi bi-calendar input-icon-left"></i>
                        <input type="date" name="posting_date" value="<?= htmlspecialchars($procurement['posting_date'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group" style="min-width:200px; flex:1;">
                    <label>Submission Deadline</label>
                    <div class="input-wrapper">
                        <i class="bi bi-calendar-x input-icon-left"></i>
                        <input type="datetime-local" name="closing_date" value="<?= $procurement['closing_date'] ? date('Y-m-d\TH:i', strtotime($procurement['closing_date'])) : '' ?>">
                    </div>
                </div>
                <div class="form-group" style="min-width:200px; flex:1;">
                    <label>Bid Opening Date</label>
                    <div class="input-wrapper">
                        <i class="bi bi-calendar-check input-icon-left"></i>
                        <input type="datetime-local" name="opening_date" value="<?= $procurement['opening_date'] ? date('Y-m-d\TH:i', strtotime($procurement['opening_date'])) : '' ?>">
                    </div>
                </div>
            </div>

        </div>

        <!-- Documents section -->
        <div style="padding:0 24px 24px; display:flex; flex-direction:column; gap:12px;">
            <div style="font-size:12px; font-weight:700; color:#06251b; text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; gap:6px;">
                <i class="bi bi-paperclip"></i> Documents
            </div>

            <!-- Existing docs -->
            <?php
            // Re-fetch docs fresh (result pointer may be exhausted)
            $modal_docs_stmt = $conn->prepare("SELECT id, document_name, file_path FROM procurement_documents WHERE procurement_id = ? ORDER BY uploaded_at DESC");
            $modal_docs_stmt->bind_param("i", $procurement_id);
            $modal_docs_stmt->execute();
            $modal_docs = $modal_docs_stmt->get_result();
            ?>
            <?php if ($modal_docs->num_rows > 0): ?>
            <div style="display:flex; flex-direction:column; gap:6px;" id="existingDocsList">
                <?php while ($md = $modal_docs->fetch_assoc()): ?>
                <div class="modal-doc-row" id="doc-row-<?= $md['id'] ?>">
                    <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:0;">
                        <i class="bi bi-file-earmark-text" style="color:#06251b; font-size:15px; flex-shrink:0;"></i>
                        <a href="<?= htmlspecialchars($md['file_path']) ?>" target="_blank"
                           style="font-size:12.5px; font-weight:600; color:#06251b; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= htmlspecialchars($md['document_name']) ?>
                        </a>
                    </div>
                    <form method="POST" action="" style="flex-shrink:0;"
                          onsubmit="return confirm('Remove this document?');">
                        <input type="hidden" name="delete_doc" value="1">
                        <input type="hidden" name="doc_id" value="<?= $md['id'] ?>">
                        <button type="submit"
                                style="background:#fff0f0; border:1.5px solid #f5c6c6; color:#c23b3b;
                                       border-radius:7px; padding:4px 10px; font-size:11px; font-weight:700;
                                       cursor:pointer; display:inline-flex; align-items:center; gap:4px;
                                       font-family:'Poppins',sans-serif;">
                            <i class="bi bi-trash3"></i> Remove
                        </button>
                    </form>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div style="font-size:12px; color:#aaa; padding:8px 0;">No documents uploaded yet.</div>
            <?php endif; ?>
            <?php $modal_docs_stmt->close(); ?>

            <!-- Upload new files -->
            <div>
                <label style="display:flex; flex-direction:column; align-items:center; gap:6px;
                              padding:18px; border:2px dashed #c8d8cc; border-radius:10px;
                              background:#f7faf8; cursor:pointer; transition:border-color .2s;"
                       onmouseover="this.style.borderColor='#06251b'" onmouseout="this.style.borderColor='#c8d8cc'">
                    <i class="bi bi-cloud-upload" style="font-size:24px; color:#06251b;"></i>
                    <span style="font-size:13px; font-weight:600; color:#333;">Click to upload files</span>
                    <span style="font-size:11px; color:#aaa;">PDF, DOCX, XLSX, PNG, JPG — multiple allowed</span>
                    <input type="file" name="new_documents[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
                           style="display:none;" onchange="previewNewFiles(this)">
                </label>
                <div id="newFilePreview" style="margin-top:8px; display:flex; flex-direction:column; gap:5px;"></div>
            </div>
        </div>

        <!-- Modal footer -->
        <div style="display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #eaeeec; background:#fafbfa;">
            <button type="button" onclick="closeEditModal()" style="padding:10px 20px; background:#fff; border:1.5px solid #d4e0d8; border-radius:9px; font-size:13px; font-weight:600; color:#4a5e54; cursor:pointer; font-family:'Poppins',sans-serif;">
                Cancel
            </button>
            <button type="submit" style="padding:10px 24px; background:#06251b; color:#ffc107; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:7px;">
                <i class="bi bi-check2"></i> Save Changes
            </button>
        </div>
        </form>

    </div>
</div>

<!-- ========================= -->
<!-- PUBLISH CONFIRM MODAL     -->
<!-- ========================= -->
<div id="publishConfirmModal" class="modal-backdrop" onclick="if(event.target===this)closePublishConfirm()">
    <div class="urm-modal">
        <button class="urm-modal-close" onclick="closePublishConfirm()" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="urm-modal-icon-wrap">
            <div class="urm-modal-icon" style="background:#e8f5e9; color:#1f7a3d;">
                <i class="bi bi-send-check"></i>
            </div>
        </div>

        <div class="urm-modal-text">
            <h3>Publish Procurement</h3>
            <p>This procurement will become publicly visible and open for bidding. This action cannot be undone.</p>
            <div class="urm-modal-user-pill"><?= htmlspecialchars($procurement['title']) ?></div>
        </div>

        <div class="urm-modal-actions">
            <button type="button" onclick="closePublishConfirm()" class="urm-btn-cancel">
                Cancel
            </button>
            <button type="button" onclick="document.getElementById('publishForm').submit()"
                    class="urm-btn-confirm" style="background:#1f7a3d;">
                <i class="bi bi-send-check"></i> Yes, Publish
            </button>
        </div>
    </div>
</div>

<!-- Session alerts -->
<?php if (isset($_SESSION['alert_success'])): ?>
    <div class="toast-alert success" id="toastAlert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_success']) ?>
    </div>
    <?php unset($_SESSION['alert_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['alert_error'])): ?>
    <div class="toast-alert error" id="toastAlert">
        <i class="bi bi-x-circle-fill"></i>
        <?= htmlspecialchars($_SESSION['alert_error']) ?>
    </div>
    <?php unset($_SESSION['alert_error']); ?>
<?php endif; ?>

<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('dashOverlay');

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }

    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);

    function openEditModal()  { document.getElementById('editProcModal').classList.add('open'); }
    function closeEditModal() { document.getElementById('editProcModal').classList.remove('open'); }

    function openPublishConfirm()  { document.getElementById('publishConfirmModal').classList.add('open'); }
    function closePublishConfirm() { document.getElementById('publishConfirmModal').classList.remove('open'); }

    function previewNewFiles(input) {
        const preview = document.getElementById('newFilePreview');
        preview.innerHTML = '';
        Array.from(input.files).forEach(f => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 10px;background:#f0f4f2;border-radius:7px;font-size:12px;color:#333;';
            row.innerHTML = '<i class="bi bi-file-earmark" style="color:#06251b;flex-shrink:0;"></i>'
                          + '<span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + f.name + '</span>'
                          + '<span style="color:#aaa;white-space:nowrap;">' + (f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : (f.size/1024).toFixed(0)+' KB') + '</span>';
            preview.appendChild(row);
        });
    }
</script>

</body>
</html>
