# CPMS Classic — PR #257 Remediation Final Report (independent-audit blockers B1–B5)

**PR:** https://github.com/bia2on2on/doctor/pull/257 (Draft — not merged)
**Branch:** `arena/ebafaac0-doctor`
**Audit-expected head:** `f7f360ed18379058fea2416f9a4dea1d02dd717b`
**Last code head (all 26 checks SUCCESS):** `87c8c93b4642c3ac749a81c3403a4cb76620a46d`
**This cleanup head:** the commit that removes the temporary `FTA-DEBUG`
diagnostics from `FacilityTypeAdaptivePlanTest.php` and updates this report
(diff limited to these two files; no production, security, browser-workflow or
assertion change).
**Base:** `main` @ `6689228a84dd9ab26741d8132e177cbe05a6543e` (untouched; PR #255/#256 untouched)

**UTC start (remediation authorization):** 2026-10-21 12:59:18 ·
**remediation end:** 14:55
**UTC start (pre-merge cleanup):** 2026-10-21 15:57:39

---

## 1. B1 — CRITICAL: Integration PHP Fatal — FIXED and VERIFIED

Cause (confirmed at the audited head): the `e71874b1` test-edit splice matched
an earlier end-marker occurrence, leaving a fragment referencing an undefined
`$membership_id` inside `test_forbidden_clinical_and_financial_role_delegation_is_refused`
and duplicating 8 whole test methods → `Cannot redeclare …` fatal
(`phpunit_exit=255` after 7 tests).

Fix: surgical removal of the spliced fragment + all 8 duplicate method bodies
(2049 → 1517 lines; duplicate-method scan clean). The original tests are
preserved; the delegation test carries the P2 contract (positive preset
delegation for doctor/accountant/secretary; negative for `ROLE_MANAGER` and
unknown role keys).

**Verified:** the suite now loads and runs on CI — **Tests: 2253, Assertions:
64,838** (vs 7 tests before the fix). The B1 fatal is gone.

## 2. B2 — HIGH: delegated non-hospital Clinic completion — FIXED and VERIFIED

Cause (confirmed): `clinic_readiness()` exposed only `hospital`, and the portal
gated the Clinic readiness card + `clinic_facility_booking` form on
`readiness['hospital']` — so a delegated Subscriber Clinic Manager could not
complete `doctor_practice`/`general_clinic` setups from the UI.

Fix: `clinic_readiness()` now returns `flow` + `requires_booking` (from
`FacilityTypeCatalog::plan_rules`); the template gates the card on
`requires_booking` with a flow-general heading («تکمیل راه‌اندازی مرکز — همین
درمانگاه»). Hospital behavior preserved (card + `data-cpms-clinic-readiness`
contract the proven hospital journey asserts — that journey stayed green);
medical-building behavior preserved (card stays hidden, as before).

Regression tests (`tests/Integration/DelegatedClinicCompletionTest.php`, 5
tests — all passing on CI): delegated **general_clinic** completion (location +
2 clinicians + 2 schedules + booking) + org-manager atomic activation;
delegated **doctor_practice** completion (1 clinician) + activation; cross-Clinic
uniform denial; Organization Manager cannot silently perform Clinic-surface
operations; readiness/booking-form render assertions for both flows.

**Real-browser verification (Chromium):** NEW `bin/rwp-portal-delegated-journey.py`
wired into `first-practice-acceptance.yml` drives the full delegated journey
through the frontend portal only (two Subscriber clinic managers + org
manager): Section A `general_clinic` with 2 Clinics, readiness panel + booking
form rendered for the delegated managers (the B2 assertions), per-Clinic
completion (390px RTL no-overflow), forged cross-Clinic booking refused with
the uniform denial, org surface without Clinic forms
(`data-cpms-clinic-steps-delegated`), atomic activation; Section B
`doctor_practice` compact completion + activation; DB facts (active Clinics
with locations/schedules/booking). **Result: `First-practice browser (mysql)`
and `First-practice browser (mariadb)` both SUCCESS on head `87c8c93`** (the
journey's only iteration defect — a forged-booking assertion that read
`submit_form`'s keyword classifier, which matches «ثبت» inside «ثبت نشد» — was
fixed by asserting the uniform-denial notice text directly).

## 3. B3 — MEDIUM: cross-form stale input collision — FIXED and VERIFIED

Fix: `$cpms_mp_form_open()` records the form being rendered; `$cpms_mp_field`
restores stale values **only** when `form_state['action']` matches that form.
Regression test (in `DelegatedClinicCompletionTest`) verifies isolation in both
directions between two forms sharing `cpms_entity_name`/`address`
(`clinic_location_create` vs `clinic_update_profile`) — passing on CI.

## 4. B4 — MEDIUM: wrong error classification — FIXED and VERIFIED

`activate_facility_setup()` / `save_clinic_booking_policy()` now map
`FacilitySetupException` via `facility_failure()` (`not_ready` →
`prerequisite`, `invalid_booking` → `invalid`); `designate_facility_manager_by_identifier()`
returns `invalid` for empty input and `prerequisite` for an unknown account
**after** authorization succeeded; unauthorized callers keep the uniform
non-disclosing `denied` (fail-closed; the user lookup still happens only after
`authorize_manager_designation`, so the path is not an existence probe).
Regression test `testNoticeCategoriesDistinguishInvalidPrerequisiteAndDenied`
— passing on CI.

## 5. B5 — LOW: template + report cleanup — FIXED

- duplicate sites `foreach` in `#cpms-mgmt-room-site` removed (duplicate
  `<option>` entries gone);
- this report corrects the inaccurate Integration claims of the audited head
  (that head failed Integration with the B1 fatal; nothing is claimed green
  without a check-run);
- no documentation-only CI-trigger commits: this report ships with the code
  commits; live CI status is maintained in the PR body via `gh pr edit`.

## 6. Browser matrix

| Journey | MySQL 8.0 | MariaDB 11.4.13 |
|---|---|---|
| First-practice 577 + recovery 287 + 12-type matrix | **PASS** | **PASS** |
| Hospital two-Subscriber-manager (50 checks) | **PASS** | **PASS** |
| **Delegated non-hospital journey (NEW)** | **PASS** | **PASS** |
| Persian RTL 390px (responsive smoke + journeys) | **PASS** | **PASS** |

Evidence: `First-practice browser (mysql)` and `First-practice browser (mariadb)`
both `success` on head `87c8c93` (each lane runs the first-practice suite,
recovery fixtures, the hospital two-manager journey and the delegated
non-hospital journey); `Responsive smoke (Chromium ×4 viewport)` `success`.

## 7. Exact-head CI status and Integration counts

**Head `87c8c93b4642c3ac749a81c3403a4cb76620a46d`: 26 check-runs, 26 SUCCESS,
0 failure, 0 skipped** (retrieved from the GitHub check-runs API for that exact
SHA). The 26 checks are:

Closure ×4 (`PHP 8.1`, `PHP 8.3`, `PHP 8.4`, `destructive restoreApply
(isolated)`) · `WPCS (changed code)` · `Static Analysis (PHPStan)` · `Unit
Tests` PHP 8.1/8.2/8.3/8.4 · `Integration (WP 7.1 + MySQL 8)` · `Tenant
Tripwire (hardcode scan)` · `Release Artifact (build + policy)` · `Staging Gate
(fresh install → restore drill)` · `Upgrade path (pinned pre-#191 source →
candidate)` · `Real WP Acceptance` (`prefix clinic_`, `prefix wp_`) ·
`Responsive smoke (Chromium ×4 viewport)` · `First-practice browser (mysql)` ·
`First-practice browser (mariadb)` · `coexistence` ×6 (`Real WP Acceptance
prefix wp_`, `combined-nineteen`, `combined-ten`, `persian-woocommerce-diagnosis`,
`persian-woocommerce-sms`, `security-authentication`).

**Integration (WP 7.1 + MySQL 8): PASS — 2253 tests, 0 errors, 0 failures.**
Evidence levels, stated precisely:
- the test INVENTORY is 2253 (printed by the immediately preceding run on
  `6b18658`: `Tests: 2253, Assertions: 64,839, Errors: 2`; `87c8c93` changed
  only statement ORDER inside two tests — it added and removed no test);
- the run on `87c8c93` is `success` and carries **no failure annotation** (the
  two previously failing tests, `FacilityTypeAdaptivePlanTest::testHospitalAckGateIsHospitalOnlyAndExplicit`
  and `testHospitalKeepsTheProvenOutpatientRules`, are green);
- the green run's exact assertion total is **not published** by the check-runs
  API (empty output summary) and the `integration-phpunit-log` artifact cannot
  be downloaded from this environment (Azure blob host is not reachable), so no
  assertion number is claimed for it.

**Root cause of the last two Integration errors (now fixed, `87c8c93`):** the
tests were wrong, not the product — instrumented breadcrumbs proved
`FacilitySetupException` «پیش از ایجاد درمانگاه، محدودهٔ فقط سرپایی بیمارستان را
صریحاً تأیید کنید» at `FacilitySetupService.php:357`: `add_clinic` requires the
hospital outpatient acknowledgment BEFORE the first Hospital Clinic is created
(the same order the proven `hospitalReady()` helper and the real-browser
journey use). The tests acknowledged afterwards; the acknowledgment was moved
before `add_clinic`.

## 8. Remaining blockers

**None** for the scope of this PR. The temporary `FTA-DEBUG` diagnostics used to
prove the last root cause are removed in this cleanup commit; production logic,
security policy, browser workflows and test assertions are unchanged.

(Standing product-scope note, unchanged by this PR: MariaDB 11.8 is diagnosed
but **not** certified — the engine gate is untouched and real MariaDB 11.8
migration execution remains NOT RUN; see §5 of the remediation record in the PR
history.)

## 9. Verdict

**READY FOR REVIEW (Draft)** — all five independent-audit blockers are fixed
and verified; 26/26 exact-head checks pass; Integration passes with 2253 tests
and zero errors/failures; both MySQL and MariaDB browser lanes pass. Per the
task instructions the PR stays **Draft** and is **not merged**.
