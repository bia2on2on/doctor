# ADR-0025 — Provider-Agnostic SMS Architecture

تاریخ: 2026-09-05 | وضعیت: **تأیید نهایی کارفرما (2026-09-05)** | مستقیماً از الزامات کارفرما (SMS Settings & Provider Management v1.0)

## تأیید نهایی کارفرما (2026-09-05)

1. ✅ **Capability:** `cpms_sms_config` (به‌جای `clinic_manage_sms_settings`) — تأیید شد؛ Naming Convention فعلی پروژه حفظ می‌شود.
2. ✅ **Fast-path OTP بدون Queue-Retry** — تأیید شد، **مشروط بر** (همه موارد در پیاده‌سازی برقرار و تست‌شده):

| شرط | برقراری |
|---|---|
| OTP خام Persist یا Log نشود | ✅ متن رکورد OTP با `***` Mask + `vars_json=NULL`؛ OpLog Mask (مگر `CPMS_DEV_OTP_ECHO` در Dev)؛ Audit بدون کد. تست: `SmsFlowTest::testOtpRequestCreatesSentMessageRow` |
| ارسال OTP دارای Timeout مشخص باشد | ✅ Timeout اجباری از `sms.advanced.timeout_sec` (پیش‌فرض **5s**، clamp 1–30) — اعمال در `$opts` هر Provider (Generic: `stream_context timeout`) |
| Failure به‌صورت امن مدیریت شود | ✅ شکست → Record `FAILED` + OpLog `SMS_FAILED` + Audit `OTP_SENT_FAIL`؛ Token معتبر می‌ماند؛ Session ایجاد نمی‌شود؛ کاربر می‌تواند (در چارچوب Cooldown/لیمیت) کد جدید بخواند |
| Rate Limit / Expiration / Attempts رعایت شوند | ✅ بدون تغییر: `otp.daily_max`/`otp.hourly_max`/IP limit + Cooldown + TTL + 5 attempts/Lockout (OtpService) |
| عدم Retry باعث Bypass نشود | ✅ عدم ارسال ≠ دسترسی: `verify()` همچنان کد معتبر+مصرف‌نشده+غیرمنقضی می‌خواهد؛ Rate Limiter حتی در شکست‌های ارسال هم شمارش می‌کند (ضد SMS-Bombing/Enumeration) |
| Generation/Verification مستقل از Provider | ✅ `OtpPolicy` (generateCode/hashCode/verify) Domain خالص است؛ Provider فقط Delivery؛ تغییر Provider هیچ اثری روی امنیت OTP ندارد |

## زمینه (Context)

- F2 (OTP) با یک `SmsGateway` ساده (log/http) ساخته شد؛ الزام جدید کارفرما ماژول کامل «تنظیمات پیامک» می‌خواهد: انتخاب Provider از Settings، Authentication چندگانه، Template/Pattern First-Class، Test Connection/SMS/Template، Queue با Statusها، Retry هوشمند، Log عملیاتی، Balance، Multi-Provider-Ready.
- Engineering Baseline v1.0: Jobها برای SMS، بدون Call در Page Load، Secrets خارج از Repo/wp_options-در-زیر، SSRF/Code-Execution ممنوع.
- تصمیم D2: نام‌گذاری Capability به شکل `cpms_{resource}_{action}`.

## تصمیم (Decision)

1. **لایه‌بندی سه‌مرحله‌ای (Business ← Service ← Provider):**
   - Business Logic (OtpService, F3+ Booking/Reminders) فقط `SmsService::sendEvent(event, mobile, vars, context)` را صدا می‌زند.
   - `SmsService` (Application/Notifications): Template Resolution + Variable Validation + Message Record (جدول `cpms_sms_messages`) + Dedupe + Queue + Retry Policy + Status Mapping.
   - `SmsProviderInterface` (Infrastructure/Sms): Contract Adapter. هر Provider: `capabilities()` + `authMethods()` + `authFields()` + `testConnection()` + `sendText()` + `sendTemplate()` + `mapStatus()`.
   - **Business Logic به هیچ Provider خاصی وابسته نیست.** Provider جدید = کلاس Adapter + ثبت در Registry (Hook `cpms_sms_provider`) — بدون تغییر Core.

