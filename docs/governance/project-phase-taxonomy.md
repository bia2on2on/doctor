# GOVERNANCE — Taxonomy و Crosswalk نظام‌های فاز/نسخه/وظیفه

> **سند canonical برای تفسیر امنِ برچسب‌های فاز.** یک ایجنت/مهندس باید در کمتر از یک دقیقه
> بفهمد کدام برچسب فاز معتبر است و برچسب‌های تاریخی به چه معنا هستند.
>
> **مرجع فازبندی اجرایی:** [`docs/roadmap/roadmap.md`](../roadmap/roadmap.md) §۰ — **Owner-approved Phase 0..20.**
> **مرجع وضعیت فعلی:** [`docs/project-current-state.md`](../project-current-state.md).
> **مرجع تحویل Phase 2/C6:** [`docs/handoff/phase2-c6-to-next-agent.md`](../handoff/phase2-c6-to-next-agent.md).

---

## ۰. سلسله‌مراتب (حکم یک‌دقیقه‌ای)

| سطح | سامانه | معنی | اختیار |
|---|---|---|---|
| **LEVEL 1** | **Owner Roadmap Phase 0..20** (در `roadmap.md` §۰) | نقشهٔ اجرایی تحویل کل محصول | **تنها مرجع اجرایی. «Phase N» بدون قید و شرط = همین.** |
| **LEVEL 2** | زیرفازها / بسته‌های کاری (مثلاً **C1..C10** در Phase 2، و **1A/1B** در Phase 1) | تجزیهٔ کاریِ یک فاز Owner | **تابعِ فاز مالک.** فاز مستقل نیستند. |
| **LEVEL 3** | سامانه‌های تاریخی/اختصاصی (`F*`، `Doc-Phase`، `V*`، «Phase 9» داخلی، P2-*، Q/AD/OD/B/D/A/M/O، Class A–D، گیت‌های Closure/Pilot/…) | فقط برای traceability تاریخی یا یک حوزهٔ خاص | **هرگز بر Owner Roadmap غلبه نمی‌کند.** |

**قانون طلایی:** عبارتِ بدون قیدِ «**فاز N**» فقط به Owner Roadmap اشاره دارد. هر برچسب دیگر باید با یک
**کیف‌فایر** همراه باشد (مثلاً «Legacy F9»، «Doc-Phase 2»، «Internal validation Phase 9»).

---

## ۱. فهرست سامانه‌های فاز/نسخه (Taxonomy Inventory)

