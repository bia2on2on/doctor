# ADR-0019 — Error Code Convention: پیشوند ثابت `CLINIC_*`

تاریخ: 2026-09-05 | وضعیت: **تأیید نهایی کارفرما** | منبع: Engineering Baseline §27 + تصمیم F2

## تصمیم

1. **همه Error Codeهای فعلی و آینده از پیشوند ثابت `CLINIC_*` استفاده می‌کنند.**
2. Format: `CLINIC_{DOMAIN}_{DETAIL}` — Uppercase Snake_Case، **Stable و machine-readable** (شکسته نمی‌شوند؛ تغییر = Breaking Change).
3. **جداکننده Message/فنی:** `code` = ثابت ماشین‌خوان (API/Log/Monitoring)؛ `message` = متن فارسی کاربر (بدون جزئیات فنی).
4. **هیچ Legacy Mapping لازم نیست** (Public Release وجود ندارد) — رنوم‌زنی یک‌باره در Contract + Documentation + Tests + کدهای F2/F2.5 **در ابتدای F3** اعمال می‌شود.

## جدول نگاشت کدهای فعلی → نهایی

| فعلی (F1–F2.5) | نهایی |
|---|---|
| `MOBILE_INVALID` / `INVALID_MOBILE` | `CLINIC_MOBILE_INVALID` |
| `COOLDOWN` | `CLINIC_OTP_COOLDOWN` |
| `DAILY_LIMIT` | `CLINIC_OTP_DAILY_LIMIT` |
| `OTP_INVALID` / `OTP_EXPIRED` / `OTP_LOCKED` | `CLINIC_OTP_INVALID` / `CLINIC_OTP_EXPIRED` / `CLINIC_OTP_LOCKED` |
| `RATE_LIMITED` | `CLINIC_RATE_LIMITED` |
| `INVALID_NONCE` | `CLINIC_INVALID_NONCE` |
| `UNAUTHORIZED` | `CLINIC_UNAUTHORIZED` |
| `PERMISSION_DENIED` | `CLINIC_PERMISSION_DENIED` |
| `SLOT_TAKEN` | `CLINIC_SLOT_TAKEN` |
| `HOLD_EXPIRED` / `EXPIRED_HOLD` | `CLINIC_HOLD_EXPIRED` |
| `DUPLICATE_IN_FLIGHT` | `CLINIC_DUPLICATE_IN_FLIGHT` |
| `DUPLICATE_APPOINTMENT` (Contract) | `CLINIC_DUPLICATE_APPOINTMENT` |
| `DUPLICATE_ACTIVE_VISIT` (Contract) | `CLINIC_DUPLICATE_ACTIVE_VISIT` |
| `ADJUSTMENT_EXCEEDS` / `ADJUSTMENT_INVALID` | `CLINIC_ADJUSTMENT_EXCEEDS` / `CLINIC_ADJUSTMENT_INVALID` |
| `DISCOUNT_EXCEEDS` / `ITEM_DISCOUNT_INVALID` | `CLINIC_DISCOUNT_EXCEEDS` / `CLINIC_ITEM_DISCOUNT_INVALID` |
| `INVOICE_ITEM_INVALID` | `CLINIC_INVOICE_ITEM_INVALID` |
| `PAYMENT_AMOUNT_INVALID` | `CLINIC_PAYMENT_AMOUNT_INVALID` |
| `DURATION_INVALID` | `CLINIC_DURATION_INVALID` |
| `SMS_*` (همه کدهای ماژول پیامک) | `CLINIC_SMS_*` (مثلاً `CLINIC_SMS_PROVIDER_UNKNOWN`) |
| `SSRF_BLOCKED` / `SMS_ENDPOINT_*` | `CLINIC_SSRF_BLOCKED` / `CLINIC_SMS_ENDPOINT_*` |
| `OTP_NO_QUEUE_RETRY` | `CLINIC_SMS_OTP_NO_RETRY` |
| `VALIDATION_FAILED` / `NOT_FOUND` / `INVALID_TRANSITION` / `POLICY_VIOLATION` / `OVERPAYMENT` / `IDEMPOTENCY_REPLAY` / `FILE_INVALID` / `CONFLICT` (Contract) | `CLINIC_VALIDATION_FAILED` / `CLINIC_NOT_FOUND` / `CLINIC_INVALID_TRANSITION` / `CLINIC_POLICY_VIOLATION` / `CLINIC_OVERPAYMENT` / `CLINIC_IDEMPOTENCY_REPLAY` / `CLINIC_FILE_INVALID` / `CLINIC_CONFLICT` |

> **اعمال شد: 2026-09-05 (F3 — گام ۱)** — ۱۵۶ نگاشت در ۱۷ فایل PHP (src+tests) + ۱۶ سند (Contract/SRS/State-Machines/Testing/Security/Architecture + ADRها). Unit Suite: 187/346 سبز (۳ Run پیاپی).

## قواعد برای کدهای جدید (از F3)

- هر Code جدید با `CLINIC_` شروع می‌شود و در **`docs/api/error-codes.md`** (فهرست مرکزی) ثبت می‌شود — Endpoint جدید بدون Code ثبت‌شده ممنوع.
- HTTP Status: 400 validation / 401 unauthenticated / 403 forbidden / 404 not-found-as-forbidden / 409 conflict / 422 business-rule / 429 rate / 502 provider-downstream / 500 internal.
- 502: خطای Providerهای Downstream (SMS/OCR) که با Retry Resolve نشده‌اند.
- Logها: `code` + `technical details`؛ API: `code` + `message` فارسی.

## Consequences

- یکپارچگی Naming در API/Log/Monitoring/Alerting.
- هزینه اعمال: ~۱ ساعت رنوم‌زنی مکانیکی + آپدیت Contract/تست‌ها (بدون شکست سازگاری خارجی).
