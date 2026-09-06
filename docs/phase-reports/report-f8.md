# گزارش فاز F8 — لایه اعلان + گزارش‌ها (Notification Layer + Reports/Export)

**فاز:** F8 — اعلان + گزارش | **تاریخ:** 2026-09-06 | **ایجنت:** Arena (`arena/01a071c4-doctor`)
**مراجع:** SRS §3.19 (FR-19.1..19.4) و §3.20 (FR-20.1..20.4)، notifications.md (N-1..N-6 + §3 کاتالوگ + §5)، background-jobs.md (appt.reminder/notif.dispatch/fu.reminder/report.export)، ADR-0025 (SMS Provider-agnostic)، ADR-0026 (D-8/D-15 — Scope سرور-side)، api-contract G5/G6/R2، data-dictionary §32، permission-matrix §2/§3/§6، performance-baseline §18 (Export async)، file-storage (خارج webroot)، ADR-0007 (ETag/304)

---

## 1. خلاصه

دو زیرسیستم در یک فاز تحویل شد:

1. **Notification Layer کامل (N-1..N-6):** جدول `cpms_notifications` queue-native (INSERT با status=queued) + `NotificationService` (publish به Staff/Patient/User، Dedupe الگوی SmsService، Cancel-on-appt-cancel، Quiet-hours، retention 90 روز) + کاتالوگ رویداد `NotificationEvents` (Jalali در قالب‌ها) + Job تکرارشونده `notif.dispatch` (هر ۲ دقیقه: queued→sent + purgeExpired در همان Job) + اتصال رویدادهای واقعی سیستم (QUEUE.called/ready_payment، APPT.confirmed/changed/cancelled، یادآوری نوبت eve/morn، Follow-up، «Export آماده شد») + REST inbox (G6) و Real-time badge (R2 با ETag/304) + UI زنگ/پنل/Toast در صفحه صف منشی.
2. **گزارش‌ها (FR-19.2/19.3):** `ReportService` با **۱۲ نوع گزارش**، مدل مجوز per-type + **Scope سرور-side** (پزشکِ متصل = OWN اجباری؛ Aggregate مطب فقط با اعطای صریح بدون Clinician-Link — D-8/D-15) + **Export غیرهمگام** (Job → CSV با BOM + محافظ Formula-injection در ذخیره‌سازی محافظت‌شده + اعلان آمادگی + دانلود فقط-مالک با Audit EXPORT + retention ۷ روز) + **Print View با Watermark** (کاربر+زمان+Scope؛ چاپ مرورگر بدون Dependency PDF).

## 2. ماتریس Acceptance Criteria (SRS §3.20 + §3.19)

| FR | شرط | وضعیت | پیاده‌سازی |
|---|---|---|---|
| FR-20.1 | رویداد → صف → ارسال (Internal/SMS)، Retry/Dedupe | ✅ | cpms_notifications (N-2 queue-native) + Job `notif.dispatch`؛ Dedupe با `dedupe_key` UNIQUE (N-5)؛ Retry با backoff در JobQueue موجود |
| FR-20.2 | رویدادهای الزامی: APPT confirmed/changed/cancelled، Reminder، Follow-up، QUEUE.called، Export آماده | ✅ | BookingService (SMS+Internal هم‌زمان)، VisitService (call/invoice_ready)، ApptReminder/FollowUp/Export Handlers |
| FR-20.3 | Inbox بیمار/کارمند + خوانده‌شده | ✅ | GET/POST `/notifications`، `/notifications/read` (ids/all) — G6 «نقش خود» سرور-side |
| FR-20.4 | Real-time Badge + Polling سبک | ✅ | GET `/rt/notifications` (R2: ETag/304 + `since`، rate 60/min)؛ UI poll هر ۱۰s |
| N-4 Quiet hours | SMS غیرتعاملی فقط 08:00–21:00 (OTP مستثنا) | ✅ | `notif.quiet_hours_*` — فقط مسیر یادآوری‌ها؛ Internal همیشه |
| FR-19.2 | ۱۲ گزارش با مجوز و Scope | ✅ | کاتالوگ `/reports` (per-actor: type/scope/available) + ۱۲ اجراکننده |
| FR-19.3 | Export با Watermark/Audit/محافظت فرمول | ✅ | Print View Watermark + Export async CSV (BOM + guard `'=+-@`) + Audit EXPORT (request/download) + دانلود مالک‌محور |
| FR-19.1/19.4 | داشبورد امروز/محیط کار | — | خارج از F8 (داشبورد منشی/پزشک در F4/F5 موجود) |

