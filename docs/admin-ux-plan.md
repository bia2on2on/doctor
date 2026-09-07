# CPMS — ADMIN UX IMPLEMENTATION PLAN (اختصاصی)

## 0. Baseline & Scope

- **Base (origin/main):** `38c573bf2c74814cdb5897e1a260081f8a07e7f1` (merge PR #8)
- **Feature branch (session):** `arena/01a07d25-doctor` — بنا به محدودیت پلتفرم، ساخت branch جداگانه ممکن نیست؛ PR از همین branch باز می‌شود.
- **Core backend (F1–F10 + F11-remediation, booking concurrency, idempotency, migrations, authorization, audit, licensing, backup, update, health, Real WP Acceptance):** **دست‌نخورده و مرجع** باقی می‌ماند. این فاز فقط **IA + Admin UX + Self-service Setup + Staff/Role Management** است و از سرویس/APIهای موجود استفاده می‌کند؛ هیچ منطق business تکراری برای UI ساخته نمی‌شود.

## 1. Inventory — تکتک entryهای فعلی CPMS در WP-Admin

| # | صفحه / Class | Title (منو) | Slug | Capability | محل فعلی | مقصد |
|---|---|---|---|---|---|---|
| 1 | `SystemPage` | CPMS (سیستم) | `cpms-system` | `cpms_config` | Tools | → زیر CPMS → «سلامت سیستم» |
| 2 | `SettingsAdmin` | CPMS (فنی و لاگ) | `cpms-settings` | `cpms_config` | Tools | → زیر CPMS → «فنی و لاگ» |
| 3 | `ClinicianAdminPage` | پزشکان و برنامه | `cpms-clinicians` | `cpms_config` | Tools | → زیر CPMS → «پزشکان و برنامه کاری» |
| 4 | `RoleCapabilitiesPage` | CPMS (دسترسی‌ها) | `cpms-roles` | `cpms_config` | Tools | → زیر CPMS → «کاربران و دسترسی‌ها» |
| 5 | `SmsSettingsPage` | تنظیمات پیامک | `cpms-sms` | `cpms_sms_config` | Settings | → زیر CPMS → «پیامک و اعلان‌ها» |
| 6 | `SecretaryQueuePage` | صف امروز | `cpms-queue` | `cpms_queue_read` | Top-level | → زیر CPMS → «نوبت‌ها و صف» |
| 7 | `SecretaryFinancePage` | مالی و تسویه | `cpms-finance` | `cpms_finance_read` | زیر `cpms-queue` | → زیر CPMS → «مالی» |
| 8 | `DoctorDashboardPage` | امروز پزشک | `cpms-doctor` | `cpms_queue_read` | Top-level | → زیر CPMS → «داشبورد پزشک» |
| 9 | `DoctorHandwritingPage` | دست‌خط | `cpms-handwriting` | `cpms_note_create` | زیر `cpms-doctor` | → زیر CPMS → «دست‌خط» |
| 10 | `PrescriptionPrintPage` | چاپ نسخه | `cpms-prescription-print` | `cpms_rx_read` | hidden (parent null) | بدون تغییر (صفحه چاپ پنهان) |
| 11 | `PatientPortalPage` | نوبت‌های من | `cpms-patient` | `read` | Top-level (فقط بیمار) | بدون تغییر (Patient ≠ WP-Admin عادی) |

**یادداشت‌های کلیدی:**
- هیچ `plugin_action_links`، `add_shortcode` و هیچ `onboarding/notice/wizard` فعلی وجود ندارد.
- هیچ صفحه اختصاصی «داشبورد مدیر»، «بیماران»، «گزارش‌ها»، «مجوز»، «بکاپ»، «به‌روزرسانی» و «کاربران/نقش‌های پیش‌فرض» جداگانه وجود ندارد؛ اینها یا داخل `SystemPage` (مجوز/بکاپ/به‌روزرسانی/Health) یا داخل `RoleCapabilitiesPage` (ماتریس فنی) مدفون‌اند.
- همه صفحات موجود `dir="rtl"` و Persian-first هستند؛ ساختار UI فعلی فرم/جدول خام WP است.

## 2. نقشه کلیدیِ IA هدف (Role-Aware)

یک **منوی Top-Level منسجم** با نام فارسی «مدیریت مطب» (cpms icon) ساخته می‌شود؛ صفحات پراکنده Tools/Settings به زیر همین منو منتقل می‌شوند (slugها حفظ/redirect می‌شوند).

**زیر منوها (capability-driven ، هیچ آیتم مرده):**

| منو | Capability | نقش‌هایی که می‌بینند | منبع |
|---|---|---|---|
| داشبورد CPMS | `cpms_config` + کارت‌های role-aware | مدیر/منشی/پزشک/حسابدار | جدید (Aggregate از سرویس‌ها) |
| نوبت‌ها و صف | `cpms_queue_read` | Secretary / Doctor | `SecretaryQueuePage` |
| مالی و تسویه | `cpms_finance_read` | Secretary / Accountant | `SecretaryFinancePage` |
| بیماران | `cpms_patient_read` | Secretary / Doctor / Manager | جدید (entry، بدون دسترسی medical به‌صرف داشتن صفحه) |
| پزشکان و برنامه کاری | `cpms_config` | Manager / Technical Owner | `ClinicianAdminPage` |
| داشبورد پزشک | `cpms_queue_read` | Doctor | `DoctorDashboardPage` |
| دست‌خط | `cpms_note_create` | Doctor | `DoctorHandwritingPage` |
| کاربران و دسترسی‌ها | `cpms_config` | Manager / Technical Owner | `RoleCapabilitiesPage` + Role Presets + Advanced Permissions |
| پیامک و اعلان‌ها | `cpms_sms_config` | Technical Owner | `SmsSettingsPage` |
| سلامت سیستم | `cpms_config` | Manager / Technical Owner | `SystemPage` (بازچین‌شده : Health / مجوز / بکاپ / به‌روزرسانی) |
| فنی و لاگ | `cpms_config` | Technical Owner | `SettingsAdmin` |

**قاعده Gold:** نمایش منو = Capability (نه hard-code نقش). بک‌اند همچنان مرجع authorize است؛ پنهان‌کردن منو ≠ authorize.

## 3. فعالیت‌های پیاده‌سازی (با ترتیب و chunk)

### Chunk A — IA + منوی Top-Level + داشبورد
- `CpmsAdminMenu` جدید: یک top-level «مدیریت مطب» می‌سازد و صفحات موجود را re-home می‌کند (slugها برای backward-compat حفظ؛ redirect از Tools/Settings قدیمی در صورت نیاز).
- داشبورد role-aware: کارت‌های «وضعیت راه‌اندازی، نوبت امروز، پزشکان فعال، صف فعلی، آخرین بکاپ، Cron، SMS، License، Health warning»؛ کوئری‌ها bounded؛ بدون افشای unauthorized.
- `plugin_action_links` (راه‌اندازی / داشبورد CPMS / تنظیمات) مطابق قابلیت.
- Notice اولیه نصب «راه‌اندازی اولیه» + دکمه‌ها؛ بعد از تکمیل محو می‌شود.

### Chunk B — Setup Wizard (Self-service, resumable)
- گام‌های ۱..۱۲ طبق مشخصات؛ resumable؛ progress؛ Optional/Required؛ حفظ data؛ بدون PHI؛ responsive؛ استفاده از سرویس‌های موجود (no duplicate logic).

### Chunk C — Staff/User Management + Account/Password + Role Presets + Advanced Permissions
- صفحه «کاربران و دسترسی‌ها» با list/add/edit/activate/deactivate/assign role/link doctor/search/filter.
- ساخت اکانت امن (WP APIs)، password-reset/link، بدون ذخیره/نمایش plaintext.
- Role Presets انسانی (پزشک/منشی/حسابدار/مدیر کلینیک) با «این نقش می‌تواند/نمی‌تواند».
- Advanced Permissions (پیش‌فرض جمع‌شده)، grouped/searchable/Persian، هشدار حساس، Audit، جلوگیری از privilege escalation.
- Role Preset Reset با تأیید/پیش‌نمایش/Audit.

### Chunk D — Doctor Management + Schedule (workflow منسجم)
- «افزودن پزشک → اطلاعات → اکانت/نقش → Schedule → ذخیره»؛ بدون دو جایگاه جداگانه WP Users و CPMS.
- Schedule بصری هفتگی؛ بدون invalidate بی‌صدا؛ نمایش impact.
- Deactivate بدون حذف history.

### Chunk E — License / Backup / Update / Health (صفحات اختصاصی)
- تفکیک از `SystemPage` به بخش‌های قابل‌فهم؛ هر fault = «چه، اثر، چه کنم» + «جزئیات فنی» جمع‌شونده.
- Restore پراصطکاک/امن (preflight/warning/safety/confirm/audit).

### Chunk F — طراحی/Responsive/Accessibility + Empty states + Dangerous-action UX
- Card/section/badge/empty-state؛ RTL؛ responsive ۶ رزولوشن؛ keyboard/focus/contrast؛ confirmation.

### Chunk G — Test Matrix + Real WP Acceptance (افزوده)
- تست‌های menu registration، role-aware visibility، action links، onboarding state، wizard progress، user creation، role assignment، password، clinician association، deactivation، schedule، presets، privilege-escalation، CSRF، IDOR، audit، responsive smoke، console، Real WP Acceptance.
- گیت `real-wp-acceptance.yml` روی ZIP رسمی extend می‌شود (Admin/Secretary/Doctor/Accountant/Manager).

## 4. تعهدها / Non-goals

- **عدم تغییر:** Core، migration، permission model، authorization، audit، licensing policy، booking concurrency، idempotency.
- **Non-goals:** OCR، telemedicine، lab، multi-branch، native app، AI diagnosis، vendor-hosted data، V2 feature.
- **CSS/JS** فقط در صفحات CPMS؛ بدون asset سراسری؛ بدون SPA سنگین.

## 5. Verdict (پس از اجرا)

در انتها `ADMIN_UX_READY` یا `ADMIN_UX_NOT_READY` + گزارش کامل فارسی ارائه می‌شود. **این PR merge نمی‌شود؛ برای بازبینی Product Owner می‌ماند.**
