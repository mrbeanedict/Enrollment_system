<?php
/* ============================================================
   sections.php — Coordinator (Staff) Section Management
   ------------------------------------------------------------
   ASSUMPTIONS / FOLLOW-UPS — please confirm or adjust:

   1. Run sections_approval_migration.sql first. It adds
      approval_status, requested_by, reviewed_by, reviewed_at,
      rejection_reason to `sections`.

   2. Access: staff with department='scheduler' can view this page
      and submit new sections (which land as 'pending'). role='admin'
      and staff with department='coordinator' are reviewers — they
      get Approve / Reject controls on pending rows.

   3. populate_section_subjects() (teachers/schedule/subjects) now
      runs on APPROVAL, not on creation. A pending section has no
      subjects yet — "Generate Subjects" only appears once approved.

   4. Deactivate / Reactivate / Generate-Subjects are NOT gated by
      the approval system — only brand-new section creation is.
      Both coordinator and admin can use them.

   5. staff_sidebar.php is assumed to render
      <aside class="staff-sidebar" id="staffSidebar">…</aside>
      as a sibling before .staff-main (per css_staff.css's
      `.staff-sidebar.open ~ .staff-main` rule), and to expose the
      logged-in staff member's session data itself.

   6. $_SESSION is assumed to carry: 'role' ('admin'|'staff'),
      'department' (for staff), and 'user_id'.
   ============================================================ */

session_start();
// Buffer all output (including anything staff_sidebar.php prints once it's
// included further down, inside the HTML) so header()/redirect calls never
// fail with "headers already sent" no matter where they happen in the page.
ob_start();
include_once '../config.php';
include_once '../notify.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
guard_password_change('../staff/staff_change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_reviewer  = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');
$is_scheduler = ($role === 'staff' && $department === 'scheduler');

