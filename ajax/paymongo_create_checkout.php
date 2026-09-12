<?php
declare(strict_types=1);
// Backs the "Pay with PayMongo (GCash / Card)" tab on
// studentportal/student_pay_online.php. Creates a PayMongo-hosted
// Checkout Session (supports both GCash and Card in one flow — PayMongo
// handles card data directly, so this app never touches raw card
// numbers) and hands the student-facing checkout_url back to the page,
// which does a real navigation there. No submission row is created
// here — see paymongo_confirm_return.php for why.
session_name('STUDENT_SESSID');
session_start();
include_once '../config.php';
include_once '../config/paymongo.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['Not authorized.']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => ['Your session expired. Please reload and try again.']]);
    exit();
}

$student_id   = (int) $_SESSION['student_id'];
$amount_given = trim($_POST['amount_given'] ?? '');

// Same "most recent enrollment, switchable via enrollment_id" lookup as
// student_pay_online.php, kept in sync with that page.
$enr_stmt = mysqli_prepare($conn, "
    SELECT e.enrollment_id, e.status, e.total_due
    FROM enrollments e
    WHERE e.student_id = ?
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
");
mysqli_stmt_bind_param($enr_stmt, "i", $student_id);
mysqli_stmt_execute($enr_stmt);
$enrollments = mysqli_fetch_all(mysqli_stmt_get_result($enr_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($enr_stmt);

$enrollment = $enrollments[0] ?? null;
$requested_id = (int) ($_POST['enrollment_id'] ?? 0);
if ($requested_id > 0) {
    foreach ($enrollments as $e) {
        if ((int) $e['enrollment_id'] === $requested_id) { $enrollment = $e; break; }
    }
}

if (!$enrollment || $enrollment['status'] !== 'enrolled') {
    echo json_encode(['success' => false, 'errors' => ['Online payment is not available for this enrollment yet.']]);
    exit();
}

$paid_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE enrollment_id = ?");
mysqli_stmt_bind_param($paid_stmt, "i", $enrollment['enrollment_id']);
mysqli_stmt_execute($paid_stmt);
$total_paid = (float) (mysqli_stmt_get_result($paid_stmt)->fetch_assoc()['total_paid'] ?? 0);
mysqli_stmt_close($paid_stmt);

$balance = round((float) $enrollment['total_due'] - $total_paid, 2);

if ($balance <= 0) {
    echo json_encode(['success' => false, 'errors' => ['You have no outstanding balance — no payment is needed.']]);
    exit();
}
if ($amount_given === '' || !is_numeric($amount_given) || (float) $amount_given <= 0) {
    echo json_encode(['success' => false, 'errors' => ['Please enter a valid amount.']]);
    exit();
}
if ((float) $amount_given > $balance) {
    echo json_encode(['success' => false, 'errors' => ['Amount cannot exceed your outstanding balance of ₱' . number_format($balance, 2) . '.']]);
    exit();
}

$amount = round((float) $amount_given, 2);

// PayMongo amounts are in centavos (smallest currency unit).
$amount_centavos = (int) round($amount * 100);

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$return_base = $proto . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/studentportal/student_pay_online';

$result = paymongo_request('POST', '/checkout_sessions', [
    'line_items' => [[
        'amount'   => $amount_centavos,
        'currency' => 'PHP',
        'name'     => 'Tuition Payment',
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya', 'grab_pay'],
    'description'          => 'Tuition payment — Enrollment #' . $enrollment['enrollment_id'],
    'send_email_receipt'   => false,
    'success_url'          => $return_base . '?paymongo_return=1',
    'cancel_url'           => $return_base . '?paymongo_cancelled=1',
    'metadata'             => [
        'enrollment_id' => (string) $enrollment['enrollment_id'],
        'student_id'    => (string) $student_id,
    ],
]);

$checkoutId  = $result['body']['data']['id'] ?? null;
$checkoutUrl = $result['body']['data']['attributes']['checkout_url'] ?? null;

if ($result['status'] !== 200 || !$checkoutId || !$checkoutUrl) {
    error_log('[paymongo_create_checkout] unexpected response: ' . json_encode($result));
    echo json_encode(['success' => false, 'errors' => ['Could not start PayMongo checkout right now. Please try again.']]);
    exit();
}

// PayMongo's success_url doesn't support a session-id placeholder — it
// redirects back with the literal URL we gave it, nothing substituted.
// Since we already know the real session ID from this response, stash it
// here instead of round-tripping it through the URL (also can't be
// tampered with by editing the address bar, unlike a URL param).
$_SESSION['paymongo_pending_checkout_id'] = $checkoutId;

echo json_encode(['success' => true, 'checkout_url' => $checkoutUrl]);
