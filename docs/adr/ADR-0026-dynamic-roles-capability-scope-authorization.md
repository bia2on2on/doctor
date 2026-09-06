# ADR-0026 — نقش‌های پویا (Dynamic Roles) + مجوزدهی Capability/Scope + بررسی معماری

تاریخ: 2026-09-06 | وضعیت: **Accepted** (تصریح کارفرما — Product/Architecture Clarification) | فاز ثبت: F6

## Context

تصریح کارفرما (2026-09-06): سیستم نباید به ۳ نقش ثابت (بیمار/منشی/پزشک) محدود بماند. نقش‌های ستادی دلخواه (دستیار، حسابدار، مدیر مطب، پرستار، پذیرش، مدیر مالی، Custom) باید در آینده قابل تعریف باشند؛ **نام نقش هرگز مکانیزم Authorization نیست** — تصمیم بر پایه Capability (+ Scope آینده) است. همچنین: تفکیک دسترسی مالی از بالینی، تفکیک دسته‌های داده بیمار، Scope منابع (OWN/…/CLINIC)، گزارشگری مالی چند-پزشکی/چند-شعبه، Responsive بودن همه داشبوردهای ستادی، و قواعد محافظت از Escalation.

## Decision

### 1. مدل مرجع Authorization (نرمال)

```
Authentication → Capability → Resource Type → Resource Scope (آینده) → Relationship/Assignment → Record Visibility → License Entitlement
```

- **D-1 (قانون سخت):** منطق کسب‌وکار و Controllerها فقط **Capability** (`cpms_*`) چک می‌کنند (`user_can`/`has_cap`). مقایسه «نام نقش» در مسیر تصمیم مجوز **ممنوع** — فقط برای برچسب Audit/گزارش مجاز است.
- **D-2:** نقش = فقط بسته‌ی پیش‌فرض Capability در زمان ساخت (Template — نرمال‌کننده نیست). نقش‌های سفارشی با هر زیرمجموعه‌ای از Capabilityها (`add_role` وردپرس + caps `cpms_*`) بدون تغییر سرویس‌ها کار می‌کنند.
- **D-3:** مالکیت مرجع: `User → Role(s) → Capabilities → Resource Scope`. نقش‌های سیستم حساس (بیمار/منشی/پزشک فعلی) در برابر حذف/تخریب محافظت می‌شوند؛ حذف/تغییر نقش نباید کاربر را یتیم کند یا دسترسی خاموش بدهد.
- **D-4 (Scope — مدل آینده، الگوی نام‌گذاری مفهومی): `OWN` / `ASSIGNED_DOCTORS` / `BRANCH` / `CLINIC` / `ALL_ALLOWED`. پیاده‌سازی در فاز مربوطه (V2 طبق ADR-0003) روی ستون‌های موجود `clinic_id`/`clinician_id` + جدول انتساب تیمی.
- **D-5 (تفکیک حوزه‌ها):** مالی مستقل از بالینی (`cpms_invoice_*`/`cpms_payment_*`/`cpms_finance_read` بدون هیچ وابستگی به `cpms_medical_read` و بالعکس). دسترسی مالی هرگز به داده بالینی اشاره نمی‌کند؛ حسابدار = نقش با فقط Capabilityهای مالی — **امروز هم بدون تغییر کد کار می‌کند**.
- **D-6 (دسته‌بندی داده بیمار):** `cpms_patient_read` (هویت/دموگرافیک) ⊥ `cpms_medical_read` (پرونده بالینی) ⊥ `cpms_private_note_*` (حساس — P-6، هرگز ضمنی از هیچ Cap عمومی) ⊥ `cpms_file_*` ⊥ `cpms_rx_*` ⊥ `cpms_invoice_read`/`cpms_finance_read`. Enforcement فقط Backend (مخفی‌کردن UI کافی نیست).
- **D-7 (Private Notes):** `read_doctor_private_notes` = فقط `cpms_private_note_read` صریح. هیچ Cap عمومی (حتی `cpms_medical_read` یا `administrator` وردپرس) آن را ضمنی ندارد. دسترسی بین-پزشکی فقط با سیاست صریح آینده.
- **D-8 (Aggregate ⊥ Detail):** مجوز گزارش تجمیعی (`cpms_finance_read`/`cpms_report_read` + Scope آینده) هرگز به معنای خواندن رکورد جزئی بیمار/پرونده بالینی نیست. گزارش درآمد پزشک دیگر requires Scope صریح.
- **D-9 (محافظت از Escalation):** اعطای Capability فقط توسط دارایِ `cpms_config` (و در آینده Cap مدیریت نقش) و هرگز Capabilityای که اعطاکننده خودش ندارد. Server-side.
- **D-10 (Audit):** اکشن‌های استاندارد آینده Role Management: `ROLE_CREATED`، `ROLE_UPDATED`، `ROLE_ARCHIVED`، `ROLE_ASSIGNED`، `ROLE_REMOVED`، `PERMISSION_GRANTED`، `PERMISSION_REVOKED`، `STAFF_SCOPE_CHANGED` — با before/after بدون PHI.
- **D-11 (2FA):** طبق ADR-0020 سیاست 2FA «بر پایه Access، نه Role» است — حساب دارای هر Cap حساس (PHI/مالی حساس/Export/Audit/تنظیمات حساس) = Privileged → 2FA الزامی، حتی با نقش سفارشی.
- **D-12 (Licensing):** ساخت نقش/اعطای مجوز از Entitlement فعلی License عبور نمی‌کند؛ License ملاک فعال‌بودن قابلیت است نه نقش.
- **D-13 (UI):** ترکیب داشبورد از سیگنال‌های Backend (Capabilityهای معتبر کاربر) ساخته می‌شود؛ پنهان‌سازی صرف CSS/JS ممنوع (هم‌راستا با P-1).
- **D-14 (Responsive):** همه داشبوردهای ستادی (پزشک/منشی/نقش‌های آینده) + بیمار Responsive کامل‌اند (موبایل/تبلت/لپ‌تاپ/دسکتاپ). «تبلت/قلم-محور» فقط بهینه‌سازی تجربه دست‌خط است نه محدودیت دستگاه؛ Canvas دست‌خط Resize/Orientation/DPR/Touch-Stylus را بدون از دست رفتن Stroke مدیریت می‌کند (الزام F7). یک UI واحد — بدون اپ تکراری برای هر دستگاه.
- **D-15 (قاعده فاز جاری):** Role Management کامل + Scope + گزارشگری مالی تفصیلی در F6 پیاده نمی‌شوند؛ فقط مستندسازی + نقاط توسعه حفظ می‌شود (roadmap: V2 + گزارش‌ها در F8).

