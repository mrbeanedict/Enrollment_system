<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Approvals · Coordinator</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  box-sizing:border-box;
  padding-top:env(safe-area-inset-top,0px);
  padding-bottom:env(safe-area-inset-bottom,0px);
  color-scheme:light;
  --bg:#F4F6F5; --surface:#FFFFFF; --surface-2:#F7F9F8; --line:#E2E6E4; --line-strong:#CBD3CF;
  --ink:#15211B; --muted:#58665F;
  --accent:#1F5A40; --accent-hover:#174633; --on-accent:#FFFFFF; --accent-soft:#E3EFE9;
  --ok:#17663F; --ok-bg:#E1F2E8; --warn:#864700; --warn-bg:#FCEED3; --bad:#B3261E; --bad-bg:#FCE8E6;
  --cell:#EAF1EE; --scrim:rgba(15,25,20,.38);
  --shadow:0 2px 6px rgba(15,25,20,.08),0 16px 40px rgba(15,25,20,.14);
  --r-ctl:8px; --r-card:12px;
}
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    color-scheme:dark;
    --bg:#0E1411; --surface:#151D19; --surface-2:#1A2420; --line:#25302B; --line-strong:#354239;
    --ink:#E7EEEA; --muted:#98A79F;
    --accent:#63C79F; --accent-hover:#7ED6B2; --on-accent:#0A1A12; --accent-soft:#1B2D25;
    --ok:#6FD3A3; --ok-bg:#183024; --warn:#F0B95C; --warn-bg:#332610; --bad:#FF8F86; --bad-bg:#3A1C1A;
    --cell:#1E2B25; --scrim:rgba(0,0,0,.55);
    --shadow:0 2px 6px rgba(0,0,0,.4),0 16px 40px rgba(0,0,0,.5);
  }
}
:root[data-theme="dark"]{
  color-scheme:dark;
  --bg:#0E1411; --surface:#151D19; --surface-2:#1A2420; --line:#25302B; --line-strong:#354239;
  --ink:#E7EEEA; --muted:#98A79F;
  --accent:#63C79F; --accent-hover:#7ED6B2; --on-accent:#0A1A12; --accent-soft:#1B2D25;
  --ok:#6FD3A3; --ok-bg:#183024; --warn:#F0B95C; --warn-bg:#332610; --bad:#FF8F86; --bad-bg:#3A1C1A;
  --cell:#1E2B25; --scrim:rgba(0,0,0,.55);
  --shadow:0 2px 6px rgba(0,0,0,.4),0 16px 40px rgba(0,0,0,.5);
}
html{height:100%;scroll-padding-top:env(safe-area-inset-top,0px)}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;min-height:100%;background:var(--bg);color:var(--ink);
  font:400 14px/1.5 'Instrument Sans',system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
  font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}
button,input,select,textarea{font:inherit;color:inherit}
button{cursor:pointer}
[hidden]{display:none!important}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

/* shell */
.app{display:grid;grid-template-columns:232px minmax(0,1fr);min-height:100vh}
.side{position:sticky;top:env(safe-area-inset-top,0px);align-self:start;
  height:calc(100vh - env(safe-area-inset-top,0px) - env(safe-area-inset-bottom,0px));
  display:flex;flex-direction:column;padding:20px 12px;border-right:1px solid var(--line);background:var(--surface)}
.brand{display:flex;align-items:center;gap:10px;padding:0 8px 20px;font-weight:700;font-size:15px;letter-spacing:-.01em}
.mark{width:28px;height:28px;border-radius:8px;background:var(--accent);color:var(--on-accent);display:grid;place-items:center;font-size:13px;font-weight:700}
.role{margin:0 8px 8px;color:var(--muted);font-size:12px}
.nav{display:flex;flex-direction:column;gap:2px}
.nav a{display:flex;align-items:center;gap:10px;padding:8px;border-radius:var(--r-ctl);color:var(--muted);text-decoration:none;font-weight:500}
.nav a:hover{background:var(--surface-2);color:var(--ink)}
.nav a[aria-current="page"]{background:var(--accent-soft);color:var(--accent);font-weight:600}
.nav .n{margin-left:auto;font-size:12px;font-weight:600;background:var(--accent);color:var(--on-accent);border-radius:999px;padding:0 7px;min-width:20px;text-align:center}
.me{margin-top:auto;display:flex;align-items:center;gap:10px;padding:8px}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-weight:700;font-size:12px}
.me b{display:block;font-size:13px;line-height:1.2}
.me span{font-size:12px;color:var(--muted)}

