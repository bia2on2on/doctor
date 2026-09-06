# راهنمای جامع ادامه پروژه — CPMS (کلینیک)

> **این فایل سند жив پروژه است.** هر ایجنت (AI یا انسان) که روی این repo کار می‌کند **باید** قبل از شروع این فایل را کامل بخواند و بعد از پایان کارِ خود، در بخش «لاگ کار ایجنت‌ها» (انتهای همین فایل) ورودی ثبت کند.

نسخه: 1.0 | آخرین به‌روزرسانی: 2026-09-05 | نگهدارنده: ایجنت‌های Arena (به‌صورت append-only)

---

## 0. چک‌لیست شروع کار هر ایجنت (اجباری)

1. این فایل را کامل بخوان (مخصوصاً §5 قواعد کار و §7 دام‌ها).
2. وضعیت repo را verify کن — دستورات آماده:
   ```bash
   git log --oneline -10 && git status --short
   gh run list --branch arena/01a071c4-doctor --limit 3
   gh pr view 1 --json state,statusCheckRollup
   ```
3. وضعیت فازها را از `docs/roadmap/roadmap.md` و آخرین گزارش `docs/phase-reports/report-*.md` بگیر.
4. **قاعده طلا:** Git history منبع تأیید است؛ گزارش‌های قبلی فقط handoff هستند — همه ادعاها را از کد/تست/CI verify کن.
5. هیچ فاز جدیدی بدون تأیید صریح کارفرما شروع نکن (قانون Gate — Section 56).

---

## 1. معرفی پروژه

**CPMS** = سیستم مدیریت مطب (Clinic Practice Management System) به‌صورت **افزونه WordPress** (PHP 8.1+، MySQL 8، WP 6.4+ — runtime تأییدشده روی WP 6.4/6.5/6.6/6.7.2 و PHP 8.1–8.4) — تک‌کلینیک در V1، تجاری با لایسنس.

