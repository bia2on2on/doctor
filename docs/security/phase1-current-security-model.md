# وضعیت امنیتی جاری — Phase 1A

> **دامنه:** این سند **رفتار واقعیِ کد پس از کامیت‌های Phase 1A** را توصیف می‌کند، نه رفتار برنامه‌ریزی‌شده.
> هر ادعای این سند از خود مخزن قابل اثبات است.
>
> | | |
> |---|---|
> | فاز | **Phase 1 — Security Hardening**، بخش **1A** (مستقل از Scope چندکلینیکی) |
> | نقطهٔ مرجع | کامیت Checkpoint فاز ۰/۰٫۵ = `6500bff` |
> | تاریخ | 2026-09-08 |
> | وضعیت | 1A پیاده‌سازی‌شده — 1B معوق (وابسته به Phase 2/3) |

---

## ۱. مرزهای این فاز

Phase 1A فقط کنترل‌های امنیتیِ **مستقل از Scope** را پوشش می‌دهد.

| در دامنه (1A) | خارج از دامنه (1B / فازهای بعد) |
|---|---|
| مجوز سطح Route (احراز هویت، Nonce، Capability) | مجوز مبتنی بر **مالکیت رکورد** و **Scope کلینیک** |
| چرخهٔ عمر OTP و ورود | ماتریس نهایی Capability (Phase 3) |
| Rate Limiting سراسری | سقف‌های per-Clinic (وابسته به تصمیم باز Q6) |
| محدودسازی مسیر فایل و گارد وب‌سرور | رمزنگاری فایل در حالت سکون (تصمیم باز) |
| کنترل دسترسی و صحت‌سنجی بکاپِ موجود | قابلیت‌های جدید بکاپ (Phase 15) |

**قید سخت (AD-13):** در Phase 1A هیچ کد جدیدی با `clinic_id = 1` نوشته نشده و هیچ فرض «کاربر جاری = پزشک» اضافه نشده است. `AuthorizationService` نهایی روی مدل ناقص فعلی ساخته **نشده** است.

---

## ۲. مدل مجوز REST — رفتار فعلی

### ۲-۱ اعداد پایه (بازراستی‌شده از کد)

| سنجه | مقدار | نحوهٔ شمارش |
|---|---|---|
| ثبت‌های `register_rest_route` در سورس | **۷۷** | فراخوان‌های واقعی در `src/Rest/*.php` |
| Routeهای زمان اجرا | **۸۰** | ۷۷ − ۱ (حلقهٔ `foreach` در `QueueController`) + ۴ رویداد پزشک |
| ثبت‌های `permission_callback` | **۸۸** | ← تصحیح نسبت به عدد ۸۹ در گزارش Phase 0 |
| `__return_true` پیش از 1A | **۶۷** | همگی حذف شدند |
| `permission_callback` گارددار پیش از 1A | **۲۱** | ۶۷ + ۲۱ = ۸۸ ✔ |
| `permission_callback` گارددار پس از 1A | **۸۸** | صفر مورد `__return_true` باقی نمانده |

> **تصحیح C-6:** گزارش Phase 0 عدد **۸۹** را ثبت کرده بود. شمارش دقیق نشان می‌دهد یکی از تطابق‌های `grep`، متنِ داخل **Docblock** در `BookingController.php:312` است و ثبت واقعی نیست. عدد درست **۸۸** است (۶۷ + ۲۱). این تصحیح عدد ۸۰ Route و عدد ۶۷ را تغییر نمی‌دهد.

### ۲-۲ طبقه‌بندی ۶۷ مورد `__return_true` — بدون فرضِ آسیب‌پذیری

بررسی هر ۶۷ مورد نشان داد **هیچ‌کدام Endpoint باز نبودند**:

| طبقه | تعداد | واقعیت |
|---|---:|---|
| گارد داخل Handler (Wrapper `staff`/`doctor`/`guard`/`patient`) | ۵۸ | Nonce + Capability یا Role داخل Handler اجرا می‌شد |
| گارد در لایهٔ Service (مجوز سطح Resource) | ۴ | مثلاً `stream`، `download`، `GET /config/services` |
| Public به تصمیم محصولی | ۵ | `availability`، `booking/quote`، `health`، `otp/request`، `otp/verify` |

⇒ این یک **نقص Late Authorization** بود، نه ۶۷ Endpoint آسیب‌پذیر. اثر عملی آن:

