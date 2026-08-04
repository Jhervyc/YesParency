<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YesParency | Procurement Transparency System</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons only -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Global stylesheet -->
    <link rel="stylesheet" href="style.css">
</head>
<body class="index-page">

<!-- ========================= -->
<!-- NAVBAR                    -->
<!-- ========================= -->
<nav class="navbar">
    <div class="nav-container">
        <a class="navbar-brand" href="index.php">
            <img src="images/procure.jpg" alt="YesParency Logo">
            <div>
                <div class="brand-name">YesParency</div>
                <div class="brand-sub">Procurement System</div>
            </div>
        </a>
        <ul class="nav-menu">
            <li><a href="#" class="nav-link">Home</a></li>
            <li><a href="#" class="nav-link">Bid Calendar</a></li>
            <li><a href="#" class="nav-link">Announcements</a></li>
            <li><a href="#" class="nav-link">Benefits</a></li>
            <li><a href="#" class="nav-link">About</a></li>
            <li style="margin-left: 16px;">
                <a href="login.php" class="btn-warning-nav">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ========================= -->
<!-- HERO                      -->
<!-- ========================= -->
<section class="hero">
    <div class="overlay"></div>
    <div class="hero-container">

        <!-- Left: text -->
        <div class="hero-content">
            <span class="hero-badge">
                SOUTHERN LUZON STATE UNIVERSITY — PROCUREMENT OFFICE
            </span>
            <h1>
                <span class="text-warning">FAIR.</span><br>
                <span class="text-warning">SECURE.</span><br>
                <span class="text-warning">TRANSPARENT.</span><br>
                PROCUREMENT FOR SLSU
            </h1>
            <p>
                YesParency supports Southern Luzon State University's
                commitment to integrity, accountability, and fair competition
                through a modern web-based procurement system.
            </p>
            <div class="hero-btns">
                <a href="register.php" class="btn-hero-primary">
                    <i class="bi bi-person-plus-fill"></i>
                    Register as Bidder
                </a>
                <a href="login.php" class="btn-hero-outline">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Log In
                </a>
            </div>
        </div>

        <!-- Right: info card -->
        <div class="hero-card">
            <div class="hero-card-title">
                <i class="bi bi-bar-chart-line"></i> Procurement at a Glance
            </div>

            <div class="hero-stat-row">
                <div class="hero-stat">
                    <div class="num">12</div>
                    <div class="lbl">Active Bids</div>
                </div>
                <div class="hero-stat">
                    <div class="num">67</div>
                    <div class="lbl">Suppliers</div>
                </div>
                <div class="hero-stat">
                    <div class="num">72</div>
                    <div class="lbl">Awarded</div>
                </div>
            </div>

            <hr class="hero-divider">

            <div class="hero-card-title">
                <i class="bi bi-broadcast"></i> Live & Upcoming
            </div>

            <div class="hero-live-item">
                <div class="live-dot"></div>
                <div class="hero-live-info">
                    <p>Construction of SLSU Multi-Purpose Hall</p>
                    <span>LIVE NOW · BAC Room · 8:00 AM</span>
                </div>
            </div>

            <div class="hero-live-item">
                <div class="upcoming-dot"></div>
                <div class="hero-live-info">
                    <p>Procurement of ICT Equipment</p>
                    <span>July 30, 2026 · 1:30 PM</span>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- STATS                     -->
<!-- ========================= -->
<section class="stats-section">
    <div class="stats-grid">

        <div class="stat-card">
            <i class="bi bi-folder2-open stat-icon"></i>
            <h2>12</h2>
            <h6>Active Procurements</h6>
            <p>Currently accepting bids</p>
        </div>

        <div class="stat-card">
            <i class="bi bi-people stat-icon"></i>
            <h2>67</h2>
            <h6>Registered Suppliers</h6>
            <p>Eligible bidders</p>
        </div>

        <div class="stat-card">
            <i class="bi bi-award stat-icon"></i>
            <h2>72</h2>
            <h6>Projects Awarded</h6>
            <p>For this fiscal year</p>
        </div>

        <div class="stat-card">
            <i class="bi bi-camera-video stat-icon"></i>
            <h2>21</h2>
            <h6>Live Bid Sessions</h6>
            <p>Conducted transparently</p>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- HOW IT WORKS              -->
