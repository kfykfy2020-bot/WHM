<?php
/**
 * Salasel Babel — «المحاسب الذكي» ticket assist (ERB → WHMCS support portal).
 *
 * When a client of «سلاسل بابل المحاسبي ERB» asks the Smart Accountant for a
 * feature that does not exist, the ERB writes a full development request
 * (draft) and opens this portal in a NEW TAB at:
 *
 *     submitticket.php?erb_draft=<one-time token, valid 15 minutes>
 *
 * This hook then:
 *   1. keeps the token in the WHMCS session through WHMCS's own login /
 *      registration flow (nothing is injected on login, registration, 2FA,
 *      password-reset or captcha pages — the client signs in by himself);
 *   2. once the client is logged in and on the ticket submission page,
 *      fetches the draft server-to-server from the ERB (HMAC-SHA256 signed,
 *      TLS verified, short timeout). The ERB returns the draft ONCE;
 *   3. injects a same-origin script that shows a visible branded cursor,
 *      moves to and selects the department whose name contains
 *      «Developing Support» (the real department id is read from the page,
 *      never assumed), fills the subject and the message, and stops on the
 *      submit button with the tooltip «راجع ثم اضغط إرسال». It NEVER clicks
 *      submit — the client reviews and submits;
 *   4. on TicketOpen, posts the ticket id / number / URL back to the ERB
 *      (signed), so the ERB conversation shows the confirmed ticket.
 *
 * Secrets live ONLY on the server in /opt/erb-integration/ticket-assist.config.php
 * (chmod 600, owned by the web server user) — never in this repository.
 * See opt/erb-integration/ticket-assist.config.php.example and README.md.
 *
 * Signature (must match ERB apps/api/src/services/assistant/support.js):
 *   X-ERB-Timestamp: unix seconds   X-ERB-Nonce: random hex   X-ERB-Signature:
 *   hex(HMAC-SHA256(secret, ts "\n" nonce "\n" METHOD "\n" urlPath "\n" sha256(body)))
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Database\Capsule;

const SALASEL_ERB_CONFIG = '/opt/erb-integration/ticket-assist.config.php';
const SALASEL_ERB_TOKEN_RE = '/^[A-Za-z0-9_-]{20,100}$/';

/**
 * Configured ERB instances: [['name' => ..., 'erb_base' => 'https://.../api/public/assistant', 'secret' => ...], ...]
 * A single-instance config (erb_base + secret at the top level) is accepted too.
 */
function salasel_erb_ticket_config()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = false;
    $raw = @include SALASEL_ERB_CONFIG;
    if (!is_array($raw)) {
        return $cfg;
    }
    $list = isset($raw['instances']) && is_array($raw['instances']) ? $raw['instances'] : [$raw];
    $instances = [];
    foreach (array_slice($list, 0, 10) as $i => $inst) {
        $base = isset($inst['erb_base']) ? rtrim((string) $inst['erb_base'], '/') : '';
        $secret = isset($inst['secret']) ? (string) $inst['secret'] : '';
        if (strpos($base, 'https://') !== 0 || strlen($secret) < 32) {
            continue;
        }
        $instances[] = ['name' => isset($inst['name']) ? (string) $inst['name'] : ('erb' . $i), 'base' => $base, 'secret' => $secret];
    }
    if (!$instances) {
        return $cfg;
    }
    $cfg = [
        'instances' => $instances,
        'department' => isset($raw['department']) && $raw['department'] !== '' ? (string) $raw['department'] : 'Developing Support',
    ];
    return $cfg;
}

/** Signed HTTPS request to one ERB instance. Returns [httpCode, decodedJson|null]. */
function salasel_erb_signed_request(array $inst, $method, $suffix, $body = null)
{
    $url = $inst['base'] . $suffix;
    $path = (string) parse_url($url, PHP_URL_PATH);
    $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $raw), $inst['secret']);
    $headers = [
        'Accept: application/json',
        'X-ERB-Timestamp: ' . $ts,
        'X-ERB-Nonce: ' . $nonce,
        'X-ERB-Signature: ' . $sig,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $raw;
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || strlen($res) > 200000) {
        return [0, null];
    }
    $json = json_decode($res, true);
    return [$code, is_array($json) ? $json : null];
}

