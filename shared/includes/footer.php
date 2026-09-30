<?php
// Shared public-site footer (index, admission, faq, requirement_checklist).
// Links are built from APP_URL so they resolve the same from any page that
// includes this file.
$ft_pub = APP_URL . '/roles/student/public';
?>
<!-- ==================== FOOTER ==================== -->
<style>
  /* Self-contained: literal colors from the student-portal palette
     (css_student.css --color-*): dark #1C2628 background, beige #FEFAE0
     for headings/hover, info #CADEDE for body text, muted #9FB8B8 for
     secondary text. Fraunces (the serif used for headings across the
     public pages) is the accent face. Scoped under .site-footer so nothing
     leaks into the including page's own .wrap/.brand/h4/a/ul rules. */
  .site-footer{background:#1C2628;color:#CADEDE;font-family:'Inter',system-ui,sans-serif;}
  .site-footer .ft-wrap{max-width:1180px;margin:0 auto;padding:0 28px;}
  .site-footer .ft-main{padding:60px 0 44px;}

  .site-footer .ft-grid{display:grid;grid-template-columns:1fr;gap:36px;}
  @media(min-width:640px){.site-footer .ft-grid{grid-template-columns:1fr 1fr;}}
  @media(min-width:980px){.site-footer .ft-grid{grid-template-columns:1.6fr 1fr 1fr 1.3fr;gap:48px;}}

  .site-footer .ft-brand{display:inline-block;text-decoration:none;}
  /* logo_mini.png is already the beige wordmark on transparent */
  .site-footer .ft-wordmark{display:block;height:26px;width:auto;max-width:100%;}
  .site-footer .ft-tagline{
    margin:18px 0 0;max-width:24ch;
    font-family:'Fraunces',Georgia,serif;font-weight:560;font-size:1.2rem;line-height:1.35;
    color:#FEFAE0;
  }
  .site-footer .ft-about p.ft-address{font-size:0.86rem;line-height:1.7;margin:12px 0 0;max-width:36ch;color:#9FB8B8;}

  .site-footer .ft-social{display:flex;gap:8px;margin-top:22px;}
  .site-footer .ft-social a{
    width:34px;height:34px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    border:1px solid rgba(202,222,222,0.2);color:#CADEDE;
    transition:border-color .15s ease,color .15s ease;
  }
  .site-footer .ft-social a:hover{border-color:#FEFAE0;color:#FEFAE0;}

  .site-footer h4{
    margin:0 0 16px;color:#FEFAE0;
    font-family:'Fraunces',Georgia,serif;font-size:1rem;font-weight:560;letter-spacing:0;
  }
  .site-footer ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:11px;}
  .site-footer a{text-decoration:none;color:#CADEDE;font-size:0.88rem;transition:color .15s ease;}
  .site-footer a:hover{color:#FEFAE0;}
  .site-footer a:focus-visible{outline:2px solid #FEFAE0;outline-offset:3px;border-radius:4px;}

  .site-footer .ft-contact li{display:flex;gap:10px;align-items:flex-start;font-size:0.88rem;line-height:1.5;}
  .site-footer .ft-contact svg{flex-shrink:0;margin-top:2px;color:#9FB8B8;}

  .site-footer .ft-bottom{border-top:1px solid rgba(202,222,222,0.12);padding:20px 0;}
  .site-footer .ft-bottom .ft-wrap{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;}
  .site-footer .ft-bottom span,
  .site-footer .ft-bottom a{font-size:0.8rem;color:#9FB8B8;}
  .site-footer .ft-bottom a:hover{color:#FEFAE0;}
</style>

<footer class="site-footer">

  <div class="ft-main">
    <div class="ft-wrap">
      <div class="ft-grid">

        <!-- Brand / About -->
        <div class="ft-about">
          <a href="<?= $ft_pub ?>/index" class="ft-brand">
            <img src="<?= APP_URL ?>/assets/images/logo_mini.png" alt="Greenfield Senior High School" class="ft-wordmark">
          </a>
          <p class="ft-address">Robinsons Imus, E. Aguinaldo Highway, Tanzang Luma, Imus City, Cavite, Philippines</p>

          <div class="ft-social" aria-label="Social media">
            <a href="https://www.facebook.com/" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M14 9h3V6h-3c-1.7 0-3 1.3-3 3v2H9v3h2v6h3v-6h2.5l.5-3H14V9.5c0-.3.2-.5.5-.5z" fill="currentColor"/></svg>
            </a>
            <a href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3.5" y="3.5" width="17" height="17" rx="5" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.6"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor"/></svg>
            </a>
            <a href="https://www.youtube.com/" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2.5" y="6" width="19" height="12" rx="4" stroke="currentColor" stroke-width="1.6"/><path d="M10.5 9.5v5l4.5-2.5-4.5-2.5z" fill="currentColor"/></svg>
            </a>
          </div>
        </div>

        <!-- Explore -->
        <div>
          <h4>Explore</h4>
          <ul>
            <li><a href="<?= $ft_pub ?>/index">Home</a></li>
            <li><a href="<?= $ft_pub ?>/index#why">About Us</a></li>
            <li><a href="<?= $ft_pub ?>/index#strands">Tracks &amp; Strands</a></li>
            <li><a href="<?= $ft_pub ?>/index#journey">How to Enroll</a></li>
          </ul>
        </div>

        <!-- Admissions -->
        <div>
          <h4>Admissions</h4>
          <ul>
            <li><a href="<?= $ft_pub ?>/admission">Apply Online</a></li>
            <li><a href="<?= $ft_pub ?>/requirement_checklist">Requirements</a></li>
            <li><a href="<?= $ft_pub ?>/faq">Enrollment FAQs</a></li>
          </ul>
        </div>

        <!-- Contact -->
        <div>
          <h4>Contact</h4>
          <ul class="ft-contact">
            <li>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 5h3l2 5-2 1.5a11 11 0 0 0 5.5 5.5L14 15l5 2v3a2 2 0 0 1-2 2C9.5 22 2 14.5 2 7a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
              <a href="tel:5551234567">(555) 123-4567</a>
            </li>
            <li>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
              <a href="mailto:enroll@greenfieldshs.edu.ph">enroll@greenfieldshs.edu.ph</a>
            </li>
            <li>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              <span>Registrar's Office<br>Mon–Fri, 8:00 AM – 4:00 PM</span>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </div>

  <div class="ft-bottom">
    <div class="ft-wrap">
      <span>© <?= date('Y') ?> Greenfield Senior High School</span>
      <a href="#top">Back to top ↑</a>
    </div>
  </div>

</footer>
