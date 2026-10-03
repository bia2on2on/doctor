# Phase 15 — Slice 2B: پیکربندی استقرار S3 (checkpoint عملیاتی محدود)

> **دامنهٔ این سند محدود است:** فقط قراردادِ پیکربندی/امنیتِ Slice 2B — راهنمای عملیاتِ S3، upload/download یا «remote backup» **نیست**. هیچ ادعایی دربارهٔ محافظتِ ریموت یا آمادگیِ بکاپِ ابری نمی‌کند. هنوز **هیچ آپلودی** وجود ندارد؛ این فقط فوندیشنِ پیکربندی + کارخانهٔ کلاینتِ بدونِ شبکه است.

## نام ثابت‌ها (تک منبعِ حقیقت — production)

تنها منبعِ پیکربندیِ تولیدی، ثابت‌های PHP تعریف‌شده در لایهٔ deployment است (`wp-config.php` یا include مدیریت‌شدهٔ سرور). **بدونِ fallback به getenv در این slice؛ بدونِ ذخیره در DB/options؛ بدون `CPMS_S3_ENABLED`.**

| ثابت | الزامی | محتوا |
| --- | --- | --- |
| `CPMS_S3_ENDPOINT` | بله | URL مطلقِ HTTPS با host؛ بدونِ userinfo/query/fragment |
| `CPMS_S3_REGION` | بله | region سرویس سازگار با S3 (بدون allowlist مخصوص AWS) |
| `CPMS_S3_BUCKET` | بله | نام bucket (بدون قانون DNS خاص provider) |
| `CPMS_S3_PREFIX` | **خیر — تنها فیلد اختیاری** | prefix نرمال‌شدهٔ کلیدِ آبجکت؛ بدون `\`، کاراکتر کنترلی یا سگمنت `.` / `..` |
| `CPMS_S3_PATH_STYLE` | بله | دقیقاً `true` یا `false` (boolean — رشتهٔ `'true'` و مانند آن رد می‌شود) |
| `CPMS_S3_ACCESS_KEY_ID` | بله | حساس — محدود، بدون کنترل/فاصله |
| `CPMS_S3_SECRET_ACCESS_KEY` | بله | **سری** — محدود؛ هرگز لاگ/serialize/نمایش نمی‌شود |
| `CPMS_BACKUP_ENCRYPTION_KEY_B64` | بله | **سری** — Base64 سخت‌گیرانه که دقیقاً به ۳۲ بایت (`SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES`) رمزگشایی شود |

## معنایی وضعیت‌ها (fail-closed)

- **همهٔ ثابت‌ها غایب** ⇒ S3 «غیرفعال/پیکربندی‌نشده» (`fromDeploymentConstants()` = `null`). پرچمِ ENABLED عمداً وجود ندارد.
- **بخشی از فیلدهای الزامی حاضر** ⇒ شکستِ صریح با `CLINIC_BACKUP_S3_PARTIAL_CONFIG` (بررسیِ حضور قبل از اعتبارسنجیِ مقدار).
- **همهٔ الزامی حاضر و معتبر** ⇒ پیکربندی‌شده (`S3BackupDeploymentConfig`).
- **مقدار ناسازگار** ⇒ خطای محدودِ همان فیلد: `CLINIC_BACKUP_S3_ENDPOINT_INVALID` / `..._REGION_INVALID` / `..._BUCKET_INVALID` / `..._PREFIX_INVALID` / `..._PATH_STYLE_INVALID` / `..._CREDENTIALS_INVALID` / `CLINIC_BACKUP_ENCRYPTION_KEY_INVALID`. پیام‌ها ثابت‌اند و هرگز مقدار/credential/کلید را حمل نمی‌کنند (`data` همیشه خالی).

## مالکیت کلید رمزنگاری و جدایی از ترابرد

`CPMS_BACKUP_ENCRYPTION_KEY_B64` متعلق به **رمزنگاری بکاپ** است (`BackupEncryptionEnvelope` — Slice 1)، نه ترابردِ S3. کلیدِ رمزگشایی‌شده فقط در حافظه و فقط از طریقِ `S3BackupDeploymentConfig::encryptionKeyForBackupEnvelope()` در دسترس است؛ نمایِ ترابردی (`S3BackupTransportSettings`) و کارخانهٔ کلاینت (`S3BackupClientFactory`) کلید را حمل/منتقل نمی‌کنند و کلید هرگز وارد پیکربندی AWS SDK نمی‌شود. تولید و چرخشِ کلید مسئولیتِ deployment (خارج از افزونه و خارج از DB) است — همان الگوی «کلید بیرون از persistence» Slice 1.

## کارخانهٔ کلاینت (بدون شبکه)

`S3BackupClientFactory::create($transportSettings)` فقط `Aws\S3\S3Client` رسمی را می‌سازد: endpoint/region پیکربندی‌شده، `use_path_style_endpoint` صریح، `signature_version = v4`، اعتبارنامهٔ صریح (بدون discovery محیطی)، `http.verify = true`. ساختِ کلاینت هیچ درخواست شبکه‌ای انجام نمی‌دهد. bucket/prefix دادهٔ عملیاتیِ مقصدِ بکاپ‌اند و به سازندهٔ کلاینت داده نمی‌شوند.

## پیمان محصولی کلیدِ آبجکت ریموت (بدون ادعای تشخیص PHI)

خودِ CPMS هرگز شناسهٔ بیمار/Clinic/بالینی به کلیدِ آبجکتِ ریموت نمی‌افزاید؛ کلیدِ آبجکت فقط از prefixِ deployment و نامِ فایل‌های بکاپِ متعلق به CPMS ساخته می‌شود. نرم‌افزار **نمی‌تواند** و **ادعا نمی‌کند** که PHI داخلِ prefixِ دلخواهِ پیکربندی‌شده را معنایی تشخیص می‌دهد — مسئولیتِ انتخابِ prefix بدونِ شناسهٔ بیمار با اپراتورِ deployment است.

## مخفی‌کاری در سطح شیء

شیءهای config/transport هیچ مقدار حساسی در پراپرتیِ قابلِ dump نگه نمی‌دارند (نگهداری در Closure + `__debugInfo` بدونِ مقدار + `__serialize()` خالی + `__unserialize()` که بازیابی را **فوراً رد می‌کند** — هیچ کپیِ serializeشده هرگز به شیءِ قابلِ استفاده برنمی‌گردد + بدونِ `__toString`/`JsonSerializable`). نتیجه: `var_dump`/`print_r`/`var_export`/`serialize`/`json_encode` مسیرِ افشای credential/کلید ندارند و `unserialize(serialize($obj))` با خطای محدودِ ثابت (`CLINIC_BACKUP_S3_CONFIG_RESTORE_REJECTED` / `CLINIC_BACKUP_S3_TRANSPORT_RESTORE_REJECTED`) شکست می‌خورد. محدودیتِ شناخته‌شده: این تضمین‌ها برای **خطاهای** `BackupException` (پیام/کد/data ثابت) و سطح‌های debug/serialization فوق است — لاگ‌گیریِ دستیِ مقادیرِ بازگشتیِ accessorها خارج از پیمان است و هرگز نباید انجام شود.

## صریحاً خارج از این slice

upload/download، تغییرِ `BackupService`/`backup.run`، persistence، Settings/UI، migration، provider abstraction، remote status/verification، و **هر ادعای محافظتِ ریموت یا رعایتِ HIPAA/PHI**. بکاپِ ریموتِ فعال هنوز وجود ندارد.
