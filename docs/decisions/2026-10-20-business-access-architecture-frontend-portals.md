# Business Access Architecture — Dedicated Frontend Portals (2026-10-20)

**Status:** Approved direction implemented as a first delivered slice (Draft PR).
**Scope owner decision (this task):**

> Only WordPress site Administrators need `wp-admin`. All other CPMS users complete
> their authorized business workflows through dedicated CPMS frontend portals.
> CPMS business authority comes from authenticated identity, active
> Organization/Clinic/Location membership and explicit GRANT/DENY rules — not from
> WordPress business roles or global WordPress capabilities.

## 1. What changed (this slice)

### 1.1 New dedicated Management Portal (frontend only)

| Piece | Path |
|---|---|
| Shell (page, template interception, private cache headers, admin-bar hiding) | `src/Frontend/ManagementPortalShell.php` |
| Request boundary (frontend POST + PRG, native sign-in, allowlisted actions) | `src/Frontend/ManagementPortalController.php` |
| Read model + authorized write orchestration | `src/Application/Management/ManagementPortalService.php` |
| Standalone server-rendered template | `templates/management-portal-shell.php` |
| Durable membership listing used by the portal | `MembershipRepository::list_for_clinic()` |

The portal is a plugin-owned published Page (`cpms-management-portal`) rendered by a
plugin-owned standalone template — the same WordPress-native mechanism already used
by the Patient, Doctor and Staff portals. There is **no** rewrite framework, no SPA
router, no second credential store, and no link or form post to `/wp-admin`
(including `admin-post.php`). Sign-in posts to the portal's own URL and uses
`wp_signon()` (WordPress core authentication); the rate limiter already registered
on `authenticate` applies unchanged.

### 1.2 Delivered workflows

**Organization Manager** (Organization membership only — no Clinic membership needed):
- Organization overview: Clinics, lifecycle status, active Location count, durable
  Clinic-manager designations.
- Clinic creation (`cpms_org_clinic_create`) and Clinic profile update
  (`cpms_org_clinic_update`).
- Clinic lifecycle suspend/reactivate (`cpms_org_clinic_lifecycle`); history is
  preserved.
- Clinic Manager designation (`cpms_org_clinic_manager_assign`) — explicit target only.
- Organization manager grants/revocations (`cpms_org_membership_manage`).

**Clinic Manager** (ACTIVE Clinic membership + `cpms_config` in that Clinic):
- Clinic profile/settings (`ClinicProfileService`).
- Locations: list, create, activate/deactivate (`LocationService`).
- Staff membership: list, and role assignment limited to built-in CPMS role keys on
  the durable membership row (`cpms_manager`, `cpms_doctor`, `cpms_secretary`,
  `cpms_accountant`, `cpms_patient`) plus delegable Clinic-local custom roles
  (`CustomRoleAssignmentService`). WordPress roles are never assignable here.
- Clinician directory and schedule surface entry point (configuration only).
- Reports / finance links are rendered **only** when the corresponding
  `cpms_report_read` / `cpms_finance_read` capability is effective in that Clinic.
  No clinical or financial row is read by the portal.

### 1.3 Authorization decoupling in existing surfaces — ATTEMPTED, REVERTED

A first batch replaced WordPress-global capability/role gates with Clinic-scoped
authority in `RestBase::requireCap()`, `ClinicalService` (`requireCap`,
`requireRole('doctor')`), `ReportService`, `ExportService`,
`DoctorPortalShell::isDoctorUser()` and the Staff Portal doctor/reception/reports
eligibility gates.

Exact-head Integration proved that batch is **not compatible with the pinned
security contracts** in this slice: 16 failures + 3 errors, including error-code
changes (`CLINIC_NOT_FOUND` expected, `CLINIC_PERMISSION_DENIED` observed), report
authorization expectations (`ReportsAuthzTest`,
`ReportsExportClinicAuthorizationTest`, `Phase14StaffPortalReports*`), export
isolation, REST trusted-context denial ordering, and prescription-concurrency
expectations. The batch was **reverted to the base contracts** (not the tests).
The decoupling is therefore *not shipped* here; it is tracked as a remaining gap
in `docs/security/business-access-decoupling-audit.md` §2.3 with the exact
regression evidence.

### 1.3b Delegated verticals delivered in the portal (2026-10-20, second slice)