if (!$is_reviewer && !$is_scheduler) {
    header("Location: ../staff/staff_dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// ── AJAX: enrolled students for a section ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'students' && isset($_GET['section_id'])) {
    header('Content-Type: application/json');
    $sid = (int)$_GET['section_id'];
    $res = $conn->prepare("
        SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, e.admission_strand AS strand,
               s.family_name, s.given_name, s.student_number
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE e.section_id = ? AND e.status IN ('pending','enrolled')
        ORDER BY s.family_name, s.given_name
    ");
    $res->bind_param('i', $sid);
    $res->execute();
    $data = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
    $conn->close();
    exit();
}

// ── Valid strands / school years ────────────────────────────────────────────
$valid_strands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT'];
// Sourced from school_year_settings (not a synthetic date-based range) so a
// submitted year always exists as a row there — school_year now has an
// enforced FK to school_year_settings.school_year.
$valid_school_years = [];
$sy_res = mysqli_query($conn, "SELECT school_year FROM school_year_settings ORDER BY school_year DESC");
if ($sy_res) { while ($r = mysqli_fetch_row($sy_res)) { $valid_school_years[] = $r[0]; } }

// ── Weekly business-hour slot list (Mon-Fri, hourly blocks 7:30 AM-4:30 PM) ──
function weekly_slots() {
    $days  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $slots = [];
    foreach ($days as $day) {
        for ($hour = 7; $hour < 16; $hour++) {
            $start = sprintf('%02d:30:00', $hour);
            $end   = sprintf('%02d:30:00', $hour + 1);
            $slots[] = ['day' => $day, 'start_time' => $start, 'end_time' => $end];
        }
    }
    return $slots;
}

// Assigns each subject_id a random-but-non-overlapping weekly slot for one section
function assign_subject_schedules($conn, $section_id, $subject_ids) {
    $slots = weekly_slots();
    $offset = array_rand($slots);
    foreach ($subject_ids as $i => $subject_id) {
        $slot = $slots[($offset + $i) % count($slots)];
        $stmt = $conn->prepare(
            "UPDATE section_subjects SET day=?, start_time=?, end_time=? WHERE section_id=? AND subject_id=?"
        );
        $stmt->bind_param('sssii', $slot['day'], $slot['start_time'], $slot['end_time'], $section_id, $subject_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Populates section_subjects (with a random teacher and a generated schedule)
// for every subject eligible for this section's grade/strand (+ CORE subjects).
// Runs on APPROVAL (or immediately for admin-created sections).
// $strandId must be the resolved strands.strand_id, not the code string.
function populate_section_subjects($conn, $section_id, $grade_level, $strandId, $school_year) {
    // Scoped to the section's own school_year — subjects are now tagged per
    // year (see scheduler/curriculum.php), so a section only ever pulls
    // subjects that actually belong to its curriculum year, not last year's
    // (or next year's) list.
    $coreId = strand_id($conn, 'CORE');
    $subj_stmt = $conn->prepare(
        "SELECT subject_id FROM subjects WHERE grade_level = ? AND strand IN (?, ?) AND school_year = ? AND is_active = 1 AND review_status = 'approved'"
    );
    $subj_stmt->bind_param('siis', $grade_level, $strandId, $coreId, $school_year);
    $subj_stmt->execute();
    $subject_ids = array_column($subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_id');
    $subj_stmt->close();

    $psst_sec_stmt = $conn->prepare("SELECT section_name FROM sections WHERE section_id = ?");
    $psst_sec_stmt->bind_param('i', $section_id);
    $psst_sec_stmt->execute();
    $psst_section_name = $psst_sec_stmt->get_result()->fetch_assoc()['section_name'] ?? '';
    $psst_sec_stmt->close();

    foreach ($subject_ids as $subject_id) {
        $teacher_row = $conn->query("SELECT teacher_id FROM teachers ORDER BY RAND() LIMIT 1")->fetch_assoc();
        $teacher_id  = $teacher_row ? (int) $teacher_row['teacher_id'] : null;

        $ss_stmt = $conn->prepare(
            "INSERT INTO section_subjects (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, 1)"
        );
        $ss_stmt->bind_param('iii', $section_id, $subject_id, $teacher_id);
        $ss_stmt->execute();
        $ss_stmt->close();

        // Keep teacher_assignments (what the teacher portal actually reads)
        // in sync with section_subjects.teacher_id — no unique key on this
        // table, so clear any stale row for this slot first, then insert.
        // This function only ever seeds a brand-new section, which always
        // starts at Semester 1.
        $del_stmt = $conn->prepare(
            "DELETE FROM teacher_assignments WHERE subject_id = ? AND section_id = ? AND school_year = ? AND semester = 1"
        );
        $del_stmt->bind_param('iis', $subject_id, $section_id, $school_year);
        $del_stmt->execute();
        $del_stmt->close();

        if ($teacher_id) {
            $ta_stmt = $conn->prepare(
                "INSERT INTO teacher_assignments (teacher_id, subject_id, section_id, school_year, semester) VALUES (?, ?, ?, ?, 1)"
            );
            $ta_stmt->bind_param('iiis', $teacher_id, $subject_id, $section_id, $school_year);
            $ta_stmt->execute();
            $ta_stmt->close();

            $psst_subj_stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
            $psst_subj_stmt->bind_param('i', $subject_id);
            $psst_subj_stmt->execute();
            $psst_subject_name = $psst_subj_stmt->get_result()->fetch_assoc()['subject_name'] ?? '';
            $psst_subj_stmt->close();

            $psst_tu_stmt = $conn->prepare("SELECT user_id FROM teachers WHERE teacher_id = ?");
            $psst_tu_stmt->bind_param('i', $teacher_id);
            $psst_tu_stmt->execute();
            $psst_tu_row = $psst_tu_stmt->get_result()->fetch_assoc();
            $psst_tu_stmt->close();
            notify_teacher_users(
                $conn,
                [(int) ($psst_tu_row['user_id'] ?? 0)],
                "You've been assigned to teach \"$psst_subject_name\" — $psst_section_name.",
                'teacherportal/teacher_schedule'
            );
        }
    }

    assign_subject_schedules($conn, $section_id, $subject_ids);
    return count($subject_ids);
}

// ── Add Section POST ───────────────────────────────────────────────────────
// Coordinator submissions land as 'pending' and wait for admin approval.
// Admin submissions are auto-approved and populated immediately.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
    $section_name = trim($_POST['section_name'] ?? '');
    $grade_level  = trim($_POST['grade_level']  ?? '');
    $strand       = trim($_POST['strand']        ?? '');
    $room         = trim($_POST['room']          ?? '');
    $capacity     = (int)($_POST['capacity']     ?? 0);
    $school_year  = trim($_POST['school_year']   ?? '');

    if (!$section_name || !$grade_level || !$strand || !$school_year || $capacity < 1) {
        $error = 'Section name, grade level, strand, school year, and capacity are required.';
    } elseif (!in_array($strand, $valid_strands, true)) {
        $error = 'Please select a valid strand.';
    } elseif (!in_array($school_year, $valid_school_years, true)) {
        $error = 'School year must be the current or upcoming academic year.';
    } else {
        $approval_status = $is_reviewer ? 'approved' : 'pending';
        $reviewed_by      = $is_reviewer ? $user_id : null;
        $reviewed_at      = $is_reviewer ? date('Y-m-d H:i:s') : null;
        $strandId         = strand_id($conn, $strand);

        $stmt = $conn->prepare(
            "INSERT INTO sections
                (section_name, grade_level, strand, room, capacity, school_year, is_active,
                 approval_status, requested_by, reviewed_by, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'ssiisssiis',
            $section_name, $grade_level, $strandId, $room, $capacity, $school_year,
            $approval_status, $user_id, $reviewed_by, $reviewed_at
        );

        if ($stmt->execute()) {
            $new_section_id = $stmt->insert_id;

            if ($is_reviewer) {
                populate_section_subjects($conn, $new_section_id, $grade_level, $strandId, $school_year);
                $success = "Section \"$section_name\" created and activated.";
            } else {
                $success = "Section \"$section_name\" submitted for coordinator approval. "
                          . "Subjects and a schedule will be generated automatically once it's approved.";
                notify_coordinators($conn, "New section \"$section_name\" awaiting approval.", 'coordinator/approvals?type=sections');
            }
        } else {
            $error = 'Could not create section. Please try again.';
        }
        $stmt->close();
    }
}

// ── Approve Section POST (ADMIN-ONLY) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can approve a new section.';
    } else {
        $sid = (int)($_POST['section_id'] ?? 0);
        $sec_stmt = $conn->prepare("SELECT grade_level, strand, school_year, approval_status, section_name, requested_by FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $sid);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif ($sec_row['approval_status'] !== 'pending') {
            $error = 'This section has already been reviewed.';
        } else {
            $upd = $conn->prepare(
                "UPDATE sections SET approval_status='approved', reviewed_by=?, reviewed_at=NOW() WHERE section_id=?"
            );
            $upd->bind_param('ii', $user_id, $sid);
            if ($upd->execute()) {
                populate_section_subjects($conn, $sid, $sec_row['grade_level'], $sec_row['strand'], $sec_row['school_year']);
                $upd->close();
                if ($sec_row['requested_by']) {
                    notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was approved.", 'scheduler/sections');
                }
                header("Location: sections?filter=" . urlencode($_GET['filter'] ?? 'pending'));
                exit();
            }
            $upd->close();
            $error = 'Could not approve section. Please try again.';
        }
    }
}

// ── Reject Section POST (ADMIN-ONLY) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can reject a new section.';
    } else {
        $sid    = (int)($_POST['section_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        $reason = $reason !== '' ? $reason : null;

        $sec_stmt = $conn->prepare("SELECT approval_status, section_name, requested_by FROM sections WHERE section_id = ?");
        $sec_stmt->bind_param('i', $sid);
        $sec_stmt->execute();
        $sec_row = $sec_stmt->get_result()->fetch_assoc();
        $sec_stmt->close();

        if (!$sec_row) {
            $error = 'Section not found.';
        } elseif ($sec_row['approval_status'] !== 'pending') {
            $error = 'This section has already been reviewed.';
        } else {
            $upd = $conn->prepare(
                "UPDATE sections
                 SET approval_status='rejected', is_active=0, rejection_reason=?, reviewed_by=?, reviewed_at=NOW()
                 WHERE section_id=?"
            );
            $upd->bind_param('sii', $reason, $user_id, $sid);
            if ($upd->execute()) {
                $upd->close();
                if ($sec_row['requested_by']) {
                    notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was rejected.", 'scheduler/sections');
                }
                header("Location: sections?filter=" . urlencode($_GET['filter'] ?? 'pending'));
                exit();
            }
            $upd->close();
            $error = 'Could not reject section. Please try again.';
        }
    }
}

// ── Deactivate (soft delete) — coordinator/admin only ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_section'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can deactivate a section.';
    } else {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE sections SET is_active = 0 WHERE section_id = ? AND approval_status = 'approved'"
        );
        $stmt->bind_param('i', $sid);
        if ($stmt->execute()) {
            header("Location: sections?filter=" . urlencode($_GET['filter'] ?? 'all'));
            exit();
        }
        $error = 'Could not deactivate section. Please try again.';
        $stmt->close();
    }
}

// ── Reactivate (undo soft delete) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_section'])) {
    $sid = (int)($_POST['section_id'] ?? 0);
    $stmt = $conn->prepare(
        "UPDATE sections SET is_active = 1 WHERE section_id = ? AND approval_status = 'approved'"
    );
    $stmt->bind_param('i', $sid);
    if ($stmt->execute()) {
        header("Location: sections?filter=" . urlencode($_GET['filter'] ?? 'all'));
        exit();
    }
    $error = 'Could not reactivate section. Please try again.';
    $stmt->close();
}

