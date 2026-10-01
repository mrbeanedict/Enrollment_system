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

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = ($_SESSION['role'] ?? '') === 'admin';
if (!$is_admin && ($_SESSION['department'] ?? '') !== 'records') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access denied</title>
          <link rel="stylesheet" href="' . APP_URL . '/assets/css/css_staff.css?v=' . filemtime(__DIR__ . '/../../../assets/css/css_staff.css') . '"></head><body>
          <div class="card" style="max-width:480px;margin:4rem auto;">
            <h2>Access denied</h2>
            <p>Only the Records department can view student accountabilities.</p>
            <a href="' . APP_URL . '/roles/staff/dashboard">&larr; Back to dashboard</a>
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
        SELECT er.enrollment_requirement_id, er.requirement_type_id, er.status, er.rejection_reason,
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
            // (roles/student/public/admission.php only seeds rows for types that existed
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
                // resubmitted — see onDropzoneFileChange()'s JS comment) can
                // optionally carry a staff-uploaded file (the dropzone next
                // to the "Seen in person" checkbox) —
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
                [$items_html, $items_text] = followup_items_markup($outstanding);
                $bodyHtml = '<p>Hi ' . htmlspecialchars($rec['given_name']) . ', this is a reminder that the following documents are still outstanding on your file:</p>'
                    . '<ul>' . $items_html . '</ul>'
                    . '<p>Please submit or bring these to the Records Office at your earliest convenience.</p>';
                $altBody = "The following documents are still outstanding on your file:\n" . $items_text;

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
$ac_status = trim($_GET['status'] ?? '');
$ac_grade  = trim($_GET['grade']  ?? '');
$ac_strand = trim($_GET['strand'] ?? '');
$ac_page   = max(1, (int) ($_GET['page'] ?? 1));
$ac_per_page = 10;

$allowed_statuses_filter = ['review', 'incomplete'];
if (!in_array($ac_status, $allowed_statuses_filter, true)) $ac_status = '';
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

$having_status_sql = match ($ac_status) {
    'review'     => "AND review_count > 0",
    'incomplete' => "AND review_count = 0",
    default      => '',
};

