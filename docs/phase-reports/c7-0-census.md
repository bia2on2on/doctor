# C7-0 — Tenant Ownership Evidence Foundation (census, **not** a refactor)

> **وضعیت:** C7-0 = گردآوری شواهد. **هیچ رفتار تولیدی در این پاس تغییر نکرده است.**
> بدون Migration، بدون `AuthorizationService`، بدون RBAC نهایی، بدون افزودن مکانیکی
> پارامتر Clinic به امضای مخازن. **C7 (پیاده‌سازی) آغاز نشده است.**
>
> **چک‌پوینت main:** `248ca1049b49ea8f82b622744e39cf5b391a6838`
> **Schema:** `2026_09_09_0020` — فایل `0021` نه ساخته شده و نه تصویب شده.

---

## ۰. اصل حاکم بر این سند (تصمیم مالک/معمار)

نبودِ پارامتر Clinic در امضای یک متد مخزن، **به‌خودی‌خود نقص امنیتی نیست**.
`find($id)` سراسری وقتی نقص است که **حداقل یک مسیر تولیدیِ قابل‌دسترس** وجود داشته
باشد که بدون اعتبارسنجی مالکیت به آن برسد. بنابراین هر ردیف این ماتریس با زنجیرهٔ
کامل زیر ارزیابی شده است:

```
ورودی (REST route / Job) → منبع Scope مورد اعتماد → سرویس کاربردی
   → متد مخزن → محل اعتبارسنجی مالکیت → کوئری خواندن/نوشتن
```

دو قاعدهٔ تکمیلی که در این پاس اعمال شد:

1. **حفاظت فقط در مرز REST کافی نیست** اگر caller داخلی/پس‌زمینه بتواند دورش بزند.
2. **مسیر مرده ≠ نقص.** متدی که هیچ فراخوانِ تولیدی ندارد، در ردهٔ `D-dead` ثبت
   می‌شود، نه `C`.

### طبقه‌بندی محلیِ این سرشماری (A/B/C/D)

| رده | معنی |
|---|---|
| **A** | خودِ مخزن با predicate کلینیک دامنه‌بندی می‌کند. |
| **B** | مخزن سراسری است ولی سرویس، مالکیت را با منبع Clinic **مورد اعتماد** و **fail-closed** اجبار می‌کند (محل دقیق کد ثبت شده). |
| **C** | نقص تأییدشده: خواندن/نوشتن بین‌کلینیکی از یک مسیر تولیدیِ قابل‌اجرا. |
| **D** | حل‌نشده / بدون شواهد قطعی (شامل `D-dead` = مسیر بدون فراخوانِ تولیدی). |

> ⚠ این A/B/C/D **کاملاً جدا** از طبقه‌بندی خطاهای پروژه است
> (که در آن A=رگرسیون جاری، B=نقص محصولیِ از پیش‌موجود، C=زیرساخت،
> D=نقص تست/harness). هرجا از طبقه‌بندی پروژه استفاده شده، صریحاً «طبقه‌بندی
> خطای پروژه» نوشته شده است.

### محدودیت اجرایی این پاس (صادقانه)

محیط این جلسه **PHP/Composer/MySQL ندارد** (نصب هم ممکن نبود: mirrorهای apt و
همهٔ میزبان‌های باینری استاتیک با خطای TLS شکست خوردند). بنابراین:

- هیچ ادعای «تست اجرا شد و قرمز/سبز بود» در این سند وجود ندارد مگر آنکه به یک
  run واقعیِ GitHub Actions ارجاع داده شده باشد.
- شواهدِ ردهٔ `C` در این سند از نوع **مسیر قطعیِ اثبات‌شونده در کد** است
  (deterministic proving path) — یعنی زنجیرهٔ کامل فراخوانی نشان داده شده و هیچ
  گِیت مالکیتی در آن وجود ندارد. جایی که فقط «شک» بوده، رده `D` ثبت شده است.
- دستورهای بازتولید در §۸ آمده‌اند.

---

## ۱. ماتریس خروجی C7-0

ستون «شاهد» یا نام تستِ موجود است، یا مختصات دقیق کد (فایل:خط).

