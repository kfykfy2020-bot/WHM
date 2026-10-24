<?php

if (!defined('WHMCS')) {
    die('Access Denied');
}

/**
 * Salasel Babel — idle session timeout dialog (styled like the Correspondence
 * system): white card, header with the big company logo, a three-part body
 * (idle alert | MM:SS countdown with progress bar | action buttons) and a
 * security footer. Applies to both the client area and the admin area.
 */
function salasel_idle_timeout_markup()
{
    return <<<'HTML'
<style>
#sbIdleOverlay{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;font-family:inherit;padding:16px}
#sbIdleCard{background:#fff;border-radius:12px;max-width:900px;width:100%;box-shadow:0 30px 70px -15px rgba(0,0,0,.5);overflow:hidden;direction:rtl}
#sbIdleCard .sbi-head{background:#fff;padding:26px 26px 20px;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #eef2f7}
#sbIdleCard .sbi-head b{color:#1e3a5f;font-size:18px}
#sbIdleCard .sbi-body{display:flex;align-items:stretch;padding:26px 22px;background:#fff}
#sbIdleCard .sbi-col{flex:1;padding:0 20px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:9px}
#sbIdleCard .sbi-div{width:1px;background:#e9edf3;align-self:stretch}
#sbIdleCard .sbi-clock{width:56px;height:56px;border-radius:50%;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:26px}
#sbIdleCard .sbi-alert-title{color:#2563eb;font-weight:800;font-size:20px}
#sbIdleCard .sbi-alert-msg{color:#64748b;font-size:13.5px;line-height:1.7}
#sbIdleCard .sbi-cd-card{background:#f8fafc;border:1px solid #eef2f7;border-radius:12px;padding:16px 22px;width:100%}
#sbIdleCard .sbi-cd-label{color:#94a3b8;font-size:13px;margin-bottom:2px}
#sbIdleCard .sbi-count{font-size:44px;font-weight:800;color:#1e3a5f;letter-spacing:2px;line-height:1.1}
#sbIdleCard .sbi-bar{height:6px;border-radius:6px;background:#e5e7eb;margin-top:12px;overflow:hidden}
#sbIdleCard .sbi-bar > i{display:block;height:100%;width:100%;background:linear-gradient(90deg,#2563eb,#22d3ee)}
#sbIdleCard .sbi-btns{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
#sbIdleCard .sbi-btn-extend{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:12px 20px;font-size:15px;font-weight:700;cursor:pointer;white-space:nowrap}
#sbIdleCard .sbi-btn-logout{background:#fff;color:#334155;border:1px solid #cbd5e1;border-radius:8px;padding:12px 20px;font-size:15px;cursor:pointer;white-space:nowrap}
#sbIdleCard .sbi-foot{background:#f1f5f9;color:#64748b;font-size:12.5px;padding:12px 22px;text-align:center;border-top:1px solid #e5e7eb}
@media(max-width:780px){#sbIdleCard .sbi-body{flex-direction:column;gap:20px}#sbIdleCard .sbi-div{display:none}}
</style>
<div id="sbIdleOverlay" dir="rtl">
  <div id="sbIdleCard">
    <div class="sbi-head"><span id="sbIdleLogo"></span></div>
    <div class="sbi-body">
      <div class="sbi-col">
        <div class="sbi-clock">&#128337;</div>
        <div class="sbi-alert-title">تنبيه الخمول</div>
        <div class="sbi-alert-msg">لم نلاحظ أي نشاط، هل ما زلت تعمل؟</div>
      </div>
      <div class="sbi-div"></div>
      <div class="sbi-col">
        <div class="sbi-cd-card">
          <div class="sbi-cd-label">سيتم تسجيل الخروج تلقائياً خلال</div>
          <div class="sbi-count" id="sbIdleCount">05:00</div>
          <div class="sbi-bar"><i id="sbIdleBar"></i></div>
        </div>
      </div>
      <div class="sbi-div"></div>
      <div class="sbi-col">
        <div class="sbi-btns">
          <button type="button" class="sbi-btn-extend" id="sbIdleExtend">تمديد الجلسة</button>
          <button type="button" class="sbi-btn-logout" id="sbIdleLogout">تسجيل الخروج</button>
        </div>
      </div>
    </div>
    <div class="sbi-foot">&#128274; تغلق الجلسة تلقائياً بعد انتهاء العدّاد لحماية معلومات النظام.</div>
  </div>
</div>
<script>
(function(){
  if (window.sbIdleInit) return; window.sbIdleInit = true;
  var IDLE_MS = 5*60*1000, COUNTDOWN = 5*60;
  var overlay, countEl, barEl, idleT, cdT, remain, active=false;
  function logoutUrl(){ var a=document.querySelector('a[href*="logout.php"]'); return a?a.href:'logout.php'; }
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
  function fmt(s){ var m=Math.floor(s/60), r=s%60; return (m<10?'0':'')+m+':'+(r<10?'0':'')+r; }
  function render(){ countEl.textContent=fmt(remain); if(barEl) barEl.style.width=(remain/COUNTDOWN*100)+'%'; }
  function reset(){ if(active) return; clearTimeout(idleT); idleT=setTimeout(warn, IDLE_MS); }
  function warn(){ active=true; remain=COUNTDOWN; render(); overlay.style.display='flex';
    cdT=setInterval(function(){ remain--; render(); if(remain<=0){ doLogout(); } },1000); }
  function extend(){ clearInterval(cdT); overlay.style.display='none'; active=false;
    try{ fetch(window.location.href,{method:'HEAD',credentials:'same-origin',cache:'no-store'}); }catch(e){} reset(); }
  function doLogout(){ clearInterval(cdT); active=false; window.location.href=logoutUrl(); }
  ready(function(){
    overlay=document.getElementById('sbIdleOverlay'); countEl=document.getElementById('sbIdleCount'); barEl=document.getElementById('sbIdleBar');
    if(!overlay) return;
    var box=document.getElementById('sbIdleLogo');
    var img=new Image(); img.style.maxHeight='100px'; img.style.width='auto'; img.alt='سلاسل بابل لتقنية المعلومات';
    img.onerror=function(){ box.innerHTML=''; var lg=document.querySelector('.logo img,#logo img,.navbar-brand img,header img');
      if(lg){ var c=lg.cloneNode(true); c.removeAttribute('class'); c.style.maxHeight='100px'; c.style.width='auto'; box.appendChild(c); }
      else box.innerHTML='<b>سلاسل بابل لتقنية المعلومات</b>'; };
    img.src='/assets/img/logo.png'; box.appendChild(img);
    document.getElementById('sbIdleExtend').addEventListener('click',extend);
    document.getElementById('sbIdleLogout').addEventListener('click',doLogout);
    window.sbIdleWarn=warn;
    if(!document.querySelector('a[href*="logout.php"]')) return;
    ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function(ev){
      document.addEventListener(ev,function(){ if(!active) reset(); },{passive:true}); });
    reset();
  });
})();
</script>
HTML;
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    // SB: skip the idle timeout on the cart/checkout + invoice/payment pages — it was
    // logging users out mid-order and while entering card data in the Moyasar iframe
    // (iframe activity does not reset the parent-page idle timer).
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if (in_array($script, ['cart.php', 'viewinvoice.php'], true)) {
        return '';
    }
    return salasel_idle_timeout_markup();
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    // never inject into AJAX / JSON / modal endpoints (e.g. whatsnew.php?modal=1)
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        return '';
    }
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === 'whatsnew.php' || isset($_GET['modal']) || isset($_GET['ajax'])) {
        return '';
    }
    return salasel_idle_timeout_markup();
});