$return_qs = http_build_query(array_filter([
    'q' => $ac_q, 'status' => $ac_status, 'grade' => $ac_grade, 'strand' => $ac_strand,
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
               ) AS submitted_count,
               (SELECT COUNT(*) FROM enrollment_requirements er
                  JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                 WHERE er.enrollment_id = e.enrollment_id AND er.status = 'pending_review' AND rt.is_active = 1
               ) AS review_count
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = s.student_id
        WHERE $where_sql
        HAVING submitted_count < applicable_reqs $having_status_sql
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

// ── Workload counters (search + grade + strand applied, status ignored so
// each counter always shows what clicking it would switch to) ─────────────
$workload_sql = "
    SELECT
        SUM(review_count > 0) AS c_review,
        SUM(review_count = 0) AS c_incomplete,
        COUNT(*) AS c_total
    FROM (
        SELECT e.enrollment_id,
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
        WHERE $where_sql
        HAVING submitted_count < applicable_reqs
    ) t
";
$workload_stmt = $conn->prepare($workload_sql);
if ($types !== '') $workload_stmt->bind_param($types, ...$params);
$workload_stmt->execute();
$workload = $workload_stmt->get_result()->fetch_assoc();
$workload_stmt->close();
$ac_counts = [
    'review'     => (int) ($workload['c_review'] ?? 0),
    'incomplete' => (int) ($workload['c_incomplete'] ?? 0),
    'total'      => (int) ($workload['c_total'] ?? 0),
];

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
    HAVING submitted_count < applicable_reqs $having_status_sql
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
    .sr-only {
      position:absolute; width:1px; height:1px; padding:0; margin:-1px;
      overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0;
    }

    /* Toolbar — same search/select/pill visual language as
       admin/staff_manage.php's .staff-toolbar, reused here as .pa-toolbar
       since this page keeps its own local <style> block rather than
       pulling in css_admin.css. */
    .pa-toolbar {
      display:flex; align-items:center; gap:10px; flex-wrap:wrap;
      background:#fff; border:1px solid #E8E8F0; border-radius:12px;
      padding:14px 16px; margin-bottom:1.25rem; box-shadow:0 10px 24px rgba(16,42,29,.05);
    }
    .pa-search { position:relative; flex:1 1 240px; min-width:180px; }
    .pa-search svg {
      position:absolute; left:12px; top:50%; width:15px; height:15px; margin-top:-7.5px;
      color:#5F6B74; pointer-events:none;
    }
    .pa-search input {
      width:100%; height:38px; box-sizing:border-box; border:1px solid #D4D9D6; border-radius:9px;
      background:#fff; padding:0 13px 0 34px; font-size:13px; font-family:inherit;
      color:#1A1A2E; outline:none; transition:border-color .15s, box-shadow .15s;
    }
    .pa-search input::placeholder { color:#8A8A9A; }
    .pa-search input:focus { border-color:#386641; box-shadow:0 0 0 3px rgba(56,102,65,.14); }
    /* width:auto overrides css_staff.css's global `select { width:100% }` —
       without it these selects stretch to fill the flex row (and wrap onto
       their own full-width line) instead of sizing to their content. */
    .pa-filter-select {
      width:auto; flex:0 0 auto; height:38px; border:1px solid #D4D9D6; border-radius:9px;
      background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%235F6B74' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
      padding:0 28px 0 12px; font-size:12.5px; font-weight:500; font-family:inherit; color:#1A1A2E;
      outline:none; cursor:pointer; appearance:none; -webkit-appearance:none;
      transition:border-color .15s, box-shadow .15s;
    }
    .pa-filter-select:hover { border-color:#386641; }
    .pa-filter-select:focus { border-color:#386641; box-shadow:0 0 0 3px rgba(56,102,65,.14); }
    .pa-filter-clear {
      height:38px; padding:0 14px; border-radius:9px; font-size:13px; font-weight:500;
      cursor:pointer; font-family:inherit; background:#fff; color:#5A5A72; border:1px solid #D4D9D6;
      text-decoration:none; display:inline-flex; align-items:center; transition:border-color .15s, color .15s;
    }
    .pa-filter-clear:hover { border-color:#ADADBD; color:#1A1A2E; }
    .btn-filter { background:#1E4D3B; color:#fff; border:none; height:38px; padding:0 14px; border-radius:9px; font-size:13px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-filter:hover { background:#163829; }

    /* ── Workload counters — compact icon+card summary (same colored-chip
       language as the dashboard's .staff-stat-card, laid out horizontally
       and clickable as a status filter). ── */
    .pa-counters { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:1.1rem; }
    .pa-counter {
      display:flex; align-items:center; gap:16px; background:#fff; border:1px solid #E8E8F0;
      border-radius:14px; padding:20px 24px; min-height:88px; cursor:pointer; font-family:inherit; text-align:left;
      text-decoration:none; color:inherit;
      box-shadow:0 1px 3px rgba(30,77,59,.06);
      transition:border-color .15s, background-color .15s, box-shadow .15s, transform .1s;
    }
    .pa-counter:visited { color:inherit; }
    .pa-counter:hover, .pa-counter:focus { text-decoration:none; }
    .pa-counter:hover { border-color:#B7C8BF; box-shadow:0 3px 10px rgba(30,77,59,.1); }
    .pa-counter:active { transform:scale(0.98); }
    .pa-counter-icon {
      flex-shrink:0; width:46px; height:46px; border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      background:#F0F0F4; color:#6B6B7D;
    }
    .pa-counter-icon svg { width:22px; height:22px; }
    .pa-counter-body { display:flex; flex-direction:column; gap:3px; }
    .pa-counter-value { font-size:26px; font-weight:700; color:#1A1A2E; line-height:1.15; }
    .pa-counter-label { font-size:11.5px; font-weight:600; color:#8A8A9A; text-transform:uppercase; letter-spacing:.05em; }

    .pa-counter--total .pa-counter-icon      { background:#EEF0F4; color:#5A6472; }
    .pa-counter--review .pa-counter-icon     { background:#FEF6E8; color:#A9720A; }
    .pa-counter--incomplete .pa-counter-icon { background:#F0F0F4; color:#6B6B7D; }
    .pa-counter--admitted .pa-counter-icon   { background:#EAF3EE; color:#1E4D3B; }

    .pa-counter--total.active      { border-color:#B9C2CC; background:#F7F8FA; }
    .pa-counter--review.active     { border-color:#F3D896; background:#FEF9EF; }
    .pa-counter--incomplete.active { border-color:#C7C7D4; background:#F7F7FA; }
    .pa-counter--admitted.active   { border-color:#8FB59B; background:#F1F8F4; }

    .pa-result-count { font-size:12.5px; color:#5A5A72; margin:0.6rem 0 0.4rem; }

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
    .req-bar-fill.partial { background:#6FCF97; }
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
      max-height:94vh; overflow-y:auto; padding:1.1rem 1.4rem;
    }
    .pa-modal-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem; gap:12px; }
    .pa-modal-title { font-size:16px; font-weight:600; color:#1A1A2E; }
    .pa-modal-title .status-flag { margin-left:6px; vertical-align:middle; }
    .pa-modal-sub { font-size:12px; color:#5A5A72; margin-top:2px; }
    .pa-modal-close { background:none; border:none; cursor:pointer; color:#8A8A9A; font-size:20px; line-height:1; padding:4px; flex-shrink:0; }
    .pa-modal-close:hover { color:#1A1A2E; }
    .pa-modal-section-title {
      font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.08em;
      color:#2F6B4F; margin:12px 0 6px; padding-bottom:4px; border-bottom:0.5px solid #EBEBF0;
    }
    .pa-modal-section-title:first-of-type { margin-top:0; }

    .req-table-wrap {
      background:#FAFAFC; border:0.5px solid #EBEBF0; border-radius:10px; padding:4px 14px;
    }
    .req-review-table { width:100%; border-collapse:collapse; font-size:13px; }
    .req-review-table th {
      text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.05em;
      color:#8A8A9A; padding:9px 8px 9px 0; border-bottom:0.5px solid #EBEBF0;
    }
    .req-review-table td { padding:7px 8px 7px 0; border-bottom:0.5px solid #EBEBF0; vertical-align:middle; }
    .req-review-table tr:last-child td { border-bottom:none; }
    .req-review-table tr.no-border td { border-bottom:none; }
    .req-name { font-weight:500; color:#1A1A2E; }
    .req-status-cell { display:flex; flex-direction:column; align-items:flex-start; gap:6px; width:190px; }
    /* Approve / Decline — the same segmented-pill shape as the Online /
       In person toggle below. The radio inside each label is still the
       real form control, just visually hidden; clicking the active side
       again clears the decision (see onDecisionClick). */
    .req-decision {
      display:inline-flex; border:0.5px solid #D4D4E0; border-radius:20px; padding:2px; background:#FAFAFC;
    }
    .req-radio {
      position:relative; display:inline-flex; align-items:center; margin:0; line-height:1.3;
      font-size:11px; font-weight:600; color:#8A8A9A;
      padding:4px 12px; border-radius:20px; cursor:pointer; user-select:none;
      transition:background-color .15s, color .15s;
    }
    .req-radio input[type="radio"] { position:absolute; opacity:0; width:1px; height:1px; margin:0; pointer-events:none; }
    .req-radio:has(input:focus-visible) { outline:2px solid #1E4D3B; outline-offset:1px; }
    .req-radio:not(.is-checked):not(.req-radio-disabled):hover { color:#1A1A2E; }
    .req-radio-approve.is-checked { background:#1E4D3B; color:#fff; }
    .req-radio-reject.is-checked { background:#C0392B; color:#fff; }
    .req-radio-disabled { opacity:0.45; cursor:not-allowed; }

    /* "Mark all seen in person" — flips every row that still has the
       Online / In person choice to In person and ticks it. */
    .req-mark-all-wrap { display:flex; justify-content:flex-end; margin:0 0 8px; }
    /* css_staff.css gives every <label> top/bottom margins (and drops the top
       one on :first-of-type), which made the two halves of a pill toggle
       different heights — these pill labels opt out. */
    .req-radio, .req-mark-all, .req-manual-confirm { margin:0; }
    .req-mark-all {
      display:inline-flex; align-items:center; gap:6px;
      font-size:11.5px; font-weight:600; color:#A9720A; cursor:pointer; user-select:none;
      background:#FEF6E8; border:0.5px solid #F3D896; border-radius:20px; padding:5px 14px;
    }
    .req-mark-all input[type="checkbox"] { margin:0; accent-color:#1E4D3B; cursor:pointer; }

    /* Full-row reason field — a second <tr> spanning all three columns,
       shown only while a Decline decision is active on that requirement.
       The row above drops its own border while this is open (see the
       .no-border toggle in onDecisionChange) so no rule sits directly
       above the textbox. */
    .req-reason-row { display:none; }
    .req-reason-row.show { display:table-row; }
    .req-reason-row td { padding-top:0; border-bottom:0.5px solid #EBEBF0; }
    .req-reason-input {
      width:100%; box-sizing:border-box; height:32px; padding:0 10px;
      border:0.5px solid #F0B8B2; border-radius:6px; font-family:inherit; font-size:12px; background:#FFFAFA;
    }
    .req-reason-input:focus { outline:none; border-color:#C0392B; }

    .req-verify-toggle {
      display:inline-flex; border:0.5px solid #D4D4E0; border-radius:20px; padding:2px; background:#FAFAFC;
    }
    .req-verify-toggle button {
      border:none; background:none; font-family:inherit; font-size:11px; font-weight:600; color:#8A8A9A;
      padding:4px 12px; border-radius:20px; cursor:pointer; transition:background-color .15s, color .15s;
    }
    .req-verify-toggle button.active { background:#1E4D3B; color:#fff; }
    .req-verify-toggle button:not(.active):hover { color:#1A1A2E; }

    .req-manual-confirm {
      display:flex; align-items:center; gap:6px; max-width:240px;
      font-size:11px; color:#A9720A; cursor:pointer; user-select:none;
      background:#FEF6E8; border:0.5px solid #F3D896; border-radius:6px; padding:5px 9px;
    }
    .req-manual-confirm input[type="checkbox"] { margin:0; accent-color:#1E4D3B; flex-shrink:0; cursor:pointer; }

    .req-note { font-size:11px; color:#5A5A72; }

    /* File column: empty state is a real dropzone, filled state is a chip */
    .req-dropzone {
      display:flex; align-items:center; gap:6px; justify-content:center;
      border:1.5px dashed #D4D4E0; border-radius:8px; padding:10px 10px;
      min-width:150px; max-width:210px; cursor:pointer; text-align:center;
      color:#8A8A9A; font-size:11px; font-weight:500; background:#FAFAFC;
      transition:border-color .15s, background-color .15s, color .15s;
    }
    .req-dropzone:hover, .req-dropzone.is-dragover {
      border-color:var(--brand-accent, #1E4D3B); background:#EEF6F1; color:#1E4D3B;
    }
    .req-dropzone svg { flex-shrink:0; }
    .req-dropzone-input { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }

    .req-file-chip {
      display:flex; align-items:center; gap:8px; border:0.5px solid #E0E0EC; border-radius:8px;
      padding:6px 8px; background:#fff; max-width:210px;
    }
    .req-file-chip-icon {
      flex-shrink:0; width:26px; height:26px; border-radius:6px; background:#EAF3EE;
      display:flex; align-items:center; justify-content:center; color:#1E4D3B;
    }
    .req-file-chip-info { min-width:0; flex:1; display:flex; flex-direction:column; }
    .req-file-chip-name {
      font-size:12px; font-weight:500; color:#1A1A2E; text-decoration:none;
      overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px;
    }
    a.req-file-chip-name:hover { color:#1E4D3B; text-decoration:underline; }
    .req-file-chip-meta { font-size:10.5px; color:#8A8A9A; }
    .req-file-chip-remove {
      flex-shrink:0; width:20px; height:20px; border-radius:50%; border:none; background:transparent;
      color:#ADADBD; cursor:pointer; display:flex; align-items:center; justify-content:center;
      font-size:15px; line-height:1; padding:0;
    }
    .req-file-chip-remove:hover { background:#FDF0EF; color:#C0392B; }

    .req-file-link {
      display:inline-flex; align-items:center; gap:8px; text-decoration:none; max-width:210px;
      border:0.5px solid #E0E0EC; border-radius:8px; padding:6px 8px; background:#fff;
      transition:border-color .15s;
    }
    .req-file-link-icon {
      flex-shrink:0; width:26px; height:26px; border-radius:6px; background:#EAF3EE;
      display:flex; align-items:center; justify-content:center; color:#1E4D3B;
    }
    .req-file-name {
      color:#1A1A2E; font-size:12px; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .req-file-link:hover { border-color:var(--brand-accent, #1E4D3B); }
    .req-file-link:hover .req-file-name { color:#1E4D3B; text-decoration:underline; }
    .req-file-empty { display:inline-block; color:#ADADBD; font-size:12px; }
    .req-blur-badge-warn {
      display:inline-block; margin-top:3px; font-size:10.5px; font-weight:600;
      color:#C06A10; background:#FFF4E6; border-radius:10px; padding:1px 8px;
    }
    .req-old-rejection { font-size:11px; color:#C0392B; margin-bottom:2px; }

    .dr-modal-footer {
      display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
      margin-top:1.25rem; padding-top:1rem; border-top:0.5px solid #EBEBF0;
    }
    .dr-modal-footer .btn-followup, .dr-modal-footer .btn-save {
      height:34px; padding:0 16px; font-size:12.5px;
    }
    .dr-footer-actions { display:flex; gap:8px; flex-wrap:wrap; margin-left:auto; }

    /* Filled tint + stronger border so it reads as a live button — the old
       white-with-faint-border version looked disabled even when it wasn't. */
    .btn-followup {
      display:inline-flex; align-items:center; gap:6px;
      background:#FEF6E8; color:#8A5A00; border:1px solid #E9C46A; border-radius:8px;
      font-weight:600; cursor:pointer; font-family:inherit;
      transition:background-color .15s, border-color .15s;
    }
    .btn-followup svg { width:14px; height:14px; flex-shrink:0; }
    .btn-followup:hover:not(:disabled) { background:#FCEBC8; border-color:#D9A93A; }
    /* Save Changes — same look and spot as document_review.php's (where it
       took over the old Approve & Admit button). */
    .btn-save {
      background:var(--brand-primary); color:#fff; border:none; border-radius:8px;
      font-weight:600; cursor:pointer; font-family:inherit;
    }
    .btn-save:hover:not(:disabled) { background:#163829; }
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
    <div class="staff-topbar-left"></div>
    <div style="display:flex; align-items:center; gap:14px;">
      <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
    </div>
  </div>

  <div class="staff-content">

    <?php if ($flash): ?>
      <div class="flash flash-<?= htmlspecialchars($flash_type) ?>" data-ui-toast><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : 'Records Portal' ?>
      <span class="dr-count-pill"><?= $total_count ?></span>
    </p>
    <h1 class="page-title">Student Accountabilities</h1>
    <p class="page-sub">Enrolled students who still owe a document. Review uploads, or check off what was verified in person.</p>

    <?php
      $ac_icon_total      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
      $ac_icon_review     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>';
      $ac_icon_incomplete = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>';

      $ac_counter_base = array_filter(['q' => $ac_q, 'grade' => $ac_grade, 'strand' => $ac_strand]);
      $ac_counter_defs = [
        ''           => ['label' => 'Total',        'value' => $ac_counts['total'],       'class' => 'pa-counter--total',      'icon' => $ac_icon_total],
        'review'     => ['label' => 'Needs Review',  'value' => $ac_counts['review'],      'class' => 'pa-counter--review',     'icon' => $ac_icon_review],
        'incomplete' => ['label' => 'Incomplete',    'value' => $ac_counts['incomplete'],  'class' => 'pa-counter--incomplete', 'icon' => $ac_icon_incomplete],
      ];
    ?>
    <div class="pa-counters">
      <?php foreach ($ac_counter_defs as $status_val => $def): ?>
        <a class="pa-counter <?= $def['class'] ?><?= $ac_status === $status_val ? ' active' : '' ?>"
           href="?<?= http_build_query(array_merge($ac_counter_base, ['status' => $status_val])) ?>">
          <span class="pa-counter-icon"><?= $def['icon'] ?></span>
          <span class="pa-counter-body">
            <span class="pa-counter-value"><?= $def['value'] ?></span>
            <span class="pa-counter-label"><?= $def['label'] ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="GET" action="accountabilities" class="pa-toolbar" id="acFilterForm">
      <div class="pa-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <label for="q" class="sr-only">Search</label>
        <input type="search" id="q" name="q" value="<?= htmlspecialchars($ac_q) ?>" placeholder="Search by name or control number" autocomplete="off">
      </div>
      <select class="pa-filter-select" id="status" name="status" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option value="review"     <?= $ac_status === 'review'     ? 'selected' : '' ?>>Needs Review</option>
        <option value="incomplete" <?= $ac_status === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
      </select>
      <select class="pa-filter-select" id="grade" name="grade" aria-label="Filter by grade">
        <option value="">All grades</option>
        <?php foreach ($allowed_grades as $g): ?>
          <option value="<?= $g ?>" <?= $ac_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
        <?php endforeach; ?>
      </select>
      <select class="pa-filter-select" id="strand" name="strand" aria-label="Filter by strand">
        <option value="">All strands</option>
        <?php foreach ($allowed_strands as $s): ?>
          <option value="<?= $s ?>" <?= $ac_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($ac_q !== '' || $ac_status !== '' || $ac_grade !== '' || $ac_strand !== ''): ?>
        <a href="accountabilities" class="pa-filter-clear">Clear</a>
      <?php endif; ?>
      <noscript><button type="submit" class="btn-filter">Filter</button></noscript>
    </form>

    <?php if (empty($students)): ?>

      <div class="pa-empty">
        <?= $total_count === 0 && $ac_q === '' && $ac_status === '' && $ac_grade === '' && $ac_strand === ''
              ? 'No enrolled students currently have outstanding documents.'
              : 'No students match your filters.' ?>
      </div>

    <?php else: ?>

      <?php
        $ac_range_start = $total_count === 0 ? 0 : $ac_offset + 1;
        $ac_range_end   = min($ac_offset + count($students), $total_count);
      ?>
      <p class="pa-result-count">Showing <?= $ac_range_start ?>&ndash;<?= $ac_range_end ?> of <?= $total_count ?> student<?= $total_count === 1 ? '' : 's' ?></p>

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
        $base_params = array_filter(['q' => $ac_q, 'status' => $ac_status, 'grade' => $ac_grade, 'strand' => $ac_strand]);
      ?>
        <div class="pa-pagination">
          <span>Page <?= $ac_page ?> of <?= $total_pages ?></span>
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
<?php require_once __DIR__ . '/../../../shared/includes/ui_modal_css.php'; ?>
<script src="<?= APP_URL ?>/assets/js/blur_detect.js?v=<?= filemtime(BASE_PATH . '/assets/js/blur_detect.js') ?>"></script>
<script>
  const studentData = <?= json_encode($modal_data, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  const returnQs = <?= json_encode($return_qs, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

  // ── Realtime toolbar: search re-submits the GET filter form shortly
  // after typing stops (no "Filter" button needed), grade/strand
  // re-submit the moment a selection changes.
  (function () {
    const form = document.getElementById('acFilterForm');
    const search = document.getElementById('q');
    if (!form || !search) return;
    if (search.value) {
      search.focus();
      const end = search.value.length;
      search.setSelectionRange(end, end);
    }
    let debounceTimer;
    search.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () { form.submit(); }, 400);
    });
    ['status', 'grade', 'strand'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', function () { form.submit(); });
    });
  })();

  function esc(s) {
    return (s ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  const fileIconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>';
  const imageIconSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5L5 21"/></svg>';
  const uploadIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg>';

  function fileKindIcon(name) {
    const ext = (name || '').split('.').pop().toLowerCase();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) ? imageIconSvg : fileIconSvg;
  }

  function formatBytes(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  const dropzoneMarkup = (rid) =>
    '<div class="req-dropzone" id="dropzone-' + rid + '" tabindex="0" ' +
      'onclick="document.getElementById(\'fileinput-' + rid + '\').click()" ' +
      'ondragover="onDropzoneDragOver(event, this)" ondragleave="onDropzoneDragLeave(event, this)" ' +
      'ondrop="onDropzoneDrop(event, ' + rid + ')">' +
      uploadIconSvg + '<span>Drag a file here or click to browse</span>' +
    '</div>' +
    '<input type="file" class="req-dropzone-input" id="fileinput-' + rid + '" name="doc_upload[' + rid + ']" ' +
      'accept=".pdf,.jpg,.jpeg,.png" onchange="onDropzoneFileChange(' + rid + ', this.files[0])">';

  function fileChipMarkup(rid, file) {
    return (
      '<div class="req-file-chip" id="filechip-' + rid + '">' +
        '<div class="req-file-chip-icon">' + fileKindIcon(file.name) + '</div>' +
        '<div class="req-file-chip-info">' +
          '<span class="req-file-chip-name">' + esc(file.name) + '</span>' +
          '<span class="req-file-chip-meta">' + esc((file.name.split('.').pop() || '').toUpperCase()) + ' &middot; ' + formatBytes(file.size) + '</span>' +
        '</div>' +
        '<button type="button" class="req-file-chip-remove" title="Remove file" onclick="removeDropzoneFile(' + rid + ')">&times;</button>' +
      '</div>'
    );
  }

  function renderRequirementsTab(enrollmentId, requirements) {
    let rows = requirements.map(r => {
      const rid = r.requirement_type_id;
      const fileCell = r.file_path
        ? '<a class="req-file-link" href="view_document?id=' + r.enrollment_requirement_id + '" target="_blank">' +
            '<span class="req-file-link-icon">' + fileKindIcon(r.original_filename || r.file_path) + '</span>' +
            '<span class="req-file-name">' + esc(r.original_filename || 'View file') + '</span>' +
          '</a><span class="req-blur-badge" id="blur-badge-' + r.enrollment_requirement_id + '"></span>'
        : '';

      let control, dropzoneCell, reasonRow = '';
      if (r.status === 'submitted') {
        control = '<span class="status-flag status-review" style="color:#1E4D3B;background:#EAF3EE;border-color:#BFE0CD;">Approved</span>';
        dropzoneCell = fileCell || '<span class="req-file-empty">No file uploaded</span>';
      } else if (r.status === 'pending_review') {
        // A file is normally already on hand by the time a row reaches
        // this status. If somehow not (invariant broken), there's nothing
        // to approve or decline yet, so skip the controls entirely rather
        // than show a pair of pills that can't be used.
        control = r.file_path
          ? '<div class="req-decision">' +
              '<label class="req-radio req-radio-approve" onclick="onDecisionClick(event, this, ' + rid + ')">' +
                '<input type="radio" name="requirements[' + rid + ']" value="submitted" onchange="onDecisionChange(this, ' + rid + ')"> Approve' +
              '</label>' +
              '<label class="req-radio req-radio-reject" onclick="onDecisionClick(event, this, ' + rid + ')">' +
                '<input type="radio" name="requirements[' + rid + ']" value="rejected" onchange="onDecisionChange(this, ' + rid + ')"> Decline' +
              '</label>' +
            '</div>'
          : '';
        dropzoneCell = fileCell || '<span class="req-file-empty">No file uploaded</span>';
        reasonRow =
          '<tr class="req-reason-row" id="reason-row-' + rid + '">' +
            '<td></td>' +
            '<td colspan="2">' +
              '<input type="text" class="req-reason-input" id="reason-' + rid + '" ' +
                'name="reasons[' + rid + ']" placeholder="Reason for declining (required) — the student will see this">' +
            '</td>' +
          '</tr>';
      } else {
        // 'pending' (nothing on file yet) or 'rejected' (file already
        // cleared, awaiting resubmission).
        const rejectionNote = r.status === 'rejected' && r.rejection_reason
          ? '<div class="req-old-rejection">Declined: ' + esc(r.rejection_reason) + ' — awaiting resubmission</div>'
          : '';

        control =
          rejectionNote +
          '<div class="req-verify-toggle" role="group" data-rid="' + rid + '">' +
            '<button type="button" class="active" data-mode="online" onclick="setVerifyMode(' + rid + ', \'online\', this)">Online</button>' +
            '<button type="button" data-mode="person" onclick="setVerifyMode(' + rid + ', \'person\', this)">In person</button>' +
          '</div>' +
          '<div id="verify-online-' + rid + '">' +
            '<div class="req-decision" id="decision-' + rid + '" style="display:none;">' +
              '<label class="req-radio req-radio-approve" onclick="onDecisionClick(event, this, ' + rid + ')" id="approve-label-' + rid + '">' +
                '<input type="radio" name="requirements[' + rid + ']" value="submitted" onchange="onDecisionChange(this, ' + rid + ')"> Approve' +
              '</label>' +
              '<label class="req-radio req-radio-reject" onclick="onDecisionClick(event, this, ' + rid + ')" id="reject-label-' + rid + '">' +
                '<input type="radio" name="requirements[' + rid + ']" value="rejected" onchange="onDecisionChange(this, ' + rid + ')"> Decline' +
              '</label>' +
            '</div>' +
            '<div class="req-note" id="resubmit-note-' + rid + '" style="display:none;">Resubmitted — will need review after saving.</div>' +
          '</div>' +
          '<div id="verify-person-' + rid + '" style="display:none;">' +
            '<label class="req-manual-confirm">' +
              '<input type="checkbox" class="req-manual-checkbox" id="checkbox-' + rid + '" onchange="onManualCheck(this, ' + rid + ')"> Seen in person — mark Submitted' +
            '</label>' +
          '</div>' +
          '<input type="hidden" id="hidden-' + rid + '" name="requirements[' + rid + ']" value="">';

        dropzoneCell =
          '<div id="file-online-' + rid + '" data-prior-status="' + esc(r.status) + '">' + dropzoneMarkup(rid) + '</div>' +
          '<div id="file-person-' + rid + '" style="display:none;"><span class="req-file-empty">No file needed</span></div>';

        // Decline only becomes clickable here for a never-before-submitted
        // row (once a file lands in the dropzone) — a 'rejected' row hides
        // its decision controls again the moment a resubmitted file
        // arrives, so it never needs this reason field.
        if (r.status === 'pending') {
          reasonRow =
            '<tr class="req-reason-row" id="reason-row-' + rid + '">' +
              '<td></td>' +
              '<td colspan="2">' +
                '<input type="text" class="req-reason-input" id="reason-' + rid + '" ' +
                  'name="reasons[' + rid + ']" placeholder="Reason for declining (required) — the student will see this">' +
              '</td>' +
            '</tr>';
        }
      }

      return (
        '<tr id="req-row-' + rid + '">' +
          '<td class="req-name">' + esc(r.name) + (r.is_required ? ' <span class="req">*</span>' : '') + '</td>' +
          '<td><div class="req-status-cell">' + control + '</div></td>' +
          '<td>' + dropzoneCell + '</td>' +
        '</tr>' +
        reasonRow
      );
    }).join('');

    // Only rows that still offer the Online / In person choice can be bulk-
    // marked; already-approved or awaiting-review rows are left alone.
    const canMarkAll = requirements.some(r => r.status === 'pending' || r.status === 'rejected');
    const markAll = canMarkAll
      ? '<div class="req-mark-all-wrap"><label class="req-mark-all">' +
          '<input type="checkbox" id="markAllPerson" onchange="markAllInPerson(this.checked)"> Mark all as seen in person' +
        '</label></div>'
      : '';

    return (
      '<div class="pa-modal-section-title">Document Requirements</div>' +
      '<form method="POST" action="accountabilities" id="reqSaveForm" enctype="multipart/form-data">' +
        '<input type="hidden" name="enrollment_id" value="' + enrollmentId + '">' +
        '<input type="hidden" name="return_qs" value="' + esc(returnQs) + '">' +
        '<input type="hidden" name="save_requirements" value="1">' +
        markAll +
        '<div class="req-table-wrap">' +
          '<table class="req-review-table">' +
            '<thead><tr><th>Requirement</th><th>Verification</th><th>File</th></tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
          '</table>' +
        '</div>' +
      '</form>'
    );
  }

  // Sets (or with value=null, clears) the Approve/Decline toggle for one
  // requirement. Rows with nothing on file also carry a hidden
  // requirements[rid] field placed AFTER the radios — PHP keeps the last
  // value for a repeated name, so that field is what the server actually
  // reads and must mirror the toggle (left blank it silently discarded
  // every Online approve/decline).
  function setDecision(rid, value, group) {
    if (group) {
      group.querySelectorAll('.req-radio').forEach(function (l) {
        const input = l.querySelector('input');
        input.checked = value !== null && input.value === value;
        l.classList.toggle('is-checked', input.checked);
      });
    }
    const hidden = document.getElementById('hidden-' + rid);
    if (hidden) hidden.value = value || '';

    // Decline keeps its reason field: it opens whenever Decline is the
    // active side, and whatever was typed survives toggling away and back.
    const reasonRow = document.getElementById('reason-row-' + rid);
    const reasonInput = document.getElementById('reason-' + rid);
    if (reasonRow && reasonInput) {
      const rejecting = value === 'rejected';
      reasonRow.classList.toggle('show', rejecting);
      if (rejecting) reasonInput.setAttribute('required', 'required');
      else reasonInput.removeAttribute('required');
      if (rejecting) reasonInput.focus();

      // The row above the reason field drops its own border while the
      // field is open, so no rule sits directly above the textbox.
      const parentRow = document.getElementById('req-row-' + rid);
      if (parentRow) parentRow.classList.toggle('no-border', rejecting);
    }
    onReqFormChanged();
  }

  // Keyboard path (arrow keys move between the visually hidden radios).
  function onDecisionChange(radio, rid) {
    setDecision(rid, radio.value, radio.closest('.req-decision'));
  }

  // Mouse path — handled manually so clicking the side that's already
  // active clears the decision instead of doing nothing.
  function onDecisionClick(e, label, rid) {
    e.preventDefault();
    const input = label.querySelector('input');
    if (!input || input.disabled) return;
    setDecision(rid, input.checked ? null : input.value, label.closest('.req-decision'));
  }

  function onManualCheck(checkbox, rid) {
    const hidden = document.getElementById('hidden-' + rid);
    if (hidden) hidden.value = checkbox.checked ? 'submitted' : '';
    syncMarkAll();
    onReqFormChanged();
  }

  // Toggles which verification path is visible for a requirement that has
  // nothing on file yet — "Online" (dropzone + Approve/Decline once a file
  // exists) or "In person" (a direct checkbox, no file involved).
  function setVerifyMode(rid, mode, btn) {
    const toggle = btn.closest('.req-verify-toggle');
    if (toggle) toggle.querySelectorAll('button').forEach(b => b.classList.toggle('active', b === btn));

    const onlineVerify = document.getElementById('verify-online-' + rid);
    const personVerify = document.getElementById('verify-person-' + rid);
    const onlineFile   = document.getElementById('file-online-' + rid);
    const personFile   = document.getElementById('file-person-' + rid);
    const checkbox     = document.getElementById('checkbox-' + rid);

    const isOnline = mode === 'online';
    if (onlineVerify) onlineVerify.style.display = isOnline ? '' : 'none';
    if (personVerify) personVerify.style.display = isOnline ? 'none' : '';
    if (onlineFile) onlineFile.style.display = isOnline ? '' : 'none';
    if (personFile) personFile.style.display = isOnline ? 'none' : '';

    // Only one path's choice may count — switching clears the other one, so
    // a stray choice can't be submitted from a panel that's now hidden.
    if (isOnline) {
      if (checkbox && checkbox.checked) {
        checkbox.checked = false;
        onManualCheck(checkbox, rid);
      }
    } else {
      const decision = document.getElementById('decision-' + rid);
      if (decision) setDecision(rid, null, decision);
    }
    syncMarkAll();
  }

  // "Mark all as seen in person" — every row that still offers the
  // Online / In person choice is switched to In person and ticked
  // (unticking reverses it back to Online).
  function markAllInPerson(checked) {
    document.querySelectorAll('#reqSaveForm .req-verify-toggle').forEach(function (toggle) {
      const rid = toggle.dataset.rid;
      const btn = toggle.querySelector('button[data-mode="' + (checked ? 'person' : 'online') + '"]');
      if (btn) setVerifyMode(rid, checked ? 'person' : 'online', btn);
      const checkbox = document.getElementById('checkbox-' + rid);
      if (checkbox && checkbox.checked !== checked) {
        checkbox.checked = checked;
        onManualCheck(checkbox, rid);
      }
    });
    syncMarkAll();
  }

  function syncMarkAll() {
    const master = document.getElementById('markAllPerson');
    if (!master) return;
    const boxes = Array.from(document.querySelectorAll('#reqSaveForm .req-manual-checkbox'));
    const n = boxes.filter(b => b.checked).length;
    master.checked = boxes.length > 0 && n === boxes.length;
    master.indeterminate = n > 0 && n < boxes.length;
  }

  function refreshApproveGate(rid, hasFile) {
    const decision = document.getElementById('decision-' + rid);
    if (decision) decision.style.display = hasFile ? '' : 'none';
  }

  function onDropzoneDragOver(e, zone) {
    e.preventDefault();
    zone.classList.add('is-dragover');
  }
  function onDropzoneDragLeave(e, zone) {
    zone.classList.remove('is-dragover');
  }
  function onDropzoneDrop(e, rid) {
    e.preventDefault();
    const zone = document.getElementById('dropzone-' + rid);
    if (zone) zone.classList.remove('is-dragover');
    const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('fileinput-' + rid);
    if (input) {
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
    }
    onDropzoneFileChange(rid, file);
  }

  function removeDropzoneFile(rid) {
    const input = document.getElementById('fileinput-' + rid);
    if (input) input.value = '';
    const cell = document.getElementById('file-online-' + rid);
    if (cell) cell.innerHTML = dropzoneMarkup(rid);
    const hidden = document.getElementById('hidden-' + rid);
    if (hidden) hidden.value = '';
    const note = document.getElementById('resubmit-note-' + rid);
    if (note) note.style.display = 'none';
    // No file means nothing to approve or decline any more.
    const decision = document.getElementById('decision-' + rid);
    if (decision) setDecision(rid, null, decision);
    refreshApproveGate(rid, false);
    onReqFormChanged();
  }

  function onDropzoneFileChange(rid, file) {
    const hidden = document.getElementById('hidden-' + rid);
    const cell = document.getElementById('file-online-' + rid);

    if (!file) { removeDropzoneFile(rid); return; }

    // A file swapped in on a row that was just declined needs a fresh
    // review — auto-approving a resubmission straight through this
    // dropzone would silently bypass it, so it goes back to the review
    // queue instead of exposing Approve here. A never-before-submitted
    // row keeps the direct Approve/Decline shortcut, gated on sharpness.
    const isRejected = cell && cell.dataset.priorStatus === 'rejected';

    function finish() {
      if (cell) cell.innerHTML = fileChipMarkup(rid, file);
      if (isRejected) {
        if (hidden) hidden.value = 'pending_review';
        const note = document.getElementById('resubmit-note-' + rid);
        if (note) note.style.display = '';
        const decision = document.getElementById('decision-' + rid);
        if (decision) decision.style.display = 'none';
      } else {
        refreshApproveGate(rid, true);
      }
      onReqFormChanged();
    }

    if (typeof scoreImageSharpness !== 'function' || !file.type.startsWith('image/')) {
      finish();
      return;
    }

    if (cell) cell.innerHTML = '<div class="req-note">Checking image sharpness…</div>';

    scoreImageSharpness(file).then(function (score) {
      if (score < BLUR_THRESHOLD) {
        removeDropzoneFile(rid);
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'warning', title: 'Image looks blurry', text: 'This image looks blurry — please attach a clearer photo or scan.', confirmButtonColor: '#1E4D3B' });
        } else {
          alert('This image looks blurry — please attach a clearer photo or scan.');
        }
      } else {
        finish();
      }
    }).catch(function () {
      // Couldn't score it (corrupt/unsupported image) — never block on a
      // check that itself failed; normal server-side validation still
      // catches a genuinely bad file.
      finish();
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

  // Shared handler code calls this after every form change; document_review
  // uses it to relabel Save as "Save & Admit". Nothing to admit here.
  function onReqFormChanged() {}

  const mailIconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="m22 6-10 7L2 6"/></svg>';

  function openModal(enrollmentId) {
    const a = studentData[enrollmentId];
    if (!a) return;

    const fullName = buildFullName(a);
    const status = computeStatus(a);
    const followupBtn = '<button type="button" class="btn-followup" onclick="confirmFollowup(' + enrollmentId + ')"' +
      (hasOutstanding(a) ? '' : ' disabled title="Nothing outstanding for this student"') +
      '>' + mailIconSvg + 'Request Follow-up</button>';

    document.getElementById('paModal').innerHTML = `
      <div class="pa-modal-head">
        <div>
          <div class="pa-modal-title">${esc(fullName)} <span class="status-flag ${status.cls}">${status.label}</span></div>
          <div class="pa-modal-sub">Grade ${esc(a.grade_level)} &ndash; ${esc(a.strand)}</div>
        </div>
        <button class="pa-modal-close" onclick="closeModal()">&times;</button>
      </div>

      ${renderRequirementsTab(enrollmentId, a.requirements)}

      <div class="dr-modal-footer">
        <div class="dr-footer-actions">
          ${followupBtn}
          <button type="submit" form="reqSaveForm" class="btn-save">Save Changes</button>
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
  // roles/student/public/admission.php (see js/blur_detect.js). A flagged row also has
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
