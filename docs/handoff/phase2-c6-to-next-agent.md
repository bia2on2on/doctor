# HANDOFF — Phase 2 / C6 → Agent بعدی

**سند canonical تحویل (Session Reconciliation — Arena `01a08828`).**

> ⚠️ این سند ادعا است، نه اثبات؛ هر بند از خود مخزن/Git/CI قابل راستی‌آزمایی است.
> Agent بعدی موظف است پیش از هر تغییر، بخش «راستی‌آزمایی» را اجرا کند.
> **مرجع حافظه:** `docs/project-current-state.md` §K + `docs/phase-reports/phase2-state.md`
> + `docs/phase-reports/c6-census.md` §۱۲. این سند تکرارِ فشرده و بازیابی‌پذیرِ همان state است —
> عمداً به SHAِ کامیتِ خودِ این سند ارجاع نمی‌دهد.

**نام‌گذاری:** برچسب‌های «Phase 9 §1..§5» در بعضی کامیت/گزارش‌ها **taxonomy وظایف داخلیِ قدیمی**
است. **فاز ۹ نقشهٔ راه مالک = Patient Portal و STILL NOT STARTED است.** وضعیت معتبر:
**Phase 2 / C6 — CLOSED** (تصمیم مالک/معمار) و **C7 — پیاده‌سازی ادغام‌شده در `main` از طریق
PR #20 (merge `a385d868`؛ بستن رسمی معلق)**. قلم بعدی Queue فاز ۲ = **C8 — NOT STARTED**
(scope تعریف‌نشده).

> **Governance فازبندی:** برای سلسله‌مراتب کامل (Owner Phase 0..20 = authoritative؛
> `C6` = بستهٔ کاری داخل **Owner Phase 2**؛ سامانه‌های تاریخی/Legacy) و crosswalk و قواعد
> نام‌گذاری، به [`docs/governance/project-phase-taxonomy.md`](../governance/project-phase-taxonomy.md)
> مراجعه کنید. عبارتِ تنها «Phase N» فقط به Owner Roadmap اشاره دارد؛ «Phase 9» داخلی ≠ Patient Portal.

---

## ۰. راستی‌آزمایی (اولین کار Agent جدید)

```bash
git rev-parse origin/main   # باید a385d8681d5c37386407360b5b3c86e9b4af4e05 باشد
gh pr view 20 --json state,mergedAt,mergeCommit     # MERGED، 2026-09-11T13:09:16Z، merge = a385d868
gh pr view 17 --json state,mergedAt                 # MERGED، 2026-09-10T20:55:57Z (تاریخی)
gh pr view 18 --json state,mergedAt                 # CLOSED، mergedAt = null (بدون merge)
gh pr view 13 --json state,isDraft                  # OPEN + DRAFT — دست‌نخورده
gh api repos/bia2on2on/doctor/actions/runs/34602712029 -q '.conclusion'  # success (CI روی a385d868)
ls clinic-practice-management/src/Migrations | tail -1   # …_0020_idempotency_clinic_scope.php
```

> **به‌روزرسانی ۲۰۲۶-۰۹-۱۱ (۲):** چک‌پوینت ادغام‌شده از `248ca10` به
> **`a385d868`** منتقل شد (ادغام **PR #20** = ترمیم C7؛ والدین `4871f84` +
> head `6b438238`؛ merge توسط bot — تأیید مالک در مخزن مستند نیست).
> تمام گیت‌های اصلی روی `a385d868` سبزند (جدول ۱ج). جدول‌ها/بخش‌های زیر که به
> `248ca10` یا `099b644` ارجاع می‌دهند **به‌عنوان سابقهٔ تاریخی** حفظ شده‌اند و
> بازنویسی نشده‌اند.

