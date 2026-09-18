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
> **به‌روزرسانی 2026-09-12:** **C10 = CLOSED — پذیرش/بستن رسمی با تصمیم صریح مالک** (بستهٔ شواهد در `origin/main` = `bdb135e9`، PR #25 MERGED؛ فقط دامنهٔ محدودشدهٔ بازبینی شواهد عملکرد — بدون ادعای NFR/بار/مقیاس‌پذیری/آمادگی تجاری؛ **Phase 2 همچنان IN PROGRESS**؛ Phase 3 / Phase 17 NOT STARTED *(🔴 تاریخی — اکنون **Phase 3 = COMPLETED / FROZEN** در `ebf8588`؛ رجوع به «به‌روزرسانی 2026-09-16» بالا)*؛ End Gate فاز ۲ تعریف/اجرا نشده). الزام دائمی توپولوژی‌های استقرار در [ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md) §«الزام دائمی محصول: یک هسته، سه توپولوژی استقرار» ثبت شد.
>
> **به‌روزرسانی 2026-09-16 (Phase 3 End Gate — PASS):** **Phase 2 = COMPLETED (technical)**؛ **Phase 3 — Role & Access Control = COMPLETED / FROZEN** (تکمیل فنی در `origin/main` = `ebf8588f34be1da2ff18a152dea2c8badc472056`، PR #57 MERGED 2026-09-16T16:46:59Z؛ نتیجهٔ End Gate فاز ۳ = **PHASE 3 PASS**). هستهٔ مرکزیٔ `AuthorizationService` با محدودهٔ Clinic تثبیت شد؛ فوندیشن‌‌های محدودشده (مدیریت staff/membership، پیکربندی/secrets SMS، چرخهٔ reports/export، دسترسی clinical/medical-file، Clinician Admin / Handwriting / Finance، Queue / Schedule / staff Booking / staff Patient، تفکیک patient-self/public، مدیر نصب بدون دسترسی بالینی ضمنی، پوششٔ داینامیکٔ چند‌Clinic، و Integration completion guard جلوگیری از false-green زودهنگام) برقرار شدند. پیاده‌سازی فاز ۳ شامل PR‌های #49/#50/#52/#54/#55/#56/#57 (ادغام‌شده در `origin/main`) و مدرک exact-head: ۱۹/۱۹ چک موفق در `ebf8588` است؛ فاقدِ تأیید/ادغام مالک (فقط پذیرش فنی). **این ادعای انتشار/V1/تجاری نیست و فاز ۴ نیست.** آخرین migration = `0020` (‎`0021` ساخته/تصویب نشده — رزرو یا ساخت نشده). **در checkpoint 2026-09-16، Phase 4 = Master Data همچنان NOT STARTED بود** *(historical — superseded by the 2026-09-17 closure update below; that checkpoint made no change to the roadmap).* رجوع به [`project-current-state.md`](project-current-state.md) برای جزئیات.

> **به‌روزرسانی 2026-09-17 (Phase 4 documentation closure reconciliation):** مرجعِ آن چک‌پوینت `origin/main = 70ace204d1524a8f5e83d33c67c1a09b7543e7a3` بود؛ **Phase 4 — Master Data = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** بر پایهٔ slices ادغام‌شدهٔ **PRs #59–#65**. این status فقط دامنهٔ فنیِ mergeشده را ثبت می‌کند و ادعای release/V1/commercial نیست؛ ServiceOffering، Specialty/Department/Room، Iran geography master data، M-01..M-05، یا رفع stale `clinic.phone` در Patient Portal را implemented/fixed اعلام نمی‌کند. Location timezone و مدل membership برای professional چندکلینیکی حفظ شده‌اند. **Phase 5 implementation در آن چک‌پوینت شروع نشده بود و بخشی از آن closure نبود.** *(🔴 تاریخی — superseded توسط «به‌روزرسانی 2026-09-17 (Phase 5 Slice 1 — bounded technical closure)» زیر.)* آخرین migration همچنان `0020` است و `0021` وجود ندارد.

> **به‌روزرسانی 2026-09-17 (Phase 5 Slice 1 — bounded technical closure):** مرجعِ آن چک‌پوینت `origin/main = e063b42260cb5ab740acf48dcf73c6c67b11fef6` بود (PR #67 MERGED 2026-09-17T15:36:54Z؛ head = `fa86e41e`؛ ۱۹/۱۹ چکِ exact-head موفق؛ گیت‌های پس‌از‌ادغام روی همان SHA موفق). **Phase 5 — Pricing Engine = CLOSED / TECHNICALLY COMPLETE (BOUNDED)**: pricing پایه **Clinic-scoped** و کافی برای جریان تأییدشدهٔ فعلی V1 است و Slice 1 شکافِ مشخصِ انتساب Location را با استخراج `invoice.location_id` از Visit معتبر بست (هرگز از payload). رفتار موجود V1 حفظ شد (manual `unit_price` override، fallback به `service.price`، snapshot آیتم‌های فاکتور). **ادعا نمی‌شود:** ServiceOffering، تغییر `u_service_code`، تعرفهٔ متفاوت برای *همان* Service در Locationهای مختلفِ یک Clinic، `is_active` server-side rejection به‌عنوان قراردادِ محصول، یا سیاست مالیات/VAT (REQUIRES LEGAL VERIFICATION). **هیچ فرضِ تک‌Clinic/تک‌Location وجود ندارد** — معماری مرجع `Organization → Clinic → Location` است و **پشتیبانی چند Location به‌تعویق نیفتاده**؛ تعلیقِ «تعرفهٔ per-Location برای همان Service» بر پایهٔ **نبودِ الزامِ اثبات‌شدهٔ V1** است، نه فرضِ تک‌Location. آخرین migration همچنان `0020` است و **`0021` وجود ندارد**. محدودیت‌های دائمی شواهد PR #67: historical **VALID RED = NOT AVAILABLE**؛ **direct named execution proof برای `Phase5InvoiceLocationFromVisitTest` = NOT RETRIEVED** (کامنت شواهد Integration همان PR فقط suiteهای Phase-3 Slice-6 / Phase-4 Slice-3 / Slice-4 / Location را نام می‌برد؛ شمارش‌های aggregate شاهدِ اجرای تستِ نام‌دار نیستند).

> **به‌روزرسانی 2026-09-18 (Phase 6 — bounded documentation closure):** مرجع جاری و راستی‌آزمایی‌شدهٔ `origin/main` = **`bd5e6a1819a838648dbdcbc6914c0d8b24bba38b`** است (**PR #76 MERGED 2026-09-18T09:59:44Z**؛ head تصویب‌شده `bd840163b33688d17af51f006aa9dccaea9e8c53`؛ والدین `fc0a598e…` + `bd840163…`؛ open PR در زمان بازبینی = ۰). **Phase 6 — Scheduling Engine = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** بر پایهٔ slices ادغام‌شدهٔ **PRs #69–#71 + #73–#76** (the range also contains the documentation-only **PR #72** — owner-requirement preservation, not a slice). **Phase 6 Slice 7 = CLOSED**: بلاکر همزمانی (TOCTOU) در اجرای چندشیفته با **PR #76** رفع شد؛ **VALID RED پذیرفته‌شده = `fca63d3a21a7918c950d2cf07bf43343fe0c20ef`** (run `35327220676`)؛ دو تلاش قبلیِ RED **همچنان INVALID RED هستند و به‌عنوان RED معتبر بازنویسی نمی‌شوند** (`091fae1c5c59142d7bc4d60aeb2ae8954e52ed28` — `ParseError` و صفر تست اجراشده؛ `c8b9592056307fea8213d131e135131198508674` — شکست‌های غیرمحصولیِ harness همراه با شکستِ positive control). **شواهد نهایی پس‌از‌ادغام روی `bd5e6a18`: هر چهار workflow لازم terminal-success** (CI `35332404611` · Real WP `35332404609` · Pilot/Staging `35332404592` · Closure `35332404644`) و **۱۹/۱۹ check run موفق**. **استثناهای ثبت‌شدهٔ شواهد (دائمی، بدون پنهان‌سازی):** (۱) **استثنای فرآیندی Slice 7 (تاریخی، غیربلاکر)** — ایجنت write قرار بود پس از گرفتن VALID RED متوقف شود اما پیش از مجوز مدیر وارد GREEN شد؛ این نقضِ انضباطِ فرآیند/شواهد ثبت می‌شود و شواهدِ نهاییِ مستقل پذیرفته‌شده را باطل نمی‌کند. (۲) **استثنای تاریخی شواهد RED در Slice 5** — کامیت تست‌تنهای `bd9351e75407ec0449204733d3b5894fd7d33aa8` در همان SHA **هیچ check runی ندارد** (API: `total_count = 0`)؛ RED گذشته‌نگر ساخته نمی‌شود و رفتار نهایی با شواهد پذیرفته‌شدهٔ بعدی پوشش یافته است. (۳) **استثنای تاریخی پس‌از‌ادغام روی main قبلی `fc0a598e739d6e4951de3167bbc4509e0d9c5378`** — ۱۸ موفق + **۱ cancelled** (`Responsive smoke`؛ run `35310058979` = `cancelled`) که **هرگز PASS نبود** و با شواهد موفقِ بعدی روی `bd5e6a18` تضاد ندارد. **پوشش نیازمندی‌ها (حفظ‌شده):** `FR-3.1`، `FR-3.2`، `FR-3.3`، `FR-3.4`، `FR-3.6`، `FR-3.7`، `FR-3.8`، `FR-3.9`، `FR-3.10` — **`FR-3.5` متعلق به Phase 8 است و به‌عنوان پوشش Phase 6 ادعا نمی‌شود.** **hardening به‌تعویق‌افتاده (پیاده‌سازی‌نشده؛ حفظ برای اسلایس hardening برنامه‌ریزی‌شدهٔ C7):** **ج** شاخهٔ legacy بدون Scope در `requireClinicianWithinTrustedClinic()` (deferrable hardening، نه بلاکر امنیتی فعلی production — مسیرهای admin تولیدی Scope صریح برقرار می‌کنند و مرز REST جداگانه scope می‌شود) و **د** override ‏`horizon_days` در payload جاب (مسیر settings بازهٔ `1..365` را clamp می‌کند ولی مسیر payload سقف بالایی ندارد). **الف** فقط بدهی کامنت/مستندات: کامنتِ نادرستِ مسیر استثنا در `ScheduleController.php` که `404` را توصیف می‌کند درحالی‌که مرز scope در حال اجرا `403 CLINIC_SCOPE_UNAVAILABLE` / `reason=location` برمی‌گرداند — رفتار محصول درست است، فایل منبع در این closure دست‌نخورده ماند و اصلاح کامنت به نخستین برش محصولیِ مجازی که آن فایل را لمس کند موکول شد. **آخرین migration همچنان `0020` است و `0021` وجود ندارد/رزرو نشده.** این closure ادعای release/V1/تجاری نیست و فقط مستندات است (هیچ کد/تست/workflow/migration/schema/configuration تغییر نکرد).

> **وضعیت (2026-09-11 — snapshot؛ موارد C10 و چک‌پوینتِ آن با «به‌روزرسانی 2026-09-12» بالا جایگزین شد):** مرجع معتبر = [`project-current-state.md`](project-current-state.md). خلاصه: **Phase 0 / 0.5 = CLOSED** · **Phase 1A = CLOSED · Phase 1B = DEFERRED** · **Phase 2 (Multi-Clinic Core) = IN PROGRESS** (C4/C5/C6 CLOSED؛ **C7 = CLOSED** — PR #20 MERGED، merge = `a385d868`؛ **پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11**؛ فقط دامنهٔ تعریف‌شدهٔ C7) · **C8 = CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location (بدون پیاده‌سازی)** · **C9 = CLOSED** — پیاده‌سازیِ محدودشده از طریق **PR #23 MERGED** در چک‌پوینت `7146d5b` ادغام شد و **پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11 (پس از ادغام)**؛ فقط دامنهٔ محدودشده: دو یافتهٔ A/Low که خودِ C7 معرفی کرده بود، در مرز REST، با حفظ `code`/status/`data` و فارسیِ پیش‌فرض؛ بدون تغییر Domain/Application، بدون migration، بدون ابزار گارْد جدید؛ **نه** رفعِ همهٔ بدهیِ i18n، **نه** پشتیبانیِ کاملِ انگلیسی، **نه** آمادگیِ globalization، **نه** بسته‌شدنِ Phase 2 (قاعدهٔ لایه‌بندیِ بومی‌سازی و بدهیِ تحمّل‌شده در `project-current-state.md` §I‑1 و `phase2-state.md` §C9) · **C10 = NOT STARTED و مجاز نشده** *(🔴 تاریخی — اکنون **C10 = CLOSED** با تصمیم صریح مالک 2026-09-12؛ رجوع به «به‌روزرسانی 2026-09-12» بالا)* (عملکرد: شواهد benchmark واقعیِ **محدودِ Staging** موجود است — `report-pilot-gate.md` §8 + گامِ `ab` در `pilot-gate.yml`؛ ولی **عملکردِ محیط مرجع/تجاری اندازه‌گیری‌نشده/اعتبارسنجی‌نشده** است و آن گزارش ادعای پاس‌شدنِ آستانه‌ها را نکرد — §12.3 ‏BLOCKED_BY_ENVIRONMENT) · **Phase 3 = NOT STARTED** *(🔴 تاریخی — اکنون **Phase 3 = COMPLETED / FROZEN** در `ebf8588`؛ رجوع به «به‌روزرسانی 2026-09-16» بالا)* · آخرین migration = `0020` (‏`0021` ساخته/تصویب نشده) · چک‌پوینت جاریِ `origin/main` = `7146d5b` *(تاریخی — چک‌پوینت جاری در «به‌روزرسانی 2026-09-12» بالا و در `project-current-state.md`)*.

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
| [`architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md) | **🔴 APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED** — طراحی ترمیم Tenant Context در Jobهای پس‌زمینه / timezone عملیاتیِ Location / پیکربندی SMS per-Clinic (**شامل credentialِ sealed**) / انتساب tenant در لاگ عملیاتی / طبقه‌بندیِ **T/S/W** برای **۱۵ نوعِ واقعیِ ثبت‌شده** (§A-3) + **۶ سوالِ طراحیِ پیش از هر migration آینده** (بدونِ رزروِ شماره) + مشخصاتِ **۱۴ تستِ RED ‏(RT-1..RT-14)**. **بازبینیِ مستقلِ معماری 2026-09-12 → حکمِ مالک `B`؛ اصلاحاتِ C-1..C-9 اعمال شد** (**فقط طراحی — هیچ پیاده‌سازی/تستی انجام نشده**) |
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
| [architecture/phase2-tenant-context-remediation-design.md](architecture/phase2-tenant-context-remediation-design.md) | طراحی ترمیم Tenant Context فاز ۲ (Jobها/timezone/SMS/لاگ عملیاتی) | — | 🔴 **طراحی تأییدشده — پیاده‌سازی نشده (NOT YET IMPLEMENTED)** |
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