<!-- ========================= -->
<section class="process-section">
    <div class="section-center">
        <span class="process-subtitle">How YesParency Works</span>
        <h2 class="process-title">A Transparent Procurement Process</h2>
    </div>

    <div class="process-wrapper">

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-file-earmark-text"></i>
                <span class="step-number">1</span>
            </div>
            <h5>Opportunity Posted</h5>
            <p>The SLSU Procurement office posts opportunities for goods, services, and infrastructure projects.</p>
        </div>

        <div class="process-line"></div>

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-people"></i>
                <span class="step-number">2</span>
            </div>
            <h5>Suppliers Participate</h5>
            <p>Interested suppliers register and submit their bids before the deadline.</p>
        </div>

        <div class="process-line"></div>

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-broadcast"></i>
                <span class="step-number">3</span>
            </div>
            <h5>Live Bid Opening</h5>
            <p>Bids are opened in public through a live and transparent session.</p>
        </div>

        <div class="process-line"></div>

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-clipboard-check"></i>
                <span class="step-number">4</span>
            </div>
            <h5>Evaluation</h5>
            <p>The BAC evaluates bids based on procurement rules.</p>
        </div>

        <div class="process-line"></div>

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-award"></i>
                <span class="step-number">5</span>
            </div>
            <h5>Awarding</h5>
            <p>The winning bidder is officially awarded the project.</p>
        </div>

        <div class="process-line"></div>

        <div class="process-item">
            <div class="process-icon">
                <i class="bi bi-window-stack"></i>
                <span class="step-number">6</span>
            </div>
            <h5>Public Disclosure</h5>
            <p>Results and awards are published for public transparency.</p>
        </div>

    </div>
</section>

<!-- ========================= -->
<!-- PROCUREMENT CARDS         -->
<!-- ========================= -->
<section class="procurement-section">
    <div class="index-container">

        <span class="section-label">Procurement Details</span>
        <h2 class="section-title">Active & Upcoming Bids Opening</h2>
        <p class="section-description">
            All procurement activities are conducted in accordance with
            Republic Act No. 9184. Click on any item to view complete
            procurement details.
        </p>

        <div class="proc-grid">

            <!-- CARD 1 -->
            <div class="proc-card live">
                <span class="status-badge live-badge">● LIVE NOW</span>
                <h5>Supply and Delivery of Science Laboratory Equipment for College of Arts and Sciences</h5>
                <ul>
                    <li><i class="bi bi-clock"></i> Opening Today – March 26, 2026 10:00 AM</li>
                    <li><i class="bi bi-geo-alt"></i> BAC Conference Room</li>
                    <li><i class="bi bi-cash"></i> ABC: ₱2,450,000.00</li>
                </ul>
                <hr>
                <small>Ref No: <strong>SLSU-BAC-2026-003</strong></small>
            </div>

            <!-- CARD 2 -->
            <div class="proc-card open">
                <span class="status-badge open-badge">● OPEN FOR SUBMISSION</span>
                <h5>Construction of New Multi-Purpose Hall at SLSU Main Campus</h5>
                <ul>
                    <li><i class="bi bi-calendar-event"></i> Bid Opening: April 3, 2026</li>
                    <li><i class="bi bi-clock-history"></i> Submission Deadline: April 2, 2026</li>
                    <li><i class="bi bi-cash"></i> ABC: ₱18,750,000.00</li>
                </ul>
                <hr>
                <small>Ref No: <strong>SLSU-BAC-2026-007</strong></small>
            </div>

            <!-- CARD 3 -->
            <div class="proc-card upcoming">
                <span class="status-badge upcoming-badge">● UPCOMING</span>
                <h5>Supply of Information Technology Equipment and Peripherals</h5>
                <ul>
                    <li><i class="bi bi-calendar"></i> Opening: April 10, 2026</li>
                    <li><i class="bi bi-clock-history"></i> Submission: April 9, 2026</li>
                    <li><i class="bi bi-cash"></i> ABC: ₱3,200,000.00</li>
                </ul>
                <hr>
                <small>Ref No: <strong>SLSU-BAC-2026-011</strong></small>
            </div>

            <!-- CARD 4 -->
            <div class="proc-card closed">
                <span class="status-badge closed-badge">✕ CLOSED</span>
                <h5>Procurement of Janitorial and Maintenance Services FY 2026</h5>
                <ul>
                    <li><i class="bi bi-calendar-check"></i> Opened: March 15, 2026</li>
                    <li><i class="bi bi-file-earmark-text"></i> Notice of Award Released</li>
                    <li><i class="bi bi-cash"></i> ABC: ₱1,800,000.00</li>
                </ul>
                <hr>
                <small>Ref No: <strong>SLSU-BAC-2026-001</strong></small>
            </div>

        </div>

        <a href="#" class="btn-view-all">View all Bid Openings</a>

    </div>
