<?php
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Requirements Checklist | Greenfield Senior High School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,560;9..144,650&display=swap" rel="stylesheet">
<!-- Uses the same shared stylesheet as students/index.php -->
<link rel="stylesheet" href="styles.css">
<style>
  /* ---------- page-only additions (checklist section) ---------- */
  .page-hero{background:var(--color-dark);padding:64px 0 40px;}
  .page-hero .wrap{text-align:center;}
  .page-hero .eyebrow{color:var(--color-muted);}
  .page-hero h1{font-family:'Fraunces',serif;font-weight:560;color:#fff;font-size:clamp(1.9rem,4vw,2.7rem);margin-top:10px;}
  .page-hero p{color:rgba(255,255,255,0.7);margin-top:12px;max-width:52ch;margin-left:auto;margin-right:auto;}

  .checklist-group{margin-bottom:40px;}
  .checklist-group-head{display:flex;align-items:baseline;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
  .checklist-group-head h2{font-size:1.15rem;font-weight:600;}
  .checklist-group-head .tag{font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;padding:4px 10px;border-radius:999px;background:var(--accent-bg);color:var(--accent);}
  .checklist-group-head .tag.voucher{background:#FEFAE0;color:#8A6D1F;}

  .req-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px;}
  .req-item{display:flex;align-items:flex-start;gap:14px;background:var(--paper);border:0.5px solid var(--border);border-radius:var(--radius-md);padding:16px 18px;box-shadow:var(--shadow-soft);}
  .req-num{flex-shrink:0;width:30px;height:30px;border-radius:50%;background:var(--accent-bg);color:var(--accent);font-weight:700;font-size:0.85rem;display:flex;align-items:center;justify-content:center;}
  .req-item h3{font-size:0.98rem;font-weight:600;margin:0;}
  .req-item p{font-size:0.85rem;margin-top:4px;}

  .req-note{background:#FEFAE0;border:0.5px solid #EFE3A8;border-radius:var(--radius-md);padding:16px 18px;font-size:0.86rem;color:#5C4E12;margin-top:6px;}
  .req-note strong{color:#8A6D1F;}

  .checklist-cta{text-align:center;margin-top:48px;}
  .checklist-cta p{margin-bottom:16px;}
</style>
</head>
<body>

<!-- ==================== NAVIGATION ==================== -->
<nav>
  <div class="wrap">
    <a href="index.php" class="brand">
      <img src="/Enrollment_system/assets/images/logo_mini2.png" alt="Greenfield Senior High School">
    </a>

    <div class="nav-links">
      <a href="index.php">Home</a>
      <a href="index.php#why">About</a>
      <a href="index.php#strands">Strands</a>
      <div class="nav-dropdown">
        <a href="index.php#enroll" class="nav-dropdown-toggle active">Admissions</a>
        <div class="nav-dropdown-menu">
          <a href="application_form.php">Application Form</a>
          <a href="requirement_checklist.php" class="active">Requirements Checklist</a>
          <a href="track_status.php">Track Application Status</a>
          <a href="faq.php">Enrollment FAQs</a>
        </div>
      </div>
    </div>

    <div class="nav-right">
      <a href="/Enrollment_system/login" class="nav-portal-link">Student Portal</a>
      <a href="application_form.php" class="btn btn-primary nav-cta">Apply Now</a>
    </div>
  </div>
</nav>

<!-- minimal dropdown styling (kept local so it doesn't have to be
     merged into the shared stylesheet) -->
<style>
  .nav-dropdown{position:relative;}
  .nav-dropdown-menu{
    display:none;position:absolute;top:calc(100% + 10px);left:50%;transform:translateX(-50%);
    background:var(--dark);border-radius:14px;padding:8px;min-width:220px;
    box-shadow:0 16px 34px rgba(28,38,40,0.28);
  }
  .nav-dropdown-menu a{display:block;padding:10px 14px;border-radius:9px;font-size:0.86rem;color:rgba(255,255,255,0.78);}
  .nav-dropdown-menu a:hover,.nav-dropdown-menu a.active{background:rgba(255,255,255,0.1);color:#fff;}
  .nav-dropdown:hover .nav-dropdown-menu,
  .nav-dropdown:focus-within .nav-dropdown-menu{display:block;}
</style>

<!-- ==================== PAGE HERO ==================== -->
<header class="page-hero">
  <div class="wrap">
    <span class="eyebrow">Admissions</span>
    <h1>Enrollment Requirements Checklist</h1>
    <p>Here's everything you need to prepare before you submit your Senior High School application.</p>
  </div>
</header>

<!-- ==================== CHECKLIST ==================== -->
<section>
  <div class="wrap">

    <div class="checklist-group">
      <div class="checklist-group-head">
        <h2>Required for All Applicants</h2>
        <span class="tag">All applicants</span>
      </div>

      <ul class="req-list">
        <li class="req-item">
          <span class="req-num">1</span>
          <div><h3>Report Card (Form 138)</h3><p>Your most recent report card from Junior High School.</p></div>
        </li>
        <li class="req-item">
          <span class="req-num">2</span>
          <div><h3>PSA-issued Birth Certificate</h3><p>An original or certified true copy issued by the Philippine Statistics Authority.</p></div>
        </li>
        <li class="req-item">
          <span class="req-num">3</span>
          <div><h3>Official Transcript of Records (OTR)</h3><p>Requested from your previous school's registrar.</p></div>
        </li>
        <li class="req-item">
          <span class="req-num">4</span>
          <div><h3>Certificate of Good Moral Character</h3><p>Issued by your previous school, required for every applicant.</p></div>
        </li>
        <li class="req-item">
          <span class="req-num">5</span>
          <div><h3>2x2 ID Picture</h3><p>Recent photo with a white background, required for every applicant.</p></div>
        </li>
      </ul>
    </div>

    <div class="checklist-group">
      <div class="checklist-group-head">
        <h2>Required for Public Junior High School Graduates</h2>
        <span class="tag voucher">For SHS Voucher</span>
      </div>

      <ul class="req-list">
        <li class="req-item">
          <span class="req-num">6</span>
          <div><h3>Voucher Eligibility Certificate (VEC)</h3><p>Only applicants coming from a public Junior High School need to submit this — it's required to claim the SHS Voucher Program.</p></div>
        </li>
      </ul>

      <div class="req-note" style="margin-top:14px;">
        <strong>Note:</strong> The VEC is only for applicants graduating from a <strong>public</strong> Junior High School. If you're coming from a private JHS, you may skip this requirement.
      </div>
    </div>

    <div class="checklist-cta">
      <p>Have all your documents ready?</p>
      <a href="application_form.php" class="btn btn-primary">Start Your Application →</a>
    </div>

  </div>
</section>

<?php include BASE_PATH . '/shared/includes/footer.php'; ?>

</body>
</html>