| # | موجودیت / مسیر | متد مخزن | فراخوانِ تولیدی | منبع Clinic مورد اعتماد | محل اجبار مالکیت | شاهد | رده | شدت | برش پیشنهادی C7 |
|---|---|---|---|---|---|---|---|---|---|
| 1 | **فایل بالینی** — stream / list / softDelete | `MedicalFileRepository::find($id)` (سراسری) | `MedicalFileService::stream()` 133 ← `MedicalFilesController` | `trustedClinicId()` 396 → `ScopeContext` → `App::scope()` → `TrustedClinicEstablisher::establish()` 410 | `assertStaffClinic()` 427 + `patientClinicId()` 451 — با **404 parity** (عدم شمارش) | `ClinicTenantIsolationTest` (۲۷ تست) | **B** | — | بدون اقدام — **الگوی مرجع C7** |
| 2 | **بیماران** | فراخوان‌های دامنه‌بندی‌شدهٔ مخزن | `PatientService` 140/152/179/224/300 | `App::scope()->clinicId` | مقایسهٔ درون‌خطی + 404 | `ClinicTenantIsolationTest` | **B** | — | بدون اقدام |
| 3 | **نوبت‌ها** — reschedule / cancel | `AppointmentRepository::findForUpdate($id)` | `BookingService::reschedule()` 443، `cancel()` 693 | `slot['clinic_id']` + `requireClinician()` 840 | `userHasPatient()` + رویداد Audit با کد FORBIDDEN | `ClinicTenantIsolationTest`، `TenantIsolationGapTest` MT-25 | **B** | — | بدون اقدام |
| 4 | **اسناد/صفحات دست‌نویس** | متدهای bare-`$id` (`requireDocument` 553، `requirePage` 566) | `HandwritingService` | هویت پزشکِ مالک ویزیت | `requireOwnVisit()` 584 — روی **همهٔ** مسیرها | خوانش کد 553–600 | **B** | — | بدون اقدام |
| 5 | **نسخه — نهایی‌سازی/ابطال** | `findForUpdateForClinic()` 75، `updateForClinic()` 87 | `ClinicalService` 386–453 | `App::scope()->clinicId` | predicate در خود کوئری + بررسی affected-rows | خوانش کد 386–453 | **A** | — | بدون اقدام |
| 6 | **گذارهای وضعیت ویزیت** | `VisitRepository::find()` | `VisitService::transition()` 193 → `applyTransition()` 227 | هویت پزشک | `guardDoctorTransitionOwnership()` ~997 (403 + Audit) | خوانش کد 990–1030 | **B**\* | کم | \*پارامتر `$forceRole` مسیر دورزدن دارد؛ فقط از فراخوان داخلی — ثبت شد، بدون فراخوان REST |
| 7 | **صف/امروز/فید بی‌درنگ** | `queueFor` / `statsFor` / `lastEventId` | `VisitService` | `queueClinicId()` | `requireQueueReader()` 809 + باریک‌سازی با `queueScopeClinicianId()` ~823 | `ClinicTenantIsolationTest` بخش B | **A/B** | — | بدون اقدام |
| 8 | **هویت بیمار / Organization** | مخزن دامنه‌بندی‌شده با Organization | `PatientIdentityService` | `$organization_id` صریح، cross-check شده | `require_identity_in_org` با **NOT_FOUND parity**؛ `clinical_records_for_clinic` fail-closed | خوانش کامل سرویس | **B / SAFE** | — | بدون اقدام — §۵ |
| 9 | **کلیدهای Idempotency** | `Idempotency::check/complete/release/find` | JobQueue + REST | `int $clinicId` صریح | UNIQUE پنج‌ستونی (Migration 0020) + `AND clinic_id = %d` | Migration 0020 + خوانش `Idempotency.php` | **A / SAFE** | — | بدون اقدام — §۷ |
| 10 | **اعلان‌ها — انتشار و خواندن** | `forPatient` / `forUser` / `insertNotification` 265 | `NotificationService` | پیوند بیمار یا `App::scope()` | نخ‌کشی clinic در کوئری | `TenantIsolationGapTest` MT-24 | **A/B** | — | بدون اقدام |
| 11 | اعلان‌ها — `markRead` / `markAllRead` | predicate فقط بر گیرنده | `NotificationService` | هویت گیرنده | کاربر فقط اعلان‌های خودش را می‌بیند ⇒ نشتی وجود ندارد | خوانش کد | **B** | خیلی کم | بدون اقدام |
| 12 | `VisitService::history()` 515 / `getVisit()` 538 | `visits->find($id)` — **بدون** predicate کلینیک | **هیچ** (`grep -rn "getVisit\|->history(" src/Rest/ src/Admin` = صفر) | ندارد | فقط `requireQueueReader()` (بررسی capability، بدون predicate) | grep بالا | **D-dead** | — | اگر روزی به REST وصل شود، **اول** باید دامنه‌بندی شود |
| 13 | **برنامهٔ هفتگی / استثناها — update و delete** | `ScheduleRepository::find/update/delete/findException/deleteException($id)` — همه bare-`$id` | `ScheduleService::update()` 99، `delete()` 124، `deleteException()` — از `ScheduleController` مسیرهای 60 و 113 | **هیچ** | فقط `configMutation()` 185 = nonce + capability `cpms_config`. `create()` 64 دامنه‌بندی دارد (`requireClinician()`)، ولی `update`/`delete` **ندارند** | §۲ زیر | **C** | **بالا** | S1 |
| 14 | **شماره‌گذاری نسخه** | `nextPrescriptionNumber()` 182 — `MAX+1` سراسری، بدون قفل | `ClinicalService::createPrescription()` 321 (شماره‌گذاری در 340، **بدون** transaction) | ندارد (سراسری بودن عمدی نیست) | ندارد | §۴ زیر | **C** | متوسط | S3 |
| 15 | **اجرای Job — پین‌شدن Settings** | — | `App::dispatcher()` 1002 + `JobsDispatcher::tick()` | **هیچ** — هیچ tenant در `cpms_jobs` نیست و dispatcher هرگز scope را بازسازی نمی‌کند | ندارد | §۳ زیر | **C** | **بالا** | S2 |
| 16 | **نوشتن‌های مالی بر پایهٔ ID** | `InvoiceRepository::findForUpdate($id)` و `PaymentRepository::findForUpdate($id)` — **بدون** predicate کلینیک | `recordPayment()` 334→360، `addAdjustment()` 605→621 (هر دو از `requireOpenInvoiceForUpdate()` 890)؛ `voidPayment()` 440؛ `refundPayment()` 523. مسیرهای REST: `FinanceController` 91 / 119 / 136 / 154 | **هیچ** | فقط `requireCap()` سراسری (`PAYMENT_CREATE`/`PAYMENT_VOID`/`PAYMENT_REFUND`/`INVOICE_ADJUST`) + بررسی status | §۶ زیر | **C** | **بالا** | S4 |
| 17 | خواندن‌های مالی بر پایهٔ ID | `invoiceView($id)` | `findInvoiceForActor()` 862، `invoiceForVisit()` 874 | **هیچ** | فقط `requireCap(INVOICE_READ)` | §۶ زیر | **C** | متوسط | S4 |

