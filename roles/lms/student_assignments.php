<?php
// Assignments belongs to the Student LMS (see lms_sidebar.php) —
// always use that session, fixed, rather than guessing between
// STUDENT_SESSID and STUDENT_LMS_SESSID. A student can be validly
// signed into both at once; this page must never flip to Student
// Portal branding just because an unrelated Student Portal session
// also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

$is_student = require_login('student', null, 'ignore', 'bool');
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;

require_once BASE_PATH . '/shared/helpers/lms_class.php';
require_once BASE_PATH . '/shared/helpers/submissions.php';
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
require_once BASE_PATH . '/shared/helpers/lms_tasks.php';

// Work on an assignment moves Assigned → Draft (files attached) → Turned in,
// and Unsubmit moves it back to Draft with the files kept — see
// schema/submission_turn_in.sql. Up to SUB_MAX_FILES files, SUB_MAX_BYTES
// in total, per assignment.

// The assignment, only if it accepts submissions and belongs to a class the
// student actually takes (same rule as Stream/Discussions) — never trust the
// posted item_id blindly. Also returns the student's submission, if any.
function as_item(mysqli $conn, int $item_id, int $student_id): ?array {
    $st = $conn->prepare("
        SELECT gi.item_id, gi.title, gi.due_date, gi.teacher_id, gi.subject_id, gi.section_id, gi.school_year, gi.quarter,
               gsub.submission_id, gsub.status
        FROM gradebook_items gi
        LEFT JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
        WHERE gi.item_id = ? AND gi.accepts_submission = 1
    ");
    $st->bind_param('ii', $student_id, $item_id);
    $st->execute();
    $item = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$item || !disc_student_owns_class($conn, $student_id, (int) $item['subject_id'], (int) $item['section_id'], $item['school_year'])) {
        return null;
    }
    return $item;
}

function as_flash(string $msg, string $type = 'success'): void {
    $_SESSION['as_flash'] = $msg;
    $_SESSION['as_flash_type'] = $type;
}

// Tell the assignment's teacher, linking to the gradebook where they review it.
function as_notify_teacher(mysqli $conn, array $item, string $message): void {
    $t = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
    $t->bind_param('i', $item['teacher_id']);
    $t->execute();
    $uid = (int) ($t->get_result()->fetch_row()[0] ?? 0);
    $t->close();
    if ($uid) {
        notify_teacher_users($conn, [$uid], $message, 'roles/teacher/teacher_gradebook?' . http_build_query([
            'subject_id' => $item['subject_id'], 'section_id' => $item['section_id'], 'sy' => $item['school_year'], 'quarter' => $item['quarter'],
        ]));
    }
}