.main{min-width:0}
.top{position:sticky;top:env(safe-area-inset-top,0px);z-index:20;display:flex;align-items:center;gap:12px;height:56px;padding:0 32px;
  background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.crumb{color:var(--muted);font-size:13px}
.crumb b{color:var(--ink);font-weight:600}
.brand-m{display:none;font-weight:700}
.top .sp{flex:1}
.searchbtn{display:flex;align-items:center;gap:8px;height:34px;padding:0 10px;min-width:220px;border:1px solid var(--line-strong);border-radius:var(--r-ctl);background:var(--surface);color:var(--muted)}
.searchbtn kbd{margin-left:auto;font:500 11px inherit;border:1px solid var(--line-strong);border-radius:4px;padding:0 5px;color:var(--muted)}
.iconbtn{position:relative;width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--line-strong);border-radius:var(--r-ctl);background:var(--surface)}
.iconbtn .dot{position:absolute;top:6px;right:7px;width:8px;height:8px;border-radius:50%;background:var(--bad);border:2px solid var(--surface)}

.page{max-width:1120px;padding:32px 32px 64px}
h1{margin:0;font-size:26px;line-height:1.2;letter-spacing:-.02em;font-weight:700}
.lede{margin:4px 0 24px;color:var(--muted)}

.tabs{display:flex;gap:24px;border-bottom:1px solid var(--line);margin-bottom:20px}
.tab{background:none;border:0;padding:10px 0;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px}
.tab:hover{color:var(--ink)}
.tab[aria-selected="true"]{color:var(--ink);border-bottom-color:var(--accent)}
.tab .ct{margin-left:6px;font-weight:500;color:var(--muted)}

