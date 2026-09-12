# ADR-0031 — معماری مرجع: `Organization → Clinic → Location` + عضویت و مجوزدهی Scoped

| | |
|---|---|
| **وضعیت** | **Accepted** |
| **تاریخ** | 2026-09-08 |
| **تصمیم‌گیر** | Product Owner (تصمیم محصول/معماری — الزام‌آور) |
| **نوع** | **Superseding ADR** — معماری مرجع آیندهٔ محصول |
| **جانشین می‌شود** | ADR-0003 (کامل) · ADR-0027 (لایهٔ مدل دامنه) · ADR-0026 (فقط بخش‌های Scope و زمان‌بندی) |
| **شواهد پایه** | `docs/phase-reports/report-phase-0-reverification.md` (۹ قید C-1..C-9) · `docs/architecture/phase0.5-target-model.md` (مدل هدف + برنامهٔ Migration) |
| **فاز اجرا** | Phase 2 (Multi-Clinic Core) و Phase 3 (Role & Access Control) طبق Roadmap تأییدشدهٔ Owner |
| **به‌روزرسانی** | 2026-09-08 — AD-14 (Q2)، AD-15 (Q8)، AD-16 (Q10) افزوده شدند |
| **به‌روزرسانی ۲** | 2026-09-12 — §۸ «الزام دائمی محصول: یک هسته، سه توپولوژی استقرار» (AD-17) با تصمیم صریح Owner افزوده شد — فقط مستندات؛ بدون تغییر schema/کد |

---

## Context

سه سند تأییدشدهٔ قبلی، معماری چند-مستأجری محصول را بر پایهٔ مدل زیر تعریف کرده بودند:

```
Clinic → Branch(es) → Departments/Specialties → Clinicians        (ADR-0027)
clinic_id در همه جداول پزشکی؛ V1 مقدار ثابت 1                      (ADR-0003)
Scope مفهومی OWN/ASSIGNED_DOCTORS/BRANCH/CLINIC — پیاده‌سازی V2    (ADR-0026)
```

ممیزی Phase 0 (فقط‌خواندنی، با شواهد اجرایی) نشان داد این مدل با واقعیت کد و با نیاز محصول در دو محور اساسی فاصله دارد:

1. **هیچ لایهٔ Organization در مدل وجود ندارد** (C-1)، و لایهٔ فیزیکی مکان هم نه به‌عنوان موجودیت مدل شده و نه پیاده شده است (C-2). واژهٔ «Branch» در ADR-0027 هرگز به schema نرسید.
2. **`clinic_id = 1` که ADR-0003 آن را «ثابت V1» نامیده بود، به ۵۴ نقطه در ۲۳ فایل تبدیل شد** (C-4) — از جمله ۲۴ مورد داخل لایهٔ Repository. هم‌زمان، از ۲۵ جدول دارای `clinic_id`، تنها ۴ جدول FK واقعی به `cpms_clinics` دارند (C-8)، و `cpms_clinicians` با `UNIQUE u_clinician_user (wp_user_id)` عملاً عضویت یک پزشک در چند Clinic را غیرممکن کرده است (C-7).

علاوه بر این، سه مکانیزمی که ADRهای قبلی به‌عنوان ابزار تحقق این معماری معرفی کرده بودند — `AccessPolicy`، `resolvePatient()` و Dangling-Check Job — **در کد وجود ندارند** (§۶ گزارش Phase 0).

بازبینی «آمادگی چندپزشکی» در 2026-09-06 حکم «۰ FOUNDATIONAL CHANGE REQUIRED» صادر کرده بود. ممیزی Phase 0 این حکم را رد می‌کند.

---

## Decision

### ۱. مدل دامنهٔ مرجع (جایگزین مدل ADR-0027)

```
Organization
   └── Clinic            (هر Clinic دقیقاً به یک Organization تعلق دارد)
         └── Location    (هر Clinic حداقل یک Location دارد)
               └── Doctor / User / Staff
```

