# راهنمای جامع ادامه پروژه — CPMS (کلینیک)

> **این فایل سند жив پروژه است.** هر ایجنت (AI یا انسان) که روی این repo کار می‌کند **باید** قبل از شروع این فایل را کامل بخواند و بعد از پایان کارِ خود، در بخش «لاگ کار ایجنت‌ها» (انتهای همین فایل) ورودی ثبت کند.

نسخه: 2.0 | آخرین به‌روزرسانی: 2026-09-08 | نگهدارنده: ایجنت‌های Arena (به‌صورت append-only)

---

> # 🔴 مرجع فازبندی — پیش از هر چیز این را بخوان
>
> ## **Owner-approved Phase 0..20 Roadmap = authoritative execution roadmap.**
>
> مرجع رسمی: [`docs/roadmap/roadmap.md`](roadmap/roadmap.md) **§۰**.
>
> نظام‌های **`F0..F10`**، **`Doc-Phase 1..8`** و برچسب‌های **`V1` / `V1.5` / `V2`** از 2026-09-08 **Legacy/Historical** هستند. در متن همین فایل و در گزارش‌های `docs/phase-reports/` فراوان دیده می‌شوند — **آن‌ها را به‌عنوان Roadmap اجرایی فعلی تفسیر نکن.** جدول نگاشتشان در `roadmap.md` §۲-۱ است.
>
> | Phase | عنوان | وضعیت |
> |---|---|---|
> | Phase 0 | Git Checkpoint | ✅ CLOSED — [`report-phase-0-reverification.md`](phase-reports/report-phase-0-reverification.md) |
> | Phase 0.5 | Target Architecture & Migration Plan | ✅ CLOSED — [`phase0.5-target-model.md`](architecture/phase0.5-target-model.md) |
> | Phase 1 | Security Hardening | ⏸ منتظر Gate Approval |
> | Phase 2 | Multi-Clinic Core | ⏳ |
> | Phase 3 | Role & Access Control | ⏳ |
> | Phase 4 | Master Data | ⏳ |
> | Phase 5 | Pricing Engine | ⏳ |
> | Phase 6 | Scheduling Engine | ⏳ |
> | Phase 7 | Appointment Engine | ⏳ |
> | Phase 8..20 | عنوان‌گذاری‌نشده | ⚠️ OPEN DECISION |
>
> ## معماری هدف الزام‌آور — [ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
>
> ```
> Organization → Clinic → Location → Doctor → User/Staff
> ```
>
> **سه قاعده‌ای که بیشترین احتمال نقض ناخواسته را دارند:**
> - **AD-02** — Organization اجباری است. هیچ مسیر موازی برای `Organization = NULL`.
> - **AD-12** — Migration = versioned forward. drop/recreate مسیر محصول نیست.
> - **AD-13** — **`clinic_id = 1` مستقیم در کد جدید ممنوع است** (و همین‌طور `organization_id = 1` و `location_id = 1`). ۵۴ مورد موجود بدهی فنی Phase 2 هستند — اضافه‌کردن مورد پنجاه‌وپنجم ممنوع است.
>
> **قاعدهٔ اجرا از Phase 1 به بعد:** code + tests + documentation باید **همراه هم** به‌روز شوند. checkpoint commit کوچک مجاز است؛ **merge فقط پس از Gate نهایی فاز**.
>
> **تعارض اسناد:** [`docs/drift-register.md`](drift-register.md) — اسنادی که هنوز مدل قدیمی را بیان می‌کنند، با فاز مالک هر مورد.

---

## 0. چک‌لیست شروع کار هر ایجنت (اجباری)

1. این فایل را کامل بخوان (مخصوصاً §5 قواعد کار و §7 دام‌ها).
2. وضعیت repo را verify کن — دستورات آماده:
   ```bash
   git log --oneline -10 && git status --short
   BR=$(git rev-parse --abbrev-ref HEAD)
   gh run list --branch "$BR" --limit 3
   gh pr list --state all --limit 5 --json number,state,title,headRefName
   ```
   > ⚠️ نسخهٔ قبلی این چک‌لیست شاخهٔ ثابت `arena/01a071c4-doctor` و `PR #1` را hardcode کرده بود. آن شاخه منسوخ است و **PR #1 از مدت‌ها پیش MERGED شده** — روی شاخهٔ جاری کار کن.
3. وضعیت فازها را **فقط** از `docs/roadmap/roadmap.md` **§۰ (Owner-approved Phase 0..20)** بگیر — نه از جدول تاریخی `F*` و نه از گزارش فاز قبلی.
4. **قاعده طلا:** Git history منبع تأیید است؛ گزارش‌های قبلی فقط handoff هستند — همه ادعاها را از کد/تست/CI verify کن.
5. هیچ فاز جدیدی بدون تأیید صریح کارفرما شروع نکن (قانون Gate — Section 56).

---

## 1. معرفی پروژه

**CPMS** = سیستم مدیریت مطب (Clinic Practice Management System) به‌صورت **افزونه WordPress** (PHP 8.1+، MySQL 8، WP 6.4+ — runtime تأییدشده روی WP 6.4/6.5/6.6/6.7.2 و PHP 8.1–8.4) — تجاری با لایسنس.

> ⛔ **تصحیح (2026-09-08):** جملهٔ قبلی «تک‌کلینیک در V1» بود. **آن جمله دیگر معتبر نیست.** طبق [ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md) (AD-01)، **Multi-Clinic / Multi-Location جزو Core است** — نه افزودنی، نه V2. مطب تک‌پزشکی هم دقیقاً از همان زنجیرهٔ `Organization → Clinic → Location` استفاده می‌کند (AD-04)؛ «حالت مطب» فقط یک تطبیق UX است، **هرگز یک مسیر کدی جدا نیست**. پیاده‌سازی در **Phase 2**.

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

> ⛔ **این «Snapshot 2026-09-05» تاریخی است.** اعداد به‌روزشده در جدول زیر آمده؛ متن تاریخی زیرش دست‌نخورده مانده.
>
> ### خط پایهٔ اثبات‌شده — ممیزی Phase 0 (بازبینی 2026-09-08)
>
> | سنجه | مقدار صحیح | ادعای منسوخ |
> |---|---|---|
> | جدول `cpms_*` | **۴۱** | ~~۳۹~~ |
> | فایل Migration | **۹** (`0001`..`0009`) | ~~0001–0003~~ |
> | Foreign Key | **۳۹** (۳۸ در CREATE + `fk_hwpage_bg` با ALTER) | — |
> | **FK روی `clinic_id`** | **۴ فقط** ⇒ ۲۱ جدول بدون FK (قید C-8) | — |
> | جدول دارای `clinic_id` | **۲۵** از ۴۱ | — |
> | Capability | **۴۶** (شمارش با `grep 'public const .* = ...cpms_'` بیش‌شمارش می‌کند: ۵ `ROLE_*` + ۱ `OPTION_*`) | — |
> | نقش ثبت‌شده | **۵** — `cpms_patient`, `cpms_secretary`, `cpms_doctor`, `cpms_accountant`, `cpms_manager` | ~~۳~~ |
> | route REST | **۸۰ در زمان اجرا** (۷۷ نقطهٔ ثبت در سورس — عدد را همیشه صریح بگو) | ~~۷۵~~ |
> | `permission_callback` | **۸۸** = ۶۷ `__return_true` + ۲۱ gated (اصلاح‌شده در Phase 1A؛ عدد قبلی ۸۹ یک false-positive از Docblock در `BookingController.php:312` بود). از ۶۷ مورد، **۶۲ guard مؤثر داشتند** (۵۸ داخل handler + ۴ در لایهٔ Service) و **۵ عمداً public** بودند (قید C-6). **وضعیت جاری پس از Phase 1A: هر ۸۸ ورودی gated و `__return_true` = صفر** — [`security/phase1-current-security-model.md`](security/phase1-current-security-model.md) | — |
> | `admin_post_*` / AJAX | **۲۵** / **۰** | — |
> | فایل تست | **۸۴** (۳۳ Unit + ۴۹ Integration) — تعداد *متد* تست بدون اجرای PHPUnit اثبات‌پذیر نیست | ~~۲۰۳ تست~~ |
> | سند tracked در `docs/` | **۸۴** | ~~۸۵~~ |
> | `clinic_id = 1` hardcode | **۵۴** در **۲۳ فایل** — **۲۴ داخل `Infrastructure/Repository/`**، ۳۰ بیرون؛ ۱۳ از ۲۳ فایل Repository (قید C-4) | ~~~۴۴~~ |
> | Default-Parameter مخفی | **۳** — `AuditLogger.php:45`, `Idempotency.php:37`, `Settings.php:145` | — |
> | PR | ۹ PR؛ **PR #1 MERGED**؛ آخرین = **#9 MERGED** | ~~PR #1 OPEN~~ |
> | شاخهٔ کاری | از `git rev-parse --abbrev-ref HEAD` بخوان | ~~`arena/01a071c4-doctor`~~ |
>
> **سه مکانیزمی که در ADRها ادعا شده‌اند و در کد وجود ندارند:** `AccessPolicy` (۰ فایل) · `resolvePatient()` (۰) · Dangling-Check Job (۰ از ۱۶ handler). به‌علاوه `Repository Base` وجود ندارد و **Patient Merge فقط schema است**.

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
- **فازبندی: منبع حقیقت = Owner-approved Phase 0..20 Roadmap** — `docs/roadmap/roadmap.md` **§۰**.
  - خطای رایج ۱: خواندن فاز بعدی از گزارش فاز قبلی — گزارش‌ها فقط خلاصه‌اند.
  - خطای رایج ۲ (**جدید و پرخطرتر**): خواندن فاز از جدول تاریخی `F0..F10` یا برچسب‌های `V1.5`/`V2` در همان فایل. آن‌ها **Legacy** هستند. `V2` منحل شده و محتوایش به Phase 2/3/4 بازتوزیع شده است.