1. کد Handler برای فراخوانِ بدون مجوز **اجرا می‌شد** (سطح حمله و هزینهٔ منابع).
2. وضعیت امنیتی هر Route از روی ثبت آن **قابل خواندن نبود** — بازبینی و ابزار خودکار نمی‌توانستند تشخیص دهند کدام Route عمداً Public است.

### ۲-۳ لایهٔ جدید در `RestBase`

| Helper | معنا | جایگزینِ چه چیزی شد |
|---|---|---|
| `permPublic()` | Route **عمداً** عمومی | `'__return_true'` روی ۵ Route |
| `permAuthenticated()` | Nonce + کاربر واردشده | Routeهایی که Capability واحد ندارند |
| `permCap()` | Nonce + Capability | ۵۸ Route با Capability مشخص |
| `permAnyRole()` | Nonce + عضویت در نقش مجاز | Routeهای بیمار و اعلان‌ها |

گاردهای داخل Handler **عمداً حذف نشدند** (Defence in Depth). چون همان متدهای پایه (`requireNonce`/`requireCap`) دوباره استفاده می‌شوند، **کد خطا، پیام و HTTP Status تغییر نکرده است**.

### ۲-۴ شمارش نهایی Routeها

| دسته | تعداد | فهرست |
|---|---:|---|
| Public صریح | **۵** | `/availability`، `/booking/quote`، `/health`، `/otp/request`، `/otp/verify` |
| نیازمند احراز هویت + Capability/Role | **۸۳** | مابقی ثبت‌ها |
| معوق به Scope (1B) | **۰ در سطح Route** | مالکیت/Scope در Service اعمال می‌شود — بند ۵ |

---

## ۳. احراز هویت و OTP — رفتار فعلی

| کنترل | وضعیت پس از 1A |
|---|---|
| نرمال‌سازی شماره | `MobileValidator::normalize()` — فقط ایران (`09xxxxxxxxx`) |
| انقضا | `otp.ttl_sec` از Settings، بررسی در `verify()` |
| مصرف تک‌بار | `UPDATE … WHERE consumed_at IS NULL` + بررسی تعداد ردیف (اتمیک) |
| مقاومت در برابر Replay | کد مصرف‌شده ⇒ `CLINIC_OTP_INVALID` |
| محدودیت تلاش | `OtpPolicy` — قفل پس از سقف تلاش ناموفق |
| ذخیرهٔ کد | **HMAC-SHA256(code, pepper)** — پیش از این `sha256(code . pepper)` |
| Pepper | `CPMS_PEPPER` → در نبود آن، Secret تصادفی ۲۵۶ بیتی در Option `cpms_otp_pepper` |
| **اتصال Purpose** | فهرست بسته `OtpService::PURPOSES` + `enum` در Route |
| **اتصال Session** | فقط `PURPOSE_LOGIN` کوکی احراز هویت صادر می‌کند |
| IP کلاینت | `ClientIp::resolve()` — بند ۴ |
| مقاومت در برابر Enumeration | پاسخ‌های `request()` وضعیت وجود کاربر را افشا نمی‌کنند |

### نقص‌های اصلاح‌شده

**V-1 — امضای نادرست پارامتر IP (بحرانی، عملکردی + امنیتی).**
`OtpService::verify()` پارامتر IP را `?int` اعلام کرده بود، اما تنها فراخوانِ Production یعنی `OtpController::verifyCode()` یک `?string` از `REMOTE_ADDR` می‌فرستد. هر دو فایل `declare(strict_types=1)` دارند ⇒ هر درخواست واقعی `/otp/verify` یک **TypeError و پاسخ ۵۰۰** می‌داد و Rate Limit `otp-verify-ip` (۲۰ در ساعت) **کد مرده** بود. هیچ تستی مسیر REST را صدا نمی‌زد و همهٔ تست‌های موجود `verify()` را با دو آرگومان فراخوانی می‌کنند، پس باگ در CI نامرئی بود.

**V-3 — سردرگمی Purpose (بحرانی).**
`purpose` رشتهٔ آزاد بود. چون State سیاست به ازای `(mobile, purpose)` نگهداری می‌شود، مهاجم می‌توانست با تغییر مقدار، Cooldown/Lockout را تکه‌تکه کند و ردیف بی‌نهایت بسازد. مهم‌تر: `verify()` **بدون توجه به Purpose** `wp_set_auth_cookie()` صدا می‌زد ⇒ یک کد `verify_mobile` عملاً یک ورود بود.

