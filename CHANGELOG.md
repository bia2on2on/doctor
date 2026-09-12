# Changelog — CPMS (Clinic Practice Management System)

تمام تغییرات مهم پروژه در این فایل ثبت می‌شود. قالب: [Keep a Changelog](https://keepachangelog.com/)؛ نسخه‌بندی: [SemVer](https://semver.org/).

## [Unreleased] — ثبت پذیرش/بستن رسمی C9 با تصمیم صریح مالک (فقط مستندات — 2026-09-11)

**وضعیت: ‏C9 = CLOSED.** پیاده‌سازیِ محدودشدهٔ C9 از طریق **PR #23 — MERGED** در
چک‌پوینت **`7146d5b`** به `main` یکپارچه شد و سپس **مالک به‌طور صریح پذیرش/بستن رسمیِ
دامنهٔ محدودشدهٔ C9 را تصویب کرد** (2026-09-11، **پس از** ادغام). این ورودی فقط
مستندات است: هیچ کد محصول، تست، workflow، تنظیم WPCS/PHPStan، schema یا migrationای
تغییر نکرد.

### شواهد یکپارچه‌سازی (راستی‌آزمایی‌شدهٔ مستقل از GitHub/`origin/main`)

- ‏`origin/main` = **`7146d5bb4167d2ac333000188d404c2aa977b817`** (چک‌پوینت ادغام‌شدهٔ جاری).
- ‏**PR #23 = MERGED** در 2026-09-11T19:35:54Z؛ head تصویب‌شده = `c92737bb0a30fbdf13d804a8dcdffa522fd556ae`؛
  merge commit = `7146d5bb…`؛ **والدین دقیقاً** `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54`
  (mainِ پیش‌از‌ادغام) + `c92737bb…` (headِ PR #23) — راستی‌آزمایی‌شده هم با `git cat-file -p`
  و هم با `parents` از GitHub API.
- ‏**PR #21 و PR #22 هر دو MERGED** (به‌ترتیب `b19930fe` و `0fd5c27`) — فقط مستندات.
- ‏**هر ۴ گیت پس‌از‌ادغام روی همان SHA `7146d5b` موفق** بودند: CI **`34639699703`** ·
  Real WordPress Acceptance **`34639699751`** · Pilot/Staging Readiness Gate **`34639699635`** ·
  Closure Gate **`34639699639`** — همه `status: completed` + `conclusion: success`؛ مجموعاً
  **۱۹ check run روی `7146d5b`، همه success** و هیچ check معلقی وجود ندارد.

### مرزِ دقیقِ این پذیرش/بسته‌شدن — فقط کارِ محدودشدهٔ ادغام‌شده از طریق PR #23

1. دو یافتهٔ A/Lowِ i18n که **خودِ C7** معرفی کرده بود؛
2. ترمیم در **مرز ارائه/‏REST**؛
3. **حفظِ** `code`/status/`data`ِ پایدار و ماشین‌خوان و رفتارِ پیش‌فرضِ فارسی؛
4. **پذیرشِ قاعدهٔ رو‌به‌جلوی لایه‌بندیِ بومی‌سازی** (`project-current-state.md` §I‑1).

### Notes — صریحاً ادعا **نمی‌شود**

- رفعِ **همهٔ** بدهیِ تاریخیِ i18n.
- فراهم‌شدنِ پشتیبانیِ کاملِ انگلیسی یا کامل‌شدنِ جهانی‌سازی (globalization).
- translation‑ready بودنِ **همهٔ** رشته‌های wp‑admin.
- رفعِ بدهیِ تاریخیِ i18n در Domain/Application (۱۳ نقطهٔ Domain و ۱۹ نقطهٔ Application
  دست‌نخورده و **بدهیِ تحمّل‌شده** باقی می‌مانند — تحمّل، نه الگو).
- **بسته‌شدنِ Phase 2** — ‏Owner Roadmap Phase 2 همچنان **IN PROGRESS** است.
- ارتقای تکنیکِ **تطبیقِ دقیقِ پیامِ منبع** (‏exact‑source‑message match) به معماریِ
  ترجیحیِ بلندمدت: این تکنیک یک سازگاریِ **گذرا و محدودشده** است و با این بسته‌شدن
  به الگو تبدیل نمی‌شود.
- ‏**C10 شروع نشده و مجاز نیست.** عملکرد: **شواهد benchmark واقعی اما محدودِ Staging موجود
  است** — گامِ قابل‌اجرای `ab` (‏p50/p95/p99 + RPS + error rate) در job ‏`staging-gate` از
  ‏`.github/workflows/pilot-gate.yml` و نتایجِ اجراشده در `report-pilot-gate.md` §8. اما آن
  محیط **محیط مرجع/تجاریِ کانونی نیست** و همان گزارش **صریحاً ادعا نکرد** اهدافِ مصوب آنجا
  پاس شده‌اند (§8: «اهداف مصوب (هیچ‌کدام پاس‌شده اعلام نمی‌شوند)»؛ داوری Quality Gate عملکرد
  به بنچمارکِ سرور مرجع موکول است — Runbook §12.3، ‏BLOCKED_BY_ENVIRONMENT). پس **عملکردِ
  محیط مرجع/تجاری در برابر آستانه‌های NFR همچنان اندازه‌گیری‌نشده/اعتبارسنجی‌نشده است**، و
  همچنین هدفِ سربارِ صفحات عمومی (‏p95 < 100ms)، اندازه‌گیریِ تعداد کوئری/‏N+1، حافظه، و بارِ
  معنادارِ چندکلینیکی. **هیچ عددِ Staging اثباتِ عملکردِ Production نیست.**
- ‏**Phase 3 آغاز نشده.** ‏**Migration `0021` نه ساخته و نه تصویب شد** (آخرین migration = `0020`).
- ‏**PR #13 دست‌نخورده** باقی ماند (OPEN + DRAFT تاریخی).

### Changed (فقط مستندات)

- ‏`docs/phase-reports/phase2-state.md` — ثبتِ بسته‌شدن رسمی C9، چک‌پوینت `7146d5b`،
  گیت‌های پس‌از‌ادغام همان SHA، و سبزیِ تأییدشدهٔ Integration روی head `c92737b`.
- ‏`docs/project-current-state.md` — چک‌پوینت یکپارچه، جدول وضعیت ادغام (ردیف‌های PR #23/#22)،
  و رکورد پذیرش رسمی C9 در §K.
- ‏`docs/handoff/phase2-c6-to-next-agent.md`، ‏`docs/governance/project-phase-taxonomy.md`،
  ‏`docs/README.md`، ‏`docs/roadmap/roadmap.md`، ‏`CHANGELOG.md` — هم‌ترازی تداوم.

## [Unreleased] — C10 (Performance review) — بستهٔ شواهد عملکرد فاز ۲ (فقط مستندات)

**وضعیت: بازبینی شواهد C10 کامل / واجد شرایط فنی برای بستن — بستن رسمی مالک در انتظار (PENDING).**
C10 رسماً بسته نشده است. این ورودی فقط مستندات است؛ **بدون هیچ تغییر کد محصول/تست/workflow/schema/migration.**

### Added (docs)
- سند جدید اختصاصی **`docs/phase-reports/c10-performance-evidence.md`** — بستهٔ شواهد عملکردِ داخلیِ LEVEL-2 فاز ۲، محدود به فوندیشن Multi-Clinic: مرزِ صریح با Owner Phase 17 (Performance — NOT STARTED)؛ ثبت شواهد اجراشدهٔ واقعی اما محدودِ Staging (اهداف `performance-baseline.md`؛ نتایج تاریخی `report-pilot-gate.md` §8 — **مقدم بر تغییرات Multi-Clinic ‏C4..C7؛ اثبات وجود/اجرای ابزار، نه عملکرد کد جاری**؛ گام `ab` در job `staging-gate` از `pilot-gate.yml`؛ گیت‌های Pilot/Staging سبز روی کد جاری — جدیدترین روی `bd2634a`، run ‏`34651627290` — **فقط اجرای موفق استپ؛ اعداد جاری قابل بازیابی نیستند و موفقیت workflow ≠ پاس‌شدن آستانه‌های latency**)؛ فهرست صریح NOT MEASURED (عملکرد محیط مرجع/NFR، سربار صفحات عمومی p95<100ms، شمارش کوئری/N+1، حافظه، بار معنادار چندکلینیکی) به‌عنوان شواهد آینده (عمدتاً Phase 17 / اعتبارسنجی انتشار) — **نه بلوکر C10**؛ خلاصهٔ بازبینی static محدود فاز ۲ (**هیچ نقص عملکردی VERIFIED نیازمند remediation نیافت**؛ بازرسی کد ≠ عملکرد اندازه‌گیری‌شده؛ عدم وجود سراسری N+1 ادعا نمی‌شود)؛ ریسک‌های ثبت‌شدهٔ مبتنی بر شواهد (مشاهدهٔ گذرای Deadlock روی `_transient_cpms_migrate_lock` در c=100 → Backlog؛ کلیدهای کش آینده tenant-aware)؛ و حکم برچسب تاریخی «End Gate (۲۶بندی)» — **هیچ فهرست کانونیِ تعریف‌شدهٔ ۲۶بندی در مخزن جاری یا تاریخچهٔ ردیابی‌شدهٔ بازرسی‌شدهٔ Git یافت نشد**؛ بازسازی ۲۶ بند انجام نمی‌شود؛ منشأ عدد به‌عنوان provenance ثبت نمی‌شود.
- به‌روزرسانیِ حداقلی پیوستار (بدون بازنویسی تاریخچه): `phase2-state.md` (§C10 جدید + Queue + چک‌پوینت جاری `bd2634a` و گیت‌های پس‌از‌ادغام آن)، `project-current-state.md` (بولت وضعیت C10)، `project-phase-taxonomy.md` (T4/crosswalk/بولت وضعیت)، `roadmap.md` (ردیف Phase 2).

### Notes
- **بدون Migration** (`0001..0020` ثابت؛ `0021` ساخته/تصویب نشد). **Phase 3 / Phase 17 / End Gate فاز ۲ شروع نشدند.** **Phase 2 = IN PROGRESS.** **PR #13 دست‌نخورده.**
- هیچ بهینه‌سازی عملکردی پیاده نشد؛ هیچ ابزار benchmark افزوده نشد؛ هیچ load test جدیدی اجرا نشد؛ **هیچ آستانهٔ NFR پاس‌شده اعلام نشد** — شواهد Staging اثبات عملکرد Production نیست.

## [Unreleased] — C9 (i18n) — پیاده‌سازی محدودشده: translation-ready شدن دو پیام انسانیِ معرفی‌شده توسط C7 در مرز REST

**وضعیتِ جاری: ادغام‌شده در `main` و C9 = CLOSED (ورودیِ بالای همین فایل را ببینید).**
**وضعیتِ تاریخیِ زمانِ تحویل (حفظ‌شده، بازنویسی‌نشده):** در لحظهٔ تحویل، پیاده‌سازیِ
محدودشدهٔ C9 کامل بود و حداکثر وضعیتِ مجازِ پیش‌از‌ادغام
«پیاده‌سازیِ محدودشده کامل / READY FOR ARCHITECT MERGE REVIEW» بود و **C9 بسته نبود**؛
بستن رسمی به‌عنوان یک تصمیمِ تداومیِ **پس‌از‌ادغام** — فقط بر پایهٔ SHA واقعیِ
ادغام‌شده در `main` + سبزیِ گیت‌های پس‌از‌ادغامِ همان SHA — محفوظ گذاشته شده بود.
آن شرایط سپساً برآورده و مستقل راستی‌آزمایی شد و مالک رسماً پذیرفت/بست.
تحویل: **PR #23** (base = `0fd5c27`) — **اکنون MERGED** در `7146d5b`.

### دامنهٔ محدودشده (دقیقاً دو یافته — هر دو A, Low)

هر دو رشتهٔ انسانی توسط **خودِ C7** معرفی شده‌اند (نه بدهی قدیمی):

| # | یافته | کامیت معرفی‌کننده | بستهٔ کاری | کد | HTTP | data |
|---|---|---|---|---|---|---|
| ۱ | `ScheduleService::requireClinicianForTrustedClinic()` — ‏`src/Application/Booking/ScheduleService.php:389` | `04a7a79ecff11ccc00a80b9fb68a1cc2d1167cf4` | C7‑S5 (PR #20) | `CLINIC_SCOPE_REQUIRED` | 400 | `[]` |
| ۲ | `FinanceService::requireTrustedClinicId()` — ‏`src/Application/Finance/FinanceService.php:1111` | `c4cf90240413f4fdf72682b5071b36da89fb37a5` | C7‑S3 (PR #20) | `CLINIC_SCOPE_REQUIRED` | 400 | `[]` |

اثبات provenance: ‏`git log -S'<literal>'` برای هر دو **دقیقاً یک کامیت** برمی‌گرداند
(رشته یک‌بار اضافه و هرگز تغییر نکرده) و `git blame` همان SHA را تأیید می‌کند. برای
مورد ۲، نسخهٔ پیش از C7 ‏(`explicitTrustedClinicId(): ?int`) فقط `null` برمی‌گرداند و
**هیچ پیام انسانی نداشت**. سه محلِ دیگرِ `CLINIC_SCOPE_REQUIRED` در `src/`
(‏`ExportService:398` ← `f2c0ca6`/C6، ‏`SystemClinicResolver:44` ← `1f8b36d`/P2‑B،
‏`TrustedClinicEstablisher:63` ← `f88fcdc`/C6) **پیش از C7** هستند و عمداً دست نخوردند.

### Changed

- **`src/Rest/ScheduleController.php`** — در همان مرز موجودِ `wrap()`: فقط واریانتِ
  واجدِ شرایط (`errorCode === 'CLINIC_SCOPE_REQUIRED'`) با msgid **literal** و دامنهٔ
  `cpms` translation‑ready شد. در این مرز تنها منبعِ آن کد همان گارد C7‑S5 است، پس خودِ
  کد برای تشخیص کافی است.
- **`src/Rest/FinanceController.php`** — در همان مرز موجودِ `staff()` (شاخهٔ
  `FinanceException`): فقط واریانتِ واجدِ شرایط، با **کدِ پایدار + تطبیقِ بایت‌دقیقِ خودِ
  پیامِ منبع** (`errorCode === 'CLINIC_SCOPE_REQUIRED' && $message === '<همان literalِ C7>'`).
  این شرط **ضروری** است: `FinanceService::trustedClinicId()` (مسیر `listServices`/`summary`)
  همان کد را از `SystemClinicResolver` با پیامِ **پویا** (شاملِ تعداد Clinicها) و
  `data['clinic_count']` باز‌نگاشت می‌کند و باید دست‌نخورده عبور کند. از **شکلِ `data`
  به‌عنوان شناسه استفاده نشد**: ‏`[]` مقدارِ پیش‌فرضِ سازندهٔ `FinanceException` است، هیچ
  قراردادِ معناییِ مستندی ندارد (‏ADR‑0019 و `error-codes.md` هیچ معناشناسی‌ای برای `data`
  تعریف نکرده‌اند)، و یک `ScopeRequiredException` با همین کد و `data` خالی از قبل در
  `TrustedClinicEstablisher` موجود است. تطبیقِ پیام **fail-safe** است: اگر literalِ سرویس
  عوض شود شرط برقرار نمی‌شود و پیامِ واقعیِ سرویس دست‌نخورده عبور می‌کند و assertionِ
  ضدِّواگراییِ A2 واگرایی را قرمز می‌کند. این **یک تکنیکِ گذرا و محدودشده** است، نه
  معماریِ مطلوبِ بلندمدت. شاخهٔ `VisitException` بدون تغییر است.

هیچ فایل Domain یا Application تغییر نکرد؛ هیچ `phpcs:ignore` اضافه نشد؛ هیچ migration
یا تغییر schema؛ هیچ ابزار/workflow جدید CI؛ هیچ تغییری در پیکربندی WPCS.

### Added (tests)

- **`tests/Integration/C9RestMessageI18nTest.php`** (۶ تست) — فقط رفتارِ بیرونیِ قابل
  مشاهده (پاکت REST: ‏`code`/`message`/`data`/HTTP + اثرِ سمت DB). هیچ تستی اجرا شدنِ
  gettext داخل `ScheduleService`/`FinanceService` را assert نمی‌کند.
  - **A — پیش‌فرض:** ‏`code` = `CLINIC_SCOPE_REQUIRED`، ‏`400`، ‏`data` دقیقاً
    `{status: 400}`، و `message` فارسیِ decode‌شده بایت‌به‌بایت برابر خروجی جاری
    (۱۰۲ بایت برای Schedule؛ ۱۵۶ بایت با **ZWNJ/U+200C** برای Finance) و برابر
    `getMessage()` همان استثنای سرویس واقعی.
  - **B — ترجمه:** با فیلتر core ‏`gettext_cpms` در مرز REST، فقط `message` عوض می‌شود؛
    ‏`code`/`status`/`data` یکسان می‌مانند.
  - **C — تفکیک‌کنندهٔ منفی:** واریانتِ resolver با `data['clinic_count']` و پیام پویا
    دست‌نخورده عبور می‌کند و literal/ترجمهٔ C7 جای آن نمی‌نشیند (نگهبانِ نگاشتِ
    صرفاً code‑keyed).
  - **D — امنیت:** پاکت ۴۰۴ غیرافشایِ `CLINIC_NOT_FOUND` («پزشک یافت نشد») برای پزشکِ
    Clinic خارجی بایت‌به‌بایت حفظ می‌شود و هیچ ردیفی درج نمی‌گردد (fail‑closed).

**زنجیرهٔ RED→GREEN (شواهد اجرایی = GitHub Actions؛ PHP در sandbox روی PATH نبود):**
دقیقاً همان دستورِ Integration ‏(`php vendor/bin/phpunit --no-configuration
--bootstrap tests/integration-bootstrap.php tests/Integration`). روی کامیتِ فقط‑تست
`81d4e8d` (run `34630765255`) نتیجه **`Tests: 674, Assertions: 4571, Failures: 2`** بود —
و آن دو شکست **دقیقاً** دو تستِ «translation‑readiness» (بخش B) بودند؛ چهار تست دیگر
(A/C/D) سبز. یعنی RED واقعی و محدود به همان شکافِ مورد نظر بود، بدون تضعیف fixture و
بدون تغییر رفتارِ امنیتیِ مورد انتظار.

### معماری رو‌به‌جلو (قاعدهٔ دائمی)

- **Domain** هیچ وابستگیِ مستقیمِ جدیدی به i18n/ارائهٔ WordPress **نمی‌گیرد**؛ کدِ جدیدِ
  Domain نباید `__()` یا معادلِ آن را صدا بزند.
- **کدِ کسب‌وکاری/سرویسیِ Application** به‌صورت الگوی پیش‌فرض **coupling مستقیمِ جدیدی**
  با i18n/ارائهٔ WordPress اضافه نمی‌کند.
- **بومی‌سازیِ متنِ انسانی در مرزهای ارائه/adapter** انجام می‌شود (REST controllerها،
  wp‑admin) — همان الگوی مستقر `RestClinicContext::toError()` (از C6) و
  `ClinicianAdminPage` (از C7‑S4).
- **`code`/HTTP status/دادهٔ ساخت‌یافتهٔ ماشین‌خوان معتبر (authoritative) می‌مانند** و
  برای کلاینت‌ها تغییر نکردند؛ هیچ تغییرِ قرارداد API لازم نشد
  (`api-contract.md` §۰ و `ADR-0019` بایت‌های `message` را پین نکرده‌اند؛ `NFR-UI-4`
  «i18n‑ready با fa پیش‌فرض» را می‌خواهد).

### بدهیِ تاریخیِ تحمّل‌شده (عمداً اصلاح نشد)

- ‏**۱۳** فراخوان مستقیم `__()` در Domain، محصور در **۲ فایل از ۴۲ فایل**:
  ‏`Domain/Membership/MembershipException.php` (۷) و
  ‏`Domain/Patients/PatientIdentityException.php` (۶) — introduced در C4/C5.
- ‏**۱۹** فراخوان مستقیم `__()` در Application، محصور در **۲ فایل**:
  ‏`Application/Membership/MembershipService.php` (۱۳) و
  ‏`Application/Patients/PatientIdentityService.php` (۶).

این‌ها **بدهیِ تاریخیِ تحمّل‌شده** هستند، نه سابقهٔ قابلِ توسعه. پاکسازیِ انبوه در دامنهٔ
C9 نیست و انجام نشد.

### نکتهٔ معماری — تکرارِ literal یک تکنیکِ **گذارای** محدودشده است، نه معماریِ مطلوب

msgidهای literalِ افزوده‌شده در دو controller، کپیِ بایت‌به‌بایتِ همان رشتهٔ منبعِ سرویس
هستند. این **یک تکنیکِ سازگاریِ گذرا (transitional) و عمداً محدودشده** برای همین دو
پیامِ معرفی‌شده توسط C7 است و دلیلِ آن یک محدودیتِ ابزارِ موجود است: قاعدهٔ
`WordPress.WP.I18n.NonSingularStringLiteralText` در WPCS (که روی خطوطِ add‌شده نسبت به
baseline گیت می‌شود) msgidِ غیر‑literal — شامل `__($e->getMessage(), 'cpms')` و حتی
constant — را **ERROR** می‌گیرد. برای پاس ماندنِ همان گیتِ موجود **بدون** هیچ
`phpcs:ignore` و بدون تضعیف WPCS، msgid literal انتخاب شد.

**این تکرار به‌عنوان معماریِ مطلوبِ بلندمدت مستند نمی‌شود.** معماری بلندمدت همان چهار
بندِ «معماری رو‌به‌جلو» بالاست. همگراییِ بعدی (consolidation) می‌تواند این تکرارِ گذرا
را جایگزین کند — اما **فقط** وقتی با شواهدِ گسترده‌تر توجیه شود، نه برای حذفِ دو literal.
به‌همین دلیل در این بسته هیچ abstraction/شناسهٔ جدیدی ساخته نشد: مرزِ REST از قبل
اطلاعاتِ کافی برای تشخیصِ امنِ هر دو واریانت داشت (هویتِ ایستای controller + کد +
خالی‌بودنِ `data`)، پس ساختِ نگاشتِ عمومیِ خطا یا چارچوبِ `message_key` نامتناسب بود.

واگراییِ سرویس/کنترلر با یک assertionِ **رفتارِ بیرونی** قفل شده است: تست‌های بخش A
برابریِ `message` پاکت REST با `getMessage()` استثنایِ سرویسِ واقعی را می‌سنجند (نه
مقایسهٔ دو literalِ منبع).

### بدهیِ محدودشدهٔ به‌تعویق‌افتاده (ثبت‌شده، اصلاح‌نشده)

- **مرز wp‑admin برنامهٔ هفتگی:** ‏`src/Admin/ClinicianAdminPage.php:436` و `:494`
  همان پیامِ سرویسِ Schedule را به‌صورت خام (`'خطا: ' . $e->getMessage()`) رندر
  می‌کنند. این **در دامنهٔ اعلام‌شدهٔ C9 (دو پیام REST) نیست** و همچنان تأیید شد که
  موجود است ⇒ به‌عنوان بدهیِ محدودشدهٔ به‌تعویق‌افتاده ثبت می‌شود، نه اصلاح.
- ‏**هیچ ابزارِ گارْدِ جدیدی ساخته نشد** (بدون scanner جدیدِ allowlistِ i18nِ Domain و
  بدون workflow/step جدیدِ CI). قاعدهٔ معماری به‌جای ابزار، **مستند/بازبینی‌محور** است؛
  WPCS هم دست نخورد (sniff ‏`WordPress.WP.I18n` فقط «چگونگیِ» فراخوانی `__()` را
  می‌سنجد و اساساً نمی‌تواند قاعدهٔ لایه‌ای یا «literalِ پیچیده‌نشده» را بیان کند).

### Notes — صریحاً ادعا **نمی‌شود**

- همهٔ بدهیِ i18n برطرف نشده است (فقط دو موردِ واجدِ شرایط).
- پشتیبانیِ کاملِ انگلیسی یا آمادگیِ جهانی‌سازی (globalization) فراهم نشده؛ هیچ کاتالوگ
  ‏`.po`/`.mo`/`.pot` و هیچ `load_plugin_textdomain()` افزوده نشد. افزونه همچنان
  کاتالوگِ `cpms` بارگذاری نمی‌کند ⇒ خروجی پیش‌فرض همان متنِ فارسیِ منبع است
  (بایت‌به‌بایت).
- ‏**C10 شروع نشده** و عملکرد همچنان **اندازه‌گیری‌نشده** است.
- ‏**Phase 2ِ Owner Roadmap بسته نشده** است.
- ‏**(تاریخی — زمانِ تحویلِ این بسته:) ‏C9 بسته (CLOSED) نشده بود؛ حداکثر وضعیتِ مجازِ
  پیش‌از‌ادغام = «پیاده‌سازیِ محدودشده کامل / READY FOR ARCHITECT MERGE REVIEW».**
  سپساً — پس از ادغامِ PR #23 در `7146d5b` و سبزیِ گیت‌های پس‌از‌ادغامِ همان SHA —
  مالک رسماً پذیرفت/بست و **C9 = CLOSED** شد (ورودیِ بالای همین فایل). آن بسته‌شدن
  هیچ‌یک از مواردِ «ادعا نمی‌شود» بالا را تغییر نمی‌دهد.
- **بدون Migration** (`0001..0020` ثابت؛ `0021` نه ساخته و نه تصویب شده). **Phase 3 آغاز نشده.**



## [Unreleased] — ثبت پذیرش رسمی C7 + بسته‌شدن مستنداتی C8 (فقط مستندات — 2026-09-11)

ورودی فقط‌مستندات؛ **بدون هیچ تغییر کد محصول/تست/workflow/schema/migration.**

### Added (docs)
- **پذیرش/بستن رسمی C7 با تصمیم صریح مالک در 2026-09-11** در مستندات کانونی تداوم ثبت شد
  (`project-current-state.md`، `phase2-state.md`، `c7-0-census.md` §۱۱-۱، handoff، taxonomy، roadmap).
  دامنهٔ پذیرش = فقط دامنهٔ تعریف‌شده/تکمیل‌شدهٔ C7: نه ادعای کامل‌بودن مطلق ایزولاسیون tenant در کل
  محصول، نه تأیید آمادگی تجاری؛ اقلام به‌تعویق‌افتاده (S2/S3، Jobs/SMS/timezone، UX چندکلینیکی
  wp-admin) به‌تعویق‌افتاده می‌مانند مگر با تصویب جداگانه؛ هیچ migration یا فاز بعدی تصویب نمی‌شود.
- **C8 به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location فاز ۲ بسته شد (بدون هیچ پیاده‌سازی)** —
  شواهد بازبینی‌شده هیچ شکاف پیاده‌سازی تأییدشده‌ای در invariantهای Location فاز ۲ نیافت؛ مرز کامل
  و فهرست صریحِ غیرمجازها در `docs/phase-reports/phase2-state.md` §C8. جغرافیای ایران ⇒ Owner
  Phase 4؛ مجوزدهی scoped نهایی ⇒ Owner Phase 3. مصرف عملیاتی `locations.timezone` توسط
  runtimeهای فعلی/legacy = مرز ثبت‌شده/قلم آشتی‌دهیِ به‌تعویق‌افتاده.
- هم‌ترازی چک‌پوینت با `b19930fe` (PR #21 MERGED 2026-09-11T14:27:14Z — sync فقط‌مستندات) +
  گیت‌های پس‌از‌ادغام: CI `34610213715` · Real-WP `34610213751` · Pilot/Staging `34610213717` ·
  Closure `34610213674` — همه success.

### Notes
- **بدون Migration** (`0001..0020` ثابت؛ `0021` نه تأیید شده و نه ساخته شده). **Phase 3 آغاز نشده.**
- C9 (i18n audit) و C10 (Performance review) با این ورودی **مجاز به اجرا نشده‌اند**؛ عملکرد
  **اندازه‌گیری‌نشده** است مگر شواهد benchmark واقعی موجود شود.

## [Unreleased] — ترمیم C7 (ایزولاسیون مالکیت شیء بین‌کلینیکی) — یکپارچه‌شده با PR #20

ترمیم C7 بر پایهٔ سرشماری شواهد C7-0
([`docs/phase-reports/c7-0-census.md`](docs/phase-reports/c7-0-census.md)) پیاده‌سازی شد و در
**PR #20 (MERGED ۲۰۲۶-۰۹-۱۱T13:09:16Z)** در چک‌پوینت `a385d868` به `main` یکپارچه شد
(والدین merge: `4871f84` = mainِ پیش‌از‌ادغام + `6b438238` = head نهایی؛ merge توسط
`app/arena-ai-coding-agent` — **تأیید مالک در مخزن مستند نیست**). این ورودی سابقهٔ C6 و
اصلاحیهٔ مالی پس از آن را بازنویسی نمی‌کند.

### Fixed
- **ایزولاسیون object-ID در ۷ عملیات مالی مبتنی بر شناسه** (ثبت پرداخت، اصلاح فاکتور، ابطال/استرداد پرداخت، خواندن فاکتور/رسید/فاکتورِ ویزیت): ردیفِ انتخاب‌شدهٔ کلاینت/مهاجم دیگر منبع اعتماد tenant نیست؛ `clinic_id` آن فقط با زمینهٔ کلینیک معتبرِ مستقل مقایسه می‌شود. شیء خارجی ⇒ `CLINIC_NOT_FOUND` 404 هم‌پاکت با «یافت‌نشد» (بدون شمارش).
- **الزام زمینهٔ کلینیک معتبر (fail-closed)** در همان ۷ عملیات: نبود Scope ⇒ `CLINIC_SCOPE_REQUIRED` 400؛ بدون هیچ fallback به Clinic ID 1.
- **مالکیت ویزیت در صدور فاکتور** و **مالکیت تعرفهٔ اقلام فاکتور**؛ **مالکیت تعرفه در update/deactivate** (بدون موفقیت بی‌صدای no-op).
- **برنامهٔ هفتگی:** ایزولاسیون update/delete/deleteException + مالکیت پزشک در create و createException (کلینیک ردیف هرگز از ردیف پزشک مشتق نمی‌شود).
- **مرز wp-admin (`ClinicianAdminPage`):** برقراری زمینهٔ کلینیک معتبر از سازوکار تأییدشدهٔ عضویت؛ بدون عضویت یا عضویت مبهم ⇒ انکار (nonce/capability جایگزین عضویت نیست). UX انتخاب چندکلینیکی ساخته نشد.

### Added
- تست‌های Integration مشخصه‌نگاری/رگرسیون C7 (`C7FinanceObjectIdIsolationTest`، `C7ScheduleObjectIdIsolationTest`، `C7PreIntegrationBoundaryTest`) — هیچ‌کدام تضعیف/skip/quarantine نشدند و هیچ assertion حذف نشد؛ تغییرات بعدیِ آزمون‌ها فقط افزودنی/تقویتی یا اصلاح harness/import بود (`5b9b79d`، `db9bd37`، `513df94`).
- برقراری Scope معتبر صریح در probeهای synthetic ابزار Pilot (`bin/pilot-smoke.php`) و دو workflow (`closure-gate.yml`، `real-wp-acceptance.yml`) — بدون حذف/تضعیف هیچ assertion یا گیت.

### Notes
- **بدون Migration؛ بدون تغییر schema** (شمارش `0001..0020` ثابت؛ `0021` نه تأیید شده و نه ساخته شده).
- **Phase 3 آغاز نشده است** (بدون `AuthorizationService`؛ بدون سیاست Location/RBAC).
- موارد به‌تعویق‌افتاده **تصویب نشده‌اند**: S2 (زمینهٔ tenant در اجرای Job — سؤال باز، نیازمند ADR)، S3 (شماره‌گذاری نسخه — سؤال باز دامنه‌ای)، Jobs/SMS/timezone، UX چندکلینیکی wp-admin.
- شواهد پیش‌از‌ادغام (head `6b438238`): CI `34598981613` · Real-WP `34598981627` + `34598978084` · Pilot `34598978102` · Closure `34598978147`. گیت‌های پس‌از‌ادغام روی `a385d868`: CI `34602712029` · Real-WP `34602711983` · Pilot `34602711956` · Closure `34602711962` — همه success.
- در لحظهٔ ادغام، **بستن رسمی C7** (پذیرش مالک) در مخزن مستند نبود — این ورودی فقط واقعیتِ ادغام و دامنهٔ تکمیل‌شده را ثبت می‌کرد. **پذیرش رسمی C7 سپساً با تصمیم صریح مالک در 2026-09-11 ارائه و در ورودی بالاتر همین فایل + مستندات کانونی ثبت شد.**

## [Unreleased] — اصلاحیهٔ پس از بستنِ C6 (ایزولاسیون مالی بر اساس کلینیک)

ثبت تاریخی: **C6 رسماً بسته شده بود**؛ سپس یک نقص دامنهٔ کلینیک در مسیرهای مالی
کشف شد. این ورودی، سابقهٔ بستنِ C6 را بازنویسی نمی‌کند — نقص واقعاً پس از بستن
پیدا شد و همین‌طور ثبت می‌شود. یکپارچه‌شده در main از طریق **PR #17 (MERGED
۲۰۲۶-۰۹-۱۰)** در چک‌پوینت `248ca10`.

### Fixed
- **هفت مسیر اجرایی مالی که به Clinic ID 1 سنجاق شده بودند** (نقص محصولیِ
  از پیش‌موجود، منشأ F6 `ef59e0cb`): `ServiceRepository::all`،
  `PaymentRepository::{revenueSummary, forRange, nextPaymentNumber}`،
  `InvoiceRepository::{openInvoices, nextInvoiceNumber}`، `FinanceService::lockClinic`.
  اصلاح با قرارداد صریح `int $clinicId` مورد اعتماد؛ خواندن‌ها از
  `trustedClinicId()` با شکست بسته (`CLINIC_SCOPE_REQUIRED` 400) و بدون هیچ
  بازگشت به کلینیک ۱. اثر پیشین: نشت تعرفه/درآمد/پرداخت/فاکتور بین کلینیک‌ها
  (شامل نام بیمار و MRN)، شماره‌گذاری اشتباه INV/PAY، و قفل ردیف در دامنهٔ نادرست.
- **نقاط کور آشکارساز Tenant Tripwire** (نقص ابزار تست): عدم تشخیص لیترال tenant
  در پارامترهای bind‑شده و سه نقص سرکوب (`select_first_clinic` shadowing،
  بلعیدن شناسهٔ qualified، معافیت هم‌خطی). `bin/tenant-tripwire.py` سخت‌گیرانه شد
  (۵۹ self‑test، tenant‑aware، allowlist خالی). اسکن فعلی: **۰ هاردکد**
  (+۱ مورد مشکوکِ مشروع تحت بررسی: `SystemClinicResolver`، AD‑04).

### Added
- `tests/Integration/FinanceClinicIsolationTest.php` — ایزولاسیون مالی با
  کلینیک غیر ۱ و همزیستی دو کلینیک، عدم عبور نام/MRN، شماره‌گذاری INV/PAY
  به‌ازای هر کلینیک، مسیرهای شکست بسته، و ایمنی INSERT (خرابکاری عمدی ⇒ rollback
  و `insert_id == 0`). تست هدف قفل، SQL واقعی قفل ردیف شماره‌گذاری را می‌بیند.

### Notes
- بدون Migration (شمارش بدون تغییر؛ `0021` نه تأیید شده و نه ساخته شده).
- **C7 آغاز نشده است.** مجوزدهی مبتنی بر Location دست‌نخورده است.
- PR رقیب **#18** حاوی همین اصلاحیه، در ۲۰۲۶-۰۹-۱۱ **بدون merge بسته شد**
  (superseded توسط #17)؛ شاخهٔ آن حذف نشده و به‌عنوان سابقه نگهداری می‌شود.

## [1.0.2] — 2026-09-07 (Hotfix نصب واقعی — بازتولیدشده روی WordPress واقعی در CI)

سه نقص گزارش‌شدهٔ نصب روی WordPress واقعی روی main بازتولید شد (گیت جدید «Real WordPress Acceptance»: ZIP رسمی `bin/build-release.sh` → WordPress 6.7.2 تمیز → نصب/فعال‌سازی → تأیید مستقیم DB → مرورگر واقعی Chromium → بررسی لاگ — با ماتریس دو prefix `wp_`/`clinic_`).

### Fixed
- **D2 — شمارش جداول در «CPMS (فنی و لاگ)» همیشه 0 بود:** `SettingsAdmin::tableCount()` الگوی `LIKE 'cpms_%'` بدون `$wpdb->prefix` داشت درحالی‌که جداول واقعی `{prefix}cpms_*` هستند. اصلاح به الگوی prefix-aware (همان الگوی `SystemHealthService`). اثبات E2E: شمارش UI == شمارش مستقیم DB (۴۱ جدول) روی هر دو prefix.
- **D1 — «CPMS (سیستم)»/Health با Critical Error می‌مرد:** `SystemPage::render()` خواندن وضعیت مجوز/Health/«فهرست بکاپ» را بدون guard صدا می‌زد؛ شکست IO مخزن بکاپ (مثل میزبانِ با wp-content غیرقابل‌نوشتن → `BackupException`) کل صفحهٔ وضعیت را Fatal می‌کرد. حالا هر بخش مستقلاً guard می‌شود و خطایش درون‌صفحه‌ای نمایش داده می‌شود — صفحهٔ وضعیت هرگز Fatal نمی‌شود. تست رگرسیون: `SystemAdminPagesTest::testSystemPageSurvivesUnwritableBackupStore` (بازتولید دقیق همان کلاس شکست).
- **REST داشبوردها روی Permalink ساده 404 می‌خورد (کشف با گیت مرورگر):** هلپرهای JS پنج صفحهٔ مدیریتی، `path` حاوی query را مستقیم به `rest_url()` می‌چسباندند؛ با Permalink ساده (`index.php?rest_route=…`) کوئری دوم با `?` اضافه می‌شد و route قفل نمی‌شد (`rest_no_route`: polling صف، اعلان‌ها، خلاصهٔ مالی، جستجوی بیمار و…). فیکس مرکزی: `apiUrl()` در هر پنج صفحه — وقتی `rest_url` خود `?` دارد، کوئری path با `&` ضمیمه می‌شود.

### Docs/CI
- Workflow جدید `real-wp-acceptance.yml` + `bin/rwp-acceptance.py`: نصب واقعی از ZIP رسمی، دو prefix (`wp_`/`clinic_`)، مرورگر واقعی (System/Health/Technical/منوهای Doctor/Secretary/Administrator فنی P-3)، هم‌سان‌سازی شمارش جداول UI↔DB، پروب Migration خراب (fail-loud + عدم ثبت)، بررسی لاگ PHP/WP/Apache/مرورگر، اسکرین‌شات/لاگ به‌عنوان Artifact، و انتشار Evidence به‌صورت Commit Comment.

### Verified (گزارش بازتولید قبل از فیکس)
- D1: STILL_PRESENT/PARTIAL → `wp-die-message` (critical error) روی هر دو prefix.
- D2: STILL_PRESENT → `UI=0 / DB=41` روی هر دو prefix.
- D3: FIXED (F1-2) → پروب migration خراب: `RuntimeException` + عدم ثبت version (تأیید مجدد روی main).

## [1.0.1] — 2026-09-07 (Remediation Part 1 — ممیزی مستقل 6e42519)

### Added
- **مدیریت دسترسی نقش‌ها (ADR-0030):** صفحه «CPMS (دسترسی‌ها)» در ابزارها — ماتریس Role × Capability فارسی با گروه‌بندی و نشانگر Cap حساس (P-11)؛ ذخیره از مسیر Override عمدی با Audit `ROLE_PERMISSION_CHANGED`/`ROLE_PERMISSION_RESET`؛ دکمه «بازگشت به پیش‌فرض». Self-healing به Override احترام می‌گذارد (Least Privilege برای خارج از فهرست برقرار است).
- **Console بیمار «نوبت‌های من»:** مقصد فارسی بیمار بعد از OTP (نوبت‌های پیش‌رو + تاریخچه با جلالی)؛ فقط داده خودش (Ownership)؛ login_redirect + مخفی‌شدن Admin Bar + هدایت GETهای wp-admin — فقط برای «بیمار خالص».
- **مدیریت پزشکان و برنامه هفتگی (ADR-0031):** صفحه «پزشکان و برنامه» در ابزارها — ثبت/ویرایش/غیرفعال‌سازی پزشک + پیوند ۱:۱ به کاربر وردپرس + ویرایش ۷ روز برنامه هفتگی + استثناهای تعطیلی/مرخصی/بستن (همه از مسیر ScheduleService با Audit + بازتولید Slot). راه‌اندازی مطب دیگر به دست‌کاری DB نیاز ندارد.
- **چاپ نسخه (ADR-0031):** دکمه «🖨️ چاپ» کنار هر نسخه در داشبورد پزشک — نمای چاپی فارسی/RTL (سربرگ مطب، بیمار/MRN/سن، تاریخ جلالی، شکایت اصلی، جدول اقلام، جای امضا) با واترمارک «پیش‌نویس/ابطال‌شده»؛ مجوز `cpms_rx_read` + مالکیت ویزیت + Audit `PRESCRIPTION_PRINTED`.
- تست‌های Integration جدید: `DoctorQueueScopeTest` (۳)، `RoleCapabilitiesOverrideTest` (۵)، `PatientPortalTest` (۳)، `ClinicianRepositoryTest` (۳)، `PrescriptionPrintTest` (۵).

### Fixed
- **باگ واقعی پنهان در `voidPrescription` (کشف توسط تست جدید Part 2):** متغیر `$reason` به closure تراکنش منتقل نمی‌شد — هر «ابطال نسخه» در تولید با خطا شکست می‌خورد (هیچ تست قبلی این مسیر را پوشش نمی‌داد). اکنون رفع + تست ابطال/چاپ.

### Security
- **Scope صف برای پزشک (ممیزی P8):** پزشکِ متصل در `/queue`، `rt/queue` و آمار داشبورد فقط ویزیت‌های خودش را می‌بیند (صف + فید Real-time + ETag + آمار)؛ پزشک بدون اتصال = هیچ؛ منشی بدون تغییر. مطابق Master Context §8 — «بدون دید ضمنی داده پزشک دیگر».

### Changed
- منوی «CPMS (فنی)» به «CPMS (فنی و لاگ)» تغییر نام یافت (تمایز از «CPMS (سیستم)» — ممیزی P10).

### Docs
- ADR-0030، permission-matrix v1.5، user-guide (بخش دسترسی‌ها + بیمار)، گزارش `report-remediation-part1.md`، لاگ agent-guide.

## [1.0.0] — 2026-09-06 (Release Candidate — Pilot/Staging Gate)

### نسخه ۱.۰ — فازهای F1 تا F9 + Pilot/Staging Readiness Gate

**هسته و معماری (F1)**
- معماری لایه‌ای (Domain/Application/Infrastructure/Rest/Bootstrap)، Migration framework ایمن و idempotent (schema تا `2026_09_07_0007`)، نقش‌ها و Capabilityها (`cpms_patient/secretary/doctor` + Administrator فنی P-3)، Settings (sealed)، Audit با Hash Chain، JobQueue (Single Worker + Row Lock — ADR-0016)، CI (Unit PHP 8.1–8.4 + Integration WP 6.7.2/MySQL 8).

**احراز هویت و پیامک (F2/F2.5)**
- OTP + Session بیمار، Patient Links، Provider-Agnostic SMS (ADR-0025: Log/Generic-API)، مسیر تست امن provider=log.

**نوبت‌دهی (F3)**
- Schedule/Slot (تقویم شمسی)، Booking API (hold→confirm با Idempotency)، Availability عمومی، REST envelope استاندارد + کدهای خطای `CLINIC_*` (ADR-0019).

**مراجعه و صف (F4)**
- Check-in/Walk-in، Queue State Machine، Real-time داشبورد منشی.

**بالینی (F5)**
- Notes/نسخه/توصیه/پیگیری، فایلهای پزشکی (storage خارج webroot + `.htaccess` deny)، جستجو، داشبورد پزشک، دست‌خط (F7: Canvas/Offline-Sync/Conflict).

**مالی (F6)**
- Invoice/Payment/Adjustment/Void/Refund/Receipt + تعرفه‌ها + داشبورد مالی منشی (ADR-0026: نقش‌های پویا آماده Scope).

**اعلان و گزارش (F8)**
- Notification Layer + Templates شمسی، ۱۲ گزارش + Export async (Watermark/Audit).

**Hardening (F9)**
- Security Review کامل T-01..T-24 (رفع root cause سه حفره واقعی)، Idempotency Replay/In-flight، گارد مالکیت ویزیت، UNIQUE wp_user_id با Preflight، پاک‌سازی Jobهای مرده، TP-16 (ارتقا/Restore)، Accessibility.

**Pilot/Staging Readiness Gate (2026-09-06)**
- ابزار Gate: `bin/build-release.sh` (artifact تمیز whitelist-only)، `bin/pilot-{seed,smoke,responsive}`، workflow `.github/workflows/pilot-gate.yml` (fresh install → upgrade → smokes → benchmark → security → audit → backup → restore drill).
- گزارش کامل: `docs/phase-reports/report-pilot-gate.md`.

### Fixed
- **Background Jobs (باگ واقعی تولید — FR-5.5/J-4):** `bin/cpms jobs tick` (entrypoint مستند system-cron) جاب‌های دوره‌ای را دوباره زمان‌بندی نمی‌کرد → در استقرار system-cron، recurringها (no-show/یادآوریها/dispatch/cleanupها) بعد از اولین اجرا برای همیشه می‌ایستادند. ریشه‌یابی: واگرایی مسیر WP-Cron و CLI. فیکس: مسیر واحد `App::runTick()` (heartbeat + re-schedule idempotent + tick) + regression test `JobQueueTest::testRunTickReschedulesAndProcessesRecurringJobs` + همگام‌سازی docs (background-jobs.md J-4، ADR-0016).

### Security
- هیچ تغییر امنیتی جدید نسبت به F9؛ Gate حملات/پروبهای فایل محافظت‌شده را روی Apache واقعی verify کرد (403 از `.htaccess` افزونه).

## [Unreleased]
- Backlog V1.5: OCR، 2FA، Merge UI، ClamAV/Encryption (R-06).
- مشاهده فرعی Gate: قفل رقابتی `_transient_cpms_migrate_lock` زیر بار c=100 چند notice deadlock گذرا تولید می‌کند (بدون اثر عملکردی) → بهبود در Backlog.
