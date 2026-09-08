# ADR-0002 — مدل نقش/قابلیت و تفکیک Admin فنی از دسترسی پزشکی

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
Master Prompt §1 و §37: Administrator وردپرس نباید به‌صورت خودکار PHI ببیند؛ Authorization صریح و مستقل.

## Decision
- افزونه 3 نقش می‌سازد: `patient`، `clinic_secretary`، `clinic_doctor` (با مجموعه Capability `cpms_*`).
- نقش `administrator` وردپرس: فقط `cpms_config` + فنی. دسترسی پزشکی (`cpms_medical_read`، `cpms_audit_read`، ...) **به کاربر** (نه نقش) به‌صورت دستی اعطا می‌شود.
- یک `AccessPolicy` (Single Source of Truth) برای Capability + Data-Access + Field/Row Filter.
  > ❌ **NOT IMPLEMENTED (تأیید 2026-09-08):** `grep -rl "AccessPolicy" src` → **۰ فایل**. این کلاس هرگز ساخته نشد. نقش آن در معماری جدید توسط `AuthorizationService` ایفا می‌شود — طراحی‌شده در `docs/architecture/phase0.5-target-model.md` §ج، پیاده‌سازی در **Phase 3**. اسناد نباید وجود فعلی آن را القا کنند.
- نقش `clinic_manager` (مدیر مطب) به‌عنوان گسترش آماده (بدون تغییر مدل).
  > ⚠️ **تصحیح واقعیت (2026-09-08):** این «گسترش آماده» از قبل ساخته شده است — با slug `cpms_manager` («مدیر کلینیک») نه `clinic_manager`. کد فعلی **۵ نقش** ثبت می‌کند نه ۳: `cpms_patient`, `cpms_secretary`, `cpms_doctor`, `cpms_accountant`, `cpms_manager` (`RolesAndCapabilities.php:186-190`). بازتعریف نقش‌ها به‌صورت per-(User, Clinic) در **Phase 3** انجام می‌شود — [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md) AD-06.
  >
  > ✅ **اصل P-3 این ADR تأیید و تقویت شد:** ADR-0031 با AD-10/AD-11 صریح می‌کند که `administrator` وردپرس هیچ blanket clinical-data bypass ندارد و System Administration از Clinical Data Access جداست.

## Consequences
+ Least Privilege واقعی؛ Audit تغییر مجوز.
− Admin فنی برای «دیدن یک پرونده» باید Capability بگیرد (ارزش امنیتی).

## Alternatives
- نقش `clinic_admin` با دسترسی کامل (مردود: نقض §1).
- فقط نقش‌های وردپرس بدون Capability (مردود: دانه‌بندی درز).
