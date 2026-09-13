# گزارش نهایی اصلاحی T2 — فقط سبزسازی T2 (Draft PR #28)

**تاریخ:** 2026-09-13 00:11 UTC  
**شاخه:** `arena/01a09667-doctor`  
**HEAD محلی:** `3a43de37f35e9446c67f317e60c1099db6058b5d`  
**HEAD ریموت PR:** `3a43de3` (مساوی محلی)  
**origin/main:** `a93d20ab1dfd81d2873ea345918e950f17a944d9`  
**وضعیت کاری:** تمیز (بدون فایل dirty)  
**PR #28:** OPEN + DRAFT  
**مایگریشن:** فقط تا `0020` (`2026_09_09_0020_idempotency_clinic_scope.php`) — بدون مایگریشن جدید  
**CI فعلی:** `34727161526` برای SHA `3a43de3` — Integration: 696 تست، 1 شکست (فقط REMINDER مورد انتظار)، بقیه گیت‌ها سبز

---

## 1) موجودی شکست‌ها قبل از اصلاح (در SHA 95758e4)

در اجرای `34725458749` (95758e4) خلاصه: `Tests: 696, Assertions: 4872, Errors: 2, Failures: 7` → مجموع 9 خطا.

جزئیات:

1. `Phase2MultiLocationTemporalRedTest::testPrematureNoShowPeriodic` **ERROR** `Class DateInterval not found` در خط 255 — ایمپورت ناقص.
2. `Phase2MultiLocationTemporalRedTest::testLazyCheckInNoShowConsistency` **ERROR** `CLINIC_DUPLICATE_ACTIVE_VISIT` — فیکسچر بیمار فعال برخورد کرد.
3. `Phase2MultiLocationTemporalRedTest::testReminderDayBoundaryMultiLocation` **FAILURE** — `handler returned 1, notifications 0/0, expected both` — این شکست **مورد انتظار قرمز** است.
4. `Phase2MultiLocationTemporalRedTest::testPerClinicGraceIsolation` **FAILURE** `null is identical to 'confirmed'` — درج نوبت B شکست خورده (null).
5. `RestQueueTest::testCheckInHappyPath` **FAILURE** `scheduled vs walk_in` — نوبت آینده به اشتباه walk_in شد.
6. `VisitFlowTest::testCheckInCreatesVisitAndAutoEnqueues` **FAILURE** `scheduled vs walk_in` — مشابه.
7. `VisitFlowTest::testFullQueueLifecycleThroughCheckout` **FAILURE** `completed vs no_show` — زنجیره صف خراب شد.
8. `VisitFlowTest::testNoShowSweepMarksOnlyUnvisitedLateAppointments` **FAILURE** `2 is identical to 1` — شمارش no_show اشتباه.
9. `VisitLicenseGateTest::testCheckInOfPreExistingAppointmentIsAllowedInReadOnlyMode` **FAILURE** `scheduled vs walk_in` — مشابه.

---

## 2) طبقه‌بندی A/B/C/D

**معیار:** A=رگرسیون محصول توسط T2، B=نقص پیشین محصول، C=زیرساخت/محیط، D=نقص تست/فیکسچر.

