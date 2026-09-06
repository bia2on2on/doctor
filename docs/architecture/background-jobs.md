# معماری Background Jobs — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7

## 1. اصول
- **J-0** همه Jobهای آهسته/تکراری از Request خارج‌اند (NFR-PERF-5).
- **J-1** Queue متمرکز: `cpms_jobs` (docs/erd) + Dispatcher — `JobQueue` Interface (تعویض‌پذیر).
- **J-2** **Idempotency اجباری:** هر Job Handler باید تکرارپذیر باشد (Lock + Status Check).
- **J-3** Single Worker V1: یک Runner با Row Lock (`locked_by + lock_expires_at`) → بدون Duplicate اجرا؛ V2: Worker خارجی (CLI daemon) بدون تغییر Handler.
- **J-4** Tick: Cron OS-level هر دقیقه `wp cron` / `bin/jobs tick` (نه فقط WP-Cron که وابسته به ترافیک است).
  - هر دو مسیر Runner (اکشن WP-Cron `cpms_jobs_tick` و `bin/cpms jobs tick`) از `App::runTick()` واحد عبور می‌کنند: ثبت Heartbeat + **زمان‌بندی مجدد Idempotent جاب‌های دوره‌ای** (`scheduleRecurringJobs`) + پردازش صف. بدون این، در استقرار system-cron جاب‌های دوره‌ای فقط یک‌بار (بعد از Activate) اجرا می‌شدند (FR-5.5 — Regression Pilot Gate 2026-09-06).
- **J-5** Retry: `attempts < max_attempts` → `run_after += backoff(attempts)` (1m/5m/15m/1h)؛ شکست نهایی → `failed` + Operational Log + Alert (Internal Notification به مدیر فنی).

## 2. فهرست Jobها

| Type | فرکانس | مسئولیت | Idempotency |
|---|---|---|---|
| `slots.generate` | روزانه (02:00) + Lazy | تولید Slotهای 30 روز آینده (K-2 Unique) | INSERT ... ON DUPLICATE / Check exists |
| `holds.expire` | هر دقیقه | آزادسازی Hold منقضی (`status, expires_at` idx) | UPDATE شرطی status |
| `appt.expire_pending` | هر 5 دقیقه | `pending` منقضی → `cancelled_by_staff` (T4) | Transition check |
| `no_show.detect` | هر 5 دقیقه | نوبت‌های `confirmed` + Grace گذشته بدون Visit → `no_show` (T8) + اعلان | Transition check |
| `appt.reminder` | روزانه 21:00/08:00 | یادآوری نوبت‌های فردا/امروز | `dedupe_key` |
| `notif.dispatch` | هر دقیقه | ارسال اعلان‌های `queued` + Retry `failed` | Status check + Provider ref |
| `fu.reminder` | روزانه 09:00 | Follow-Up سررسید | `reminder_sent_at` |
| `ocr.recognize` | رویدادی | تشخیص دست‌خط (V1.5) | Job row lock |
| `handwriting.gc` | روزانه | پاک‌سازی Versionهای دست‌خط (سیاست نگهداری) | Delete with condition |
| `cleanup.otp` | روزانه | حذف `otp_tokens` >24h | — |
| `cleanup.idempotency` | روزانه | حذف کلید >90 روز | — |
| `cleanup.holds` | روزانه | حذف رکوردهای hold >7 روز | — |
| `audit.chain_verify` | روزانه | صحت‌سنجی Hash Chain Audit (آخرین 10k + نمونه) | Report only |
| `report.export` | رویدادی | Exportهای سنگین (CSV/PDF) → فایل + اعلان | Job row |
| `backup.trigger` | روزانه | اجرای برنامه بکاپ (docs/backup) | Lock + last_success |
| `temp.cleanup` | روزانه | فایل‌های موقت/Preview مهلت‌گذشته | — |

## 3. Alert و پایش
- هر `failed` (نهایی) → `cpms_operational_logs(level=error)` + Internal Notification به `cpms_config` holders.
- Metric ساده (تعداد queued/failed در صفحه Admin فنی) — V1؛ Export Metrics (V2).

## 4. Test
- هر Handler: Unit Test (Idempotency: دو بار اجرا = یک اثر) + Integration (Queue→Worker).
- TP-13: Job `holds.expire` → Slot آزاد + Hold status=expired (تکرار = بدون اثر).
