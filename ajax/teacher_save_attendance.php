<?php
declare(strict_types=1);
// Per-cell autosave for roles/teacher/teacher_attendance.php — one
// P/L/A value per student per actual scheduled class date (session_date).
// No admin-configured date range: a date is valid as long as it falls
// within the selected class's school year span (PH SHS convention —
// June of the start year through May of the end year, same convention
// as config.php's current_real_school_year()).
session_name('TEACHER_SESSID');
session_start();
include('../config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher' || !isset($_SESSION['teacher_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit();
}

$teacher_id   = (int) $_SESSION['teacher_id'];
$student_id   = (int) ($_POST['student_id'] ?? 0);
$subject_id   = (int) ($_POST['subject_id'] ?? 0);
$section_id   = (int) ($_POST['section_id'] ?? 0);
$sy           = trim($_POST['school_year'] ?? '');
$session_date = trim($_POST['session_date'] ?? '');

if ($student_id <= 0 || $subject_id <= 0 || $section_id <= 0 || !preg_match('/^(\d{4})-(\d{4})$/', $sy, $syMatch)) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid data.']);
    exit();
}

$dateObj = DateTime::createFromFormat('Y-m-d', $session_date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $session_date) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid session date.']);
    exit();
}

// The date must fall within this school year's June-May span.
$syStartYear = (int) $syMatch[1];
$syStart = new DateTime("$syStartYear-06-01");
$syEnd   = new DateTime(($syStartYear + 1) . '-05-31');
if ($dateObj < $syStart || $dateObj > $syEnd) {
    echo json_encode(['success' => false, 'error' => 'That date is outside school year ' . $sy . '.']);
    exit();
}

// Scoped to the semester the session date falls in, since a different
// teacher may own the other semester.
$semester = semester_for_date($sy, $session_date);
$own = $conn->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=? AND semester=? AND is_active=1");
$own->bind_param('iiisi', $teacher_id, $subject_id, $section_id, $sy, $semester);
$own->execute();
$isOwn = (bool) $own->get_result()->fetch_row();
$own->close();

if (!$isOwn) {
    echo json_encode(['success' => false, 'error' => 'That class was not found among your assignments.']);
    exit();
}

$ros_stmt = $conn->prepare("SELECT 1 FROM enrollments WHERE section_id=? AND student_id=? AND status='enrolled'");
$ros_stmt->bind_param('ii', $section_id, $student_id);
$ros_stmt->execute();
$isEnrolled = (bool) $ros_stmt->get_result()->fetch_row();
$ros_stmt->close();

if (!$isEnrolled) {
    echo json_encode(['success' => false, 'error' => 'Student is not enrolled in this section.']);
    exit();
}

$val = strtoupper(trim((string) ($_POST['value'] ?? '')));
if ($val !== '' && !in_array($val, ['P', 'L', 'A'], true)) {
    echo json_encode(['success' => false, 'error' => 'Only P, L, or A is allowed.']);
    exit();
}

if ($val === '') {
    $del = $conn->prepare("DELETE FROM gradebook_attendance WHERE subject_id=? AND section_id=? AND school_year=? AND student_id=? AND session_date=?");
    $del->bind_param('iisis', $subject_id, $section_id, $sy, $student_id, $session_date);
    $del->execute();
    $del->close();
    echo json_encode(['success' => true, 'value' => null]);
    exit();
}

$upsert = $conn->prepare("
    INSERT INTO gradebook_attendance (teacher_id, subject_id, section_id, school_year, session_date, student_id, value)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE value = VALUES(value)
");
$upsert->bind_param('iiissis', $teacher_id, $subject_id, $section_id, $sy, $session_date, $student_id, $val);
$upsert->execute();
$upsert->close();

echo json_encode(['success' => true, 'value' => $val]);
