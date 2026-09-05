# گزارش فاز F1 — Core Architecture & Migrations

تاریخ: 2026-09-05 | وضعیت: **تأییدشده (Approved 2026-09-05)** | بعد از تأیید: **F2 (احراز هویت OTP) — آغاز شد**

**تصمیمات نهایی کارفرما (۲۰۲۶-۰۹-۰۵) — اعمال شد:**
1. **D1 — Cron:** Production = System Cron اصلی + Retry/Idempotency/Health → **ADR-0016**
2. **D2 — Capabilities:** فهرست نهایی 45 قابلیت → **permission-matrix v1.1** (قبل از پیاده‌سازی، مطابق شرط)
3. **D3 — Settings:** مقادیر پیش‌فرض نهایی → **settings-reference.md** + DEFAULTS کد
4. **D4 — Duration:** سلسله‌مراتب 4 لایه + Snapshot در Booking → **ADR-0017** + Migration 0002

---

## 1. خروجی کامل

```
clinic-practice-management/
├── clinic-practice-management.php      Bootstrap + Autoload
├── composer.json / phpunit.xml / CI
├── uninstall.php                       (حذف فقط با Setting صریح فنی — FR-22.3)
├── bin/cpms                            CLI: migrate | jobs tick | audit verify | slots generate | status
├── src/
│   ├── Domain/                         ← خالص (بدون WP) — 122 تست سبز
│   │   ├── Machine/                    StateMachine + 4 ماشین (Appointment/Visit/Invoice/Payment)
│   │   ├── Otp/                        OtpPolicy + OtpState (TTL/Attempts/Cooldown/Daily/Lockout)
│   │   ├── Finance/                    InvoiceCalc (totals/partial/overpayment/adjustment)
│   │   └── Slots/                      SlotGenerator (برنامه + استراحت + استثنائات)
│   ├── Migrations/                     Runner + 0001 (37 جدول)
│   ├── Infrastructure/
│   │   ├── Db/CpmsDb.php               (prepare-only, transactional, FOR UPDATE)
│   │   ├── Audit/                      HashChain + AuditLogger (Masking, ضیاد OTP/Token)
│   │   ├── Logging/OpLogger.php        (جدا از Audit، Sanitize ضد PHI)
│   │   ├── Security/                   RateLimiter (اتومیک) + Idempotency (claim/complete/release)
│   │   └── Queue/JobQueue.php          (enqueue/claim/complete/fail + Backoff + Stale Locks)
│   ├── Application/Jobs/               Dispatcher + 4 Handler (holds.expire, cleanup.otp, cleanup.rate_limits, slots.generate)
│   ├── Auth/RolesAndCapabilities.php   3 نقش + 30 Capability + Admin فنی=فقط cpms_config
│   ├── Settings/Settings.php           (cpms_settings + پیش‌فرض‌های Policy)
│   ├── Admin/SettingsAdmin.php         صفحه «CPMS (فنی)» — بدون PHI
│   └── Rest/                           RestBase (nonce/cap/ratelimit/error-shape) + HealthController
└── tests/
    ├── Unit/                           122 تست — سبز (بدون WP)
    └── Integration/                    6 کلاس تست (CI با WP+MySQL): Migration, AuditChain, Idempotency, RateLimiter, JobQueue, SlotClaim
```

**نتیجه تست (اجراشده در این Work Session):** `OK (122 tests, 189 assertions)` — 3 بار پیاپی.

## 2. تصمیمات معماری اجراشده
| # | تصمیم |
|---|---|
| 1 | Domain کاملاً خالص (بدون `function_exists('wp_...')`) → Unit Test بدون WP/CI. |
| 2 | `StateMachine` عمومی با Actor-based branching (cancel بیمار ≠ cancel کارکن با همان Event). |
| 3 | Audit: `FOR UPDATE` روی آخرین ردیف برای ساخت زنجیر در Write هم‌زمان + White-list فیلدهای Hash (PHI در Hash نمی‌رود). |
| 4 | `Idempotency-Key` با `context_id` (invoice/visit) + حالت `pending` → 409 برای Request موازی + `release` برای Retry بعد از شکست. |
| 5 | Rate Limit: Window ثابت + `INSERT ... ON DUPLICATE KEY UPDATE hits=hits+1` (اتومیک بدون Read-then-Write). |
| 6 | Job: `claim` با Row Lock + UPDATE شرطی → بدون Double-Claim؛ `releaseStaleLocks` برای Worker مرده. |
| 7 | Claim Slot (قلب TP-03): `UPDATE ... SET held_count=held_count+1 WHERE capacity-booked-held>0` — در F3 داخل Booking Service قرار می‌گیرد (اینجا فقط تضمین DB تست شد). |
| 8 | Migration خودکار هنگام `admin_init`/`rest_api_init` (idempotent + transient lock) + CLI دستی. |
| 9 | `uninstall.php` فقط با `cpms_wipe_on_uninstall` — پیش‌فرض هیچ حذفی. |

