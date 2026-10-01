<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

require_login();
guard_password_change(APP_URL . '/roles/staff/change_password');

$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'enrolled', 'balance', 'expired', 'cancelled', 'all'])) {
    $status_filter = 'pending';
}

// ── Cancel a pre-enrolled (awaiting payment) enrollment (staff) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    $cancel_id = (int)$_POST['cancel_id'];
    $stmt = $conn->prepare(
        "UPDATE enrollments SET status='cancelled' WHERE enrollment_id=? AND status='pre_enrolled'"
    );
    $stmt->bind_param('i', $cancel_id);
    $stmt->execute();
    $stmt->close();
    header("Location: enrollments_staff?status=pending&msg=cancelled");
    exit();
}

// ── Fetch enrollments ──────────────────────────────────────────────────────
$balance_join  = "LEFT JOIN (SELECT enrollment_id, SUM(amount) AS paid, MAX(paid_at) AS last_payment_at FROM payments GROUP BY enrollment_id) pd
                   ON pd.enrollment_id = e.enrollment_id";
$balance_where = "e.status = 'enrolled' AND e.total_due IS NOT NULL AND COALESCE(pd.paid, 0) < e.total_due";
// "Fully Paid" tab (URL key stays 'enrolled' so existing links keep working) —
// the exact complement of $balance_where within enrolled rows, so every
// enrolled student lands in exactly one of Fully Paid / Balance Due.
$paid_where    = "e.status = 'enrolled' AND NOT (e.total_due IS NOT NULL AND COALESCE(pd.paid, 0) < e.total_due)";

// The "Pending" tab means "awaiting Treasury payment" — that's
// enrollments.status='pre_enrolled' (Registrar has finished and assigned a
// section), not the raw 'pending' status an enrollment has before Registrar
// ever touches it. Map the URL-facing filter key to the real DB value.
$status_db_map = ['pending' => 'pre_enrolled'];
$status_db_value = $status_db_map[$status_filter] ?? $status_filter;

if ($status_filter === 'all') {
    $where = '';
} elseif ($status_filter === 'balance') {
    $where = "WHERE $balance_where";
} elseif ($status_filter === 'enrolled') {
    $where = "WHERE $paid_where";
} else {
    $where = "WHERE e.status = ?";
}

