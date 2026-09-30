<?php
declare(strict_types=1);
// Student LMS counterpart to ajax/mark_notifications_read.php — identical
// to ajax/student_mark_notifications_read.php except for the session name,
// since the LMS is a fully separate session/cookie (STUDENT_LMS_SESSID)
// from the Student Portal even though it's the same underlying student
// account and the same notifications rows (recipient_type='student',
// user_id = users_student.user_student_id).
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_student_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

$user_student_id = (int) $_SESSION['user_student_id'];

// LMS-origin rows only (link under roles/lms/) — the Student Portal bell's
// own unread rows are marked read separately by
// student_mark_notifications_read.php, so the two bells' read state no
// longer bleed into each other.
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND recipient_type = 'student' AND is_read = 0 AND link LIKE 'roles/lms/%'");
$stmt->bind_param('i', $user_student_id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