**V-4 — Pepper پیش‌فرضِ درونِ کد (متوسط).**
در نبود ثابت `CPMS_PEPPER`، مقدار ثابت `cpms-dev-pepper-change-me` استفاده می‌شد. کد OTP فقط ۶ رقم است؛ با Pepper شناخته‌شده، تمام کدها از روی یک Dump جدول به‌صورت آفلاین و آنی بازیابی می‌شوند.

---

## ۴. Rate Limiting — رفتار فعلی

| کنترل | کلید | سقف | پنجره | وضعیت |
|---|---|---:|---:|---|
| ورود بر اساس IP | `login-ip:{ip}` | ۲۰ | ۹۰۰ ثانیه | **جدید در 1A** |
| ورود بر اساس نام کاربری | `login-user:{sha256[:32]}` | ۱۰ | ۹۰۰ ثانیه | **جدید در 1A** |
| درخواست OTP (روزانه) | `otp-day:{mobile}` | Settings | ۸۶۴۰۰ | موجود |
| درخواست OTP (ساعتی) | `otp-hour:{mobile}` | Settings | ۳۶۰۰ | موجود |
| درخواست OTP بر اساس IP | `otp-ip:{ip}` | ۱۰ | ۳۶۰۰ | موجود |
| تأیید OTP بر اساس IP | `otp-verify-ip:{ip}` | ۲۰ | ۳۶۰۰ | موجود — **تازه فعال شد** (V-1) |
| رزرو عمومی | `booking-{userId}` | ۱۰ | ۳۶۰۰ | موجود |
| آپلود فایل | `files:upload:{userId}` | ۱۰ | ۳۶۰۰ | موجود |
| جریان Realtime | `rt_queue_*` / `rt_notif_*` | ۶۰ | ۶۰ | موجود |
| تست/ارسال SMS | `sms-test-*` / `sms-send-*` / `sms-tmpl-*` | ۱۰/۱۰/۲۰ | ۳۶۰۰ | موجود |
| `wp_ajax` | — | — | — | **در افزونه وجود ندارد (۰ مورد)** |

**V-2 — نبود کامل کنترل Bruteforce ورود (بحرانی).**
Docblock خودِ `RateLimiter` کلید `login:{ip}` را تبلیغ می‌کرد، اما در کل افزونه **صفر** فراخوانی برای آن و **هیچ** Hook روی `authenticate` یا `wp_login_failed` وجود نداشت ⇒ `wp-login.php` و احراز هویت REST بدون هیچ سقفی قابل Bruteforce بودند.

طراحی `LoginRateLimiter`:
- فقط **شکست‌ها** شمرده می‌شوند ⇒ ورود عادی هرگز قفل نمی‌شود.
- بررسی روی فیلتر `authenticate` با اولویت ۵، **پیش از** مقایسهٔ رمز توسط WordPress.
- پیام رد **برای حساب موجود و ناموجود یکسان** است ⇒ به Oracle شمارش کاربر تبدیل نمی‌شود.
- نام کاربری پیش از تبدیل به کلید **هش** می‌شود ⇒ جدول Rate Limit آن را خام ذخیره نمی‌کند.
- `RateLimiter::peek()` اضافه شد: بررسیِ پیش از عمل نباید خودش شمارنده را مصرف کند.

### رفتار Trusted Proxy (صریح)

`ClientIp::resolve()`:

| شرایط | نتیجه |
|---|---|
| پیش‌فرض (بدون تعریف `CPMS_TRUSTED_PROXIES`) | فقط `REMOTE_ADDR` — هدرهای Forwarded **نادیده** |
| `CPMS_TRUSTED_PROXIES` تعریف‌شده **و** `REMOTE_ADDR` عضو آن | زنجیرهٔ `X-Forwarded-For`/`X-Real-IP` از راست به چپ تا نزدیک‌ترین گام غیرمعتمد |
| `CPMS_TRUSTED_PROXIES` تعریف‌شده ولی `REMOTE_ADDR` عضو آن نیست | هدرها **نادیده** |

پیکربندی: `define('CPMS_TRUSTED_PROXIES', '10.0.0.0/8, 172.18.0.5');` (IPv4/IPv6، CIDR یا IP خام).

