# Changelog

All notable changes to the CPMS plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-09-05

### Added
- **F6 (Core) — Finance: Invoice/Payment lifecycle (D12–D18 + P3), Services (G2), Secretary finance UI, Checkout NOT_SETTLED guard, ADR-0026 (dynamic roles clarification):**
  - **FinanceService** (`src/Application/Finance/`): صدور فاکتور D12 (I1: فقط ویزیت consultation_completed/awaiting_payment؛ I4 یکتایی فاکتور فعال → 409؛ اقلام از تعرفه یا دستی با تخفیف قلم؛ V11 با نقش system از طریق `VisitService::applyTransition(forceRole)`؛ Totals خالص در `InvoiceCalc` — ریال صحیح TP-18)، ثبت پرداخت D13 (Idempotency روی خود جدول با `UNIQUE(invoice_id,idempotency_key)` — M-1؛ اولین 201/تکرار همان کلید 200 + `CLINIC_IDEMPOTENCY_REPLAY` + همان payment_id — TP-02؛ بیش‌پرداخت → `CLINIC_OVERPAYMENT` 422 — M-3؛ `InvoiceMachine` pay_partial/pay_full؛ V12 تسویه سیستمی در همان Transaction — M-7)، ابطال D14 (فقط همان روز UTC → `CLINIC_VOID_WINDOW_EXPIRED`؛ بازگردانی فاکتور؛ ویزیت دست‌نخورده — V12 یک‌طرفه، Deviation مستند)، بازپرداخت P3 (جزئی/کامل، سقف amount−refunded)، اصلاح D15 (credit/debit با دلیل؛ کلید تسویه مؤثر = total−credit+debit؛ M-6 روی فاکتور paid/voided → `CLINIC_INVOICE_NOT_MODIFIABLE`)، رسید D17 (Deterministic — M-5؛ فقط پرداخت‌های captured؛ تاریخ جلالی؛ JSON + نمای چاپ UI — PDF سمت سرور = Backlog بدون Dependency جدید)، خلاصه D18 (Revenue/by_method/refunded/open balances/آخرین پرداخت‌ها)، تعرفه‌ها G2 (CRUD فقط `cpms_config` — soft-delete؛ خواندن با `cpms_invoice_read` یا `cpms_config`)
  - **Repos (ADR-0021):** `ServiceRepository`، `InvoiceRepository` (شماره `INV-ymd-NNN` per-clinic)، `PaymentRepository` (`PAY-ymd-NNNN` + revenueSummary)؛ عددگیری سریال با قفل ردیف کلینیک (سریال‌سازی MAX+1)
  - **VisitService:** `applyTransition` public + `$forceRole` (نقش system فقط برای V11/V12 — M-7)؛ گارد `CLINIC_NOT_SETTLED` در Checkout (V14 — فاکتور open/partial → 409 با open_invoices/balance؛ مسیر معافیت waive عمداً باز)
  - **FinanceController:** D12(201)/D12b نمای فاکتور/D12c فاکتور فعال ویزیت/D13(هدر Idempotency-Key الزامی — بدون آن 400؛ 201/200)/D14/P3 بازپرداخت/D15/D17/D18 + `/config/services` CRUD — الگوی Nonce+Cap+Envelope
  - **UI:** `SecretaryFinancePage` (زیرمنوی صف — `cpms_finance_read`): داشبورد D18، تب تسویه‌نشده‌ها (صدور فاکتور با اقلام تعرفه/دستی)، بدهی‌های باز، Drawer تسویه (پرداخت با `crypto.randomUUID` برای Idempotency-Key، ابطال/بازپرداخت/اصلاح، رسید چاپی)، تب تعرفه‌ها (فقط `cpms_config`)؛ دکمه «آماده‌سازی صورتحساب» صف → لینک عمیق به داشبورد مالی
  - **کدهای خطای جدید:** `CLINIC_NOT_SETTLED`، `CLINIC_VOID_WINDOW_EXPIRED`، `CLINIC_INVOICE_NOT_MODIFIABLE` (error-codes.md)؛ `InvoiceMachine`: self-loop partial→partial برای pay_partial (M-3 تجمیع)
  - **ADR-0026 (تصریح محصول کارفرما 2026-09-06):** نقش‌های پویا + مجوزدهی Capability/Scope؛ بررسی معماری §27 انجام شد — **بدون Blocker**؛ Debt نقش-محور باقی‌مانده + نقشه مهاجرت مستند؛ SRS (FR-1.13/1.14, NFR-SEC-13/14, NFR-UI-5/6)، auth-authorization §2.5، permission-matrix P-9..P-12+§6، roadmap (V2/F8)، agent-guide هم‌راستا شد
  - تست‌ها: `FinanceFlowTest` (۱۷ تست): صدور/V11/audit/یکتایی/Validation، جزئی→کامل+V12، Idempotency سرویس+REST (201/200/تک‌ردیف)، بیش‌پرداخت، ابطال همان‌روز/روز بعد/دلیل، بازپرداخت جزئی/کامل/سقف، اصلاحات credit/debit/M-6/V12 با credit، رسید Deterministic، خلاصه، تعرفه‌ها admin-only، ماتریس مجوزها (بیمار/پزشک/منشی/admin فنی P-3)، رگرسیون Checkout بدون فاکتور — Integration: ۲۰۳ تست سبز در CI
