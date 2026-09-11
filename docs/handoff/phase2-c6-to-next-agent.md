# HANDOFF — Phase 2 / C6 → Agent بعدی

**سند canonical تحویل (Session Reconciliation — Arena `01a08828`).**

> ⚠️ این سند ادعا است، نه اثبات؛ هر بند از خود مخزن/Git/CI قابل راستی‌آزمایی است.
> Agent بعدی موظف است پیش از هر تغییر، بخش «راستی‌آزمایی» را اجرا کند.
> **مرجع حافظه:** `docs/project-current-state.md` §K + `docs/phase-reports/phase2-state.md`
> + `docs/phase-reports/c6-census.md` §۱۲. این سند تکرارِ فشرده و بازیابی‌پذیرِ همان state است —
> عمداً به SHAِ کامیتِ خودِ این سند ارجاع نمی‌دهد.

**نام‌گذاری:** برچسب‌های «Phase 9 §1..§5» در بعضی کامیت/گزارش‌ها **taxonomy وظایف داخلیِ قدیمی**
است. **فاز ۹ نقشهٔ راه مالک = Patient Portal و STILL NOT STARTED است.** وضعیت معتبر:
**Phase 2 / C6 — CLOSED** (تصمیم مالک/معمار). زیرفاز بعدی Phase 2 = **C7 — NOT STARTED**.

> **Governance فازبندی:** برای سلسله‌مراتب کامل (Owner Phase 0..20 = authoritative؛
> `C6` = بستهٔ کاری داخل **Owner Phase 2**؛ سامانه‌های تاریخی/Legacy) و crosswalk و قواعد
> نام‌گذاری، به [`docs/governance/project-phase-taxonomy.md`](../governance/project-phase-taxonomy.md)
> مراجعه کنید. عبارتِ تنها «Phase N» فقط به Owner Roadmap اشاره دارد؛ «Phase 9» داخلی ≠ Patient Portal.

---

## ۰. راستی‌آزمایی (اولین کار Agent جدید)

```bash
git rev-parse origin/main   # باید 248ca1049b49ea8f82b622744e39cf5b391a6838 باشد
gh pr view 17 --json state,mergedAt                 # MERGED، 2026-09-10T20:55:57Z
gh pr view 18 --json state,mergedAt                 # CLOSED، mergedAt = null (بدون merge)
gh pr view 13 --json state,isDraft                  # OPEN + DRAFT — دست‌نخورده
gh api repos/bia2on2on/doctor/actions/runs/34529280235 -q '.conclusion'  # success (CI)
ls clinic-practice-management/src/Migrations | tail -1   # …_0020_idempotency_clinic_scope.php
```

> **به‌روزرسانی ۲۰۲۶-۰۹-۱۱:** چک‌پوینت ادغام‌شده از `099b644` به **`248ca10`**
> منتقل شد (ادغام PR #17 = اصلاحیهٔ پس از بستنِ C6 برای دامنهٔ کلینیک در مالی).
> جدول‌های زیر که به `099b644` ارجاع می‌دهند **به‌عنوان سابقهٔ تاریخی** حفظ
> شده‌اند و بازنویسی نشده‌اند.

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| `origin/main` (جاری) | `248ca1049b49ea8f82b622744e39cf5b391a6838` — **Merge PR #17** |
| `origin/main` (چک‌پوینت قبلی، تاریخی) | `099b6449362ce16be185aa811ff1f7da7dec269e` — **Merge PR #14 (والدین: `8087b42` + `a49b182`)** |
| Branch کاریِ پیش‌از‌ادغام (تاریخی) | `arena/01a08828-doctor` — implementation `3fc5a54` — closure docs `becc82f` — ادغام شد |
| نسب تاریخی | `8087b42` ⊂ `79cce4b`(#10) ⊂ `9e006b0`(#11) ⊂ `f88fcdc`(#12) ⊂ `09d505b`(#13-head، diagnostic) ⊂ … ⊂ `a49b182`(#14) — **خطی** |
| PR #14 | [#14](https://github.com/bia2on2on/doctor/pull/14) — **MERGED** (2026-09-10) — head `a49b182` — base `main` |
| PR #10 / #11 / #12 | **MERGED** (تاریخی؛ زیرمجموعهٔ #14) — بازگشایی ممنوع |
| PR #13 | **OPEN + DRAFT** — diagnostic ترمیم C6 (`arena/01a086b4-doctor`، head `09d505b`) — **دست‌نخورده** |
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

## ۱ج. گیت‌های سبزِ پس‌از‌ادغام — همه روی `248ca10` (`origin/main` جاری)

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

## ۴. خط قرمزها

- Phase 3 / C7 / C8 / Portal / mobile: **شروع ممنوع**.
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

**C7** = زیرفاز بعدی Phase 2. Scope هنوز تعریف نشده. شروع فقط با تأیید مالک.
**قبل از شروع:** ابتدا این سند + `project-current-state.md` + `phase2-state.md` را بخوانید.

## ۷. تداوم پایانی (Final Continuity)

این مخزن باید survive agent/chat disappearance کند:

| مورد | وضعیت |
|---|---|
| PR #17 | **MERGED** ۲۰۲۶-۰۹-۱۰T20:55:57Z — اصلاحیهٔ پس از بستنِ C6 (مالی) — merge = `248ca10` |
| PR #18 | **CLOSED بدون merge** ۲۰۲۶-۰۹-۱۱T06:00:12Z (`mergedAt = null`) — اصلاحیهٔ رقیب، superseded توسط #17؛ شاخهٔ `arena/01a08b72-doctor` (head `68cde82`) **حذف نشده** |
| PR #16 | CLOSED (تاریخی) |
| PR #14 | **MERGED** — `arena/01a08828-doctor` → `main` (merge = `099b644`) |
| PR #10 / #11 / #12 / #15 | MERGED (تاریخی) |
| PR #13 | OPEN + DRAFT — diagnostic — **دست‌نخورده** (بازبررسی ۲۰۲۶-۰۹-۱۱) |
| `origin/main` | `248ca10` — Merge PR #17 (چک‌پوینت قبلی `099b644`، والدین `8087b42` + `a49b182`) |
| C6 | CLOSED + اصلاحیهٔ پس از بستن ادغام شد — `248ca10` = چک‌پوینت جاری؛ `3fc5a54` = شواهد اجرایی پیش‌از‌ادغام؛ `becc82f` = closure docs پیش‌از‌ادغام |
| C7 | **NOT STARTED** (پیاده‌سازی). C7-0 = فقط شواهد ⇒ [`docs/phase-reports/c7-0-census.md`](../phase-reports/c7-0-census.md) |
| Phase 3 | NOT STARTED — نیازمند تصمیم مالک |
| Migration 0021 | ممنوع بدون تأیید مالک — ساخته نشده؛ آخرین migration = `0020` |

**تأیید checkpoint ادغام:**
```bash
git rev-parse origin/main   # 248ca1049b49ea8f82b622744e39cf5b391a6838
gh pr view 17 --json state  # MERGED
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

**تست‌های characterization commit نشده‌اند** — چون گیت‌های `arena/**` روی هر push
اجرا می‌شوند و تست قرمز، شاخه را عمداً قرمز می‌کند. طرح تست‌ها + دستور دقیق
بازتولید در §۸ همان سند. **نیازمند تصمیم مالک** پیش از ثبت شواهد قرمز.

**SHA‌های کلیدی:**
- `099b644` = origin/main (ادغام PR #14)
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