| شناسه | نام/الگو | کجا دیده می‌شود | معنای تاریخی | وضعیت | تعارض احتمالی با Owner؟ | وابستگی کد/تست/CI |
|---|---|---|---|---|---|---|
| T1 | **Owner Phase 0..20** | `roadmap.md` §۰ | نقشهٔ اجرایی رسمی | **AUTHORITATIVE / CURRENT** | — | بله (گیت‌ها) |
| T2 | **Phase 0.5** | `architecture/phase0.5-target-model.md` | فاز میانیِ مستندسازیِ معماری و Migration | **CURRENT (DOC + APPROVED)**، خارج از شمارهٔ اصلی | جزیی | خیر |
| T3 | **Phase 1A / 1B** | `roadmap.md` §۰-۲ | تقسیم اجرایی Phase 1 (Security) | **SUBPHASE** (1A CLOSED، 1B DEFERRED) | خیر | 1B: آینده‌ای |
| T4 | **C1..C10** | `phase2-state.md`/`project-current-state.md` | زیرفازهای Phase 2 (Multi-Clinic Core) | **CURRENT** — C1..C6 CLOSED · **C7 CLOSED (پذیرش رسمی مالک 2026-09-11)** · **C8 CLOSED (بستهٔ شواهد/مستندات فوندیشن Location — بدون پیاده‌سازی)** · **C9 CLOSED (پیاده‌سازیِ محدودشده ادغام‌شده در `7146d5b` از طریق PR #23 MERGED؛ پذیرش/بستن رسمی مالک 2026-09-11 پس از ادغام)** · C10 NOT STARTED (مجاز نشده) | خیر | بله |
| T5 | **C6-A..E3 / C6-F / C6-G / C6 Export** | `c6-census.md` | ریز-زیرفازهای C6 | **CURRENT** | خیر | بله |
| T6 | **F0..F10 (+ F2.5)** | `roadmap.md` §۲-۲، `phase-reports/report-f*.md` | فازهای ساخت محصول پیش از Phase 0 تا Go-Live V1 | **LEGACY / HISTORICAL** | **بله** (F9 = Hardening؛ با Phase 9 اشتباه نشود) | خیر |
| T7 | **Doc-Phase 1..8** | `docs/README.md` | فازهای *مستندسازی* | **LEGACY / DOCUMENTATION-ONLY** | **بله — پرریسک** (Doc-Phase 2 ≠ Phase 2) | خیر |
| T8 | **V1 / V1.5 / V2** | `roadmap.md` §۲-۱ | برچسب‌های انتشار/نسخه | V1 = HISTORICAL (1.0.0 منتشر شد) · V1.5 = PAUSED (OD-2) · V2 = DISSOLVED | **بله** (V2 محتوایش به Phase 2/3/4 بازتوزیع شد) | نسخهٔ `1.0.0` در هدر + assert در closure-gate |
| T9 | **«Phase 9 §1..§5» (داخلی)** | `phase2-state.md`/`c6-census.md`/`project-current-state.md` | برچسب داخلیِ تسک برای batch استحکام tenant (C6) | **LEGACY / AMBIGUOUS** | **بله — پرریسک** | خیر |
| T10 | **Internal «Phase 9» = ماتریس وردپرس / «Phase 10» = ماتریس PHP** | `phase-reports/report-go-live-validation.md` §4/§5 | ماتریس سازگاری در گزارش go-live | **DOCUMENTATION-ONLY / AMBIGUOUS** | **بله — پرریسک** | خیر |
| T11 | **P2-B / P2-D1 / P2-D2** | کامیت‌ها (1f8b36d, 98ca0ff, 40832be) | برچسب داخلیِ جزئیات Phase 2 | **UNKNOWN** (مُستندشده اندک) | خیر | فقط پیام کامیت |
| T12 | **Q1..Q13 Decision Register** | `phase0.5-target-model.md` §و | تصمیم‌های معماری | **CURRENT** | خیر | Q6/Q11/Q12 باز |
| T13 | **OD-1..OD-9 / D1..D4** | `roadmap.md`/ADRها | تصمیم‌های Owner (OD)/محصول (D) | **CURRENT** | خیر | OD-2/OD-3/OD-5 باز |
| T14 | **D-01..D-21، A-01..A-09، M-01..M-05، O-01..O-06، B-01..B-21** | `drift-register.md`، `phase1b-deferred-register.md` | ردیف‌های مغایرت (Drift) و اقلام deferred | **CURRENT** | خیر | B-* در فازهای مربوط |
| T15 | **AD-01..AD-16 (ADR-0031)** | `adr/ADR-0031-…` | تصمیمات معماری الزام‌آور | **AUTHORITATIVE (architecture)** | خیر | بله (کد/مایگریشن) |
| T16 | **Class A / B / C / D** | `phase2-state.md`/`c6-census.md`/`project-current-state.md` | طبقه‌بندی شکست تست/معیوب (A=product، B=Critical، C=infra، D=harness) | **CURRENT (harness)** | خیر | بله |
| T17 | **Closure / Pilot / Staging / Real-WP / Go-Live** | workflowها و گزارش‌ها | اصطلاحات «گیت» برای دروازه‌های فاز | **CURRENT** | خیر | بله |
| T18 | **Milestones M1/M2/M3** | `roadmap.md` §۳ | نقاط عطف تاریخی (پایان F3/F5+F6/F9) | **LEGACY / HISTORICAL** | خیر | خیر |

---

## ۲. Crosswalk — فقط نگاشت‌های مبتنی بر مدرک

> ⚠️ **اجباراً یک‌به‌یک برابر نسازید.** اگر سامانهٔ تاریخی چیز متفاوتی را اندازه می‌گرفت،
> از «NO EXACT MAPPING» استفاده کنید.

### ۲-۱. Owner Roadmap ↔ زیرفاز فعلی ↔ Legacy F ↔ مستندسازی ↔ نسخه

| Owner Roadmap | زیرفاز/بستهٔ کاری | برچسب Legacy | سند/ردیف | نسخه | وضعیت |
|---|---|---|---|---|---|
| **Phase 0** | — | (پیش از F؛ ممیزی F) | `report-phase-0-reverification.md` | V1 (پایه) | CLOSED |
| **Phase 0.5** | — | — | `phase0.5-target-model.md` + ADR-0031 | — | CLOSED/APPROVED |
| **Phase 1** | **1A** CLOSED · **1B** DEFERRED | F9 (Hardening — بخشی) | `report-od9-closure.md`، `phase1a-to-next-agent.md` | — | 1A CLOSED / 1B DEFERRED |
| **Phase 2** | **C4/C5/C6 CLOSED** (ادغام در `099b644`؛ اصلاحیهٔ پس از بستن در `248ca10`)؛ **C7 = CLOSED** (ترمیم ادغام‌شده در `a385d868`، PR #20 MERGED؛ **پذیرش رسمی مالک 2026-09-11**)؛ **C8 = CLOSED** (بستهٔ شواهد/مستندات فوندیشن Location — بدون پیاده‌سازی)؛ **C9 = CLOSED** (پیاده‌سازیِ محدودشده ادغام‌شده در `7146d5b`، PR #23 MERGED 2026-09-11T19:35:54Z؛ والدین `0fd5c27` + `c92737b`؛ **پذیرش/بستن رسمی مالک 2026-09-11 پس از ادغام** — فقط دامنهٔ محدودشده)؛ C10 NOT STARTED (مجاز نشده) | **V2** (بخش Multi-clinic → بازتوزیع) | ADR-0031؛ drift D-01..D-20 | — | IN PROGRESS |
| **Phase 3** | — (C7 متعلق به Phase 2 است، نه Phase 3 — رجوع به Queue در `phase2-state.md`) | **V2** (Role/Scope/Staff → بازتوزیع) | B-01..B-08؛ A-01..A-09 | — | NOT STARTED |
| **Phase 4** | — | **V2** (Specialty/Dept/Room → بازتوزیع) | M-01..M-05؛ Q11 باز | — | NOT STARTED |
| **Phase 5..8، 10..20** | — | بخشی از **V2** → OD-2 باز | drift O-* | V1.5 = PAUSED → OD-2 | NOT STARTED |
| **Phase 9 (Patient Portal)** | — | ⚠️ فقط هم‌اسامی؛ با T9/T10 اشتباه نشود | — | — | **NOT STARTED** |

### ۲-۲. «Phase N» پرابهام — همان عبارت، معانی متفاوت

| عبارت | معنی ۱ (Owner) | معنی ۲ | معنی ۳ |
|---|---|---|---|
| «**Phase 2**» | Multi-Clinic Core | Doc-Phase 2 = Permission Matrix | — |
| «**Phase 1**» | Security Hardening | Doc-Phase 1 = SRS | — |
| «**Phase 9**» | **Patient Portal** | Internal task label «Phase 9 §5» (C6 hardening) | Internal validation Phase 9 = WordPress matrix |

### ۲-۳. موارد «NO EXACT MAPPING»

- `Doc-Phase 1..8` ↔ Owner Phase: **NO EXACT MAPPING** (اندازه‌گیریِ چیزهای متفاوت — مستندسازی در برابر اجرا).
- `F0..F10` ↔ Owner Phase: **NO EXACT MAPPING در سطح تک‌به‌تک**؛ کل بلوک F = «کار پیش از Phase 0» که Phase 0 آن را ممیزی کرد.
- Class A/B/C/D: **NO EXACT MAPPING** به Owner Phase (یک سیستم طبقه‌بندیِ کیفیت/شکست است، نه فاز).
- Closure/Pilot/Go-Live: **NO EXACT MAPPING** به فاز — «درهای عبور» (gates) هستند، نه فاز.

---

## ۳. قواعد اصطلاحات (Terminology Rules — دائمی)

1. **«Owner Roadmap Phase N — <name>»** — وقتی ابهام است، مرجع باید این‌گونه بنویسد (مثال: «Owner Roadmap Phase 9 — Patient Portal»).
2. برای کارِ فعلی: **«Owner Roadmap Phase 2 — Multi-Clinic Core / C6»**.
3. برچسب‌های تاریخی باید کیف‌فایر داشته باشند:
   - `Legacy F9` (نه «F9» تنها، اگر ممکن است با فاز اشتباه شود)
   - `Historical Doc-Phase 2`
   - `Legacy release label V1.5`
   - `Internal validation Phase 9` / `Internal task label "Phase 9 §5"`
4. **عبارتِ تنها «فاز N» ممنوع** برای هر سامانهٔ داخلی/تاریخی، زیرا Owner Phase 9 = Patient Portal.
5. هیچ برچسبِ مفردِ تاریخیِ «Phase N» نباید اجازهٔ غلبه بر Owner Roadmap را داشته باشد.

---

## ۴. حکم حفظ تاریخچه (Hierarchy + Preservation)

- **بدون mass-rename.** گزارش‌های تاریخی باید از نظر تاریخی دقیق بمانند.
- **بدون بازنویسیِ پیام کامیت‌ها.** **بدون بازنویسیِ شواهد قدیمی** صرفاً برای راحتی اصطلاح.
- هدف = **ایمنیِ تفسیر**، نه بازنویسی تاریخ.
- راه حل: (۱) این سند canonical · (۲) اضافه‌کردن پیوند/اخطارِ باریک به نقاط ورود فعلی · (۳) علامت‌گذاریِ سامانه‌های تاریخی به‌عنوان historical/legacy در جای مناسب.

---

## ۵. مثال‌های نام‌گذاری صحیح / نادرست

**صحیح:**
- «Owner Roadmap Phase 2 — Multi-Clinic Core / C6» (کار فعلی)
- «Owner Roadmap Phase 9 — Patient Portal، NOT STARTED»
- «Legacy F9 (Hardening)»، «Historical Doc-Phase 2»، «Legacy release label V1.5»
- «Internal task label "Phase 9 §5"» برای batch استحکام tenant (C6)

**نادرست (باید اصلاح شود):**
- ❌ «ما در Phase 9 هستیم» (وقتی منظور C6 است) → ✅ «در Phase 2 / C6 هستیم»
- ❌ «Phase 9 §5 یعنی کار hardening» → ✅ «Internal task label "Phase 9 §5" (C6 hardening)»
- ❌ «Doc-Phase 2» به‌عنوان فاز اجرایی → ✅ «Historical Doc-Phase 2 (مستندسازی)»
- ❌ «V2 شامل Multi-Clinic است» → ✅ «Legacy V2 DISSOLVED؛ Multi-Clinic در Owner Phase 2 است»

---

## ۶. دستور بازیابی برای ایجنت جدید (Recovery Quickstart)

1. با `docs/project-current-state.md` شروع کنید (وضعیت + SHA مرجع + پیوندها).
2. مرحلهٔ «راستی‌آزمایی» در `docs/handoff/phase2-c6-to-next-agent.md` §۰ را اجرا کنید (Git/remote/PR/CI).
3. **پیش از هر تغییر کد/مستندات:** Owner Roadmap §۰ را در `docs/roadmap/roadmap.md` بخوانید.
4. اگر برچسب «فاز N» یا «C6» یا «Phase 9» دیدید، با این سند مطابقت دهید که کدام سامانه است.
5. **هرگز** نتیجهٔ مربوط به یک سامانهٔ تاریخی را با Owner Roadmap یکی نگیرید؛ اگر نمی‌دانید، «NO EXACT MAPPING» یا «UNKNOWN» بنویسید.

---

## ۷. پیوندهای مرتبط

- [`docs/roadmap/roadmap.md`](../roadmap/roadmap.md) §۰ — Owner-approved Phase 0..20 (AUTHORITATIVE)
- [`docs/project-current-state.md`](../project-current-state.md) — وضعیت فعلی + SHA مرجع
- [`docs/handoff/phase2-c6-to-next-agent.md`](../handoff/phase2-c6-to-next-agent.md) — تحویل Phase 2/C6
- [`docs/phase-reports/phase2-state.md`](../phase-reports/phase2-state.md) — state فاز ۲
- [`docs/phase-reports/c6-census.md`](../phase-reports/c6-census.md) — census tenant hardcode
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) — معماری مرجع
- [`docs/drift-register.md`](../drift-register.md) — مغایرت‌های اسنادی
- [`docs/security/phase1b-deferred-register.md`](../security/phase1b-deferred-register.md) — اقلام deferred فاز 1B
- [`docs/architecture/phase0.5-target-model.md`](../architecture/phase0.5-target-model.md) — مدل هدف + Decision Register

---

## ۸. وضعیت جاری (خلاصهٔ ثابت — حاکم)

- **Owner Roadmap Phase 0..20 = مرجع اجرایی (AUTHORITATIVE).**
- **C6 = بستهٔ کاری / زیرفاز داخل Owner Roadmap Phase 2** — نه فاز مستقل.
- **Owner Roadmap Phase 9 = Patient Portal و HAS NOT STARTED.**
- **ارجاع‌های داخلی/تاریخیِ «Phase 9» ≠ Owner Roadmap Phase 9.**
- **C6 = CLOSED** (تصمیم مالک/معمار؛ ادغام در `origin/main` = `099b644`؛ مرزهای deferred در `c6-deferred-boundaries.md`؛ اصلاحیهٔ پس از بستن در `248ca10`).
- **C7 = CLOSED** — پیاده‌سازی ادغام‌شده در `origin/main` از طریق PR #20 (merge `a385d868`، 2026-09-11؛ والدین `4871f84` + `6b438238`؛ بدون Migration؛ Phase 3 شروع‌نشده) و **پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11 ثبت شد**. دامنهٔ پذیرش = فقط دامنهٔ تعریف‌شده/تکمیل‌شدهٔ C7 — نه ادعای کامل‌بودن مطلق ایزولاسیون در کل محصول و نه تأیید آمادگی تجاری؛ اقلام به‌تعویق‌افتاده (S2/S3، Jobs/SMS/timezone، UX چندکلینیکی wp-admin) به‌تعویق‌افتاده می‌مانند مگر با تصویب جداگانه.
- **C8 = CLOSED به‌عنوان بستهٔ شواهد/مستنداتِ فوندیشن Location فاز ۲ (2026-09-11، بدون پیاده‌سازی)** — وجود برچسب Queue به‌تنهایی مجوز پیاده‌سازی نیست و بسته‌شدن C8 مجوز هیچ پیاده‌سازی Location جدید، migration 0021، دیتاست استان/شهر ایران، seed جغرافیای سراسری، UX مدیریت master-data، سیاست نهایی فاز ۳، کار portal، یا اصلاح scheduling/reminder/timezone را ایجاد نمی‌کند. جغرافیای ایران ⇒ **Owner Phase 4 (Master Data)**؛ مجوزدهی scoped نهایی ⇒ **Owner Phase 3**. معنای بسته‌شدن: «فوندیشن Location فاز ۲ بر پایهٔ شواهد جاری بسته شد»، نه «تمام رفتار آتی Location/timezone کامل است» (مصرف عملیاتی `locations.timezone` توسط runtimeهای فعلی/legacy = مرز ثبت‌شده/قلم آشتی‌دهیِ به‌تعویق‌افتاده).
- **C9 = CLOSED — پذیرش/بستن رسمی با تصمیم صریح مالک در 2026-09-11، پس از ادغامِ موفق** — پیاده‌سازیِ محدودشده از طریق **PR #23 MERGED** در چک‌پوینت `7146d5bb4167d2ac333000188d404c2aa977b817` ادغام شد (والدین `0fd5c27` + `c92737b`) و هر ۴ گیت پس‌از‌ادغام روی همان SHA سبز شدند (CI `34639699703` · Real‑WP `34639699751` · Pilot `34639699635` · Closure `34639699639`). دامنهٔ پذیرش = **فقط** کارِ محدودشدهٔ ادغام‌شده: دو یافتهٔ A/Lowِ i18n با منشأ خودِ C7، ترمیم در مرز ارائه/‏REST، حفظ `code`/status/`data` و فارسیِ پیش‌فرض، و پذیرشِ قاعدهٔ لایه‌بندیِ بومی‌سازی. **این بسته‌شدن ادعای رفعِ همهٔ بدهیِ i18n، پشتیبانیِ کاملِ انگلیسی، آمادگیِ globalization، translation‑readiness همهٔ رشته‌های wp‑admin، رفعِ بدهیِ تاریخیِ Domain/Application (۱۳ + ۱۹ نقطه = بدهیِ تحمّل‌شده، نه الگو) یا بسته‌شدنِ Phase 2 نیست**؛ و تکنیکِ تطبیقِ دقیقِ پیامِ منبع یک سازگاریِ **گذرا** است، نه معماریِ ترجیحیِ بلندمدت.
- **C10 = NOT STARTED و مجاز نشده** — هیچ scope مصوبی ندارد. وضعیتِ عملکرد (اصلاح‌شده): **شواهد benchmark واقعی اما محدودِ Staging موجود است** (گامِ قابل‌اجرای `ab` در job ‏`staging-gate` از `.github/workflows/pilot-gate.yml`؛ نتایجِ اجراشده در `report-pilot-gate.md` §8 با p50/p95/p99/RPS/error واقعی)، **اما عملکردِ محیط مرجع/تجاری در برابر آستانه‌های NFR همچنان اندازه‌گیری‌نشده/اعتبارسنجی‌نشده است** — آن محیط Staging محیطِ مرجعِ کانونی نیست و همان گزارش صریحاً ادعای پاس‌شدنِ اهداف را نکرد (داوری Quality Gate عملکرد موکول به بنچمارکِ سرور مرجع — §12.3، ‏BLOCKED_BY_ENVIRONMENT). اندازه‌گیری‌نشده باقی می‌مانند: هدفِ سربارِ صفحات عمومی (‏p95 < 100ms)، تعداد کوئری/‏N+1، حافظه، و بارِ معنادارِ چندکلینیکی. برچسب تاریخی «End Gate (۲۶بندی)» **فهرست پذیرش ۲۶بندیِ تعریف‌شده نیست** و هیچ تعریف کانونیِ ۲۶بندی در مخزن وجود ندارد. ‏**Phase 3 آغاز نشده.** ‏**Migration `0021` ساخته/تصویب نشده** (آخرین = `0020`). ‏**Phase 2 = IN PROGRESS.**
