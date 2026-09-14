<?php
// Discussions belongs to the Student LMS (see lms_sidebar.php) —
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

// Confirm the student is currently enrolled in this exact subject+section+
// school_year — never trust the posted values blindly.
function disc_student_owns_class(mysqli $conn, int $student_id, int $subject_id, int $section_id, string $sy): bool {
    $stmt = $conn->prepare("
        SELECT 1 FROM enrollments e
        JOIN section_subjects ss ON ss.section_id = e.section_id AND ss.subject_id = ?
        WHERE e.student_id = ? AND e.section_id = ? AND e.school_year = ? AND e.status = 'enrolled'
        LIMIT 1
    ");
    $stmt->bind_param('iiis', $subject_id, $student_id, $section_id, $sy);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

// Every teacher currently assigned to this exact subject/section/school
// year — not the thread's own created_by_teacher_id, which is null for
// student-started threads.
function disc_teacher_targets(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
    $stmt = $conn->prepare("
        SELECT t.user_id FROM teacher_assignments ta
        JOIN teachers t ON t.teacher_id = ta.teacher_id
        WHERE ta.subject_id = ? AND ta.section_id = ? AND ta.school_year = ? AND ta.is_active = 1
    ");
    $stmt->bind_param('iis', $subject_id, $section_id, $sy);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
    $stmt->close();
    return $ids;
}

// ── Start a new thread ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_thread']) && $is_student) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (!disc_student_owns_class($conn, $student_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That class was not found among your subjects.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($title === '' || $body === '') {
        $_SESSION['dc_flash'] = 'Please provide a thread title and an opening message.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO discussion_threads (subject_id, section_id, school_year, title, created_by_type, created_by_student_id)
                VALUES (?, ?, ?, ?, 'student', ?)
            ");
            $ins->bind_param('iissi', $subject_id, $section_id, $sy, $title, $student_id);
            $ins->execute();
            $thread_id = $ins->insert_id;
            $ins->close();

            $insp = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_student_id, body) VALUES (?, 'student', ?, ?)");
            $insp->bind_param('iis', $thread_id, $student_id, $body);
            $insp->execute();
            $insp->close();

            $conn->commit();
            $return_qs .= '&thread_id=' . $thread_id;
            $_SESSION['dc_flash'] = "\"$title\" started.";
            $_SESSION['dc_flash_type'] = 'success';
            notify_teacher_users(
                $conn,
                disc_teacher_targets($conn, $subject_id, $section_id, $sy),
                "New discussion thread: \"$title\".",
                'roles/teacher/teacher_discussions'
            );
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dc_flash'] = 'Could not start the thread. Please try again.';
            $_SESSION['dc_flash_type'] = 'error';
        }
    }
    header("Location: student_discussions?" . $return_qs);
    exit();
}

// ── Reply to a thread ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply']) && $is_student) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id]);

    $chk = $conn->prepare("SELECT is_locked FROM discussion_threads WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
    $chk->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
    $chk->execute();
    $thread = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$thread || !disc_student_owns_class($conn, $student_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That thread was not found among your subjects.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ((int) $thread['is_locked'] === 1) {
        $_SESSION['dc_flash'] = 'This thread is locked.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($body === '') {
        $_SESSION['dc_flash'] = 'Please write a reply.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $ins = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_student_id, body) VALUES (?, 'student', ?, ?)");
        $ins->bind_param('iis', $thread_id, $student_id, $body);
        $ins->execute();
        $ins->close();
        $_SESSION['dc_flash'] = 'Reply posted.';
        $_SESSION['dc_flash_type'] = 'success';
        notify_teacher_users(
            $conn,
            disc_teacher_targets($conn, $subject_id, $section_id, $sy),
            'New reply in a discussion thread.',
            'roles/teacher/teacher_discussions'
        );
    }
    header("Location: student_discussions?" . $return_qs);
    exit();
}

// ── Delete own post only (students can't moderate others') ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post']) && $is_student) {
    $post_id    = (int) ($_POST['post_id'] ?? 0);
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id]);

    $del = $conn->prepare("DELETE FROM discussion_posts WHERE post_id=? AND thread_id=? AND posted_by_type='student' AND posted_by_student_id=?");
    $del->bind_param('iii', $post_id, $thread_id, $student_id);
    $del->execute();
    $del->close();
    $_SESSION['dc_flash'] = 'Post deleted.';
    $_SESSION['dc_flash_type'] = 'success';
    header("Location: student_discussions?" . $return_qs);
    exit();
}

