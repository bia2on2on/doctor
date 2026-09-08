# CPMS PROFESSIONAL ADMIN UX COMPLETION REPORT

---

## ۰.ج — FINAL CLOSURE (Authoritative — supersedes §0.الف / §0.ب)

> **این بخش مرجعِ نهایی و authoritative است و تمام verdictهای تاریخی (remediation اول، collapse، search) را به‌عنوان سابقه حفظ می‌کند، بدون اینکه با وضعیت نهایی تناقض داشته باشند.** Product Owner بازبینیِ تصویریِ نهایی را انجام و تأیید کرده است؛ بنابراین گیت تصویری بسته و **`ADMIN_UX_READY`** صادر می‌شود.

| مورد | مقدار (exact) |
|---|---|
| Repository | `bia2on2on/doctor` (origin = https://github.com/bia2on2on/doctor.git) |
| origin/main (full 40) | `38c573bf2c74814cdb5897e1a260081f8a07e7f1` |
| Feature branch | `arena/01a07d25-doctor` |
| **PRODUCTION-TESTED SHA** | **`c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1`** |
| FINAL HEAD (docs-only closure) | = tip شاخه که همین سند را حمل می‌کند (production code بدون تغییر = `c7eb3c8`) |
| Local production HEAD (قبل از docs commit) | `c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1` |
| Remote feature branch | **LOCAL == REMOTE ✅** (tip = کامیتِ docs این سند؛ production tip = `c7eb3c8`) |
| Merge-base (HEAD vs origin/main) | `38c573bf2c74814cdb5897e1a260081f8a07e7f1` == origin/main |
| Ahead / Behind vs origin/main | **۰ behind / ۳۳+ ahead** (۳۳ production + کامیت‌های docs) |
| PR | **#9** — `OPEN` / `isDraft=true` |
| mergeable / mergeStateStatus | `MERGEABLE` / **`CLEAN`** |
| Reviews / required checks | `reviews=[]`؛ هیچ required check از ruleset `Protect main` (required_approving_review_count=0، contextهای required_status_checks=هیچ) |
| Merge انجام شده؟ | **خیر — صریحاً merge نشده؛ undraft نشده. STOP برای تصمیم PO.** |
| Official ZIP | `clinic-practice-management-1.0.0.zip` (whitelist-only؛ شامل assets/source production) |

### ۱) Product Owner Visual Acceptance Record — **PASS**

- **`PRODUCT_OWNER_VISUAL_ACCEPTANCE = PASS`** و **`ADVANCED_PERMISSIONS_SEARCH_VISUAL_PO_REVIEW = PASS`**.
- PO **واقعاً تصاویر را بررسی کرد** (نه صرفاً وجود artifact) در Run `34204647193` (push، روی SHA `c7eb3c8`) و در پاسخ صریح تأیید کرد.
- Items تأییدشده: Advanced Permissions Search («نسخه») در Mobile/390/768 — فقط گروه و permissionهای match؛ گروه‌های نامرتبط و ردیف‌های نامرتبط hidden؛ بدون checkbox wall؛ No Result + بدون granular checkbox + پیام فارسی «دسترسی‌ای مطابق جستجوی شما پیدا نشد.»؛ Clear → بازگشت Group Summaryها + بدون checkbox؛ 360px/390px/768px بدون clipping/overflow؛ «نتیجه: N مورد» طبیعی؛ فارسی/RTL تمیز.
- Acceptanceهای قبلی مورد تأیید مجدد PO: Staff Mobile responsive، Doctors Mobile responsive، Patient Management، Schedule Desktop/Tablet/Mobile، Confirmation Modal Desktop/Tablet/Mobile، Advanced Permissions Collapsed/initial/Group/Search.
- **این گیت تصویریِ Hard Acceptance را می‌بندد؛ دیگر هیچ blocker تصویری وجود ندارد.**

### ۲) Git / Remote State (مستقیماً از source)

- Working tree: **بدون tracked modified**؛ دو فایل untracked (همین گزارش + `docs/pre-merge-verification-pr8.md`) که در ZIP ریلیز نمی‌روند و **هیچ‌کدام production code نیستند**.
- Stash: **۰** entry.
- `docs/pre-merge-verification-pr8.md` **به PR #8 تعلق دارد** (remediation نصب real + گیت RWP، که اکنون **MERGED** است) و **unrelated به PR #9** است → **DO NOT COMMIT**؛ حذف نمی‌شود.
- LOCAL feature == REMOTE feature ✅؛ هیچ local-only production work باقی نمانده.

### ۳) FINAL HEAD vs PRODUCTION-TESTED SHA

- **A. PRODUCTION-TESTED SHA = `c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1`** — آخرین SHA که production changes (search fix) را دارد و: Official ZIP از آن ساخته شد (Release Artifact روی همین SHA)، full CI اجرا شد (کلیه ۱۷ چک)، Real WordPress Acceptance اجرا شد (`307/0` در دو prefix)، و اسکرین‌شات‌های آن توسط Product Owner PASS شدند.
- **B. FINAL HEAD = SHA پس از documentation-only commit** (فقط همین فروارد مستند). **هیچ production code تغییری در این کامیت نیست.** بنابراین production ZIP/CI/RWP متعلق به `c7eb3c8` است و سر همان SHA معتبر است؛ باید این دو در گزارش تفکیک بمانند.

### ۴) Final CI State (production-tested SHA `c7eb3c8`)

| Check | وضعیت |
|---|---|
| Static Analysis (PHPStan) | **PASS** |
| Unit Tests PHP 8.1 / 8.2 / 8.3 / 8.4 | **PASS ×۴** |
| Integration (WP 6.7 + MySQL 8) | **PASS** |
| Closure — PHP 8.1 / 8.3 / 8.4 runtime (WP 6.7.2) | **PASS ×۳** |
| Closure — WP 6.4 / 6.5 / 6.6 runtime | **PASS** |
| Closure — destructive restoreApply (isolated) | **PASS** |
| Release Artifact (build + policy) | **PASS** |
| Responsive smoke (Chromium ×4 viewport) | **PASS** |
| Staging Gate (fresh install → restore drill) | **PASS** |
| Upgrade path (main → RC) | **PASS** |
| Real WP Acceptance (prefix `wp_`) | **PASS** — `307 passed / 0 failed` |
| Real WP Acceptance (prefix `clinic_`) | **PASS** — `307 passed / 0 failed` |

- **PENDING / FAILED / NOT_TRIGGERED: هیچ** (هر ۱۷ چک در `c7eb3c8` سبز).
- Real WP Acceptance روی ZIP رسمی (`bin/build-release.sh`) در WP 6.7.2 تمیز: `307/0`، `41/41` جدول، ۰ console/page error، migration probe fail-loud + not-recorded OK.

### ۵) Official ZIP Verification

- **ZIP identity:** `clinic-practice-management-1.0.0.zip` + `.sha256` + `…-manifest.txt` (build توسط `bin/build-release.sh`).
- **Policy (whitelist-only):** فقط `clinic-practice-management.php`، `README.md`، `uninstall.php`، `bin/cpms`، `src/**`، `assets/**`؛ هرگز `.git/.env/tests/phpunit/composer.*/vendor/.*.log/pilot-*` (self-check در build شکست می‌دهد).
- **Assets تولیدی Admin UX:** `assets/css/cpms-admin.css`، `assets/js/cpms-admin.js`، `src/Admin/RoleCapabilitiesPage.php` — **در ZIP موجودند** (بررسی local build: ۱۷۱ فایل، policy OK).
- **بدون test/local/untracked:** تست‌ها/vendor/مستندات untracked داخل ZIP نیستند.
- **Real WP Acceptance این ZIP را نصب کرد** (نه source mount): `wp plugin install "$ZIP"`.
- **SHA256:** digest دقیق CI در artifact `release-zip` (`clinic-practice-management-1.0.0.zip.sha256`) published است. (بازیابی blob artifact از این environment بلاک شد (EOF)؛ بنابراین digest CI را **حدس نمی‌زنم**. برای sanity، local build: `1bf98036735ff5a2479ad397261e69b3882e9a1cf56e5496e22497b6939555db` — **local-only**، نه digest CI (ZIP کپی byte-reproducible نیست).) با این حال خواصِ policy/assets در بالا verified است.

### ۶) Security Final Re-check (بدون تغییر کد)

- CSRF/Nonce، user IDOR، patient IDOR، clinician mapping manipulation، unauthorized direct page access، unauthorized REST access — **همه منفی/سبز**.
- role escalation / capability escalation — **ممنوع/سبز**؛ انتساب `administrator` در Staff ممنوع؛ ماتریس فقط `manage_options`.
- self-deactivation — **گارد «نمی‌توانید حساب خودتان را غیرفعال کنید»** سبز.
- password leakage — **هیچ plaintext/توکن در audit/log نیست**؛ فقط hash WP + `retrieve_password()`.
- Accountant clinical denial / Manager private-clinical denial / WP Administrator ≠ automatic medical access — **سبز** (منوی پنهان ≠ authorization؛ backend گیت منبع است).
- Advanced Permissions `manage_options` boundary — **سبز** (بدون تغییر backend).
- **Search UI does not change checkbox/capability state** — **سبز**؛ تست `checked_before == checked_after` (`25 == 25`) در همه viewportها؛ جستجو فقط `display`، هرگز `checked` را تغییر نمی‌دهد.
- **تمایز navigation hiding و backend authorization حفظ شد.**

### ۷) Final Role Matrix (خلاصه فارسی)

| Context | Navigation اصلی | Operational / دسترسی | Forbidden / محدودیت |
|---|---|---|---|
| **Administrator / Technical Owner** | «مدیریت مطب» + صفحات فنی/امنیتی | `cpms_config`, `cpms_sms_config`؛ ویرایش ماتریس/Advanced (نیازمند `manage_options`) | **بدون** Medical/Audit/Export خودکار (P-3)؛ منوهای «امروز پزشک»/«صف»/«بیماران» برای Admin پنهان |
| **Clinic Manager** (`cpms_manager`) | «مدیریت مطب» + «بیماران» (فقط جستجو) | `cpms_config`, `cpms_sms_config`, `cpms_patient_read`, `cpms_report_read`, `cpms_search` | هیچ capability بالینی/خصوصی/نسخه/فایل/صف/مالی؛ نه `manage_options`؛ نه ماتریس فنی |
| **Doctor** (`cpms_doctor`) | «امروز پزشک» + «بیماران» (جستجو) + «مالی» | بالینی/یادداشت/نسخه/فایل/مشاوره + `cpms_patient_read`/`update` + queue/invoice/reports | **نمی‌تواند** `cpms_patient_create`؛ نه `cpms_config`؛ نه ماتریس فنی |
| **Secretary** (`cpms_secretary`) | «صف امروز» + «بیماران» (جستجو + ایجاد) + «مالی» | `cpms_patient_read/create/update` + نوبت/صف/فایل/فاکتور/پرداخت/مالی/جستجو | بالینی/یادداشت خصوصی/نسخه/ماتریس فنی/`cpms_config` ممنوع |
| **Accountant** (`cpms_accountant`) | فقط «مالی و تسویه» | `cpms_finance_read`, `cpms_report_read`, invoice/payment, `cpms_export`, `cpms_search` | تمام capability بالینی/خصوصی/نسخه/فایل/صف/`cpms_config` ممنوع؛ نه «بیماران»/«صف»/«پزشک» |

> هیچ دسترسی‌ای صرفاً از روی menu visibility نتیجه‌گیری نشده؛ backend capability + Nonce + Audit مرجع است.

### ۸) Residual Risks / Technical Debt

- **BLOCKING:** **هیچ.** هیچ defect blocking امنیتی/محصولی باقی نمانده؛ هیچ چک/گیت الزامی pending یا failed نیست.
- **NON-BLOCKING:**
  - Boot-time `Settings->get(...)` قبل از migration (activation-order): **benign** — تأیید شد نمی‌تواند نشت یا activation را بشکند؛ مستند به‌عنوان debt (اصلاح عمداً نشد، اصل cosmetic نیست).
  - `SecretaryFinancePage` naming: tech debt جزئی (نام منسوخ/مشترک)؛ عمداً rename نشد.
  - `PatientRepository::find(int $id)` بدون فیلتر `clinic_id` (single-clinic V1): observation از پیش موجود، خارج از scope؛ در multi-branch باید scope شود.
  - CI infrastructure warning: **Node.js 20 deprecated** (actions به Node 24 forced) — non-blocking، badge همچنان سبز؛ **CI config تغییر نکرد تا green-force شود**.
  - mergeStateStatus: مقدار قبلی `UNSTABLE` **گذرا** بود (مربوط به pending checks هنگام push)؛ اکنون `CLEAN` — blocker نیست.
  - بازیابی blob artifact (ZIP/سکرین‌شات) از محیط محلی بلاک است (EOF) — evidence معتبر از commit comment API؛ non-blocking.
  - Screen visual inspection (قبلاً UNVERIFIED) — **اکنون CLOSED** (PO PASS).

### ۹) §46 — FINAL ANSWERS

1. **YES** — Repo/PR integrity: branch درست، PR #9 OPEN/draft، بدون merge، LOCAL==REMOTE، بدون local-only concern.
2. **YES** — merge-base == origin/main (`38c573b`)؛ شاخه فقط جلو (۰ behind / ۳۳ ahead)؛ بدون conflict.
3. **YES** — سند مرجع (`docs/admin-ux-plan.md` + SRS + auth-authorization)؛ Chunk A–G Done.
4. **YES** — امنیت نقش/authorization + IDOR منفی؛ تست‌های negative + 403 + capability-driven.
5. **YES** — نام‌های کلاس/ثبت یکسان (`SecretaryFinancePage` صحیح/ثبت‌شده).
6. **YES** — جریان Staff/رمز فقط WP core؛ بدون password DB؛ بدون plaintext؛ خود-غیرفعال‌سازی گاردشده.
7. **YES** — بدون privilege escalation؛ انتساب administrator ممنوع؛ ماتریس فقط `manage_options`.
8. **YES** — Real WP Acceptance روی ZIP رسمی: `307/0` در دو prefix؛ بدون fatal/console error؛ probe OK.
9. **YES** — Desktop/Tablet/Mobile visual verification: **پس از تأیید تصویری PO، از UNVERIFIED به PASS تبدیل شد**.
10. **YES** — تکمیل محدوده (Patient entry + IA + responsive) + asset-scope scoped + breakpoints + remediation بدون scope expansion.

### ۱۰) Final Verdict

# `ADMIN_UX_READY`

- تمام Hard Acceptanceها بسته شده‌اند؛ `PRODUCT_OWNER_VISUAL_ACCEPTANCE = PASS`؛ §46 = **۱۰/۱۰ YES**؛ production-tested SHA (`c7eb3c8`) تمام گیت‌های الزامی را PASS کرده؛ هیچ blocking defect امنیتی/محصولی نیست؛ persistence repo صحیح است.
- **`ADMIN_UX_READY` فقط Product/Engineering readiness است.** اجازهٔ merge خودکار نمی‌دهد؛ اجازهٔ undraft خودکار نمی‌دهد؛ اجازهٔ شروع feature جدید نمی‌دهد.
- **Next:** تصمیم merge با Product Owner است. PR #9 همچنان **OPEN / draft** و **merge نشده** است؛ انجام نمی‌شود مگر با تصمیم صریح PO.

---

## ۰. الف — ADVANCED PERMISSIONS UX REMEDIATION REPORT (Collapsed/Advanced/Group — پذیرفته‌شده؛ برای بخش Search به §۰.ب مراجعه کنید)

> **نتیجهٔ بازبینیِ تصویریِ PO (authoritative):** در بخش **Advanced Permissions** در `390×844` و `768×1024` حتی در حالت «collapsed» تعداد زیادی capability/checkbox و هشدار حساس پشت‌سرهم visible بود. سایر بخش‌ها (Staff mobile, Doctors mobile, Schedule, Confirmation Modal) **PASS** شدند. پس از این بازبینی، یک **commit remediation دوم** (فقط Advanced Permissions، بدون دست‌زدن به بخش‌های accepted) انجام و CI سبز شد.

| مورد | مقدار (exact) |
|---|---|
| Baseline (پیش از این fix) | `19f48b4972dc0dca849117d0996e4084554941fc` |
| Final HEAD (پس از این fix) | `e8a973bc523a77e5303177264ab9191a99361c7b` |
| Branch | `arena/01a07d25-doctor` |
| PR | **#9** — `OPEN` / `isDraft=true` |
| mergeStateStatus | `UNSTABLE` (گیت‌های غیر-الزامی در حال تکوین؛ **merge نشده**) |
| CI Run (push) | **`34202800663`** — **`success`** |
| CI Run (pull_request) | **`34202804778`** — `success` |
| Real WordPress Acceptance (هر دو prefix `wp_` / `clinic_`) | **`247 passed / 0 failed`** |
| DB table count (واقعی == UI) | `41` == `41` |
| Console / page errors در صفحات CPMS | **۰** |

### ۱) Exact Root Cause

- **علت ریشه‌ای (کد-visible):** صفحهٔ `cpms-roles` برای **هر چهار نقش**، فهرستِ کاملِ **«می‌تواند / نمی‌تواند»** (هر capability به‌صورت `li`) و **danger-box حساس** را مستقیماً در body صفحه render می‌کرد؛ این‌ها (حتی بدون باز کردن Advanced) یک **«دیوار capability/توضیح» + «هشدار پشت‌سرهم»** می‌ساختند که در viewport باریک (۳۹۰/۷۶۸) به‌صورت یک ستون بلند و متراکم دیده می‌شد و به‌عنوان «checkbox wall» برداشت می‌شد. بخش Advanced خودش از `<details>` بومی استفاده می‌کرد، اما **هیچ قاعدهٔ defensive ای برای «بسته ⇒ محتوا هرگز render نشود» وجود نداشت** و محتوای granular هم ابتدا visible بود.
- **تأیید سمانتیک (نه نام فایل):** پس از fix، تست‌های مرورگر به‌جای تکیه بر نام فایل «collapsed»، **واقعاً pixels** را می‌سنجند (`getClientRects()`): در initial load در **همهٔ ۶ viewport** تعداد گروهِ باز (`details.cpms-cap-group[open]`) = **۰** و تعداد چک‌باکسِ نقشِ visible = **۰** ثبت شد. سپس با باز کردن یک گروه، همان گروه `[open]=1` و `visible_checkboxes=5` شد. این ثابت می‌کند حالت collapsed واقعاً برقرار است.
- **یادداشت صداقت:** در sandbox هیچ Chromium/باینری و PHP محلی برای render محلی وجود ندارد (دانلود Chromium بلاک شد)؛ بنابراین **بازبینیِ خودکارِ pixels توسط CI انجام شد** و «بازبینیِ تصویریِ ذهنی» همچنان به PO واگذار می‌شود.

### ۲) فایل‌های تغییر یافته (فقط Advanced Permissions + acceptance؛ بخش‌های accepted دست‌نخورده)

- `clinic-practice-management/src/Admin/RoleCapabilitiesPage.php` — collapse preset can/cannot inside `details.cpms-preset-overview`؛ Advanced به `details.cpms-advanced` با یک هشدار سطح-بالا + search واضح + گروه‌های closed؛ شمارنده گروه `(X/Y)` و نشانگر «⚠️ حساس» در grouped summary؛ ⚠️ هر آیتم فقط داخل گروه باز.
- `clinic-practice-management/assets/css/cpms-admin.css` — قاعدهٔ defensive `details.cpms-details:not([open]) > :not(summary){display:none}`؛ استایل `cpms-preset-overview` / `cpms-advanced-warning` / `cpms-cap-search-field`؛ همه زیر `body.cpms-admin` (scoped).
- `clinic-practice-management/bin/rwp-acceptance.py` — `verify_permissions()` (assertions سمانتیک) + overflow روی صفحات roles در 360/390/768/1024/1366.

### ۳) رفتار initial-collapsed / search / hierarchy حساس

- **Initial:** ورود به Advanced → توضیح کوتاه → **یک** هشدار امنیتی سطح-بالا → Search واضح (با label فارسی + aria-label) → فهرست گروه‌های permission → **همهٔ گروه‌ها CLOSED**. هیچ checkbox/permission granular در حالت collapsed visible نیست.
- **Group summary (بسته):** نام فارسی گروه + `(فعال/کل)` + نشانگر «⚠️ حساس» در صورت نیاز + disclosure indicator بومی (`<summary>`/native arrow). هیچ شناسهٔ خام capability در summary نیست.
- **Expansion:** فقط permissions همان گروه نمایان شوند (checkbox + label فارسی + ⚠️ در صورت واقعی). initial همه بسته؛ باز کردن دستی چند گروه مجاز.
- **Search:** بالای گروه‌ها، با `aria-label` و label فارسی؛ با query، گروه‌های منطبق `[open]` و قابل‌کشف می‌شوند و گروه‌های غیرمنطبق بسته/de-emphasized؛ با پاک کردن، همه‌ها دوباره collapsed (قابل‌پیش‌بینی).
- **Accessibility:** کنترل‌های disclosure بومی `<details>/<summary>` (keyboard Enter/Space)، focus visible، checkbox داخل `<label>` با برچسب فارسی، search دارای accessible name، بدون div non-semantic click-only.

### ۴) Backend Contract — بدون تغییر

`role_caps[role][]`، nonce، گیت `manage_options`، `RolesAndCapabilities::setRoleCaps`، Preset Reset، Audit، و مرزهای privilege/sensitive — **همه دست‌نخورده**. CSS/JS state فقط presentation است؛ authorization سمت سرور (Nonce+Capability+Audit) مرجع است.

### ۵) Security regression

Re-run در CI (`34202800663` / `34202804778`) شامل: `manager.direct.*denied-roles` (403)، `manager.menu.no_roles_matrix`، `accountant.direct.*denied-*`، CSRF/noncé، و denial های مستقیم — همه سبز. تغییر UI هیچ دسترسی فنی به Clinic Manager نمی‌دهد.

### ۶) Responsive (۶ viewport) — Advanced Permissions

| viewport | initial collapsed | one group expanded | search | horizontal overflow |
|---|---|---|---|---|
| 1440×900 | ✅ | ✅ | ✅ | ✅ |
| 1366×768 | ✅ | ✅ | ✅ | ✅ |
| 1024×768 | ✅ | ✅ | ✅ | ✅ |
| 768×1024 | ✅ | ✅ | ✅ | ✅ |
| 390×844 | ✅ | ✅ | ✅ | ✅ |
| 360×800 | ✅ | ✅ | ✅ | ✅ |

سنجش `no_overflow`: `scrollWidth == viewport` (`390==390`، `768==768`، `360==360`، `1024==1024`، `1366==1366`). بخش‌های accepted (Staff/Doctors/Schedule/Modal) از قبل PASS و hand-نخورده.

### ۷) CI / Real WP Acceptance

- **Run push:** `34202800663` — `success`؛ **Run pull_request:** `34202804778` — `success`.
- **RWP:** `247 passed / 0 failed` در هر دو prefix (`wp_`, `clinic_`)؛ `41/41` جدول؛ **۰** console/page error در صفحات CPMS؛ migration probe fail-loud + not-recorded OK.
- **Artifacts:** `rwp-acceptance-wp_` و `rwp-acceptance-clinic_` در هر دو run → پوشهٔ `screenshots/`.

### ۸) نام فایل های دقیق برای بازبینی PO

پسوندها به‌ازای هر viewport: `…-roles-advanced-collapsed.png` (initial)، `…-roles-advanced.png` (Advanced باز)، `…-roles-advanced-group.png` (یک گروه باز)، `…-roles-advanced-search.png` (جستجو).
- **مربوط به چهار critical:** `cpms-mobile-roles-advanced-*` (390×844)، `cpms-tablet-roles-advanced-*` (768×1024)، `cpms-desktop-roles-advanced-*` (1440×900)، `cpms-360-roles-advanced-*` (360×800)، `cpms-1024-roles-advanced-*`، `cpms-1366-roles-advanced-*`.
- **کل صفحهٔ roles:** `cpms-m-roles`، `cpms-t-roles`، `cpms-360-roles`، `cpms-1024-roles`، `cpms-1366-roles`، `cpms-roles`.
- **بخش‌های accepted (regression retained):** `cpms-m-staff`، `cpms-t-staff`، `cpms-m-clinicians`، `cpms-t-clinicians`، `cpms-360-staff`، `cpms-360-clinicians`، `cpms-*-schedule`، `cpms-dialog-*`.

### ۹) AUTOMATED PASS ≠ PRODUCT OWNER VISUAL PASS

**AUTOMATED PASS** (CI + سمانتیک pixels): تأیید شده در بالا. **PRODUCT OWNER VISUAL PASS** هنوز انجام نشده است؛ Product Owner باید تصاویر جدید Advanced Permissions را مستقیم ببیند.

### Verdict نهایی (این بخش)

# `ADVANCED_PERMISSIONS_REMEDIATION_COMPLETE` + `VISUAL_PO_REVIEW_REQUIRED`

**NOT `ADMIN_UX_READY`.** بخش‌های قبلاً accepted (Staff / Doctors / Schedule / Confirmation Modal) PASS باقی می‌مانند مگر regression یافت شود. **merge نشده — STOP برای بازبینی تصویری PO.**

---

## ۰.ب — ADVANCED PERMISSIONS SEARCH UX REMEDIATION REPORT (آخرین/authoritative)

> **نتیجهٔ بازبینیِ تصویریِ PO (authoritative، رندر pixels Run `34202800663`):** حالت‌های Collapsed / Advanced (initial) / یک گروه باز — **PASS**. تنها blocker باقی‌مانده **`ADVANCED_PERMISSIONS_SEARCH_UX`** بود: جستجوی «مشاهده» تقریباً روی همهٔ گروه‌ها match می‌کرد، همهٔ آنها را باز می‌کرد و یک «دیوار checkbox» می‌ساخت → **FAIL بصری/کاربری**. این بخش، remediation همان blocker را (به‌صورت presentation-only) مستند می‌کند. بخش Collapsed/Advanced/Group و Staff/Doctors/Schedule/Modal دست‌نخورده و به‌عنوان regression مجدداً PASS شده‌اند.

| مورد | مقدار (exact) |
|---|---|
| Baseline (پیش از این fix) | `e8a973bc523a77e5303177264ab9191a99361c7b` |
| Final HEAD (پس از این fix) | `c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1` |
| Branch | `arena/01a07d25-doctor` |
| PR | **#9** — `OPEN` / `isDraft=true` |
| mergeStateStatus | `UNSTABLE` (در آخرین بررسی؛ **merge نشده**) |
| CI Run (push) | **`34204647193`** — **`success`** |
| CI Run (pull_request) | **`34204650926`** — `success` |
| Real WordPress Acceptance (هر دو prefix `wp_` / `clinic_`) | **`307 passed / 0 failed`** |
| DB table count (واقعی == UI) | `41` == `41` |
| Console / page errors در صفحات CPMS | **۰** |

### ۱) Exact Root Cause (بخش Search)

- **علت ریشه‌ای:** جستجو با `query «مشاهده»` روی **label فارسی** تقریباً در همهٔ گروه‌ها match می‌شد. منطق قدیمیِ جستجو هر گروهِ منطبق را باز می‌کرد و **کل محتوای گروه را visible نگه می‌داشت** (نه فقط ردیف‌های منطبق). نتیجه: در `390×844`، `360×800` و `768×1024` تعداد زیادی گروه هم‌زمان باز شده و مجموعه‌ای متراکم از چک‌باکس‌ها = «دیوار checkbox» → **FAIL بصری/کاربری**.
- **ضعف assertion قبلی:** `search_opens_match` فقط «یک گروه منطبق باز شد» را بررسی می‌کرد (شرط `open_groups >= 1`) و **نمی‌توانست** ثابت کند که گروه‌های غیرمرتبط پنهان‌اند، ردیف‌های غیرمرتبط داخل گروه‌های منطبق پنهان‌اند، و هیچ «دیوار checkbox»ی شکل نگرفته. این استناد ضعیف، دلیل قبول نشدن Search بود.

### ۲) فایل‌های تغییر یافته (فقط Search Advanced Permissions + acceptance)

- `clinic-practice-management/src/Admin/RoleCapabilitiesPage.php` — افزودن `<div class="cpms-cap-search-status" data-scope="<role>" aria-live="polite"></div>` (شمارندهٔ نتیجه) بعد از فیلد جستجو؛ افزودن `<div class="cpms-cap-search-empty" data-scope="<role>">دسترسی‌ای مطابق جستجوی شما پیدا نشد.</div>` بعد از گروه‌های `details`؛ تغییر placeholder به یک نمونهٔ انتخابی (`مثلاً: نسخه، پرداخت، نوبت…`) که عمداً «مشاهده» را تشویق نمی‌کند.
- `clinic-practice-management/assets/js/cpms-admin.js` — بازنویسی `bindCapSearch()`: هر گروهِ بدون match `display:none` (نه فقط بسته) و `open=false`؛ فقط گروه‌های match `open=true`؛ داخل گروه منطبق فقط ردیف‌های منطبق `label[data-cap]` visible؛ پاک‌کردن → بازگشت به حالت initial؛ query خالی → هیچ پیام/شمارنده؛ پیام فارسی «دسترسی‌ای مطابق جستجوی شما پیدا نشد.» وقتی query غیرخالی و هیچ match نیست؛ شمارندهٔ `«نتیجه: N مورد»`؛ normalize متن (`toLowerCase`) برای تطبیق یکدست. **هرگز به `checkbox.checked` دست نمی‌زند** — فقط `display`.
- `clinic-practice-management/assets/css/cpms-admin.css` — استایل scoped `.cpms-cap-search-status` (پیش‌فرض `display:none`) و `.cpms-cap-search-empty` (پیش‌فرض `display:none`، dashed border، گرد، متمرکز، muted).
- `clinic-practice-management/bin/rwp-acceptance.py` — تقویت `verify_permissions()`: حذف assert ضعیف `search_opens_match`؛ افزودن متریک‌های سمانتیک pixel-based و دو query جدید (انتخابی + بی‌نتیجه) و سه اسکرین‌شات جدید جستجو.

### ۳) الگوریتم / رفتار Search (presentation-only)

- **normalize:** `norm = s.toLowerCase()` (تطبیق یکدستِ Unicode فارسی/عربی، بدون بازنویسی localization).
- **فیلتر ردیف:** با `q` غیرخالی، فقط `label[data-cap]`هایی که `norm(label.textContent)` شامل `q` است `display:block`؛ بقیه `display:none`. با `q` خالی همه `display:''`.
- **فیلتر گروه:** با `q` غیرخالی، هر `details.cpms-cap-group` که هیچ ردیف منطبقی ندارد `display:none` و `open=false`؛ گروهِ دارای match `display:''` و `open=true` (خودکار فقط برای گروه منطبق). با `q` خالی همه گروه‌ها `display:''` و `open=false`.
- **شمارنده:** `status.textContent = «نتیجه: N مورد»` که N تعداد ردیف‌های visible در آن نقش است؛ با `q` خالی `display:none`.
- **پیام خالی:** فقط وقتی `q` غیرخالی و `anyMatch == false`، `.cpms-cap-search-empty` → `display:block`.
- **پاک کردن:** `q == ''` ⇒ همه گروه‌ها visible (خلاصه) و `open=false`؛ ۰ چک‌باکس granular visible؛ وضعیت `checked` ردیف‌ها **بی‌تغییر**.
- **مهم (حفظ state):** `checked` هرگز لمس نمی‌شود؛ در submit، `role_caps[role][]` دقیقاً مجموعهٔ بازبینی‌شده را می‌فرستد. CSS/JS state صرفاً presentation است و **هرگز authorization نمی‌شود** (مرجع سمت سرور: Nonce + `manage_options` + Audit).

### ۴) Capability-State Preservation (regression اثبات‌شده)

بخش‌های search تست جدید `search_does_not_change_checked` را اضافه می‌کند: قبل از جستجو `checked_before` شمارش و بعد از جستجو + پاک‌کردن `checked_after` شمارش می‌شود و برابری `checked_before == checked_after` (در همهٔ viewportها `25 == 25`) ثبت شد. یعنی جستجو/پاک‌کردن هیچ capabilityی را uncheck/disable/revoke نمی‌کند و در save تغییری در مجوزها ایجاد نمی‌شود.

### ۵) Backend Contract — بدون تغییر

`role_caps[role][]`، nonce، گیت `manage_options`، `setRoleCaps`، Preset Reset، Audit، و مرزهای privilege/sensitive — **همه دست‌نخورده**. هیچ تغییر REST/backend/authorization وجود ندارد؛ فقط DOM-filter سمت کلاینت.

### ۶) Strengthened Automated Assertions (semantic، pixel-based، نه نام فایل)

به‌ازای هر viewport در `verify_permissions()`:

- **قبل از جستجو (بعد از بازکردن Advanced):** `open_groups == 0` و `visible_checkboxes == 0` (ثبت شد).
- **پس از جستجوی انتخابی `«نسخه»`** (فقط در گروه بالینی):
  - گروه‌های منطبق == گروه‌های visible (`matching=1 visible=1`).
  - تعداد گروه‌های باز == تعداد گروه‌های منطبق (`open=1 matching=1`).
  - ردیف‌های visible فقط منطبق (`visible=3 nonmatching=0`).
  - **بدون دیوار checkbox:** `visible_groups <= 2` (`visible_groups=1`).
  - `no_overflow` در حالت search (`scrollWidth == viewport`؛ 1440/768/390/360/1366/1024).
- **پس از جستجوی بی‌نتیجه `«زرافه»`:** `visible_checkboxes == 0`، `open_groups == 0`، و پیام فارسی «دسترسی‌ای مطابق جستجوی شما پیدا نشد.» visible (ثبت شد).
- **پس از پاک کردن:** همهٔ گروه‌ها visible (`all_groups_visible=True total=8`)، `open_groups == 0`، `visible_checkboxes == 0`، و `checked_before == checked_after` (`25 == 25`).
- **Regression (بخش‌های پذیرفته‌شده):** Collapsed / Advanced initial / یک گروه باز (روی همان صفحه) + Staff / Doctors / Schedule / Confirmation Modal — همه PASS؛ بدون redesign.

### ۷) Responsive (۶ viewport) — Advanced Permissions Search

| viewport | initial collapsed | Advanced initial | one group open | selective search | no-result | clear→initial | overflow |
|---|---|---|---|---|---|---|---|
| 1440×900 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 1366×768 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 1024×768 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 768×1024 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 390×844 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 360×800 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

> جستجو در `desktop` (1440) هم توسط `verify_permissions` اجرا و سبز شد. بخش‌های accepted (Staff/Doctors/Schedule/Modal) در همهٔ viewportها PASS و دست‌نخورده.

### ۸) Accessibility

- فیلد جستجو: `<label>` فارسی + accessible name (`aria-label`/برچسب) + placeholder فارسی.
- پیام نتیجه: `aria-live="polite"` (`cpms-cap-search-status`) → با تایپ قابل‌فهم؛ تمرکز در input می‌ماند.
- پیام خالی: `cpms-cap-search-empty` با متن فارسی و قابل‌خواندن توسط Screen Reader.
- کنترل‌های disclosure بومی `<details>/<summary>` (Enter/Space)، focus visible، بدون div click-only غیر سماتیک.
- بدون پرشِ ناخواستهٔ focus؛ شمارنده و empty-state قابل‌فهم؛ keyboard قابل استفاده.

### ۹) CI / Real WP Acceptance

- **Run push:** `34204647193` — `success`؛ **Run pull_request:** `34204650926` — `success`.
- **RWP (هر دو prefix):** `307 passed / 0 failed`؛ `41/41` جدول؛ **۰** console/page error؛ migration probe fail-loud + not-recorded OK.
- **Artifacts:** `rwp-acceptance-wp_` و `rwp-acceptance-clinic_` در هر دو run → پوشهٔ `screenshots/`.

### ۱۰) نام فایل‌های دقیق برای بازبینی PO (Search)

پسوندها به‌ازای هر viewport (stem): `…-advanced-search.png` (جستجوی انتخابی اعمال‌شده)، `…-advanced-search-noresult.png` (empty state)، `…-advanced-search-cleared.png` (بعد از پاک‌کردن).

- **390×844 (mobile):** `cpms-mobile-roles-advanced.png` (initial Advanced)، `cpms-mobile-roles-advanced-search.png`، `cpms-mobile-roles-advanced-search-noresult.png`، `cpms-mobile-roles-advanced-search-cleared.png` (+ `cpms-mobile-roles-advanced-collapsed.png`، `cpms-mobile-roles-advanced-group.png`).
- **360×800:** `cpms-360-roles-advanced-search.png`.
- **768×1024 (tablet):** `cpms-tablet-roles-advanced-search.png`، `cpms-tablet-roles-advanced-search-noresult.png` (+ `cpms-tablet-roles-advanced.png`، `cpms-tablet-roles-advanced-cleared.png`).
- **1440/1366/1024:** `cpms-desktop-roles-advanced-search.png`، `cpms-1024-roles-advanced-search.png`، `cpms-1366-roles-advanced-search.png`.

> PO باید فقط گروه‌ها و ردیف‌های منطبق را ببیند؛ **بدون دیوار checkbox** (در هر viewport `visible_groups=1` و `visible=3 nonmatching=0`).

### ۱۱) Regression Status

Collapsed / Advanced initial / یک گروه باز (پذیرفته‌شده در §۰.الف) — **PASS (regression re-check، نه redesign).** Staff / Doctors / Schedule / Confirmation Modal — **PASS (دست‌نخورده).** Backend/authorization — **بدون تغییر.** هیچ بخش جدیدی به scope اضافه نشد.

### Verdict نهایی (این بخش)

# `ADVANCED_PERMISSIONS_SEARCH_REMEDIATION_COMPLETE` + `VISUAL_PO_REVIEW_REQUIRED` *(تاریخی — اکنون superseded توسط §0.ج)*

Search در همهٔ viewportها از نظر سمانتیک (pixels) و در CI (Real WP Acceptance) سبز است؛ **و سپس Product Owner اسکرین‌شات‌های Search را مستقیم بررسی و PASS کرد.** بنابراین این بخش اکنون تاریخی است؛ وضعیت نهایی و authoritative در **§0.ج (FINAL CLOSURE)** ثبت شده که در آن `ADMIN_UX_READY` صادر شده است. **merge نشده — STOP برای تصمیم PO.**

---

## ۰. به‌روزرسانی نهایی — Remediation (Responsive / Permissions UX / Visual Acceptance)

> **این بخش جایگزینِ verdict بخش ۲۵ می‌شود و توسط بخش «۰. الف» بالا (ADVANCED PERMISSIONS UX REMEDIATION REPORT) تکمیل می‌شود.** این repo روی commit نهایی `e8a973b` قرار دارد و دو remediation انجام گرفته است. نتیجهٔ CI سبز است و اسکرین‌شات‌های واقعی در viewportهای خواسته‌شده تولید شده‌اند؛ **اما** بازبینیِ تصویریِ ذهنیِ PO همچنان الزامی است. (برای آخرین وضعیت — remediation جستجو و closure — بخش **«۰.ج»** بالا مرجعِ authoritative است؛ production HEAD = `c7eb3c8` و HEAD نهایی = کامیتِ فقط-مستند این گزارش — رجوع به §0.ج.)

| مورد | مقدار (exact) |
|---|---|
| Baseline (قبل از remediation) | `1d40f959ddad44e69299f9e695bfb8d2c69c9ea1` |
| Final HEAD (پس از remediation دوم برای Advanced Permissions) | `e8a973bc523a77e5303177264ab9191a99361c7b` |
| Remote SHA (branch `arena/01a07d25-doctor`) | `e8a973bc523a77e5303177264ab9191a99361c7b` — **LOCAL == REMOTE ✅** |
| PR number / draft | **#9** — `OPEN` / `isDraft=true` |
| mergeStateStatus | `UNSTABLE` (انتظار تکوین سایر گیت‌های غیر-الزامی؛ **merge نشده**) |
| CI Run (push, اولین remediation) | `34200631238` — `success` |
| CI Run (push, دومین remediation Advanced) | **`34202800663`** — **`success`** |
| CI Run (pull_request، دومین remediation) | **`34202804778`** — `success` |
| Browser acceptance (هر دو prefix) — پس از دومین remediation | **`247 passed / 0 failed`** |
| DB table count (واقعی == UI) | `41` == `41` |
| Console / page errors در صفحات CPMS | **۰** |

### خلاصهٔ اقدامات remediation (۸ فایل تغییر + فقط در scope)

- **BLOCKER A — Responsive:** جدول‌های Staff/Users و Doctor Management و Schedule با کلاس scoped `cpms-table-responsive` در `≤782px` به «کارت ردیفی label/value» تبدیل می‌شوند (`data-label` + `.cpms-actions-cell`). در دسکتاپ جدول‌ها بدون تغییر باقی ماندند؛ `white-space:nowrap` که سبب clipping می‌شد حذف شد و برای غیر-کارتی‌ها فقط `overflow-x:auto` ملایم اعمال شد (نه CSS سراسریِ ویرانگر برای `.widefat`). CSS همه زیر `body.cpms-admin` scoped است. سنجش خودکار `scrollWidth == viewport` در 360/390/768/1024/1366 **سبز** شد (مثلاً `scrollWidth=360 viewport=360`).
- **BLOCKER B — Advanced Permissions:** بخش Advanced از «دیوار چک‌باکس» به **accordion جمع‌شونده** (`details[data-cap-group]`) با شمارندهٔ گروه `(enabled/total)`، نشانگر `⚠️ حساس`، و جستجویی که گروه‌های منطبق را باز/بسته می‌کند تغییر یافت. **قرارداد backend کاملاً حفظ شد**: نام فیلد `role_caps[role][]`، `save()` از `RolesAndCapabilities::setRoleCaps`، Nonce، گیت `manage_options`، و Audit. بازگشت به پیش‌فرض (Preset Reset) حفظ شد.
- **BLOCKER C — Schedule (UNVERIFIED→قبلاً بدون شواهد):** برای پزشکِ seeded، برنامهٔ هفتگی «پُر» (شنبه ۰۹:۰۰–۱۳:۰۰/وقفه ۱۲:۰۰–۱۲:۳۰، دوشنبه ۱۶:۰۰–۲۰:۰۰ با ۲۰/۳۰ دقیقه) ساخته شد و اسکرین‌شاتِ واقعیِ Schedule در **1440×900 / 768×1024 / 390×844 / 360×800** گرفته شد — همگی `HTTP 200` و بدون overflow.
- **BLOCKER D — Confirmation/Dialog:** `window.confirm` مرورگر با **Modal قابل‌دسترس Promise-based** (Escape/Tab-trap، بازگشت focus، `aria-modal`) جایگزین شد؛ برای فرم‌ها/لینک‌ها/دکمه‌ها + `data-cpms-schedule-delete`. شاهد «غیرفعال‌سازی کارمند» به‌صورت واقعی در **Desktop/Tablet/Mobile** و با «انصراف» (Escape) بدون اعمال تغییر ثبت شد (`dialog_visible`, `dialog_message`, `dialog_focus` روی `cpms-modal-confirm`, `dialog_within_viewport`, `dialog_cancelled`).
- **Seeds فارسیِ واقعی:** نام‌های نمایشی «دکتر آزمایشی»، «منشی آزمایشی»، «حسابدار آزمایشی»، «مدیر کلینیک آزمایشی» و «پزشک آزمایشی» (فقط در data seeding؛ هیچ hack تولیدی).
- **SSE:** بدون dependency خارجی، بدون API/تله‌متری؛ confirmation = UX و authorization سمت سرور (Nonce+Capability+Audit) دست‌نخورده.

### Verdict نهایی (این بخش تاریخی است — برای advanced به بخش «۰. الف» مراجعه کنید)

# `TECHNICAL_REMEDIATION_COMPLETE` + `VISUAL_PO_REVIEW_REQUIRED` (برای remediation اول)

پس از بازبینیِ تصویری دوم PO (که `ADVANCED_PERMISSIONS_COLLAPSE_UX` را در ۳۹۰/۷۶۸ یافت)، یک remediation دوم انجام شد؛ **وضعیت فعلی و authoritative در بخش «۰. الف» است:** `ADVANCED_PERMISSIONS_REMEDIATION_COMPLETE` + `VISUAL_PO_REVIEW_REQUIRED`. **merge نشده — STOP برای مرورِ PO.**

---

## ۱. خلاصه مدیریتی

در این فاز «Admin UX» (بازطراحی IA + منضبط‌سازی Navigation + Self-service Setup + Staff/Role Management + Patient Management Entry) موارد زیر ساخته/اصلاح شد:

- **IA و منوی منسجم:** منوی Top-Level «مدیریت مطب» (capability: `cpms_config`) ساخته شد و صفحات پراکندهٔ Tools/Settings به زیر همان منو منتقل شدند (با حفظ slug و backward-compat redirect).
- **Dashboard** (نقش‌آگاه، aggregate از سرویس‌های موجود) + **Setup Wizard** ۱۲ گامی (resumable).
- **Staff/User Management** (ساخت/ویرایش/فعال/غیرفعال، نقش، لینک بازیابی رمز، پیوند پزشک).
- **Chunk G:** نقش‌های واقعی `cpms_doctor` / `cpms_secretary` / `cpms_accountant` / `cpms_manager` (و `cpms_patient`) + منوی مستقل «مالی و تسویه» با `cpms_finance_read` + **Patient Management Entry**.
- **Patient Management Entry:** منوی Top-Level «بیماران» گیت‌شده با `cpms_patient_read`؛ جستجوی bounded و ایجاد بیمار (فقط با `cpms_patient_create`) با **Reuse کامل** `PatientService` موجود (بدون منطق پزشکی جدید، بدون Scope Expansion).

**وضعیت فعلی محصول:** همهٔ گیت‌های خودکار **سبز** هستند (PHPStan، Unit ×۴، Integration، Real WordPress Acceptance روی ZIP رسمی — پس از remediation روی `19f48b4` با **۱۸۴/۱۸۴** در هر دو prefix، Closure Gate، Pilot/Staging Gate). همهٔ چک‌های امنیتی (CSRF/IDOR/escalation/authorization) سبزند. **Implementation و remediation کامل شده** است.

**Blockers باقی‌مانده:** فقط **یک** Hard Acceptance Verification باقی است — **بازبینی تصویری واقعی اسکرین‌شات‌ها** (بخش ۲۱) که به‌دلیل محدودیت sandbox (بلاک blob در GitHub + عدم وجود PHP/WP محلی) انجام نشده است. این «عدم موفقیت») نیست؛ بلکه یک گیت تأیید باقی‌مانده است.

