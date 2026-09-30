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

// Ownership check, teacher notification targets, reply/delete — shared
// with the subject Stream page (announcement comments are replies too).
require_once BASE_PATH . '/shared/helpers/lms_class.php';

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

    [$ok, $msg] = disc_student_reply($conn, $student_id, $thread_id, $subject_id, $section_id, $sy, $body);
    $_SESSION['dc_flash'] = $msg;
    $_SESSION['dc_flash_type'] = $ok ? 'success' : 'error';
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

    disc_student_delete_post($conn, $student_id, $post_id, $thread_id);
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
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      /* Thread rows = LMS Home's My Courses rows. */
      .dc-threads { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:4px; }
      .dc-thread-row { position:relative; display:flex; align-items:center; gap:14px; padding:10px; border-radius:10px; }
      .dc-thread-row:hover, .dc-thread-row:focus-within { background:var(--st-hover); }
      .dc-thread-row .hm-tile { width:34px; height:34px; background:var(--st-info); color:var(--st-dark); }
      .dc-thread-main { flex:1; min-width:0; }
      .dc-thread-title { font-weight:700; font-size:14px; color:var(--st-dark); }
      .dc-thread-title a { color:inherit; text-decoration:none; }
      .dc-thread-row .dc-thread-title a::after { content:''; position:absolute; inset:0; border-radius:inherit; }
      .dc-thread-meta { font-size:12.5px; color:var(--st-sub); }
      .dc-count { flex:none; padding:3px 10px; border-radius:999px; background:var(--st-tint); color:var(--st-dark); font-size:11.5px; font-weight:700; white-space:nowrap; }
      .dc-lock-badge { display:inline-block; margin-left:6px; padding:1px 6px; border-radius:4px; background:var(--st-tint); color:var(--st-sub); font-size:9.5px; font-weight:800; letter-spacing:.06em; vertical-align:2px; }
      .dc-new { margin-bottom:16px; border:1px solid var(--st-line); border-radius:12px; background:var(--st-hover); }
      .dc-new summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:8px; padding:12px 14px; font-size:13.5px; font-weight:700; color:var(--st-dark); }
      .dc-new summary::-webkit-details-marker { display:none; }
      .dc-new summary::before { content:'+'; display:grid; place-items:center; width:22px; height:22px; border-radius:50%; background:var(--st-dark); color:#fff; font-size:15px; line-height:1; }
      .dc-new[open] summary::before { content:'–'; }
      .dc-new .dc-new-form { padding:0 14px 14px; margin:0; }
      .dc-new-form { display:flex; flex-direction:column; gap:8px; margin-bottom:1rem; }
      .dc-new-form input[type="text"], .dc-new-form textarea {
        border:1px solid var(--st-line); border-radius:8px; background:#fff; padding:9px 12px; font-size:13.5px; font-family:inherit; box-sizing:border-box;
      }
      .dc-new-form input[type="text"]:focus, .dc-new-form textarea:focus { outline:none; border-color:var(--st-primary); box-shadow:0 0 0 3px rgba(56,102,65,.15); }
      .dc-new-form textarea { min-height:70px; resize:vertical; }
      .btn-primary-dc { height:34px; padding:0 18px; background:var(--st-dark); border:none; border-radius:999px; color:#fff; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; align-self:flex-start; }
      .btn-primary-dc:hover { background:var(--st-primary); }
      .dc-thread-head { font-family:'Fraunces', Georgia, serif; font-size:19px; font-weight:600; color:var(--st-dark); margin-bottom:12px; }
      .dc-post { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--lms-border-2); }
      .dc-post:last-of-type { border-bottom:none; }
      .dc-ava { flex:none; width:32px; height:32px; border-radius:50%; display:grid; place-items:center; font-size:11.5px; font-weight:700; background:var(--st-tint); color:var(--st-dark); }
      .dc-ava.teacher { background:var(--st-dark); color:var(--st-beige); }
      .dc-post-main { flex:1; min-width:0; }
      .dc-post-meta { font-size:12px; color:var(--st-sub); margin-bottom:3px; }
      .dc-post-author { font-weight:700; color:var(--st-dark); }
      .dc-post-tag { margin-left:4px; font-size:10px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--st-primary); }
      .dc-post-body { font-size:13.5px; line-height:1.5; color:#2E3A3B; white-space:pre-wrap; word-break:break-word; }
      .btn-sm { height:28px; padding:0 10px; border:1px solid var(--lms-border); border-radius:6px; background:#fff; font-size:11px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--lms-ink); color:var(--lms-ink); }
      .btn-sm.danger:hover { border-color:#F5C6C2; color:#C0392B; }
      .dc-back { font-size:12px; color:#5A5A72; text-decoration:none; display:inline-block; margin-bottom:.75rem; }
      .dc-back:hover { color:var(--lms-ink); }
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

    // Whether this page was reached with an explicit subject_id (e.g. from
    // a My Courses card) — drives the subj-tabstrip below. Not the same as
    // $sel_subject, which also gets auto-filled to the first subject when
    // none is given, for this page's own subject picker.
    $subject_id   = (int) ($_GET['subject_id'] ?? 0);
    $subject_name = null;

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
    foreach ($subjects as $s) {
        if ((int) $s['subject_id'] === $subject_id) { $subject_name = $s['subject_name']; break; }
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
            WHERE dt.subject_id=? AND dt.section_id=? AND dt.school_year=? AND dt.kind = 'discussion'
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

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.222 0-2.386-.216-3.447-.61L3 21l1.395-4.184A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></span>
            <div class="student-panel-title">Discussions</div>
          </div>
        </div>

        <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast style="margin-bottom:.75rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

        <?php if (empty($sy_list) || empty($subjects)): ?>
          <p class="empty-state">No subjects found yet.</p>
        <?php else: ?>
          <form method="GET" class="lms-picker">
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

            <h2 class="dc-thread-head">
              <?= htmlspecialchars($thread_detail['title']) ?>
              <?php if ($thread_detail['is_locked']): ?><span class="dc-lock-badge">LOCKED</span><?php endif; ?>
            </h2>

            <?php foreach ($posts as $p):
              [$pg, $pf] = array_pad(explode(' ', (string) ($p['author_name'] ?? 'User'), 2), 2, '');
              $isTeacher = $p['posted_by_type'] === 'teacher';
            ?>
              <div class="dc-post">
                <span class="dc-ava<?= $isTeacher ? ' teacher' : '' ?>" aria-hidden="true"><?= htmlspecialchars(lms_initials($pg, $pf)) ?></span>
                <div class="dc-post-main">
                <div class="dc-post-meta">
                  <span class="dc-post-author"><?= htmlspecialchars($p['author_name'] ?? 'User') ?></span><?php if ($isTeacher): ?><span class="dc-post-tag">Teacher</span><?php endif; ?>
                  · <?= date('M j, Y g:i A', strtotime($p['posted_at'])) ?>
                </div>
                <div class="dc-post-body"><?= htmlspecialchars($p['body']) ?></div>
                <?php if ($p['posted_by_type'] === 'student' && (int) $p['posted_by_student_id'] === $student_id): ?>
                  <form method="POST" style="display:inline;" data-confirm="Delete this post?" data-icon="warning">
                    <input type="hidden" name="post_id" value="<?= (int)$p['post_id'] ?>">
                    <input type="hidden" name="thread_id" value="<?= $sel_thread ?>">
                    <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                    <input type="hidden" name="section_id" value="<?= $sec_id ?>">
                    <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                    <button type="submit" name="delete_post" class="btn-sm danger" style="margin-top:6px;">Delete</button>
                  </form>
                <?php endif; ?>
                </div>
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

            <details class="dc-new"<?= empty($threads) ? ' open' : '' ?>>
              <summary>Start a new thread</summary>
              <form method="POST" class="dc-new-form">
                <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                <input type="hidden" name="section_id" value="<?= $sec_id ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                <input type="text" name="title" placeholder="Thread title" aria-label="Thread title" required>
                <textarea name="body" placeholder="Opening message" aria-label="Opening message" required></textarea>
                <button type="submit" name="new_thread" class="btn-primary-dc">Start thread</button>
              </form>
            </details>

            <?php if (empty($threads)): ?>
              <p class="empty-state">No discussions yet for this subject.</p>
            <?php else: ?>
              <ul class="dc-threads">
              <?php foreach ($threads as $t): $replies = max(0, (int) $t['post_count'] - 1); ?>
                <li class="dc-thread-row">
                  <span class="hm-tile" aria-hidden="true"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.222 0-2.386-.216-3.447-.61L3 21l1.395-4.184A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></span>
                  <div class="dc-thread-main">
                    <div class="dc-thread-title">
                      <a href="student_discussions?sy=<?= urlencode($selected_sy) ?>&subject_id=<?= $sel_subject ?>&thread_id=<?= (int)$t['thread_id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                      <?php if ($t['is_locked']): ?><span class="dc-lock-badge">LOCKED</span><?php endif; ?>
                    </div>
                    <div class="dc-thread-meta">Started <?= date('M j', strtotime($t['created_at'])) ?><?php if ($t['last_activity']): ?> · last post <?= date('M j, g:i A', strtotime($t['last_activity'])) ?><?php endif; ?></div>
                  </div>
                  <span class="dc-count"><?= $replies ?> <?= $replies === 1 ? 'reply' : 'replies' ?></span>
                </li>
              <?php endforeach; ?>
              </ul>
            <?php endif; ?>

          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
