# ADR-0003 — چند-شعبه/چند-پزشک از روز اول (clinic_id/clinician_id)

> ## ⛔ SUPERSEDED BY [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md) — 2026-09-08
>
> **وضعیت: Superseded (کامل).** این ADR سند تاریخی است و **مبنای معماری فعلی نیست.** متن اصلی عمداً دست‌نخورده باقی مانده تا تاریخچهٔ تصمیم حفظ شود.
>
> **چرا Superseded شد — سه دلیل با شاهد (`docs/phase-reports/report-phase-0-reverification.md`):**
>
> 1. **بند «V1 مقدار ثابت 1» به بدهی فنی تبدیل شد.** نتیجهٔ عملی‌اش **۵۴ مورد hardcode شدهٔ `clinic_id = 1` در ۲۳ فایل** بود (۲۴ مورد داخل لایهٔ Repository) — قید C-4. طبق **AD-13** در ADR-0031، `clinic_id = 1` مستقیم در کد جدید **ممنوع** است.
> 2. **ادعای «`clinic_id` (FK→cpms_clinics) در تمام جداول پزشکی» درست نیست.** واقعیت: از ۴۱ جدول، ۲۵ جدول ستون `clinic_id` دارند و تنها **۴** جدول FK واقعی به `cpms_clinics` دارند (`clinicians`, `patients`, `schedule`, `services`) ⇒ **۲۱ جدول بدون FK** — قید C-8.
> 3. **مکانیزم «Repository Base» که این ADR فیلتر متمرکز `clinic_id` را به آن سپرده بود، وجود ندارد.** ۱۷ کلاس Repository، صفر `abstract class`، صفر `extends`. ⇒ **NOT IMPLEMENTED.**
>
> **همچنین:** مدل این ADR فاقد لایهٔ **Organization** (C-1) و لایهٔ **Location** (C-2) است. معماری مرجع از 2026-09-08:
> `Organization → Clinic → Location → Doctor → User/Staff`
>
> **بند «Data-Scoping تیمی در V2»:** واژهٔ «V2» منسوخ است. طبق Roadmap تأییدشدهٔ Owner، این کار متعلق به **Phase 3 — Role & Access Control** است.

---

وضعیت: ~~Accepted~~ **Superseded by ADR-0031** | تاریخ: 2026-09-05

## Context
§48: V1 تک‌پزشک/تک‌شعبه ولی معماری بن‌بست نسازد.

## Decision
- `clinic_id` (FK→cpms_clinics) در تمام جداول پزشکی؛ V1 مقدار ثابت 1.
- `clinician_id` در Schedule/Slot/Appointment/Visit/Note/RX/FollowUp.
- همه Queryها فیلتر `clinic_id` (در Repository Base) — بدون هزینه عملکردی (Index).
- Data-Scoping تیمی (پزشک فقط بیماران تیمش) در V2 — همین ستون‌ها کافیه.

## Consequences
+ Zero-cost extensibility؛ Reports از ابتدا per-clinic.
− یک ستون اضافه در Queryها (قابل‌حمل).
