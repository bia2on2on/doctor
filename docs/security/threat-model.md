# Threat Model — CPMS (STRIDE)

نسخه 1.0 | 2026-09-05 | فاز 7 | دارایی‌های اصلی: **PHI**، **تراکنش‌های مالی**، **تأییدیت (Integrity) داده‌های بالینی**، **Availability نوبت‌دهی**

## 1. Threats کلیدی

| ID | Threat (STRIDE) | دارایی | سطح (L/M/H) | کنترل/مهارت | باقی‌مانده | تست |
|---|---|---|---|---|---|---|
| T-01 | **IDOR:** `GET /patient/123` توسط Patient A (Elevation) | PHI | **H** | Ownership + Data-Access در Server؛ 404-as-403؛ Audit attempt؛ Rate Limit | M | TP-07 |
| T-02 | **Double-Booking** رزرو هم‌زمان (Repudiation/Integrity) | Integrity نوبت | **H** | Claim اتمیک DB + Transaction + Hold (ADR-0004) | L | TP-03 |
| T-03 | **Brute-force OTP** / SMS Bombing (DoS/ Spoofing) | حساب بیمار | M | 5 attempts+Lockout+3/روز+10/hr+cooldown | L | TP-05 |
| T-04 | **دسترسی Secretary به Private Note** (Elevation) | PHI بالینی | **H** | Visibility Filter در Repository (نه UI)؛ Capability | L | TP-08 |
| T-05 | **دسترسی Admin فنی به PHI** (Elevation) | PHI | **H** | تفکیک صریح Capability؛ پیش‌فرض خالی | L | TP-09 |
| T-06 | **نشت فایل از URL عمومی** (Information Disclosure) | PHI | **H** | ذخیره خارج URL عمومی + Stream مجوزیافته + نام تصادفی + .htaccess deny | L | TP-06 |
| T-07 | **SQL Injection** (Tampering) | کل DB | **H** | Prepared Statements اجباری (Query Layer)؛ Code Review | L | SAST + Review |
| T-08 | **XSS** در یادداشت‌ها/نسخه‌ها (Tampering) | Session PHI | M | KSES (Whitelist) + Escaping خروجی + CSP | L | Review + E2E |
| T-09 | **CSRF** (Repudiation) | Mutation | M | Nonce bound-to-session + SameSite | L | TP-04 |
| T-10 | **Session Hijacking** (Spoofing) | Session | M | Secure/HttpOnly/SameSite+Site Binding+Timeout | L | Review |
| T-11 | **Audit Tampering** (Repudiation) | Audit | **H** | Append-only + Hash Chain + Trigger + دسترسی V1 | M | TP-11 |
| T-12 | **Double Payment** (DoS مالی/Integrity) | مالی | **H** | Idempotency-Key U + Transaction + Lock Row | L | TP-02 |
| T-13 | **OCR به Provider خارجی: نشت PHI** (Information Disclosure) | PHI | M (V1.5) | حذف PII از تصویر (بدون نام/تاریخ) + Consent + Contract DPA + Provider داخلی اولویت | M | Review + Acceptance |
| T-14 | **نشت داده در Error Log** (Information Disclosure) | PHI | M | Log بدون Body درخواست؛ Masking؛ Sanitization خطا | L | Review |
| T-15 | **Handwriting: از دست رفتن داده در Tablet** (Availability) | داده بالینی | **H** | IndexedDB Local + Autosave + Sync State + Retry (ADR-0014) | L | TP-12 (شبیه‌سازی آفلاین) |
| T-16 | **نشت از Local Storage Tablet** (شخص قابل‌شناسایی) (Information Disclosure) | PHI | M | حذف Local بعد از Sync موفق (پیش‌فرض)؛ Encryption Local در V1.5؛ هشدار در UI | M | Review |
| T-17 | **Upload بدافزار/فایل جعلی** (Tampering/DoS) | سرور | M | MIME finfo + Whitelist + Size + (ClamAV V1.5) + نام تصادفی | L | TP-06 |
| T-18 | **Replay Request** (Repudiation) | Mutation | M | Idempotency-Key + Hold token یک‌بار + Nonce | L | TP-02 |
| T-19 | **Race Condition در Queue** (دو منشی/پزشک هم‌زمان) (Integrity) | Integrity صف | M | Row Lock + Transition Check + History | L | TP-03 (متغیر) |
| T-20 | **Vendor/Supply Chain** (WordPress + PHP deps) (Tampering) | کل | M | WP روزمره؛ deps ثابت (composer lock)؛ Dependency Updates (NFR-SEC-11) | M | Process |
| T-21 | **دسترسی فیزیکی سرور/بکاپ** (Information Disclosure) | PHI | M | رمزنگاری بکاپ + Key خارج سرور + Hardening OS (فاز 13) | M | DR Review |
| T-22 | **Mass Assignment** (Tampering) | Mutation | L | Whitelist فیلدها در Repository | L | TP-10 |
| T-23 | **Timing Attack** روی OTP (Spoofing) | احراز | L | `hash_equals` | L | Code Review |
| T-24 | **No-Show غلط/Complete دوباره** (Integrity) | Integrity | M | State Machine + Lock (J-1) | L | TP-03 |

## 2. Trust Boundaries
```
[مرورگر بیمار] ──HTTPS──▶ [WP REST API] ──▶ [CPMS Domain] ──▶ [MySQL]
                              │  ──▶ [File Store (خارج webroot)]
                              │  ──▶ [SMS Gateway] (فروشگاه داده: mobile+template فقط)
                              │  ──▶ [OCR Provider] (V1.5؛ تصویر بدون PII)
[Tablet پزشک] ──HTTPS──▶ (همان) + Local Storage (IndexedDB) ← تهدید T-16
```

## 3. داده‌های حساس و چرخه حیات
| داده | تولید | ذخیره | انتقال | خرابی |
|---|---|---|---|---|
| کد OTP | سرور | Hash فقط (24h) | SMS | پاک‌سازی Job |
| PHI | User/Doctor | `cpms_*` (InnoDB) | HTTPS | Soft/Delete طبق Retention |
| Stroke دست‌خط | Tablet | Local (موقت) + Server | HTTPS (gzip) | حذف Local بعد Sync |
| Secret | Config | wp-config/Env | — | Rotating (فاز 13) |
| Audit | Server | Append-only + Hash Chain | — | آرشیو 10+ سال |

## 4. مصوبات (Acceptance)
- هیچ PHI در URL، Query String، Cache Header، Error Log، یا Body خطای API وجود نداشته باشد (Review + تست).
- `grep` روی کد: هیچ `prepare`-نشده‌ای، هیچ URL عمومی فایل، هیچ ذخیره OTP خام.
- Security Review + Checklist در فاز 13 با امضای کارفرما.
