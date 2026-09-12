<?php
// Staff-only ("Print Proof") — students now have their own copy of this
// page at studentportal/student_cor.php, kept in their own portal/session
// (STUDENT_SESSID) instead of sharing this file across two audiences.
session_start();
include_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login"); exit();
}

$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

$enrollment_id = (int)($_GET['enrollment_id'] ?? 0);
if (!$enrollment_id) {
    header("Location: ../staff/staff_dashboard"); exit();
}

// ── Fetch enrollment + student + section + payment ──────────────────────────
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
    WHERE e.enrollment_id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $enrollment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $conn->close();
    header("Location: ../staff/staff_dashboard"); exit();
}

$pay_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE enrollment_id = ?");
$pay_stmt->bind_param('i', $enrollment_id);
$pay_stmt->execute();
$row['amount'] = (float) $pay_stmt->get_result()->fetch_assoc()['total_paid'];
$pay_stmt->close();

$latest_sql = "
    SELECT payment_method, paid_at, notes AS pay_notes
    FROM payments
    WHERE enrollment_id = ?
    ORDER BY paid_at DESC, payment_id DESC
    LIMIT 1
";
$latest_stmt = $conn->prepare($latest_sql);
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
// SHS voucher only applies to students who came from a public junior high school.
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

$conn->close();

// Not yet paid — redirect to payment first
if ($row['status'] === 'pending') {
    header("Location: payment?enrollment_id=$enrollment_id"); exit();
}

$student_name = trim($row['family_name'] . ', ' . $row['given_name'] .
    ($row['middle_name'] ? ' ' . $row['middle_name'] : '') .
    ($row['suffix']      ? ' ' . $row['suffix']      : ''));
$printed_by   = $_SESSION['username'] ?? 'Staff';

