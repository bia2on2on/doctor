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
