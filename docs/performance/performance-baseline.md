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
- پس از ۱۳ ردیف، profiling pass مقایسه‌ای (Phase 17 comparative profiling): با `SetEnv CPMS_PROFILE 1` موقت روی همین runner، برای هر endpoint تعداد N نمونهٔ sequential با c=1 گرفته می‌شود (هر نمونه: هدر `X-CPMS-Profile` با payload allowlisted `cpms.req-profile/1` + کد ۲۰۰؛ سپس flag برداشته و Apache restore می‌شود). نمونه‌ها در همان staging privacy-scan می‌شوند و فقط aggregateهای allowlisted منتشر می‌شوند؛ نمونهٔ گمشده/نامعتبر = **PROFILING MEASUREMENT FAILURE** (fail-closed، مجزا از خطای ab/HTTP).

**Diagnostic schema / invariant contract:** `cpms.pilot-bench-diagnostics/1` در artifact داخلی و `cpms.pilot-bench-evidence/2` برای projection REST. Static fields دقیقاً شامل runner CPU count، active MPM، PHP mode و پنج مقدار capacity هستند. هر level باید یک JSON object دقیقاً با status `ok`، همان `seq`/phase/concurrency، exit-code bounded، sample count/interval/elapsed و فیلدهای CPU/memory/RSS/process-count bounded داشته باشد؛ `host_cpu_avg_pct ≤ host_cpu_max_pct`، process count ≥ 1، runner CPU count در همهٔ rows برابر static است، row count و sequence با benchmark manifest دقیقاً برابرند، و هر number finite است. Safe projection فقط این fields صریح + established benchmark rows + exact run binding را منتشر می‌کند؛ extra fields، enum/type/range violations، missing fields، tampering یا privacy sentinelها fail-closed هستند.

**Request-profiling schema / invariant contract (2026-10-06 — Phase 17 comparative profiling):** `cpms.req-profile/1` per-request header (دقیقاً ۱۸ کلید allowlisted: چهار segment زمانی boot/init/dispatch + total، شمارش و زمان DB لایهٔ CPMS، و totalهای `$wpdb` + endpoint token + schema؛ بدون SQL/PHI/path/header) که فقط با flag سمت‌سرور `CPMS_PROFILE=1` فعال می‌شود (هیچ ورودی request نمی‌تواند آن را arm کند). batch نمونه‌ها `cpms.req-profile-batch/1` است (params دقیق + TSV با cross-check endpoint و HTTP 200)؛ aggregate هر endpoint دقیقاً `samples_per_endpoint` نمونه، const بودن شمارش‌های CPMS (variance = refusal)، min/max برای totalهای `$wpdb` و mean/max برای زمان‌ها دارد. projection REST به‌صورت additive به `cpms.pilot-bench-evidence/3` ارتقا یافته (همهٔ فیلدهای /2 بدون تغییر + بلاک `profiling`)؛ extra/missing fields، enum/type/range violations، sum نامعتبر، tampering یا privacy sentinelها fail-closed هستند.

| سؤال | آنچه این هارنس اکنون اندازه می‌گیرد | محدودیت/تفسیر |
|---|---|---|
| Apache MPM و ظرفیت | active MPM و effective bounded `ServerLimit`/`ThreadLimit`/`ThreadsPerChild`/`MaxRequestWorkers`/`MaxConnectionsPerChild` + mod_php/PHP handler mode | config arbitrary/full text منتشر نمی‌شود؛ این actual staging process/config evidence است، نه reference-server configuration |
| CPU میزبان | samples aligned with each `ab` level؛ avg/max host CPU normalized to total host utilization؛ runner CPU count | runner shared است؛ CPU دیگر jobs/host noise را از application CPU به‌طور کامل جدا نمی‌کند |
| Memory میزبان | minimum `MemAvailable` و maximum used percentage در هر level | memory pressure/NUMA/IO cache و DB container memory جداگانه اندازه‌گیری نمی‌شود |
| load generator vs server | `ab` CPU/RSS جدا از aggregate Apache/PHP CPU/RSS/process count در همان samples | در این topology هر دو روی runner هستند؛ attribution کاملِ host contention ممکن نیست، اما dominance نسبیِ host/ab/server را قابل مشاهده می‌کند |
| correctness | parser قبلی همچنان failed/non-2xx و missing/unparseable را گزارش می‌کند | diagnostic collection failure جداگانه با `DIAGNOSTIC MEASUREMENT FAILURE` گزارش و evidence آن level منتشر نمی‌شود |