Clinic Manager (Clinic authority, ordinary Subscriber):
staff enrollment (`subscriber`-only provisioning or account linking), membership
activation/suspension, Location scope, capability GRANT/DENY/clear bounded by the
actor's own authority, clinician registration, weekly schedules.

Organization Manager (Organization authority only):
facility-aware onboarding reusing `FacilitySetupService` — plan start across the 12
facility types, hospital outpatient-only acknowledgment, sites, held Clinics, rooms,
explicit Clinic-manager designation, consolidated review and atomic activation.
Clinic-scoped facility steps (rooms/clinicians/schedules/booking policy execution)
still require an explicit Clinic membership + `cpms_config`, exactly as published;
this is why full hospital activation needs a designated Clinic manager, not the
Organization Manager alone.

### 1.3c Still not delivered

- Decoupling of the operational REST/service/portal gates from WordPress-global
  capabilities (see §1.3): Doctor, Reception, Accountant and Patient journeys still
  depend on the published global gates on those paths.
- Reports/finance *data* inside the portal: only capability-decided links are shown,
  because the report services still authorize the legacy way in this slice.

### 1.4 Not changed (deliberately)

- No schema/migration change; no destructive data change.
- Existing wp-admin screens remain functional for WordPress Administrators.
- Existing REST contracts (routes, methods, envelopes, error codes) are unchanged.
- Licence and suspension policies are untouched.
- Hash-chained audit writes are unchanged; every write still audits through the
  existing domain services.

## 2. Security invariants asserted by this slice

1. Organization authority never grants Clinic, clinical, financial or PHI access.
2. Clinic identifiers, Organization identifiers, Location identifiers and target
   user identifiers submitted by the browser are **selectors only**; every write
   re-establishes durable membership and re-reads object ownership.
3. A forged or cross-tenant identifier fails closed with a uniform, non-disclosing
   message.
4. A WordPress Administrator with no durable membership gets no management surface,
   no workspace and no scoped capability (asserted directly against
   `AuthorizationService::can()`).
5. The portal's surfaces are derived from durable authority only: no `current_user_can`,
   `user_can` or `has_cap` call exists in the portal service or controller
   (asserted by test).
6. No portal route, form action or redirect targets `/wp-admin`.

## 3. Known gaps / remaining risks (honest list)

- **Phase 1 decoupling is not delivered.** The attempted scoped-first gates were
  reverted after exact-head Integration reported 16 failures + 3 errors in pinned
  security contracts. Doctor/Reception/Accountant/Patient workflows therefore still
  require their WordPress-global gates on the REST/service side; only the new
  Management Portal is WordPress-role-free.
- **`ClinicalService::requireRole` for non-doctor roles** returns immediately and
  relies on capability checks. Only `'doctor'` call sites exist today; a future
  secretary-shaped gate must be expressed as a capability, not a role.
- **wp-admin pages still read WordPress roles/capabilities** (73 occurrences across
  `src/Admin/`). They are the Administrator/legacy surface and are intentionally out
  of scope for this slice; they are *not* used by the frontend portals.
- **`FirstPracticeSetupService` / bootstrap paths** still require
  `manage_options`; first-time owner bootstrap remains an Administrator action by
  design (see `organization_manager_bootstrap`).
- **Facility wizard:** the wizard remains wp-admin-only in this slice. Phase 4
  (reusable in-portal onboarding) is *foundation only*: the portal architecture is
  service-backed and rerenderable outside wp-admin, but no wizard step is mounted in
  the portal and no claim is made that the 12 facility types are provisioned from
  the portal.
- **Staff creation:** creating a brand-new WordPress user from the portal is not
  implemented; the portal assigns roles to *existing* ACTIVE memberships. User
  creation stays in the existing staff flow until an invitation contract is approved.

## 4. Acceptance evidence

- `tests/Integration/ManagementPortalTest.php` (real migrations + MySQL, no mocks):
  Organization Manager and Clinic Manager flows on plain Subscribers; explicit DENY;
  suspended Organization membership; forged/cross-Clinic/cross-Organization ids;
  Administrator-alone denial; no-wp-admin/no-WordPress-role source assertions.
- Existing Integration suite, PHPStan and WPCS run on the exact PR head in CI.
- Real-browser acceptance: the existing browser workflows ran at the PR head for
  their own scopes; **no browser journey for the new Management Portal is claimed**
  in this slice.
