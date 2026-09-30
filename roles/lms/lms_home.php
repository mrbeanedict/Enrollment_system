<?php
// LMS Home belongs to the Student LMS (see lms_sidebar.php) — always
// use that session, fixed, same as every other LMS page. See
// student_lessons.php for why this is fixed rather than guessed.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/lms_subject_icon.php';
require_once BASE_PATH . '/shared/helpers/lms_tasks.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student LMS — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
</head>
<body class="student-layout lms-layout lms-warm-bg lms-home-bg">
<?php if (!require_login('student', null, 'ignore', 'bool')): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access the Student LMS.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/lms_navbar.php';

    $student_id = (int) $_SESSION['student_id'];

    $stu_stmt = mysqli_prepare($conn, "SELECT given_name FROM students WHERE student_id = ?");
    mysqli_stmt_bind_param($stu_stmt, "i", $student_id);
    mysqli_stmt_execute($stu_stmt);
    mysqli_stmt_bind_result($stu_stmt, $home_given_name);
    mysqli_stmt_fetch($stu_stmt);
    mysqli_stmt_close($stu_stmt);

    // Same "most recent enrollment on file" convention every other
    // student page uses — not necessarily tied to a picker here since
    // this dashboard shows live/current standing, not a browsable history.
    $enr_stmt = mysqli_prepare($conn, "
        SELECT section_id, school_year FROM enrollments
        WHERE student_id = ? ORDER BY enrollment_date DESC, enrollment_id DESC LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $courses      = [];
    $todoItems    = [];
    $recentLessons = [];
    $dueThisWeekCount = 0;
    $newLessonsCount  = 0;
    $sy = $enrollment['school_year'] ?? '';

    if ($enrollment && $enrollment['section_id']) {
        $sec_id = (int) $enrollment['section_id'];

        // My Courses (deduped across both semesters — this is "what
        // I'm taking this year," not a semester-scoped browse view).
        $c_stmt = mysqli_prepare($conn, "
            SELECT sub.subject_id, sub.subject_name, MIN(CONCAT(t.given_name, ' ', t.family_name)) AS teacher_name
            FROM section_subjects ss
            JOIN subjects sub ON sub.subject_id = ss.subject_id
            LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
            WHERE ss.section_id = ?
            GROUP BY sub.subject_id, sub.subject_name
            ORDER BY sub.subject_name
        ");
        mysqli_stmt_bind_param($c_stmt, "i", $sec_id);
        mysqli_stmt_execute($c_stmt);
        $courses = mysqli_fetch_all(mysqli_stmt_get_result($c_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($c_stmt);

        // To-do: assignments not yet turned in... ('via' decides the link;
        // 'kind' is the label — a quarterly assessment shows as an Exam
        // however it's delivered, same grouping as helpers/grading.php.)
        $a_stmt = mysqli_prepare($conn, "
            SELECT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name, 'Assignment' AS via,
                   IF(gi.category = 'quarterly_assessment', 'Exam', 'Assignment') AS kind
            FROM gradebook_items gi
            JOIN subjects sub ON sub.subject_id = gi.subject_id
            LEFT JOIN gradebook_submissions gs ON gs.item_id = gi.item_id AND gs.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.accepts_submission = 1
              AND gi.due_date IS NOT NULL AND (gs.submission_id IS NULL OR gs.status <> 'turned_in')
        ");
        mysqli_stmt_bind_param($a_stmt, "iis", $student_id, $sec_id, $sy);
        mysqli_stmt_execute($a_stmt);
        $todoItems = mysqli_fetch_all(mysqli_stmt_get_result($a_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($a_stmt);

        // ...plus quizzes not yet attempted/submitted.
        $q_stmt = mysqli_prepare($conn, "
            SELECT DISTINCT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name, 'Quiz' AS via,
                   IF(gi.category = 'quarterly_assessment', 'Exam', 'Quiz') AS kind
            FROM gradebook_items gi
            JOIN subjects sub ON sub.subject_id = gi.subject_id
            LEFT JOIN quiz_attempts qa ON qa.item_id = gi.item_id AND qa.student_id = ?
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.is_quiz = 1
              AND gi.due_date IS NOT NULL AND (qa.attempt_id IS NULL OR qa.submitted_at IS NULL)
        ");
        mysqli_stmt_bind_param($q_stmt, "iis", $student_id, $sec_id, $sy);
        mysqli_stmt_execute($q_stmt);
        $todoItems = array_merge($todoItems, mysqli_fetch_all(mysqli_stmt_get_result($q_stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($q_stmt);

        // ...plus in-class exams (nothing to submit online) that haven't
        // happened yet — there's no "done" state for these, so past ones drop off.
        $e_stmt = mysqli_prepare($conn, "
            SELECT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name, 'Exam' AS via, 'Exam' AS kind
            FROM gradebook_items gi
            JOIN subjects sub ON sub.subject_id = gi.subject_id
            WHERE gi.section_id = ? AND gi.school_year = ? AND gi.category = 'quarterly_assessment'
              AND gi.is_quiz = 0 AND gi.accepts_submission = 0
              AND gi.due_date IS NOT NULL AND gi.due_date >= CURDATE()
        ");
        mysqli_stmt_bind_param($e_stmt, "is", $sec_id, $sy);
        mysqli_stmt_execute($e_stmt);
        $todoItems = array_merge($todoItems, mysqli_fetch_all(mysqli_stmt_get_result($e_stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($e_stmt);

        usort($todoItems, function ($a, $b) { return strtotime($a['due_date']) <=> strtotime($b['due_date']); });

        $weekAhead = strtotime('+7 days');
        foreach ($todoItems as $t) {
            if (strtotime($t['due_date']) <= $weekAhead) $dueThisWeekCount++;
        }

        // Recent lessons feed (last 14 days) + a tighter 7-day count for the stat chip.
        $l_stmt = mysqli_prepare($conn, "
            SELECT l.lesson_id, l.title, l.posted_at, sub.subject_id, sub.subject_name
            FROM lessons l
            JOIN subjects sub ON sub.subject_id = l.subject_id
            WHERE l.section_id = ? AND l.school_year = ? AND l.posted_at >= (NOW() - INTERVAL 14 DAY)
            ORDER BY l.posted_at DESC
            LIMIT 4
        ");
        mysqli_stmt_bind_param($l_stmt, "is", $sec_id, $sy);
        mysqli_stmt_execute($l_stmt);
        $recentLessons = mysqli_fetch_all(mysqli_stmt_get_result($l_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($l_stmt);

        $nl_stmt = mysqli_prepare($conn, "
            SELECT COUNT(*) AS c FROM lessons
            WHERE section_id = ? AND school_year = ? AND posted_at >= (NOW() - INTERVAL 7 DAY)
        ");
        mysqli_stmt_bind_param($nl_stmt, "is", $sec_id, $sy);
        mysqli_stmt_execute($nl_stmt);
        $newLessonsCount = (int) (mysqli_stmt_get_result($nl_stmt)->fetch_assoc()['c'] ?? 0);
        mysqli_stmt_close($nl_stmt);
    }

    // Soonest task per subject (todoItems is already sorted by due date);
    // the course holding the very next task is the highlighted "Continue" row.
    $nextBySubject = [];
    foreach ($todoItems as $t) {
        $nextBySubject[(int) $t['subject_id']] ??= $t;
    }
    $nextSubjectId = $todoItems ? (int) $todoItems[0]['subject_id'] : 0;

    $subjQs = fn(int $sid) => http_build_query(['subject_id' => $sid, 'sy' => $sy]);
    $taskUrl = function (array $t) use ($subjQs): string {
        switch ($t['via']) {
            case 'Quiz':       return 'take_quiz?item_id=' . (int) $t['item_id'];
            case 'Assignment': return 'student_assignments?' . $subjQs((int) $t['subject_id']) . '#as-item-' . (int) $t['item_id'];
            default:           return 'student_stream?' . $subjQs((int) $t['subject_id']);
        }
    };

    // Everything the week calendar needs; rendered client-side so the
    // student can page through weeks without a reload.
    $calEvents = array_map(fn($t) => [
        'id'      => (int) $t['item_id'],
        'title'   => $t['title'],
        'subject' => $t['subject_name'],
        'kind'    => strtolower($t['kind']),
        'due'     => date('Y-m-d\TH:i', strtotime($t['due_date'])),
        'url'     => $taskUrl($t),
    ], $todoItems);

    // Latest school-wide announcement (posted by the admin) for the banner.
    $ann = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT title, body, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1
    ")) ?: null;

    $tileTones   = ['', 't1', 't2', 't3'];

    $ico_cap   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg>';
    $ico_clock = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    $ico_cal   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path stroke-linecap="round" d="M3.5 10h17M8 3v4M16 3v4"/></svg>';
    $ico_check = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>';
    $ico_spark ='<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>';
  ?>
  <div class="student-main">
    <div class="student-content">
      <div class="hm">

        <header class="hm-head">
          <div>
            <h1>Welcome back<?= $home_given_name ? ', ' . htmlspecialchars($home_given_name) : '' ?></h1>
            <p>Here's what's happening across your classes.</p>
          </div>
          <div class="hm-stats">
            <div class="hm-stat"><span class="hm-tile"><?= $ico_cap ?></span><div><b><?= count($courses) ?></b><span><?= count($courses) === 1 ? 'Course' : 'Courses' ?></span></div></div>
            <div class="hm-stat"><span class="hm-tile"><?= $ico_clock ?></span><div><b><?= $dueThisWeekCount ?></b><span>Due This Week</span></div></div>
            <div class="hm-stat"><span class="hm-tile"><?= $ico_spark ?></span><div><b><?= $newLessonsCount ?></b><span><?= $newLessonsCount === 1 ? 'New Lesson' : 'New Lessons' ?></span></div></div>
          </div>
        </header>

        <?php if ($ann):
          $isNew   = strtotime($ann['created_at']) >= strtotime('-7 days');
          $body    = trim((string) $ann['body']);
          $firstLn = trim(strtok($body, "\n") ?: '');
          $hasMore = $body !== '' && ($firstLn !== $body || mb_strlen($firstLn) > 110);
        ?>
          <div class="hm-banner">
            <span class="hm-banner-tag"><?= $isNew ? 'NEW' : 'NOTICE' ?></span>
            <div class="hm-banner-main">
              <b><?= htmlspecialchars($ann['title']) ?></b>
              <?php if ($firstLn !== '' && !$hasMore): ?> — <?= htmlspecialchars($firstLn) ?><?php endif; ?>
              <span class="meta"> · <?= date('M j', strtotime($ann['created_at'])) ?></span>
              <?php if ($hasMore): ?><div class="hm-banner-full" id="hmAnnFull" hidden><?= htmlspecialchars($body) ?></div><?php endif; ?>
            </div>
            <?php if ($hasMore): ?>
              <button type="button" class="hm-btn hm-btn-light" id="hmAnnToggle" aria-expanded="false" aria-controls="hmAnnFull">Read more &rarr;</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="hm-grid">
          <div class="hm-col">
            <section class="hm-panel hm-cal" aria-labelledby="hm-week" id="hmCal">
              <div class="hm-cal-head">
                <h2 class="hm-panel-title" id="hm-week"><span class="hm-tile lg"><?= $ico_cal ?></span>This week</h2>
                <div class="hm-cal-nav">
                  <span class="hm-cal-range" id="hmCalRange" aria-live="polite"></span>
                  <button type="button" class="hm-icon-btn" id="hmCalPrev" aria-label="Previous week">&#8249;</button>
                  <button type="button" class="hm-pill-btn" id="hmCalToday">Today</button>
                  <button type="button" class="hm-icon-btn" id="hmCalNext" aria-label="Next week">&#8250;</button>
                </div>
              </div>
              <div class="hm-cal-days" id="hmCalDays" role="group" aria-label="Days of the week"></div>
              <div class="hm-cal-dayhead">
                <b id="hmCalDayLabel"></b>
                <span class="hm-legend" aria-hidden="true"><i class="k-exam"></i>Exam <i class="k-quiz"></i>Quiz <i class="k-assignment"></i>Assignment</span>
              </div>
              <ul class="hm-cal-list" id="hmCalList"></ul>
              <noscript><p class="hm-empty">Turn on JavaScript to see your week.</p></noscript>
            </section>

            <section class="hm-panel" aria-labelledby="hm-courses">
              <h2 class="hm-panel-title" id="hm-courses">My Courses</h2>
              <?php if (empty($courses)): ?>
                <p class="hm-empty">No courses found yet.</p>
              <?php else: ?>
                <ul class="hm-courses">
                  <?php foreach ($courses as $c):
                    $sid  = (int) $c['subject_id'];
                    $next = $nextBySubject[$sid] ?? null;
                    $due  = $next ? lms_due_short($next['due_date']) : '';
                  ?>
                    <li class="hm-course<?= $sid === $nextSubjectId ? ' is-next' : '' ?>">
                      <span class="hm-tile <?= $tileTones[$sid % 4] ?>" aria-hidden="true"><?= subject_banner_icon($c['subject_name']) ?></span>
                      <span class="hm-course-main">
                        <a class="hm-course-name" href="student_stream?<?= $subjQs($sid) ?>"><?= htmlspecialchars($c['subject_name']) ?></a>
                        <span class="hm-course-sub"><?= htmlspecialchars($c['teacher_name'] ?? 'Teacher not yet assigned') ?></span>
                      </span>
                      <?php if ($next): ?>
                        <span class="hm-due-pill <?= lms_kind_class($next['kind']) ?><?= $due === 'Overdue' ? ' is-overdue' : '' ?>" title="<?= htmlspecialchars($next['title']) ?>"><?= htmlspecialchars($next['kind']) ?> · <?= $due ?></span>
                        <a class="hm-open-btn" href="<?= htmlspecialchars($taskUrl($next)) ?>">Open<span class="hm-sr"> <?= htmlspecialchars($next['kind'] . ': ' . $next['title']) ?></span></a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          </div>

          <aside>
            <section class="hm-panel" aria-labelledby="hm-todo" id="hmTodo">
              <div class="hm-todo-head">
                <h2 class="hm-panel-title" id="hm-todo"><span class="hm-tile lg"><?= $ico_check ?></span>To-Do</h2>
                <?php if ($todoItems): ?><span class="hm-todo-count" id="hmTodoCount" aria-live="polite">0 of <?= count($todoItems) ?> done</span><?php endif; ?>
              </div>
              <?php if (empty($todoItems)): ?>
                <p class="hm-empty">No tasks due — you're all caught up 🎉</p>
              <?php else: ?>
                <div class="hm-progress" aria-hidden="true"><span id="hmTodoBar"></span></div>
                <div class="hm-chips" role="group" aria-label="Filter tasks">
                  <button type="button" class="hm-chip is-on" data-filter="all" aria-pressed="true">All</button>
                  <button type="button" class="hm-chip" data-filter="tests" aria-pressed="false">Tests</button>
                  <button type="button" class="hm-chip" data-filter="assignments" aria-pressed="false">Assignments</button>
                </div>
                <ul class="hm-todo" id="hmTodoList">
                  <?php foreach ($todoItems as $t): $due = lms_due_short($t['due_date']); ?>
                    <li class="hm-todo-item" data-id="<?= (int) $t['item_id'] ?>" data-group="<?= $t['kind'] === 'Assignment' ? 'assignments' : 'tests' ?>">
                      <input type="checkbox" class="hm-check" id="hm-t-<?= (int) $t['item_id'] ?>" aria-label="Mark <?= htmlspecialchars($t['title']) ?> as done">
                      <div class="hm-todo-main">
                        <a class="hm-todo-title" href="<?= htmlspecialchars($taskUrl($t)) ?>"><?= htmlspecialchars($t['title']) ?></a>
                        <span class="hm-todo-meta"><span class="hm-kind <?= lms_kind_class($t['kind']) ?>"><?= htmlspecialchars($t['kind']) ?></span><span class="hm-todo-subj"><?= htmlspecialchars($t['subject_name']) ?></span></span>
                      </div>
                      <span class="hm-todo-due<?= $due === 'Today' ? ' is-today' : ($due === 'Overdue' ? ' is-overdue' : '') ?>" title="<?= date('D, M j · g:i A', strtotime($t['due_date'])) ?>"><?= $due ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <p class="hm-empty hm-todo-none" id="hmTodoNone" hidden>Nothing in this filter.</p>
              <?php endif; ?>
            </section>

            <section class="hm-panel" aria-labelledby="hm-lessons">
              <h2 class="hm-panel-title" id="hm-lessons"><span class="hm-tile lg"><?= $ico_cap ?></span>Recent Lessons</h2>
              <?php if (empty($recentLessons)): ?>
                <p class="hm-empty">No lessons posted in the last two weeks.</p>
              <?php else: ?>
                <ul class="hm-lessons">
                  <?php foreach ($recentLessons as $l): ?>
                    <li class="hm-lesson">
                      <div class="hm-lesson-title"><?= htmlspecialchars($l['title']) ?></div>
                      <div class="hm-lesson-meta"><?= htmlspecialchars($l['subject_name']) ?> · <?= date('M j, g:i A', strtotime($l['posted_at'])) ?></div>
                      <a class="hm-btn hm-btn-dark" href="student_lessons?<?= $subjQs((int) $l['subject_id']) ?>#lesson-<?= (int) $l['lesson_id'] ?>">Resume<span class="hm-sr"> <?= htmlspecialchars($l['title']) ?></span></a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          </aside>
        </div>

      </div>
    </div>
  </div>
  <script>
    // Banner: expand / collapse the full announcement.
    (function () {
      var btn = document.getElementById('hmAnnToggle'), full = document.getElementById('hmAnnFull');
      if (!btn || !full) return;
      btn.addEventListener('click', function () {
        var open = full.hidden;
        full.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.innerHTML = open ? 'Show less' : 'Read more &rarr;';
        btn.closest('.hm-banner').classList.toggle('is-open', open);
      });
    })();

    // Week calendar: Sun–Sat strip with a dot per kind due that day; click
    // a day to list what's due. Paging is client-side over the same
    // pending-task list the To-Do panel uses.
    (function () {
      var root = document.getElementById('hmCal');
      if (!root) return;
      var EVENTS = <?= json_encode($calEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      var TODAY  = parseDay(<?= json_encode(date('Y-m-d')) ?>);
      var KINDS  = ['exam', 'quiz', 'assignment'];
      var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      var DOW = ['Su','Mo','Tu','We','Th','Fr','Sa'];
      var DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

      function parseDay(s) { var p = s.split('-'); return new Date(+p[0], p[1] - 1, +p[2]); }
      function key(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
      function addDays(d, n) { var r = new Date(d); r.setDate(r.getDate() + n); return r; }
      function esc(s) { var el = document.createElement('div'); el.textContent = s; return el.innerHTML; }
      function fmtTime(hm) {
        var h = +hm.slice(0, 2), m = hm.slice(3, 5);
        return ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
      }

      var byDay = {};
      EVENTS.forEach(function (e) { (byDay[e.due.slice(0, 10)] = byDay[e.due.slice(0, 10)] || []).push(e); });
      Object.keys(byDay).forEach(function (k) { byDay[k].sort(function (a, b) { return a.due < b.due ? -1 : 1; }); });

      var daysEl = document.getElementById('hmCalDays'), listEl = document.getElementById('hmCalList');
      var rangeEl = document.getElementById('hmCalRange'), labelEl = document.getElementById('hmCalDayLabel');
      var selected = TODAY;

      function render(focus) {
        var start = addDays(selected, -selected.getDay()), end = addDays(start, 6);
        rangeEl.textContent = start.getMonth() === end.getMonth()
          ? MON[start.getMonth()] + ' ' + start.getFullYear()
          : MON[start.getMonth()] + (start.getFullYear() !== end.getFullYear() ? ' ' + start.getFullYear() : '') + ' – ' + MON[end.getMonth()] + ' ' + end.getFullYear();

        var html = '';
        for (var i = 0; i < 7; i++) {
          var d = addDays(start, i), k = key(d), evs = byDay[k] || [];
          var isSel = k === key(selected), isToday = k === key(TODAY);
          var dots = KINDS.filter(function (kind) { return evs.some(function (e) { return e.kind === kind; }); })
                          .map(function (kind) { return '<i class="k-' + kind + '"></i>'; }).join('');
          html += '<button type="button" class="hm-day' + (isSel ? ' is-sel' : '') + (isToday ? ' is-today' : '') + '" data-day="' + k + '"'
                + ' aria-pressed="' + isSel + '" tabindex="' + (isSel ? 0 : -1) + '"'
                + ' aria-label="' + DAYS[i] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + (evs.length ? ', ' + evs.length + ' due' : '') + '">'
                + '<span class="hm-dow">' + DOW[i] + '</span><span class="hm-dnum">' + d.getDate() + '</span>'
                + '<span class="hm-dots">' + dots + '</span></button>';
        }
        daysEl.innerHTML = html;
        if (focus) daysEl.querySelector('.is-sel').focus();

        var sk = key(selected), evs = byDay[sk] || [];
        labelEl.textContent = DAYS[selected.getDay()] + ', ' + MONTHS[selected.getMonth()] + ' ' + selected.getDate()
                            + (sk === key(TODAY) ? ' · Today' : '');
        listEl.innerHTML = evs.length ? evs.map(function (e) {
          return '<li class="hm-cal-item k-' + e.kind + '">'
               + '<div class="hm-cal-item-main"><b>' + esc(e.title) + '</b><span>' + esc(e.subject) + '</span></div>'
               + '<span class="hm-kind k-' + e.kind + '">' + e.kind + '</span>'
               + '<span class="hm-cal-time">' + fmtTime(e.due.slice(11)) + '</span>'
               + '<a class="hm-open-btn is-shown" href="' + esc(e.url) + '">Open<span class="hm-sr"> ' + esc(e.title) + '</span></a></li>';
        }).join('') : '<li class="hm-cal-empty">Nothing due this day.</li>';
      }

      daysEl.addEventListener('click', function (e) {
        var b = e.target.closest('.hm-day'); if (!b) return;
        selected = parseDay(b.dataset.day); render();
      });
      daysEl.addEventListener('keydown', function (e) {
        var step = { ArrowLeft: -1, ArrowRight: 1 }[e.key]; if (!step) return;
        e.preventDefault(); selected = addDays(selected, step); render(true);
      });
      document.getElementById('hmCalPrev').addEventListener('click', function () { selected = addDays(selected, -7); render(); });
      document.getElementById('hmCalNext').addEventListener('click', function () { selected = addDays(selected, 7); render(); });
      document.getElementById('hmCalToday').addEventListener('click', function () { selected = TODAY; render(); });
      render();
    })();

    // To-Do: filter chips + personal "done" ticks. Ticking is a private
    // checklist kept in this browser only — it doesn't submit anything.
    (function () {
      var list = document.getElementById('hmTodoList');
      if (!list) return;
      var STORE = 'lmsHomeDone:<?= $student_id ?>';
      var items = Array.prototype.slice.call(list.querySelectorAll('.hm-todo-item'));
      var done = {};
      try { (JSON.parse(localStorage.getItem(STORE)) || []).forEach(function (id) { done[id] = 1; }); } catch (e) {}

      function save() {
        // Keep only ids still on the list so finished-for-real items don't pile up.
        var ids = items.map(function (li) { return li.dataset.id; }).filter(function (id) { return done[id]; });
        try { localStorage.setItem(STORE, JSON.stringify(ids)); } catch (e) {}
      }
      function sync() {
        var n = 0;
        items.forEach(function (li) {
          var on = !!done[li.dataset.id];
          li.classList.toggle('is-done', on);
          li.querySelector('.hm-check').checked = on;
          if (on) n++;
        });
        document.getElementById('hmTodoCount').textContent = n + ' of ' + items.length + ' done';
        document.getElementById('hmTodoBar').style.transform = 'scaleX(' + (items.length ? n / items.length : 0) + ')';
      }

      list.addEventListener('change', function (e) {
        if (!e.target.classList.contains('hm-check')) return;
        var id = e.target.closest('.hm-todo-item').dataset.id;
        if (e.target.checked) done[id] = 1; else delete done[id];
        save(); sync();
      });

      var chips = document.querySelectorAll('#hmTodo .hm-chip'), none = document.getElementById('hmTodoNone');
      Array.prototype.forEach.call(chips, function (chip) {
        chip.addEventListener('click', function () {
          var f = chip.dataset.filter, shown = 0;
          Array.prototype.forEach.call(chips, function (c) { c.classList.toggle('is-on', c === chip); c.setAttribute('aria-pressed', c === chip); });
          items.forEach(function (li) { var show = f === 'all' || li.dataset.group === f; li.hidden = !show; if (show) shown++; });
          none.hidden = shown > 0;
        });
      });

      save(); sync();
    })();
  </script>
<?php endif; ?>
</body>
</html>
