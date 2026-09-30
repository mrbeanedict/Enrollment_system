<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);

function disc_owns_class(mysqli $conn, int $teacher_id, int $subject_id, int $section_id, string $sy): bool {
    $stmt = $conn->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=? AND is_active=1");
    $stmt->bind_param('iiis', $teacher_id, $subject_id, $section_id, $sy);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

// Reverse direction of roles/lms/student_discussions.php's disc_teacher_targets() —
// same enrolled-students query already proven in
// roles/teacher/teacher_lessons.php's lesson_notify_targets().
function disc_student_targets(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
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

// Where a student lands for an announcement: its card on the subject Stream.
function disc_stream_link(int $subject_id, string $sy, int $thread_id): string {
    return 'roles/lms/student_stream?' . http_build_query(['subject_id' => $subject_id, 'sy' => $sy]) . '#ann-' . $thread_id;
}

// ── Start a new thread ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_thread'])) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    // 'announcement' posts to the class Stream (roles/lms/student_stream.php)
    // instead of the Discussions list; its later posts are class comments.
    $kind       = ($_POST['kind'] ?? '') === 'announcement' ? 'announcement' : 'discussion';
    $is_ann     = $kind === 'announcement';
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (!disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That class was not found among your assignments.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($title === '' || $body === '') {
        $_SESSION['dc_flash'] = $is_ann ? 'Please provide an announcement title and message.' : 'Please provide a thread title and an opening message.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO discussion_threads (subject_id, section_id, school_year, title, kind, created_by_type, created_by_teacher_id)
                VALUES (?, ?, ?, ?, ?, 'teacher', ?)
            ");
            $ins->bind_param('iisssi', $subject_id, $section_id, $sy, $title, $kind, $teacher_id);
            $ins->execute();
            $thread_id = $ins->insert_id;
            $ins->close();

            $insp = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_teacher_id, body) VALUES (?, 'teacher', ?, ?)");
            $insp->bind_param('iis', $thread_id, $teacher_id, $body);
            $insp->execute();
            $insp->close();

            $conn->commit();

            notify_student_users(
                $conn,
                disc_student_targets($conn, $subject_id, $section_id, $sy),
                $is_ann ? "New announcement: \"$title\"." : "New discussion thread: \"$title\".",
                $is_ann ? disc_stream_link($subject_id, $sy, $thread_id) : 'roles/lms/student_discussions'
            );

            $return_qs .= '&thread_id=' . $thread_id;
            $_SESSION['dc_flash'] = $is_ann ? "Announcement \"$title\" posted to the class Stream." : "\"$title\" started.";
            $_SESSION['dc_flash_type'] = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dc_flash'] = $is_ann ? 'Could not post the announcement. Please try again.' : 'Could not start the thread. Please try again.';
            $_SESSION['dc_flash_type'] = 'error';
        }
    }
    header("Location: teacher_discussions?" . $return_qs);
    exit();
}

