# ADMIN UX — POST-MERGE HANDOFF (durable)

> **هدف:** این سند برای agent بعدی است که پس از merge PR #9 و از بین رفتن این session، بتواند بدون نیاز به conversation history، وضعیت واقعی پروژه، acceptanceهای انجام‌شده و مراحل post-merge را بفهمد.
>
> **نکتهٔ حیاتی:** مقادیر این سند در **زمان تهیه (قبل از merge)** از Git/GitHub **مستقیماً و یک‌بار دیگر verify** شدند. agent بعدی باید همین مقادیر را دوباره از source راستی‌آزمایی کند (نه کورکورانه از این سند).

---

## ۱) EXACT VERIFIED STATE (قبل از merge)

| مورد | مقدار (exact) |
|---|---|
| Repository | `bia2on2on/doctor` |
| PR | **#9** — `OPEN` |
| Base branch | `main` |
| Feature branch | `arena/01a07d25-doctor` |
| **CURRENT / PRE-MERGE PR HEAD** | **`607dcc15bf0c9925d8e9efe5f655853631c0bb27`** *(در زمان نگارش این سند قبل از کامیت‌های docs بعدی — با هر docs commit تغییر می‌کند)* |
| **PRODUCTION-TESTED SHA** | **`c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1`** |
| Pre-merge main | `38c573bf2c74814cdb5897e1a260081f8a07e7f1` |

> **⚠️ سه SHA متمایز و مستقل — هرگز با هم اشتباه نشوند:**
> 1. **PRODUCTION-TESTED SHA** = `c7eb3c801f123dc44c3dc8e3cbcb5d76d2170fd1` — آخرین SHA که production changes دارد و Official ZIP/CI/RWP/PO-screenshots روی آن اجرا/تأیید شد.
> 2. **FINAL CLOSURE REPORT SHA** = `607dcc15bf0c9925d8e9efe5f655853631c0bb27` — SHA ای که گزارش نهایی در آن ثبت شد (historical؛ دیگر لازم نیست برابر HEAD فعلی باشد).
> 3. **CURRENT / PRE-MERGE PR HEAD AT MERGE TIME** = مقدارِ پویا؛ در هر لحظه با آخرین کامیتِ روی شاخه تغییر می‌کند (در زمان نگارش این سند = `8ba4f37…` در وضعیت فعلی و ممکن است با هر docs commit دیگری باز هم تغییر کند).
>
> **قاعدهٔ درست برای second parent (نه SHA ثابت):**
> **EXPECTED SECOND PARENT = PR #9 HEAD SHA IMMEDIATELY BEFORE MERGE** — که باید در لحظهٔ merge از GitHub (`gh api repos/.../pulls/9 --jq '.head.sha'`) خوانده شود، نه از یک SHA hard-coded در این سند.

### Verified PR #9 state (query منبع)

- **OPEN** ✅
- **draft** = `true` ✅
- **mergeable** = `MERGEABLE` ✅
- **mergeStateStatus** = `CLEAN` ✅
- Checks: **۱۷/۱۷ PASS** ✅ (5 Closure + 1 Integration + 2 Real WP + 1 Release Artifact + 1 Responsive smoke + 1 Staging Gate + 1 Static Analysis + 4 Unit (8.1/8.2/8.3/8.4) + 1 Upgrade path)
- **No pending / No failed** ✅
- **No conflict** ✅ (base_sha = main؛ head_sha = SHA فعلی PR در زمان بررسی — پویا)
- Reviews `0`؛ review requests `0`؛ ruleset `Protect main`: `required_approving_review_count=0` + هیچ required-status context
- **§46 = ۱۰/۱۰ YES** ✅
- **PRODUCT_OWNER_VISUAL_ACCEPTANCE = PASS** ✅
- **`ADMIN_UX_READY`** ✅
- **`READY_FOR_PRODUCT_OWNER_MERGE`** ✅

> Merge-base (HEAD vs origin/main) = `38c573bf…` == origin/main؛ ahead/behind = ۰ behind / ۳۶ ahead.
> Working tree قبل از this handoff = فقط `docs/pre-merge-verification-pr8.md` untracked؛ بدون tracked-modified؛ 0 stash.

---

## ۲) COMPLETED PRODUCT SCOPE (که PR #9 پیاده و verify کرد)

PR #9 «CPMS Professional Admin UX — Phase (IA + Dashboard + Setup + Staff)» موارد زیر را پیاده و تأیید کرده (۳۶ commit در range origin/main..HEAD):

