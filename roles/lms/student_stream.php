<?php
// Subject Stream — the subject's home page in the Student LMS. Shows the
// teacher's class announcements, newest first, each with its comment
// thread; everyone taking the subject in this section sees and can join
// the same comments.
//
// Announcements are discussion threads with kind = 'announcement' (see
// schema/discussion_thread_kind.sql): the teacher posts them from
// roles/teacher/teacher_discussions.php, the first post is the
// announcement text, and every later post is a comment. Commenting goes
// through the exact same disc_student_reply() as a Discussions reply.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/lms_class.php';
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
require_once BASE_PATH . '/shared/helpers/lms_tasks.php';

$is_student = require_login('student', null, 'ignore', 'bool');
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;

// ── Comment on an announcement ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment']) && $is_student) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');
    $body       = trim($_POST['body'] ?? '');

    [$ok, $msg] = disc_student_reply($conn, $student_id, $thread_id, $subject_id, $section_id, $sy, $body);
    $_SESSION['st_flash'] = $msg;
    $_SESSION['st_flash_type'] = $ok ? 'success' : 'error';
    header('Location: student_stream?' . http_build_query(['subject_id' => $subject_id, 'sy' => $sy]) . '#ann-' . $thread_id);
    exit();
}

// ── Delete own comment ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post']) && $is_student) {
    $thread_id  = (int) ($_POST['thread_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $sy         = trim($_POST['school_year'] ?? '');

    $ok = disc_student_delete_post($conn, $student_id, (int) ($_POST['post_id'] ?? 0), $thread_id);
    $_SESSION['st_flash'] = $ok ? 'Comment deleted.' : 'That comment could not be deleted.';
    $_SESSION['st_flash_type'] = $ok ? 'success' : 'error';
    header('Location: student_stream?' . http_build_query(['subject_id' => $subject_id, 'sy' => $sy]) . '#ann-' . $thread_id);
    exit();
}

$flash      = $_SESSION['st_flash']      ?? '';
$flash_type = $_SESSION['st_flash_type'] ?? 'info';
unset($_SESSION['st_flash'], $_SESSION['st_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stream — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      .st-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
      .st-hero { display:flex; align-items:center; gap:18px; background:var(--st-dark); color:var(--st-beige); border-radius:14px; padding:22px 24px; margin-bottom:18px; }
      .st-hero-icon { flex:none; width:56px; height:56px; border-radius:12px; display:grid; place-items:center; background:rgba(254,250,224,.10); color:var(--st-beige); }
      .st-hero-icon svg { width:30px; height:30px; stroke-width:6; }
      .st-hero-main { min-width:0; }
      .st-hero-eyebrow { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--st-muted); }
      .st-hero h1 { margin:4px 0 6px; font-family:'Fraunces', Georgia, serif; font-weight:600; font-size:clamp(1.4rem, 3vw, 1.9rem); line-height:1.2; color:var(--st-beige); }
      .st-hero-meta { font-size:13px; color:var(--st-muted); }
      .st-hero-meta b { color:var(--st-beige); font-weight:600; }
      @media (max-width: 560px) { .st-hero-icon { display:none; } }

      .st-grid { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:18px; align-items:start; }
      .st-card .hm-todo { max-height:none; }
      .st-card .hm-todo-item { padding:8px 2px; }
      .st-card-none { margin:0; font-size:13px; color:var(--st-sub); }
      @media (max-width: 900px) { .st-grid { grid-template-columns:1fr; } .st-side { order:-1; } }

      .st-ann { background:#fff; border:1px solid var(--lms-border); border-radius:14px; margin-bottom:1rem; overflow:hidden; scroll-margin-top:80px; }
      .st-ann-head { display:flex; gap:12px; align-items:center; padding:16px 18px 0; }
      .st-ava { flex:none; width:36px; height:36px; border-radius:50%; display:grid; place-items:center; font-size:12.5px; font-weight:700;
                background:var(--lms-cream); color:var(--lms-ink); }
      .st-ava.sm { width:30px; height:30px; font-size:11px; }
      .st-ava.teacher { background:var(--lms-ink); color:#fff; }
      .st-ann-who { font-size:13px; font-weight:600; color:#1A1A2E; }
      .st-ann-when { font-size:12px; color:var(--lms-muted-2); }
      .st-ann-body { padding:12px 18px 16px; }
      .st-ann-title { margin:0 0 6px; font-size:15px; font-weight:650; color:#1A1A2E; }
      .st-ann-text { font-size:14px; line-height:1.55; color:#34423B; white-space:pre-wrap; word-break:break-word; }

      .st-comments { border-top:1px solid var(--lms-border-2); background:var(--lms-surface-soft); padding:12px 18px 14px; }
      .st-comments-count { font-size:12.5px; font-weight:600; color:var(--lms-muted); margin-bottom:8px; display:flex; align-items:center; gap:6px; }
      .st-more summary { cursor:pointer; list-style:none; font-size:12.5px; font-weight:600; color:var(--lms-ink-accent); margin-bottom:8px; }
      .st-more summary::-webkit-details-marker { display:none; }
      .st-more[open] summary { display:none; }
      .st-c { display:flex; gap:10px; padding:7px 0; }
      .st-c-main { min-width:0; flex:1; }
      .st-c-meta { font-size:12px; color:var(--lms-muted-2); }
      .st-c-meta b { color:#1A1A2E; font-weight:600; }
      .st-c-meta .tag { margin-left:4px; font-size:10.5px; font-weight:700; color:var(--lms-ink-accent); text-transform:uppercase; letter-spacing:.04em; }
      .st-c-text { font-size:13.5px; line-height:1.5; color:#34423B; white-space:pre-wrap; word-break:break-word; }
      .st-c-del { border:0; background:none; padding:0; margin-left:6px; font-size:11.5px; font-family:inherit; color:var(--lms-muted-2); cursor:pointer; text-decoration:underline; text-underline-offset:2px; }
      .st-c-del:hover { color:#C0392B; }

      .st-reply { display:flex; gap:10px; align-items:flex-start; margin-top:8px; }
      .st-reply textarea { flex:1; min-height:38px; height:38px; max-height:160px; resize:vertical; box-sizing:border-box; border:1px solid var(--lms-border); border-radius:19px;
                           background:#fff; padding:9px 14px; font-size:13.5px; font-family:inherit; color:#1A1A2E; }
      .st-reply textarea:focus { outline:none; border-color:var(--lms-ink); }
      .st-send { flex:none; height:38px; padding:0 16px; border:0; border-radius:19px; background:var(--lms-ink); color:#fff; font-size:13px; font-weight:600; font-family:inherit; cursor:pointer; }
      .st-send:hover { background:var(--lms-ink-hover); }
      .st-send:disabled { opacity:.45; cursor:not-allowed; }
      .st-locked { font-size:12.5px; color:var(--lms-muted); margin-top:4px; }

      .st-card { background:#fff; border:1px solid var(--lms-border); border-radius:14px; padding:16px 18px; margin-bottom:14px; }
      .st-card h2 { display:flex; align-items:center; gap:10px; margin:0 0 12px; font-family:'Fraunces', Georgia, serif; font-size:16px; font-weight:600; color:var(--st-dark); }
      .st-card h2 .hm-tile { width:30px; height:30px; background:var(--st-info); color:var(--st-dark); }
      .st-person { display:flex; align-items:center; gap:10px; padding:5px 0; font-size:13.5px; color:#1A1A2E; }
      .st-people { list-style:none; margin:0; padding:0; }
      .st-card-count { margin-left:auto; font-family:'Inter', sans-serif; font-size:12px; font-weight:700; color:var(--st-sub); }
      .st-more-btn { display:block; margin-top:6px; padding:0; border:0; background:none; font:inherit; font-size:12.5px; font-weight:700; color:var(--st-dark); cursor:pointer; text-decoration:underline; text-underline-offset:2px; }
      .st-more-btn:hover { color:var(--st-primary); }
      .st-card-link { display:inline-block; margin-top:8px; font-size:13px; font-weight:600; color:var(--lms-ink-accent); text-decoration:none; }
      .st-card-link:hover { text-decoration:underline; text-underline-offset:2px; }

      .st-empty { text-align:center; padding:40px 20px; background:#fff; border:1px dashed var(--lms-border); border-radius:12px; }
      .st-empty h2 { margin:0 0 4px; font-size:15px; color:#1A1A2E; }
      .st-empty p { margin:0; font-size:13.5px; color:var(--lms-muted); }
    </style>
</head>
<body class="student-layout lms-layout lms-warm-bg">
<?php if (!$is_student): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $sy_list = [];
    $sy_stmt = $conn->prepare("SELECT DISTINCT school_year FROM enrollments WHERE student_id = ? ORDER BY school_year DESC");
    $sy_stmt->bind_param('i', $student_id);
    $sy_stmt->execute();
    foreach ($sy_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $sy_list[] = $row['school_year'];
    $sy_stmt->close();

    $selected_sy = $_GET['sy'] ?? '';
    if (!in_array($selected_sy, $sy_list, true)) $selected_sy = $sy_list[0] ?? '';

    $enr_stmt = $conn->prepare("
        SELECT e.section_id, sec.section_name
        FROM enrollments e
        JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    $enr_stmt->bind_param('is', $student_id, $selected_sy);
    $enr_stmt->execute();
    $enrollment = $enr_stmt->get_result()->fetch_assoc();
    $enr_stmt->close();
    $sec_id = $enrollment ? (int) $enrollment['section_id'] : 0;

    $subjects = [];
    if ($sec_id > 0) {
        $subj_stmt = $conn->prepare("
            SELECT DISTINCT sub.subject_id, sub.subject_name
            FROM section_subjects ss JOIN subjects sub ON sub.subject_id = ss.subject_id
            WHERE ss.section_id = ?
            ORDER BY sub.subject_name
        ");
        $subj_stmt->bind_param('i', $sec_id);
        $subj_stmt->execute();
        $subjects = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $subj_stmt->close();
    }

    // Stream is always about one subject — fall back to the first one so the
    // subject tab strip (which keys off $subject_id) always shows.
    $subject_id   = (int) ($_GET['subject_id'] ?? 0);
    $subject_name = null;
    foreach ($subjects as $s) {
        if ((int) $s['subject_id'] === $subject_id) { $subject_name = $s['subject_name']; break; }
    }
    if ($subject_name === null && $subjects) {
        $subject_id   = (int) $subjects[0]['subject_id'];
        $subject_name = $subjects[0]['subject_name'];
    }

    $in_class      = $subject_name !== null && disc_student_owns_class($conn, $student_id, $subject_id, $sec_id, $selected_sy);
    $teachers      = [];
    $student_count = 0;
    $announcements = [];
    $posts_by      = [];
    $upcoming      = [];
    $classmates    = [];
    if ($in_class) {
        $teachers      = lms_class_teachers($conn, $subject_id, $sec_id, $selected_sy);
        $class_students = lms_class_students($conn, $subject_id, $sec_id, $selected_sy);
        $student_count  = count($class_students);
        // Sidebar preview: up to 10 classmates (you're not listed — the
        // People page shows everyone, you included).
        $classmates = array_values(array_filter($class_students, fn($s) => (int) $s['student_id'] !== $student_id));

        $a_stmt = $conn->prepare("
            SELECT dt.thread_id, dt.title, dt.is_locked, dt.created_at, t.given_name, t.family_name
            FROM discussion_threads dt
            LEFT JOIN teachers t ON t.teacher_id = dt.created_by_teacher_id
            WHERE dt.subject_id = ? AND dt.section_id = ? AND dt.school_year = ? AND dt.kind = 'announcement'
            ORDER BY dt.created_at DESC, dt.thread_id DESC
        ");
        $a_stmt->bind_param('iis', $subject_id, $sec_id, $selected_sy);
        $a_stmt->execute();
        $announcements = $a_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $a_stmt->close();
        $posts_by = disc_posts_by_thread($conn, array_column($announcements, 'thread_id'));

        // Upcoming — this subject's slice of LMS Home's To-Do: work not yet
        // turned in / quizzes not yet submitted, soonest first.
        $u_stmt = $conn->prepare("
            SELECT gi.item_id, gi.title, gi.due_date, gi.category, gi.is_quiz
            FROM gradebook_items gi
            LEFT JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ?
            WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
              AND gi.due_date IS NOT NULL AND (gs.submission_id IS NULL OR gs.status <> 'turned_in')
            UNION
            SELECT gi.item_id, gi.title, gi.due_date, gi.category, gi.is_quiz
            FROM gradebook_items gi
            LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
            WHERE gi.subject_id = ? AND gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
              AND gi.due_date IS NOT NULL AND (qa.attempt_id IS NULL OR qa.submitted_at IS NULL)
            ORDER BY due_date
            LIMIT 5
        ");
        $u_stmt->bind_param('iiisiiis', $student_id, $subject_id, $sec_id, $selected_sy, $student_id, $subject_id, $sec_id, $selected_sy);
        $u_stmt->execute();
        $upcoming = $u_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $u_stmt->close();
    }

    $when = fn(string $ts) => date('M j, Y · g:i A', strtotime($ts));
    $qs   = http_build_query(['subject_id' => $subject_id, 'sy' => $selected_sy]);
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Stream
          <span class="student-topbar-subtitle">Announcements from your teacher</span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <?php include BASE_PATH . '/shared/includes/lms_subject_tabs.php'; ?>

    <div class="student-content">
      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast style="margin-bottom:.75rem;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if ($subject_name === null): ?>
        <div class="st-empty"><h2>No subjects yet</h2><p>Your subjects appear here once you're enrolled in a section.</p></div>
      <?php elseif (!$in_class): ?>
        <div class="st-empty"><h2>This subject isn't part of your load</h2><p>You can only see the Stream of subjects you're enrolled in this school year.</p></div>
      <?php else: ?>

        <header class="st-hero">
          <span class="st-hero-icon" aria-hidden="true"><?= subject_banner_icon($subject_name) ?></span>
          <div class="st-hero-main">
            <div class="st-hero-eyebrow">SY <?= htmlspecialchars($selected_sy) ?> · <?= htmlspecialchars($enrollment['section_name']) ?></div>
            <h1><?= htmlspecialchars($subject_name) ?></h1>
            <div class="st-hero-meta">
              <?php if ($teachers): ?>
                <b><?= htmlspecialchars(implode(', ', array_map(fn($t) => $t['given_name'] . ' ' . $t['family_name'], $teachers))) ?></b>
              <?php else: ?>Teacher not yet assigned<?php endif; ?>
              · <?= $student_count ?> <?= $student_count === 1 ? 'student' : 'students' ?>
            </div>
          </div>
        </header>

        <div class="st-grid">
          <main>
            <?php if (!$announcements): ?>
              <div class="st-empty">
                <h2>No announcements yet</h2>
                <p>When your teacher posts something for this class, it shows up here.</p>
              </div>
            <?php endif; ?>

            <?php foreach ($announcements as $a):
              $tid      = (int) $a['thread_id'];
              $posts    = $posts_by[$tid] ?? [];
              // The opening teacher post is the announcement itself; the rest are comments.
              $opening  = ($posts && $posts[0]['posted_by_type'] === 'teacher') ? array_shift($posts) : null;
              $n        = count($posts);
              $older    = $n > 3 ? array_slice($posts, 0, $n - 2) : [];
              $recent   = $n > 3 ? array_slice($posts, $n - 2) : $posts;
              $author   = trim(($a['given_name'] ?? '') . ' ' . ($a['family_name'] ?? '')) ?: 'Teacher';
              $render_c = function (array $p) use ($student_id, $tid, $subject_id, $selected_sy, $when) {
                  $mine = $p['posted_by_type'] === 'student' && (int) $p['posted_by_student_id'] === $student_id;
                  [$g, $f] = array_pad(explode(' ', (string) $p['author_name'], 2), 2, '');
                  ?>
                  <div class="st-c">
                    <span class="st-ava sm <?= $p['posted_by_type'] === 'teacher' ? 'teacher' : '' ?>" aria-hidden="true"><?= htmlspecialchars(lms_initials($g, $f)) ?></span>
                    <div class="st-c-main">
                      <div class="st-c-meta">
                        <b><?= htmlspecialchars($p['author_name'] ?? 'User') ?></b><?php if ($p['posted_by_type'] === 'teacher'): ?><span class="tag">Teacher</span><?php endif; ?>
                        · <?= htmlspecialchars($when($p['posted_at'])) ?>
                        <?php if ($mine): ?>
                          <form method="POST" style="display:inline" data-confirm="Delete your comment?" data-icon="warning">
                            <input type="hidden" name="post_id" value="<?= (int) $p['post_id'] ?>">
                            <input type="hidden" name="thread_id" value="<?= $tid ?>">
                            <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
                            <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                            <button type="submit" name="delete_post" class="st-c-del">Delete</button>
                          </form>
                        <?php endif; ?>
                      </div>
                      <div class="st-c-text"><?= htmlspecialchars($p['body']) ?></div>
                    </div>
                  </div>
                  <?php
              };
            ?>
              <article class="st-ann" id="ann-<?= $tid ?>">
                <div class="st-ann-head">
                  <span class="st-ava teacher" aria-hidden="true"><?= htmlspecialchars(lms_initials($a['given_name'] ?? 'T', $a['family_name'] ?? '')) ?></span>
                  <div>
                    <div class="st-ann-who"><?= htmlspecialchars($author) ?></div>
                    <div class="st-ann-when"><time datetime="<?= htmlspecialchars(date('c', strtotime($a['created_at']))) ?>"><?= htmlspecialchars($when($a['created_at'])) ?></time></div>
                  </div>
                </div>
                <div class="st-ann-body">
                  <h2 class="st-ann-title"><?= htmlspecialchars($a['title']) ?></h2>
                  <?php if ($opening): ?><div class="st-ann-text"><?= htmlspecialchars($opening['body']) ?></div><?php endif; ?>
                </div>

                <div class="st-comments">
                  <?php if ($n): ?>
                    <div class="st-comments-count">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20H7l-4 3V6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2z"/></svg>
                      <?= $n ?> class <?= $n === 1 ? 'comment' : 'comments' ?>
                    </div>
                    <?php if ($older): ?>
                      <details class="st-more">
                        <summary>View <?= count($older) ?> earlier <?= count($older) === 1 ? 'comment' : 'comments' ?></summary>
                        <?php foreach ($older as $p) $render_c($p); ?>
                      </details>
                    <?php endif; ?>
                    <?php foreach ($recent as $p) $render_c($p); ?>
                  <?php endif; ?>

                  <?php if ((int) $a['is_locked'] === 1): ?>
                    <p class="st-locked">Comments are turned off for this announcement.</p>
                  <?php else: ?>
                    <form method="POST" class="st-reply">
                      <input type="hidden" name="thread_id" value="<?= $tid ?>">
                      <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
                      <input type="hidden" name="section_id" value="<?= $sec_id ?>">
                      <input type="hidden" name="school_year" value="<?= htmlspecialchars($selected_sy) ?>">
                      <label class="st-sr" for="st-c-<?= $tid ?>">Add a class comment</label>
                      <textarea id="st-c-<?= $tid ?>" name="body" rows="1" placeholder="Add a class comment…" required data-autogrow></textarea>
                      <button type="submit" name="comment" class="st-send" disabled>Post</button>
                    </form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </main>

          <aside class="st-side">
            <section class="st-card" aria-labelledby="st-upcoming">
              <h2 id="st-upcoming"><span class="hm-tile" aria-hidden="true"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>Upcoming</h2>
              <?php if (!$upcoming): ?>
                <p class="st-card-none">Nothing due for this class — you're all caught up.</p>
              <?php else: ?>
                <ul class="hm-todo">
                  <?php foreach ($upcoming as $u):
                    $kind = lms_task_kind($u);
                    $dl   = lms_due_short($u['due_date']);
                    $url  = $u['is_quiz'] ? 'take_quiz?item_id=' . (int) $u['item_id'] : 'student_assignments?' . $qs . '#as-item-' . (int) $u['item_id'];
                  ?>
                    <li class="hm-todo-item">
                      <div class="hm-todo-main">
                        <a class="hm-todo-title" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($u['title']) ?></a>
                        <span class="hm-todo-meta"><span class="hm-kind <?= lms_kind_class($kind) ?>"><?= $kind ?></span><?= date('M j, g:i A', strtotime($u['due_date'])) ?></span>
                      </div>
                      <span class="hm-todo-due<?= lms_due_tone($dl) ?>"><?= $dl ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>

            <section class="st-card" aria-labelledby="st-teachers">
              <h2 id="st-teachers"><?= count($teachers) === 1 ? 'Teacher' : 'Teachers' ?></h2>
              <?php if (!$teachers): ?>
                <p style="margin:0;font-size:13px;color:var(--lms-muted)">Not yet assigned.</p>
              <?php endif; ?>
              <?php foreach ($teachers as $t): ?>
                <div class="st-person">
                  <span class="st-ava sm teacher" aria-hidden="true"><?= htmlspecialchars(lms_initials($t['given_name'], $t['family_name'])) ?></span>
                  <?= htmlspecialchars($t['given_name'] . ' ' . $t['family_name']) ?>
                </div>
              <?php endforeach; ?>
            </section>

            <section class="st-card" aria-labelledby="st-classmates">
              <h2 id="st-classmates">Classmates <span class="st-card-count"><?= count($classmates) ?></span></h2>
              <?php if (!$classmates): ?>
                <p class="st-card-none">No classmates enrolled in this subject yet.</p>
              <?php else: ?>
                <ul class="st-people" id="stPeople">
                  <?php foreach (array_slice($classmates, 0, 10) as $i => $s): ?>
                    <li class="st-person"<?= $i >= 5 ? ' hidden' : '' ?>>
                      <span class="st-ava sm" aria-hidden="true"><?= htmlspecialchars(lms_initials($s['given_name'], $s['family_name'])) ?></span>
                      <?= htmlspecialchars($s['given_name'] . ' ' . $s['family_name']) ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if (count($classmates) > 5): ?>
                  <button type="button" class="st-more-btn" id="stPeopleMore" aria-controls="stPeople" aria-expanded="false">Show <?= min(10, count($classmates)) - 5 ?> more</button>
                <?php endif; ?>
              <?php endif; ?>
              <a class="st-card-link" href="student_people?<?= $qs ?>">See everyone (<?= $student_count ?> <?= $student_count === 1 ? 'student' : 'students' ?>) &rarr;</a>
            </section>
          </aside>
        </div>

      <?php endif; ?>
    </div>
  </div>

  <script>
    // Comment boxes: grow with the text, and "Post" only enables once there's something to send.
    document.querySelectorAll('textarea[data-autogrow]').forEach(function (ta) {
      var btn = ta.form.querySelector('.st-send');
      ta.addEventListener('input', function () {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight + 2, 160) + 'px';
        btn.disabled = !ta.value.trim();
      });
      ta.addEventListener('keydown', function (e) {
        // Ctrl/Cmd+Enter posts, plain Enter keeps adding lines.
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && ta.value.trim()) { e.preventDefault(); ta.form.requestSubmit(btn); }
      });
    });
    // Classmates card: 5 shown, "Show more" reveals up to 10 (the rest are on People).
    (function () {
      var btn = document.getElementById('stPeopleMore');
      if (!btn) return;
      var extra = document.querySelectorAll('#stPeople li[hidden]'), label = btn.textContent;
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        extra.forEach(function (li) { li.hidden = !open; });
        btn.setAttribute('aria-expanded', open);
        btn.textContent = open ? 'Show less' : label;
      });
    })();
    document.querySelectorAll('.st-reply').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (f.dataset.busy === '1') { e.preventDefault(); return; }
        f.dataset.busy = '1';
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
