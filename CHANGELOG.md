# Changelog — CPMS (Clinic Practice Management System)

تمام تغییرات مهم پروژه در این فایل ثبت می‌شود. قالب: [Keep a Changelog](https://keepachangelog.com/)؛ نسخه‌بندی: [SemVer](https://semver.org/).

## [1.0.2] — 2026-09-07 (Hotfix نصب واقعی — بازتولیدشده روی WordPress واقعی در CI)

سه نقص گزارش‌شدهٔ نصب روی WordPress واقعی روی main بازتولید شد (گیت جدید «Real WordPress Acceptance»: ZIP رسمی `bin/build-release.sh` → WordPress 6.7.2 تمیز → نصب/فعال‌سازی → تأیید مستقیم DB → مرورگر واقعی Chromium → بررسی لاگ — با ماتریس دو prefix `wp_`/`clinic_`).

### Fixed
- **D2 — شمارش جداول در «CPMS (فنی و لاگ)» همیشه 0 بود:** `SettingsAdmin::tableCount()` الگوی `LIKE 'cpms_%'` بدون `$wpdb->prefix` داشت درحالی‌که جداول واقعی `{prefix}cpms_*` هستند. اصلاح به الگوی prefix-aware (همان الگوی `SystemHealthService`). اثبات E2E: شمارش UI == شمارش مستقیم DB (۴۱ جدول) روی هر دو prefix.
- **D1 — «CPMS (سیستم)»/Health با Critical Error می‌مرد:** `SystemPage::render()` خواندن وضعیت مجوز/Health/«فهرست بکاپ» را بدون guard صدا می‌زد؛ شکست IO مخزن بکاپ (مثل میزبانِ با wp-content غیرقابل‌نوشتن → `BackupException`) کل صفحهٔ وضعیت را Fatal می‌کرد. حالا هر بخش مستقلاً guard می‌شود و خطایش درون‌صفحه‌ای نمایش داده می‌شود — صفحهٔ وضعیت هرگز Fatal نمی‌شود. تست رگرسیون: `SystemAdminPagesTest::testSystemPageSurvivesUnwritableBackupStore` (بازتولید دقیق همان کلاس شکست).
- **REST داشبوردها روی Permalink ساده 404 می‌خورد (کشف با گیت مرورگر):** هلپرهای JS پنج صفحهٔ مدیریتی، `path` حاوی query را مستقیم به `rest_url()` می‌چسباندند؛ با Permalink ساده (`index.php?rest_route=…`) کوئری دوم با `?` اضافه می‌شد و route قفل نمی‌شد (`rest_no_route`: polling صف، اعلان‌ها، خلاصهٔ مالی، جستجوی بیمار و…). فیکس مرکزی: `apiUrl()` در هر پنج صفحه — وقتی `rest_url` خود `?` دارد، کوئری path با `&` ضمیمه می‌شود.

### Docs/CI
- Workflow جدید `real-wp-acceptance.yml` + `bin/rwp-acceptance.py`: نصب واقعی از ZIP رسمی، دو prefix (`wp_`/`clinic_`)، مرورگر واقعی (System/Health/Technical/منوهای Doctor/Secretary/Administrator فنی P-3)، هم‌سان‌سازی شمارش جداول UI↔DB، پروب Migration خراب (fail-loud + عدم ثبت)، بررسی لاگ PHP/WP/Apache/مرورگر، اسکرین‌شات/لاگ به‌عنوان Artifact، و انتشار Evidence به‌صورت Commit Comment.

### Verified (گزارش بازتولید قبل از فیکس)
- D1: STILL_PRESENT/PARTIAL → `wp-die-message` (critical error) روی هر دو prefix.
- D2: STILL_PRESENT → `UI=0 / DB=41` روی هر دو prefix.
- D3: FIXED (F1-2) → پروب migration خراب: `RuntimeException` + عدم ثبت version (تأیید مجدد روی main).

## [1.0.1] — 2026-09-07 (Remediation Part 1 — ممیزی مستقل 6e42519)

### Added
- **مدیریت دسترسی نقش‌ها (ADR-0030):** صفحه «CPMS (دسترسی‌ها)» در ابزارها — ماتریس Role × Capability فارسی با گروه‌بندی و نشانگر Cap حساس (P-11)؛ ذخیره از مسیر Override عمدی با Audit `ROLE_PERMISSION_CHANGED`/`ROLE_PERMISSION_RESET`؛ دکمه «بازگشت به پیش‌فرض». Self-healing به Override احترام می‌گذارد (Least Privilege برای خارج از فهرست برقرار است).
- **Console بیمار «نوبت‌های من»:** مقصد فارسی بیمار بعد از OTP (نوبت‌های پیش‌رو + تاریخچه با جلالی)؛ فقط داده خودش (Ownership)؛ login_redirect + مخفی‌شدن Admin Bar + هدایت GETهای wp-admin — فقط برای «بیمار خالص».
- **مدیریت پزشکان و برنامه هفتگی (ADR-0031):** صفحه «پزشکان و برنامه» در ابزارها — ثبت/ویرایش/غیرفعال‌سازی پزشک + پیوند ۱:۱ به کاربر وردپرس + ویرایش ۷ روز برنامه هفتگی + استثناهای تعطیلی/مرخصی/بستن (همه از مسیر ScheduleService با Audit + بازتولید Slot). راه‌اندازی مطب دیگر به دست‌کاری DB نیاز ندارد.
- **چاپ نسخه (ADR-0031):** دکمه «🖨️ چاپ» کنار هر نسخه در داشبورد پزشک — نمای چاپی فارسی/RTL (سربرگ مطب، بیمار/MRN/سن، تاریخ جلالی، شکایت اصلی، جدول اقلام، جای امضا) با واترمارک «پیش‌نویس/ابطال‌شده»؛ مجوز `cpms_rx_read` + مالکیت ویزیت + Audit `PRESCRIPTION_PRINTED`.
- تست‌های Integration جدید: `DoctorQueueScopeTest` (۳)، `RoleCapabilitiesOverrideTest` (۵)، `PatientPortalTest` (۳)، `ClinicianRepositoryTest` (۳)، `PrescriptionPrintTest` (۵).

### Fixed
- **باگ واقعی پنهان در `voidPrescription` (کشف توسط تست جدید Part 2):** متغیر `$reason` به closure تراکنش منتقل نمی‌شد — هر «ابطال نسخه» در تولید با خطا شکست می‌خورد (هیچ تست قبلی این مسیر را پوشش نمی‌داد). اکنون رفع + تست ابطال/چاپ.

### Security
- **Scope صف برای پزشک (ممیزی P8):** پزشکِ متصل در `/queue`، `rt/queue` و آمار داشبورد فقط ویزیت‌های خودش را می‌بیند (صف + فید Real-time + ETag + آمار)؛ پزشک بدون اتصال = هیچ؛ منشی بدون تغییر. مطابق Master Context §8 — «بدون دید ضمنی داده پزشک دیگر».

### Changed
- منوی «CPMS (فنی)» به «CPMS (فنی و لاگ)» تغییر نام یافت (تمایز از «CPMS (سیستم)» — ممیزی P10).

### Docs
- ADR-0030، permission-matrix v1.5، user-guide (بخش دسترسی‌ها + بیمار)، گزارش `report-remediation-part1.md`، لاگ agent-guide.

## [1.0.0] — 2026-09-06 (Release Candidate — Pilot/Staging Gate)

### نسخه ۱.۰ — فازهای F1 تا F9 + Pilot/Staging Readiness Gate

**هسته و معماری (F1)**
- معماری لایه‌ای (Domain/Application/Infrastructure/Rest/Bootstrap)، Migration framework ایمن و idempotent (schema تا `2026_09_07_0007`)، نقش‌ها و Capabilityها (`cpms_patient/secretary/doctor` + Administrator فنی P-3)، Settings (sealed)، Audit با Hash Chain، JobQueue (Single Worker + Row Lock — ADR-0016)، CI (Unit PHP 8.1–8.4 + Integration WP 6.7.2/MySQL 8).

**احراز هویت و پیامک (F2/F2.5)**
- OTP + Session بیمار، Patient Links، Provider-Agnostic SMS (ADR-0025: Log/Generic-API)، مسیر تست امن provider=log.

**نوبت‌دهی (F3)**
- Schedule/Slot (تقویم شمسی)، Booking API (hold→confirm با Idempotency)، Availability عمومی، REST envelope استاندارد + کدهای خطای `CLINIC_*` (ADR-0019).

**مراجعه و صف (F4)**
- Check-in/Walk-in، Queue State Machine، Real-time داشبورد منشی.

**بالینی (F5)**
- Notes/نسخه/توصیه/پیگیری، فایلهای پزشکی (storage خارج webroot + `.htaccess` deny)، جستجو، داشبورد پزشک، دست‌خط (F7: Canvas/Offline-Sync/Conflict).

**مالی (F6)**
- Invoice/Payment/Adjustment/Void/Refund/Receipt + تعرفه‌ها + داشبورد مالی منشی (ADR-0026: نقش‌های پویا آماده Scope).

**اعلان و گزارش (F8)**
- Notification Layer + Templates شمسی، ۱۲ گزارش + Export async (Watermark/Audit).

**Hardening (F9)**
- Security Review کامل T-01..T-24 (رفع root cause سه حفره واقعی)، Idempotency Replay/In-flight، گارد مالکیت ویزیت، UNIQUE wp_user_id با Preflight، پاک‌سازی Jobهای مرده، TP-16 (ارتقا/Restore)، Accessibility.

**Pilot/Staging Readiness Gate (2026-09-06)**
- ابزار Gate: `bin/build-release.sh` (artifact تمیز whitelist-only)، `bin/pilot-{seed,smoke,responsive}`، workflow `.github/workflows/pilot-gate.yml` (fresh install → upgrade → smokes → benchmark → security → audit → backup → restore drill).
- گزارش کامل: `docs/phase-reports/report-pilot-gate.md`.

### Fixed
- **Background Jobs (باگ واقعی تولید — FR-5.5/J-4):** `bin/cpms jobs tick` (entrypoint مستند system-cron) جاب‌های دوره‌ای را دوباره زمان‌بندی نمی‌کرد → در استقرار system-cron، recurringها (no-show/یادآوریها/dispatch/cleanupها) بعد از اولین اجرا برای همیشه می‌ایستادند. ریشه‌یابی: واگرایی مسیر WP-Cron و CLI. فیکس: مسیر واحد `App::runTick()` (heartbeat + re-schedule idempotent + tick) + regression test `JobQueueTest::testRunTickReschedulesAndProcessesRecurringJobs` + همگام‌سازی docs (background-jobs.md J-4، ADR-0016).

### Security
- هیچ تغییر امنیتی جدید نسبت به F9؛ Gate حملات/پروبهای فایل محافظت‌شده را روی Apache واقعی verify کرد (403 از `.htaccess` افزونه).

## [Unreleased]
- Backlog V1.5: OCR، 2FA، Merge UI، ClamAV/Encryption (R-06).
- مشاهده فرعی Gate: قفل رقابتی `_transient_cpms_migrate_lock` زیر بار c=100 چند notice deadlock گذرا تولید می‌کند (بدون اثر عملکردی) → بهبود در Backlog.
