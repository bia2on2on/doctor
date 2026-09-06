# بازبینی آمادگی چندپزشکی — One Product / One Core (ADR-0027)

**تاریخ:** 2026-09-06 | **ایجنت:** Arena | **مبنای تصمیم:** ADR-0027 (تصمیم نهایی محصول کارفرما)
**روش:** ممیزی کامل Schema (۳۷ جدول Migration 0001 + 0002/0003/0004/0005) + کد (`src/` — Services/Controllers/Jobs/Admin pages) + اسناد (ADR-0003/0026، permission-matrix، SRS)
**قاعده:** ماژول‌های کارکردی فقط برای «خلوص مفهومی» بازنویسی نمی‌شوند؛ اقلام Minor Alignment مستند و در فاز مناسب اجرا می‌شوند.

---

## 0. حکم کلی

**بدون تغییر Foundational لازم نیست.** معماری از روز اول برای این مدل ساخته شده (ADR-0003: `clinic_id` در تمام جداول + `clinician_id` در تمام موجودیت‌های بالینی/زمان‌بندی؛ ADR-0026: Capability+Scope با مدل `OWN/ASSIGNED_DOCTORS/BRANCH/CLINIC`). ۱۰ محور بازبینی: عمدتاً READY (تنها 1.8 = MINOR ALIGNMENT کامل) و **۰ FOUNDATIONAL CHANGE REQUIRED**؛ مجموعاً **۱۱ قلم Minor Alignment** در Backlog §3 (به F9/V1.5/V2/F10 نگاشت شده). هیچ موردی که به‌تعویق انداختنش rework سنگین بسازد وجود ندارد — اقلام Minor همه additive هستند (Migrationهای افزایشی + لایه Enforcement) و نقشه اجرایشان در §3 آمده.

---

## 1. ماتریس یافته‌ها (۱۰ محور درخواستی)

### 1.1 فرض‌های تک‌پزشکی موجود → **READY**

جست‌وجوی هدفمند در `src/`:
- **هیچ سرویسی پزشک واحد را فرض نمی‌کند.** نمونه‌ها: `SlotsGenerateHandler` برای *همه* Clinicianهای فعال Slot می‌سازد (مدت‌زمان/ظرفیت/Break per-clinician از `cpms_schedule`)؛ `SecretaryQueuePage::activeClinicians()` لیست همه پزشکان فعال (Picker چندپزشکی از F4)؛ `DoctorDashboardPage::ownClinician()` پزشک خودِ کاربر را از `wp_user_id` resolve می‌کند.
- **SMS/اعلان** نام پزشک را از `appointment→clinician` می‌گیرد (هر نوبت پزشک خودش) — چندپزشکی از ابتدا.
- **LicenseGate** عملیاتی است (Operation codes) — هیچ سقف/فرض تعداد پزشک ندارد؛ Entitlement چندپزشکی (per-doctor plan) در F10 روی همین Seam اضافه می‌شود.
- تنها «ثابت» موجود: `clinic_id = 1` (~۴۴ نقطه) — ثابت Tenant هر-نصب-یک-کلینیک طبق ADR-0003، **نه** فرض تک‌پزشکی؛ برای مدل Branch (درون یک کلینیک) تغییری نمی‌خواهد.
- **UX حالت مطب** (Skip خودکار انتخاب پزشک وقتی ۱ پزشک فعال) هنوز پیاده نشده → Minor Alignment #1 (§3).

### 1.2 پوشش `clinician_id` → **READY**

`clinician_id` (FK NOT NULL + Index) در: `cpms_schedule`، `cpms_schedule_exceptions`، `cpms_schedule_slots`، `cpms_appointments`، `cpms_visits`، `cpms_clinical_notes` (+ ستون author)، `cpms_prescriptions`، `cpms_recommendations`، `cpms_follow_ups`، `cpms_handwriting_documents` (مالکیت از مسیر visit→clinician). مالی: `cpms_invoices`/`cpms_payments` ستون مستقیم ندارند اما **Attribution از مسیر `invoice→visit→clinician_id` با JOIN بدون تغییر Schema** می‌رسد (تأیید ADR-0026 §معماری + F8 پیاده کرد). گزارش F8 `revenue` با Scope OWN همین JOIN را فیلتر می‌کند.

### 1.3 Patient = موجودیت Clinic-level → **READY**

`cpms_patients`: کلید `clinic_id` + `mrn` یکتا در کلینیک؛ `cpms_patient_user_links` به Patient (نه به پزشک). هیچ ساختار «بیمارِ per-doctor» وجود ندارد؛ یک بیمار با چند پزشک = چند Appointment/Visit که هرکدام `clinician_id` خودش را دارد. Merge (`cpms_patient_merges`) هم clinic-level است.

