# سیستم مدیریت مطب — Clinical Practice Management System (CPMS)

مستندات معماری و نیازمندی‌ها — نسخه 2.0 — 2026-09-08

> # 🔴 مرجع فازبندی اجرایی
>
> ## **Owner-approved Phase 0..20 Roadmap = authoritative execution roadmap.**
>
> مرجع رسمی: [`roadmap/roadmap.md`](roadmap/roadmap.md) **§۰**.
> نظام‌های **`F0..F10`**، **`Doc-Phase 1..8`** (جدول همین صفحه) و برچسب‌های **`V1`/`V1.5`/`V2`** **Legacy/Historical** هستند و **Roadmap اجرایی فعلی نیستند**.
>
> **معماری هدف الزام‌آور — [ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md):**
> `Organization → Clinic → Location → Doctor → User/Staff`
>
> **تعارض شناخته‌شدهٔ اسناد با واقعیت:** [`drift-register.md`](drift-register.md)

> **🤖 راهنمای ایجنت‌ها (الزام شروع کار):** هر ایجنت (AI یا انسان) پیش از هر کاری [`agent-guide.md`](agent-guide.md) را کامل بخواند — وضعیت فازها، قواعد الزامی کارفرما، الگوهای کد، دام‌های شناخته‌شده، فازهای باقی‌مانده و **پروتکل لاگ کار ایجنت‌ها (§9–10: هر ایجنت ورودی خود را append می‌کند)**.

