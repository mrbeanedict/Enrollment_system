<?php
declare(strict_types=1);
// Called automatically (via fetch, on page load) when the student lands
// back on roles/student/portal/student_pay_online.php after a PayMongo Checkout
// Session — either from the success_url or after refreshing that URL.
//
// This app has no public URL for PayMongo to send a webhook to (local
// XAMPP), so confirmation works by polling PayMongo directly here instead:
// we ask PayMongo "did this checkout session actually get paid?" rather
// than trusting anything the browser/URL claims. Only once PayMongo
// itself confirms `status === 'paid'` does a row get written to
// online_payment_submissions — at that point Treasury's existing review
// queue (treasury/online_payments.php, unchanged) picks it up exactly
// like any other online submission, except this one's reference_no is a
// real, independently-verifiable PayMongo payment ID instead of a
// simulated one.
session_name('STUDENT_SESSID');
session_start();
include_once '../config.php';
include_once '../config/paymongo.php';
include_once '../notify.php';
require_once '../config/mail.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized.']);
    exit();
}

$student_id  = (int) $_SESSION['student_id'];
// PayMongo's success_url has no session-id placeholder to redirect back
// with — the real ID was stashed here at creation time instead (see
// paymongo_create_checkout.php).
$checkout_id = trim($_SESSION['paymongo_pending_checkout_id'] ?? '');

if ($checkout_id === '' || !preg_match('/^cs_[A-Za-z0-9]+$/', $checkout_id)) {
    echo json_encode(['success' => false, 'error' => 'No pending PayMongo checkout found for this session.']);
    exit();
}

$result = paymongo_request('GET', '/checkout_sessions/' . urlencode($checkout_id));

if ($result['status'] !== 200) {
    error_log('[paymongo_confirm_return] lookup failed: ' . json_encode($result));
    echo json_encode(['success' => false, 'error' => 'Could not verify your payment right now. Please contact Treasury if you were charged.']);
    exit();
}

$attrs    = $result['body']['data']['attributes'] ?? [];
$metadata = $attrs['metadata'] ?? [];
$payments = $attrs['payments'] ?? [];

// Never trust the URL alone — confirm this checkout session was really
// created for the student who's currently logged in.
if ((int) ($metadata['student_id'] ?? 0) !== $student_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This payment does not belong to your account.']);
    exit();
}

$enrollment_id = (int) ($metadata['enrollment_id'] ?? 0);

// Double-check the enrollment really is this student's own, not just
// trusting the metadata payload.
$own_stmt = mysqli_prepare($conn, "SELECT enrollment_id FROM enrollments WHERE enrollment_id = ? AND student_id = ?");
mysqli_stmt_bind_param($own_stmt, "ii", $enrollment_id, $student_id);
mysqli_stmt_execute($own_stmt);
$owns = mysqli_stmt_get_result($own_stmt)->fetch_assoc();
mysqli_stmt_close($own_stmt);

if (!$owns) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This payment does not belong to your account.']);
    exit();
}

$paidPayment = null;
foreach ($payments as $p) {
    if (($p['attributes']['status'] ?? '') === 'paid') { $paidPayment = $p; break; }
}

if (!$paidPayment) {
    // Not an error — the student may have cancelled, or the redirect
    // simply arrived before PayMongo's own processing finished.
    echo json_encode(['success' => false, 'pending' => true, 'error' => 'Payment was not completed.']);
    exit();
}

$paymongo_payment_id = $paidPayment['id'] ?? $checkout_id;
$amount = round(((float) ($paidPayment['attributes']['amount'] ?? 0)) / 100, 2);
$sourceType = strtolower($paidPayment['attributes']['source']['type'] ?? '');
$CHANNEL_MAP = ['gcash' => 'GCash', 'card' => 'Card', 'paymaya' => 'Maya', 'grab_pay' => 'GrabPay'];
$method = $CHANNEL_MAP[$sourceType] ?? 'Card';

if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Could not verify the payment amount.']);
    exit();
}

// Idempotency guard — the return URL (or this endpoint) can legitimately
// be hit more than once (page refresh, back/forward). Never record the
// same PayMongo payment twice.
$dupe_stmt = mysqli_prepare($conn, "SELECT submission_id FROM online_payment_submissions WHERE reference_no = ?");
mysqli_stmt_bind_param($dupe_stmt, "s", $paymongo_payment_id);
mysqli_stmt_execute($dupe_stmt);
$already = mysqli_stmt_get_result($dupe_stmt)->fetch_assoc();
mysqli_stmt_close($dupe_stmt);

if ($already) {
    unset($_SESSION['paymongo_pending_checkout_id']);
    echo json_encode(['success' => true, 'already_recorded' => true]);
    exit();
}

$ins = mysqli_prepare($conn, "
    INSERT INTO online_payment_submissions (enrollment_id, amount, payment_method, reference_no)
    VALUES (?, ?, ?, ?)
");
mysqli_stmt_bind_param($ins, "idss", $enrollment_id, $amount, $method, $paymongo_payment_id);

if (!mysqli_stmt_execute($ins)) {
    mysqli_stmt_close($ins);
    error_log('[paymongo_confirm_return] insert failed for payment ' . $paymongo_payment_id);
    echo json_encode(['success' => false, 'error' => 'We verified your PayMongo payment but could not record it — please contact Treasury with reference ' . $paymongo_payment_id . '.']);
    exit();
}
mysqli_stmt_close($ins);

notify_student_users(
    $conn,
    [(int) ($_SESSION['user_student_id'] ?? 0)],
    'Your online payment of ₱' . number_format($amount, 2) . ' is pending Treasury verification.',
    'roles/student/portal/student_pay_online'
);

// Best-effort — same pattern as submit_online_payment in
// student_pay_online.php, never undoes the submission on failure.
$stu_stmt = mysqli_prepare($conn, "SELECT given_name, family_name, email FROM students WHERE student_id = ?");
mysqli_stmt_bind_param($stu_stmt, "i", $student_id);
mysqli_stmt_execute($stu_stmt);
$stu = mysqli_stmt_get_result($stu_stmt)->fetch_assoc();
mysqli_stmt_close($stu_stmt);

if (!empty($stu['email'])) {
    try {
        $mail = getMailer();
        $bodyHtml = '<p>Hi ' . htmlspecialchars($stu['given_name']) . ', we received your online payment via PayMongo:</p>'
            . email_detail_rows([
                'Method'    => $method,
                'Amount'    => '₱' . number_format($amount, 2),
                'Reference' => $paymongo_payment_id,
            ])
            . '<p style="margin-top:16px;">This is <strong>pending Treasury verification</strong> — you\'ll receive another email once it\'s confirmed or if there\'s an issue with it.</p>';
        send_branded_email(
            $mail,
            $stu['email'],
            trim($stu['given_name'] . ' ' . $stu['family_name']),
            'Online Payment Submitted — Pending Verification',
            'Payment Submitted',
            $bodyHtml,
            "Method: $method\nAmount: PHP " . number_format($amount, 2) . "\nReference: $paymongo_payment_id\nStatus: Pending verification"
        );
    } catch (\Throwable $e) {
        error_log('[paymongo_confirm_return] confirmation email failed: ' . $e->getMessage());
    }
}

unset($_SESSION['paymongo_pending_checkout_id']);
echo json_encode(['success' => true, 'amount' => $amount, 'method' => $method]);
