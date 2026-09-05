# تصمیمات نهایی کارفرما — بازبینی Baseline (F2)

تاریخ: 2026-09-05 | وضعیت: **نهایی و الزام‌آور** | سند مادر: `docs/phase-reports/engineering-baseline-review.md`

این سند، مجموعه تصمیمات کارفرما در پاسخ به موارد باز Review را ثبت می‌کند. هر تصمیم به ADR/سند عملیاتی مربوط پیوسته است.

## خلاصه تصمیمات

| # | موضوع | تصمیم نهایی | سند |
|---|---|---|---|
| C1 | Error Code Convention | پیشوند ثابت **`CLINIC_*`** برای **تمام** کدهای فعلی و آینده؛ **بدون Legacy Mapping** (رِلیز عمومی وجود ندارد)؛ کدها **Stable و machine-readable**؛ جداسازی `code` (فنی) از `message` (فارسی کاربر) | **ADR-0019** |
| C1-اعمال | زمان اعمال | Contract + Documentation + Tests + کدهای F2/F2.5 **در ابتدای F3** | ADR-0019 §2 |
| C2 | لایه Repository | **الزامی از F3**؛ **Domain-Focused** (Patient/Appointment/Slot/Invoice/Payment/File/License…)؛ **بدون God Repository**؛ کد سالم F1/F2 **صرفاً برای یکدستی Refactor نمی‌شود** — فقط در صورت بروز **Duplication / Tight Coupling / Testability / Security / Transaction / Concurrency** با **Impact Analysis** و به‌صورت محدود | **ADR-0021** |
| M1 | روش 2FA | **TOTP (RFC 6238)** — **SMS-OTP روش Recovery پیش‌فرض 2FA نیست** | **ADR-0020** |
| M1-دامنه | 2FA بر پایه Access | الزامی برای **هر کاربر با دسترسی Medical/Sensitive** (PHI، اطلاعات پزشکی، مالی حساس، Export، Audit، تنظیمات حساس) — فعلی: **Doctor + Secretary**؛ آینده: هر Role/User با Grant صریح از همین دسترسی‌ها (Gate روی Grant، نه Role) | ADR-0020 §2 |
| M1-Recovery | Recovery Codes | 10 کد **یک‌بارمصرف**؛ فقط **Hash** ذخیره؛ TOTP Secret در **Vault** — **خام هرگز Log/ذخیره نمی‌شود** | ADR-0020 §3-4 |
| M1-حاکمیت | Reset/Disable | فقط با **Permission مناسب + Audit** (`TFA_ENABLED`/`TFA_RESET`/`TFA_DISABLED`) | ADR-0020 §5 |
| M1-مستقلی | ماژول SMS بیمار | ماژول SMS/OTP بیمار (F2.5) **کاملاً مستقل** از Staff 2FA — خرابی Provider پیامک روی ورود Staff اثر ندارد | ADR-0020 §1 |
| M2 | Licensing Grace | Grace Period پیش‌فرض **7 روز — Server-Configurable** | ثبت در **ADR-0023** (F10) |
| M4 | RPO/RTO | **RPO ≤ 6 ساعت، RTO ≤ 8 ساعت** (مبنای Production)؛ مسیر ارتقا به **RPO ≤ 1h بدون بازطراحی** (فرکانس Dump ساعتی / binlog-PITR) | `docs/backup/backup-recovery.md` §1 |
| M5 | Performance Baseline | **REST p95 < 300ms** (Endpoints پایه/تعاملی)؛ **Overhead صفحه عمومی p95 < 100ms**؛ عملیات سنگین (**OCR، SMS، Export، PDF، Report**) **خارج از هدف REST → async/Job**؛ Benchmark باید **محیط، حجم داده، وضعیت Cache، هم‌زمانی** را صریح کند | `docs/performance/performance-baseline.md` |

## الزامات جدید برای F3 (Booking) — از تصمیمات بالا

1. **کدهای `CLINIC_*`** — اعمال هم‌زمان در API Contract + Docs + Tests + کد F2/F2.5 (بدون Legacy Mapping).
2. **لایه Repository** — تمام کد جدید F3 از Repositoryهای Domain-Focused می‌گذرد (Patient، Appointment، Slot + جدیدها).
3. **LicenseGate Seam** — عملیات ساخت (Appointment/Visit/…) از `LicenseGate` بگذرند (پیاده‌سازی پیش‌فرض = ACTIVE).
4. **Correlation ID** — در Logهای REST (M10) — ارزان، همراه F3.

## تصمیمات Governance (2026-09-05 — حین F3)

| # | موضوع | تصمیم | سند |
|---|---|---|---|
| G-1 | Cancellation/Reschedule Deadline | **24h = Default نهایی** (تأیید صریح کارفرما)؛ **Configurable** از Settings؛ **بدون اثر Retroactive** روی نوبت‌ها/تصمیمات ثبت‌شده | SRS FR-4.9/4.10 + Settings `booking.cancel_deadline_hours` / `booking.reschedule_deadline_hours` |
| G-2 | Conflict Resolution Protocol + **Document Precedence Policy** | ✅ **تأیید نهایی کارفرما (2026-09-05)** — Precedence: Client Final Decisions → Approved ADRs (scope) → Baseline → SRS → SM/ERD → API Contract → Settings → Implementation؛ تعارض هم‌رتبه = STOP+Report+Wait | `docs/decisions/2026-09-05-document-precedence-policy.md` (وضعیت: تأییدشده) |
| G-3 | **GAP-1** — بُعد Clinician در Booking | ✅ **تأیید نهایی کارفرما (2026-09-05)** — B1: `clinician_id` الزامی · B5: `clinician_id` اختیاری (Default = پزشک فعلی نوبت) · D10: `clinician_id` الزامی؛ بدون تغییر Schema | `docs/decisions/2026-09-05-f3-booking-gaps.md` (وضعیت: حل‌شده) |

## تصمیمات قبلی‌تر (بازبینی F2 — D1–D4، تأییدشده)

- D1: بدون Rate Limit روی Verify/Submit — محافظت = TTL/Cooldown/MaxAttempts/Abuse locks (به‌علاوه Nonce در REST).
- D2: جدول Settingها `cpms_sms_config` (Naming Convention پروژه — تأیید نهایی 2026-09-05).
- D3: `cpms_otp_attempts` و `wp_users.meta` — بدون تغییر.
- D4: `mobile_hash = SHA-256(normalized_mobile + per-install salt)` — بدون تغییر.