// ── Generate subjects for an approved section that currently has none ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_subjects'])) {
    $sid = (int)($_POST['section_id'] ?? 0);
    $sec_stmt = $conn->prepare("SELECT grade_level, strand, school_year, approval_status FROM sections WHERE section_id = ?");
    $sec_stmt->bind_param('i', $sid);
    $sec_stmt->execute();
    $sec_row = $sec_stmt->get_result()->fetch_assoc();
    $sec_stmt->close();

    if (!$sec_row) {
        $error = 'Section not found.';
    } elseif ($sec_row['approval_status'] !== 'approved') {
        $error = 'This section is not yet approved.';
    } else {
        $count_res = $conn->prepare("SELECT COUNT(*) AS c FROM section_subjects WHERE section_id = ?");
        $count_res->bind_param('i', $sid);
        $count_res->execute();
        $subj_count = (int) $count_res->get_result()->fetch_assoc()['c'];
        $count_res->close();

        if ($subj_count > 0) {
            $error = 'This section already has subjects assigned.';
        } else {
            populate_section_subjects($conn, $sid, $sec_row['grade_level'], $sec_row['strand'], $sec_row['school_year']);
            header("Location: sections?filter=" . urlencode($_GET['filter'] ?? 'all'));
            exit();
        }
    }
}

