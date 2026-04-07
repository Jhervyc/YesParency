/* ─── DASHBOARD JS ─── */
/* Used by: bidder-dashboard.html, admin-dashboard.html */

// Nav active state
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function (e) {
    if (this.getAttribute('href') === '#') e.preventDefault();
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    this.classList.add('active');
  });
});

// Sidebar backdrop — inject once
const backdrop = document.createElement('div');
backdrop.className = 'sidebar-backdrop';
backdrop.id = 'sidebarBackdrop';
document.body.appendChild(backdrop);

function openSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (sidebar) sidebar.classList.add('open');
  backdrop.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (sidebar) sidebar.classList.remove('open');
  backdrop.classList.remove('open');
  document.body.style.overflow = '';
}

// Backdrop click closes sidebar
backdrop.addEventListener('click', closeSidebar);

// Sidebar toggle button — replace inline onclick with proper handler
document.addEventListener('DOMContentLoaded', () => {
  const toggleBtn = document.querySelector('.sidebar-toggle');
  if (toggleBtn) {
    // Remove inline onclick
    toggleBtn.removeAttribute('onclick');
    toggleBtn.addEventListener('click', () => {
      const sidebar = document.getElementById('sidebar');
      if (sidebar && sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }
});

// Close sidebar on nav item click (mobile)
document.addEventListener('click', e => {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  if (
    sidebar.classList.contains('open') &&
    e.target.closest('.nav-item') &&
    window.innerWidth <= 768
  ) {
    closeSidebar();
  }
});

// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSidebar();
});
