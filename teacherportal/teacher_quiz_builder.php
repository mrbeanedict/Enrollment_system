<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../teacherportal/teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$item_id    = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
$return_qs  = $_GET['return'] ?? $_POST['return'] ?? '';

// Confirm this item belongs to a class the teacher actually teaches and is
// genuinely an online quiz — never trust the posted item_id blindly.
function quiz_item_owned(mysqli $conn, int $item_id, int $teacher_id): ?array {
    $stmt = $conn->prepare("
        SELECT gi.item_id, gi.title, gi.max_score, sub.subject_name, sec.section_name
        FROM gradebook_items gi
        JOIN subjects sub ON sub.subject_id = gi.subject_id
        JOIN sections sec ON sec.section_id = gi.section_id
        JOIN teacher_assignments ta ON ta.teacher_id = gi.teacher_id AND ta.subject_id = gi.subject_id
            AND ta.section_id = gi.section_id AND ta.school_year = gi.school_year AND ta.is_active = 1
        WHERE gi.item_id = ? AND gi.teacher_id = ? AND gi.is_quiz = 1
        LIMIT 1
    ");
    $stmt->bind_param('ii', $item_id, $teacher_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$flash      = '';
$flash_type = 'info';

// ── Add a question with its choices ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $item = quiz_item_owned($conn, $item_id, $teacher_id);
    $qtext  = trim($_POST['question_text'] ?? '');
    $points = (float) ($_POST['points'] ?? 1);
    $choiceTexts = array_map('trim', $_POST['choice_text'] ?? []);
    $correctIdx  = (int) ($_POST['correct_choice'] ?? -1);

    $nonEmpty = [];
    foreach ($choiceTexts as $i => $t) { if ($t !== '') $nonEmpty[$i] = $t; }

    if (!$item) {
        $flash_msg = 'That quiz was not found among your assignments.';
        $ok = false;
    } elseif ($qtext === '' || $points <= 0) {
        $flash_msg = 'Please provide question text and a valid point value.';
        $ok = false;
    } elseif (count($nonEmpty) < 2 || count($nonEmpty) > 6) {
        $flash_msg = 'Provide between 2 and 6 answer choices.';
        $ok = false;
    } elseif (!isset($nonEmpty[$correctIdx])) {
        $flash_msg = 'Please mark exactly one correct choice.';
        $ok = false;
    } else {
        $ok = true;
    }

    if ($ok) {
        $conn->begin_transaction();
        $maxOrd = $conn->query("SELECT COALESCE(MAX(sort_order),-1)+1 AS n FROM quiz_questions WHERE item_id=" . (int)$item_id)->fetch_assoc()['n'];
        $insQ = $conn->prepare("INSERT INTO quiz_questions (item_id, question_text, points, sort_order) VALUES (?, ?, ?, ?)");
        $insQ->bind_param('isdi', $item_id, $qtext, $points, $maxOrd);
        $insQ->execute();
        $question_id = $insQ->insert_id;
        $insQ->close();

        $insC = $conn->prepare("INSERT INTO quiz_choices (question_id, choice_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
        $ord = 0;
        foreach ($nonEmpty as $i => $text) {
            $isCorrect = ($i === $correctIdx) ? 1 : 0;
            $insC->bind_param('isii', $question_id, $text, $isCorrect, $ord);
            $insC->execute();
            $ord++;
        }
        $insC->close();
        $conn->commit();
        $_SESSION['qb_flash'] = 'Question added.';
        $_SESSION['qb_flash_type'] = 'success';
    } else {
        $_SESSION['qb_flash'] = $flash_msg;
        $_SESSION['qb_flash_type'] = 'error';
    }
    header("Location: teacher_quiz_builder?item_id=" . $item_id . "&return=" . urlencode($return_qs));
    exit();
}

// ── Edit an existing question's text/points/choices ─────────────────────
// Choices are matched to existing rows by position (sort_order) and
// updated in place rather than deleted+recreated — quiz_answers.choice_id
// has no ON DELETE CASCADE, so deleting a choice a student already
// picked would fail; updating in place sidesteps that entirely for the
// common case (same choice count, just editing text/correctness).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $item = quiz_item_owned($conn, $item_id, $teacher_id);
    $qtext  = trim($_POST['question_text'] ?? '');
    $points = (float) ($_POST['points'] ?? 1);
    $choiceTexts = array_map('trim', $_POST['choice_text'] ?? []);
    $correctIdx  = (int) ($_POST['correct_choice'] ?? -1);

    $nonEmpty = [];
    foreach ($choiceTexts as $i => $t) { if ($t !== '') $nonEmpty[$i] = $t; }

    $qOwn = null;
    if ($item) {
        $qOwnStmt = $conn->prepare("SELECT question_id FROM quiz_questions WHERE question_id=? AND item_id=?");
        $qOwnStmt->bind_param('ii', $question_id, $item_id);
        $qOwnStmt->execute();
        $qOwn = $qOwnStmt->get_result()->fetch_assoc();
        $qOwnStmt->close();
    }

    if (!$item || !$qOwn) {
        $flash_msg = 'That question was not found among your assignments.';
        $ok = false;
    } elseif ($qtext === '' || $points <= 0) {
        $flash_msg = 'Please provide question text and a valid point value.';
        $ok = false;
    } elseif (count($nonEmpty) < 2 || count($nonEmpty) > 4) {
        $flash_msg = 'Provide between 2 and 4 answer choices.';
        $ok = false;
    } elseif (!isset($nonEmpty[$correctIdx])) {
        $flash_msg = 'Please mark exactly one correct choice.';
        $ok = false;
    } else {
        $ok = true;
    }

    if ($ok) {
        $upd = $conn->prepare("UPDATE quiz_questions SET question_text=?, points=? WHERE question_id=?");
        $upd->bind_param('sdi', $qtext, $points, $question_id);
        $upd->execute();
        $upd->close();

        // Existing choices, indexed 0..n-1 in the same compacted order the
        // edit form itself renders them into slots (see $q['choices'][$i]
        // below) — so slot index $i here means exactly the same choice the
        // teacher saw pre-filled (or empty) in that slot, however many
        // edits have happened before this one.
        $ec_stmt = $conn->prepare("SELECT choice_id FROM quiz_choices WHERE question_id=? ORDER BY sort_order");
        $ec_stmt->bind_param('i', $question_id);
        $ec_stmt->execute();
        $existingChoices = array_column($ec_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'choice_id');
        $ec_stmt->close();

        $updC = $conn->prepare("UPDATE quiz_choices SET choice_text=?, is_correct=? WHERE choice_id=?");
        $insC = $conn->prepare("INSERT INTO quiz_choices (question_id, choice_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
        $delC = $conn->prepare("DELETE FROM quiz_choices WHERE choice_id=?");
        for ($i = 0; $i < 4; $i++) {
            $hasNew      = isset($nonEmpty[$i]);
            $existingCid = $existingChoices[$i] ?? null;
            if ($hasNew && $existingCid) {
                $isCorrect = ($i === $correctIdx) ? 1 : 0;
                $updC->bind_param('sii', $nonEmpty[$i], $isCorrect, $existingCid);
                $updC->execute();
            } elseif ($hasNew && !$existingCid) {
                $isCorrect = ($i === $correctIdx) ? 1 : 0;
                $insC->bind_param('isii', $question_id, $nonEmpty[$i], $isCorrect, $i);
                $insC->execute();
            } elseif (!$hasNew && $existingCid) {
                // Slot was cleared — best-effort delete; a choice a student
                // already answered can't be removed (FK — mysqli throws on
                // this by default in PHP 8.1+, so a real try/catch is
                // needed, not just @), so it's silently left behind
                // (unused, since no slot points at it anymore) rather than
                // erroring out.
                $delC->bind_param('i', $existingCid);
                try {
                    $delC->execute();
                } catch (mysqli_sql_exception $e) {
                    // Leave the orphaned-but-referenced choice row in place.
                }
            }
        }
        $updC->close();
        $insC->close();
        $delC->close();

        $_SESSION['qb_flash'] = 'Question updated.';
        $_SESSION['qb_flash_type'] = 'success';
    } else {
        $_SESSION['qb_flash'] = $flash_msg;
        $_SESSION['qb_flash_type'] = 'error';
    }
    header("Location: teacher_quiz_builder?item_id=" . $item_id . "&return=" . urlencode($return_qs));
    exit();
}

// ── Delete a question (choices/answers cascade) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $item = quiz_item_owned($conn, $item_id, $teacher_id);
    if ($item) {
        $del = $conn->prepare("DELETE FROM quiz_questions WHERE question_id=? AND item_id=?");
        $del->bind_param('ii', $question_id, $item_id);
        $del->execute();
        $_SESSION['qb_flash'] = $del->affected_rows > 0 ? 'Question deleted.' : 'Question not found.';
        $_SESSION['qb_flash_type'] = $del->affected_rows > 0 ? 'success' : 'error';
        $del->close();
    } else {
        $_SESSION['qb_flash'] = 'That quiz was not found among your assignments.';
        $_SESSION['qb_flash_type'] = 'error';
    }
    header("Location: teacher_quiz_builder?item_id=" . $item_id . "&return=" . urlencode($return_qs));
    exit();
}

$flash      = $_SESSION['qb_flash']      ?? '';
$flash_type = $_SESSION['qb_flash_type'] ?? 'info';
unset($_SESSION['qb_flash'], $_SESSION['qb_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Builder — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../css/css_teacher.css') ?>">
    <style>
      .qb-back { font-size:12px; color:#5A5A72; text-decoration:none; display:inline-block; margin-bottom:.75rem; }
      .qb-back:hover { color:var(--brand-primary); }
      .qb-question { border-bottom:0.5px solid #EBEBF0; padding:12px 0; }
      .qb-question:last-child { border-bottom:none; }
      .qb-qtext { font-weight:600; font-size:14px; color:#1A1A2E; }
      .qb-qmeta { font-size:11px; color:#8A8A9A; margin:2px 0 6px; }
      .qb-choice { font-size:13px; padding:2px 0 2px 14px; color:#5A5A72; }
      .qb-choice.correct { color:#1A6B4A; font-weight:600; }
      .qb-add-form { display:flex; flex-direction:column; gap:8px; max-width:520px; margin-bottom:1.25rem; }
      .qb-add-form input[type="text"], .qb-add-form textarea {
        border:0.5px solid #D4D4E0; border-radius:6px; padding:8px 10px; font-size:13px; font-family:inherit;
      }
      .qb-choice-row { display:flex; align-items:center; gap:8px; }
      .qb-choice-row input[type="text"] { flex:1; }
      .qb-points-row { display:flex; align-items:center; gap:8px; font-size:12px; color:#5A5A72; }
      .qb-points-row input { width:80px; height:32px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px; font-family:inherit; }
      .btn-sm { height:32px; padding:0 12px; border:0.5px solid #D4D4E0; border-radius:6px; background:#fff; font-size:12px; font-family:inherit; cursor:pointer; color:#5A5A72; }
      .btn-sm:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
      .btn-primary-gb { height:38px; padding:0 18px; background:var(--brand-primary); border:none; border-radius:8px; color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; align-self:flex-start; }
      .btn-primary-gb:hover { background:var(--brand-primary-hover); }
      .qb-item-delete { border:none; background:none; cursor:pointer; font-size:12px; color:#8A8A9A; font-family:inherit; }
      .qb-item-delete:hover { color:#C0392B; }
      .qb-question-actions { float:right; display:flex; align-items:center; gap:10px; }
      .qb-item-edit { border:none; background:none; cursor:pointer; font-size:12px; color:#8A8A9A; font-family:inherit; }
      .qb-item-edit:hover { color:var(--brand-primary); }
      .qb-edit-form { margin-top:10px; padding-top:10px; border-top:0.5px dashed #EBEBF0; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'teacher_sidebar.php';
    $item = quiz_item_owned($conn, $item_id, $teacher_id);

    $questions = [];
    if ($item) {
        $q_stmt = $conn->prepare("SELECT question_id, question_text, points FROM quiz_questions WHERE item_id=? ORDER BY sort_order");
        $q_stmt->bind_param('i', $item_id);
        $q_stmt->execute();
        $questions = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $q_stmt->close();

        if (!empty($questions)) {
            $qids = array_column($questions, 'question_id');
            $placeholders = implode(',', array_fill(0, count($qids), '?'));
            $c_stmt = $conn->prepare("SELECT question_id, choice_text, is_correct FROM quiz_choices WHERE question_id IN ($placeholders) ORDER BY sort_order");
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
  ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          Quiz Builder
          <span class="teacher-topbar-subtitle"><?= $item ? htmlspecialchars($item['title']) . ' — ' . htmlspecialchars($item['subject_name']) . ' (' . htmlspecialchars($item['section_name']) . ')' : '' ?></span>
        </div>
      </div>
      <?php include 'teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">
      <a class="qb-back" href="teacher_gradebook?<?= htmlspecialchars($return_qs) ?>">&larr; Back to Assessment</a>

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if (!$item): ?>
        <div class="notice notice-info">That quiz was not found among your assignments.</div>
      <?php else: ?>

        <div class="teacher-panel-block">
          <div class="gb-cat-title" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2F6B4F;margin-bottom:.5rem;">Add Question</div>
          <form method="POST" class="qb-add-form">
            <input type="hidden" name="item_id" value="<?= $item_id ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
            <textarea name="question_text" rows="2" placeholder="Question text" required></textarea>
            <div class="qb-points-row">Points: <input type="number" name="points" value="1" min="0.01" step="0.01"></div>
            <?php for ($i = 0; $i < 4; $i++): ?>
              <div class="qb-choice-row">
                <input type="radio" name="correct_choice" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?> title="Mark as correct">
                <input type="text" name="choice_text[]" placeholder="Choice <?= $i + 1 ?><?= $i < 2 ? ' (required)' : ' (optional)' ?>">
              </div>
            <?php endfor; ?>
            <button type="submit" name="add_question" class="btn-primary-gb">+ Add Question</button>
          </form>
        </div>

        <div class="teacher-panel-block">
          <div class="gb-cat-title" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2F6B4F;margin-bottom:.5rem;">Questions (<?= count($questions) ?>)</div>
          <?php if (empty($questions)): ?>
            <p class="empty-state">No questions added yet.</p>
          <?php else: ?>
            <?php foreach ($questions as $q): $qid = (int)$q['question_id']; ?>
              <div class="qb-question">
                <span class="qb-question-actions">
                  <button type="button" class="qb-item-edit" onclick="toggleEditQuestion(<?= $qid ?>)" title="Edit">&#9998; Edit</button>
                  <form method="POST" style="display:inline;" data-confirm="Delete this question?" data-icon="warning">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
                    <input type="hidden" name="question_id" value="<?= $qid ?>">
                    <button type="submit" name="delete_question" class="qb-item-delete" title="Delete">&times; Delete</button>
                  </form>
                </span>
                <div class="qb-qtext"><?= htmlspecialchars($q['question_text']) ?></div>
                <div class="qb-qmeta"><?= rtrim(rtrim(number_format((float)$q['points'], 2), '0'), '.') ?> pt(s)</div>
                <?php foreach ($q['choices'] as $c): ?>
                  <div class="qb-choice <?= $c['is_correct'] ? 'correct' : '' ?>"><?= $c['is_correct'] ? '✓ ' : '— ' ?><?= htmlspecialchars($c['choice_text']) ?></div>
                <?php endforeach; ?>

                <form method="POST" class="qb-add-form qb-edit-form" id="edit-form-<?= $qid ?>" style="display:none;">
                  <input type="hidden" name="item_id" value="<?= $item_id ?>">
                  <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
                  <input type="hidden" name="question_id" value="<?= $qid ?>">
                  <textarea name="question_text" rows="2" placeholder="Question text" required><?= htmlspecialchars($q['question_text']) ?></textarea>
                  <div class="qb-points-row">Points: <input type="number" name="points" value="<?= htmlspecialchars($q['points']) ?>" min="0.01" step="0.01"></div>
                  <?php for ($i = 0; $i < 4; $i++): $c = $q['choices'][$i] ?? null; ?>
                    <div class="qb-choice-row">
                      <input type="radio" name="correct_choice" value="<?= $i ?>" <?= ($c && $c['is_correct']) ? 'checked' : '' ?> title="Mark as correct">
                      <input type="text" name="choice_text[]" value="<?= htmlspecialchars($c['choice_text'] ?? '') ?>" placeholder="Choice <?= $i + 1 ?><?= $i < 2 ? ' (required)' : ' (optional)' ?>">
                    </div>
                  <?php endfor; ?>
                  <div>
                    <button type="submit" name="edit_question" class="btn-primary-gb">Save Changes</button>
                    <button type="button" class="btn-sm" onclick="toggleEditQuestion(<?= $qid ?>)">Cancel</button>
                  </div>
                </form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    </div>
  </div>
  <script>
    function toggleEditQuestion(id) {
      var f = document.getElementById('edit-form-' + id);
      if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'flex' : 'none';
    }
  </script>
<?php endif; ?>
</body>
</html>
