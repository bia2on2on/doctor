# Phase 2 — طراحی ترمیم Tenant Context در Jobهای پس‌زمینه، Timezone عملیاتی، پیکربندی SMS و لاگ عملیاتی

> # 🔴 APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED
> ## 🔴 جهت طراحیِ تأییدشده — **هنوز پیاده‌سازی نشده است**
>
> این سند **فقط طراحی/مشخصات** است. **هیچ کد، تست، workflow، schema یا migration‌ای
> بر پایهٔ آن نوشته یا اجرا نشده است.** هیچ بخشی از این سند مجوزِ پیاده‌سازی، ساخت
> Migration `0021`، شروع Phase 2 End Gate، شروع Phase 3 یا Phase 17 نیست.
> وضعیت اجرا = **NOT IMPLEMENTED**؛ هر پیاده‌سازیِ آینده نیازمند تصویب جداگانهٔ مالک،
> زنجیرهٔ RED→GREEN و گیت‌های CI است.

| | |
|---|---|
| **وضعیت** | **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED** (طراحی تأییدشده؛ پیاده‌سازی انجام نشده) |
| **تاریخ ثبت** | 2026-09-12 |
| **فاز مالک** | Owner Roadmap **Phase 2 (Multi-Clinic Core) — IN PROGRESS**؛ زیرمجموعهٔ اقلام به‌تعویق‌افتادهٔ «Jobs/SMS/timezone» که در `phase2-state.md` §C7 و `c6-deferred-boundaries.md` ثبت شده‌اند |
| **سطح** | LEVEL-2 داخلی (طبق `docs/governance/project-phase-taxonomy.md`) — تابعِ Roadmap مالک، فاز مستقل نیست |
| **مبنای معماری** | [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) (AD-04/AD-08/AD-13/AD-14/AD-15 + **AD-17 §۸**) · [`ADR-0016`](../adr/ADR-0016-job-execution.md) · [`background-jobs.md`](background-jobs.md) · [`phase0.5-target-model.md`](phase0.5-target-model.md) |
| **Migration** | **`0021` ساخته/تصویب نشده** — آخرین migration = `0020`. پیش‌نیازهای بازِ قبل از هر migration در §۸ فهرست شده‌اند |
| **End Gate** | **شروع نشده / تعریف نشده / پاس نشده** — این سند هیچ گیت فاز ۲ را باز نمی‌کند |
| **ماهیت** | فقط مستندات (docs only) — بدون تغییر کد محصول، تست، workflow، schema، migration |

---

## ۰. خلاصهٔ مدیریتی (Executive summary — EN)

Phase 2 removed tenant hardcodes from the request path and made REST scope
membership-verified and fail-closed. The **background/job path is the remaining
structural gap**: `cpms_jobs` carries **no tenant columns at all**, the dispatcher
establishes **no tenant context**, and per-Clinic collaborators (`Settings`, SMS
configuration, operational timezone) are still resolved from Clinic-level state
rather than from an explicit, persisted, per-job scope. In a genuine multi-Clinic
installation (AD-17 topology B/C) that is a correctness and isolation risk even
though **no runtime tenant-default violation exists today** (Tenant Tripwire:
`hardcodes: 0`, allowlist `[]`).

This document records the **approved design direction** for closing that gap —
job tenant context, system-wide job classification, Location-based operational
timezone, per-Clinic SMS configuration resolution, tenant attribution of
operational logs, and rules for any future legacy-job backfill — **plus the
design questions that must be answered before Migration `0021` may exist** and
**the RED test specification** that any implementation must satisfy.

**Nothing here is implemented. Nothing here authorizes implementation.**

---

## ۱. وضعیت راستی‌آزمایی‌شدهٔ جاری (Current verified state — بدون ادعای اضافه)

همهٔ موارد زیر در همین working tree (چک‌پوینت `bdb135e9` + سند C10/AD-17) **بازبینی/راستی‌آزمایی** شده‌اند؛
این فهرست «وضعیت موجود» است، نه نقص‌شمارى جدید و نه ادعای عملکرد:

