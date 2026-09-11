<?php
/**
 * includes/footer.php — Public site footer component.
 *
 * Usage (from any public page):
 *   require_once __DIR__ . '/includes/footer.php'; // adjust path as needed
 *   render_public_footer();
 *
 * Styling lives in css/components.css (.footer-index and friends) so it's
 * shared by every page that calls this, the same way includes/navbar.php's
 * navbar CSS lives there.
 */
function render_public_footer(): void
{
?>
<footer class="footer-index">
    <div class="footer-index-grid">

        <div>
            <div class="footer-brand-title">
                <i class="bi bi-transparency"></i> YesParency Portal
            </div>
            <p class="footer-brand-desc">
                Southern Luzon State University's official digital procurement transparency system. Empowering suppliers with fair competition and public accountability.
            </p>
        </div>

        <div class="footer-nav-col">
            <h5>Navigation</h5>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="bid_schedule.php">Procurement</a></li>
                <li><a href="index.php#about">About System</a></li>
                <li><a href="login.php">Bidder Portal</a></li>
            </ul>
        </div>

        <div class="footer-nav-col">
            <h5>Governance</h5>
            <ul>
                <li><a href="https://www.philgeps.gov.ph" target="_blank" rel="noopener">PhilGEPS Portal</a></li>
                <li><a href="https://gppb.gov.ph" target="_blank" rel="noopener">GPPB R.A. 9184 Guidelines</a></li>
                <li><a href="https://slsu.edu.ph" target="_blank" rel="noopener">SLSU Official Website</a></li>
            </ul>
        </div>

    </div>

    <div class="footer-bottom-bar">
        <div>&copy; <?= date('Y') ?> YesParency — Southern Luzon State University. All Rights Reserved.</div>
        <div>Compliant with R.A. 9184 Government Procurement Standards</div>
    </div>
</footer>
<?php
}
