<?php
// Records — the single admission-review screen. Replaces the old two-stage
// pending_applications.php ("approve" the whole application) +
// requirement.php (checklist + mark admitted) pipeline: both depended on an
// enrollments.admission_status column that no longer exists in the schema,
// so that stage-1 "approve" gate was already dead in practice. Now every
// submitted application (enrollments.status = 'pending') shows up here
// directly. Records reviews/sets each requirement document's status from
// this page; the moment a Save Changes submission leaves every required
// (is_required=1) document 'submitted', admission (control number + email)
// fires automatically in that same request — see dr_finalize_admission()
// and its call sites below. The standalone approve_admit handler stays as a
// manual fallback/re-trigger, not the primary path.
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
            <p>Only the Records department can review admissions.</p>
            <a href="<?= APP_URL ?>/roles/staff/dashboard">&larr; Back to dashboard</a>
          </div></body></html>';
    exit();
}

$allowed_grades  = ['11', '12'];
$allowed_strands = ['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL-ICT'];
$allowed_req_statuses = ['pending', 'pending_review', 'submitted', 'rejected'];

$flash         = $_SESSION['dr_flash']         ?? '';
$flash_type    = $_SESSION['dr_flash_type']    ?? 'info';
unset($_SESSION['dr_flash'], $_SESSION['dr_flash_type']);