| # | واقعیت جاری | شاهد |
|---|---|---|
| 1 | جدول `cpms_jobs` **هیچ ستون tenant‌ای ندارد** (نه `clinic_id`، نه `organization_id`، نه `location_id`) | `src/Migrations/2026_09_05_0001_initial_schema.php:657`؛ هیچ migration بعدی (تا `0020`) این جدول را تغییر نداده است |
| 2 | `JobQueue::enqueue()` **هیچ scope‌ای را persist نمی‌کند** — فقط `type`, `payload_json`, `status`, `priority`, `attempts`, `max_attempts`, `run_after`, `created_at` | `src/Infrastructure/Queue/JobQueue.php:28` |
| 3 | `JobsDispatcher::tick()` **هیچ context‌ی برقرار نمی‌کند**؛ Handler فقط `array $payload` می‌گیرد؛ نوع ناشناخته ⇒ `RuntimeException('NO_HANDLER:<type>')` ⇒ Job fail می‌شود | `src/Application/Jobs/JobsDispatcher.php:38` |
| 4 | `ScopeContext` یک holder **ایستای per-process** است با `set/tryGet/clear`؛ در production فقط در مسیر Job/Export و REST Trusted context ست می‌شود | `src/Application/Scope/ScopeContext.php` · `src/Application/Reports/ExportService.php:414` · `src/Rest/RestClinicContext.php:109` |
| 5 | نبودِ Scope صریح ⇒ `App::scope()` به `SystemClinicResolver` می‌رسد که **فقط در حالت «دقیقاً یک Clinic»** مقدار برمی‌گرداند و در غیر آن `CLINIC_SCOPE_REQUIRED` می‌دهد (fail-closed؛ «تنها Clinic»، نه «اولین Clinic») | `src/Bootstrap/App.php:858` · `src/Application/Scope/SystemClinicResolver.php` |
| 6 | `Settings` با `int $clinicId` **صریح** ساخته می‌شود و cache‌اش per-clinic کلید می‌خورد (`self::$cache[$clinicId]`)؛ هیچ default-parameter tenant‌ای باقی نمانده است | `src/Settings/Settings.php:149`, `:125` |
| 7 | Timezone عملیاتیِ امروز از **`cpms_clinics.timezone`** خوانده می‌شود (`Settings::clinicTimezone()`) با fallback ثابت `Asia/Tehran`؛ مصرف‌کننده‌ها: `ApptReminderHandler:106`، `FollowUpReminderHandler:105`، `NotificationService:243` | `src/Settings/Settings.php:282` |
| 8 | `cpms_locations.timezone` **NOT NULL** است (Migration `0011`) ولی **هیچ مصرف‌کنندهٔ عملیاتی runtime ندارد** — یعنی Location هنوز در کد منبع حقیقت زمان نیست (همان مرزِ ثبت‌شدهٔ به‌تعویق‌افتادهٔ C8) | `src/Migrations/2026_09_09_0011_locations.php:10`؛ grep: تنها خواننده‌های `cpms_locations` = `PrimaryLocationResolver`، `TrustedClinicEstablisher`، `MembershipRepository` (هیچ‌کدام `timezone` نمی‌خوانند) |
| 9 | پیکربندی SMS از `Settings` همان Clinic خوانده می‌شود (`sms.provider` و …) ⇒ **درستیِ آن کاملاً تابعِ درستیِ `clinicId`ِ همان instance است** | `src/Application/Notifications/SmsService.php:258`, `:298`, `:380`, `:638` |
| 10 | جدول `cpms_operational_logs` **ستون tenant ندارد** (`level`, `message`, `context_json`, `request_id`, `created_at`) و `OpLogger` هیچ clinic‌ای را ثبت نمی‌کند | `src/Migrations/2026_09_05_0001_initial_schema.php:701` · `src/Infrastructure/Logging/OpLogger.php:46` |
| 11 | **هیچ خواننده/UI/API‌ای برای `cpms_operational_logs` وجود ندارد** — تنها مصرف‌کننده‌ها نوشتن (`OpLogger`) و حذف retention (`OpLogCleanupHandler`) هستند. بنابراین **امروز هیچ مسیر خواندنِ cross-clinic روی این جدول وجود ندارد** و این سند **هیچ نشتیِ Health UI را ادعا نمی‌کند** | grep سراسری `src/`: فقط `OpLogger::write` (insert) و `OpLogCleanupHandler` (DELETE بر پایهٔ `created_at`) |
| 12 | برخی Jobها امروز **جاروی سراسریِ نصب** هستند و scope هر ردیف را از آبجکت مرجع مشتق می‌کنند (مثلاً `slots.generate` روی همهٔ clinicianها پیمایش می‌کند و `clinic_id`/`location_id` را از ردیف `cpms_schedule` / `PrimaryLocationResolver` می‌گیرد) | `src/Application/Jobs/SlotsGenerateHandler.php:32-66` |
| 14 | تنها «نمایشِ» مرتبط با صف، فهرستِ ۵ Jobِ `failed` در صفحهٔ فنی wp-admin است — query **بدون هیچ بُعد tenant** (چون `cpms_jobs` ستون tenant ندارد) و پشتِ capability `cpms_config` + nonce | `src/Admin/SettingsAdmin.php:37`, `:45-48` |
| 13 | Tenant Tripwire روی runtime فعال: **`hardcodes: 0`، `allowlist_entries: 0`، `suspects: 1`** (مورد suspect = `SystemClinicResolver.php:52`، sanction‌شده در `c6-census.md`)؛ ۵۹ self-test سبز | `python3 bin/tenant-tripwire.py --allowlist bin/tenant-tripwire-allowlist.json` |

> **نتیجهٔ صادقانه:** مسئلهٔ جاری «hardcode tenant» نیست (آن صفر است)؛ مسئلهٔ ساختاریِ
> باقی‌مانده **نبودِ context صریحِ persist‌شده برای Jobهای tenant-scoped** و **مشتق‌شدن
> timezone/SMS از سطح Clinic به‌جای سطح عملیاتیِ صحیح** است. این سند همان را طراحی می‌کند.

---

## ۲. A — Tenant Context برای Jobهای پس‌زمینه (Background Job Tenant Context)

**قاعدهٔ بنیادی:** هر Job که روی دادهٔ یک Clinic اثر می‌گذارد یا از پیکربندی یک Clinic
می‌خواند (**tenant-scoped**) باید **context صریح، اعتبارسنجی‌شده و persist‌شده** داشته باشد
که در **زمان dispatch** برقرار شده و در **زمان اجرا** بدون هیچ حدس/ارث‌برداری از محیط مصرف شود.

### A-1. قواعد الزام‌آور

