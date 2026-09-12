# Phase 2 — طراحی ترمیم Tenant Context در Jobهای پس‌زمینه، Timezone عملیاتی، پیکربندی SMS و لاگ عملیاتی

> # 🔴 APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED
> ## 🔴 جهت طراحیِ تأییدشده — **هنوز پیاده‌سازی نشده است**
>
> این سند **فقط طراحی/مشخصات** است. **هیچ کد، تست، workflow، schema یا migration‌ای
> بر پایهٔ آن نوشته یا اجرا نشده است.** هیچ بخشی از این سند مجوزِ پیاده‌سازی، ساخت
> ساختِ **هیچ migration آینده‌ای** (با هر شماره‌ای — این سند هیچ شماره‌ای را رزرو نمی‌کند)، شروع
> Phase 2 End Gate، شروع Phase 3 یا Phase 17 نیست.
> وضعیت اجرا = **NOT IMPLEMENTED**؛ هر پیاده‌سازیِ آینده نیازمند تصویب جداگانهٔ مالک،
> زنجیرهٔ RED→GREEN و گیت‌های CI است.

| | |
|---|---|
| **وضعیت** | **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED** (طراحی تأییدشده؛ پیاده‌سازی انجام نشده) |
| **تاریخ ثبت** | 2026-09-12 |
| **فاز مالک** | Owner Roadmap **Phase 2 (Multi-Clinic Core) — IN PROGRESS**؛ زیرمجموعهٔ اقلام به‌تعویق‌افتادهٔ «Jobs/SMS/timezone» که در `phase2-state.md` §C7 و `c6-deferred-boundaries.md` ثبت شده‌اند |
| **سطح** | LEVEL-2 داخلی (طبق `docs/governance/project-phase-taxonomy.md`) — تابعِ Roadmap مالک، فاز مستقل نیست |
| **مبنای معماری** | [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) (AD-04/AD-08/AD-13/AD-14/AD-15 + **AD-17 §۸**) · [`ADR-0016`](../adr/ADR-0016-job-execution.md) · [`background-jobs.md`](background-jobs.md) · [`phase0.5-target-model.md`](phase0.5-target-model.md) |
| **Migration** | **هیچ migration‌ای ساخته/تصویب/اجرا نشد** — آخرین migration = `0020`. **هیچ شمارهٔ migration آینده‌ای در این سند رزرو نشده** (شماره و محتوای migration بعدی فقط هنگامِ تصویبِ اولین برشِ پیاده‌سازیِ migration‌دار تعیین می‌شود). پیش‌نیازهای بازِ قبل از هر migration در §۸ فهرست شده‌اند |
| **End Gate** | **شروع نشده / تعریف نشده / پاس نشده** — این سند هیچ گیت فاز ۲ را باز نمی‌کند |
| **بازبینیِ معماری** | بازبینیِ مستقلِ **فقط‌خواندنی** (2026-09-12) روی همین چک‌پوینت → حکمِ مالک: **`B — NEEDS SMALL DOCUMENTATION CORRECTIONS`**. اصلاحاتِ **C-1..C-9** اعمال و در §۱۱ ثبت شد؛ §۸ بر پایهٔ تصمیماتِ مالک به‌روز شد؛ **RT-13/RT-14** افزوده شد. **PR هنوز DRAFT است — Ready/merge نشد** |
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
design questions that must be answered before any future migration may exist**
(this document reserves **no** migration number) and
**the RED test specification** that any implementation must satisfy.

**Nothing here is implemented. Nothing here authorizes implementation.**

> **Correction round (2026-09-12, documentation only).** An independent read-only architecture
> review found one materially false statement in §5-D-2 (SMS credential storage) plus eight
> completeness/consistency gaps. The Owner ruled **`B — NEEDS SMALL DOCUMENTATION CORRECTIONS`**.
> All nine corrections (**C-1..C-9**) are applied and itemized in §۱۱; the six pre-implementation
> questions in §۸ were re-recorded against the Owner's decisions; the RED spec grew from
> RT-1..RT-12 to **RT-1..RT-14** (existing items preserved, none weakened). Verified current
> behaviour and target design remain strictly separated, and every evidence class
> (VERIFIED FACT / HISTORICAL EVIDENCE / INFERENCE / OPEN) is labelled where it matters.

---

## ۱. وضعیت راستی‌آزمایی‌شدهٔ جاری (Current verified state — بدون ادعای اضافه)

همهٔ موارد زیر در همین working tree (چک‌پوینت `bdb135e9` + سند C10/AD-17) **بازبینی/راستی‌آزمایی** شده‌اند؛
این فهرست «وضعیت موجود» است، نه نقص‌شماری جدید و نه ادعای عملکرد:

| # | واقعیت جاری | شاهد |
|---|---|---|
| 1 | جدول `cpms_jobs` **هیچ ستون tenant‌ای ندارد** (نه `clinic_id`، نه `organization_id`، نه `location_id`) | `src/Migrations/2026_09_05_0001_initial_schema.php:657`؛ هیچ migration بعدی (تا `0020`) این جدول را تغییر نداده است |
| 2 | `JobQueue::enqueue()` در **سطحِ صف/ستون** هیچ scope‌ای را persist نمی‌کند — فقط `type`, `payload_json`, `status`, `priority`, `attempts`, `max_attempts`, `run_after`, `created_at` (هیچ ستونِ tenant‌ای وجود ندارد). **تکمیلِ صریح:** یک نوعِ Job امروز Clinic را **داخلِ `payload_json`** persist و مصرف می‌کند — ردیف ۱۵ | `src/Infrastructure/Queue/JobQueue.php:28` |
| 3 | `JobsDispatcher::tick()` **هیچ context‌ی برقرار نمی‌کند**؛ Handler فقط `array $payload` می‌گیرد؛ نوع ناشناخته ⇒ `RuntimeException('NO_HANDLER:<type>')` ⇒ Job fail می‌شود | `src/Application/Jobs/JobsDispatcher.php:38` |
| 4 | `ScopeContext` یک holder **ایستای per-process** است با `set/tryGet/clear`؛ در production در سه مسیر ست می‌شود: **(الف)** Job/Export، **(ب)** REST Trusted context، **(پ)** صفحاتِ wp-admin که scope صریح جایگزین می‌کنند (`App::replaceExplicitScope`) | `src/Application/Scope/ScopeContext.php` · `src/Application/Reports/ExportService.php:414` · `src/Rest/RestClinicContext.php:109`, `:131`, `:158` · `src/Admin/ClinicianAdminPage.php:413`, `:456`, `:491`, `:509` · `src/Bootstrap/App.php:884` |
| 5 | نبودِ Scope صریح ⇒ `App::scope()` به `SystemClinicResolver` می‌رسد که **فقط در حالت «دقیقاً یک Clinic»** مقدار برمی‌گرداند و در غیر آن `CLINIC_SCOPE_REQUIRED` می‌دهد (fail-closed؛ «تنها Clinic»، نه «اولین Clinic») | `src/Bootstrap/App.php:858` · `src/Application/Scope/SystemClinicResolver.php` |
| 6 | `Settings` با `int $clinicId` **صریح** ساخته می‌شود و cache‌اش per-clinic کلید می‌خورد (`self::$cache[$clinicId]`)؛ هیچ default-parameter tenant‌ای باقی نمانده است | `src/Settings/Settings.php:149`, `:125` |
| 7 | Timezone عملیاتیِ امروز از **`cpms_clinics.timezone`** خوانده می‌شود (`Settings::clinicTimezone()`) با fallback ثابت `Asia/Tehran`؛ مصرف‌کننده‌ها: `ApptReminderHandler:106`، `FollowUpReminderHandler:105`، `NotificationService:243` | `src/Settings/Settings.php:282` |
| 8 | `cpms_locations.timezone` **NOT NULL** است (Migration `0011`) ولی **هیچ مصرف‌کنندهٔ عملیاتی runtime ندارد** — یعنی Location هنوز در کد منبع حقیقت زمان نیست (همان مرزِ ثبت‌شدهٔ به‌تعویق‌افتادهٔ C8) | `src/Migrations/2026_09_09_0011_locations.php:10`؛ grep: تنها خواننده‌های `cpms_locations` = `PrimaryLocationResolver`، `TrustedClinicEstablisher`، `MembershipRepository` (هیچ‌کدام `timezone` نمی‌خوانند) |
| 9 | پیکربندی SMS — شامل **provider، قالب، `sender`، `advanced` و credentialِ sealedِ `sms.auth`** — از `Settings`ِ **همان Clinicِ آن instance** خوانده می‌شود ⇒ **درستیِ آن کاملاً تابعِ درستیِ `clinicId`ِ همان instance است** (جزئیاتِ ذخیره‌سازیِ credential: §۵-D-2) | `src/Application/Notifications/SmsService.php:258`, `:298`, `:380`, `:636-670`؛ نوشتنِ `sms.auth`: `:348` |
| 10 | جدول `cpms_operational_logs` **ستون tenant ندارد** (`level`, `message`, `context_json`, `request_id`, `created_at`) و `OpLogger` هیچ clinic‌ای را ثبت نمی‌کند | `src/Migrations/2026_09_05_0001_initial_schema.php:701` · `src/Infrastructure/Logging/OpLogger.php:46` |
| 11 | **هیچ خواننده/UI/API‌ای برای `cpms_operational_logs` وجود ندارد** — تنها مصرف‌کننده‌ها نوشتن (`OpLogger`) و حذف retention (`OpLogCleanupHandler`) هستند. بنابراین **امروز هیچ مسیر خواندنِ cross-clinic روی این جدول وجود ندارد** و این سند **هیچ نشتیِ Health UI را ادعا نمی‌کند** | grep سراسری `src/`: فقط `OpLogger::write` (insert) و `OpLogCleanupHandler` (DELETE بر پایهٔ `created_at`) |
| 12 | برخی Jobها امروز **جاروی سراسریِ نصب** هستند و scope هر ردیف را از آبجکت مرجع مشتق می‌کنند (مثلاً `slots.generate` روی همهٔ clinicianها پیمایش می‌کند و `clinic_id`/`location_id` را از ردیف `cpms_schedule` / `PrimaryLocationResolver` می‌گیرد) | `src/Application/Jobs/SlotsGenerateHandler.php:32-66` |
| 13 | Tenant Tripwire روی runtime فعال: **`hardcodes: 0`، `allowlist_entries: 0`، `suspects: 1`** (مورد suspect = `SystemClinicResolver.php:52`، sanction‌شده در `c6-census.md`)؛ ۵۹ self-test سبز | `python3 bin/tenant-tripwire.py --allowlist bin/tenant-tripwire-allowlist.json` |
| 14 | تنها «نمایشِ» مرتبط با صف، فهرستِ ۵ Jobِ `failed` در صفحهٔ فنی wp-admin است — query **بدون هیچ بُعد tenant** (چون `cpms_jobs` ستون tenant ندارد) و پشتِ capability `cpms_config` + nonce | `src/Admin/SettingsAdmin.php:37`, `:45-48` |
| 15 | **سابقهٔ کاریِ موجودِ contextِ per-job (نه پیشنهادِ greenfield):** تنها نوعِ رویدادمحورِ tenant-scopedِ امروز — `report.export` — Clinic را **داخلِ `payload_json`** persist می‌کند، آن را **fail-closed اعتبارسنجی** می‌کند (`clinic_id ≤ 0` ⇒ `ScopeRequiredException('CLINIC_SCOPE_REQUIRED')`)، scope را **bind** می‌کند و در **`finally`** به حالت قبل بازمی‌گرداند | `src/Application/Reports/ExportService.php:74-80` (enqueue با `clinic_id`) · `:112-126` (`generate()` = payload → bind → `try/finally` restore) · `:393-404` (`clinicIdFromJobPayload()` fail-closed) · `:410-422` (`bindJobClinic()` با بازگردانی scope قبلی) |

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
| **T — Tenant-scoped** | روی داده/پیکربندیِ **یک Clinic مشخص** عمل می‌کند | `sms.send`، `report.export` | context صریح persist‌شده + اعتبارسنجی در dispatch + ایزولاسیون per-job (A-1.1..A-1.12) |
| **S — System-wide (installation-level)** | واقعاً به **کل نصب** تعلق دارد و هیچ Clinic مالکش نیست | `backup.run`، `license.refresh` (⚠️ طبقهٔ هدف، **مشروط به §۸-۱**) · cleanup‌های سن‌محورِ جدولِ فاقد ستون tenant: `cleanup.otp`، `cleanup.rate_limits`، `cleanup.oplog`، `handwriting.gc` | ثبت **صریح + توجیه‌شده** در registry؛ **بدون** `clinic_id = 0` و **بدون** Clinic مصنوعی |
| **W — Installation-wide sweep با scope مشتق per-row** | جاروی سراسری که scope **هر ردیف** را از آبجکت مرجعِ همان ردیف می‌گیرد | `slots.generate`، `holds.expire`، `appt.reminder`، `fu.reminder`، `notif.dispatch`، `visits.no_show` (همه = **رفتارِ جاریِ راستی‌آزمایی‌شده**) | باید صریحاً طبقه‌بندی و **ثبت** شود؛ ردیف بدون آبجکت مرجعِ قابلِ انتساب ⇒ skip + log (نه حدس) |

