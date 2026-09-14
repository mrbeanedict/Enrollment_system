<?php
// Quizzes belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. A student can be validly signed into both at
// once; this page must never flip to Student Portal branding just
// because an unrelated Student Portal session also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

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
    <style>
      .as-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .as-picker select {
        appearance: none; -webkit-appearance: none;
        height:36px; border:1px solid var(--lms-border, #DCE5DE); border-radius:8px;
        background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 24 16'%3E%3Cpolygon points='2,3 22,3 12,15' fill='%230A3925'/%3E%3C/svg%3E") no-repeat right 12px center;
        background-size: 12px;
        padding:0 34px 0 12px; font-size:13px; font-family:inherit; color:#1A1A2E;
        cursor: pointer; transition: border-color .15s ease;
      }
      .as-picker select:hover, .as-picker select:focus {
        outline: none; border-color: var(--lms-ink, #0A3925);
      }
      .as-subject-title { font-size:13px; font-weight:700; color:var(--lms-ink); text-transform:uppercase; letter-spacing:.04em; margin:1rem 0 .5rem; }
      .as-subject-title:first-child { margin-top:0; }
      .as-item { border-bottom:1px solid var(--lms-border-2); padding:12px 0; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
      .as-item:last-child { border-bottom:none; }
      .as-item-title { font-weight:600; font-size:14px; color:#1A1A2E; }
      .as-item-meta { font-size:11px; color:#8A8A9A; margin-top:2px; }
      .btn-sm { height:32px; padding:0 12px; border:1px solid var(--lms-border); border-radius:6px; background:#fff; font-size:12px; font-family:inherit; cursor:pointer; color:#5A5A72; text-decoration:none; display:inline-block; line-height:32px; }
      .btn-sm:hover { border-color:var(--lms-ink); color:var(--lms-ink); }
      .as-submitted { font-size:12px; color:#1A6B4A; background:#EBF7F2; border:0.5px solid #A8D9C5; border-radius:6px; padding:6px 10px; }
    </style>
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
                SELECT gi.item_id, gi.title, gi.time_limit_minutes, gi.max_score,
                       sub.subject_name,
                       qa.submitted_at, qa.score
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1 AND gi.subject_id = ?
                ORDER BY gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiisi", $selected_sem, $student_id, $sec_id, $selected_sy, $subject_id);
        } else {
            $it_stmt = mysqli_prepare($conn, "
                SELECT gi.item_id, gi.title, gi.time_limit_minutes, gi.max_score,
                       sub.subject_name,
                       qa.submitted_at, qa.score
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
                ORDER BY sub.subject_name, gi.item_id
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
          <form method="GET" class="as-picker">
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
          <?php else: ?>
            <?php foreach ($items_by_subject as $grp_subject_name => $subj_items): ?>
              <?php if ($subject_id <= 0): ?>
                <div class="as-subject-title"><?= htmlspecialchars($grp_subject_name) ?></div>
              <?php endif; ?>
              <?php foreach ($subj_items as $it): ?>
                <div class="as-item">
                  <div>
                    <div class="as-item-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="as-item-meta"><?= $it['time_limit_minutes'] ? $it['time_limit_minutes'] . ' minute time limit' : 'No time limit' ?></div>
                  </div>
                  <?php if ($it['submitted_at']): ?>
                    <div class="as-submitted">✓ Score: <?= rtrim(rtrim(number_format((float)$it['score'], 2), '0'), '.') ?> / <?= rtrim(rtrim(number_format((float)$it['max_score'], 2), '0'), '.') ?></div>
                  <?php else: ?>
                    <a class="btn-sm" href="take_quiz?item_id=<?= (int)$it['item_id'] ?>">Start Quiz</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
