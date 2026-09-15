# معماری Background Jobs — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | **به‌روزرسانی آشتی نهایی فاز ۲: 2026-09-15** (بند جدید **J-5a** — سیاست شکست قطعیِ نوع‌دارِ M-4؛ بازنگری **§5** — پیاده‌سازی قرارداد طبقه‌بندی/اسکوپ و وضعیت جاری؛ یادداشت تکمیلی **§5.1** — تایم‌زونِ صریحِ Location برای ساعات سکوتِ یادآوری‌ها)

## 1. اصول
- **J-0** همه Jobهای آهسته/تکراری از Request خارج‌اند (NFR-PERF-5).
- **J-1** Queue متمرکز: `cpms_jobs` (docs/erd) + Dispatcher — `JobQueue` Interface (تعویض‌پذیر).
- **J-2** **Idempotency اجباری:** هر Job Handler باید تکرارپذیر باشد (Lock + Status Check).
- **J-3** Single Worker V1: یک Runner با Row Lock (`locked_by + lock_expires_at`) → بدون Duplicate اجرا؛ V2: Worker خارجی (CLI daemon) بدون تغییر Handler.
- **J-4** Tick: Cron OS-level هر دقیقه `wp cron` / `bin/jobs tick` (نه فقط WP-Cron که وابسته به ترافیک است).
  - هر دو مسیر Runner (اکشن WP-Cron `cpms_jobs_tick` و `bin/cpms jobs tick`) از `App::runTick()` واحد عبور می‌کنند: ثبت Heartbeat + **زمان‌بندی مجدد Idempotent جاب‌های دوره‌ای** (`scheduleRecurringJobs`) + پردازش صف. بدون این، در استقرار system-cron جاب‌های دوره‌ای فقط یک‌بار (بعد از Activate) اجرا می‌شدند (FR-5.5 — Regression Pilot Gate 2026-09-06).
- **J-5** Retry: `attempts < max_attempts` → `run_after += backoff(attempts)` (1m/5m/15m/1h)؛ شکست نهایی → `failed` + Operational Log + Alert (Internal Notification به مدیر فنی).
- **J-5a (M-4 — تفکیک شکست قطعی/گذرا؛ وضعیت جاری):** قراردادِ نشانگرِ
  `NonRetryableJobFailure` (`src/Application/Jobs/NonRetryableJobFailure.php`): تصمیمِ
  «غیرقابلِ تلاشِ مجدد» **فقط بر پایهٔ نوعِ** Throwable گرفته می‌شود، هرگز متنِ پیام.
  هر `Throwable` دارایِ این نشانگر در **همان اولین تلاش** توسط
  `JobsDispatcher` → `JobQueue::failTerminal()` نهایی (`failed`) می‌شود — **بدونِ
  Requeue/Backoff عمومی**. موردِ صریحِ فعلی: **`JobPayloadInvalidException`**
  (payload نامعتبر = شکستِ قطعی؛ اجرای مجدد همان payload همان شکست را بازتولید می‌کند).
  شکست‌های معمولی/گذرا همان مسیر قبلی را حفظ می‌کنند (`attempts < max_attempts` →
  بازصف با backoff؛ وگرنه `failed`). رفتارِ **stale-lock/بازیابی جدا و بدون تغییر** است
  (بازیابیِ قفلِ منقضی فقط وقتی `attempts < max_attempts` اجازهٔ تلاشِ واقعی می‌دهد).
  این نشانگر عمداً به خانواده‌های عمومیِ خطا (مثل همهٔ مواردِ `ScopeRequiredException`
  یا همهٔ خطاهای پیکربندی) تعمیم داده **نشده** است؛ هر مورد تصمیمِ محصولیِ جداگانه می‌خواهد.

## 2. فهرست Jobها

| Type | فرکانس | مسئولیت | Idempotency |
|---|---|---|---|
| `slots.generate` | روزانه (02:00) + Lazy | تولید Slotهای 30 روز آینده (K-2 Unique) | INSERT ... ON DUPLICATE / Check exists |
| `holds.expire` | هر دقیقه | آزادسازی Hold منقضی (`status, expires_at` idx) | UPDATE شرطی status |
| `appt.expire_pending` | هر 5 دقیقه | `pending` منقضی → `cancelled_by_staff` (T4) | Transition check |
| `no_show.detect` | هر 5 دقیقه | نوبت‌های `confirmed` + Grace گذشته بدون Visit → `no_show` (T8) + اعلان | Transition check |
| `appt.reminder` | روزانه 21:00/08:00 | یادآوری نوبت‌های فردا/امروز | `dedupe_key` |
| `notif.dispatch` | هر دقیقه | ارسال اعلان‌های `queued` + Retry `failed` | Status check + Provider ref |
| `fu.reminder` | روزانه 09:00 | Follow-Up سررسید | `reminder_sent_at` |
| `ocr.recognize` | رویدادی | تشخیص دست‌خط (V1.5) | Job row lock |
| `handwriting.gc` | روزانه | پاک‌سازی Versionهای دست‌خط (سیاست نگهداری) | Delete with condition |
| `cleanup.otp` | روزانه | حذف `otp_tokens` >24h | — |
| `cleanup.idempotency` | روزانه | حذف کلید >90 روز | — |
| `cleanup.holds` | روزانه | حذف رکوردهای hold >7 روز | — |
| `audit.chain_verify` | روزانه | صحت‌سنجی Hash Chain Audit (آخرین 10k + نمونه) | Report only |
| `report.export` | رویدادی | Exportهای سنگین (CSV/PDF) → فایل + اعلان | Job row |
| `backup.run` | هر Tick (چک) / دورهای با `backup.interval_hours` | بکاپ DB cpms_* + storage + مانیفست (F10 — موتور داخل افزونه؛ spec §22؛ جانشین `backup.trigger` مفهومی) | `backup.enabled` + سررسید + Lock صف |
| `license.refresh` | هر Tick (چک) / Backoff | refresh سند مجوز از سرور فروشنده (F10/ADR-0023) — هرگز در مسیر درخواست | `refreshDue()` + Backoff بر اساس شکستهای پیاپی |
| `temp.cleanup` | روزانه | فایل‌های موقت/Preview مهلت‌گذشته | — |

