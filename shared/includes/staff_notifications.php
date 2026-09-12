<?php
// Requires: $conn open, session active (include after config.php / near the
// top of the HTML body, inside .staff-topbar). Self-contained: queries its
// own data, prints its own markup/style/script.
//
// Included from roles/staff/dashboard.php and from department pages under
// roles/staff/{coordinator,records,registrar,scheduler,treasury}/ — each at
// a different folder depth, so every link uses the absolute APP_URL prefix
// (see staff_sidebar.php) rather than a depth-counted relative path. All
// notification `link` values in the DB must be written root-relative (no
// leading "../") so prepending APP_URL resolves correctly no matter where
// they're rendered.

$sn_user_id = (int)($_SESSION['user_id'] ?? 0);

$sn_notifs = [];
$sn_unread = 0;
$sn_stmt = $conn->prepare(
    "SELECT notification_id, message, link, is_read, created_at
     FROM notifications WHERE user_id = ? AND recipient_type = 'staff' ORDER BY created_at DESC LIMIT 10"
);
$sn_stmt->bind_param('i', $sn_user_id);
$sn_stmt->execute();
$sn_notifs = $sn_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sn_stmt->close();

$sn_uc = mysqli_prepare($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND recipient_type = 'staff' AND is_read = 0");
mysqli_stmt_bind_param($sn_uc, 'i', $sn_user_id);
mysqli_stmt_execute($sn_uc);
mysqli_stmt_bind_result($sn_uc, $sn_unread);
mysqli_stmt_fetch($sn_uc);
mysqli_stmt_close($sn_uc);

function sn_time_ago($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>
<div class="staff-notif-wrap">
  <button class="staff-notif-bell" id="staffNotifBell" type="button" aria-label="Notifications">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
    </svg>
    <?php if ($sn_unread > 0): ?><span class="staff-notif-badge"><?= $sn_unread > 9 ? '9+' : $sn_unread ?></span><?php endif; ?>
  </button>
  <div class="staff-notif-dropdown" id="staffNotifDropdown">
    <div class="staff-notif-header">Notifications</div>
    <?php if (!$sn_notifs): ?>
      <div class="staff-notif-empty">No notifications yet.</div>
    <?php else: ?>
      <?php foreach ($sn_notifs as $sn_n): ?>
        <a href="<?= APP_URL . '/' . htmlspecialchars($sn_n['link']) ?>" class="staff-notif-item<?= $sn_n['is_read'] ? '' : ' unread' ?>">
          <div class="staff-notif-msg"><?= htmlspecialchars($sn_n['message']) ?></div>
          <div class="staff-notif-time"><?= sn_time_ago($sn_n['created_at']) ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<style>
  .staff-notif-wrap { position:relative; }
  .staff-notif-bell {
    position:relative; width:34px; height:34px; border-radius:8px; border:0.5px solid #D4D4E0;
    background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer;
    color:#5A5A72; transition:all .12s;
  }
  .staff-notif-bell:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
  .staff-notif-badge {
    position:absolute; top:-5px; right:-5px; background:#C0392B; color:#fff; font-size:10px;
    font-weight:600; border-radius:20px; min-width:16px; height:16px; padding:0 4px;
    display:flex; align-items:center; justify-content:center; line-height:1;
  }
  .staff-notif-dropdown {
    display:none; position:absolute; right:0; top:calc(100% + 8px); width:320px; max-height:380px;
    overflow-y:auto; background:#fff; border:0.5px solid #D4D4E0; border-radius:10px;
    box-shadow:0 8px 24px rgba(20,20,40,.12); z-index:50;
  }
  .staff-notif-dropdown.open { display:block; }
  .staff-notif-header {
    font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.05em;
    color:#8A8A9A; padding:12px 14px; border-bottom:0.5px solid #EBEBF0;
  }
  .staff-notif-empty { padding:24px 14px; text-align:center; font-size:12px; color:#8A8A9A; }
  .staff-notif-item {
    display:block; padding:10px 14px; text-decoration:none; border-bottom:0.5px solid #F5F5F7;
  }
  .staff-notif-item:last-child { border-bottom:none; }
  .staff-notif-item:hover { background:#FAFAFC; }
  .staff-notif-item.unread { background:#F3F8F6; }
  .staff-notif-msg { font-size:12.5px; color:#1A1A2E; line-height:1.4; }
  .staff-notif-time { font-size:11px; color:#8A8A9A; margin-top:2px; }
</style>

<script>
(function () {
  var bell = document.getElementById('staffNotifBell');
  var dropdown = document.getElementById('staffNotifDropdown');
  if (!bell || !dropdown) return;

  var marked = false;
  bell.addEventListener('click', function (e) {
    e.stopPropagation();
    dropdown.classList.toggle('open');
    if (dropdown.classList.contains('open') && !marked) {
      marked = true;
      fetch('<?= APP_URL ?>/ajax/mark_notifications_read', { method: 'POST' });
      var badge = bell.querySelector('.staff-notif-badge');
      if (badge) badge.remove();
    }
  });

  document.addEventListener('click', function (e) {
    if (!dropdown.contains(e.target) && e.target !== bell) dropdown.classList.remove('open');
  });
})();
</script>
