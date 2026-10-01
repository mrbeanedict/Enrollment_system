<?php
declare(strict_types=1);
// Search modal behind the staff top bar's search button
// (shared/includes/staff_sidebar.php).
// GET q → {groups: [{label, items: [{title, sub, url}]}]}, scoped to the
// signed-in staff member's department. Pages in the sidebar nav are matched
// client-side; this only returns records.
//
// Every result links to the page that owns that record, filtered so the
// record is the row that shows: either the page's own ?q= search
// (records, sections, curriculum, teacher_manage via admin_table.js) or,
// for pages whose search box is client-side only (registrar enrollment,
// treasury lists), ?q= is copied into that box by staff_sidebar.php on load.
// The q value is always a field the target page's search actually matches.
//
//   records     → Document Review (pending applicants), Accountabilities
//                 (enrolled, still owing a document)
//   registrar   → Student Directory (admitted, not yet enrolled this cycle)
//   treasury    → Payments (by tab), Online Payments (pending submissions)
//   scheduler   → Sections, Subjects
//   coordinator → Sections, Subjects, Teachers
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!require_login('staff', null, 'ignore', 'bool')) {
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
$per  = 6;
$department = $_SESSION['department'] ?? '';
$staff_url  = APP_URL . '/roles/staff/';
$groups = [];

// Runs one search query; every '?' in $sql is bound to $like.
$fetch = function (string $sql) use ($conn, $like): array {
    $st = $conn->prepare($sql);
    $n  = substr_count($sql, '?');
    if ($n) $st->bind_param(str_repeat('s', $n), ...array_fill(0, $n, $like));
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
};
$add = function (string $label, array $items) use (&$groups): void {
    if ($items) $groups[] = ['label' => $label, 'items' => $items];
};
$name = fn(array $r): string => trim($r['family_name'] . ', ' . $r['given_name'], ', ');
$sub  = fn(string ...$bits): string => implode(' · ', array_filter($bits, fn($b) => $b !== null && $b !== ''));
$grade = fn(?string $g): string => $g ? 'Grade ' . $g : '';
$url  = fn(string $page, array $params): string =>
    $staff_url . $page . '?' . http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));

// Student name / number match — s = students, e = enrollments.
$studentMatch = "(s.family_name LIKE ? OR s.given_name LIKE ?
                  OR CONCAT(s.given_name, ' ', s.family_name) LIKE ?
                  OR CONCAT(s.family_name, ', ', s.given_name) LIKE ?
                  OR s.student_number LIKE ? OR e.control_number LIKE ?)";