</section>

<!-- ========================= -->
<!-- BIDS & AWARDS             -->
<!-- ========================= -->
<section class="awards-section">
    <div class="index-container">
        <div class="awards-grid">

            <!-- LEFT: Table -->
            <div class="award-table-card">
                <div class="awards-table-header">
                    <h3><i class="bi bi-trophy"></i> Recently Awarded Projects</h3>
                    <a href="#" class="view-more">View More <i class="bi bi-arrow-right"></i></a>
                </div>
                <div style="overflow-x: auto;">
                    <table class="awards-table">
                        <thead>
                            <tr>
                                <th>Project Title</th>
                                <th>Category</th>
                                <th>Awarded To</th>
                                <th>Date Awarded</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Supply and Delivery of Science Laboratory Equipment</td>
                                <td><span class="category goods">Goods</span></td>
                                <td>LabTech Supplies Co.</td>
                                <td>May 12, 2026</td>
                                <td>₱1,800,000.00</td>
                            </tr>
                            <tr>
                                <td>Supply of IT Equipment and Peripherals</td>
                                <td><span class="category goods">Goods</span></td>
                                <td>LabTech Supplies Co.</td>
                                <td>January 12, 2026</td>
                                <td>₱1,500,000.00</td>
                            </tr>
                            <tr>
                                <td>Procurement of Janitorial Services</td>
                                <td><span class="category service">Services</span></td>
                                <td>Cleanway Tech</td>
                                <td>February 25, 2026</td>
                                <td>₱800,100.00</td>
                            </tr>
                            <tr>
                                <td>Repair of SLSU Gymnasium Roofing</td>
                                <td><span class="category infra">Infrastructure</span></td>
                                <td>BuildWay Co.</td>
                                <td>February 25, 2026</td>
                                <td>₱800,100.00</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RIGHT: Accordion -->
            <div class="bids-card">
                <h3>Bids and Awards</h3>
                <div class="accordion" id="awardAccordion">

                    <div class="accordion-item">
                        <button class="accordion-btn" onclick="toggleAccordion(this)">
                            Notice to Proceed <i class="bi bi-chevron-down acc-icon"></i>
                        </button>
                        <div class="accordion-body">
                            Download recently issued Notice to Proceed documents.
                        </div>
                    </div>

                    <div class="accordion-item">
                        <button class="accordion-btn" onclick="toggleAccordion(this)">
                            Notice of Award <i class="bi bi-chevron-down acc-icon"></i>
                        </button>
                        <div class="accordion-body">
                            View official Notice of Award files.
                        </div>
                    </div>

                    <div class="accordion-item">
                        <button class="accordion-btn" onclick="toggleAccordion(this)">
                            Request for Quotation <i class="bi bi-chevron-down acc-icon"></i>
                        </button>
                        <div class="accordion-body">
                            RFQ documents available for download.
                        </div>
                    </div>

                    <div class="accordion-item">
                        <button class="accordion-btn" onclick="toggleAccordion(this)">
                            Invitation to Bid <i class="bi bi-chevron-down acc-icon"></i>
                        </button>
                        <div class="accordion-body">
                            Current Invitations to Bid.
                        </div>
                    </div>

                    <div class="accordion-item">
                        <button class="accordion-btn" onclick="toggleAccordion(this)">
                            PhilGEPS <i class="bi bi-chevron-down acc-icon"></i>
                        </button>
                        <div class="accordion-body">
                            Procurement notices linked with PhilGEPS.
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>

