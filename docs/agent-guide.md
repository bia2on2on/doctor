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
> | Phase 2 | Multi-Clinic Core | ✅ CLOSED (TECHNICAL) — see the current-state checkpoint |
> | Phase 3 | Role & Access Control | ✅ COMPLETED / FROZEN — merged PRs #49/#50/#52/#54/#55/#56/#57 |
> | Phase 4 | Master Data | ✅ CLOSED / TECHNICALLY COMPLETE (BOUNDED) — merged PRs #59–#65 at live main `70ace204d1524a8f5e83d33c67c1a09b7543e7a3`; remaining deferred/open Master Data items stay deferred/open |
> | Phase 5 | Pricing Engine | ✅ CLOSED / TECHNICALLY COMPLETE (BOUNDED) — Phase 5 Slice 1 merged via **PR #67** (merge `e063b42260cb5ab740acf48dcf73c6c67b11fef6`, 2026-09-17T15:36:54Z). Base pricing is **Clinic-scoped** and sufficient for the current approved V1 flow; `invoice.location_id` is derived from the validated Visit. Per-Location tariff for the *same* Service is **NOT** a proven V1 requirement (deferred on absence of a proven requirement — **not** on any single-Clinic/single-Location assumption). Latest migration remains `0020` |
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
>   - *(راستی‌آزمایی 2026-09-12)* «۵۴» **سرشماریِ تاریخیِ Phase 0** است، **نه بدهیِ runtimeِ جاری**: Tenant Tripwire روی runtime فعال `hardcodes: 0` و allowlist `[]` گزارش می‌دهد (۵۹ self-test سبز). آشتی‌دهیِ دقیقِ «۵۴ تاریخی ↔ ۰ جاری» + شاهدِ قابلِ اجرا در [`project-current-state.md`](project-current-state.md) §D‑1. خودِ قاعدهٔ AD-13 بدون تغییر پابرجاست و **`clinic_id = 0` و Clinic مصنوعیِ «System» هم ممنوع‌اند** (جزئیات: [`architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md) §۳-B‑2).
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

### [2026-09-12 ~08:40 UTC] — ایجنت Arena (شاخهٔ `arena/01a094c5-doctor`) — بازیابیِ تداومِ تمیز + چک‌پوینتِ مستندات/طراحی (فقط مستندات) — STOP
- **چرا این نشست:** نشستِ قبلی به شاخهٔ `arena/01a092ab-doctor` قفل بود و آن شاخه از طریق merge `68fbd405` ancestry نامطلوبِ `30435673`/`151d7e1` را وارد تاریخچه کرده بود. این نشست **از همان topology ادامه نداد**؛ شاخهٔ تازه مستقیماً از `origin/main` = `bdb135e9b3eff9db9fbe104c33bc6c30850c5263` شروع شد (working tree تمیز؛ بدون untracked).
- **راستی‌آزماییِ پیش از نوشتن:** `origin/main` هنوز **دقیقاً** `bdb135e9` (پیش‌روی نکرده بود) · PR باز = فقط **#13 (OPEN + DRAFT، base `arena/01a086ca-doctor`)** که **دست‌نخورده** ماند · هیچ PR معادلی برای این محتوا وجود نداشت · آخرین migration = **`0020`** (`2026_09_09_0020_idempotency_clinic_scope.php`) · کامیتِ مصوبِ مستندات `4c1ab657` با `git fetch origin <sha>` گرفته و بررسی شد: **والد = دقیقاً `bdb135e9`**، **دقیقاً ۸ فایل و همگی مستندات** (CHANGELOG.md · docs/README.md · ADR-0031 · project-phase-taxonomy.md · c10-performance-evidence.md · phase2-state.md · project-current-state.md · roadmap.md)، **بدون کد/تست/workflow/schema/migration**.
- **کامیت ۱ (`84a3975`) — انتقالِ تمیز:** `git cherry-pick -x 4c1ab657` بدون هیچ conflict؛ **tree یکسان** با `4c1ab657` (هر دو `f3c687f6`) ⇒ محتوای **C10 = CLOSED (تصمیم صریح مالک 2026-09-12، فقط دامنهٔ محدودشدهٔ بازبینی شواهد؛ بدون ادعای NFR/بار/مقیاس‌پذیری/آمادگی تجاری/Phase 17/بستن Phase 2)** و **ADR-0031 §۸ / AD-17 (یک هسته، سه توپولوژی استقرار)** عیناً منتقل شد. **`68fbd405`/`30435673`/`151d7e1` وارد ancestry نشدند** (با `git merge-base --is-ancestor` برای هر سه بررسی شد)؛ شاخهٔ قدیم merge نشد؛ تاریخچه بازنویسی نشد.
- **کامیت ۲ (`49ed1c3`) — جهتِ طراحیِ مصوبِ Phase 2 (پیاده‌سازی‌نشده):** سند کانونیِ جدید **`docs/architecture/phase2-tenant-context-remediation-design.md`** با برچسبِ صریح **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**: (A) tenant contextِ صریح/اعتبارسنجی‌شده/ایزولهٔ per-job برای Jobهای پس‌زمینه (Organization مشتق از Clinic و بدون تکرارِ بی‌توجیه؛ Location معمولاً مشتق از آبجکت عملیاتیِ مرجع و **نه** افزودن `location_id` به هر Job؛ برقراری توسط Dispatcher؛ پاک‌سازی در `finally`؛ بدون وابستگی به WP کاربرِ جاری/REST؛ `A → B → A` بدون نشتِ Settings؛ حفظ scope در retry؛ fail-closed برای scope مخدوش و نوعِ ناشناخته؛ ممنوعیتِ حدسِ مالکیت) + طبقه‌بندیِ الزامیِ انواع (T/S/W) · (B) **بدون allowlist نهاییِ system-wide**؛ `backup.run`/`license.refresh`/`cleanup.oplog` **عمداً UNRESOLVED**؛ **ممنوعیت مطلقِ `clinic_id = 0` و Clinic مصنوعی** · (C) timezone عملیاتی = **Location** (چند Location با timezone متفاوت؛ Clinic timezone هرگز override نمی‌کند؛ fail-closed؛ `Asia/Tehran` فقط مقدار سازگاریِ کنترل‌شده؛ Clinic timezone = legacy/default تا تصمیم deprecation) · (D) رزولوشنِ پیکربندیِ SMSِ Clinic B مستقل از کاربر/context/Job قبلی/ترتیب اجرا/instanceِ Clinic دیگر، با ترجیحِ انتزاعِ `SettingsFactory`/`SmsConfigResolver` · (E) انتسابِ tenant در لاگِ عملیاتی + **ستون `clinic_id` به‌تنهایی authorization نیست** + **بدون ادعای نشتیِ اثبات‌نشده** (راستی‌آزمایی: `cpms_operational_logs` امروز **هیچ خواننده/UI/API‌ای ندارد**) · (F) قواعدِ backfillِ Jobهای legacy (فقط provenance قطعی؛ «یک Clinic بودنِ نصب» کافی نیست؛ مبهم‌های pending/processing ⇒ fail-closed؛ بدون Clinic ID حدسی؛ idempotent/recoverable) · **§۸ = ۶ سوالِ بازِ «پیش از Migration `0021`» (همه OPEN)** *(🔴 تاریخی — روایتِ همان کامیت؛ اکنون **هیچ شمارهٔ migration آینده‌ای رزرو نیست** و §۸ بر پایهٔ تصمیماتِ مالک 2026-09-12 به‌روز شد)* · **§۹ = مشخصاتِ ۱۲ تستِ RED ‏(RT-1..RT-12) فقط ثبت شد — هیچ تستی نوشته/اجرا نشد و همهٔ تست‌های موجود حفظ شدند** *(🔴 تاریخی — اکنون **RT-1..RT-14**؛ ورودیِ بعدیِ همین §۱۰ را ببینید)*. لینک‌ها: `docs/README.md` (جدول مراجع + نقشهٔ اسناد؛ و نشانه‌گذاریِ سطرِ کهنهٔ «2026-09-11 / C10 = NOT STARTED» به‌عنوان superseded — **بدون حذفِ محتوا**)، `background-jobs.md` §۵، `phase2-state.md` (اشاره در اقلامِ به‌تعویق‌افتادهٔ Jobs/SMS/timezone)، `project-current-state.md` (Linked canonical docs)، `CHANGELOG.md`.
- **کامیت ۳ (`c575aec`) — آشتی‌دهیِ «۵۴»:** **`۵۴` = سرشماریِ تاریخیِ Phase 0** و **بدون بازنویسی** حفظ شد؛ **وضعیتِ جاریِ راستی‌آزمایی‌شده:** نقضِ tenant-default در runtime فعال = **۰** · Tripwire `{"files_scanned": 173, "hardcodes": 0, "suspects": 1, "allowlist_entries": 0}` + ۵۹/۵۹ self-test · allowlist = **`[]`** · تنها suspect = `SystemClinicResolver.php:52` (sanction‌شده/fail-closed) · الگوی grepِ تاریخی امروز ۷ برخوردِ **متنی/کامنتی** (+۱ عبارت داخل Migrationِ اجراشدهٔ `0020`) دارد و **۰** tenant-defaultِ runtime · هر ۳ Default-Parameter حذف شده‌اند و `DEFAULT 1` سطحِ schema از هر ۳ جدول با Migration `0016` برداشته شد. محلِ ثبت: **§D‑1 جدید در `project-current-state.md`** (جدولِ شاهد + عبارتِ کانونیِ مجاز) + یادداشتِ «وضعیتِ جاری» در `phase0.5-target-model.md` §الف‑۶ (اعداد/جداول/فرمان‌های تاریخی دست‌نخورده) + تصریحِ یک‌خطیِ AD-13 در همین راهنما.
- **پاکسازیِ کوچکِ آینده (ثبت شد، انجام نشد):** docblockِ `src/Infrastructure/Repository/ClinicianRepository.php:17` («همه کوئری‌ها clinic_id=1») **راستی‌آزماییِ مستقل: کهنه** — کلاس `int $clinic_id` صریح می‌گیرد (`listAll():30`، `create():76`) و literalِ tenant ندارد. چون مأموریت **فقط‌مستندات** بود، **هیچ فایل PHP‌ای تغییر نکرد**؛ این قلم cleanupِ فقط‑کامنتیِ آینده است.
- **اعتبارسنجیِ محلیِ این نشست:** Tripwire (scan + ۵۹ self-test) ✅ · بررسیِ لینک‌های نسبیِ Markdown روی فایل‌های تغییرکرده ✅ (تنها لینکِ شکستهٔ گزارش‌شده = `project-current-state.md:398 → phase2-state.md` که **پیش‌ازاین نشست هم وجود داشت** و عمداً دست نخورد) · بدون تغییر در `.github/workflows`، `phpstan.neon.dist`، `phpcs.xml.dist`، `phpunit.xml` یا هر ابزار/تستی. **اجرای واقعیِ PHPUnit/PHPStan/WPCS فقط در GitHub Actions** (sandbox فاقد PHP) — run IDها در PR ثبت می‌شود.
- **ممنوع‌های رعایت‌شده:** بدون کد محصول · بدون تست · بدون تغییر workflow · بدون schema · **بدون Migration `0021`** · بدون تغییر `main` · بدون merge · بدون force-push · بدون `reset --hard` · بدون بازنویسی تاریخچه · بدون tag/release/version-bump · **End Gate فاز ۲ شروع/تعریف/پاس نشد** · Phase 3 / Phase 17 همچنان **NOT STARTED** · Phase 2 = **IN PROGRESS** · PR #13 دست‌نخورده · شاخهٔ قدیمیِ Arena بسته/حذف نشد.
- **STOP طبق دستور** — پس از pushِ شاخه و بازکردنِ **یک Draft PR** به `main` (بدون merge)، منتظر تصمیم مالک: بستنِ ۶ سوالِ §۸ پیش از هر Migration، و تصویبِ جداگانهٔ هر پیاده‌سازی.

### [2026-09-12 ~12:10 UTC] — ایجنت Arena (شاخهٔ `arena/01a094c5-doctor`، PR #26 DRAFT) — بازبینیِ مستقلِ معماری + اصلاحاتِ مستنداتیِ C-1..C-9 (فقط مستندات) — STOP
- **چرا این نشست:** بازبینیِ **فقط‌خواندنیِ** Draft PR #26 و سندِ کانونیِ `docs/architecture/phase2-tenant-context-remediation-design.md` + حلِ شاهد-محورِ شش سوالِ §۸. **حکمِ مالک: `B — NEEDS SMALL DOCUMENTATION CORRECTIONS BEFORE ACCEPTANCE`** ⇒ اصلاحاتِ مستنداتی اعمال شد؛ **merge/Ready نشد**.
- **راستی‌آزماییِ پیش از نوشتن:** `origin/main` = **`bdb135e9`** (بدون پیش‌روی) · headِ PR #26 = **`0670216`** (بدون تغییرِ غیرمنتظره) · `mergeable_state=clean` · **۲۱/۲۱ check success** روی همان SHA · PR #13 = OPEN + DRAFT (دست‌نخورده) · آخرین migration = **`0020`** (‏`0021` وجود ندارد) · working tree تمیز.
- **یافتهٔ اصلی (must-fix = C-1):** گزارهٔ §۵-D-2 («Secret/API key هرگز در `cpms_settings` ذخیره نمی‌شود») **خلافِ کد و خلافِ `ADR-0025:44-45`** بود. واقعیتِ راستی‌آزمایی‌شده: credentialِ SMS **per-Clinic** در `cpms_settings.sms.auth` به‌صورت **sealed (AES-256-GCM)** ذخیره می‌شود (`SmsService.php:348`, `:646-660`, `:667+`؛ `CredentialVault.php:7-15`) و **plaintext** هرگز ذخیره/نمایش/لاگ نمی‌شود (`Settings.php:145-147`, `:239-243`). **کلیدِ Vault سطحِ نصب است** ⇒ **رمزنگاری به‌خودیِ خود مرزِ tenant نیست**؛ ایزولاسیون فقط با **resolveِ درستِ Clinic پیش از خواندن/رمزگشایی** برقرار می‌شود.
- **سایر اصلاحات:** **C-2** ترتیبِ ردیف‌های §۱ (‏۱۳/۱۴) · **C-3** مسیرهای wp-admin برای ست‌شدنِ `ScopeContext` (`ClinicianAdminPage.php:413/456/491/509`) · **C-4** ثبتِ سابقهٔ کاریِ `report.export` به‌عنوانِ ردیفِ ۱۵ §۱ (persistِ `clinic_id` در `payload_json` + اعتبارسنجیِ fail-closed + bind + restore در `finally`) · **C-5** تصحیحِ طبقهٔ `appt.reminder` از T به **W** (جاروی سراسری با انتسابِ per-row؛ **بدونِ ادعای sharding**) · **C-6** ثبتِ منبعِ سومِ timezone (`setup.clinic.timezone`) و **قطعِ اتصالِ راستی‌آزمایی‌شده** با `clinicTimezone()` (که فقط `cpms_clinics.timezone` را می‌خواند و هیچ نویسندهٔ production‌ای ندارد) · **C-7** افزودنِ **C-9/C-10** (`slots.generate` = `gmdate('Y-m-d')`ِ UTC؛ `visits.no_show` = cutoffِ UTC روی مقادیرِ محلیِ `slot_date/slot_time`) · **C-8** افزودنِ **E-7** (ایندکسِ موجود `(level, created_at)` به‌دلیلِ leftmost-prefix، `DELETE`ِ فقط-`created_at` را پوشش نمی‌دهد؛ حذفِ امروز **بی‌کران**) · **C-9** تعیینِ **منبعِ حقیقتِ registry = `App::dispatcher()`/`RECURRING_JOBS`** (‏۱۵ نوع) و ثبتِ driftِ `background-jobs.md`.
- **§A-3 جدید:** جدولِ طبقه‌بندیِ **T/S/W** برای **۱۵ نوعِ واقعیِ ثبت‌شده** با تفکیکِ اجباریِ «رفتارِ جاریِ runtime» از «طبقهٔ هدف»، ستون‌های وابستگیِ Settings / دسترسیِ tenant-sensitive / لزومِ `clinic_id` / مجاز بودنِ `NULL`. طبقهٔ `backup.run` و `license.refresh` **مشروط به §۸-۱** ثبت شد. **`NULL` هرگز خودکار «system» نیست.**
- **§۸ بر پایهٔ تصمیماتِ مالک:** ۸-۱ 🟢 **جهتِ مصوب** = انتزاعِ اختصاصی روی **WordPress Options** با ترجیحِ `autoload=no` (سابقهٔ بومی: `cpms_otp_pepper` با `add_option(..., '', 'no')`) — **بدونِ انتقالِ فله‌ای بر پایهٔ prefix**، **بدونِ جابه‌جاییِ وضعیتِ ساخت‌یافتهٔ license** (جدول‌های `0008`)، **فهرستِ کلیدها OPEN** · ۸-۲ ✅ **installation-wide** (رفتارِ قابلِ اجرا + شاهدِ تاریخیِ `settings-reference.md:5`/`agent-guide.md:658`) **با مرزِ ضدِتعمیم** (سایرِ کلیدهای retention/purge جداگانه تصمیم می‌گیرند) · ۸-۳ 🟢 **مدلِ طراحیِ مصوب** · ۸-۴ ⛔ **schema/migration NOT AUTHORIZED** (فقط جهت؛ **راهبردِ «NULLِ دو-دوره‌ای» کانونی/نهایی نشد**) · ۸-۵ ⏳ **OPEN — NOT MEASURED** (نمونهٔ پرس‌وجو با `$db->table()`، چون prefixِ وردپرس محیط‌محور است) · ۸-۶ ✅ **resolved برای انواعِ جاری** (snapshotِ `location_id` مسیرِ تغییر ندارد؛ reschedule ردیفِ جدید می‌سازد؛ `cpms_locations` نویسندهٔ production ندارد) + **زنجیرهٔ اشتقاقِ Location برای آبجکتِ بدونِ `location_id` همچنان OPEN**.
- **تست‌ها:** RT-1..RT-12 **حفظ** و با شاهد تدقیق شدند (RT-2 تفکیکِ تاریخِ محلی از quiet hours · RT-3 سنجشِ **هویتِ credential** نه فقط provider ID · RT-4/RT-14 fail-closedِ per-job و ایزولاسیونِ blast radius · RT-6 پوششِ `App::$settings/$dispatcher/$smsService/providers` · RT-7 authoritative Location · RT-8 بدونِ side-effectِ جزئی · RT-9 شاملِ `slots.generate`/`visits.no_show` · RT-10 evidence-driven · RT-11 تفکیکِ «سمتِ نوشتنِ امروز» از «خوانندهٔ آینده» · RT-12 روی ۱۵ نوعِ واقعی) + **RT-13** (پیکربندیِ سطحِ نصب بدونِ contextِ Clinic و بدونِ `clinic_id=0`/Clinic جعلی) و **RT-14** (ایزولاسیونِ شکستِ per-job درونِ tick) افزوده شد. **RT-15 اضافه نشد** (بدونِ شاهدِ semanticِ جداگانه). **هیچ تستی نوشته/اجرا/ضعیف نشد.**
- **drift:** `docs/drift-register.md` **بخش ۹** اضافه شد (‏۹-A تناقضِ داخلیِ `settings-reference.md` دربارهٔ Secretِ SMS · ۹-B جدولِ §۲ `background-jobs.md` در برابرِ registry واقعی · ۹-C docblockِ کهنهٔ `ClinicianRepository.php:17`) — **بدونِ تغییرِ هیچ فایلِ PHP** و بدونِ بازنویسیِ اسنادِ تاریخی.
- **ممنوع‌های رعایت‌شده:** بدون کد محصول · بدون تست · بدون تغییر workflow/CI · بدون schema · **بدونِ هر migration** · **بدونِ رزروِ شمارهٔ migration آینده** (هر عبارتِ ارجاع‌دهنده به شمارهٔ migrationِ بعدی، به «پیش از هر migration آینده» تبدیل شد) · بدون ادعای نشتِ `operational_logs` (هیچ خوانندهٔ production‌ای ندارد) · بدون `clinic_id=0` / Clinic مصنوعی / حدسِ مالکیتِ tenant · بدون ادعای NFR/مقیاس‌پذیری · بدون merge/Ready/force-push/tag/release/version-bump · PR #13 دست‌نخورده · End Gate فاز ۲ شروع/پاس نشد · Phase 3 / Phase 17 = NOT STARTED · Phase 2 = IN PROGRESS · C10 = CLOSED (با دامنهٔ محدودشده).
- **اعتبارسنجیِ این نشست:** Tripwire (`hardcodes: 0`, `allowlist_entries: 0`, `suspects: 1` = `SystemClinicResolver.php:52`) + **۵۹ self-test سبز** ✅ · بررسیِ لینک‌های نسبیِ Markdown ✅ · diff = **فقط docs + CHANGELOG** ✅ · CI پس از push در گزارشِ نهایی ثبت شد.
- **STOP طبق دستور** — پس از pushِ همان شاخه و DRAFT باقی‌ماندنِ PR #26؛ منتظر تصمیم مالک برای پذیرش/ادغام و برای بستنِ مواردِ OPEN باقی‌مانده (فهرستِ کلیدهای سطحِ نصب، جزئیاتِ schema/ایندکسِ لاگ عملیاتی، اندازه‌گیریِ دادهٔ legacy، زنجیرهٔ اشتقاقِ Location برای آبجکت‌های بدونِ `location_id`).

### [2026-09-13 09:40 UTC] — ایجنت Arena — T3 GREEN implementation slice (PR #28؛ اعتبارسنجی CI در انتظار)
- **فاز/محدوده:** فقط Phase 2 / T3 برای `appt.reminder`؛ PR #28 عمداً **OPEN + DRAFT** باقی می‌ماند. Phase 3 شروع نشده و هیچ merge/Ready/force-push/reset/migration انجام نشده است.
- **مبنای راستی‌آزمایی‌شده پیش از نوشتن:** `origin/main=a93d20ab1dfd81d2873ea345918e950f17a944d9`؛ PR #28 و شاخهٔ `arena/01a09667-doctor` در `6081c7392a0062e923b9036fcf31ad302ef96c65` و tree محلی clean بود. RED تاریخی مورد قبول: CI `34747206969` روی همان SHA.
- **اقدامات:** `ApptReminderHandler` به W-sweep محدود با join ساختاری Appointment→Location، زمان‌مرجع UTC ثابت، پنجرهٔ محافظه‌کارانهٔ تاریخ، eligibility نهایی per-Location، keyset cursor و continuation پایدار در `cpms_jobs.payload_json` تبدیل شد. payload continuation فقط `{continuation:true, version:1, reference_utc, cursor:{slot_date,slot_time,id}}` است و malformed payload با `JOB_PAYLOAD_INVALID` fail-closed می‌شود. `App::dispatcher()` فقط DI scope-neutral جدید را تزریق می‌کند؛ `JobQueue`/`JobsDispatcher`/`SmsService`/quiet-hours تغییر نکردند. تست‌های T3 برای mismatch، payload، fixed reference، cursor، prefix ردشدنی، continuation یکتا و exhaustion افزوده شد؛ مستند `background-jobs.md` نیز قرارداد و proof پنجره را ثبت می‌کند.
- **اعتبارسنجی محلی:** `git diff --check` موفق؛ lint WASM PHP 8.2 برای ۴ فایل PHP تغییرکرده موفق. PHPUnit/Integration/PHPStan/WPCS/Tripwire/Real-WP/Closure/Pilot در sandbox اجرا نشده‌اند؛ شواهد دقیق پس از commit/push و CI ثبت می‌شود.
- **کامیت‌ها:** هنوز ساخته نشده (در انتظار review نهایی diff و validation CI).
- **وضعیت tree:** dirty؛ فقط ۵ فایل tracked مرتبط با T3 تغییر کرده‌اند، به‌علاوه helper lint خارج از repo در `/home/ubuntu/doctor/lint-php.mjs` که باید قبل از commit حذف شود.

### [2026-09-16 21:48 UTC] — ایجنت Arena — Phase 4 Slice 3: WalkIn حرفه‌ای مشترک
- فاز/محدوده: فقط  در مسیر واقعی ؛ بدون تغییر migration/authorization/queue semantics.
- اقدامات: RED معتبر روی کامیت تست‌تنهای  و run  (یک شکست دقیق: 404  به‌علت استفاده از Clinic خانه A زیر Scope موثق B)؛ سپس Clinic عملیات از Scope موثق، مشارکت با ، مالکیت بیمار/Visit/settings/audit هم‌Clinic؛ ۶ تست پذیرش مثبت/منفی. fixture مستقیم Finance نیز Scope صریح موجود خود را برقرار می‌کند. CI evidence برای نمایش testcaseهای Slice 3 در JUnit افزوده شد.
- کامیت‌ها:  تست RED؛  کد+کنترل‌ها؛  Scope صریح fixture؛  شاهد اجرای testcaseها (شاخه ).
- CI:  روی  = SUCCESS؛ Integration دقیقاً ۸۷۸ تست/۱۰۵۴۴ assertion و suite جدید ۶ تست/۱۹۷ assertion، بدون failure/error/skip. run میانی  صریحاً FAILURE بود (۸ fixture مالی بدون Scope) و با  اصلاح شد؛ سبزی آن run ادعا نمی‌شود.
- تصمیمات درون‌فازی: REST همیشه Scope صریح RestClinicContext را مصرف می‌کند؛ no-scope چندکلینیکی fail-closed؛ Clinic خانه فقط داده سازگاری و نه tenant عملیات REST؛ auto-enqueue و Audit از Clinic Visit/Scope B.
- موارد باز/تحویل: تست Integration محلی به‌علت نبود PHP/WP/MySQL اجرا نشد؛ lint سه فایل PHP با PHP-WASM و Tripwire ۵۹/۵۹ محلی سبز بود. وضعیت نهایی Gateهای exact-head پس از commit این لاگ باید از GitHub بازیابی شود.
- وضعیت tree: در زمان ثبت این ورودی، تغییر فقط همین لاگِ append-only است؛ پس از commit باید clean شود.

### [2026-09-16 21:49 UTC] — ایجنت Arena — تصحیح ورودی بلافاصلهٔ قبل (آسیب shell-expansion)
- **تصحیح صریح:** در ورودی بلافاصلهٔ قبل، backtickهای Markdown به‌علت heredoc بدون quote توسط shell اجرا شدند و چند شناسه/نام از متن حذف شد؛ آن متن ناقص را نباید شاهد دانست. دادهٔ صحیح و کامل این ورودی است.
- فاز/محدوده: فقط `VisitService::walkIn()` در مسیر واقعی `POST /clinic/v1/visits/walk-in`؛ بدون تغییر migration/authorization/queue semantics.
- اقدامات: RED معتبر روی کامیت تست‌تنهای `0bb8bad` و run `35153195839` (یک شکست دقیق: 404 `CLINIC_NOT_FOUND` به‌علت استفاده از Clinic خانه A زیر Scope موثق B)؛ سپس Clinic عملیات از Scope موثق، مشارکت با `MembershipRepository::clinician_participates_in()`، مالکیت بیمار/Visit/settings/audit هم‌Clinic؛ ۶ تست پذیرش مثبت/منفی. fixture مستقیم Finance نیز Scope صریح موجود خود را برقرار می‌کند. CI evidence برای نمایش testcaseهای Slice 3 در JUnit افزوده شد.
- کامیت‌ها: `0bb8bad` تست RED؛ `463a456` کد+کنترل‌ها؛ `f771b6c` Scope صریح fixture؛ `8e1dad4` شاهد اجرای testcaseها (شاخه `arena/01a0ac20-doctor`).
- CI: `35154085312` روی `8e1dad4` = SUCCESS؛ Integration دقیقاً ۸۷۸ تست/۱۰۵۴۴ assertion و suite جدید ۶ تست/۱۹۷ assertion، بدون failure/error/skip. run میانی `35153752130` صریحاً FAILURE بود (۸ fixture مالی بدون Scope) و با `f771b6c` اصلاح شد؛ سبزی آن run ادعا نمی‌شود.
- تصمیمات درون‌فازی: REST همیشه Scope صریح RestClinicContext را مصرف می‌کند؛ no-scope چندکلینیکی fail-closed؛ Clinic خانه فقط دادهٔ سازگاری و نه tenant عملیات REST؛ auto-enqueue و Audit از Clinic Visit/Scope B.
- موارد باز/تحویل: تست Integration محلی به‌علت نبود PHP/WP/MySQL اجرا نشد؛ lint فایل‌های PHP تغییرکرده با PHP-WASM و Tripwire ۵۹/۵۹ محلی سبز بود. وضعیت نهایی Gateهای exact-head پس از commit این لاگ باید از GitHub بازیابی شود.
- وضعیت tree: در زمان ثبت این ورودی، تغییر فقط لاگ append-only است؛ پس از commit باید clean شود.

### [2026-09-17 02:40 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0ac5a-doctor`) — Phase 4 Slice 4: حل clinician صف برای حرفه‌ای مشترک
- **فاز/محدوده:** فقط `VisitService::queueScopeClinicianId()` روی مسیرهای واقعی `GET /clinic/v1/doctor/today`، `GET /clinic/v1/queue` و `GET /clinic/v1/rt/queue`. Slice فقط خواندنی است؛ walkIn/checkIn/transition/checkout، AuthorizationService، Scope establishment، Booking، Schedule، migrationها، ServiceOffering و pricing دست‌نخورده‌اند. پارامتر مردهٔ `clinician_id` روی `/secretary/today` عمداً در این slice اصلاح نشد.
- **مبنای راستی‌آزمایی‌شده پیش از ویرایش:** `main = 0a2f31c61d8bba60ba5e4a134e527b3db8326f44` (هم local HEAD و هم `git ls-remote`)، PR باز = ۰، آخرین migration = `2026_09_09_0020_idempotency_clinic_scope` (بدون ساخت یا رزرو ۰۰۲۱).
- **VALID RED (شاهد واقعی):** کامیت تست‌تنهای `21f7c3d` روی run `35160194475` — `Tests: 891, Assertions: 11159, Failures: 3` و هر سه شکست فقط در suite جدید و دقیقاً روی قرارداد هدف: doctor = 200 با صف/آمار خالی (`Failed asserting that an array contains 180`)، staff = `Failed asserting that 404 is identical to 200` با کد `CLINIC_NOT_FOUND`، و rt/queue = فید خالی. ده کنترل منفی/همجوار در همان run سبز بودند (رفتار موجود حفظ شده).
- **دو تلاش ناموفقِ قبل از RED معتبر (گزارش صادقانه — این‌ها RED نبودند):**
  ① `a0a7038` / run `35159625216` = **شکست fixture** نه RED: `cpms_visits.location_id` با Migration 0013‏ NOT NULL + FK `fk_visit_location` است (Migration 0015 آن را nullable نکرد) ⇒ درج مستقیم Visit خطای foreign key داد. رفع در `f406e4d` (ساخت Location صریح برای هر Clinic).
  ② `f406e4d` / run `35159920036` = **باگ assertion خودِ تست** نه RED: آرگومان‌های `assertGreaterThan`/`assertLessThan` جابه‌جا (actual, expected) پاس شده بودند. رفع در `21f7c3d` بدون تغییر معنا.
- **تغییر محصول (`0d2abfa`):**
  - پریمیتیو جدید `MembershipRepository::active_clinician_id_for_wp_user(int $wp_user_id): ?int` — حل «هویت یکتای فعال» فقط با `wp_user_id` و **بدون فیلتر Clinic**؛ بدون `ORDER BY`/`LIMIT 1`/«ردیف اول»: یک پرس‌وجوی تجمعی (`COUNT(*)`, `MIN(id)`, `MAX(id)`) همیشه دقیقاً یک ردیف دارد و `COUNT(*)=1 AND MIN(id)=MAX(id)` یکتایی ساختاری `u_clinician_user` را باز-راستی‌آزمایی می‌کند.
  - **تفکیک شکست DB از نبودِ مشروع:** چون `COUNT(*)` همیشه ردیف می‌دهد، `fetchRow() === null` فقط یعنی خودِ پرس‌وجو شکست خورده ⇒ `RuntimeException` (کنوانسیون موجود همین Repository) ⇒ در REST به `CLINIC_INTERNAL_ERROR` (500) می‌رسد و هرگز «پزشک ندارد»/صف خالیِ بی‌صدا نمی‌شود. `n = 0` ⇒ نبودِ مشروع (بدون ردیف یا ردیف غیرفعال) ⇒ null.
  - `queueScopeClinicianId()`: doctor = حل هویت یکتای فعال → نبود ⇒ ۰ (رفتار ADR-0030) → `clinician_participates_in(identity, trustedClinic)` → false ⇒ ۰ → در غیر این صورت همان هویت. staff = بدون پارامتر ⇒ null (بدون تغییر)؛ با پارامتر ⇒ فقط اگر فعال و مشارکت‌کننده در Clinic مورد اعتماد، وگرنه همان 404 `CLINIC_NOT_FOUND`. از `MembershipRepository` موجود استفاده می‌شود و منطق عضویت تکرار نشده است.
- **برخورد واقعی با تست موجود (مهم):** run `35160454285` (پس از پچ محصول) سه RED را سبز کرد اما `ClinicTenantIsolationTest::testDoctorQueueScopeNeverUnionsClinics` را شکست. این یک تضاد قراردادیِ اجتناب‌ناپذیر است نه flake: آن تست یک پزشک با عضویت ACTIVE در هر دو Clinic A و B، یک هویت clinician (خانه A) و یک Visit در هر Clinic می‌سازد و انتظار «صف خالی زیر context موثق B» را داشت — با این توجیهِ درون‌کدی که «پروفایل پزشک در B وجود ندارد»، یعنی `clinicians.clinic_id` به‌عنوان مرز دامنه. دو fixture از نظر ساختاری یکسان‌اند، پس هیچ پیاده‌سازی نمی‌تواند هر دو را برآورده کند. انتظارِ کهنه در `82a421f` تصحیح شد: خاصیت ضد-union **در هر دو جهت** صریح asserts می‌شود (ویزیت B دیده می‌شود، ویزیت A دیده نمی‌شود، دقیقاً یک ردیف) و هیچ assertion حذف/سست نشد.
- **کنترل‌های منفی (همه در suite جدید و همه سبز روی main و پس از پچ):** پزشک بدون هویت ⇒ ۲۰۰ با صف/آمار خالی؛ هویت غیرفعال ⇒ همان‌طور؛ مشارکت suspended در B ⇒ REST موجود `403 CLINIC_SCOPE_UNAVAILABLE` و در سطح سرویس (با Scope صریح B) صف خالی؛ فیلتر کارکنی با clinician بدون مشارکت/معلق/ناموجود/غیرفعال ⇒ `404 CLINIC_NOT_FOUND`؛ منشی بدون فیلتر ⇒ کل صف Clinic B و هرگز Clinic A؛ بازیگر غیرمجاز در B ⇒ `403 CLINIC_PERMISSION_DENIED`؛ هویت یکتا و Clinic خانه دست‌نخورده + صفر جهش Visit؛ پزشکِ Clinic خانه در context A ⇒ فقط ویزیت‌های خودش (نه همکار).
- **مرز tenant صف (بازبینی کد):** `queueFor`/`statsFor`/`eventsSince`/`lastEventId` در `VisitRepository` بدون تغییر باقی ماندند و هر چهار همچنان `clinic_id = %d` بی‌قیدوشرط + clinician اختیاری دارند؛ tenant صف همچنان از `queueClinicId()`/`App::scope()` می‌آید و `clinician_id` فقط تنگ‌تر می‌کند.
- **اعتبارسنجی محلی:** PHP/Composer/MySQL در sandbox نصب نیستند و مخازن apt مسدودند ⇒ **Integration/Unit محلی NOT RUN**؛ پکیج‌های php-wasm هم باینری لازم را نداشتند. آنچه محلی اجرا شد: Tripwire (`59/59` self-test سبز، scan = `hardcodes: 0, suspects: 1` = همان suspect قدیمی `SystemClinicResolver.php:52`) و پارس‌شدنِ syntactic فایل‌های تغییرکرده با پارسر PHP (با کنترل منفی: فایل عمداً خراب = PARSE FAIL).
- **CI روی HEAD نهایی این slice (`82a421f48d6bcda5bb0f789ab2a6d3285c7be19f`):** run `35160862027` = SUCCESS؛ Integration ترمینال `OK (891 tests, 11179 assertions)` با `errors=0 warnings=0 failures=0 skipped=0`؛ suite جدید `Phase4Slice4QueueSharedProfessionalTest` = ۱۳ تست / ۶۳۳ assertion / صفر شکست؛ `Phase4Slice3WalkInSharedProfessionalTest` = ۶/۱۹۷ و suiteهای Phase 3 Slice 6 سبز. دانلود artifact لاگ/JUnit از sandbox مسدود است ⇒ شواهد per-test برای `ClinicTenantIsolationTest`/`DoctorQueueScopeTest` در دانهٔ تک‌تست **NOT RETRIEVED** (شاهد موجود: aggregate صفر شکست + ناپدیدشدنِ همان تک‌شکستِ run قبلی).
- **Git/PR:** فقط کامیت forward-only روی `arena/01a0ac5a-doctor`؛ PR **#62 DRAFT** (بدون Ready/merge/force-push/reset/rebase/tag/release و بدون تغییر main).
- **وضعیت tree:** در زمان ثبت این ورودی تغییر فقط همین لاگ append-only است؛ پس از commit باید clean شود. وضعیت Gateهای exact-head برای کامیتِ این لاگ پس از push از GitHub بازیابی می‌شود.

### [2026-09-17 07:08 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0acc1-doctor`، PR #63 DRAFT) — Phase4 Clinic Profile Canonicalization — FIX 3 ACCEPTANCE BLOCKERS — STOP
- **فاز/محدوده:** فقط PR #63 — Clinic Profile Canonicalization — سه مانع پذیرش (حذف فایل گزارش، تقویت تست updated_at، بازگردانی escaping تصادفی). بدون تغییر معماری ClinicProfileService / ClinicRepository / AuthorizationService / Location / timezone / FinanceService / Pricing / ServiceOffering / migrations. بدون 0021.
- **مبنای راستی‌آزمایی‌شده پیش از ویرایش:** `origin/main = b0f7f7015cd2b8a4674213a15fe17fe2ed5754c7` (هم local و هم `git ls-remote`)، PR #63 = OPEN + DRAFT، head = `f11025faaa10830538f4736c28904d6dcf0ad6ed` (مطابق expected)، base = `b0f7f7015cd2b8a4674213a15fe17fe2ed5754c7` (مطابق expected)، open PRs = فقط #63، working tree پس از `git checkout HEAD -- .` تمیز و منطبق بر head.
- **VALID RED (شاهد واقعی — صادقانه):** run `35167649955` و `35166751584` روی کامیت‌های اولیهٔ این شاخه — `Phase4ClinicProfileCanonicalizationRedTest::testWizardSaveShouldUpdateCanonicalAndDownstreamButCurrentlyDoesNot` با پیام `RED EVIDENCE: cpms_clinics(A).name should equal operator-entered value after wizard save, but remains old` — Expected `'کلینیک جدید A - RED TEST'` Actual `'کلینیک قدیمی A'` — اثبات می‌کرد wizard به `setup.clinic.*` می‌نوشت و `cpms_clinics` قدیمی می‌ماند. این RED معتبر مبنای GREEN شد.
- **تلاش‌های ناموفق میانی قبل از GREEN نهایی (گزارش صادقانه — این‌ها RED نبودند، بلکه شکست‌های fixture/test):**
  ① `ClinicScope::__construct()` private — `Call to private ClinicCore\Application\Scope\ClinicScope::__construct()` در `Phase4ClinicProfileTest::testNegativeD_SuspendedMembershipDenied` — رفع با `ClinicScope::forClinic()->withOrganization`.
  ② `updated_at` با دقت `.000` — همان ثانیه می‌ماند — `assertNotEquals` شکست — موقتاً به `notEmpty` تضعیف شده بود (ضعیف) — اکنون با blocker 2 به شکل قوی deterministic بازگردانده می‌شود.
  ③ audit COUNT — `cpms_audit_log` (مفرد) به‌جای `cpms_audit_logs` (جمع) و ستون `target_id` به‌جای `resource_id` — `null` برمی‌گشت — رفع با جدول جمع و `resource_id` و `?? '0'`.
  ④ `FinanceException: دسترسی لازم را ندارید` در `receipt()` — `cpms_manager` فاقد `INVOICE_READ` — نیاز به grant در `cpms_membership_capabilities` + WP cap `cpms_invoice_read` + `cpms_config` — رفع با `grantCapability` + `grantWpCap`.
  ⑤ `SetupWizardTest` — انتظارات قدیمی dual-write `setup.clinic.*` + timezone fallback — خلاف canonical — بازنویسی برای چک `cpms_clinics` canonical و حفظ تاریخی `setup.clinic.*`.
  ⑥ Static Analysis — `Anonymous function has an unused use $current/$createdAt/$actorUserId` در `ClinicProfileService.php:168` — رفع با حذف `use`های بلااستفاده.
  ⑦ `PHASE4_CLINIC_PROFILE_REPORT_FA.md` — فایل evidence تولیدشدهٔ بدون درخواست — باید حذف شود (blocker 1).
  ⑧ `renderHealth()` — `;font-weight:600;\"&gt;` (backslash اضافی) به‌جای `;font-weight:600;"&gt;` — رگرسیون تصادفی escaping (blocker 3).
- **تغییر محصول در این نشست (فقط سه قلم مجاز):**
  - **Blocker 1:** حذف `PHASE4_CLINIC_PROFILE_REPORT_FA.md` از PR (نه جابجایی، نه کپی، نه ساخت فایل گزارش دیگر). شواهد حذف: `git status` → `D PHASE4_CLINIC_PROFILE_REPORT_FA.md` و `git diff --stat` بدون آن فایل.
  - **Blocker 2:** تقویت تست `updated_at` — در `Phase4ClinicProfileTest::setUp()` مقدار اولیهٔ `cpms_clinics.updated_at` برای Clinic A/B به `'2020-01-01 00:00:00.000'` (clearly earlier valid timestamp) ست می‌شود — بدون `sleep` — سپس در `testHappyPathUpdatesCanonicalAndPreservesInvariants` مقدار `before.updated_at` چک می‌شود که دقیقاً `'2020-01-01 00:00:00.000'` است و `after.updated_at !== before.updated_at` و `notEmpty` — اثبات deterministic تغییر timestamp روی تغییر واقعی. سایر assertionها تضعیف نشدند. اگر تست محصول واقعی را نشان دهد، STOP قبل از تغییر product code (در این مورد محصول درست کار می‌کند — `nowUtcSql()` مقدار جدید می‌دهد).
  - **Blocker 3:** بازگردانی escaping در `src/Admin/CpmsSetupWizard.php::renderHealth()` — خط `echo '<span style=\"color:' . esc_attr($cls) . ';font-weight:600;\\\">'` (غلط) به `echo '<span style=\"color:' . esc_attr($cls) . ';font-weight:600;\">'` (درست) — مطابق base `origin/main:450` — `od -c` تأیید: `;   "   >   '` (بدون backslash). فقط همین یک خط، بدون تغییر UI اطراف.
- **معماری محصول بدون تغییر (تأیید):** `ClinicProfileService` — trusted `trustedClinicId` + CONFIG auth + atomic validation + preserve invariants + no-op + audit only on change + fail-closed — دست‌نخورده؛ `ClinicRepository::find/updateProfile` — دست‌نخورده؛ `AuthorizationService`/`Location`/`timezone`/`FinanceService`/Pricing/ServiceOffering دست‌نخورده؛ بدون migration جدید (آخرین `0020`).
- **کامیت‌های این شاخه (تا قبل از این لاگ):** `dfef387` (GREEN اولیه) → `c9dd91a` (fix private ctor/audit/updated_at/INVOICE_READ) → `caab63a` (fix audit plural + WP caps) → `f11025f` (افزودن گزارش فارسی — حذف در همین نشست) — این لاگ.
- **CI روی head قبلی `caab63a` (آخرین head سبز قبل از گزارش):** run `35169312043` = SUCCESS — `Integration (WP 6.7 + MySQL 8) pass 1m53s` + `Static Analysis (PHPStan) pass 44s` + Unit 8.1-8.4 + WPCS + Tripwire + Real WP Acceptance (wp_/clinic_) + Closure (5/5) + Upgrade path + Release Artifact + Responsive smoke — همه سبز، جز Staging Gate که در لحظهٔ چک pending بود. شواهد per-test برای `Phase4ClinicProfileTest`/`Phase4ClinicProfileCanonicalizationRedTest` در JUnit aggregate صفر failure داشت، اما دانلود لاگ خام از sandbox مسدود است ⇒ **per-test PASS برای تک‌تست updated_at در این run NOT RETRIEVED** (شاهد موجود: aggregate green + عدم وجود failure در کامنت PR برای آن SHA).
- **وضعیت exact-head جدید پس از این کامیت اصلاحی:** در زمان ثبت این ورودی، کامیت اصلاحی هنوز push نشده ⇒ **head جدید full SHA = pending/not-yet-known** و **exact-head check run IDs = pending/not-yet-known** — ادعای سبزی آینده نمی‌شود. پس از push، با `git rev-parse HEAD` و `gh pr checks 63` بازیابی می‌شود و در گزارش نهایی ثبت می‌شود. از ایجاد کامیت‌های حلقوی صرفاً برای به‌روزرسانی run ID خودِ همین کامیت لاگ خودداری شد.
- **اعتبارسنجی محلی:** PHP/Composer/MySQL در sandbox نصب نیستند ⇒ **Integration/Unit محلی NOT RUN**؛ آنچه محلی اجرا شد: `git diff --stat` — فقط سه فایل مجاز تغییر (حذف گزارش + `CpmsSetupWizard.php` یک خط + `Phase4ClinicProfileTest.php` تقویت updated_at) + همین لاگ append-only؛ `php -l` با WASM برای فایل‌های تغییرکرده **NOT RETRIEVED** (محیط فاقد PHP)؛ Tripwire محلی **NOT RUN**.
- **Git/PR:** فقط کامیت forward-only روی `arena/01a0acc1-doctor`؛ PR #63 همچنان **DRAFT** (بدون Ready/merge/close/delete branch/tag/release)؛ بدون `reset --hard`/`git clean`/`rebase`/`force-push`؛ push به همین شاخه.
- **وضعیت tree:** در زمان ثبت این ورودی، تغییر فقط همین لاگ append-only + سه اصلاح مجاز است؛ پس از commit باید clean شود. وضعیت Gateهای exact-head برای کامیتِ این لاگ پس از push از GitHub بازیابی می‌شود.

### [2026-09-17 07:26 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0acc1-doctor`، PR #63 DRAFT) — CORRECTION: VALID RED evidence for Clinic Profile — STOP
- **تصحیح صریح:** ورودی قبلی `[2026-09-17 07:08 Asia/Tehran]` در بخش VALID RED به‌صورت مبهم هر دو `35166751584` و `35167649955` را زیر VALID RED قرار داده بود — آن سطر نادقیق/مبهم بود و اصلاح می‌شود.
- **VALID RED فقط:** run `35166751584`، commit `1c94e65` (test-only commit) — canonical Clinic profile پس از مسیر موفق موجود Wizard قدیمی stale ماند — شاهد واقعی RED.
- **NOT VALID RED:** run `35167649955`، commit `dfef387` — این یک اجرای failing پس از GREEN اولیه بود (2 errors + 6 failures) با نقص‌های fixture/test شامل private `ClinicScope` constructor، timestamp same-second assertion، و انتظارات قدیمی `SetupWizardTest` — RED معتبر نیست.
- **دامنهٔ این تصحیح:** فقط همین گزارهٔ شواهدی را supersede می‌کند؛ سایر حقایق ثبت‌شدهٔ قبلی بدون تغییر باقی می‌مانند مگر اینکه مستقلاً نقض شوند.
- **عدم افزودن شواهد گمانه‌ای:** هیچ run ID دقیق برای head جدید فعلی قبل از وجود واقعی اضافه نمی‌شود.
- **Git/PR:** بدون تغییر کد محصول/تست/workflow/migration — فقط همین لاگ append-only.

### [2026-09-17 08:32 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0adaf-doctor`، PR #64 DRAFT) — تنها blocker: attach کردن WP user موجود به Clinic هدف — IMPLEMENTED / EVIDENCE-CLOSED PENDING FINAL LOG HEAD
- **دامنهٔ دقیق:** فقط مسیر Staff Management برای اینکه operator مجاز در Clinic B حساب WordPress موجود/حرفه‌ای را به Clinic B attach کند؛ بدون ساخت WP user یا clinician identity دوم. Schedule/Booking/WalkIn/Queue/AuthorizationService architecture/Location CRUD/pricing/geography/specialty/Patient Portal/migration دست‌نخورده ماندند؛ migration جدید وجود ندارد و آخرین migration همچنان `0020` است.
- **راستی‌آزماییِ پیش از کدنویسی:** `origin/main` و `main` روی `30c4eee3fe3ae33b3b18054d0f6de70fd71875a3` بودند؛ PR بازِ مرتبط پیش از شروع وجود نداشت. شروع ثبت‌شدهٔ نشست: `START_TEHRAN=2026-09-17 04:46:23 Asia/Tehran`.
- **RED معتبر:** test-only head `5ae1c6e06102863472ca0759062d3f7060601c4d`، run `35183271154`، aggregate `Tests: 906, Assertions: 11384, Failures: 1.`؛ شکست دقیق `Phase4ExistingStaffOnboardingTest::testClinicBStaffPathAttachesExistingUserWithoutCreatingIdentity`: خطای واقعیِ مسیر create، `Sorry, that username already exists!`، در برابر انتظار خطای خالی. این RED از missing-method/parse/setup failure نبود و blocker را در product/admin contract نشان داد.
- **پیاده‌سازیِ forward-only:** در `StaffManagementPage`، فرم nonceدار `mode=attach_existing` فقط شناسهٔ پایدار WP و نقش Clinic-scoped می‌گیرد؛ `save()` آن را parse می‌کند؛ `attachExistingUser()` authentication/current operator را کنترل، Clinic درخواست‌شده را از persistence با `authorizeStaffWrite()` و CONFIG/مجوز scoped دوباره authorize، role را به allowlist CPMS محدود، user nonexistent و administrator را fail-closed می‌کند، و فقط `MembershipService::create_membership()` را صدا می‌زند. هیچ `wp_insert_user()`، تغییر `u_clinician_user`، ساخت clinician، merge با mobile، تغییر home Clinic یا تغییر membership قبلی ندارد. active/suspended duplicate از همان service به conflict قطعی برمی‌گردد و silently reactivate/duplicate نمی‌شود؛ audit attach نیز ثبت می‌شود.
- **تست‌های اضافه‌شده:** `tests/Integration/Phase4ExistingStaffOnboardingTest.php` مسیر واقعی `StaffManagementPage::upsertUser()` با `mode=attach_existing` را پوشش می‌دهد: حفظ WP identity/role/capability، حفظ membership و clinician/home-Clinic قبلی، دقیقاً یک identity، Clinic-A-only authorization، no Clinic authorization، target raw/zero/fixed Clinic rejection، nonexistent WP user، active duplicate، suspended duplicate و administrator؛ نقش disallowed نیز در مسیر negative fixture کنترل می‌شود. تست‌ها فقط زیرساخت موجود را مصرف می‌کنند و migration/CI تغییر نکرده است.
- **اصلاح حین اعتبارسنجی:** اجرای نخست روی `0480b80` در testِ authorization موجود به‌علت نمایش global user choices، markerهای Clinic B را در renderِ Clinic A نشت می‌داد؛ این production leak با commit `f6ac779` حذف شد: UI اکنون stable WP ID را می‌گیرد و فهرست global user/name/email تولید نمی‌کند. PHPStan annotation همان run نیز یک import missing برای `MembershipException` را نشان داد و در همان fix اضافه شد. این دو مورد fixture failure نبودند؛ اصلاح محصول بودند.
- **GREEN دقیقِ قابل‌بازیابی برای pre-log head:** commit `f6ac779e205dac724e99277256d5ee26566bf05b` روی شاخه push شد. CI run `35183825260` = **SUCCESS**: terminal `OK (912 tests, 11507 assertions)`، JUnit root `tests=912, assertions=11507, errors=0, warnings=0, failures=0, skipped=0`; PHPStan، WPCS، Tenant Tripwire و Unitهای PHP 8.1/8.2/8.3/8.4 نیز pass شدند. Closure Gate run `35183822682` = success (runtime PHP 8.1/8.3/8.4، compat WP، restoreApply همگی pass)؛ Real WP Acceptance run `35183825249` = success برای prefixهای `clinic_` و `wp_`؛ Pilot/Staging Gate run `35183822616` = success (Release Artifact، Upgrade path، Staging Gate و Responsive smoke).
- **مرز شواهد:** artifact خام JUnit/log از sandbox با `EOF` قابل download/retrieve نبود و completion comment suite جدید `Phase4ExistingStaffOnboardingTest` را به‌صورت per-suite چاپ نکرد؛ بنابراین **per-test/per-suite PASS برای این class = NOT RETRIEVED** و از aggregate green استنتاج نمی‌شود. فقط aggregate GREEN بالا و تغییر count (`906` RED → `912` GREEN) ثبت شده است. اجرای PHPUnit/PHPStan/WPCS محلی **NOT RUN** است چون PHP/Composer/MySQL در sandbox موجود نیستند؛ `git diff --check` محلی VERIFIED است.
- **Git/PR/Gates:** دو commit forward-only این نشست `0480b80` و `f6ac779` هستند؛ فقط به `arena/01a0adaf-doctor` push شده‌اند. PR #64 همچنان **OPEN + DRAFT** و **بدون Ready/merge/close/delete/force-push/rebase/reset/clean/tag/release**؛ `main` تغییر نکرده است. پس از این append-only log، exact head و check runهای نهایی هنوز **PENDING / NOT RETRIEVED** هستند و نباید با شواهد pre-log اشتباه شوند.

### [2026-09-17 09:20 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0adaf-doctor`، PR #64 DRAFT) — FIX ONLY: enumeration parity for existing-user attach
- **پیش‌شرط زنده:** PR #64 همچنان OPEN + DRAFT، base/main=`30c4eee3fe3ae33b3b18054d0f6de70fd71875a3` و head پیش از این blocker=`64b88db6bf6e95742821156fe5bf7aabd8c8a495` بود. local checkout ابتدا روی base با همان محتوای دقیقِ head پراکنده/unstaged بود؛ با stash موقتِ non-destructive و fast-forward امن به همان head همگام شد؛ بدون reset/clean/rebase/force-push. main در این نشست تغییر نکرده است.
- **Focused RED معتبر:** commit test-only=`1e8a1b899a84911a5b3de9f69cb5869fa20a1fea`، CI run=`35187126304`، integration=`Tests: 913, Assertions: 11525, Failures: 1.`؛ failure دقیق در `Phase4ExistingStaffOnboardingTest::testAuthorizedTargetUserRejectionsAreEnumerationNeutralAndUnauthorizedLookupStaysScoped`: برای nonexistent پیام `کاربر WordPress موجود یافت نشد.` و برای administrator پیام `حساب administrator از مسیر مدیریت پرسنل قابل افزودن نیست.` بود. این شکست regression واقعیِ parity است، نه setup/parse failure؛ DB warningهای انتهای log علت failure این تست نبودند.
- **حداقل fix:** فقط `StaffManagementPage::attachExistingUser()` تغییر کرد؛ شاخهٔ nonexistent و administrator یک rejection خنثی و یکسان می‌دهند: `حساب انتخاب‌شده برای افزودن به این کلینیک قابل استفاده نیست.` Authorization قبل از `get_userdata()`، MembershipService، success path، duplicate/suspended semantics، role mutation، identity و audit architecture تغییر نکردند.
- **تست focused:** همان test اکنون equality پیام و شکل خروجی (`error/generated/user_id`)، zero membership mutation، حفظ global administrator role، و authorization-before-target-lookup برای nonexistent و administrator را assert می‌کند؛ negativeهای قبلی تضعیف نشدند.
- **Audit correction:** هیچ شواهد مستقیمی در این نشست retrieve نشد که `cpms_audit_logs.clinic_id` برای attach برابر Clinic B باشد. فقط وجود `clinic_id` در metadata ثبت‌شدهٔ مسیر قبلی شناخته شده است؛ ستون ممکن است به‌دلیل اتکای logger موجود به `App::scope()` مقدار `NULL` داشته باشد. این موضوع blocker این task نیست و audit architecture عمداً تغییر نکرد.
- **کامیت کد:** `6b8aa86e98c5ca26a0be2e3a54043a2b59449027` (`fix: neutralize existing-user enumeration`) روی همان branch push شد. پس از append این entry، head/checkهای نهایی هنوز pending هستند و نباید ادعای GREEN pre-log ساخته شود.
- **اعتبارسنجی محلی:** `git diff --check` سبز؛ PHPUnit/PHPStan/WPCS محلی **NOT RUN** چون PHP/Composer/MySQL در sandbox موجود نیستند. per-test artifact خام از sandbox در این مرحله **NOT RETRIEVED**؛ شواهد RED بالا از PR completion comment مستقیماً بازیابی شد.
- **محدوده:** فقط `StaffManagementPage.php`، `Phase4ExistingStaffOnboardingTest.php` و همین entry append-only تغییر می‌کنند؛ بدون migration/0021، workflow، AuthorizationService، MembershipService، Location، Schedule، Booking، Visit، Queue، Pricing یا ServiceOffering.

### [2026-09-17 10:39 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0ae2c-doctor`، بدون PR) — PHASE4 «افزودن WP user موجود به Clinic دیگر»: STOP بدون ویرایش — کار از قبل در main موجود است
- **LIVE REVERIFY:** task این نشست انتظار داشت main روی baseline `30c4cee3fe3ae33b3b18054d0f6de70fd71875a3` (merge PR #63) باشد. راستی‌آزمایی زندهٔ GitHub API: main زنده = `ad5a2617ebc474fbb6d0e973ecd8cb036c60f85b` (merge PR #64 در 2026-09-17 06:03:03 UTC). main نسبت به baseline موردانتظار جلو رفته و delta آن دقیقاً همین slice درخواستی است ⇒ مطابق قاعدهٔ STOP خودِ task، بدون هیچ ویرایش کد/تست/workflow/migration متوقف شدم. PR باز: صفر (مطابق انتظار). آخرین migration: `2026_09_09_0020_idempotency_clinic_scope.php` — بدون 0021. شروع ثبت‌شدهٔ نشست: `START_TEHRAN=2026-09-17 10:32:45 Asia/Tehran`. `git status` پیش از این append: clean روی `ad5a261`.
- **کار موجود (بازیابی مستقیم):** PR #64 «Phase 4: attach existing WP staff to another Clinic» (MERGED) — فایل‌ها: `src/Admin/StaffManagementPage.php` (+107/−1)، `tests/Integration/Phase4ExistingStaffOnboardingTest.php` (+497 جدید)، `docs/agent-guide.md` (+21). کامیت‌های main بعد از baseline: `5ae1c6e` (test-only RED)، `0480b80` (feat)، `f6ac779` (fix: حذف نشت lookup کاربران cross-clinic)، `64b88db` (docs)، `1e8a1b8` (test-only RED enumeration parity)، `6b8aa86` (fix: پیام‌های rejection خنثی)، `47fe469` (docs، head نهایی PR).
- **مسیر محصول موجود (بدون تغییر در این نشست):** `admin_post_cpms_staff_save` → `StaffManagementPage::save()` (is_user_logged_in + check_admin_referer) → `upsertUser(mode=attach_existing)` → `attachExistingUser()`: کنترل actor جاری، `authorizeStaffWrite()` (TrustedClinicEstablisher + مجوز CONFIG/Scoped از persistence — clinic_id ارسالی فقط selector)، allowlist نقش CPMS، `get_userdata(existing_user_id)` (شناسهٔ پایدار WP؛ بدون merge موبایل؛ administrator رد می‌شود)، و تنها membership-write = `App::membership_service()->create_membership()`. active/suspended duplicate = conflict قطعی از همان service (بدون reactivate/duplicate خاموش). audit: `STAFF_USER_ATTACHED`.
- **پوشش تست موجود (۸ متد):** happy path (حفظ WP identity/role/capability، حفظ membership A و clinician/home-Clinic، دقیقاً یک identity) + negativeهای A–F: بدون مجوز Clinic، فقط-A نتواند B را تغییر دهد، WP user ناموجود، duplicate active، duplicate suspended، نقش غیرمجاز + enumeration parity. با کنترل‌های منفی task مطابقت دارد.
- **شواهد CI بازیابی‌شده (completion comments PR #64 + GitHub API؛ بدون اجرای محلی):** RED معتبر: run `35183271154` روی commit test-only `5ae1c6e06102863472ca0759062d3f7060601c4d` — `Tests: 906, Assertions: 11384, Failures: 1.` در `Phase4ExistingStaffOnboardingTest::testClinicBStaffPathAttachesExistingUserWithoutCreatingIdentity` با Expected `''` / Actual `'Sorry, that username already exists!'` — یعنی مسیر create واقعی موجود user را attach نکرد. GREEN نهایی pre-merge: run `35187343756` روی head `47fe469b1892d364835ce84b2693855bc03bbc6c` = success؛ terminal `OK (913 tests, 11541 assertions)`؛ JUnit root: `tests=913 assertions=11541 errors=0 warnings=0 failures=0 skipped=0`. پس از merge روی main `ad5a261`: run `35188168950` = success (Integration 1m46s + PHPStan + Unit 8.1/8.2/8.3/8.4 + WPCS + Tenant Tripwire) و تمام ۱۹ check-run روی کامیت merge = success.
- **مرز صداقت شواهد:** per-test/per-suite PASS برای `Phase4ExistingStaffOnboardingTest` در runهای GREEN = **NOT RETRIEVED** (completion comment فقط selection suiteهای دیگر را چاپ می‌کند؛ لاگ خام از sandbox قابل بازیابی نبود). aggregate green مبنای ادعای اجرای تک‌تست قرار نگرفت. اجرای محلی PHPUnit/PHPStan/WPCS = **NOT RUN** (PHP/Composer/MySQL در sandbox نیستند — با `which php composer mysql` راستی‌آزمایی شد).
- **یادداشت اصلاحی همین نشست:** نخستین تلاش برای append با ابزار ویرایش، به‌اشتباه header ورودی 09:20 را جایگزین کرد؛ بلافاصله با بازگردانی محتوای کامیت‌شدهٔ همان فایل (تنها تغییر uncommitted همین خطای خودم بود؛ `git diff` پیش از اصلاح فقط همین فایل را نشان می‌داد) و append صحیح در انتهای فایل اصلاح شد. هیچ ورودی دیگری تغییر یا حذف نشد.
- **اقدام این نشست:** فقط همین ورودی append-only (الزام governance) + commit forward-only روی `arena/01a0ae2c-doctor` و push به همین شاخه. بدون ساخت PR (شرط STOP؛ هیچ تغییر محصول/تستی برای review وجود ندارد)، بدون Ready/merge/close/delete branch/tag/release/force-push/reset/clean/rebase؛ main مستقیماً تغییر داده نشد.

### [2026-09-17 11:46 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0ae2c-doctor`، PR #65 DRAFT) — PHASE4 «شعبه‌ها (Location master data): ساخت + ویرایش name/timezone» — IMPLEMENTED / GREEN
- **LIVE REVERIFY:** main زنده = `ad5a2617ebc474fbb6d0e973ecd8cb036c60f85b` (مطابق انتظار)، PR باز = ۰، آخرین migration = `0020` (بدون 0021)، working tree clean. کار Location-management موجود نبود (فقط `PrimaryLocationResolver` فقط-خواندنی + seed مهاجرت 0011؛ هیچ صفحهٔ admin/REST/سرویس/ریپوی Location روی main نبود) ⇒ **PRE-EXISTING RED NOT AVAILABLE** (طبق استثنای مصوب task، boundary + تست‌های قراردادی با هم ساخته شدند و رفتار داخل boundary جدید test-first هدایت شد). شروع: `START_TEHRAN=2026-09-17 11:08:33 Asia/Tehran`.
- **مرز پیاده‌سازی (کوچک‌ترین سطح قابل‌نگهداری، هم‌راستا با StaffManagementPage/ClinicProfileService):** `admin_post_cpms_location_save` → `LocationAdminPage::save()` (is_user_logged_in + `check_admin_referer`) → `upsertLocation()` (مرز خالص: TrustedClinicEstablisher — clinic_id ارسالی فقط selector — + مجوز CONFIG scoped) → `LocationService` (CONFIG را دوباره احراز می‌کند؛ validation کامل قبل از mutation؛ parity برای ناموجود/خارجی؛ IANA-only بدون fallback؛ audit با trusted clinic_id صریح) → `LocationRepository` (create همیشه `is_primary=0`/`is_active=1` را قطعی می‌کند؛ update فقط name/timezone/updated_at) → DB. فایل‌های جدید: `src/Admin/LocationAdminPage.php`، `src/Application/Location/LocationService.php`، `src/Application/Location/LocationException.php`، `src/Infrastructure/Repository/LocationRepository.php`، `tests/Integration/Phase4LocationMasterDataTest.php` (۱۴ تست از مسیر واقعی admin boundary). تغییر یافته‌ها: `src/Bootstrap/App.php` (ثبت صفحه + locationRepository/locationService)، `.github/workflows/ci.yml` (فقط selection شواهد per-suite برای suite جدید — الگوی مستقر Slice3/4؛ بدون تغییر گیت‌ها)، همین لاگ. بدون migration/0021؛ Schedule/Booking/WalkIn/Queue/Pricing/ServiceOffering/Organization/geography/Clinic Profile/PrimaryLocationResolver دست‌نخورده.
- **RED معتبر داخل boundary جدید (مطابق شرط‌های مصوب):** کامیت `0fda84b` — CI run `35197860173`: `Tests: 927, Assertions: 12130, Failures: 1.` — تنها شکست: `Phase4LocationMasterDataTest::testNoOpUpdateIsExplicitAndProducesNoSuccessAudit` با «identical name+timezone must be reported as an explicit no-op — Failed asserting that false is true.» (خط ۲۸۹). bootstrap/ثبت/fixture/احراز هویت/establishment/دسترسی به سرویس واقعی همگی موفق بودند و assertion رفتاری شکست خورد — پیاده‌سازی اولیهٔ واقعی (نه stub ردکننده) همیشه update+audit می‌زد. ۱۳ تست دیگر همان run سبز بودند؛ warningهای DB در tail مربوط به تست‌های دیگر (VisitNoShow*/SecurityHardening) و از قبل موجود بودند.
- **GREEN:** کامیت `a993cee6759695b7abb22aee5108e0ed0ec2e5f5` (تشخیص صریح no-op: مقادیر یکسان ⇒ return noop=true بدون update/updated_at/audit موفق). CI run `35198192935` = success: terminal `OK (927 tests, 12135 assertions)`؛ JUnit root: `tests=927 assertions=12135 errors=0 warnings=0 failures=0 skipped=0`. **شاهد per-suite/per-test مستقیم** (کامنت completion PR #65): `Phase4LocationMasterDataTest tests="14" assertions="594" errors="0" warnings="0" failures="0" skipped="0"` + هر ۱۴ testcase با شمارش assertion جدا (از جمله `testNoOpUpdateIsExplicitAndProducesNoSuccessAudit` با ۳۸ assertion و `testUpdateTimezoneDoesNotRewriteHistoricalOperationalRows` با ۵۴ assertion).
- **گیت‌های exact-head (`a993cee`):** CI `35198192935` = success (Integration 1m53s + PHPStan + WPCS + Unit 8.1/8.2/8.3/8.4 + Tripwire)؛ Closure Gate `35198187315` = success (runtime 8.1/8.3/8.4 + WP 6.4-6.6 + restoreApply)؛ Pilot/Staging `35198187110` = success (Upgrade path + Staging + Release Artifact + Responsive)؛ Real WP Acceptance `35198192940` = success (prefixهای `clinic_` و `wp_`). تمام ۱۹ check-run روی همین SHA = success.
- **صداقت شواهد:** اجرای محلی PHPUnit/PHPStan/WPCS = **NOT RUN** (PHP/Composer/MySQL در sandbox نیستند). Tenant Tripwire محلی = **PASS** (python3 موجود بود: `production tenant hardcode = 0`). `git diff --check` محلی = سبز. تست مستقیم nonce/CSRF در لایهٔ `save()` (که redirect/exit می‌کند) با زیرساخت موجود Integration ممکن نیست — همان الگوی مستقر StaffManagementPage؛ مرز خالص post-nonce (`upsertLocation`) تست شده است. fault-injection برای «query failure → fail-closed» تست مستقیم ندارد؛ به‌صورت code-level (`assertNoSqlError` + نگاشت RuntimeException→QUERY_FAILED) برقرار است. تغییر ci.yml صرفاً selection شواهد بود (نه رفتار گیت) و در PR مستند شده است.
- **Git/PR:** دو کامیت forward-only (`0fda84b`، `a993cee`) + همین لاگ؛ فقط به `arena/01a0ae2c-doctor` push شد. PR #65 = **OPEN + DRAFT** (بدون Ready/merge/close/delete branch/tag/release/force-push/reset/clean/rebase)؛ main دست‌نخورده. این slice «final technical slice candidate» فاز ۴ است؛ تصمیم نهایی پذیرش/ادامه با مالک است.


### [2026-09-17 13:15:50 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0aec1-doctor`) — Phase 4 documentation closure reconciliation
- فاز/محدوده: فقط آشتی‌دهیِ مستندات کانونی وضعیت Phase 4؛ بدون بازگشاییِ تحلیل فنی و بدون شروع Phase 5.
- **LIVE PIN:** `origin/main = 70ace204d1524a8f5e83d33c67c1a09b7543e7a3`، open PRs = `0`، آخرین migration = `0020`، شاخهٔ نشست = `arena/01a0aec1-doctor`.
- اقدامات: roadmap، current-state، taxonomy، drift register، README و جدول جاری همین راهنما به‌روز شدند؛ closure فقط به PRهای merge‌شدهٔ **#59–#65** و دامنهٔ bounded آن‌ها ارجاع می‌دهد. `clinic.phone` stale Patient Portal consumer، ServiceOffering، تصمیم‌های specialty/geography، و M-01..M-05 به‌عنوان fixed/implemented ادعا نشدند؛ Location timezone و membership cross-Clinic بدون تغییر تصمیمی حفظ شدند.
- کامیت‌ها: **PENDING — SHA در گزارش نهایی ثبت می‌شود**.
- CI: اجرای محلی PHPUnit/PHPStan/WPCS **NOT RUN**؛ exact-head checks تا پس از ساخت Draft PR **NOT RETRIEVED**.
- تصمیمات درون‌فازی: Phase 4 = **CLOSED / TECHNICALLY COMPLETE (BOUNDED)**؛ Phase 3 = **COMPLETED / FROZEN**؛ Phase 5 = **NOT STARTED**؛ هیچ migration (از جمله `0021`) ساخته/تغییر/رزرو نشد.
- موارد باز/تحویل به ایجنت بعد: deferred/open Master Data drift و هر شواهد اجراییِ not retrieved همان‌طور که هست باقی می‌ماند؛ این entry خودش append-only است.
- وضعیت tree: **در زمان ثبت این entry، منتظر commit**.

### [2026-09-17 18:44 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0afef-doctor`، PR DRAFT) — Phase 5 Slice 1: «ثبت Location فاکتور از Visit و تثبیت رفتارهای مالی موجود»
- **LIVE REVERIFY پیش از کار:** `origin/main = 86cd5ef0479e74a9f1e86f7474196a30e1bc5221` (مطابق انتظار)، open PRs = `0`، آخرین migration = `0020` (بدون 0021)، شاخهٔ نشست = `arena/01a0afef-doctor`، working tree clean. شروع: `START_TEHRAN=2026-09-17 18:44:58 Asia/Tehran`.
- **دامنه:** کوچک‌ترین برش Phase 5 — فقط `invoice.location_id` از `visit.location_id` در زمان صدور فاکتور. Location هرگز از payload گرفته نمی‌شود. `invoiceView()` مقدار ذخیره‌شده را برمی‌گرداند. هیچ migration جدید، تغییر schema، ServiceOffering، pricing per-professional، per-location tariff، `services.location_id` NOT NULL، `is_active` policy، backfill، discount workflow، tax/VAT، multi-currency، prepayment، packages، UI redesign یا broad audit انجام نشد.
- **Invariantهای محافظت‌شده:** ۱) Clinic فقط از trusted server context؛ ۲) payload منبع location_id نیست؛ ۳) invoice.location_id فقط از Visit معتبر؛ ۴) بدون first-row/default/fake Location؛ ۵) cross-Clinic service_id fail-closed؛ ۶) manual unit_price override حفظ؛ ۷) fallback به service.price حفظ؛ ۸) snapshot تاریخی مستقل از service.price بعدی؛ ۹) بدون backfill فاکتور تاریخی؛ ۱۰) بدون سیاست جدید is_active/location_id غیرNULL.
- **تغییرات محصول (FinanceService.php — ۸ سطر اضافه):** `issueInvoice()`: استخراج `location_id` از Visit row (قبلاً `findForUpdate` + `rowBelongsToTrustedClinic` شده) و ارسال به `InvoiceRepository::insert()`؛ `invoiceView()`: بازگرداندن `location_id` (nullable برای فاکتورهای تاریخی). بدون تغییر `InvoiceRepository` (ستون `location_id` از migration 0015 وجود دارد و `$row +=` مقدار صریح را حفظ می‌کند).
- **تست جدید (Phase5InvoiceLocationFromVisitTest.php — ۷ متد):** ۱) سناریوی اصلی: Clinic با ۲ Location، Visit در Location دوم، `invoice.location_id === visit.location_id`؛ ۲) payload location_id نادیده گرفته شود؛ ۳) manual unit_price override snapshot؛ ۴) fallback به service.price؛ ۵) snapshot immutability پس از تغییر service.price؛ ۶) cross-Clinic service_id fail-closed؛ ۷) فاکتور تاریخی NULL backfill نشود.
- **RED/GREEN:** اجرای محلی PHPUnit/PHPStan/WPCS = **NOT RUN** (PHP/Composer/MySQL در sandbox موجود نیستند؛ با `which php composer mysql` تأیید). RED معتبر runtime در CI = **NOT RETRIEVED** (پیش از push). GREEN = **PENDING** تا پس از CI exact-head.
- **Git/PR:** کامیت forward-only `ac9fe35` + همین لاگ؛ فقط به `arena/01a0afef-doctor` push. Draft PR **PENDING**. بدون Ready/merge/close/delete branch/tag/release/force-push/reset/clean/rebase؛ main دست‌نخورده.