## 3. پروتکل اعلان‌ها (N-1..N-6 — پیاده‌شده)

- **کانال‌ها:** Internal = cpms_notifications (جدید)؛ SMS = پایپ‌لاین Provider-agnostic موجود (ADR-0025، بدون دو-صف‌کردن)؛ Email/Push = V2 (کاتالوگ notifications.md).
- **چرخه (N-2):** INSERT با `status='queued'` (داخل همان Transaction گردش‌کار) → Job تکرارشونده `notif.dispatch` هر ۲ دقیقه: queued→sent (attempts/backoff از JobQueue) + **purgeExpired** (حذف sent/read قدیمی‌تر از `notif.archive_days`=90d) داخل همان Job — بدون cron جدید (الگوی RECURRING_JOBS).
- **Dedupe (N-5):** الگوی SmsService — SELECT قبل INSERT؛ queued/sent/delivered → skip (null)، cancelled/failed → نسل جدید با پسوند `-{id}` (بدون برخورد UNIQUE). کلیدها per-recipient:
  - APPT: `apt:{id}:appointment_confirmed:p{pid}` / `:appointment_cancelled:p{pid}` / `:appointment_rescheduled:p{pid}` / `apt:{id}:remind:{eve|morn}`
  - QUEUE: `queue:called:v{visitId}:r{recallCount}` (هر Recall چرخه جدید) / `queue:pay:v{visitId}` — گیرنده: منشی‌های دارای QUEUE_READ به‌جز فراخواننده
  - FOLLOW_UP: `fu:{id}:remind` — EXPORT: `export:{exportId}:u{userId}`
- **انصراف خودکار (§5):** لغو یا جابه‌جایی نوبت → `cancelQueuedForAppointment(id)` — همه اعلان‌های queued با پیشوند `apt:{id}:` کنسل می‌شوند (یادآوری‌های بی‌موضوع).
- **Quiet hours (§5):** فقط روی SMS یادآوری‌ها (appt.reminder) — بازه `notif.quiet_hours_start/end` به وقت Timezone کلینیک با پشتیبانی بازه overnight؛ **اعلان Internal همیشه** (مزاحم نیست)؛ OTP مستثنا (مسیر inline دست‌نخورده).
- **قاعده نابودنکردنی (کارفرما):** شکست اعلان هرگز گردش‌کار اصلی (transition/booking) را نمی‌شکند — try/catch + op-log warning (الگوی BookingService SMS).

## 4. مدل مجوز و Scope گزارش‌ها (ADR-0026 D-8/D-15)

- **Scope سرور-side:** کاربرِ متصل به `cpms_clinicians.wp_user_id` = **OWN** (فیلتر `clinician_id` اجباری در SQL — هیچ پارامتری از کلاینت آن را عوض نمی‌کند)؛ Aggregate مطب **فقط** برای دارنده `cpms_report_read` **بدون** Clinician-Link (اعطای صریح — الگوی حسابدار ماتریس §6). پزشک متصل هرگز Aggregate کل مطب نمی‌گیرد (403).
- **Caps per-type (D-8 تفکیک):** گزارش‌های مالی (revenue/payment_methods/open_balances) = `cpms_finance_read`، بدون نام بیمار (Aggregate⊥Detail — D-15)؛ عملیاتی با نام بیمار (appointments/cancellations/no_shows/walk_ins/visits) = `cpms_patient_read`؛ avg_waiting/visit_duration = `cpms_report_read`؛ follow_ups_due = `cpms_medical_read`. منشی بدون `cpms_report_read` = 403 (پیش‌فرض ماتریس §2).
- **Notes خصوصی هرگز کوئری نمی‌شوند** (visibility='doctor_private' در هیچ گزارشی SELECT نمی‌شود) — تست نشت دارد.
- **Bounded ranges:** `reports.max_range_days`=366؛ فرمت تاریخ غلط/بازه معکوس → 422 `CLINIC_VALIDATION_FAILED`.

## 5. معماری و کد