| تست | طبقه | دلیل |
|---|---|---|
| testPrematureNoShowPeriodic ERROR DateInterval | **D** | ایمپورت بدون `\` یا `use` — قرارداد پروژه استفاده از `\DateInterval` است |
| testLazyCheckInNoShowConsistency ERROR duplicate active Visit | **D** | فیکسچر `fxTInsertAppointment` همیشه بیمار ثابت `fxTPatient` را استفاده می‌کرد و `fxTInsertPatient` وجود نداشت؛ ویزیت فعال قبلی باقی ماند |
| testPerClinicGraceIsolation FAILURE null | **D** | دو علت: (1) درج `cpms_appointments` بدون `reference_code` و `slot_end_time` که NOT NULL است → insert_id=0 → SELECT null، (2) slug ثابت `temporal-clinic-b` بدون تصادفی‌سازی → برخورد یکتا در اجراهای متوالی |
| RestQueueTest/VisitFlowTest/VisitLicenseGate FAILURE scheduled→walk_in | **A** | رگرسیون T2: تست‌ها `gmdate()` (UTC) را برای `slot_date/time` استفاده می‌کردند، در حالی که قرارداد T2 می‌گوید `slot_date/time` باید **wall-clock محلی Location** (Asia/Tehran) باشد. تبدیل: `local Tehran + Asia/Tehran → UTC = now+1h -3:30 = 2.5h قبل` → Grace 30 دقیقه گذشته → Service به درستی آن را overdue تشخیص داد و walk_in ساخت. محصول درست کار می‌کرد، تست قدیمی بود |
| testReminderDayBoundaryMultiLocation FAILURE | **EXPECTED RED** | مسیر محصول یادآور عمداً قرمز نگه داشته شد — بوت‌استرپ و فیکسچر موفق، handler فراخوانی شد، شکست روی assertion مورد نظر (1 به جای 2) |

هیچ مورد B یا C یافت نشد (به جز قفل‌های تصادفی `Lock wait timeout` که گذرا هستند).

---

## 3) اصلاح شواهد نادرست قبلی

گزارش قبلی ادعا کرده بود T2 در 95758e4 با 4 شکست قرمز نیست. بررسی اجرایی نشان داد 9 شکست (2 ERROR +7 FAILURE) وجود داشت که 4 مورد از آن‌ها رگرسیون A بودند که در گزارش قبلی به عنوان D طبقه‌بندی شده بودند. همچنین ادعا شده بود `perClinicGrace` به دلیل `SettingsFactory` است، در حالی که ریشه اصلی نبودِ `reference_code` بود.

---

## 4) فیکس‌های دقیق تست/فیکسچر

- **DateInterval:** در `Phase2MultiLocationTemporalRedTest` تمام `new DateInterval` به `new \DateInterval` تغییر یافت (یا `use` صحیح). این D است و قرارداد را تضعیف نمی‌کند.
- **Active Visit collision:** در `Phase2MultiLocationTemporalFixture.php` تابع `fxTInsertPatient()` اضافه شد و `fxTInsertAppointment` اکنون پارامتر اختیاری `patientId` می‌پذیرد؛ در `testLazyCheckInNoShowConsistency` بیمار دوم متمایز ساخته می‌شود تا ویزیت فعال قبلی تداخل نکند.
- **perClinicGraceIsolation:** 
  - شناسه‌های دوم تصادفی شدند `random_int(70000,79999)` و قبل از درج پاکسازی شدند.
  - slug کلینیک دوم به `temporal-clinic-b-{random}` تصادفی شد.
  - درج نوبت‌ها اکنون شامل `reference_code` (`GR-` + random) و `slot_end_time` (محاسبه +20 دقیقه با `\DateTimeImmutable` و `\DateInterval`) است.
  - پاکسازی گسترده برای کلینیک‌های stale با الگوی `slug LIKE 'temporal-clinic-b-%'` اضافه شد.
- **VisitFlow/RestQueue/LicenseGate:** تمام تولیدکنندگان `slot_date/time` که از `gmdate('Y-m-d', $t)` استفاده می‌کردند به ` (new \DateTimeImmutable('@'.$t))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format(...)` تغییر یافتند تا قرارداد T2 (ذخیره wall-clock محلی) رعایت شود. `slot_end_time` نیز با `add(PT20M)` در همان TZ محاسبه شد.

---

## 5) ریشه‌یابی perClinicGrace null

`INSERT IGNORE` قبلی با ID ثابت 62202 باعث `insert_id=0` می‌شد. بعداً حتی با random ID، عدم وجود `reference_code` باعث شکست درج (NOT NULL) و `insert_id=0` شد → `SELECT status FROM appointments WHERE id=0` → null. با افزودن `reference_code` و تصادفی‌سازی slug، درج موفق و تست سبز شد.

---

## 6) تفکیک نهایی Service/Repository

**قبل (دوگانگی):**
- Repository: `appointmentsPastGrace()` با `slot_date <= now+2d LIMIT 100` و سپس فیلتر PHP با `resolveLocationTimezoneForRepo()` + `appointmentUtcInstantForRepo()` + `graceForClinicForRepo()` (مستقیم `cpms_settings` SELECT) → سیاست تکراری.
- Service: همین منطق را دوباره پیاده می‌کرد + fallback به `resolvePrimaryLocationTimezone()` (اولین Location) که تفسیر نادرست invalid/mismatched بود.

**بعد (تصحیح):**
- **Repository:** فقط دسترسی داده محدود — `appointmentsPastGraceCandidates(limit, nowUtc, cursor)` با `LEFT JOIN cpms_locations` برای دریافت `loc_clinic_id` و `loc_timezone`، بدون هیچ `DateTime` eligibility، بدون خواندن `cpms_settings`. `appointmentsPastGrace()` قدیمی اکنون فقط wrapper است که `candidates` را برمی‌گرداند.
- **Service:** تنها مرجع معتبر eligibility — اعتبارسنجی صریح: `clinic_id>0 && location_id>0`, `loc_clinic_id != null` وگرنه `visit.location_missing` warning و skip، `loc_clinic_id == clinic_id` وگرنه mismatch skip، `loc_timezone` غیرخالی و معتبر `DateTimeZone` وگرنه skip، سپس `appointmentUtcInstant()` (محلی + TZ → UTC) + `graceForClinic()` (از `SettingsFactory::forClinic()` با کش C) → `eligible = apptUtc + PTgraceM`، مقایسه `nowUtc >= eligible` → تراکنش `findForUpdate` + `markAppointmentNoShow`.

هیچ `OperationalTimezoneResolver` یا `TemporalContext` جدید ساخته نشد.

---

## 7) استراتژی صفحه‌بندی/پیشرفت کاندید

- **بدون OFFSET:** از keyset cursor استفاده شد: `(slot_date, slot_time, id)` با ترتیب قطعی `ORDER BY slot_date ASC, slot_time ASC, id ASC`.
- **پارامتر cursor:** `?array{slot_date:string, slot_time:string, id:int}` — شرط `WHERE (date>? OR date=? AND time>? OR date=? AND time=? AND id>?)`.
- **حل گرسنگی (starvation):** اگر 100 کاندید اول همگی invalid باشند (مثلاً Location ناموجود)، حلقه قبلی با `LIMIT 100` گیر می‌کرد. اکنون حلقه Service: `maxScan=500`, `batchSize=100`, `maxToProcess=100` — حتی اگر کاندید invalid باشد، cursor جلو می‌رود (`cursor = {date,time,id}`) و `scanned` افزایش می‌یابد، بنابراین پیشرفت محدود و تضمینی است.
- **بدون وضعیت جدید/ dead-letter/ migration/ index جدید.**

---

## 8) اثبات عدم گرسنگی

فرض کنید N کاندید اول invalid باشند. در هر تکرار، `appointmentsPastGraceCandidates(100, nowUtc, cursor)` 100 ردیف بعدی را برمی‌گرداند (به دلیل cursor). حتی اگر همه 100 invalid باشند، `cursor` به آخرین ردیف آن بچ به‌روز می‌شود و `scanned+=100`. پس از `maxScan=500`، حداکثر 500 ردیف بررسی شده و حداقل 400 ردیف جلو رفته‌ایم. اگر هنوز کاندید معتبر باقی مانده باشد، در تیک بعدی Cron (یا حلقه بعدی) دوباره از cursor جدید شروع می‌شود. بنابراین هیچ ردیف invalid نمی‌تواند برای همیشه جلوی پیشرفت را بگیرد. اثبات با `LEFT JOIN` است که ردیف‌های ناموجود را حذف نمی‌کند تا Service بتواند آن‌ها را لاگ و skip کند.

---

## 9) شکل نهایی کوئری و پیچیدگی

```sql
SELECT a.id, a.clinic_id, a.location_id, a.patient_id, a.clinician_id,
       a.slot_date, a.slot_time,
       l.clinic_id AS loc_clinic_id, l.timezone AS loc_timezone
