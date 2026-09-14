<?php
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    $ann_stmt = mysqli_prepare($conn, "
        SELECT title, body, category, created_at
        FROM announcements
        WHERE is_active = 1
        ORDER BY created_at DESC
    ");
    mysqli_stmt_execute($ann_stmt);
    $announcements = mysqli_fetch_all(mysqli_stmt_get_result($ann_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($ann_stmt);
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Announcements
          <span class="teacher-topbar-subtitle">Latest updates from the school</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">
      <div class="teacher-panel-block">
        <?php if (empty($announcements)): ?>
          <p class="empty-state">No announcements posted yet.</p>
        <?php else: ?>
          <?php foreach ($announcements as $a): ?>
            <div class="teacher-announcement-row">
              <div class="teacher-announcement-header">
                <span class="teacher-announcement-title"><?= htmlspecialchars($a['title']) ?></span>
                <?php if ($a['category']): ?>
                  <span class="badge badge-<?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars(ucfirst($a['category'])) ?></span>
                <?php endif; ?>
              </div>
              <p class="teacher-announcement-body"><?= nl2br(htmlspecialchars($a['body'])) ?></p>
              <span class="teacher-announcement-time"><?= htmlspecialchars(date('F j, Y g:i A', strtotime($a['created_at']))) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