### 1.4 رابطه Appointment/Visit با Clinician → **READY**

هر دو جدول `clinician_id BIGINT NOT NULL` + FK (`fk_appt_clinician`/`fk_visit_clinician`) + ایندکس‌های per-clinician (`idx_appt_day(clinician_id, slot_date, status)`، `idx_visit_queue(clinician_id, status, waiting_since)`). Slot هم per-clinician است (`u_slot(clinician_id, slot_date, slot_time)`) — یعنی هر Appointment به Clinician/Slot بدون ابهام گره خورده.

### 1.5 صف چندپزشکی → **READY** (با ۲ Minor Alignment: #2 و #3)

- `VisitRepository::queueFor(clinicId, clinicianId, statuses)` فیلتر Clinician دارد؛ REST `GET /queue?clinician_id=` (اختیاری) + داشبورد منشی Picker پزشک + داشبورد پزشک فقط صف خودش (`queue?clinician_id=own`).
- ایندکس‌ها per-clinician ✓. چند بیمار در صف چند پزشک هم‌زمان = همان Query با فیلتر.
- **Minor #2 (Scope صف):** خواندن صف فعلاً فقط Capability-gated است (QUEUE_READ = دید کل کلینیک) — Scope منشی (ASSIGNED_DOCTORS / BRANCH) طبق ADR-0026 در V2 روی همین Query اضافه می‌شود. «دیدن صف کل کلینیک» برای منشی V1 رفتار پیش‌فرض معقولی است؛ محدودسازی = لایه Enforcement، نه تغییر Schema.
- **Minor #3 (مالکیت Transition):** `VisitService::applyTransition` نقش‌محور است (State Machine بر اساس role) — پزشک می‌تواند روی ویزیت پزشکِ دیگر call/recall/start بزند. در تیم واحد بی‌ضرر؛ طبق تصمیم «Cross-doctor عملیات نیازمند Scope صریح» → گارد مالکیت برای role=doctor (ویزیت clinician_id ≠ پزشکِ متصلِ Caller → 403/404) — **کم‌ریسک، پیشنهاد فاز F9 (Hardening)**.
- فیلترهای آینده Branch/Department/Room روی همین Query افزودنی‌اند (ستون + پارامتر).

### 1.6 Schedule چندپزشکی → **READY**

- `cpms_schedule`: سطر per (clinician, day_of_week) با `appointment_duration_min`/`slot_capacity`/Breakهای اختصاصی؛ `cpms_schedule_exceptions` per-clinician؛ ScheduleController همه Endpointها `clinician_id` الزامی.
- **نکته Branch-ready:** `u_slot(clinician_id, slot_date, slot_time)` یعنی یک پزشک در یک لحظه فقط یک Slot دارد — **از قبل همان قاعده «جلوگیری از تعارض بین-مکانی»** است که تصمیم محصول خواسته؛ اضافه‌کردن branch_id به Slot این Unique را نباید بشکند (Branch = صفت Slot، نه عضو کلید).
- Schedule شعبه‌ای (یک پزشک، دو شعبه): افزودن `branch_id` به `cpms_schedule`/`cpms_schedule_slots` + تغییر `u_sched_day` به `(clinician_id, branch_id, day_of_week)` — Migration افزایشی در فاز Branch (V2)، بدون rework.

### 1.7 آمادگی Attribution مالی → **READY** (با Minor #4 و #5)

- زنجیره Clinic → Visit → Clinician (فاکتور در V1 همیشه visit-bound است: I1 فقط از ویزیت consultation_completed) + Service از مسیر `invoice_items→cpms_services`. F8 `revenue`/`open_balances` با Scope OWN (per-doctor) و CLINIC (Aggregate) همین زنجیره را می‌خواند — تفکیک Aggregate⊥Detail (D-8/D-15) پیاده و تست‌شده.
- **Minor #4 (Service اختصاصی پزشک):** `cpms_services` فقط clinic-level است (`u_service_code(clinic_id, code)`). سرویس per-clinician = ستون `clinician_id NULLABLE` (NULL = clinic-wide) + منطق Eligibility/Duration default در فاز مربوطه — افزودنی، الگوریتم Duration Snapshot (ADR-0017) دست‌نخورده.
- **Minor #5 (گزارش‌های Breakdown):** گزارش per-doctor/per-service/per-specialty (group-by) هنوز نیست — طبق تصمیم «در فاز roadmap خودش» (V2). همه ستون‌ها برای JOIN/group موجودند.
- «دسترسی مالی هرگز به بالینی imply نمی‌شود» — از F6/F8 برقرار (Caps جدا + تست DoctorFinancialReportDoesNotGrantClinicalAccess).