2. **Registry + Adapterهای داخلی:**
   - `SmsProviderRegistry` — registration داخلی (log، generic_api) + `do_action('cpms_sms_provider', $register)` برای پلاگین‌های Adapter سوم‌ساز.
   - `LogSmsProvider` (Dev/Test) + `GenericApiSmsProvider` (اتصال پنل‌های ناشناخته با Request/Response Mapping — بدون PHP/eval، با **SSRF Guard**).

3. **Template/Pattern First-Class (Domain معنای واحد = «SMS Template»):**
   - Events داخلی: `otp`, `appointment_confirmed`, `appointment_reminder`, `appointment_cancelled`, `appointment_rescheduled`, `follow_up_reminder` (Registry باز برای Event جدید).
   - هر Event: `variables` (مجاز) + `required` + `default_text` (فارسی، با `{{var}}`).
   - **Variable Mapping داخلی → فرمت Provider کاملاً وظیفه Adapter است** (سند `docs/sms/adding-provider.md`) — وارد Business Logic نمی‌شود.
   - Validation قبل از ارسال: Template ID موجود + Required Variables Map شده + Provider از Template پشتیبانی می‌کند (وگرنه فالبک به `default_text` اعلان‌شده).

4. **Credentials Secure Storage:**
   - **AES-256-GCM** — کلید از `CPMS_SECRET_KEY` (Env) یا مشتق‌شده از Salt‌های نصب وردپرس (منحصربه‌فرد per-installation) — **هیچ Secret در Repo یا wp_options به‌صورت plaintext نیست** (قاعده D3 + Baseline §9).
   - ذخیره در `cpms_settings` (`sms.auth`): فقط Ciphertext + Nonce + Tag + `last4`.
   - باز کردن Settings = نمایش Placeholder `••••••••abcd` — مقدار قبلی هرگز به Browser بازمی‌گردد **مگر** User مقدار جدید بزند یا Action صریح «حذف کلید» بزند.
   - Log/Diagnostic/Audit: همیشه Mask. REST: هیچ وقت plaintext.

5. **Queue + Statusها + Retry (Baseline §2/§30، الزام §19/§20):**
   - جدول `cpms_sms_messages` (Migration 0003): Status `QUEUED/SENDING/SENT/DELIVERED/FAILED/RETRYING` + `attempts` + `provider_msg_id` + `failure_code` + `dedupe_key` (UNIQUE).
   - Workflow: Event → SmsService → Record (QUEUED) → Job `sms.send` → Provider.
   - **Fast-path برای OTP:** ارسال Inline (Timeout کوتاه) — و **بدون Queue-Retry**: از آنجا که کد OTP هرگز ذخیره نمی‌شود (بخش 6)، Retry پیام از Queue به‌معنای ارسال کد جدید است؛ بنابراین در شکست، Record با `FAILED/CLINIC_SMS_OTP_NO_RETRY` می‌ماند و **کاربر درخواست کد جدید می‌دهد**. (برائت: کد OTP نباید تا 60 ثانیه منتظر tick بعدی بماند؛ سایر Events خالص Queue‌ای هستند.)
   - **Retry فقط برای خطاهای Retryable** (Network/Timeout/5xx/429/Temporary) — **بدون Blind Retry** برای Invalid Mobile/Template/Credentials.
   - **Dedupe:** `dedupe_key` بر پایه (event + context + روز) — Double-Click و Retry مجدد ارسال تکراری نمی‌کند (Re-send پس از FAILED = نسل جدید).

6. **OTP مستقل از Provider (الزام §25) + بدون ذخیره کد خام (Baseline §8):**
   - `OtpService` همچون قبل Generate/Hash/Expire/Rate-Limit/Attempt/Verify را دارد.
   - Provider فقط Delivery است؛ تغییر Provider هیچ اثری روی امنیت OTP ندارد.
   - **کد OTP هرگز در `cpms_sms_messages` ذخیره نمی‌شود:** متن رکورد OTP با `***` Mask است و `vars_json` برای OTP خالی است؛ کد فقط در لحظه Dispatch Inline به‌صورت Live به Provider می‌رود.

