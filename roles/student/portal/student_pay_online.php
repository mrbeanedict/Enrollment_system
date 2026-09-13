<?php
// Distinct cookie name keeps the student session independent from admin/staff (see student_login.php).
session_name('STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') === 'student';

$enrollment  = null;
$eligible    = false;
$balance     = null;
$submissions = [];

if ($logged_in) {
    $student_id = (int) $_SESSION['student_id'];

    // Same "most recent enrollment, switchable via ?enrollment_id=" lookup
    // as student_payments.php, kept in sync with that page.
    $enr_list_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.status, e.total_due, e.admission_grade_level AS grade_level,
               e.school_year, st.strand_code AS strand
        FROM enrollments e
        JOIN strands st ON st.strand_id = e.admission_strand
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    ");
    mysqli_stmt_bind_param($enr_list_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_list_stmt);
    $enrollments = mysqli_fetch_all(mysqli_stmt_get_result($enr_list_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($enr_list_stmt);

    $enrollment = $enrollments[0] ?? null;
    if (isset($_GET['enrollment_id'])) {
        $requested_id = (int) $_GET['enrollment_id'];
        foreach ($enrollments as $e) {
            if ($e['enrollment_id'] === $requested_id) { $enrollment = $e; break; }
        }
    }

    // Online payment is only offered once the student is actually
    // 'enrolled' — a brand-new applicant's first payment still has to be
    // assessed and recorded in person by Treasury (see plan notes: the
    // first payment also computes total_due and provisions the portal
    // account, logic this page deliberately doesn't duplicate).
    $eligible = $enrollment && $enrollment['status'] === 'enrolled';

    if ($eligible) {
        $paid_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE enrollment_id = ?");
        mysqli_stmt_bind_param($paid_stmt, "i", $enrollment['enrollment_id']);
        mysqli_stmt_execute($paid_stmt);
        $total_paid = (float) (mysqli_stmt_get_result($paid_stmt)->fetch_assoc()['total_paid'] ?? 0);
        mysqli_stmt_close($paid_stmt);

        $balance = round((float) ($enrollment['total_due'] ?? 0) - $total_paid, 2);
    }

    // Student's own submission history for this enrollment.
    if ($enrollment) {
        $sub_stmt = mysqli_prepare($conn, "
            SELECT amount, payment_method, reference_no, status, submitted_at, rejection_reason
            FROM online_payment_submissions
            WHERE enrollment_id = ?
            ORDER BY submitted_at DESC
        ");
        mysqli_stmt_bind_param($sub_stmt, "i", $enrollment['enrollment_id']);
        mysqli_stmt_execute($sub_stmt);
        $submissions = mysqli_fetch_all(mysqli_stmt_get_result($sub_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($sub_stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Online — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_student.css') ?>">
    <style>
      .poh-table-wrap { overflow-x:auto; border:1px solid var(--color-info); border-radius:14px; }
      .poh-table { width:100%; min-width:520px; border-collapse:collapse; font-size:13px; }
      .poh-table th, .poh-table td { padding:12px 14px; text-align:left; }
      .poh-table th:not(:first-child), .poh-table td:not(:first-child) { text-align:center; }

      .poh-table thead th {
        background: var(--color-primary); color: var(--color-accent);
        font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
        padding:13px 14px; white-space:nowrap;
      }
      .poh-table thead th:first-child { border-top-left-radius:13px; text-align:left; }
      .poh-table thead th:last-child  { border-top-right-radius:13px; }

      .poh-table tbody tr { border-bottom:1px solid #EEF3F1; transition:background-color .12s; }
      .poh-table tbody tr:last-child { border-bottom:none; }
      .poh-table tbody tr:nth-child(even) { background: rgba(202, 222, 222, 0.18); }
      .poh-table tbody tr:hover { background: rgba(56, 102, 65, 0.07); }

      .poh-table .td-date { color:#5A6B6B; font-size:12.5px; white-space:nowrap; }
      .poh-table .amount-cell { font-weight:700; color:var(--color-dark); }
    </style>
</head>
<body class="student-layout">
<?php if (!$logged_in): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/student_sidebar.php'; ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Pay Online
          <span class="student-topbar-subtitle">Pay online via PayMongo</span>
        </div>
      </div>
      <?php include BASE_PATH . '/shared/includes/student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <?php if (!$enrollment): ?>
        <div class="notice notice-info">No enrollment record found yet.</div>
      <?php elseif (!$eligible): ?>
        <div class="notice notice-info">Online payment is available once your first payment has been recorded by Treasury. Please pay in person to get started.</div>
      <?php else: ?>

        <div class="student-dashboard-grid pay-grid">

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <div>
                  <div class="student-panel-title">Submit a Payment</div>
                  <div class="student-panel-sub">SY <?= htmlspecialchars($enrollment['school_year']) ?> — Grade <?= htmlspecialchars($enrollment['grade_level']) ?> · <?= htmlspecialchars($enrollment['strand']) ?></div>
                </div>
              </div>
            </div>

            <p class="field-hint" style="margin-bottom:1rem;">
              Outstanding balance: <strong>₱<?= number_format(max(0, $balance ?? 0), 2) ?></strong>
            </p>

            <?php if (($balance ?? 0) <= 0): ?>
              <p class="empty-state">You have no outstanding balance right now.</p>
            <?php else: ?>

            <!-- ============ PayMongo (GCash / Card) ============ -->
            <div class="method-panel" id="panel-PayMongo">
              <div style="border:1px solid #D4D4E0;border-radius:10px;padding:16px;background:#FAFAFC;margin-bottom:1rem;">
                <div style="font-weight:700;margin-bottom:6px;">Pay with GCash, Maya, GrabPay, or Card</div>
                <p class="field-hint" style="margin:0;">You'll be redirected to PayMongo's secure checkout to complete your payment, then brought back here automatically.</p>
              </div>

              <div class="student-form" style="margin:0;">
                <label for="paymongoAmount">Amount to Pay</label>
                <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars((string) $balance) ?>" id="paymongoAmount" placeholder="0.00">
              </div>

              <button type="button" class="btn-student-primary" id="paymongoPayBtn" style="margin-top:1rem;">Proceed to PayMongo</button>
              <div id="paymongoProcessing" class="field-hint" style="display:none;margin-top:.75rem;">Starting secure checkout…</div>

              <form method="post" id="paymongoForm" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="enrollment_id" value="<?= (int) $enrollment['enrollment_id'] ?>">
                <input type="hidden" name="amount_given" id="paymongoAmountHidden">
              </form>
            </div>

            <?php endif; ?>
          </div>

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <div>
                  <div class="student-panel-title">Submission History</div>
                  <div class="student-panel-sub"><?= count($submissions) ?> submission<?= count($submissions) === 1 ? '' : 's' ?> on file</div>
                </div>
              </div>
            </div>

            <?php if (empty($submissions)): ?>
              <p class="empty-state">No online payment submissions yet.</p>
            <?php else: ?>
              <div class="poh-table-wrap">
              <table class="poh-table">
                <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($submissions as $s): ?>
                    <tr>
                      <td class="td-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($s['submitted_at']))) ?></td>
                      <td><?= htmlspecialchars($s['payment_method']) ?></td>
                      <td class="amount-cell">₱<?= number_format((float) $s['amount'], 2) ?></td>
                      <td><?= htmlspecialchars($s['reference_no']) ?></td>
                      <td>
                        <?php if ($s['status'] === 'pending'): ?>
                          <span class="badge badge-pending">Pending</span>
                        <?php elseif ($s['status'] === 'confirmed'): ?>
                          <span class="badge badge-enrolled">Confirmed</span>
                        <?php else: ?>
                          <span class="badge badge-cancelled" title="<?= htmlspecialchars($s['rejection_reason'] ?? '') ?>">Declined</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            <?php endif; ?>
          </div>

        </div>

      <?php endif; ?>

    </div>
  </div>

  <script>
    (function () {
      var paymongoBtn = document.getElementById('paymongoPayBtn');
      if (paymongoBtn) {
        paymongoBtn.addEventListener('click', function () {
          var amount = document.getElementById('paymongoAmount').value.trim();

          function warn(text) {
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'warning', title: 'Check your details', text: text, confirmButtonColor: '#386641' });
            } else {
              alert(text);
            }
          }

          if (!amount || parseFloat(amount) <= 0) { warn('Enter a valid amount.'); return; }

          paymongoBtn.disabled = true;
          document.getElementById('paymongoProcessing').style.display = '';
          document.getElementById('paymongoAmountHidden').value = amount;

          fetch('../ajax/paymongo_create_checkout', {
            method: 'POST',
            body: new FormData(document.getElementById('paymongoForm'))
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.success && data.checkout_url) {
                // Real navigation — the student needs to actually land on
                // PayMongo's hosted checkout page, not stay on this one.
                window.location.href = data.checkout_url;
                return;
              }
              document.getElementById('paymongoProcessing').style.display = 'none';
              paymongoBtn.disabled = false;
              var msg = (data.errors && data.errors.length) ? data.errors.join(' ') : 'Could not start PayMongo checkout. Please try again.';
              if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Could not proceed', text: msg, confirmButtonColor: '#386641' });
              } else {
                alert(msg);
              }
            })
            .catch(function () {
              document.getElementById('paymongoProcessing').style.display = 'none';
              paymongoBtn.disabled = false;
              if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Something went wrong', text: 'Could not reach the server. Please try again.', confirmButtonColor: '#386641' });
              } else {
                alert('Could not reach the server. Please try again.');
              }
            });
        });
      }

      // Returning from PayMongo's hosted checkout (success or a page
      // refresh on this same return URL) — poll our own server, which in
      // turn asks PayMongo directly whether the session actually got
      // paid, rather than trusting anything in the URL itself.
      (function () {
        var params = new URLSearchParams(window.location.search);
        var cancelled = params.get('paymongo_cancelled');

        if (params.get('paymongo_return') === '1') {
          var loader = document.getElementById('pageLoader');
          if (loader) loader.classList.add('show');

          fetch('../ajax/paymongo_confirm_return')
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (loader) loader.classList.remove('show');
              window.history.replaceState({}, '', window.location.pathname);
              if (data.success) {
                if (typeof Swal !== 'undefined') {
                  Swal.fire({ icon: 'success', title: 'Payment submitted', text: 'Your PayMongo payment was received and is awaiting Treasury confirmation.', confirmButtonColor: '#386641' })
                    .then(function () { window.location.reload(); });
                } else {
                  window.location.reload();
                }
              } else {
                var msg = data.error || 'Your payment could not be verified.';
                if (typeof Swal !== 'undefined') {
                  Swal.fire({ icon: 'info', title: 'Payment not completed', text: msg, confirmButtonColor: '#386641' });
                } else {
                  alert(msg);
                }
              }
            })
            .catch(function () {
              if (loader) loader.classList.remove('show');
              window.history.replaceState({}, '', window.location.pathname);
            });
        } else if (cancelled === '1') {
          window.history.replaceState({}, '', window.location.pathname);
        }
      })();

    })();
  </script>
<?php endif; ?>
</body>
</html>
