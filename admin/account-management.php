<?php 
include ("utils/protect-page.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bidder Accounts | YesParency</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
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
            <div class="sub">Admin Panel</div>
        </div>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-broadcast"></i><span>Bid Opening</span></a>

        <div class="nav-section-label">Procurement</div>
        <a href="procurement.php" class="nav-item"><i class="bi bi-folder2-open"></i><span>Procurements</span></a>
        <a href="bid_submissions.php" class="nav-item"><i class="bi bi-inbox"></i><span>Bid Submissions</span></a>

        <div class="nav-section-label">Management</div>
        <a href="account-management.php" class="nav-item active"><i class="bi bi-people"></i><span>Bidder Accounts</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-megaphone"></i><span>Announcements</span></a>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-journal-text"></i><span>Audit Trail</span></a>

        <div class="nav-section-label">System</div>
        <a href="dashboard.php" class="nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><i class="bi bi-person"></i></div>
            <div class="user-info">
                <div class="uname"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="urole">Administrator</div>
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
        <span class="topbar-title">Bidder Accounts</span>
    </div>
    <div class="topbar-right">
        <div class="topbar-badge"><i class="bi bi-bell"></i><span class="badge-dot"></span></div>
        <div class="topbar-avatar"><i class="bi bi-person"></i></div>
    </div>
</div>

<!-- ========================= -->
<!-- MAIN CONTENT              -->
<!-- ========================= -->
<main class="dash-main" id="dashMain">
    <div class="dash-content">

        <div class="page-header">
            <h2>Bidder Accounts</h2>
            <p>Review and manage bidder registrations and pending applications.</p>
        </div>

        <!-- Bidder cards grid -->
        <div class="bidder-grid">

        <?php
            $stmt = $conn->prepare(
                "SELECT *
                FROM users
                WHERE role = ? || (role = ? && status = ?)"
            );

            $role  = "bidder";
            $role2 = "user";
            $status = "pending";

            $stmt->bind_param("sss", $role, $role2, $status);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()):
                $userId = $row['user_id'];

                $profileStmt = $conn->prepare("
                    SELECT business_name, application_status
                    FROM bidder_profiles
                    WHERE user_id = ?
                ");
                $profileStmt->bind_param("i", $userId);
                $profileStmt->execute();
                $profileResult = $profileStmt->get_result();

                $businessName = "No Business Profile";
                $appStatus    = null;

                if ($profileResult->num_rows > 0) {
                    $profile      = $profileResult->fetch_assoc();
                    $businessName = $profile['business_name'];
                    $appStatus    = $profile['application_status'];
                }

                // Determine badge
                $statusClass = 'closed';
                $statusLabel = htmlspecialchars($row['status']);
                if ($row['status'] === 'pending')  { $statusClass = 'upcoming'; }
                if ($row['status'] === 'active')   { $statusClass = 'open'; }
                if ($row['role']   === 'bidder')   { $statusClass = 'open'; $statusLabel = 'Approved Bidder'; }
        ?>

            <div class="bidder-card-item">
                <div class="bidder-card-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>
                <div class="bidder-card-info">
                    <div class="bidder-card-name">
                        <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                    </div>
                    <div class="bidder-card-meta">
                        <span><i class="bi bi-building"></i> <?= htmlspecialchars($businessName) ?></span>
                        <span><i class="bi bi-person-badge"></i> <?= htmlspecialchars($row['role']) ?></span>
                    </div>
                </div>
                <div class="bidder-card-right">
                    <span class="proc-status-pill <?= $statusClass ?>">
                        <?= $statusLabel ?>
                    </span>
                    <button class="proc-action-btn review" onclick="loadBidder(<?= $userId ?>)">
                        <i class="bi bi-eye"></i> View
                    </button>
                </div>
            </div>

        <?php endwhile; ?>

        </div><!-- /.bidder-grid -->

    </div>
</main>

<!-- ========================= -->
<!-- SIDE DRAWER               -->
<!-- ========================= -->
<div id="drawerOverlay" onclick="closeModal()"></div>

<div id="sideModal">
    <div class="drawer-header">
        <h4><i class="bi bi-person-lines-fill"></i> Bidder Details</h4>
        <button id="closeBtn" onclick="closeModal()" aria-label="Close drawer">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div id="modalContent" class="drawer-body">
        <div class="drawer-placeholder">
            <i class="bi bi-person-circle"></i>
            <p>Select a bidder to view their details.</p>
        </div>
    </div>
</div>

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

    // Drawer logic
    function loadBidder(userId) {
        const content = document.getElementById('modalContent');
        content.innerHTML = '<div class="drawer-loading"><i class="bi bi-arrow-repeat spin"></i> Loading...</div>';
        document.getElementById('sideModal').classList.add('active');
        document.getElementById('drawerOverlay').classList.add('active');

        fetch("get_bidder.php?id=" + userId)
            .then(r => r.text())
            .then(data => {
                content.innerHTML = '<div class="drawer-fetched-content">' + data + '</div>';
            })
            .catch(() => {
                content.innerHTML = '<div class="drawer-placeholder"><i class="bi bi-exclamation-circle"></i><p>Failed to load bidder details.</p></div>';
            });
    }

    function closeModal() {
        document.getElementById('sideModal').classList.remove('active');
        document.getElementById('drawerOverlay').classList.remove('active');
    }
</script>

</body>
</html>
