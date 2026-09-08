# PHASE 2 PRE-IMPLEMENTATION GATE REPORT

> **نوع:** Pre-Implementation Check — فقط‌خواندنی. **هیچ کد/Migration/Schema از Phase 2 در این session نوشته نشده است.**
> **تاریخ:** 2026-09-08 · **Agent Session:** `arena/01a082db-doctor`
> **پایهٔ بررسی:** `cd29473` (شاخهٔ session؛ شامل بستن OD-9) روی `8087b42` (origin/main)
> **منبع الزامات:** دستور Session (§۶) · [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) · [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md)
> **قاعدهٔ اعداد:** همهٔ اعداد این گزارش **در همین session از کد فعلی استخراج مجدد شده‌اند** (فرمان‌ها در بند ۲). هیچ عددی از handoff/گزارش‌های قبلی کپی نشده است؛ موارد اختلاف با اسناد قبلی صریحاً «🔍 تصحیح» علامت‌گذاری شده است.

---

## ۱. هدف و دامنهٔ گزارش

پیش از شروع Phase 2 (Multi-Clinic Core)، بررسی مستقلِ وضعیت فعلی کد و انطباق مدل هدف — طبق دستور Owner — بدون پیاده‌سازی هیچ بخشی از Phase 2. خروجی: فهرست نقطه‌های تزریق، بدهی‌های موجود، اختلاف‌های سند-کد، و تصمیم‌های بازِ مسدودکننده.

## ۲. روش و تکرارپذیری اعداد

هر عدد با فرمان واقعی روی working tree در `cd29473` بازتولید شده است:

```
grep -h "CREATE TABLE" src/Migrations/*.php | …            → جدول‌ها (بند ۳)
grep -c "FOREIGN KEY" src/Migrations/*.php                  → FK (بند ۴)
grep -rnE "clinic_id\s*=\s*1\b|'clinic_id'\s*=>\s*1\b" src  → سرشماری hardcode (بند ۷)
git grep … 8087b42 / 79cce4b                                → تفاضل دریفتهای فاز 1A (بند ۷)
grep -c "    public const .*= 'cpms_" src/Auth/RolesAndCapabilities.php → ۵۲ (بند ۸)
grep -rc "register_rest_route" src | awk '{s+=$2} END…'     → ۷۷ (+۴ از DOCTOR_EVENTS) (بند ۱۳)
grep -hoE "admin_post_(cpms_[a-z_]+)" src | sort -u         → ۲۵ (بند ۱۳)
```

عدد PHPUnit/گیت‌ها فقط از GitHub Actions با run-id (بند ۱۶).

## ۳. Schema فعلی از Migrations (ورودی Phase 2)

| سنجه | مقدار تأییدشده | توضیح |
|---|---|---|
| فایل‌های Migration | **۹** (`2026_09_05_0001` … `2026_09_07_0009`) | ۰۰۰۱=Initial، ۰۰۰۲=ALTER durations، ۰۰۰۳=sms، ۰۰۰۴=handwriting(+FK ALTER)، ۰۰۰۵=notifications، ۰۰۰۶=idempotency scope، ۰۰۰۷=clinician-user unique، ۰۰۰۸=licensing، ۰۰۰۹=rate-limit window |
| جدول‌های ساخته‌شده | **۴۰** (۳۷ در ۰۰۰۱ + `sms_messages` در ۰۰۰۳ + `license_install`/`license_state` در ۰۰۰۸) | + `cpms_schema_migrations` توسط `MigrationRunner::ensureSchemaTable()` = **۴۱ کل** — هم‌خوان با probe گیت (`cpms-tables — tables=41`) |
| MigrationRunner | forward-only، افزایشی، تراکنش + ثبت نسخه | DDL در MySQL تراکنشی نیست ⇒ idempotency در migrationهای جدید **اجباری** (الگوی مرجع: SHOW INDEX/SHOW COLUMNS در ۰۰۰۴، ۰۰۰۶، ۰۰۰۷، ۰۰۰۸) |
| Seedهای موجود | `cpms_clinics` ردیف `id=1` (۰۰۰۱) و `cpms_license_install` (۰۰۰۸) | A1 معتبر: دادهٔ Production وجود ندارد |