- **تقدم اسناد به‌روزشده:** تصمیم نهایی Owner ← **ADR-0031** ← ADRهای دیگر ← Engineering Baseline ← SRS ← State Machines/ERD ← API Contract ← Settings ← Implementation.
  ADR-0031 بر ADR-0003 (کامل) و بر بخش‌هایی از ADR-0027 و ADR-0026 مقدم است.
- **تعارض شناخته‌شدهٔ سند-با-واقعیت** پیش از هر کار در `docs/drift-register.md` چک شود؛ آن موارد **از قبل ثبت شده‌اند** و نیازی به STOP مجدد ندارند — فقط در فاز مالکشان اصلاح می‌شوند.

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
- **Authorization = Capability، نه نام نقش (ADR-0026 D-1 — همچنان معتبر):** منطق جدید هرگز `if role == X` نمی‌نویسد.
  > 🔄 **به‌روزرسانی (ADR-0031، AD-06):** «(+ Scope در V2)» منسوخ است. مدل هدف: نقش **صفتِ رابطهٔ (User, Clinic)** است نه صفتِ User؛ `User↔Clinic` رابطهٔ **M:N** است (AD-05). نقطهٔ ورود واحد `AuthorizationService::can(wpUserId, capability, ScopeContext{clinicId, ?locationId, ?resource})` می‌شود و فراخوانی مستقیم `current_user_can('cpms_…')` بیرون از آن سرویس **ممنوع** خواهد شد (با architecture test). پیاده‌سازی در **Phase 3** — امروز هنوز `user_can` الگوی جاری است. کلاس `AccessPolicy` که ADR-0002/0026 به آن ارجاع می‌دهند **وجود ندارد**. نقش‌های ستادی سفارشی (حسابدار/دستیار/…) باید بدون تغییر Business Logic کار کنند؛ نام نقش فقط برچسب Audit است. حوزه‌ها مستقل‌اند: مالی ⊥ بالینی ⊥ هویت ⊥ یادداشت خصوصی؛ Cap عمومی هرگز `cpms_private_note_*` را ضمنی نمی‌دهد. همه داشبوردهای ستادی Responsive‌اند (تبلت/قلم = بهینه‌سازی دست‌خط، نه محدودیت دستگاه).
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

- Workflow: `.github/workflows/ci.yml` — ۳ job: **Unit** (matrix PHP 8.1–8.4، بدون WP) + **Integration** (WP 6.7.2 + MySQL 8، PHPUnit 9.6، `tests/bin/install-wp-tests.sh`، root/root، prefix `wptests_`) + **`phpstan`** (Static Analysis — از F1-3/گروه 4؛ `phpstan.neon.dist`: level=3 هم‌تراز وضعیت واقعی کد (با اجرای واقعی/probe)، scope `src + bin`، بدون baseline؛ ارتقا به 4+ نیازمند بازسازی تایپی — کار آینده).
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

### [2026-09-07 ~06:40 UTC] — ایجنت Arena — ممیزی مستقل کامل + Remediation Part 1 (P2/P5/P8/P10)

- **فاز/محدوده:** پس از ممیزی مستقل فقط-خواندنی کل کد روی `6e42519` (خواسته کارفرما: «همه مشکلات را پارت‌به‌پارت رفع کن») — Part 1 = ۴ ایراد از ۱۳.
- **اقدامات:**
  - **P2 (دسترسی نقش‌ها):** ADR-0030 — Override مدیریتی `cpms_role_caps_override` + صفحه «CPMS (دسترسی‌ها)» (`cpms_config` + Nonce + Audit `ROLE_PERMISSION_CHANGED`/`RESET`)؛ Self-healing اکنون مبنای Override را محترم می‌شمارد؛ خارج از فهرست همچنان پاک می‌شود (TP-10 سالم). نقش بیمار غیرقابل‌ویرایش (P-5).
  - **P8 (Scope صف پزشک):** `VisitService::today/eventsSince/lastEventId` برای نقش doctor → فقط ویزیت‌های Clinician خودش (بدون اتصال = هیچ)؛ Repository سه متد با پارامتر اختیاری `?int $clinicianId` — بدون Schema change. مطابق Master Context §8.
  - **P5 (مقصد بیمار):** صفحه «نوبت‌های من» (Ownership-only از مسیر `listMine`) + `login_redirect` + مخفی‌کردن Admin Bar + هدایت GETهای wp-admin — فقط برای «بیمار خالص» (multi-role ستادی مستثنی).
  - **P10:** «CPMS (فنی)» → «CPMS (فنی و لاگ)».
  - Docs: ADR-0030، permission-matrix v1.5، user-guide، CHANGELOG 1.0.1، `report-remediation-part1.md`.
- **کامیت‌ها:** روی `arena/01a077e9-doctor` (لیست در PR) — کد + تست + مستندات.
- **CI:** ✅ سبز کامل ۱۴/۱۴ روی `05d50ed` — Integration run 34090300269 (۳۰۷ تست، ۱۱ تست جدید سبز؛ یک شکست اولیه در RoleCapabilitiesOverrideTest به‌دلیل مقایسه ترتیبی به‌جای مجموعه‌ای «بازگشت به پیش‌فرض» → root-cause fix در 05d50ed)؛ Unit 8.1–8.4 + Pilot/Staging Gate + Closure Gate (run 34090297534/34090297520) — همه pass. Closure Gate نشان داد WP 6.4/6.5/6.6 از قبل در Gate پوشش دارد (اصلاح P7 ممیزی).
- **تصمیمات درون‌فازی:** ① Scope پزشک در Service نه Controller (P-1)؛ ② «Override در بکاپ cpms_* فعلی نمی‌آید — fail-safe به پیش‌فرض» در گزارش ثبت شد؛ ③ Cap جدید نسخه‌های آینده برای نقش Override-دار خودکار فعال نمی‌شود (قابل‌پیش‌بینی بودن)؛ ④ P13 (Notes بدون optimistic locking) به‌عنوان رفتار عمدی + ADR آینده ثبت شد.
- **موارد باز:** Part 2 پیشنهادی = UI پزشک/برنامه هفتگی (P1) + چاپ نسخه (P12)؛ Part 3 = UI گزارش‌ها (P4)؛ Part 4 = MariaDB/WP6.4 در CI (P6/P7) + i18n (P9) + JS/CSS جداسازی (P11)؛ Part 5 = پورتال بیمار (P3 — نیازمند تصمیم محصول). **توقف تا تأیید کارفرما.**
- **CI (نهایی):** ✅ سبز ۱۴/۱۴ روی `4d5dcf7` — Integration WP6.7/MySQL8 = **۳۱۵ تست / ۰ شکست** (۸ تست جدید Part 2 سبز)؛ Unit 8.1–8.4 + Pilot/Staging + Closure همه pass (run 34093548834 و 34093539362). یک راند شکست (۳۱۵/۱E+3F) با ریشه‌یابی از کامنت خودکار PR: باگ واقعی voidPrescription + join users + assert جابه‌جای تست → کامیت 4d5dcf7.
- **وضعیت tree:** clean بعد از کامیت.

### [2026-09-07 ~07:40 UTC] — ایجنت Arena — Remediation Part 2: Setup UI پزشک/برنامه (P1) + چاپ نسخه (P12)

- **فاز/محدوده:** تأیید کارفرما برای Part 2 از زنجیره رفع ایرادات ممیزی — دو قلم: بحرانی P1 و عملیاتی P12.
- **اقدامات:**
  - **P1 (راه‌اندازی):** `ClinicianRepository` جدید (فهرست/create/update با چک ۱:۱ صریح + RuntimeException — چون wpdb روی UNIQUE خطا نمی‌اندازد؛ قید DB لایه دوم Race) + صفحه «پزشکان و برنامه» (tools, `cpms_config` + Nonce): لیست/ثبت/ویرایش/غیرفعال‌سازی (حذف فیزیکی ممنوع — FK)، پیوند ۱:۱ کاربر با Select، ویرایش ۷ روز برنامه هفتگی با فرم آرایه‌ای (بدون فرم تودرتوی نامعتبر؛ دکمه «ذخیره روز» per-row؛ حذف روز با فرم hidden مجزا) + استثناهای تعطیلی/مرخصی/بستن — همه از مسیر `ScheduleService` (Audit SCHEDULE_* + بازتولید Slot خودکار). Audit جدید: `CLINICIAN_CREATED/UPDATED/STATUS_CHANGED`.
  - **P12 (چاپ نسخه):** `ClinicalService::prescriptionForPrint()` — `cpms_rx_read` + `requireOwnVisit` (ماتریس 4.3) + Audit `PRESCRIPTION_PRINTED`؛ انتخاب آخرین نسخه غیرواقعی یا rx صریح (rx ویزیت دیگر → 404)؛ خروجی کامل (اقلام/بیمار/MRN/سن/جلالی/شکایت اصلی/پزشک/کلینیک). صفحه مخفی `cpms-prescription-print` با CSS چاپ + واترمارک draft/voided. دکمه «🖨️ چاپ» per-rx در داشبورد پزشک (CFG.can_rx + print_url).
  - تست‌ها: `ClinicianRepositoryTest` (۳) + `PrescriptionPrintTest` (۵) — شامل 404 مالکیت پزشک دیگر و 403 منشی.
  - Docs: report-remediation-part2.md، user-guide (راه‌اندازی از UI + چاپ)، CHANGELOG (ادامه 1.0.1).
