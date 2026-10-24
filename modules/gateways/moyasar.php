<?php
/**
 * Moyasar Payment Gateway for WHMCS (custom, hosted card form).
 * Uses Moyasar Payment Form (moyasar.js) + server-side verification.
 * salaselbabel.com — Salasel Babel IT.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function moyasar_MetaData()
{
    return [
        'DisplayName' => 'مدى / بطاقة ائتمان / Apple Pay (Moyasar)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function moyasar_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Moyasar (مُيسّر)',
        ],
        'publishableKey' => [
            'FriendlyName' => 'Publishable API Key',
            'Type' => 'text',
            'Size' => '70',
            'Description' => 'pk_live_xxx — من لوحة Moyasar → API Keys',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret API Key',
            'Type' => 'password',
            'Size' => '70',
            'Description' => 'sk_live_xxx — من لوحة Moyasar → API Keys',
        ],
        'enableApplePay' => [
            'FriendlyName' => 'Apple Pay',
            'Type' => 'yesno',
            'Description' => 'تفعيل Apple Pay (يظهر على أجهزة Apple فقط، ويتطلب توثيق النطاق داخل Moyasar لإتمام الدفع)',
        ],
    ];
}

function moyasar_link($params)
{
    // Amount must be in the smallest currency unit (halalas): 10 SAR = 1000
    $amount = (int) round(((float) $params['amount']) * 100);
    $currency = $params['currency'];
    $invoiceId = (int) $params['invoiceid'];
    $systemUrl = rtrim($params['systemurl'], '/');

    $pubKey = json_encode((string) $params['publishableKey']);
    $callbackUrl = json_encode($systemUrl . '/modules/gateways/callback/moyasar.php?invoiceid=' . $invoiceId);
    $description = json_encode('Invoice #' . $invoiceId . ' - ' . $params['companyname'], JSON_UNESCAPED_UNICODE);

    $applePay = !empty($params['enableApplePay']);
    $methods = $applePay ? "['creditcard', 'applepay']" : "['creditcard']";

    // Apple Pay REQUIRES label/country/validate_merchant_url, otherwise moyasar.js
    // refuses to render the ENTIRE form (including the card fields). This is what
    // broke checkout: applepay was listed in methods without this block.
    $applePayBlock = '';
    if ($applePay) {
        $label = json_encode((string) $params['companyname'], JSON_UNESCAPED_UNICODE);
        $applePayBlock = '
      apple_pay: {
        country: "SA",
        label: ' . $label . ',
        validate_merchant_url: "https://api.moyasar.com/v1/applepay/initiate"
      },';
    }

    return '
<div style="max-width:520px;margin:14px auto;text-align:right;">
  <link rel="stylesheet" href="https://cdn.moyasar.com/mpf/1.15.0/moyasar.css" />
  <div class="mysr-form"></div>
  <script src="https://cdn.moyasar.com/mpf/1.15.0/moyasar.js"></script>
  <script>
    Moyasar.init({
      element: ".mysr-form",
      amount: ' . $amount . ',
      currency: "' . $currency . '",
      description: ' . $description . ',
      publishable_api_key: ' . $pubKey . ',
      callback_url: ' . $callbackUrl . ',
      supported_networks: ["visa", "mastercard", "mada", "unionpay"],' . $applePayBlock . '
      methods: ' . $methods . '
    });
  </script>
</div>';
}
