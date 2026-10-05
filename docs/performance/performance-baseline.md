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

## 7. وضعیت اجرای هارنس (ثبت 2026-10-05 — Phase 17 Slice 0؛ فقط ثبتِ «چه چیزی عملاً اندازه گرفته می‌شود»)

> این بخش **اهداف §1 و متدولوژی §3 و قواعد Quality Gate §5 را تغییر نمی‌دهد و جایگزین آن‌ها نمی‌شود**؛
> فقط صادقانه ثبت می‌کند هارنس خودکار موجود **چه چیزی را واقعاً انجام می‌دهد و چه چیزی را انجام نمی‌دهد**،
> تا هیچ خواننده‌ای از «سبز بودن گیت» نتیجهٔ NFR استخراج نکند. مرجع تصمیم مالک برای داوری نهایی همچنان
> بنچمارک **سرور مرجع** است (`docs/phase-reports/report-pilot-gate.md` §12.3 — BLOCKED_BY_ENVIRONMENT).

**گام اجرای فعلی:** `staging-gate` ← step «Performance benchmark — ab (cold + warm, c=1/10/50/100) → JSON/MD artifact»
در `.github/workflows/pilot-gate.yml`؛ منطقی که `ab` را تحلیل و خروجی را تولید می‌کند:
`clinic-practice-management/bin/pilot-bench-report.py` (استاندارد stdlib، با self-test قطعی `--test` که در گام
«Lint gate tools» روی **هر** ران اجرا می‌شود، نه فقط ران‌های بنچمارک).

| بند §3 (متدولوژی الزامی) | آنچه هارنس فعلی واقعاً انجام می‌دهد | شکاف بازمانده |
|---|---|---|
| ۱. محیط | PHP/Apache/MySQL/WP نسخه‌ها و `runner` + `run_id`/`run_attempt`/`head_sha` در `run` هم‌JSON ثبت می‌شوند | شناسهٔ دقیق CPU/RAM/Disk ابر ثبت نمی‌شود (runner اشتراکی GitHub Actions است، نه محیط تجاری) |
| ۲. حجم داده | dataset از `pilot-seed` (خروجی JSON `/tmp/seed.json`) در artifact درج می‌شود؛ شمارش واقعی رکوردها | **تک‌کلینیکی**: `bin/pilot-seed.php` صراحتاً «exactly one clinic» را الزام می‌کند؛ بار معنادار چندکلینیکی اندازه گرفته **نمی‌شود** |
| ۳. وضعیت Cache | `cold` = نخستین Request‌ها پس از restart فرآیندهای Apache/mod_php (opcode cache خالی، بدون هیچ warm-up؛ انتظار readiness فقط در سطح TCP). `warm` = همان اندازه‌گیری‌ها روی stack سرویس‌شده با ۲ Request گرم‌کننده (رفتار پیشین) | cold **در سطح زیرساخت نیست**: InnoDB buffer pool و OS page cache پاک نمی‌شوند و هارنس ادعای آن را نمی‌کند. warm-up پیشین ۲ Request بود (نه ۱۰۰ Requestِ §3) و عمداً تغییر نکرد تا اندازه‌گیری‌های تاریخی قابل‌مقایسه بمانند |
| ۴. هم‌زمانی | سطوح **1 / 10 / 50 / 100**؛ `c=1` افزوده شد و `c=10/50/100` عیناً حفظ شدند (همان URL، همان `-n`) | ابزار `ab` است نه `k6`/`wrk`؛ طول هر سطح با **تعداد Request** کنترل می‌شود (`n=200/1500/3000`) نه «≥۵ دقیقه در سطح»؛ `-n 200` در `c=1` یعنی درصد p95 با رزولوشن خام |
| ۵. خروجی | P50/P95/P99 + RPS + failed + Non-2xx → artifact `pilot-benchmark-<run_id>` (JSON ماشین‌خوان + MD انسانی + TXT هم‌شکلِ `bench.txt` پیشین + manifest + raw dumps) با `retention-days: 14`؛ **به‌علاوه (Slice 0.6)** یک projectionِ **allowlisted** (`cpms.pilot-bench-evidence/1` — فقط seq / phase cold\|warm / label ثابتِ endpoint / concurrency / تعداد Request / p50-p95-p99 / req-s / failed / non-2xx + bindingِ run) با همان الگویِ موجودِ evidence-comment به PR یا commit-comment post می‌شود تا از **GitHub REST** (بدونِ artifact access) خوانده شود؛ ساختش از گزارشِ ساختاریافتهٔ **پس از اسکنِ حریمِ خصوصی** است و هر ساختارِ نامنتظرِ آن fail-closed رد می‌شود | اعداد per-run **عمداً در تاریخچهٔ Git commit نمی‌شوند** (comment تاریخچهٔ Git نیست)؛ پس مسیر `reports/benchmarks/<date>-<env>.md` در این مخزن پر نمی‌شود و Artifact پس از انقضا در دسترس نیست → ثبت دستی خلاصه در گزارش فاز، در زمان بستن فاز |
| §5 Quality Gate | **هیچ** آستانهٔ latency در CI اعمال نمی‌شود؛ فقط شکست‌های صحت (خروجی غیرقابل‌تحلیل `ab` / Non-2xx) گام را قرمز می‌کنند | «شکست p95 < 300ms = بلوک Quality Gate» در محیط سرور مرجع داوری می‌شود، نه روی runner اشتراکی؛ هارنس هرگز پاس/شکست NFR-PERF اعلام نمی‌کند |

**چهار خطِ قرمزِ این هارنس (تغییرناپذیر تا بستن فاز):**
۱) اعداد runner اشتراکی **شواهد انطباق NFR نیستند** (در هیچ جهتی)؛
۲) اعداد per-run در تاریخچه commit نمی‌شوند؛
۳) جمع‌آوری اندازه‌گیری ≠ داوری Quality Gate — داوری در §12.3 گزارش Pilot باقی است؛
۴) تنها payloadِ مجازِ خروج از runner به سطحِ REST، همان projectionِ **allowlisted** است (فیلدهای صریحِ بالا + binding)؛ raw dump / header / body / متنِ آزاد / متادیتای محیط هرگز منتشر نمی‌شود و ساختارِ ناسازگار fail-closed رد می‌شود (خطِ قرمزِ ۱ و ۲ را تغییر نمی‌دهد).

