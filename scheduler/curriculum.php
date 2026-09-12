<?php
/* ============================================================
   curriculum.php — Subject Curriculum Management
   ------------------------------------------------------------
   Access model (mirrors sections.php):
     - role='admin'                         -> reviewer (approve/reject),
                                                same as coordinator, plus
                                                can always reach this page
     - staff, department='coordinator'       -> reviewer (approve/reject)
     - staff, department='scheduler'         -> proposer (add subjects,
                                                which land as 'for_review'
                                                unless a reviewer is the
                                                one submitting)
   Everyone who can reach this page sees the same list; reviewer-only
   controls (Approve/Reject) simply don't render for a scheduler.
   Toggle/Delete are NOT gated beyond page access, same precedent as
   sections.php's Deactivate/Reactivate/Generate Subjects.
   ============================================================ */

session_start();
include_once '../config.php';
include_once '../notify.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
guard_password_change('../staff/staff_change_password');

$role       = $_SESSION['role']       ?? '';
$department = $_SESSION['department'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$is_admin     = ($role === 'admin');
$is_reviewer  = $is_admin || ($role === 'staff' && $department === 'coordinator');
$is_scheduler = ($role === 'staff' && $department === 'scheduler');

if (!$is_reviewer && !$is_scheduler) {
    header("Location: ../staff/staff_dashboard"); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$success = '';
$error   = '';

// ── AJAX: enrolled/pending students taking a subject ───────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'students' && isset($_GET['subject_id'])) {
    header('Content-Type: application/json');
    $subid = (int)$_GET['subject_id'];
    $res  = $conn->prepare("
        SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, st.strand_code AS strand,
               s.family_name, s.given_name, s.student_number, sec.section_name
        FROM enrollment_subjects es
        JOIN enrollments e ON e.enrollment_id = es.enrollment_id
        JOIN students s    ON s.student_id   = e.student_id
        JOIN strands st    ON st.strand_id   = e.admission_strand
        LEFT JOIN sections sec ON sec.section_id = es.section_id
        WHERE es.subject_id = ? AND e.status IN ('pending','enrolled')
        ORDER BY s.family_name, s.given_name
    ");
    $res->bind_param('i', $subid);
    $res->execute();
    $data = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
    $conn->close();
    exit();
}

// ── Valid grade levels / strands ────────────────────────────────────────────
$valid_grade_levels = ['11', '12'];
$valid_strands      = ['CORE', 'STEM', 'HUMSS', 'ABM', 'GAS', 'TVL'];

// ── School years — every distinct year already used by a subject, plus
// whichever year is currently open, so a brand-new year still shows up in
// the picker even before any subject has been tagged with it yet. ─────────
$all_years = [];
$sy_res = mysqli_query($conn, "
    SELECT school_year FROM subjects
    UNION
    SELECT school_year FROM school_year_settings
    ORDER BY school_year DESC
");
if ($sy_res) { while ($r = mysqli_fetch_row($sy_res)) { $all_years[] = $r[0]; } }

$default_year_res = mysqli_query($conn, "
    SELECT school_year FROM school_year_settings
    WHERE is_admission_open = 1 OR is_enrollment_open = 1
    ORDER BY school_year DESC LIMIT 1
");
$default_year = $default_year_res && ($r = mysqli_fetch_row($default_year_res)) ? $r[0] : ($all_years[0] ?? date('Y') . '-' . (date('Y') + 1));

// Preserve current filters/page across every redirect (PRG pattern, same as
// pending_applications.php's $return_qs).
$f_grade  = in_array($_GET['grade_level'] ?? '', $valid_grade_levels, true) ? $_GET['grade_level'] : '';
$f_strand = in_array($_GET['strand'] ?? '', $valid_strands, true) ? $_GET['strand'] : '';
$f_review = $_GET['review_status'] ?? '';
if (!in_array($f_review, ['approved', 'for_review', 'rejected'], true)) $f_review = '';
$f_year   = isset($_GET['school_year']) && in_array($_GET['school_year'], $all_years, true) ? $_GET['school_year'] : $default_year;
$page     = max(1, (int)($_GET['page'] ?? 1));
$f_source_year = isset($_GET['source_year']) && in_array($_GET['source_year'], $all_years, true) ? $_GET['source_year'] : '';

$qs_params = array_filter([
    'grade_level'   => $f_grade,
    'strand'        => $f_strand,
    'review_status' => $f_review,
    'school_year'   => $f_year !== $default_year ? $f_year : null,
    'source_year'   => $f_source_year,
    'page'          => $page > 1 ? $page : null,
]);
$return_qs = http_build_query($qs_params);

// ── Propose / Create Subject POST ───────────────────────────────────────────
// A reviewer (coordinator/admin) creating a subject here auto-approves it,
// same as sections.php's admin-created sections. A scheduler's submission
// always lands as 'for_review' and waits on a reviewer.
//
// NOTE: no "units" field — that's a college-registrar concept, not part of
// the DepEd SHS curriculum model this system follows. No semester either —
// SHS students enroll once per school year, so a subject is offered for the
// whole year, not split across terms.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $grade_level  = trim($_POST['grade_level']  ?? '');
    $strand       = trim($_POST['strand']        ?? '');

    if (!$subject_name || !$grade_level || !$strand) {
        $error = 'Subject name, grade level, and strand are required.';
    } elseif (!in_array($grade_level, $valid_grade_levels, true)) {
        $error = 'Please select a valid grade level.';
    } elseif (!in_array($strand, $valid_strands, true)) {
        $error = 'Please select a valid strand.';
    } else {
        $review_status = $is_reviewer ? 'approved' : 'for_review';
        $proposed_by   = $is_reviewer ? null : $user_id;
        $approved_by   = $is_reviewer ? $user_id : null;
        $approved_at   = $is_reviewer ? date('Y-m-d H:i:s') : null;
        $strandId      = strand_id($conn, $strand);

        $stmt = $conn->prepare(
            "INSERT INTO subjects (subject_name, grade_level, strand, is_active, review_status, proposed_by, approved_by, approved_at, school_year)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssisiiss', $subject_name, $grade_level, $strandId, $review_status, $proposed_by, $approved_by, $approved_at, $f_year);
        if ($stmt->execute()) {
            $success = $is_reviewer
                ? "Subject \"$subject_name\" created and approved."
                : "Subject \"$subject_name\" submitted for coordinator review.";
            if (!$is_reviewer) {
                notify_coordinators($conn, "New subject \"$subject_name\" awaiting review.", 'coordinator/approvals?type=curriculum');
            }
        } elseif ($conn->errno === 1062) {
            $error = 'A subject with this name, grade level, and strand already exists.';
        } else {
            $error = 'Could not submit subject. Please try again.';
        }
        $stmt->close();
    }
}

// ── Approve a pending subject (REVIEWER-ONLY: coordinator or admin) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can approve a proposed subject.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $info_stmt = $conn->prepare("SELECT subject_name, proposed_by FROM subjects WHERE subject_id = ?");
        $info_stmt->bind_param('i', $subid);
        $info_stmt->execute();
        $subj_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare(
            "UPDATE subjects SET review_status='approved', approved_by=?, approved_at=NOW(), rejection_reason=NULL
             WHERE subject_id=? AND review_status='for_review'"
        );
        $stmt->bind_param('ii', $user_id, $subid);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok && $affected > 0) {
            if ($subj_row && $subj_row['proposed_by']) {
                notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was approved.", 'scheduler/curriculum');
            }
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not approve — it may have already been reviewed.';
    }
}

// ── Reject a pending subject (REVIEWER-ONLY: coordinator or admin) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can reject a proposed subject.';
    } else {
        $subid  = (int)($_POST['subject_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) {
            $error = 'Please provide a reason for rejecting this subject.';
        } else {
            $info_stmt = $conn->prepare("SELECT subject_name, proposed_by FROM subjects WHERE subject_id = ?");
            $info_stmt->bind_param('i', $subid);
            $info_stmt->execute();
            $subj_row = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            $stmt = $conn->prepare(
                "UPDATE subjects SET review_status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=?
                 WHERE subject_id=? AND review_status='for_review'"
            );
            $stmt->bind_param('isi', $user_id, $reason, $subid);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($ok && $affected > 0) {
                if ($subj_row && $subj_row['proposed_by']) {
                    notify_users($conn, [$subj_row['proposed_by']], "Subject \"{$subj_row['subject_name']}\" was rejected.", 'scheduler/curriculum');
                }
                header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
            }
            $error = 'Could not reject — it may have already been reviewed.';
        }
    }
}

// ── Toggle active status — coordinator/admin only ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can change a subject\'s active status.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $cur   = (int)($_POST['is_active']  ?? 1);
        $new   = $cur ? 0 : 1;
        $stmt  = $conn->prepare("UPDATE subjects SET is_active=? WHERE subject_id=?");
        $stmt->bind_param('ii', $new, $subid);
        if ($stmt->execute()) {
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not update subject status. Please try again.';
        $stmt->close();
    }
}

