<?php
// Serves a student-uploaded requirement document to Records staff only —
// files under uploads/requirement_documents/ are never served directly
// (see the .htaccess there), so this is the only way to view one. Reads
// the on-disk path from the DB row rather than trusting anything in the
// query string, so there's no path-traversal surface.
session_start();
include('../config.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

$is_admin = ($_SESSION['role'] ?? '') === 'admin';
if (!$is_admin && ($_SESSION['department'] ?? '') !== 'records') {
    http_response_code(403);
    exit('Access denied.');
}

$enrollment_requirement_id = (int) ($_GET['id'] ?? 0);
if ($enrollment_requirement_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = $conn->prepare("SELECT file_path, original_filename FROM enrollment_requirements WHERE enrollment_requirement_id = ?");
$stmt->bind_param('i', $enrollment_requirement_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !$row['file_path']) {
    http_response_code(404);
    exit('Not found.');
}

// file_path is always a server-generated name under this fixed directory
// (see student_accountabilities.php's upload handler) — never
// user-supplied, so no realpath/traversal check is needed beyond
// confirming the file still exists on disk.
$full_path = __DIR__ . '/../uploads/requirement_documents/' . $row['file_path'];
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
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['original_filename']) . '"');
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');
readfile($full_path);