### [2026-09-17 19:28 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0b016-doctor`) — Phase 5 documentation closure reconciliation (bounded، فقط مستندات)
- **فاز/محدوده:** فقط آشتی‌دهیِ مستندات کانونی با وضعیت واقعی Phase 5. **بدون هیچ تغییر PHP / test / workflow / migration / schema / configuration؛ بدون ServiceOffering؛ بدون تغییر `u_service_code`؛ بدون `0021`؛ بدون شروع Phase 6؛ بدون جزئیات محصولی جدید.**
- **LIVE REVERIFY پیش از کار:** repo root = `/home/user/doctor` · شاخهٔ نشست = `arena/01a0b016-doctor` · local HEAD = `e063b42` · working tree clean (بدون untracked) · `origin/main = e063b42260cb5ab740acf48dcf73c6c67b11fef6` (مطابق انتظار) · open PRs = `0` · **PR #67 = MERGED** (merge = `e063b42`، 2026-09-17T15:36:54Z؛ head = `fa86e41e`؛ ۳ فایل تغییریافته) · آخرین migration = `0020` · `0021` وجود ندارد. شروع: `START_TEHRAN=2026-09-17 19:28:08 Asia/Tehran`.
- **اقدامات:** `roadmap.md` (سطر Phase 5 + ارجاع سطر Phase 4)، `project-current-state.md` (بلوک checkpoint سرصفحه + جدول §C + سطر «Integrated main checkpoint»)، `drift-register.md` (نُت بستن Phase 5 + سطر O-06 + ردیف نگاشت «Pricing/تعرفه») و همین راهنما (جدول وضعیت بالا + همین entry) به‌روزرسانی شدند؛ در `docs/README.md` و `docs/governance/project-phase-taxonomy.md` فقط ادعای کهنهٔ «Phase 5 شروع نشده» با annotation تاریخی/به‌روزرسانی حداقلی اصلاح شد. هیچ فایل خارج از `docs/` تغییر نکرد.
- **تصمیمات درون‌فازی:** **Phase 5 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** بر پایهٔ PR #67 ادغام‌شده؛ pricing پایه **Clinic-scoped** و کافی برای جریان تأییدشدهٔ فعلی V1؛ manual `unit_price` override و fallback به `service.price` و snapshot آیتم‌های فاکتور حفظ‌شده‌اند؛ `is_active` server-side rejection **قراردادِ اثبات‌شدهٔ محصول نیست**؛ سیاست مالیات/VAT = **REQUIRES LEGAL VERIFICATION**؛ **هیچ migration اضافی برای Phase 5 توجیه نمی‌شود**. **هیچ فرضِ تک‌Clinic/تک‌Location مستند نشد**؛ تعرفهٔ متفاوتِ «همان Service» در Locationهای مختلفِ یک Clinic **الزامِ سختِ اثبات‌شدهٔ V1 نیست** و تعلیق آن بر پایهٔ **نبودِ الزامِ اثبات‌شده** است، نه فرضِ تک‌Location — **پشتیبانی چند Location به‌تعویق نیفتاده است** (معماری مرجع `Organization → Clinic → Location`). در صورت الزامِ تأییدشدهٔ آینده = تصمیمِ scoped جداگانه با بازبینی schema/uniqueness.
- **شواهد PR #67 (محدودیت دائمی — ثبت صادقانه، بدون تبدیل NOT RETRIEVED به PASS):** historical **VALID RED = NOT AVAILABLE** (هیچ workflow-run روی کامیت پیاده‌سازی `ac9fe35abbd3ec4217b5801f581d8316b5217b17` ثبت نشده — `total_count = 0`؛ بنابراین «RED معتبر» در دسترس نیست)؛ **direct named execution proof برای `Phase5InvoiceLocationFromVisitTest` = NOT RETRIEVED** (کامنتِ شواهدِ Integration همان PR فقط suiteهای Phase-3 Slice-6 / Phase-4 Slice-3 / Phase-4 Slice-4 / Phase-4 Location را نام می‌برد؛ شمارش‌های aggregate — ۹۳۴ تست / ۱۲۱۶۶ assertion — شاهدِ اجرای یک تستِ نام‌دار نیستند). exact-head: ۱۹/۱۹ check-run موفق روی `fa86e41e`؛ **post-merge** روی `e063b42`: check-runs = 19/19 success و ۴ workflow-run موفق (CI `35241393668` · Real WP `35241393522` · Pilot/Staging `35241393533` · Closure `35241393493`).
- **کامیت‌ها:** **PENDING — SHA نهایی در گزارش تحویل ثبت می‌شود** (شاخه `arena/01a0b016-doctor`).
- **CI:** اجرای محلی PHPUnit/PHPStan/WPCS = **NOT RUN** (PHP/Composer/MySQL در sandbox موجود نیستند)؛ exact-head checks مربوط به این PR مستندات = تا پیش از ساخت Draft PR **NOT RETRIEVED**.
- **موارد باز/تحویل به ایجنت بعد:** قلمِ بازِ O-06 («تعرفهٔ متفاوت per-Location برای همان Service») تا الزامِ تأییدشدهٔ آینده — **بدون فاز/بدون migration** — باز می‌ماند؛ Phase 6 شروع نشده است؛ entry قبلیِ Phase 5 Slice 1 (خطوط پیشین) **append-only و دست‌نخورده** می‌ماند و «GREEN = PENDING» آن در همین entry با شواهد post-merge فوق **تکمیل** (نه ویرایش) می‌شود.
- **وضعیت tree:** **در زمان ثبت این entry، منتظر commit.**

