<?php
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enrollment FAQs | Greenfield Senior High School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,560;9..144,650&display=swap" rel="stylesheet">
<!-- Uses the same shared stylesheet as students/index.php -->
<link rel="stylesheet" href="styles.css">
<style>
  /* ---------- page-only additions (FAQ section) ---------- */
  .page-hero{background:var(--color-dark);padding:64px 0 40px;}
  .page-hero .wrap{text-align:center;}
  .page-hero .eyebrow{color:var(--color-muted);}
  .page-hero h1{font-family:'Fraunces',serif;font-weight:560;color:#fff;font-size:clamp(1.9rem,4vw,2.7rem);margin-top:10px;}
  .page-hero p{color:rgba(255,255,255,0.7);margin-top:12px;max-width:52ch;margin-left:auto;margin-right:auto;}

  .faq-list{max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:12px;}
  .faq-item{background:var(--paper);border:0.5px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-soft);overflow:hidden;}
  .faq-item summary{list-style:none;cursor:pointer;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;font-weight:600;font-size:0.96rem;color:var(--ink);}
  .faq-item summary::-webkit-details-marker{display:none;}
  .faq-item .faq-icon{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:var(--accent-bg);color:var(--accent);display:flex;align-items:center;justify-content:center;transition:transform 0.15s ease;}
  .faq-item[open] .faq-icon{transform:rotate(45deg);}
  .faq-item .faq-answer{padding:0 20px 18px;font-size:0.88rem;color:var(--ink-soft);}

  .faq-cta{text-align:center;margin-top:48px;}
  .faq-cta p{margin-bottom:16px;}
</style>
</head>
<body>

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
          <a href="requirement_checklist.php">Requirements Checklist</a>
          <a href="track_status.php">Track Application Status</a>
          <a href="faq.php" class="active">Enrollment FAQs</a>
        </div>
      </div>
    </div>

    <div class="nav-right">
      <a href="/Enrollment_system/login" class="nav-portal-link">Student Portal</a>
      <a href="application_form.php" class="btn btn-primary nav-cta">Apply Now</a>
    </div>
  </div>
</nav>

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

<header class="page-hero">
  <div class="wrap">
    <span class="eyebrow">Admissions</span>
    <h1>Enrollment FAQs</h1>
    <p>Quick answers to the questions we get most about applying to Senior High School.</p>
  </div>
</header>

<section>
  <div class="wrap">
    <div class="faq-list">

      <details class="faq-item" open>
        <summary>Who can enroll in Senior High School (Grades 11–12)?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">Any Junior High School graduate — whether from a public or private school — can apply, as long as they meet the requirements listed on our Requirements Checklist page.</div>
      </details>

      <details class="faq-item">
        <summary>What documents do I need to prepare?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">You'll need your Report Card (Form 138), PSA-issued Birth Certificate, Official Transcript of Records, Certificate of Good Moral Character, and a 2x2 ID picture. If you're from a public Junior High School, you'll also need a Voucher Eligibility Certificate. See our full Requirements Checklist for details.</div>
      </details>

      <details class="faq-item">
        <summary>How do I apply online?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">Create a student account, fill out the online application form with your preferred grade level and strand, then upload your requirements. You can track your status anytime once it's submitted.</div>
      </details>

      <details class="faq-item">
        <summary>What strands are offered?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">We offer five DepEd-recognized strands: STEM, ABM, HUMSS, GAS, and TVL-ICT. You can explore each one on our home page before choosing.</div>
      </details>

      <details class="faq-item">
        <summary>Do I need a Voucher Eligibility Certificate (VEC)?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">Only if you're coming from a public Junior High School and are applying for the SHS Voucher Program. Applicants from private JHS don't need to submit this.</div>
      </details>

      <details class="faq-item">
        <summary>How do I track my application status?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">Use the Check Status box on our home page and enter your LRN or application/control number to see your current status in real time.</div>
      </details>

      <details class="faq-item">
        <summary>Is there a deadline for enrollment?
          <span class="faq-icon">+</span>
        </summary>
        <div class="faq-answer">Enrollment periods are announced on our home page and through official school channels. We recommend applying early since slots per strand are limited.</div>
      </details>

    </div>

    <div class="faq-cta">
      <p>Still have questions?</p>
      <a href="application_form.php" class="btn btn-primary">Start Your Application →</a>
    </div>
  </div>
</section>

<?php include BASE_PATH . '/shared/includes/footer.php'; ?>

</body>
</html>