// Applicable requirement rows for one enrollment (respecting the
// public-JHS-only gate), shared by the approve_admit and send_followup
// handlers below.
function dr_load_requirements(mysqli $conn, int $enrollment_id, int $jhs_is_public): array {
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

// Flips students.admission_status to 'admitted' and assigns a control number
// (CTRL-YYYY-NNNN, sequential per year, lock+retry on the unique constraint)
// if one isn't already set. Caller must already be inside a transaction and
// must have already verified every required document is submitted. Returns
// the control number, or null if this student was already admitted (nothing
// to do) — shared by save_requirements' auto-admit-on-save path and the
// standalone approve_admit handler (kept as a manual fallback/re-trigger).
function dr_finalize_admission(mysqli $conn, int $enrollment_id, int $student_id, ?string $current_control_number): ?string {
    $adm_stmt = $conn->prepare("
        UPDATE students SET admission_status = 'admitted'
        WHERE student_id = ? AND admission_status = 'pending_requirements'
    ");
    $adm_stmt->bind_param('i', $student_id);
    if (!$adm_stmt->execute())
        throw new Exception('ADMISSION_STATUS_FAILED');
    $newly_admitted = $adm_stmt->affected_rows > 0;
    $adm_stmt->close();

    if (!$newly_admitted) return null;

    $new_cn = $current_control_number;
    if ($new_cn === null) {
        $cn_year   = date('Y');
        $cn_prefix = "CTRL-$cn_year-";
        $cn_like   = $cn_prefix . '%';

        $seq_stmt = $conn->prepare(
            "SELECT control_number FROM enrollments
             WHERE control_number LIKE ?
             ORDER BY control_number DESC LIMIT 1 FOR UPDATE"
        );
        $seq_stmt->bind_param('s', $cn_like);
        $seq_stmt->execute();
        $last_row = $seq_stmt->get_result()->fetch_assoc();
        $seq_stmt->close();

        $next_seq = 1;
        if ($last_row) {
            $parts    = explode('-', $last_row['control_number']);
            $next_seq = ((int) end($parts)) + 1;
        }

        $attempts = 0;
        $cn_saved = false;
        while (!$cn_saved && $attempts < 3) {
            $attempts++;
            $new_cn = $cn_prefix . str_pad((string) $next_seq, 4, '0', STR_PAD_LEFT);
            $cn_stmt = $conn->prepare(
                "UPDATE enrollments SET control_number = ? WHERE enrollment_id = ? AND control_number IS NULL"
            );
            $cn_stmt->bind_param('si', $new_cn, $enrollment_id);
            if ($cn_stmt->execute()) {
                $cn_saved = true;
            } elseif ($conn->errno === 1062) {
                $next_seq++;
            } else {
                $cn_stmt->close();
                throw new Exception('CONTROL_NUMBER_FAILED: ' . $conn->error);
            }
            $cn_stmt->close();
        }
        if (!$cn_saved) {
            throw new Exception('CONTROL_NUMBER_FAILED: could not allocate a unique control number');
        }
    }

    return $new_cn;
}

// Best-effort — call only after the admission itself is already committed,
// since an SMTP hiccup should never undo a real admission.
function dr_send_admission_email(array $rec, string $controlNumber, array $followupItems): bool {
    if (empty($rec['email'])) return false;
    try {
        $mail = getMailer();
        $followup_html = '';
        if (!empty($followupItems)) {
            $followup_html = '<p>You may still bring or submit these when you can (they do not affect your admission):</p><ul><li>'
                . implode('</li><li>', array_map('htmlspecialchars', $followupItems)) . '</li></ul>';
        }
        $bodyHtml = '<p>Hi ' . htmlspecialchars($rec['given_name']) . ', congratulations! Your admission has been confirmed. Your control number is:</p>'
            . email_highlight_box($controlNumber)
            . email_detail_rows([
                'Applicant'      => trim($rec['family_name'] . ', ' . $rec['given_name']),
                'Grade & Strand' => 'Grade ' . $rec['grade_level'] . ' — ' . $rec['strand'],
                'School Year'    => $rec['school_year'],
                'Date Confirmed' => date('F j, Y'),
            ])
            . $followup_html
            . '<p style="margin-top:16px;">Please keep your control number for your records. The Records Office will reach out with next steps.</p>';
        $altBody = "Congratulations! Your admission has been confirmed.\nControl Number: $controlNumber";

        return send_branded_email(
            $mail,
            $rec['email'],
            trim($rec['given_name'] . ' ' . $rec['family_name']),
            'Your Admission is Confirmed ' . $controlNumber,
            'Admission Confirmation',
            $bodyHtml,
            $altBody
        );
    } catch (\Throwable $e) {
        error_log('[document_review.php] admission email failed: ' . $e->getMessage());
        return false;
    }
}

// ── Save requirement statuses for one enrollment ────────────────────────────
// Every applicable requirement row is re-evaluated: unchanged rows are
// skipped entirely, changed rows are updated, and if that leaves every
// required (is_required=1) row 'submitted', admission (control number +
// confirmation email) fires automatically in this same request — no separate
// "Approve & Admit" click needed for the common case.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requirements'])) {
    $enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
    $posted_status = $_POST['requirements'] ?? [];
    $posted_reason = $_POST['reasons']      ?? [];
    $return_qs     = $_POST['return_qs']    ?? '';

    if ($enrollment_id <= 0) {
        $_SESSION['dr_flash'] = 'Invalid record — please search again.';
        $_SESSION['dr_flash_type'] = 'error';
    } else {
        $chk = $conn->prepare("
            SELECT e.enrollment_id, e.status AS enrollment_status, e.control_number,
                   e.admission_grade_level AS grade_level, strd.strand_code AS strand, e.school_year,
                   st.student_id, st.family_name, st.given_name, st.email,
                   se.is_public AS jhs_is_public, st.admission_status
            FROM enrollments e
            JOIN students st ON st.student_id = e.student_id
            LEFT JOIN student_education se ON se.student_id = st.student_id
            JOIN strands strd ON strd.strand_id = e.admission_strand
            WHERE e.enrollment_id = ?
            LIMIT 1
        ");
        $chk->bind_param('i', $enrollment_id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$rec || $rec['enrollment_status'] !== 'pending') {
            $_SESSION['dr_flash'] = 'This record is no longer available. Please search again.';
            $_SESSION['dr_flash_type'] = 'error';
        } else {
            $rows_stmt = $conn->prepare("
                SELECT er.enrollment_requirement_id, er.requirement_type_id, er.status, er.file_path,
                       rt.applicable_to, rt.requirement_name, rt.is_required
                FROM enrollment_requirements er
                JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                WHERE er.enrollment_id = ? AND rt.is_active = 1
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

            // Only rows whose posted status actually differs get touched —
            // and a row being rejected needs a reason, since that's what the
            // student sees on their Accountabilities page as to why.
            $save_errors = [];
            $updates = []; // enrollment_requirement_id => ['status' => ..., 'reason' => ...|null, 'delete_file' => ...|null]
            foreach ($rows as $r) {
                $erid = (int) $r['enrollment_requirement_id'];
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

                $updates[$erid] = [
                    'status' => $new_status,
                    'reason' => $new_status === 'rejected' ? $reason : null,
                    // Rejecting a document deletes its file outright — no "safe
                    // placeholder" is kept, the student re-uploads from scratch.
                    'delete_file' => ($new_status === 'rejected' && $r['file_path']) ? $r['file_path'] : null,
                    'upload' => $upload,
                ];
            }

            if (!empty($save_errors)) {
                $_SESSION['dr_flash'] = implode(' ', $save_errors);
                $_SESSION['dr_flash_type'] = 'error';
            } elseif (empty($updates)) {
                $_SESSION['dr_flash'] = 'No changes to save.';
                $_SESSION['dr_flash_type'] = 'info';
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
                    $upd_upload_stmt = $conn->prepare("
                        UPDATE enrollment_requirements
                        SET status = ?, submitted_at = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW(),
                            file_path = ?, original_filename = ?
                        WHERE enrollment_requirement_id = ?
                    ");
                    foreach ($updates as $erid => $u) {
                        $submitted_at = $u['status'] === 'submitted' ? date('Y-m-d') : null;
                        if ($u['delete_file']) {
                            $full_path = __DIR__ . '/../../../uploads/requirement_documents/' . $u['delete_file'];
                            if (is_file($full_path)) @unlink($full_path);
                            $upd_del_stmt->bind_param('sssii', $u['status'], $submitted_at, $u['reason'], $uid, $erid);
                            if (!$upd_del_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_del_stmt->error);
                        } elseif ($u['upload']) {
                            $stored_name = uniqid('reqdoc_', true) . '.' . $u['upload']['ext'];
                            $dest = __DIR__ . '/../../../uploads/requirement_documents/' . $stored_name;
                            if (!move_uploaded_file($u['upload']['tmp_name'], $dest))
                                throw new Exception('UPLOAD_MOVE_FAILED');
                            $orig_name = $u['upload']['original_name'];
                            $upd_upload_stmt->bind_param('sssissi', $u['status'], $submitted_at, $u['reason'], $uid, $stored_name, $orig_name, $erid);
                            if (!$upd_upload_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_upload_stmt->error);
                        } else {
                            $upd_stmt->bind_param('sssii', $u['status'], $submitted_at, $u['reason'], $uid, $erid);
                            if (!$upd_stmt->execute())
                                throw new Exception('UPDATE_REQUIREMENT_FAILED: ' . $upd_stmt->error);
                        }
                    }
                    $upd_stmt->close();
                    $upd_del_stmt->close();
                    $upd_upload_stmt->close();

                    // Resulting status per row, in-memory — same applicable_to/
                    // jhs_is_public filter as dr_load_requirements(), so this is
                    // equivalent to re-querying without the extra round trip.
                    $required_incomplete = false;
                    $followup_items = [];
                    foreach ($rows as $r) {
                        $erid = (int) $r['enrollment_requirement_id'];
                        $resulting_status = $updates[$erid]['status'] ?? $r['status'];
                        if ($resulting_status === 'submitted') continue;
                        if ((int) $r['is_required'] === 1) {
                            $required_incomplete = true;
                        } else {
                            $followup_items[] = $r['requirement_name'];
                        }
                    }

                    $new_cn = null;
                    if (!$required_incomplete && $rec['admission_status'] !== 'admitted') {
                        $new_cn = dr_finalize_admission($conn, $enrollment_id, (int) $rec['student_id'], $rec['control_number']);
                    }

                    mysqli_commit($conn);

                    if ($new_cn !== null) {
                        $email_ok = dr_send_admission_email($rec, $new_cn, $followup_items);
                        $_SESSION['dr_flash'] = $email_ok
                            ? 'Requirements updated — student admitted (control number ' . $new_cn . '). Confirmation email sent.'
                            : 'Requirements updated — student admitted (control number ' . $new_cn . '). The confirmation email could not be sent; please follow up manually.';
                        $_SESSION['dr_flash_type'] = $email_ok ? 'success' : 'error';
                    } else {
                        $_SESSION['dr_flash'] = 'Requirements updated.';
                        $_SESSION['dr_flash_type'] = 'success';
                    }
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $_SESSION['dr_flash'] = 'Something went wrong while saving. Please try again.';
                    $_SESSION['dr_flash_type'] = 'error';
                    error_log('[document_review.php] ' . $e->getMessage());
                }
            }
        }
    }
    header("Location: document_review" . ($return_qs !== '' ? '?' . $return_qs : ''));
    exit();
}

// ── Approve & admit ──────────────────────────────────────────────────────────
// The one deliberate "this application is accepted" action. Gated strictly on
// every required (is_required=1) document being 'submitted' — optional
// documents (Good Moral, 2x2 Picture, VEC) never block this, they just get
// listed in the admission email as still-outstanding follow-ups. Re-checked
// server-side regardless of what the disabled button state in the browser
// says, since that's just a UX hint, not a security boundary.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_admit'])) {
    $enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
    $return_qs     = $_POST['return_qs'] ?? '';

    $chk = $conn->prepare("
        SELECT e.enrollment_id, e.control_number,
               e.admission_grade_level AS grade_level, strd.strand_code AS strand, e.school_year,
               st.student_id, st.family_name, st.given_name, st.email, se.is_public AS jhs_is_public,
               st.admission_status
        FROM enrollments e
        JOIN students st ON st.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = st.student_id
        JOIN strands strd ON strd.strand_id = e.admission_strand
        WHERE e.enrollment_id = ? AND e.status = 'pending'
        LIMIT 1
    ");
    $chk->bind_param('i', $enrollment_id);
    $chk->execute();
    $rec = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$rec) {
        $_SESSION['dr_flash'] = 'This record is no longer available. Please search again.';
        $_SESSION['dr_flash_type'] = 'error';
    } elseif ($rec['admission_status'] === 'admitted') {
        $_SESSION['dr_flash'] = 'This student is already admitted.';
        $_SESSION['dr_flash_type'] = 'info';
    } else {
        $reqs = dr_load_requirements($conn, $enrollment_id, (int) $rec['jhs_is_public']);
        $required_incomplete = false;
        $followup_items = [];
        foreach ($reqs as $r) {
            if ($r['status'] === 'submitted') continue;
            if ((int) $r['is_required'] === 1) { $required_incomplete = true; break; }
            $followup_items[] = $r['requirement_name'];
        }

        if ($required_incomplete) {
            $_SESSION['dr_flash'] = 'All required documents must be submitted before admitting this student.';
            $_SESSION['dr_flash_type'] = 'error';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $new_cn = dr_finalize_admission($conn, $enrollment_id, (int) $rec['student_id'], $rec['control_number']);
                if ($new_cn === null) {
                    throw new Exception('ALREADY_ADMITTED');
                }

                // Student portal account creation happens later, at
                // treasury/payment.php, once this student's first payment
                // actually clears (not here — admission alone doesn't mean
                // they've committed to enrolling).
                mysqli_commit($conn);

                $email_ok = dr_send_admission_email($rec, $new_cn, $followup_items);
                $_SESSION['dr_flash'] = $email_ok
                    ? 'Student admitted — control number ' . $new_cn . '. Confirmation email sent.'
                    : 'Student admitted — control number ' . $new_cn . '. The confirmation email could not be sent; please follow up manually.';
                $_SESSION['dr_flash_type'] = $email_ok ? 'success' : 'error';
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['dr_flash'] = 'Something went wrong while admitting this student. Please try again.';
                $_SESSION['dr_flash_type'] = 'error';
                error_log('[document_review.php] ' . $e->getMessage());
            }
        }
    }
    header("Location: document_review" . ($return_qs !== '' ? '?' . $return_qs : ''));
    exit();
}

// ── Request follow-up ────────────────────────────────────────────────────────
// A general "nudge the guardian" email about whatever's currently outstanding
// — required or optional, before or after admission. Doesn't change any
// status, purely a reminder.
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
        $_SESSION['dr_flash'] = 'This record is no longer available. Please search again.';
        $_SESSION['dr_flash_type'] = 'error';
    } elseif (empty($rec['email'])) {
        $_SESSION['dr_flash'] = 'This student has no email on file — add one under Contact & Guardian Information first.';
        $_SESSION['dr_flash_type'] = 'error';
    } else {
        $reqs = dr_load_requirements($conn, $enrollment_id, (int) $rec['jhs_is_public']);
        $outstanding = array_filter($reqs, fn($r) => $r['status'] !== 'submitted');

        if (empty($outstanding)) {
            $_SESSION['dr_flash'] = 'Nothing outstanding for this applicant — no reminder needed.';
            $_SESSION['dr_flash_type'] = 'info';
        } else {
            try {
                $mail = getMailer();
                $items_html = '';
                foreach ($outstanding as $r) {
                    $tag = ((int) $r['is_required'] === 1) ? ' (required)' : ' (optional)';
                    $items_html .= '<li>' . htmlspecialchars($r['requirement_name']) . htmlspecialchars($tag) . '</li>';
                }
                $bodyHtml = '<p>Hi ' . htmlspecialchars($rec['given_name']) . ', this is a reminder that the following admission requirements are still outstanding:</p>'
                    . '<ul>' . $items_html . '</ul>'
                    . '<p>Please submit or bring these to the Records Office at your earliest convenience.</p>';
                $altBody = "The following admission requirements are still outstanding:\n"
                    . implode("\n", array_map(fn($r) => '- ' . $r['requirement_name'], $outstanding));

                send_branded_email(
                    $mail,
                    $rec['email'],
                    trim($rec['given_name'] . ' ' . $rec['family_name']),
                    'Outstanding Admission Requirements — Reminder',
                    'Requirements Reminder',
                    $bodyHtml,
                    $altBody
                );

                $_SESSION['dr_flash'] = 'Reminder email sent.';
                $_SESSION['dr_flash_type'] = 'success';
            } catch (\Throwable $e) {
                error_log('[document_review.php] followup email failed: ' . $e->getMessage());
                $_SESSION['dr_flash'] = 'Could not send the reminder email. Please try again.';
                $_SESSION['dr_flash_type'] = 'error';
            }
        }
    }
    header("Location: document_review" . ($return_qs !== '' ? '?' . $return_qs : ''));
    exit();
}

