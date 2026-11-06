<?php
/**
 * Email OTP — WHMCS Two-Factor Authentication provider
 * ----------------------------------------------------
 * Sends a one-time numeric code to the user's registered email address as a
 * second step at login (client area and/or admin area).
 *
 * Security properties:
 *   - Cryptographically-secure code (random_int), 6 digits.
 *   - Stored HASHED (sha256 + server pepper), never in plaintext.
 *   - Short expiry (EMAILOTP_TTL), single-use (cleared on success).
 *   - Per-send throttle (EMAILOTP_RESEND_WAIT) + attempt lockout (EMAILOTP_MAX_ATTEMPTS).
 *   - Constant-time comparison (hash_equals).
 *
 * Built in-house for Salasel Babel (salaselbabel.com). No external services.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('EMAILOTP_LENGTH'))       define('EMAILOTP_LENGTH', 4);        // digits
if (!defined('EMAILOTP_TTL'))          define('EMAILOTP_TTL', 900);         // code lifetime (seconds) = 15 min
if (!defined('EMAILOTP_RESEND_WAIT'))  define('EMAILOTP_RESEND_WAIT', 60);  // min seconds between code emails
if (!defined('EMAILOTP_MAX_ATTEMPTS')) define('EMAILOTP_MAX_ATTEMPTS', 5);  // wrong tries before the code is burned

/* ------------------------------------------------------------------ */
/*  WHMCS 2FA provider hooks                                          */
/* ------------------------------------------------------------------ */

function emailotp_config()
{
    return array(
        'FriendlyName'     => array('Type' => 'System', 'Value' => 'Email OTP'),
        'ShortDescription' => array('Type' => 'System', 'Value' => 'رمز تحقق لمرة واحدة يُرسل إلى بريدك الإلكتروني'),
        'Description'      => array('Type' => 'System', 'Value' => 'يُرسل رمز تحقق إلى بريد المستخدم عند تسجيل الدخول كخطوة تحقق ثانية. · Sends a one-time verification code to the user\'s email as a second login step.'),
    );
}

/** Shown when a user turns Email OTP on in their Security Settings. */
function emailotp_activate($params)
{
    $email  = emailotp_email($params);
    $masked = $email ? emailotp_mask($email) : '';
    $note   = $masked
        ? "سنرسل رموز الدخول إلى: <strong dir=\"ltr\">{$masked}</strong><br>We'll send your login codes to: <strong>{$masked}</strong>"
        : "سنرسل رموز الدخول إلى بريدك المسجّل. · We'll email your login codes to your account email.";

    return '
    <div style="line-height:1.9">
      <p>' . $note . '</p>
      <p style="color:#666;font-size:13px">عند كل تسجيل دخول يصلك رمز مؤقت عبر البريد لإكمال الدخول.<br>
      At each login you\'ll receive a one-time code by email to finish signing in.</p>
      <input type="hidden" name="emailotp_confirm" value="1">
      <input type="submit" value="تفعيل · Enable" class="btn btn-primary">
    </div>';
}

/** Called after the activation form is submitted. Nothing secret to store. */
function emailotp_activateverify($params)
{
    return array(
        'success'  => true,
        'msg'      => 'تم تفعيل التحقق عبر البريد. · Email verification enabled.',
        'settings' => array('method' => 'email'),
    );
}

/** Render the "enter the code" step and (throttled) email a fresh code. */
function emailotp_challenge($params)
{
    emailotp_ensureTable();
    $uid = emailotp_userId($params);
    $now = time();

    $row      = Capsule::table('mod_emailotp')->where('user_id', $uid)->first();
    $recently = $row && (int) $row->last_sent_at > 0 && ($now - (int) $row->last_sent_at) < EMAILOTP_RESEND_WAIT;

    if (!$recently) {
        $code  = emailotp_generate();
        $email = emailotp_email($params);
        $sent  = false;
        if ($email !== '') {
            $sent = emailotp_send($email, $code);
        }
        Capsule::table('mod_emailotp')->updateOrInsert(
            array('user_id' => $uid),
            array(
                'code_hash'    => emailotp_hash($code),
                'expires_at'   => $now + EMAILOTP_TTL,
                'attempts'     => 0,
                'last_sent_at' => $now,
            )
        );
        logModuleCall('emailotp', 'challenge', 'uid=' . $uid, ($email ? 'to ' . emailotp_mask($email) : 'NO EMAIL') . ' sent=' . ($sent ? '1' : '0'));
    }

    $email  = emailotp_email($params);
    $masked = $email ? emailotp_mask($email) : '';
    $mins   = (int) round(EMAILOTP_TTL / 60);

    return '
    <form action="dologin.php" method="post" autocomplete="off">
      <div style="text-align:center">
        <h4 style="margin-bottom:4px">أدخل الرمز المُرسَل إلى بريدك</h4>
        <div style="font-size:14px;color:#555;margin-bottom:10px">Enter the code sent to your email</div>
        ' . ($masked ? '<p style="color:#666" dir="ltr">' . $masked . '</p>' : '') . '
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="' . EMAILOTP_LENGTH . '" name="otp" required autofocus
               class="form-control text-center" style="letter-spacing:8px;font-size:22px;max-width:220px;margin:6px auto"
               placeholder="' . str_repeat('·', EMAILOTP_LENGTH) . '">
        <br>
        <button class="btn btn-primary" type="submit">تحقّق · Verify</button>
      </div>
    </form>
    <div style="text-align:center;margin-top:12px">
      <a href="javascript:location.reload()" style="font-size:13px">لم يصلك الرمز؟ إعادة الإرسال · Resend code</a>
      <div style="color:#999;font-size:12px;margin-top:4px">صالح لمدة ' . $mins . ' دقائق · valid for ' . $mins . ' min</div>
    </div>';
}

