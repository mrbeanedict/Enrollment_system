<?php
// Records — outstanding-document view for already-ENROLLED students.
// records/document_review.php covers pre-admission applicants
// (enrollments.status = 'pending') and stops the moment a student is
// admitted; this page picks up from there, so an enrolled student who
// still owes a document (e.g. the 2x2 Picture, or a re-upload after a
// rejection) has somewhere to be tracked and verified. Same
// enrollment_requirements/requirement_types tables and the same
// checkbox/radio verify UI as document_review.php — no admission logic
// here at all (no control number, no admission_status change), since
// these students are already admitted.
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../config/mail.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/login");
    exit();
}
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = ($_SESSION['role'] ?? '') === 'admin';
if (!$is_admin && ($_SESSION['department'] ?? '') !== 'records') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access denied</title>
          <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=' . filemtime(__DIR__ . '/../../../assets/css/css_staff.css') . '"></head><body>
          <div class="card" style="max-width:480px;margin:4rem auto;">
            <h2>Access denied</h2>
            <p>Only the Records department can view student accountabilities.</p>
            <a href="<?= APP_URL ?>/roles/staff/dashboard">&larr; Back to dashboard</a>
          </div></body></html>';
    exit();
}

$allowed_grades  = ['11', '12'];
$allowed_strands = ['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL-ICT'];
$allowed_req_statuses = ['pending', 'pending_review', 'submitted', 'rejected'];

$flash      = $_SESSION['ac_flash']      ?? '';
$flash_type = $_SESSION['ac_flash_type'] ?? 'info';
unset($_SESSION['ac_flash'], $_SESSION['ac_flash_type']);

