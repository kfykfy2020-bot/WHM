<?php
/**
 * Redirect the WHMCS client-area login page to the passwordless email-OTP
 * sign-in (signin.php). Fallback to the native password login with ?native=1.
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

add_hook('ClientAreaPageLogin', 1, function ($vars) {
    if (isset($_GET['native'])) {
        return; // emergency fallback: native password login still reachable
    }
    $url = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?: '', '/') . '/signin.php';
    header('Location: ' . $url, true, 302);
    exit;
});
