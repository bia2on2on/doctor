# Post-Onboarding Clinic & Location Management — Classic Slice

**Status:** Draft slice, building on merged PR #244 (Organization Manager clinic provisioning).
**Slice scope:** Authorized post-setup management of Clinics and Locations without reinstall or wizard restart.

## 1. Functional operations delivered

### 1.1 Clinic settings update (Organization-scoped)
- **Authority:** Organization Manager authority only — a durable active `organization_manager` membership for the exact Organization, resolved server-side **per operation** with `DENY > GRANT > Organization-Manager preset`: `cpms_org_clinic_create` (provisioning), `cpms_org_clinic_update` (Clinic settings), `cpms_org_clinic_lifecycle` (suspend/reactivate); management reads are the union of the three. Each capability is independent (a DENY on one neither widens nor narrows the others), authorization is evaluated **before** tenant-supplied input is validated, and neither WordPress Administrator nor Clinic membership implies any of them. The management screen's Organization selector is the **union** of the three capabilities (`management_organizations()`), and profile/lifecycle controls are rendered only when the matching per-capability probe (`can()`) is true, so an actor holding only `cpms_org_clinic_update`/`_lifecycle` reaches the page and sees exactly the controls they may use. Hiding a control is UX only — every write re-authorizes itself server-side. Every request re-checks Organization row lock (`SELECT ... FOR UPDATE`), Organization `active` status, and Clinic→Organization ownership. No first-Clinic fallback, no `clinic_id=1` / `organization_id=1` hardcode, no WP Administrator bypass, no Clinic-membership shortcut.
- **Allowed fields:** `name` (1–190, Persian RTL), `address` (≤255 nullable), `phone` (≤32 nullable). `organization_id`, `slug`, `timezone`, `created_at`, `status` are preserved. Validation is atomic — any validation failure = zero partial mutation.
- **Concurrency & idempotency:** Organization + Clinic row locks serialize concurrent edits. Idempotent retry with identical payload returns `noop: true` without a second audit event. Audit failure rolls back the profile mutation in the same transaction.
- **Audit:** `CLINIC_PROFILE_UPDATED` hash-chained event with actor, org, before/after, clinic as authority clinic. No secrets stored.
- **Admin UX:** Menu *سازمان‌ها → مدیریت درمانگاه‌ها* lists organizations explicitly (no implicit first selection). After selecting an Organization, clinics are listed with explicit Clinic selector. RTL Classic form with nonce, server re-check, Persian success/error notices.

### 1.2 Clinic lifecycle — deactivate / reactivate
- **Authority:** `cpms_org_clinic_lifecycle` (Organization-scoped, `DENY > GRANT > Organization-Manager preset`), independent of `cpms_org_clinic_create` / `cpms_org_clinic_update`.
- **Preservation:** Status change touches only `status`, `status_updated_at`, `status_updated_by_wp_user_id`, `updated_at`. No medical, financial, audit, backup rows are deleted or rewritten. History remains queryable and backed up.
- **Lifecycle invariants:**
  - `status` ENUM `active`/`suspended` NOT NULL DEFAULT `active` (migration 0029, idempotent).
  - `suspended` Clinic is still visible in Organization listing with a badge. `TrustedClinicEstablisher` is deliberately **not** a lifecycle gate: Clinic scope stays available so existing care and continuity operations continue (an earlier blanket `clinic_suspended` scope block was reverted). Suspension is enforced operation-by-operation on new demand — see *Clinic suspension — enforced boundaries* below.
  - Audit/backup/history queries are not blocked by suspension (they enumerate all clinics).
  - Primary Location protection and Location lifecycle remain per-Location; no cascade to Locations on Clinic suspend.
- **Transaction safety:** Organization row lock + Clinic `FOR UPDATE` lock, conditional update with expected organization match, hash-chained audit `CLINIC_DEACTIVATED` / `CLINIC_REACTIVATED`, verification readback, idempotent noop on already-desired status without duplicate audit.
- **Admin UX:** Per-clinic *غیرفعال‌سازی / فعال‌سازی مجدد* button with explicit confirmation message stating history is preserved.

