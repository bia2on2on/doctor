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

## 1.5. وضعیت فعلی پیاده‌سازی (پس از Chunk A/B/C)

> 🟢 **Chunk A** (IA + منوی Top-Level «مدیریت مطب» + داشبورد) — کامل، سبز (commit `fabe13e`/`8f36894`).
> 🟢 **Chunk B** (Setup Wizard ۱۲-گام) — کامل، سبز (commit `0f6e5a1`).
> 🟢 **Chunk C** (بخش Staff/User + Role presets/Advanced perms موجود) — کامل، سبز (commit `409dbe3`).
>
> **ساختار منوی فعلی:**
> - **«مدیریت مطب» (top-level، فقط مدیر/`cpms_config`):** داشبورد، راه‌اندازی، سلامت سیستم، فنی و لاگ،
>   پزشکان و برنامه کاری، کاربران و دسترسی‌ها، پیامک و اعلان‌ها.
> - **نقش‌محور (top-level جدا، workbench):** «صف امروز» (منشی)، «امروز پزشک» (پزشک)، «نوبت‌های من» (بیمار).
>
> ⚠️ **تصمیم باز برای Product Owner:** در نقشهٔ هدف (بخش ۲) صفحات منشی/پزشک «زیر CPMS» آمده؛ اما
> پیاده‌سازی فعلی آن‌ها را به‌عنوان top-level نقش‌محور جدا نگه داشته تا navigation نقش‌ها (که به parent
> capability وابسته است) نشکند. اگر PO بخواهد همه در یک منوی «مدیریت مطب» ادغام شوند، باید منوی top-level
> با cap مشترک + فیلتر visibility نقش‌محور ساخته شود (Refactor بعدی). صفحات Tools/Settings پراکنده
> (items ۱–۵) دیگر تکراری نیستند و به `admin.php?page=*` تحت «مدیریت مطب» اشاره می‌کنند.

**یادداشت‌های کلیدی:**
- چند `plugin_action_links` و onboarding/wizard اضافه شد؛ هیچ `add_shortcode` فعلی وجود ندارد.
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

> ✅ **Done** — top-level «مدیریت مطب» + زیرمنوهای re-home + داشبورد + action links + onboarding notice.
> Legacy `tools.php?page=cpms-system`/`cpms-settings` به `admin.php?page=*` (تحت «مدیریت مطب») در
> `admin_menu` priority 5 هدایت می‌شوند تا قبل از 403 `user_can_access_admin_page()` (commit `fabe13e`).
> گیت Real WP Acceptance (هر دو prefix `clinic_`/`wp_`) سبز.

### Chunk B — Setup Wizard (Self-service, resumable)
- گام‌های ۱..۱۲ طبق مشخصات؛ resumable؛ progress؛ Optional/Required؛ حفظ data؛ بدون PHI؛ responsive؛ استفاده از سرویس‌های موجود (no duplicate logic).

> ✅ **Done** — `CpmsSetupWizard` (admin.php?page=cpms-wizard) با ۱۲ گام (welcome/clinic/booking/users/
> doctors/schedules/sms/backup/license/health/review/finish)؛ progress؛ resumable از طریق
> `setup.current_step`/`setup.started_at`؛ ذخیرهٔ Atomic + audited روی Settings؛ بدون PHI؛
> گیت تکمیل = نام کلینیک + ≥۱ پزشک فعال؛ گام‌های اختیاری (SMS/backup/license) مانع نمی‌شوند؛
> ثبت `setup.completed`؛ استفاده از سرویس‌های موجود (no duplicate logic)؛ CSRF + capability + sanitize + bounded؛
> تست Integration (`SetupWizardTest`) سبز (commit `7327969`).

### Chunk C — Staff/User Management + Account/Password + Role Presets + Advanced Permissions
- صفحه «کاربران و دسترسی‌ها» با list/add/edit/activate/deactivate/assign role/link doctor/search/filter.
- ساخت اکانت امن (WP APIs)، password-reset/link، بدون ذخیره/نمایش plaintext.
- Role Presets انسانی (پزشک/منشی/حسابدار/مدیر کلینیک) با «این نقش می‌تواند/نمی‌تواند».
- Advanced Permissions (پیش‌فرض جمع‌شده)، grouped/searchable/Persian، هشدار حساس، Audit، جلوگیری از privilege escalation.
- Role Preset Reset با تأیید/پیش‌نمایش/Audit.