- **Coherent CPMS navigation**: منوی Top-Level «مدیریت مطب» (`CpmsAdminMenu`) + re-home صفحات پراکنده Tools/Settings (با حفظ slug + redirect قدیمی).
- **Dashboard**: نقش‌آگاه (`CpmsDashboard`) به‌عنوان صفحهٔ فرود.
- **Onboarding**: notice برای مدیر تا تکمیل راه‌اندازی (`CpmsAdminMenu::onboardingNotice`).
- **Setup Wizard**: ۱۲گام، resumable، فارسی (`CpmsSetupWizard`).
- **Plugin action links**: فقط لینک‌های capability-driven (`CpmsAdminMenu::actionLinks`).
- **Staff/User Management**: صفحهٔ «کاربران و دسترسی‌ها» (`StaffManagementPage`, `cpms-staff`, گِیت `cpms_config`) — ساخت/ویرایش/فعال/غیرفعال، نقش، پیوند پزشک.
- **Account/password flow**: فقط WP core (`wp_insert_user`/`wp_update_user`/`wp_generate_password`/`retrieve_password`)؛ بدون password DB جدا؛ بدون plaintext.
- **Patient Management**: منوی «بیماران» گِیت‌شده با `cpms_patient_read` (`PatientAdminPage`) + جستجوی bounded و ایجاد فقط با `cpms_patient_create` (Reuse `PatientService`؛ بدون منطق پزشکی جدید).
- **Doctor Management**: `ClinicianAdminPage` (`cpms-clinicians`, `cpms_config`) ساخت/پیوند doctor (`upsertUser` reuse).
- **Schedule**: صفحهٔ پزشکان + برنامه؛ scheduler‌های مسیر ویزیت؛ schedule impact در جریان doctor.
- **Role presets**: `RoleCapabilitiesPage` (Normal) با «می‌تواند/نمی‌تواند» + توضیح فارسی + هشدار حساس.
- **Advanced Permissions**: collect/group/search/Persian؛ Advanced پشت `<details class="cpms-advanced">`؛ گروه‌های `cpms-cap-group`؛ boundary = `manage_options` + Nonce + Audit.
- **Responsive permission search remediation**: جستجو فقط ردیف‌های match (display فقط)؛ بدون checkbox wall؛ حالت empty فارسی؛ پاک‌کردن → بازگشت به initial؛ **هرگز `checked` را تغییر نمی‌دهد**.
- **Finance navigation**: منوی «مالی و تسویه» (`SecretaryFinancePage`, گِیت `cpms_finance_read`) + نقش accountant.
- **License/Backup/Restore/Update/Health/System**: `SystemPage` (`cpms-system`) + `SettingsAdmin` (`cpms-settings`) زیر «مدیریت مطب».
- **Persian/RTL / responsive UX / accessibility**: فارسی، RTL، breakpoints (360/390/768/1024/1366/1440)، `<details>/<summary>` بومی، aria-live، accessible name.
- **Role-aware navigation / authorization/security**: capability-gated menus + denialهای 403 (منوی پنهان ≠ authorize).
- **Audit**: `STAFF_USER_*`، `ROLE_PERMISSION_CHANGED`/`RESET`، actions مالی و غیره.
- **Official ZIP acceptance**: `real-wp-acceptance.yml` → ZIP رسمی (`bin/build-release.sh`) → `wp plugin install "$ZIP"` در WP 6.7.2 تمیز → Playwright/Chromium روی ۵ نقش.

---

## ۳) SECURITY INVARIANTS (agent بعدی باید این‌ها را NOT weaken کند)

- **hidden menu ≠ authorization** — منوی پنهان دسترسی نمی‌دهد؛ گیت سمت سرور مرجع است.
- **WP Administrator ≠ automatic medical access** (P-3) — admin فقط `cpms_config` + `cpms_sms_config`.
- **Clinic Manager ≠ private clinical access** — Manager بالینی/private-level ندارد.
- **Accountant ≠ clinical/patient/queue access** — فقط مالی/گزارش/export.
- **Advanced Permissions security boundary = `manage_options`** — ماتریس/Advanced فقط برای مالک فنی.
- **WordPress remains sole password/auth source** — هیچ auth/password DB جدا.
- **no separate password DB**، **no plaintext passwords/tokens in logs/audit**.
- **Patient/clinician/resource IDOR protections** — negative tests.
- **search filtering does not alter checkbox/capability state** — فقط `display`؛ `checked` هرگز لمس نمی‌شود.
- **`role_caps[role][]` backend contract preserved** — nonce + `manage_options` + `setRoleCaps` + reset + audit.

---

## ۴) VISUAL ACCEPTANCE (Product Owner واقعاً تصاویر را بررسی کرد)

- PO **واقعاً** تصاویر را از (**Run `34204647193`**) بررسی کرد، نه فقط وجود artifact.
- PASS برای: **Staff Mobile** · **Doctors Mobile** · **Patients** · **Schedule Desktop/Tablet/Mobile** · **Confirmation Modal** · **Advanced Permissions Collapsed** · **Advanced Permissions initial** · **Advanced Permissions Group** · **Advanced Permissions Search** · **Search No Result** · **Search Clear** · **Persian/RTL** · **360/390/768 critical layouts**.
- **RWP baseline (pre-merge):** `307 passed / 0 failed` برای هر دو `wp_` و `clinic_`.
- Relevant final remediation evidence: Run **`34204647193`** (push، روی production-tested SHA `c7eb3c8`).

---

## ۵) NON-BLOCKING FOLLOW-UP