// ── Delete (soft) subject — coordinator/admin only ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    if (!$is_reviewer) {
        $error = 'Only a coordinator or admin can delete a subject.';
    } else {
        $subid = (int)($_POST['subject_id'] ?? 0);
        $stmt  = $conn->prepare("UPDATE subjects SET is_active = 0 WHERE subject_id = ?");
        $stmt->bind_param('i', $subid);
        if ($stmt->execute()) {
            header("Location: curriculum" . ($return_qs !== '' ? '?' . $return_qs : '')); exit();
        }
        $error = 'Could not delete subject. Please try again.';
        $stmt->close();
    }
}

// ── Carry selected subjects into the currently-viewed year ─────────────────
// Unlike the old all-or-nothing copy, this only inserts the specific
// subject_ids the user checked off (re-validated server-side against
// source_year/is_active/review_status — never trust the posted IDs blindly).
// Reviewer submissions auto-approve, same as add_subject above; a scheduler's
// carried-over subjects still land as 'for_review' just like a manual
// proposal. INSERT IGNORE against the (subject_name, grade_level, strand,
// school_year) unique key makes re-submitting harmless.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_copy_subjects'])) {
    $source_year = trim($_POST['source_year'] ?? '');
    $picked_ids  = array_filter(array_map('intval', $_POST['subject_id'] ?? []));

    if (!in_array($source_year, $all_years, true) || $source_year === $f_year) {
        $error = 'Please choose a different source school year.';
    } elseif (empty($picked_ids)) {
        $error = 'Please select at least one subject to carry over.';
    } else {
        $ph = implode(',', array_fill(0, count($picked_ids), '?'));
        $verify_stmt = $conn->prepare("
            SELECT subject_id FROM subjects
            WHERE subject_id IN ($ph) AND school_year = ? AND is_active = 1 AND review_status = 'approved'
        ");
        $verify_stmt->bind_param(str_repeat('i', count($picked_ids)) . 's', ...[...$picked_ids, $source_year]);
        $verify_stmt->execute();
        $confirmed_ids = array_map(fn($r) => (int)$r['subject_id'], $verify_stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $verify_stmt->close();

        if (empty($confirmed_ids)) {
            $error = 'None of the selected subjects could be found in that school year.';
        } else {
            $review_status = $is_reviewer ? 'approved' : 'for_review';
            $proposed_by   = $is_reviewer ? null : $user_id;
            $approved_by   = $is_reviewer ? $user_id : null;
            $approved_at   = $is_reviewer ? date('Y-m-d H:i:s') : null;

            $ph2 = implode(',', array_fill(0, count($confirmed_ids), '?'));
            $copy_stmt = $conn->prepare("
                INSERT IGNORE INTO subjects
                    (subject_name, grade_level, strand, is_active, review_status, proposed_by, approved_by, approved_at, school_year)
                SELECT subject_name, grade_level, strand, 1, ?, ?, ?, ?, ?
                FROM subjects
                WHERE subject_id IN ($ph2)
            ");
            $copy_stmt->bind_param('siiss' . str_repeat('i', count($confirmed_ids)), $review_status, $proposed_by, $approved_by, $approved_at, $f_year, ...$confirmed_ids);
            if ($copy_stmt->execute()) {
                $copied = $copy_stmt->affected_rows;
                $copy_stmt->close();
                $success = $copied > 0
                    ? ($is_reviewer
                        ? "$copied subject(s) added to SY $f_year."
                        : "$copied subject(s) submitted for coordinator review in SY $f_year.")
                    : 'Those subjects are already part of this school year.';
                if (!$is_reviewer && $copied > 0) {
                    notify_coordinators($conn, "$copied subject(s) carried into SY $f_year awaiting review.", 'coordinator/approvals?type=curriculum');
                }
            } else {
                $error = 'Could not carry over subjects. Please try again.';
                $copy_stmt->close();
            }
        }
    }
}

// ── Filters (WHERE) ──────────────────────────────────────────────────────────
$where  = ['1=1'];
$types  = '';
$params = [];

if ($f_grade !== '')  { $where[] = 's.grade_level = ?';    $types .= 's'; $params[] = $f_grade; }
if ($f_strand !== '') { $where[] = 's.strand = ?';         $types .= 'i'; $params[] = strand_id($conn, $f_strand); }
if ($f_review !== '') { $where[] = 's.review_status = ?';  $types .= 's'; $params[] = $f_review; }
$where[] = 's.school_year = ?'; $types .= 's'; $params[] = $f_year;
$where_sql = implode(' AND ', $where);

// ── Total count (for pagination) ────────────────────────────────────────────
$per_page = 15;
$count_sql = "SELECT COUNT(*) AS c FROM subjects s WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_count = (int)$count_stmt->get_result()->fetch_assoc()['c'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_count / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// ── Fetch subjects (current page) ───────────────────────────────────────────
$sql = "
    SELECT s.subject_id, s.subject_name, s.grade_level, st.strand_code AS strand,
           s.is_active, s.review_status, s.rejection_reason,
           COUNT(DISTINCT CASE WHEN e.status IN ('pending','enrolled') THEN e.enrollment_id END) AS taking
    FROM subjects s
    JOIN strands st ON st.strand_id = s.strand
    LEFT JOIN enrollment_subjects es ON es.subject_id = s.subject_id
    LEFT JOIN enrollments e ON e.enrollment_id = es.enrollment_id
    WHERE $where_sql
    GROUP BY s.subject_id
    ORDER BY (s.review_status = 'for_review') DESC, s.grade_level ASC, s.strand ASC, s.subject_name ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$list_types  = $types . 'ii';
$list_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Pending count — shown to reviewers as a heads-up banner (mirrors
// sections.php's $pending_count, which is admin-only there too).
$pending_count = 0;
if ($is_reviewer) {
    $pc = mysqli_query($conn, "SELECT COUNT(*) AS c FROM subjects WHERE review_status = 'for_review'");
    $pending_count = $pc ? (int)mysqli_fetch_assoc($pc)['c'] : 0;
}

$filter_labels = [
    ''           => 'All',
    'approved'   => 'Approved',
    'for_review' => 'Pending',
    'rejected'   => 'Rejected',
];

// ── Carry Over Subjects panel data ──────────────────────────────────────────
// Defaults to the next-most-recent year after the one currently being viewed
// (same "most likely prior year" default the old copy-curriculum form used).
$other_years = array_values(array_diff($all_years, [$f_year]));
$carry_source_year = $f_source_year !== '' ? $f_source_year : ($other_years[0] ?? '');
$carry_rows = [];
if ($carry_source_year !== '') {
    $carry_stmt = $conn->prepare("
        SELECT s.subject_id, s.subject_name, s.grade_level, st.strand_code AS strand
        FROM subjects s
        JOIN strands st ON st.strand_id = s.strand
        WHERE s.school_year = ? AND s.is_active = 1 AND s.review_status = 'approved'
          AND NOT EXISTS (
              SELECT 1 FROM subjects d
              WHERE d.school_year = ? AND d.subject_name = s.subject_name
                AND d.grade_level = s.grade_level AND d.strand = s.strand
          )
        ORDER BY s.grade_level ASC, s.strand ASC, s.subject_name ASC
    ");
    $carry_stmt->bind_param('ss', $carry_source_year, $f_year);
    $carry_stmt->execute();
    $carry_rows = $carry_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $carry_stmt->close();
}

// NOTE: do NOT close $conn here — staff_sidebar.php (included below, in the
// HTML body) runs its own queries for nav badges/status and needs the
// connection still open. Let PHP close it automatically at script end.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Curriculum — <?= $is_reviewer ? 'Coordinator' : 'Scheduler' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/css_staff.css?v=<?= filemtime(__DIR__ . '/../css/css_staff.css') ?>">
  <style>
    /* Supplemental styles — same conventions as sections.php, so the two
       pages feel like one dashboard rather than two different apps. */
    .filter-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:.75rem; }
    .filter-tab {
      font-size:12px; font-weight:500; padding:6px 12px; border-radius:20px;
      border:0.5px solid #D4D4E0; background:#fff; color:#5A5A72;
      text-decoration:none; white-space:nowrap; transition:all .12s;
    }
    .filter-tab:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .filter-tab.active { background:var(--brand-primary); border-color:var(--brand-primary); color:#fff; }

    .select-bar { display:flex; gap:8px; margin-bottom:1rem; flex-wrap:wrap; }
    .select-bar select {
      height:32px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#FAFAFC; padding:0 10px; font-size:12px; font-family:inherit; color:#1A1A2E;
    }
    .select-bar a.clear-link { font-size:12px; color:#8A8A9A; text-decoration:none; align-self:center; }
    .select-bar a.clear-link:hover { color:#C0392B; }

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

    .btn-toggle {
      height:26px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:6px;
      background:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      color:#5A5A72; margin:2px 4px 2px 0;
    }
    .btn-toggle:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .btn-approve { color:#1A7A5E; border-color:#A8D9C5; }
    .btn-reject  { color:#C0392B; border-color:#F5C6C2; }

    .rej-reason { font-size:11px; color:#C0392B; margin-top:2px; }

    .form-group { margin-bottom:.85rem; }
    .form-group label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.3rem; }
    .form-group input, .form-group select { width:100%; height:38px; border:0.5px solid #D4D4E0;
      border-radius:8px; background:#FAFAFC; padding:0 12px; font-size:13px;
      font-family:inherit; color:#1A1A2E; outline:none; }
    .form-group input:focus, .form-group select:focus {
      border-color:var(--brand-accent); box-shadow:0 0 0 3px rgba(47,107,79,.14); background:#fff; }
    .btn-add { width:100%; height:38px; background:var(--brand-primary); border:none; border-radius:8px;
      color:#fff; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:.2rem; }
    .btn-add:hover { background:var(--brand-primary-hover); }

    .two-col { display:grid; grid-template-columns:1fr 320px; gap:14px; align-items:start; }
    .full-col { display:block; }
    .full-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
    /* css_staff.css's .panel is built for centered single-column public pages
       (max-width:760px; margin:auto). Inside this wider two-column dashboard
       grid that centering just eats space on both sides — neutralize it so
       each panel fills its grid column instead. Same fix as sections.php. */
    .two-col .panel { max-width:none; margin-left:0; margin-right:0; width:100%; }
    .card-title { font-size:13px; font-weight:600; color:#1A1A2E; }
    .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.9rem; }
    .empty-state { color:#8A8A9A; font-size:13px; padding:1rem 0; }
    tr.clickable { cursor:pointer; }
    tr.clickable:hover td { background:#FAFAFC; }

    /* ── Pagination (matches pending_applications.php) ─────────────────── */
    .curr-pagination { display:flex; align-items:center; justify-content:space-between; margin-top:1rem; font-size:12.5px; color:#5A5A72; }
    .curr-pagination .pages { display:flex; gap:4px; }
    .curr-pagination a, .curr-pagination span.current {
      display:inline-flex; align-items:center; justify-content:center;
      width:30px; height:30px; border-radius:6px; text-decoration:none; font-size:12.5px;
    }
    .curr-pagination a { color:#5A5A72; border:0.5px solid #E0E0EC; }
    .curr-pagination a:hover { border-color:#ADADBD; }
    .curr-pagination span.current { background:var(--brand-primary); color:#fff; font-weight:600; }

    /* Modal (same pattern used across the app) */
    dialog#subjectModal { border:none; border-radius:12px; padding:1.5rem;
      width:min(560px, 92vw); max-height:80vh; overflow-y:auto;
      box-shadow:0 8px 40px rgba(0,0,0,.22);
      position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); margin:0; }
    dialog#subjectModal::backdrop { background:rgba(0,0,0,.45); }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .modal-title { font-size:15px; font-weight:600; color:#1A1A2E; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer;
      color:#8A8A9A; line-height:1; padding:0 4px; }
    .modal-close:hover { color:#1A1A2E; }
    .modal-meta { font-size:12px; color:#8A8A9A; margin-bottom:1rem; }
    .student-list { border-collapse:collapse; width:100%; font-size:13px; }
    .student-list th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:5px 8px 5px 0; border-bottom:0.5px solid #EBEBF0; }
    .student-list td { padding:8px 8px 8px 0; border-bottom:0.5px solid #F5F5F7; }
    .student-list tr:last-child td { border-bottom:none; }
    .badge-enrolled  { background:#EBF7F2; color:#1A7A5E; }
    .badge-pending2  { background:#FFF4E6; color:#C06A10; }
    .reject-form { display:flex; gap:6px; align-items:center; margin-top:4px; }
    .reject-form input[type=text] { height:26px; font-size:11px; border:0.5px solid #D4D4E0;
      border-radius:6px; padding:0 8px; font-family:inherit; }
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
          Curriculum
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
      <h1 class="page-title">Curriculum</h1>
      <p class="page-sub"><?= $is_reviewer ? 'Review proposed subjects and manage the active curriculum.' : 'Propose subjects, track coordinator approval, and manage the active curriculum.' ?></p>

      <?php if ($success): ?><div class="notice notice-info"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($is_reviewer && $pending_count > 0 && $f_review !== 'for_review'): ?>
        <div class="notice notice-info">
          <?= $pending_count ?> subject<?= $pending_count === 1 ? '' : 's' ?> awaiting your approval.
          <a href="?review_status=for_review">Review now</a>
        </div>
      <?php endif; ?>

      <div class="<?= $is_reviewer ? 'full-col' : 'two-col' ?>">

        <!-- Subjects list -->
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Subjects (<?= $total_count ?>)</span>
          </div>

          <div class="filter-row">
            <?php foreach ($filter_labels as $key => $label):
              $tab_params = array_filter(['grade_level' => $f_grade, 'strand' => $f_strand, 'review_status' => $key]);
            ?>
              <a class="filter-tab <?= $f_review === $key ? 'active' : '' ?>" href="?<?= http_build_query($tab_params) ?>">
                <?= $label ?><?= ($key === 'for_review' && $is_reviewer && $pending_count > 0) ? " ($pending_count)" : '' ?>
              </a>
            <?php endforeach; ?>
          </div>

          <form method="GET" class="select-bar">
            <?php if ($f_review !== ''): ?><input type="hidden" name="review_status" value="<?= htmlspecialchars($f_review) ?>"><?php endif; ?>
            <select name="school_year" onchange="this.form.submit()">
              <?php foreach ($all_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $f_year === $y ? 'selected' : '' ?>>
                  SY <?= htmlspecialchars($y) ?><?= $y === $default_year ? ' (current)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="grade_level" onchange="this.form.submit()">
              <option value="">All Grade Levels</option>
              <?php foreach ($valid_grade_levels as $g): ?>
                <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
              <?php endforeach; ?>
            </select>
            <select name="strand" onchange="this.form.submit()">
              <option value="">All Strands</option>
              <?php foreach ($valid_strands as $s): ?>
                <option value="<?= $s ?>" <?= $f_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($f_grade || $f_strand): ?>
              <a class="clear-link" href="?<?= http_build_query(array_filter(['review_status' => $f_review, 'school_year' => $f_year !== $default_year ? $f_year : null])) ?>">Clear</a>
            <?php endif; ?>
          </form>

          <?php if (empty($rows)): ?>
            <p class="empty-state">No subjects found for this filter.</p>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Subject</th>
                  <th>Grade / Strand</th>
                  <th>Taking</th>
                  <th>Status</th>
                  <th>Review</th>
                  <th><?= $is_reviewer ? 'Action' : '' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r):
                  $subid  = (int)$r['subject_id'];
                  $modalArgs = $subid . ', ' . htmlspecialchars(json_encode($r['subject_name']));
                  $reviewBadge = [
                    'approved'   => ['badge-approved', 'Approved'],
                    'for_review' => ['badge-pending', 'Pending'],
                    'rejected'   => ['badge-rejected', 'Rejected'],
                  ][$r['review_status']];
                ?>
                  <tr class="clickable" tabindex="0"
                      onclick="openModal(<?= $modalArgs ?>)"
                      onkeydown="if(event.key==='Enter'||event.key===' ')openModal(<?= $modalArgs ?>)">
                    <td class="td-name">
                      <?= htmlspecialchars($r['subject_name']) ?>
                      <?php if ($r['review_status'] === 'rejected' && $r['rejection_reason']): ?>
                        <div class="rej-reason">Reason: <?= htmlspecialchars($r['rejection_reason']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                    <td><?= (int)$r['taking'] ?></td>
                    <td>
                      <?php if ($r['is_active']): ?>
                        <span class="badge badge-active">Active</span>
                      <?php else: ?>
                        <span class="badge badge-inactive">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $reviewBadge[0] ?>"><?= $reviewBadge[1] ?></span></td>
                    <?php
                      $toggle_msg = ($r['is_active'] ? 'Deactivate' : 'Activate') . ' subject "' . $r['subject_name'] . '"?';
                      $delete_msg = 'Delete subject "' . $r['subject_name'] . '"? Students who already completed or are taking it will be unaffected, but it will no longer be assignable to new sections.';
                    ?>
                    <td onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">
                      <?php if ($r['review_status'] === 'for_review' && $is_reviewer): ?>
                        <form method="POST" style="display:inline;"
                              data-confirm="Approve subject &quot;<?= htmlspecialchars($r['subject_name']) ?>&quot;?" data-icon="question">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <button type="submit" name="approve_subject" class="btn-toggle btn-approve">Approve</button>
                        </form>
                        <form method="POST" style="display:inline;" class="reject-form">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <input type="hidden" name="rejection_reason" value="">
                          <button type="button" class="btn-toggle btn-reject js-reject"
                                  data-name="<?= htmlspecialchars($r['subject_name'], ENT_QUOTES) ?>">Reject</button>
                        </form>
                      <?php elseif ($r['review_status'] === 'for_review'): ?>
                        <span class="td-meta">Awaiting coordinator review</span>
                      <?php elseif ($is_reviewer): ?>
                        <form method="POST" style="display:inline;"
                              data-confirm="<?= htmlspecialchars($toggle_msg) ?>" data-icon="question">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <input type="hidden" name="is_active"  value="<?= (int)$r['is_active'] ?>">
                          <button type="submit" name="toggle_subject" class="btn-toggle">
                            <?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>
                          </button>
                        </form>
                        <form method="POST" style="display:inline;"
                              data-confirm="<?= htmlspecialchars($delete_msg) ?>" data-icon="warning">
                          <input type="hidden" name="subject_id" value="<?= $subid ?>">
                          <button type="submit" name="delete_subject" class="btn-toggle" style="color:#C0392B;border-color:#F5C6C2;">
                            Delete
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <?php if ($total_pages > 1):
              $base_params = array_filter(['grade_level' => $f_grade, 'strand' => $f_strand, 'review_status' => $f_review]);
            ?>
              <div class="curr-pagination">
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

        <!-- Propose New Subject form — schedulers only; coordinators/admin don't create subjects here -->
        <?php if (!$is_reviewer): ?>
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Propose New Subject</span>
          </div>
          <p class="td-meta" style="margin-bottom:.75rem;">Submitted subjects need coordinator approval before they can be assigned to sections.</p>
          <form method="POST"
                data-confirm="Submit this subject for coordinator approval?"
                data-icon="question">
            <div class="form-group">
              <label for="f_subject_name">Subject Name <span style="color:#C0392B">*</span></label>
              <input id="f_subject_name" type="text" name="subject_name" placeholder="e.g. Empowerment Technologies"
                     value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label for="f_grade_level">Grade Level <span style="color:#C0392B">*</span></label>
              <select id="f_grade_level" name="grade_level" required>
                <option value="">Select</option>
                <?php foreach ($valid_grade_levels as $g): ?>
                  <option value="<?= $g ?>" <?= ($_POST['grade_level'] ?? '') === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                <?php endforeach; ?>
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
            <button type="submit" name="add_subject" class="btn-add">
              Submit for Approval
            </button>
          </form>
        </div>
        <?php endif; ?>

      </div>

      <?php if (count($all_years) > 1): ?>
      <div class="full-col">
        <div class="panel">
          <div class="card-header">
            <span class="card-title">Carry Over Subjects into SY <?= htmlspecialchars($f_year) ?></span>
          </div>
          <p class="td-meta" style="margin-bottom:.75rem;">Check the subjects to bring into SY <?= htmlspecialchars($f_year) ?> — no need to retype them. <?= $is_reviewer ? 'Carried-over subjects are added directly as approved.' : 'Carried-over subjects still need coordinator approval, same as a manual proposal.' ?></p>

          <form method="GET" class="select-bar">
            <?php if ($f_review !== ''): ?><input type="hidden" name="review_status" value="<?= htmlspecialchars($f_review) ?>"><?php endif; ?>
            <?php if ($f_year !== $default_year): ?><input type="hidden" name="school_year" value="<?= htmlspecialchars($f_year) ?>"><?php endif; ?>
            <span class="td-meta" style="align-self:center;">Copy from:</span>
            <select name="source_year" onchange="this.form.submit()">
              <?php foreach ($other_years as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $carry_source_year === $y ? 'selected' : '' ?>>SY <?= htmlspecialchars($y) ?></option>
              <?php endforeach; ?>
            </select>
          </form>

          <?php if (empty($carry_rows)): ?>
            <p class="empty-state">All of SY <?= htmlspecialchars($carry_source_year) ?>'s subjects are already in SY <?= htmlspecialchars($f_year) ?>.</p>
          <?php else: ?>
            <form method="POST" data-confirm="Carry the selected subjects into SY <?= htmlspecialchars($f_year, ENT_QUOTES) ?>?" data-icon="question">
              <input type="hidden" name="source_year" value="<?= htmlspecialchars($carry_source_year) ?>">
              <table class="data-table">
                <thead>
                  <tr>
                    <th style="width:28px;"><input type="checkbox" id="carrySelectAll"></th>
                    <th>Subject</th>
                    <th>Grade / Strand</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($carry_rows as $cr): ?>
                    <tr>
                      <td><input type="checkbox" class="carry-checkbox" name="subject_id[]" value="<?= (int)$cr['subject_id'] ?>"></td>
                      <td class="td-name"><?= htmlspecialchars($cr['subject_name']) ?></td>
                      <td>G<?= htmlspecialchars($cr['grade_level']) ?> &middot; <?= htmlspecialchars($cr['strand']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <button type="submit" name="bulk_copy_subjects" class="btn-toggle" style="margin-top:.85rem;">Add Selected to SY <?= htmlspecialchars($f_year) ?></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Drill-down Modal: students currently taking this subject -->
  <dialog id="subjectModal" aria-labelledby="modalTitle">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Subject Enrollees</span>
      <button class="modal-close" aria-label="Close"
              onclick="document.getElementById('subjectModal').close()">&times;</button>
    </div>
    <div class="modal-meta" id="modalMeta"></div>
    <div id="modalBody">
      <p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>
    </div>
  </dialog>

  <script>
    // Carry Over Subjects — "select all" checkbox
    const carrySelectAll = document.getElementById('carrySelectAll');
    if (carrySelectAll) {
      carrySelectAll.addEventListener('change', () => {
        document.querySelectorAll('.carry-checkbox').forEach(cb => { cb.checked = carrySelectAll.checked; });
      });
    }

    // Sidebar toggle (defensive: only wires up if the sidebar markup exists)
    const sidebarEl     = document.getElementById('staffSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarEl && sidebarToggle) {
      sidebarToggle.addEventListener('click', () => sidebarEl.classList.toggle('open'));
    }

    // Reject-with-reason: SWAL textarea (required), then submit
    document.querySelectorAll('.js-reject').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('.reject-form');
        const name = btn.dataset.name;
        Swal.fire({
          title: 'Reject "' + name + '"?',
          input: 'textarea',
          inputPlaceholder: 'Reason (required)',
          inputAttributes: { style: 'resize:vertical;max-width:100%;box-sizing:border-box;' },
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#C0392B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Reject',
          cancelButtonText: 'Cancel',
          inputValidator: (value) => {
            if (!value || !value.trim()) return 'A reason is required.';
          }
        }).then((result) => {
          if (!result.isConfirmed) return;
          form.querySelector('input[name="rejection_reason"]').value = result.value.trim();
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'reject_subject';
          hidden.value = '1';
          form.appendChild(hidden);
          form.submit();
        });
      });
    });

    const dlg = document.getElementById('subjectModal');

    dlg.addEventListener('click', function(e) {
      const rect = dlg.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right ||
          e.clientY < rect.top  || e.clientY > rect.bottom) {
        dlg.close();
      }
    });

    function openModal(subjectId, subjectName) {
      document.getElementById('modalTitle').textContent = subjectName;
      document.getElementById('modalMeta').textContent  = 'Students currently enrolled in or taking this subject';
      document.getElementById('modalBody').innerHTML    = '<p style="color:#8A8A9A;font-size:13px;">Loading&hellip;</p>';
      dlg.showModal();

      fetch('curriculum?action=students&subject_id=' + subjectId)
        .then(r => r.json())
        .then(data => {
          if (!data.length) {
            document.getElementById('modalBody').innerHTML = '<p style="color:#8A8A9A;font-size:13px;text-align:center;padding:1.5rem 0;">No students currently taking this subject.</p>';
            return;
          }
          let html = '<table class="student-list"><thead><tr><th>#</th><th>Name</th><th>Student Number</th><th>Section</th><th>Status</th></tr></thead><tbody>';
          data.forEach((s, i) => {
            const badgeCls = s.status === 'enrolled' ? 'badge-enrolled' : 'badge-pending2';
            html += '<tr>'
              + '<td style="color:#8A8A9A;font-size:12px;">' + (i+1) + '</td>'
              + '<td style="font-weight:500">' + esc(s.family_name) + ', ' + esc(s.given_name) + '</td>'
              + '<td style="font-family:monospace;font-size:12px;color:var(--brand-primary)">' + (s.student_number || '—') + '</td>'
              + '<td style="font-size:12px;color:#5A5A72">' + esc(s.section_name || '—') + '</td>'
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