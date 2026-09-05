# گزارش فاز F3 — نوبت‌دهی و رزرو آنلاین (Booking API + Patient + Schedule + تست‌های REST/همزمانی + CI)

تاریخ: 2026-09-05 | وضعیت: **پیاده‌سازی کامل — در انتظار تأیید نهایی کارفرما (2 مورد باز در §5)** | محیط: PHP 8.1–8.4 / MySQL 8 / WordPress 6.7.2

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
5. **CI واقعی**: workflow از زیرپوشه plugin (که GitHub هرگز اجرایش نمی‌کرد) به ریشه repo منتقل شد؛ Unit روی PHP 8.1–8.4 (سبز)، Integration واقعی با MySQL 8 + WordPress 6.7.2 Test Library (PHPUnit 9.6 — نسخه رسمی پشتیبانی‌شده WP Test Lib؛ PHPUnit 11 با `parseTestMethodAnnotations` حذف‌شده ناسازگار است).

**تست‌ها:**
- Unit: `OK (209 tests, 33,327 assertions)` — اجرای مکرر سبز (PHPUnit 11.5.56، بدون WP).
- Integration: **99 تست در 14 فایل** (67 قبلی + 33 جدید: `RestBookingTest` 13، `RestPatientTest` 7، `RestScheduleTest` 8، `ConcurrencyTest` 4 + موارد RestBooking اضافه‌شده در Commit تست) — اجرا در CI با WP واقعی + MySQL واقعی؛ dispatch از مسیر `rest_do_request` واقعی (نه فراخوانی مستقیم Service).
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
| CI | Pipeline واقعی سبز | ⚠️ | Unit سبز روی 8.1–8.4 (تأییدشده). Integration: WP+MySQL بوت شد و 99 تست اجرا شد؛ دو مشکل زیرساختی CI (svn حذف‌شده از runner؛ parse-error در wp-tests-config؛ ناسازگاری PHPUnit 11 با WP Test Lib) رفع شد؛ **اجرای نهایی پس از اصلاح PHPUnit 9.6 در انتظار بازیابی دسترسی GitHub است** (§5-4) |

**نتیجه: 20/21 سبز + 1 در انتظار تأیید اجرای نهایی CI (معیار «سبز محسوب نکردن تا اجرای واقعی» رعایت شد).**

---

## 3. تغییرات کلیدی (اختلاف با F2)

| مورد | F2 | F3 (الان) |
|---|---|---|
| Endpointهای REST | health + otp + sms | **+ booking (B1–B6), patient/me, patients (D2–D5), appointments staff (D9–D11), config/schedules + config/schedule-exceptions (G1/G1b)** — همه `clinic/v1` |
| معماری دسترسی داده | Repository فقط برای Appointment/Patient/Slot (F3-early) | **+ `ScheduleRepository`** (الگوی مرجع ADR-0021: whitelist فیلدها، بدون منطق دامنه) |
| Envelope خطا | کد top-level مشتق snake-case (`cpms_clinic_*`) | **top-level = `CLINIC_*`** (Contract §0) — یک نقطه مرکزی `RestBase::error()` |
| روت‌های پارامتری | `{id}` (غیرقابل match — باگ) | `(?P<id>\d+)` |
| Permission callbacks | fatal/exit در برخی مسیرها | `?WP_Error` + Audit ردشدگی‌ها |
| تست | 157 unit + 45 integration (تقریبی، بدون اجرای CI) | **209 unit / 33,327 assertion + 99 integration (14 فایل، شامل REST-level و همزمانی fork)** |
| CI | فایل workflow در زیرپوشه — **هرگز اجرا نمی‌شد** | workflow در ریشه؛ matrix 8.1–8.4؛ job Integration واقعی (mysql:8 + WP 6.7.2 Test Lib + pcntl) |
| Settings docs | cancel/reschedule 12h (ناچسب با کد) | 24h همگام شد (`settings-reference.md` v1.3) |

## 3.1 کامیتهای F3 (14 کامیتمعیار در `arena/01a071c4-doctor`)

`e2ef22d` (fix: fatal cancelPermission + CLINIC_* envelopes) → `af39a5e` (feat: schedule API G1) → `ad9856c` (fix: روت‌های غیرقابل‌دسترس + کد top-level) → `5a32a83`/`e24098d` (test: REST-level + concurrency) → `5a44e7f`..`058d377` (CI: انتقال به ریشه، mysql:8، tarball به‌جای svn، ABSPATH، PHPUnit 9.6) → docs.

---

## 4. تصمیمات مهندسی درون‌فازی (بدون تغییر Scope)

