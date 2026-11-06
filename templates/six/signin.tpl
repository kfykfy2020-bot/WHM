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
                <form method="post" action="{$pwless_action}" class="login-form" role="form" autocomplete="off" id="otpForm">
                    <input type="hidden" name="step" value="code">
                    <input type="hidden" name="code" id="otpCode">
                    <div class="form-group text-center">
                        <label>رمز التحقق</label>
                        <div class="otp-boxes" dir="ltr">{section name=i loop=$pwless_len}<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="off">{/section}</div>
                        <p class="text-muted" style="font-size:13px;line-height:1.9">أُرسل رمز من {$pwless_len} أرقام إلى <strong dir="ltr">{$pwless_masked}</strong></p>
                        <div id="otpTimer" class="otp-timer"></div>
                    </div>
                    <div align="center">
                        <input type="submit" class="btn btn-primary" value="تحقّق وادخل">
                    </div>
                </form>

                <script>
                    var OTP_RESEND = {$pwless_resend};
                    var OTP_RESEND_URL = "{$pwless_action}?resend=1";
                </script>
                {literal}<style>
                .otp-boxes{display:flex;gap:10px;justify-content:center;direction:ltr;margin:8px 0 14px}
                .otp-box{width:54px;height:62px;text-align:center;font-size:26px;font-weight:700;color:#243b53;background:#fff;border:1px solid #cdd7e3;border-radius:12px;outline:none;transition:border .15s,box-shadow .15s;-moz-appearance:textfield}
                .otp-box:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.12)}
                .otp-timer{font-size:13px;color:#6b7c93;min-height:20px;margin-bottom:6px}
                .otp-timer a{color:#1d4ed8;text-decoration:none;font-weight:700}
                @media (max-width:767px){.otp-boxes{gap:8px}.otp-box{width:50px;height:58px;font-size:23px}}
                </style>
                <script>
                (function(){
                    var boxes=document.querySelectorAll('.otp-box');
                    var hidden=document.getElementById('otpCode');
                    var form=document.getElementById('otpForm');
                    function collect(){var v='';for(var k=0;k<boxes.length;k++){v+=boxes[k].value;}hidden.value=v;return v;}
                    for(var i=0;i<boxes.length;i++){(function(i){
                        var b=boxes[i];
                        b.addEventListener('input',function(){
                            b.value=b.value.replace(/[^0-9]/g,'').slice(0,1);
                            if(b.value&&i<boxes.length-1){boxes[i+1].focus();}
                            if(collect().length===boxes.length){form.submit();}
                        });
                        b.addEventListener('keydown',function(e){
                            if(e.key==='Backspace'&&!b.value&&i>0){boxes[i-1].focus();}
                        });
                        b.addEventListener('paste',function(e){
                            var d=(((e.clipboardData||window.clipboardData).getData('text'))||'').replace(/[^0-9]/g,'');
                            if(!d){return;}e.preventDefault();
                            for(var j=0;j<boxes.length;j++){boxes[j].value=d.charAt(j)||'';}
                            collect();
                            var last=Math.min(d.length,boxes.length)-1;if(last<0){last=0;}boxes[last].focus();
                            if(collect().length===boxes.length){form.submit();}
                        });
                    })(i);}
                    if(boxes[0]){boxes[0].focus();}
                    form.addEventListener('submit',function(){collect();});
                    var t=OTP_RESEND;var el=document.getElementById('otpTimer');
                    function two(n){return n<10?'0'+n:''+n;}
                    function tick(){
                        if(t>0){var m=Math.floor(t/60),s=t%60;el.innerHTML='يمكنك طلب رمز جديد خلال '+two(m)+':'+two(s);t--;setTimeout(tick,1000);}
                        else{el.innerHTML='لم يصلك الرمز؟ <a href="'+OTP_RESEND_URL+'">إعادة الإرسال</a>';}
                    }
                    tick();
                })();
                </script>{/literal}
            {/if}

        </div>
    </div>
</div>