| # | قاعده |
|---|---|
| **A-1.1** | Jobهای tenant-scoped **باید** Clinic خود را به‌صورت **صریح و persist‌شده** همراه Job داشته باشند (ستون اختصاصی روی صف، یا payload/context بادوامِ معادل — انتخاب مکانیزم = §۸-۴/§۸-۵). «از محیط اجرا حدس بزن» ممنوع است |
| **A-1.2** | **Organization از Clinic مشتق می‌شود** (`clinics.organization_id` — هر Clinic دقیقاً یک Organization دارد). `organization_id` **روی Job تکرار/ذخیره نمی‌شود مگر نیازِ اثبات‌شده** (مثلاً Job واقعاً Org-level است یا نیاز به query بدون join دارد)؛ تکرار بدون نیاز = منبع واگرایی (denormalization بدون توجیه) |
| **A-1.3** | **Location به‌طور معمول از آبجکت عملیاتیِ مرجع مشتق می‌شود** (نوبت/ویزیت/اسلات/شیفت → `location_id` خودشان). **`location_id` به‌طور پیش‌فرض به هر Job اضافه نمی‌شود**؛ فقط وقتی لازم است که (الف) رفتار Job به Location وابسته است (مثل timezone یادآوری) و (ب) آبجکت مرجع در دسترس/قابل‌اتکا نیست |
| **A-1.4** | **Dispatcher مسئول برقراری context معتبرِ Job است** — خواندن scope از Job، **اعتبارسنجی** آن (وجود Clinic، فعال بودن، تعلق به Organization معتبر) و سپس اجرای Handler داخل آن context. Handlerها **context را حدس نمی‌زنند** و از `App::scope()`ِ بی‌صاحب استفاده نمی‌کنند |
| **A-1.5** | **ایزولاسیون per-job:** context در آغاز هر Job ست و در **`finally`** پاک می‌شود (الگوی موجود و تأییدشده: `ExportService.php:414-419` و `App::replaceExplicitScope`). هیچ Jobی context خود را به Job بعدی، به request بعدی یا به worker بعدی **ارث نمی‌دهد** |
| **A-1.6** | **بدون وابستگی به WP کاربر جاری یا REST request.** در cron/CLI هیچ `wp_get_current_user()`، هیچ nonce، هیچ header و هیچ «کاربر قبلی» وجود ندارد؛ هر مسیری که به آن‌ها تکیه کند در background **باطل** است |
| **A-1.7** | **اجرای متوالیِ Jobهای Clinicهای مختلف نباید Settings/context را به اشتراک بگذارد.** هر تغییر scope باید cache/instance‌های مشتق را باطل کند (`Settings::flushCache()` + بازسازی instance — الگوی موجود `App::resetScope()`/`replaceExplicitScope()`). ترتیب `A → B → A` باید به همان نتایجِ `A` تنها برسد |
| **A-1.8** | **Retry باید scope را حفظ کند.** تلاش مجددِ یک Job همان Clinic (و همان Location مشتق‌شدهٔ معتبر) را می‌بیند؛ scope در retry **هرگز** باز-حدس‌زده یا «به‌روز» نمی‌شود مگر طبق قاعدهٔ صریحِ §۷ (F) |
| **A-1.9** | **Fail-closed:** scope مخدوش/ناقص/نامعتبر (Clinic ناموجود، غیرفعال، `clinic_id ≤ 0`، نوعِ نامعلوم) ⇒ Job **اجرا نمی‌شود**؛ با خطای صریح + Operational Log شکست می‌خورد. fallback خاموش به «تنها Clinic»/«اولین Clinic»/«Clinic کاربر جاری» **ممنوع** است |
| **A-1.10** | **نوع Job ناشناخته ⇒ fail-closed.** رفتار امروز (`NO_HANDLER:<type>` ⇒ fail) حفظ می‌شود و **تقویت** می‌شود: هر نوع ثبت‌شده باید **طبقهٔ scope** صریح داشته باشد (§۳)؛ نوعِ بدون طبقه = reject در زمان ثبت/اجرا، نه حدس در زمان اجرا |
| **A-1.11** | **مالکیت tenant هرگز حدس زده نمی‌شود** — نه از «تعداد Clinicهای نصب»، نه از «آخرین Job»، نه از «ردیف اول»، نه از «تنظیمات سراسری». تنها منبع مجاز: context صریحِ persist‌شدهٔ خودِ Job یا آبجکت مرجعِ معتبرش |
| **A-1.12** | **دادهٔ tenant روی Job = دادهٔ عملیاتی، نه metadata تزئینی.** هر مقدار scope که persist می‌شود باید در اجرا **مصرف** شود؛ نوشتن `clinic_id` و بعد خواندنِ مسیرِ دیگری از داده = ضدالگو (توهمِ ایزولاسیون) |

### A-2. طبقه‌بندی انواع Job (الزامی برای هر نوع)

هر نوع Job باید **دقیقاً یکی** از این طبقه‌ها را به‌صورت ثبت‌شده داشته باشد:

| طبقه | تعریف | نمونهٔ مفهومی | الزام |
|---|---|---|---|
| **T — Tenant-scoped** | روی داده/پیکربندیِ **یک Clinic مشخص** عمل می‌کند | `sms.send`، `report.export`، `appt.reminder` (به‌ازای Clinic) | context صریح persist‌شده + اعتبارسنجی در dispatch + ایزولاسیون per-job (A-1.1..A-1.12) |
| **S — System-wide (installation-level)** | واقعاً به **کل نصب** تعلق دارد و هیچ Clinic مالکش نیست | (نامزد: `backup.run`، `license.refresh`) — **هنوز قطعی نیست** (§۳ و §۸-۱) | ثبت **صریح + توجیه‌شده** در registry؛ **بدون** `clinic_id = 0` و **بدون** Clinic مصنوعی |
| **W — Installation-wide sweep با scope مشتق per-row** | جاروی سراسری که scope **هر ردیف** را از آبجکت مرجعِ همان ردیف می‌گیرد | `slots.generate` (وضعیت امروز)، `holds.expire`، cleanup‌های مبتنی بر ردیف | باید صریحاً طبقه‌بندی و **ثبت** شود؛ ردیف بدون آبجکت مرجعِ قابل‌انتساب ⇒ skip + log (نه حدس) |

> **قاعدهٔ سرسخت:** طبقهٔ scope یک ویژگی **ثبت‌شدهٔ نوع Job** است، نه نتیجهٔ بازرسیِ زمان اجرا.
> «الان فقط یک Clinic هست پس فرقی ندارد» **توجیه نیست** — AD-17 توپولوژی B/C را الزام دائمی محصول می‌داند.

---

## ۳. B — Jobهای System-Wide (هنوز **قطعی نشده**)