> **قاعدهٔ سرسخت:** طبقهٔ scope یک ویژگی **ثبت‌شدهٔ نوع Job** است، نه نتیجهٔ بازرسیِ زمان اجرا.
> «الان فقط یک Clinic هست پس فرقی ندارد» **توجیه نیست** — AD-17 توپولوژی B/C را الزام دائمی محصول می‌داند.

> ⚠️ **تصحیحِ ثبت‌شده (بازبینیِ مستقلِ معماری، 2026-09-12):** در نسخهٔ نخستِ جدولِ بالا،
> `appt.reminder` به‌عنوان نمونهٔ **T** «(به‌ازای Clinic)» ذکر شده بود. **کدِ جاری این را تأیید
> نمی‌کند:** `ApptReminderHandler` یک **جاروی سراسریِ بدونِ predicate کلینیک** است که `clinic_id`ِ هر
> ردیف را از خودِ ردیفِ نوبت می‌گیرد و به `SmsService::sendEvent()` / `publishToPatient()` منتقل
> می‌کند (`src/Application/Jobs/ApptReminderHandler.php:46-58`؛ commentِ صریحِ C6 در `:46`) ⇒
> در واژگانِ همین سند **W** است، نه T. **هیچ shardingِ per-Clinic‌ای امروز وجود ندارد و این سند
> ادعای وجودِ آن را ندارد.**
>
> همچنین **T-per-Clinic برای یادآوری‌ها کافی نیست:** یک Clinic می‌تواند چند Location با timezoneِ
> متفاوت داشته باشد (C-2)، پس محاسبهٔ درستِ «امروز/فردا» و quiet hours باید از **آبجکتِ عملیاتیِ
> همان ردیف** (C-1) مشتق شود، نه از یک contextِ کلینیکیِ واحد بر کلِ جارو.
>
> **تفکیکِ الزامیِ گزارش‌دهی:** «رفتارِ جاریِ runtime» و «طبقهٔ هدفِ طراحی» دو مقولهٔ جدا هستند؛
> §A-3 هر دو را صریح و جدا ثبت می‌کند و هیچ‌کدام به‌جای دیگری گزارش نمی‌شود.

### A-3. طبقه‌بندیِ **۱۵ نوعِ واقعاً ثبت‌شده** در runtime (مدلِ طراحیِ مصوب — نه پیاده‌سازی)

**منبعِ حقیقتِ registry = `App::dispatcher()` + `RECURRING_JOBS`**
(`src/Bootstrap/App.php:1002+` و `:1045-1065`): **۱۵ نوعِ ثبت‌شده**، که **۱۳ نوع** در
`RECURRING_JOBS` زمان‌بندی می‌شوند و دو نوعِ `sms.send` و `report.export` **رویدادمحور** هستند
(`scheduleRecurringJobs()` در هر tick، هر نوعِ زمان‌بندی‌نشده را **بدونِ payload** enqueue می‌کند —
`App.php:1114-1128`).

> ⛔ **`docs/architecture/background-jobs.md` §۲ منبعِ حقیقتِ runtime نیست** و driftِ ثبت‌شده دارد
> (`docs/drift-register.md` §۹-B): نام‌های **ثبت‌نشده در کد** (`appt.expire_pending`، `ocr.recognize`،
> `cleanup.holds`، `audit.chain_verify`، `temp.cleanup`) · **نام‌های ناهمسان**
> (`no_show.detect` ↔ `visits.no_show`؛ `cleanup.idempotency` ↔ `cleanup.idem`) ·
> **انواعِ ثبت‌شدهٔ غایب از آن سند** (`cleanup.oplog`، `cleanup.rate_limits`، `sms.send`).
> هر طبقه‌بندی/registry/allowlist باید از **کدِ واقعیِ dispatcher** ساخته شود، نه از آن جدولِ تاریخی
> (که بدون بازنویسی، به‌عنوان سندِ تاریخی باقی می‌ماند).

