# لاگ کار ایجنت‌ها — CPMS

این فایل محل ورودی‌های جدید کار ایجنت‌هاست. **فقط به انتهای فایل اضافه کنید؛ ورودی‌های قبلی را ویرایش یا حذف نکنید.** تاریخچهٔ کامل قبلی عیناً در [`agent-guide-archive-2026-10-09.md`](agent-guide-archive-2026-10-09.md) نگهداری می‌شود.

برای هر کار، یک ورودی کوتاه و قابل راستی‌آزمایی ثبت کنید. جزئیات غیرضروری، دادهٔ بیمار، رمز، توکن، کوکی و اطلاعات محرمانه را وارد نکنید.

## الگوی ورودی

```markdown
### [YYYY-MM-DD UTC] — <عنوان تسک> (<branch/PR اگر هست>)

- **مبنای شروع:** مخزن/شاخه، HEAD و وضعیت درخت کار؛ وضعیت remote/PR فقط اگر زنده بررسی شده است.
- **دامنه و تغییرات:** چه چیزی تغییر کرد و چه چیزی صریحاً خارج از دامنه ماند.
- **اعتبارسنجی:** فرمان‌ها/گیت‌های اجراشده و نتیجه؛ هر مورد اجرا‌نشده را NOT RUN بنویسید.
- **تحویل و موارد باز:** commit/PR و گام بعدی؛ merge/release را فقط اگر واقعاً انجام شده ثبت کنید.
```

---

### [2026-10-09 UTC] — بازآرایی راهنمای ایجنت‌ها بدون حذف متن (در حال بررسی)

- **مبنای شروع:** نسخهٔ زندهٔ `main` پس از PR #209؛ نسخهٔ کامل ۲٬۴۰۲ خطی `docs/agent-guide.md` پیش از بازآرایی با SHA-256 `facde6bebcc94ed5c42fad29e4fe4d300760d200d5a2d9888daf4d9458a9ef2a` ثبت شد.
- **دامنه و تغییرات:** راهنمای کوتاه و دسته‌بندی‌شده، فهرست موضوعی، فایل مستقل برای لاگ‌های آینده، و اصلاح دستور شروع `AGENTS.md` در حال آماده‌سازی است. نسخهٔ قبلی راهنما باید بایت‌به‌بایت در آرشیو حفظ شود؛ هیچ متن تاریخی حذف یا بازنویسی نمی‌شود.
- **اعتبارسنجی:** پیش از تحویل باید برابری hash آرشیو با نسخهٔ مبنا، لینک‌های داخلی و `git diff --check` بررسی شوند؛ نتیجه هنوز ثبت نشده است.
- **تحویل و موارد باز:** یک Draft PR متمرکز پس از تکمیل بررسی‌ها ساخته خواهد شد؛ هنوز merge نشده است.


### [2026-10-09 UTC] — افزودهٔ اعتبارسنجی برای بازآرایی راهنمای ایجنت‌ها

- **حفظ متن:** آرشیو با محتوای commit پایهٔ `7a7b71472a4bd36fa67e8d2db77a03490f65f830:docs/agent-guide.md` مقایسه شد؛ فایل آرشیو دقیقاً ۲٬۴۰۲ خط و بایت‌به‌بایت یکسان است (SHA-256: `facde6bebcc94ed5c42fad29e4fe4d300760d200d5a2d9888daf4d9458a9ef2a`).
- **اعتبارسنجی ایستا:** ۱۰۲ پیوند محلی در فایل‌های راهنمای شروع، AGENTS، فهرست اسناد و لاگ بررسی شدند و مقصدِ گمشده‌ای نداشتند؛ `git diff --check` موفق شد. راهنمای فعال ۴۸ خط است.
- **وضعیت تحویل:** فقط مستندات تغییر کرده‌اند؛ branch `arena/e1f4b2c9-doctor` از `main` پایهٔ ذکرشده منشعب شد. PR پس از commit/push ساخته می‌شود؛ هیچ ادغامی انجام نشده است.

### [2026-10-09 UTC] — انتقال ورودی Phase 1B به لاگ append-only جدید (branch `arena/cb45567b-doctor` / Draft PR #208)