- **AD-01 — Multi-Clinic / Multi-Location جزو Core است.** نه افزودنی، نه V2، نه Feature Flag.
- **AD-02 — Organization اجباری است.** Core حق ندارد مسیر موازی برای `Organization = NULL` بسازد. هیچ شاخهٔ شرطی `IF organization IS NULL` مجاز نیست.
- **AD-03 — هر Clinic دقیقاً به یک Organization تعلق دارد:** `clinics.organization_id NOT NULL` + FK.
- **AD-04 — مطب تک‌پزشکی هم دقیقاً از همین زنجیره استفاده می‌کند.** «حالت مطب» فقط یک تطبیق UX است (مثلاً Skip خودکار انتخاب پزشک وقتی یک Clinician فعال است) و **هرگز یک مسیر کدی جدا نیست.** این بند اصل One-Product/One-Core از ADR-0027 را حفظ و تأیید می‌کند.
- **واژگان:** «Branch» در ADR-0027 و «شعبه» در SRS/ERD از این پس معادل **Location** خوانده می‌شوند. اسناد جدید فقط «Location» به کار می‌برند.

### ۲. عضویت و مجوزدهی Scoped (جایگزین بخش Scope در ADR-0026)

- **AD-05 — `User ↔ Clinic` رابطهٔ M:N است.** یک کاربر می‌تواند هم‌زمان عضو چند Clinic باشد.
- **AD-06 — Role/Authorization باید Scoped باشد.** نقش **صفتِ رابطهٔ (User, Clinic)** است، نه صفتِ User. یک کاربر می‌تواند در Clinic A پزشک و در Clinic B مدیر باشد.
- منبع حقیقت مجوز پس از Phase 3:
  `cpms_clinic_memberships` (عضویت + Preset نقش) + `cpms_membership_capabilities` (override دانه‌ریز؛ اولویت `deny > grant > preset`) + `cpms_membership_locations` (محدودهٔ مکانی).
- نقطهٔ ورود واحد: `AuthorizationService::can(wpUserId, capability, ScopeContext{clinicId, ?locationId, ?resource})`.
- فراخوانی مستقیم `current_user_can('cpms_…')` بیرون از این سرویس **ممنوع** می‌شود؛ اجرای این قاعده به یک architecture test سپرده می‌شود.
- شکست لایهٔ مالکیت شیء ⇒ پاسخ **404**، نه 403 (تا وجود منبع افشا نشود).
- **این بند اصل D-1 از ADR-0026 را نقض نمی‌کند؛ آن را تکمیل می‌کند:** تصمیم مجوز همچنان بر پایهٔ Capability است نه نام نقش — فقط ارزیابی Capability از سطح سراسری به سطح (User, Clinic) منتقل می‌شود.

### ۳. هویت، بیمار و حریم خصوصی

- **AD-07 — OTP در سطح Identity/Installation است، نه Authorization کلینیک.** `cpms_otp_tokens` عمداً `clinic_id` نمی‌گیرد. اگر OTP برای یک workflow خاص صادر شود، purpose/context آن به‌شکل امن ثبت می‌شود؛ مجوز کلینیک جداگانه و پس از احراز هویت ارزیابی می‌گردد.
- **AD-09 — Patient Identity می‌تواند shared باشد، ولی Clinical Record و medical access باید scope-isolated باشند.** دسترسی بالینی ضمنی بین‌کلینیکی **ممنوع** است. این بند بند ۵ ADR-0027 («Patient موجودیت clinic-level است») را حفظ و به سطح Organization تعمیم می‌دهد.
- ⚠️ **قید باز:** *جهت* «هویت مشترک + رکورد بالینی ایزوله» تصویب شده است، اما **مکانیزم identity-resolution/hashing هنوز تصویب نشده** (Q2). تا تصویب صریح، جدول `cpms_patient_identities` ساخته نمی‌شود. الزام فنی ثبت‌شده: فضای شمارهٔ موبایل ایران ~۱۰⁹ و کد ملی ~۱۰¹⁰ است ⇒ هش خام SHA-256 با rainbow table معکوس‌شدنی است؛ هر هش هویتی باید **کلیددار** باشد (HMAC-SHA256 یا Argon2id) و روی خروجی `MobileValidator::normalize()` محاسبه شود، نه روی ورودی خام.

