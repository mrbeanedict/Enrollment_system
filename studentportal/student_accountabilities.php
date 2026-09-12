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
    <title>Accountabilities — SHS Enrollment</title>
    <link rel="stylesheet" href="../assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../assets/css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'student_sidebar.php';

    $student_id = (int) $_SESSION['student_id'];

    $enr_stmt = mysqli_prepare($conn, "
        SELECT enrollment_id
        FROM enrollments
        WHERE student_id = ?
        ORDER BY enrollment_date DESC, enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);

    $submitted      = [];
    $missing        = [];
    $pending_review = [];
    $rejected       = [];

    if ($enrollment) {
        $stu_stmt = mysqli_prepare($conn, "SELECT is_public AS jhs_is_public FROM student_education WHERE student_id = ?");
        mysqli_stmt_bind_param($stu_stmt, "i", $student_id);
        mysqli_stmt_execute($stu_stmt);
        mysqli_stmt_bind_result($stu_stmt, $jhs_is_public);
        mysqli_stmt_fetch($stu_stmt);
        mysqli_stmt_close($stu_stmt);

        $req_stmt = mysqli_prepare($conn, "
            SELECT rt.requirement_type_id, rt.requirement_name, er.status, er.rejection_reason
            FROM requirement_types rt
            LEFT JOIN enrollment_requirements er
                   ON er.requirement_type_id = rt.requirement_type_id
                  AND er.enrollment_id = ?
            WHERE rt.is_active = 1
              AND (rt.applicable_to = 'all' OR ? = 1)
            ORDER BY rt.display_order ASC
        ");
        mysqli_stmt_bind_param($req_stmt, "ii", $enrollment['enrollment_id'], $jhs_is_public);
        mysqli_stmt_execute($req_stmt);
        $req_rows = mysqli_fetch_all(mysqli_stmt_get_result($req_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($req_stmt);

        foreach ($req_rows as $r) {
            if ($r['status'] === 'submitted') {
                $submitted[] = $r;
            } elseif ($r['status'] === 'pending_review') {
                $pending_review[] = $r;
            } elseif ($r['status'] === 'rejected') {
                $rejected[] = $r;
            } else {
                $missing[] = $r;
            }
        }
    }

    $total_reqs = count($submitted) + count($missing) + count($pending_review) + count($rejected);
    $done_reqs  = count($submitted);
    $req_pct    = $total_reqs > 0 ? round(($done_reqs / $total_reqs) * 100) : 0;
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Accountabilities
          <span class="student-topbar-subtitle">Document requirements status</span>
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
            <h1 class="acct-page-title">Requirements</h1>
            <p class="acct-page-sub">Track which documents are submitted, awaiting review, or still needed to complete your file.</p>
          </div>
        </div>

        <div class="student-panel-block acct-req-panel">
          <div class="student-panel-header">
            <div class="student-panel-header-left">
              <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7-8h.01M5 6a2 2 0 012-2h10a2 2 0 012 2v14l-3-2-3 2-3-2-3 2V6z"/></svg></span>
              <div class="student-panel-title">Requirements</div>
            </div>
          </div>

          <?php if ($total_reqs === 0): ?>
            <div class="notice notice-success">No accountabilities yet.</div>
          <?php else: ?>

            <div class="req-progress">
              <div class="req-progress-top">
                <span class="req-progress-label">Your progress</span>
                <span class="req-progress-count"><?= $done_reqs ?> of <?= $total_reqs ?> verified</span>
              </div>
              <div class="req-progress-bar">
                <div class="req-progress-fill" style="width:<?= $req_pct ?>%;"></div>
              </div>
            </div>

            <?php if ($done_reqs === $total_reqs): ?>
              <div class="notice notice-success">All requirements have been submitted and verified. You're all set.</div>
            <?php else: ?>
              <p class="req-lead">Here's what's left. Bring physical copies to the <strong>Records office</strong> when you're ready.</p>
            <?php endif; ?>

            <div class="req-list">
              <?php foreach ($rejected as $r): ?>
              <div class="req-row rejected">
                <span class="req-icon bad"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14.14A1.5 1.5 0 003.5 20.5h17a1.5 1.5 0 001.39-2.5L13.71 3.86a1.5 1.5 0 00-2.42 0z"/></svg></span>
                <div class="req-body">
                  <div class="req-row-top">
                    <span class="req-name"><?= htmlspecialchars($r['requirement_name']) ?></span>
                    <span class="badge badge-cancelled">Needs Reupload</span>
                  </div>
                  <div class="req-note bad-note"><?= $r['rejection_reason'] ? htmlspecialchars($r['rejection_reason']) : 'Please resubmit this document.' ?></div>
                </div>
              </div>
              <?php endforeach; ?>

              <?php foreach ($missing as $r): ?>
              <div class="req-row muted">
                <span class="req-icon todo"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v10m0 0l-3.5-3.5M12 14l3.5-3.5M5 17h14"/></svg></span>
                <div class="req-body">
                  <div class="req-row-top">
                    <span class="req-name"><?= htmlspecialchars($r['requirement_name']) ?></span>
                    <span class="badge badge-expired">Not Submitted</span>
                  </div>
                  <div class="req-note">Bring this to the Records office to complete your file.</div>
                </div>
              </div>
              <?php endforeach; ?>

              <?php foreach ($pending_review as $r): ?>
              <div class="req-row">
                <span class="req-icon wait"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3.5 2"/></svg></span>
                <div class="req-body">
                  <div class="req-row-top">
                    <span class="req-name"><?= htmlspecialchars($r['requirement_name']) ?></span>
                    <span class="badge badge-pending">Awaiting Verification</span>
                  </div>
                  <div class="req-note">Received — the Records office is reviewing it.</div>
                </div>
              </div>
              <?php endforeach; ?>

              <?php foreach ($submitted as $r): ?>
              <div class="req-row">
                <span class="req-icon ok"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
                <div class="req-body">
                  <div class="req-row-top">
                    <span class="req-name"><?= htmlspecialchars($r['requirement_name']) ?></span>
                    <span class="badge badge-enrolled">Verified</span>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
