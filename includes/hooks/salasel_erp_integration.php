<?php
/**
 * Salasel Babel — WHMCS → ERP (نظام سلاسل بابل المحاسبي) integration.
 *
 * On every paid invoice, send a JOURNAL_VOUCHER (سند قيد يومي) to the ERP
 * external-systems API. The ERP receives it with NO effect; a human there
 * accepts and approves it before any entry is posted — so sending is safe.
 *
 *   debit  1104  (نقد لدى نقاط البيع والمدفوعات الإلكترونية) = invoice total
 *   credit 4102  (إيرادات الخدمات)                          = total - tax
 *   credit 2105  (ض.ق.م – مخرجات)                           = tax (if > 0)
 *
 * The API key + base URL live in /opt/erb-integration/config.php (chmod 600,
 * owned by www-data) — a secret, never committed to any repository.
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Database\Capsule;

add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $cfg = @include '/opt/erb-integration/config.php';
        if (!is_array($cfg) || empty($cfg['key']) || empty($cfg['base'])) {
            logActivity('ERP integration: config missing or incomplete; invoice #' . $invoiceId . ' not sent.');
            return;
        }

        $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$inv || $inv->status !== 'Paid') {
            return;
        }

        $total = round((float) $inv->total, 2);
        $tax   = round((float) $inv->tax + (float) $inv->tax2, 2);
        $net   = round($total - $tax, 2);
        if ($total <= 0) {
            return; // nothing to record
        }

        $client = Capsule::table('tblclients')->where('id', $inv->userid)->first();
        $clientName = '#' . $inv->userid;
        if ($client) {
            $clientName = trim($client->companyname) !== ''
                ? trim($client->companyname)
                : trim($client->firstname . ' ' . $client->lastname);
        }

        // payment account by gateway
        $gw = strtolower((string) $inv->paymentmethod);
        $cashAccount = '1104'; // electronic / Moyasar (default)
        if (strpos($gw, 'bank') !== false) {
            $cashAccount = '1102'; // bank transfer
        }

        $date = substr((string) $inv->datepaid, 0, 10);
        if ($date === '' || $date === '0000-00-00') {
            $date = date('Y-m-d');
        }

        $lines = [
            ['accountCode' => $cashAccount, 'debit' => $total, 'description' => 'تحصيل فاتورة #' . $invoiceId],
            ['accountCode' => '4102', 'credit' => $net, 'description' => 'إيراد خدمات'],
        ];
        if ($tax > 0) {
            $lines[] = ['accountCode' => '2105', 'credit' => $tax, 'description' => 'ض.ق.م مخرجات'];
        }

        $body = json_encode([
            'type'       => 'JOURNAL_VOUCHER',
            'externalId' => 'WHMCS-INV-' . $invoiceId,
            'payload'    => [
                'date'        => $date,
                'description' => 'تحصيل فاتورة WHMCS #' . $invoiceId . ' - ' . $clientName,
                'reference'   => 'WHMCS-INV-' . $invoiceId,
                'lines'       => $lines,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(rtrim($cfg['base'], '/') . '/operations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cfg['key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            logActivity('ERP integration: invoice #' . $invoiceId . ' connection error: ' . $err);
            return;
        }
        logActivity('ERP integration: invoice #' . $invoiceId . ' -> JOURNAL_VOUCHER HTTP ' . $code . ' ' . substr((string) $res, 0, 240));
    } catch (\Throwable $e) {
        logActivity('ERP integration error (invoice #' . $invoiceId . '): ' . $e->getMessage());
    }
});
