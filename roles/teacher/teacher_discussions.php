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

// ── Start a new thread ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_thread'])) {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $return_qs  = http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy]);

    if (!disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That class was not found among your assignments.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($title === '' || $body === '') {
        $_SESSION['dc_flash'] = 'Please provide a thread title and an opening message.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO discussion_threads (subject_id, section_id, school_year, title, created_by_type, created_by_teacher_id)
                VALUES (?, ?, ?, ?, 'teacher', ?)
            ");
            $ins->bind_param('iissi', $subject_id, $section_id, $sy, $title, $teacher_id);
            $ins->execute();
            $thread_id = $ins->insert_id;
            $ins->close();

            $insp = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_teacher_id, body) VALUES (?, 'teacher', ?, ?)");
            $insp->bind_param('iis', $thread_id, $teacher_id, $body);
            $insp->execute();
            $insp->close();

            $conn->commit();
            $return_qs .= '&thread_id=' . $thread_id;
            $_SESSION['dc_flash'] = "\"$title\" started.";
            $_SESSION['dc_flash_type'] = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['dc_flash'] = 'Could not start the thread. Please try again.';
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
    $chk = $conn->prepare("SELECT is_locked FROM discussion_threads WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
    $chk->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
    $chk->execute();
    $thread = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$thread || !disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
        $_SESSION['dc_flash'] = 'That thread was not found among your assignments.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ((int) $thread['is_locked'] === 1) {
        $_SESSION['dc_flash'] = 'This thread is locked.';
        $_SESSION['dc_flash_type'] = 'error';
    } elseif ($body === '') {
        $_SESSION['dc_flash'] = 'Please write a reply.';
        $_SESSION['dc_flash_type'] = 'error';
    } else {
        $ins = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_teacher_id, body) VALUES (?, 'teacher', ?, ?)");
        $ins->bind_param('iis', $thread_id, $teacher_id, $body);
        $ins->execute();
        $ins->close();
        $_SESSION['dc_flash'] = 'Reply posted.';
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

    if (disc_owns_class($conn, $teacher_id, $subject_id, $section_id, $sy)) {
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
            SELECT dt.thread_id, dt.title, dt.is_locked, dt.created_at,
                   (SELECT COUNT(*) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS post_count,
                   (SELECT MAX(dp.posted_at) FROM discussion_posts dp WHERE dp.thread_id = dt.thread_id) AS last_activity
            FROM discussion_threads dt
            WHERE dt.subject_id=? AND dt.section_id=? AND dt.school_year=?
            ORDER BY last_activity DESC
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

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

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
            <div class="dc-thread-title" style="font-size:15px; margin-bottom:.25rem;">
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
                <form method="POST" style="display:inline;" data-confirm="Delete this post?" data-icon="warning">
                  <input type="hidden" name="post_id" value="<?= (int)$p['post_id'] ?>">
                  <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                  <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                  <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                  <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                  <button type="submit" name="delete_post" class="btn-sm danger" style="margin-top:4px;">Delete</button>
                </form>
              </div>
            <?php endforeach; ?>

            <div style="margin-top:1rem; display:flex; gap:8px; flex-wrap:wrap;">
              <form method="POST" style="display:inline;" data-confirm="<?= $thread_detail['is_locked'] ? 'Unlock' : 'Lock' ?> this thread?" data-icon="question">
                <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                <button type="submit" name="toggle_lock" class="btn-sm"><?= $thread_detail['is_locked'] ? 'Unlock Thread' : 'Lock Thread' ?></button>
              </form>
            </div>

            <?php if (!$thread_detail['is_locked']): ?>
              <form method="POST" class="dc-new-form" style="margin-top:1rem;">
                <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sel_section ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
                <textarea name="body" placeholder="Write a reply" required></textarea>
                <button type="submit" name="reply" class="btn-primary-dc">Reply</button>
              </form>
            <?php endif; ?>
          </div>

        <?php else: ?>

          <div class="teacher-panel-block">
            <form method="POST" class="dc-new-form">
              <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
              <input type="hidden" name="section_id" value="<?= $sel_section ?>">
              <input type="hidden" name="school_year" value="<?= htmlspecialchars($sel_sy) ?>">
              <input type="text" name="title" placeholder="New thread title" required>
              <textarea name="body" placeholder="Opening message" required></textarea>
              <button type="submit" name="new_thread" class="btn-primary-dc">Start Thread</button>
            </form>

            <?php if (empty($threads)): ?>
              <p class="empty-state">No discussions yet for this class.</p>
            <?php else: ?>
              <?php foreach ($threads as $t): ?>
                <div class="dc-thread-row">
                  <div>
                    <div class="dc-thread-title">
                      <a href="teacher_discussions?subject_id=<?= $sel_subject ?>&section_id=<?= $sel_section ?>&sy=<?= urlencode($sel_sy) ?>&thread_id=<?= (int)$t['thread_id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                      <?php if ($t['is_locked']): ?><span class="dc-lock-badge">LOCKED</span><?php endif; ?>
                    </div>
                    <div class="dc-thread-meta"><?= (int)$t['post_count'] ?> post<?= (int)$t['post_count'] === 1 ? '' : 's' ?></div>
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
                      <form method="POST" data-confirm="Delete this thread and all its posts? This cannot be undone." data-icon="warning">
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
