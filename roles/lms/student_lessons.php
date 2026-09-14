<?php
// Lessons belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. A student can be validly signed into both at
// once; this page must never flip to Student Portal branding just
// because an unrelated Student Portal session also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
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
      .ls-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .ls-picker select {
        appearance: none; -webkit-appearance: none;
        height:36px; border:1px solid var(--lms-border, #DCE5DE); border-radius:8px;
        background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 24 16'%3E%3Cpolygon points='2,3 22,3 12,15' fill='%230A3925'/%3E%3C/svg%3E") no-repeat right 12px center;
        background-size: 12px;
        padding:0 34px 0 12px; font-size:13px; font-family:inherit; color:#1A1A2E;
        cursor: pointer; transition: border-color .15s ease;
      }
      .ls-picker select:hover, .ls-picker select:focus {
        outline: none; border-color: var(--lms-ink, #0A3925);
      }
      .ls-subject-title { font-size:13px; font-weight:700; color:var(--lms-ink); text-transform:uppercase; letter-spacing:.04em; margin:1rem 0 .5rem; }
      .ls-subject-title:first-child { margin-top:0; }
      .ls-item { border-bottom:1px solid var(--lms-border-2); padding:12px 0; }
      .ls-item:last-child { border-bottom:none; }
      .ls-item-title { font-weight:600; font-size:14px; color:#1A1A2E; }
      .ls-item-meta { font-size:11px; color:#8A8A9A; margin:2px 0 6px; }
      .ls-item-body { font-size:13px; color:#3A3A4A; white-space:pre-wrap; margin-bottom:6px; }
      .ls-item-attachment { font-size:12px; }
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
          <form method="GET" class="ls-picker">
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

          <?php if (empty($lessons_by_subject)): ?>
            <p class="empty-state">No lessons posted yet for this semester.</p>
          <?php else: ?>
            <?php foreach ($lessons_by_subject as $grp_subject_name => $lessons): ?>
              <?php if ($subject_id <= 0): ?>
                <div class="ls-subject-title"><?= htmlspecialchars($grp_subject_name) ?></div>
              <?php endif; ?>
              <?php foreach ($lessons as $l): ?>
                <div class="ls-item">
                  <div class="ls-item-title"><?= htmlspecialchars($l['title']) ?></div>
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
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
