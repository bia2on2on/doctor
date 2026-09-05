# گزارش فاز F3 — نوبت‌دهی و رزرو آنلاین (Booking API + Patient + Schedule + تست‌های REST/همزمانی + CI)

تاریخ: 2026-09-05 | وضعیت: **کامل — CI سبز (21/21 AC)؛ در انتظار تأیید کارفرما برای ورود به F4 (3 تصمیم باز در §5)** | محیط: PHP 8.1–8.4 / MySQL 8 / WordPress 6.7.2

---

## 1. خلاصه

F3 در پنج لایه انجام شد:

1. **جریان رزرو آنلاین B1–B6** (BookingService، روت‌های REST روی `clinic/v1`): Availability، Quote، Hold (TTL + RateLimit)، Confirm (Idempotency الزامی + Replay بدون رکورد تکراری)، Cancel/Reschedule (Policy از Settings + Idempotency)، Resume، Appointments/mine.
2. **خودخدمتی بیمار C1/C2 + کارکنان D2–D11**: Whitelist فیلدها (فیلدهای بالینی از مسیر خودخدمت ممنوع)، جستجو/ساخت/ویرایش بیمار توسط منشی، نوبت حضوری (`is_walkin_express`)، لغو توسط منشی.
3. **Schedule API (G1/G1b)** با معماری لایه‌ای جدید: `ScheduleRepository` (اولین Repository دامنه‌محور — ADR-0021)، `ScheduleService` (اعتبارسنجی کامل + Regeneration مطابق ADR-0004)، `ScheduleController` (cap `cpms_config` + Nonce). سیاست: تغییر/حذف برنامه → حذف **فقط** Slotهای خالی آینده + enqueue بازتولید (priority 3)؛ Slot دارای رزرو/hold هرگز حذف نمی‌شود؛ خطای Regeneration جریان Config را نمی‌شکند (cron روزانه جبران می‌کند).
4. **سخت‌سازی REST (رفع باگ‌های واقعی که تست‌های سطح REST کشف کردند):**
   - روت‌های `{id}` با الگوی literal ثبت شده بودند که در WP REST **هرگز match نمی‌شدند** (cancel/reschedule/get-patient عملاً 404) → اصلاح به `(?P<id>\d+)`.
   - Envelope خطا: کد سطح top-level مشتق snake-case بود؛ اکنون خود `code` همان ثابت `CLINIC_*` مطابق Contract §0 است (یک نقطه مرکزی: `RestBase::error()`).
   - `cancelPermission` فاتال می‌داد (ArgumentCountError) → بازنویسی با 401/403 + Audit.
   - همه permission callback ها اکنون `?WP_Error` برمی‌گردانند (null=اجازه، WP_Error=رد با پوشش CLINIC_*) + Audit `FORBIDDEN_ACCESS_ATTEMPT`.
5. **CI واقعی — سبز**: workflow از زیرپوشه plugin (که GitHub هرگز اجرایش نمی‌کرد) به ریشه repo منتقل شد؛ Unit روی PHP 8.1–8.4 سبز؛ Integration واقعی با MySQL 8 + WordPress 6.7.2 Test Library سبز — **run نهایی: هر 5 job ✓ (Unit ×4 + Integration؛ 99 تست، 456 assertion)**. تکرارهای دیباگ CI تعداد قابل توجهی **باگ واقعی Production** را آشکار کردند (فهرست کامل در §4.1). PHPUnit 9.6 برای Integration (نسخه رسمی پشتیبانی‌شده WP Test Lib؛ PHPUnit 11 با `parseTestMethodAnnotations` حذف‌شده ناسازگار است).

