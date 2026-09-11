# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | Post-merge sync — checkpoint ادغام‌شده `a385d868` (PR #20 MERGED 2026-09-11T13:09:16Z — ترمیم C7). شواهد پیش‌از‌ادغام: head `6b438238` (هر ۵ workflow کانونی GREEN). SHA history (تاریخی): `3fc5a54` → `becc82f` → `a49b182` (#14 head) → `099b644` (merge) → `248ca10` (PR #17 merge) → `4871f84` (PR #19 merge) → `6b438238` (#20 head) → `a385d868` (merge) |
| **وضعیت Phase 2** | IN PROGRESS — C1..C6 done؛ **C6 CLOSED**؛ **C7 ترمیم‌شده و ادغام‌شده در main (PR #20 MERGED)**؛ بستن رسمی C7 معلق تا تصمیم مالک. قلم بعدی Queue = **C8 (NOT STARTED — scope تعریف‌نشده)** |
| **آخرین remote SHA سبزِ تأییدشده** | `a385d868` (`origin/main`) — هر ۴ گیت پس‌از‌ادغام GREEN (پایین)؛ PR #20 **MERGED** |
| **C6 بسته شده** | ۱۴۰۱/۰۶/۱۹ — با تصمیم مالک/معمار |
| **C7 (پیاده‌سازی)** | ادغام‌شده در `a385d868` از طریق PR #20 (S1..S6)؛ **بستن رسمی/پذیرش مالک مستند نیست** |
| **Schema** | `2026_09_09_0020` — فایل/تصویب 0021 وجود ندارد (بدون تغییر schema در C7) |

> این فایل state جاری است، نه گزارش. عمداً به SHA کامیتِ خودِ این سند ارجاع
> نمی‌دهد — مبنا = checkpoint ادغام‌شده `a385d868` (`origin/main`؛ PR #20 MERGED).
> (جدول‌های زیر که به `099b644`/`248ca10` ارجاع می‌دهند سابقهٔ تاریخی‌اند.) نسب تاریخیِ
> تأییدشده: headهای PR #10 (`79cce4b`) و PR #11 (`9e006b0`) جد خط #14 بودند و
> تاریخچه‌شان در `099b644` ادغام شده است. PHP در sandbox ممیزی روی PATH نبود؛
> شواهد اجرایی = GitHub Actions.

## گیت‌های سبز — پس‌از‌ادغام (روی `a385d868` = `origin/main` جاری)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34602712029 | ✅ success |
| Real WordPress Acceptance | 34602711983 | ✅ success |
| Pilot/Staging Readiness | 34602711956 | ✅ success |
| Closure Gate | 34602711962 | ✅ success |

**head نهایی پیش‌از‌ادغامِ PR #20 (`6b438238`) — هر پنج workflow کانونی سبز:**
CI `34598981613` · Real-WP `34598981627` + `34598978084` · Pilot `34598978102` · Closure `34598978147`.
والدین merge `a385d868`: `4871f84` (mainِ پیش‌از‌ادغام = merge PR #19) + `6b438238` (head).
ادغام توسط `app/arena-ai-coding-agent` (bot) — **تأیید مالک در مخزن مستند نیست**.

## گیت‌های سبز — پس‌از‌ادغام روی `248ca10` (چک‌پوینت قبلی — تاریخی)

| گیت | Run | نتیجه |
|---|---|---|
| CI | 34460364222 | ✅ success |
| Real WordPress Acceptance | 34460364238 | ✅ success |
| Pilot/Staging Readiness | 34460364243 | ✅ success |
| Closure Gate | 34460364219 | ✅ success |

والدین merge: `8087b42` (mainِ پیش‌از‌ادغام = merge PR #9) + `a49b182` (head پیش‌از‌ادغامِ PR #14).

## گیت‌های سبز — batch استحکام tenant (تاریخی، پیش‌از‌ادغام، روی `3fc5a54`)

برچسب داخلی تسک در گزارش‌ها «Phase 9 §5» بود — واژگان تاریخیِ taxonomy وظایفِ داخلی؛
فاز ۹ نقشهٔ راه مالک = Patient Portal و همچنان **NOT STARTED** است.

| گیت | Run | نتیجه |
|---|---|---|
| CI (Unit×4 + PHPStan + WPCS changed‑lines + **Integration** — 602 test، 0E/0F) | 34406996627 | ✅ success (۷ job) |
| Real WordPress Acceptance (ZIP → clean WP → browser؛ prefix `wp_` و `clinic_`) | 34406991487 | ✅ success |
| Pilot/Staging Readiness (Artifact + Responsive smoke + Upgrade + Staging) | 34406991520 | ✅ success |
| Closure Gate (GO‑LIVE evidence) | 34406991334 | ✅ success |

خلاصهٔ batch (جزئیات + شواهد RED تاریخی در `c6-census.md` و `project-current-state.md` §K):

- `f5ebefd` — **product fix:** پنج `clinic_id = 1` در `VisitService` (queue/Today/stats/feed
  watermark) حذف؛ trusted context با fail‑closed؛ دامنهٔ پزشک Clinic‑aware؛ `clinician_id`
  بین‌Clinic ⇒ 404. اثبات red→green: probeهای ۷–۱۶ صف روی `f5ebefd` سبز شدند.
- `f6703dc`/`b7b8399`/`bff690a` — **product fix:** ایزولاسیون Per‑Object فایل بالینی
  (`MedicalFileService` + `ClinicalService::record`)؛ Envelope رد == not‑found (عدم افشای
  وجود)؛ مسیرهای skip‑listed برای کارکنان: Scope → resolver سیستمی → **عضویت فعال یکتا**
  (هرگز «Clinic اول»). Class B Critical (خواندن هر فایل توسط هر staff با `cpms_file_read`)
  برطرف و probeها سبز.
- `c2bff76` — **test(harness):** ریشهٔ آلودگی بین‌کلاسی (نخستین REST‑touch = pin یک‌بارمصرفِ
  سرویس‌ها زیر Scope فیکسچور ⇒ خرابی Export/Reports/Sms/Otp/VisitFlow) با warm خنثی +
  پاک‌سازی دیسک + رفع collision email در `makeUser` بسته شد؛ هیچ کلاس/تست/product دیگری
  تغییر نکرد.

## گیت‌های سبز — checkpoint ترمیم Trusted REST (روی `4289d89`)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + WPCS changed‑lines + **Integration**) | 34388298772 | ✅ success (۷ job) |
| Real WordPress Acceptance (push — prefix `wp_` و `clinic_`) | 34388294483 | ✅ success (2 job، steps_failed=0) |
| Pilot/Staging Readiness | 34388294486 | ✅ success |
| Closure Gate (GO‑LIVE evidence) | 34388294616 | ✅ success (۵ job؛ کامنت‌های evidence روی PR #13 با همین run id) |

### checkpoint بازبینی batch ترمیم (C6 CONTINUATION — review/consolidation)

- **تأیید تاریخچه (read‑only):** `origin/main` = `8087b42` ⊂ `f88fcdc` (head PR #12) ⊂
  `4289d89` ⊂ `4606b15` — زنجیرهٔ خطی `f88fcdc → adecd21 → a23b509 → b7a3a6b → da72e1c →
  4289d89 → 9e5cbcd → 4606b15`؛ PR #13 **دقیقاً** delta ترمیم (۷ کامیت، `+456/−72`،
  `mergeable_state=clean`)؛ PR #12 بی‌تغییر (`f88fcdc`، draft، `blocked`). هیچ FF/merge/close
  و هیچ شاخهٔ راه دوری جابه‌جا نشد (جز کامیت‌های جدید همین batch روی `arena/01a086b4-doctor`).
- **گیت‌های سبز روی `4606b15`:** CI `34390466560` · Real‑WP `34390462202` (wp_/clinic_) ·
  Pilot `34390462154` · Closure `34390462224` — هر چهار run `completed/success`، steps_failed=0،
  rollup PR #13 = 18× SUCCESS. نکتهٔ پوشش: روی `4606b15` رویداد `pull_request` **فقط CI** را
  اجرا کرد و سه گیت دیگر از `push` روی همان SHA سبزند (اجرای PR‑only برای Real‑WP وجود نداشت؛
  چون SHA یکسان بود rerun لازم نبود).
- **چرخهٔ حیات Scope (مرور + تست اول):** پنج invariant قابل‌اجرای تازه در
  `RestTrustedClinicContextTest` — مسیر WP_Error، drain شدنِ جفت‌های pending، حفظ scope
  مشروعِ job/system، A→B/B→A بدون نشت، و تودرتو (`rest_do_request`) — سبز روی `0205089`
  (CI `34393642656`، ۷ job سبز؛ Real‑WP `34393637166` و Closure `34393637150` نیز سبز).
- **تصحیح باریکِ واژگان سند (نظریه، نه product):** «restore تضمینی» به معنای پوششِ
  استثنای callback توسط netِ `shutdown` **نیست**: `respond_to_request()` استثنای
  مهار‌نشدۀ callback را به `WP_Error` تبدیل می‌کند و `rest_request_after_callbacks` پس از
  آن اجرا می‌شود؛ net برای throw/fatal/`exit()` در سطح filter لازم است.
- **پروب C6‑F (فقط تست، روی `5b3768e`) — Class A، عمداً سبز نشده:** `Integration` قرمز با
  `Tests: 568, Assertions: 2951, Failures: 2` (annotation همان run):
  `testStaffCannotFinalizePrescriptionOfAnotherClinic` و `testSmsLogsAreScopedToTheBoundClinic`.
  طبقه‌بندی: **Class A (product)** — (۱) `ClinicalService::finalizePrescription` با
  `PrescriptionRepository::findForUpdate` (`WHERE id` بدون `clinic_id`)؛ (۲) `SmsService::logs`
  بدون فیلتر `clinic_id` روی `cpms_sms_messages`. هر دو test guard ساختاری (Class D) دارند و
  از پاس شدنشان مطمئنیم (شکست روی ادعای جداسازی، نه پیش‌شرط)؛ سایر گیت‌ها روی همین SHA
  سبزند (Real‑WP `completed/success`، Closure `completed/success`). این دو تست به‌عنوان
  «مشخصهٔ معلق» C6‑F روی شاخه می‌مانند و **رفع Product در این batch انجام نشد** (رفع =
  تصمیم Phase 3/C6‑F با مالک).

- branch = `arena/01a086b4-doctor`؛ base تشخیصی = `arena/01a086ca-doctor` (PR #13 — draft،
  برای اجرای گیت‌ها روی continuationِ خطیِ `f88fcdc`؛ **هیچ شاخهٔ راه دوری جابه‌جا نشد**).
- تعداد تست Integration در run سبز از API قابل استخراج نبود (لاگ خام/artifact در sandbox
  مسدود است؛ گام «Run integration tests» = success و no failure‑comment) → **NOT VERIFIED
  به‌صورت عددی**، سبز بودن از check‑run تأیید شده.
- **NOT TESTED locally:** PHP در این sandbox نصب نبود (بدون `php -l`/phpunit محلی)؛ همهٔ
  شواهد = GitHub Actions روی SHA.
- هم‌ترازی docs روی SHA بعدی نیز سبز است: CI `34389431689` · Real‑WP `34389425080` ·
  Pilot `34389425027` · Closure `34389425099` (۱ check‑run = success). این ارجاع برای
  «وضعیت گیت‌ها» است، نه مبنای وضعیت پیاده‌سازی؛ مبنای سندها همان `4289d89` است.
- **بازبین batch ترمیم روی tip `4606b15` (sibling review — فقط docs):** CI `34390466560`
  (۷ job سبز: WPCS · PHPStan · Unit×4 · Integration) · Real‑WP `34390462202` (prefix
  `wp_` و `clinic_`) · Pilot `34390462154` · Closure `34390462224` — هر چهار run
  `completed/success` با `head_sha` درست. PR #12 بی‌تغییر (head `f88fcdc`، draft،
  `mergeable_state=blocked`)؛ PR #13 = دقیقاً delta ترمیم (`+456/−72`، ۷ کامیت،
  `mergeable_state=clean`) — **هیچ merge/FF/close انجام نشد**. نکتهٔ پوشش: روی
  `4606b15` رویداد `pull_request` فقط CI را اجرا کرد؛ سه گیت دیگر از رویداد `push`
  روی همان SHA سبز شده‌اند.
- **معنای چرخهٔ حیات WP (تصحیح باریکِ واژگان، بدون تغییر product):** `respond_to_request()`
  استثنای مهار‌نشدۀ callback را به `WP_Error` تبدیل می‌کند و `rest_request_after_callbacks`
  **پس از** آن اجرا می‌شود ⇒ restore در مسیر خطای callback هم انجام می‌شود؛ netِ
  `shutdown` برای throw/fatal در سطح filter (مجاور بلوک try/catch) یا `exit()` لازم است.
  شاهد قابل‌اجرا: `testErrorResponsePathStillRestoresScope` (restore در مسیر WP_Error)،
  `testPendingPairDrainsAndNextRequestStillRestores` (safety‑net)،
  `testPreExistingExplicitScopeSurvivesRequestAndRestoresAfterwards` (scope مشروع
  job/system پاک نمی‌شود)، `testSequentialClinicRequestsDoNotLeakScope` (A→B/B→A)،
  `testNestedRestDispatchRestoresOuterScope` (تودرتو) — سبز روی `0205089` (CI `34393642656`).

### C6‑F کلاس A — ترمیم دو نقص Cross‑Tenant (شاهد قابل‌اجرا، سبز روی `2d13f2d`)

- **مبنا/پایان:** شروع `41321e2` → `7c4b2bd` (نسخه) → `2d13f2d` (SMS) — ادامهٔ خطی،
  بدون force‑push/amend/rebase (دستور §۰ رعایت شد؛ تخلف batch قبل در همین سند ثبت است).
- **Finding A — High (IDOR بین‌تننتی، جهش):** `POST /clinic/v1/prescriptions/{id}/finalize`
  فقط نقش/Cap را می‌سنجید؛ `PrescriptionRepository::findForUpdate` = `WHERE id` ⇒ هر پزشکِ
  دارای `cpms_rx_create` می‌توانست نسخهٔ Clinic دیگر را **نهایی** کند.
  ریشه: نبودِ مالکیت Per‑Object در **Query Layer**. ترمیم:
  `findForUpdateForClinic(id, clinicId)` + `updateForClinic(clinicId, id, …)` (predicate دیتابیس،
  قابل ایندکس از PRIMARY) و در `ClinicalService::finalizePrescription/voidPrescription`
  Clinic از `App::scope()` (Trusted Scope؛ بدون هیچ id کلاینتی/بدون fallback به Clinic 1)؛
  خطا = `CLINIC_NOT_FOUND` 404 یکسان با «ناموجود» ⇒ **بدون افشای وجود** و **بدون جهش**
  (`affected < 1` ⇒ همان safe not‑found). تست‌ها: A/A مجاز، A/B رد+بدون‌جهش، B/B مجاز،
  بدنهٔ پاسخ یکسان با ناموجود، سوییچ پیاپی A→B→A، غیرعضو → همان 404.
- **Finding B — High (نشت PHI در خواندن):** `GET /clinic/v1/sms/logs` → `SmsController::logs`
  → `SmsService::logs` با `SELECT`/`COUNT` **بدون** `clinic_id` ⇒ هر دارندۀ `cpms_sms_config`
  لاگِ تمام tenantها (موبایل + متن) را می‌دید. ترمیم: `logs(int $clinicId, …)` با
  `WHERE clinic_id = %d` برای **هر دو** COUNT و SELECT (prepared SQL؛ بدون post‑filter در PHP).
  تست‌ها: context A و B، کاربر چند‌عضویت با سوییچ context، Organization دیگر، Clinic خالی،
  پایداری شمارش/ترتیب/صفحه‌بندی، و شهادِ SQL‑level روی لایۀ سرویس.
- **Dedupe (§۶ — تأییدشده، رفعِ schema‑free):** `dedupe_key = sha256(event|ctxType|ctxId|day)`
  و `uq_dedupe` یک UNIQUE **سراسری** ⇒ یک رویداد/Context/روزِ یکسان در دو Clinic، ارسال
  دومی را سرکوب می‌کرد (انکار سرویس، نه نشت داده). هویت Clinic به hash اضافه شد + lookup
  با `AND clinic_id = %d`؛ شهاد: `SmsFlowTest::testDedupeIsScopedPerClinic` + حفظ
  `testDedupePreventsDuplicateContextMessage`. **محدودیتِ از پیش موجود (گزارش، نه رفع):**
  مسیر resend با الحاق `-{id}` به کلید ۶۴تایی، در ستون `CHAR(64)` truncate می‌شود؛ رفعش
  به schema نیاز دارد ⇒ **OPEN، بدون Migration**.
- **ایندکس (مشاهدۀ کارایی، بدون تغییر schema):** `cpms_sms_messages` هیچ ایندکس `clinic_id`
  ندارد (اینکس‌ها: `uq_dedupe`, `ix_status_updated`, `ix_event_created`, `ix_context`).
  کوئری جدید **درست** است (tenant predicate + فیلتر روی PRIMARY‑order) ولی روی جدول بزرگ
  scan می‌کند. **هیچ Migration ساخته/تصویب نشد و 0021 لازم نبود** — در صورت درخواست
  بهین‌سازی: `KEY ix_sms_clinic (clinic_id, id)` با تأیید Owner.
- **گیت‌ها روی `2d13f2d`:** CI `34396622019` (۷ job سبز: WPCS · PHPStan · Unit×4 · **Integration**) ·
  Real‑WP `34396616394` (`wp_` و `clinic_`) · Pilot `34396616498` · Closure `34396616419`
  — همه `completed/success`. روی `7c4b2bd` (فقط Fix A) قرمزِ باقی‌مانده دقیقاً یک تستِ SMS بود
  (`Tests: 571, Assertions: 2994, Failures: 1`) و Real‑WP/Closure/Pilot سبز. **RED تاریخیِ
  `5b3768e`/`41321e2` (`Tests: 568, Assertions: 2951, Failures: 2`) پاک نشده است.**
- **هیچ تست امنیتی skip/skip‑soft/xfail نشد؛ هیچ gate‌ای تضعیف نشد؛ skip list تغییر نکرد؛
  Phase 3/`AuthorizationService` شروع نشد؛ PR#10..#13 دست‌نخورده.**
- **باقی‌ماندۀ C6 (بدون تغییر):** Tripwire→CI · C6‑F جامع (۱۴‑موردی) · طبقه‌بندی/تصمیم
  D‑route‌ها (از جمله `files/{id}/stream` که در skip list است و read‑path بالینی‌اش هنوز
  Per‑Object check ندارد) · workflow onboarding عضویت staff · **C6 = IN PROGRESS** (در آن مقطع؛ اکنون CLOSED — رجوع به بالای سند).

### سابقه (روی `6e5d48c` — C6 Reports+Export+Pilot)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + **WPCS line-scoped**) | 34375762362 | ✅ success |
| Real-WP (PR) | 34375762425 | ✅ success |
| Real-WP (push) | 34375756020 | ✅ success |
| Pilot/Staging | 34375756043 | ✅ success |
| Closure | 34375756075 | ✅ success |

### سابقه (روی 9e006b0 — C6-E2)

| گیت | Run | نتیجه |
|---|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | 34352114628 | ✅ success |
| Real-WP (PR) | 34352114325 | ✅ success |
| Real-WP (push) | 34352109691 | ✅ success |
| Pilot/Staging | 34352109661 | ✅ success |
| Closure | 34352109682 | ✅ success |

### سابقه (روی 315e582 — C5 کامل)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration 527 تست + **WPCS line-scoped**) | 34336052376 | ✅ success |
| Real-WP (PR) | 34336052355 | ✅ success |
| Real-WP (push) | 34336047913 | ✅ success |
| Pilot/Staging | 34336047922 | ✅ success |
| Closure | 34336047920 | ✅ success |

### سابقه (روی 41b24dc — C4 کامل)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + WPCS line-scoped) | 34330257136 | ✅ success |
| Real-WP (PR) | 34330256991 | ✅ success |
| Real-WP (push) | 34330252423 | ✅ success |
| Pilot/Staging | 34330252351 | ✅ success |
| Closure | 34330252342 | ✅ success |

### سابقه (روی db35873 — C3)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + WPCS changed-files) | 34316294584 | ✅ success |
| Real-WP (push) | 34316289951 | ✅ success |
| Real-WP (PR) | 34316294518 | ✅ success |
| Pilot/Staging | 34316289927 | ✅ success |
| Closure | 34316290002 | ✅ success |

### سابقه (روی f23e0c5)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration) | 34309588163 | ✅ success |
| Real-WP (push) | 34309588103 | ✅ success |
| Real-WP (PR) | 34309583290 | ⚠️ functional ✓ — فقط آپلود artifact شکست (**Class C infra**)؛ rerun/dispatch توسط GitHub/token رد شد؛ دوقلوی push همان SHA کامل سبز. بازبینی با event بعدی |
| Pilot/Staging | 34309583258 | ✅ success |
| Closure | 34309583310 | ✅ success |

## Current substep

- **C6 — CLOSED (Owner/Architect approved technical closure).** Implementation evidence: `3fc5a54` (pre-merge); **integrated checkpoint: `099b644`** (`origin/main`, PR #14 MERGED).
  - ✅ C6-A..E2 (census, Notif/SMS/Jobs, Booking/Schedule, Patients/Clinical/Visits/Files, Settings/Audit/Idempotency + Migration 0020, repo writes)
  - ✅ C6-E3 Reports (`1c82d26` + Class D `a120a68`)
  - ✅ C6 Export (`f2c0ca6`) — clinic in payload; purge per-row
  - ✅ Pilot/bin (`6e5d48c`) — no literal clinic_id=1
  - ✅ Tripwire production runtime = 0 (34 self-tests) — CI enforced
  - ✅ Trusted REST ScopeContext (membership-verified) — fail-closed
  - ✅ C6-F isolation — 45/45 VERIFIED_GREEN (MT-39 runtime)
  - ✅ All quality gates GREEN on 3fc5a54
  - ✅ No known Critical/High C6 tenant-isolation defect

### C6 Deferred Boundaries (NOT C6 scope — recorded for transparency)

| ID | Item | Decision | Notes |
|---|---|---|---|
| A | Final role/capability policy | DEFERRED to Phase 3 | Scope beyond tenant isolation |
| B | Staff onboarding UI/API | DEFERRED to LATER_STAFF_ADMIN_UX | Global WP role MUST NOT auto-create Membership |
| C | SMS resend CHAR(64) truncation | KNOWN_MEDIUM_DEBT | Schema-dependent; not C6 blocker |
| D | Historical closure hash mismatch | EXPECTED_NEGATIVE_TEST | Not a defect |

- **C7 — MERGED (implementation).** ترمیم C7 (S1..S6) از طریق **PR #20 MERGED**
  در `a385d868` به main یکپارچه شد؛ زنجیرهٔ RED→GREEN و Run IDها در
  [`c7-0-census.md`](c7-0-census.md) §۱۱ و وضعیت جاری در
  [`project-current-state.md`](../project-current-state.md) §C.
  **بستن رسمی/پذیرش مالک برای C7 در مخزن مستند نیست** ⇒ «پیاده‌سازی ادغام‌شده، بستن رسمی معلق».
  بدون Migration (0021 ساخته/تصویب نشد)؛ Phase 3 شروع‌نشده؛ اقلام S2/S3 و
  Jobs/SMS/timezone و UX چندکلینیکی wp-admin **به تعویق افتاده و تصویب نشده‌اند**.
- **C8 — NEXT (NOT STARTED).** قلم بعدی Queue طبق همین سند. scope/پذیرش تعریف‌نشده.
  **قاعدهٔ تفسیر (برای تداوم):** وجود برچسب داخلی/canonical «C8» به‌تنهایی
  اثبات‌کنندهٔ نیاز به کار پیاده‌سازی **نیست**. بر پایهٔ شواهد جاری (پاس بازبینی
  فقط‑خواندنی) **هیچ شکاف پیاده‌سازیِ تأییدشده‌ای در invariantهای فاز ۲ حوزهٔ
  Location وجود ندارد** ⇒ C8 در حال حاضر یک **بستهٔ شواهد/مستندسازی/closure**
  است، نه مجوز خودکار پیاده‌سازی. داده‌های استان/شهر ایران و UX مدیریت Master Data
  ⇒ **Owner Phase 4**؛ مجوزدهی scoped نهایی ⇒ **Phase 3**؛ مصرف عملیاتی
  `locations.timezone` (Scheduling/Reminder/DST) ⇒ **مرز ثبت‌شده** و در این PR
  مجوز پیاده‌سازی نیست. **بدون Migration.**
- **Do not start:** C8 (until scope is defined and Owner-approved), Phase 3, Migration 0021.

### سابقهٔ C5 (خلاصه) — Patient Identity Foundation: ✅ کامل (هر ۵ گیت سبز روی `315e582`)
  (کامیت‌های `9207afa` → `2ba16d7` → `315e582` + کامیت docs این واحد):
  - **نرمال‌سازی موبایل ایران** (`9207afa`): ارقام فارسی/عربی → ASCII
    (`fold_digits`)، فرم‌های 98+صفرِ میان‌شهری (۱۳/۱۵ رقمی)، و فیکس مستند شاخهٔ
    معیوب ۱۲رقمی `9809` (خروجی خراب `00...` می‌ساخت → حالا null). تست‌های
    table-driven واحد (۲۳ case).
  - **فوندیشن هویت** (`2ba16d7`): Migration 0019 (`normalized_mobile` غیر یکتا +
    ایندکس `idx_identity_org_mobile` + جدول `cpms_patient_identity_links`)،
    `PatientIdentityException` (`CLINIC_PATIENT_IDENTITY_*`)،
    `PatientIdentityRepository` (همهٔ lookupها org-scoped)،
    `PatientIdentityService` (create/lookup/duplicate_candidates/set_mobile/
    link_clinical_record/clinical_records_for_clinic/link_user/
    identities_for_user؛ fail-closed + anti-enumeration)،
    `App::patient_identity_service()`، تست integration جدید (ماتریس ۲۰بندی
    مالک؛ OTP OD-8 هم دوباره اثبات شد).
  - **فیکس Class D ×4** (`315e582`): expectationهای نسخهٔ schema در
    Phase2SchemaTest/TempTableIsolationTest و سه workflow گیت (pilot/closure/
    real-wp) هاردکد `0018` بودند → `0019`؛ assertion EXPLAIN به «بدون full-scan
    + ایندکس org-scoped» تعدیل شد (انتخاب بین دو ایندکس org-scoped با optimizer
    است — روی دادهٔ کوچک تست، ایندکس کوتاه‌تر را برمی‌گزیند).
  - **داکیومنت** (این کامیت): target-model (یادداشت as-built C5 + نرمال‌سازی)،
    data-dictionary (بخش as-built هویت)، erd.md (اشاره‌گر)، ADR-0031 (وضعیت
    پیاده‌سازی identity)، phase1b-deferred-register (B-14 ✅ / B-17 روشن‌سازی)،
    state.

### سابقهٔ C4 (خلاصه) — کد/تست/داکیومنت
  (کامیت‌های 98ca0ff..91e5fe4) + WPCS-cleanup (کامیت‌های 67e1208..41b24dc):
  بازنویسی سه فایل جدید در سبک WordPress بدون تغییر منطق (snake_case؛
  `App::membership_service()`)، پالایش `phpcs.xml.dist` (پیشوند ClinicCore +
  هفت exclusion مستند)، ارتقای گیت WPCS به **line-scoped** (فقط نقض روی
  خطوط add شده نسبت به baseline می‌شکند؛ legacy مثل App.php با ~۶۵۰ نقض
  تاریخی فقط روی خطوط جدیدش سنجیده می‌شود). دو باگ ابزارِ گیت در راه
  رفع شد (هر دو Class D، با annotation/کامنت قابل‌مشاهده شدند): پیشوند
  progress در خروجی `--report=json` (ruleset `sp`) که JSON را می‌شکست
  (run 34328451996 → فیکس parse در 1364c37) و newlineِ `print()` خالی که
  گیت را روی اجرای سبز قرمز می‌کرد (run 34329655919 → فیکس در 41b24dc).
  اعتبارسنجی لوکال پیش از push: رانر phpcs داخل php-wasm با درخت
  وابستگی resolution مورد انتظار CI (wpcs 3.4.1 + phpcs 3.13.6 +
  PHPCSUtils 1.2.3 + PHPCSExtra 1.5.1) — ۰ نقض روی ۸۶۰ خط add شده؛
  Universal/NormalizedArrays از PHPCSExtra می‌آیند (نه خود phpcs).

### رویداد توکن (ثبت برای handoff)

- پس از push موفق 91e5fe4 (خروجی git push تأیید شد: `005c2ba..91e5fe4`)،
  توکن GitHub با 401 رد شد (مانند رویداد مشابه قبلی در همین فاز که
  خودش بازیابی شد). retry محدود انجام شد؛ credential دستکاری نشد.
  وضعیت ریموت = 91e5fe4 (push پیش از قطعی پذیرفته شده).
  وضعیت: توکن بازیابی شد؛ runهای 91e5fe4 بررسی شدند (WPCS = 34318905233،
  نقض‌های واقعی کد C4 → فیکس در کامیت بعدی).

## Done substeps (این فاز)

- **C4 — Membership primitives**: ✅ (کامیت‌های 98ca0ff..91b24dc؛ جزئیات در
  Current substep و drift-register §۸-۱ و یادداشت C4 در target-model).
- **C1..C2 + Recovery**: کامیت‌های 1f8b36d..f23e0c5 (شرح کامل: کامیت‌ها و
  Migration Foundation Recovery Report در PR #11).
- **C3 — WPCS regression gate**: ✅ سبز (کامیت‌های b6f6c93..db35873).
  `phpcs.xml.dist` + job `wpcs` (استراتژی changed-files از baseline
  f23e0c5). سه تلاش Class C/D در راه: advisoryهای رجیستری روی phpcs 3.11.x
  و wpcs 3.1.0 (→ دامنهٔ ^3.1 + فیلتر امنیتی composer)، allow-plugins، و
  باگ f-string در expansion. جزئیات: drift-register §۸-۱.

## Queue (صف داخلی زیربسته‌های Phase 2 — LEVEL 2)

> **Provenance (ثبت‌شدهٔ صریح):** این صف، صفِ **داخلی زیربسته‌های Phase 2** است که در
> همین سند ثبت شده و `docs/governance/project-phase-taxonomy.md` آن را به‌عنوان
> زیرفازهای **LEVEL 2** فاز ۲ («تابعِ فاز مالک») تأیید می‌کند. **شواهد مخزن،
> تأیید صریح مالک بر این ترتیب دقیق `C4…C10` را اثبات نمی‌کند** (تنها annotation
> تأیید صریح در کامیتِ معرفیِ همین صف — `b6f6c93` — مربوط به C3/WPCS است، نه به
> این ترتیب). این صف **تابعِ** Owner Roadmap Phase 0..20 است و بر آن غلبه نمی‌کند؛
> تا هرگونه تصریح مالک، همین ترتیب به‌عنوان صف جاری معتبر می‌ماند.

C4 Membership primitives → C5 Patient Identity foundation → C6 حذف
tenant hardcodes (census تازه از HEAD) → C7 Repository/Service isolation
(multi-org واقعی) → C8 Iran Location foundation → C9 i18n audit →
C10 Performance review → End Gate (۲۶بندی) → STOP.

## Open blockers

- هیچ.

## نکات اجرایی پابرجا

- هر واحد: CODE+TEST+DOC → atomic commit → push → monitor حداقل CI مربوط.
- هر push: verify remote SHA + ثبت run IDs در همین فایل.
- sandbox بدون PHP/MySQL — تنها اجرای واقعی = GitHub Actions؛ /tmp
  disposable؛ هر rebuild sandbox: `git fetch origin <branch>` + `git reset
  FETCH_HEAD` (mixed) — غیرممنوع و اثبات‌شده.
