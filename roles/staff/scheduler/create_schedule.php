<?php
/* ============================================================
   create_schedule.php — retired; redirects to Class Scheduling
   ------------------------------------------------------------
   The guided "Create Schedule" page was merged into scheduling.php,
   which now builds a section's whole schedule (timetable + list,
   one transactional save, Semester 2 set-up with "Copy Semester 1
   times"). New sections are created on the Sections page only.

   Kept as a redirect so old bookmarks and notification links still
   land somewhere useful: if the old lookup fields name an existing
   section, it opens that section directly.
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';

require_login();

$semester = (int)($_GET['semester'] ?? 1) === 2 ? 2 : 1;
$target   = 'scheduling?semester=' . $semester;

$strand = trim($_GET['strand'] ?? '');
$grade  = trim($_GET['grade_level'] ?? '');
$sy     = trim($_GET['school_year'] ?? '');
$name   = trim($_GET['section_name'] ?? '');
if ($strand !== '' && $grade !== '' && $sy !== '' && $name !== '') {
    $st = $conn->prepare("
        SELECT sec.section_id FROM sections sec JOIN strands st ON st.strand_id = sec.strand
        WHERE sec.is_active = 1 AND st.strand_code = ? AND sec.grade_level = ? AND sec.school_year = ? AND sec.section_name = ?
        LIMIT 1");
    $st->bind_param('ssss', $strand, $grade, $sy, $name);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) $target .= '&section_id=' . (int)$row['section_id'];
}

header('Location: ' . $target, true, 301);
exit();