> **به‌روزرسانی ۲۰۲۶-۰۹-۱۱ (۱):** چک‌پوینت ادغام‌شده از `099b644` به **`248ca10`**
> منتقل شد (ادغام PR #17 = اصلاحیهٔ پس از بستنِ C6 برای دامنهٔ کلینیک در مالی).

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| `origin/main` (جاری) | `a385d8681d5c37386407360b5b3c86e9b4af4e05` — **Merge PR #20 (ترمیم C7)** |
| `origin/main` (چک‌پوینت قبلی، تاریخی) | `248ca1049b49ea8f82b622744e39cf5b391a6838` — **Merge PR #17** |
| `origin/main` (چک‌پوینت قبلی، تاریخی) | `099b6449362ce16be185aa811ff1f7da7dec269e` — **Merge PR #14 (والدین: `8087b42` + `a49b182`)** |
| Branch کاریِ پیش‌از‌ادغام (تاریخی) | `arena/01a08828-doctor` — implementation `3fc5a54` — closure docs `becc82f` — ادغام شد |
| نسب تاریخی | `8087b42` ⊂ `79cce4b`(#10) ⊂ `9e006b0`(#11) ⊂ `f88fcdc`(#12) ⊂ `09d505b`(#13-head، diagnostic) ⊂ … ⊂ `a49b182`(#14) — **خطی** |
| PR #20 | [#20](https://github.com/bia2on2on/doctor/pull/20) — **MERGED** ۲۰۲۶-۰۹-۱۱T13:09:16Z — ترمیم C7 (S1..S6) — merge = `a385d868` — والدین `4871f84` + `6b438238` — merge توسط `app/arena-ai-coding-agent` (bot)؛ **تأیید مالک در مخزن مستند نیست** |
| PR #19 | [#19](https://github.com/bia2on2on/doctor/pull/19) — **MERGED** ۲۰۲۶-۰۹-۱۱T07:10:36Z — هم‌ترازی مستندات با `248ca10` + اصلاح پرسش‌های باز C7-0 (والد `4871f84`) |
| PR #17 | [#17](https://github.com/bia2on2on/doctor/pull/17) — **MERGED** (۲۰۲۶-۰۹-۱۰) — اصلاحیهٔ مالی پس از C6 — merge = `248ca10` |
| PR #14 | [#14](https://github.com/bia2on2on/doctor/pull/14) — **MERGED** (2026-09-10) — head `a49b182` — base `main` |
| PR #10 / #11 / #12 / #15 | **MERGED** (تاریخی) — بازگشایی ممنوع |
| PR #18 | **CLOSED بدون merge** (۲۰۲۶-۰۹-۱۱) — superseded توسط #17؛ شاخهٔ `arena/01a08b72-doctor` حفظ شده |
| PR #13 | **OPEN + DRAFT** — diagnostic ترمیم C6 (`arena/01a086b4-doctor`، head `09d505b`) — **دست‌نخورده**؛ توجه: `09d505b` اکنون ancestorِ `origin/main` است (ادغام از مسیر PR #14) ⇒ پاک‌سازی فقط تصمیم بعدیِ مالک |
| Schema | `2026_09_09_0020` — **Migration 0021 وجود ندارد و تصویب نشده** |

## ۱. گیت‌های سبزِ پیش‌از‌ادغام (تاریخی) — همه روی `3fc5a54`

| گیت | Run | نتیجه |
|---|---|---|
| CI (Tripwire + Unit×4 + PHPStan + WPCS + **Integration** 616 tests) | `34450386921` | ✅ success |
| Real-WP Acceptance (push) | `34450382616` | ✅ success |
| Real-WP Acceptance (PR) | `34450386918` | ✅ success |
| Pilot/Staging Readiness | `34450382549` | ✅ success |
| Closure Gate | `34450382522` | ✅ success |

SHA `3fc5a54` = آخرین implementation test (پیش‌از‌ادغام). SHA `becc82f` = آخرین documentation tip (پیش‌از‌ادغام).

## ۱ب. گیت‌های سبز روی `099b644` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | `34460364222` | ✅ success |
| Real-WP Acceptance | `34460364238` | ✅ success |
| Pilot/Staging Readiness | `34460364243` | ✅ success |
| Closure Gate | `34460364219` | ✅ success |

## ۱ج. گیت‌های سبزِ پس‌از‌ادغام — همه روی `a385d868` (`origin/main` جاری)

| گیت | Run | Attempt | نتیجه |
|---|---|---|---|
| CI | `34602712029` | 1 | ✅ success |
| Real-WP Acceptance | `34602711983` | 1 | ✅ success |
| Pilot/Staging Readiness | `34602711956` | 1 | ✅ success |
| Closure Gate | `34602711962` | 1 | ✅ success |

**head نهایی پیش‌از‌ادغامِ PR #20 (`6b438238`) — هر پنج workflow کانونی سبز:**
CI `34598981613` (PR) · Real-WP `34598981627` (PR) + `34598978084` (push) ·
Pilot `34598978102` · Closure `34598978147`.
RED تاریخی حفظ‌شده: CI `34598629321` روی `f2786c5` (۶۶۸ تست، ۱ شکست — رفع‌شده در `6b438238`).

## ۱ج-تاریخی. گیت‌های سبزِ پس‌از‌ادغام روی `248ca10` (چک‌پوینت قبلی)

| گیت | Run | Attempt | نتیجه |
|---|---|---|---|
| CI | `34529280235` | 1 | ✅ success |
| Real-WP Acceptance | `34529280196` | 1 | ✅ success |
| Closure Gate | `34529280176` | 1 | ✅ success |
| Pilot/Staging Readiness | `34529280164` | **2** | ✅ success |

Pilot تلاش اول روی `248ca10` شکست خورد — **طبقه‌بندی خطای پروژه: Class C**
(زیرساخت/tooling، گذرا)، نه رگرسیون محصول. تلاش دوم مراحل ماهویِ staging را
اجرا و موفق شد. تلاش اول **پاک نشده**؛ همین‌جا ثبت می‌شود.

## ۲. وضعیت C6 Technical DoD

| آیتم | وضعیت |
|---|---|
| Runtime tenant defaults = 0 | ✅ Tripwire: 173 فایل، 0 نقض |
| Isolation matrix 45/45 | ✅ همه VERIFIED_GREEN |
| Tripwire CI | ✅ 34 self-tests PASS، CI GREEN |
| MT-39 runtime handler tests | ✅ Real `__invoke()` با non-1 clinics |
| Trusted REST context | ✅ |
| Booking/Notification/Reports/Export/Settings/Cache/Jobs/Audit | ✅ |
| WPCS / PHPStan / Unit / Integration / Real-WP / Pilot / Closure | ✅ |

## ۳. کارهای deferred و دلیل

| آیتم | طبقه‌بندی | دلیل |
|---|---|---|
| Route classification (نهايی) | DEFERRED_TO_OWNER_ROADMAP_PHASE_3 | tenant isolation اثبات شده؛ role/capability policy = Phase 3 |
| Staff onboarding (UI/API) | LATER_STAFF_ADMIN_UX | domain primitives کامل؛ caller تولیدی/UI ندارد |
| SMS resend key (CHAR(64) truncation) | KNOWN_MEDIUM_DEBT | tenant isolation آسیب نمی‌بیند (clinic_id در hash)؛ schema change نیاز دارد |
| Phase 3 / AuthorizationService | NOT STARTED | نیازمند تصمیم مالک |
| C7-S2 — زمینهٔ tenant در اجرای Job | DEFERRED (سؤال باز — ADR لازم) | در C7 حل نشد؛ شکل راه‌حل باز است؛ Migration 0021 تصویب/ساخته نشده |
| C7-S3 — شماره‌گذاری نسخه | DEFERRED (سؤال باز دامنه‌ای مالک) | آیا توالی per-clinic مطلوب است؟ بدون شاهد اجرایی، رده D نه نقص اثبات‌شده |
| wp-admin multi-clinic selection UX | NOT BUILT | مرز فعلی با Membership حل می‌شود؛ UX انتخاب صریح هنوز ساخته نشده |
| Jobs/SMS/timezone hardening | DEFERRED (بدون تصویب خاموش) | همان S2؛ تا تصمیم مالک دست‌نخورده |

## ۴. خط قرمزها

- **C7:** پیاده‌سازی از طریق PR #20 در `main` ادغام شده؛ **بستن رسمی = معلق تا تصمیم مالک**. بازگشایی/بازنویسی ادغام ممنوع.
- Phase 3 / C8 / Portal / mobile: **شروع ممنوع بدون scope و تأیید صریح مالک** (C8 فقط یک قلم Queue با scope تعریف‌نشده است).
- **Migration 0021**: بدون تأیید مالک، ممنوع.
- PR #13: **دست‌نخورده** (merge/close ممنوع)؛ `main`: فقط با تأیید مالک؛ force-push: ممنوع.
- هیچ security تستی skip/quarantine/weaken نشد.

## ۴ب. گیت‌های بازگشت‌ناپذیر (Permanent Regression Gates)

کار آینده نباید هیچ‌یک از موارد زیر را تضعیف کند:

| # | Gate | معیار |
|---|---|---|
| 1 | Isolation matrix 45/45 | هر ۴۵ آیتم باید VERIFIED_GREEN بماند |
| 2 | Tripwire CI | production runtime allowlist = خالی; 34 self-tests PASS |
| 3 | Self-tests | هیچ تست Tripwire حذف/xfail نشود |
| 4 | Membership fail-closed | بدون active Membership ⇒ reject |
| 5 | Non-1 tenant IDs | هیچ hardcode clinic_id=1 در production code |
| 6 | Clinical-file isolation | فایل بالینی per-Clinic |
| 7 | Prescription Clinic ownership | نسخه Clinic-scoped |
| 8 | SMS log isolation | لاگ SMS per-Clinic |
| 9 | Background-job tenant identity | jobs باید Clinic context داشته باشند |
| 10 | Reports/Export/Settings/Cache isolation | همه per-Clinic |
| 11 | Migration 0020 | schema `2026_09_09_0020` دست‌نخورده |

## ۵. نکات harness

- warm خنثی **قبل** از ساخت fixture Clinicها (boot() pin).
- `cpms_visits.location_id` NOT NULL (Migration 0013).
- `cpms_appointments` فاقد `duration_min`.
- notification event names باید از `NotificationEvents` باشند.
- `seedSlot()` date = +7 days — برای handler tests نیاز به slot با date=today.

## ۶. بعدیِ ایمن

**C7 = تمام‌شده در سطح پیاده‌سازی:** ترمیم C7 (S1..S6) از طریق **PR #20 MERGED**
در `a385d868` ادغام شد؛ بستن رسمی/پذیرش مالک در مخزن مستند **نیست**.
**قلم بعدی Queue فاز ۲ = C8 — «Iran Location foundation»** (به نقلِ `phase2-state.md`).
⚠️ **Scope و پذیرش C8 در اسناد canonical تعریف نشده است** — شروع فقط پس از تعریف
scope و تأیید صریح مالک مجاز است. مرز مالکیت «ایران/جغرافیا: C8 (Phase 2) یا
Owner Phase 4 (Master Data)؟» در اسناد حل نشده و باید توسط مالک تعیین شود.
ادامهٔ Queue: C9 (i18n audit) → C10 (Performance review) → End Gate فاز ۲ → STOP.

**قبل از شروع:** ابتدا این سند + `project-current-state.md` + `phase2-state.md` را بخوانید.

## ۷. تداوم پایانی (Final Continuity)

این مخزن باید survive agent/chat disappearance کند:

| مورد | وضعیت |
|---|---|
| PR #20 | **MERGED** ۲۰۲۶-۰۹-۱۱T13:09:16Z — ترمیم C7 (S1..S6) — merge = `a385d868` — والدین `4871f84` + `6b438238` — merge توسط bot؛ تأیید مالک در مخزن مستند نیست |
| PR #19 | **MERGED** ۲۰۲۶-۰۹-۱۱T07:10:36Z — هم‌ترازی مستندات + اصلاح پرسش‌های باز C7-0 (والد `4871f84`) |
| PR #17 | **MERGED** ۲۰۲۶-۰۹-۱۰T20:55:57Z — اصلاحیهٔ پس از بستنِ C6 (مالی) — merge = `248ca10` |
| PR #18 | **CLOSED بدون merge** ۲۰۲۶-۰۹-۱۱T06:00:12Z (`mergedAt = null`) — اصلاحیهٔ رقیب، superseded توسط #17؛ شاخهٔ `arena/01a08b72-doctor` (head `68cde82`) **حذف نشده** |
| PR #16 | CLOSED (تاریخی) |
| PR #14 | **MERGED** — `arena/01a08828-doctor` → `main` (merge = `099b644`) |
| PR #10 / #11 / #12 / #15 | MERGED (تاریخی) |
| PR #13 | OPEN + DRAFT — diagnostic — **دست‌نخورده** (بازبررسی ۲۰۲۶-۰۹-۱۱). `09d505b` اکنون ancestorِ `origin/main` است ⇒ پاک‌سازی = تصمیم بعدیِ مالک، نه این پاس |
| `origin/main` | `a385d868` — Merge PR #20 (چک‌پوینت‌های قبلی `248ca10` سپس `099b644`، والدین `4871f84` + `6b438238`) |
| C6 | CLOSED + اصلاحیهٔ پس از بستن ادغام شد — `248ca10` = چک‌پوینت اصلاحیه؛ `3fc5a54` = شواهد اجرایی پیش‌از‌ادغام؛ `becc82f` = closure docs پیش‌از‌ادغام |
| C7 | **ادغام‌شده در `main` از طریق PR #20 (`a385d868`)** — S1..S6؛ C7-0 = فقط شواهد ⇒ [`docs/phase-reports/c7-0-census.md`](../phase-reports/c7-0-census.md). **بستن رسمی = معلق تا تصمیم مالک** |
| C8 | NOT STARTED — قلم بعدی Queue فاز ۲ («Iran Location foundation»)؛ scope/پذیرش تعریف‌نشده |
| Phase 3 | NOT STARTED — نیازمند تصمیم مالک |
| Migration 0021 | ممنوع بدون تأیید مالک — ساخته نشده؛ آخرین migration = `0020` |

**تأیید checkpoint ادغام:**
```bash
git rev-parse origin/main   # a385d8681d5c37386407360b5b3c86e9b4af4e05
gh pr view 20 --json state,mergedAt  # MERGED / 2026-09-11T13:09:16Z
gh pr view 17 --json state  # MERGED (تاریخی)
gh pr view 18 --json state,mergedAt  # CLOSED / null
```

### C7-0 — خلاصهٔ یافته‌ها برای Agent بعدی (فقط شواهد، بدون تغییر محصول)

ماتریس کامل در [`docs/phase-reports/c7-0-census.md`](../phase-reports/c7-0-census.md).
سرشماری A/B/C/D آن **محلی** است و با طبقه‌بندی خطای پروژه اشتباه نشود.

| یافته | رده | برش |
|---|---|---|
| مسیرهای مالیِ مبتنی بر ID (`recordPayment`/`voidPayment`/`refundPayment`/`addAdjustment` + خواندن فاکتور) بدون تأیید مالکیت کلینیک | C — بالا | S4 |
| اجرای Job: نمونهٔ `Settings` پین‌شده روی همهٔ کلینیک‌ها (timezone، افق Slot، Quiet Hours، retention، **اعتبارنامهٔ SMS**) | C — بالا | S2 — نیازمند ADR؛ **شکل راه‌حل باز است** (ستون جدید در `cpms_jobs` فقط یک کاندید است، نه الزام) |
| `ScheduleService::update/delete/deleteException` بدون predicate کلینیک | C — بالا | S1 |
| شماره‌گذاری نسخه: توالی سراسری، بدون قفل، UNIQUE سراسری | **D — حل‌نشده** (نه نقص تأییدشده) | S3 — ابتدا **سؤال باز دامنه‌ای**: آیا شمارهٔ نسخه اصلاً باید per-clinic باشد؟ بدون شاهد اجرایی، بدون Migration پیشنهادی |
| Organization/هویت بیمار، دامنهٔ کلید Idempotency (0020)، فایل بالینی، بیماران، نوبت‌ها، دست‌نویس، نسخهٔ نهایی‌شده، صف | SAFE (A/B) | — |
| `VisitService::history()`/`getVisit()` — بدون predicate ولی **بدون فراخوان REST** | D-dead | اگر روزی سیم‌کشی شد، اول دامنه‌بندی |

**تست‌های characterization در آن پاس commit نشدند** (شاخه عمداً قرمز نمی‌شد)؛
اما در پاس بعدی به‌صورت اجرایی روی شاخهٔ C7 نوشته و با ترمیم، سبز شدند —
زنجیرهٔ کامل RED→GREEN در §۱۱ همان سند ثبت است.

**SHA‌های کلیدی:**
- `a385d868` = origin/main (ادغام PR #20 — ترمیم C7)
- `6b438238` = head نهایی پیش‌از‌ادغامِ PR #20 (هر پنج workflow کانونی سبز)
- `4871f84` = mainِ پیش‌از‌ادغامِ PR #20 (ادغام PR #19)
- `248ca10` = چک‌پوینت قبلی (ادغام PR #17)
- `099b644` = چک‌پوینت پیشین (ادغام PR #14)
- `8087b42` = mainِ پیش‌از‌ادغام (تاریخی؛ والد اول merge)
- `a49b182` = head پیش‌از‌ادغامِ PR #14 (تاریخی؛ والد دوم merge)
- `3fc5a54` = implementation evidence (all 5 gates GREEN — پیش‌از‌ادغام)
- `becc82f` = closure documentation tip (پیش‌از‌ادغام)

---

### بایگانی شواهد RED

| Run | SHA | محتوا |
|---|---|---|
| `34440881334` | `030a63b` | 5 Class-D harness failures |
| `34441624738` | `f4afc31` | 4 failures |
| `34442241861` | `d4b6f3c` | 1 failure (notification) |
| `34446563634` | `7c745bd` | 1 failure (visit location_id) |
| `34450386921` | `3fc5a54` | ✅ **همه سبز** (Tripwire + 616 tests) |