- **تصمیمات درون‌فازی:** ① Deactivate-only برای clinician؛ ② برنامه هفتگی از ScheduleService نه SQL مستقیم؛ ③ چاپ فقط پزشکِ خودش؛ ④ «ذخیره روز» = update/create خودکار با u_sched_day؛ ⑤ هر دو قلم CRITICAL ممیزی اکنون بسته — باقی Partها = IMPORTANT/UX.
- **موارد باز:** Part 3 پیشنهادی = UI گزارش‌ها (P4)؛ Part 4 = MariaDB/WP matrix + i18n + JS/CSS؛ Part 5 = پورتال بیمار (تصمیم محصول). **توقف تا تأیید کارفرما.**
- **وضعیت tree:** clean بعد از کامیت.

#### پیوست Part 2 — رفع شکست‌های راند اول CI (ریشه‌یابی از کامنت PR)
- **باگ واقعی تولیدی (کشف تست جدید):** `ClinicalService::voidPrescription` — `$reason` در `use` closure تراکنش نبود → هر ابطال نسخه Warning/Fatal (خط ۴۳۴). هیچ تست قبلی ابطال نسخه را نپوشانده بود. Fix + تست.
- `ClinicianRepository::listAll` — `table('users')` پیشوند cpms_ می‌گرفت (`{wp}_cpms_users` ناموجود) → `dbPrefix().'users'` (الگوی BookingService).
- تست `testUserLinking...` — دو assert با Semantics جابه‌جا نوشته شده بود (خودِ تست اشتباه بود، نه کد) → اصلاح + مستندسازی Semantics در کامنت.
- in-session debug workaround: لاگ job از API مسدود است (results-receiver) — جزئیات شکست از کامنت خودکار PR خوانده شد (مکانیزم موجود ci.yml «Post failures to PR»).

### [2026-09-07 ~09:30 UTC] — ایجنت Arena — F1 Remediation گروه 1: رفع F1-1 (P0) — باگ واحد پنجره در RateLimiter::cleanup
- **فاز/محدوده:** تأیید کارفرما برای ۷ رفع F1 با ترتیب مصوب؛ گروه 1 = F1-1. پروتکل: پیاده‌سازی ← تست ← commit ← push به شاخهٔ feature ← تأیید SHA remote ← گزارش.
- **اقدامات:**
  - **Migration `2026_09_07_0009`:** `cpms_rate_limits.window_sec INT UNSIGNED NOT NULL DEFAULT 3600` (additive، guard با SHOW COLUMNS). پیش‌فرض 3600 = همان واحدی که کد قدیم فرض می‌کرد → رفتار ردیف‌های legacy خنثی.
  - **`RateLimiter::hit()`:** `window_sec` در INSERT ذخیره + `ON DUPLICATE KEY UPDATE window_sec = VALUES(window_sec)` (self-heal ردیف‌های پیش از Migration).
  - **`RateLimiter::cleanup()`:** cutoff مستقل از واحد — `DELETE … WHERE window_id * window_sec < (now - olderThanSec)` (به‌جای `intdiv(time()-olderThanSec, 3600)`).
  - **تست‌های رگرسیون جدید (Integration، روی MySQL واقعی CI):** `testDailyOtpLimitSurvivesCleanup` (سناریوی دقیق OtpService: 3 hit با windowSec=86400 → cleanup(2*86400) هم‌سان Job روزانه → ردیف زنده می‌ماند + `window_sec=86400` + تلاش 4ام همان روز block)؛ `testCleanupKeepsLiveWindowsOfEveryUnit` (86400/3600/60)؛ `testCleanupRemovesExpiredWindowsOfEveryUnit` (ردیف‌های 4 روز پیش با هر واحد حذف می‌شوند). تست قدیمی `testCleanupRemovesOldWindows` حفظ شد (پوشش مسیر legacy با پیش‌فرض 3600).
- **تصمیمات درون‌فازی:** ① حذف ستون در `down()` عمداً no-op (زدن ستون = بازگشت به باگ)؛ ② `VALUES()` در ON DUPLICATE حفظ شد (سازگار MariaDB — alias-syntax در MariaDB نیست)؛ ③ `cleanup()` soft (execute) باقی ماند — شکست پاک‌سازی دوره‌ای نباید tick را شکند (رفتار قبلی).
- **تست محلی (php-wasm PHP 8.5):** lint 3 فایل ✓ + Unit suite 285/0F (همان baseline). **تست Integration: CI (PR push).**
- **وضعیت:** commit + push + SHA remote در ادامه این لاگ ثبت می‌شود.

#### پیوست گروه 1 — رفع شکست راند اول CI (ریشه‌یابی از کامنت خودکار PR)
- **ریشه:** شکست همهٔ Jobهای WP (Integration + Pilot + Closure روی همهٔ runtimes) در یک نقطهٔ واحد بود: `FAIL schema-0008 — 2026_09_07_0009`. Gateها و `MigrationTest` نسخهٔ فعلی schema را **پین** کرده بودند (`2026_09_07_0008`) — نقطهٔ همگام‌سازی طراحی‌شده برای هر Migration جدید.
- **رفع (تضعیف Gate نه، به‌روزرسانی پین):** `schema-0009` در closure-gate (۲×probe + restore-drill grep)، pilot-gate (fresh-install check + upgrade-path check + echo نمایشی) و `MigrationTest` (`LATEST_VERSION` + افزودن `rollbackOne()=0009` به ابتدای زنجیرهٔ rollback در دو تست Preflight/Upgrade — down()ِ 0009 عمداً no-op است و re-migrate آن idempotent).
- **تست‌ها:** Unit 285/0F (php-wasm) + lint ✓؛ Integration/Gates در راند بعدی CI.

#### پیوست گروه 1 (ب) — باگ واقعی دوم: probe اشتباه در Migration 0009 (کشف از 9 شکست Integration راند 2)
- **شواهد:** 9 شکست (7×RateLimiterTest + OtpFlowTest + RestBookingTest) دقیقاً مطابق رفتار «جدولِ 3 ستونه بدون window_sec»: INSERT چهارستونه soft-fail، DELETE با شرط window_sec حذف صفر؛ در حالی که MigrationTest سبز بود (FKها روی جدول واقعی) → ردیف window_sec واقعاً هرگز ساخته نشده بود.
- **ریشه:** `CpmsDb::query()` فقط **bool** برمی‌گرداند؛ در up()ِ 0009 probe با `query("SHOW COLUMNS …")` نوشته شده بود → `empty(true) === false` → شرط «ستون موجود است» همیشگی → **ALTER هیچ‌وقت اجرا نمی‌شد** ولی version با موفقیت ثبت می‌شد (به همین دلیل همهٔ Gateها — که فقط version را پین می‌کنند — سبز ماندند!). دقیقاً همان کلاس خطایی که F1-2 (silent-fail migration) هدف دارد.
- **رفع:** probe با `fetchRow()` + `$col === null` (الگوی استاندارد ماست: 0004/0005/0006) + تست جدید `testWindowSecColumnAddedByMigration` (وجود ستون + int unsigned + default 3600). اسکن کل src: نمونهٔ دیگری از این الگوی نادرست وجود ندارد.
- **درس برای گروه 2 (F1-2):** strict mode روی MigrationRunner عیناً همین کلاس شکست (SQL ناموفق + version ثبت‌شده) را در آینده مسدود می‌کند.

### [2026-09-07 ~12:00 UTC] — ایجنت Arena — F1 Remediation گروه 2: رفع F1-2 (P0) — Migration Fail-loud
- **فاز/محدوده:** گروه 2 از ۷. پیاده‌سازی + تست محلی انجام شد؛ push/CI در انتظار بازیابی اتصال GitHub (توکن GH_TOKEN اعتبار خود را از دست داد — 401 Bad credentials).
- **اقدامات:**
  - **`CpmsDb`:** `setStrict(bool)` + `ensureNoSqlError()` (در Strict mode: `$wpdb->last_error` غیرخالی → `RuntimeException` با پیام خطای SQL) — فراخوانی در انتهای query/execute/fetchRow/fetchAll/fetchValue/insert/update/delete. پیش‌فرض soft (رفتار سایر مسیرها دست‌نخورده).
  - **`MigrationRunner`:** در `migrate()` و `rollbackOne()` — `setStrict(true)` قبل از transaction و `setStrict(false)` در `finally`. چون INSERTِ version داخل همان closure تراکنش است، در throw: نه version ثبت می‌شود (rollback) و نه Migrationهای بعدی اجرا می‌شوند.
  - **تست رگرسیون جدید (Integration):** `testFailingMigrationAbortsAndIsNotRecorded` — MigrationRunner با دایرکتوری temp + Migration آگاهانهٔ شکست‌خورد (`SELECT * FROM جدولِ ناموجود`): assert RuntimeException با «SQL error» + version در `applied()` نیست + Migration خوبِ بعدی اجرا نشده.
- **ارتباط با گروه 1:** دقیقاً همین کلاس خطا در Migration 0009 رخ داده بود (ALTER بی‌صدا رد شد + version ثبت شد)؛ از این پس چنین شکستی در CI/Production fail-loud است.
- **تست محلی (php-wasm 8.5):** lint 3 فایل ✓ + Unit 285/0F. **Integration: CI (در انتظار push).**

