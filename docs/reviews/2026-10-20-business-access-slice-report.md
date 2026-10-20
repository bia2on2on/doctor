# Business-access slice — final report (2026-10-20, UTC)

- PR: https://github.com/bia2on2on/doctor/pull/255 — **Draft, not merged**
- Base (accepted baseline): `6c64a91ffb1c6ff20da218736fdc81e81ca2024e`
- Head at this report: `59c3c6c08c22c26415f7de78472d9dd68ebafb0e`
- Exact-head CI: **26/26 SUCCESS** (`gh pr checks 255`, verified 2026-10-20T08:48Z)
- Authorization receipt: `2026-10-20T07:32:12Z` (session start). Safe stop ≈ `09:22Z`, hard stop ≈ `09:32Z`.

## Verdict

**BLOCKED** for release authorization: Phase 1 (business-authority decoupling of the
operational REST/service/portal paths) is not delivered, the Doctor / Reception /
Accountant / Patient frontend journeys are therefore not proven, and MariaDB 11.8 is
**NOT RUN**. The delivered slice itself (Clinic Manager + Organization/facility
portals) is internally coherent, regression-free and review-ready.

## Delivered and evidenced

| Workflow | Actor | Evidence |
|---|---|---|
| Organization overview, Clinic create/update/status, Clinic-manager designation, Organization-manager grant/revoke | Subscriber + active Organization membership | `ManagementPortalTest::test_subscriber_with_organization_membership_manages_organization_through_portal` |
| Clinic profile/settings, Locations list/create/activate | Subscriber + active Clinic membership + `cpms_config` | `test_subscriber_with_clinic_membership_manages_clinic_through_portal` |
| Staff enrollment (account linking or `subscriber`-only provisioning), no-overwrite | Clinic manager | `test_subscriber_clinic_manager_enrolls_staff_and_provisions_only_a_subscriber`, `test_staff_enrollment_is_clinic_scoped_and_never_overwrites` |
| Membership activation/suspension as a durable deny | Clinic manager | `test_membership_suspension_is_a_durable_uncached_deny` |
| Location scope assignment (same-Clinic ACTIVE Locations only) | Clinic manager | `test_location_scope_assignments_require_active_locations_of_the_same_clinic` |
| Capability GRANT/DENY/clear, bounded by the actor's own authority | Clinic manager | `test_capability_rules_are_bounded_by_the_actors_own_authority` |
| Clinician registration, schedule creation through the published contract | Clinic manager | `test_clinician_registration_and_schedule_are_clinic_scoped` |
| Facility plan over all 12 facility types, hospital outpatient acknowledgment, sites, held Clinics, rooms, designation, review, atomic activation | Subscriber + Organization authority (+ explicit Clinic designation for Clinic-scoped steps) | `test_subscriber_org_manager_runs_hospital_setup_from_the_portal`, `test_hospital_facility_activation_is_atomic_from_the_portal` |
| Administrator alone gains nothing | `administrator` | `test_administrator_alone_cannot_reach_any_clinic_management_workflow` |

Security-negative coverage added in this slice: cross-Clinic membership ids, cross-Clinic
Location ids, cross-Organization plan actions, suspended membership deny, delegation
escalation refusal, authorization-before-object-lookup ordering, and `subscriber`-only
provisioning.

## Not delivered (honest gaps)

1. **Phase 1 decoupling** — an earlier batch (scoped-first capability resolution in
   `RestBase`, `ClinicalService`, `ReportService`, `ExportService`, `StaffPortalShell`,
   `DoctorPortalShell`) produced 16 failures + 3 errors in pinned security contracts and
   was reverted rather than weakening tests (see the audit §2.1). Operational portals
   still depend on the published WordPress-global gates.
2. **Doctor / Reception / Accountant / Patient frontend journeys** — not implemented in
   this slice, therefore not claimed; no browser evidence exists for them on a
   Subscriber-only account.
3. **Reports/finance data inside the portal** — only capability-decided links; the report
   services still authorize the legacy way.
4. **All 12 facility types "operational"** — the catalogue is selectable and the
   hospital/medical-building flows run end-to-end, but the non-multi-clinic types are
   labelled only; single-unit setup still uses the existing per-Clinic journey.
5. **MariaDB 11.8** — NOT RUN (no local MySQL/Docker). Diagnosis from code evidence:
   migration 0027's engine gate rejects `11.8.x`, so activation aborts deterministically;
   the reproducible isolated-installer test is in
   `docs/operations/2026-10-20-mariadb-11.8-activation-diagnosis.md`.
6. **Browser acceptance for the new portal** — NOT RUN; the exact-head browser jobs cover
   their own pre-existing scopes only.

## Infrastructure note

One `Pilot/Staging Readiness Gate` run failed at `composer audit` with
`https://packagist.org/api/security-advisories/ … HTTP/2 502` (upstream registry outage);
the next run of the same job passed. No production code was changed for it.