### 1.3 Additional Location management — explicitly authorized Clinic
- **Authority:** Clinic-scoped `cpms_config` capability via existing `LocationAdminPage` + `LocationService`. Every write re-establishes trusted Clinic from persistence (`TrustedClinicEstablisher`) and re-checks Clinic-scoped authorization; `clinic_id` from request is selector only.
- **UX:** *شعبه‌ها و محل‌ها* shows an explicit Clinic selector (no first-Clinic auto-selection, no `clinic_id=1`). The selected Clinic's Location list supports profile edit and deactivate/reactivate for non-primary Locations; the primary Location stays active and outside this edit form. Doctor may add a second practice Location after setup; treatment center may add another Clinic via the Organization flow above. The page distinguishes the two: **“افزودن درمانگاه” = Clinic inside Organization (Org-managed, with primary Location atomically)** vs **“افزودن شعبه” = Location inside a Clinic (Clinic-scoped, non-primary, timezone-aware)**.
- **Safety delivered in this slice:** CREATE always `is_primary=0, is_active=1`; UPDATE is Clinic-scoped and may change only `name`, `timezone`, `address`, and `phone`, with omitted optional fields preserved and slug/ownership untouched. Missing or invalid Clinic selection is denied rather than inferred from a sole membership. Not-found/foreign Location enumeration remains indistinguishable; timezone validation is IANA-only with no fallback. Create, profile update, deactivate, and reactivate are transactional with hash-chained audit (`LOCATION_CREATED/UPDATED/DEACTIVATED/REACTIVATED`); audit failure rolls the Location mutation back.

### 1.3.1 Location deactivation — Doctor Portal continuity (PR #248 follow-up)
- New scheduling, slot generation, availability and booking remain active-Location-only. Deactivating a Location preserves its schedule, materialized slots, appointments, visits, clinical records, finance and audit rows; slot generation skips inactive Locations and rechecks lifecycle under the established transaction lock order.
- The Doctor Portal keeps explicit Clinic and Location selection. An inactive Location is offered only when the current clinician has an active Visit there or a pending/confirmed appointment there, within the actor's active membership scope. The Today projection remains clinician/Clinic/Location/date scoped and uses the persisted Location timezone.
- Visit Workspace operations at an inactive Location require the exact selected Visit to remain active for that clinician. The bounded prescription-history projection is read-only and requires active treatment at that Location; an appointment alone cannot reopen unrelated historical workspace access. Generic staff scope and scheduling/booking paths do not inherit this exception.
- This continuity policy is limited to Doctor Portal reads and work on existing active Visits. Reception-side check-in of a pre-existing appointment at an inactive Location and a live two-connection deactivation-versus-booking run remain separate verification/follow-up items; neither is claimed as proven by this document. Runtime CI is required before treating this PR follow-up as accepted.

### 1.4 Role / membership management
- Reuses existing layers:
  - **Organization managers:** `سازمان‌ها → مدیران سازمان` (Org-scoped, last-active-manager protection, row lock, audit).
  - **Clinic staff:** `کاربران و دسترسی‌ها` (`StaffManagementPage`) and `نقش‌های سفارشی Clinic-local` (`CustomRoleDefinitionsPage`) — Clinic-scoped, `AuthorizationService` DENY>GRANT>preset. Staff and custom-role assignment/definition operations require the Clinic selector explicitly; a sole membership never supplies an implicit default. Assignment remains a membership-only reassignment for suspended members.
- No WP Administrator clinical authority: Administrator status is not an `AuthorizationService` bypass; explicit DENY in either layer wins.
- Cross-Clinic PHI: Clinic isolation preserved — Clinic A never reads Clinic B medical records (`canForObject` owner check, 404 on ownership mismatch).

## 2. Security boundaries

| Boundary | Enforcement |
|---|---|
| Organization Manager ≠ Clinic membership/PHI | Provisioning creates Clinic + primary Location only; no `cpms_clinic_memberships`, no `membership_locations`, no clinician row, no capability override |
| No auto-selection | Every Organization and Clinic selector has empty default `— انتخاب —`; the Location, staff, and custom-role management screens also require an explicit Clinic even for sole memberships; server never falls back to the first/only row |
| No WP Admin bypass | `manage_options` is only checked for the narrow first-manager bootstrap; all Org/Clinic operations require explicit durable memberships |
| Cross-Clinic PHI | `AuthorizationService::canForObject`, `TrustedClinicEstablisher`, per-Clinic repository ownership checks; tests assert Clinic A actor cannot read Clinic B patient/appointment |
| Capability precedence | Org: `explicit DENY > explicit GRANT > Manager preset > deny`. Clinic: same via `AuthorizationService` |
| Audit chain | Hash-chain via `AuditLogger` with prev_hash lock; audit failure rolls back the outer transaction (profile/lifecycle/bootstrap/provision) |
| Backup history | Suspended clinics remain in `cpms_clinics` and are included in Backup enumeration; audit table is append-only |
| Server/domain | `sanitize_text_field`/`sanitize_title`/`absint` + prepared SQL via `CpmsDb::prepare`; `wp_nonce_field` on every form; no `clinic_id` trust from client |