### [2026-09-07 ~13:35 UTC] — ایجنت Arena (شاخهٔ `arena/01a07c01-doctor`) — F1 Remediation گروه 3: رفع F1-4 — Audit تغییرات Settings (قبل/بعد + updated_by)
- **رخداد ورودی (گزارش صادقانه):** پیاده‌سازی قبلی گروه 3 (کامیت `06d5498` روی `arena/01a07b2e-doctor`) در هیچ‌جا موجود نبود — نه در workspace جدید (shallow clone با ۱ کامیت)، نه در هیچ ref ریموت (`git ls-remote`)، و فایل `patches/0001-feat-F1-4-*.patch` نیز در ریپو نبود (سندباکس قبلی persist نشده بود). Remote شاخه `arena/01a07b2e-doctor` روی `8d5715d` (همان گروه 2) بود. **تصمیم:** پیاده‌سازی مجدد F1-4 طبق پروتکل و scope تأییدشده PR #5 («Settings audit (before/after + updated_by) + tests») روی شاخهٔ نشست فعلی. این جلسه به شاخهٔ `arena/01a07c01-doctor` قفل است (محیط Agent) — push/PR از همین شاخه.
- **مبنا:** `git reset --hard origin/main` (`a16e245` = merge گروه‌های 1+2) تا delta PR فقط گروه 3 باشد.
- **اقدامات:**
  - **`Settings::set()`** (نقطهٔ واحد همهٔ نوشتن‌ها — ۱۰ call-site): خواندن مقدار مؤثر قبل (ردیف یا `DEFAULTS`) + مقایسه JSON بعد از نوشتن → تغییر مؤثر = Audit `SETTING_UPDATE` (اکشن مرجع audit-strategy §2؛ `resource_type=setting`) با before/after به شکل `{setting, value}` + actor (`updated_by` + نقش WP از `get_userdata`؛ بدون کاربر = actor null → «system»).
  - **مستثناها (ضد سیل Audit ۱۰ساله):** ① تغییر no-op (مقدار جدید = مقدار مؤثر فعلی) — ردیف/updated_by بازنویسی می‌شود ولی Audit نمی‌گیرد؛ ② کلیدهای Runtime/telemetry `RUNTIME_KEYS` = `jobs.last_tick_at` (هر Tick!)، `backup.last_run_at`، `sms.last_test` — OpLog دارند.
  - **قرار امنیتی Vault:** کلید Credential-دار `sms.auth` (`sealed` AES-256-GCM + last4) — رویداد + actor ثبت می‌شود اما مقدار با `[redacted:credentials]` جایگزین می‌شود (حتی Ciphertext به Audit ۱۰ساله کپی نمی‌شود — سیاست «Secret به Audit تعلق ندارد»).
  - **ساختار `{setting, value}` به‌جای کلیدِ خودِ Setting در before/after:** Sanitize فعلی Audit کلیدهای مطابق ممنوعه‌ها (`otp`, `code`, …) را کامل حذف می‌کند — با ساختار ثابت، شناسهٔ Setting (به‌عنوان «مقدار») زنده می‌ماند؛ دقتِ خود Sanitize در F1-8 (گروه 7) اصلاح می‌شود و این ساختار همچنان درست می‌ماند (بدون وابستگی به رفتار فعلی).
  - **Wiring:** `App::settings()` اکنون `self::audit()` تزریق می‌کند (پارامتر اختیاری → سازگار با همهٔ سازنده‌های قبلی؛ بدون وابستگی حلقوی — AuditLogger فقط db+op).
  - **updated_by واقعی:** `SystemPage::backupSave/updateSettings` اکنون `get_current_user_id()` پاس می‌دهند (تا دیروز system ثبت می‌شد)؛ SmsService از قبل `$userId` داشت.
- **تست رگرسیون جدید (Integration — MySQL CI):** `SettingsAuditTest` (۶ تست): before/after+updated_by+نقش (Default 3→5)، no-op بدون Audit، سه کلید Runtime بدون Audit، actor سیستم، Credentials redacted (ciphertext هرگز در after_json نیست)، سلامت Hash Chain بعد از رکوردهای Setting.
- **Docs:** audit-strategy §2 (شرح قانون جدید + مستثناها)، settings-reference v1.4، CHANGELOG 1.0.2.
- **تصمیمات درون‌فازی:** ① Audit بعد از نوشتن Setting (خارج از transaction) — `CpmsDb::transactional` تو-در-تو پشتیبانی نمی‌کند و AuditLogger خودش transaction دارد؛ الگوی موجود سرویس‌ها هم «Audit بعد از Commit» است؛ ② no-op = refresh ردیف بدون Audit (آگاهانه — eventِ تغییر مؤثر ملاک است)؛ ③ `sms.last_test` telemetry است نه Config.
- **تست محلی (php-wasm 8.2):** lint فایل‌های تغییر (Settings/App/SystemPage/SettingsAuditTest) ✓. Unit suite به Settings/App دست ندارد (grep: صفر ارجاع). **Integration: CI (PR).**
- **وضعیت:** commit + push + PR + CI در ادامه این لاگ ثبت می‌شود.

#### پیوست گروه 3 — push + تأیید SHA remote + CI
- **Session-branch نکته:** این نشست به شاخهٔ `arena/01a07c01-doctor` قفل است (محیط Agent)؛ درخواست push به `arena/01a07b2e-doctor` قابل اجرا نبود — و چون `06d5498`/patch هر two موجود نبودند، خروجی واقعی همان پیاده‌سازی مجدد روی این شاخه شد.
- **کامیت:** `d42ef0d` (feat(F1-4)) از مبنای `a16e245` (merge گروه‌های 1+2).
- **Push:** ✅ — Remote SHA: **`d42ef0d9c40e614d775dcc9c7e07b7c417898975`** = local HEAD (تأیید با `git ls-remote`).
- **PR:** #7 (`arena/01a07c01-doctor` → `main`) — «F1 Remediation (cont. 2) — group 3/7: settings audit (F1-4)».
- **CI (روی `d42ef0d`): ✅ سبز ۳/۳** — CI/pull_request run **34128017332** (Unit PHP 8.1–8.4 + Integration WP6.7/MySQL8 = success؛ step «Post failures to PR» skip = صفر شکست) + Pilot/Staging Readiness Gate run **34128001933** (8m34s، success) + Closure Gate run **34128001870** (success). لاگ خام jobها از results-receiver مسدود است (محدودیت شناخته‌شدهٔ محیط) — Evidence: conclusion رسمی GitHub.
- **Tree:** clean. بعد از این docs-commit، CI نهایی روی HEAD ثبت می‌شود.

### [2026-09-07 ~14:55 UTC] — ایجنت Arena (شاخهٔ `arena/01a07c01-doctor`) — F1 Remediation گروه 4: رفع F1-3 — PHPStan در CI (سطح هم‌تراز واقعیت) + docs sync
- **فاز/محدوده:** گروه 4 از 7. «PHPStan added to CI (level matched to real code state) + docs sync» — بدون تضعیف Gate.
- **روش تعیین سطح:** PHP CLI در sandbox نیست (apt مخازن ناقص، باینری استاتیک در GitHub release موجود نبود) → **Probe روی CI**: workflow موقت با اجرای سطح‌های 0..8 و انتشار خروجی در کامنت PR (الگوی «Post failures to PR» — لاگ job از API مسدود است). ۴ راند (رفع constraint stubs `^7.0`، حذف `scanConstants` حذف‌شده در PHPStan 2، رفع artifact ویرایش موازی در انتهای HandwritingService — درس: ویرایش‌های موازی edit_file روی یک فایل ممنوع).
- **باگ‌های واقعی که خود تحلیل پیدا کرد (رفع شد):**
  ① `Admin/SystemPage` — ارجاع به `SystemHealthService::HOST_SUPPORTED*` بدون import درست → کلاسِ `ClinicCore\Admin\SystemHealthService` ناموجود → **Fatal در رندر صفحه «CPMS (سیستم)»** (F10). رفع: import `ClinicCore\Application\System\SystemHealthService`.
  ② `Rest/OtpController:80` — فراخوانی `OtpException::getData()` که وجود نداشت → **Fatal در پاسخ REST برای کد OTP غلط/منقضی** (مسیر رایج کاربر؛ تست‌ها service-level بودند و نمی‌گرفتند). رفع: `getData()` به `OtpException` اضافه شد.
  ③ `OtpService::audit()` — ۵ آرگومان صدا می‌شد، ۴ پارامتر می‌گرفت → meta «remaining» در `OTP_VERIFY_FAIL` بی‌صدا drop. رفع: `$meta` اختیاری + ادغام در after_json (Audit غنی‌تر).
- **لینت/تایپ:** حذف `use`های بلااستفاده (Booking/Clinical/Handwriting)، `callable(): T` برای `CpmsDb::transactional`، PHPDocهای ناسازگار (SmsController/BackupManifest).
- **پیکربندی:** `phpstan.neon` — سطح **3** (سطح 0..3 سبز؛ سطح 4 = ۶۷ خطا — بازسازی تایپی گسترده از ~۲۵ فایل، خارج از scope remediation؛ 5..8 بیشتر). `phpstan-bootstrap.php` (ثابت‌های WP/افزونه: ABSPATH، ARRAY_A، MINUTE_IN_SECONDS، CPMS_PLUGIN_DIR، …). require-dev: `phpstan/phpstan ^2.1` + `php-stubs/wordpress-stubs ^7.0` + `szepeviktor/phpstan-wordpress ^2.0`. ci.yml: job سوم «Static Analysis (PHPStan)» مستقل از Unit/Integration. یک ignore هدفمند+مستند: path-check `require_once` مسیرهای runtime WP در WpUpdateBridge (گارد function_exists موجود). Probe workflow حذف شد.
- **Docs sync:** agent-guide §6 (۳ job)، testing-plan §45 (L.6 → L3 فعلی + هدف ارتقا)، engineering-baseline §38 (اشاره به PHPStan L3 در CI)، CHANGELOG 1.0.2.
- **تست محلی:** lint php-wasm 9 فایل ✓ (بعد از رفع artifact). **PHPStan/Integration/Unit: CI.**
- **وضعیت:** commit + push + SHA remote + CI نهایی در ادامه این لاگ ثبت می‌شود.