> ⚠️ **یادداشتِ drift (2026-09-12 — بدونِ بازنویسیِ جدولِ تاریخی):** فهرستِ بالا **منبعِ حقیقتِ
> runtime نیست**. منبعِ حقیقت = `App::dispatcher()`/`RECURRING_JOBS`
> (`clinic-practice-management/src/Bootstrap/App.php`) با **۱۵ نوعِ ثبت‌شده**. جزئیاتِ اختلاف
> (نام‌های ثبت‌نشده، نام‌های ناهمسان، انواعِ غایب، و تفاوتِ «فرکانس/افقِ» ثبت‌شده با رفتارِ واقعیِ
> زمان‌بندی) در **`docs/drift-register.md` §۹-B** ثبت شد.

## 3. Alert و پایش
- هر `failed` (نهایی) → `cpms_operational_logs(level=error)` + Internal Notification به `cpms_config` holders.
- Metric ساده (تعداد queued/failed در صفحه Admin فنی) — V1؛ Export Metrics (V2).

## 4. Test
- هر Handler: Unit Test (Idempotency: دو بار اجرا = یک اثر) + Integration (Queue→Worker).
- TP-13: Job `holds.expire` → Slot آزاد + Hold status=expired (تکرار = بدون اثر).

## 5. Phase 2 — Tenant Context برای Jobها (✅ قراردادِ طبقه‌بندی/اسکوپ پیاده‌سازی شد — تکمیلِ فنی)

> **به‌روزرسانی آشتی نهایی (2026-09-15 — وضعیت جاری):** قراردادِ طبقه‌بندیِ **T/S/W**
> (Tenant-scoped / System-wide / Installation-wide sweep) برای هر **۱۵ نوعِ ثبت‌شده** در
> `App::dispatcher()` **پیاده‌سازی شده است**: منبعِ حقیقتِ زمانِ اجرا
> **`src/Application/Jobs/JobScopeRegistry.php`** (به‌همراهِ `JobScopeClass`) است —
> ۲ نوعِ `T` (`sms.send`, `report.export`)، ۶ نوعِ `S` (`cleanup.otp`, `cleanup.rate_limits`,
> `cleanup.idem`, `cleanup.oplog`, `license.refresh`, `backup.run`) و ۷ نوعِ `W`
> (`holds.expire`, `slots.generate`, `visits.no_show`, `notif.dispatch`, `appt.reminder`,
> `fu.reminder`, `handwriting.gc`). نوعِ بدونِ طبقه **fail-closed** رد می‌شود
> (`JOB_SCOPE_UNCLASSIFIED`) و `JobsDispatcher` به‌صورت **پیش‌فرض** سخت‌گیر است؛ حالتِ سهل‌گیر
> فقط با opt-in صریحِ زیرساختِ تست ممکن است. سازگاریِ این سه منبعِ حقیقت (رجیستری /
> زمان‌بندی `RECURRING_JOBS` / handlerهای دیسپچر) با تست محافظت می‌شود (RT-12 drift guard).
> مشخصاتِ کانونیِ طراحی همچنان در سند
> [`phase2-tenant-context-remediation-design.md`](phase2-tenant-context-remediation-design.md)
> ثبت است؛ آن سند **هیچ شمارهٔ migration آینده‌ای را رزرو نکرد** (آخرین migration = `0020`).
>
> **وضعیتِ جاری (راستی‌آزمایی‌شده با بازرسیِ کد روی `35acced`):** هر **۸** شغلِ کانونیِ
> فهرستِ **M-2** — `appt.reminder`, `visits.no_show`, `slots.generate`, `fu.reminder`,
> `notif.dispatch`, `cleanup.oplog`, `backup.run`, `handwriting.gc` — روی main
> **حل شده‌اند** (ساختِ scope-neutral / وابستگی‌های `SettingsFactory` و `InstallationSettings`
> به‌جای `App::settings()`/`App::scope()` سراسری). `cpms_jobs` طبقِ تصمیمِ مستند **هیچ ستون
> `clinic_id`‌ای نگرفت** — قاعدهٔ «T ⇒ غیرتهی / S,W ⇒ NULL» در سطحِ قراردادِ رجیستری + تست
> برقرار است، نه ستونِ داده. بیانِ قدیمیِ «پیاده‌سازی نشده / هیچ کد، تست یا مهاجرتی نوشته
> نشده است» در نسخه‌های پیشین همین بند، توصیفِ **تاریخیِ** وضعیتِ پیش از ادغامِ برش‌های
> فاز ۲ است و دیگر وضعیتِ جاری نیست.
>
> ⛔ **منبعِ حقیقتِ runtimeِ انواعِ Job، جدولِ §۲ همین سند نیست** — بلکه
> **`App::dispatcher()` + `RECURRING_JOBS`** در `src/Bootstrap/App.php` است (**۱۵ نوعِ ثبت‌شده**،
> که ۱۳ نوع زمان‌بندی می‌شوند و `sms.send`/`report.export` رویدادمحورند). جدولِ §۲ **driftِ
> ثبت‌شده** دارد (نام‌های ثبت‌نشده در کد، نام‌های ناهمسان، و انواعِ ثبت‌شدهٔ غایب از آن) و بدونِ
> بازنویسی، به‌عنوانِ **سندِ تاریخی/مفهومی** باقی می‌ماند: `docs/drift-register.md` §۹-B.
> طبقهٔ scopeِ هر یک از ۱۵ نوعِ واقعی در سندِ کانونی §A-3 ثبت شده و **از فاز ۲ به‌صورتِ
> اجرایی در `JobScopeRegistry` پیاده‌سازی شده است** (نه در این جدول).