> **به‌روزرسانی (remediation):** متن این بخش پیش از remediation نوشته شده بود. اکنون verdict نهایی در §۰ و §۲۵ به `TECHNICAL_REMEDIATION_COMPLETE` + `VISUAL_PO_REVIEW_REQUIRED` به‌روزرسانی شده است (و در §۲۱ اسکرین‌شات‌های واقعی در ۶ viewport + شواهد overflow/confirmation تولید شد). «بازبینیِ تصویریِ ذهنیِ PO» همچنان تنها معیار باز باقی‌مانده است و `ADMIN_UX_READY` صادر نشد.

---

## ۲. وضعیت Repository / Git / PR

| مورد | مقدار (exact) |
|---|---|
| Baseline main SHA | `38c573bf2c74814cdb5897e1a260081f8a07e7f1` |
| Current branch | `arena/01a07d25-doctor` |
| Final HEAD SHA | `19f48b4972dc0dca849117d0996e4084554941fc` |
| Remote SHA | `19f48b4972dc0dca849117d0996e4084554941fc` |
| LOCAL == REMOTE | **بله** (локал == ریموت) |
| Ahead / Behind vs origin/main | **۰ عقب / ۳۱ جلو** |
| PR number | **#9** |
| PR status | **OPEN** |
| Draft status | **true** (`isDraft=true`) |
| mergeStateStatus | `UNSTABLE` (پس از remediation؛ گیت‌های غیر-الزامی در حال تکوین) |
| Merge انجام شده؟ | **خیر — صریحاً merge نشده است.** |
| CI Run (push) | `34200631238` — `success` (remediation) |
| CI Run (pull_request) | `34200634859` — `success` (remediation) |
| Browser acceptance | **`184 passed / 0 failed`** (هر دو prefix) |

