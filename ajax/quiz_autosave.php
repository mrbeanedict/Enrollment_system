<?php
declare(strict_types=1);
// Saves one quiz answer the moment the student picks it (roles/lms/take_quiz.php).
// POST item_id, question_id, choice_id (multiple choice) or answer_text
// (typed types; enumeration as a JSON array) → {ok, seconds_left}. Only into
// the student's own open attempt, before the time limit (plus a small
// grace), and only for a question that belongs to that quiz.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/quiz.php';

header('Content-Type: application/json');

$fail = function (int $code, string $error) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'method');
if (!require_login('student', null, 'ignore', 'bool')) $fail(401, 'login');

$student_id  = (int) ($_SESSION['student_id'] ?? 0);
$item_id     = (int) ($_POST['item_id'] ?? 0);
$question_id = (int) ($_POST['question_id'] ?? 0);
$choice_id   = (int) ($_POST['choice_id'] ?? 0);

$item = quiz_item_for_student($conn, $item_id, $student_id);
if (!$item) $fail(404, 'quiz');

$attempt = quiz_attempt($conn, $item, $student_id);
if (!$attempt || $attempt['submitted_at'] !== null) $fail(409, 'submitted');
if (quiz_time_up($attempt, QUIZ_GRACE_SECONDS)) $fail(409, 'time_up');

// Typed answer (identification / fill in the blank / enumeration) or a
// multiple-choice pick.
if (isset($_POST['answer_text'])) {
    if (!quiz_save_text_answer($conn, (int) $attempt['attempt_id'], $item_id, $question_id, (string) $_POST['answer_text'])) $fail(422, 'answer');
} elseif (!quiz_save_answer($conn, (int) $attempt['attempt_id'], $item_id, $question_id, $choice_id)) {
    $fail(422, 'choice');
}

echo json_encode(['ok' => true, 'seconds_left' => $attempt['seconds_left'] === null ? null : (int) $attempt['seconds_left']]);
