# WHM — نسخة تخصيصات لوحة الاستضافة (WHMCS)

نسخة احتياطية ومرجعية لتخصيصات **لوحة سلاسل بابل للاستضافة** (WHMCS):
- الموقع: `my.salaselbabel.com`
- الخادم: `37.60.225.100` (الكود في `/var/www/whmcs`، يُنشر بالنسخ المباشر — ليس git).

> مشروع **منفصل تماماً** عن «نظام المراسلات» (`Salasel-Babel/correspondence-system`).
>
> ⚠️ **لا يدخل هذا المستودع أي سرّ إطلاقاً** (مفاتيح API، كلمات مرور، توكنات). ملفات الأسرار الحقيقية تبقى على الخادم فقط.

## المحتويات ومواضعها على الخادم

### `includes/hooks/` ← تُنسخ إلى `/var/www/whmcs/includes/hooks/`
- **salasel_client_linking.php** — بطاقة «🔗 اربط نطاقك بخادمك» في صفحة خدمة العميل: يربط نطاق العميل (المسجّل عبر Namecheap) بعنوان خادمه بضغطة، مع خيار إلغاء الربط.
- **salasel_erp_integration.php** — عند دفع أي فاتورة يُرسل سند قيد (JOURNAL_VOUCHER) إلى نظام ERP (`erb.salaselbabel.com`). يقرأ المفتاح من `/opt/erb-integration/config.php` (سرّ — غير مضمّن هنا).
- **salasel_cart_to_invoice.php** — توجيه إتمام الحجز من السلّة إلى صفحة الفاتورة.
- **salasel_home_redirect.php** — إعادة توجيه الصفحة الرئيسية.
- **salasel_idle_timeout.php** — لوحة الخمول / انتهاء الجلسة.
- **salasel_erb_ticket_assist.php** — طلبات التطوير من «المحاسب الذكي» في ERB: حين يطلب عميل ERB ميزة غير موجودة يجهّز المحاسب الطلب كاملاً ويفتح البوابة في تبويب جديد على `submitticket.php?erb_draft=<رمز لمرة واحدة، 15 دقيقة>`. الإضافة تُبقي الرمز في الجلسة عبر دخول WHMCS أو التسجيل (لا تحقن شيئاً في صفحات الدخول والتسجيل والتحقق الثنائي والـ captcha — العميل يسجّل بنفسه)، ثم بعد الدخول تجلب المسودة من ERB مباشرة (موقّعة HMAC-SHA256، TLS، مهلة 10 ثوانٍ)، وتحرّك مؤشراً مرئياً يختار قسم «Developing Support» بمعرّفه الحقيقي من الصفحة ويكتب العنوان والنص، ويتوقف عند زر الإرسال بتلميح «راجع ثم اضغط إرسال» — بلا إرسال آلي. خطاف `TicketOpen` يعيد رقم التذكرة ورابطها إلى ERB موقّعاً. يقرأ السر من `/opt/erb-integration/ticket-assist.config.php` (سرّ — غير مضمّن هنا).

### `lang/overrides/arabic.php` ← يُنسخ إلى `/var/www/whmcs/lang/overrides/arabic.php`
- تعديل كلمات صفحة الدخول: `register` → «التسجيل»، و`forgotpw` → «نسيت كلمة السر؟».

### قوالب الإعدادات السرّية (بقيم بديلة فقط)
- `opt/erb-integration/config.php.example` ← انسخه إلى `/opt/erb-integration/config.php` على الخادم واملأ المفتاح الحقيقي (chmod 600، ملكية www-data).
- `opt/erb-integration/ticket-assist.config.php.example` ← انسخه إلى `/opt/erb-integration/ticket-assist.config.php` واكتب فيه عنوان ERB (`https://…/api/public/assistant`) والسر المشترك نفسه المحفوظ في ERB (إعدادات المحاسب الذكي ← «السر المشترك مع إضافة البوابة»، 32 حرفاً على الأقل: `openssl rand -hex 32`). يقبل عدة أنظمة ERB (حتى 10) في `instances`. (chmod 600، ملكية www-data).
- `opt/cf-integration/config.php.example` ← إعداد Cloudflare (**غير مستخدم حالياً** — ميزة النطاقات الفرعية أُزيلت؛ مُبقى للمرجع).

## طلبات التطوير — البديل بواجهة WHMCS البرمجية (اختياري)
إن لم تعمل الإضافة يبقى في بطاقة ERB «نسخ نص التذكرة» ورابط البوابة. ولإتاحة «إرسال مباشر» (بعد موافقة العميل على البطاقة) يستعمل ERB بيانات اعتماد WHMCS المحفوظة في «ربط الأنظمة» إن كان عنوانها `https://my.salaselbabel.com` نفسه، ويحتاج دور API فيها: `GetSupportDepartments` و`GetClients` و`GetTickets` و`OpenTicket` (مع `GetInvoices` و`GetInvoice` و`GetClientsDetails` للجلب). لا يُنشأ حساب للعميل: البريد المسجل له في ERB يجب أن يطابق حساباً قائماً في البوابة.

## ملاحظة
تشمل هذه النسخة تخصيصات «سلاسل بابل» الأساسية. توجد hooks أخرى على الخادم (مثل `telegram-config.php` و`managed_whitelabel.php`) **لم تُضمَّن** لأنها قد تحوي أسراراً؛ يمكن إضافتها لاحقاً بعد تنقيتها.