**چرا این پیش‌فرض:** اعتماد کورکورانه به `X-Forwarded-For` هر محدودیت مبتنی بر IP را با یک هدر جعلی بی‌اثر می‌کند (هر درخواست = یک هویت تازه). در مقابل، خواندن همیشگی `REMOTE_ADDR` پشت Proxy/CDN همهٔ کاربران را در یک سطل مشترک می‌ریزد و محدودیت را به قفل سراسری تبدیل می‌کند. هیچ پیش‌فرضِ درستی وجود ندارد ⇒ انتخاب باید **اعلام‌شده** باشد.

---

## ۵. امنیت فایل و بکاپ — رفتار فعلی

### ۵-۱ آنچه از قبل درست بود (بدون تغییر)

| کنترل | واقعیت |
|---|---|
| اعتبارسنجی نوع فایل | `MedicalFileService` — MIME واقعی با `finfo` روی محتوا، مقایسه با Allowlist |
| پسوند فایل | **از MIME استخراج می‌شود**، نه از ورودی کاربر ⇒ تغییر نام بی‌اثر است |
| نام ذخیره | ۳۲ کاراکتر hex تصادفی؛ نام اصلی فقط در دیتابیس |
| هدرهای تحویل | `Content-Disposition: attachment`، `X-Content-Type-Options: nosniff`، `Cache-Control: private` |
| شناسهٔ بکاپ | `assertSafeId()` روی **همهٔ** متدهای مسیر + ۸ کاراکتر تصادفی در شناسه |

### ۵-۲ نقص‌های اصلاح‌شده

**V-5 — نبود محدودسازی مسیر (متوسط، Defence in Depth).**
`LocalFileStorage::absolutePath()` مسیر را صرفاً الحاق می‌کرد و `read()`/`delete()` از همان عبور می‌کردند. `storage_path` امروز توسط `store()` ساخته می‌شود و از ورودی کاربر نمی‌آید ⇒ **پیمایش مسیرِ قابل بهره‌برداری اثبات نشد**. اما تنها مانعِ تبدیل «یک ستون دیتابیس» به «خواندن/حذف هر فایل روی سرور»، همان فرض بود. اکنون `containedPath()` هر بخش `..` را **رد** می‌کند (نه اینکه resolve کند)، مسیر مطلق، Backslash و بایت NUL را رد می‌کند و اگر فایل موجود باشد، Symlinkِ خارج از ریشه را هم رد می‌کند.

**V-6 — صحت‌سنجی بکاپ Fail-Open (متوسط).**
```php
$manifestShaOk = @file_get_contents($dir.'/manifest.json.sha256') === false   // ← نبودِ فایل = «سالم»
    || trim(...) === hash_file('sha256', $dir.'/manifest.json');
```
یعنی برای پنهان کردن دستکاری مانیفست کافی بود مهاجم **فایل هش را پاک کند** و بکاپ همچنان `ok_quick` گزارش می‌شد. اکنون Fail-Closed با `hash_equals`: نبودن، خالی بودن یا عدم تطابق ⇒ `corrupt`.

**V-7 — اتکا به `.htaccess` به تنهایی (متوسط، ریسک باقی‌مانده).**
هر دو مسیر `wp-content/clinic-files/` و `wp-content/cpms-backups/` داخل DocumentRoot هستند و فقط با `.htaccess` + `index.php` خالی محافظت می‌شدند. **nginx فایل `.htaccess` را نمی‌خواند** ⇒ روی نصب nginx این پوشه‌ها مستقیماً سرو می‌شوند؛ برای پوشهٔ بکاپ یعنی یک Dump کامل پایگاه داده.

آنچه در 1A انجام شد (افزونه نمی‌تواند پیکربندی وب‌سرور را بازنویسی کند):

| اقدام | فایل |
|---|---|
| گارد IIS | `web.config` با `<deny users="*" />` |
| دستور دقیق nginx | `README-SECURITY.txt` در هر دو پوشه |
| تشخیص شرط | `isInsideWebRoot()` روی هر دو Store |

> ⚠️ **ریسک باقی‌مانده (بسته نشده):** محافظت صحیح همچنان به پیکربندی وب‌سرور وابسته است. راه‌حل قطعی، انتقال هر دو پوشه به خارج از DocumentRoot است (`files.storage_path` / `backup.storage_path`). این یک تصمیم راه‌اندازی است، نه تغییر کد.

