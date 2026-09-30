<?php
// Student quiz-taking — shared by roles/lms/take_quiz.php and
// ajax/quiz_autosave.php.
//
// One attempt per student per quiz (quiz_attempts is unique on item+student).
// Every answer is saved the moment it's picked (quiz_answers, unique on
// attempt+question, is_correct filled in at grading), so a refresh, a crash
// or a dropped connection loses nothing. The time limit is enforced here on
// the server, from MySQL's own clock, not the browser's.
require_once BASE_PATH . '/shared/helpers/lms_class.php';

const QUIZ_GRACE_SECONDS = 30; // network slack for an auto-submit fired at 0:00

// The quiz, only if it's in a class the student actually takes (same rule
// as Stream / Discussions / Assignments) — never trust the item_id blindly.
function quiz_item_for_student(mysqli $conn, int $item_id, int $student_id): ?array {
    $stmt = $conn->prepare("
        SELECT gi.item_id, gi.title, gi.max_score, gi.time_limit_minutes, gi.due_date, gi.teacher_id,
               gi.subject_id, gi.section_id, gi.school_year, sub.subject_name
        FROM gradebook_items gi
        JOIN subjects sub ON sub.subject_id = gi.subject_id
        WHERE gi.item_id = ? AND gi.is_quiz = 1
    ");
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !disc_student_owns_class($conn, $student_id, (int) $row['subject_id'], (int) $row['section_id'], $row['school_year'])) {
        return null;
    }
    return $row;
}

