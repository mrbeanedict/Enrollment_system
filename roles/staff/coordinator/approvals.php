<?php
/* ============================================================
   approvals.php — Coordinator Approvals (consolidated)
   ------------------------------------------------------------
   Reviewer-only page (role='admin' or department='coordinator').
   Consolidates the three pending-request queues that already
   live on sections.php / curriculum.php / scheduling.php into
   one pill-filtered dashboard: Sections | Curriculum | Schedule.

   Approve/Reject actions here run the exact same prepared
   statements as those pages (same tables/columns/guards) — this
   page does not replace them, it's an additional consolidated
   entry point for coordinators.
   ============================================================ */

session_start();
require_once __DIR__ . '/../../../bootstrap.php';
include_once '../notify.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . APP_URL . "/login"); exit();
}
guard_password_change(APP_URL . '/roles/staff/change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_reviewer = ($role === 'admin') || ($role === 'staff' && $department === 'coordinator');

if (!$is_reviewer) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// ── Type / status / search / page (shared filter state) ────────────────────
$valid_types = ['sections', 'curriculum', 'schedule'];
$type = in_array($_GET['type'] ?? '', $valid_types, true) ? $_GET['type'] : 'sections';

$valid_status = ['', 'pending', 'approved', 'rejected'];
$status_raw = $_GET['status'] ?? '';
$status = in_array($status_raw, $valid_status, true) ? $status_raw : 'pending';

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$valid_grade_levels = ['11', '12'];
$valid_strands      = ['CORE', 'STEM', 'HUMSS', 'ABM', 'GAS', 'TVL'];
$f_grade  = in_array($_GET['grade_level'] ?? '', $valid_grade_levels, true) ? $_GET['grade_level'] : '';
$f_strand = in_array($_GET['strand'] ?? '', $valid_strands, true) ? $_GET['strand'] : '';

$return_qs = http_build_query(array_filter([
    'type'        => $type,
    'status'      => $status !== '' ? $status : null,
    'q'           => $q !== '' ? $q : null,
    'grade_level' => $f_grade !== '' ? $f_grade : null,
    'strand'      => $f_strand !== '' ? $f_strand : null,
    'page'        => $page > 1 ? $page : null,
]));

// ── Approve / Reject: Sections ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_section'])) {
    $sid = (int)($_POST['section_id'] ?? 0);
    $sec_stmt = $conn->prepare("SELECT grade_level, strand, approval_status, section_name, requested_by, school_year FROM sections WHERE section_id = ?");
    $sec_stmt->bind_param('i', $sid);
    $sec_stmt->execute();
    $sec_row = $sec_stmt->get_result()->fetch_assoc();
    $sec_stmt->close();

    if (!$sec_row) {
        $error = 'Section not found.';
    } elseif ($sec_row['approval_status'] !== 'pending') {
        $error = 'This section has already been reviewed.';
    } else {
        $upd = $conn->prepare("UPDATE sections SET approval_status='approved', reviewed_by=?, reviewed_at=NOW() WHERE section_id=?");
        $upd->bind_param('ii', $user_id, $sid);
        if ($upd->execute()) {
            populate_section_subjects_for_approval($conn, $sid, $sec_row['grade_level'], $sec_row['strand'], $sec_row['school_year']);
            $upd->close();
            if ($sec_row['requested_by']) {
                notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was approved.", 'scheduler/sections');
            }
            header("Location: approvals?" . $return_qs); exit();
        }
        $upd->close();
        $error = 'Could not approve section. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_section'])) {
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
            "UPDATE sections SET approval_status='rejected', is_active=0, rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE section_id=?"
        );
        $upd->bind_param('sii', $reason, $user_id, $sid);
        if ($upd->execute()) {
            $upd->close();
            if ($sec_row['requested_by']) {
                notify_users($conn, [$sec_row['requested_by']], "Section \"{$sec_row['section_name']}\" was rejected.", 'scheduler/sections');
            }
            header("Location: approvals?" . $return_qs); exit();
        }
        $upd->close();
        $error = 'Could not reject section. Please try again.';
    }
}

