<?php
declare(strict_types=1);
// Generates a real downloadable PDF for one of the logged-in student's own
// payments — backs the "Download PDF" button in student_payments.php's
// receipt modal (previously a disabled "Coming Soon" placeholder).
session_name('STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

require_login('student', 'student_login');

$student_id  = (int) $_SESSION['student_id'];
$payment_id  = (int) ($_GET['payment_id'] ?? 0);

// Joined and filtered by the logged-in student's own student_id — a
// payment_id alone is never trusted, so one student can never fetch
// another's receipt by guessing/editing the query string.
$stmt = mysqli_prepare($conn, "
    SELECT p.payment_id, p.amount, p.payment_method, p.paid_at, p.notes,
           e.school_year, e.admission_grade_level,
           st.strand_code,
           s.given_name, s.family_name, s.student_number
    FROM payments p
    JOIN enrollments e ON e.enrollment_id = p.enrollment_id
    JOIN strands st ON st.strand_id = e.admission_strand
    JOIN students s ON s.student_id = e.student_id
    WHERE p.payment_id = ? AND e.student_id = ?
");
mysqli_stmt_bind_param($stmt, "ii", $payment_id, $student_id);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    exit('Receipt not found.');
}

$receiptNo  = 'OR-' . str_pad((string) $row['payment_id'], 6, '0', STR_PAD_LEFT);
$studentFull = trim($row['given_name'] . ' ' . $row['family_name']);
$datePaid   = date('F j, Y', strtotime($row['paid_at']));
$amount     = number_format((float) $row['amount'], 2);

$notesRow = '';
if (!empty($row['notes'])) {
    $notesRow = '<div class="row"><span class="label">Notes</span><span class="value">' . htmlspecialchars($row['notes']) . '</span></div>';
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1A1A2E; font-size: 12px; }
  .header { text-align: center; margin-bottom: 18px; border-bottom: 1.5px solid #386641; padding-bottom: 12px; }
  .header h1 { font-size: 16px; margin: 0; color: #386641; }
  .header p { font-size: 10px; color: #5A5A72; margin: 2px 0 0; text-transform: uppercase; letter-spacing: .05em; }
  .title { text-align: center; font-size: 13px; font-weight: bold; margin: 18px 0 4px; text-transform: uppercase; letter-spacing: .05em; }
  .receipt-no { text-align: center; font-size: 11px; color: #5A5A72; margin-bottom: 18px; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .row span { display: block; padding: 8px 0; border-bottom: 0.5px solid #D4D4E0; }
  .label { color: #5A5A72; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
  .value { font-size: 13px; font-weight: bold; padding-top: 2px !important; border-bottom: none !important; }
  .amount .value { color: #386641; font-size: 18px; }
  .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #8A8A9A; }
</style>
</head>
<body>
  <div class="header">
    <h1>Greenfield Senior High School</h1>
    <p>Official Payment Receipt</p>
  </div>

  <div class="title">Receipt</div>
  <div class="receipt-no">{$receiptNo}</div>

  <div class="row"><span class="label">Student Name</span><span class="value">{$studentFull}</span></div>
  <div class="row"><span class="label">Student Number</span><span class="value">{$row['student_number']}</span></div>
  <div class="row"><span class="label">Grade &amp; Strand</span><span class="value">Grade {$row['admission_grade_level']} &middot; {$row['strand_code']}</span></div>
  <div class="row"><span class="label">School Year</span><span class="value">{$row['school_year']}</span></div>
  <div class="row"><span class="label">Date Paid</span><span class="value">{$datePaid}</span></div>
  <div class="row"><span class="label">Payment Method</span><span class="value">{$row['payment_method']}</span></div>
  {$notesRow}
  <div class="row amount"><span class="label">Amount Paid</span><span class="value">&#8369;{$amount}</span></div>

  <div class="footer">This is a system-generated receipt and does not require a signature.</div>
</body>
</html>
HTML;

$options = new Options();
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'portrait');
$dompdf->render();
$dompdf->stream("receipt_{$receiptNo}.pdf", ['Attachment' => true]);
