# HANDOFF — Phase 2 / C6 → Agent بعدی

**سند canonical تحویل (Session Closure — Arena `01a086b4`).**
وضعیت مرجعِ پیاده‌سازی: **`c2bff76d1e21643a66bc0056a29881faaa2f299f`**

> ⚠️ این سند ادعا است، نه اثبات؛ هر بند از خود مخزن/Git/CI قابل راستی‌آزمایی است.
> Agent بعدی موظف است پیش از هر تغییر، بخش «راستی‌آزمایی» را اجرا کند.
> **مرجع حافظه:** `docs/project-current-state.md` §K + `docs/phase-reports/phase2-state.md`
> + `docs/phase-reports/c6-census.md` §۱۲. این سند تکرارِ فشرده و بازیابی‌پذیرِ همان state است —
> عمداً به SHAِ کامیتِ خودِ این سند ارجاع نمی‌دهد.

**نام‌گذاری:** برچسب‌های «Phase 9 §1..§5» در بعضی کامیت/گزارش‌ها **taxonomy وظایف داخلیِ قدیمی**
است. **فاز ۹ نقشهٔ راه مالک = Patient Portal و STILL NOT STARTED است.** وضعیت معتبر:
**Phase 2 / C6 — IN PROGRESS**.

> **Governance فازبندی:** برای سلسله‌مراتب کامل (Owner Phase 0..20 = authoritative؛
> `C6` = بستهٔ کاری داخل **Owner Phase 2**؛ سامانه‌های تاریخی/Legacy) و crosswalk و قواعد
> نام‌گذاری، به [`docs/governance/project-phase-taxonomy.md`](../governance/project-phase-taxonomy.md)
> مراجعه کنید. عبارتِ تنها «Phase N» فقط به Owner Roadmap اشاره دارد؛ «Phase 9» داخلی ≠ Patient Portal.

---

## ۰. راستی‌آزمایی (اولین کار Agent جدید)