/** Fetch the draft once (tries each configured ERB; a token belongs to exactly one). */
function salasel_erb_fetch_draft($token)
{
    $cfg = salasel_erb_ticket_config();
    if (!$cfg) {
        return null;
    }
    foreach ($cfg['instances'] as $idx => $inst) {
        list($code, $json) = salasel_erb_signed_request($inst, 'GET', '/ticket-draft/' . rawurlencode($token));
        if ($code === 200 && isset($json['draft']) && is_array($json['draft'])) {
            $d = $json['draft'];
            return [
                'instance' => $idx,
                'ref' => substr((string) ($d['ref'] ?? ''), 0, 40),
                'subject' => mb_substr((string) ($d['subject'] ?? ''), 0, 200),
                'message' => mb_substr((string) ($d['message'] ?? ''), 0, 20000),
                'department' => mb_substr((string) ($d['department'] ?? $cfg['department']), 0, 80),
            ];
        }
    }
    return null;
}

function salasel_erb_logged_in($vars)
{
    return !empty($vars['loggedin']) || !empty($_SESSION['uid']);
}

/** Pages on which nothing is ever injected or redirected (the client authenticates by himself). */
function salasel_erb_auth_page($vars)
{
    $file = strtolower((string) ($vars['filename'] ?? ''));
    $tpl = strtolower((string) ($vars['templatefile'] ?? ''));
    foreach (['login', 'register', 'pwreset', 'password-reset', 'two-factor', 'twofa', 'logout', 'captcha', 'user-verify', 'verify'] as $needle) {
        if (strpos($file, $needle) !== false || strpos($tpl, $needle) !== false) {
            return true;
        }
    }
    return false;
}

// Per-request flag: the footer injects only when this request is the logged-in ticket page with a draft
$GLOBALS['salasel_erb_inject'] = null;

add_hook('ClientAreaPage', 1, function ($vars) {
    if (!salasel_erb_ticket_config() || salasel_erb_auth_page($vars)) {
        return;
    }
    $file = strtolower((string) ($vars['filename'] ?? ''));

    // 1) a new token from the ERB link: keep it in the session (through login / registration)
    if ($file === 'submitticket' && isset($_GET['erb_draft']) && is_string($_GET['erb_draft']) && preg_match(SALASEL_ERB_TOKEN_RE, $_GET['erb_draft'])) {
        $_SESSION['salasel_erb_pending'] = ['token' => $_GET['erb_draft'], 'at' => time()];
        if (!salasel_erb_logged_in($vars)) {
            // WHMCS's own login flow, then back to the ticket page (the token stays in the session)
            $_SESSION['loginurlredirect'] = 'submitticket.php';
            header('Location: clientarea.php', true, 302);
            exit;
        }
    }
    $pending = isset($_SESSION['salasel_erb_pending']) && is_array($_SESSION['salasel_erb_pending']) ? $_SESSION['salasel_erb_pending'] : null;
    if ($pending && time() - (int) $pending['at'] > 15 * 60) {
        unset($_SESSION['salasel_erb_pending']);
        $pending = null;
    }
    if (!salasel_erb_logged_in($vars)) {
        return;
    }
    // 2) after registration WHMCS lands on the client area home: continue to the ticket page once
    if ($pending && $file === 'clientarea' && empty($_GET['action']) && empty($_SESSION['salasel_erb_bounced'])) {
        $_SESSION['salasel_erb_bounced'] = 1;
        header('Location: submitticket.php', true, 302);
        exit;
    }
    if ($file !== 'submitticket') {
        return;
    }
    // 3) fetch the draft once (server-to-server, signed); later page loads of the flow use the session copy
    $draft = isset($_SESSION['salasel_erb_draft']) && is_array($_SESSION['salasel_erb_draft']) ? $_SESSION['salasel_erb_draft'] : null;
    if ($pending) {
        unset($_SESSION['salasel_erb_pending'], $_SESSION['salasel_erb_bounced']);
        $fetched = salasel_erb_fetch_draft($pending['token']);
        if ($fetched) {
            $draft = $fetched + ['at' => time()];
            $_SESSION['salasel_erb_draft'] = $draft;
        } else {
            $draft = null;
            unset($_SESSION['salasel_erb_draft']);
            $GLOBALS['salasel_erb_inject'] = ['error' => 'unavailable'];
            logActivity('ERB ticket assist: draft unavailable (expired, already used, or not configured)');
            return;
        }
    }
    if ($draft && time() - (int) $draft['at'] > 2 * 3600) {
        unset($_SESSION['salasel_erb_draft']);
        $draft = null;
    }
    if ($draft) {
        $GLOBALS['salasel_erb_inject'] = ['draft' => $draft];
    }
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $inject = $GLOBALS['salasel_erb_inject'] ?? null;
    if (!$inject || salasel_erb_auth_page($vars) || !salasel_erb_logged_in($vars)) {
        return '';
    }
    // only the fields the script needs; JSON-encoded with every HTML-significant character escaped
    $payload = isset($inject['draft'])
        ? ['subject' => $inject['draft']['subject'], 'message' => $inject['draft']['message'], 'department' => $inject['draft']['department']]
        : ['error' => 'unavailable'];
    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '';
    }
    return '<script>window.SALASEL_ERB_DRAFT=' . $json . ';</script>' . salasel_erb_ticket_script();
});

