# Round 4 report — scoped authority hardening and the facility wizard

- **Code head:** `89ca847` (tree identical to `5a294f8`; the empty commit re-runs a flaky lane)
- **CI evidence head:** `9199c84` — same code tree, plus this docs-only report commit
- **Base:** `0f3d21a` (Round-3 head)
- **Branch:** `arena/4be58c19-doctor` · **PR #255 (Draft, not merged)** · PR #256 untouched

## 1. Completed in this round

### 1.1 Authorization contract (Phase A core)

`AuthorizationService::scoped_decision()`

| Durable membership in the requested Clinic | Decision |
| --- | --- |
| none | `null` — legacy WordPress-capability path |
| exists, non-ACTIVE (suspended/revoked) | `false` — final DENY, never rescued by a global WP cap |
| ACTIVE | `can()` — explicit DENY > explicit GRANT > role preset; missing capability ⇒ final `false` |

Consequences proven by `tests/Integration/ScopedAuthorityHardeningTest.php` (8 DB-backed
tests, green in CI):

- a WordPress-global capability cannot substitute for a missing scoped capability
  (no cross-Clinic bleed);
- a suspended membership is a nobody in that Clinic;
- an explicit scoped DENY outranks an explicit GRANT and any role preset.

### 1.2 Scoped role precedence

- `VisitService::roleForUser()` — with a trusted scope the durable membership decides
  (`doctor` / `secretary`). A scoped WordPress "doctor" **without** a membership stays a
  *doctor identity*: the queue resolves to that clinician's own empty set and is never
  widened to the clinic-wide staff view. Unknown actors resolve to `null`. WordPress roles
  answer only when no scope exists.
- `ClinicalService::search()` — the clinical/private widening flag comes from the
  membership role or the active clinician profile, not from a WP role.
- `MedicalFileService` — a `doctor_private` upload also requires an ACTIVE
  `cpms_clinicians` profile; an explicit `PRIVATE_NOTE_CREATE` grant held by a non-doctor
  is refused.

### 1.3 Fixture corrections (never the engine)

Report/export/queue suites written against the old `null`-means-fallback contract relied on
WordPress-global capabilities. They now GRANT the same capabilities **on the membership**:
`ReportsClinicIsolationTest`, `ExportClinicIsolationTest`,
`ReportsExportClinicAuthorizationTest`, `Phase2JobScopeRedFoundationTest`.

One pre-existing expectation was **strengthened, not weakened**:
`Phase4Slice4QueueSharedProfessionalTest::testDoctorWithSuspendedParticipationInBNeverSeesClinicQueue`
now asserts an explicit scoped DENY (`CLINIC_PERMISSION_DENIED`, 403) at the service layer
instead of a bounded empty projection; the denial message is asserted to carry no Visit
detail. The security property ("Clinic B never leaks") is unchanged and enforced earlier.

The suspended-Clinic **Reception** contract was preserved as-is: bounded EMPTY board read
(HTTP 200, no lifecycle reason leaked). A draft 403 guard was reverted because it
contradicted the established Phase 11 contract
(`Phase11ReceptionAppointmentCreateRedTest`).

### 1.4 Wizard

- The facility/Organization wizard (`FacilitySetupPage`) already implements the adaptive
  A–I journey on the existing Organization → Clinic → Location model (type → identity →
  sites → clinics → managers → rooms → clinicians → staff → schedules → booking → review →
  atomic activation; `medical_building` as its own structural flow; hospital outpatient-only
  acknowledgment; per-Clinic independent managers; no WP-role change).
- **Added this round:** the site/address step now uses the **offline** Iranian province/city
  catalog (`AddressSelectorField` + `IranProvinceCityCatalog`) — no network call, no
  preselected province, strict province↔city validation, free-text detail preserved,
  idempotent re-submit. Test:
  `FacilitySetupJourneyTest::testSiteAddressUsesTheOfflineProvinceCityCatalogAndRejectsAnOrphanCity`.

## 2. Exact-head CI (code tree at `89ca847`, re-run at `9199c84`)