#### پیوست گروه 4 — push + تأیید SHA remote + CI
- **کامیت‌های گروه 4:** `a90ea61` (ابزار + probe) ← `17816fb` (stubs ^7.0) ← `0cf7d46` (probe v2) ← `d018dfd` (scanConstants) ← `b3d445f` (باگ‌های واقعی + bootstrap) ← `c92e6f2` (رفع artifact ویرایش) ← **`2cebb07` (قفل سطح 3 + حذف probe + docs sync)**.
- **Push:** ✅ — Remote SHA: **`2cebb07e0221bb5a00e8a02f55d80fad1c0a2b8a`** = local HEAD (تأیید `git ls-remote`).
- **CI (روی `2cebb07`): ✅ سبز ۳/۳** — CI/pull_request run **34130498852** = **۶/۶ job سبز** (Unit PHP 8.1–8.4 + Integration WP6.7/MySQL8 + **Static Analysis/PHPStan L3** — اولین اجرای رسمی Gate استاتیک جدید) + Closure Gate run **34130493200** + Pilot/Staging Readiness Gate run **34130493287** (8m33s).
- **Tree:** clean. ادامه: گروه 5 (F1-5/F1-6 — oplog retention + JobQueue::fail race guard) طبق ترتیب مصوب، در انتظار دستور کارفرما (خواستهٔ این نشست فقط تا گروه 4 بود).

#### پیوست — قطع اتصال GitHub در پایان نشست (گزارش صادقانه)
- بلافاصله بعد از push `0c8c487` و تأیید SHA ریموت، توکن GitHub (gh + git credential) باطل شد (401 Bad credentials — همان الگوی شناخته‌شدهٔ محیط Agent در گروه 2). کامیتِ این یادداشت محلی است؛ بعد از اتصال مجدد GitHub توسط کارفرما push می‌شود.
- **شواهد CI روی `0c8c487` (HEAD نهایی):** CI = **موفق ۶/۶** (run 34131390001 — شامل Static Analysis/PHPStan L3) + Closure Gate = موفق (run 34131386018) — هر دو قبل از قطع، سبز دیده و تأیید شد. Pilot Gate (run 34131385987) در لحظهٔ قطع در حال اجرا بود؛ وضعیت نهایی‌اش از API خوانده نشد — روی `2cebb07` (کد یکسان؛ دلتا فقط ۲ فایل Markdown) Pilot سبز بود (run 34130493287، 8m33s). بعد از اتصال مجدد، کارفرما/ایجنت بعدی می‌تواند وضعیت این run را با `gh run view 34131385987` تأیید کند.

### [2026-09-07 ~17:30 UTC] — ایجنت Arena — F1 Remediation گروه 5: F1-5 (oplog retention) + F1-6 (JobQueue::fail race guard)
- **F1-5:** Setting جدید `retention.oplog_days` (پیش‌فرض **۹۰** — مصوب کارفرما) + Handler `OpLogCleanupHandler` (الگوی OtpCleanupHandler؛ days در هر اجرا از Settings خوانده می‌شود) + `'cleanup.oplog' => 1` در RECURRING_JOBS + ثبت در dispatcher. `cpms_operational_logs` جدول hot بدون Retention بود؛ Audit جداست و ۱۰ساله می‌ماند (audit-strategy §5/§6). Doc: settings-reference v1.5.
- **F1-6:** `JobQueue::fail(..., ?string $workerId = null)` — با workerId: هر دو UPDATE (retry/final) شرط `AND locked_by = %s` می‌گیرند؛ صفر ردیف → `JOB_FAIL_SKIPPED_LOCK_LOST` + return بدون تغییر (زامبی دیگر نمی‌تواند Status/Retry بازنویسی کند). با null: رفتار legacy (bin/cpms jobs retry مستقیماً SQL می‌زند و fail() صدا نمی‌زند — verify شد؛ تنها فراخواننده = JobsDispatcher که اکنون workerId می‌دهد). شمارش ردیف با `execute():int` نه `query():bool` (درس F1-1).
- **تست رگرسیون (Integration):** `JobQueueTest` +۳ (`testFailWithLostLockDoesNotTouchJob` — قفل چرخیده به w2، failِ w1 هیچ تغییری نمی‌دهد؛ `testFailWithOwnerWorkerSchedulesRetry`؛ `testFailFinalWithOwnerWorkerMarksFailed`) + `OpLogRetentionTest` (۴). مسیر legacy fail بدون workerId هم توسط تست قدیمی `testFailRetriesWithBackoffThenFails` پوشش می‌ماند.
- **تست محلی:** lint php-wasm ۷ فایل ✓. **Integration/Unit: CI.**
- **وضعیت:** commit + push + SHA + CI در ادامه ثبت می‌شود.

### [2026-09-07 ~18:00 UTC] — ایجنت Arena — F1 Remediation گروه 6: F1-7 — قفل یکپارچهٔ runTick (WP-Cron = CLI)
- **ریشه:** GET_LOCK فقط در `bin/cpms jobs tick` بود (docblock خودش ادعای «بدون Duplicate Runner» داشت!) و WP-Cron (`cpms_jobs_tick` → `App::runTick`) بدون قفل Tick می‌کرد → دو SAPI هم‌زمان.
- **رفع:** قفل داخل `App::runTick()` — `SELECT GET_LOCK('cpms_jobs_tick', 0)` (ثابت جدید `App::TICK_LOCK`؛ نام همان قفل قبلی برای سازگاری)؛ Skip → return **-1**؛ آزادسازی در `finally` (`RELEASE_LOCK`). helper جدید `App::isTickLocked()` با `IS_FREE_LOCK` (docblock: داخل خود Tick هم true برمی‌گرداند — برای پیام CLI بعد از Skip است). `bin/cpms` دیگر قفل محلی ندارد و همان پیام قبلی را با isTickLocked() چاپ می‌کند. نکته F1-1: GET_LOCK/RELEASE/IS_FREE با پارامتر prepare (%s) — نه string interpolation.
- **تست رگرسیون (Integration):** `TickLockTest` (۳ تست) با **اتصال دوم** `new \wpdb(...)` + set_prefix (الگوی SlotCapacityOneHundredWayTest): ① قفل بیرونی → runTick=-1 + Job queued می‌ماند + isTickLocked=true؛ ② بدون قفل → اجرا + آزادسازی قفل از دید اتصال دوم (IS_FREE_LOCK=1)؛ ③ بازتاب قفل بیرونی در isTickLocked قبل/بعد از Release. tearDown قفل را آزاد می‌کند (ایمنی تست بعدی).
- **سازگاری:** تست قدیمی `JobQueueTest::testRunTickReschedulesAndProcessesRecurringJobs` با قفل جدید هم پاس می‌شود (قفل روی همان اتصال گرفته/آزاد می‌شود). WP-Cron hook فقط return value را نادیده می‌گیرد.
- **تست محلی:** lint php-wasm (App.php، bin/cpms، TickLockTest) ✓. **Integration: CI.**
- **وضعیت:** commit + push + SHA + CI در ادامه ثبت می‌شود.

### [2026-09-07 ~18:25 UTC] — ایجنت Arena — F1 Remediation گروه 7: F1-8 (دقت sanitize) + F1-10 (docblock فالبک StateMachine)
- **F1-8:** `AuditLogger::sanitize` از substring-regex (`/(otp|code|…)/i`) به **تطبیق دقیق نام کلید (case-insensitive)** تغییر کرد — کلیدهای بی‌خطرِ شبیه (`http_code`/`status_code`/`failure_code`) دیگر حذف نمی‌شوند و رکوردهای HTTP/خطا در Audit معنا پیدا می‌کنند. فهرست ممنوعه‌ها گسترده‌تر و صریح شد (`auth_code`, `verification_code`, `passwd`, `access_token`, `refresh_token`, `id_token`, `session_token`, `client_secret`, `apikey`, `authorization`, …). Masking (`MASK_KEYS` از قبل exact) و Truncation و Hash Chain دست‌نخورده. نکتهٔ سازگاری: ساختار `{setting, value}` در Audit تنظیمات (F1-4) همچنان معتبر و بهینه است — شناسهٔ Setting اکنون به‌عنوان «مقدار» هم محفوظ می‌ماند. یادداشت خارج از scope: `OpLogger::sanitize` الگوی substring دارد اما به‌جای حذف، `[masked]` می‌گذارد (آسیب کمتر) — تغییرش در این گروه نیست و به‌عنوان مشاهده ثبت شد.
- **F1-10 (فقط docblock + تست):** قواعد Fallback در `StateMachine` صریح شد — fail-loud برای (from,event) نامعلوم؛ fallback به Candidate بدون محدودیت برای actor خارج از فهرست‌ها (فهرست خالی = همه)؛ ترتیب ارجحیت match؛ نکتهٔ امنیتی (مجوز واقعی در Service). رفتار کد هیچ تغییری نکرد. تست‌های مستندکننده Unit: `testActorFallbackToUnrestrictedCandidate` + `testNoFallbackWhenOnlyRestrictedCandidatesExist`.
- **تست محلی:** lint php-wasm ۵ فایل ✓. **Integration/Unit: CI (راند نهایی).**
- **وضعیت:** commit + push + SHA + CI نهایی (سه pipeline) در ادامه ثبت می‌شود. پس از سبزی: **STOP** — انتظار تأیید کارفرما (طبق پروتکل؛ F2 شروع نمی‌شود).