## 3. Concurrency & idempotency (tested)

- Clinic profile update: Organization + Clinic `FOR UPDATE` locks; noop on identical data; concurrent writers serialize via InnoDB row locks.
- Clinic lifecycle: Organization lock serializes `set_clinic_status`; conditional update checks expected Clinic row; second identical lifecycle request returns noop without second audit event; audit failure rolls back status.
- Location lifecycle already covered by `LocationService::set_active` with Clinic lock and active-count protection (primary immutable, at least one active Location required).
- Bootstrap provisioning retains the prior two-process fork test for first-Manager race.

## 4. Facility catalog — twelve types maintained, unsupported transitions documented

**Catalog is presentation/configuration only** (never authorization, never tenant boundary). All 12 ids/labels from `FacilityTypeCatalog::all()` are preserved:

1. مطب پزشک — انفرادی یا مشترک
2. درمانگاه عمومی
3. درمانگاه تخصصی/پلی‌کلینیک
4. کلینیک/درمانگاه دندانپزشکی
5. مرکز تشخیصی و پاراکلینیکی
6. مرکز توانبخشی
7. مرکز سلامت روان
8. مرکز درمان تخصصی سرپایی
9. مرکز جراحی محدود/سرپایی
10. بیمارستان
11. مرکز جامع سلامت و خدمات بهداشتی
12. سایر مراکز درمانی

**Unsupported / not yet provisioned automatically:**
- **Clinic → Clinic facility-type transition** (e.g., مطب → بیمارستان) — no automatic schema/data migration; type is stored as `setup.facility_type` settings and is not a lifecycle state. Changing type after setup requires explicit product decision and manual migration, not yet implemented.
- **Inpatient/HIS workflows** for hospital type — only outpatient flows are supported; `FacilityTypeCatalog::flow_supports_inpatient()` is `false` for every flow and no HIS module is fabricated.
- **Medical-building multi-Clinic auto-provisioning via wizard** — the wizard adaptive flow still marks `medical_building` as `flow_supports_provisioning = false`; multi-Clinic creation is only via explicit Organization Manager provisioning, not via the wizard.
- **Automatic configuration/completion for every newly provisioned facility type** — adaptive configuration/completion remains unimplemented for newly provisioned Clinics; the wizard does not claim to configure unsupported inpatient workflows.

## 5. Tests (DB-backed; committed for CI — NOT executed locally, see §6)

New: `tests/Integration/ClinicLifecycleManagementTest.php` (18 tests, real MySQL/MariaDB via the WP test suite, `RealTableMigrations`):

| Concern | Tests |
|---|---|
| Clinic settings update + protected columns + audit | `testOrganizationManagerCanUpdateClinicProfileAndAuditIsPreserved`, `testClinicProfileUpdateRejectsValidationWithoutMutation` |
| Idempotency (no duplicate audit) | `testClinicProfileUpdateIsIdempotentOnIdenticalData`, `testClinicLifecycleIsIdempotentAndDoesNotDuplicateAudit`, `testClinicLifecycleViaAdminBoundaryIsIdempotent` |
| Negative permissions | `testClinicMemberCannotUpdateClinicViaOrganizationPath`, `testWordPressAdministratorWithoutOrganizationMembershipIsDenied`, `testOrganizationDenyOverridesClinicAdministrationPreset`, `testLocationAdminPageEnforcesExplicitClinicSelectorAndDeniesOutsider` |
| Cross-tenant isolation | `testOutsideOrganizationManagerCannotUpdateOrLifecycleOtherOrganizationClinic`, `testAdditionalLocationCreationUnderExplicitAuthorizedClinicAndCrossClinicIsolation`, `testCrossClinicPHIIsolationIsPreservedAfterClinicSuspend` |
| Lifecycle + historical continuity | `testClinicLifecyclePreservesMedicalFinancialAuditAndBackupHistory`, `testSuspendedClinicBlocksTrustedClinicEstablishmentButOrgManagementStillWorks`, `testClinicLifecycleRespectsSuspendedOrganizationFailClosed` |
| Audit atomicity / no orphan mutation | `testDeniedLifecycleLeavesNoAuditRowAndNoStatusChange` |
| Explicit-selector admin boundary | `testClinicManagementPageRejectsNoSelectionAndRespectsExplicitAuthorization` |
| Facility catalogue unchanged | `testTwelveTypeFacilityCatalogIsPreservedAndInpatientRemainsUnsupported` |

