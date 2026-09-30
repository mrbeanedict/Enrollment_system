<?php
// Shared bits for showing gradebook tasks in the Student LMS (lms_home.php,
// my_courses.php, student_assignments.php, student_quizzes.php,
// student_stream.php) so a task reads the same on every page.

// Compact due label: "Overdue", "Today", "Tomorrow", a weekday name within
// the next 6 days, otherwise "Oct 6". Calendar days, not 24-hour blocks, so
// something due tonight still reads "Today".
function lms_due_short(string $due): string {
    $ts   = strtotime($due);
    $days = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d'))) / 86400);
    if ($ts < time()) return 'Overdue';
    if ($days <= 0) return 'Today';
    if ($days === 1) return 'Tomorrow';
    if ($days <= 6) return date('l', $ts);
    return date('M j', $ts);
}

// Display kind of a gradebook item — a quarterly assessment shows as an
// Exam however it's delivered (same grouping as helpers/grading.php).
function lms_task_kind(array $item): string {
    if (($item['category'] ?? '') === 'quarterly_assessment') return 'Exam';
    return !empty($item['is_quiz']) ? 'Quiz' : 'Assignment';
}

// CSS hook for the kind colours in css_lms.css (.k-exam / .k-quiz / .k-assignment).
function lms_kind_class(string $kind): string {
    return 'k-' . strtolower($kind);
}

// Pill modifier for a due label: urgent (Today/Overdue) reads red.
function lms_due_tone(string $label): string {
    return $label === 'Overdue' ? ' is-overdue' : ($label === 'Today' ? ' is-today' : '');
}
