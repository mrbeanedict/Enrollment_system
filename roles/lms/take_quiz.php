<?php
// Quiz-taking belongs to the Student LMS (see lms_sidebar.php) —
// always use that session, fixed, rather than guessing between
// STUDENT_SESSID and STUDENT_LMS_SESSID. A student can be validly
// signed into both at once; this page must never flip to Student
// Portal branding just because an unrelated Student Portal session
// also happens to be alive.
//
// All questions scroll on one page (no pagination). Every answer is saved
// the moment it's picked (ajax/quiz_autosave.php), and the time limit is
// enforced on the server — see shared/helpers/quiz.php.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/quiz.php';

$is_student = require_login('student', null, 'ignore', 'bool');
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;
$item_id    = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);

function tq_graded_notice(mysqli $conn, array $item): void {
    notify_student_users($conn, [(int) ($_SESSION['user_student_id'] ?? 0)],
        'Your quiz "' . $item['title'] . '" has been graded.',
        'roles/lms/take_quiz?item_id=' . (int) $item['item_id']);
}

// ── Submit (by the student, or automatically when time runs out) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz']) && $is_student) {
    $item    = quiz_item_for_student($conn, $item_id, $student_id);
    $attempt = $item ? quiz_attempt($conn, $item, $student_id) : null;

    if (!$item) {
        $_SESSION['tq_flash'] = 'That quiz was not found among your subjects.';
        $_SESSION['tq_flash_type'] = 'error';
    } elseif ($attempt && $attempt['submitted_at'] === null) {
        $answers = quiz_saved_answers($conn, (int) $attempt['attempt_id']);
        // Answers posted with the form count only while time remains (plus a
        // little slack for an auto-submit fired at 0:00); after that, only
        // what was already saved is graded.
        if (!quiz_time_up($attempt, QUIZ_GRACE_SECONDS)) {
            $answers = quiz_merge_posted_answers(quiz_questions($conn, $item_id), $answers, (array) ($_POST['answer'] ?? []));
        } else {
            $_SESSION['tq_flash'] = 'Time ran out — the answers saved before then were submitted.';
            $_SESSION['tq_flash_type'] = 'info';
        }
        quiz_finalize($conn, $item, (int) $attempt['attempt_id'], $student_id, $answers);
        tq_graded_notice($conn, $item);
    }
    header('Location: take_quiz?item_id=' . $item_id);
    exit();
}

$flash      = $_SESSION['tq_flash']      ?? '';
$flash_type = $_SESSION['tq_flash_type'] ?? 'info';
unset($_SESSION['tq_flash'], $_SESSION['tq_flash_type']);

