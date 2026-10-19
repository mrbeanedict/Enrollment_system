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
    $q = $conn->prepare("SELECT question_id, question_text, question_type, points FROM quiz_questions WHERE item_id = ? ORDER BY sort_order, question_id");
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

// [question_id => answer] already saved for an attempt: the choice_id (int)
// for multiple choice, the typed text (string) for the other types
// (enumeration is a JSON array string).
function quiz_saved_answers(mysqli $conn, int $attempt_id): array {
    $st = $conn->prepare("SELECT question_id, choice_id, answer_text FROM quiz_answers WHERE attempt_id = ?");
    $st->bind_param('i', $attempt_id);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['choice_id'] !== null) {
            $out[(int) $r['question_id']] = (int) $r['choice_id'];
        } elseif ($r['answer_text'] !== null && trim($r['answer_text']) !== '') {
            $out[(int) $r['question_id']] = $r['answer_text'];
        }
    }
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

const QUIZ_TYPED_TYPES    = ['identification', 'fill_blank', 'enumeration'];
const QUIZ_MAX_ENUM_ITEMS = 10;

// Forgiving comparison form of a typed answer: case, punctuation, extra
// spaces and a leading a/an/the are ignored.
function quiz_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace("/['\u{2019}]/u", '', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = trim($s);
    return preg_replace('/^(a|an|the) /u', '', $s);
}

// "chloroplast|chloroplasts" -> normalized, non-empty alternates.
function quiz_alternates(string $row): array {
    $out = [];
    foreach (explode('|', $row) as $alt) {
        $n = quiz_norm($alt);
        if ($n !== '') $out[] = $n;
    }
    return $out;
}

// Clean a student's typed answer for storage. Enumeration answers are an
// array of strings (form) or a JSON array string (autosave) and are stored
// as JSON, capped to $nItems entries. Returns null if the shape is invalid.
function quiz_clean_text_answer(string $type, $raw, int $nItems): ?string {
    if ($type === 'enumeration') {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return null;
        $items = [];
        foreach (array_slice(array_values($raw), 0, max(1, min(QUIZ_MAX_ENUM_ITEMS, $nItems))) as $v) {
            $items[] = is_string($v) ? mb_substr(trim($v), 0, 200) : '';
        }
        // Nothing typed in any box = no answer (stored as '' so it reads as unanswered).
        if (!array_filter($items, fn($v) => $v !== '')) return '';
        return json_encode($items, JSON_UNESCAPED_UNICODE);
    }
    if (!is_string($raw)) return null;
    return mb_substr(trim($raw), 0, 500);
}

// Grade one question. $question has question_type, points and choices (with
// is_correct); $answer is the choice_id (int) for multiple choice or the
// typed text (string, enumeration = JSON array) otherwise. Pure — no DB.
// Returns ['earned' => float, 'correct' => bool, 'choice_id' => ?int, 'text' => ?string].
function quiz_grade_answer(array $question, $answer): array {
    $type   = $question['question_type'] ?? 'multiple_choice';
    $points = (float) $question['points'];
    $res    = ['earned' => 0.0, 'correct' => false, 'choice_id' => null, 'text' => null];

    if ($type === 'multiple_choice') {
        $valid = []; $correct = null;
        foreach ($question['choices'] as $c) {
            $valid[(int) $c['choice_id']] = true;
            if ((int) $c['is_correct'] === 1) $correct = (int) $c['choice_id'];
        }
        if (is_int($answer) && isset($valid[$answer])) {
            $res['choice_id'] = $answer;
            if ($answer === $correct) { $res['correct'] = true; $res['earned'] = $points; }
        }
        return $res;
    }

    $rows = [];
    foreach ($question['choices'] as $c) {
        if ((int) $c['is_correct'] === 1) $rows[] = quiz_alternates((string) $c['choice_text']);
    }
    if (!is_string($answer) || trim($answer) === '' || !$rows) return $res;
    $res['text'] = $answer;

    if ($type === 'enumeration') {
        $entries = json_decode($answer, true);
        if (!is_array($entries)) return $res;
        $n = count($rows); $used = []; $matched = 0;
        foreach (array_slice(array_values($entries), 0, $n) as $e) {
            $norm = quiz_norm(is_string($e) ? $e : '');
            if ($norm === '') continue;
            foreach ($rows as $i => $alts) {
                if (!isset($used[$i]) && in_array($norm, $alts, true)) { $used[$i] = true; $matched++; break; }
            }
        }
        $res['earned']  = round($points * $matched / $n, 2);
        $res['correct'] = $matched === $n;
        return $res;
    }

    // identification / fill_blank: any alternate of the single answer row.
    if (in_array(quiz_norm($answer), $rows[0], true)) { $res['correct'] = true; $res['earned'] = $points; }
    return $res;
}

// Save one typed answer (autosave) for an identification / fill-in-blank /
// enumeration question that belongs to this quiz.
function quiz_save_text_answer(mysqli $conn, int $attempt_id, int $item_id, int $question_id, $raw): bool {
    $st = $conn->prepare("SELECT q.question_type, (SELECT COUNT(*) FROM quiz_choices c WHERE c.question_id = q.question_id AND c.is_correct = 1) AS n
                          FROM quiz_questions q WHERE q.question_id = ? AND q.item_id = ? AND q.question_type <> 'multiple_choice'");
    $st->bind_param('ii', $question_id, $item_id);
    $st->execute();
    $q = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$q) return false;

    $text = quiz_clean_text_answer($q['question_type'], $raw, (int) $q['n']);
    if ($text === null) return false;

    $up = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, choice_id, answer_text, is_correct) VALUES (?, ?, NULL, ?, 0)
                          ON DUPLICATE KEY UPDATE choice_id = NULL, answer_text = VALUES(answer_text)");
    $up->bind_param('iis', $attempt_id, $question_id, $text);
    $ok = $up->execute();
    $up->close();
    return $ok;
}

// Fold the answers posted with the Submit form into the saved ones, each
// validated for its question's type. $posted = $_POST['answer'].
function quiz_merge_posted_answers(array $questions, array $answers, array $posted): array {
    foreach ($questions as $q) {
        $qid = (int) $q['question_id'];
        if (!array_key_exists($qid, $posted)) continue;
        if (($q['question_type'] ?? 'multiple_choice') === 'multiple_choice') {
            if ((int) $posted[$qid] > 0) $answers[$qid] = (int) $posted[$qid];
        } else {
            $n = count(array_filter($q['choices'], fn($c) => (int) $c['is_correct'] === 1));
            $text = quiz_clean_text_answer($q['question_type'], $posted[$qid], $n);
            if ($text !== null && $text !== '') $answers[$qid] = $text;
        }
    }
    return $answers;
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
        $up = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, choice_id, answer_text, is_correct, points_earned) VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE choice_id = VALUES(choice_id), answer_text = VALUES(answer_text),
                                                      is_correct = VALUES(is_correct), points_earned = VALUES(points_earned)");
        foreach ($questions as $q) {
            $qid = (int) $q['question_id'];
            $total += (float) $q['points'];
            $g = quiz_grade_answer($q, $answers[$qid] ?? null);
            $earned += $g['earned'];
            $is_correct = $g['correct'] ? 1 : 0;
            $up->bind_param('iiisid', $attempt_id, $qid, $g['choice_id'], $g['text'], $is_correct, $g['earned']);
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
