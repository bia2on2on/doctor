# CPMS — گزارش نهایی اعتبارسنجی تولید / GO-LIVE GATE (پس از F10)

تاریخ: 2026-09-06 | شاخه: `arena/01a076ad-doctor` | HEAD نهاییِ اعتبارسنجی‌شده: `2c77509` | PR: **#3 (OPEN، بدون merge به main)**
مرجع: «FINAL PRODUCTION VALIDATION & GO-LIVE GATE» (کارفرما) + F10 spec §1–§52 + ADR-0023/0028/0029

> روش: هیچ نتیجه‌ای بدون شواهد PASS ثبت نشده؛ موارد بدون شواهد به‌صراحت
> `NOT_TESTED`/`BLOCKED_BY_ENVIRONMENT` هستند و هرگز به PASS تبدیل نشده‌اند.
> داده‌های پزشکی/شخصی استفاده‌شده همگی **Synthetic** بوده‌اند.

---

## 0. جمع‌بندی اجرایی

در این فاز، علاوه بر بازبینی مستقل baseline، موارد زیر انجام شد:

1. **ریشهٔ شکست‌های Pilot/Staging Gate پیدا و رفع شد** (یک defect در خود
   workflow: انتظار سخت‌کدِ schema قدیمی `0007` در حالی که ریلیز F10 به `0008`
   مهاجرت می‌کند — نه مشکل Apache/محیط؛ تصویر «Apache dead» در دیباگ
   گمراه‌کننده بود چون jobها جلوتر از گام Apache متوقف می‌شدند).
2. **Pilot/Staging Gate پس از اصلاح، دو بار پیاپی سبز شد (۴/۴ job)** —
   شامل نصب واقعی از آرتیفکت ZIP، دادهٔ Synthetic (۴۰۰ بیمار)، ۱۰ نوع Job،
   Smokeهای S1–S9، بنچمارک ab، ممیزی امنیتی، بکاپ و **Restore Drill کامل در
   محیط ایزوله**، مسیر ارتقا main/F8 → RC/F10، و Responsive با Chromium واقعی.
3. **تست جدید «بدون-PHI-به-Vendor + مسدودسازی SSRF پیش از HTTP»** روی مسیر
   واقعی خروجی (رهگیری WP HTTP) اضافه و در CI سبز شد.
4. بررسی آرتیفکت ریلیز: ساخته‌شده از HEAD نهایی، policy پاک، SHA ثبت شد.
5. هیچ defect سطح CRITICAL/HIGH یا نقض یکپارچگی دادهٔ مسدودکنندهٔ ریلیز در
   محصول یافت نشد؛ شکاف‌های شواهدی که نیازمند زیرساخت خارجی/محیط واقعی‌اند
   مستند و به‌عنوان پیش‌نیاز go-live فهرست شدند.

---

## 1. بازبینی Baseline (Phase 1)

| مورد | وضعیت | شواهد |
|---|---|---|
| HEAD | `2c77509` (پس از ۳ commit اعتبارسنجی این فاز) | `git rev-parse HEAD` |
| شاخه | `arena/01a076ad-doctor` (هرگز main) | `git status --branch` |
| working tree | تمیز (فقط `dist/` نادیده‌گرفته‌شده) | `git status --short` |
| PR | #3 OPEN (base=main؛ بدون merge) | `gh pr view 3` |
| CI | ✅ سبز ۵/۵ روی `2c77509` — run `34038885481` (Unit 8.1–8.4 + Integration WP6.7.2/MySQL8: **۲۹۶ تست / ۱٬۴۱۷ اِسert / ۰ شکست**) | `gh run view` |
| Pilot/Staging Gate | ✅ سبز ۴/۴ روی `2c77509` — run `34038883633` و روی `66e371b` — run `34038136150` | `gh run view` |
| Schema version | `2026_09_07_0008` (۸ مهاجرت؛ همه در ZIP) | gate check + MigrationTest |
| Plugin version | `1.0.0` (CPMS_VERSION) | هدر افزونه |
| سازگاری اعلامی | README: PHP 8.1+ / MySQL 8 / WP 6.7+ — هدر افزونه «Requires at least: 6.4» | — |
| پیش از این فاز | HEAD `6df3ca6` با CI سبز ولی **Pilot Gate قرمز (مشکوک به محیط)** | — |

