<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
include_once '../notify.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$valid_quarters = ['1', '2', '3', '4'];
$valid_categories = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'quarterly_assessment' => 'Quarterly Assessment'];

$success = '';
$error   = '';

// ── Add a gradable item (assignment/quiz/long quiz) to a class+quarter ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $quarter    = $_POST['quarter'] ?? '';
    $category   = $_POST['category'] ?? '';
    $title      = trim($_POST['title'] ?? '');
    $max_score  = (float) ($_POST['max_score'] ?? 0);
    $item_type  = $_POST['item_type'] ?? 'manual';
    $accepts_submission = $item_type === 'submission' ? 1 : 0;
    $is_quiz    = $item_type === 'quiz' ? 1 : 0;
    $time_limit_raw = trim($_POST['time_limit_minutes'] ?? '');
    $time_limit = ($is_quiz && $time_limit_raw !== '') ? (int) $time_limit_raw : null;
    $due_date_raw = trim($_POST['due_date'] ?? '');
    $due_date   = $due_date_raw !== '' ? str_replace('T', ' ', $due_date_raw) . ':00' : null;
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'quarter' => $quarter]);

    // Only add items to a class this teacher actually teaches — never trust
    // the posted subject/section pair blindly. Scoped to the semester the
    // quarter falls in, since a different teacher may own the other semester.
    $own_semester = quarter_to_semester(in_array($quarter, $valid_quarters, true) ? $quarter : '1');
    $own = $conn->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=? AND semester=? AND is_active=1");
    $own->bind_param('iiisi', $teacher_id, $subject_id, $section_id, $sy, $own_semester);
    $own->execute();
    $isOwn = (bool) $own->get_result()->fetch_row();
    $own->close();

    if (!$isOwn) {
        $_SESSION['gb_flash'] = 'That class was not found among your assignments.';
        $_SESSION['gb_flash_type'] = 'error';
    } elseif (!in_array($quarter, $valid_quarters, true) || !isset($valid_categories[$category]) || $title === '' || $max_score <= 0) {
        $_SESSION['gb_flash'] = 'Please fill in the item title and a valid max score.';
        $_SESSION['gb_flash_type'] = 'error';
    } else {
        $ins = $conn->prepare("
            INSERT INTO gradebook_items (teacher_id, subject_id, section_id, school_year, quarter, category, title, max_score, accepts_submission, due_date, is_quiz, time_limit_minutes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param('iiissssdisii', $teacher_id, $subject_id, $section_id, $sy, $quarter, $category, $title, $max_score, $accepts_submission, $due_date, $is_quiz, $time_limit);
        if ($ins->execute()) {
            $_SESSION['gb_flash'] = "\"$title\" added.";
            $_SESSION['gb_flash_type'] = 'success';
            // Only student-facing content (a quiz to take, or an assignment
            // to submit) is worth notifying about — a plain manual score
            // entry (e.g. a written exam grade) isn't "posted" content.
            if ($is_quiz || $accepts_submission) {
                $gb_stmt = $conn->prepare("
                    SELECT us.user_student_id
                    FROM enrollment_subjects es
                    JOIN enrollments e ON e.enrollment_id = es.enrollment_id
                    JOIN users_student us ON us.student_id = e.student_id
                    WHERE es.subject_id = ? AND es.section_id = ? AND e.school_year = ? AND e.status = 'enrolled'
                ");
                $gb_stmt->bind_param('iis', $subject_id, $section_id, $sy);
                $gb_stmt->execute();
                $gb_ids = array_column($gb_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_student_id');
                $gb_stmt->close();
                $gb_kind = $is_quiz ? 'quiz' : 'assignment';
                notify_student_users(
                    $conn,
                    $gb_ids,
                    "New $gb_kind posted: \"$title\".",
                    $is_quiz ? 'learningportal/student_quizzes' : 'learningportal/student_assignments'
                );
            }
        } else {
            $_SESSION['gb_flash'] = 'Could not add the item. Please try again.';
            $_SESSION['gb_flash_type'] = 'error';
        }
        $ins->close();
    }
    header("Location: teacher_gradebook?" . $return_qs);
    exit();
}

// ── Edit an existing item's title/max score ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $item_id    = (int) ($_POST['item_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $quarter    = $_POST['quarter'] ?? '';
    $title      = trim($_POST['title'] ?? '');
    $max_score  = (float) ($_POST['max_score'] ?? 0);
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'quarter' => $quarter]);

    if ($title === '' || $max_score <= 0) {
        $_SESSION['gb_flash'] = 'Please provide a title and a valid max score.';
        $_SESSION['gb_flash_type'] = 'error';
    } else {
        // Ownership is enforced directly in the UPDATE's WHERE clause —
        // never trust the posted item_id to already belong to this teacher.
        $upd = $conn->prepare("UPDATE gradebook_items SET title=?, max_score=? WHERE item_id=? AND teacher_id=?");
        $upd->bind_param('sdii', $title, $max_score, $item_id, $teacher_id);
        $upd->execute();
        if ($upd->affected_rows > 0) {
            $_SESSION['gb_flash'] = "\"$title\" updated.";
            $_SESSION['gb_flash_type'] = 'success';
        } else {
            $_SESSION['gb_flash'] = 'Could not update — item not found among your assignments.';
            $_SESSION['gb_flash_type'] = 'error';
        }
        $upd->close();
    }
    header("Location: teacher_gradebook?" . $return_qs);
    exit();
}

