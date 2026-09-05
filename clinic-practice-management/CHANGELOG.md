# Changelog

All notable changes to the CPMS plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-09-05

### Added
- **F3 (Core) — Booking flow, Patient self-service, Staff endpoints, Schedule API, REST hardening, CI:**
  - جریان رزرو آنلاین کامل B1–B6: `availability`, `booking/quote`, `booking/hold` (TTL + RateLimit), `booking/confirm` (+ Idempotency-Key الزامی و Replay)، `appointments/mine`, `appointments/{id}/cancel|reschedule` (+ Idempotency-Key)، `booking/resume` — روی namespace `clinic/v1` (روت‌های `{id}` با الگوی regex صحیح `(?P<id>\d+)` ثبت شده‌اند)
  - خودخدمتی بیمار C1/C2 (`GET/PUT /patient/me` با Whitelist فیلدها — فیلدهای بالینی هرگز از مسیر خودخدمت قابل تغییر نیستند)
  - Endpoints منشی/کارکنان D2–D11 (جستجو/ساخت/ویرایش بیمار، لیست/ساخت نوبت حضوری `is_walkin_express`، لغو نوبت)
  - **Schedule API (G1/G1b):** `GET/POST /config/schedules`, `PUT/DELETE /config/schedules/{id}`, `GET/POST /config/schedule-exceptions`, `DELETE /config/schedule-exceptions/{id}` — cap `cpms_config` + Nonce؛ Regeneration مطابق ADR-0004 (حذف فقط Slotهای خالی آینده + enqueue بازتولید؛ Slot دارای رزرو هرگز حذف نمی‌شود)؛ `ScheduleRepository` (لایه Repository — ADR-0021) + `ScheduleService` + `ScheduleController`
  - Error Envelope نهایی مطابق Contract §0: `code` سطح top-level همان `CLINIC_*` است (`RestBase::error()`); permission callbacks مسیرها به‌جای fatal، `WP_Error` با پوشش `CLINIC_*` برمی‌گردانند؛ 401/403 + Audit `FORBIDDEN_ACCESS_ATTEMPT`
  - تست‌های Integration سطح REST (dispatch واقعی `rest_do_request`): CSRF/Nonce، 401/403، IDOR (بیمار B روی داده بیمار A)، Idempotency (الزامی + Replay بدون رکورد تکراری)، Rate Limit 429، Envelopeها، Correlation-Id
  - تست‌های همزمانی (TP-03) با پردازهای واقعی موازی (`pcntl_fork` + اتصال mysqli مستقل): ظرفیت 1 → دقیقاً یک برنده؛ ظرفیت N → دقیقاً N؛ شمارنده‌های hold→claim دقیق
  - **CI واقعی (F3):** workflow به ریشه repo منتقل شد (workflow داخل زیرپوشه هرگز اجرا نمی‌شد)؛ Job Unit روی PHP 8.1–8.4؛ Job Integration واقعی با MySQL 8 + WordPress 6.7.2 Test Library (اسکریپت `tests/bin/install-wp-tests.sh`؛ PHPUnit 9.6 برای سازگاری با WP Test Lib)
- **F3 (Step 1) — Unified `CLINIC_*` Error Code convention (ADR-0019):**
  - All error codes across API, Domain, Application, Infrastructure, REST, and tests now use the stable, machine-readable `CLINIC_*` prefix (no legacy mapping — no public release yet).
  - Covers Auth/OTP, REST (nonce/auth/permission/rate), Slots/Booking, Finance, SMS, and shared contract codes (`CLINIC_INVALID_TRANSITION`, `CLINIC_POLICY_VIOLATION`, `CLINIC_OVERPAYMENT`, `CLINIC_CONFLICT`, …).
  - Clear separation: `code` (stable machine token) vs `message` (Persian user-facing, no technical detail).
  - New central registry `docs/api/error-codes.md` (mandatory: every new endpoint registers its codes here).
  - API Contract, SRS, State Machines, Testing Plan, Architecture/Security docs, and all ADR references aligned. No behavior change — purely naming (Unit suite green 187/346).
- **F2.5 — Provider-Agnostic SMS module (ADR-0025):**
  - `SmsService` (Application) + `SmsProviderInterface` + `SmsProviderRegistry` (plugin hook `cpms_sms_provider`)
  - Built-in adapters: `log` (dev/test), `generic_api` (endpoint + request/response mapping, SSRF guard, no code execution)
  - Credential vault (AES-256-GCM; key from `CPMS_SECRET_KEY` env or WP salts) — never plaintext in settings/REST/logs/audit
  - SMS templates as first-class events (OTP, appointment confirmed/reminder/cancelled/rescheduled, follow-up) with internal variable mapping
  - `cpms_sms_messages` table (Migration 0003): statuses QUEUED/SENDING/SENT/DELIVERED/FAILED/RETRYING, dedupe key, attempts, provider message id
  - Smart retry (no blind retry), duplicate-SMS prevention via unique dedupe key
  - 10 REST endpoints (`/sms/*`) + admin "تنظیمات پیامک" page (test connection / test send / template test / logs / balance)
  - New capability `cpms_sms_config` (46 total)
  - OTP hardening: OTP code never persisted in SMS records (masked text, no vars, no queue retry)
- **F1 — Core & Migrations:**
  - 38 dedicated `cpms_*` tables (PHI never in `wp_posts`)
  - State machines: Appointment (9-state), Visit/Queue (9-state), Invoice, Payment (7-state)
  - Append-only Audit log with SHA-256 hash chain + `bin/cpms audit verify`
  - Job Queue (15 job types) with retry/backoff/failure tracking
  - Migration runner (versioned, logged) — Migrations 0001 (initial) + 0002 (duration/buffers)
  - Slot generation + atomic Slot Claim (single-capacity concurrency-safe)
  - Health endpoint + technical settings page
- **F2 (P0) — Mobile + OTP Authentication:**
  - `POST /wp-json/clinic/v1/otp/request` + `/otp/verify` (API Contract A2/A3 — namespace `clinic/v1`)
  - OtpService: TTL 120s, max 5 attempts + 15-min lockout, cooldown, daily/hourly/IP rate limits
  - OTP stored only as `SHA-256(code + pepper)` — raw code never persisted/logged
  - Auto user creation (`cpms_patient`) + patient linking + session (`wp_set_auth_cookie`)
  - SMS Gateway abstraction: LogSmsGateway (dev) + HttpSmsGateway; failure → retryable `sms.send` job
  - Final 45-capability authorization model (permission-matrix v1.1, no generic capability)
  - System Cron as primary runner with `GET_LOCK` (ADR-0016) + queue health/stale detection
  - `bin/cpms jobs list|retry`, `bin/cpms health`
  - Appointment duration: 4-layer resolution + booking-time snapshot (ADR-0017)
  - Settings defaults per client decision D3 (documented in `docs/settings-reference.md`)

### Notes
- Commercial/proprietary plugin. License & Update System planned as phase F10
  (Engineering Baseline v1.0, `docs/engineering-baseline.md`).