### ۳-۱. Patient Identity — تصمیم نهایی Q2 (Owner، 2026-09-08)

**AD-14 — مدل هویت بیمار تثبیت شد:**

| قاعده | تصمیم |
|---|---|
| سطح **Patient Identity** | **Organization** |
| سطح **Clinical Patient Record** | **Clinic** — ایزوله |
| کلید هویت | هر Identity یک **immutable internal ID** دارد |
| نقش شمارهٔ موبایل | موبایل نرمال‌شده **می‌تواند** برای lookup/verification استفاده شود، ولی **به‌تنهایی primary/immutable identity key نیست** |
| تغییر موبایل · تشخیص تکراری · merge · identity resolution | همه باید **explicit و audit‌شده** باشند — هیچ‌کدام ضمنی یا خودکارِ بی‌صدا نیست |
| دید بالینی بین‌کلینیکی | **هیچ cross-clinic medical visibility ضمنی مجاز نیست** |

**پیامدهای طراحی که از این تصمیم مستقیماً نتیجه می‌شوند:**

1. مسئلهٔ امنیتی هش که در Phase 0.5 مطرح شد **حل می‌شود بدون نیاز به تصمیم رمزنگاری:** چون کلید هویت یک `internal_id` تغییرناپذیر است و موبایل فقط یک **صفت قابل تغییرِ lookup** است، دیگر لازم نیست موبایل به یک کلید هش‌شدهٔ برگشت‌ناپذیر تبدیل شود. اگر هر ستون lookup هش شود، همچنان باید **کلیددار** باشد (HMAC-SHA256) و روی خروجی `MobileValidator::normalize()` محاسبه گردد — نه روی ورودی خام.
2. **`MobileValidator::normalize()` امروز فقط ایران را پشتیبانی می‌کند** (خروجی canonical `09xxxxxxxxx`؛ `+971…` → `null`). هر توسعهٔ بین‌المللی نیازمند تصمیم جداگانه است.
3. **تغییر موبایل نیازمند re-verification است.** ستون موجود `cpms_patient_user_links.mobile_at_link` امروز فقط snapshot است و برای این کار استفاده نمی‌شود.
4. **Merge موجود قابل اتکا نیست:** `cpms_patient_merges` فقط schema است (پیاده‌سازی صفر) و درون‌کلینیکی است. «ادغام Identity» در سطح Organization یک نوع **دوم** است که هیچ رکورد بالینی جابه‌جا نمی‌کند.
5. Discovery بین‌کلینیکی («این بیمار در کلینیک دیگری هم پرونده دارد») خودش یک افشای اطلاعات پزشکی است و **بدون سیاست صریح مجاز نیست**.

**فاز مالک:** ساخت `cpms_patient_identities` در **Phase 2**؛ سیاست‌های دسترسی در **Phase 3**؛ merge/duplicate UI در فاز مربوطه.

### ۳-۲. Location اجباری — تصمیم نهایی Q8 (Owner، 2026-09-08)

**AD-15:**

- **هر Clinic باید حداقل یک Location داشته باشد.**
- **Single Doctor / Single Clinic هم یک Location واقعی دارد** — نه ساختگی، نه اختیاری.
- **Scheduling/Appointment در آینده نباید حالت special-case «بدون Location» داشته باشد.**

**پیامد مستقیم بر Migration:** `location_id` در `cpms_schedule`، `cpms_schedule_slots`، `cpms_appointments` و `cpms_visits` **`NOT NULL`** می‌شود (سه‌مرحله‌ای: `ADD NULL` → `UPDATE` → `MODIFY NOT NULL` → `ADD FK`). این تصمیم گزینهٔ `NULL`-able را که شاخهٔ شرطی می‌ساخت، حذف می‌کند — دقیقاً هم‌راستا با AD-02.

### ۳-۳. Namespace قابلیت سازمان — تصمیم نهایی Q10 (Owner، 2026-09-08)

**AD-16:**

