# Business-Access Decoupling Audit (2026-10-20)

Purpose: record, precisely and honestly, where CPMS business authority still depended
on WordPress business roles or WordPress-global capabilities, what this slice replaced,
and what remains. Evidence is by source inspection of the branch head plus the
Integration suite; anything not executed is marked as inference.

## 1. Method

```
grep -rn "has_cap(\|user_can(\|current_user_can(" clinic-practice-management/src --include=*.php
grep -rn "ROLE_DOCTOR|ROLE_SECRETARY|ROLE_MANAGER|ROLE_ACCOUNTANT" clinic-practice-management/src
grep -rn "admin_post" clinic-practice-management/src
```

Counts at the branch base (`main` = `6c64a91`):

| Area | `has_cap`/`user_can`/`current_user_can` | WP CPMS role references |
|---|---|---|
| `src/Admin/` | 73 | majority (legacy/wp-admin surface) |
| `src/Application/` | 22 | few (services) |
| `src/Rest/` | 11 | `permAnyRole` gates, staff routing signal |
| `src/Frontend/` | 1 (comment) + module gates | 3 (doctor/reception gates) |
| Total | 112 | 79 |

## 2. Findings and disposition

### 2.1 Attempted and reverted (regression evidence at the exact head)

An initial batch attempted exactly the replacements below. Exact-head Integration
(2190 tests) reported **16 failures + 3 errors** in pre-existing security-contract
suites, so the batch was reverted to the base contracts instead of weakening the
tests:

| ID | Location (reverted) | Attempted replacement | Regression observed |
|---|---|---|---|
| A-01 | `RestBase::requireCap()` | trusted-scope scoped capability fallback | `PatientAdminTest::testRestSearchRequiresPatientRead` (200 vs 403), `ClinicalFilesScopedAuthorizationTest` (`CLINIC_NOT_FOUND` → `CLINIC_PERMISSION_DENIED`) |
| A-02 | `ClinicalService::requireCap()/requireRole('doctor')` | scoped capability + durable clinician identity | same clinical suites + `Phase1BPrescriptionConcurrencyTest` |
| A-03 | `ReportService` / `ExportService` capability checks | scoped-first capability | `ReportsAuthzTest`, `ReportsExportClinicAuthorizationTest`, `ReportsClinicIsolationTest`, `ExportClinicIsolationTest`, `Phase2JobScopeRedFoundationTest` |
| A-04 | `StaffPortalShell` doctor/reception/reports eligibility | capability-only eligibility | `Phase14StaffPortalReportsAvgWaitingRedTest`, `Phase14StaffPortalReportsClinicScopeTest`, `RestTrustedClinicContextTest` |
| A-05 | `DoctorPortalShell::isDoctorUser()` | durable clinician identity | (no regression observed, but reverted with the batch to keep one coherent state) |

**Delivered instead:** the additive Management Portal (A-10), which introduces no
new authority source and leaves every existing gate byte-for-byte as published.

### 2.2 Retained deliberately (documented, not hidden)

| ID | Location | Why retained | Residual risk |
|---|---|---|---|
| B-01 | `src/Admin/*` page gates (`current_user_can('manage_options')`, `cpms_config`, `read`) | These are the WordPress-Administrator / legacy back-office surface. They are not used by any frontend portal, and every business write inside them re-authorizes through Organization/Clinic services. | An Administrator sees admin screens but still gains **no** scoped clinical/financial authority (`AuthorizationService::can()` denies; pinned by tests). |
| B-02 | `RestBase::requireNonce()` + global capability on **public/booking** routes (`permCap` for guest-facing paths) | Nonce + authenticated-session CSRF protection is transport, not business authority; the global capability there is an additional layer over service-level scoped checks. | Global caps can still *narrow* a legitimate scoped actor on those routes; they can never widen. |
| B-03 | `RestClinicContext::current_user_is_staff()` global caps/roles | Routing signal only (decides whether a trusted Clinic scope is bound for the request). It performs no authorization decision; the durable-membership probe was already added for Clinic-local custom roles. | A staff actor recognized **only** by WordPress role still needs durable membership before any scope binds. |
| B-04 | `OrganizationClinicProvisioningService::bootstrap_owner()` (`manage_options`) | First Organization owner bootstrap is an explicit Administrator bootstrap by design (documented in the bootstrap page). | Cannot be used as an ongoing business path; it creates one Organization Manager membership. |
| B-05 | `FirstPracticeSetupService` (`manage_options`) | Initial plugin setup/activation is an installation task. | Administrator-only by design; no clinical/financial data path. |
| B-06 | `BookingController` `has_cap()` staff-vs-patient branching | Chooses which response shape/route a caller gets; both branches still run nonce + service authorization. | Narrowing only. |
| B-07 | `NotificationService` `has_cap()` recipient filtering | Decides *extra* notification recipients, not data access. | No PHI read is granted by it. |
| B-08 | `FacilitySetupService` `user_can()` helper | wp-admin facility wizard gate (`cpms_config` / `manage_options` per step). | Wizard is wp-admin-only in this slice (see 2.3). |

