<?php
if (!defined('WHMCS')) { die('Access Denied'); }
use WHMCS\Database\Capsule;
// White-label hiding on MANAGED-product service pages only (clients can't see control/password/IP/Contabo).
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if ((($_GET['action'] ?? '') !== 'productdetails')) { return ''; }
    $sid = (int) ($_GET['id'] ?? 0);
    if (!$sid) { return ''; }
    $pid = (int) Capsule::table('tblhosting')->where('id', $sid)->value('packageid');
    if (!in_array($pid, [70], true)) { return ''; }
    return <<<'HTML'
<script>
(function(){
function h(){
  document.querySelectorAll('a,button').forEach(function(e){
    var t=(e.textContent||'').trim();
    var a=((e.getAttribute('href')||'')+' '+(e.getAttribute('onclick')||''));
    if(/changepassword/i.test(a)||t==='تغيير كلمة المرور'||/a=boot|a=shutdown|a=reboot/i.test(a)||t==='تشغيل'||t==='إيقاف'||t==='إعادة تشغيل'){
      (e.closest('li')||e).style.display='none';
    }
  });
  var dom=document.getElementById('domain');
  if(dom){ dom.querySelectorAll('div.row').forEach(function(r){ r.style.display='none'; }); }
  document.querySelectorAll('a[href="#domain"]').forEach(function(a){ (a.closest('li')||a).style.display='none'; });
  document.querySelectorAll('*').forEach(function(el){
    var own=''; for(var i=0;i<el.childNodes.length;i++){ if(el.childNodes[i].nodeType===3) own+=el.childNodes[i].textContent; }
    if(own.trim()==='بيانات السيرفر'){ el.style.display='none'; }
  });
}
if(document.readyState!=='loading') h(); else document.addEventListener('DOMContentLoaded', h);
})();
</script>
HTML;
});