#### پیوست — شواهد نهایی گروه‌های 4(تکمیل)–7 و STOP
- **گروه 4 (تکمیل):** `463330e` (probe src+bin) → `8db0084` (نهایی: `phpstan.neon.dist`، level=3 بدون baseline — probe: صفر خطا @0..3 روی src+bin، ۷۲ خطا @4؛ job key `phpstan`؛ تصحیح AC-9 در report-f1.md).
- **گروه 5:** `ce751ba` (+`35977f5` docs) — F1-5 (cleanup.oplog + retention.oplog_days=90) + F1-6 (fail race guard). CI run **34139668303** سبز ۶/۶.
- **گروه 6:** `3a403f3` — F1-7 (قفل یکپارچهٔ runTick + isTickLocked + تست اتصال دوم). CI run **34139964706** سبز ۶/۶.
- **گروه 7:** `88183a6` — F1-8 (sanitize exact-match؛ http_code/failure_code حفظ) + F1-10 (docblock فالبک + تست‌های Unit). CI روی HEAD نهایی: run **34140229256** سبز ۶/۶ + Closure **34140225798** سبز + Pilot **34140225799** سبز (۸ دقیقه).
- **پاک‌سازی:** workflow موقت probe حذف شد (در 88183a6+این کامیت).
- **جمع تست‌های جدید این نشست:** SettingsAuditTest (۶) + JobQueueTest (۳+) + OpLogRetentionTest (۴) + TickLockTest (۳) + AuditChainTest (۱+) + StateMachineTest (۲+) = ۱۹+ تست رگرسیون.
- **STOP طبق پروتکل:** هر ۷ گروه انجام/سبز شد — منتظر تأیید کارفرما برای merge PR #7. F2 شروع نمی‌شود.

### [2026-09-07 ~19:30 UTC] — ایجنت Arena (شاخهٔ `arena/01a07cdc-doctor`) — Hotfix نقص‌های نصب واقعی + گیت Real WordPress Acceptance (PR #8)
- **ماموریت کارفرما:** بازتولید سه نقص گزارش‌شدهٔ نصب روی WordPress واقعی (D1: Tools→CPMS (سیستم)/Health = Critical Error؛ D2: CPMS (فنی)→شمارش جداول=0؛ D3: MigrationRunner ممکن است شکست SQL را موفق ثبت کند) + فیکس حداقلی + گیت CI واقعی (ZIP رسمی→WP تمیز→نصب→Migration→DB verification→Browser login→System/Health/Technical/Doctor/Secretary) با دو prefix `wp_`/`clinic_` بدون تضعیف تست.
- **بازتولید (قبل از فیکس — Evidence در Commit Commentها روی main~commits شاخه):** D1 = STILL_PRESENT (قالب `wp-die-message` + «There has been a critical error» روی هر دو prefix)؛ D2 = STILL_PRESENT (`UI=0 / DB=41` روی هر دو prefix)؛ D3 = FIXEDِ قبلی (پروب migration خراب: RuntimeException + عدم ثبت version).
- **ریشه‌ها/فیکس:**
  1. **D1** — `SystemPage::render()` سه فراخوان سرویس (license statusMeta / health run / backup listBackups) را بدون guard انجام می‌داد؛ کلاس‌شکست واقعی: wp-content غیرقابل‌نوشتن برای PHP → `ProtectedBackupStore::ensureGuards()` → `BackupException` → Fatal کل صفحه. فیکس: guard درون‌بخشی + نمایش خطای درون‌صفحه‌ای (صفحهٔ وضعیت = آخرین نقطهٔ دید، هرگز Fatal نشود). رگرسیون: `SystemAdminPagesTest` (۲ تست؛ بازتولید دقیق با «فایل به‌جای پوشهٔ بکاپ»).
  2. **D2** — `SettingsAdmin::tableCount()` از `LIKE 'cpms_%'` بدون prefix به `$wpdb->prefix . 'cpms_%'` (prepared — الگوی SystemHealthService). مانع در-suite تست: جداول TEMPORARY در WP Test Suite در information_schema دیده نمی‌شوند → قفل قطعی = گیت E2E (دو prefix).
  3. **REST 404 روی Permalink ساده (کشف با Console Gate مرورگر):** هلپر JS `api()` در ۵ صفحهٔ مدیریتی path حاوی `?query` را به `rest_url()` می‌چسباند — با permalink ساده (`index.php?rest_route=…`) پارامتر دوم باید با `&` ضمیمه شود وگرنه `rest_no_route` 404 (پولینگ صف/اعلان‌ها/خلاصهٔ مالی/جستجوی بیمار/…). فیکس مرکزی: `apiUrl()` در هر ۵ صفحه (DoctorDashboard/SecretaryQueue/SecretaryFinance/DoctorHandwriting/SmsSettings). Pilot با permalink «قشنگ» این را ماسک می‌کرد.
- **دام‌های زیرساختی که حین ساخت گیت رفع شد (Honesty):** YAML colon-in-scalar در name جاب؛ double-backslash در `wp eval` داخل single-quote؛ pipe-masking با tee (وضعیت خروجی واقعی گم می‌شد → RC capture صریح)؛ فایل‌های افزونهٔ نصب‌شده root-owned → تزریق probe با sudo؛ لاگ خام Job و artifact blob در این محیط مسدودند (sandbox proxy EOF) → Evidence به‌صورت **Commit Comment** منتشر می‌شود + Artifactها در UI گیت‌هاب موجودند. پروب TEMP DEBUG بعد از ریشه‌یابی حذف شد. deny-probe (403 عمدی) روی صفحهٔ بدون watcher منتقل شد تا گیت Console تضعیف نشود.
- **کامیت‌ها:** `cf97dba` (گیت Acceptance) → `85ed9bc` (YAML fix) → `a340cb2` (رفع باگ‌های خود گیت) → `9d9b97a`→`b7ca5e1`→`901c192`→`364ceeb`→`37e69f6` (تقویت evidence) → **`df6567a` (فیکس محصول: D1+D2+REST)** → این لاگ.
- **CI نهایی روی `df6567a`: ✅ همه سبز** — Real WP Acceptance push run **34150553934** (wp_ و clinic_ هر دو **25/25 PASS**؛ actual=41=ui روی هر دو؛ System/Health رندر؛ منوهای نقش‌ها؛ پروب D3؛ منوی Administrator فنی P-3 + Deny 403؛ بدون Fatal در لاگ‌ها) + CI/PR **34150968679** (Unit 8.1–8.4 + Integration + PHPStan) + Acceptance PR **34150968686** + Closure **34150553982** (۵/۵) + Pilot **34150554156** (Release/Upgrade/Staging/Responsive سبز). Artifacts: `rwp-acceptance-wp_` / `rwp-acceptance-clinic_`.
- **PR #8** — باز برای تأیید کارفرما؛ **ایجنت merge نکرد ≻ STOP طبق پروتکل.**