### [2026-09-18 01:25 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0aebc-doctor`، PR DRAFT) — حفظ دو الزام صریح مالک (documentation-only)
- **فاز/محدوده:** فقط **ثبت ماندگارِ** دو الزام صریح مالک در اسناد کانونی — **بدون هیچ تغییر PHP / test / workflow / migration / schema / UI / config** و **بدون پیاده‌سازی** هیچ‌یک از دو الزام؛ بدون بازطراحی roadmap؛ بدون ایجاد سند جدید.
- **LIVE PIN پیش از کار (بازبینی مستقل):** `origin/main = 7d4a0c1c16a42fb3dc171a35fdd567651f960ed3` (شامل merge **PR #70** — Phase 6 Slice 2) · open PRs = `#71` (DRAFT) · آخرین migration = `0020` (`0021` وجود ندارد) · شاخهٔ نشست = `arena/01a0aebc-doctor` · working tree در شروع = clean. برای پایه‌گذاریِ بدون‌تعارض، `git fetch origin main` + `merge --ff-only` انجام شد (فقط metadata محلی؛ بدون force/rebase/rewrite).
- **الزام ۱ — «مدت نوبت/اسلات قابل‌پیکربندی» (`FR-3.10`، فاز مالک Phase 6):** نمونه‌های الزامی ۱۵/۲۰ دقیقه و سایر مقادیر معتبرِ اپراتور؛ **بدون** تحمیل یک مقدار سراسری بر همهٔ پزشکان/کلینیک‌ها/شعبه‌ها. **شواهد as-built (بازرسی کد):** `cpms_schedule.appointment_duration_min` (`SMALLINT UNSIGNED NOT NULL DEFAULT 20` — Migration `0001`؛ هر ردیفِ برنامه = Clinic+Location+Clinician+روز+شیفت)، اعتبارسنجی **۵..۲۴۰** در `ScheduleService`، فرم wp-admin (`ClinicianAdminPage` فیلد مدت، min 5/max 240) و REST (`POST/PUT /config/schedules`)، مصرف در `SlotsGenerateHandler` و `DurationResolver` (ADR-0017: Override نوبت ← Service ← برنامهٔ پزشک ← پیش‌فرض کلینیک + Snapshot در زمان رزرو). ⇒ **نیازی به معماری «مدت» جدید نیست**؛ باقی‌مانده = UX/پیش‌فرض‌های تجاری (Phase 20).
- **الزام ۲ — «ویزارد راه‌اندازی هدایت‌شدهٔ پس از نصب» (`FR-23.1`/`FR-23.2`، فاز مالک Phase 20):** الزام تجاری مالک برای راه‌اندازی هدایت‌شدهٔ اولین اجرا (نه صرفاً مستندات). **چک‌پوینت الزامی ثبت‌شده:** «Before final commercial Setup Wizard implementation, obtain owner product/UX direction for exact wizard steps.» مراحل/صفحه‌ها/پیش‌فرض‌ها/UX **تأیید نشده‌اند** و نباید اختراع شوند.
- **تفکیک صریح (بدون ادعای تکمیل):** یک **ویزارد فنی** وجود دارد — `CpmsSetupWizard` (`src/Admin/CpmsSetupWizard.php`، slug `cpms-wizard`، cap `cpms_config`، ۱۲ گام، resumable با `setup.current_step`، تست `SetupWizardTest`، مستند در `docs/admin-ux-plan.md` §Chunk B) — که **Organization/Clinic/Location جدید نمی‌سازد** و فقط Clinic موجودِ scope را می‌خواند/به‌روزرسانی می‌کند. ⇒ «ویزارد فنی موجود» **≠** «تکمیل الزام تجاری آنبوردینگ»؛ ادعای تکمیل = **NOT AVAILABLE** (شاهد اجرایی/پذیرش تجاری وجود ندارد).
- **فایل‌های تغییریافته (فقط `docs/`):** `docs/srs/SRS.md` (بردار `FR-3.10` + بخش جدید §3.23 با `FR-23.1`/`FR-23.2`)، `docs/roadmap/roadmap.md` (سطرهای Phase 6 و Phase 20 + نُت حفظ الزامات)، `docs/agent-guide.md` (همین entry).
- **فازبندی:** مدت نوبت → **Phase 6 (Scheduling Engine)**؛ نهایی‌سازی تجاری ویزارد/آنبوردینگ → **Phase 20 (UI/UX & Commercial Release)**. هیچ تغییری در کار فعال فعلی و هیچ migration/`0021` رزرو نشد.
- **CI:** اجرای محلی PHPUnit/PHPStan/WPCS = **NOT RUN** (PHP/Composer/MySQL در sandbox موجود نیست)؛ exact-head checks پس از ساخت Draft PR در گزارش تحویل ثبت می‌شود.
- **موارد باز/تحویل به ایجنت بعد:** پیش از پیاده‌سازی تجاری ویزارد، **جهت‌گیری محصولی/UX مالک** الزامی است (`FR-23.2`)؛ برای مدت نوبت هیچ کار فیچر جدیدی لازم نیست مگر UX آنبوردینگ (Phase 20).
- **وضعیت tree:** در زمان ثبت این entry، تغییرات آمادهٔ commit روی `arena/01a0aebc-doctor`؛ شمارهٔ PR و SHA نهایی در گزارش تحویل ثبت می‌شود.

### [2026-09-18 01:27 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0b130-doctor`، PR #71 DRAFT) — Phase 6 Slice 3: «Location صریح و معتبر برای create برنامه»
- **LIVE REVERIFY پیش از کار:** `origin/main = 7d4a0c1c16a42fb3dc171a35fdd567651f960ed3` (= merge PR #70 / Slice 2)، open PRs = `0` (پیش از ساخت)، آخرین migration = `0020` (بدون 0021)، شاخهٔ نشست = `arena/01a0b130-doctor`.
- **قرارداد هدف:** create برنامه در قرارداد مدرن `location_id` صریح می‌خواهد؛ Location باید واقعی + فعال + متعلق به Clinic معتبرِ Scope باشد (هرگز از payload/ردیف پزشک/Location ارسالی Clinic گرفته نمی‌شود)؛ بیگانه/ناموجود/غیرفعال ⇒ پاریتِ 404 `CLINIC_NOT_FOUND` «محل یافت نشد» (عدم شمارش)؛ نبود فیلد ⇒ 400 `CLINIC_VALIDATION_FAILED` `errors.location_id=required` — هیچ جایگزینی بی‌صدا با Primary/اولی/خانه. قاعدهٔ تکراری در محدودهٔ (Clinic+Location+clinician+weekday) — همان روز هفته در Location دیگر مجاز است؛ **Multi-shift فعال نشد**. view مقدار ذخیره‌شدهٔ `location_id` را برمی‌گرداند؛ Slotها Location و timezone محلیِ آن را به‌ ارث می‌برند.
- **RED (TDD، واقعی در CI — محلی PHP/MySQL نبود):**
  - RED اولیه **run 35276874340** @ `496a97c4` (فقط تست): ۹۵۴ تست — T1–T7 با امضای نقصِ کلاسیک **B** (جایگزینی بی‌صدا Primary 181≠182؛ نبود field در view؛ پیش‌بررسی تکراری Clinic-wide؛ چیدمان ردیف 190≠191؛ نبود fail-closed برای Location بیگانه/ناموجود) + ۲ نقص **D** سمت تست که شناسایی و ثبت شدند: (۱) `$this->TZ_PRIMARY` به‌جای `self::TZ_PRIMARY` در preconditions (error)؛ (۲) dispatchهای هماهنگ‌شدهٔ `RestScheduleTest` بدون `X-CPMS-Clinic-Id` → مرز REST، selectorِ Location بدون selectorِ Clinic را 422 می‌گیرد (`Location cannot establish Clinic.`).
  - **VALID RED پاک: run 35278155692** @ `4c458008` — `Tests: 954, Assertions: 12735, Failures: 7, Errors: 0` — دقیقاً و فقط T1–T7 (B) شکست خوردند؛ bootstrap/fixture/مسیر محصول سالم؛ `testFixtureTopologyPreconditions` و T8 و `RestScheduleTest` و بقیهٔ ۹۴۷ تست سبز.
- **GREEN:**
  - commit محصول **`6a823ed`**: `ScheduleService::create()` (ترتیب: clinician → مشارکت در Clinic معتبر → بازهٔ روز → `location_id` الزامی → `LocationRepository::findActiveForClinic` (real+active+clinic match، پاریت 404) → پیش‌بررسی تکراری Location-aware → درج با `location_id` صریح)؛ `ScheduleRepository::create()` بدون فالبک (guard آشکار — ستون NOT NULL از 0013)؛ `findByClinicianDayInClinic` حذف شد (مرده پس از سوییچ) و `findByClinicianDayInClinicAndLocation` جایگزین شد؛ `scheduleView()` field `location_id` دارد؛ REST: آرگومنت `location_id` اختیاری (اجرای قرارداد در سرویس تا 404-parityِ physician بیگانه حفظ شود)؛ wp-admin: جدول برنامه = ماتریس (روز × محل) فقط با Locationهای Clinic معتبر، ردیف موجود Location ثابت دارد (update جابه‌جا نمی‌کند)، `saveSchedules` جست‌وجو با (clinician, day, clinic, location) و create با `location_id`؛ `App::scheduleService()` LocationRepository تزریق می‌کند.
  - **run 35278862253** @ `6a823ed`: `Tests: 954, Failures: 0, Errors: 1` — تنها error = نقص **D** سمت تست: assertion جدیدِ `testWpAdminScheduleCreateForOwnClinicSucceeds` کلید `location_id` را می‌خواند ولی probe `fetchScheduleRowForClinicianDay` آن ستون را SELECT نمی‌کرد → commit `8c153c1`.
  - **GREEN نهایی: run 35279185117** @ `8c153c16` — terminal summary (completion-guard): **`OK (954 tests, 12771 assertions)`**؛ JUnit: `errors=0 failures=0 skipped=0`. اجرای نام‌دار (کامنت شواهد همان run): `Phase6ScheduleExplicitLocationTest` **9/9** (261 assertion — هر ۹ testcase نام‌شده: preconditions/T1–T8) + سوت‌های رگرسیون: `RestScheduleTest` 8/8 · `Phase4Slice1ProfessionalMultiClinicParticipationTest` 8/8 · `ScheduleRegenerationClinicScopeRedTest` 2/2 · `SchedulingLocationLocalBoundaryRedTest` 9/9 · `SlotsGenerateLocationLocalTemporalRedTest` 2/2. exact-head CI checks روی `8c153c16`: Integration · PHPStan · Tenant Tripwire · Unit (8.1/8.2/8.3/8.4) · WPCS — همه success.
- **محدودیت‌های رعایت‌شده:** بدون migration (0021 ساخته/رزرو نشد)؛ بدون جدول/وابستگی/لایهٔ سرویس جدید؛ `cpms_clinician_locations` الزامِ authorization نشد؛ `PrimaryLocationResolver` برای مسیرهای خواندنیِ Booking (Appointment/VisitRepository) ماند و فقط از create برنامه برداشته شد؛ Phase 20 UI ساخته نشد؛ Phase 7 شروع نشد؛ CHANGELOG طبق عرف ریپو (سطر Phase برای برش‌ها افزوده نمی‌شود) دست‌نخورده.
- **Docs:** `docs/api/api-contract.md` — سطر G1 به‌روزرسانی (location_id الزامی در POST + view + قاعدهٔ تکراری Location-aware)؛ `docs/api/error-codes.md` بدون تغییر (کدهای `CLINIC_VALIDATION_FAILED`/`CLINIC_NOT_FOUND` مستند بودند).
- **Git/PR:** کامیت‌ها forward-only: `496a97c` (تست RED) → `4c45800` (تصحیح D) → `6a823ed` (GREEN محصول) → `8c153c1` (تصحیح D) → همین entry (docs). Draft PR **#71** باز است (draft)؛ بدون Ready/merge/close/delete branch/tag/release/force-push/reset/clean/rebase؛ main دست‌نخورده.
- **تحویل به ایجنت بعد:** برش بعدی Phase 6 = **multi-shift** (هنوز فعال نیست — single-row-per-(Location,weekday) فعلاً قرارداد است)؛ entry قبلی‌ها append-only و دست‌نخورده ماندند.

### [2026-09-18 01:51 Asia/Tehran] — تکمیل entry Phase 6 Slice 3 (تأیید exact-head نهایی)
- **exact head نهایی:** `ca7b484e7092f74429bba11a3f532ba4a6709aee` (کامیت docs = آخرِ entry قبلی) — CI run **`35279505286`** @ همان head: `completed/success`؛ completion-guard **`OK (954 tests, 12771 assertions)`**؛ اجرای نام‌دار (کامنت شواهد 5721802943): `Phase6ScheduleExplicitLocationTest` 9/9 (261 assertion) · `Phase4Slice1ProfessionalMultiClinicParticipationTest` 8/8 · `RestScheduleTest` 8/8 · `ScheduleRegenerationClinicScopeRedTest` 2/2 · `SchedulingLocationLocalBoundaryRedTest` 9/9 · `SlotsGenerateLocationLocalTemporalRedTest` 2/2 — همه 0 error/0 failure.
- **وضعیت پایانی:** Draft PR **#71** (OPEN/draft، head = `ca7b484e`، ۵ کامیت از main جلو) کامل و ready-to-review است؛ بدون merge/Ready/tag/release/force-push. برش بعدی = multi-shift Phase 6.

### [2026-09-18 01:58 Asia/Tehran] — اصلاح entry تکمیلی Phase 6 Slice 3 (head دقیق + سلسلهٔ کامیت‌های docs)
- entryِ «01:51» یک اسنپ‌شات در لحظهٔ نگارش بود و خطِ «exact head نهایی: ca7b484e» را می‌تواند گمراه‌کننده خواند؛ **اصلاح دقیق:**
  - آخرین کامیتِ **حاوی کد = `8c153c1`** (GREEN — run 35279185117).
  - کامیت‌های پس از آن **فقط docs** هستند: `ca7b484` (entry + api-contract) → `b342116` (entry تکمیلی) → `511ac96` (اصلاح timestamp) → همین entry.
  - بازتأیید CI روی headهای docs (همه بدون هیچ تغییر کد): run **35279505286** @ `ca7b484e` — `completed/success`، completion-guard `OK (954 tests, 12771 assertions)`، کامنت شواهد 5721802943؛ run **35281643731** @ `511ac96` — `completed/success`.
- **وضعیت پایانی واقعی:** Draft PR **#71** (OPEN/draft) — head = آخرین کامیتِ docs-only؛ CI روی head هر لحظه سبز است؛ آمادهٔ review؛ بدون merge/Ready/tag/release/force-push. برش بعدی = multi-shift Phase 6.

### [2026-09-18 03:00 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0b1a3-doctor`، Draft PR #73) — Phase 6 Slice 4: «multi-shift با رد قطعی همپوشانی»
- **LIVE REVERIFY پیش از کار:** repo root = `/home/user/doctor` · شاخهٔ نشست = `arena/01a0b1a3-doctor` · local HEAD = `c0867b4` (= `origin/main`) · tree clean · open PRs = `0` · آخرین migration = `0020` (بدون 0021).
- **اصلاح تحلیل مدیر (با شاهد):** مدیر ۲ تست منسوخ نام برده بود؛ بازرسی زنده نشان داد **۳** تست قرارداد قدیمی «یک ردیف در هر روز» را کد می‌کردند — سومی `RestScheduleTest::testScheduleCrudAndSlotGeneration` (بلوک dup با 14:00-18:00 پس از 09:00-12:00 و کامنت «the single-row rule») بود که با همان اصلِ حفظ invariant به‌روزرسانی شد. بقیهٔ تحلیل (u_sched_slot چندشیفته، LIMIT 1 در `ScheduleService::create:108`، نبود ORDER BY، کامنت کهنهٔ handler، گارد admin) عیناً تأیید شد.
- **تأیید ادعای (f) روی ژنراتور زنده:** `SlotGenerator::grid` هر Slot را با `t + step <= endSec` داخل پنجرهٔ شیفت خودش تولید می‌کند ⇒ گرید پایهٔ دو شیفت نامتقاطع هیچ `slot_time` مشترکی ندارد (تعیین‌پذیر بدون ORDER BY). ظرافتِ گزارش‌شده: extraهای `open_override` در سطح (clinician, clinic, date) خوانده و به‌ازای هر ردیف بازتولید می‌شوند — رفتار پیشین، بدون exception در تست‌های جدید، خارج از scope این برش (بدون دست‌کاری query طبق بند f).
- **قرارداد هدف:** چند شیفت نامتقاطع در یک (Clinic, Location, clinician, weekday) مجاز؛ همپوشانی ACTIVEها در create و update با دلیل متمایز `overlapping_shift` (زیر `CLINIC_VALIDATION_FAILED`/400، کلید `start_time,end_time`) رد می‌شود؛ تکرار دقیق start_time همچنان `duplicate_schedule_day`؛ مرز مماس مجاز؛ ردیف غیرفعال مانع نیست و فعال‌سازیِ داخل همپوشانی رد می‌شود؛ update ردیفِ خودش را مستثنا می‌کند؛ Location سطر تغییرناپذیر ماند (خارج از whitelist).
- **تغییرات محصول (بدون migration — آخرین همچنان 0020):** `ScheduleService` (حذف پیش‌بررسی تک‌ردیفه + `assertNoShiftConflict` در create/update با مقایسهٔ بازه‌ای `s1<e2 && s2<e1`)؛ `ScheduleRepository` (یابندهٔ LIMIT 1 حذف و `listByClinicianDayInClinicAndLocation` جایگزین شد — پوشش با کلید 0014)؛ `ClinicianAdminPage::saveSchedules` (تطابق چندردیفه ⇒ خطای صریح «چند برنامه… هیچ تغییری ذخیره نشد» و بدون نوشتن)؛ `SlotsGenerateHandler` (فقط سطر ۱۸: کلید زندهٔ u_slot)؛ `ci.yml` (فقط بلوک additive شواهد Slice 4).
- **RED معتبر در CI:** run **35286496400** @ `bfe7d6e` (فقط تست) — completion-guard: `Tests: 963, Assertions: 12991, Failures: 10` (0 error) — دقیقاً ۱۰ شکست کلاس-B موردانتظار: M1/M2(دلیل اشتباه)/M4/M5/M6/M7/M8 + T4 + بلوک Phase4Slice1 + بلوک RestScheduleTest؛ M3 و preconditions و ۹۵۳ تست دیگر سبز. اجرای محلی = NOT RUN (PHP/MySQL در sandbox نیست)؛ لاگ job = NOT RETRIEVED (محدودیت blob) — شواهد از کامنت‌های PR (5722565341/5722565574) خوانده شد.
- **GREEN:** کامیت محصول (همین برش)؛ نتیجهٔ exact-head پس از CI در گزارش PR ثبت می‌شود. Invariantهای ۱-۸ در بدنهٔ PR #73 صورت و با تست اثبات می‌شوند.
- **Git/PR:** forward-only روی `arena/01a0b1a3-doctor`؛ Draft PR **#73** باز؛ بدون Ready/merge/force-push/reset/rebase. Entryهای قبلی append-only و دست‌نخورده.

### [2026-09-18] — Phase 6 Slice 5 explicit wp-admin schedule-row identity (in progress)
- **LIVE INSPECTION (VERIFIED FACT):** repository root is `/home/user/doctor`; authoritative `main` and local HEAD are `8be47522c8c60a51394659ac987497b9d49c0433`; no open PR was returned; latest migration remains `0020`.
- **CORRECTION (VERIFIED FACT):** the repository’s plugin and test roots are nested under `clinic-practice-management/`, not the repository root. The director’s overwrite claim and Slice-4 multi-row refusal guard were confirmed in `src/Admin/ClinicianAdminPage.php`.
- **TEST-FIRST EVIDENCE:** test-only commit `bd9351e` defines explicit-row edit, render-all-rows/add-row, and foreign/mismatched-row fail-closed contracts. Local execution is **NOT RUN**: PHP and Composer dependencies are unavailable in the sandbox (`php: command not found`, `vendor/bin/phpunit: No such file or directory`), so no valid local RED is claimed.

### [2026-09-18 08:34 Asia/Tehran] — ایجنت Arena (شاخهٔ `arena/01a0b2d4-doctor`، Draft PR #75) — Phase 6 Slice 6: «دامنه‌بندی Location برای استثناهای برنامه»
- **LIVE REVERIFY پیش از کار:** repo root = `/home/user/doctor`؛ شاخهٔ نشست = `arena/01a0b2d4-doctor`؛ local HEAD = `main` = `e388729244b4a5a1a339f5c0212bd466b71b37fa` (merge PR #74 / Slice 5)؛ tree clean؛ open PRs = `0`؛ آخرین migration = `0020` (بدون 0021).
- **قرارداد هدف (از قبل‌موجود در 0015 — هیچ migration جدیدی ساخته/رزرو نشد):** `cpms_schedule_exceptions.location_id` nullable + FK `fk_schedexc_location`؛ `NULL` = همهٔ Locationهای Clinic معتبر، عدد = فقط همان Location. نوشتن: نبود/تهی ⇒ NULL، مقدار ⇒ واقعی+فعال+مالکیت Clinic معتبر وگرنه fail-closed؛ تولید: query استثنا به‌ازای هر ردیف برنامه `(location_id IS NULL OR location_id = <row>)`؛ view: `location_id` nullable؛ wp-admin: selector «همهٔ محل‌ها» + Locationهای فعال Clinic و ستون محل در فهرست؛ REST: آرگومان اختیاری؛ delete و SlotGenerator دست‌نخورده.
- **اصلاح تحلیل مدیر (با شاهد زنده):** (۱) ادعای «REST باید همان پاکت 404 سرویس را برگرداند» نادرست است: در مرز REST، `location_id` **هم‌زمان selector مکانیِ درخواست** است (`RestClinicContext::extractIds` + `TrustedClinicEstablisher::verifiedScope`) و بیگانه/ناموجود/غیرفعال هر سه پیش از رسیدن به سرویس با **یک پاکت یکسان 403 `CLINIC_SCOPE_UNAVAILABLE`** رد می‌شوند (پاریت حفظ می‌شود، افشای وجود نمی‌شود). پیشینهٔ همین رفتار در Slice 3 (`Phase6ScheduleExplicitLocationTest` T5) فقط `not 200` + پاریت را assert می‌کند. نسخهٔ نخستِ assertionهای REST در سوئیت من یک نقص **D** بود (انتظار 404 از مرز) و در `7b4a801` تصحیح شد — قرارداد 404 parity در سطح **سرویس** (و مسیر wp-admin) assert می‌شود. (۲) گیت WPCS روی خطوط >۱۰۰ کاراکتر حساس نیست: PR #74 (Slice 5) ۲۵ خط بالای ۱۰۰ بایت (تا ۴۶۰) افزود و `WPCS (changed code)` = SUCCESS (run `35305875331` / job `105477847882`).
- **RED معتبر (TDD، فقط تست):** commit `248fe90` → CI run **`35308554603`**، job Integration `105485705953` — `FAILURES! Tests: 978, Assertions: 13498, Failures: 9` — دقیقاً ۹ نقص کلاس **B** موردانتظار: R1 (leave گره‌خورده به A2، A1 بسته می‌شد)، R2 (blocked گره‌خورده)، R3 (open_override گره‌خورده)، R5 (Location بیگانه بی‌صدا نادیده + نوشتن ردیف)، R6 (ناموجود)، R7 (غیرفعال)، R8 (نبود `location_id` در view)، R9 (فرم wp-admin بدون selector)، R9-write (انتخاب اپراتور ذخیره نمی‌شد). R4/R10/`testFixtureTopologyPreconditions`/قرارداد delete در همین run سبز بودند (نیمهٔ no-regression). لاگ job/artifact = **NOT RETRIEVED** (blob → EOF، محدودیت کلاس C) — شواهد از کامنت‌های PR #75 خوانده شد.
- **GREEN نهایی:** commit محصول **`9424937`** (۵ فایل، +۱۴۵/−۱۲) — `ScheduleService` (هلپر `optionalLocationIdForTrustedClinic` + درج + op-log + `exceptionView` با `location_id` nullable)، `ScheduleRepository` (`EXCEPTION_CREATE_FIELDS` += `location_id`)، `SlotsGenerateHandler` (SELECT دامنه‌مند + `(location_id IS NULL OR location_id = %d)`؛ `SlotGenerator` دست‌نخورده)، `ClinicianAdminPage` (selector + ستون محل + forward در handler)، `ScheduleController` (آرگومان اختیاری integer با اعمال قرارداد در Service). سپس commit **`7b4a801`** (تصحیح D تست + بلوک additive شواهد JUnit).
  - run میانی `35308906265` @ `9424937`: `Tests: 978, Assertions: 13539, Failures: 3` — هر سه، همان نقص D سمت تست (نه محصول): ۴۰۳ مرز در برابر انتظار ۴۰۴.
  - **run نهایی CI `35309183691` @ `7b4a801` = completed success**؛ completion-guard: **`OK (978 tests, 13564 assertions)`**؛ JUnit root: `errors=0 warnings=0 failures=0 skipped=0`. شواهد نام‌دار (کامنت PR همان run): سوئیت جدید `Phase6ScheduleExceptionLocationTest` **13 تست / 452 assertion / 0 failure** (هر ۱۳ testcase نام‌شده) + رگرسیون‌ها همه ۰ خطا: `C7PreIntegrationBoundaryTest` 22/359 · `C7ScheduleObjectIdIsolationTest` 3/42 · `Phase4Slice1ProfessionalMultiClinicParticipationTest` 8/228 · `Phase6ScheduleExplicitLocationTest` 9/264 · `Phase6ScheduleMultiShiftTest` 11/326 · `RestScheduleTest` 8/67 · `ScheduleRegenerationClinicScopeRedTest` 2/41 · `SchedulingLocationLocalBoundaryRedTest` 9/295 · `SlotsGenerateLocationLocalTemporalRedTest` 2/82 · `SlotsGenerateM2WiringRedTest` 1/43. سایر jobهای همان run: Tenant Tripwire · Unit 8.1/8.2/8.3/8.4 · PHPStan · WPCS — همه success.
- **Indexability (کد، بدون اجرای EXPLAIN):** query استثنا همچنان با prefix `(clinician_id, date)` روی `idx_sched_exc (clinician_id,date,type)` می‌رود؛ `clinic_id` از قبل residual filter بود و شرط جدید `(location_id IS NULL OR location_id = ?)` روی همان مجموعهٔ باریک‌شده ارزیابی می‌شود ⇒ مسیر دسترسی تغییر نمی‌کند و **index جدید توجیه ندارد**. EXPLAIN واقعی = NOT RUN (MySQL در sandbox نیست).
- **محدودیت‌ها:** بدون migration/schema/جدول/ایندکس/سرویس/وابستگی جدید؛ بدون تغییر قرارداد Slices 3–5، کلیدهای uniqueness، pricing/booking/visit؛ `SlotGenerator` بدون تغییر معنایی؛ `listExceptions()` (Clinic-scoped) دست‌نخورده؛ `docs/api/api-contract.md` عمداً دست‌نخورده ماند (خارج از فهرست مجاز این برش) — به‌عنوان follow-up مستند شد؛ تست‌ها خارج از scope گیت WPCS.
- **Git/PR:** forward-only: `248fe90` (تست RED) → `9424937` (GREEN محصول) → `7b4a801` (تصحیح D + شواهد CI) → همین entry (docs). Draft PR **#75** باز است (draft؛ بدون Ready/merge/force-push/reset/rebase/tag/release). Entryهای قبلی append-only و دست‌نخورده.
- **تحویل به ایجنت بعد:** برش بعدی Phase 6 = دامنهٔ Location برای سایر مصرف‌کننده‌های `location_id` که هنوز NULL-محورند (نمونه: `services`/`invoices`/`payments`/`settings` که در 0015 ستون گرفتند ولی هیچ‌کدام هنوز خوانده نمی‌شوند) — و در صورت لزوم هم‌راستاسازی پیام مرز REST با پاکت سرویس (تصمیم محصولی، خارج از این برش).