---

## 2. آرتیفکت ریلیز (Phase 5)

- فرایند رسمی: `bash bin/build-release.sh` (همان گام `Release Artifact` گیت).
- **ZIP**: `clinic-practice-management/dist/clinic-practice-management-1.0.0.zip`
- **SHA256 (ساخت محلی روی HEAD `2c77509`)**: `abfd44641f65c64f64544af38dc05b4ca2be64cf948ed6ad83f4103561331880`
  (ساخت قبلی روی `66e371b`: `1378a5c5…89853` — تفاوت فقط timestamp زِیپ است؛ محتوا یکسان)
- **تعداد فایل**: ۱۵۵ (manifest)
- **بازرسی محتوا**: ✅ بدون `.git/.env/tests/phpunit/composer/vendor/*.log/pilot-*`؛ بدون کلید/رمز
  (اسکن الگوهای رسمی گیت: CLEAN)؛ شامل `clinic-practice-management.php`, `uninstall.php`, `README.md`,
  `src/**`, `bin/cpms` و هر ۸ مهاجرت (`0001…0008`).
- **هم‌ارزی با HEAD**: آرتیفکت از checkout همان HEAD ساخته می‌شود؛ گیت `Release Artifact` روی هر
  push دوباره می‌سازد و policy-scan می‌کند (در هر دو run سبز). SHA آرتیفکتِ ساخته‌شده در CI به‌دلیل
  محدودیت شبکهٔ sandbox (قطع stream به Azure blob) از داخل این محیط قابل‌خواندن نبود —
  مسیر دسترسی: GitHub → run `34038883633` → artifact `release-zip` → `*.sha256`.
- آرتیفکت CI در گیت روی WP واقعی نصب و کل مسیر تأیید شد (نه mount سورس).

---

## 3. محیط‌های آزمون

| محیط | قابلیت | وضعیت |
|---|---|---|
| Sandbox این جلسه | بدون PHP/MySQL/Apache/اینترنت عمومی (فقط node+zip؛ GitHub API از طریق proxy) | BLOCKED_BY_ENVIRONMENT برای WP/MySQL محلی؛ تست واحد فقط با WASM-PHP (8 خطای محیطی شناخته‌شده، بدون sodium) |
| CI — Unit (GitHub Actions, ubuntu-latest) | PHP 8.1/8.2/8.3/8.4 | VERIFIED_CI |
| CI — Integration | WP 6.7.2 + MySQL 8 (docker) + PHP 8.2 + pcntl + PHPUnit 9.6 — **نصب از سورس** | VERIFIED_CI |
| Pilot/Staging Gate | WP 6.7.2 + MySQL 8 ×۲ + Apache/mod_php + wp-cli + ab + Chromium — **نصب از آرتیفکت ZIP** | VERIFIED_CI (۲ بار سبز) |
| سرور فروشندهٔ واقعی (لایسنس/انتشار) | خارج repo | BLOCKED_BY_ENVIRONMENT |
| Pilot میزبان واقعی (دامنه/TLS/SMS واقعی) | خارج CI | BLOCKED_BY_ENVIRONMENT |

---

## 4. ماتریس وردپرس (Phase 9)

- شواهد موجود: **WP 6.7.2** — Integration (سورس) و Pilot (آرتیفکت: fresh/upgrade/restore/responsive).
- ادعای README = `WordPress 6.7+`. هدر افزونه «Requires at least: 6.4».
- **WP 6.4 / 6.5 / 6.6**: اجرا نشده → `NOT_TESTED`؛ ادعای پشتیبانی از 6.4 (هد‌ر) پیش از
  go-live باید یا با تست پوشش داده شود یا هدر/README هم‌راستا شوند (ناسازگاری مستندی — مورد ۳۶).
- REST/auth/cron/مهاجرت/گردش‌کار کامل روی 6.7.2 از آرتیفکت تأیید شد (VERIFIED_CI).

## 5. ماتریس PHP (Phase 10)

