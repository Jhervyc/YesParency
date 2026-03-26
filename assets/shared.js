/* ─── SHARED HEADER + MODAL INJECTION ─── */
/* Runs on all public pages. Skipped on dashboard and live pages. */
(function () {
  const path = location.pathname.split('/').pop();
  const isBids = path === 'bid-openings.html';
  const isAnn  = path === 'procurement-announcements.html';
  const isLive = path === 'bid-live.html';
  const isDash = path === 'bidder-dashboard.html' || path === 'admin-dashboard.html';

  // Dashboards and live page manage their own layout — bail out
  if (isLive || isDash) return;

  // ── Inject header ──
  const header = document.createElement('header');
  header.className = 'site-header';
  header.innerHTML = `
    <a href="yesparency-landing.html" class="logo-wrap">
      <div class="logo-seal">Y</div>
      <div class="logo-text">
        <div class="sys-name">YesParency</div>
        <div class="sys-sub">SLSU Procurement System</div>
      </div>
    </a>
    <nav>
      <a href="bid-openings.html" class="nav-pill ${isBids ? 'active' : ''}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        Bid Schedule
      </a>
      <a href="procurement-announcements.html" class="nav-pill ${isAnn ? 'active' : ''}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        Announcements
      </a>
      <a href="bid-live.html" class="live-badge" id="liveBadge">
        <div class="live-dot"></div>
        LIVE
      </a>
      <button class="btn-signin" id="openLogin">Login</button>
    </nav>
  `;
  document.body.prepend(header);

  // ── Inject login modal ──
  const modal = document.createElement('div');
  modal.className = 'modal-overlay';
  modal.id = 'loginModal';
  modal.innerHTML = `
    <div class="modal-box">
      <button class="modal-close" id="closeLogin" aria-label="Close">✕</button>
      <div class="modal-logo">
        <div class="modal-seal">Y</div>
        <span class="modal-logo-text">YesParency</span>
      </div>
      <div class="modal-title">Welcome back</div>
      <div class="modal-sub">Sign in to your procurement account</div>
      <form id="loginForm">
        <div class="form-group">
          <label for="login-email">Email Address</label>
          <input type="email" id="login-email" placeholder="you@slsu.edu.ph" autocomplete="email" required />
        </div>
        <div class="form-group">
          <label for="login-password">Password</label>
          <input type="password" id="login-password" placeholder="••••••••" autocomplete="current-password" required />
        </div>
        <div class="form-row">
          <label><input type="checkbox" /> Remember me</label>
          <a href="#">Forgot password?</a>
        </div>
        <button type="submit" class="btn-login">Sign In</button>
      </form>
      <div class="modal-divider">or</div>
      <div class="modal-register">Don't have an account? <a href="register.html">Register as Bidder</a></div>
    </div>
  `;
  document.body.appendChild(modal);

  // ── Modal events ──
  const openModal  = () => modal.classList.add('open');
  const closeModal = () => modal.classList.remove('open');

  document.getElementById('openLogin').addEventListener('click', e => { e.preventDefault(); openModal(); });
  document.getElementById('closeLogin').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  document.getElementById('loginForm').addEventListener('submit', e => {
    e.preventDefault();
    const email = document.getElementById('login-email').value.trim().toLowerCase();
    if (email === 'bidder@gmail.com') {
      window.location.href = 'bidder-dashboard.html';
    } else {
      alert('Invalid credentials. Use bidder@gmail.com to demo the bidder dashboard.');
    }
  });

  // ── Scroll reveal ──
  document.addEventListener('DOMContentLoaded', () => {
    const obs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.style.opacity = '1';
          e.target.style.transform = 'translateY(0)';
        }
      });
    }, { threshold: 0.08 });

    document.querySelectorAll('.schedule-card, .ann-card').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      obs.observe(el);
    });
  });
})();

/* ─── FILTER UTILITIES ─── */
function filterCards(status, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.schedule-card').forEach(card => {
    card.style.display = (status === 'all' || card.classList.contains(status)) ? '' : 'none';
  });
}

function filterAnn(tag, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.ann-card').forEach(card => {
    card.style.display = (tag === 'all' || card.dataset.tag === tag) ? '' : 'none';
  });
}

/* ─── COUNTDOWN ─── */
function initCountdown(isoDate) {
  const target = new Date(isoDate);
  function tick() {
    const diff = target - new Date();
    if (diff <= 0) {
      ['cd-days','cd-hours','cd-mins','cd-secs'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '00';
      });
      return;
    }
    const d = Math.floor(diff / 86400000);
    const h = Math.floor(diff % 86400000 / 3600000);
    const m = Math.floor(diff % 3600000 / 60000);
    const s = Math.floor(diff % 60000 / 1000);
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v).padStart(2,'0'); };
    set('cd-days', d); set('cd-hours', h); set('cd-mins', m); set('cd-secs', s);
  }
  tick();
  setInterval(tick, 1000);
}
