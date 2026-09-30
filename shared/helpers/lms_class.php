<?php
// Student-side helpers for one LMS class (subject + section + school year):
// who is in it, and posting into its discussion threads. Shared by
// roles/lms/student_discussions.php and roles/lms/student_stream.php — a
// class announcement is a discussion thread with kind = 'announcement'
// (see schema/discussion_thread_kind.sql), so commenting on one is the
// exact same reply as in Discussions, with the same checks.

// Who "takes" a class: enrollment_subjects is per subject (so back subjects /
// irregular loads land in the right class, and a subject left out of a
// student's load isn't theirs even if their section offers it); an
// enrollment with no enrollment_subjects rows at all takes every subject of
// its own section. The same rule as the teacher side's notification targets
// (teacher_discussions.php's disc_student_targets()). Expects enrollments
// aliased `e`; binds (subject_id, section_id, section_id, subject_id).
const LMS_TAKES_CLASS_SQL = "(
        EXISTS (SELECT 1 FROM enrollment_subjects es
                WHERE es.enrollment_id = e.enrollment_id AND es.subject_id = ? AND es.section_id = ?)
     OR (e.section_id = ?
         AND NOT EXISTS (SELECT 1 FROM enrollment_subjects es2 WHERE es2.enrollment_id = e.enrollment_id)
         AND EXISTS (SELECT 1 FROM section_subjects ss WHERE ss.section_id = e.section_id AND ss.subject_id = ?))
)";

