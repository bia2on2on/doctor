# ADR-0016 — اجرای Background Jobs: System Cron اصلی، WP-Cron فقط Fallback

وضعیت: Accepted | تاریخ: 2026-09-05 | تأیید کارفرما: 2026-09-05 (تصمیم F1-D1)

## Context
Jobهای حیاتی (انقضای Hold، ارسال SMS/Notification، Reminder، OCR، Cleanups) نباید به ترافیک وبسایت (WP-Cron) وابسته باشند. الزامات کارفرما: Retry + Failure Tracking، Idempotency، Locking، مشاهده/Retry شکست‌ها، Operational Log، Health Check، و امکان استفاده از WP-Cron در Dev/Staging.

## Decision
1. **Production:** System Cron (crontab سرور) هر دقیقه:
   ```
   * * * * * cd /var/www/wp && php bin/cpms jobs tick --limit=20 >> /var/log/cpms-tick.log 2>&1
   ```
   + توصیه `DISABLE_WP_CRON` در wp-config برای قطع وابستگی.
2. **WP-Cron:** فقط Fallback — افزودنی `cpms_minute` (60s) با همان Action `cpms_jobs_tick`. در Dev/Staging می‌توان System Cron را نشد و روی Fallback بود.
3. **Concurrency/Lock:** `bin/cpms jobs tick` ابتدا `GET_LOCK('cpms_jobs_tick', 0)` (MySQL named lock) را می‌گیرد؛ اگر Runner دیگری فعال است، بدون کاری خارج می‌شود. خود Claim Jobها نیز Row Lock + UPDATE شرطی دارد (لایه دوم). دو Runner هم‌زمان = صفر Duplicate اجرا.
4. **Idempotency:** هر Handler تکرارپذیر است (تست TP-13). ارسال SMS با `dedupe_key` در جدول notifications (هر رویداد = حداکثر یک اعلان فعال) + Jobهای retry فقط برای موارد `failed`.
5. **Retry/Failure:** `cpms_jobs` با `attempts/max_attempts/last_error/status` + Backoff (1s/5s/15s/60s/300s). پیش‌فرض `max_attempts = 3` (Setting `jobs.default_max_attempts`، تصمیم کارفرما). Jobهای `failed`:
   - CLI: `bin/cpms jobs list --status=failed` و `bin/cpms jobs retry <id>`
   - صفحه «CPMS (فنی)»: لیست ۵ شکست اخیر + هشدار.
   - Operational Log: `JOB_RETRY` / `JOB_FAILED_FINAL` (Alert به cpms_config holders).
6. **Health Check:** `GET /clinic/v1/health` + `bin/cpms health`:
   - `jobs.last_tick_at` (هر tick ثبت می‌کند)،
   - `jobs.stale = now - last_tick > 5 دقیقه` → تشخیص متوقف‌شدن Queue/Cron،
   - `jobs.failed_count`.
7. **Dev/Staging:** WP-Cron یا `jobs tick` دستی؛ در Staging باید حداقل tick هر دقیقه فعال باشد (CI یک تست Smoke اجرا می‌کند).

## Consequences
+ قطع وابستگی به ترافیک؛ Duplicate اجرا غیرممکن؛ شکست‌ها قابل مشاهده/Retry.
− نیاز به crontab سرور در Production (دستورالعمل نصب، F9).
− در نبود System Cron و فیلتر `DISABLE_WP_CRON` اشتباه → Fallback WP-Cron فقط در صورت ترافیک اجرا می‌شود (Health `stale=true` هشدار می‌دهد).

## Alternatives
- Queue خارجی (Redis/SQS/RabbitMQ): برای حجم V1 زودهنگام (Overengineering)؛ Interface `JobQueue` آماده تعویض است (V2).
- فقط WP-Cron: مردود — وابستگی به ترافیک (نقض تصمیم کارفرما).