**در این سند هیچ allowlist گسترده‌ای نهایی نمی‌شود.** هر نوع که ادعای system-wide بودن دارد
باید **یکی‌یکی، صریحاً ثبت و توجیه** شود؛ «چون همه‌جا لازم است» توجیه نیست.

### B-1. اقلام **عمداً باز (UNRESOLVED)**

| نوع | مفهوم | چرا باز است |
|---|---|---|
| `backup.run` | **مفهوماً installation-wide** است (بکاپ از کل DB/storage نصب) | **دامنهٔ پیکربندی‌اش حل‌نشده است:** کلیدهای رفتاری آن امروز از مسیر `Settings` (جدول `cpms_settings` که `clinic_id` NOT NULL دارد) خوانده می‌شوند ⇒ «پیکربندیِ نصب» در مدلِ دادهٔ امروز **جایِ صریحی ندارد**. تا وقتی مدلِ تنظیماتِ سطح نصب تعیین نشود (§۸-۱)، طبقهٔ نهایی آن **S** یا **T-per-Clinic** یا **S + پیکربندی نصب** مشخص نیست |
| `license.refresh` | **مفهوماً installation-wide** است (مجوزِ نصب/پلاگین) | همان مشکل: منبع پیکربندی/وضعیتِ مجوز امروز در مدلِ Clinic-scoped نشسته است ⇒ **دامنهٔ پیکربندی حل‌نشده** (§۸-۱) |
| `cleanup.oplog` | پاک‌سازی `cpms_operational_logs` بر پایهٔ `created_at` | طبقه‌بندی‌اش **به semanticsِ retention وابسته است**: اگر retention سیاستِ **نصب** باشد ⇒ S؛ اگر سیاستِ **هر Clinic** باشد (و/یا اگر لاگ‌ها tenant-attributed شوند — §۶) ⇒ T یا W. تا تعیینِ مالکِ retention (§۸-۲) **باز می‌ماند** |

### B-2. ممنوعیت‌های مطلق (بدون استثنا)

- ⛔ **`clinic_id = 0` ممنوع** — نه به‌عنوان «system»، نه به‌عنوان «نامعلوم»، نه به‌عنوان sentinel. صفر یک شناسهٔ tenant جعلی است و هر query/join/FK/authorization بر پایهٔ آن یا بی‌معناست یا ناامن (هم‌خانوادهٔ AD-13: `clinic_id = 1` ممنوع ⇒ `clinic_id = 0` هم ممنوع).
- ⛔ **Clinic مصنوعی/سنتتیکِ «System» ممنوع** — ساخت ردیف `cpms_clinics` با نامِ «System»/«Global»/«HQ» برای فرار از طبقه‌بندی، **ممنوع** است. چنین ردیفی Organization/Location/Membership مصنوعی می‌سازد، در شمارشِ «تعداد Clinicها» (و بنابراین در fail-closed بودنِ `SystemClinicResolver`) اختلال ایجاد می‌کند و مرز مجوزدهی را آلوده می‌کند.
- ⛔ **NULL به‌عنوان «system» به‌صورت ضمنی ممنوع** — اگر جایی مقدار tenant برای رویدادِ واقعاً سیستمی NULL است، باید **معنای صریحِ ثبت‌شده** داشته باشد (مانند قاعدهٔ موجودِ `cpms_audit_logs.clinic_id` NULL-able در Migration `0016`: «NULL = سیستمی، نه ۱») و **هر خواننده** باید آن را صریحاً مدیریت کند؛ NULL هرگز به «Clinic پیش‌فرض» نگاشت نمی‌شود.
- ⛔ **allowlist ضمنی/سراسری ممنوع** — هیچ لیستِ «این نوع‌ها tenant نمی‌خواهند» بدون ثبتِ تک‌تکِ موارد + توجیه + معیار پذیرش معتبر نیست.

---

## ۴. C — Timezone عملیاتی = Location

**منبع حقیقتِ timezone عملیاتی، `Location` است** (`cpms_locations.timezone` — NOT NULL، از Migration `0011`).

| # | قاعده |
|---|---|
| **C-1** | اگر Job/رویداد یک **آبجکت عملیاتیِ صریح** دارد (نوبت، ویزیت، اسلات، شیفت، یادآوریِ یک نوبت مشخص) ⇒ **timezoneِ Locationِ همان آبجکت** برنده است — نه timezoneِ Clinic، نه تنظیم کاربر، نه timezone سرور |
| **C-2** | **یک Clinic می‌تواند چند Location با timezoneهای متفاوت داشته باشد.** هر طراحی که «یک timezone به‌ازای Clinic» را فرض کند، توپولوژی B/C (AD-17 §۸) را نقض می‌کند |
| **C-3** | **Timezoneِ Clinic هرگز timezoneِ Locationِ یک آبجکت را override نمی‌کند.** Clinic-level فقط **default/legacy** است (§C-6) |
| **C-4** | **Fail-closed:** نبودِ Locationِ لازم، timezone نامعتبر/غیر-IANA، یا Locationِ غیرمتعلق به همان Clinic ⇒ خطای صریح + توقف عملیات (مثلاً یادآوری ارسال نمی‌شود) — **نه** fallback خاموش به `Asia/Tehran` |
| **C-5** | **`Asia/Tehran` فقط یک مقدار IANA کنترل‌شدهٔ سازگاری/پیش‌فرض است** (پیش‌فرض seeding در Migration `0011`؛ whitelist ویزارد). **هرگز نباید نبودِ scope عملیاتی را بپوشاند** — یعنی هیچ مسیری مجاز نیست «چون `Asia/Tehran` هست، ادامه بده» بگوید وقتی Location/آبجکت مرجعِ لازم مفقود است |
| **C-6** | **Timezoneِ Clinic = legacy/default سازگاری** است و **تصمیمِ deprecation آن موکول به آینده است** (این سند آن را حذف یا نهایی نمی‌کند؛ §۸-۵/§۸-۶). تا آن زمان: مصرف‌کنندهٔ عملیاتیِ `cpms_clinics.timezone` باید صریحاً به‌عنوان «مسیر سازگاری» مستند بماند و مسیر Location جایگزینش شود |
| **C-7** | **ذخیرهٔ زمان = UTC** (قاعدهٔ موجود ADR-0013/`Settings`): timezone فقط برای **محاسبهٔ زمان‌بندی/نمایش** است؛ هیچ ستونِ زمانی به timezone محلی ذخیره نمی‌شود |
| **C-8** | **پایداری در retry:** اگر Locationِ مشتق‌شده از آبجکت مرجع می‌تواند بین enqueue و retry **تغییر کند**، باید صریحاً تصمیم گرفته شود که retry با **Locationِ زمانِ enqueue** اجرا شود یا **Locationِ جاریِ آبجکت** — این یک **سوال بازِ ثبت‌شده** است (§۸-۶)، نه یک انتخاب ضمنی |

