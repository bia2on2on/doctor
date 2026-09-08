# PHASE 0 — GIT CHECKPOINT & BASELINE AUDIT + RE-VERIFICATION REPORT

| | |
|---|---|
| **فاز** | **Phase 0 — Git Checkpoint** (طبق Roadmap تأییدشدهٔ Owner، Phase 0..20) |
| **وضعیت** | ✅ **CLOSED** — پذیرفته‌شده توسط Product Owner |
| **نوع** | ممیزی خط پایه، فقط‌خواندنی — صفر تغییر کد، صفر Migration، صفر تغییر Schema |
| **ثبت رسمی** | 2026-09-08 |
| **شاخه** | `arena/01a0808c-doctor` |
| **HEAD مبنا** | `8087b42e19a1721e38eb073aa1a17aff1cbac97b` (= `main` = `origin/main`) |
| **مرجع فازبندی** | `docs/roadmap/roadmap.md` §۰ — Owner-approved Phase 0..20 |
| **سند بعدی** | `docs/architecture/phase0.5-target-model.md` (Phase 0.5) |

> **چرا این سند وجود دارد:** گزارش Phase 0 و بازبینی مجدد آن ابتدا فقط در گفتگو ارائه شدند. Product Owner دستور داد به‌عنوان **مستند رسمی پروژه** ثبت شوند تا ۹ قید معماری ردپای دائمی در مخزن داشته باشند.
>
> **نسخهٔ مرجع C-6 و C-8 = نسخهٔ اصلاح‌شده** (تأییدشده توسط Owner). متن اولیهٔ آن دو در §۴ به‌عنوان تاریخچه حفظ شده و صراحتاً **Superseded** علامت خورده است — تاریخچهٔ تصمیم پاک نشده.

---

## ۱. دامنه و قواعد اجراشده

| قاعده | رعایت |
|---|---|
| فقط فاز تأییدشده؛ مسائل فازهای بعد فقط **گزارش** می‌شوند نه اصلاح | ✅ |
| بازرسی کامل پیش از هر ادعا | ✅ |
| عدم دست‌کاری Working Tree (`reset --hard` / `clean -fd` / `checkout .` / `restore .`) | ✅ اجرا نشد |
| هیچ فرضی گرفته نشد (نه `clinic_id = 1`، نه `current_user = doctor`) | ✅ |
| بدون میان‌بر معماری | ✅ |
| Phase Gate — بدون تأیید صریح، فاز بعدی شروع نمی‌شود | ✅ |
| بدون `commit` / `tag` / `push` در خود Phase 0 | ✅ |
| بدون حذف فایل، بدون نصب/تغییر dependency | ✅ |
| دیتابیس فقط‌خواندنی | ✅ (دیتابیس زنده‌ای وجود نداشت — §۲) |
| تفکیک اکید CURRENT STATE از TARGET ARCHITECTURE | ✅ (Target در سند Phase 0.5) |
| بدون refactor پنهان | ✅ |

**دو قلم untracked موجود در زمان Phase 0 — طبق دستور Owner دست‌نخورده ماندند** (نه حذف، نه ignore، نه commit): `docs/commercial-gap-audit.md` و `.download/`.

---

## ۲. محیط اجرا — آنچه وجود ندارد

اثبات‌شده با اجرای واقعی، نه فرض:

| مورد | نتیجه |
|---|---|
| باینری `mysql` / `mariadb` | ❌ موجود نیست |
| پورت شنوندهٔ ۳۳۰۶ یا ۵۴۳۲ | ❌ صفر (`ss -tln`) |
| `/var/lib/mysql` | ❌ موجود نیست |
| `wp-cli` | ❌ موجود نیست |
| `/var/www` (نصب WordPress) | ❌ موجود نیست |

> **نتیجهٔ روش‌شناختی الزام‌آور:** تمام دانش Schema در این پروژه **فقط از سورس Migration** استخراج شده است، نه از یک دیتابیس زنده. هر ادعای «رفتار زمان اجرا» در این سند بدون شاهد اجرایی مطرح نشده است.

**مخزن:** clone به‌صورت **shallow** است (`.git/shallow` حاوی همان SHA، `rev-list --count HEAD = 1`، HEAD با برچسب `(grafted)`). طبق دستور Owner **`git fetch --unshallow` اجرا نشد**. ۰ tag، ۰ stash، ۰ GitHub release.

---

## ۳. خط پایهٔ عددی (اثبات‌شده)