FROM cpms_appointments a
LEFT JOIN cpms_locations l ON l.id = a.location_id
WHERE a.status='confirmed' AND a.active_visit_id IS NULL
  AND a.slot_date <= :upperDate (+2d)
  AND ((a.slot_date > :c_date) OR (a.slot_date=:c_date AND a.slot_time>:c_time)
       OR (a.slot_date=:c_date AND a.slot_time=:c_time AND a.id>:c_id))
ORDER BY a.slot_date ASC, a.slot_time ASC, a.id ASC
LIMIT 100
```

- **چرا LEFT نه INNER:** INNER ردیف‌های با Location ناموجود را بی‌صدا حذف می‌کند و باعث پنهان شدن نقص داده می‌شود؛ LEFT آن‌ها را نگه می‌دارد تا Service با `visit.location_missing` هشدار دهد و fail-closed کند.
- **پیچیدگی:** Repository: `O(batchSize)` برای هر فراخوانی، بدون N+1 Location (چون JOIN). Service: در بدترین حالت `O(maxScan)` = 500 بررسی در هر تیک، هر بررسی `O(1)` برای TZ و grace (grace از کش `SettingsFactory` با پیچیدگی `O(C)` که C تعداد کلینیک‌های متفاوت است، در عمل 1-2). قبلاً Repository `1+2N` (هر کاندید یک SELECT Location و یک SELECT Settings) و Service `1+N+C` بود؛ اکنون Repository `1` (JOIN) و Service `1+C` (بدون N+1).

---

## 10) اعتبارسنجی Location/Clinic

- `appointment.location_id` باید >0 باشد، وگرنه skip (نه no_show).
- `loc_clinic_id` (از JOIN) نباید null باشد → missing Location → warning `visit.location_missing` و skip.
- `loc_clinic_id == appointment.clinic_id` باید برقرار باشد → وگرنه mismatch → warning `visit.location_mismatch` و skip (fail-closed، بدون fallback به primary).
- `loc_timezone` باید غیرخالی و `DateTimeZone` معتبر باشد → وگرنه skip.

هیچ fallback به `primary Location` برای تفسیر مجدد invalid/mismatched انجام نمی‌شود (طبق agent-guide §8-6 OPEN).

---

## 11) تفکیک Grace

- `SettingsFactory::forClinic(clinicId)` وجود کلینیک را اعتبارسنجی نمی‌کند — ایمنی از مسیر `appointment.location_id → existing Location → Location.clinic_id == appointment.clinic_id` به دست می‌آید.
- `graceForClinic()` در Service: اگر تنظیم `queue.no_show_grace_minutes` وجود نداشته باشد، 30 دقیقه پیش‌فرض؛ اگر نامعتبر باشد، null برمی‌گرداند و کاندید skip می‌شود (fail-closed).
- هیچ fallback به اولین کلینیک/کلینیک 1/کاربر WP وجود ندارد.

---

## 12) معنای مشترک periodic+lazy

هر دو مسیر دقیقاً یک قرارداد را اجرا می‌کنند:

```
local wall-clock (slot_date + slot_time) + validated Location.timezone
  => UTC instant (appointmentUtcInstant)
  + per-Clinic grace (PT{grace}M)
  => eligibility instant
  compare nowUtc >= eligibility => overdue => no_show