7. **Multi-Provider-Ready بدون Redesign:**
   - V1: یک Active Provider (`sms.provider`).
   - Architecture: Provider بر اساس `id` از Registry resolve می‌شود + Message Row `provider` را ذخیره می‌کند → Failover آینده = Setting `sms.fallback_provider` + انتخاب در SmsService — بدون تغییر Core/DB.
   - Failover **نباید** در خطاهای Permanent (Invalid Mobile و…) فعال شود (طبق الزام §24).

8. **Performance (الزام §30 + Baseline §2):**
   - هیچ Call به API Provider در Page Load عمومی/Admin نیست — فقط در Actionهای صریح (Test/Send) یا در Job.
   - Provider Metadata Cache در-request. Timeout اجباری (Settings، پیش‌فرض 5s).

9. **Capability (متناسب با D2/Baseline §4):**
   - الزام کارفرما `clinic_manage_sms_settings` را پیشنهاد می‌داد؛ با توجه به **تصمیم تأییدشده D2** (نام‌گذاری `cpms_{resource}_{action}` و نه `clinic_*`)، اسلگ نهایی: **`cpms_sms_config`**.
   - (این یک تطبیق نام‌گذاری است، نه تضاد با الزام — خود الزام هم می‌گوید «نمونه».)
   - اهدا: نقش‌های مطب V1: **هیچ** (تنظیمات فنی). WP Administrator: ✅ (حوزه فنی — مطابق D2، بدون Medical). منشی/پزشک: ❌ (اعطای موردی در صورت نیاز).

10. **Audit (الزام §29):** `SMS_PROVIDER_CHANGED`, `SMS_CREDENTIAL_UPDATED`, `SMS_TEMPLATE_CHANGED`, `SMS_TEST_SENT`, `SMS_CONNECTION_TESTED` — بدون هیچ مقدار Credential/OTP.

## Impact Analysis (کوتاه — طبق الزام §33)

| حوزه | اثر |
|---|---|
| کد | Refactor کوچک F2: `OtpService` از `SmsGateway` مستقیم به `SmsService` تغییر می‌کند (رفتار و تست‌ها حفظ می‌شوند). حذف 5 فایل Gateway قدیمی، افزودن ~20 فایل ماژول SMS. **هیچ بخش Core دیگری دست نمی‌خورد.** |
| Database | +1 جدول (`cpms_sms_messages`) — Migration 0003 تفریقی؛ بدون تغییر جدولهای موجود. |
| امنیت | Vault (AES-256-GCM)، SSRF Guard، Mask همه‌جایی، CSRF/Nonce (REST کوکی‌ای)، Capability جدید. OTP Security بدون تغییر. |
| Performance | بدون Call در Page Load؛ همه‌چیز در REST صریح/Job. |
| Licensing/Queue/Notifications | هماهنگ: Job Queue F1 (ADR-0016)؛ Notification Architecture = `SmsService` (پایه برای Email/V1.5). |
| Conflict با تصمیمات قبلی | **هیچ تضاد Bloکسندری** — فقط تطبیق نام Capability با D2 (مستند شد) و Fast-path OTP (در ADR-0016 هم همین الگوریتم تایید شده بود). |

## Conssequences

- مثبت: تعویض/افزودن Provider بدون تغییر Core؛ Test/Log/Balance عملیاتی؛ Dedupe+Retry هوشمند.
- منفی/ریسک: پیچیدگی Settings (با UX ساده §26 جبران می‌شود)؛ Generic Provider = سطح حمله بالاتر (SSRF Guard + کد-execution ممنوع + Timeout).
- قابل بازگشت: Adapterها مستقل‌اند؛ حذف ماژول = حذف Job + Endpoint + جدول (بدون اثر روی Bیزنس).
