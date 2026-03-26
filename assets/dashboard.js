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

// Mobile sidebar toggle — close on outside click
document.addEventListener('click', e => {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  if (
    sidebar.classList.contains('open') &&
    !sidebar.contains(e.target) &&
    !e.target.closest('.sidebar-toggle')
  ) {
    sidebar.classList.remove('open');
  }
});