## ۴. وضعیت Foreign Key ها

- **مجموع FK: ۳۹** = ۳۸ در CREATEهای ۰۰۰۱ + ۱ از ALTER در ۰۰۰۴ (`fk_hwpage_bg` → `medical_attachments` ON DELETE SET NULL).
- **FK به `cpms_clinics`: فقط ۴** — `fk_clinicians_clinic`، `fk_patients_clinic`، `fk_schedule_clinic`، `fk_services_clinic`.
- ⇒ از ۲۶ جدول دارای `clinic_id` (بند ۵)، **۲۲ جدول هیچ FK به Clinic ندارند** — موتور DB امروز هیچ تضمینی برای صحت `clinic_id` نمی‌دهد. (M-09 فاز ۲ باید ۲۲ constraint اضافه کند؛ نه ۲۱.)

## ۵. جدول‌های دارای `clinic_id` — 🔍 تصحیح نسبت به اسناد قبلی

سرشماری دقیق: **۲۶ جدول** ستون `clinic_id` دارند — نه ۲۵.

| یافته | جزئیات |
|---|---|
| ۲۵ جدول از ۰۰۰۱ | همان‌هایی که جدول «ب-۵» مدل هدف فهرست کرده است |
| 🔍 **`cpms_sms_messages` جا افتاده است** | `clinic_id INT UNSIGNED NOT NULL DEFAULT 1` (migration ۰۰۰۳) — در جدول نگاشت «ب-۵» مدل هدف **نیست** (فقط در پانوشت ذکر شده) و در فهرست «۲۱ جدول بدون FK» هم نیامده است. Phase 2 باید برای آن هم FK→clinics بگذارد یا تصمیم صریح ثبت کند |
| 🔍 ناسازگاری نوع ستون | ۲۳ جدول: `BIGINT UNSIGNED NOT NULL` · ۲ جدول: `BIGINT UNSIGNED NOT NULL DEFAULT 1` (`idempotency_keys`, `drug_reference`) · ۱ جدول: **`INT UNSIGNED NOT NULL DEFAULT 1`** (`sms_messages` — هم ناهم‌نوع و هم دارای DEFAULT) — برای ADD FK باید ابتدا به `BIGINT` هم‌نوع با `clinics.id` تبدیل شود |
| `DEFAULT 1` روی ستون | در **۳ جدول** (`idempotency_keys`, `drug_reference`, `sms_messages`) — پس از Multi-Clinic نباید مقدار پیش‌فرضِ `1` بماند (الگوی AD-13 در سطح schema) |

## ۶. UNIQUE constraintهای بحرانی Phase 2

سه‌گانهٔ شکستن UNIQUE (نه دوتا، كما اینکه مدل هدف فقط دو مورد را فهرست کرده بود):

| کلید | تعریف فعلی | مشکل با مدل هدف | اقدام لازم |
|---|---|---|---|
| `u_sched_day` | `(clinician_id, day_of_week)` | یک برنامه در روز برای کل سیستم — نه چند شعبه، نه دو شیفت | M-07: → `(clinic_id, location_id, clinician_id, day_of_week, start_time)` — 🔴 برگشت‌ناپذیر |
| `u_slot` | `(clinician_id, slot_date, slot_time)` | یکتایی slot از Location بی‌خبر است | M-07: → `(location_id, clinician_id, slot_date, slot_time)` — 🔴 برگشت‌ناپذیر |
| `u_clinician_user` (۰۰۰۷) | `UNIQUE (wp_user_id)` روی کل جدول | در نسخهٔ اول این گزارش «نقض AD-05» خوانده شد — *(🔍 تصحیح 2026-09-09: پس از تحلیل معنایی، رأی عوض شد)* AD-05 دربارهٔ **Membership** است نه Profile؛ ERD مصوب `WP_USER \|\|--o\| CLINICIAN` یک پروفایل به‌ازای کاربر را ایجاب می‌کند | **حفظ می‌شود** (بدون migration)؛ M:N از راه `cpms_clinic_memberships` + `clinicians.clinic_id` معنای «کلینیکِ خانه» می‌گیرد — تفصیل: `phase0.5-target-model.md` د-۶-۳ + گزارش نهایی §۸ |