// ── Delete an item (its scores go with it via ON DELETE CASCADE) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id    = (int) ($_POST['item_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $quarter    = $_POST['quarter'] ?? '';
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'quarter' => $quarter]);

    $del = $conn->prepare("DELETE FROM gradebook_items WHERE item_id=? AND teacher_id=?");
    $del->bind_param('ii', $item_id, $teacher_id);
    $del->execute();
    if ($del->affected_rows > 0) {
        $_SESSION['gb_flash'] = 'Item deleted.';
        $_SESSION['gb_flash_type'] = 'success';
    } else {
        $_SESSION['gb_flash'] = 'Could not delete — item not found among your assignments.';
        $_SESSION['gb_flash_type'] = 'error';
    }
    $del->close();
    header("Location: teacher_gradebook?" . $return_qs);
    exit();
}

// Per-cell score saving now happens via ajax/teacher_save_score.php
// (Enter-to-save on each input) instead of a page-level batch submit —
// see the autosave JS near the bottom of this file.

$flash      = $_SESSION['gb_flash']      ?? '';
$flash_type = $_SESSION['gb_flash_type'] ?? 'info';
unset($_SESSION['gb_flash'], $_SESSION['gb_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
    <style>
      .gb-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .gb-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .gb-cat-block { margin-bottom:1.25rem; }
      .gb-cat-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#2F6B4F; margin-bottom:.5rem; }
      .gb-add-item { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:.6rem; }
      .gb-add-item input {
        height:32px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px; font-family:inherit;
      }
      .gb-add-item input[name="title"] { flex:1 1 180px; }
      .gb-add-item input[name="max_score"] { width:80px; }
      .gb-add-item input[name="due_date"] { width:170px; }
      .gb-submission-toggle { display:flex; align-items:center; gap:4px; font-size:11px; color:#5A5A72; white-space:nowrap; }
      .gb-submission-toggle input { height:auto !important; }
      .gb-submissions { margin-top:6px; padding:8px 10px; background:#FAFAFC; border:0.5px solid #EBEBF0; border-radius:6px; font-size:11px; }
      .gb-submissions-summary { font-weight:600; color:#5A5A72; margin-bottom:4px; }
      .gb-submission-row { display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
      .gb-late-badge { color:#C0392B; font-weight:600; }
      .gb-due-badge { color:#8A8A9A; font-size:10px; display:block; }
      .btn-sm { height:32px; padding:0 12px; border:0.5px solid #D4D4E0; border-radius:6px; background:#fff; font-size:12px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
      .btn-primary-gb { height:38px; padding:0 18px; background:var(--brand-primary); border:none; border-radius:8px; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
      .btn-primary-gb:hover { background:var(--brand-primary-hover); }
      .gb-score-table { width:100%; border-collapse:collapse; font-size:13px; }
      .gb-score-table th, .gb-score-table td { padding:10px 14px; border-bottom:1px solid #EEF3F0; text-align:left; }
      .gb-item-table thead th {
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
        color:var(--brand-primary); background:var(--brand-tint); border-bottom:1px solid #DCEAE1;
      }
      .gb-item-table thead th:first-child { border-top-left-radius:12px; }
      .gb-item-table thead th:last-child { border-top-right-radius:12px; }
      .gb-item-table tbody tr:last-child td { border-bottom:none; }
      .gb-item-table tbody tr:hover { background:#FAFCFA; }
      .gb-item-table .td-name { font-weight:500; color:#1A1A2E; }
      .gb-max { font-size:10px; color:#8A8A9A; display:block; }
      .gb-item-actions { display:inline-flex; gap:10px; align-items:center; }
      .gb-manage-link { font-size:12px; color:var(--brand-primary); font-weight:600; text-decoration:none; white-space:nowrap; }
      .gb-manage-link:hover { text-decoration:underline; }
      .gb-item-edit, .gb-item-delete {
        border:none; background:none; cursor:pointer; font-size:13px; padding:1px 3px; line-height:1;
        color:#8A8A9A; font-family:inherit;
      }
      .gb-item-edit:hover { color:var(--brand-primary); }
      .gb-item-delete:hover { color:#C0392B; }
      .gb-table-scroll { overflow-x:auto; border-radius:12px; border:1px solid #EAF3EE; }
      .gb-cat-badge {
        display:inline-block; font-size:9px; font-weight:700; letter-spacing:.03em;
        border-radius:4px; padding:1px 5px;
      }
      .gb-cat-badge-written_work { color:#2C5AA0; background:#EAF1FB; }
      .gb-cat-badge-performance_task { color:#C06A10; background:#FFF4E6; }
      .gb-cat-badge-quarterly_assessment { color:#2F6B4F; background:#EAF4EE; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    // Every distinct class (subject+section+school_year) this teacher is
    // assigned to — same source as My Classes/My Schedule.
    $cls_stmt = $conn->prepare("
        SELECT ta.subject_id, ta.section_id, ta.school_year, ta.semester, sub.subject_name,
               sec.section_name, sec.grade_level, st.strand_code AS strand
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
        JOIN strands st ON st.strand_id = sec.strand
        WHERE ta.teacher_id = ? AND ta.is_active = 1
        ORDER BY ta.school_year DESC, sec.grade_level, sec.section_name, sub.subject_name
    ");
    $cls_stmt->bind_param('i', $teacher_id);
    $cls_stmt->execute();
    $classes = $cls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cls_stmt->close();

    $sel_subject = (int) ($_GET['subject_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_sy      = $_GET['sy'] ?? '';
    $sel_quarter = in_array($_GET['quarter'] ?? '', $valid_quarters, true) ? $_GET['quarter'] : '1';
    $return_qs   = http_build_query(['subject_id' => $sel_subject, 'section_id' => $sel_section, 'sy' => $sel_sy, 'quarter' => $sel_quarter]);

    // A teacher can have separate Sem1/Sem2 assignments for the same
    // subject+section+school_year — match the one whose semester actually
    // covers the selected quarter.
    $current = null;
    foreach ($classes as $c) {
        if ((int) $c['subject_id'] === $sel_subject && (int) $c['section_id'] === $sel_section && $c['school_year'] === $sel_sy
            && (int) $c['semester'] === quarter_to_semester($sel_quarter)) {
            $current = $c;
            break;
        }
    }
    // Nothing selected yet (or an invalid combo) — default to the first class.
    if ($current === null && !empty($classes)) {
        $current = $classes[0];
        $sel_subject = (int) $current['subject_id'];
        $sel_section = (int) $current['section_id'];
        $sel_sy      = $current['school_year'];
    }

    $items = [];
    $roster = [];

    if ($current !== null) {
        $it_stmt = $conn->prepare("
            SELECT item_id, category, title, max_score, accepts_submission, due_date, is_quiz, time_limit_minutes
            FROM gradebook_items
            WHERE subject_id=? AND section_id=? AND school_year=? AND quarter=? AND teacher_id=?
            ORDER BY category, item_id
        ");
        $it_stmt->bind_param('iissi', $sel_subject, $sel_section, $sel_sy, $sel_quarter, $teacher_id);
        $it_stmt->execute();
        foreach ($it_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $items[$row['category']][] = $row;
        }
        $it_stmt->close();

        // Roster count only — needed for the "X / N submitted" summary
        // below; there's no per-student score grid on this page anymore.
        $ros_stmt = $conn->prepare("
            SELECT s.student_id, s.family_name, s.given_name
            FROM enrollments e JOIN students s ON s.student_id = e.student_id
            WHERE e.section_id = ? AND e.status = 'enrolled'
            ORDER BY s.family_name, s.given_name
        ");
        $ros_stmt->bind_param('i', $sel_section);
        $ros_stmt->execute();
        $roster = $ros_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ros_stmt->close();

        // Submissions per item, for the compact summary shown under any
        // item with accepts_submission=1.
        $submissionsByItem = [];
        $sub_stmt = $conn->prepare("
            SELECT gsub.item_id, gsub.student_id, gsub.submitted_at, gsub.is_late, s.family_name, s.given_name
            FROM gradebook_submissions gsub
            JOIN gradebook_items gi ON gi.item_id = gsub.item_id
            JOIN students s ON s.student_id = gsub.student_id
            WHERE gi.subject_id=? AND gi.section_id=? AND gi.school_year=? AND gi.quarter=? AND gi.teacher_id=?
            ORDER BY s.family_name, s.given_name
        ");
        $sub_stmt->bind_param('iissi', $sel_subject, $sel_section, $sel_sy, $sel_quarter, $teacher_id);
        $sub_stmt->execute();
        foreach ($sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $submissionsByItem[(int) $row['item_id']][] = $row;
        }
        $sub_stmt->close();
    }

    // Flatten all categories into one ordered list for the unified table —
    // still tagged with its category so the DepEd weighting concept (and a
    // small badge in the column header) stays visible.
    $catBadge = ['written_work' => 'WW', 'performance_task' => 'PT', 'quarterly_assessment' => 'QA'];
    $allItems = [];
    foreach ($valid_categories as $catKey => $catLabel) {
        foreach ($items[$catKey] ?? [] as $it) {
            $allItems[] = $it;
        }
    }
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Assessment
          <span class="teacher-topbar-subtitle">Manage quizzes, seatwork, and exams for your classes</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <?php
          // The class picker chooses subject+section+school_year only —
          // which semester applies is decided by the Quarter picker next
          // to it, so a teacher with the same class in both semesters
          // (a common case) sees it once here, not duplicated.
          $class_options = [];
          foreach ($classes as $c) {
              $key = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year'];
              if (!isset($class_options[$key])) { $class_options[$key] = $c; }
          }
        ?>
        <form method="GET" class="gb-picker">
          <select name="class" onchange="var v=this.value.split('|'); location.href='teacher_gradebook?subject_id='+v[0]+'&section_id='+v[1]+'&sy='+encodeURIComponent(v[2])+'&quarter=<?= urlencode($sel_quarter) ?>';">
            <?php foreach ($class_options as $c): $val = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year']; ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= ((int)$c['subject_id']===$sel_subject && (int)$c['section_id']===$sel_section && $c['school_year']===$sel_sy) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section_name']) ?> (SY <?= htmlspecialchars($c['school_year']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select name="quarter" onchange="location.href='teacher_gradebook?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>&quarter='+this.value;">
            <?php foreach ($valid_quarters as $q): ?>
              <option value="<?= $q ?>" <?= $sel_quarter === $q ? 'selected' : '' ?>>Quarter <?= $q ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($current === null): ?>
          <div class="notice notice-info">Select a class above to view its gradebook.</div>
        <?php elseif (empty($roster)): ?>
          <p class="empty-state">No students currently enrolled in this section.</p>
        <?php else: ?>

          <div class="teacher-panel-block gb-cat-block">
            <div class="gb-cat-title">Add Item</div>
            <form method="POST" class="gb-add-item">
              <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
              <input type="hidden" name="section_id" value="<?= $sel_section ?>">
              <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
              <input type="hidden" name="quarter" value="<?= htmlspecialchars($sel_quarter) ?>">
              <select name="category" required>
                <?php foreach ($valid_categories as $catKey => $catLabel): ?>
                  <option value="<?= $catKey ?>"><?= htmlspecialchars($catLabel) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="title" placeholder="Item title (e.g. Seatwork 1)" required>
              <input type="number" name="max_score" placeholder="Max score" min="1" step="0.01" required>
              <select name="item_type" class="gb-item-type" onchange="var w=this.closest('form').querySelector('.gb-time-limit'); w.style.display = this.value==='quiz' ? '' : 'none';">
                <option value="manual">Manual score entry</option>
                <option value="submission">Accept file submissions</option>
                <option value="quiz">Online quiz (auto-graded)</option>
              </select>
              <input type="number" name="time_limit_minutes" class="gb-time-limit" placeholder="Time limit (min)" min="1" style="display:none;">
              <input type="datetime-local" name="due_date" title="Due date (optional)">
              <button type="submit" name="add_item" class="btn-sm">+ Add Item</button>
            </form>
          </div>

          <?php if (empty($allItems)): ?>
            <p class="empty-state" style="padding:.5rem 0;">No items yet.</p>
          <?php else: ?>
            <div class="gb-table-scroll">
              <table class="gb-score-table gb-item-table">
                <thead>
                  <tr>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Max Score</th>
                    <th>Due Date</th>
                    <th>Type</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allItems as $it):
                    $subs = $submissionsByItem[(int)$it['item_id']] ?? [];
                  ?>
                    <tr>
                      <td class="td-name"><?= htmlspecialchars($it['title']) ?></td>
                      <td><span class="gb-cat-badge gb-cat-badge-<?= htmlspecialchars($it['category']) ?>"><?= $catBadge[$it['category']] ?? '' ?></span> <?= htmlspecialchars($valid_categories[$it['category']] ?? '') ?></td>
                      <td><?= rtrim(rtrim(number_format((float)$it['max_score'], 2), '0'), '.') ?></td>
                      <td><?= $it['due_date'] ? date('M j, Y g:i A', strtotime($it['due_date'])) : '<span class="empty-state" style="padding:0;">—</span>' ?></td>
                      <td>
                        <?php if ($it['is_quiz']): ?>
                          Online Quiz
                        <?php elseif ($it['accepts_submission']): ?>
                          File Submission — <?= count($subs) ?> / <?= count($roster) ?> submitted
                        <?php else: ?>
                          Manual
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="gb-item-actions">
                          <?php if ($it['is_quiz']): ?>
                            <a href="teacher_quiz_builder?item_id=<?= (int)$it['item_id'] ?>&return=<?= urlencode($return_qs) ?>" class="gb-manage-link">Manage Questions →</a>
                          <?php endif; ?>
                          <button type="button" class="gb-item-edit" title="Edit"
                                  data-item-id="<?= (int)$it['item_id'] ?>"
                                  data-title="<?= htmlspecialchars($it['title'], ENT_QUOTES) ?>"
                                  data-max="<?= htmlspecialchars($it['max_score'], ENT_QUOTES) ?>">&#9998;</button>
                          <form method="POST" style="display:inline;" data-confirm="Delete &quot;<?= htmlspecialchars($it['title'], ENT_QUOTES) ?>&quot;? All scores entered for it will be deleted too." data-icon="warning">
                            <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                            <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                            <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                            <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                            <input type="hidden" name="quarter" value="<?= htmlspecialchars($sel_quarter) ?>">
                            <button type="submit" name="delete_item" class="gb-item-delete" title="Delete">&times;</button>
                          </form>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php foreach ($allItems as $it): if (!$it['accepts_submission']) continue;
              $subs = $submissionsByItem[(int)$it['item_id']] ?? [];
              if (empty($subs)) continue;
            ?>
              <div class="gb-submissions">
                <div class="gb-submissions-summary"><?= htmlspecialchars($it['title']) ?> — <?= count($subs) ?> / <?= count($roster) ?> submitted</div>
                <?php foreach ($subs as $sub): ?>
                  <div class="gb-submission-row">
                    <span><?= htmlspecialchars($sub['family_name'] . ', ' . $sub['given_name']) ?><?= $sub['is_late'] ? ' <span class="gb-late-badge">Late</span>' : '' ?></span>
                    <span>
                      <?= date('M j, g:i A', strtotime($sub['submitted_at'])) ?>
                      — <a href="submission_attachment?item_id=<?= (int)$it['item_id'] ?>&student_id=<?= (int)$sub['student_id'] ?>" target="_blank">View</a>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>

  <form method="POST" id="editItemForm" style="display:none;">
    <input type="hidden" name="edit_item" value="1">
    <input type="hidden" name="item_id" id="editItemId">
    <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
    <input type="hidden" name="section_id" value="<?= $sel_section ?>">
    <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
    <input type="hidden" name="quarter" value="<?= htmlspecialchars($sel_quarter) ?>">
    <input type="hidden" name="title" id="editItemTitle">
    <input type="hidden" name="max_score" id="editItemMax">
  </form>
  <script>
    document.querySelectorAll('.gb-item-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var currentTitle = btn.dataset.title;
        var currentMax = btn.dataset.max;
        Swal.fire({
          title: 'Edit item',
          html:
            '<input id="swalItemTitle" class="swal2-input" placeholder="Title" value="' + currentTitle.replace(/"/g, '&quot;') + '">' +
            '<input id="swalItemMax" class="swal2-input" type="number" min="1" step="0.01" placeholder="Max score" value="' + currentMax + '">',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Save',
          preConfirm: function () {
            var title = document.getElementById('swalItemTitle').value.trim();
            var max = parseFloat(document.getElementById('swalItemMax').value);
            if (!title || !max || max <= 0) {
              Swal.showValidationMessage('Please provide a title and a valid max score.');
              return false;
            }
            return { title: title, max: max };
          }
        }).then(function (result) {
          if (!result.isConfirmed) return;
          document.getElementById('editItemId').value = btn.dataset.itemId;
          document.getElementById('editItemTitle').value = result.value.title;
          document.getElementById('editItemMax').value = result.value.max;
          document.getElementById('editItemForm').submit();
        });
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