- Unit: PHP 8.1/8.2/8.3/8.4 سبز (CI) — syntax/دِپرکیت سطح Unit.
- مسیر کامل runtime (activation/migrate/REST/crypto/backup/license/jobs/filesystem):
  روی **PHP 8.2** (Integration + Pilot با CLI و mod_php 8.3 برای Apache و 8.2 CLI) VERIFIED_CI.
- runtime کامل روی 8.1/8.3/8.4: اجرا نشده → `NOT_TESTED` (فراتر از ادعای ریلیزِ کنونی ادعا نمی‌شود).
- پسوندهای لازم (mysqli/sodium/mbstring/curl/gd/zip/xml/pcntl) در هر دو محیط CI موجود و مسیرهای
  وابسته (sodium) سبز هستند.

## 6. ماتریس پایگاه‌داده (Phase 11)

- **MySQL 8**: VERIFIED_CI — schema/migrate، ایندکس‌ها (u_idem_scope، u_clinician_user،
  slot/payment uniques)، تراکنش/row-lock (VisitConcurrency)، رقابت ۱۰۰-راهی، بکاپ/بازیابی،
  idempotency، تست‌های Migration و Restore Drill.
- **MariaDB**: ادعای تجاری وجود ندارد (README: MySQL 8؛ baseline §40 فقط «تعریف حداقل نسخه» را
  الزام می‌کند)؛ **تست نشده** و ادعا نمی‌شود.

## 7. Fresh-install از آرتیفکت (Phase 7) — VERIFIED_CI (Pilot)

ZIP → WP 6.7.2 تمیز → activate → schema `0008` → نقش‌های `cpms_*` → رویداد cron `cpms_jobs_tick`
→ تعداد جداول `cpmswp_cpms_*` ≥ ۳۰ → **پنجرهٔ فعال‌سازی نصب تازه = `ACTIVATION_PENDING`/fresh**
(assert جدید گیت) → seed مصنوعی ۴۰۰ بیمار/۳۰ روز/۱۵ اسلات → ۱۰ نوع Job دوره‌ای اجرا و بدون failed →
محافظت فایل (403 مستقیم + storage خارج webroot) → Smokeهای S1–S9 (۹/۹ PASS) → REST envelopeها →
بنچمارک → ممیزی امنیتی → زنجیر Audit → بکاپ → Restore Drill. بدون هیچ warning/fatal در سطح کاربر
(WP_DEBUG off؛ بررسی‌شده). رفتار «فقدان Provider اختیاری»: Health/UI `NOT_CONFIGURED` را نشان می‌دهد
(بدون PHI؛ test-safe).

## 8. ارتقا (Phase 8) — VERIFIED_CI (Pilot، مسیر اصلی main → RC)

- نصب «نسخهٔ قبلی» = F8-final (`f11f6e0`، schema `0005`) با دادهٔ Synthetic (پزشک/بیمار/ردیف‌های
  idempotency با context_id=NULL) → تعویض فایل‌ها با **ZIP ریلیز F10** → `bin/cpms migrate` →
  schema `0008`؛ مهاجرت‌ها یک‌بار؛ NULL→0 نرمال؛ ایندکس‌های `u_idem_scope`/`u_clinician_user` موجود؛
  شمارش patients/clinicians دست‌نخورده؛ **پنجرهٔ مهاجرت = `ACTIVATION_GRACE`/migration (۳۰ روز)**
  (assert جدید گیت). بدون ازدست‌رفتن داده یا اختلال.

## 9. Deactivate / Reactivate / Reinstall (Phase 8/10)

`LicenseActivationWindowIntegrationTest` (CI): deactivate/reactivate و reinstall با باقی‌ماندن دادهٔ
CPMS، زمانِ شروع پنجره را تغییر نمی‌دهند (anti-reset؛ ON DUPLICATE فقط updated_at). VERIFIED_CI.

## 10. ماتریس نقش‌به‌نقش E2E (Phase 14)

پوشش در Integration (WP واقعی؛ نه صرفاً پنهان‌کردن UI — تلاش در سطح API/Backend):

