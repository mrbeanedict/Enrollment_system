<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';

// Redirect non-teacher roles (e.g. staff/admin) before any HTML output —
// doing this only inside teacher_sidebar.php's own guard is too late here,
// since this page already prints <head> before including it, and a
// header() redirect after output has started fails silently (with a
// "headers already sent" warning) instead of actually redirecting.
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../teacherportal/teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../css/css_teacher.css') ?>">
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access the dashboard.</p>

<?php else: ?>
  <?php
    include_once 'teacher_sidebar.php';

    $teacher_id = (int) $_SESSION['teacher_id'];

    // Every active subject/section assignment for this teacher, with the
    // matching section_subjects schedule row (day/time/room) when one has
    // been set. A teacher can be assigned a subject before the section's
    // schedule is finalized, so the schedule columns may come back NULL.
    $asn_stmt = mysqli_prepare($conn, "
        SELECT sec.section_id, sec.section_name, sec.grade_level, st.strand_code AS strand, sec.room AS homeroom,
               sub.subject_id, sub.subject_name, ta.semester,
               ss.day, ss.start_time, ss.end_time, ss.room AS room_override,
               ta.school_year
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
        JOIN strands st ON st.strand_id = sec.strand
        LEFT JOIN section_subjects ss
               ON ss.section_id = ta.section_id
              AND ss.subject_id = ta.subject_id
              AND ss.teacher_id = ta.teacher_id
              AND ss.semester = ta.semester
        WHERE ta.teacher_id = ? AND ta.is_active = 1
        ORDER BY sec.grade_level, sec.section_name, sub.subject_name
    ");
    mysqli_stmt_bind_param($asn_stmt, "i", $teacher_id);
    mysqli_stmt_execute($asn_stmt);
    $assignments = mysqli_fetch_all(mysqli_stmt_get_result($asn_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($asn_stmt);

    // Group assignments by section so each class card can list its subjects
    // and a single student headcount (not duplicated per subject).
    $classes = [];
    $subject_ids = [];
    $school_year = null;

    foreach ($assignments as $row) {
        $sid = $row['section_id'];
        if (!isset($classes[$sid])) {
            $classes[$sid] = [
                'section_name' => $row['section_name'],
                'grade_level'  => $row['grade_level'],
                'strand'       => $row['strand'],
                'homeroom'     => $row['homeroom'],
                'subjects'     => [],
                'student_count'=> 0,
            ];
        }
        $classes[$sid]['subjects'][] = [
            'name'     => $row['subject_name'],
            'semester' => (int)$row['semester'],
            'day'      => $row['day'],
            'start'    => $row['start_time'],
            'end'      => $row['end_time'],
            'room'     => $row['room_override'] ?: $row['homeroom'],
        ];
        $subject_ids[$row['subject_id']] = true;
        $school_year = $row['school_year'];
    }

    // Enrolled headcount per section (once per section, not per subject).
    $total_students = 0;
    foreach ($classes as $sid => &$cls) {
        $cnt_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'enrolled'");
        mysqli_stmt_bind_param($cnt_stmt, "i", $sid);
        mysqli_stmt_execute($cnt_stmt);
        mysqli_stmt_bind_result($cnt_stmt, $cnt);
        mysqli_stmt_fetch($cnt_stmt);
        mysqli_stmt_close($cnt_stmt);
        $cls['student_count'] = (int) $cnt;
        $total_students += (int) $cnt;
    }
    unset($cls);

    $total_classes  = count($classes);
    $total_subjects = count($subject_ids);

    $announcements = mysqli_fetch_all(
        mysqli_query($conn, "SELECT title, body, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 2"),
        MYSQLI_ASSOC
    );

    // Used by the Quick Access chevrons below — was previously defined in
    // teacher_sidebar.php, but that copy was removed along with the sidebar
    // collapse-toggle button it was only otherwise used for.
    $ico_chevron = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';

    // Today's classes — derived from $assignments (already fetched above),
    // no extra query needed. section_subjects.day is stored as Mon/Tue/.../Fri,
    // matching PHP's date('D') output directly.
    $today_day     = date('D');
    $today_classes = array_values(array_filter($assignments, fn($r) => $r['day'] === $today_day && $r['start_time']));
    usort($today_classes, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));

    // Which weekdays this teacher has any class at all — drives the small
    // dot under each day in the week strip.
    $week_class_days = array_values(array_unique(array_filter(array_column($assignments, 'day'))));

    // This week strip — Sunday through Saturday of the current week.
    $week_dow      = (int) date('w'); // 0 (Sun) .. 6 (Sat)
    $week_start_ts = strtotime('today') - ($week_dow * 86400);
    $week_labels   = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
    $week_today_ts = strtotime('today');
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Dashboard
          <span class="teacher-topbar-subtitle">Your teaching overview</span>
        </div>
      </div>
      <?php include 'teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <div class="dashboard-hero">
        <div class="dashboard-hero-content">
          <h2>Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'there') ?>!</h2>
          <p>Here's your teaching overview for today.</p>
        </div>
      </div>

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <div class="teacher-panel-block">
          <div class="teacher-panel-header">
            <div class="teacher-panel-header-left">
              <span class="teacher-panel-icon"><?= $ico_class ?></span>
              <div>
                <div class="teacher-panel-title">My Teaching Load</div>
                <div class="teacher-panel-sub"><?= htmlspecialchars($school_year ?? '') ?> school year</div>
              </div>
            </div>
            <a class="teacher-panel-action" href="teacher_classes">View Details</a>
          </div>
          <div class="teacher-stat-grid">
            <div class="teacher-stat-card tone-info">
              <span class="teacher-stat-icon"><?= $ico_cap ?></span>
              <div class="teacher-stat-body">
                <div class="teacher-stat-label">My Classes</div>
                <div class="teacher-stat-value"><?= $total_classes ?></div>
              </div>
            </div>
            <div class="teacher-stat-card tone-accent">
              <span class="teacher-stat-icon"><?= $ico_book ?></span>
              <div class="teacher-stat-body">
                <div class="teacher-stat-label">Subjects Taught</div>
                <div class="teacher-stat-value"><?= $total_subjects ?></div>
              </div>
            </div>
            <div class="teacher-stat-card tone-dark">
              <span class="teacher-stat-icon"><?= $ico_users ?></span>
              <div class="teacher-stat-body">
                <div class="teacher-stat-label">Total Students</div>
                <div class="teacher-stat-value"><?= $total_students ?></div>
              </div>
            </div>
            <div class="teacher-stat-card tone-primary">
              <span class="teacher-stat-icon"><?= $ico_calendar ?></span>
              <div class="teacher-stat-body">
                <div class="teacher-stat-label">School Year</div>
                <div class="teacher-stat-value"><?= htmlspecialchars($school_year ?? '—') ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="teacher-section-label">My Classes</div>
        <div class="teacher-quick-links">
          <?php foreach ($classes as $cls): ?>
            <a class="teacher-quick-link" href="teacher_classes">
              <span class="teacher-quick-link-icon"><?= $ico_cap ?></span>
              <span class="teacher-quick-link-body">
                <span class="teacher-quick-link-title">
                  <?= htmlspecialchars($cls['section_name']) ?>
                  <span class="field-hint">— G<?= htmlspecialchars($cls['grade_level']) ?> <?= htmlspecialchars($cls['strand']) ?></span>
                </span>
                <span class="teacher-quick-link-sub">
                  <?= count($cls['subjects']) ?> subject<?= count($cls['subjects']) === 1 ? '' : 's' ?>
                  &middot; <?= $cls['student_count'] ?> student<?= $cls['student_count'] === 1 ? '' : 's' ?>
                  &middot; <?= htmlspecialchars(implode(', ', array_column($cls['subjects'], 'name'))) ?>
                </span>
              </span>
              <span class="teacher-quick-link-chevron"><?= $ico_chevron ?></span>
            </a>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>

      <div class="teacher-dashboard-grid">

        <!-- LEFT COLUMN -->
        <div class="teacher-dashboard-col">

          <div class="teacher-panel-block">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <div class="teacher-panel-title">Quick Access</div>
              </div>
            </div>
            <div class="teacher-quick-links">
              <a class="teacher-quick-link" href="teacher_schedule">
                <span class="teacher-quick-link-icon"><?= $ico_schedule ?></span>
                <span class="teacher-quick-link-body">
                  <span class="teacher-quick-link-title">My Schedule</span>
                  <span class="teacher-quick-link-sub">See your subjects, days, and rooms</span>
                </span>
                <span class="teacher-quick-link-chevron"><?= $ico_chevron ?></span>
              </a>
              <a class="teacher-quick-link" href="teacher_profile">
                <span class="teacher-quick-link-icon"><?= $ico_profile ?></span>
                <span class="teacher-quick-link-body">
                  <span class="teacher-quick-link-title">My Profile</span>
                  <span class="teacher-quick-link-sub">Manage contact info and password</span>
                </span>
                <span class="teacher-quick-link-chevron"><?= $ico_chevron ?></span>
              </a>
            </div>
          </div>

          <div class="teacher-panel-block">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <span class="teacher-panel-icon"><?= $ico_calendar ?></span>
                <div>
                  <div class="teacher-panel-title">Today's Classes</div>
                  <div class="teacher-panel-sub"><?= date('l, F j') ?></div>
                </div>
              </div>
              <a class="teacher-panel-action" href="teacher_schedule">View Full Week</a>
            </div>
            <?php if (empty($today_classes)): ?>
              <p class="empty-state">No classes scheduled today.</p>
            <?php else: ?>
              <div class="teacher-timeline">
                <?php foreach ($today_classes as $i => $c): ?>
                  <div class="teacher-timeline-item">
                    <div class="teacher-timeline-time"><?= htmlspecialchars(date('g:i A', strtotime($c['start_time']))) ?></div>
                    <div class="teacher-timeline-dot-col">
                      <div class="teacher-timeline-dot"></div>
                      <?php if ($i < count($today_classes) - 1): ?><div class="teacher-timeline-line"></div><?php endif; ?>
                    </div>
                    <div class="teacher-timeline-body">
                      <strong><?= htmlspecialchars($c['subject_name']) ?></strong>
                      <span><?= htmlspecialchars($c['section_name']) ?> &middot; Room <?= htmlspecialchars($c['room_override'] ?: $c['homeroom'] ?: '—') ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="teacher-dashboard-col">

          <div class="teacher-panel-block">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <div class="teacher-panel-title">This week</div>
              </div>
              <span class="teacher-panel-action"><?= date('M Y', $week_today_ts) ?></span>
            </div>
            <div class="teacher-week-strip">
              <?php for ($i = 0; $i < 7; $i++): $day_ts = $week_start_ts + ($i * 86400); $has_class = in_array(date('D', $day_ts), $week_class_days, true); ?>
                <div class="teacher-week-day<?= $day_ts === $week_today_ts ? ' today' : '' ?><?= $has_class ? ' has-class' : '' ?>">
                  <span><?= $week_labels[$i] ?></span>
                  <span class="d-num"><?= date('j', $day_ts) ?></span>
                  <span class="teacher-week-day-dot"></span>
                </div>
              <?php endfor; ?>
            </div>
          </div>

          <div class="teacher-panel-block panel-dark">
            <div class="teacher-panel-header">
              <div class="teacher-panel-header-left">
                <span class="teacher-panel-icon"><?= $ico_announce ?></span>
                <div class="teacher-panel-title">Announcements</div>
              </div>
              <a class="teacher-panel-action" href="teacher_announcements">View All</a>
            </div>
            <?php if (empty($announcements)): ?>
              <p class="empty-state">No announcements right now.</p>
            <?php else: ?>
              <?php foreach ($announcements as $a): ?>
                <div class="teacher-announcement-row">
                  <div class="teacher-announcement-title"><?= htmlspecialchars($a['title']) ?></div>
                  <div class="teacher-announcement-body"><?= nl2br(htmlspecialchars($a['body'])) ?></div>
                  <div class="teacher-announcement-time"><?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))) ?></div>
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