## 3. تغییرات نسبت به مستندات (اعلام مطابق Section 56)
| تغییر | نوع | Impact |
|---|---|---|
| جدول #37 `cpms_rate_limits` (window_key, window_id, hits) | افزودن زیرساختی | هیچ (بیرون داده‌های پزشکی) |
| `cpms_idempotency_keys`: ستون‌های `status` (pending/done) و `context_id` | افزودن | هیچ (کاملاً سازگار) |
| `AuditLogger.verifyChain` فقط روی بازه‌ی خوانده‌شده | توضیحی | در آرشیو، Chain در مرز بررسی می‌شود (ADR-0008) |
> مستندات ERD با Version 1.1 تا پایان F2 اصلاح می‌شود (به‌روزرسانی docs/erd).

## 4. Assumptionهای این فاز
- PHP 8.1+ روی سرور (CI: 8.1–8.4).
- Cron OS-level در دسترس است (`bin/cpms jobs tick` هر دقیقه)؛ WP-Cron فقط Fallback.
- دیتابیس MySQL 8 (InnoDB, utf8mb4).
- `CPMS_PEPPER` در wp-config/Env تعریف می‌شود (در نبود: مقدار dev + هشدار در F9).

## 5. موارد مبهم
- **ترتیب دقیق Jobهای دوره‌ای** (هر 10 دقیقه یا هر 1 دقیقه برای holds.expire) — فعلاً: holds.expire هر دقیقه (Queue با priority)، بقیه روزانه. اگر SLA دقیق‌تر خواستید: تنظیم `rt` و `jobs` در Settings.
- **WP-Cron یا systemd/timer** برای `jobs tick` — تصمیم زیرساختی کارفرما (فاز استقرار).

## 6. ریسک‌های شناسایی‌شده
| ریسک | مهارت |
|---|---|
| `FOR UPDATE` روی آخرین ردیف Audit در ترافیک بالا | صنفی: Audit در F1 کم‌ترافیک است؛ در F9: Batch/Queue برای Audit (ADR آماده) |
| WP-Cron در سایت‌های کم‌ترافیک اجرا نمی‌شود | Cron OS-level در دستورالعمل نصب اجباری است |
| DDL در Transaction (MySQL) Commit ضمني دارد | Runner: هر Migration جدا Transaction؛ Rollback فقط با `down()` صریح |
| PHP 8.4 در CI: Deprecation آینده | Matrix 8.1–8.4 + Static Analysis |

## 7. آیتم‌های نیازمند تصمیم کارفرما
1. **زیرساخت استقرار** (VPS/دسترس SSH برای Cron) — قبل از F9 لازم است.
2. **تأیید فهرست Capabilities** در `RolesAndCapabilities` (فهرست کامل: src/Auth) — مطابق Matrix است؟
3. **پیش‌فرض‌های Settings** (src/Settings/Settings.php::DEFAULTS) — مقادیر Policy (TTL، Grace، Retention) نهایی؟

## 8. Acceptance Criteria F1

| # | معیار | وضعیت |
|---|---|---|
| AC-1 | ساختار Lایه‌ای مطابق ADR-0001 (Domain خالص، DB Access فقط در Infrastructure) | ✅ |
| AC-2 | Migration 37 جدول + Schema Version + Idempotent + Rollback | ✅ (تست Integration) |
| AC-3 | Roles/Capabilities ثبت‌شده؛ Admin بدون دسترسی پزشکی | ✅ |
| AC-4 | Audit با Hash Chain + Masking + بدون OTP/Token | ✅ (تست: زنجیر، جعل، Masking) |
| AC-5 | Rate Limit اتمیک + Idempotency (claim/replay/release) | ✅ (تست) |
| AC-6 | Job Queue: claim/complete/fail/backoff/stale-lock + Dispatcher | ✅ (تست) |
| AC-7 | State Machineهای 4 گانه Exhaustive (TP-14) | ✅ (122 تست Unit سبز) |
| AC-8 | تضمین DB ضد Double-Booking (Claim اتمیک + K-2) | ✅ (SlotClaimTest) |
| AC-9 | CI: Unit (بدون WP) + Integration (WP+MySQL) + PHPStan L6 | ✅ (Workflow) |
| AC-10 | هیچ Endpoint پزشکی ساخته نشده (فقط /health فنی) — مطابق Gate | ✅ |

## 9. گام بعدی (F2 — احراز هویت)
- Flow Mobile+OTP کامل (A2/A3): `SmsGateway` Interface + Provider از Setting + `OtpService` (استفاده از OtpPolicy) + ساخت/اتصال حساب بیمار (`cpms_patient_user_links`) + Session/Security + Rate Limit روی Endpointها + TP-04/05/17.
