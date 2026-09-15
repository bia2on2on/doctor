# Settings Reference — CPMS (واحد و semantics هر Setting)

نسخه 1.5 | 2026-09-07 | جدول `cpms_settings` (کلید/مقدار JSON) + پیش‌فرض‌های `Settings::DEFAULTS`

> **تغییر 1.6 (آشتی نهایی فاز ۲ — 2026-09-15):** مالکیتِ سطحِ نصب برای کلیدهای **پذیرفته‌شده**
> به‌روز شد: `notif.archive_days` (ادغامِ مسیرِ #40)، `retention.oplog_days` (M-2) و پیکربندیِ
> بکاپ (`backup.enabled`, `backup.interval_hours`, `backup.keep_count`, `backup.storage_path`)
> به‌همراهِ حالتِ عملیاتیِ `backup.last_run_at` — همگی از `InstallationSettings` (Optionهای
> وردپرس، بدونِ autoload) خوانده/نوشته می‌شوند، نه `cpms_settings` یک Clinic؛ منبعِ اجرا:
> `src/Settings/InstallationSettings.php`. همچنین «محلیِ» ساعاتِ سکوت در مسیرهای قابل‌دسترسِ
> `appt.reminder`/`fu.reminder` = ساعتِ محلیِ **Location** صریحِ ردیفِ عملیاتی (نه کلینیک) —
> سطرهای مربوط را ببینید. **هیچ کلیدِ دیگری صرفاً به‌خاطرِ پیشوند مهاجرت/طبقه‌بندی نشد**
> (مالکیتِ کلیدهایی مثل `license.server_url` یا `queue.no_show_grace_minutes` در این به‌روزرسانی
> جابه‌جا نشده و همچنان قلمِ آشتیِ مستقلِ بعدی است).

> **تغییر 1.5 (F1-5 — Retention لاگ عملیاتی):** کلید جدید `retention.oplog_days` (پیش‌فرض `90` روز) — Job دوره‌ای `cleanup.oplog` ردیف‌های قدیمی‌تر از این سن را از `cpms_operational_logs` حذف می‌کند (رشد بی‌کران جدول hot). Audit مستقل است و `retention.audit_years` (۱۰ سال) دست‌نخورده می‌ماند.
>
> **به‌روزرسانی M-2 (سمتِ منبع و کران):** این کلید پیکربندی **سطح نصب** است و از `InstallationSettings::getOplogRetentionDays()` (Option وردپرس `cpms_retention_oplog_days`، بدونِ autoload، پیش‌فرض مؤثر `90`، مقدار خراب/کمتر از ۱ → همان `90`) خوانده می‌شود — نه از `cpms_settings` یک Clinic. هر اجرا حداکثر `500` ردیفِ واجدِ شرایط را حذف می‌کند و اجراهای تکرارشوندهٔ همان Job ادامهٔ پاک‌سازی را انجام می‌دهند. سایر کلیدهای `retention.*` از این تصمیم مستثناست.

> **تغییر 1.4 (F1-4 — Audit تنظیمات):** هر تغییر مؤثر Setting از مسیر `Settings::set()` اکنون با اکشن `SETTING_UPDATE` در Audit ثبت می‌شود — before/after (`{setting, value}`؛ before = مقدار مؤثر قبلی شامل Default) + actor (`updated_by` + نقش WP؛ بدون کاربر = `system`). تغییر no-op (مقدار جدید = مقدار مؤثر فعلی) Audit نمی‌گیرد. کلیدهای Runtime/telemetry (`jobs.last_tick_at`، `backup.last_run_at`، `sms.last_test`) — که به‌تکرار توسط سیستم نوشته می‌شوند — مستثنا هستند و در Operational Log ثبت می‌شوند (جلوگیری از سیل Audit ۱۰ساله). جزئیات: `docs/security/audit-strategy.md` §2. تست رگرسیون: `SettingsAuditTest`.

> **تغییر 1.3:** همگام‌سازی با کد (F3): `booking.cancel_deadline_hours` و `booking.reschedule_deadline_hours` از `12` به `24` (مطابق SRS FR-4.9/FR-4.10 و `Settings::DEFAULTS`).

> همه مقادیر زیر **Default/Seed** هستند (تصمیم کارفرما 2026-09-05) و از Settings قابل تغییرند (کاربر دارای `cpms_config`). **Hard-Code نیستند.**
> Secret/API Key در این جدول ذخیره نمی‌شود — فقط از `wp-config.php`/Environment (تصمیم F1-D3).

| کلید | Default | واحد | Semantics |
|---|---|---|---|
| `ui.calendar` | `jalali` | enum: `jalali\|gregorian` | تقویم **نمایشی** (Presentation فقط). ذخیره Backend همیشه Gregorian/UTC. |
| `otp.ttl_sec` | `120` | ثانیه | مدت اعتبار کد OTP (2 دقیقه). |
| `otp.max_attempts` | `5` | بار | حداکثر تلاش نادرست قبل از قفل (قفل: `otp.lockout_sec`). |
| `otp.cooldown_sec` | `60` | ثانیه | حداقل فاصله بین دو ارسال کد برای هر شماره. |
| `otp.daily_max` | `3` | کد/روز | حداکثر کد ارسالی در هر روز (بازه زمانی کلینیک) — ضد SMS Bombing. |
| `otp.lockout_sec` | `900` | ثانیه | مدت قفل بعد از 5 شکست (15 دقیقه). |
| `otp.hourly_max` | `10` | درخواست/ساعت | Rate Limit درخواست OTP (هر شماره + هر IP). |
| `booking.duration_default_min` | `20` | دقیقه | **پیش‌فرض کلینیک** مدت ویزیت (لایه 1 ADR-0017). |
| `booking.slot_capacity_default` | `1` | تعداد | ظرفیت پیش‌فرض هر Slot. |
| `booking.min_lead_hours` | `2` | ساعت | حداقل فاصله زمانی تا زودترین نوبت قابل رزرو. |
| `booking.max_future_days` | `60` | روز | افق حداکثری رزرو آنلاین. |
| `booking.cancel_deadline_hours` | `24` | ساعت | حداقل فاصله تا نوبت برای لغو آنلاین (Policy — FR-4.9). |
| `booking.reschedule_deadline_hours` | `24` | ساعت | حداقل فاصله تا نوبت برای جابه‌جایی آنلاین (FR-4.10). |
| `booking.hold_ttl_sec` | `600` | ثانیه | مهلت نگهداری Hold Slot در جریان رزرو آنلاین. |
| `booking.buffer_pre_default_min` | `0` | دقیقه | Buffer پیش از ویزیت (V2؛ V1 غیرفعال). |
| `booking.buffer_post_default_min` | `0` | دقیقه | Buffer بعد از ویزیت (V2؛ V1 غیرفعال). |
| `appt.reminder_before_hours` | `24` | ساعت | ارسال یادآوری نوبت (Job). |
| `queue.no_show_grace_minutes` | `30` | دقیقه | فاصله بعد از Slot برای علامت no-show خودکار. |
| `queue.auto_enqueue` | `true` | bool | ورود خودکار به صف بعد از Check-in. |
| `queue.max_recalls` | `3` | بار | حداکثر Recall یک Visit. |
| `jobs.default_max_attempts` | `3` | بار | پیش‌فرض Retry Jobها (تصمیم کارفرما). |
| `patient.profile_invoices_visible` | `false` | bool | نمایش فاکتور/رسید به بیمار (تصمیم D2). |
| `hw.local_retain` | `off` | enum: `off\|last\|always` | نگهداری Local دست‌خط در Tablet بعد از Sync (T-16). |
| `hw.autosave_sec` | `5` | ثانیه | فاصله Auto-save ویرایشگر دست‌خط. |
| `hw.version_keep` | `10` | عدد ≥1 | حداقل تعداد نسخه اخیر هر صفحه که GC (`handwriting.gc`) هرگز حذف نمی‌کند (ADR-0009). |
| `hw.version_max_age_days` | `30` | روز ≥1 | نسخه‌های قدیمی‌تر از این سن (و خارج از `hw.version_keep`) در جاب روزانه پاک‌سازی می‌شوند. |
| `files.storage_path` | `` | مسیر مطلق | پوشه ذخیرهفایلهای پزشکی فاز F5 — خالی = `wp-content/clinic-files/` (خارج uploads) با گارد `.htaccess` deny + `index.php`. توصیه file-storage.md: مسیر مطلق خارج DocumentRoot. تغییر در هر Request خوانده می‌شود (بدون کش). |
| `files.max_upload_bytes` | `10485760` | بایت | سقف حجم آپلود E16/C3 — پیشفرض 10 MB؛ اعملای سرور (F-3) با خطای `CLINIC_FILE_INVALID` 400. |
| `clinical.require_chief_complaint` | `true` | bool | الزام ثبت شکایت اصلی قبل از Complete (FR-8.7 — E14). |
| `notif.quiet_hours_start` | `08:00` | `HH:MM` محلی | شروع بازه ارسال SMS غیرتعاملی (یادآوری‌ها — F8 notifications §5؛ OTP مستثنا). **فاز ۲:** در مسیرهای قابل‌دسترسِ `appt.reminder`/`fu.reminder` این ساعتِ محلی از **تایم‌زونِ صریح و ماندگارِ Location** ردیفِ عملیاتی (`cpms_locations.timezone`) در همان لحظهٔ مرجعِ کنترل‌شدهٔ پردازشِ یادآوری محاسبه می‌شود — تایم‌زونِ کلینیک جایگزینِ Location نمی‌شود (فقط وقتی هیچ تایم‌زونِ صریحی داده نشود قراردادِ قدیمیِ کلینیک‌محور حفظ می‌شود). نبود/خرابیِ تایم‌زونِ Location پیش از ارزیابی، ردیف را به‌صورتِ شکستِ بسته رد می‌کند. |
| `notif.quiet_hours_end` | `21:00` | `HH:MM` محلی | پایان بازه Quiet Hours (پشتیبانی بازهٔ شب‌گذر/overnight). **فاز ۲:** منطقه‌زمانیِ مرجع در مسیرهای یادآوری = تایم‌زونِ صریحِ Location (نه کلینیک؛ جزئیات در سطرِ `notif.quiet_hours_start`). خودِ کلیدها همچنان در `cpms_settings` همان Clinic ذخیره می‌شوند — فقط منبعِ منطقه‌زمانی/لحظهٔ مرجعِ ارزیابی تغییر کرده است. |
| `notif.archive_days` | `90` | روز | Retention اعلان‌های Internal — حذف ارسال‌شده/خوانده‌شده‌های قدیمی در `notif.dispatch`. **به‌روزرسانی فاز ۲ (ادغام‌شده روی main):** این کلید **سطح نصب** است و از `InstallationSettings::getNotifArchiveDays()` (Option وردپرس `cpms_notif_archive_days`، بدونِ autoload، پیش‌فرض مؤثر `90`، مقدارِ خراب/زیرِ یک → `90`) خوانده می‌شود — نه از `cpms_settings` یک Clinic. |
| `reports.max_range_days` | `366` | روز | سقف بازه گزارش/Export — بزرگ‌تر → 422 `CLINIC_VALIDATION_FAILED`. |
| `reports.export_retention_days` | `7` | روز | نگهداری فایل Export قبل از حذف (فایل + ردیف؛ دانلود منقضی → 410 `CLINIC_EXPORT_EXPIRED`). |
| `reports.export_max_rows` | `10000` | ردیف | سقف ردیف‌های Export (async — performance-baseline §18). |
| `files.encrypt_at_rest` | `false` | bool | رمزنگاری هر-فایل (تصمیم D6؛ V1.5). |
| `retention.audit_years` | `10` | سال | نگهداری Audit (تابع قانون محل — D7). |
| `retention.record_years` | `15` | سال | نگهداری پرونده. |
| `sms.provider` | `` | string | id Provider فعال: `` = log (Dev/Staging)، `generic_api`، یا id Adapter ثبت‌شده (ADR-0025). |
| `sms.auth_method` | `` | enum: `api_key\|bearer\|username_password` | روش Authentication پنل (مطابق `authMethods()` Adapter). |
| `sms.auth` | `{}` | JSON (رمزنگاری‌شده) | **Vault Credentials** (AES-256-GCM): `{method, fields:{<field>:{sealed, last4}}}`. plaintext هرگز اینجا/در Repo/در REST نیست. |
| `sms.sender` | `` | string (≤20) | شماره ارسال (Sender) — فقط اگر Provider پشتیبانی کند. |
| `sms.advanced.timeout_sec` | `5` | ثانیه (1–30) | Timeout اجباری Call به API Provider. |
| `sms.advanced.retry_count` | `3` | بار (1–10) | حداکثر Attempt هر Message (فقط خطاهای Retryable). |
| `sms.templates` | `{}` | JSON per-event | `{event: {template_id, updated_at}}` — Template/Pattern پنل برای هر رویداد (خالی = متن پیش‌فرض داخلی). |
| `sms.generic.*` | … | JSON | تنظیمات Generic API Provider: `endpoint`, `http_method` (GET/POST), `auth_header`, `auth_format` (`{key}`), `request_json` (Template با `{mobile} {message} {template_id} {vars} {sender}`), `response.{success_field,success_values,id_field,error_field}`, `extra_headers`. **بدون Code/eval + SSRF Guard.** |
| `sms.last_test` | `{}` | JSON | `{status: ok\|failed, at, provider, message}` — برای وضعیت `VERIFIED`/`ERROR` (بدون Secret). |
| `rt.poll_sec_secretary` | `3` | ثانیه | بازه Polling داشبورد منشی. |
| `rt.poll_sec_doctor` | `5` | ثانیه | بازه Polling داشبورد پزشک. |

## Timezone (قانون سفت — ADR-0013)
- **ذخیره:** همه Timestampها `DATETIME(3)` در **UTC**.
- **Slot time:** `TIME` محلی کلینیک (Slot ذاتاً محلی است؛ `clinics.timezone = Asia/Tehran`).
- **نمایش:** تبدیل UTC → timezone کلینیک → Jalali (فقط Presentation Layer).
- **Jobها:** مقایسه‌ها با `now_utc` + آستانه‌های محلی محاسبه‌شده در PHP. **فاز ۲ (ادغام `35acced`):** در `appt.reminder`/`fu.reminder` حقیقتِ زمانیِ عملیاتی = **تایم‌زونِ صریح و ماندگارِ Location ردیف** (`cpms_locations.timezone`) در همان لحظهٔ مرجعِ کنترل‌شدهٔ پردازش؛ تایم‌زونِ کلینیک جایگزینِ Location نمی‌شود و تایم‌زونِ فرایند/وردپرس/سرور کنترل‌کننده نیست. نبود/خرابیِ تایم‌زونِ Location در آن مسیرها پیش از ارزیابیِ ساعاتِ سکوت به‌صورتِ شکستِ بسته رد می‌شود. این تکمیلِ فاز ۲ است، نه ادعای تکمیلِ زمان‌بندیِ فازهای بعدی.

## SMS — Provider-Agnostic (ADR-0025)
- **Business Logic ← `SmsService` ← `SmsProviderInterface`** — هیچ وابستگی به Provider خاص در Core (تفکیک §8/§25 الزامات پیامک).
- **Events اولجه (Domain واحد):** `otp`, `appointment_confirmed`, `appointment_reminder`, `appointment_cancelled`, `appointment_rescheduled`, `follow_up_reminder` (Registry باز).
- **متغیرهای داخلی:** `otp_code, patient_name, doctor_name, appointment_date, appointment_time, clinic_name` — Mapping به فرمت Provider فقط در Adapter.
- **امنیت Credential:** Vault AES-256-GCM (کلید: Env `CPMS_SECRET_KEY` یا Salt نصب)؛ UI فقط `••••••••abcd`؛ REST هرگز plaintext؛ Log/Audit/Diagnostic Mask.
- **امنیت Generic API:** SSRF Guard (IP خصوصی/Loopback مسدود) + بدون Code/eval + Timeout اجباری.
- **OTP:** کد خام **هرگز** در جدول پیام‌ها ذخیره نمی‌شود (متن Mask + بدون vars)؛ بدون Queue-Retry برای OTP.

## F10 — مجوز / بکاپ / بهروزرسانی (کلیدهای جدید 2026-09-06)

| کلید | پیشفرض | نوع | توضیح |
|---|---|---|---|
| `license.server_url` | `''` | string (https) | آدرس سرور لایسنس/بهروزرسانی فروشنده (کنترلپلین؛ ADR-0028). خالی = فعالسازی آفلاین/دستی با سند امضاشده |
| `backup.enabled` | `false` | bool | بکاپ دورهای (Job `backup.run`) — فعالسازی آگاهانه |
| `backup.interval_hours` | `24` | int | فاصله بکاپ دورهای |
| `backup.keep_count` | `14` | int | Retention: نگهداری N نسخه آخر (V1 بدون Tiering) |
| `backup.storage_path` | `''` | string | مسیر مطلق مخزن بکاپ؛ خالی = `{WP_CONTENT_DIR}/cpms-backups` (گارد سرور خودکار) |
| `backup.last_run_at` | `0` | int (ts) | آخرین بکاپ موفق (نوشتهشده توسط Job/دستی) |
| `update.check_interval_hours` | `24` | int | TTL کش بررسی بهروزرسانی (transient) |
| `update.channel` | `stable` | enum stable\|beta | کانال انتشار |

> **به‌روزرسانی مالکیت فاز ۲ (2026-09-15 — فقط کلیدهای پذیرفته‌شده):** کلیدهای پیکربندیِ بکاپ —
> `backup.enabled`، `backup.interval_hours`، `backup.keep_count` و `backup.storage_path` —
> **تنظیمات سطح نصب** هستند و از `InstallationSettings` (Optionهای وردپرس؛ پیش‌فرض‌ها و
> کران‌های همین جدول) خوانده/نوشته می‌شوند، نه از `cpms_settings` یک Clinic؛
> `backup.last_run_at` **حالتِ عملیاتیِ سطح نصب** است که توسط مسیرِ `backup.run`/دستی
> نوشته می‌شود. منبعِ اجرا: `src/Settings/InstallationSettings.php` +
> `BackupRunHandler`/`SystemPage`. مالکیتِ `license.server_url` و `update.*` در این
> به‌روزرسانی جابه‌جا **نشده** و جزو اقلامِ کنترلی/آشتیِ مستقل باقی می‌ماند.

> **حالت توسعه/تست مجوز (نه یک Setting):** ثابت `CPMS_DEV_MODE` در `wp-config.php`
> یا فیلتر `cpms_license_dev_mode` → وضعیت `DEVELOPMENT` (فعالیت باز، برچسب
> «🧪 DEVELOPMENT» در Admin). فقط مکانیسم صریح — هیچ تشخیص خودکارِ
> environment/دامنه/localhost برای دور زدن مجوز وجود ندارد (ADR-0023).
>
> **پنجره‌های فعال‌سازی (نه Setting):** شروع و نوع پنجره (`fresh` ۷ روز /
> `migration` ۳۰ روز) در `cpms_license_install` (Migration 0008) persist
> می‌شود — کلید تنظیمی ندارد و UI قابلیت Reset ندارد (تصمیم کارفرما 2026-09-06).

## Secrets (نظم)
| Secret | محل | ممنوع |
|---|---|---|
| `CPMS_PEPPER` (هش OTP/Hash IP) | `wp-config.php` / Env | ❌ repository، ❌ wp_options |
| SMS پنل (API Key/Token/Password) | `cpms_settings.sms.auth` — **فقط Ciphertext AES-256-GCM (Vault، ADR-0025)**؛ کلید Vault: Env `CPMS_SECRET_KEY` یا Salt نصب | ❌ plaintext در settings، ❌ repository، ❌ log، ❌ REST، ❌ Audit |
| OCR API Key (V1.5) | `wp-config.php` / Env | ❌ جدول settings |
| Backup Key | خارج سرور | ❌ روی سرور |
