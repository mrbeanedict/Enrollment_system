<?php
// Quizzes belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. A student can be validly signed into both at
// once; this page must never flip to Student Portal branding just
// because an unrelated Student Portal session also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
require_once BASE_PATH . '/shared/helpers/lms_tasks.php';

$is_student = require_login('student', null, 'ignore', 'bool');
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quizzes — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
</head>
<body class="student-layout lms-layout lms-warm-bg">
<?php if (!$is_student): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

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

    // Present only when arriving from a My Courses card — narrows the
    // quiz list to one subject and drives the subj-tabstrip below.
    $subject_id   = (int) ($_GET['subject_id'] ?? 0);
    $subject_name = null;

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.section_id
        FROM enrollments e
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "is", $student_id, $selected_sy);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $items_by_subject = [];
    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];

        if ($subject_id > 0) {
            $it_stmt = mysqli_prepare($conn, "
                SELECT gi.item_id, gi.title, gi.time_limit_minutes, gi.max_score, gi.due_date, gi.category, gi.is_quiz,
                       sub.subject_name,
                       qa.attempt_id, qa.submitted_at, qa.score
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1 AND gi.subject_id = ?
                ORDER BY gi.due_date IS NULL, gi.due_date, gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiisi", $selected_sem, $student_id, $sec_id, $selected_sy, $subject_id);
        } else {
            $it_stmt = mysqli_prepare($conn, "
                SELECT gi.item_id, gi.title, gi.time_limit_minutes, gi.max_score, gi.due_date, gi.category, gi.is_quiz,
                       sub.subject_name,
                       qa.attempt_id, qa.submitted_at, qa.score
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
                ORDER BY sub.subject_name, gi.due_date IS NULL, gi.due_date, gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiis", $selected_sem, $student_id, $sec_id, $selected_sy);
        }
        mysqli_stmt_execute($it_stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($it_stmt), MYSQLI_ASSOC) as $row) {
            $items_by_subject[$row['subject_name']][] = $row;
            $subject_name = $row['subject_name'];
        }
        mysqli_stmt_close($it_stmt);
    }

    if ($subject_id > 0 && $subject_name === null) {
        $sn_stmt = mysqli_prepare($conn, "SELECT subject_name FROM subjects WHERE subject_id = ?");
        mysqli_stmt_bind_param($sn_stmt, "i", $subject_id);
        mysqli_stmt_execute($sn_stmt);
        mysqli_stmt_bind_result($sn_stmt, $subject_name);
        mysqli_stmt_fetch($sn_stmt);
        mysqli_stmt_close($sn_stmt);
    }
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Quizzes
          <span class="student-topbar-subtitle">Take your online quizzes for each class</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">
      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            <div class="student-panel-title">Quizzes</div>
          </div>
        </div>

        <?php if (empty($sy_list)): ?>
          <p class="empty-state">No enrollment record found yet.</p>
        <?php else: ?>
          <form method="GET" class="lms-picker">
            <?php if ($subject_id > 0): ?><input type="hidden" name="subject_id" value="<?= $subject_id ?>"><?php endif; ?>
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

          <?php if (empty($items_by_subject)): ?>
            <p class="empty-state">No quizzes available this semester.</p>
          <?php else:
            $all   = array_merge(...array_values($items_by_subject));
            $nDone = count(array_filter($all, fn($r) => $r['submitted_at']));
            $num   = fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
          ?>
            <div class="lms-summary">
              <span class="lms-summary-count"><?= $nDone ?> of <?= count($all) ?> done</span>
              <div class="hm-chips" role="group" aria-label="Filter quizzes" data-filter-list="qzList">
                <button type="button" class="hm-chip is-on" data-filter="all" aria-pressed="true">All</button>
                <button type="button" class="hm-chip" data-filter="todo" aria-pressed="false">To take</button>
                <button type="button" class="hm-chip" data-filter="done" aria-pressed="false">Done</button>
              </div>
            </div>
            <div class="hm-progress" aria-hidden="true"><span style="transform:scaleX(<?= count($all) ? $nDone / count($all) : 0 ?>)"></span></div>

            <div id="qzList">
            <?php foreach ($items_by_subject as $grp_subject_name => $subj_items): ?>
              <?php if ($subject_id <= 0): ?>
                <h3 class="lms-group-title" data-filter-group><span class="hm-tile" aria-hidden="true"><?= subject_banner_icon($grp_subject_name) ?></span><?= htmlspecialchars($grp_subject_name) ?><span class="count"><?= count($subj_items) ?></span></h3>
              <?php endif; ?>
              <ul class="hm-cal-list">
              <?php foreach ($subj_items as $it):
                $kind = lms_task_kind($it);
                $done = (bool) $it['submitted_at'];
                $dl   = (!$done && $it['due_date']) ? lms_due_short($it['due_date']) : '';
              ?>
                <li class="hm-cal-item <?= lms_kind_class($kind) ?><?= $done ? ' is-done' : '' ?>" data-status="<?= $done ? 'done' : 'todo' ?>">
                  <div class="hm-cal-item-main">
                    <b><?= htmlspecialchars($it['title']) ?></b>
                    <span>
                      <?= $it['time_limit_minutes'] ? (int) $it['time_limit_minutes'] . '-minute time limit' : 'No time limit' ?>
                      <?php if ($it['due_date']): ?> · Due <?= date('M j, g:i A', strtotime($it['due_date'])) ?><?php endif; ?>
                    </span>
                  </div>
                  <span class="hm-kind <?= lms_kind_class($kind) ?>"><?= $kind ?></span>
                  <?php if ($done): ?>
                    <span class="lms-score">✓ <?= $num($it['score']) ?> / <?= $num($it['max_score']) ?></span>
                    <a class="hm-open-btn is-quiet" href="take_quiz?item_id=<?= (int) $it['item_id'] ?>">View<span class="hm-sr"> result for <?= htmlspecialchars($it['title']) ?></span></a>
                  <?php else: ?>
                    <?php if ($dl): ?><span class="hm-todo-due<?= lms_due_tone($dl) ?>"><?= $dl ?></span><?php endif; ?>
                    <a class="hm-open-btn" href="take_quiz?item_id=<?= (int) $it['item_id'] ?>"><?= $it['attempt_id'] ? 'Resume' : 'Start' ?><span class="hm-sr"> <?= htmlspecialchars($it['title']) ?></span></a>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
              </ul>
            <?php endforeach; ?>
            </div>
            <p class="hm-empty lms-filter-none" hidden>Nothing in this filter.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="<?= APP_URL ?>/assets/js/lms_filter.js?v=<?= filemtime(BASE_PATH . '/assets/js/lms_filter.js') ?>"></script>
<?php endif; ?>
</body>
</html>