---

## ۵. D — پیکربندی SMS (Per-Clinic Configuration Resolution)

**اصل:** یک SMS متعلق به **Clinic B** باید **همیشه** پیکربندیِ **Clinic B** را حل کند — مستقل از هر چیز دیگر.

### D-1. بی‌اثر بودنِ منابعِ محیطی (الزامی)

رزولوشن پیکربندی SMS **نباید** به هیچ‌یک از این‌ها وابسته باشد:

- ❌ WP کاربرِ جاری (`wp_get_current_user()` / نقش سراسری WP)؛
- ❌ contextِ REST (header/nonce/route)؛
- ❌ Job قبلی یا scope باقی‌مانده از اجرای قبل؛
- ❌ **ترتیب اجرای Jobها** (`A → B → A` باید برای B همان نتیجهٔ B-تنها را بدهد)؛
- ❌ **instanceِ `Settings` متعلق به Clinic دیگر** (حتی اگر در همان process زنده باشد).

### D-2. انتزاعِ ترجیحی

> **ترجیح: یک abstraction تمیزِ سطح اپلیکیشن — `SettingsFactory` / `SmsConfigResolver` یا معادل —
> به‌جای ساخت‌وپراکندۀ ad-hoc `Settings` در لایه‌های مختلف.**

- مسئولیت: «Clinic → پیکربندیِ معتبرِ SMS (provider، پارامترها، قالب/زبان، محدودیت‌ها)» به‌صورت **صریح، قابل تست و یکتا**.
- **Secret/API key هرگز در `cpms_settings` ذخیره نمی‌شود** (قاعدهٔ موجودِ `Settings` — فقط wp-config/env). این قاعده با این طراحی **تغییر نمی‌کند**؛ بنابراین «پیکربندیِ per-Clinic» = انتخاب provider/پارامترها، نه نگهداری credential در DB.
- کش: اگر کش‌ می‌شود، **حتماً per-Clinic کلید بخورد** و با تغییر scope باطل شود (الگوی موجود `Settings::$cache[$clinicId]` حفظ/تعمیم می‌یابد).
- **نامِ دقیقِ کلاس/اینترفیس در این سند نهایی نمی‌شود** — معیار، «انتزاعِ یکتا و صریح» است، نه اسم.

### D-3. رفتارِ Fail-Closed

پیکربندیِ ناموجود/نامعتبر/متعلق به Clinic دیگر ⇒ **ارسال انجام نمی‌شود** + خطای صریح + Operational Log (با انتساب tenant — §۶). fallback به providerِ پیش‌فرضِ «نصب» یا به پیکربندیِ Clinic دیگر **ممنوع** است. (providerِ `log` یک **default سازگاریِ صریح** است، نه یک fallbackِ بی‌صدا برای Clinicِ اشتباه.)

---

## ۶. E — لاگ‌های عملیاتی (Operational Logs)

| # | قاعده |
|---|---|
| **E-1** | **رویدادهای عملیاتیِ tenant-scoped باید انتساب tenant داشته باشند** (Clinic؛ و در صورت معناداری، Organization مشتق‌شده). رویدادهای واقعاً سیستمی باید **صریحاً** به‌عنوان سیستمی نشانه‌گذاری شوند (نه با `clinic_id = 0`، نه با Clinic جعلی، نه با حدس) |
| **E-2** | ⛔ **ستون `clinic_id` به‌تنهایی authorization نیست.** وجود انتساب، فقط **داده** است؛ **هر** query، UI و API که لاگ عملیاتی می‌خواند باید **مستقلاً** scope مجاز را اعمال کند (membership/رابطهٔ تأییدشده — همان قاعدهٔ «Context transport vs trust» در `project-current-state.md` §D) |
| **E-3** | **این سند هیچ نشتیِ موجود را ادعا نمی‌کند.** راستی‌آزماییِ امروز: `cpms_operational_logs` **هیچ خواننده/UI/API‌ای ندارد** (فقط `OpLogger` می‌نویسد و `OpLogCleanupHandler` بر پایهٔ retention حذف می‌کند) ⇒ **مسیر اثبات‌شدهٔ نشتِ cross-clinic از این جدول امروز وجود ندارد**. ادعای «Health UI دادهٔ Clinic دیگر را نشان می‌دهد» **تا وقتی از طریق خودِ query + مسیر authorization اثبات نشود، ثبت نمی‌شود**. **مشاهدهٔ ثبت‌شده (بدون حکم نقص):** صفحهٔ فنی wp-admin فهرستِ ۵ Jobِ `failed` را **بدون بُعد tenant** نشان می‌دهد (`SettingsAdmin.php:45-48`) چون `cpms_jobs` امروز ستون tenant ندارد و دسترسی‌اش با `cpms_config` گیت شده است — **دیدِ فنیِ سطح نصب**. وقتی انتسابِ tenant به Jobها/لاگ‌ها اضافه شود، **باید صریحاً تعیین شود** که این دید «فنیِ سطح نصب» می‌ماند (با توجیه ثبت‌شده) یا tenant-scoped می‌شود؛ این determination **انجام نشده** و در §۸-۳/§۸-۴ باز است |
| **E-4** | وقتی خواننده/UI/API معرفی شد: (الف) filter tenant **در خودِ query** اجباری است (نه فیلتر در لایهٔ نمایش)؛ (ب) نبودِ scope صریح ⇒ fail-closed؛ (پ) دسترسی سطح نصب (technical admin) باید **صریح و مجزا** تعریف شود — «wp-admin می‌بیند» مجوزِ ضمنی نیست (مرزِ نهاییِ نقش/قابلیت = **Phase 3**، که شروع نشده) |
| **E-5** | **PII ممنوع** (قاعدهٔ موجودِ `OpLogger::sanitize` حفظ می‌شود): پیام/context لاگ عملیاتی نباید موبایل/کد/محتوای پیام/شناسهٔ ملی داشته باشد؛ انتساب tenant با **شناسه‌ها** انجام می‌شود، نه با محتوا |
| **E-6** | جزئیات schema/ایندکس (نام/نوع ستون، NULL-able بودن، ایندکس‌های `(clinic_id, created_at)` / `(level, clinic_id, created_at)`، retention و partitioning) **در این سند نهایی نمی‌شود** — **سوال بازِ §۸-۴** است و هر تغییر، نیازمند migration مصوب (نه `0021` در این تسک) است |