| نوع | رفتارِ **جاریِ** runtime (راستی‌آزمایی‌شده با بازرسیِ کد) | طبقهٔ **هدف** | وابستگیِ **جاری** به Settings | دسترسیِ tenant-sensitive | `clinic_id` روی Job لازم است؟ | `NULL` مجاز است؟ |
|---|---|---|---|---|---|---|
| `holds.expire` | جاروی `cpms_slot_holds` با `status='active' AND expires_at<=now LIMIT 500` بدونِ predicate کلینیک + آزادسازیِ ظرفیتِ اسلاتِ همان ردیف (`HoldsExpireHandler.php:20-52`) | **W** | ندارد | خیر (اثر per-row؛ ردیف `clinic_id` دارد) | خیر | بله — فقط با ثبتِ صریحِ W |
| `cleanup.otp` | `DELETE cpms_otp_tokens WHERE created_at < cutoff(24h)`؛ جدول **ستون tenant ندارد** (`OtpCleanupHandler.php:18-26`) | **S** | ندارد (ثابتِ سیاست در کد) | خیر | خیر | بله — S ثبت‌شده |
| `cleanup.rate_limits` | `RateLimiter::cleanup(2*86400)` ⇒ `DELETE cpms_rate_limits WHERE window_id*window_sec < cutoff`؛ ستون‌ها `window_key/window_id/hits` بدون tenant (`RateLimiter.php:79-87`) | **S** | ندارد | خیر | خیر | بله — S ثبت‌شده |
| `cleanup.idem` | `Idempotency::cleanup(90)` ⇒ `DELETE cpms_idempotency_keys WHERE created_at < cutoff`؛ جدول `clinic_id` دارد ولی حذف **سن‌محورِ کلِ جدول** است (`Idempotency.php:121-131`) | **S** | ندارد | خیر | خیر | بله — S ثبت‌شده |
| `cleanup.oplog` | `DELETE cpms_operational_logs WHERE created_at < cutoff` **بدونِ LIMIT و بدونِ بُعد tenant**؛ روزها از `retention.oplog_days` (`OpLogCleanupHandler.php:27-33`) | **S** — semanticsِ retention = **نصب‌گسترده** (§۸-۲ RESOLVED) | ✅ `retention.oplog_days` از سطرِ **Clinicِ bootstrap** ⇒ منبعِ پیکربندی باید سطحِ نصب شود (§۸-۱) | خیر | خیر | بله — S ثبت‌شده |
| `slots.generate` | پیمایشِ **همهٔ** clinicianهای فعالِ همهٔ Clinicها؛ `clinic_id` از `cpms_clinicians` و `location_id` از `cpms_schedule` وگرنه `PrimaryLocationResolver::resolve()` (fail-closed) (`SlotsGenerateHandler.php:29-70`) | **W** | ✅ `booking.max_future_days` (افق) از Clinicِ bootstrap ⇒ bleedِ پیکربندی | خیر — ولی تاریخِ اسلات حساسِ timezone است (C-9) | خیر | بله — W ثبت‌شده |
| `sms.send` | رویدادمحور با payload `{message_id}`؛ ردیفِ پیام `clinic_id` دارد ولی provider/credential/sender/timeout از `Settings`ِ **سطحِ process** حل می‌شود (`SmsService.php:175-203`, `:636-670`) | **T** | ✅ `sms.provider`، `sms.auth` (sealed)، `sms.sender`، `sms.advanced` | ✅ **بله** — credential + محتوای پیامِ بیمار | ✅ **بله** | ❌ **خیر** |
| `visits.no_show` | `processNoShows()` ← `appointmentsPastGrace()` بدونِ predicate کلینیک؛ انتساب از خودِ ردیفِ نوبت (`VisitService.php:485-508`؛ `VisitRepository.php:330-342`) | **W** | ✅ `queue.no_show_grace_minutes` از Clinicِ bootstrap | خیر | خیر | بله — W ثبت‌شده |
| `handwriting.gc` | `purgeVersions()` ← `purgeOldVersions()` روی `cpms_handwriting_page_versions` (**بدونِ `clinic_id`**)، کلِ جدول (`HandwritingService.php:383-389`؛ `HandwritingRepository.php:159+`) | **S** | ✅ `hw.version_keep`، `hw.version_max_age_days` از Clinicِ bootstrap | خیر | خیر | بله — S ثبت‌شده |
| `notif.dispatch` | یک `UPDATE` **کلِ جدول** بدونِ predicate + `purgeArchived(notif.archive_days)` (`NotificationRepository.php:204-214`؛ `NotificationService.php:217-223`) | **W** | ✅ `notif.archive_days` از Clinicِ bootstrap | خیر (جدول `clinic_id` دارد) | خیر | بله — W ثبت‌شده |
| `appt.reminder` | جاروی سراسریِ نوبت‌های `confirmed` برای امروز/فردا **بدونِ predicate کلینیک**؛ `clinic_id`ِ هر ردیف به SMS/Notification پاس می‌شود؛ «امروز/فردا» از `clinicTimezone()`ِ **Clinicِ bootstrap** (`ApptReminderHandler.php:46-58`, `:104-112`) | **W** (+ اصلاحِ timezone بر پایهٔ Location — §۴) | ✅✅ `clinicTimezone()`، `notif.quiet_hours_*` و پیکربندی SMS از Clinicِ bootstrap | ✅ **بله** — موبایل/نامِ بیمار در SMS | خیر برای ردیفِ Job (انتسابِ per-row) — مگر مالک shardingِ صریح را تصویب کند | بله — W ثبت‌شده |
| `fu.reminder` | همان الگوی جارو روی `cpms_follow_ups`؛ این جدول **`location_id` ندارد** ⇒ اشتقاقِ Locationِ آبجکت نیازمندِ زنجیرهٔ ثبت‌شده است (`FollowUpReminderHandler.php:45-55`, `:103-110`) | **W** (+ اصلاحِ timezone بر پایهٔ Location — §۴) | ✅✅ `clinicTimezone()`، quiet hours، پیکربندی SMS | ✅ **بله** | خیر | بله — W ثبت‌شده |
| `report.export` | رویدادمحور؛ `clinic_id` در `payload_json` persist + اعتبارسنجیِ fail-closed + bind + restore در `finally` (`ExportService.php:74-80`, `:112-126`, `:393-404`, `:410-422`) | **T** — **امروز هم درست پیاده شده** (ردیف ۱۵ §۱) | ✅ `reports.export_max_rows`، `reports.export_retention_days` | ✅ بله | ✅ بله (از payload) | ❌ خیر |
| `license.refresh` | وضعیتِ مجوز در **جدول‌های سطحِ نصب بدونِ `clinic_id`** (`cpms_license_install`، `cpms_license_state` تک‌ردیفی — Migration `0008`)؛ اما `license.server_url` از سطرِ Clinic خوانده می‌شود (`App.php:746`, `:950`) | **S** — ⚠️ **مشروط به §۸-۱** | ✅ `license.server_url` از Clinicِ bootstrap | خیر — دادهٔ تجاریِ control-plane، نه بالینی | خیر | بله — S ثبت‌شده |
| `backup.run` | بکاپِ **کلِ نصب**: dumpِ همهٔ `cpms_*` + storage + manifest (`BackupService.php:90-105`)؛ `backup.*` از سطرِ Clinic خوانده/نوشته می‌شود و UI آن صفحهٔ «CPMS (سیستم)» است (`SystemPage.php:203-207`, `:327-329`) | **S** — ⚠️ **مشروط به §۸-۱** | ✅ `backup.enabled/interval_hours/keep_count/storage_path/last_run_at` از Clinicِ bootstrap | 🔎 artifact حاوی PHIِ **همهٔ** Clinicهاست (AD-11: مرز منطقی، نه فیزیکی) ولی **Clinic-scoped نیست**؛ «بکاپ per-Clinic» ویژگیِ ثبت‌نشده است | خیر | بله — S ثبت‌شده |

**قاعدهٔ سازگاریِ registry (برای هر پیاده‌سازیِ آینده):** هر نوع **دقیقاً یک** طبقهٔ ثبت‌شده دارد و
dispatcher سازگاریِ داده را enforce می‌کند — `T ⇒ clinic_id` غیرتهی و اعتبارسنجی‌شده ·
`S ⇒ clinic_id` باید NULL باشد · `W ⇒ clinic_id` باید NULL باشد و انتساب از آبجکتِ مرجعِ هر ردیف
مشتق شود. نوعِ بدونِ طبقه ⇒ reject در زمانِ ثبت/اجرا (A-1.10 + RT-12).
**`NULL` هرگز به‌طور خودکار «system» معنا نمی‌شود** — معنا فقط از **طبقهٔ ثبت‌شدهٔ همان نوع** می‌آید (§۳-B-2).

> ⚠️ **مرزِ این جدول:** این یک **مدلِ طراحیِ مصوب** است، نه پیاده‌سازی. هیچ ستونِ `clinic_id`‌ای به
> `cpms_jobs` اضافه نشده، هیچ registry کلاسی در کد وجود ندارد و هیچ تستی این جدول را enforce نمی‌کند.
> طبقهٔ `backup.run`/`license.refresh` تا تعیینِ منبعِ پیکربندیِ سطحِ نصب (§۸-۱) **مشروط** است و
> «نصب‌گسترده بودنِ» سایر کلیدهای retention/purge از این جدول **استنتاج نمی‌شود** (§۸-۲ فقط دربارهٔ
> `retention.oplog_days` تصمیم دارد).

---

## ۳. B — Jobهای System-Wide (بدونِ allowlist نهایی؛ وضعیتِ هر مورد صریح ثبت می‌شود)

**در این سند هیچ allowlist گسترده‌ای نهایی نمی‌شود.** هر نوع که ادعای system-wide بودن دارد
باید **یکی‌یکی، صریحاً ثبت و توجیه** شود؛ «چون همه‌جا لازم است» توجیه نیست.

### B-1. اقلامِ **مشروط/باز** (هیچ allowlist نهایی‌ای در این سند ساخته نمی‌شود)

| نوع | مفهوم | چرا باز/مشروط است |
|---|---|---|
| `backup.run` | **مفهوماً installation-wide** است (بکاپ از کل DB/storage نصب — `BackupService.php:90-105` همهٔ `cpms_*` را dump می‌کند) | **طبقهٔ هدف = S، ولی مشروط:** جهتِ §۸-۱ (انتزاعِ اختصاصیِ سطحِ نصب روی WordPress Options) تصویبِ اصولی شده، اما **فهرستِ دقیقِ کلیدها نهایی نشده** و semanticِ هر کلید باید جداگانه راستی‌آزمایی شود. کلیدهای رفتاریِ بکاپ امروز از `Settings` (جدول `cpms_settings` با `clinic_id` NOT NULL) خوانده می‌شوند ⇒ تا پیاده‌سازیِ آن جهت، «پیکربندیِ نصب» در مدلِ دادهٔ امروز جایِ صریحی ندارد |
| `license.refresh` | **مفهوماً installation-wide** است (مجوزِ نصب/پلاگین) | **طبقهٔ هدف = S، ولی مشروط به §۸-۱:** وضعیتِ **ساخت‌یافتهٔ** مجوز امروز در **جدول‌های اختصاصیِ سطحِ نصب بدونِ `clinic_id`** است (`cpms_license_install`، `cpms_license_state` — Migration `0008`) و **نباید** صرفاً به‌خاطرِ معرفیِ Optionsِ سطحِ نصب جابه‌جا شود؛ تنها کلیدهای **پیکربندیِ اسکالر** (`license.server_url` و خانوادهٔ `update.*`) kandidِ سطحِ نصب‌اند. منبعِ پیکربندی امروز Clinic-scoped است (`App.php:746`, `:950`) |
| `cleanup.oplog` | پاک‌سازی `cpms_operational_logs` بر پایهٔ `created_at` | ✅ **RESOLVED (§۸-۲):** retention = **نصب‌گسترده** ⇒ طبقهٔ **S**. اگر در آینده نیازِ محصولی به retentionِ per-Clinic پیدا شود، آنگاه **بازطراحیِ tenant-awareِ صریح** لازم است (انتسابِ tenant به لاگ‌ها — §۶ — و DELETE تفکیک‌شده بر پایهٔ Clinic) و آن مسیر **امروز طراحی/تصویب نشده است** |

### B-2. ممنوعیت‌های مطلق (بدون استثنا)

- ⛔ **`clinic_id = 0` ممنوع** — نه به‌عنوان «system»، نه به‌عنوان «نامعلوم»، نه به‌عنوان sentinel. صفر یک شناسهٔ tenant جعلی است و هر query/join/FK/authorization بر پایهٔ آن یا بی‌معناست یا ناامن (هم‌خانوادهٔ AD-13: `clinic_id = 1` ممنوع ⇒ `clinic_id = 0` هم ممنوع).
- ⛔ **Clinic مصنوعی/سنتتیکِ «System» ممنوع** — ساخت ردیف `cpms_clinics` با نامِ «System»/«Global»/«HQ» برای فرار از طبقه‌بندی، **ممنوع** است. چنین ردیفی Organization/Location/Membership مصنوعی می‌سازد، در شمارشِ «تعداد Clinicها» (و بنابراین در fail-closed بودنِ `SystemClinicResolver`) اختلال ایجاد می‌کند و مرز مجوزدهی را آلوده می‌کند.
- ⛔ **NULL به‌عنوان «system» به‌صورت ضمنی ممنوع** — اگر جایی مقدار tenant برای رویدادِ واقعاً سیستمی NULL است، باید **معنای صریحِ ثبت‌شده** داشته باشد (مانند قاعدهٔ موجودِ `cpms_audit_logs.clinic_id` NULL-able در Migration `0016`: «NULL = سیستمی، نه ۱») و **هر خواننده** باید آن را صریحاً مدیریت کند؛ NULL هرگز به «Clinic پیش‌فرض» نگاشت نمی‌شود.
- ⛔ **allowlist ضمنی/سراسری ممنوع** — هیچ لیستِ «این نوع‌ها tenant نمی‌خواهند» بدون ثبتِ تک‌تکِ موارد + توجیه + معیار پذیرش معتبر نیست.

