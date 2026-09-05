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

**CPMS** = سیستم مدیریت مطب (Clinic Practice Management System) به‌صورت **افزونه WordPress** (PHP 8.1+، MySQL 8، WP 6.7+) — تک‌کلینیک در V1، تجاری با لایسنس.

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

### آخرین وضعیت فنی (verify شده)

- **HEAD فعلی:** `4fbcbed` روی شاخه `arena/01a071c4-doctor` — همه push شده، tree clean
- **PR #1:** OPEN — هر ۵ چک SUCCESS (Unit PHP 8.1/8.2/8.3/8.4 + Integration WP 6.7.2 + MySQL 8)
- **آخرین run سبز:** 33988080619 روی `acb8103` (هر ۵ job)
- **تست‌ها:** Unit ≈ ۲۱۰ تست/۳۳,۳۲۹ assertion سبز (CI)؛ Integration = **۱۳۳ تست/۵۷۵ assertion سبز، ۰ skip** — شامل تست‌های concurrency با fork واقعی
- **Schema:** ۳۹ جدول `cpms_*` (مهاجرت‌های 0001–0003)
- **Capabilities:** ۴۹ ثابت کد در برابر ۴۶ ماتریس سند (drift باز — بند §8-2)

### نقشه قابلیت‌های ساخته‌شده (Backend کامل تا F4)

- **Auth/OTP:** ثبت‌نام/ورود بیمار با OTP موبایل، Patient↔WP-User link، rate limit
- **SMS:** Provider-agnostic (Log/Generic API)، Vault، Queue، Templates، SSRF guard
- **Booking:** تقویم/اسلات/ظرفیت، Hold→Confirm (اتمیک DB-level ضد double-booking)، Idempotency-Key، Reschedule/Cancel با policy، Duration snapshot
- **Patient:** CRUD + جستجو + پروفایل
- **Queue/Visits (F4):** Check-in/Walk-in، ماشین کامل V1–V15 با Row-Lock و تاریخچه append-only، صف FIFO + نوبت فوری، R1 polling با ETag/304، No-show خودکار (lazy + cron)، Checkout/Waive، داشبورد منشی WP-Admin

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

---

## 8. کارهای باقی‌مانده

### فازهای آینده (طبق roadmap — بدون تأیید شروع نشود)

| فاز | محتوا | DoD/تست |
|---|---|---|
| **F5** | بالینی: صفحه ویزیت، Notes+Versions (E8/E9)، Prescriptions (E10/E11)، Recommendations (E12)، Follow-ups (E13)، E7 پرونده کامل، **E15 Reopen/Correction**، File Upload/Stream (E16/E17)، جستجوی جامع (E18)، داشبورد پزشک (UI + Call flow) | TP-06, TP-08, TP-10 |
| **F6** | مالی: Services، Invoice/Payment/Adjustment/Void/Refund (D12–D15)، Receipt PDF (D17)، داشبورد مالی منشی، Checkout Flow کامل (D18) | TP-02, TP-18 + TP-01 بخش مالی |
| **F7** | دست‌خط: Canvas (Pressure/Tools/Zoom/Multi-page)، Stroke Storage، Auto-save + Offline Sync (IndexedDB) + Conflict | TP-12 |
| **F8** | اعلان + گزارش: Notification Layer + Templates (Jalali)، ۱۲ گزارش + Export (Watermark/Audit) | TP-13 |
| **F9** | Hardening: Security Review (T-01..T-24)، Performance، Backup/Restore Test، Accessibility، مستندات کاربری، Pilot | TP-16 + DoD V1 |
| **V1.5** | OCR فارسی، 2FA (TOTP)، Merge UI، ClamAV/Encryption | TP-OCR + 2FA |
| **V2** | Multi-clinic، پرداخت آنلاین، بیمه/آزمایشگاه، Push، Mobile API | — |

**مایلستون‌ها:** M1 (پایان F3) ✓ رسیده — بیمار آنلاین نوبت می‌گیرد | M2 (پایان F5+F6) — چرخه کامل مطب، Pilot داخلی | M3 (پایان F9) — Go-Live V1.

### تصمیمات باز (نیازمند کارفرما)

1. **Drift ماتریس Capability (۴۹ ثابت کد / ۴۶ ماتریس)** — پیشنهاد: قبل از F5 بسته شود (E7–E13 حساس‌اند). شامل بحث «ثبت استثنای روزانه منشی/مدیریت برنامه پزشک» بدون cap موجود.
2. **Availability UI (تقویم/OTP/Profile)** — تصمیم قبلی: فاز UI مستقل پس از فازهای Backend.
3. **PHPStan** — با baseline + وابستگی composer در فاز آینده.
4. **`SmsController::can()`** — envelope استاندارد `CLINIC_*` ندارد (بدهی F2.5 → F8/patch).
5. **ADR-0023 Licensing** هنوز نوشته نشده (برای F10/licensing phase).

### نکات فنی برای F5 (آماده‌شده‌ها)

- Backend صف پزشک (E1/E2/E3/E4/E5/E6/E14) از F4 آماده است؛ فقط UI پزشک می‌ماند.
- ماشین Visit منطق V10 (complete) و V15 (reopen) را از F4 دارد — سرویس `transition()` آماده؛ endpoint E15 با validation بالینی در F5.
- فایل‌ها/Notes/Prescriptions schema از migration 0001 موجود (جدول‌های `cpms_clinical_notes`, `cpms_prescriptions`, `cpms_files`, …) — قبل از پیاده‌سازی ERD بخش 5 را بخوان.
- `completeReferencedAppointment` (T9) و ماشین‌ها را تغییر نده — تست‌های سبز وابسته‌اند.

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