```bash
git log --oneline -1 origin/arena/01a086b4-doctor          # باید c2bff76 (یا docs-tip بعدی) باشد
git rev-list --left-right --count origin/main...origin/arena/01a086b4-doctor   # انتظار: 0 <n>
gh pr view 13 --json state,isDraft,headRefOid              # OPEN DRAFT، head = c2bff76
gh pr view 12 --json state,isDraft,headRefOid              # OPEN DRAFT، head = f88fcdc
gh api repos/bia2on2on/doctor/actions/runs/34406996627 -q '.status + " " + .conclusion'   # completed success
```

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| Branch کاری | `arena/01a086b4-doctor` — tip **`c2bff76`** (local == remote در زمان تنظیم این سند) |
| `origin/main` | `8087b42e19a1721e38eb073aa1a17aff1cbac97b` — **دست‌نخورده** |
| فاصله | ۹۹ ahead / ۰ behind نسبت به `main` |
| نسب تأییدشده (`merge-base --is-ancestor`) | `8087b42` ⊂ `f88fcdc` (head PR #12) ⊂ `4289d89` ⊂ `4606b15` ⊂ `ddca8d7` ⊂ … ⊂ `c2bff76` — **خطی؛ بدون merge/rebase/force-push/cherry-pick** |
| PR #12 | `arena/01a086ca-doctor` — OPEN DRAFT — head `f88fcdc` — merge/close ممنوع |
| PR #13 | base `arena/01a086ca-doctor` — OPEN DRAFT — head `c2bff76` — merge/close ممنوع |
| Tags / Releases | هیچ‌کدام ساخته نشد؛ version bump نشد |
| Schema | `2026_09_09_0020` — **هیچ Migration 0021 وجود ندارد و تصویب نشده** |

## ۱. گیت‌های سبزِ نهایی — همه روی `c2bff76`

| گیت | Run | نتیجه |
|---|---|---|
| CI = Unit×4 (PHP 8.1–8.4) + PHPStan + **WPCS changed-lines** + **Integration** | `34406996627` | ✅ ۷ job success — Integration: کل suite سبز (۶۰۲ تست، ۰ خطا/۰ شکست) |
| Real WordPress Acceptance (ZIP → clean WP → browser؛ prefix `wp_` و `clinic_`) | `34406991487` | ✅ success |
| Pilot/Staging Readiness (Release Artifact · Responsive smoke · Upgrade path · Staging Gate) | `34406991520` | ✅ success |
| Closure Gate (GO‑LIVE evidence closure) | `34406991334` | ✅ success |

سیاست سبزبودن: هیچ PASSی از jobهای partially-pending گزارش نشد؛ هر run در حالت terminal خوانده شد.

## ۲. آنچه این session بست (جمع‌بندی ادعاهای product — همگی با شاهد اجرایی red→green)

1. **Visit queue/Today/Feed tenant correction** (`f5ebefd`): پنج literalِ `clinic_id = 1` در
   `VisitService::today/eventsSince/lastEventId` (فراخوانی‌های `queueFor/statsFor/eventsSince/
   lastEventId` با `1`) حذف شد؛ دامنه حالا از trusted context می‌آید (Scope صریح ← Resolution
   «تنها Clinic» ← `CLINIC_SCOPE_REQUIRED` 400). دامنهٔ پزشک: OWN profile **در همان Clinic**؛
   نبودِ پروفایل ⇒ مجموعهٔ خالی؛ `clinician_id` بین‌Clinic ⇒ 404.
2. **Clinical file isolation correction** (`f6703dc` + `b7b8399` + `bff690a`):
   `/files/{id}/stream` با Cap سراسری `cpms_file_read` و بدون هیچ tenant check، فایل هر Clinic
   دیگری را با ۲۰۰ و بایت واقعی می‌داد (Class B Critical — شاهد: runهای `34403028668`/
   `34403805829`/`34404449199`). حالا: guard Per‑Object در `MedicalFileService` (stream /
   staffFiles / softDelete / store) **پیش از** هر disk read/write؛ cross-tenant و
   cross-Organization ⇒ دقیقاً همان Envelope «یافت نشد» (CLINIC_NOT_FOUND/404، صفر بایت محتوا،
   دلیل فقط در Audit). برای مسیرهای skip-listed: staff ⇒ Scope → system resolver → **unique
   active membership** (`TrustedClinicEstablisher`)؛ هیچ «اولین Clinic» نیست.
   `ClinicalService::record()` هم visit/patient را به Clinic مورد اجازه قید کرد.
3. **Trusted REST repair / prescription / SMS log / SMS dedupe** — در batch‌های قبلی همین خط
   (`f88fcdc…4289d89` و `2d13f2d/7c4b2bd`)؛ جزئیات: `project-current-state.md` §K.
4. **Invariants اجرایی‌شده (۲۵ probe در `tests/Integration/ClinicTenantIsolationTest.php`):**
   patient own-file ✓ · patient→other-patient 404 ✓ · staff cross-Clinic 404 ✓ ·
   multi-membership context-A denied / context-B allowed (تفاوت فقط هدر) ✓ · cross-Org denied ✓ ·
   missing vs inaccessible indistinguishable ✓ · denied stream zero clinical bytes ✓ ·
   sequential A→B→A no leak ✓ · id-manipulation / traversal ✓ · Today/Feed exact-domain (id ≠ 1) ✓ ·
   sole-membership resolution ✓ · ambiguous ⇒ 400 ✓ · `doctor/today` never unions ✓ ·
   cross-clinic `clinician_id` ⇒ 404 ✓.
5. **Fixture-pollutionِ همین session بسته شد (این session، نه Agent بعد):**
   ریشه = `App::boot()`/`rest_api_init` سرویس‌ها را **یک‌بار در هر پروسه** داخل closureهای route
   capture می‌کنند؛ این کلاس (الفبا: `ClinicTenantIsolation` < `ClinicalFlow`) نخستین
   REST‑touch پروسه بود و warm زیر Scope فیکسچور ⇒ pin به Clinic جعلی ⇒ خرابی
   `ExportClinicIsolation/ReportsAuthz/SmsFlow/OtpFlow×2/OtpSecurity/VisitFlow`
   (شاهد red: run `34404449199`). رفع در `c2bff76`: warm خنثی **پیش از** ساخت Clinicها +
   unlink آرتیفکت‌های دیسکی در purge + رفع باگ واقعی `makeUser` (email ثابت ⇒ `(int) WP_Error`).
   هیچ تست/سرویس دیگری تغییر نکرد؛ victimها سبز شدند (run `34406996627`).

## ۳. باقی‌ماندهٔ C6 (فقط همین‌ها — چیزی را از این فهرست کم/زیاد نکن بدون مدرک)

1. **Suite جامع C6‑F (۱۴‌بندی کامل)** — این session یک subset متمرکزِ فایل/صف را executable کرد؛
   بقیه باز است.
2. **Tripwire → CI wiring** — عمداً در این session شروع **نشد**؛ اسکریپت `bin/tenant-tripwire.py`
   هنوز allowlist‌محور و خارج از CI است.
3. **OPEN DECISION — طبقه‌بندی routeها:** `/prescriptions` skip، `/appointments/{id}/reschedule`
   vs `/cancel`، `GET /visits{,/{id}}` skip، `/files/{id}/stream` + `/patients/{id}/files`
   ownership-only، `/config/services*`، `/sms/*`. (این batch skip list را در هیچ جهتی تغییر نداد.)
4. **Staff membership onboarding** — باز (هیچ provisioning خودکارِ Membership در product نیست؛
   `MembershipService::create_membership()` تنها API است و production caller ندارد — شکاف
   طبق `docs/architecture/phase0.5-target-model.md` footnote ۵).
5. **Observation (نه تعهد): index clinic-leading** برای queryهای فهرستِ attachmentها — بدون
   تغییر مدرک، فقط مشاهده باقی می‌ماند؛ predicateهای جدید روی PRIMARY سوارند.

## ۴. خط قرمزها (همچنان برجا)

- Phase 3 / `AuthorizationService`، C7، C8، Phase 4+، Portalها، mobile/JWT،
  SMS index migration، resend schema: **شروع ممنوع**.
- **Migration 0021 / هر schema change**: بدون تأیید مالک و بدون مدرک، ممنوع.
- `main`، PRهای #10–#13 (merge/close)، tag/release/version bump: دست‌نخورده.
- Skip list `RestClinicContext`: در هیچ جهتی نه برای «سبزکردن» بلکه برای رفع نقص — نیازمند
  تصمیم معماری جداگانه است.
- هیچ security تستی هرگز skip/quarantine/weaken نشد؛ انتظار output هرگز به رفتار غلط product
  تطبیق داده نشد.

## ۵. نکتهٔ دامنهٔ harness که Agent بعد باید بداند

- **هر کلاس جدیدی که پیش از `ClinicalFlowTest` در ترتیب الفبایی، `rest_do_request` را لمس کند،
  پینِ `boot()` سرویس‌ها را زیر Scope خودش می‌برد** و کل Suite را آلوده می‌کند (بند ۲.۵). الگوی
  صحیح: warm در Scope خنثی **قبل** از ساخت fixture-Clinicها؛ پاک‌سازی دیسک صریح؛ email‌های
  یکتا؛ ردیف‌های shared (`clinic_id=1`) دست نزن.
- `rest_do_request` روی مسیرِ match‌نشده `WP_Error` می‌دهد — هرگز `get_status()` بدون گارد؛
  `WP_REST_Response` متد `get_header()` ندارد (`get_headers()`).
- `%i` placeholder در `wpdb::prepare` وجود ندارد؛ `u_clinician_user` UNIQUE است — هر Clinician
  ردیفِ wp_user_id یکتا/`%d` می‌خواهد.
- sandbox محلی بدون PHP/Composer است؛ شواهد اجرایی فقط GitHub Actions (push روی `arena/**`).

## ۶. بعدیِ ایمن (اگر مالک ادامه داد)

**ادامهٔ suite جامع C6‑F** از آیتم‌های دست‌نخوردهٔ ۱۴‌بندی (مثلاً Billing/Finance و Notification
per-object read-path) با همان پروتکل: probe red → class‑A/B تفکیک → fix اتمیک → گیت کامل.
Tripwire→CI و تصمیم routeها **منتظر دستور صریح مالک** است.

---

### بایگانیِ شواهد (red checkpoints — تاریخچه پاک نشد)

| Run | SHA | محتوای شاهد |
|---|---|---|
| `34403028668` | `d5072ff` | 20 probe failure = اثبات نشتِ فایل/صف (product Class B) |
| `34403805829` | `f5ebefd` | probeهای صف سبز؛ 11 فایل قرمز |
| `34404449199` | `b7b8399` | 8/11 فایل سبز؛ 6 آلودگیِ بین‌کلاسی (Class D — بسته‌شده در `c2bff76`) |
| `34405141498` | `bff690a` | 1 probe قرمز (email collision harness) + آلودگی باقی‌مانده |
| `34406996627` | `c2bff76` | ✅ **همه سبز** |