### [2026-09-08 ~22:15 UTC] — ایجنت Arena (شاخهٔ `arena/01a082db-doctor`، PR #11 DRAFT) — بستن OD-9 (مرز ذخیره‌سازی بکاپ) + PHASE 2 PRE-IMPLEMENTATION CHECK — STOP
- **ماموریت کارفرما:** (۱) verify handoff Phase 1A (`79cce4b`)؛ (۲) بستن OD-9 طبق تصمیم مالک (گزینهٔ C سخت‌گیرانه): ریشهٔ بکاپ فعال باید بیرون DocumentRoot باشد — Fail-Closed، بدون silent fallback — ولی Restore/DR نباید deadlock شود؛ (۳) گیت OD-9 با ۴ workflow سبز؛ (۴) فقط PRE-IMPLEMENTATION CHECK فاز ۲ (بدون پیاده‌سازی) + گزارش Gate و STOP.
- **پیاده‌سازی OD-9 (کامیت `3531d5d`):** `ProtectedBackupStore` با سازندهٔ private + `::active()` (fail-closed، realpath ⇒ symlink هم رد؛ کد `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`) + `::legacySource()` (فقط‌خواندنی: createDir/ensureGuards/delete رد، listIds بدون گارد). `App::backupService()` پیکربندی ناامن را صریحاً downgrade می‌کند (مسیر عوض نمی‌شود؛ کش برداشته شد). `BackupService`: تفکیک مبدأ/مقصد — `resolveSourceStore()` (فعال→خصوصی→legacy) + `safetyDestinationStore()` (Safety Backup فقط به مقصد امن؛ مبدأ legacy هرگز مقصد نیست؛ نبود مقصد امن = توقف قبل از گام مخرب) + preflight با `integrity_warnings`/`legacy_unverified`/`source`/`source_root` + Audit RESTORE_APPLIED گسترده. `App::ensurePrivateStorage()`: مهاجرت idempotent ریشهٔ ناامن/legacy (copy→verify sha256→rename→verify→remove؛ تعارض بدون overwrite؛ Setting دست‌نخورده). `SystemHealthService`: inside-webroot = FAIL (قبلاً WARNING). `BackupRunHandler`: ثبت errorCode صریح.
- **تست:** `tests/Integration/BackupStorageBoundaryTest.php` — ۱۴ متد (default خارج webroot، safe path، inside-webroot رد، symlink رد، downgrade صریح، legacy readonly، رزولوشن سرویس، redirect Safety Backup، restore مسیر عادی، tampered رد، legacy_unverified، مهاجرت idempotent، تعارض، Health FAIL). مهاجرت با بکاپ واقعی seed می‌شود (نه manifest ساختگی) و restoreApply با artifact «توقف قبل از DDL» ایزوله شده — مسیر مخرب کامل = Restore Drill سطح OS در Pilot Gate.
- **خطای میانی و طبقه‌بندی:** CI run 34282802365 (روی `3531d5d`) قرمز — یک Error در تست تعارض: `mkdir(dirname($dst))` والد را می‌ساخت نه دایرکتوری بکاپ. طبقه‌بندی **D (نقص تست)**؛ fix یک‌خطی در `cd29473`؛ سایر اجراها (Closure/Real-WP روی همان SHA و Pilot با push جدید) سبز بودند.
- **شواهد نهایی روی `cd29473` (هر ۵ اجرا سبز):** CI **34283426837** (PHPStan lvl 3 + Unit 8.1–8.4 + Integration WP6.7/MySQL8؛ ۴۸۱ تست) · Real-WP PR **34283426829** · Real-WP push **34283425385** · Closure (شامل destructive restoreApply) **34283425388** · Pilot/Staging (شامل Restore Drill) **34283425398**. **OD-9 = CLOSED** (`docs/phase-reports/report-od9-closure.md`؛ drift-register/roadmap/handoff/security-model §۵-۴/runbook §۷/error-codes به‌روز).
- **PHASE 2 PRE-IMPLEMENTATION CHECK (`docs/phase-reports/phase2-pre-implementation-gate-report.md`، ۱۷ بند، همهٔ اعداد re-verify):** ۴۱ جدول/۹ migration/۳۹ FK تأیید؛ 🔍 تصحیح‌ها: ۲۶ جدول clinic_id (نه ۲۵ — `sms_messages` در نگاشت ب-۵ جا افتاده + نوع ستونش INT است نه BIGINT)؛ ۲۲ FK لازم (نه ۲۱)؛ سومین UNIQUE شکننده = `u_clinician_user` (نقض AD-05/M:N)؛ سرشماری hardcode: ۵۲ اجرایی + ۳ default-param در ۲۴ فایل — 🔍 **رانش فاز 1A: +۱ hardcode اجرایی جدید در `OtpService::resolveUser` (نقض AD-13 — در بدهی Phase 2 ثبت شد)**؛ یافتهٔ دو منبع TZ (wizard فقط setting می‌نویسد، ردیف clinics هرگز UPDATE نمی‌شود) — باید پیش از M-04 تعیین تکلیف شود. Q6/Q11/Q12 باز اما غیرمسدودکننده. **رأی: فاز ۲ آمادهٔ شروع مشروط به بند ۱۷ گزارش.**
- **محدودیت محیط:** sandbox فاقد PHP — عدد PHPUnit فقط از GitHub Actions با run-id؛ لاگ خام jobها در این محیط EOF می‌دهد (شواهد از check-run/PR comments استخراج شد).
- **STOP طبق دستور:** PR #10 همچنان OPEN + DRAFT (لمس نشد)؛ PR #11 برای CI DRAFT باز شد (merge نشد)؛ بدون tag/release/version-bump/merge. Phase 2 implement نشد. انتظار تأیید مالک.

### [2026-09-09 ~09:30 UTC] — ایجنت Arena (شاخهٔ `arena/01a082db-doctor`) — FINAL PRE-PHASE-2 GATE — STOP
- **ماموریت کارفرما (۱۰ بند):** verify اتصال/هویت repo؛ تصحیح رکورد عددی ۲۶/۲۲؛ تحقیق رگرسیون `clinic_id=1` + fix کوچک؛ تصمیم Source of Truth زمانی؛ طراحی Clinician/User/Membership؛ حکم معنایی SMS؛ اعتبارسنجی Migration Plan؛ تست‌پلن؛ مرز 1B/3؛ گزارش ۱۷بندی. **بدون هیچ implementation فاز ۲.**
- **§۱ هویت:** اتصال GitHub برگشت (401 دیروز گذرا؛ بدون workaround). شاخه = `arena/01a082db-doctor`؛ نام `arena/01a082d2-doctor` **در remote وجود ندارد** (ls-remote: ۱۰ شاخه؛ خطای خوانش «b»→«d2» گزارش شد). HEAD = `9d6cf41` == remote؛ هر ۵ گیت آن سبز (CI 34283426837 · Real-WP 34284357086/34284347413 · Closure 34284347483 · Pilot 34284347475).
- **§۲ تصحیح:** بازشماری مستقل — **۲۶ جدول clinic_id (فهرست/نوع هر یک)، ۴ FK، ۲۲ بدون FK**؛ `cpms_sms_messages` تنها INT (و DEFAULT 1؛ DEFAULT-1ها = ۳ جدول). علت خطای census قبلی: جمع‌بندی فقط migration 0001 را شمرد. بلوک «🔴 تصحیح نهایی» در `phase0.5-target-model.md` (ب-۵) + ماتریس کامل در **د-۶** جدید. تاریخچه Phase 0 بازنویسی نشد.
- **§۳ رگرسیون AD-13:** git blame — خط جدید Phase 1A در **`findExistingUser`** است (کامیت `4c16009`، OD-8)، نه `resolveUser` (آن از 8087b42 بود — تصحیح اتریبوشن گزارش قبلی خودم). Fix (`bbc1e83`): helper واحد `findActivePatientIdByMobile()` با `clinic_id = %d` + `Settings::clinicId()` (getter جدید؛ بدون مفهوم Scope ساختگی)؛ insert لینک هم به کلینیک پیکربندی‌شده؛ **OtpService = صفر hardcode؛ سرشماری ۵۲→۴۹**. تست جدید با کلینیک دوم واقعی + دو بیمار هم‌موبایل. B-15 دفترچه 1B تصحیح؛ **B-21 بسته شد (OD-7/OD-9)**.
- **§۴ TZ:** نقشهٔ کامل read/write — ستون clinics فقط seed می‌شود و تنها reader عملیاتی‌اش `Settings::clinicTimezone()` با ۳ مصرف‌کننده (یادآوری ×۲ + اعلان) است؛ setting ویزارد **هیچ مصرف‌کنندهٔ عملیاتی ندارد** ⇒ انتخاب اپراتیر بی‌اثر (نقص ثبت‌شده). پیشنهاد: `locations.timezone` = SoT عملیاتی؛ clinics.timezone = default واگذاری seeding؛ نگاشت M-04 = setting(معتبر) → ستون → Asia/Tehran + preflight واگرایی؛ hotfix ویزارد پیشنهاد شد (اجرا نشد — خارج از mandate).
- **§۵/§۸ Cardinality:** `u_clinician_user` **حفظ می‌شود** (تصحیح رأی قبلی): AD-05 دربارهٔ Membership است؛ ERD مصوب `WP_USER ||--o| CLINICIAN` یک پروفایل به‌ازای کاربر می‌خواهد. سه مفهوم تفکیک: WP identity / Clinician profile (یک به‌ازای user؛ `clinic_id` = «کلینیکِ خانه»، نه مرز مجوز) / Membership+clinician_locations (M:N واقعی). کار Phase 2 = Query-level (فهرست پزشکان کلینیک از Membership)، نه شکستن ایندکس.
- **§۶ SMS:** tenant-owned عملیاتی (PII + زمینهٔ بالینی در متن/vars_json) — FK به clinics **توجیه معنایی دارد** (پس از INT→BIGINT + حذف DEFAULT 1 در M-08b)؛ location_id لازم نیست؛ نبود retention = تصمیم باز مالک.
- **§۷ Migration:** د-۶ (ماتریس ۲۶ جدول + M-08b جدید + M-09=۲۲ FK + تصمیم سه UNIQUE با test plan پنج‌بندی + ترتیب کامل) — versioned-forward، drop/recreate ممنوع، CI upgrade-path الزامی.
- **§۸ تست‌پلن:** ۱۵ دسته (از جمله 🆕 tripwire معماری شمارش hardcode با snapshot=۴۹؛ ممنوعیت mock isolation — fixture ردیف واقعی دوم).
- **§۹ مرز 1B/3:** از ۱۷ قلم مجوزی (B-01..B-17): Phase 2 = ScopeContext + فیلتر Repository + schema (B-02..B-06, B-08, B-09/10-مشروط, B-11, B-14, B-16, B-17)؛ Phase 3 = enforce سیاستی/ماتریس/Break-Glass (B-01-enforce, B-07, B-12-سیاست, B-15-جریان). Phase 2 به Role Management تبدیل نمی‌شود.
- **گیت‌های fix:** CI `34286105777` سبز (Integration با تست جدید) · Closure `34286100991` سبز · Real-WP/Pilot — نتیجه در `final-pre-phase2-gate-report.md` بند ۱۵.
- **رأی:** ⏳ NOT READY تا (۱) سبزی هر ۵ گیت `bbc1e83` و (۲) تصویب مالک بر «کلینیکِ خانه/UNIQUE» و «Source of Truth زمانی». جزئیات: `docs/phase-reports/final-pre-phase2-gate-report.md` (۱۷ بند).
- **STOP طبق دستور** — بدون merge/tag/تغییر main؛ PR #10 دست‌نخورده؛ untrackedهای محافظت‌شده لمس نشد.

