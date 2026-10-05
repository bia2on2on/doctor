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

## 7. وضعیت اجرای هارنس و تشخیص ظرفیت (ثبت 2026-10-05 — Phase 17؛ فقط اندازه‌گیری)

> این بخش **اهداف §1 و متدولوژی §3 و قواعد Quality Gate §5 را تغییر نمی‌دهد و جایگزین آن‌ها نمی‌شود**؛ فقط آنچه هارنس Pilot/Staging واقعاً اندازه می‌گیرد را ثبت می‌کند. اعداد shared GitHub runner شواهد انطباق NFR نیستند؛ داوری سرور مرجع همچنان در `docs/phase-reports/report-pilot-gate.md` §12.3 با وضعیت `BLOCKED_BY_ENVIRONMENT` باقی می‌ماند.

**گام‌های فعال در همان job موجود `staging-gate`:**

- `Performance benchmark — ab (cold + warm, c=1/10/50/100)` در `.github/workflows/pilot-gate.yml` همچنان همان endpointها، تعداد درخواست‌ها، warm-upها و ۱۳ ردیف established را اجرا می‌کند؛ این slice هیچ ردیفی را حذف/تغییر نمی‌دهد و فقط تشخیص را به‌صورت additive کنار هر اجرای `ab` جمع می‌کند.
- `bin/pilot-bench-diagnostics.py` با stdlib و `/proc`، بدون package جدید، `ab` را به‌عنوان child اجرا و در همان بازه نمونه‌برداری می‌کند. فایل‌های diagnostic فقط summaryهای bounded دارند: تعداد نمونه/فاصله/مدت، CPU و memory میزبان، CPU/RSS خود `ab`، و CPU/RSS/count گروه Apache/PHP. full process list، command line، environment، config text و HTTP data ذخیره نمی‌شوند.
- قبل از ردیف‌ها، `apache2ctl -M`/`-V`/`-t -D DUMP_RUN_CFG` و فقط directiveهای شناخته‌شده برای active MPM خوانده می‌شوند. خروجی ساختاریافتهٔ bounded شامل `active_mpm`، `php_execution_mode`، runner CPU count و `ServerLimit`، `ThreadLimit`، `ThreadsPerChild`، `MaxRequestWorkers`، `MaxConnectionsPerChild` است. نبود/بدشکلی هر مقدار = **DIAGNOSTIC MEASUREMENT FAILURE**؛ با خطای ab/HTTP اشتباه نمی‌شود.

**Diagnostic schema / invariant contract:** `cpms.pilot-bench-diagnostics/1` در artifact داخلی و `cpms.pilot-bench-evidence/2` برای projection REST. Static fields دقیقاً شامل runner CPU count، active MPM، PHP mode و پنج مقدار capacity هستند. هر level باید یک JSON object دقیقاً با status `ok`، همان `seq`/phase/concurrency، exit-code bounded، sample count/interval/elapsed و فیلدهای CPU/memory/RSS/process-count bounded داشته باشد؛ `host_cpu_avg_pct ≤ host_cpu_max_pct`، process count ≥ 1، runner CPU count در همهٔ rows برابر static است، row count و sequence با benchmark manifest دقیقاً برابرند، و هر number finite است. Safe projection فقط این fields صریح + established benchmark rows + exact run binding را منتشر می‌کند؛ extra fields، enum/type/range violations، missing fields، tampering یا privacy sentinelها fail-closed هستند.

| سؤال | آنچه این هارنس اکنون اندازه می‌گیرد | محدودیت/تفسیر |
|---|---|---|
| Apache MPM و ظرفیت | active MPM و effective bounded `ServerLimit`/`ThreadLimit`/`ThreadsPerChild`/`MaxRequestWorkers`/`MaxConnectionsPerChild` + mod_php/PHP handler mode | config arbitrary/full text منتشر نمی‌شود؛ این actual staging process/config evidence است، نه reference-server configuration |
| CPU میزبان | samples aligned with each `ab` level؛ avg/max host CPU normalized to total host utilization؛ runner CPU count | runner shared است؛ CPU دیگر jobs/host noise را از application CPU به‌طور کامل جدا نمی‌کند |
| Memory میزبان | minimum `MemAvailable` و maximum used percentage در هر level | memory pressure/NUMA/IO cache و DB container memory جداگانه اندازه‌گیری نمی‌شود |
| load generator vs server | `ab` CPU/RSS جدا از aggregate Apache/PHP CPU/RSS/process count در همان samples | در این topology هر دو روی runner هستند؛ attribution کاملِ host contention ممکن نیست، اما dominance نسبیِ host/ab/server را قابل مشاهده می‌کند |
| correctness | parser قبلی همچنان failed/non-2xx و missing/unparseable را گزارش می‌کند | diagnostic collection failure جداگانه با `DIAGNOSTIC MEASUREMENT FAILURE` گزارش و evidence آن level منتشر نمی‌شود |

**Measurement-only guardrails (unchanged):** هیچ latency threshold در workflow اضافه نشده؛ safe comment هیچ response/header/body/config text/PHI/credential/cookie/nonce/path/free-form label یا unallowlisted environment metadata ندارد؛ `contents: read`, `actions: read`, `pull-requests: write` تغییری نکرده؛ اعداد per-run به Git commit نمی‌شوند. هدف‌ها و reference-server adjudication هنوز open هستند و **Phase 17 IN PROGRESS** است.

**Accepted comparison provenance:** PR #183 head `14d306dd2541003c6f33473b073afcf393785e06`, Pilot/Staging run `37377598773` (push), REST-visible 13-row evidence. Warm health c=10/50/100: p95 `283/1437/2737 ms`, RPS `45.79/46.80/46.54`; availability c=10/50/100: p95 `309/1515/3043 ms`, RPS `42.14/43.78/42.83`; WordPress root c=50: p95 `1661 ms`, RPS `39.00`; all rows failed=0 and non-2xx=0. This remains shared-runner evidence only, not an NFR verdict.

