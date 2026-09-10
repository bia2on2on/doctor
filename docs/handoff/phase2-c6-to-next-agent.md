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
git rev-parse origin/main   # باید 099b6449362ce16be185aa811ff1f7da7dec269e باشد
gh pr view 14 --json state,headRefOid               # MERGED، head = a49b182
gh api repos/bia2on2on/doctor/actions/runs/34460364222 -q '.conclusion'  # success
```

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| `origin/main` | `099b6449362ce16be185aa811ff1f7da7dec269e` — **Merge PR #14 (والدین: `8087b42` + `a49b182`)** |
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

## ۱ب. گیت‌های سبزِ پس‌از‌ادغام — همه روی `099b644` (`origin/main`)

| گیت | Run | نتیجه |
|---|---|---|
| CI | `34460364222` | ✅ success |
| Real-WP Acceptance | `34460364238` | ✅ success |
| Pilot/Staging Readiness | `34460364243` | ✅ success |
| Closure Gate | `34460364219` | ✅ success |

## ۲. وضعیت C6 Technical DoD

| آیتم | وضعیت |
|---|---|
| Runtime tenant defaults = 0 | ⚠️ **در زمان بستن C6 نادرست بود** — به «۲ب» مراجعه کنید |
| Isolation matrix 45/45 | ✅ همه VERIFIED_GREEN |
| Tripwire CI | ✅ 34 self-tests PASS، CI GREEN — ⚠️ پوشش ناقص بود (به «۲ب» مراجعه کنید) |
| MT-39 runtime handler tests | ✅ Real `__invoke()` با non-1 clinics |
| Trusted REST context | ✅ |
| Booking/Notification/Reports/Export/Settings/Cache/Jobs/Audit | ✅ |
| WPCS / PHPStan / Unit / Integration / Real-WP / Pilot / Closure | ✅ |

## ۲ب. تصحیح پس‌از‌بستن — C6 post-closure corrective (2026-09-10)

**تاریخچه دست‌نخورده می‌ماند:** C6 به‌صورت رسمی بسته و در `main` ادغام شد (PR #14 → `099b644`،
PR #15 → `6c84316`). آنچه پایین می‌آید **کشفِ بعدی** است، نه بازنویسی آن پذیرش.

**یافته:** پذیرش C6 صریحاً ادعای «صفر tenant default در runtime» را داشت، اما **هفت مسیر مالی
تولید** همچنان در زمان اجرا با Clinic ID 1 کار می‌کردند:

| # | فایل | متد |
|---|---|---|
| ۱ | `Infrastructure/Repository/ServiceRepository.php` | `all()` |
| ۲ | `Infrastructure/Repository/PaymentRepository.php` | `revenueSummary()` |
| ۳ | `Infrastructure/Repository/PaymentRepository.php` | `forRange()` |
| ۴ | `Infrastructure/Repository/PaymentRepository.php` | `nextPaymentNumber()` |
| ۵ | `Infrastructure/Repository/InvoiceRepository.php` | `openInvoices()` |
| ۶ | `Infrastructure/Repository/InvoiceRepository.php` | `nextInvoiceNumber()` |
| ۷ | `Application/Finance/FinanceService.php` | `lockClinic()` |

**چرا Tripwire آن‌ها را ندید (نقص کلاس D):** در هر هفت مورد، ستون tenant یک **placeholder**
(`%d`) بود و literal `1` **جداگانه bind** می‌شد — اغلب چند خط پایین‌تر از خود SQL. آشکارساز
خط‌محور ساختاراً نمی‌تواند این را ببیند، پس `CLEAN` گزارش می‌کرد. این یک نقطهٔ کورِ
test/gate است، نه ضعف محصول.

**نقص ثانویهٔ تأییدشده (همان مسیرهای مالی):** `wpdb::insert` در خطا `false` می‌دهد ولی
`insert_id` را پاک نمی‌کند. `InvoiceRepository::insert` و `PaymentRepository::insert` آن شناسهٔ
**stale** را برمی‌گرداندند؛ در مسیر فاکتور هیچ گاردی وجود نداشت ⇒ اقلام فاکتور می‌توانستند به
یک فاکتور بیگانه چسبانده شوند و تراکنش commit شود. گارد مسیر پرداخت (`!$ok || $paymentId <= 0`)
هم ناکافی بود چون `$ok` همان `insert_id` بود، یعنی فقط «صفر» گرفته می‌شد.

**طبقه‌بندی:** هفت نقص مالی = **B (pre-existing product defect)** — پیش از C6 وجود داشتند و از
بستن C6 جان سالم به‌در بردند. نقطهٔ کور Tripwire = **D (test/gate defect)**. **هیچ‌کدام A نیستند**
(کار جاری رگرسیونی وارد نکرد).

**رفع (corrective باریک — نه بازکردن دوبارهٔ C6، نه C7، نه Phase 3، بدون Migration):**
هر هفت قرارداد حالا Clinic را **الزامی** می‌گیرند؛ `FinanceService` Clinic را از
`ClinicScope` مورد اعتمادِ درخواست یا از `clinic_id` ردیفِ ویزیت/فاکتورِ قفل‌شده در همان
تراکنش می‌گیرد (هرگز از پارامتر کلاینت) و `requireClinicScope()` مسیر را **fail-closed** می‌بندد.
شکست درج invoice/item/adjustment/payment حالا استثنا می‌دهد ⇒ شناسهٔ stale/صفر هرگز مصرف
نمی‌شود و تراکنش ROLLBACK می‌شود (مسیر Idempotency-race با catch همان استثنا حفظ شد).

**Tripwire:** لایهٔ دوم (تحلیل prepared statement) افزوده شد + سه سایه‌اندازی تصحیح شد
(الگوی مردهٔ `select_first_clinic`، `id_1_primary` روی `c.id = 1`، و تطبیق benign خط‌محور).
Self-tests: ۳۴ → **۶۲**. Allowlist **خالی** ماند. روی `main` پیش از رفع: **۷ hardcode**؛
پس از رفع: **۰ hardcode / ۱ suspect** (۱۷۳ فایل).

**ادعای تصحیح‌شده:** «runtime tenant defaults = 0» اکنون با آشکارسازِ سخت‌شده و تستِ اجرایی
پشتیبانی می‌شود — و فقط به همین دلیل دوباره assert می‌شود.

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
| PR #14 | **MERGED** — `arena/01a08828-doctor` → `main` (merge = `099b644`) |
| PR #10 / #11 / #12 | MERGED (تاریخی) |
| PR #13 | OPEN + DRAFT — diagnostic — **دست‌نخورده** |
| `origin/main` | `099b644` — **ادغام‌شده** (والدین: `8087b42` + `a49b182`) |
| C6 | CLOSED — `099b644` = checkpoint ادغام‌شده؛ `3fc5a54` = شواهد اجرایی پیش‌از‌ادغام؛ `becc82f` = closure docs پیش‌از‌ادغام |
| C7 | NOT STARTED — scope undefined |
| Phase 3 | NOT STARTED — نیازمند تصمیم مالک |
| Migration 0021 | ممنوع بدون تأیید مالک |

**تأیید checkpoint ادغام:**
```bash
git rev-parse origin/main   # 099b6449362ce16be185aa811ff1f7da7dec269e
gh pr view 14 --json state  # MERGED
```

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