> ✅ **Done (بخش Staff/User)** — `StaffManagementPage` (admin.php?page=cpms-staff) تحت «مدیریت مطب»؛
> مدیریت فقط نقش‌های CPMS (پزشک/منشی/بیمار)؛ انتساب/ویرایش/غیرفعال‌سازی administrator مجاز نیست
> (جلوگیری از privilege escalation)؛ ایجاد/ویرایش امن با WP core API؛ رمز هرگز plaintext ذخیره/نمایش
> نمی‌شود (در صورت خالی، رمز قوی CSPRNG یک‌بار نمایش داده می‌شود)؛ اعتبارسنجی قدرت رمز؛
> غیرفعال‌سازی بدون حذف تاریخچه (نقش قبلی در usermeta و بازگردانی هنگام فعال‌سازی)؛ Audit کامل.
> تست Integration (`StaffManagementTest`) سبز (commit `409dbe3`).
> **Role Presets + Advanced Permissions** توسط `RoleCapabilitiesPage` موجود (cpms-roles) پوشش داده
> می‌شود (audit + reset + whitelist + self-healing) — همان منطق موجود، بدون duplicate.
> روابط/ردیرکت‌های re-home شده هم به `admin.php?page=*` کانونیکال اشاره می‌کنند.

### Chunk D — Doctor Management + Schedule (workflow منسجم)
- «افزودن پزشک → اطلاعات → اکانت/نقش → Schedule → ذخیره»؛ بدون دو جایگاه جداگانه WP Users و CPMS.
- Schedule بصری هفتگی؛ بدون invalidate بی‌صدا؛ نمایش impact.
- Deactivate بدون حذف history.

> ✅ **Done (commit `ccecf3e`/`6c7f6f1`)** — «افزودن پزشک» حالا می‌تواند حساب وردپرس را با نقش
> `cpms_doctor` در همان جریان بسازد (reuse مسیر امن `StaffManagementPage::upsertUser`؛
> `upsertUser` حالا `user_id` را هم برمی‌گرداند) و پیوند ۱:۱ برقرار کند. بدون invalidate بی‌صدا:
> `ScheduleService::impact()` گزارش می‌دهد چند اسلات خالی آینده بازتولید می‌شود و چند اسلات
> رزرو/Hold «محافظت» می‌شود؛ صفحهٔ پزشک جعبهٔ پیش‌نمایش را نشان می‌دهد و پیام ذخیره شامل
> اعداد تأثیر است. رمز هرگز plaintext ذخیره/نمایش نمی‌شود؛ غیرفعال‌سازی تاریخچه را حفظ می‌کند.
> تست Integration (`DoctorWorkflowTest`) سبز؛ PHPStan/Unit/Closure/Real-WP-Acceptance/Release/
> Upgrade/Responsive همه سبز؛ فقط Staging Gate (غیرمرتبط، از قبل) در انتظار.

### Chunk E — License / Backup / Update / Health (صفحات اختصاصی)
- تفکیک از `SystemPage` به بخش‌های قابل‌فهم؛ هر fault = «چه، اثر، چه کنم» + «جزئیات فنی» جمع‌شونده.
- Restore پراصطکاک/امن (preflight/warning/safety/confirm/audit).

> ✅ **Done (commit `819e0a3`)** — بخش Health به‌صورت «کارت خطا» برای هر بررسیِ غیر-PASS رندر می‌شود
> (چه/اثر/چه کنم) + «جزئیات فنی» جمع‌شونده؛ بررسی‌های PASS در جدول فشرده می‌مانند. راهنمای
> انسانی از تابع خالص `SystemPage::guide()` (قابل تست، بدون WP/DB) می‌آید. Restore امن/پراصطکاک:
> preflight (جدول/ردیف/فایل/یکپارچگی/restore_safe) پیش از هر اقدام مخرب + چک‌باکس اقرار +
> تایپ RESTORE — همگی از b‌ک‌اندِ موجود (`restorePreflight` + Safety Backup خودکار + audit)
> استفاده می‌کنند (بدون duplicate). تست Integration (`SystemAdminUxTest`) سبز؛ همهٔ گیت‌ها
> (PHPStan/Unit/Closure/Real-WP/Release/Upgrade/Responsive) سبز؛ فقط Staging Gate (غیرمرتبط،
> از قبل) در انتظار. سرصفحهٔ «وضعیت Health / سازگاری میزبان» و مقاومت بخش‌به‌بخش (D1) حفظ شد —
> `SystemAdminPagesTest` همچنان سبز.

### Chunk F — طراحی/Responsive/Accessibility + Empty states + Dangerous-action UX
- Card/section/badge/empty-state؛ RTL؛ responsive ۶ رزولوشن؛ keyboard/focus/contrast؛ confirmation.

