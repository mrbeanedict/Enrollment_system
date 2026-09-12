<?php
declare(strict_types=1);
// Staff/admin counterpart to ajax/paymongo_confirm_return.php. Called
// automatically when staff land back on treasury/payment.php after a
// PayMongo Checkout Session. Unlike the student version, this endpoint
// does NOT write to the database — it only verifies the payment with
// PayMongo and hands the confirmed amount/channel/reference back to the
// page's JS, which fills in the existing Amount Given / Payment Method
// fields and submits the *existing* payment form. That form's POST
// handler already contains all the real business logic (installments,
// first-payment total_due, student-account provisioning, COR generation)
// — reusing it here means none of that gets duplicated or drifts out of
// sync with the Cash path.
session_start();
include_once '../config.php';
include_once '../config/paymongo.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized.']);
    exit();
}

// PayMongo's success_url has no session-id placeholder to redirect back
// with — the real ID was stashed here at creation time instead (see
// paymongo_treasury_checkout.php).
$checkout_id   = trim($_SESSION['paymongo_treasury_pending_checkout_id'] ?? '');
$enrollment_id = (int) ($_GET['enrollment_id'] ?? 0);

if ($checkout_id === '' || !preg_match('/^cs_[A-Za-z0-9]+$/', $checkout_id) || $enrollment_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No pending PayMongo checkout found for this session.']);
    exit();
}

$result = paymongo_request('GET', '/checkout_sessions/' . urlencode($checkout_id));

if ($result['status'] !== 200) {
    error_log('[paymongo_treasury_confirm] lookup failed: ' . json_encode($result));
    echo json_encode(['success' => false, 'error' => 'Could not verify this payment right now. Please try again.']);
    exit();
}

$attrs    = $result['body']['data']['attributes'] ?? [];
$metadata = $attrs['metadata'] ?? [];
$payments = $attrs['payments'] ?? [];

// The checkout was created for a specific enrollment — never trust the
// URL's enrollment_id alone, confirm it against what PayMongo has on file.
if ((int) ($metadata['enrollment_id'] ?? 0) !== $enrollment_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This checkout session does not match this enrollment.']);
    exit();
}

$paidPayment = null;
foreach ($payments as $p) {
    if (($p['attributes']['status'] ?? '') === 'paid') { $paidPayment = $p; break; }
}

if (!$paidPayment) {
    // Not resolved yet — leave the pending checkout id in session so a
    // retry/refresh can still find it.
    echo json_encode(['success' => false, 'pending' => true, 'error' => 'Payment was not completed.']);
    exit();
}

$amount = round(((float) ($paidPayment['attributes']['amount'] ?? 0)) / 100, 2);
$sourceType = strtolower($paidPayment['attributes']['source']['type'] ?? '');
$CHANNEL_MAP = ['gcash' => 'GCash', 'card' => 'Card', 'paymaya' => 'Maya', 'grab_pay' => 'GrabPay'];
$method = $CHANNEL_MAP[$sourceType] ?? 'Card';
$reference = $paidPayment['id'] ?? $checkout_id;

if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Could not verify the payment amount.']);
    exit();
}

unset($_SESSION['paymongo_treasury_pending_checkout_id']);
echo json_encode(['success' => true, 'amount' => $amount, 'method' => $method, 'reference' => $reference]);