---

## ۴. C — Timezone عملیاتی = Location

**منبع حقیقتِ timezone عملیاتی، `Location` است** (`cpms_locations.timezone` — NOT NULL، از Migration `0011`).

**سه منبعِ timezone در مخزن وجود دارد (راستی‌آزمایی‌شده با بازرسیِ کد) — فقط یکی مصرفِ عملیاتی دارد:**

| منبع | وضعیتِ جاریِ راستی‌آزمایی‌شده |
|---|---|
| `cpms_locations.timezone` (NOT NULL، از Migration `0011`) | **منبعِ حقیقتِ هدفِ معماری** (AD-08) — ولی **هیچ مصرف‌کنندهٔ عملیاتیِ runtime ندارد** (§۱ ردیف ۸) |
| `cpms_clinics.timezone` (NOT NULL DEFAULT `'Asia/Tehran'` — `0001:29`؛ seed در `0001:736`) | **تنها منبعِ مؤثرِ امروز**: `Settings::clinicTimezone()` فقط همین را می‌خواند (`Settings.php:282-289`)؛ مصرف‌کننده‌ها: `ApptReminderHandler.php:106`، `FollowUpReminderHandler.php:105`، `NotificationService.php:243`. **هیچ مسیرِ production‌ای پس از seed آن را نمی‌نویسد** |
| `setup.clinic.timezone` (کلید در `cpms_settings`) | ویزارد آن را **می‌نویسد** (`CpmsSetupWizard.php:585-600`) و Migration `0011` هنگامِ seedِ Location آن را **می‌خواند** (`0011:60-70`)؛ ولی `clinicTimezone()` آن را **نمی‌خواند** ⇒ **در runtime بی‌اثر است** (C-6) |

| # | قاعده |
|---|---|
| **C-1** | اگر Job/رویداد یک **آبجکت عملیاتیِ صریح** دارد (نوبت، ویزیت، اسلات، شیفت، یادآوریِ یک نوبت مشخص) ⇒ **timezoneِ Locationِ همان آبجکت** برنده است — نه timezoneِ Clinic، نه تنظیم کاربر، نه timezone سرور |
| **C-2** | **یک Clinic می‌تواند چند Location با timezoneهای متفاوت داشته باشد.** هر طراحی که «یک timezone به‌ازای Clinic» را فرض کند، توپولوژی B/C (AD-17 §۸) را نقض می‌کند |
| **C-3** | **Timezoneِ Clinic هرگز timezoneِ Locationِ یک آبجکت را override نمی‌کند.** Clinic-level فقط **default/legacy** است (§C-6) |
| **C-4** | **Fail-closed:** نبودِ Locationِ لازم، timezone نامعتبر/غیر-IANA، یا Locationِ غیرمتعلق به همان Clinic ⇒ خطای صریح + توقف عملیات (مثلاً یادآوری ارسال نمی‌شود) — **نه** fallback خاموش به `Asia/Tehran` |
| **C-5** | **`Asia/Tehran` فقط یک مقدار IANA کنترل‌شدهٔ سازگاری/پیش‌فرض است** (پیش‌فرض seeding در Migration `0011`؛ whitelist ویزارد). **هرگز نباید نبودِ scope عملیاتی را بپوشاند** — یعنی هیچ مسیری مجاز نیست «چون `Asia/Tehran` هست، ادامه بده» بگوید وقتی Location/آبجکت مرجعِ لازم مفقود است |
| **C-6** | **Timezoneِ Clinic = legacy/default سازگاری** است و **تصمیمِ deprecation آن موکول به آینده است** (این سند آن را حذف یا نهایی نمی‌کند؛ §۸-۶ و تصمیمِ جداگانهٔ مالک). تا آن زمان: مصرف‌کنندهٔ عملیاتیِ `cpms_clinics.timezone` باید صریحاً به‌عنوان «مسیر سازگاری» مستند بماند و مسیر Location جایگزینش شود. **قطعِ اتصالِ راستی‌آزمایی‌شده (verified disconnect):** کلیدِ `setup.clinic.timezone` توسط Setup Wizard نوشته می‌شود (`CpmsSetupWizard.php:269`, `:585-600`) ولی `clinicTimezone()` **فقط** `cpms_clinics.timezone` را می‌خواند (`Settings.php:282-289`) و هیچ مسیرِ production‌ای پس از seedِ `0001:736` آن ستون را نمی‌نویسد ⇒ **انتخابِ timezone در ویزارد امروز در runtime مؤثر نیست.** این سند آن را «مؤثر» نمی‌نامد؛ وصل‌کردنِ این سه منبع به Locationِ عملیاتی بخشی از همین جهتِ طراحی است |
| **C-7** | **ذخیرهٔ زمان = UTC** (قاعدهٔ موجود ADR-0013/`Settings`): timezone فقط برای **محاسبهٔ زمان‌بندی/نمایش** است؛ هیچ ستونِ زمانی به timezone محلی ذخیره نمی‌شود |
| **C-8** | **پایداری در retry (§۸-۶).** ✅ **واقعیتِ جاریِ راستی‌آزمایی‌شده:** `location_id` روی نوبت/ویزیت یک **snapshotِ زمانِ ساخت** است (از Slotِ مرجع، وگرنه `PrimaryLocationResolver` — `AppointmentRepository.php:85-89` و `:101+`؛ `VisitRepository.php:93+`) و **هیچ مسیرِ به‌روزرسانیِ معمولی برای آن وجود ندارد** (`AppointmentRepository::updateStatus()` فقط `STATUS_FIELDS` را whitelist می‌کند — `:121-133`)؛ **reschedule یک ردیفِ نوبتِ جدید می‌سازد** (`BookingService.php:522-547`) و ردیفِ قدیم فقط `status`/`rescheduled_to` می‌گیرد؛ همچنین **هیچ نویسندهٔ production‌ای برای `cpms_locations` وجود ندارد** (فقط migrationها) ⇒ Locationِ آبجکتِ مرجع در عمل تغییر نمی‌کند. ✅ **جهتِ مصوب:** برای انواعِ **جاریِ** Job، Location از **آبجکتِ عملیاتیِ مرجعِ معتبر (authoritative) در زمانِ اجرا** مشتق می‌شود، نه از مقدارِ جداگانهٔ زمانِ enqueue. ⚠️ **قیدِ بازنگری:** اگر در آینده semanticsِ آبجکت اجازهٔ **تغییرِ Location** بدهد (ویرایش/غیرفعال‌سازی Location، انتقال نوبت به Location دیگر)، **همین قرارداد باید صریحاً بازنگری و ثبت شود** — تغییرِ خاموشِ رفتارِ retry مجاز نیست |
| **C-9** | **`slots.generate` — محاسبهٔ تاریخِ محلی (در دامنهٔ remediationِ timezone).** ✅ راستی‌آزمایی‌شده: تاریخِ امروز/افقِ تولیدِ اسلات با `gmdate('Y-m-d')` یعنی **UTC** محاسبه می‌شود (`SlotsGenerateHandler.php:30`)، در حالی که `slot_date` در ADR-0013 و در خودِ مسیرِ یادآوری «تاریخِ تقویمِ **محلیِ** مطب» است (`ApptReminderHandler.php:100-102`)؛ افق نیز از `booking.max_future_days`ِ **Clinicِ bootstrap** خوانده می‌شود (`SlotsGenerateHandler.php:29`). 🔎 پیامدِ استنتاجی (اجرا/اندازه‌گیری نشده): در Location‌هایی با اختلافِ timezone، مرزِ «روز» و افقِ رزرو می‌تواند برای آن Location نادرست بیفتد. **این Job صریحاً در دامنهٔ remediationِ timezone است** — بدونِ تعمیم به سایر Jobها |
| **C-10** | **`visits.no_show` — مقایسهٔ grace/زمانِ محلی (در دامنهٔ remediationِ timezone).** ✅ راستی‌آزمایی‌شده: cutoff با `gmdate('Y-m-d H:i:s', time() - grace*60)` از **UTC** ساخته می‌شود (`VisitService.php:485-491`) و با `CONCAT(a.slot_date, ' ', a.slot_time)` — مقادیرِ **محلیِ** نوبت — مقایسه می‌شود (`VisitRepository.php:330-342`)؛ `queue.no_show_grace_minutes` نیز از Clinicِ bootstrap می‌آید (`VisitService.php:885-888`). 🔎 پیامدِ استنتاجی (اجرا/اندازه‌گیری نشده): no-show می‌تواند به‌اندازهٔ اختلافِ timezone زودتر/دیرتر ثبت شود. **این Job نیز صریحاً در دامنهٔ remediationِ timezone است** |

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

- مسئولیت: «**Clinicِ اعتبارسنجی‌شده** → پیکربندیِ معتبرِ SMS شامل **provider، قالب/‏template و زبان، `sender`/پارامترهای پنل، تنظیماتِ `advanced` (retry/timeout) و credentialِ sealedِ همان Clinic**» به‌صورت **صریح، قابل تست و یکتا**.

> 🔴 **تصحیحِ ثبت‌شده (must-fix — بازبینیِ مستقلِ معماری، 2026-09-12).** جملهٔ نسخهٔ نخستِ این بند
> («Secret/API key هرگز در `cpms_settings` ذخیره نمی‌شود — قاعدهٔ موجودِ `Settings`، فقط wp-config/env»)
> **با کدِ جاری و با `ADR-0025` در تضاد بود** و با متنِ زیر جایگزین شد. آن جمله روایتِ کهنهٔ
> «تصمیم F1-D3» در `settings-reference.md:12` است که **با خودِ همان سند** (`:56` و §Secrets `:106`)
> ناسازگار است؛ این ناسازگاری در `docs/drift-register.md` §۹-A ثبت شد (بدون بازنویسیِ انبوهٔ سندِ مرجع).