| نقش | اجازه/رد در پوشش | تست‌ها |
|---|---|---|
| بیمار | OTP، نوبت خود، Cancel/Reschedule خود، فهرست «من»، رد دسترسی به دادهٔ بیمار دیگر، بلوکهٔ جست‌وجو/صف/مالی | OtpFlow, RestBooking, RestPatient, ClinicalFlow, RestQueue, MedicalFiles |
| پزشک | صف/ویزیت/نوت/نسخه/دست‌خط/پیوست/تکمیل/Private Note؛ رد دسترسی به ویزیت و Private Note پزشک دیگر؛ transitionهای ماشین | VisitFlow, ClinicalFlow, HandwritingFlow, SecurityHardening, RestQueue, ReportsAuthz |
| منشی | نوبت/چک‌این/صف/مالی طبق مجوز؛ رد ساخت نوت بالینی و دسترسی کلینیکال | PermissionMatrix, ClinicalFlow, FinanceFlow, RestQueue, RestSchedule |
| دستیار | دموگرافیک/صف مجاز؛ رد بالینی/Private | PermissionMatrix (caps)، ClinicalFlow |
| حسابدار | فاکتور/پرداخت/گزارش مالی؛ رد تشخیص/نوت/نسخه/فایل/Private | FinanceFlow, ReportsAuthz, PermissionMatrix |
| مدیر کلینیک | نماهای عملیاتی/مالیِ دارای گرنت؛ رد Private Note پزشکان | ReportsAuthz (aggregate only با گرنت صریح) |
| WP Admin / CPMS Admin | config فقط با cap `cpms_config`؛ **Admin دسترسی بالینی ضمنی ندارد** (admin caps «فنی‌محض» — `testAdminDefaultCapsAreTechnicalOnly`)؛ هیچ دور زدن سایلنت | PermissionMatrix, ClinicalFlow |

## 11. گردش‌کار کامل کلینیک (Phase 15)

- زنجیرهٔ کامل بیمار→نوبت→یادآور→چک‌این→صف→ویزیت→نوت/نسخه/دست‌خط/فایل→تکمیل→فاکتور/پرداخت→
  گزارش/Export→بکاپ→بازیابی: در Pilot روی آرتیفکت (S1–S9 + seed + restore drill) و در Integration
  روی مسیر واقعی (BookingFlow→VisitFlow→ClinicalFlow→HandwritingFlow→FinanceFlow→
  NotificationFlow→ReportsAuthz→BackupEngine) — هر دو VERIFIED_CI.
- تک‌پزشک و چندپزشک: ایزوله‌سازی مالکیت (Cross-doctor/IDOR) و هم‌زمانی چندپزشک در
  SecurityHardening/Concurrency/SlotClaim/ReportsAuthz پوشش دارد.
- **بازیابی در محیط ایزوله**: Pilot — Restore Drill روی MySQL دوم + WP tree دوم با مقایسهٔ
  شمارش **تک‌تک جدول‌ها** (restore == snapshot بکاپ)، audit chain، checksum فایل‌ها و نمونهٔ
  رکورد کسب‌وکار؛ سبز. (`restoreApply` درون‌افزونه‌ایِ مخرب: زیر را ببینید — §۳۱)

## 12. جریان‌های منفی/شکست (Phase 16)

پوشش API/سرویس (VERIFIED_CI): دابل‌کلیک/بازپخش (idempotency)، retry مرورگر (replay)، انقضای
nonce/401/403، چند تب (قفل Revision دست‌خط + resume-after-disconnect نوبت)، timeout فروشندهٔ
لایسنس (retryable → کش ادامه می‌یابد)، پاسخ دستکاری‌شده/نصبِ دیگر (رد)، انقضا/REVOKED/SUSPENDED
امضاشده، deadlock/قفل ردیف (VisitConcurrency)، هم‌پوشانی runnerها (JobQueue)، بکاپِ خراب/دستکاری‌شده
(رد)، توقف/شکست‌های DB (Duplicate/unique). UI/مرورگرِ واقعی برای offline/شبکه: `NOT_TESTED` (محیط).

## 13. هم‌زمانی (Phase 17) — نتایج دقیق

