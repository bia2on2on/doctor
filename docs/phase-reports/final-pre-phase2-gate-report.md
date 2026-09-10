# FINAL PRE-PHASE-2 GATE REPORT

> **تاریخ:** 2026-09-09 · **Session:** `arena/01a082db-doctor` · **نوع:** فقط بررسی + یک fix کوچکِ صریحاً مأموریت‌یافته (بند ۶) — **هیچ schema/migration/domain implementation از Phase 2 انجام نشد.**
> **مبنای دستور:** FINAL PRE-PHASE-2 GATE (مالک، ۱۰ بند) · اسناد مرجع: [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) · [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md) (تصحیح‌شده: ب-۵ + د-۶) · [`phase1b-deferred-register.md`](../security/phase1b-deferred-register.md) (تصحیح‌شده)
> **قاعدهٔ اعداد:** همهٔ اعداد با فرمان واقعی روی working tree / Git تاریخ‌خورده بازتولید شده‌اند.

---

## ۱. هویت دقیق Branch/Repository (گزارش مستقیم از Git)

| سنجه | مقدار (بدون حدس) |
|---|---|
| `git branch --show-current` | **`arena/01a082db-doctor`** |
| `git rev-parse HEAD` (آغاز Gate) | `9d6cf41a2036244ae82d06436bd9238686f144fa` → پس از fix مأموریت‌یافتهٔ بند ۶: `bbc1e83…` (کامل در بند ۱۴) |
| `git remote -v` | `origin → https://github.com/bia2on2on/doctor.git` (fetch/push) |
| upstream محلی | **تنظیم نشده** (push صریح؛ `git rev-list @{u}...HEAD` → fatal — همان انتظار) |
| local vs remote HEAD | **یکسان** — `git ls-remote`: `refs/heads/arena/01a082db-doctor = 9d6cf41…` (پس از push: `bbc1e83`) |
| `origin/main` | `8087b42e19a1721e38eb073aa1a17aff1cbac97b` (بدون تغییر ✓) |
| `git status` | clean (پیش از شروع Gate) |
| PR شاخهٔ فعلی | **PR #11** — `[DO NOT MERGE] OD-9…` — **OPEN + DRAFT** ✓ |

