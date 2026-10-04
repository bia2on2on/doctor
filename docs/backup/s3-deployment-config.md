# Phase 15 — Slice 2B–2D: پیکربندی و عملیات محدودِ S3

> **دامنهٔ این سند محدود است:** قراردادِ پیکربندی/امنیتِ Slice 2B، آینه‌سازیِ دستیِ Slice 2C و بازسازیِ غیرمخربِ Slice 2D فقط تا preflight موجود. این راهنمای عمومیِ عملیاتِ S3 یا «remote backup» نیست و **هیچ ادعایی دربارهٔ محافظتِ تضمین‌شدهٔ ریموت، Restore تولیدی، آمادگیِ provider pilot یا RPO/RTO نمی‌کند**.
>
> **وضعیت (پس از Slice 2D):** دو عملیاتِ **صریح و دستی** وجود دارد: آینه‌سازیِ رمزنگاری‌شدهٔ **یک** بکاپِ محلیِ موجود (`BackupS3Mirror::mirrorBackup()`) و دریافت/بازسازیِ همان آینه در staging خصوصی با `BackupS3MirrorRecovery::reconstructAndPreflight(array $pointer)`. نتیجهٔ دومی فقط **«remote reconstruction passed existing restore preflight»** است: بازسازی از verifier و restore preflight موجود می‌گذرد؛ این عملیات `restoreApply()` را فراخوانی نمی‌کند و جداول یا storage تولیدی را تغییر نمی‌دهد. **هیچ integration خودکار با `backup.run`/زمان‌بند، remote retention/delete lifecycle یا UI/Settings وجود ندارد** (فیلدهای پیکربندی همچنان فقط ثابت‌های deployment‌اند).

## دامنهٔ artifact بکاپ بر پایهٔ کدِ اجراشونده

`BackupService::createBackup()` بکاپ را در سطحِ کلِ نصب می‌سازد؛ نه فقط Clinic جاری.

- `db.sql` شامل جدول‌هایی است که dumper با الگوی `CpmsDb::dbPrefix() . 'cpms_%'` به `SHOW TABLES LIKE` می‌یابد (پیشوندِ پیکربندی‌شدهٔ WordPress به‌علاوهٔ `cpms_…`). جدول‌های WordPress Core، `wp_users`، `wp_options`، نقش‌ها و جدول‌های افزونه‌های دیگر در این dump نیستند.
- `storage/` از تمام ریشه‌های بالینیِ فعالِ استخراج‌شده از `cpms_clinics` و Settingِ هر Clinic با کلید `files.storage_path` در `cpms_settings` گردآوری می‌شود؛ مسیر خالی از پیش‌فرضِ `LocalFileStorage` پیروی می‌کند. فایل‌ها بازگشتی، با مسیر نسبیِ مجاز و segment اولِ شناسهٔ Clinic عددیِ مثبت، کپی می‌شوند؛ ریشه‌های یکسان deduplicate و تعارضِ محتوای هم‌مسیر fail-closed می‌شود. با صفر Clinic مجموعهٔ فایل بالینی خالی است.
- `wp-content/uploads` یا فایل‌های عمومی دیگر جداگانه پیمایش نمی‌شوند: فایل‌های بیرون از ریشه‌های بالینیِ فعال خارج‌اند؛ اگر فایلی واقعاً زیرِ یک ریشهٔ فعال باشد و قاعدهٔ مسیر بالا را بگذراند، صرف‌نظر از منشأ اولیه‌اش ممکن است داخل شود. فایل‌های plugin/source و پیکربندیِ deployment (از جمله `wp-config.php`/includeهای استقرار) کپی نمی‌شوند.
- آرتیفکتِ محلی (`db.sql`، `storage/`، `manifest.json` و `manifest.json.sha256`) **plaintext** است؛ checksum رمزنگاری نیست. S3 mirror نسخه‌های رمزنگاری‌شدهٔ `db.sql`، `manifest.json`، فایل‌های storage و catalog را می‌فرستد؛ envelopeِ remote قالب `CPMSBK01` و Sodium secretstream XChaCha20-Poly1305ِ احراز‌شده است؛ sidecarِ `manifest.json.sha256` remote نمی‌شود. مقدارِ ثابت‌های deploymentِ credential و کلید رمزنگاریِ S3 بخشی از آرتیفکت نیستند.

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

