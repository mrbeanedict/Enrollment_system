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

// Same upload convention as teacher_lessons.php, kept local since only
// this one page uploads assignment submissions.
const SUB_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip'];
const SUB_MAX_BYTES   = 15 * 1024 * 1024; // 15 MB — larger than a single lesson attachment

$flash      = '';
$flash_type = 'info';

// ── Submit (or resubmit) a file for one assignment ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_work']) && $is_student) {
    $item_id   = (int) ($_POST['item_id'] ?? 0);
    $return_qs = $_POST['return_qs'] ?? '';

    // Confirm this item belongs to a subject the student is actually
    // enrolled in (via their current section's offerings) and that it
    // genuinely accepts submissions — never trust the posted item_id blindly.
    $chk = $conn->prepare("
        SELECT gi.due_date, gi.title, gi.teacher_id
        FROM gradebook_items gi
        JOIN enrollments e ON e.section_id = gi.section_id AND e.school_year = gi.school_year
        WHERE gi.item_id = ? AND gi.accepts_submission = 1 AND e.student_id = ? AND e.status = 'enrolled'
        LIMIT 1
    ");
    $chk->bind_param('ii', $item_id, $student_id);
    $chk->execute();
    $item = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$item) {
        $_SESSION['as_flash'] = 'That assignment was not found among your subjects.';
        $_SESSION['as_flash_type'] = 'error';
    } else {
        $file_err = $_FILES['work']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($file_err === UPLOAD_ERR_NO_FILE) {
            $_SESSION['as_flash'] = 'Please choose a file to submit.';
            $_SESSION['as_flash_type'] = 'error';
        } elseif ($file_err !== UPLOAD_ERR_OK) {
            $_SESSION['as_flash'] = 'Could not upload the file. Please try again.';
            $_SESSION['as_flash_type'] = 'error';
        } else {
            $orig_name = $_FILES['work']['name'];
            $size      = (int) $_FILES['work']['size'];
            $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, SUB_ALLOWED_EXT, true) || $size > SUB_MAX_BYTES) {
                $_SESSION['as_flash'] = 'File must be PDF, Word, PowerPoint, JPG, PNG, or ZIP — 15 MB max.';
                $_SESSION['as_flash_type'] = 'error';
            } elseif (!upload_content_matches_ext($_FILES['work']['tmp_name'], $ext)) {
                $_SESSION['as_flash'] = 'This file\'s content does not match its extension. Please re-check the file.';
                $_SESSION['as_flash_type'] = 'error';
            } else {
                $stored_name = uniqid('submission_', true) . '.' . $ext;
                $dest = __DIR__ . '/../../uploads/gradebook_submissions/' . $stored_name;
                if (!move_uploaded_file($_FILES['work']['tmp_name'], $dest)) {
                    $_SESSION['as_flash'] = 'Could not save the file. Please try again.';
                    $_SESSION['as_flash_type'] = 'error';
                } else {
                    $is_late = ($item['due_date'] !== null && strtotime('now') > strtotime($item['due_date'])) ? 1 : 0;

                    // Remove any prior submission's file before the upsert —
                    // resubmitting replaces, it doesn't pile up orphaned files.
                    $old = $conn->prepare("SELECT file_path FROM gradebook_submissions WHERE item_id=? AND student_id=?");
                    $old->bind_param('ii', $item_id, $student_id);
                    $old->execute();
                    $old_row = $old->get_result()->fetch_assoc();
                    $old->close();
                    if ($old_row && $old_row['file_path']) {
                        $old_path = __DIR__ . '/../../uploads/gradebook_submissions/' . $old_row['file_path'];
                        if (is_file($old_path)) @unlink($old_path);
                    }

                    $ups = $conn->prepare("
                        INSERT INTO gradebook_submissions (item_id, student_id, file_path, original_filename, is_late)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), original_filename=VALUES(original_filename),
                            is_late=VALUES(is_late), submitted_at=NOW()
                    ");
                    $ups->bind_param('iissi', $item_id, $student_id, $stored_name, $orig_name, $is_late);
                    if ($ups->execute()) {
                        $_SESSION['as_flash'] = 'Work submitted.';
                        $_SESSION['as_flash_type'] = 'success';

                        $sa_tu_stmt = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
                        $sa_tu_stmt->bind_param('i', $item['teacher_id']);
                        $sa_tu_stmt->execute();
                        $sa_tu_row = $sa_tu_stmt->get_result()->fetch_assoc();
                        $sa_tu_stmt->close();
                        notify_teacher_users(
                            $conn,
                            [(int) ($sa_tu_row['user_id'] ?? 0)],
                            'A student submitted work for "' . $item['title'] . '".',
                            'roles/teacher/teacher_grade_management'
                        );
                    } else {
                        $_SESSION['as_flash'] = 'Could not save the submission. Please try again.';
                        $_SESSION['as_flash_type'] = 'error';
                    }
                    $ups->close();
                }
            }
        }
    }
    header("Location: student_assignments?" . $return_qs);
    exit();
}

