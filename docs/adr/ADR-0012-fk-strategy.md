# ADR-0012 — استراتژی Foreign Key: حیاتی فیزیکی، High-Volume منطقی

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§35: Foreign Keys/Logical Integrity باید تصمیم دقیق داشته باشد؛ جداول بزرگ (audit/history) I/O حساس‌اند.

## Decision
- **FK فیزیکی (InnoDB):** داده‌های حیاتی/تخفیف‌پذیر — `patients→clinics`، `appointments→slots/patients/clinicians` (RESTRICT)، `visits→patients/appointment`، `payments→invoices` (RESTRICT)، `invoice_items→invoice` (CASCADE)، `prescription_items→prescriptions` (CASCADE).
- **Logical فقط (بدون FK فیزیکی):** `cpms_audit_logs`، `cpms_visit_status_history`، `cpms_clinical_note_versions`، `cpms_handwriting_page_versions`، `cpms_operational_logs`، `cpms_jobs` — صحت با Job موندگاری + Query Layer.
- `ON DELETE RESTRICT` برای بیمار/فاکتور (داده با وابسته قابل حذف نیست — با Soft Delete/Archive جایگزین).

## Consequences
+ Integrity حیاتی در سطح DB؛ Performance جداول بزرگ.
− باید Job موندگاری (Dangling Check) در F1 ساخته شود (تست TP-15).

> ### وضعیت واقعی — بازبینی 2026-09-08 (تصمیم این ADR تغییر نکرده؛ فقط واقعیت اجرا ثبت می‌شود)
>
> ❌ **Job موندگاری (Dangling Check) ساخته نشد.** `src/Application/Jobs/` شامل ۱۶ handler است و هیچ‌کدام این کار را انجام نمی‌دهد. **NOT IMPLEMENTED.** اسناد نباید وجود فعلی آن را القا کنند.
>
> **شمارش واقعی FK:** **۳۹** Foreign Key در کل schema — ۳۸ درون `CREATE TABLE` و یک مورد (`fk_hwpage_bg`) از راه `ALTER TABLE` در Migration `0004`.
>
> **شکاف مهم‌تر (قید C-8):** از **۲۵** جدولی که ستون `clinic_id` دارند، تنها **۴** جدول FK واقعی به `cpms_clinics` دارند (`fk_clinicians_clinic`, `fk_patients_clinic`, `fk_schedule_clinic`, `fk_services_clinic`) ⇒ **۲۱ جدول بدون FK**. یعنی ایزولاسیون داده بین کلینیک‌ها امروز در سطح دیتابیس تضمین نمی‌شود. افزودن این ۲۱ FK در **Phase 2** انجام می‌شود — [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md).