`CPMS_BACKUP_ENCRYPTION_KEY_B64` متعلق به **رمزنگاری بکاپ** است (`BackupEncryptionEnvelope` — Slice 1)، نه ترابردِ S3. custody نسخهٔ اصلی باید بیرون از میزبان و در سامانهٔ مدیریت رازِ تحتِ کنترلِ اپراتور بماند؛ برای اجرای mirror یا reconstruction، همان نسخه باید از آن مرجع به ثابتِ deployment `CPMS_BACKUP_ENCRYPTION_KEY_B64` در فرایند CPMS provision شود. این قرارداد از `wp-config.php` یا include مدیریت‌شدهٔ deployment و از `defined()/constant()` می‌خواند (نه `getenv()` و نه DB/options). کلیدِ رمزگشایی‌شده فقط در حافظه و فقط از طریقِ `S3BackupDeploymentConfig::encryptionKeyForBackupEnvelope()` در دسترس است؛ نمایِ ترابردی (`S3BackupTransportSettings`) و کارخانهٔ کلاینت (`S3BackupClientFactory`) کلید را حمل/منتقل نمی‌کنند و کلید هرگز وارد پیکربندی AWS SDK نمی‌شود. catalog/pointer شناسهٔ کلید ندارد؛ برای هر mirror باید مرجع/نسخهٔ دقیقِ همان کلید در custody خارجی معلوم باشد. چارچوبِ key-ID/rotation/KMS در این slice وجود ندارد.

## کارخانهٔ کلاینت (بدون شبکه)

`S3BackupClientFactory::create($transportSettings)` فقط `Aws\S3\S3Client` رسمی را می‌سازد: endpoint/region پیکربندی‌شده، `use_path_style_endpoint` صریح، `signature_version = v4`، اعتبارنامهٔ صریح (بدون discovery محیطی)، `http.verify = true`. ساختِ کلاینت هیچ درخواست شبکه‌ای انجام نمی‌دهد. bucket/prefix دادهٔ عملیاتیِ مقصدِ بکاپ‌اند و به سازندهٔ کلاینت داده نمی‌شوند.

## پیمان محصولی کلیدِ آبجکت ریموت (بدون ادعای تشخیص PHI)

خودِ CPMS هرگز شناسهٔ بیمار/Clinic/بالینی به کلیدِ آبجکتِ ریموت نمی‌افزاید. از Slice 2C شکلِ کلید **صریحاً opaque** است: `prefixِ deployment + '/' + شناسهٔ ۳۲‌کاراکتریِ آینه + '/' + شناسهٔ ۳۲‌کاراکتریِ شیء` — هر دو شناسه ۱۶ بایت تصادفیِ رمزنگاری‌شده (۱۲۸ بیت)‌اند و **هیچ** نام فایل، مسیر نسبی، شناسهٔ بکاپِ محلی، timestampِ مشتق‌شده از آن یا هویتِ tenant در کلید نمی‌آید. نرم‌افزار **نمی‌تواند** و **ادعا نمی‌کند** که PHI داخلِ prefixِ دلخواهِ پیکربندی‌شده را معنایی تشخیص می‌دهد — مسئولیتِ انتخابِ prefix بدونِ شناسهٔ بیمار با اپراتورِ deployment است.

## مخفی‌کاری در سطح شیء

شیءهای config/transport هیچ مقدار حساسی در پراپرتیِ قابلِ dump نگه نمی‌دارند (نگهداری در Closure + `__debugInfo` بدونِ مقدار + `__serialize()` خالی + `__unserialize()` که بازیابی را **فوراً رد می‌کند** — هیچ کپیِ serializeشده هرگز به شیءِ قابلِ استفاده برنمی‌گردد + بدونِ `__toString`/`JsonSerializable`). نتیجه: `var_dump`/`print_r`/`var_export`/`serialize`/`json_encode` مسیرِ افشای credential/کلید ندارند و `unserialize(serialize($obj))` با خطای محدودِ ثابت (`CLINIC_BACKUP_S3_CONFIG_RESTORE_REJECTED` / `CLINIC_BACKUP_S3_TRANSPORT_RESTORE_REJECTED`) شکست می‌خورد. محدودیتِ شناخته‌شده: این تضمین‌ها برای **خطاهای** `BackupException` (پیام/کد/data ثابت) و سطح‌های debug/serialization فوق است — لاگ‌گیریِ دستیِ مقادیرِ بازگشتیِ accessorها خارج از پیمان است و هرگز نباید انجام شود.