// ── Filter ───────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$allowed_filters = ['all', 'active', 'inactive', 'pending', 'rejected'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';

$where = '1=1';
switch ($filter) {
    case 'active':   $where = "s.is_active = 1 AND s.approval_status = 'approved'"; break;
    case 'inactive': $where = "s.is_active = 0 AND s.approval_status != 'pending'"; break;
    case 'pending':  $where = "s.approval_status = 'pending'"; break;
    case 'rejected': $where = "s.approval_status = 'rejected'"; break;
}

// Pending count, shown to admins as a heads-up regardless of current filter
$pending_count = 0;
if ($is_reviewer) {
    $pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sections WHERE approval_status = 'pending'");
    $pending_count = $pc ? (int) mysqli_fetch_assoc($pc)['c'] : 0;
}

// ── Fetch sections ─────────────────────────────────────────────────────────
$sql = "
    SELECT s.*,
           COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS enrolled,
           COUNT(DISTINCT ss.subject_id) AS subj_count,
           req.given_name AS req_given, req.family_name AS req_family,
           rev.given_name AS rev_given, rev.family_name AS rev_family,
           st.strand_code AS strand
    FROM sections s
    JOIN strands st ON st.strand_id = s.strand
    LEFT JOIN enrollments e ON e.section_id = s.section_id
    LEFT JOIN section_subjects ss ON ss.section_id = s.section_id
    LEFT JOIN staff req ON req.user_id = s.requested_by
    LEFT JOIN staff rev ON rev.user_id = s.reviewed_by
    WHERE $where
    GROUP BY s.section_id
    ORDER BY (s.approval_status = 'pending') DESC, s.is_active DESC,
             s.grade_level ASC, s.strand ASC, s.section_name ASC
";
$result = mysqli_query($conn, $sql);
$rows = [];
if ($result) {
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = $r;
    }
}
// NOTE: $conn is intentionally left open here — staff_sidebar.php (included
// further down, in the HTML) runs its own queries against it. PHP closes the
// connection automatically at end of script.

