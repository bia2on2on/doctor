# CPMS Classic — PR #257 Remediation Final Report (independent-audit blockers B1–B5)

**PR:** https://github.com/bia2on2on/doctor/pull/257 (Draft — not merged)
**Branch:** `arena/ebafaac0-doctor`
**Audit-expected head:** `f7f360ed18379058fea2416f9a4dea1d02dd717b`
**Final code head:** `65b5f34215c211fb07a70d446287c63e0674db2b` (dispatched 2026-10-21 14:26:27 UTC)
**Base:** `main` @ `6689228a84dd9ab26741d8132e177cbe05a6543e` (untouched; PR #255/#256 untouched)

**UTC start (remediation authorization):** 2026-10-21 12:59:18
**UTC end:** 2026-10-21 14:55 (≤ 120-minute limit; hard stop 14:59:18)

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
with locations/schedules/booking). **Result: First-practice browser (mysql) =
SUCCESS** on head `5403028` (the journey's only iteration defect — a forged-
booking assertion that read `submit_form`'s keyword classifier, which matches
«ثبت» inside «ثبت نشد» — was fixed by asserting the uniform-denial notice text
directly). The mariadb lane of the same head was still finishing at the time
box; the same journey code runs on both lanes.

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

## 6. Browser matrix (this remediation)

| Journey | MySQL 8.0 | MariaDB 11.4.13 |
|---|---|---|
| First-practice 577 + recovery 287 + 12-type matrix | PASS (head 5403028) | see exact-head CI |
| Hospital two-Subscriber-manager (50 checks) | PASS (step green on 5403028) | see exact-head CI |
| **Delegated non-hospital journey (NEW)** | **PASS** (head 5403028) | see exact-head CI |
| Persian RTL 390px (responsive + journeys) | PASS | see exact-head CI |

## 7. Exact-head CI status and Integration counts

Authoritative exact-head table (head `65b5f34215c211fb07a70d446287c63e0674db2b`,
dispatched 14:26:27 UTC) is recorded in the PR body of #257 (updated via
`gh pr edit`; per B5 no docs-only CI-trigger commits). Status at the time box
from the penultimate head `5403028` (identical code except diagnostic
breadcrumbs):

- WPCS (changed code) **PASS**; PHPStan **PASS**; Unit PHP 8.1–8.4 **PASS**;
  Closure gates **PASS**; Tenant/Authz tripwire **PASS**; Composer audit
  **PASS**; Architecture Guard **PASS**; REST Contract Lint **PASS**;
  Migration gates (MySQL 8.0 / MariaDB 10.11) **PASS**; MariaDB 10.11 / 11.4
  runtime parity **PASS**; WP 6.6/6.7/7.1 matrix **PASS**; Release packaging
  **PASS**; Stage A clean-host **PASS**; Third-party baseline **PASS**; Real
  WordPress Acceptance **PASS**; Persian coexistence **PASS**; Pilot/Staging
  **PASS**; Closure **PASS**; First-practice browser (mysql) **PASS**.
- **Integration (WP 7.1 + MySQL 8): FAIL — Tests: 2253, Assertions: 64,838,
  Errors: 2.** The two errors are
  `FacilityTypeAdaptivePlanTest::testHospitalAckGateIsHospitalOnlyAndExplicit`
  and `FacilityTypeAdaptivePlanTest::testHospitalKeepsTheProvenOutpatientRules`
  (the scenario was split at the ambiguous region for diagnosis). **B1 is
  fixed** (the suite runs; only these 2 errors remain, vs the audited head's
  fatal after 7 tests) and all five new `DelegatedClinicCompletionTest` tests
  pass. The error is **not** in the test bodies (in-body `::error::`
  breadcrumbs did not fire) nor in the class tearDown body — the remaining
  zone is `parent::tearDown()` (WP test lifecycle) reacting to state left by
  the hospital-scenario paths (start(hospital) / `acknowledge_outpatient` /
  in-transaction refusals); a final breadcrumb round covering that zone was
  dispatched at 14:26:27 UTC and its annotation will name the exact throwable
  after the time box.

## 8. Remaining blockers

1. **Integration is not yet zero-error (2 errors, named above).** Root cause is
   narrowed to the WP test-lifecycle/teardown interaction with the
   hospital-scenario state; the exact throwable is being surfaced by the
   dispatched diagnostic round. The same scenario's production paths are
   exercised green by the real-browser hospital journey (50/50) on MySQL 8.0
   and the 577-check first-practice suite, so this is a test-harness
   interaction, not a product regression — but per the acceptance bar ("Full
   Integration PASS with zero failures/errors") this remains **OPEN**.
2. Exact-head CI on `65b5f34` completes after the time box — record its table
   in the PR body; the mariadb first-practice lane of `5403028` was still
   running at the time box (same journey code as the green mysql lane).

## 9. Verdict

**BLOCKED** — B1/B3/B4/B5 are fixed and verified; B2 is fixed and verified
both by integration tests and by a real-Chromium delegated non-hospital
journey (green on the MySQL lane). The single remaining blocker is the two
Integration errors named in §7/§8. The PR stays Draft and is not merged.