| گزارهٔ دقیق | وضعیت | شاهد |
|---|---|---|
| **plaintext** credential هرگز در `cpms_settings` ذخیره نمی‌شود و در HTML/Log/REST/Audit ظاهر نمی‌شود | ✅ درست (و با این طراحی تغییر نمی‌کند) | `CredentialVault.php:7-15` («مقدار plaintext هرگز در Settings/HTML/Log/REST/Audit نیست»)؛ `Settings.php:145-147` + `:239-243` (کپیِ audit با `[redacted:credentials]`)؛ `settings-reference.md:56`, `:106` |
| credentialِ پنلِ SMS **امروز per-Clinic در `cpms_settings` ذخیره می‌شود** — کلیدِ `sms.auth`، به‌صورت **sealed** (AES-256-GCM: `{v, nonce, tag, data}` + `last4`) | ✅ **واقعیتِ جاریِ راستی‌آزمایی‌شده** | نوشتن: `SmsService.php:348` · خواندن: `:646-660` (`storedAuth()`) · رمزگشایی فقط در لحظهٔ call: `:667+` (`plaintextCredentials()`)؛ `ADR-0025:44-45` («ذخیره در `cpms_settings` (`sms.auth`): فقط Ciphertext + Nonce + Tag + `last4`»)؛ `settings-reference.md` §Secrets |
| کلیدِ Vault **سطحِ نصب** است: Env `CPMS_SECRET_KEY` (≥32 byte) یا مشتق از Saltهای نصبِ وردپرس (per-installation، نه per-Clinic) | ✅ درست | `CredentialVault.php:9-13` و `:69` |
| ⇒ **رمزنگاری به‌خودیِ خود، ایزولاسیونِ Clinic را enforce نمی‌کند**: هر process با دسترسی به DB و کلیدِ Vault می‌تواند credentialِ **هر** Clinic را رمزگشایی کند (هیچ سدِّ رمزنگارانه‌ای بین Clinicها نیست) | 🔎 نتیجهٔ مستقیمِ دو سطرِ بالا — **بدونِ ادعای سوءاستفادهٔ اثبات‌شده** | — |
| ⇒ بنابراین **اپلیکیشن باید پیش از بازیابی/رمزگشایی، Clinicِ درست را اعتبارسنجی و resolve کند**؛ سدِ ایزولاسیون = **scope resolutionِ درست**، نه cryptography | 🟢 الزامِ طراحی (همان اصلِ §D + D-1 + D-3) | — |

**دامنهٔ صریحِ ایزولاسیونِ per-Clinicِ پیکربندیِ SMS:** `sms.provider` · `sms.templates` (قالب/زبان) ·
`sms.sender` و پارامترهای پنل · `sms.advanced` (retry/timeout) · **و `sms.auth` (credentialِ sealed)** —
همه باید برای **Clinicِ اعتبارسنجی‌شدهٔ همان Job/درخواست** حل شوند، و نه از هیچ‌یک از منابعِ D-1.

**تضمینِ رمزنگاری بیش از حدِ واقعی بیان نمی‌شود:** AES-256-GCM فقط **محرمانگی/اصالتِ مقدارِ ذخیره‌شده
در برابرِ کسی است که به DB دسترسی دارد ولی کلیدِ Vault را ندارد**؛ این **مرزِ tenant نیست** و جایگزینِ
scope resolutionِ درست نمی‌شود. به همین دلیل §D-1 (بی‌اثر بودنِ منابعِ محیطی) و §D-3 (fail-closed)
برای credential نیز — نه فقط provider — برقرار است.
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
| **E-6** | جزئیات schema/ایندکس (نام/نوع ستون، NULL-able بودن، ایندکس‌ها، retention و partitioning) **در این سند نهایی نمی‌شود** — **§۸-۴** را ببینید: جهتِ بازبینی‌شده ثبت شده ولی **schema/migration مجاز نیست**. هر تغییر نیازمندِ migrationِ مصوبِ **جداگانه** است؛ **هیچ migration‌ای در این تسک ساخته نشد و این سند هیچ شمارهٔ migration آینده‌ای را رزرو نمی‌کند** |
| **E-7** | ✅ **واقعیتِ جاریِ ایندکس/retention (راستی‌آزمایی‌شده با بازرسیِ کد):** تنها ایندکسِ جدول `idx_oplog_level (level, created_at)` است (`src/Migrations/2026_09_05_0001_initial_schema.php:709`)، در حالی که تنها queryِ اجراییِ امروزِ retention یک predicate **فقط-`created_at`** است: `DELETE FROM cpms_operational_logs WHERE created_at < %s` (`src/Application/Jobs/OpLogCleanupHandler.php:27-33`). به‌دلیلِ **قاعدهٔ leftmost-prefix**، آن ایندکس این الگوی دسترسی را **پوشش نمی‌دهد**. همان `DELETE` امروز **بی‌کران (بدونِ `LIMIT`)** است. 🔎 پیامدِ استنتاجی (بدونِ اندازه‌گیری/اجرا): هزینهٔ پاک‌سازی با رشدِ جدول بالا می‌رود و حذفِ بی‌کران می‌تواند lock/long-transaction ایجاد کند. **راه‌حل‌های ممکن (chunked delete با `LIMIT`، ایندکسی با ستونِ نخستِ `created_at`) در این سند فقط «جهتِ طراحی» هستند — هیچ‌کدام پیاده‌سازی نشده و هیچ ایندکسِ speculative‌ای بدونِ queryِ پشتیبان پیشنهاد نمی‌شود** (E-6/§۸-۴) |

---

## ۷. F — Jobهای Legacy (قواعد migration/backfill آینده)

این قواعد **برای آینده** است؛ **هیچ backfill/migration‌ای در این تسک انجام نشده و هیچ migration جدیدی ساخته نشده است** (آخرین = `0020`؛ این سند شمارهٔ migration آینده را رزرو نمی‌کند).

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

> **توضیحِ افزوده‌شده از بازبینیِ مستقل (2026-09-12) — بدونِ تغییرِ قواعدِ بالا:**
>
> 1. ✅ **دقت دربارهٔ سابقهٔ Migration `0020`:** آن migration **دو** رفتار دارد که باید از هم جدا شوند —
>    **(الف) قیدِ محافظِ قابلِ استناد:** preflight بدونِ تغییرِ داده + **abort صریح در نصبِ چندکلینیکی**
>    (`2026_09_09_0020_idempotency_clinic_scope.php:30-45`)؛ **(ب) رفتاری که F-2/F-5 آن را ممنوع
>    می‌دانند و نباید تکرار شود:** در نصبِ تک‌کلینیکی همان migration مقدارِ تهی را با
>    `UPDATE ... SET clinic_id = 1` **normalize** می‌کند (با commentِ «همهٔ نصب‌ها تا امروز
>    تک‌کلینیکی‌اند»). برای `cpms_idempotency_keys` (ردیف‌های request-scoped و tenant-bearing) این
>    قابلِ دفاع بود؛ برای `cpms_jobs` **قابلِ تکرار نیست**، چون ۱۳ نوعِ زمان‌بندی‌شده
>    installation-level/sweep‌اند و نوشتنِ یک Clinic روی آن‌ها **مالکِ tenantِ جعلی** به Jobِ سطحِ نصب
>    نسبت می‌دهد (نقضِ A-1.11/A-1.12 و B-2). **نتیجه: از `0020` فقط guard/abort/preflight الگو گرفته
>    شود، نه literal-normalization.**
> 2. ✅ **دسترسیِ واقعیِ امروز به provenance (راستی‌آزمایی‌شده):** از ۱۵ نوعِ ثبت‌شده، فقط **دو نوع**
>    payloadِ tenant-bearing دارند — `report.export` (خودِ `clinic_id` در `payload_json`؛
>    `ExportService.php:74-80`) و `sms.send` (`message_id` → `cpms_sms_messages.clinic_id`؛
>    `SmsService.php:136-152`). **۱۳ نوعِ زمان‌بندی‌شده با payloadِ تهی enqueue می‌شوند**
>    (`App.php:1114-1128` — `$queue->enqueue($type, [], $now, priority: $priority)`) ⇒ برای آن‌ها
>    **هیچ provenance‌ای در سطحِ ردیفِ Job وجود ندارد** و انتسابِ Clinic نه لازم است و نه مجاز
>    (طبقهٔ W/S در §A-3). این یعنی F-3 عملاً فقط برای همان دو نوعِ رویدادمحور به کار می‌آید.
> 3. ✅ **ردیف‌های terminal:** `success`/`failed` هرگز dispatch نمی‌شوند ⇒ نگهداریِ «scope نامعلوم»
>    برای آن‌ها (F-6) ریسکِ اجرایی ندارد؛ ریسکِ fail-closed فقط برای `queued`/`processing` است (F-4).
> 4. ⛔ **اندازه‌گیریِ جایگزینِ حدس:** هیچ تعدادِ ردیفِ واقعیِ `cpms_jobs` از مخزن/کد قابلِ استخراج
>    نیست (§۸-۵ = OPEN — NOT MEASURED)؛ پس هیچ migrationِ job‌محوری پیش از آن اندازه‌گیری طراحی نشود (F-9).

---

## ۸. سوالاتِ طراحیِ **پیش از هر migration آینده** — وضعیتِ بازبینی‌شده (تصمیماتِ مالک/معمار، 2026-09-12)

> ⛔ **هیچ migration‌ای در این تسک ساخته/اجرا نشد و این سند هیچ شمارهٔ migration آینده‌ای را رزرو نمی‌کند.**
> آخرین migration = **`0020`** (`2026_09_09_0020_idempotency_clinic_scope.php`)؛ `0021` **وجود ندارد و تصویب نشده** است.
> **شماره و محتوای migration بعدی فقط زمانی تعیین می‌شود که اولین برشِ پیاده‌سازیِ migration‌دار تصویب شود.**
> همچنین **هیچ الزامی وجود ندارد که اولین PRِ پیاده‌سازی migration داشته باشد**: بخشی از این طراحی
> (contextِ per-job از طریقِ `payload_json` — §۱ ردیف ۱۵ و §A-2/A-1.1) **بدونِ هیچ تغییرِ schema‌ای** قابلِ پیاده‌سازی است.

