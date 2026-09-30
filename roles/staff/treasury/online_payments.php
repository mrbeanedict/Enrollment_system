<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../config/mail.php';

require_login();
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
                    'roles/student/portal/student_payments',
                    'highlight'
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
                    'roles/student/portal/student_pay_online',
                    'highlight'
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
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
      overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

    .topbar-crumb { display: inline-flex; align-items: baseline; white-space: nowrap; }
    .topbar-path { color: #8A9992; font-weight: 500; margin-right: 5px; }

    /* ── White card shell wrapping toolbar + table + pager (matches
       enrollments_staff.php) ── */
    .enroll-card { background: #fff; border: 1px solid #E4E4EC; border-radius: 16px;
      box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 1px 8px rgba(16,24,40,.03); overflow: hidden; }

    .enroll-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap;
      padding: 1rem 1.25rem; }
    .enroll-search { position: relative; flex: 1 1 auto; min-width: 0; max-width: 340px; }
    .enroll-search svg { position: absolute; left: 12px; top: 50%; width: 15px; height: 15px;
      margin-top: -7.5px; color: #8A8A9A; pointer-events: none; }
    #onlineSearchInput { width: 100%; height: 36px; border: 1px solid #E0E0EC; border-radius: 8px;
      background: #fff; padding: 0 13px 0 34px; font-size: 13px; font-family: inherit;
      color: #1A1A2E; outline: none; }
    #onlineSearchInput::placeholder { color: #8A8A9A; }
    #onlineSearchInput:focus { border-color: #1E4D3B; box-shadow: 0 0 0 3px rgba(30, 77, 59, 0.14); }

    .enroll-filter-select { width: auto; flex: 0 0 auto; height: 36px; border: 1px solid #E0E0EC; border-radius: 8px;
      background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238A8A9A' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
      padding: 0 30px 0 12px; font-size: 12.5px; font-weight: 500; font-family: inherit;
      color: #1A1A2E; outline: none; cursor: pointer; appearance: none; white-space: nowrap; }
    .enroll-filter-select:focus { border-color: #1E4D3B; box-shadow: 0 0 0 3px rgba(30, 77, 59, 0.14); }

    .enroll-table { width:100%; border-collapse:collapse; font-size:13px; }
    .enroll-table th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; color: #8A8A9A; padding: 10px 14px; background: #FAFAFC;
      border-top: 1px solid #EDEDF2; border-bottom: 1px solid #EDEDF2; }
    .enroll-table td { padding: 11px 14px; border-bottom: 1px solid #F5F5F7; vertical-align: middle; color: #1A1A2E; }
    .enroll-table tr:last-child td { border-bottom:none; }
    .enroll-table tr:hover td { background: #FAFAFC; }
    .enroll-table th:last-child, .enroll-table td:last-child { text-align: right; }
    .td-meta { color:#5A5A72; font-size:12px; }

    .td-name-cell { display: flex; align-items: center; gap: 10px; }
    .enroll-avatar { flex: 0 0 auto; width: 32px; height: 32px; border-radius: 50%;
      background: #EAF3EE; color: #1E4D3B; font-size: 11.5px; font-weight: 700;
      display: flex; align-items: center; justify-content: center; }
    .td-name-stack { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .td-name { font-weight: 600; color: #1A1A2E; }
    .td-student-number { font-family: monospace; font-size: 11px; color: #8A9992; }

    .action-buttons { display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap; }

    .btn-pay { height:28px; padding:0 12px; background:#1E4D3B; border:none; border-radius:6px;
      color:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-pay:hover { background:#163A2C; }
    .btn-cancel { height:28px; padding:0 10px; background:transparent; border:0.5px solid #E0E0EC;
      border-radius:6px; color:#C0392B; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-cancel:hover { background:#FDF0EF; border-color:#F5C6C2; }
    .btn-decline-confirm:hover { background:#A5301F; }
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }

    .enroll-table-footer { display: flex; align-items: center; justify-content: space-between;
      gap: 12px; flex-wrap: wrap; padding: .9rem 1.25rem; border-top: 1px solid #EDEDF2; }
    .enroll-footer-summary { font-size: 12.5px; color: #5A5A72; }
    .enroll-pager { display: flex; align-items: center; gap: 16px; }
    .enroll-pagination-rows { display: flex; align-items: center; gap: 8px; font-size: 12.5px;
      color: #5A5A72; white-space: nowrap; }
    .enroll-pagination-rows select { height: 30px; border: 1px solid #E0E0EC; border-radius: 6px;
      background: #fff; padding: 0 8px; font-size: 12.5px; font-weight: 600; font-family: inherit;
      color: #1A1A2E; outline: none; cursor: pointer; }
    .enroll-pagination-pages { display: flex; align-items: center; gap: 4px; }
    .enroll-pagination-numbers { display: flex; align-items: center; gap: 4px; }
    .enroll-page-btn { min-width: 26px; height: 26px; padding: 0 6px; border: none; border-radius: 7px;
      background: transparent; color: #5A5A72; font-size: 12.5px; font-weight: 500; font-family: inherit;
      line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .enroll-page-btn:hover:not(:disabled) { background: #EBF7F2; color: #1A7A5E; }
    .enroll-page-btn.active { background: #1E4D3B; color: #fff; font-weight: 700; }
    .enroll-page-btn:disabled { opacity: 0.35; cursor: not-allowed; }
    .enroll-page-arrow { font-size: 15px; }
    .enroll-page-ellipsis { min-width: 20px; text-align: center; color: #8A8A9A; font-size: 12.5px; }

    /* Empty state (blank slate) — full width and plain white under the
       table header, same treatment as enrollments_staff.php's card. */
    body .staff-content .empty-state,
    body .content .empty-state {
      max-width: none;
      width: 100%;
      background: #FFFFFF;
      border: 0;
      border-radius: 0 0 16px 16px;
      margin: 0;
    }

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
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">
<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left"></div>
      <div style="display:flex; align-items:center; gap:14px;">
        <?php include_once BASE_PATH . '/shared/includes/staff_notifications.php'; ?>
        <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>
    <div class="staff-content">
<?php endif; ?>

  <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : htmlspecialchars($roleLabel) . ' Portal' ?></p>
  <h1 class="page-title">Online Payment Submissions</h1>
  <p class="page-sub">Assess payments students made online through PayMongo (GCash, Maya, GrabPay or card). Confirm each one to post it to the student's account, or decline it with a reason.</p>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>
  <?php if (($_GET['msg'] ?? '') === 'confirmed'): ?>
    <div class="alert alert-success" data-ui-toast>Payment confirmed and recorded.</div>
  <?php elseif (($_GET['msg'] ?? '') === 'rejected'): ?>
    <div class="alert alert-success" data-ui-toast>Submission declined.</div>
  <?php endif; ?>

  <div class="enroll-card">

    <?php if (!empty($pending_rows)): ?>
    <!-- Search + sort — one row -->
    <div class="enroll-toolbar">
      <div class="enroll-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <label for="onlineSearchInput" class="sr-only">Search by student name or student number</label>
        <input type="search" id="onlineSearchInput" placeholder="Search by student name or student number">
      </div>
      <select class="enroll-filter-select" id="onlineSort" aria-label="Sort submissions">
        <option value="date-asc">Sort: Oldest first</option>
        <option value="date-desc">Sort: Newest first</option>
        <option value="amount-desc">Sort: Amount (High to Low)</option>
        <option value="amount-asc">Sort: Amount (Low to High)</option>
        <option value="name-asc">Sort: Name (A–Z)</option>
        <option value="name-desc">Sort: Name (Z–A)</option>
      </select>
    </div>
    <?php endif; ?>

    <?php if (empty($pending_rows)): ?>
      <table class="enroll-table">
        <thead>
          <tr><th>Student</th><th>S.Y.</th><th>Method</th><th>Amount</th><th>Reference</th><th>Submitted</th><th>Action</th></tr>
        </thead>
      </table>
      <div class="empty-state">
        <div class="icon-wrap gold">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
            <rect x="3.5" y="6" width="17" height="12" rx="2.4" stroke="#DE9A2A" stroke-width="1.6"/>
            <path d="M3.5 10h17" stroke="#DE9A2A" stroke-width="1.6"/>
            <path d="M7 14h4" stroke="#DE9A2A" stroke-width="1.6" stroke-linecap="round"/>
          </svg>
        </div>
        <h3>No pending online payment submissions</h3>
        <p>Submissions will show up here once a student pays via GCash or bank transfer.</p>
      </div>
    <?php else: ?>
      <table class="enroll-table" id="onlineTable">
        <thead>
          <tr><th>Student</th><th>S.Y.</th><th>Method</th><th>Amount</th><th>Reference</th><th>Submitted</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pending_rows as $r): ?>
            <?php
              $full_name  = $r['family_name'] . ', ' . $r['given_name'];
              $initials   = mb_strtoupper(mb_substr($r['given_name'], 0, 1) . mb_substr($r['family_name'], 0, 1));
              $search_key = mb_strtolower(trim($full_name . ' ' . ($r['student_number'] ?? '')));
            ?>
            <tr data-search="<?= htmlspecialchars($search_key) ?>"
                data-name="<?= htmlspecialchars(mb_strtolower($full_name)) ?>"
                data-date="<?= strtotime($r['submitted_at']) ?: 0 ?>"
                data-amount="<?= (float) $r['amount'] ?>">
              <td>
                <div class="td-name-cell">
                  <span class="enroll-avatar"><?= htmlspecialchars($initials) ?></span>
                  <span class="td-name-stack">
                    <span class="td-name"><?= htmlspecialchars($full_name) ?></span>
                    <span class="td-student-number"><?= htmlspecialchars($r['student_number'] ?? '—') ?></span>
                  </span>
                </div>
              </td>
              <td class="td-meta"><?= htmlspecialchars($r['school_year']) ?></td>
              <td class="td-meta"><?= htmlspecialchars($r['payment_method']) ?></td>
              <td>₱<?= number_format((float) $r['amount'], 2) ?></td>
              <td class="td-meta"><?= htmlspecialchars($r['reference_no']) ?></td>
              <td class="td-meta"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['submitted_at']))) ?></td>
              <td>
                <div class="action-buttons">
                <form method="post" data-confirm="Confirm this payment? A payments record will be created." data-icon="question">
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
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="empty-state hidden" id="onlineNoMatch">No submissions match your search.</p>

      <div class="enroll-table-footer" id="onlineFooter">
        <span class="enroll-footer-summary" id="onlineSummary"></span>
        <div class="enroll-pager">
          <label class="enroll-pagination-rows">
            <select id="onlineRowsPerPage" aria-label="Rows per page">
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            per page
          </label>
          <div class="enroll-pagination-pages" id="onlinePaginationPages">
            <button type="button" id="onlinePrev" class="enroll-page-btn enroll-page-arrow" aria-label="Previous page">&lsaquo;</button>
            <span id="onlinePaginationNumbers" class="enroll-pagination-numbers"></span>
            <button type="button" id="onlineNext" class="enroll-page-btn enroll-page-arrow" aria-label="Next page">&rsaquo;</button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

    </div>
  </div>

  <script>
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.classList.remove('open');
      });
    });

    <?php if (!empty($pending_rows)): ?>
    (function () {
      var table  = document.getElementById('onlineTable');
      if (!table) return;

      var rows        = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search]'));
      var tbody       = table.querySelector('tbody');
      var searchInput = document.getElementById('onlineSearchInput');
      var sortSelect  = document.getElementById('onlineSort');
      var rowsSelect  = document.getElementById('onlineRowsPerPage');
      var prevBtn     = document.getElementById('onlinePrev');
      var nextBtn     = document.getElementById('onlineNext');
      var numbersEl   = document.getElementById('onlinePaginationNumbers');
      var summaryEl   = document.getElementById('onlineSummary');
      var footerEl    = document.getElementById('onlineFooter');
      var noMatchEl   = document.getElementById('onlineNoMatch');

      function buildPageList(page, totalPages) {
        if (totalPages <= 7) {
          var all = [];
          for (var i = 1; i <= totalPages; i++) { all.push(i); }
          return all;
        }
        var pages = [1];
        if (page > 3) { pages.push('ellipsis'); }
        for (var p = Math.max(2, page - 1); p <= Math.min(totalPages - 1, page + 1); p++) { pages.push(p); }
        if (page < totalPages - 2) { pages.push('ellipsis'); }
        pages.push(totalPages);
        return pages;
      }

      var page = 1;

      function render() {
        var per     = parseInt(rowsSelect.value, 10) || 10;
        var matched = rows.filter(function (r) { return r._filterMatch !== false; });
        var total   = matched.length;
        var totalPages = Math.max(1, Math.ceil(total / per));
        if (page > totalPages) { page = totalPages; }
        if (page < 1) { page = 1; }
        var start = (page - 1) * per;
        var end   = start + per;

        rows.forEach(function (r) { r.style.display = 'none'; });
        matched.slice(start, end).forEach(function (r) { r.style.display = ''; });

        summaryEl.textContent = total === 0
          ? 'Showing 0 of 0 submissions'
          : 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total + ' submissions';

        document.getElementById('onlinePaginationPages').style.display = totalPages <= 1 ? 'none' : '';

        numbersEl.innerHTML = '';
        buildPageList(page, totalPages).forEach(function (p) {
          if (p === 'ellipsis') {
            var span = document.createElement('span');
            span.className = 'enroll-page-ellipsis';
            span.textContent = '…';
            numbersEl.appendChild(span);
            return;
          }
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'enroll-page-btn' + (p === page ? ' active' : '');
          btn.textContent = p;
          btn.addEventListener('click', function () { page = p; render(); });
          numbersEl.appendChild(btn);
        });

        prevBtn.disabled = page <= 1;
        nextBtn.disabled = page >= totalPages;

        table.style.display = total === 0 ? 'none' : '';
        footerEl.classList.toggle('hidden', total === 0);
        noMatchEl.classList.toggle('hidden', total !== 0);
      }

      prevBtn.addEventListener('click', function () { if (page > 1) { page--; render(); } });
      nextBtn.addEventListener('click', function () { page++; render(); });
      rowsSelect.addEventListener('change', function () { page = 1; render(); });

      function applyFilters() {
        var q = searchInput.value.trim().toLowerCase();
        rows.forEach(function (r) { r._filterMatch = !q || r.dataset.search.indexOf(q) !== -1; });
        page = 1;
        render();
      }

      function applySort() {
        var parts = sortSelect.value.split('-');
        var field = parts[0];
        var dir   = parts[1] === 'asc' ? 1 : -1;
        rows.sort(function (a, b) {
          if (field === 'name') { return a.dataset.name.localeCompare(b.dataset.name) * dir; }
          if (field === 'amount') { return (Number(a.dataset.amount) - Number(b.dataset.amount)) * dir; }
          return (Number(a.dataset.date) - Number(b.dataset.date)) * dir;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        page = 1;
        render();
      }

      searchInput.addEventListener('input', applyFilters);
      sortSelect.addEventListener('change', applySort);

      render();
    })();
    <?php endif; ?>
  </script>
</body>
</html>
