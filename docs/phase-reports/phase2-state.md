# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | **Slice 1B.1 (E) — اصلاحات پیش‌از‌ادغامِ یافته‌های بازبینیِ امنیتی/معماری روی Draft PR #27 (2026-09-12):** حذفِ `bindPayloadClinicScope` + scope-neutral شدنِ `ExportService` + گارْدِ محدودِ عضویت (`MembershipRepository::find_active`) با کدِ `CLINIC_EXPORT_CLINIC_NOT_AUTHORIZED` + fail-closed پیش‌فرضِ `JobsDispatcher`. جزئیات در بخش «Slice 1B.1» پایین. Phase 2 همچنان IN PROGRESS. *قبلی:* **C10 (Performance review) — CLOSED؛ پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-12** (بستهٔ شواهد یکپارچه در `bdb135e9`، PR #25 MERGED؛ فقط دامنهٔ محدودشدهٔ بازبینی شواهد — بدون ادعای NFR/بار/مقیاس‌پذیری؛ Phase 2 همچنان IN PROGRESS). *سابقه (2026-09-11):* بستهٔ شواهد عملکرد کامل / واجد شرایط فنی برای بستن؛ بستن رسمی مالک در انتظار. بستهٔ شواهدِ **فقط‌مستندات** در سند اختصاصی [`c10-performance-evidence.md`](c10-performance-evidence.md) + §C10 همین سند ثبت شد — بدون هیچ پیاده‌سازی/بهینه‌سازی/migration. پیش از این: **بستن رسمی C9 با تصمیم صریح مالک در 2026-09-11 پس از ادغامِ موفق** (PR #23 MERGED در `7146d5b`؛ بستن رسمی در sync فقط‌مستنداتِ PR #24 ثبت شد). Checkpoint ادغام‌شدهٔ جاریِ main = `bd2634a` (PR #24 MERGED 2026-09-11T21:54:50Z؛ والدین `7146d5b` + `2adec4e`). SHA history (تاریخی): `3fc5a54` → `becc82f` → `a49b182` (#14 head) → `099b644` (merge) → `248ca10` (PR #17 merge) → `4871f84` (PR #19 merge) → `6b438238` (#20 head) → `a385d868` (merge #20) → `3589b15d` (#21 head) → `b19930fe` (merge #21) → `c5f98ab9` (#22 head) → `0fd5c27` (merge #22) → `c92737b` (#23 head) → `7146d5b` (merge #23) → `2adec4e` (#24 head) → `bd2634a` (merge #24) |
| **وضعیت Phase 2** | IN PROGRESS — C1..C6 done؛ **C6 CLOSED**؛ **C7 CLOSED — پیاده‌سازی ادغام‌شده در main (PR #20 MERGED، merge `a385d868`) و پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11 ثبت شد**؛ **C8 CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location (بدون پیاده‌سازی — رجوع به §C8 پایین)**؛ **C9 CLOSED — پیاده‌سازیِ محدودشده در main ادغام شد (PR #23 MERGED، merge `7146d5b`) و پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11 ثبت شد** (فقط دامنهٔ محدودشدهٔ تعریف‌شده؛ رجوع به §C9 پایین). **C10 CLOSED — پذیرش/بستن رسمی با تصمیم صریح مالک 2026-09-12؛ فقط دامنهٔ محدودشدهٔ بازبینی شواهد، بدون ادعای NFR/بار/مقیاس‌پذیری** (بستهٔ شواهدِ فقط‌مستندات؛ بدون هیچ پیاده‌سازی — §C10 پایین) |
| **آخرین remote SHA سبزِ تأییدشده** | `bdb135e9` (`origin/main`) — هر ۴ گیت پس‌از‌ادغام success (CI `34678813474` · Real‑WP `34678813477` · Pilot/Staging `34678813488` · Closure `34678813479`)؛ PR #25 **MERGED** 2026-09-12T06:42:07Z (فقط مستندات — بستهٔ شواهد C10؛ والدین `bd2634a` + `e2d9ce74`). قبلی: `bd2634a` (PR #24 — CI `34651627032` · Real‑WP `34651627286` · Pilot/Staging `34651627290` · Closure `34651627211`) |
| **C6 بسته شده** | ۱۴۰۱/۰۶/۱۹ — با تصمیم مالک/معمار |
| **C7 (پیاده‌سازی)** | ادغام‌شده در `a385d868` از طریق PR #20 (S1..S6)؛ **پذیرش/بستن رسمی مالک: تصمیم صریح 2026-09-11 — CLOSED** (فقط دامنهٔ تعریف‌شده/تکمیل‌شدهٔ C7؛ نه ادعای کامل‌بودن مطلق ایزولاسیون، نه تأیید آمادگی تجاری، و بدون تصویب اقلام به‌تعویق‌افتاده/migration/فازهای بعدی) |
| **C9 (i18n — محدودشده)** | **CLOSED — پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11، پس از ادغامِ موفقِ PR #23 در `7146d5b` و سبزیِ هر ۴ گیت پس‌از‌ادغام روی همان SHA.** فقط دو یافتهٔ A/Low که خودِ C7 معرفی کرده بود، در مرز REST translation-ready شدند؛ بدون تغییر Domain/Application، بدون migration، بدون ابزار گارْد جدید، بدون پاکسازی انبوه — §C9 پایین. این بسته‌شدن **نه** ادعای رفعِ همهٔ بدهیِ i18n است، **نه** پشتیبانیِ کاملِ انگلیسی، **نه** آمادگیِ globalization، و **نه** بسته‌شدنِ Phase 2 |
| **Schema** | `2026_09_09_0020` — بدون تغییر؛ فایل/تصویب `0021` وجود ندارد (بدون تغییر schema در C7؛ بسته‌شدن مستنداتی C8 و پیاده‌سازی/بسته‌شدن محدودشدهٔ C9 هم هیچ migrationای را مجاز نکردند) |

> این فایل state جاری است، نه گزارش. عمداً به SHA کامیتِ خودِ این سند ارجاع
> نمی‌دهد — مبنا = checkpoint ادغام‌شدهٔ `bd2634a` (`origin/main`؛ PR #24 MERGED — فقط مستندات: بستن رسمی C9 + اصلاح واژگانی شواهد عملکرد).
> (جدول‌های زیر که به `099b644`/`248ca10`/`b19930fe` ارجاع می‌دهند سابقهٔ تاریخی‌اند.) نسب تاریخیِ
> تأییدشده: headهای PR #10 (`79cce4b`) و PR #11 (`9e006b0`) جد خط #14 بودند و
> تاریخچه‌شان در `099b644` ادغام شده است. PHP در sandbox ممیزی روی PATH نبود؛
> شواهد اجرایی = GitHub Actions.

## Phase 2 Tenant Context Remediation — Slice 1B: RED → GREEN (2026-09-12)

**وضعیت: پیاده‌سازیِ Slice 1B روی Draft PR #27 — DO NOT MERGE. Phase 2 همچنان
IN PROGRESS است و هیچ‌کدام از گیت‌های پذیرش بسته نشده‌اند.**

سند کانونیِ طراحی: [`../architecture/phase2-tenant-context-remediation-design.md`](../architecture/phase2-tenant-context-remediation-design.md)
(§A-3 · §5-D-1..D-3 · §9 RT-1..RT-14). نقشهٔ علتِ ریشه‌ایِ پیش‌از‌کدنویسی:
[`phase2-slice1b-rootcause-map.md`](phase2-slice1b-rootcause-map.md).

### چک‌پوینت‌ها (زنجیرهٔ RED → GREEN)

| | SHA | CI run | نتیجه |
|---|---|---|---|
| **RED (شاهد اجراییِ نقص)** | `43cf2ef6ee494e206c25130033a48613e461893e` | `34697309922` (attempt 1, `pull_request`) | ❌ failure — `Tests: 682, Assertions: 4705, Failures: 5.` |
| **GREEN candidate اول** | `28187345f1e77d95719c7e0f7ab91be32af24a6c` | `34699927150` (attempt 1) | ❌ failure — `Tests: 682, Assertions: 4736, Errors: 1, Failures: 1` + PHPStan |
| **GREEN checkpoint (کد)** | `7a54d7743a58ca2f9cb12ed5e22504e56141bb85` | `34700456222` (attempt 1, `pull_request`) | ✅ success — هر ۸ job |

روی `7a54d77` هر پنج workflow سبز: CI `34700456222` · Real‑WP Acceptance
`34700456223` (هر دو `pull_request`) · Closure Gate `34700452404` · Real‑WP
Acceptance `34700452407` · Pilot/Staging `34700452411` (سه مورد آخر `push`).

> ⚠ **مرزِ صداقتِ شواهد:** چون step ‏«Post failures to PR» در `ci.yml` فقط در
> حالتِ شکست کامنت می‌گذارد و لاگ job از این sandbox قابل بازیابی نیست، سطرِ
> خلاصهٔ PHPUnit برای run سبزِ `34700456222` مستقیماً خوانده **نشده**. آنچه
> اثبات‌شده است: job ‏«Integration (WP 6.7 + MySQL 8)» = `success` (یعنی PHPUnit
> با exit 0 روی کلِ `tests/Integration`) و **هیچ** annotation شکستی روی آن job
> ثبت نشده. شمارِ ۶۸۲ تست از دو run قبلیِ همان suite (بدون افزودن/حذف متدِ
> تست بین آن‌ها) استنتاج می‌شود، نه از خواندنِ مستقیمِ سطرِ خلاصهٔ run سبز.

### آنچه Slice 1B واقعاً رفع کرد

۱. **RT-3 (امنیتی، بالاترین اولویت):** پیامِ متعلق به Clinic B اکنون provider،
   `sender`، `sms.advanced` و **credentialِ sealedِ `sms.auth`** را از
   پیکربندیِ **همان** Clinic حل می‌کند. منبعِ Clinic = `clinic_id` خودِ ردیفِ
   پیام (منبعِ durable)، نه `Settings` میخ‌شدهٔ سطحِ process.
۲. **RT-6:** `SmsService` scope-neutral شد؛ `App::settings()` در هر فراخوانی از
   scope مشتق می‌شود و کشِ واقعی در `SettingsFactory` با کلیدِ `clinicId` است؛
   `sms.generic` به‌جای freeze شدن در لحظهٔ ساختِ registry، lazy و per-Clinic
   حل می‌شود. توالیِ کاملِ `A → B → A` در تست assert می‌شود.
۳. **RT-4:** `App::dispatcher()` بدونِ کاربرِ WP و بدونِ هیچ Clinic scope
   قابلِ ساخت است (ساختِ handlerها به داخلِ callableهای ثبت‌شده منتقل شد).
۴. **RT-14:** شکستِ scopeِ **یک** job دیگر کلِ tick را نمی‌اندازد؛ همان job
   `failed` می‌شود و jobهای بی‌ارتباطِ همان tick اجرا می‌شوند. `tick()` در
   `JobsDispatcher` **دست‌نخورده** ماند (کنترلِ مثبت نشان داده بود هرگز نقص از
   آنجا نبود).
۵. **RT-12:** قراردادِ تولیدیِ `JobScopeRegistry` با طبقه‌بندیِ صریحِ T/S/W برای
   هر ۱۵ نوعِ ثبت‌شده (۲ T / ۷ S / ۶ W، عیناً از §A-3)؛ نوعِ ناشناخته
   fail-closed رد می‌شود؛ `NULL` هرگز به‌طور خودکار «system» نیست؛ و گارْدِ
   drift بینِ registry و ثبتِ handlerهای زمانِ اجرا در تست سنجیده می‌شود.

### آنچه **پیاده‌سازی نشده** (صریح)

- ⛔ **کلِ Phase 2 remediation** — فقط RT-3/4/6/12/14. ‏RT-1/2/5/7/8/9/10/11/13
  دست‌نخورده‌اند.
- ⛔ **timezone (RT-9 / C-9 / C-10)** — `slots.generate`، `visits.no_show`،
  `appt.reminder` و `fu.reminder` همچنان بر پایهٔ timezoneِ Clinicِ bootstrap
  کار می‌کنند. **رفع نشده.**
- ⛔ **پیکربندیِ سطحِ نصب (§۸-۱ / RT-13)** — کلیدهای `retention.oplog_days`،
  `hw.version_*`، `notif.archive_days`، `backup.*`، `license.server_url` و
  `queue.no_show_grace_minutes` همچنان از سطرِ `cpms_settings` یک Clinic خوانده
  می‌شوند. در نصبِ چندکلینیکیِ بدونِ scope، jobهای S/W مربوط **per-job
  fail-closed** می‌شوند (سازگار با RT-14) ولی منشأ پیکربندیِ نصب‌گسترده
  **مهاجرت/پیاده‌سازی نشده است**.
- ⛔ **`operational_logs` (E-7)** — ایندکس/retention بدون تغییر؛ `DELETE`
  همچنان بی‌`LIMIT`.
- ⛔ **انتسابِ tenant در لاگِ عملیاتی (RT-11)** و **provenance/backfill
  ‏(RT-10 / §۸-۵ NOT MEASURED)**.
- ⛔ **هیچ schema/migration‌ای** — آخرین migration همچنان
  `2026_09_09_0020_idempotency_clinic_scope.php` است و **`0021` وجود ندارد**.
  هیچ ستونِ `clinic_id`‌ای به `cpms_jobs` اضافه نشد، پس قاعدهٔ سازگاریِ
  «T ⇒ غیرتهی / S,W ⇒ NULL» هنوز **گارْدِ داده‌ایِ اجرایی** ندارد و فقط در سطحِ
  قراردادِ registry و تست ثبت است.
- ⛔ **Phase 2 بسته نشده** و **End Gate شروع/پاس نشده است.**
- ⛔ **PR #27 ادغام نشده و Ready for Review نشده است.**

## Phase 2 — Slice 1B.1 (E): اصلاحات پیش‌از‌ادغامِ یافته‌های بازبینیِ امنیتی/معماری PR #27 (2026-09-12)

**وضعیت:** Draft PR #27 — **DO NOT MERGE**. این برش فقط دو یافتهٔ **الزامیِ**
بازبینی (H-1 و M-3) و یک **گارْد محدودِ مجوزِ tenant برای Export** را اصلاح
می‌کند؛ فاز ۳ (`AuthorizationService`/نقش‌ها) پیاده‌سازی نشده و Phase 2 همچنان
IN PROGRESS است.

### یافتهٔ الزامی ۱ (H-1) — حذفِ bindکردنِ scopeِ موردِ اعتماد از payload خام

- `App::bindPayloadClinicScope()` **حذف شد**؛ handlerِ `report.export` دیگر هیچ
  scope‌ای از `payload_json.clinic_id` برقرار نمی‌کند. payload فقط «انتخابِ
  عملیات» است، نه مجوز و نه contextِ موردِ اعتماد.
- `ExportService` **scope-neutral** شد: هیچ‌یک از وابستگی‌های Clinic‌دار
  (`Settings`، `ReportService`، `NotificationService`، `LocalFileStorage`) در
  سازنده تزریق نمی‌شود؛ همه از بستهٔ جدید `ExportClinicDeps` و فقط برای
  Clinic‌ای که مرزِ قابلِ اعتماد تعیین کرده است، به‌صورت **lazy** حل می‌شوند.
- ترتیبِ مسیر Job در `generate()`: اعتبارِ عددیِ `clinic_id` (فقط انتخاب عملیات)
  → `requireClinicMembership()` (مجوزِ tenant) → حلِ `ExportClinicDeps` →
  bindِ scope → اجرا. یعنی context **فقط پس از** مرزِ قابلِ اعتماد برقرار می‌شود
  و ساختِ سرویس هیچ خواندنِ Settings/فایل/پیکربندیِ tenant‌دار ندارد.
- `App::exportService()` دیگر `App::settings()` را resolve نمی‌کند (تأیید با
  تحلیلِ سورس).

### گارْدِ محدودِ مجوزِ Export (بستنِ مسیرِ confused-deputy — نه فاز ۳)

- `requireReportAccess()`/`requireCap()` فقط capability‌های **سراسریِ**
  WordPress را می‌سنجند و به‌تنهایی نمی‌توانند «حقِ export» را از «حقِ export
  در این Clinic» جدا کنند.
- گارْدِ جدید `requireClinicMembership()` از primitive موجودِ فاز ۲ استفاده
  می‌کند: `MembershipRepository::find_active()` روی `cpms_clinic_memberships` با
  `status = 'active'` — **نه** `AuthorizationService` فاز ۳ و **نه** نقش/سیاستِ
  اختراعی. عددی‌بودنِ `clinic_id` مجوز نیست.
- نبودِ عضویتِ فعال ⇒ **fail-closed** با کدِ پایدارِ
  `CLINIC_EXPORT_CLINIC_NOT_AUTHORIZED` (HTTP 403). بدون fallback، بدون حدس،
  بدون صدور خروجی.
- عمداً روی `request()` اعمال نشد: آنجا Clinic از `TrustedClinicEstablisher`
  (همان عضویتِ تأییدشدهٔ `find_active`) می‌آید و افزودنِ دوبارهٔ آن می‌توانست
  نصبِ تک‌کلینیکی را که scope‌اش از `SystemClinicResolver` می‌آید بشکند.

### یافتهٔ الزامی ۲ (M-3) — fail-closed بودنِ registry به‌صورتِ پیش‌فرض

- `JobsDispatcher` اکنون `$allowUnclassifiedJobTypes = false` به‌صورتِ
  **پیش‌فرض** دارد؛ production با ساختِ معمولیِ دوآرگومانی enforcement دارد و
  نمی‌تواند «تصادفاً» dispatcherِ سهل‌گیر بسازد.
- تنها مصرف‌کنندهٔ مجازِ حالتِ permissive، زیرساختِ عمومیِ تست است
  (`JobQueueTest`) که با آرگومانِ سومِ `true` **صریح** opt-out می‌کند.
- نوعِ ناشناختهٔ production همچنان `JOB_SCOPE_UNCLASSIFIED` (stable) می‌گیرد؛
  RT-12 دست‌نخورده و قوی است.

### شواهدِ اجرایی (تست‌ها) — و مرزِ صداقتِ شواهد

- `tests/Integration/Phase2JobScopeRedFoundationTest.php` از ۸ به **۱۴ تست** رسید.
  هر ۸ تستِ Slice 1A/1B — از جمله **RT-3 و RT-6** — بدون تغییر ماندند.
- ۶ تستِ جدید: (۱) payload دست‌کاری‌شده با capability سراسریِ کامل
  (`cpms_report_read` + `cpms_export` + `cpms_patient_read`) و عضویتِ فعالِ
  Clinic A **نمی‌تواند** از مرز Clinic به B عبور کند؛ (۲) مسیرِ مشروعِ
  هم‌Clinic همچنان کار می‌کند؛ (۳) گرافِ `ExportService` بدون هیچ Clinic/Scope
  قابلِ ساخت است و scope‌ای برقرار نمی‌کند؛ (۴) ساختِ پیش‌فرضِ dispatcher نوعِ
  بدون‌طبقه را رد می‌کند؛ (۵) حالتِ permissive فقط با opt-in صریح؛ (۶) dispatcher
  تولیدی enforcement را حفظ می‌کند.
- **مرزِ صداقت:** ۶ تستِ جدید **همزمان با کدِ اصلاحی** (همان commit) اضافه
  شدند؛ برای آن‌ها **run جداگانهٔ RED وجود ندارد** (زنجیرهٔ RED → GREEN مستند
  فقط برای ۵ تستِ اصلیِ Slice 1A/1B است). GREEN‌بودنِ تستِ گارْد یعنی رد شدن در
  شرایطِ «همهٔ capability‌های سراسری + عضویتِ A + نبودِ عضویتِ B» — نه یک run
  RED ساختگی.

### M-2 — هشت job که همچنان توسط Settingsِ Clinic-scoped مسدودند (مستند، نه حل)

پس از lazy شدنِ dispatcher (Slice 1B)، هشت نوع job هنوز در **لحظهٔ اجرای
handler** وابستگی‌های Clinic‌دار را می‌سازند و در نصبِ چندکلینیکیِ بدونِ
کاربر/scope با `CLINIC_SCOPE_REQUIRED` شکست می‌خورند:

| نوع | کلاس | محلِ خواندن Settings/Scope در ساختِ handler |
|---|---|---|
| `cleanup.oplog` | S | `OpLogCleanupHandler($db, self::settings())` |
| `backup.run` | S | `self::backupService()` + `self::settings()` |
| `handwriting.gc` | S | `self::handwritingService()` → `self::settings()` |
| `slots.generate` | W | `self::settings()` |
| `appt.reminder` | W | `self::settings()` + `self::notificationService()` |
| `fu.reminder` | W | `self::settings()` + `self::notificationService()` |
| `notif.dispatch` | W | `self::notificationService()` → `self::settings()` |
| `visits.no_show` | W | `self::visitService()` → `self::settings()` |

- **علت شکست:** این هشت job پیکربندی/سرویسِ Clinic‌دار را در لحظهٔ اجرا
  (داخل callable ثبت‌شده) می‌سازند؛ در نصبِ چندکلینیکیِ بدونِ scope،
  `App::scope()` fail-closed خطا می‌دهد و handler قبل از انجامِ کارش می‌افتد.
- **پیامدِ retry/churn:** `JobQueue::fail()` وقتی `attempts < max_attempts`
  است job را دوباره `queued` می‌کند (backoff ۱/۵/۱۵/۶۰/۳۰۰ ثانیه) و پس از
  `max_attempts = 3` terminal `failed` می‌شود؛ اما `scheduleRecurringJobs()` در
  هر tick نوعِ بدونِ ردیفِ `queued` را **دوباره enqueue** می‌کند ⇒ چرخهٔ
  تکراریِ claim → شکست → بازصف → شکست، بدون انجامِ کارِ واقعی.
- **`cleanup.oplog` یکی از همین jobهای مسدود است.**
- **این مورد توسط Slice 1B.1 رفع نشده** و یک بلوکرِ باقی‌ماندهٔ فاز ۲ است
  (نیازمند معماریِ Settings سطحِ نصب / §۸-۱؛ خارج از دامنهٔ این برش — نه جابه‌جایی
  کلید، نه migration/schema).
- ⚠ طبقه‌بندیِ T/S/W به‌خودیِ‌خود اثباتِ صحتِ عملیاتی نیست؛ فقط قراردادِ scope است.

### M-4 — retry قطعیِ scope همچنان generic (بازطراحی نشده)

- شکست‌های **قطعیِ** scope/پیکربندی (مثل `CLINIC_SCOPE_REQUIRED` بالا) همچنان
  از رفتارِ retry عمومیِ `JobQueue::fail()` استفاده می‌کنند (بازصف با backoff
  به‌جای terminal فوری/سیاستِ خاصِ «شکست قطعی»).
- در این برش **queue بازطراحی نشده** و هیچ معماریِ retry جدیدی افزوده نشد؛
  این یک آیتمِ باقی‌ماندهٔ follow-up است.

### چک‌پوینت اجرایی (GREEN) — head `182068c` (کدِ اصلاحی `b9d4e36` + همین مستندات)

| گیت | Run | رویداد | نتیجه |
|---|---|---|---|
| CI (Unit 8.1/8.2/8.3/8.4 + PHPStan + WPCS + Tenant Tripwire + Integration) | `34709238401` | pull_request | ✅ success — هر ۸ job |
| Real WordPress Acceptance (prefix `wp_` + `clinic_`) | `34709238433` | pull_request | ✅ success — هر دو prefix |
| Closure Gate (GO‑LIVE evidence) | `34709236199` | push | ✅ success |
| Real WordPress Acceptance | `34709236204` | push | ✅ success |
| Pilot/Staging Readiness Gate | `34709236219` | push | ✅ success |

> یادداشتِ صداقت: run قبلیِ Real‑WP روی `b9d4e36` (run `34706037675`) در job
> «Browser acceptance — all 5 roles» با prefix `clinic_` **یک‌بار** شکست خورد،
> در حالی که همین SHA در run push (`34706035279`) سبز بود و در run تازهٔ
> `34709238433` همان job سبز شد ⇒ طبقه‌بندی C (نوسانِ محیطی/مرورگر)، نه نقصِ کد.
> (یافتهٔ C9 قبلاً ثبت کرده که لاگِ کاملِ jobها از این sandbox قابل بازیابی نیست؛
> مبنای طبقه‌بندی = سبزیِ همان SHA در run هم‌زمانِ push + سبزیِ run تازه.)

### جمع‌بندی برش 1B.1

- ✅ بدون schema/migration — آخرین migration همچنان `2026_09_09_0020`؛ `0021`
  وجود ندارد. بدون تغییر workflow. بدون force-push/بازنویسی تاریخچه. بدون
  تضعیف/اسکیپِ تست.
- ⛔ فاز ۳ (AuthorizationService/نقش‌ها) پیاده‌سازی نشده — فقط گارْدِ محدود با
  primitive موجود.
- ⛔ Phase 2 IN PROGRESS · End Gate NOT STARTED/PASSED · PR #27 DRAFT و ادغام‌نشده.

## گیت‌های سبز — پس‌از‌ادغام (روی `bd2634a` = `origin/main` جاری)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34651627032 | ✅ success |
| Real WordPress Acceptance | 34651627286 | ✅ success |
| Pilot/Staging Readiness | 34651627290 | ✅ success |
| Closure Gate | 34651627211 | ✅ success |

(verify زندهٔ 2026-09-11 روی همان SHA: هر ۴ workflow — همه success؛ هیچ check معلق یا ناموفق نیست. job «Staging Gate» شامل استپ benchmark ‏`ab` نیز بدون فعال‌شدن شرط شکست non‑2xx خود کامل شد — متریک‌های دقیق جاری از Arena قابل بازیابی نبودند؛ هیچ عدد جاری، از جمله error rate، ادعا نمی‌شود.)

**ادغام PR #24:** merge = `bd2634a` در 2026-09-11T21:54:50Z؛ والدین دقیقاً
`7146d5b` (mainِ پیش‌از‌ادغام = merge PR #23) + `2adec4e` (head تصویب‌شدهٔ PR #24) —
فقط مستندات (بستن رسمی C9 + اصلاح واژگانی شواهد عملکرد).

## گیت‌های سبز — پس‌از‌ادغام روی `7146d5b` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34639699703 | ✅ success |
| Real WordPress Acceptance | 34639699751 | ✅ success |
| Pilot/Staging Readiness | 34639699635 | ✅ success |
| Closure Gate | 34639699639 | ✅ success |

(verify زندهٔ 2026-09-11 روی همان SHA: مجموعاً ۱۹ check run روی `7146d5b` — همه success؛ هیچ check معلق یا ناموفق نیست.)

**ادغام PR #23:** merge = `7146d5b` در 2026-09-11T19:35:54Z؛ والدین دقیقاً
`0fd5c27` (mainِ پیش‌از‌ادغام = merge PR #22) + `c92737b` (head تصویب‌شدهٔ PR #23) —
راستی‌آزمایی‌شده با `git cat-file -p` و با `parents` از GitHub API. ادغام توسط
`app/arena-ai-coding-agent` (bot). زنجیرهٔ کامیت‌های headِ PR #23: `81d4e8d` (RED
فقط‑تست) → `fec48c87` (ترمیم) → `954bb906` (docs) → `c92737b` (باریک‌کردنِ
discriminator مالی = head نهایی).

**گیت‌های headِ پیش‌از‌ادغامِ PR #23 (`c92737b`) — همه success:** CI `34637483738` ·
Real‑WP `34637483754` + `34637478336` · Pilot `34637478344` · Closure `34637478675`.

## گیت‌های سبز — پس‌از‌ادغام روی `0fd5c27` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34619119838 | ✅ success |
| Real WordPress Acceptance | 34619119805 | ✅ success |
| Pilot/Staging Readiness | 34619119828 | ✅ success |
| Closure Gate | 34619119855 | ✅ success |

(verify زندهٔ 2026-09-11: مجموعاً ۱۹ check run روی `0fd5c27` — همه success؛ هیچ check معلق یا ناموفق نیست.)

## گیت‌های سبز — پس‌از‌ادغام روی `b19930fe` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34610213715 | ✅ success |
| Real WordPress Acceptance | 34610213751 | ✅ success |
| Pilot/Staging Readiness | 34610213717 | ✅ success |
| Closure Gate | 34610213674 | ✅ success |

(verify زندهٔ 2026-09-11: مجموعاً 19 check run روی `b19930fe` — همه success؛ هیچ check معلق یا ناموفق نیست.)

**Historical — گیت‌های پس‌از‌ادغام روی `a385d868` (چک‌پوینت قبلی — همه success):**

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34602712029 | ✅ success |
| Real WordPress Acceptance | 34602711983 | ✅ success |
| Pilot/Staging Readiness | 34602711956 | ✅ success |
| Closure Gate | 34602711962 | ✅ success |

**head نهایی پیش‌از‌ادغامِ PR #20 (`6b438238`) — هر پنج workflow کانونی سبز:**
CI `34598981613` · Real-WP `34598981627` + `34598978084` · Pilot `34598978102` · Closure `34598978147`.
والدین merge `a385d868`: `4871f84` (mainِ پیش‌از‌ادغام = merge PR #19) + `6b438238` (head).
ادغام توسط `app/arena-ai-coding-agent` (bot) — در لحظهٔ ادغام **تأیید مالک در مخزن مستند نبود** (واقعیت تاریخی، حفظ‌شده)؛ **پذیرش رسمی C7 سپساً با تصمیم صریح مالک در 2026-09-11 ارائه و در همین سند + `project-current-state.md` ثبت شد.**

## گیت‌های سبز — پس‌از‌ادغام روی `248ca10` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34460364222 | ✅ success |
| Real WordPress Acceptance | 34460364238 | ✅ success |
| Pilot/Staging Readiness | 34460364243 | ✅ success |
| Closure Gate | 34460364219 | ✅ success |

والدین merge: `8087b42` (mainِ پیش‌از‌ادغام = merge PR #9) + `a49b182` (head پیش‌از‌ادغامِ PR #14).

## گیت‌های سبز — batch استحکام tenant (تاریخی، پیش‌از‌ادغام، روی `3fc5a54`)

برچسب داخلی تسک در گزارش‌ها «Phase 9 §5» بود — واژگان تاریخیِ taxonomy وظایفِ داخلی؛
فاز ۹ نقشهٔ راه مالک = Patient Portal و همچنان **NOT STARTED** است.

| گیت | Run | نتیجه |
|---|---|---|
| CI (Unit×4 + PHPStan + WPCS changed‑lines + **Integration** — 602 test، 0E/0F) | 34406996627 | ✅ success (۷ job) |
| Real WordPress Acceptance (ZIP → clean WP → browser؛ prefix `wp_` و `clinic_`) | 34406991487 | ✅ success |
| Pilot/Staging Readiness (Artifact + Responsive smoke + Upgrade + Staging) | 34406991520 | ✅ success |
| Closure Gate (GO‑LIVE evidence) | 34406991334 | ✅ success |

خلاصهٔ batch (جزئیات + شواهد RED تاریخی در `c6-census.md` و `project-current-state.md` §K):

- `f5ebefd` — **product fix:** پنج `clinic_id = 1` در `VisitService` (queue/Today/stats/feed
  watermark) حذف؛ trusted context با fail‑closed؛ دامنهٔ پزشک Clinic‑aware؛ `clinician_id`
  بین‌Clinic ⇒ 404. اثبات red→green: probeهای ۷–۱۶ صف روی `f5ebefd` سبز شدند.
- `f6703dc`/`b7b8399`/`bff690a` — **product fix:** ایزولاسیون Per‑Object فایل بالینی
  (`MedicalFileService` + `ClinicalService::record`)؛ Envelope رد == not‑found (عدم افشای
  وجود)؛ مسیرهای skip‑listed برای کارکنان: Scope → resolver سیستمی → **عضویت فعال یکتا**
  (هرگز «Clinic اول»). Class B Critical (خواندن هر فایل توسط هر staff با `cpms_file_read`)
  برطرف و probeها سبز.
- `c2bff76` — **test(harness):** ریشهٔ آلودگی بین‌کلاسی (نخستین REST‑touch = pin یک‌بارمصرفِ
  سرویس‌ها زیر Scope فیکسچور ⇒ خرابی Export/Reports/Sms/Otp/VisitFlow) با warm خنثی +
  پاک‌سازی دیسک + رفع collision email در `makeUser` بسته شد؛ هیچ کلاس/تست/product دیگری
  تغییر نکرد.

## گیت‌های سبز — checkpoint ترمیم Trusted REST (روی `4289d89`)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + WPCS changed‑lines + **Integration**) | 34388298772 | ✅ success (۷ job) |
| Real WordPress Acceptance (push — prefix `wp_` و `clinic_`) | 34388294483 | ✅ success (2 job، steps_failed=0) |
| Pilot/Staging Readiness | 34388294486 | ✅ success |
| Closure Gate (GO‑LIVE evidence) | 34388294616 | ✅ success (۵ job؛ کامنت‌های evidence روی PR #13 با همین run id) |

### checkpoint بازبینی batch ترمیم (C6 CONTINUATION — review/consolidation)

- **تأیید تاریخچه (read‑only):** `origin/main` = `8087b42` ⊂ `f88fcdc` (head PR #12) ⊂
  `4289d89` ⊂ `4606b15` — زنجیرهٔ خطی `f88fcdc → adecd21 → a23b509 → b7a3a6b → da72e1c →
  4289d89 → 9e5cbcd → 4606b15`؛ PR #13 **دقیقاً** delta ترمیم (۷ کامیت، `+456/−72`،
  `mergeable_state=clean`)؛ PR #12 بی‌تغییر (`f88fcdc`، draft، `blocked`). هیچ FF/merge/close
  و هیچ شاخهٔ راه دوری جابه‌جا نشد (جز کامیت‌های جدید همین batch روی `arena/01a086b4-doctor`).
- **گیت‌های سبز روی `4606b15`:** CI `34390466560` · Real‑WP `34390462202` (wp_/clinic_) ·
  Pilot `34390462154` · Closure `34390462224` — هر چهار run `completed/success`، steps_failed=0،
  rollup PR #13 = 18× SUCCESS. نکتهٔ پوشش: روی `4606b15` رویداد `pull_request` **فقط CI** را
  اجرا کرد و سه گیت دیگر از `push` روی همان SHA سبزند (اجرای PR‑only برای Real‑WP وجود نداشت؛
  چون SHA یکسان بود rerun لازم نبود).
- **چرخهٔ حیات Scope (مرور + تست اول):** پنج invariant قابل‌اجرای تازه در
  `RestTrustedClinicContextTest` — مسیر WP_Error، drain شدنِ جفت‌های pending، حفظ scope
  مشروعِ job/system، A→B/B→A بدون نشت، و تودرتو (`rest_do_request`) — سبز روی `0205089`
  (CI `34393642656`، ۷ job سبز؛ Real‑WP `34393637166` و Closure `34393637150` نیز سبز).
- **تصحیح باریکِ واژگان سند (نظریه، نه product):** «restore تضمینی» به معنای پوششِ
  استثنای callback توسط netِ `shutdown` **نیست**: `respond_to_request()` استثنای
  مهار‌نشدۀ callback را به `WP_Error` تبدیل می‌کند و `rest_request_after_callbacks` پس از
  آن اجرا می‌شود؛ net برای throw/fatal/`exit()` در سطح filter لازم است.
- **پروب C6‑F (فقط تست، روی `5b3768e`) — Class A، عمداً سبز نشده:** `Integration` قرمز با
  `Tests: 568, Assertions: 2951, Failures: 2` (annotation همان run):
  `testStaffCannotFinalizePrescriptionOfAnotherClinic` و `testSmsLogsAreScopedToTheBoundClinic`.
  طبقه‌بندی: **Class A (product)** — (۱) `ClinicalService::finalizePrescription` با
  `PrescriptionRepository::findForUpdate` (`WHERE id` بدون `clinic_id`)؛ (۲) `SmsService::logs`
  بدون فیلتر `clinic_id` روی `cpms_sms_messages`. هر دو test guard ساختاری (Class D) دارند و
  از پاس شدنشان مطمئنیم (شکست روی ادعای جداسازی، نه پیش‌شرط)؛ سایر گیت‌ها روی همین SHA
  سبزند (Real‑WP `completed/success`، Closure `completed/success`). این دو تست به‌عنوان
  «مشخصهٔ معلق» C6‑F روی شاخه می‌مانند و **رفع Product در این batch انجام نشد** (رفع =
  تصمیم Phase 3/C6‑F با مالک).

- branch = `arena/01a086b4-doctor`؛ base تشخیصی = `arena/01a086ca-doctor` (PR #13 — draft،
  برای اجرای گیت‌ها روی continuationِ خطیِ `f88fcdc`؛ **هیچ شاخهٔ راه دوری جابه‌جا نشد**).
- تعداد تست Integration در run سبز از API قابل استخراج نبود (لاگ خام/artifact در sandbox
  مسدود است؛ گام «Run integration tests» = success و no failure‑comment) → **NOT VERIFIED
  به‌صورت عددی**، سبز بودن از check‑run تأیید شده.
- **NOT TESTED locally:** PHP در این sandbox نصب نبود (بدون `php -l`/phpunit محلی)؛ همهٔ
  شواهد = GitHub Actions روی SHA.
- هم‌ترازی docs روی SHA بعدی نیز سبز است: CI `34389431689` · Real‑WP `34389425080` ·
  Pilot `34389425027` · Closure `34389425099` (۱ check‑run = success). این ارجاع برای
  «وضعیت گیت‌ها» است، نه مبنای وضعیت پیاده‌سازی؛ مبنای سندها همان `4289d89` است.
- **بازبین batch ترمیم روی tip `4606b15` (sibling review — فقط docs):** CI `34390466560`
  (۷ job سبز: WPCS · PHPStan · Unit×4 · Integration) · Real‑WP `34390462202` (prefix
  `wp_` و `clinic_`) · Pilot `34390462154` · Closure `34390462224` — هر چهار run
  `completed/success` با `head_sha` درست. PR #12 بی‌تغییر (head `f88fcdc`، draft،
  `mergeable_state=blocked`)؛ PR #13 = دقیقاً delta ترمیم (`+456/−72`، ۷ کامیت،
  `mergeable_state=clean`) — **هیچ merge/FF/close انجام نشد**. نکتهٔ پوشش: روی
  `4606b15` رویداد `pull_request` فقط CI را اجرا کرد؛ سه گیت دیگر از رویداد `push`
  روی همان SHA سبز شده‌اند.
- **معنای چرخهٔ حیات WP (تصحیح باریکِ واژگان، بدون تغییر product):** `respond_to_request()`
  استثنای مهار‌نشدۀ callback را به `WP_Error` تبدیل می‌کند و `rest_request_after_callbacks`
  **پس از** آن اجرا می‌شود ⇒ restore در مسیر خطای callback هم انجام می‌شود؛ netِ
  `shutdown` برای throw/fatal در سطح filter (مجاور بلوک try/catch) یا `exit()` لازم است.
  شاهد قابل‌اجرا: `testErrorResponsePathStillRestoresScope` (restore در مسیر WP_Error)،
  `testPendingPairDrainsAndNextRequestStillRestores` (safety‑net)،
  `testPreExistingExplicitScopeSurvivesRequestAndRestoresAfterwards` (scope مشروع
  job/system پاک نمی‌شود)، `testSequentialClinicRequestsDoNotLeakScope` (A→B/B→A)،
  `testNestedRestDispatchRestoresOuterScope` (تودرتو) — سبز روی `0205089` (CI `34393642656`).

### C6‑F کلاس A — ترمیم دو نقص Cross‑Tenant (شاهد قابل‌اجرا، سبز روی `2d13f2d`)

- **مبنا/پایان:** شروع `41321e2` → `7c4b2bd` (نسخه) → `2d13f2d` (SMS) — ادامهٔ خطی،
  بدون force‑push/amend/rebase (دستور §۰ رعایت شد؛ تخلف batch قبل در همین سند ثبت است).
- **Finding A — High (IDOR بین‌تننتی، جهش):** `POST /clinic/v1/prescriptions/{id}/finalize`
  فقط نقش/Cap را می‌سنجید؛ `PrescriptionRepository::findForUpdate` = `WHERE id` ⇒ هر پزشکِ
  دارای `cpms_rx_create` می‌توانست نسخهٔ Clinic دیگر را **نهایی** کند.
  ریشه: نبودِ مالکیت Per‑Object در **Query Layer**. ترمیم:
  `findForUpdateForClinic(id, clinicId)` + `updateForClinic(clinicId, id, …)` (predicate دیتابیس،
  قابل ایندکس از PRIMARY) و در `ClinicalService::finalizePrescription/voidPrescription`
  Clinic از `App::scope()` (Trusted Scope؛ بدون هیچ id کلاینتی/بدون fallback به Clinic 1)؛
  خطا = `CLINIC_NOT_FOUND` 404 یکسان با «ناموجود» ⇒ **بدون افشای وجود** و **بدون جهش**
  (`affected < 1` ⇒ همان safe not‑found). تست‌ها: A/A مجاز، A/B رد+بدون‌جهش، B/B مجاز،
  بدنهٔ پاسخ یکسان با ناموجود، سوییچ پیاپی A→B→A، غیرعضو → همان 404.
- **Finding B — High (نشت PHI در خواندن):** `GET /clinic/v1/sms/logs` → `SmsController::logs`
  → `SmsService::logs` با `SELECT`/`COUNT` **بدون** `clinic_id` ⇒ هر دارندۀ `cpms_sms_config`
  لاگِ تمام tenantها (موبایل + متن) را می‌دید. ترمیم: `logs(int $clinicId, …)` با
  `WHERE clinic_id = %d` برای **هر دو** COUNT و SELECT (prepared SQL؛ بدون post‑filter در PHP).
  تست‌ها: context A و B، کاربر چند‌عضویت با سوییچ context، Organization دیگر، Clinic خالی،
  پایداری شمارش/ترتیب/صفحه‌بندی، و شهادِ SQL‑level روی لایۀ سرویس.
- **Dedupe (§۶ — تأییدشده، رفعِ schema‑free):** `dedupe_key = sha256(event|ctxType|ctxId|day)`
  و `uq_dedupe` یک UNIQUE **سراسری** ⇒ یک رویداد/Context/روزِ یکسان در دو Clinic، ارسال
  دومی را سرکوب می‌کرد (انکار سرویس، نه نشت داده). هویت Clinic به hash اضافه شد + lookup
  با `AND clinic_id = %d`؛ شهاد: `SmsFlowTest::testDedupeIsScopedPerClinic` + حفظ
  `testDedupePreventsDuplicateContextMessage`. **محدودیتِ از پیش موجود (گزارش، نه رفع):**
  مسیر resend با الحاق `-{id}` به کلید ۶۴تایی، در ستون `CHAR(64)` truncate می‌شود؛ رفعش
  به schema نیاز دارد ⇒ **OPEN، بدون Migration**.
- **ایندکس (مشاهدۀ کارایی، بدون تغییر schema):** `cpms_sms_messages` هیچ ایندکس `clinic_id`
  ندارد (اینکس‌ها: `uq_dedupe`, `ix_status_updated`, `ix_event_created`, `ix_context`).
  کوئری جدید **درست** است (tenant predicate + فیلتر روی PRIMARY‑order) ولی روی جدول بزرگ
  scan می‌کند. **هیچ Migration ساخته/تصویب نشد و 0021 لازم نبود** — در صورت درخواست
  بهین‌سازی: `KEY ix_sms_clinic (clinic_id, id)` با تأیید Owner.
- **گیت‌ها روی `2d13f2d`:** CI `34396622019` (۷ job سبز: WPCS · PHPStan · Unit×4 · **Integration**) ·
  Real‑WP `34396616394` (`wp_` و `clinic_`) · Pilot `34396616498` · Closure `34396616419`
  — همه `completed/success`. روی `7c4b2bd` (فقط Fix A) قرمزِ باقی‌مانده دقیقاً یک تستِ SMS بود
  (`Tests: 571, Assertions: 2994, Failures: 1`) و Real‑WP/Closure/Pilot سبز. **RED تاریخیِ
  `5b3768e`/`41321e2` (`Tests: 568, Assertions: 2951, Failures: 2`) پاک نشده است.**
- **هیچ تست امنیتی skip/skip‑soft/xfail نشد؛ هیچ gate‌ای تضعیف نشد؛ skip list تغییر نکرد؛
  Phase 3/`AuthorizationService` شروع نشد؛ PR#10..#13 دست‌نخورده.**
- **باقی‌ماندۀ C6 (بدون تغییر):** Tripwire→CI · C6‑F جامع (۱۴‑موردی) · طبقه‌بندی/تصمیم
  D‑route‌ها (از جمله `files/{id}/stream` که در skip list است و read‑path بالینی‌اش هنوز
  Per‑Object check ندارد) · workflow onboarding عضویت staff · **C6 = IN PROGRESS** (در آن مقطع؛ اکنون CLOSED — رجوع به بالای سند).

### سابقه (روی `6e5d48c` — C6 Reports+Export+Pilot)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + **WPCS line-scoped**) | 34375762362 | ✅ success |
| Real-WP (PR) | 34375762425 | ✅ success |
| Real-WP (push) | 34375756020 | ✅ success |
| Pilot/Staging | 34375756043 | ✅ success |
| Closure | 34375756075 | ✅ success |

### سابقه (روی 9e006b0 — C6-E2)

| گیت | Run | نتیجه |
|---|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | 34352114628 | ✅ success |
| Real-WP (PR) | 34352114325 | ✅ success |
| Real-WP (push) | 34352109691 | ✅ success |
| Pilot/Staging | 34352109661 | ✅ success |
| Closure | 34352109682 | ✅ success |

### سابقه (روی 315e582 — C5 کامل)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration 527 تست + **WPCS line-scoped**) | 34336052376 | ✅ success |
| Real-WP (PR) | 34336052355 | ✅ success |
| Real-WP (push) | 34336047913 | ✅ success |
| Pilot/Staging | 34336047922 | ✅ success |
| Closure | 34336047920 | ✅ success |

### سابقه (روی 41b24dc — C4 کامل)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + WPCS line-scoped) | 34330257136 | ✅ success |
| Real-WP (PR) | 34330256991 | ✅ success |
| Real-WP (push) | 34330252423 | ✅ success |
| Pilot/Staging | 34330252351 | ✅ success |
| Closure | 34330252342 | ✅ success |

### سابقه (روی db35873 — C3)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + WPCS changed-files) | 34316294584 | ✅ success |
| Real-WP (push) | 34316289951 | ✅ success |
| Real-WP (PR) | 34316294518 | ✅ success |
| Pilot/Staging | 34316289927 | ✅ success |
| Closure | 34316290002 | ✅ success |

### سابقه (روی f23e0c5)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration) | 34309588163 | ✅ success |
| Real-WP (push) | 34309588103 | ✅ success |
| Real-WP (PR) | 34309583290 | ⚠️ functional ✓ — فقط آپلود artifact شکست (**Class C infra**)؛ rerun/dispatch توسط GitHub/token رد شد؛ دوقلوی push همان SHA کامل سبز. بازبینی با event بعدی |
| Pilot/Staging | 34309583258 | ✅ success |
| Closure | 34309583310 | ✅ success |

## Current substep

- **C6 — CLOSED (Owner/Architect approved technical closure).** Implementation evidence: `3fc5a54` (pre-merge); **integrated checkpoint: `099b644`** (`origin/main`, PR #14 MERGED).
  - ✅ C6-A..E2 (census, Notif/SMS/Jobs, Booking/Schedule, Patients/Clinical/Visits/Files, Settings/Audit/Idempotency + Migration 0020, repo writes)
  - ✅ C6-E3 Reports (`1c82d26` + Class D `a120a68`)
  - ✅ C6 Export (`f2c0ca6`) — clinic in payload; purge per-row
  - ✅ Pilot/bin (`6e5d48c`) — no literal clinic_id=1
  - ✅ Tripwire production runtime = 0 (34 self-tests) — CI enforced
  - ✅ Trusted REST ScopeContext (membership-verified) — fail-closed
  - ✅ C6-F isolation — 45/45 VERIFIED_GREEN (MT-39 runtime)
  - ✅ All quality gates GREEN on 3fc5a54
  - ✅ No known Critical/High C6 tenant-isolation defect

### C6 Deferred Boundaries (NOT C6 scope — recorded for transparency)

| ID | Item | Decision | Notes |
|---|---|---|---|
| A | Final role/capability policy | DEFERRED to Phase 3 | Scope beyond tenant isolation |
| B | Staff onboarding UI/API | DEFERRED to LATER_STAFF_ADMIN_UX | Global WP role MUST NOT auto-create Membership |
| C | SMS resend CHAR(64) truncation | KNOWN_MEDIUM_DEBT | Schema-dependent; not C6 blocker |
| D | Historical closure hash mismatch | EXPECTED_NEGATIVE_TEST | Not a defect |

- **C7 — CLOSED (2026-09-11).** ترمیم C7 (S1..S6) از طریق **PR #20 MERGED**
  در `a385d868` به main یکپارچه شد؛ زنجیرهٔ RED→GREEN و Run IDها در
  [`c7-0-census.md`](c7-0-census.md) §۱۱ و وضعیت جاری در
  [`project-current-state.md`](../project-current-state.md) §C.
  بستن فنی C7 از قبل مبتنی بر شواهد بود (زنجیرهٔ RED→GREEN + همهٔ گیت‌ها سبز + ادغام در main)؛
  **پذیرش/بستن رسمی اکنون با تصمیم صریح مالک در 2026-09-11 ارائه و ثبت شد** ⇒ C7 = **CLOSED**.
  این پذیرش فقط دامنهٔ تعریف‌شده/تکمیل‌شدهٔ C7 را در بر می‌گیرد: **نه** ادعای کامل‌بودن مطلق
  ایزولاسیون tenant در کل محصول است، **نه** تأیید آمادگی تجاری، و اقلام به‌تعویق‌افتاده را
  — مگر با تصویب جداگانه — تصویب نمی‌کند و هیچ migration یا فاز بعدی را مجاز نمی‌داند.
  بدون Migration (0021 ساخته/تصویب نشد)؛ Phase 3 شروع‌نشده؛ اقلام S2/S3 و
  Jobs/SMS/timezone و UX چندکلینیکی wp-admin **به تعویق افتاده و تصویب نشده‌اند**.

> **🔴 وضعیتِ اقلام به‌تعویق‌افتادهٔ Jobs/SMS/timezone (2026-09-12):** **جهتِ طراحی تأیید شد ولی
> پیاده‌سازی نشده است** — مشخصاتِ کانونی در
> [`../architecture/phase2-tenant-context-remediation-design.md`](../architecture/phase2-tenant-context-remediation-design.md)
> (**APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**). آن سند: (A) tenant contextِ صریح/ایزوله
> برای Jobها، (B) طبقه‌بندیِ صریحِ Jobهای system-wide با **بازماندنِ عمدیِ** `backup.run` /
> `license.refresh` / `cleanup.oplog` و **ممنوعیت** `clinic_id = 0` و Clinic مصنوعی، (C) timezone
> عملیاتی = **Location**، (D) رزولوشن per-Clinicِ پیکربندی SMS، (E) انتساب tenant در لاگ عملیاتی
> (ستون `clinic_id` به‌تنهایی authorization نیست)، (F) قواعد backfillِ Jobهای legacy؛ به‌علاوهٔ
> **۶ سوالِ طراحیِ پیش از هر migration آینده** (بدونِ رزروِ شمارهٔ migration) و **مشخصاتِ ۱۴ تستِ RED
> ‏(RT-1..RT-14)**؛ به‌علاوهٔ **طبقه‌بندیِ T/S/W برای ۱۵ نوعِ واقعیِ ثبت‌شده در `App::dispatcher()`** (§A-3).
> **این ثبت، مجوز پیاده‌سازی نیست**؛ **End Gate فاز ۲ شروع/تعریف/پاس نشد**؛ Migration `0021`
> ساخته نشد (آخرین = `0020`)؛ هیچ تستی نوشته/تضعیف/حذف نشد؛ Phase 3 / Phase 17 همچنان NOT STARTED.

### C8 — CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location فاز ۲ (2026-09-11 — بدون پیاده‌سازی)

**قاعدهٔ تفسیر (حفظ‌شده):** وجود برچسب داخلی/canonical «C8» به‌تنهایی اثبات‌کنندهٔ نیاز به کار
پیاده‌سازی **نیست**؛ C8 صرفاً به‌خاطر وجود برچسب Queue به کار پیاده‌سازی تبدیل **نشد**. بر پایهٔ
شواهد بازبینی‌شده **هیچ شکاف پیاده‌سازیِ تأییدشده‌ای در invariantهای فاز ۲ حوزهٔ Location وجود
ندارد** ⇒ C8 = **بستهٔ شواهد/مستندسازی/closure** و همین‌طور (فقط‌مستندات) بسته شد.

**مرز بسته‌شدن C8 (بر پایهٔ شواهد موجود — بدون کار جدید):**
- معماری `Organization → Clinic → Location` معتبر است و Location متعلق به Clinic است
  (FK). **AD-15 = طراحی هدف مصوب** («هر Clinic حداقل یک Location») — اما شواهدِ روی main
  جاری این موارد را اثبات می‌کند، **نه** یک invariant اثبات‌شدهٔ چرخهٔ ساخت برای همهٔ
  Clinicهای آتی: Migration 0011 در زمانِ migration برای Clinicهای موجودِ آن زمان یک
  Location اصلی seed می‌کند (per-clinic، idempotent)؛ schema `cpms_locations` با مالکیت
  Clinic مستقر است؛ `PrimaryLocationResolver` در نبودِ Location اصلی **fail-closed**
  است (`resolve()` ⇒ استثنای صریح؛ `tryResolve()` ⇒ null)؛ و چهار جدول عملیاتی
  (schedule/schedule_slots/appointments/visits) `location_id NOT NULL` با بک‌فیل از
  Location اصلی دارند (Migration 0013). ادعای «هر مسیرِ ساخت Clinic به‌صورت
  اجباری/اتمیک Location ایجاد می‌کند» روی main جاری اثبات نشده و ثبت نمی‌شود.
- **Membership و Location Assignment دو مفهوم متمایزند**؛ شرکت در چند Clinic منجر به تکثیر
  پروفایل حرفه‌ای Clinician نمی‌شود (یک پروفایل حرفه‌ای به‌ازای هر WP User).
- بدون fallback به Clinic-ID-1؛ بدون پیش‌فرض/فرض Tehran یا province_id/city_id.
- شیء هدف (Location/رکورد انتخاب‌شدهٔ کلاینت) هرگز منبع زمینهٔ tenant معتبر نیست.
- مبنا = شواهد موجود Location/schema/resolver/membership-assignment — بدون شواهد جدید.

**آنچه بستن C8 مجاز نمی‌داند (صریحاً):**
- هیچ پیاده‌سازی Location جدید صرفاً برای «پر کردن برچسب بسته»؛
- هیچ Migration 0021؛
- هیچ دیتاست استان/شهر/_master-data ایران؛
- هیچ seed جغرافیای سراسری؛
- هیچ UX مدیریت master-data؛
- هیچ سیاست نهایی role/capability فاز ۳؛
- هیچ کار portal؛
- هیچ «اصلاح» scheduling/reminder/timezone صرفاً به‌واسطهٔ این بسته‌شدن.

داده‌های استان/شهر ایران و UX مدیریت Master Data ⇒ **Owner Roadmap Phase 4**؛
مجوزدهی scoped نهایی ⇒ **Owner Roadmap Phase 3**.

**مرز شناخته‌شدهٔ timezone محل (بدون اصلاح در اینجا):** معماری دائم (AD-08 / Q7، ADR-0031)
می‌گوید `locations.timezone` مرجع عملیاتی است (canonical = UTC؛ `clinics.timezone` فقط
پیش‌فرض اولیه می‌ماند)؛ اما شواهد قبلاً ثبت‌شده نشان می‌دهد مصرف‌کنندگان runtime فعلی/legacy
هنوز از timezone کلینیک/settings استفاده می‌کنند و هم‌پوشانی مالکیت کانونی با کار scheduling
بعدی است. این موضوع به‌عنوان **مرز ثبت‌شده/قلم آشتی‌دهیِ به‌تعویق‌افتاده** ثبت می‌شود —
آشتی‌دهیِ مصرف runtime با قاعدهٔ AD-08/Q7 طبق شواهد کانونی به کار اجرایی/ممیزیِ بعدیِ
scheduling/timezone موکول است؛ **خودِ قاعدهٔ معماری (`locations.timezone` = مرجع عملیاتی)
تصمیم‌گرفته‌شده و پابرجاست و «تصمیم جدید مالک» نیست.** ادعای این نیست که همهٔ
مصرف‌کنندگان runtime اکنون از timezone محل استفاده می‌کنند.

**معنای بسته‌شدن C8:** «فوندیشن Location فاز ۲ بر پایهٔ شواهد جاری بسته شد» — **نه**
«تمام رفتار آتی Location/timezone کامل است».

- **Do not start:** C8 implementation (بسته شد؛ فقط‌مستندات — شروع پیاده‌سازی مجاز نیست)، Phase 3، Migration 0021.

### C9 — i18n محدودشده: ادغام‌شده و **CLOSED** (پذیرش رسمی مالک، پس از ادغام) — 2026-09-11

**وضعیت: «C9 = CLOSED — پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11، پس از
ادغامِ موفقِ پیاده‌سازیِ محدودشده در `main`.»**

**متنِ تصمیمِ مالک (که بسته‌شدن بر پایهٔ آن ثبت می‌شود):** مالک **به‌طور صریح
پذیرش/بستن رسمیِ C9 را تصویب کرد** — پس از ادغامِ موفق. این تصویب **پس از** ادغام
و **پس از** سبزیِ گیت‌های پس‌از‌ادغام روی همان SHA صادر شد؛ پیش از آن لحظه وضعیتِ
معتبر «پیاده‌سازیِ محدودشده کامل / READY FOR ARCHITECT MERGE REVIEW» بود و
**C9 بسته نبود**. آن واقعیت تاریخی **بازنویسی نمی‌شود**: هیچ سندی در این مخزن
پیش از این تصمیم ادعای بسته‌بودنِ C9 نکرده بود.

**تحویل:** ‏**PR #23 — MERGED** در `7146d5b` (2026-09-11T19:35:54Z)؛ base = `0fd5c27`؛
head تصویب‌شده = `c92737b`؛ والدینِ merge = `0fd5c27` + `c92737b`.

**مرزِ دقیقِ این بسته‌شدن — فقط کارِ محدودشده‌ای که از طریق PR #23 ادغام شد:**

1. دو یافتهٔ A/Lowِ i18n که **خودِ C7** معرفی کرده بود (`ScheduleService.php:389` و
   `FinanceService.php:1111`، هر دو `CLINIC_SCOPE_REQUIRED` / 400 / `data: []`)؛
2. ترمیم در **مرز ارائه/‏REST** (`ScheduleController::wrap()` و `FinanceController::staff()`)؛
3. **حفظِ** `code`/status/`data` و رفتارِ پیش‌فرضِ فارسی، بایت‌به‌بایت؛
4. **پذیرشِ قاعدهٔ رو‌به‌جلوی لایه‌بندیِ بومی‌سازی** (§«قاعدهٔ معماریِ رو‌به‌جلو» پایین
   و `project-current-state.md` §I‑1).

**این بسته‌شدن صریحاً شاملِ مواردِ زیر نیست** (و ادعایشان ثبت نمی‌شود): رفعِ همهٔ
بدهیِ تاریخیِ i18n · فراهم‌شدنِ پشتیبانیِ کاملِ انگلیسی · کامل‌شدنِ globalization ·
translation‑ready بودنِ همهٔ رشته‌های wp‑admin · رفعِ بدهیِ تاریخیِ i18n در
Domain/Application (۱۳ نقطهٔ Domain و ۱۹ نقطهٔ Application دست‌نخورده و **بدهیِ
تحمّل‌شده** باقی می‌مانند) · بسته‌شدنِ Phase 2.

**تذکرِ معماریِ حفظ‌شده:** تطبیقِ **دقیقِ پیامِ منبع** (‏exact‑source‑message match) که
برای این دو پیامِ C7 به‌کار رفت یک **تکنیکِ سازگاریِ گذرا و محدودشده** است، **نه
معماریِ ترجیحیِ بلندمدت**. این بسته‌شدن آن را به الگو ارتقا نمی‌دهد.

#### دامنهٔ محدودشده — دقیقاً دو یافته (هر دو **A, Low**)

تعیینِ scope/شواهدِ محدودشده (که پیش‌شرطِ اجرا بود) انجام شد و نشان داد از **۵** محلِ
throw ‏`CLINIC_SCOPE_REQUIRED` در `src/`، **دقیقاً ۲ مورد** توسط خودِ C7 معرفی شده‌اند:

| # | یافته | کامیت معرفی‌کننده | بستهٔ کاری | کد | HTTP | data |
|---|---|---|---|---|---|---|
| ۱ | `ScheduleService::requireClinicianForTrustedClinic()` — ‏`src/Application/Booking/ScheduleService.php:389` | `04a7a79ecff11ccc00a80b9fb68a1cc2d1167cf4` | C7‑S5 (PR #20، merge `a385d868`) | `CLINIC_SCOPE_REQUIRED` | 400 | `[]` |
| ۲ | `FinanceService::requireTrustedClinicId()` — ‏`src/Application/Finance/FinanceService.php:1111` | `c4cf90240413f4fdf72682b5071b36da89fb37a5` | C7‑S3 (PR #20، merge `a385d868`) | `CLINIC_SCOPE_REQUIRED` | 400 | `[]` |

**اثبات provenance:** ‏`git log -S'<literal>'` روی تاریخچهٔ کامل (۳۵۹ کامیت) برای هر دو
**دقیقاً یک کامیت** برمی‌گرداند و `git blame` همان SHA را تأیید می‌کند ⇒ رشته یک‌بار
اضافه و هرگز تغییر نکرده. برای مورد ۲، نسخهٔ پیش از C7
(`git show c4cf9024^:…`) متدِ `explicitTrustedClinicId(): ?int` بود که فقط `null`
برمی‌گرداند و **هیچ throw و هیچ پیام انسانی نداشت** ⇒ رشته ۱۰۰٪ جدیدِ C7 است. برای مورد ۱،
کلِ متدِ `requireClinicianForTrustedClinic()` در `04a7a79e` جدید است (همهٔ خطوط `+`).

**سه محلِ دیگر عمداً دست نخوردند** (همه **پیش از C7** ⇒ کلاس B):
‏`Application/Reports/ExportService.php:398` ← `f2c0ca6` (C6) ·
‏`Application/Scope/SystemClinicResolver.php:44` ← `1f8b36d` (Phase 2 P2‑B) ·
‏`Application/Scope/TrustedClinicEstablisher.php:63` ← `f88fcdc` (C6).

#### ترمیم — فقط در مرز ارائهٔ REST

- ‏**`src/Rest/ScheduleController.php`** — داخل همان مرزِ موجودِ `wrap()`: فقط واریانتِ
  واجدِ شرایط (`errorCode === 'CLINIC_SCOPE_REQUIRED'`) با msgid **literal** و دامنهٔ
  ‏`cpms`. در این مرز تنها منبعِ آن کد همان گارد C7‑S5 است (‏`ScheduleService` در کلِ
  ‏`src/Rest/` فقط توسط `ScheduleController` مصرف می‌شود)، پس خودِ کد کافی است.
- ‏**`src/Rest/FinanceController.php`** — داخل همان مرزِ موجودِ `staff()`، فقط شاخهٔ
  ‏`FinanceException`: شرطِ **کدِ پایدار + تطبیقِ بایت‌دقیقِ خودِ پیامِ منبع**
  (‏`errorCode === 'CLINIC_SCOPE_REQUIRED' && $message === '<همان literalِ C7>'`).
  این شرط **ضروری** است، چون در همین مرز کد به‌تنهایی یکتا نیست:
  ‏`FinanceService::trustedClinicId()` (مسیر `listServices()`/`summary()` ⇒ روت‌های
  ‏`GET /clinic/v1/config/services` و `GET /clinic/v1/finance/summary`) همان کد را از
  ‏`SystemClinicResolver` با پیامِ **پویا** (شاملِ تعداد Clinicها) و
  ‏`data['clinic_count']` باز‌نگاشت می‌کند. از **شکلِ `data` به‌عنوان شناسه استفاده نشد**:
  ‏`[]` مقدارِ پیش‌فرضِ سازندهٔ `FinanceException` است، هیچ قراردادِ معناییِ مستندی ندارد
  (‏ADR‑0019 و `error-codes.md:132` هیچ معناشناسی‌ای برای `data` تعریف نکرده‌اند)، و یک
  ‏`ScopeRequiredException` با همین کد و `data` خالی و پیامِ انگلیسی از قبل در
  ‏`TrustedClinicEstablisher:63` موجود است که `trustedClinicId()` آن را عام بازنشر می‌کند.
  تطبیقِ پیام **fail-safe** است: اگر literalِ سرویس عوض شود، پیامِ واقعیِ سرویس دست‌نخورده
  عبور می‌کند و assertionِ ضدِّواگراییِ A2 واگرایی را قرمز می‌کند. این **یک تکنیکِ گذرا و
  محدودشده** برای همین دو پیامِ C7 است، نه معماریِ مطلوبِ بلندمدت. شاخهٔ `VisitException`
  بدون تغییر ماند (‏`VisitService` هیچ throw با این کد ندارد).

جمعِ تغییرِ تولیدی: **۲ فایل، ۴۴ خط افزوده / ۲ خط حذف‌شده** (عمدهٔ آن توضیحِ معماری).
**هیچ فایل Domain یا Application تغییر نکرد** · هیچ `phpcs:ignore` افزوده نشد ·
از `__($e->getMessage(), 'cpms')` استفاده **نشد** · هیچ migration/schema ·
هیچ ابزار یا workflow/step جدید CI · هیچ تغییری در پیکربندی WPCS ·
هیچ abstraction/شناسهٔ جدید (بدون نگاشتِ عمومیِ خطا، بدون چارچوبِ `message_key`،
بدون translator port، بدون بازنشانیِ معماریِ خطای Domain/Application).

#### حفظِ سازگاری (هر پنج مورد)

‏`code` = `CLINIC_SCOPE_REQUIRED` و HTTP = `400` و `data` = دقیقاً `{status: 400}`
بدون تغییر به `RestBase::error()` می‌روند؛ ترجمه فقط آرگومانِ `message` را لمس می‌کند.
پیامِ فارسیِ پیش‌فرض **بایت‌به‌بایت** حفظ می‌شود، چون افزونه **هیچ** کاتالوگِ
‏`.po`/`.mo`/`.pot` ندارد و **هیچ** `load_plugin_textdomain()` صدا نمی‌زند ⇒ دامنهٔ
‏`cpms` به `NOOP_Translations` خالیِ WordPress می‌رسد و `__($msgid,'cpms')` همان
msgid را عیناً برمی‌گرداند (مستندِ رسمیِ `translate()`: «اگر ترجمه‌ای نباشد **یا دامنه
بارگذاری نشده باشد**، همان متنِ اصلی برگردانده می‌شود»). این رفتار پیش‌تر هم در همین
مخزن به‌صورت عملیاتی اثبات شده بود: `RestClinicContext::toError()` از C6 همان
‏`__(…,'cpms')` را روی مسیرِ زندهٔ REST صدا می‌زند و سوئیت سبز است. رفتارِ
fail‑closed و non‑enumeration هم دست‌نخورده ماند (ترجمه **پس از** همهٔ تصمیم‌های
fail‑closed اعمال می‌شود و پاکتِ ۴۰۴‑parity ‏`CLINIC_NOT_FOUND` اصلاً با predicate
منطبق نمی‌شود). **هیچ تغییرِ قرارداد API لازم نشد** (`api-contract.md` §۰ و
‏`ADR-0019:9` بایت‌های `message` را پین نکرده‌اند؛ `NFR-UI-4` «i18n‑ready با fa
پیش‌فرض» را می‌خواهد).

#### شواهد اجرایی — زنجیرهٔ RED→GREEN

PHP در sandbox روی PATH نبود و نصب آن هم ممکن نشد (شبکه فقط به GitHub محدود است)؛
طبق روالِ مستندِ همین مخزن **شواهد اجرایی = GitHub Actions**. دستورِ واقعیِ job
Integration:

```
php -d memory_limit=1G vendor/bin/phpunit --no-configuration \
  --bootstrap tests/integration-bootstrap.php tests/Integration
```

- ‏**RED** — کامیتِ فقط‑تست `81d4e8d` (تنها فایلِ افزوده‌شده
  ‏`tests/Integration/C9RestMessageI18nTest.php`)، run **`34630765255`** (event
  ‏`pull_request`) ⇒ job «Integration (WP 6.7 + MySQL 8)» **failure** با
  ‏**`Tests: 674, Assertions: 4571, Failures: 2`**. آن دو شکست **دقیقاً** دو تستِ
  «translation‑readiness» بودند:
  ‏`testScheduleScopeRequiredMessageIsTranslatableAtRestBoundary` (خط ۲۰۷) و
  ‏`testFinanceScopeRequiredMessageIsTranslatableAtRestBoundary` (خط ۲۲۶) — در هر دو،
  مقدارِ Actual همان فارسیِ جاری بود. یعنی **RED واقعی و محدود به همان شکافِ هدف**،
  بدون تضعیفِ fixture و بدون تغییرِ رفتارِ امنیتیِ مورد انتظار. چهار تستِ دیگر
  (پیش‌فرض/تفکیک‌کنندهٔ منفی/امنیت) سبز بودند و در فهرست شکست ظاهر نشدند. در همان
  head، jobهای Unit×4 و PHPStan و WPCS (changed code) و Tenant Tripwire همگی **success**
  بودند ⇒ RED فقط از Integration و فقط از همان دو assertion می‌آمد.
- ‏**GREEN — تأییدشده روی head نهاییِ `c92737b`.** در run ‏`34637483738` (CI روی همان
  head) job ‏«Integration (WP 6.7 + MySQL 8)» — **همان jobی که در `81d4e8d` failure بود** —
  ‏**success** است؛ و در همان run ‏`Static Analysis (PHPStan)`، هر چهار `Unit Tests`
  (PHP 8.1/8.2/8.3/8.4)، ‏`WPCS (changed code)` و `Tenant Tripwire` همگی **success** هستند.
  تست‌ها بدون هیچ تغییری در خودشان سبز شدند. سپس روی SHA ادغام‌شدهٔ `7146d5b` هر ۴ گیت
  پس‌از‌ادغام سبز شدند و مجموعاً ۱۹ check run — همه success. **هیچ check معلقی «سبز»
  نامیده نشده**: همهٔ نتایجِ بالا `status: completed` + `conclusion: success` هستند.

تست‌ها فقط **رفتارِ بیرونیِ قابل مشاهده** را می‌سنجند (پاکت REST: ‏`code`/`message`/
‏`data`/HTTP + اثرِ سمت DB). هیچ تستی اجرا شدنِ gettext داخل `ScheduleService`/
‏`FinanceService` را assert نمی‌کند. مکانیزمِ ترجمهٔ کنترل‌شده = فیلترِ core
‏`gettext_cpms` (بدون نیاز به فایل `.mo`). شرطِ «نبودِ Scope در زمانِ سرویس» با یک
probe با priority ۱۱ روی `rest_request_before_callbacks` ساخته می‌شود که Scope را
**پس از** برقراریِ مشروعِ مرز C6 پاک می‌کند — دقیقاً همان تکنیکِ موجود در
‏`RestTrustedClinicContextTest` (نمونهٔ `$spy`/`$bomber`)؛ هیچ فیلترِ تولیدی حذف یا
ضعیف نشد.

#### قاعدهٔ معماریِ رو‌به‌جلو (دائمی)

- ‏**Domain** هیچ وابستگیِ مستقیمِ **جدیدی** به i18n/ارائهٔ WordPress نمی‌گیرد؛ کدِ جدیدِ
  Domain نباید `__()` یا معادلِ آن را صدا بزند.
- ‏**کدِ کسب‌وکاری/سرویسیِ Application** به‌صورت الگوی پیش‌فرض coupling مستقیمِ جدیدی با
  i18n/ارائهٔ WordPress اضافه نمی‌کند.
- ‏**بومی‌سازیِ متنِ انسانی در مرزهای ارائه/adapter** است (REST controllerها، wp‑admin) —
  همان الگوی مستقر `RestClinicContext::toError()` (از C6) و `ClinicianAdminPage` (از C7‑S4).
- ‏**`code`/HTTP status/دادهٔ ساخت‌یافتهٔ ماشین‌خوان authoritative می‌مانند.**
- استفادهٔ موجودِ Domain/Application از `__()` **بدهیِ تاریخیِ تحمّل‌شده است، نه سابقهٔ
  قابلِ توسعه**: ‏**۱۳** محل در Domain محصور در **۲ فایل از ۴۲ فایل**
  (‏`Domain/Membership/MembershipException.php` = ۷، ‏`Domain/Patients/PatientIdentityException.php` = ۶)
  و **۱۹** محل در Application محصور در ۲ فایل
  (‏`Application/Membership/MembershipService.php` = ۱۳، ‏`Application/Patients/PatientIdentityService.php` = ۶).
  ‏**اصلاح/پاکسازی نشدند** و پاکسازیِ انبوه در دامنهٔ C9 نبود.

> **تکرارِ literal یک تکنیکِ گذرا (transitional) است، نه معماریِ مطلوبِ بلندمدت.**
> msgidهای literalِ افزوده‌شده در دو controller کپیِ بایت‌به‌بایتِ رشتهٔ منبعِ سرویس هستند.
> این **یک تکنیکِ سازگاریِ گذرا و عمداً محدودشده** برای همین دو پیامِ معرفی‌شدهٔ C7 است و
> دلیلش یک محدودیتِ ابزارِ موجود است: قاعدهٔ
> ‏`WordPress.WP.I18n.NonSingularStringLiteralText` در WPCS (که روی خطوطِ add‌شده نسبت به
> baseline گیت می‌شود) msgidِ غیر‑literal — شامل `__($e->getMessage(), 'cpms')` و حتی
> constant کلاس — را **ERROR** می‌گیرد؛ پس literal تنها راهِ سبز نگه‌داشتنِ همان گیتِ موجود
> **بدون** هیچ `phpcs:ignore` و **بدون** تضعیفِ WPCS بود.
> **این تکرار به‌عنوان معماریِ مطلوبِ بلندمدت مستند نمی‌شود.** معماریِ بلندمدت همان بندهای
> «قاعدهٔ معماریِ رو‌به‌جلو» بالاست؛ همگراییِ بعدی می‌تواند این تکرارِ گذرا را جایگزین کند —
> اما **فقط** وقتی با شواهدِ گسترده‌تر توجیه شود، نه صرفاً برای حذفِ دو literal. به‌همین
> دلیل در این بسته **هیچ abstraction جدیدی فقط برای حذفِ دو literalِ تکراری ساخته نشد**:
> مرزِ REST از قبل اطلاعاتِ کافی برای تشخیصِ امنِ هر دو واریانت داشت (هویتِ ایستای
> controller + کدِ پایدار + خالی‌بودنِ `data`)، پس ساختِ نگاشتِ عمومیِ خطا یا چارچوبِ
> ‏`message_key` نامتناسب بود. واگراییِ سرویس/کنترلر با assertionِ **رفتارِ بیرونی** قفل
> شده است (تست‌های پیش‌فرض، `message` پاکت REST را با `getMessage()` استثنایِ سرویسِ واقعی
> مقایسه می‌کنند — نه مقایسهٔ دو literalِ منبع).

#### گارْد معماری — تصمیم

‏**هیچ ابزارِ گارْدِ جدیدی ساخته نشد**: بدون scanner جدیدِ allowlistِ i18nِ Domain و بدون
workflow/step جدیدِ CI. پیکربندی WPCS هم دست نخورد. دلیلِ مبتنی بر شواهد: sniff
‏`WordPress.WP.I18n` فقط «چگونگیِ» فراخوانی `__()` را می‌سنجد (تطابق دامنه، literal بودنِ
آرگومان‌ها، placeholderها) و **نه** می‌تواند قاعدهٔ لایه‌ای («در Domain ‏`__()` ممنوع») را
بیان کند و **نه** sniff استانداردی برای «literalِ پیچیده‌نشده در `__()`» وجود دارد. پس
قاعدهٔ معماری فعلاً **مستند/بازبینی‌محور** است — هم برای Domain و هم برای Application.
(اگر در آینده ابزار خواسته شود، ساده‌ترین شکلِ پایدار یک allowlist **سطحِ فایل** با دقیقاً
۲ ورودیِ Domain است، چون baseline از C4/C5 تاکنون دست‌نخورده بوده؛ این فقط یک گزینهٔ
ثبت‌شده است، نه مصوبِ این بسته.)

#### بدهیِ محدودشدهٔ به‌تعویق‌افتاده (ثبت‌شده، اصلاح‌نشده)

- ‏**مرز wp‑admin برنامهٔ هفتگی:** ‏`src/Admin/ClinicianAdminPage.php:436` و `:494` همان
  پیامِ سرویسِ Schedule را خام (`'خطا: ' . $e->getMessage()`) رندر می‌کنند — **همچنان
  تأیید شد که موجود است**. چون دامنهٔ اعلام‌شدهٔ C9 «دو پیامِ REST» بود، این مسیر
  **به‌تعویق افتاد** و فقط ثبت می‌شود.
- ‏۱۳ محلِ Domain و ۱۹ محلِ Application (بالا) — بدهیِ تحمّل‌شده.
- ‏**دسترس‌پذیریِ این دو پیام در REST شرطی/لایهٔ دومِ دفاعی است:** در درخواستِ REST
  تولیدیِ کاربرِ staff، مرز C6 ‏(`rest_request_before_callbacks`) Scope را **پیش از**
  callback می‌بندد و در صورتِ شکست، `RestClinicContext::toError()` با پیامِ عمومیِ خودش
  (‏`محدودهٔ کلینیک لازم است.`) پاسخ می‌دهد و callback اجرا نمی‌شود. بنابراین گاردِ سطحِ
  سرویس نقشِ defence‑in‑depth دارد. این واقعیت **طراحی را تغییر نمی‌دهد** (هرگاه پیام
  منتشر شود از همان مرز می‌گذرد) و در طراحیِ تست لحاظ شد. تست‌های موجودِ C7 هم همین حالت
  را با فراخوانِ مستقیمِ سرویس پس از `App::resetScope()` می‌سازند.

#### صریحاً ادعا **نمی‌شود**

همهٔ بدهیِ i18n برطرف نشده · پشتیبانیِ کاملِ انگلیسی فراهم نشده · آمادگیِ
globalization اعلام نمی‌شود · هیچ کاتالوگِ ترجمه افزوده نشد · همهٔ رشته‌های wp‑admin
translation‑ready **نشده‌اند** · بدهیِ تاریخیِ i18n در Domain/Application رفع نشده
(۱۳ + ۱۹ نقطه، بدهیِ تحمّل‌شده) · **پیاده‌سازی C10 شروع نشده و مجاز نشده** ·
‏**Phase 2ِ Owner Roadmap بسته نشده (IN PROGRESS)** · Phase 3 آغاز نشده ·
Migration `0021` نه ساخته و نه تصویب شد.

> **وضعیتِ عملکرد — اصلاحِ واژگانی (۲۰۲۶-۰۹-۱۱):** گفتارِ کلیِ «عملکرد کاملاً
> اندازه‌گیری‌نشده / هیچ شواهد benchmark واقعی موجود نیست» **نادرست بود و اصلاح شد**.
> **شواهد benchmark واقعی اما محدودِ Staging موجود است.** راستی‌آزمایی مستقیمِ مخزن:
> گامِ قابل‌اجرای «Performance benchmark — ab (p50/p95/p99 + RPS + error rate)» داخل job
> ‏`staging-gate` در `.github/workflows/pilot-gate.yml` قرار دارد، و نتایجِ اجراشده در
> ‏`docs/phase-reports/report-pilot-gate.md` §8 با p50/p95/p99/RPS/error واقعی ثبت شده‌اند
> (منبعِ ثبت‌شدهٔ خودِ گزارش: steps سبزِ runهای `34025267752` و `34027029638`). در
> چک‌پوینتِ جاریِ C9 نیز job ‏«Staging Gate (fresh install → restore drill)» در run
> ‏`34639699635` ‏**success** شد؛ **اعدادِ دقیقِ بنچمارکِ همان run از این sandbox قابل
> بازیابی نیست** (endpoint لاگ‌ها به `results-receiver.actions.githubusercontent.com`
> ریدایرکت می‌شود و از اینجا مسدود است) — پس برای آن run فقط «اجرای موفق» تأیید می‌شود،
> نه اعداد.
> اما آن محیط **محیطِ مرجع/تجاریِ کانونی نیست** و همان گزارش **صریحاً ادعا نکرد** اهدافِ
> مصوب پاس شده‌اند (§8: «اهداف مصوب (هیچ‌کدام پاس‌شده اعلام نمی‌شوند)»؛ داوری Quality Gate
> عملکرد موکول به بنچمارکِ سرور مرجع است — Runbook §12.3، ‏BLOCKED_BY_ENVIRONMENT).
> بنابراین **عملکردِ محیط مرجع/تجاری در برابر آستانه‌های NFR همچنان
> اندازه‌گیری‌نشده/اعتبارسنجی‌نشده است**؛ و نیز اندازه‌گیری‌نشده می‌مانند: هدفِ سربارِ
> صفحات عمومی (‏p95 < 100ms)، اندازه‌گیریِ تعداد کوئری/‏N+1، حافظه، و بارِ معنادارِ
> چندکلینیکی. **هیچ عددِ Staging اثباتِ عملکردِ Production نیست** و این اصلاح C10 را
> شروع یا مجاز نمی‌کند.

> بسته‌شدنِ رسمیِ C9 (تصمیمِ صریح مالک 2026-09-11، پس از ادغام) **فقط** همان چهار قلمِ
> دامنهٔ محدودشدهٔ بالا را پوشش می‌دهد و هیچ‌یک از مواردِ «ادعا نمی‌شود» را تغییر نمی‌دهد.

- **Do not start:** پیاده‌سازی/بهینه‌سازیِ عملکرد (C10 implementation — تا بستن رسمی مالک مجاز نیست)، Phase 3، Migration 0021، پاکسازیِ انبوهِ بدهیِ i18n، ساختِ
  abstraction جدید فقط برای حذفِ دو literalِ تکراری، و تعمیمِ تکنیکِ تطبیقِ دقیقِ
  پیامِ منبع به الگوی بلندمدت.

### C10 — بستهٔ شواهد عملکرد: CLOSED — پذیرش/بستن رسمی مالک 2026-09-12 (بدون پیاده‌سازی)

**وضعیت: C10 = CLOSED — تصمیم صریح مالک در 2026-09-12**، پس از ادغام بستهٔ شواهد در
`origin/main` = `bdb135e9` (PR #25 MERGED؛ ۴ گیت پس‌از‌ادغام success — جدول بالا). دامنهٔ
بستن = فقط بازبینی محدودشدهٔ شواهد عملکرد فاز ۲؛ **نه** انطباق NFR جاری، **نه** عملکرد بار
پس از Multi-Clinic، **نه** مقیاس‌پذیری، **نه** آمادگی تجاری، **نه** Phase 17، **نه** بستن
Phase 2. همهٔ قیدهای زیر (اعداد تاریخی؛ NOT MEASURED / NOT RETRIEVED) پابرجا هستند.
*(سابقه 2026-09-11: «بازبینی شواهد کامل / واجد شرایط فنی — بستن رسمی مالک PENDING».)*
بستهٔ شواهدِ کامل در سند اختصاصی
[`c10-performance-evidence.md`](c10-performance-evidence.md) ثبت شد؛ خلاصهٔ کراندار:

- **ماهیت:** بستهٔ بازبینی شواهد عملکردِ داخلیِ LEVEL-2، محدود به فوندیشن
  Multi-Clinic فاز ۲ — **نه** Owner Phase 17 (Performance؛ NOT STARTED)، نه
  بهینه‌سازی تجاری، نه مجوز optimization حدسی، نه مجوز تضعیف tenant isolation،
  نه مجوز migration؛ و **نه** اثبات آمادگی عملکردی تجاری.
- **شواهد اجراشدهٔ موجود (واقعی):** اهداف مصوب `performance-baseline.md`؛
  نتایج اجراشدهٔ Staging در `report-pilot-gate.md` §8 (p50/p95/p99/RPS/error
  واقعی؛ «اهداف مصوب هیچ‌کدام پاس‌شده اعلام نمی‌شوند») — **این اعداد تاریخی‌اند
  (runهای 2026-09-06) و مقدم بر تغییرات اصلی Multi-Clinic فاز ۲ (C4..C7)؛ وجود/اجرای
  ابزار benchmark را نشان می‌دهند، نه عملکرد کدِ جاری Multi-Clinic را**؛ گام
  قابل‌اجرای benchmark ‏`ab` در job ‏`staging-gate` از `pilot-gate.yml` (بدون آستانهٔ
  latency)؛ گیت‌های Pilot/Staging ادغام‌شدهٔ سبز روی کد جاری (جدیدترین: `bd2634a` —
  Pilot/Staging `34651627290`) که **فقط اجرای موفق استپ/گیت را اثبات می‌کنند** (استپ
  بدون فعال‌شدن شرط شکست non‑2xx خود کامل شد) — متریک‌های دقیق benchmark جاری، از
  جمله مقدار عددی error rate، از شواهد فعلی Arena قابل بازیابی نیستند (NOT RETRIEVED)
  و موفقیت workflow ≠ پاس‌شدن آستانه‌های latency/NFR. **این شواهد آستانه‌های NFR محیط مرجع/تجاری را پاس‌شده ثابت
  نمی‌کنند.**
- **NOT MEASURED باقی می‌مانند (بلوکر C10 نیستند؛ شواهد آینده — عمدتاً Phase 17 /
  اعتبارسنجی انتشار):** عملکرد محیط مرجع (4vCPU/8GB) در برابر NFR؛ سربار افزونه
  روی صفحات عمومی (p95 < 100ms)؛ شمارش کوئری runtime/N+1؛ حافظه؛ بار معنادار
  چندکلینیکی.
- **بازبینی static محدود فاز ۲:** predicate/ایندکس‌های tenant بازرسی شد؛ membership
  lookup آشکار به‌ازای-ردیف یافت نشد؛ N+1 آشکار در دامنهٔ بازرسی‌شده یافت نشد؛
  resolution مربوط به ScopeContext/Membership کراندار بود؛ کش Settings کلید
  clinic_id دارد؛ مشکل فعلی object-cache tenant-leak وجود ندارد (object cache
  گسترده استفاده نمی‌شود)؛ **هیچ نقص عملکردی VERIFIED نیازمند remediation یافت
  نشد.** بازرسی کد ≠ عملکرد اندازه‌گیری‌شده؛ عدم وجود سراسری N+1 ادعا نمی‌شود.
- **ریسک‌های ثبت‌شده (مبتنی بر شواهد):** مشاهدهٔ گذرای Deadlock روی
  `_transient_cpms_migrate_lock` در c=100 (`report-pilot-gate.md` §8 → Backlog)؛
  کلیدهای کش آینده باید tenant-aware بمانند؛ بنچمارک سرور مرجع = شواهد آینده.
- **برچسب «End Gate (۲۶بندی)»:** تاریخی/داخلی؛ **هیچ فهرست پذیرش کانونیِ تعریف‌شدهٔ
  ۲۶بندی در مخزن جاری یا تاریخچهٔ ردیابی‌شدهٔ بازرسی‌شدهٔ Git یافت نشد**؛ بازسازی ۲۶ بند
  انجام نمی‌شود؛ منشأ عدد به‌عنوان provenance ثبت نمی‌شود.
- **بدهی/اقلام به‌تعویق‌افتاده:** بدون تغییر — S2/S3، Jobs/SMS/timezone، UX
  چندکلینیکی wp-admin به‌تعویق ماندند.
- **Phase 2 همچنان IN PROGRESS؛ End Gate فاز ۲ و Phase 3 شروع نمی‌شوند.**

### سابقهٔ C5 (خلاصه) — Patient Identity Foundation: ✅ کامل (هر ۵ گیت سبز روی `315e582`)
  (کامیت‌های `9207afa` → `2ba16d7` → `315e582` + کامیت docs این واحد):
  - **نرمال‌سازی موبایل ایران** (`9207afa`): ارقام فارسی/عربی → ASCII
    (`fold_digits`)، فرم‌های 98+صفرِ میان‌شهری (۱۳/۱۵ رقمی)، و فیکس مستند شاخهٔ
    معیوب ۱۲رقمی `9809` (خروجی خراب `00...` می‌ساخت → حالا null). تست‌های
    table-driven واحد (۲۳ case).
  - **فوندیشن هویت** (`2ba16d7`): Migration 0019 (`normalized_mobile` غیر یکتا +
    ایندکس `idx_identity_org_mobile` + جدول `cpms_patient_identity_links`)،
    `PatientIdentityException` (`CLINIC_PATIENT_IDENTITY_*`)،
    `PatientIdentityRepository` (همهٔ lookupها org-scoped)،
    `PatientIdentityService` (create/lookup/duplicate_candidates/set_mobile/
    link_clinical_record/clinical_records_for_clinic/link_user/
    identities_for_user؛ fail-closed + anti-enumeration)،
    `App::patient_identity_service()`، تست integration جدید (ماتریس ۲۰بندی
    مالک؛ OTP OD-8 هم دوباره اثبات شد).
  - **فیکس Class D ×4** (`315e582`): expectationهای نسخهٔ schema در
    Phase2SchemaTest/TempTableIsolationTest و سه workflow گیت (pilot/closure/
    real-wp) هاردکد `0018` بودند → `0019`؛ assertion EXPLAIN به «بدون full-scan
    + ایندکس org-scoped» تعدیل شد (انتخاب بین دو ایندکس org-scoped با optimizer
    است — روی دادهٔ کوچک تست، ایندکس کوتاه‌تر را برمی‌گزیند).
  - **داکیومنت** (این کامیت): target-model (یادداشت as-built C5 + نرمال‌سازی)،
    data-dictionary (بخش as-built هویت)، erd.md (اشاره‌گر)، ADR-0031 (وضعیت
    پیاده‌سازی identity)، phase1b-deferred-register (B-14 ✅ / B-17 روشن‌سازی)،
    state.

### سابقهٔ C4 (خلاصه) — کد/تست/داکیومنت
  (کامیت‌های 98ca0ff..91e5fe4) + WPCS-cleanup (کامیت‌های 67e1208..41b24dc):
  بازنویسی سه فایل جدید در سبک WordPress بدون تغییر منطق (snake_case؛
  `App::membership_service()`)، پالایش `phpcs.xml.dist` (پیشوند ClinicCore +
  هفت exclusion مستند)، ارتقای گیت WPCS به **line-scoped** (فقط نقض روی
  خطوط add شده نسبت به baseline می‌شکند؛ legacy مثل App.php با ~۶۵۰ نقض
  تاریخی فقط روی خطوط جدیدش سنجیده می‌شود). دو باگ ابزارِ گیت در راه
  رفع شد (هر دو Class D، با annotation/کامنت قابل‌مشاهده شدند): پیشوند
  progress در خروجی `--report=json` (ruleset `sp`) که JSON را می‌شکست
  (run 34328451996 → فیکس parse در 1364c37) و newlineِ `print()` خالی که
  گیت را روی اجرای سبز قرمز می‌کرد (run 34329655919 → فیکس در 41b24dc).
  اعتبارسنجی لوکال پیش از push: رانر phpcs داخل php-wasm با درخت
  وابستگی resolution مورد انتظار CI (wpcs 3.4.1 + phpcs 3.13.6 +
  PHPCSUtils 1.2.3 + PHPCSExtra 1.5.1) — ۰ نقض روی ۸۶۰ خط add شده؛
  Universal/NormalizedArrays از PHPCSExtra می‌آیند (نه خود phpcs).

### رویداد توکن (ثبت برای handoff)

- پس از push موفق 91e5fe4 (خروجی git push تأیید شد: `005c2ba..91e5fe4`)،
  توکن GitHub با 401 رد شد (مانند رویداد مشابه قبلی در همین فاز که
  خودش بازیابی شد). retry محدود انجام شد؛ credential دستکاری نشد.
  وضعیت ریموت = 91e5fe4 (push پیش از قطعی پذیرفته شده).
  وضعیت: توکن بازیابی شد؛ runهای 91e5fe4 بررسی شدند (WPCS = 34318905233،
  نقض‌های واقعی کد C4 → فیکس در کامیت بعدی).

## Done substeps (این فاز)

- **C4 — Membership primitives**: ✅ (کامیت‌های 98ca0ff..91b24dc؛ جزئیات در
  Current substep و drift-register §۸-۱ و یادداشت C4 در target-model).
- **C1..C2 + Recovery**: کامیت‌های 1f8b36d..f23e0c5 (شرح کامل: کامیت‌ها و
  Migration Foundation Recovery Report در PR #11).
- **C3 — WPCS regression gate**: ✅ سبز (کامیت‌های b6f6c93..db35873).
  `phpcs.xml.dist` + job `wpcs` (استراتژی changed-files از baseline
  f23e0c5). سه تلاش Class C/D در راه: advisoryهای رجیستری روی phpcs 3.11.x
  و wpcs 3.1.0 (→ دامنهٔ ^3.1 + فیلتر امنیتی composer)، allow-plugins، و
  باگ f-string در expansion. جزئیات: drift-register §۸-۱.

## Queue (صف داخلی زیربسته‌های Phase 2 — LEVEL 2)

> **Provenance (ثبت‌شدهٔ صریح):** این صف، صفِ **داخلی زیربسته‌های Phase 2** است که در
> همین سند ثبت شده و `docs/governance/project-phase-taxonomy.md` آن را به‌عنوان
> زیرفازهای **LEVEL 2** فاز ۲ («تابعِ فاز مالک») تأیید می‌کند. **شواهد مخزن،
> تأیید صریح مالک بر این ترتیب دقیق `C4…C10` را اثبات نمی‌کند** (تنها annotation
> تأیید صریح در کامیتِ معرفیِ همین صف — `b6f6c93` — مربوط به C3/WPCS است، نه به
> این ترتیب). این صف **تابعِ** Owner Roadmap Phase 0..20 است و بر آن غلبه نمی‌کند؛
> تا هرگونه تصریح مالک، همین ترتیب به‌عنوان صف جاری معتبر می‌ماند.

C4 Membership primitives → C5 Patient Identity foundation → C6 حذف
tenant hardcodes (census تازه از HEAD) → C7 Repository/Service isolation
(multi-org واقعی) → C8 Iran Location foundation → C9 i18n audit →
C10 Performance review → End Gate (۲۶بندی) → STOP.

> **وضعیت روی همین صف (2026-09-11):** C4..C6 CLOSED · **C7 CLOSED (پذیرش رسمی مالک)** ·
> **C8 CLOSED به‌عنوان بستهٔ شواهد/مستندات (بدون پیاده‌سازی — §C8 بالا)** · **C9 CLOSED**
> (پیاده‌سازیِ محدودشده از طریق PR #23 MERGED در `7146d5b` ادغام شد؛ **پذیرش/بستن رسمی
> با تصمیم صریح مالک 2026-09-11 پس از ادغام** — §C9 بالا) · **C10 = CLOSED (پذیرش رسمی مالک 2026-09-12؛ فقط دامنهٔ محدودشدهٔ شواهد — §C10 بالا)**. صف داخلی C1..C10 تکمیل شد؛ **Phase 2 همچنان IN PROGRESS** — End Gate تعریف/اجرا نشده.
> برچسب تاریخی «End Gate (۲۶بندی)» **فهرست پذیرش ۲۶بندیِ تعریف‌شده نیست** —
> هیچ تعریف کانونی ۲۶بندی در مخزن یافت نشد؛ قبل از هر گام، تعیین scope/شواهدِ محدودشده لازم است
> (برای C9 i18n: این تعیین انجام شد و دامنه به **دقیقاً دو یافتهٔ A/Lowِ معرفی‌شده توسط خودِ C7**
> محدود ماند — یافته‌های اسکن قبلیِ رشته‌های فارسی/i18n مجوزِ اصلاح انبوه **نشوند** و نشدند؛
> برای C10 Performance: تعیین شواهدِ محدودشده انجام و بستهٔ شواهدِ فقط‌مستندات ثبت شد (§C10 بالا و c10-performance-evidence.md)؛ عملکرد محیط مرجع/تجاری همچنان اندازه‌گیری‌نشده است و هیچ پیاده‌سازی/بهینه‌سازی مجاز نشده است).

## Open blockers

- هیچ.

## نکات اجرایی پابرجا

- هر واحد: CODE+TEST+DOC → atomic commit → push → monitor حداقل CI مربوط.
- هر push: verify remote SHA + ثبت run IDs در همین فایل.
- sandbox بدون PHP/MySQL — تنها اجرای واقعی = GitHub Actions؛ /tmp
  disposable؛ هر rebuild sandbox: `git fetch origin <branch>` + `git reset
  FETCH_HEAD` (mixed) — غیرممنوع و اثبات‌شده.