Additional regression coverage for this post-onboarding management slice:
- `tests/Integration/Phase4LocationMasterDataTest.php`: address/phone create/update and masked audit evidence; explicit Clinic selection; authorization-before-validation denial; schedule, appointment, visit, clinical note/version, invoice and payment preservation after Location deactivation; audit-failure rollback for create/profile/lifecycle.
- `tests/Integration/StaffManagementAuthorizationTest.php`: no-selector UI and write denial, including a sole active membership, plus explicit-selector positive controls.
- `tests/Integration/CustomRoleStaffAssignmentTest.php`: missing Clinic selector denied for both assignment and assignable-role lookup without membership or audit mutation.
- `tests/Integration/CustomRoleManagementServiceTest.php` and `CustomRoleArchivalTest.php`: custom-role definition service/UI also require an explicit Clinic; a sole membership is not selected implicitly.
- `tests/Unit/Admin/PublicShortcodeAdminPageTest.php`: the read-only shortcode helper also requires explicit Clinic selection and no longer infers a sole membership.

Updated (schema-head pins only, required by the new migration `2026_10_18_0029`): `MigrationTest`, `MariaDb1011CompatibilityTest`, `CustomRoleDisplayNameMigrationTest`, `CustomRoleMigrationSafetyTest`, `FinanceAuthorizationPolicyRegressionTest`, `Phase2SchemaTest`, `CustomRoleArchivalMigrationTest` (rollback helper now steps back through 0029 → 0028 → 0027).

Lifecycle **concurrency** is serialized by the Organization row lock plus `SELECT ... FOR UPDATE` on the Clinic row and a conditional update; a two-process race test was **not** added in this slice (the existing bootstrap race test covers the analogous Organization-lock pattern) — tracked, not claimed.

## 5.1 Enforcement points for a suspended Clinic (evidence vs. inference)

| Surface | Respects `cpms_clinics.status = suspended`? | Evidence |
|---|---|---|
| Staff/admin write paths that establish trusted scope (`LocationAdminPage`, `StaffManagementPage`, `ClinicianAdminPage`, `CustomRoleDefinitionsPage`, `CpmsSetupWizard`, `DoctorHandwritingPage`) | **No — deliberately.** `TrustedClinicEstablisher` is not a lifecycle gate (an earlier blanket `clinic_suspended` block was reverted): existing-care and continuity operations must keep working in a suspended Clinic. Suspension is enforced at the new-demand boundaries listed above instead | code in this slice |
| Organization-level Clinic administration (this page) | **Yes by design** — the Organization Manager can still list/update/reactivate a suspended Clinic; suspension blocks *operational* Clinic scope, not Organization administration | code in this slice |
| Backup enumeration | **No, intentionally** — `BackupService` selects all `cpms_clinics` rows so suspended-Clinic history keeps being backed up | pre-existing code, unchanged |
| Audit history | **No, intentionally** — `cpms_audit_logs` is append-only and never filtered by Clinic status | pre-existing code, unchanged |
| Public booking shortcode Clinic chooser | **Yes** — `PublicBookingShortcode::findClinic()` reads `cpms_clinics.status` and does not advertise a suspended Clinic | code in this slice |

## 6. Pending / explicitly not claimed

- **Public booking chooser does not yet hide/refuse a suspended Clinic.** `PublicBookingShortcode` documents that `cpms_clinics` historically had no active flag and treats "a durable row with this id" as a valid Clinic. Making public booking honour `status = suspended` is a separate, deliberately un-taken decision (it changes a public-facing enumeration/availability contract and needs Owner product acceptance). **Tracked, not implemented, not claimed.**
- **Facility-type transitions are not supported.** No automated Clinic→Clinic type migration (e.g. مطب → بیمارستان); `setup.facility_type` is configuration, not a lifecycle state. Inpatient/HIS remains unsupported for every flow. Adaptive per-type completion for newly provisioned Clinics remains unimplemented.
- **Tests were NOT executed in this sandbox** — no PHP interpreter is available in the agent workspace (`php` absent, `phpunit.phar` present but unrunnable). All new/updated tests are committed for CI execution; no local pass/fail result is claimed. See the PR body for the exact list.