| Lane | Result |
| --- | --- |
| Integration (WP 7.1 + MySQL 8) | **PASS** — 2125 tests, 59874 assertions, 0 failures, 0 errors |
| WPCS (changed code) / PHPStan / Unit 8.1–8.4 / Closure ×4 | **PASS** |
| Real WP Acceptance (`prefix clinic_`), First-practice browser (mariadb), Upgrade path, Tenant Tripwire | **PASS** |
| Real WP Acceptance (`prefix wp_`) | **PASS at `9199c84`**; at `89ca847` it failed once for **infrastructure** only (`curl error 28` to `packagist.org` + baseline-ZIP build failure) and passed at the preceding SHAs |
| Coexistence ×3 | **FAIL — upstream pin drift** (`persian-woocommerce` 10.0.5→10.0.6, `-sms` 7.3.1→7.3.2); unfixable here (artifact identity needs downloads.wordpress.org) |
| Responsive smoke (Chromium ×4), Staging Gate | still pending at report time |

Flake recorded honestly: `LocationDeactivationBookingConcurrencyTest::testLocationDeactivationSerializesWithBookingAndPreservesHistory`
failed once at `5a294f8` (which contained no functional change relative to the run before it)
and passed on the identical tree at `89ca847`.

## 3. Facility-type coverage matrix

| Facility type | Flow | Provisioning proven | Evidence |
| --- | --- | --- | --- |
| `doctor_practice` | simple_practice | single-practice wizard (7 steps) | `FacilityOnboardingWizardTest`, First-practice browser lane |
| `general_clinic` | multi_doctor_clinic | facility wizard | `FacilitySetupJourneyTest`, `FacilityAdaptiveFlowEvolutionTest` |
| `specialized_clinic` | multi_doctor_clinic | facility wizard (incl. `medical_building` structure) | as above |
| `dental_clinic` | multi_doctor_clinic | facility wizard | adaptive-labels test |
| `diagnostic_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `rehabilitation_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `mental_health_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `specialized_outpatient_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `limited_surgery_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `hospital` | hospital_network | full journey: 2 outpatient Clinics, rooms, shared clinician, schedules, atomic activation | `testHospitalOutpatientJourney…`, `testMultiClinicActivation…` |
| `comprehensive_health_center` | multi_doctor_clinic | facility wizard | adaptive-profile test |
| `other` | multi_doctor_clinic | facility wizard | adaptive-profile test |

**Not claimed:** per-type *distinct* provisioning beyond hospital and medical building.
Selection, Persian per-type terminology and flow adaptation are tested; deeper
differentiation for the remaining ten types is still design/backlog.

## 4. Remaining gaps (explicit)

1. **No real-Chromium journey of the facility wizard.** The Organization-Manager and
   Clinic-Manager wizard paths are proven by DB-backed controller tests
   (`FacilitySetupJourneyTest`, 1686 lines) only. The existing browser lanes cover the
   single-practice wizard and the staff portals.
2. **MariaDB 11.8: NOT RUN** — unchanged gate (see audit §4.3); the CI MariaDB lane runs
   11.4.13, which is *not* evidence for 11.8.
3. Coexistence pins: upstream drift, Stage A fails closed; re-pinning needs verified
   artifact integrity.
4. Post-onboarding frontend management of Clinics/Locations/staff/clinicians/schedules
   exists in the Management Portal with tests, but has no browser evidence either.
5. `ScopedAuthorityHardeningTest` authored without a local PHP/MySQL runtime; it was
   written, linted with php-wasm, and passed its first real CI execution (+8 tests in the
   suite count, 0 failures).

## 5. Verdict

**BLOCKED for release.** The scoped-authority hardening and the wizard address step are
delivered and green on the exact head, but: coexistence lanes are red by upstream drift,
one lane is infra-flaked, MariaDB 11.8 is unverified, browser evidence for the facility
wizard does not exist, per-type provisioning is not complete, and independent
security/product acceptance is outstanding. PR #255 stays Draft; no merge.