$back_link = $is_admin ? '../admin/enrollments' : 'enrollments_staff';
$print_date   = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enrollment Proof — #<?= $enrollment_id ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', sans-serif;
      background: #F5F5F7;
      color: #1A1A2E;
      min-height: 100vh;
    }

    /* ── Screen toolbar ───────────────────────────────────────────────────── */
    .toolbar {
      background: #1A1A2E;
      padding: 0.75rem 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .toolbar-info {
      font-size: 13px;
      color: rgba(255,255,255,0.6);
    }
    .toolbar-info strong { color: #fff; }
    .toolbar-actions { display: flex; gap: 8px; }
    .btn-print {
      height: 34px; padding: 0 16px; background: #1E4D3B; border: none; border-radius: 7px;
      color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-print:hover { background: #163829; }
    .btn-back {
      height: 34px; padding: 0 14px; background: transparent; border: 0.5px solid rgba(255,255,255,0.2);
      border-radius: 7px; color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;
      cursor: pointer; font-family: inherit; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-back:hover { border-color: rgba(255,255,255,0.5); color: #fff; }

    /* ── Proof page ───────────────────────────────────────────────────────── */
    .page {
      width: 210mm;
      min-height: 297mm;
      margin: 2rem auto;
      background: #ffffff;
      box-shadow: 0 4px 24px rgba(0,0,0,0.12);
      padding: 20mm 18mm 18mm;
    }

    /* ── School header ────────────────────────────────────────────────────── */
    .school-header {
      text-align: center;
      border-bottom: 2px solid #1A1A2E;
      padding-bottom: 12px;
      margin-bottom: 16px;
    }
    .school-name {
      font-size: 15px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #1A1A2E;
    }
    .school-address {
      font-size: 11px;
      color: #5A5A72;
      margin-top: 2px;
    }
    .doc-title {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #1A1A2E;
      margin-top: 8px;
    }
    .doc-subtitle {
      font-size: 11px;
      color: #5A5A72;
      margin-top: 2px;
    }

    /* ── Status badge ─────────────────────────────────────────────────────── */
    .status-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #EBF7F2;
      border: 1px solid #A8D9C5;
      border-radius: 6px;
      padding: 8px 14px;
      margin-bottom: 14px;
      font-size: 12px;
    }
    .status-paid { font-weight: 600; color: #1A7A5E; }
    .status-meta { color: #5A5A72; }

    /* ── Sections ─────────────────────────────────────────────────────────── */
    .section-title {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #2F6B4F;
      margin: 14px 0 6px;
      padding-bottom: 4px;
      border-bottom: 0.5px solid #EBEBF0;
    }

    /* ── Info table ───────────────────────────────────────────────────────── */
    .info-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }
    .info-table tr { border-bottom: 0.5px solid #F5F5F7; }
    .info-table tr:last-child { border-bottom: none; }
    .info-table th {
      text-align: left;
      font-weight: 500;
      color: #5A5A72;
      padding: 5px 8px 5px 0;
      width: 38%;
      vertical-align: top;
    }
    .info-table td {
      padding: 5px 0;
      color: #1A1A2E;
      font-weight: 500;
      vertical-align: top;
    }

    /* ── Payment block ────────────────────────────────────────────────────── */
    .payment-box {
      background: #F8F8FA;
      border: 0.5px solid #E0E0EC;
      border-radius: 6px;
      padding: 10px 14px;
      margin-top: 8px;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 8px;
    }
    .pay-item-label { font-size: 10px; color: #8A8A9A; margin-bottom: 2px; }
    .pay-item-value { font-size: 13px; font-weight: 600; color: #1A1A2E; }
    .pay-amount { color: #1A7A5E; }

    /* ── Signature area ───────────────────────────────────────────────────── */
    .sig-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2rem;
      margin-top: 28px;
    }
    .sig-block { text-align: center; }
    .sig-line {
      border-top: 1px solid #1A1A2E;
      margin-bottom: 4px;
    }
    .sig-name { font-size: 12px; font-weight: 600; }
    .sig-role { font-size: 10px; color: #5A5A72; }

    /* ── Footer ───────────────────────────────────────────────────────────── */
    .doc-footer {
      margin-top: 20px;
      padding-top: 10px;
      border-top: 0.5px solid #EBEBF0;
      font-size: 10px;
      color: #ADADBD;
      display: flex;
      justify-content: space-between;
    }

    /* ── Print ────────────────────────────────────────────────────────────── */
    @media print {
      body  { background: #fff; }
      .toolbar { display: none; }
      .page {
        width: 100%;
        margin: 0;
        padding: 14mm 14mm 12mm;
        box-shadow: none;
        min-height: unset;
      }
    }
  </style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
  <div class="toolbar-info">
    Enrollment Proof &mdash; <strong>#<?= $enrollment_id ?></strong>
    &nbsp;&middot;&nbsp; <?= htmlspecialchars($student_name) ?>
  </div>
  <div class="toolbar-actions">
    <a href="<?= htmlspecialchars($back_link) ?>" class="btn-back">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back
    </a>
    <button class="btn-print" onclick="window.print()">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
      </svg>
      Print
    </button>
  </div>
</div>

<!-- Printable proof -->
<div class="page">

  <div class="school-header">
    <div class="school-name">Senior High School Enrollment System</div>
    <div class="school-address">School Address &middot; City, Province &middot; Philippines</div>
    <div class="doc-title">Certificate of Enrollment</div>
    <div class="doc-subtitle">School Year <?= htmlspecialchars($row['school_year']) ?></div>
  </div>

  <div class="status-row">
    <span class="status-paid">&#10003; Enrollment Confirmed &amp; Paid</span>
    <span class="status-meta">Enrollment #<?= $enrollment_id ?> &middot; <?= htmlspecialchars($row['enrollment_date']) ?></span>
  </div>

  <!-- Student info -->
  <div class="section-title">Student Information</div>
  <table class="info-table">
    <tr><th>Full Name</th>       <td><?= htmlspecialchars($student_name) ?></td></tr>
    <?php if ($row['student_number']): ?>
    <tr><th>Student Number</th>  <td><?= htmlspecialchars($row['student_number']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Sex</th>             <td><?= htmlspecialchars($row['sex']) ?></td></tr>
    <tr><th>Address</th>         <td><?= htmlspecialchars($row['full_address'] ?? '—') ?></td></tr>
  </table>

  <!-- Enrollment info -->
  <div class="section-title">Enrollment Details</div>
  <table class="info-table">
    <tr><th>Grade Level</th>  <td>Grade <?= htmlspecialchars($row['grade_level']) ?></td></tr>
    <tr><th>Strand</th>       <td><?= htmlspecialchars($row['strand']) ?></td></tr>
    <tr><th>Section</th>      <td><?= htmlspecialchars($row['section_name'] ?? '—') ?><?= $row['room'] ? ' — Room ' . htmlspecialchars($row['room']) : '' ?></td></tr>
    <tr><th>School Year</th>  <td><?= htmlspecialchars($row['school_year']) ?></td></tr>
    <tr><th>Student Type</th> <td><?= $row['is_new_student'] ? 'New Student' : 'Returning Student' ?></td></tr>
  </table>

  <!-- Subjects & teachers -->
  <div class="section-title">Subjects &amp; Teachers</div>
  <?php if (empty($subjects)): ?>
    <p style="font-size:12px;color:#8A8A9A;">No subjects recorded for this enrollment.</p>
  <?php else: ?>
    <table class="info-table">
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

  <!-- Billing summary -->
  <div class="section-title">Billing Summary</div>
  <table class="info-table">
    <tr><th>Tuition Fee</th><td>&#8369;<?= number_format($tuition_amount, 2) ?></td></tr>
    <tr><th>SHS Voucher</th><td>&minus;&#8369;<?= number_format($shs_voucher, 2) ?></td></tr>
    <tr><th>Miscellaneous Fee</th><td>&#8369;<?= number_format($misc_fee, 2) ?></td></tr>
    <tr><th>Assessment</th><td style="font-weight:700;">&#8369;<?= number_format($assessment_total, 2) ?></td></tr>
  </table>

  <!-- Payment info -->
  <div class="section-title">Payment Record</div>
  <div class="payment-box">
    <div>
      <div class="pay-item-label">Amount Paid</div>
      <div class="pay-item-value pay-amount">&#8369;<?= number_format((float)($row['amount'] ?? 0), 2) ?></div>
    </div>
    <div>
      <div class="pay-item-label">Method</div>
      <div class="pay-item-value"><?= htmlspecialchars($row['payment_method'] ?? '—') ?></div>
    </div>
    <div>
      <div class="pay-item-label">Date Paid</div>
      <div class="pay-item-value"><?= $row['paid_at'] ? date('M j, Y', strtotime($row['paid_at'])) : '—' ?></div>
    </div>
    <?php if ($row['pay_notes']): ?>
    <div style="grid-column:1/-1;">
      <div class="pay-item-label">Notes / OR No.</div>
      <div class="pay-item-value" style="font-size:12px;"><?= htmlspecialchars($row['pay_notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Signatures -->
  <div class="sig-row">
    <div class="sig-block">
      <div style="height:36px;"></div>
      <div class="sig-line"></div>
      <div class="sig-name">Student / Guardian Signature</div>
      <div class="sig-role">Conforme</div>
    </div>
    <div class="sig-block">
      <div style="height:36px;"></div>
      <div class="sig-line"></div>
      <div class="sig-name">Registrar / Authorized Personnel</div>
      <div class="sig-role">Signature over Printed Name</div>
    </div>
  </div>

  <div class="doc-footer">
    <span>Printed by: <?= htmlspecialchars($printed_by) ?></span>
    <span>Date printed: <?= $print_date ?></span>
  </div>

</div>

<script>window.addEventListener('load', () => window.print());</script>

</body>
</html>
