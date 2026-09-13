<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['results' => []]);
    exit();
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit();
}

// Student Number + name matches, admitted only.
$stmt = $conn->prepare(
    "SELECT student_number, family_name, given_name, middle_name
     FROM students
     WHERE admission_status = 'admitted'
       AND student_number IS NOT NULL
       AND (student_number LIKE CONCAT(?, '%')
            OR family_name LIKE CONCAT('%', ?, '%')
            OR given_name LIKE CONCAT('%', ?, '%'))
     ORDER BY family_name, given_name
     LIMIT 6"
);
$stmt->bind_param('sss', $q, $q, $q);
$stmt->execute();
$res = $stmt->get_result();
$results = [];
$seen = [];
while ($row = $res->fetch_assoc()) {
    $middleInitial = $row['middle_name'] ? mb_substr($row['middle_name'], 0, 1) . '. ' : '';
    $results[] = [
        'match_value' => $row['student_number'],
        'match_label' => $row['student_number'],
        'name'        => trim($row['given_name'] . ' ' . $middleInitial . $row['family_name']),
    ];
    $seen[$row['student_number']] = true;
}
$stmt->close();

// Control Number matches, admitted only (joined through enrollments).
$stmt = $conn->prepare(
    "SELECT e.control_number, st.student_number, st.family_name, st.given_name, st.middle_name
     FROM enrollments e
     JOIN students st ON st.student_id = e.student_id
     WHERE st.admission_status = 'admitted'
       AND e.control_number LIKE CONCAT('%', ?, '%')
     ORDER BY st.family_name, st.given_name
     LIMIT 6"
);
$stmt->bind_param('s', $q);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    if (isset($seen[$row['student_number']])) {
        continue; // already surfaced via Student Number/name match — don't burn a slot
    }
    $middleInitial = $row['middle_name'] ? mb_substr($row['middle_name'], 0, 1) . '. ' : '';
    $results[] = [
        'match_value' => $row['control_number'],
        'match_label' => $row['control_number'],
        'name'        => trim($row['given_name'] . ' ' . $middleInitial . $row['family_name']),
    ];
    $seen[$row['student_number']] = true;
}
$stmt->close();

$results = array_slice($results, 0, 8);

echo json_encode(['results' => $results]);