if ($department === 'records') {
    $rows = $fetch("
        SELECT s.family_name, s.given_name, e.control_number,
               e.admission_grade_level AS grade_level, strd.strand_code AS strand
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN strands strd ON strd.strand_id = e.admission_strand
        WHERE e.status = 'pending' AND $studentMatch
        ORDER BY s.family_name, s.given_name LIMIT $per
    ");
    $add('Document Review', array_map(fn($r) => [
        'title' => $name($r),
        'sub'   => $sub('Applicant', $r['control_number'], $grade($r['grade_level']), $r['strand']),
        'url'   => $url('records/document_review', ['q' => $r['control_number'] ?: $r['family_name']]),
    ], $rows));

    // Same applicable_reqs > submitted_count rule as records/accountabilities.php.
    $rows = $fetch("
        SELECT s.family_name, s.given_name, e.control_number,
               e.admission_grade_level AS grade_level, strd.strand_code AS strand,
               (SELECT COUNT(*) FROM requirement_types rt
                  WHERE rt.is_active = 1 AND (rt.applicable_to = 'all' OR se.is_public = 1)
               ) AS applicable_reqs,
               (SELECT COUNT(*) FROM enrollment_requirements er
                  JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                 WHERE er.enrollment_id = e.enrollment_id AND er.status = 'submitted' AND rt.is_active = 1
               ) AS submitted_count
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = s.student_id
        LEFT JOIN strands strd ON strd.strand_id = e.admission_strand
        WHERE e.status = 'enrolled' AND $studentMatch
        HAVING submitted_count < applicable_reqs
        ORDER BY s.family_name, s.given_name LIMIT $per
    ");
    $add('Accountabilities', array_map(function ($r) use ($name, $sub, $grade, $url) {
        $owed = (int) $r['applicable_reqs'] - (int) $r['submitted_count'];
        return [
            'title' => $name($r),
            'sub'   => $sub($owed . ' document' . ($owed === 1 ? '' : 's') . ' owed', $r['control_number'], $grade($r['grade_level']), $r['strand']),
            'url'   => $url('records/accountabilities', ['q' => $r['control_number'] ?: $r['family_name']]),
        ];
    }, $rows));

} elseif ($department === 'registrar') {
    // Mirrors registrar/enrollment.php's Step 1 directory: same cycle pick,
    // admitted students, minus anyone already enrolled for that cycle.
    $cy = $conn->query("SELECT school_year FROM school_year_settings
                        ORDER BY is_enrollment_open DESC, is_admission_open DESC, updated_at DESC LIMIT 1");
    $cycle_sy = $cy ? ($cy->fetch_row()[0] ?? null) : null;
    if ($cycle_sy !== null) {
        $sy = $conn->real_escape_string($cycle_sy);
        $rows = $fetch("
            SELECT s.family_name, s.given_name, s.student_number, e.control_number, e.status
            FROM students s
            LEFT JOIN enrollments e ON e.enrollment_id = (
                SELECT e2.enrollment_id FROM enrollments e2
                WHERE e2.student_id = s.student_id AND e2.school_year = '$sy'
                ORDER BY e2.enrollment_id DESC LIMIT 1
            )
            WHERE s.admission_status = 'admitted' AND (e.status IS NULL OR e.status <> 'enrolled')
              AND $studentMatch
            ORDER BY s.family_name, s.given_name LIMIT $per
        ");
        $status_label = ['pre_enrolled' => 'Pre-enrolled', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];
        $add('Student Directory', array_map(fn($r) => [
            'title' => $name($r),
            'sub'   => $sub($r['student_number'] ?: $r['control_number'], $status_label[$r['status']] ?? 'Not yet enrolled'),
            // The directory row's search text is "family, given … <student no. or control no.>".
            'url'   => $url('registrar/enrollment', ['q' => $r['student_number'] ?: ($r['control_number'] ?: $r['family_name'])]),
        ], $rows));
    }

} elseif ($department === 'treasury') {
    // Which enrollments_staff tab the row sits under (same split as that page).
    $rows = $fetch("
        SELECT s.family_name, s.given_name, s.student_number, e.control_number, e.status,
               e.admission_grade_level AS grade_level, e.total_due, COALESCE(pd.paid, 0) AS paid
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN (SELECT enrollment_id, SUM(amount) AS paid FROM payments GROUP BY enrollment_id) pd
               ON pd.enrollment_id = e.enrollment_id
        WHERE $studentMatch
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC LIMIT $per
    ");
    $add('Payments', array_map(function ($r) use ($name, $sub, $grade, $url) {
        $balance = $r['status'] === 'enrolled' && $r['total_due'] !== null && (float) $r['paid'] < (float) $r['total_due'];
        [$tab, $label] = match (true) {
            $r['status'] === 'pre_enrolled' => ['pending',   'Awaiting payment'],
            $balance                        => ['balance',   'Balance due'],
            $r['status'] === 'enrolled'     => ['enrolled',  'Fully paid'],
            $r['status'] === 'expired'      => ['expired',   'Expired'],
            $r['status'] === 'cancelled'    => ['cancelled', 'Cancelled'],
            default                         => ['all',       'With Registrar'],
        };
        return [
            'title' => $name($r),
            'sub'   => $sub($label, $r['student_number'] ?: $r['control_number'], $grade($r['grade_level'])),
            'url'   => $url('treasury/enrollments_staff', ['status' => $tab, 'q' => $r['student_number'] ?: ($r['control_number'] ?: $r['family_name'])]),
        ];
    }, $rows));

    // online_payments.php's search text is "<family>, <given> <student no.>".
    $rows = $fetch("
        SELECT s.family_name, s.given_name, s.student_number, ops.amount, ops.submitted_at
        FROM online_payment_submissions ops
        JOIN enrollments e ON e.enrollment_id = ops.enrollment_id
        JOIN students s ON s.student_id = e.student_id
        WHERE ops.status = 'pending' AND $studentMatch
        ORDER BY ops.submitted_at ASC LIMIT $per
    ");
    $add('Online Payments', array_map(fn($r) => [
        'title' => $name($r),
        'sub'   => $sub('₱' . number_format((float) $r['amount'], 2), 'Submitted ' . date('M j', strtotime($r['submitted_at']))),
        'url'   => $url('treasury/online_payments', ['q' => $r['student_number'] ?: $r['family_name']]),
    ], $rows));

} elseif ($department === 'scheduler' || $department === 'coordinator') {
    $rows = $fetch("
        SELECT sec.section_name, sec.school_year, sec.grade_level, st.strand_code, sec.room, sec.approval_status, sec.is_active
        FROM sections sec JOIN strands st ON st.strand_id = sec.strand
        WHERE sec.section_name LIKE ? OR sec.room LIKE ?
        ORDER BY sec.school_year DESC, sec.grade_level, sec.section_name LIMIT $per
    ");
    $add('Sections', array_map(fn($r) => [
        'title' => $r['section_name'],
        'sub'   => $sub('SY ' . $r['school_year'], $grade($r['grade_level']), $r['strand_code'],
                        $r['room'] ? 'Room ' . $r['room'] : '',
                        $r['approval_status'] !== 'approved' ? ucfirst($r['approval_status']) : (!$r['is_active'] ? 'Inactive' : '')),
        'url'   => $url('scheduler/sections', ['q' => $r['section_name'], 'school_year' => $r['school_year']]),
    ], $rows));

    $rows = $fetch("
        SELECT sub.subject_name, sub.school_year, sub.grade_level, st.strand_code, sub.review_status
        FROM subjects sub JOIN strands st ON st.strand_id = sub.strand
        WHERE sub.subject_name LIKE ?
        ORDER BY sub.school_year DESC, sub.grade_level, sub.subject_name LIMIT $per
    ");
    $review_label = ['for_review' => 'For review', 'rejected' => 'Rejected'];
    $add('Subjects', array_map(fn($r) => [
        'title' => $r['subject_name'],
        'sub'   => $sub('SY ' . $r['school_year'], $grade($r['grade_level']), $r['strand_code'], $review_label[$r['review_status']] ?? ''),
        'url'   => $url('scheduler/curriculum', ['q' => $r['subject_name'], 'school_year' => $r['school_year']]),
    ], $rows));

    if ($department === 'coordinator') {
        // teacher_manage's search text includes username and email.
        $rows = $fetch("
            SELECT t.family_name, t.given_name, t.email, s.strand_code, u.username
            FROM teachers t
            LEFT JOIN strands s ON s.strand_id = t.strand
            LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
            WHERE t.family_name LIKE ? OR t.given_name LIKE ?
               OR CONCAT(t.given_name, ' ', t.family_name) LIKE ?
               OR CONCAT(t.family_name, ', ', t.given_name) LIKE ?
               OR u.username LIKE ? OR t.email LIKE ?
            ORDER BY t.family_name, t.given_name LIMIT $per
        ");
        $add('Teachers', array_map(fn($r) => [
            'title' => $name($r),
            'sub'   => $sub('Teacher', $r['strand_code'], $r['email']),
            'url'   => $url('coordinator/teacher_manage', ['q' => $r['username'] ?: ($r['email'] ?: $r['family_name'])]),
        ], $rows));
    }
}

echo json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE);