// ── Filters ──────────────────────────────────────────────────────────────
$dr_q      = trim($_GET['q']      ?? '');
$dr_status = trim($_GET['status'] ?? '');
$dr_grade  = trim($_GET['grade']  ?? '');
$dr_strand = trim($_GET['strand'] ?? '');
$dr_page   = max(1, (int) ($_GET['page'] ?? 1));
$dr_per_page = 15;

$allowed_statuses_filter = ['review', 'incomplete', 'admitted'];
if (!in_array($dr_status, $allowed_statuses_filter, true)) $dr_status = '';
if (!in_array($dr_grade, $allowed_grades, true))            $dr_grade  = '';
if (!in_array($dr_strand, $allowed_strands, true))          $dr_strand = '';

$where  = ["e.status = 'pending'"];
$types  = '';
$params = [];

if ($dr_q !== '') {
    $where[]  = "(s.family_name LIKE ? OR s.given_name LIKE ? OR e.control_number LIKE ?)";
    $like     = '%' . $dr_q . '%';
    $types   .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($dr_grade !== '') {
    $where[]  = "e.admission_grade_level = ?";
    $types   .= 's';
    $params[] = $dr_grade;
}
if ($dr_strand !== '') {
    $where[]  = "e.admission_strand = ?";
    $types   .= 'i';
    $params[] = strand_id($conn, $dr_strand);
}
$where_sql = implode(' AND ', $where);

$having_sql = match ($dr_status) {
    'admitted'   => "admission_status = 'admitted'",
    'review'     => "admission_status != 'admitted' AND review_count > 0",
    'incomplete' => "admission_status != 'admitted' AND review_count = 0",
    default      => '1=1',
};

$return_qs = http_build_query(array_filter([
    'q' => $dr_q, 'status' => $dr_status, 'grade' => $dr_grade, 'strand' => $dr_strand,
    'page' => $dr_page > 1 ? $dr_page : null,
]));

// ── Total count (for pagination) ────────────────────────────────────────────
$count_sql = "
    SELECT COUNT(*) AS c FROM (
        SELECT e.enrollment_id, s.admission_status,
               (SELECT COUNT(*) FROM enrollment_requirements er
                  JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                 WHERE er.enrollment_id = e.enrollment_id AND er.status = 'pending_review' AND rt.is_active = 1
               ) AS review_count
        FROM enrollments e
        JOIN students s ON s.student_id = e.student_id
        WHERE $where_sql
        HAVING $having_sql
    ) t
";
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_count = (int) $count_stmt->get_result()->fetch_assoc()['c'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_count / $dr_per_page));
if ($dr_page > $total_pages) $dr_page = $total_pages;
$dr_offset = ($dr_page - 1) * $dr_per_page;

