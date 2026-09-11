# نقشه راه توسعه — CPMS

نسخه 2.0 | 2026-09-08

> ## 🔴 قانون بنیادین فازبندی
>
> **تنها نظام رسمی فازبندی اجرایی این پروژه = Owner-approved Phase 0..20 Roadmap (بخش ۰ همین سند).**
>
> نظام‌های قدیمی **`F0..F10`** و **`Doc-Phase 1..8`** و برچسب‌های **`V1` / `V1.5` / `V2`** از 2026-09-08 **Legacy/Historical** هستند. آن‌ها فقط برای traceability تاریخی نگه داشته شده‌اند و **هیچ ایجنتی (AI یا انسان) حق ندارد آن‌ها را به‌عنوان Roadmap اجرایی فعلی تفسیر کند.** نگاشتشان در بخش ۲ آمده است.
>
> **قانون Gate:** هر فاز فقط بعد از تأیید صریح Product Owner روی خروجی + Acceptance Criteria فاز قبل شروع می‌شود.
>
> **مرجع تفسیر فازها (Governance):** [`docs/governance/project-phase-taxonomy.md`](../governance/project-phase-taxonomy.md) —
> سلسله‌مراتب سامانه‌های فاز (Owner Phase 0..20 = authoritative؛ زیرفازهای C* داخل Phase 2؛
> سامانه‌های Legacy/تاریخی) + crosswalk + قواعد نام‌گذاری. عبارتِ تنها «Phase N» فقط به همین Roadmap اشاره دارد.

---

## ۰. Owner-Approved Execution Roadmap — Phase 0..20 (AUTHORITATIVE)

