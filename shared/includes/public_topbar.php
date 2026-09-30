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
</script>