.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-card);overflow:hidden}
.bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--line)}
.bar-r{display:flex;gap:8px;flex-wrap:wrap}
.seg{display:inline-flex;padding:2px;background:var(--bg);border:1px solid var(--line);border-radius:10px}
.seg button{border:0;background:none;padding:5px 12px;border-radius:8px;font-weight:500;color:var(--muted)}
.seg button:hover{color:var(--ink)}
.seg button[aria-pressed="true"]{background:var(--surface);color:var(--ink);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.12)}
.seg .ct{margin-left:5px;color:var(--muted);font-weight:500}
.field{position:relative}
.field svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.input,.select{height:34px;border:1px solid var(--line-strong);border-radius:var(--r-ctl);background:var(--surface);padding:0 10px}
.input{padding-left:32px;width:240px}
.input::placeholder{color:var(--muted)}
.select{appearance:none;-webkit-appearance:none;padding-right:30px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238A9690' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 10px center}
.bulk{background:var(--accent-soft);border-bottom-color:transparent}
.bulk b{color:var(--accent)}

.btn{height:34px;padding:0 14px;border-radius:var(--r-ctl);border:1px solid var(--line-strong);background:var(--surface);font-weight:600;font-size:13px}
.btn:hover{background:var(--surface-2)}
.btn.sm{height:30px;padding:0 12px}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--on-accent)}
.btn.primary:hover{background:var(--accent-hover)}
.btn.soft{background:var(--accent-soft);border-color:transparent;color:var(--accent)}
.btn.soft:hover{filter:brightness(.97)}
.btn.bad{color:var(--bad)}
.btn.bad.fill{background:var(--bad);border-color:var(--bad);color:#fff}
.btn:disabled{opacity:.45;cursor:not-allowed}

.tablewrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:760px}
th{padding:10px 16px;text-align:left;font-size:12px;font-weight:600;color:var(--muted);border-bottom:1px solid var(--line);white-space:nowrap;background:var(--surface-2)}
th .sort{display:inline-flex;align-items:center;gap:4px;background:none;border:0;padding:0;font:inherit;color:inherit;font-weight:600}
th .sort:hover{color:var(--ink)}
th .sort svg{opacity:.35;transition:transform .15s}
th[aria-sort] .sort{color:var(--ink)}
th[aria-sort] .sort svg{opacity:1}
th[aria-sort="ascending"] .sort svg{transform:rotate(180deg)}
td{padding:12px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
tbody tr:last-child td{border-bottom:0}
tbody tr{cursor:pointer}
tbody tr:hover,tbody tr:focus-within{background:var(--surface-2)}
.c-chk{width:44px;padding-right:0}
.c-act{text-align:right;width:110px}
.chk{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
.chk:disabled{cursor:not-allowed;opacity:.4}
.rowlink{background:none;border:0;padding:0;font-weight:600;font-size:14px;text-align:left}
.rowlink:hover{color:var(--accent)}
.sub{display:block;color:var(--muted);font-size:12px}
.slots{display:flex;align-items:center;gap:10px}
.meter{width:56px;height:4px;border-radius:2px;background:var(--line)}
.meter i{display:block;height:100%;border-radius:2px;background:var(--accent)}
.meter i.short{background:var(--warn)}
.badge{display:inline-flex;align-items:center;gap:6px;height:22px;padding:0 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.badge.ok{color:var(--ok);background:var(--ok-bg)}
.badge.bad{color:var(--bad);background:var(--bad-bg)}
.badge.warn{color:var(--warn);background:var(--warn-bg)}

.empty{padding:56px 16px;text-align:center}
.empty h3{margin:0 0 4px;font-size:16px}
.empty p{margin:0 auto 16px;color:var(--muted);max-width:360px}

/* drawer */
.scrim{position:fixed;inset:0;background:var(--scrim);z-index:40;opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s}
.scrim.on{opacity:1;visibility:visible}
.drawer{position:fixed;top:0;right:0;bottom:0;z-index:50;width:min(520px,100%);background:var(--surface);border-left:1px solid var(--line);box-shadow:var(--shadow);
  display:flex;flex-direction:column;transform:translateX(100%);visibility:hidden;transition:transform .22s ease,visibility .22s;
  padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
.drawer.on{transform:none;visibility:visible}
.d-head{display:flex;align-items:flex-start;gap:12px;padding:20px 24px 16px;border-bottom:1px solid var(--line)}
.d-head h2{margin:0;font-size:20px;letter-spacing:-.01em;line-height:1.25}
.d-head p{margin:2px 0 0;color:var(--muted)}
.d-head .iconbtn{margin-left:auto;flex:none}
.d-body{flex:1;overflow-y:auto;padding:20px 24px}
.d-body h3{margin:24px 0 10px;font-size:14px}
.d-body h3:first-child{margin-top:0}
dl{margin:0;display:grid;grid-template-columns:130px 1fr;gap:8px 16px}
dt{color:var(--muted)}
dd{margin:0}
.issues{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.issues li{padding:10px 12px;border-radius:var(--r-ctl);background:var(--bad-bg);color:var(--ink);border:1px solid color-mix(in srgb,var(--bad) 30%,transparent)}
.issues li b{color:var(--bad);display:block;font-size:13px}
.clear{padding:10px 12px;border-radius:var(--r-ctl);background:var(--ok-bg);color:var(--ok);font-weight:600}
.wk{display:grid;grid-template-columns:38px repeat(5,minmax(0,1fr));gap:4px;font-size:11px}
.wk .h{color:var(--muted);text-align:center;font-weight:600;padding-bottom:2px}
.wk .t{color:var(--muted);align-self:center;text-align:right;padding-right:4px;white-space:nowrap}
.cell{height:32px;border-radius:6px;background:var(--cell);display:grid;place-items:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;padding:0 4px;font-weight:500}
.cell.empty{background:transparent;border:1px dashed var(--line)}
.cell.conflict{background:var(--bad-bg);color:var(--bad);outline:2px solid var(--bad);outline-offset:-2px;font-weight:700}
.legend{display:flex;gap:16px;margin-top:10px;color:var(--muted);font-size:12px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px;background:var(--cell)}
.legend i.c{background:var(--bad-bg);outline:2px solid var(--bad);outline-offset:-2px}
.d-foot{display:flex;gap:8px;justify-content:flex-end;align-items:center;padding:14px 24px;border-top:1px solid var(--line);background:var(--surface)}
.d-foot .note{margin-right:auto;color:var(--muted);font-size:13px}
.rej{width:100%}
.rej label{display:block;font-weight:600;margin-bottom:6px}
.rej textarea{width:100%;min-height:76px;resize:vertical;border:1px solid var(--line-strong);border-radius:var(--r-ctl);background:var(--surface);padding:8px 10px}
.rej .row{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}

/* popover + palette */
.pop{position:absolute;right:0;top:42px;width:340px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-card);box-shadow:var(--shadow);z-index:30;overflow:hidden;text-align:left}
.pop h3{margin:0;padding:12px 16px;font-size:14px;border-bottom:1px solid var(--line)}
.pop button.item{display:block;width:100%;text-align:left;background:none;border:0;padding:12px 16px;border-bottom:1px solid var(--line)}
.pop button.item:last-child{border-bottom:0}
.pop button.item:hover{background:var(--surface-2)}
.pop .item span{display:block;color:var(--muted);font-size:12px}
.wrap-rel{position:relative}
.modal{position:fixed;inset:0;z-index:60;display:flex;justify-content:center;align-items:flex-start;padding:14vh 16px 16px;background:var(--scrim)}
.pal{width:min(560px,100%);background:var(--surface);border:1px solid var(--line);border-radius:var(--r-card);box-shadow:var(--shadow);overflow:hidden}
.pal input{width:100%;height:48px;border:0;border-bottom:1px solid var(--line);padding:0 16px;background:transparent;font-size:15px;outline:none}
.pal ul{list-style:none;margin:0;padding:6px;max-height:320px;overflow-y:auto}
.pal li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 10px;border-radius:8px;cursor:pointer}
.pal li[aria-selected="true"]{background:var(--accent-soft);color:var(--accent)}
.pal li small{color:var(--muted)}
.pal .none{padding:16px;color:var(--muted);cursor:default}

.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:70;display:flex;align-items:center;gap:16px;
  background:var(--ink);color:var(--bg);padding:10px 12px 10px 16px;border-radius:10px;box-shadow:var(--shadow);max-width:calc(100% - 32px)}
.toast button{background:none;border:0;color:inherit;font-weight:700;text-decoration:underline;padding:4px 6px}

@media (max-width:860px){
  .app{grid-template-columns:minmax(0,1fr)}
  .side{display:none}
  .brand-m{display:block}
  .crumb{display:none}
  .top{padding:0 16px}
  .page{padding:24px 16px 56px}
  .searchbtn{min-width:0}
  .searchbtn span,.searchbtn kbd{display:none}
  .searchbtn{width:34px;padding:0;justify-content:center}
  .input{width:100%}
  .field{flex:1 1 100%}
  .bar-r{width:100%}
  .bar-r .select{flex:1}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<div class="app">
  <aside class="side" aria-label="Main navigation">
    <div class="brand"><span class="mark">S</span>SHS Portal</div>
    <p class="role">Coordinator</p>
    <nav class="nav">
      <a href="#" onclick="return false"><span data-ic="home"></span>Dashboard</a>
      <a href="#" aria-current="page" onclick="return false"><span data-ic="check"></span>Approvals <span class="n" id="navCount">0</span></a>
      <a href="#" onclick="return false"><span data-ic="users"></span>Sections</a>
      <a href="#" onclick="return false"><span data-ic="book"></span>Curriculum</a>
      <a href="#" onclick="return false"><span data-ic="cal"></span>Schedules</a>
    </nav>
    <div class="me"><span class="avatar">AR</span><div><b>Ana Reyes</b><span>Coordinator</span></div></div>
  </aside>

  <div class="main">
    <header class="top">
      <span class="brand-m">SHS Portal</span>
      <span class="crumb">Coordinator / <b>Approvals</b></span>
      <span class="sp"></span>
      <button class="searchbtn" id="palBtn" aria-label="Search"><span data-ic="search" data-s="16"></span><span>Search</span><kbd>Ctrl K</kbd></button>
      <div class="wrap-rel">
        <button class="iconbtn" id="notifBtn" aria-label="Notifications" aria-expanded="false"><span data-ic="bell" data-s="16"></span><span class="dot"></span></button>
        <div class="pop" id="notif" hidden></div>
      </div>
    </header>

    <main class="page">
      <h1>Approvals</h1>
      <p class="lede">Requests from schedulers waiting on your decision.</p>

      <div class="tabs" role="tablist" id="tabs"></div>

      <section class="card" id="schedPanel">
        <div class="bar" id="toolbar">
          <div class="seg" id="seg" role="group" aria-label="Filter by status"></div>
          <div class="bar-r">
            <div class="field">
              <span data-ic="search" data-s="16"></span>
              <input class="input" id="q" type="search" placeholder="Search section or scheduler" aria-label="Search requests">
            </div>
            <select class="select" id="grade" aria-label="Grade level">
              <option value="all">All grades</option><option value="11">Grade 11</option><option value="12">Grade 12</option>
            </select>
            <select class="select" id="strand" aria-label="Strand">
              <option value="all">All strands</option><option>ABM</option><option>HUMSS</option><option>STEM</option><option>TVL</option>
            </select>
          </div>
        </div>
        <div class="bar bulk" id="bulk" hidden>
          <span><b id="bulkN">0</b> selected</span>
          <div class="bar-r">
            <button class="btn sm" id="bulkClear">Clear</button>
            <button class="btn sm primary" id="bulkApprove">Approve selected</button>
          </div>
        </div>
        <div class="tablewrap"><table><thead id="thead"></thead><tbody id="tbody"></tbody></table></div>
        <div class="empty" id="empty" hidden></div>
      </section>

      <section class="card" id="otherPanel" hidden>
        <div class="empty">
          <h3 id="otherTitle"></h3>
          <p>This tab isn't part of the prototype. Schedules is the page being redesigned.</p>
          <button class="btn primary" id="backSched">Go to schedule requests</button>
        </div>
      </section>
    </main>
  </div>
</div>

<div class="scrim" id="scrim"></div>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-labelledby="dTitle" aria-hidden="true">
  <div class="d-head">
    <div><h2 id="dTitle"></h2><p id="dSub"></p></div>
    <button class="iconbtn" id="dClose" aria-label="Close"><span data-ic="x" data-s="16"></span></button>
  </div>
  <div class="d-body" id="dBody"></div>
  <div class="d-foot" id="dFoot"></div>
</aside>

<div class="modal" id="pal" hidden>
  <div class="pal" role="dialog" aria-modal="true" aria-label="Search">
    <input id="palQ" type="text" placeholder="Search sections, schedulers, strands" autocomplete="off" aria-label="Search">
    <ul id="palList" role="listbox"></ul>
  </div>
</div>
<div class="toast" id="toast" role="status" hidden></div>

<script>
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const I={
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  bell:'<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 20a2 2 0 0 0 3.4 0"/>',
  home:'<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/>',
  check:'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  users:'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.3c2.2.7 3.5 2.6 3.5 5.7"/>',
  book:'<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
  cal:'<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
  x:'<path d="M6 6l12 12M18 6 6 18"/>',
  down:'<path d="M12 5v14M6 13l6 6 6-6"/>'
};
const ic=(n,s=18)=>`<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[n]}</svg>`;
document.querySelectorAll('[data-ic]').forEach(e=>e.innerHTML=ic(e.dataset.ic,+(e.dataset.s||18)));

const SUBJ={
  STEM:['GenMath','Physics','Chem','PreCal','OralCom','PE','Filipino','Research'],
  ABM:['BusMath','FABM','Econ','OralCom','PE','Filipino','Org&Mgt','Research'],
  TVL:['Shop','ICT','Trade','PE','Filipino','OralCom','Work Imm.','Research'],
  HUMSS:['Creative','Disciplines','Politics','PE','Filipino','OralCom','Trends','Research']
};
const ROWS=[
  {id:1,section:'ABM 11-3',grade:11,strand:'ABM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:28,total:30,age:42,status:'pending',
    issues:[{title:'2 required subjects have no slot',text:'Business Math and PE still need a time. Add them or approve as incomplete.'}]},
  {id:2,section:'STEM 11-Flex',grade:11,strand:'STEM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:34,total:35,age:190,status:'pending',
    issues:[{title:'Room conflict, Tue 9 AM',text:'Room 204 is also booked by STEM 11-1.',cell:[1,2]}]},
  {id:3,section:'TVL 12-2',grade:12,strand:'TVL',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:30,total:30,age:300,status:'pending',issues:[]},
  {id:4,section:'STEM 11-2',grade:11,strand:'STEM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:35,total:35,age:1500,status:'pending',
    issues:[{title:'Teacher conflict, Thu 8 AM',text:'Mr. Dizon is scheduled for two sections at the same time.',cell:[3,1]}]},
  {id:5,section:'STEM 11-1',grade:11,strand:'STEM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:35,total:35,age:2900,status:'pending',issues:[]},
  {id:6,section:'ABM 12-1',grade:12,strand:'ABM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:32,total:32,age:4400,status:'approved',issues:[]},
  {id:7,section:'HUMSS 11-1',grade:11,strand:'HUMSS',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:30,total:30,age:5800,status:'approved',issues:[]},
  {id:8,section:'STEM 12-3',grade:12,strand:'STEM',by:'Lino Gonzales',staff:6,sy:'2026–2027',slots:29,total:35,age:7200,status:'rejected',
    reason:'Six subjects are missing from the schedule. Please complete it and resubmit.',issues:[]}
];
const state={tab:'schedule',status:'pending',q:'',grade:'all',strand:'all',sort:{key:'age',dir:-1},sel:new Set(),openId:null};
const DAYS=['Mon','Tue','Wed','Thu','Fri'];
const TIMES=['7 AM','8 AM','9 AM','10 AM','11 AM','1 PM','2 PM','3 PM'];
const age=m=>m<60?m+' min ago':m<1440?Math.round(m/60)+' hr ago':(Math.round(m/1440)===1?'Yesterday':Math.round(m/1440)+' days ago');
const byId=id=>ROWS.find(r=>r.id===id);
const counts=()=>{const c={pending:0,approved:0,rejected:0,all:ROWS.length};ROWS.forEach(r=>c[r.status]++);return c;};
const eligible=r=>r.status==='pending'&&!r.issues.length;

function visible(){
  const q=state.q.trim().toLowerCase();
  const list=ROWS.filter(r=>(state.status==='all'||r.status===state.status)
    &&(state.grade==='all'||String(r.grade)===state.grade)
    &&(state.strand==='all'||r.strand===state.strand)
    &&(!q||(r.section+' '+r.by+' '+r.strand).toLowerCase().includes(q)));
  const {key,dir}=state.sort;
  list.sort((a,b)=>(key==='section'?a.section.localeCompare(b.section,undefined,{numeric:true}):a.age-b.age)*dir);
  return list;
}

function renderTabs(){
  const c=counts();
  const tabs=[['sections','Sections',3],['curriculum','Curriculum',2],['schedule','Schedule',c.pending]];
  $('#tabs').innerHTML=tabs.map(([k,l,n])=>`<button class="tab" role="tab" data-tab="${k}" aria-selected="${state.tab===k}">${l}<span class="ct">${n}</span></button>`).join('');
  $('#navCount').textContent=3+2+c.pending;
  $('#schedPanel').hidden=state.tab!=='schedule';
  $('#otherPanel').hidden=state.tab==='schedule';
  $('#otherTitle').textContent=state.tab==='sections'?'Section requests':'Curriculum requests';
}
function renderSeg(){
  const c=counts();
  $('#seg').innerHTML=[['pending','Pending'],['approved','Approved'],['rejected','Rejected'],['all','All']]
    .map(([k,l])=>`<button data-status="${k}" aria-pressed="${state.status===k}">${l}<span class="ct">${c[k]}</span></button>`).join('');
}
function renderTable(){
  const list=visible();
  const showStatus=state.status!=='pending';
  const s=state.sort;
  const sortTh=(k,label)=>{
    const on=s.key===k;
    const aria=on?(k==='age'?(s.dir===-1?'ascending':'descending'):(s.dir===1?'ascending':'descending')):null;
    return `<th ${aria?`aria-sort="${aria}"`:''}><button class="sort" data-sort="${k}">${label}${ic('down',12)}</button></th>`;
  };
  const allSel=list.filter(eligible);
  const checked=allSel.length&&allSel.every(r=>state.sel.has(r.id));
  $('#thead').innerHTML=`<tr>
    <th class="c-chk"><input class="chk" type="checkbox" id="selAll" aria-label="Select all ready requests" ${checked?'checked':''} ${allSel.length?'':'disabled'}></th>
    ${sortTh('section','Section')}
    <th>Slots scheduled</th>
    <th>${showStatus?'Status':'Checks'}</th>
    ${sortTh('age','Submitted')}
    <th class="c-act"><span class="sr">Action</span></th></tr>`;
  const tb=$('#tbody');
  tb.innerHTML=list.map(r=>{
    const pct=Math.round(r.slots/r.total*100);
    let badge;
    if(r.status==='approved')badge='<span class="badge ok">Approved</span>';
    else if(r.status==='rejected')badge='<span class="badge bad">Rejected</span>';
    else badge=r.issues.length?`<span class="badge bad">${r.issues.length} ${r.issues.length>1?'issues':'issue'}</span>`:'<span class="badge ok">Ready</span>';
    if(showStatus&&r.status==='pending')badge='<span class="badge warn">Pending</span>';
    const act=r.status!=='pending'?'<button class="btn sm" data-act="view">View</button>'
      :eligible(r)?'<button class="btn sm soft" data-act="approve">Approve</button>'
      :'<button class="btn sm" data-act="view">Review</button>';
    return `<tr data-id="${r.id}">
      <td class="c-chk"><input class="chk" type="checkbox" aria-label="Select ${esc(r.section)}" ${state.sel.has(r.id)?'checked':''} ${eligible(r)?'':'disabled'} ${eligible(r)?'':'title="Resolve issues before bulk approval"'}></td>
      <td><button class="rowlink">${esc(r.section)}</button><span class="sub">Grade ${r.grade} · ${esc(r.sy)}</span></td>
      <td><div class="slots"><span>${r.slots} of ${r.total}</span><span class="meter" aria-hidden="true"><i class="${r.slots<r.total?'short':''}" style="width:${pct}%"></i></span></div></td>
      <td>${badge}</td>
      <td>${age(r.age)}</td>
      <td class="c-act">${act}</td></tr>`;
  }).join('');
  const e=$('#empty');
  if(!list.length){
    e.hidden=false;
    const filtered=state.q||state.grade!=='all'||state.strand!=='all';
    if(filtered)e.innerHTML='<h3>No requests match</h3><p>Try a different search or clear the filters.</p><button class="btn" id="clearF">Clear filters</button>';
    else if(state.status==='pending')e.innerHTML='<h3>Nothing waiting on you</h3><p>Every schedule request has been decided.</p><button class="btn" id="seeAll">View decided requests</button>';
    else e.innerHTML='<h3>No requests here yet</h3><p>Requests will appear once schedulers submit them.</p>';
  }else e.hidden=true;
  $('#toolbar').hidden=state.sel.size>0;
  $('#bulk').hidden=state.sel.size===0;
  $('#bulkN').textContent=state.sel.size;
}
function render(){renderTabs();renderSeg();renderTable();renderNotif();}

/* decisions */
let toastTimer;
function toast(msg,undo){
  const t=$('#toast');
  t.innerHTML=`<span>${esc(msg)}</span>${undo?'<button>Undo</button>':''}`;
  t.hidden=false;
  if(undo)t.querySelector('button').onclick=()=>{undo();t.hidden=true;};
  clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.hidden=true,6000);
}
function decide(ids,status,reason){
  const prev=ids.map(id=>[id,byId(id).status,byId(id).reason]);
  ids.forEach(id=>{const r=byId(id);r.status=status;r.reason=reason;state.sel.delete(id);});
  const msg=ids.length===1?`${byId(ids[0]).section} ${status}.`:`${ids.length} requests ${status}.`;
  closeDrawer();render();
  toast(msg,()=>{prev.forEach(([id,s,rs])=>{const r=byId(id);r.status=s;r.reason=rs;});render();});
}

/* drawer */
let lastFocus=null;
function gridHTML(r){
  const subs=SUBJ[r.strand];
  let h='<div class="wk" role="img" aria-label="Weekly schedule preview for '+esc(r.section)+'"><span></span>'+DAYS.map(d=>`<span class="h">${d}</span>`).join('');
  for(let p=0;p<8;p++){
    h+=`<span class="t">${TIMES[p]}</span>`;
    for(let d=0;d<5;d++){
      const idx=p*5+d;
      if(idx>=r.slots){h+='<span class="cell empty"></span>';continue;}
      const conf=r.issues.find(i=>i.cell&&i.cell[0]===d&&i.cell[1]===p);
      h+=`<span class="cell ${conf?'conflict':''}" ${conf?`title="${esc(conf.title)}"`:''}>${esc(subs[(d*2+p*3+r.id)%subs.length])}</span>`;
    }
  }
  return h+'</div><div class="legend"><span><i></i>Scheduled</span><span><i class="c"></i>Conflict</span></div>';
}
function openDrawer(id,from){
  const r=byId(id);state.openId=id;lastFocus=from||document.activeElement;
  $('#dTitle').textContent=r.section;
  $('#dSub').textContent=`Grade ${r.grade} · ${r.strand} · ${r.sy}`;
  const issues=r.issues.length
    ?`<ul class="issues">${r.issues.map(i=>`<li><b>${esc(i.title)}</b>${esc(i.text)}</li>`).join('')}</ul>`
    :'<div class="clear">No conflicts found. All required subjects have a slot.</div>';
  $('#dBody').innerHTML=`
    <h3>Summary</h3>
    <dl><dt>Requested by</dt><dd>${esc(r.by)} <span style="color:var(--muted)">(Staff ID ${r.staff})</span></dd>
    <dt>Submitted</dt><dd>${age(r.age)}</dd>
    <dt>Slots scheduled</dt><dd>${r.slots} of ${r.total} required</dd>
    <dt>Status</dt><dd>${r.status==='pending'?'<span class="badge warn">Pending</span>':r.status==='approved'?'<span class="badge ok">Approved</span>':'<span class="badge bad">Rejected</span>'}</dd></dl>
    ${r.status==='rejected'&&r.reason?`<h3>Reason for rejection</h3><p style="margin:0">${esc(r.reason)}</p>`:''}
    <h3>Checks</h3>${issues}
    <h3>Weekly preview</h3>${gridHTML(r)}`;
  footNormal(r);
  $('#drawer').classList.add('on');$('#drawer').setAttribute('aria-hidden','false');$('#scrim').classList.add('on');
  setTimeout(()=>$('#dClose').focus(),30);
}
function footNormal(r){
  const f=$('#dFoot');
  if(r.status!=='pending'){f.innerHTML='<span class="note">This request has been decided.</span><button class="btn" id="dDone">Close</button>';$('#dDone').onclick=closeDrawer;return;}
  f.innerHTML=`<button class="btn bad" id="dRej">Reject</button><button class="btn primary" id="dApp">${r.issues.length?'Approve anyway':'Approve'}</button>`;
  $('#dApp').onclick=()=>decide([r.id],'approved');
  $('#dRej').onclick=()=>{
    f.innerHTML=`<div class="rej"><label for="rejWhy">Reason for rejection</label>
      <textarea id="rejWhy" placeholder="Tell ${esc(r.by.split(' ')[0])} what to fix before resubmitting"></textarea>
      <div class="row"><button class="btn" id="rejNo">Cancel</button><button class="btn bad fill" id="rejGo" disabled>Reject request</button></div></div>`;
    const ta=$('#rejWhy'),go=$('#rejGo');ta.focus();
    ta.oninput=()=>go.disabled=!ta.value.trim();
    $('#rejNo').onclick=()=>footNormal(r);
    go.onclick=()=>decide([r.id],'rejected',ta.value.trim());
  };
}
function closeDrawer(){
  if(state.openId===null)return;
  state.openId=null;
  $('#drawer').classList.remove('on');$('#drawer').setAttribute('aria-hidden','true');$('#scrim').classList.remove('on');
  if(lastFocus&&document.contains(lastFocus))lastFocus.focus();
}
function openRow(id){
  const r=byId(id);
  state.tab='schedule';state.status=r.status;state.q='';state.grade='all';state.strand='all';
  $('#q').value='';$('#grade').value='all';$('#strand').value='all';
  render();openDrawer(id,null);
}

/* notifications */
function renderNotif(){
  const items=ROWS.filter(r=>r.status==='pending').sort((a,b)=>a.age-b.age).slice(0,4);
  $('#notif').innerHTML='<h3>Notifications</h3>'+(items.length
    ?items.map(r=>`<button class="item" data-open="${r.id}">${esc(r.section)} schedule submitted<span>${esc(r.by)} · ${age(r.age)}</span></button>`).join('')
    :'<div style="padding:16px;color:var(--muted)">You\'re all caught up.</div>');
}
function toggleNotif(force){
  const on=force!==undefined?force:$('#notif').hidden;
  $('#notif').hidden=!on;$('#notifBtn').setAttribute('aria-expanded',on);
}

/* command palette */
let palIdx=0,palItems=[];
function palRender(){
  const q=$('#palQ').value.trim().toLowerCase();
  palItems=ROWS.filter(r=>!q||(r.section+' '+r.strand+' '+r.by+' '+r.status).toLowerCase().includes(q));
  palIdx=Math.min(palIdx,Math.max(palItems.length-1,0));
  $('#palList').innerHTML=palItems.length
    ?palItems.map((r,i)=>`<li role="option" data-i="${i}" aria-selected="${i===palIdx}"><span>${esc(r.section)}</span><small>Schedule · ${r.status}</small></li>`).join('')
    :'<li class="none">No matches</li>';
}
function palOpen(){$('#pal').hidden=false;$('#palQ').value='';palIdx=0;palRender();$('#palQ').focus();toggleNotif(false);}
function palClose(){$('#pal').hidden=true;}
function palGo(i){const r=palItems[i];if(!r)return;palClose();openRow(r.id);}

/* events */
$('#tabs').addEventListener('click',e=>{const b=e.target.closest('[data-tab]');if(b){state.tab=b.dataset.tab;renderTabs();}});
$('#backSched').onclick=()=>{state.tab='schedule';renderTabs();};
$('#seg').addEventListener('click',e=>{const b=e.target.closest('[data-status]');if(b){state.status=b.dataset.status;state.sel.clear();render();}});
$('#q').addEventListener('input',e=>{state.q=e.target.value;renderTable();});
$('#grade').addEventListener('change',e=>{state.grade=e.target.value;renderTable();});
$('#strand').addEventListener('change',e=>{state.strand=e.target.value;renderTable();});
$('#thead').addEventListener('click',e=>{
  const b=e.target.closest('[data-sort]');
  if(b){const k=b.dataset.sort;if(state.sort.key===k)state.sort.dir*=-1;else state.sort={key:k,dir:k==='age'?-1:1};renderTable();}
});
$('#thead').addEventListener('change',e=>{
  if(e.target.id==='selAll'){
    const list=visible().filter(eligible);
    if(e.target.checked)list.forEach(r=>state.sel.add(r.id));else list.forEach(r=>state.sel.delete(r.id));
    renderTable();
  }
});
$('#tbody').addEventListener('change',e=>{
  const tr=e.target.closest('tr');if(!tr||!e.target.classList.contains('chk'))return;
  const id=+tr.dataset.id;e.target.checked?state.sel.add(id):state.sel.delete(id);renderTable();
});
$('#tbody').addEventListener('click',e=>{
  const tr=e.target.closest('tr[data-id]');if(!tr||e.target.classList.contains('chk'))return;
  const id=+tr.dataset.id;
  const act=e.target.closest('[data-act]');
  if(act&&act.dataset.act==='approve'){decide([id],'approved');return;}
  openDrawer(id,tr.querySelector('.rowlink'));
});
$('#empty').addEventListener('click',e=>{
  if(e.target.id==='clearF'){state.q='';state.grade='all';state.strand='all';$('#q').value='';$('#grade').value='all';$('#strand').value='all';renderTable();}
  if(e.target.id==='seeAll'){state.status='all';render();}
});
$('#bulkClear').onclick=()=>{state.sel.clear();renderTable();};
$('#bulkApprove').onclick=()=>decide([...state.sel],'approved');
$('#dClose').onclick=closeDrawer;
$('#scrim').onclick=closeDrawer;
$('#notifBtn').onclick=e=>{e.stopPropagation();toggleNotif();};
$('#notif').addEventListener('click',e=>{const b=e.target.closest('[data-open]');if(b){toggleNotif(false);openRow(+b.dataset.open);}});
document.addEventListener('click',e=>{if(!e.target.closest('.wrap-rel'))toggleNotif(false);});
$('#palBtn').onclick=palOpen;
$('#pal').addEventListener('click',e=>{if(e.target.id==='pal')palClose();const li=e.target.closest('li[data-i]');if(li)palGo(+li.dataset.i);});
$('#palQ').addEventListener('input',()=>{palIdx=0;palRender();});
$('#palQ').addEventListener('keydown',e=>{
  if(e.key==='ArrowDown'){e.preventDefault();palIdx=Math.min(palIdx+1,palItems.length-1);palRender();}
  else if(e.key==='ArrowUp'){e.preventDefault();palIdx=Math.max(palIdx-1,0);palRender();}
  else if(e.key==='Enter'){e.preventDefault();palGo(palIdx);}
});
document.addEventListener('keydown',e=>{
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();$('#pal').hidden?palOpen():palClose();return;}
  if(e.key==='Escape'){
    if(!$('#pal').hidden)palClose();
    else if(!$('#notif').hidden)toggleNotif(false);
    else closeDrawer();
  }
});
if(/Mac|iPhone|iPad/.test(navigator.platform||''))document.querySelector('.searchbtn kbd').textContent='⌘K';
render();
</script>
</body>
</html>