## ۷. سرشماری `clinic_id = 1` — بدهی فنی Phase 2 و 🔍 رانش جدید فاز 1A

**سرشماری در `cd29473` (الگوی `clinic_id = 1` / `'clinic_id' => 1`):**

| سنجه | عدد | مقایسه با مدل هدف (پایهٔ `8087b42`) |
|---|---|---|
| تطابق متنی کل | **۵۶ در ۲۴ فایل** | ۵۴ در ۲۳ فایل |
| **اجرایی** (بدون خطوط کامنت) | **۵۲ در ۲۴ فایل** | ۵۱ اجرایی |
| کامنت‌های صرف | ۴ (`VisitService:326`, `ClinicianRepository:17`, `NotificationRepository:13`, `LoginRateLimiter:135`) | ۳ |
| داخل Repository | ۲۲ اجرایی | ۲۴ متنی |
| خارج از Repository | ۳۰ اجرایی | ۳۰ متنی |
| Default-Parameter خطرناک | **۳**: `AuditLogger.php:45 (?int $clinicId = 1)` · `Idempotency.php:37` · `Settings.php:145` | بدون تغییر |
| الگوی سالم `clinic_id = %d` | ۲۵ نقطه | ۲۵ — بدون تغییر |

**🔍 رانش فاز 1A (diff واقعی `8087b42` → `79cce4b`):**
- **+۱ hardcode اجراییِ جدید:** کامیت `4c16009` (OD-8، Phase 1A) نسخهٔ کپی‌شده‌ای از کوئری `WHERE clinic_id = 1 AND mobile = %s` در متد جدید `findExistingUser` اضافه کرد — **نقض AD-13**. *(🔍 تصحیح 2026-09-09: در نسخهٔ اول این گزارش، متد مقصر به‌اشتباه `resolveUser` ذکر شده بود؛ git blame متد صحیح را `findExistingUser` نشان داد — `resolveUser` از `8087b42` موجود بود. این رگرسیون در FINAL PRE-PHASE-2 GATE با fix کوچک اصلاح و بسته شد: کوئری تکراری + دو نمونهٔ پیشین فایل به helper پارامتریِ واحد با `Settings::clinicId()` تبدیل شدند؛ سرشماری اجرایی: ۵۲ → ۴۹.)*
- +۱ خط کامنت (`LoginRateLimiter:135` — توضیحِ ضدِ الگو، بی‌ضرر).
- Session فعلی (OD-9): **صفر** مورد جدید (verify با diff `79cce4b..cd29473`).

**جمع بدهی Phase 2:** ۵۲ اجرایی + ۳ Default-Parameter = **۵۵ نقطه در ۲۴ فایل** (به‌علاوهٔ تصمیم SMS/سایر جداول بند ۵).

## ۸. نقش‌ها و Capabilityها (ورودی مدل Membership)

`RolesAndCapabilities`: **۵۲ ثابت** = ۵ نقش (`cpms_doctor` ۳۳، `cpms_secretary` ۲۵، `cpms_accountant` ۱۱، `cpms_manager` ۵، `cpms_patient` ۰) + ۱ Option (`OPTION_OVERRIDE`) + **۴۶ Capability**. سه Capability بدون نقش: `cpms_patient_archive`، `cpms_patient_merge`، `cpms_audit_read`. نگاشت User↔Role کاملاً در `wp_usermeta` است؛ هیچ جدول `cpms_*` آن را نگه نمی‌دارد — منبع حقیقت جدید (memberships) در Phase 2/3 ساخته می‌شود (D0→D4، طبق Q4 dual-mode می‌ماند تا خروج عینی D2).