| سناریو | نتیجه | شواهد (CI، مسیر واقعی `SlotRepository::atomicBook`/سرویس) |
|---|---|---|
| ۱۰۰ درخواست هم‌زمان، یک پزشک، یک اسلات، capacity=1 | **دقیقاً ۱ برنده** | `SlotCapacityOneHundredWayTest::testOneHundredWaySameSlotCapacityOneAllowsExactlyOneWinner` |
| capacity=3 با contention>3 | **دقیقاً ۳ برنده** | `…CapacityThreeAllowsExactlyThreeWinners` |
| بازپخش Idempotency-Key هم‌کلاینت | پاسخ ذخیره‌شده، بدون duplicate | BookingFlow/Idempotency/RestBooking |
| cancel/reschedule/booking هم‌زمان | ظرفیت دقیق می‌ماند (hold→booked یک‌ظرفیتی) | SlotClaim/Concurrency |
| پرداخت تکراری | قید یکتایی + replay | FinanceFlow |
| transition ویزیت هم‌زمان | قفل ردیف؛ دقیقاً یک complete | VisitConcurrency |
| runnerهای Job هم‌پوشان | GET_LOCK/lease؛ idempotent reschedule | JobQueue/MigrationTest |

وضعیت نهایی DB (نه فقط HTTP) در همهٔ این تست‌ها assert می‌شود.

## 14. عملکرد (Phase 18/45)

- Pilot (Apache/mod_php + MySQL8 + opcache؛ دادهٔ ۴۰۰ بیمار): `ab` روی `/health` (۳۰۰۰× در
  c=10/50/100)، `/availability` (۱۵۰۰×) و مرجع `/wp-json/`؛ **گیت شرط non-2xx=0 و parse را دارد و
  سبز است**. اعداد p50/p95/p99/RPS در آرتیفکت `gate-logs` (run `34038883633`) ثبت‌اند؛ به‌دلیل قطع
  stream شبکهٔ sandbox به Azure blob قابل‌خواندن از این محیط نبود → مسیر دسترسی: GitHub → run →
  artifact `gate-logs` → `bench.txt` (مستند؛ نه PASS جعلی).
- baseline عملکرد پروژه (NFR-PERF-1) در docs موجود است؛ مقایسهٔ عددی نهایی پیش از go-live روی
  میزبان Pilot واقعی الزامی است (BLOCKED_BY_ENVIRONMENT).
- بار/soak طولانی: اجرا نشد → `NOT_TESTED`.

## 15. فرانت/مرورگر (Phase 19/20/21/22)

- Responsive smoke (Chromium واقعی، ۴ viewport × ۴ صفحه، روی آرتیفکت): بدون overflow افقی — سبز.
- RTL/فارسی: قالب و صفحه‌ها فارسی/RTL هستند؛ Jalali در رسید و اعلان‌ها تست شده (Finance/Notification).
- عمق UX انسانی/دسترسی‌پذیری (axe، کیبورد، فوکوس، کنتراست، zoom) و بازبینی payload/cache:
  `NOT_TESTED` در این محیط — نیازمند نشست مرورگر روی میزبان Pilot واقعی (پیش‌نیاز go-live؛ BLOCKED_BY_ENVIRONMENT).

## 16. امنیت / مهاجم (Phase 24–27) — همه روی محیط یک‌بارمصرف

