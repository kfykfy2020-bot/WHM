<div class="panel panel-default" style="margin-top:15px">
  <div class="panel-heading"><h3 class="panel-title">معلومات الخدمة</h3></div>
  <div class="panel-body">
    {if $showtech}
      {if $ip}
        <p><strong>عنوان IP:</strong> <code>{$ip}</code></p>
        <p><strong>اسم المستخدم:</strong> <code>root</code> (Linux · SSH) — أو <code>admin</code> (Windows · RDP)</p>
      {else}
        <p>الخادم قيد التجهيز.</p>
      {/if}
      {if $instanceid}<p class="text-muted" style="margin-top:8px"><small>معرّف الخادم: {$instanceid}</small></p>{/if}
    {else}
      <p>خدمتك <strong>مُدارة بالكامل</strong> من مؤسسة سلاسل بابل لتقنية المعلومات — التشغيل والصيانة والنسخ الاحتياطي علينا.</p>
      <p class="text-muted"><small>لأي دعم أو طلب، افتح تذكرة من قسم الدعم.</small></p>
    {/if}
  </div>
</div>