$flash      = $_SESSION['dc_flash']      ?? '';
$flash_type = $_SESSION['dc_flash_type'] ?? 'info';
unset($_SESSION['dc_flash'], $_SESSION['dc_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discussions — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <style>
      .dc-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .dc-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .dc-subject-title { font-size:13px; font-weight:700; color:#2F6B4F; text-transform:uppercase; letter-spacing:.04em; margin:1rem 0 .5rem; }
      .dc-subject-title:first-child { margin-top:0; }
      .dc-thread-row { padding:10px 0; border-bottom:0.5px solid #EBEBF0; }
      .dc-thread-row:last-child { border-bottom:none; }
      .dc-thread-title { font-weight:600; font-size:13px; color:#1A1A2E; }
      .dc-thread-title a { color:inherit; text-decoration:none; }
      .dc-thread-title a:hover { color:var(--brand-primary); }
      .dc-thread-meta { font-size:11px; color:#8A8A9A; }
      .dc-lock-badge { font-size:10px; color:#C0392B; font-weight:600; margin-left:6px; }
      .dc-new-form { display:flex; flex-direction:column; gap:8px; margin-bottom:1rem; }
      .dc-new-form input[type="text"], .dc-new-form textarea {
        border:0.5px solid #D4D4E0; border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; box-sizing:border-box;
      }
      .dc-new-form textarea { min-height:70px; resize:vertical; }
      .btn-primary-dc { height:38px; padding:0 18px; background:var(--brand-primary); border:none; border-radius:8px; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; align-self:flex-start; }
      .btn-primary-dc:hover { background:var(--brand-primary-hover); }
      .dc-post { border-bottom:0.5px solid #EBEBF0; padding:10px 0; }
      .dc-post:last-child { border-bottom:none; }
      .dc-post-meta { font-size:11px; color:#8A8A9A; margin-bottom:3px; }
      .dc-post-author { font-weight:600; color:#1A1A2E; }
      .dc-post-body { font-size:13px; color:#3A3A4A; white-space:pre-wrap; }
      .btn-sm { height:28px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px; background:#fff; font-size:11px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
      .btn-sm.danger:hover { border-color:#F5C6C2; color:#C0392B; }
      .dc-back { font-size:12px; color:#5A5A72; text-decoration:none; display:inline-block; margin-bottom:.75rem; }
      .dc-back:hover { color:var(--brand-primary); }
    </style>
</head>
<body class="student-layout lms-layout">
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

    $sec_id = $enrollment ? (int) $enrollment['section_id'] : 0;

    $sel_subject = (int) ($_GET['subject_id'] ?? 0);
    $sel_thread  = (int) ($_GET['thread_id'] ?? 0);

    $subjects = [];
    if ($sec_id > 0) {
        $subj_stmt = mysqli_prepare($conn, "
            SELECT DISTINCT sub.subject_id, sub.subject_name
            FROM section_subjects ss JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ?
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($subj_stmt, "i", $sec_id);
        mysqli_stmt_execute($subj_stmt);
        $subjects = mysqli_fetch_all(mysqli_stmt_get_result($subj_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($subj_stmt);
    }
    if ($sel_subject <= 0 && !empty($subjects)) {
        $sel_subject = (int) $subjects[0]['subject_id'];
    }

    $threads = [];
    $thread_detail = null;
    $posts = [];
    if ($sec_id > 0 && $sel_subject > 0) {
        $th_stmt = $conn->prepare("
            SELECT dt.thread_id, dt.title, dt.is_locked, dt.created_at,
                   (SELECT COUNT(*) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS post_count,
                   (SELECT MAX(dp.posted_at) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS last_activity
            FROM discussion_threads dt
            WHERE dt.subject_id=? AND dt.section_id=? AND dt.school_year=?
            ORDER BY last_activity DESC
        ");
        $th_stmt->bind_param('iis', $sel_subject, $sec_id, $selected_sy);
        $th_stmt->execute();
        $threads = $th_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $th_stmt->close();

        if ($sel_thread > 0) {
            foreach ($threads as $t) {
                if ((int) $t['thread_id'] === $sel_thread) { $thread_detail = $t; break; }
            }
            if ($thread_detail !== null) {
                $p_stmt = $conn->prepare("
                    SELECT dp.post_id, dp.posted_by_type, dp.posted_by_student_id, dp.body, dp.posted_at,
                           CASE WHEN dp.posted_by_type='teacher' THEN CONCAT(t.given_name,' ',t.family_name) ELSE CONCAT(s.given_name,' ',s.family_name) END AS author_name
                    FROM discussion_posts dp
                    LEFT JOIN teachers t ON t.teacher_id = dp.posted_by_teacher_id
                    LEFT JOIN students s ON s.student_id = dp.posted_by_student_id
                    WHERE dp.thread_id = ?
                    ORDER BY dp.posted_at ASC
                ");
                $p_stmt->bind_param('i', $sel_thread);
                $p_stmt->execute();
                $posts = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $p_stmt->close();
            }
        }
    }
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Discussions
          <span class="student-topbar-subtitle">Ask questions, talk with your class</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.222 0-2.386-.216-3.447-.61L3 21l1.395-4.184A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></span>
            <div class="student-panel-title">Discussions</div>
          </div>
        </div>

        <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" style="margin-bottom:.75rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

        <?php if (empty($sy_list) || empty($subjects)): ?>
          <p class="empty-state">No subjects found yet.</p>
        <?php else: ?>
          <form method="GET" class="dc-picker">
            <select name="sy" onchange="this.form.submit()">
              <?php foreach ($sy_list as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $selected_sy === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="subject_id" onchange="this.form.submit()">
              <?php foreach ($subjects as $s): ?>
                <option value="<?= (int)$s['subject_id'] ?>" <?= (int)$s['subject_id']===$sel_subject ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>

          <?php if ($thread_detail !== null): ?>

            <a class="dc-back" href="student_discussions?sy=<?= urlencode($selected_sy) ?>&subject_id=<?= $sel_subject ?>">&larr; Back to threads</a>

            <div class="dc-thread-title" style="font-size:15px; margin-bottom:.5rem;">
              <?= htmlspecialchars($thread_detail['title']) ?>
              <?php if ($thread_detail['is_locked']): ?><span class="dc-lock-badge">LOCKED</span><?php endif; ?>
            </div>

            <?php foreach ($posts as $p): ?>
              <div class="dc-post">
                <div class="dc-post-meta">
                  <span class="dc-post-author"><?= htmlspecialchars($p['author_name'] ?? 'User') ?></span>
                  <?= $p['posted_by_type'] === 'teacher' ? ' (Teacher)' : '' ?>
                  · <?= date('M j, Y g:i A', strtotime($p['posted_at'])) ?>
                </div>
                <div class="dc-post-body"><?= nl2br(htmlspecialchars($p['body'])) ?></div>
                <?php if ($p['posted_by_type'] === 'student' && (int) $p['posted_by_student_id'] === $student_id): ?>
                  <form method="POST" style="display:inline;" data-confirm="Delete this post?" data-icon="warning">
                    <input type="hidden" name="post_id" value="<?= (int)$p['post_id'] ?>">
                    <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                    <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                    <input type="hidden" name="section_id" value="<?= $sec_id ?>">
                    <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                    <button type="submit" name="delete_post" class="btn-sm danger" style="margin-top:4px;">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php if (!$thread_detail['is_locked']): ?>
              <form method="POST" class="dc-new-form" style="margin-top:1rem;">
                <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sec_id ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                <textarea name="body" placeholder="Write a reply" required></textarea>
                <button type="submit" name="reply" class="btn-primary-dc">Reply</button>
              </form>
            <?php else: ?>
              <p class="empty-state">This thread is locked — no new replies.</p>
            <?php endif; ?>

          <?php else: ?>

            <form method="POST" class="dc-new-form">
              <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
              <input type="hidden" name="section_id" value="<?= $sec_id ?>">
              <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
              <input type="text" name="title" placeholder="New thread title" required>
              <textarea name="body" placeholder="Opening message" required></textarea>
              <button type="submit" name="new_thread" class="btn-primary-dc">Start Thread</button>
            </form>

            <?php if (empty($threads)): ?>
              <p class="empty-state">No discussions yet for this subject.</p>
            <?php else: ?>
              <?php foreach ($threads as $t): ?>
                <div class="dc-thread-row">
                  <div class="dc-thread-title">
                    <a href="student_discussions?sy=<?= urlencode($selected_sy) ?>&subject_id=<?= $sel_subject ?>&thread_id=<?= (int)$t['thread_id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                    <?php if ($t['is_locked']): ?><span class="dc-lock-badge">LOCKED</span><?php endif; ?>
                  </div>
                  <div class="dc-thread-meta"><?= (int)$t['post_count'] ?> post<?= (int)$t['post_count'] === 1 ? '' : 's' ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