/** Validate the submitted code. Returns true on success. */
function emailotp_verify($params)
{
    emailotp_ensureTable();
    $uid   = emailotp_userId($params);
    $input = isset($params['post_vars']['otp']) ? preg_replace('/\D/', '', (string) $params['post_vars']['otp']) : '';
    $now   = time();

    $row = Capsule::table('mod_emailotp')->where('user_id', $uid)->first();
    if (!$row || empty($row->code_hash)) {
        return false;
    }
    if ((int) $row->expires_at < $now) {
        Capsule::table('mod_emailotp')->where('user_id', $uid)->update(array('code_hash' => null));
        return false;
    }
    if ((int) $row->attempts >= EMAILOTP_MAX_ATTEMPTS) {
        return false;
    }

    $ok = ($input !== '') && hash_equals((string) $row->code_hash, emailotp_hash($input));
    if ($ok) {
        Capsule::table('mod_emailotp')->where('user_id', $uid)->update(array('code_hash' => null, 'attempts' => 0));
        return true;
    }

    Capsule::table('mod_emailotp')->where('user_id', $uid)->update(array('attempts' => (int) $row->attempts + 1));
    return false;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                           */
/* ------------------------------------------------------------------ */

function emailotp_ensureTable()
{
    Capsule::connection()->getPdo()->exec(
        'CREATE TABLE IF NOT EXISTS `mod_emailotp` (' .
        '`user_id` INT UNSIGNED NOT NULL PRIMARY KEY,' .
        '`code_hash` VARCHAR(64) NULL,' .
        '`expires_at` INT UNSIGNED NOT NULL DEFAULT 0,' .
        '`attempts` INT UNSIGNED NOT NULL DEFAULT 0,' .
        '`last_sent_at` INT UNSIGNED NOT NULL DEFAULT 0' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function emailotp_userId($params)
{
    if (isset($params['user_info']['id'])) return (int) $params['user_info']['id'];
    if (isset($params['userid']))          return (int) $params['userid'];
    if (isset($params['uid']))             return (int) $params['uid'];
    return 0;
}

/** Resolve the login email for a 2FA user id (WHMCS 9 Users table, legacy fallback). */
function emailotp_email($params)
{
    if (!empty($params['user_info']['email'])) return (string) $params['user_info']['email'];
    $uid = emailotp_userId($params);
    if ($uid <= 0) return '';
    try {
        $e = Capsule::table('tblusers')->where('id', $uid)->value('email');
        if (!empty($e)) return (string) $e;
    } catch (\Throwable $e) {
    }
    try {
        $e = Capsule::table('tblclients')->where('id', $uid)->value('email');
        if (!empty($e)) return (string) $e;
    } catch (\Throwable $e) {
    }
    return '';
}

function emailotp_generate()
{
    $min = (int) pow(10, EMAILOTP_LENGTH - 1);
    $max = (int) pow(10, EMAILOTP_LENGTH) - 1;
    return (string) random_int($min, $max);
}

function emailotp_hash($code)
{
    $pepper = '';
    try {
        $pepper = (string) Capsule::table('tblconfiguration')->where('setting', 'Hash')->value('value');
    } catch (\Throwable $e) {
    }
    return hash('sha256', $code . '|' . $pepper);
}

function emailotp_mask($email)
{
    $at = strpos($email, '@');
    if ($at === false) return $email;
    $name = substr($email, 0, $at);
    $dom  = substr($email, $at);
    $keep = max(1, min(2, strlen($name)));
    return substr($name, 0, $keep) . str_repeat('*', max(1, strlen($name) - $keep)) . $dom;
}

function emailotp_send($email, $code)
{
    $fromEmail = '';
    $fromName  = '';
    try {
        $fromEmail = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromEmail')->value('value');
        $fromName  = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromName')->value('value');
    } catch (\Throwable $e) {
    }
    if ($fromEmail === '') $fromEmail = 'noreply@salaselbabel.com';
    if ($fromName === '')  $fromName  = 'Salasel Babel';

    $mins    = (int) round(EMAILOTP_TTL / 60);
    $subject = 'رمز الدخول · Login code: ' . $code;
    $body    =
        '<div style="font-family:Tahoma,Arial,sans-serif;max-width:480px;margin:auto">' .
          '<div style="direction:rtl;text-align:right">' .
            '<p>رمز تسجيل الدخول الخاص بك:</p>' .
            '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:#102A6B">' . $code . '</p>' .
            '<p style="color:#555">صالح لمدة ' . $mins . ' دقائق. إذا لم تحاول تسجيل الدخول، تجاهل هذه الرسالة.</p>' .
          '</div>' .
          '<hr style="border:none;border-top:1px solid #eee;margin:16px 0">' .
          '<div style="direction:ltr;text-align:left">' .
            '<p>Your login verification code:</p>' .
            '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:#102A6B">' . $code . '</p>' .
            '<p style="color:#555">Valid for ' . $mins . ' minutes. If you didn\'t try to sign in, ignore this email.</p>' .
          '</div>' .
          '<p style="color:#999;font-size:12px;text-align:center;margin-top:16px">' . htmlspecialchars($fromName) . '</p>' .
        '</div>';

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= 'Auto-Submitted: auto-generated' . "\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($email, $encodedSubject, $body, $headers, '-f' . $fromEmail);
}