## ۹. انطباق ERD هدف با ADR-0031 — نتیجه: سازگار

هر ۱۶ تصمیم `AD-01…AD-16` در ADR-0031 وضعیت ✅ DECIDED دارند و ERD «ب-۱» مدل هدف با آن بی‌تناقض است: زنجیرهٔ اجباری `Organization → Clinic → Location` (AD-01..AD-04)، M:N + Scoped Role (AD-05/AD-06)، OTP سطح installation (AD-07)، TZ مستقل Location (AD-08)، جداسازی Clinical/Admin (AD-09..AD-11)، versioned forward migration (AD-12)، ممنوعیت hardcode (AD-13)، Patient Identity سطح Org + Record سطح Clinic با internal ID تغییرناپذیر (AD-14)، حداقل یک Location (AD-15)، namespace `cpms_org_*` (AD-16). **تناقض سند-کد یافت نشد؛ اختلاف‌های عددی در بندهای ۵، ۶ و ۷ تصحیح شد.**

## ۱۰. Cardinalityهای هدف (تأیید از ADR-0031)

| رابطه | Cardinality | نکتهٔ اجرایی Phase 2 |
|---|---|---|
| Organization → Clinic | `1 : N`، در هر دو جهت اجباری | `clinics.organization_id NOT NULL` — سه‌مرحله‌ای (M-02b) |
| Clinic → Location | `1 : N` با **حداقل ۱** (AD-15) | `location_id NOT NULL` در ۴ جدول عملیاتی — سه‌مرحله‌ای (M-06) |
| User ↔ Clinic | **M:N** از راه `cpms_clinic_memberships` (UNIQUE(clinic_id, wp_user_id)) | مانع فعلی: `u_clinician_user` (بند ۶) |
| Membership → Capability/Location | `1 : N` overrideهای دانه‌ریز | deny > grant > preset |
| Patient Identity → Patient record | Org-level identity، Clinic-owned records (AD-14) | `identity_id` روی patients؛ discovery بین‌کلینیکی ممنوع |
| Clinician ↔ Location | M:N (`cpms_clinician_locations`) | رفع C-7 |

## ۱۱. مرز Patient Identity / Clinical Record (AD-14)

- `cpms_patient_identities` در **Phase 2** ساخته می‌شود (سطح Organization)؛ `cpms_patients` ایزوله در سطح Clinic می‌ماند.
- کلید هویت = **immutable internal ID**؛ موبایل نرمال‌شده فقط lookup/verification (پیش‌فرض `MobileValidator::normalize()` — فقط ایران؛ `+971…` → null — توسعهٔ بین‌المللی تصمیم جداگانه می‌خواهد).
- الزام رمزنگاری باقی‌است: هر هش lookup باید **کلیددار** (HMAC-SHA256/Argon2id) روی خروجی normalize باشد.
- یافتهٔ کد فعلی مرتبط: `cpms_patient_merges` فقط schema است (**صفر پیاده‌سازی** — grep متدهای merge در PatientService/Repository خالی) و درون‌کلینیکی است؛ «ادغام Identity» نوع دومی است که هیچ رکورد بالینی جابه‌جا نمی‌کند. تغییر موبایل هنوز جریانی ندارد (`mobile_at_link` فقط snapshot است).
- عدم افشا: شکست لایهٔ مالکیت شیء ⇒ **404 نه 403**؛ `identity_id` هرگز در API سطح Clinic برنمی‌گردد.

## ۱۲. Timezone (AD-08)