| حوزه | نتیجه |
|---|---|
| IDOR/افق/عمودی | مسدود (پزشک-روی-ویزیتِ دیگری، بیمار-روی-بیمارِ دیگر، رد Secretary در کلینیکال، admin فنی‌محض) — SecurityHardening/Clinical/Rest* |
| CSRF | nonce الزامی برای هر REST نوشتاری (Rest* با CLINIC_INVALID_NONCE) |
| XSS | APIها JSON؛ escaping سمت سرور؛ فرمول‌نگاری در Export رد/مهر می‌شود؛ تست زندهٔ مرورگر روی ورودی پزشکی = `NOT_TESTED` (پیش‌نیاز) |
| SQLi | همهٔ کوئری‌ها از `CpmsDb::prepare`/wpdb prepared (بازبینی کد) — تست injection زنده اضافه نشد |
| SSRF | URL لایسنس/به‌روزرسانی: HTTPS-only + SsrfGuard؛ **تست جدید: ۶ Endpoint داخلی/خصوصی/متادیتا (شامل `[::1]`، 169.254.169.254، 192.168.x، 10.x، 127.0.0.1) پیش از هر HTTP مسدود می‌شوند** (VERIFIED_CI) |
| فایل/آپلود | PHP در لباس JPG رد؛ MIME باید با پسوند واقعی هم‌خوان باشد؛ سقف حجم؛ stream فقط با مجوز؛ مسیر protected با .htaccess (403 واقعی در Apache Pilot) |
| بکاپ/Export/مجوزها | گارد cap + nonce در Admin/CLI؛ دسترسی مستقیم بکاپ = deny |
| Rate-limit | پنجرهٔ ساعتی atomic؛ آزمون bypass نشد |
| حریم خصوصی خروجی (§28) | **تست جدید `VendorPlanePrivacyTest`**: sentinel PHI در DB؛ رهگیری کامل `pre_http_request` برای activate/refresh/update — هیچ sentinel در URL/Header/Body؛ Body لایسنس فقط کلیدهای Allowlist ADR-0028؛ مسیر کامل `activateWithKey`/`refresh` با سند امضاشده (VERIFIED_CI). اسکن Pilot: بدون sentinel در لاگ‌های error/tick/access |
| نگاشت کش/CDN/objet cache | `NOT_TESTED` (محیط) |

## 17. لایسنس E2E (Phase 29)

همهٔ سناریوهای state model در Integration سبز (VERIFIED_CI):
ACTIVATION_PENDING روز ۰/قبل ۷/بعد ۷ بدون سند → RESTRICTED؛ ACTIVATION_GRACE قبل/بعد ۳۰؛ فعال‌سازی
موفق در پنجره؛ فعال‌سازی آفلاین امضاشده؛ ACTIVE/EXPIRING/GRACE/RESTRICTED؛ UNREACHABLE با کش
(Retryable → ACTIVE می‌ماند)؛ INVALID-signature بدون ذخیره؛ REVOKED/SUSPENDED امضاشده؛ DEVELOPMENT
فقط صریح؛ anti-reset؛ **no permanent NOT_CONFIGURED bypass** (فقط defensive)؛ مهار عقب‌کشیدن ساعت؛
تاریخچه/Export/گردش‌کارِ جاری زیر RESTRICTED باز (VisitLicenseGate/Clinical). بدون درخواست شبکه در
بارگذاری صفحات عادی؛ بدون PHI (بخش ۱۶). Pilot: fresh=ACTIVATION_PENDING، upgrade=ACTIVATION_GRACE.

## 18. به‌روزرسانی / زنجیرهٔ تأمین (Phase 30)

UpdateServiceTest: entitled/not-entitled، امضای غلط رد، مانیفست ناسازگار رد، not_configured بدون
شبکه؛ WpUpdateBridge: تزریق فقط slug خودمان؛ sha256 بسته پیش از نصب (upgrader_pre_download)؛
بدون eval/کد راه دور (بازبینی). کلید خصوصی امضا: در repo/ZIP نیست (policy scan؛ کلیدها placeholder
fail-closed). E2E با سرور انتشار واقعی: BLOCKED_BY_ENVIRONMENT (runbook موجود).

## 19. بکاپ/بازیابی (Phase 31)

- ساخت بکاب (DB `cpms_*` snapshot + mirror storage + مانیفست/checksum) و verify/tamper/prune/
  preflight/confirm در BackupEngineTest روی MySQL واقعی — VERIFIED_CI.
- DR کامل Pilot (محیط ایزوله: MySQL دوم + WP tree دوم): شمارش تک‌تک جدول‌ها restore==snapshot،
  audit chain، checksum فایل‌ها، نمونهٔ کسب‌وکار — سبز (VERIFIED_CI؛ RTO ثبت‌شده در آرتیفکت).