| سنجه | مقدار | روش اثبات |
|---|---|---|
| فایل tracked | **۳۶۰** | `git ls-files` |
| فایل PHP در `src/` | **۱۶۵** (۳۴٬۴۰۴ خط) | شمارش مستقیم |
| فایل تست | **۸۴** (۳۳ Unit + ۴۹ Integration) | شمارش فایل |
| سند tracked در `docs/` | **۸۴** | `git ls-files docs` |
| جدول `cpms_*` | **۴۱** | نام‌های یکتای `$db->table()` در `src/Migrations/` |
| فایل Migration | **۹** (`0001`..`0009`) + `MigrationRunner.php` | `ls src/Migrations/` |
| Foreign Key | **۳۹** (۳۸ در `CREATE` + `fk_hwpage_bg` از راه `ALTER` در `0004`) | `CONSTRAINT fk_*` یکتا |
| Capability | **۴۶** | ۵۲ ثابت `'cpms_…'` منهای ۵ `ROLE_*` و ۱ `OPTION_*` |
| نقش ثبت‌شده | **۵** | `RolesAndCapabilities.php:186-190` |
| Controller REST | **۱۴** (namespace `clinic/v1`) | — |
| `register_rest_route` در سورس | **۷۷** | grep |
| **route زمان اجرا** | **۸۰** | شمارش حلقه‌ای (یک ثبت می‌تواند چند route بسازد) |
| `permission_callback` | **۸۹** ⚠️ | grep — **تصحیح‌شده در Phase 1A: عدد صحیح ۸۸ است**؛ بند ۹ |
| اکشن `admin_post_*` | **۲۵** | grep |
| مسیر AJAX (`wp_ajax_*`) | **۰** | grep |
| Seed در Migration | **۲** — `cpms_clinics id=1` (`0001:736`)، `cpms_license_install` (`0008:84`) | grep |
| نسخهٔ افزونه | `1.0.0` (هدر + `CPMS_VERSION`) | فایل ورودی افزونه |

> **دام شمارشی ثبت‌شده:** شمارش `register_rest_route` با regex ساده **گمراه‌کننده است** (۷۷ در سورس ≠ ۸۰ در زمان اجرا). در هر ارجاع بعدی باید صریحاً گفته شود کدام عدد مقصود است.
>
> **دام شمارشی دوم:** شمارش Capability با `grep "public const .* = 'cpms_"` **بیش‌شمارش** می‌کند (۵ ثابت `ROLE_*` و ۱ ثابت `OPTION_*` هم مطابقت می‌کنند). عدد صحیح **۴۶** است.

---

## ۴. نُه قید معماری (C-1 … C-9)

این ۹ مورد توسط Product Owner به‌عنوان **قید معماری** قفل شده‌اند. هیچ فازی حق ندارد بدون بررسی صریح در برابر این ۹ قید شروع شود.

### C-1 — لایهٔ Organization وجود ندارد
هیچ جدول، کلاس یا مفهومی به نام Organization در کد نیست. ریشهٔ سلسله‌مراتب فعلی `cpms_clinics` است.
**وضعیت:** MISSING · **فاز مالک:** Phase 2 · **تصمیم مرتبط:** AD-02، AD-03 (ADR-0031)

### C-2 — لایهٔ Location وجود ندارد
هیچ جدول `locations` / `branches` / `sites` وجود ندارد. `cpms_clinicians.room` یک `VARCHAR` آزاد است، نه موجودیت.
**وضعیت:** MISSING · **فاز مالک:** Phase 2 · **تصمیم مرتبط:** AD-01، AD-08

### C-3 — Clinic Context / Resolver وجود ندارد
هیچ کلاس `ClinicContext`، `TenantResolver` یا معادلی وجود ندارد. هیچ نقطهٔ واحدی «کلینیک جاری» را تعیین نمی‌کند.
**وضعیت:** MISSING · **فاز مالک:** Phase 2

### C-4 — ۵۴ مورد hardcode شدهٔ `clinic_id = 1`
الگوی دقیق `clinic_id = 1` یا `'clinic_id' => 1` ⇒ **۵۴ مورد در ۲۳ فایل**.
توزیع (تأیید مجدد در Documentation Reconciliation): **۲۴ داخل `src/Infrastructure/Repository/`** و **۳۰ بیرون از آن**؛ **۱۳ از ۲۳** فایلِ آلوده Repository هستند.
سه مورد اضافه به‌شکل Default-Parameter که در الگوی بالا شمرده نمی‌شوند ولی همان اثر را دارند:
`Infrastructure/Audit/AuditLogger.php:45` · `Infrastructure/Security/Idempotency.php:37` · `Settings/Settings.php:145` ⇒ سرشماری موسّع **۵۷ مورد در ۲۶ فایل**.
در مقابل، **۲۵ مورد** پارامتری سالم (`clinic_id = %d`) در **۱۱ فایل** وجود دارد — یعنی الگوی درست در کدبیس شناخته‌شده است ولی یکدست اعمال نشده.
**وضعیت:** بدهی فنی فعال · **فاز مالک:** Phase 2 · **تصمیم مرتبط:** AD-13

