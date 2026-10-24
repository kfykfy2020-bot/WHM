<?php
/**
 * Moyasar callback for WHMCS — verifies the payment server-side (secret key)
 * before crediting the invoice. Moyasar appends ?id=<payment_id>.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'moyasar';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$invoiceId = isset($_REQUEST['invoiceid']) ? (int) $_REQUEST['invoiceid'] : 0;
$paymentId = isset($_REQUEST['id']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['id']) : '';
$redirectBase = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;

if (!$paymentId || !$invoiceId) {
    header('Location: ' . $redirectBase . '&paymentfailed=true');
    exit;
}

// --- Server-side verification via Moyasar API (never trust the redirect alone) ---
$ch = curl_init('https://api.moyasar.com/v1/payments/' . urlencode($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $gatewayParams['secretKey'] . ':',
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$payment = json_decode($response, true);
$status = is_array($payment) ? ($payment['status'] ?? '') : '';

if ($httpCode !== 200 || $status !== 'paid') {
    logTransaction($gatewayParams['name'], (is_array($payment) ? $payment : ['raw' => $response, 'http' => $httpCode]), 'Unsuccessful');
    header('Location: ' . $redirectBase . '&paymentfailed=true');
    exit;
}

// Validate invoice + guard against duplicate crediting
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($paymentId);

$amountMajor = ((int) ($payment['amount'] ?? 0)) / 100;
$feeMajor = ((int) ($payment['fee'] ?? 0)) / 100;

logTransaction($gatewayParams['name'], $payment, 'Success');
addInvoicePayment($invoiceId, $paymentId, $amountMajor, $feeMajor, $gatewayModuleName);

header('Location: ' . $redirectBase . '&paymentsuccess=true');
exit;