$filter_labels = [
    'all'      => 'All',
    'active'   => 'Active',
    'inactive' => 'Inactive',
    'pending'  => 'Pending Approval',
    'rejected' => 'Rejected',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sections — <?= $is_reviewer ? 'Coordinator' : 'Scheduler' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_staff.css') ?>">
  <style>
    /* Supplemental styles for the sections table/modal — css_staff.css doesn't
       define a generic data table, so these piggyback on its CSS variables. */
    .filter-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:1rem; }
    .filter-tab {
      font-size:12px; font-weight:500; padding:6px 12px; border-radius:20px;
      border:0.5px solid #D4D4E0; background:#fff; color:#5A5A72;
      text-decoration:none; white-space:nowrap; transition:all .12s;
    }
    .filter-tab:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .filter-tab.active { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }

    .data-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:auto; }
    .data-table th {
      text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.05em; color:#8A8A9A; padding:8px 12px;
      border-bottom:0.5px solid #EBEBF0;
    }
    .data-table td { padding:10px 12px; border-bottom:0.5px solid #F5F5F7; vertical-align:top; }
    .data-table th:first-child, .data-table td:first-child { padding-left:0; }
    .data-table th:last-child,  .data-table td:last-child  { padding-right:0; }
    .data-table tr:last-child td { border-bottom:none; }
    .td-name { font-weight:500; color:#1A1A2E; }
    .td-meta { font-size:11px; color:#8A8A9A; margin-top:2px; }

    .badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase;
      letter-spacing:.03em; border-radius:20px; padding:2px 9px; margin:1px 4px 1px 0; white-space:nowrap; }
    .badge-active    { background:#EBF7F2; color:#1A7A5E; }
    .badge-inactive  { background:#F5F5F7; color:#8A8A9A; }
    .badge-pending   { background:#FFF4E6; color:#C06A10; }
    .badge-approved  { background:#EAF3EE; color:var(--brand-primary); }
    .badge-rejected  { background:#FDF0EF; color:#C0392B; }

    .cap-bar { height:6px; background:#EBEBF0; border-radius:3px; margin-top:4px; overflow:hidden; width:80px; }
    .cap-fill { height:100%; border-radius:3px; }
    .cap-ok   { background:#2ECC71; }
    .cap-low  { background:#E67E22; }
    .cap-full { background:#C0392B; }

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }

    .form-group { margin-bottom:.85rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem; }
    .form-group input, .form-group select { width:100%; height:38px; border:0.5px solid #D4D4E0;
      border-radius:8px; background:#FAFAFC; padding:0 12px; font-size:13px;
      font-family:inherit; color:#1A1A2E; outline:none; }
    .form-group input:focus, .form-group select:focus {
      border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff; }
    .form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .btn-add { width:100%; height:38px; background:var(--brand-primary); border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:.2rem; }
    .btn-add:hover { background:var(--brand-primary-hover); }

    .two-col { display:grid; grid-template-columns:1fr 320px; gap:14px; align-items:start; }
    .full-col { display:block; }
    .full-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    /* css_staff.css's .panel is built for centered single-column public pages
       (max-width:760px; margin:auto). Inside this wider two-column dashboard
       grid that centering just eats space on both sides — neutralize it here
       so each panel fills its grid column instead. */
    .two-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    .card-title { font-size:13px; font-weight:600; color:#1A1A2E; }
    .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.9rem; }
    .empty-state { color:#8A8A9A; font-size:13px; padding:1rem 0; }
    tr.clickable { cursor:pointer; }
    tr.clickable:hover td { background:#FAFAFC; }

    /* Modal */
    dialog#sectionModal { border:none; border-radius:12px; padding:1.5rem;
      width:min(560px, 92vw); max-height:80vh; overflow-y:auto;
      box-shadow:0 8px 40px rgba(0,0,0,.22);
      position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); margin:0; }
    dialog#sectionModal::backdrop { background:rgba(0,0,0,.45); }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .modal-title { font-size:15px; font-weight:600; color:#1A1A2E; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:#8A8A9A; line-height:1; padding:0 4px; }
    .modal-close:hover { color:#1A1A2E; }
    .modal-meta { font-size:12px; color:#8A8A9A; margin-bottom:1rem; }
    .student-list { border-collapse:collapse; width:100%; font-size:13px; }
    .student-list th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:5px 8px 5px 0; border-bottom:0.5px solid #EBEBF0; }
    .student-list td { padding:8px 8px 8px 0; border-bottom:0.5px solid #F5F5F7; }
    .student-list tr:last-child td { border-bottom:none; }
    .badge-enrolled { background:#EBF7F2; color:#1A7A5E; }

    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="staff-layout">

  <?php include_once '../staff/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <button class="btn-sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" type="button">
          <span></span><span></span><span></span>
        </button>
        <div class="staff-topbar-title">
          Sections
          <span class="staff-topbar-subtitle"><?= $is_reviewer ? 'Coordinator review' : 'Scheduler' ?></span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once '../staff/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <p class="page-eyebrow"><?= $is_reviewer ? 'Coordinator Portal' : 'Scheduler Portal' ?></p>
      <h1 class="page-title">Sections</h1>
      <p class="page-sub"><?= $is_reviewer ? 'Review submitted sections and manage capacity.' : 'Create sections, track approval, and manage capacity.' ?></p>

      <?php if ($success): ?><div class="notice notice-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($is_reviewer && $pending_count > 0 && $filter !== 'pending'): ?>
        <div class="notice notice-info">
          <?= $pending_count ?> section<?= $pending_count === 1 ? '' : 's' ?> awaiting your approval.
          <a href="?filter=pending">Review now</a>
        </div>
      <?php endif; ?>

      <div class="<?= $is_reviewer ? 'full-col' : 'two-col' ?>">

        <!-- Sections list -->
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Sections (<?= count($rows) ?>)</span>
          </div>

          <div class="filter-row">
            <?php foreach ($filter_labels as $key => $label): ?>
              <a class="filter-tab <?= $filter === $key ? 'active' : '' ?>" href="?filter=<?= $key ?>">
                <?= $label ?><?= ($key === 'pending' && $is_reviewer && $pending_count > 0) ? " ($pending_count)" : '' ?>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if (empty($rows)): ?>
            <p class="empty-state">No sections found for this filter.</p>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Section</th>
                  <th>Grade / Strand</th>
                  <th>Room</th>
                  <th>S.Y.</th>
                  <th>Capacity</th>
                  <th>Approval</th>
                  <th>Status</th>
                  <th><?= $is_reviewer ? 'Action' : '' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r):
                  $enrolled  = (int)$r['enrolled'];
                  $capacity  = (int)$r['capacity'];
                  $remaining = $capacity - $enrolled;
                  $pct       = $capacity > 0 ? round($enrolled / $capacity * 100) : 0;
                  if ($remaining <= 0)      { $fillClass = 'cap-full'; }
                  elseif ($remaining <= 5)  { $fillClass = 'cap-low'; }
                  else                      { $fillClass = 'cap-ok'; }
                  $slotText = $remaining <= 0 ? 'Full' : "$remaining slots left";

                  $approval = $r['approval_status'];
                  $modalArgs = $r['section_id'] . ', ' . htmlspecialchars(json_encode($r['section_name'])) . ', ' . $enrolled . ', ' . $capacity;
                ?>
                  <tr class="clickable" tabindex="0"
                      onclick="openModal(<?= $modalArgs ?>)"
                      onkeydown="if(event.key==='Enter'||event.key===' ')openModal(<?= $modalArgs ?>)">
                    <td>
                      <div class="td-name"><?= htmlspecialchars($r['section_name']) ?></div>
                      <?php if ($approval === 'pending' && $r['req_family']): ?>
                        <div class="td-meta">Requested by <?= htmlspecialchars($r['req_given'] . ' ' . $r['req_family']) ?></div>
                      <?php elseif ($approval === 'rejected'): ?>
                        <div class="td-meta">
                          Rejected<?= $r['rev_family'] ? ' by ' . htmlspecialchars($r['rev_given'] . ' ' . $r['rev_family']) : '' ?><?= $r['rejection_reason'] ? ': ' . htmlspecialchars($r['rejection_reason']) : '' ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                    <td><?= htmlspecialchars($r['room'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['school_year']) ?></td>
                    <td>
                      <span style="font-size:13px;"><?= $enrolled ?>/<?= $capacity ?></span>
                      <div class="td-meta"><?= $slotText ?></div>
                      <div class="cap-bar"><div class="cap-fill <?= $fillClass ?>" style="width:<?= $pct ?>%"></div></div>
                    </td>
                    <td>
                      <span class="badge badge-<?= $approval ?>"><?= ucfirst($approval) ?></span>
                    </td>
                    <td>
                      <span class="badge <?= $r['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                        <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                      </span>
                    </td>
                    <td onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">

                      <?php if ($approval === 'pending'): ?>
                        <?php if ($is_reviewer): ?>
                          <!-- ADMIN-ONLY: approve / reject a brand-new section -->
                          <form method="POST" style="display:inline;"
                                data-confirm="Approve &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? Subjects, teachers, and a schedule will be generated automatically." data-icon="question">
                            <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                            <button type="submit" name="approve_section" class="btn-toggle" style="color:#1A7A5E;border-color:#A8D9C5;">
                              Approve
                            </button>
                          </form>
                          <form method="POST" style="display:inline;" class="reject-form">
                            <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                            <input type="hidden" name="rejection_reason" value="">
                            <button type="button" class="btn-toggle js-reject" style="color:#C0392B;border-color:#F5C6C2;"
                                    data-name="<?= htmlspecialchars($r['section_name'], ENT_QUOTES) ?>">
                              Reject
                            </button>
                          </form>
                        <?php else: ?>
                          <span class="td-meta">Awaiting admin approval</span>
                        <?php endif; ?>

                      <?php elseif ($approval === 'approved'): ?>
                        <?php if ((int)$r['subj_count'] === 0): ?>
                          <form method="POST" style="display:inline;"
                                data-confirm="Generate subjects, teachers, and a schedule for &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;?" data-icon="question">
                            <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                            <button type="submit" name="regenerate_subjects" class="btn-toggle" style="color:var(--brand-primary);border-color:#C9C2F0;">
                              Generate Subjects
                            </button>
                          </form>
                        <?php endif; ?>

                        <?php if ($r['is_active']): ?>
                          <?php if ($is_reviewer): ?>
                            <form method="POST" style="display:inline;"
                                  data-confirm="Deactivate section &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? Students already enrolled will be unaffected, but the section will no longer accept new enrollments." data-icon="warning">
                              <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                              <button type="submit" name="deactivate_section" class="btn-toggle" style="color:#C0392B;border-color:#F5C6C2;">
                                Deactivate
                              </button>
                            </form>
                          <?php endif; ?>
                        <?php else: ?>
                          <form method="POST" style="display:inline;"
                                data-confirm="Reactivate section &quot;<?= htmlspecialchars($r['section_name']) ?>&quot;? It will accept new enrollments again." data-icon="question">
                            <input type="hidden" name="section_id" value="<?= (int)$r['section_id'] ?>">
                            <button type="submit" name="reactivate_section" class="btn-toggle" style="color:#1A7A5E;border-color:#A8D9C5;">
                              Reactivate
                            </button>
                          </form>
                        <?php endif; ?>

                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- Add Section form — schedulers only; coordinators/admin don't create sections here -->
        <?php if (!$is_reviewer): ?>
        <div class="panel">
          <div class="card-header">
            <span class="card-title">New Section</span>
          </div>
          <p class="td-meta" style="margin-bottom:.75rem;">Submitted sections need admin approval before subjects and a schedule are generated.</p>
          <form method="POST"
                data-confirm="Submit this section for admin approval?"
                data-icon="question">
            <div class="form-group">
              <label for="f_section_name">Section Name <span style="color:#C0392B">*</span></label>
              <input id="f_section_name" type="text" name="section_name" placeholder="e.g. STEM-A"
                     value="<?= htmlspecialchars($_POST['section_name'] ?? '') ?>" required>
            </div>
            <div class="form-row-2">
              <div class="form-group">
                <label for="f_grade_level">Grade Level <span style="color:#C0392B">*</span></label>
                <select id="f_grade_level" name="grade_level" required>
                  <option value="">Select</option>
                  <option value="11" <?= ($_POST['grade_level'] ?? '') === '11' ? 'selected' : '' ?>>Grade 11</option>
                  <option value="12" <?= ($_POST['grade_level'] ?? '') === '12' ? 'selected' : '' ?>>Grade 12</option>
                </select>
              </div>
              <div class="form-group">
                <label for="f_strand">Strand <span style="color:#C0392B">*</span></label>
                <select id="f_strand" name="strand" required>
                  <option value="">Select</option>
                  <?php foreach ($valid_strands as $s): ?>
                    <option value="<?= $s ?>" <?= ($_POST['strand'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-row-2">
              <div class="form-group">
                <label for="f_room">Room <span style="color:#8A8A9A;font-weight:400">(optional)</span></label>
                <input id="f_room" type="text" name="room" placeholder="e.g. Room 201"
                       value="<?= htmlspecialchars($_POST['room'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label for="f_capacity">Capacity <span style="color:#C0392B">*</span></label>
                <input id="f_capacity" type="number" name="capacity" min="1" max="999" placeholder="e.g. 40"
                       value="<?= htmlspecialchars($_POST['capacity'] ?? '') ?>" required>
              </div>
            </div>
            <div class="form-group">
              <label for="f_school_year">School Year <span style="color:#C0392B">*</span></label>
              <select id="f_school_year" name="school_year" required>
                <?php foreach ($valid_school_years as $sy): ?>
                  <option value="<?= $sy ?>" <?= ($_POST['school_year'] ?? '') === $sy ? 'selected' : '' ?>><?= $sy ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" name="add_section" class="btn-add">
              Submit for Approval
            </button>
          </form>
        </div>
        <?php endif; ?>

      </div>

    </div>
  </div>

  <!-- Drill-down Modal (native <dialog>) -->
  <dialog id="sectionModal" aria-labelledby="modalTitle">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Section Students</span>
      <button class="modal-close" aria-label="Close"
              onclick="document.getElementById('sectionModal').close()">&times;</button>
    </div>
    <div class="modal-meta" id="modalMeta"></div>
    <div id="modalBody">
      <p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>
    </div>
  </dialog>

  <script>
    // ── Sidebar toggle (defensive: only wires up if the sidebar markup exists) ──
    const sidebarEl     = document.getElementById('staffSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarEl && sidebarToggle) {
      sidebarToggle.addEventListener('click', () => sidebarEl.classList.toggle('open'));
    }

    // ── Reject-with-reason: SWAL textarea, then submit ──
    document.querySelectorAll('.js-reject').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('.reject-form');
        const name = btn.dataset.name;
        Swal.fire({
          title: 'Reject "' + name + '"?',
          input: 'textarea',
          inputPlaceholder: 'Reason (optional)',
          inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#C0392B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Reject',
          cancelButtonText: 'Cancel'
        }).then((result) => {
          if (!result.isConfirmed) return;
          form.querySelector('input[name="rejection_reason"]').value = (result.value || '').trim();
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'reject_section';
          hidden.value = '1';
          form.appendChild(hidden);
          form.submit();
        });
      });
    });

    // ── Section student drill-down modal ──
    const dlg = document.getElementById('sectionModal');

    dlg.addEventListener('click', function(e) {
      const rect = dlg.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right ||
          e.clientY < rect.top  || e.clientY > rect.bottom) {
        dlg.close();
      }
    });

    function openModal(sectionId, sectionName, enrolled, capacity) {
      document.getElementById('modalTitle').textContent = sectionName;
      document.getElementById('modalMeta').textContent  = enrolled + ' enrolled / ' + capacity + ' capacity';
      document.getElementById('modalBody').innerHTML    = '<p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>';
      dlg.showModal();

      fetch('sections?action=students&section_id=' + sectionId)
        .then(r => r.json())
        .then(data => {
          if (!data.length) {
            document.getElementById('modalBody').innerHTML = '<p style="color:#8A8A9A;font-size:13px;text-align:center;padding:1.5rem 0;">No enrolled students in this section.</p>';
            return;
          }
          let html = '<table class="student-list"><thead><tr><th>#</th><th>Name</th><th>Student Number</th><th>Status</th></tr></thead><tbody>';
          data.forEach((s, i) => {
            const badgeCls = s.status === 'enrolled' ? 'badge-enrolled' : 'badge-pending';
            html += '<tr>'
              + '<td style="color:#8A8A9A;font-size:12px;">' + (i+1) + '</td>'
              + '<td style="font-weight:500">' + esc(s.family_name) + ', ' + esc(s.given_name) + '</td>'
              + '<td style="font-family:monospace;font-size:12px;color:var(--brand-primary)">' + (s.student_number || '—') + '</td>'
              + '<td><span class="badge ' + badgeCls + '">' + s.status + '</span></td>'
              + '</tr>';
          });
          html += '</tbody></table>';
          document.getElementById('modalBody').innerHTML = html;
        })
        .catch(() => {
          document.getElementById('modalBody').innerHTML = '<p style="color:#C0392B;font-size:13px;">Failed to load students.</p>';
        });
    }

    function esc(str) {
      return String(str || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
  </script>

</body>
</html>