### 1.8 آمادگی Permission/Scope → **MINOR ALIGNMENT** (طرح کامل موجود، Enforcement فاز بعدی)

- **مدل زنده:** Capability (`has_cap`/`add_cap` — نام نقش هرگز مکانیزم Authorization نیست، P-9). نقش‌های V1 = Template پیش‌فرض؛ نقش سفارشی ستادی از الان با هر زیرمجموعه Cap کار می‌کند.
- **مدل هدف (ADR-0026 D-4):** `OWN/ASSIGNED_DOCTORS/BRANCH/CLINIC/ALL_ALLOWED` + جدول انتساب تیمی — پیاده‌سازی V2 روی ستون‌های موجود؛ Enforcement الگوی آن در F8 اثبات شد (Scope سرور-side گزارش‌ها: پارامتر کلاینت بی‌اثر، تست‌شده).
- انتساب Staff→Doctor/Department/Branch = جدول جدید `cpms_staff_assignments` (V2). Audit رویدادهای حساس (`STAFF_ASSIGNED`، `STAFF_SCOPE_CHANGED`، …) در permission-matrix §6 از قبل تعریف شده‌اند.

### 1.9 آمادگی Reporting → **READY** (ابعاد افزودنی)

F8: ۱۲ گزارش + Scope سرور-side (OWN/CLINIC) + کاتالوگ per-actor. ابعاد آینده (Branch/Specialty/Service group-by) روی همین `ReportService` — ستون‌ها/JOINها موجود؛ Specialty نیازمند مدل دامنه است (§1.10).

### 1.10 نواحی Schema که بعداً گران می‌شوند → **بررسی شد؛ هیچ‌کدام بن‌بست نیست**

| ناحیه | وضعیت فعلی | تغییر لازم | طبقه‌بندی |
|---|---|---|---|
| `clinic_id`/`clinician_id` همه‌جا | از روز اول (ADR-0003) | — | READY |
| Specialty | `cpms_clinicians.specialty VARCHAR(190)` (تک‌مقداری، DDL-only، در هیچ Query استفاده نمی‌شود) | جدول `cpms_specialties` + `cpms_clinician_specialties` (M:N)؛ VARCHAR به‌عنوان Label باقی بماند یا migrate شود | **Minor #6** — افزودنی؛ چون هیچ کدی به آن گره نخورده، الان Migrating آن اجباری نیست و بعداً هم rework نمی‌سازد |
| Branch | فقط کلید Tenant (`clinic_id`) | جدول `cpms_branches` (فرزند Clinic) + `branch_id` در schedule/slots/visits/… + `u_sched_day` تغییر | افزودنی در V2 (تصمیم: الان پیاده نشود) |
| Department | وجود ندارد | `cpms_departments` + `department_id` روی clinicians (یا M:N) | افزودنی در V2 |
| Room | `cpms_clinicians.room` (اتاق پیش‌فرض) + `meta.room` در فراخوان صف | مدیریت Room کامل = جدول/Enum + ارجاع در Visit/Schedule | افزودنی در V2 |
| Staff Assignment | نقش‌محور WP + Cap | `cpms_staff_assignments` (staff↔doctor/department/branch) + Scope Enforcement | V2 (ADR-0026) |
| Service per-clinician | clinic-level | `clinician_id NULL` | Minor #4 |
| «اولین پزشک آزاد» (First-available) | Availability per-clinician (`clinician_id` الزامی در B1) | Endpoint جست‌وجوی Cross-clinician روی همان جدول Slot | افزودنی؛ بدون Redesign دیتابیس |
| ثابت `clinic_id=1` | ~۴۴ نقطه (ADR-0003) | Seam مرکزی `ClinicContext::id()` هنگام نیاز به Multi-tenant هر-نصب (خارج تصمیم فعلی) | Minor #7 (بدهی فنی — فقط مستند) |

---

## 2. سه مورد تصمیم‌محور (فرمت درخواستی — هیچ‌کدام Foundational-Blocker نیست، برای شفافیت ثبت شد)

### مورد الف — Specialty تک‌مقداری (VARCHAR) در برابر M:N