---

## ۷. F — Jobهای Legacy (قواعد migration/backfill آینده)

این قواعد **برای آینده** است؛ **هیچ backfill/migration‌ای در این تسک انجام نشده و `0021` ساخته نشده است.**

| # | قاعده |
|---|---|
| **F-1** | **فقط provenance قطعی (deterministic).** انتساب Clinic به یک ردیف legacy باید از **شاهدِ قابلِ اثبات** بیاید، نه از استنتاج آماری |
| **F-2** | ⛔ **«تعداد Clinicهای نصب الان یکی است» به‌تنهایی provenance کافی نیست.** حتی در نصب تک‌کلینیکی، انتساب باید صریح ثبت/تأیید شود (و در نصب چندکلینیکی هرگز مجاز نیست) — این دقیقاً همان قیدِ محافظِ موجود در Migration `0020` است که در نصب چندکلینیکی **abort** می‌کند |
| **F-3** | **منابعِ امنِ provenance:** (الف) **آبجکت تجاریِ مرجعِ خودِ Job** (مثلاً `message_id` → `cpms_sms_messages.clinic_id`؛ `appointment_id` → `cpms_appointments.clinic_id`؛ `export_id`/payload صریح)؛ (ب) **payload/context بادوام و صریحِ** موجود در `payload_json` که Clinic در آن ثبت شده باشد. هر دو باید **قابلِ ردیابی و قابلِ آزمون** باشند |
| **F-4** | ⛔ **ردیف‌های tenant-scopedِ مبهمِ `pending`/`processing` ⇒ fail-closed.** اگر provenance قطعی نیست، Job **اجرا نمی‌شود** (reject/hold + log صریح) — **هرگز** به یک Clinic «نسبت داده» نمی‌شود تا اجرا شود |
| **F-5** | ⛔ **Clinic ID حدسی تخصیص داده نمی‌شود** — نه `1`، نه «اولین Clinic»، نه «تنها Clinic» به‌عنوان حدسِ بی‌صدا، نه `0`، نه Clinic مصنوعی |
| **F-6** | **ردیف‌های `success`/`failed`ِ تاریخی (تکمیل‌شده)** می‌توانند — **در صورت ایمن بودن** — scope تاریخیِ نامعلوم را **به‌عنوان نامعلوم حفظ کنند** (بدون ساختِ دادهٔ جعلی و بدون بازنویسی تاریخچهٔ عملیاتی). این یعنی «نمی‌دانستیم» یک وضعیت مجازِ ثبت‌شده است، نه یک نقصِ پنهان |
| **F-7** | **Migration باید idempotent و recoverable باشد** (الگوی موجود پروژه: `SHOW COLUMNS`/`SHOW INDEX` + preflight بدون تغییر داده + abort صریح + `down()` مستند). اجرای مجدد پس از شکست باید امن باشد و هیچ گامِ مخربی بدون preflight انجام نشود |
| **F-8** | **بدون drop/recreate و بدون بازنویسی تاریخچهٔ Jobها**؛ versioned-forward (قاعدهٔ موجودِ `phase0.5-target-model.md` بخش د) |
| **F-9** | **ترتیب:** ابتدا **طبقه‌بندی انواع Job** (§۲-A-2 و §۳) و سپس **اندازه‌گیری واقعیِ دادهٔ legacy** (§۸-۵) و **بعد** طراحی migration. نوشتن migration پیش از این دو = **ممنوع** |

---

## ۸. سوالاتِ بازِ طراحی که **پیش از Migration `0021`** باید بسته شوند (OPEN — UNSOLVED)

> ⛔ **Migration `0021` در این تسک ساخته نشده و تا بسته‌شدنِ صریحِ مواردِ زیر توسط مالک، مجاز نیست.**
> آخرین migration = **`0020`** (`2026_09_09_0020_idempotency_clinic_scope.php`).

