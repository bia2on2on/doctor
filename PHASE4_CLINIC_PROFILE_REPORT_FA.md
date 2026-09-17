# گزارش نهایی — Phase4 Clinic Profile Canonicalization (Trusted)

**START_TEHRAN:** 2026-09-17 03:30:00 +0330
**END_TEHRAN:** 2026-09-17 04:42:42 +0330

## خلاصه اجرایی

پیاده‌سازی به‌روزرسانی مطمئن پروفایل کلینیک (نام/آدرس/تلفن) با منبع canonical واحد `cpms_clinics` انجام شد. مسیر قدیمی `setup.clinic.*` دیگر second writable canonical نیست — ردیف‌های تاریخی حذف نمی‌شوند اما دیگر نوشته نمی‌شوند. مسیر Setup Wizard Clinic Information نیز به همان مسیر canonical یکپارچه شد و فیلد timezone حذف شد (حقیقت عملیاتی timezone از Location می‌آید، بدون sync).

### فایل‌های کلیدی پیاده‌سازی

- `src/Infrastructure/Repository/ClinicRepository.php` — متد `find()` و `updateProfile()` با fail-closed (RuntimeException روی last_error) و فقط ستون‌های مجاز `name/address/phone/updated_at`.
- `src/Application/Clinic/ClinicProfileException.php` — کدهای خطا `AUTH_REQUIRED, SCOPE_REQUIRED, NOT_FOUND, PERMISSION_DENIED, VALIDATION, QUERY_FAILED`.
- `src/Application/Clinic/ClinicProfileService.php` — سرویس اصلی:
  - ورودی `actorUserId` و `trustedClinicId` (از TrustedClinicEstablisher/ScopeContext) — هیچ `clinic_id` خام از payload اعتماد نمی‌شود.
  - بارگذاری ردیف durable از `cpms_clinics`، حفظ invariants `id/organization_id/slug/timezone/created_at`.
  - احراز هویت CONFIG از طریق `AuthorizationService::authorize()` (membership durable + explicit CONFIG) — بدون fallback به کلینیک اول و بدون ID ثابت.
  - اعتبارسنجی اتمیک قبل از هر جهش: `name` تریم‌شده غیرتهی ≤190، `address` ≤255، `phone` ≤32، در صورت نامعتبر صفر جهش جزئی.
  - تشخیص no-op (نام/آدرس/تلفن یکسان) و بازگشت بدون audit.
  - تراکنش با `SELECT ... FOR UPDATE` و به‌روزرسانی فقط ستون‌های مجاز، fail-closed روی query failure (نه موفقیت خاموش).
  - audit `CLINIC_PROFILE_UPDATED` فقط روی تغییر واقعی، با حفظ timezone و locations.
- `src/Admin/CpmsSetupWizard.php` — یکپارچه‌سازی canonical:
  - `renderClinic()` و `renderReview()` و `isReadyToOperate()` اکنون `cpms_clinics` را از `ScopeContext/App::scope` می‌خوانند.
  - `saveClinic()` دیگر dual-write ندارد؛ فقط `trustedClinicId` از Scope + `ClinicProfileService` را صدا می‌زند؛ timezone مدیریت نمی‌شود.
  - `saveBooking` و سایر گام‌ها بدون تغییر.
- `src/Bootstrap/App.php` — افزودن `clinicRepository()` و `clinicProfileService()` به DI.

## ادغام Setup Wizard

- مسیر محصول: `CpmsSetupWizard::saveClinic()` — narrowest existing callable wizard method (به دلیل `wp_safe_redirect + exit` در `save()`).
- ورودی‌های wizard: `clinic_name`, `clinic_address`, `clinic_phone` — `clinic_timezone` نادیده گرفته می‌شود (مطابق الزام DO NOT include timezone).
- پس از GREEN: wizard مستقیماً `cpms_clinics` را به‌روز می‌کند؛ `setup.clinic.*` تاریخی باقی می‌ماند اما مقدار جدید در آن نوشته نمی‌شود — تست RED این را اثبات می‌کند.

## شواهد تست‌ها

### CI سبز نهایی (run 35169312043)

```
Integration (WP 6.7 + MySQL 8)  pass  1m53s
Static Analysis (PHPStan)       pass  44s
Unit Tests (PHP 8.1-8.4)        pass
WPCS (changed code)             pass
Tenant Tripwire                 pass
Real WP Acceptance (wp_, clinic_) pass
Closure — PHP 8.1/8.3/8.4, WP 6.4/6.5/6.6, destructive restoreApply pass
```

### RED → GREEN