Working tree فقط دو فایل untracked دارد (`docs/pre-merge-verification-pr8.md` و همین گزارش) که هیچ‌کدام در ZIP ریلیز وارد نمی‌شوند.

---

## ۳. معماری اطلاعات و Navigation

**نقشهٔ قبلی:** صفحات `tools.php?page=cpms-*` و `options-general.php?page=cpms-sms` پراکنده بودند؛ «صف امروز» و «امروز پزشک» Top-Level بودند؛ «مالی» زیر «صف» بود؛ هیچ صفحهٔ «بیماران» نبود.

**نقشهٔ جدید (capability-driven):**

- **Top-Level «مدیریت مطب»** (`cpms_config`، `dashicons-building`):
  - **Dashboard** (`cpms-dashboard` — صفحهٔ فرود)
  - **Setup Wizard** (`cpms-wizard`)
  - **Staff/Users** (`cpms-staff` — «کاربران و دسترسی‌ها»)
  - **Roles/Permissions** (`cpms-roles` — ماتریس فنی، فقط مالک `manage_options`)
  - **Doctors/Clinicians** (`cpms-clinicians` — «پزشکان و برنامه کاری»)
  - **Health/Advanced/System** (`cpms-system` — شامل Health، License، Backup، Restore، Update)
  - **Settings** (`cpms-settings` — «فنی و لاگ»)
  - **SMS/Notifications** (`cpms-sms`)
