# ADR-0027 — یک محصول، یک Core: مطب تک‌پزشکی = زیرمجموعه درمانگاه چندپزشکی

> ## ⚠️ PARTIALLY SUPERSEDED BY [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md) — 2026-09-08
>
> **وضعیت: Accepted (اصل محصول) / Superseded (مدل دامنه).**
>
> **آنچه معتبر می‌ماند — و در ADR-0031 صریحاً تأیید شده:**
> - بند ۱ — **One Product / One Core / One Database / Adaptive UX.** دو Plugin یا Fork جداگانه همچنان ممنوع است. (= AD-04)
> - بند ۳ — **Scope و Authorization همیشه سرور-side.** فیلتر فرانت‌اند هرگز مبنای مجوز نیست.
> - بند ۴ — Specialty = مفهوم دامنه، نه نقش Authorization؛ Doctor↔Specialty باید M:N باشد.
> - بند ۵ — **Patient موجودیت clinic-level است؛ «پزشک بودن در همان درمانگاه» به‌طور خودکار دسترسی به Private Notes پزشک دیگر نمی‌دهد.** (ADR-0031 آن را با AD-09 به سطح Organization تعمیم می‌دهد.)
> - بند ۶ — مالی هرگز به بالینی imply نمی‌شود.
> - بند ۷ — این ADR «قید معماری دائمی» است نه مجوز پیاده‌سازی زودهنگام.
>
> **آنچه Superseded شد:**
> - **مدل مفهومی هدف در بخش Context** — `Clinic → Branch(es) → Departments/Specialties → Clinicians`. این مدل **لایهٔ Organization ندارد** (قید C-1) و «Branch» هرگز به schema نرسید (قید C-2).
>   **مدل مرجع از 2026-09-08:** `Organization → Clinic → Location → Doctor → User/Staff`.
>   واژهٔ **«Branch» از این پس معادل «Location»** خوانده می‌شود؛ اسناد جدید فقط «Location» به کار می‌برند.
> - **ارجاع به `docs/architecture/multi-doctor-readiness-review.md` با حکم «۰ FOUNDATIONAL CHANGE REQUIRED».** آن حکم با شواهد اجرایی Phase 0 (۹ قید C-1..C-9) رد می‌شود.
> - **زمان‌بندی «قابلیت‌های چندپزشکی در فاز خودشان (V2)».** واژهٔ «V2» منسوخ است: Multi-Clinic/Multi-Location = **Phase 2**، Role/Scope = **Phase 3** (Roadmap تأییدشدهٔ Owner).
>
> بند خودِ همین ADR که می‌گوید «deviations نیاز به ADR جدید با تأیید کارفرما دارند» با ADR-0031 اجرا شده است.

---

وضعیت: **Accepted (اصل محصول)** / **Partially Superseded by ADR-0031 (مدل دامنه)** | تاریخ: 2026-09-06 | نوع: **تصمیم محصول نهایی کارفرما (دائمی)**

## Context

تصمیم نهایی محصول (کارفرما، 2026-09-06): محصول واحد برای هر دو سناریو —

1. **مطب پزشک** (Single-Doctor Practice)
2. **درمانگاه/کلینیک چندپزشکی** (Multi-Doctor Clinic)

مدل مفهومی هدف:

```
Clinic → Branch(es) → Departments/Specialties → Clinicians/Doctors
       → Staff Assignments → Services → Schedules → Appointments
       → Visits → Queues → Clinical Records → Financial Records
```

## Decision

1. **One Product / One Core / One Database Architecture / Adaptive Features-UX.**
   - دو Plugin، دو Codebase یا Fork جداگانه **ممنوع**.
   - Core Architecture مشترک؛ مطبِ تک‌پزشکی = **حالت ساده‌شده همان معماری چندپزشکی** (یک Clinician فعال)، نه مسیر کدی جدا.
2. **تطبیق UX با Feature Flag / Configuration / Context-aware UI:**
   - حالت مطب: انتخاب پزشک وقتی فقط یک پزشک فعال است خودکار Skip می‌شود؛ امکانات غیرضروری درمانگاهی UI را شلوغ نمی‌کنند.
   - حالت درمانگاه: چند پزشک/تخصص/Staff متعدد + Role/Permission/Scope قابل تنظیم + Schedule مستقل + Queue فیلترشونده + Financial Attribution per Doctor + Reporting Clinic-wide طبق Permission + آمادگی Branch/Department/Room.
3. **Scope و Authorization همیشه سرور-side** (تصمیم DATA ISOLATION): فیلتر فرانت‌اند (مثل `doctor_id=123`) هرگز مبنای مجوز نیست؛ Backend باید دسترسی Caller به داده clinician 123 را verify کند.
4. **Specialty = مفهوم دامنه، نه نقش Authorization.** Doctor↔Specialty باید قابل مدل‌سازی چندتایی باشد.
5. **Patient موجودیت clinic-level است** — بدون رکورد تکراری per-doctor؛ Visit/Appointment تعیین‌کننده Clinician مربوطه‌اند. دسترسی بالینی بین پزشکان تابع Authorization/Privacy صریح است؛ «پزشک بودن در همان درمانگاه» به‌طور خودکار به Private Notes پزشک دیگر دسترسی نمی‌دهد.
6. **مالی هرگز به بالینی imply نمی‌شود** (ADR-0026 D-8) — Attribution: Clinic / Clinician / Service (+Branch/Department در آینده اگر فعال شود).
7. **پیاده‌سازی تدریجی طبق roadmap** — عمده قابلیت‌های چندپزشکی در فاز خودشان می‌آیند؛ این ADR «قید معماری دائمی» است، نه مجوز پیاده‌سازی زودهنگام.

## Consequences

- معماری موجود از روز اول با همین مدل ساخته شده (ADR-0003: `clinic_id`/`clinician_id` همه‌جا؛ ADR-0026: Capability+Scope) — بازبینی آمادگی ۱۰‌محوری: `docs/architecture/multi-doctor-readiness-review.md` (نتیجه: ۰ FOUNDATIONAL؛ ۱۱ قلم MINOR ALIGNMENT مستند و به فازها F9/V1.5/V2/F10 منتقل شدند).
- توسعه‌های آینده **همه** باید این تصمیم را رعایت کنند؛ deviations نیاز به ADR جدید با تأیید کارفرما دارند.
- «حالت مطب» فقط یک پیکربندی/تشخیص UX است (مثلاً شمار Clinician فعال = 1) — هرگز branch کد جدا.
- لایسنس (F10) باید Entitlement چندپزشکی را مدل کند (مثلاً پلن per-doctor) — Seam فعلی LicenseGate عملیاتی است و تعداد پزشک را فرض نمی‌کند.

## ارتباط با اسناد دیگر

- ADR-0003 (چند-پزشک/شعبه از روز اول) — پیش‌نیاز تحقق‌یافته
- ADR-0026 (نقش‌های پویا + Capability/Scope) — مدل Authorization هدف
- `docs/architecture/multi-doctor-readiness-review.md` — بازبینی آمادگی + Backlog هم‌ترازی (phase-mapped)
- SRS §1.1/§1.2/§2.1/§2.4 (A-1/A-5) + §3.4 — هم‌راستا شدند
- `docs/permissions/permission-matrix.md` §6 — Scope هدف
- `docs/roadmap/roadmap.md` — Backlog هم‌ترازی چندپزشکی