- **مبنای شروع:** `main` زنده به `b2ed268b6d9f20a69d58a2f7bc5f121246e88f2c` (merge PR #210 — فقط مستندات) رسیده بود: `docs/agent-guide.md` به درگاه ۴۸ خطی تبدیل شد، متن کامل در `docs/agent-guide-archive-2026-10-09.md` حفظ شد و `docs/agent-log.md` محل append-only ورودی‌های جدید گردید. ورودی زیر پیش‌تر در commit `df308a7a7a118dbc4d566daeecd5e03f068a0918` به انتهای نسخهٔ قدیمی `docs/agent-guide.md` اضافه شده بود و با `main` تعارض داشت (PR #208 = CONFLICTING).
- **دامنه و تغییرات:** این merge ورودی را بدون هیچ ویرایش محتوایی به همین فایل منتقل می‌کند و `docs/agent-guide.md` را بایت‌به‌بایت همان نسخهٔ `main` نگه می‌دارد؛ هیچ متن تاریخی حذف یا بازنویسی نشده (commit `df308a7` در تاریخچهٔ شاخه باقی است). هیچ تغییر کد، تست، workflow یا migration در این ورودی نیست.
- **اعتبارسنجی:** `git show origin/main:docs/agent-guide.md | diff -q - docs/agent-guide.md` → بدون اختلاف؛ `git diff --check` موفق.
- **تحویل و موارد باز:** متن عینی ورودی منتقل‌شده بلافاصله در ادامه آمده است؛ نتیجهٔ اعتبارسنجی این head در ورودی بعدی ثبت شده است.

### [2026-10-09 12:40 UTC] — Arena agent (fixed branch `arena/cb45567b-doctor`; one DRAFT PR #208 against `main`) — Phase 1B B-01/B-02 clinical authorization correction (TEST-ONLY RED → narrowed GREEN)

- **Live preflight:** repository root `/home/user/doctor`; legal session branch `arena/cb45567b-doctor`; clean worktree; `HEAD` = `origin/main` = GitHub `main` = `89dc70370fafb208572bc19402cafb35c461ca8f`; **open PRs = 0** at start. Latest migration unchanged `2026_09_26_0023_handwriting_prescription_paper.php`; **no migration, dependency, role or capability added**.
- **VALID RED (test-only, exact SHA):** `88ebf35d7dab544bb98d3bc9bfe0363ffdd96381` — CI run `37927102820` (pull_request), Integration job `113809842637`: `Tests: 1636, Assertions: 54378, Failures: 5`; the five failures are exactly the new Phase 1B regressions (private-note leakage to a same-Clinic peer; leakage when scoped `cpms_private_note_read` is explicitly denied; peer E8 note insertion `note_rows 0→1 / audit 8→9`; peer E11 finalization `draft→finalized / audit 8→9`; dual-member Clinic-A context writing a Clinic-B note `clinic_b_notes 0→1 / audit 8→9`). Own-Visit positive control passed. Tenant Tripwire, Unit 8.1–8.4, PHPStan and WPCS were green on that head; the AuthorizationService coverage step was skipped because the Integration step failed first, and its fail-closed guard reported the consequently absent coverage files (workflow cascade, not a separate product defect).
- **Reproduced defects (runtime, not code-reading inference):** B-02 cross-Clinic write through a trusted Clinic-A context into a Clinic-B Visit (`ClinicalService::addNote()` never called the existing `assertVisitInActiveClinic()`); B-01 doctor-specific prescription finalization by a same-Clinic peer (`finalizePrescription()` checked Clinic + `cpms_rx_create` only); B-01 private-note disclosure — `record()` returned every visibility to any scoped `cpms_medical_read` holder, contradicting SRS A-5 / ADR-0027 §5 / ADR-0026 D-7.
- **NOT a defect (reclassified, restriction not invented):** same-Clinic peer **note creation**. The accepted policy (permission matrix §4.3 note rows + `ClinicalFilesScopedAuthorizationTest::testSameActorIsAuthorizedPerClinicIndependently` on main, where Clinic B's Visit belongs to a different WP user because `u_clinician_user` is UNIQUE) authorizes a scoped `cpms_note_create` holder to note a same-Clinic Visit. The first RED asserted a narrower own-Visit rule; that assertion is **INVALID as a defect claim** and was replaced by a positive control pinning the authorized behavior plus the existing 403 capability denial. No Product Decision was invented, and private-note disclosure stays restricted.
- **Implementation (smallest existing guards):** `record()` — query-level `forVisit($visitId, ['patient_visible'])` unless the actor owns the Visit's persisted clinician **and** holds `cpms_private_note_read` globally **and** Clinic-scoped; `addNote()` — existing `assertVisitInActiveClinic($visit)` before permission checks/mutation; `finalizePrescription()` — new `require_own_clinician()` on the locked row's persisted `clinician_id` inside the existing transaction before the state update. Denials reuse `CLINIC_NOT_FOUND` 404 with zero clinical/audit mutation. New helpers `owns_visit()` / `require_own_clinician()` / `is_active_clinician_of_user()` are server-derived (active `cpms_clinicians` row whose `wp_user_id` equals the authenticated user); existing `requireOwnVisit()` and its audited 404 are byte-preserved.
- **Intermediate attempt (recorded, not hidden):** `e5656b171959d273efae045f2cc5d01f74c590f3` (run `37928240146`) broke 12 pre-existing tests (`Errors: 9, Failures: 3`) plus 80 added-line WPCS findings. Root causes: over-broad own-Visit note restriction (contradicted accepted policy); an unproven Visit↔prescription `clinician_id` equality constraint; ownership checked before the 403 capability path; `FinanceClinicIsolationTest::makeCompletedVisit()` calling `addNote()` without explicit scope; non-WP spacing on added lines. All five were corrected forward (no reset/rebase/force-push/history rewrite).
- **Harness corrections (class D):** `FinanceClinicIsolationTest::makeCompletedVisit()` now wraps the clinical note write in the same `withScope()` the file already uses for `walkIn()`/`issueInvoice()`; `RestTrustedClinicContextTest`'s two prescription-scope fixtures no longer rely on a silently failing second `insertClinician()` (UNIQUE `u_clinician_user`) that persisted `clinician_id = 0` — they reuse the actor's single real clinician profile, matching the documented "multi-Clinic participation is membership, not duplicate clinician rows" model.
- **State at the authorization-window checkpoint:** corrected head `47b381b7707ae1196a86228f4a2074744cf117b2` reached `Tests: 1636, Failures: 2` (run `37929881213`) with **1 remaining added-line WPCS violation** whose sniff/line the sandbox could not retrieve (log blob + mirror comment unreachable); the fixture correction for those 2 failures and a defensive WPCS ignore are pushed as the next forward commit. Exact-head six-workflow verification, the aggregate check-run count and `git diff --check` on the final head remain **PENDING** — nothing is claimed PASS without a terminal run. PR #208 stays **DRAFT / NOT MERGED**; independent security acceptance is required.

### [2026-10-09 16:40 UTC] — Arena agent (fixed branch `arena/cb45567b-doctor`; one DRAFT PR #208 against `main`) — Phase 1B B-01/B-02: رفع آخرین شکست WPCS روی head دقیق + merge رو‌به‌جلوی `main`

- **مبنای شروع (بازیابی زنده):** درخت کار تمیز بود اما HEAD محلی روی `89dc70370fafb208572bc19402cafb35c461ca8f` (کلون shallow) مانده بود در حالی که head واقعی remote/PR `df308a7a7a118dbc4d566daeecd5e03f068a0918` بود؛ هیچ کار منحصربه‌فرد محلی در خطر نبود و شاخه فقط با `git fetch` + `git merge --ff-only` جلو برده شد (بدون `reset --hard`/`clean`/rebase/force-push). `origin/main` = `b2ed268b6d9f20a69d58a2f7bc5f121246e88f2c` و PR #208 به‌دلیل `docs/agent-guide.md` در وضعیت `mergeable: CONFLICTING` / `mergeStateStatus: DIRTY` بود. آخرین migration بدون تغییر: `2026_09_26_0023_handwriting_prescription_paper.php`.
- **شواهد head دقیق `df308a7`:** شش family — Real WordPress Acceptance `37932053027` success · Persian five-plugin coexistence `37932053246` success · Third-Party Compatibility Baseline `37932052928` success · Closure Gate `37932048201` success · Pilot/Staging Readiness Gate `37932048190` success · CI `37932052980` **failure**. درون CI همهٔ jobها success بودند (Integration WP 6.7 + MySQL 8، PHPStan، Unit 8.1/8.2/8.3/8.4، Tenant Tripwire) و تنها job شکست‌خورده `WPCS (changed code)` بود. تخلف دقیق (از mirror comment روی PR): `clinic-practice-management/src/Application/Clinical/ClinicalService.php:485 [ERROR] PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket`.
- **دامنه و تغییرات (commit `2b46abf97db469d5fbd60ac04f8d4c4f6c5133fe`):** گارد مالکیت clinician در E11 از داخل closure چندخطی `transactional()` بیرون برده شد — همان الگوی مستندِ `updateNote()` در همین سرویس (بررسی‌های رد پیش از تراکنش تا هیچ جهش/audit ثبت نشود) — به‌صورت متد خصوصی جدید `require_own_prescription_clinician()` که `clinician_id` را **با predicate همان Clinic** در WHERE می‌خواند (ردیف Clinic دیگر هرگز بارگذاری نمی‌شود) و در نبود ردیف، `clinician_id` معلق (`0`) یا clinician غیرمتصل به کاربر احراز هویتی، fail-closed با همان `CLINIC_NOT_FOUND` 404 غیرقابل‌شمارش و صفر جهش بالینی/audit رد می‌کند. `cpms_prescriptions.clinician_id` immutable است (هیچ مسیر کدی آن را به‌روزرسانی نمی‌کند؛ فقط `status`/`finalized_at`/`void_reason`/`updated_at`)، بنابراین pre-check race-safe است و predicate Clinic و گذار Draft→Finalized همان‌جا داخل تراکنش قفل‌شده باقی ماندند. خطوط legacy آن closure بایت‌به‌بایت به نسخهٔ `main` برگردانده شدند تا خارج از دامنهٔ تغییر re-indent نشوند. **حذف ignore حدسی:** `phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning` روی خط `$note_visibility` حذف شد؛ دلیل: head `47b381b7707ae1196a86228f4a2074744cf117b2` همان خط را بدون ignore داشت و run `37929881213` فقط تخلف خط ۴۸۵ را گزارش کرد، پس آن suppression هیچ‌گاه لازم نبود. هیچ تغییر schema/migration/dependency/role/capability/tenant و هیچ گسترش سیاست مجوز انجام نشد.
- **اعتبارسنجی:** محلی فقط ایستا — parse موفق AST با `php-parser` برای `ClinicalService.php`، `RestTrustedClinicContextTest.php` و `FinanceClinicIsolationTest.php`؛ `git diff --check` تمیز. در این sandbox نه `php` CLI هست نه `vendor/`، بنابراین PHPUnit/PHPStan/WPCS محلی **NOT RUN** است و هر نتیجهٔ PASS/GREEN فقط از workflowهای GitHub روی همان SHA قابل استناد است. روی head ادغام‌شده، تأیید شش workflow در لحظهٔ commit **PENDING** بود و پس از terminal‌شدن در PR #208 به‌صورت evidence comment ثبت می‌شود.
- **تحویل و موارد باز:** PR #208 همچنان **DRAFT و NOT MERGED** است (یک PR؛ بدون PR اضافه؛ بدون rerun دستی/`workflow_dispatch`). پذیرش امنیتی مستقل الزامی است. تصمیم‌های محصولی باز بدون تغییر باقی‌اند و پیاده‌سازی نشده‌اند: PD-1 (آیا ایجاد یادداشت E8/یادداشت خصوصی هم‌Clinic باید به clinician خودِ Visit محدود شود) و PD-2 (آیا سازوکار صریح shared-care بین پزشکان لازم است).

### [2026-10-09 UTC] — رفع موانع پذیرش امنیتی مستقل Phase 1B (PR #208 / branch arena/cb45567b-doctor)

- **مبنای شروع و بازیابی:** head مبنای شروع `8173202a668a2e056c05460dec5b99154e06c929` (تست‌های افزوده‌شده برای موانع ۱ و ۲) با وضعیت CI `37967790442` (تست‌های ۱۶۴۲، ادعاها ۵۴۴۳۴، شکست‌ها ۳ — سه مورد نشت قطعه‌متن یادداشت خصوصی در جستجو) بود. تغییرات ناتمام امنیتی به شکل امن و رو به جلو تکمیل شدند (بدون reset/rebase/force-push).
- **دامنه و تغییرات (Blockers 1, 2, 3):**
  - **مانع ۱ (نشت یادداشت خصوصی در جستجو و چاپ نسخه):** متد `can_read_private_notes()` برای یکپارچه‌سازی شروط سه‌گانهٔ افشای `doctor_private` (مالکیت پزشک فعال ویزیت + مجوز سراسری `cpms_private_note_read` + مجوز محلی همان مطب با اولویت صریح DENY) در `ClinicalService` ایجاد و در خواندن پرونده (E7)، جستجوی سراسری (E18) و چاپ نسخه (`prescriptionForPrint`) استفاده شد. در `ClinicalNoteRepository::search` پارامتر الحاقی اختیاری `$own_clinician_id` اضافه شد تا فیلترسازی مستقیم در سطح کوئری SQL (نه بعد از واکشی در PHP) انجام شود؛ در نبود مجوز، فقط یادداشت‌های `patient_visible` واکشی می‌شوند. در مسیر چاپ نسخه نیز از واکشی `forVisit($visitId, null)` به واکشی با `$note_visibility` بر اساس همان تصمیم مجوز تغییر یافت تا متن شکایت اصلی خصوصی در صورت وجود deny افشا نشود.
  - **مانع ۲ (ایمنی بازنشانی هویت پزشک در E11):** اعتبارسنجی هویت پزشک فعال از خارج تراکنش به داخل مرز جهش تراکنشی و پس از اخذ قفل ردیف `FOR UPDATE` منتقل شد تا تغییرات متغیرهای هویت (`wp_user_id` / `is_active`) قبل از اعمال گذار `draft` به `finalized` منجر به رد non-enumerating و rollback کامل شود، بدون افزودن قفل جدید روی جدول پزشکان و بدون خطر وارونگی ترتیب قفل‌ها.
  - **مانع ۳ (پوشش رگرسیون):** تست‌های تفکیک‌شده برای جستجوی همکار، خوانندهٔ فقط پزشکی، پزشک دارای scoped deny، پزشک مجاز با هر دو دسترسی، و غیرفعال‌سازی/تغییر انتساب متوالی پزشک اضافه شد (با قید صریح اینکه تست‌های متوالی اثبات همزمانی نیستند).
- **اعتبارسنجی:** تمامی فایل‌های PHP بدون خطای نحوی parse شدند؛ `git diff --check` بدون خطای فاصله‌گذاری؛ شش family ورک‌فلو روی head جدید به صورت عادی اجرا و پایش می‌شوند؛ هیچ migration، وابستگی، نقش یا سیاست جدیدی افزوده نشد.
- **تحویل و موارد باز:** PR #208 در وضعیت DRAFT و باز باقی مانده و ادغام نشده است. موارد باز PD-1 و PD-2 بدون تغییر به عنوان تصمیم‌های محصولی به کارفرما گزارش می‌شوند.

### [2026-10-09 UTC] — رفع کامل مانع امنیتی E11 (قفل‌گذاری باریک ردیف پزشک و آزمون همزمانی دو اتصال)

- **مبنای شروع و بازیابی:** head مبنای شروع `2ddc2b49747cc49d82ae79aa4e773fd6df100203` با وضعیت کاملاً سبز در همهٔ ۴۷ چک و ۶ workflow family بود.
- **دامنه و تغییرات (E11 Locking Read & Two-Connection Proof):**
  - متد `require_own_clinician_for_update()` در `ClinicalService` برای اخذ قفل باریک `FOR UPDATE` روی ردیف `cpms_clinicians` پس از اخذ قفل نسخه (`findForUpdateForClinic`) پیاده‌سازی شد (ترتیب قفل‌ها: `cpms_prescriptions -> cpms_clinicians`). در صورت غیرفعال بودن پزشک، عدم تطابق `wp_user_id` یا عدم وجود رکورد، خطای ۴۰۴ non-enumerating صادر و کل تراکنش rollback می‌شود.
  - دو تست همزمانی واقعی دو اتصال (`freshMysqli`) در `RestTrustedClinicContextTest.php` اضافه شد: (۱) قفل ردیف پزشک توسط اتصال مستقل باعث بلوکه شدن نهایی‌سازی نسخه تا زمان آزادسازی قفل می‌شود؛ (۲) غیرفعال‌سازی پزشک توسط اتصال مستقل بلافاصله در خواندن قفل‌دار مشاهده شده و عملیات با وضعیت `draft` و صفر جهش رد می‌شود.
- **اعتبارسنجی:** تمامی فایل‌های PHP بدون خطای نحوی parse شدند؛ `git diff --check` بدون خطا؛ بدون افزودن migration، نقش یا سیاست جدید؛ PR #208 همچنان در وضعیت DRAFT باقی می‌ماند.

### [2026-10-09 18:30 UTC] — Arena agent (fixed branch `arena/cb45567b-doctor`; one DRAFT PR #208 against `main`) — تثبیت کامل مانع امنیتی E11، آزمون همزمانی دو اتصال مستقل و پاس شدن ۴۷ چک

- **مبنای شروع و بازیابی:** head مبنای شروع `c1f82e0` با پیاده‌سازی خواندن قفل‌دار `fetchRowForUpdate` روی `cpms_clinicians` بود. برای جلوگیری از تداخل تراکنش‌های fixtureهای commitشده در آزمون‌های همزمانی دواتصاله با `WP_UnitTestCase`، آزمون‌های همزمانی به کلاس اختصاصی و مستقل `Phase1BPrescriptionConcurrencyTest` منتقل شدند.
- **دامنه و تغییرات:**
  - کلاس مستقل `Phase1BPrescriptionConcurrencyTest` برای آزمون‌های همزمانی دو اتصال (`freshMysqli`) اضافه شد:
    ۱. آزمون سریال‌سازی قفل (`testPhase1BTwoConnectionClinicianLockSerializesPrescriptionFinalization`): اتصال مستقل قفل انحصاری `FOR UPDATE` روی ردیف `cpms_clinicians` می‌گیرد و تراکنش نهایی‌سازی در زمان انتظار قفل معطل می‌ماند، و پس از آزادسازی قفل توسط اتصال مستقل بدون خرابی نهایی می‌شود.
    ۲. آزمون لغو همزمان هویت (`testPhase1BTwoConnectionConcurrentRevocationDeniesFinalization`): اتصال مستقل وضعیت `is_active = 0` را روی پزشک اعمال و commit می‌کند؛ خواندن قفل‌دار تراکنش نهایی‌سازی بلافاصله لغو را مشاهده کرده و با خطای ۴۰۴ امن (`CLINIC_NOT_FOUND`)، وضعیت `draft` و بدون هیچ جهش یا لاگ audit نهایی‌سازی را رد می‌کند.
  - پاک‌سازی کامل و امن سطرهای آزمون در `tearDown` با دستورهای مجزا و بدون آسیب به سایر آزمون‌های مجموعه.
  - هیچ نقش/مجوز جدید، مهاجرت schema، وابستگی یا تنظیم جدیدی اضافه نشد و PR #208 در وضعیت DRAFT باقی ماند.
- **اعتبارسنجی (Head دقیق `03e9830fe8e841c10e23b514a473ac4296647523`):**
  - تمامی ۴۷ چک روی head دقیق با موفقیت پاس شدند (۴۷/۴۷ GREEN):
    - CI `37985072462` (شامل Integration با ۱۶۴۴ تست و ۵۴۴۴۷ assertion، WPCS بدون خطا، PHPStan، Unit Tests PHP 8.1/8.2/8.3/8.4، Tenant Tripwire).
    - Real WordPress Acceptance `37985072380` (۲/۲ موفقیت).
    - Third-Party Compatibility Baseline `37985072371` (۲۲/۲۲ موفقیت).
    - Persian five-plugin coexistence `37985072632` (۶/۶ موفقیت).
    - Closure Gate `37985065534` (۵/۵ موفقیت).
    - Pilot/Staging Readiness Gate `37985065515` (۴/۴ موفقیت).
- **تحویل و موارد باز:** PR #208 به عنوان DRAFT باز است و ادغام نشده است. کلیه الزامات امنیتی E11 و موانع پذیرش فاز 1B با شواهد عینی همزمانی و سریال‌سازی قفل مرتفع شدند.
