<?php
// Subject People — the teacher(s) and classmates of one LMS subject (this
// section, this school year). Names only: no student numbers, emails or
// contact details are shown to classmates.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/lms_class.php';

$is_student = require_login('student', null, 'ignore', 'bool');
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>People — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      /* Teachers beside Classmates on wide screens; classmates flow into columns. */
      .pp-wrap { display:grid; grid-template-columns:minmax(260px, 340px) minmax(0, 1fr); gap:18px; align-items:start; }
      .pp-wrap .pp-group { margin-bottom:0; }
      #ppList { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); }
      @media (max-width: 900px) { .pp-wrap { grid-template-columns:1fr; } }
      .pp-group { background:#fff; border:1px solid var(--lms-border); border-radius:14px; margin-bottom:18px; overflow:hidden; }
      .pp-head { display:flex; align-items:center; gap:12px; padding:16px 20px 14px; border-bottom:1px solid var(--lms-border); flex-wrap:wrap; }
      .pp-head .hm-tile { width:34px; height:34px; background:var(--st-info); color:var(--st-dark); }
      .pp-head h2 { margin:0; font-family:'Fraunces', Georgia, serif; font-weight:600; font-size:17px; color:var(--st-dark); }
      .pp-head .pp-count { margin-left:auto; }
      .pp-find { position:relative; flex:1 1 180px; max-width:240px; }
      .pp-find input { width:100%; height:32px; padding:0 12px; border:1px solid var(--st-line); border-radius:999px; font:inherit; font-size:12.5px; color:var(--st-dark); }
      .pp-find input:focus { outline:none; border-color:var(--st-primary); box-shadow:0 0 0 3px rgba(56,102,65,.15); }
      .pp-list li[hidden] { display:none; }
      .pp-count { font-size:13px; font-weight:600; color:var(--lms-muted); }
      .pp-list { list-style:none; margin:0; padding:0; }
      .pp-list li { display:flex; align-items:center; gap:12px; padding:11px 20px; border-bottom:1px solid var(--lms-border-2); font-size:14px; color:#1A1A2E; }
      .pp-list li:last-child { border-bottom:0; }
      .pp-ava { flex:none; width:34px; height:34px; border-radius:50%; display:grid; place-items:center; font-size:12px; font-weight:700; background:var(--lms-cream); color:var(--lms-ink); }
      .pp-ava.teacher { background:var(--st-dark); color:var(--st-beige); }
      .pp-you { margin-left:auto; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--lms-ink-accent);
                background:var(--lms-cream); padding:3px 8px; border-radius:999px; }
      .pp-li-you { background:var(--lms-surface-soft); }
      .pp-empty { padding:18px 20px; font-size:13.5px; color:var(--lms-muted); margin:0; }
    </style>
</head>
<body class="student-layout lms-layout lms-warm-bg">
<?php if (!$is_student): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $sy_list = [];
    $sy_stmt = $conn->prepare("SELECT DISTINCT school_year FROM enrollments WHERE student_id = ? ORDER BY school_year DESC");
    $sy_stmt->bind_param('i', $student_id);
    $sy_stmt->execute();
    foreach ($sy_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $sy_list[] = $row['school_year'];
    $sy_stmt->close();

    $selected_sy = $_GET['sy'] ?? '';
    if (!in_array($selected_sy, $sy_list, true)) $selected_sy = $sy_list[0] ?? '';

    $enr_stmt = $conn->prepare("
        SELECT e.section_id FROM enrollments e
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    $enr_stmt->bind_param('is', $student_id, $selected_sy);
    $enr_stmt->execute();
    $enrollment = $enr_stmt->get_result()->fetch_assoc();
    $enr_stmt->close();
    $sec_id = $enrollment ? (int) $enrollment['section_id'] : 0;

    $subjects = [];
    if ($sec_id > 0) {
        $subj_stmt = $conn->prepare("
            SELECT DISTINCT sub.subject_id, sub.subject_name
            FROM section_subjects ss JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ?
            ORDER BY sub.subject_name
        ");
        $subj_stmt->bind_param('i', $sec_id);
        $subj_stmt->execute();
        $subjects = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $subj_stmt->close();
    }

    $subject_id   = (int) ($_GET['subject_id'] ?? 0);
    $subject_name = null;
    foreach ($subjects as $s) {
        if ((int) $s['subject_id'] === $subject_id) { $subject_name = $s['subject_name']; break; }
    }
    if ($subject_name === null && $subjects) {
        $subject_id   = (int) $subjects[0]['subject_id'];
        $subject_name = $subjects[0]['subject_name'];
    }

    $teachers = $students = [];
    $in_class = $subject_name !== null && disc_student_owns_class($conn, $student_id, $subject_id, $sec_id, $selected_sy);
    if ($in_class) {
        $teachers = lms_class_teachers($conn, $subject_id, $sec_id, $selected_sy);
        $students = lms_class_students($conn, $subject_id, $sec_id, $selected_sy);
    }
    $classmates = count(array_filter($students, fn($s) => (int) $s['student_id'] !== $student_id));
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          People
          <span class="student-topbar-subtitle">Your teacher and classmates</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">
      <?php if ($subject_name === null): ?>
        <p class="empty-state">No subjects found yet.</p>
      <?php elseif (!$in_class): ?>
        <p class="empty-state">This subject isn't part of your load this school year.</p>
      <?php else: ?>
        <div class="pp-wrap">
          <section class="pp-group" aria-labelledby="pp-t">
            <div class="pp-head">
              <span class="hm-tile" aria-hidden="true"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg></span>
              <h2 id="pp-t"><?= count($teachers) === 1 ? 'Teacher' : 'Teachers' ?></h2>
            </div>
            <?php if (!$teachers): ?>
              <p class="pp-empty">No teacher has been assigned to this subject yet.</p>
            <?php else: ?>
              <ul class="pp-list">
                <?php foreach ($teachers as $t): ?>
                  <li>
                    <span class="pp-ava teacher" aria-hidden="true"><?= htmlspecialchars(lms_initials($t['given_name'], $t['family_name'])) ?></span>
                    <?= htmlspecialchars($t['given_name'] . ' ' . $t['family_name']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="pp-group" aria-labelledby="pp-s">
            <div class="pp-head">
              <span class="hm-tile" aria-hidden="true"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 015.36-1.86M7 20v-2c0-.66.13-1.28.36-1.86m0 0a5 5 0 019.28 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></span>
              <h2 id="pp-s">Classmates</h2>
              <span class="pp-count"><?= $classmates ?> <?= $classmates === 1 ? 'classmate' : 'classmates' ?></span>
              <?php if (count($students) > 8): ?>
                <label class="pp-find"><span class="hm-sr">Find a classmate</span><input type="search" id="ppFind" placeholder="Find a classmate" autocomplete="off"></label>
              <?php endif; ?>
            </div>
            <?php if (!$students): ?>
              <p class="pp-empty">No students are enrolled in this subject yet.</p>
            <?php else: ?>
              <ul class="pp-list" id="ppList">
                <?php foreach ($students as $s): $me = (int) $s['student_id'] === $student_id; ?>
                  <li class="<?= $me ? 'pp-li-you' : '' ?>">
                    <span class="pp-ava" aria-hidden="true"><?= htmlspecialchars(lms_initials($s['given_name'], $s['family_name'])) ?></span>
                    <?= htmlspecialchars($s['given_name'] . ' ' . $s['family_name']) ?>
                    <?php if ($me): ?><span class="pp-you">You</span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    (function () {
      var input = document.getElementById('ppFind');
      if (!input) return;
      var rows = document.querySelectorAll('#ppList li');
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        rows.forEach(function (li) { li.hidden = q !== '' && li.textContent.toLowerCase().indexOf(q) === -1; });
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