// ── Load current page ───────────────────────────────────────────────────────
$list_sql = "
    SELECT e.enrollment_id, e.control_number, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
           e.school_year, e.enrollment_date,
           s.student_id, s.family_name, s.given_name, s.middle_name, s.suffix,
           se.is_public AS jhs_is_public, s.student_type,
           s.admission_status, s.created_at,
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
    HAVING $having_sql
    ORDER BY e.enrollment_date ASC, e.enrollment_id ASC
    LIMIT ? OFFSET ?
";
$list_stmt = $conn->prepare($list_sql);
$list_types  = $types . 'ii';
$list_params = array_merge($params, [$dr_per_page, $dr_offset]);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$res = $list_stmt->get_result();

$applicants = [];
while ($row = $res->fetch_assoc()) $applicants[] = $row;
$list_stmt->close();

// Per-applicant requirement detail, for the view modal — bounded to this
// page's rows (max $dr_per_page), same one-extra-query-per-row pattern
// already used for duplicate-checking elsewhere in this app.
foreach ($applicants as &$a) {
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
    $a['created_at_iso'] = str_replace(' ', 'T', $a['created_at']);
}
unset($a);

$modal_data = [];
foreach ($applicants as $a) $modal_data[$a['enrollment_id']] = $a;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document Review — Records</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <style>
    .dr-count-pill {
      background:#1E4D3B; color:#fff; font-size:12px; font-weight:600;
      border-radius:999px; padding:2px 10px; margin-left:8px;
    }

    /* ── Filter bar ─────────────────────────────────────────────────────── */
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

    /* ── Table ─────────────────────────────────────────────────────────── */
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
    .status-incomplete  { color:#5A5A72; background:#F5F5F7; border:0.5px solid #E0E0EC; }
    .status-admitted   { color:#1E4D3B; background:#EAF3EE; border:0.5px solid #BFE0CD; }

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

    /* ── Pagination (matches the old requirement.php / pending_applications.php) ── */
    .pa-pagination { display:flex; align-items:center; justify-content:space-between; margin-top:1rem; font-size:12.5px; color:#5A5A72; }
    .pa-pagination .pages { display:flex; gap:4px; }
    .pa-pagination a, .pa-pagination span.current {
      display:inline-flex; align-items:center; justify-content:center;
      width:30px; height:30px; border-radius:6px; text-decoration:none; font-size:12.5px;
    }
    .pa-pagination a { color:#5A5A72; border:0.5px solid #E0E0EC; }
    .pa-pagination a:hover { border-color:#ADADBD; }
    .pa-pagination span.current { background:#1E4D3B; color:#fff; font-weight:600; }

    /* ── View modal ─────────────────────────────────────────────────────── */
    .pa-modal-overlay {
      display:none; position:fixed; inset:0; background:rgba(20,20,30,.45); z-index:400;
      align-items:center; justify-content:center; padding:1.25rem;
    }
    .pa-modal-overlay.open { display:flex; }
    .pa-modal {
      background:#fff; border-radius:12px; max-width:880px; width:100%;
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

    /* ── Requirements review table ────────────────────────────────────── */
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
    .req-status-cell { display:flex; flex-direction:column; align-items:flex-start; gap:5px; min-width:170px; }
    .req-decision { display:flex; gap:8px; }
    .req-radio {
      display:inline-flex; align-items:center; justify-content:center; gap:5px;
      flex:1 1 0; box-sizing:border-box; min-width:92px;
      font-size:12px; font-weight:600;
      cursor:pointer; padding:5px 11px; border-radius:20px; border:0.5px solid #D4D4E0;
      background:#FAFAFC; color:#5A5A72; user-select:none;
      transition:background-color .15s, border-color .15s, color .15s;
    }
    .req-radio input[type="radio"] { accent-color:currentColor; margin:0; cursor:pointer; width:14px; height:14px; flex-shrink:0; }
    .req-radio-approve.is-checked { background:#EAF3EE; border-color:#BFE0CD; color:#1E4D3B; }
    .req-radio-reject.is-checked { background:#FDF0EF; border-color:#F0B8B2; color:#C0392B; }
    .req-reason-input {
      display:none; width:100%; min-width:160px; height:30px; padding:0 8px; margin-top:2px;
      border:0.5px solid #D4D4E0; border-radius:6px; font-family:inherit; font-size:12px; background:#FFFAFA;
    }
    .req-reason-input.show { display:block; }
    .req-manual-confirm {
      display:flex; align-items:center; gap:6px;
      font-size:11px; color:#A9720A; cursor:pointer; user-select:none;
      background:#FEF6E8; border:0.5px solid #F3D896; border-radius:20px; padding:5px 14px;
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
    .dr-modal-footer .btn-primary, .dr-modal-footer .btn-secondary,
    .dr-modal-footer .btn-followup, .dr-modal-footer .btn-admit {
      height:34px; padding:0 16px; font-size:12.5px;
    }
    .dr-footer-actions { display:flex; gap:8px; flex-wrap:wrap; }

    .btn-followup {
      background:#fff; color:#A9720A; border:1px solid #F3D896; border-radius:8px;
      font-weight:600; cursor:pointer; font-family:inherit;
    }
    .btn-followup:hover:not(:disabled) { background:#FEF6E8; }
    .btn-admit {
      background:var(--brand-primary); color:#fff; border:none; border-radius:8px;
      font-weight:600; cursor:pointer; font-family:inherit;
    }
    .btn-admit:hover:not(:disabled) { background:#163829; }
    .dr-modal-footer button:disabled {
      opacity:0.55; cursor:not-allowed; pointer-events:auto;
    }

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
      <span class="staff-topbar-title">Document Review</span>
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
    <h1 class="page-title">Admission Document Review</h1>
    <p class="page-sub">Search or filter applications, then open a record to review its submitted documents, set each requirement's status, and admit the student once everything checks out.</p>

    <form method="GET" action="document_review" class="pa-filter-bar">
      <div class="pa-filter-field">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= htmlspecialchars($dr_q) ?>" placeholder="Name">
      </div>
      <div class="pa-filter-field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="">All</option>
          <option value="review"     <?= $dr_status === 'review'     ? 'selected' : '' ?>>Needs Review</option>
          <option value="incomplete" <?= $dr_status === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
          <option value="admitted"   <?= $dr_status === 'admitted'   ? 'selected' : '' ?>>Admitted</option>
        </select>
      </div>
      <div class="pa-filter-field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
          <option value="">All</option>
          <?php foreach ($allowed_grades as $g): ?>
            <option value="<?= $g ?>" <?= $dr_grade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pa-filter-field">
        <label for="strand">Strand</label>
        <select id="strand" name="strand">
          <option value="">All</option>
          <?php foreach ($allowed_strands as $s): ?>
            <option value="<?= $s ?>" <?= $dr_strand === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pa-filter-actions">
        <button type="submit" class="btn-filter">Filter</button>
        <?php if ($dr_q !== '' || $dr_status !== '' || $dr_grade !== '' || $dr_strand !== ''): ?>
          <a href="document_review" class="btn-filter-clear">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (empty($applicants)): ?>

      <div class="pa-empty">
        <?= $total_count === 0 && $dr_q === '' && $dr_status === '' && $dr_grade === '' && $dr_strand === ''
              ? 'No applications are currently awaiting document review. New client-submitted admissions will appear here automatically.'
              : 'No applications match your filters.' ?>
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
          <?php foreach ($applicants as $a):
            $full_name = trim($a['family_name'] . ', ' . $a['given_name']
                . ($a['middle_name'] ? ' ' . $a['middle_name'] : '')
                . ($a['suffix'] ? ' ' . $a['suffix'] : ''));
            $applicable = (int) $a['applicable_reqs'];
            $submitted  = (int) $a['submitted_count'];
            $reviewCnt  = (int) $a['review_count'];
            $pct        = $applicable > 0 ? round(($submitted / $applicable) * 100) : 0;
            $barClass   = $submitted === 0 ? 'empty' : ($submitted >= $applicable ? 'full' : 'partial');

            if ($a['admission_status'] === 'admitted') { $statusClass = 'status-admitted'; $statusLabel = 'Admitted'; }
            elseif ($reviewCnt > 0)                    { $statusClass = 'status-review';   $statusLabel = 'Needs Review'; }
            else                                        { $statusClass = 'status-incomplete'; $statusLabel = 'Incomplete'; }
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
        $base_params = array_filter(['q' => $dr_q, 'status' => $dr_status, 'grade' => $dr_grade, 'strand' => $dr_strand]);
      ?>
        <div class="pa-pagination">
          <span>Page <?= $dr_page ?> of <?= $total_pages ?> &middot; <?= $total_count ?> total</span>
          <div class="pages">
            <?php if ($dr_page > 1): ?>
              <a href="?<?= http_build_query(array_merge($base_params, ['page' => $dr_page - 1])) ?>">&larr;</a>
            <?php endif; ?>
            <?php for ($p = max(1, $dr_page - 2); $p <= min($total_pages, $dr_page + 2); $p++): ?>
              <?php if ($p === $dr_page): ?>
                <span class="current"><?= $p ?></span>
              <?php else: ?>
                <a href="?<?= http_build_query(array_merge($base_params, ['page' => $p])) ?>"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($dr_page < $total_pages): ?>
              <a href="?<?= http_build_query(array_merge($base_params, ['page' => $dr_page + 1])) ?>">&rarr;</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div><!-- .staff-content -->
</div><!-- .staff-main -->

<!-- ── View modal ─────────────────────────────────────────────────────────── -->
<div class="pa-modal-overlay" id="paModalOverlay" onclick="if(event.target===this) closeModal()">
  <div class="pa-modal" id="paModal"></div>
</div>

<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/blur_detect.js"></script>
<script>
  const applicantData = <?= json_encode($modal_data, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  const returnQs = <?= json_encode($return_qs, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

  function esc(s) {
    return (s ?? '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function fmtDateTime(iso) {
    if (!iso) return '—';
    const dt = new Date(iso);
    if (isNaN(dt.getTime())) return iso;
    return dt.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' }) +
      ' at ' + dt.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
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
        // Done — nothing left to decide on this row.
        control = '<span class="status-flag status-admitted">Approved</span>';
      } else if (r.status === 'pending_review') {
        // A file is on hand awaiting a decision — one radio click is the
        // whole action; Reject reveals the (required) reason inline.
        control =
          '<div class="req-decision">' +
            '<label class="req-radio req-radio-approve">' +
              '<input type="radio" name="requirements[' + rid + ']" value="submitted" onchange="onDecisionChange(this, ' + rid + ')"><span>Approve</span>' +
            '</label>' +
            '<label class="req-radio req-radio-reject">' +
              '<input type="radio" name="requirements[' + rid + ']" value="rejected" onchange="onDecisionChange(this, ' + rid + ')"><span>Decline</span>' +
            '</label>' +
          '</div>' +
          '<input type="text" class="req-reason-input" id="reason-' + rid + '" ' +
            'name="reasons[' + rid + ']" placeholder="Reason for declining (required)">';
      } else {
        // 'pending' (nothing uploaded yet) or 'rejected' (file already
        // deleted, awaiting resubmission) — no file to approve, so the only
        // path forward here is staff confirming they verified it in person.
        // Checking the box directly marks it Submitted — no second dropdown
        // step needed.
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
      '<form method="POST" action="document_review" id="reqSaveForm" enctype="multipart/form-data">' +
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
    if (a.admission_status === 'admitted') return { cls: 'status-admitted',   label: 'Admitted' };
    if (reviewCount > 0)                   return { cls: 'status-review',     label: 'Needs Review' };
    return { cls: 'status-incomplete', label: 'Incomplete' };
  }

  // Approve & Admit only ever looks at is_required=1 rows; optional rows
  // (Good Moral, 2x2 Picture, VEC) never block it — they just surface here so
  // Request Follow-up can be offered regardless of which bucket they're in.
  function admitReadiness(a) {
    const reqs = a.requirements || [];
    const requiredComplete = reqs.filter(r => Number(r.is_required) === 1).every(r => r.status === 'submitted');
    const hasOutstanding = reqs.some(r => r.status !== 'submitted');
    return { requiredComplete, hasOutstanding };
  }

  function buildFullName(a) {
    return [a.family_name + ',', a.given_name, a.middle_name, a.suffix].filter(Boolean).join(' ');
  }

  function openModal(enrollmentId) {
    const a = applicantData[enrollmentId];
    if (!a) return;

    const fullName = buildFullName(a);
    const status = computeStatus(a);
    const ready = admitReadiness(a);
    const isAdmitted = a.admission_status === 'admitted';

    const admitBtn = isAdmitted
      ? '<button type="button" class="btn-admit" disabled title="Already admitted">Admitted</button>'
      : '<button type="button" class="btn-admit" onclick="confirmApprove(' + enrollmentId + ')"' +
          (ready.requiredComplete ? '' : ' disabled title="All required documents must be submitted first"') +
          '>Approve &amp; Admit</button>';
    const followupBtn = '<button type="button" class="btn-followup" onclick="confirmFollowup(' + enrollmentId + ')"' +
      (ready.hasOutstanding ? '' : ' disabled title="Nothing outstanding for this applicant"') +
      '>Request Follow-up</button>';

    document.getElementById('paModal').innerHTML = `
      <div class="pa-modal-head">
        <div>
          <div class="pa-modal-title">${esc(fullName)} <span class="status-flag ${status.cls}">${status.label}</span></div>
          <div class="pa-modal-sub">${esc(a.control_number || 'No control number yet')} &middot; Grade ${esc(a.grade_level)} &ndash; ${esc(a.strand)}</div>
          <div class="pa-modal-sub">Submitted ${fmtDateTime(a.created_at_iso)}</div>
        </div>
        <button class="pa-modal-close" onclick="closeModal()">&times;</button>
      </div>

      ${renderRequirementsTab(enrollmentId, a.requirements)}

      <div class="dr-modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal()">Close</button>
        <div class="dr-footer-actions">
          ${followupBtn}
          ${admitBtn}
        </div>
      </div>
    `;
    document.getElementById('paModalOverlay').classList.add('open');
    checkUploadedImagesForBlur(a.requirements);
    guardBlurrySubmit();
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

  function closeModal() {
    document.getElementById('paModalOverlay').classList.remove('open');
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  function postAction(action, enrollmentId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'document_review';
    form.innerHTML =
      '<input type="hidden" name="enrollment_id" value="' + enrollmentId + '">' +
      '<input type="hidden" name="return_qs" value="' + esc(returnQs) + '">' +
      '<input type="hidden" name="' + action + '" value="1">';
    document.body.appendChild(form);
    form.submit();
  }

  function confirmApprove(enrollmentId) {
    const fullName = buildFullName(applicantData[enrollmentId]);
    Swal.fire({
      title: 'Approve & admit this student?',
      html: '<p style="margin:0;font-size:13px;color:#5A5A72;text-align:left;">' +
        'This assigns a control number to <strong>' + esc(fullName) + '</strong>, marks them admitted, and emails their confirmation.' +
        '</p>',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#1E4D3B',
      cancelButtonColor: '#aaa',
      confirmButtonText: 'Approve & Admit',
      cancelButtonText: 'Cancel',
    }).then((result) => {
      if (result.isConfirmed) postAction('approve_admit', enrollmentId);
    });
  }

  function confirmFollowup(enrollmentId) {
    const fullName = buildFullName(applicantData[enrollmentId]);
    Swal.fire({
      title: 'Send a reminder email?',
      html: '<p style="margin:0;font-size:13px;color:#5A5A72;text-align:left;">' +
        'This emails <strong>' + esc(fullName) + '</strong> about their currently outstanding requirements.' +
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