### C-5 — Staff/Permission به Clinic scope نشده است
نگاشت User→Role فقط از راه نقش‌های هستهٔ WordPress انجام می‌شود؛ **هیچ جدول CPMS کاربر را به نقش نگاشت نمی‌کند.** هر کاربر یک نقش سراسری دارد. `capsMap()` (`RolesAndCapabilities.php:248-265`) یک `match` ثابت است و آپشن `cpms_role_caps_override` مجموعهٔ پیش‌فرض را **به‌طور کامل جایگزین** می‌کند. `registerRole()` هر `cpms_*` خارج از فهرست مؤثر را self-heal (حذف) می‌کند.
**وضعیت:** ساختاری · **فاز مالک:** Phase 3 · **تصمیم مرتبط:** AD-05، AD-06

### C-6 — الگوی `permission_callback = __return_true` *(نسخهٔ اصلاح‌شده — مرجع)*

**اعداد اثبات‌شده:**

| سنجه | مقدار |
|---|---|
| route در زمان اجرا (REST) | **۸۰** |
| ثبت `permission_callback` | **۸۹** ⚠️ (صحیح: **۸۸** — بند ۹) |
| از آن‌ها `__return_true` | **۶۷** |
| از آن‌ها gated callback | **۲۲** |
| از ۶۷ مورد `__return_true` — دارای guard داخل handler (طبق audit فعلی) | **۶۲** |
| endpoint عمداً public | **۵** |

تفکیک ۶۲ مورد دارای guard بر اساس wrapper: `staff()` ۲۲ · `doctor()` ۹ · `guard()` ۹ · `patient()` ۵ · بقیه از راه متد داخلی که خودش `requireNonce()` + `requireCap()` را صدا می‌زند (`stream`, `save`, `printView`, `download`, `rtQueue`, `rtNotifications`, `doctorAction`, و ۹ متد `SmsController`).

پنج endpoint عمداً public: `booking/availability` · `booking/quote` · `health` · `otp/requestCode` · `otp/verifyCode`.

**حکم دقیق — این جمله مرجع است:**

> **`__return_true` به‌تنهایی معادل vulnerability اثبات‌شده نیست.** مسئله **معماری** است: دیرهنگام بودن Authorization (پس از dispatch شدن route) و ضعف در **audit-پذیری و test-پذیری** آن. WordPress در این حالت هیچ گیت مجوزی در لایهٔ router ندارد و صحت کاملاً به انضباط درون هر handler وابسته می‌ماند — یعنی یک handler جدید که فراموش کند wrapper را صدا بزند، **بی‌صدا** باز می‌شود و هیچ تست ساختاری آن را نمی‌گیرد.
>
> **endpointهای public هم مصون نیستند:** آن ۵ مورد همچنان نیازمند validation ورودی، rate-limit و سازوکار anti-abuse مناسب‌اند (به‌ویژه `otp/requestCode` که سطح حملهٔ enumeration و هزینهٔ پیامک دارد).

**وضعیت:** بدهی معماری (نه آسیب‌پذیری اثبات‌شده) · **فاز مالک:** Phase 1a — اصلاح خودِ الگو مستقل از Scope است و همین حالا قابل انجام است.

<details>
<summary><b>تاریخچه — نسخهٔ اولیهٔ C-6 (SUPERSEDED، حفظ‌شده برای traceability)</b></summary>

> نسخهٔ اولیه: «۶۷ REST route با `permission_callback = __return_true`.»
> **چرا Superseded شد:** آن جمله این برداشت را ایجاد می‌کرد که ۶۷ endpoint بدون هیچ کنترل مجوزی باز هستند. بررسی دقیق نشان داد ۶۲ مورد guard درون handler دارند و ۵ مورد عمداً public‌اند. نسخهٔ اصلاح‌شده توسط Product Owner در 2026-09-08 تأیید و جایگزین شد.

</details>