- `tests/Integration/Phase4ClinicProfileCanonicalizationRedTest.php`:
  - Fixture: Organization دینامیک + Clinic A/B دینامیک (نه 0 نه 1) + Location A/B با timezone صریح `Asia/Tehran` / `Asia/Kabul` + actor فعال CONFIG + membership durable + مقادیر قدیمی `cpms_clinics`.
  - اثبات RED قبلی: `cpms_clinics(A).name` باید پس از `saveClinic` برابر مقدار ورودی اپراتور شود اما قدیمی می‌ماند — اکنون GREEN است.
  - اثبات downstream: `FinanceService::receipt()` مقدار جدید را بدون wiring سفارشی می‌بیند (clinic.name/address/phone جدید).
  - اثبات عدم second canonical: `setup.clinic.name` تاریخی حفظ می‌شود و برابر مقدار قدیمی باقی می‌ماند (نه جدید).

### GREEN + Negative Controls A-L

`tests/Integration/Phase4ClinicProfileTest.php`:

- **HappyPath**: canonical به‌روز، حفظ `slug/org_id/timezone/created_at`, location timezone بدون تغییر، audit `CLINIC_PROFILE_UPDATED` ثبت، receipt downstream مقدار جدید را نشان می‌دهد.
- **A AUTH_REQUIRED**: `actorUserId=0` → 401, صفر جهش.
- **B SCOPE_REQUIRED**: `trustedClinicId=0` → 400, صفر جهش.
- **C NOT_FOUND**: کلینیک ناموجود 999999 → 404.
- **D Suspended**: membership suspended → PERMISSION_DENIED, صفر جهش (استفاده از `ClinicScope::forClinic()->withOrganization` به دلیل private ctor).
- **E NoMembership**: بدون membership → PERMISSION_DENIED.
- **F NoCONFIG**: secretary بدون CONFIG → PERMISSION_DENIED.
- **G CrossClinic**: actor A با scope A تلاش برای تغییر B → PERMISSION_DENIED, A و B بدون تغییر.
- **H Validation Empty Name**: نام تریم خالی → VALIDATION 422, صفر جهش.
- **I Validation Name Too Long**: 191 کاراکتر → VALIDATION.
- **J Validation Address Too Long**: 256 → VALIDATION.
- **K Validation Phone Too Long**: 33 → VALIDATION.
- **L Noop & Audit & Atomic & Preserve**: 
  - noop با مقادیر قدیمی → `noop=true`, audit count 0
  - تغییر واقعی → audit count 1, timezone حفظ, location timezone حقیقت عملیاتی
  - validation اتمیک (نام معتبر + phone نامعتبر) → صفر جهش
  - عدم fallback به کلینیک 1

### SetupWizardTest بازنویسی شده

- `testSaveClinicPersists`: اکنون canonical `cpms_clinics` را چک می‌کند، نه `setup.clinic.*`.
- `testSaveClinicFallsBackTimezone`: timezone باید حفظ شود، نه fallback به `setup.clinic.timezone`.
- `testWizardDoesNotCompleteWithoutClinicName`: canonical name خالی → عدم تکمیل.
- سایر تست‌ها با `authorizeConfigUser` که membership + WP cap CONFIG می‌دهد، سازگار شد.

## Invariantهای رعایت‌شده

- `cpms_clinics` تنها canonical.
- `setup.clinic.*` حذف تاریخی نمی‌شود، اما دیگر writable نیست.
- Context مطمئن + احراز CONFIG، عدم اعتماد به `clinic_id` خام، عدم جهش بین کلینیک‌ها.
- فقط `name/address/phone/updated_at` قابل تغییر، حفظ `id/organization_id/slug/timezone/created_at` و locations.
- اعتبارسنجی اتمیک، عدم موفقیت خاموش روی خطای کوئری، بدون fallback به اولین کلینیک یا ID ثابت.
- بدون migration 0021، بدون Clinic create/delete، بدون org/location CRUD، بدون timezone editing.

## شواهد اجرایی

- Commit `c9dd91a` و `caab63a` روی شاخه `arena/01a0acc1-doctor` — CI سبز.
- فایل‌های پیاده‌سازی در `src/Application/Clinic/` و `src/Infrastructure/Repository/ClinicRepository.php` و `src/Admin/CpmsSetupWizard.php`.
- تست‌ها در `tests/Integration/Phase4ClinicProfileCanonicalizationRedTest.php` و `Phase4ClinicProfileTest.php` و `SetupWizardTest.php`.

## نتیجه

پیاده‌سازی مطابق مشخصات Trusted Clinic profile update با موفقیت به GREEN رسید، تمام negative controls پاس شد، downstream receipt اثبات شد، و Static Analysis بدون خطای unused use پاس شد.