| قطعه | مسیر | نقش |
|---|---|---|
| Migration 0005 | `src/Migrations/2026_09_07_0005_notifications.php` | `cpms_notifications`: `read_at DATETIME(3)` + ایندکس `idx_notif_patient` — idempotent با SHOW COLUMNS/SHOW INDEX |
| کاتالوگ | `src/Domain/Notifications/NotificationEvents.php` | رویدادها + قالب‌ها (fa، Jalali از قبل در Jalali::formatYmd) + isKnown/render |
| سرویس | `src/Application/Notifications/NotificationService.php` | publishToStaff/Patient/User + inbox/since/unread + markRead + cancelQueuedForAppointment + smsQuietHoursOpen + dispatchQueued/purgeExpired |
| Repository | `src/Infrastructure/Repository/NotificationRepository.php` | CRUD + کوئری‌های per-recipient (forPatient/forUser/unreadCount/lastId/findByDedupeKey/cancelQueuedForAppointment) |
| گزارش | `src/Application/Reports/ReportService.php` | کاتالوگ per-actor + اجرای ۱۲ گزارش + resolveScope سرور-side + rowLimit + Audit REPORT_READ |
| Export | `src/Application/Reports/ExportService.php` | request (202) → generate (CSV: BOM + Formula-guard `'=+-@` → LocalFileStorage خارج webroot + اعلان آمادگی) + listFor/download (مالک) + purgeExpired |
| Exception | `src/Application/Reports/ReportException.php` | errorCode/httpStatus/data → Envelope استاندارد CLINIC_* |
| Jobs | `src/Application/Jobs/{NotifDispatch,ApptReminder,FollowUpReminder,ReportExport}Handler.php` | ۴ Handler تکرارشونده؛ ApptReminder با quiet-hours و dedupe دو-لایه (J-2: rerun همان روز = 0)؛ FollowUp با `reminder_sent_at` |
| REST | `src/Rest/NotificationsController.php` | G6 (GET/POST notifications، /read) + R2 (/rt/notifications با ETag/304/rate 60min) |
| REST | `src/Rest/ReportsController.php` | کاتالوگ + اجرا + print (Watermark HTML) + export (202) + exports + download (Attachment) |
| UI | `src/Admin/SecretaryQueuePage.php` | زنگ 🔔 + Badge unread + پنل اعلان + Toast (queue_called=📣، سایر به‌جز export=💰) + Poll 10s با `since` |
| Wiring | `src/Bootstrap/App.php` | factoryهای static-cache + routes + dispatcher با ۴ Handler + RECURRING_JOBS (۷ entry: +notif.dispatch=6, appt.reminder=4, fu.reminder=4, report.export=1) |
| تست | `tests/Integration/{NotificationFlowTest,ReportsAuthzTest}.php` | ۲۲ تست (§7) |

**Settings جدید:** `notif.quiet_hours_start/end` (08:00/21:00)، `notif.archive_days`=90، `reports.max_range_days`=366، `reports.export_retention_days`=7، `reports.export_max_rows`=10000.

## 6. Export — جریان کامل (FR-19.3 + performance-baseline §18)

1. `POST /reports/{type}/export` (nonce + cpms_report_read؛ cpms_export برای نقش‌های پویا — V2) → اعتبارسنجی Scope/بازه همان‌جا (خطا همگام) → درج `cpms_report_exports` + Job `report.export` → **202 `{status:'queued', job_id}`** + Audit `EXPORT (request)`.
2. Handler (async): اجرای گزارش (سقف `reports.export_max_rows`=10000) → CSV با **BOM UTF-8** + **محافظ Formula-injection** (ردیف‌هایی که با `= + - @` شروع می‌شوند با `'` پیشوند می‌خورند) → ذخیره در LocalFileStorage (خارج webroot، مسیر غیرقابل حدس) + اعلان Internal `report_export_ready` به درخواست‌دهنده + Audit `EXPORT (generate)`.
3. `GET /reports/exports` — فهرست Exportهای خود Actor (status/file_name/created/expires)؛ `GET /reports/exports/{id}/download` — **فقط مالک** (کاربر دیگر → 404) + Audit `EXPORT (download)` + هدرهای Attachment/no-cache/nosniff.
4. Retention: purgeExpired داخل همان Job — فایل و ردیف DB بعد از `reports.export_retention_days`=7 روز حذف؛ دانلود منقضی → 410 `CLINIC_EXPORT_EXPIRED`.
5. **Print View:** `GET /reports/{type}/print` — HTML چاپی RTL با Watermark ثابت (نام کاربر + زمان UTC + Scope) روی صفحه و در هدر متا؛ PDF سمت سرور = Backlog (پیش‌زمینه F6 — بدون Dependency جدید).