- **ISSUE:** تصمیم محصول: «یک پزشک ممکن است چند تخصص داشته باشد». مدل فعلی ستونی تک‌مقداری است (استفاده‌نشده در کد).
- **CURRENT MODEL:** `cpms_clinicians.specialty VARCHAR(190) NULL`.
- **TARGET MODEL:** `cpms_specialties` (کد/نام) + `cpms_clinician_specialties(clinician_id, specialty_id)` M:N.
- **IMPACT:** هیچ کد/Query/UX فعلی به VARCHAR وابسته نیست → تغییر بعداً = یک Migration + Backfill اختیاری. هیچ rework‌ای ساخته نمی‌شود.
- **MIGRATION REQUIREMENT:** Migration جدید در فاز فعال‌سازی (V2 یا وقتی Booking UX تخصص‌محور بخواهد).
- **OPTIONS:** (۱) الان M:N بساز (۲) در فاز خودش.
- **RECOMMENDATION:** گزینه ۲ — افزودنی است و صبر‌کردن ریسک صفر دارد؛ طبق قاعده «no premature implementation».

### مورد ب — Schedule شعبه‌ای و کلید یونیک

- **ISSUE:** `u_sched_day(clinician_id, day_of_week)` یک برنامه در روز برای هر پزشک؛ پزشکِ دو-شعبه‌ای نیاز به برنامه per-branch دارد.
- **CURRENT MODEL:** یک سطر Schedule per (clinician, weekday)؛ Slot با `u_slot(clinician, date, time)` (خوب برای ضد-تعارض بین-مکانی).
- **TARGET MODEL:** `branch_id` در Schedule (+کلید `(clinician_id, branch_id, day_of_week)`) و Slot (به‌عنوان صفت؛ `u_slot` دست‌نخورده).
- **IMPACT:** Migration ایندکس در فاز Branch؛ کوئری‌های Schedule فعلی با Default branch کار می‌کنند.
- **RECOMMENDATION:** در فاز Branch (V2) — الگوی `u_slot` از الان درست است و عمداً نباید تغییر کند.

### مورد ج — Enforcement Scope صف/Transitions