**Measurement-only guardrails (unchanged):** هیچ latency threshold در workflow اضافه نشده؛ safe comment هیچ response/header/body/config text/PHI/credential/cookie/nonce/path/free-form label یا unallowlisted environment metadata ندارد؛ `contents: read`, `actions: read`, `pull-requests: write` تغییری نکرده؛ اعداد per-run به Git commit نمی‌شوند. هدف‌ها و reference-server adjudication هنوز open هستند و **Phase 17 IN PROGRESS** است.

**Accepted comparison provenance:** PR #183 head `14d306dd2541003c6f33473b073afcf393785e06`, Pilot/Staging run `37377598773` (push), REST-visible 13-row evidence. Warm health c=10/50/100: p95 `283/1437/2737 ms`, RPS `45.79/46.80/46.54`; availability c=10/50/100: p95 `309/1515/3043 ms`, RPS `42.14/43.78/42.83`; WordPress root c=50: p95 `1661 ms`, RPS `39.00`; all rows failed=0 and non-2xx=0. This remains shared-runner evidence only, not an NFR verdict.

**Live bounded-diagnostic result:** PR #184 DRAFT push run `37385077743`, head `f97935caa7b587b5e11ccd3c1866d1a4eaa958a3`, REST comment `6004957834` verified through the local `--verify-evidence` parser. Actual staging was **Apache prefork + mod_php** on **4 runner CPUs**, with `ServerLimit=256`, `ThreadLimit=0`, `ThreadsPerChild=1`, `MaxRequestWorkers=150`, `MaxConnectionsPerChild=0`. At sustained warm levels, host CPU maxed at **100%**, Apache/PHP server CPU averaged **313.97–339.74%** (one-core-normalized aggregate), while `ab` averaged at most **1.44%**; peak server process count was **130**, below `MaxRequestWorkers`. Host memory did not constrain the run: minimum available was about **12.85 GB** and maximum used was **19.63%**. Therefore the single evidence-backed slice conclusion is **C: plugin/server execution appears dominant enough to justify plugin profiling next**. The reference endpoint also saturates this shared stack, so this does not attribute all CPU to CPMS; it justifies profiling as the next measurement, not optimization. No NFR/reference-server conclusion is made.


## 8. سربارِ افزونه روی صفحهٔ عمومی — ACTIVE در برابر DEACTIVATED (ثبت 2026-10-06 — Phase 17؛ فقط اندازه‌گیری)

> این بخش **اهداف §1، متدولوژی §3 و قواعد Quality Gate §5 را تغییر نمی‌دهد**؛ فقط آنچه هارنس Pilot/Staging واقعاً اندازه می‌گیرد را ثبت می‌کند. اعداد shared GitHub runner شواهد انطباق NFR نیستند؛ داوری سرور مرجع همچنان در `docs/phase-reports/report-pilot-gate.md` §12.3 با وضعیت `BLOCKED_BY_ENVIRONMENT` باقی می‌ماند.

**چرا این برش لازم بود:** برشِ profiling مقایسه‌ایِ قبلی فقط ثابت کرد «هیچ هزینهٔ پردازشِ endpointِ CPMSِ معناداری بالاتر از baselineِ مسیر وردپرس با افزونهٔ فعال در مرز اندازه‌گیری‌شده وجود ندارد». آن برش **هزینهٔ بارگذاریِ خودِ CPMS** را اندازه نگرفت، چون `RequestProfiler` داخل CPMS است و هنگام غیرفعال بودنِ افزونه نمی‌تواند داده تولید کند. این برش دقیقاً همان را با مقایسهٔ **یک صفحهٔ عمومیِ واحد** در **همان نصبِ یک‌بار‌مصرفِ Pilot/Staging** اندازه می‌گیرد: **A) افزونه ACTIVE** در برابر **B) افزونه DEACTIVATED**.

