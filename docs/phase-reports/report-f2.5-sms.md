# گزارش فاز F2.5 — ماژول پیامک Provider-Agnostic (SMS Settings & Provider Management)

تاریخ: 2026-09-05 | وضعیت: **تأییدشده (Approved 2026-09-05)** | مرجع: ADR-0025 (تأیید نهایی) + الزامات کارفرما (SMS v1.0)

> **تأیید کارفرما:** (1) `cpms_sms_config` — Naming Convention پروژه حفظ شد. (2) Fast-path OTP بدون Queue-Retry با **شش شرط** — همه برقرار و مستند در ADR-0025 (جدول شرط‌ها + شواهد تست).

---

## 1. خلاصه

ماژول «تنظیمات پیامک» طبق سند کارفرما پیاده شد: مدیر مجاز **بدون تغییر کد** پنل را تنظیم، تست و مدیریت می‌کند و Business Logic به هیچ Provider خاصی وابسته نیست.

**تست:** `OK (187 tests, 346 assertions)` — ۳ اجرای پشت‌سرهم سبز (+30 تست Unit جدید؛ `SmsFlowTest` Integration در CI).

## 2. ماتریس الزامات (سند SMS v1.0)

| § | الزام | وضعیت | پیاده‌سازی |
|---|---|:---:|---|
| 1 | بخش Settings → SMS + Capability مستقل | ✅ | `SmsSettingsPage` + **`cpms_sms_config`** (Matrix v1.2) |
| 2 | انتخاب Provider + معماری Adapter/Plugin-Based | ✅ | `SmsProviderRegistry` + Hook `cpms_sms_provider` |
| 3 | Authentication Methods چندگانه؛ UI فقط روش‌های پشتیبانی‌شده | ✅ | `authMethods()`/`authFields()` در Contract؛ UI داینامیک |
| 4 | امنیت Credentials (نه در Source/Git/HTML/Log/Diagnostic/REST) | ✅ | **Vault AES-256-GCM** + Placeholder `••••••••abcd` + REST Mask |
| 5 | تست اتصال + نتیجه Human-Friendly | ✅ | `testConnection()` + `sms.last_test` |
| 6 | ارسال پیامک آزمایشی + Provider Message ID | ✅ | `POST /sms/test-send` |
| 7 | Sender Number (Fetch/Manual) | ✅ | `fetchSenders()` در Contract + فیلد Sender |
| 8 | `sendText` در Interface؛ Business Logic از **SmsService** | ✅ | `SmsService::sendEvent()` — OtpService فقط همین را صدا می‌زند |
| 9 | Pattern/Template First-Class (معنای واحد = SMS Template) | ✅ | `SmsEvents` + `SmsTemplateRenderer` |
| 10 | Template ↔ Events داخلی (6 رویداد + Registry باز) | ✅ | `SmsEvents::all()` |
| 11 | تنظیمات Template در Settings | ✅ | `sms.templates` + `POST /sms/templates` |
| 12 | Variable Mapping داخلی | ✅ | `SmsEvents::VARIABLES` + Validation مجاز/الزامی |
| 13 | تبدیل متغیرها وظیفه **Adapter** (نه Business Logic) | ✅ | `sendTemplate(vars)` در Contract + مستند `docs/sms/adding-provider.md` §3 |
| 14 | تست الگو (Preview + ارسال؛ بدون داده واقعی بیمار) | ✅ | `POST /sms/templates/test` + `preview()` |
| 15 | Validation قبل از فعال‌سازی Template | ✅ | `validateTemplate()` + `SMS_TEMPLATE_NOT_SUPPORTED` |
| 16 | اعلام Capabilityها توسط Adapter؛ UI بر اساس آن | ✅ | `capabilities()` → UI داینامیک |
| 17 | Generic API Provider (Mapping) + بدون PHP/eval + SSRF + Endpoint Validation | ✅ | `GenericApiSmsProvider` + `SsrfGuard` + اسکن Template |
| 18 | Advanced Settings (Timeout/Retry) با پیش‌فرض امن | ✅ | `sms.advanced` (clamp 1–30 / 1–10) |
| 19 | Queue + Statusها (QUEUED..RETRYING) | ✅ | `cpms_sms_messages` (Migration 0003) + Job `sms.send` |
| 20 | Retry هوشمند + بدون Blind Retry + **Duplicate SMS Prevention** | ✅ | `RetryClassifier` + `dedupe_key` UNIQUE |
| 21 | Delivery Report + Provider Message ID + Status Mapping | ✅ | `mapStatus()` + `provider_msg_id` (Sync تحویل: V1.5) |
| 22 | SMS Log (Mask، بدون OTP/Credential، Pagination) | ✅ | `GET /sms/logs` |
| 23 | Balance (Optional/Capability-Based) | ✅ | `GET /sms/balance` + `fetchBalance()` |
| 24 | Multi-Provider-Ready (V1: یک Active؛ بدون Redesign) | ✅ | `provider` در هر Message + resolve از Registry |
| 25 | **OTP مستقل از Provider + کد خام ذخیره نمی‌شود** | ✅ | OtpService بدون تغییر امنیتی؛ رکورد OTP Mask + بدون vars + بدون Queue-Retry |
| 26 | UX غیرتکنیکال | ✅ | صفحه RTL + جملات فارسی + Status Banner |
| 27 | Validation پیش از Save + Secret خالی = حفظ قبلی + حذف صریح | ✅ | `__CLEAR__` + تست `testSaveSettingsKeepsStoredCredentialWhenInputEmpty` |
| 28 | وضعیت Configuration (4 حالت) | ✅ | `status()` + Health قابل اتصال |
| 29 | Audit عملیات حساس (بدون مقدارها) | ✅ | `SMS_PROVIDER_CHANGED` و ۴ رویداد دیگر |
| 30 | بدون Call در Page Load + Cache + Timeout | ✅ | فقط REST صریح/Job |
| 31 | Developer Contract | ✅ | `docs/sms/adding-provider.md` |
| 32 | Dev Mode = `log` | ✅ | `LogSmsProvider` پیش‌فرض |
| 33 | هماهنگی با Baseline/Matrix/Queue/Licensing + Impact + ADR | ✅ | ADR-0025 (با Impact Analysis) |

