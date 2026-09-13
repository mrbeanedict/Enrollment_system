<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../config/mail.php';

// Creates the student's portal login (username = Student Number) and emails
// the generated password — called once, right after a student's first
// payment clears. Best-effort throughout: a failure here must never undo an
// already-recorded payment, it just gets logged for staff to follow up on.
function provision_student_portal_account(mysqli $conn, array $row): void {
    if (empty($row['student_number'])) {
        error_log('[payment.php] enrollment_id=' . $row['enrollment_id'] . ' cannot provision account — student has no Student Number on file.');
        return;
    }

    $username  = $row['student_number'];
    $password  = substr(bin2hex(random_bytes(4)), 0, 8);
    $pass_hash = password_hash($password, PASSWORD_BCRYPT);

    $u_stmt = $conn->prepare("INSERT INTO users_student (student_id, username, password_hash, is_active) VALUES (?, ?, ?, 1)");
    $u_stmt->bind_param('iss', $row['student_id'], $username, $pass_hash);
    if (!$u_stmt->execute()) {
        error_log('[payment.php] ACCOUNT_CREATE_FAILED for enrollment_id=' . $row['enrollment_id'] . ': ' . $u_stmt->error);
        $u_stmt->close();
        return;
    }
    $u_stmt->close();

    if (empty($row['email'])) {
        error_log('[payment.php] account created for enrollment_id=' . $row['enrollment_id'] . ' but student has no email on file — credentials not sent.');
        return;
    }

    try {
        $mail = getMailer();
        $bodyHtml = '<p>Hi ' . htmlspecialchars($row['given_name']) . ', your enrollment payment has been recorded. Your student portal account is ready:</p>'
            . email_detail_rows([
                'Username' => $username,
                'Password' => $password,
            ])
            . '<p style="margin-top:16px;">Log in at the student portal to view your enrollment, schedule, and payments. Please change your password after logging in.</p>';
        send_branded_email(
            $mail,
            $row['email'],
            trim($row['given_name'] . ' ' . $row['family_name']),
            'Your Student Portal Account',
            'Student Portal Account',
            $bodyHtml,
            "Username: $username\nPassword: $password"
        );
    } catch (\Throwable $e) {
        error_log('[payment.php] account email failed for enrollment_id=' . $row['enrollment_id'] . ': ' . $e->getMessage());
    }
}

if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/login"); exit();
}
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// ── Auto-create payments table if missing ──────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS payments (
        payment_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id  INT NOT NULL,
        amount         DECIMAL(10,2) NOT NULL,
        payment_method ENUM('Cash','GCash','Bank Transfer','Card','PayMongo','Maya','GrabPay') NOT NULL DEFAULT 'Cash',
        received_by    INT UNSIGNED NOT NULL,
        paid_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes          VARCHAR(255),
        FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id)
    )
");

// ── Auto-create + seed tuition_fees table if missing ────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS tuition_fees (
        grade_level     VARCHAR(2) PRIMARY KEY,
        tuition_amount  DECIMAL(10,2) NOT NULL,
        shs_voucher     DECIMAL(10,2) NOT NULL DEFAULT 0,
        misc_fee        DECIMAL(10,2) NOT NULL DEFAULT 0
    )
");
// SHS Voucher Program: voucher recipients (public-JHS completers) are meant
// to owe nothing out of pocket, so the seeded voucher covers tuition + misc
// in full — the itemized assessment still displays even when it nets to ₱0,
// since that breakdown is the transparency requirement, not the balance.
mysqli_query($conn, "
    INSERT IGNORE INTO tuition_fees (grade_level, tuition_amount, shs_voucher, misc_fee) VALUES
        ('11', 5000.00, 5500.00, 500.00),
        ('12', 10000.00, 10500.00, 500.00)
");

// ── Get enrollment ─────────────────────────────────────────────────────────
$enrollment_id = (int)($_GET['enrollment_id'] ?? 0);
if (!$enrollment_id) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

$sql = "
    SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, strd.strand_code AS strand, e.school_year, e.enrollment_date,
           e.total_due, e.semester2_status, e.semester2_reviewed_at,
           CONCAT(st.family_name, ', ', st.given_name) AS student_name,
           se.is_public AS jhs_is_public,
           st.student_id, st.student_number, st.email, st.family_name, st.given_name, us.user_student_id,
           sec.section_name
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    LEFT JOIN student_education se ON se.student_id = st.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN users_student us ON us.student_id = st.student_id
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    WHERE e.enrollment_id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $enrollment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    header("Location: " . APP_URL . "/roles/staff/dashboard"); exit();
}

// Treasury can only act once Registrar has actually finished pre-enrollment
// (status flips to 'pre_enrolled' only at enrollment.php's finalize step,
// once a section is assigned) — a bare 'pending' row means Records/Registrar
// haven't completed their steps yet, so there's nothing valid to bill.
$not_ready = !in_array($row['status'], ['pre_enrolled', 'enrolled'], true);

