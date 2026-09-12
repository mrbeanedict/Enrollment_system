<!-- ==================== FOOTER (kept from the original page) ==================== -->
<style>
  /* Self-contained: uses literal brand colors rather than the host page's
     --color-*/--dark-style tokens, so this include renders identically no
     matter which student/ page pulls it in or what its own <style> defines.
     Selectors are scoped under `footer`/`.footer-*` so nothing here leaks
     into the including page's own .wrap/.brand/h4/a/ul rules. */
  footer{background:#1C2628;padding:0;}
  footer .wrap{max-width:1180px;margin:0 auto;padding:0 28px;}
  .footer-main{padding:56px 0 40px;}

  .footer-grid{display:grid;grid-template-columns:1fr;gap:32px;}
  @media(min-width:720px){.footer-grid{grid-template-columns:1.3fr 1fr 1fr;gap:56px;}}

  footer .brand{display:flex;align-items:center;gap:10px;text-decoration:none;}
  .footer-about .brand{color:#FEFAE0;font-weight:600;font-size:1.05rem;}
  .footer-about p{color:rgba(159,184,184,0.75);font-size:0.88rem;line-height:1.65;margin-top:14px;max-width:32ch;}

  .footer-social{display:flex;gap:10px;margin-top:18px;}
  .footer-social a{
    width:32px;height:32px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,0.06);
    color:#CADEDE;
    transition:background 0.15s ease, color 0.15s ease;
  }
  .footer-social a:hover{background:#386641;color:#fff;}

  footer h4{color:#FEFAE0;font-size:0.82rem;letter-spacing:0.04em;text-transform:uppercase;margin-bottom:16px;font-weight:600;}
  footer ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;}
  footer a{text-decoration:none;color:#9FB8B8;font-size:0.88rem;transition:color 0.15s ease;}
  footer a:hover{color:#CADEDE;}

  .footer-contact-item{display:flex;gap:10px;align-items:flex-start;color:#9FB8B8;font-size:0.88rem;}
  .footer-contact-item svg{flex-shrink:0;margin-top:2px;color:#9FB8B8;}
  .footer-contact-item a{color:#9FB8B8;}
  .footer-contact-item a:hover{color:#CADEDE;}

  .footer-bottom{background:#FEFAE0;padding:16px 0;}
  .footer-bottom .wrap{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;}
  .footer-bottom span{color:#1C2628;font-size:0.78rem;font-weight:600;}
</style>

<footer>

  <div class="footer-main">
    <div class="wrap">

      <div class="footer-grid">

        <!-- Brand / About -->
        <div class="footer-about">

          <a href="index" class="brand">
            <svg width="28" height="28" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="17" cy="17" r="17" fill="#FEFAE0"/>
              <path d="M9 21.5V14L17 10L25 14V21.5" stroke="#1C2628" stroke-width="1.8" stroke-linejoin="round"/>
              <path d="M13 21.5V17H21V21.5" stroke="#1C2628" stroke-width="1.8" stroke-linejoin="round"/>
            </svg>
            Greenfield Senior High School
          </a>

          <p>Robinsons mus, E. Aguinaldo Highway, Tanzang Luma, Imus City, Cavite, Philippines</p>

          <div class="footer-social" aria-label="Social media">
            <a href="#" aria-label="Facebook">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <path d="M14 9h3V6h-3c-1.7 0-3 1.3-3 3v2H9v3h2v6h3v-6h2.5l.5-3H14V9.5c0-.3.2-.5.5-.5z" fill="currentColor"/>
              </svg>
            </a>
            <a href="#" aria-label="Instagram">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <rect x="3.5" y="3.5" width="17" height="17" rx="5" stroke="currentColor" stroke-width="1.6"/>
                <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.6"/>
                <circle cx="17.2" cy="6.8" r="1" fill="currentColor"/>
              </svg>
            </a>
            <a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" aria-label="YouTube">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <rect x="2.5" y="6" width="19" height="12" rx="4" stroke="currentColor" stroke-width="1.6"/>
                <path d="M10.5 9.5v5l4.5-2.5-4.5-2.5z" fill="currentColor"/>
              </svg>
            </a>
          </div>

        </div>

        <!-- Admissions -->
        <div>
          <h4>Admissions</h4>
          <ul>
            <li><a href="application_form.php">Application Form</a></li>
            <li><a href="requirement_checklist.php">Requirements Checklist</a></li>
            <li><a href="faq.php">Enrollment FAQs</a></li>
          </ul>
        </div>

        <!-- Contact -->
        <div>
          <h4>Contact Info</h4>
          <ul>

            <li class="footer-contact-item">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <path d="M4 5h3l2 5-2 1.5a11 11 0 0 0 5.5 5.5L14 15l5 2v3a2 2 0 0 1-2 2C9.5 22 2 14.5 2 7a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
              </svg>
              <a href="tel:5551234567">(555) 123-4567</a>
            </li>

            <li class="footer-contact-item">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="5" width="18" height="14" rx="2.5" stroke="currentColor" stroke-width="1.5"/>
                <path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
              </svg>
              <a href="mailto:enroll@greenfieldshs.edu.ph">enroll@greenfieldshs.edu.ph</a>
            </li>

            <li class="footer-contact-item">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="11" r="3" stroke="currentColor" stroke-width="1.5"/>
                <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" stroke="currentColor" stroke-width="1.5"/>
              </svg>
              <span>Registrar's Office · Mon–Fri, 8am–4pm</span>
            </li>

          </ul>
        </div>

      </div>

    </div>
  </div>

  <div class="footer-bottom">
    <div class="wrap">
      <span>© 2026 Greenfield Senior High School</span>

    </div>
  </div>

</footer>