## مرز فعلی و موارد صریحاً خارج از scope

Slice 2D فقط دریافت و بازسازیِ موقتِ ciphertext را برای رسیدن به verifier و restore preflight موجود اضافه می‌کند. **Destructive restore/apply،** تغییرِ `backup.run` یا زمان‌بند، persistence/تنظیمات UI، migration، provider abstraction، remote retention/delete lifecycle و provider pilot خارج از scope هستند. این preflight نه Restore تولیدی است، نه تضمینِ محافظتِ ریموت و نه شاهدِ RPO/RTO یا رعایتِ HIPAA/PHI.
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
- **VERIFIED در برابر ACKNOWLEDGED:** برای هر شیء، digestِ SHA-256ِ ciphertext در همان PUT با `ChecksumSHA256` ارسال می‌شود (+ `ContentLength`). اگر endpoint در پاسخِ خودِ PUT، `ChecksumSHA256` هم‌خوان برگرداند ⇒ `VERIFIED`. اگر اثباتِ checksum در دسترس نباشد ⇒ `ACKNOWLEDGED` که هرگز verified گزارش/لاگ نمی‌شود. هیچ **شرطِ طولی** برای رسیدن به VERIFIED نیست: خودِ AWS در مرجعِ `PutObject` می‌گوید `x-amz-object-size` «only present if you append to an object» (AppendObject، S3 Express One Zone) — پس پاسخِ معمولیِ PUT طولِ شیء را تضمین نمی‌کند؛ اگر endpoint صراحتاً طولِ **متناقضی** اعلام کند Fail-Closed رد می‌شود (`..._SIZE_MISMATCH`) که فقط ردِّ تناقض است، نه اثباتِ لازم. ناهم‌خوانیِ **صریحِ** checksum ⇒ `CLINIC_BACKUP_MIRROR_CHECKSUM_MISMATCH`؛ ردِ checksum توسط سرویس هم از همان مسیرِ خطای SDK (پاسخِ غیرموفق) شکستِ صریح است، نه تنزل به acknowledged. ETag و metadataِ اکوی‌شده هرگز دلیلِ برابریِ بدنه نیستند و HEAD برای اثباتِ بدنه استفاده نمی‌شود.
- **نتیجهٔ سطح‌مجموعه:** همه `VERIFIED` ⇒ `encrypted upload verified`؛ حداقل یک `ACKNOWLEDGED` ⇒ `encrypted upload acknowledged; checksum verification unavailable`؛ هر شکست ⇒ عملیات ناموفق. این «پایانِ ترتیبِ آپلود» ادعای تراکنشِ اتمیک یا visibility هم‌زمان نیست.
- **رفتار در شکست:** مبدأ محلی بایت‌به‌بایت دست‌نخورده، بدونِ prune/invalidation، بدونِ plaintext fallback، با پیامِ bounded بدونِ حساسیت. اگر پیش از شکست شیءِ ریموتی ساخته شده باشد، **فقط** همان اشیاءِ همین تلاش به‌صورتِ best-effort پاک می‌شوند (بدونِ هیچ API عمومیِ delete/retention و بدونِ ListObjects)؛ اگر نظافت کامل نشود، evidenceٔ bounded (`attempted/failed/object_ids`ِ opaque) در `data['cleanup']` خطا و لاگِ عملیاتی ثبت می‌شود.
- **ردپای ماندگار (مشروط):** اگر `AuditLogger`/`OpLogger` موجود به سازنده تزریق شود، log محلی فقط projection هشت‌فیلدهٔ pointer (`backup_id`, `mirror_id`, `catalog_object_id`, `object_count`, `ciphertext_bytes`, `verification`, `timestamp`, `result_code`) را می‌گیرد — بدونِ مسیر منطقی، PHI، secret یا مقدارِ پیکربندی. Mirror در هر حال result برمی‌گرداند؛ جدول یا remote index برای pointer ساخته نمی‌شود.
- **کدهای خطا:** `CLINIC_BACKUP_MIRROR_NOT_CONFIGURED` / `_LOCAL_INVALID` / `_ENCRYPTION_FAILED` / `_OBJECT_TOO_LARGE` / `_UPLOAD_FAILED` / `_CHECKSUM_MISMATCH` / `_SIZE_MISMATCH` (پیام‌های ثابت، `data` همیشه خالی جز evidenceٔ نظافت).
- **نکتهٔ عملیاتی:** هیچ سازوکارِ فراخوانیِ خودکاری اضافه نشده — UI/Settings/Job در این slice ممنوع بود؛ اپراتور باید صریحاً این عملیات را صدا بزند. تست‌ها در `tests/Unit/Phase15S3MirrorUploadRedTest.php` (بدونِ شبکه/endpoint واقعی — seamٔ مستند `http_handler` Slice 2B).

