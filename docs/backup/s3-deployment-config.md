# Phase 15 — Slice 2B: پیکربندی استقرار S3 (checkpoint عملیاتی محدود)

> **دامنهٔ این سند محدود است:** قراردادِ پیکربندی/امنیتِ Slice 2B + قراردادِ عملیاتِ صریحِ آینه‌سازیِ Slice 2C. این راهنمای عمومیِ عملیاتِ S3 یا «remote backup» نیست و **هیچ ادعایی دربارهٔ محافظتِ ریموت، بازیابی یا آمادگیِ بکاپِ ابری نمی‌کند**.
>
> **وضعیت (پس از Slice 2C):** یک عملیاتِ **صریحِ دستی** وجود دارد — آینه‌سازیِ رمزنگاری‌شدهٔ **یک** بکاپِ محلیِ موجود روی S3 (`BackupS3Mirror::mirrorBackup()`)؛ یعنی «آپلودِ رمزنگاری‌شدهٔ یک‌طرفه». **هیچ download/recovery، هیچ integration خودکار با `backup.run`/زمان‌بند، هیچ remote retention و هیچ UI/Settings در این فازها وجود ندارد** (فیلدهای پیکربندی هنوز فقط ثابت‌های deployment‌اند).

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

خودِ CPMS هرگز شناسهٔ بیمار/Clinic/بالینی به کلیدِ آبجکتِ ریموت نمی‌افزاید. از Slice 2C شکلِ کلید **صریحاً opaque** است: `prefixِ deployment + '/' + شناسهٔ ۳۲‌کاراکتریِ آینه + '/' + شناسهٔ ۳۲‌کاراکتریِ شیء` — هر دو شناسه ۱۶ بایت تصادفیِ رمزنگاری‌شده (۱۲۸ بیت)‌اند و **هیچ** نام فایل، مسیر نسبی، شناسهٔ بکاپِ محلی، timestampِ مشتق‌شده از آن یا هویتِ tenant در کلید نمی‌آید. نرم‌افزار **نمی‌تواند** و **ادعا نمی‌کند** که PHI داخلِ prefixِ دلخواهِ پیکربندی‌شده را معنایی تشخیص می‌دهد — مسئولیتِ انتخابِ prefix بدونِ شناسهٔ بیمار با اپراتورِ deployment است.

## مخفی‌کاری در سطح شیء

شیءهای config/transport هیچ مقدار حساسی در پراپرتیِ قابلِ dump نگه نمی‌دارند (نگهداری در Closure + `__debugInfo` بدونِ مقدار + `__serialize()` خالی + `__unserialize()` که بازیابی را **فوراً رد می‌کند** — هیچ کپیِ serializeشده هرگز به شیءِ قابلِ استفاده برنمی‌گردد + بدونِ `__toString`/`JsonSerializable`). نتیجه: `var_dump`/`print_r`/`var_export`/`serialize`/`json_encode` مسیرِ افشای credential/کلید ندارند و `unserialize(serialize($obj))` با خطای محدودِ ثابت (`CLINIC_BACKUP_S3_CONFIG_RESTORE_REJECTED` / `CLINIC_BACKUP_S3_TRANSPORT_RESTORE_REJECTED`) شکست می‌خورد. محدودیتِ شناخته‌شده: این تضمین‌ها برای **خطاهای** `BackupException` (پیام/کد/data ثابت) و سطح‌های debug/serialization فوق است — لاگ‌گیریِ دستیِ مقادیرِ بازگشتیِ accessorها خارج از پیمان است و هرگز نباید انجام شود.

## صریحاً خارج از این slice

upload/download، تغییرِ `BackupService`/`backup.run`، persistence، Settings/UI، migration، provider abstraction، remote status/verification، و **هر ادعای محافظتِ ریموت یا رعایتِ HIPAA/PHI**. بکاپِ ریموتِ فعال هنوز وجود ندارد.
---

## Slice 2C — آینه‌سازیِ صریحِ رمزنگاری‌شدهٔ یک بکاپِ محلیِ موجود

کلاس: `ClinicCore\Application\Backup\BackupS3Mirror` (final، بدونِ interface/provider/DI). ورودیِ تنها = شناسهٔ یک بکاپِ **موجود**؛ هیچ بکاپِ تازه‌ای ساخته نمی‌شود و به `backup.run` وصل نیست.

```php
$result = $mirror->mirrorBackup($existingLocalBackupId);   // throw BackupException در شکست
```

