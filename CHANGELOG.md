# Changelog — CPMS (Clinic Practice Management System)

تمام تغییرات مهم پروژه در این فایل ثبت می‌شود. قالب: [Keep a Changelog](https://keepachangelog.com/)؛ نسخه‌بندی: [SemVer](https://semver.org/).

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