**صفحهٔ منتخب (از یک allowlist ثابت):** صفحهٔ اصلی وردپرسِ نصبِ تمیز (`home` / برچسب ثابت `Public front page`). دلیلِ نمایندگی: محتوای عمومیِ ساختهٔ هستهٔ وردپرس است (نه صفحهٔ پورتالِ متعلق‌به CPMS)، هیچ PHI/وضعیتِ احراز‌شدهٔ بالینی لازم ندارد، با غیرفعال شدنِ CPMS از بین نمی‌رود، bootstrap عادی وردپرس را منصفانه طی می‌کند (بارگذاری افزونه ← query اصلی ← `template_redirect` ← رندر قالب ← `wp_head`/`wp_footer` که هوک‌های فرانت‌اندِ خودِ CPMS در آن‌ها اجرا می‌شوند)، و فقط با GET ناشناس و بدون نوشتن قابل تکرار است.

**مرز اندازه‌گیری (تقارنِ دو حالت):** برای حالتِ DEACTIVATED هیچ دادهٔ profiler جعل نمی‌شود. مقایسهٔ اصلی از یک زمان‌سنجیِ **بیرونی/سطح-request** استفاده می‌کند که در هر دو حالت یکسان است (`curl` با زمان‌سنجی سمت کلاینت): همان runner، همان نصب وردپرس، همان DB، همان صفحه، همان ابزار، همان سیاستِ گرم‌کردن.

**طراحی:** ۳ round به‌ترتیبِ **ACTIVE → DEACTIVATED → ACTIVE** (ACTIVE اول = سیاستِ قطعی؛ نصب فعال شروع می‌شود و گام‌های بعدی gate به حالتِ فعال نیاز دارند). هر round: ۵ درخواستِ گرم‌کننده دور انداخته می‌شود، سپس ۱۰۰ نمونهٔ متوالی با c=1. برای هر round منتشر می‌شود: `state`، تعداد نمونه، تعداد گرم‌کننده، p50/p95/p99 (nearest-rank)، میانگین و تعداد non-2xx. دو round فعال، round غیرفعال را قاب می‌گیرند تا drift زمانیِ runner اشتراکی **مشاهده‌پذیر** باشد نه فرض‌شوده. deltaِ نهایی: p95 ادغام‌شدهٔ هر دو round فعال در برابر p95ِ round غیرفعال.

**شمارش query:** اندازه‌گیری **نشده (NOT MEASURED)**. شمارشِ منصفانهٔ هر request در حالتِ غیرفعال نیازمند مشاهده‌گرِ متعلق‌به CPMS (که طبق تعریف وجود ندارد) یا query-logging سراسریِ ناامن/وابستگی جدید است؛ شواهدِ نامتقارن از یک شکافِ صادقانه بدتر است.

**محلِ منطق:** منطقِ جمع‌آوری (نمونه‌برداری، غیرفعال/فعال‌سازی، اثباتِ بازیابی) در هارنس stdlib-only `clinic-practice-management/bin/pilot-page-overhead.py` با `--test` قطعی در گامِ Lint است — نه در یک بلوکِ شلِ درونِ YAML؛ دلیلِ فنی: GitHub طول یک عبارتِ `run:` را به **۲۱٬۰۰۰ کاراکتر** محدود می‌کند و نسخهٔ درون‌خطیِ این گام آن را رد می‌کرد (workflow file از کار می‌افتاد). این همان الگویِ مستقرِ `bin/pilot-bench-report.py` و `bin/pilot-bench-diagnostics.py` است.

**ایمنیِ غیرفعال‌سازی (فقط نمونهٔ یک‌بار‌مصرفِ Pilot/Staging داخل workflow):** فقط مکانیزم بومیِ وردپرس (`wp plugin deactivate` / `wp plugin activate`)؛ بدون uninstall/حذف، بدون اجرای هوکِ مخرب، بدون تغییر جدول/تنظیمات/دادهٔ CPMS. بازیابی با `trap ... EXIT` **و** به‌صورت درون‌خطی پیش از round پایانی تضمین شده است. پس از round غیرفعال، گام ثابت می‌کند: افزونه فعال است، endpoint عمومیِ سلامتِ CPMS کد ۲۰۰ می‌دهد، رویداد `cpms_jobs_tick` زمان‌بندی است، و نسخهٔ migrationها و اثرانگشتِ تعداد جدول/ستون‌های CPMS تغییر نکرده‌اند. شکستِ هر بازیابی، گام را با صدای بلند fail می‌کند.

