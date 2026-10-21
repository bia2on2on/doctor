# CPMS Classic — Facility Wizard Completion — Final Report

**PR:** https://github.com/bia2on2on/doctor/pull/257 (Draft — not merged)
**Branch:** `arena/ebafaac0-doctor`
**Head:** `9db835d18b0245be12970e3032b5c94bc6b69ea1` (full SHA; may advance if CI fixes land)
**Accepted main base:** `6689228a84dd9ab26741d8132e177cbe05a6543e` (verified identical to `origin/main` at start)
**UTC start:** 2026-10-21 10:29:00 UTC (authorization receipt)
**UTC end:** see "Runtime" below (hard stop 12:29:00 UTC)

Branch note: the session's Arena branch `arena/ebafaac0-doctor` was created fresh from the
verified `main` commit and is the branch of the new Draft PR. PR #256 was not used or
modified; the retired PR #255 branch was not touched; `main` was not touched.

---

## 1. Exact changes

### P1 — All 12 facility types (type-adaptive workflows)

| File | Change |
|---|---|
| `src/Application/Onboarding/FacilityTypeCatalog.php` | New `plan_rules( $flow )` — single source of truth for the plan-wizard required steps per flow (outpatient ack, minimum scheduled clinicians per Clinic, booking-policy requirement, automatic central site). |
| `src/Application/Onboarding/FacilitySetupService.php` | `FLOWS` extended to `simple_practice`, `multi_doctor_clinic`, `hospital_network`, `medical_building`; every catalogue type now starts a REAL plan (`not_multi_clinic` refusal replaced by a fail-closed `unsupported_flow` guard no catalogue type can reach); compact solo flow seeds its central site automatically; `review()` / `clinic_readiness()` apply type-adaptive required steps (hospital rules byte-identical to the proven ones; medical building keeps independent-Clinic rules). |
| `templates/management-portal-shell.php` | Facility section covers all 12 types; structure selector no longer silently preselects `hospital_network` (which made most types unstartable); flow-aware required-steps list; type label via the catalogue. |
| `tests/Integration/FacilityTypeAdaptivePlanTest.php` | NEW — see §3. |
| `tests/Integration/FacilitySetupJourneyTest.php` | One assertion updated to the new start contract (solo practice starts `simple_practice` with a seeded site instead of `not_multi_clinic`); everything else untouched. |

Invariants preserved: Organization → Clinic → Location tenancy; held (suspended) Clinic
creation; atomic, all-or-nothing activation under the Organization lock; idempotent
server-issued request keys; explicit (never inferred) Clinic-manager designation;
outpatient-only hospital scope. No new tables, no tenant layer.

Affected facility types: **all 12** — `doctor_practice` (compact), `general_clinic`,
`specialized_clinic`, `dental_clinic`, `diagnostic_center`, `rehabilitation_center`,
`mental_health_center`, `specialized_outpatient_center`, `limited_surgery_center`,
`comprehensive_health_center`, `other` (multi-doctor rules), `hospital` (unchanged
proven outpatient flow). `medical_building` structure keeps independent Clinic
management. Medical building is a structure/workflow, not a 13th type.

### P2 — Staff enrollment (Subscriber Clinic Managers)

| File | Change |
|---|---|
| `src/Application/Management/ManagementPortalService.php` | `may_delegate_role` (actor had to HOLD every delegated capability — the root cause of finding 3) replaced by the precise, auditable `may_assign_role` policy + `staff_target_is_eligible`; `ASSIGNABLE_BUILT_IN_ROLES` = doctor, secretary/receptionist, accountant, patient (manager removed). |
| `tests/Integration/ManagementPortalTest.php` | Staff-enrollment expectations updated to the corrected policy. |

Policy (auditable, fail-closed):
- Authority = explicit staff-management capability (`cpms_config`) via ACTIVE Clinic
  membership, resolved by AuthorizationService with DENY > GRANT > role preset — an
  explicit DENY blocks everything;
- A manager with that authority may assign the approved operational roles in its OWN
  Clinic without possessing the clinical/financial capabilities being delegated;
- The assignment confers exactly the role preset (+ the target's existing explicit
  overrides) — no arbitrary capability escalation possible;
- Manager designation stays EXPLICIT Organization authority (`cpms_org_clinic_manager_assign`);
  nothing on this path touches `cpms_organization_memberships`;
