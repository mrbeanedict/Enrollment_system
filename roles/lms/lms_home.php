<?php
// LMS Home belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, same as every other LMS page. See
// student_lessons.php for why this is fixed rather than guessed.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student LMS — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
</head>
<body class="student-layout lms-layout">
<?php if (!require_login('student', null, 'ignore', 'bool')): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access the Student LMS.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $student_id = (int) $_SESSION['student_id'];

    $dash_hour = (int) date('G');
    $greeting  = $dash_hour < 12 ? 'Good morning' : ($dash_hour < 18 ? 'Good afternoon' : 'Good evening');

    $stu_stmt = mysqli_prepare($conn, "SELECT given_name FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stu_stmt, "i", $student_id);
    mysqli_stmt_execute($stu_stmt);
    mysqli_stmt_bind_result($stu_stmt, $home_given_name);
    mysqli_stmt_fetch($stu_stmt);
    mysqli_stmt_close($stu_stmt);

    // Same "most recent enrollment on file" convention every other
    // student page uses — not necessarily tied to a picker here since
    // this dashboard shows live/current standing, not a browsable history.
    $enr_stmt = mysqli_prepare($conn, "
        SELECT section_id, school_year FROM enrollments
        WHERE student_id = ? ORDER BY enrollment_date DESC, enrollment_id DESC LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $courses      = [];
    $todoItems    = [];
    $recentLessons = [];
    $dueThisWeekCount = 0;
    $newLessonsCount  = 0;

    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];
        $sy     = $enrollment['school_year'];

        // My Courses (deduped across both semesters — this is "what
        // I'm taking this year," not a semester-scoped browse view).
        $c_stmt = mysqli_prepare($conn, "
            SELECT sub.subject_id, sub.subject_name, MIN(CONCAT(t.given_name, ' ', t.family_name)) AS teacher_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
            WHERE ss.section_id = ?
            GROUP BY sub.subject_id, sub.subject_name
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($c_stmt, "i", $sec_id);
        mysqli_stmt_execute($c_stmt);
        $courses = mysqli_fetch_all(mysqli_stmt_get_result($c_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($c_stmt);

        // To-do: assignments not yet submitted...
        $a_stmt = mysqli_prepare($conn, "
            SELECT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name, 'Assignment' AS kind
            FROM gradebook_items gi
            JOIN subjects sub ON sub.subject_id = gi.subject_id
            LEFT JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
              AND gi.due_date IS NOT NULL AND gs.submission_id IS NULL
        ");
        mysqli_stmt_bind_param($a_stmt, "iis", $student_id, $sec_id, $sy);
        mysqli_stmt_execute($a_stmt);
        $todoItems = mysqli_fetch_all(mysqli_stmt_get_result($a_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($a_stmt);

        // ...plus quizzes not yet attempted/submitted.
        $q_stmt = mysqli_prepare($conn, "
            SELECT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name, 'Quiz' AS kind
            FROM gradebook_items gi
            JOIN subjects sub ON sub.subject_id = gi.subject_id
            LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
              AND gi.due_date IS NOT NULL AND (qa.attempt_id IS NULL OR qa.submitted_at IS NULL)
        ");
        mysqli_stmt_bind_param($q_stmt, "iis", $student_id, $sec_id, $sy);
        mysqli_stmt_execute($q_stmt);
        $todoItems = array_merge($todoItems, mysqli_fetch_all(mysqli_stmt_get_result($q_stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($q_stmt);

        usort($todoItems, function ($a, $b) { return strtotime($a['due_date']) <=> strtotime($b['due_date']); });

        $weekAhead = strtotime('+7 days');
        foreach ($todoItems as $t) {
            if (strtotime($t['due_date']) <= $weekAhead) $dueThisWeekCount++;
        }

        // Recent lessons feed (last 14 days) + a tighter 7-day count for the stat chip.
        $l_stmt = mysqli_prepare($conn, "
            SELECT l.title, l.posted_at, sub.subject_id, sub.subject_name
            FROM lessons l
            JOIN subjects sub ON sub.subject_id = l.subject_id
            WHERE l.section_id = ? AND l.school_year = ? AND l.posted_at >= (NOW() - INTERVAL 14 DAY)
            ORDER BY l.posted_at DESC
            LIMIT 6
        ");
        mysqli_stmt_bind_param($l_stmt, "is", $sec_id, $sy);
        mysqli_stmt_execute($l_stmt);
        $recentLessons = mysqli_fetch_all(mysqli_stmt_get_result($l_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($l_stmt);

        $nl_stmt = mysqli_prepare($conn, "
            SELECT COUNT(*) AS c FROM lessons
            WHERE section_id = ? AND school_year = ? AND posted_at >= (NOW() - INTERVAL 7 DAY)
        ");
        mysqli_stmt_bind_param($nl_stmt, "is", $sec_id, $sy);
        mysqli_stmt_execute($nl_stmt);
        $newLessonsCount = (int) (mysqli_stmt_get_result($nl_stmt)->fetch_assoc()['c'] ?? 0);
        mysqli_stmt_close($nl_stmt);
    }

    $todoDisplay = array_slice($todoItems, 0, 8);

    $ico_book  = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>';
  $ico_clock = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  $ico_sparkle = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>';
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          <?= htmlspecialchars($greeting) ?><?= $home_given_name ? ', ' . htmlspecialchars($home_given_name) : '' ?>
          <span class="student-topbar-subtitle">Welcome to the Student LMS</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <div class="student-content">

      <div class="lms-dash-stats">
        <div class="lms-dash-stat">
          <span class="lms-dash-stat-icon"><?= $ico_book ?></span>
          <div>
            <div class="lms-dash-stat-number"><?= count($courses) ?></div>
            <div class="lms-dash-stat-label">Course<?= count($courses) === 1 ? '' : 's' ?></div>
          </div>
        </div>
        <div class="lms-dash-stat">
          <span class="lms-dash-stat-icon<?= $dueThisWeekCount > 0 ? ' warn' : '' ?>"><?= $ico_clock ?></span>
          <div>
            <div class="lms-dash-stat-number"><?= $dueThisWeekCount ?></div>
            <div class="lms-dash-stat-label">Due This Week</div>
          </div>
        </div>
        <div class="lms-dash-stat">
          <span class="lms-dash-stat-icon"><?= $ico_sparkle ?></span>
          <div>
            <div class="lms-dash-stat-number"><?= $newLessonsCount ?></div>
            <div class="lms-dash-stat-label">New Lesson<?= $newLessonsCount === 1 ? '' : 's' ?> This Week</div>
          </div>
        </div>
      </div>

      <div class="lms-dash-grid">
        <div>
          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><?= $ico_clock ?></span>
                <div class="student-panel-title">To-Do</div>
              </div>
            </div>
            <?php if (empty($todoDisplay)): ?>
              <p class="lms-empty-mini">No tasks due — you're all caught up 🎉</p>
            <?php else: ?>
              <?php foreach ($todoDisplay as $t):
                $swatch = $t['subject_id'] % 6;
                $isOverdue = strtotime($t['due_date']) < time();
              ?>
                <div class="lms-todo-item lms-swatch-<?= $swatch ?>">
                  <div class="lms-todo-item-body">
                    <div class="lms-todo-item-title"><?= htmlspecialchars($t['title']) ?></div>
                    <div class="lms-todo-item-meta">
                      <span class="subject"><?= htmlspecialchars($t['subject_name']) ?></span>
                      · Due <?= date('M j, g:i A', strtotime($t['due_date'])) ?>
                      <?php if ($isOverdue): ?><span class="lms-todo-overdue"> · Overdue</span><?php endif; ?>
                    </div>
                  </div>
                  <span class="lms-todo-kind"><?= htmlspecialchars($t['kind']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><?= $ico_book ?></span>
                <div class="student-panel-title">Recent Lessons</div>
              </div>
            </div>
            <?php if (empty($recentLessons)): ?>
              <p class="lms-empty-mini">No lessons posted recently.</p>
            <?php else: ?>
              <?php foreach ($recentLessons as $l): $swatch = $l['subject_id'] % 6; ?>
                <div class="lms-lesson-item lms-swatch-<?= $swatch ?>">
                  <div class="lms-lesson-item-body">
                    <div class="lms-lesson-item-title"><?= htmlspecialchars($l['title']) ?></div>
                    <div class="lms-lesson-item-meta">
                      <span class="subject"><?= htmlspecialchars($l['subject_name']) ?></span>
                      · <?= date('M j, g:i A', strtotime($l['posted_at'])) ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <div class="student-panel-title">My Courses</div>
              </div>
            </div>
            <?php if (empty($courses)): ?>
              <p class="lms-empty-mini">No courses found yet.</p>
            <?php else: ?>
              <?php foreach ($courses as $i => $c): $swatch = $c['subject_id'] % 6; ?>
                <div class="lms-course-row lms-swatch-<?= $swatch ?>">
                  <span class="lms-course-dot"></span>
                  <div>
                    <div class="lms-course-row-name"><?= htmlspecialchars($c['subject_name']) ?></div>
                    <div class="lms-course-row-teacher"><?= htmlspecialchars($c['teacher_name'] ?? 'Teacher not yet assigned') ?></div>
                  </div>
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