**Measurement-only guardrails (تغییرناپذیر):** هیچ آستانهٔ latency در workflow اضافه نشده؛ projection امن شامل هیچ URL آزاد، هدر، بدنه، مسیر فایل‌سیستم، فهرست افزونه، متن/مقدار SQL، nonce/cookie/کلید، metadata محیطی یا PHI نمی‌شود؛ ۱۳ ردیف بنچمارک و بلاک profiling بدون تغییر مانده‌اند و بلاک `page_overhead` فقط به‌صورت additive به `cpms.pilot-bench-evidence/4` افزوده شده است؛ مجوزهای workflow (`contents: read`, `actions: read`, `pull-requests: write`) تغییر نکرده‌اند.

## 9. صف پس‌زمینه — enqueue تا اولین claim (Phase 17; measurement-only, 2026-10-06)

> این بخش یک اندازه‌گیریِ تشخیصی است؛ هدف §1، روش §3، cadence صف یا معنای scheduler را تغییر نمی‌دهد. **نتیجهٔ هر اجرا فقط در REST evidence همان head/run/event و artifact همان run نگه‌داری می‌شود؛ aggregateهای per-run به Git commit نمی‌شوند.**

**نیازمندی و ابهام:** §1 همین سند برای عملیات سنگین می‌گوید Job باید **`< 5s` شروع شود** و مدت عملیات جدا گزارش شود. SRS §4.2 / `NFR-PERF-5` فقط Async بودن OCR/Export/Backup و non-blocking بودن درخواست را مقرر می‌کند؛ هیچ‌یک triggerِ موردنظر برای «شروع» را تعیین نمی‌کند. بنابراین autonomous و explicit-tick دو حالت جدا هستند؛ این slice هر دو حالت را به‌صورت **جداگانه و برچسب‌خورده** اندازه می‌گیرد: `explicit_tick` (کنترلِ همان اجرا) و `autonomous` (فقط مسیرِ Fast-Wake تولید). شاهدِ منتشرشده همان بلاکِ `job_start` است و برچسبش با فایلی که از آن آمده pin می‌شود؛ هیچ aggregateی دوباره‌برچسب نمی‌خورد. هیچ‌یک از این دو حالت به‌تنهایی انطباق با NFR/سرور مرجع را ثابت نمی‌کند.

**مرزِ زمانِ شروع (منجمد از runtime):** برای همان ردیفِ دقیق `cpms_jobs`، `created_at` ذخیره‌شده در `JobQueue::enqueue()` → `started_at` ذخیره‌شده در تراکنشِ claimِ `JobQueue::claim()`؛ claim، گذار `queued → processing` و افزایش `attempts` را پیش از اجرای Handler ثبت می‌کند. این مرز **first claim/start** است، نه پایان Handler. هر یک از ۱۰۰ sample با `max_attempts=1` enqueue می‌شود و باید `attempts=1` و terminal outcome داشته باشد؛ در نتیجه retry نمی‌تواند `started_at` را بازنویسی و به‌جای اولین start جا زده شود. وضعیت‌های `success` و `failed` هر دو start محسوب می‌شوند؛ شکست پردازش جدا از latency گزارش می‌شود، و sample بدون start قرارداد اندازه‌گیری را fail-closed می‌کند.