// Diagnostic: this row is expected to always carry these keys since they're
// all in the SELECT list above. If any go missing, log the actual row shape
// instead of leaving PHP warnings as the only trace of the anomaly.
$expected_keys = ['student_name', 'grade_level', 'strand', 'school_year', 'status'];
if (array_diff($expected_keys, array_keys($row))) {
    error_log('[payment.php] enrollment_id=' . $enrollment_id . ' unexpected row shape: ' . json_encode($row));
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

// Block payment on an enrollment that's no longer active — its seat has
// already been freed back up (expired) or the enrollment was cancelled.
if (in_array($row['status'], ['expired', 'cancelled'], true)) {
    $back_url = $is_admin ? APP_URL . '/roles/admin/enrollments' : 'enrollments_staff';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Record Payment — SHS Enrollment System</title>
      <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
      <?php if ($is_admin): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>"><?php endif; ?>
      <style>
        .pay-wrap { max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .alert-error { font-size:13px; background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B;
          border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
      </style>
    </head>
    <body<?php if (!$is_admin): ?> class="staff-layout"<?php endif; ?>>
    <?php if ($is_admin): ?>
      <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>
      <div class="main"><div class="content">
    <?php else: ?>
      <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
      <div class="staff-main">
        <div class="staff-topbar"><div class="staff-topbar-left"><span class="staff-topbar-title">Record Payment</span></div></div>
        <div class="staff-content">
    <?php endif; ?>
    <div class="pay-wrap">
      <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : htmlspecialchars($roleLabel) . ' Portal' ?></p>
      <h1 class="page-title">Record Payment</h1>
      <div class="alert-error">
        This enrollment has <?= htmlspecialchars($row['status']) ?> and can no longer accept payment.
        <?= $row['status'] === 'expired' ? 'Its section seat has been released — please start a new enrollment.' : 'Please start a new enrollment.' ?>
      </div>
      <a href="<?= $back_url ?>" class="btn-secondary">&larr; Back to Enrollments</a>
    </div>
    <?php if ($is_admin): ?>
      </div></div>
    <?php else: ?>
      </div></div>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit();
}

// ── How much has already been paid / how many installments so far ─────────
$paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid, COUNT(*) AS cnt FROM payments WHERE enrollment_id = ?");
$paid_stmt->bind_param('i', $enrollment_id);
$paid_stmt->execute();
$paid_row = $paid_stmt->get_result()->fetch_assoc();
$paid_stmt->close();
$amount_paid   = (float) $paid_row['paid'];
$payment_count = (int) $paid_row['cnt'];
$total_due     = $row['total_due'] !== null ? (float) $row['total_due'] : null;

// Individual payment dates — needed to split $amount_paid by semester
// below (same paid_at >= semester2_reviewed_at cutoff already used for
// $cycle_payment_count).
$paylist_stmt = $conn->prepare("SELECT amount, paid_at FROM payments WHERE enrollment_id = ?");
$paylist_stmt->bind_param('i', $enrollment_id);
$paylist_stmt->execute();
$payment_list = $paylist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$paylist_stmt->close();

// A Semester 2 charge gets its own fresh installment count — otherwise a
// student who already used up all 4 Semester 1 installments would have
// their brand-new Semester 2 balance treated as "only 1 left" and shown
// as one lump sum instead of its own 4-way split. $remaining/total_due
// stay lifetime-cumulative (already correct); only the installment COUNT
// is rescoped to payments made since Semester 2 was actually billed.
$cycle_payment_count = $payment_count;
if ($row['semester2_status'] === 'approved' && !empty($row['semester2_reviewed_at'])) {
    $cyc_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE enrollment_id = ? AND paid_at >= ?");
    $cyc_stmt->bind_param('is', $enrollment_id, $row['semester2_reviewed_at']);
    $cyc_stmt->execute();
    $cycle_payment_count = (int) $cyc_stmt->get_result()->fetch_assoc()['cnt'];
    $cyc_stmt->close();
}

// Fully paid — go straight to print. Requires a payment to actually
// already be on file ($payment_count > 0): a ₱0 total_due with zero
// payments yet (first visit, nothing recorded) must still show the
// Record Payment page so Confirm Enrollment records the payment first.
if ($total_due !== null && $payment_count > 0 && $amount_paid >= $total_due) {
    header("Location: print?enrollment_id=$enrollment_id"); exit();
}

$error   = '';

// ── Tuition/billing summary for this student's grade level (first payment only) ─
$tf_stmt = $conn->prepare("SELECT tuition_amount, shs_voucher, misc_fee FROM tuition_fees WHERE grade_level = ?");
$tf_stmt->bind_param('s', $row['grade_level']);
$tf_stmt->execute();
$tf_row = $tf_stmt->get_result()->fetch_assoc();
$tf_stmt->close();

// SHS voucher only applies once Records has actually verified the Voucher
// Eligibility Certificate (requirement_types.applicable_to='public_jhs_only')
// for this enrollment — jhs_is_public alone just means the student *might*
// qualify, not that eligibility has been confirmed on file.
$voucher_verified = false;
if (!empty($row['jhs_is_public'])) {
    $vec_stmt = $conn->prepare("
        SELECT 1 FROM enrollment_requirements er
        JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
        WHERE er.enrollment_id = ? AND rt.applicable_to = 'public_jhs_only' AND er.status = 'submitted'
        LIMIT 1
    ");
    $vec_stmt->bind_param('i', $enrollment_id);
    $vec_stmt->execute();
    $voucher_verified = (bool) $vec_stmt->get_result()->fetch_row();
    $vec_stmt->close();
}
$voucher_pending = !empty($row['jhs_is_public']) && !$voucher_verified;

$tuition_amount   = (float) ($tf_row['tuition_amount'] ?? 0);
$shs_voucher      = $voucher_verified ? (float) ($tf_row['shs_voucher'] ?? 0) : 0.0;
$misc_fee         = (float) ($tf_row['misc_fee'] ?? 0);
$assessment_total = max(0.0, $tuition_amount - $shs_voucher + $misc_fee);

// Semester 2 is only ever ADDED on top of Semester 1's assessment (never
// replaces it), so the combined total_due can be split back apart the
// same way roles/student/portal/student_payments.php already does: Semester 2's
// own assessment is a fresh tuition_fees lookup (the figure it was billed
// at), Semester 1's is whatever's left of the combined total after
// subtracting that back out, and payments are split by whether they
// landed before or after the Semester 2 review timestamp (same
// cycle-scoping rule $cycle_payment_count above already uses).
$has_active_sem2 = $row['semester2_status'] === 'approved' && !empty($row['semester2_reviewed_at']);
$sem1 = null;
$sem2 = null;
if ($has_active_sem2 && $total_due !== null) {
    $sem2_assessment = $assessment_total;
    $sem1_assessment = max(0.0, $total_due - $sem2_assessment);

    $sem1_paid = 0.0;
    $sem2_paid = 0.0;
    foreach ($payment_list as $p) {
        if (strtotime($p['paid_at']) >= strtotime($row['semester2_reviewed_at'])) {
            $sem2_paid += (float) $p['amount'];
        } else {
            $sem1_paid += (float) $p['amount'];
        }
    }

    $sem1 = ['assessment' => $sem1_assessment, 'paid' => $sem1_paid, 'balance' => $sem1_assessment - $sem1_paid];
    $sem2 = ['assessment' => $sem2_assessment, 'paid' => $sem2_paid, 'balance' => $sem2_assessment - $sem2_paid];
}

// A ₱0 assessment is only a genuine "nothing owed" when the voucher is what
// zeroed it out. If tuition_fees itself has no configured amount for this
// grade level (both raw tuition and misc are ₱0, before any voucher is
// applied), that's a fee-setup gap, not a real zero balance — auto-enrolling
// on it would let a student through without anyone actually assessing them.
$fees_configured    = $tuition_amount > 0.0 || $misc_fee > 0.0;
// "First payment" means no payment has been recorded yet — not whether
// total_due happens to be null. enrollment.php now precomputes total_due
// at finalize time (even to ₱0 for voucher-covered students), so relying
// on total_due===null here would misclassify an unpaid ₱0 enrollment as
// "subsequent," showing a broken form that requires a minimum ₱0.01
// payment against a ₱0 balance instead of the zero-assessment Confirm
// Enrollment button.
$is_first_payment   = ($payment_count === 0);
$is_zero_assessment = $is_first_payment && $assessment_total <= 0.0;
$fees_missing       = $is_zero_assessment && !$fees_configured;
$remaining        = $is_first_payment ? null : round($total_due - $amount_paid, 2);
$quarters_left     = $is_first_payment ? 4 : max(1, 4 - $cycle_payment_count);
$suggested_amount  = $is_first_payment ? null : round($remaining / $quarters_left, 2);

// Unified "pay in installments" basis — same checkbox/table UI works for
// both a first payment and a later one (e.g. a Semester 2 balance stacked
// on an enrollment that already has Semester 1 payments on file). Display
// only: neither of these feeds the POST handler, which still keys off
// $is_first_payment/$payment_count exactly as before.
$installment_full    = $is_first_payment ? $assessment_total : $remaining;
$installment_quarter = $is_first_payment ? round($assessment_total / 4, 2) : $suggested_amount;

// ── POST: record payment ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$not_ready) {
    $amount_given = trim($_POST['amount_given'] ?? '');
    $method       = $_POST['payment_method'] ?? 'Cash';
    $notes        = trim($_POST['notes'] ?? '');
    $uid          = (int)$_SESSION['user_id'];
    $allowed      = ['Cash', 'PayMongo', 'GCash', 'Card', 'Maya', 'GrabPay'];

    if ($fees_missing) {
        $error = "Tuition fees are not configured for Grade {$row['grade_level']} yet — contact an administrator before this student can be enrolled.";
    // A voucher-covered ₱0 assessment isn't a payment being collected — it's
    // a confirmation that nothing is owed, so the usual ">0" rule is waived.
    } elseif (!$is_zero_assessment && ($amount_given === '' || !is_numeric($amount_given) || (float)$amount_given <= 0)) {
        $error = 'Please enter a valid amount given.';
    } elseif (!in_array($method, $allowed)) {
        $error = 'Please select a valid payment method.';
    } else {
        // Amount Given is recorded as the payment amount as-typed — staff
        // records what was actually collected today (full, partial, or a
        // quarter's worth); it does not have to equal the full assessment.
        // For a ₱0 assessment, the "amount" is simply ₱0 — a confirmation,
        // not a collection, but still logged as a payments row for the audit
        // trail (the record IS the transparency).
        $charge_amount = $is_zero_assessment ? 0.0 : round((float)$amount_given, 2);
        $new_total_due = $is_first_payment ? round($assessment_total, 2) : null;
        if ($is_zero_assessment) {
            $notes = $notes !== '' ? $notes : 'Voucher-covered — no balance due';
        }

        if (!$error) {
            $esc_notes = mysqli_real_escape_string($conn, $notes);

            mysqli_begin_transaction($conn);
            try {
                $notes_val = $notes !== '' ? $notes : null;
                $ins = $conn->prepare(
                    "INSERT INTO payments (enrollment_id, amount, payment_method, received_by, notes)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ins->bind_param('idsis', $enrollment_id, $charge_amount, $method, $uid, $notes_val);
                if (!$ins->execute()) {
                    throw new mysqli_sql_exception($ins->error);
                }
                $ins->close();

                if ($is_first_payment) {
                    $upd = $conn->prepare("UPDATE enrollments SET status='enrolled', total_due=? WHERE enrollment_id=?");
                    $upd->bind_param('di', $new_total_due, $enrollment_id);
                } else {
                    $upd = $conn->prepare("UPDATE enrollments SET status='enrolled' WHERE enrollment_id=?");
                    $upd->bind_param('i', $enrollment_id);
                }
                if (!$upd->execute()) {
                    throw new mysqli_sql_exception($upd->error);
                }
                $upd->close();

                mysqli_commit($conn);

                // Student portal account is provisioned here, not at
                // admission — enrollment isn't real until the first payment
                // (or the zero-assessment "Confirm Enrollment" for
                // voucher-covered students) actually clears. Username is the
                // student's Student Number (stable, no randomized-slug
                // collisions to worry about); password is random and sent
                // by email only —
                // never shown on screen — since Treasury isn't necessarily
                // handing the student anything at this point (e.g. a parent
                // paying online later would have no one to hand a slip to).
                if ($is_first_payment && empty($row['user_student_id'])) {
                    provision_student_portal_account($conn, $row);
                }

                // The COR is generated once, right after the FIRST payment —
                // whether that payment covers the full assessment or just one
                // quarterly installment. Later installments (#2-4) go back to
                // the payment page instead of re-triggering the COR redirect;
                // staff can still open it manually via "Print Proof" anytime.
                if ($is_first_payment) {
                    header("Location: print?enrollment_id=$enrollment_id");
                } else {
                    header("Location: payment?enrollment_id=$enrollment_id");
                }
                exit();
            } catch (mysqli_sql_exception $e) {
                mysqli_rollback($conn);
                $error = 'Payment could not be saved. Please try again.';
                error_log('[payment.php] ' . $e->getMessage());
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Record Payment — SHS Enrollment System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <?php if ($is_admin): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>"><?php endif; ?>
  <style>
    .pay-wrap  { max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    .summary-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:0; }
    .summary-table tr { border-bottom:0.5px solid #F0F0F5; }
    .summary-table tr:last-child { border-bottom:none; }
    .summary-table th { text-align:left; font-weight:500; color:#5A5A72; padding:6px 0; width:42%; }
    .summary-table td { color:#1A1A2E; padding:6px 0; }
    .sem-breakdown-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .sem-breakdown-table th { text-align:left; font-weight:500; text-transform:uppercase; letter-spacing:.04em;
      font-size:10.5px; color:#8A8A9A; padding:6px 8px 6px 0; border-bottom:0.5px solid #EBEBF0; }
    .sem-breakdown-table td { padding:7px 8px 7px 0; color:#1A1A2E; border-bottom:0.5px solid #F5F5F7; }
    .sem-breakdown-table tr:last-child td { border-bottom:none; }
    .form-label { display:block; font-size:11px; font-weight:500; text-transform:uppercase;
      letter-spacing:.05em; color:#5A5A72; margin-bottom:.35rem; margin-top:.9rem; }
    .form-label:first-of-type { margin-top:0; }
    .form-input { width:100%; height:40px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC; padding:0 12px; font-size:14px; font-family:inherit; color:#1A1A2E; outline:none; }
    .form-input:focus { border-color:#2F6B4F; box-shadow:0 0 0 3px rgba(123,111,205,.14); background:#fff; }
    .form-select { width:100%; height:40px; border:0.5px solid #D4D4E0; border-radius:8px;
      background:#FAFAFC url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238A8A9A' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
      padding:0 32px 0 12px; font-size:14px; font-family:inherit; color:#1A1A2E; outline:none; appearance:none; }
    .form-select:focus { border-color:#2F6B4F; box-shadow:0 0 0 3px rgba(123,111,205,.14); background-color:#fff; }
    .form-textarea { width:100%; border:0.5px solid #D4D4E0; border-radius:8px; background:#FAFAFC;
      padding:10px 12px; font-size:13px; font-family:inherit; color:#1A1A2E; outline:none; resize:vertical; min-height:70px; }
    .form-textarea:focus { border-color:#2F6B4F; box-shadow:0 0 0 3px rgba(123,111,205,.14); background:#fff; }
    .alert-error { font-size:13px; background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B;
      border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .amount-prefix { position:relative; }
    .amount-prefix::before { content:'₱'; position:absolute; left:12px; top:50%; transform:translateY(-50%);
      font-size:14px; color:#5A5A72; pointer-events:none; }
    .amount-prefix .form-input { padding-left:24px; }
    .status-chip { display:inline-block; font-size:11px; font-weight:600; background:#FFF4E6;
      color:#C06A10; border-radius:20px; padding:3px 10px; margin-left:6px; vertical-align:middle; }
    .btn-pay { width:100%; height:42px; background:#1E4D3B; border:none; border-radius:8px;
      color:#fff; font-size:14px; font-weight:500; cursor:pointer; font-family:inherit; margin-top:1.25rem; }
    .btn-pay:hover { background:#163829; }
    .installment-row { display:flex; align-items:center; gap:8px; margin-top:.9rem; font-size:13px; color:#1A1A2E; }
    .quarter-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:.6rem; }
    .quarter-table th, .quarter-table td { text-align:left; padding:4px 0; color:#5A5A72; }

    /* GCash/Bank Transfer confirmation modal — for these two methods this
       IS the real submit path (validates Amount Given, animates, then
       requestSubmit()s the form). Cash keeps the plain bottom button. */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(26,26,46,.45); z-index:100; align-items:center; justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal-box { background:#fff; border-radius:12px; padding:20px 22px; width:100%; max-width:380px; box-shadow:0 20px 50px rgba(0,0,0,.2); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.9rem; }
    .modal-head h3 { margin:0; font-size:15px; }
    .modal-close { background:none; border:none; font-size:20px; line-height:1; cursor:pointer; color:#8A8A9A; }
    .modal-close:hover { color:#1A1A2E; }
    .spinner { width:34px; height:34px; border:3px solid rgba(0,0,0,.1); border-top-color:#1E7A46;
      border-radius:50%; animation:pmtspin .8s linear infinite; margin:0 auto; }
    @keyframes pmtspin { to { transform:rotate(360deg); } }
    .verify-state { text-align:center; padding:.5rem 0; }
    .verify-check { width:40px; height:40px; border-radius:50%; background:#EBF7F2; color:#1A7A5E;
      display:flex; align-items:center; justify-content:center; margin:0 auto .6rem; font-size:20px; }
  </style>
</head>
<body<?php if (!$is_admin): ?> class="staff-layout"<?php endif; ?>>

<?php if ($is_admin): ?>
  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>
  <div class="main"><div class="content">
<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <span class="staff-topbar-title">Record Payment</span>
      </div>
      <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
    </div>
    <div class="staff-content">
<?php endif; ?>

<div class="pay-wrap">

  <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : htmlspecialchars($roleLabel) . ' Portal' ?></p>
  <h1 class="page-title">Record Payment</h1>

  <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Enrollment summary -->
  <div class="panel" style="margin-bottom:1rem;">
    <p class="panel-sub">Enrollment Summary
      <span class="status-chip">Pending Payment</span>
      <?php if (in_array($row['semester2_status'] ?? null, ['pending', 'approved'], true)): ?>
        <span class="status-chip" style="background:#EAF3EE;color:#1E4D3B;">Semester 2</span>
      <?php endif; ?>
    </p>
    <table class="summary-table">
      <tr><th>Student</th>      <td><?= htmlspecialchars($row['student_name'] ?? '—') ?></td></tr>
      <tr><th>Section</th>      <td><?= htmlspecialchars($row['section_name'] ?? '—') ?></td></tr>
      <tr><th>Grade / Strand</th><td>Grade <?= htmlspecialchars($row['grade_level'] ?? '—') ?> — <?= htmlspecialchars($row['strand'] ?? '—') ?></td></tr>
      <tr><th>School Year</th>  <td><?= htmlspecialchars($row['school_year'] ?? '—') ?></td></tr>
      <tr><th>Enrollment #</th> <td>#<?= $enrollment_id ?></td></tr>
    </table>
  </div>

  <?php if ($not_ready): ?>
  <div class="panel">
    <p class="panel-sub">Pre-Enrollment Not Complete</p>
    <p style="font-size:13px; color:#5A5A72; margin:0;">
      <?php if ($row['status'] === 'pending'): ?>
        This student's pre-enrollment isn't complete yet. Registrar still needs to finish assigning a section (Steps 1&ndash;5 in Enrollment) before Treasury can process payment.
      <?php elseif ($row['status'] === 'expired'): ?>
        This enrollment record has expired. Please have Registrar start a new pre-enrollment for this student.
      <?php else: ?>
        This enrollment record was cancelled and can no longer accept payment.
      <?php endif; ?>
    </p>
  </div>
  <?php else: ?>

  <?php if ($is_first_payment): ?>
  <!-- Assessment (first payment) — itemized breakdown, shown even at ₱0: the
       breakdown itself is the fee-transparency requirement, not the balance. -->
  <div class="panel" style="margin-bottom:1rem;">
    <p class="panel-sub">Assessment</p>
    <table class="summary-table">
      <tr><th>Tuition Fee</th><td>₱<?= number_format($tuition_amount, 2) ?></td></tr>
      <tr><th>Voucher Subsidy</th><td>&minus;₱<?= number_format($shs_voucher, 2) ?></td></tr>
      <tr><th>Miscellaneous Fee</th><td>₱<?= number_format($misc_fee, 2) ?></td></tr>
      <tr><th>Total Assessment</th><td style="font-weight:600;">₱<?= number_format($assessment_total, 2) ?></td></tr>
    </table>
    <?php if ($voucher_pending): ?>
      <p class="field-hint" style="margin-top:.6rem;">Voucher-eligible, pending Records verification (Voucher Eligibility Certificate) &mdash; full assessment applied for now. The subsidy will apply automatically once verified.</p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <!-- Account Balance panel (subsequent installments) — while a Semester
       2 charge is active, the headline figures show Semester 2's OWN
       assessment/paid/balance (not the lifetime combined total), since
       this page is explicitly labeled "Semester 2" (see badge above) and
       a combined figure would silently fold in Sem1's already-settled
       amount. The breakdown table right below still shows both semesters
       for the full-year reference. -->
  <?php
    $display_assessment = ($has_active_sem2 && $sem2) ? $sem2['assessment'] : $total_due;
    $display_paid        = ($has_active_sem2 && $sem2) ? $sem2['paid']       : $amount_paid;
    $display_balance      = ($has_active_sem2 && $sem2) ? $sem2['balance']    : $remaining;
  ?>
  <div class="panel" style="margin-bottom:1rem;">
    <p class="panel-sub">Account Balance<?= $has_active_sem2 ? ' — Semester 2' : '' ?></p>
    <table class="summary-table">
      <tr><th>Total Assessment</th><td>₱<?= number_format($display_assessment, 2) ?></td></tr>
      <tr><th>Amount Paid</th>     <td>₱<?= number_format($display_paid, 2) ?></td></tr>
      <tr><th>Balance Due</th>     <td style="font-weight:600;">₱<?= number_format($display_balance, 2) ?></td></tr>
    </table>
    <?php if ($has_active_sem2 && $sem1 && $sem2): ?>
      <table class="sem-breakdown-table" style="margin-top:.75rem;">
        <thead><tr><th>Semester</th><th>Due</th><th>Paid</th><th>Balance</th></tr></thead>
        <tbody>
          <tr>
            <td>Semester 1</td>
            <td>₱<?= number_format($sem1['assessment'], 2) ?></td>
            <td>₱<?= number_format($sem1['paid'], 2) ?></td>
            <td>₱<?= number_format($sem1['balance'], 2) ?></td>
          </tr>
          <tr>
            <td>Semester 2</td>
            <td>₱<?= number_format($sem2['assessment'], 2) ?></td>
            <td>₱<?= number_format($sem2['paid'], 2) ?></td>
            <td>₱<?= number_format($sem2['balance'], 2) ?></td>
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Payment form -->
  <?php if ($fees_missing): ?>
  <div class="panel">
    <div class="alert-error" style="margin-bottom:0;">
      Tuition fees are not configured for Grade <?= htmlspecialchars($row['grade_level']) ?> yet
      — an administrator needs to set the tuition and miscellaneous fee
      amounts in Fee Settings before this student can be enrolled.
    </div>
  </div>
  <?php elseif ($is_zero_assessment): ?>
  <div class="panel">
    <form method="POST" action="payment?enrollment_id=<?= $enrollment_id ?>" data-confirm="Confirm enrollment with no balance due?" data-icon="question">
      <input type="hidden" name="amount_given" value="0">
      <input type="hidden" name="payment_method" value="Cash">
      <button type="submit" class="btn-pay">Confirm Enrollment</button>
    </form>
  </div>
  <?php else: ?>
  <div class="panel">
    <p class="panel-sub">Payment Details</p>
    <form method="POST" action="payment?enrollment_id=<?= $enrollment_id ?>"
          data-confirm="Confirm and record this payment? This cannot be undone." data-icon="question">

      <label class="form-label" for="amount_given">Amount Given (<span id="amount_given_method">Cash</span>) <span style="color:#C0392B">*</span></label>
      <div class="amount-prefix">
        <input class="form-input" type="number" id="amount_given" name="amount_given" min="0.01" step="0.01"
               placeholder="0.00"
               value="<?= htmlspecialchars($_POST['amount_given'] ?? '') ?>" required>
      </div>
      <?php if ($is_first_payment || $suggested_amount !== null): ?>
      <div class="installment-row">
        <input type="checkbox" id="installment" name="installment" onchange="toggleInstallment()">
        <label for="installment">Pay in installments (quarterly)</label>
      </div>
      <div id="installment-details" class="hidden">
        <table class="quarter-table" id="quarter-breakdown">
          <thead><tr><th><?= $is_first_payment ? 'Quarter' : 'Installment' ?></th><th>Suggested Amount</th></tr></thead>
          <tbody>
            <?php if ($is_first_payment): ?>
              <?php for ($q = 1; $q <= 4; $q++): ?>
                <tr><td>Quarter <?= $q ?></td><td>₱<?= number_format($installment_quarter, 2) ?></td></tr>
              <?php endfor; ?>
            <?php else: ?>
              <?php
                // Not tied to real academic quarter numbers here — a later
                // balance (e.g. a Semester 2 charge stacked on an enrollment
                // that already has 4 Semester 1 payments on file) doesn't
                // map onto "quarter 5, 6, ...", so these are just generic,
                // equal installments over whatever's left.
              ?>
              <?php for ($i = 1; $i <= $quarters_left; $i++): ?>
                <tr><td>Installment <?= $i ?></td><td>₱<?= number_format($installment_quarter, 2) ?></td></tr>
              <?php endfor; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <p class="field-hint" style="margin-top:.3rem;">Enter what the student/parent can afford to pay this quarter in Amount Given above — it doesn't have to be an even split.</p>
      </div>
      <?php endif; ?>

      <div class="change-row" style="margin-top:.5rem;font-size:13px;color:#1A1A2E;">
        <span id="change_label">Change:</span> <strong id="change_display">₱0.00</strong>
      </div>

      <label class="form-label" for="payment_method">Payment Method <span style="color:#C0392B">*</span></label>
      <select class="form-select" id="payment_method" name="payment_method">
        <?php foreach (['Cash', 'PayMongo'] as $m): ?>
          <option value="<?= $m ?>" <?= ($_POST['payment_method'] ?? 'Cash') === $m ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>

      <label class="form-label" for="notes">Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#ADADBD;">(optional)</span></label>
      <textarea class="form-textarea" id="notes" name="notes" placeholder="e.g. OR No. 12345"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>

      <button type="submit" class="btn-pay" id="bottomConfirmBtn">Confirm Payment</button>
      <button type="button" class="btn-pay" id="paymongoProceedBtn" style="display:none;">Proceed to PayMongo</button>
      <p class="field-hint" id="paymongoAmountError" style="display:none;color:#C0392B;margin-top:.5rem;">Enter the Amount Given above before proceeding.</p>
      <div class="field-hint" id="paymongoProcessing" style="display:none;margin-top:.5rem;">Starting secure checkout…</div>
    </form>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<script>
(function () {
  const given     = document.getElementById('amount_given');
  const disp      = document.getElementById('change_display');
  const label     = document.getElementById('change_label');
  const installCb = document.getElementById('installment'); // present on any payment with an installment option

  const owedFull    = <?= json_encode(round($installment_full, 2)) ?>;
  const owedQuarter = <?= json_encode(round($installment_quarter, 2)) ?>;

  // What's actually due for THIS transaction — checking the installment box
  // means "I'm paying one quarter's worth," not the whole remaining/full
  // balance (comparing against the full balance made a spot-on installment
  // payment look like a shortfall instead of ₱0). Same logic for a first
  // payment or a later one — only the underlying full/quarter amounts differ.
  function owedNow() {
    if (installCb && installCb.checked) return owedQuarter;
    return owedFull;
  }

  function updateChange() {
    if (given.value === '') {
      label.textContent = 'Change:';
      disp.textContent = '₱0.00';
      disp.style.color = '#1A1A2E';
      return;
    }
    const diff = (parseFloat(given.value) || 0) - owedNow();
    if (diff < 0) {
      label.textContent = 'Balance Due:';
      disp.textContent = '₱' + Math.abs(diff).toFixed(2);
      disp.style.color = '#C0392B';
    } else {
      label.textContent = 'Change:';
      disp.textContent = '₱' + diff.toFixed(2);
      disp.style.color = '#1A6B4A';
    }
  }

  window.toggleInstallment = function () {
    document.getElementById('installment-details').classList.toggle('hidden', !installCb.checked);
    updateChange();
  };

  given.addEventListener('input', updateChange);
  if (installCb) installCb.addEventListener('change', updateChange);
  updateChange();

  const methodSelect = document.getElementById('payment_method');
  const methodLabel   = document.getElementById('amount_given_method');
  if (methodSelect && methodLabel) {
    methodSelect.addEventListener('change', function () {
      methodLabel.textContent = methodSelect.value;
    });
  }

  // ── Live PayMongo checkout ──────────────────────────────────────────────
  // Selecting "PayMongo" replaces the plain Confirm button with "Proceed
  // to PayMongo" — mirrors how the just-removed GCash/Bank Transfer modals
  // used to be the only path for those methods. The actual payment gets
  // recorded by auto-filling and submitting this same form once PayMongo
  // confirms it (see the return-handling block below) — same POST handler
  // Cash already uses, nothing duplicated.
  const paymentForm      = given.form;
  const bottomConfirmBtn = document.getElementById('bottomConfirmBtn');
  const paymongoBtn      = document.getElementById('paymongoProceedBtn');
  const paymongoError    = document.getElementById('paymongoAmountError');
  const paymongoProcessing = document.getElementById('paymongoProcessing');
  const enrollmentId     = <?= json_encode($enrollment_id) ?>;

  function syncPaymentMethodUI() {
    const isPaymongo = methodSelect && methodSelect.value === 'PayMongo';
    if (bottomConfirmBtn) bottomConfirmBtn.style.display = isPaymongo ? 'none' : '';
    if (paymongoBtn) paymongoBtn.style.display = isPaymongo ? '' : 'none';
    if (paymongoError) paymongoError.style.display = 'none';
  }
  if (methodSelect) {
    syncPaymentMethodUI();
    methodSelect.addEventListener('change', syncPaymentMethodUI);
  }

  // Sets the <select>'s value to the real channel PayMongo reports
  // (GCash/Card/Maya/GrabPay) even though only Cash/PayMongo are normally
  // offered as options — adds a matching <option> on the fly so the value
  // actually submits correctly instead of silently clearing.
  function setDetectedPaymentMethod(value) {
    if (!methodSelect) return;
    var opt = methodSelect.querySelector('option[value="' + value + '"]');
    if (!opt) {
      opt = document.createElement('option');
      opt.value = value;
      opt.textContent = value;
      methodSelect.appendChild(opt);
    }
    methodSelect.value = value;
  }

  if (paymongoBtn) {
    paymongoBtn.addEventListener('click', function () {
      if (!given.value || parseFloat(given.value) <= 0) {
        if (paymongoError) paymongoError.style.display = '';
        return;
      }
      if (paymongoError) paymongoError.style.display = 'none';
      paymongoBtn.disabled = true;
      if (paymongoProcessing) paymongoProcessing.style.display = '';

      const body = new FormData();
      body.append('enrollment_id', String(enrollmentId));
      body.append('amount_given', given.value);

      fetch('../ajax/paymongo_treasury_checkout', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success && data.checkout_url) {
            window.location.href = data.checkout_url;
            return;
          }
          if (paymongoProcessing) paymongoProcessing.style.display = 'none';
          paymongoBtn.disabled = false;
          var msg = (data.errors && data.errors.length) ? data.errors.join(' ') : 'Could not start PayMongo checkout. Please try again.';
          if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Could not proceed', text: msg, confirmButtonColor: '#1E4D3B' });
          } else {
            alert(msg);
          }
        })
        .catch(function () {
          if (paymongoProcessing) paymongoProcessing.style.display = 'none';
          paymongoBtn.disabled = false;
          if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Something went wrong', text: 'Could not reach the server. Please try again.', confirmButtonColor: '#1E4D3B' });
          } else {
            alert('Could not reach the server. Please try again.');
          }
        });
    });
  }

  // Returning from PayMongo's hosted checkout — verify with our server
  // (which verifies with PayMongo directly), then fill and submit this
  // same form so the normal payment-recording logic runs exactly once.
  (function () {
    var params = new URLSearchParams(window.location.search);

    if (params.get('paymongo_return') === '1') {
      var loader = document.getElementById('pageLoader');
      if (loader) loader.classList.add('show');

      fetch('../ajax/paymongo_treasury_confirm?enrollment_id=' + encodeURIComponent(enrollmentId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          window.history.replaceState({}, '', window.location.pathname + '?enrollment_id=' + enrollmentId);
          if (!data.success) {
            if (loader) loader.classList.remove('show');
            var msg = data.error || 'Your payment could not be verified.';
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'info', title: 'Payment not completed', text: msg, confirmButtonColor: '#1E4D3B' });
            } else {
              alert(msg);
            }
            return;
          }

          given.value = data.amount;
          setDetectedPaymentMethod(data.method);
          var notesEl = document.getElementById('notes');
          if (notesEl) {
            var refLine = 'PayMongo ref: ' + data.reference;
            notesEl.value = notesEl.value ? (notesEl.value + ' — ' + refLine) : refLine;
          }
          updateChange();

          paymentForm.dataset.confirmed = '1';
          paymentForm.requestSubmit();
        })
        .catch(function () {
          if (loader) loader.classList.remove('show');
          window.history.replaceState({}, '', window.location.pathname + '?enrollment_id=' + enrollmentId);
        });
    } else if (params.get('paymongo_cancelled') === '1') {
      window.history.replaceState({}, '', window.location.pathname + '?enrollment_id=' + enrollmentId);
    }
  })();

})();
</script>

<?php if ($is_admin): ?>
  </div></div>
<?php else: ?>
  </div></div><!-- .staff-content .staff-main -->
<?php endif; ?>

</body>
</html>