<?php
// Serves a student's submitted file to the teacher who owns that item's
// class. Files under uploads/gradebook_submissions/ are never served
// directly (see the .htaccess there) — see
// roles/lms/submission_attachment.php for the student-side
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
$file_id    = (int) ($_GET['file_id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = $conn->prepare("
    SELECT f.file_path, f.original_filename
    FROM gradebook_submission_files f
    JOIN gradebook_submissions gsub ON gsub.submission_id = f.submission_id
    JOIN gradebook_items gi ON gi.item_id = gsub.item_id
    WHERE f.file_id = ? AND gi.teacher_id = ? AND gsub.status = 'turned_in'
");
// Turned-in work only — a student's draft files stay private until they turn in.
$stmt->bind_param('ii', $file_id, $teacher_id);
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