- **Namespace قابلیت‌های سازمان = `cpms_org_*`.**
- **System/Organization Administration از Clinical Data Access جدا نگه داشته می‌شود** (تقویت AD-10 و AD-11).
- **فعلاً یک capability واحد همه‌کاره ساخته نمی‌شود** — الگوی `cpms_{resource}_{action}` که در permission-matrix P-4 تثبیت شده حفظ می‌شود.
- **ماتریس نهایی قابلیت‌ها در Phase 3 تثبیت می‌شود** — نه زودتر.

⛔ به‌طور مشخص: ساختن یک `cpms_org_manage` سراسری در Phase 1A یا Phase 2 **ممنوع** است.

### ۴. زمان

- **AD-08 — Location منطقهٔ زمانی مستقل دارد:** `locations.timezone NOT NULL`.
- Canonical = UTC (وضعیت فعلی کد از قبل درست است: `CpmsDb::now()` از `gmdate()` استفاده می‌کند). زمان‌بندی/دسترس‌پذیری/نمایش در منطقهٔ زمانی همان Location محاسبه می‌شود.
- این بند ADR-0013 را **نقض نمی‌کند؛ گسترش می‌دهد**: `clinics.timezone` جای خود را به‌عنوان مقدار پیش‌فرض حفظ می‌کند و `locations.timezone` مرجع مؤثر می‌شود. پوشش تست DST و تبدیل منطقهٔ زمانی در فاز مربوطه الزامی است.

### ۵. مرز اداری در برابر مرز بالینی

- **AD-10 — WordPress `administrator` هیچ blanket clinical-data bypass ندارد.** (این بند اصل P-3 از permission-matrix و ADR-0002 را تأیید و تقویت می‌کند.)
- **AD-11 — System Administration از Clinical Data Access جداست.** System Administration = نصب، بکاپ، لایسنس، پیامک، Health (`manage_options`). Clinical Data Access فقط از مسیر `AuthorizationService::can()`.
- **Break-Glass** به‌عنوان مکانیزم ضد قفل‌شدگی **طراحی** شده است — دارندهٔ `manage_options`، تأیید دومرحله‌ای + دلیل اجباری، اعطای Membership **موقت** روی **یک** Clinic، حداکثر ۶۰ دقیقه، رویداد `BREAK_GLASS_ACTIVATED` در زنجیرهٔ audit، اعلان به مدیران آن Clinic، ابطال خودکار. **پیاده‌سازی نمی‌شود مگر با تأیید جداگانه.**
- **صداقت فنی ثبت‌شده:** بکاپ حاوی PHI است و دارندهٔ `manage_options` می‌تواند از آن dump بگیرد. بنابراین مرز AD-11 یک مرز **منطقی** است، نه فیزیکی. اسناد نباید آن را به‌عنوان تضمین فنی بیان کنند.

### ۶. مهاجرت

- **AD-12 — استراتژی = versioned forward migrations.** drop/recreate مسیر محصول **نیست**، حتی با صفر بودن دادهٔ Production. دلیل: `MigrationRunner` forward-only است، نسخهٔ `1.0.0` با زیرساخت Update منتشر شده، و چهار گیت CI روی نصب واقعی WordPress اجرا می‌شوند.
- الزامات کیفی: **idempotency اجباری** (DDL در MySQL تراکنشی نیست ⇒ اجرای مجدد پس از شکست میانی باید امن باشد)، رفتار مشخص در partial failure، و یک job جدید CI برای مسیر ارتقا (نصب `1.0.0` از ZIP → اجرای migrationهای جدید → تأیید schema).
- **AD-13 — `clinic_id = 1` مستقیم در کد جدید ممنوع است** — و به همان ترتیب `organization_id = 1` و `location_id = 1`. ساخت یک ردیف Seed واقعی مجاز است؛ آنچه ممنوع می‌ماند نوشتن شناسه به‌صورت ثابت در کد است. ۵۴ مورد موجود بدهی فنی Phase 2 هستند و در همان فاز حذف می‌شوند.

### ۷. مکانیزم‌هایی که وجود ندارند (تصریح ضدِ گمراهی)