### 2.3 Remaining gaps (not solved here — must not be claimed as done)

1. **Business-authority decoupling for clinical/financial/report routes is NOT
   delivered.** The attempted scoped-first gates were reverted after exact-head
   Integration reported 16 failures + 3 errors in pinned security contracts (see
   §2.1). Doctor / Reception / Accountant / Patient workflows still depend on the
   existing WordPress-global gates on the REST/service side, exactly as on `main`.
   Any claim that those roles can complete their workflows without privileged
   WordPress roles is **unsupported** by this PR.
2. **Staff user creation from the portal** is not implemented (roles are assignable
   only to existing ACTIVE memberships).
3. **Facility wizard reuse outside wp-admin** is architectural only (Phase 4
   foundation). None of the 12 facility types is provisioned from the portal.
4. **wp-admin surface** (B-01) still reads WordPress roles/capabilities. This is the
   accepted Administrator back-office; migrating it is a separate slice.
5. **`manage_options` bootstrap** (B-04/B-05) remains an explicit Administrator
   action; it is not a business workflow.

## 2A. Independent-review remediation (2026-10-20, second round)

The independent review of head `3204369` confirmed five P0 authorization defects in the
dedicated portal. All five are fixed in `ManagementPortalService` (and
`FacilitySetupService` for the entry check); each has a negative regression test.

| ID | Defect (confirmed) | Fix | Regression test |
|---|---|---|---|
| P0-1 | `set_staff_capability()` read the target membership **before** any management authorization, so an actor holding only some other capability (e.g. a secretary with `cpms_patient_read`) could mutate capability overrides | `clinic_manager_scope()` establishes the trusted Clinic and authorizes `cpms_config` FIRST; the membership row is read afterwards; the delegated capability must also be effective for the actor; DENY > GRANT > preset unchanged | `test_non_manager_capability_mutation_is_refused_before_lookup` |
| P0-2 | `assign_built_in_role()` / `enroll_staff()` allowed self-role changes and unlimited role delegation (a manager could hand out `cpms_doctor`, i.e. clinical authority it never held) | authorization precedes target lookup; the actor's **own** membership can never be changed; every capability of the target role preset must already be effective for the actor in that Clinic | `test_self_role_escalation_is_refused`, `test_forbidden_clinical_and_financial_role_delegation_is_refused`, `test_subscriber_clinic_manager_enrolls_staff_and_provisions_only_a_subscriber` |
| P0-3 | `update_location()` / `set_location_active()` performed the target Location lookup before authorization (existence/ownership probe surface) | scoped management capability is authorized before the Location row is read; foreign ids and unauthorized actors receive the same uniform failure | `test_location_mutation_requires_scoped_management_before_lookup` |
| P0-4 | Sensitive portal mutations wrote **no** audit rows (enrollment, role change, suspension, scope, capability overrides, clinician registration) | every mutation appends a hash-chained `AuditLogger` entry with before/after state, actor, resource and Clinic | `test_sensitive_portal_changes_write_hash_chained_audit_rows` |
| P0-5 | Portal had no regression coverage for any of the above | seven new DB-backed negative/positive tests plus controller-only facility journeys | see `ManagementPortalTest` §9 and the facility tests |

Functional review items addressed in the same round:

- **P1-6/P1-7** — free-text `site_key`/`clinic_key` inputs are replaced by
  server-populated selectors (`facility_selectors()`), and clinician assignment, weekly
  schedules and the booking policy are now portal controller actions, so hospital
  readiness and atomic activation are reachable **without** calling
  `FacilitySetupService` directly from a test.