- مسیر افزونه: `clinic-practice-management/`
- Namespace: `ClinicCore\` → `clinic-practice-management/src/`
- REST namespace: `clinic/v1`
- زبان داخلی کد/کامنت‌ها: فارسی + اصطلاحات انگلیسی

### معماری لایه‌ای (الگوی فعلی — حفظ شود)

```
src/
├── Domain/            # منطق خالص: Machineها (State Machines)، Exceptionها، Validators
├── Application/       # سرویس‌ها (Auth/Booking/Visits/Patients/Notifications/Jobs)
├── Infrastructure/    # Db (CpmsDb wrapper روی wpdb)، Repositoryها، Audit، Security، Sms، Queue، Logging
├── Rest/              # Controllerهای REST (RestBase مشترک: nonce → cap → error/success envelope)
├── Admin/             # صفحات WP-Admin (SettingsAdmin، SmsSettingsPage، SecretaryQueuePage)
├── Auth/              # RolesAndCapabilities (نقش‌ها + کدهای capability)
├── Settings/          # Settings (option-based + cache)
├── Bootstrap/         # App (DI container استاتیک + boot/activate/cron)
└── Migrations/        # MigrationRunner + فایل‌های versioned
tests/
├── Unit/              # بدون WP — domain خالص
└── Integration/       # WP_UnitTestCase — bootstrap: tests/integration-bootstrap.php
```

**قواعد لایه‌ای:**
- Repository از F3 به بعد **اجباری** (ADR-0021): فقط data-access، بدون منطق دامنه، بدون God-Repository.
- کد F1/F2 برای یکنواختی refactor نشده — فقط برای مشکل واقعی (با impact analysis).
- Controller فقط: nonce → capability → فراخوانی سرویس → envelope. منطق دامنه در سرویس.
- هر Endpoint جدید فقط با سند API Contract (قانون Section 56).

---

## 2. وضعیت فعلی (Snapshot 2026-09-05)

### فازهای کامل‌شده

| فاز | وضعیت | گزارش |
|---|---|---|
| F1 — Core Architecture (Migration/Roles/Settings/Audit/JobQueue/CI) | ✅ | `report-f1.md` |
| F2 — احراز هویت (OTP/Session/Patient Links) | ✅ | `report-f2.md` |
| F2.5 — پیامک Provider-Agnostic (ADR-0025) | ✅ | `report-f2.5-sms.md` |
| F3 — نوبت‌دهی (Schedule/Slot/Booking API/Patient CRUD) | ✅ CI سبز | `report-f3.md` |
| **F4 — مراجعه/صف (Check-in/Walk-in/Queue SM/Real-time/داشبورد منشی)** | ✅ **CI سبز** | `report-f4.md` |
| F5 — بالینی (Notes/نسخه/توصیه/پیگیری/فایل/جستجو/داشبورد پزشک + Drift بسته) | ✅ CI سبز | `report-f5.md` |
| **F6 — مالی (Invoice/Payment/Adjustment/Void/Refund/Receipt/Summary + تعرفه‌ها + داشبورد مالی منشی + ADR-0026)** | ✅ **CI سبز** | `report-f6.md` |
| F7 — دست‌خط (Canvas/Offline Sync/Conflict) | ✅ CI سبز | `report-f7.md` |
| F8 — اعلان + گزارش + Export | ✅ CI سبز | `report-f8.md` |
| F9 — Hardening (Security T-01..T-24/TP-16/DoD V1) | ✅ CI سبز | `report-f9.md` |
| **Pilot/Staging Readiness Gate** | 🔄 ۱۴ run — Release/Upgrade/Responsive سبز؛ Staging تا Backup ✅، Restore Drill آخر | `report-pilot-gate.md` |

### آخرین وضعیت فنی (verify شده)

- **HEAD فعلی:** Pilot/Staging Gate فعال روی `arena/01a071c4-doctor` (F9 کامل در `c5e82a7` + Gate tooling/fixes) — وضعیت لحظه‌ای در report-pilot-gate.md §15
- **PR #1:** OPEN — هر ۵ چک (Unit PHP 8.1/8.2/8.3/8.4 + Integration WP 6.7.2 + MySQL 8)
- **تست‌ها:** Unit سبز روی PHP 8.1–8.4؛ Integration بعد از F6 = ۲۰۳ تست (FinanceFlowTest ۱۷ + قبلی‌ها)، ۰ skip — شامل concurrency با fork واقعی
- **Schema:** ۳۹ جدول `cpms_*` (مهاجرت‌های 0001–0003)؛ جدول فایل = `cpms_medical_attachments`
- **Capabilities:** drift ۴۹/۴۶ در F5 بسته شد — ماتریس مبنا (۴۶)؛ مفرغ‌ها (files/search) مطابق ماتریس اضافه شدند؛ registerRole اکنون stray capهای `cpms_*` را هم پاک می‌کند (self-healing — 998ee81)

### نقشه قابلیت‌های ساخته‌شده (Backend کامل تا F6)

- **Auth/OTP:** ثبت‌نام/ورود بیمار با OTP موبایل، Patient↔WP-User link، rate limit
- **SMS:** Provider-agnostic (Log/Generic API)، Vault، Queue، Templates، SSRF guard
- **Booking:** تقویم/اسلات/ظرفیت، Hold→Confirm (اتمیک DB-level ضد double-booking)، Idempotency-Key، Reschedule/Cancel با policy، Duration snapshot
- **Patient:** CRUD + جستجو + پروفایل
- **Queue/Visits (F4):** Check-in/Walk-in، ماشین کامل V1–V15 با Row-Lock و تاریخچه append-only، صف FIFO + نوبت فوری، R1 polling با ETag/304، No-show خودکار (lazy + cron)، Checkout/Waive، داشبورد منشی WP-Admin
- **Clinical (F5):** E7 پرونده کامل، E8/E9 Notes + نسخه‌بندی append-only، E10/E11 نسخه Draft/Finalize/Void، E12/E13 توصیه/پیگیری، E14/E15 پایان/بازگشایی با Validation، C5/C6/C7 نمای بیمار (فقط patient_visible)، E16/E17/C3/C4 فایل محافظت‌شده، E18 جستجوی جامع Role-Aware، داشبورد + صفحه ویزیت پزشک (WP-Admin)
- **Finance (F6):** تعرفه‌ها (G2 — soft-delete)، D12 صدور فاکتور (V11 سیستمی)، D13 پرداخت Idempotent (M-1 روی خود جدول، 201/200+Replay)، D14 ابطال (همان‌روز)، P3 بازپرداخت، D15 اصلاح Credit/Debit (کلید تسویه مؤثر)، D17 رسید Deterministic (جلالی + چاپ UI)، D18 خلاصه مالی، V14 گارد NOT_SETTLED در Checkout؛ INV/PAY سریال با قفل کلینیک؛ محاسبات ریالِ صحیح (InvoiceCalc — TP-18)؛ داشبورد مالی منشی (WP-Admin)

---

## 3. قواعد کارفرما (Binding — هرگز نقض نشود)

این قواعد از دستورالعمل‌های صریح کارفرما آمده و **بر همه ایجنت‌ها الزامی** است:

### 3.1 تقدم اسناد (Document Precedence)

```
Client Final Decisions → Approved ADRs (scope) → Engineering Baseline → SRS
→ State Machines/ERD → API Contract → Settings Reference → Implementation
```
- تعارض مهم = **STOP** با قالب ISSUE / IMPACT / OPTIONS / RECOMMENDATION و انتظار برای پاسخ.
- تصمیم نهایی کارفرما بر همه چیز مقدم است.
- فازبندی: منبع حقیقت = `docs/roadmap/roadmap.md` (خطای رایج: خواندن فاز بعدی از گزارش فاز قبلی — گزارش‌ها فقط خلاصه‌اند).

### 3.2 شرایط STOP (فقط این موارد — بقیه با قضاوت مهندسی)

Scope change، تغییر main workflow، تغییر قاعده business کاربر-محور، تغییر permission model، schema change مهم خارج از پلن، breaking API، تغییر سیاست licensing/security، احتمال data loss، تصمیم medical/legal، dependency بزرگ/پرخطر، هزینه third-party جدید، تعارض غیرقابل‌حل منابع.

**Working mode:** سؤال مکرر درون‌فازی ممنوع؛ برای naming/test/validation/internal interface/doc-sync/امنیتِ route قضاوت خودت را به کار ببر؛ هرگز کیفیت فدای سرعت نشود.

### 3.3 Governance فاز

- هر فاز را تا کامل شدن جلو ببر (مگر STOP واقعی).
- در پایان فاز: STOP + گزارش تکمیل (قالب `report-f3.md`/`report-f4.md`: خلاصه، ماتریس AC، تغییرات، کامیت‌ها، تصمیمات درون‌فازی §4، موارد باز §5، گام بعد §6).
- ورود به فاز بعد فقط با تأیید کارفرما.

### 3.4 قواعد فنی

- **Error codes:** همه `CLINIC_*`، stable/ماشین‌خوان، registry = `docs/api/error-codes.md` (هر کد جدید آنجا ثبت شود)؛ پیام فارسی کاربر جدا از کد فنی. کلید `status` در envelope رزرو است.
- **Patient ≠ WP User، Appointment ≠ Visit، Invoice ≠ Payment** — کلاینت هرگز trusted نیست؛ UI permission ≠ backend authorization؛ بیمار A نباید داده بیمار B را ببیند؛ منشی نمی‌تواند نوت خصوصی پزشک را ببیند.
- **Authorization = Capability، نه نام نقش (ADR-0026):** منطق جدید هرگز `if role == X` نمی‌نویسد — فقط `user_can('cpms_…')` (+ Scope در V2). نقش‌های ستادی سفارشی (حسابدار/دستیار/…) باید بدون تغییر Business Logic کار کنند؛ نام نقش فقط برچسب Audit است. حوزه‌ها مستقل‌اند: مالی ⊥ بالینی ⊥ هویت ⊥ یادداشت خصوصی؛ Cap عمومی هرگز `cpms_private_note_*` را ضمنی نمی‌دهد. همه داشبوردهای ستادی Responsive‌اند (تبلت/قلم = بهینه‌سازی دست‌خط، نه محدودیت دستگاه).
- **2FA (V1.5):** TOTP RFC 6238 برای حساب‌های ممتاز بر اساس ACCESS نه role name.
- **SMS:** همیشه از مسیر Notification→SmsService→ProviderInterface؛ credential هرگز hardcode/log نشود.
- **Licensing:** انقضا هرگز داده پزشکی را قفل/حذف نمی‌کند؛ بعد از grace (پیش‌فرض ۷ روز) فقط عملیات جدید block؛ license server هرگز داده پزشکی نمی‌گیرد و در مسیر booking network call مستقیم ندارد.
- **Performance:** REST p95 < 300ms؛ عملیات سنگین (OCR/SMS/PDF/Export) async؛ بدون N+1/لیست بی‌سقف/polling بی‌کنترل.
- **Backup:** RPO ≤ 6h / RTO ≤ 8h (تست restore در F9/TP-16).

### 3.5 قواعد تست و CI (از تجربه‌های تأییدشده F3/F4)

- تست واقعی هرگز برای سبز شدن CI حذف/ضعیف/skip نشود؛ assertion برای پنهان‌کردن bug عوض نشود.
- Failure ناشی از production code → اصلاح production؛ ناشی از test infra → اصلاح infra.
- Integration test فقط وقتی Passed است که **واقعاً روی WordPress+MySQL اجرا و سبز شده** (CI = شاهد).
- Concurrency test باید واقعاً اجرا شود (fork واقعی — در runner لینوکسی pcntl موجود است).
- Debug instrumentation موقت بعد از تشخیص cleanup شود؛ workflow ساده بماند.
- دیباگ CI فقط **Root-Cause based** — بدون iteration تشخیصی غیرضروری؛ هر CI-fix منطقی commit+push شود.
- در Session از sleep/wait طولانی استفاده نشود؛ اگر run در حال اجراست، وضعیت را ثبت کن و کار مستقل انجام بده، بعد چک کن.
- اگر بعد از چند iteration معقول CI قرمز ماند: STOP + Root Cause Report (failing tests / exact errors / علت / راه‌حل پیشنهادی).
- لاگ‌های job از sandbox قابل دانلود نیستند (redirect به blob با EOF) — راه خواندن شکست‌ها: کامنت‌های PR (step «Post failures to PR») یا API annotations. این محدودیت به‌تنهایی دلیل تغییر production نیست.

---

## 4. نقشه اسناد کلیدی

| سند | نقش |
|---|---|
| `docs/roadmap/roadmap.md` | فازبندی + DoD هر فاز (منبع حقیقت فاز) |
| `docs/srs/SRS.md` | الزامات عملکردی (FR-x) |
| `docs/api/api-contract.md` | همه Endpointها (A/B/C/D/E/R/SM) — §0 = قواعد envelope |
| `docs/api/error-codes.md` | Registry کدهای `CLINIC_*` (منبع حقیقت) |
| `docs/state-machines/*.md` | ماشین‌های Appointment/Visit/… + invariants (J-x/T-x/V-x) |
| `docs/erd/` | مدل داده (D-x) + data dictionary |
| `docs/security/` | تهدیدها (T-01..T-24)، auth-authorization (۵ لایه) |
| `docs/adr/ADR-*.md` | تصمیمات معماری تأییدشده |
| `docs/engineering-baseline.md` | استانداردهای مهندسی |
| `docs/permissions/` | ماتریس capabilityها |
| `docs/testing/` | TP-x تست‌پلن |
| `docs/phase-reports/report-*.md` | گزارش تکمیل هر فاز |
| `docs/decisions/` | تصمیمات نهایی کارفرما |

---

## 5. الگوهای پیاده‌سازی (Patternهای جاافتاده — دنبال کنید)

### سرویس جدید (مثل `VisitService`)

```php
final class XxxService {
    public function __construct(
        private readonly CpmsDb $db,
        private readonly XxxRepository $repos, ...
    ) {}
    // عملیات state-changing:
    return $this->db->transactional(function () use (...): array {
        $row = $this->repos->findForUpdate($id);      // Row Lock (J-1 الگو)
        $to = XxxMachine::create()->machine()->assert($from, $event, $actorRole); // ماشین
        // UPDATE + تاریخچه append-only + audit
    });
}
// خطا: throw XxxException::of('CLINIC_CODE', 'پیام فارسی', 409, ['key' => val]);
```
- Exception دامنه‌ای مثل `BookingException`/`VisitException` (errorCode/httpStatus/data).
- **هرگز transactional تو در تو نکن** (nested = COMMIT ضمنی) — منطق مشترک در متد private بدون transaction.
- Envelope خطا در Controller با `wrap()`/`guard()` → `$this->error($e->errorCode, $e->httpStatus, ...)`.

### REST Controller

- ارث‌بری از `RestBase`؛ `permission_callback='__return_true'` + nonce/cap **داخل callback** (الگوی موجود).
- ثبت در `App::boot()` → `register_routes()` داخل `rest_api_init`.
- Capهای صف: `QUEUE_READ/CHECKIN/ADVANCE/CALL/CHECKOUT`؛ منشی همه را دارد جز CALL؛ پزشک CALL + CONSULT_*.

### Repository (ADR-0021)

- Constructor فقط `CpmsDb`؛ متدهای data-access خالص (`find/findForUpdate/insert/updateById/...`).
- `CpmsDb` quirks: `insert()` → bool (آیدی از `wpdb_last_insert_id()`)، `update(table, data, where)`، `fetchRowForUpdate` = SELECT … FOR UPDATE، placeholderها فقط فرمت wpdb (`%d/%s/%f` — بدون `?`).

### Admin Page

- الگوی `SecretaryQueuePage`: `add_menu_page` + cap + render با config inline (rest_url+nonce) + JS vanilla بدون وابستگی + بدون PHI در HTML اولیه.

### Job تکرارشونده

- Handler در `src/Application/Jobs/` + register در `App::dispatcher()` + افزودن به `RECURRING_JOBS` در `App` (idempotent — در هر tick دوباره زمان‌بندی می‌شود).

### تست Integration

- `WP_UnitTestCase` + `App::migrations()->migrate()` در setUp + seed با SQL مستقیم + `makeUser` با `set_role`.
- **Userهای تست باید نام یکتا** داشته باشند اگر کلاس COMMIT واقعی می‌زند (الگوی ConcurrencyTest) + cleanup دستی در tearDown.
- fixtureهای COMMIT شده با ROLLBACK پاک نمی‌شوند — cleanup دستی لازم.
- Timezone: همه UTC در DB (`nowUtc()/nowUtcSql()`)، مقایسه زمان با string 'Y-m-d H:i:s'.
- slots UNIQUE روی `(clinician_id, slot_date, slot_time)` — زمان‌های تست باید یکتا باشند.

---

## 6. CI و محیط

- Workflow: `.github/workflows/ci.yml` — ۲ job: **Unit** (matrix PHP 8.1–8.4، بدون WP) + **Integration** (WP 6.7.2 + MySQL 8، PHPUnit 9.6، `tests/bin/install-wp-tests.sh`، root/root، prefix `wptests_`).
- چرخه CI ≈ ۴.۵–۵ دقیقه.
- شواهد شکست را از کامنت‌های PR #1 بخوان (step «Post failures to PR» فقط در failure).
- **محیط sandbox فاقد PHP CLI است** — برای lint فایل‌های PHP از WASM:
  ```bash
  cd /tmp && npm i @php-wasm/node   # یک‌بار
  # lint.mjs: NodePHP.load() + php.writeFile(tmp) + php.cli(['php','-l',tmp])
  # نکته: argv باید با 'php' شروع شود؛ exit code را منتقل کن (stdout بعد از cli می‌میرد)
  ```
- composer/vendor در sandbox نیست — تست‌ها را از CI بخوان، نه اجرای محلی.

---

## 7. دام‌های شناخته‌شده (Pitfalls — خوانده شود!)

1. **`transactional` تو در تو = COMMIT ضمنی** → savepoint-filter فقط queryهای `/*cpms*/`-marked را به SAVEPOINT تبدیل می‌کند (فقط در تست). COMMIT خام (`$wpdb->query('COMMIT')`) تراکنش والد تست را می‌بندد → نشت fixture → Duplicate key/WP_Error در تست‌های بعدی.
2. **تداخل کلید `status` در envelope خطا** — data exception هرگز کلید `status` نداشته باشد (برعکس merge شده در `RestBase::error`).
3. **`CpmsDb::query()` bool برمی‌گرداند** — برای affected/insert از `execute()/wpdb_last_insert_id()`.
4. **wpdb prepare با null → ''** و بدون placeholder `?` — مقادیر nullable را شرطی بچین.
5. **INNODB_TRX در CI قابل استفاده نیست** (performance_schema خاموش) — برای اثبات lock از mysqli مستقل + `SET SESSION innodb_lock_wait_timeout` استفاده کن.
6. **`php.cli` در WASM**: argv باید با `'php'` شروع شود؛ بعد از اولین فراخوانی stdout مرده است — فقط exit code.
7. **GitHub token ممکن است mid-session 401 شود** — retry بی‌فایده است؛ state را commit کن و از کارفرما بخواه GitHub را در Arena reconnect کند (تجربه: برگشت).
8. **لاگ jobها از API → blob storage EOF** — شکست‌ها را از کامنت PR بخوان.
9. **فازبندی را فقط از roadmap بخوان** — گزارش‌های فاز ممکن است pointer اشتباه داشته باشند (اتفاق افتاد: report-f3 §6).
10. **Retry سایز merge**: `array_merge(['status'=>$http], $data)` خطرناک — envelope اول است.
11. **آیتم مرزی نیمه‌شب UTC** در تست‌های «امروز/فردا» — با آفست‌های زمانی مطمئن کار کن.
12. **App helpers فایل plugin-level** (مثل `cpms_request_id`) — IIFE داخل `App::boot` در پروسه تست CI دیده نمی‌شوند؛ فایل plugin `clinic-practice-management.php`.
13. **`wp eval-file` + `declare(strict_types=1)` = Fatal** — در کد eval شده، declare باید اولین statement باشد (docblock قبلش هم غیرمجاز) → در اسکریپتهای eval-file (pilot-*) declare نگذار.
14. **`wp eval-file ... | tee` خطاهای stderr را گم می‌کند** → همیشه `2>&1 | tee` + `set -o pipefail`؛ وگرنه فایل لاگ خالی می‌ماند و root cause گم می‌شود.
15. **Closure در اسکریپتهای eval-file**: متغیرهای خارجی ($wpdb/$db/...) باید صریحاً `use` شوند — خطای «Call to a member function on null» یعنی import جا افتاده.
16. **اسکریپت تستGate باید با schema واقعی نوشته شود، نه حدس**: نمونه‌ها — `cpms_patient_user_links` ستون `linked_at/mobile_at_link/clinic_id` دارد (نه created_at)؛ جدول فایل `cpms_medical_attachments` و خروجی `upload()` = `presentFile` بدون `storage_path` (مسیر از DB بخوان)؛ ستونهای SMS: `recipient/event` (نه mobile/template)؛ status پیامکها **حروف بزرگ** (`SENT`/`QUEUED`).
17. **قبل از نوشتن assert روی رفتار REST، api-contract.md/error-codes.md را بخوان**: گم‌شدن Nonce همیشه 403 `CLINIC_INVALID_NONCE` است (Guard استاندارد: Nonce→Capability)، نه 401 UNAUTHORIZED.
18. **Runnerهای tick (WP-Cron و CLI) باید یک مسیر باشند** — هر Runner جدید از `App::runTick()` عبور کند؛ وگرنه recurringها بعد از اولین اجرا می‌ایستند (باگ Gate run 34023615811).
19. **`wp rewrite structure --hard` روی برخی محیطها بی‌صدا .htaccess نمی‌نویسد** → heredoc مستقیم با قواعد استاندارد WP بنویس.
20. **Apache روی runner: PrivateTmp فعال است** → فایلهای موقت بین پروسه PHP و apache به اشتراک گذاشته نمی‌شوند؛ مسیر آپلود را explicit کن.

---

## 8. کارهای باقی‌مانده

### فازهای آینده (طبق roadmap — بدون تأیید شروع نشود)

| فاز | محتوا | DoD/تست |
|---|---|---|
| **F7** | دست‌خط: Canvas (Pressure/Tools/Zoom/Multi-page — Responsive طبق ADR-0026 D-14)، Stroke Storage، Auto-save + Offline Sync (IndexedDB) + Conflict | TP-12 |
| **F8** | اعلان + گزارش: Notification Layer + Templates (Jalali)، ۱۲ گزارش + Export (Watermark/Audit) | TP-13 |
| **F9** | Hardening: Security Review (T-01..T-24)، Performance، Backup/Restore Test، Accessibility، مستندات کاربری، Pilot | TP-16 + DoD V1 |
| **V1.5** | OCR فارسی، 2FA (TOTP)، Merge UI، ClamAV/Encryption | TP-OCR + 2FA |
| **V2** | Multi-clinic، پرداخت آنلاین، بیمه/آزمایشگاه، Push، Mobile API | — |

**مایلستون‌ها:** M1 (پایان F3) ✓ | M2 (پایان F5+F6) ✓ رسیده — چرخه کامل مطب (منشی→پزشک→مالی)، آماده Pilot داخلی | M3 (پایان F9) — Go-Live V1.

### تصمیمات باز (نیازمند کارفرما)

1. ~~Drift ماتریس Capability (۴۹/۴۶)~~ — **بسته شد (F5):** ماتریس مبنا شد؛ مفرغ‌ها (files/search) از ماتریس اضافه شدند؛ غیرماتریسی‌ها حذف؛ باگ registerRole (stray cap هرگز پاک نمی‌شد) در 998ee81 اصلاح — جزئیات report-f5.md §3.
2. **Availability UI (تقویم/OTP/Profile)** — تصمیم قبلی: فاز UI مستقل پس از فازهای Backend.
3. **PHPStan** — طبق دستور F5: Blocker نیست؛ اگر بدون اختلاف قابل اضافه‌شدن است طبق roadmap انجام شود ولی توسعه Clinical متوقف نشود.
4. **`SmsController::can()`** — envelope استاندارد `CLINIC_*` ندارد (بدهی F2.5 → F8/patch).
5. **ADR-0023 Licensing** هنوز نوشته نشده (برای F10/licensing phase).

### نکات فنی برای F7 (آماده‌شده‌ها از F6)

- **ADR-0026 (تصریح کارفرما 2026-09-06) برای همه فازهای بعد الزامی است:** Authorization فقط Capability (`cpms_*`) — هرگز `if role == X` در کد جدید؛ نقش‌های سفارشی باید بدون تغییر Business Logic کار کنند؛ همه داشبوردهای ستادی Responsive (دست‌خط = بهینه‌سازی قلم روی تبلت، نه محدودیت دستگاه — Canvas باید Resize/Orientation/DPR/Touch-Stylus را بدون از‌دست‌رفتن Stroke مدیریت کند). نقشه مهاجرت Debt نقش-محور در خود ADR.
- `VisitService::applyTransition()` اکنون public است با `$forceRole` (فقط نقش system برای V11/V12) — عمل مالی + Transition در یک Transaction (الگوی FinanceService — M-7).
- الگوی Idempotency مالی (M-1): بدون جدول عمومی — `UNIQUE(invoice_id, idempotency_key)` روی خود جدول؛ اولین 201، تکرار همان کلید 200 + `code=CLINIC_IDEMPOTENCY_REPLAY` + همان payment_id؛ برای درجِ رقابت‌زده fallback به findByIdempotencyKey.
- محاسبات پولی: ریالِ صحیح (integer) همه‌جا؛ خالص در `InvoiceCalc` (تست Unit موجود)؛ کلید تسویه مؤثر = total − credit + debit.
- عددگیری سریال: قفل ردیف کلینیک (`SELECT ... FOR UPDATE` روی cpms_clinics) قبل از MAX+1.
- Audit مبلغی با کلیدهای نقطه‌دار (`invoice.balance`)؛ اکشن‌های مرجع در audit-strategy §2 (PAYMENT_CAPTURE نه PAYMENT_CAPTURED)؛ json_encode با فاصله بعد از «:» — تست‌ها JSON را decode کنند نه substring.
- Checkout: گارد `CLINIC_NOT_SETTLED` فقط مسیر paid→check_out را می‌بندد؛ مسیر معافیت (waive با دلیل) عمداً باز است.
- بازگشت از paid (void/refund) وضعیت ویزیت را برنمی‌گرداند (V12 یک‌طرفه — Deviation مستند در report-f6 §7).
- J-5 در تست‌ها: هر ویزیت جدید در همان روز = بیمار تازه (helper makePatient) — وگرنه CLINIC_DUPLICATE_ACTIVE_VISIT.
- F5 نکات همچنان معتبر: Audit FORBIDDEN خارج از Transaction؛ Factories وابسته به Setting بدون singleton-cache؛ ستون‌های NOT NULL بدون default همیشه صریح.

---

## 9. پروتکل لاگ کار ایجنت‌ها (اجباری)

**هر ایجنت** در پایان کار خود (یا پایان turn اگر کار ادامه دارد) این موارد را در انتهای همین فایل append می‌کند:

```
### [YYYY-MM-DD HH:MM UTC] — ایجنت <شناسه> — <عنوان کار>
- فاز/محدوده: ...
- اقدامات: (خلاصه روزنامه‌وار)
- کامیت‌ها: <sha> <پیام کوتاه> (شاخه arena/01a071c4-doctor)
- CI: <run-id> → <نتیجه> روی <sha>
- تصمیمات درون‌فازی: ...
- موارد باز/تحویل به ایجنت بعد: ...
- وضعیت tree: clean/dirty
```

قواعد لاگ:
- **Append-only** — ورودی قبلی را هرگز ویرایش/حذف نکن.
- اگر کار نیمه‌تمام است، صریح بنویس «ناتمام — ادامه در …».
- هر ادعای «سبز» باید run-id داشته باشد.
- این فایل در هر commit مربوط به خودش همراه کد commit شود.

---

## 10. لاگ کار ایجنت‌ها

### [2026-09-05 ~17:30 UTC] — ایجنت Arena (ادامه F3) — بستن نهایی F3
- فاز/محدوده: F3 close-out
- اقدامات: بازیابی دسترسی GitHub؛ تأیید runهای سبز نهایی (33982059480→33982970314→**33983240011 روی 1f2b1e7 هر ۵ job**)؛ commit «honesty note» 820d4b1 + evidence 1f2b1e7؛ گزارش نهایی report-f3.md ارائه شد (۲۱/۲۱ AC؛ کاتالوگ ۱۴ باگ production در §4.1). کاربر «ادامه بده» → ورود F4.
- کامیت‌ها: 820d4b1, 1f2b1e7
- CI: 33983240011 = SUCCESS (1f2b1e7)
- وضعیت tree: clean

### [2026-09-05 18:00–19:05 UTC] — ایجنت Arena — فاز F4 کامل (مراجعه/صف)
- فاز/محدوده: F4 کامل — Backend + UI + تست‌ها + مستندات
- اقدامات (خلاصه):
  - Recon: تعارض فازبندی گزارش F3 §6 با roadmap حل شد (roadmap مقدم: F4=صف، F6=مالی؛ doc-sync در 5d46a11).
  - `VisitException` + `VisitRepository` (ADR-0021، بدون تغییر schema) + `VisitService` (V1–V15 با Row-Lock J-1، تاریخچه append-only J-3، J-4 اولویت فوری از is_walkin_express، J-5 قفل بیمار، J-6 سقف recall، ER-06 دیرهنگام→no_show+ویزیت فوری walk-in-like، FR-5.5 sweep، T9 در check_out و waive) + `QueueController` (D1/D6/D7/D8/D16 + E1–E6 + E14 + R1 با ETag/304 و rate-limit) + `VisitsNoShowHandler` + `SecretaryQueuePage` (داشبورد منشی: امروز/Drawer/Walk-in/Keyboard، polling 3s، Page Visibility).
  - تست‌ها: VisitFlowTest، RestQueueTest، VisitConcurrencyTest (TP-03b سه‌لایه: fork موازی DB + سرویس + Row-Lock)، JobQueueTest + تست چرخه جاب‌ها.
  - باگ‌های واقعی رفع‌شده: (۱) تداخل کلید status در envelope خطای RestBase (پاسخ 0)؛ (۲) جاب‌های تکرارشونده one-shot بودند (میراث F1) → زمان‌بندی idempotent در هر tick؛ (۳) E14 route ثبت شد (D16 بدون آن بی‌معنا — مستند در گزارش §4-2)؛ (۴) typo FQCN ×۲.
- کامیت‌ها: 0e77f6a, 5d46a11, b5c1eb7, a79f5c3, 39b8d7b, 9078ef0, 349d557, 73390d3, 98d18df
- CI: زنجیره 33985879588 (قرمز 6F/2E → ریشه‌یابی) → 33986638561 ✓ → 33986863341 ✓ → 33986951258/33987173206 (قرمز typo) → **33987189105 ✓ (349d557)** → 33987457187 ✓ → 33987706167 ✓ (98d18df). Integration: 133 تست/575 assertion، 0 skip.
- تصمیمات درون‌فازی (مستند در report-f4 §4): E14 در F4؛ T9 در waive؛ R1 محدود به امروز؛ اولویت صف بدون ستون جدید؛ no-show فقط confirmed (ماشین PENDING→no_show ندارد).
- موارد باز: drift capability 49/46؛ PHPStan؛ SmsController::can()؛ UI پزشک → F5.
- گزارش فاز: `docs/phase-reports/report-f4.md` (۲۱/۲۱ AC). Roadmap F4 → ✅.
- وضعیت tree: clean

### [2026-09-05 ~19:40 UTC] — ایجنت Arena — پاک‌سازی نهایی CI + تأیید
- فاز/محدوده: cleanup + verification
- اقدامات: verify شش قلم بازبینی کارفرما از repo (bootstrap/namespace، table naming، idempotency key، migration idempotent، child-process cleanup، CI) — همه حل‌شده؛ حذف باقی‌مانده‌های دیباگ workflow (DIAG grep مرده + step Diagnose schema state)؛ تأیید ۰ skip؛ unit 4×PHP سبز؛ integration سبز.
- کامیت‌ها: acb8103
- CI: **33988080619 = SUCCESS روی acb8103 (HEAD) — هر ۵ job**؛ PR #1 rollup ۵/۵ SUCCESS.
- وضعیت tree: clean

### [2026-09-05 ~20:00 UTC] — ایجنت Arena — این فایل راهنما
- اقدامات: ایجاد `docs/agent-guide.md` (همین فایل) به‌عنوان راهنمای جامع ادامه پروژه + پروتکل لاگ ایجنت‌ها.
- کامیت: 4fbcbed | CI: 33988491339 = SUCCESS (هر ۵ job).
- مورد باز: **ورود به F5 نیازمند تأیید کارفرما** (طبق Governance). تصمیم باز پیشنهادی: بستن drift capability قبل از F5.

### [2026-09-05 ~20:15 UTC] — ایجنت Arena — رفرش snapshot
- اقدامات: درخواست مجدد کارفرما برای راهنما → verify شد (فایل موجود، CI سبز)؛ فقط HEAD در snapshot به‌روز شد (4fbcbed). بدون تغییر محتوایی دیگر.
- وضعیت tree: clean

### [2026-09-05 ~20:35 UTC] — ایجنت Arena — کشف‌پذیری راهنما + doc-sync
- فاز/محدوده: مستندسازی (بدون تغییر production)
- اقدامات: (۱) ارجاع الزامی به این فایل در بالای `docs/README.md` (ایندکس اسناد) + به‌روزرسانی خط وضعیت فازها (F2.5/F4 اضافه شد)؛ (۲) ثبت رسمی **F4 در CHANGELOG افزونه** (بخش Added کامل + بخش Fixed برای دو باگ واقعی: تداخل کلید status در envelope و جاب‌های تکرارشونده one-shot) — F4 در CHANGELOG ثبت نشده بود.
- کامیت‌ها: همین ورودی | CI: docs-only — دو run مشابه قبلی سبز
- وضعیت tree: clean

### [2026-09-05 ~21:10 UTC] — ایجنت Arena — تکرار پنجم درخواست راهنما → verify
- فاز/محدوده: مستندسازی
- اقدامات: کارفرما همان درخواست را پنجمین بار فرستاد → وضعیت verify شد (فایل موجود با ۸ ورودی لاگ، کشف‌پذیر از README ریشه/docs/README/README افزونه، CI سبز روی 96a69c6 = run 33989243385، tree clean). هیچ کار جدیدی باقی نمانده بود؛ این ورودی صرفاً برای صداقت لاگ. سؤال مستقیم از کارفرما برای تعیین قدم بعد (F5 / مشکل نمایش / نیاز متفاوت).
- وضعیت tree: clean

### [2026-09-05 — منتقل‌شده] — ایجنت Arena-2 (شاخه `arena/01a07281-doctor` / PR #2) — بازبینی اولیه و ساخت دفترچه تحویل
> ورودی از `AGENTS.md` ریشهٔ شاخهٔ Agent دوم (b9467da) به اینجا منتقل شد (ادغام راهنماها — §5.3 گزارش F4). متن اصلی حفظ شده است.

- **هدف درخواست:** مطالعه وضعیت پروژه و ایجاد راهنمای پایدار برای Agentهای بعدی.
- **وضعیت قبل از شروع:** Repository شامل مستندات کامل و کد F1/F2/F2.5 و بخش‌هایی از F3 بود؛ F3 در Roadmap در حال انجام ثبت شده است.
- **اقدامات:** بررسی ساختار کامل Repository/مستندات/کد/تست‌ها؛ ایجاد `AGENTS.md`؛ ثبت وضعیت فازها، اصول توسعه، کارهای باقی‌مانده و قالب لاگ.
- **تست‌های اجراشده:** بدون اجرای تست (فقط بررسی Repository).
- **وضعیت Git:** commit 7b7131a روی شاخه `arena/01a07281-doctor` (base: main 210d437).
- **یادداشت ایجنت فعلی:** بخش‌های مفید این دفترچه (پروتکل لاگ، چک‌لیست پایان کار، ترتیب فازها) در همین راهنمای واحد جذب شد؛ `AGENTS.md` ریشه به پوینتر کوتاه تبدیل شد.

### [2026-09-05 — منتقل‌شده] — ایجنت Arena-2 (شاخه `arena/01a07281-doctor` / PR #2) — شروع F4 / Visit و Queue Slice 1
> ورودی از `AGENTS.md` ریشهٔ شاخهٔ Agent دوم (b9467da) به اینجا منتقل شد. کد این slice در Audit رسمی F4 (گزارش `report-f4.md` §5) بررسی شد: باگ‌های واقعی (permission_callback همیشه 403، نقش‌نگاشت غلط سرور، duplicate→existing به‌جای 409، race در walk-in) و نبود تست؛ merge نشد — ایده‌های خوب (Fallback Throwable، پروتکل لاگ صادقانه) اتخاذ و در شاخه اصلی پیاده شد.

- **هدف درخواست:** شروع فاز F4 پس از تکمیل نسبی F3.
- **وضعیت قبل از شروع:** State Machine و Schema مربوط به Visit/Queue از قبل وجود داشتند، اما Service، Repository و REST Controller فاز F4 وجود نداشتند. تغییرات F3 در PR شماره 1 در این شاخه merge نشده بودند.
- **اقدامات:** `VisitRepository` + `VisitService` (Check-in/Walk-in/Enqueue/Transition/Recall/Audit/History) + `VisitController` + wiring `App.php` + گزارش شروع F4.
- **تست‌های اجراشده:** PHP در محیط فعلی نصب نبود — PHPUnit و lint اجرا نشد.
- **ریسک‌های گزارش‌شده توسط خود Agent:** تست Integration، REST Argument Validation، IDOR، Today Dashboard، Real-time Polling و هماهنگی با F3 باقی مانده بود.
- **وضعیت Git:** commit b9467da روی شاخه `arena/01a07281-doctor`.

### [2026-09-05 ~21:45 UTC] — ایجنت Arena — Audit PR #2 + تکمیل §18 لایسنس صف/مراجعه → بستن F4
- فاز/محدوده: F4 close-out (دستور ۳۴-بندی کارفرما)
- اقدامات:
  - **Audit کامل PR #2** (`arena/01a07281-doctor` @ b9467da؛ base اشتباه 210d437 بدون fixهای F3): ۴ باگ واقعی (permission_callback `=== null` همیشه 403؛ نقش‌نگاشت سرور patient→secretary؛ duplicate→existing به‌جای 409 قرارداد §10؛ race در walk-in بدون قفل بیمار + recall هاردکد + بدون تست). عدم merge مستند؛ PR با کامنت Audit بسته شد (شاخه حفظ شد). جزئیات: `report-f4.md` §5.
  - **سیاست لایسنس §18 پیاده شد:** `LicenseGate` تزریقی در `VisitService` (الگوی BookingService؛ بدون Network Call)؛ Walk-in مستقل در Read-Only → `CLINIC_LICENSE_BLOCKED/503`؛ Check-in نوبت موجود و Transitionهای ویزیت در جریان مجاز. تست `VisitLicenseGateTest` (۵ تست با Gate تزریقی).
  - غنی‌سازی آمار داشبورد (`appointments_today/appointments_no_show/walk_in_today`)؛ حذف PHI غیرضروری از SELECT صف؛ Fallback `Throwable` در `QueueController::guard` (اتخاذ از PR #2).
  - انتقال لاگ‌های Agent-2 به همین فایل؛ `AGENTS.md` ریشه → پوینتر به این راهنما.
- کامیت‌ها: 4eb8eaa (کد+تست) + کامیت docs همین ورودی (شاخه arena/01a071c4-doctor)
- CI: پس از push بررسی و ثبت می‌شود (در ورودی بعدی تکمیل می‌گردد).
- وضعیت tree: پس از docs-commit → clean
- گام بعد: **F5 — Clinical با تأیید کارفرما آغاز می‌شود**؛ قدم اول: بستن Capability Drift طبق Permission Matrix و Least Privilege (Technical Alignment)، سپس استخراج Scope F5 از اسناد + cross-check با کد.

### [2026-09-06 23:00 UTC] — ایجنت F6 — مالی کامل + ADR-0026

- **F6 کامل شد** (جزئیات: `report-f6.md`): FinanceService/Controller/UI/Repos + ۱۷ تست Integration؛ CI سبز ۵/۵ روی 7121d56 (run 33996401245)؛ Integration = ۲۰۳ تست.
- **ADR-0026** ثبت شد (تصریح کارفرما: نقش‌های پویا/Capability/Scope/Responsive) — بررسی معماری بدون Blocker؛ نقشه مهاجرت Debt نقش-محور در ADR؛ مستندات SRS/Permission/Security/Roadmap هم‌راستا.
- **توقف طبق پروتکل:** F7 (دست‌خط) شروع نشده — منتظر تأیید کارفرما.

### [2026-09-06 ~23:55 UTC] — ایجنت Arena — F7 دست‌خط کامل (CI نهایی در انتظار اتصال GitHub)

- **F7 پیاده شد** (جزئیات: `report-f7.md`): Migration 0004 (background_attachment_id + ایندکس GC)، HandwritingService/Repository/Controller (F1/F1b/F1c/F2/F3 + پروتکل Revision ADR-0014 + Idempotency عمومی با context=pageId)، HandwritingGcHandler + RECURRING_JOBS، Settings hw.version_keep/max_age_days، DoctorHandwritingPage (ویرایشگر تمام‌صفحه: DPR/Pressure/Coalesced/Touch=pan-pinch/Erasor سطح-Stroke/Undo/Redo/Zoom/Full-screen/Multi-page/Template/Annotation E16/Auto-save→IndexedDB+PUT/Backoff 5..1800s/Resume online+focus/Conflict دو تب/Recovery محلی)، دکمه‌های 🖋️ در DoctorDashboardPage، HandwritingFlowTest (۱۵ تست).
- **سه ریشه شکست CI پیدا و رفع شد:** (۱) import غلط `Infrastructure\Security\Settings` به‌جای `Settings\Settings` (TypeError ساخت سرویس — آبشاری به همه REST/Jobs)؛ (۲) `pageRow` بدون document_id (INSERT نمی‌افتاد → insert_id کهنه → findPage=null) + گارد RuntimeException روی insert شکست‌خورده در Repository؛ (۳) **Flakiness نیمه‌شب UTC** در تست‌های Visit/Queue (gmdate('Y-m-d') با time()±N جفت می‌شد — اجرای 23:00–00:00 UTC مسیر lazy no-show می‌گرفت؛ اصلاح: تاریخ+ساعت Slot از یک timestamp در VisitFlowTest/VisitLicenseGateTest/RestQueueTest).
- **نکته برای CI های آینده:** CI ساعت ~23:3x UTC اجرا شد و Flakiness بالا آمد — اگر دوباره خطای 'walk_in' به‌جای 'scheduled' دیدید، اول ساعت UTC را چک کنید؛ ریشه اصلاح شد ولی بد نیست بدانید.
- **توکن GitHub وسط جلسه منقضی شد** — پس از اتصال مجدد، سندباکس بازسازی شده بود (کلون تازه): بازیابی با reset به b3875ea (نوک remote) + working tree حفظ‌شده = کامیت بازسازی‌شده 75cf798 (محتوای 190d8c5+130cb73 یکجا).
- **CI نهایی سبز ۵/۵:** run **34011934801** @ **4286aff** — Integration = ۲۱۸ تست ۰ خطا (اصلاح آخر: مقایسه Idempotent-replay با ksort — JSON objectها بدون‌ترتیب‌اند؛ ترتیب کلیدهای پاسخ ذخیره‌شده در decode با پاسخ تازه فرق داشت، مقادیر یکسان).
- **مشاهده برای F9 (Hardening):** ایندکس یونیک `cpms_idempotency_keys.u_idem_key` فقط روی `key` است ولی SELECT کتاب‌keeping چهار ستونی است (key, endpoint, wp_user_id, context_id) — کلید تکراری بین Contextها → INSERT بی‌صدا می‌افتد (بنر Duplicate در لاگ تست‌ها، بی‌ضرر فعلی). بازبینی ایندکس در F9 (جزئیات: report-f7 §9).
- **F7 بسته شد** — HEAD نهایی: **cf290be** (کد 4286aff + docs) — CI نهایی روی HEAD: run **34012034597** سبز ۵/۵. Tree پاک. توقف طبق پروتکل: F8 (اعلان+گزارش) منتظر تأیید کارفرما.

### [2026-09-07 ~00:30 UTC] — ایجنت Arena — شروع F8 (اعلان + گزارش)

- **ورود به F8 پس از تأیید کارفرما.** Scope: Notification Layer (رویداد→queue→adapter؛ N-1..N-6)، رویدادهای FR-20.2، SMS روی معماری Provider-agnostic موجود (ADR-0025)، Jalali در Templates، Queue/Retry/Dedupe، یادآوری نوبت/Follow-up، ۱۲ گزارش FR-19.2 با مدل مجوز+scope (ADR-0026/D-8)، Export (CSV محافظت فرمول‌اینجکشن، Watermark، Audit EXPORT، دانلود محافظت‌شده)، TP-13 + Report Tests.
- **تحقیق تکمیل:** notifications.md (کامل)، background-jobs.md (appt.reminder/notif.dispatch/fu.reminder/report.export)، SRS FR-19/20/21، api-contract G5/G6/R2، data-dictionary §32/33، permission-matrix §2/§3/§6، ADR-0026، performance-baseline (Export async)، file-storage (محافظت ساختاری)، wireframes (Toast منشی).
- **کد پایه موجود:** SmsService provider-agnostic کامل (templates/dedupe/queue/retry) + SmsSendJobHandler + ارسال APPTconfirmed/cancelled/rescheduled از BookingService (Jalali از قبل). cpms_notifications موجود ولی بدون NotificationService و بدون ستون read_at (نیاز Migration 0005). RolesAndCapabilities: REPORT_READ/EXPORT/FINANCE_READ موجود. FinanceService.summary موجود (D18) ولی بدون Scope پزشک.
- **تصمیم‌های طراحی F8:**
  - Internal channel = cpms_notifications (جدید NotificationService)؛ SMS = پایپ‌لاین موجود cpms_sms_messages (بدون دو-صف کردن)؛ Email/Push = V1 رها (اختیاری در کاتالوگ). PAYMENT.receipt اختیاری → V1 رها.
  - cpms_notifications باقی می‌ماند queue-native (N-2): INSERT queued؛ Job `notif.dispatch` (هر دقیقه، RECURRING) → queued→sent + Archive>90d (retention delete).
  - Dedupe: الگوی SmsService (SELECT قبل INSERT؛ UNIQUE dedupe_key)؛ کلید per-recipient (`apt:{id}:confirm:u{userId}` / `:p{patientId}`).
  - Scope گزارش‌ها: پزشکِ متصل (cpms_clinicians.wp_user_id) = OWN سرور-side (فیلتر clinician_id اجباری)؛ Aggregate مطب فقط برای دارنده cpms_report_read بدون Clinician-Link (اعطای صریح، الگوی حسابدار ماتریس §6)؛ پزشک متصل هرگز Aggregate کل مطب نمی‌گیرد (403) — D-8/D-15 + قواعد کارفرما.
  - تفکیک Aggregate⊥Detail: گزارش مالی (revenue/payment_methods/open_balances) = جمعی بدون نام بیمار + نیاز finance_read؛ گزارش عملیاتی با نام بیمار = نیاز patient_read؛ follow_ups_due = نیاز medical_read. Notes خصوصی هرگز در هیچ گزارشی نیست (کوئری نمی‌شوند).
  - Export: POST → Job `report.export` (async طبق baseline §18) → CSV (BOM + ساکس فرمول‌اینجکشن) در LocalFileStorage (خارج webroot) + اعلان Internal «آماده شد» به درخواست‌دهنده (الگوی background-jobs: «فایل + اعلان»)؛ دانلود فقط مالک + cpms_export + Audit EXPORT (request و download). PDF سرور = Backlog (پیش‌زمینه F6)؛ Print View با Watermark (کاربر+زمان) برای چاپ مرورگر.
  - یادآوری‌ها: appt.reminder/fu.reminder به‌صورت RECURRING per-tick + dedupe (J-2)؛ Quiet hours 08:00–21:00 فقط روی SMS غیرتعاملی (یادآوری‌ها)؛ OTP مستثنا (مسیر inline موجود دست‌نخورده).
- قدم بعد: Migration 0005 → NotificationService/Repository → Jobs → Wiring (Visit/Booking) → Controllers (G5/G6/R2) → ReportService/Export → UI Badge → تست‌ها → docs.

### [2026-09-06 ~06:15 UTC] — ایجنت Arena — F8 اعلان + گزارش کامل (CI سبز ۵/۵)

- **F8 پیاده و بسته شد** (جزئیات: `report-f8.md`): Migration 0005 (cpms_notifications.read_at + idx_notif_patient)، NotificationEvents/NotificationService/NotificationRepository (Dedupe الگوی SmsService + Cancel-on-appt-cancel + Quiet-hours + retention 90d داخل notif.dispatch هر ۲ دقیقه)، ۴ Job تکرارشونده (notif.dispatch/appt.reminder/fu.reminder/report.export)، Wiring رویدادها (QUEUE.called/ready_payment در VisitService؛ APPT confirmed/changed/cancelled SMS+Internal در BookingService)، NotificationsController (G6 inbox/read + R2 ETag/304/since/rate 60)، UI زنگ/پنل/Toast در SecretaryQueuePage، ReportService (۱۲ گزارش + Scope سرور-side D-8/D-15 + تفکیک Aggregate⊥Detail + بازه Bounded)، ExportService (async CSV با BOM+Formula-guard خارج webroot + اعلان آمادگی + دانلود مالک‌محور + Audit EXPORT + retention 7d/410)، Print View با Watermark؛ Settings شش کلید جدید؛ ۲۲ تست Integration جدید (NotificationFlowTest ۱۱ + ReportsAuthzTest ۱۱).
- **CI کد:** run **34015519073** @ **f11f6e0** سبز ۵/۵ — Integration = **۲۴۰ تست، ۰ خطا** (۲۱۸ قبلی + ۲۲ جدید). **CI docs نهایی:** run **34015846043** @ **c433416** سبز ۵/۵.
- **سه درس رفع‌شده در دو iteration CI:** ① `private const NS` نمی‌تواند protected والد را override کند (Fatal — بازتعریف ثابت فقط گسترده‌تر)؛ ② **`rest_do_request` رشته کوئری در route را پارس نمی‌کند** (regex مسیر `$`-انکر → 404) — پارامترهای GET در تست‌ها با `set_param` ست شوند؛ ③ ارجاع `&$this->prop` به typed property مقداردهی‌نشده در PHP 8.1+ خطاست (مقدار اولیه `= 0`).
- **F8 بسته شد** — HEAD کد: **f11f6e0** + docs نهایی؛ CI روی docs نهایی هم سبز (run 34015846043، ۵/۵). Tree پاک. توقف طبق پروتکل: F9 (Hardening) منتظر تأیید کارفرما.

### [2026-09-06 ~07:20 UTC] — ایجنت Arena — تصمیم محصول ADR-0027 (یک محصول چندپزشکی) + بازبینی آمادگی معماری

- **تصمیم نهایی کارفرما ثبت شد (ADR-0027):** One Product / One Core / One Database / Adaptive UX — مطب تک‌پزشکی = زیرمجموعه درمانگاه چندپزشکی؛ دو Plugin/Codebase/Fork ممنوع؛ Scope همیشه سرور-side؛ Specialty = دامنه (نه نقش Authorization)؛ Patient = clinic-level؛ مالی هرگز به بالینی imply نمی‌شود.
- **بازبینی آمادگی انجام شد** (`docs/architecture/multi-doctor-readiness-review.md`): ممیزی ۳۷ جدول + کد + اسناد روی ۱۰ محور درخواستی. **نتیجه: ۰ FOUNDATIONAL CHANGE REQUIRED، هیچ STOP-blocker؛ ۱۱ قلم Minor Alignment به فازها نگاشت شد**.
  - شواهد کلیدی: `clinician_id` از روز اول در Schedule/Slot/Appointment/Visit/Note/RX/FollowUp (ADR-0003)؛ Patient clinic-level + MRN کلینیک‌سوئیپ؛ Booking با clinician_id الزامی (B1/D10)؛ صف با فیلتر clinician؛ داشبورد پزشک ownClinician()؛ F8 Scope سرور-side گزارش‌ها (الگوی Enforcement اثبات‌شده)؛ LicenseGate بدون فرض تعداد پزشک؛ `u_slot(clinician_id, slot_date, slot_time)` از قبل ضد-تعارض بین-مکانی است.
  - Minor Alignmentها (phase-mapped، بدون پیاده‌سازی زودهنگام طبق قاعده کارفرما): گارد مالکیت Transition صف برای پزشک + UNIQUE Index روی clinicians.wp_user_id (تضمین 1:1) → **F9**؛ UX حالت مطب (Skip خودکار Picker با ۱ پزشک) → V1.5؛ Specialty M:N + Booking تخصص‌محور/First-available + سرویس per-clinician + Breakdown Reports + Staff Assignments/Scope + Branch + Department/Room → V2؛ Entitlement لایسنس per-doctor → F10.
  - سه مورد تصمیم‌محور با فرمت ISSUE/CURRENT/TARGET/IMPACT/MIGRATION/OPTIONS/RECOMMENDATION در §2 سند بازبینی ثبت شد (Specialty، Schedule شعبه‌ای + u_sched_day، Enforcement Scope) — توصیه همه: فاز خودشان.
- **Docs sync:** ADR-0027 جدید؛ SRS §1.1/§1.2/§2.1/§2.4 (A-1 بازنویسی، A-5 ابطال‌شده توسط F5، FR-4.11 جدید)؛ permission-matrix §6 یادداشت ADR-0027؛ roadmap v1.1 (F9/V1.5/V2 اقلام چندپزشکی)؛ wireframes/patient.md §10 (رزرو تطبیقی).
- **SRS A-5 ابطال ثبت شد:** فرض قدیمی «دسترسی پزشک به Private Notes سایر پزشکان در V1» — پیاده‌سازی F5 از ابتدا سخت‌گیرانه‌تر بود (مالکیت ویزیت خودش، تست 404) و ADR-0027 آن را قطعی کرد.
- توقف طبق پروتکل: F9 (Hardening) همچنان منتظر تأیید کارفرما؛ Backlog چندپزشکی در فازهای نگاشت‌شده اجرا می‌شود.

### [2026-09-06 ~08:30 UTC] — ایجنت Arena — F9 Hardening کامل (CI سبز ۵/۵)

- **F9 پیاده و بسته شد** (جزئیات: `report-f9.md`): ماتریس کامل T-01..T-24 با Evidence تستی — سه حفره واقعی بسته شد: ① Idempotency برای Endpointهای بدون context (Replay/In-flight خاموش به‌دلیل تبدیل NULL→0 توسط prepare + UNIQUE تک‌ستونه) — Migration 0006 با Preflight + بازنویسی کلاس؛ ② گارد مالکیت ویزیت پزشک (ADR-0027 #3) دولایه با Audit ماندگار قبل از Transaction؛ ③ UNIQUE پیوند clinician↔wp_user (Minor #12) با Preflight راهنما. Cleanup Jobs مرده ×3 فعال + دو Fatal عملیاتی (execute() به‌جای query()؛ require به‌جای require_once در MigrationRunner). Accessibility (dialog/focus/44px). user-guide.md فارسی برای Pilot. Performance NFR-PERF-1: پوشش ایندکس همه Hot-pathها تأیید؛ Benchmark ران‌تایم (k6 @50) صادقانه به محیط مرجع Pilot سپرده شد (در CI قابل اجرا نیست). TP-16: مسیر ارتقا از Restore Legacy تست‌شده + Runbook؛ Drill محیط مجزا = چک‌لیست Pilot.
- **CI نهایی کد:** run **34019234033** سبز ۵/۵ — Integration = **۲۵۴ تست** (۲۴۰ F8 + ۱۴ جدید/الحاقی: SecurityHardeningTest ۹ + MigrationTest +۵). سه راند رفع‌اشکال CI با root cause واحد در هر راند: ① `require_once` MigrationRunner (rollback→re-migrate همان process → true)؛ ② `catch (RuntimeException)` در namespace تست = کلاس ناموجود (AssertionFailedError هم RuntimeException است → گمراه‌کننده) + cleanup تست در finally (DDL وسط تراکنش تست = implicit commit → نشت state)؛ ③ چهار ریشه مستقل: NS بدون اسلش در تست (rest_no_route 404 کاذب)، UPDATE فقط یکی از دو ردیف corrupt، setUp بدون Link پزشک↔clinician، TypeError execute/query.
- **درس‌های F9 برای فازهای بعد:** ① در تستهای namespaced همیشه `\\RuntimeException` بک‌اسلش‌دار؛ ② هیچ تست State-mutatingی بدون `finally` بازیابی وقتی DDL دارد (implicit commit تراکنش WP تست را می‌شکند)؛ ③ مسیر REST در تست‌ها همیشه با اسلش ابتدای namespace (`/clinic/v1/...`)؛ ④ امضاهای `:int` هرگز مقدار `query():bool` برنگردانند — برای تعداد سطر `execute()`؛ ⑤ فایلهای Migration باید side-effect-free باشند تا `require` (نه require_once) امن باشد.
- **Docs sync:** report-f9.md، CHANGELOG (F9)، roadmap F9 ✅، api-contract §0 (دامنه Idempotency چه‌گانه)، user-guide.md (از کامیت کد).
- **F9 بسته شد.** طبق پروتокол و دستور صریح کارفرما: **گزارش Completion ارائه شد و توقف تا تأیید F10 (Go-Live/Pilot عملیاتی) — بدون تأیید وارد فاز جدید نمی‌شویم.**

### [2026-09-06 ~13:10 UTC] — ایجنت Arena — F10 شروع: بنیان لایسنس، بکاپ، بهروزرسانی امن، Health/UX، تست ۱۰۰-راهی (اجرای فاز؛ CI در انتظار)

- **F10 طبق اسپک کارفرما آغاز شد** (اجازه ویرایش/commit/push/PR؛ بدون merge به main). Git delta علیه ممیزی: HEAD `a68241bce` دستنخورده (clean). هیچ FOUNDATIONAL_CONFLICT یافت نشد.
- **ADR-0023 (پروتکل لایسنس)** + **ADR-0028 (مرز Data/Control Plane)** + **ADR-0029 (تحویل امن بهروزرسانی)** پذیرفته و ثبت شد.
- **لایسنس (مکمل Seam F3):** Domain خالص (LicenseStatus/Policy/StateMachine/EntitlementRegistry fail-closed/SignedLicenseGate/LicenseSignature Ed25519) + Infra (VendorGateway + HttpVendorGateway HTTPS/SSRF/Timeout؛ LicenseRepository + Migration `2026_09_07_0008`) + App/LicenseService (فعالسازی سرور و آفلاین-سند؛ وضعیت محلی امضاشده؛ refresh فقط در Job با Backoff). `App::licenseGate()` حالا واقعی است؛ نصب فعالنشده دیگر «بازِ ابدی» نیست — تصمیم کارفرما 2026-09-06: `ACTIVATION_PENDING` (نصب تازه؛ پنجرهٔ ۷ روزه از Migration 0008) و `ACTIVATION_GRACE` (نصب pre-F10؛ مهلت ۳۰ روزه)؛ پایان پنجره بدون سند → RESTRICTED؛ `NOT_CONFIGURED` فقط دفاعی؛ حالت توسعه فقط صریح (`CPMS_DEV_MODE`/فیلتر `cpms_license_dev_mode` → `DEVELOPMENT`) بدون تشخیص خودکار محیط؛ anti-reset + مرجع زمان سرور. وضعیتها: ACTIVE/EXPIRING/GRACE/RESTRICTED/SUSPENDED/REVOKED/INVALID/UNREACHABLE؛ قطع شبکه ≠ نامعتبر/پنجره.
- **بکاپ/بازیابی:** موتور داخل افزونه (db.sql تمام cpms_* با snapshot سازگار بدون mysqldump + mirror storage + مانیفست sha256) در ProtectedBackupStore؛ Job دوره‌ای `backup.run`؛ Retention؛ Preflight + Safety Backup + Restore با تأیید صریح (فقط cpms_*)؛ CLI `bin/cpms backup …`.
- **بهروزرسانی امن:** مانیفست انتشار Ed25519 با کلید جدا (ReleaseKeys)، بدون eval/کد از راه دور؛ entitlement گیت feature `updates`؛ UpdateService + کش transient.
- **Health/UX:** SystemHealthService (چکهای بدون PHI + Host Capability SUPPORTED/WARNINGS/UNSUPPORTED) + صفحه «CPMS (سیستم)» (مجوز/Health/بکاپ/Restore/بهروزرسانی؛ cap `cpms_config`؛ nonce؛ بدون PHI).
- **آزمون پذیرش ۱۰۰-راهی (§27/§28):** `SlotCapacityOneHundredWayTest` — ۱۰۰ فرایند همزمان با اتصال مستقل MySQL روی مسیر واقعی `SlotRepository::atomicBook`؛ ظرفیت ۱ → دقیقاً ۱ برنده؛ ظرفیت ۳ → دقیقاً ۳ (در CI اجرا میشود؛ این sandbox MySQL ندارد).
- **واحدتست محلی (WASM PHP 8.2):** ۲۸۵ تست، ۱۶٬۶۶۱ اِسert، ۰ شکست (۸ خطای محیطی شناختهشده 32-bit؛ ۸ skip نیازمند sodium). **CI سبز ۵/۵** (Unit 8.1–8.4 + Integration WP6.7/MySQL8 — run 34037362222؛ شامل 100-way پذیرش و تستهای پنجرهٔ فعالسازی). اجرای محلی WP/MySQL در این sandbox ممکن نیست (BLOCKED_BY_ENVIRONMENT).
- **گزارش F10 و توقف تا تأیید کارفرما** طبق §49–§51 در ادامه همین لاگ ثبت خواهد شد.