---

## ۲. C — برنامهٔ هفتگی: update/delete بدون دامنهٔ کلینیک

**زنجیرهٔ اثبات (کد واقعی، بدون تفسیر):**

```
POST/PATCH /clinic/v1/config/schedules/{id}      ScheduleController.php:60
   permission_callback → configMutation()        ScheduleController.php:185
        └─ requireNonce() + requireCap(CONFIG)   ← فقط capability، بدون Clinic
   callback → ScheduleService::update()          ScheduleService.php:99
        └─ $this->schedules->find($id)           ScheduleRepository.php:44  ← بدون clinic_id
        └─ $this->schedules->update($id, $data)  ScheduleRepository.php:101 ← بدون clinic_id
DELETE /clinic/v1/config/schedule-exceptions/{id} ScheduleController.php:113
   → ScheduleService::deleteException() → deleteException($id)  Repository:150
```

**چرا این نقص است:** `create()` (خط 64) عمداً از `requireClinician()` (خط 358)
کلینیک را از ردیف پزشک می‌گیرد و در `clinic_id` می‌نشاند — یعنی مدلِ مالکیت در
همین سرویس تعریف شده است. اما `update()`/`delete()` هرگز آن را دوباره تأیید
نمی‌کنند: یک کاربر با capability `cpms_config` در کلینیک X می‌تواند با ارسال
شناسهٔ برنامهٔ کلینیک Y آن را ویرایش یا حذف کند. جدول `cpms_schedules` ستون
`clinic_id` **دارد** (`2026_09_05_0001_initial_schema.php`) — پس این کمبود
داده‌ای نیست، کمبود predicate است.

