<?php
declare(strict_types=1);
// Search box in the Student LMS top bar (shared/includes/lms_navbar.php).
// GET q → {groups: [{label, items: [{title, sub, url}]}]} over the student's
// current section + school year: their courses, lessons, assignments and
// quizzes. Title matches only, a few per group.
//
// Quiz results open the subject's quiz list, never take_quiz directly —
// opening a quiz starts its timer, which a search click shouldn't do.
session_name('STUDENT_LMS_SESSID');
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!require_login('student', null, 'ignore', 'bool') || !isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['groups' => []]);
    exit();
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['groups' => []]);
    exit();
}
$q    = mb_substr($q, 0, 60);
$like = '%' . addcslashes($q, '%_\\') . '%';
$student_id = (int) $_SESSION['student_id'];
$per  = 5;

// Same "most recent enrollment on file" convention as every LMS page.
$st = $conn->prepare("SELECT section_id, school_year FROM enrollments WHERE student_id = ? ORDER BY enrollment_date DESC, enrollment_id DESC LIMIT 1");
$st->bind_param('i', $student_id);
$st->execute();
$enr = $st->get_result()->fetch_assoc();
$st->close();
if (!$enr || !$enr['section_id']) {
    echo json_encode(['groups' => []]);
    exit();
}
$sec = (int) $enr['section_id'];
$sy  = $enr['school_year'];

// Lessons / Assignments / Quizzes pages filter by semester, so each link
// carries the semester that subject is offered in (first one if both).
$semBySubject = [];
$st = $conn->prepare("SELECT subject_id, MIN(semester) AS sem FROM section_subjects WHERE section_id = ? GROUP BY subject_id");
$st->bind_param('i', $sec);
$st->execute();
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $semBySubject[(int) $r['subject_id']] = (int) $r['sem'];
$st->close();

$qs = function (int $subject_id, bool $withSem = true) use ($sy, $semBySubject): string {
    $p = ['subject_id' => $subject_id, 'sy' => $sy];
    if ($withSem) $p['sem'] = $semBySubject[$subject_id] ?? 1;
    return http_build_query($p);
};
$base = APP_URL . '/roles/lms/';
$when = fn(?string $ts) => $ts ? date('M j', strtotime($ts)) : null;
$groups = [];

// Courses
$st = $conn->prepare("
    SELECT DISTINCT sub.subject_id, sub.subject_name
    FROM section_subjects ss JOIN subjects sub ON sub.subject_id = ss.subject_id
    WHERE ss.section_id = ? AND sub.subject_name LIKE ?
    ORDER BY sub.subject_name LIMIT $per
");
$st->bind_param('is', $sec, $like);
$st->execute();
$items = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $items[] = ['title' => $r['subject_name'], 'sub' => 'Course', 'url' => $base . 'student_stream?' . $qs((int) $r['subject_id'], false)];
}
$st->close();
if ($items) $groups[] = ['label' => 'Courses', 'items' => $items];

// Lessons
$st = $conn->prepare("
    SELECT l.lesson_id, l.title, l.posted_at, sub.subject_id, sub.subject_name
    FROM lessons l JOIN subjects sub ON sub.subject_id = l.subject_id
    WHERE l.section_id = ? AND l.school_year = ? AND l.title LIKE ?
    ORDER BY l.posted_at DESC LIMIT $per
");
$st->bind_param('iss', $sec, $sy, $like);
$st->execute();
$items = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $items[] = ['title' => $r['title'], 'sub' => $r['subject_name'] . ' · ' . $when($r['posted_at']),
                'url' => $base . 'student_lessons?' . $qs((int) $r['subject_id']) . '#lesson-' . (int) $r['lesson_id']];
}
$st->close();
if ($items) $groups[] = ['label' => 'Lessons', 'items' => $items];

// Assignments and quizzes (both are gradebook items)
foreach ([['Assignments', 'gi.accepts_submission = 1'], ['Quizzes', 'gi.is_quiz = 1']] as [$label, $cond]) {
    $st = $conn->prepare("
        SELECT gi.item_id, gi.title, gi.due_date, sub.subject_id, sub.subject_name
        FROM gradebook_items gi JOIN subjects sub ON sub.subject_id = gi.subject_id
        WHERE gi.section_id = ? AND gi.school_year = ? AND $cond AND gi.title LIKE ?
        ORDER BY gi.due_date IS NULL, gi.due_date DESC LIMIT $per
    ");
    $st->bind_param('iss', $sec, $sy, $like);
    $st->execute();
    $items = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $sid = (int) $r['subject_id'];
        $url = $label === 'Quizzes'
            ? $base . 'student_quizzes?' . $qs($sid)
            : $base . 'student_assignments?' . $qs($sid) . '#as-item-' . (int) $r['item_id'];
        $items[] = ['title' => $r['title'], 'sub' => $r['subject_name'] . ($r['due_date'] ? ' · Due ' . $when($r['due_date']) : ''), 'url' => $url];
    }
    $st->close();
    if ($items) $groups[] = ['label' => $label, 'items' => $items];
}

echo json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE);
