<?php
/**
 * includes/navbar.php — Public site navbar component.
 *
 * Usage (from any public page):
 *   require_once __DIR__ . '/includes/navbar.php'; // adjust path as needed
 *   render_public_navbar($active, $user_logged_in, $user_dashboard_link);
 *
 * $active: 'home' | 'procurement' | 'about' | 'login' | 'register' | ''
 */
function render_public_navbar(string $active = '', bool $user_logged_in = false, string $user_dashboard_link = 'login.php'): void
{
?>
<nav class="navbar" id="mainNavbar">
    <div class="nav-container">
        <a class="navbar-brand" href="index.php">
            <img src="<?= strpos($_SERVER['SCRIPT_NAME'], '/bidder/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/user/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/superadmin/') !== false ? '../' : '' ?>images/logo.png" alt="YesParency">
            <div>
                <div class="brand-name">YesParency</div>
                <div class="brand-sub">SLSU Procurement Portal</div>
            </div>
        </a>
        <ul class="nav-menu">
            <li><a href="index.php" class="nav-link <?= $active === 'home' ? 'active' : '' ?>">Home</a></li>
            <li><a href="bid_schedule.php" class="nav-link <?= $active === 'procurement' ? 'active' : '' ?>">Procurement</a></li>
            <li><a href="index.php#about" class="nav-link <?= $active === 'about' ? 'active' : '' ?>">About</a></li>
            <li>
                <?php if ($user_logged_in): ?>
                    <a href="<?= htmlspecialchars($user_dashboard_link) ?>" class="btn-warning-nav">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn-warning-nav">
                        <i class="bi bi-box-arrow-in-right"></i> Login
                    </a>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile hamburger toggle — shared by every page that renders this navbar.
    const navMenu = document.querySelector('.nav-menu');
    const navContainer = document.querySelector('.nav-container');
    const btn = document.createElement('button');
    btn.className = 'nav-hamburger';
    btn.innerHTML = '<i class="bi bi-list"></i>';
    btn.setAttribute('aria-label', 'Toggle menu');
    navContainer.appendChild(btn);
    btn.addEventListener('click', function() {
        navMenu.classList.toggle('open');
        btn.innerHTML = navMenu.classList.contains('open')
            ? '<i class="bi bi-x-lg"></i>'
            : '<i class="bi bi-list"></i>';
    });

    // Solid background once the page scrolls past the navbar's own height.
    const nav = document.getElementById('mainNavbar');
    window.addEventListener('scroll', () => {
        nav.classList.toggle('scrolled', window.scrollY > 40);
    });
});
</script>
<?php
}

/**
 * Shared navbar CSS — call once inside <style> or inline in <head>.
 * Only needed for pages that don't already have this CSS.
 */
function render_public_navbar_css(): void
{
?>
<style>
.navbar {
    background: rgba(6,37,27,.97);
    backdrop-filter: blur(10px);
    padding: 13px 0;
    position: fixed; top: 0; left: 0;
    width: 100%; z-index: 1000;
    border-bottom: 2px solid rgba(255,193,7,.4);
}
.nav-container {
    max-width: 1200px; margin: 0 auto; padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
}
.nav-menu { list-style: none; display: flex; align-items: center; gap: 24px; margin: 0; padding: 0; }
.nav-link {
    color: #e5ece8 !important; font-size: 13.5px; font-weight: 600;
    text-decoration: none; padding: 6px 2px; transition: color .2s;
}
.nav-link:hover, .nav-link.active { color: #ffc107 !important; }
.btn-warning-nav {
    background: linear-gradient(135deg, #ffc107 0%, #f59e0b 100%);
    border: none; color: #06251b; padding: 9px 22px; border-radius: 12px;
    font-weight: 800; font-size: 13px; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 7px;
    box-shadow: 0 4px 14px rgba(245,158,11,.28); transition: all .2s;
}
.btn-warning-nav:hover { transform: translateY(-2px); color: #06251b; }
</style>
<?php
}
