# گزارش فاز F2 — احراز هویت موبایل + OTP (بخش P0 + اعمال تصمیمات D1–D4)

تاریخ: 2026-09-05 | وضعیت: **P0 کامل — در انتظار بازبینی** | محیط: PHP 8.4 / MySQL / WordPress 6.x

---

## 1. خلاصه

F2 در سه لایه انجام شد:
1. **اعمال تصمیمات نهایی F1 (D1–D4)** — مستندسازی ADR + Matrix + Settings + Migration 0002 + Capabilities نهایی.
2. **هسته OTP:** OtpService (Request/Verify)، SMS Gateway قابل تعویض، Session + ساخت/لینک کاربر.
3. **عملیات Queue (D1):** GET_LOCK برای Runner، Health Check Stale، `jobs list/retry` در CLI، نمایش Jobهای شکست‌خورده در Settings Admin.

**تست:** `OK (157 tests, 232 assertions)` — ۳ اجرای پشت‌سرهم سبز (قبل: 122/189).
تست‌های Integration (`OtpFlowTest`) در CI اجرا می‌شوند.

---

## 2. ماتریس Acceptance Criteria

| AC | معیار | وضعیت | شواهد |
|---|---|:---:|---|
| A2 | `POST /otp/request` — موبایل → Normalize + Valid + Rate Limit (day/hour/ip) + Policy (cooldown/daily/lock) + Token (فقط Hash) + SMS (Sync + Retry Job) | ✅ | `OtpController`, `OtpService::request`, `OtpFlowTest` |
| A3 | `POST /otp/verify` — کد ۶ رقمی → Hash Compare (timing-safe) → تک‌بار (consumed_at) → ساخت کاربر `cpms_patient` / لینک بیمار موجود → **Session** (`wp_set_auth_cookie`) | ✅ | `OtpService::verify`, `OtpFlowTest` |
| TP-05 | Rate Limit: 3/روز، 10/ساعت، IP Limit + `COOLDOWN`/`DAILY_LIMIT`/`RATE_LIMITED` | ✅ | `OtpFlowTest::testDailyLimitBlocksNewRequests`, `RateLimiterTest` |
| TP-17 | کد خام هیچ‌جا ذخیره/لاگ/انتقال نمی‌شود (فقط `SHA-256(code+pepper)`؛ Audit بدون کد) | ✅ | `OtpFlowTest::testRequestCreatesHashedTokenNotRawCode` + `testAuditEventsRecordedWithoutCode` |
| TP-07 | Idempotency/Lock: مصرف تک‌بار Token به‌صورت Atomic (`consumed_at IS NULL`) | ✅ | `OtpFlowTest::testSecondUseOfSameCodeRejected` |
| — | ارسال شکست‌خورده → Job `sms.send` (priority 8, max 3) بدون Block Request | ✅ | `SmsDispatchHandler`, `OtpService::request` |
| — | قفل بعد از 5 تلاش اشتباه (15 دقیقه) + `OTP_LOCKED` | ✅ | `OtpFlowTest::testVerifyWrongCodeIncrementsAttemptsThenLocks` |
| D1 | GET_LOCK Runner (بدون Duplicate) + Health Stale (>5min) + `jobs list/retry` + نمایش Failed در Admin | ✅ | `bin/cpms`, `App::queueHealth`, `SettingsAdmin`, ADR-0016 |
| D2 | 45 Capability نهایی (قبل از پیاده‌سازی در Matrix v1.1) + نقش‌ها مطابق Matrix | ✅ | `RolesAndCapabilities`, `permission-matrix.md` |
| D3 | DEFAULTS جدید در Settings (TTL 120s, Lead 2h, 60d, 12h, 3 attempts, 10MB, Jalali, …) | ✅ | `Settings::DEFAULTS`, `settings-reference.md` |
| D4 | Migration 0002 (`duration_min`, `slot_end_time`, buffers آماده) + DurationResolver 4 لایه | ✅ | Migration 0002, `DurationResolver`, `DurationResolverTest` (TP-21) |

**نتیجه: 11/11 سبز.**

---

## 3. تغییرات کلیدی (اختلاف با F1)

| مورد | F1 | F2 (الان) |
|---|---|---|
| Capability | 30 slug (شامل `cpms_manage_clinic`) | **45 slug** — بدون Capability کلی؛ `permission-matrix v1.1` مرجع است |
| `cpms_appointments` | بدون مدت | **+ `duration_min` (20) + `slot_end_time`** (Snapshot) |
| `cpms_schedule` / `cpms_services` | — | **+ buffer_pre/post_min** / **+ duration_min** (آماده V2/Override) |
| Settings | TTL 300s, max advance 30d, lead 24h, 5 attempts, 20MB | **TTL 120s, 60d, lead 2h + deadlines 12h, 3 attempts, 10MB**, `ui.calendar=jalali`, `appt.reminder_before_hours=24` |
| Queue runner | Tick بدون Lock | **GET_LOCK** + `jobs.last_tick_at` + Health Stale |
| CLI | tick/audit/slots/status | **+ `jobs list` + `jobs retry <id>` + `health`** |
| Endpoint | `GET /health` | **+ `POST /otp/request` + `POST /otp/verify`** |
| SMS | — | `SmsGateway` + `LogSmsGateway` (Dev) + `HttpSmsGateway` + Factory از Settings |

---

## 4. سؤالات باز برای کارفرما

1. **Provider SMS:** `HttpSmsGateway` قرارداد عمومی دارد (POST JSON `{to, message}`). نام Provider نهایی (مثلاً کاسپین/پارس‌پیام/…) برای نوشتن Adapter اختصاصی و Test نیاز است. **تا آن زمان `sms.provider=log` (Dev) فعال است.**
2. **حساب کاربری اولیه مدیر:** اولین منشی/پزشک چگونه ساخته می‌شود؟ (پیشنهاد: `bin/cpms admin create <user> <role>` در F3 — یا دستی از WP Admin).
3. **Email برای reset password:** خارج از Scope F2 (تنها ورود = موبایل+OTP).

---

## 5. گام بعدی (F3 — Booking)

- `BookingService` با Resolution سلسله‌مراتب (ADR-0017) + Snapshot
- Policy: min lead 2h / max 60d / deadlines 12h (از Settings)
- Endpointهای N3–N11 + Patient Self-Service (S1–S6) با Ownership
- تست‌های TP-20 (IDOR) + TP-21 (Snapshot/Duration)