$item = $attempt = null;
$questions = $saved = [];
if ($is_student) {
    $item = quiz_item_for_student($conn, $item_id, $student_id);
    if ($item) {
        $attempt = quiz_attempt($conn, $item, $student_id, true);
        // Came back after the time ran out (closed the tab, lost connection):
        // grade what was saved instead of leaving the attempt hanging open.
        if ($attempt['submitted_at'] === null && quiz_time_up($attempt, QUIZ_GRACE_SECONDS)) {
            quiz_finalize($conn, $item, (int) $attempt['attempt_id'], $student_id, quiz_saved_answers($conn, (int) $attempt['attempt_id']));
            tq_graded_notice($conn, $item);
            $flash = 'Time ran out — the answers saved before then were submitted.';
            $flash_type = 'info';
            $attempt = quiz_attempt($conn, $item, $student_id);
        }
        // Came back after the page went silent (browser closed without a
        // "leaving" message getting through): that absence is a strike.
        // last_seen is NULL when the leave was already counted (or first visit).
        if ($attempt['submitted_at'] === null && $attempt['idle_seconds'] !== null && (int) $attempt['idle_seconds'] > QUIZ_HEARTBEAT_GAP) {
            quiz_record_strike($conn, $item, (int) $attempt['attempt_id'], $student_id);
            $attempt = quiz_attempt($conn, $item, $student_id);
        }
        if ($attempt['submitted_at'] === null) {
            quiz_touch($conn, (int) $attempt['attempt_id']);
            $questions = quiz_questions($conn, $item_id);
            $saved     = quiz_saved_answers($conn, (int) $attempt['attempt_id']);
        }
    }
}
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $item ? htmlspecialchars($item['title']) . ' — Quiz' : 'Quiz' ?> — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_lms.css') ?>">
    <style>
      .tq { --tq-line:var(--st-line); --tq-card:#fff; --tq-ink:var(--st-dark); --tq-muted:var(--st-sub); --tq-green:var(--st-dark); --tq-green-2:var(--st-primary);
            --tq-pick:#E2ECE6; --tq-warm:#FBF0DA; --tq-warm-line:#EBD7A6; --tq-warm-ink:#9A7026;
            --tq-mono: ui-monospace, 'JetBrains Mono', 'Cascadia Mono', 'SF Mono', Consolas, monospace;
            max-width:860px; margin-inline:auto; color:var(--tq-ink); }
      .tq-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }

      .tq-crumb { font-size:12px; color:var(--tq-muted); margin-bottom:6px; }
      .tq-crumb a { color:inherit; text-decoration:none; }
      .tq-crumb a:hover { color:var(--tq-ink); text-decoration:underline; text-underline-offset:2px; }
      .tq h1 { margin:0; font-family:'Fraunces', Georgia, serif; font-size:24px; font-weight:600; line-height:1.25; }
      .tq-sub { margin:4px 0 18px; font-size:13px; color:var(--tq-muted); }

      .tq-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; background:var(--tq-card); border:1px solid var(--tq-line); border-radius:12px; padding:14px 16px; margin-bottom:18px; }
      .tq-stat b { display:block; font-family:var(--tq-mono); font-size:17px; font-weight:700; line-height:1.2; }
      .tq-stat span { font-size:11.5px; color:var(--tq-muted); }

      /* Sticky while the questions scroll underneath. */
      .tq-bar { position:sticky; top:0; z-index:5; background:var(--tq-warm); border:1px solid var(--tq-warm-line); border-radius:12px; padding:10px 16px; margin-bottom:14px;
                box-shadow:0 6px 12px -10px rgba(40,30,0,.35); }
      .tq-bar-row { display:flex; justify-content:space-between; align-items:center; gap:12px; }
      .tq-bar-label { font-size:13px; font-weight:600; color:var(--tq-warm-ink); }
      .tq-clock { font-family:var(--tq-mono); font-size:15px; font-weight:700; color:var(--tq-warm-ink); font-variant-numeric:tabular-nums; }
      .tq-bar.is-low { background:#FCE8E6; border-color:#EFB9B3; }
      .tq-bar.is-low .tq-bar-label, .tq-bar.is-low .tq-clock { color:#A5221B; }
      .tq-progress { display:flex; justify-content:space-between; gap:12px; margin-top:8px; font-size:11.5px; color:var(--tq-muted); }
      .tq-meter { height:3px; background:rgba(0,0,0,.08); border-radius:2px; margin-top:6px; overflow:hidden; }
      .tq-meter i { display:block; height:100%; width:0; background:var(--tq-green-2); transition:width .2s ease; }
      .tq-save.is-error { color:#A5221B; font-weight:600; }

      .tq-q { background:var(--tq-card); border:1px solid var(--tq-line); border-radius:12px; padding:16px 18px 18px; margin:0 0 12px; scroll-margin-top:90px; }
      .tq-q-head { display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:8px; padding:0; }
      .tq-q-num { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--tq-green-2); }
      .tq-q-pts { font-size:11.5px; color:var(--tq-muted); }
      .tq-q-text { margin:0 0 12px; font-size:15px; font-weight:600; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
      .tq-opts { display:flex; flex-direction:column; gap:8px; }
      .tq-opt { display:flex; align-items:center; gap:12px; padding:10px 12px; background:#fff; border:1px solid var(--tq-line); border-radius:10px; cursor:pointer;
                font-size:14px; transition:border-color .12s, background-color .12s; }
      .tq-opt:hover { border-color:#AEB8B1; }
      .tq-opt input { appearance:none; -webkit-appearance:none; flex:none; width:16px; height:16px; margin:0; border:1.5px solid #8C9A92; border-radius:50%; display:grid; place-items:center; cursor:pointer; }
      .tq-opt input::after { content:""; width:8px; height:8px; border-radius:50%; background:var(--tq-green-2); transform:scale(0); transition:transform .12s; }
      .tq-opt input:checked { border-color:var(--tq-green-2); }
      .tq-opt input:checked::after { transform:scale(1); }
      .tq-opt input:focus-visible { outline:2px solid var(--tq-green-2); outline-offset:2px; }
      .tq-opt:has(input:checked) { background:var(--tq-pick); border-color:var(--tq-green-2); }
      .tq-opt-text { flex:1; min-width:0; word-break:break-word; }
      .tq-opt-key { flex:none; font-size:11.5px; color:#A3ADA7; }
      .tq-rules { margin:0 0 14px; padding:10px 12px; background:var(--tq-warm); border:1px solid var(--tq-warm-line); border-radius:10px; color:var(--tq-warm-ink); font-size:13px; line-height:1.5; }
      .tq-strikes { display:block; margin-top:4px; font-weight:600; }
      .tq-strikes.is-hot { color:#B3261E; }
      .tq-typed { display:flex; flex-direction:column; gap:8px; }
      .tq-typed-hint { margin:0; font-size:12.5px; color:var(--tq-muted); }
      .tq-text { width:100%; padding:10px 12px; background:#fff; border:1px solid var(--tq-line); border-radius:10px; font:inherit; color:var(--tq-ink); }
      .tq-text:hover { border-color:#AEB8B1; }
      .tq-text:focus-visible { outline:2px solid var(--tq-green-2); outline-offset:2px; border-color:var(--tq-green-2); }

      .tq-foot { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:14px 0 24px; }
      .tq-foot-note { font-size:12.5px; color:var(--tq-muted); }
      .tq-foot-actions { display:flex; gap:10px; margin-left:auto; }
      .tq-btn { display:inline-flex; align-items:center; justify-content:center; height:40px; padding:0 20px; border-radius:999px; font-size:13.5px; font-weight:600; font-family:inherit; text-decoration:none; cursor:pointer; }
      .tq-btn-ghost { background:#fff; border:1px solid var(--tq-line); color:var(--tq-ink); }
      .tq-btn-ghost:hover { border-color:#AEB8B1; }
      .tq-btn-primary { background:var(--tq-green); border:1px solid var(--tq-green); color:#fff; }
      .tq-btn-primary:hover { background:var(--st-primary); border-color:var(--st-primary); }
      .tq-btn:disabled { opacity:.55; cursor:progress; }

      .tq-result { background:var(--tq-card); border:1px solid var(--tq-line); border-radius:12px; padding:28px 20px; text-align:center; }
      .tq-result-label { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--tq-green-2); }
      .tq-result-score { margin:6px 0 4px; font-family:var(--tq-mono); font-size:34px; font-weight:700; }
      .tq-result-meta { font-size:13px; color:var(--tq-muted); margin-bottom:18px; }
      .tq-empty { background:var(--tq-card); border:1px dashed var(--tq-line); border-radius:12px; padding:28px 20px; text-align:center; color:var(--tq-muted); font-size:14px; }

      @media (max-width: 560px) {
        .tq h1 { font-size:19px; }
        .tq-stat b { font-size:15px; }
        .tq-q { padding:14px; }
        .tq-foot-actions { width:100%; }
        .tq-foot-actions .tq-btn { flex:1; }
      }
      @media (prefers-reduced-motion: reduce) { .tq * { transition:none !important; } }
    </style>
</head>
<body class="student-layout lms-layout lms-warm-bg">
<?php if (!$is_student): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/lms_navbar.php'; ?>
  <div class="student-main">
    <div class="student-content">
      <div class="tq">
        <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast style="margin-bottom:12px;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

        <?php if (!$item): ?>
          <div class="tq-empty">That quiz was not found among your subjects. <a href="student_quizzes">Back to Quizzes</a></div>
        <?php else:
          $qs_subj  = http_build_query(['subject_id' => $item['subject_id'], 'sy' => $item['school_year']]);
          $back_url = 'student_quizzes?' . $qs_subj;
        ?>
          <nav class="tq-crumb" aria-label="Breadcrumb">
            <a href="my_courses?<?= http_build_query(['sy' => $item['school_year']]) ?>">Academics</a> /
            <a href="student_stream?<?= $qs_subj ?>"><?= htmlspecialchars($item['subject_name']) ?></a> /
            <a href="<?= $back_url ?>">Quizzes</a>
          </nav>
          <h1><?= htmlspecialchars($item['title']) ?> — <?= htmlspecialchars($item['subject_name']) ?></h1>

          <?php if ($attempt['submitted_at'] !== null): $res = quiz_result($conn, $item, $attempt, $student_id); ?>
            <p class="tq-sub">Submitted <?= date('M j, Y · g:i A', strtotime($attempt['submitted_at'])) ?></p>
            <?php if (!empty($attempt['auto_submitted'])): ?>
              <div class="notice notice-error" style="margin-bottom:12px;">This quiz was submitted automatically because you left it <?= QUIZ_MAX_STRIKES ?> times (switching tabs or closing the browser). Only the answers saved before then were graded.</div>
            <?php endif; ?>
            <div class="tq-result">
              <div class="tq-result-label">Your score</div>
              <div class="tq-result-score"><?= $res['score'] !== null ? $num($res['score']) : '—' ?> / <?= $num($res['max']) ?></div>
              <div class="tq-result-meta"><?= $res['correct'] ?> of <?= $res['questions'] ?> <?= $res['questions'] === 1 ? 'question' : 'questions' ?> correct</div>
              <a class="tq-btn tq-btn-ghost" href="<?= $back_url ?>">Back to Quizzes</a>
            </div>

          <?php elseif (!$questions): ?>
            <p class="tq-sub">&nbsp;</p>
            <div class="tq-empty">This quiz has no questions yet — check back later.</div>

          <?php else:
            $nq      = count($questions);
            $nsaved  = count(array_intersect_key($saved, array_flip(array_map('intval', array_column($questions, 'question_id')))));
            $timed   = $attempt['seconds_left'] !== null;
            $letters = range('A', 'Z');
          ?>
            <p class="tq-sub">Quiz in progress · autosaves every answer<?= $timed ? ' · the timer keeps running if you leave' : '' ?></p>
            <div class="tq-rules" role="note">
              <b>Stay on this page.</b> Switching tabs or windows, or closing or reloading the browser, counts as leaving the quiz.
              You get 2 warnings; the 3rd time your quiz is submitted automatically.
              <span class="tq-strikes" id="tqStrikes" data-start="<?= (int) $attempt['violations'] ?>">Leaves so far: <b id="tqStrikeN"><?= (int) $attempt['violations'] ?></b> of <?= QUIZ_MAX_STRIKES ?></span>
            </div>

            <div class="tq-stats">
              <div class="tq-stat"><b><?= $nq ?></b><span>Questions</span></div>
              <div class="tq-stat"><b id="tqAnswered"><?= $nsaved ?></b><span>Answered</span></div>
              <div class="tq-stat"><b>1 attempt</b><span>Allowed</span></div>
            </div>

            <div class="tq-bar" id="tqBar" role="region" aria-label="Quiz progress">
              <div class="tq-bar-row">
                <span class="tq-bar-label"><?= $timed ? 'Time remaining' : 'No time limit' ?></span>
                <?php if ($timed): ?>
                  <span class="tq-clock" id="tqClock" role="timer" aria-live="off" data-seconds="<?= max(0, (int) $attempt['seconds_left']) ?>">--:--</span>
                <?php endif; ?>
              </div>
              <div class="tq-meter" aria-hidden="true"><i id="tqMeter"></i></div>
              <div class="tq-progress">
                <span id="tqPct"><?= (int) round($nsaved / $nq * 100) ?>% complete</span>
                <span class="tq-save" id="tqSave" aria-live="polite"><?= $nsaved ? 'All answers saved' : 'Answers save as you pick them' ?></span>
              </div>
            </div>

            <form method="POST" id="tqForm" data-item="<?= $item_id ?>" data-total="<?= $nq ?>">
              <input type="hidden" name="item_id" value="<?= $item_id ?>">
              <input type="hidden" name="submit_quiz" value="1">
              <?php foreach ($questions as $i => $q): $qid = (int) $q['question_id']; ?>
                <fieldset class="tq-q" id="q<?= $i + 1 ?>" data-qid="<?= $qid ?>">
                  <legend class="tq-sr">Question <?= $i + 1 ?> of <?= $nq ?></legend>
                  <div class="tq-q-head" aria-hidden="true">
                    <span class="tq-q-num">Question <?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?> / <?= str_pad((string) $nq, 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="tq-q-pts"><?= $num($q['points']) ?> <?= (float) $q['points'] === 1.0 ? 'pt' : 'pts' ?></span>
                  </div>
                  <p class="tq-q-text" id="q<?= $i + 1 ?>-text"><?= htmlspecialchars($q['question_text']) ?></p>
                  <?php if (($q['question_type'] ?? 'multiple_choice') === 'multiple_choice'): ?>
                  <div class="tq-opts" role="radiogroup" aria-labelledby="q<?= $i + 1 ?>-text">
                    <?php foreach ($q['choices'] as $k => $c): $cid = (int) $c['choice_id']; ?>
                      <label class="tq-opt">
                        <input type="radio" name="answer[<?= $qid ?>]" value="<?= $cid ?>" <?= ($saved[$qid] ?? 0) === $cid ? 'checked' : '' ?>>
                        <span class="tq-opt-text"><?= htmlspecialchars($c['choice_text']) ?></span>
                        <span class="tq-opt-key" aria-hidden="true"><?= $letters[$k] ?? '' ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <?php elseif ($q['question_type'] === 'enumeration'):
                    // Only the NUMBER of required items is sent to the page — never the answers themselves.
                    $nItems = max(1, count(array_filter($q['choices'], fn($c) => (int) $c['is_correct'] === 1)));
                    $savedItems = is_string($saved[$qid] ?? null) ? (json_decode($saved[$qid], true) ?: []) : [];
                  ?>
                  <div class="tq-typed" data-type="enumeration">
                    <p class="tq-typed-hint">Enter <?= $nItems ?> answer<?= $nItems === 1 ? '' : 's' ?>, in any order.</p>
                    <?php for ($k = 0; $k < $nItems; $k++): ?>
                      <input type="text" class="tq-text" name="answer[<?= $qid ?>][]" maxlength="200" autocomplete="off"
                             aria-label="Answer <?= $k + 1 ?> of <?= $nItems ?> for question <?= $i + 1 ?>"
                             placeholder="Answer <?= $k + 1 ?>" value="<?= htmlspecialchars((string) ($savedItems[$k] ?? '')) ?>">
                    <?php endfor; ?>
                  </div>
                  <?php else: ?>
                  <div class="tq-typed" data-type="single">
                    <input type="text" class="tq-text" name="answer[<?= $qid ?>]" maxlength="500" autocomplete="off"
                           aria-labelledby="q<?= $i + 1 ?>-text" placeholder="Type your answer"
                           value="<?= htmlspecialchars(is_string($saved[$qid] ?? null) ? $saved[$qid] : '') ?>">
                  </div>
                  <?php endif; ?>
                </fieldset>
              <?php endforeach; ?>

              <div class="tq-foot">
                <span class="tq-foot-note" id="tqLeft"></span>
                <div class="tq-foot-actions">
                  <a class="tq-btn tq-btn-ghost" href="<?= $back_url ?>">Back</a>
                  <button type="submit" class="tq-btn tq-btn-primary" id="tqSubmit">Submit quiz</button>
                </div>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($item && $attempt && $attempt['submitted_at'] === null && $questions): ?>
  <script>
  (function () {
    var form    = document.getElementById('tqForm');
    var total   = parseInt(form.dataset.total, 10);
    var saveEl  = document.getElementById('tqSave');
    var submitB = document.getElementById('tqSubmit');
    var submitting = false;

    /* ── Progress ── */
    // A question counts as answered if a radio is picked or any typed box has text.
    function isAnswered(q) {
      if (q.querySelector('input[type="radio"]:checked')) return true;
      return Array.prototype.some.call(q.querySelectorAll('input.tq-text'), function (t) { return t.value.trim() !== ''; });
    }
    function answeredCount() { return Array.prototype.filter.call(form.querySelectorAll('.tq-q'), isAnswered).length; }
    function syncProgress() {
      var n = answeredCount(), left = total - n, pct = Math.round(n / total * 100);
      document.getElementById('tqAnswered').textContent = n;
      document.getElementById('tqPct').textContent = pct + '% complete';
      document.getElementById('tqMeter').style.width = pct + '%';
      document.getElementById('tqLeft').textContent = left ? left + (left === 1 ? ' question' : ' questions') + ' unanswered' : 'All questions answered';
    }

    /* ── Autosave: one request per pick; the latest pick per question wins.
          Failed saves retry, and every answer also rides along with Submit. ── */
    var pending = {}, inflight = 0, failed = false;
    function status() {
      if (failed)        { saveEl.textContent = 'Not saved — check your connection (retrying)'; saveEl.classList.add('is-error'); }
      else if (inflight) { saveEl.textContent = 'Saving…'; saveEl.classList.remove('is-error'); }
      else               { saveEl.textContent = 'All answers saved'; saveEl.classList.remove('is-error'); }
    }
    // $typed = a typed answer (string / JSON array string) instead of a radio choice id.
    function save(qid, cid, typed) {
      pending[qid] = cid; inflight++; status();
      var body = new URLSearchParams({ item_id: form.dataset.item, question_id: qid });
      body.set(typed ? 'answer_text' : 'choice_id', cid);
      fetch('<?= APP_URL ?>/ajax/quiz_autosave', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }).then(function (j) { j.status = r.status; return j; }); })
        .then(function (j) {
          inflight--;
          if (j.ok) { if (pending[qid] === cid) delete pending[qid]; failed = Object.keys(pending).length > 0 && failed; }
          else if (j.error === 'time_up' || j.error === 'submitted') { finish(); return; }
          else { failed = true; setTimeout(function () { if (pending[qid] === cid) save(qid, cid, typed); }, 3000); }
          status();
        })
        .catch(function () {
          inflight--; failed = true; status();
          setTimeout(function () { if (pending[qid] === cid) save(qid, cid, typed); }, 3000);
        });
    }
    form.addEventListener('change', function (e) {
      if (!e.target.matches('.tq-q input[type="radio"]')) return;
      syncProgress();
      save(e.target.closest('.tq-q').dataset.qid, e.target.value);
    });

    /* ── Typed answers (identification / fill in the blank / enumeration):
          saved shortly after typing stops, and immediately when leaving a box. ── */
    function typedValue(q) {
      var boxes = q.querySelectorAll('input.tq-text');
      if (q.querySelector('.tq-typed[data-type="enumeration"]')) {
        return JSON.stringify(Array.prototype.map.call(boxes, function (b) { return b.value; }));
      }
      return boxes[0].value;
    }
    var typeTimers = {};
    function saveTyped(q) {
      var qid = q.dataset.qid;
      clearTimeout(typeTimers[qid]);
      delete typeTimers[qid];
      save(qid, typedValue(q), true);
    }
    form.addEventListener('input', function (e) {
      if (!e.target.matches('input.tq-text')) return;
      syncProgress();
      var q = e.target.closest('.tq-q');
      clearTimeout(typeTimers[q.dataset.qid]);
      typeTimers[q.dataset.qid] = setTimeout(function () { saveTyped(q); }, 600);
    });
    form.addEventListener('focusout', function (e) {
      if (!e.target.matches('input.tq-text')) return;
      var q = e.target.closest('.tq-q');
      if (typeTimers[q.dataset.qid]) saveTyped(q);
    });

    /* ── Submit: one attempt only, so always confirm (and say what's unanswered). ── */
    function finish() { if (submitting) return; submitting = true; submitB.disabled = true; submitB.textContent = 'Submitting…'; form.submit(); }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (submitting) return;
      var left = total - answeredCount();
      var msg = left
        ? 'You have ' + left + (left === 1 ? ' unanswered question' : ' unanswered questions') + '. Submit anyway? You only get one attempt.'
        : 'Submit your answers? You only get one attempt.';
      if (typeof Swal === 'undefined') { if (window.confirm(msg)) finish(); return; }
      Swal.fire({ title: 'Submit quiz?', text: msg, icon: left ? 'warning' : 'question', showCancelButton: true,
                  confirmButtonText: 'Submit', cancelButtonText: 'Keep answering', confirmButtonColor: '#1C2628' })
        .then(function (r) { if (r.isConfirmed) finish(); });
    });


    /* ── Anti-cheat: leaving the quiz is a strike. The SERVER counts them
          (2 warnings, the 3rd auto-submits); this only reports. ── */
    var MAX_STRIKES = <?= QUIZ_MAX_STRIKES ?>;
    var VIOLATION_URL = '<?= APP_URL ?>/ajax/quiz_violation';
    var strikeEl = document.getElementById('tqStrikes'), strikeN = document.getElementById('tqStrikeN');
    var seenKey = 'tq_strikes_seen_' + form.dataset.item;
    var away = false, awayTimer = null, warnOpen = false, ended = false;

    function body(reason) { return new URLSearchParams({ item_id: form.dataset.item, reason: reason }); }
    function setStrikes(n) {
      strikeN.textContent = n;
      strikeEl.classList.toggle('is-hot', n >= MAX_STRIKES - 1);
      try { sessionStorage.setItem(seenKey, String(n)); } catch (e) {}
    }
    function strikeMessage(n) {
      if (n === 1) return 'Warning 1 of 2. You left the quiz. If you leave 2 more times, it is submitted automatically.';
      if (n === 2) return 'FINAL warning (2 of 2). If you leave the quiz once more, it is submitted automatically.';
      return 'You left the quiz ' + MAX_STRIKES + ' times, so it was submitted automatically.';
    }
    function showStrike(n, submitted) {
      setStrikes(n);
      if (submitted) {
        ended = true;
        var go = function () { window.location.reload(); };
        if (typeof Swal === 'undefined') { window.alert(strikeMessage(MAX_STRIKES)); go(); return; }
        Swal.fire({ title: 'Quiz submitted', text: strikeMessage(MAX_STRIKES), icon: 'error', allowOutsideClick: false, confirmButtonColor: '#1C2628' }).then(go);
        return;
      }
      warnOpen = true;
      var done = function () { warnOpen = false; };
      if (typeof Swal === 'undefined') { window.alert(strikeMessage(n)); done(); return; }
      Swal.fire({ title: 'Warning', text: strikeMessage(n), icon: 'warning', confirmButtonColor: '#1C2628', allowOutsideClick: false }).then(done);
    }
    function reportLeave(reason) {
      if (away || warnOpen || ended || submitting) return;
      away = true; // one absence = one strike, until the student is back
      fetch(VIOLATION_URL, { method: 'POST', body: body(reason), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && (j.violations > 0 || j.submitted)) showStrike(j.violations, j.submitted); })
        .catch(function () {});
    }
    function back() { clearTimeout(awayTimer); awayTimer = null; away = false; }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { clearTimeout(awayTimer); reportLeave('hidden'); }
      else { back(); }
    });
    // Another window/app took focus without hiding this tab. Short grace so a click
    // into the browser's own address bar and straight back isn't punished.
    window.addEventListener('blur', function () {
      if (away || warnOpen || ended || submitting) return;
      clearTimeout(awayTimer);
      awayTimer = setTimeout(function () { if (!document.hasFocus()) reportLeave('blur'); }, 1500);
    });
    window.addEventListener('focus', back);
    // Closing / reloading / navigating away. sendBeacon survives the page going away.
    window.addEventListener('pagehide', function () {
      if (away || ended || submitting) return;
      if (navigator.sendBeacon) navigator.sendBeacon(VIOLATION_URL, body('leave'));
    });
    // Heartbeat: if the browser is killed before the beacon goes out, the server
    // notices the silence when the student next opens the quiz.
    setInterval(function () {
      if (ended || submitting) return;
      fetch(VIOLATION_URL, { method: 'POST', body: body('heartbeat'), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && j.submitted && !submitting) { ended = true; window.location.reload(); } })
        .catch(function () {});
    }, 5000);

    // Strikes that happened while the page was gone (closed tab, reload, long absence):
    // tell the student now, once.
    (function () {
      var n = parseInt(strikeEl.dataset.start, 10) || 0, seen = 0;
      try { seen = parseInt(sessionStorage.getItem(seenKey) || '0', 10) || 0; } catch (e) {}
      setStrikes(n);
      if (n > seen && n < MAX_STRIKES) showStrike(n, false);
    })();

    /* ── Timer: counts down the server's seconds_left (never the device clock);
          submits automatically at 0:00. ── */
    var clock = document.getElementById('tqClock');
    if (clock) {
      var bar = document.getElementById('tqBar');
      var endAt = performance.now() + parseInt(clock.dataset.seconds, 10) * 1000;
      var lastAnnounced = null;
      (function tick() {
        var s = Math.max(0, Math.ceil((endAt - performance.now()) / 1000));
        var m = Math.floor(s / 60), r = s % 60;
        clock.textContent = (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
        bar.classList.toggle('is-low', s <= 60);
        // Screen readers: announce at 5 min and 1 min left, not every second.
        if ((s === 300 || s === 60) && lastAnnounced !== s) { lastAnnounced = s; saveEl.textContent = (s / 60) + (s === 60 ? ' minute' : ' minutes') + ' left'; }
        if (s === 0) { clock.textContent = '00:00'; finish(); return; }
        setTimeout(tick, 250);
      })();
    }

    /* ── The LMS nav is itself sticky: pin the timer bar just under it. ── */
    var nav = document.getElementById('lmsFolderNav'), tqBar = document.getElementById('tqBar');
    function pinBar() {
      var h = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
      tqBar.style.top = h + 'px';
      document.querySelectorAll('.tq-q').forEach(function (q) { q.style.scrollMarginTop = (h + tqBar.offsetHeight + 12) + 'px'; });
    }
    window.addEventListener('resize', pinBar);
    pinBar();

    syncProgress();
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