### NON_BLOCKING_PRODUCT_POLICY_REVIEW
**Doctor** (تنها در scope مذکور) به‌صورت **عمدی** capabilityهای مالی مستند دارد شامل:
`cpms_payment_void`، `cpms_payment_refund`، و موارد مرتبط `cpms_invoice_read`، `cpms_invoice_create`، `cpms_finance_read`، `cpms_report_read` — طبق permission-matrix.md v1.5 §4.3/rows 138–142.

- **این intentional/verified است و برای PR #9 blocker نیست.**
- **agent بعدی نباید این را در طول post-merge verification تغییر دهد.**
- نیاز به **تصمیم جداگانهٔ Product/policy/security** بعداً دارد.

### Non-blocking technical debt (هر جا still applicable)
- **Boot-time `Settings->get(...)` activation-order**: benign؛ تأیید شد نمی‌تواند نشت یا activation را بشکند؛ مستند به‌عنوان debt (اصلاح عمداً نشد).
- **`SecretaryFinancePage` naming**: tech debt جزئی (نام منسوخ/مشترک)؛ عمداً rename نشد.
- **`PatientRepository::find(int $id)` بدون فیلتر `clinic_id`** (single-clinic V1): observation از پیش موجود، خارج از scope.
- **CI infrastructure warning Node.js 20 deprecated** (actions به Node 24 forced): non-blocking؛ CI config تغییر نکرد.
- (جزئیات جامع‌تر در `docs/verification-report-admin-ux-final.md` §0.ج §8.)

---

## ۶) POST-MERGE TASK FOR NEXT AGENT (executable checklist)

بعد از اینکه **Product Owner** PR #9 را با **MERGE COMMIT** (merge commit، نه squash/rebase) به‌صورت دستی merge کرد، agent بعدی:

1. **Fetch latest `origin/main`** (`git fetch origin main`).
2. **Verify PR #9 status = `MERGED`** (`gh pr view 9 --json state`).
3. **Discover & record** (همه از GitHub query شده، نه از این سند): `PR_HEAD_BEFORE_MERGE = gh api repos/bia2on2on/doctor/pulls/9 --jq '.head.sha'` (قبل از merge)؛ سپس بعد از merge، `merge_commit_sha = gh api repos/.../pulls/9 --jq '.merge_commit_sha'` + `git rev-parse`، first parent، second parent، resulting `origin/main` SHA.
4. **Verify second parent == `$PR_HEAD_BEFORE_MERGE`** — یعنی **دقیقاً HEAD واقعی PR در لحظهٔ merge**. **نه** یک SHA hard-coded تاریخی مثل `607dcc1` یا `8ba4f37`. (می‌توانید `git cat-file -p $merge_commit_sha` را بزنید و والد دوم را با `$PR_HEAD_BEFORE_MERGE` مقایسه کنید.)
5. **Verify merge strategy was a real two-parent merge commit**, not squash/rebase (`git cat-file -p <merge>` → دو والد؛ single-parent = نه).
6. **Monitor workflows** triggered on merged `main` (`gh run list --json ...`).
7. **Verify `main`**: PHPStan · Unit PHP 8.1/8.2/8.3/8.4 · Integration · Closure/security gates · Release Artifact · Upgrade · Responsive smoke · Pilot/Staging · Real WordPress Acceptance.
8. **Verify RWP on MAIN uses official ZIP** (`real-wp-acceptance.yml` → `bin/build-release.sh` → `wp plugin install "$ZIP"`)، نه source mount.
9. **Verify both** `wp_` و `clinic_` prefixes.
10. **Expected pre-merge behavioral baseline: `307 passed / 0 failed`.** اگر متفاوت بود، investigate؛ نه skip.
11. **Build/identify official ZIP from merged main** و گزارش: filename · version · main SHA · SHA256 در صورت دسترسی · packaging-policy result.
12. **Confirm no regression** به security invariants بالا ($۳).
13. **Do not change production code merely because session changed.**
14. **If a post-merge test fails:** investigate first؛ Do not weaken/skip tests؛ Do not fake PASS.
15. **Do not delete feature branch automatically.**

---

## ۷) NEXT AGENT FINAL STATUS

agent بعدی باید با **دقیقاً یکی** از این دو پایان دهد:

- **`MERGE_AND_POST_MERGE_ACCEPTANCE_COMPLETE`**
- **`POST_MERGE_ACCEPTANCE_FAILED`**

**نباید صرفاً به‌دلیل merge شدن PR #9 ادعای success کند.**

---

## ۸) NEW AGENT BOOTSTRAP PROMPT

```
read docs/admin-ux-post-merge-handoff.md
read docs/verification-report-admin-ux-final.md
git fetch origin main
verify all SHAs instead of trusting the document blindly
# critical: query PR #9 on GitHub first
gh pr view 9 --json state,mergeable,mergeStateStatus,headRefName,baseRefName
gh api repos/bia2on2on/doctor/pulls/9 --jq '{head:.head.sha, base:.base.sha, merge_commit_sha:.merge_commit_sha, state, mergeable, mergeable_state}'
# the expected second parent must equal .head.sha captured IMMEDIATELY BEFORE merge,
# NOT a hard-coded SHA from the document
perform POST-MERGE verification only
do not redo Admin UX
do not modify Doctor finance policy
report in Persian
```
