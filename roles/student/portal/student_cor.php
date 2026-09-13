<?php
// Distinct cookie name keeps the student session independent from admin/staff (see student_login.php).
session_name('STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: student_login"); exit();
}

$student_id = (int) $_SESSION['student_id'];

// The enrollment is always scoped to this session's own student_id —
// never trust a raw ?enrollment_id= alone (a tampered value just returns
// "not found" instead of another student's record). Defaults to the most
// recent enrollment, same convention as student_payments.php/student_pay_online.php.
$requested_id = isset($_GET['enrollment_id']) ? (int) $_GET['enrollment_id'] : 0;

$sql = "
    SELECT
        e.enrollment_id, e.student_id, e.status, e.admission_grade_level AS grade_level, strd.strand_code AS strand,
        e.school_year, e.enrollment_date, e.is_new_student, e.total_due, e.semester2_status,
        st.family_name, st.given_name, st.middle_name, st.suffix,
        st.sex, sa.address_line AS full_address, se.school_name AS jhs_school, se.year_graduated AS jhs_year_graduated, st.student_number, se.is_public AS jhs_is_public,
        sec.section_name, sec.room
    FROM enrollments e
    JOIN students st ON st.student_id = e.student_id
    LEFT JOIN student_addresses sa ON sa.student_id = st.student_id
    LEFT JOIN student_education se ON se.student_id = st.student_id
    JOIN strands strd ON strd.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    WHERE e.student_id = ?" . ($requested_id ? " AND e.enrollment_id = ?" : "") . "
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 1
";
$stmt = $conn->prepare($sql);
if ($requested_id) {
    $stmt->bind_param('ii', $student_id, $requested_id);
} else {
    $stmt->bind_param('i', $student_id);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header("Location: student_dashboard"); exit();
}
$enrollment_id = (int) $row['enrollment_id'];

// Not yet paid — nothing to show a certificate for yet.
if ($row['status'] !== 'enrolled') {
    header("Location: student_dashboard"); exit();
}

$pay_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE enrollment_id = ?");
$pay_stmt->bind_param('i', $enrollment_id);
$pay_stmt->execute();
$row['amount'] = (float) $pay_stmt->get_result()->fetch_assoc()['total_paid'];
$pay_stmt->close();

