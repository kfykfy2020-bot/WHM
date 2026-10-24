<?php
/**
 * Salasel Babel — client-area "link your domain to your server" (white-label).
 *
 * A clear multi-option card, each option explained for the client:
 *   (1) Domain registered WITH US  -> one click to LINK (set A-records to the
 *       server IP) or UNLINK (remove those A-records), via our registrar's DNS.
 *   (2) Domain registered ELSEWHERE -> show the server IP + simple A-record steps.
 *   (3) No domain yet               -> register one, then link it.
 *
 * Never creates any subdomain on our own domain (salaselbabel.com).
 */

if (!defined('WHMCS')) { die('Access Denied'); }

use WHMCS\Database\Capsule;

/* Shared: load the registrar module + current DNS records for a domain we manage.
 * Returns [configArray, currentRecords] or a string error message. */
if (!function_exists('salasel_domain_dns_context')) {
    function salasel_domain_dns_context($dom) {
        $registrar = strtolower(trim((string) $dom->registrar));
        if ($registrar !== 'namecheap') {
            return 'هذا النطاق غير مُدار لدينا تلقائياً — استخدم الخيار الثاني.';
        }
        $modfile = '/var/www/whmcs/modules/registrars/namecheap/namecheap.php';
        if (!file_exists($modfile)) {
            return 'إدارة النطاقات غير متاحة حالياً.';
        }
        require_once $modfile;
        if (!function_exists('namecheap_GetDNS') || !function_exists('namecheap_SaveDNS') || !function_exists('getregistrarconfigoptions')) {
            return 'إدارة DNS غير متاحة لهذا المزوّد.';
        }
        $cfg = getregistrarconfigoptions('namecheap');
        if (!is_array($cfg) || empty($cfg)) {
            return 'تعذّر تحميل إعدادات النطاقات.';
        }
        $parts = explode('.', $dom->domain, 2);
        $sld = $parts[0] ?? '';
        $tld = $parts[1] ?? '';
        if ($sld === '' || $tld === '') {
            return 'صيغة النطاق غير صالحة.';
        }
        $params = array_merge($cfg, [
            'sld'        => $sld,
            'tld'        => $tld,
            'domainid'   => (int) $dom->id,
            'domainname' => $dom->domain,
        ]);
        $cur = namecheap_GetDNS($params);
        if (!is_array($cur) || isset($cur['error'])) {
            return false; // could not read records -> abort, never overwrite blindly
        }
        return [$params, $cur];
    }
}

/* LINK: point @ and www to the server IP, preserving every other record. */
if (!function_exists('salasel_point_domain_to_ip')) {
    function salasel_point_domain_to_ip($dom, $ip) {
        $ctx = salasel_domain_dns_context($dom);
        if (!is_array($ctx)) { return $ctx; } // string error or false
        list($params, $cur) = $ctx;

        $records = [];
        foreach ($cur as $rec) {
            if (!is_array($rec)) { continue; }
            $hn = strtolower(trim((string) ($rec['hostname'] ?? '')));
            $ty = strtoupper(trim((string) ($rec['type'] ?? '')));
            if (($hn === '@' || $hn === '' || $hn === 'www') && $ty === 'A') {
                continue; // drop old @ / www A
            }
            $records[] = $rec;
        }
        $records[] = ['hostname' => '@',   'type' => 'A', 'address' => $ip, 'priority' => ''];
        $records[] = ['hostname' => 'www', 'type' => 'A', 'address' => $ip, 'priority' => ''];

        $params['dnsrecords'] = $records;
        $res = namecheap_SaveDNS($params);
        if (is_array($res) && !empty($res['error'])) { return false; }
        return true;
    }
}

/* UNLINK: remove the @ and www A-records that point to THIS server's IP,
 * leaving all other records untouched. */