| # | سوالِ باز | چرا blocker است | وضعیت |
|---|---|---|---|
| **۸-۱** | **مدلِ تنظیماتِ سطح نصب (installation-level settings) برای `backup.run` و `license.refresh`** — کجا ذخیره می‌شود؟ (`cpms_settings` امروز `clinic_id` NOT NULL دارد؛ گزینهٔ «Clinic مصنوعی» ⛔ ممنوع است؛ گزینهٔ `clinic_id = 0` ⛔ ممنوع است) | بدون آن، طبقهٔ scope این دو Job و منبع پیکربندی‌شان **قابل تعیین نیست** ⇒ هر migration برای Jobها ناقص/زودرس است | **OPEN — UNSOLVED** |
| **۸-۲** | **semanticsِ retention برای `cleanup.oplog`** — retention سیاستِ **نصب** است یا **هر Clinic**؟ (و آیا اساساً tenant-attributed می‌شود؟) | طبقهٔ scope این Job (§۳-B-1) و اینکه آیا ستون tenant روی لاگ‌ها معنادار است، به آن وابسته است | **OPEN — UNSOLVED** |
| **۸-۳** | **فهرست نهاییِ واقعاً system-wide** — تک‌تکِ انواع باید صریحاً ثبت + توجیه شوند (امروز: هیچ allowlist نهایی وجود ندارد) | بدون آن، پیاده‌سازی مجبور به حدس می‌شود؛ حدس = نقض A-1.11 | **OPEN — UNSOLVED** |
| **۸-۴** | **جزئیات دقیق schema/ایندکس `operational_logs`** (نام/نوع/NULL-able بودن ستون انتساب، ایندکس‌ها، اثر بر hot-path نوشتن، retention/partitioning) | هر انتخاب، یک migration واقعی است؛ این سند **عمداً** آن را نهایی نکرده است (§۶-E-6) | **OPEN — UNSOLVED** |
| **۸-۵** | **نیازهای واقعیِ گذارِ Job/schemaِ legacy — اندازه‌گیری‌شده** (چند ردیف `cpms_jobs` در نصب‌های واقعی وجود دارد؟ چند تا `pending`/`processing`؟ چند تا payloadِ قابلِ استناد؟) | قواعد §۷ بدون دادهٔ اندازه‌گیری‌شده، migrationِ امن تولید نمی‌کنند؛ «حدسِ حجم» ممنوع است | **OPEN — NOT MEASURED** |
| **۸-۶** | **آیا آبجکت عملیاتیِ مرجعِ یک Job می‌تواند Location را به‌گونه‌ای تغییر دهد که Locationِ مشتق‌شده در retry ناپایدار شود؟** (مثلاً نوبت به Location دیگر منتقل شود؛ شیفت/برنامهٔ پزشک جابه‌جا شود) | تعیین می‌کند retry با **Locationِ enqueue** اجرا شود یا **Locationِ جاریِ آبجکت** (C-8)؛ انتخابِ ضمنی = رفتار نامعین در یادآوری‌ها/زمان‌بندی | **OPEN — UNSOLVED** |

**قاعدهٔ بستن:** هر سوالِ بالا فقط با **تصمیم صریح مالک/معمار + ثبت در همین سند (و در صورت نیاز ADR مربوطه)** بسته می‌شود. بسته‌شدنِ یک سوال، مجوزِ پیاده‌سازیِ کلِ این سند نیست.

---

## ۹. مشخصاتِ تستِ **RED** (فقط ثبتِ نیازمندی — **پیاده‌سازی نشده**)

> ⛔ **هیچ تستی در این تسک نوشته/اجرا نشده است.** موارد زیر **پوششِ اجراییِ لازمِ آینده** است که هر
> پیاده‌سازیِ این طراحی **باید** ابتدا آن را **RED** و سپس GREEN کند (قاعدهٔ موجود پروژه:
> زنجیرهٔ RED→GREEN با run-id؛ بدون تضعیف تست).
> ✅ **همهٔ تست‌های موجود حفظ می‌شوند** — این فهرست جایگزین/حذف هیچ تست فعلی نیست و هیچ تستی
> skip/weaken/quarantine نمی‌شود.