### C-7 — Schedule برای چند-کلینیک/چند-مکان کافی نیست
`cpms_schedule` دارای `UNIQUE u_sched_day (clinician_id, day_of_week)` است ⇒ یک پزشک نمی‌تواند در یک روز هفته دو برنامهٔ متفاوت (مثلاً دو مکان) داشته باشد. `cpms_schedule_slots` دارای `UNIQUE u_slot (clinician_id, slot_date, slot_time)` است.
همچنین `cpms_clinicians` دارای `UNIQUE u_clinician_user (wp_user_id)` (افزوده در Migration `0007`) است ⇒ **یک کاربر WordPress نمی‌تواند در بیش از یک Clinic پروفایل پزشک داشته باشد.** این مورد مستقیماً با AD-05 در تعارض است.
**وضعیت:** بلوکهٔ ساختاری · **فاز مالک:** Phase 2 (شکستن UNIQUEها = نقطهٔ برگشت‌ناپذیر Migration)

### C-8 — `clinic_id` بدون Foreign Key *(نسخهٔ اصلاح‌شده — مرجع)*

| سنجه | مقدار |
|---|---|
| جدول دارای ستون `clinic_id` | **۲۵** |
| Foreign Key مستقیم به `cpms_clinics` | **۴** |
| جدول دارای `clinic_id` **بدون** FK مستقیم | **۲۱** |

چهار FK موجود (همه در `2026_09_05_0001_initial_schema.php`):
`fk_clinicians_clinic` (خط ۵۰) · `fk_patients_clinic` (خط ۸۵) · `fk_schedule_clinic` (خط ۱۶۴) · `fk_services_clinic` (خط ۵۴۳).

۱۶ جدول از ۴۱ جدول اصلاً `clinic_id` ندارند — از جمله `cpms_otp_tokens`، `cpms_jobs`، `cpms_rate_limits`، `cpms_operational_logs` (رجوع به C-9).

**پیامد:** ایزولاسیون داده بین کلینیک‌ها امروز **در سطح دیتابیس تضمین نمی‌شود**؛ فقط به درستی Query در لایهٔ اپلیکیشن متکی است. با توجه به C-4، آن لایه هم آلوده است.
**وضعیت:** شکاف یکپارچگی داده · **فاز مالک:** Phase 2 (افزودن ۲۱ FK)

<details>
<summary><b>تاریخچه — نسخهٔ اولیهٔ C-8 (SUPERSEDED، حفظ‌شده برای traceability)</b></summary>

> نسخهٔ اولیه: «`appointments` و `visits` دارای `clinic_id` هستند ولی FK مستقیم به Clinic ندارند.»
> **چرا Superseded شد:** آن جمله دامنه را به دو جدول محدود می‌کرد، درحالی‌که مسئله سیستمی است: ۲۱ جدول از ۲۵ جدول دارای `clinic_id` فاقد FK هستند. نسخهٔ اصلاح‌شده توسط Product Owner در 2026-09-08 تأیید و جایگزین شد.

</details>

### C-9 — جداول نیازمند بازبینی Scope
`cpms_otp_tokens`، `cpms_jobs`، `cpms_rate_limits`، `cpms_operational_logs` هیچ‌کدام `clinic_id` ندارند و باید تعیین‌تکلیف شوند.
**وضعیت پس از Phase 0.5:**
- `cpms_otp_tokens` → **عمداً بدون `clinic_id`** (AD-07 — OTP نگرانی Identity است)
- `cpms_jobs`، `cpms_operational_logs` → `clinic_id NULL` افزوده می‌شود
- `cpms_rate_limits` → **تصمیم باز (Q6)**؛ پیشنهاد دوسطحی (سراسری برای دفاع IP، per-Clinic برای سهمیه)
همچنین `cpms_rate_limits` ستون `id` ندارد؛ کلیدش `window_id` است.
**فاز مالک:** Phase 1 / Phase 2

---

## ۵. اصلاحات بازبینی مجدد (Re-verification)

بازبینی مستقلِ گزارش اولیهٔ Phase 0، پنج خطای عددی/واقعی پیدا کرد. همه در همان زمان تصحیح شدند و اعداد تصحیح‌شده در §۳ و §۴ آمده‌اند:

| # | ادعای اولیه | واقعیت اثبات‌شده |
|---|---|---|
| P-1 | ۷۵ route REST | **۸۰** در زمان اجرا (۷۷ نقطهٔ ثبت در سورس) |
| P-2 | ۳ Foreign Key به `cpms_clinics` | **۴** (`fk_services_clinic` جا افتاده بود) — و ۲۱ جدول بدون FK |
| P-3 | Migration `0008` سه `CREATE TABLE` دارد | **دو** مورد (grep خام کامنت‌ها را هم شمرده بود) |
| P-4 | ۸۵ سند در `docs/` | **۸۴** سند tracked (۸۵مین، `commercial-gap-audit.md`، untracked است) |
| P-5 | «سرور روی پورت ۸۰۸۰ در حال اجراست» | **باطل** — پردازه‌های پس‌زمینه بین نوبت‌ها زنده نمی‌مانند |

> **درس روش‌شناختی ثبت‌شده:** یک DDL parser که فقط الگوی `$db->table('x') . ' ('` را می‌فهمد، جدول‌های با نام درون‌ریزی‌شده (`{$var}`) و همهٔ FKهای افزوده‌شده با `ALTER TABLE` را از دست می‌دهد. هر شمارش parser باید با یک `grep` مستقل مقابله‌سنجی شود.

---

## ۶. سه مکانیزم معماری که در کد وجود ندارند

این سه مورد در ADRهای تأییدشده به‌گونه‌ای نوشته شده‌اند که وجودشان را القا می‌کنند. **هیچ‌کدام در کد فعلی وجود ندارند** (نه ناقص — صفر):

| مکانیزم | سند مدعی | واقعیت | اثبات |
|---|---|---|---|
| **`AccessPolicy`** — «Single Source of Truth برای Capability + Data-Access + Field/Row Filter» | ADR-0002، ADR-0026، multi-doctor-readiness-review §1.7/§1.8 | **وجود ندارد** | `grep -rl "AccessPolicy" src` → ۰ فایل |
| **`resolvePatient()`** — «باید در همهٔ Queryهای بیمار اعمال شود (Repository Base)» | ADR-0015 | **وجود ندارد** | `grep -rn "resolvePatient" src` → ۰ نتیجه |
| **Dangling-Check Job** — «Job موندگاری در F1 ساخته شود (TP-15)» | ADR-0012 | **وجود ندارد** | ۱۶ handler در `src/Application/Jobs/` — هیچ‌کدام |

مورد چهارم مرتبط: **`Repository Base`** که ADR-0003 ادعا می‌کند فیلتر `clinic_id` را متمرکز اعمال می‌کند نیز وجود ندارد — ۱۷ کلاس Repository، صفر `abstract class`، صفر `extends`.

مورد پنجم مرتبط: **Patient Merge** — جدول `cpms_patient_merges` و capability `cpms_patient_merge` هر دو وجود دارند، ولی `grep "function .*[Mm]erge"` روی `PatientService` و `PatientRepository` صفر نتیجه می‌دهد ⇒ **merge فقط schema است، بدون هیچ پیاده‌سازی.**

> **قاعدهٔ الزام‌آور (دستور Owner):** اسناد نباید طوری نوشته شوند که وجود فعلی این مکانیزم‌ها را القا کنند. ADRهای مربوطه با یادداشت «NOT IMPLEMENTED» علامت خورده‌اند (ADR-0002، ADR-0003، ADR-0012، ADR-0015).

---

## ۷. یافته‌های ساختاری تکمیلی

| یافته | جزئیات |
|---|---|
| لایهٔ Repository نیمه‌آماده است | ۷ فایل Repository هم‌زمان `clinic_id = %d` و `clinic_id = 1` دارند |
| `CpmsDb::table()` از `$wpdb->prefix` استفاده می‌کند | **نه `base_prefix`** ⇒ سازگاری Multisite هرگز ادعا نشده و بررسی نشده است |
| زمان از قبل UTC ذخیره می‌شود | `CpmsDb.php:234` از `gmdate()` استفاده می‌کند ⇒ AD-08 یک **توسعه** است نه بازنویسی |
| منطقهٔ زمانی امروز از Clinic خوانده می‌شود | `Settings.php:268` با fallback `'Asia/Tehran'` |
| نرمال‌سازی موبایل فقط ایران است | `MobileValidator::normalize()` خروجی canonical `09xxxxxxxxx`؛ **بدون E.164**؛ `mask()` → `0912***5678` |
| سه Capability به هیچ نقشی نگاشت نشده‌اند | `cpms_patient_archive`، `cpms_patient_merge`، `cpms_audit_read` |
| توزیع Capability روی نقش‌ها | SEC ۲۵ / DOC ۳۳ / ACC ۱۱ / MGR ۵ / PATIENT ۰ |
| `MigrationRunner` فقط forward-only است | `MigrationRunner.php:68-107`؛ `rollbackOne()` در نبود `down()` استثنا پرتاب می‌کند |
| DDL در MySQL تراکنشی نیست | ⇒ idempotency در هر Migration **اجباری** است، نه اختیاری |