سه مکانیزم زیر در ADRهای قبلی به‌گونه‌ای نوشته شده‌اند که وجودشان را القا می‌کنند. **هیچ‌کدام در کد فعلی وجود ندارند:**

| مکانیزم | ادعاکننده | وضعیت واقعی |
|---|---|---|
| `AccessPolicy` | ADR-0002، ADR-0026 | ❌ NOT IMPLEMENTED — `grep -rl "AccessPolicy" src` = ۰ |
| `resolvePatient()` | ADR-0015 | ❌ NOT IMPLEMENTED — ۰ نتیجه |
| Dangling-Check Job | ADR-0012 | ❌ NOT IMPLEMENTED — ۱۶ handler، هیچ‌کدام |
| `Repository Base` (فیلتر متمرکز `clinic_id`) | ADR-0003 | ❌ NOT IMPLEMENTED — ۱۷ کلاس، صفر `abstract`/`extends` |
| Patient Merge | ADR-0015 | ⚠️ SCHEMA-ONLY — جدول و capability هست، پیاده‌سازی صفر |

**قاعده:** هیچ سندی از این پس نباید طوری نوشته شود که وجود فعلی این مکانیزم‌ها را القا کند. نقش `AccessPolicy` در معماری جدید توسط `AuthorizationService` ایفا می‌شود (Phase 3).

> **وضعیت پیاده‌سازی Patient Identity (C5 — 2026-09-09):** فوندیشن AD-14 پیاده و
> تست شد (کامیت‌های `9207afa` و `2ba16d7`): Migration 0019 (ستون
> `normalized_mobile` غیر یکتا + ایندکس مرکب `idx_identity_org_mobile` و جدول
> `cpms_patient_identity_links`)، `PatientIdentityService` /
> `PatientIdentityRepository` / `PatientIdentityException` با کدهای
> `CLINIC_PATIENT_IDENTITY_*` (ADR-0019). قواعد برقرار: هر lookup موبایل
> Organization-scoped (امضای متد؛ بدون predicate سازمان هیچ query نیست)؛ موبایل
> صفت است — duplicate candidates مجاز و non-destructive؛ رکورد بالینی فقط با
> Clinic صریحِ همان Organization خوانده می‌شود (cross-org ⇒ خالی، fail-closed)؛
> لینک WP User صریح و Organization-bound؛ هویتِ سازمان دیگر = همان NOT_FOUND
> (anti-enumeration). OTP `verify_mobile` همچنان هیچ user/identity/link نمی‌سازد
> (OD-8؛ تست‌های OtpSecurityTest + تست جدید C5). **هنوز NOT IMPLEMENTED (فازهای
> بعدی):** REST endpoints هویت، Policy/Scope UI (Phase 3/9)، merge engine
> (ADR-0015 — schema-only می‌ماند)، resolvePatient، و مکانیزم هش (B-17).

### ۸. الزام دائمی محصول: یک هسته، سه توپولوژی استقرار (AD-17 — تصمیم Owner، 2026-09-12)

**AD-17 — یک پلاگین، یک هستهٔ مشترک، سه توپولوژی استقرار.** همان مدل `Organization → Clinic → Location`
‏(§۱) و همان قواعد عضویت (§۲) باید **بدون معماری جداگانه و بدون مسیر کد موازی** این سه سناریو را پوشش دهند؛
تفاوت آن‌ها فقط در **UX** و در **Context Resolution امن** (تعیین صریح Organization/Clinic/Location فعال) است:

| توپولوژی | توصیف | نکتهٔ context |
|---|---|---|
| **A — مطب تک‌پزشک** | یک Organization، یک Clinic، یک Location، یک پزشک | تنها گزینهٔ موجود به‌طور **صریح** resolve می‌شود — نه با فرض «اولین ردیف» |
| **B — کلینیک چندپزشک** | یک Clinic با یک یا چند Location و چند پزشک/کارمند | Location فعال باید صریح یا از قاعدهٔ اعلام‌شده (Primary Location) به‌دست آید |
| **C — سازمان چندکلینیکی / ساختمان پزشکی** | چند Clinic زیر یک Organization؛ کاربران/پزشکان می‌توانند هم‌زمان در چند Clinic فعال باشند | هیچ Clinic «پیش‌فرض ضمنی» وجود ندارد؛ ابهام ⇒ fail-closed (مانند `CLINIC_SCOPE_REQUIRED`) |