// ── Reply to a thread ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id]);

    // Confirm this thread belongs to a class this teacher actually teaches
    // and isn't locked — never trust the posted thread_id blindly.
    $chk = $conn->prepare("SELECT title, kind, is_locked FROM discussion_threads WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
    $chk->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
    $chk->execute();
    $thread = $chk->get_result()->fetch_assoc();
    $chk->close();
    $is_ann = $thread && $thread['kind'] === 'announcement';

    if (!$thread || !disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That thread was not found among your assignments.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ((int) $thread['is_locked'] === 1) {
        $_SESSION['dc_flash'] = $is_ann ? 'Comments are turned off for this announcement.' : 'This thread is locked.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($body === '') {
        $_SESSION['dc_flash'] = 'Please write a reply.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $ins = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_teacher_id, body) VALUES (?, 'teacher', ?, ?)");
        $ins->bind_param('iis', $thread_id, $teacher_id, $body);
        $ins->execute();
        $ins->close();

        notify_student_users(
            $conn,
            disc_student_targets($conn, $subject_id, $section_id, $sy),
            $is_ann ? "Your teacher commented on \"{$thread['title']}\"." : 'New reply in a discussion thread.',
            $is_ann ? disc_stream_link($subject_id, $sy, $thread_id) : 'roles/lms/student_discussions'
        );

        $_SESSION['dc_flash'] = $is_ann ? 'Comment posted.' : 'Reply posted.';
        $_SESSION['dc_flash_type'] = 'success';
    }
    header("Location: teacher_discussions?" . $return_qs);
    exit();
}

// ── Lock/unlock a thread (teacher moderation) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_lock'])) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id]);

    if (disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $upd = $conn->prepare("UPDATE discussion_threads SET is_locked = NOT is_locked WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
        $upd->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
        $upd->execute();
        $upd->close();
    }
    header("Location: teacher_discussions?" . $return_qs);
    exit();
}

// ── Delete a post (teacher moderation — any post in their own class) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    $post_id    = (int) ($_POST['post_id'] ?? 0);
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id]);

    // An announcement's opening post is the announcement text itself — it
    // goes away with the whole announcement (Delete in the list), never alone.
    $op = $conn->prepare("SELECT MIN(dp.post_id) FROM discussion_posts dp JOIN discussion_threads dt ON dt.thread_id = dp.thread_id WHERE dt.thread_id = ? AND dt.kind = 'announcement'");
    $op->bind_param('i', $thread_id);
    $op->execute();
    $opening_id = (int) ($op->get_result()->fetch_row()[0] ?? 0);
    $op->close();

    if ($opening_id > 0 && $post_id === $opening_id) {
        $_SESSION['dc_flash'] = 'That is the announcement itself — delete the announcement from the list instead.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif (disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $del = $conn->prepare("
            DELETE dp FROM discussion_posts dp
            JOIN discussion_threads dt ON dt.thread_id = dp.thread_id
            WHERE dp.post_id=? AND dt.thread_id=? AND dt.subject_id=? AND dt.section_id=? AND dt.school_year=?
        ");
        $del->bind_param('iiiis', $post_id, $thread_id, $subject_id, $section_id, $sy);
        $del->execute();
        $del->close();
        $_SESSION['dc_flash'] = 'Post deleted.';
        $_SESSION['dc_flash_type'] = 'success';
    }
    header("Location: teacher_discussions?" . $return_qs);
    exit();
}

// ── Rename a thread ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_thread'])) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $new_title  = trim($_POST['new_title'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (!disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That class was not found among your assignments.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($new_title === '') {
        $_SESSION['dc_flash'] = 'Please provide a thread title.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif (mb_strlen($new_title) > 150) {
        $_SESSION['dc_flash'] = 'Thread title is too long.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $upd = $conn->prepare("UPDATE discussion_threads SET title=? WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
        $upd->bind_param('siiis', $new_title, $thread_id, $subject_id, $section_id, $sy);
        $upd->execute();
        $upd->close();
        $_SESSION['dc_flash'] = 'Thread renamed.';
        $_SESSION['dc_flash_type'] = 'success';
    }
    header("Location: teacher_discussions?" . $return_qs);
    exit();
}