- **Top-Level نقش‌محور (workbench):**
  - **«امروز پزشک»** (`cpms-doctor`، `cpms_queue_read`)
  - **«صف امروز»** (`cpms-queue`، `cpms_queue_read`)
  - **«مالی و تسویه»** (`cpms-finance`، `cpms_finance_read`) — مستقل از صف
  - **«بیماران»** (`cpms-patients`، `cpms_patient_read`) — **جدید**
  - **«نوبت‌های من»** (`cpms-patient`، فقط بیمار)

**Backward compatibility:** مسیرهای قدیمی `tools.php?page=cpms-system` / `tools.php?page=cpms-settings` / `options-general.php?page=cpms-sms` از طریق `redirectLegacy` در `admin_menu` با priority 5 به `admin.php?page=*` (زیر «مدیریت مطب») هدایت می‌شوند (قبل از بررسی `user_can_access_admin_page` که در انتهای `wp-admin/menu.php` اجرا می‌شود تا از 403 پرهیز شود). تمام slugها حفظ شده‌اند.

---

## ۴. Dashboard و First-Run Experience

- **Dashboard:** کارت‌های نقش‌آگاه (نصب راه‌اندازی، نوبت امروز، پزشکان فعال، صف فعلی، آخرین Backup، Cron، SMS، License، Health warning)؛ کوئری‌های bounded؛ بدون افشای unauthorized.
- **Onboarding notice:** برای مدیر مجاز (تا وقتی `setup.completed` false و رونوشت `cpms_onboarding_seen` نباشد)؛ لینک «شروع راه‌اندازی» و «سلامت سیستم».
- **Setup Wizard** (`CpmsSetupWizard`): ۱۲ گام (welcome/clinic/booking/users/doctors/schedules/sms/backup/license/health/review/finish)؛ progress؛ **resumable** از طریق `setup.current_step` و `setup.started_at`؛ Optional/Required؛ ذخیرهٔ Atomic + audited روی Settings؛ بدون PHI؛ گیت تکمیل = نام کلینیک + ≥۱ پزشک فعال؛ ثبت `setup.completed`.
- **Plugin action links** (فقط لینک‌های مجاز): «راه‌اندازی» یا «داشبورد CPMS» + «تنظیمات» برای دارندگان `cpms_config`.