1. **PHPUnit دو نسخه‌ای:** Unit روی PHPUnit 10.5/11 (PHP 8.1→10.5، 8.2+→11)؛ Integration روی **PHPUnit 9.6** چون WP 6.7 Test Lib به `PHPUnit\Util\Test::parseTestMethodAnnotations()` وابسته است که در PHPUnit 10/11 حذف شده و polyfills پوششش نمی‌دهد (99/99 خطا در setUp). راه‌حل استاندارد اکوسیستم WP اعمال شد.
2. **منبع Test Lib:** اسکریپت کلاسیک `install-wp-tests.sh` از trunk هسته حذف شده (بازسازی wp-env) → نسخه اقتباس‌شده vendored شد (`tests/bin/install-wp-tests.sh`): دانلود tarball تگ wordpress-develop (runnerهای ubuntu-24.04 بدون svn).
3. **تست همزمانی و تراکنش تست WP:** WP Test Suite هر تست را در تراکنش والد اجرا می‌کند و اتصال‌های مستقل روی ردیف‌های uncommitted قفل می‌شوند → تست‌های fork داده را قبل از fork commit و پس از تست پاکسازی دستی می‌کنند (در کامنت کلاس مستند شده).
4. **`generated_from`:** payload بازتولید از مسیر Schedule با `source=manual` ثبت می‌شود (مطابق ENUM ستون: lazy|cron|manual).
5. **Gate لایسنس روی Config اعمال نشد** — Config عملیات Protected نیست (F10 مال اعمال Gate نهایی است).

---

## 5. موارد باز / نیازمند تصمیم کارفرما (STOP — پیش از F4)

1. **Availability UI (تقویم + Slot + OTP + Profile):** نقش UI در F3 صریحاً تعریف نشده (SRS/Contract فقط Backend را الزام می‌کنند). **تصمیم محصول لازم است:** (الف) افزودن به F4 تحت scope صریح، یا (ب) فاز UI مستقل پس از F4. پیشنهاد: (ب) — ابتدا تکمیل پایه مالی/ویزیت (F4)، سپس UI یکپارچه.
2. **_capability مفقود برای مدیریت برنامه توسط منشی/پزشک:** ماتریس §4.2/4.3 به منشی «ثبت استثنای روزانه» و به پزشک «مدیریت برنامه خود» می‌دهد، اما مدل 46-capability هیچ cap برنامه‌ای ندارد → پیاده‌سازی آن ردیف‌ها = **تغییر مدل دسترسی** (STOP طبق Governance). وضعیت فعلی: فقط مدیر با `cpms_config`. گزینه‌ها: (الف) افزودن capهای `cpms_schedule_exception_create` و مشابه در یک افزایش آتی با تأیید ماتریس، (ب) باقی‌ماندن admin-only در V1. پیشنهاد: (الف) در F4 به‌همراه بازبینی drift های 49-const/46-matrix.
3. **بدهی فنی F2.5:** `SmsController::can()` هنوز envelope استاندارد `CLINIC_*` ندارد (تأثیر: پیام خطای یکدست نیست؛ رفتار امنیتی سالم است). تعمیر در F8/patch توصیه می‌شود.
4. **تأیید اجرای نهایی CI:** همه اجزای CI به‌صورت مرحله‌به‌مرحله در اجراهای واقعی GitHub Actions اعتبارسنجی شد (بوت WP، اجرای 99 تست، matrix unit سبز)؛ اما توکن GitHub این نشست در لحظه اجرای نهایی (بعد از اصلاح PHPUnit 9.6) منقضی شد و نتیجه آخرین run قابل مشاهده نیست. **پس از اتصال مجدد GitHub، آخرین run باید سبز تأیید شود** (یا در صورت قرمزی، همان فرآیند دیباگ با annotations ادامه یابد). طبق سیاست «اجرای واقعی»، این مورد تا تأیید، «در انتظار» است.
5. **PHPStan:** مرحله static-analysis موجود در CI قدیمی هرگز اجرا نشده بود و بدون اعتبارسنجی محلی حذف شد (باگ composition: نصب ad-hoc در runtime). پیشنهاد: افزودن در فاز بعدی با baseline و وابستگی composer.

---

## 6. گام بعدی (F4 — مالی/ویزیت؛ پس از تأیید این گزارش)

- Invoice/Payment (D12–D15) + Visit/Queue (D16) + Finance summary (D18)
- اعمال Gate لایسنس طبق نقشه راه (F10 برای سیاست کامل)
- بازبینی drift های capability (49 ثابت کد در برابر 46 ماتریس) — همراه تصمیم §5-2
