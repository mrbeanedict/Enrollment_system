<?php
// Serves a lesson attachment to a student currently enrolled in the
// lesson's section. Files under uploads/lesson_attachments/ are never
// served directly (see the .htaccess there), so this is the only way to
// view one from this portal — see teacherportal/lesson_attachment.php for
// the teacher-side equivalent. Reads the on-disk path from the DB row
// rather than trusting anything in the query string, so there's no
// path-traversal surface.
// Lesson attachments are only ever linked from the Student LMS (see
// lms_sidebar.php / student_lessons.php) — always use that session,
// fixed, rather than guessing between STUDENT_SESSID and
// STUDENT_LMS_SESSID.
session_name('STUDENT_LMS_SESSID');
session_start();
include_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

$student_id = (int) ($_SESSION['student_id'] ?? 0);
$lesson_id  = (int) ($_GET['id'] ?? 0);
if ($lesson_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

// Only serve the file if this student currently has an 'enrolled'
// enrollment in the exact section+school_year this lesson was posted to —
// not just "was ever enrolled there," and not any other student's section.
$stmt = $conn->prepare("
    SELECT l.attachment_path, l.attachment_original_name
    FROM lessons l
    JOIN enrollments e ON e.section_id = l.section_id AND e.school_year = l.school_year
    WHERE l.lesson_id = ? AND e.student_id = ? AND e.status = 'enrolled'
    LIMIT 1
");
$stmt->bind_param('ii', $lesson_id, $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !$row['attachment_path']) {
    http_response_code(404);
    exit('Not found.');
}

$full_path = __DIR__ . '/../uploads/lesson_attachments/' . $row['attachment_path'];
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
