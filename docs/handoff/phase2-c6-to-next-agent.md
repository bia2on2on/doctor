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
git log --oneline -1 origin/arena/01a08828-doctor   # باید becc82f (یا بعدی) باشد
git rev-list --left-right --count origin/main...origin/arena/01a08828-doctor   # انتظار: 0 <n
gh pr view 14 --json state,headRefOid               # OPEN، head = becc82f
gh api repos/bia2on2on/doctor/actions/runs/34450386921 -q '.conclusion'  # success
```

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| Branch کاری | `arena/01a08828-doctor` — tip **`becc82f`** (closure docs) |
| `origin/main` | `8087b42e19a1721e38eb073aa1a17aff1cbac97b` — **دست‌نخورده** |
| نسب (`merge-base --is-ancestor`) | `8087b42` ⊂ `79cce4b`(#10) ⊂ `9e006b0`(#11) ⊂ `f88fcdc`(#12) ⊂ `09d505b`(#13) ⊂ … ⊂ `becc82f`(#14) — **خطی** |
| PR #14 | `arena/01a08828-doctor` — OPEN — head `becc82f` — base `main` — |
| PR #10–#13 | OPEN — heads ancestors of `becc82f` — redundant با #14 |
| Schema | `2026_09_09_0020` — **Migration 0021 وجود ندارد و تصویب نشده** |

## ۱. گیت‌های سبزِ نهایی — همه روی `3fc5a54`

| گیت | Run | نتیجه |
|---|---|---|
| CI (Tripwire + Unit×4 + PHPStan + WPCS + **Integration** 616 tests) | `34450386921` | ✅ success |
| Real-WP Acceptance (push) | `34450382616` | ✅ success |
| Real-WP Acceptance (PR) | `34450386918` | ✅ success |
| Pilot/Staging Readiness | `34450382549` | ✅ success |
| Closure Gate | `34450382522` | ✅ success |

SHA `3fc5a54` = آخرین implementation test. SHA `becc82f` = آخرین documentation tip.

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
- `main` / PR merge/close / force-push: **دست‌نخورده**.
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
| PR #14 | OPEN — `arena/01a08828-doctor` → `main` |
| PR #10–#13 | OPEN — ancestors of #14 — redundant |
| `origin/main` | `8087b42` — **دست‌نخورده** |
| C6 | CLOSED — SHA `3fc5a54` = شواهد اجرایی; SHA `becc82f` = closure docs |
| C7 | NOT STARTED — scope undefined |
| Phase 3 | NOT STARTED — نیازمند تصمیم مالک |
| Migration 0021 | ممنوع بدون تأیید مالک |

**تأیید نسب:**
```bash
git merge-base --is-ancestor origin/main HEAD && echo "main ⊂ HEAD ✓"
git log --oneline origin/main..HEAD | head -20
```

**SHA‌های کلیدی:**
- `8087b42` = origin/main (دست‌نخورده)
- `3fc5a54` = implementation evidence (all 5 gates GREEN)
- `becc82f` = closure documentation tip

---

### بایگانی شواهد RED

| Run | SHA | محتوا |
|---|---|---|
| `34440881334` | `030a63b` | 5 Class-D harness failures |
| `34441624738` | `f4afc31` | 4 failures |
| `34442241861` | `d4b6f3c` | 1 failure (notification) |
| `34446563634` | `7c745bd` | 1 failure (visit location_id) |
| `34450386921` | `3fc5a54` | ✅ **همه سبز** (Tripwire + 616 tests) |