- Canonical = UTC: `CpmsDb::nowUtc()/nowUtcSql()` از زمان UTC استفاده می‌کنند ✓ — لایهٔ DB آماده است.
- `locations.timezone NOT NULL` (مقدار اولیه از `clinics.timezone`).
- 🔍 **یافتهٔ دو منبع حقیقت TZ:** ردیف `cpms_clinics` فقط توسط seed مهاجرت نوشته می‌شود (`'Asia/Tehran'`)؛ SetupWizard فقط setting جداگانهٔ `setup.clinic.timezone` را ذخیره می‌کند و **هرگز ردیف `cpms_clinics` را UPDATE نمی‌کند**؛ `Settings::clinicTimezone()` از **ستون جدول** می‌خواند (با `$this->clinicId` که default آن ۱ است — بند ۷). این دو منبع می‌توانند واگرا شوند. Phase 2 باید: (۱) منبع seeding مکان‌ها را تعیین کند، (۲) Wizard را به‌روزرسانی کند تا Clinic/Location واقعی را بنویسد، (۳) پوشش تست DST را طبق ADR-0031 §۴ اضافه کند.

## ۱۳. نقاط تزریق ClinicContext (کجای کد باید Scope بگیرد)

امروز **هیچ کلاس `ClinicContext`/`ScopeContext`/`AuthorizationService` وجود ندارد** (جست‌وجو در src: صفر). نقاط تزریق فاز ۲:

| لایه | نقطه | حجم |
|---|---|---|
| Repository | فایل‌های دارای hardcode اجرایی (۲۲ مورد داخل + بخشی از ۳۰ خارج) | بازنویسی هر دو لایه Repository و Service — «فقط تزریق به Service» **باطل** است (تصحیح Phase 0.5 پابرجا) |
| REST | **۸۰ route در runtime** (۷۷ فراخوانی `register_rest_route` − ۱ داخل حلقهٔ `DOCTOR_EVENTS` ×۴ عضو) | منبع resolution طبق ج-۷: هدر `X-CPMS-Clinic-Id` → پارامتر → primary membership → تک‌membership → `CLINIC_SCOPE_REQUIRED` |
| admin_post | **۲۵ اکشن** (`cpms_*` یکتا) | ۱۴ مورد scope-dependent |
| AJAX | ۰ (`wp_ajax` وجود ندارد) | — |
| سازنده‌ها | `Settings::__construct(clinicId=1)` · `AuditLogger::__construct(clinicId=1)` · `Idempotency::check(clinicId=1)` | Default-Parameterها باید حذف شوند، نه تغییر مقدار |
| پس از Phase 3 | تک‌مسیره: فقط `AuthorizationService::can(wpUserId, capability, ScopeContext)` | + تست معماری `current_user_can('cpms_` |

## ۱۴. ترتیب Migration فاز ۲ و نقاط برگشت‌ناپذیر

نقشهٔ M-01…M-15 مدل هدف (د-۴) معتبر است؛ با این تصحیح‌ها/تأکیدها:
1. **M-07 دو UNIQUE** (`u_sched_day`, `u_slot`) — برگشت‌ناپذیر؛ `down()` صریح.
2. 🔍 **Migration جدید لازم برای `u_clinician_user`** (بند ۶) — سومین شکستن UNIQUE، با preflight الگوی ۰۰۰۷.
3. 🔍 **M-09 باید ۲۲ FK اضافه کند** (نه ۲۱ — بند ۵) + MODIFY نوع `sms_messages.clinic_id` به BIGINT.
4. M-02b و M-06 سه‌مرحله‌ای (NULL → UPDATE → NOT NULL) طبق AD-02/AD-15.
5. Seedها idempotent (`INSERT IGNORE`/`WHERE NOT EXISTS`): M-02 Org، M-04 Location، M-13 Membershipها.
6. هیچ جدول/ستونی حذف نمی‌شود (`clinicians.specialty` می‌ماند — Q11 باز).
7. `cpms_jobs`/`cpms_operational_logs` فقط `clinic_id NULL` می‌گیرند؛ `otp_tokens` مستثنا (Q5)؛ `rate_limits` معلق (Q6).
8. الزام CI جدید: upgrade path (نصب 1.0.0 از ZIP → migrationهای جدید → تأیید schema) — طبق ADR-0031 §۶.

