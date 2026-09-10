# C6 Corrective — فهرست مشکلات یافت‌شده (برای بازبینی ایجنت بعدی)

**تاریخ:** ۲۰۲۶‑۰۹‑۱۰ · **شاخه:** `arena/01a08b0a-doctor` · **HEAD:** `60ee1ec2aeebb81f1ad9260634a5e04ed089127b`
**PR:** [#16](https://github.com/bia2on2on/doctor/pull/16) — **DRAFT، ممنوع از merge**
**پایه:** `origin/main` = `6c8431687d8441cae220212d6ae8d8a97ed4058c` (دست‌نخورده)

> این سند فهرست مشکلات است، نه گزارش موفقیت. دو مورد **باز** است (بخش ۴).

## راهنمای طبقه‌بندی

| کلاس | معنا |
|---|---|
| **A** | رگرسیون تازه که کار جاری وارد کرده |
| **B** | نقص محصولی از پیش موجود |
| **C** | نقص زیرساخت/CI |
| **D** | نقص تست/گیت (آشکارساز یا خودِ تست) |

---

## ۰) وضعیت گیت‌ها روی این شاخه — شمارش دقیق

Runهای trigger‌شده روی `60ee1ec` (همه `completed`):

| Run | Workflow | رویداد | نتیجه |
|---|---|---|---|
| `34480690937` | CI | `pull_request` | ❌ **failure** (۱ job از ۸) |
| `34480690968` | Real WP Acceptance | `pull_request` | ✅ success (۲/۲) |
| `34480571978` | Closure Gate | `push` | ✅ success (۵/۵) |
| `34480572017` | Real WP Acceptance | `push` | ✅ success (۲/۲) |
| `34480572129` | Pilot/Staging Readiness | `push` | ✅ success (۴/۴) |

جداسازی job‌های CI `34480690937`:

| Job | نتیجه |
|---|---|
| Tenant Tripwire (hardcode scan) | ✅ success |
| Unit Tests (PHP 8.1 / 8.2 / 8.3 / 8.4) | ✅ success ×۴ |
| Static Analysis (PHPStan) | ✅ success |
| WPCS (changed code) | ✅ success |
| **Integration (WP 6.7 + MySQL 8)** | ❌ **failure** |

شمارش تست Integration از لاگ CI:

```
Tests: 628, Assertions: 4077, Failures: 2.
```

راستی‌آزمایی محلیِ همین عدد: تعداد `public function test` در `tests/Integration/`
روی `6c84316` = **۶۱۶**، روی این شاخه = **۶۲۸** (۱۲ تست تازه). یعنی
**هر ۶۱۶ تست موجود همچنان pass هستند** و رفعِ کد چیزی را نشکسته است؛
هر ۲ شکست از ۱۲ تست تازهٔ خودم است.

---

## ۱) هفت نقطهٔ Clinic-1 در runtime — کلاس **B** — رفع شد

هر هفت مورد روی `origin/main` = `6c84316` **بازتأیید** شد (پیش از هر ویرایش) و
با اسکن آشکارساز سخت‌شده نیز مستقل تأیید شد: **۷ hardcode روی `6c84316`**.

الگوی مشترک: ستون tenant یک placeholder `%d` بود و literal `1`
**جداگانه در آرایهٔ پارامترها bind می‌شد** — دقیقاً همان چیزی که
آشکارساز قدیمی نمی‌دید.

| # | فایل / متد | binding خراب | شعاع اثر |
|---|---|---|---|
| 1 | `ServiceRepository::all` | `[1]` | فهرست تعرفه/خدمات همیشه از Clinic 1 |
| 2 | `PaymentRepository::nextPaymentNumber` | `[1, $prefix.'%']` | **شمارهٔ سریال پرداخت** از دنبالهٔ Clinic 1 |
| 3 | `PaymentRepository::revenueSummary` | `[1, …]` | خلاصهٔ درآمد Clinic 1 به‌جای Clinic عملیاتی |
| 4 | `PaymentRepository::forRange` | `[1, …]` | بازهٔ پرداخت‌ها از Clinic 1 |
| 5 | `InvoiceRepository::nextInvoiceNumber` | `[1, $prefix.'%']` | **شمارهٔ سریال فاکتور** از دنبالهٔ Clinic 1 |
| 6 | `InvoiceRepository::openInvoices` | `[1, $limit]` | فاکتورهای باز Clinic 1 + **نام و MRN بیمار Clinic 1** به کاربر Clinic دیگر |
| 7 | `FinanceService::lockClinic` | `SELECT id FROM cpms_clinics WHERE id = %d LIMIT 1 FOR UPDATE` با `[1]` | **قفل ردیفِ سریال‌سازی روی ردیف Clinic 1** صرف‌نظر از Clinic نویسنده ⇒ دو Clinic می‌توانند شمارهٔ یکسان بسازند |

**رفع:** هر هفت قرارداد `int $clinic_id` **الزامی** (بدون default) گرفت؛
`FinanceService::requireClinicScope()` با `CLINIC_SCOPE_REQUIRED` (۴۰۰) fail-closed می‌کند؛
Clinic هرگز از ورودی کلاینت نمی‌آید (مرز REST از `TrustedClinicEstablisher`،
مسیر فاکتور/پرداخت از `clinic_id` ردیفِ **داخل همان تراکنش قفل‌شده**).

---

## ۲) نقص ثانویه: شناسهٔ درجِ stale — کلاس **B** — رفع شد، ⚠️ **بدون پوشش تست سبز**

**زنجیرهٔ علت (trace‌شده روی `6c84316`، با ارجاع خطی — نه فرضی):**

1. `wpdb::insert()` در خطا `false` می‌دهد ولی `insert_id` را **پاک نمی‌کند** ⇒
   شناسهٔ آخرین درجِ موفق سرِ جایش می‌ماند.
2. `CpmsDb::insert()` درست `false` برمی‌گرداند (`$this->wpdb->insert(...) !== false`) —
   اما **فراخوان‌ها مقدار برگشتی را دور می‌ریختند**.
3. **مسیر فاکتور — بدون هیچ گاردی.** `InvoiceRepository::insert()` روی `6c84316`:

   ```php
   $this->db->insert('cpms_invoices', $row);        // مقدار برگشتی دور ریخته می‌شود
   return $this->db->wpdb_last_insert_id();         // می‌تواند stale باشد
   ```

   و `FinanceService::issueInvoice()` با همان شناسه **بدون بررسی** `insertItem()`
   می‌زد ⇒ اقلام می‌توانستند به **فاکتور بیگانه (احتمالاً Clinic دیگر)** بچسبند و
   تراکنش commit شود.
4. **مسیر پرداخت — گارد ناسالم.** `FinanceService.php` روی `6c84316`، خطوط ۳۷۰/۳۸۱/۳۸۲:

   ```php
   $ok = $this->payments->insert(…);          // ← insert() نوع بازگشتی int دارد، نه bool
   $paymentId = $this->db->wpdb_last_insert_id();
   if (!$ok || $paymentId <= 0) { … }         // ← هر دو شرط عملاً «id == 0» را می‌سنجند
   ```

   چون `insert()` شناسهٔ درج را برمی‌گرداند، `$ok` یک `int` است و `!$ok` فقط وقتی
   true است که شناسه **صفر** باشد. یعنی **شناسهٔ stale غیرصفر از گارد رد می‌شد** و
   کد ادامه می‌داد: `applyInvoicePaymentEffect()` اثر پرداختِ **انجام‌نشده** را روی
   فاکتور می‌گذاشت و commit می‌شد ⇒ **پرداخت شبح و کاهش موجودی فاکتور بدون پول واقعی**.
   (این خطرناک‌تر از حالت صفر است، چون بی‌صدا اتفاق می‌افتد.)

**رفع:** `insert` / `insertItem` / `insertAdjustment` (فاکتور) و `insert` (پرداخت)
در شکست استثنا می‌دهند ⇒ ROLLBACK. مسیر Idempotency-race پرداخت با catch همان
استثنا **بدون تغییر رفتار بیرونی** حفظ شد (بازگشت `['replay' => …]`).
الگو از `HandwritingRepository` / `NotificationRepository` موجود گرفته شد.

> ⚠️ **مورد باز:** دو تستی که باید این نقص را پوشش می‌دادند همان دو تستِ
> شکست‌خوردهٔ بخش ۴ هستند. پس این نقص **الآن هیچ رگرسیون‌تست سبزی ندارد**.

---

## ۳) نقطه‌های کور Tenant Tripwire — کلاس **D** — رفع شد

آشکارساز قدیمی روی `6c84316` خروجی
`CLEAN: 173 files, 0 hardcodes, 0 suspects` می‌داد در حالی که ۷ نقص واقعی بود.
سه علت، هر سه **پیش از تغییر به‌صورت تجربی تأیید شد**:

1. **الگوی مرده:** `select_first_clinic` روی main **۸ خط** را match می‌کرد ولی
   **همه** توسط benign `limit_1_generic` خفه می‌شدند — و آن الگو خودش
   ساختاراً `LIMIT 1` می‌خواهد، یعنی هرگز نمی‌توانست چیزی گزارش کند.
2. **anchor نداشتن:** `id_1_primary` بخش `id = 1` از `c.id = 1` را match می‌کرد
   (چون `_` و `.` word char هستند) ⇒ هر پرس‌وجوی ستون tenant با alias خفه می‌شد.
3. **benign خط‌محور:** تطبیق benign کل خط را می‌گرفت، پس
   `WHERE clinic_id = 1 AND is_active = 1` به‌خاطر `is_active = 1` خفه می‌شد.

**رفع:** لایهٔ دوم `scan_prepared()` (بازسازی SQL از literalها، resolve متغیرهای
تک‌انتسابی **خط‌محدود** تا ترتیب placeholder با params هم‌تراز بماند،
هم‌تراز‌سازی placeholder→param، پوشش ذاتی multiline، تشخیص قفل/انتخاب ردیف جدول tenant)
+ anchor‌دار کردن همهٔ الگوهای benign با `(?<![\w.$])` + `_is_shadowed()`
که benign را فقط روی anchor‌های خودِ match اعمال می‌کند.

| | hardcode | suspect | allowlist | فایل |
|---|---|---|---|---|
| `6c84316` | **۷** | ۱ | ۰ | ۱۷۳ |
| این شاخه | **۰** | ۱ | ۰ | ۱۷۳ |

* Self-tests: **۳۴ → ۶۲**، همه PASS (اجرای محلی).
* **Allowlist خالی ماند** (هیچ ورودی برای سبزکردن اضافه نشد).
* **هیچ تشخیصی تضعیف نشد.**
* suspect باقی‌مانده: `src/Application/Scope/SystemClinicResolver.php:52`
  (`SELECT id FROM cpms_clinics LIMIT 1`) — همان الگوی قبلاً مرده که اکنون برای
  مرور دیده می‌شود؛ resolverِ «دقیقاً یک Clinic» با گارد `COUNT(*) === 1` است و
  گیت را نمی‌شکند. **عمداً allowlist نخورد.**

---

## ۴) دو شکست CI — کلاس **D** (باگ تستِ خودم) — 🔴 **باز**

```
1) FinanceClinicScopeTest::testInvoiceInsertFailureStopsAndNeverAttachesItemsToStaleParent
   Failed asserting that null is an instance of class "RuntimeException".
   tests/Integration/FinanceClinicScopeTest.php:399

2) FinanceClinicScopeTest::testPaymentInsertFailureRollsBackInsteadOfCommittingStaleId
   Failed asserting that null is an instance of class "ClinicCore\Application\Finance\FinanceException".
   tests/Integration/FinanceClinicScopeTest.php:457
```

### علت دقیق (خوانده‌شده از کد، نه حدس)

استراتژی تزریق خطای من این بود: یک ردیف با شمارهٔ خارج‌الگو
(`'ZZZ-POISON'`) درج کنم تا `MAX()` الگوی `INV-`/`PAY-` ندهد ⇒ `seq = 0` ⇒
عددگیر `001`/`0001` بسازد که از قبل اشغال است ⇒ نقض unique.

اما پیاده‌سازی واقعی این است:

```sql
SELECT MAX(invoice_number) FROM …cpms_invoices
 WHERE clinic_id = %d AND invoice_number LIKE 'INV-<ymd>-%'
```

`MAX()` **با فیلتر `LIKE '<prefix>%'`** گرفته می‌شود، پس ردیف مسموم
`'ZZZ-POISON'` اصلاً داخل مجموعهٔ MAX نیست و روی دنباله اثری ندارد. در نتیجه:

* MAX = `'INV-<ymd>-001'` (همان ردیفی که من اشغال کردم) ⇒ `seq = 1` ⇒ بعدی `002`
* `002` آزاد است ⇒ درج **موفق** می‌شود ⇒ هیچ استثنایی پرتاب نمی‌شود ⇒ `$thrown === null`

دقیقاً همان اتفاق برای پرداخت (`PAY-<ymd>-0001` → `0002`).
**کد محصول درست کار می‌کند؛ فرض تست غلط بود.**

⚠️ توجه: آن فیلتر `LIKE` همان `[1, $prefix.'%']` است که من در همین corrective
از `1` به clinic واقعی تغییر دادم — یعنی تست بر پایهٔ شکلِ **قدیمی** کوئری نوشته شده بود.

### نکتهٔ مهم برای ایجنت بعدی

**تصادم deterministic از راه شماره‌گذاری در یک اتصال ممکن نیست.** چون
`next = MAX + 1` و MAX بزرگ‌ترین عضو مجموعه است، عدد بعدی هرگز عضو مجموعه نیست.
پس «اشغال‌کردن عدد بعدی» هرگز به نقض unique نمی‌رسد. تزریق خطا باید از راه دیگری باشد.

### گزینه‌های پیشنهادی (به‌ترتیب ترجیح)

1. **پرداخت — از `u_pay_idem (clinic_id, idempotency_key)`:** یک پرداخت خام با
   همان idempotency key درج کن. ⚠️ اما `recordPayment` این حالت را **by design**
   به `['replay' => …]` تبدیل می‌کند؛ پس این تست باید **سلوکِ replay** را
   asserted کند، نه استثنا. (تست ارزشمند ولی متفاوت.)
2. **فاکتور — تزریق خطای واقعی MySQL با فیلتر `query`:** همان الگویی که در همین
   فایل برای گرفتن کوئری قفل استفاده شده (`add_filter('query', …, 5, 1)`) را
   طوری به‌کار ببر که **literal شماره در جملهٔ INSERT فاکتور** به شمارهٔ
   از‌قبل‌موجود بازنویسی شود ⇒ MySQL خطای duplicate واقعی می‌دهد ⇒
   گارد واقعی اجرا می‌شود. بقیهٔ ادعاها (نچسبیدن قلم به والد stale، ROLLBACK)
   کاملاً واقعی می‌ماند.
3. **سطح Repository:** درج مستقیم تکراری و asserted کردن اینکه `insert()`
   استثنا می‌دهد و `insertId()` مصرف نمی‌شود.

🚫 **ممنوع:** ضعیف‌کردن/حذف/quarantine کردن این دو تست، یا تغییر مقدار انتظاری
برای «مبارک‌کردن» رفتار. هدف تست (درج شکست‌خورده نباید ادامه یابد) درست است؛
فقط روش تزریق غلط است.

---

## ۵) مشاهدات جانبی در لاگ CI — بدون اقدام

* `WordPress database error Duplicate entry … for key '…cpms_clinicians.u_clinician_user'`
  در `RestTrustedClinicContextTest` و `SecurityHardeningTest::testSecondClinicianWithSameWpUserIsRejectedByDb`
  ⇒ تست‌های **negative** که همان خطا را انتظار دارند و **pass** هستند.
  وجود هر چهار متد روی `6c84316` محلی راستی‌آزمایی شد ⇒ از پیش موجود.
* `Lock wait timeout exceeded … SELECT * FROM …cpms_visits WHERE id = 233 LIMIT 1 FOR UPDATE`
  در `VisitConcurrencyTest::testRowLockSerializesConcurrentTransition` ⇒ متد روی
  `6c84316` وجود دارد (پیش‌موجود) و تست **pass** است. به‌عنوان **flake بالقوه**
  ثبت می‌شود؛ ربطی به این corrective ندارد.
* ⚠️ **راستی‌آزمایی‌نشده:** نتوانستم لاگ job مربوط به `main` را دانلود کنم
  (هاست blob لاگ‌ها از این sandbox در دسترس نیست؛ فقط `api.github.com` reachable است).
  پس «پیش‌مبودنِ نویز» از راه **وجود متد روی `6c84316`** نتیجه‌گیری شد، نه از diff لاگ.

---

## ۶) ماتریس راستی‌آزمایی

| مورد | وضعیت |
|---|---|
| ۷ نقطهٔ Clinic-1 روی `6c84316` | ✅ VERIFIED (اسکن = ۷ hardcode) |
| اسکن پس از رفع = ۰ hardcode / ۱۷۳ فایل | ✅ VERIFIED (اجرای محلی) |
| Tripwire self-tests ۶۲/۶۲ | ✅ VERIFIED (اجرای محلی) |
| Allowlist خالی | ✅ VERIFIED |
| Tenant Tripwire (CI) | ✅ VERIFIED — job success |
| Unit ۸.۱/۸.۲/۸.۳/۸.۴ | ✅ VERIFIED — job success ×۴ |
| PHPStan level 3 | ✅ VERIFIED — job success |
| WPCS خطوط تغییریافته | ✅ VERIFIED — job success |
| Real-WP (`wp_` + `clinic_`) | ✅ VERIFIED — ۲ run، هر دو success |
| Closure Gate | ✅ VERIFIED — ۵/۵ success |
| Pilot/Staging | ✅ VERIFIED — ۴/۴ success |
| Integration | ❌ **RED** — ۶۲۸ تست، ۲ شکست (هر دو تست تازهٔ خودم، کلاس D) |
| پوشش تستیِ نقصِ شناسهٔ stale | 🔴 **NOT COVERED** (همان ۲ تست شکست‌خورده) |
| `php -l` / اجرای محلی PHP | ⛔ NOT MEASURED — sandbox این نشست PHP/composer/MySQL ندارد |
| لاگ job روی `main` | ⛔ NOT MEASURED — هاست blob در دسترس نیست |

---

## ۷) کارهای باقی‌مانده

1. **رفع روش تزریق خطا در آن ۲ تست** (بخش ۴، گزینهٔ ۱–۳) — بدون ضعیف‌کردن ادعاها.
2. push و تأیید سبز شدن Integration (و بقیهٔ گیت‌ها) روی SHA جدید.
3. PR #16 **DRAFT بماند**؛ merge نکن.
4. **Migration نساز** — `0021` نباید وجود داشته باشد (آخرین = `0020`).
5. **C7 را شروع نکن.** این یک بازکردنِ دوبارهٔ C6 یا Phase 3 نیست.
6. PR #13 باید **OPEN + DRAFT** بماند (آخرین بررسی: `OPEN`, `isDraft=true`, base `arena/01a086ca-doctor`).
