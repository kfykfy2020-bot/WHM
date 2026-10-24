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

### `lang/overrides/arabic.php` ← يُنسخ إلى `/var/www/whmcs/lang/overrides/arabic.php`
- تعديل كلمات صفحة الدخول: `register` → «التسجيل»، و`forgotpw` → «نسيت كلمة السر؟».

### قوالب الإعدادات السرّية (بقيم بديلة فقط)
- `opt/erb-integration/config.php.example` ← انسخه إلى `/opt/erb-integration/config.php` على الخادم واملأ المفتاح الحقيقي (chmod 600، ملكية www-data).
- `opt/cf-integration/config.php.example` ← إعداد Cloudflare (**غير مستخدم حالياً** — ميزة النطاقات الفرعية أُزيلت؛ مُبقى للمرجع).

## ملاحظة
تشمل هذه النسخة تخصيصات «سلاسل بابل» الأساسية. توجد hooks أخرى على الخادم (مثل `telegram-config.php` و`managed_whitelabel.php`) **لم تُضمَّن** لأنها قد تحوي أسراراً؛ يمكن إضافتها لاحقاً بعد تنقيتها.