// Applicable requirement rows for one enrollment (respecting the
// public-JHS-only gate) — same helper shape as document_review.php's
// dr_load_requirements(), used by the follow-up email handler below.
function ac_load_requirements(mysqli $conn, int $enrollment_id, int $jhs_is_public): array {
    $stmt = $conn->prepare("
        SELECT er.enrollment_requirement_id, er.requirement_type_id, er.status,
               rt.requirement_name, rt.is_required, rt.applicable_to
        FROM enrollment_requirements er
        JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
        WHERE er.enrollment_id = ? AND rt.is_active = 1
    ");
    $stmt->bind_param('i', $enrollment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        if ($r['applicable_to'] === 'public_jhs_only' && !$jhs_is_public) continue;
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

// ── Save requirement statuses for one enrollment ────────────────────────────
// Same only-changed-rows update as document_review.php's save_requirements —
// but no admission finalize step: these students are already enrolled.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requirements'])) {
    $enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
    $posted_status = $_POST['requirements'] ?? [];
    $posted_reason = $_POST['reasons']      ?? [];
    $return_qs     = $_POST['return_qs']    ?? '';

    if ($enrollment_id <= 0) {
        $_SESSION['ac_flash'] = 'Invalid record — please search again.';
        $_SESSION['ac_flash_type'] = 'error';
    } else {
        $chk = $conn->prepare("
            SELECT e.enrollment_id, e.status AS enrollment_status,
                   st.student_id, se.is_public AS jhs_is_public
            FROM enrollments e
            JOIN students st ON st.student_id = e.student_id
            LEFT JOIN student_education se ON se.student_id = st.student_id
            WHERE e.enrollment_id = ?
            LIMIT 1
        ");
        $chk->bind_param('i', $enrollment_id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$rec || $rec['enrollment_status'] !== 'enrolled') {
            $_SESSION['ac_flash'] = 'This record is no longer available. Please search again.';
            $_SESSION['ac_flash_type'] = 'error';
        } else {
            // LEFT JOIN — a student can be missing an enrollment_requirements
            // row entirely for a requirement_type added after they applied
            // (student/admission.php only seeds rows for types that existed
            // at application time). Those rows must be treated as 'pending'
            // and INSERTed on save, not silently skipped like an INNER JOIN
            // would do — that was the original bug: checking the manual
            // verify box for one of these had no row to UPDATE, so Save
            // Changes did nothing and the item stayed "Incomplete".
            $rows_stmt = $conn->prepare("
                SELECT er.enrollment_requirement_id, rt.requirement_type_id,
                       COALESCE(er.status, 'pending') AS status, er.file_path,
                       rt.applicable_to, rt.requirement_name
                FROM requirement_types rt
                LEFT JOIN enrollment_requirements er
                       ON er.requirement_type_id = rt.requirement_type_id AND er.enrollment_id = ?
                WHERE rt.is_active = 1
            ");
            $rows_stmt->bind_param('i', $enrollment_id);
            $rows_stmt->execute();
            $rows_res = $rows_stmt->get_result();
            $rows = [];
            while ($r = $rows_res->fetch_assoc()) {
                if ($r['applicable_to'] === 'public_jhs_only' && !$rec['jhs_is_public']) continue;
                $rows[] = $r;
            }
            $rows_stmt->close();

            $save_errors = [];
            $updates = []; // keyed by requirement_type_id — erid may be null (needs INSERT)
            foreach ($rows as $r) {
                $erid = $r['enrollment_requirement_id'] !== null ? (int) $r['enrollment_requirement_id'] : null;
                $rid  = (int) $r['requirement_type_id'];
                $new_status = $posted_status[$rid] ?? $r['status'];
                if (!in_array($new_status, $allowed_req_statuses, true)) $new_status = $r['status'];
                if ($new_status === $r['status']) continue;

                $reason = trim($posted_reason[$rid] ?? '');
                if ($new_status === 'rejected' && $reason === '') {
                    $save_errors[] = 'Please give a reason for declining "' . $r['requirement_name'] . '".';
                    continue;
                }

                // A file-less row moving to 'submitted' (first-time staff
                // verification) or 'pending_review' (a declined row being
                // resubmitted — see onManualFileChange()'s JS comment) can
                // optionally carry a staff-uploaded file (the "Or upload a
                // file" input next to the "Verified in person" checkbox) —
                // validated the same way as a student's own upload, so
                // it's indistinguishable to every downstream consumer
                // (records/view_document.php) once saved.
                $upload = null;
                $file_err = $_FILES['doc_upload']['error'][$rid] ?? UPLOAD_ERR_NO_FILE;
                if (in_array($new_status, ['submitted', 'pending_review'], true) && !$r['file_path'] && $file_err !== UPLOAD_ERR_NO_FILE) {
                    if ($file_err !== UPLOAD_ERR_OK) {
                        $save_errors[] = 'Could not upload the file for "' . $r['requirement_name'] . '". Please try again.';
                        continue;
                    }
                    $orig_name   = $_FILES['doc_upload']['name'][$rid];
                    $size        = (int) $_FILES['doc_upload']['size'][$rid];
                    $constraints = req_doc_constraints($r['requirement_name']);
                    $ext         = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $constraints['ext'], true) || $size > $constraints['max_bytes']) {
                        $save_errors[] = '"' . $r['requirement_name'] . '": ' . $constraints['hint'];
                        continue;
                    }
                    $upload = [
                        'tmp_name'      => $_FILES['doc_upload']['tmp_name'][$rid],
                        'ext'           => $ext,
                        'original_name' => $orig_name,
                    ];
                }

                $updates[$rid] = [
                    'erid' => $erid,
                    'status' => $new_status,
                    'reason' => $new_status === 'rejected' ? $reason : null,
                    'delete_file' => ($new_status === 'rejected' && $r['file_path']) ? $r['file_path'] : null,
                    'upload' => $upload,
                ];
            }

            if (!empty($save_errors)) {
                $_SESSION['ac_flash'] = implode(' ', $save_errors);
                $_SESSION['ac_flash_type'] = 'error';
            } elseif (empty($updates)) {
                $_SESSION['ac_flash'] = 'No changes to save.';
                $_SESSION['ac_flash_type'] = 'info';
            } else {
                mysqli_begin_transaction($conn);
                try {
                    $uid = (int) $_SESSION['user_id'];
                    $upd_stmt = $conn->prepare("
                        UPDATE enrollment_requirements
                        SET status = ?, submitted_at = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW()
                        WHERE enrollment_requirement_id = ?
                    ");
                    $upd_del_stmt = $conn->prepare("
                        UPDATE enrollment_requirements
                        SET status = ?, submitted_at = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW(),
                            file_path = NULL, original_filename = NULL
                        WHERE enrollment_requirement_id = ?
                    ");
                    $ins_stmt = $conn->prepare("
                        INSERT INTO enrollment_requirements
                            (enrollment_id, requirement_type_id, status, submitted_at, rejection_reason, reviewed_by, reviewed_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins_upload_stmt = $conn->prepare("
                        INSERT INTO enrollment_requirements
                            (enrollment_id, requirement_type_id, status, submitted_at, rejection_reason, reviewed_by, reviewed_at, file_path, original_filename)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
                    ");
                    $upd_upload_stmt = $conn->prepare("
                        UPDATE enrollment_requirements
                        SET status = ?, submitted_at = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW(),
                            file_path = ?, original_filename = ?
                        WHERE enrollment_requirement_id = ?
                    ");
                    foreach ($updates as $rid => $u) {
                        $submitted_at = $u['status'] === 'submitted' ? date('Y-m-d') : null;

                        $stored_name = null;
                        $orig_name = null;
                        if ($u['upload']) {
                            $stored_name = uniqid('reqdoc_', true) . '.' . $u['upload']['ext'];
                            $dest = __DIR__ . '/../../../uploads/requirement_documents/' . $stored_name;
                            if (!move_uploaded_file($u['upload']['tmp_name'], $dest))
                                throw new Exception('UPLOAD_MOVE_FAILED');
                            $orig_name = $u['upload']['original_name'];
                        }

                        if ($u['erid'] === null) {
                            if ($stored_name !== null) {
                                $ins_upload_stmt->bind_param('iisssiss', $enrollment_id, $rid, $u['status'], $submitted_at, $u['reason'], $uid, $stored_name, $orig_name);
                                if (!$ins_upload_stmt->execute())
                                    throw new Exception('INSERT_REQUIREMENT_FAILED: ' . $ins_upload_stmt->error);
                            } else {
                                $ins_stmt->bind_param('iisssi', $enrollment_id, $rid, $u['status'], $submitted_at, $u['reason'], $uid);
                                if (!$ins_stmt->execute())
                                    throw new Exception('INSERT_REQUIREMENT_FAILED: ' . $ins_stmt->error);
                            }
                        } elseif ($u['delete_file']) {
                            $full_path = __DIR__ . '/../../../uploads/requirement_documents/' . $u['delete_file'];
                            if (is_file($full_path)) @unlink($full_path);
                            $upd_del_stmt->bind_param('sssii', $u['status'], $submitted_at, $u['reason'], $uid, $u['erid']);
                            if (!$upd_del_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_del_stmt->error);
                        } elseif ($stored_name !== null) {
                            $upd_upload_stmt->bind_param('sssissi', $u['status'], $submitted_at, $u['reason'], $uid, $stored_name, $orig_name, $u['erid']);
                            if (!$upd_upload_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_upload_stmt->error);
                        } else {
                            $upd_stmt->bind_param('sssii', $u['status'], $submitted_at, $u['reason'], $uid, $u['erid']);
                            if (!$upd_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_stmt->error);
                        }
                    }
                    $upd_stmt->close();
                    $upd_del_stmt->close();
                    $ins_stmt->close();
                    $ins_upload_stmt->close();
                    $upd_upload_stmt->close();

                    mysqli_commit($conn);
                    $_SESSION['ac_flash'] = 'Requirements updated.';
                    $_SESSION['ac_flash_type'] = 'success';
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $_SESSION['ac_flash'] = 'Something went wrong while saving. Please try again.';
                    $_SESSION['ac_flash_type'] = 'error';
                    error_log('[accountabilities.php] ' . $e->getMessage());
                }
            }
        }
    }
    header("Location: accountabilities" . ($return_qs !== '' ? '?' . $return_qs : ''));
    exit();
}

// ── Request follow-up ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_followup'])) {
    $enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
    $return_qs     = $_POST['return_qs'] ?? '';

    $chk = $conn->prepare("
        SELECT st.given_name, st.family_name, st.email, se.is_public AS jhs_is_public
        FROM enrollments e
        JOIN students st ON st.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = st.student_id
        WHERE e.enrollment_id = ? LIMIT 1
    ");
    $chk->bind_param('i', $enrollment_id);
    $chk->execute();
    $rec = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$rec) {
        $_SESSION['ac_flash'] = 'This record is no longer available. Please search again.';
        $_SESSION['ac_flash_type'] = 'error';
    } elseif (empty($rec['email'])) {
        $_SESSION['ac_flash'] = 'This student has no email on file.';
        $_SESSION['ac_flash_type'] = 'error';
    } else {
        $reqs = ac_load_requirements($conn, $enrollment_id, (int) $rec['jhs_is_public']);
        $outstanding = array_filter($reqs, fn($r) => $r['status'] !== 'submitted');

        if (empty($outstanding)) {
            $_SESSION['ac_flash'] = 'Nothing outstanding for this student — no reminder needed.';
            $_SESSION['ac_flash_type'] = 'info';
        } else {
            try {
                $mail = getMailer();
                $items_html = '';
                foreach ($outstanding as $r) {
                    $tag = ((int) $r['is_required'] === 1) ? ' (required)' : ' (optional)';
                    $items_html .= '<li>' . htmlspecialchars($r['requirement_name']) . htmlspecialchars($tag) . '</li>';
                }
                $bodyHtml = '<p>Hi ' . htmlspecialchars($rec['given_name']) . ', this is a reminder that the following documents are still outstanding on your file:</p>'
                    . '<ul>' . $items_html . '</ul>'
                    . '<p>Please submit or bring these to the Records Office at your earliest convenience.</p>';
                $altBody = "The following documents are still outstanding on your file:\n"
                    . implode("\n", array_map(fn($r) => '- ' . $r['requirement_name'], $outstanding));

                send_branded_email(
                    $mail,
                    $rec['email'],
                    trim($rec['given_name'] . ' ' . $rec['family_name']),
                    'Outstanding Documents — Reminder',
                    'Requirements Reminder',
                    $bodyHtml,
                    $altBody
                );

                $_SESSION['ac_flash'] = 'Reminder email sent.';
                $_SESSION['ac_flash_type'] = 'success';
            } catch (\Throwable $e) {
                error_log('[accountabilities.php] followup email failed: ' . $e->getMessage());
                $_SESSION['ac_flash'] = 'Could not send the reminder email. Please try again.';
                $_SESSION['ac_flash_type'] = 'error';
            }
        }
    }
    header("Location: accountabilities" . ($return_qs !== '' ? '?' . $return_qs : ''));
    exit();
}

// ── Filters ──────────────────────────────────────────────────────────────
$ac_q      = trim($_GET['q']      ?? '');
$ac_grade  = trim($_GET['grade']  ?? '');
$ac_strand = trim($_GET['strand'] ?? '');
$ac_page   = max(1, (int) ($_GET['page'] ?? 1));
$ac_per_page = 15;

if (!in_array($ac_grade, $allowed_grades, true))   $ac_grade  = '';
if (!in_array($ac_strand, $allowed_strands, true)) $ac_strand = '';

$where  = ["e.status = 'enrolled'"];
$types  = '';
$params = [];

if ($ac_q !== '') {
    $where[]  = "(s.family_name LIKE ? OR s.given_name LIKE ? OR e.control_number LIKE ?)";
    $like     = '%' . $ac_q . '%';
    $types   .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($ac_grade !== '') {
    $where[]  = "e.admission_grade_level = ?";
    $types   .= 's';
    $params[] = $ac_grade;
}
if ($ac_strand !== '') {
    $where[]  = "e.admission_strand = ?";
    $types   .= 'i';
    $params[] = strand_id($conn, $ac_strand);
}
$where_sql = implode(' AND ', $where);

$return_qs = http_build_query(array_filter([
    'q' => $ac_q, 'grade' => $ac_grade, 'strand' => $ac_strand,
    'page' => $ac_page > 1 ? $ac_page : null,
]));

// Only students with a genuine outstanding item belong on this list —
// applicable_reqs > submitted_count.
$count_sql = "
    SELECT COUNT(*) AS c FROM (
        SELECT e.enrollment_id,
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
        WHERE $where_sql
        HAVING submitted_count < applicable_reqs
    ) t
";
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_count = (int) $count_stmt->get_result()->fetch_assoc()['c'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_count / $ac_per_page));
if ($ac_page > $total_pages) $ac_page = $total_pages;
$ac_offset = ($ac_page - 1) * $ac_per_page;

$list_sql = "
    SELECT e.enrollment_id, e.control_number, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
           e.school_year,
           s.student_id, s.family_name, s.given_name, s.middle_name, s.suffix,
           se.is_public AS jhs_is_public,
           (SELECT COUNT(*) FROM requirement_types rt
              WHERE rt.is_active = 1 AND (rt.applicable_to = 'all' OR se.is_public = 1)
           ) AS applicable_reqs,
           (SELECT COUNT(*) FROM enrollment_requirements er
              JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
             WHERE er.enrollment_id = e.enrollment_id AND er.status = 'submitted' AND rt.is_active = 1
           ) AS submitted_count,
           (SELECT COUNT(*) FROM enrollment_requirements er
              JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
             WHERE er.enrollment_id = e.enrollment_id AND er.status = 'pending_review' AND rt.is_active = 1
           ) AS review_count
    FROM enrollments e
    JOIN students s ON s.student_id = e.student_id
    LEFT JOIN student_education se ON se.student_id = s.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    WHERE $where_sql
    HAVING submitted_count < applicable_reqs
    ORDER BY s.family_name ASC, s.given_name ASC
    LIMIT ? OFFSET ?
";
$list_stmt = $conn->prepare($list_sql);
$list_types  = $types . 'ii';
$list_params = array_merge($params, [$ac_per_page, $ac_offset]);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$res = $list_stmt->get_result();

$students = [];
while ($row = $res->fetch_assoc()) $students[] = $row;
$list_stmt->close();

foreach ($students as &$a) {
    $req_stmt = $conn->prepare("
        SELECT rt.requirement_type_id, rt.requirement_name, rt.applicable_to, rt.is_required,
               er.enrollment_requirement_id, er.status, er.file_path, er.original_filename, er.rejection_reason
        FROM requirement_types rt
        LEFT JOIN enrollment_requirements er
               ON er.requirement_type_id = rt.requirement_type_id AND er.enrollment_id = ?
        WHERE rt.is_active = 1
        ORDER BY rt.display_order ASC
    ");
    $req_stmt->bind_param('i', $a['enrollment_id']);
    $req_stmt->execute();
    $req_res = $req_stmt->get_result();
    $reqs = [];
    while ($r = $req_res->fetch_assoc()) {
        if ($r['applicable_to'] === 'public_jhs_only' && !$a['jhs_is_public']) continue;
        $reqs[] = [
            'enrollment_requirement_id' => (int) $r['enrollment_requirement_id'],
            'requirement_type_id'      => (int) $r['requirement_type_id'],
            'name'                     => $r['requirement_name'],
            'status'                   => $r['status'] ?? 'pending',
            'file_path'                => $r['file_path'],
            'original_filename'        => $r['original_filename'],
            'rejection_reason'         => $r['rejection_reason'],
            'is_required'              => (int) $r['is_required'],
        ];
    }
    $req_stmt->close();
    $a['requirements'] = $reqs;
}
unset($a);

$modal_data = [];
foreach ($students as $a) $modal_data[$a['enrollment_id']] = $a;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Accountabilities — Records</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    .dr-count-pill {
      background:#1E4D3B; color:#fff; font-size:12px; font-weight:600;
      border-radius:999px; padding:2px 10px; margin-left:8px;
    }
    .pa-filter-bar {
      display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
      background:#fff; border:0.5px solid #E0E0EC; border-radius:10px;
      padding:12px 14px; margin-bottom:1rem;
    }
    .pa-filter-field { display:flex; flex-direction:column; gap:4px; }
    .pa-filter-field label { font-size:11px; font-weight:500; color:#5A5A72; text-transform:uppercase; letter-spacing:.04em; }
    .pa-filter-field input, .pa-filter-field select {
      height:34px; padding:0 10px; border:0.5px solid #D4D4E0; border-radius:7px;
      font-family:inherit; font-size:13px; background:#FAFAFC; min-width:150px;
    }
    .pa-filter-actions { display:flex; gap:8px; margin-left:auto; }
    .btn-filter, .btn-filter-clear {
      height:34px; padding:0 14px; border-radius:7px; font-size:13px; font-weight:500;
      cursor:pointer; font-family:inherit;
    }
    .btn-filter { background:#1E4D3B; color:#fff; border:none; }
    .btn-filter:hover { background:#163829; }
    .btn-filter-clear { background:#fff; color:#5A5A72; border:0.5px solid #D4D4E0; text-decoration:none; display:inline-flex; align-items:center; }
    .btn-filter-clear:hover { border-color:#ADADBD; }

    .pa-table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
    .pa-table th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:9px 10px; border-bottom:0.5px solid #EBEBF0; }
    .pa-table td { padding:10px; border-bottom:0.5px solid #F5F5F7; vertical-align:middle; color:#1A1A2E; }
    .pa-table tr:last-child td { border-bottom:none; }
    .pa-table tr:hover td { background:#FAFAFC; }
    .td-name { font-weight:500; }
    .td-meta { color:#5A5A72; font-size:12px; }

    .status-flag {
      display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600;
      border-radius:20px; padding:2px 9px; white-space:nowrap;
    }
    .status-review     { color:#A9720A; background:#FEF6E8; border:0.5px solid #F3D896; }
    .status-incomplete { color:#5A5A72; background:#F5F5F7; border:0.5px solid #E0E0EC; }

    .req-bar-wrap { display:flex; align-items:center; gap:8px; min-width:120px; }
    .req-bar { flex:1; height:5px; border-radius:3px; background:#EBEBF0; overflow:hidden; }
    .req-bar-fill { height:100%; border-radius:3px; }
    .req-bar-fill.full    { background:#2ECC71; }
    .req-bar-fill.partial { background:#E67E22; }
    .req-bar-fill.empty   { background:#D8D8E4; }
    .req-bar-text { font-size:11px; color:#5A5A72; white-space:nowrap; }

    .pa-row-actions { display:flex; gap:6px; flex-wrap:wrap; }
    .btn-view { height:28px; padding:0 11px; border-radius:6px; font-size:11px; font-weight:600;
      cursor:pointer; border:none; font-family:inherit; white-space:nowrap; background:#F0F0EA; color:#3A3A32; }
    .btn-view:hover { background:#E4E4DC; }

    .pa-empty {
      text-align:center; padding:3rem 1rem; color:#5A5A72; font-size:14px;
      background:#fff; border:1px dashed #D8D8E4; border-radius:10px;
    }
    .flash { border-radius:8px; padding:10px 14px; font-size:13px; margin-bottom:1rem; }
    .flash-error   { background:#FDF0EF; border:1px solid #F5C6C2; color:#C0392B; }
    .flash-info    { background:#EAF3EE; border:1px solid #C9E0D4; color:#1E4D3B; }
    .flash-success { background:#EAF3EE; border:1px solid #A8D9C5; color:#1A7A5E; }

    form.inline { display:inline; }

    .pa-pagination { display:flex; align-items:center; justify-content:space-between; margin-top:1rem; font-size:12.5px; color:#5A5A72; }
    .pa-pagination .pages { display:flex; gap:4px; }
    .pa-pagination a, .pa-pagination span.current {
      display:inline-flex; align-items:center; justify-content:center;
      width:30px; height:30px; border-radius:6px; text-decoration:none; font-size:12.5px;
    }
    .pa-pagination a { color:#5A5A72; border:0.5px solid #E0E0EC; }
    .pa-pagination a:hover { border-color:#ADADBD; }
    .pa-pagination span.current { background:#1E4D3B; color:#fff; font-weight:600; }

    .pa-modal-overlay {
      display:none; position:fixed; inset:0; background:rgba(20,20,30,.45); z-index:400;
      align-items:center; justify-content:center; padding:1.25rem;
    }
    .pa-modal-overlay.open { display:flex; }
    .pa-modal {
      background:#fff; border-radius:12px; max-width:760px; width:100%;
      max-height:94vh; overflow-y:auto; padding:1.35rem 1.6rem;
    }
    .pa-modal-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; gap:12px; }
    .pa-modal-title { font-size:16px; font-weight:600; color:#1A1A2E; }
    .pa-modal-title .status-flag { margin-left:6px; vertical-align:middle; }
    .pa-modal-sub { font-size:12px; color:#5A5A72; margin-top:2px; }
    .pa-modal-close { background:none; border:none; cursor:pointer; color:#8A8A9A; font-size:20px; line-height:1; padding:4px; flex-shrink:0; }
    .pa-modal-close:hover { color:#1A1A2E; }
    .pa-modal-section-title {
      font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.08em;
      color:#2F6B4F; margin:16px 0 6px; padding-bottom:4px; border-bottom:0.5px solid #EBEBF0;
    }
    .pa-modal-section-title:first-of-type { margin-top:0; }

    .req-table-wrap {
      background:#FAFAFC; border:0.5px solid #EBEBF0; border-radius:10px; padding:4px 14px;
    }
    .req-review-table { width:100%; border-collapse:collapse; font-size:13px; }
    .req-review-table th {
      text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.05em;
      color:#8A8A9A; padding:10px 8px 10px 0; border-bottom:0.5px solid #EBEBF0;
    }
    .req-review-table td { padding:9px 8px 9px 0; border-bottom:0.5px solid #EBEBF0; vertical-align:middle; }
    .req-review-table tr:last-child td { border-bottom:none; }
    .req-name { font-weight:500; color:#1A1A2E; }
    .req-status-cell { display:flex; flex-direction:column; align-items:flex-start; gap:5px; }
    .req-decision { display:flex; gap:8px; }
    .req-radio {
      display:inline-flex; align-items:center; justify-content:center; gap:5px;
      flex:1 1 0; box-sizing:border-box;
      font-size:12px; font-weight:600;
      cursor:pointer; padding:5px 11px; border-radius:20px; border:0.5px solid #D4D4E0;
      background:#FAFAFC; color:#5A5A72; user-select:none;
      transition:background-color .15s, border-color .15s, color .15s;
    }
    .req-radio input[type="radio"] { accent-color:currentColor; margin:0; cursor:pointer; }
    .req-radio-approve.is-checked { background:#EAF3EE; border-color:#BFE0CD; color:#1E4D3B; }
    .req-radio-reject.is-checked { background:#FDF0EF; border-color:#F0B8B2; color:#C0392B; }
    .req-reason-input {
      display:none; width:100%; min-width:160px; height:30px; padding:0 8px; margin-top:2px;
      border:0.5px solid #D4D4E0; border-radius:6px; font-family:inherit; font-size:12px; background:#FFFAFA;
    }
    .req-reason-input.show { display:block; }
    .req-manual-confirm {
      display:flex; align-items:center; gap:6px; max-width:240px;
      font-size:11px; color:#A9720A; cursor:pointer; user-select:none;
      background:#FEF6E8; border:0.5px solid #F3D896; border-radius:6px; padding:5px 9px;
    }
    .req-manual-confirm input[type="checkbox"] { margin:0; accent-color:#1E4D3B; flex-shrink:0; cursor:pointer; }
    .req-upload-label {
      display:flex; align-items:center; gap:6px;
      font-size:11px; color:#5A5A72; cursor:pointer; user-select:none;
      background:#FAFAFC; border:0.5px solid #D4D4E0; border-radius:20px; padding:5px 14px;
      max-width:200px; overflow:hidden;
    }
    .req-upload-label:hover { border-color:var(--brand-accent); color:var(--brand-primary); }
    .req-upload-label span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .req-upload-input { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }
    .req-file-link {
      display:inline-flex; align-items:center; gap:5px; text-decoration:none; max-width:180px;
    }
    .req-file-name {
      color:#8A8A9A; font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .req-file-link svg { flex-shrink:0; color:#5A5A72; }
    .req-file-link:hover .req-file-name { color:#1E4D3B; text-decoration:underline; }
    .req-file-link:hover svg { color:#1E4D3B; }
    .req-file-empty { display:inline-block; color:#ADADBD; font-size:12px; }
    .req-blur-badge-warn {
      display:inline-block; margin-top:3px; font-size:10.5px; font-weight:600;
      color:#C06A10; background:#FFF4E6; border-radius:10px; padding:1px 8px;
    }
    .req-radio-disabled { opacity:0.45; cursor:not-allowed; }
    .req-old-rejection { font-size:11px; color:#C0392B; margin-bottom:2px; }

    .dr-modal-footer {
      display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
      margin-top:1.25rem; padding-top:1rem; border-top:0.5px solid #EBEBF0;
    }
    .dr-modal-footer .btn-primary, .dr-modal-footer .btn-secondary, .dr-modal-footer .btn-followup {
      height:34px; padding:0 16px; font-size:12.5px;
    }
    .dr-footer-actions { display:flex; gap:8px; flex-wrap:wrap; }

    .btn-followup {
      background:#fff; color:#A9720A; border:1px solid #F3D896; border-radius:8px;
      font-weight:600; cursor:pointer; font-family:inherit;
    }
    .btn-followup:hover:not(:disabled) { background:#FEF6E8; }
    .dr-modal-footer button:disabled { opacity:0.55; cursor:not-allowed; pointer-events:auto; }

    .swal2-popup .swal2-input { margin-top: 1em !important; }
    .swal2-popup .swal2-select, .swal2-popup .swal2-textarea { box-sizing: border-box !important; max-width: 100% !important; }
    .swal2-popup .swal2-textarea { resize: vertical !important; }
  </style>
</head>
<body class="staff-layout">

<?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
<div class="staff-main">
  <div class="staff-topbar">
    <div class="staff-topbar-left">
      <span class="staff-topbar-title">Student Accountabilities</span>
    </div>
    <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
  </div>

  <div class="staff-content">

    <?php if ($flash): ?>
      <div class="flash flash-<?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : 'Records portal' ?>
      <span class="dr-count-pill"><?= $total_count ?></span>
    </p>
    <h1 class="page-title">Student Accountabilities</h1>
    <p class="page-sub">Enrolled students who still owe a document. Review uploads, or check off what was verified in person.</p>

    <form method="GET" action="accountabilities" class="pa-filter-bar">
      <div class="pa-filter-field">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= htmlspecialchars($ac_q) ?>" placeholder="Name">
      </div>
      <div class="pa-filter-field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
          <option value="">All</option>
          <?php foreach ($allowed_grades as $g): ?>
            <option value="<?= $g ?>" <?= $ac_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pa-filter-field">
        <label for="strand">Strand</label>
        <select id="strand" name="strand">
          <option value="">All</option>
          <?php foreach ($allowed_strands as $s): ?>
            <option value="<?= $s ?>" <?= $ac_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pa-filter-actions">
        <button type="submit" class="btn-filter">Filter</button>
        <?php if ($ac_q !== '' || $ac_grade !== '' || $ac_strand !== ''): ?>
          <a href="accountabilities" class="btn-filter-clear">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (empty($students)): ?>

      <div class="pa-empty">
        <?= $total_count === 0 && $ac_q === '' && $ac_grade === '' && $ac_strand === ''
              ? 'No enrolled students currently have outstanding documents.'
              : 'No students match your filters.' ?>
      </div>

    <?php else: ?>

      <table class="pa-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Grade / Strand</th>
            <th>Status</th>
            <th>Requirements</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $a):
            $full_name = trim($a['family_name'] . ', ' . $a['given_name']
                . ($a['middle_name'] ? ' ' . $a['middle_name'] : '')
                . ($a['suffix'] ? ' ' . $a['suffix'] : ''));
            $applicable = (int) $a['applicable_reqs'];
            $submitted  = (int) $a['submitted_count'];
            $reviewCnt  = (int) $a['review_count'];
            $pct        = $applicable > 0 ? round(($submitted / $applicable) * 100) : 0;
            $barClass   = $submitted === 0 ? 'empty' : ($submitted >= $applicable ? 'full' : 'partial');

            if ($reviewCnt > 0) { $statusClass = 'status-review'; $statusLabel = 'Needs Review'; }
            else                { $statusClass = 'status-incomplete'; $statusLabel = 'Incomplete'; }
          ?>
            <tr>
              <td class="td-name"><?= htmlspecialchars($full_name) ?></td>
              <td class="td-meta">Grade <?= htmlspecialchars($a['grade_level']) ?> &middot; <?= htmlspecialchars($a['strand']) ?></td>
              <td><span class="status-flag <?= $statusClass ?>"><?= $statusLabel ?></span></td>
              <td>
                <div class="req-bar-wrap">
                  <div class="req-bar"><div class="req-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div></div>
                  <span class="req-bar-text"><?= $submitted ?>/<?= $applicable ?></span>
                </div>
              </td>
              <td>
                <div class="pa-row-actions">
                  <button type="button" class="btn-view" onclick="openModal(<?= (int) $a['enrollment_id'] ?>)">View</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1):
        $base_params = array_filter(['q' => $ac_q, 'grade' => $ac_grade, 'strand' => $ac_strand]);
      ?>
        <div class="pa-pagination">
          <span>Page <?= $ac_page ?> of <?= $total_pages ?> &middot; <?= $total_count ?> total</span>
          <div class="pages">
            <?php if ($ac_page > 1): ?>
              <a href="?<?= http_build_query(array_merge($base_params, ['page' => $ac_page - 1])) ?>">&larr;</a>
            <?php endif; ?>
            <?php for ($p = max(1, $ac_page - 2); $p <= min($total_pages, $ac_page + 2); $p++): ?>
              <?php if ($p === $ac_page): ?>
                <span class="current"><?= $p ?></span>
              <?php else: ?>
                <a href="?<?= http_build_query(array_merge($base_params, ['page' => $p])) ?>"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($ac_page < $total_pages): ?>
              <a href="?<?= http_build_query(array_merge($base_params, ['page' => $ac_page + 1])) ?>">&rarr;</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div><!-- .staff-content -->
</div><!-- .staff-main -->

<div class="pa-modal-overlay" id="paModalOverlay" onclick="if(event.target===this) closeModal()">
  <div class="pa-modal" id="paModal"></div>
</div>

<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/blur_detect.js"></script>
<script>
  const studentData = <?= json_encode($modal_data, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  const returnQs = <?= json_encode($return_qs, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

  function esc(s) {
    return (s ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  const fileIconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>';

  function renderRequirementsTab(enrollmentId, requirements) {
    let rows = requirements.map(r => {
      const rid = r.requirement_type_id;
      const fileCell = r.file_path
        ? '<a class="req-file-link" href="view_document?id=' + r.enrollment_requirement_id + '" target="_blank">' +
            '<span class="req-file-name">' + esc(r.original_filename || 'View file') + '</span>' + fileIconSvg +
          '</a><span class="req-blur-badge" id="blur-badge-' + r.enrollment_requirement_id + '"></span>'
        : '<span class="req-file-empty">No file uploaded</span>';

      let control;
      if (r.status === 'submitted') {
        control = '<span class="status-flag status-review" style="color:#1E4D3B;background:#EAF3EE;border-color:#BFE0CD;">Approved</span>';
      } else if (r.status === 'pending_review') {
        control =
          '<div class="req-decision">' +
            '<label class="req-radio req-radio-approve">' +
              '<input type="radio" name="requirements[' + rid + ']" value="submitted" onchange="onDecisionChange(this, ' + rid + ')"> Approve' +
            '</label>' +
            '<label class="req-radio req-radio-reject">' +
              '<input type="radio" name="requirements[' + rid + ']" value="rejected" onchange="onDecisionChange(this, ' + rid + ')"> Decline' +
            '</label>' +
          '</div>' +
          '<input type="text" class="req-reason-input" id="reason-' + rid + '" ' +
            'name="reasons[' + rid + ']" placeholder="Reason for declining (required)">';
      } else {
        const rejectionNote = r.status === 'rejected' && r.rejection_reason
          ? '<div class="req-old-rejection">Declined: ' + esc(r.rejection_reason) + ' — awaiting resubmission</div>'
          : '';
        control =
          rejectionNote +
          '<label class="req-manual-confirm">' +
            '<input type="checkbox" class="req-manual-checkbox" id="checkbox-' + rid + '" onchange="onManualCheck(this, ' + rid + ')"> Verified in person — mark Submitted' +
          '</label>' +
          '<label class="req-upload-label">' +
            '<input type="file" class="req-upload-input" name="doc_upload[' + rid + ']" accept=".pdf,.jpg,.jpeg,.png" onchange="onManualFileChange(this, ' + rid + ', \'' + r.status + '\')">' +
            '<span id="upload-name-' + rid + '">Or upload a file</span>' +
          '</label>' +
          '<input type="hidden" id="hidden-' + rid + '" name="requirements[' + rid + ']" value="">';
      }

      return (
        '<tr>' +
          '<td class="req-name">' + esc(r.name) + (r.is_required ? ' <span class="req">*</span>' : '') + '</td>' +
          '<td><div class="req-status-cell">' + control + '</div></td>' +
          '<td>' + fileCell + '</td>' +
        '</tr>'
      );
    }).join('');

    return (
      '<div class="pa-modal-section-title">Document Requirements</div>' +
      '<form method="POST" action="accountabilities" id="reqSaveForm" enctype="multipart/form-data">' +
        '<input type="hidden" name="enrollment_id" value="' + enrollmentId + '">' +
        '<input type="hidden" name="return_qs" value="' + esc(returnQs) + '">' +
        '<div class="req-table-wrap">' +
          '<table class="req-review-table">' +
            '<thead><tr><th>Requirement</th><th>Status</th><th>File</th></tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
          '</table>' +
        '</div>' +
        '<div class="dr-modal-footer">' +
          '<span class="field-hint">Declining a document requires a reason — the student will see it.</span>' +
          '<button type="submit" name="save_requirements" class="btn-primary">Save Changes</button>' +
        '</div>' +
      '</form>'
    );
  }

  function onDecisionChange(radio, rid) {
    const group = radio.closest('.req-decision');
    if (group) {
      group.querySelectorAll('.req-radio').forEach(l => l.classList.toggle('is-checked', l.querySelector('input') === radio));
    }
    const reasonInput = document.getElementById('reason-' + rid);
    if (!reasonInput) return;
    const rejecting = radio.value === 'rejected';
    reasonInput.classList.toggle('show', rejecting);
    if (rejecting) reasonInput.setAttribute('required', 'required');
    else reasonInput.removeAttribute('required');
  }

  function onManualCheck(checkbox, rid) {
    const hidden = document.getElementById('hidden-' + rid);
    if (!hidden) return;
    hidden.value = checkbox.checked ? 'submitted' : '';
  }

  function onManualFileChange(input, rid, priorStatus) {
    const hidden = document.getElementById('hidden-' + rid);
    const nameSpan = document.getElementById('upload-name-' + rid);
    const checkbox = document.getElementById('checkbox-' + rid);
    const hasFile = input.files && input.files.length > 0;

    if (!hasFile) {
      if (hidden) hidden.value = checkbox && checkbox.checked ? 'submitted' : '';
      if (nameSpan) nameSpan.textContent = 'Or upload a file';
      return;
    }

    const file = input.files[0];

    // Sets the hidden field once the file has cleared the blur check (or
    // didn't need one). A file swapped in on a row that was just declined
    // needs a fresh review — auto-approving a resubmission straight
    // through this "staff verified in person" shortcut would silently
    // bypass it. A never-before-submitted 'pending' row keeps the
    // existing direct-approve shortcut, now gated on sharpness first.
    function attach() {
      if (hidden) hidden.value = priorStatus === 'rejected' ? 'pending_review' : 'submitted';
      if (nameSpan) nameSpan.textContent = file.name;
    }

    // PDFs (allowed for most requirement types) can't be meaningfully
    // blur-checked without rendering them first — skip straight to attach.
    if (typeof scoreImageSharpness !== 'function' || !file.type.startsWith('image/')) {
      attach();
      return;
    }

    if (nameSpan) nameSpan.textContent = 'Checking image sharpness…';

    scoreImageSharpness(file).then(function (score) {
      if (score < BLUR_THRESHOLD) {
        input.value = '';
        if (nameSpan) nameSpan.textContent = 'Or upload a file';
        // Leave the hidden field exactly as it was — this file never
        // gets a chance to be approved by "Save Changes".
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'warning', title: 'Image looks blurry', text: 'This image looks blurry — please attach a clearer photo or scan.', confirmButtonColor: '#1E4D3B' });
        } else {
          alert('This image looks blurry — please attach a clearer photo or scan.');
        }
      } else {
        attach();
      }
    }).catch(function () {
      // Couldn't score it (corrupt/unsupported image) — never block on a
      // check that itself failed; normal server-side validation still
      // catches a genuinely bad file.
      attach();
    });
  }

  function computeStatus(a) {
    const reviewCount = (a.requirements || []).filter(r => r.status === 'pending_review').length;
    if (reviewCount > 0) return { cls: 'status-review', label: 'Needs Review' };
    return { cls: 'status-incomplete', label: 'Incomplete' };
  }

  function hasOutstanding(a) {
    return (a.requirements || []).some(r => r.status !== 'submitted');
  }

  function buildFullName(a) {
    return [a.family_name + ',', a.given_name, a.middle_name, a.suffix].filter(Boolean).join(' ');
  }

  function openModal(enrollmentId) {
    const a = studentData[enrollmentId];
    if (!a) return;

    const fullName = buildFullName(a);
    const status = computeStatus(a);
    const followupBtn = '<button type="button" class="btn-followup" onclick="confirmFollowup(' + enrollmentId + ')"' +
      (hasOutstanding(a) ? '' : ' disabled title="Nothing outstanding for this student"') +
      '>Request Follow-up</button>';

    document.getElementById('paModal').innerHTML = `
      <div class="pa-modal-head">
        <div>
          <div class="pa-modal-title">${esc(fullName)} <span class="status-flag ${status.cls}">${status.label}</span></div>
          <div class="pa-modal-sub">${esc(a.control_number || 'No control number')} &middot; Grade ${esc(a.grade_level)} &ndash; ${esc(a.strand)}</div>
        </div>
        <button class="pa-modal-close" onclick="closeModal()">&times;</button>
      </div>

      ${renderRequirementsTab(enrollmentId, a.requirements)}

      <div class="dr-modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal()">Close</button>
        <div class="dr-footer-actions">
          ${followupBtn}
        </div>
      </div>
    `;
    document.getElementById('paModalOverlay').classList.add('open');
    checkUploadedImagesForBlur(a.requirements);
    guardBlurrySubmit();
  }

  // Safety net for the rare race where the async blur check resolves
  // after a fast Approve click, or a radio somehow stayed enabled —
  // re-checks at submit time and blocks if a flagged-blurry row is still
  // set to "Approve."
  function guardBlurrySubmit() {
    const form = document.getElementById('reqSaveForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      const blurryApproved = Array.from(document.querySelectorAll('.req-blur-badge-warn')).some(function (badge) {
        const row = badge.closest('tr');
        if (!row) return false;
        const checkedRadio = row.querySelector('input[type="radio"][value="submitted"]:checked');
        return !!checkedRadio;
      });
      if (blurryApproved) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'warning', title: 'Blurry document', text: 'One of the documents is flagged as possibly blurry — please reject it to request a clearer copy instead of approving.', confirmButtonColor: '#1E4D3B' });
        } else {
          alert('One of the documents is flagged as possibly blurry — please reject it instead of approving.');
        }
      }
    });
  }

  // Checks already-uploaded images (not PDFs, which can't be meaningfully
  // blur-checked without rendering them) using the same offline
  // Laplacian-variance heuristic as the upload-time check in
  // student/admission.php (see js/blur_detect.js). A flagged row also has
  // its Approve radio disabled — the radios are named by
  // requirement_type_id (a small fixed catalog id), NOT
  // enrollment_requirement_id (the per-student row the badge/file link
  // use), so both ids from the same `r` are needed to find the right one.
  function checkUploadedImagesForBlur(requirements) {
    if (typeof scoreImageSharpness !== 'function') return;
    (requirements || []).forEach(function (r) {
      if (!r.file_path) return;
      const ext = (r.file_path.split('.').pop() || '').toLowerCase();
      if (!['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return;

      const badge = document.getElementById('blur-badge-' + r.enrollment_requirement_id);
      if (!badge) return;

      scoreImageSharpness('view_document?id=' + r.enrollment_requirement_id)
        .then(function (score) {
          if (score < BLUR_THRESHOLD) {
            badge.textContent = '⚠ Possibly blurry';
            badge.classList.add('req-blur-badge-warn');

            // Only exists while the row is still pending_review (radios
            // only render in that state) — an already-'submitted' row has
            // no radio to disable here.
            const approveRadio = document.querySelector(
              'input[name="requirements[' + r.requirement_type_id + ']"][value="submitted"]'
            );
            if (approveRadio) {
              approveRadio.disabled = true;
              const label = approveRadio.closest('.req-radio-approve');
              if (label) {
                label.classList.add('req-radio-disabled');
                label.title = 'This image looks blurry — reject it to request a clearer copy instead.';
              }
            }
          }
        })
        .catch(function () { /* can't determine — leave unflagged */ });
    });
  }

  function closeModal() {
    document.getElementById('paModalOverlay').classList.remove('open');
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  function postAction(action, enrollmentId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'accountabilities';
    form.innerHTML =
      '<input type="hidden" name="enrollment_id" value="' + enrollmentId + '">' +
      '<input type="hidden" name="return_qs" value="' + esc(returnQs) + '">' +
      '<input type="hidden" name="' + action + '" value="1">';
    document.body.appendChild(form);
    form.submit();
  }

  function confirmFollowup(enrollmentId) {
    const fullName = buildFullName(studentData[enrollmentId]);
    Swal.fire({
      title: 'Send a reminder email?',
      html: '<p style="margin:0;font-size:13px;color:#5A5A72;text-align:left;">' +
        'This emails <strong>' + esc(fullName) + '</strong> about their currently outstanding documents.' +
        '</p>',
      icon: 'info',
      showCancelButton: true,
      confirmButtonColor: '#A9720A',
      cancelButtonColor: '#aaa',
      confirmButtonText: 'Send Reminder',
      cancelButtonText: 'Cancel',
    }).then((result) => {
      if (result.isConfirmed) postAction('send_followup', enrollmentId);
    });
  }
</script>

</body>
</html>
