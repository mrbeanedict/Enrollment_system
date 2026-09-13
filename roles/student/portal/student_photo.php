<?php
// Streams the logged-in student's own profile photo. Always serves "my
// own current photo" — no id/path parameter accepted from the client —
// so there's no path-traversal or cross-student access surface at all.
// Files under uploads/profile_photos/ are never served directly (see
// the .htaccess there), same convention as records/view_document.php.
// Reachable from both the Student Portal and the Student LMS (each a
// distinct session) — same explicit `portal` signal as
// student_profile.php, since this endpoint has no single fixed home.
$is_lms_mode = ($_GET['portal'] ?? '') === 'lms';
session_name($is_lms_mode ? 'STUDENT_LMS_SESSID' : 'STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

$student_id = (int) ($_SESSION['student_id'] ?? 0);

$stmt = $conn->prepare("SELECT photo_path FROM users_student WHERE student_id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !$row['photo_path']) {
    http_response_code(404);
    exit('No photo set.');
}

// photo_path is always a server-generated name under this fixed
// directory (see the upload handler in student_profile.php) — never
// user-supplied, so no realpath/traversal check is needed beyond
// confirming the file still exists on disk.
$full_path = __DIR__ . '/../../../uploads/profile_photos/' . $row['photo_path'];
if (!is_file($full_path)) {
    http_response_code(404);
    exit('File no longer available.');
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['photo_path']) . '"');
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($full_path);
