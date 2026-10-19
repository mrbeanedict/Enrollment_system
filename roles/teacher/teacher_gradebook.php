<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$valid_quarters = ['1', '2', '3', '4'];
$valid_categories = ['written_work' => 'Written Work', 'performance_task' => 'Performance Task', 'quarterly_assessment' => 'Quarterly Assessment'];

$success = '';
$error   = '';

require_once BASE_PATH . '/shared/helpers/submissions.php';

// ── Grade turned-in work (score + optional written feedback) ────────────────
// Saves into gradebook_scores — the same row Grade Management's score cells
// write — so class grade rollups pick it up unchanged.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $item_id    = (int) ($_POST['item_id'] ?? 0);
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $score_raw  = trim((string) ($_POST['score'] ?? ''));
    $feedback   = trim((string) ($_POST['feedback'] ?? ''));
    $return_qs  = http_build_query([
        'subject_id' => (int) ($_POST['subject_id'] ?? 0), 'section_id' => (int) ($_POST['section_id'] ?? 0),
        'sy' => trim($_POST['school_year'] ?? ''), 'quarter' => $_POST['quarter'] ?? '1',
    ]);

    // This teacher's item, and work the student has actually turned in.
    $chk = $conn->prepare("
        SELECT gi.title, gi.max_score, gi.subject_id, gi.school_year, gsc.raw_score AS prev_score
        FROM gradebook_items gi
        JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ? AND gs.status = 'turned_in'
        LEFT JOIN gradebook_scores gsc ON gsc.item_id = gi.item_id AND gsc.student_id = gs.student_id
        WHERE gi.item_id = ? AND gi.teacher_id = ? AND gi.accepts_submission = 1
    ");
    $chk->bind_param('iii', $student_id, $item_id, $teacher_id);
    $chk->execute();
    $item = $chk->get_result()->fetch_assoc();
    $chk->close();

    $max = $item ? (float) $item['max_score'] : 0.0;
    if (!$item) {
        $_SESSION['gb_flash'] = 'That work isn\'t turned in (or isn\'t one of your assignments) — it can\'t be graded yet.';
        $_SESSION['gb_flash_type'] = 'error';
    } elseif ($score_raw === '' || !is_numeric($score_raw) || (float) $score_raw < 0 || (float) $score_raw > $max) {
        $_SESSION['gb_flash'] = 'Enter a score from 0 to ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . '.';
        $_SESSION['gb_flash_type'] = 'error';
    } elseif (mb_strlen($feedback) > SUB_COMMENT_MAX) {
        $_SESSION['gb_flash'] = 'Feedback can be up to ' . SUB_COMMENT_MAX . ' characters.';
        $_SESSION['gb_flash_type'] = 'error';
    } else {
        $score = round((float) $score_raw, 2);
        $fb    = $feedback === '' ? null : $feedback;
        $up = $conn->prepare("
            INSERT INTO gradebook_scores (item_id, student_id, raw_score, feedback, feedback_at, graded_by, graded_at)
            VALUES (?, ?, ?, ?, IF(? IS NULL, NULL, NOW()), ?, NOW())
            ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score), graded_by = VALUES(graded_by), graded_at = NOW(),
                feedback_at = IF(VALUES(feedback) <=> feedback, feedback_at, VALUES(feedback_at)),
                feedback = VALUES(feedback)
        ");
        $up->bind_param('iidssi', $item_id, $student_id, $score, $fb, $fb, $teacher_id);
        $up->execute();
        $up->close();

        $first  = $item['prev_score'] === null;
        $shown  = rtrim(rtrim(number_format($score, 2), '0'), '.') . ' / ' . rtrim(rtrim(number_format($max, 2), '0'), '.');
        $us = $conn->prepare("SELECT user_student_id FROM users_student WHERE student_id = ?");
        $us->bind_param('i', $student_id);
        $us->execute();
        $usid = (int) ($us->get_result()->fetch_row()[0] ?? 0);
        $us->close();
        if ($usid) {
            notify_student_users($conn, [$usid],
                ($first ? "Your work for \"{$item['title']}\" was graded: $shown." : "Your grade for \"{$item['title']}\" was updated: $shown.")
                    . ($fb !== null ? ' Your teacher left feedback.' : ''),
                'roles/lms/student_assignments?' . http_build_query(['subject_id' => $item['subject_id'], 'sy' => $item['school_year']]) . '#as-item-' . $item_id,
                'highlight');
        }
        $_SESSION['gb_flash'] = ($first ? 'Graded' : 'Grade updated') . " — $shown" . ($fb !== null ? ', with feedback.' : '.');
        $_SESSION['gb_flash_type'] = 'success';
    }
    header('Location: teacher_gradebook?' . $return_qs . '#gb-sub-' . $item_id . '-' . $student_id);
    exit();
}

