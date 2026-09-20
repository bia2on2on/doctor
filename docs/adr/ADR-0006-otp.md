# ADR-0006 — طراحی OTP (Hash-only، TTL، Attempts، Rate Limit)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§5: OTP با Expiration/RateLimit/Cooldown/MaxAttempts/Log؛ کد خام ذخیره نشود.

## Decision
- کد 6 رقمی CSPRNG؛ ذخیره `SHA-256(code + pepper)` در `cpms_otp_tokens` (pepper از Env).
- TTL 300s؛ attempts 5؛ Lockout 15 دقیقه؛ Cooldown resend 60s؛ Max 3 کد/روز؛ 10/hr (IP+Mobile).
- Compare با `hash_equals` (ضد Timing).
- هر رویداد → Audit/Operational (بدون کد).
- `SmsGateway` Interface → Provider از Setting (تصمیم کارفرما R-01).

## Consequences
+ نشت DB ≠ نشت کد.
− اگر Provider خراب باشد، ورود بیمار متوقف می‌شود → کنترل: Backup Provider (V1.5) + هشدار (FR-ER-02).

## Alternatives
- رمز عبور/Passkey برای بیمار (UX ضعیف در موبایل؛ خارج از Scope).
- Token Magic Link (جالب برای V2 — معماری به آن مانعی ندارد).

## Addendum — Phase 8 Slice 2 (2026-09-20): اتصال Challenge به Clinic

تصمیم مالک (Hold-Timing) ایجاب کرد Challenge OTP به Clinicِ نوبت گره بخورد؛
اثبات شد هیچ شناسهٔ پایدارِ موجودی این پیوند را امن تأمین نمی‌کند، پس
`cpms_otp_tokens.clinic_id` (Migration `2026_09_20_0021`؛ NULL مجاز، FK به
`cpms_clinics(id)`، بدون Backfill) افزوده شد. A2 با انتخابِ bookable، Clinic
را فقط از دادهٔ persisted مشتق و مُهر می‌کند (Tamper ⇒ 404/422 با صفر
Challenge/SMS؛ بدون انتخاب روی چند-Clinic ⇒ پاکت `CLINIC_SCOPE_REQUIRED`).
A3 هویت‌سطح می‌ماند (`mobile/code/purpose` — AD-15) و Clinicِ مُهرشدهٔ
Challenge مرجعِ سیاست OTP، جست‌وجوی Patient و Clinicِ لینک است؛ ردیفِ
تاریخیِ NULL: تک-Clinic = resolver تثبیت‌شده، چند-Clinic = fail-closed.
بازاستفادهٔ هویتِ قطعی `{mobile}@otp.cpms.local` جایگزین ساختِ تکراری کاربر
شد (همان user_id، `is_new_user=false`). جزئیات:
`docs/decisions/2026-09-20-phase8-slice2-owner-decisions.md`.