// Confirm the student is currently enrolled in this exact subject+section+
// school_year — never trust the posted values blindly.
function disc_student_owns_class(mysqli $conn, int $student_id, int $subject_id, int $section_id, string $sy): bool {
    $stmt = $conn->prepare("
        SELECT 1 FROM enrollments e
        WHERE e.student_id = ? AND e.school_year = ? AND e.status = 'enrolled' AND " . LMS_TAKES_CLASS_SQL . "
        LIMIT 1
    ");
    $stmt->bind_param('isiiii', $student_id, $sy, $subject_id, $section_id, $section_id, $subject_id);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

// Every teacher currently assigned to this exact subject/section/school
// year — not the thread's own created_by_teacher_id, which is null for
// student-started threads.
function disc_teacher_targets(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
    $stmt = $conn->prepare("
        SELECT t.user_id FROM teacher_assignments ta
        JOIN teachers t ON t.teacher_id = ta.teacher_id
        WHERE ta.subject_id = ? AND ta.section_id = ? AND ta.school_year = ? AND ta.is_active = 1
    ");
    $stmt->bind_param('iis', $subject_id, $section_id, $sy);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
    $stmt->close();
    return $ids;
}

// Teachers of the class (People tab / Stream header). teacher_assignments is
// what the teacher portal reads; section_subjects.teacher_id (what My Courses
// shows) is the fallback for a class with no assignment row yet.
function lms_class_teachers(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.teacher_id, t.given_name, t.family_name
        FROM teacher_assignments ta
        JOIN teachers t ON t.teacher_id = ta.teacher_id
        WHERE ta.subject_id = ? AND ta.section_id = ? AND ta.school_year = ? AND ta.is_active = 1
        ORDER BY t.family_name, t.given_name
    ");
    $stmt->bind_param('iis', $subject_id, $section_id, $sy);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows) return $rows;

    $stmt = $conn->prepare("
        SELECT DISTINCT t.teacher_id, t.given_name, t.family_name
        FROM section_subjects ss
        JOIN teachers t ON t.teacher_id = ss.teacher_id
        WHERE ss.subject_id = ? AND ss.section_id = ?
        ORDER BY t.family_name, t.given_name
    ");
    $stmt->bind_param('ii', $subject_id, $section_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Enrolled students taking this subject in this section (LMS_TAKES_CLASS_SQL).
function lms_class_students(mysqli $conn, int $subject_id, int $section_id, string $sy): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT s.student_id, s.given_name, s.family_name
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.school_year = ? AND e.status = 'enrolled' AND " . LMS_TAKES_CLASS_SQL . "
        ORDER BY s.family_name, s.given_name
    ");
    $stmt->bind_param('siiii', $sy, $subject_id, $section_id, $section_id, $subject_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Reply to (or comment on) a thread in the student's own class.
// Returns [bool ok, string message].
function disc_student_reply(mysqli $conn, int $student_id, int $thread_id, int $subject_id, int $section_id, string $sy, string $body): array {
    $chk = $conn->prepare("SELECT title, kind, is_locked FROM discussion_threads WHERE thread_id=? AND subject_id=? AND section_id=? AND school_year=?");
    $chk->bind_param('iiis', $thread_id, $subject_id, $section_id, $sy);
    $chk->execute();
    $thread = $chk->get_result()->fetch_assoc();
    $chk->close();

    $is_ann = $thread && $thread['kind'] === 'announcement';
    if (!$thread || !disc_student_owns_class($conn, $student_id, $subject_id, $section_id, $sy)) {
        return [false, 'That ' . ($is_ann ? 'announcement' : 'thread') . ' was not found among your subjects.'];
    }
    if ((int) $thread['is_locked'] === 1) {
        return [false, $is_ann ? 'Comments are turned off for this announcement.' : 'This thread is locked.'];
    }
    if ($body === '') {
        return [false, $is_ann ? 'Please write a comment.' : 'Please write a reply.'];
    }

    $ins = $conn->prepare("INSERT INTO discussion_posts (thread_id, posted_by_type, posted_by_student_id, body) VALUES (?, 'student', ?, ?)");
    $ins->bind_param('iis', $thread_id, $student_id, $body);
    $ins->execute();
    $ins->close();

    notify_teacher_users(
        $conn,
        disc_teacher_targets($conn, $subject_id, $section_id, $sy),
        $is_ann ? "New comment on your announcement \"{$thread['title']}\"." : 'New reply in a discussion thread.',
        'roles/teacher/teacher_discussions?' . http_build_query(['subject_id' => $subject_id, 'section_id' => $section_id, 'sy' => $sy, 'thread_id' => $thread_id])
    );
    return [true, $is_ann ? 'Comment posted.' : 'Reply posted.'];
}

// Students may only delete their own posts (teachers moderate the rest).
function disc_student_delete_post(mysqli $conn, int $student_id, int $post_id, int $thread_id): bool {
    $del = $conn->prepare("DELETE FROM discussion_posts WHERE post_id=? AND thread_id=? AND posted_by_type='student' AND posted_by_student_id=?");
    $del->bind_param('iii', $post_id, $thread_id, $student_id);
    $del->execute();
    $ok = $del->affected_rows > 0;
    $del->close();
    return $ok;
}

// Posts for several threads at once, oldest first, grouped by thread_id.
function disc_posts_by_thread(mysqli $conn, array $thread_ids): array {
    $thread_ids = array_values(array_filter(array_map('intval', $thread_ids)));
    if (!$thread_ids) return [];
    $ph = implode(',', array_fill(0, count($thread_ids), '?'));
    $stmt = $conn->prepare("
        SELECT dp.post_id, dp.thread_id, dp.posted_by_type, dp.posted_by_teacher_id, dp.posted_by_student_id, dp.body, dp.posted_at,
               CASE WHEN dp.posted_by_type='teacher' THEN CONCAT(t.given_name,' ',t.family_name) ELSE CONCAT(s.given_name,' ',s.family_name) END AS author_name
        FROM discussion_posts dp
        LEFT JOIN teachers t ON t.teacher_id = dp.posted_by_teacher_id
        LEFT JOIN students s ON s.student_id = dp.posted_by_student_id
        WHERE dp.thread_id IN ($ph)
        ORDER BY dp.posted_at ASC, dp.post_id ASC
    ");
    $stmt->bind_param(str_repeat('i', count($thread_ids)), ...$thread_ids);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) $out[(int) $p['thread_id']][] = $p;
    $stmt->close();
    return $out;
}

// Two-letter initials for the avatar circles.
function lms_initials(string $given, string $family): string {
    return strtoupper(mb_substr(trim($given), 0, 1) . mb_substr(trim($family), 0, 1));
}