// Mirrors sections.php's populate_section_subjects() — kept local so this
// page has no hard dependency on sections.php internals.
function populate_section_subjects_for_approval($conn, $section_id, $grade_level, $strandId, $school_year) {
    $coreId = strand_id($conn, 'CORE');
    $subj_stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE grade_level = ? AND strand IN (?, ?)");
    $subj_stmt->bind_param('sii', $grade_level, $strandId, $coreId);
    $subj_stmt->execute();
    $subject_ids = array_column($subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_id');
    $subj_stmt->close();

    foreach ($subject_ids as $subject_id) {
        $teacher_row = $conn->query("SELECT teacher_id FROM teachers ORDER BY RAND() LIMIT 1")->fetch_assoc();
        $teacher_id  = $teacher_row ? (int) $teacher_row['teacher_id'] : null;
        $ss_stmt = $conn->prepare("INSERT INTO section_subjects (section_id, subject_id, teacher_id, semester) VALUES (?, ?, ?, 1)");
        $ss_stmt->bind_param('iii', $section_id, $subject_id, $teacher_id);
        $ss_stmt->execute();
        $ss_stmt->close();

        // Keep teacher_assignments (what the teacher portal actually reads)
        // in sync with section_subjects.teacher_id — same pattern as
        // scheduler/sections.php's populate_section_subjects(). This
        // function only ever seeds a brand-new section, always Semester 1.
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
        }
    }
}

// ── Approve / Reject: Curriculum (subjects) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_subject'])) {
    $subid = (int)($_POST['subject_id'] ?? 0);
    $info_stmt = $conn->prepare("SELECT subject_name, proposed_by, review_status FROM subjects WHERE subject_id = ?");
    $info_stmt->bind_param('i', $subid);
    $info_stmt->execute();
    $subj_row = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();

    if (!$subj_row) {
        $error = 'Subject not found.';
    } elseif ($subj_row['review_status'] !== 'for_review') {
        $error = 'This subject has already been reviewed.';
    } else {
        $stmt = $conn->prepare(
            "UPDATE subjects SET review_status='approved', approved_by=?, approved_at=NOW(), rejection_reason=NULL WHERE subject_id=? AND review_status='for_review'"
        );
        $stmt->bind_param('ii', $user_id, $subid);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok && $affected > 0) {
            if ($subj_row['proposed_by']) {
                notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was approved.", 'scheduler/curriculum');
            }
            header("Location: approvals?" . $return_qs); exit();
        }
        $error = 'Could not approve — it may have already been reviewed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_subject'])) {
    $subid  = (int)($_POST['subject_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$reason) {
        $error = 'Please provide a reason for rejecting this subject.';
    } else {
        $info_stmt = $conn->prepare("SELECT subject_name, proposed_by, review_status FROM subjects WHERE subject_id = ?");
        $info_stmt->bind_param('i', $subid);
        $info_stmt->execute();
        $subj_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        if (!$subj_row) {
            $error = 'Subject not found.';
        } elseif ($subj_row['review_status'] !== 'for_review') {
            $error = 'This subject has already been reviewed.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE subjects SET review_status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=? WHERE subject_id=? AND review_status='for_review'"
            );
            $stmt->bind_param('isi', $user_id, $reason, $subid);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($ok && $affected > 0) {
                if ($subj_row['proposed_by']) {
                    notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was rejected.", 'scheduler/curriculum');
                }
                header("Location: approvals?" . $return_qs); exit();
            }
            $error = 'Could not reject — it may have already been reviewed.';
        }
    }
}

// ── Approve / Reject: Schedule (per-section package) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $info_stmt = $conn->prepare("SELECT section_name, schedule_submitted_by, schedule_status FROM sections WHERE section_id = ?");
    $info_stmt->bind_param('i', $section_id);
    $info_stmt->execute();
    $sched_row = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();

    if (!$sched_row) {
        $error = 'Section not found.';
    } elseif ($sched_row['schedule_status'] !== 'pending') {
        $error = 'This schedule has already been reviewed.';
    } else {
        $stmt = $conn->prepare(
            "UPDATE sections SET schedule_status='approved', schedule_reviewed_by=?, schedule_reviewed_at=NOW() WHERE section_id=? AND schedule_status='pending'"
        );
        $stmt->bind_param('ii', $user_id, $section_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            if ($sched_row['schedule_submitted_by']) {
                notify_users($conn, [$sched_row['schedule_submitted_by']], "Schedule for section \"{$sched_row['section_name']}\" was approved.", 'scheduler/scheduling');
            }
            header("Location: approvals?" . $return_qs); exit();
        }
        $error = 'Could not approve — this schedule may have already been reviewed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_schedule'])) {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $reason     = trim($_POST['rejection_reason'] ?? '');
    if (!$reason) {
        $error = 'Please provide a reason for rejecting this schedule.';
    } else {
        $info_stmt = $conn->prepare("SELECT section_name, schedule_submitted_by, schedule_status FROM sections WHERE section_id = ?");
        $info_stmt->bind_param('i', $section_id);
        $info_stmt->execute();
        $sched_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        if (!$sched_row) {
            $error = 'Section not found.';
        } elseif ($sched_row['schedule_status'] !== 'pending') {
            $error = 'This schedule has already been reviewed.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE sections SET schedule_status='rejected', schedule_reviewed_by=?, schedule_reviewed_at=NOW(), schedule_rejection_reason=? WHERE section_id=? AND schedule_status='pending'"
            );
            $stmt->bind_param('isi', $user_id, $reason, $section_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                if ($sched_row['schedule_submitted_by']) {
                    notify_users($conn, [$sched_row['schedule_submitted_by']], "Schedule for section \"{$sched_row['section_name']}\" was rejected.", 'scheduler/scheduling');
                }
                header("Location: approvals?" . $return_qs); exit();
            }
            $error = 'Could not reject — this schedule may have already been reviewed.';
        }
    }
}

