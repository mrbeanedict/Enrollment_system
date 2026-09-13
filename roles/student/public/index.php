<?php
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Greenfield Senior High School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,560;9..144,650&display=swap" rel="stylesheet">
<style>
  :root{
    /* ---- brand tokens (same as student portal) ---- */
    --color-primary:#386641;
    --color-primary-hover:#2F5636;
    --color-primary-active:#24422A;
    --color-dark:#1C2628;
    --color-muted:#9FB8B8;
    --color-info:#CADEDE;
    --color-accent:#FEFAE0;

    --accent-ink:#8A6D1F;
    --bg:#F4F8F5;
    --paper:#FFFFFF;
    --ink:var(--color-dark);
    --ink-soft:#5C6E6E;
    --ink-faint:#8FA3A3;
    --border:#E1EBE5;
    --border-soft:#ECF3EE;
    --primary-tint:#E3EEE5;
    --dark-tint:#2A3638;

    --radius-lg:22px;
    --radius-md:14px;
    --radius-sm:10px;
    --shadow-soft:0 1px 2px rgba(28,38,40,0.04), 0 10px 28px rgba(28,38,40,0.06);
  }
  *{box-sizing:border-box;}
  html{scroll-behavior:smooth;}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:'Inter',sans-serif;-webkit-font-smoothing:antialiased;}
  h1,h2,h3,h4{margin:0;color:var(--ink);font-family:'Fraunces',serif;font-weight:560;letter-spacing:-0.01em;line-height:1.12;}
  p{line-height:1.65;color:var(--ink-soft);margin:0;}
  a{color:inherit;}
  .wrap{max-width:1180px;margin:0 auto;padding:0 28px;}
  .eyebrow{font-size:0.72rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--color-primary);font-weight:700;}
  .btn{display:inline-flex;align-items:center;gap:8px;font-family:'Inter',sans-serif;font-weight:600;font-size:0.94rem;padding:13px 26px;border-radius:999px;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:background .12s ease, border-color .12s ease, color .12s ease;}
  .btn-primary{background:var(--color-primary);color:#fff;}
  .btn-primary:hover{background:var(--color-primary-hover);}
  .btn-outline{background:transparent;color:var(--ink);border-color:var(--border);}
  .btn-outline:hover{border-color:var(--color-primary);color:var(--color-primary);}
  .btn-outline-light{background:transparent;color:#fff;border-color:rgba(255,255,255,0.5);}
  .btn-outline-light:hover{background:rgba(255,255,255,0.12);border-color:#fff;}
  section{padding:88px 0;}
  .section-head{max-width:600px;margin:0 0 44px;}
  .section-head h2{font-size:clamp(1.7rem,3.2vw,2.4rem);margin-top:10px;}
  .section-head p{margin-top:12px;font-size:1.02rem;}

  /* ================= NAV ================= */
  nav{position:fixed;top:0;left:0;right:0;z-index:60;padding-top:20px;}
  .nav-inner{
    max-width:1180px;margin:0 auto;padding:10px 14px;
    display:flex;align-items:center;justify-content:space-between;
    background:rgba(28,38,40,0.9);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
    border-radius:999px;box-shadow:0 12px 30px rgba(28,38,40,0.18);
  }
  .brand{display:flex;align-items:center;gap:10px;text-decoration:none;}
  .brand img{display:block;height:32px;width:auto;flex-shrink:0;}
  .brand-mark{width:32px;height:32px;border-radius:9px;background:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .brand span{font-family:'Fraunces',serif;font-weight:600;font-size:0.98rem;color:#fff;}
  .nav-links{display:none;gap:2px;align-items:center;}
  @media(min-width:860px){.nav-links{display:flex;}}
  .nav-links a{font-weight:500;font-size:0.86rem;text-decoration:none;color:rgba(255,255,255,0.72);padding:9px 16px;border-radius:999px;transition:background .15s ease,color .15s ease;}
  .nav-links a:hover{background:rgba(255,255,255,0.1);color:#fff;}
  .nav-right{display:flex;align-items:center;gap:8px;}
  .nav-portal{
    display:inline-flex;align-items:center;gap:7px;
    background:var(--color-accent);color:var(--accent-ink);
    font-size:0.82rem;font-weight:700;padding:9px 16px 9px 12px;border-radius:999px;text-decoration:none;
  }
  .nav-portal:hover{background:#FBF2C9;}
  .nav-cta{display:none;background:var(--color-primary);color:#fff;font-size:0.82rem;font-weight:700;padding:10px 18px;border-radius:999px;text-decoration:none;}
  @media(min-width:860px){.nav-cta{display:inline-flex;}}
  .nav-cta:hover{background:var(--color-primary-hover);}

  /* ================= HERO ================= */
  .hero{
    position:relative;overflow:hidden;
    min-height:100vh;
    display:flex;align-items:center;
    padding-top:0;padding-bottom:0;
    background:var(--color-dark);
  }
  .hero > .wrap{width:100%;}

  /* photograph layer — "clear" variant (no baked-in mockup text) with a much
     lighter treatment than the old ui_landscape.png pass, so the photo
     itself stays visible instead of reading as a dark backdrop. */
  .hero-photo{
    position:absolute;inset:0;
    background-image:url('/Enrollment_system/assets/images/background/ui_landscape_clear.png');
    background-size:cover;
    background-position:center 62%;
    filter:contrast(1.02) brightness(.98) saturate(1);
    transform:scale(1.03); /* hides soft edge from the grain overlay */
  }

  /* grey-green duotone: recolors the photo's hue/saturation using the brand
     muted tone while preserving its original luminance/detail */
  .hero-photo-duotone{
    position:absolute;inset:0;
    background:var(--color-muted);
    mix-blend-mode:color;
    opacity:.18;
  }

  .hero-deco{position:absolute;inset:0;overflow:hidden;}

  /* light brand wash — just enough for white type to stay legible without
     hiding the photo underneath (per the old heavy-tint version, which
     made the background read as nearly solid dark green). */
  .hero-deco .tint{
    position:absolute;inset:0;
    background:linear-gradient(165deg, rgba(28,38,40,0.42) 0%, rgba(36,66,42,0.32) 42%, rgba(56,102,65,0.18) 78%, rgba(28,38,40,0.22) 100%);
    mix-blend-mode:multiply;
  }
  .hero-deco .tint-top{
    position:absolute;inset:0;
    background:linear-gradient(180deg, rgba(28,38,40,0.35) 0%, rgba(28,38,40,0) 34%, rgba(28,38,40,0) 62%, rgba(28,38,40,0.5) 100%);
  }

  .hero-deco .blob{position:absolute;border-radius:50%;filter:blur(2px);opacity:.55;mix-blend-mode:screen;}
  .hero-deco .b1{width:480px;height:480px;background:radial-gradient(circle at 30% 30%,#4A7A54,transparent 70%);top:-160px;right:-120px;}
  .hero-deco .b2{width:340px;height:340px;background:radial-gradient(circle at 60% 40%,#2F5636,transparent 70%);bottom:-140px;left:-80px;}
  .hero-deco .grid{position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,0.08) 1px, transparent 1px);background-size:26px 26px;opacity:.5;}
  .hero-deco .spark{position:absolute;color:var(--color-accent);opacity:.75;font-size:1.1rem;}

  /* film-grain / noise pass — sits above everything else in .hero-deco so
     the whole composite (photo + tint + blobs) reads as one grainy plate */
  .hero-deco .noise{
    position:absolute;inset:-10%;
    width:120%;height:120%;
    opacity:.22;
    mix-blend-mode:overlay;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='180' height='180'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    background-size:180px 180px;
    pointer-events:none;
  }
  .hero-inner{position:relative;z-index:2;text-align:center;padding-bottom:70px;}
  .hero-eyebrow{color:var(--color-muted);}
  .hero h1{font-size:clamp(2.4rem,5.6vw,4.1rem);color:#fff;margin-top:16px;max-width:820px;margin-left:auto;margin-right:auto;}
  .hero h1 .accent{color:#fff;}
  .hero .lead{font-size:1.06rem;margin:20px auto 0;max-width:52ch;color:rgba(255,255,255,0.72);}
  .hero-actions{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;margin-top:32px;}

  /* ================= ABOUT (two-col, checklist + image) ================= */
  .about-grid{display:grid;grid-template-columns:1fr;gap:44px;align-items:center;}
  @media(min-width:900px){.about-grid{grid-template-columns:1fr 1fr;}}
  .about-copy h2{font-size:clamp(1.8rem,3.4vw,2.5rem);margin-top:10px;}
  .about-copy .lead-sub{margin-top:14px;font-size:1.02rem;max-width:46ch;}
  .check-list{list-style:none;padding:0;margin:26px 0 0;display:flex;flex-direction:column;gap:16px;}
  .check-list li{display:flex;gap:12px;align-items:flex-start;}
  .check-mark{flex-shrink:0;width:24px;height:24px;border-radius:50%;background:var(--primary-tint);color:var(--color-primary);display:flex;align-items:center;justify-content:center;margin-top:1px;}
  .check-list strong{display:block;font-size:0.96rem;font-weight:600;color:var(--ink);}
  .check-list span{font-size:0.88rem;color:var(--ink-soft);}
  .about-copy .btn{margin-top:28px;}

  .about-logo{max-width:420px;width:100%;justify-self:center;}

  .photo-panel{
    border-radius:var(--radius-lg);aspect-ratio:2/3;position:relative;overflow:hidden;
    display:flex;align-items:flex-end;padding:22px;
    box-shadow:var(--shadow-soft);
    background-color:var(--primary-tint);
    background-size:contain;background-repeat:no-repeat;background-position:center;
  }
  .photo-panel .tag{
    background:rgba(255,255,255,0.92);color:var(--ink);font-size:0.76rem;font-weight:700;
    padding:6px 13px;border-radius:999px;
  }
  .photo-panel svg{position:absolute;inset:0;width:100%;height:100%;}

  /* tone recipe for the "why" section's placeholder photo panel — built from
     the brand tokens only */
  .tone-a{background-image:linear-gradient(155deg,#4A7A54 0%,var(--color-primary) 55%,var(--color-primary-active) 100%);}

  /* ================= FULL BLEED BAND ================= */
  .band{
    position:relative;overflow:hidden;border-radius:var(--radius-lg);
    background:linear-gradient(120deg,var(--color-dark) 0%,var(--color-primary-active) 100%);
    padding:clamp(48px,8vw,88px) clamp(28px,6vw,72px);
    color:#fff;margin:0 auto;max-width:1180px;
  }
  .band-grid{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:32px;}
  .band h2{color:#fff;font-size:clamp(1.7rem,3.4vw,2.5rem);max-width:16ch;}
  .band-badge{
    flex-shrink:0;width:112px;height:112px;border-radius:50%;
    background:var(--color-accent);color:var(--accent-ink);
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    font-family:'Fraunces',serif;
  }
  .band-badge strong{font-size:1.6rem;line-height:1;}
  .band-badge span{font-size:0.62rem;text-transform:uppercase;letter-spacing:.06em;margin-top:4px;font-family:'Inter',sans-serif;font-weight:700;}
  .band-deco{position:absolute;right:-60px;top:-60px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,0.08),transparent 70%);}

  /* ================= STATEMENT STRIP ================= */
  .statement{display:grid;grid-template-columns:1fr;gap:26px;align-items:center;}
  @media(min-width:820px){.statement{grid-template-columns:auto 1fr;}}
  .statement-icon{width:64px;height:64px;border-radius:16px;background:var(--primary-tint);color:var(--color-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .statement p.big{font-family:'Fraunces',serif;font-size:clamp(1.3rem,2.6vw,1.8rem);font-weight:500;color:var(--ink);line-height:1.4;}

  /* ================= JOURNEY ================= */
  .section-alt{background:var(--paper);border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
  .journey-steps{display:flex;align-items:stretch;gap:0;flex-wrap:wrap;}
  .journey-step{flex:1 1 190px;display:flex;flex-direction:column;background:var(--bg);border:1px solid var(--border-soft);border-radius:var(--radius-md);padding:22px 20px;position:relative;transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;}
  .journey-step:hover{transform:translateY(-4px);box-shadow:var(--shadow-soft);border-color:var(--color-primary);}
  .journey-step-top{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
  .journey-step-icon{width:46px;height:46px;border-radius:50%;background:var(--primary-tint);color:var(--color-primary-active);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s ease, color .15s ease;}
  .journey-step:hover .journey-step-icon{background:var(--color-primary);color:#fff;}
  .journey-step-num{width:22px;height:22px;border-radius:50%;background:var(--color-primary);color:#fff;font-size:0.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .journey-step h3{font-size:0.98rem;margin-bottom:6px;}
  .journey-step p{font-size:0.84rem;}
  .journey-arrow{display:none;align-items:center;justify-content:center;flex:0 0 28px;color:var(--ink-faint);opacity:.6;}
  @media(min-width:900px){.journey-arrow{display:flex;}.journey-step{flex-basis:0;}}
  @media(max-width:899px){.journey-steps{flex-direction:column;gap:14px;}.journey-step{flex-basis:auto;}}

  /* ================= FINAL CTA (dark subscribe band) ================= */
  .cta-band{background:var(--color-dark);border-radius:var(--radius-lg);padding:clamp(44px,7vw,72px) clamp(24px,6vw,64px);position:relative;overflow:hidden;}
  .cta-band .grid{position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,0.06) 1px, transparent 1px);background-size:24px 24px;}
  .cta-inner{position:relative;z-index:2;max-width:640px;margin:0 auto;text-align:center;}
  .cta-band h2{color:#fff;font-size:clamp(1.6rem,3.2vw,2.2rem);}
  .cta-band p{color:var(--color-muted);margin-top:12px;}
  .lookup-form{display:flex;flex-wrap:wrap;gap:10px;margin-top:28px;justify-content:center;}
  .lookup-form input{flex:1 1 260px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.16);border-radius:999px;padding:13px 18px;color:#fff;font-size:0.92rem;font-family:'Inter',sans-serif;}
  .lookup-form input::placeholder{color:rgba(255,255,255,0.42);}
  .lookup-form input:focus{outline:2px solid var(--color-accent);outline-offset:1px;}
  .lookup-form button{flex-shrink:0;}
  .lookup-form button:disabled{opacity:0.65;cursor:not-allowed;}

  .status-result{margin-top:18px;font-size:0.92rem;height:78px;display:flex;align-items:center;justify-content:center;}
  .status-result .found{color:#fff;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.16);border-radius:14px;padding:14px 18px;display:inline-block;}
  .status-result .found strong{color:var(--color-accent);}
  .status-result .not-found{color:rgba(255,255,255,0.62);}

  /* ================= STRAND CAROUSEL (large portrait cards, cropped peek) ================= */
  .strand-head{max-width:480px;margin:0 0 52px;}
  .strand-head h2{font-size:clamp(2.1rem,3.8vw,2.9rem);margin-top:10px;}

  .strand-viewport{overflow-x:hidden;}
  .strand-gallery{
    display:flex;
    align-items:flex-start;
    gap:clamp(24px,3vw,40px);
    scroll-behavior:smooth;
  }

  .strand-col{
    display:block;text-decoration:none;
    flex:0 0 clamp(220px,24vw,300px);
  }

  .strand-photo{
    display:block;position:relative;overflow:hidden;
    aspect-ratio:3/4.3;
    border-radius:var(--radius-lg);
    border:0.5px solid var(--border);
    box-shadow:var(--shadow-soft);
    transition:border-color 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease;
  }
  .strand-photo img{display:block;width:100%;height:100%;object-fit:cover;}
  .strand-col:hover .strand-photo{
    border-color:var(--color-primary);
    box-shadow:0 14px 28px rgba(28,38,40,0.18);
    transform:translateY(-4px);
  }
  .strand-col:active .strand-photo{transform:translateY(-1px);}
  .strand-col:focus-visible .strand-photo{outline:2px solid var(--color-primary);outline-offset:2px;}

  /* Strand short name, overlaid directly on the photo — not part of the
     reference image, added on top of it. */
  .strand-photo-label{
    position:absolute;left:12px;bottom:12px;
    background:rgba(28,38,40,0.72);color:#fff;
    font-size:0.72rem;font-weight:700;letter-spacing:0.03em;
    padding:5px 12px;border-radius:999px;
  }

  .strand-caption{display:block;margin-top:14px;}
  .strand-full{display:block;font-size:0.94rem;font-weight:600;color:var(--ink);line-height:1.4;max-width:22ch;}

  /* Dot indicators — one per card, centered above the control bar. */
  .strand-dots{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:44px;}
  .strand-dot{
    width:8px;height:8px;padding:0;border-radius:50%;border:none;cursor:pointer;
    background:var(--border);transition:background 0.15s ease, transform 0.15s ease, width 0.15s ease;
  }
  .strand-dot:hover{background:var(--ink-faint);}
  .strand-dot.active{width:22px;border-radius:5px;background:var(--color-primary);}

  /* Prev/next/play-pause controls, centered as one deliberate control bar. */
  .strand-controls{display:flex;justify-content:center;gap:10px;margin-top:16px;}
  .strand-nav-btn{
    width:42px;height:42px;border-radius:50%;
    border:0.5px solid var(--border);background:var(--paper);color:var(--ink-soft);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    transition:border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
  }
  .strand-nav-btn.strand-nav-next{background:var(--color-dark);border-color:var(--color-dark);color:#fff;}
  .strand-nav-btn.strand-nav-next:hover{background:var(--color-primary-active);border-color:var(--color-primary-active);}
  .strand-nav-btn:not(.strand-nav-next):hover{border-color:var(--color-primary);color:var(--color-primary);}
  .strand-nav-btn:disabled{opacity:0.4;cursor:not-allowed;}
  .strand-nav-btn svg{width:15px;height:15px;}

  /* Autoplay progress bar — thin track under the control bar, fill
     animates 0%→100% over one autoplay cycle, driven by JS toggling
     the .running class (CSS transition does the animating). */
  .strand-progress{
    width:160px;height:3px;border-radius:2px;margin:16px auto 0;
    background:var(--primary-tint);overflow:hidden;
  }
  .strand-progress-fill{
    width:0%;height:100%;border-radius:2px;background:var(--color-primary);
    transition:width linear 0s;
  }
  .strand-progress-fill.running{width:100%;transition-duration:var(--strand-autoplay-ms,4500ms);}

  /* Footer markup/CSS now lives in includes/footer.php (self-contained,
     included below) so it can be reused by other student/ pages. Forced
     here too since this page's own body{background:var(--bg)} (light)
     was showing through instead of the footer's dark background. */
  footer{background:var(--color-dark) !important;}

  @media (prefers-reduced-motion: reduce){*{transition:none !important;}}
</style>
</head>
<body>

<!-- ==================== HERO ==================== -->
<header class="hero" id="top">
  <div class="hero-photo"></div>
  <div class="hero-photo-duotone"></div>
  <div class="hero-deco">
    <div class="tint"></div>
    <div class="grid"></div>
    <div class="blob b1"></div>
    <div class="blob b2"></div>
    <span class="spark" style="top:120px;left:8%;">✦</span>
    <span class="spark" style="top:190px;right:10%;">✦</span>
    <span class="spark" style="top:70px;right:26%;">✦</span>
    <div class="noise"></div>
    <div class="tint-top"></div>
  </div>

  <div class="wrap hero-inner">
    <h1>Shape your future at <span class="accent">Greenfield Senior High School.</span></h1>
    <p class="lead">Discover the strand that fits your goals, build the skills that matter, and take your next step with confidence.</p>
    <div class="hero-actions">
      <a href="admission" class="btn btn-primary">Start Enrollment</a>
      <a href="#strands" class="btn btn-outline-light">Explore Strands</a>
    </div>
  </div>

</header>

<!-- ==================== WHY Greenfield Senior High School ==================== -->
<section id="why">
  <div class="wrap">
    <div class="about-grid">
      <div class="about-copy">
        <span class="eyebrow">About Us</span>
        <h2>Why Greenfield Senior High School</h2>
        <p class="lead-sub">A Senior High School experience focused on growth, preparation, and the path ahead — for college, careers, and life after Grade 12.</p>

        <ul class="check-list">
          <li>
            <div class="check-mark"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="m5 12.5 4.5 4.5L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div><strong>Academic Excellence</strong><span>Build strong foundations for higher education and future careers.</span></div>
          </li>
          <li>
            <div class="check-mark"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="m5 12.5 4.5 4.5L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div><strong>College &amp; Career Preparation</strong><span>Develop practical skills and direction for your chosen path.</span></div>
          </li>
          <li>
            <div class="check-mark"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="m5 12.5 4.5 4.5L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div><strong>Student Development</strong><span>Grow confidence, independence, and skills in a supportive environment.</span></div>
          </li>
        </ul>

        <a href="#strands" class="btn btn-primary">Explore Our Strands →</a>
      </div>

      <img src="/Enrollment_system/assets/images/logo2.png" alt="Greenfield Senior High School" class="about-logo">
    </div>
  </div>
</section>

<!-- ==================== TRACKS & STRANDS (kept from the original page) ==================== -->
<section id="strands">
  <div class="wrap">

    <div class="section-head strand-head">
      <span class="eyebrow">Tracks & strands offered</span>
      <h2>Choose a strand.</h2>
      <p>Five DepEd-recognized tracks, each built around where you want to go after Grade 12.</p>
    </div>

    <div class="strand-viewport">
      <div class="strand-gallery" id="strandGallery">

        <!-- STEM -->
        <a class="strand-col" href="admission?strand=STEM">
          <span class="strand-photo">
            <img src="/Enrollment_system/assets/images/admission_strand/STEM.png" alt="">
            <span class="strand-photo-label">STEM</span>
          </span>
          <span class="strand-caption">
            <span class="strand-full">Science, Technology, Engineering and Mathematics</span>
          </span>
        </a>

        <!-- ABM -->
        <a class="strand-col" href="admission?strand=ABM">
          <span class="strand-photo">
            <img src="/Enrollment_system/assets/images/admission_strand/ABM.jpeg" alt="">
            <span class="strand-photo-label">ABM</span>
          </span>
          <span class="strand-caption">
            <span class="strand-full">Accountancy, Business and Management</span>
          </span>
        </a>

        <!-- HUMSS -->
        <a class="strand-col" href="admission?strand=HUMSS">
          <span class="strand-photo">
            <img src="/Enrollment_system/assets/images/admission_strand/HUMSS.jpg" alt="">
            <span class="strand-photo-label">HUMSS</span>
          </span>
          <span class="strand-caption">
            <span class="strand-full">Humanities and Social Sciences</span>
          </span>
        </a>

        <!-- GAS -->
        <a class="strand-col" href="admission?strand=GAS">
          <span class="strand-photo">
            <img src="/Enrollment_system/assets/images/admission_strand/GAS.png" alt="">
            <span class="strand-photo-label">GAS</span>
          </span>
          <span class="strand-caption">
            <span class="strand-full">General Academic Strand</span>
          </span>
        </a>

        <!-- TVL-ICT -->
        <a class="strand-col" href="admission?strand=TVL-ICT">
          <span class="strand-photo">
            <img src="/Enrollment_system/assets/images/admission_strand/ICT.jpg" alt="">
            <span class="strand-photo-label">TVL-ICT</span>
          </span>
          <span class="strand-caption">
            <span class="strand-full">Information and Communications Technology</span>
          </span>
        </a>

      </div>
    </div>

    <div class="strand-dots" id="strandDots">
      <button type="button" class="strand-dot active" data-index="0" aria-label="Go to STEM"></button>
      <button type="button" class="strand-dot" data-index="1" aria-label="Go to ABM"></button>
      <button type="button" class="strand-dot" data-index="2" aria-label="Go to HUMSS"></button>
      <button type="button" class="strand-dot" data-index="3" aria-label="Go to GAS"></button>
      <button type="button" class="strand-dot" data-index="4" aria-label="Go to TVL-ICT"></button>
    </div>

    <div class="strand-controls">
      <button type="button" class="strand-nav-btn" id="strandPrev" aria-label="Previous strand">
        <svg viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <button type="button" class="strand-nav-btn strand-nav-next" id="strandNext" aria-label="Next strand">
        <svg viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <button type="button" class="strand-nav-btn" id="strandPlayPause" aria-label="Pause carousel">
        <svg id="strandPlayPauseIcon" viewBox="0 0 24 24" fill="none"><path d="M8 6v12M16 6v12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
      </button>
    </div>

    <div class="strand-progress"><div class="strand-progress-fill" id="strandProgressFill"></div></div>
  </div>
</section>

<!-- ==================== FULL-BLEED BAND ==================== -->
<section>
  <div class="wrap">
    <div class="band">
      <div class="band-deco"></div>
      <div class="band-grid">
        <h2>Learn beyond the classroom, with mentors who know your name.</h2>
        <div class="band-badge"><strong>5</strong><span>Tracks</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ==================== STATEMENT STRIP ==================== -->
<section class="section-alt">
  <div class="wrap">
    <div class="statement">
      <div class="statement-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.9 6.3 6.9.6-5.2 4.6 1.6 6.8-6.2-3.7-6.2 3.7 1.6-6.8L2.2 8.9l6.9-.6L12 2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
      </div>
      <p class="big">We're changing what senior high feels like — smaller sections, real mentorship, and a campus built around five clear paths forward.</p>
    </div>
  </div>
</section>

<!-- ==================== HOW TO ENROLL ==================== -->
<section id="journey">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">Enrollment Process</span>
      <h2>Your journey starts here.</h2>
      <p>Five simple steps from online application to student portal access — track your progress at every stage.</p>
    </div>

    <div class="journey-steps">
      <div class="journey-step">
        <div class="journey-step-top">
          <div class="journey-step-icon"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.4" stroke="currentColor" stroke-width="1.8"/><path d="M5 20c0-3.5 3.1-6.2 7-6.2s7 2.7 7 6.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
          <span class="journey-step-num">1</span>
        </div>
        <h3>Create an account</h3>
        <p>Sign up using your Student Number if you already have one, or create a new student account.</p>
      </div>
      <div class="journey-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>

      <div class="journey-step">
        <div class="journey-step-top">
          <div class="journey-step-icon"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="5" y="3.5" width="14" height="17" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
          <span class="journey-step-num">2</span>
        </div>
        <h3>Fill out application</h3>
        <p>Choose your preferred grade level and strand, then submit for review.</p>
      </div>
      <div class="journey-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>

      <div class="journey-step">
        <div class="journey-step-top">
          <div class="journey-step-icon"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M7 18a4.5 4.5 0 0 1-.5-8.97A5.5 5.5 0 0 1 17.4 8.5 4 4 0 0 1 17 18H7z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 10.5v6M9.2 13.3 12 10.5l2.8 2.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
          <span class="journey-step-num">3</span>
        </div>
        <h3>Upload requirements</h3>
        <p>Submit Form 138, PSA birth certificate, and good moral certificate securely online.</p>
      </div>
      <div class="journey-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>

      <div class="journey-step">
        <div class="journey-step-top">
          <div class="journey-step-icon"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="m8 12.5 2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
          <span class="journey-step-num">4</span>
        </div>
        <h3>Track your application</h3>
        <p>Monitor your admission status in real time as our Records team reviews your file.</p>
      </div>
      <div class="journey-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>

      <div class="journey-step">
        <div class="journey-step-top">
          <div class="journey-step-icon"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M12 3l2.2 4.6 5 .7-3.6 3.6.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.6 5-.7L12 3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></div>
          <span class="journey-step-num">5</span>
        </div>
        <h3>Get admitted & enroll</h3>
        <p>Complete section selection, pay enrollment fees, and get your student portal access.</p>
      </div>
    </div>
  </div>
</section>

<!-- ==================== FINAL CTA ==================== -->
<section id="enroll" class="section-alt">
  <div class="wrap">
    <div class="cta-band">
      <div class="grid"></div>
      <div class="cta-inner">
        <span class="eyebrow" style="color:var(--color-accent);">Ready to Take the Next Step?</span>
        <h2 style="margin-top:10px;">Start your enrollment, or check where your application stands.</h2>
        <p>Already applied? Look up your status with your Student Number or application number.</p>
        <form class="lookup-form" id="statusLookupForm">
          <input type="text" name="identifier" id="statusIdentifier" placeholder="Enter your Student Number or control number" required>
          <button type="submit" class="btn btn-primary" id="statusLookupBtn">Check Status</button>
          <!-- Honeypot — real applicants never see or fill this. -->
          <div style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;" aria-hidden="true">
            <label for="statusWebsite">Leave this field blank</label>
            <input type="text" id="statusWebsite" name="website" tabindex="-1" autocomplete="off">
          </div>
        </form>
        <div id="statusResult" class="status-result" role="status" aria-live="polite"></div>
      </div>
    </div>
  </div>
</section>

<?php include BASE_PATH . '/shared/includes/footer.php'; ?>

<script>
  // Strand carousel: cards keep their fixed CSS width (no forced-fit
  // math) and the prev/next buttons glide by one card width at a time,
  // cropping the trailing card at the right edge until it's scrolled in.
  (function () {
    var viewport = document.querySelector('.strand-viewport');
    var gallery = document.getElementById('strandGallery');
    var prev = document.getElementById('strandPrev');
    var next = document.getElementById('strandNext');
    var playPause = document.getElementById('strandPlayPause');
    var playPauseIcon = document.getElementById('strandPlayPauseIcon');
    var dots = Array.prototype.slice.call(document.querySelectorAll('#strandDots .strand-dot'));
    var progressFill = document.getElementById('strandProgressFill');
    if (!viewport || !gallery || !prev || !next || !playPause) return;

    var PAUSE_ICON = '<path d="M8 6v12M16 6v12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>';
    var PLAY_ICON  = '<path d="M8 5.5v13l11-6.5-11-6.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var AUTOPLAY_MS = 4500;
    var timer = null;
    var userPaused = false;

    document.documentElement.style.setProperty('--strand-autoplay-ms', AUTOPLAY_MS + 'ms');

    function step() {
      var col = gallery.querySelector('.strand-col');
      if (!col) return viewport.clientWidth;
      var gap = parseFloat(getComputedStyle(gallery).columnGap || getComputedStyle(gallery).gap || '0');
      return col.getBoundingClientRect().width + gap;
    }

    function updateDots() {
      if (!dots.length) return;
      var cols = gallery.querySelectorAll('.strand-col');
      var s = step() || 1;
      var idx = Math.round(viewport.scrollLeft / s);
      idx = Math.max(0, Math.min(cols.length - 1, idx));
      dots.forEach(function (dot, i) { dot.classList.toggle('active', i === idx); });
    }

    function update() {
      prev.disabled = viewport.scrollLeft <= 4;
      next.disabled = viewport.scrollLeft >= viewport.scrollWidth - viewport.clientWidth - 4;
      updateDots();
    }

    function advance() {
      var atEnd = viewport.scrollLeft >= viewport.scrollWidth - viewport.clientWidth - 4;
      if (atEnd) {
        viewport.scrollTo({ left: 0, behavior: 'smooth' });
      } else {
        viewport.scrollBy({ left: step(), behavior: 'smooth' });
      }
    }

    function runProgress() {
      if (!progressFill) return;
      progressFill.classList.remove('running');
      // Force reflow so the class can be re-added to restart the transition.
      void progressFill.offsetWidth;
      progressFill.classList.add('running');
    }

    function resetProgress() {
      if (progressFill) progressFill.classList.remove('running');
    }

    function startAutoplay() {
      if (reduceMotion || userPaused || timer) return;
      runProgress();
      timer = setInterval(function () { advance(); runProgress(); }, AUTOPLAY_MS);
    }

    function stopAutoplay() {
      if (timer) { clearInterval(timer); timer = null; }
      resetProgress();
    }

    function setPaused(paused) {
      userPaused = paused;
      playPause.setAttribute('aria-label', paused ? 'Resume carousel' : 'Pause carousel');
      if (playPauseIcon) playPauseIcon.outerHTML = paused
        ? '<svg id="strandPlayPauseIcon" viewBox="0 0 24 24" fill="none">' + PLAY_ICON + '</svg>'
        : '<svg id="strandPlayPauseIcon" viewBox="0 0 24 24" fill="none">' + PAUSE_ICON + '</svg>';
      playPauseIcon = document.getElementById('strandPlayPauseIcon');
      if (paused) { stopAutoplay(); } else { startAutoplay(); }
    }

    playPause.addEventListener('click', function () {
      setPaused(!userPaused);
    });

    prev.addEventListener('click', function () {
      viewport.scrollBy({ left: -step(), behavior: 'smooth' });
      setPaused(true);
    });
    next.addEventListener('click', function () {
      viewport.scrollBy({ left: step(), behavior: 'smooth' });
      setPaused(true);
    });

    dots.forEach(function (dot, i) {
      dot.addEventListener('click', function () {
        viewport.scrollTo({ left: step() * i, behavior: 'smooth' });
        setPaused(true);
      });
    });

    viewport.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    viewport.addEventListener('mouseenter', stopAutoplay);
    viewport.addEventListener('mouseleave', function () { if (!userPaused) startAutoplay(); });
    viewport.addEventListener('focusin', stopAutoplay);
    viewport.addEventListener('focusout', function () { if (!userPaused) startAutoplay(); });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stopAutoplay(); } else if (!userPaused) { startAutoplay(); }
    });

    update();
    if (reduceMotion) setPaused(true);
    startAutoplay();
  })();

  // Application status lookup (Check Status box in the final CTA band).
  (function () {
    var form = document.getElementById('statusLookupForm');
    var input = document.getElementById('statusIdentifier');
    var btn = document.getElementById('statusLookupBtn');
    var result = document.getElementById('statusResult');
    if (!form || !input || !btn || !result) return;

    function esc(str) {
      return String(str || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var identifier = input.value.trim();
      if (!identifier) return;

      btn.disabled = true;
      var originalLabel = btn.textContent;
      btn.textContent = 'Checking…';
      result.innerHTML = '';

      var body = new URLSearchParams();
      body.set('identifier', identifier);
      body.set('website', document.getElementById('statusWebsite').value);

      fetch('/Enrollment_system/ajax/status_lookup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.found) {
            var meta = [];
            if (data.grade_level) meta.push('Grade ' + esc(data.grade_level));
            if (data.strand) meta.push(esc(data.strand));
            if (data.school_year) meta.push('SY ' + esc(data.school_year));
            result.innerHTML = '<div class="found">Hi ' + esc(data.given_name) + ' — your application status is <strong>' + esc(data.status) + '</strong>'
              + (meta.length ? '<br>' + meta.join(' · ') : '') + '</div>';
          } else {
            result.innerHTML = '<div class="not-found">We couldn\'t find an application with that number — double check it and try again, or contact the registrar\'s office.</div>';
          }
        })
        .catch(function () {
          result.innerHTML = '<div class="not-found">Something went wrong. Please try again in a moment.</div>';
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = originalLabel;
        });
    });
  })();
</script>

</body>
</html>
