# Changelog

All notable changes to the CPMS plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-09-05

### Added
- **F4 (Core) — Visit/Queue flow, Real-time polling (R1), Secretary dashboard:**
  - جریان مراجعه کامل: `POST /visits/checkin` (D6 — با مسیر ER-06 دیرهنگام: نوبت past-grace → `no_show` + Visit فوری Walk-in-like با حفظ ارجاع)، `POST /visits/walk-in` (D7، `clinician_id` الزامی)، `POST /visits/{id}/status` (D8 — نگاشت `to_status` منشی → event ماشین: enqueue/cancel/invoice_ready/waive)، `POST /visits/{id}/checkout` (D16 — `paid → check_out` یا معافیت با دلیل الزامی)
  - داشبوردها: `GET /secretary/today` (D1 — آمار + صف زنده)، `GET /doctor/today` (E1)، `GET /queue` (E2)؛ اکشن‌های پزشک E3–E6 (call/recall/start/skip) + E14 (complete)
  - Real-time V1 (ADR-0007): `GET /rt/queue?since={event_id}` با ETag/If-None-Match → 304 + Rate limit per-user؛ فید از `cpms_visit_status_history` (append-only، محدود به امروز)
  - `VisitService`: اجرای کامل ماشین V1–V15 با Row-Lock (J-1 یکتایی complete)، تاریخچه append-only (J-3)، ترتیب صف FIFO + نوبت فوری از `is_walkin_express` (J-4 بدون تغییر Schema)، ویزیت فعال واحد بیمار×پزشک×روز با قفل بیمار (J-5 → `CLINIC_DUPLICATE_ACTIVE_VISIT`)، سقف recall از `queue.max_recalls` (J-6)، تکمیل نوبت مرجع در خروج (T9) + `VisitRepository` (ADR-0021) + `VisitException`
  - No-show خودکار (FR-5.5): sweep داخلی Check-in (lazy) + جاب تکرارشونده `visits.no_show` (هر دقیقه؛ فقط نوبت‌های confirmed بدون Visit فعال پس از `queue.no_show_grace_minutes`)
  - `SecretaryQueuePage` (WP-Admin، `cpms_queue_read`): داشبورد زنده امروز/Drawer گردش کار/Walk-in/Keyboard (W/C/R//Esc) با polling 3s + توقف روی تب مخفی (Page Visibility) — بدون endpoint جدید
  - کدهای خطای جدید: `CLINIC_INVALID_APPOINTMENT_STATE`، `CLINIC_RECALL_LIMIT_REACHED`
  - تست‌ها: `VisitFlowTest` (TP-19 جریان‌های دیرهنگام/no-show)، `VisitConcurrencyTest` (TP-03b سه‌لایه: fork موازی DB-level + سرویس + Row-Lock واقعی)، `RestQueueTest` (TP-04/07/09 + R1 ETag/304)، `JobQueueTest` (چرخه جاب تکرارشونده) — Integration: ۱۳۳ تست/۵۷۵ assertion، ۰ skip در CI
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

### Fixed
- **Envelope خطای REST — تداخل کلید `status`:** Data خطای دامنه‌ای (مثلاً `status: 'waiting'`) با `array_merge` روی HTTP Status رسمی Envelope می‌نشست (پاسخ با status نامعتبر/0) — `RestBase::error()` اکنون `status` رسمی را در آخرین اولویت merge می‌کند؛ کدهای دامنه از کلیدهای `visit_status/appointment_status` استفاده می‌کنند.
- **جاب‌های تکرارشونده یک‌باره بودند (میراث F1):** `scheduleRecurringJobs()` فقط هنگام Activate اجرا می‌شد و Dispatcher دوباره زمان‌بندی نمی‌کرد — `cpms_jobs_tick` اکنون پیش از dispatch دوباره (Idempotent، بدون نسخه Queued تکراری) زمان‌بندی می‌کند (`RECURRING_JOBS` در `App`). برای `holds.expire`/`slots.generate`/`visits.no_show` حیاتی بود.

### Notes
- Commercial/proprietary plugin. License & Update System planned as phase F10
  (Engineering Baseline v1.0, `docs/engineering-baseline.md`).