- **P1-8** — room provisioning stays a Clinic operation: an Organization Manager
  without an explicit Clinic designation is refused, and the delegation is what makes
  it succeed. Organization authority is never converted into Clinic authority.
- **P1-9** — `FacilitySetupService::has_admin_entry()` (WordPress-global
  `user_can()`) is replaced by `has_cpms_entry()`: ACTIVE durable membership plus the
  scoped capability that opens that role's surface. It is per Clinic, never a
  WordPress role, and it grants nothing.
- **P1-10** — a clinician serving several Clinics of one Organization is visible in
  every one of them (home + participating profiles, deduplicated) and can be scheduled
  in the secondary Clinic; the durable home Clinic is re-read rather than assumed.

**Not delivered (see §2.3):** Subscriber operation of the Doctor/Reception portals and
the decoupling of the REST/service gates from WordPress-global capabilities (P1-11 to
P1-13 of the review). No "authorized in any Clinic" fallback was introduced anywhere.

## 3. Test evidence

- `tests/Integration/ManagementPortalTest.php`:
  - Subscriber + Organization membership: overview, create Clinic, profile update,
    lifecycle suspend/reactivate, Clinic-manager designation, Organization-manager
    grant — all through `ManagementPortalController::dispatch()`, asserting the actor
    keeps `subscriber` and gains no global CPMS capability.
  - Subscriber + Clinic membership: workspace, profile, Location create/deactivate,
    membership role assignment, refusal of `administrator` as a role_key.
  - Explicit membership DENY blocks the workspace and the write; suspended
    Organization membership removes the surface.
  - Forged/cross-Clinic Location, cross-Clinic staff target, cross-Organization
    Clinic update, unknown action — all fail closed with no state change.
  - Administrator alone: no Organization, no Clinic, no workspace, no scoped
    capability (`MEDICAL_READ`, `FINANCE_READ`, `PATIENT_READ`, `CONFIG` all false).
  - Surface source: no `/wp-admin`, no `admin-post.php`, no `current_user_can`,
    `user_can(` or `has_cap` in the portal controller/service.
- Existing Integration tests for `AuthorizationService`, Doctor/Staff portals,
  Finance and Custom Roles are untouched and remained the regression net that
  caught the attempted decoupling (§2.1).
- Known issue at head: `ManagementPortalTest::test_portal_surface_never_requires_wp_admin`
  failed in its first exact-head run because the template docblock contained the
  literal string "/wp-admin" (the assertion matched documentation, not markup). The
  wording was corrected; the assertion still fails closed on real links/actions.

### 3.2 Exact-head evidence for round 2

- `ManagementPortalTest` (22 DB-backed tests at this head) and the two facility
  journeys in `FacilitySetupJourneyTest` are executed by the exact-head Integration
  lane; the audit-row test also verifies the global hash-chain linkage of the recent
  window.
- WPCS runs on added lines only; PHPStan level 3 covers the modified services.
- Exact-head browser evidence at `0e4506cd` (run 39407879447, MariaDB 11.4.13 **and**
  MySQL 8.4.11): both First-Practice jobs are SUCCESS — the full installer → wizard →
  bootstrap → independent-Clinic/hospital facility journey runs in Chromium with no
  WordPress role change, including `building.cpms_entry_from_membership` and
  `building.cpms_entry_unaffected_by_wordpress_role`, and `facility.delegated_manager.other_clinic_denied`.
- Exact-head `Real WordPress Acceptance (ZIP → clean WP → browser)` at `0e4506cd`:
  SUCCESS. The CI workflow (lint, WPCS, PHPStan, Integration, unit) is SUCCESS at the
  same SHA.