$latest_stmt = $conn->prepare("
    SELECT payment_method, paid_at, notes AS pay_notes
    FROM payments
    WHERE enrollment_id = ?
    ORDER BY paid_at DESC, payment_id DESC
    LIMIT 1
");
$latest_stmt->bind_param('i', $enrollment_id);
$latest_stmt->execute();
$latest = $latest_stmt->get_result()->fetch_assoc() ?: [];
$latest_stmt->close();
$row['payment_method'] = $latest['payment_method'] ?? null;
$row['paid_at']        = $latest['paid_at'] ?? null;
$row['pay_notes']      = $latest['pay_notes'] ?? null;

// ── Tuition/billing summary for this student's grade level ─────────────────
$tf_stmt = $conn->prepare("SELECT tuition_amount, shs_voucher, misc_fee FROM tuition_fees WHERE grade_level = ?");
$tf_stmt->bind_param('s', $row['grade_level']);
$tf_stmt->execute();
$tf_row = $tf_stmt->get_result()->fetch_assoc();
$tf_stmt->close();

$tuition_amount   = (float) ($tf_row['tuition_amount'] ?? 0);
$shs_voucher      = !empty($row['jhs_is_public']) ? (float) ($tf_row['shs_voucher'] ?? 0) : 0.0;
$misc_fee         = (float) ($tf_row['misc_fee'] ?? 0);
$assessment_total = $tuition_amount - $shs_voucher + $misc_fee;

// ── Subjects & teachers for this enrollment ─────────────────────────────────
// section_subjects has one schedule row per subject PER SEMESTER — without
// filtering to one, the join below fans out and every subject shows twice
// (its Sem1 slot and its Sem2 slot). enrollment_subjects itself carries no
// semester (a subject is a whole-year commitment; only its schedule varies),
// so the semester to display has to come from the same live "genuinely in
// Semester 2" gate used everywhere else in this codebase.
$isGenuinelySem2 = $row['semester2_status'] === 'approved'
    && !has_outstanding_accountabilities($conn, $enrollment_id, (int) $row['jhs_is_public']);
$displaySemester = $isGenuinelySem2 ? 2 : 1;

$subjects_sql = "
    SELECT subj.subject_name,
           ss.day, ss.start_time, ss.end_time,
           ss.room AS specialized_room,
           CONCAT(t.given_name, ' ', t.family_name) AS teacher_name
    FROM enrollment_subjects es
    JOIN subjects subj ON subj.subject_id = es.subject_id
    LEFT JOIN section_subjects ss ON ss.section_id = (
        SELECT section_id FROM enrollments WHERE enrollment_id = ?
    ) AND ss.subject_id = es.subject_id AND ss.semester = ?
    LEFT JOIN teachers t ON t.teacher_id = ss.teacher_id
    WHERE es.enrollment_id = ?
    ORDER BY subj.subject_name ASC
";
$subjects = [];
$subj_stmt = $conn->prepare($subjects_sql);
$subj_stmt->bind_param('iii', $enrollment_id, $displaySemester, $enrollment_id);
$subj_stmt->execute();
$subj_res = $subj_stmt->get_result();
while ($subj_res && ($sr = mysqli_fetch_assoc($subj_res))) {
    if (!empty($sr['day']) && !empty($sr['start_time']) && !empty($sr['end_time'])) {
        $sr['schedule_display'] = $sr['day'] . ' '
            . date('g:i A', strtotime($sr['start_time'])) . '–'
            . date('g:i A', strtotime($sr['end_time']));
    } else {
        $sr['schedule_display'] = null;
    }
    $sr['room_display'] = !empty($sr['specialized_room']) ? $sr['specialized_room'] : ($row['room'] ?? null);
    $subjects[] = $sr;
}
$subj_stmt->close();

$student_name = trim($row['family_name'] . ', ' . $row['given_name'] .
    ($row['middle_name'] ? ' ' . $row['middle_name'] : '') .
    ($row['suffix']      ? ' ' . $row['suffix']      : ''));
$print_date = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Enrollment — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_student.css') ?>">
    <style>
        .cor-page {
          width: 100%; max-width: 800px; margin: 0 auto;
          background: #ffffff; border-radius: 12px; border: 1px solid #EBEBF0;
          padding: 32px 28px;
        }
        .cor-school-header { text-align: center; border-bottom: 2px solid #1A1A2E; padding-bottom: 12px; margin-bottom: 16px; }
        .cor-school-name { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #1A1A2E; }
        .cor-school-address { font-size: 11px; color: #5A5A72; margin-top: 2px; }
        .cor-doc-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #1A1A2E; margin-top: 8px; }
        .cor-doc-subtitle { font-size: 11px; color: #5A5A72; margin-top: 2px; }
        .cor-status-row { display: flex; justify-content: space-between; align-items: center; background: #EBF7F2; border: 1px solid #A8D9C5; border-radius: 6px; padding: 8px 14px; margin-bottom: 14px; font-size: 12px; }
        .cor-status-paid { font-weight: 600; color: #1A7A5E; }
        .cor-status-meta { color: #5A5A72; }
        .cor-section-title { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #2F6B4F; margin: 14px 0 6px; padding-bottom: 4px; border-bottom: 0.5px solid #EBEBF0; }
        .cor-info-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .cor-info-table tr { border-bottom: 0.5px solid #F5F5F7; }
        .cor-info-table tr:last-child { border-bottom: none; }
        .cor-info-table th { text-align: left; font-weight: 500; color: #5A5A72; padding: 5px 8px 5px 0; width: 38%; vertical-align: top; }
        .cor-info-table td { padding: 5px 0; color: #1A1A2E; font-weight: 500; vertical-align: top; }
        .cor-payment-box { background: #F8F8FA; border: 0.5px solid #E0E0EC; border-radius: 6px; padding: 10px 14px; margin-top: 8px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .cor-pay-item-label { font-size: 10px; color: #8A8A9A; margin-bottom: 2px; }
        .cor-pay-item-value { font-size: 13px; font-weight: 600; color: #1A1A2E; }
        .cor-pay-amount { color: #1A7A5E; }
        .cor-sig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 28px; }
        .cor-sig-block { text-align: center; }
        .cor-sig-line { border-top: 1px solid #1A1A2E; margin-bottom: 4px; }
        .cor-sig-name { font-size: 12px; font-weight: 600; }
        .cor-sig-role { font-size: 10px; color: #5A5A72; }
        .cor-doc-footer { margin-top: 20px; padding-top: 10px; border-top: 0.5px solid #EBEBF0; font-size: 10px; color: #ADADBD; display: flex; justify-content: space-between; }
        @media print {
          .student-sidebar, .student-topbar, .btn-print-cor { display: none !important; }
          .student-main { margin: 0 !important; }
          .cor-page { border: none; box-shadow: none; max-width: 100%; }
        }
    </style>
</head>
<body class="student-layout">
  <?php include_once BASE_PATH . '/shared/includes/student_sidebar.php'; ?>
  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          Certificate of Enrollment
          <span class="student-topbar-subtitle">Enrollment #<?= $enrollment_id ?></span>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="student_dashboard" class="btn-student-primary" style="background:transparent;color:#1E4D3B;border:0.5px solid #1E4D3B;">Back</a>
        <button type="button" class="btn-student-primary btn-print-cor" onclick="window.print()">Print</button>
      </div>
    </div>

    <div class="student-content">
      <div class="cor-page">

        <div class="cor-school-header">
          <div class="cor-school-name">Senior High School Enrollment System</div>
          <div class="cor-school-address">School Address &middot; City, Province &middot; Philippines</div>
          <div class="cor-doc-title">Certificate of Enrollment</div>
          <div class="cor-doc-subtitle">School Year <?= htmlspecialchars($row['school_year']) ?></div>
        </div>

        <div class="cor-status-row">
          <span class="cor-status-paid">&#10003; Enrollment Confirmed &amp; Paid</span>
          <span class="cor-status-meta">Enrollment #<?= $enrollment_id ?> &middot; <?= htmlspecialchars($row['enrollment_date']) ?></span>
        </div>

        <div class="cor-section-title">Student Information</div>
        <table class="cor-info-table">
          <tr><th>Full Name</th>       <td><?= htmlspecialchars($student_name) ?></td></tr>
          <?php if ($row['student_number']): ?>
          <tr><th>Student Number</th>  <td><?= htmlspecialchars($row['student_number']) ?></td></tr>
          <?php endif; ?>
          <tr><th>Sex</th>             <td><?= htmlspecialchars($row['sex']) ?></td></tr>
          <tr><th>Address</th>         <td><?= htmlspecialchars($row['full_address'] ?? '—') ?></td></tr>
        </table>

        <div class="cor-section-title">Enrollment Details</div>
        <table class="cor-info-table">
          <tr><th>Grade Level</th>  <td>Grade <?= htmlspecialchars($row['grade_level']) ?></td></tr>
          <tr><th>Strand</th>       <td><?= htmlspecialchars($row['strand']) ?></td></tr>
          <tr><th>Section</th>      <td><?= htmlspecialchars($row['section_name'] ?? '—') ?><?= $row['room'] ? ' — Room ' . htmlspecialchars($row['room']) : '' ?></td></tr>
          <tr><th>School Year</th>  <td><?= htmlspecialchars($row['school_year']) ?></td></tr>
          <tr><th>Student Type</th> <td><?= $row['is_new_student'] ? 'New Student' : 'Returning Student' ?></td></tr>
        </table>

        <div class="cor-section-title">Subjects &amp; Teachers</div>
        <?php if (empty($subjects)): ?>
          <p style="font-size:12px;color:#8A8A9A;">No subjects recorded for this enrollment.</p>
        <?php else: ?>
          <table class="cor-info-table">
            <thead>
              <tr>
                <th style="width:32%;">Subject</th>
                <th style="width:24%;">Schedule</th>
                <th style="width:26%;">Teacher</th>
                <th style="width:18%;">Room</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subjects as $subj): ?>
                <tr>
                  <td><?= htmlspecialchars($subj['subject_name']) ?></td>
                  <td><?= htmlspecialchars($subj['schedule_display'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($subj['teacher_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($subj['room_display'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <div class="cor-section-title">Billing Summary</div>
        <table class="cor-info-table">
          <tr><th>Tuition Fee</th><td>&#8369;<?= number_format($tuition_amount, 2) ?></td></tr>
          <tr><th>SHS Voucher</th><td>&minus;&#8369;<?= number_format($shs_voucher, 2) ?></td></tr>
          <tr><th>Miscellaneous Fee</th><td>&#8369;<?= number_format($misc_fee, 2) ?></td></tr>
          <tr><th>Assessment</th><td style="font-weight:700;">&#8369;<?= number_format($assessment_total, 2) ?></td></tr>
        </table>

        <div class="cor-section-title">Payment Record</div>
        <div class="cor-payment-box">
          <div>
            <div class="cor-pay-item-label">Amount Paid</div>
            <div class="cor-pay-item-value cor-pay-amount">&#8369;<?= number_format((float) ($row['amount'] ?? 0), 2) ?></div>
          </div>
          <div>
            <div class="cor-pay-item-label">Method</div>
            <div class="cor-pay-item-value"><?= htmlspecialchars($row['payment_method'] ?? '—') ?></div>
          </div>
          <div>
            <div class="cor-pay-item-label">Date Paid</div>
            <div class="cor-pay-item-value"><?= $row['paid_at'] ? date('M j, Y', strtotime($row['paid_at'])) : '—' ?></div>
          </div>
          <?php if ($row['pay_notes']): ?>
          <div style="grid-column:1/-1;">
            <div class="cor-pay-item-label">Notes / OR No.</div>
            <div class="cor-pay-item-value" style="font-size:12px;"><?= htmlspecialchars($row['pay_notes']) ?></div>
          </div>
          <?php endif; ?>
        </div>

        <div class="cor-sig-row">
          <div class="cor-sig-block">
            <div style="height:36px;"></div>
            <div class="cor-sig-line"></div>
            <div class="cor-sig-name">Student / Guardian Signature</div>
            <div class="cor-sig-role">Conforme</div>
          </div>
          <div class="cor-sig-block">
            <div style="height:36px;"></div>
            <div class="cor-sig-line"></div>
            <div class="cor-sig-name">Registrar / Authorized Personnel</div>
            <div class="cor-sig-role">Signature over Printed Name</div>
          </div>
        </div>

        <div class="cor-doc-footer">
          <span>Printed by: <?= htmlspecialchars($student_name) ?></span>
          <span>Date printed: <?= $print_date ?></span>
        </div>

      </div>
    </div>
  </div>
</body>
</html>
