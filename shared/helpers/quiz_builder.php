<?php
// Teacher-side quiz authoring helpers — shared by roles/teacher/teacher_quiz_builder.php
// and ajax/teacher_generate_quiz.php.
require_once BASE_PATH . '/shared/helpers/quiz.php'; // QUIZ_TYPED_TYPES / QUIZ_MAX_ENUM_ITEMS

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

// Validate one question the way the manual "Add Question" form does: text,
// positive points, 2-4 non-empty choices, exactly one valid correct index
// (an index into the original $choices array). Returns the cleaned
// ['text', 'points', 'choices' => [...], 'correct' => int] or null.
function quiz_clean_question(string $text, float $points, array $choices, int $correctIdx): ?array {
    $text = trim($text);
    if ($text === '' || $points <= 0 || mb_strlen($text) > 2000) return null;

    $clean = [];
    $newCorrect = -1;
    foreach (array_values($choices) as $i => $c) {
        $c = trim((string) $c);
        if ($c === '') continue;
        if ($i === $correctIdx) $newCorrect = count($clean);
        $clean[] = mb_substr($c, 0, 500);
    }
    if (count($clean) < 2 || count($clean) > 4 || $newCorrect < 0) return null;

    return ['type' => 'multiple_choice', 'text' => $text, 'points' => $points, 'choices' => $clean, 'correct' => $newCorrect];
}

// Validate an identification / fill_blank / enumeration question.
// $answers: for identification and fill_blank, the accepted alternate
// spellings of the ONE answer; for enumeration, the required items (each may
// itself hold "|"-separated alternates). Returns the cleaned
// ['type', 'text', 'points', 'answers' => string[]] or null.
function quiz_clean_typed(string $type, string $text, float $points, array $answers): ?array {
    $text = trim($text);
    if (!in_array($type, QUIZ_TYPED_TYPES, true) || $text === '' || $points <= 0 || mb_strlen($text) > 2000) return null;

    $clean = [];
    foreach ($answers as $a) {
        $a = trim((string) $a);
        if ($a !== '') $clean[] = mb_substr($a, 0, 200);
    }
    if ($type === 'enumeration') {
        if (count($clean) < 2 || count($clean) > QUIZ_MAX_ENUM_ITEMS) return null;
    } else {
        if (count($clean) < 1 || count($clean) > 10) return null;
        $clean = [mb_substr(implode('|', array_map(fn($a) => str_replace('|', '', $a), $clean)), 0, 500)];
    }
    // A fill-in-the-blank sentence needs a visible blank for the student.
    if ($type === 'fill_blank' && !preg_match('/_{2,}/', $text)) $text .= ' _____';

    return ['type' => $type, 'text' => $text, 'points' => $points, 'answers' => $clean];
}

// Insert one already-validated question with its choices (caller owns the
// transaction). Same rows the manual form writes, so grading is identical.
function quiz_insert_question(mysqli $conn, int $item_id, array $q): void {
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM quiz_questions WHERE item_id = ?");
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $ord = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $type = $q['type'] ?? 'multiple_choice';
    $insQ = $conn->prepare("INSERT INTO quiz_questions (item_id, question_text, question_type, points, sort_order) VALUES (?, ?, ?, ?, ?)");
    $insQ->bind_param('issdi', $item_id, $q['text'], $type, $q['points'], $ord);
    $insQ->execute();
    $question_id = $insQ->insert_id;
    $insQ->close();

    $insC = $conn->prepare("INSERT INTO quiz_choices (question_id, choice_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
    if ($type === 'multiple_choice') {
        foreach ($q['choices'] as $i => $text) {
            $isCorrect = ($i === $q['correct']) ? 1 : 0;
            $insC->bind_param('isii', $question_id, $text, $isCorrect, $i);
            $insC->execute();
        }
    } else {
        // Typed types: every row is an accepted answer / required item.
        foreach ($q['answers'] as $i => $text) {
            $isCorrect = 1;
            $insC->bind_param('isii', $question_id, $text, $isCorrect, $i);
            $insC->execute();
        }
    }
    $insC->close();
}

// Replace an identification / fill-in-blank / enumeration question's text,
// points and accepted-answer rows (a $q from quiz_clean_typed). Typed
// answers never reference quiz_choices (quiz_answers.choice_id is NULL for
// them), so swapping the rows can't hit that foreign key.
function quiz_update_typed_question(mysqli $conn, int $question_id, array $q): bool {
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE quiz_questions SET question_text = ?, points = ? WHERE question_id = ?");
        $upd->bind_param('sdi', $q['text'], $q['points'], $question_id);
        $upd->execute();
        $upd->close();

        $del = $conn->prepare("DELETE FROM quiz_choices WHERE question_id = ?");
        $del->bind_param('i', $question_id);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT INTO quiz_choices (question_id, choice_text, is_correct, sort_order) VALUES (?, ?, 1, ?)");
        foreach ($q['answers'] as $i => $text) {
            $ins->bind_param('isi', $question_id, $text, $i);
            $ins->execute();
        }
        $ins->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('quiz_update_typed_question failed: ' . $e->getMessage());
        return false;
    }
}