<!-- ========================= -->
<!-- FOOTER                    -->
<!-- ========================= -->
<footer class="footer-index">
    <div class="footer-index-grid">

        <div>
            <div class="footer-brand">
                <img src="images/procure.jpg" alt="SLSU Logo" class="logo" style="width:60px;height:60px;border-radius:50%;object-fit:cover;margin-right:16px;border:2px solid rgba(255,193,7,.4);">
                <div>
                    <h3>YesParency</h3>
                    <p>Southern Luzon State University<br>Procurement Office</p>
                </div>
            </div>
        </div>

        <div>
            <h5>Quick Links</h5>
            <ul>
                <li><a href="#">Home</a></li>
                <li><a href="#">Bid Opportunities</a></li>
                <li><a href="#">Bid Results</a></li>
                <li><a href="#">Announcements</a></li>
                <li><a href="#">Contact</a></li>
            </ul>
        </div>

        <div>
            <h5>Suppliers</h5>
            <ul>
                <li><a href="#">Register</a></li>
                <li><a href="#">Supplier Guide</a></li>
                <li><a href="#">Requirements</a></li>
                <li><a href="#">Frequently Asked Questions</a></li>
            </ul>
        </div>

        <div>
            <h5>Contact Us</h5>
            <ul class="contact-list">
                <li><i class="bi bi-geo-alt"></i> Southern Luzon State University, Lucban, Quezon</li>
                <li><i class="bi bi-telephone"></i> 0000-000</li>
                <li><i class="bi bi-envelope"></i> procurement@slsu.edu.ph</li>
            </ul>
        </div>

        <div>
            <h5>Connect With Us</h5>
            <div class="social-links">
                <a href="https://www.facebook.com/profile.php?id=61573070853148" aria-label="Facebook">
                    <i class="bi bi-facebook"></i>
                </a>
                <a href="#" aria-label="Website">
                    <i class="bi bi-globe"></i>
                </a>
                <a href="mailto:slsuprocurement@slsu.edu.ph" aria-label="Email">
                    <i class="bi bi-envelope-fill"></i>
                </a>
            </div>
        </div>

    </div>

    <div style="max-width:1200px;margin:0 auto;padding:0 20px;">
        <hr>
        <div class="footer-bottom">
            <p>© 2026 YesParency. All Rights Reserved.</p>
            <p>Developed for the Southern Luzon State University Procurement Office.</p>
        </div>
    </div>
</footer>

<script>
    // Accordion toggle
    function toggleAccordion(btn) {
        const body = btn.nextElementSibling;
        const isOpen = btn.classList.contains('open');

        // Close all
        document.querySelectorAll('.accordion-btn').forEach(b => {
            b.classList.remove('open');
            b.nextElementSibling.classList.remove('open');
        });

        // Open clicked if it was closed
        if (!isOpen) {
            btn.classList.add('open');
            body.classList.add('open');
        }
    }

    // Navbar scroll effect
    window.addEventListener('scroll', () => {
        const nav = document.querySelector('.navbar');
        nav.style.boxShadow = window.scrollY > 50
            ? '0 5px 15px rgba(0,0,0,.25)'
            : 'none';
    });
</script>

</body>
</html>