**اثر جانبی:** `update`/`delete` در پایان `regenerate((int) $current['clinician_id'])`
را صدا می‌زنند که Slotهای خالیِ آیندهٔ آن پزشک را حذف می‌کند — یعنی نوشتنِ
مخرب در کلینیک قربانی، نه فقط تغییر یک ردیف.

**رده:** C — شدت **بالا** (نوشتن بین‌کلینیکی، پیکربندی عملیاتی).

---

## ۳. C — پین‌شدن Settings در اجرای Job (چندکلینیکی)

**زنجیرهٔ اثبات:**

```
App::dispatcher()                     App.php:1002
  └─ if (self::$dispatcher === null)  ← گراف handler فقط یک‌بار ساخته می‌شود
  └─ $settings = self::settings();    App.php:839
        └─ new Settings(db, self::scope()->clinicId, audit)   ← clinicId همان‌جا منجمد می‌شود
  └─ ->register('appt.reminder', new ApptReminderHandler($db, $settings, ...))
  └─ ->register('fu.reminder',   new FollowUpReminderHandler($db, $settings, ...))
  └─ ->register('slots.generate', new SlotsGenerateHandler($db, $settings, $op))

JobsDispatcher::tick()                JobsDispatcher.php:38
  └─ claim() → json_decode(payload) → $handler($payload)
     ← هیچ App::replaceExplicitScope() و هیچ Settings::flushCache() در این حلقه نیست
```

`cpms_jobs` (initial_schema 657–674) **ستون `clinic_id` ندارد** و
`JobQueue::enqueue()` هیچ هویت tenant در payload نمی‌گذارد. پس در نصب چندکلینیکی،
یک نمونهٔ `Settings` که به کلینیکِ لحظهٔ ساخت گراف پین شده، برای **همهٔ** ردیف‌های
همهٔ کلینیک‌ها استفاده می‌شود.

**پیامدهای مشخصِ ردیابی‌شده:**

| مسیر | چه چیزی از Settings پین‌شده خوانده می‌شود | پیامد |
|---|---|---|
| `ApptReminderHandler::localToday()` | `settings->clinicTimezone()` (`Settings.php:282`، `WHERE id = %d` با `clinicId` پین‌شده) | «امروز/فردا» با timezone کلینیک اشتباه محاسبه و روی ردیف‌های همهٔ کلینیک‌ها اعمال می‌شود ⇒ پنجرهٔ یادآوری نادرست |
| `FollowUpReminderHandler` | همان الگو | همان |
| `SlotsGenerateHandler` | `settings->get('booking.max_future_days')` | افق تولید Slot یک کلینیک روی همهٔ کلینیک‌ها |
| `NotificationService::smsQuietHoursOpen()` 233 | `clinicTimezone()` پین‌شده | Quiet Hours با timezone اشتباه ⇒ SMS در ساعت ممنوعِ کلینیک دیگر |
| `NotifDispatchHandler` | `notif.archive_days` | سیاست نگهداری یک کلینیک روی همه |
| `SmsService::sendEvent()` 65 / `dispatchMessage()` | `sms.provider` / `sms.sender` / `sms.advanced` / اعتبارنامه‌ها از نمونهٔ پین‌شده | **اعتبارنامهٔ SMS یک کلینیک برای ارسال پیام کلینیک دیگر** |

**نکتهٔ مهمِ صداقتی:** `sendEvent()` `clinic_id` را **به‌درستی** از ردیف
(`$row['clinic_id']`) می‌گیرد و در `INSERT` و در `dedupe_key` می‌نشاند — پس
حسابداری پیام درست است. آنچه غلط است، **provider/sender/credential** است که از
`$this->settings` پین‌شده می‌آید. این دو را نباید با هم اشتباه گرفت.