### ۵-۳ رمزنگاری در حالت سکون

**وضعیت: پیاده‌سازی نشده.** نه فایل‌های بالینی و نه بکاپ‌ها رمزنگاری نمی‌شوند. تنظیم `files.encrypt_at_rest` در مستندات به‌عنوان تصمیم کارفرما (V1.5) ثبت شده است. Phase 1A این را تغییر نداده — بند ۷.

---

## ۶. `admin_post` و AJAX — نتیجهٔ ممیزی

| سنجه | نتیجه |
|---|---:|
| اکشن‌های `admin_post_*` | **۲۵** |
| دارای بررسی Nonce | **۲۵ / ۲۵** |
| دارای بررسی Capability | **۲۵ / ۲۵** |
| `admin_post_nopriv_*` | **۰** |
| `wp_ajax*` در کل افزونه | **۰** (بازراستی‌شده) |
| **شکاف یافت‌شده** | **۰** |

| فایل | تعداد | Capability |
|---|---:|---|
| `ClinicianAdminPage.php` | ۶ | `CONFIG` |
| `CpmsSetupWizard.php` | ۲ | `CONFIG` |
| `PatientAdminPage.php` | ۱ | `PATIENT_CREATE` |
| `RoleCapabilitiesPage.php` | ۲ | `manage_options` |
| `SettingsAdmin.php` | ۱ | `cpms_config` |
| `StaffManagementPage.php` | ۳ | `CONFIG` |
| `SystemPage.php` | ۱۰ | `cpms_config` |

⇒ **هیچ تغییری در کد لازم نبود.** یافتهٔ Phase 0 مبنی بر `wp_ajax = 0` تأیید شد.

---

## ۷. تصمیم‌های باز (بدون اقدام یک‌جانبه)

| # | موضوع | چرا در 1A بسته نشد |
|---|---|---|
| **OD-5** | Pepper زنجیرهٔ Audit (`AuditLogger::pepper()`) همان مقدار ثابت درونِ کد را دارد | تعویض آن زنجیرهٔ Hash **رکوردهای Audit موجود** را روی نصب‌های فعلی نامعتبر می‌کند ⇒ تصمیم پیامدساز، نه اصلاح. برخلاف Audit، Tokenهای OTP عمر چنددقیقه‌ای دارند و تعویض Pepper بی‌خطر بود. |
| **OD-6** | رمزنگاری فایل بالینی و بکاپ در حالت سکون | تصمیم محصولی/عملیاتی (مدیریت کلید، بازیابی، اثر بر Restore) |
| **OD-7** | انتقال اجباری پوشه‌های ذخیره به خارج از DocumentRoot | تصمیم راه‌اندازی — ممکن است نصب‌های موجود را بشکند |
| **Q6** | دامنهٔ جدول `cpms_rate_limits` (سراسری / per-Clinic) | سقف‌های سراسری 1A بدون آن کار می‌کنند؛ سقف per-Clinic به Phase 2 وابسته است |

---

## ۸. مرجع پیکربندی

| ثابت / Option | نقش | پیش‌فرض در نبود آن |
|---|---|---|
| `CPMS_PEPPER` | Pepper هش OTP (و زنجیرهٔ Audit) | OTP: Secret تصادفی ماندگار در Option `cpms_otp_pepper` |
| `CPMS_TRUSTED_PROXIES` | فهرست Proxy معتمد (CIDR/IP، با کاما) | هیچ Proxy ای معتمد نیست ⇒ فقط `REMOTE_ADDR` |
| `files.storage_path` | مسیر مطلق ذخیرهٔ فایل بالینی | `wp-content/clinic-files` (**داخل DocumentRoot**) |
| `backup.storage_path` | مسیر مطلق ذخیرهٔ بکاپ | `wp-content/cpms-backups` (**داخل DocumentRoot**) |

---

## ۹. اسناد مرتبط

- [`docs/security/phase1b-deferred-register.md`](phase1b-deferred-register.md) — اقلام معوق به Phase 1B
- [`docs/security/auth-authorization.md`](auth-authorization.md) — مدل مرجع (دارای بنر Superseded برای بخش‌های چندکلینیکی)
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) — AD-01…AD-16
- [`docs/drift-register.md`](../drift-register.md) — فاصلهٔ سند/کد