| # | سوال | وضعیتِ بازبینی‌شده | چه چیزی **هنوز** بسته نشده |
|---|---|---|---|
| **۸-۱** | **مدلِ تنظیماتِ سطح نصب (installation-level settings)** — `backup.*`، `license.server_url`/`update.*`، `jobs.last_tick_at`، `retention.oplog_days` امروز در `cpms_settings` (با `clinic_id` NOT NULL) نشسته‌اند | 🟢 **APPROVED DIRECTION (در سطحِ اصل):** یک **انتزاعِ اختصاصیِ اپلیکیشن/repository روی WordPress Options API** برای پیکربندیِ **اسکالری که سطحِ نصب بودنش اثبات شود**؛ ترجیحاً **`autoload=no`** برای مواردِ غیرِ hot-path و حساس؛ **بدونِ `clinic_id = 0`** و **بدونِ Clinic مصنوعی**. **سابقهٔ بومیِ راستی‌آزمایی‌شده در همین مخزن:** `cpms_otp_pepper` — یک **secretِ سطحِ نصب** با `add_option(..., '', 'no')` ⇒ autoload=no (`OtpService.php:63`, `:521-527`)؛ `cpms_role_caps_override` (`RolesAndCapabilities.php:104`, `:277`, `:316`)؛ transientهای بررسیِ به‌روزرسانی (`UpdateService.php:83-103`) | ⛔ **انتقالِ فله‌ای بر اساسِ اشتراکِ prefix ممنوع** — semanticِ **هر کلید/خانواده** باید جداگانه راستی‌آزمایی شود. ⛔ وضعیتِ **ساخت‌یافتهٔ** license در جدول‌های اختصاصیِ سطحِ نصب (`cpms_license_install`/`cpms_license_state` — Migration `0008`) **نباید** صرفاً به‌خاطرِ معرفیِ Options جابه‌جا شود. ⏳ **فهرستِ دقیقِ کلیدهای مهاجرت‌یافته نهایی نشده.** ⏳ شکلِ دقیقِ **حسابرسی** نوشتن‌های سطحِ نصب (الگوی موجودِ `SETTING_UPDATE` در `Settings::set()` + redaction) در طراحیِ پیاده‌سازی تعیین می‌شود، نه اینجا |
| **۸-۲** | **semanticsِ retention برای `cleanup.oplog`** — نصب یا هر Clinic؟ | ✅ **RESOLVED (جهتِ طراحی): `retention.oplog_days` = نصب‌گسترده (installation-wide).** مبنای دوگانه: **(الف) رفتارِ قابلِ اجرایِ امروز** — `OpLogCleanupHandler.php:27-33` یک `DELETE` **سن‌محورِ کلِ جدول و بدونِ بُعد tenant** است و خودِ جدول ستون tenant ندارد؛ **(ب) شاهدِ تاریخیِ محصول** — `settings-reference.md:5` (تغییرِ v1.5) و `agent-guide.md:658` این کلید را یک سیاستِ **واحد** با پیش‌فرضِ ۹۰ روز «مصوبِ کارفرما» ثبت کرده‌اند، بدونِ هیچ مفهومِ per-Clinic ⇒ طبقهٔ `cleanup.oplog` = **S** (§۳-B-1 و §A-3) | ⚠️ **مرزِ صریحِ این تصمیم (ضدِ تعمیم):** فقط دربارهٔ `retention.oplog_days` است. سایرِ کلیدهای retention/purge — `notif.archive_days`، `hw.version_keep`/`hw.version_max_age_days`، `retention.audit_years`/`retention.record_years`، ثابتِ `Idempotency::cleanup(90)` و `RateLimiter::cleanup(2*86400)` — اگرچه امروز **به همان شکل** از `Settings`ِ Clinicِ bootstrap خوانده و بر کلِ جدول اعمال می‌شوند (راستی‌آزمایی‌شده)، **سطحِ نصب بودنشان از این تصمیم استنتاج نمی‌شود** و هرکدام نیازمندِ راستی‌آزمایی/تصمیمِ **جداگانه** است. 🔎 `retention.audit_years`/`record_years` امروز **فقط نمایشی** و بدونِ مجری‌اند (`SettingsAdmin.php:87-88`). اگر در آینده نیازِ محصولی به retentionِ per-Clinic پیدا شود ⇒ **بازطراحیِ tenant-awareِ صریح** لازم است (انتسابِ لاگ‌ها + `DELETE` تفکیک‌شده)، نه فقط تغییرِ مقدارِ همان Job |
| **۸-۳** | **فهرستِ نهاییِ واقعاً system-wide** | 🟢 **APPROVED DESIGN MODEL:** T/S/W به‌عنوان **مدلِ طراحی** تصویب شد و طبقه‌بندیِ بازبینی‌شدهٔ **۱۵ نوعِ واقعاً ثبت‌شده** در **§A-3** ثبت است — با تفکیکِ اجباریِ «رفتارِ جاریِ runtime» از «طبقهٔ هدف». **`NULL` هرگز به‌طور خودکار «system» نیست**؛ معنا فقط از **طبقهٔ ثبت‌شدهٔ همان نوع** می‌آید. منبعِ حقیقتِ registry = **`App::dispatcher()`/`RECURRING_JOBS`** (`App.php:1002+`, `:1045-1065`)، **نه** `background-jobs.md` (driftِ ثبت‌شده: `docs/drift-register.md` §۹-B) | ⏳ **ثبتِ اجراییِ** طبقه‌ها در registryِ کد (پیاده‌سازی) انجام نشده. ⚠️ طبقهٔ `backup.run` و `license.refresh` **مشروط** به §۸-۱ است. ⏳ قاعدهٔ سازگاریِ «T ⇒ غیرتهیِ معتبر / S,W ⇒ NULL» فقط در سطحِ طراحی است و هیچ گارْدِ اجرایی/تستی ندارد (RT-12) |
| **۸-۴** | **جزئیاتِ دقیق schema/ایندکس `operational_logs`** | ⛔ **schema/migration NOT AUTHORIZED — فقط «جهتِ بازبینی‌شده» ثبت می‌شود:** (۱) انتسابِ tenant برای **رویدادهای tenant-scoped** لازم است (E-1) و رویدادهای واقعاً سیستمی باید **صریحاً** سیستمی نشانه‌گذاری شوند؛ (۲) `clinic_id BIGINT UNSIGNED NULL` یک **کاندید** است (هم‌الگو با `cpms_audit_logs` پس از Migration `0016`: «NULL = سیستمی، نه ۱»)؛ (۳) **`location_id` اضافه نشود مگر نیازِ query/semanticِ اثبات‌شده** — امروز هیچ خواننده/UI/API‌ای وجود ندارد (§۱ ردیف ۱۱)؛ (۴) واقعیتِ ایندکسِ جاری: queryِ retention **فقط-`created_at`** است و ایندکسِ موجود (`level, created_at`) به‌دلیلِ leftmost-prefix آن را پوشش نمی‌دهد (E-7)؛ (۵) هر ایندکس باید **یک queryِ واقعیِ پشتیبان** داشته باشد — ایندکسِ speculative ممنوع | ⏳ **استراتژیِ دقیقِ FK (شاملِ رفتارِ `ON DELETE`)، شکلِ ایندکس‌ها، backfill و cutoff نهایی نشده و در این سند کانونی نمی‌شود.** به‌طورِ خاص، هر راهبردِ **«NULLِ دو-دوره‌ای»** (تفکیکِ legacyِ پیش از cutoff از رویدادِ سیستمیِ پس از آن) **فقط یک گزینهٔ در حالِ بررسی است، نه تصمیمِ نهایی** — انتخابِ آن به طراحیِ واقعیِ migration و شواهدِ recovery/upgrade وابسته است. ⏳ chunked-delete و شکلِ نهاییِ ایندکس = طراحیِ پیاده‌سازی (E-7) |
| **۸-۵** | **نیازهای واقعیِ گذارِ Job/schemaِ legacy — اندازه‌گیری‌شده** | ⏳ **OPEN — NOT MEASURED.** کدِ مخزن **نمی‌تواند** جمعیتِ واقعیِ صفِ production را ثابت کند و **هیچ عددی** در این سند ثبت نمی‌شود. آنچه از کد **قابلِ راستی‌آزمایی** است فقط «قابلیتِ provenance به تفکیکِ نوع» است (§۷ یادداشتِ ۲): از ۱۵ نوع، فقط `report.export` و `sms.send` payloadِ tenant-bearing دارند و ۱۳ نوعِ زمان‌بندی‌شده با **payloadِ تهی** enqueue می‌شوند | ⏳ اندازه‌گیریِ واقعی روی نصب‌ها (شمارش بر پایهٔ `type`/`status`، سنِ ردیف‌ها، شمارِ ردیف‌های دارای payloadِ قابلِ استناد) انجام **نشده**. نمونهٔ پرس‌وجو **با abstractionِ DBِ پروژه** نوشته می‌شود چون **prefixِ وردپرس محیط‌محور است** و مقدارِ ثابتِ `wp_` در سندِ معماریِ عالم‌گیر **ثبت نمی‌شود**: `$db->fetchAll('SELECT type, status, COUNT(*) AS n, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM ' . $db->table('cpms_jobs') . ' GROUP BY type, status')` |
| **۸-۶** | **آیا آبجکتِ مرجع می‌تواند Locationِ مشتق‌شده را در retry ناپایدار کند؟** | ✅ **RESOLVED برای انواعِ جاریِ Job.** **واقعیتِ راستی‌آزمایی‌شده:** `location_id` روی نوبت/ویزیت **snapshotِ زمانِ ساخت** است (`AppointmentRepository.php:85-89`, `:101+`؛ `VisitRepository.php:93+`)، **هیچ مسیرِ به‌روزرسانیِ معمولی ندارد** (`updateStatus()` فقط `STATUS_FIELDS` — `AppointmentRepository.php:121-133`)، **reschedule ردیفِ جدید می‌سازد** (`BookingService.php:522-547`) و **`cpms_locations` هیچ نویسندهٔ production‌ای ندارد**. **جهتِ مصوب:** اشتقاقِ Location از **آبجکتِ عملیاتیِ مرجعِ authoritative در زمانِ اجرا** (C-8) | ⚠️ **قیدِ بازنگری:** اگر semanticsِ آیندهٔ آبجکت اجازهٔ **تغییرِ Location** بدهد، همین قرارداد باید **صریحاً بازنگری و ثبت** شود — تغییرِ خاموشِ رفتارِ retry مجاز نیست. ⏳ **بازماندهٔ واقعیِ این سوال:** برای آبجکت‌هایی که **`location_id` ندارند** (`cpms_follow_ups`، `cpms_notifications`، `cpms_sms_messages`) **زنجیرهٔ اشتقاقِ Location هنوز تعریف/تصویب نشده** است ⇒ مربوط به `fu.reminder`/`notif.dispatch`/`sms.send` و باید با C-4 (fail-closed) همراه باشد |

