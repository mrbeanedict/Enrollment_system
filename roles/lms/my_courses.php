<?php
// My Courses belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, rather than guessing between STUDENT_SESSID
// and STUDENT_LMS_SESSID. See student_lessons.php for why.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

// Picks a decorative illustration matching what the subject actually
// is, by keyword against subjects.subject_name (checked against the
// real 47 distinct names in this DB) — not just a flat color per
// card. Order matters: more specific categories (Business) are
// checked before broader ones (Social Science) so a name like
// "Business Ethics and Social Responsibility" lands correctly.
function subject_banner_icon(string $subjectName): string {
    $name = mb_strtolower($subjectName);
    $categories = [
        'math'     => ['calculus', 'mathematics', 'statistics and probability'],
        'biology'  => ['biology'],
        'chemistry'=> ['chemistry'],
        'physics'  => ['physics'],
        'computer' => ['computer', 'programming', 'systems servicing', 'systems analysis', 'web development', 'networks and critical thinking'],
        'business' => ['economics', 'business', 'entrepreneurship', 'accountancy', 'management', 'marketing'],
        'religion' => ['religion'],
        'research' => ['research'],
        'media'    => ['media and information'],
        'immersion'=> ['work immersion'],
        'social'   => ['social science', 'social sciences', 'politics and governance', 'citizenship', 'disaster readiness'],
        'language' => ['communication', 'reading and writing', 'creative writing', 'creative nonfiction'],
        'arts'     => ['arts from the regions', 'humanities'],
    ];

    $svgAttrs = 'viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'math' => "<svg $svgAttrs><path d=\"M15 90 L15 10\"/><path d=\"M10 85 L90 85\"/><path d=\"M20 70 Q45 20 65 50 T90 15\"/></svg>",
        'biology' => "<svg $svgAttrs><path d=\"M20 5 L80 25 L20 45 L80 65 L20 85 L80 95\"/><path d=\"M80 5 L20 25 L80 45 L20 65 L80 85 L20 95\"/></svg>",
        'chemistry' => "<svg $svgAttrs><path d=\"M40 8 L40 35 L15 82 Q12 90 22 90 L78 90 Q88 90 85 82 L60 35 L60 8\"/><path d=\"M33 8 L67 8\"/><circle cx=\"38\" cy=\"70\" r=\"4\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"58\" cy=\"76\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"48\" cy=\"58\" r=\"3\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'physics' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"4\"><circle cx=\"50\" cy=\"50\" r=\"6\" fill=\"currentColor\" stroke=\"none\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\" transform=\"rotate(60 50 50)\"/><ellipse cx=\"50\" cy=\"50\" rx=\"45\" ry=\"18\" transform=\"rotate(120 50 50)\"/></svg>",
        'computer' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"6\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M35 20 L10 50 L35 80\"/><path d=\"M65 20 L90 50 L65 80\"/><path d=\"M58 12 L42 88\"/></svg>",
        'business' => "<svg $svgAttrs><rect x=\"10\" y=\"35\" width=\"80\" height=\"50\" rx=\"5\"/><path d=\"M35 35 L35 22 Q35 15 42 15 L58 15 Q65 15 65 22 L65 35\"/><path d=\"M10 55 L90 55\"/><rect x=\"25\" y=\"62\" width=\"10\" height=\"15\" fill=\"currentColor\" stroke=\"none\"/><rect x=\"45\" y=\"52\" width=\"10\" height=\"25\" fill=\"currentColor\" stroke=\"none\"/><rect x=\"65\" y=\"45\" width=\"10\" height=\"32\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'religion' => "<svg $svgAttrs><path d=\"M50 88 C20 65, 8 45, 8 28 C8 12, 25 5, 38 15 Q50 25, 50 30 Q50 25, 62 15 C75 5, 92 12, 92 28 C92 45, 80 65, 50 88 Z\"/></svg>",
        'research' => "<svg $svgAttrs><rect x=\"10\" y=\"8\" width=\"55\" height=\"70\" rx=\"4\"/><path d=\"M22 25 L53 25 M22 38 L53 38 M22 51 L40 51\"/><circle cx=\"68\" cy=\"65\" r=\"18\"/><path d=\"M81 78 L95 92\"/></svg>",
        'media' => "<svg $svgAttrs><rect x=\"8\" y=\"28\" width=\"84\" height=\"58\" rx=\"8\"/><path d=\"M35 28 L40 15 L60 15 L65 28\"/><circle cx=\"50\" cy=\"57\" r=\"19\"/></svg>",
        'immersion' => "<svg $svgAttrs><rect x=\"8\" y=\"38\" width=\"84\" height=\"48\" rx=\"5\"/><path d=\"M35 38 L35 22 Q35 15 44 15 L56 15 Q65 15 65 22 L65 38\"/><path d=\"M8 60 L38 60 M62 60 L92 60\"/><rect x=\"38\" y=\"52\" width=\"24\" height=\"16\" fill=\"currentColor\" stroke=\"none\"/></svg>",
        'social' => "<svg viewBox=\"0 0 100 100\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"4\" stroke-linecap=\"round\"><circle cx=\"50\" cy=\"50\" r=\"42\"/><path d=\"M8 50 L92 50 M50 8 L50 92\"/><path d=\"M22 22 Q50 50 22 78 M78 22 Q50 50 78 78\"/></svg>",
        'language' => "<svg $svgAttrs><path d=\"M50 25 Q30 12 10 20 L10 78 Q30 70 50 82 Q70 70 90 78 L90 20 Q70 12 50 25 Z\"/><path d=\"M50 25 L50 82\"/></svg>",
        'arts' => "<svg $svgAttrs><path d=\"M50 10 Q15 10 15 42 Q15 65 38 65 Q45 65 45 57 Q45 50 52 50 Q88 50 88 25 Q88 10 50 10 Z\"/><circle cx=\"32\" cy=\"28\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"50\" cy=\"22\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"68\" cy=\"26\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/><circle cx=\"72\" cy=\"40\" r=\"5\" fill=\"currentColor\" stroke=\"none\"/></svg>",
    ];
    // Fallback: generic open book — same shape as 'language', a
    // reasonable default for "a subject" in general.
    $fallback = "<svg $svgAttrs><path d=\"M50 25 Q30 12 10 20 L10 78 Q30 70 50 82 Q70 70 90 78 L90 20 Q70 12 50 25 Z\"/><path d=\"M50 25 L50 82\"/></svg>";

    foreach ($categories as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw)) return $icons[$key];
        }
    }
    return $fallback;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <style>
      .mc-picker { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
      .mc-picker select {
        height:36px; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
        padding:0 10px; font-size:13px; font-family:inherit; color:#1A1A2E;
      }

      /* ══════════════════════════════════════════════════════════════════
         Glassmorphism trial — My Courses only. Mirrors the liquid-blob
         backdrop and frosted-glass surfaces from roles/lms/lms_login.php
         (see css_lms.css's .auth-backdrop / .auth-shell), re-applied to this
         page's existing student-layout/course-card structure instead of the
         login screen's split panel. Everything below is scoped under
         body.mc-glass-page so it can never leak into any other page that
         also loads css_student.css. */

      body.mc-glass-page .student-main { background: transparent; }

      body.mc-glass-page {
        background-image:
          repeating-linear-gradient(90deg, rgba(255,255,255,0.05) 0px, rgba(255,255,255,0.05) 1px, transparent 1px, transparent 4px),
          linear-gradient(160deg, #FBFDF9 0%, #EDF5EE 32%, #D9E8DE 66%, #C3DACB 100%);
        background-attachment: fixed;
      }

      /* Anchored inside .student-content (not the full viewport) — that
         area is the only part of this layout not already covered by an
         opaque sidebar/topbar, so the blobs need to live there to read at
         all instead of drifting, unseen, behind the app chrome. */
      body.mc-glass-page .student-content { position: relative; }
      .mc-glass-backdrop {
        position: absolute; inset: 0; z-index: 0;
        overflow: hidden; pointer-events: none;
      }
      .mc-glass-backdrop span {
        position: absolute; filter: blur(70px); will-change: transform, border-radius;
        animation: mc-drift 20s ease-in-out infinite;
      }
      .mc-blob-cream { width: 30vw; aspect-ratio: 1; top: -8%; left: 4%; background: var(--color-accent, #FEFAE0); opacity: 0.8; }
      .mc-blob-info  { width: 26vw; aspect-ratio: 1; top: 6%; right: 6%; background: var(--color-info, #CADEDE); opacity: 0.85; animation-duration: 24s; animation-direction: reverse; }
      .mc-blob-primary { width: 30vw; aspect-ratio: 1; bottom: -12%; left: 30%; background: var(--color-primary, #386641); opacity: 0.4; animation-duration: 27s; }
      @keyframes mc-drift {
        0%, 100% { transform: translate(0,0) scale(1); border-radius: 42% 58% 65% 35% / 45% 40% 60% 55%; }
        50%      { transform: translate(4%, 5%) scale(1.08); border-radius: 60% 40% 45% 55% / 55% 60% 40% 45%; }
      }
      @media (prefers-reduced-motion: reduce) {
        .mc-glass-backdrop span { animation: none; }
      }

      body.mc-glass-page .student-panel-block { position: relative; z-index: 1; }

      body.mc-glass-page .student-topbar {
        background: rgba(255,255,255,0.55);
        backdrop-filter: blur(18px) saturate(150%);
        -webkit-backdrop-filter: blur(18px) saturate(150%);
        border-bottom: 1px solid rgba(255,255,255,0.6);
      }

      body.mc-glass-page .student-panel-block,
      body.mc-glass-page .course-card {
        background: rgba(255,255,255,0.42);
        backdrop-filter: blur(28px) saturate(180%);
        -webkit-backdrop-filter: blur(28px) saturate(180%);
        border: 1px solid rgba(255,255,255,0.65);
        box-shadow: 0 20px 50px -20px rgba(28,38,40,0.25), inset 0 1px 0 rgba(255,255,255,0.7);
      }
      body.mc-glass-page .student-panel-block { border-radius: 20px; }
      body.mc-glass-page .course-card { border-radius: 16px; }
    </style>
</head>
<body class="student-layout lms-layout mc-glass-page">
<?php if (!require_login('student', null, 'ignore', 'bool')): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $student_id = (int) $_SESSION['student_id'];

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

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.section_id, sec.section_name
        FROM enrollments e
        JOIN sections sec ON sec.section_id = e.section_id
        WHERE e.student_id = ? AND e.school_year = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "is", $student_id, $selected_sy);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $courses = [];
    $dueBySubject = [];
    $threadCountBySubject = [];
    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];
        $c_stmt = mysqli_prepare($conn, "
            SELECT sub.subject_id, sub.subject_name,
                   CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
            WHERE ss.section_id = ? AND ss.semester = ?
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($c_stmt, "is", $sec_id, $selected_sem);
        mysqli_stmt_execute($c_stmt);
        $courses = mysqli_fetch_all(mysqli_stmt_get_result($c_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($c_stmt);

        // Due tasks per subject — same "not yet submitted/attempted"
        // definition lms_home.php's To-do list uses, just grouped by
        // subject here instead of flattened into one list.
        $due_stmt = mysqli_prepare($conn, "
            SELECT gi.subject_id, gi.title, gi.due_date, 'Assignment' AS kind
            FROM gradebook_items gi
            LEFT JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
              AND gi.due_date IS NOT NULL AND gs.submission_id IS NULL
            UNION ALL
            SELECT gi.subject_id, gi.title, gi.due_date, 'Quiz' AS kind
            FROM gradebook_items gi
            LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
              AND gi.due_date IS NOT NULL AND (qa.attempt_id IS NULL OR qa.submitted_at IS NULL)
            ORDER BY due_date ASC
        ");
        mysqli_stmt_bind_param($due_stmt, "iisiis", $student_id, $sec_id, $selected_sy, $student_id, $sec_id, $selected_sy);
        mysqli_stmt_execute($due_stmt);
        $due_res = mysqli_stmt_get_result($due_stmt);
        while ($row = mysqli_fetch_assoc($due_res)) {
            $dueBySubject[(int) $row['subject_id']][] = $row;
        }
        mysqli_stmt_close($due_stmt);

        // Discussion thread counts per subject — a real count of existing
        // threads, not a fabricated "unread" badge: the LMS session has no
        // per-student read/seen tracking for posts to base one on.
        $thread_stmt = mysqli_prepare($conn, "
            SELECT subject_id, COUNT(*) AS thread_count
            FROM discussion_threads
            WHERE section_id = ? AND school_year = ?
            GROUP BY subject_id
        ");
        mysqli_stmt_bind_param($thread_stmt, "is", $sec_id, $selected_sy);
        mysqli_stmt_execute($thread_stmt);
        $thread_res = mysqli_stmt_get_result($thread_stmt);
        while ($row = mysqli_fetch_assoc($thread_res)) {
            $threadCountBySubject[(int) $row['subject_id']] = (int) $row['thread_count'];
        }
        mysqli_stmt_close($thread_stmt);
    }

    $palette_count = 6;
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Courses
          <span class="student-topbar-subtitle"><?= $enrollment ? htmlspecialchars($enrollment['section_name']) : '' ?></span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <div class="student-content">
      <div class="mc-glass-backdrop" aria-hidden="true">
        <span class="mc-blob-cream"></span>
        <span class="mc-blob-info"></span>
        <span class="mc-blob-primary"></span>
      </div>
      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg></span>
            <div class="student-panel-title">Course Overview</div>
          </div>
        </div>

        <?php if (empty($sy_list)): ?>
          <p class="empty-state">No enrollment record found yet.</p>
        <?php else: ?>
          <form method="GET" class="mc-picker">
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

          <?php if (empty($courses)): ?>
            <p class="empty-state">No courses found for this semester.</p>
          <?php else: ?>
            <div class="course-cards">
              <?php foreach ($courses as $i => $c):
                $subjId    = (int) $c['subject_id'];
                $dueItems  = $dueBySubject[$subjId] ?? [];
                $threadCnt = $threadCountBySubject[$subjId] ?? 0;
              ?>
                <div class="course-card">
                  <div class="course-card-banner palette-<?= $i % $palette_count ?>">
                    <div class="course-card-menu">
                      <button type="button" class="course-card-menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="Course options">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                      </button>
                      <div class="course-card-menu-panel">
                        <a href="student_lessons">Lessons</a>
                        <a href="student_discussions?subject_id=<?= $subjId ?>">Discussions</a>
                        <a href="student_assignments">Assignments</a>
                        <a href="student_quizzes">Quizzes</a>
                      </div>
                    </div>
                    <span class="course-card-banner-icon"><?= subject_banner_icon($c['subject_name']) ?></span>
                  </div>
                  <div class="course-card-body">
                    <div class="course-card-title"><?= htmlspecialchars($c['subject_name']) ?></div>
                    <div class="course-card-teacher"><?= htmlspecialchars($c['teacher_name'] ?? 'Teacher not yet assigned') ?></div>

                    <a class="course-card-thread-link" href="student_discussions?subject_id=<?= $subjId ?>">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.222 0-2.386-.216-3.447-.61L3 21l1.395-4.184A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                      <?= $threadCnt ?> <?= $threadCnt === 1 ? 'thread' : 'threads' ?>
                    </a>

                    <div class="course-card-due">
                      <div class="course-card-due-label">Due</div>
                      <?php if (empty($dueItems)): ?>
                        <div class="course-card-due-empty">None</div>
                      <?php else: ?>
                        <?php foreach (array_slice($dueItems, 0, 2) as $d): ?>
                          <div class="course-card-due-item">
                            <span class="due-kind-dot kind-<?= strtolower($d['kind']) ?>"></span>
                            <span class="due-item-title"><?= htmlspecialchars($d['kind']) ?> · <?= htmlspecialchars($d['title']) ?></span>
                            <span class="due-item-date"><?= date('M j', strtotime($d['due_date'])) ?></span>
                          </div>
                        <?php endforeach; ?>
                        <?php if (count($dueItems) > 2): ?>
                          <div class="course-card-due-more">+<?= count($dueItems) - 2 ?> more</div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    document.querySelectorAll('.course-card-menu-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var menu = btn.closest('.course-card-menu');
        var wasOpen = menu.classList.contains('open');
        document.querySelectorAll('.course-card-menu.open').forEach(function (m) { m.classList.remove('open'); });
        if (!wasOpen) menu.classList.add('open');
      });
    });
    document.addEventListener('click', function () {
      document.querySelectorAll('.course-card-menu.open').forEach(function (m) { m.classList.remove('open'); });
    });
  </script>
<?php endif; ?>
</body>
</html>