| Phase | عنوان | وضعیت |
|---|---|---|
| **Phase 0** | **Git / Project Stabilization** | ✅ **CLOSED** — [`report-phase-0-reverification.md`](../phase-reports/report-phase-0-reverification.md) (۹ قید C-1..C-9) |
| **Phase 0.5** | **Target Architecture & Migration Plan** *(فاز میانی مستندسازی، خارج از شماره‌گذاری اصلی)* | ✅ **CLOSED / APPROVED** — [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md) · [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) |
| **Phase 1** | **Security Hardening** | 🚧 **IN PROGRESS** — **Phase 1A ✅ CLOSED at `9bc6f7f`** (تأیید مالک؛ همهٔ Gateها سبز) · **OD-9 ✅ CLOSED** — مرز ذخیره‌سازی بکاپ (Fail-Closed + مبدأ legacy فقط‌خواندنی + هدایت Safety Backup؛ [`report-od9-closure.md`](../phase-reports/report-od9-closure.md)) · **Phase 1B 🕒 DEFERRED** تا Multi-Clinic Scope. تقسیم اجرایی در §۰-۲ · تحویل: [`phase1a-to-next-agent.md`](../handoff/phase1a-to-next-agent.md) |
| **Phase 2** | **Multi-Clinic Core** | 🚧 **IN PROGRESS** — **C4/C5/C6 ✅ CLOSED** (ادغام در `origin/main` = `099b644`؛ PR #14 MERGED؛ اصلاحیهٔ پس از بستنِ C6 در `248ca10`؛ PR #17 MERGED) · **C7 ✅ CLOSED** (ترمیم S1..S6؛ PR #20 MERGED 2026-09-11؛ merge = `a385d868`؛ **پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11** — فقط دامنهٔ تعریف‌شدهٔ C7) · **C8 ✅ CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location** (بدون پیاده‌سازی؛ جغرافیای ایران ⇒ Phase 4؛ مجوزدهی نهایی ⇒ Phase 3) · **C9/C10 🕒 NOT STARTED — مجاز نشده** (C9 فقط با تعیین scope/شواهد محدودشده) · وضعیت جاری: [`project-current-state.md`](../project-current-state.md) |
| **Phase 3** | **Role & Access Control** | ⏳ |
| **Phase 4** | **Master Data** | ⏳ |
| **Phase 5** | **Pricing Engine** | ⏳ |
| **Phase 6** | **Scheduling Engine** | ⏳ |
| **Phase 7** | **Appointment Engine** | ⏳ |
| **Phase 8** | **Patient Public Booking** | ⏳ |
| **Phase 9** | **Patient Portal** | ⏳ |
| **Phase 10** | **Doctor Portal** | ⏳ |
| **Phase 11** | **Clinic Reception** | ⏳ |
| **Phase 12** | **Finance Architecture** | ⏳ |
| **Phase 13** | **Prescription & Documents** | ⏳ |
| **Phase 14** | **Reporting** | ⏳ |
| **Phase 15** | **Backup & Recovery** | ⏳ |
| **Phase 16** | **License & Commercial Engine** | ⏳ |
| **Phase 17** | **Performance** | ⏳ |
| **Phase 18** | **Compatibility** | ⏳ |
| **Phase 19** | **Automated Testing** | ⏳ |
| **Phase 20** | **UI/UX & Commercial Release** | ⏳ |

> ✅ **OD-1 بسته شد (2026-09-08).** Product Owner هر ۲۱ فاز را تصریح کرد. **این sequence توسط هیچ taxonomy‌ای از نظام‌های Legacy (`F0..F10`، `Doc-Phase`، `V1/V1.5/V2`) قابل تغییر نیست.**

### §۰-۲ — تقسیم اجرایی Phase 1

| زیرفاز | دامنه | وابستگی |
|---|---|---|
| **Phase 1A** ✅ | امنیت **مستقل** از Multi-Clinic Scope | ندارد — همین حالا قابل اجرا |
| **Phase 1B** | **Scoped / Object Authorization** | وابسته به Phase 2 و Phase 3 |

> ⚠️ این تقسیم **شماره یا هدف Roadmap را تغییر نمی‌دهد** — Phase 1 همچنان یک فاز است.
> 🔴 **Phase 1B نباید با معماری موقت `clinic_id = 1` پیاده‌سازی شود.** هر قلمی که برای مجوزدهی واقعی به Clinic/Patient scope نیاز دارد، در **Phase 1B Deferred Register** ثبت می‌شود و تا Phase 2/3 صبر می‌کند. **ساخت bypass موقت ممنوع است.**

### معماری هدف الزام‌آور (ADR-0031)

```
Organization → Clinic → Location → Doctor → User/Staff
```

۱۳ تصمیم معماری تصویب‌شده (AD-01..AD-13) در [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) و §۰ سند [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md) ثبت شده‌اند. سه مورد پرتکرارترین:

- **AD-02** — Organization اجباری است؛ هیچ مسیر موازی برای `Organization = NULL`.
- **AD-12** — Migration = versioned forward؛ drop/recreate مسیر محصول نیست.
- **AD-13** — `clinic_id = 1` مستقیم در کد جدید ممنوع است.

### نگاشت قیدهای Phase 0 به فاز مالک

| قید | موضوع | فاز مالک |
|---|---|---|
| C-6 | الگوی authorization دیرهنگام (`permission_callback`) | **Phase 1** |
| C-9 | Scope جداول `otp_tokens` / `jobs` / `rate_limits` / `operational_logs` | **Phase 1** |
| C-1, C-2, C-3, C-4, C-7, C-8 | Organization / Location / ClinicContext / hardcode / UNIQUE / FK | **Phase 2** |
| C-5 | Staff/Permission بدون Clinic scope | **Phase 3** |

### قاعدهٔ اجرا از Phase 1 به بعد

- **code + tests + documentation باید همراه هم به‌روز شوند.** تغییر معماری بدون سند و تست متناظر = ناقص.
- checkpoint commitهای کوچک و موضوعی مجازند.
- **Merge فقط پس از Gate نهایی هر فاز** — نه وسط کار، نه «برای جلوگیری از وقفه».
- اسناد تاریخی rewrite نمی‌شوند؛ ADR تاریخی **Superseded** می‌شود، نه پاک.
- آنچه امروز لازم نیست، پیاده نمی‌شود.

---

## ۱. Drift Register

فهرست اسنادی که هنوز مدل قدیمی را بیان می‌کنند و اصلاحشان به فاز مالکشان موکول شده است:
👉 [`docs/drift-register.md`](../drift-register.md)

---

## ۲. نظام‌های Legacy (Historical — فقط برای traceability)

> ⛔ **این بخش Roadmap اجرایی نیست.** صرفاً ثبت می‌کند که کار تاریخی پروژه تحت چه برچسب‌هایی انجام شده تا گزارش‌ها و ADRهای قدیمی قابل ردیابی بمانند.

### ۲-۱. نگاشت Legacy → Owner-approved Phase

| برچسب Legacy | معنی تاریخی | وضعیت | نگاشت به نظام رسمی |
|---|---|---|---|
| `F0`..`F10` | فازهای ساخت محصول تا V1 + Go-Live | ✅ همه انجام و merge شده‌اند | کار **پیش از Phase 0** — خط پایه‌ای که Phase 0 آن را ممیزی کرد |
| `Doc-Phase 1..8` | فازهای *مستندسازی* در `docs/README.md` (SRS، Permission، State Machines، ERD، API، Wireframes، Security، Testing/Scope/Roadmap) | ✅ اسناد نوشته شده‌اند | نظام مستندسازی — **هیچ ربطی به Phase 0..20 اجرایی ندارد** |
| `V1` | دامنهٔ انتشار اولیه (`1.0.0`) | ✅ منتشر شد | تاریخی |
| `V1.5` | OCR، 2FA، Merge UI، ClamAV/Encryption، UX حالت مطب | ⏸ معلق | باید توسط Owner به Phase مناسب نگاشت شود — **OPEN DECISION (OD-2)** |
| `V2` | Role Management، Scope، Multi-clinic، Branch، Department/Room، Specialty، Staff Assignments، Online Payment، Insurance، Mobile API | ⛔ **منحل شد** | 🔴 محتوایش بازتوزیع شد: **Multi-clinic/Branch → Phase 2** · **Role/Scope/Staff → Phase 3** · **Specialty/Department/Room → Phase 4 (Master Data)** · بقیه **OPEN DECISION (OD-2)** |

> 🔴 **مهم‌ترین Drift رفع‌شده:** برچسب `V2` در اسناد قدیمی، **Multi-Clinic** را به آینده‌ای نامعلوم موکول می‌کرد. در Roadmap تأییدشدهٔ Owner، Multi-Clinic Core **دومین فاز اجرایی (Phase 2)** است — بلافاصله پس از Security Hardening.
>
> ⚠️ **OPEN DECISION (OD-2):** اقلام `V1.5` و بخش‌های بازتوزیع‌نشدهٔ `V2` (Online Payment، Insurance/Lab، Push، Mobile API/JWT، OCR، 2FA) هنوز به هیچ Phase از ۰..۲۰ نگاشت نشده‌اند. تا تصریح Owner، **بدون فاز** می‌مانند.

### ۲-۲. جدول تاریخی فازهای F (بدون تغییر — سند تاریخی)

پیش‌فرض تاریخی این جدول: تیم ۲ نفره (۱ PHP/WP Senior + ۱ Frontend)؛ زمان‌بندی تقریبی.

> **هشدار خواندن:** ستون «تخمین» و ارجاع‌های `V1.5`/`V2` در جدول زیر **منسوخ**اند. متن دست‌نخورده باقی مانده تا تاریخچه حفظ شود.

> **تصمیم محصول (ADR-0027، 2026-09-06) — ⚠️ مدل دامنه‌اش با ADR-0031 جایگزین شد:** **یک محصول واحد چندپزشکی** — مطب تک‌پزشکی = زیرمجموعه UX درمانگاه چندپزشکی؛ یک Core/یک Schema/Features تطبیقی. بازبینی آمادگی معماری: `docs/architecture/multi-doctor-readiness-review.md` — ⛔ **حکم «۰ FOUNDATIONAL» آن سند با شواهد اجرایی Phase 0 (قیدهای C-1..C-9) رد شد؛ سند تاریخی است.**

### جدول فازهای F (تاریخی)

| فاز | محتوا | خروجی/DoD | تخمین |
|---|---|---|---|
| **F0** (حاضر) | مستندات Phase 1–8 (این بسته) | تأیید کارفرما | 1 هفته (مسلم) |
| **F1** | Core Architecture: Skeleton افزونه، لایه‌ها، Migration System + مigrations اولیه، Roles/Capabilities، Settings، Audit/Operational Base، Rate Limit/Idempotency Middleware، Job Queue + Dispatcher، CI | تست Migration + Audit + Queue سبز | 2 هفته |
| **F2** ✅ | احراز هویت: OTP کامل، Session/Security، Patient User Links، Rate Limit (157/232 سبز؛ + 4 تصمیم کارفرما D1–D4) | TP-04, TP-05, TP-17 | 1.5 هفته |
| **F2.5** ✅ | ماژول پیامک Provider-Agnostic (ADR-0025 — تأییدشده): Settings/Templates/Test/Log/Balance + Generic API (SSRF) + Vault + Queue SMS (187/346 سبز) | SmsFlowTest (CI) + 30 تست Unit | 1 هفته |
| **F3** ✅ کامل شد — CI سبز (گزارش: [report-f3.md](../phase-reports/report-f3.md)) | نوبت‌دهی: Schedule/Exceptions، Slot generation، Hold/Claim، Booking API (A/B)، Patient Profile CRUD — **Availability UI = تصمیم محصول (گزارش §5-1)** | TP-03, TP-14, TP-15, TP-20 + سناریوی رزرو E2E | 2.5 هفته |
| **F4** ✅ کامل شد — CI سبز (گزارش: [report-f4.md](../phase-reports/report-f4.md)) | مراجعه/صف: Check-in/Walk-in، Queue State Machine + History، Real-time Polling، داشبورد منشی (امروز/Drawer/Walk-in/Keyboard) | TP-19 + TP-03b + TP-07 | 2.5 هفته |
| **F5** | بالینی: صفحه ویزیت، Notes+Versions، Prescriptions، Recommendations، Follow-ups، Complete/Reopen، File Upload/Stream، داشبورد پزشک (امروز/صف/Call) | TP-06, TP-08, TP-10 | 3 هفته |
| **F6** ✅ کامل شد — CI سبز (گزارش: [report-f6.md](../phase-reports/report-f6.md)) | مالی: Services، Invoice/Payment/Adjustment/Void/Refund، Receipt، داشبورد مالی منشی، Checkout Flow + **ADR-0026** (نقش‌های پویا — تصریح کارفرما) | TP-02, TP-18 + TP-01 (بخش مالی) | 2 هفته |
| **F7** ✅ کامل شد — CI سبز (گزارش: [report-f7.md](../phase-reports/report-f7.md)) | دست‌خط: Canvas کامل (Pressure/Tools/Zoom/Full-screen/Multi-page/Template)، Stroke Storage، Auto-save + **Offline Sync** (IndexedDB) + Conflict | TP-12 | 2.5 هفته |
| **F8** ✅ کامل شد — CI سبز (گزارش: [report-f8.md](../phase-reports/report-f8.md)) | اعلان + گزارش: Notification Layer کامل + Templates (Jalali)، 12 گزارش + Export (Watermark/Audit) — گزارش‌های مالی با تفکیک **Aggregate از Detail** و آماده Scope (ADR-0026) | TP-13 + Report Tests | 1.5 هفته |
| **F9** ✅ کامل شد — CI سبز (گزارش: [report-f9.md](../phase-reports/report-f9.md)) | Hardening: Security Review کامل T-01..T-24 (رفع root cause سه حفره واقعی: Idempotency Replay/In-flight خاموش، گارد مالکیت ویزیت پزشک، UNIQUE wp_user_id با Preflight)، پاک‌سازی سه Job مرده + دو Fatal عملیاتی، Performance NFR-PERF-1 (تأیید پوشش ایندکس؛ Benchmark ران‌تایم = چک‌لیست Pilot طبق متدولوژی baseline)، TP-16 (مسیر ارتقا/Restore تست‌شده + Runbook)، Accessibility Pass (NFR-UI-3)، user-guide Pilot | Security Checklist + TP-16 + DoD V1 | 2 هفته |
| **Pilot/Staging Gate** 🔄 در حال اجرا — ۱۴ run (گزارش زنده: [report-pilot-gate.md](../phase-reports/report-pilot-gate.md)) | اثبات آمادگی V1 در محیط واقعی: Release Artifact clean، Fresh Install، Upgrade F8→RC، Apache/Cron/Storage، Seed Synthetic، ۹ Smoke نقش‌ها، Benchmark، Security، Audit، Backup/Restore Drill با Integrity؛ **یک باگ واقعی تولید کشف و ریشه‌یابی شد** (recurring jobs در مسیر CLI می‌ایستاد → `App::runTick()` واحد + regression test) | چک‌لیست ۲۵ قلم کارفرما + Verdict سه‌حالته | ۱ هفته |
| **V1.5** ⛔ Legacy | OCR (انتخاب Provider + Acceptance Test فارسی)، 2FA، Merge UI، ClamAV/Encryption (تصمیم R-06) + **UX حالت مطب (ADR-0027: Skip خودکار انتخاب پزشک با ۱ Clinician فعال)** | TP-OCR + 2FA Tests | 3–4 هفته |
| **V2** ⛔ **منحل — به Phase 2/3/4 بازتوزیع شد (بخش ۲-۱)** | **Role Management کامل (ADR-0026): نقش‌های سفارشی + UI مدیریت + Scope (OWN/ASSIGNED_DOCTORS/BRANCH/CLINIC) + Audit `ROLE_*`/`PERMISSION_*` + گزارشگری مالی Scope-دار (per-doctor/per-service/Aggregate-تفکیک-از-Detail)**، **قابلیت‌های چندپزشکی کامل (ADR-0027): مدل Specialty (M:N) + Booking «تخصص→خدمت→پزشک» و «اولین پزشک آزاد» + سرویس per-clinician + Staff Assignments/Scope Enforcement (صف/مالی) + Branch + Department/Room + Clinic Manager Dashboard**، Multi-clinic/Team، Online Payment، Insurance/Lab، Push، Mobile API (JWT) | ADR-0026/0027 + تست Escalation/Scope | بر اساس نیاز |

## ۳. مایلستون‌های تاریخی (Legacy)
- **M1 (پایان F3):** بیمار واقعی می‌تواند آنلاین نوبت بگیرد (به‌صورت داخلی).
- **M2 (پایان F5+F6):** چرخه کامل مطب بدون OCR (منشی→پزشک→مالی) — **Pilot داخلی**.
- **M3 (پایان F9):** **Go-Live V1** + بکاپ/DR تست‌شده.

## ۴. ریسک‌های تاریخی برنامه (Legacy)
| ریسک | مهارت |
|---|---|
| دقت OCR فارسی (V1.5) | Provider Selection با Acceptance Test واقعی (R-04) — OCR در V1 نیست |
| دست‌خط روی iPad/Samsung (Pointer/Pressure) | Spike در ابتدای F7 (2 روز) روی 2 دستگاه واقعی |
| SMS Provider نامناسب | Adapter + 2 Provider در F2 (تصمیم R-01) |
| حجم استوری Stroke | ADR-0009 + Load Test نمونه (F7) |
