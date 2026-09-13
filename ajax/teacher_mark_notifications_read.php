<?php
declare(strict_types=1);
// Teacher-portal counterpart to ajax/mark_notifications_read.php — same
// shape, but keyed on the TEACHER_SESSID cookie/session, since the staff
// portal's default/unnamed session isn't active here.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND recipient_type = 'teacher' AND is_read = 0");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
