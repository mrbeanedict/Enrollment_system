<?php
// Serves a student's submitted file to the teacher who owns that item's
// class. Files under uploads/gradebook_submissions/ are never served
// directly (see the .htaccess there) — see
// learningportal/submission_attachment.php for the student-side
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
$item_id    = (int) ($_GET['item_id'] ?? 0);
$student_id = (int) ($_GET['student_id'] ?? 0);
if ($item_id <= 0 || $student_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = $conn->prepare("
    SELECT gsub.file_path, gsub.original_filename
    FROM gradebook_submissions gsub
    JOIN gradebook_items gi ON gi.item_id = gsub.item_id
    WHERE gsub.item_id = ? AND gsub.student_id = ? AND gi.teacher_id = ?
");
$stmt->bind_param('iii', $item_id, $student_id, $teacher_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Not found.');
}

$full_path = __DIR__ . '/../../uploads/gradebook_submissions/' . $row['file_path'];
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
    'zip'  => 'application/zip',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['original_filename']) . '"');
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');
readfile($full_path);
