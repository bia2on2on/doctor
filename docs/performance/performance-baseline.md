# Performance Baseline — CPMS

نسخه 1.0 | 2026-09-05 | منبع: Engineering Baseline §17/§29 + **تصمیم نهایی کارفرما F2** | مسئول پیاده‌سازی: F3+ (Middleware اندازه‌گیری) / F9 (Hardening & Benchmark)

## 1. اهداف عملکردی (Production Baseline)

| شاخص | هدف | تعریف |
|---|---|---|
| **REST API (پایه/تعاملی)** | **p95 < 300 ms** | Endpoints تعاملی و Core: رزرو/لغو نوبت، OTP verify، صف/ورود ویزیت، لیست‌خوانی داشبورد، پرداخت/فاکتور — از دریافت Request تا ارسال Response (سرور) |
| **لینک‌های عمومی (Public/داشبورد)** | **p95 اضافه‌باری (Overhead) افزونه < 100 ms** | زمان اضافه‌ای که افزونه CPMS روی بارگذاری یک صفحه عمومی/داشبورد نسبت به بدون-افزونه اضافه می‌کند |
| **عملیات سنگین** | **خارج از هدف REST** | OCR، ارسال SMS، Export، PDF، گزارش‌های سنگین، پردازش تصویر — **async/Job Queue** (Job باید < 5s شروع شود؛ مدت عملیات جداگانه Report می‌شود) |

> REST p95 به معنی **۹۵٪ Requests زیر 300ms** — نه میانگین. Latency P50/P95/P99 هر Endpoint در Benchmark ثبت می‌شود.

## 2. فهرست Endpoints «پایه/تعاملی» (Benchmark Set — F3 تکمیل می‌شود)

| Group | Endpoints |
|---|---|
| Booking | نوبت‌گیری، لغو، مشاهده نوبت‌های روز، Hold |
| Authentication | Login، OTP request/verify، refresh |
| Queue | ورود/خروج ویزیت، صف فعلی |
| Patient | Create/Update/Query بیمار |
| Billing | فاکتور، پرداخت، انصراف |

عملیات سنگین (خارج از Set): `jobs.ocr`، `jobs.sms`، `jobs.export`، `jobs.pdf`، گزارش‌ها — با `Job` + Worker (Baseline §18)، **نه** در مسیر REST.

## 3. روش Benchmark (الزامی — بدون این مشخصات، Benchmark اعتبار ندارد)

هر Benchmark باید **همه** موارد زیر را صریح ذکر کند:

1. **محیط:** نسخه PHP/MySQL/WordPress، نوع CPU/RAM/Disk (یا Instance Cloud)، OS.
2. **حجم داده (Dataset):** تعداد رکوردها به تفکیک جدول (مثلاً 10k بیمار / 100k نوبت / 100k فاکتور) — عدد Round شده + Seed ثابت.
3. **وضعیت Cache:** Cold (بعد از `FLUSH + restart`) یا Warm (بعد از ۱۰۰ Request گرم‌کننده) — هر دو Run می‌شود.
4. **حمل هم‌زمان (Concurrency):** سطح‌های 1 / 10 / 50 / 100 (Tool: `k6`/`wrk`؛ مدت ≥ ۵ دقیقه در هر سطح).
5. **خروجی:** P50/P95/P99 + Error Rate + Resource Usage (CPU/RAM DB) — در `reports/benchmarks/<date>-<env>.md`.

## 4. الزامات معماری (از F3)

- **Middleware اندازه‌گیری:** هر Request REST: `duration_ms` در Operational Log + Counter (برای Alerting) — بدون Log محتوای Body.
- **Async-First:** هر کار > ~200ms (تخمینی) یا I/O سنگین → Job (Queue Worker، ADR-0022/ADR-0025).
- **DB:** Index Review در هر Migration جدید (Queryهای Core در F3+: `EXPLAIN` برای Queryهای کلیدی در Report فاز ثبت می‌شود).
- **Cache:** Object Cache برای تکرارهای پرتکرار (تنظیمات/جداول Look-up) — با Invalidation صریح.

## 5. Quality Gate (F9)

- Benchmark Set کامل در محیط استاندارد (مستند در §3) → پست در `reports/benchmarks/`.
- **شکست هدف p95 < 300ms در Core Endpoints = بلوک Quality Gate** (تا Profiling + بهینه‌سازی یا ثبت ADR با توجیه).
- رگرسیون: هر Feature بزرگ جدید → Run سریع Benchmark Set (Warm, 10 concurrent) و مقایسه با آخرین Baseline.

## 6. نظارت در Production

- p95 REST از Logها (هر ساعت)؛ Alert اگر p95 > 300ms برای ۱۰ دقیقه پیاپی (Alerting در F9/Production Hardening).
- صفحات عمومی: Overhead افزونه با Profiler (Query Count + Time) در نمونه‌های دوره‌ای.