**تست‌ها:**
- Unit: `OK (210 tests, 33,329 assertions)` — اجرای مکرر سبز (PHPUnit 11.5.56، بدون WP؛ +1 regression-test پایداری هش زنجیره Audit در برابر coerc رشته‌ای DB).
- Integration: **99 تست در 14 فایل — سبز در CI** (67 قبلی + 33 جدید: `RestBookingTest` 13، `RestPatientTest` 7، `RestScheduleTest` 8، `ConcurrencyTest` 4 + موارد RestBooking اضافه‌شده در Commit تست)؛ dispatch از مسیر `rest_do_request` واقعی (نه فراخوانی مستقیم Service)؛ ایزوله‌سازی تست‌ها با ترجمه‌ی تراکنش‌های Service به SAVEPOINT در bootstrap (§4.2).
- همزمانی (TP-03): پردازهای واقعی موازی `pcntl_fork` روی اتصال‌های mysqli مستقل — ظرفیت 1 → دقیقاً یک برنده؛ ظرفیت 3 → دقیقاً 3؛ تبدیل hold→claim با شمارنده‌های دقیق و بدون نشت ظرفیت.

---

## 2. ماتریس Acceptance Criteria

| AC | معیار | وضعیت | شواهد |
|---|---|:---:|---|
| B1 | `POST /booking/hold` — Hold اتمیک با TTL (پیش‌فرض 600s) + RateLimit 10/hr | ✅ | `BookingService::hold`, `RestBookingTest::testHoldConfirmEnvelopeAndCorrelationHeader`, `ConcurrencyTest` |
| B2 | `POST /booking/confirm` — `Idempotency-Key` الزامی؛ Replay = همان Appointment بدون رکورد دوم | ✅ | `RestBookingTest::testConfirmWithoutIdempotencyKeyRejected` (400) + `testHoldConfirmEnvelopeAndCorrelationHeader` (Replay یکسان) |
| B3 | `GET /appointments/mine` | ✅ | `BookingController`, `BookingFlowTest` |
| B4 | `POST /appointments/{id}/cancel` — Policy از Settings (FR-4.9؛ پیش‌فرض 24h) + IDOR | ✅ | `RestBookingTest::testPatientCannotCancelOthersAppointment` (403 + عدم تغییر وضعیت) |
| B5 | `POST /appointments/{id}/reschedule` — Policy + Idempotency + انتقال Slot | ✅ | `RestBookingTest::testRescheduleWithoutIdempotencyKeyRejected` + `BookingFlowTest` |
| B6 | `GET /booking/resume` — ادامه بعد از قطعی | ✅ | `BookingService::resume`, `BookingFlowTest` |
| C1/C2 | `GET/PUT /patient/me` — Whitelist؛ فیلد بالینی رد می‌شود | ✅ | `RestPatientTest::testUpdateMeWhitelistBlocksClinicalFields` |
| D2/D3/D4/D5 | جستجو/ساخت/مشاهده/ویرایش بیمار با cap + duplicate-mobile 400 | ✅ | `RestPatientTest` |
| D9/D10/D11 | لیست روز پزشک/نوبت حضوری/لغو توسط منشی | ✅ | `RestBookingTest::testStaffCreateAndListViaRest` (+ duplicate → 409 `CLINIC_DUPLICATE_APPOINTMENT`) |
| G1 | CRUD برنامه هفتگی با cap `cpms_config` + Nonce + اعتبارسنجی کامل | ✅ | `RestScheduleTest` (including duplicate-day 400) |
| G1b | CRUD استثنائات (holiday/leave/blocked/open_override) | ✅ | `RestScheduleTest::testHolidayExceptionClosesFutureDay` + `testRangeExceptionValidation` |
| FR-3.3 | تولید Slot از برنامه؛ استثنا = روز بسته | ✅ | `RestScheduleTest::testScheduleCrudAndSlotGeneration` (اسلات‌های 09:00/10:00/11:00 با ظرفیت) |
| ADR-0004 | Regeneration: حذف فقط Slotهای خالی؛ Slot رزروشده محفوظ | ✅ | `RestScheduleTest::testBookedSlotSurvivesRegeneration` |
| TP-03 | N درخواست هم‌زمان روی Slot ظرفیت 1 → دقیقاً یک موفق | ✅ | `ConcurrencyTest::testParallelWorkersClaimCapacityOneExactlyOnceSucceeds` (fork×10) |
| TP-04 | CSRF — موتانت بدون/با Nonce خراب → 403 `CLINIC_INVALID_NONCE` | ✅ | `RestBookingTest` + `RestPatientTest` + `RestScheduleTest` |
| TP-07 | IDOR — بیمار B روی Hold/نوبت/پروفایل بیمار A → 403 + Audit | ✅ | `RestBookingTest` (confirm/cancel/reschedule) |
| TP-09 | Authorization — نقش بدون cap → 403 + Audit `FORBIDDEN_ACCESS_ATTEMPT` | ✅ | `RestBookingTest::testHoldByNonPatientRoleRejectedAndAudited` (تأیید رکورد Audit) |
| TP-20 | Rate Limit booking → 429 `CLINIC_RATE_LIMITED` | ✅ | `RestBookingTest::testHoldRateLimitedAfterTenPerHour` (11th → 429) |
| Envelope | `{code: CLINIC_*, message, data:{status}}` در top-level | ✅ | `assertClinicError` در هر سه فایل REST + `RestBase::error()` |
| M10 | `X-CPMS-Correlation-Id` در Response | ✅ | `RestBookingTest::testHoldConfirmEnvelopeAndCorrelationHeader` |
| CI | Pipeline واقعی سبز | ✅ | **Run سبز کامل (هر 5 job)**: Unit ×4 (PHP 8.1–8.4) + Integration (WP 6.7.2 + MySQL 8، 99 تست/456 assertion). مسیر رسیدن: 12 run قرمز→سبز با رفع باگ‌های واقعی (§4.1) و زیرساخت تست (§4.2) |