**33/33 سبز.**

## 3. تغییرات (اختلاف با F2)

| مورد | F2 | F2.5 |
|---|---|---|
| لایه ارسال | `SmsGateway` (log/http) — OtpService مستقیم | **`SmsService`** (Application) ← **`SmsProviderInterface`** (Infrastructure) |
| Providerها | 2 کلاس ثابت | **Registry** + `log` + `generic_api` + Hook افزونه‌پذیری |
| Templates | متن ثابت در OtpService | **First-Class**: 6 رویداد + متغیرها + Template ID پنل + Validation + تست |
| Queue | `sms.send` (payload خام) | `cpms_sms_messages` (6 Status) + Dedupe + Retry هوشمند + Attempt Tracking |
| Credentials | Env فقط | **Vault AES-256-GCM** در Settings (Ciphertext) + Placeholder + حذف صریح |
| REST | 2 Endpoint OTP | **+10 Endpoint** (SM-1..SM-10) |
| Admin | صفحه وضعیت | **+ صفحه تنظیمات پیامک** (تنظیم/تست/الگو/Log/اعتبار) |
| Capability | 45 | **46** (`cpms_sms_config`) |
| Database | 39 جدول | **40** (Migration 0003: `cpms_sms_messages`) |

## 4. Sؤالات/مغایرت‌های ثبت‌شده

1. ✅ **نام Capability (تأییدشده 2026-09-05):** `cpms_sms_config` — Naming Convention فعلی پروژه حفظ شد.
2. ✅ **OTP + Queue (تأییدشده 2026-09-05 با 6 شرط):** برای OTP: **Inline بدون Queue-Retry** — شرط‌ها (Timeout، بدون Persist/Log، Failure امن، Rate/Expiry/Attempts، بدون Bypass، استقلال Generation/Verification) در ADR-0025 چک‌لیست‌شده و تست‌شده‌اند. سایر رویدادها خالص Queue‌ای.
3. **تصمیمات باز F2 (C1/C2/M1/M4/M5)** در انتظار نهایی‌سازی کارفرما؛ این ماژول با **هر دو** گزینه‌ی Repository/Non-Repository و با کدهای فعلی/`CLINIC_*` سازگار است (بدون نیاز به Rework).

## 5. گام بعدی

**F3 (Booking)** با الزامات ثبت‌شده در Baseline Review:
- **LicenseGate Seam** در سرویس‌های ساخت (آمادگی F10)
- Repository از کد جدید (در صورت تأیید C2)
- Correlation ID در Log