- **ISSUE:** طبق «DATA ISOLATION»، عملیات/دید چندپزشکی باید Scope سرور-side داشته باشد؛ فعلاً Capability کافی تلقی شده (تیم واحد V1).
- **CURRENT MODEL:** Queue read = QUEUE_READ (کلینیک-سوئیپ)؛ Visit transition = role-gated.
- **TARGET MODEL:** Capability → Resource Scope (ADR-0026) → گارد مالکیت برای پزشک‌ها (ویزیت خودش) + محدودسازی صف منشی به Scope انتساب.
- **IMPACT:** فقط Enforcement (Query-level) + تست؛ بدون تغییر Schema. بخش V2 (انتساب) — اما گارد مالکیت پزشک (Minor #3) کم‌ریسک و امنیتی است.
- **RECOMMENDATION:** گارد مالکیت در **F9 Hardening**؛ Enforcement کامل Scope با جدول انتساب در **V2**.

---

## 3. Backlog هم‌ترازی (Minor Alignment — phase-mapped)

| # | قلم | فاز پیشنهادی | ریسک | اندازه |
|---|---|---|---|---|
| 1 | UX حالت مطب: تشخیص «۱ Clinician فعال» → Skip خودکار Picker پزشک (Booking/Dashboard/Queue) + مخفی‌سازی توانمندی‌های درمانگاهی غیرضروری | V1.5 (Portal/UX) | کم | S |
| 3 | گارد مالکیت: پزشک فقط روی ویزیت خودش call/recall/start (نقش doctor + Clinician متصل ≠ ویزیت → 403) + تست | **F9 Hardening** | کم | S |
| 12 | UNIQUE Index روی `cpms_clinicians.wp_user_id` (NULL مجاز — تضمین 1:1 کاربر↔پزشک؛ الان فقط قرارداد است) + تست Migration | **F9 Hardening** | کم | S |
| 4 | `cpms_services.clinician_id NULL` (سرویس clinic-wide یا per-clinician) + Eligibility/Default-duration | V2 (Services فاز ۲) | کم | M |
| 5 | گزارش‌های Breakdown: per-doctor/per-service/per-specialty (Scope طبق Permission) | V2 | کم | M |
| 6 | مدل Specialty (M:N) + Booking UX «تخصص→خدمت→پزشک» و «اولین پزشک آزاد» | V2 | کم | M |
| 8 | Staff Assignments + Scope Enforcement کامل (Queue/Finance/Reports) + Audit `STAFF_*`/`CLINIC_SCOPE_CHANGED` | V2 (ADR-0026) | متوسط | L |
| 9 | Branch: جداول + `branch_id` در Schedule/Slot/Visit + `u_sched_day` | V2 (ADR-0003) | متوسط | L |
| 10 | Department/Room modeling + فیلترهای صف | V2 (اگر لازم شد) | کم | M |
| 7 | Seam مرکزی ثابت `clinic_id` (`ClinicContext`) | هنگام نیاز واقعی (Multi-tenant) | کم | S |
| 11 | Entitlement لایسنس چندپزشکی (per-doctor plan) روی LicenseGate | F10 (Licensing) | کم | S |

(شماره ۲ = Scope صف درون قلم ۸ ادغام شد.)

**Audit رویدادهای حساس درخواستی:** `DOCTOR_ADDED`/`DOCTOR_DEACTIVATED` (→ امروز: `CLINICIAN_*` در سرویس Clinician — در فاز V2 Role Management)، `SPECIALTY_ASSIGNED` (فاز ۶)، `STAFF_ASSIGNED`/`STAFF_SCOPE_CHANGED`/`CLINIC_SCOPE_CHANGED` (V2 — permission-matrix §6 از قبل تعریف‌شده).

---

## 4. تأیید مطابق تصمیم (چک‌لیست عینی کد)

| الزام تصمیم | شاهد در کد | وضعیت |
|---|---|---|
| چند پزشک مستقل (Profile/Services/Schedule/Queue/…) | `cpms_clinicians` per-clinic + `wp_user_id` (1:1 به‌قرارداد — `ownClinician()` با LIMIT 1؛ **بدون UNIQUE Index** → Backlog #12: ایندکس یونیک روی `wp_user_id` در F9 — MySQL چند NULL مجاز می‌داند، Migration افزایشی/کم‌ریسک)؛ Schedule/Slot/Visit/Note همه per-clinician | ✅ (با #12) |
| انتساب Staff بر پایه Capability نه نام نقش | `RolesAndCapabilities::has_cap` + P-9؛ نقش‌های سفارشی کار می‌کنند | ✅ (Scope = V2) |
| بیمار clinic-level بدون تکرار | `cpms_patients` + MRN کلینیک‌سوئیپ | ✅ |
| دسترسی بین‌پزشکی به Private Notes ممنوع | `visibility='doctor_private'` + مالکیت «ویزیت خودش» در ClinicalService/Handwriting (تست 404 پزشک دیگر از F5/F7) | ✅ |
| Booking با Clinician صریح | B1/D10: `clinician_id` الزامی availability/quote/hold/confirm + GAP-1/G-3 | ✅ |
| Slot بدون ابهام به Clinician گره خورده | `u_slot(clinician_id, slot_date, slot_time)` + FK | ✅ |
| Duration per-doctor + Snapshot | `cpms_schedule.appointment_duration_min` per-clinician + ADR-0017 Snapshot | ✅ |
| صف فیلتر بر اساس Clinician/Status | `queueFor(clinic, clinician, statuses)` + REST param | ✅ |
| Attribution مالی Clinic/Clinician/Service | `invoice→visit→clinician` + `invoice_items→services`؛ F8 Scope OWN/CLINIC | ✅ |
| Reporting per-doctor/Aggregate طبق Permission | F8 `ReportService::resolveScope` سرور-side (تست: پارامتر کلاینت بی‌اثر) | ✅ |
| داشبورد پزشک = زمینه خودش | `ownClinician()` + `queue?clinician_id=own` | ✅ |
| Responsive + Pen-First (نه Tablet-Only) | ADR-0026 D-14 + پیاده‌سازی F7 | ✅ |
| DATA ISOLATION سرور-side | الگوی F8 اثبات‌شده؛ صف/Transition → Minor #3/#8 | 🔶 فاز‌بندی‌شده |
| Audit تغییرات انتساب حساس | تعریف در permission-matrix §6؛ پیاده‌سازی V2 | 🔶 فاز‌بندی‌شده |

---

## 5. جمع‌بندی

معماری فعلی **از ابتدا برای «یک محصول، چندپزشک»** طراحی شده بود (ADR-0003 صریحاً همین را مقرر کرده) و تصمیم نهایی کارفرما آن را از «آینده‌نگری» به «قید دائمی محصول» ارتقا داد. هیچ تغییر Foundational لازم نیست؛ کارهای باقی‌مانده افزودنی و در Backlog §3 به فازها (F9/F10/V1.5/V2) نگاشت شده‌اند. توسعه‌های آینده موظف‌اند این سند + ADR-0027 را رعایت کنند.
