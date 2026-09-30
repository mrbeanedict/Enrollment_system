<?php
// Assignment submissions — shared by the student side
// (roles/lms/student_assignments.php, submission_attachment.php) and the
// teacher side (roles/teacher/teacher_gradebook.php, submission_attachment.php).
// See schema/submission_turn_in.sql for the draft / turned_in model.

const SUB_ALLOWED_EXT  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip'];
const SUB_MAX_FILES    = 3;                  // per assignment
const SUB_MAX_BYTES    = 10 * 1024 * 1024;   // 10 MB for all of an assignment's files together
const SUB_COMMENT_MAX  = 2000;               // characters per private comment

function sub_upload_dir(): string {
    return BASE_PATH . '/uploads/gradebook_submissions/';
}

function sub_format_bytes(int $b): string {
    if ($b <= 0) return '0 MB';
    if ($b >= 1024 * 1024) return rtrim(rtrim(number_format($b / (1024 * 1024), 1), '0'), '.') . ' MB';
    return max(1, (int) round($b / 1024)) . ' KB';
}

// On-disk size, for files moved in by the migration (file_size = 0).
function sub_file_size(array $f): int {
    if ((int) $f['file_size'] > 0) return (int) $f['file_size'];
    $p = sub_upload_dir() . $f['file_path'];
    return is_file($p) ? (int) filesize($p) : 0;
}

// Files for several submissions at once, grouped by submission_id.
function sub_files_by_submission(mysqli $conn, array $submission_ids): array {
    $ids = array_values(array_filter(array_map('intval', $submission_ids)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT file_id, submission_id, file_path, original_filename, file_size, uploaded_at
                            FROM gradebook_submission_files WHERE submission_id IN ($ph) ORDER BY file_id");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $out[(int) $f['submission_id']][] = $f;
    $stmt->close();
    return $out;
}

// Private comment threads for one assignment, grouped by student_id
// (pass a single student to get just theirs). Oldest first.
function sub_comments(mysqli $conn, int $item_id, ?int $student_id = null): array {
    $sql = "SELECT sc.comment_id, sc.student_id, sc.author_type, sc.body, sc.created_at,
                   CASE WHEN sc.author_type = 'teacher' THEN CONCAT(t.given_name, ' ', t.family_name)
                        ELSE CONCAT(s.given_name, ' ', s.family_name) END AS author_name
            FROM submission_comments sc
            LEFT JOIN teachers t ON t.teacher_id = sc.author_teacher_id
            LEFT JOIN students s ON s.student_id = sc.student_id
            WHERE sc.item_id = ?" . ($student_id !== null ? " AND sc.student_id = ?" : "") . "
            ORDER BY sc.created_at, sc.comment_id";
    $stmt = $conn->prepare($sql);
    if ($student_id !== null) $stmt->bind_param('ii', $item_id, $student_id);
    else $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) $out[(int) $c['student_id']][] = $c;
    $stmt->close();
    return $out;
}

function sub_add_comment(mysqli $conn, int $item_id, int $student_id, string $author_type, ?int $teacher_id, string $body): bool {
    $stmt = $conn->prepare("INSERT INTO submission_comments (item_id, student_id, author_type, author_teacher_id, body) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisis', $item_id, $student_id, $author_type, $teacher_id, $body);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Whether the teacher has already recorded a score — graded work can't be
// unsubmitted or changed out from under that score.
function sub_is_graded(mysqli $conn, int $item_id, int $student_id): bool {
    $gr = $conn->prepare("SELECT 1 FROM gradebook_scores WHERE item_id=? AND student_id=? AND raw_score IS NOT NULL");
    $gr->bind_param('ii', $item_id, $student_id);
    $gr->execute();
    $graded = (bool) $gr->get_result()->fetch_row();
    $gr->close();
    return $graded;
}
