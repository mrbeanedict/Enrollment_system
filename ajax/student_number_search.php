<?php
declare(strict_types=1);
session_start();
include('../config.php');
// Sits in ajax/, sibling to enrollment.php — config.php is one level up,
// same relationship as enrollment.php's own include('../config.php') would
// need if enrollment.php were in a subfolder. It isn't (see enrollment.php's
// header comment), but ajax/ genuinely is a subfolder of that directory.

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Not authenticated.']);
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['found' => false, 'error' => 'Database connection unavailable.']);
    exit();
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['found' => false, 'error' => 'Please enter a Student Number or Control Number.']);
    exit();
}

/* ── Resolve the query to a student row ───────────────────────────────
   Student Number: returning students search by this (numeric, up to 12
   digits, system-generated — see registrar/enrollment.php's minting logic).
   Control Number: new students & transferees search by this — it lives
   on `enrollments`, not `students`, so it needs a join. We try Student
   Number first when the input looks numeric, otherwise control number
   first, then fall back to the other so either field works regardless of
   format. */
$looksNumeric = (bool)preg_match('/^\d+$/', $q);

function find_by_student_number(mysqli $conn, string $studentNumber): ?array
{
    $stmt = $conn->prepare(
        "SELECT st.student_id, st.student_number, st.family_name, st.given_name, st.middle_name, st.suffix,
                st.date_of_birth, st.sex, se.school_name AS jhs_school, se.year_graduated AS jhs_year_graduated,
                st.is_transferee, st.admission_status, st.student_type
         FROM students st
         LEFT JOIN student_education se ON se.student_id = st.student_id
         WHERE st.student_number = ? LIMIT 1"
    );
    $stmt->bind_param('s', $studentNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function find_by_control_number(mysqli $conn, string $ctrl): ?array
{
    $stmt = $conn->prepare(
        "SELECT st.student_id, st.student_number, st.family_name, st.given_name, st.middle_name, st.suffix,
                st.date_of_birth, st.sex, se.school_name AS jhs_school, se.year_graduated AS jhs_year_graduated,
                st.is_transferee, st.admission_status, st.student_type
         FROM enrollments e
         JOIN students st ON st.student_id = e.student_id
         LEFT JOIN student_education se ON se.student_id = st.student_id
         WHERE e.control_number = ? LIMIT 1"
    );
    $stmt->bind_param('s', $ctrl);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$student   = null;
$matchedBy = null;

if ($looksNumeric) {
    $student = find_by_student_number($conn, $q);
    $matchedBy = 'student_number';
    if (!$student) {
        $student = find_by_control_number($conn, $q);
        $matchedBy = 'control_number';
    }
} else {
    $student = find_by_control_number($conn, $q);
    $matchedBy = 'control_number';
    if (!$student) {
        $student = find_by_student_number($conn, $q);
        $matchedBy = 'student_number';
    }
}

if (!$student) {
    echo json_encode(['found' => false]);
    exit();
}

// Only admitted (admission-complete) students are searchable here.
if ($student['admission_status'] !== 'admitted') {
    echo json_encode([
        'found'            => true,
        'admission_status' => $student['admission_status'],
        'name'             => trim(
            $student['given_name'] . ' '
            . ($student['middle_name'] ? $student['middle_name'] . ' ' : '')
            . $student['family_name']
            . ($student['suffix'] ? ' ' . $student['suffix'] : '')
        ),
    ]);
    exit();
}

$name = trim(
    $student['given_name'] . ' '
    . ($student['middle_name'] ? $student['middle_name'] . ' ' : '')
    . $student['family_name']
    . ($student['suffix'] ? ' ' . $student['suffix'] : '')
);

// Most recent enrollment ever (any cycle) — source of "current" grade/strand
// and the academic-status placeholder below.
$stmt = $conn->prepare(
    "SELECT e.admission_grade_level AS grade_level, st.strand_code AS strand, e.status, e.academic_status
     FROM enrollments e
     JOIN strands st ON st.strand_id = e.admission_strand
     WHERE e.student_id = ? ORDER BY e.enrollment_id DESC LIMIT 1"
);
$stmt->bind_param('i', $student['student_id']);
$stmt->execute();
$lastEnrollment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isNewStudent = ($lastEnrollment === null);

// Real academic_status column (in_progress/passed/failed) — null when the
// student has no prior enrollment in this system at all (new/transferee).
$academicStatusRaw = $lastEnrollment['academic_status'] ?? null;
$academicStatus = match ($academicStatusRaw) {
    'passed'  => 'Passed',
    'failed'  => 'Failed',
    'in_progress' => 'In Progress',
    default   => 'New Enrollee',
};

// Current cycle — needed to check for an existing active-this-year row.
$cycleRow = $conn->query(
    "SELECT school_year FROM school_year_settings
     ORDER BY is_enrollment_open DESC, is_admission_open DESC, updated_at DESC
     LIMIT 1"
)->fetch_assoc();

$hasActiveEnrollment = false;
$existingStatus      = null;
$pendingEnrollmentId = null;

if ($cycleRow) {
    $stmt = $conn->prepare(
        "SELECT enrollment_id, status FROM enrollments
         WHERE student_id = ? AND school_year = ? AND active_lock = 1
         LIMIT 1"
    );
    $stmt->bind_param('is', $student['student_id'], $cycleRow['school_year']);
    $stmt->execute();
    $activeRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($activeRow) {
        $hasActiveEnrollment = true;
        $existingStatus = $activeRow['status'];
        if ($activeRow['status'] === 'pending') {
            $pendingEnrollmentId = (int)$activeRow['enrollment_id'];
        }
    }
}

echo json_encode([
    'found'                 => true,
    'student_id'            => (int)$student['student_id'],
    'matched_by'            => $matchedBy,
    'matched_value'         => $matchedBy === 'student_number' ? $student['student_number'] : $q,
    'name'                  => $name,
    'admission_status'      => $student['admission_status'],
    'student_type'          => $student['student_type'],
    'is_new_student'        => $isNewStudent,
    'is_transferee'         => (bool)$student['is_transferee'],
    'grade_level'           => $lastEnrollment['grade_level'] ?? null,
    'strand'                => $lastEnrollment['strand'] ?? null,
    'academic_status'       => $academicStatus,
    'academic_status_raw'   => $academicStatusRaw,
    'date_of_birth'         => $student['date_of_birth'],
    'sex'                   => $student['sex'],
    'jhs_school'            => $student['jhs_school'],
    'jhs_year_graduated'    => $student['jhs_year_graduated'],
    'has_active_enrollment' => $hasActiveEnrollment,
    'existing_status'       => $existingStatus,
    'pending_enrollment_id' => $pendingEnrollmentId,
]);