## Slice 2D — بازسازیِ غیرمخرب تا restore preflight موجود

کلاسِ نهایی `ClinicCore\Application\Backup\BackupS3MirrorRecovery` عملیاتِ دستیِ `reconstructAndPreflight(array $pointer)` را ارائه می‌کند. ورودی فقط pointer هشت‌فیلدهٔ Slice 2C است؛ object key فقط از prefix پیکربندی‌شده و شناسه‌های opaqueِ معتبرِ آینه/کاتالوگ/شیء ساخته می‌شود. دریافت با `S3Client` رسمی و seamِ handler موجود انجام می‌شود؛ ETag هرگز SHA-256 فرض نمی‌شود.

- کاتالوگِ رمز‌شده با `BackupEncryptionEnvelope` احراز/باز می‌شود؛ سقف JSON آن **1 MiB** است و format/version/envelope/هویت/شمار/aggregate evidence به‌صورت fail-closed بررسی می‌شود. مسیرهای مجاز فقط `db.sql`، `manifest.json` و `storage/<clinic-id>/<relative-path>` هستند؛ مسیرهای مطلق/ناامن، کنترل‌کاراکتر، backslash، alias، duplicate، symlink escape و مقصد خارج از stage رد می‌شوند.
- حداکثر **1024 شیء با احتساب کاتالوگ**، حداکثر **4 GiB ciphertext برای هر شیء** (همان سقف Slice 2C) و حداکثر **8 GiB برای بودجهٔ تجمیعیِ ciphertext + plaintextِ stage** اعمال می‌شود؛ free-space guard پیش از دانلود payload اجرا می‌شود. این‌ها سقف‌های فنیِ پذیرش‌اند، نه تضمینِ اندازهٔ قابل‌بازیابی در همهٔ میزبان‌ها.
- اندازهٔ کاتالوگ و اندازه/hash هر payload مطابق catalogِ احراز‌شده بررسی می‌شود؛ همهٔ envelopeها احراز می‌شوند. فقط `db.sql`، `manifest.json`، فایل‌های storageِ معتبر و sidecar محلیِ `manifest.json.sha256` ساخته می‌شوند. hash مانیفست پیش از verifier سنجیده می‌شود. verifier و `BackupService::restorePreflight()` موجود استفاده می‌شوند؛ هیچ `restoreApply()`/SQL اجرا یا storage تولیدی دستکاری نمی‌شود.
- staging یکتا و owner-only بیرون از webroot و ریشه‌های بکاپ ساخته می‌شود. کاتالوگ، ciphertext و plaintext در موفقیت و شکست پاک می‌شوند؛ شکستِ cleanup فقط evidenceٔ محدودِ cleanup و کدِ خطای اولیهٔ غیرحساس را نشان می‌دهد. نتیجهٔ موفق فقط `remote reconstruction passed existing restore preflight` است.
- برای Slice 2D هیچ job/scheduler، `backup.run` integration، retention/delete، UI/Settings یا API/provider abstraction اضافه نشده است. این بررسی، اثباتِ restore واقعی یا RPO/RTO نیست.