**نتیجه: 21/21 سبز — شامل اجرای واقعی Integration در CI (معیار «سبز محسوب نکردن تا اجرای واقعی» تا آخر رعایت شد).**

---

## 3. تغییرات کلیدی (اختلاف با F2)

| مورد | F2 | F3 (الان) |
|---|---|---|
| Endpointهای REST | health + otp + sms | **+ booking (B1–B6), patient/me, patients (D2–D5), appointments staff (D9–D11), config/schedules + config/schedule-exceptions (G1/G1b)** — همه `clinic/v1` |
| معماری دسترسی داده | Repository فقط برای Appointment/Patient/Slot (F3-early) | **+ `ScheduleRepository`** (الگوی مرجع ADR-0021: whitelist فیلدها، بدون منطق دامنه) |
| Envelope خطا | کد top-level مشتق snake-case (`cpms_clinic_*`) | **top-level = `CLINIC_*`** (Contract §0) — یک نقطه مرکزی `RestBase::error()` |
| روت‌های پارامتری | `{id}` (غیرقابل match — باگ) | `(?P<id>\d+)` |
| Permission callbacks | fatal/exit در برخی مسیرها | `bool\|WP_Error` (true=اجازه؛ null در WP یعنی رد!) + Audit ردشدگی‌ها |
| تست | 157 unit + 45 integration (تقریبی، بدون اجرای CI) | **210 unit / 33,329 assertion + 99 integration سبز در CI (14 فایل، شامل REST-level و همزمانی fork)** |
| CI | فایل workflow در زیرپوشه — **هرگز اجرا نمی‌شد** | workflow در ریشه؛ matrix 8.1–8.4؛ job Integration واقعی (mysql:8 + WP 6.7.2 Test Lib + pcntl) |
| Settings docs | cancel/reschedule 12h (ناچسب با کد) | 24h همگام شد (`settings-reference.md` v1.3) |

## 3.1 کامیتهای F3 (14 کامیتمعیار در `arena/01a071c4-doctor`)

`e2ef22d` (fix: fatal cancelPermission + CLINIC_* envelopes) → `af39a5e` (feat: schedule API G1) → `ad9856c` (fix: روت‌های غیرقابل‌دسترس + کد top-level) → `5a32a83`/`e24098d` (test: REST-level + concurrency) → `5a44e7f`..`058d377` (CI: انتقال به ریشه، mysql:8، tarball به‌جای svn، ABSPATH، PHPUnit 9.6) → **چرخه سبزسازی CI (13 کامیت، `efa60fc`..`380d74d`)**: پارامتری‌سازی datetime خام، SAVEPOINT در bootstrap تست، نرمال‌سازی تایپ HashChain، true از permission_callback ها، namespace درست BookingException، atomic* با affected-rows، نگاشت staff در ماشین حالت، پاک‌سازی RateLimiter، ماندگاری attempts OTP، Correlation helper در سطح فایل — هر یک با کامیت جدا و توضیح ریشه/اثر (§4.1).

