<?php
declare(strict_types=1);
// Anti-cheat reports from roles/lms/take_quiz.php.
// POST item_id, reason = hidden | blur | leave | heartbeat
//   heartbeat -> just proves the page is open (updates last_seen).
//   others    -> one strike; the 3rd auto-submits. Counted on the server.
// Also used by navigator.sendBeacon() on pagehide, so the reply may never be read.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $fail(405, 'method'); }
if (!require_login('student', null, 'ignore', 'bool')) { $fail(401, 'login'); }

$student_id = (int) ($_SESSION['student_id'] ?? 0);
$item_id    = (int) ($_POST['item_id'] ?? 0);
$reason     = (string) ($_POST['reason'] ?? '');
if (!in_array($reason, ['hidden', 'blur', 'leave', 'heartbeat'], true)) { $fail(422, 'reason'); }

$item = quiz_item_for_student($conn, $item_id, $student_id);
if (!$item) { $fail(404, 'quiz'); }

$attempt = quiz_attempt($conn, $item, $student_id);
if (!$attempt) { $fail(404, 'attempt'); }
$aid = (int) $attempt['attempt_id'];

$reply = fn(int $v, bool $submitted) => json_encode([
    'ok' => true, 'violations' => $v, 'remaining' => max(0, QUIZ_MAX_STRIKES - $v), 'submitted' => $submitted,
]);

if ($attempt['submitted_at'] !== null) {
    echo $reply((int) $attempt['violations'], true);
    exit();
}
// Out of time: the normal time-up submit handles it, and that is not a strike.
if (quiz_time_up($attempt, QUIZ_GRACE_SECONDS)) { $fail(409, 'time_up'); }

if ($reason === 'heartbeat') {
    quiz_touch($conn, $aid);
    echo $reply((int) $attempt['violations'], false);
    exit();
}

$r = quiz_record_strike($conn, $item, $aid, $student_id);
echo $reply($r['violations'], $r['submitted']);