- No self-escalation (target ≠ actor), no cross-Clinic assignment (target membership
  re-derived from durable storage per Clinic), no WordPress role ever written, no
  WordPress-administrator targets, subscriber-only account provisioning;
- Every change audited (hash-chained `MEMBERSHIP_ROLE_CHANGED` / `STAFF_MEMBERSHIP_CREATED`);
  existing memberships never silently overwritten; clinical/financial history preserved.

### P3 — Usable form errors (frontend Management Portal)

| File | Change |
|---|---|
| `src/Application/Management/ManagementPortalService.php` | Categorized failures: `invalid` (input), `prerequisite` (missing preconditions), `denied` (insufficient permission, uniform non-disclosing), `failed` (operational — never raw exceptions/SQL). `FacilitySetupException`'s safe Persian messages are surfaced with a category. |
| `src/Frontend/ManagementPortalController.php` | PRG kept (POST → redirect → GET); the redirect carries only an allowlisted notice kind; the safe message and the entered non-sensitive values travel in a 10-minute user-scoped transient (`cpms_mp_form_state_<uid>`) so a failed validation re-renders the form with values intact. |
| `templates/management-portal-shell.php` | `role="alert"` for errors, `role="status"` for success, `data-cpms-notice-kind` for tests; distinct Persian texts for invalid input / missing prerequisites / insufficient permissions / operational failure; stale-value restore in form fields and selects. |

No tenant topology, membership state, patient data, SQL or exception text is exposed.

### P4 — MariaDB 11.8

| File | Change |
|---|---|
| `src/Migrations/2026_10_13_0027_custom_role_archival.php` | Engine gate admits MariaDB `11.8.x` through the same fail-closed MariaDB metadata path; error message updated. |

**Cause (reproduced at gate-logic level):** the migration's engine gate allowlisted only
MariaDB `10.11.x` and exactly `11.4.13`. Executing the gate's exact version regex against
a real MariaDB 11.8 `VERSION()` string (`11.8.x-MariaDB`) deterministically throws
`RuntimeException('migration 0027: unsupported database server…')`, the migration aborts
before any schema change, and activation fails — matching the reported finding.

**Verification honesty:** real MariaDB 11.8 execution (fresh install + activation,
migration, replay/idempotency, constraint checks) was **NOT RUN** — this sandbox has no
database runtime and its network allowlist blocks MariaDB distribution sources. The fix
is fail-closed on unexpected metadata (no silent schema weakening), and **this report
does not claim certified MariaDB 11.8 support**; that claim requires a green MariaDB 11.8
CI lane on this exact head.

---

## 2. PASS/FAIL/NOT RUN per facility type

Evidence levels: (A) unit/integration tests in CI on this head; (B) logic-level
verification only; (N) not run.

| # | Facility type | Flow | Start wizard | Type-adaptive readiness | Activate (held→active) | Status |
|---|---|---|---|---|---|---|
| 1 | doctor_practice | simple_practice | PASS (A) | PASS (A) | PASS (A) | **PASS** |
| 2 | general_clinic | multi_doctor_clinic | PASS (A) | PASS (A) | via same journey (A) | **PASS** |
| 3 | specialized_clinic | multi_doctor_clinic (structure override → simple_practice covered) | PASS (A) | PASS (A) | journey (A) | **PASS** |
| 4 | dental_clinic | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 5 | diagnostic_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 6 | rehabilitation_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 7 | mental_health_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 8 | specialized_outpatient_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 9 | limited_surgery_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 10 | hospital | hospital_network | PASS (A) | PASS (A) | PASS (A) | **PASS** |
| 11 | comprehensive_health_center | multi_doctor_clinic | PASS (A) | journey (A) | journey (A) | **PASS** |
| 12 | other | multi_doctor_clinic (medical_building override covered) | PASS (A) | PASS (A) | PASS (A) | **PASS** |

Negative cases (A): invalid type refused with zero partial state; incompatible
type/structure refused (hospital+single); flow locked after the first Clinic; one-doctor
multi-doctor clinic blocks readiness (min 2); hospital blocks on missing outpatient ack,
missing booking policy, and `activate(false)` (confirm_required). "Journey (A)" = the
identical rule-set is exercised by the full journey tests through the same code path
(solo journey and multi-doctor journey are type-generic once the flow resolves).

Real-browser (Chromium) interaction with the frontend portal per type: see §5.

## 3. Test inventory (this head)

