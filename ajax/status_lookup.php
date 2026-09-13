<?php
declare(strict_types=1);
// Public, unauthenticated status-lookup endpoint for the "Check Status"
// box on roles/student/public/index.php. Deliberately single-factor (Student Number or
// control number only) per explicit product decision — accepting that a
// still-pending application (before either value is assigned by staff)
// simply won't be findable yet. To keep at least some anti-enumeration
// hygiene without a second factor, a not-found match and a found-but-
// not-yet-searchable match return the same generic shape/response time,
// and only a minimal, non-identifying subset of fields is ever returned.
include('../config.php');

header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['found' => false]);
    exit();
}

// Honeypot — real visitors never see or fill this (same convention as
// roles/student/public/admission.php's "website" field). Bots get the same generic
// not-found response as any other non-match, never a distinct signal.
if (trim($_POST['website'] ?? '') !== '') {
    echo json_encode(['found' => false]);
    exit();
}

$identifier = trim($_POST['identifier'] ?? '');
if ($identifier === '') {
    echo json_encode(['found' => false]);
    exit();
}

function status_lookup_by_student_number(mysqli $conn, string $studentNumber): ?array
{
    $stmt = $conn->prepare(
        "SELECT student_id, given_name, admission_status FROM students WHERE student_number = ? LIMIT 1"
    );
    $stmt->bind_param('s', $studentNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function status_lookup_by_control_number(mysqli $conn, string $ctrl): ?array
{
    $stmt = $conn->prepare(
        "SELECT st.student_id, st.given_name, st.admission_status
         FROM enrollments e
         JOIN students st ON st.student_id = e.student_id
         WHERE e.control_number = ? LIMIT 1"
    );
    $stmt->bind_param('s', $ctrl);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$looksNumeric = (bool) preg_match('/^\d+$/', $identifier);

if ($looksNumeric) {
    $student = status_lookup_by_student_number($conn, $identifier) ?? status_lookup_by_control_number($conn, $identifier);
} else {
    $student = status_lookup_by_control_number($conn, $identifier) ?? status_lookup_by_student_number($conn, $identifier);
}

if (!$student) {
    echo json_encode(['found' => false]);
    exit();
}

// Most recent enrollment for this student — source of the status label.
$stmt = $conn->prepare(
    "SELECT e.status, e.school_year, e.admission_grade_level AS grade_level, s.strand_code AS strand
     FROM enrollments e
     JOIN strands s ON s.strand_id = e.admission_strand
     WHERE e.student_id = ? ORDER BY e.enrollment_id DESC LIMIT 1"
);
$stmt->bind_param('i', $student['student_id']);
$stmt->execute();
$enrollment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Collapse the raw status vocabulary into a short, non-alarming public
// label — never expose internal detail like a specific rejection reason.
if ($student['admission_status'] !== 'admitted') {
    $label = 'Under review';
} elseif (($enrollment['status'] ?? null) === 'enrolled') {
    $label = 'Enrolled';
} elseif (($enrollment['status'] ?? null) === 'waitlisted') {
    $label = 'Waitlisted';
} elseif (in_array($enrollment['status'] ?? null, ['cancelled', 'withdrawn', 'expired'], true)) {
    $label = 'Not active';
} else {
    $label = 'Admitted — awaiting enrollment';
}

echo json_encode([
    'found'       => true,
    'given_name'  => $student['given_name'],
    'status'      => $label,
    'school_year' => $enrollment['school_year'] ?? null,
    'grade_level' => $enrollment['grade_level'] ?? null,
    'strand'      => $enrollment['strand'] ?? null,
]);