**قاعدهٔ بستن:** هر موردِ بالا فقط با **تصمیم صریح مالک/معمار + ثبت در همین سند (و در صورت نیاز ADR مربوطه)**
بسته می‌شود. بسته‌شدنِ یک سوال، مجوزِ پیاده‌سازیِ کلِ این سند نیست؛ و **هیچ‌یک از وضعیت‌های 🟢/✅ بالا
به معنای «پیاده‌سازی‌شده» نیست** — وضعیتِ اجرایِ کلِ این سند همچنان **NOT IMPLEMENTED** است.

---

## ۹. مشخصاتِ تستِ **RED** — ‏RT-1..RT-14 (فقط ثبتِ نیازمندی — **پیاده‌سازی نشده**)

> ⛔ **هیچ تستی در این تسک نوشته/اجرا نشده است.** موارد زیر **پوششِ اجراییِ لازمِ آینده** است که هر
> پیاده‌سازیِ این طراحی **باید** ابتدا آن را **RED** و سپس GREEN کند (قاعدهٔ موجود پروژه:
> زنجیرهٔ RED→GREEN با run-id؛ بدون تضعیف تست).
> ✅ **همهٔ تست‌های موجود حفظ می‌شوند** — این فهرست جایگزین/حذف هیچ تست فعلی نیست و هیچ تستی
> skip/weaken/quarantine نمی‌شود.

| # | تست لازم (آینده) | انتظارِ کلیدی (assert) |
|---|---|---|
| **RT-1** | **رگرسیون تک‌Org/تک‑Clinic/تک‑Location** | توپولوژی A (AD-17) بدون تغییر رفتار کار می‌کند؛ scope به‌طور **صریح** resolve می‌شود (نه «ردیف اول»)؛ هیچ fallback خاموشی رخ نمی‌دهد |
| **RT-2** | **یک Clinic با دو Location و timezone متفاوت** — **دو دغدغهٔ تفکیک‌پذیر** | **(الف) محاسبهٔ تاریخ/زمانِ محلی:** هر Job/یادآوری از timezoneِ **Locationِ آبجکتِ خودش** استفاده می‌کند و نتایجِ دو Location **قابلِ تفکیک** است (C-1..C-4). **(ب) quiet hours:** ارزیابیِ `notif.quiet_hours_*` نیز بر پایهٔ timezoneِ **همان آبجکت** است، نه Clinicِ bootstrap — این دو مسیرِ کدِ جدا هستند (`ApptReminderHandler.php:104-112` در برابرِ `NotificationService.php:233-247`) و باید **جدا** assert شوند. 🔎 fixture: چون **هیچ مسیرِ محصولی برای ساختِ Location دوم وجود ندارد** (راستی‌آزمایی‌شده: `cpms_locations` هیچ نویسندهٔ production‌ای ندارد)، ردیفِ دوم باید **مستقیماً در DBِ تست** ساخته شود — همچنان «ردیفِ واقعی»، نه mock |
| **RT-3** | **دو Clinic با پیکربندی/credential‌های متفاوتِ SMS** — **ایزولاسیونِ واقعیِ پیکربندی و credential**، نه فقط provider ID | پیامِ Clinic B **همیشه** با پیکربندیِ B ارسال/ثبت می‌شود: `sms.provider` · قالب/`template_id` · `sms.sender` · `sms.advanced` · **و credentialِ sealedِ `sms.auth`ِ همان Clinic**. assert باید **هویتِ credential** (مثلاً `last4`/حسابِ providerِ مصرف‌شده) را بسنجد، نه فقط نامِ provider — زیرا کلیدِ Vault **سطحِ نصب** است و رمزگشاییِ credentialِ Clinicِ اشتباه **بی‌صدا موفق می‌شود** (§۵-D-2). هیچ‌گاه با پیکربندی A ارسال/ثبت نمی‌شود (D-1..D-3) |
| **RT-4** | **اجرای cron/background بدون WP کاربر** | در نبودِ `wp_get_current_user()`/REST context، Jobهای tenant-scoped **فقط** از contextِ persist‌شدهٔ خودشان کار می‌کنند؛ نبودِ context ⇒ **fail-closed** (A-1.6, A-1.9). 🔎 رفتارِ **جاری** برای مقایسه: در نصبِ چندکلینیکیِ بدونِ scope، `App::dispatcher()` هنگامِ **ساخت** به `settings()`→`App::scope()` می‌رسد و **کلِ tick** با `CLINIC_SCOPE_REQUIRED` می‌افتد (`App.php:1002+`, `:839-849`, `:858`) — یعنی fail-closed ولی **خشن/سراسری**؛ هدفِ طراحی، fail-closedِ **per-job** است (isolation در RT-14 سنجیده می‌شود) |
| **RT-5** | **کاربر با membership چندگانه نباید بر scopeِ Job اثر بگذارد** | عضویت چند Clinicِ یک کاربر، scopeِ هیچ Jobی را **تغییر/مبهم** نمی‌کند؛ membership ≠ contextِ اجرا (A-1.6 + AD-17 قاعدهٔ ۴). شاهدِ مرز: `TrustedClinicEstablisher` membership-based و **فقط مسیرِ درخواست** است و Jobها هرگز نباید به آن رجوع کنند |
| **RT-6** | **اجرای متوالیِ `A → B → A` بدون نشتِ stateِ سطحِ process** | هر سه گام نتایجِ درستِ Clinicِ خودش را می‌دهد؛ **هیچ** state مشترکی از گامِ قبل باقی نمی‌ماند. پوششِ **صریحِ همهٔ کش‌های سطحِ process** که امروز وجود دارند: `Settings::$cache` (`Settings.php:125`, `:307`) · `App::$settings` (`App.php:144`, `:839-849`) · `App::$dispatcher` (`:146`, `:1002+`) · `App::$smsService` (`:149`, `:756`) · **registry/providerهای SMS** (`App::providers()` — `:679`؛ از `settings()->get('sms.generic')` ساخته و cache می‌شود ⇒ خطرِ freezeِ پیکربندیِ cross-clinic) (A-1.5, A-1.7, D-1) |
| **RT-7** | **Semanticsِ retry حفظ می‌شود** | تلاشِ مجدد **همان Clinic** را می‌بیند و scope در retry **باز-حدس زده نمی‌شود** (A-1.8). دربارهٔ Location: طبقِ جهتِ مصوبِ §۸-۶/C-8، Location از **آبجکتِ عملیاتیِ مرجعِ authoritative در زمانِ اجرا** مشتق می‌شود؛ تست باید هم‌زمان **تغییرناپذیریِ snapshotِ جاری** را assert کند (`location_id` در نوبت/ویزیت مسیرِ update ندارد و reschedule ردیفِ جدید می‌سازد) تا هر تغییرِ آیندهٔ semantics **آشکار** شود، نه خاموش |
| **RT-8** | **نبودِ tenant context ⇒ fail-closed** | Job اجرا نمی‌شود؛ خطای صریح + Operational Log؛ **هیچ fallback** به «تنها/اولین Clinic» رخ نمی‌دهد (A-1.9..A-1.11). **بدونِ side-effectِ جزئی:** پیش از reject، **هیچ** ردیفِ `sms_messages`/notification/file خروجی نوشته یا ارسال نمی‌شود و هیچ وضعیتِ ردیفی نیمه‌کاره جلو نمی‌رود. انتسابِ خودِ رویدادِ شکست باید **صریحاً سیستمی/نامعلوم** باشد (نه `0`، نه Clinicِ حدسی) — E-1 |
| **RT-9** | **timezoneِ عملیاتیِ Location در همهٔ مسیرهای زمانیِ راستی‌آزمایی‌شده** | **(الف)** زمان‌بندیِ ارسالِ یادآوری بر پایهٔ timezoneِ **Locationِ نوبت** (نه Clinic، نه `Asia/Tehran` پیش‌فرض) — با دو نوبت در دو Locationِ متفاوت (C-1, C-5). **(ب) `slots.generate`:** محاسبهٔ «امروز»/افقِ تولیدِ اسلات بر پایهٔ **تاریخِ محلیِ Location** سنجیده شود، نه `gmdate('Y-m-d')`ِ UTC (C-9؛ `SlotsGenerateHandler.php:29-30`). **(پ) `visits.no_show`:** مقایسهٔ grace/سررسید بر پایهٔ **زمانِ محلیِ نوبت** سنجیده شود، نه cutoffِ UTC روی مقادیرِ محلیِ `slot_date`/`slot_time` (C-10؛ `VisitService.php:485-491` + `VisitRepository.php:330-342`) |
| **RT-10** | **Jobِ legacyِ مبهم هرگز به یک Clinic «حدس» زده نمی‌شود** | ردیفِ بدون provenance قطعی ⇒ **اجرا نمی‌شود** (hold/reject + log)؛ هیچ `clinic_id` حدسی (شامل `0`/`1`/«تنها Clinic») نوشته نمی‌شود (F-2, F-4, F-5). **evidence-driven می‌ماند:** هیچ backfill/normalize‌ای بدونِ اندازه‌گیریِ واقعیِ §۸-۵ (که **NOT MEASURED** است) و بدونِ provenanceٔ قطعیِ §۷ مجاز نیست؛ الگوی literal-normalizationِ Migration `0020` **تکرار نمی‌شود** (§۷ یادداشتِ ۱) |
| **RT-11** | **انتسابِ tenant در لاگِ عملیاتی** — **دو بخشِ ناهمزمان** | **(الف) امروز قابلِ آزمون — سمتِ نوشتن:** رویدادِ tenant-scoped با انتسابِ درستِ Clinic ثبت می‌شود و رویدادِ سیستمی **صریحاً** سیستمی است (نه `0`، نه Clinic جعلی) (E-1). **(ب) موکول به وجودِ خواننده:** enforceِ مستقلِ scope در هر خواننده/UI/API (E-2/E-4) **فقط زمانی** نوشته می‌شود که یک خوانندهٔ واقعی معرفی شود — امروز **هیچ خوانندهٔ production‌ای وجود ندارد** (§۱ ردیف ۱۱)، پس این بخش به‌صورتِ «placeholderِ مشروط» ثبت می‌شود و **حذف/ضعیف** نمی‌شود |
| **RT-12** | **هر نوع Jobِ ثبت‌شده طبقهٔ scope صریح دارد** | گارْدِ ثبت/اجرا روی **۱۵ نوعِ واقعیِ ثبت‌شده در `App::dispatcher()`/`RECURRING_JOBS`** (§A-3): نوعِ بدونِ طبقهٔ scope ⇒ reject (نه حدس در زمانِ اجرا)؛ نوعِ ناشناخته ⇒ fail-closed (A-1.10, §۲-A-2). **قراردادِ سازگاریِ داده نیز سنجیده شود:** `T ⇒ clinic_id` غیرتهی و اعتبارسنجی‌شده · `S ⇒ NULL` · `W ⇒ NULL` با انتسابِ per-row از آبجکتِ مرجع (A-1.12) |
| **RT-13** | **پیکربندیِ سطحِ نصب بدونِ contextِ Clinic حل می‌شود** *(افزوده از بازبینیِ 2026-09-12)* | کلیدهای سطحِ نصب (طبقِ جهتِ §۸-۱) **بدونِ** هیچ scope کلینیکی خوانده/نوشته می‌شوند و در cron/CLI در دسترس‌اند؛ یک instanceِ `Settings`ِ Clinic-scoped **نمی‌تواند** آن‌ها را بخواند/بنویسد؛ و assert شود که **هیچ** ردیفِ `clinic_id = 0` و **هیچ** Clinic مصنوعی/سنتتیکِ «System» ساخته نشده است (§۳-B-2). 🔎 تا وقتی §۸-۱ پیاده نشده، این تست **RED/منتظرِ پیاده‌سازی** است و هیچ رفتاری را «موجود» فرض نمی‌کند |
| **RT-14** | **ایزولاسیونِ شکستِ per-job درونِ یک tick** *(افزوده از بازبینیِ 2026-09-12)* | خطای fail-closedِ **یک** Job (scopeِ مخدوش/ناقص، نوعِ بدونِ طبقه، provenanceٔ مبهم) **نباید** باعث شود Jobهای **بی‌ارتباطِ** همان tick اجرا نشوند یا کلِ tick متوقف شود؛ Jobِ خطادار با وضعیت/خطای صریح ثبت می‌شود و بقیه ادامه می‌یابند. 🔎 این خواسته **رفتارِ امروز نیست** (خطای ساختِ dispatcher کلِ tick را می‌اندازد — RT-4) و یک **الزامِ هدف** است |

