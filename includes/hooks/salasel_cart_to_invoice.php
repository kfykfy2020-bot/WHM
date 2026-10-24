<?php
/**
 * Salasel Babel: after a cart order is placed, take the customer straight to
 * the generated invoice (Moyasar payment page) instead of stopping on the
 * "order received" confirmation page. WHMCS core (ionCube) does not honour
 * AutoRedirectoInvoice=invoice for this standard_cart checkout, so we redirect
 * on the complete page itself using the invoice link WHMCS already rendered.
 */
if (!defined('WHMCS')) {
    die('Access Denied');
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $action = strtolower((string) ($_GET['a'] ?? ''));
    if ($script !== 'cart.php' || $action !== 'complete') {
        return '';
    }
    // Redirect to the unpaid invoice (Moyasar card form) that the order created.
    return <<<'HTML'
<script>
(function () {
    try {
        var links = document.querySelectorAll('a[href*="viewinvoice.php?id="]');
        if (links && links.length) {
            window.location.replace(links[0].href);
        }
    } catch (e) {}
})();
</script>
HTML;
});
