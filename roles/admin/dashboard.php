<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . APP_URL . "/login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . APP_URL . "/login"); exit();
}

function fetchCount($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r) { return 0; }
    $row = mysqli_fetch_row($r);
    return (int)($row[0] ?? 0);
}

$total_students    = fetchCount($conn, "SELECT COUNT(*) FROM students");
$total_enrollments = fetchCount($conn, "SELECT COUNT(*) FROM enrollments WHERE status != 'cancelled'");
$pending_payments  = fetchCount($conn, "SELECT COUNT(*) FROM enrollments WHERE status = 'pending'");
$active_sections   = fetchCount($conn, "SELECT COUNT(*) FROM sections WHERE is_active = 1");
$total_staff       = fetchCount($conn, "SELECT COUNT(*) FROM users WHERE role = 'staff' AND is_active = 1");

// Recent enrollments
$recent_sql = "
    SELECT CONCAT(st.family_name, ', ', st.given_name) AS student_name,
           st.student_number, e.enrollment_id, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
           sec.section_name, e.school_year, e.status, e.enrollment_date
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 10
";
$recent_result = mysqli_query($conn, $recent_sql);
$recent_rows   = [];
if ($recent_result) { while ($r = mysqli_fetch_assoc($recent_result)) { $recent_rows[] = $r; } }

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
  <style>
    /* ── Dashboard-only visual polish ──────────────────────────────────────
       Page-local overrides, not touched in css_admin.css itself, since
       .card is shared with several other admin pages (announcements,
       enrollments, fee_settings, school_year_settings, staff_manage,
       students) and this restyle is scoped to the dashboard only. */
    .stat-card, .card {
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(56,102,65,0.06);
    }
    .stat-icon { border-radius: 10px; }
  </style>
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">

    <div class="topbar">
      <div class="topbar-right">
        <span class="topbar-date"><?= date('F j, Y') ?></span>
      </div>
    </div>

    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Dashboard</span></p>
        <h1 class="page-title">Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>!</h1>
        <p class="page-sub">Here's what's happening with enrollment today.</p>
      </div>

      <div class="staff-overlap">

      <!-- Stat cards -->
      <div class="stat-grid">

        <div class="stat-card">
          <div class="stat-icon stat-icon-purple">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          </div>
          <div class="stat-label">Total Students</div>
          <div class="stat-value"><?= number_format($total_students) ?></div>
          <div class="stat-sub">All registered</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-green">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
          </div>
          <div class="stat-label">Enrollments</div>
          <div class="stat-value"><?= number_format($total_enrollments) ?></div>
          <div class="stat-sub">Active (non-cancelled)</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-orange">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
          </div>
          <div class="stat-label">Pending Payments</div>
          <div class="stat-value"><?= number_format($pending_payments) ?></div>
          <div class="stat-sub">Awaiting payment</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-blue">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
          </div>
          <div class="stat-label">Staff Accounts</div>
          <div class="stat-value"><?= number_format($total_staff) ?></div>
          <div class="stat-sub">Active users</div>
        </div>

      </div>

      <!-- Recent enrollments table -->
      <div class="card">
          <div class="card-header">
            <span class="card-title">Recent Enrollments</span>
            <a href="enrollments" class="card-link">View all &rarr;</a>
          </div>

          <?php if (empty($recent_rows)): ?>
            <p class="empty-state">No enrollments yet.</p>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Grade / Strand</th>
                  <th>Section</th>
                  <th>Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recent_rows as $r): ?>
                  <tr>
                    <td>
                      <div class="td-name"><?= htmlspecialchars($r['student_name']) ?></div>
                      <div class="td-meta"><?= htmlspecialchars($r['student_number'] ?? '—') ?></div>
                    </td>
                    <td>G<?= htmlspecialchars($r['grade_level']) ?> &middot; <?= htmlspecialchars($r['strand']) ?></td>
                    <td><?= htmlspecialchars($r['section_name'] ?? '—') ?></td>
                    <td><span class="td-meta"><?= htmlspecialchars($r['enrollment_date'] ?? '') ?></span></td>
                    <td>
                      <?php
                        $status = strtolower($r['status'] ?? 'pending');
                        $badge  = match($status) {
                            'enrolled'  => 'badge-enrolled',
                            'cancelled' => 'badge-cancelled',
                            default     => 'badge-pending',
                        };
                      ?>
                      <span class="badge <?= $badge ?>"><?= htmlspecialchars($status) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
      </div>

      </div>

    </div>
  </div>

</body>
</html>
