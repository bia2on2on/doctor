# CPMS Classic — PR #257 Remediation Final Report (independent-audit blockers B1–B5)

**PR:** https://github.com/bia2on2on/doctor/pull/257 (Draft — not merged)
**Branch:** `arena/ebafaac0-doctor`
**Expected head at audit:** `f7f360ed18379058fea2416f9a4dea1d02dd717b`
**Final head:** the commit that ships THIS report together with the B1–B5 code
fixes (see the PR head SHA — per audit B5 no documentation-only CI-trigger
commits are made, so the report cannot name its own commit hash).
**Base:** `main` @ `6689228a84dd9ab26741d8132e177cbe05a6543e` (untouched; PR #255/#256 untouched)

**UTC start (remediation authorization):** 2026-10-21 12:59:18
**UTC end:** (filled in the closing chat/PR summary; hard stop 120 minutes after start)

---

## 1. B1 — CRITICAL: Integration PHP Fatal in `ManagementPortalTest.php` — FIXED

Root cause (confirmed by inspection of the audited head): the earlier
`e71874b1` test-edit was applied by a splice that matched an earlier occurrence
of its end-marker, which (a) left a fragment of
`test_capability_rules_are_bounded_by_the_actors_own_authority()` inside
`test_forbidden_clinical_and_financial_role_delegation_is_refused()` referencing
an undefined `$membership_id`, and (b) duplicated 8 entire test methods
(`test_location_scope_assignments_require_active_locations_of_the_same_clinic`
… `test_forbidden_clinical_and_financial_role_delegation_is_refused`) a second
time in the class body → `Cannot redeclare …` PHP Fatal on load
(`phpunit_exit=255` after 7 tests).

Fix (surgical, no assertions weakened):
- the misplaced fragment is removed (the method now closes cleanly after its
  positive/negative staff-delegation assertions);
- all 8 duplicate method bodies are removed (2049 → 1517 lines; verified zero
  duplicate method names in the class);
- the preserved/added coverage in
  `test_forbidden_clinical_and_financial_role_delegation_is_refused` is the P2
  contract: positive preset delegation for `ROLE_DOCTOR`, `ROLE_ACCOUNTANT`,
  `ROLE_SECRETARY`; negative for `ROLE_MANAGER` (manager designation is never a
  staff-role delegation) and for unknown role keys;
- every other original test method is untouched.

Verification: `php-parser` syntax OK; duplicate-method scan clean; the full
Integration suite (including `FacilityTypeAdaptivePlanTest` and
`DelegatedClinicCompletionTest`) is expected to load and run on this head —
exact counts recorded from CI (§5).

## 2. B2 — HIGH: delegated non-hospital Clinic completion — FIXED

Root cause (confirmed at the audited head):
`FacilitySetupService::clinic_readiness()` exposed only `hospital`
(`flow === 'hospital_network'`), and
`templates/management-portal-shell.php` gated the whole Clinic readiness card +
`clinic_facility_booking` form on `! empty($cpms_mp_readiness['hospital'])` —
so for `doctor_practice`/`simple_practice` and the 9+1 `multi_doctor_clinic`
types, a delegated Subscriber Clinic Manager saw NO booking form and NO
readiness panel while the Organization surface intentionally withholds Clinic
steps from the Organization Manager (`data-cpms-clinic-steps-delegated`) — the
plan could not be completed from the UI.

Fix:
- `clinic_readiness()` now also returns `'flow'` and
  `'requires_booking' => (bool) $rules['require_booking_policy']` (docblock
  shape updated); `hospital` is kept for compatibility;
- the template gates the readiness card + booking form on
  `requires_booking` (true for simple_practice / multi_doctor_clinic /
  hospital_network) and the heading is flow-general
  («تکمیل راه‌اندازی مرکز — همین درمانگاه»). Medical-building behavior is
  preserved exactly (`require_booking_policy = false` → card stays hidden, same
  as before); hospital behavior is preserved exactly (card + form + the
  `data-cpms-clinic-readiness` contract the proven browser journey asserts).

Regression tests added (`tests/Integration/DelegatedClinicCompletionTest.php`,
5 tests):
1. delegated **general_clinic** (multi_doctor_clinic) Subscriber manager
   completes location + 2 clinicians + 2 weekly schedules + booking policy on
   the Clinic surface; readiness `ready` with `requires_booking=true`; the
   rendered portal page contains the readiness card and the booking form (B2
   UI regression); the Organization Manager then activates atomically
   (held → active; activation recorded on the plan);
2. delegated **doctor_practice** (simple_practice) completes with 1 clinician
   and activates the same way;
3. cross-Clinic denial: the manager of Clinic A is uniformly refused
   (`notice=denied`, no disclosure) on Clinic B's location/booking/readiness,
   while Clinic A stays usable;
4. the Organization Manager (org authority only, not designated) is refused
   Clinic-surface operations and gets no readiness disclosure;
5. B3/B4 tests (§3–§4).

Real-browser regression (validation requirement 7):
`bin/rwp-portal-delegated-journey.py` (NEW Playwright harness, wired into
`first-practice-acceptance.yml` as "Browser — delegated non-hospital Clinic
completion") drives, in Chromium, with the frontend portal only:
- Section A: `general_clinic` (multi_doctor_clinic) — Organization Manager
  starts the plan (type+structure+identity), 1 site, 2 held Clinics, delegates
  a DIFFERENT Subscriber per Clinic; each delegated manager completes their own
  Clinic (readiness panel + booking form must render — the B2 assertions),
  location, 2 clinicians, 2 schedules, booking policy → readiness ready
  (Clinic A at 390px RTL, overflow check); cross-Clinic forged booking submit
  is refused; Organization surface shows no Clinic forms
  (`data-cpms-clinic-steps-delegated`); Organization Manager activates
  atomically;
- Section B: `doctor_practice` (simple_practice) — same delegated completion
  with 1 clinician and atomic activation;
- final DB facts: every Clinic active with locations + schedules + booking.

## 3. B3 — MEDIUM: cross-form stale input collision — FIXED

Root cause (confirmed): `ManagementPortalController` stores `'action'` in the
user-scoped one-shot form-state transient, but the template restored
`$cpms_mp_stale[$name]` into **every** form rendering a field of that name
(`cpms_entity_name`, `address`, `phone`, …), so one failed submission
overwrote sibling forms on the same page.

Fix: `$cpms_mp_form_open()` now records the form being rendered
(`$cpms_mp_current_action`); `$cpms_mp_field` restores a stale value **only**
when `form_state['action']` equals the current form's action. Non-matching
forms render their own defaults untouched.

Regression test (in `DelegatedClinicCompletionTest`): two forms sharing
`cpms_entity_name`/`address` (`clinic_location_create` vs
`clinic_update_profile`) are rendered after a failed submission of each; the
failed form keeps its typed values and the sibling form is verified untouched
in both directions.

## 4. B4 — MEDIUM: wrong error classification — FIXED

`src/Application/Management/ManagementPortalService.php`:
- `activate_facility_setup()`: `FacilitySetupException` now maps through
  `facility_failure()` — `not_ready`/`confirm_required`/`ack_required` render
  as `notice=prerequisite` with the safe Persian blocker text (previously
  mislabeled `denied`);
- `save_clinic_booking_policy()`: same mapping — `invalid_booking` renders as
  `notice=invalid` (previously `denied`); unauthorized callers still get the
  uniform `denied`;
- `designate_facility_manager_by_identifier()`: empty identifier →
  `notice=invalid` (invalid input); unknown WordPress account **after**
  authorization succeeded → `notice=prerequisite` (missing prerequisite);
  authorization refusals remain the uniform non-disclosing `denied`
  (fail-closed; the user lookup still happens only after
  `authorize_manager_designation` succeeds, so this path is not a
  user-existence probe for unauthorized actors).

Regression test (`testNoticeCategoriesDistinguishInvalidPrerequisiteAndDenied`)
asserts all four categories on these exact paths.

## 5. B5 — LOW: template + report cleanup — FIXED

- `templates/management-portal-shell.php`: the duplicated sites `foreach` in
  `#cpms-mgmt-room-site` (duplicate `<option>` entries) is removed;
- this report no longer claims any Integration PASS that did not happen: the
  audited head `f7f360ed` failed Integration with the B1 fatal and the earlier
  "green on `e71874b`" statement was wrong — corrected here and in the PR body;
- no documentation-only CI-trigger commits: this report ships in the same
  commit as the code fixes; the exact-head CI table is maintained in the PR
  body (updated via `gh pr edit`, which does not trigger workflows).

## 6. Browser matrix (this remediation)

| Journey | Engine / DB | Status |
|---|---|---|
| Hospital two-Subscriber-manager onboarding (`rwp-portal-hospital-journey.py`, 50 checks) | Chromium × MySQL 8.0 + MariaDB 11.4.13 | see §7 (must stay green — the B2 gate change touches this card) |
| First-practice + 12-type admin matrix + recovery (`rwp-first-practice.py` 577 + `rwp-recovery.py` 287) | Chromium × MySQL 8.0 + MariaDB 11.4.13 | see §7 |
| **Delegated non-hospital completion (`rwp-portal-delegated-journey.py`, NEW)** | Chromium × MySQL 8.0 + MariaDB 11.4.13 | see §7 |
| Real WP acceptance (ZIP → clean WP) + Persian coexistence | Chromium | see §7 |
| Persian RTL 390px (responsive smoke + journey viewport checks) | Chromium | see §7 |

## 7. Exact-head CI status and Integration counts

The exact-head CI matrix (WPCS, PHPStan, Unit PHP 8.1–8.4, Integration with
exact test/assertion counts, Closure gates, Tenant/Authz tripwire, Composer
audit, Architecture Guard, REST contract lint, Migration gates MySQL 8.0 +
MariaDB 10.11, MariaDB 10.11/11.4 runtime parity, WP 6.6/6.7/7.1 matrix,
Release packaging, Stage A clean-host install, Third-party baseline, Real WP
acceptance, First-practice browser mysql+mariadb, Persian coexistence
mysql+mariadb) is recorded from the final head's check-runs **in the PR body of
#257** (updated with `gh pr edit` after the run completes, per B5: no
docs-only CI-trigger commits). Any red lane at the hard stop is listed in §8
instead of being papered over.

## 8. Remaining blockers

(To be finalized with the exact-head CI outcome; anything red or unverified at
the hard stop is listed here — nothing is claimed green without a check-run.)

## 9. Verdict

**READY FOR REVIEW (Draft)** once the exact-head CI matrix (§7) is green;
otherwise **BLOCKED** with the red lanes named in §8. The PR stays Draft and is
not merged.
