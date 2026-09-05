# ADR-0015 — ادغام (Merge) رکوردهای تکراری بیمار

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§2: حساب‌های تکراری، اصلاح موبایل، ادغام رکوردهای تکراری باید در معماری ممکن باشد.

## Decision
- **Soft Merge (نه Delete):** بیمار «مادر» (surviving) + بیمار «فرزند» → `status=archived` + ردیف در `cpms_patient_merges` (نگاشت + Actor + Reason + زمان).
- فرایند (Transaction):
  1. قفل دو رکورد (FOR UPDATE، ترتیب id ثابت → Deadlock-free).
  2. انتخاب Surviving (قاعده: رکورد با تاریخچه کامل‌تر / انتخاب کاربر).
  3. بازتوجه FKهای «مستقیم» (visits/appointments/notes/...) به Surviving — فقط در جداول که FK فیزیکی دارند.
  4. جداول بدون FK فیزیکی: نگاشت در `mapping_json` (Query Layer: `resolvePatient(id)`).
  5. `patient_user_links` فرزند → Surviving (با بررسی تکرار).
  6. Audit `PATIENT_MERGE` (mapping کامل).
- **فرایند قابل بازگشت نیست** (Policy) — اما داده حذف نمی‌شود (Archive)؛ ابزار بازگردانی داخلی برای مدیر فنی (V1.5).
- UI: V1.5 (ابزار «مدیریت تکراری‌ها»); API در V1.

## Consequences
+ بدون داده‌پردازی خطرناک؛ ردپای کامل.
− `resolvePatient` باید در همه Queryهای بیمار اعمال شود (Repository Base — تست TP-15).