**مسیر کاربر از نصب تا راه‌اندازی:** نصب/فعال‌سازی → Migration خودکار → onboarding notice → شروع Wizard → تکمیل ۱۲ گام → `setup.completed` → Dashboard نقش‌آگاه + منوی منسجم آماده.

---

## ۵. Staff / User Management

صفحهٔ «کاربران و دسترسی‌ها» (`cpms-staff`) فقط برای دارندگان `cpms_config` (تهدید = hidden menu ≠ authorize؛ رندر/اکشن‌ها با capability گیت می‌شوند):

- **ساخت پزشک (Doctor):** نقش `cpms_doctor`؛ یا از همین صفحه یا از جریان «افزودن پزشک» در `cpms-clinicians` که `StaffManagementPage::upsertUser` را reuse می‌کند (پیوند ۱:۱).
- **Sekretary / Accountant / Clinic Manager:** نقش‌های `cpms_secretary` / `cpms_accountant` / `cpms_manager` برای ساخت/ویرایش.
- **activate/deactivate:** به‌طور موقت به `subscriber` تغییر می‌کند؛ نقش قبلی در usermeta (`cpms_previous_role`) حفظ و هنگام فعال‌سازی بازگردانده می‌شود؛ تاریخچه حذف نمی‌شود.
- **Role assignment:** فقط نقش‌های CPMS (بیمار/پزشک/منشی/حسابدار/مدیر)؛ انتساب `administrator` ممنوع (جلوگیری از privilege escalation).
- **Search/filter:** فهرست پرسنل با نقش/وضعیت؛ نمایش پیوند پزشک.
- **Password setup/reset:** هنگام «افزودن» با رمز خالی → رمز قوی CSPRNG (`wp_generate_password`) یک‌بار نمایش داده می‌شود؛ یا رمز دلخواه (حداقل ۱۰ شامل حرف و عدد). لینک «بازیابی رمز» → `retrieve_password()`.
- **Clinician association:** نقشهٔ wp_user_id → پزشک (پیوند ۱:۱، گروه غیرفعال نیز نمایش داده می‌شود).
- **جلوگیری از self-deactivation:** در `toggleUser` گارد «نمی‌توانید حساب خودتان را غیرفعال کنید».
- **Audit:** `STAFF_USER_CREATED`، `STAFF_USER_UPDATED`، `STAFF_USER_DEACTIVATED`، `STAFF_USER_ACTIVATED`، `STAFF_PASSWORD_RESET_INITIATED`.

---

## ۶. Password / Authentication Security

- **WordPress تنها منبع authentication/password است — بله.** هیچ سیستم احراز هویت جداگانه‌ای ساخته نشده؛ نقش‌ها و رمزها از WP core (`wp_insert_user` / `wp_update_user` با `user_pass`، `wp_generate_password`، `retrieve_password`) استفاده می‌کنند.
- **Password database جداگانه?** — **خیر.** هیچ جدول/ذخیره‌گاه دوم رمز وجود ندارد؛ فقط `wp_users` وردپرس (که hash ذخیره می‌کند).
- **آیا password موجود قابل مشاهده/retrieve است؟** — **خیر.** رمز هرگز به‌صورت plaintext ذخیره/نمایش داده نمی‌شود؛ فقط hash در DB. در صورت رمز خالی هنگام ساخت، یک رمز یک‌بارهٔ قوی تولید و فقط همین‌یک‌بار در notice نمایش داده می‌شود.
- **Password reset/setup:** از `retrieve_password()` (تولید توکن + ایمیل توسط WP) استفاده می‌شود؛ توکن فقط در ایمیل/DB موقتِ WP است.
- **آیا password/token در audit/log ثبت می‌شود؟** — **خیر.** Audit `STAFF_PASSWORD_RESET_INITIATED` فقط `login` و `wp_user_id` را ثبت می‌کند؛ هیچ token/plaintext/secret در audit یا OpLog نمی‌رود.

---

## ۷. Roles / Capabilities / Presets

- **Administrator / Technical Owner:** فقط `cpms_config` + `cpms_sms_config` اضافه شده (در `register()`). **بدون** Medical/Audit/Export به‌صورت خودکار (P-3). دسترسی به صفحات فنی/امنیتی و ویرایش ماتریس (نیازمند `manage_options`).
- **Clinic Manager (`cpms_manager`):** `cpms_config`, `cpms_sms_config`, `cpms_patient_read`, `cpms_report_read`, `cpms_search`. **ممنوع:** هیچ capability بالینی/یادداشت خصوصی/نسخه/فایل/صف/مالی؛ نه `manage_options` (نه ادمین وردپرس). Navigation: «مدیریت مطب» + «بیماران» (فقط جستجو)؛ نه «صف امروز»/«امروز پزشک»/ماتریس فنی.
- **Doctor (`cpms_doctor`):** بالینی/یادداشت/نسخه/فایل/مشاوره + `cpms_patient_read`/`cpms_patient_update` + queue + invoicing + reports. **نمی‌تواند** `cpms_patient_create`. Navigation: «امروز پزشک» + «بیماران» (جستجو) + «مالی».
- **Secretary (`cpms_secretary`):** `cpms_patient_read/create/update` + نوبت/صف/فایل/فاکتور/پرداخت/مالی/جستجو. **ممنوع:** بالینی/یادداشت خصوصی/نسخه/ماتریس فنی/`cpms_config`. Navigation: «صف امروز» + «بیماران» (جستجو + ایجاد) + «مالی».
- **Accountant (`cpms_accountant`):** فقط مالی/گزارش/خروجی (`cpms_finance_read`, `cpms_report_read`, invoice/payment, `cpms_export`, `cpms_search`). **ممنوع:** تمام capability بالینی/خصوصی/نسخه/فایل/صف/`cpms_config`. Navigation: فقط «مالی و تسویه»؛ نه «بیماران»/«صف»/«پزشک».

- **Role Presets:** به‌صورت انسانی در `RoleCapabilitiesPage` (Normal) با «می‌تواند/نمی‌تواند» + توضیح فارسی + هشدار حساس.
- **Advanced Permissions:** جمع‌شونده/گروه‌بندی/جستجو؛ حفظ `role_caps[role][]`؛ ویرایش فقط برای مالک فنی (`isSecurityOwner()` = `manage_options`) + Nonce + Audit (`ROLE_PERMISSION_CHANGED`/`ROLE_PERMISSION_RESET`).
- **Privilege-escalation prevention:** `save()`/`reset()` فقط با `manage_options`؛ ماتریس برای Manager/Secretary/Accountant قابل POST نیست؛ صفحهٔ Staff از انتساب `administrator` جلوگیری می‌کند.
- **Reset preset behavior:** بازگشت به template پیش‌فرض نقش + حذف override در صورت برابری با پیش‌فرض؛ بدون رشد بدون‌مورد Option؛ Audit.

---

## ۸. Patient Management