> ✅ **Done (commit `63ce037` + `70a0c80` + `5fe13f0` + `7f3b6a3`)** — طراحی اسکوپ‌شدهٔ صفحات
> CPMS (`assets/css/cpms-admin.css` + `assets/js/cpms-admin.js` + `CpmsAssets`؛ فقط صفحات CPMS،
> بدون asset سراسری/فریم‌ورک). Permissions بازطراحی: Normal = Role Presets + توضیح فارسی +
> «می‌تواند/نمی‌تواند» + هشدار حساس؛ Advanced = جمع‌شونده/گروه‌بندی/جستجو با حفظ
> `role_caps[role][]` و مسیر امن backend. Empty states (پزشک/پرسنل/بکاپ) با next action؛
> dangerous actions با تأیید. گیت `real-wp-acceptance.yml` با اسکرین‌شات دسکتاپ/موبایل
> (Dashboard/Setup/Staff/Clinicians/Roles/System/Advanced Permissions) و بازبینی منو + console
> متصل شد. همهٔ گیت‌ها سبز؛ فقط Staging Gate (غیرمرتبط، از قبل) در انتظار.

> ✅ **Done (commit `63ce037`)** — سیستم طراحی اسکوپ‌شدهٔ صفحات CPMS (`assets/css/cpms-admin.css`
> + `assets/js/cpms-admin.js`) + `CpmsAssets` (فقط صفحات CPMS، بدون asset سراسری/فریم‌ورک).
> صفحهٔ Permissions بازطراحی: Normal = Role Presets + توضیح فارسی نقش + «می‌تواند/نمی‌تواند»
> + هشدار حساس؛ Advanced = جمع‌شونده/گروه‌بندی/جستجو با حفظ `role_caps[role][]` و مسیر امن backend.
> Empty states حرفه‌ای (بدون پزشک/پرسنل/بکاپ/گزارش/SMS) هرکدام با next action. Dangerous actions
> با تأیید (onclick/data-cpms-confirm). گیت `real-wp-acceptance.yml` با اسکرین‌شات دسکتاپ/موبایل
> (Dashboard/Setup/Staff/Clinicians/Roles/System/Advanced Permissions) متصل شد. تست Integration
> `AdminUxDesignTest` + PHPStan/Unit/Closure/Real-WP-Acceptance/Release/Upgrade/Responsive سبز؛
> فقط Staging Gate (غیرمرتبط، از قبل) در انتظار.

### Chunk G — Test Matrix + Real WP Acceptance (افزوده)
- تست‌های menu registration، role-aware visibility، action links، onboarding state، wizard progress، user creation، role assignment، password، clinician association، deactivation، schedule، presets، privilege-escalation، CSRF، IDOR، audit، responsive smoke، console، Real WP Acceptance.
- گیت `real-wp-acceptance.yml` روی ZIP رسمی extend می‌شود (Admin/Secretary/Doctor/Accountant/Manager).

> ✅ **Done (commit `4c00ed0` + `9da81e4` + `4cb8b58` + `c297b59`)** — نقش‌های V1
> `cpms_accountant` (فقط مالی/گزارش، بدون بالینی/خصوصی) و `cpms_manager` (مدیریت ستادی/عملیاتی
> با `cpms_config`، بدون بالینی/یادداشت خصوصی/صف و بدون `manage_options`) ثبت و در
> `StaffManagementPage` (ایجاد/ویرایش/فعال/غیرفعال + نقش) و `RoleCapabilitiesPage` (پیش‌فرض‌ها +
> Advanced) اضافه شدند؛ «مالی و تسویه» به منوی مستقل `cpms_finance_read` تبدیل شد تا حسابدار بدون
> `cpms_queue_read` هم به آن برسد و تب «در انتظار تسویه» (دادهٔ صف با `QUEUE_READ`) برای او پنهان
> است؛ ماتریس دسترسی فقط برای مالک فنی (`manage_options`) قابل ویرایش است. گیت
> `real-wp-acceptance.yml` برای هر ۵ نقش (Admin/Manager/Doctor/Secretary/Accountant) منو + دسترسی
> مستقیم (403) + console را می‌سنجد؛ `RoleManagementGTest` (REST منفی + negative/audit) و
> `StaffManagementTest` (ساخت حسابدار/مدیر + رد خود-غیرفعال‌سازی) افزوده شدند.
> PHPStan/Unit/Integration/Closure/Release/Upgrade/Responsive/Real-WP-Acceptance (هر دو prefix)/
> **Staging Gate** همگی سبز.

## 4. تعهدها / Non-goals

- **عدم تغییر:** Core، migration، permission model، authorization، audit، licensing policy، booking concurrency، idempotency.
- **Non-goals:** OCR، telemedicine، lab، multi-branch، native app، AI diagnosis، vendor-hosted data، V2 feature.
- **CSS/JS** فقط در صفحات CPMS؛ بدون asset سراسری؛ بدون SPA سنگین.

## 5. Verdict (پس از اجرا)

در انتها `ADMIN_UX_READY` یا `ADMIN_UX_NOT_READY` + گزارش کامل فارسی ارائه می‌شود. **این PR merge نمی‌شود؛ برای بازبینی Product Owner می‌ماند.**