function as_student_name(mysqli $conn, int $student_id): string {
    $s = $conn->prepare("SELECT CONCAT(given_name, ' ', family_name) FROM students WHERE student_id = ?");
    $s->bind_param('i', $student_id);
    $s->execute();
    $n = (string) ($s->get_result()->fetch_row()[0] ?? 'A student');
    $s->close();
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_student && array_intersect(['add_files', 'remove_file', 'turn_in', 'unsubmit', 'private_comment'], array_keys($_POST))) {
    $item_id   = (int) ($_POST['item_id'] ?? 0);
    $return_qs = $_POST['return_qs'] ?? '';
    $item      = as_item($conn, $item_id, $student_id);
    $sub_id    = $item ? (int) ($item['submission_id'] ?? 0) : 0;
    $turned_in = $item && $item['status'] === 'turned_in';

    if (!$item) {
        as_flash('That assignment was not found among your subjects.', 'error');

    // ── Attach files (draft only) ────────────────────────────────────────
    } elseif (isset($_POST['add_files'])) {
        $up = $_FILES['work'] ?? null;
        $names = $up ? (array) $up['name'] : [];
        $picked = [];
        foreach ($names as $i => $n) {
            if ((int) ((array) $up['error'])[$i] === UPLOAD_ERR_NO_FILE) continue;
            $picked[] = ['name' => $n, 'tmp' => ((array) $up['tmp_name'])[$i], 'size' => (int) ((array) $up['size'])[$i], 'error' => (int) ((array) $up['error'])[$i]];
        }

        $existing = $sub_id ? (sub_files_by_submission($conn, [$sub_id])[$sub_id] ?? []) : [];
        $used     = array_sum(array_map('sub_file_size', $existing));
        $adding   = array_sum(array_column($picked, 'size'));

        $err = '';
        if ($turned_in) {
            $err = 'This work is already turned in — unsubmit it first to change your files.';
        } elseif (!$picked) {
            $err = 'Please choose a file to attach.';
        } elseif (count($existing) + count($picked) > SUB_MAX_FILES) {
            $err = 'You can attach up to ' . SUB_MAX_FILES . ' files per assignment (' . count($existing) . ' already attached).';
        } elseif ($used + $adding > SUB_MAX_BYTES) {
            $err = 'Files for one assignment can total ' . sub_format_bytes(SUB_MAX_BYTES) . ' at most — you have ' . sub_format_bytes(SUB_MAX_BYTES - $used) . ' left.';
        } else {
            foreach ($picked as $p) {
                $ext = strtolower(pathinfo($p['name'], PATHINFO_EXTENSION));
                if ($p['error'] !== UPLOAD_ERR_OK) { $err = 'Could not upload "' . $p['name'] . '". Please try again.'; break; }
                if (!in_array($ext, SUB_ALLOWED_EXT, true)) { $err = '"' . $p['name'] . '" isn\'t allowed — use PDF, Word, PowerPoint, JPG, PNG, or ZIP.'; break; }
                if (!upload_content_matches_ext($p['tmp'], $ext)) { $err = '"' . $p['name'] . '" doesn\'t match its extension. Please re-check the file.'; break; }
            }
        }

        if ($err !== '') {
            as_flash($err, 'error');
        } else {
            if (!$sub_id) {
                // Draft row; LAST_INSERT_ID trick returns the id on a race-duplicate too.
                $ins = $conn->prepare("INSERT INTO gradebook_submissions (item_id, student_id, status) VALUES (?, ?, 'draft')
                                       ON DUPLICATE KEY UPDATE submission_id = LAST_INSERT_ID(submission_id)");
                $ins->bind_param('ii', $item_id, $student_id);
                $ins->execute();
                $sub_id = (int) $conn->insert_id;
                $ins->close();
            }
            $saved = 0;
            foreach ($picked as $p) {
                $ext    = strtolower(pathinfo($p['name'], PATHINFO_EXTENSION));
                $stored = uniqid('submission_', true) . '.' . $ext;
                if (!move_uploaded_file($p['tmp'], sub_upload_dir() . $stored)) continue;
                $f = $conn->prepare("INSERT INTO gradebook_submission_files (submission_id, file_path, original_filename, file_size) VALUES (?, ?, ?, ?)");
                $f->bind_param('issi', $sub_id, $stored, $p['name'], $p['size']);
                $f->execute();
                $f->close();
                $saved++;
            }
            $saved === count($picked)
                ? as_flash($saved === 1 ? 'File attached. Turn in when you\'re ready.' : "$saved files attached. Turn in when you're ready.")
                : as_flash('Some files could not be saved. Please try again.', 'error');
        }

    // ── Remove one attached file (draft only) ────────────────────────────
    } elseif (isset($_POST['remove_file'])) {
        $file_id = (int) ($_POST['file_id'] ?? 0);
        $f = $conn->prepare("SELECT f.file_path FROM gradebook_submission_files f
                             JOIN gradebook_submissions gs ON gs.submission_id = f.submission_id
                             WHERE f.file_id = ? AND gs.submission_id = ? AND gs.student_id = ? AND gs.status = 'draft'");
        $f->bind_param('iii', $file_id, $sub_id, $student_id);
        $f->execute();
        $file = $f->get_result()->fetch_assoc();
        $f->close();
        if ($turned_in) {
            as_flash('This work is already turned in — unsubmit it first to change your files.', 'error');
        } elseif (!$file) {
            as_flash('That file was not found.', 'error');
        } else {
            $d = $conn->prepare("DELETE FROM gradebook_submission_files WHERE file_id = ?");
            $d->bind_param('i', $file_id);
            $d->execute();
            $d->close();
            $path = sub_upload_dir() . $file['file_path'];
            if (is_file($path)) @unlink($path);
            as_flash('File removed.');
        }

    // ── Turn in (with an optional private comment) ───────────────────────
    } elseif (isset($_POST['turn_in'])) {
        $comment = trim($_POST['comment'] ?? '');
        $nfiles  = $sub_id ? count(sub_files_by_submission($conn, [$sub_id])[$sub_id] ?? []) : 0;
        if ($turned_in) {
            as_flash('This work is already turned in.', 'error');
        } elseif ($nfiles === 0) {
            as_flash('Attach at least one file before turning in.', 'error');
        } elseif (mb_strlen($comment) > SUB_COMMENT_MAX) {
            as_flash('Private comments can be up to ' . SUB_COMMENT_MAX . ' characters.', 'error');
        } else {
            $is_late = ($item['due_date'] !== null && time() > strtotime($item['due_date'])) ? 1 : 0;
            $u = $conn->prepare("UPDATE gradebook_submissions SET status = 'turned_in', submitted_at = NOW(), is_late = ?
                                 WHERE submission_id = ? AND student_id = ? AND status = 'draft'");
            $u->bind_param('iii', $is_late, $sub_id, $student_id);
            $u->execute();
            $ok = $u->affected_rows > 0;
            $u->close();
            if (!$ok) {
                as_flash('Could not turn in your work. Please try again.', 'error');
            } else {
                if ($comment !== '') sub_add_comment($conn, $item_id, $student_id, 'student', null, $comment);
                $who = as_student_name($conn, $student_id);
                as_notify_teacher($conn, $item, "$who turned in work for \"{$item['title']}\"" . ($is_late ? ' (late)' : '') . ($comment !== '' ? ' with a private comment.' : '.'));
                as_flash($is_late ? 'Turned in — marked late.' : 'Turned in.');
            }
        }

    // ── Unsubmit (back to draft, files kept) ─────────────────────────────
    } elseif (isset($_POST['unsubmit'])) {
        if (!$turned_in) {
            as_flash('This work isn\'t turned in.', 'error');
        } elseif (sub_is_graded($conn, $item_id, $student_id)) {
            as_flash('This work has already been graded and can\'t be unsubmitted — send your teacher a private comment if you need to change it.', 'error');
        } else {
            $u = $conn->prepare("UPDATE gradebook_submissions SET status = 'draft', submitted_at = NULL, is_late = 0
                                 WHERE submission_id = ? AND student_id = ? AND status = 'turned_in'");
            $u->bind_param('ii', $sub_id, $student_id);
            $u->execute();
            $u->close();
            as_flash('Unsubmitted. Your files are kept — make your changes, then turn in again' . ($item['due_date'] ? ' before the due date.' : '.'));
        }

    // ── Private comment to the teacher ───────────────────────────────────
    } elseif (isset($_POST['private_comment'])) {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment === '') {
            as_flash('Please write a comment.', 'error');
        } elseif (mb_strlen($comment) > SUB_COMMENT_MAX) {
            as_flash('Private comments can be up to ' . SUB_COMMENT_MAX . ' characters.', 'error');
        } else {
            sub_add_comment($conn, $item_id, $student_id, 'student', null, $comment);
            as_notify_teacher($conn, $item, as_student_name($conn, $student_id) . " left a private comment on \"{$item['title']}\".");
            as_flash('Private comment sent to your teacher.');
        }
    }

    header('Location: student_assignments?' . $return_qs . '#as-item-' . $item_id);
    exit();
}

// A POST bigger than post_max_size arrives with $_POST/$_FILES emptied — say
// so instead of silently reloading the page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_student && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    as_flash('Those files are too large to upload at once — an assignment can hold ' . sub_format_bytes(SUB_MAX_BYTES) . ' in total.', 'error');
    header('Location: student_assignments?' . ($_SERVER['QUERY_STRING'] ?? ''));
    exit();
}

$flash      = $_SESSION['as_flash']      ?? '';
$flash_type = $_SESSION['as_flash_type'] ?? 'info';
unset($_SESSION['as_flash'], $_SESSION['as_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      .as-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
      .as-card { border:1px solid var(--lms-border); border-radius:14px; background:#fff; margin-bottom:14px; overflow:hidden; scroll-margin-top:80px; }
      .as-card:target { border-color:var(--st-primary); box-shadow:0 0 0 3px rgba(56,102,65,.15); }
      .as-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:14px 16px 10px; }
      .as-card-tags { flex:none; display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
      .as-item-title { margin:0; font-weight:650; font-size:15px; color:#1A1A2E; }
      .as-item-meta { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:12px; color:var(--lms-muted-2); margin-top:4px; }
      .as-status { flex:none; font-size:11.5px; font-weight:700; padding:4px 10px; border-radius:999px; white-space:nowrap; }
      .as-status-ok    { background:#E6F4EC; color:#17663F; }
      .as-status-late  { background:#FCEED3; color:#7A4300; }
      .as-status-bad   { background:#FCE8E6; color:#A5221B; }
      .as-status-draft { background:var(--lms-cream); color:var(--lms-ink); }
      .as-status-muted { background:#F1F3F2; color:var(--lms-muted); }

      .as-grade { display:flex; gap:14px; align-items:flex-start; margin:0 16px 12px; padding:12px 14px; border-radius:10px; background:#EEF7F1; border:1px solid #BFE0CB; }
      .as-grade-score { flex:none; display:flex; align-items:baseline; gap:4px; color:#17663F; }
      .as-grade-score b { font-size:24px; font-weight:750; line-height:1; }
      .as-grade-score span { font-size:13px; font-weight:600; }
      .as-grade-body { min-width:0; flex:1; }
      .as-grade-meta { font-size:12px; font-weight:600; color:#17663F; margin-bottom:3px; }
      .as-grade-feedback { font-size:13.5px; line-height:1.5; color:#1F3A2B; white-space:pre-wrap; word-break:break-word; }
      .as-grade-none { font-size:12.5px; color:var(--lms-muted-2); }
      .as-work { margin:0 16px 14px; padding:12px 14px; border:1px solid var(--lms-border-2); border-radius:10px; background:var(--lms-surface-soft); }
      .as-work-head { display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
      .as-work-head h4, .as-private h4 { margin:0; font-size:12px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--lms-muted); }
      .as-quota { font-size:12px; color:var(--lms-muted-2); }
      .as-files { list-style:none; margin:0 0 10px; padding:0; display:flex; flex-direction:column; gap:6px; }
      .as-files li { display:flex; align-items:center; gap:8px; min-width:0; }
      .as-files .attach-chip { min-width:0; max-width:100%; background:#fff; }
      .as-file-size { flex:none; font-size:12px; color:var(--lms-muted-2); }
      .as-file-remove button { width:28px; height:28px; border:0; border-radius:50%; background:transparent; color:var(--lms-muted); font-size:18px; line-height:1; cursor:pointer; }
      .as-file-remove button:hover { background:#FCE8E6; color:#A5221B; }
      .as-add { margin-bottom:10px; }
      .as-add .attach-dropzone { padding:14px; }
      .attach-dropzone.is-uploading { opacity:.6; pointer-events:none; }
      .as-add-error { margin:6px 0 0; font-size:12.5px; color:#A5221B; }

      .as-turnin label { display:block; font-size:12.5px; font-weight:600; color:#1A1A2E; margin-bottom:4px; }
      .as-turnin label span { font-weight:400; color:var(--lms-muted-2); }
      .as-turnin textarea, .as-pc-form textarea { width:100%; box-sizing:border-box; resize:vertical; border:1px solid var(--lms-border); border-radius:8px;
        background:#fff; padding:8px 10px; font-size:13.5px; font-family:inherit; color:#1A1A2E; }
      .as-turnin textarea:focus, .as-pc-form textarea:focus { outline:none; border-color:var(--lms-ink); }
      .as-turnin-row { display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap; }
      .as-hint { font-size:12px; color:var(--lms-muted-2); margin-right:auto; }
      .as-btn-primary { height:34px; padding:0 18px; border:0; border-radius:8px; background:var(--lms-ink); color:#fff; font-size:13px; font-weight:600; font-family:inherit; cursor:pointer; }
      .as-btn-primary:hover { background:var(--lms-ink-hover); }
      .as-btn-primary:disabled, .btn-sm:disabled { opacity:.45; cursor:not-allowed; }
      .as-turned { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; font-size:13px; color:#17663F; font-weight:600; }
      .as-turned .as-hint { margin-right:0; font-weight:400; }

      .as-private { margin:0 16px 14px; }
      .as-private h4 { display:flex; align-items:center; gap:6px; margin-bottom:6px; }
      .as-private h4 span { font-weight:400; text-transform:none; letter-spacing:0; color:var(--lms-muted-2); }
      .as-pc { padding:7px 10px; border-left:3px solid var(--lms-border); margin-bottom:6px; }
      .as-pc.is-teacher { border-left-color:var(--lms-ink); background:var(--lms-surface-soft); }
      .as-pc-meta { font-size:12px; color:var(--lms-muted-2); }
      .as-pc-meta b { color:#1A1A2E; }
      .as-pc-text { font-size:13.5px; color:#34423B; white-space:pre-wrap; word-break:break-word; }
      .as-pc-form { display:flex; gap:8px; align-items:flex-start; }
      .as-pc-form .btn-sm { flex:none; }
      .btn-sm { height:32px; padding:0 12px; border:1px solid var(--lms-border); border-radius:6px; background:#fff; font-size:12px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--lms-ink); color:var(--lms-ink); }
      .as-late-tag { color:#C0392B; font-weight:600; }
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
    // assignment list to one subject and drives the subj-tabstrip below.
    $subject_id   = (int) ($_GET['subject_id'] ?? 0);
    $subject_name = null;

    $return_qs = http_build_query(array_filter([
        'sy'         => $selected_sy,
        'sem'        => $selected_sem,
        'subject_id' => $subject_id ?: null,
    ]));

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
                SELECT gi.item_id, gi.title, gi.due_date, gi.category, gi.max_score,
                       sub.subject_name,
                       gsub.submission_id, gsub.status, gsub.submitted_at, gsub.is_late,
                       gsc.raw_score, gsc.feedback, gsc.graded_at, CONCAT(gt.given_name, ' ', gt.family_name) AS graded_by_name
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
                LEFT JOIN gradebook_scores gsc ON gsc.item_id = gi.item_id AND gsc.student_id = gsub.student_id
                LEFT JOIN teachers gt ON gt.teacher_id = gsc.graded_by
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1 AND gi.subject_id = ?
                ORDER BY gi.due_date IS NULL, gi.due_date, gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiisi", $selected_sem, $student_id, $sec_id, $selected_sy, $subject_id);
        } else {
            $it_stmt = mysqli_prepare($conn, "
                SELECT gi.item_id, gi.title, gi.due_date, gi.category, gi.max_score,
                       sub.subject_name,
                       gsub.submission_id, gsub.status, gsub.submitted_at, gsub.is_late,
                       gsc.raw_score, gsc.feedback, gsc.graded_at, CONCAT(gt.given_name, ' ', gt.family_name) AS graded_by_name
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
                LEFT JOIN gradebook_scores gsc ON gsc.item_id = gi.item_id AND gsc.student_id = gsub.student_id
                LEFT JOIN teachers gt ON gt.teacher_id = gsc.graded_by
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
                ORDER BY sub.subject_name, gi.due_date IS NULL, gi.due_date, gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiis", $selected_sem, $student_id, $sec_id, $selected_sy);
        }
        mysqli_stmt_execute($it_stmt);
        $all_items = mysqli_fetch_all(mysqli_stmt_get_result($it_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($it_stmt);
        foreach ($all_items as $row) {
            $items_by_subject[$row['subject_name']][] = $row;
            $subject_name = $row['subject_name'];
        }

        // Attached files and private comment threads for the listed items.
        $files_by    = sub_files_by_submission($conn, array_column($all_items, 'submission_id'));
        $comments_by = [];
        foreach ($all_items as $row) {
            $comments_by[(int) $row['item_id']] = sub_comments($conn, (int) $row['item_id'], $student_id)[$student_id] ?? [];
        }
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
          Assignments
          <span class="student-topbar-subtitle">Submit your work for each class</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7-8h.01M5 6a2 2 0 012-2h10a2 2 0 012 2v14l-3-2-3 2-3-2-3 2V6z"/></svg></span>
            <div class="student-panel-title">Assignments</div>
          </div>
        </div>

        <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast style="margin-bottom:.75rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

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
            <p class="empty-state">No assignments open for submission this semester.</p>
          <?php else:
            $nTurned  = count(array_filter($all_items, fn($r) => $r['status'] === 'turned_in' || $r['raw_score'] !== null));
            $nMissing = count(array_filter($all_items, fn($r) => $r['status'] !== 'turned_in' && $r['raw_score'] === null && $r['due_date'] && strtotime($r['due_date']) < time()));
          ?>
            <div class="lms-summary">
              <span class="lms-summary-count"><?= $nTurned ?> of <?= count($all_items) ?> turned in<?= $nMissing ? ' · ' . $nMissing . ' missing' : '' ?></span>
              <div class="hm-chips" role="group" aria-label="Filter assignments" data-filter-list="asList">
                <button type="button" class="hm-chip is-on" data-filter="all" aria-pressed="true">All</button>
                <button type="button" class="hm-chip" data-filter="todo" aria-pressed="false">To do</button>
                <?php if ($nMissing): ?><button type="button" class="hm-chip" data-filter="missing" aria-pressed="false">Missing</button><?php endif; ?>
                <button type="button" class="hm-chip" data-filter="done" aria-pressed="false">Turned in</button>
                <button type="button" class="hm-chip" data-filter="graded" aria-pressed="false">Graded</button>
              </div>
            </div>
            <div class="hm-progress" aria-hidden="true"><span style="transform:scaleX(<?= $nTurned / count($all_items) ?>)"></span></div>

            <div id="asList">
            <?php foreach ($items_by_subject as $grp_subject_name => $subj_items): ?>
              <?php if ($subject_id <= 0): ?>
                <h3 class="lms-group-title" data-filter-group><span class="hm-tile" aria-hidden="true"><?= subject_banner_icon($grp_subject_name) ?></span><?= htmlspecialchars($grp_subject_name) ?><span class="count"><?= count($subj_items) ?></span></h3>
              <?php endif; ?>
              <div>
              <?php foreach ($subj_items as $it):
                $iid      = (int) $it['item_id'];
                $sid      = (int) ($it['submission_id'] ?? 0);
                $files    = $sid ? ($files_by[$sid] ?? []) : [];
                $nfiles   = count($files);
                $turned   = $it['status'] === 'turned_in';
                $graded   = $it['raw_score'] !== null;
                $due_ts   = $it['due_date'] ? strtotime($it['due_date']) : null;
                $past_due = $due_ts !== null && $due_ts < time();
                $used     = array_sum(array_map('sub_file_size', $files));
                $thread   = $comments_by[$iid] ?? [];
                $num      = fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
                if ($graded)            [$chip, $tone] = ['Graded · ' . $num($it['raw_score']) . ' / ' . $num($it['max_score']), 'ok'];
                elseif ($turned)        [$chip, $tone] = [$it['is_late'] ? 'Turned in late' : 'Turned in', $it['is_late'] ? 'late' : 'ok'];
                elseif ($past_due)      [$chip, $tone] = ['Missing', 'bad'];
                elseif ($nfiles)        [$chip, $tone] = ['Draft · not turned in', 'draft'];
                else                    [$chip, $tone] = ['Assigned', 'muted'];
                $kind   = lms_task_kind($it);
                $status = $graded ? 'done graded' : ($turned ? 'done' : ($past_due ? 'todo missing' : 'todo'));
                $dl     = (!$turned && !$graded && $due_ts) ? lms_due_short($it['due_date']) : '';
              ?>
                <article class="as-card" id="as-item-<?= $iid ?>" data-status="<?= $status ?>">
                  <div class="as-card-head">
                    <div>
                      <h3 class="as-item-title"><?= htmlspecialchars($it['title']) ?></h3>
                      <div class="as-item-meta">
                        <span class="hm-kind <?= lms_kind_class($kind) ?>"><?= $kind ?></span>
                        <?= $due_ts ? 'Due ' . date('M j, Y g:i A', $due_ts) : 'No due date' ?>
                      </div>
                    </div>
                    <div class="as-card-tags">
                      <?php if ($dl && $dl !== 'Overdue'): ?><span class="hm-todo-due<?= lms_due_tone($dl) ?>"><?= $dl ?></span><?php endif; ?>
                      <span class="as-status as-status-<?= $tone ?>"><?= htmlspecialchars($chip) ?></span>
                    </div>
                  </div>

                  <?php if ($graded || ($it['feedback'] ?? null) !== null): ?>
                    <section class="as-grade" aria-label="Grade and feedback">
                      <div class="as-grade-score">
                        <?php if ($graded): ?>
                          <b><?= $num($it['raw_score']) ?></b><span>/ <?= $num($it['max_score']) ?></span>
                        <?php else: ?>
                          <b>—</b><span>not scored yet</span>
                        <?php endif; ?>
                      </div>
                      <div class="as-grade-body">
                        <div class="as-grade-meta">
                          <?= $graded ? 'Graded' : 'Feedback' ?><?= $it['graded_by_name'] ? ' by ' . htmlspecialchars($it['graded_by_name']) : '' ?><?= $it['graded_at'] ? ' · ' . date('M j, Y g:i A', strtotime($it['graded_at'])) : '' ?>
                        </div>
                        <?php if (($it['feedback'] ?? null) !== null): ?>
                          <div class="as-grade-feedback"><?= htmlspecialchars($it['feedback']) ?></div>
                        <?php else: ?>
                          <div class="as-grade-none">No written feedback.</div>
                        <?php endif; ?>
                      </div>
                    </section>
                  <?php endif; ?>

                  <section class="as-work" aria-label="Your work">
                    <div class="as-work-head">
                      <h4>Your work</h4>
                      <span class="as-quota"><?= $nfiles ?> of <?= SUB_MAX_FILES ?> files · <?= sub_format_bytes($used) ?> of <?= sub_format_bytes(SUB_MAX_BYTES) ?></span>
                    </div>

                    <?php if ($files): ?>
                      <ul class="as-files">
                        <?php foreach ($files as $f): $fi = file_icon_meta($f['original_filename']); ?>
                          <li>
                            <a href="submission_attachment?file_id=<?= (int) $f['file_id'] ?>" target="_blank" rel="noopener" class="attach-chip">
                              <span class="attach-icon <?= $fi['cls'] ?>"><?= htmlspecialchars($fi['label']) ?></span>
                              <span class="attach-chip-name"><?= htmlspecialchars($f['original_filename']) ?></span>
                            </a>
                            <span class="as-file-size"><?= sub_format_bytes(sub_file_size($f)) ?></span>
                            <?php if (!$turned): ?>
                              <form method="POST" class="as-file-remove">
                                <input type="hidden" name="item_id" value="<?= $iid ?>">
                                <input type="hidden" name="file_id" value="<?= (int) $f['file_id'] ?>">
                                <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                                <button type="submit" name="remove_file" value="1" aria-label="Remove <?= htmlspecialchars($f['original_filename']) ?>" title="Remove">&times;</button>
                              </form>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>

                    <?php if (!$turned): ?>
                      <?php if ($nfiles < SUB_MAX_FILES && $used < SUB_MAX_BYTES): $slots = SUB_MAX_FILES - $nfiles; $left = SUB_MAX_BYTES - $used; ?>
                        <form method="POST" enctype="multipart/form-data" class="as-add">
                          <input type="hidden" name="item_id" value="<?= $iid ?>">
                          <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                          <input type="hidden" name="add_files" value="1">
                          <label class="attach-dropzone" for="work<?= $iid ?>">
                            <input type="file" id="work<?= $iid ?>" name="work[]" multiple hidden data-auto-upload
                                   data-slots="<?= $slots ?>" data-left="<?= $left ?>"
                                   accept=".<?= implode(',.', SUB_ALLOWED_EXT) ?>">
                            <svg class="attach-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
                            <span class="attach-dropzone-text"><strong><?= $nfiles ? 'Add another file' : 'Add files' ?></strong> or drag and drop</span>
                            <span class="field-hint">PDF, Word, PowerPoint, JPG, PNG, or ZIP · up to <?= $slots ?> more <?= $slots === 1 ? 'file' : 'files' ?>, <?= sub_format_bytes($left) ?> left</span>
                          </label>
                          <p class="as-add-error" role="alert" hidden></p>
                          <noscript><button type="submit" class="btn-sm">Upload</button></noscript>
                        </form>
                      <?php endif; ?>

                      <form method="POST" class="as-turnin">
                        <input type="hidden" name="item_id" value="<?= $iid ?>">
                        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                        <label for="as-c-<?= $iid ?>">Private comment to your teacher <span>(optional)</span></label>
                        <textarea id="as-c-<?= $iid ?>" name="comment" rows="2" maxlength="<?= SUB_COMMENT_MAX ?>" placeholder="Anything your teacher should know about this work"></textarea>
                        <div class="as-turnin-row">
                          <?php if (!$nfiles): ?><span class="as-hint">Attach at least one file to turn in.</span><?php endif; ?>
                          <button type="submit" name="private_comment" value="1" class="btn-sm" data-needs-text>Send comment only</button>
                          <button type="submit" name="turn_in" value="1" class="as-btn-primary" <?= $nfiles ? '' : 'disabled' ?>><?= $past_due ? 'Turn in late' : 'Turn in' ?></button>
                        </div>
                      </form>
                    <?php else: ?>
                      <div class="as-turned">
                        <span>
                          Turned in <?= date('M j, Y g:i A', strtotime($it['submitted_at'])) ?>
                          <?php if ($it['is_late']): ?><span class="as-late-tag">· Late</span><?php endif; ?>
                        </span>
                        <?php if ($graded): ?>
                          <span class="as-hint">Graded — send your teacher a private comment if something needs to change.</span>
                        <?php else: ?>
                          <form method="POST" data-confirm="Unsubmit this work? Your files stay attached — turn in again when you're done<?= $due_ts ? ', ideally before the due date' : '' ?>." data-icon="question">
                            <input type="hidden" name="item_id" value="<?= $iid ?>">
                            <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                            <button type="submit" name="unsubmit" value="1" class="btn-sm">Unsubmit</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </section>

                  <?php if ($thread || $turned): ?>
                    <section class="as-private" aria-label="Private comments">
                      <h4>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        Private comments <span>only you and your teacher can see these</span>
                      </h4>
                      <?php foreach ($thread as $c): ?>
                        <div class="as-pc <?= $c['author_type'] === 'teacher' ? 'is-teacher' : '' ?>">
                          <div class="as-pc-meta"><b><?= htmlspecialchars($c['author_type'] === 'teacher' ? $c['author_name'] : 'You') ?></b><?= $c['author_type'] === 'teacher' ? ' · Teacher' : '' ?> · <?= date('M j, g:i A', strtotime($c['created_at'])) ?></div>
                          <div class="as-pc-text"><?= htmlspecialchars($c['body']) ?></div>
                        </div>
                      <?php endforeach; ?>
                      <?php if ($turned): ?>
                        <form method="POST" class="as-pc-form">
                          <input type="hidden" name="item_id" value="<?= $iid ?>">
                          <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                          <label class="as-sr" for="as-pc-<?= $iid ?>">Add a private comment</label>
                          <textarea id="as-pc-<?= $iid ?>" name="comment" rows="1" maxlength="<?= SUB_COMMENT_MAX ?>" placeholder="Add a private comment…" required></textarea>
                          <button type="submit" name="private_comment" value="1" class="btn-sm" data-needs-text>Post</button>
                        </form>
                      <?php endif; ?>
                    </section>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            </div>
            <p class="hm-empty lms-filter-none" hidden>Nothing in this filter.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <script>
    // ── Add files: picking (or dropping) files uploads them straight away,
    //    after the same limits the server enforces are checked here first. ──
    document.querySelectorAll('input[data-auto-upload]').forEach(function (input) {
      var form     = input.form;
      var dropzone = form.querySelector('.attach-dropzone');
      var errEl    = form.querySelector('.as-add-error');
      var slots    = parseInt(input.dataset.slots, 10);
      var left     = parseInt(input.dataset.left, 10);
      var allowed  = (input.getAttribute('accept') || '').split(',').map(function (x) { return x.replace('.', '').toLowerCase(); });
      var mb = function (b) { return (b / (1024 * 1024)).toFixed(1).replace(/\.0$/, '') + ' MB'; };

      function fail(msg) { errEl.textContent = msg; errEl.hidden = false; input.value = ''; }

      input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        errEl.hidden = true;
        if (!files.length) return;
        if (files.length > slots) return fail('You can add ' + slots + ' more ' + (slots === 1 ? 'file' : 'files') + ' to this assignment.');
        var total = 0;
        for (var i = 0; i < files.length; i++) {
          var ext = (files[i].name.split('.').pop() || '').toLowerCase();
          if (allowed.indexOf(ext) === -1) return fail('"' + files[i].name + '" isn’t allowed — use PDF, Word, PowerPoint, JPG, PNG, or ZIP.');
          total += files[i].size;
        }
        if (total > left) return fail('That’s ' + mb(total) + ' — only ' + mb(left) + ' left for this assignment.');
        dropzone.classList.add('is-uploading');
        dropzone.querySelector('.attach-dropzone-text').innerHTML = '<strong>Uploading…</strong>';
        form.submit();
      });

      ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.add('is-dragover'); });
      });
      ['dragleave', 'dragend'].forEach(function (evt) {
        dropzone.addEventListener(evt, function () { dropzone.classList.remove('is-dragover'); });
      });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
          input.files = e.dataTransfer.files;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });

    // "Send comment only" / "Post" stay disabled until there's text.
    document.querySelectorAll('[data-needs-text]').forEach(function (btn) {
      var ta = btn.form.querySelector('textarea[name="comment"]');
      var sync = function () { btn.disabled = !ta.value.trim(); };
      ta.addEventListener('input', sync);
      sync();
    });

    // One click per submit — no double turn-ins / duplicate comments.
    document.querySelectorAll('.as-turnin, .as-pc-form, .as-file-remove').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (f.dataset.busy === '1') { e.preventDefault(); return; }
        f.dataset.busy = '1';
      });
    });

    // A file dropped outside a dropzone must not navigate the browser away.
    ['dragover', 'drop'].forEach(function (evt) {
      window.addEventListener(evt, function (e) { e.preventDefault(); });
    });
  </script>
  <script src="<?= APP_URL ?>/assets/js/lms_filter.js?v=<?= filemtime(BASE_PATH . '/assets/js/lms_filter.js') ?>"></script>
<?php endif; ?>
</body>
</html>