- **شکاف صریح**: بازپخشِ مخرب `restoreApply` درون‌افزونه (DROP/ایمپورت روی DB جاری) در CI عمداً
  اجرا نمی‌شود (تخریب‌کننده برای DB مشترک تست). لایه‌های اطراف (آرتیفکت معتبر، preflight، گارد
  تأیید صریح CLI/Admin، safety-backup) تست‌شده‌اند؛ اجرای نهایی فقط در محیط DR مجزا (runbook:
  `docs/backup/backup-recovery.md`) → **BLOCKED_BY_ENVIRONMENT با دستورالعمل دقیق** — پیش‌نیاز go-live.

## 20. Cron / Job (Phase 33)

WP-Cron fallback و System-Cron هر دو: در Pilot رویداد `cpms_jobs_tick` + اجرای ۱۰ نوع Job دوره‌ای
بدون failed + دو tick پشت‌سرهم (re-schedule دوره‌ای) + health `stale=false`؛ Integration:
JobQueueTest (بک‌آف/retry/lease/اولویت/overlap) و Notification dedupe. بدون وابستگی به daemon برای
حالت عادی (در runner، cron daemon در دسترس نبود → scheduler-loop جایگزین برای اثبات recurring؛ در
سرور واقعی crontab/systemd-timer طبق user-guide).

## 21. Pilot/Staging Gate — بازبینی مستقل (Phase 39)

- **ریشهٔ شکست**: هر دو job در گام بررسی schema متوقف می‌شدند: گیت انتظار `2026_09_07_0007`
  داشت، ریلیز F10 به `0008` مهاجرت می‌کند (خطوط ۱۹۶/۷۶۹/۷۹۱ pilot-gate.yml). «Apache dead» در
  دیباگ صرفاً چون گام‌های بعدی هرگز اجرا نشده بودند. → **workflow defect**، نه Apache/محیط/آرتیفکت.
- **رفع**: انتظار به `0008` + دو assert جدید (fresh=ACTIVATION_PENDING/fresh؛ upgrade=ACTIVATION_GRACE/
  migration) — بدون تضعیف هیچ گیت.
- **نتیجه**: دو اجرای پیاپی سبز (runهای `34038136150` و `34038883633`). گیت قرمزِ سابق اکنون
  سبز و قابل‌تکرار است؛ نه حذف، نه skip، نه downgrade.

## 22. یافته‌های ایستا/وابستگی (Phase 40)

- وابستگی runtime خارجی ندارد (بدون composer/vendor — فقط WP/PHP هسته) → «composer audit» موضوعیت
  ندارد؛ اسکن محتوای ZIP پاک.
- PHPStan: بر اساس دستور، نه تکرار ادعای تاریخی و نه افزودن ابزار صرفاً برای حفظ ادعا — فهرست نشد.
- الگوهای خطرناک (eval/system/remote code): بازبینی کد — عدم وجود؛ بدون eval/کد راه دور.

## 23. نقص‌های یافت‌شده/رفع‌شده (Phase 41)

| # | نقص | شدت | ریشه | رفع | تست بازگشت | نتیجه |
|---|---|---|---|---|---|---|
| ۱ | Pilot gate schema انتظار `0007` (ریلیز F10 = `0008`) | High (گیت) | انتظار سخت‌کدِ stale در workflow | به‌روزرسانی به `0008` + assertهای F10 | اجرای دوبارهٔ گیت | ✅ سبز ۲× |
| ۲ | تست جدید SSRF: dataProvider ناسازگار PHPUnit9 | Low (تست) | روش provider | حلقهٔ inline | CI | ✅ سبز |
| ۳ | `deleteBackup` audit قبل از موفقیت واقعی | Low (شواهد) | ترتیب | audit پس از موفقیت | CI/unit | ✅ سبز |
| ۴ | ناسازگاری مستند: هدر «Requires at least 6.4» vs README «6.7+» | Low (مستند) | — | رفع کد نشد؛ پیش‌نیاز: هم‌راستاسازی یا تست 6.4–6.6 | — | باز (مستند) |

## 24. ریسک‌های باقی/پیش‌نیازهای go-live

