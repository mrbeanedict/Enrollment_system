<?php
// Streams a teacher's profile photo for the coordinator/admin directory
// (roles/staff/coordinator/teacher_manage.php). Unlike roles/teacher/teacher_photo.php
// (which only ever serves the logged-in teacher's own photo), this one takes
// a teacher_id — so it's gated to reviewer roles instead of "is this you".
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

require_login();

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$is_reviewer = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');
if (!$is_reviewer) {
    http_response_code(403);
    exit('Access denied.');
}

$teacher_id = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT u.photo_path FROM teachers t JOIN users u ON u.user_id = t.user_id WHERE t.teacher_id = ?");
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !$row['photo_path']) {
    http_response_code(404);
    exit('No photo set.');
}

// photo_path is always a server-generated name under this fixed directory
// (see the upload handler in teacher_manage.php) — never user-supplied, so
// no realpath/traversal check is needed beyond confirming the file exists.
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
