<?php

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Config\Setting;

/**
 * Salasel Babel — redirect the WHMCS portal home page (index.php / site root)
 * straight to the client area.
 *
 * Only the portal home uses the "homepage" template, so friendly-URL pages
 * such as index.php?rp=/announcements, /cart.php, etc. are NOT affected and
 * keep working normally.
 */
add_hook('ClientAreaPage', 1, function ($vars) {
    if (($vars['templatefile'] ?? '') === 'homepage') {
        $base = '';
        try {
            $base = rtrim((string) Setting::getValue('SystemURL'), '/');
        } catch (\Throwable $e) {
            $base = '';
        }
        $target = ($base !== '' ? $base . '/clientarea.php' : 'clientarea.php');
        header('Location: ' . $target, true, 302);
        exit;
    }
});
