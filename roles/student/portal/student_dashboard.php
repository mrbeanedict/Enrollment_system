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
    <title>Dashboard — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!require_login('student', null, 'ignore', 'bool')): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access the dashboard.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/student_sidebar.php';

    $dash_hour = (int) date('G');
    $greeting  = $dash_hour < 12 ? 'Good morning' : ($dash_hour < 18 ? 'Good afternoon' : 'Good evening');

    $student_id = (int) $_SESSION['student_id'];

    // Most recent enrollment record on file for this student (not necessarily
    // the active/current school year — see the stat card label below).
    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.control_number, e.section_id, st.strand_code AS strand, e.admission_grade_level AS grade_level,
               e.school_year, e.status, e.total_due
        FROM enrollments e
        JOIN strands st ON st.strand_id = e.admission_strand
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $section_name    = null;
    $balance         = null;
    $today_classes   = [];
    $week_class_days = [];

    if ($enrollment) {

        if ($enrollment['section_id']) {
            $sec_stmt = mysqli_prepare($conn, "SELECT section_name FROM sections WHERE section_id = ?");
            mysqli_stmt_bind_param($sec_stmt, "i", $enrollment['section_id']);
            mysqli_stmt_execute($sec_stmt);
            mysqli_stmt_bind_result($sec_stmt, $section_name);
            mysqli_stmt_fetch($sec_stmt);
            mysqli_stmt_close($sec_stmt);

            // Today's classes only — section_subjects.day is stored as Mon/Tue/.../Fri,
            // which matches PHP's date('D') output directly.
            $today_stmt = mysqli_prepare($conn, "
                SELECT sub.subject_name, ss.start_time, ss.end_time, ss.room, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
                FROM section_subjects ss
                JOIN subjects sub ON sub.subject_id = ss.subject_id
                LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
                WHERE ss.section_id = ? AND ss.day = ?
                ORDER BY ss.start_time
            ");
            $today_day = date('D');
            mysqli_stmt_bind_param($today_stmt, "is", $enrollment['section_id'], $today_day);
            mysqli_stmt_execute($today_stmt);
            $today_classes = mysqli_fetch_all(mysqli_stmt_get_result($today_stmt), MYSQLI_ASSOC);
            mysqli_stmt_close($today_stmt);

            // Which weekdays this section has any class at all — drives the
            // small dot under each day in the week strip below.
            $days_stmt = mysqli_prepare($conn, "SELECT DISTINCT day FROM section_subjects WHERE section_id = ?");
            mysqli_stmt_bind_param($days_stmt, "i", $enrollment['section_id']);
            mysqli_stmt_execute($days_stmt);
            $days_result = mysqli_stmt_get_result($days_stmt);
            while ($row = mysqli_fetch_assoc($days_result)) {
                $week_class_days[] = $row['day'];
            }
            mysqli_stmt_close($days_stmt);
        }

        $bal_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE enrollment_id = ?");
        mysqli_stmt_bind_param($bal_stmt, "i", $enrollment['enrollment_id']);
        mysqli_stmt_execute($bal_stmt);
        mysqli_stmt_bind_result($bal_stmt, $total_paid);
        mysqli_stmt_fetch($bal_stmt);
        mysqli_stmt_close($bal_stmt);

        if ($enrollment['total_due'] !== null) {
            $balance = (float) $enrollment['total_due'] - (float) $total_paid;
        }
    }

    $announcements = mysqli_fetch_all(
        mysqli_query($conn, "SELECT title, body, category, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 2"),
        MYSQLI_ASSOC
    );

    // This week strip — Sunday through Saturday of the current week.
    $week_dow       = (int) date('w'); // 0 (Sun) .. 6 (Sat)
    $week_start_ts  = strtotime('today') - ($week_dow * 86400);
    $week_labels    = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
    $week_today_ts  = strtotime('today');

    // Most recent posted "event" announcement — surfaced below the week strip.
    // (Announcements only carry a post date, not a separate event date, so we
    // show the latest one as a heads-up rather than pinning it to a specific day.)
    $week_event = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT title, created_at FROM announcements
        WHERE is_active = 1 AND category = 'event'
        ORDER BY created_at DESC LIMIT 1
    "));

    $ico_calendar = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
    $ico_cap      = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg>';
    $ico_building = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l8-4v18M13 21V11l6 3v7M9 9v.01M9 12v.01M9 15v.01"/></svg>';
    $ico_check    = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    $ico_announce = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>';
    $ico_schedule = $ico_calendar;
    $ico_profile  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
    $ico_chevron  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          <?= $greeting ?>, <?= htmlspecialchars($sb_given_name ?: ($_SESSION['username'] ?? 'there')) ?>!
          <span class="student-topbar-subtitle"><?= date('l, F j, Y') ?></span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <div class="dashboard-hero">
        <div class="dashboard-hero-content">
          <h2>Empowering You for a Brighter Tomorrow</h2>
          <p>Stay informed. Stay prepared. Succeed.</p>
        </div>
      </div>

      <?php if (!$enrollment): ?>
        <div class="notice notice-info">No enrollment record found yet.</div>
      <?php else: ?>

        <div class="student-stat-grid">
          <div class="student-stat-card tone-info">
            <div class="student-stat-body">
              <div class="student-stat-label">Last Enrolled S.Y.</div>
              <div class="student-stat-value"><?= htmlspecialchars($enrollment['school_year']) ?></div>
            </div>
            <span class="student-stat-icon"><?= $ico_calendar ?></span>
          </div>
          <div class="student-stat-card tone-accent">
            <div class="student-stat-body">
              <div class="student-stat-label">Grade &amp; Strand</div>
              <div class="student-stat-value">G<?= htmlspecialchars($enrollment['grade_level']) ?> · <?= htmlspecialchars($enrollment['strand']) ?></div>
            </div>
            <span class="student-stat-icon"><?= $ico_cap ?></span>
          </div>
          <div class="student-stat-card tone-dark">
            <div class="student-stat-body">
              <div class="student-stat-label">Section</div>
              <div class="student-stat-value"><?= $section_name ? htmlspecialchars($section_name) : '—' ?></div>
            </div>
            <span class="student-stat-icon"><?= $ico_building ?></span>
          </div>
          <div class="student-stat-card tone-primary">
            <div class="student-stat-body">
              <div class="student-stat-label">Status</div>
              <div class="student-stat-value"><span class="badge badge-<?= htmlspecialchars($enrollment['status']) ?>"><?= htmlspecialchars(ucfirst($enrollment['status'])) ?></span></div>
              <?php if ($enrollment['status'] === 'enrolled'): ?>
                <a class="stat-cor-link" href="student_cor?enrollment_id=<?= (int) $enrollment['enrollment_id'] ?>">View COR →</a>
              <?php endif; ?>
            </div>
            <span class="student-stat-icon"><?= $ico_check ?></span>
          </div>
        </div>

 

      <?php endif; ?>

      <div class="student-dashboard-grid">

        <!-- LEFT COLUMN -->
        <div class="student-dashboard-col">

          <?php if ($enrollment): ?>

            <div class="student-panel-block">
              <div class="student-panel-header">
                <div class="student-panel-header-left">
                  <div class="student-panel-title">Quick Access</div>
                </div>
              </div>
              <div class="student-quick-links">
                <a class="student-quick-link" href="student_schedule">
                  <span class="student-quick-link-icon"><?= $ico_schedule ?></span>
                  <span class="student-quick-link-body">
                    <span class="student-quick-link-title">My Schedule</span>
                    <span class="student-quick-link-sub">See your subjects, days, and rooms</span>
                  </span>
                  <span class="student-quick-link-chevron"><?= $ico_chevron ?></span>
                </a>
                <a class="student-quick-link" href="student_profile">
                  <span class="student-quick-link-icon"><?= $ico_profile ?></span>
                  <span class="student-quick-link-body">
                    <span class="student-quick-link-title">My Profile</span>
                    <span class="student-quick-link-sub">Manage contact info and password</span>
                  </span>
                  <span class="student-quick-link-chevron"><?= $ico_chevron ?></span>
                </a>
              </div>
            </div>

            <div class="student-panel-block">
              <div class="student-panel-header">
                <div class="student-panel-header-left">
                  <span class="student-panel-icon"><?= $ico_calendar ?></span>
                  <div>
                    <div class="student-panel-title">Today's Schedule</div>
                    <div class="student-panel-sub"><?= date('l, F j') ?></div>
                  </div>
                </div>
                <a class="student-panel-action" href="student_schedule">View Full Week <span class="panel-action-arrow">→</span></a>
              </div>
              <?php if (empty($today_classes)): ?>
                <p class="empty-state">No classes scheduled today.</p>
              <?php else: ?>
                <div class="student-timeline">
                  <?php foreach ($today_classes as $i => $c): ?>
                    <div class="student-timeline-item">
                      <div class="student-timeline-time"><?= htmlspecialchars(date('g:i A', strtotime($c['start_time']))) ?></div>
                      <div class="student-timeline-dot-col">
                        <div class="student-timeline-dot"></div>
                        <?php if ($i < count($today_classes) - 1): ?><div class="student-timeline-line"></div><?php endif; ?>
                      </div>
                      <div class="student-timeline-body">
                        <strong><?= htmlspecialchars($c['subject_name']) ?></strong>
                        <span>Room <?= htmlspecialchars($c['room'] ?? '—') ?> · <?= htmlspecialchars($c['teacher_name'] ?? '—') ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

          <?php endif; ?>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="student-dashboard-col">

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <div class="student-panel-title">This week</div>
              </div>
              <span class="student-panel-action"><?= date('M Y', $week_today_ts) ?></span>
            </div>
            <div class="student-week-strip">
              <?php for ($i = 0; $i < 7; $i++): $day_ts = $week_start_ts + ($i * 86400); $has_class = in_array(date('D', $day_ts), $week_class_days, true); ?>
                <div class="student-week-day<?= $day_ts === $week_today_ts ? ' today' : '' ?><?= $has_class ? ' has-class' : '' ?>">
                  <span><?= $week_labels[$i] ?></span>
                  <span class="d-num"><?= date('j', $day_ts) ?></span>
                  <span class="week-day-dot"></span>
                </div>
              <?php endfor; ?>
            </div>
            <?php if ($week_event): ?>
              <div class="student-quick-links" style="margin-top:0;">
                <div class="student-quick-link" style="cursor:default;">
                  <span class="student-quick-link-icon"><?= $ico_calendar ?></span>
                  <span class="student-quick-link-body">
                    <span class="student-quick-link-title"><?= htmlspecialchars($week_event['title']) ?></span>
                    <span class="student-quick-link-sub">Posted <?= htmlspecialchars(date('M j, Y', strtotime($week_event['created_at']))) ?></span>
                  </span>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <div class="student-panel-block panel-dark">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><?= $ico_announce ?></span>
                <div class="student-panel-title">Announcements</div>
              </div>
              <a class="student-panel-action" href="student_announcements">View All <span class="panel-action-arrow">→</span></a>
            </div>
            <?php if (empty($announcements)): ?>
              <p class="empty-state">No announcements right now.</p>
            <?php else: ?>
              <?php foreach ($announcements as $a): ?>
                <div class="student-announcement-row">
                  <?php if ($a['category']): ?><span class="student-announcement-tag"><?= htmlspecialchars(ucfirst($a['category'])) ?></span><?php endif; ?>
                  <div class="student-announcement-title"><?= htmlspecialchars($a['title']) ?></div>
                  <div class="student-announcement-body"><?= nl2br(htmlspecialchars($a['body'])) ?></div>
                  <div class="student-announcement-time"><?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))) ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div>

      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