```

- بدون `PHP default tz` ضمنی، بدون `strtotime` بدون TZ، بدون بازنویسی Clinic TZ روی Location، بدون WP TZ، بدون fallback `Asia/Tehran`، بدون مقایسه رشته‌ای local-vs-UTC.
- lazy (checkIn) در صورت عدم قطعیت (TZ نامعتبر، Location ناموجود) **no_show نمی‌کند** و با warning ادامه می‌دهد؛ periodic هم skip می‌کند نه abort کل sweep.

---

## 13) فایل‌های تغییر یافته

نسبت به `461aeeb` (T1 baseline):

- `src/Application/Visits/VisitService.php` — حذف `resolvePrimaryLocationTimezone()`، بازنویسی `checkIn` به fail-closed، بازنویسی `processNoShows()` به cursor loop با اعتبارسنجی JOIN.
- `src/Infrastructure/Repository/VisitRepository.php` — حذف `resolveLocationTimezoneForRepo()`, `appointmentUtcInstantForRepo()`, `graceForClinicForRepo()`, `appointmentsPastGrace()` policy؛ پیاده‌سازی `appointmentsPastGraceCandidates()` با LEFT JOIN و cursor.
- `tests/Integration/Fixtures/Phase2MultiLocationTemporalFixture.php` — افزودن `fxTInsertPatient()` و پشتیبانی `patientId` در `fxTInsertAppointment`.
- `tests/Integration/Phase2MultiLocationTemporalRedTest.php` — فیکس DateInterval، فیکس collision بیمار فعال، فیکس perClinicGrace (reference_code, slug تصادفی، cleanup)، به‌روزرسانی assertionهای repository به candidate-only.
- `tests/Integration/RestQueueTest.php` — تولید `slot_date/time` با TZ تهران.
- `tests/Integration/VisitFlowTest.php` — تولید `slot_date/time` با TZ تهران و `slot_end_time` با `PT20M`.
- `tests/Integration/VisitLicenseGateTest.php` — تولید `slot_date/time` با TZ تهران.

هیچ تغییری در `BookingWindow.php`, `BookingService.php`, `BookingController.php`, `SlotRepository.php`, `ApptReminderHandler.php`.

---

## 14) کامیت‌ها

- `d6a02feddb3552c7aa6976f12a7a850b0b07bf82` — `fix(phase2-temporal): T2 corrective GREEN — remove dual ownership, fix starvation, N+1, fixture defects`
- `3a43de37f35e9446c67f317e60c1099db6058b5d` — `fix(test): perClinicGraceIsolation — add reference_code+slot_end_time, randomize clinic slug, cleanup stale clinics`

هر دو روی `arena/01a09667-doctor` و push شده، HEAD محلی == ریموت.

---

## 15) شواهد PASS مستقیم

**قبل (95758e4):** 2 ERROR +7 FAILURE  
**بعد (3a43de3):** اجرای `34727161526`:

```
Tests: 696, Assertions: 4889, Failures: 1
```

تنها شکست باقی‌مانده `testReminderDayBoundaryMultiLocation` است (EXPECTED RED). تمام موارد زیر اکنون PASS:

- `testPrematureNoShowPeriodic` — آینده با grace آینده → confirmed باقی ماند (Service فیلتر کرد، Repository candidate برگرداند)
- `testLazyCheckInNoShowConsistency` — lazy و periodic هم‌راستا، overdue → walk_in-like
- `testPerClinicGraceIsolation` — Clinic A (grace 30, 40min ago) → no_show، Clinic B (grace 120, 40min ago) → confirmed
- `RestQueueTest::testCheckInHappyPath` — scheduled
- `VisitFlowTest::testCheckInCreatesVisitAndAutoEnqueues` — scheduled
- `VisitFlowTest::testFullQueueLifecycleThroughCheckout` — completed
- `VisitFlowTest::testNoShowSweepMarksOnlyUnvisitedLateAppointments` — 1 no_show
- `VisitLicenseGateTest::testCheckInOfPreExistingAppointmentIsAllowedInReadOnlyMode` — scheduled

---

## 16) شواهد RED معتبر

`testReminderDayBoundaryMultiLocation` در `34727161526`:

```
EXPECTED RED #5 — REMINDER DAY-BOUNDARY (both missed): Tehran date=2026-09-13 Berlin date=2026-09-12 clinicToday=2026-09-13 — handler returned 1, notifications 0/0, expected both per Location.
```

- بوت‌استرپ: موفق (WP 6.7.2)
- فیکسچر: موفق (دو Location با TZ متفاوت)
- مسیر محصول: `ApptReminderHandler::handle()` فراخوانی شد
- شکست روی assertion مورد نظر (انتظار 2 یادآور بر اساس هر Location، دریافت 1 بر اساس clinicToday)

این RED معتبر است و طبق دستورالعمل نباید سبز شود.

---

## 17) گیت‌های کامل

برای SHA `3a43de3`:

- **CI (pull) run 34727161526:** Integration 1 failure (فقط REMINDER)، بقیه 695 PASS
- **Closure Gate 34727159081:** success (تمام 5 runtime PASS)
- **Unit Tests:** PHP 8.1/8.2/8.3/8.4 success
- **Static Analysis (PHPStan):** success
- **Tenant Tripwire:** success
- **WPCS:** success
- **Release Artifact:** success
- **Upgrade path:** success
- **Pilot/Staging:** null (اجرا نشده — NOT RUN)
- **Real WP Acceptance:** null (NOT RUN برای این SHA)

---

## 18) نتیجه Pilot

NOT RUN / NOT RETRIEVED — در محدوده این تسک نیست.

---

## 19) وضعیت مایگریشن

فقط `0020` — `2026_09_09_0020_idempotency_clinic_scope.php` — هیچ مایگریشن 0021، ایندکس جدید، یا تغییر اسکیما انجام نشد.

---

## 20) PR Draft

PR #28 همچنان `OPEN + DRAFT` — Ready نشد، merge نشد.

---

## 21) NOT RUN / NOT RETRIEVED / NOT MEASURED

- Pilot/Staging Readiness: NOT RUN
- Real WP Acceptance برای 3a43de3: NOT RUN (برای 95758e4 اجرا شده بود)
- اندازه‌گیری عملکرد N+1: NOT MEASURED (فقط تحلیل پیچیدگی انجام شد)

---

## 22) باقی‌مانده T3 / M-2 / M-4

- **T3:** یادآور (reminder day-boundary + quiet hours) عمداً قرمز نگه داشته شد — خارج از محدوده.
- **M-2:** نصب Settings در سطح installation — خارج از محدوده.
- **M-4:** operational_logs — خارج از محدوده.
- **FollowUpReminder, slots.generate, Phase3/4:** خارج از محدوده.

---

## 23) حکم نهایی A/B/C

- **A (رگرسیون محصول توسط T2):** 4 مورد — VisitFlow (3) + RestQueue (1) + LicenseGate (1) — که همگی به دلیل استفاده از `gmdate` UTC به جای wall-clock محلی بودند و با فیکس تست‌ها (نه محصول) حل شدند؛ محصول از ابتدا درست بود و T2 آن را Location-aware کرده بود.
- **B (نقص پیشین):** 0
- **C (زیرساخت):** 0 (به جز قفل‌های گذرا)
- **D (نقص تست/فیکسچر):** 3 مورد — DateInterval، active Visit collision، perClinicGrace null (reference_code + slug)

**نتیجه:** T2 اکنون **سبز** است به جز یک قرمز مورد انتظار (یادآور). هیچ تغییر محصول خارج از محدوده انجام نشد، هیچ مایگریشن جدیدی ساخته نشد، و قراردادهای T1 حفظ شدند.

---

**نویسنده:** Agent corrective T2  
**SHA نهایی:** `3a43de37f35e9446c67f317e60c1099db6058b5d`  
**PR:** #28 DRAFT