**چرا تست‌های موجود این را رد نمی‌کنند:** در `TenantIsolationGapTest`
تست‌های `testReminderHandlerUsesClinicFromRowNotHardcoded`،
`testFollowUpHandler…` و `test*SelectHasNoClinicPredicate` **assertion روی متن
منبع** هستند و خودشان با برچسب «supplemental — NOT primary runtime evidence»
مستند شده‌اند. همچنین `testSettingsAreIsolatedAcrossClinics` (680) و
`testSettingsCacheDoesNotLeakAcrossClinics` (705) فقط به این دلیل سبزند که
صریحاً `App::replaceExplicitScope()` + `Settings::flushCache()` را صدا می‌زنند —
کاری که **مسیر Job هرگز انجام نمی‌دهد**. بنابراین آن تست‌ها این یافته را نقض
نمی‌کنند.

**«Worker سراسری» عمدی است و نقص نیست** — نقص، *نبودِ بازسازی زمینهٔ tenant در
لحظهٔ اجرا* است.

**Location به‌عنوان منبع حقیقت:** طبق invariant پروژه، timezone عملیاتی از
Location می‌آید. این سند **هیچ معنای مجوزدهیِ user-to-Location معرفی نمی‌کند** —
فقط ثبت می‌کند که مقدار timezone نباید از یک نمونهٔ Settings پین‌شده بیاید.

**رده:** C — شدت **بالا**.

---

## ۴. C — شماره‌گذاری نسخه (Prescription numbering)

**شواهد (schema + کد + فراخوان):**

- Schema: `cpms_prescriptions` در `2026_09_05_0001_initial_schema.php:413–433`
  دارای **`UNIQUE u_rx_number` سراسری** روی `prescription_number` (نه
  `(clinic_id, prescription_number)`) — برخلاف الگوی مالی که
  `u_inv_number (clinic_id, invoice_number)` و `u_pay_number (clinic_id, payment_number)` دارد.
- `PrescriptionRepository::nextPrescriptionNumber()` خط 182:
  `SELECT MAX(CAST(SUBSTRING_INDEX(prescription_number,'-',-1) AS UNSIGNED)) FROM cpms_prescriptions`
  — **بدون** `WHERE clinic_id`، **بدون** `FOR UPDATE`، **بدون** قفل نام‌دار.
  کامنت خودِ متد می‌گوید «داخل Transaction فراخوانی شود».
- تنها فراخوان: `ClinicalService::createPrescription()` خط 321، شماره‌گذاری در خط
  340 — و این مسیر **داخل transaction نیست** (برخلاف `FinanceService::recordPayment`
  که هم `transactional()` دارد و هم `lockClinic()`).

**دو یافتهٔ متمایز:**

1. **جفت‌شدگی توالی بین کلینیک‌ها (قطعی):** شماره‌ها از یک شمارندهٔ سراسری
   می‌آیند. کلینیک B با ثبت نسخه، شمارهٔ بعدیِ کلینیک A را جلو می‌برد. این
   نشت اطلاعات حجمی است (کلینیک A از پرشِ شماره می‌فهمد کلینیک دیگری چقدر نسخه
   نوشته) و «هر کلینیک توالی خودش را دارد» را نقض می‌کند.
2. **مسابقهٔ همزمانی (race):** `MAX+1` بدون قفل و بدون transaction + UNIQUE
   سراسری ⇒ دو ثبت همزمان می‌توانند به یک شماره برسند و یکی با خطای duplicate-key
   شکست بخورد. این با یا بدون چندکلینیکی بودن رخ می‌دهد.

**این SAFE نیست.** الگوی درست در همین مخزن موجود است (مالی: قفل + توالیِ
per-clinic) و اینجا اعمال نشده.

**هیچ Migration پیشنهاد/ایجاد نشد.** اصلاح واقعی نیازمند تصمیم مالک است، چون
تغییر `u_rx_number` به کلید مرکب یا افزودن قفل، هر دو تصمیم معماری‌اند (§۹).

**رده:** C — شدت متوسط (یکپارچگی داده + نشت حجمی؛ نه نشت محتوای بالینی).

---

## ۵. C — نوشتن/خواندن مالی بر پایهٔ ID (یافتهٔ جدید این پاس)

PR #17 هفت مسیر مالیِ **پین‌شده به Clinic ID 1** را اصلاح کرد. آن اصلاح، این
مسئلهٔ متفاوت را پوشش نمی‌دهد: مسیرهای مالیِ **مبتنی بر شناسهٔ ورودی**.

