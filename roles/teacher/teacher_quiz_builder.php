<?php
// Teachers sign in through the shared login.php (same as admin/staff), so
// this uses the default session — see teacher_sidebar.php for the auth guard.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('teacher', 'teacher_login', 'redirect', 'ignore');
guard_password_change('teacher_change_password', 'teacher');

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$item_id    = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
$return_qs  = $_GET['return'] ?? $_POST['return'] ?? '';

require_once BASE_PATH . '/shared/helpers/quiz_builder.php';

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

// ── Save reviewed AI-generated questions ────────────────────────────────
// The teacher already reviewed/edited the draft; every question is
// re-validated here exactly like the manual form (never trust the client).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_generated'])) {
    $item   = quiz_item_owned($conn, $item_id, $teacher_id);
    $points = (float) ($_POST['gen_points'] ?? 1);
    $added  = 0;
    $skipped = 0;
    if ($item) {
        $conn->begin_transaction();
        try {
            foreach (($_POST['gen_include'] ?? []) as $i => $_on) {
                $gtype = (string) ($_POST['gen_type'][$i] ?? 'multiple_choice');
                if ($gtype === 'multiple_choice') {
                    $clean = quiz_clean_question(
                        (string) ($_POST['gen_q'][$i] ?? ''),
                        $points,
                        (array) ($_POST['gen_choice'][$i] ?? []),
                        (int) ($_POST['gen_correct'][$i] ?? -1)
                    );
                } else {
                    // Typed answers arrive as one text field: alternates split on
                    // "|" (identification / fill in the blank) or one item per
                    // line (enumeration).
                    $rawAns = (string) ($_POST['gen_answers'][$i] ?? '');
                    $parts  = $gtype === 'enumeration' ? preg_split('/\R/u', $rawAns) : explode('|', $rawAns);
                    $clean  = quiz_clean_typed($gtype, (string) ($_POST['gen_q'][$i] ?? ''), $points, $parts ?: []);
                }
                if (!$clean) { $skipped++; continue; }
                quiz_insert_question($conn, $item_id, $clean);
                $added++;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $added = 0;
            error_log('add_generated failed: ' . $e->getMessage());
        }
    }
    if ($added > 0) {
        $_SESSION['qb_flash'] = $added . ' question' . ($added === 1 ? '' : 's') . ' added.' . ($skipped ? " $skipped skipped (incomplete)." : '');
        $_SESSION['qb_flash_type'] = 'success';
    } else {
        $_SESSION['qb_flash'] = 'No questions were added. Check that each selected question has text, 2+ choices and a correct answer.';
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
        $qOwnStmt = $conn->prepare("SELECT question_id FROM quiz_questions WHERE question_id=? AND item_id=? AND question_type='multiple_choice'");
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

// ── Edit an identification / fill-in-the-blank / enumeration question ───
// Multiple choice has its own handler above. Re-validated like the AI draft
// review: one answers field ("|" alternates, or one item per line for
// enumeration).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_typed_question'])) {
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $item  = quiz_item_owned($conn, $item_id, $teacher_id);
    $qType = null;
    if ($item) {
        $tStmt = $conn->prepare("SELECT question_type FROM quiz_questions WHERE question_id=? AND item_id=? AND question_type<>'multiple_choice'");
        $tStmt->bind_param('ii', $question_id, $item_id);
        $tStmt->execute();
        $qType = $tStmt->get_result()->fetch_row()[0] ?? null;
        $tStmt->close();
    }
    $clean = null;
    if ($qType) {
        $rawAns = (string) ($_POST['answers'] ?? '');
        $parts  = $qType === 'enumeration' ? preg_split('/\R/u', $rawAns) : explode('|', $rawAns);
        $clean  = quiz_clean_typed($qType, (string) ($_POST['question_text'] ?? ''), (float) ($_POST['points'] ?? 1), $parts ?: []);
    }
    if (!$item || !$qType) {
        $_SESSION['qb_flash'] = 'That question was not found among your assignments.';
        $_SESSION['qb_flash_type'] = 'error';
    } elseif (!$clean) {
        $_SESSION['qb_flash'] = 'Please provide the question, a valid point value and the answer(s). Enumeration needs 2 to ' . QUIZ_MAX_ENUM_ITEMS . ' items.';
        $_SESSION['qb_flash_type'] = 'error';
    } elseif (quiz_update_typed_question($conn, $question_id, $clean)) {
        $_SESSION['qb_flash'] = 'Question updated.';
        $_SESSION['qb_flash_type'] = 'success';
    } else {
        $_SESSION['qb_flash'] = 'Could not save the question. Please try again.';
        $_SESSION['qb_flash_type'] = 'error';
    }
    header("Location: teacher_quiz_builder?item_id=" . $item_id . "&return=" . urlencode($return_qs));
    exit();
}

// ── Delete ALL questions of this quiz (choices/answers cascade) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_questions'])) {
    $item = quiz_item_owned($conn, $item_id, $teacher_id);
    if ($item) {
        $del = $conn->prepare("DELETE FROM quiz_questions WHERE item_id=?");
        $del->bind_param('i', $item_id);
        $del->execute();
        $n = $del->affected_rows;
        $del->close();
        $_SESSION['qb_flash'] = $n > 0 ? $n . ' question' . ($n === 1 ? '' : 's') . ' deleted.' : 'There were no questions to delete.';
        $_SESSION['qb_flash_type'] = $n > 0 ? 'success' : 'info';
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
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_teacher.css') ?>">
    <style>
      .qb-back {
        display:inline-flex; align-items:center; gap:8px; height:34px; padding:0 14px 0 10px; margin-bottom:.75rem;
        font-size:12.5px; font-weight:600; color:#2F6B4F; text-decoration:none; background:#fff;
        border:0.5px solid #C9D8CF; border-radius:999px; transition:background .15s, border-color .15s, color .15s, box-shadow .15s;
      }
      .qb-back-arrow { display:inline-block; font-size:15px; line-height:1; transition:transform .18s; }
      .qb-back:hover { background:#E6F4EC; border-color:#2F6B4F; color:#1E4D3B; }
      .qb-back:hover .qb-back-arrow { transform:translateX(-4px); }
      .qb-back:active { transform:translateY(1px); }
      .qb-back:focus-visible { outline:none; box-shadow:0 0 0 3px rgba(30,77,59,.25); }
      .qb-panel-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:.5rem; }
      .qb-panel-head .gb-cat-title { margin-bottom:0 !important; }
      .qb-delete-all-form { margin:0; }
      .qb-act {
        display:inline-flex; align-items:center; gap:5px; height:28px; padding:0 10px; font-size:12px; font-weight:600; font-family:inherit;
        background:#fff; border:0.5px solid #D4D4E0; border-radius:6px; color:#5A5A72; cursor:pointer;
        transition:background .15s, border-color .15s, color .15s, box-shadow .15s;
      }
      .qb-act:focus-visible { outline:none; box-shadow:0 0 0 3px rgba(30,77,59,.2); }
      .qb-act-edit:hover, .qb-act-edit[aria-expanded="true"] { background:#E6F4EC; border-color:#2F6B4F; color:#1E4D3B; }
      .qb-act-delete:hover, .qb-act-deleteall:hover { background:#FDECEA; border-color:#D14343; color:#B3261E; }
      .qb-act-delete:focus-visible, .qb-act-deleteall:focus-visible { box-shadow:0 0 0 3px rgba(209,67,67,.2); }
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
      .qb-edit-label { font-size:11px; color:#5A5A72; }
      .qb-edit-note { margin:0; font-size:11px; color:#8A8A9A; }
      .qb-edit-form textarea, .qb-edit-form input[type="text"] { border:0.5px solid #D4D4E0; border-radius:6px; padding:8px 10px; font-size:13px; font-family:inherit; }
      .qb-gen-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:.5rem; }
      .qb-gen-form label { display:flex; flex-direction:column; gap:3px; font-size:11px; color:#5A5A72; }
      .qb-gen-form select { height:32px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px; font-family:inherit; background:#FAFAFC; }
      .qb-gen-form input[type="number"], .qb-gen-form input[type="text"] { height:32px; border:0.5px solid #D4D4E0; border-radius:6px; padding:0 8px; font-size:12px; font-family:inherit; }
      .qb-gen-hint { font-size:11px; color:#8A8A9A; margin:0 0 .5rem; }
      .qb-gen-status { font-size:12px; color:#5A5A72; margin:.5rem 0; min-height:18px; }
      .qb-gen-status.err { color:#C0392B; }
      .qb-draft-q { border:0.5px solid #EBEBF0; border-radius:8px; padding:10px 12px; margin-bottom:8px; background:#FAFAFC; }
      .qb-draft-q.off { opacity:.45; }
      .qb-draft-head { display:flex; align-items:flex-start; gap:8px; margin-bottom:6px; }
      .qb-draft-tag { display:inline-block; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#2F6B4F; background:#E6F4EC; border-radius:999px; padding:1px 8px; margin-bottom:6px; }
      .qb-draft-ans-label { display:block; font-size:11px; color:#5A5A72; margin:4px 0 3px; }
      .qb-draft-q textarea.qb-draft-ans, .qb-draft-q input.qb-draft-ans { width:100%; box-sizing:border-box; border:0.5px solid #D4D4E0; border-radius:6px; padding:6px 8px; font-size:13px; font-family:inherit; }
      .qb-draft-head textarea { flex:1; border:0.5px solid #D4D4E0; border-radius:6px; padding:6px 8px; font-size:13px; font-family:inherit; }
      .qb-draft-q .qb-choice-row input[type="text"] { border:0.5px solid #D4D4E0; border-radius:6px; padding:6px 8px; font-size:13px; font-family:inherit; }
    </style>
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once BASE_PATH . '/shared/includes/teacher_sidebar.php';
    $item = quiz_item_owned($conn, $item_id, $teacher_id);

    $questions = [];
    $submittedCount = 0; // students who already submitted — shown in the "Delete all" warning
    if ($item) {
        $sc = $conn->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE item_id = ? AND submitted_at IS NOT NULL");
        $sc->bind_param('i', $item_id);
        $sc->execute();
        $submittedCount = (int) $sc->get_result()->fetch_row()[0];
        $sc->close();

        $q_stmt = $conn->prepare("SELECT question_id, question_text, question_type, points FROM quiz_questions WHERE item_id=? ORDER BY sort_order");
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
      <?php include BASE_PATH . '/shared/includes/teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">
      <a class="qb-back" id="qbBack" href="teacher_gradebook?<?= htmlspecialchars($return_qs) ?>"><span class="qb-back-arrow" aria-hidden="true">&larr;</span> Back to Assessment</a>

      <?php if ($flash): ?><div class="notice notice-<?= htmlspecialchars($flash_type) ?>" data-ui-toast><?= htmlspecialchars($flash) ?></div><?php endif; ?>

      <?php if (!$item): ?>
        <div class="notice notice-info">That quiz was not found among your assignments.</div>
      <?php else: ?>

        <div class="teacher-panel-block">
          <div class="gb-cat-title" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2F6B4F;margin-bottom:.5rem;">Generate from File (AI)</div>
          <p class="qb-gen-hint">Upload lesson notes as a PDF or PowerPoint (.pptx) and AI drafts multiple-choice questions. You review and edit them before they are added. Students never see the answers.</p>
          <form id="qbGenForm" class="qb-gen-form" enctype="multipart/form-data">
            <input type="hidden" name="item_id" value="<?= $item_id ?>">
            <label>PDF or PowerPoint (.pptx), 10 MB max<input type="file" name="source_file" accept=".pdf,.pptx,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation" required></label>
            <label>Question type
              <select name="question_type" id="qbGenType">
                <option value="multiple_choice">Multiple choice</option>
                <option value="identification">Identification</option>
                <option value="fill_blank">Fill in the blank</option>
                <option value="enumeration">Enumeration</option>
              </select>
            </label>
            <label>Questions<input type="number" name="num_questions" value="10" min="1" max="20" style="width:80px;"></label>
            <label>Focus / difficulty (optional)<input type="text" name="instructions" maxlength="300" placeholder="e.g. Chapter 2 only, easy" style="width:220px;"></label>
            <button type="submit" class="btn-primary-gb" id="qbGenBtn" style="align-self:flex-end;">Generate</button>
          </form>
          <div class="qb-gen-status" id="qbGenStatus"></div>
          <form method="POST" id="qbDraftForm" style="display:none;">
            <input type="hidden" name="item_id" value="<?= $item_id ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
            <div class="qb-points-row" style="margin-bottom:8px;">Points per question: <input type="number" name="gen_points" value="1" min="0.01" step="0.01"></div>
            <div id="qbDraftList"></div>
            <button type="submit" name="add_generated" class="btn-primary-gb">Add selected to quiz</button>
          </form>
        </div>

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
          <div class="qb-panel-head">
            <div class="gb-cat-title" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2F6B4F;">Questions (<?= count($questions) ?>)</div>
            <?php if (!empty($questions)):
              $nQ = count($questions);
              $delAllMsg = 'Delete all ' . $nQ . ' question' . ($nQ === 1 ? '' : 's') . ' in this quiz? This cannot be undone.'
                         . ($submittedCount > 0 ? ' ' . $submittedCount . ' student' . ($submittedCount === 1 ? ' has' : 's have') . ' already submitted it: their scores stay in the gradebook, but their answer details are removed.' : '');
            ?>
              <form method="POST" class="qb-delete-all-form" data-confirm="<?= htmlspecialchars($delAllMsg, ENT_QUOTES) ?>" data-icon="warning">
                <input type="hidden" name="item_id" value="<?= $item_id ?>">
                <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
                <button type="submit" name="delete_all_questions" class="qb-act qb-act-deleteall" title="Delete every question in this quiz">&#128465; Delete all</button>
              </form>
            <?php endif; ?>
          </div>
          <?php if (empty($questions)): ?>
            <p class="empty-state">No questions added yet.</p>
          <?php else: ?>
            <?php foreach ($questions as $q): $qid = (int)$q['question_id']; ?>
              <div class="qb-question">
                <?php $isMc = ($q['question_type'] ?? 'multiple_choice') === 'multiple_choice'; ?>
                <span class="qb-question-actions">
                  <button type="button" class="qb-act qb-act-edit" id="edit-btn-<?= $qid ?>" onclick="toggleEditQuestion(<?= $qid ?>)" aria-expanded="false" aria-controls="edit-form-<?= $qid ?>" title="Edit this question">&#9998; Edit</button>
                  <form method="POST" style="display:inline;" data-confirm="Delete this question?" data-icon="warning">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
                    <input type="hidden" name="question_id" value="<?= $qid ?>">
                    <button type="submit" name="delete_question" class="qb-act qb-act-delete" title="Delete this question">&#128465; Delete</button>
                  </form>
                </span>
                <div class="qb-qtext"><?= htmlspecialchars($q['question_text']) ?></div>
                <?php $typeLabels = ['multiple_choice' => 'Multiple choice', 'identification' => 'Identification', 'fill_blank' => 'Fill in the blank', 'enumeration' => 'Enumeration']; ?>
                <div class="qb-qmeta"><?= $typeLabels[$q['question_type'] ?? 'multiple_choice'] ?? '' ?> · <?= rtrim(rtrim(number_format((float)$q['points'], 2), '0'), '.') ?> pt(s)</div>
                <?php if ($isMc): foreach ($q['choices'] as $c): ?>
                  <div class="qb-choice <?= $c['is_correct'] ? 'correct' : '' ?>"><?= $c['is_correct'] ? '✓ ' : '— ' ?><?= htmlspecialchars($c['choice_text']) ?></div>
                <?php endforeach; else: foreach ($q['choices'] as $c): ?>
                  <div class="qb-choice correct">✓ <?= htmlspecialchars(str_replace('|', ' / ', $c['choice_text'])) ?></div>
                <?php endforeach; endif; ?>

                <?php if ($isMc): ?>
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
                <?php else:
                  $ansLines = array_column($q['choices'], 'choice_text');
                  $isEnum   = $q['question_type'] === 'enumeration';
                ?>
                <form method="POST" class="qb-add-form qb-edit-form" id="edit-form-<?= $qid ?>" style="display:none;">
                  <input type="hidden" name="item_id" value="<?= $item_id ?>">
                  <input type="hidden" name="return" value="<?= htmlspecialchars($return_qs) ?>">
                  <input type="hidden" name="question_id" value="<?= $qid ?>">
                  <textarea name="question_text" rows="2" placeholder="Question text" required><?= htmlspecialchars($q['question_text']) ?></textarea>
                  <div class="qb-points-row">Points: <input type="number" name="points" value="<?= htmlspecialchars($q['points']) ?>" min="0.01" step="0.01"></div>
                  <?php if ($isEnum): ?>
                    <label class="qb-edit-label">Required items, one per line (use | between acceptable spellings of one item). Students get partial credit per item, in any order.</label>
                    <textarea name="answers" rows="<?= max(3, count($ansLines)) ?>" required><?= htmlspecialchars(implode("\n", $ansLines)) ?></textarea>
                  <?php else: ?>
                    <label class="qb-edit-label">Accepted answer (use | between acceptable alternates, e.g. chloroplast|chloroplasts). Capitalization and punctuation are ignored.</label>
                    <input type="text" name="answers" value="<?= htmlspecialchars($ansLines[0] ?? '') ?>" required>
                  <?php endif; ?>
                  <p class="qb-edit-note">Changes apply to future grading only. Students who already submitted keep their scores.</p>
                  <div>
                    <button type="submit" name="edit_typed_question" class="btn-primary-gb">Save Changes</button>
                    <button type="button" class="btn-sm" onclick="toggleEditQuestion(<?= $qid ?>)">Cancel</button>
                  </div>
                </form>
                <?php endif; ?>
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
      if (!f) return;
      var open = (f.style.display === 'none' || !f.style.display);
      f.style.display = open ? 'flex' : 'none';
      var b = document.getElementById('edit-btn-' + id);
      if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var first = f.querySelector('textarea, input[type="text"]'); if (first) first.focus(); }
    }

    // Leaving with generated questions that were never added would lose them: confirm first.
    (function () {
      var back = document.getElementById('qbBack');
      var draft = document.getElementById('qbDraftForm');
      if (!back || !draft) return;
      back.addEventListener('click', function (e) {
        if (draft.style.display === 'none') return; // nothing unsaved
        e.preventDefault();
        var go = function () { window.location.href = back.href; };
        var msg = "You have generated questions that haven't been added to the quiz yet. Leave anyway?";
        if (typeof Swal === 'undefined') { if (window.confirm(msg)) go(); return; }
        Swal.fire({ title: 'Unsaved questions', text: msg, icon: 'warning', showCancelButton: true,
                    confirmButtonText: 'Leave', cancelButtonText: 'Stay', confirmButtonColor: '#1E4D3B', cancelButtonColor: '#aaa' })
          .then(function (r) { if (r.isConfirmed) go(); });
      });
    })();

    (function () {
      var form = document.getElementById('qbGenForm');
      if (!form) return;
      var btn = document.getElementById('qbGenBtn');
      var status = document.getElementById('qbGenStatus');
      var draftForm = document.getElementById('qbDraftForm');
      var list = document.getElementById('qbDraftList');

      function el(tag, attrs, children) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
          if (k === 'text') e.textContent = attrs[k]; else e.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { e.appendChild(c); });
        return e;
      }

      // Built with DOM APIs + .value/textContent (never innerHTML) so AI/PDF
      // text can't inject markup.
      var TYPE_LABELS = { multiple_choice: 'Multiple choice', identification: 'Identification', fill_blank: 'Fill in the blank', enumeration: 'Enumeration' };

      function renderDraft(questions) {
        list.textContent = '';
        questions.forEach(function (q, i) {
          var box = el('div', { 'class': 'qb-draft-q' });
          box.appendChild(el('span', { 'class': 'qb-draft-tag', text: TYPE_LABELS[q.type] || '' }));
          box.appendChild(el('input', { type: 'hidden', name: 'gen_type[' + i + ']', value: q.type }));
          var chk = el('input', { type: 'checkbox', name: 'gen_include[' + i + ']', value: '1', checked: 'checked', title: 'Include this question' });
          chk.addEventListener('change', function () { box.classList.toggle('off', !chk.checked); });
          var ta = el('textarea', { name: 'gen_q[' + i + ']', rows: '2' });
          ta.value = q.question;
          box.appendChild(el('div', { 'class': 'qb-draft-head' }, [chk, ta]));

          if (q.type === 'multiple_choice') {
            q.choices.forEach(function (c, j) {
              var radio = el('input', { type: 'radio', name: 'gen_correct[' + i + ']', value: String(j), title: 'Mark as correct' });
              if (j === q.correct_index) radio.checked = true;
              var txt = el('input', { type: 'text', name: 'gen_choice[' + i + '][]' });
              txt.value = c;
              box.appendChild(el('div', { 'class': 'qb-choice-row' }, [radio, txt]));
            });
          } else if (q.type === 'enumeration') {
            box.appendChild(el('label', { 'class': 'qb-draft-ans-label', text: 'Required items (one per line; use | between acceptable spellings of one item). Students get partial credit per item, in any order.' }));
            var eta = el('textarea', { 'class': 'qb-draft-ans', name: 'gen_answers[' + i + ']', rows: String(Math.max(3, q.answers.length)) });
            eta.value = q.answers.join('\n');
            box.appendChild(eta);
          } else {
            box.appendChild(el('label', { 'class': 'qb-draft-ans-label', text: 'Accepted answer (use | between acceptable alternates, e.g. chloroplast|chloroplasts). Capitalization and punctuation are ignored.' }));
            var ans = el('input', { type: 'text', 'class': 'qb-draft-ans', name: 'gen_answers[' + i + ']' });
            ans.value = q.answers.join('|');
            box.appendChild(ans);
          }
          list.appendChild(box);
        });
        draftForm.style.display = '';
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Generating…';
        status.className = 'qb-gen-status';
        status.textContent = 'Reading your file and drafting questions. This can take up to a minute.';
        draftForm.style.display = 'none';

        fetch('<?= APP_URL ?>/ajax/teacher_generate_quiz', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.success) throw new Error(res.error || 'Generation failed.');
            var n = res.questions.length;
            status.textContent = n + ' draft question' + (n === 1 ? '' : 's') + ' ready. Review and edit them, untick any you do not want, then add them.';
            renderDraft(res.questions);
          })
          .catch(function (err) {
            status.className = 'qb-gen-status err';
            status.textContent = err.message || 'Something went wrong. Please try again.';
          })
          .finally(function () {
            btn.disabled = false;
            btn.textContent = 'Generate';
          });
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