// The student's attempt, with seconds_left computed by MySQL (NULL = no
// time limit) so the countdown never depends on the device's clock/timezone.
function quiz_attempt(mysqli $conn, array $item, int $student_id, bool $create = false): ?array {
    $sql = "SELECT attempt_id, started_at, submitted_at, score,
                   CASE WHEN ? > 0 THEN TIMESTAMPDIFF(SECOND, NOW(), started_at + INTERVAL ? MINUTE) END AS seconds_left
            FROM quiz_attempts WHERE item_id = ? AND student_id = ?";
    $limit = (int) $item['time_limit_minutes'];
    $item_id = (int) $item['item_id'];
    $get = function () use ($conn, $sql, $limit, $item_id, $student_id) {
        $st = $conn->prepare($sql);
        $st->bind_param('iiii', $limit, $limit, $item_id, $student_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    };
    $attempt = $get();
    if (!$attempt && $create) {
        $ins = $conn->prepare("INSERT IGNORE INTO quiz_attempts (item_id, student_id) VALUES (?, ?)");
        $ins->bind_param('ii', $item_id, $student_id);
        $ins->execute();
        $ins->close();
        $attempt = $get();
    }
    return $attempt;
}

function quiz_time_up(array $attempt, int $grace = 0): bool {
    return $attempt['seconds_left'] !== null && (int) $attempt['seconds_left'] < -$grace;
}

// Questions (in order) with their choices.
function quiz_questions(mysqli $conn, int $item_id): array {
    $q = $conn->prepare("SELECT question_id, question_text, points FROM quiz_questions WHERE item_id = ? ORDER BY sort_order, question_id");
    $q->bind_param('i', $item_id);
    $q->execute();
    $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    if (!$questions) return [];

    $ids = array_column($questions, 'question_id');
    $c = $conn->prepare("SELECT question_id, choice_id, choice_text, is_correct FROM quiz_choices
                         WHERE question_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY sort_order, choice_id");
    $c->bind_param(str_repeat('i', count($ids)), ...$ids);
    $c->execute();
    $by = [];
    foreach ($c->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $by[(int) $row['question_id']][] = $row;
    $c->close();
    foreach ($questions as &$qq) $qq['choices'] = $by[(int) $qq['question_id']] ?? [];
    unset($qq);
    return $questions;
}

// [question_id => choice_id] already saved for an attempt.
function quiz_saved_answers(mysqli $conn, int $attempt_id): array {
    $st = $conn->prepare("SELECT question_id, choice_id FROM quiz_answers WHERE attempt_id = ? AND choice_id IS NOT NULL");
    $st->bind_param('i', $attempt_id);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[(int) $r['question_id']] = (int) $r['choice_id'];
    $st->close();
    return $out;
}

// Save one answer (autosave). The choice must belong to the question and
// the question to the quiz.
function quiz_save_answer(mysqli $conn, int $attempt_id, int $item_id, int $question_id, int $choice_id): bool {
    $chk = $conn->prepare("SELECT 1 FROM quiz_choices c JOIN quiz_questions q ON q.question_id = c.question_id
                           WHERE c.choice_id = ? AND c.question_id = ? AND q.item_id = ?");
    $chk->bind_param('iii', $choice_id, $question_id, $item_id);
    $chk->execute();
    $ok = (bool) $chk->get_result()->fetch_row();
    $chk->close();
    if (!$ok) return false;

    $up = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, choice_id, is_correct) VALUES (?, ?, ?, 0)
                          ON DUPLICATE KEY UPDATE choice_id = VALUES(choice_id)");
    $up->bind_param('iii', $attempt_id, $question_id, $choice_id);
    $ok = $up->execute();
    $up->close();
    return $ok;
}

// Grade and close the attempt. $answers = [question_id => choice_id]; every
// question gets a row (unanswered = NULL choice, wrong). The scaled score
// goes into the gradebook exactly like a teacher-entered score.
function quiz_finalize(mysqli $conn, array $item, int $attempt_id, int $student_id, array $answers): void {
    $item_id   = (int) $item['item_id'];
    $questions = quiz_questions($conn, $item_id);

    $conn->begin_transaction();
    try {
        // Claim the attempt first — a double submit (or submit racing the
        // expiry auto-finalize) grades only once.
        $claim = $conn->prepare("UPDATE quiz_attempts SET submitted_at = NOW() WHERE attempt_id = ? AND submitted_at IS NULL");
        $claim->bind_param('i', $attempt_id);
        $claim->execute();
        $claimed = $claim->affected_rows > 0;
        $claim->close();
        if (!$claimed) { $conn->rollback(); return; }

        $earned = 0.0; $total = 0.0;
        $up = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, choice_id, is_correct) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE choice_id = VALUES(choice_id), is_correct = VALUES(is_correct)");
        foreach ($questions as $q) {
            $qid = (int) $q['question_id'];
            $pts = (float) $q['points'];
            $total += $pts;
            $correct = null;
            $valid   = [];
            foreach ($q['choices'] as $c) {
                $valid[(int) $c['choice_id']] = true;
                if ((int) $c['is_correct'] === 1) $correct = (int) $c['choice_id'];
            }
            $chosen = isset($answers[$qid], $valid[(int) $answers[$qid]]) ? (int) $answers[$qid] : null;
            $is_correct = ($chosen !== null && $chosen === $correct) ? 1 : 0;
            if ($is_correct) $earned += $pts;
            $up->bind_param('iiii', $attempt_id, $qid, $chosen, $is_correct);
            $up->execute();
        }
        $up->close();

        $att = $conn->prepare("UPDATE quiz_attempts SET score = ? WHERE attempt_id = ?");
        $att->bind_param('di', $earned, $attempt_id);
        $att->execute();
        $att->close();

        $raw = $total > 0 ? round($earned / $total * (float) $item['max_score'], 2) : 0.0;
        $teacher_id = (int) $item['teacher_id'];
        $gs = $conn->prepare("
            INSERT INTO gradebook_scores (item_id, student_id, raw_score, graded_by, graded_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score), graded_by = VALUES(graded_by), graded_at = NOW()
        ");
        $gs->bind_param('iidi', $item_id, $student_id, $raw, $teacher_id);
        $gs->execute();
        $gs->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// Result summary for a submitted attempt: the gradebook score (what the
// teacher sees — it may have been adjusted since) and correct/total counts.
function quiz_result(mysqli $conn, array $item, array $attempt, int $student_id): array {
    $st = $conn->prepare("SELECT raw_score FROM gradebook_scores WHERE item_id = ? AND student_id = ?");
    $st->bind_param('ii', $item['item_id'], $student_id);
    $st->execute();
    $raw = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();

    $st = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(is_correct), 0) FROM quiz_answers WHERE attempt_id = ?");
    $st->bind_param('i', $attempt['attempt_id']);
    $st->execute();
    [$n, $right] = $st->get_result()->fetch_row();
    $st->close();

    return ['score' => $raw, 'max' => $item['max_score'], 'questions' => (int) $n, 'correct' => (int) $right];
}