```
POST /clinic/v1/invoices/{id}/payments        FinanceController.php:91
POST /clinic/v1/payments/{id}/void            FinanceController.php:119
POST /clinic/v1/payments/{id}/refund          FinanceController.php:136
POST /clinic/v1/invoices/{id}/adjustments     FinanceController.php:154

FinanceService::recordPayment() 334
  └─ requireCap(PAYMENT_CREATE)                        ← capability سراسری
  └─ transactional(...)
       └─ requireOpenInvoiceForUpdate($invoiceId) 890
            └─ $this->invoices->findForUpdate($invoiceId)   ← بدون predicate کلینیک
            └─ بررسی status ∈ {open, partial}               ← تنها گِیت
       └─ $invoiceClinicId = (int) $invoice['clinic_id'];   ← از خودِ ردیفِ قربانی
       └─ lockClinic($invoiceClinicId); nextPaymentNumber($invoiceClinicId)

FinanceService::voidPayment() 440
  └─ requireCap(PAYMENT_VOID)
  └─ $this->payments->findForUpdate($paymentId)         ← بدون predicate کلینیک
  └─ سپس invoices->findForUpdate($payment['invoice_id']) ← باز هم بدون predicate

FinanceService::addAdjustment() 605 → requireOpenInvoiceForUpdate() 621  ← همان الگو
FinanceService::refundPayment() 523 → payments->find/findForUpdate($paymentId)
```

**نکتهٔ ظریف و مهم:** خط `$invoiceClinicId = (int) $invoice['clinic_id']` **ظاهر
درستی دارد** — عملیات در کلینیکِ خودِ فاکتور اجرا می‌شود، پس شماره‌گذاری و قفل
درست‌اند و tripwire هم چیزی نمی‌بیند. اما دقیقاً همین است که مسئله را می‌سازد:
کلینیک از **ردیفی که مهاجم انتخاب کرده** گرفته می‌شود، نه از Scope مورد اعتمادِ
درخواست. سرویس هرگز نمی‌پرسد «آیا این فاکتور به کلینیک فعلیِ من تعلق دارد؟».

مقایسه با همین کلاس: `listServices()` (خط 77) و `summary()` (خط 782) از
`trustedClinicId()` (خط 1012) استفاده می‌کنند و fail-closed هستند. یعنی ابزار
لازم **در همین فایل** موجود است و در مسیرهای مبتنی بر ID به‌کار نرفته.

**اثر:** کاربری با capability مالی در کلینیک X می‌تواند با شناسهٔ فاکتور/پرداختِ
کلینیک Y: پرداخت ثبت کند، پرداخت را باطل یا مسترد کند، فاکتور را اصلاح کند، و از
راه `findInvoiceForActor` نام بیمار و MRN کلینیک Y را بخواند (پاسخ `invoiceView`
شامل مشخصات بیمار است).

**رده:** C — شدت **بالا** (نوشتن مالی + نشت PII بیمار).

---

## ۶. موارد SAFE (تغییری لازم نیست)

- **Organization / هویت بیمار (§۹ درخواست مالک):** Organization ID همیشه از رابطهٔ
  مورد اعتماد Clinic→Organization می‌آید؛ هیچ‌جا `clinic_id` به‌جای
  `organization_id` جایگزین نشده؛ هویت سطح Organization **هیچ دید بالینی ضمنی**
  در سطح Clinic نمی‌دهد (`clinical_records_for_clinic` fail-closed است)؛
  مدیریت موبایل تکراری **غیر مخرب** است. **SAFE — هیچ workflow جدیدی سیم‌کشی نشد.**
- **JobQueue / Idempotency (§۱۰ درخواست مالک):** Migration 0020 کلید idempotency
  را با کلینیک دامنه‌بندی کرده (UNIQUE پنج‌ستونی + `AND clinic_id = %d`). دامنهٔ
  کلید **SAFE** است. Worker سراسری **عمدی** است و نقص نیست. دو کمبود واقعی که
  ثبت می‌شوند و ذیل ردیف ۱۵ ماتریس رسیدگی می‌شوند: (الف) payload جاب هیچ هویت
  tenant حمل نمی‌کند، (ب) اجرا هرگز زمینهٔ tenant مورد اعتماد را بازنمی‌سازد.
