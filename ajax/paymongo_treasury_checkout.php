<?php
declare(strict_types=1);
// Backs the "Proceed to PayMongo" button on treasury/payment.php — the
// staff/admin counterpart to ajax/paymongo_create_checkout.php. Creates a
// PayMongo-hosted Checkout Session (GCash/Card/Maya/GrabPay) so staff can
// complete or relay a payment through PayMongo (e.g. a phone-in payment),
// then hands the checkout_url back for a real redirect. Same auth guard
// treasury/payment.php itself uses — default/unnamed session, no
// session_name() call.
session_start();
include_once '../config.php';
include_once '../config/paymongo.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['Not authorized.']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => ['Invalid request.']]);
    exit();
}

$enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
$amount_given  = trim($_POST['amount_given'] ?? '');
$staff_user_id = (int) $_SESSION['user_id'];

if ($enrollment_id <= 0) {
    echo json_encode(['success' => false, 'errors' => ['Missing enrollment.']]);
    exit();
}
if ($amount_given === '' || !is_numeric($amount_given) || (float) $amount_given <= 0) {
    echo json_encode(['success' => false, 'errors' => ['Please enter a valid amount.']]);
    exit();
}

$chk = mysqli_prepare($conn, "SELECT enrollment_id FROM enrollments WHERE enrollment_id = ?");
mysqli_stmt_bind_param($chk, "i", $enrollment_id);
mysqli_stmt_execute($chk);
$exists = mysqli_stmt_get_result($chk)->fetch_assoc();
mysqli_stmt_close($chk);

if (!$exists) {
    echo json_encode(['success' => false, 'errors' => ['Enrollment not found.']]);
    exit();
}

$amount = round((float) $amount_given, 2);
$amount_centavos = (int) round($amount * 100);

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$return_base = $proto . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/treasury/payment';

$result = paymongo_request('POST', '/checkout_sessions', [
    'line_items' => [[
        'amount'   => $amount_centavos,
        'currency' => 'PHP',
        'name'     => 'Tuition Payment',
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash', 'card', 'paymaya', 'grab_pay'],
    'description'          => 'Tuition payment — Enrollment #' . $enrollment_id,
    'send_email_receipt'   => false,
    'success_url'          => $return_base . '?enrollment_id=' . $enrollment_id . '&paymongo_return=1',
    'cancel_url'           => $return_base . '?enrollment_id=' . $enrollment_id . '&paymongo_cancelled=1',
    'metadata'             => [
        'enrollment_id' => (string) $enrollment_id,
        'staff_user_id' => (string) $staff_user_id,
    ],
]);

$checkoutId  = $result['body']['data']['id'] ?? null;
$checkoutUrl = $result['body']['data']['attributes']['checkout_url'] ?? null;

if ($result['status'] !== 200 || !$checkoutId || !$checkoutUrl) {
    error_log('[paymongo_treasury_checkout] unexpected response: ' . json_encode($result));
    echo json_encode(['success' => false, 'errors' => ['Could not start PayMongo checkout right now. Please try again.']]);
    exit();
}

// PayMongo's success_url doesn't support a session-id placeholder — it
// redirects back with the literal URL we gave it, nothing substituted.
// Since we already know the real session ID from this response, stash it
// here instead of round-tripping it through the URL.
$_SESSION['paymongo_treasury_pending_checkout_id'] = $checkoutId;

echo json_encode(['success' => true, 'checkout_url' => $checkoutUrl]);