- **F5 (Core) — Clinical: Notes/Prescriptions/Recommendations/Follow-ups, Complete/Reopen, Patient views, Protected Files, Global Search, Doctor UI; Capability drift closed:**
  - **Drift ماتریس Capability بسته شد (دستور کارفرما):** ماتریس §4 مبنا (۴۶ cap)؛ سه قلم غیرماتریسی حذف؛ مفرغ‌های واقعی (files/search) مطابق ماتریس؛ **باگ production در `registerRole`:** حلقه sync فقط ALL_CAPS را می‌پیمود و stray capهای `cpms_*` بیرون آن هرگز حذف نمی‌شدند — اصلاح با self-healing کامل (هر cap با پیشوند `cpms_*` خارج از فهرست نقش → remove) + تست رگرسیون (998ee81)
  - **E7 پرونده کامل** `GET /visits/{id}/record` (`cpms_medical_read` — فقط پزشک): بیمار (آلرژی/زمینهه/دارو/سابقه جراحی)، ویزیت، Notes همه Visibilityها، نسخهها + اقلام، توصیهها، پیگیریها، فایلها، ویزیتهای قبلی؛ Audit `MEDICAL_RECORD_VIEWED`
  - **E8/E9 Notes:** `POST /visits/{id}/notes` (category enum هشتگانه + visibility)، `PUT /notes/{id}` Correction با `change_reason` الزامی (FR-8.5) — Snapshot نسخه قبلی در `cpms_clinical_note_versions` (append-only — K-6)، ویرایش فقط نویسنده (ماتریس 4.3)، Capability تفکیکشده `cpms_private_note_*` برای doctor_private (P-6)؛ **TP-08 Enforcement در سطح Query:** `ClinicalNoteRepository` فیلتر `?visibility` در WHERE — doctor_private هرگز به Patient/Secretary برنمیگردد حتی اگر لایههای بالاتر اشتباه کنند؛ Audit IDOR تلاش ویرایش یادداشت دیگران → 404 + `FORBIDDEN_ACCESS_ATTEMPT` (الگوی pre-check خارج از تراکنش تا Audit با Rollback از بین نرود)
  - **E10/E11 Prescriptions:** Draft با شماره ترتیلی `RX-######`، اقلام اعتبارسنجی‌شده (فرم/مسیر enum)، `POST /prescriptions/{id}/finalize` (تکرار → 409)، Void سرویسی با دلیل؛ «ویزیت خودش» (clinician متصل به کاربر — ماتریس 4.3)؛ رفع باگ `clinic_id` implicit-0 در insert (فیلترهای چند-مطب میشکستند — ADR-0003) + regression assert
  - **E12/E13:** توصیهها (type/text/is_patient_visible) + Follow-up (suggested_date یا interval_days + reason)
  - **E14/E15:** `POST /visits/{id}/complete` با Validation قابل تنظیم (Setting `clinical.require_chief_complaint` پیشفرض روشن — FR-8.7؛ شکایت اصلی ثبتنشده → 422) و `POST /visits/{id}/reopen` با دلیل الزامی (FR-8.8؛ Audit `CONSULTATION_REOPENED`)؛ complete از QueueController به ClinicalController منتقل شد
  - **C5/C6/C7 نمای بیمار** (Ownership از `cpms_patient_user_links` — P-5/P-8، بدون Capability): لیست/جزئیات ویزیتها و نسخهها فقط `patient_visible` و غیر-Draft؛ IDOR → 404 + Audit
  - **E16/E17/C3/C4 فایلهای محافظت‌شده (TP-06):** `LocalFileStorage` خارج از uploads (Setting `files.storage_path` مطلق یا پیشفرض `wp-content/clinic-files`) با نام تصادفی 32-hex، چیدمان `{clinic}/{xx}/{name}`، گاردهای `.htaccess` (Require all denied) + `index.php`؛ MIME واقعی با `finfo_buffer` روی محتوا + تطابق پسوند + سقف `files.max_upload_bytes` (پیشفرض 10MB)؛ RateLimit آپلود 10/hr هر دو مسیر؛ Stream باینری مستقیم (نه Envelope) با nosniff/Disposition؛ ماتریس دسترسی: بیمار=خودش+patient_visible، پزشک=همه، منشی=`cpms_file_read` فقط patient_visible، بقیه → 404+Audit؛ Audit `FILE_UPLOADED/FILE_READ (doctor_private|lab_result)/FILE_SOFT_DELETED`
  - **E18 جستجوی جامع Role-Aware** `GET /search?q=&type=patient|note|rx|all&from&to` (`cpms_search` — منشی+پزشک): منشی فقط بیمار (فاقد capهای خواندن بالینی — TP-08 در جستجو)، پزشک همه انواع با Snippet کوتاه یادداشت (بدون متن کامل)؛ Audit `SEARCH_EXECUTED` با q/type/شمار نتایج
  - **DoctorDashboardPage (WP-Admin، F5 UI):** نمای صف زنده پزشک متصل (polling 5s + توقف تب مخفی) با Call/Recall/Start (E3–E5) + **صفحه ویزیت One-Page:** هدر بیمار + بنر آلرژی (E7)، فرم یادداشت با Visibility و اصلاح نسخه‌دار (E8/E9)، نسخه Draft/Finalize (E10/E11)، توصیه/پیگیری (E12/E13)، آپلود/مشاهده فایل (E16/E17)، پایان ویزیت با پیام Validation و بازگشایی با دلیل (E14/E15) — بدون PHI در HTML اولیه (همه از REST)
  - Settings جدید: `files.storage_path`، `clinical.require_chief_complaint` (+ همراستاسازی `files.max_upload_bytes` با settings-reference)
  - تستها: `ClinicalFlowTest` (۲۵ تست — جریان کامل + TP-08 سطح Query و API + IDOR + Validation + نسخهبندی + reopen + C5/C6/C7 + E18) و `MedicalFilesTest` (۱۲ تست — TP-06 خارج uploads/گاردها، polyglot php-in-jpg، MIME/ext/size، ماتریس دسترسی، soft delete، C3/C4 REST، 401 ناشناس) — Integration: ۱۸۴ تست/۸۳۵ assertion، ۰ skip در CI