- **قفل Tick:** `GET_LOCK('cpms_jobs_tick')` در `App::runTick()` ~1079 برای هر دو
  مسیر WP-Cron و CLI یکسان است — درست.

---

## ۷. برش‌های پیشنهادی C7 (فقط بر پایهٔ شواهد تأییدشدهٔ بالا)

| برش | دامنه | مبنای شواهد | نیازمند تصمیم مالک/ADR؟ |
|---|---|---|---|
| **S1** | دامنه‌بندی نوشتن‌های برنامهٔ هفتگی: `ScheduleService::update/delete/deleteException` مالکیت را از `requireClinician()` (همان الگوی `create`) دوباره تأیید کنند؛ 404 parity | §۲ | **خیر** — الگو در همان فایل موجود است |
| **S2** | بازسازی زمینهٔ tenant در اجرای Job: هویت tenant در payload/ردیف صف + `replaceExplicitScope` per-job در `JobsDispatcher` | §۳ | **بله** — ADR لازم است (شکل حمل tenant؛ ستون در `cpms_jobs` = Migration ⇒ تصویب مالک) |
| **S3** | شماره‌گذاری نسخه: توالی per-clinic + قفل، هم‌راستا با الگوی مالی | §۴ | **بله** — تغییر `u_rx_number` نیازمند Migration و تصویب مالک |
| **S4** | مسیرهای مالیِ مبتنی بر ID: تأیید مالکیت با `trustedClinicId()` پیش از `findForUpdate`، با 404 parity برای جلوگیری از شمارش | §۵ | **خیر** — `trustedClinicId()` در همان کلاس هست |

**ترتیب پیشنهادی بر پایهٔ محرمانگی/نوشتن (طبق اولویت مالک):** S4 → S2 → S1 → S3.

---

## ۸. تست‌های Characterization — وضعیت و دستور بازتولید

طبق قید صریح مالک: **اگر شواهد قرمز را نمی‌توان روی شاخهٔ push‌شده گذاشت، دستورهای
دقیق بازتولید مستند شود و پیش از commit کردن آن‌ها متوقف شویم.** در این مخزن،
`.github/workflows/ci.yml` روی هر Pull Request و `closure-gate.yml` /
`pilot-gate.yml` / `real-wp-acceptance.yml` روی هر push به `arena/**` اجرا
می‌شوند. بنابراین **افزودن تست قرمز به این شاخه، گیت‌های شاخه را عمداً قرمز
می‌کند.**

بر همین اساس:

- **هیچ تست characterization در این commit اضافه نشده است.** ← توقف طبق قید ۱۱.
- **هیچ تستی تضعیف، skip، `markTestIncomplete` یا `@expectedException` نشده است.**
- **هیچ گیتی تضعیف نشده است.**

نیازمند **تصمیم صریح مالک** پیش از commit شواهد قرمز: آیا شاخهٔ C7-0 اجازه دارد
با تست‌های قرمزِ characterization قرمز بماند (و صرفاً «کاندید merge» نباشد)؟

### دستورهای دقیق بازتولید (محیط با PHP + MySQL)

```bash
cd clinic-practice-management
composer install
# راه‌اندازی WP test harness مطابق CI (.github/workflows/ci.yml مرحلهٔ Integration)
php -d memory_limit=1G vendor/bin/phpunit --no-configuration \
    --bootstrap tests/integration-bootstrap.php tests/Integration
```

اجرای موردیِ کلاس‌های ایزولیشن موجود:

```bash
php -d memory_limit=1G vendor/bin/phpunit --no-configuration \
    --bootstrap tests/integration-bootstrap.php \
    tests/Integration/FinanceClinicIsolationTest.php
php -d memory_limit=1G vendor/bin/phpunit --no-configuration \
    --bootstrap tests/integration-bootstrap.php \
    tests/Integration/ClinicTenantIsolationTest.php
php -d memory_limit=1G vendor/bin/phpunit --no-configuration \
    --bootstrap tests/integration-bootstrap.php \
    tests/Integration/TenantIsolationGapTest.php
```

Tripwire (بدون نیاز به PHP):

