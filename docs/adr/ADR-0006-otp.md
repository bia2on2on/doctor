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
