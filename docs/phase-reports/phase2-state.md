# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | بر مبنای SHA پیاده‌سازی `6e5d48c801e86779c0daf350d68c9df49e49a6c8` |
| **وضعیت Phase 2** | IN PROGRESS — C1..C5 done؛ C6 **ناقص** (Reports/Export/Pilot انجام؛ REST Scope هنوز نه) |
| **آخرین remote SHA سبزِ تأییدشده** | `6e5d48c` (هر ۵ گیت؛ PR #12 OPEN DRAFT — ادغام ممنوع) |
| **Schema** | `2026_09_09_0020` — فایل/تصویب 0021 وجود ندارد |

> این فایل state جاری است، نه گزارش. عمداً به SHA کامیتِ خودِ این سند ارجاع
> نمی‌دهد — مبنا = SHA پیاده‌سازی `6e5d48c`. شاخهٔ ادامه:
> `arena/01a086ca-doctor`. نسب تأییدشده: headهای PR #10 (`79cce4b`) و
> PR #11 (`9e006b0`) جد همین SHA هستند. PHP در sandbox ممیزی روی PATH نبود؛
> شواهد اجرایی = GitHub Actions.

## Last known good gates (روی 6e5d48c — C6 Reports+Export+Pilot)

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

- **C6 — حذف tenant hardcodes: ناقص (نه PASS).** روی `6e5d48c`:
  - ✅ C6-A..E2 (census، Notif/SMS/Jobs، Booking/Schedule، Patients/Clinical/Visits/Files، Settings/Audit/Idempotency + Migration 0020، repo writes)
  - ✅ C6-E3 Reports (`1c82d26` + Class D `a120a68`)
  - ✅ C6 Export (`f2c0ca6`) — clinic در payload جاب؛ purge per-row
  - ✅ Pilot/bin (`6e5d48c`) — بدون literal clinic_id=1
  - tripwire production runtime = **۰** (اجرای محلی ابزار؛ **هنوز به CI وصل نشده**)
  - ❌ Trusted REST `ScopeContext` (membership-verified) — **پیاده نشده**
  - ❌ C6-F isolation جامع — **PARTIAL**
  - C6-G docs: این هم‌ترازی وضعیت است، نه اعلام اتمام C6
- **قدم بعدی مجاز پس از هم‌ترازی docs:** حداقل مرز Trusted REST Clinic context
  (نه Tripwire-CI، نه C6-F کامل، نه C7/C8/Phase 3).
- **شروع نشود:** C7، C8، Phase 3/`AuthorizationService`، Phase 4، پورتال‌ها،
  JWT، Migration 0021.

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

## Queue (ترتیب مصوب مالک)

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