// ── Delete a thread (and its posts) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_thread'])) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $conn->begin_transaction();
        try {
            $delp = $conn->prepare("
                DELETE dp FROM discussion_posts dp
                JOIN discussion_threads dt ON dt.thread_id = dp.thread_id
                WHERE dt.thread_id=? AND dt.subject_id=? AND dt.section_id=? AND dt.school_year=?
            ");
            $delp->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
            $delp->execute();
            $delp->close();

            $delt = $conn->prepare("DELETE FROM discussion_threads WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
            $delt->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
            $delt->execute();
            $delt->close();

            $conn->commit();
            $_SESSION['dc_flash'] = 'Thread deleted.';
            $_SESSION['dc_flash_type'] = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dc_flash'] = 'Could not delete the thread. Please try again.';
            $_SESSION['dc_flash_type'] = 'error';
        }
    }
    header("Location: teacher_discussions?" . $return_qs);
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
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
    <style>
      .dc-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .dc-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }
      .dc-thread-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:0.5px solid #EBEBF0; }
      .dc-thread-row:last-child { border-bottom:none; }
      .dc-thread-menu { position:relative; }
      .dc-thread-menu-btn {
        width:28px; height:28px; border:none; background:transparent; border-radius:6px;
        display:flex; align-items:center; justify-content:center; cursor:pointer; color:#8A8A9A;
      }
      .dc-thread-menu-btn:hover { background:#F0F0F5; color:#1A1A2E; }
      .dc-thread-menu-dropdown {
        position:absolute; right:0; top:32px; min-width:150px; background:#fff; border:0.5px solid #D4D4E0;
        border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.08); padding:6px; z-index:20;
        opacity:0; visibility:hidden; transform:translateY(-4px); transition:opacity .15s, transform .15s;
      }
      .dc-thread-menu-dropdown.open { opacity:1; visibility:visible; transform:translateY(0); }
      .dc-thread-menu-item {
        display:block; width:100%; text-align:left; padding:8px 10px; border:none; background:transparent;
        border-radius:6px; font-size:12.5px; font-family:inherit; color:#3A3A4A; cursor:pointer;
      }
      .dc-thread-menu-item:hover { background:#F0F0F5; }
      .dc-thread-menu-item-danger { color:#C0392B; }
      .dc-thread-menu-item-danger:hover { background:#FBEAE8; }
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
      .dc-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
      .dc-kind { display:flex; gap:8px; flex-wrap:wrap; border:0; padding:0; margin:0; }
      .dc-kind label { display:flex; align-items:center; gap:7px; padding:8px 12px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
                       font-size:13px; font-weight:600; color:#1A1A2E; cursor:pointer; }
      .dc-kind label span { font-weight:400; color:#8A8A9A; font-size:12px; }
      .dc-kind label:has(input:checked) { border-color:var(--brand-primary); background:#fff; box-shadow:0 0 0 1px var(--brand-primary) inset; }
      .dc-kind input { accent-color:var(--brand-primary); margin:0; }
      .dc-ann-badge { display:inline-block; margin-right:6px; padding:1px 7px; border-radius:999px; background:var(--brand-primary); color:#fff;
                      font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; vertical-align:1px; }
      .dc-ann-note { margin:0 0 .5rem; font-size:12px; color:#8A8A9A; }
      .dc-ann-comments { margin-top:.75rem; padding-top:.5rem; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#8A8A9A; }
      .dc-back:hover { color:var(--brand-primary); }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';

    $cls_stmt = $conn->prepare("
        SELECT ta.subject_id, ta.section_id, ta.school_year, ta.semester, sub.subject_name, sec.section_name
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.subject_id = ta.subject_id
        JOIN sections sec ON sec.section_id = ta.section_id
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
    $sel_thread  = (int) ($_GET['thread_id'] ?? 0);

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

    $threads = [];
    $thread_detail = null;
    $posts = [];
    if ($current !== null) {
        $th_stmt = $conn->prepare("
            SELECT dt.thread_id, dt.title, dt.kind, dt.is_locked, dt.created_at,
                   (SELECT COUNT(*) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS post_count,
                   (SELECT MAX(dp.posted_at) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS last_activity,
                   (SELECT MIN(dp.post_id) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS first_post_id
            FROM discussion_threads dt
            WHERE dt.subject_id=? AND dt.section_id=? AND dt.school_year=?
            ORDER BY dt.kind = 'announcement' DESC, last_activity DESC
        ");
        $th_stmt->bind_param('iis', $sel_subject, $sel_section, $sel_sy);
        $th_stmt->execute();
        $threads = $th_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $th_stmt->close();

        if ($sel_thread > 0) {
            foreach ($threads as $t) {
                if ((int) $t['thread_id'] === $sel_thread) { $thread_detail = $t; break; }
            }
            if ($thread_detail !== null) {
                $p_stmt = $conn->prepare("
                    SELECT dp.post_id, dp.posted_by_type, dp.posted_by_teacher_id, dp.posted_by_student_id, dp.body, dp.posted_at,
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
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Discussions
          <span class="teacher-topbar-subtitle">Talk with your class per subject</span>
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
          // Discussions aren't semester-partitioned — a class taught across
          // both semesters (a common case) still shows once here.
          $class_options = [];
          foreach ($classes as $c) {
              $key = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year'];
              if (!isset($class_options[$key])) { $class_options[$key] = $c; }
          }
        ?>
        <form method="GET" class="dc-picker">
          <select name="class" onchange="var v=this.value.split('|'); location.href='teacher_discussions?subject_id='+v[0]+'&section_id='+v[1]+'&sy='+encodeURIComponent(v[2]);">
            <?php foreach ($class_options as $c): $val = $c['subject_id'] . '|' . $c['section_id'] . '|' . $c['school_year']; ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= ((int)$c['subject_id']===$sel_subject && (int)$c['section_id']===$sel_section && $c['school_year']===$sel_sy) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['subject_name']) ?> — <?= htmlspecialchars($c['section_name']) ?> (SY <?= htmlspecialchars($c['school_year']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($current === null): ?>
          <div class="notice notice-info">Select a class above to view its discussions.</div>
        <?php elseif ($thread_detail !== null): ?>

          <a class="dc-back" href="teacher_discussions?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>">&larr; Back to threads</a>

          <div class="teacher-panel-block">
            <?php $is_ann = $thread_detail['kind'] === 'announcement'; ?>
            <div class="dc-thread-title" style="font-size:15px; margin-bottom:.25rem;">
              <?php if ($is_ann): ?><span class="dc-ann-badge">Announcement</span><?php endif; ?>
              <?= htmlspecialchars($thread_detail['title']) ?>
              <?php if ($thread_detail['is_locked']): ?><span class="dc-lock-badge"><?= $is_ann ? 'COMMENTS OFF' : 'LOCKED' ?></span><?php endif; ?>
            </div>
            <?php if ($is_ann): ?>
              <p class="dc-ann-note">Shown on the class Stream. Everyone in this class can read and join the comments below.</p>
            <?php endif; ?>

            <?php foreach ($posts as $p):
              // An announcement's opening post is the announcement itself — it
              // goes with the announcement (delete that from the list), not
              // one comment at a time.
              $is_opening = $is_ann && (int) $p['post_id'] === (int) $thread_detail['first_post_id']; ?>
              <?php if ($is_ann && !$is_opening && (int) $p['post_id'] === (int) ($posts[1]['post_id'] ?? 0)): ?>
                <div class="dc-ann-comments">Class comments</div>
              <?php endif; ?>
              <div class="dc-post">
                <div class="dc-post-meta">
                  <span class="dc-post-author"><?= htmlspecialchars($p['author_name'] ?? 'User') ?></span>
                  <?= $p['posted_by_type'] === 'teacher' ? ' (Teacher)' : '' ?>
                  · <?= date('M j, Y g:i A', strtotime($p['posted_at'])) ?>
                </div>
                <div class="dc-post-body"><?= nl2br(htmlspecialchars($p['body'])) ?></div>
                <?php if (!$is_opening): ?>
                <form method="POST" style="display:inline;" data-confirm="Delete this <?= $is_ann ? 'comment' : 'post' ?>?" data-icon="warning">
                  <input type="hidden" name="post_id" value="<?= (int)$p['post_id'] ?>">
                  <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                  <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                  <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                  <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                  <button type="submit" name="delete_post" class="btn-sm danger" style="margin-top:4px;">Delete</button>
                </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <div style="margin-top:1rem; display:flex; gap:8px; flex-wrap:wrap;">
              <form method="POST" style="display:inline;" data-confirm="<?= $is_ann ? ($thread_detail['is_locked'] ? 'Turn comments back on for this announcement?' : 'Turn off comments for this announcement?') : ($thread_detail['is_locked'] ? 'Unlock this thread?' : 'Lock this thread?') ?>" data-icon="question">
                <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                <button type="submit" name="toggle_lock" class="btn-sm"><?= $is_ann ? ($thread_detail['is_locked'] ? 'Turn On Comments' : 'Turn Off Comments') : ($thread_detail['is_locked'] ? 'Unlock Thread' : 'Lock Thread') ?></button>
              </form>
            </div>

            <?php if (!$thread_detail['is_locked']): ?>
              <form method="POST" class="dc-new-form" style="margin-top:1rem;">
                <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                <textarea name="body" placeholder="<?= $is_ann ? 'Add a class comment' : 'Write a reply' ?>" required></textarea>
                <button type="submit" name="reply" class="btn-primary-dc"><?= $is_ann ? 'Comment' : 'Reply' ?></button>
              </form>
            <?php endif; ?>
          </div>

        <?php else: ?>

          <div class="teacher-panel-block">
            <form method="POST" class="dc-new-form">
              <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
              <input type="hidden" name="section_id" value="<?= $sel_section ?>">
              <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
              <fieldset class="dc-kind">
                <legend class="dc-sr">Post type</legend>
                <label><input type="radio" name="kind" value="discussion" checked> Discussion thread</label>
                <label><input type="radio" name="kind" value="announcement"> Class announcement <span>shows on the class Stream; students can comment</span></label>
              </fieldset>
              <input type="text" name="title" placeholder="New thread title" required maxlength="150" data-ph-discussion="New thread title" data-ph-announcement="Announcement title">
              <textarea name="body" placeholder="Opening message" required data-ph-discussion="Opening message" data-ph-announcement="What do you want to tell the class?"></textarea>
              <button type="submit" name="new_thread" class="btn-primary-dc" data-label-discussion="Start Thread" data-label-announcement="Post Announcement">Start Thread</button>
            </form>

            <?php if (empty($threads)): ?>
              <p class="empty-state">No discussions yet for this class.</p>
            <?php else: ?>
              <?php foreach ($threads as $t): ?>
                <div class="dc-thread-row">
                  <div>
                    <div class="dc-thread-title">
                      <?php $t_ann = $t['kind'] === 'announcement'; ?>
                      <?php if ($t_ann): ?><span class="dc-ann-badge">Announcement</span><?php endif; ?>
                      <a href="teacher_discussions?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>&thread_id=<?= (int)$t['thread_id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                      <?php if ($t['is_locked']): ?><span class="dc-lock-badge"><?= $t_ann ? 'COMMENTS OFF' : 'LOCKED' ?></span><?php endif; ?>
                    </div>
                    <?php if ($t_ann): $cc = max(0, (int)$t['post_count'] - 1); ?>
                      <div class="dc-thread-meta">Posted <?= date('M j, Y', strtotime($t['created_at'])) ?> · <?= $cc ?> comment<?= $cc === 1 ? '' : 's' ?></div>
                    <?php else: ?>
                      <div class="dc-thread-meta"><?= (int)$t['post_count'] ?> post<?= (int)$t['post_count'] === 1 ? '' : 's' ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="dc-thread-menu">
                    <button type="button" class="dc-thread-menu-btn" data-menu-toggle aria-label="Thread options">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                    </button>
                    <div class="dc-thread-menu-dropdown">
                      <button type="button" class="dc-thread-menu-item"
                        data-edit-thread
                        data-thread-id="<?= (int)$t['thread_id'] ?>"
                        data-thread-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>">
                        Edit title
                      </button>
                      <form method="POST" data-confirm="<?= $t_ann ? 'Delete this announcement and all its comments? This cannot be undone.' : 'Delete this thread and all its posts? This cannot be undone.' ?>" data-icon="warning">
                        <input type="hidden" name="thread_id" value="<?= (int)$t['thread_id'] ?>">
                        <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                        <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                        <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                        <button type="submit" name="delete_thread" class="dc-thread-menu-item dc-thread-menu-item-danger">Delete</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>

              <form method="POST" id="dc-edit-thread-form" style="display:none;">
                <input type="hidden" name="thread_id" id="dc-edit-thread-id">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                <input type="hidden" name="new_title" id="dc-edit-thread-title">
                <button type="submit" name="edit_thread" id="dc-edit-thread-submit"></button>
              </form>
            <?php endif; ?>
          </div>

        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
  <script>
    // Kebab menu open/close (per thread row), plus outside-click / Escape to close.
    document.querySelectorAll('[data-menu-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var dropdown = btn.closest('.dc-thread-menu').querySelector('.dc-thread-menu-dropdown');
        var wasOpen = dropdown.classList.contains('open');
        document.querySelectorAll('.dc-thread-menu-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
        if (!wasOpen) { dropdown.classList.add('open'); }
      });
    });
    document.addEventListener('click', function () {
      document.querySelectorAll('.dc-thread-menu-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.dc-thread-menu-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
      }
    });

    // New post: wording follows the chosen type (discussion vs announcement).
    document.querySelectorAll('.dc-kind input[name="kind"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        var form = radio.form, k = radio.value;
        form.querySelectorAll('[data-ph-' + k + ']').forEach(function (el) { el.placeholder = el.getAttribute('data-ph-' + k); });
        var btn = form.querySelector('[data-label-' + k + ']');
        if (btn) btn.textContent = btn.getAttribute('data-label-' + k);
      });
    });

    // Edit thread title — prompt via SweetAlert2 (falls back to native prompt()).
    document.querySelectorAll('[data-edit-thread]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var threadId = btn.getAttribute('data-thread-id');
        var currentTitle = btn.getAttribute('data-thread-title');

        function submitNewTitle(newTitle) {
          if (!newTitle || newTitle.trim() === '' || newTitle === currentTitle) { return; }
          document.getElementById('dc-edit-thread-id').value = threadId;
          document.getElementById('dc-edit-thread-title').value = newTitle.trim();
          document.getElementById('dc-edit-thread-form').requestSubmit(document.getElementById('dc-edit-thread-submit'));
        }

        if (typeof Swal === 'undefined') {
          submitNewTitle(prompt('Edit thread title', currentTitle));
          return;
        }
        Swal.fire({
          title: 'Edit thread title',
          input: 'text',
          inputValue: currentTitle,
          inputAttributes: { maxlength: 150 },
          showCancelButton: true,
          confirmButtonText: 'Save',
          confirmButtonColor: '#1e6f4e'
        }).then(function (result) {
          if (result.isConfirmed) { submitNewTitle(result.value); }
        });
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