add_hook('TicketOpen', 1, function ($vars) {
    try {
        $cfg = salasel_erb_ticket_config();
        $draft = isset($_SESSION['salasel_erb_draft']) && is_array($_SESSION['salasel_erb_draft']) ? $_SESSION['salasel_erb_draft'] : null;
        if (!$cfg || !$draft || empty($draft['ref'])) {
            return;
        }
        // the ticket the client just submitted from the prepared draft (subject may be edited: the session and the
        // development department tie it); a ticket to another department is not reported
        if (stripos((string) ($vars['deptname'] ?? ''), (string) $draft['department']) === false) {
            return;
        }
        $ticketId = (int) ($vars['ticketid'] ?? 0);
        $tid = (string) ($vars['ticketmask'] ?? $vars['ticketid'] ?? '');
        if ($ticketId <= 0 || $tid === '') {
            return;
        }
        $c = Capsule::table('tbltickets')->where('id', $ticketId)->value('c');
        $base = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
        $url = $base . '/viewticket.php?tid=' . rawurlencode($tid) . ($c ? '&c=' . rawurlencode((string) $c) : '');
        $inst = $cfg['instances'][(int) $draft['instance']] ?? null;
        if (!$inst) {
            return;
        }
        unset($_SESSION['salasel_erb_draft']);
        list($code) = salasel_erb_signed_request($inst, 'POST', '/ticket-callback', ['ref' => $draft['ref'], 'ticketid' => $ticketId, 'tid' => $tid, 'url' => $url]);
        logActivity('ERB ticket assist: ticket #' . $tid . ' reported to ERB (' . $inst['name'] . ') HTTP ' . $code);
    } catch (\Throwable $e) {
        logActivity('ERB ticket assist error: ' . substr($e->getMessage(), 0, 200));
    }
});