- Known flake (not caused by this diff, tracked as NOT RUN evidence): in the
  Pilot/Staging Readiness Gate at `0e4506cd`, `Responsive smoke` step
  *Doctor Portal — real browser* failed at the mobile-390 viewport only on
  `assert_scope_unselected` ("operational data was requested before explicit Clinic and
  Location selection", `rows=[] today=0 queue=0 history=0`), while 768/1366 passed in
  the same run and the identical plugin code passed this job at `b303b212`. The diff
  between the two SHAs is the first-practice Python harness plus documentation, neither
  of which the pilot gate loads. At the next head `879753f` the same job passed in full
  (Responsive smoke SUCCESS), which confirms the flake.
- Second environmental flake at `879753f`: `coexistence-persian-woocommerce-diagnosis`
  failed after 43 s in step 6 *Tooling (apache + wp-cli + playwright/chromium)*, before
  any checkout-dependent step could touch this diff; the identical job was SUCCESS at
  `0e4506cd`. Net result at `879753f`: 25/26 checks green, the single red check being
  that pre-execution tooling failure.

## 4. WordPress-independent business access (round 3, in progress)

Owner policy: a WordPress **Subscriber** holding a durable CPMS membership and the
effective scoped permission of an explicitly selected Clinic/Location uses the same
operational portals without any privileged WordPress role. WordPress roles/capabilities
remain a *valid legacy path* but are no longer entry requirements; CPMS data is the
authority.

### 4.1 Delivered in this round (code + regression contract)

| Area | Change | Authority now |
| --- | --- | --- |
| Doctor Portal entry | `DoctorPortalController::perm_doctor`, `QueueController::permDoctorPortal` | nonce + authenticated actor + server-derived ACTIVE clinician identity + ≥1 ACTIVE membership; the eligible Clinic list is filtered by scoped capability per Clinic |
| Visit Workspace boundary | `DoctorPortalController::perm_workspace` | removed WP-global `requireCap`/WP-role gates; explicit trusted Clinic (establisher) → `AuthorizationService::can(cap)` → explicit Location → ownership |
| Doctor queue/today `guard()` | `QueueController::guard` | the WP-global capability is no longer a precondition; the scoped permission of the bound Clinic (`requireClinicPermission`) still decides and still fails closed without a scope |
| Clinical writes/reads | `ClinicalService::requireCap` / `requireRole` | scoped fallback: explicit `ScopeContext` + ACTIVE membership + `can(cap)` (DENY > GRANT > preset); business role may come from the durable membership `role_key` |
| Visit workflow | `VisitService::roleForUser` / `requireQueueReader` | logical role and queue authority may come from the ACTIVE membership role of the explicit scope; WP role stays first (legacy) and unknown keeps failing closed |
| Reception boundary | `ReceptionPortalController::perm_reception` | removed the WP-global capability loop and WP-secretary gate; `authorize_and_bind_reception_scope()` (explicit Clinic → scoped authorization → trusted scope + Location) is the authority |
| Module visibility | `StaffPortalShell::doctor_module_eligible`, `reception_module_eligible`, `DoctorPortalShell::isDoctorUser` | CPMS only (identity + ACTIVE membership + all module capabilities in the SAME Clinic); visibility is never authority |

Regression contract: `tests/Integration/SubscriberOperationalAccessTest.php`
(Subscriber doctor positive path incl. visit record, cross-Clinic refusal, explicit DENY
override, suspended membership, inactive Location, ambiguous/missing scope, WordPress
administrator without CPMS authority, membership-without-identity and
identity-without-membership, Subscriber secretary Reception context + foreign-Clinic
probe).

### 4.2 Not delivered in this round (explicit)

- Subscriber **Accountant/Finance** and **Reports/Export**: module visibility and the
  REST boundaries still require the WordPress-global `cpms_finance_read` /
  `cpms_report_read` capability, so the module is not exposed to a Subscriber either
  (deliberately kept consistent — no visible module that would 403).
- `MedicalFileService`, `HandwritingService`, `FinanceService`, `ReportService`,
  `ExportService` still carry WordPress-global `has_cap` layers at their own call sites.
- `RestClinicContext` generic staff routing signal and `NotificationsController`
  role lists are unchanged.
- MariaDB 11.8 migration-0027 gate: **NOT RUN** (no MariaDB 11.8 instance available in
  this environment; no compatibility change was inferred or applied).
- Real-browser Subscriber journeys: **NOT RUN** in this round.

### 4.3 MariaDB 11.8 — migration-0027 version gate (investigation, no code change)

`2026_10_13_0027_custom_role_archival.php` identifies the engine with a strict
allowlist before any DDL: MySQL `8.x`, MariaDB `10.11.x` and MariaDB `11.4.13`
(the runtimes the integration lanes actually execute). A MariaDB **11.8** server is
therefore rejected with `migration 0027: unsupported database server ...` and **no
DDL runs** — the migration fails closed, it does not silently guess.

Decision: **no compatibility change applied.** Widening the pattern to `11.x` (or to
11.8 specifically) would be an inference about a runtime nobody has replayed, and the
owner's rule is explicit that a compatibility correction must be evidence-supported.
Evidence available in this round: a mocked-metadata regression
(`CustomRoleArchivalMigrationTest::test_mariadb_118_is_outside_the_verified_gate_and_fails_closed_before_ddl`)
proving the refusal and the absence of DDL.

**NOT RUN:** a real MariaDB 11.8 migrate → rollback → replay on a live server. It needs
a MariaDB 11.8 instance, which this environment does not provide. Required evidence to
change the gate: full migrate to schema `2026_10_18_0029`, `rollbackOne()` from 0027,
repeat apply, `SHOW CREATE TABLE cpms_custom_role_defs` equality, and the CHECK /
`@@SESSION.check_constraint_checks` probes the migration already performs.

### 4.4 Round-2 exact-head evidence (`be27e1e` and successor)

- Integration (WP 7.1 + MySQL 8): **PASS** — `OK (2220 tests, 64341 assertions)`,
  0 failures at `be27e1e`; WPCS (changed lines): **PASS**; Unit (8.1–8.4): **PASS**.
- New negative/positive contract `SubscriberOperationalAccessTest` (11 cases): Subscriber
  doctor Today / live queue / visit record / context on scoped authority alone;
  cross-Clinic refusal; explicit DENY over preset; suspended membership; inactive
  Location without continuity; missing/ambiguous scope; explicit DENY on the finance
  board; WordPress Administrator without CPMS authority.
- Responsive smoke exposed one real ordering regression from this round: a Reception
  request with **no** Clinic selector returned `400 CLINIC_SCOPE_REQUIRED` to an actor
  holding no Reception authority, where the established contract is `403
  CLINIC_PERMISSION_DENIED` with no patient data. Fixed in
  `ReceptionPortalController::perm_reception`: when no explicit Clinic is supplied the
  durable Reception authority decides the envelope first (deny 403), and only an actor
  who holds Reception authority somewhere is asked for the explicit Clinic (400).
  No patient data was returned in the failing probe.
- Coexistence jobs (3): **blocked upstream, not by this change**. Stage A fails at its
  `official_stable_source_validated` gate because the pinned versions no longer equal the
  current upstream stable (`persian-woocommerce-sms` 7.3.1 → 7.3.2,
  `persian-woocommerce` 10.0.5 → 10.0.6). The harness fails closed by design; Stage B is
  NOT RUN. Updating the pins is a separate, reviewable change and was not made here.

### 4.5 Round-3 delivery — Subscriber security closure (exact-head evidence)

**Reviewed head:** `bd6f346` → **delivered head `e9271f6`** (this section).

#### Authorization model refinement (`AuthorizationService::scoped_decision`)

A single authoritative helper now answers, per (actor, Clinic, capability):

| result | meaning | consequence |
|---|---|---|
| `true` | the ACTIVE membership effectively grants the capability | allowed (Subscriber and staff identical) |
| `false` | the ACTIVE membership carries an **explicit DENY** | **final** — no WordPress-global `has_cap()` may override it |
| `null` | no ACTIVE membership, or a membership that neither grants nor denies | the caller's legacy WordPress-capability path may answer (unchanged compatibility) |

This replaced the previous "scoped check, else global" pattern at these boundaries:
`ReportService` (route gate + report-specific capabilities), `ExportService`
(export + report-type capabilities), `ReportsController`, `FinanceController`,
`FinanceService::listServices`, `HandwritingService`, `MedicalFileService`,
`ClinicalService` (`requireCap`, private-note/private-file readback),
`VisitService::requireQueueReader`, `PatientService` (license class), and the
shared `RestBase::requireCap` used by the remaining staff controllers.

#### Accountant surface (owner delivery 2026-10-20)

The Finance board's read boundary is **`cpms_finance_read` + `cpms_invoice_read`
in ONE Clinic**. `cpms_queue_read` is no longer part of it, so the built-in
`cpms_accountant` preset opens the board without any WordPress role and without
queue authority. `cpms_queue_checkout` still guards checkout, so the accountant
still cannot mutate settlement. Five pre-existing test expectations that pinned
"accountant is outside the finance board" were updated in the same commit with
the reason recorded inline — this is a deliberate contract change, not a
weakened assertion.

#### Doctor / reception surfaces

- `MedicalFileService`: staff classification (doctor/secretary/patient) comes from
  the durable membership of the file's Clinic plus the clinician identity, never
  from a WordPress role; the global pre-check capability gates were removed and
  `authorizeScoped()` on the durable Clinic remains the decider before any disk
  read. `doctor_private` still needs clinician identity **and** scoped
  `cpms_private_note_read` **and** scoped `cpms_file_read`.
- `ClinicalService`: private notes/files readback uses durable Visit ownership +
  scoped capability (D-8 masking unchanged).
- `ReceptionPortalController`: unscoped requests are decided by durable Reception
  authority first — an actor whose required Reception capabilities are denied
  gets `403 CLINIC_PERMISSION_DENIED` and never a `400` selector oracle; the
  clinic selector and the operational requests share one eligibility rule
  (ACTIVE secretary membership, ACTIVE Organization, non-suspended Clinic, all
  three queue capabilities); `StaffPortalShell::reception_module_eligible` now
  mirrors it exactly.
- `PatientService`: the license "reception" operation class is derived from the
  durable secretary membership + scoped `cpms_patient_create`, not the WP role.

#### Regressions added (`SubscriberOperationalAccessTest`, DB-backed)

Full Subscriber doctor workspace (record, handwriting, clinical search, scoped
DENY closure), true Subscriber accountant (finance board + report catalog, no
`QUEUE_READ`), scoped DENY over a legacy WordPress role capability, reception
DENY on unscoped context/board plus the authorized actor's `400` selector
contract, suspended Organization and suspended Clinic removal from the selector,
cross-Clinic isolation, and authorization-before-PHI (foreign existing vs
nonexistent Visit produce one indistinguishable envelope with no patient
identity in the body).

#### Exact-head CI at `e9271f6` — **23 pass / 3 fail / 0 pending (26 checks)**

Green: Integration (`2226` tests), WPCS (changed lines), Unit 8.1–8.4, Static
Analysis, Tenant Tripwire, Real WP Acceptance ×3, Upgrade path, Release
Artifact, Closure ×4, First-practice browser (mysql + mariadb), Responsive smoke
(Chromium ×4), Staging Gate (fresh install → restore drill), coexistence ×3
(auth/combined-ten) — i.e. **all product/security/browser lanes**.

Red: `coexistence-persian-woocommerce-sms`, `coexistence-persian-woocommerce-diagnosis`,
`coexistence-combined-nineteen` — Stage A `official_stable_source_validated`
fails closed because upstream stable advanced past the frozen pins
(`persian-woocommerce-sms` 7.3.1 → 7.3.2, `persian-woocommerce` 10.0.5 → 10.0.6).
Stage B is NOT RUN. **No pin was changed**: the harness verifies exact-version
identity *and* ZIP header identity against `downloads.wordpress.org`, which this
execution environment cannot reach, so artifact integrity of a new pin could not
be verified. Re-pinning stays a separate, reviewable change.

#### NOT RUN (honest)

- Real Chromium journeys with **Subscriber** Manager/Doctor/Receptionist/Accountant
  accounts. The existing browser lanes authenticate WordPress-role staff; the
  Subscriber contract is proven by DB-backed integration tests only. Exact repro
  for the installation agent: create a `subscriber` WP user, seed
  `cpms_clinic_memberships` (role `cpms_doctor` / `cpms_secretary` /
  `cpms_accountant`) in one Clinic with an ACTIVE Location, then run
  `bin/pilot-doctor-portal.py`, `bin/pilot-reception.py`, `bin/pilot-finance-board.py`
  against that session.
- MariaDB **11.8** migration-0027 replay (unchanged: gate stays fail-closed,
  evidence not obtainable here) — see §4.3.
- PRIORITY-2 frontend-management completeness (clinic settings, booking policy,
  SMS settings, clinician/schedule management, executable `simple_practice` /
  `multi_doctor_clinic` onboarding, 12-facility differentiation, separate
  Organization/Clinic manager identities) remains **open**.

## 5. Round 4 — scoped authority hardening and the facility wizard (2026-10-20)

### 5.1 Authorization contract change (`AuthorizationService::scoped_decision`)

The tri-state contract is now fail-closed **per Clinic**:

| Durable membership row in this Clinic | Result |
| --- | --- |
| none | `null` — legacy WordPress-capability path (unchanged) |
| exists, not ACTIVE (suspended/revoked) | `false` — final DENY, **never** rescued by a global WP cap |
| ACTIVE | `$this->can(...)` — explicit DENY > explicit GRANT > role preset; a missing capability is a final `false` |

Consequences (each covered by a DB-backed negative test in
`tests/Integration/ScopedAuthorityHardeningTest.php`):

- a WordPress-global capability can no longer substitute for a missing scoped
  capability, so nothing bleeds across Clinics;
- a suspended membership makes the actor a nobody in that Clinic — it does not
  fall back to global WordPress caps;
- an explicit scoped DENY still outranks an explicit GRANT and any role preset.

### 5.2 Scoped role precedence (identity is durable, not a WP role)

- `VisitService::roleForUser()`: with a trusted scope, the durable membership
  decides (`doctor` / `secretary`). A WordPress "doctor" with **no** membership in
  that Clinic stays a *doctor identity* (queue scope = that clinician's own empty
  set) and is never widened to the clinic-wide staff view; other unknown actors
  resolve to `null`. WordPress roles answer only when no scope exists.
- `ClinicalService::search()`: the private-note/clinical widening flag comes from
  the membership role or the active clinician profile, not from the WP role.
- `MedicalFileService`: a `doctor_private` upload additionally requires an ACTIVE
  `cpms_clinicians` profile — an explicit `PRIVATE_NOTE_CREATE` grant held by a
  non-doctor is refused.

### 5.3 Legacy fixtures corrected (never the engine)

Three suites were written against the old `null`-means-fallback contract and
relied on **WordPress-global** capabilities (`cpms_patient_read`,
`cpms_medical_read`, `cpms_report_read`) to reach Clinic-scoped report/export
surfaces. They now GRANT the same capabilities on the **membership**
(`MembershipService::set_capability`), which is the only wire that satisfies the
hardened contract:

- `ReportsClinicIsolationTest`, `ExportClinicIsolationTest`,
  `ReportsExportClinicAuthorizationTest`, `Phase2JobScopeRedFoundationTest`.

One pre-existing expectation was **strengthened, not weakened**:
`Phase4Slice4QueueSharedProfessionalTest::testDoctorWithSuspendedParticipationInBNeverSeesClinicQueue`
now asserts that a suspended participation is an explicit scoped DENY
(`CLINIC_PERMISSION_DENIED`, 403) at the service layer instead of a bounded empty
projection. The security property ("Clinic B never leaks") is unchanged and is
now enforced earlier; the denial message is asserted to carry no Visit detail.

The suspended-Clinic **Reception continuity** contract was kept as-is: a
suspended Clinic keeps the bounded EMPTY board read (HTTP 200, no lifecycle
reason leaked). A Round-4 draft that returned `403 CLINIC_SCOPE_UNAVAILABLE`
there was reverted because it contradicted the established Phase 11 contract and
the passing `Phase11ReceptionAppointmentCreateRedTest` evidence.

### 5.4 Facility wizard — address step

The Organization wizard's site/address step now renders the **offline** Iranian
province/city catalog selects (`AddressSelectorField` +
`IranProvinceCityCatalog`): no network call, no preselected province, free-text
detail preserved, a city without its province or a city outside the chosen
province is a validation error and persists nothing, and re-submitting the same
building stays idempotent. Covered by
`FacilitySetupJourneyTest::testSiteAddressUsesTheOfflineProvinceCityCatalogAndRejectsAnOrphanCity`.

### 5.5 Evidence status at this head

- Integration suite (WP 7.1 + MySQL 8): 2124 tests, 0 failures / 0 errors at
  `f88e5ba` (the address-step commit `fcf8abf` adds one test).
- WPCS (changed code), PHPStan, Unit (8.1–8.4), Closure lanes, Real WP
  Acceptance (`wp_`, `clinic_`): green.
- Coexistence lanes (`persian-woocommerce`, `-sms`, `combined-nineteen`): FAIL —
  upstream pin drift, unchanged from Round 3 and not fixable in this environment
  (artifact identity cannot be verified without `downloads.wordpress.org`).
- MariaDB **11.8**: still **NOT RUN** (see §4.3).