**دربارهٔ اختلاف نام branch:** نام `arena/01a082d2-doctor` **در هیچ‌کجای remote وجود ندارد** — `git ls-remote --heads origin` دقیقاً این ۱۰ شاخه را برمی‌گرداند: `01a071c4`، `01a076ad`، `01a077e9`، `01a07b2e`، `01a07c01`، `01a07cdc`، `01a07d25`، `01a0808c`، **`01a082db`**، `main`. شاخهٔ این session از ابتدا `arena/01a082db-doctor` بوده (پیشین‌ترین سابقهٔ ثبت‌شده در `gh`: شاخهٔ session قبلی `arena/01a0808c-doctor` = PR #10). نتیجه: **«01a082d2» یک خطای خوانش/تایپی از «01a082db» است، نه شاخهٔ واقعی.** هیچ اقدامی لازم نیست؛ حدسی دربارهٔ منشأ آن زده نمی‌شود.

## ۲. وضعیت Remote/GitHub

اتصال در آغاز این Gate **برگشت** (خطای 401 دیروز گذرا بود؛ بدون هیچ workaround یا تغییر credential — فقط `gh auth status` مجدداً سبز شد). وضعیت commit نهایی `9d6cf41` (docs-only): **هر ۵ اجرای trigger شده سبز** — CI `34283426837` (PR) · Real-WP `34284357086` (PR) / `34284347413` (push) · Closure `34284347483` · Pilot `34284347475`. هیچ مشکلی باز نماند.

## ۳. HEAD نهایی این Gate

`bbc1e83` (پس از کامیت fix بند ۶؛ شاخه push شده). جزئیات کامیت‌ها در بند ۱۴.

## ۴. تصحیح رکورد عددی Phase 0/0.5 — ۲۶ جدول / ۲۲ بدون FK

بازشماری مستقل از Migrationهای واقعی (روش در [`phase2-pre-implementation-gate-report.md`](phase2-pre-implementation-gate-report.md) §۲؛ خروجی کامل ۲۶ ردیفی در `phase0.5-target-model.md` **د-۶-۲**):

- **۲۶ جدول دارای `clinic_id`** (فهرست کامل با نوع دقیق هر یک در د-۶-۲). **۴ جدول FK مستقیم دارند** (`clinicians`, `patients`, `schedule`, `services`) ⇒ **۲۲ جدول بدون FK** — تضمین صحت `clinic_id` توسط DB امروز فقط برای ۴ جدول است.
- **`cpms_sms_messages` تنها جدول ناهم‌نوع:** `INT UNSIGNED NOT NULL DEFAULT 1` (بقیه BIGINT). **DEFAULT 1 روی ۳ جدول**: `drug_reference`, `idempotency_keys`, `sms_messages`.
- **چرا census قبلی (۲۵/۲۱) خطا داشت:** شمارش جمع‌بندی فقط جدول‌های Migration **0001** را لحاظ کرد؛ `sms_messages` (ساختهٔ **0003**) در جدول «الف-۲» و در فهرست ۱۶تایی دیده شده بود اما در جمع‌بندی عددی و در نگاشت «ب-۵» نیامده بود.
- **اصلاح اسناد:** تصحیح traceable در `phase0.5-target-model.md` (بلوک «🔴 تصحیح نهایی Pre-Phase-2 Gate» پس از ب-۵) افزوده شد؛ **گزارش تاریخی Phase 0 history-rewrite نشد** و جداول اصلی دست‌نخورده ماندند. concern مهاجرتی INT→BIGINT + حذف DEFAULT 1 در همان بلوک و در M-08b (د-۶-۴) ثبت شد.

## ۵. حکم معنایی جدول SMS (`cpms_sms_messages`)

| پرسش | پاسخ (مبتنی بر کد/ADR-0025) |
|---|---|
| چرا clinic_id دارد؟ | پیام‌ها **رویدادهای درون-کلینیکی**‌اند (یادآوری نوبت/پیگیری بیمارِ همان کلینیک)؛ پیکربندی SMS (Provider/کلید/قالب/مانده) سطح نصب است، ولی **محتوا و گیرنده** per-clinic |
| tenant-owned یا global؟ | **tenant-owned عملیاتی** — گیرنده (موبایل = PII)، متن و `vars_json` (شامل نام/زمینهٔ بالینی)، dedupe و retry همه در زمینهٔ کلینیک رخ می‌دهند |
| FK به clinics منطقی است؟ | **بله — توجیه معنایی دارد، نه مکانیکی** (ایزولاسیون privacy/retention/audit per-clinic)؛ اما **پیش‌شرط**: `MODIFY INT→BIGINT` + حذف `DEFAULT 1` (M-08b) |
| location_id لازم دارد؟ | **خیر** — هیچ نیازمندی «ارسال per-location» وجود ندارد؛ هویت فرستنده Clinic/نصب است. افزودن آن بدون نیاز = شاخهٔ بی‌معنا (ضد AD-02) |
| retention/privacy | ⚠️ متن پیام + vars_json حاوی PII/زمینهٔ بالینی‌اند و **هیچ سیاست retention/cleanupای برای این جدول وجود ندارد** (برخلاف operational_logs که retention دارد) — رشد نامحدود PII. **ثبت به‌عنوان تصمیم مالک** (بند ۱۶) |
| قاعدهٔ تعمیم‌یافته | برای هر ۲۶ جدول، ماتریس معنایی کامل در `phase0.5` **د-۶-۲** (FK/Scope از ماهیت رکورد، نه صرف وجود ستون؛ ۲۲ FK فقط پس از توجیه هر ردیف) |

## ۶. رگرسیون `clinic_id = 1` — علت ریشه‌ای + وضعیت fix

**تحقیق (git blame روی `src/Application/Auth/OtpService.php`):**

| پرسش | پاسخ دقیق |
|---|---|
| خط چیست؟ | `' WHERE clinic_id = 1 AND mobile = %s AND status = %s ORDER BY id DESC LIMIT 1'` |
| کدام متد؟ | **`findExistingUser()`** (خط ۲۹۹ در 9d6cf41) — 🔍 *تصحیح: گزارش قبلی من به‌اشتباه `resolveUser` را مقصر می‌دانست؛ `resolveUser` (خط ۳۲۷) و insert آن (خط ۳۵۶) هر دو از `8087b42` **پیش از Phase 1A** موجود بودند* |
| executable یا تست/کامنت؟ | **executable** (کوئری SQL زنده در مسیر `verify` → purposeهای غیر-Provisioning) |
| کدام commit؟ | **`4c16009`** «security(auth): remove account creation from verify-mobile flow» — کار **OD-8 در Phase 1A** (2026-09-08 19:46 UTC) |
| چرا census قبلی نگرفتش؟ | census فاز 0.5 روی پایهٔ `8087b42` اجرا شده بود (قبل از کد Phase 1A)؛ در Phase 1A سرشماری مجدد اجرا نشد و **AD-13 هیچ tripwire خودکاری نداشت** (architecture test به Phase 2 موکول شده بود). +۱ نمونه در فایلِ از قبل آلوده در بازبینی دستی دیده نشد |
| semantics | **فرض tenant واقعی است** — یافتن بیمارِ فعالِ دارای موبایل در «کلینیک پیش‌فرض». مقدار `1` همان Clinic seed شده است؛ نام مشابه/مقدار دیگری در کار نیست |

**وضعیت: FIXED ✅ (کامیت `bbc1e83`) — forward fix کوچک، بدون history-rewrite و بدون ساخت مدل جعلی Phase 2:**

1. کوئری تکراری + دو نمونهٔ پیشینِ همین فایل → **یک helper واحد** `findActivePatientIdByMobile()` با الگوی موجودِ پارامتری `clinic_id = %d` و مقدار از **`Settings::clinicId()`** (پارامتر ساختگری موجود، پیش‌فرض = Clinic seed شده — یک منبع حقیقتِ از پیش موجود، نه مفهوم Scope جدید). getter عمومی `clinicId()` به Settings اضافه شد.
2. insert لینک بیمار⇄کاربر هم به Clinicِ پیکربندی‌شده مهر می‌خورد (نه literal 1).
3. **TEST:** `OtpFlowTest::testVerifyResolvesPatientInConfiguredClinicNotHardcodedDefault` — کلینیک دومِ واقعی + دو بیمار هم‌موبایل (کلینیک ۱ و ۲)؛ سرویس مقید به کلینیک ۲ باید بیمار کلینیک ۲ را وصل کند، لینک را با `clinic_id=2` بزند و بیمار کلینیک ۱ لینک نگیرد. (بدون mock — ردیف‌های واقعی DB.)
4. **DOC:** تصحیح B-15 در `phase1b-deferred-register.md` (ادعای نادرست «Phase 1A هیچ نمونه‌ای اضافه نکرده» حذف و واقعیت + fix ثبت شد) + تصحیح گزارش pre-implementation §۷ + مستندسازی «چرا بازطراحی کامل AD-14 همچنان Phase 2 است» در docblock خود متد.
5. نتیجهٔ سرشماری: **OtpService = صفر نمونهٔ اجرایی**؛ کل repo: **۵۲ → ۴۹ اجرایی** (۵۱ قبل از Phase 1A → رانش +۱ → fix سه نمونهٔ این فایل).

**چرا حذف کاملِ فرضِ Clinic ممکن نبود بدون Phase 2:** جریان OTP→اتصال بیمار ذاتاً به «پروندهٔ بالینی در یک Clinic» گره خورده؛ حذف Clinic از این جست‌وجو یعنی یا cross-clinic lookup (نقض AD-14/حریم خصوصی) یا fail-closed (شکستن پنل بیمارِ در حال کار). صریح/تک‌منبعی‌کردنِ آن از طریق پیکربندی موجود، حداقلِ scope-independent بود؛ بازطراحی نهایی مطابق AD-14 = Phase 2.

## ۷. Timezone — نقشهٔ وضع فعلی + پیشنهاد Source of Truth

**نقشهٔ کامل (همهٔ read/write sites با شاهد کد):**

| مورد | نقش | موقعیت‌ها |
|---|---|---|
| `cpms_clinics.timezone` (ستون) | **تنها writer:** seed مهاجرت 0001 (`'Asia/Tehran'`) — **هیچ UPDATE در runtime وجود ندارد**. **تنها reader:** `Settings::clinicTimezone()` (`WHERE id = clinicId`، fallback `'Asia/Tehran'`) | `Settings.php:276` |
| `Settings::clinicTimezone()` | تنها مسیر مصرف عملیاتی TZ: **۳ مصرف‌کننده** — `ApptReminderHandler:102` و `FollowUpReminderHandler:102` (محاسبهٔ ساعت محلی یادآوری) + `NotificationService:231` («ساعت مفهومی مطب» N-6) | — |
| setting `setup.clinic.timezone` | **writer:** SetupWizard گام کلینیک (`saveClinic:600`، whitelist چهار TZ). **reader:** فقط پیش‌انتخاب dropdown خود Wizard (`:269`) — **هیچ مصرف‌کنندهٔ عملیاتی ندارد** | `CpmsSetupWizard.php` |
| Core زمان‌بندی | Booking/Schedule کاملاً **UTC** canonical работают (`DateTimeZone('UTC')` در BookingService/ScheduleService؛ `CpmsDb::nowUtc`) — مطابق ADR-0013/AD-08 ✓ | — |

**🔴 نقص واقعیِ امروز (ثبت‌شده):** انتخاب TZ توسط اپراتور در Wizard **فقط در setting ذخیره می‌شود و هیچ اثر عملیاتی ندارد**؛ ۳ مصرف‌کنندهٔ واقعی ستونِ هرگز-به‌روز-نشدنی seed را می‌خوانند ⇒ اگر اپراتور در Wizard «Kabul/UTC» انتخاب کند، یادآوری‌ها همچنان با Asia/Tehran محاسبه می‌شوند. دو منبع حقیقت ناهم‌ارز (یکی فقط نمایشی، یکی عملیاتی اما write-ناپذیر).

**پیشنهاد Target (برای تصویب مالک — نه پیاده‌سازی):**
1. **`locations.timezone NOT NULL` = تنها source of truth عملیاتی** برای scheduling/availability/displayِ عملیاتِ آن Location (AD-08).
2. **`clinics.timezone` = default قطعیِ seeding** برای Locationهای جدید همان Clinic (deterministic) — **نه** fallback عملیاتی برای Locationهای موجود (همه NOT NULL دارند؛ مسیر موازی ساخته نمی‌شود — ضد AD-02). **Organization بدون TZ می‌ماند** (در ERD مصوب نیست؛ افزودنش تصمیم جداگانه می‌خواهد).
3. **نگاشت پیشنهادی M-04 (هنوز اجرا نشده):** برای هر Clinic، TZ اولیهٔ Location اصلی = `setup.clinic.timezone` اگر تنظیم و معتبر باشد (منعکسِ قصد صریح اپراتور) وگرنه `clinics.timezone` وگرنه `'Asia/Tehran'`؛ همراه با **گزارش preflight واگرایی** (کلینیک‌هایی که دو منبعشان فرق دارد) برای تصمیم آگاهانهٔ مالک.
4. پس از M-04: ۳ مصرف‌کننده از `Settings::clinicTimezone()` به resolution آگاه از Location (از زمینهٔ رویداد) مهاجرت می‌کنند؛ Wizard به جای setting، **ردیف Clinic/Location را می‌نویسد** (مسیر نوشتن واحد)؛ `setup.clinic.timezone` یا حذف می‌شود یا صراحتاً «فقط پیش‌انتخاب UI» مستند می‌شود.
5. **Hotfix پیشنهادی پیش از Phase 2 (نیازمند تأیید مالک):** Wizard هنگام ذخیره، ستون `cpms_clinics.timezone` را هم UPDATE کند تا محصول تک‌کلینیکیِ فعلی گمراه‌کننده نباشد — تغییر کوچک، اما خارج از mandate این Gate اجرا نشد.

## ۸. پیشنهاد Cardinality — Clinician / User / Membership

**وضع موجود:** `u_clinician_user = UNIQUE(wp_user_id)` روی `cpms_clinicians` (migration 0007، ADR-0027 Minor #12) + `clinics` NOT NULL FK + تست گارد `SecurityHardeningTest::testSecondClinicianWithSameWpUserIsRejectedByDb`. تعارض ظاهری با AD-05 (M:N).

**تفکیک سه مفهوم (پیشنهاد):**

| مفهوم | موجودیت | Cardinality |
|---|---|---|
| **WordPress User identity** | `wp_users` | سراسری — یک به‌ازای انسانِ واردشونده (بدون تغییر) |
| **Clinician professional profile** | `cpms_clinicians` | **یک ردیف به‌ازای wp_user** — `u_clinician_user` **حفظ می‌شود** (دقیقاً همان `WP_USER \|\|--o\| CLINICIAN` در ERD مصوب ب-۱). `clinic_id` معنای جدید: **«کلینیکِ خانه»** — مقدار اولیهٔ deterministic (کلینیکِ سازندهٔ پروفایل)؛ **هرگز مرز مجوز/دامنهٔ داده نیست** |
| **Clinic membership/assignment** | `cpms_clinic_memberships` (M:N، AD-05/AD-06) + `cpms_clinician_locations` (M:N پزشک↔Location؛ `UNIQUE(clinician_id, location_id)` + `is_primary`) | رابطهٔ واقعی «پزشک در چند کلینیک/شعبه» |

**چرا این مدل و نه شکستن UNIQUE:** AD-05 دربارهٔ **Membership** است نه Profile؛ حذف UNIQUE و ساخت «پروفایل تکراری per-clinic» دقیقاً همان چیزی است که ERD مصوب **ایجاب نمی‌کند** (برعکس، یک پروفایل به‌ازای کاربر را می‌خواهد) و دادهٔ حرفه‌ای (نام/تخصص) را duplicate می‌کند. رکوردهای بالینی (visits/prescriptions/…) از قبل `clinic_id` مستقل روی خود رکورد دارند و به `clinicians.clinic_id` وابسته نیستند — ایزولاسیون دست نمی‌خورد. **کاری که Phase 2 باید بکند Query-level است، نه Schema-level:** فهرست «پزشکان کلینیک X» از `WHERE clinicians.clinic_id = X` به Membership-driven منتقل شود؛ `ownClinician` سراسری باقی می‌ماند (همان LIMIT 1 امروز). Doctor↔Location = `clinician_locations` (مکان‌ها از راه Location به Clinic وصل‌اند؛ schedule در `schedule.location_id` ∩ محل‌های مجاز پزشک). **تأیید نهایی این semantics = تصمیم مالک (بند ۱۶).**

## ۹. ترتیب Migration به‌روزشده

کامل در `phase0.5-target-model.md` **د-۶-۴** (versioned-forward؛ drop/recreate ممنوع؛ idempotency اجباری؛ CI upgrade-path). خلاصهٔ تغییرات نسبت به د-۴ قبلی:
- **M-08b 🆕** — تصحیح نوع/DEFAULT: `sms_messages.clinic_id` INT→BIGINT؛ حذف DEFAULT 1 از ۳ جدول.
- **M-09** — **۲۲ FK** (نه ۲۱)، هر یک پس از preflight orphan-check (الگوی 0007)؛ + در صورت تأیید B-11، `audit_logs.clinic_id` NULL-able برای رویدادهای پیش از Scope.
- **هیچ migration برای `u_clinician_user`** (بند ۸) — «شکنندهٔ سوم» از فهرست حذف شد.
- ماتریس per-table کامل (۲۶ ردیف: کلید فعلی/رابطهٔ هدف/FK/nullability/ایندکس/ترتیب/ملاحظهٔ معنایی) = **د-۶-۲**.

## ۱۰. تصمیم سه UNIQUE

| کلید | تصمیم | Test plan |
|---|---|---|
| `u_sched_day` | 🔴 شکستن → `u_sched_slot (clinic_id, location_id, clinician_id, day_of_week, start_time)` | preflight duplicate (fail-loud، بدون تغییر داده) → پس از مهاجرت: دو شیفت هم‌روز مجاز / دو شعبه مجاز / تکرار دقیق رد → idempotent (SHOW INDEX) → `down()` بازسازی کلید قدیم → CI upgrade-path |
| `u_slot` | 🔴 شکستن → `(location_id, clinician_id, slot_date, slot_time)` | همان الگوی پنج‌مرحله‌ای، نسخهٔ slot |
| `u_clinician_user` | ✅ **حفظ** (تصحیح رأی — بند ۸) | گارد موجود حفظ می‌شود؛ تست جدید: یک wp_user + Membership در ۲ کلینیک + یک ردیف clinician + schedule در Locationهای هر دو |

## ۱۱. مرز Phase 1B / Phase 3 (بر پایهٔ Deferred Register — ۱۷ قلم مادهٔ معوقِ مجوزی B-01..B-17 از مجموع ۲۱)

| دسته | اقلام | Phase 2 (فقط infrastructure/primitives) | Phase 3 (authorization policy) |
|---|---|---|---|
| مالکیت/Scope شیء | B-01..B-08 | **ScopeContext + فیلتر Scope در Repository** (ایزولاسیون دادهٔ مکانیکی — B-02, B-03, B-04, B-05, B-06, B-08)؛ B-01 زمینهٔ Scope بدون enforce | **`AuthorizationService::can()` authoritative** (D2→D3)، ماتریس Capability نهایی (B-07)، deny/grant overrides، Break-Glass |
| Rate Limit | B-09, B-10 | schema دوطرفه (اگر Q6 تصویب شود) | سیاست سقف‌ها |
| Audit | B-11, B-12 | `audit_logs.clinic_id NULL`-able + حذف Default-Parameterها (B-11) | رویدادهای ورودِ پیش از Scope، Audit سطح Org (B-12) |
| هویت بیمار | B-14..B-17 | جدول `cpms_patient_identities` (B-14)؛ helperهای resolution (B-16)؛ قید HMAC در صورت هش (B-17) | سیاست merge/discovery/دسترسی بین‌کلینیکی؛ جریان تغییر موبایل (B-15) |
| سایر (B-18..B-21) | ۴ قلم غیرمجوزی | — | B-19 فاز ۳ · B-18 فاز ۱۷ · B-20 با OD-5 · **B-21 ✅ CLOSED (OD-7/OD-9)** |

**مرز صریح:** Phase 2 = مدل داده + Context + ایزولاسیون Repository + shadow-mode (D1). **Phase 2 به Role Management کامل تبدیل نمی‌شود** — enforce سیاستی، ماتریس نهایی، Break-Glass و حذف legacy = Phase 1b/3 طبق Q4 (D0..D4). قابلیت‌های مالی (`cpms_payment_void`/`cpms_payment_refund`) طبق قید دائمی تغییر نمی‌کنند.

## ۱۲. Test/Gate Plan فاز ۲ (دسته‌بندی — پیش از implementation)

| # | دسته | رویکرد (بدون شبیه‌سازی `clinic_id = 1`) |
|---|---|---|
| 1 | Schema migration | هر M: idempotent re-run + partial-failure safe + `down()` برای M-07 |
| 2 | Upgrade path | job جدید CI: نصب ZIP `1.0.0` → migrationهای فاز ۲ → تأیید schema (SHOW INDEX/FK) + تست‌ها سبز |
| 3 | FK integrity | رد insert با clinic_id ناموجود (۲۲ FK جدید)؛ orphan preflight fail-loud |
| 4 | Organization isolation | ≥۲ Org واقعی؛ هیچ queryای دادهٔ Org دیگر را برنمی‌گرداند |
| 5 | Clinic isolation | ≥۲ Clinic واقعی + بیماران هم‌موبایل در هر دو — T-01/T-03/T-04/T-05 دفترچه 1B با ردیف‌های واقعی |
| 6 | Location isolation | ≥۲ Location در یک Clinic؛ صف/نوبت per-location |
| 7 | User multi-clinic membership | یک wp_user عضو ۲ Clinic؛ فقط کلینیک فعال دیده می‌شود (T-02) |
| 8 | نقش متفاوت per-clinic | Membership با role_key متفاوت؛ Capability از Membershipِ همان کلینیک |
| 9 | Doctor multi-location | یک clinician + ۲ Location؛ schedule/slot در هر دو؛ تعارض ظرفیت در مرز Location |
| 10 | Patient Identity vs Clinical Record | Identity مشترک Org؛ رکورد بالینی ایزوله؛ `identity_id` هرگز در API سطح Clinic برنمی‌گردد (AD-14) |
| 11 | Timezone/Location scheduling | `locations.timezone` مقدم بر همه؛ DST edge (تغییر ساعت) در تبدیل UTC↔local؛ preflight واگرایی TZ (بند ۷) |
| 12 | **No clinic_id=1 regression** | 🆕 **tripwire معماری**: تست شمارش hardcode اجرایی با snapshot ثبت‌شده (فعلاً ۴۹) — هر رشد = fail؛ به‌روزرسانی snapshot فقط با حذف بدهی |
| 13 | Context propagation | Repository/Service همه از ScopeContext می‌خوانند؛ تست اینکه هیچ مسیر CRUDای بدون Context اجرا نمی‌شود (fail-closed) |
| 14 | UNIQUEها | پوشش بند ۱۰ (هر دو کلید شکسته + گارد u_clinician_user) |
| 15 | OTP/هویت | مسیر OTP→بیمار با Clinicِ پیکربندی‌شده (تست این Gate به‌عنوان baseline) و پس از AD-14 با Identity |

**قاعده:** تست isolation با mock/کلینیک‌غیرواقعی ممنوع — fixture باید Clinic/Location/بیمار واقعی دوم بسازد (الگوی تست بند ۶ همین گزارش).

## ۱۳. فایل‌های تغییر‌یافته در این Gate

| فایل | تغییر |
|---|---|
| `src/Application/Auth/OtpService.php` | helper واحد پارامتری + حذف ۳ hardcode + docblock (بند ۶) |
| `src/Settings/Settings.php` | getter عمومی `clinicId()` |
| `tests/Integration/OtpFlowTest.php` | تست رگرسیون AD-13 جدید |
| `docs/security/phase1b-deferred-register.md` | تصحیح B-15 · B-21 CLOSED |
| `docs/phase-reports/phase2-pre-implementation-gate-report.md` | تصحیح‌های traceable (اتریبوشن متد · DEFAULT-1=۳ · رأی u_clinician_user) |
| `docs/architecture/phase0.5-target-model.md` | بلوک تصحیح ۲۶/۲۲ + **د-۶** (ماتریس معنایی، تصمیم UNIQUEها، ترتیب اصلاح‌شده) |
| `docs/phase-reports/final-pre-phase2-gate-report.md` | همین گزارش |
| `docs/agent-guide.md` | لاگ session (append-only) |

## ۱۴. کامیت‌ها

| SHA | عنوان | محتوا |
|---|---|---|
| `bbc1e83` | `fix(auth): close AD-13 regression in OTP patient lookup (forward fix…)` | CODE + TEST + DOC (بند ۶) — push شده برای CI |
| `docs(gate): final pre-phase-2 gate report + phase0.5 semantic matrix` | گزارش نهایی + د-۶ + تصحیح‌ها + لاگ session |

## ۱۵. نتایج CI/Gate

- **پایهٔ Gate (`9d6cf41`, docs-only):** هر ۵ اجرا سبز — CI `34283426837` · Real-WP `34284357086`/`34284347413` · Closure `34284347483` · Pilot `34284347475`.
- **fix بند ۶ (`bbc1e83`): هر ۵ اجرا سبز ✅** (Integration شامل تست رگرسیون جدید AD-13 — شمارش تست‌ها از خروجی رسمی run 34282802365: ۴۸۱ + ۱ جدید):

| Workflow | Run ID | نتیجه |
|---|---|---|
| CI (PHPStan lvl3 + Unit 8.1–8.4 + Integration WP6.7/MySQL8) | 34286105777 | ✅ GREEN (1m45s) |
| Real WP Acceptance (PR #11) | 34286105875 | ✅ GREEN (3m27s) |
| Real WP Acceptance (push) | 34286100988 | ✅ GREEN (4m15s) |
| Closure Gate | 34286100991 | ✅ GREEN (1m31s) |
| Pilot/Staging Gate (شامل Restore Drill) | 34286100996 | ✅ GREEN (9m26s) |

## ۱۶. تصمیم‌های باز مالک (OWNER DECISIONS)

| # | تصمیم | اثر |
|---|---|---|
| 1 | **Q6** — دامنهٔ `cpms_rate_limits` (سراسری/per-Clinic/دوسطحی) | فقط M مربوط به rate_limits |
| 2 | **Q11** — حذف `clinicians.specialty` | فاز جداگانه؛ مسدودکننده نیست |
| 3 | **Q12** — UX تعویض Clinic | مسیر backend مستقل قابل ساخت |
| 4 | **Semantics «کلینیکِ خانه» + حفظ `u_clinician_user`** (بند ۸) | پیش‌نیاز شروع M-10..M-13 |
| 5 | **پیشنهاد Source of Truth زمانی + نگاشت M-04** (بند ۷) — ازجمله precedenceِ setting بر ستون | پیش‌نیاز M-04 |
| 6 | **Hotfix پیشنهادی TZ در Wizard** (بند ۷-۵) | کیفیت محصول تک‌کلینیکی فعلی |
| 7 | **retention پیام‌های SMS** (بند ۵) | privacy؛ مستقل از فاز |
| 8 | **B-11 — `audit_logs.clinic_id` NULL-able** برای رویدادهای پیش از Scope | در M-09 |
| 9 | **مکانیزم هش lookup هویت** (HMAC/Argon2id) — فقط اگر ستون هش ساخته شود | M-15 |

## ۱۷. رأی نهایی: READY / NOT READY برای Phase 2 Implementation

**✅ شرط مهندسی برآورده شد — فقط منتظر تصمیم مالک:**
1. ~~سبز شدن هر ۵ workflow روی `bbc1e83`~~ — ✅ انجام شد (بند ۱۵).
2. **تصویب مالک بر تصمیم‌های شمارهٔ ۴ و ۵ بند ۱۶** (semantics «کلینیکِ خانه» + حفظ `u_clinician_user`؛ و Source of Truth زمانی/نگاشت M-04) — این دو مستقیماً روی M-04/M-07/M-10..M-13 اثر می‌گذارند و بدون آنها implementation شروع نمی‌شود.

سایر تصمیم‌های بند ۱۶ مسدودکننده نیستند. مدل هدف، ماتریس ۲۶ جدولی، ترتیب migration و تست‌پلن به‌روز و کامل است (`phase0.5` د-۶). **پس از تصویب موارد ۴ و ۵، Phase 2 آمادهٔ شروع implementation است.**

---

> ⛔ **STOP** — طبق دستور: هیچ migration/schema implementation فاز ۲ انجام نشد؛ merge/tag/release/تغییر main انجام نشد؛ PR #10 دست‌نخورده (OPEN+DRAFT)؛ فایل‌های untracked محافظت‌شده لمس نشدند. انتظار دستور مالک.
