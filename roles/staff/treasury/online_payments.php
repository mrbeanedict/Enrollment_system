<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../config/mail.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/login"); exit();
}
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// ── POST: confirm or reject a submission ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action        = $_POST['action'] ?? '';
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $uid           = (int) $_SESSION['user_id'];

        $sub_stmt = mysqli_prepare($conn, "
            SELECT s.*, e.total_due, e.status AS enrollment_status,
                   st.given_name, st.family_name, st.email, us.user_student_id
            FROM online_payment_submissions s
            JOIN enrollments e ON e.enrollment_id = s.enrollment_id
            JOIN students st ON st.student_id = e.student_id
            LEFT JOIN users_student us ON us.student_id = st.student_id
            WHERE s.submission_id = ? AND s.status = 'pending'
        ");
        mysqli_stmt_bind_param($sub_stmt, "i", $submission_id);
        mysqli_stmt_execute($sub_stmt);
        $sub = mysqli_stmt_get_result($sub_stmt)->fetch_assoc();
        mysqli_stmt_close($sub_stmt);

        // Live balance, re-checked right here rather than trusted from the
        // listing query — a submission can go stale between page load and
        // this POST (e.g. another payment landing in between), and this is
        // the check that actually stops an already-settled balance from
        // being paid again (see plan notes: this is exactly what let
        // Heve Abi's enrollment get overpaid).
        $liveBalance = null;
        if ($sub) {
            $bal_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE enrollment_id = ?");
            mysqli_stmt_bind_param($bal_stmt, "i", $sub['enrollment_id']);
            mysqli_stmt_execute($bal_stmt);
            $paidSoFar = (float) (mysqli_stmt_get_result($bal_stmt)->fetch_assoc()['paid'] ?? 0);
            mysqli_stmt_close($bal_stmt);
            $liveBalance = round((float) $sub['total_due'] - $paidSoFar, 2);
        }

        if (!$sub) {
            $errors[] = 'This submission was not found or has already been reviewed.';
        } elseif ($action === 'confirm' && $liveBalance <= 0) {
            $errors[] = 'This student has no outstanding balance — this payment was not recorded.';
        } elseif ($action === 'confirm') {
            mysqli_begin_transaction($conn);
            try {
                $amount = (float) $sub['amount'];
                $ins = $conn->prepare(
                    "INSERT INTO payments (enrollment_id, amount, payment_method, received_by, notes)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $notes = 'Online submission — Ref: ' . $sub['reference_no'];
                $ins->bind_param('idsis', $sub['enrollment_id'], $amount, $sub['payment_method'], $uid, $notes);
                if (!$ins->execute()) { throw new mysqli_sql_exception($ins->error); }
                $ins->close();

                $upd = $conn->prepare("UPDATE enrollments SET status='enrolled' WHERE enrollment_id=?");
                $upd->bind_param('i', $sub['enrollment_id']);
                if (!$upd->execute()) { throw new mysqli_sql_exception($upd->error); }
                $upd->close();

                $mark = $conn->prepare("UPDATE online_payment_submissions SET status='confirmed', reviewed_by=?, reviewed_at=NOW() WHERE submission_id=?");
                $mark->bind_param('ii', $uid, $submission_id);
                if (!$mark->execute()) { throw new mysqli_sql_exception($mark->error); }
                $mark->close();

                mysqli_commit($conn);

                notify_student_users(
                    $conn,
                    [(int) ($sub['user_student_id'] ?? 0)],
                    'Your payment of ₱' . number_format($amount, 2) . ' has been confirmed.',
                    'roles/student/portal/student_payments'
                );

                if (!empty($sub['email'])) {
                    try {
                        $mail = getMailer();
                        $bodyHtml = '<p>Hi ' . htmlspecialchars($sub['given_name']) . ', your online payment has been confirmed:</p>'
                            . email_detail_rows([
                                'Method'    => $sub['payment_method'],
                                'Amount'    => '₱' . number_format($amount, 2),
                                'Reference' => $sub['reference_no'],
                            ]);
                        send_branded_email(
                            $mail, $sub['email'], trim($sub['given_name'] . ' ' . $sub['family_name']),
                            'Online Payment Confirmed', 'Payment Confirmed', $bodyHtml,
                            "Method: {$sub['payment_method']}\nAmount: PHP " . number_format($amount, 2) . "\nReference: {$sub['reference_no']}\nStatus: Confirmed"
                        );
                    } catch (\Throwable $e) {
                        error_log('[online_payments.php] confirm email failed: ' . $e->getMessage());
                    }
                }
            } catch (mysqli_sql_exception $e) {
                mysqli_rollback($conn);
                $errors[] = 'Could not confirm this payment. Please try again.';
                error_log('[online_payments.php] ' . $e->getMessage());
            }
        } elseif ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') {
                $errors[] = 'Please provide a reason for rejecting this submission.';
            } else {
                $mark = $conn->prepare("UPDATE online_payment_submissions SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE submission_id=?");
                $mark->bind_param('isi', $uid, $reason, $submission_id);
                $mark->execute();
                $mark->close();

                notify_student_users(
                    $conn,
                    [(int) ($sub['user_student_id'] ?? 0)],
                    'Your payment submission was declined: ' . $reason,
                    'roles/student/portal/student_pay_online'
                );

                if (!empty($sub['email'])) {
                    try {
                        $mail = getMailer();
                        $bodyHtml = '<p>Hi ' . htmlspecialchars($sub['given_name']) . ', your online payment submission could not be verified:</p>'
                            . email_detail_rows([
                                'Method'    => $sub['payment_method'],
                                'Amount'    => '₱' . number_format((float) $sub['amount'], 2),
                                'Reference' => $sub['reference_no'],
                                'Reason'    => $reason,
                            ])
                            . '<p style="margin-top:16px;">Please resubmit with correct details, or pay in person at the Cashier\'s office.</p>';
                        send_branded_email(
                            $mail, $sub['email'], trim($sub['given_name'] . ' ' . $sub['family_name']),
                            'Online Payment Could Not Be Verified', 'Payment Declined', $bodyHtml,
                            "Method: {$sub['payment_method']}\nAmount: PHP " . number_format((float) $sub['amount'], 2) . "\nReference: {$sub['reference_no']}\nReason: $reason"
                        );
                    } catch (\Throwable $e) {
                        error_log('[online_payments.php] reject email failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        header("Location: online_payments?msg=" . ($action === 'confirm' ? 'confirmed' : 'rejected'));
        exit();
    }
}

// ── Fetch pending submissions ────────────────────────────────────────────
// A student whose enrollment already has no outstanding balance (e.g. a
// duplicate submission left over after an earlier one was confirmed)
// never belongs in this queue — confirming it would just overpay them.
// Same COALESCE/SUM-of-payments pattern used in enrollments_staff.php.
$pending = mysqli_query($conn, "
    SELECT s.submission_id, s.enrollment_id, s.amount, s.payment_method, s.reference_no, s.submitted_at,
           st.family_name, st.given_name, st.student_number, e.school_year,
           (e.total_due - COALESCE(pd.paid, 0)) AS live_balance
    FROM online_payment_submissions s
    JOIN enrollments e ON e.enrollment_id = s.enrollment_id
    JOIN students st ON st.student_id = e.student_id
    LEFT JOIN (SELECT enrollment_id, SUM(amount) AS paid FROM payments GROUP BY enrollment_id) pd
           ON pd.enrollment_id = e.enrollment_id
    WHERE s.status = 'pending'
    HAVING live_balance > 0.01
    ORDER BY s.submitted_at ASC
");
$pending_rows = [];
if ($pending) { while ($r = mysqli_fetch_assoc($pending)) { $pending_rows[] = $r; } }

$roleLabel = 'Treasury';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Online Payments — SHS Enrollment System</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <?php if ($is_admin): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>"><?php endif; ?>
  <style>
    .enroll-table { width:100%; border-collapse:collapse; font-size:13px; }
    .enroll-table th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:7px 10px 7px 0; border-bottom:0.5px solid #EBEBF0; }
    .enroll-table td { padding:10px 10px 10px 0; border-bottom:0.5px solid #F5F5F7; vertical-align:middle; color:#1A1A2E; }
    .enroll-table tr:last-child td { border-bottom:none; }
    .td-name { font-weight:500; }
    .td-meta { color:#5A5A72; font-size:12px; }
    .btn-pay { height:28px; padding:0 12px; background:#1E7A46; border:none; border-radius:6px;
      color:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-pay:hover { background:#166138; }
    .btn-cancel { height:28px; padding:0 10px; background:transparent; border:0.5px solid #E0E0EC;
      border-radius:6px; color:#C0392B; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-cancel:hover { background:#FDF0EF; border-color:#F5C6C2; }
    .btn-decline-confirm:hover { background:#A5301F; }
    .empty-state { text-align:center; padding:3rem 1rem; color:#8A8A9A; font-size:14px; }
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }

    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(26,26,46,.45); z-index:100; align-items:center; justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal-box { background:#fff; border-radius:12px; padding:20px 22px; width:100%; max-width:400px; box-shadow:0 20px 50px rgba(0,0,0,.2); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.9rem; }
    .modal-head h3 { margin:0; font-size:15px; }
    .modal-close { background:none; border:none; font-size:20px; line-height:1; cursor:pointer; color:#8A8A9A; }
    .modal-close:hover { color:#1A1A2E; }
  </style>
</head>
<body class="<?= $is_admin ? '' : 'staff-layout' ?>">

<?php if ($is_admin): ?>
  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <span class="topbar-title">Online Payments</span>
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">
<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <span class="staff-topbar-title">Online Payments</span>
      </div>
      <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
    </div>
    <div class="staff-content">
<?php endif; ?>

  <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : htmlspecialchars($roleLabel) . ' Portal' ?></p>
  <h1 class="page-title">Online Payment Submissions</h1>
  <p class="field-hint" style="margin-bottom:1rem;">Simulated GCash/Bank Transfer payments submitted by students, awaiting confirmation.</p>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>
  <?php if (($_GET['msg'] ?? '') === 'confirmed'): ?>
    <div class="alert alert-success">Payment confirmed and recorded.</div>
  <?php elseif (($_GET['msg'] ?? '') === 'rejected'): ?>
    <div class="alert alert-success">Submission declined.</div>
  <?php endif; ?>

  <?php if (empty($pending_rows)): ?>
    <p class="empty-state">No pending online payment submissions.</p>
  <?php else: ?>
    <table class="enroll-table">
      <thead>
        <tr><th>Student</th><th>S.Y.</th><th>Method</th><th>Amount</th><th>Reference</th><th>Submitted</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($pending_rows as $r): ?>
          <tr>
            <td>
              <div class="td-name"><?= htmlspecialchars($r['family_name'] . ', ' . $r['given_name']) ?></div>
              <div class="td-meta"><?= htmlspecialchars($r['student_number']) ?></div>
            </td>
            <td><?= htmlspecialchars($r['school_year']) ?></td>
            <td><?= htmlspecialchars($r['payment_method']) ?></td>
            <td>₱<?= number_format((float) $r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['reference_no']) ?></td>
            <td class="td-meta"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['submitted_at']))) ?></td>
            <td style="white-space:nowrap;">
              <form method="post" style="display:inline;" data-confirm="Confirm this payment? A payments record will be created." data-icon="question">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="submission_id" value="<?= (int) $r['submission_id'] ?>">
                <button type="submit" class="btn-pay">Confirm</button>
              </form>
              <button type="button" class="btn-cancel" onclick="document.getElementById('rejectModal-<?= (int) $r['submission_id'] ?>').classList.add('open')">Decline</button>

              <div class="modal-overlay" id="rejectModal-<?= (int) $r['submission_id'] ?>">
                <div class="modal-box">
                  <div class="modal-head">
                    <h3>Decline Submission</h3>
                    <button type="button" class="modal-close" onclick="document.getElementById('rejectModal-<?= (int) $r['submission_id'] ?>').classList.remove('open')">&times;</button>
                  </div>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="submission_id" value="<?= (int) $r['submission_id'] ?>">
                    <label for="reason-<?= (int) $r['submission_id'] ?>" style="display:block;font-size:12px;font-weight:600;margin-bottom:.4rem;">Reason</label>
                    <textarea id="reason-<?= (int) $r['submission_id'] ?>" name="rejection_reason" rows="3" required
                      style="width:100%;border:0.5px solid #D4D4E0;border-radius:8px;padding:8px 10px;font-family:inherit;font-size:12.5px;resize:vertical;"
                      placeholder="e.g. Reference number does not match any transaction"></textarea>
                    <button type="submit" class="btn-decline-confirm" style="display:block;width:100%;height:38px;margin-top:.75rem;background:#C0392B;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;">Confirm Decline</button>
                  </form>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

    </div>
  </div>

  <script>
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.classList.remove('open');
      });
    });
  </script>
</body>
</html>