/** The visible cursor (same origin, no external resources). Draft text is only ever set via .value / .textContent. */
function salasel_erb_ticket_script()
{
    return <<<'HTML'
<style>
#sbErbCursor{position:fixed;z-index:2147483600;left:0;top:0;width:26px;height:30px;pointer-events:none;transition:transform .55s cubic-bezier(.22,.8,.3,1);filter:drop-shadow(0 3px 6px rgba(11,42,91,.35))}
#sbErbTip{position:fixed;z-index:2147483601;max-width:280px;padding:8px 12px;border-radius:12px;background:#0b2a5b;color:#fff;font-size:13px;line-height:1.5;direction:rtl;pointer-events:none;box-shadow:0 8px 22px rgba(11,42,91,.3);transition:transform .55s cubic-bezier(.22,.8,.3,1),opacity .2s}
#sbErbTip b{display:block;font-size:11px;color:#7fdcf5;margin-bottom:2px}
.sb-erb-ring{outline:3px solid #0fb2de !important;outline-offset:3px;border-radius:6px;transition:outline-color .3s}
.sb-erb-pulse{animation:sbErbPulse 1.1s infinite}
@keyframes sbErbPulse{50%{outline-color:rgba(15,178,222,.25)}}
#sbErbNote{margin:12px 0;padding:12px 14px;border-radius:10px;border:1px solid #f3c969;background:#fffaf0;color:#5b4300;direction:rtl;font-size:14px}
</style>
<script>
(function () {
  var data = window.SALASEL_ERB_DRAFT || {};
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var cursor, tip;
  function note(text) {
    var host = document.querySelector('.card-body') || document.querySelector('main') || document.body;
    var el = document.getElementById('sbErbNote') || document.createElement('div');
    el.id = 'sbErbNote';
    el.textContent = text;
    if (!el.parentNode) host.insertBefore(el, host.firstChild);
  }
  function ensure() {
    if (cursor) return;
    cursor = document.createElement('div');
    cursor.id = 'sbErbCursor';
    cursor.innerHTML = '<svg width="26" height="30" viewBox="0 0 26 30"><path d="M2 2 L2 24 L8 18 L12 28 L16 26 L12 17 L21 17 Z" fill="#0fb2de" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>';
    tip = document.createElement('div');
    tip.id = 'sbErbTip';
    document.body.appendChild(cursor);
    document.body.appendChild(tip);
  }
  async function moveTo(el, text) {
    ensure();
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    await sleep(350);
    var r = el.getBoundingClientRect();
    var x = Math.round(r.right - Math.min(30, r.width / 2));
    var y = Math.round(r.top + r.height * 0.6);
    cursor.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    tip.innerHTML = '<b>المحاسب الذكي — سلاسل بابل</b>';
    tip.appendChild(document.createTextNode(text));
    var tx = Math.max(8, Math.min(window.innerWidth - 290, x - 270));
    tip.style.transform = 'translate(' + tx + 'px,' + (y + 26) + 'px)';
    await sleep(600);
  }
  function ring(el, on) { if (el) el.classList[on ? 'add' : 'remove']('sb-erb-ring'); }
  var matches = function (text) { return String(text || '').toLowerCase().indexOf(String(data.department || '').toLowerCase()) !== -1; };
  function setValue(el, value) {
    var proto = Object.getPrototypeOf(el);
    var desc = Object.getOwnPropertyDescriptor(proto, 'value');
    if (desc && desc.set) desc.set.call(el, value); else el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
  async function typeInto(el, value) {
    el.focus({ preventScroll: true });
    if (value.length <= 120) {
      for (var k = 1; k <= value.length; k++) { setValue(el, value.slice(0, k)); await sleep(18); }
    } else setValue(el, value);
  }
  async function run() {
    if (data.error) {
      note('انتهت صلاحية طلب التطوير المجهّز أو استُعمل من قبل. ارجع إلى «المحاسب الذكي» في النظام واضغط «فتح في بوابة الدعم» من جديد.');
      return;
    }
    var select = document.querySelector('select[name="deptid"]');
    var subject = document.querySelector('input[name="subject"]');
    var message = document.querySelector('textarea[name="message"]');
    // step one: choose the department by its real id from the page
    if (!select) {
      var links = Array.prototype.slice.call(document.querySelectorAll('a[href*="deptid="]'));
      var link = links.filter(function (a) { return matches(a.textContent); })[0];
      if (!link) { note('لم يُعثر في البوابة على قسم «' + data.department + '». اختر القسم المناسب بنفسك؛ نص الطلب محفوظ وسيُعبّأ في الخطوة التالية.'); return; }
      await moveTo(link, 'أختار قسم ' + data.department + '…');
      var id = new URL(link.href, location.href).searchParams.get('deptid');
      if (!/^\d+$/.test(String(id))) return;
      location.href = 'submitticket.php?step=2&deptid=' + encodeURIComponent(id);
      return;
    }
    var option = Array.prototype.slice.call(select.options).filter(function (o) { return matches(o.textContent); })[0];
    if (!option) { note('لم يُعثر على قسم «' + data.department + '» في قائمة الأقسام؛ لم يُختر قسم آخر. اختر القسم بنفسك، والعنوان والنص معبّآن.'); }
    else {
      await moveTo(select, 'أختار قسم ' + data.department + '…');
      ring(select, true);
      if (select.value !== option.value) setValue(select, option.value);
      await sleep(400);
      ring(select, false);
    }
    if (subject) { await moveTo(subject, 'أكتب عنوان الطلب…'); ring(subject, true); await typeInto(subject, data.subject || ''); ring(subject, false); }
    if (message) { await moveTo(message, 'أكتب نص الطلب كاملاً…'); ring(message, true); await typeInto(message, data.message || ''); ring(message, false); }
    var submit = document.getElementById('openTicketSubmit') || (message && message.form && message.form.querySelector('[type="submit"]'));
    if (submit) {
      await moveTo(submit, 'راجع ثم اضغط إرسال');
      submit.classList.add('sb-erb-ring', 'sb-erb-pulse');
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 500); });
  else setTimeout(run, 500);
})();
</script>
HTML;
}
