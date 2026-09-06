# گزارش فاز F9 — Hardening (Security Review + Performance + Backup/Restore + Accessibility + Pilot Docs)

**فاز:** F9 — Hardening | **تاریخ:** 2026-09-06 | **ایجنت:** Arena (`arena/01a071c4-doctor`)
**مراجع:** threat-model.md (T-01..T-24 + §4 مصوبات)، SRS §4.2 (NFR-PERF-1) و §4.5 (NFR-UI-3)، testing-plan (TP-16)، backup-recovery.md، ADR-0027 (Minor #3/#12)، multi-doctor-readiness-review.md، api-contract §0، performance-baseline.md

---

## 1. خلاصه

F9 با رویکرد «Hardening واقعی نه Refactor سلیقه‌ای» اجرا شد: هر قلم فقط وقتی تغییر کرد که (الف) مورد Scope صریح فاز بود، یا (ب) باگ واقعی/ثبت‌شده با root cause مشخص بود. خروجی‌های اصلی:

1. **Security Review T-01..T-24 کامل** (§2) — ماتریس تک‌تک تهدیدها با وضعیت، کنترل و Evidence تستی؛ **سه حفره واقعی در همین بازرسی بسته شد:** Idempotency که Replay/In-flight آن برای Endpointهای بدون context عملاً از کار افتاده بود (T-12/T-18)، گارد مالکیت ویزیت پزشک که وجود نداشت (T-01-class برای صف چندپزشکی — ADR-0027 #3)، و UNIQUE نبودن پیوند clinician↔wp_user (ADR-0027 #12).
2. **Availability:** سه Job پاک‌سازی (`cleanup.otp`، `cleanup.rate_limits`، `cleanup.idem`) که در آپلود اولیه فقط register شده بودند و **هرگز زمان‌بندی نشده بودند** + دو باگ عملیاتی کشف‌واصلاح‌شده در همان مسیر (TypeError برگشتی `query():bool` از امضای `:int`؛ `require_once` در MigrationRunner که rollback→re-migrate در همان process را fatal می‌کرد).
3. **Performance NFR-PERF-1:** پوشش ایندکس همه Hot-pathها بازرسی و تأیید شد (§3)؛ اجرای Benchmark ران‌تایم (k6 @ 1/10/50/100) طبق متدولوژی performance-baseline.md به محیط مرجع Pilot سپرده شد (نیازمند سرور 4vCPU/8GB واقعی است — در CI قابل اجرا نیست؛ صادقانه تفکیک شد).
4. **TP-16 Backup/Restore:** سناریوی بحرانی «Restore در محیطی با Migration bookkeeping ناقص + داده Legacy» به‌صورت Integration Test واقعی پوشش داده شد (§4)؛ Runbook کامل + Drill محیط مجزا = چک‌لیست Pilot (backup-recovery.md).
5. **Accessibility Pass (NFR-UI-3):** ممیزی واقعی UI (Keyboard/Contrast/Touch Target/خطاها + XSS) با سه اصلاح کد (§5).
6. **مستندات کاربری Pilot:** `docs/user-guide.md` (فارسی — نصب/نقش‌ها/Ping clinician↔wp_user_id/Cron/نگهداری) — §6.

**CI نهایی:** ۵/۵ سبز (Unit PHP 8.1–8.4 + Integration WP 6.7/MySQL 8) — **۲۵۴ تست Integration** (۹ تست جدید SecurityHardeningTest + ۵ تست جدید MigrationTest) — run `34019234033` (کد) + run نهایی docs.

## 2. Security Review — ماتریس T-01..T-24

وضعیت‌ها: ✅ = کنترل پیاده + تست‌شده؛ 📋 = فرایند/عملیاتی (خارج کد)؛ 🔜 = فاز بعدی طبق Roadmap (بدون Scope Creep).

| ID | تهدید | وضعیت | Evidence / اقدام F9 |
|---|---|---|---|
| T-01 | IDOR داده بیمار/بیمار دیگر | ✅ | Ownership + Data-Access سرور-side؛ 404-as-403 + `FORBIDDEN_ACCESS_ATTEMPT` (PatientFlowTest/ClinicalFlowTest از F2–F5). **F9:** همان الگو برای صفحه چندپزشکی — گارد مالکیت ویزیت (§2.1) + تست REST IDOR |
| T-02 | Double-Booking هم‌زمان | ✅ | Claim اتمیک + Transaction + Hold (ADR-0004) — SlotClaimTest/BookingConcurrencyTest |
| T-03 | Brute-force OTP / SMS Bombing | ✅ | 5 تلاش + Lockout + 3/روز + 10/hr + cooldown — OtpFlowTest. **F9:** `cleanup.otp` بالاخره واقعاً زمان‌بندی شد (پاک‌سازی >24h؛ قبلاً dead handler) |
| T-04 | Secretary → Private Note | ✅ | فیلتر visibility در **کوئری** Repository نه UI — ClinicalFlowTest (TP-08) |
| T-05 | Admin فنی → PHI | ✅ | تفکیک Capability؛ پیش‌فرض خالی — PermissionMatrixTest |
| T-06 | نشت فایل از URL عمومی | ✅ | ذخیره خارج webroot + Stream مجوزیافته + نام تصادفی — FileFlowTest |
| T-07 | SQL Injection | ✅ | Prepared Statements اجباری در Query Layer (`CpmsDb::prepare` — پارامتر خارج از prepare ممکن نیست)؛ بازرسی F9: هیچ SQL رشته‌ای با interpolate داده کاربر در src/ یافت نشد |
| T-08 | XSS در Notes/نسخه‌ها | ✅ | ممیزی F9: helper `esc()` در هر سه صفحه سفارشی (Queue/Finance/Doctor) روی هر interpolation نام/یادداشت + `esc_html` سمت PHP؛ ورودی سرور kses/Enum. CSP = Hardening سرور (Pilot checklist) |
| T-09 | CSRF | ✅ | Nonce-bound-to-user روی همه Mutationها + SameSite کوکی WP — تست‌های nonce (rest_*) |
| T-10 | Session Hijacking | ✅ 📋 | فلگ‌های کوکی (Secure/HttpOnly/SameSite) از پیکربندی WP/سرور — چک‌لیست Pilot (user-guide §نصب) |
| T-11 | Audit Tampering | ✅ | Append-only + Hash Chain + Trigger — AuditTest |
| T-12 | Double Payment | ✅ | **F9 — رفع Root cause:** Idempotency برای Endpointهای بدون context (booking/confirm) عملاً از کار افتاده بود (بنیاد: `prepare` مقدار NULL را به 0 تبدیل می‌کرد و predicateهای NULL-safe هرگز match نمی‌شدند → Replay ذخیره‌شده و In-flight هرگز برنمی‌گشت؛ UNIQUE تک‌ستونه با دامنه ۴ستونه Bookkeeping هم‌خوان نبود). Migration 0006 + بازنویسی کلاس (§2.2) |
| T-13 | OCR → نشت PHI | 🔜 | V1.5 (تصویر بدون PII + Consent + DPA) — خارج V1 |
| T-14 | نشت داده در Error Log | ✅ | Envelope عمومی `CLINIC_INTERNAL_ERROR`؛ کلاس Exception فقط سمت سرور لاگ می‌شود (الگوی QueueController fallback) |
| T-15 | از دست رفتن داده دست‌خط | ✅ | IndexedDB + Autosave + Backoff + Sync State (ADR-0014) — HandwritingFlowTest |
| T-16 | نشت Local Storage تبلت | ✅ | حذف Local بعد از Sync طبق `hw.local_retain` — تست GC (T-16 mapped در F7) |
| T-17 | Upload بدافزار | ✅ | finfo MIME + Whitelist + Size + نام تصادفی — FileFlowTest (ClamAV = V1.5 طبق Threat Model) |
| T-18 | Replay Request | ✅ | **F9:** Idempotency پس از رفع root cause واقعاً Replay=پاسخ ذخیره‌شده (DONE+response_json) و In-flight=409 برمی‌گرداند (§2.2) + Hold token یک‌بار مصرف |
| T-19 | Race در Queue | ✅ | Row Lock (`FOR UPDATE`) + State Machine داخل Transaction — VisitConcurrencyTest (رگرسیون F9 سبز) |
| T-20 | Supply Chain | 📋 | composer.lock ثابت؛ بروزرسانی WP/deps = فرایند عملیاتی (NFR-SEC-11) |
| T-21 | دسترسی فیزیکی/بکاپ | 📋 | رمزنگاری بکاپ + Key خارج سرور — Runbook در backup-recovery.md؛ اجرای Drill = Pilot (§4) |
| T-22 | Mass Assignment | ✅ | Whitelist فیلدها در Repositoryها (الگوی ADR-0021) — تست‌های Validation 422 |
| T-23 | Timing Attack روی OTP | ✅ | `hash_equals` در مقایسه Hash — OtpFlowTest |
| T-24 | No-Show غلط/Complete دوباره | ✅ | State Machine + Lock (J-1) — VisitFlowTest/JobQueueTest |

### 2.1 گارد مالکیت ویزیت پزشک (ADR-0027 Minor #3 — مصوب کارفرما)

- **قاعده:** پزشکِ متصل (از `cpms_clinicians.wp_user_id`) فقط روی ویزیت‌های **خودش** Transition صف می‌دهد؛ منشی/system دامنه کلینیک را حفظ می‌کنند (V1). مسیر مالی سیستم (V11/V12 با `forceRole='system'`) عمداً مستثناست — طراحی مصوب.
- **لایه ۱ (قبل از Transaction):** در `VisitService::transition()` با `visits->find()` — رد شدن با **Audit ماندگار** `FORBIDDEN_ACCESS_ATTEMPT` (با Rollback از بین نمی‌رود) → 403 `CLINIC_PERMISSION_DENIED`.
- **لایه ۲ (defense-in-depth):** در `applyTransition` وقتی `forceRole===null`.
- **تست‌ها (۴):** پزشک B روی ویزیت پزشک A (سرویس) → 403؛ پزشک بدون Link → 403؛ همان سناریو از **REST** (IDOR) → 403 envelope؛ مالک → 200؛ مسیر system bypass → مجاز.
- **نکته:** تست‌های موجود F3–F8 که پزشک را بدون Link واقعی استفاده می‌کردند، در setUp با `UPDATE ... SET wp_user_id` اصلاح شدند (نه weaken — رفتار جدید را با واقعیت Alignment دادند).

### 2.2 Idempotency — رفع Root cause بدهی F7 §9 (T-12/T-18)

- **دو ریشه:** (۱) `wpdb->prepare` برای `%d` مقدار NULL را به 0 تبدیل می‌کند → predicateهای `NULL-safe <=>` برای `context_id IS NULL` هرگز ردیف را پیدا نمی‌کردند → **Replay ذخیره‌شده و محافظت In-flight برای Endpointهای بدون context (مثل booking/confirm) بی‌صدا از کار افتاده بود** (فقط fallback دامنه‌ای جواب می‌داد؛ `complete()` هم ردیفها را DONE نمی‌کرد). (۲) `UNIQUE(key)` با دامنه واقعی Bookkeeping `(key, endpoint, wp_user_id, context_id)` نمی‌خواند.
- **Migration `2026_09_07_0006_idempotency_scope`:** Preflight (ردیف تکراری در دامنه هدف → **Abort با پیام راهنما، بدون حذف/merge خودکار**) → نرمال‌سازی NULL→0 → `NOT NULL DEFAULT 0` → `UNIQUE(key, endpoint, wp_user_id, context_id)`؛ `down()` امن؛ Idempotent با SHOW INDEX.
- **بازنویسی `Idempotency`:** نرمال‌سازی 0 در مرز API، predicate تساوی ساده (بدون NULL-safe)، و **re-check صریح پس از برخورد UNIQUE برای Race دو درخواست هم‌کلید** (409 In-flight).
- **تست‌ها (۵):** Replay=پاسخ ذخیره‌شده واقعی (response_code/json asserts)، In-flight=409، همان کلید در دو دامنه مجاز، مسیر ارتقا از داده Legacy NULL (بازسازی سناریوی Restore)، Abort پیش‌پرواز + ریکاوری پس از پاک‌سازی دستی.

### 2.3 UNIQUE پیوند Clinician↔WP user (ADR-0027 Minor #12)

- Migration `2026_09_07_0007_clinician_user_unique`: **Preflight** (دو clinician با wp_user_id یکسان → Abort با راهنمای «تصمیم انسانی: یکی بماند، بقیه NULL»؛ بدون تغییر داده) → `UNIQUE u_clinician_user`؛ چند clinician با NULL (unlink) همچنان مجاز. `down()` تست‌شده.
- تست‌ها (۳): DB ردیف تکراری را رد می‌کند؛ NULLهای متعدد مجاز؛ Preflight abort + re-run موفق پس از رفع دستی.

### 2.4 کارهای Hardening تکمیلی (باگ‌های واقعی همین بازرسی)

| مورد | شرح | اثر عملیاتی |
|---|---|---|
| **Cleanup Jobs** | `cleanup.otp`/`cleanup.rate_limits` فقط register بودند و هرگز در `RECURRING_JOBS` نبودند (dead handler)؛ `Idempotency::cleanup` هرگز صدا زده نمی‌شد → جدول بی‌نهایت رشد می‌کرد. هر سه اکنون با priority 1 زمان‌بندی‌اند + تست زمان‌بندی/purge واقعی (ردیف ۹۱ روزه حذف، ردیف تازه می‌ماند) | رشد بی‌کران ۳ جدول (otp/rate_limits/idempotency) بسته شد |
| **TypeError مرگبار Cleanup** | `Idempotency::cleanup` و `OtpCleanupHandler` مقدار `query():bool` را از امضای `:int` برمی‌گرداندند → هر اجرای Cron = Fatal. اصلاح به `execute()` (تعداد سطر، الگوی `RateLimiter::cleanup`) | Fatal صفشده در اولین Tick واقعی |
| **MigrationRunner `require_once`** | پس از `rollbackOne()`، re-migrate در همان process روی `true` (نه آرایه Migration) می‌خورد → «Invalid migration file». فایلها فقط آرایه برمی‌گردانند → `require` | rollback+migrate در یک process (سيناريوی واقعی ops) fatal می‌شد |
| **SmsController Envelope (بدهی F2.5)** | رد مجوز با `rest_forbidden` بومی WP برمی‌گشت → اکنون Envelope استاندارد `CLINIC_PERMISSION_DENIED` (cap-check داخل Handler) | یکدستی ADR-0019 برای کل API |

## 3. Performance — NFR-PERF-1

**نیازمندی (SRS §4.2):** P95 پاسخ API عمومی (تقویم/داشبورد) < 500ms در 50 کاربر هم‌زمان (سرور مرجع 4 vCPU/8GB).

- **آنچه در F9 تأیید شد (Static/Design-level):**
  - پوشش ایندکس تک‌تک Hot-pathهای خواندنی API عمومی (بازرسی مستقیم Schema + کوئری‌ها): `idx_slots_avail` (تقویم/availability)، `idx_appt_day(clinician_id,slot_date,status)` و `idx_visit_day` (داشبورد امروز)، `idx_visit_queue` (صف زنده)، `idx_inv_status(clinic_id,status,created_at)`، `idx_pay_day(clinic_id,paid_at)` (داشبورد مالی)، `idx_fu_due(status,suggested_date)`، `idx_notif_patient`، `idx_idem_created` (cleanup). **هیچ Hot-path بدون ایندکس نیست و Index اضافه‌ای لازم نبود** (بدون Premature Optimization؛ جداول کوچک otp/rate_limits بدون ایندکس جدید باقی ماندند — تصمیم مستند).
  - الگوهای عملکردی حفظ‌شده: Polling سبک با **ETag/304** (ADR-0007)، Pagination/rowLimit در گزارش‌ها، Export غیرهمگام (§18 performance-baseline)، Lazy هیچ-scan.
- **آنچه عمداً به Pilot سپرده شد (صادقانه):** اجرای واقعی Benchmark بار (`k6`/`wrk`، سطوح 1/10/50/100، هر سطح ≥۵ دقیقه، ثبت P50/P95/P99 + Error Rate + Resource Usage طبق performance-baseline.md) — این آزمون به سرور مرجع واقعی (4vCPU/8GB + WP + MySQL با داده Pilot) وابسته است و در CI Sandbox قابل اجرا نیست. **معیار قبولی و متدولوژی مستند و آماده‌اند** و به‌عنوان اولین قلم چک‌لیست Pilot در user-guide ثبت شده‌اند.
- **رگرسیون عملکردی کد F9:** گارد مالکیت = یک SELECT ایندکس‌دار (`wp_user_id` روی clinician با UNIQUE + `id` PK) قبل از Transaction — O(log n)، بدون Full-scan؛ Migrationها فقط یک‌بار اجرا می‌شوند.

## 4. Backup/Restore — TP-16

- **در قلمرو کد (تست‌شده در CI):** حساس‌ترین سناریوی Restore — «DB بازیابی‌شده با داده Legacy و Migration bookkeeping ناقص» — به‌صورت Integration Test واقعی: `testUpgradePathFromLegacyIdempotencyState` (ردیفهای قدیمی NULL + بدون version → migrate نرمال‌سازی/ایندکس UNIQUE ایمن اعمال می‌کند) + دو تست Preflight (داده معیوب پس از Restore → **Abort با پیام راهنما، نه حذف خاموش داده**). چرخه `rollbackOne()`/`migrate()` در همان process تست شد (باگ `require_once` همین‌جا کشف و ریشه‌ای رفع شد).
- **فرایند عملیاتی (مستند):** backup-recovery.md — RPO≤6h/RTO≤4h، بکاپ ۶ساعته رمزنگاری‌شده با Key خارج سرور، checksum + restore-verify، دو مقصد فیزیکی. **Drill کامل Restore در محیط مجزا** (طبق testing-plan TP-16 و جدول §4 backup-recovery: «هر فصل + بعد از Migration حساس») به چک‌لیست Pilot/عملیات سپرده شد — به زیرساخت واقعی وابسته است.
- مصوبه کارفرما رعایت شد: Migrations **بدون حذف/merge خاموش** داده موجود (Preflight + Abort)، امن و تست‌شده (up + down).

## 5. Accessibility Pass — NFR-UI-3

ممیزی واقعی UIهای سفارشی (پنج صفحه Admin) با سه محور:

| محور | ممیزی | اصلاح F9 |
|---|---|---|
| **Keyboard Nav** | کنترلها native WP (button/select/input) = کیبوردپذیر؛ Drawer با **Esc** بسته می‌شد؛ جستجوی Walk-in فوکوس خودکار داشت | **Dialog Semantics:** `role="dialog" aria-modal aria-labelledby` + **فوکوس به دکمه بستن هنگام باز شدن** + **بازگشت فوکوس به جستجو پس از بستن** (SecretaryQueuePage) |
| **Touch Target ≥44px** | صفحه دست‌خط (سطح اصلی تبلت) همه ≥44px (تب صفحات 44×44، ابزارها 40px+ قلم) | دکمه‌های داشبورد پزشک 40px → **44px** (DoctorDashboardPage) |
| **کنتراست AA** | پالت WP-Admin استاندارد (#2271b1 روی سفید ≈ 4.6:1 ✓؛ badgeها با border تیره)؛ متن سفارشی همه تیره‌روشن | — (منطبق) |
| **پیام خطای واضح** | Envelope فارسی سرور از طریق `errMessage()` در همه صفحات + Toast/Inline box | — (منطبق) |
| **XSS (T-08 هم‌پوشان)** | `esc()` روی همه interpolation نام/یادداشت در Queue/Finance/Doctor (۲۷+ مورد بازبینی‌شده) | — (منطبق) |

## 6. مستندات کاربری و Pilot

- **`docs/user-guide.md` (فارسی):** نصب و فعال‌سازی، نقش‌ها و Capabilityها، **لینک clinician↔wp_user_id (مرحله حیاتی راه‌اندازی چندپزشکی — اکنون با UNIQUE پشتیبانی DB)**، راه‌اندازی Cron/WP-Cron برای Jobهای زمان‌بندی‌شده (شامل سه Cleanup جدید)، راهنمای روزمره منشی/پزشک/بیمار، قواعد امنیتی (OTP/Nonce/گارد مالکیت)، نگهداری (بکاپ/پاک‌سازی/به‌روزرسانی).
- **Docs sync در همین فاز:** api-contract §0 (دامنه یکتایی Idempotency چه‌گانه)، CHANGELOG، roadmap (F9 ✅)، agent-guide، error-codes (بدون کد جدید — `CLINIC_PERMISSION_DENIED` موجود).

## 7. معماری و کد

| قطعه | مسیر | نقش |
|---|---|---|
| Migration 0006 | `src/Migrations/2026_09_07_0006_idempotency_scope.php` | Preflight → نرمال‌سازی NULL→0 → `NOT NULL DEFAULT 0` → `UNIQUE(key,endpoint,wp_user_id,context_id)`؛ down امن |
| Migration 0007 | `src/Migrations/2026_09_07_0007_clinician_user_unique.php` | Preflight تکراری → `UNIQUE u_clinician_user`؛ NULL تکراری مجاز؛ down امن |
| Idempotency | `src/Infrastructure/Security/Idempotency.php` | نرمال‌سازی مرز API + Replay واقعی/In-flight 409/Re-check رِیس + `execute()` در cleanup |
| VisitService | `src/Application/Visits/VisitService.php` | گارد مالکیت دولایه (`guardDoctorTransitionOwnership` + auditAndThrow قبل از Transaction) |
| Jobs | `src/Application/Jobs/IdemCleanupHandler.php` (جدید) + App.php | سه Cleanup در `RECURRING_JOBS` (priority 1) |
| MigrationRunner | `src/Migrations/MigrationRunner.php` | `require` به‌جای `require_once` (re-migrate پس از rollback) |
| REST | `src/Rest/SmsController.php` | Envelope استاندارد مجوز (رفع بدهی F2.5) |
| UI | `src/Admin/{SecretaryQueuePage,DoctorDashboardPage}.php` | a11y: dialog semantics + فوکوس‌مدیریتی + 44px |
| تست | `tests/Integration/SecurityHardeningTest.php` (۹) + MigrationTest (+۵) | ماتریس §2/§2.2/§2.3 + زمان‌بندی/purge Cleanup + مسیر ارتقا |

## 8. رگرسیون و CI

- **Integration: ۲۵۴ تست** (از ۲۴۰ در F8) — **۹ تست جدید** SecurityHardeningTest + **۵ تست جدید** MigrationTest (Scope UNIQUE، Clinician UNIQUE، مسیر ارتقا، دو Preflight) + اصلاح Alignment در setUps چهار فایل تست (Link پزشک↔clinician — نتیجه قاعده جدید، نه weaken).
- **Unit PHP 8.1/8.2/8.3/8.4: سبز.** Run نهایی: `34019234033` (۵/۵) + run تأیید docs روی HEAD نهایی.
- سه راند رفع‌ اشکال CI در طول فاز، هرکدام با root cause واحد (نه patch تست): `require_once` → catch بدون بک‌اسلش در namespace تست → چهار ریشه مستقل (TypeError execute، NS test، UPDATE تک‌ردیفی، setUp بدون Link).

## 9. تصمیم‌ها و انحرافات

- **بدون Scope Creep:** اقلام Minor Alignment طبق نقشه F9 فقط دو قلم مصوب (#3 گارد مالکیت، #12 UNIQUE) اجرا شدند؛ بقیه (Dynamic Roles، Specialty M:N، Department، Staff Scope، UX تک‌پزشکی و…) در V1.5/V2/F10 ماندند.
- **Benchmark بار و Drill بکاپِ محیط واقعی** صادقانه به Pilot منتقل شد (وابستگی به زیرساخت مرجع) — با متدولوژی/معیار قبولی مستند و آماده؛ این ادعای «تست‌شده» بدون اجرا ثبت نشد.
- Deviation کوچک: `CLINIC_PERMISSION_DENIED` برای گارد مالکیت به‌جای کد جدید (همان معنا، سازگاری کلاینتها حفظ شد — الگوی error-codes موجود).

## 10. وضعیت فاز

**F9 ✅ بسته شد.** DoD V1 (طبق roadmap M3): Security Checklist تکمیل (§2)، TP-16 در قلمرو ممکن + Runbook، NFR-UI-3 Pass، مستندات کاربری، دو Alignment مصوب، رگرسیون F2–F8 سبز. **گام بعدی Go-Live V1 = استقرار Pilot طبق user-guide + اجرای Benchmark/Drill در محیط مرجع** — خارج از قلمرو این فاز و طبق دستور کارفرما: **تا تأیید صریح، ورود به F10 انجام نمی‌شود.**