- **Real-browser submission coverage (implemented in CI):** `clinic-practice-management/bin/rwp_classic_submissions.py` (invoked by `bin/rwp-acceptance.py` in `Real WordPress Acceptance`) exercises real Chromium submissions for:
  1. Patient cross-Clinic self-enrollment (`PatientEnrollmentSection` → `POST /clinic/v1/patient/enroll`, `classic.enrollment.*`).
  2. WordPress Administrator bootstrap of Organization Manager (`cpms-organization-manager-bootstrap` → `cpms_bootstrap_organization_manager`, `classic.bootstrap.*`) and B4 pure `manage_options` no-silent-grant boundary (`classic.b4.*`).
  3. Organization Manager Clinic provisioning (`cpms-organizations`), Clinic profile update & lifecycle suspend/reactivate (`cpms-clinic-management`, B1/B2), and Clinic Manager Location create/update/lifecycle (`cpms-locations`, B3) with offline Iranian 31-province/city selectors (`ClinicCore\Domain\Geography\IranProvinceCityCatalog` + `ClinicCore\Admin\AddressSelectorField`, `classic.geography.*`).
- **Owner real-host acceptance checklist:** `docs/testing/classic-real-host-acceptance-checklist.md` (evidence-based checklist for independent execution on the Owner host; actual Owner-host execution is not claimed until performed on that host).
- Adaptive per-facility-type setup (full wizard configuration for every newly provisioned Clinic type) remains unimplemented — the slice is intentionally narrow and does not claim it.

## 7. How to verify

```php
# Clinic update via Org authority (explicit org + clinic)
App::organization_clinic_provisioning_service()->update_clinic_profile($actorId, $orgId, $clinicId, ['name'=>'...']);
# Suspend
App::organization_clinic_provisioning_service()->set_clinic_status($actorId, $orgId, $clinicId, false);
# History check
$db->fetchAll('SELECT * FROM cpms_clinics WHERE id = %d', [$clinicId]); // status=suspended, old invoices/patients still present
# Audit chain
App::audit()->verifyChain(1000)['ok'] // true
```

Admin: *سازمان‌ها → مدیریت درمانگاه‌ها* (explicit Org selector → clinic table → per-clinic update form → lifecycle buttons). All forms are Classic RTL, accessible, keyboard navigable.

## Clinic suspension — enforced boundaries (implemented)

Suspension is a **new-demand** control, not a scope blackout. The Clinic row keeps its scope, so
existing care and administration continue; only operations that would create new demand in that
Clinic are refused.

### Refused while `status = suspended`
| Boundary | Enforcement | Result |
| --- | --- | --- |
| `BookingService::hold()` | `assert_clinic_accepting_new_demand( …, for_update: true )` inside the transaction, **before** capacity is claimed | `BookingException CLINIC_SUSPENDED` (409), zero `held_count` |
| `BookingService::confirm()` | same locked guard, taken **before** the Slot lock | 409, **side-effect-free**: the pre-suspension Hold stays `active` with its capacity, no appointment, no `booked_count`; only the Idempotency key is freed for retry |
| `BookingService::rescheduleAs()` — the single implementation behind patient `reschedule()` and staff `rescheduleByStaff()` | locked guard on the **destination** Clinic before any Slot lock or capacity mutation | 409; the existing appointment survives untouched (id, status and counters unchanged) |
| `BookingService::createByStaff()` | guard right after `trustedClinicIdForStaff()`, before the transaction | 409; no staff or Organization bypass |
| Patient self-enrollment (`PatientService::enroll_current_patient()`) | `AND clinic.status = 'active'` inside the locked query | existing 404 parity preserved (no new information leak) |
| Slot generation sweep (`SlotsGenerateHandler`) | `cl.status = 'active'` join | no new Slots are generated for a suspended Clinic |
| Booking-selection OTP (`OtpController::requestCode()`) | suspended selected Clinic | `409 CLINIC_SUSPENDED`; selection-less purposes (login, mobile verification) still work |
| Patient enrollment destinations (`PatientEnrollmentRepository::destination_clinics()`) | `AND c.status = 'active'` in the destination query | a suspended Clinic is never offered by `PatientEnrollmentOptions::for_user()`; it returns after reactivation. Advertisement filtering only — `enroll_current_patient()` stays the server-side authority |
| Reception slot advertising (`ReceptionPortalController::reception_slots()`) | `cpms_clinics.status` read after the trusted Clinic/Location is resolved | suspended Clinic → the same bounded empty day (HTTP 200, no slots, no reason leaked) used for 0 eligible Locations |
| Public availability / quote / shortcode | non-locking `clinic_is_suspended()` read at the advertisement boundary | availability → HTTP 200 with `days = []`; quote → `available=false`, `capacity_left=0`; the shortcode does not advertise the Clinic. Non-locking on purpose: every demand-creating path re-checks under the Clinic row lock, so an advertisement read that races a suspension can never produce an appointment |

