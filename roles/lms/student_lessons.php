<?php
// Lessons belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. A student can be validly signed into both at
// once; this page must never flip to Student Portal branding just
// because an unrelated Student Portal session also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lessons — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      /* Lesson cards = LMS Home's Recent Lessons cards. */
      .ls-list { display:flex; flex-direction:column; gap:10px; }
      .ls-item { padding:14px 16px; border-radius:10px; background:var(--st-tint); scroll-margin-top:130px; }
      .ls-item:target { box-shadow:0 0 0 2px var(--st-primary); }
      .ls-item-title { display:flex; align-items:center; gap:8px; font-weight:700; font-size:14.5px; color:var(--st-dark); }
      .ls-item-meta { font-size:12px; color:var(--st-sub); margin:2px 0 8px; }
      .ls-item-body { font-size:13.5px; line-height:1.55; color:#2E3A3B; white-space:pre-wrap; word-break:break-word; margin-bottom:8px; }
      .ls-item-attachment .attach-chip { background:#fff; }
      .ls-search { position:relative; flex:1 1 220px; max-width:320px; margin-left:auto; }
      .ls-search input { width:100%; height:34px; padding:0 12px 0 32px; border:1px solid var(--st-line); border-radius:999px; background:#fff; font:inherit; font-size:12.5px; color:var(--st-dark); }
      .ls-search input:focus { outline:none; border-color:var(--st-primary); box-shadow:0 0 0 3px rgba(56,102,65,.15); }
      .ls-search svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:14px; height:14px; color:var(--st-sub); pointer-events:none; }
    </style>
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

    // Present only when arriving from a My Courses card — narrows the
    // lessons list to one subject and drives the subj-tabstrip below.
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

    $lessons_by_subject = [];
    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];

        if ($subject_id > 0) {
            $ls_stmt = mysqli_prepare($conn, "
                SELECT l.lesson_id, l.title, l.body, l.attachment_path, l.attachment_original_name, l.posted_at,
                       sub.subject_name, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
                FROM lessons l
                JOIN subjects sub ON sub.subject_id = l.subject_id
                JOIN section_subjects ss ON ss.section_id = l.section_id AND ss.subject_id = l.subject_id AND ss.semester = ?
                LEFT JOIN teachers t ON t.teacher_id = l.teacher_id
                WHERE l.section_id = ? AND l.school_year = ? AND l.subject_id = ?
                ORDER BY l.posted_at DESC
            ");
            mysqli_stmt_bind_param($ls_stmt, "iisi", $selected_sem, $sec_id, $selected_sy, $subject_id);
        } else {
            $ls_stmt = mysqli_prepare($conn, "
                SELECT l.lesson_id, l.title, l.body, l.attachment_path, l.attachment_original_name, l.posted_at,
                       sub.subject_name, CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
                FROM lessons l
                JOIN subjects sub ON sub.subject_id = l.subject_id
                JOIN section_subjects ss ON ss.section_id = l.section_id AND ss.subject_id = l.subject_id AND ss.semester = ?
                LEFT JOIN teachers t ON t.teacher_id = l.teacher_id
                WHERE l.section_id = ? AND l.school_year = ?
                ORDER BY sub.subject_name, l.posted_at DESC
            ");
            mysqli_stmt_bind_param($ls_stmt, "iis", $selected_sem, $sec_id, $selected_sy);
        }
        mysqli_stmt_execute($ls_stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($ls_stmt), MYSQLI_ASSOC) as $row) {
            $lessons_by_subject[$row['subject_name']][] = $row;
            $subject_name = $row['subject_name'];
        }
        mysqli_stmt_close($ls_stmt);
    }

    // Subject name may still be unknown if this subject simply has no
    // lessons posted yet — look it up directly so the tab strip/heading
    // isn't blank in that case.
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
          Lessons
          <span class="student-topbar-subtitle">Content posted by your teachers</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg></span>
            <div class="student-panel-title">Lessons</div>
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
            <?php if (!empty($lessons_by_subject)): ?>
              <label class="ls-search">
                <span class="hm-sr">Search lessons</span>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                <input type="search" id="lsSearch" placeholder="Search lessons" autocomplete="off">
              </label>
            <?php endif; ?>
          </form>

          <?php if (empty($lessons_by_subject)): ?>
            <p class="empty-state">No lessons posted yet for this semester.</p>
          <?php else: ?>
            <div id="lsList">
            <?php foreach ($lessons_by_subject as $grp_subject_name => $lessons): ?>
              <?php if ($subject_id <= 0): ?>
                <h3 class="lms-group-title" data-filter-group><span class="hm-tile" aria-hidden="true"><?= subject_banner_icon($grp_subject_name) ?></span><?= htmlspecialchars($grp_subject_name) ?><span class="count"><?= count($lessons) ?></span></h3>
              <?php endif; ?>
              <div class="ls-list">
              <?php foreach ($lessons as $l): ?>
                <div class="ls-item" id="lesson-<?= (int)$l['lesson_id'] ?>">
                  <div class="ls-item-title"><?= htmlspecialchars($l['title']) ?><?php if (strtotime($l['posted_at']) >= strtotime('-7 days')): ?><span class="lms-new">NEW</span><?php endif; ?></div>
                  <div class="ls-item-meta">
                    <?= htmlspecialchars($l['teacher_name'] ?? 'Teacher') ?> · <?= date('M j, Y g:i A', strtotime($l['posted_at'])) ?>
                  </div>
                  <div class="ls-item-body"><?= nl2br(htmlspecialchars($l['body'])) ?></div>
                  <?php if ($l['attachment_path']): $fi = file_icon_meta($l['attachment_original_name']); ?>
                    <div class="ls-item-attachment">
                      <a href="lesson_attachment?id=<?= (int)$l['lesson_id'] ?>" target="_blank" class="attach-chip">
                        <span class="attach-icon <?= $fi['cls'] ?>"><?= htmlspecialchars($fi['label']) ?></span>
                        <span class="attach-chip-name"><?= htmlspecialchars($l['attachment_original_name']) ?></span>
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            </div>
            <p class="hm-empty lms-filter-none" id="lsNone" hidden>No lessons match your search.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <script>
    // Search: filters the lesson cards by title / teacher / text as you type.
    (function () {
      var input = document.getElementById('lsSearch'), list = document.getElementById('lsList');
      if (!input || !list) return;
      var items = list.querySelectorAll('.ls-item'), none = document.getElementById('lsNone');
      // It sits inside the SY/semester form — Enter must not reload the page.
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        items.forEach(function (el) {
          var show = !q || el.textContent.toLowerCase().indexOf(q) !== -1;
          el.hidden = !show;
          if (show) shown++;
        });
        list.querySelectorAll('[data-filter-group]').forEach(function (h) {
          var any = h.nextElementSibling.querySelector('.ls-item:not([hidden])');
          h.hidden = !any; h.nextElementSibling.hidden = !any;
        });
        none.hidden = shown > 0;
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
