<?php
// Distinct cookie name keeps the student session independent from admin/staff (see student_login.php).
session_name('STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/student_sidebar.php';

    $announcements = mysqli_fetch_all(
        mysqli_query($conn, "SELECT title, body, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC"),
        MYSQLI_ASSOC
    );
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Announcements
          <span class="student-topbar-subtitle">Updates from the school</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg></span>
            <div class="student-panel-title">All Announcements</div>
          </div>
        </div>
        <?php if (empty($announcements)): ?>
          <p class="empty-state">No announcements right now.</p>
        <?php else: ?>
          <?php foreach ($announcements as $a): ?>
            <div class="student-announcement-row">
              <div class="student-announcement-title"><?= htmlspecialchars($a['title']) ?></div>
              <div class="student-announcement-body"><?= nl2br(htmlspecialchars($a['body'])) ?></div>
              <div class="student-announcement-time"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($a['created_at']))) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