---

## ۸. جمع‌بندی و انتقال به فاز بعد

**Phase 0 = CLOSED.** هیچ کدی تغییر نکرد. ۹ قید معماری قفل شدند. دو قید C-6 و C-8 در 2026-09-08 با نسخهٔ اصلاح‌شده جایگزین شدند و همان نسخه‌ها مرجع معماری پروژه‌اند.

**نگاشت قیدها به فازهای مالک (طبق Roadmap تأییدشدهٔ Owner):**

| قید | فاز مالک |
|---|---|
| C-6 (الگوی authorization دیرهنگام) | **Phase 1** — Security Hardening (بخش 1a، مستقل از Scope) |
| C-9 (`otp_tokens` / `rate_limits`) | **Phase 1** |
| C-1, C-2, C-3, C-4, C-7, C-8 | **Phase 2** — Multi-Clinic Core |
| C-5 | **Phase 3** — Role & Access Control |

**پیش‌نیاز ورود به Phase 1:** طراحی Phase 1 باید صریحاً در برابر همین ۹ قید و ۱۳ تصمیم AD-01..AD-13 (ADR-0031) ارائه و تأیید شود.

---

## ضمیمه — فرمان‌های اثبات کلیدی

```bash
# جدول‌ها (۴۱)
grep -rhoE "\$db->table\('[a-z_0-9]+'\)" src/Migrations/*.php | sort -u | wc -l

# Foreign Key (۳۹)
grep -rhoE "CONSTRAINT \`?fk_[a-z_0-9]+" src/Migrations/*.php | sort -u | wc -l

# FK روی clinic_id (۴)
grep -rnE "FOREIGN KEY *\(\`?clinic_id" src/Migrations/*.php

# hardcode (۵۴ در ۲۳ فایل)
grep -rnE "clinic_id\s*=\s*1\b|'clinic_id'\s*=>\s*1\b" src --include=*.php | wc -l
grep -rlE "clinic_id\s*=\s*1\b|'clinic_id'\s*=>\s*1\b" src --include=*.php | wc -l

# پارامتری سالم (۲۵ در ۱۱ فایل)
grep -rnE "clinic_id\s*=\s*%d" src --include=*.php | wc -l

# permission_callback (۶۷ __return_true / ۲۲ gated)
grep -rho "permission_callback[^,]*" src/Rest/*.php | sort | uniq -c

# مکانیزم‌های ناموجود (هر سه = ۰)
grep -rl "AccessPolicy" src --include=*.php | wc -l
grep -rn "resolvePatient" src --include=*.php | wc -l
grep -rn "function .*[Mm]erge" src/Application/Patients src/Infrastructure/Repository/PatientRepository.php | wc -l
```

*(مسیرها نسبی به `clinic-practice-management/`)*

---

## ۹. یادداشت اصلاحی — Phase 1A (2026-09-08)

این گزارش یک سند **تاریخی** است و اعداد آن عمداً بازنویسی نشده‌اند. یک تصحیح ثبت می‌شود:

| قلم | عدد این گزارش | عدد صحیح | ریشهٔ خطا |
|---|---:|---:|---|
| ثبت‌های `permission_callback` | ۸۹ | **۸۸** | یکی از تطابق‌های `grep` متنِ داخل **Docblock** در `BookingController.php:312` بود، نه یک ثبت واقعی |
| `permission_callback` گارددار | ۲۲ | **۲۱** | پیامد مستقیم مورد بالا (۶۷ + ۲۱ = ۸۸) |

اعداد **۸۰ Route زمان اجرا**، **۶۷ `__return_true`**، **۲۵ `admin_post_*`** و **۰ `wp_ajax`** در بازراستی Phase 1A **تأیید** شدند و تغییری نکرده‌اند. عدد ۸۰ به‌طور مستقل هم تأیید شد: شمارش مسیرهای یکتای `(controller, path)` پس از باز کردن حلقهٔ `DOCTOR_EVENTS` دقیقاً ۸۰ می‌شود.

وضعیت **جاری** (نه تاریخی) در [`../security/phase1-current-security-model.md`](../security/phase1-current-security-model.md) نگهداری می‌شود.
