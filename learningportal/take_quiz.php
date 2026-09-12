<?php
// Quiz-taking belongs to the Student LMS (see lms_sidebar.php) —
// always use that session, fixed, rather than guessing between
// STUDENT_SESSID and STUDENT_LMS_SESSID. A student can be validly
// signed into both at once; this page must never flip to Student
// Portal branding just because an unrelated Student Portal session
// also happens to be alive.
session_name('STUDENT_LMS_SESSID');
session_start();
include_once '../config.php';
include_once '../notify.php';

$is_student = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') === 'student';
$student_id = $is_student ? (int) ($_SESSION['student_id'] ?? 0) : 0;
$item_id    = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);

// Confirm this quiz belongs to a subject the student is currently enrolled
// in (via their current section's offerings) — never trust the posted
// item_id blindly. Mirrors student_assignments.php's ownership check.
function quiz_item_for_student(mysqli $conn, int $item_id, int $student_id): ?array {
    $stmt = $conn->prepare("
        SELECT gi.item_id, gi.title, gi.max_score, gi.time_limit_minutes, sub.subject_name
        FROM gradebook_items gi
        JOIN subjects sub ON sub.subject_id = gi.subject_id
        JOIN enrollments e ON e.section_id = gi.section_id AND e.school_year = gi.school_year
        WHERE gi.item_id = ? AND gi.is_quiz = 1 AND e.student_id = ? AND e.status = 'enrolled'
        LIMIT 1
    ");
    $stmt->bind_param('ii', $item_id, $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$flash      = '';
$flash_type = 'info';

// ── Grade and submit the attempt ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz']) && $is_student) {
    $item = quiz_item_for_student($conn, $item_id, $student_id);

    if (!$item) {
        $_SESSION['tq_flash'] = 'That quiz was not found among your subjects.';
        $_SESSION['tq_flash_type'] = 'error';
        header("Location: take_quiz?item_id=" . $item_id);
        exit();
    }

    $att_stmt = $conn->prepare("SELECT attempt_id, submitted_at FROM quiz_attempts WHERE item_id=? AND student_id=?");
    $att_stmt->bind_param('ii', $item_id, $student_id);
    $att_stmt->execute();
    $attempt = $att_stmt->get_result()->fetch_assoc();
    $att_stmt->close();

    if (!$attempt || $attempt['submitted_at'] !== null) {
        // No attempt in progress, or already submitted — nothing to do.
        header("Location: take_quiz?item_id=" . $item_id);
        exit();
    }
    $attempt_id = (int) $attempt['attempt_id'];

    $q_stmt = $conn->prepare("SELECT question_id, points FROM quiz_questions WHERE item_id=?");
    $q_stmt->bind_param('i', $item_id);
    $q_stmt->execute();
    $questions = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $q_stmt->close();

    $correctByQ = [];
    if (!empty($questions)) {
        $qids = array_column($questions, 'question_id');
        $placeholders = implode(',', array_fill(0, count($qids), '?'));
        $c_stmt = $conn->prepare("SELECT question_id, choice_id FROM quiz_choices WHERE question_id IN ($placeholders) AND is_correct=1");
        $c_stmt->bind_param(str_repeat('i', count($qids)), ...$qids);
        $c_stmt->execute();
        foreach ($c_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $correctByQ[(int)$row['question_id']] = (int)$row['choice_id'];
        }
        $c_stmt->close();
    }

    $answered = $_POST['answer'] ?? []; // [question_id] => choice_id
    $earned = 0.0;
    $totalPoints = 0.0;
    $ansIns = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, choice_id, is_correct) VALUES (?, ?, ?, ?)");
    foreach ($questions as $q) {
        $qid = (int) $q['question_id'];
        $pts = (float) $q['points'];
        $totalPoints += $pts;
        $chosen = isset($answered[$qid]) ? (int) $answered[$qid] : null;
        $isCorrect = ($chosen !== null && isset($correctByQ[$qid]) && $chosen === $correctByQ[$qid]) ? 1 : 0;
        if ($isCorrect) $earned += $pts;
        $ansIns->bind_param('iiii', $attempt_id, $qid, $chosen, $isCorrect);
        $ansIns->execute();
    }
    $ansIns->close();

    $rawScore = $totalPoints > 0 ? ($earned / $totalPoints) * (float) $item['max_score'] : 0.0;

    $updAtt = $conn->prepare("UPDATE quiz_attempts SET submitted_at=NOW(), score=? WHERE attempt_id=?");
    $updAtt->bind_param('di', $earned, $attempt_id);
    $updAtt->execute();
    $updAtt->close();

    // Same upsert pattern save_scores uses in teacher_gradebook.php — no
    // second grading path, this flows straight into the existing rollup.
    $gradedBy = $conn->prepare("SELECT teacher_id FROM gradebook_items WHERE item_id=?");
    $gradedBy->bind_param('i', $item_id);
    $gradedBy->execute();
    $teacher_id = (int) ($gradedBy->get_result()->fetch_assoc()['teacher_id'] ?? 0);
    $gradedBy->close();

    $ups = $conn->prepare("
        INSERT INTO gradebook_scores (item_id, student_id, raw_score, graded_by, graded_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score), graded_by = VALUES(graded_by), graded_at = NOW()
    ");
    $ups->bind_param('iidi', $item_id, $student_id, $rawScore, $teacher_id);
    $ups->execute();
    $ups->close();

    notify_student_users(
        $conn,
        [(int) ($_SESSION['user_student_id'] ?? 0)],
        'Your quiz "' . $item['title'] . '" has been graded.',
        'learningportal/student_quizzes'
    );

    header("Location: take_quiz?item_id=" . $item_id);
    exit();
}

$flash      = $_SESSION['tq_flash']      ?? '';
$flash_type = $_SESSION['tq_flash_type'] ?? 'info';
unset($_SESSION['tq_flash'], $_SESSION['tq_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Quiz — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_student.css?v=<?= filemtime(__DIR__ . '/../css/css_student.css') ?>">
    <style>
      .tq-back { font-size:12px; color:#5A5A72; text-decoration:none; display:inline-block; margin-bottom:.75rem; }
      .tq-back:hover { color:var(--brand-primary); }
      .tq-timer { font-size:13px; font-weight:700; color:#C0392B; margin-bottom:1rem; }
      .tq-question { border-bottom:0.5px solid #EBEBF0; padding:14px 0; }
      .tq-question:last-child { border-bottom:none; }
      .tq-qtext { font-weight:600; font-size:14px; color:#1A1A2E; margin-bottom:8px; }
      .tq-choice { display:flex; align-items:center; gap:8px; font-size:13px; color:#333; padding:4px 0; }
      .btn-primary-gb { height:38px; padding:0 18px; background:var(--brand-primary); border:none; border-radius:8px; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:1rem; }
      .btn-primary-gb:hover { background:var(--brand-primary-hover); }
      .tq-result { font-size:20px; font-weight:700; color:#1A6B4A; background:#EBF7F2; border:0.5px solid #A8D9C5; border-radius:10px; padding:20px; text-align:center; }
    </style>
</head>
<body class="student-layout lms-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="lms_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'lms_navbar.php';
    $item = quiz_item_for_student($conn, $item_id, $student_id);

    $attempt = null;
    if ($item) {
        $att_stmt = $conn->prepare("SELECT attempt_id, started_at, submitted_at, score FROM quiz_attempts WHERE item_id=? AND student_id=?");
        $att_stmt->bind_param('ii', $item_id, $student_id);
        $att_stmt->execute();
        $attempt = $att_stmt->get_result()->fetch_assoc();
        $att_stmt->close();

        if (!$attempt) {
            $ins = $conn->prepare("INSERT INTO quiz_attempts (item_id, student_id) VALUES (?, ?)");
            $ins->bind_param('ii', $item_id, $student_id);
            $ins->execute();
            $ins->close();
            $att_stmt = $conn->prepare("SELECT attempt_id, started_at, submitted_at, score FROM quiz_attempts WHERE item_id=? AND student_id=?");
            $att_stmt->bind_param('ii', $item_id, $student_id);
            $att_stmt->execute();
            $attempt = $att_stmt->get_result()->fetch_assoc();
            $att_stmt->close();
        }

        $questions = [];
        if ($attempt['submitted_at'] === null) {
            $q_stmt = $conn->prepare("SELECT question_id, question_text, points FROM quiz_questions WHERE item_id=? ORDER BY sort_order");
            $q_stmt->bind_param('i', $item_id);
            $q_stmt->execute();
            $questions = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $q_stmt->close();

            if (!empty($questions)) {
                $qids = array_column($questions, 'question_id');
                $placeholders = implode(',', array_fill(0, count($qids), '?'));
                $c_stmt = $conn->prepare("SELECT question_id, choice_id, choice_text FROM quiz_choices WHERE question_id IN ($placeholders) ORDER BY sort_order");
                $c_stmt->bind_param(str_repeat('i', count($qids)), ...$qids);
                $c_stmt->execute();
                $choicesByQ = [];
                foreach ($c_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $choicesByQ[(int)$row['question_id']][] = $row;
                }
                $c_stmt->close();
                foreach ($questions as &$q) { $q['choices'] = $choicesByQ[(int)$q['question_id']] ?? []; }
                unset($q);
            }
        }
    }
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          <?= $item ? htmlspecialchars($item['title']) : 'Quiz' ?>
          <span class="student-topbar-subtitle"><?= $item ? htmlspecialchars($item['subject_name']) : '' ?></span>
        </div>
      </div>
      <span class="student-topbar-date"><?= date('F j, Y') ?></span>
    </div>

    <div class="student-content">
      <a class="tq-back" href="student_quizzes">&larr; Back to Quizzes</a>

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if (!$item): ?>
        <div class="notice notice-info">That quiz was not found among your subjects.</div>
      <?php elseif ($attempt['submitted_at'] !== null): ?>
        <div class="student-panel-block">
          <div class="tq-result">You scored <?= rtrim(rtrim(number_format((float)$attempt['score'], 2), '0'), '.') ?> / <?= rtrim(rtrim(number_format((float)$item['max_score'], 2), '0'), '.') ?></div>
        </div>
      <?php elseif (empty($questions)): ?>
        <div class="notice notice-info">This quiz has no questions yet — check back later.</div>
      <?php else: ?>
        <div class="student-panel-block">
          <?php if ($item['time_limit_minutes']): ?>
            <div class="tq-timer" id="tqTimer"></div>
          <?php endif; ?>
          <form method="POST" id="tqForm">
            <input type="hidden" name="item_id" value="<?= $item_id ?>">
            <?php foreach ($questions as $i => $q): ?>
              <div class="tq-question">
                <div class="tq-qtext"><?= ($i + 1) ?>. <?= htmlspecialchars($q['question_text']) ?></div>
                <?php foreach ($q['choices'] as $c): ?>
                  <label class="tq-choice">
                    <input type="radio" name="answer[<?= (int)$q['question_id'] ?>]" value="<?= (int)$c['choice_id'] ?>">
                    <?= htmlspecialchars($c['choice_text']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            <button type="submit" name="submit_quiz" class="btn-primary-gb">Submit Quiz</button>
          </form>
        </div>
        <?php if ($item['time_limit_minutes']): ?>
          <script>
            (function () {
              var startedAt = new Date(<?= json_encode(str_replace(' ', 'T', $attempt['started_at'])) ?>).getTime();
              var limitMs = <?= (int)$item['time_limit_minutes'] ?> * 60 * 1000;
              var deadline = startedAt + limitMs;
              var el = document.getElementById('tqTimer');
              var submitted = false;
              function tick() {
                var remaining = deadline - Date.now();
                if (remaining <= 0) {
                  el.textContent = "Time's up — submitting...";
                  if (!submitted) { submitted = true; document.getElementById('tqForm').submit(); }
                  return;
                }
                var m = Math.floor(remaining / 60000);
                var s = Math.floor((remaining % 60000) / 1000);
                el.textContent = 'Time remaining: ' + m + ':' + (s < 10 ? '0' : '') + s;
                setTimeout(tick, 1000);
              }
              tick();
            })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