پیش از GO_LIVE_READY (همگی ماهیت «شواهد محیط خارجی/دستی» دارند؛ هیچ‌کدام defect کد نیست):

1. تأیید E2E با سرور فروشندهٔ واقعی (لایسنس activate/refresh + انتشار امضاشده) — BLOCKED_BY_ENVIRONMENT.
2. Pilot واقعی روی میزبان مشتری‌مانند (دامنه/TLS/SMS واقعی) + بازبینی دستی UX/RTL/دسترس‌پذیری/مرورگر.
3. اجرای `restoreApply` مخرب در محیط DR مجزا (runbook آماده).
4. تست WP 6.4–6.6 (یا هم‌راستاسازی ادعای هدر) و runtime کامل PHP 8.1/8.3/8.4.
5. بازبینی عددی عملکرد از آرتیفکت `gate-logs` + بنچمارک روی Pilot واقعی؛ soak محدود.
6. تست کش/CDN/object-cache و XSS مرورگر با ورودی پزشکی (اختیاریِ امنیتی).
7. هم‌راستاسازی نهایی مستندات سازگاری (مورد ۲۳#۴) و تأیید نهایی کارفرما (§51) + merge PR #3.

## 25. جمع‌بندی و شواهد کلیدی

- CI: **سبز ۵/۵** روی `2c77509` (run `34038885481`؛ ۲۹۶ تست/۱٬۴۱۷ اِسert).
- Pilot/Staging Gate: **سبز ۴/۴** روی `2c77509` (run `34038883633`) و `66e371b` (run `34038136150`).
- تست پذیرش ۱۰۰-راهی: دقیقاً ۱ برنده (capacity=1) و ۳ برنده (capacity=3) — سبز.
- حریم خصوصی Vendor (sentinel) + SSRF پیش از HTTP: سبز (تست جدید).
- آرتیفکت: SHA256 `abfd4464…31880`؛ policy پاک؛ ۱۵۵ فایل؛ مهاجرت `0001…0008`.
- هیچ CRITICAL/HIGH در محصول؛ هیچ نقض یکپارچگی داده/مسدودکنندهٔ ریلیز یافت نشد.
- گیت‌های دارای شواهد محیطیِ کمبود: فهرست §۲۴.

---

FINAL VERDICT: CONDITIONAL_READY

**توضیح شواهد (به فارسی):** گیت‌های کد/محصول — شامل نصب تازه و ارتقا از آرتیفکت واقعی روی
WP 6.7.2 + MySQL 8، سیاست کامل لایسنس (پنجرهٔ ۷/۳۰ روزه، anti-reset، dev-mode صریح، REVOKED/
SUSPENDED/UNREACHABLE)، آزمون هم‌زمانی ۱۰۰-راهی روی مسیر واقعی، امنیت نقش/IDOR/فایل/SSRF،
ضمانت «بدون PHI به Vendor»، به‌روزرسانی امن، بکاپ/بازیابی (تا سطح DR drill در محیط ایزوله) و
Responsive واقعی — همگی **شواهد معتبر (VERIFIED_CI) دارند** و گیتِ Pilot که قرمز بود با رفع
defect workflow سبز شد (۲ بار). هیچ نقص CRITICAL/HIGH یا نقض دادهٔ مسدودکننده یافت نشد؛ بنابراین
NOT_READY توجیه ندارد. اما شواهدِ محیطیِ واقعی که ذاتاً خارج از CI/این sandbox است (سرور فروشندهٔ
واقعی، Pilot میزبان واقعی، اجرای `restoreApply` مخرب در محیط DR مجزا، پوشش WP 6.4–6.6 و runtime
PHP 8.1/8.3/8.4، بازبینی دستی UX/دسترس‌پذیری، عددهای بنچمارک روی میزبان هدف) هنوز
BLOCKED_BY_ENVIRONMENT است و اجازهٔ GO_LIVE_READY نمی‌دهد. پس: **CONDITIONAL_READY** — آمادهٔ
مرحلهٔ Pilot واقعی/تأیید نهایی کارفرما؛ بدون merge به main، بدون deploy، بدون دادهٔ واقعی، بدون
V1.1/V2 تا تأیید صریح شما.