// ── Remove a submission (undo) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_submission']) && $is_student) {
    $item_id   = (int) ($_POST['item_id'] ?? 0);
    $return_qs = $_POST['return_qs'] ?? '';

    // Same ownership check as submitting — never trust the posted item_id.
    $chk = $conn->prepare("
        SELECT gsub.submission_id, gsub.file_path
        FROM gradebook_items gi
        JOIN enrollments e ON e.section_id = gi.section_id AND e.school_year = gi.school_year
        JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
        WHERE gi.item_id = ? AND gi.accepts_submission = 1 AND e.student_id = ? AND e.status = 'enrolled'
        LIMIT 1
    ");
    $chk->bind_param('iii', $student_id, $item_id, $student_id);
    $chk->execute();
    $sub = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$sub) {
        $_SESSION['as_flash'] = 'That submission was not found.';
        $_SESSION['as_flash_type'] = 'error';
    } else {
        // A graded submission can't be silently pulled out from under an
        // already-recorded score — the teacher would be left grading a
        // file that no longer exists.
        $gr = $conn->prepare("SELECT 1 FROM gradebook_scores WHERE item_id=? AND student_id=? AND raw_score IS NOT NULL");
        $gr->bind_param('ii', $item_id, $student_id);
        $gr->execute();
        $alreadyGraded = (bool) $gr->get_result()->fetch_row();
        $gr->close();

        if ($alreadyGraded) {
            $_SESSION['as_flash'] = 'This assignment has already been graded and can\'t be removed — contact your teacher if you need to resubmit.';
            $_SESSION['as_flash_type'] = 'error';
        } else {
            $del = $conn->prepare("DELETE FROM gradebook_submissions WHERE submission_id=?");
            $del->bind_param('i', $sub['submission_id']);
            $del->execute();
            $del->close();

            if ($sub['file_path']) {
                $path = __DIR__ . '/../../uploads/gradebook_submissions/' . $sub['file_path'];
                if (is_file($path)) @unlink($path);
            }
            $_SESSION['as_flash'] = 'Submission removed. You can upload again before the due date.';
            $_SESSION['as_flash_type'] = 'success';
        }
    }
    header("Location: student_assignments?" . $return_qs);
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
      .as-item { border-bottom:1px solid var(--lms-border-2); padding:12px 0; }
      .as-item:last-child { border-bottom:none; }
      .as-item-title { font-weight:600; font-size:14px; color:#1A1A2E; }
      .as-item-meta { font-size:11px; color:#8A8A9A; margin:2px 0 8px; }
      .as-overdue { color:#C0392B; font-weight:600; }
      .as-form { display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; }
      .btn-sm { height:32px; padding:0 12px; border:1px solid var(--lms-border); border-radius:6px; background:#fff; font-size:12px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--lms-ink); color:var(--lms-ink); }
      .as-remove-btn:hover { border-color:#E0A8A8; color:#C0392B; }
      .as-submitted { font-size:12px; color:#1A6B4A; background:#EBF7F2; border:0.5px solid #A8D9C5; border-radius:6px; padding:6px 10px; display:inline-flex; align-items:center; gap:8px; }
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
                SELECT gi.item_id, gi.title, gi.due_date, gi.category,
                       sub.subject_name,
                       gsub.submitted_at, gsub.is_late, gsub.original_filename
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1 AND gi.subject_id = ?
                ORDER BY gi.due_date IS NULL, gi.due_date, gi.item_id
            ");
            mysqli_stmt_bind_param($it_stmt, "iiisi", $selected_sem, $student_id, $sec_id, $selected_sy, $subject_id);
        } else {
            $it_stmt = mysqli_prepare($conn, "
                SELECT gi.item_id, gi.title, gi.due_date, gi.category,
                       sub.subject_name,
                       gsub.submitted_at, gsub.is_late, gsub.original_filename
                FROM gradebook_items gi
                JOIN subjects sub ON sub.subject_id = gi.subject_id
                JOIN section_subjects ss ON ss.section_id = gi.section_id AND ss.subject_id = gi.subject_id AND ss.semester = ?
                LEFT JOIN gradebook_submissions gsub ON gsub.item_id = gi.item_id AND gsub.student_id = ?
                WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
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

        <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" style="margin-bottom:.75rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

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
            <p class="empty-state">No assignments open for submission this semester.</p>
          <?php else: ?>
            <?php foreach ($items_by_subject as $grp_subject_name => $subj_items): ?>
              <?php if ($subject_id <= 0): ?>
                <div class="as-subject-title"><?= htmlspecialchars($grp_subject_name) ?></div>
              <?php endif; ?>
              <?php foreach ($subj_items as $it):
                $isOverdue = $it['due_date'] && strtotime($it['due_date']) < time() && !$it['submitted_at'];
              ?>
                <div class="as-item">
                  <div class="as-item-title"><?= htmlspecialchars($it['title']) ?></div>
                  <div class="as-item-meta">
                    <?= $it['due_date'] ? 'Due ' . date('M j, Y g:i A', strtotime($it['due_date'])) : 'No due date' ?>
                    <?php if ($isOverdue): ?> <span class="as-overdue">— Overdue</span><?php endif; ?>
                  </div>

                  <?php if ($it['submitted_at']): $fi = file_icon_meta($it['original_filename'] ?? ''); ?>
                    <div class="as-submitted">
                      ✓ Submitted <?= date('M j, Y g:i A', strtotime($it['submitted_at'])) ?>
                      <?php if ($it['is_late']): ?><span class="as-late-tag">Late</span><?php endif; ?>
                      <a href="submission_attachment?item_id=<?= (int)$it['item_id'] ?>" target="_blank" class="attach-chip">
                        <span class="attach-icon <?= $fi['cls'] ?>"><?= htmlspecialchars($fi['label']) ?></span>
                        <span class="attach-chip-name"><?= htmlspecialchars($it['original_filename'] ?? 'View') ?></span>
                      </a>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="as-form" style="margin-top:6px;">
                      <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                      <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                      <div class="attach-upload-control">
                        <label class="attach-dropzone" for="work<?= (int)$it['item_id'] ?>">
                          <input type="file" id="work<?= (int)$it['item_id'] ?>" name="work" required hidden>
                          <svg class="attach-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
                          <span class="attach-dropzone-text"><strong>Click to upload</strong> or drag and drop</span>
                          <span class="field-hint">PDF, Word, PowerPoint, JPG, PNG, or ZIP — 15 MB max.</span>
                        </label>
                        <div class="attach-file-card" style="display:none;">
                          <div class="attach-icon"></div>
                          <div class="attach-file-body">
                            <span class="attach-file-name"></span>
                            <div class="attach-file-meta"></div>
                            <div class="attach-file-actions">
                              <button type="button" class="attach-change-btn">Change</button>
                              <button type="button" class="attach-remove-btn">Remove</button>
                            </div>
                          </div>
                        </div>
                        <button type="button" class="attach-add-btn" title="Multiple attachments per submission are coming soon">+ Add attachment</button>
                      </div>
                      <button type="submit" name="submit_work" class="btn-sm">Replace Submission</button>
                    </form>
                    <form method="POST" class="as-form" style="margin-top:6px;" data-confirm="Remove your submission? You can upload again before the due date." data-icon="warning">
                      <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                      <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                      <button type="submit" name="remove_submission" class="btn-sm as-remove-btn">Remove Submission</button>
                    </form>
                  <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" class="as-form">
                      <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                      <input type="hidden" name="return_qs" value="<?= htmlspecialchars($return_qs) ?>">
                      <div class="attach-upload-control">
                        <label class="attach-dropzone" for="work<?= (int)$it['item_id'] ?>">
                          <input type="file" id="work<?= (int)$it['item_id'] ?>" name="work" required hidden>
                          <svg class="attach-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
                          <span class="attach-dropzone-text"><strong>Click to upload</strong> or drag and drop</span>
                          <span class="field-hint">PDF, Word, PowerPoint, JPG, PNG, or ZIP — 15 MB max.</span>
                        </label>
                        <div class="attach-file-card" style="display:none;">
                          <div class="attach-icon"></div>
                          <div class="attach-file-body">
                            <span class="attach-file-name"></span>
                            <div class="attach-file-meta"></div>
                            <div class="attach-file-actions">
                              <button type="button" class="attach-change-btn">Change</button>
                              <button type="button" class="attach-remove-btn">Remove</button>
                            </div>
                          </div>
                        </div>
                        <button type="button" class="attach-add-btn" title="Multiple attachments per submission are coming soon">+ Add attachment</button>
                      </div>
                      <button type="submit" name="submit_work" class="btn-sm">Submit</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <script>
    // ── Attachment dropzone (click-to-browse + drag-and-drop) ─────────────
    // Purely a nicer picker for the same <input type="file"> the form
    // already posts — no separate upload step, the file still travels with
    // the rest of the form on submit.
    document.querySelectorAll('.attach-upload-control').forEach(function (control) {
      var input    = control.querySelector('input[type="file"]');
      var dropzone = control.querySelector('.attach-dropzone');
      var card     = control.querySelector('.attach-file-card');
      if (!input || !dropzone || !card) return;

      var icon      = card.querySelector('.attach-icon');
      var nameEl    = card.querySelector('.attach-file-name');
      var metaEl    = card.querySelector('.attach-file-meta');
      var changeBtn = card.querySelector('.attach-change-btn');
      var removeBtn = card.querySelector('.attach-remove-btn');

      function iconMeta(filename) {
        var ext = (filename.split('.').pop() || '').toUpperCase();
        var cls = 'attach-icon-generic';
        if (ext === 'PDF') cls = 'attach-icon-pdf';
        else if (['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP'].indexOf(ext) !== -1) cls = 'attach-icon-img';
        else if (['DOC', 'DOCX'].indexOf(ext) !== -1) cls = 'attach-icon-doc';
        else if (['PPT', 'PPTX'].indexOf(ext) !== -1) cls = 'attach-icon-ppt';
        return { label: ext, cls: cls };
      }

      function showFile(file) {
        var meta = iconMeta(file.name);
        icon.textContent = meta.label;
        icon.className = 'attach-icon ' + meta.cls;
        nameEl.textContent = file.name;
        metaEl.textContent = (file.size / (1024 * 1024)).toFixed(1) + ' MB';
        dropzone.style.display = 'none';
        card.style.display = '';
      }

      function reset() {
        dropzone.style.display = '';
        card.style.display = 'none';
      }

      input.addEventListener('change', function () {
        if (input.files && input.files.length > 0) {
          showFile(input.files[0]);
        } else {
          reset();
        }
      });

      if (changeBtn) changeBtn.addEventListener('click', function () { input.click(); });
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          input.value = '';
          reset();
        });
      }

      ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
          e.preventDefault();
          dropzone.classList.add('is-dragover');
        });
      });
      ['dragleave', 'dragend'].forEach(function (evt) {
        dropzone.addEventListener(evt, function () { dropzone.classList.remove('is-dragover'); });
      });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        var dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length) {
          input.files = dt.files;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });

      reset();
    });

    // Prevent a file dropped outside a dropzone from navigating the browser
    // away from the page.
    ['dragover', 'drop'].forEach(function (evt) {
      window.addEventListener(evt, function (e) { e.preventDefault(); });
    });
  </script>
<?php endif; ?>
</body>
</html>
