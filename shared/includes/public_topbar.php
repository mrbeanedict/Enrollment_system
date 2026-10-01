<?php
// Shared public-site top bar (index, faq, requirement_checklist).
// Self-contained like footer.php: literal brand colors and selectors scoped
// under .topbar, specific enough to beat styles.css's older global
// nav/.brand/.nav-links/.nav-right rules on the pages that load it.
//
// Optional before including:
//   $topbar_active   = 'requirements' | 'faq'  — highlights that page's link
//   $topbar_on_index = true                    — homepage links become plain
//                                                in-page anchors (no reload)
$tb_pub    = APP_URL . '/roles/student/public';
$tb_active = $topbar_active ?? '';
$tb_home   = !empty($topbar_on_index) ? '' : $tb_pub . '/index';
$tb_links  = [
    ''             => ['Home',          $tb_home === '' ? '#top' : $tb_home],
    'about'        => ['About',         $tb_home . '#why'],
    'strands'      => ['Strands',       $tb_home . '#strands'],
    'journey'      => ['How to Enroll', $tb_home . '#journey'],
    'requirements' => ['Requirements',  $tb_pub . '/requirement_checklist'],
    'faq'          => ['FAQs',          $tb_pub . '/faq'],
];

// Search modal index — a fixed list of what the public site covers. `k` is
// extra matching text that isn't shown. Keep in step with index/faq/
// requirement_checklist when their content changes.
$tb_req = $tb_pub . '/requirement_checklist';
$tb_faq = $tb_pub . '/faq';
$tb_search = [
    ['Pages', [
        ['Home',                     'Greenfield Senior High School', $tb_home === '' ? '#top' : $tb_home, 'start main landing'],
        ['Why Greenfield',           'About the school',              $tb_home . '#why',     'about'],
        ['Academic Strands',         'STEM, ABM, HUMSS, GAS, TVL-ICT', $tb_home . '#strands', 'tracks courses programs'],
        ['How to Enroll',            'Step-by-step enrollment',       $tb_home . '#journey', 'process steps apply'],
        ['Check Application Status', 'Track with your LRN or control number', $tb_home . '#enroll', 'track status lrn control number'],
        ['Requirements Checklist',   'Documents to prepare',          $tb_req,               'documents requirements'],
        ['FAQs',                     'Frequently asked questions',    $tb_faq,               'questions help'],
        ['Apply Now',                'Online application form',       $tb_pub . '/admission', 'enroll admission application register'],
        ['Student Portal',           'Sign in to your account',       APP_URL . '/roles/student/portal/student_login', 'login sign in account'],
    ]],
    ['Requirements', [
        ['Report Card (Form 138)',                'All applicants',              $tb_req, 'grades jhs'],
        ['PSA-issued Birth Certificate',          'All applicants',              $tb_req, 'psa nso'],
        ['Official Transcript of Records (OTR)',  'All applicants',              $tb_req, 'otr tor'],
        ['Certificate of Good Moral Character',   'All applicants',              $tb_req, 'good moral'],
        ['2x2 ID Picture',                        'All applicants',              $tb_req, 'photo picture id'],
        ['Voucher Eligibility Certificate (VEC)', 'Public JHS graduates only',   $tb_req, 'vec voucher public'],
    ]],
    ['FAQs', [
        ['Who can enroll in Senior High School (Grades 11–12)?', 'FAQ', $tb_faq, 'eligible jhs graduate'],
        ['What documents do I need to prepare?',                 'FAQ', $tb_faq, 'requirements'],
        ['How do I apply online?',                               'FAQ', $tb_faq, 'application account'],
        ['What strands are offered?',                            'FAQ', $tb_faq, 'stem abm humss gas tvl ict'],
        ['Do I need a Voucher Eligibility Certificate (VEC)?',   'FAQ', $tb_faq, 'vec voucher'],
        ['How do I track my application status?',               'FAQ', $tb_faq, 'status lrn control number'],
        ['Is there a deadline for enrollment?',                  'FAQ', $tb_faq, 'deadline schedule period'],
    ]],
];
?>
<style>
  :root{--topbar-h:76px;}
  nav.topbar{
    position:sticky;top:0;left:auto;right:auto;z-index:60;
    height:var(--topbar-h);
    background:#FFFFFF;
    border-bottom:1px solid #E1EBE5;
    box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none;
    font-family:'Inter',system-ui,sans-serif;
  }
  .topbar .topbar-inner{
    display:flex;align-items:center;justify-content:space-between;gap:20px;
    height:100%;max-width:1180px;margin:0 auto;padding:0 28px;
  }
  .topbar .brand{display:flex;align-items:center;text-decoration:none;flex-shrink:0;}
  .topbar .brand img{display:block;height:46px;width:auto;}
  .topbar .nav-links{display:none;gap:2px;align-items:center;}
  @media(min-width:960px){.topbar .nav-links{display:flex;}}
  .topbar .nav-links a{
    font-weight:500;font-size:0.9rem;text-decoration:none;color:#5C6E6E;
    padding:9px 14px;border-radius:999px;white-space:nowrap;
    transition:background .15s ease,color .15s ease;
  }
  .topbar .nav-links a:hover,
  .topbar .nav-links a:focus-visible,
  .topbar .nav-links a.active{background:#E3EEE5;color:#24422A;}
  .topbar .nav-right{display:flex;align-items:center;gap:8px;}
  .topbar .nav-portal{
    display:inline-flex;align-items:center;
    color:#386641;border:1px solid #E1EBE5;
    font-size:0.86rem;font-weight:600;padding:9px 16px;border-radius:999px;text-decoration:none;
    transition:border-color .15s ease;
  }
  .topbar .nav-portal:hover{border-color:#386641;}
  .topbar .nav-cta{
    display:none;background:#386641;color:#FFFFFF;
    font-size:0.86rem;font-weight:600;padding:10px 18px;border-radius:999px;text-decoration:none;
  }
  @media(min-width:600px){.topbar .nav-cta{display:inline-flex;}}
  .topbar .nav-cta:hover{background:#2F5636;}
  .topbar .nav-toggle{
    display:flex;align-items:center;justify-content:center;
    width:42px;height:42px;border-radius:50%;border:1px solid #E1EBE5;
    background:#FFFFFF;color:#1C2628;cursor:pointer;
  }
  @media(min-width:960px){.topbar .nav-toggle{display:none;}}
  .topbar .mobile-menu{display:none;background:#FFFFFF;border-bottom:1px solid #E1EBE5;box-shadow:0 10px 28px rgba(28,38,40,0.06);}
  .topbar .mobile-menu.open{display:block;}
  @media(min-width:960px){.topbar .mobile-menu,.topbar .mobile-menu.open{display:none;}}
  .topbar .mobile-menu a{display:block;padding:14px 28px;text-decoration:none;color:#1C2628;font-weight:500;border-top:1px solid #ECF3EE;}
  .topbar .mobile-menu a:hover,
  .topbar .mobile-menu a.active{background:#E3EEE5;}
  @media(max-width:520px){.topbar .brand img{height:38px;}.topbar .nav-portal{display:none;}}
  .topbar .nav-search{
    display:flex;align-items:center;justify-content:center;
    width:42px;height:42px;border-radius:50%;border:1px solid #E1EBE5;
    background:#FFFFFF;color:#386641;cursor:pointer;transition:border-color .15s ease;
  }
  .topbar .nav-search:hover{border-color:#386641;}

  /* search modal */
  .tb-search{position:fixed;inset:0;z-index:200;display:flex;align-items:flex-start;justify-content:center;
    padding:12vh 16px 16px;background:rgba(28,38,40,0.4);font-family:'Inter',system-ui,sans-serif;}
  .tb-search[hidden]{display:none;}
  .tb-search-box{width:100%;max-width:560px;max-height:70vh;display:flex;flex-direction:column;
    background:#FFFFFF;border:1px solid #E1EBE5;border-radius:16px;box-shadow:0 20px 50px rgba(28,38,40,0.2);overflow:hidden;}
  .tb-search-field{display:flex;align-items:center;gap:10px;padding:0 16px;border-bottom:1px solid #ECF3EE;color:#5C6E6E;}
  .tb-search-field input{flex:1;min-width:0;height:54px;border:0;outline:0;background:transparent;font:inherit;font-size:1rem;color:#1C2628;}
  .tb-search-field input::-webkit-search-cancel-button{display:none;}
  /* beat styles.css's global input:focus outline/border */
  .tb-search .tb-search-field input,
  .tb-search .tb-search-field input:focus{border:0;border-radius:0;box-shadow:none;outline:none;padding:0;background:transparent;}
  .tb-search-close{font-size:0.72rem;font-weight:600;color:#5C6E6E;border:1px solid #E1EBE5;border-radius:6px;padding:3px 7px;background:#FFFFFF;cursor:pointer;}
  .tb-search-status{padding:14px 18px;font-size:0.86rem;color:#5C6E6E;}
  .tb-search-status:empty{display:none;}
  .tb-search-list{list-style:none;margin:0;padding:6px;overflow-y:auto;}
  .tb-search-list:empty{display:none;}
  .tb-search-group{padding:10px 12px 4px;font-size:0.7rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#5C6E6E;}
  .tb-search-opt{padding:9px 12px;border-radius:10px;cursor:pointer;}
  .tb-search-opt-title{display:block;font-size:0.92rem;font-weight:500;color:#1C2628;}
  .tb-search-opt-sub{display:block;font-size:0.78rem;color:#5C6E6E;margin-top:2px;}
  .tb-search-opt.active,.tb-search-opt:hover{background:#E3EEE5;}
  .tb-search-opt.active .tb-search-opt-title{color:#24422A;}
  /* anchored sections land below the sticky bar */
  section[id]{scroll-margin-top:var(--topbar-h);}
</style>

<nav class="topbar" aria-label="Main">
  <div class="topbar-inner">
    <a href="<?= $tb_home === '' ? '#top' : $tb_home ?>" class="brand">
      <img src="<?= APP_URL ?>/assets/images/logo_sidebar_v3.png" alt="Greenfield Senior High School">
    </a>
    <div class="nav-links">
      <?php foreach ($tb_links as $key => [$label, $href]): ?>
        <a href="<?= $href ?>"<?= ($key !== '' && $key === $tb_active) ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="nav-right">
      <button type="button" class="nav-search" id="tbSearchBtn" aria-label="Search" title="Search (Ctrl+K)" aria-haspopup="dialog">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      </button>
      <a href="<?= APP_URL ?>/roles/student/portal/student_login" class="nav-portal">Student Portal</a>
      <a href="<?= $tb_pub ?>/admission" class="nav-cta">Enroll Now</a>
      <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
  <div class="mobile-menu" id="mobileMenu">
    <?php foreach ($tb_links as $key => [$label, $href]): ?>
      <a href="<?= $href ?>"<?= ($key !== '' && $key === $tb_active) ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="<?= APP_URL ?>/roles/student/portal/student_login">Student Portal</a>
    <a href="<?= $tb_home ?>#enroll">Check Status</a>
  </div>
</nav>

<div class="tb-search" id="tbSearch" hidden>
  <div class="tb-search-box" role="dialog" aria-modal="true" aria-label="Search the site">
    <div class="tb-search-field">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="search" id="tbSearchInput" placeholder="Search pages, requirements, FAQs" autocomplete="off" maxlength="60"
             role="combobox" aria-expanded="true" aria-controls="tbSearchList" aria-autocomplete="list">
      <button type="button" class="tb-search-close" id="tbSearchClose" aria-label="Close search">Esc</button>
    </div>
    <div class="tb-search-status" id="tbSearchStatus" role="status" aria-live="polite"></div>
    <ul class="tb-search-list" id="tbSearchList" role="listbox" aria-label="Search results"></ul>
  </div>
</div>

<script>
  // Mobile menu toggle for the top bar.
  (function () {
    var toggle = document.getElementById('navToggle');
    var menu = document.getElementById('mobileMenu');
    if (!toggle || !menu) return;
    function setOpen(open) {
      menu.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    toggle.addEventListener('click', function () { setOpen(!menu.classList.contains('open')); });
    menu.addEventListener('click', function (e) { if (e.target.tagName === 'A') setOpen(false); });
  })();

  // Search modal — matches the fixed $tb_search index client-side.
  (function () {
    var btn = document.getElementById('tbSearchBtn');
    var modal = document.getElementById('tbSearch');
    var input = document.getElementById('tbSearchInput');
    var list = document.getElementById('tbSearchList');
    var status = document.getElementById('tbSearchStatus');
    if (!btn || !modal) return;
    var index = <?= json_encode(array_map(fn($g) => [
        'label' => $g[0],
        'items' => array_map(fn($i) => ['title' => $i[0], 'sub' => $i[1], 'url' => $i[2], 'k' => $i[3]], $g[1]),
    ], $tb_search), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var active = -1, lastFocus = null;

    function esc(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function opts() { return list.querySelectorAll('.tb-search-opt'); }
    function setActive(i) {
      var o = opts();
      o.forEach(function (el, n) {
        el.classList.toggle('active', n === i);
        el.setAttribute('aria-selected', n === i ? 'true' : 'false');
      });
      active = i;
      if (o[i]) { input.setAttribute('aria-activedescendant', o[i].id); o[i].scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    function search() {
      var q = input.value.trim().toLowerCase();
      // Every word has to appear somewhere in the item's text.
      var words = q.split(/\s+/).filter(Boolean);
      var html = '', n = 0;
      index.forEach(function (g) {
        var hits = g.items.filter(function (it) {
          var hay = (it.title + ' ' + it.sub + ' ' + it.k).toLowerCase();
          return words.every(function (w) { return hay.indexOf(w) !== -1; });
        });
        // Empty query: just the page list, not every requirement and FAQ.
        if (!q && g.label !== 'Pages') hits = [];
        if (!hits.length) return;
        html += '<li class="tb-search-group" role="presentation">' + esc(g.label) + '</li>';
        hits.forEach(function (it) {
          html += '<li role="option" id="tbSearchOpt' + n + '" class="tb-search-opt" data-url="' + esc(it.url) + '" aria-selected="false">'
                + '<span class="tb-search-opt-title">' + esc(it.title) + '</span>'
                + '<span class="tb-search-opt-sub">' + esc(it.sub) + '</span></li>';
          n++;
        });
      });
      list.innerHTML = html;
      status.textContent = n ? '' : 'No matches for “' + input.value.trim() + '”.';
      setActive(n ? 0 : -1);
    }
    function go(el) {
      if (!el) return;
      var url = el.dataset.url;
      close(false);
      window.location.href = url;
    }
    function open() {
      if (!modal.hidden) return;
      lastFocus = document.activeElement;
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      input.value = '';
      search();
      input.focus();
    }
    function close(restoreFocus) {
      if (modal.hidden) return;
      modal.hidden = true;
      document.body.style.overflow = '';
      if (restoreFocus !== false && lastFocus && lastFocus.focus) lastFocus.focus();
    }

    btn.addEventListener('click', open);
    document.getElementById('tbSearchClose').addEventListener('click', close);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) close(); });
    input.addEventListener('input', search);
    input.addEventListener('keydown', function (e) {
      var n = opts().length;
      if (e.key === 'ArrowDown')    { e.preventDefault(); if (n) setActive((active + 1) % n); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (n) setActive((active - 1 + n) % n); }
      else if (e.key === 'Enter')   { e.preventDefault(); go(opts()[active]); }
    });
    list.addEventListener('click', function (e) { go(e.target.closest('.tb-search-opt')); });
    list.addEventListener('mousemove', function (e) {
      var el = e.target.closest('.tb-search-opt');
      if (el) setActive(Array.prototype.indexOf.call(opts(), el));
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) { e.preventDefault(); close(); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open(); }
    });
  })();
</script>