## بررسی معماری (تدارک‌شده توسط تصریح §27 — نتیجه: بدون Blocker)

| پرسش | یافته (verify شده در کد 2026-09-06) | وضعیت |
|---|---|---|
| مجوز وابسته به نام نقش جایی هست؟ | لایه REST + سرویس‌های مالی/صف تقریباً کاملاً Capability-محور (`requireCap`/`user_can`). **موارد استثنا (قابل پل‌زدن، نه بازطراحی):** (۱) `ClinicalService::requireRole('doctor')` در ۹ عمل بالینی (علاوه بر Cap همان عمل)؛ (۲) `VisitService::roleForUser` → بازیگر ماشین وضعیت Visit (`doctor`/`secretary`) + `requireSecretary` برای Check-in/Walk-in؛ (۳) چک نقش `cpms_patient` در `PatientController`/`ClinicalController`/`FilesController`/`BookingController` (P-5 — by-design، مبتنی بر Ownership)؛ (۴) انشعاب visibility در `MedicalFileService` بر پایه نقش | ⚠️ Debt مستند → §«نقشه مهاجرت» |
| نقش سفارشی بدون بازنویسی سرویس‌ها؟ | بله برای مسیرهای Capability-محور (REST + مالی امروز). ماشین Visit/Clinical بازیگر نقش‌محور دارد → با «نگاشت نقش→بازیگر» (RoleActorResolver) پل می‌شود | ✅ قابل توسعه |
| دسته‌های داده بیمار جدا مجوزدهی می‌شوند؟ | بله — D-6؛ ساختار فعلی (`patient_read`/`medical_read`/`private_note_*`/`file_*`/`rx_*`/`invoice_*`) از قبل تفکیک دارد؛ «دستیار» = `patient_read`+`queue_*`+`appt_*` بدون `medical_read` همین امروز ممکن است | ✅ |
| مالی از بالینی جدا؟ | بله — D-5؛ `FinanceService` (F6) فقط Cap مالی چک می‌کند؛ Admin فنی هم مالی روزانه ندارد (P-3) | ✅ |
| Scope (پزشک خودم/مطب/شعبه) تمیز اضافه می‌شود؟ | بله — `clinic_id` در همه جداول + `clinician_id` در جداول بالینی (ADR-0003)؛ AccessPolicy از ابتدا Interface با `visibleRows` است؛ فایل مالی به پزشک از مسیر `invoice→visit→clinician_id` می‌رسد (JOIN، بدون تغییر Schema) | ✅ (V2) |
| 2FA برای نقش‌های حساس سفارشی؟ | بله — ADR-0020 از ابتدا Access-based است (D-11) | ✅ |
| گزارش چند-پزشکی نیاز به بازطراحی Schema دارد؟ | خیر — `visits.clinician_id` موجود؛ درآمد per-doctor = JOIN موجود؛ تفکیک Aggregate/Detail در لایه Query/Presenter | ✅ |
| تک‌کلینیک/تک‌پزشک Hard-code مشکل‌ساز؟ | `clinic_id=1` به‌عنوان ثابت V1 طبق ADR-0003 (تصمیم مستند، مکانیکی قابل تعمیم)؛ چند-پزشک از روز اول پشتیبانی می‌شود (`cpms_clinicians`، صف per-clinician) | ✅ |

