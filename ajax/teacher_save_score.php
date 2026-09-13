<?php
declare(strict_types=1);
// Per-cell autosave for roles/teacher/teacher_grade_management.php's
// Quiz/Seatwork columns — each column is a real gradebook_items row
// (the same items Assessment creates/manages), so this saves into
// gradebook_scores, the exact same table Assessment's now-removed
// score grid used to write to.
session_name('TEACHER_SESSID');
session_start();
include('../config.php');
include('../notify.php');

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher' || !isset($_SESSION['teacher_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit();
}

$teacher_id = (int) $_SESSION['teacher_id'];
$item_id    = (int) ($_POST['item_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);
$subject_id = (int) ($_POST['subject_id'] ?? 0);
$section_id = (int) ($_POST['section_id'] ?? 0);
$sy         = trim($_POST['school_year'] ?? '');
$quarter    = $_POST['quarter'] ?? '';
$valid_quarters = ['1', '2', '3', '4'];

if ($item_id <= 0 || $student_id <= 0 || $subject_id <= 0 || $section_id <= 0 || $sy === '' || !in_array($quarter, $valid_quarters, true)) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid data.']);
    exit();
}

// Never trust the posted class pair blindly — same ownership guard
// used throughout the teacher portal. Scoped to the semester the quarter
// falls in, since a different teacher may own the other semester.
$semester = quarter_to_semester($quarter);
$own = $conn->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? AND section_id=? AND school_year=? AND semester=? AND is_active=1");
$own->bind_param('iiisi', $teacher_id, $subject_id, $section_id, $sy, $semester);
$own->execute();
$isOwn = (bool) $own->get_result()->fetch_row();
$own->close();

if (!$isOwn) {
    echo json_encode(['success' => false, 'error' => 'That class was not found among your assignments.']);
    exit();
}

// The item must actually belong to this class+quarter+teacher.
$it_stmt = $conn->prepare("SELECT max_score, is_quiz, title FROM gradebook_items WHERE item_id=? AND subject_id=? AND section_id=? AND school_year=? AND quarter=? AND teacher_id=?");
$it_stmt->bind_param('iiissi', $item_id, $subject_id, $section_id, $sy, $quarter, $teacher_id);
$it_stmt->execute();
$item = $it_stmt->get_result()->fetch_assoc();
$it_stmt->close();

if (!$item) {
    echo json_encode(['success' => false, 'error' => 'Item not found among your assignments.']);
    exit();
}
$max = (float) $item['max_score'];

// The student must actually be enrolled in this section.
$ros_stmt = $conn->prepare("SELECT 1 FROM enrollments WHERE section_id=? AND student_id=? AND status='enrolled'");
$ros_stmt->bind_param('ii', $section_id, $student_id);
$ros_stmt->execute();
$isEnrolled = (bool) $ros_stmt->get_result()->fetch_row();
$ros_stmt->close();

if (!$isEnrolled) {
    echo json_encode(['success' => false, 'error' => 'Student is not enrolled in this section.']);
    exit();
}

$val = trim((string) ($_POST['value'] ?? ''));

if ($val === '') {
    $del = $conn->prepare("DELETE FROM gradebook_scores WHERE item_id=? AND student_id=?");
    $del->bind_param('ii', $item_id, $student_id);
    $del->execute();
    $del->close();
    $score = null;
} else {
    $score = (float) $val;
    if ($score < 0) $score = 0;
    if ($score > $max) $score = $max;

    $upsert = $conn->prepare("
        INSERT INTO gradebook_scores (item_id, student_id, raw_score, graded_by, graded_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score), graded_by = VALUES(graded_by), graded_at = NOW()
    ");
    $upsert->bind_param('iidi', $item_id, $student_id, $score, $teacher_id);
    $upsert->execute();
    $upsert->close();

    // Only notify for an actual quiz score, not every seatwork/performance-
    // task cell this same endpoint also autosaves.
    if (!empty($item['is_quiz'])) {
        $usid_stmt = $conn->prepare("SELECT user_student_id FROM users_student WHERE student_id = ?");
        $usid_stmt->bind_param('i', $student_id);
        $usid_stmt->execute();
        $usid_row = $usid_stmt->get_result()->fetch_assoc();
        $usid_stmt->close();
        notify_student_users(
            $conn,
            [(int) ($usid_row['user_student_id'] ?? 0)],
            'Your quiz "' . $item['title'] . '" has been graded.',
            'roles/lms/student_quizzes'
        );
    }
}

$grade = grade_management_grade($conn, $student_id, $subject_id, $section_id, $sy, $quarter);

echo json_encode([
    'success' => true,
    'value'   => $score,
    'total'   => $grade['total'],
    'remarks' => $grade['remarks'],
]);
