<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Enrollment system - confirm modals</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#1C2321;
    --muted:#8A9995;
    --page:#EFF3F1;
    --card:#FFFFFF;
    --border:rgba(19,45,40,0.07);
    --teal:#0F6F62;
    --teal-hover:#0B5A50;
    --teal-tint:#E3F1EE;
    --coral:#D6503F;
    --coral-hover:#B93F30;
    --coral-tint:#FBEAE7;
    --gold:#DE9A2A;
    --gold-tint:#FBF0DC;
    --ring:0 20px 44px -14px rgba(19,45,40,0.16);
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    min-height:100vh;
    background:
      radial-gradient(circle at 15% 10%, rgba(15,111,98,0.06), transparent 40%),
      radial-gradient(circle at 85% 90%, rgba(222,154,42,0.07), transparent 40%),
      var(--page);
    font-family:'Inter', sans-serif;
    color:var(--ink);
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:48px 20px 72px;
  }
  .intro{
    max-width:600px;
    text-align:center;
    margin-bottom:40px;
  }
  .intro .eyebrow{
    font-size:12px;
    color:var(--teal);
    font-weight:600;
    letter-spacing:0.04em;
    text-transform:uppercase;
    margin-bottom:10px;
  }
  .intro h1{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:30px;
    line-height:1.18;
    margin:0 0 12px;
  }
  .intro p{
    color:var(--muted);
    font-size:14.5px;
    line-height:1.6;
    margin:0;
  }
  .grid{
    display:flex;
    flex-wrap:wrap;
    gap:40px;
    justify-content:center;
    align-items:flex-start;
    max-width:1160px;
  }
  .modal{
    position:relative;
    width:420px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:22px;
    box-shadow:var(--ring);
    padding:34px 38px 30px;
    text-align:center;
    display:flex;
    flex-direction:column;
    align-items:center;
    overflow:hidden;
  }
  /* soft tinted wash behind the icon so the top of the card doesn't
     read as empty white space */
  .modal::before{
    content:"";
    position:absolute;
    top:-70px;
    left:50%;
    width:220px;
    height:220px;
    transform:translateX(-50%);
    border-radius:50%;
    filter:blur(6px);
    opacity:0.55;
    pointer-events:none;
  }
  .modal.v-teal::before{ background:radial-gradient(circle, var(--teal-tint), transparent 70%); }
  .modal.v-coral::before{ background:radial-gradient(circle, var(--coral-tint), transparent 70%); }
  .modal.v-gold::before{ background:radial-gradient(circle, var(--gold-tint), transparent 70%); }

  .icon-wrap{
    position:relative;
    width:56px;
    height:56px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:16px;
  }
  .icon-wrap.teal{ background:var(--teal-tint); box-shadow:0 0 0 8px rgba(15,111,98,0.06); }
  .icon-wrap.coral{ background:var(--coral-tint); box-shadow:0 0 0 8px rgba(214,80,63,0.06); }
  .icon-wrap.gold{ background:var(--gold-tint); box-shadow:0 0 0 8px rgba(222,154,42,0.08); }

  .modal h2{
    position:relative;
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:21px;
    margin:0 0 8px;
    color:var(--ink);
  }
  .modal p.sub{
    position:relative;
    font-size:13.5px;
    line-height:1.55;
    color:var(--muted);
    margin:0 0 22px;
    font-weight:400;
    max-width:36ch;
  }
  .actions{
    position:relative;
    display:flex;
    gap:10px;
    width:100%;
  }
  .actions button{
    flex:1 1 0;
  }
  button{
    font-family:'Inter', sans-serif;
    font-size:13.5px;
    font-weight:600;
    border:none;
    border-radius:999px;
    padding:11px 20px;
    cursor:pointer;
    text-align:center;
    transition:transform .12s ease, box-shadow .12s ease, background .12s ease;
  }
  button:active{ transform:translateY(1px) scale(0.98); }

  .btn-ghost{
    background:transparent;
    color:var(--muted);
    border:1px solid var(--border);
  }
  .btn-ghost:hover{ background:#F1F3F2; color:var(--ink); border-color:transparent; }

  .btn-teal{ background:var(--teal); color:#fff; }
  .btn-teal:hover{ background:var(--teal-hover); }

  .btn-coral{ background:var(--coral); color:#fff; }
  .btn-coral:hover{ background:var(--coral-hover); }

  .btn-gold{ background:var(--gold); color:#241705; }
  .btn-gold:hover{ background:#C88720; }

  .tag{
    position:relative;
    font-size:11px;
    font-weight:600;
    letter-spacing:0.01em;
    color:var(--muted);
    margin-top:16px;
  }

  /* ---------- Outcome / result modal (not a confirm action) ---------- */
  .modal.outcome{
    background:linear-gradient(160deg, var(--teal-tint) 0%, #FFFFFF 48%, #FFFFFF 100%);
  }
  .modal.outcome::before{ content:none; }

  .icon-wrap.outcome-icon{
    width:66px;
    height:66px;
    background:#FFFFFF;
    box-shadow:0 10px 26px -8px rgba(15,111,98,0.35), 0 0 0 1px rgba(15,111,98,0.08);
    margin-bottom:18px;
  }

  .outcome-meta{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
    font-size:13.5px;
    font-weight:600;
    color:var(--ink);
    margin:0 0 6px;
  }
  .meta-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
  }
  .meta-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    flex:0 0 auto;
  }
  .meta-dot.teal{ background:var(--teal); }
  .meta-dot.gold{ background:var(--gold); }
  .meta-sep{
    color:var(--muted);
    font-weight:400;
    font-size:12.5px;
  }

  .btn-dark{ background:var(--ink); color:#fff; }
  .btn-dark:hover{ background:#0E1412; }

  svg{ display:block; }

  @media (max-width:400px){
    .modal{ width:100%; max-width:340px; }
  }

  .section-label{
    font-size:12px;
    font-weight:600;
    letter-spacing:0.04em;
    text-transform:uppercase;
    color:var(--teal);
    margin:0 0 18px;
    text-align:center;
  }
  .section-label.second{ margin-top:64px; }

  /* ---------- Toast / snackbar ---------- */
  .toast-stack{
    display:flex;
    flex-direction:column;
    gap:14px;
    width:100%;
    max-width:400px;
  }
  .toast{
    position:relative;
    display:flex;
    align-items:flex-start;
    gap:12px;
    width:100%;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    box-shadow:0 10px 28px -12px rgba(19,45,40,0.14);
    padding:16px 40px 16px 16px;
    opacity:0;
    transform:translateY(10px);
    animation:toastIn 0.45s cubic-bezier(0.16,1,0.3,1) forwards;
    overflow:hidden;
  }
  .toast:nth-child(1){ animation-delay:0.05s; }
  .toast:nth-child(2){ animation-delay:0.2s; }
  .toast:nth-child(3){ animation-delay:0.35s; }

  @keyframes toastIn{
    from{ opacity:0; transform:translateY(10px); }
    to{ opacity:1; transform:translateY(0); }
  }
  @media (prefers-reduced-motion: reduce){
    .toast{ animation:none; opacity:1; transform:none; }
  }

  /* auto-dismiss countdown, success toast only */
  .toast-progress{
    position:absolute;
    left:0;
    bottom:0;
    height:3px;
    width:100%;
    background:var(--teal);
    border-radius:0 0 16px 16px;
    transform-origin:left;
    animation:toastProgress 4s linear forwards;
    animation-delay:0.5s;
  }
  @keyframes toastProgress{
    from{ transform:scaleX(1); }
    to{ transform:scaleX(0); }
  }
  @media (prefers-reduced-motion: reduce){
    .toast-progress{ animation:none; transform:scaleX(1); }
  }

  .toast-icon{
    flex:0 0 auto;
    width:36px;
    height:36px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
  }
  .toast-icon.teal{ background:var(--teal-tint); }
  .toast-icon.coral{ background:var(--coral-tint); }
  .toast-icon.gold{ background:var(--gold-tint); }

  .toast-body{ flex:1 1 auto; min-width:0; }
  .toast-title{
    font-size:14px;
    font-weight:600;
    color:var(--ink);
    margin:0 0 3px;
  }
  .toast-sub{
    font-size:12.5px;
    line-height:1.45;
    color:var(--muted);
    margin:0;
  }
  .toast-close{
    position:absolute;
    top:12px;
    right:12px;
    width:22px;
    height:22px;
    border-radius:50%;
    border:none;
    background:transparent;
    color:var(--muted);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    padding:0;
    transition:background .12s ease, color .12s ease;
  }
  .toast-close:hover{ background:#F1F3F2; color:var(--ink); }

  /* ---------- Profile dropdown ---------- */
  .dd-mock{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    width:100%;
    max-width:260px;
  }
  .dd-trigger{
    display:flex;
    align-items:center;
    gap:10px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:999px;
    padding:6px 14px 6px 6px;
    box-shadow:0 1px 3px rgba(19,45,40,0.06);
  }
  .dd-avatar{
    width:32px;
    height:32px;
    border-radius:50%;
    background:var(--teal);
    color:#fff;
    font-size:13px;
    font-weight:600;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
  }
  .dd-trigger-text{ text-align:left; line-height:1.25; }
  .dd-trigger-name{ font-size:13px; font-weight:600; color:var(--ink); display:block; }
  .dd-trigger-role{ font-size:11.5px; color:var(--muted); display:block; }
  .dd-trigger-chevron{ color:var(--muted); flex:0 0 auto; }

  .dd-menu{
    margin-top:10px;
    width:100%;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    box-shadow:0 16px 36px -14px rgba(19,45,40,0.18);
    padding:8px;
    display:flex;
    flex-direction:column;
    gap:2px;
  }
  .dd-item{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border-radius:10px;
    font-size:13.5px;
    font-weight:500;
    color:var(--ink);
    text-decoration:none;
    transition:background .12s ease;
  }
  .dd-item:hover{ background:#F1F3F2; }
  .dd-item svg{ flex:0 0 auto; color:var(--teal); }
  .dd-item.dd-danger svg{ color:var(--coral); }
  .dd-item.dd-danger:hover{ background:var(--coral-tint); }
  .dd-item.dd-danger{ color:var(--coral); }
  .dd-divider{ height:1px; background:var(--border); margin:6px 4px; }

  /* ---------- Notification panel ---------- */
  .notif-panel{
    width:100%;
    max-width:400px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:18px;
    box-shadow:0 16px 36px -14px rgba(19,45,40,0.16);
    overflow:hidden;
  }
  .notif-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:16px 18px 12px;
  }
  .notif-head h3{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:17px;
    margin:0;
    color:var(--ink);
  }
  .notif-close{
    width:24px;
    height:24px;
    border-radius:50%;
    border:none;
    background:transparent;
    color:var(--muted);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
  }
  .notif-close:hover{ background:#F1F3F2; color:var(--ink); }

  .notif-tabs{
    display:flex;
    gap:6px;
    padding:0 14px 14px;
  }
  .notif-tab{
    flex:1;
    text-align:center;
    font-size:12.5px;
    font-weight:600;
    color:var(--muted);
    background:transparent;
    border:none;
    border-radius:999px;
    padding:7px 10px;
    cursor:pointer;
    transition:background .12s ease, color .12s ease;
  }
  .notif-tab:hover{ color:var(--ink); }
  .notif-tab.active{ background:var(--ink); color:#fff; }

  .notif-list{ display:flex; flex-direction:column; }
  .notif-item{
    position:relative;
    display:flex;
    align-items:flex-start;
    gap:12px;
    padding:12px 18px;
    border-top:1px solid var(--border);
  }
  .notif-item:first-child{ border-top:none; }
  .notif-icon{
    width:34px;
    height:34px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
  }
  .notif-icon.teal{ background:var(--teal-tint); }
  .notif-icon.coral{ background:var(--coral-tint); }
  .notif-icon.gold{ background:var(--gold-tint); }
  .notif-body{ flex:1 1 auto; min-width:0; }
  .notif-title{
    font-size:13.5px;
    font-weight:600;
    color:var(--ink);
    margin:0 0 2px;
  }
  .notif-title .unread-dot{
    display:inline-block;
    width:6px;
    height:6px;
    border-radius:50%;
    background:var(--coral);
    margin-right:6px;
    vertical-align:middle;
  }
  .notif-desc{
    font-size:12.5px;
    line-height:1.45;
    color:var(--muted);
    margin:0;
  }
  .notif-time{
    font-size:11px;
    color:var(--muted);
    flex:0 0 auto;
    padding-top:2px;
  }

  /* ---------- Status badges / pills ---------- */
  .badge-row{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    justify-content:center;
    max-width:600px;
  }
  .badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:12.5px;
    font-weight:600;
    border-radius:999px;
    padding:7px 14px;
  }
  .badge .badge-dot{
    width:7px;
    height:7px;
    border-radius:50%;
    flex:0 0 auto;
  }
  .badge.b-teal{ background:var(--teal-tint); color:var(--teal-hover); }
  .badge.b-teal .badge-dot{ background:var(--teal); }
  .badge.b-coral{ background:var(--coral-tint); color:var(--coral-hover); }
  .badge.b-coral .badge-dot{ background:var(--coral); }
  .badge.b-gold{ background:var(--gold-tint); color:#8A5D14; }
  .badge.b-gold .badge-dot{ background:var(--gold); }
  .badge.b-muted{ background:#EEF1F0; color:var(--muted); }
  .badge.b-muted .badge-dot{ background:var(--muted); }

  /* ---------- Empty state ---------- */
  .empty-state{
    width:100%;
    max-width:380px;
    text-align:center;
    padding:44px 32px;
    background:var(--card);
    border:1px dashed var(--border);
    border-radius:20px;
  }
  .empty-state .icon-wrap{ margin:0 auto 18px; }
  .empty-state h3{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:18px;
    margin:0 0 6px;
    color:var(--ink);
  }
  .empty-state p{
    font-size:13.5px;
    line-height:1.55;
    color:var(--muted);
    margin:0 0 20px;
  }
  .empty-state button{
    padding:10px 22px;
  }

  /* ---------- Loading skeleton ---------- */
  .skeleton-block{
    width:100%;
    max-width:720px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:18px;
    overflow:hidden;
  }
  .skel-row{
    display:flex;
    align-items:center;
    gap:14px;
    padding:16px 18px;
    border-bottom:1px solid var(--border);
  }
  .skel-row:last-child{ border-bottom:none; }
  .skel{
    position:relative;
    overflow:hidden;
    background:#EDF1EF;
    border-radius:8px;
  }
  .skel::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,0.75), transparent);
    transform:translateX(-100%);
    animation:skelShimmer 1.6s ease-in-out infinite;
  }
  @keyframes skelShimmer{
    100%{ transform:translateX(100%); }
  }
  @media (prefers-reduced-motion: reduce){
    .skel::after{ animation:none; display:none; }
  }
  .skel-avatar{ width:34px; height:34px; border-radius:50%; flex:0 0 auto; }
  .skel-lines{ flex:1 1 auto; display:flex; flex-direction:column; gap:8px; }
  .skel-line{ height:10px; border-radius:6px; }
  .skel-line.w-40{ width:40%; }
  .skel-line.w-60{ width:60%; }
  .skel-line.w-25{ width:25%; }
  .skel-pill{ width:70px; height:22px; border-radius:999px; flex:0 0 auto; }
</style>
</head>
<body>

  <div class="section-label">Confirm modals</div>

  <div class="grid">

    <!-- Card 1: Log out -->
    <div class="modal v-teal">
      <div class="icon-wrap teal">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M9 8V6.5A2.5 2.5 0 0 1 11.5 4H17a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5.5A2.5 2.5 0 0 1 9 17.5V16" stroke="#0F6F62" stroke-width="1.8" stroke-linecap="round"/>
          <path d="M3.5 12H14" stroke="#0F6F62" stroke-width="1.8" stroke-linecap="round"/>
          <path d="M7 8.5 3.5 12 7 15.5" stroke="#0F6F62" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h2>Log out?</h2>
      <p class="sub">You'll be signed out and returned to the login page.</p>
      <div class="actions">
        <button class="btn-ghost">Cancel</button>
        <button class="btn-teal">Yes, log out</button>
      </div>
    </div>

    <!-- Card 2: Delete / remove enrollment record -->
    <div class="modal v-coral">
      <div class="icon-wrap coral">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M12 8v5" stroke="#D6503F" stroke-width="1.8" stroke-linecap="round"/>
          <circle cx="12" cy="16.2" r="0.9" fill="#D6503F"/>
          <circle cx="12" cy="12" r="9" stroke="#D6503F" stroke-width="1.8"/>
        </svg>
      </div>
      <h2>Remove enrollment record?</h2>
      <p class="sub">This unenrolls the student and can't be undone.</p>
      <div class="actions">
        <button class="btn-ghost">Cancel</button>
        <button class="btn-coral">Remove</button>
      </div>
    </div>

    <!-- Card 3: Enrollment success - personality moment -->
    <div class="modal v-gold">
      <div class="icon-wrap gold">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M4 10.5 12 6l8 4.5-8 4.5-8-4.5Z" stroke="#DE9A2A" stroke-width="1.7" stroke-linejoin="round"/>
          <path d="M7.5 12.6V16c0 1 2 2.2 4.5 2.2s4.5-1.2 4.5-2.2v-3.4" stroke="#DE9A2A" stroke-width="1.7" stroke-linecap="round"/>
          <path d="M20 10.5V15" stroke="#DE9A2A" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
      </div>
      <h2>You're enrolled!</h2>
      <p class="sub">We've saved your spot. A confirmation is on its way to your inbox.</p>
      <div class="actions single">
        <button class="btn-gold">Great, thanks</button>
      </div>
      <div class="tag">personality variant</div>
    </div>

    <!-- Card 4: Outcome / result - not a confirm action -->
    <div class="modal outcome">
      <div class="icon-wrap outcome-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
          <path d="M4.5 12.5 9.5 17.5 19.5 7" stroke="#0F6F62" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h2>Placeholder result title</h2>
      <div class="outcome-meta">
        <span class="meta-pill"><span class="meta-dot teal"></span>Placeholder A</span>
        <span class="meta-sep">to</span>
        <span class="meta-pill"><span class="meta-dot gold"></span>Placeholder B</span>
      </div>
      <p class="sub">Secondary placeholder line describing the result in one sentence.</p>
      <div class="actions">
        <button class="btn-ghost">See details</button>
        <button class="btn-dark">Primary action</button>
      </div>
      <div class="tag">placeholder content - outcome pattern, on hold</div>
    </div>

  </div>

  <div class="section-label second">Toast &amp; snackbar</div>

  <div class="toast-stack">

    <!-- Changes saved -->
    <div class="toast">
      <div class="toast-icon teal">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M4.5 12.5 9.5 17.5 19.5 7" stroke="#0F6F62" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="toast-body">
        <p class="toast-title">Changes saved</p>
        <p class="toast-sub">Your student record updates are live.</p>
      </div>
      <button class="toast-close" aria-label="Dismiss">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
          <path d="M5 5 19 19M19 5 5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
      <div class="toast-progress"></div>
    </div>

    <!-- Payment failed -->
    <div class="toast">
      <div class="toast-icon coral">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M12 8v5" stroke="#D6503F" stroke-width="2" stroke-linecap="round"/>
          <circle cx="12" cy="16.2" r="0.9" fill="#D6503F"/>
          <circle cx="12" cy="12" r="8.5" stroke="#D6503F" stroke-width="2"/>
        </svg>
      </div>
      <div class="toast-body">
        <p class="toast-title">Payment failed</p>
        <p class="toast-sub">Card declined for invoice #F-209. Try another method.</p>
      </div>
      <button class="toast-close" aria-label="Dismiss">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
          <path d="M5 5 19 19M19 5 5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <!-- New enrollment request -->
    <div class="toast">
      <div class="toast-icon gold">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M12 5.5c-3.9 0-6.5 2.9-6.5 6.7 0 3.1-.8 4.3-1.5 5.1h16c-.7-.8-1.5-2-1.5-5.1 0-3.8-2.6-6.7-6.5-6.7Z" stroke="#DE9A2A" stroke-width="1.7" stroke-linejoin="round"/>
          <path d="M10 19.2a2 2 0 0 0 4 0" stroke="#DE9A2A" stroke-width="1.7" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="toast-body">
        <p class="toast-title">New enrollment request</p>
        <p class="toast-sub">A parent submitted a request for Grade 7 - Section A.</p>
      </div>
      <button class="toast-close" aria-label="Dismiss">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
          <path d="M5 5 19 19M19 5 5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

  </div>

  <div class="section-label second">Profile dropdown</div>

  <div class="dd-mock">
    <div class="dd-trigger">
      <span class="dd-avatar">R</span>
      <span class="dd-trigger-text">
        <span class="dd-trigger-name">Rina Alcantara</span>
        <span class="dd-trigger-role">Registrar</span>
      </span>
      <span class="dd-trigger-chevron">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
      </span>
    </div>
    <div class="dd-menu">
      <a href="#" class="dd-item">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20c1.4-3.6 4.3-5.5 7.5-5.5s6.1 1.9 7.5 5.5"/></svg>
        <span>Profile</span>
      </a>
      <a href="#" class="dd-item">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9.6a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.14.61.62 1.09 1.23 1.23H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
        <span>Settings</span>
      </a>
      <div class="dd-divider"></div>
      <a href="#" class="dd-item dd-danger">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 8V6.5A2.5 2.5 0 0 1 11.5 4H17a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5.5A2.5 2.5 0 0 1 9 17.5V16"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 12H14"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 8.5 3.5 12 7 15.5"/></svg>
        <span>Logout</span>
      </a>
    </div>
  </div>

  <div class="section-label second">Notifications</div>

  <div class="notif-panel">
    <div class="notif-head">
      <h3>Notifications</h3>
      <button type="button" class="notif-close" aria-label="Close">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 5 19 19M19 5 5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="notif-tabs">
      <button type="button" class="notif-tab active">Today</button>
      <button type="button" class="notif-tab">This week</button>
      <button type="button" class="notif-tab">Earlier</button>
    </div>
    <div class="notif-list">
      <div class="notif-item">
        <div class="notif-icon teal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4.5 12.5 9.5 17.5 19.5 7" stroke="#0F6F62" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="notif-body">
          <p class="notif-title"><span class="unread-dot"></span>Enrollment approved</p>
          <p class="notif-desc">Juan dela Cruz's enrollment for Grade 7 - Section A was approved.</p>
        </div>
        <div class="notif-time">2h ago</div>
      </div>
      <div class="notif-item">
        <div class="notif-icon gold">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5.5c-3.9 0-6.5 2.9-6.5 6.7 0 3.1-.8 4.3-1.5 5.1h16c-.7-.8-1.5-2-1.5-5.1 0-3.8-2.6-6.7-6.5-6.7Z" stroke="#DE9A2A" stroke-width="1.7" stroke-linejoin="round"/><path d="M10 19.2a2 2 0 0 0 4 0" stroke="#DE9A2A" stroke-width="1.7" stroke-linecap="round"/></svg>
        </div>
        <div class="notif-body">
          <p class="notif-title"><span class="unread-dot"></span>New enrollment request</p>
          <p class="notif-desc">A parent submitted a request for Grade 7 - Section A.</p>
        </div>
        <div class="notif-time">5h ago</div>
      </div>
      <div class="notif-item">
        <div class="notif-icon coral">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 8v5" stroke="#D6503F" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.2" r="0.9" fill="#D6503F"/><circle cx="12" cy="12" r="8.5" stroke="#D6503F" stroke-width="2"/></svg>
        </div>
        <div class="notif-body">
          <p class="notif-title">Payment failed</p>
          <p class="notif-desc">Card declined for invoice #F-209. Try another method.</p>
        </div>
        <div class="notif-time">Yesterday</div>
      </div>
    </div>
  </div>

  <div class="section-label second">Status badges &amp; pills</div>

  <div class="badge-row">
    <span class="badge b-teal"><span class="badge-dot"></span>Enrolled</span>
    <span class="badge b-teal"><span class="badge-dot"></span>Paid</span>
    <span class="badge b-gold"><span class="badge-dot"></span>Pending</span>
    <span class="badge b-gold"><span class="badge-dot"></span>Under review</span>
    <span class="badge b-coral"><span class="badge-dot"></span>Rejected</span>
    <span class="badge b-coral"><span class="badge-dot"></span>Unpaid</span>
    <span class="badge b-muted"><span class="badge-dot"></span>Draft</span>
  </div>

  <div class="section-label second">Empty state</div>

  <div class="empty-state">
    <div class="icon-wrap teal">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
        <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" stroke="#0F6F62" stroke-width="1.6" stroke-linejoin="round"/>
        <path d="M4 8.5 12 13l8-4.5M12 13v7" stroke="#0F6F62" stroke-width="1.6" stroke-linejoin="round"/>
      </svg>
    </div>
    <h3>No enrollment records yet</h3>
    <p>Records will show up here once a student submits an application for this school year.</p>
    <button class="btn-teal">Add a record</button>
  </div>

  <div class="section-label second">Loading skeleton</div>

  <div class="skeleton-block">
    <div class="skel-row">
      <div class="skel skel-avatar"></div>
      <div class="skel-lines">
        <div class="skel skel-line w-40"></div>
        <div class="skel skel-line w-25"></div>
      </div>
      <div class="skel skel-pill"></div>
    </div>
    <div class="skel-row">
      <div class="skel skel-avatar"></div>
      <div class="skel-lines">
        <div class="skel skel-line w-60"></div>
        <div class="skel skel-line w-25"></div>
      </div>
      <div class="skel skel-pill"></div>
    </div>
    <div class="skel-row">
      <div class="skel skel-avatar"></div>
      <div class="skel-lines">
        <div class="skel skel-line w-40"></div>
        <div class="skel skel-line w-25"></div>
      </div>
      <div class="skel skel-pill"></div>
    </div>
  </div>

  <script>
    (function(){
      var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      document.querySelectorAll('.notif-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
          document.querySelectorAll('.notif-tab').forEach(function(t){ t.classList.remove('active'); });
          tab.classList.add('active');
        });
      });

      function closeToast(toast){
        toast.style.transition = 'opacity .25s ease, transform .25s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-6px)';
        setTimeout(function(){ toast.remove(); }, 250);
      }

      document.querySelectorAll('.toast-progress').forEach(function(bar){
        var toast = bar.closest('.toast');
        if(!toast) return;

        if(reduced){
          setTimeout(function(){ closeToast(toast); }, 4000);
        } else {
          bar.addEventListener('animationend', function(){ closeToast(toast); });
        }

        var closeBtn = toast.querySelector('.toast-close');
        if(closeBtn){ closeBtn.addEventListener('click', function(){ closeToast(toast); }); }
      });
    })();
  </script>

</body>
</html>
