<?php 
    include("utils/protect-page.php");
    include("utils/protect-secretariat.php");

    $procurement_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['procurement_id']) ? intval($_GET['procurement_id']) : 0);

    if ($procurement_id === 0) {
        header("Location: procurement.php");
        exit();
    }

    // ------------------------------------
    // 1. Fetch Procurement Details
    // ------------------------------------
    $stmt = $conn->prepare("SELECT * FROM procurements WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $procurement_id);
    $stmt->execute();
    $proc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proc) {
        $_SESSION['alert_error'] = "Procurement project not found.";
        header("Location: procurement.php");
        exit();
    }

    // ------------------------------------
    // 2. ADD LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_lot'])) {
        $lot_number  = intval($_POST['lot_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $abc         = floatval($_POST['abc'] ?? 0);

        if ($lot_number <= 0) {
            $_SESSION['alert_error'] = "Please provide a valid Lot Number greater than 0.";
        } elseif (empty($title)) {
            $_SESSION['alert_error'] = "Lot Title is required.";
        } elseif ($abc <= 0) {
            $_SESSION['alert_error'] = "Approved Budget for the Lot (ABC) must be greater than 0.";
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? LIMIT 1");
            $check_stmt->bind_param("ii", $procurement_id, $lot_number);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "Lot Number #{$lot_number} already exists in this procurement!";
            } else {
                $check_stmt->close();
                $stmt = $conn->prepare("
                    INSERT INTO lots (procurement_id, lot_number, lot_title, description, abc)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissd", $procurement_id, $lot_number, $title, $description, $abc);
                if ($stmt->execute()) {
                    $new_lot_id = $conn->insert_id;
                    audit_log(
                        $conn,
                        'LOT_CREATED',
                        'lots',
                        $new_lot_id,
                        "Added Lot #{$lot_number}: {$title} to procurement #{$procurement_id}",
                        null,
                        [
                            'procurement_id' => $procurement_id,
                            'lot_number' => $lot_number,
                            'lot_title' => $title,
                            'description' => $description,
                            'abc' => $abc
                        ]
                    );
                    $_SESSION['alert_success'] = "Lot #{$lot_number} ({$title}) was added successfully.";
                } else {
                    $_SESSION['alert_error'] = "Failed to add lot. Please try again.";
                }
                $stmt->close();
            }
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 3. UPDATE LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_lot'])) {
        $lot_id      = intval($_POST['lot_id'] ?? 0);
        $lot_number  = intval($_POST['lot_number'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $abc         = floatval($_POST['abc'] ?? 0);

        if ($lot_id <= 0 || $lot_number <= 0) {
            $_SESSION['alert_error'] = "Invalid lot record or lot number.";
        } elseif (empty($title)) {
            $_SESSION['alert_error'] = "Lot Title is required.";
        } elseif ($abc <= 0) {
            $_SESSION['alert_error'] = "Approved Budget for the Lot (ABC) must be greater than 0.";
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM lots WHERE procurement_id = ? AND lot_number = ? AND id != ? LIMIT 1");
            $check_stmt->bind_param("iii", $procurement_id, $lot_number, $lot_id);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "Lot Number #{$lot_number} is already assigned to another lot!";
            } else {
                $check_stmt->close();

                // Snapshot before update
                $snap = $conn->prepare("SELECT lot_number, lot_title, description, abc, status FROM lots WHERE id = ?");
                $snap->bind_param("i", $lot_id);
                $snap->execute();
                $old_lot = $snap->get_result()->fetch_assoc();
                $snap->close();

                $stmt = $conn->prepare("
                    UPDATE lots 
                    SET lot_number = ?, lot_title = ?, description = ?, abc = ? 
                    WHERE id = ? AND procurement_id = ?
                ");
                $stmt->bind_param("issdii", $lot_number, $title, $description, $abc, $lot_id, $procurement_id);
                if ($stmt->execute()) {
                    audit_log(
                        $conn,
                        'LOT_UPDATED',
                        'lots',
                        $lot_id,
                        "Updated Lot #{$lot_number}: {$title}",
                        [
                            'lot_number' => (int)($old_lot['lot_number'] ?? 0),
                            'lot_title' => $old_lot['lot_title'] ?? '',
                            'description' => $old_lot['description'] ?? '',
                            'abc' => (float)($old_lot['abc'] ?? 0)
                        ],
                        [
                            'lot_number' => $lot_number,
                            'lot_title' => $title,
                            'description' => $description,
                            'abc' => $abc
                        ]
                    );
                    $_SESSION['alert_success'] = "Lot #{$lot_number} details have been updated.";
                } else {
                    $_SESSION['alert_error'] = "Failed to update lot details.";
                }
                $stmt->close();
            }
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 4. DELETE LOT
    // ------------------------------------
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_lot'])) {
        $lot_id = intval($_POST['lot_id'] ?? 0);

        if ($lot_id > 0) {
            // Snapshot before deletion
            $snap = $conn->prepare("SELECT lot_number, lot_title, description, abc, status FROM lots WHERE id = ?");
            $snap->bind_param("i", $lot_id);
            $snap->execute();
            $old_lot = $snap->get_result()->fetch_assoc();
            $snap->close();

            $stmt = $conn->prepare("DELETE FROM lots WHERE id = ? AND procurement_id = ?");
            $stmt->bind_param("ii", $lot_id, $procurement_id);
            if ($stmt->execute()) {
                $ltitle = $old_lot['lot_title'] ?? "#$lot_id";
                $lnum = $old_lot['lot_number'] ?? '';
                audit_log(
                    $conn,
                    'LOT_DELETED',
                    'lots',
                    $lot_id,
                    "Deleted Lot #{$lnum} ({$ltitle}) from procurement #{$procurement_id}",
                    $old_lot,
                    null
                );
                $_SESSION['alert_success'] = "Lot was removed successfully.";
            } else {
                $_SESSION['alert_error'] = "Failed to delete lot.";
            }
            $stmt->close();
        }

        header("Location: manage_lots.php?id=" . $procurement_id);
        exit();
    }

    // ------------------------------------
    // 5. Fetch Existing Lots & Statistics
    // ------------------------------------
    $lot_stmt = $conn->prepare("SELECT * FROM lots WHERE procurement_id = ? ORDER BY lot_number ASC");
    $lot_stmt->bind_param("i", $procurement_id);
    $lot_stmt->execute();
    $lots_res = $lot_stmt->get_result();

    $lots = [];
    $total_lots_abc = 0.0;
    $max_lot_number = 0;

    while ($l = $lots_res->fetch_assoc()) {
        $total_lots_abc += (float)$l['abc'];
        if ((int)$l['lot_number'] > $max_lot_number) {
            $max_lot_number = (int)$l['lot_number'];
        }
        $lots[] = $l;
    }
    $lot_stmt->close();

    $proc_abc = (float)$proc['abc'];
    $remaining_abc = $proc_abc - $total_lots_abc;
    $lots_count = count($lots);
    $budget_pct = $proc_abc > 0 ? min(100, round(($total_lots_abc / $proc_abc) * 100, 1)) : 0;
    $suggested_next_lot = $max_lot_number + 1;

    // Status Badge Helpers
    $p_status = strtolower($proc['status'] ?? 'draft');
    $status_label = ucfirst($p_status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lots: <?= htmlspecialchars($proc['title']) ?> | YesParency</title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Shared Stylesheets -->
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-shell.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="../css/pages/admin-manage-lots.css">
</head>
<body class="dash-body">

<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
<div class="dash-content">

    <!-- ── Top Nav & Breadcrumbs ── -->
    <div class="vp-nav-bar">
        <div class="vp-breadcrumbs">
            <a href="procurement.php"><i class="bi bi-folder2-open"></i> Procurements</a>
            <span>/</span>
            <span>Manage Lots</span>
        </div>
        <div>
            <a href="procurement.php" class="vp-back-link">
                <i class="bi bi-check2-circle text-success"></i> Done — Back to Procurements
            </a>
        </div>
    </div>

    <!-- ── Hero Card Banner ── -->
    <div class="vp-hero-card">
        <div class="vp-hero-top">
            <div class="vp-hero-badges">
                <span class="hero-pill ref" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($proc['slsu_ref_no']) ?>');" title="Click to copy">
                    <i class="bi bi-hash"></i> <?= htmlspecialchars($proc['slsu_ref_no']) ?> <i class="bi bi-copy ref-copy-icon"></i>
                </span>
                <span class="hero-pill mode">
                    <i class="bi bi-briefcase"></i> <?= htmlspecialchars($proc['procurement_mode']) ?>
                </span>
                <span class="hero-pill status">
                    <i class="bi bi-circle-fill status-dot-live"></i> <?= $status_label ?>
                </span>
            </div>
        </div>

        <div class="vp-hero-title">
            <?= htmlspecialchars($proc['title']) ?>
        </div>

        <!-- Hero Metrics -->
        <div class="vp-hero-metrics">
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-cash-stack"></i> Total Project ABC</div>
                <div class="vp-hero-metric-val gold">₱ <?= number_format($proc_abc, 2) ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-layers"></i> Configured Lots</div>
                <div class="vp-hero-metric-val"><?= $lots_count ?> <?= $lots_count === 1 ? 'Lot' : 'Lots' ?></div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-pie-chart"></i> Allocated Budget</div>
                <div class="vp-hero-metric-val">₱ <?= number_format($total_lots_abc, 2) ?> (<?= $budget_pct ?>%)</div>
            </div>
            <div class="vp-hero-metric-item">
                <div class="vp-hero-metric-lbl"><i class="bi bi-wallet2"></i> Remaining Unallocated</div>
                <div class="vp-hero-metric-val <?= $remaining_abc < 0 ? 'hero-metric-val--danger' : '' ?>">
                    ₱ <?= number_format($remaining_abc, 2) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Stats Summary Grid ── -->
    <div class="stats-summary-grid">
        <div class="stat-ring-card">
            <div class="stat-ring-wrap green">
                <i class="bi bi-layers-fill stat-ring-icon"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num"><?= $lots_count ?></div>
                <div class="stat-ring-lbl">Total Project Lots</div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap gold">
                <i class="bi bi-cash-stack stat-ring-icon"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num">₱ <?= number_format($total_lots_abc, 2) ?></div>
                <div class="stat-ring-lbl">Lots ABC Sum</div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap <?= $remaining_abc < 0 ? 'red' : 'blue' ?>">
                <i class="bi <?= $remaining_abc < 0 ? 'bi-exclamation-triangle-fill' : 'bi-wallet-fill' ?> stat-ring-icon"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num <?= $remaining_abc < 0 ? 'stat-ring-num--danger' : '' ?>">₱ <?= number_format($remaining_abc, 2) ?></div>
                <div class="stat-ring-lbl"><?= $remaining_abc < 0 ? 'Overbudget Deficit' : 'Unallocated Balance' ?></div>
            </div>
        </div>

        <div class="stat-ring-card">
            <div class="stat-ring-wrap green">
                <i class="bi bi-percent stat-ring-icon"></i>
            </div>
            <div class="stat-ring-info">
                <div class="stat-ring-num"><?= $budget_pct ?>%</div>
                <div class="stat-ring-lbl">Budget Allocated</div>
            </div>
        </div>
    </div>

    <!-- ── Two-Column Main Layout ── -->
    <div class="vp-grid-layout">

        <!-- ── LEFT COLUMN: Existing Lots Management ── -->
        <div class="vp-left-col">

            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-layers text-success"></i> Existing Project Lots
                        <span class="vp-card-count"><?= $lots_count ?> Configured</span>
                    </div>
                </div>

                <div class="vp-card-body">
                    
                    <!-- Budget Allocation Meter -->
                    <div class="allocation-meter-card">
                        <div class="alloc-header-row">
                            <span><i class="bi bi-bar-chart-fill clr-forest"></i> ABC Allocation Progress</span>
                            <span>₱ <?= number_format($total_lots_abc, 2) ?> / ₱ <?= number_format($proc_abc, 2) ?> (<?= $budget_pct ?>%)</span>
                        </div>
                        <div class="alloc-progress-track">
                            <div class="alloc-progress-fill <?= $remaining_abc < 0 ? 'overbudget' : '' ?>" style="--fill-pct: <?= min(100, $budget_pct) ?>%;"></div>
                        </div>
                        <div class="alloc-sub-text">
                            <?php if ($remaining_abc < 0): ?>
                                <span class="alloc-warn-text"><i class="bi bi-exclamation-triangle-fill"></i> Warning: Total lot ABC exceeds overall project ABC budget by ₱ <?= number_format(abs($remaining_abc), 2) ?>!</span>
                            <?php elseif ($remaining_abc == 0): ?>
                                <span class="alloc-ok-text"><i class="bi bi-check-circle-fill"></i> 100% of project budget is perfectly allocated across lots.</span>
                            <?php else: ?>
                                <span>₱ <?= number_format($remaining_abc, 2) ?> remaining to be allocated to other lots or contingencies.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lots List -->
                    <?php if ($lots_count > 0): ?>
                        <div class="lots-list-group">
                            <?php foreach ($lots as $lot): ?>
                                <div class="lot-row-card">
                                    <div class="lot-badge-num">
                                        <?= htmlspecialchars($lot['lot_number']) ?>
                                    </div>
                                    <div class="lot-main-info">
                                        <div class="lot-title-heading">
                                            <span>Lot <?= htmlspecialchars($lot['lot_number']) ?>: <?= htmlspecialchars($lot['lot_title']) ?></span>
                                        </div>
                                        <?php if (!empty($lot['description'])): ?>
                                            <div class="lot-desc-text">
                                                <?= nl2br(htmlspecialchars($lot['description'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="lot-meta-tags">
                                            <span class="lot-abc-pill">
                                                <i class="bi bi-cash-stack"></i> ABC: ₱ <?= number_format($lot['abc'], 2) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="lot-action-btns">
                                        <button type="button" 
                                                class="btn-lot-action edit"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($lot)) ?>)"
                                                title="Edit Lot #<?= htmlspecialchars($lot['lot_number']) ?>">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </button>

                                        <button type="button" 
                                                class="btn-lot-action delete"
                                                onclick="openDeleteModal(<?= (int)$lot['id'] ?>, <?= htmlspecialchars(json_encode($lot['lot_number'])) ?>, <?= htmlspecialchars(json_encode($lot['lot_title'])) ?>)"
                                                title="Delete Lot">
                                            <i class="bi bi-trash3"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="vp-empty-state">
                            <i class="bi bi-layers"></i>
                            <h4>No Lots Added Yet</h4>
                            <p>This procurement project does not have any configured lots. Use the form on the right to add Lot #1.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- ── RIGHT COLUMN: Add Lot Form + Allocation Guidelines ── -->
        <div class="vp-right-col">

            <!-- Card 1: Add Lot Form -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-plus-circle-fill text-success"></i> Add New Lot
                    </div>
                </div>

                <div class="vp-card-body">
                    <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST" id="addLotForm">
                        <input type="hidden" name="procurement_id" value="<?= $procurement_id ?>">

                        <!-- Lot Number -->
                        <div class="field-group">
                            <label class="field-label" for="add_lot_number">
                                Lot Number <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="number" 
                                       id="add_lot_number" 
                                       name="lot_number" 
                                       min="1" 
                                       value="<?= $suggested_next_lot ?>" 
                                       placeholder="e.g. 1" 
                                       required>
                                <i class="bi bi-hash input-icon"></i>
                            </div>
                            <span class="field-hint">Sequential lot identifier in bidding docs</span>
                        </div>

                        <!-- Lot Title -->
                        <div class="field-group">
                            <label class="field-label" for="add_title">
                                Lot Title / Classification <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="text" 
                                       id="add_title" 
                                       name="title" 
                                       placeholder="e.g. Lot 1 - Supply & Delivery of IT Equipment" 
                                       required>
                                <i class="bi bi-tag input-icon"></i>
                            </div>
                        </div>

                        <!-- Lot Approved Budget (ABC) -->
                        <div class="field-group">
                            <label class="field-label" for="add_abc">
                                Lot Approved Budget (ABC in PHP) <span class="req">*</span>
                            </label>
                            <div class="input-icon-box">
                                <input type="number" 
                                       step="0.01" 
                                       min="0.01" 
                                       id="add_abc" 
                                       name="abc" 
                                       placeholder="0.00" 
                                       oninput="updateAddAbcPreview(this.value)" 
                                       required>
                                <i class="bi bi-currency-dollar input-icon"></i>
                            </div>
                            <div class="currency-preview-pill" id="addAbcFormattedPreview">
                                <i class="bi bi-cash-stack"></i>
                                <span>Formatted: ₱ 0.00</span>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="field-group">
                            <label class="field-label" for="add_description">
                                Lot Description &amp; Technical Scope
                            </label>
                            <div class="input-icon-box">
                                <textarea id="add_description" 
                                          name="description" 
                                          rows="3" 
                                          placeholder="Specify technical specifications, delivery terms, or bill of quantities for this lot..."></textarea>
                                <i class="bi bi-card-text input-icon"></i>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" name="add_lot" class="btn-submit-proposal mt-6">
                            <i class="bi bi-plus-lg"></i> Add Lot to Project
                        </button>
                    </form>
                </div>
            </div>

            <!-- Card 2: Lot Partitioning Guidelines -->
            <div class="vp-card">
                <div class="vp-card-head">
                    <div class="vp-card-title">
                        <i class="bi bi-info-circle text-success"></i> Lot Partitioning Guide
                    </div>
                </div>

                <div class="vp-card-body">
                    <div class="timeline-guide">
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Single vs Multi-Lot Procurements</div>
                            <div class="timeline-guide-sub">If the procurement has only 1 unified scope, create Lot 1 with the full ABC amount.</div>
                        </div>
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Independent Bidder Evaluation</div>
                            <div class="timeline-guide-sub">Each lot is evaluated and awarded separately according to lowest calculated responsive bid (LCRB).</div>
                        </div>
                        <div class="timeline-guide-step">
                            <div class="timeline-guide-dot"></div>
                            <div class="timeline-guide-title">Cumulative Budget Cap</div>
                            <div class="timeline-guide-sub">The sum of all lot ABC allocations should not exceed the overall project ABC of ₱ <?= number_format($proc_abc, 2) ?>.</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
</main>

<!-- ========================= -->
<!-- EDIT LOT MODAL            -->
<!-- ========================= -->
<div id="editLotModal" class="modal-backdrop">
    <div class="modal-dialog-box">
        <div class="modal-head">
            <h3><i class="bi bi-pencil-square"></i> Edit Lot Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST" id="editLotForm">
            <div class="modal-body-content">
                <input type="hidden" id="edit_lot_id" name="lot_id">

                <!-- Lot Number -->
                <div class="field-group">
                    <label class="field-label" for="edit_lot_number">
                        Lot Number <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="number" id="edit_lot_number" name="lot_number" min="1" required>
                        <i class="bi bi-hash input-icon"></i>
                    </div>
                </div>

                <!-- Lot Title -->
                <div class="field-group">
                    <label class="field-label" for="edit_title">
                        Lot Title <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="text" id="edit_title" name="title" required>
                        <i class="bi bi-tag input-icon"></i>
                    </div>
                </div>

                <!-- Lot ABC -->
                <div class="field-group">
                    <label class="field-label" for="edit_abc">
                        Approved Budget (ABC in PHP) <span class="req">*</span>
                    </label>
                    <div class="input-icon-box">
                        <input type="number" step="0.01" min="0.01" id="edit_abc" name="abc" oninput="updateEditAbcPreview(this.value)" required>
                        <i class="bi bi-currency-dollar input-icon"></i>
                    </div>
                    <div class="currency-preview-pill" id="editAbcFormattedPreview">
                        <i class="bi bi-cash-stack"></i>
                        <span>Formatted: ₱ 0.00</span>
                    </div>
                </div>

                <!-- Description -->
                <div class="field-group">
                    <label class="field-label" for="edit_description">
                        Description &amp; Specifications
                    </label>
                    <div class="input-icon-box">
                        <textarea id="edit_description" name="description" rows="3"></textarea>
                        <i class="bi bi-card-text input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" onclick="closeEditModal()" class="btn-modal-cancel">
                    Cancel
                </button>
                <button type="submit" name="update_lot" class="btn-modal-save">
                    <i class="bi bi-floppy-fill"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================= -->
<!-- DELETE CONFIRMATION MODAL -->
<!-- ========================= -->
<div id="deleteLotModal" class="modal-backdrop">
    <div class="modal-dialog-box modal-dialog-box--sm">
        <div class="modal-head modal-head--danger">
            <h3><i class="bi bi-trash3-fill text-danger"></i> Confirm Lot Deletion</h3>
            <button type="button" class="modal-close-btn" onclick="closeDeleteModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form action="manage_lots.php?id=<?= $procurement_id ?>" method="POST">
            <div class="modal-body-content modal-body-content--center">
                <input type="hidden" id="delete_lot_id" name="lot_id">

                <div class="delete-warn-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <h4 class="delete-modal-title">
                    Delete Lot #<span id="delete_lot_num_txt"></span>?
                </h4>
                <p class="delete-modal-desc">
                    Are you sure you want to remove <strong id="delete_lot_title_txt" class="clr-dark"></strong>? Any line items or bidder allocations associated with this lot will be affected.
                </p>
            </div>

            <div class="modal-foot modal-foot--center">
                <button type="button" onclick="closeDeleteModal()" class="btn-modal-cancel">
                    Cancel
                </button>
                <button type="submit" name="delete_lot" class="btn-lot-action delete btn-lot-action--modal">
                    <i class="bi bi-trash3-fill"></i> Yes, Delete Lot
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Session Alert Toasts -->
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
    // ── Helper: Format Currency ──
    function formatPHP(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) return '₱ 0.00';
        return '₱ ' + num.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateAddAbcPreview(val) {
        const preview = document.getElementById('addAbcFormattedPreview');
        if (preview) {
            preview.querySelector('span').textContent = 'Formatted: ' + formatPHP(val);
        }
    }

    function updateEditAbcPreview(val) {
        const preview = document.getElementById('editAbcFormattedPreview');
        if (preview) {
            preview.querySelector('span').textContent = 'Formatted: ' + formatPHP(val);
        }
    }

    // ── Edit Modal Handlers ──
    const editModal = document.getElementById('editLotModal');

    function openEditModal(lot) {
        if (!lot) return;
        document.getElementById('edit_lot_id').value      = lot.id;
        document.getElementById('edit_lot_number').value  = lot.lot_number;
        document.getElementById('edit_title').value       = lot.lot_title;
        document.getElementById('edit_description').value = lot.description || '';
        document.getElementById('edit_abc').value         = lot.abc;
        updateEditAbcPreview(lot.abc);

        editModal.classList.add('open');
    }

    function closeEditModal() {
        editModal.classList.remove('open');
    }

    // ── Delete Modal Handlers ──
    const deleteModal = document.getElementById('deleteLotModal');

    function openDeleteModal(lotId, lotNum, lotTitle) {
        document.getElementById('delete_lot_id').value        = lotId;
        document.getElementById('delete_lot_num_txt').textContent   = lotNum;
        document.getElementById('delete_lot_title_txt').textContent = lotTitle;

        deleteModal.classList.add('open');
    }

    function closeDeleteModal() {
        deleteModal.classList.remove('open');
    }

    // Modal backdrop click dismissal
    window.addEventListener('click', e => {
        if (e.target === editModal) closeEditModal();
        if (e.target === deleteModal) closeDeleteModal();
    });

    // Escape key dismissal
    window.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeEditModal();
            closeDeleteModal();
        }
    });

    // Auto-dismiss toast
    const toast = document.getElementById('toastAlert');
    if (toast) setTimeout(() => toast.classList.add('hide'), 4000);
</script>

</body>
</html>