## قراردادِ محدودِ بازیابی پس از ازدست‌رفتنِ میزبان

این قرارداد فقط به دو service صریحِ Slice 2C/2D مربوط است. S3 mirror به `backup.run` وصل نیست؛ `bin/cpms backup`، Admin و REST مسیرِ S3 mirror/reconstruction ندارند. هیچ دستور CLI، route یا UI جدیدی در این راهنما تعریف نمی‌شود.

### Pointer دقیق و نگهداریِ خارج از میزبان

پس از mirror موفق، `BackupS3Mirror::pointerFromResult()` دقیقاً این هشت کلید را **به همین ترتیب** برمی‌گرداند؛ pointer هیچ credential، secret یا مقصدی ندارد:

| ترتیب | کلید | نوع/قیدِ موفقیت |
| --- | --- | --- |
| 1 | `backup_id` | string؛ شناسهٔ بکاپِ محلیِ منبع |
| 2 | `mirror_id` | string؛ شناسهٔ opaque با ۳۲ رقمِ hex کوچک |
| 3 | `catalog_object_id` | string؛ شناسهٔ opaque با ۳۲ رقمِ hex کوچک |
| 4 | `object_count` | integer؛ تعداد کلِ آبجکت‌ها با احتساب catalog |
| 5 | `ciphertext_bytes` | integer؛ مجموعِ بایت‌های ciphertext |
| 6 | `verification` | `VERIFIED` یا `ACKNOWLEDGED` |
| 7 | `timestamp` | integer؛ Unix epoch seconds |
| 8 | `result_code` | برای نتیجهٔ موفق: `ok` |

اگر caller، `AuditLogger` و/یا `OpLogger` اختیاری را به `BackupS3Mirror` بدهد، pointer در log محلی ثبت می‌شود؛ منبعِ قطعیِ فراخوانی، result برگشتی و `pointerFromResult()` است. در هر حالت remote pointer index یا فهرست‌کردن/جست‌وجوی آبجکت‌ها وجود ندارد. `mirror_id` و `catalog_object_id` برای ساختنِ کلیدِ catalog و `backup_id` برای نام‌گذاریِ stage لازم‌اند؛ شناسه‌های آبجکت تصادفی و opaque هستند. با از دست‌رفتنِ میزبان، log محلی نیز ممکن است از دست برود و pointer را نمی‌توان از روی bucket فهرست‌شده بازسازی کرد. برای اجرای procedure مستندِ ازدست‌رفتنِ کاملِ میزبان، حفظِ pointer بیرون از میزبانِ اصلیِ CPMS **الزامی** است و باید پس از ازدست‌رفتنِ همان میزبان نیز مستقلاً قابل‌بازیابی بماند. کدِ فعلیِ CPMS pointer را از caller می‌گیرد و **مخزن/آبجکت‌ستِ جداگانه‌ای را الزام نمی‌کند**؛ packetی که به‌طور مستقل قابل‌شناسایی و دریافت باشد در همان bucket نیز ذاتاً توسط کدِ reconstruction پشتیبانی‌نشده نیست، مشروط بر اینکه راهِ دریافتِ آن پس از ازدست‌رفتنِ میزبان واقعاً در دسترس باشد. چون CPMS pointer index/listing ندارد، کشف و دریافتِ packet مسئولیتِ اپراتور است. نگهداریِ packet در bucket، account یا failure-domain جداگانه می‌تواند به‌عنوان توصیهٔ عملیاتی برای تاب‌آوری بیشتر مناسب باشد، اما **توصیه است، نه invariant یا الزامِ فنیِ CPMS**.

### حداقلِ recovery packet معمولی

Packet عملیاتیِ خارج از میزبان می‌تواند این مواردِ غیرمحرمانه را داشته باشد:

1. pointer هشت‌فیلدهٔ بالا؛
2. مقصدِ لازم برای همان mirror: `endpoint`، `region`، `bucket`، **prefix نرمال‌شده** (خالی هم مقدار معتبری است) و `path-style` به‌صورت boolean؛
3. فقط **reference**های بیرونی برای secretِ `CPMS_S3_ACCESS_KEY_ID` و `CPMS_S3_SECRET_ACCESS_KEY` (یا یک reference به secret recordی که هر دو را فراهم می‌کند)؛ و reference/version دقیقِ رازِ `CPMS_BACKUP_ENCRYPTION_KEY_B64` که در همان mirror استفاده شد.

Reference شناسهٔ محل custody است، نه مقدارِ secret. catalog و pointer نسخه/شناسهٔ کلید را حمل نمی‌کنند؛ کلیدِ درست باید همان کلیدِ رمزگشایی باشد. در packet معمولی **هرگز** secret value، رشتهٔ Base64 یا بایتِ کلید، داده/شناسهٔ بیمار یا Clinic، PHI، header یا token/cookie مربوط به Authorization، یا URL امضاشده/پیش‌امضاشده قرار ندهید. هیچ نمونهٔ مقدارِ محرمانه در این سند وجود ندارد.

### پیش‌نیازهای میزبانِ جایگزین

برای رسیدن به `reconstructAndPreflight()` لازم است:

- CPMS با کدِ Slice 2D و autoload/dependencyهای production موجود، در WordPressی که به‌طور عادی bootstrap شده اجرا شود؛ PHP حداقل `8.1`، runtime شصت‌وچهاربیتی، Sodium و AWS SDK موجود باشند.
- خودِ بکاپِ محلیِ مبدأ روی میزبانِ ازدست‌رفته برای این مرحله لازم نیست: payload از S3 بازسازی می‌شود. بااین‌حال، فرایند به WordPress/CPMS bootstrapped و MySQL قابل‌دسترسی نیاز دارد: `App::backupService()` وابستگی‌های موجود را می‌سازد و restore preflight در پایان فقط probeِ اتصالِ DB (`SELECT 1`) را انجام می‌دهد؛ این به‌تنهایی DBِ مقصدِ restore را آماده یا دادهٔ SQL را import نمی‌کند.
- ثابت‌های deploymentِ جدول بالا هنگام اجرای PHP حاضر باشند؛ `CPMS_S3_PREFIX` همان مقدارِ نرمال‌شده، و ثابتِ کلید از reference/version بیرونیِ دقیق provision شده باشد. میزبان باید دسترسی HTTPS/TLS به endpoint داشته باشد و credential باید اجازهٔ خواندنِ آبجکت‌های همان مقصد را بدهد.
- دایرکتوریِ موقتِ سیستم برای ساختِ stage قابل‌نوشتن و دارای فضای کافی برای این آینه باشد (سقفِ aggregate کد 8 GiB). کد stage یکتا با مجوز owner-only `0700` می‌سازد و مسیر زیرِ webroot، ریشهٔ بکاپ یا ریشهٔ بالینیِ فعال را رد می‌کند.

### فراخوانیِ service در حدِ موجود

فقط از یک context مورداعتماد که WordPress/CPMS را bootstrap کرده و secretها را از مرجع بیرونی به ثابت‌های deployment provision کرده است، این service callها قابل استفاده‌اند؛ snippetها **دستور CLI نیستند** و مسیر عمومیِ آمادهٔ اپراتور محسوب نمی‌شوند:

```php
$config = \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig::fromDeploymentConstants();
if (!$config instanceof \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig) {
    throw new \RuntimeException('S3 backup deployment configuration is unavailable.');
}
$backupService = \ClinicCore\Bootstrap\App::backupService();
$mirror = new \ClinicCore\Application\Backup\BackupS3Mirror($backupService->store(), $config);
$result = $mirror->mirrorBackup($existingLocalBackupId); // بکاپِ موجود و verifier-passing
$pointer = \ClinicCore\Application\Backup\BackupS3Mirror::pointerFromResult($result);
// pointer + مقصدِ غیرمحرمانه + secret references را خارج از این میزبان حفظ کنید.
```

روی میزبان جایگزین، پس از provision همان secret version و فراهم‌کردنِ pointer با نوع‌های بالا:

```php
$config = \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig::fromDeploymentConstants();
$recovery = new \ClinicCore\Application\Backup\BackupS3MirrorRecovery(
    \ClinicCore\Bootstrap\App::backupService(),
    $config
);
$result = $recovery->reconstructAndPreflight($pointer);
```

### چهار نتیجهٔ متفاوت — نه یک ادعای واحد

| مرحله | آنچه کد بررسی می‌کند | آنچه ثابت نمی‌کند |
| --- | --- | --- |
| آپلودِ `ACKNOWLEDGED` | endpoint درخواستِ PutObject را پذیرفته؛ checksum هم‌خوان در پاسخ در دسترس نبوده است | برابریِ بایت‌های نگه‌داری‌شده یا دوامِ provider |
| آپلودِ `VERIFIED` | پاسخِ PutObject برای هر آبجکت، `ChecksumSHA256` هم‌خوان با checksum محلی داشته است | دوام/تکثیرِ بلندمدت در provider واقعی |
| reconstruction verification | دریافت، اندازه/hashهای catalog، رمزگشاییِ احراز‌شده، manifest و `LocalBackupVerifier` روی stage موفق‌اند | اجرای SQL یا بازگشت کاملِ سرویس |
| restore preflight | `BackupService::restorePreflight()` روی stage موفق است و probe اتصال DB را می‌گذراند | اجرای restore روی یک target واقعی یا یک تمرین کاملِ disaster recovery |

`BackupS3MirrorRecovery::reconstructAndPreflight()` **`restoreApply()` را صدا نمی‌زند**، SQL را اجرا نمی‌کند و storage تولیدی را تغییر نمی‌دهد. Restore مخرب، عملیات جداگانهٔ محلیِ `restoreApply()` است؛ remote destructive restore در این قابلیت وجود ندارد. نتیجهٔ فعلی فقط `remote reconstruction passed existing restore preflight` است و **اثباتِ disaster recovery کامل، دوامِ provider، RPO یا RTO نیست**.

### قطعِ ناگهانی و stage خصوصیِ باقیمانده

در اجرای عادی، `finally` پاک‌سازیِ stage را تلاش می‌کند. اگر process یا میزبان ناگهانی متوقف شود، `finally` اجرا نمی‌شود و stage یکتای زیرِ دایرکتوریِ موقتِ سیستم با پیشوندِ `cpms-mirror-recovery-` ممکن است بماند؛ آن stage می‌تواند plaintextِ `db.sql`، manifest و فایل‌های بالینی را در کنار catalog/ciphertext موقت داشته باشد. **هیچ janitor خودکاری وجود ندارد.** فقط پس از تأیید از راهِ supervisor/process inventory که هیچ recovery process فعالی از همان میزبان/کاربر در حال اجرا نیست، اپراتور مجاز است candidate را با بررسیِ ownership، mode و symlinkها به‌صورت دستی inspect کند و فقط stageِ واقعاً رهاشده را حذف کند؛ اگر فعالیتی نامشخص است، حذف نکنید. حذف معمولی/`unlink` وعدهٔ secure wipe یا محوشدنِ فیزیکیِ بایت‌ها نیست.

### حدِ شواهدِ provider

Integration chain موجود، artifact واقعیِ `BackupService::createBackup()` را از mirror تا reconstruction/preflight عبور می‌دهد، اما AWS HTTP را با seamِ `http_handler` درون‌فرایندی intercept و fake می‌کند. به endpoint یا provider واقعی درخواست نمی‌فرستد و بنابراین durability، رفتار یا پایداریِ S3-compatible provider واقعی را اثبات نمی‌کند.

### پوششِ یکپارچهٔ زنجیرهٔ واقعی (منبعِ واقعی → آینه → بازسازی)

علاوه بر fixture دست‌نویسِ `tests/Integration/Phase15S3MirrorReconstructionRedTest.php`، فایلِ `tests/Integration/Phase15SourceBackupRemoteRecoveryChainTest.php` همان زنجیره را روی **آرتیفکتِ واقعیِ موتورِ محصول** اثبات می‌کند: منبع از `BackupService::createBackup()` واقعی می‌آید (dump واقعیِ `cpms_*` با `BackupSqlDumper` + فایل‌های بالینیِ واقعی که `LocalFileStorage::store()` زیرِ ریشه‌های فعالِ هر Clinic از `cpms_settings` نوشته است)، سپس `BackupS3Mirror::mirrorBackup()` واقعی، pointer دقیقِ هشت‌فیلدهٔ `pointerFromResult()`، و `BackupS3MirrorRecovery::reconstructAndPreflight()` واقعی تا verifier و restore preflight موجود — بدونِ provider واقعی و بدونِ restore.

