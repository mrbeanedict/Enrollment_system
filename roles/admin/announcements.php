<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

$success = '';
$error   = '';

// ── Add announcement ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_announcement'])) {
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $category = $_POST['category'] ?? '';
    $category = in_array($category, ['event', 'reminder'], true) ? $category : null;

    if ($title === '' || $body === '') {
        $error = 'Title and message are required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO announcements (title, body, category, posted_by) VALUES (?, ?, ?, ?)");
        $uid  = (int) $_SESSION['user_id'];
        $stmt->bind_param('sssi', $title, $body, $category, $uid);
        $stmt->execute();
        $stmt->close();
        header("Location: announcements?saved=1"); exit();
    }
}

// ── Toggle active/hidden ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $id     = (int) ($_POST['announcement_id'] ?? 0);
    $active = (int) ($_POST['is_active'] ?? 0);
    $new    = $active ? 0 : 1;
    $stmt   = $conn->prepare("UPDATE announcements SET is_active = ? WHERE announcement_id = ?");
    $stmt->bind_param('ii', $new, $id);
    $stmt->execute();
    header("Location: announcements"); exit();
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $id   = (int) ($_POST['announcement_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header("Location: announcements?deleted=1"); exit();
}

if (isset($_GET['saved']))   { $success = 'Announcement posted.'; }
if (isset($_GET['deleted'])) { $success = 'Announcement deleted.'; }

$announcements = $conn->query("
    SELECT a.announcement_id, a.title, a.body, a.category, a.created_at, a.is_active, u.username AS posted_by_name
    FROM announcements a
    JOIN users u ON u.user_id = a.posted_by
    ORDER BY a.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcements — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
  <style>
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error   { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }
    .announce-form { margin-bottom:1.5rem; }
    .announce-form label { display:block; font-size:11px; font-weight:500; text-transform:uppercase; letter-spacing:.05em; color:#5A5A72; margin-bottom:.35rem; }
    .announce-form input, .announce-form textarea, .announce-form select {
      width:100%; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
      padding:10px 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; margin-bottom:1rem;
    }
    .announce-form input:focus, .announce-form textarea:focus, .announce-form select:focus { border-color:#386641; box-shadow:0 0 0 3px rgba(56,102,65,.14); background:#fff; }
    .announce-form textarea { min-height:90px; resize:vertical; }
    .announce-tag { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
      padding:2px 8px; border-radius:20px; margin-bottom:.35rem; }
    .announce-tag.event    { background:#FEFAE0; color:#8A6D1F; }
    .announce-tag.reminder { background:#CADEDE; color:#1E3A3A; }
    .btn-add-inline { height:38px; padding:0 16px; background:#386641; border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-add-inline:hover { background:#2F5636; }
    .announce-row { padding:1rem 0; border-bottom:0.5px solid #F0F0F5; display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; }
    .announce-row:last-child { border-bottom:none; }
    .announce-row.inactive { opacity:0.5; }
    .announce-title { font-size:13px; font-weight:600; color:#1A1A2E; margin-bottom:.25rem; }
    .announce-body { font-size:13px; color:#5A5A72; line-height:1.5; white-space:pre-wrap; margin-bottom:.4rem; }
    .announce-meta { font-size:11px; color:#8A8A9A; }
    .announce-actions { display:flex; gap:6px; flex-shrink:0; }
    .btn-sm { height:28px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px; background:#fff;
      font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; color:#5A5A72; }
    .btn-sm:hover { background:#F5F5F7; }
    .btn-sm.danger:hover { background:rgba(192,57,43,0.1); border-color:rgba(192,57,43,0.3); color:#C0392B; }
  </style>
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Announcements</span></p>
        <h1 class="page-title">Announcements</h1>
        <p class="page-sub">Published announcements appear on every student's dashboard.</p>
      </div>

      <div class="staff-overlap">

      <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Post a New Announcement</span>
        </div>
        <form method="POST" action="announcements" class="announce-form">
          <label for="title">Title</label>
          <input id="title" type="text" name="title" placeholder="e.g. Enrollment deadline extended" required>

          <label for="body">Message</label>
          <textarea id="body" name="body" placeholder="Write the announcement..." required></textarea>

          <label for="category">Category</label>
          <select id="category" name="category">
            <option value="">None</option>
            <option value="event">Event</option>
            <option value="reminder">Reminder</option>
          </select>

          <button type="submit" name="add_announcement" class="btn-add-inline">Post Announcement</button>
        </form>
      </div>

      <div class="card" style="margin-top:1rem;">
        <div class="card-header">
          <span class="card-title">All Announcements</span>
        </div>

        <?php if (empty($announcements)): ?>
          <p class="empty-state">No announcements posted yet.</p>
        <?php else: ?>
          <?php foreach ($announcements as $a): ?>
            <div class="announce-row<?= $a['is_active'] ? '' : ' inactive' ?>">
              <div style="min-width:0;">
                <?php if ($a['category']): ?><span class="announce-tag <?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars(ucfirst($a['category'])) ?></span><br><?php endif; ?>
                <div class="announce-title"><?= htmlspecialchars($a['title']) ?></div>
                <div class="announce-body"><?= htmlspecialchars($a['body']) ?></div>
                <div class="announce-meta">
                  Posted by <?= htmlspecialchars($a['posted_by_name']) ?> on <?= date('M j, Y g:i A', strtotime($a['created_at'])) ?>
                  <?= $a['is_active'] ? '' : ' — hidden' ?>
                </div>
              </div>
              <div class="announce-actions">
                <form method="POST" action="announcements">
                  <input type="hidden" name="announcement_id" value="<?= (int) $a['announcement_id'] ?>">
                  <input type="hidden" name="is_active" value="<?= (int) $a['is_active'] ?>">
                  <button type="submit" name="toggle_active" class="btn-sm"><?= $a['is_active'] ? 'Hide' : 'Unhide' ?></button>
                </form>
                <form method="POST" action="announcements" data-confirm="Delete this announcement? This can't be undone." data-icon="warning">
                  <input type="hidden" name="announcement_id" value="<?= (int) $a['announcement_id'] ?>">
                  <button type="submit" name="delete_announcement" class="btn-sm danger">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

</body>
</html>
