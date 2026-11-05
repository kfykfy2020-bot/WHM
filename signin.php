<?php
/**
 * Passwordless email-OTP sign-in for Salasel Babel WHMCS client area.
 * Flow: enter email -> emailed one-time code -> verify -> SSO login (no password).
 * Reuses the Email OTP module helpers (code gen / send / hash / table).
 */

define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require __DIR__ . '/modules/security/emailotp/emailotp.php';

use WHMCS\Database\Capsule;

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

$ttlMin = (int) round(EMAILOTP_TTL / 60);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — مؤسسة سلاسل بابل لتقنية المعلومات</title>
<meta name="robots" content="noindex,nofollow">
<style>
  :root{--brand:#102A6B;--brand2:#1d4ed8;--ink:#243b53;--muted:#627d98;--line:#d9e2ec;--bg:#eef2f7}
  *{box-sizing:border-box}
  body{font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;background:linear-gradient(160deg,#f4f7fb,#e6ecf5);margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:var(--ink)}
  .card{width:100%;max-width:420px;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 50px rgba(16,42,107,.12);padding:30px 26px;text-align:center}
  .logo{height:54px;width:auto;margin:0 auto 14px;display:block;object-fit:contain}
  h1{font-size:20px;margin:0 0 6px;color:var(--brand)}
  .lead{color:var(--muted);font-size:14px;margin:0 0 22px;line-height:1.8}
  label{display:block;text-align:right;font-weight:700;font-size:14px;margin:0 0 6px}
  input[type=email],input[type=text]{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:11px;font-size:16px;font-family:inherit;outline:none;transition:border .15s,box-shadow .15s}
  input:focus{border-color:var(--brand2);box-shadow:0 0 0 3px rgba(29,78,216,.12)}
  .code-input{text-align:center;letter-spacing:10px;font-size:24px;font-weight:700}
  .btn{width:100%;margin-top:18px;padding:13px;border:0;border-radius:11px;background:var(--brand);color:#fff;font-size:16px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s}
  .btn:hover{background:#0b1f52}
  .msg{border-radius:11px;padding:11px 13px;font-size:13.5px;margin-bottom:16px;line-height:1.7}
  .msg.err{background:#fff0f1;color:#b4232d;border:1px solid #f3c9cd}
  .msg.ok{background:#eef8f0;color:#1a7a3a;border:1px solid #c7e7cf}
  .sub{color:var(--muted);font-size:13px;margin-top:14px}
  .sub a{color:var(--brand2);text-decoration:none}
  .masked{direction:ltr;font-weight:700;color:var(--ink)}
  .foot{margin-top:22px;color:#9fb0c3;font-size:12px}
</style>
</head>
<body>
  <form class="card" method="post" action="<?php echo htmlspecialchars($systemUrl); ?>/signin.php" autocomplete="off">
    <img class="logo" src="<?php echo htmlspecialchars($systemUrl); ?>/assets/img/logo.png" alt="مؤسسة سلاسل بابل لتقنية المعلومات">

    <?php if ($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="msg ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <?php if ($step === 'email'): ?>
      <h1>تسجيل الدخول</h1>
      <p class="lead">أدخل بريدك الإلكتروني ويصلك <strong>رمز تحقّق</strong> للدخول — بدون كلمة مرور.</p>
      <input type="hidden" name="step" value="email">
      <label for="email">البريد الإلكتروني</label>
      <input type="email" id="email" name="email" placeholder="example@email.com" required autofocus>
      <button class="btn" type="submit">إرسال رمز التحقق</button>
    <?php else: ?>
      <h1>أدخل رمز التحقق</h1>
      <p class="lead">أرسلنا رمزًا من 6 أرقام إلى:<br><span class="masked"><?php echo htmlspecialchars($maskedEmail); ?></span><br>صالح لمدة <?php echo $ttlMin; ?> دقيقة.</p>
      <input type="hidden" name="step" value="code">
      <label for="code">رمز التحقق</label>
      <input type="text" id="code" name="code" class="code-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="······" required autofocus>
      <button class="btn" type="submit">تحقّق وادخل</button>
      <div class="sub"><a href="<?php echo htmlspecialchars($systemUrl); ?>/signin.php">لم يصلك الرمز؟ ابدأ من جديد</a></div>
    <?php endif; ?>

    <div class="foot">© <?php echo date('Y'); ?> مؤسسة سلاسل بابل لتقنية المعلومات</div>
  </form>
</body>
</html>