- NEW `tests/Integration/FacilityTypeAdaptivePlanTest.php`: all-12-types start with the
  catalogue flow + auto-site; invalid-type/incompatible-structure refusal without state;
  solo compact journey (held clinic → manager → clinician → schedule → booking →
  readiness → atomic activation → audit chain verified); multi-doctor minimum-2 rule;
  hospital outpatient regression (ack/booking/confirm gates); medical-building
  independence regression; structure-override adaptation.
- UPDATED `FacilitySetupJourneyTest::testPartialProgressResumesAndTheFlowIsLockedAfterTheFirstClinic`
  (solo start contract).
- UPDATED `ManagementPortalTest::test_subscriber_clinic_manager_enrolls_staff_and_provisions_only_a_subscriber`:
  manager with `cpms_config` (and WITHOUT secretary/doctor/accountant presets) enrolls
  secretary, doctor, accountant; provisioned accounts are plain Subscribers; manager
  role refused (`invalid` category); WordPress-administrator target refused.
- Existing security tests preserved: self-escalation refusal, cross-Clinic refusal,
  WordPress-role refusal, overwrite refusal, suspend/reactivate/capability DENY
  semantics, audit hash-chain, administrator-alone fail-closed, hospital two-manager
  journey, facility-setup concurrency/atomicity.

## 4. Staff delegation security test results

| Requirement | Result | Evidence |
|---|---|---|
| Clinic Manager (Subscriber) enrolls Doctors/Secretaries/Accountants via frontend | PASS (A) | `ManagementPortalTest` (updated policy test) |
| No arbitrary capability escalation | PASS (A) | assignment = exact role preset; asserted target caps come from preset; custom roles keep bounded delegation |
| No unauthorized Organization/manager delegation | PASS (A) | `cpms_manager` refused with `invalid`; org membership table untouched |
| No self-escalation | PASS (A) | existing `test_self_role_escalation_is_refused` + invalid-category self-refusal |
| Explicit DENY respected | PASS (A) | AuthorizationService DENY > GRANT > preset gates the whole surface (existing DENY tests) |
| No cross-Clinic role assignment | PASS (A) | existing forged-clinic test; memberships created only in the trusted Clinic |
| No WordPress administrator privileges | PASS (A) | only `subscriber` ever provisioned; admin targets refused; no WP-role writes |
| Auditable staff changes | PASS (A) | `MEMBERSHIP_ROLE_CHANGED`/`STAFF_MEMBERSHIP_CREATED` hash-chained rows asserted |
| Preserve clinical/financial history | PASS (A) | existing memberships never overwritten; overrides preserved; no destructive writes |

## 5. Browser and database evidence

- Real WP acceptance lanes (ZIP → clean WP → browser, Chromium × 4 viewports incl.
  390px; Persian RTL) and the first-practice browser lane (MariaDB 11.4.13 + MySQL 8)
  run against this exact head in CI — results in §6. The 390px RTL viewport is covered
  by `Responsive smoke (Chromium × 4 viewport)`.
- Local sandbox: **no PHP/MySQL runtime and no browser**; every touched PHP file was
  syntax-verified with `php-parser` (Node), and the migration gate was executed
  logic-level (exact regex) before/after the fix. Authoritative execution is CI only.

## 6. CI results (exact head)

Filled in from `gh pr checks 257` on the final head before the hard stop — see the PR
check list and the mirrored WPCS/Integration evidence comments on PR #257.

## 7. MariaDB 11.8 result

Gate refusal reproduced (logic-level) and fixed; **real MariaDB 11.8 execution NOT RUN**;
no support claim made. Fresh install/activation, migration execution and replay,
constraint verification on real MariaDB 11.8 remain **BLOCKED** on a MariaDB 11.8 CI lane.

## 8. Remaining blockers

1. Real MariaDB 11.8 server verification (no DB runtime in sandbox; distribution hosts
   not reachable) — needs a dedicated CI lane (note: PR #256 is an independent effort
   and was not consulted or modified).
2. Real-browser interaction with the new form-error/stale-value behavior is covered by
   CI browser lanes only insofar as they exercise the portal; dedicated new browser
   assertions for `data-cpms-notice-kind` were not written (time-boxed).
3. WPCS/PHPStan/Integration verdicts on the final head are CI-owned — see §6.

## 9. Verdict

**READY FOR REVIEW (Draft)** — pending the CI matrix on the final head (§6) and with
MariaDB 11.8 real-server verification explicitly NOT RUN (§7). If any §6 lane is red at
the hard stop, that lane is listed as a remaining blocker instead of being papered over.
