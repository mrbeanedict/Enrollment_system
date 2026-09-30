<?php
// My Courses belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. See student_lessons.php for why.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

// subject_banner_icon() — shared with lms_home.php.
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
require_once BASE_PATH . '/shared/helpers/lms_tasks.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
</head>
<body class="student-layout lms-layout lms-warm-bg">
<?php if (!require_login('student', null, 'ignore', 'bool')): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $student_id = (int) $_SESSION['student_id'];

    $sy_list = [];
    $sy_stmt = mysqli_prepare($conn, "SELECT DISTINCT school_year FROM enrollments WHERE student_id = ? ORDER BY school_year DESC");
    mysqli_stmt_bind_param($sy_stmt, "i", $student_id);
    mysqli_stmt_execute($sy_stmt);
    $sy_res = mysqli_stmt_get_result($sy_stmt);
    while ($row = mysqli_fetch_assoc($sy_res)) { $sy_list[] = $row['school_year']; }
    mysqli_stmt_close($sy_stmt);

    $selected_sy = $_GET['sy'] ?? '';
    if (!in_array($selected_sy, $sy_list, true)) {
        $selected_sy = $sy_list[0] ?? '';
    }

    $selected_sem = (string) ($_GET['sem'] ?? '1');
    if (!in_array($selected_sem, ['1', '2'], true)) $selected_sem = '1';

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.section_id, sec.section_name
        FROM enrollments e
        JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "is", $student_id, $selected_sy);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $courses = [];
    $dueBySubject = [];
    $threadCountBySubject = [];
    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];
        $c_stmt = mysqli_prepare($conn, "
            SELECT sub.subject_id, sub.subject_name,
                   CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
            WHERE ss.section_id = ? AND ss.semester = ?
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($c_stmt, "is", $sec_id, $selected_sem);
        mysqli_stmt_execute($c_stmt);
        $courses = mysqli_fetch_all(mysqli_stmt_get_result($c_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($c_stmt);

        // Due tasks per subject — same "not yet submitted/attempted"
        // definition lms_home.php's To-do list uses, just grouped by
        // subject here instead of flattened into one list.
        $due_stmt = mysqli_prepare($conn, "
            SELECT gi.item_id, gi.subject_id, gi.title, gi.due_date, gi.category, gi.is_quiz
            FROM gradebook_items gi
            LEFT JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
              AND gi.due_date IS NOT NULL AND (gs.submission_id IS NULL OR gs.status <> 'turned_in')
            UNION
            SELECT gi.item_id, gi.subject_id, gi.title, gi.due_date, gi.category, gi.is_quiz
            FROM gradebook_items gi
            LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
              AND gi.due_date IS NOT NULL AND (qa.attempt_id IS NULL OR qa.submitted_at IS NULL)
            ORDER BY due_date ASC
        ");
        mysqli_stmt_bind_param($due_stmt, "iisiis", $student_id, $sec_id, $selected_sy, $student_id, $sec_id, $selected_sy);
        mysqli_stmt_execute($due_stmt);
        $due_res = mysqli_stmt_get_result($due_stmt);
        while ($row = mysqli_fetch_assoc($due_res)) {
            $row['kind'] = lms_task_kind($row);
            $row['url']  = $row['is_quiz']
                ? 'take_quiz?item_id=' . (int) $row['item_id']
                : 'student_assignments?' . http_build_query(['subject_id' => $row['subject_id'], 'sy' => $selected_sy, 'sem' => $selected_sem]) . '#as-item-' . (int) $row['item_id'];
            $dueBySubject[(int) $row['subject_id']][] = $row;
        }
        mysqli_stmt_close($due_stmt);

        // Discussion thread counts per subject — a real count of existing
        // threads, not a fabricated "unread" badge: the LMS session has no
        // per-student read/seen tracking for posts to base one on.
        $thread_stmt = mysqli_prepare($conn, "
            SELECT subject_id, COUNT(*) AS thread_count
            FROM discussion_threads
            WHERE section_id = ? AND school_year = ? AND kind = 'discussion'
            GROUP BY subject_id
        ");
        mysqli_stmt_bind_param($thread_stmt, "is", $sec_id, $selected_sy);
        mysqli_stmt_execute($thread_stmt);
        $thread_res = mysqli_stmt_get_result($thread_stmt);
        while ($row = mysqli_fetch_assoc($thread_res)) {
            $threadCountBySubject[(int) $row['subject_id']] = (int) $row['thread_count'];
        }
        mysqli_stmt_close($thread_stmt);
    }

    $palette_count = 6;
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Courses
          <span class="student-topbar-subtitle"><?= $enrollment ? htmlspecialchars($enrollment['section_name']) : '' ?></span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <div class="student-content">
      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg></span>
            <div class="student-panel-title">Course Overview</div>
          </div>
        </div>

        <?php if (empty($sy_list)): ?>
          <p class="empty-state">No enrollment record found yet.</p>
        <?php else: ?>
          <form method="GET" class="lms-picker">
            <select name="sy" onchange="this.form.submit()">
              <?php foreach ($sy_list as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $selected_sy === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="sem" onchange="this.form.submit()">
              <option value="1" <?= $selected_sem === '1' ? 'selected' : '' ?>>Semester 1</option>
              <option value="2" <?= $selected_sem === '2' ? 'selected' : '' ?>>Semester 2</option>
            </select>
          </form>

          <?php if (empty($courses)): ?>
            <p class="empty-state">No courses found for this semester.</p>
          <?php else: ?>
            <div class="course-cards">
              <?php foreach ($courses as $i => $c):
                $subjId    = (int) $c['subject_id'];
                $dueItems  = $dueBySubject[$subjId] ?? [];
                $threadCnt = $threadCountBySubject[$subjId] ?? 0;

                // Carried by every link off this card, so the subject-scoped
                // academics pages (and their subj-tabstrip) land on the same
                // school year / semester the student is currently browsing.
                $subjQsFull = http_build_query(['subject_id' => $subjId, 'sy' => $selected_sy, 'sem' => $selected_sem]);
                $subjQsNoSem = http_build_query(['subject_id' => $subjId, 'sy' => $selected_sy]);
              ?>
                <div class="course-card" data-href="student_stream?<?= $subjQsNoSem ?>" tabindex="0" role="link" aria-label="Open <?= htmlspecialchars($c['subject_name']) ?>">
                  <div class="course-card-banner palette-<?= $i % $palette_count ?>">
                    <div class="course-card-menu">
                      <button type="button" class="course-card-menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="Course options">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                      </button>
                      <div class="course-card-menu-panel">
                        <a href="student_stream?<?= $subjQsNoSem ?>">Stream</a>
                        <a href="student_lessons?<?= $subjQsFull ?>">Lessons</a>
                        <a href="student_discussions?<?= $subjQsNoSem ?>">Discussions</a>
                        <a href="student_assignments?<?= $subjQsFull ?>">Assignments</a>
                        <a href="student_quizzes?<?= $subjQsFull ?>">Quizzes</a>
                        <a href="student_people?<?= $subjQsNoSem ?>">People</a>
                      </div>
                    </div>
                    <span class="course-card-banner-icon"><?= subject_banner_icon($c['subject_name']) ?></span>
                  </div>
                  <div class="course-card-body">
                    <div class="course-card-title"><?= htmlspecialchars($c['subject_name']) ?></div>
                    <div class="course-card-teacher"><?= htmlspecialchars($c['teacher_name'] ?? 'Teacher not yet assigned') ?></div>

                    <a class="course-card-thread-link" href="student_discussions?<?= $subjQsNoSem ?>">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.222 0-2.386-.216-3.447-.61L3 21l1.395-4.184A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                      <?= $threadCnt ?> <?= $threadCnt === 1 ? 'thread' : 'threads' ?>
                    </a>

                    <div class="course-card-due">
                      <div class="course-card-due-label">Due</div>
                      <?php if (empty($dueItems)): ?>
                        <div class="course-card-due-empty">None</div>
                      <?php else: ?>
                        <?php foreach (array_slice($dueItems, 0, 2) as $d): $dl = lms_due_short($d['due_date']); ?>
                          <a class="course-card-due-item" href="<?= htmlspecialchars($d['url']) ?>" title="<?= date('D, M j · g:i A', strtotime($d['due_date'])) ?>">
                            <span class="hm-kind <?= lms_kind_class($d['kind']) ?>"><?= htmlspecialchars($d['kind']) ?></span>
                            <span class="due-item-title"><?= htmlspecialchars($d['title']) ?></span>
                            <span class="due-item-date<?= lms_due_tone($dl) ?>"><?= $dl ?></span>
                          </a>
                        <?php endforeach; ?>
                        <?php if (count($dueItems) > 2): ?>
                          <div class="course-card-due-more">+<?= count($dueItems) - 2 ?> more</div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    document.querySelectorAll('.course-card-menu-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var menu = btn.closest('.course-card-menu');
        var wasOpen = menu.classList.contains('open');
        document.querySelectorAll('.course-card-menu.open').forEach(function (m) { m.classList.remove('open'); });
        if (!wasOpen) menu.classList.add('open');
      });
    });
    document.addEventListener('click', function () {
      document.querySelectorAll('.course-card-menu.open').forEach(function (m) { m.classList.remove('open'); });
    });

    // Clicking anywhere on a course card opens that subject's Stream (its home) —
    // except the 3-dot menu and its own links, which handle their own
    // navigation (menu links jump straight to Discussions/Assignments/Quizzes).
    document.querySelectorAll('.course-card[data-href]').forEach(function (card) {
      card.addEventListener('click', function (e) {
        if (e.target.closest('a, .course-card-menu')) return;
        window.location.href = card.dataset.href;
      });
      card.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('a, .course-card-menu')) {
          e.preventDefault();
          window.location.href = card.dataset.href;
        }
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
