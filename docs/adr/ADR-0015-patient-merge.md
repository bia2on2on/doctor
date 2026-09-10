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

> ### وضعیت واقعی — بازبینی 2026-09-08 (تصمیم این ADR تغییر نکرده؛ فقط واقعیت اجرا ثبت می‌شود)
>
> ⚠️ **Merge فقط schema است.** جدول `cpms_patient_merges` (با `clinic_id`, `surviving_patient_id`, `merged_patient_id`, `merged_by_wp_user_id`, `reason`, `mapping_json`, `merged_at`) و capability `cpms_patient_merge` هر دو وجود دارند، اما `grep "function .*[Mm]erge"` روی `PatientService` و `PatientRepository` **صفر نتیجه** می‌دهد ⇒ **هیچ پیاده‌سازی‌ای وجود ندارد.**
>
> ❌ **`resolvePatient()` وجود ندارد** — `grep -rn "resolvePatient" src` = ۰ نتیجه. **`Repository Base` هم وجود ندارد** (۱۷ کلاس، صفر `abstract`/`extends`). یعنی هر دو مکانیزمی که این ADR صحت پس از merge را به آن‌ها سپرده بود، **NOT IMPLEMENTED** هستند.
>
> **پیامد برای معماری آینده:** طراحی Patient Identity در [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md) (AD-09) **نمی‌تواند به merge موجود تکیه کند**. ضمناً merge فعلی درون‌کلینیکی است (`clinic_id` دارد)؛ «ادغام Identity» در سطح Organization یک نوع دوم است که هیچ رکورد بالینی جابه‌جا نمی‌کند. مکانیزم identity-resolution هنوز **تصویب نشده** (Q2).
