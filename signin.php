<?php
/**
 * Passwordless email-OTP sign-in for Salasel Babel WHMCS client area.
 * Rendered inside the active client-area template (six) so it matches the
 * panel design. Flow: email -> emailed one-time code -> verify -> SSO login.
 * Reuses the Email OTP module helpers (code gen / send / hash / table).
 */

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require __DIR__ . '/modules/security/emailotp/emailotp.php';

$systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?: '', '/');

$step        = 'email';
$error       = '';
$notice      = '';
$maskedEmail = '';

function pwless_find_user($email)
{
    return Capsule::table('tblusers')->where('email', $email)->first();
}
function pwless_client_for_user($uid)
{
    return Capsule::table('tblusers_clients')->where('auth_user_id', $uid)->orderBy('client_id')->value('client_id');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStep = isset($_POST['step']) ? $_POST['step'] : '';

    if ($postStep === 'email') {
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $user  = $email ? pwless_find_user($email) : null;
        if ($user) {
            $uid = (int) $user->id;
            emailotp_ensureTable();
            $now = time();
            $row = Capsule::table('mod_emailotp')->where('user_id', $uid)->first();
            if (!$row || ($now - (int) $row->last_sent_at) >= 60) {
                $code = emailotp_generate();
                emailotp_send($email, $code);
                Capsule::table('mod_emailotp')->updateOrInsert(
                    array('user_id' => $uid),
                    array('code_hash' => emailotp_hash($code), 'expires_at' => $now + EMAILOTP_TTL, 'attempts' => 0, 'last_sent_at' => $now)
                );
            }
            $_SESSION['pwless_uid']   = $uid;
            $_SESSION['pwless_email'] = $email;
            $step        = 'code';
            $maskedEmail = emailotp_mask($email);
            $notice      = 'أرسلنا رمز التحقق إلى بريدك. تحقّق من صندوق الوارد.';
        } else {
            $error = 'لم نجد حسابًا مسجّلًا بهذا البريد. تأكّد من بريدك.';
        }
    } elseif ($postStep === 'code') {
        $uid         = (int) (isset($_SESSION['pwless_uid']) ? $_SESSION['pwless_uid'] : 0);
        $email       = isset($_SESSION['pwless_email']) ? $_SESSION['pwless_email'] : '';
        $maskedEmail = $email ? emailotp_mask($email) : '';
        $input       = preg_replace('/\D/', '', isset($_POST['code']) ? $_POST['code'] : '');
        $now         = time();
        $row         = $uid ? Capsule::table('mod_emailotp')->where('user_id', $uid)->first() : null;
        $ok          = $row && !empty($row->code_hash) && (int) $row->expires_at >= $now
                       && (int) $row->attempts < 5 && $input !== ''
                       && hash_equals((string) $row->code_hash, emailotp_hash($input));
        if ($ok) {
            Capsule::table('mod_emailotp')->where('user_id', $uid)->update(array('code_hash' => null, 'attempts' => 0));
            $clientId = pwless_client_for_user($uid);
            if ($clientId) {
                $admin = Capsule::table('tbladmins')->where('disabled', 0)->orderBy('id')->value('username');
                $res   = localAPI('CreateSsoToken', array(
                    'client_id'         => $clientId,
                    'destination'       => 'sso:custom_redirect',
                    'sso_redirect_path' => '/clientarea.php',
                ), $admin);
                if ((isset($res['result']) ? $res['result'] : '') === 'success' && !empty($res['redirect_url'])) {
                    unset($_SESSION['pwless_uid'], $_SESSION['pwless_email']);
                    header('Location: ' . $res['redirect_url']);
                    exit;
                }
            }
            $error = 'تعذّر إتمام تسجيل الدخول. حاول مرة أخرى.';
            $step  = 'code';
        } else {
            if ($row) {
                Capsule::table('mod_emailotp')->where('user_id', $uid)->update(array('attempts' => (int) $row->attempts + 1));
            }
            $error = 'الرمز غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا.';
            $step  = 'code';
        }
    }
}

// Resend to the same email (GET ?resend=1), throttled server-side.
if ($step === 'email' && isset($_GET['resend']) && !empty($_SESSION['pwless_email'])) {
    $email = $_SESSION['pwless_email'];
    $uid   = (int) (isset($_SESSION['pwless_uid']) ? $_SESSION['pwless_uid'] : 0);
    if ($uid > 0) {
        emailotp_ensureTable();
        $now = time();
        $row = Capsule::table('mod_emailotp')->where('user_id', $uid)->first();
        if (!$row || ($now - (int) $row->last_sent_at) >= EMAILOTP_RESEND_WAIT) {
            $code = emailotp_generate();
            emailotp_send($email, $code);
            Capsule::table('mod_emailotp')->updateOrInsert(
                array('user_id' => $uid),
                array('code_hash' => emailotp_hash($code), 'expires_at' => $now + EMAILOTP_TTL, 'attempts' => 0, 'last_sent_at' => $now)
            );
        }
        $step        = 'code';
        $maskedEmail = emailotp_mask($email);
        $notice      = 'أعدنا إرسال الرمز إلى بريدك.';
    }
}

$ttlMin = (int) round(EMAILOTP_TTL / 60);

$ca = new ClientArea();
$ca->setPageTitle('تسجيل الدخول');
$ca->initPage();
$ca->assign('pwless_step', $step);
$ca->assign('pwless_error', $error);
$ca->assign('pwless_notice', $notice);
$ca->assign('pwless_masked', $maskedEmail);
$ca->assign('pwless_ttl', $ttlMin);
$ca->assign('pwless_len', EMAILOTP_LENGTH);
$ca->assign('pwless_resend', EMAILOTP_RESEND_WAIT);
$ca->assign('pwless_action', $systemUrl . '/signin.php');
$ca->setTemplate('signin');
$ca->output();
