<?php
// Requires: $conn open (mysqli). Include after config.php.

// Shared insert used by every notify_* helper below. $recipient_type scopes
// the row to the right portal's session/user-id space — staff/teacher share
// the `users` table (their user_id values could theoretically collide with
// a student's user_student_id), students don't live in `users` at all, so
// every query against this table must always filter on both columns
// together, never user_id alone.
function notify_insert(mysqli $conn, string $recipient_type, array $ids, string $message, string $link): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return;

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, recipient_type, message, link) VALUES (?, ?, ?, ?)");
    foreach ($ids as $id) {
        $stmt->bind_param('isss', $id, $recipient_type, $message, $link);
        $stmt->execute();
    }
    $stmt->close();
}

function notify_users(mysqli $conn, array $user_ids, string $message, string $link): void {
    notify_insert($conn, 'staff', $user_ids, $message, $link);
}

// $teacher_user_ids are teachers.user_id values (nullable — a teacher with
// no portal account yet has none; notify_insert()'s array_filter already
// silently drops any null/zero entries).
function notify_teacher_users(mysqli $conn, array $teacher_user_ids, string $message, string $link): void {
    notify_insert($conn, 'teacher', $teacher_user_ids, $message, $link);
}

// $user_student_ids are users_student.user_student_id values.
function notify_student_users(mysqli $conn, array $user_student_ids, string $message, string $link): void {
    notify_insert($conn, 'student', $user_student_ids, $message, $link);
}

function notify_coordinators(mysqli $conn, string $message, string $link): void {
    $ids = [];
    $deptId = department_id($conn, 'coordinator');
    $res = mysqli_query($conn, "SELECT user_id FROM staff WHERE department_id = " . (int)$deptId . " AND is_active = 1");
    if ($res) {
        while ($row = mysqli_fetch_row($res)) { $ids[] = (int)$row[0]; }
    }
    notify_users($conn, $ids, $message, $link);
}
