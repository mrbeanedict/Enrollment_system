<?php
declare(strict_types=1);
// Student Portal counterpart to ajax/mark_notifications_read.php — keyed
// on the STUDENT_SESSID cookie/session and users_student.user_student_id
// (students aren't in the shared `users` table at all).
session_name('STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_student_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

$user_student_id = (int) $_SESSION['user_student_id'];

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND recipient_type = 'student' AND is_read = 0");
$stmt->bind_param('i', $user_student_id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