## 7. تست‌ها (TP-13 + Report Tests)

**NotificationFlowTest — ۱۱ تست:**
1. `testCallEnqueuesInternalNotificationForSecretariesNotActor` — QUEUE.called → اعلان به همه منشی‌های QUEUE_READ به‌جز فراخواننده
2. `testRecallThenCallCreatesNewGenerationButNoDuplicateWithinGeneration` — Recall چرخه جدید (r+1)؛ بدون تکرار در همان چرخه
3. `testInvoiceReadyNotifiesOtherSecretaries` — QUEUE.ready_payment → منشی‌ها
4. `testDispatchJobFlipsQueuedToSentIdempotently` — notif.dispatch: queued→sent؛ رکورد قبلاً sent دوباره ارسال نمی‌شود
5. `testInboxMarkReadAndRtNotifications` — G6 Inbox فقط رکوردهای خود گیرنده + markRead(ids) + read_at + R2: ETag/304 + `since` (بدون جدید → [])
6. `testPatientInboxShowsAppointmentNotificationsWithJalali` — APPT confirm → SMS + Internal هم‌زمان (Jalali در body)
7. `testCancelAppointmentCancelsQueuedRelatedNotifications` — لغو توسط مطب → اعلان cancelled به بیمار + یادآوری queued کنسل (§5)
8. `testApptReminderJobSendsSmsAndInternalWithDedupe` — فاز eve + SMS+Internal + **rerun همان روز = 0** (J-2)
9. `testApptReminderRespectsQuietHoursForSmsOnly` — Quiet hours بسته → فقط Internal (SMS صفر)؛ بعد از باز شدن → SMS
10. `testFollowUpReminderJobMarksReminderSentAt` — fu.reminder → SMS + Internal + `reminder_sent_at` + rerun=0
11. `testF8JobsAreScheduledRecurring` — `scheduleRecurringJobs` دقیقاً یک QUEUED برای هر ۴ نوع جدید

**ReportsAuthzTest — ۱۱ تست:**
1. `testCatalogListsTwelveTypesForDoctorWithOwnScope` — کاتالوگ ۱۲ نوع برای پزشک متصل (scope=own)
2. `testSecretaryDeniedByDefault` — منشی بدون cap → 403 CLINIC_PERMISSION_DENIED
3. `testDoctorScopeIsOwnServerSide` — پزشک فقط داده خود (۲ ویزیت خودش از ۳)؛ پارامتر کلاینت بی‌اثر
4. `testAggregateClinicScopeOnlyByExplicitGrant` — Aggregate فقط با اعطای صریح (حسابدار بدون Link → scope=clinic؛ پزشک متصل → 403)
5. `testDoctorFinancialReportDoesNotGrantClinicalAccess` — گزارش مالی به ops بدون finance_read → 403
6. `testAggregateReportsComputeCorrectly` — revenue net/payment_count، avg_waiting، visit_duration، payment_methods، open_balances، no_shows، walk_ins، cancellations، follow_ups_due
7. `testRangeValidationIsBounded` — بازه > 366 روز → 422؛ تاریخ غلط → 422
8. `testPrivateDoctorNotesNeverLeakIntoReports` — Notes خصوصی (doctor_private) هرگز در گزارش‌ها نیست
9. `testExportFlowAuthorizationAuditAndFormulaInjection` — 202 → tick → CSV (BOM + Formula-guard `=cmd`→`'=cmd`) + اعلان آمادگی + Audit EXPORT (request/download) + doctorB دانلود Export doctorA → 404
10. `testExportWithoutReportReadCapDenied` — Export بدون report_read → 403
11. `testPrintViewContainsWatermarkAndRows` — Print View: Watermark (نام کاربر) + ردیف‌ها در HTML

**CI نهایی:** Integration = **۲۴۰ تست** (۲۱۸ قبلی + ۲۲ جدید)، ۰ خطا — Unit 8.1/8.2/8.3/8.4 هم سبز.