### 5.1 T3 — `appt.reminder` (implementation slice; Phase 2 COMPLETE — technical)

`appt.reminder` is a **W-sweep**. The queued root payload remains the compatible empty
object. A bounded execution obtains one UTC reference instant, normalizes it as
`Y-m-d\TH:i:s\Z`, and uses that same instant in every continuation. A continuation is an
`appt.reminder` job whose `payload_json` contains exactly `continuation: true`, `version: 1`,
the fixed `reference_utc`, and the keyset cursor `{slot_date, slot_time, id}`. The payload is
progress state only: it contains no trusted Clinic scope and malformed payloads fail closed with
`JOB_PAYLOAD_INVALID`; they never restart as a root sweep or establish `ScopeContext`.

The candidate query joins each Appointment to its persisted Location using both
`Location.id = appointment.location_id` and `Location.clinic_id = appointment.clinic_id`.
It orders by `slot_date ASC, slot_time ASC, id ASC`; it does not use OFFSET. The broad
persisted-date prefilter is the inclusive union from the minimum Location-local date at the fixed
UTC instant through one calendar day after the maximum Location-local date, evaluated across PHP's
supported IANA identifiers. This cannot omit a Location's local today or tomorrow; it is only a
prefilter. Final eligibility parses the candidate's own Location timezone and compares the stored
date to that Location's local today/tomorrow. Missing/mismatched Location rows and empty or invalid
timezones fail closed without stopping unrelated rows.

Each execution scans at most **100 candidates** in pages of **40** and makes at most **60
notification attempts**. A cursor advances after every scanned candidate, including final-calendar
rejections. When further candidates may remain, one—and only one—same-type continuation is enqueued
with a strictly greater cursor and the unchanged fixed reference. Existing notification/SMS dedupe
continues to provide at-least-once-safe effects; this design does not claim exactly-once execution.
No migration, generic `JobQueue` change, scheduler redesign, or quiet-hours policy change is part of
this slice.

**به‌روزرسانی بعدی (ادغام `35acced` — PR #47، فاز ۲):** ساعاتِ سکوتِ ارسالِ SMS برای
`appt.reminder` و `fu.reminder` اکنون با **تایم‌زونِ صریح و ماندگارِ Location** ردیفِ عملیاتی
(`cpms_locations.timezone`) و همان **لحظهٔ مرجعِ کنترل‌شدهٔ UTC** که پردازشِ یادآوریِ همان
مسیر استفاده می‌کند ارزیابی می‌شود؛ تایم‌زونِ کلینیک در این مسیرهای قابل‌دسترس جایگزینِ
Location نمی‌شود و نبود/خرابیِ تایم‌زونِ Location **پیش از ارزیابیِ ساعاتِ سکوت** به‌صورتِ
fail-closed رد می‌شود. فقط منبعِ منطقه‌زمانی/لحظهٔ مرجع تغییر کرد — آغاز/پایانِ پیکربندی‌شده،
بازهٔ شب‌گذر، فعال‌سازی و ایجادِ اعلانِ داخلی بدون تغییر ماندند. این تکمیلِ فنیِ فاز ۲ است و
ادعای تکمیلِ زمان‌بندیِ فازهای بعدی (مثل فاز ۶) نیست.
