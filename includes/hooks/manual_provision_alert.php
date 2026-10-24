<?php
/**
 * Salasel Babel — Manual provisioning alert.
 *
 * When a customer PAYS for a product that is set up for manual fulfilment
 * (Contabo module but with NO Contabo product code = not auto-provisioned),
 * alert the admin so the server can be set up by hand. Three channels:
 *   1) A To-Do item on the admin dashboard (persists until done).
 *   2) An admin notification email (System type).
 *   3) An entry in the Activity Log.
 *
 * Fires only for NEW services (status "Pending") so renewals do not re-alert.
 * Auto-provisioned products (those WITH a Contabo product code) are ignored.
 *
 * File: /var/www/whmcs/includes/hooks/manual_provision_alert.php
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('InvoicePaid', 1, function ($vars) {
    sb_manual_provision_alert((int) ($vars['invoiceid'] ?? 0));
});

/**
 * Core logic, named so it can be invoked directly (e.g. for a safe test that
 * does not involve any payment gateway or charge).
 */
if (!function_exists('sb_manual_provision_alert')) {
function sb_manual_provision_alert($invoiceId)
{
    $invoiceId = (int) $invoiceId;
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->get();
    } catch (\Throwable $e) {
        logActivity('Manual-provision alert: could not read invoice items for #' . $invoiceId . ': ' . $e->getMessage());
        return;
    }

    foreach ($items as $it) {
        $serviceId = (int) $it->relid;
        if ($serviceId <= 0) {
            continue;
        }

        $svc = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$svc) {
            continue;
        }
        // Only new services awaiting setup — skip renewals of already-active ones.
        if ($svc->domainstatus !== 'Pending') {
            continue;
        }

        $prod = Capsule::table('tblproducts')->where('id', $svc->packageid)->first();
        if (!$prod) {
            continue;
        }
        // Manual = handled by the Contabo module but with no product code set.
        if ($prod->servertype !== 'contabo') {
            continue;
        }
        if (trim((string) $prod->configoption1) !== '') {
            continue; // has a Contabo product code => auto-provisioned, no manual action needed
        }

        // De-dupe: if a to-do for this service already exists (not completed), skip.
        $already = Capsule::table('tbltodolist')
            ->where('title', 'like', '%#' . $serviceId . ')%')
            ->where('status', '!=', 'Completed')
            ->exists();
        if ($already) {
            continue;
        }

        $client = Capsule::table('tblclients')->where('id', $svc->userid)->first();
        $clientName = $client
            ? trim($client->firstname . ' ' . $client->lastname) . ' <' . $client->email . '>'
            : ('#' . $svc->userid);
        $label = $svc->domain ?: '-';
        $title = 'تجهيز يدوي: ' . $prod->name . ' (خدمة #' . $serviceId . ')';
        $body =
            'دفع العميل خدمةً تحتاج تجهيزًا يدويًّا على كنتابو — جهّز الخادم ثم فعّل الخدمة.' . "\n\n" .
            'المنتج: ' . $prod->name . "\n" .
            'رقم الخدمة: #' . $serviceId . "\n" .
            'العميل: ' . $clientName . "\n" .
            'الاسم/الدومين: ' . $label . "\n" .
            'الفاتورة: #' . $invoiceId . "\n\n" .
            'لوحة الإدارة: https://my.salaselbabel.com/  (العملاء ▸ الخدمة #' . $serviceId . ')';

        $today = date('Y-m-d');

        // 1) To-Do item on the admin dashboard.
        try {
            Capsule::table('tbltodolist')->insert([
                'date'        => $today,
                'title'       => $title,
                'description' => $body,
                'admin'       => 1,
                'status'      => 'New',
                'duedate'     => $today,
            ]);
        } catch (\Throwable $e) {
            logActivity('Manual-provision alert: to-do insert failed for svc #' . $serviceId . ': ' . $e->getMessage());
        }

        // 2) Admin notification email (System type).
        try {
            localAPI('SendAdminEmail', [
                'customsubject' => $title,
                'custommessage' => nl2br($body),
                'type'          => 'system',
            ]);
        } catch (\Throwable $e) {
            logActivity('Manual-provision alert: admin email failed for svc #' . $serviceId . ': ' . $e->getMessage());
        }

        // 3) Activity log trail.
        logActivity('تنبيه تجهيز يدوي: خدمة #' . $serviceId . ' (' . $prod->name . ') — بانتظار التجهيز اليدوي بعد الدفع.');

        // 4) Phone push via Telegram — only if configured (token + chat_id).
        //    Config file holds the secret and lives on the server only:
        //    /var/www/whmcs/includes/hooks/telegram-config.php  ->  return ['token'=>'...','chat_id'=>'...'];
        try {
            $tgCfgFile = __DIR__ . '/telegram-config.php';
            if (is_file($tgCfgFile)) {
                $tg = include $tgCfgFile;
                if (is_array($tg) && !empty($tg['token']) && !empty($tg['chat_id'])) {
                    $tgText = "🖥️ تجهيز يدوي مطلوب\n\n" . $body;
                    $ch = curl_init('https://api.telegram.org/bot' . $tg['token'] . '/sendMessage');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query([
                            'chat_id' => $tg['chat_id'],
                            'text' => $tgText,
                            'disable_web_page_preview' => 'true',
                        ]),
                        CURLOPT_TIMEOUT => 20,
                    ]);
                    $tgRes = curl_exec($ch);
                    $tgCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($tgCode !== 200) {
                        logActivity('Telegram alert HTTP ' . $tgCode . ' for svc #' . $serviceId . ': ' . substr((string) $tgRes, 0, 200));
                    }
                }
            }
        } catch (\Throwable $e) {
            logActivity('Telegram alert failed for svc #' . $serviceId . ': ' . $e->getMessage());
        }
    }
}
} // end if(!function_exists)