// ── Private comment reply on a student's assignment ─────────────────────────
// Only on this teacher's own item, and only to a student who has already
// engaged with it (a submission or a comment of their own).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sub_comment'])) {
    $item_id    = (int) ($_POST['item_id'] ?? 0);
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $body       = trim($_POST['comment'] ?? '');
    $return_qs  = http_build_query([
        'subject_id' => (int) ($_POST['subject_id'] ?? 0), 'section_id' => (int) ($_POST['section_id'] ?? 0),
        'sy' => trim($_POST['school_year'] ?? ''), 'quarter' => $_POST['quarter'] ?? '1',
    ]);

    $chk = $conn->prepare("
        SELECT gi.title, gi.subject_id, gi.school_year FROM gradebook_items gi
        WHERE gi.item_id = ? AND gi.teacher_id = ? AND gi.accepts_submission = 1
          AND (EXISTS (SELECT 1 FROM gradebook_submissions gs WHERE gs.item_id = gi.item_id AND gs.student_id = ?)
            OR EXISTS (SELECT 1 FROM submission_comments sc WHERE sc.item_id = gi.item_id AND sc.student_id = ?))
    ");
    $chk->bind_param('iiii', $item_id, $teacher_id, $student_id, $student_id);
    $chk->execute();
    $item = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$item) {
        $_SESSION['gb_flash'] = 'That submission was not found among your assignments.';
        $_SESSION['gb_flash_type'] = 'error';
    } elseif ($body === '' || mb_strlen($body) > SUB_COMMENT_MAX) {
        $_SESSION['gb_flash'] = $body === '' ? 'Please write a comment.' : 'Private comments can be up to ' . SUB_COMMENT_MAX . ' characters.';
        $_SESSION['gb_flash_type'] = 'error';
    } else {
        sub_add_comment($conn, $item_id, $student_id, 'teacher', $teacher_id, $body);
        $us = $conn->prepare("SELECT user_student_id FROM users_student WHERE student_id = ?");
        $us->bind_param('i', $student_id);
        $us->execute();
        $usid = (int) ($us->get_result()->fetch_row()[0] ?? 0);
        $us->close();
        if ($usid) {
            notify_student_users($conn, [$usid], "Your teacher left a private comment on \"{$item['title']}\".",
                'roles/lms/student_assignments?' . http_build_query(['subject_id' => $item['subject_id'], 'sy' => $item['school_year']]) . '#as-item-' . $item_id);
        }
        $_SESSION['gb_flash'] = 'Private comment sent.';
        $_SESSION['gb_flash_type'] = 'success';
    }
    header('Location: teacher_gradebook?' . $return_qs . '#gb-sub-' . $item_id . '-' . $student_id);
    exit();
}

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
                    $is_quiz ? 'roles/lms/student_quizzes' : 'roles/lms/student_assignments'
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
      .gb-add-item select {
        height:32px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px;
        font-family:inherit; color:#1A1A2E; background:#FAFAFC; cursor:pointer;
      }
      .gb-add-item input, .gb-add-item select, .gb-add-item .btn-sm { transition:border-color .15s, box-shadow .15s, background .15s, opacity .15s; }
      .gb-add-item input:hover, .gb-add-item select:hover { border-color:#9DB8A9; }
      .gb-add-item input:focus, .gb-add-item select:focus {
        outline:none; border-color:var(--brand-primary, #1E4D3B); box-shadow:0 0 0 3px rgba(30,77,59,.15);
      }
      .gb-add-item input.is-invalid { border-color:#D14343; box-shadow:0 0 0 3px rgba(209,67,67,.12); }
      .gb-add-item .btn-sm {
        height:32px; padding:0 14px; border:0; border-radius:6px; font-size:12px; font-weight:600;
        font-family:inherit; background:var(--brand-primary, #1E4D3B); color:#fff; cursor:pointer;
      }
      .gb-add-item .btn-sm:hover:not(:disabled) { filter:brightness(1.12); }
      .gb-add-item .btn-sm:disabled { opacity:.45; cursor:not-allowed; }
      .gb-add-item .gb-time-limit { width:130px; }
      .gb-add-item input[name="due_date"] { width:170px; }
      @keyframes gb-row-flash { from { background:#FFF6CC; } to { background:transparent; } }
      .gb-item-table tr.gb-row-new td { animation:gb-row-flash 2.5s ease-out; }
      .gb-submission-toggle { display:flex; align-items:center; gap:4px; font-size:11px; color:#5A5A72; white-space:nowrap; }
      .gb-submission-toggle input { height:auto !important; }
      .gb-submissions { margin-top:6px; padding:8px 10px; background:#FAFAFC; border:0.5px solid #EBEBF0; border-radius:6px; font-size:11px; }
      .gb-submissions-summary { font-weight:600; color:#5A5A72; margin-bottom:4px; }
      .gb-submission-row { padding:8px 0; border-top:0.5px solid #EBEBF0; scroll-margin-top:80px; }
      .gb-submission-row:first-of-type { border-top:0; }
      .gb-sub-main { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; }
      .gb-sub-name { font-weight:600; color:#1A1A2E; font-size:12px; }
      .gb-sub-when { color:#8A8A9A; }
      .gb-sub-files { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
      .gb-sub-files a { padding:2px 8px; border:0.5px solid #D4D4E0; border-radius:999px; background:#fff; color:var(--brand-primary); text-decoration:none; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      .gb-sub-files a:hover { border-color:var(--brand-primary); }
      .gb-graded-badge { margin-left:6px; padding:1px 7px; border-radius:999px; background:#E6F4EC; color:#17663F; font-weight:700; font-size:10.5px; }
      .gb-grade { display:flex; gap:6px; align-items:flex-start; flex-wrap:wrap; margin-top:6px; }
      .gb-grade-score { display:flex; align-items:center; gap:5px; color:#5A5A72; font-weight:600; white-space:nowrap; }
      .gb-grade-score input { width:70px; height:30px !important; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 6px; font-size:12px; font-family:inherit; box-sizing:border-box; }
      .gb-grade textarea { flex:1; min-width:180px; min-height:30px; resize:vertical; border:0.5px solid #D4D4E0; border-radius:6px; padding:6px 8px; font-size:12px; font-family:inherit; box-sizing:border-box; }
      .gb-grade-btn { height:30px; background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }
      .gb-grade-btn:hover { background:var(--brand-primary-hover); color:#fff; }
      .gb-pc { margin-top:4px; }
      .gb-pc summary { cursor:pointer; color:#5A5A72; font-weight:600; }
      .gb-pc-new { margin-left:4px; padding:0 6px; border-radius:999px; background:var(--brand-primary); color:#fff; font-size:10px; }
      .gb-pc-item { margin:6px 0 0; padding:4px 8px; border-left:2px solid #D4D4E0; }
      .gb-pc-item.is-teacher { border-left-color:var(--brand-primary); }
      .gb-pc-meta { color:#8A8A9A; }
      .gb-pc-meta b { color:#1A1A2E; }
      .gb-pc-text { font-size:12px; color:#3A3A4A; white-space:pre-wrap; word-break:break-word; }
      .gb-pc-form { display:flex; gap:6px; margin-top:6px; }
      .gb-pc-form textarea { flex:1; min-height:32px; resize:vertical; border:0.5px solid #D4D4E0; border-radius:6px; padding:6px 8px; font-size:12px; font-family:inherit; box-sizing:border-box; }
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
        // Turned-in work only — drafts stay private to the student until
        // they turn in (see schema/submission_turn_in.sql).
        $submissionsByItem = [];
        $sub_stmt = $conn->prepare("
            SELECT gsub.submission_id, gsub.item_id, gsub.student_id, gsub.submitted_at, gsub.is_late, s.family_name, s.given_name
            FROM gradebook_submissions gsub
            JOIN gradebook_items gi ON gi.item_id = gsub.item_id
            JOIN students s ON s.student_id = gsub.student_id
            WHERE gi.subject_id=? AND gi.section_id=? AND gi.school_year=? AND gi.quarter=? AND gi.teacher_id=?
              AND gsub.status = 'turned_in'
            ORDER BY s.family_name, s.given_name
        ");
        $sub_stmt->bind_param('iissi', $sel_subject, $sel_section, $sel_sy, $sel_quarter, $teacher_id);
        $sub_stmt->execute();
        $sub_rows = $sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sub_stmt->close();
        foreach ($sub_rows as $row) {
            $submissionsByItem[(int) $row['item_id']][(int) $row['student_id']] = $row;
        }
        $filesBySubmission = sub_files_by_submission($conn, array_column($sub_rows, 'submission_id'));

        // Private comment threads per item. A student who commented but
        // hasn't turned in (or unsubmitted) still gets a row, so no comment
        // goes unseen.
        $commentsByItem = [];
        $extraStudents  = [];
        foreach ($items as $catItems) {
            foreach ($catItems as $ci) {
                if (!$ci['accepts_submission']) continue;
                $threads = sub_comments($conn, (int) $ci['item_id']);
                $commentsByItem[(int) $ci['item_id']] = $threads;
                foreach (array_keys($threads) as $tsid) {
                    if (!isset($submissionsByItem[(int) $ci['item_id']][$tsid])) $extraStudents[$tsid] = true;
                }
            }
        }
        // Current grade + feedback per item/student (pre-fills the Grade box).
        $gradesByItem = [];
        $subItemIds = array_keys($submissionsByItem);
        if ($subItemIds) {
            $gq = $conn->prepare("SELECT item_id, student_id, raw_score, feedback FROM gradebook_scores
                                  WHERE item_id IN (" . implode(',', array_fill(0, count($subItemIds), '?')) . ")");
            $gq->bind_param(str_repeat('i', count($subItemIds)), ...$subItemIds);
            $gq->execute();
            foreach ($gq->get_result()->fetch_all(MYSQLI_ASSOC) as $g) $gradesByItem[(int) $g['item_id']][(int) $g['student_id']] = $g;
            $gq->close();
        }

        $studentNames = [];
        if ($extraStudents) {
            $ids = array_keys($extraStudents);
            $nm = $conn->prepare("SELECT student_id, family_name, given_name FROM students WHERE student_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $nm->bind_param(str_repeat('i', count($ids)), ...$ids);
            $nm->execute();
            foreach ($nm->get_result()->fetch_all(MYSQLI_ASSOC) as $n) $studentNames[(int) $n['student_id']] = $n;
            $nm->close();
        }
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

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast><?= htmlspecialchars($flash) ?></div><?php endif; ?>

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
            <form method="POST" class="gb-add-item" id="gbAddItemForm" novalidate>
              <input type="hidden" name="add_item" value="1">
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
              <select name="item_type" class="gb-item-type">
                <option value="manual">Manual score entry</option>
                <option value="submission">Accept file submissions</option>
                <option value="quiz">Online quiz (auto-graded)</option>
              </select>
              <input type="number" name="time_limit_minutes" class="gb-time-limit" placeholder="Time limit (min)" min="1" style="display:none;">
              <input type="datetime-local" name="due_date" title="Due date (optional)">
              <button type="submit" class="btn-sm" disabled>+ Add Item</button>
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
              $iid     = (int)$it['item_id'];
              $subs    = $submissionsByItem[$iid] ?? [];
              $threads = $commentsByItem[$iid] ?? [];
              if (empty($subs) && empty($threads)) continue;
              // Turned-in students first (alphabetical), then comment-only ones.
              $rows = $subs;
              foreach ($threads as $tsid => $_) {
                  if (!isset($rows[$tsid]) && isset($studentNames[$tsid])) $rows[$tsid] = $studentNames[$tsid] + ['student_id' => $tsid, 'submission_id' => null];
              }
            ?>
              <div class="gb-submissions">
                <div class="gb-submissions-summary"><?= htmlspecialchars($it['title']) ?> — <?= count($subs) ?> / <?= count($roster) ?> turned in</div>
                <?php foreach ($rows as $sid => $sub):
                  $files  = $sub['submission_id'] ? ($filesBySubmission[(int)$sub['submission_id']] ?? []) : [];
                  $thread = $threads[$sid] ?? [];
                  $last   = $thread ? end($thread) : null;
                  $unread = $last && $last['author_type'] === 'student';
                  $grade  = $gradesByItem[$iid][$sid] ?? null;
                  $gscore = $grade && $grade['raw_score'] !== null ? rtrim(rtrim(number_format((float) $grade['raw_score'], 2), '0'), '.') : '';
                  $gmax   = rtrim(rtrim(number_format((float) $it['max_score'], 2), '0'), '.');
                ?>
                  <div class="gb-submission-row" id="gb-sub-<?= $iid ?>-<?= (int)$sid ?>">
                    <div class="gb-sub-main">
                      <span class="gb-sub-name"><?= htmlspecialchars($sub['family_name'] . ', ' . $sub['given_name']) ?><?= !empty($sub['is_late']) ? ' <span class="gb-late-badge">Late</span>' : '' ?></span>
                      <span class="gb-sub-when">
                        <?= $sub['submission_id'] ? 'Turned in ' . date('M j, g:i A', strtotime($sub['submitted_at'])) : 'Not turned in' ?>
                        <?php if ($gscore !== ''): ?><span class="gb-graded-badge">Graded <?= $gscore ?> / <?= $gmax ?></span><?php endif; ?>
                      </span>
                    </div>
                    <?php if ($files): ?>
                      <div class="gb-sub-files">
                        <?php foreach ($files as $f): ?>
                          <a href="submission_attachment?file_id=<?= (int)$f['file_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($f['original_filename']) ?></a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($sub['submission_id']): ?>
                      <form method="POST" class="gb-grade">
                        <input type="hidden" name="item_id" value="<?= $iid ?>">
                        <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
                        <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                        <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                        <input type="hidden" name="quarter" value="<?= htmlspecialchars($sel_quarter) ?>">
                        <label class="gb-grade-score">
                          <span>Grade</span>
                          <input type="number" name="score" min="0" max="<?= $gmax ?>" step="0.01" required value="<?= $gscore ?>"
                                 aria-label="Score for <?= htmlspecialchars($sub['given_name'] . ' ' . $sub['family_name']) ?>, out of <?= $gmax ?>">
                          <span>/ <?= $gmax ?></span>
                        </label>
                        <textarea name="feedback" rows="1" maxlength="<?= SUB_COMMENT_MAX ?>" placeholder="Feedback for <?= htmlspecialchars($sub['given_name']) ?> (optional)"
                                  aria-label="Feedback for <?= htmlspecialchars($sub['given_name'] . ' ' . $sub['family_name']) ?>"><?= htmlspecialchars($grade['feedback'] ?? '') ?></textarea>
                        <button type="submit" name="grade_submission" value="1" class="btn-sm gb-grade-btn"><?= $gscore !== '' ? 'Update grade' : 'Return grade' ?></button>
                      </form>
                    <?php endif; ?>
                    <details class="gb-pc" <?= $unread ? 'open' : '' ?>>
                      <summary>Private comments (<?= count($thread) ?>)<?= $unread ? ' <span class="gb-pc-new">student replied</span>' : '' ?></summary>
                      <?php foreach ($thread as $c): ?>
                        <div class="gb-pc-item <?= $c['author_type'] === 'teacher' ? 'is-teacher' : '' ?>">
                          <div class="gb-pc-meta"><b><?= $c['author_type'] === 'teacher' ? 'You' : htmlspecialchars($c['author_name']) ?></b> · <?= date('M j, g:i A', strtotime($c['created_at'])) ?></div>
                          <div class="gb-pc-text"><?= htmlspecialchars($c['body']) ?></div>
                        </div>
                      <?php endforeach; ?>
                      <form method="POST" class="gb-pc-form">
                        <input type="hidden" name="item_id" value="<?= $iid ?>">
                        <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
                        <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                        <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                        <input type="hidden" name="quarter" value="<?= htmlspecialchars($sel_quarter) ?>">
                        <textarea name="comment" rows="1" maxlength="<?= SUB_COMMENT_MAX ?>" required placeholder="Private comment to <?= htmlspecialchars($sub['given_name']) ?>…" aria-label="Private comment to <?= htmlspecialchars($sub['given_name'] . ' ' . $sub['family_name']) ?>"></textarea>
                        <button type="submit" name="sub_comment" value="1" class="btn-sm">Send</button>
                      </form>
                    </details>
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
    (function () {
      var form = document.getElementById('gbAddItemForm');
      if (!form) return;
      var title = form.elements.title, max = form.elements.max_score;
      var type = form.elements.item_type, limit = form.elements.time_limit_minutes;
      var btn = form.querySelector('.btn-sm');

      function titleOk() { return title.value.trim() !== ''; }
      function maxOk() { var v = parseFloat(max.value); return !isNaN(v) && v >= 1; }
      function limitOk() {
        if (type.value !== 'quiz' || limit.value === '') return true;
        return parseInt(limit.value, 10) >= 1;
      }
      function refresh() {
        btn.disabled = !(titleOk() && maxOk() && limitOk());
      }
      function flag(el, ok) { el.classList.toggle('is-invalid', !ok); }

      title.addEventListener('blur', function () { flag(title, titleOk()); });
      max.addEventListener('blur', function () { flag(max, maxOk()); });
      limit.addEventListener('blur', function () { flag(limit, limitOk()); });
      [title, max, limit].forEach(function (el) {
        el.addEventListener('input', function () {
          if (el.classList.contains('is-invalid')) flag(el, el === title ? titleOk() : el === max ? maxOk() : limitOk());
          refresh();
        });
      });

      type.addEventListener('change', function () {
        var isQuiz = type.value === 'quiz';
        limit.style.display = isQuiz ? '' : 'none';
        if (!isQuiz) { limit.value = ''; flag(limit, true); }
        else limit.focus();
        refresh();
      });

      form.addEventListener('submit', function (e) {
        if (btn.disabled) { e.preventDefault(); flag(title, titleOk()); flag(max, maxOk()); return; }
        btn.disabled = true;
        btn.textContent = 'Adding…';
      });

      // After the redirect, highlight the row that was just added (matched by
      // the flash toast text: "<title>" added.).
      var toast = document.querySelector('.notice-success[data-ui-toast]');
      var m = toast && toast.textContent.match(/^"(.*)" added\.$/);
      if (m) {
        var rows = document.querySelectorAll('.gb-item-table tbody tr');
        for (var i = rows.length - 1; i >= 0; i--) {
          var cell = rows[i].querySelector('.td-name');
          if (cell && cell.textContent === m[1]) {
            rows[i].classList.add('gb-row-new');
            rows[i].scrollIntoView({ block: 'center', behavior: 'smooth' });
            break;
          }
        }
      }
      refresh();
    })();

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