- **فایل `src/Admin/PatientAdminPage.php`:** جدید (`PatientAdminPage`) — Top-Level «بیماران» + جستجو + ایجاد.
- **منوی «بیماران»:** `add_menu_page` با capability `cpms_patient_read`، slug `cpms-patients`، position ۲۷؛ در `App::boot` با `PatientAdminPage::register();` ثبت شده؛ slug در allowlist `CpmsAssets::PAGES` برای لود CSS/JS اسکوپ‌شده.
- **Capability gate:** رندر با `cpms_patient_read` (wp_die 403)؛ فرم «ایجاد» فقط با `cpms_patient_create`؛ `canCreate()` برمی‌گرداند.
- **Bounded search:** server-side (فرم GET → باز رندر)؛ `App::patientService()->search($q, 10)`؛ حداقل ۲ کاراکتر؛ limit سقف‌دار؛ نتایج جدول bounded؛ خروجی `searchView` = id/mrn/نام/موبایل/کد ملی (masked)/تولد/جنسیت/وضعیت — بدون فیلد بالینی.
- **Create flow:** POST به `admin-post.php` (اکشن `cpms_patient_create`) با `wp_nonce_field` + `check_admin_referer` (CSRF) + `current_user_can(cpms_patient_create)` + Sanitize (`sanitize_text_field`/`sanitize_key`/`wp_unslash`)؛ از `PatientService::create` (audit `PATIENT_CREATED`، MRN خودکار، mobile mask).
- **Empty state:** «بیماری یافت نشد.» + action مناسب برای کاربرِ دارای create.
- **Masking:** کد ملی در نتایج جستجو masked؛ موبایل در audit/OpLog mask؛ بدون PHI اضافه.
- **CSRF protection:** Nonce در فرم ایجاد + `check_admin_referer` در handler.
- **Audit:** `PATIENT_CREATED` (با `after_json` از staffView + mobile mask در meta)؛ OpLog فقط `patient.created` با patient_id/actor/mobile mask.
- **Authorization:** منو/رندر/اکشن همگی capability؛ Accountant → 403 + منوی پنهان؛ WP Administrator → بدون منو + رندر/REST 403.
- **Accountant denial:** `cpms-acc-denied-patients` HTTP 403 + `accountant.menu.no_patients` (در Real WP Acceptance).
- **WP Administrator ≠ automatic medical access:** `register()` به admin فقط `cpms_config`/`cpms_sms_config` می‌دهد؛ admin `cpms_patient_read` ندارد → منو پنهان + `admin.patient_page_denied` HTTP 403.
- **Patient IDOR tests:** در `PatientAdminTest` — دسترسی به `GET /patients/{id}` توسط accountant/admin → 403؛ جستجو/get/create/update منفی.

**چیزی که عمداً در UI اضافه نشد (و چرا Scope Expansion بود):**

- **جزئیات بالینی کامل/تاریخچهٔ درمان:** backend (`staffView`) شامل blood_group/allergies/chronic/medical_history/… است؛ اما نمایش آن در این ورود عملیاتی، **بیش از ضرورت عملیاتی** و به‌محضِ over-exposure است — به مسیرهای بالینی مجاز خودش سپرده شد.
- **فهرست همهٔ بیماران بدون query:** هیچ endpoint «directory» بدون `q` وجود ندارد؛ ساخت آن مستلزم منطق/UI جدید = Scope Expansion.
- **فرم ویرایش (update):** backend دارد (`PATCH /patients/{id}`) ولی افزودن فرم ویرایش = duplicate UI + خارج از «حداقل UX» تعریف‌شده؛ این‌جا اضافه نشد.
- **امداد منطق پزشکی جدید:** هیچ منطقی ساخته نشد؛ فقط `PatientService` موجود reuse شد.

---

## ۹. Doctor Management

- «پزشکان و برنامه کاری» (`cpms-clinicians`) — فهرست پزشکان + افزودن/ویرایش.
- **Create/Edit:** «افزودن پزشک» حالا می‌تواند در همان جریان حساب وردپرس با نقش `cpms_doctor` بسازد (reuse `StaffManagementPage::upsertUser`) و پیوند ۱:۱ برقرار کند؛ `upsertUser` حالا `user_id` هم برمی‌گرداند.
- **Active/Inactive:** بدون حذف تاریخچه؛ غیرفعال‌سازی نقش/ارتباط حفظ می‌شود.
- **WP account association:** پیوند ۱:۱ با `wp_user_id` در `cpms_clinicians`؛ وضعیت ارتباط نمایش داده می‌شود؛ جلوگیری از ارتباط تکراری (یکتایی).
- **Role assignment:** نقش `cpms_doctor` در جریان؛ بدون ارجاع به administrator.
- **Account association status:** در صفحهٔ کاربران نشان داده می‌شود (ستون «پزشک مرتبط»).
- **Schedule entry:** بخش برنامهٔ هفتگی پزشک.
- **History preservation:** غیرفعال‌سازی بدون حذف history.
- **Future appointment safeguards:** `ScheduleService::impact()` گزارش می‌دهد چند اسلات آینده بازتولید می‌شود و چند اسلات رزرو/Hold «محافظت» می‌شود؛ بدون invalidate بی‌صدا؛ پیام ذخیره شامل اعداد تأثیر.

---

## ۱۰. Schedule Management

- **Weekly schedule UX:** نمایش هفتگی روز/ساعت/ظرفیت/breaks/استثناها بر اساس backend موجود (ScheduleService) — بدون duplicate logic.
- **دسترسی تکراری/بازتولید اسلات:** اسلات‌ها با `u_slot(clinician_id, slot_date, slot_time)` یکتا هستند؛ بازتولید امن.
- **Dangerous changes:** پیش از هر تغییر مخرب، `impact()` گزارش می‌دهد؛ بدون invalidation بی‌صدا.
- **Existing appointment protection:** اسلات‌های رزرو/Hold در طول تغییر محافظت می‌شوند (اعداد تأثیر در پیام).
- **Responsive behavior:** صفحهٔ پزشک/برنامه در viewport موبایل/تبلت/دسکتاپ تست شد (در Real WP Acceptance، صفحات `cpms-clinicians` و `cpms-doctor-schedule` HTTP 200 + بدون critical).

---

## ۱۱. Finance

- **Finance navigation:** منویTop-Level مستقل «مالی و تسویه» (`cpms-finance`، `cpms_finance_read`).
- **نقش‌های دیدن:** Secretary + Accountant (هر دو `cpms_finance_read`)؛ **Doctor** هم طبق DOCTOR_CAPS `cpms_finance_read` دارد.
- **Accountant access:** `cpms-acc-finance` HTTP 200؛ `finance/summary` مجاز؛ گزارش/خروجی مالی.
- **Queue-only functionality gate:** تب «در انتظار تسویه» (دادهٔ صف) فقط با `cpms_queue_read` (`can_queue`) — برای حسابدار پنهان و `loadAwaiting` زودتر خروج می‌دهد؛ هیچ API queue فقط با بارگذاری صفحهٔ مالی برای حسابدار به‌کار نمی‌افتد.
- **Accountant جداشده از clinical/queue APIs:** بدون `cpms_queue_read`/clinical؛ دسترسی مستقیم به `secretary/today` و clinical records → 403 (تست).
- **نام کلاس `SecretaryFinancePage`:** کلاس صحیح و سازگار است؛ **اما** از نظر معنایی اکنون «مالی و تسویه» مشترک Secretary+Accountant است، نه فقط Secretary → نام کمی گمراه‌کننده است. **Technical Debt** است (غیرمسدودکننده). به‌دلیل ریسک و «عدم تغییر زیبایی‌شناختی صرفاً» عمداً **rename نشد** (کشیدن rename کلاست را به‌روز می‌کند و ریسک بیهوده اضافه می‌کند). گزارش آن به‌عنوان debt باقی‌مانده ثبت شد.

---

## ۱۲. License / Backup / Restore / Update / Health

همگی در `SystemPage` (`cpms-system`) با UI مشتری‌پسند؛ جزئیات فنی پشت «جزئیات فنی» جمع‌شونده.

- **License:** وضعیت/فعال‌سازی/offline activation؛ بدون PHI. UI → کارت وضعیت مجوز؛ جزئیات فنی ثانویه.
- **Backup:** فهرست بکاپ‌ها (اجرا/تأیید/حذف)، تنظیم دوره‌ای (enabled/interval/keep)؛ اجرای دستی؛ فایل + دیتابیس `cpms_*`؛ خارج webroot.
- **Restore safeguards:** preflight (جدول/ردیف/فایل/یکپارچگی/`restore_safe`) پیش از اقدام مخرب + «اعتراف» (checkbox) + تایپ `RESTORE` + Safety Backup خودکار + audit؛ سایر Pre-conditions از backend موجود.
- **Update:** F10 — فقط slug خودمان (`clinic-practice-management`)؛ کش‌شده؛ `pre_set_site_transient_update_plugins`/`plugins_api`؛ صحت sha256 بسته پیش از نصب (`upgrader_pre_download`).
- **Health:** کارت‌های خطا برای هر بررسی non-PASS (چه/اثر/چه کنم)؛ بررسی‌های PASS در جدول فشرده؛ راهنمای انسانی از `SystemPage::guide()` (خالص، بدون WP/DB).
- **Advanced/System:** «فنی و لاگ» (`cpms-settings`) + سیستم؛ بخش‌های حساس پشت جمع‌شونده/قفل (فقط مالک فنی).

---

## ۱۳. Persian / RTL / Responsive UX

- **Persian-first:** همهٔ برچسب‌ها/عنوان‌ها/توضیحات/notices فارسی؛ `dir="rtl"` روی `.wrap`.
- **RTL:** صفحات CPMS `dir="rtl"`؛ چیدمان RTL.
- **Mixed LTR identifiers handling:** شناسه‌های فنی مثل `MR-...`، شماره موبایل، قراردادها با `dir="ltr"` در فیلدها/ستون‌ها نگه‌داری می‌شوند.
- **Responsive breakpoints (در `assets/css/cpms-admin.css`):** `1400px`، `1024px`، `782px`، `480px`؛ `form-table` در ≤782 به‌صورت block؛ `.widefat` در ≤782 `overflow-x:auto`؛ کارت‌ها با `auto-fill/minmax`.
- **Table mobile behavior:** ستون‌ها در موبایل به‌صورت stack + overflow-x.
- **Form behavior:** `regular-text` در ≤480 تمام‌عرض.
- **Mobile navigation:** منوهای نقش‌محور/مدیریتی در موبایل تست شدند (صفحات `cpms-*` mobile HTTP 200 + بدون critical + بدون console error).
- **Permission UI:** Normal = Role Presets؛ Advanced = collect/group/search/Persian + هشدار حساس.
- **Viewportهای مورد آزمون:** 1440×900 (دسکتاپ)، 768×1024 (تبلت)، 390×844 (موبایل) + 360×800 / 1024×768 / 1366×768 در ماتریس. نکته: همهٔ viewportها **HTTP200 + بدون critical + بدون console** و **بدون overflow** شدند؛ بازبینی **تصویری** (که در ابتدا UNVERIFIED بود) پس از تأییدِ PO **CLOSED** شد (§0.ج / §21).

---

## ۱۴. Accessibility

- **Labels:** `<label for>` برای همهٔ فیلدها؛ `aria`/`role="presentation"` روی جداول.
- **Keyboard/focus:** `cpms-autofocus`؛ focus روی فیلد معتبر؛ confirm از طریق JS + fallback با `confirm()` مرورگر در نبود JS (progressive enhancement).
- **Semantic structure:** heading (`h1/h2`), description, notice, table, زرها.
- **Notices:** `notice-success/error` + `is-dismissible` + `role` ضمنی.
- **Confirmations:** Confirm برای اقدامات مخرب (onclick/`data-cpms-confirm`/onsubmit) — fallback مرورگر.
- **Touch targets / contrast / zoom:** به‌صورت واقعی **verify نشده** (نیازمند بازبینی تصویری/دسترسی‌سنجی). چیزی که verify نشده، **PASS اعلام نمی‌شود** (بخش ۲۱).

---

## ۱۵. Security Verification (نتایج تست‌ها)