قواعد الزام‌آور (تکرار/تحکیم AD-01..AD-16 برای هر سه توپولوژی):

1. **ممنوع:** `clinic_id = 1`، `location_id = 1`، `organization_id = 1`، «اولین ردیف»، وضعیت سراسری/ایستای
   Clinic فعال (global clinic state)، و هر فرض هویتی/جغرافیایی از جمله فرض `Asia/Tehran` به‌عنوان هویت
   Clinic/Location (fallback فنی IANA ≠ هویت جغرافیایی). (تحکیم AD-13 و AD-08.)
2. **WP User ≠ پزشک.** کاربر وردپرس یک هویت احراز هویت است؛ «پزشک بودن» فقط از طریق Membership + رکورد
   Clinician در یک Clinic معنا دارد. یک WP User می‌تواند در چند Clinic عضو باشد یا در هیچ‌کدام پزشک نباشد.
3. **هویت بالینی (Clinician) در Clinicهای مختلف تکثیر نمی‌شود**؛ یک شخص = یک هویت، چند Membership.
4. **Membership ≠ Location assignment.** عضویت در Clinic، حضور/تخصیص به یک Location را ایجاب نمی‌کند و
   برعکس؛ این دو رابطهٔ جداگانه‌اند.
5. **Patient Identity در سطح Organization** و **Clinical Record در سطح Clinic** (AD-14).
   **دید بین‌کلینیکی هرگز ضمنی نیست** — هیچ توپولوژی‌ای (حتی C) به‌طور خودکار دید متقابل رکورد بالینی ایجاد نمی‌کند.
6. **Context Resolution** برای هر مسیر اجرا (درخواست کاربر، REST، cron/job، CLI) باید صریح و قابل ردیابی باشد؛
   نبودِ context ⇒ خطا/توقف، نه fallback خاموش به یک Clinic/Location/منطقهٔ زمانی.

**مرزها:** جزئیات دامنه‌بندی نقش/قابلیت (role/capability scoping) در **Phase 3** تعریف می‌شود و این بند آن را
پیش‌داوری نمی‌کند؛ پورتال‌های فرانت‌اند (بیمار/پزشک) در دامنهٔ **Phase 2 نیستند**. این بند هیچ migration،
schema یا کد جدیدی را ایجاد یا مجاز نمی‌کند؛ صرفاً معیار پذیرشِ دائمی برای هر طراحی/پیاده‌سازی آینده است.

---

## Consequences

**مثبت**
- ایزولاسیون داده از «قرارداد لایهٔ اپلیکیشن» به «قید سطح دیتابیس» ارتقا می‌یابد (۲۱ FK جدید).
- مدل سازمانی واقعی (گروه درمانی چند-کلینیکی، کلینیک چند-مکانی) بدون fork و بدون شاخهٔ کدی دوم پشتیبانی می‌شود.
- Authorization یک نقطهٔ ورود واحد و قابل تست پیدا می‌کند؛ الگوی «مجوز دیرهنگام» (C-6) قابل حذف می‌شود.
- تعارض بین اسناد و کد صریح و ردیابی‌پذیر می‌شود.

**منفی / هزینه**
- Phase 2 هزینهٔ واقعی و قابل توجهی دارد: ۸ جدول جدید + ۲۱ FK + حذف ۵۴ hardcode + ۳ Default-Parameter + ساخت `ClinicContext` + بازنویسی **هر دو** لایهٔ Repository و Service. هیچ برآورد خوش‌بینانه‌ای ارائه نمی‌شود و هیچ تخمین تقویمی داده نمی‌شود.
- دو تغییر **برگشت‌ناپذیر** در Migration: شکستن `UNIQUE u_sched_day` و `UNIQUE u_slot`. `down()` آن‌ها فقط تا زمانی کار می‌کند که داده‌ای قید قدیمی را نقض نکرده باشد.
- `UNIQUE u_clinician_user (wp_user_id)` از Migration `0007` باید بازطراحی شود؛ این قید مستقیماً با AD-05 در تعارض است.
- دورهٔ Dual-Mode: مجوزدهی legacy تا تکمیل Phase 3 در کنار مدل جدید زنده می‌ماند (نردبان D0→D4 با شرط خروج عینی: شمارندهٔ `AUTHZ_FALLBACK` = صفر). این حالت **دائمی نیست**.