| # | تست لازم (آینده) | انتظارِ کلیدی (assert) |
|---|---|---|
| **RT-1** | **رگرسیون تک‌Org/تک‑Clinic/تک‑Location** | توپولوژی A (AD-17) بدون تغییر رفتار کار می‌کند؛ scope به‌طور **صریح** resolve می‌شود (نه «ردیف اول»)؛ هیچ fallback خاموشی رخ نمی‌دهد |
| **RT-2** | **یک Clinic با دو Location و timezone متفاوت** | هر Job/یادآوری از timezoneِ **Locationِ آبجکتِ خودش** استفاده می‌کند؛ نتایجِ دو Location با هم **قابلِ تفکیک** است (C-1..C-4) |
| **RT-3** | **دو Clinic با پیکربندی/credential‌های متفاوتِ SMS** | پیامِ Clinic B **همیشه** با پیکربندی B ارسال می‌شود؛ هیچ‌گاه با پیکربندی A ارسال/ثبت نمی‌شود (D-1..D-3) |
| **RT-4** | **اجرای cron/background بدون WP کاربر** | در نبودِ `wp_get_current_user()`/REST context، Jobهای tenant-scoped **فقط** از contextِ persist‌شدهٔ خودشان کار می‌کنند؛ نبودِ context ⇒ **fail-closed** (A-1.6, A-1.9) |
| **RT-5** | **کاربر با membership چندگانه نباید بر scopeِ Job اثر بگذارد** | عضویت چند Clinicِ یک کاربر، scopeِ هیچ Jobی را **تغییر/مبهم** نمی‌کند؛ membership ≠ contextِ اجرا (A-1.6 + AD-17 قاعدهٔ ۴) |
| **RT-6** | **اجرای متوالیِ `A → B → A` بدون نشتِ Settings** | هر سه گام نتایجِ درستِ Clinicِ خودش را می‌دهد؛ هیچ Settings/context‌ای از گام قبل **باقی نمی‌ماند** (A-1.5, A-1.7, D-1) |
| **RT-7** | **Semanticsِ retry حفظ می‌شود** | تلاشِ مجدد همان Clinic (و طبق تصمیمِ §۸-۶ همان Location) را می‌بیند؛ scope در retry **باز-حدس زده نمی‌شود** (A-1.8) |
| **RT-8** | **نبودِ tenant context ⇒ fail-closed** | Job اجرا نمی‌شود؛ خطای صریح + Operational Log؛ **هیچ** داده‌ای نوشته/ارسال نمی‌شود؛ هیچ fallback به «تنها/اولین Clinic» رخ نمی‌دهد (A-1.9..A-1.11) |
| **RT-9** | **یادآوری‌ها از timezoneِ Locationِ عملیاتی استفاده می‌کنند** | زمان‌بندیِ ارسالِ یادآوری بر پایهٔ timezoneِ **Locationِ نوبت** محاسبه می‌شود (نه Clinic، نه `Asia/Tehran` پیش‌فرض) — با دو نوبت در دو Locationِ متفاوت (C-1, C-5) |
| **RT-10** | **Jobِ legacyِ مبهم هرگز به یک Clinic «حدس» زده نمی‌شود** | ردیفِ بدون provenance قطعی ⇒ **اجرا نمی‌شود** (hold/reject + log)؛ هیچ `clinic_id` حدسی (شامل `0`/`1`/«تنها Clinic») نوشته نمی‌شود (F-2, F-4, F-5) |
| **RT-11** | **انتسابِ tenant در لاگِ عملیاتی** | رویدادِ tenant-scoped با انتسابِ درستِ Clinic ثبت می‌شود؛ رویدادِ سیستمی **صریحاً** سیستمی است (نه `0`، نه Clinic جعلی)؛ **و** هر خوانندهٔ آینده به‌طور مستقل scope را enforce می‌کند (E-1, E-2) |
| **RT-12** | **هر نوع Jobِ ثبت‌شده طبقهٔ scope صریح دارد** | گارْدِ ثبت/اجرا: نوعِ بدون طبقهٔ scope ⇒ reject (نه حدس در زمان اجرا)؛ نوعِ ناشناخته ⇒ fail-closed (A-1.10, §۲-A-2) |

**یادداشتِ اجراییِ آینده (نه اکنون):** این موارد عمدتاً **Integration** هستند (DB واقعی + Migration +
صف واقعی) و باید با **ردیف‌های واقعیِ Clinic/Location دوم** ساخته شوند — **mock-isolation ممنوع**
(قاعدهٔ موجودِ تست‌پلن فاز ۲: fixture ردیف واقعی دوم، نه mock).

---

## ۱۰. مرزها و ممنوعیت‌های این سند (Non-goals)

- ⛔ **پیاده‌سازی کد محصول** — انجام نشد و مجاز نیست.
- ⛔ **نوشتن/اجرای تست** — فقط مشخصات (§۹)؛ هیچ تستی اضافه/تغییر/حذف نشد.
- ⛔ **تغییر workflow/CI** — هیچ تغییری در `.github/workflows` داده نشد؛ هیچ گیتی تضعیف نشد.
- ⛔ **تغییر schema / ساخت Migration `0021`** — آخرین migration `0020` باقی است.
- ⛔ **شروع یا پاس‌کردن Phase 2 End Gate** — End Gate **تعریف/شروع نشده** است (برچسب تاریخی «۲۶بندی» فهرست پذیرش نیست).
- ⛔ **شروع Phase 3 (Role & Access Control) / Phase 4 (Master Data) / Phase 17 (Performance)** — همه **NOT STARTED**.
- ⛔ **هرگونه ادعای عملکرد/NFR/مقیاس‌پذیری/آمادگی تجاری** — C10 **فقط** در دامنهٔ محدودشدهٔ بازبینی شواهد بسته شد (`c10-performance-evidence.md`).
- ⛔ **`clinic_id = 0` / `clinic_id = 1` / `location_id = 1` / «اولین ردیف» / وضعیت سراسری Clinic / Clinic مصنوعی / فرض هویتی `Asia/Tehran`** — همه ممنوع (AD-13 + AD-17 §۸ + §۳-B-2 همین سند).
- ⛔ **تضعیف ایزولاسیون tenant به بهانهٔ سادگی/عملکرد** — اولویت: صحت/امنیت tenant.

---

## ۱۱. Provenance و ثبت تغییرات

| تاریخ | رویداد |
|---|---|
| 2026-09-12 | ثبتِ اولیهٔ این سند به‌عنوان **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**، شامل: وضعیت راستی‌آزمایی‌شدهٔ جاری (§۱)، قواعد A..F (§۲–§۷)، شش سوالِ بازِ pre-`0021` (§۸) و مشخصاتِ ۱۲ تستِ RED (§۹). **فقط مستندات** — بدون کد/تست/workflow/schema/migration. مبنای ارجاعات: ADR-0031 (AD-04/08/13/14/15 + AD-17 §۸)، ADR-0016، `background-jobs.md`، `phase0.5-target-model.md`، `c6-deferred-boundaries.md`، `phase2-state.md` §C7/§C8، `project-current-state.md` §D |

**ارجاعات:**
[`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) ·
[`ADR-0016`](../adr/ADR-0016-job-execution.md) ·
[`ADR-0013`](../adr/ADR-0013-datetime.md) ·
[`background-jobs.md`](background-jobs.md) ·
[`phase0.5-target-model.md`](phase0.5-target-model.md) ·
[`../phase-reports/phase2-state.md`](../phase-reports/phase2-state.md) ·
[`../phase-reports/c6-deferred-boundaries.md`](../phase-reports/c6-deferred-boundaries.md) ·
[`../project-current-state.md`](../project-current-state.md) ·
[`../testing/testing-plan.md`](../testing/testing-plan.md)
