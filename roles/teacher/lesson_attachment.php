<?php
// Serves a lesson attachment to the teacher who posted it. Files under
// uploads/lesson_attachments/ are never served directly (see the
// .htaccess there), so this is the only way to view one from this portal
// — see learningportal/lesson_attachment.php for the student-side
// equivalent. Reads the on-disk path from the DB row rather than
// trusting anything in the query string, so there's no path-traversal
// surface.
session_name('TEACHER_SESSID');
session_start();
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    exit('Access denied.');
}

$teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);
$lesson_id  = (int) ($_GET['id'] ?? 0);
if ($lesson_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = $conn->prepare("SELECT attachment_path, attachment_original_name FROM lessons WHERE lesson_id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $lesson_id, $teacher_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !$row['attachment_path']) {
    http_response_code(404);
    exit('Not found.');
}

$full_path = __DIR__ . '/../../uploads/lesson_attachments/' . $row['attachment_path'];
if (!is_file($full_path)) {
    http_response_code(404);
    exit('File no longer available.');
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['attachment_original_name']) . '"');
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');
readfile($full_path);