### Preserved while `status = suspended` (continuity)
Existing appointments and their lifecycle (`resume`, `listMine`, cancel, no-show), visits and
treatment completion, clinical records and medical files, finance settlement/refunds, reports,
exports, backup enumeration and recovery, audit/history queries, Organization administration of the
Clinic (profile read, settings, reactivation) and Location management. Leaving a suspended Clinic is
allowed: the reschedule guard applies to the **destination** Clinic, so a patient can be moved to an
active one, while a replacement appointment can never be created inside a suspended Clinic.

### Locking, atomicity and race behaviour
- Uniform lock order in every booking transaction: **Clinic row (`FOR UPDATE`) → Slot rows in
  ascending id order**; `rescheduleAs()` takes the old appointment lock before the Clinic, and
  `createByStaff()` now re-checks the status under the Clinic row lock **inside** its transaction
  before the Slot lock (its earlier pre-transaction check was unlocked, so a suspension landing
  between the check and the Slot lock was not serialized). No path locks a Clinic after a Slot, so a
  suspension racing a booking cannot form an ABBA deadlock cycle (MySQL would otherwise choose a
  victim and surface a raw database error).
- Patient self-enrollment uses the **management** order: Organization `FOR UPDATE` → Clinic
  `FOR UPDATE` (`enroll_current_patient()` previously locked Clinic→Organization through one joined
  `FOR UPDATE`, the inverse of `set_clinic_status()`/`update_clinic_profile()`). The unlocked
  `organization_id` candidate read is never trusted: Organization status, Clinic status and the
  Clinic→Organization relationship are all revalidated under the two locks, and every refusal keeps
  404 parity.
- All guarded mutations run inside the existing `db->transactional()` boundary; a denial rolls the
  whole transaction back, and `confirm()`'s compensation handler releases Hold capacity only for
  **non**-suspension failures.
- Lifecycle writes take the Organization row lock + Clinic `FOR UPDATE`, use a conditional update with
  expected-Organization match, read the result back and are idempotent (no duplicate audit when the
  status is already the desired one).

### Regression coverage
`tests/Integration/ClinicLifecycleManagementTest.php` (27 DB-backed tests) covers the boundaries
above, including `testSuspendedClinicIsNotAdvertisedOnPublicAvailabilityAndQuote` (with a real
active-Location + active-`cpms_schedule` positive control),
`testSuspendedClinicRejectsNewHoldWithoutCapacitySideEffects`,
`testSuspendedClinicRefusesConfirmingAnExistingHold`,
`testSuspendedClinicRefusesPatientAndStaffRescheduleButKeepsExistingAppointment`,
`testSuspendedClinicRefusesStaffCreatedAppointment`,
`testSuspendedClinicRefusesNewSelfEnrollmentButKeepsExistingRecords`,
`testSuspendedClinicIsNotOfferedAsEnrollmentDestinationAndReturnsAfterReactivation`,
`testManagerWithCreateDenyKeepsUpdateAndLifecycleUiReachability` and
`testSuspendedClinicPreservesContinuityAndRefusesNewDemandAtBoundaries`. No test is skipped or marked
incomplete. Teardown deletes children before parents (`cpms_audit_logs`, holds, appointments,
slots/schedule before clinicians, then Locations/Clinics/Organizations) so no committed row leaks into
a later test.
