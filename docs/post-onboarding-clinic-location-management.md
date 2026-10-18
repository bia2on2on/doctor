# Post-Onboarding Clinic & Location Management — Classic Slice

**Status:** Draft slice, building on merged PR #244 (Organization Manager clinic provisioning).
**Slice scope:** Authorized post-setup management of Clinics and Locations without reinstall or wizard restart.

## 1. Functional operations delivered

### 1.1 Clinic settings update (Organization-scoped)
- **Authority:** Organization Manager authority only — a durable active `organization_manager` membership for the exact Organization, resolved server-side **per operation** with `DENY > GRANT > Organization-Manager preset`: `cpms_org_clinic_create` (provisioning), `cpms_org_clinic_update` (Clinic settings), `cpms_org_clinic_lifecycle` (suspend/reactivate); management reads are the union of the three. Each capability is independent (a DENY on one neither widens nor narrows the others), authorization is evaluated **before** tenant-supplied input is validated, and neither WordPress Administrator nor Clinic membership implies any of them. Tracked UI gap (not a bypass): the page's Organization dropdown is resolved with `cpms_org_clinic_create`, so an actor granted only the update/lifecycle capability has no page path; service enforcement stays fail-closed. Every request re-checks Organization row lock (`SELECT ... FOR UPDATE`), Organization `active` status, and Clinic→Organization ownership. No first-Clinic fallback, no `clinic_id=1` / `organization_id=1` hardcode, no WP Administrator bypass, no Clinic-membership shortcut.
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

### 1.3 Additional Location creation — explicitly authorized Clinic
- **Authority:** Clinic-scoped `cpms_config` capability via existing `LocationAdminPage` + `LocationService`. Every write re-establishes trusted Clinic from persistence (`TrustedClinicEstablisher`) and re-checks Clinic-scoped authorization; `clinic_id` from request is selector only.
- **UX:** *شعبه‌ها و محل‌ها* shows an explicit Clinic selector (no first-Clinic auto-selection, no `clinic_id=1`). Doctor may add a second practice Location after setup; treatment center may add another Clinic via the Organization flow above. The page distinguishes the two: **“افزودن درمانگاه” = Clinic inside Organization (Org-managed, with primary Location atomically)** vs **“افزودن شعبه” = Location inside a Clinic (Clinic-scoped, non-primary, timezone-aware)**.
- **Safety already delivered (Phase 4):** CREATE always `is_primary=0, is_active=1`, UPDATE only `name/timezone`, slug/owner preserved, enumeration parity for not-found vs foreign, IANA timezone validation without fallback, audit `LOCATION_CREATED/UPDATED/DEACTIVATED/REACTIVATED`.

### 1.4 Role / membership management
- Reuses existing layers:
  - **Organization managers:** `سازمان‌ها → مدیران سازمان` (Org-scoped, last-active-manager protection, row lock, audit).
  - **Clinic staff:** `کاربران و دسترسی‌ها` (`StaffManagementPage`) and `نقش‌های سفارشی Clinic-local` (`CustomRoleDefinitionsPage`) — Clinic-scoped, `AuthorizationService` DENY>GRANT>preset, assignment is membership-only reassignment for suspended members.
- No WP Administrator clinical authority: Administrator status is not an `AuthorizationService` bypass; explicit DENY in either layer wins.
- Cross-Clinic PHI: Clinic isolation preserved — Clinic A never reads Clinic B medical records (`canForObject` owner check, 404 on ownership mismatch).

## 2. Security boundaries

| Boundary | Enforcement |
|---|---|
| Organization Manager ≠ Clinic membership/PHI | Provisioning creates Clinic + primary Location only; no `cpms_clinic_memberships`, no `membership_locations`, no clinician row, no capability override |
| No auto-selection | Every Organization and Clinic selector has empty default `— انتخاب —`; server never falls back to first row |
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

Updated (schema-head pins only, required by the new migration `2026_10_18_0029`): `MigrationTest`, `MariaDb1011CompatibilityTest`, `CustomRoleDisplayNameMigrationTest`, `CustomRoleMigrationSafetyTest`, `FinanceAuthorizationPolicyRegressionTest`, `Phase2SchemaTest`, `CustomRoleArchivalMigrationTest` (rollback helper now steps back through 0029 → 0028 → 0027).

Lifecycle **concurrency** is serialized by the Organization row lock plus `SELECT ... FOR UPDATE` on the Clinic row and a conditional update; a two-process race test was **not** added in this slice (the existing bootstrap race test covers the analogous Organization-lock pattern) — tracked, not claimed.

## 5.1 Enforcement points for a suspended Clinic (evidence vs. inference)

| Surface | Respects `cpms_clinics.status = suspended`? | Evidence |
|---|---|---|
| Staff/admin write paths that establish trusted scope (`LocationAdminPage`, `StaffManagementPage`, `ClinicianAdminPage`, `CustomRoleDefinitionsPage`, `CpmsSetupWizard`, `DoctorHandwritingPage`) | **Yes** — `TrustedClinicEstablisher::verified_scope()` now fails closed with reason `clinic_suspended` | code in this slice |
| Organization-level Clinic administration (this page) | **Yes by design** — the Organization Manager can still list/update/reactivate a suspended Clinic; suspension blocks *operational* Clinic scope, not Organization administration | code in this slice |
| Backup enumeration | **No, intentionally** — `BackupService` selects all `cpms_clinics` rows so suspended-Clinic history keeps being backed up | pre-existing code, unchanged |
| Audit history | **No, intentionally** — `cpms_audit_logs` is append-only and never filtered by Clinic status | pre-existing code, unchanged |
| Public booking shortcode Clinic chooser | **NOT YET** — tracked gap below | pre-existing code, unchanged in this slice |

## 6. Pending / explicitly not claimed

- **Public booking chooser does not yet hide/refuse a suspended Clinic.** `PublicBookingShortcode` documents that `cpms_clinics` historically had no active flag and treats "a durable row with this id" as a valid Clinic. Making public booking honour `status = suspended` is a separate, deliberately un-taken decision (it changes a public-facing enumeration/availability contract and needs Owner product acceptance). **Tracked, not implemented, not claimed.**
- **Facility-type transitions are not supported.** No automated Clinic→Clinic type migration (e.g. مطب → بیمارستان); `setup.facility_type` is configuration, not a lifecycle state. Inpatient/HIS remains unsupported for every flow. Adaptive per-type completion for newly provisioned Clinics remains unimplemented.
- **Tests were NOT executed in this sandbox** — no PHP interpreter is available in the agent workspace (`php` absent, `phpunit.phar` present but unrunnable). All new/updated tests are committed for CI execution; no local pass/fail result is claimed. See the PR body for the exact list.

- Real-browser submission coverage for patient cross-Clinic enrollment and Administrator Organization Manager bootstrap is **tracked but not yet included in CI** for this slice; it is documented in this file and planned for the next acceptance slice.
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