---

## Alternatives (بررسی و رد شده)

| گزینه | چرا رد شد |
|---|---|
| `Organization` اختیاری / `NULL`-able برای مطب تک‌پزشکی | مسیر موازی در Core می‌سازد؛ هر Query و هر گزارش باید دو حالت را مدیریت کند. رد شده با AD-02. |
| نگه‌داشتن `clinic_id = 1` به‌عنوان «ثابت V1» و موکول کردن به V2 | همان تصمیم ADR-0003 بود؛ نتیجه‌اش ۵۴ نقطهٔ hardcode شد. تکرارش هزینه را نمایی می‌کند. |
| drop & recreate کل schema (چون داده Production نیست) | مسیر Upgrade منتشرشده و ۴ گیت CI را می‌شکند؛ مزیت سرعتش روی جداول خالی صفر است. رد شده با AD-12. |
| Multisite وردپرس به‌جای مدل Organization | `CpmsDb::table()` از `$wpdb->prefix` استفاده می‌کند نه `base_prefix`؛ سازگاری Multisite هرگز ادعا یا آزموده نشده. خارج از دامنه. |
| ادامه با حکم «۰ FOUNDATIONAL» بازبینی 2026-09-06 | با شواهد اجرایی Phase 0 (C-1..C-9) رد می‌شود. |

---

## ارتباط با اسناد دیگر

| سند | نسبت |
|---|---|
| **ADR-0003** (چند-شعبه/چند-پزشک) | ❌ **Superseded کامل** — به‌ویژه بند «V1 مقدار ثابت 1» |
| **ADR-0027** (یک محصول چندپزشکی) | ⚠️ **Superseded جزئی** — مدل دامنه‌اش جایگزین شد؛ اصل One-Product/One-Core و بند ۵ (Patient) و بند ۳ (Scope سرور-side) **معتبر می‌مانند** |
| **ADR-0026** (نقش‌های پویا) | ⚠️ **Superseded جزئی** — D-4 (مدل Scope) و D-15 (زمان‌بندی V2) جایگزین شدند؛ D-1، D-5..D-14 معتبر می‌مانند |
| **ADR-0002** (نقش/Capability) | ✅ اصل P-3 تأیید و تقویت شد؛ فقط با یادداشت «AccessPolicy پیاده نشده» علامت خورد |
| **ADR-0012** (استراتژی FK) | ✅ معتبر؛ با یادداشت «Dangling-Check Job پیاده نشده» و تصحیح شمارش FK |
| **ADR-0013** (UTC/Jalali) | ✅ معتبر؛ با AD-08 گسترش یافت |
| **ADR-0015** (Patient Merge) | ✅ معتبر؛ با یادداشت «schema-only، `resolvePatient` وجود ندارد» |
| **ADR-0030** (Override + Scope صف) | ✅ معتبر؛ self-healing آن تا مرحلهٔ D4 نردبان Dual-Mode دست‌نخورده می‌ماند |
| `docs/phase-reports/report-phase-0-reverification.md` | شواهد پایه (C-1..C-9) |
| `docs/architecture/phase0.5-target-model.md` | مدل هدف، ERD، برنامهٔ Migration (M-01..M-15)، Decision Register (Q1..Q13) |
| `docs/drift-register.md` | فهرست اسنادی که هنوز مدل قدیمی را بیان می‌کنند، با فاز مالک هر مورد |
| `docs/roadmap/roadmap.md` §۰ | Roadmap تأییدشدهٔ Owner (Phase 0..20) |