---

## 4. تصمیمات مهندسی درون‌فازی (بدون تغییر Scope)

1. **PHPUnit دو نسخه‌ای:** Unit روی PHPUnit 10.5/11 (PHP 8.1→10.5، 8.2+→11)؛ Integration روی **PHPUnit 9.6** چون WP 6.7 Test Lib به `PHPUnit\Util\Test::parseTestMethodAnnotations()` وابسته است که در PHPUnit 10/11 حذف شده و polyfills پوششش نمی‌دهد (99/99 خطا در setUp). راه‌حل استاندارد اکوسیستم WP اعمال شد.
2. **منبع Test Lib:** اسکریپت کلاسیک `install-wp-tests.sh` از trunk هسته حذف شده (بازسازی wp-env) → نسخه اقتباس‌شده vendored شد (`tests/bin/install-wp-tests.sh`): دانلود tarball تگ wordpress-develop (runnerهای ubuntu-24.04 بدون svn).
3. **تست همزمانی و تراکنش تست WP:** WP Test Suite هر تست را در تراکنش والد اجرا می‌کند و اتصال‌های مستقل روی ردیف‌های uncommitted قفل می‌شوند → تست‌های fork داده را قبل از fork commit و پس از تست پاکسازی دستی می‌کنند (در کامنت کلاس مستند شده).
4. **`generated_from`:** payload بازتولید از مسیر Schedule با `source=manual` ثبت می‌شود (مطابق ENUM ستون: lazy|cron|manual).
5. **Gate لایسنس روی Config اعمال نشد** — Config عملیات Protected نیست (F10 مال اعمال Gate نهایی است).

### 4.1 باگ‌های واقعی Production که تکرارهای CI آشکار کردند

معیار «اجرای واقعی» جا افتاد: هیچ‌یک از موارد زیر با unit test قبلی یا بازبینی دستی کشف نشده بودند؛ همگی توسط اجرای واقعی Integration در CI (WP 6.7.2 + MySQL 8 واقعی) شناسایی و رفع شدند و اکنون پوشش تست دارند:

| # | باگ | اثر در Production (بدون fix) | ریشه |
|---|---|---|---|
| 1 | `permission_callback`ها در موفقیت `null` برمی‌گرداندند | **هر endpoint محافظت‌شده همیشه 403 `rest_forbidden`** — حتی برای کاربر مجاز | در WP، false **و null** هر دو یعنی رد؛ امضا به `bool\|WP_Error` و بازگشت `true` اصلاح شد |
| 2 | ۵ فایل `use ClinicCore\Application\Booking\BookingException` (کلاس ناموجود — واقعی: `Domain\Booking`) | `wrap()` هرگز exception را نمی‌گرفت؛ throwهای سرویس = fatal «Class not found» | import اشتباه namespace |
| 3 | `HashChain::fieldsFor` بدون نرمال‌سازی تایپ | **verifyChain همیشه false** — صحت‌سنجی زنجیره Audit عملاً بی‌اثر | هنگام نوشتن idها int، هنگام خواندن string (wpdb) → canonical JSON متفاوت |
| 4 | `atomicBook` شرط ظرفیت را بدون `held_count` می‌سنجید | **Overbooking**: نوبت حضوری/جابه‌جایی می‌توانست کل ظرفیتِ Hold شده بیمار دیگر را قفل کند |
| 5 | `CpmsDb::query()` bool برمی‌گرداند و atomic* ها مستقیم آن را return می‌کردند | **atomicHold/Book روی اسلات پر هم true** → شمارنده‌ها از ظرفیت جلو می‌زدند | API جدید `execute()` (affected rows) + مقایسه `> 0` |
| 6 | `machineCheck` با actor `'staff'` | **منشی/پزشک هرگز نمی‌توانست نوبتی لغو کند** (InvalidTransition) — ماشین با نقش‌های منطقی کار می‌کند | نگاشت staff→[secretary, doctor] |
| 7 | `RateLimiter::cleanup` cutoff روزانه را با window_id ساعتی مقایسه می‌کرد | پاک‌سازی دوره‌ای **هرگز چیزی حذف نمی‌کرد** (جدول رشد می‌کرد) |
| 8 | ذخیره تلاش‌های نادرست OTP با prepare و مقدار null → `locked_until = ''` | **UPDATE کلان رد می‌شد؛ شمارنده هرگز ذخیره نمی‌شد و قفل OTP هرگز فعال نمی‌شد** | migr به `CpmsDb::update()` (null→SQL NULL) |
| 9 | helperهای `cpms_request_id/cpms_session_id` داخل hook `init` تعریف می‌شدند | در CLI/CRON (و تست) هدر `X-CPMS-Correlation-Id` هرگز ست نمی‌شد | تعریف در سطح فایل اصلی افزونه |
| 10 | `appointmentView` کلید `appointment_id` نداشت | ناسازگاری آشکار با API Contract B2/B5 (کلید پاسخ) |
| 11 | `BookingService::audit()` در cancel/reschedule با ۱۰ آرگومان صدا زده می‌شد (meta=null) | TypeError در مسیر لغو/جابه‌جایی غیرمجاز |
| 12 | درج datetime خام بدون placeholder/quote در ۶ نقطه (BookingService + SlotRepository×5) | syntax error در MySQL — کلاً مسیر اجرا نمی‌شد |
| 13 | `JobQueue::releaseStaleLocks`/`RateLimiter::cleanup` با return type `int` ولی مقدار bool | TypeError در هر اجرای Worker | (همان #5) |
| 14 | `staffView` بدون null-safe روی کلیدهای بالینی | Warning/Exception در مسیر diff خودخدمتی |

### 4.2 زیرساخت تست — تصمیم‌های مهندسی

1. **ایزوله‌سازی تست در برابر تراکنش سرویس‌ها:** WP Test Suite هر تست را در یک تراکنش باز اجرا می‌کند؛ `START TRANSACTION` سرویس داخل آن = COMMIT ضمنی کل fixtureها → نشت داده بین تست‌ها (Duplicate keyهای زنجیره‌ای). راه‌حل نهایی: `CpmsDb::transactional` افعال تراکنش را با نشانگر `/*cpms*/` صادر می‌کند و **bootstrap تست** با filter روی `wpdb` (همان الگوی خود WP برای CREATE TEMPORARY) آن‌ها را به SAVEPOINT/RELEASE/ROLLBACK-TO ترجمه می‌کند. Production فیلتر را ندارد — رفتار تراکنش کاملاً دست‌نخورده.
2. **تشخیص تراکنش باز (تلاش‌های ردشده):** `@@IN_TRANSACTION` فقط MariaDB است (MySQL: Unknown variable)؛ `information_schema.INNODB_TRX` هم در محیط CI خود connection را نشان نداد (شمارش ۰ با وجود تراکنش فعال). به‌جای تشخیص در runtime، بازنویسی در لایه bootstrap انتخاب شد (قطعیت بالاتر، صفر تغییر در Production).
3. **مهاجرت واقعی فقط یک‌بار در bootstrap:** `CREATE TABLE` زیر فیلتر تست WP به TEMPORARY تبدیل می‌شود → FK migrationها fail و `schema_migrations` واقعی را shadow می‌کند؛ guard وجود (information_schema) + `require_once` + placeholderهای `%s` در migrationها رفع کرد.
4. **انتقال لاگ شکست به کامنت PR** (head/DIAG/ERRORS/FAILURES) چون لاگهای job از طریق API به blob ریدایرکت می‌شوند و دانلودشان EOF می‌دهد.
5. **نکات wpdb:** placeholder `?` پشتیبانی نمی‌شود (فقط %s/%d/%f)؛ prepare بدون placeholder یک doing_it_wrong ثبت می‌کند و تست را می‌اندازد؛ prepare با مقدار null → رشته خالی.

---

## 5. موارد باز / نیازمند تصمیم کارفرما (STOP — پیش از F4)

1. **Availability UI (تقویم + Slot + OTP + Profile):** نقش UI در F3 صریحاً تعریف نشده (SRS/Contract فقط Backend را الزام می‌کنند). **تصمیم محصول لازم است:** (الف) افزودن به فاز بعدی تحت scope صریح، یا (ب) فاز UI مستقل پس از آن. پیشنهاد: (ب) — ابتدا تکمیل پایه ویزیت/صف (F4) و مالی (F6)، سپس UI یکپارچه.
2. **_capability مفقود برای مدیریت برنامه توسط منشی/پزشک:** ماتریس §4.2/4.3 به منشی «ثبت استثنای روزانه» و به پزشک «مدیریت برنامه خود» می‌دهد، اما مدل 46-capability هیچ cap برنامه‌ای ندارد → پیاده‌سازی آن ردیف‌ها = **تغییر مدل دسترسی** (STOP طبق Governance). وضعیت فعلی: فقط مدیر با `cpms_config`. گزینه‌ها: (الف) افزودن capهای `cpms_schedule_exception_create` و مشابه در یک افزایش آتی با تأیید ماتریس، (ب) باقی‌ماندن admin-only در V1. پیشنهاد: (الف) در F4 به‌همراه بازبینی drift های 49-const/46-matrix.
3. **بدهی فنی F2.5:** `SmsController::can()` هنوز envelope استاندارد `CLINIC_*` ندارد (تأثیر: پیام خطای یکدست نیست؛ رفتار امنیتی سالم است). تعمیر در F8/patch توصیه می‌شود.
4. ~~**تأیید اجرای نهایی CI**~~ — **حل شد:** دسترسی GitHub بازیابی و CI با ۱۲ تکرار دیباگ واقعی (رفع باگ‌های §4.1 + زیرساخت §4.2) به **سبز کامل** رسید — runهای آخر: هر 5 job ✓ (Unit PHP 8.1–8.4 + Integration 99 تست/456 assertion). شواهد (هر سه مشاهده مستقیم): run سبز روی `fd191b0` → run سبز روی `380d74d` (کد نهایی بدون دیاگ‌ها) → **run سبز روی `820d4b1` = HEAD نهایی** (33982970314؛ هر 5 job ✓). زنجیره از ابتدا قرمز تا سبز کامل در PR #1 قابل بازبینی است.
5. **PHPStan:** مرحله static-analysis موجود در CI قدیمی هرگز اجرا نشده بود و بدون اعتبارسنجی محلی حذف شد (باگ composition: نصب ad-hoc در runtime). پیشنهاد: افزودن در فاز بعدی با baseline و وابستگی composer.

---

## 6. گام بعدی (F4 — مراجعه/صف؛ پس از تأیید این گزارش)

> **اصلاح doc-sync (2026-09-05):** نگارش اول این بخش F4 را «مالی/ویزیت» خوانده بود — خطا بود. منبع حقیقت فازبندی `docs/roadmap/roadmap.md` است (از commit اولیه بدون تغییر): **F4 = مراجعه/صف** و **F6 = مالی**.

- Check-in/Walk-in (D6/D7) + Queue State Machine & History (D8) + Real-time Polling (R1 — ADR-0007) + داشبورد منشی (D1) + اکشن‌های پزشک (E1–E6) + Checkout (D16) + No-show خودکار (FR-5.5)
- تست‌های نقشه راه: TP-19 (جریان no-show/دیرهنگام)، TP-03b (concurrent complete)، TP-07 (IDOR بخش ویزیت)
- مالی (D12–D18: Invoice/Payment/Receipt/Finance) در **F6**
- اعمال Gate لایسنس طبق نقشه راه (F10 برای سیاست کامل)
- بازبینی drift های capability (49 ثابت کد در برابر 46 ماتریس) — همراه تصمیم §5-2