$sql = "
    SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
           e.school_year, e.enrollment_date, e.total_due, e.semester2_status,
           COALESCE(pd.paid, 0) AS amount_paid, pd.last_payment_at,
           s.family_name, s.given_name, s.student_number, e.control_number,
           sec.section_name
    FROM enrollments e
    JOIN students s   ON s.student_id   = e.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    $balance_join
    $where
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 200
";
$stmt = $conn->prepare($sql);
if ($where === 'WHERE e.status = ?') {
    $stmt->bind_param('s', $status_db_value);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
if ($result) { while ($r = mysqli_fetch_assoc($result)) { $rows[] = $r; } }
$stmt->close();

// Status counts for tab badges — keys mirror the real enrollments.status
// enum values as-is (raw 'pending' included), so this stays an accurate
// reflection of the DB. The "Pending" tab itself displays $counts['pre_enrolled']
// (see $status_db_map above), not $counts['pending'].
$counts = ['pending' => 0, 'pre_enrolled' => 0, 'enrolled' => 0, 'cancelled' => 0, 'expired' => 0, 'balance' => 0];
$cnt_res = mysqli_query($conn, "SELECT status, COUNT(*) c FROM enrollments GROUP BY status");
if ($cnt_res) { while ($r = mysqli_fetch_assoc($cnt_res)) { $counts[$r['status']] = (int)$r['c']; } }

$bal_cnt_res = mysqli_query($conn, "
    SELECT COUNT(*) c FROM enrollments e
    $balance_join
    WHERE $balance_where
");
if ($bal_cnt_res) { $counts['balance'] = (int) mysqli_fetch_assoc($bal_cnt_res)['c']; }
// Fully Paid badge = enrolled minus those still owing ($counts['enrolled']
// itself stays the raw total, which the All tab still sums).
$counts['paid'] = max(0, $counts['enrolled'] - $counts['balance']);

// Distinct grade levels present in the current tab's rows, for the Grade filter.
$grade_levels = [];
foreach ($rows as $r) { $grade_levels[$r['grade_level']] = true; }
$grade_levels = array_keys($grade_levels);
sort($grade_levels);

// A balance figure rendered as a colored pill — coral while money's owed,
// teal once it's cleared — so the number itself carries the status instead
// of relying on a separate badge/column.
function balance_pill(?float $amount): string {
    if ($amount === null) {
        return '<span class="td-meta">—</span>';
    }
    $cls = $amount > 0.005 ? 'due' : 'clear';
    return '<span class="balance-pill ' . $cls . '">₱' . number_format(max($amount, 0), 2) . '</span>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enrollments — SHS Enrollment System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_staff.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_staff.css') ?>">
  <?php if ($is_admin): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_admin.css') ?>"><?php endif; ?>
  <style>
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
      overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

    /* Topbar breadcrumb — "Treasury Portal / <page>" on one line. A single
       wrapper span keeps this as ONE child of .staff-topbar-title / .topbar-title
       (both flex containers elsewhere), so it isn't split across two lines
       the way two direct children would be. */
    .topbar-crumb { display: inline-flex; align-items: baseline; white-space: nowrap; }
    .topbar-path { color: #8A9992; font-weight: 500; margin-right: 5px; }

    /* ── White card shell wrapping toolbar + pills + table + pager ── */
    .enroll-card { background: #fff; border: 1px solid #E4E4EC; border-radius: 16px;
      box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 1px 8px rgba(16,24,40,.03); overflow: hidden; }

    .enroll-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap;
      padding: 1rem 1.25rem; }
    .enroll-search { position: relative; flex: 1 1 auto; min-width: 0; max-width: 340px; }
    .enroll-search svg { position: absolute; left: 12px; top: 50%; width: 15px; height: 15px;
      margin-top: -7.5px; color: #8A8A9A; pointer-events: none; }
    #enrollSearchInput { width: 100%; height: 36px; border: 1px solid #E0E0EC; border-radius: 8px;
      background: #fff; padding: 0 13px 0 34px; font-size: 13px; font-family: inherit;
      color: #1A1A2E; outline: none; }
    #enrollSearchInput::placeholder { color: #8A8A9A; }
    #enrollSearchInput:focus { border-color: #1E4D3B; box-shadow: 0 0 0 3px rgba(30, 77, 59, 0.14); }

    .enroll-filter-select { width: auto; flex: 0 0 auto; height: 36px; border: 1px solid #E0E0EC; border-radius: 8px;
      background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238A8A9A' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
      padding: 0 30px 0 12px; font-size: 12.5px; font-weight: 500; font-family: inherit;
      color: #1A1A2E; outline: none; cursor: pointer; appearance: none; white-space: nowrap; }
    .enroll-filter-select:focus { border-color: #1E4D3B; box-shadow: 0 0 0 3px rgba(30, 77, 59, 0.14); }

    /* ── Status tabs, underlined (active tab gets a bottom border) ── */
    .tab-pills { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 1.25rem;
      border-bottom: 1px solid #E4E4EC; }
    .tab-pill { display: inline-flex; align-items: center; gap: 6px; padding: 0 0 11px;
      border: 0; border-bottom: 2px solid transparent; margin-bottom: -1px;
      background: transparent; font-size: 13px; font-weight: 600; color: #8A8A9A;
      text-decoration: none; white-space: nowrap; transition: color .12s, border-color .12s; }
    .tab-pill:hover { color: #1A1A2E; }
    .tab-pill .tab-count { font-size: 10.5px; font-weight: 700; background: #EBEBF0; color: #5A5A72;
      border-radius: 10px; padding: 1px 7px; }
    .tab-pill .tab-count.warn { background: #FDF0EF; color: #C0392B; }
    .tab-pill.active { color: #1E4D3B; border-color: #1E4D3B; }
    .tab-pill.active .tab-count { background: #1E4D3B; color: #fff; }
    .tab-pill.active .tab-count.warn { background: #C0392B; color: #fff; }

    /* ── Table ── */
    .enroll-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .enroll-table th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; color: #8A8A9A; padding: 10px 14px; background: #FAFAFC;
      border-top: 1px solid #EDEDF2; border-bottom: 1px solid #EDEDF2; }
    .enroll-table td { padding: 11px 14px; border-bottom: 1px solid #F5F5F7;
      vertical-align: middle; color: #1A1A2E; }
    .enroll-table tr:last-child td { border-bottom: none; }
    .enroll-table tr:hover td { background: #FAFAFC; }
    .enroll-table th:last-child, .enroll-table td:last-child { text-align: right; }
    .td-meta { color: #5A5A72; font-size: 12px; }

    .td-name-cell { display: flex; align-items: center; gap: 10px; }
    .enroll-avatar { flex: 0 0 auto; width: 32px; height: 32px; border-radius: 50%;
      background: #EAF3EE; color: #1E4D3B; font-size: 11.5px; font-weight: 700;
      display: flex; align-items: center; justify-content: center; }
    .td-name-stack { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .td-name { font-weight: 600; color: #1A1A2E; }
    .td-student-number { font-family: monospace; font-size: 11px; color: #8A9992; }

    .balance-pill { display: inline-flex; align-items: center; font-weight: 700; font-size: 12.5px;
      border-radius: 20px; padding: 4px 11px; white-space: nowrap; }
    .balance-pill.due   { background: #FDF0EF; color: #C0392B; }
    .balance-pill.clear { background: #EBF7F2; color: #1A7A5E; }
    .balance-sub { display: block; font-size: 11px; color: #8A9992; margin-top: 2px; }

    .badge { display: inline-block; font-size: 10px; font-weight: 600; border-radius: 20px; padding: 2px 9px; }
    .badge-enrolled  { background: #EBF7F2; color: #1A7A5E; }
    .badge-pending   { background: #FDF0EF; color: #C0392B; }
    .badge-cancelled { background: #F5F5F7; color: #8A8A9A; }
    .badge-expired   { background: #F5F5F7; color: #5A5A72; }

    .action-buttons { display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap; }

    .btn-pay { height: 28px; padding: 0 12px; background: #1E4D3B; border: none; border-radius: 6px;
      color: #fff; font-size: 11px; font-weight: 500; cursor: pointer; font-family: inherit;
      text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap; }
    .btn-pay:hover { background: #163A2C; }
    .btn-print { height: 28px; padding: 0 12px; background: #EBF7F2; border: 0.5px solid #A8D9C5;
      border-radius: 6px; color: #1A7A5E; font-size: 11px; font-weight: 500; cursor: pointer;
      font-family: inherit; text-decoration: none; display: inline-flex; align-items: center;
      white-space: nowrap; }
    .btn-print:hover { background: #DCF0E7; }
    .btn-cancel { height: 28px; padding: 0 10px; background: transparent;
      border: 0.5px solid #E0E0EC; border-radius: 6px; color: #C0392B; font-size: 11px;
      font-weight: 500; cursor: pointer; font-family: inherit; }
    .btn-cancel:hover { background: #FDF0EF; border-color: #F5C6C2; }

    .alert { font-size: 13px; border-radius: 8px; padding: 9px 13px; margin-bottom: 1rem; }
    .alert-success { background: #EBF7F2; border: 0.5px solid #A8D9C5; color: #1A6B4A; }

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

    /* Empty state: full width and plain white inside the card, instead of
       the shared ui_modal.css dashed card — scoped to this page only. */
    body .staff-content .empty-state,
    body .content .empty-state {
      max-width: none;
      width: 100%;
      background: #FFFFFF;
      border: 0;
      border-top: 1px solid #EDEDF2;
      border-radius: 0 0 16px 16px;
      margin: 0;
    }
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
  <h1 class="page-title">Enrollments</h1>
  <p class="page-sub">Track payments for every enrollment: complete pending enrollments once the student pays, record payments on balances due, and view receipts.</p>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled'): ?>
    <div class="alert alert-success" data-ui-toast>Enrollment cancelled successfully.</div>
  <?php endif; ?>

  <?php
    $tabs = [
      'pending'   => 'Pending Payment',
      'enrolled'  => 'Fully Paid',
      'balance'   => 'Balance Due',
      'expired'   => 'Expired',
      'cancelled' => 'Cancelled',
      'all'       => 'All',
    ];
  ?>

  <!-- Status tabs, underlined — sit above the card, no white
       background of their own. -->
  <div class="tab-pills">
    <?php foreach ($tabs as $key => $label):
      $active = $status_filter === $key ? ' active' : '';
      if ($key === 'all') {
          $cnt = $counts['pending'] + $counts['pre_enrolled'] + $counts['enrolled'] + $counts['cancelled'] + $counts['expired'];
      } elseif ($key === 'pending') {
          $cnt = $counts['pre_enrolled'];
      } elseif ($key === 'enrolled') {
          $cnt = $counts['paid'];
      } else {
          $cnt = $counts[$key] ?? 0;
      }
      $warn = ($key === 'balance' && $cnt > 0) ? ' warn' : '';
    ?>
      <a href="enrollments_staff?status=<?= $key ?>" class="tab-pill<?= $active ?>">
        <?= $label ?>
        <span class="tab-count<?= $warn ?>"><?= $cnt ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="enroll-card">

    <!-- Search + filters — one row -->
    <div class="enroll-toolbar">
      <div class="enroll-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <label for="enrollSearchInput" class="sr-only">Search by student name or student number</label>
        <input type="search" id="enrollSearchInput" placeholder="Search by student name or student number">
      </div>
      <select class="enroll-filter-select" id="enrollGradeFilter" aria-label="Filter by grade level">
        <option value="">All Grades</option>
        <?php foreach ($grade_levels as $gl): ?>
          <option value="<?= htmlspecialchars($gl) ?>">Grade <?= htmlspecialchars($gl) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="enroll-filter-select" id="enrollSort" aria-label="Sort enrollments">
        <option value="date-desc">Sort: Newest first</option>
        <option value="date-asc">Sort: Oldest first</option>
        <option value="name-asc">Sort: Name (A–Z)</option>
        <option value="name-desc">Sort: Name (Z–A)</option>
      </select>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="icon-wrap teal">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
            <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" stroke="#0F6F62" stroke-width="1.6" stroke-linejoin="round"/>
            <path d="M4 8.5 12 13l8-4.5M12 13v7" stroke="#0F6F62" stroke-width="1.6" stroke-linejoin="round"/>
          </svg>
        </div>
        <h3>No <?= $status_filter === 'all' ? '' : strtolower($tabs[$status_filter] ?? $status_filter) . ' ' ?>enrollments found</h3>
        <p>Records will show up here once they match this filter.</p>
      </div>
    <?php else: ?>
      <table class="enroll-table" id="enrollTable">
        <thead>
          <tr>
            <th>Student</th>
            <th>Grade / Strand</th>
            <th>Section</th>
            <?php if ($status_filter === 'pending'): ?>
              <th>Amount Paid</th>
              <th>Remaining</th>
              <th>Enrollment Date</th>
            <?php elseif ($status_filter === 'enrolled'): ?>
              <th>Amount Paid</th>
              <th>Enrollment Date</th>
            <?php elseif ($status_filter === 'balance'): ?>
              <th>Balance Due</th>
              <th>Last Payment</th>
            <?php elseif ($status_filter === 'expired' || $status_filter === 'cancelled'): ?>
              <th>Enrollment Date</th>
            <?php else /* all */: ?>
              <th>Enrollment Date</th>
              <th>Payment / Balance</th>
              <th>Status</th>
            <?php endif; ?>
            <?php if ($status_filter !== 'expired'): ?><th>Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $total_due   = $r['total_due'] !== null ? (float)$r['total_due'] : null;
              $amount_paid = (float)$r['amount_paid'];
              $balance_due = $total_due !== null ? $total_due - $amount_paid : null;
              $has_balance = $r['status'] === 'enrolled' && $balance_due !== null && $balance_due > 0.0;
              $search_key  = mb_strtolower(trim($r['family_name'] . ' ' . $r['given_name'] . ' ' . ($r['student_number'] ?? '') . ' ' . ($r['control_number'] ?? '')));
              // New admissions have no student number yet — fall back to the control number.
              $id_label    = $r['student_number'] ?: ($r['control_number'] ?: '—');
              $full_name   = $r['family_name'] . ', ' . $r['given_name'];
              $initials    = mb_strtoupper(mb_substr($r['given_name'], 0, 1) . mb_substr($r['family_name'], 0, 1));
            ?>
            <tr data-search="<?= htmlspecialchars($search_key) ?>"
                data-grade="<?= htmlspecialchars($r['grade_level']) ?>"
                data-name="<?= htmlspecialchars(mb_strtolower($full_name)) ?>"
                data-date="<?= strtotime($r['enrollment_date']) ?: 0 ?>">
              <td>
                <div class="td-name-cell">
                  <span class="enroll-avatar"><?= htmlspecialchars($initials) ?></span>
                  <span class="td-name-stack">
                    <span class="td-name"><?= htmlspecialchars($full_name) ?></span>
                    <span class="td-student-number"><?= htmlspecialchars($id_label) ?></span>
                  </span>
                </div>
              </td>
              <td class="td-meta">
                Grade <?= htmlspecialchars($r['grade_level']) ?>
                &middot; <?= htmlspecialchars($r['strand']) ?>
              </td>
              <td class="td-meta"><?= htmlspecialchars($r['section_name'] ?? '—') ?></td>

              <?php if ($status_filter === 'pending'): ?>
                <td class="td-meta">₱<?= number_format($amount_paid, 2) ?></td>
                <td><?= balance_pill($total_due !== null ? $balance_due : null) ?></td>
                <td class="td-meta"><?= htmlspecialchars($r['enrollment_date']) ?></td>
              <?php elseif ($status_filter === 'enrolled'): ?>
                <td>₱<?= number_format($amount_paid, 2) ?></td>
                <td class="td-meta"><?= htmlspecialchars($r['enrollment_date']) ?></td>
              <?php elseif ($status_filter === 'balance'): ?>
                <td><?= balance_pill($balance_due) ?></td>
                <td class="td-meta"><?= $r['last_payment_at'] !== null ? htmlspecialchars(date('M j, Y', strtotime($r['last_payment_at']))) : '—' ?></td>
              <?php elseif ($status_filter === 'expired' || $status_filter === 'cancelled'): ?>
                <td class="td-meta"><?= htmlspecialchars($r['enrollment_date']) ?></td>
              <?php else /* all */: ?>
                <td class="td-meta"><?= htmlspecialchars($r['enrollment_date']) ?></td>
                <td>
                  <?= balance_pill($total_due !== null ? $balance_due : null) ?>
                  <?php if ($total_due !== null): ?>
                    <span class="balance-sub">₱<?= number_format($amount_paid, 2) ?> of ₱<?= number_format($total_due, 2) ?> paid</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php $badge_key = $r['status'] === 'pre_enrolled' ? 'pending' : $r['status']; ?>
                  <span class="badge badge-<?= $badge_key ?>">
                    <?= ucfirst($r['status']) ?>
                  </span>
                </td>
              <?php endif; ?>

              <?php if ($status_filter !== 'expired'): ?>
              <td>
                <div class="action-buttons">
                <?php if ($r['status'] === 'pre_enrolled'): ?>
                  <a href="payment?enrollment_id=<?= $r['enrollment_id'] ?>"
                     class="btn-pay">Complete Enrollment</a>
                  <form method="POST" data-confirm="Cancel this enrollment?" data-icon="warning">
                    <input type="hidden" name="cancel_id" value="<?= $r['enrollment_id'] ?>">
                    <button type="submit" class="btn-cancel">Cancel</button>
                  </form>
                <?php elseif ($has_balance): ?>
                  <a href="payment?enrollment_id=<?= $r['enrollment_id'] ?>"
                     class="btn-pay">Record Payment</a>
                <?php elseif ($r['status'] === 'enrolled'): ?>
                  <a href="print?enrollment_id=<?= $r['enrollment_id'] ?>"
                     class="btn-print">View Receipt</a>
                <?php elseif ($r['status'] === 'cancelled'): ?>
                  <a href="payment?enrollment_id=<?= $r['enrollment_id'] ?>"
                     class="btn-print">View Receipt</a>
                <?php endif; ?>
                </div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="empty-state hidden" id="enrollNoMatch">No enrollments match your search.</p>

      <div class="enroll-table-footer" id="enrollFooter">
        <span class="enroll-footer-summary" id="enrollSummary"></span>
        <div class="enroll-pager">
          <label class="enroll-pagination-rows">
            <select id="enrollRowsPerPage" aria-label="Rows per page">
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            per page
          </label>
          <div class="enroll-pagination-pages" id="enrollPaginationPages">
            <button type="button" id="enrollPrev" class="enroll-page-btn enroll-page-arrow" aria-label="Previous page">&lsaquo;</button>
            <span id="enrollPaginationNumbers" class="enroll-pagination-numbers"></span>
            <button type="button" id="enrollNext" class="enroll-page-btn enroll-page-arrow" aria-label="Next page">&rsaquo;</button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($rows)): ?>
    <script>
      (function () {
        var table  = document.getElementById('enrollTable');
        if (!table) return;

        var rows        = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search]'));
        var tbody       = table.querySelector('tbody');
        var searchInput = document.getElementById('enrollSearchInput');
        var gradeSelect = document.getElementById('enrollGradeFilter');
        var sortSelect  = document.getElementById('enrollSort');
        var rowsSelect  = document.getElementById('enrollRowsPerPage');
        var prevBtn     = document.getElementById('enrollPrev');
        var nextBtn     = document.getElementById('enrollNext');
        var numbersEl   = document.getElementById('enrollPaginationNumbers');
        var summaryEl   = document.getElementById('enrollSummary');
        var footerEl    = document.getElementById('enrollFooter');
        var noMatchEl   = document.getElementById('enrollNoMatch');

        // Compact page-number list: first/last + a window around the current
        // page, collapsing gaps into an 'ellipsis' token.
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
            ? 'Showing 0 of 0 enrollments'
            : 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total + ' enrollments';

          document.getElementById('enrollPaginationPages').style.display = totalPages <= 1 ? 'none' : '';

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
        nextBtn.addEventListener('click', function () { page++; render(); } );
        rowsSelect.addEventListener('change', function () { page = 1; render(); });

        function applyFilters() {
          var q     = searchInput.value.trim().toLowerCase();
          var grade = gradeSelect ? gradeSelect.value : '';
          rows.forEach(function (r) {
            var matchesSearch = !q || r.dataset.search.indexOf(q) !== -1;
            var matchesGrade  = !grade || r.dataset.grade === grade;
            r._filterMatch = matchesSearch && matchesGrade;
          });
          page = 1;
          render();
        }

        function applySort() {
          if (!sortSelect) return;
          var parts = sortSelect.value.split('-');
          var field = parts[0];
          var dir   = parts[1] === 'asc' ? 1 : -1;
          rows.sort(function (a, b) {
            if (field === 'name') {
              return a.dataset.name.localeCompare(b.dataset.name) * dir;
            }
            return (Number(a.dataset.date) - Number(b.dataset.date)) * dir;
          });
          rows.forEach(function (r) { tbody.appendChild(r); });
          page = 1;
          render();
        }

        searchInput.addEventListener('input', applyFilters);
        if (gradeSelect) gradeSelect.addEventListener('change', applyFilters);
        if (sortSelect) sortSelect.addEventListener('change', applySort);

        render();
      })();
    </script>
  <?php endif; ?>

<?php if ($is_admin): ?>
    </div><!-- .content -->
  </div><!-- .main -->
<?php else: ?>
    </div><!-- .staff-content -->
  </div><!-- .staff-main -->
<?php endif; ?>

</body>
</html>