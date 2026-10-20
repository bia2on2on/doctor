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
