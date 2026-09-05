# Settings Reference — CPMS (واحد و semantics هر Setting)

نسخه 1.3 | 2026-09-05 | جدول `cpms_settings` (کلید/مقدار JSON) + پیش‌فرض‌های `Settings::DEFAULTS`

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
| `files.max_upload_bytes` | `10485760` | بایت | حداکثر حجم فایل پزشکی (10 MB). |
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
- **Jobها:** مقایسه‌ها با `now_utc` + آستانه‌های محلی محاسبه‌شده در PHP.

## SMS — Provider-Agnostic (ADR-0025)
- **Business Logic ← `SmsService` ← `SmsProviderInterface`** — هیچ وابستگی به Provider خاص در Core (تفکیک §8/§25 الزامات پیامک).
- **Events اولجه (Domain واحد):** `otp`, `appointment_confirmed`, `appointment_reminder`, `appointment_cancelled`, `appointment_rescheduled`, `follow_up_reminder` (Registry باز).
- **متغیرهای داخلی:** `otp_code, patient_name, doctor_name, appointment_date, appointment_time, clinic_name` — Mapping به فرمت Provider فقط در Adapter.
- **امنیت Credential:** Vault AES-256-GCM (کلید: Env `CPMS_SECRET_KEY` یا Salt نصب)؛ UI فقط `••••••••abcd`؛ REST هرگز plaintext؛ Log/Audit/Diagnostic Mask.
- **امنیت Generic API:** SSRF Guard (IP خصوصی/Loopback مسدود) + بدون Code/eval + Timeout اجباری.
- **OTP:** کد خام **هرگز** در جدول پیام‌ها ذخیره نمی‌شود (متن Mask + بدون vars)؛ بدون Queue-Retry برای OTP.

## Secrets (نظم)
| Secret | محل | ممنوع |
|---|---|---|
| `CPMS_PEPPER` (هش OTP/Hash IP) | `wp-config.php` / Env | ❌ repository، ❌ wp_options |
| SMS پنل (API Key/Token/Password) | `cpms_settings.sms.auth` — **فقط Ciphertext AES-256-GCM (Vault، ADR-0025)**؛ کلید Vault: Env `CPMS_SECRET_KEY` یا Salt نصب | ❌ plaintext در settings، ❌ repository، ❌ log، ❌ REST، ❌ Audit |
| OCR API Key (V1.5) | `wp-config.php` / Env | ❌ جدول settings |
| Backup Key | خارج سرور | ❌ روی سرور |
