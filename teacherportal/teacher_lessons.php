<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';
include_once '../notify.php';

// Shared by every "new content posted" handler in this file — resolves
// the user_student_id of every actively-enrolled student taking this
// exact subject/section/school year, so they can be notified.
function lesson_notify_targets(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
    $stmt = $conn->prepare("
        SELECT us.user_student_id
        FROM enrollment_subjects es
        JOIN enrollments e ON e.enrollment_id = es.enrollment_id
        JOIN users_student us ON us.student_id = e.student_id
        WHERE es.subject_id = ? AND es.section_id = ? AND e.school_year = ? AND e.status = 'enrolled'
    ");
    $stmt->bind_param('iis', $subject_id, $section_id, $sy);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_student_id');
    $stmt->close();
    return $ids;
}

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../teacherportal/teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);

// Same upload convention as req_doc_constraints() in config.php, but kept
// local here since only this one page uploads lesson attachments.
const LESSON_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
const LESSON_MAX_BYTES   = 10 * 1024 * 1024; // 10 MB — teaching materials run larger than requirement scans

// Verify a class belongs to this teacher — shared by every handler below,
// same "never trust the posted subject/section pair blindly" guard used
// throughout the gradebook.
function lesson_owns_class(mysqli $conn, int $teacher_id, int $subject_id, int $section_id, string $sy): bool {
    $stmt = $conn->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=? AND is_active=1");
    $stmt->bind_param('iiis', $teacher_id, $subject_id, $section_id, $sy);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

// ── Post a new lesson ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_lesson'])) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (!lesson_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['ls_flash'] = 'That class was not found among your assignments.';
        $_SESSION['ls_flash_type'] = 'error';
    } elseif ($title === '' || $body === '') {
        $_SESSION['ls_flash'] = 'Please provide a title and lesson content.';
        $_SESSION['ls_flash_type'] = 'error';
    } else {
        $stored_name = null;
        $orig_name   = null;
        $file_err = $_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($file_err !== UPLOAD_ERR_NO_FILE) {
            if ($file_err !== UPLOAD_ERR_OK) {
                $_SESSION['ls_flash'] = 'Could not upload the attachment. Please try again.';
                $_SESSION['ls_flash_type'] = 'error';
            } else {
                $orig_name = $_FILES['attachment']['name'];
                $size      = (int) $_FILES['attachment']['size'];
                $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (!in_array($ext, LESSON_ALLOWED_EXT, true) || $size > LESSON_MAX_BYTES) {
                    $_SESSION['ls_flash'] = 'Attachment must be PDF, Word, PowerPoint, JPG, or PNG — 10 MB max.';
                    $_SESSION['ls_flash_type'] = 'error';
                } else {
                    $stored_name = uniqid('lesson_', true) . '.' . $ext;
                    $dest = __DIR__ . '/../uploads/lesson_attachments/' . $stored_name;
                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                        $_SESSION['ls_flash'] = 'Could not save the attachment. Please try again.';
                        $_SESSION['ls_flash_type'] = 'error';
                        $stored_name = null;
                    }
                }
            }
        }

        if (empty($_SESSION['ls_flash'])) {
            $ins = $conn->prepare("
                INSERT INTO lessons (teacher_id, subject_id, section_id, school_year, title, body, attachment_path, attachment_original_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param('iiisssss', $teacher_id, $subject_id, $section_id, $sy, $title, $body, $stored_name, $orig_name);
            if ($ins->execute()) {
                $_SESSION['ls_flash'] = "\"$title\" posted.";
                $_SESSION['ls_flash_type'] = 'success';
                notify_student_users(
                    $conn,
                    lesson_notify_targets($conn, $subject_id, $section_id, $sy),
                    "New lesson posted: \"$title\".",
                    'learningportal/student_lessons'
                );
            } else {
                $_SESSION['ls_flash'] = 'Could not post the lesson. Please try again.';
                $_SESSION['ls_flash_type'] = 'error';
            }
            $ins->close();
        }
    }
    header("Location: teacher_lessons?" . $return_qs);
    exit();
}

// ── Edit a lesson's title/body ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_lesson'])) {
    $lesson_id  = (int) ($_POST['lesson_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if ($title === '' || $body === '') {
        $_SESSION['ls_flash'] = 'Please provide a title and lesson content.';
        $_SESSION['ls_flash_type'] = 'error';
    } else {
        $upd = $conn->prepare("UPDATE lessons SET title=?, body=?, updated_at=NOW() WHERE lesson_id=? AND teacher_id=?");
        $upd->bind_param('ssii', $title, $body, $lesson_id, $teacher_id);
        $upd->execute();
        if ($upd->affected_rows > 0) {
            $_SESSION['ls_flash'] = "\"$title\" updated.";
            $_SESSION['ls_flash_type'] = 'success';
        } else {
            $_SESSION['ls_flash'] = 'Could not update — lesson not found among your assignments.';
            $_SESSION['ls_flash_type'] = 'error';
        }
        $upd->close();
    }
    header("Location: teacher_lessons?" . $return_qs);
    exit();
}

