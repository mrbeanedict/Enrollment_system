<?php
// Distinct cookie name keeps the student session independent from admin/staff (see student_login.php).
session_name('STUDENT_SESSID');
session_start();
include_once '../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payments — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_student.css?v=<?= filemtime(__DIR__ . '/../css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'student_sidebar.php';

    $student_id = (int) $_SESSION['student_id'];

    // Every enrollment record this student has on file, newest first — a
    // student can carry multiple school years, so the payments page lets
    // them switch between them via the S.Y. dropdown below.
    $enr_list_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.total_due, e.admission_grade_level AS grade_level,
               e.school_year, st.strand_code AS strand, se.is_public AS jhs_is_public,
               e.semester2_status, e.semester2_reviewed_at
        FROM enrollments e
        JOIN strands st ON st.strand_id = e.admission_strand
        JOIN students s ON s.student_id = e.student_id
        LEFT JOIN student_education se ON se.student_id = s.student_id
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    ");
    mysqli_stmt_bind_param($enr_list_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_list_stmt);
    $enrollments = mysqli_fetch_all(mysqli_stmt_get_result($enr_list_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($enr_list_stmt);

    // Selected school year defaults to the most recent enrollment; a valid
    // ?enrollment_id= belonging to this student switches the view.
    $enrollment = $enrollments[0] ?? null;
    if (isset($_GET['enrollment_id'])) {
        $requested_id = (int) $_GET['enrollment_id'];
        foreach ($enrollments as $e) {
            if ($e['enrollment_id'] === $requested_id) {
                $enrollment = $e;
                break;
            }
        }
    }

    $payments   = [];
    $balance    = null;
    $tf_row     = null;

    if ($enrollment) {
        $pay_stmt = mysqli_prepare($conn, "
            SELECT amount, payment_method, paid_at, notes
            FROM payments
            WHERE enrollment_id = ?
            ORDER BY paid_at DESC
        ");
        mysqli_stmt_bind_param($pay_stmt, "i", $enrollment['enrollment_id']);
        mysqli_stmt_execute($pay_stmt);
        $payments = mysqli_fetch_all(mysqli_stmt_get_result($pay_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($pay_stmt);

        $total_paid = 0.0;
        foreach ($payments as $p) { $total_paid += (float) $p['amount']; }
        if ($enrollment['total_due'] !== null) {
            $balance = (float) $enrollment['total_due'] - $total_paid;
        }

        // ── Tuition breakdown for this student's grade level ────────────────
        if ($enrollment['grade_level']) {
            $tf_stmt = mysqli_prepare($conn, "SELECT tuition_amount, shs_voucher, misc_fee FROM tuition_fees WHERE grade_level = ?");
            mysqli_stmt_bind_param($tf_stmt, "s", $enrollment['grade_level']);
            mysqli_stmt_execute($tf_stmt);
            $tf_row = mysqli_fetch_assoc(mysqli_stmt_get_result($tf_stmt));
            mysqli_stmt_close($tf_stmt);
        }

        // SHS voucher subsidy only applies once Records has verified the
        // Voucher Eligibility Certificate on file (mirrors treasury/payment.php).
        $voucher_verified = false;
        if (!empty($enrollment['jhs_is_public'])) {
            $vec_stmt = mysqli_prepare($conn, "
                SELECT 1 FROM enrollment_requirements er
                JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
                WHERE er.enrollment_id = ? AND rt.applicable_to = 'public_jhs_only' AND er.status = 'submitted'
                LIMIT 1
            ");
            mysqli_stmt_bind_param($vec_stmt, "i", $enrollment['enrollment_id']);
            mysqli_stmt_execute($vec_stmt);
            mysqli_stmt_store_result($vec_stmt);
            $voucher_verified = mysqli_stmt_num_rows($vec_stmt) > 0;
            mysqli_stmt_close($vec_stmt);
        }

        $tuition_amount = (float) ($tf_row['tuition_amount'] ?? 0);
        $shs_voucher     = $voucher_verified ? (float) ($tf_row['shs_voucher'] ?? 0) : 0.0;
        $misc_fee        = (float) ($tf_row['misc_fee'] ?? 0);
        $assessment_total = $enrollment['total_due'] !== null
            ? (float) $enrollment['total_due']
            : max(0.0, $tuition_amount - $shs_voucher + $misc_fee);

        // Semester 2 is only ever ADDED on top of Semester 1's assessment
        // (never replaces it — see registrar/enrollment.php's finalize
        // logic and treasury/payment.php's cycle-scoped installment fix),
        // so the two terms can be split back apart here: Semester 2's own
        // assessment is a fresh tuition_fees lookup (same figure it was
        // billed at), and Semester 1's is whatever's left of the combined
        // total_due after subtracting that back out. Payments are split by
        // whether they landed before or after the Semester 2 review
        // timestamp — the same cycle-scoping rule already built and tested
        // in treasury/payment.php.
        $has_active_sem2 = $enrollment['semester2_status'] === 'approved' && !empty($enrollment['semester2_reviewed_at']);
        $sem1 = null;
        $sem2 = null;
        if ($has_active_sem2) {
            $sem2_assessment = max(0.0, $tuition_amount - $shs_voucher + $misc_fee);
            $sem1_assessment = max(0.0, $assessment_total - $sem2_assessment);

            $sem1_paid = 0.0;
            $sem2_paid = 0.0;
            foreach ($payments as $p) {
                if (strtotime($p['paid_at']) >= strtotime($enrollment['semester2_reviewed_at'])) {
                    $sem2_paid += (float) $p['amount'];
                } else {
                    $sem1_paid += (float) $p['amount'];
                }
            }

            // Only Semester 2 gets an itemized tuition/voucher/misc
            // breakdown — that's a fresh, accurate tuition_fees lookup.
            // Semester 1's original itemized breakdown isn't stored
            // anywhere historically, so it's shown as its own derived
            // total only, not force-itemized into figures that might not
            // actually match what was billed back then.
            $sem1 = [
                'label' => 'Semester 1', 'assessment' => $sem1_assessment,
                'paid' => $sem1_paid, 'balance' => $sem1_assessment - $sem1_paid,
            ];
            $sem2 = [
                'label' => 'Semester 2', 'assessment' => $sem2_assessment,
                'paid' => $sem2_paid, 'balance' => $sem2_assessment - $sem2_paid,
                'tuition_amount' => $tuition_amount, 'shs_voucher' => $shs_voucher, 'misc_fee' => $misc_fee,
            ];
        }
    }

    // Distinct payment methods actually on file — drives the filter pills.
    $methods = [];
    foreach ($payments as $p) {
        if (!in_array($p['payment_method'], $methods, true)) {
            $methods[] = $p['payment_method'];
        }
    }
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Payments
          <span class="student-topbar-subtitle">Payment history and balance</span>
        </div>
      </div>
      <?php include 'student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <?php if (!$enrollment): ?>
        <div class="notice notice-info">No enrollment record found yet.</div>
      <?php else: ?>

        <div class="acct-page-head acct-page-head-tight">
          <div>
            <h1 class="acct-page-title">Payments</h1>
            <p class="acct-page-sub">
              Grade <?= htmlspecialchars($enrollment['grade_level'] ?? '—') ?> — <?= htmlspecialchars($enrollment['strand'] ?? '—') ?>
              · S.Y. <?= htmlspecialchars($enrollment['school_year'] ?? '—') ?>
            </p>
          </div>
        </div>

        <div class="student-dashboard-grid pay-grid">

          <!-- ============ LEFT: PAYMENT HISTORY ============ -->
          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h9l4 4v12H6V4z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 15.5h4"/></svg></span>
                <div class="student-panel-title">Payment History</div>
              </div>
            </div>

            <?php if (!empty($payments) || count($enrollments) > 1): ?>
            <div class="pay-filter-bar">
              <?php if (!empty($payments)): ?>
              <div class="pay-pill-group" id="methodFilter">
                <button type="button" class="pay-pill-btn active" data-method="all">All</button>
                <?php foreach ($methods as $m): ?>
                  <button type="button" class="pay-pill-btn" data-method="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <?php if (count($enrollments) > 1): ?>
              <div class="pay-sy-select">
                <select id="syFilter" onchange="window.location.href = 'student_payments?enrollment_id=' + this.value;">
                  <?php foreach ($enrollments as $e): ?>
                    <option value="<?= (int) $e['enrollment_id'] ?>" <?= $e['enrollment_id'] === $enrollment['enrollment_id'] ? 'selected' : '' ?>>
                      S.Y. <?= htmlspecialchars($e['school_year']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <?php if (!empty($payments)): ?>
              <div class="pay-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" id="paySearch" placeholder="Search date, amount, or method">
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($payments)): ?>
              <p class="empty-state">No payments recorded yet.</p>
            <?php else: ?>
              <table class="data-table" id="payTable">
                <thead>
                  <tr><th>Date</th><th>Amount</th><th>Method</th><?php if ($has_active_sem2): ?><th>Semester</th><?php endif; ?><th>Receipt</th></tr>
                </thead>
                <tbody id="payTableBody">
                  <?php foreach ($payments as $p): ?>
                  <?php
                    $date_fmt = date('M j, Y', strtotime($p['paid_at']));
                    $amt_fmt  = number_format((float) $p['amount'], 2);
                    $pay_sem  = $has_active_sem2
                        ? (strtotime($p['paid_at']) >= strtotime($enrollment['semester2_reviewed_at']) ? 'Semester 2' : 'Semester 1')
                        : null;
                  ?>
                  <tr data-method="<?= htmlspecialchars($p['payment_method']) ?>"
                      data-search="<?= htmlspecialchars(strtolower($date_fmt . ' ' . $amt_fmt . ' ' . $p['payment_method'])) ?>">
                    <td><?= htmlspecialchars($date_fmt) ?></td>
                    <td class="amount-cell">₱<?= $amt_fmt ?></td>
                    <td><span class="method-tag"><span class="method-dot"></span><?= htmlspecialchars($p['payment_method']) ?></span></td>
                    <?php if ($has_active_sem2): ?><td><?= htmlspecialchars($pay_sem) ?></td><?php endif; ?>
                    <td>
                      <button type="button" class="receipt-btn"
                              data-date="<?= htmlspecialchars($date_fmt) ?>"
                              data-amount="₱<?= $amt_fmt ?>"
                              data-method="<?= htmlspecialchars($p['payment_method']) ?>">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M6 3h9l4 4v14H6V3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 11h6M9 14.5h6M9 18h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        Receipt
                      </button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p class="empty-state hidden" id="payEmptyRow">No payments match your filters.</p>
            <?php endif; ?>
          </div>

          <!-- ============ RIGHT: ASSESSMENT + BALANCE ============ -->
          <div class="student-dashboard-col pay-side">

            <div class="student-panel-block panel-dark">
              <div class="student-panel-header">
                <div class="student-panel-header-left">
                  <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path stroke-linecap="round" d="M7 9h10M7 13h10M7 17h6"/></svg></span>
                  <div class="student-panel-title">Total Assessment</div>
                </div>
              </div>
              <div class="assessment-label">School Year <?= htmlspecialchars($enrollment['school_year'] ?? '—') ?></div>
              <?php if ($has_active_sem2): ?>
                <div class="assessment-amount" style="font-size:1.4em;">₱<?= number_format($assessment_total, 2) ?></div>
                <p class="assessment-sub" style="margin-bottom:.5rem;">Combined — Semester 1 &amp; Semester 2</p>
                <div class="breakdown-row"><span>Semester 1</span><span>₱<?= number_format($sem1['assessment'], 2) ?></span></div>
                <div class="breakdown-row"><span>Semester 2</span><span>₱<?= number_format($sem2['assessment'], 2) ?></span></div>
              <?php else: ?>
                <div class="assessment-amount">₱<?= number_format($assessment_total, 2) ?></div>
                <p class="assessment-sub">Tuition, miscellaneous &amp; other school fees combined</p>
              <?php endif; ?>
              <button type="button" class="btn-view-breakdown" id="openBreakdown">
                <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M8 9h8M8 12.5h8M8 16h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                View Tuition Breakdown
              </button>
            </div>

            <div class="student-panel-block">
              <div class="student-panel-header">
                <div class="student-panel-header-left">
                  <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="6" width="19" height="13" rx="2.5"/><path d="M2.5 10.5h19"/></svg></span>
                  <div class="student-panel-title">Balance</div>
                </div>
              </div>

              <div class="balance-tiles">
                <div class="balance-tile tile-due">
                  <div class="balance-tile-label">Total Due</div>
                  <div class="balance-tile-value">₱<?= number_format((float) ($enrollment['total_due'] ?? 0), 2) ?></div>
                </div>
                <div class="balance-tile tile-paid">
                  <div class="balance-tile-label">Total Paid</div>
                  <div class="balance-tile-value">₱<?= number_format(array_sum(array_column($payments, 'amount')), 2) ?></div>
                </div>
              </div>

              <?php if ($has_active_sem2): ?>
                <table class="data-table" style="margin-top:.75rem;">
                  <thead><tr><th>Semester</th><th>Due</th><th>Paid</th><th>Balance</th></tr></thead>
                  <tbody>
                    <?php foreach ([$sem1, $sem2] as $s): ?>
                      <tr>
                        <td class="td-name"><?= htmlspecialchars($s['label']) ?></td>
                        <td>₱<?= number_format($s['assessment'], 2) ?></td>
                        <td>₱<?= number_format($s['paid'], 2) ?></td>
                        <td>₱<?= number_format($s['balance'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>

              <?php if ($balance !== null && $balance > 0): ?>
                <div class="balance-status status-due">
                  <span class="balance-status-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 8.5v5M12 16.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/></svg></span>
                  <div>
                    <strong>₱<?= number_format($balance, 2) ?> Balance Due</strong>
                    <span>Settle this at the Cashier's office to avoid enrollment holds.</span>
                  </div>
                </div>
              <?php else: ?>
                <div class="balance-status status-complete">
                  <span class="balance-status-icon"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="m8 12.5 2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <div>
                    <strong>Payment Complete</strong>
                    <span>Your account has no outstanding balance.</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <!-- ============ TUITION BREAKDOWN MODAL ============ -->
        <div class="modal-overlay" id="breakdownModal">
          <div class="modal-box">
            <div class="modal-head">
              <div>
                <h3>Tuition Breakdown</h3>
                <p>S.Y. <?= htmlspecialchars($enrollment['school_year'] ?? '—') ?> · Grade <?= htmlspecialchars($enrollment['grade_level'] ?? '—') ?> — <?= htmlspecialchars($enrollment['strand'] ?? '—') ?></p>
              </div>
              <button type="button" class="modal-close" data-close="breakdownModal">
                <svg viewBox="0 0 24 24" fill="none"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              </button>
            </div>

            <?php if ($has_active_sem2): ?>
              <p style="font-weight:600;margin:.5rem 0 .25rem;">Semester 1</p>
              <div class="breakdown-row"><span>Assessment (from admission)</span><span>₱<?= number_format($sem1['assessment'], 2) ?></span></div>
              <div class="breakdown-total">
                <span>Semester 1 Total</span>
                <span>₱<?= number_format($sem1['assessment'], 2) ?></span>
              </div>

              <p style="font-weight:600;margin:1rem 0 .25rem;">Semester 2</p>
              <div class="breakdown-row"><span>Tuition Fee</span><span>₱<?= number_format($sem2['tuition_amount'], 2) ?></span></div>
              <?php if ($sem2['shs_voucher'] > 0): ?>
                <div class="breakdown-row"><span>Voucher Subsidy</span><span>&minus;₱<?= number_format($sem2['shs_voucher'], 2) ?></span></div>
              <?php endif; ?>
              <div class="breakdown-row"><span>Miscellaneous Fee</span><span>₱<?= number_format($sem2['misc_fee'], 2) ?></span></div>
              <div class="breakdown-total">
                <span>Semester 2 Total</span>
                <span>₱<?= number_format($sem2['assessment'], 2) ?></span>
              </div>

              <div class="breakdown-total" style="margin-top:1rem;border-top:2px solid currentColor;padding-top:.5rem;">
                <span>Combined Total Assessment</span>
                <span>₱<?= number_format($assessment_total, 2) ?></span>
              </div>
            <?php else: ?>
              <div class="breakdown-row"><span>Tuition Fee</span><span>₱<?= number_format($tuition_amount, 2) ?></span></div>
              <?php if ($shs_voucher > 0): ?>
                <div class="breakdown-row"><span>Voucher Subsidy</span><span>&minus;₱<?= number_format($shs_voucher, 2) ?></span></div>
              <?php endif; ?>
              <div class="breakdown-row"><span>Miscellaneous Fee</span><span>₱<?= number_format($misc_fee, 2) ?></span></div>

              <div class="breakdown-total">
                <span>Total Assessment</span>
                <span>₱<?= number_format($assessment_total, 2) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ============ RECEIPT MODAL (static preview — download not wired up yet) ============ -->
        <div class="modal-overlay" id="receiptModal">
          <div class="modal-box">
            <div class="modal-head">
              <div>
                <h3>Payment Receipt</h3>
                <p>Preview only</p>
              </div>
              <button type="button" class="modal-close" data-close="receiptModal">
                <svg viewBox="0 0 24 24" fill="none"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              </button>
            </div>

            <div class="breakdown-row"><span>Date Paid</span><span id="receiptDate">—</span></div>
            <div class="breakdown-row"><span>Amount</span><span id="receiptAmount">—</span></div>
            <div class="breakdown-row"><span>Method</span><span id="receiptMethod">—</span></div>

            <p class="modal-note">Downloadable PDF receipts are coming soon — this is a preview only.</p>
            <button type="button" class="btn-student-primary modal-note-btn" disabled>Download PDF (Coming Soon)</button>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>

  <script>
    (function () {
      var table = document.getElementById('payTable');
      if (!table) return;

      var rows       = Array.prototype.slice.call(document.querySelectorAll('#payTableBody tr'));
      var pillGroup  = document.getElementById('methodFilter');
      var searchBox  = document.getElementById('paySearch');
      var emptyRow   = document.getElementById('payEmptyRow');
      var activeMethod = 'all';
      var searchTerm    = '';

      function applyFilters() {
        var visibleCount = 0;
        rows.forEach(function (row) {
          var matchesMethod = activeMethod === 'all' || row.dataset.method === activeMethod;
          var matchesSearch = row.dataset.search.indexOf(searchTerm.toLowerCase()) !== -1;
          var show = matchesMethod && matchesSearch;
          row.style.display = show ? '' : 'none';
          if (show) visibleCount++;
        });
        if (emptyRow) emptyRow.classList.toggle('hidden', visibleCount !== 0);
        table.style.display = visibleCount === 0 ? 'none' : '';
      }

      if (pillGroup) {
        pillGroup.addEventListener('click', function (e) {
          var btn = e.target.closest('.pay-pill-btn');
          if (!btn) return;
          pillGroup.querySelectorAll('.pay-pill-btn').forEach(function (b) { b.classList.remove('active'); });
          btn.classList.add('active');
          activeMethod = btn.dataset.method;
          applyFilters();
        });
      }

      if (searchBox) {
        searchBox.addEventListener('input', function (e) {
          searchTerm = e.target.value;
          applyFilters();
        });
      }

      // ── Modals (breakdown + static receipt preview) ──────────────────────
      document.querySelectorAll('.modal-close[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.getElementById(btn.dataset.close).classList.remove('open');
        });
      });

      document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
          if (e.target === overlay) overlay.classList.remove('open');
        });
      });

      var openBreakdownBtn = document.getElementById('openBreakdown');
      if (openBreakdownBtn) {
        openBreakdownBtn.addEventListener('click', function () {
          document.getElementById('breakdownModal').classList.add('open');
        });
      }

      document.querySelectorAll('.receipt-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.getElementById('receiptDate').textContent   = btn.dataset.date;
          document.getElementById('receiptAmount').textContent = btn.dataset.amount;
          document.getElementById('receiptMethod').textContent = btn.dataset.method;
          document.getElementById('receiptModal').classList.add('open');
        });
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