if (!function_exists('salasel_unlink_domain_from_ip')) {
    function salasel_unlink_domain_from_ip($dom, $ip) {
        $ctx = salasel_domain_dns_context($dom);
        if (!is_array($ctx)) { return $ctx; }
        list($params, $cur) = $ctx;

        $records = [];
        $removed = false;
        foreach ($cur as $rec) {
            if (!is_array($rec)) { continue; }
            $hn = strtolower(trim((string) ($rec['hostname'] ?? '')));
            $ty = strtoupper(trim((string) ($rec['type'] ?? '')));
            $addr = trim((string) ($rec['address'] ?? ''));
            if (($hn === '@' || $hn === '' || $hn === 'www') && $ty === 'A' && $addr === $ip) {
                $removed = true;
                continue; // remove our link
            }
            $records[] = $rec;
        }
        if (!$removed) {
            return 'هذا النطاق غير مربوط بهذا الخادم.';
        }
        $params['dnsrecords'] = $records;
        $res = namecheap_SaveDNS($params);
        if (is_array($res) && !empty($res['error'])) { return false; }
        return true;
    }
}

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    $sid = (int) ($_GET['id'] ?? 0);
    if ($sid <= 0) { return ''; }
    $uid = (int) ($_SESSION['uid'] ?? 0);

    $svc = Capsule::table('tblhosting')->where('id', $sid)->first();
    if (!$svc) { return ''; }
    if ($uid > 0 && (int) $svc->userid !== $uid) { return ''; }
    if ($uid <= 0) { $uid = (int) $svc->userid; }

    $ip = trim((string) $svc->dedicatedip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return ''; }
    $ipEsc = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

    $okMsg = '';
    $errMsg = '';

    // ---- Option 1: handle link / unlink submit ----
    if (!empty($_POST['salasel_link_domain_id'])) {
        $did = (int) $_POST['salasel_link_domain_id'];
        $action = (($_POST['salasel_action'] ?? 'link') === 'unlink') ? 'unlink' : 'link';
        $dom = Capsule::table('tbldomains')->where('id', $did)->where('userid', $uid)->first();
        if (!$dom) {
            $errMsg = 'النطاق غير مطابق لحسابك.';
        } else {
            $domEsc = htmlspecialchars($dom->domain, ENT_QUOTES, 'UTF-8');
            try {
                if ($action === 'unlink') {
                    $r = salasel_unlink_domain_from_ip($dom, $ip);
                    if ($r === true) {
                        logActivity('Salasel domain UNLINK: client #' . $uid . ' domain ' . $dom->domain . ' from svc #' . $sid . ' (' . $ip . ')');
                        $okMsg = 'تم إلغاء ربط <strong style="direction:ltr;display:inline-block">' . $domEsc . '</strong> عن هذا الخادم.';
                    } elseif (is_string($r)) {
                        $errMsg = $r;
                    } else {
                        $errMsg = 'تعذّر إلغاء الربط تلقائياً. <a href="submitticket.php">افتح تذكرة دعم</a>.';
                    }
                } else {
                    $r = salasel_point_domain_to_ip($dom, $ip);
                    if ($r === true) {
                        logActivity('Salasel domain LINK: client #' . $uid . ' domain ' . $dom->domain . ' -> svc #' . $sid . ' (' . $ip . ')');
                        $okMsg = 'تم توجيه <strong style="direction:ltr;display:inline-block">' . $domEsc . '</strong> إلى خادمك — يعمل خلال دقائق إلى ساعتين.';
                    } elseif (is_string($r)) {
                        $errMsg = $r;
                    } else {
                        $errMsg = 'تعذّر ربط النطاق تلقائياً. <a href="submitticket.php">افتح تذكرة دعم</a> ونربطه لك فوراً.';
                    }
                }
            } catch (\Throwable $e) {
                logActivity('Salasel domain ' . $action . ' error (' . $dom->domain . '): ' . $e->getMessage());
                $errMsg = 'حدث خطأ غير متوقّع. <a href="submitticket.php">افتح تذكرة دعم</a> ونساعدك فوراً.';
            }
        }
    }

    // ---- client's domains registered with us ----
    $domains = Capsule::table('tbldomains')
        ->where('userid', $uid)
        ->where('status', 'Active')
        ->orderBy('domain')
        ->get();

    $csrf = htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8');

    $okHtml = $okMsg !== ''
        ? '<div style="background:#e6f7ea;border:1px solid #9fdcb0;color:#176b33;border-radius:8px;padding:10px 14px;margin:8px 0">✅ ' . $okMsg . '</div>'
        : '';
    $errHtml = $errMsg !== ''
        ? '<div style="background:#fdecec;border:1px solid #f1b5b5;color:#9b1c1c;border-radius:8px;padding:10px 14px;margin:8px 0">⚠️ ' . $errMsg . '</div>'
        : '';

    // Option 1 inner
    if (count($domains) > 0) {
        $opts = '';
        foreach ($domains as $d) {
            $opts .= '<option value="' . (int) $d->id . '">' . htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $opt1Inner = '
      <p style="margin:0 0 8px;color:#445">اختر نطاقك واضغط <strong>«اربط»</strong> لنوجّهه إلى خادمك تلقائياً، أو <strong>«إلغاء الربط»</strong> لإزالة التوجيه متى شئت.</p>
      ' . $okHtml . $errHtml . '
      <form method="post" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="token" value="' . $csrf . '">
        <select name="salasel_link_domain_id" required style="padding:10px 12px;border:1px solid #cdd9e5;border-radius:8px;min-width:220px;direction:ltr;background:#fff">' . $opts . '</select>
        <button type="submit" name="salasel_action" value="link" style="background:#1B4585;color:#fff;border:0;border-radius:8px;padding:11px 22px;font-weight:700;cursor:pointer">اربط</button>
        <button type="submit" name="salasel_action" value="unlink" style="background:#fff;color:#1B4585;border:1px solid #1B4585;border-radius:8px;padding:11px 20px;font-weight:700;cursor:pointer">إلغاء الربط</button>
      </form>';
    } else {
        $opt1Inner = '
      <p style="margin:0;color:#667">لا يوجد لديك نطاق مسجّل عندنا بعد. سجّل نطاقاً من <strong>الخيار ③</strong> بالأسفل، ثم ارجع هنا واربطه بضغطة.</p>';
    }

    return '
<div class="card" dir="rtl" style="margin-top:22px;border:1px solid #d7e2ec;border-radius:10px;overflow:hidden">
  <div style="background:#1B4585;color:#fff;padding:12px 16px;font-weight:700;font-size:16px">🔗 اربط نطاقك بخادمك</div>
  <div style="padding:16px;line-height:1.9;color:#223">
    <p style="margin:0 0 14px;color:#445">لتجعل نطاقك يفتح موقعك على هذا الخادم، اختر حالتك من الخيارات التالية:</p>

    <div style="border:1px solid #e1e8f0;border-radius:10px;padding:14px;margin-bottom:14px;background:#fafcff">
      <div style="font-weight:700;color:#1B4585;margin-bottom:6px">① نطاقك مسجّل لدينا؟ — اربطه (أو ألغِ الربط) بضغطة</div>
      ' . $opt1Inner . '
    </div>

    <div style="border:1px solid #e1e8f0;border-radius:10px;padding:14px;margin-bottom:14px;background:#fafcff">
      <div style="font-weight:700;color:#1B4585;margin-bottom:6px">② نطاقك مسجّل عند جهة أخرى؟</div>
      <p style="margin:0 0 8px;color:#445">إن اشتريت نطاقك من مكان آخر، ادخل لوحة إدارته وأضف سجلّي <strong>A</strong> يشيران إلى عنوان خادمك:</p>
      <div style="background:#eef5ff;border:1px dashed #1B4585;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        عنوان خادمك (IP): <strong style="font-size:18px;direction:ltr;display:inline-block">' . $ipEsc . '</strong>
      </div>
      <table class="table table-bordered" style="background:#fff;text-align:center;margin:0 0 6px">
        <thead><tr style="background:#f3f6fa"><th>النوع</th><th>الاسم</th><th>القيمة</th></tr></thead>
        <tbody>
          <tr><td>A</td><td><code>@</code></td><td style="direction:ltr">' . $ipEsc . '</td></tr>
          <tr><td>A</td><td><code>www</code></td><td style="direction:ltr">' . $ipEsc . '</td></tr>
        </tbody>
      </table>
      <small style="color:#667">لا تعرف كيف؟ <a href="submitticket.php">افتح تذكرة دعم</a> ونربطه لك.</small>
    </div>

    <div style="border:1px solid #e1e8f0;border-radius:10px;padding:14px;background:#fafcff">
      <div style="font-weight:700;color:#1B4585;margin-bottom:6px">③ ما عندك نطاق بعد؟</div>
      <p style="margin:0 0 10px;color:#445">احجز نطاقاً جديداً باسمك (مثل <code>شركتك.com</code>)، ثم ارجع واربطه من الخيار ① بضغطة.</p>
      <a href="cart.php?a=add&amp;domain=register" style="display:inline-block;background:#1B4585;color:#fff;border-radius:8px;padding:10px 20px;font-weight:700;text-decoration:none">احجز نطاقاً جديداً</a>
    </div>
  </div>
</div>';
});