// ── Build the query for the currently-selected type ─────────────────────────
$status_col = [
    'sections'   => 's.approval_status',
    'curriculum' => 's.review_status',
    'schedule'   => 's.schedule_status',
][$type];

$status_value_map = [
    'sections'   => ['pending' => 'pending',    'approved' => 'approved', 'rejected' => 'rejected'],
    'curriculum' => ['pending' => 'for_review',  'approved' => 'approved', 'rejected' => 'rejected'],
    'schedule'   => ['pending' => 'pending',     'approved' => 'approved', 'rejected' => 'rejected'],
][$type];

$grade_col  = 's.grade_level';
$strand_col = 'st.strand_code'; // same alias (strands table) in every type's query

$where  = ['1=1'];
$types  = '';
$params = [];

if ($status !== '' && isset($status_value_map[$status])) {
    $where[] = "$status_col = ?";
    $types  .= 's';
    $params[] = $status_value_map[$status];
}

if ($q !== '') {
    $like = '%' . $q . '%';
    if ($type === 'curriculum') {
        $where[] = '(s.subject_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)';
    } else {
        $where[] = '(s.section_name LIKE ? OR req.family_name LIKE ? OR req.given_name LIKE ?)';
    }
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($f_grade !== '')  { $where[] = "$grade_col = ?";   $types .= 's'; $params[] = $f_grade; }
if ($f_strand !== '') { $where[] = "$strand_col = ?";  $types .= 's'; $params[] = $f_strand; }
$where_sql = implode(' AND ', $where);

if ($type === 'sections') {
    $base_sql = "FROM sections s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = s.requested_by WHERE $where_sql";
    $select   = "SELECT s.section_id, s.section_name, s.grade_level, st.strand_code AS strand, s.room, s.capacity, s.school_year,
                        s.approval_status AS status, s.rejection_reason,
                        req.family_name AS req_family, req.given_name AS req_given, s.requested_by AS req_uid";
    $order    = "ORDER BY (s.approval_status = 'pending') DESC, s.section_id DESC";
} elseif ($type === 'curriculum') {
    $base_sql = "FROM subjects s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = s.proposed_by WHERE $where_sql";
    $select   = "SELECT s.subject_id, s.subject_name, s.grade_level, st.strand_code AS strand,
                        s.review_status AS status, s.rejection_reason,
                        req.family_name AS req_family, req.given_name AS req_given, s.proposed_by AS req_uid";
    $order    = "ORDER BY (s.review_status = 'for_review') DESC, s.subject_id DESC";
} else { // schedule
    $base_sql = "FROM sections s JOIN strands st ON st.strand_id = s.strand LEFT JOIN staff req ON req.user_id = s.schedule_submitted_by WHERE $where_sql";
    $select   = "SELECT s.section_id, s.section_name, s.grade_level, st.strand_code AS strand, s.school_year,
                        s.schedule_status AS status, s.schedule_rejection_reason AS rejection_reason,
                        req.family_name AS req_family, req.given_name AS req_given, s.schedule_submitted_by AS req_uid";
    $order    = "ORDER BY (s.schedule_status = 'pending') DESC, s.section_id DESC";
}

$count_stmt = $conn->prepare("SELECT COUNT(*) AS c $base_sql");
if ($types !== '') $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_count = (int)$count_stmt->get_result()->fetch_assoc()['c'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_count / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$sql = "$select $base_sql $order LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$list_types  = $types . 'ii';
$list_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Pending counts across all three, for the pill badges
$pending_counts = ['sections' => 0, 'curriculum' => 0, 'schedule' => 0];
$pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sections WHERE approval_status = 'pending'");
if ($pc) $pending_counts['sections'] = (int)mysqli_fetch_assoc($pc)['c'];
$pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM subjects WHERE review_status = 'for_review'");
if ($pc) $pending_counts['curriculum'] = (int)mysqli_fetch_assoc($pc)['c'];
$pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sections WHERE schedule_status = 'pending'");
if ($pc) $pending_counts['schedule'] = (int)mysqli_fetch_assoc($pc)['c'];

$type_labels = ['sections' => 'Sections', 'curriculum' => 'Curriculum', 'schedule' => 'Schedule'];
$status_labels = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

// NOTE: do NOT close $conn here — staff_sidebar.php / staff_notifications.php
// (included below, in the HTML) run their own queries and need it open.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Approvals — Coordinator</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    .filter-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:.75rem; }
    .filter-tab {
      font-size:12px; font-weight:500; padding:6px 12px; border-radius:20px;
      border:0.5px solid #D4D4E0; background:#fff; color:#5A5A72;
      text-decoration:none; white-space:nowrap; transition:all .12s;
    }
    .filter-tab:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .filter-tab.active { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }

    .type-tabs { display:flex; gap:8px; margin-bottom:1rem; flex-wrap:wrap; }
    .type-tab {
      font-size:13px; font-weight:600; padding:9px 18px; border-radius:20px;
      border:1px solid #D4D4E0; background:#fff; color:#5A5A72;
      text-decoration:none; white-space:nowrap; transition:all .12s;
    }
    .type-tab:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .type-tab.active { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }
    .type-tab .cnt { opacity:.85; font-weight:500; }

    .toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:1rem; flex-wrap:wrap; }
    .search-box { position:relative; flex:0 0 auto; width:240px; }
    .search-box input {
      width:100%; height:36px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 12px 0 34px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; box-sizing:border-box;
    }
    .search-box input:focus { border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff; }
    .search-box svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#8A8A9A; }

    .filters-form { display:flex; flex-wrap:wrap; align-items:center; gap:8px; flex:0 0 auto; }
    .filter-select {
      flex:0 0 auto; width:150px; height:36px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 10px; font-size:12px; font-family:inherit; color:#1A1A2E; box-sizing:border-box;
    }
    .filters-form .clear-link { font-size:12px; color:#8A8A9A; text-decoration:none; white-space:nowrap; }
    .filters-form .clear-link:hover { color:#C0392B; }

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
    .badge-pending    { background:#FFF4E6; color:#C06A10; }
    .badge-for_review { background:#FFF4E6; color:#C06A10; }
    .badge-approved   { background:#EAF3EE; color:var(--brand-primary); }
    .badge-rejected   { background:#FDF0EF; color:#C0392B; }

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0;
    }
    .action-btn-row { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }

    .panel { max-width:none; margin:0; width:100%; }
    .card-title { font-size:13px; font-weight:600; color:#1A1A2E; }
    .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.9rem; }
    .empty-state { color:#8A8A9A; font-size:13px; padding:1.5rem 0; text-align:center; }

    .app-pagination { display:flex; justify-content:space-between; align-items:center; margin-top:1rem; font-size:12px; color:#8A8A9A; }
    .app-pagination .pages { display:flex; gap:4px; }
    .app-pagination .pages a, .app-pagination .pages span {
      display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:26px;
      border-radius:6px; text-decoration:none; color:#5A5A72; font-size:12px;
    }
    .app-pagination .pages a:hover { background:#F5F5F7; }
    .app-pagination .pages span.current { background:var(--brand-primary); color:#fff; font-weight:600; }

    .rej-reason { font-size:11px; color:#C0392B; margin-top:2px; }

    .table-scroll { overflow-x:auto; }

    @media (max-width: 700px) {
      .toolbar { flex-direction:column; align-items:stretch; }
      .filters-form { flex-direction:column; align-items:stretch; }
      .search-box, .filter-select { width:100%; }
    }
  </style>
</head>
<body class="staff-layout">

  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>

  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <button class="btn-sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" type="button">
          <span></span><span></span><span></span>
        </button>
        <div class="staff-topbar-title">
          Approvals
          <span class="staff-topbar-subtitle">Coordinator review</span>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="staff-content">

      <p class="page-eyebrow">Coordinator Portal</p>
      <h1 class="page-title">Approvals</h1>
      <p class="page-sub">Review section, curriculum, and schedule requests submitted by schedulers.</p>

      <?php if ($success): ?><div class="notice notice-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <!-- Category pills -->
      <div class="type-tabs">
        <?php foreach ($type_labels as $tkey => $tlabel): ?>
          <a class="type-tab <?= $type === $tkey ? 'active' : '' ?>"
             href="?<?= http_build_query(['type' => $tkey, 'status' => 'pending']) ?>">
            <?= $tlabel ?>
            <?php if ($pending_counts[$tkey] > 0): ?><span class="cnt">(<?= $pending_counts[$tkey] ?>)</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="panel">
        <div class="card-header">
          <span class="card-title"><?= $type_labels[$type] ?> Requests (<?= $total_count ?>)</span>
        </div>

        <div class="toolbar">
          <div class="filter-row" style="margin-bottom:0;">
            <?php foreach ($status_labels as $skey => $slabel): ?>
              <a class="filter-tab <?= $status === $skey ? 'active' : '' ?>"
                 href="?<?= http_build_query(array_filter(['type' => $type, 'status' => $skey, 'q' => $q !== '' ? $q : null])) ?>">
                <?= $slabel ?>
              </a>
            <?php endforeach; ?>
          </div>
          <form method="GET" class="filters-form">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <span class="search-box">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M19 11a8 8 0 11-16 0 8 8 0 0116 0z"/>
              </svg>
              <input type="text" name="q" placeholder="Search by name or requester&hellip;" value="<?= htmlspecialchars($q) ?>">
            </span>
            <select name="grade_level" class="filter-select" onchange="this.form.submit()">
              <option value="">All Grade Levels</option>
              <?php foreach ($valid_grade_levels as $g): ?>
                <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
              <?php endforeach; ?>
            </select>
            <select name="strand" class="filter-select" onchange="this.form.submit()">
              <option value="">All Strands</option>
              <?php foreach ($valid_strands as $s): ?>
                <option value="<?= $s ?>" <?= $f_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($f_grade !== '' || $f_strand !== ''): ?>
              <a class="clear-link"
                 href="?<?= http_build_query(array_filter(['type' => $type, 'status' => $status !== '' ? $status : null, 'q' => $q !== '' ? $q : null])) ?>">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <?php if (!$rows): ?>
          <div class="empty-state">No <?= strtolower($type_labels[$type]) ?> requests found.</div>
        <?php else: ?>
          <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Requested By</th>
                <?php if ($type === 'sections'): ?>
                  <th>Section</th><th>Grade &amp; Strand</th><th>Room</th><th>Capacity</th><th>School Year</th>
                <?php elseif ($type === 'curriculum'): ?>
                  <th>Subject</th><th>Grade &amp; Strand</th>
                <?php else: ?>
                  <th>Section</th><th>Grade &amp; Strand</th><th>School Year</th>
                <?php endif; ?>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td>
                    <?php if ($r['req_family']): ?>
                      <div class="td-name"><?= htmlspecialchars($r['req_given'] . ' ' . $r['req_family']) ?></div>
                      <div class="td-meta">Staff ID #<?= (int)$r['req_uid'] ?></div>
                    <?php else: ?>
                      <span class="td-meta">&mdash;</span>
                    <?php endif; ?>
                  </td>

                  <?php if ($type === 'sections'): ?>
                    <td class="td-name"><?= htmlspecialchars($r['section_name']) ?></td>
                    <td>Grade <?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                    <td><?= htmlspecialchars($r['room'] ?: '—') ?></td>
                    <td><?= (int)$r['capacity'] ?></td>
                    <td><?= htmlspecialchars($r['school_year']) ?></td>
                  <?php elseif ($type === 'curriculum'): ?>
                    <td class="td-name"><?= htmlspecialchars($r['subject_name']) ?></td>
                    <td>Grade <?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                  <?php else: ?>
                    <td class="td-name"><?= htmlspecialchars($r['section_name']) ?></td>
                    <td>Grade <?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                    <td><?= htmlspecialchars($r['school_year']) ?></td>
                  <?php endif; ?>

                  <td>
                    <span class="badge badge-<?= $r['status'] ?>"><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></span>
                    <?php if ($r['status'] === 'rejected' && $r['rejection_reason']): ?>
                      <div class="rej-reason"><?= htmlspecialchars($r['rejection_reason']) ?></div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ($r['status'] === 'pending' || $r['status'] === 'for_review'): ?>
                      <?php
                        $id_field  = $type === 'curriculum' ? 'subject_id' : 'section_id';
                        $id_value  = $type === 'curriculum' ? $r['subject_id'] : $r['section_id'];
                        $approve_name = $type === 'sections' ? 'approve_section' : ($type === 'curriculum' ? 'approve_subject' : 'approve_schedule');
                        $reject_name  = $type === 'sections' ? 'reject_section'  : ($type === 'curriculum' ? 'reject_subject'  : 'reject_schedule');
                        $item_name    = $type === 'curriculum' ? $r['subject_name'] : $r['section_name'];
                      ?>
                      <div class="action-btn-row">
                        <form method="POST" style="display:inline;"
                              data-confirm="Approve &quot;<?= htmlspecialchars($item_name) ?>&quot;?" data-icon="question">
                          <input type="hidden" name="<?= $id_field ?>" value="<?= (int)$id_value ?>">
                          <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                          <button type="submit" name="<?= $approve_name ?>" class="btn-toggle" style="color:#1A7A5E;border-color:#A8D9C5;">Approve</button>
                        </form>
                        <form method="POST" style="display:inline;" class="reject-form">
                          <input type="hidden" name="<?= $id_field ?>" value="<?= (int)$id_value ?>">
                          <input type="hidden" name="rejection_reason" value="">
                          <button type="button" class="btn-toggle js-reject" style="color:#C0392B;border-color:#F5C6C2;"
                                  data-name="<?= htmlspecialchars($item_name, ENT_QUOTES) ?>"
                                  data-reject-name="<?= $reject_name ?>">
                            Reject
                          </button>
                        </form>
                        <?php if ($type === 'schedule'): ?>
                          <a href="../scheduler/scheduling?section_id=<?= (int)$r['section_id'] ?>" class="btn-toggle" style="display:inline-block;text-decoration:none;">View</a>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <?php if ($type === 'schedule'): ?>
                        <a href="../scheduler/scheduling?section_id=<?= (int)$r['section_id'] ?>" class="btn-toggle" style="display:inline-block;text-decoration:none;">View</a>
                      <?php else: ?>
                        <span class="td-meta">Reviewed</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>

          <?php if ($total_pages > 1):
            $base_params = array_filter(['type' => $type, 'status' => $status !== '' ? $status : null, 'q' => $q !== '' ? $q : null, 'grade_level' => $f_grade !== '' ? $f_grade : null, 'strand' => $f_strand !== '' ? $f_strand : null]);
          ?>
            <div class="app-pagination">
              <span>Page <?= $page ?> of <?= $total_pages ?> &middot; <?= $total_count ?> total</span>
              <div class="pages">
                <?php if ($page > 1): ?>
                  <a href="?<?= http_build_query(array_merge($base_params, ['page' => $page - 1])) ?>">&larr;</a>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                  <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                  <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($base_params, ['page' => $p])) ?>"><?= $p ?></a>
                  <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                  <a href="?<?= http_build_query(array_merge($base_params, ['page' => $page + 1])) ?>">&rarr;</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <script>
    const sidebarEl     = document.getElementById('staffSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarEl && sidebarToggle) {
      sidebarToggle.addEventListener('click', () => sidebarEl.classList.toggle('open'));
    }

    // Reject-with-reason: SWAL textarea (required for subjects/schedules, optional for sections)
    document.querySelectorAll('.js-reject').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('.reject-form');
        const name = btn.dataset.name;
        const required = btn.dataset.rejectName === 'reject_subject' || btn.dataset.rejectName === 'reject_schedule';
        Swal.fire({
          title: 'Reject "' + name + '"?',
          input: 'textarea',
          inputPlaceholder: required ? 'Reason (required)' : 'Reason (optional)',
          inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#C0392B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Reject',
          cancelButtonText: 'Cancel',
          inputValidator: (value) => {
            if (required && (!value || !value.trim())) return 'A reason is required.';
          }
        }).then((result) => {
          if (!result.isConfirmed) return;
          form.querySelector('input[name="rejection_reason"]').value = (result.value || '').trim();
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = btn.dataset.rejectName;
          hidden.value = '1';
          form.appendChild(hidden);
          form.submit();
        });
      });
    });
  </script>

</body>
</html>