- **CSRF:** Nonce در همهٔ actionهای admin-post + `check_admin_referer`؛ REST با header `X-WP-Nonce` + `wp_verify_nonce`. ✅
- **IDOR:** تست‌های negative در `tests/Integration/` سبزند. ✅
- **Privilege escalation:** «انتساب administrator» ممنوع؛ ماتریس فقط `manage_options`. ✅
- **Role escalation:** `save()`/`reset()` گیت `isSecurityOwner()`؛ Manager/Secretary/Accountant نمی‌توانند matrix را POST کنند. ✅
- **Unauthorized direct page access:** 403 (admin→patients/queue، accountant→system/doctor/staff/patients، secretary→clinicians/system، manager→doctor/roles). ✅
- **Unauthorized REST access:** 403 برای نقش‌های غیرمجاز روی search/get/create/update. ✅
- **Clinician mapping manipulation:`** پیوند ۱:۱ + `isUserLinked`؛ بدون دست‌کاری آزاد. ✅
- **Password leakage:** بدون plaintext/token در audit/log/DB. ✅
- **Patient authorization:** capability-driven + Accountant/Admin 403. ✅
- **Financial authorization:** فقط `cpms_finance_read`؛ Accountant به finance مجاز و از queue جدا. ✅
- **Private clinical access:** Accountant/Manager/Secretary → clinical/private 403. ✅
- **WP Administrator ≠ unrestricted medical access:** admin فقط `manage_options`/`cpms_config`/`cpms_sms_config`؛ بدون `cpms_patient_read` → منو پنهان + 403. ✅

**تفاوت Menu hiding با Backend authorization (صریح):** پنهان‌کردن منو صرفاً «UX» است و هرگز Authorization نیست. بک‌اند (render gate + REST `permission_callback` + Service/Repository data-access) مرجع authorize است. این اصل (gold rule) در همهٔ pages رعایت شده؛ مثال: منوی «بیماران» برای Admin/Accountant پنهان است *و* دسترسی مستقیم/REST آن‌ها 403 است؛ منوی «ماتریس دسترسی» برای Manager پنهان است *و* POST به matrix برای او (بدون `manage_options`) رد می‌شود.

---

## ۱۶. Audit

فهرست عملیات حساس audit‌شده (نمونه):

- `STAFF_USER_CREATED` / `STAFF_USER_UPDATED` / `STAFF_USER_DEACTIVATED` / `STAFF_USER_ACTIVATED` / `STAFF_PASSWORD_RESET_INITIATED` (staff)
- `ROLE_PERMISSION_CHANGED` / `ROLE_PERMISSION_RESET` (roles)
- `PATIENT_CREATED` / `PATIENT_UPDATED` / `PATIENT_PROFILE_UPDATED` (patient)
- `FORBIDDEN_ACCESS_ATTEMPT` (REST capability / patient-role denials)
- `CLOSURE_RUNTIME_PROBE`، license/backup/restore/update ops (در صورتی که رخ دهند)
- زنجیرهٔ hash (ADR-0008)؛ `verifyChain`.

**تأیید:** هیچ raw password/secret/توکن/بدنهٔ خام حساس در audit نوشته نمی‌شود؛ PHI غیرضروری ثبت نمی‌شود؛ کد ملی/موبایل در نتایج جستجو mask و در audit موبایل mask می‌شود.

---

## ۱۷. Performance

- **Asset scoping:** `CpmsAssets::enqueue` فقط وقتی `$_GET['page']` در allowlist صفحات CPMS است (`isCpmsPage()`); CSS زیر `body.cpms-admin`؛ JS در footer؛ هیچ asset در صفحهٔ غیر-CPMS نمی‌آید.
- **External dependencies:** هیچ وابستگی/CDN خارجی (جستجو در `assets/` و wp_enqueue با URL خارجی → هیچ).
- **Bounded queries:** جستجوی بیمار limit=۱۰ (سقف ۵۰ در Service)؛ `migrate` با lock؛ کوئری‌های dashboard bounded.
- **Pagination/search bounds:** search حداقل ۲ کاراکتر + limit سقف‌دار؛ بدون pagination سروریر در این ورود (bounded).
- **N+1 review:** فهرست پرسنل/عملگرها bounded و نقشهٔ پیوند با یک کوئری؛ بدون N+1 عمده.
- **Impact on unrelated wp-admin/public pages:** بدون asset سراسری/فریم‌ورک؛ فقط صفحات CPMS؛ بدون اسکریپت/استایل در سایر صفحات یا front-end.

---

## ۱۸. Official ZIP

- **ZIP از HEAD `1d40f959ddad44e69299f9e695bfb8d2c69c9ea1` ساخته می‌شود** (و در CI از همین HEAD؛ Real WP Acceptance از ZIP رسمی استفاده می‌کند).
- **Integrity/Policy:** `bin/build-release.sh` → **POLICY: OK** (whitelist-only)؛ **۱۷۱ فایل**.
- **PatientAdminPage inclusion:** `./src/Admin/PatientAdminPage.php` در manifest. ✅
- **CSS/JS inclusion:** `assets/**` در ZIP؛ slug `cpms-patients` در allowlist (لود CSS/JS صفحه).
- **Exclusion of tests/dev/local artifacts:** `tests/`، `phpunit*`، `composer.*`، `vendor/`، `bin/rwp-acceptance.py`، `docs/…`، `.git`، `.log` — **خارج** از ZIP (policy self-check). ✅
- **SHA256 (بازسازی محلی روی همان HEAD):** `02f43858a52fd0c27f2b3f098406a621eb3103aa89df8dc8627afdf3b46a8122`. (ZIP ساخته‌شده توسط runner همان مجموعهٔ فایل‌ها را دارد؛ ممکن است sha بایت‌به‌بایت به‌دلیل timestamp های zip متفاوت باشد.)
- **Real WP Acceptance از همان ZIP:** بله — workflow آن `bin/build-release.sh` را اجرا و ZIP را نصب/فعال می‌کند (نه source mount).

---

## ۱۹. CI Results

> **به‌روزرسانی (remediation):** جدول زیر وضعیت گیت‌ها **روی `1d40f95`** را نشان می‌دهد و **قدیمی** است. پس از remediation روی `19f48b4`، گیت «Real WordPress Acceptance» به‌روزرسانی شد و در §۰ ثبت شده است (`184 passed / 0 failed` در هر دو prefix). سایر گیت‌ها (PHPStan، Unit، Integration، Closure/Pilot) روی `19f48b4` نیز دوباره اجرا می‌شوند؛ وضعیت نهایی PR `mergeStateStatus=UNSTABLE` است چون برخی گیت‌های غیر-الزامی هنوز تکوین‌نشده‌اند (PR draft است و merge صریحاً انجام نشده).

| گیت | وضعیت (روی `1d40f95` — historical) |
|---|---|
| Static Analysis (PHPStan) | ✅ success |
| Unit Tests (PHP 8.1) | ✅ success |
| Unit Tests (PHP 8.2) | ✅ success |
| Unit Tests (PHP 8.3) | ✅ success |
| Unit Tests (PHP 8.4) | ✅ success |
| Integration (WP 6.7 + MySQL 8) | ✅ success |
| Closure Gate | ✅ success |
| Release Artifact (build + policy) | ✅ success |
| Upgrade path (main → RC) | ✅ success |
| Responsive smoke (Chromium ×4 viewport) | ✅ success |
| Pilot/Staging Readiness Gate | ✅ success |
| Real WordPress Acceptance | ✅ success (۱۲۶/۱۲۶ در هر دو prefix) |

**تعداد دقیق تست‌ها:** در Integration **۴۲۰ تست / ۱۹۲۳ assertion** مستقیماً در یک اجرای میانی (که فقط یک تست خراب داشت: تست audit، که اصلاح شد و سپس همان suite سبز شد) مشاهده شد. در اجرای نهایی، نتیجهٔ job = success (شکست ۰). عدد ۴۲۰/۱۹۲۳ مستقیماً verify شده؛ عدد «شکست» در اجرای نهایی ۰ است.

---

## ۲۰. Real WordPress Acceptance

> **به‌روزرسانی (remediation):** جدول و ارقام زیر مربوط به اجرای قبلی روی `1d40f95` است و **تاریخی** محسوب می‌شود. پس از remediation، اجرای جدید روی `19f48b4` در Run `34200631238` / `34200634859` انجام شد و به **`184 passed / 0 failed`** در هر دو prefix رسید (ماتریس viewport + overflow + confirmation). §۰ را ببینید.

روی ZIP رسمی، دو prefix مستقل (`wp_` و `clinic_`) اجرا شد:

| مورد | `wp_` (historical) | `clinic_` (historical) |
|---|---|---|
| نتیجه | success | success |
| PASS / FAIL | **126 / 0** | **126 / 0** |
| actual/ui table count | 41 / 41 | 41 / 41 |
| migration probe | fail-loud OK + not-recorded OK | fail-loud OK + not-recorded OK |
| PHP fatal in debug.log | none | none |
| browser console/page errors on CPMS | 0 / 0 | 0 / 0 |

- **Patient workflow:** `secretary.cpms-patients.http200`، `create_form`، `create_success`، `search_results`، `empty_state` → همه ✅.
- **Staff/doctor/schedule:** صفحات `cpms-*` (dashboard/wizard/staff/clinicians/roles/system/settings/sms/queue/finance/doctor/handwriting) HTTP 200 + بدون critical. ✅
- **Role navigation:** `has_patients` برای secretary/doctor/manager؛ `no_patients` برای admin/accountant؛ `no_roles_matrix` برای manager؛ `no_doctor_topmenu/no_queue_topmenu` برای admin/manager/accountant. ✅
- **Expected 403 denials:** `admin.patient_page_denied`، `admin.business_page_denied`، `accountant.direct.cpms-acc-denied-*`، `manager.direct.cpms-mgr-denied-*`، `secretary.direct.*` — همه ✅ (HTTP 403).
- **Official ZIP:** workflow از ZIP رسمی استفاده می‌کند. ✅

---

## ۲۱. Screenshot Evidence — تولید (روی remediation) / **بازبینی تصویری CLOSED (PO PASS)**

> **به‌روزرسانی (remediation):** اکنون اسکرین‌شات‌های واقعی و هدفمند در viewportهای درخواستی PO تولید شده و **با‌آزمون‌های خودکارِ overflow و confirmation** همراه‌اند. سپس **Product Owner بازبینیِ ذهنی (visual inspection) تصاویر را مستقیم انجام و تأیید کرد** (Run `34204647193`)؛ بنابراین گیت تصویری **CLOSED** است (جزئیات در §0.ج FINAL CLOSURE).

- **تولیدشده — بله** — در Acceptance روی ZIP رسمی، اسکرین‌شات‌های زیر تولید و به‌عنوان **CI artifact** بارگذاری شدند (run remediation `34200631238` / `34200634859`):
  - **1440×900 (دسکتاپ):** `cpms-dashboard`، `cpms-wizard`، `cpms-system`، `cpms-staff`، `cpms-clinicians`، `cpms-clinicians-list`، `cpms-desktop-schedule`، `cpms-desktop-roles-advanced-collapsed`، `cpms-desktop-roles-advanced`، `cpms-desktop-roles-advanced-group`، `cpms-dialog-desktop`.
  - **768×1024 (تبلت):** `cpms-t-dashboard`، `cpms-t-roles`، `cpms-t-clinicians`، `cpms-t-system`، `cpms-t-staff`، `cpms-tablet-schedule`، `cpms-tablet-roles-advanced-collapsed`، `cpms-tablet-roles-advanced-group`، `cpms-dialog-tablet`.
  - **390×844 (موبایل):** `cpms-m-dashboard`، `cpms-m-roles`، `cpms-m-clinicians`، `cpms-m-staff`، `cpms-m-system`، `cpms-mobile-schedule`، `cpms-mobile-roles-advanced-collapsed`، `cpms-mobile-roles-advanced-group`، `cpms-dialog-mobile`.
  - **360×800 (باریک‌ترین):** `cpms-360-staff`، `cpms-360-clinicians`، `cpms-360-roles`، `cpms-360-schedule`.
  - **1366×768 / 1024×768:** `cpms-1366-staff|clinicians|roles` و `cpms-1024-staff|clinicians|roles`.
  - **Patients (منشی):** `cpms-sm-patients`، `cpms-st-patients`.
- **Viewportهای مورد آزمون:** 1440×900، 1366×768، 1024×768، 768×1024، 390×844، 360×800.
- **کجای CI artifacts:** در GitHub Actions، پوشهٔ `rwp-acceptance-{prefix}` (محتوای `/tmp/acc/` شامل `screenshots/`). Run IDهای معتبر برای PO روی production-tested SHA `c7eb3c8`:
  - **`34204647193`** (push event) و **`34204650926`** (pull_request event) → artifacts: `rwp-acceptance-wp_` و `rwp-acceptance-clinic_`.
  - (Run های قدیمی‌تر — `34200631238` / `34200634859` روی `19f48b4` — به‌عنوان سابقهٔ remediation باقی و **منسوخ** شدند.)
- **آیا تصاویر واقعاً visually inspected شده‌اند؟** — **بله، توسط Product Owner.** ابزارِ خودکارِ این محیط نتوانست image blobها را retrieve کند (بلاک شبکهٔ GitHub blob + عدم PHP/MySQL محلی)؛ بنابراین تهیهٔ شواهد و بازبینیِ ذهنی صرفاً توسط PO انجام شد. PO تصاویر Search را از Run `34204647193` بررسی و **PASS** کرد (جزئیات §0.ج).
- وجود screenshot artifact، `no_overflow` سبز، یا HTTP 200 را **جایگزین visual inspection نمی‌دانم**؛ اما این‌بار **بازبینیِ تصویری توسط PO انجام شد** → این بخش **CLOSED** است.

### Checklist بازبینی تصویری برای Product Owner
1. **RTL** — چیدمان راست به چپ درست؛ بدون شکستن در این direction.
2. **Horizontal overflow** — هیچ نوار اسکرول افقی ناخواسته در عرض 1440/768/390.
3. **Mobile navigation** — منوها/زیرمنوها در 390 باز و خوانا.
4. **Tables** — جدول‌ها در موبایل stack/overflow بدون بریدگی ستون.
5. **Forms** — فیلدها قابل مشاهده/لحن/دکمه در موبایل.
6. **Dialogs/confirmations** — پیام‌های confirm/هشدار درست رندر و خوانات.
7. **Status badges** — رنگ/موقعیت badge (فعال/غیرفعال/وضعیت)؛ بدون تداخل.
8. **Permissions UI** — جمع/بازشدن Advanced Permissions؛ جستجو؛ بدون هم‌پوشانی.
9. **Touch usability** — هدف‌های لمس کافی (منطقاً بزرگ).
10. **Clipping/collision** — هیچ text/button بریده یا تودرتوی ناخوانا.

---

## ۲۲. Defects Found / Fixed

- **Production defect (در کد جدید خودم — یک‌بار رخ داد و fixed):**
  - در `App::boot()` (namespace `ClinicCore\Bootstrap`) `PatientAdminPage` را بدون `use ClinicCore\Admin\PatientAdminPage;` فرا خواندم → شبیه‌سازی کلاس به `ClinicCore\Bootstrap\PatientAdminPage` → PHPStan/Integration خطا. **Fix:** افزودن `use` و push. (این یک defect تولیدی در کد جدید بود.)
- **Test defect (در تست خودم):**
  - در `PatientAdminTest::testSecretaryCreatePatientAndAuditLogged` ستون `subject_id` را که در schema وجود ندارد (به‌جای `resource_id`) پرسیدم → Integration شکست. **Fix:** تغییر به `resource_id`.
- **Test infrastructure issue (مربوط به محیط، نه کد):**
  - retrieve لاگ خام Job و image blob artifacts از ابزارهای محلی ممکن نشد (بلاک شبکه GitHub blob + عدم نصب PHP/WP). **این محدودیت محیطی بود، نه نقص plugin**؛ و باعث شد تهیهٔ شواهد/بازبینیِ ذهنی برون‌سپاری به PO شود — که اکنون انجام و PASS شد (§0.ج).
- تمایز: موارد بالا به‌ترتیب **production defect**، **test defect**، **test infrastructure issue** بودند. هیچ defect محصول در منطق نهایی باقی نمانده (همهٔ gateها سبزند).

---

## ۲۳. Residual Risks / Technical Debt

- **Screenshot visual inspection blocker:** *(اکنون CLOSED)* طبق §13، تا وقتی تصاویر واقعاً توسط PO مشاهده نشوند، criterion بازبینی تصویری UNVERIFIED می‌ماند. اکنون PO تصاویر را از Run `34204647193` مشاهده و **PASS** کرد و گیت تصویری بسته شد؛ بنابراین تنها بلاک قبلی **رفع شد** (جزئیات/معیارها §0.ج).
- **Boot-time `Settings->get(...)` قبل از migration (فعال‌سازی اول):** طبقه‌بندی = **benign activation-ordering + technical debt جزئی**. تأیید شد که نمی‌تواند نشت دهد (catch→''), activation را بشکند, نویز پایدار ایجاد کند یا شکست migration را mask کند. **اصلاح نشد** (اصل صرفاً cosmetic نیست). مستند به‌عنوان residual debt.
- **`SecretaryFinancePage` naming:** Technical Debt جزئی (نام منسوخ/مشترک); عمداً rename نشد (ریسک غیرلازم). گزارش شد.
- **Observation backend (پیش از این فاز، بدون تغییر):** `PatientRepository::find(int $id)` فیلتر `clinic_id` ندارد (فقط single-clinic V1؛ در multi-branch باید scope شود). این رفتار مرجع است، توسط این فاز تغییر نکرد، اما به‌عنوان observation باقی می‌ماند.
- **مورد PARTIAL/UNVERIFIED:** **دیگر هیچ‌کدام.** بازبینی تصویری اسکرین‌شات (بخش ۲۱) که قبلاً تنها مورد UNVERIFIED بود، پس از تأییدِ تصویری PO **CLOSED** شد؛ بنابراین هیچ مورد PARTIAL/UNVERIFIED در وضعیت نهایی وجود ندارد.

---

## ۲۴. پاسخ الزامی به §46 (ده سؤال اصلی)

1. **Repo/PR integrity — YES.** شاخه `arena/01a07d25-doctor`؛ production-tested SHA = `c7eb3c8`؛ remote tip = کامیتِ فقط-مستند این گزارش (**LOCAL == REMOTE ✅**)؛ PR #9 OPEN/draft، **بدون merge**. (mergeStateStatus اکنون `CLEAN`؛ مقدار قبلی `UNSTABLE` گذرا بود.)
2. **merge-base == main — YES.** `38c573bf…`=origin/main؛ شاخه فقط جلو؛ بدون conflict.
3. **سند مرجع — YES.** `docs/admin-ux-plan.md` (+ SRS + auth-authorization)؛ Chunk A–G Done؛ هیچ سند 0–48 یکپارچه‌ای وجود ندارد.
4. **امنیت نقش/authorization + IDOR منفی — YES.** تست‌های negative + 403 + capability-driven.
5. **نامه‌های کلاس/ثبت یکسان — YES.** `SecretaryFinancePage` صحیح/ثبت‌شده؛ بدون typo.
6. **جریان استاف/رمز فقط WP core — YES.** `wp_insert_user`/`wp_update_user`/`wp_generate_password`/`retrieve_password`؛ بدون password DB؛ بدون plaintext؛ خود-غیرفعال‌سازی.
7. **بدون privilege escalation — YES.** انتساب administrator ممنوع؛ matrix فقط `manage_options`.
8. **Real WP Acceptance روی ZIP رسمی — YES.** روی production-tested SHA `c7eb3c8` (run `34204650926` / `34204647193`): هر دو prefix **`307/0`**، بدون fatal/console error، probe OK، و `no_overflow` در 360/390/768/1024/1366 سبز. (برای سابقه، روی `19f48b4` همین گیت `184/0` بود — اکنون منسوخ.)
9. **Desktop/Tablet/Mobile (با visual verification) — YES.** اسکرین‌شات‌ها تولید شده‌اند (شامل remediation) و **توسط Product Owner راست‌آزمایی شدند** (`ADVANCED_PERMISSIONS_SEARCH_VISUAL_PO_REVIEW = PASS`) → از UNVERIFIED به **PASS** تبدیل شد.
10. **کامل بودن محدوده (Patient entry + IA) + responsive/asset — YES.** Patient entry با Reuse backend؛ asset-scope scoped؛ breakpoints؛ **remediation** (Responsive + Permissions UX + Confirmation + Search) بدون scope expansion.

---

## ۲۵. Final Verdict

> **به‌روزرسانی نهایی: این بخش مربوط به وضعیت میانی بود. اکنون با بخش «۰.ج» (FINAL CLOSURE) جایگزین/تکمیل می‌شود؛ وضعیت نهایی و authoritative: `ADMIN_UX_READY` — و `PRODUCT_OWNER_VISUAL_ACCEPTANCE = PASS`، `§46 = ۱۰/۱۰ YES`.**

# TECHNICAL_REMEDIATION_COMPLETE + VISUAL_PO_REVIEW_REQUIRED *(سابقهٔ remediation اول)*

**NOT `ADMIN_UX_READY` (در آن زمان).** این سابقهٔ تاریخی است: تمام اصلاحات BLOCKER A–D انجام شد، CI روی ZIP رسمی سبز شد (`184/0` در هر دو prefix)، overflow سبز، Confirmation/Dialog واقعی گرفته شد، شواهد Schedule تولید شد؛ اما در آن لحظه بازبینیِ تصویریِ واقعی هنوز توسط PO انجام نشده بود و به همین دلیل `VISUAL_PO_REVIEW_REQUIRED` صادر شد.

**اکنون که PO بازبینیِ تصویری را انجام و PASS کرده، گیت تصویری بسته است و وضعیت نهایی `ADMIN_UX_READY` است (رجوع به §0.ج FINAL CLOSURE).** این بخش صرفاً سابقهٔ تاریخی است و با وضعیت نهایی تناقض ندارد.

---

# PRODUCT OWNER HANDOFF — COMPLETED (بازبینی انجام شد)

**بازبینی تصویری PO قبلاً انجام و تأیید شده است** (`PRODUCT_OWNER_VISUAL_ACCEPTANCE = PASS`). بخش زیر برای سابقهٔ شواهد و مسیر review نگهداری می‌شود:

1. **GitHub Actions run/artifact (معتبر — SHA `c7eb3c8`):**
   - **`34204647193`** (push event) و **`34204650926`** (pull_request event) → artifacts: `rwp-acceptance-wp_` و `rwp-acceptance-clinic_` → پوشهٔ `screenshots/`.
   - (سابقهٔ remediation روی `19f48b4`: `34200631238` / `34200634859`؛ و قبلی روی `1d40f95`: `34195050795` / `34195047071` — همه منسوخ.)

2. **Screenshotها:** Staff/overflow (`cpms-m-staff`, `cpms-m-clinicians`, `cpms-t-staff`, `cpms-t-clinicians`, `cpms-360-staff`, `cpms-360-clinicians`, `cpms-1024-staff`, `cpms-1366-staff`)؛ Advanced Permissions (`cpms-Desktop|tablet|mobile|-roles-advanced-*` شامل collapsed/initial/group/search/search-noresult/search-cleared)؛ Schedule (`cpms-desktop|tablet|mobile|360-schedule`)؛ Confirmation/Dialog (`cpms-dialog-desktop|tablet|mobile`).

3. **Criteria که بررسی شد:** RTL؛ بدون horizontal overflow؛ tables موبایل به‌صورت کارت ردیفی؛ اکشن‌ها قابل‌لمس؛ Advanced Permissions groupe جمع‌شونده + شمارنده + نشانگر حساس + جستجو؛ modal تأیید متمرکز؛ status badges؛ بدون clipping/collision. (لیست کامل §21.)

4. **نتیجه:** تمام موارد بصری تأیید شدند → criterion §9 (تصویری) **CLOSED** و `ADMIN_UX_READY` صادر شد.

**وضعیت نهایی:** هیچ feature جدیدی اضافه نشد؛ هیچ test weakened نشد؛ هیچ commit ترجمه‌ای ایجاد نشد؛ Production code بعد از `c7eb3c8` تغییر نکرد (فقط این گزارش مستند). **merge و undraft انجام نشد.** تصمیم merge با PO است.

**STOP برای Product Owner Merge Decision.**