**یادداشتِ اجراییِ آینده (نه اکنون):** این موارد عمدتاً **Integration** هستند (DB واقعی + Migration +
صف واقعی) و باید با **ردیف‌های واقعیِ Clinic/Location دوم** ساخته شوند — **mock-isolation ممنوع**
(قاعدهٔ موجودِ تست‌پلن فاز ۲: fixture ردیف واقعی دوم، نه mock).
**راستی‌آزماییِ مهم برای ساختِ fixture:** امروز **هیچ مسیرِ محصولی** برای ساختِ Clinic دوم
(تنها `INSERT` روی `cpms_clinics` همان seedِ `0001:736` است) یا Location دوم
(`cpms_locations` هیچ نویسندهٔ production‌ای ندارد) وجود ندارد ⇒ fixtureها باید ردیف‌ها را
**مستقیماً در DBِ تست** بسازند. این **دلیلِ اضافهٔ** صحتِ RT-2/RT-3/RT-6/RT-13 است، نه ضعفِ آن‌ها.
**هیچ تستی در این تسک نوشته/اجرا نشد** و هیچ تستِ موجودی skip/weaken/quarantine نشده است.

---

## ۱۰. مرزها و ممنوعیت‌های این سند (Non-goals)

- ⛔ **پیاده‌سازی کد محصول** — انجام نشد و مجاز نیست.
- ⛔ **نوشتن/اجرای تست** — فقط مشخصات (§۹)؛ هیچ تستی اضافه/تغییر/حذف نشد.
- ⛔ **تغییر workflow/CI** — هیچ تغییری در `.github/workflows` داده نشد؛ هیچ گیتی تضعیف نشد.
- ⛔ **تغییر schema / ساختِ هر migration جدید** — آخرین migration `0020` باقی است و **هیچ شمارهٔ migration آینده‌ای در این سند رزرو نشده است**.
- ⛔ **الزامِ وجودِ migration در اولین PRِ پیاده‌سازی** — چنین الزامی وجود ندارد؛ بخشی از این طراحی (contextِ per-job از `payload_json`) بدونِ تغییرِ schema قابلِ پیاده‌سازی است (§۸-۱/§۱ ردیف ۱۵).
- ⛔ **شروع یا پاس‌کردن Phase 2 End Gate** — End Gate **تعریف/شروع نشده** است (برچسب تاریخی «۲۶بندی» فهرست پذیرش نیست).
- ⛔ **شروع Phase 3 (Role & Access Control) / Phase 4 (Master Data) / Phase 17 (Performance)** — همه **NOT STARTED**.
- ⛔ **هرگونه ادعای عملکرد/NFR/مقیاس‌پذیری/آمادگی تجاری** — C10 **فقط** در دامنهٔ محدودشدهٔ بازبینی شواهد بسته شد (`c10-performance-evidence.md`).
- ⛔ **`clinic_id = 0` / `clinic_id = 1` / `location_id = 1` / «اولین ردیف» / وضعیت سراسری Clinic / Clinic مصنوعی / فرض هویتی `Asia/Tehran`** — همه ممنوع (AD-13 + AD-17 §۸ + §۳-B-2 همین سند).
- ⛔ **تضعیف ایزولاسیون tenant به بهانهٔ سادگی/عملکرد** — اولویت: صحت/امنیت tenant.

---

## ۱۱. Provenance و ثبت تغییرات

| تاریخ | رویداد |
|---|---|
| 2026-09-12 | ثبتِ اولیهٔ این سند به‌عنوان **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**، شامل: وضعیت راستی‌آزمایی‌شدهٔ جاری (§۱)، قواعد A..F (§۲–§۷)، شش سوالِ بازِ «pre-`0021`» (§۸) *(اصطلاحِ تاریخیِ همان نسخه — اکنون **هیچ شمارهٔ migration‌ای رزرو نیست**؛ ردیفِ بعدی را ببینید)* و مشخصاتِ ۱۲ تستِ RED (§۹). **فقط مستندات** — بدون کد/تست/workflow/schema/migration. مبنای ارجاعات: ADR-0031 (AD-04/08/13/14/15 + AD-17 §۸)، ADR-0016، `background-jobs.md`، `phase0.5-target-model.md`، `c6-deferred-boundaries.md`، `phase2-state.md` §C7/§C8، `project-current-state.md` §D |
| 2026-09-12 | **اصلاحاتِ مستنداتیِ پس از بازبینیِ مستقلِ معماری (حکمِ مالک: `B — NEEDS SMALL DOCUMENTATION CORRECTIONS`) — بدونِ ادغام، بدونِ Ready، بدونِ کد/تست/schema/migration:** ‏**C-1 (must-fix)** تصحیحِ گزارهٔ نادرستِ §۵-D-2 دربارهٔ ذخیره‌سازیِ credential (واقعیتِ جاری: sealed/AES-256-GCM در `cpms_settings.sms.auth` per-Clinic مطابقِ `ADR-0025:44-45`؛ کلیدِ Vault سطحِ نصب ⇒ رمزنگاری مرزِ tenant نیست) · **C-2** اصلاحِ ترتیبِ شمارهٔ ردیف‌های §۱ · **C-3** تکمیلِ مسیرهای ست‌شدنِ `ScopeContext` (wp-admin) · **C-4** ثبتِ سابقهٔ کاریِ `report.export` (ردیف ۱۵ §۱) · **C-5** تصحیحِ طبقهٔ `appt.reminder` از T به **W** (§۲-A-2 + §A-3 جدید) · **C-6** ثبتِ منبعِ سومِ timezone (`setup.clinic.timezone`) و قطعِ اتصالِ راستی‌آزمایی‌شدهٔ آن با `clinicTimezone()` · **C-7** افزودنِ `slots.generate` و `visits.no_show` به دامنهٔ remediationِ timezone (C-9/C-10) · **C-8** ثبتِ واقعیتِ ایندکس/retentionِ `operational_logs` (E-7) · **C-9** تعیینِ منبعِ حقیقتِ registry = `App::dispatcher()` و ثبتِ driftِ `background-jobs.md` (`drift-register.md` §۹-B) · به‌روزرسانیِ §۸ بر پایهٔ تصمیماتِ مالک (‏۸-۱ 🟢 جهتِ مصوب/فهرستِ کلیدها OPEN · ۸-۲ ✅ installation-wide · ۸-۳ 🟢 مدلِ طراحیِ مصوب · ۸-۴ ⛔ schema NOT AUTHORIZED · ۸-۵ ⏳ NOT MEASURED · ۸-۶ ✅ resolved برای انواعِ جاری + زنجیرهٔ اشتقاقِ آبجکتِ بدونِ `location_id` OPEN) · افزودنِ **RT-13/RT-14** و تدقیقِ RT-2/3/4/6/7/8/9/10/11/12 · **حذفِ هر عبارتِ رزروکنندهٔ شمارهٔ migration** |

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
