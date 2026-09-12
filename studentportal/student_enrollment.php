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
    <title>Enrollment — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_student.css?v=<?= filemtime(__DIR__ . '/../css/css_student.css') ?>">
</head>
<body class="student-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student'): ?>
  <p>You are not logged in. Please <a href="student_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php
    include_once 'student_sidebar.php';

    $student_id = (int) $_SESSION['student_id'];

    $enr_stmt = mysqli_prepare($conn, "
        SELECT e.enrollment_id, e.status, e.school_year, e.admission_grade_level AS grade_level, st.strand_code AS strand
        FROM enrollments e
        JOIN strands st ON st.strand_id = e.admission_strand
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
    mysqli_stmt_execute($enr_stmt);
    $enrollment = mysqli_fetch_assoc(mysqli_stmt_get_result($enr_stmt));
    mysqli_stmt_close($enr_stmt);
  ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Enrollment
          <span class="student-topbar-subtitle">Your enrollment status</span>
        </div>
      </div>
      <?php include 'student_topbar_right.php'; ?>
    </div>

    <div class="student-content">

      <div class="student-panel-block">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            <div class="student-panel-title">Enrollment Status</div>
          </div>
        </div>
        <?php if (!$enrollment): ?>

          <div class="enroll-status-row">
            <span class="enroll-status-icon neutral">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
              <div class="enroll-status-title">No enrollment record yet</div>
              <div class="enroll-status-sub">You don't have an enrollment record on file yet. Check back once your application has been processed.</div>
            </div>
          </div>

        <?php elseif ($enrollment['status'] === 'enrolled'): ?>

          <div class="enroll-status-row">
            <span class="enroll-status-icon ok">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
              <div class="enroll-status-title">You are already enrolled in Greenfield Senior High School for the current School Year</div>
              <div class="enroll-status-sub">School Year <?= htmlspecialchars($enrollment['school_year']) ?> — Grade <?= htmlspecialchars($enrollment['grade_level']) ?>, <?= htmlspecialchars($enrollment['strand']) ?></div>
            </div>
          </div>
          <a class="btn-student-primary" href="student_cor?enrollment_id=<?= (int) $enrollment['enrollment_id'] ?>">Show COR</a>

        <?php elseif ($enrollment['status'] === 'pre_enrolled'): ?>

          <div class="enroll-status-row">
            <span class="enroll-status-icon warn">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-8.25 3.75h.008v.008h-.008v-.008z"/></svg>
            </span>
            <div>
              <div class="enroll-status-title">You are pre-enrolled — payment pending</div>
              <div class="enroll-status-sub">School Year <?= htmlspecialchars($enrollment['school_year']) ?>. You're registered, but your enrollment isn't final until your initial payment is recorded by the Treasury office.</div>
            </div>
          </div>

        <?php elseif ($enrollment['status'] === 'pending'): ?>

          <div class="enroll-status-row">
            <span class="enroll-status-icon warn">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
              <div class="enroll-status-title">Your enrollment is still being processed</div>
              <div class="enroll-status-sub">School Year <?= htmlspecialchars($enrollment['school_year']) ?>. We'll update this once staff finish processing your record.</div>
            </div>
          </div>

        <?php else: ?>

          <div class="enroll-status-row">
            <span class="enroll-status-icon bad">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l-6 6m0-6l6 6m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
              <div class="enroll-status-title">Your enrollment was <?= htmlspecialchars($enrollment['status']) ?></div>
              <div class="enroll-status-sub">School Year <?= htmlspecialchars($enrollment['school_year']) ?>. Please visit or contact the Registrar's office for assistance.</div>
            </div>
          </div>

        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; ?>
</body>
</html>