- **پیش‌شرط محلی (Fail-Closed، پیش از هر درخواست):** همان راستی‌آزماییِ تثبیت‌شده (`LocalBackupVerifier` — منطقِ `BackupService::verifyIn`) + الزامِ وجودِ `manifest.json.sha256`. بکاپِ ناموجود/دست‌کاری‌شده/غیرقابل‌اثبات رد می‌شود و **هیچ** درخواستی نمی‌رود.
- **مجموعهٔ ریموت:** `db.sql` + `manifest.json` + یک شیء به‌ازای هر فایلِ storageِ مندرج در مانیفست + **یک کاتالوگِ فقط‌ریموت که آخر از همه آپلود می‌شود**. `manifest.json.sha256` عمداً شیءِ ریموت نیست.
- **رمزنگاری:** فقط `BackupEncryptionEnvelope` (قالب `CPMSBK01`)؛ هر شیء پیش از درخواست رمزنگاری می‌شود و **فقط ciphertext** وارد بدنهٔ SDK می‌شود (stream از فایلِ موقت — بدونِ بارگذاری کل ciphertext در حافظه). کلید فقط از `encryptionKeyForBackupEnvelope()` خوانده می‌شود و هرگز در client config/metadata/log/audit/error/نامِ شیء نمی‌آید.
- **کاتالوگ v1:** `format = cpms-s3-mirror-catalog`، `format_version = 1`، `mirror_id`، `catalog_object_id`، `local_backup_id`، `local_manifest_sha256`، `envelope_format`/`envelope_format_version` و `entries[] = {role: database|manifest|storage, logical_path, object_id, ciphertext_bytes, ciphertext_sha256, remote_verification}`. مسیرهای منطقی **فقط** داخل plaintextِ کاتالوگ مجازند چون خودِ شیء رمزنگاری‌شده است. هیچ credential/کلیدی در آن نیست. کاتالوگ artifact خصوصیِ موقتِ محلی است (در plaintext و ciphertext) که در **همهٔ** مسیرهای پایان پاک می‌شود و هیچ جدول/fایلِ ماندگاری و هیچ migration نمی‌سازد.
- **سقفِ تک‌بخشی:** `PutObject` تنها عملیاتِ upload است؛ `MAX_ENCRYPTED_OBJECT_BYTES = 4 * 1024^3 = 4294967296` **بایت ciphertext** (واحد: بایتِ باینری) — یعنی ۱ GiB زیرِ سقفِ رسمیِ AWS برای یک PUT (5 GB = 5368709120 بایت؛ همان عدد در SDK قفل‌شده: `MultipartUploader::PART_MAX_SIZE`). شیءِ بزرگ‌تر **پیش از** درخواست با `CLINIC_BACKUP_MIRROR_OBJECT_TOO_LARGE` رد می‌شود. multipart/`UploadPart`/`MultipartUploader` در این slice وجود ندارد و آستانهٔ خودکارِ ۱۶MiB SDK به‌عنوان limit تفسیر نمی‌شود.
- **VERIFIED در برابر ACKNOWLEDGED:** برای هر شیء، digestِ SHA-256ِ ciphertext در همان PUT با `ChecksumSHA256` ارسال می‌شود (+ `ContentLength`). اگر endpoint در پاسخِ خودِ PUT، `ChecksumSHA256` هم‌خوان و `x-amz-object-size` برابرِ اندازهٔ محلی برگرداند ⇒ `VERIFIED`. اگر اثباتِ checksum در دسترس نباشد ⇒ `ACKNOWLEDGED` که هرگز verified گزارش/لاگ نمی‌شود. ناهم‌خوانیِ **صریح** ⇒ `CLINIC_BACKUP_MIRROR_CHECKSUM_MISMATCH` / `..._SIZE_MISMATCH` (Fail-Closed). ETag و metadataِ اکوی‌شده هرگز دلیلِ برابریِ بدنه نیستند و HEAD برای اثباتِ بدنه استفاده نمی‌شود.
- **نتیجهٔ سطح‌مجموعه:** همه `VERIFIED` ⇒ `encrypted upload verified`؛ حداقل یک `ACKNOWLEDGED` ⇒ `encrypted upload acknowledged; checksum verification unavailable`؛ هر شکست ⇒ عملیات ناموفق. این «پایانِ ترتیبِ آپلود» ادعای تراکنشِ اتمیک یا visibility هم‌زمان نیست.
- **رفتار در شکست:** مبدأ محلی بایت‌به‌بایت دست‌نخورده، بدونِ prune/invalidation، بدونِ plaintext fallback، با پیامِ bounded بدونِ حساسیت. اگر پیش از شکست شیءِ ریموتی ساخته شده باشد، **فقط** همان اشیاءِ همین تلاش به‌صورتِ best-effort پاک می‌شوند (بدونِ هیچ API عمومیِ delete/retention و بدونِ ListObjects)؛ اگر نظافت کامل نشود، evidenceٔ bounded (`attempted/failed/object_ids`ِ opaque) در `data['cleanup']` خطا و لاگِ عملیاتی ثبت می‌شود.
- **ردپای ماندگار:** فقط audit/operation log موجود با pointer هشت‌فیلده (`backup_id`, `mirror_id`, `catalog_object_id`, `object_count`, `ciphertext_bytes`, `verification`, `timestamp`, `result_code`) — بدونِ مسیر منطقی، PHI، secret یا مقدارِ پیکربندی.
- **کدهای خطا:** `CLINIC_BACKUP_MIRROR_NOT_CONFIGURED` / `_LOCAL_INVALID` / `_ENCRYPTION_FAILED` / `_OBJECT_TOO_LARGE` / `_UPLOAD_FAILED` / `_CHECKSUM_MISMATCH` / `_SIZE_MISMATCH` (پیام‌های ثابت، `data` همیشه خالی جز evidenceٔ نظافت).
- **نکتهٔ عملیاتی:** هیچ سازوکارِ فراخوانیِ خودکاری اضافه نشده — UI/Settings/Job در این slice ممنوع بود؛ اپراتور باید صریحاً این عملیات را صدا بزند. تست‌ها در `tests/Unit/Phase15S3MirrorUploadRedTest.php` (بدونِ شبکه/endpoint واقعی — seamٔ مستند `http_handler` Slice 2B).

