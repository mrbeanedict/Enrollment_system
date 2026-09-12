<?php
// Auth guard — required in case this partial is ever requested directly.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: " . APP_URL . "/login");
    exit();
}

$current = basename($_SERVER['PHP_SELF']);

// Real (date-computed) current school year + term, for the topbar badge —
// current_real_school_year()/semester_for_date() already exist in
// shared/config/config.php (every admin page requires bootstrap.php before
// this partial), so this reuses the same authoritative source
// registrar/scheduler pages rely on instead of hand-rolling a second
// notion of "the current year".
$topbar_school_year = current_real_school_year();
$topbar_term         = semester_for_date($topbar_school_year, date('Y-m-d'));

function navLink($href, $label, $icon_svg, $current_page, $match) {
    $active = ($current_page === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="nav-link' . $active . '">' . $icon_svg . $label . '</a>';
}
?>

<!-- SweetAlert2 -->
<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-logout-url="<?= APP_URL ?>/logout"></script>

<!-- Sidebar overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<aside class="sidebar" id="adminSidebar">

  <div class="sidebar-brand">
    <div class="sidebar-logo-crop">
      <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo">
    </div>
    <div class="sidebar-brand-text">
      <span class="sidebar-brand-name">Greenfield Senior High School</span>
      <span class="sidebar-brand-role">Admin Panel</span>
    </div>
  </div>

  <nav class="sidebar-nav">

    <span class="nav-section-label">Overview</span>

    <?php navLink('dashboard', 'Dashboard',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
      $current, 'dashboard.php'); ?>

    <span class="nav-section-label">Enrollment</span>

    <?php navLink('enrollments', 'Enrollments',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
      $current, 'enrollments.php'); ?>

    <span class="nav-section-label">People</span>

    <?php navLink('students', 'Students',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
      $current, 'students.php'); ?>

    <?php navLink('staff_manage', 'Staff',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>',
      $current, 'staff_manage.php'); ?>

    <?php navLink('teacher_accounts', 'Teacher Accounts',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-1.487.44-2.887 1.34-4.078L12 14zm0 0v7"/></svg>',
      $current, 'teacher_accounts.php'); ?>

    <span class="nav-section-label">Settings</span>

    <?php navLink('fee_settings', 'Fee Settings',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 2v8m0 0v2m0-2c-1.11 0-2.08-.402-2.599-1M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>',
      $current, 'fee_settings.php'); ?>

    <?php navLink('school_year_settings', 'School Year Settings',
      '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
      $current, 'school_year_settings.php'); ?>

  </nav>

  <div class="sidebar-photo">
    <img src="<?= APP_URL ?>/assets/images/background/ui.png" alt="">
  </div>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-avatar">
        <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="sidebar-user-text">
        <span class="sidebar-username"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></span>
        <span class="sidebar-role">System Administrator</span>
      </div>
      <a href="<?= APP_URL ?>/logout" class="btn-logout" id="logoutBtn" aria-label="Log out" title="Log out">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      </a>
    </div>
  </div>

</aside>

<script>
(function () {
  const sidebar  = document.getElementById('adminSidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const SIDEBAR_KEY = 'sidebarOpen';

  function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('open');  localStorage.setItem(SIDEBAR_KEY, '1'); }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); localStorage.setItem(SIDEBAR_KEY, '0'); }
  function toggleSidebar() { sidebar.classList.contains('open') ? closeSidebar() : openSidebar(); }

  // Restore open/closed state from the previous page (this is a multi-page
  // app — every navigation is a full reload, so state has to persist here).
  // Defaults to open (matches the sidebar's always-visible appearance
  // before this toggle existed) unless explicitly closed.
  if (localStorage.getItem(SIDEBAR_KEY) !== '0') { openSidebar(); }

  // Inject the shared topbar chrome into every page's own .topbar: a
  // hamburger (CSS-hides itself above the 900px off-canvas breakpoint), and
  // a static profile display on the right. Centralized here — same
  // reasoning as the confirm-dialog handler below — so no admin page has
  // to duplicate this markup itself.
  //
  // The search box is opt-in via topbar.dataset.search (its value becomes
  // the placeholder): only pages that actually wire up #topbarSearchInput
  // to a live filter should declare it. Injecting it unconditionally on
  // every page — as this used to do — put a focusable, brand-styled input
  // in front of the admin on pages with no filter behind it at all, so it
  // silently ate keystrokes and read as broken chrome rather than an
  // unbuilt feature.
  document.addEventListener('DOMContentLoaded', function () {
    const topbar = document.querySelector('.topbar');
    if (!topbar) return;

    const left = document.createElement('div');
    left.className = 'topbar-left';

    const toggleBtn = document.createElement('button');
    toggleBtn.className = 'btn-sidebar-toggle';
    toggleBtn.type = 'button';
    toggleBtn.setAttribute('aria-label', 'Toggle menu');
    toggleBtn.innerHTML = '<span></span><span></span><span></span>';
    toggleBtn.addEventListener('click', toggleSidebar);
    left.appendChild(toggleBtn);

    if (topbar.dataset.search) {
      const search = document.createElement('div');
      search.className = 'topbar-search';
      search.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';
      const searchInput = document.createElement('input');
      searchInput.type = 'search';
      searchInput.id = 'topbarSearchInput';
      searchInput.placeholder = topbar.dataset.search;
      searchInput.setAttribute('aria-label', topbar.dataset.search);
      search.appendChild(searchInput);
      left.appendChild(search);
    }

    topbar.prepend(left);

    // Rightmost topbar content — just the date + A.Y./term pill. Admin
    // identity already lives in the sidebar footer card below, so there's
    // no separate profile display here to duplicate it.
    const right = topbar.querySelector('.topbar-right') || topbar.appendChild(Object.assign(document.createElement('div'), { className: 'topbar-right' }));

    const termLabel = <?= json_encode('A.Y. ' . $topbar_school_year . ' • Term ' . $topbar_term, JSON_UNESCAPED_UNICODE) ?>;
    const termBadge = document.createElement('span');
    termBadge.className = 'topbar-term-badge';
    termBadge.textContent = termLabel;
    right.appendChild(termBadge);
  });

  // Logo/brand area closes the sidebar when open.
  const brand = sidebar.querySelector('.sidebar-brand');
  if (brand) {
    brand.style.cursor = 'pointer';
    brand.addEventListener('click', closeSidebar);
  }

  overlay.addEventListener('click', closeSidebar);

  // A confirmed submit or logout shows #pageLoader and disables the
  // trigger before navigating away (see doSubmit() below and the logout
  // handler). If the browser then restores this exact page from bfcache
  // on Back/Forward instead of reloading it, both of those would still be
  // in effect — a full-screen, click-blocking spinner over an otherwise
  // working page, with no reload needed to reach it and none obvious to
  // fix it. `pageshow` with `event.persisted` is exactly that restore.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    var loader = document.getElementById('pageLoader');
    if (loader) loader.classList.remove('show');
    document.querySelectorAll('button:disabled, input[type="submit"]:disabled').forEach(function (btn) {
      btn.disabled = false;
    });
  });

  // Logout — SweetAlert (falls back to native confirm() if Swal failed to load)
  document.addEventListener('DOMContentLoaded', function () {
    const logoutBtn = document.getElementById('logoutBtn');
    if (!logoutBtn) return;
    logoutBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (typeof Swal === 'undefined') {
        if (window.confirm('Log out? You will be returned to the login page.')) {
          document.getElementById('pageLoader').classList.add('show');
          window.location.href = '<?= APP_URL ?>/logout';
        }
        return;
      }
      Swal.fire({
        title: 'Log out?',
        text: 'You will be returned to the login page.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#386641',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, log out',
        cancelButtonText: 'Cancel'
      }).then(function (result) {
        if (result.isConfirmed) {
          document.getElementById('pageLoader').classList.add('show');
          window.location.href = '<?= APP_URL ?>/logout';
        }
      });
    });
  });

  // Global SweetAlert for data-confirm forms and links (falls back to native confirm())
  document.addEventListener('DOMContentLoaded', function () {
    // Forms
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      var lastSubmitter = null;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { lastSubmitter = btn; });
      });

      form.addEventListener('submit', function (e) {
        // Resubmitting via requestSubmit() re-fires this listener — let it through once.
        if (form.dataset.confirmed === '1') {
          form.dataset.confirmed = '';
          return;
        }
        e.preventDefault();
        var msg  = this.dataset.confirm || 'Are you sure?';
        var icon = this.dataset.icon   || 'warning';
        var self = this;

        function doSubmit() {
          self.dataset.confirmed = '1';
          // Disable the trigger and show the full-page loader before the
          // (possibly slow — e.g. a synchronous email send) POST goes out,
          // so a second click can't fire the same action twice.
          if (lastSubmitter) { lastSubmitter.disabled = true; }
          var loader = document.getElementById('pageLoader');
          if (loader) loader.classList.add('show');
          if (lastSubmitter && typeof self.requestSubmit === 'function') {
            self.requestSubmit(lastSubmitter);
          } else {
            HTMLFormElement.prototype.submit.call(self);
          }
        }

        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) doSubmit();
          return;
        }
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: icon,
          showCancelButton: true,
          confirmButtonColor: '#386641',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) doSubmit();
        });
      });
    });

    // Links
    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var href = this.href;
        var msg  = this.dataset.confirm || 'Are you sure?';
        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) window.location.href = href;
          return;
        }
        Swal.fire({
          title: 'Confirm',
          text: msg,
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#386641',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) window.location.href = href;
        });
      });
    });
  });
})();
</script>