- **F4 (Core) — Visit/Queue flow, Real-time polling (R1), Secretary dashboard:**
  - جریان مراجعه کامل: `POST /visits/checkin` (D6 — با مسیر ER-06 دیرهنگام: نوبت past-grace → `no_show` + Visit فوری Walk-in-like با حفظ ارجاع)، `POST /visits/walk-in` (D7، `clinician_id` الزامی)، `POST /visits/{id}/status` (D8 — نگاشت `to_status` منشی → event ماشین: enqueue/cancel/invoice_ready/waive)، `POST /visits/{id}/checkout` (D16 — `paid → check_out` یا معافیت با دلیل الزامی)
  - داشبوردها: `GET /secretary/today` (D1 — آمار + صف زنده)، `GET /doctor/today` (E1)، `GET /queue` (E2)؛ اکشن‌های پزشک E3–E6 (call/recall/start/skip) + E14 (complete)
  - Real-time V1 (ADR-0007): `GET /rt/queue?since={event_id}` با ETag/If-None-Match → 304 + Rate limit per-user؛ فید از `cpms_visit_status_history` (append-only، محدود به امروز)
  - `VisitService`: اجرای کامل ماشین V1–V15 با Row-Lock (J-1 یکتایی complete)، تاریخچه append-only (J-3)، ترتیب صف FIFO + نوبت فوری از `is_walkin_express` (J-4 بدون تغییر Schema)، ویزیت فعال واحد بیمار×پزشک×روز با قفل بیمار (J-5 → `CLINIC_DUPLICATE_ACTIVE_VISIT`)، سقف recall از `queue.max_recalls` (J-6)، تکمیل نوبت مرجع در خروج (T9) + `VisitRepository` (ADR-0021) + `VisitException`
  - No-show خودکار (FR-5.5): sweep داخلی Check-in (lazy) + جاب تکرارشونده `visits.no_show` (هر دقیقه؛ فقط نوبت‌های confirmed بدون Visit فعال پس از `queue.no_show_grace_minutes`)
  - `SecretaryQueuePage` (WP-Admin، `cpms_queue_read`): داشبورد زنده امروز/Drawer گردش کار/Walk-in/Keyboard (W/C/R//Esc) با polling 3s + توقف روی تب مخفی (Page Visibility) — بدون endpoint جدید
  - کدهای خطای جدید: `CLINIC_INVALID_APPOINTMENT_STATE`، `CLINIC_RECALL_LIMIT_REACHED`
  - سیاست لایسنس صف/مراجعه (§18 کارفرما، Seam ADR-0023): Walk-in مستقل (ویزیت جدید بدون نوبت) در حالت Read-Only لایسنس → `CLINIC_LICENSE_BLOCKED/503`؛ Check-in نوبت از پیش موجود و Transitionهای ویزیت در جریان تا اتمام ایمن مجاز؛ بدون Network Call در مسیر درخواست (تست `VisitLicenseGateTest` با Gate تزریقی)
  - آمار داشبورد امروز غنی‌ شد: `appointments_today`، `appointments_no_show`، `walk_in_today`؛ حذف PHI غیرضروری (موبایل/کد ملی) از SELECT صف؛ Fallback خطای داخلی صف → `CLINIC_INTERNAL_ERROR/500`
  - تست‌ها: `VisitFlowTest` (TP-19 جریان‌های دیرهنگام/no-show)، `VisitConcurrencyTest` (TP-03b سه‌لایه: fork موازی DB-level + سرویس + Row-Lock واقعی)، `RestQueueTest` (TP-04/07/09 + R1 ETag/304)، `JobQueueTest` (چرخه جاب تکرارشونده)، `VisitLicenseGateTest` — Integration: ۱۳۸ تست/۵۸۵ assertion، ۰ skip در CI
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
