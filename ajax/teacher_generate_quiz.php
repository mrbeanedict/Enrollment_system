<?php
declare(strict_types=1);
// Drafts quiz questions from an uploaded PDF (see shared/helpers/ai_quiz.php).
// Returns JSON only — nothing is saved to the quiz here; the teacher reviews
// the draft in teacher_quiz_builder.php and saves it with add_generated.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/shared/helpers/quiz_builder.php';
require_once BASE_PATH . '/shared/helpers/ai_quiz.php';

header('Content-Type: application/json');

function gen_fail(string $msg, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') gen_fail('Invalid request.', 405);
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher' || !isset($_SESSION['teacher_id'])) {
    gen_fail('Not authenticated.', 401);
}

$teacher_id = (int) $_SESSION['teacher_id'];
$item_id    = (int) ($_POST['item_id'] ?? 0);
if (!quiz_item_owned($conn, $item_id, $teacher_id)) gen_fail('That quiz was not found among your assignments.');

// Simple per-session throttle to protect the API key/quota: 5 per 10 minutes.
$now = time();
$_SESSION['ai_quiz_calls'] = array_values(array_filter($_SESSION['ai_quiz_calls'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['ai_quiz_calls']) >= 5) gen_fail('Too many generations. Please wait a few minutes and try again.');

$file = $_FILES['source_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    gen_fail('Please choose a PDF file to upload.');
}
$ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf' || !upload_content_matches_ext($file['tmp_name'], 'pdf')) gen_fail('Only PDF files are supported.');
if ($file['size'] > 10 * 1024 * 1024) gen_fail('The PDF is too large (10 MB max).');

$n            = max(1, min(AI_QUIZ_MAX_QUESTIONS, (int) ($_POST['num_questions'] ?? 10)));
$instructions = (string) ($_POST['instructions'] ?? '');
$type         = (string) ($_POST['question_type'] ?? 'multiple_choice');
if (!in_array($type, AI_QUIZ_TYPES, true)) gen_fail('Please choose a valid question type.');

$_SESSION['ai_quiz_calls'][] = $now;
// Read once and discard — the upload is never stored.
$pdf = file_get_contents($file['tmp_name']);
@unlink($file['tmp_name']);
session_write_close(); // don't hold the session lock during the slow API call

try {
    $questions = ai_generate_quiz($pdf, $n, $instructions, $type);
} catch (RuntimeException $e) {
    gen_fail($e->getMessage());
}

echo json_encode(['success' => true, 'questions' => $questions]);
