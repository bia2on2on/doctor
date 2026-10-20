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

### 2.1 Fixed in this slice (business authority no longer WordPress-derived)

| ID | Location | Dependency | Replacement |
|---|---|---|---|
| A-01 | `RestBase::requireCap()` | Global capability was the only accepted source | Global **or** trusted-scope scoped capability; no ambient Clinic (`ScopeContext::tryGet()` only) |
| A-02 | `DoctorPortalShell::isDoctorUser()` | WP role `cpms_doctor` | Durable ACTIVE `cpms_clinicians` identity |
| A-03 | `StaffPortalShell::doctor_module_eligible()` | WP role `cpms_doctor` | Clinician identity + scoped caps (unchanged caps) |
| A-04 | `StaffPortalShell::reception_module_eligible()` | WP role `cpms_secretary` + global `read` | Scoped `cpms_queue_read/checkin/advance` |
| A-05 | `StaffPortalShell::reports_eligible_clinics()` | Global `cpms_report_read` | Scoped `cpms_report_read` per Clinic |
| A-06 | `ClinicalService::requireCap()` | Global `user_can()` | Scoped engine when a trusted scope exists (FinanceService precedent) |
| A-07 | `ClinicalService::requireRole('doctor')` | WP role `cpms_doctor` | Durable ACTIVE clinician identity |
| A-08 | `ReportService::requireCap()/missingCaps()` | Global `has_cap()` | Scoped engine when a trusted scope exists |
| A-09 | `ExportService::requireCap()/typeCaps check` | Global `has_cap()` | Scoped engine when a trusted scope exists |
| A-10 | All Organization/Clinic management workflows | Required the wp-admin pages (`admin-post.php`) | Dedicated frontend Management Portal (`cpms-management-portal`) |

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

1. **Browser acceptance for Doctor / Reception / Accountant / Patient without
   privileged WordPress roles is not yet proven in this slice.** The code paths are
   decoupled (A-01…A-09) and the existing Integration suite is green-by-policy, but a
   real-browser journey for each role class is only evidenced by CI runs attached to
   the PR. Treat any role-completeness claim as pending those runs.
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
  Finance and Custom Roles remain the regression net for 2.1.