// ── Delete a lesson (and its attachment file, if any) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_lesson'])) {
    $lesson_id  = (int) ($_POST['lesson_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    $chk = $conn->prepare("SELECT attachment_path FROM lessons WHERE lesson_id=? AND teacher_id=?");
    $chk->bind_param('ii', $lesson_id, $teacher_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        $_SESSION['ls_flash'] = 'Could not delete — lesson not found among your assignments.';
        $_SESSION['ls_flash_type'] = 'error';
    } else {
        $del = $conn->prepare("DELETE FROM lessons WHERE lesson_id=? AND teacher_id=?");
        $del->bind_param('ii', $lesson_id, $teacher_id);
        $del->execute();
        $del->close();
        if ($row['attachment_path']) {
            $full_path = __DIR__ . '/../uploads/lesson_attachments/' . $row['attachment_path'];
            if (is_file($full_path)) @unlink($full_path);
        }
        $_SESSION['ls_flash'] = 'Lesson deleted.';
        $_SESSION['ls_flash_type'] = 'success';
    }
    header("Location: teacher_lessons?" . $return_qs);
    exit();
}

$flash      = $_SESSION['ls_flash']      ?? '';
$flash_type = $_SESSION['ls_flash_type'] ?? 'info';
unset($_SESSION['ls_flash'], $_SESSION['ls_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lessons — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../css/css_teacher.css') ?>">
    <style>
      .ls-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .ls-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .ls-post-form { display:flex; flex-direction:column; gap:8px; margin-bottom:1.25rem; }
      .ls-post-form input[type="text"], .ls-post-form textarea {
        border:0.5px solid #D4D4E0; border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; box-sizing:border-box;
      }
      .ls-post-form textarea { min-height:90px; resize:vertical; }
      .btn-primary-ls { height:38px; padding:0 18px; background:var(--brand-primary); border:none; border-radius:8px; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; align-self:flex-start; }
      .btn-primary-ls:hover { background:var(--brand-primary-hover); }
      .ls-item { border-bottom:0.5px solid #EBEBF0; padding:12px 0; }
      .ls-item:last-child { border-bottom:none; }
      .ls-item-title { font-weight:600; font-size:14px; color:#1A1A2E; }
      .ls-item-meta { font-size:11px; color:#8A8A9A; margin:2px 0 6px; }
      .ls-item-body { font-size:13px; color:#3A3A4A; white-space:pre-wrap; margin-bottom:6px; }
      .ls-item-attachment { font-size:12px; }
      .ls-item-actions { display:flex; gap:8px; margin-top:6px; }
      .btn-sm { height:28px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px; background:#fff; font-size:11px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
      .btn-sm.danger:hover { border-color:#F5C6C2; color:#C0392B; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'teacher_sidebar.php';

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

    $current = null;
    foreach ($classes as $c) {
        if ((int) $c['subject_id'] === $sel_subject && (int) $c['section_id'] === $sel_section && $c['school_year'] === $sel_sy) {
            $current = $c;
            break;
        }
    }
    if ($current === null && !empty($classes)) {
        $current = $classes[0];
        $sel_subject = (int) $current['subject_id'];
        $sel_section = (int) $current['section_id'];
        $sel_sy      = $current['school_year'];
    }

    $lessons = [];
    if ($current !== null) {
        $ls_stmt = $conn->prepare("
            SELECT lesson_id, title, body, attachment_path, attachment_original_name, posted_at, updated_at
            FROM lessons
            WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=?
            ORDER BY posted_at DESC
        ");
        $ls_stmt->bind_param('iiis', $teacher_id, $sel_subject, $sel_section, $sel_sy);
        $ls_stmt->execute();
        $lessons = $ls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ls_stmt->close();
    }
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Lessons
          <span class="teacher-topbar-subtitle">Post lesson content for each class</span>
        </div>
      </div>
      <?php include 'teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if (empty($classes)): ?>
        <div class="notice notice-info">No class assignments found yet.</div>
      <?php else: ?>

        <?php
          // Lessons aren't semester-partitioned — a class taught across
          // both semesters (a common case) still shows once here.
          $class_options = [];
          foreach ($classes as $c) {
              $key = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year'];
              if (!isset($class_options[$key])) { $class_options[$key] = $c; }
          }
        ?>
        <form method="GET" class="ls-picker">
          <select name="class" onchange="var v=this.value.split('|'); location.href='teacher_lessons?subject_id='+v[0]+'&section_id='+v[1]+'&sy='+encodeURIComponent(v[2]);">
            <?php foreach ($class_options as $c): $val = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year']; ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= ((int)$c['subject_id']===$sel_subject && (int)$c['section_id']===$sel_section && $c['school_year']===$sel_sy) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section_name']) ?> (SY <?= htmlspecialchars($c['school_year']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($current === null): ?>
          <div class="notice notice-info">Select a class above to view its lessons.</div>
        <?php else: ?>

          <div class="teacher-panel-block">
            <form method="POST" enctype="multipart/form-data" class="ls-post-form">
              <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
              <input type="hidden" name="section_id" value="<?= $sel_section ?>">
              <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
              <input type="text" name="title" placeholder="Lesson title" required>
              <textarea name="body" placeholder="Lesson content" required></textarea>
              <input type="file" name="attachment" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
              <button type="submit" name="post_lesson" class="btn-primary-ls">Post Lesson</button>
            </form>

            <?php if (empty($lessons)): ?>
              <p class="empty-state">No lessons posted yet for this class.</p>
            <?php else: ?>
              <?php foreach ($lessons as $l): ?>
                <div class="ls-item">
                  <div class="ls-item-title"><?= htmlspecialchars($l['title']) ?></div>
                  <div class="ls-item-meta">
                    Posted <?= date('M j, Y g:i A', strtotime($l['posted_at'])) ?>
                    <?= $l['updated_at'] ? ' · edited ' . date('M j, Y g:i A', strtotime($l['updated_at'])) : '' ?>
                  </div>
                  <div class="ls-item-body"><?= nl2br(htmlspecialchars($l['body'])) ?></div>
                  <?php if ($l['attachment_path']): ?>
                    <div class="ls-item-attachment">
                      <a href="lesson_attachment?id=<?= (int)$l['lesson_id'] ?>" target="_blank">📎 <?= htmlspecialchars($l['attachment_original_name']) ?></a>
                    </div>
                  <?php endif; ?>
                  <div class="ls-item-actions">
                    <button type="button" class="btn-sm ls-edit"
                            data-lesson-id="<?= (int)$l['lesson_id'] ?>"
                            data-title="<?= htmlspecialchars($l['title'], ENT_QUOTES) ?>"
                            data-body="<?= htmlspecialchars($l['body'], ENT_QUOTES) ?>">Edit</button>
                    <form method="POST" style="display:inline;" data-confirm="Delete &quot;<?= htmlspecialchars($l['title'], ENT_QUOTES) ?>&quot;?" data-icon="warning">
                      <input type="hidden" name="lesson_id" value="<?= (int)$l['lesson_id'] ?>">
                      <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                      <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                      <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                      <button type="submit" name="delete_lesson" class="btn-sm danger">Delete</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>

  <form method="POST" id="editLessonForm" style="display:none;">
    <input type="hidden" name="edit_lesson" value="1">
    <input type="hidden" name="lesson_id" id="editLessonId">
    <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
    <input type="hidden" name="section_id" value="<?= $sel_section ?>">
    <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
    <input type="hidden" name="title" id="editLessonTitle">
    <input type="hidden" name="body" id="editLessonBody">
  </form>
  <script>
    document.querySelectorAll('.ls-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        Swal.fire({
          title: 'Edit lesson',
          html: '<input id="swalLsTitle" class="swal2-input" placeholder="Title"><textarea id="swalLsBody" class="swal2-textarea" placeholder="Lesson content"></textarea>',
          didOpen: function () {
            // Set via the DOM property, not interpolated into the html
            // string above — the lesson title/body can contain arbitrary
            // characters (quotes, angle brackets), so this avoids any risk
            // of breaking out of the markup.
            document.getElementById('swalLsTitle').value = btn.dataset.title;
            document.getElementById('swalLsBody').value = btn.dataset.body;
          },
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Save',
          preConfirm: function () {
            var title = document.getElementById('swalLsTitle').value.trim();
            var body = document.getElementById('swalLsBody').value.trim();
            if (!title || !body) {
              Swal.showValidationMessage('Please provide a title and lesson content.');
              return false;
            }
            return { title: title, body: body };
          }
        }).then(function (result) {
          if (!result.isConfirmed) return;
          document.getElementById('editLessonId').value = btn.dataset.lessonId;
          document.getElementById('editLessonTitle').value = result.value.title;
          document.getElementById('editLessonBody').value = result.value.body;
          document.getElementById('editLessonForm').submit();
        });
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