**نتیجه: Blocker معماری وجود ندارد؛ ادامه F6 بدون توقف.**

## نقشه مهاجرت Debt نقش-محور (فاز Role Management — V2)

1. `RoleActorResolver` متمرکز: نقش(های) وردپرس → بازیگر ماشین (`doctor`/`secretary`/`system`)؛ ماشین‌ها بدون تغییر می‌مانند (قراردادشان «بازیگر» است نه نقش وردپرس).
2. حذف `ClinicalService::requireRole` — Cap همان عمل + (در صورت نیاز) Cap جدید دانه‌ریز کافی است؛ رفتار Doctor/Secretary فعلی حفظ می‌شود (سازگاری).
3. انتقال انشعاب‌های نقش در `MedicalFileService` به `AccessPolicy` (تک‌منبع).
4. چک نقش `cpms_patient` در Controllerها → همان می‌ماند (P-5، Ownership-based، توسط تصریح §17 پذیرفته شده — WordPress Role به‌عنوان زیرساخت Identity مجاز است؛ ملاک مجوزِ داده همچنان Ownership است).

## Consequences

+ نقش‌های سفارشی (دستیار/حسابدار/مدیر مطب/…) بدون تغییر Business Logic قابل تعریف‌اند (فقط در فاز V2 UI مدیریت + Scope می‌آید).
+ تفکیک حوزه‌ها (مالی/بالینی/هویت/خصوصی) از امروز در Schema/Capها برقرار است — F6 (مالی) کاملاً Capability-محور پیاده شد.
− دو مورد Debt نقش-محور (بالا) تا V2 پابرجاست — هر تغییر جدید **نباید** مقایسه نقش اضافه کند (قاعده D-1).
− Scope فعلاً مفهومی است؛ تا V2 «مالی/گزارش» = سطح مطب (CLINIC) با ثابت `clinic_id=1`.

## Alternatives

- پیاده‌سازی کامل Role Management/Scope/گزارشگری در F6 → مردود (نقض D-15 و roadmap؛ overengineering زودهنگام).
- سیستم مجوز موازی غیر-وردپرس → مردود (§17 تصریح؛ وردپرس Cap Infrastructure کافی و هم‌راستا با ADR-0002).
- حذف نقش‌های ثابت فعلی → مردود (سازگاری رفتار فعلی Doctor/Secretary الزامی است).