**روشِ Pilot/Staging:** فقط نصب disposableِ workflow و مسیر production enqueue (`App::jobs()->enqueue`) استفاده می‌شود: ۱۰۰ جاب system-scoped ثابت `backup.run` با payloadِ فاقد دادهٔ بالینی و یک correlation token تصادفیِ غیرحساس برای همان اجرا؛ پیش‌شرط می‌سنجد backup خاموش است تا Handler فایل/Provider نسازد. یک بار `bin/cpms jobs tick --limit=200` صریحاً بعد از enqueue و **پیش از** گام System Cron فراخوانی می‌شود. `App::runTick()` صفِ due سراسری را drain می‌کند و به این ۱۰۰ ردیف محدود نیست؛ فقط ۱۰۰ ردیفِ token-correlated در محاسبهٔ این slice وارد می‌شوند. گزارش‌ساز با `--job-start-mode` به مسیر trusted pin می‌شود؛ تغییر label aggregate نمی‌تواند forced tick را autonomous (یا برعکس) جا بزند. **مسیرِ autonomous (Phase 17 fast wake):** `JobQueue::enqueue()` اول ردیف را Commit می‌کند و سپس یک رویدادِ تکِ **installation-wide** `cpms_jobs_wake` (بدونِ args؛ بدونِ job/Clinic/user identity) درخواست می‌کند — WordPress core خودش رویدادِ یکسان را در بازهٔ ±۱۰ دقیقه dedupe می‌کند و مسیرِ enqueue هم تا وقتی رویدادی pending است دوباره زمان‌بندی نمی‌کند، پس انفجارِ enqueue به **یک** Wake رویداد تبدیل می‌شود. Wake-up یک hint است، نه authority: spawn در پایانِ همان درخواست (اکشن `shutdown` وردپرس) و فقط با API بومیِ وردپرس `spawn_cron()` (Loopbackِ non-blocking به `wp-cron.php` خودِ سایت با قواعد خودِ وردپرس: `DOING_CRON`، قفلِ ۶۰ ثانیه‌ای، الزامِ وجود رویدادِ due) انجام می‌شود؛ هیچ URL ورودی‌محور، هیچ payload/job id/PHI روی شبکه، و هیچ endpoint جدیدی ساخته نمی‌شود. Handlerِ Wake همان `App::runTick()` موجود را با سقفِ بستهٔ `App::WAKE_TICK_LIMIT = 200` صدا می‌زند؛ قفلِ `GET_LOCK` و معنای `claim()` تنها مرجعِ پردازش می‌مانند. **Fallback دست‌نخورده:** رویدادِ دوره‌ای `cpms_jobs_tick` با `cpms_minute` (و System Cron هر دقیقه) تغییر نمی‌کند و در هاست‌هایی که Loopback/spawn کار نمی‌کند همان cadence تنها مسیرِ شروع است — ادعای `< 5s` برای آن هاست‌ها **مطرح نمی‌شود** (وابسته به محیط). در حالتِ `autonomous` هیچ tickی (نه CLI و نه دستی) صدا زده نمی‌شود؛ شمارندهٔ `sample_count=100` جاب system-scoped `backup.run` با payloadِ فاقدِ دادهٔ بالینی enqueue می‌شود و collector فقط با یک **انتظارِ کران‌دار** (۲۰ ثانیه، بدونِ هیچ فراخوانیِ tick) شاهدِ اولین start را می‌خواند؛ اگر شروع‌ها در این کران ثبت نشوند aggregate fail-closed رد می‌شود و بلاکِ منتشرشده برچسبِ خودش (`explicit_tick`) را حفظ می‌کند. J-4 و cadence دقیقه‌ایِ فعلی، `cpms_jobs_tick`، قفل و `App::runTick()` بدون تغییر می‌مانند. Job نمونه S-scope است؛ Clinic ID انتخاب/ذخیره نمی‌شود و Clinic اول به‌عنوان authority استفاده نمی‌شود.

**زمان و تجمیع:** enqueue و claim هر دو از ساعت UTC سمت PHP همان staging host استفاده و در همان DB پایدار می‌شوند؛ `CpmsDb::nowUtcSql()` فعلاً دقتِ ثانیه‌ای را با لاحقهٔ `.000` می‌نویسد (پس اختلاف‌ها quantized به ۱۰۰۰ ms هستند، نه دقت جعلی sub-second). صد نمونه به nearest-rank `p50/p95/p99` و `max` تبدیل می‌شوند. `processing_failure_count` جداست؛ `not_started_count` برای خروجی معتبر باید صفر باشد. **۵ ثانیه آستانهٔ CI نیست**: مقدار بالاتر دادهٔ اندازه‌گیری است، نه failureِ latency.