### [2026-09-10 ~12:30 UTC] — ایجنت Arena (شاخهٔ `arena/01a08b0a-doctor`) — C6 post-closure corrective: هفت tenant default مالی + سخت‌سازی Tenant Tripwire
- **ماموریت کارفرما:** بازیابی حقیقت remote، راستی‌آزمایی مستقل گیت‌های پس‌از‌ادغام، و فقط در صورت سبزی آن‌ها اجرای یک **corrective باریکِ پس‌از‌بستن C6**. صریحاً ممنوع: بازکردن دوبارهٔ C6، C7، Phase 3، سیاست مجوز Location، Migration 0021، merge، بستن PR #13.
- **بازیابی حقیقت (همه از Git/GitHub زنده):** `HEAD` == `origin/main` == `6c84316` (working tree تمیز، بدون untracked؛ clone **shallow** با depth=1 — پس والدینِ merge commit فقط از API خوانده شدند). والدین `6c84316` = `099b644` + `4b3896c` (دقیقاً مطابق انتظار). **PR #15 = MERGED**، **PR #13 = OPEN + DRAFT** (دست‌نخورده). آخرین Migration = **0020** (بدون 0021). `origin/main` **پیش نرفته** — پس هیچ پیشرویِ مشروعیت‌سنجی‌شده‌ای لازم نبود.
- **گیت‌های پس‌از‌ادغام (راستی‌آزمایی مستقل در سطح job، نه فقط run):** روی `6c84316` — CI `34469323541` ✅ (۸/۸ job) · Real-WP `34469323447` ✅ (۲/۲) · Pilot `34469323466` ✅ (۴/۴) · Closure `34469323482` ✅ (۵/۵). همه `status=completed / conclusion=success / run_attempt=1`.
- **بازتأیید هفت نقطهٔ نقص روی `origin/main` فعلی (همه هنوز موجود بودند؛ هیچ‌کدام قبلاً اصلاح نشده بود):** `ServiceRepository::all` · `PaymentRepository::{revenueSummary, forRange, nextPaymentNumber}` · `InvoiceRepository::{openInvoices, nextInvoiceNumber}` · `FinanceService::lockClinic`.
- **نقص کلاس D — نقطهٔ کور Tripwire (با evidence اجرایی، پیش از هر تغییر):** در هر هفت مورد ستون tenant یک `%d` است و literal `1` جداگانه bind می‌شود ⇒ آشکارساز خط‌محور `CLEAN` می‌داد (`173 فایل، 0 نقض`). سه سایه‌اندازی هم **تجربی** تأیید شد: ① `select_first_clinic` **الگوی مرده** بود (۸ خط روی main مطابقت می‌کردند ولی `limit_1_generic` همه را می‌کشت، چون خودش ساختاراً `LIMIT 1` می‌خواهد)؛ ② `id_1_primary` الگوی `id = 1` را داخل `c.id = 1` هم می‌گرفت و hardcode واقعی را می‌پوشاند؛ ③ تطبیق benign **خط‌محور** بود، پس `WHERE clinic_id = 1 AND is_active = 1` توسط `is_active_flag` خفه می‌شد.
- **سخت‌سازی Tripwire (کامیت جدا، عمداً RED):** لایهٔ دوم = تحلیل prepared statement — بازسازی SQL هر فراخوان `CpmsDb` از literalها (با resolve متغیرهای **تک‌انتسابی** در جای خودشان، تا ترتیب placeholderها با params هم‌تراز بماند؛ `ServiceRepository::all` دقیقاً همین حالت را دارد) + هم‌تراز‌سازی placeholder→param. یک placeholder وقتی tenant است که ستونش `clinic_id/organization_id/location_id` باشد، یا `id`ِ یک جدول tenant (`cpms_clinics/organizations/locations` — یعنی شکلِ قفل ردیف `lockClinic`). multiline ذاتاً پوشش دارد (پنجرهٔ balanced-paren، نه خط). سایه‌اندازی‌ها: تطبیق benign به **لنگرِ literal-1 خودِ match** محدود شد، الگوهای ستونی با `(?<![\w.$])` لنگر شدند، و `select_first_clinic` به «انتخاب ردیف بدون predicate» باریک شد (هم زنده شد، هم ۷ false positive حذف شد). **Self-tests: ۳۴ → ۶۲، همه PASS. Allowlist خالی ماند.** روی `main` پیش از رفع: **دقیقاً ۷ hardcode** در همان هفت فایل (و صفر در ۱۶۶ فایل دیگر ⇒ بدون false positive نامعقول). پس از رفع: **۰ hardcode / ۱ suspect** (`SystemClinicResolver:52` — همان الگوی قبلاً مرده، اکنون برای مرور دیده می‌شود؛ allowlist عمداً اضافه نشد و suspect گیت را نمی‌شکند).
- **نقص ثانویه — تأیید شد (کلاس B):** `wpdb::insert` در خطا `false` می‌دهد ولی `insert_id` را پاک نمی‌کند. `InvoiceRepository::insert` شناسهٔ **stale** را برمی‌گرداند و `issueInvoice` بدون هیچ گاردی با آن `insertItem()` می‌زد ⇒ اقلام به فاکتور بیگانه (احتمالاً Clinic دیگر) می‌چسبید و تراکنش commit می‌شد. گارد مسیر پرداخت هم ناکافی بود (`$ok` همان `insert_id` بود ⇒ فقط «صفر» گرفته می‌شد). رفع: گارد `!$ok || $id <= 0` در `insert`/`insertItem`/`insertAdjustment` (Invoice) و `insert` (Payment) با الگوی موجودِ `HandwritingRepository`/`NotificationRepository`؛ `recordPayment` با catch همان استثنا مسیر Idempotency-race را **بدون تغییر رفتار بیرونی** حفظ می‌کند و هر شکست دیگر → `FinanceException 500` + ROLLBACK.
- **رفع هفت مسیر (بدون fallback، بدون تبدیل clinic_id کلاینت به مورد اعتماد، fail-closed):** امضاهای Repository حالا `int $clinic_id` **الزامی** دارند (بدون مقدار پیش‌فرض). `FinanceService::requireClinicScope()` نگهبان fail-closed است (`CLINIC_SCOPE_REQUIRED` 400). منبع Clinic: `App::scope()->clinicId` برای `listServices`/`summary` (همان ClinicScope مورد اعتمادی که `TrustedClinicEstablisher` در مرز REST مستقر می‌کند) و `clinic_id` ردیفِ ویزیت/فاکتورِ **قفل‌شده در همان تراکنش** برای `issueInvoice`/`recordPayment`/`lockClinic`/عددگیری. همه prepared SQL ماند؛ WP user هرگز Doctor فرض نشد؛ `u_clinician_user` دست‌نخورده.
- **تست رگرسیون (`tests/Integration/FinanceClinicScopeTest.php` — فایل جدید، ۱۲ تست، بدون mock، ردیف واقعی MySQL):** Clinic عملیاتی **غیر از ۱** (B=62001، C=62002 در یک Organization واحد ⇒ جداسازی در سطح Clinic اثبات می‌شود نه Organization)؛ مسیر کامل مالی روی Clinic غیر از ۱؛ جداسازی تعرفه؛ نرسیدن فاکتور باز/پرداخت Clinic B به C؛ نرسیدن **نام و MRN** بیمار B به C (MRNهای متمایز و قابل تشخیص)؛ جداسازی خلاصهٔ درآمد و `forRange`؛ **عددگیری مستقل** INV و PAY (هر دو Clinic از `001`/`0001` شروع می‌کنند و دنبالهٔ هم را نمی‌بینند)؛ **قفل روی ردیف Clinic درست** (با گرفتن کوئری‌های `… cpms_clinics WHERE id = N LIMIT 1 FOR UPDATE` از فیلتر `query` — و اثبات اینکه قفل Clinic 1 گرفته نمی‌شود)؛ **شکست درج** با تزریق خطای واقعی MySQL (مسموم‌کردن `MAX(invoice_number)`/`MAX(payment_number)` با شمارهٔ خارج‌الگو + اشغال شمارهٔ بعدی ⇒ نقض `u_inv_number`/`u_pay_number`) با این ادعای کلیدی که فاکتورِ از‌پیش‌موجود **هیچ قلمی نمی‌گیرد** (دقیقاً همان چیزی که کد قدیمی با شناسهٔ stale انجام می‌داد)؛ سازگاری صریح **Clinic 1**؛ و قرارداد Reflection برای الزامی‌بودن `clinic_id` در هر شش متد + `lockClinic`. پوشش ایزوله‌سازی موجود C6 دست‌نخورده ماند و هیچ مقدار انتظاری برای «مبارک‌کردن» رفتار خراب تغییر نکرد.
- **محدودیت محیط (صریح):** این sandbox **PHP/composer/MySQL ندارد** و شبکهٔ apt/packagist بسته است ⇒ `php -l`، Unit، Integration، PHPStan و WPCS **محلی قابل اجرا نبودند**. آنچه محلی اجرا شد: **Tripwire self-tests (۶۲/۶۲)** و **Tripwire production scan** (۷ → ۰ hardcode). بقیهٔ گیت‌ها فقط از GitHub Actions قابل تأییدند.
- **Migration: هیچ.** 0021 ساخته نشد و لازم هم نبود. **C7 همچنان NOT STARTED.** PR #13 دست‌نخورده. بدون merge/tag/release/version-bump.
- **وضعیت:** کامیت‌ها روی `arena/01a08b0a-doctor`؛ PR DRAFT اصلاحی و run-idهای CI در گزارش نهاییِ همین نشست ثبت می‌شود. **STOP** پس از push و گزارش.