```bash
python3 bin/tenant-tripwire.py --test   # ← تأیید شد در همین جلسه: 59 passed, 0 failed
python3 bin/tenant-tripwire.py          # ← تأیید شد: 173 فایل، 0 هاردکد، 1 مورد مشکوکِ شناخته‌شده
                                        #   (SystemClinicResolver.php:52 — AD-04)، exit 0
```

### طرح تست‌های پیشنهادی (وقتی مالک اجازه داد)

هر تست باید: خاصیت امنیتیِ مورد آزمایش را در docblock بگوید، ایزوله بودن fixture
را اثبات کند (الگوی `CPMS_ISOLATION_WITNESS` موجود در `TenantIsolationGapTest:48`)،
از **شناسهٔ کلینیک ≠ 1** استفاده کند (محدودهٔ رزرو `≥ 61000` طبق قرارداد مخزن)،
برای رفتار بین‌tenant حداقل **دو کلینیک** بسازد، به شناسهٔ ثابت DB وابسته نباشد،
و **404 parity** را ترجیح دهد (نه 403) تا شمارش ممکن نشود.

| تست پیشنهادی | یافته | ادعای مورد انتظار |
|---|---|---|
| `testRecordPaymentOnForeignClinicInvoiceIsRejected` | §۵ | فاکتور کلینیک B با actorِ دارای capability در A ⇒ `CLINIC_NOT_FOUND` 404، بدون ردیف پرداخت جدید |
| `testVoidPaymentOnForeignClinicPaymentIsRejected` | §۵ | 404 parity، status پرداخت بدون تغییر |
| `testForeignInvoiceReadDoesNotLeakPatientNameOrMrn` | §۵ | پاسخ نباید نام/MRN کلینیک B را دربر داشته باشد |
| `testScheduleUpdateAcrossClinicsIsRejected` | §۲ | ردیف کلینیک B تغییر نکند و Slotهای B حذف نشوند |
| `testScheduleExceptionDeleteAcrossClinicsIsRejected` | §۲ | همان |
| `testReminderJobUsesEachRowsOwnClinicTimezone` | §۳ | دو کلینیک با timezone عمداً متفاوت؛ مرز «امروز» هر ردیف با timezone خودش محاسبه شود |
| `testSmsCredentialsAreNotSharedAcrossClinicsInJobPath` | §۳ | provider/sender هر پیام از تنظیمات کلینیک همان ردیف بیاید. **هرگز مقدار اعتبارنامه لاگ یا assert نشود** — فقط شناسهٔ provider و اینکه مقادیر «متفاوت»اند |
| `testSlotsGenerateHorizonIsPerClinic` | §۳ | `booking.max_future_days` متفاوت برای دو کلینیک |
| `testPrescriptionNumberingIsIndependentPerClinic` | §۴ | ثبت در B نباید شمارهٔ بعدی A را جلو ببرد |

---

## ۹. مواردی که نیازمند تصمیم مالک / ADR هستند

1. **S2 (هویت tenant در Job):** حمل tenant در payload یا ستون جدید در `cpms_jobs`؟
   حالت دوم = Migration ⇒ تصویب لازم.
2. **S3 (شماره‌گذاری نسخه):** تغییر `u_rx_number` به `(clinic_id, prescription_number)`
   ⇒ Migration + سیاست backfill ⇒ تصویب لازم.
3. **سیاست تست قرمز:** آیا شاخهٔ C7-0 می‌تواند با تست‌های قرمز characterization
   قرمز بماند؟ (§۸)

---

## ۱۰. آنچه در این پاس **انجام نشد** (طبق دستور)

- بدون refactor مخزن/سرویس؛ بدون افزودن مکانیکی پارامتر Clinic.
- بدون `AuthorizationService`، بدون RBAC نهایی، بدون Membership-to-Location،
  بدون provisioning خودکار Membership، بدون portal، بدون mobile/JWT، بدون تغییر
  مدل پروفایل Clinician.
- بدون معناشناسیِ مجوزدهی user-to-Location.
- بدون Migration 0021. بدون Phase 3.
- بدون merge، بدون تغییر مستقیم main، بدون force-push، بدون بازنویسی تاریخچه،
  بدون tag/release/version bump.
- بدون حذف هیچ شاخهٔ تاریخی.
