# Clinic Practice Management (CPMS) — WordPress Plugin

افزونه اختصاصی مدیریت مطب. مستندات معماری: `../docs/`.

> 🤖 **ایجنت‌ها:** قبل از هر کاری [`../docs/agent-guide.md`](../docs/agent-guide.md) را بخوانید (وضعیت + قواعد + پروتکل لاگ کار ایجنت‌ها).

## ساختار (ADR-0001)
```
src/
  Domain/           ← خالص (بدون WP): State Machines, OtpPolicy, InvoiceCalc, SlotGenerator
  Migrations/       ← Migration System + 0001 (37 جدول cpms_*)
  Infrastructure/   ← CpmsDb, Audit (Hash Chain), OpLog, RateLimiter, Idempotency, JobQueue
  Application/      ← Services + Job Handlers
  Auth/             ← Roles/Capabilities
  Admin/            ← Settings (فنی)
  Rest/             ← Base REST (nonce/cap/error shape)
tests/
  Unit/             ← بدون WP (PHPUnit) — هر CI
  Integration/      ← WP Test Suite — با MySQL واقعی
bin/cpms            ← CLI: migrate | jobs tick | audit verify | slots generate
```

## نصب و کار با CLI (سرور)
```
wp plugin activate clinic-practice-management
bin/cpms migrate            # ساخت جداول (idempotent)
bin/cpms jobs tick          # اجرای Jobهای سررسید (Cron OS: هر دقیقه)
bin/cpms audit verify 10000 # صحت زنجیر هش Audit
```

## اصول سفت
- داده پزشکی فقط در `cpms_*` (نه wp_posts).
- بدون کد OTP/رمز/Token در Log.
- هر تغییر وضعیت = State Machine + History + Audit.
- بدون Endpoint جدید بدون API Contract (`docs/api/api-contract.md`).