## 8. انحرافات و تصمیم‌های مستندشده

| انحراف/تصمیم | توضیح |
|---|---|
| notif.dispatch هر ۲ دقیقه (نه هر دقیقه) | background-jobs.md بازه پیشنهادی؛ ۲ دقیقه با اولویت 6 کافی و ارزان‌تر — Handler idempotent |
| purgeExpired داخل notif.dispatch (نه Job جدا) | یک Job کمتر؛ همان الگوی handwriting.gc (retention مستمر به‌جای بچ) |
| Email/Push | V2 طبق کاتالوگ notifications.md (کاهش عمدی) |
| PAYMENT.receipt اعلان | V1 رها (اختیاری در کاتالوگ) |
| PDF سمت سرور | Backlog (پیش‌زمینه F6) — Print HTML با Watermark پوشش FR-19.3 را می‌دهد |
| cpms_export cap | فقط برای نقش‌های پویا (V2/ADR-0026) — در V1 همه Exportها از مسیر report_read می‌گذرند و Audit EXPORT هر دو سو را ثبت می‌کند |
| Toast منشی بدون Toast UI جدید | Toast در SecretaryQueuePage (زنگ در هدر + پنل) طبق wireframe؛ اعلان بیمار در ورودی V1.5 (پورتال بیمار) |

## 9. CI

| کامیت | Run | نتیجه |
|---|---|---|
| c223f79 (کد اول F8) | 34014509158 | ❌ Fatal: `private const NS` در NotificationsController دیدگاه protected والد را تنگ‌تر می‌کند (PHP: بازتعریف ثابت فقط می‌تواند گسترده‌تر شود) |
| 7ceb763 (NS → protected) | 34014623787 | ❌ 12E+1F — سه ریشه: ① ارجاع `&$this->prop` روی typed property مقداردهی‌نشده (PHP 8.1+) ② query-string در route با `rest_do_request` مچ نمی‌شود (regex مسیر `$`-انکر است → 404؛ پارامترها باید با set_param/GET param داده شوند) ③ شمارش rerun یادآوری باید «فقط تازه‌ها» بشمارد (insertNotification روی dedupe null برگرداند) |
| **f11f6e0 (اصلاح تست‌ها)** | **34015519073** | ✅ **سبز ۵/۵** (Unit PHP 8.1/8.2/8.3/8.4 + Integration WP 6.7+MySQL 8) — **Integration: ۲۴۰ تست، ۰ خطا** |
| **docs نهایی (این گزارش)** | — | docs-only |

**درس‌های ثبت‌شده:** `rest_do_request` رشته کوئری را در route پارس نمی‌کند — پارامترهای GET در تست‌ها باید با `set_param` ست شوند (GET→params['GET']). بازتعریف ثابت کلاس نمی‌تواند visibility را تنگ‌تر کند (`private` روی `protected` والد = Fatal).

## 10. Docs Sync (بسته‌شده در همین فاز)

- `docs/api/api-contract.md`: G5 (کاتالوگ + ۱۲ گزارش + print + export + exports/download) و G6 (inbox/read) کامل؛ R2 بازنویسی شد (ETag/304/since/rate 60min)
- `docs/erd/data-dictionary.md` §32: `read_at DATETIME(3)` + `idx_notif_patient`
- `docs/settings-reference.md`: ۶ کلید جدید (notif.*/reports.*)
- `CHANGELOG.md`: ورودی F8
- `docs/roadmap/roadmap.md`: F8 ✅
- `docs/agent-guide.md`: لاگ F8 (این گزارش)
- `tests/Integration/MigrationTest.php`: LATEST_VERSION → `2026_09_07_0005`

## 11. تحویل و گام بعد

- **DoD F8:** کد ✓ + ۲۲ تست Integration جدید (کل ۲۴۰ سبز) ✓ + **CI سبز ۵/۵ (run 34015519073 @ f11f6e0)** + این گزارش ✓
- **گام بعد (با تأیید کارفرما):** F9 — Hardening (Security Review T-01..T-24، Performance NFR-PERF-1، Backup/Restore TP-16، Accessibility، مستندات کاربری، Pilot). موارد ثبت‌شده برای F9: ایندکس `u_idem_key` (گزارش F7 §9) + بنر Duplicate در لاگ تست‌ها.