- **بدونِ شبکه:** همهٔ درخواست‌های AWS درون‌فرایندی با همان seam مستندِ `http_handler` (Slice 2B) گرفته می‌شوند؛ host endpoint از TLD رزروشدهٔ `.invalid` (RFC 6761) است؛ bucket/prefix/region/credential/کلید/ردیف‌های Clinic/پلاده‌ها ساختگی و غیرتولیدی‌اند. **هیچ abstraction ترابری/provider جدیدی برای تست ساخته نشده است.**
- **رمزنگاریِ سرتاسری (end-to-end):** بدنهٔ هر شیءِ remote با magic پاکتِ `CPMSBK01` آغاز می‌شود، **هیچ marker plaintext** از آرتیفکتِ واقعی (dump، دو فایلِ بالینی، note، مسیرهای نسبیِ واقعیِ storage) در هیچ بدنهٔ شیئی نیست، و رمزگشاییِ اشیاء **دوسویی (bijection)** دقیقاً همان فایل‌های واقعیِ منبع است؛ کاتالوگِ احراز‌شده `local_backup_id` و `local_manifest_sha256` را به همان آرتیفکتِ واقعی مقید می‌کند و `manifest.json.sha256` هرگز شیءِ ریموت نیست.
- **عدمِ تخریب:** بازسازی هیچ `restoreApply()` را صدا نمی‌زند، هیچ SQL نوشتنی/DDL/ایمپورتی صادر نمی‌کند (متنِ `DROP TABLE`/`INSERT` در dumpِ stage هرگز اجرا نمی‌شود)، هیچ ردیفِ `cpms_*`/options و هیچ ریشهٔ فعالِ بالینی را تغییر نمی‌دهد، و stagingِ مالک در موفقیت **و** در شکست (کلیدِ نادرست) پاک می‌شود. بکاپِ منبع در هر سه مسیر بایت‌به‌بایت دست‌نخورده و `ok_quick` می‌ماند.
- **مرزِ ایزولاسیونِ هدف (صریح):** این تست روی همان سازوکارِ ایزولهٔ **موجود** اجرا می‌شود (`WP_UnitTestCase` + `tests/integration-bootstrap.php` + MySQL 8 واقعیِ CI) و یک **هدفِ ایزولهٔ کرانه‌دار** است، **نه نصبِ تازهٔ وردپرس**: ریشه‌های store/scratch/stage/بالینیِ این تست خصوصیِ `0700` و بیرون از document root و در `tearDown` پاک می‌شوند و سرویسِ بازیابی به store‌ای مقید است که **قابلِ اثبات** فاقدِ آن بکاپ است؛ ولی خودِ نصبِ وردپرس و schemaی `cpms_*` همان نصبِ اشتراکیِ migration‌شدهٔ CI است.
- **کرانهٔ اشتراکیِ harness:** `createBackup()` طبق قراردادِ M-2 **نصب‌محور (installation-wide)** است، بنابراین روی harness اشتراکی ممکن است فایل‌های بالینیِ Clinic‌های دیگری هم (که settingsشان rollback شده و به ریشهٔ پیش‌فرضِ مستند حل می‌شوند) داخلِ همان بکاپِ واقعی باشند. به همین دلیل این تست **هرگز** شمارِ مطلقِ storage را ادعا نمی‌کند: دو فایلِ متعلقِ تست با containment و بایتِ دقیق اثبات می‌شوند، همهٔ شمارها از مانیفستِ واقعی مشتق می‌شوند، و شکلِ هر مسیرِ storage دقیقاً با آنچه `assert_relative()` و قاعدهٔ مسیرِ منطقیِ بازیابی می‌طلبند سنجه می‌شود.