> **وضعیت تاریخی (2026-09-08 — snapshot):** همهٔ فازهای تاریخی `F1..F10` + Pilot/Staging Gate + Closure Gate + Remediation انجام و merge شده‌اند (۹ PR؛ آخرین = #9 MERGED). نسخهٔ منتشرشده `1.0.0`.
> **وضعیت جاری (2026-09-11):** مرجع معتبر = [`project-current-state.md`](project-current-state.md). خلاصه: **Phase 0 / 0.5 = CLOSED** · **Phase 1A = CLOSED · Phase 1B = DEFERRED** · **Phase 2 (Multi-Clinic Core) = IN PROGRESS** (C4/C5/C6 CLOSED؛ **C7 = CLOSED** — PR #20 MERGED، merge = `a385d868`؛ **پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11**؛ فقط دامنهٔ تعریف‌شدهٔ C7) · **C8 = CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location (بدون پیاده‌سازی)** · **C9/C10 = NOT STARTED و مجاز نشده** (C9 فقط با تعیین scope/شواهد محدودشده) · **Phase 3 = NOT STARTED** · آخرین migration = `0020` (‏`0021` ساخته/تصویب نشده).

> **🎯 تصمیم محصول (ADR-0027، 2026-09-06):** **یک محصول واحد چندپزشکی** — مطب تک‌پزشکی = زیرمجموعه UX درمانگاه چندپزشکی؛ یک Core/یک Schema/Features تطبیقی. **این اصل معتبر است.**
>
> ⚠️ **اما مدل دامنهٔ ADR-0027 با [ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md) جایگزین شد** (لایهٔ Organization نداشت؛ «Branch» هرگز به schema نرسید). واژهٔ «Branch» از این پس = **Location**.
>
> ⛔ **`architecture/multi-doctor-readiness-review.md` و حکم «۰ FOUNDATIONAL» آن با شواهد اجرایی Phase 0 رد شد** — ۹ قید معماری C-1..C-9 در [`phase-reports/report-phase-0-reverification.md`](phase-reports/report-phase-0-reverification.md). آن سند تاریخی است.

## اسناد مرجع فعلی (بالاترین تقدم)

| سند | نقش |
|---|---|
| [`project-current-state.md`](project-current-state.md) | **وضعیت جاری** + checkpoint ادغام‌شده + بازیابی بدون تاریخچهٔ چت |
| [`roadmap/roadmap.md`](roadmap/roadmap.md) §۰ | **Owner-approved Phase 0..20** — مرجع فازبندی اجرایی |
| [`adr/ADR-0031`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md) | معماری مرجع + ۱۳ تصمیم AD-01..AD-13 |
| [`phase-reports/report-phase-0-reverification.md`](phase-reports/report-phase-0-reverification.md) | خط پایهٔ اثبات‌شده + ۹ قید معماری C-1..C-9 |
| [`architecture/phase0.5-target-model.md`](architecture/phase0.5-target-model.md) | مدل هدف، ERD، برنامهٔ Migration، Decision Register (Q1..Q13) |
| [`drift-register.md`](drift-register.md) | تعارض اسناد با معماری هدف + فاز مالک هر مورد |
| [`agent-guide.md`](agent-guide.md) | راهنمای عملیاتی ایجنت‌ها + قواعد الزامی |

---

## شاخه‌بندی مستندات (Doc-Phase 1..8 — نظام مستندسازی، **Legacy**)

> ⛔ **هشدار شماره‌گذاری:** ستون «فاز» در جدول زیر به **Doc-Phase** اشاره دارد — فازهای *مستندسازی* مطابق Section 56 Master Prompt. **هیچ ربطی به Phase 0..20 اجرایی ندارد.** «Doc-Phase 2» (Permission Matrix) ≠ «Phase 2» (Multi-Clinic Core).
>
> ⚠️ ستون «وضعیت» (`منتظر تأیید`) از مخزن قابل راستی‌آزمایی نیست — **OPEN DECISION (OD-4)** در Drift Register.

| مسیر | محتوا | Doc-Phase | وضعیت |
|---|---|---|---|
| [srs/SRS.md](srs/SRS.md) | نیازمندی‌های نرم‌افزار، شماره‌گذاری‌شده | 1 | منتظر تأیید |
| [srs/use-cases.md](srs/use-cases.md) | فهرست Use Case | 1 | منتظر تأیید |
| [permissions/permission-matrix.md](permissions/permission-matrix.md) | ماتریس دسترسی + مدل Capability | 2 | منتظر تأیید |
| [state-machines/appointment.md](state-machines/appointment.md) | State Machine نوبت | 3 | منتظر تأیید |
| [state-machines/visit-queue.md](state-machines/visit-queue.md) | State Machine مراجعه/صف | 3 | منتظر تأیید |
| [state-machines/payment.md](state-machines/payment.md) | State Machine مالی | 3 | منتظر تأیید |
| [erd/erd.md](erd/erd.md) | ERD کامل + تصمیمات ساختاری | 4 | منتظر تأیید |
| [erd/data-dictionary.md](erd/data-dictionary.md) | Data Dictionary + Indexes/Constraints | 4 | منتظر تأیید |
| [api/api-contract.md](api/api-contract.md) | قرارداد REST API | 5 | منتظر تأیید |
| [security/auth-authorization.md](security/auth-authorization.md) | طراحی احراز هویت/مجوز + OTP | 5/7 | منتظر تأیید |
| [wireframes/patient.md](wireframes/patient.md) | وایرفریم بیمار (Mobile-First) | 6 | منتظر تأیید |
| [wireframes/secretary.md](wireframes/secretary.md) | وایرفریم منشی | 6 | منتظر تأیید |
| [wireframes/doctor.md](wireframes/doctor.md) | وایرفریم پزشک (Tablet/Pen) | 6 | منتظر تأیید |
| [security/threat-model.md](security/threat-model.md) | Threat Model (STRIDE) | 7 | منتظر تأیید |
| [security/audit-strategy.md](security/audit-strategy.md) | استراتژی Audit Log | 7 | منتظر تأیید |
| [architecture/file-storage.md](architecture/file-storage.md) | استراتژی نگهداری فایل | 7 | منتظر تأیید |
| [architecture/handwriting-storage.md](architecture/handwriting-storage.md) | معماری ذخیره دست‌خط | 7 | منتظر تأیید |
| [architecture/handwriting-recognition.md](architecture/handwriting-recognition.md) | معماری تشخیص دست‌خط | 7 | منتظر تأیید |
| [architecture/notifications.md](architecture/notifications.md) | معماری اعلان | 7 | منتظر تأیید |
| [architecture/multi-doctor-readiness-review.md](architecture/multi-doctor-readiness-review.md) | بازبینی آمادگی چندپزشکی (ADR-0027) + Backlog هم‌ترازی | — | ⛔ **Historical** — حکم «۰ FOUNDATIONAL» با Phase 0 رد شد |
| [architecture/phase0.5-target-model.md](architecture/phase0.5-target-model.md) | مدل هدف + ERD + Migration Plan + Decision Register | — | ✅ Finalized |
| [phase-reports/report-phase-0-reverification.md](phase-reports/report-phase-0-reverification.md) | گزارش Phase 0 + Re-verification + ۹ قید معماری | — | ✅ CLOSED |
| [drift-register.md](drift-register.md) | تعارض اسناد با معماری هدف + فاز مالک | — | ✅ فعال |
| [architecture/background-jobs.md](architecture/background-jobs.md) | معماری Background Jobs | 7 | منتظر تأیید |
| [backup/backup-recovery.md](backup/backup-recovery.md) | برنامه Backup/DR | 7 | منتظر تأیید |
| [testing/testing-plan.md](testing/testing-plan.md) | برنامه تست | 8 | منتظر تأیید |
| [scope/mvp-scope.md](scope/mvp-scope.md) | محدوده MVP/V1 | 8 | منتظر تأیید |
| [roadmap/roadmap.md](roadmap/roadmap.md) | نقشه راه توسعه | 8 | منتظر تأیید |
| [adr/](adr/) | Architecture Decision Records | 1-8 | Accepted (قابل بازبینی) |
| [phase-reports/report-phase1-8.md](phase-reports/report-phase1-8.md) | گزارش فاز + تصمیمات + ریسک‌ها + آیتم‌های تصمیم‌گیری کارفرما | — | — |

## قوانین

- هیچ کد اصلی بدون تأیید شش سند بالادستی (SRS، Permission، State Machines، ERD، API، Wireframes) شروع نمی‌شود.
  > تصحیح شماره‌گذاری: عبارت قبلی «Phase 9+» بود که به **Doc-Phase** اشاره داشت، نه به Phase 9 در Roadmap تأییدشدهٔ Owner.
- **قاعدهٔ اجرا از Phase 1 به بعد:** code + tests + documentation **همراه هم** به‌روز می‌شوند. checkpoint commit کوچک مجاز؛ **merge فقط پس از Gate نهایی فاز**.
- **اسناد تاریخی rewrite نمی‌شوند** — ADR تاریخی **Superseded** می‌شود، نه پاک.
- هر تغییر فاز قبل: Impact Analysis + Version مجدد مستند.
- کدگذاری نیازمندی‌ها: `FR-x.y` (عملکردی)، `NFR-x` (غیرعملکردی)، `ER-x` (Edge Case)، `UC-x` (Use Case).
