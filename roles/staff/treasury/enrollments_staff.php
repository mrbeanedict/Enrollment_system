<?php
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/login"); exit();
}
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
$balance_join  = "LEFT JOIN (SELECT enrollment_id, SUM(amount) AS paid FROM payments GROUP BY enrollment_id) pd
                   ON pd.enrollment_id = e.enrollment_id";
$balance_where = "e.status = 'enrolled' AND e.total_due IS NOT NULL AND COALESCE(pd.paid, 0) < e.total_due";

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
} else {
    $where = "WHERE e.status = ?";
}

$sql = "
    SELECT e.enrollment_id, e.status, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
           e.school_year, e.enrollment_date, e.total_due, e.semester2_status,
           COALESCE(pd.paid, 0) AS amount_paid,
           s.family_name, s.given_name, s.student_number,
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
    .tab-bar { display:flex; gap:0; border-bottom:1px solid #EBEBF0; margin-bottom:1.25rem; }
    .tab-link { display:inline-flex; align-items:center; gap:6px; padding:9px 16px;
      font-size:13px; font-weight:500; color:#5A5A72; text-decoration:none;
      border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s; }
    .tab-link:hover { color:#1A1A2E; }
    .tab-link.active { color:#1E4D3B; border-bottom-color:#1E4D3B; }
    .tab-count { font-size:11px; background:#EBEBF0; border-radius:10px;
      padding:1px 7px; color:#5A5A72; }
    .tab-link.active .tab-count { background:#EDE9FF; color:#1E4D3B; }
    .tab-count.warn { background:#FFF4E6; color:#C06A10; }

    .enroll-table { width:100%; border-collapse:collapse; font-size:13px; }
    .enroll-table th { text-align:left; font-size:11px; font-weight:600; text-transform:uppercase;
      letter-spacing:.06em; color:#8A8A9A; padding:7px 10px 7px 0; border-bottom:0.5px solid #EBEBF0; }
    .enroll-table td { padding:10px 10px 10px 0; border-bottom:0.5px solid #F5F5F7;
      vertical-align:middle; color:#1A1A2E; }
    .enroll-table tr:last-child td { border-bottom:none; }
    .enroll-table tr:hover td { background:#FAFAFC; }
    .td-name { font-weight:500; }
    .td-meta { color:#5A5A72; font-size:12px; }
    .td-student-number  { font-family:monospace; font-size:12px; color:#2F6B4F; }

    .badge { display:inline-block; font-size:10px; font-weight:600; border-radius:20px; padding:2px 9px; }
    .badge-enrolled  { background:#EBF7F2; color:#1A7A5E; }
    .badge-pending   { background:#FFF4E6; color:#C06A10; }
    .badge-cancelled { background:#F5F5F7; color:#8A8A9A; }
    .badge-expired   { background:#F5F5F7; color:#5A5A72; }

    .btn-pay { height:28px; padding:0 12px; background:#C06A10; border:none; border-radius:6px;
      color:#fff; font-size:11px; font-weight:500; cursor:pointer; font-family:inherit;
      text-decoration:none; display:inline-flex; align-items:center; white-space:nowrap; }
    .btn-pay:hover { background:#9C540C; }
    .btn-print { height:28px; padding:0 12px; background:#EBF7F2; border:0.5px solid #A8D9C5;
      border-radius:6px; color:#1A7A5E; font-size:11px; font-weight:500; cursor:pointer;
      font-family:inherit; text-decoration:none; display:inline-flex; align-items:center;
      white-space:nowrap; }
    .btn-print:hover { background:#DCF0E7; }
    .btn-cancel { height:28px; padding:0 10px; background:transparent;
      border:0.5px solid #E0E0EC; border-radius:6px; color:#C0392B; font-size:11px;
      font-weight:500; cursor:pointer; font-family:inherit; }
    .btn-cancel:hover { background:#FDF0EF; border-color:#F5C6C2; }

    .empty-state { text-align:center; padding:3rem 1rem; color:#8A8A9A; font-size:14px; }
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
  </style>
</head>
<body class="<?= $is_admin ? '' : 'staff-layout' ?>">

<?php if ($is_admin): ?>
  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <span class="topbar-title">Enrollments</span>
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">
<?php else: ?>
  <?php include_once BASE_PATH . '/shared/includes/staff_sidebar.php'; ?>
  <div class="staff-main">
    <div class="staff-topbar">
      <div class="staff-topbar-left">
        <span class="staff-topbar-title">Enrollments</span>
      </div>
      <span class="staff-topbar-date"><?= date('F j, Y') ?></span>
    </div>
    <div class="staff-content">
<?php endif; ?>

  <p class="page-eyebrow"><?= $is_admin ? 'Admin Portal' : htmlspecialchars($roleLabel) . ' Portal' ?></p>
  <h1 class="page-title">Enrollments</h1>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled'): ?>
    <div class="alert alert-success">Enrollment cancelled successfully.</div>
  <?php endif; ?>

  <!-- Tab bar -->
  <div class="tab-bar">
    <?php
    $tabs = [
      'pending'   => 'Pending Payment',
      'enrolled'  => 'Enrolled',
      'balance'   => 'Balance Due',
      'expired'   => 'Expired',
      'cancelled' => 'Cancelled',
      'all'       => 'All',
    ];
    foreach ($tabs as $key => $label):
      $active = $status_filter === $key ? ' active' : '';
      if ($key === 'all') {
          $cnt = $counts['pending'] + $counts['pre_enrolled'] + $counts['enrolled'] + $counts['cancelled'] + $counts['expired'];
      } elseif ($key === 'pending') {
          $cnt = $counts['pre_enrolled'];
      } else {
          $cnt = $counts[$key] ?? 0;
      }
      $warn   = (($key === 'pending' || $key === 'balance') && $cnt > 0) ? ' warn' : '';
    ?>
      <a href="enrollments_staff?status=<?= $key ?>"
         class="tab-link<?= $active ?>">
        <?= $label ?>
        <span class="tab-count<?= $warn ?>"><?= $cnt ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty-state">No <?= $status_filter === 'all' ? '' : strtolower($tabs[$status_filter] ?? $status_filter) . ' ' ?>enrollments found.</div>
  <?php else: ?>
    <table class="enroll-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Student Number</th>
          <th>Grade / Strand</th>
          <th>Section</th>
          <th>Date</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="td-meta"><?= $r['enrollment_id'] ?></td>
            <td class="td-name">
              <?= htmlspecialchars($r['family_name'] . ', ' . $r['given_name']) ?>
            </td>
            <td class="td-student-number"><?= htmlspecialchars($r['student_number'] ?? '—') ?></td>
            <td class="td-meta">
              Grade <?= htmlspecialchars($r['grade_level']) ?>
              &middot; <?= htmlspecialchars($r['strand']) ?>
            </td>
            <td class="td-meta"><?= htmlspecialchars($r['section_name'] ?? '—') ?></td>
            <td class="td-meta"><?= htmlspecialchars($r['enrollment_date']) ?></td>
            <td>
              <span class="badge badge-<?= $r['status'] ?>">
                <?= ucfirst($r['status']) ?>
              </span>
              <?php if (in_array($r['semester2_status'] ?? null, ['pending', 'approved'], true)): ?>
                <span class="badge" style="background:#EAF3EE;color:#1E4D3B;">Semester 2</span>
              <?php endif; ?>
            </td>
            <?php $has_balance = $r['status'] === 'enrolled' && $r['total_due'] !== null && (float)$r['amount_paid'] < (float)$r['total_due']; ?>
            <td>
              <?php if ($r['status'] === 'pre_enrolled'): ?>
                <a href="payment?enrollment_id=<?= $r['enrollment_id'] ?>"
                   class="btn-pay">Record Payment</a>
                <form method="POST" style="display:inline; margin-left:4px;"
                      data-confirm="Cancel this enrollment?" data-icon="warning">
                  <input type="hidden" name="cancel_id" value="<?= $r['enrollment_id'] ?>">
                  <button type="submit" class="btn-cancel">Cancel</button>
                </form>
              <?php elseif ($has_balance): ?>
                <a href="payment?enrollment_id=<?= $r['enrollment_id'] ?>"
                   class="btn-pay">Record Payment</a>
              <?php elseif ($r['status'] === 'enrolled'): ?>
                <a href="print?enrollment_id=<?= $r['enrollment_id'] ?>"
                   class="btn-print">Print Proof</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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