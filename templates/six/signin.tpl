<div class="logincontainer">

    {include file="$template/includes/pageheader.tpl" title="تسجيل الدخول" desc="الدخول عبر رمز يصل إلى بريدك — بدون كلمة مرور"}

    {if $pwless_error}
        <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-triangle"></i> {$pwless_error}</div>
    {/if}
    {if $pwless_notice}
        <div class="alert alert-success" role="alert"><i class="fas fa-check-circle"></i> {$pwless_notice}</div>
    {/if}

    <div class="row">
        <div class="col-sm-12">

            {if $pwless_step == 'email'}
                <form method="post" action="{$pwless_action}" class="login-form" role="form" autocomplete="off">
                    <input type="hidden" name="step" value="email">
                    <div class="form-group">
                        <label for="inputEmail">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" id="inputEmail" placeholder="ادخل بريدك" autofocus required>
                    </div>
                    <p class="text-muted" style="font-size:13px;line-height:1.9">أدخل بريدك الإلكتروني ويصلك <strong>رمز تحقّق</strong> للدخول — بدون كلمة مرور.</p>
                    <div align="center">
                        <input type="submit" class="btn btn-primary" value="إرسال رمز التحقق">
                    </div>
                </form>
            {else}
                <form method="post" action="{$pwless_action}" class="login-form" role="form" autocomplete="off">
                    <input type="hidden" name="step" value="code">
                    <div class="form-group">
                        <label for="inputCode">رمز التحقق</label>
                        <input type="text" name="code" class="form-control text-center" id="inputCode" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="······" autocomplete="one-time-code" autofocus required style="letter-spacing:8px;font-size:20px">
                    </div>
                    <p class="text-muted" style="font-size:13px;line-height:1.9">أرسلنا رمزًا من 6 أرقام إلى <strong dir="ltr">{$pwless_masked}</strong><br>صالح لمدة {$pwless_ttl} دقيقة.</p>
                    <div align="center">
                        <input type="submit" class="btn btn-primary" value="تحقّق وادخل">
                        <a href="{$pwless_action}" class="btn btn-default">ابدأ من جديد</a>
                    </div>
                </form>
            {/if}

        </div>
    </div>
</div>