**Privacy/evidence contract:** projection از schema `/4` به `cpms.pilot-bench-evidence/5` فقط به‌صورت additive ارتقا یافته است؛ بلاک `job_start` دقیقاً شامل `measurement_mode`, fixed `job_type`, `sample_count`, `p50_ms`, `p95_ms`, `p99_ms`, `max_ms`, `not_started_count`, `processing_failure_count` است. binding دقیق `run_id`, `run_attempt`, `event_name`, `head_sha`, `ref` از متادیتای همان run می‌آید. هیچ payload، correlation token، queue/internal ID، نام Clinic/بیمار، SQL، path، environment dump، arbitrary error، auth/cookie/nonce/secret منتشر نمی‌شود. فایل خام در scratch خصوصی disposable حذف می‌شود؛ فقط aggregate allowlist وارد report می‌شود. تساوی دقیق فیلدها، enum/type/range/monotonic checks، binding و sentinel scan fail-closed هستند.

**حفظ شواهد قبلی:** ۱۳ ردیف benchmark، bounded capacity diagnostics، comparative `profiling` و مقایسهٔ `page_overhead` در گزارش/REST دست‌نخورده و additive باقی می‌مانند. این اندازه‌گیری به‌تنهایی نه target mode را حل می‌کند، نه NFR/reference-server compliance یا آمادگی تولید را. هر عددِ autonomous فقط برای همان run/head/event معتبر است و حتی در محیطِ سالمِ Loopback یک ادعای **محیط‌وابسته** است، نه SLAِ جهانی: روی هاستی که spawn/loopback در آن کار نکند، مسیرِ دقیقه‌ای Fallback برقرار است و عددِ `< 5s` گزارش نمی‌شود.

**شاهد زندهٔ exact-head (2026-10-06؛ فقط اندازه‌گیری):** کدِ اندازه‌گیری روی head **`26e2114f94d5f617296c4f3effcdf999377ea2d8`** اجرا شد. Pilot/Staging run **`37489607493`** با نتیجهٔ `success`؛ REST comment [#6020088659](https://github.com/bia2on2on/doctor/pull/188#issuecomment-6020088659) با `--verify-evidence` تأیید شد و به همان run متصل است (`run_attempt=1`, `event_name=push`, `head_sha=26e2114f94d5f617296c4f3effcdf999377ea2d8`, `ref=arena/eba49573-doctor`). خروجیِ allowlisted: `explicit_tick`, `backup.run`, **۱۰۰** نمونه، `p50/p95/p99/max = 2000/2000/2000/2000 ms`, `not_started_count=0`, `processing_failure_count=0`. تمام نمونه‌ها first-start و terminal outcome معتبر داشتند؛ هیچ شکستِ پردازش یا never-started در این run گزارش نشد.

**دقت:** مقدارهای `created_at` و `started_at` از `CpmsDb::nowUtcSql()` در همان میزبان PHP/UTC ساخته و در DB پایدار می‌شوند؛ هر دو با `.000` ذخیره می‌شوند. بنابراین اعداد فوق فقط با **granularity یک‌ثانیه‌ای (تقریباً ±۱ ثانیه)** قابل تفسیرند، نه دقت sub-second. p95 ثبت‌شده ۲ ثانیه است؛ این فقط نتیجهٔ صریحاً فراخوانی‌شدهٔ `jobs tick` در Pilot disposable است، نه شاهدی برای شروع autonomous.

**Exact-head workflow / وضعیت RED:** روی همین head، Pilot/Staging `37489607493`, Closure `37489607296`, CI `37489614236` و Real WordPress Acceptance `37489614293` همگی `success` شدند؛ **۱۹/۱۹ check-run** کامل و موفق بود. وضعیت RED در دامنهٔ readiness باقی می‌ماند: تضمینِ `<5s` برای autonomous production scheduling اندازه‌گیری نشده و reference-server NFR همچنان `BLOCKED_BY_ENVIRONMENT` است. این blocker یک شکستِ latency در CI نیست؛ آستانهٔ latency اضافه نشده و queue/scheduler cadence تغییر نکرده است.

**تصمیم A/B/C/D — B (فقط مسیر explicit tick):** دادهٔ معتبرِ همین حالت، p95 ثبت‌شدهٔ ۲ ثانیه را نشان می‌دهد و برای این مشاهده زیر هدف متنیِ `<5s` است؛ از آن نتیجهٔ autonomous، انتخابِ mode تولید، NFR compliance یا آمادگی go-live استنتاج نمی‌شود. تصمیم فقط طبقه‌بندیِ شاهدِ صریحاً tick‌شده است؛ زمان‌بندی موجود بدون تغییر می‌ماند.