## ۱۵. تصمیم‌های باز مسدودکننده/مرتبط

| مورد | وضعیت | اثر بر Phase 2 |
|---|---|---|
| Q6 — `cpms_rate_limits` سراسری/per-Clinic/دوسطحی | ⏳ NEEDS OWNER | فقط migration مربوط به rate_limits معلق می‌ماند؛ بقیهٔ Mها مسدود نیست |
| Q11 — حذف `clinicians.specialty` | ⏳ NEEDS OWNER | مسدودکننده نیست (ستون می‌ماند)؛ نرمال‌سازی specialty طبق نقشه انجام می‌شود |
| Q12 — UX تعویض Clinic | ⏳ NEEDS OWNER | تصمیم UX؛ مسیر backend ج-۷ مستقل از آن قابل ساخت است |
| 🔍 FK برای `cpms_sms_messages` (بند ۵) | NEW — باید در نقشهٔ M-09 بیاید | جزئی، لزوم تصمیم ثبت‌شده |
| 🔍 منبع TZ برای seeding Location + به‌روزرسانی Wizard (بند ۱۲) | NEW — طراحی Migration/Wizard | باید پیش از M-04 تعیین شود |
| مکانیزم دقیق هش lookup هویت (HMAC/Argon2id، کلید سرور) | الگومند در AD-14؛ انتخاب نهایی هنگام ساخت | فقط اگر ستون هش‌شده ساخته شود |

## ۱۶. وضعیت رگرسیون/گیت‌های این session (شواهد)

OD-9 در همین session بسته و push شد (`3531d5d` + fix تست `cd29473`؛ طبقه‌بندی خطای اول: **D — نقص تست**، بدون تغییر کد محصول). شواهد run-id و وضعیت نهایی گیت‌ها در [`report-od9-closure.md`](report-od9-closure.md) ثبت شده است. قابلیت‌های مالی (`cpms_payment_void`, `cpms_payment_refund`) دست‌نخورده‌اند؛ PHPStan lvl 3 بدون baseline سبز است؛ مجموعهٔ Integration فعلی **۴۸۱ تست** است (شمارش از خروجی رسمی run 34282802365؛ نتیجهٔ نهایی سبز در run 34283426837).

## ۱۷. جمع‌بندی و رأی

**انطباق مدل هدف با کد فعلی: تأیید شد** (بدون تناقض ساختاری؛ سه تصحیح عددی/نگاشتی در بندهای ۵، ۶، ۷ + دو یافتهٔ جدید در بندهای ۱۲ و ۱۵).

**فاز ۲ از نظر مهندسی آمادهٔ شروع است، به شرط:**
1. Mها طبق ترتیب د-۴ با سه تصحیح بند ۱۴ اجرا شوند (۲۲ FK، شکستن `u_clinician_user`، هم‌نوع‌سازی BIGINT).
2. بدهی ۵۵ نقطه‌ای (۵۲ hardcode + ۳ default-param) به‌طور کامل در همان فاز حذف شود — شامل رانش جدید `OtpService::resolveUser` (نقض AD-13 در فاز 1A).
3. بند ۱۲ (منبع TZ واحد + Wizard) پیش از M-04 تعیین تکلیف شود.
4. تصمیم Q6/Q11/Q12 طبق جدول بند ۱۵ (هیچ‌کدام کل فاز را مسدود نمی‌کنند).

**این گزارش صرفاً Pre-Implementation Check است؛ شروع پیاده‌سازی Phase 2 مستلبل دستور صریح Owner پس از پذیرش این گزارش است.**
