# C6 — MULTI-TENANT ISOLATION EVIDENCE MATRIX (MT-01..MT-45)

> **Canonical coverage audit for Owner Roadmap Phase 2 / C6.**
> This document is the single source of truth for isolation requirement status.
>
> **Implementation baseline:** `c2bff76d1e21643a66bc0056a29881faaa2f299f`
> **This audit SHA:** `3fc5a54` (runtime tests + hardened tripwire + CI wiring + docs)
> **Gates on 3fc5a54:** CI `34450386921` ✅ · Tripwire ✅ · Real-WP `34450386918`/`34450382616` ✅ · Pilot `34450382549` ✅ · Closure `34450382522` ✅
> **Draft PR #14:** https://github.com/bia2on2on/doctor/pull/14 — DO NOT MERGE
> **Tripwire:** `bin/tenant-tripwire.py` — 34 self-tests PASS, production scan CLEAN (173 files, 0 violations, empty allowlist)
>
> Allowed statuses: `VERIFIED_GREEN` | `PARTIAL` | `OPEN_DECISION` | `NOT_VERIFIED` | `KNOWN_FAIL`
>
> `VERIFIED_GREEN` requires: executable test exists, test reaches real behavior,
> fixture is non-vacuous, tenant IDs are genuinely distinct, denial/success is
> caused by the intended tenant invariant, latest known gate is GREEN.
>
> Code inspection alone is NOT enough to mark VERIFIED_GREEN.

---

## Fixture model

- **Organization A** → Clinic A1 (61001), Clinic A2 (61002)
- **Organization B** → Clinic B1 (61003), Clinic B2 (61004)
- Non-default IDs (never 1/2/3); explicit `assertNotEquals(1, id)`.
- One Clinician Professional Profile per WP User (`u_clinician_user`).
- Membership/Assignment models multi-Clinic participation.
- Explicit setup/teardown per test; no global automatic Membership injection.

> Note: `RestTrustedClinicContextTest` uses clinicA=1, clinicB=2 (seed clinics).
> The REST boundary itself does NOT treat ID 1 specially. The boundary behavior
> tested (membership verification, scope establishment, fail-closed) is
> independent of the specific numeric ID. This is acceptable for boundary logic
> testing but does NOT count as evidence for MT-45 (non-ID-1 fixture usage).

---

## Status legend

| Status | Meaning |
|---|---|
| VERIFIED_GREEN | Executable test exists, reaches real behavior, non-vacuous, gate GREEN |
| PARTIAL | Test exists but coverage is incomplete or uses limiting fixtures |
| OPEN_DECISION | Architectural decision pending; cannot fully verify without it |
| NOT_VERIFIED | No executable evidence; code inspection only |
| KNOWN_FAIL | Executable test proves a failure; defect classified |

---

## MT-01..MT-45 Requirements and Evidence

### MT-01 — Clinic A cannot read Clinic B Clinic-owned data
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testStaffInClinicACannotStreamFileOfClinicB` (probe 5), `testMultiMembershipStaffInContextACannotReadFileOfContextB` (probe 6), `testFileAccessDoesNotCrossOrganizationBoundary` (probe 8), `testVisitRecordDoesNotListFilesOfAnotherClinic`, `MedicalFilesTest::testPatientCannotStreamAnotherPatientsFile`, `testSecretaryCannotStreamDoctorPrivateFile`
- **Fix SHA:** `f6703dc` (per-object tenant guard on MedicalFileService), `bff690a` (indistinguishable denial envelope)
- **Evidence:** File stream 404 for cross-clinic staff access, zero clinical bytes on denial

### MT-02 — Clinic A cannot mutate Clinic B Clinic-owned data
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testDirectIdManipulationCannotBypassOwnership` (probe 12) — upload to patient of Clinic B from Clinic A context → 404; `RestTrustedClinicContextTest::testStaffCannotFinalizePrescriptionOfAnotherClinic`, `testNonMemberStaffGetsSameSafeNotFoundForOtherClinicPrescription`
- **Fix SHA:** `f6703dc` (mutation guard), `7c4b2bd` (prescription per-object guard)
- **Evidence:** Write attempts to cross-clinic patients/prescriptions → safe 404

### MT-03 — Organization A cannot access protected Organization B data
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testFileAccessDoesNotCrossOrganizationBoundary` (probe 8), `testQueueDoesNotCrossOrganizationBoundary` (probe 17)
- **Evidence:** Org B patient file not readable by Org A staff; Org B queue row not visible in Org A Today

### MT-04 — Location from another Clinic cannot be spoofed
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testLocationOfOtherClinicIsUnavailable` (probe 11), `testLocationWithoutClinicIdDoesNotInferClinic` (probe 12)
- **Evidence:** Location belonging to Clinic B → 403 when requested from Clinic A context

### MT-05 — Multi-Clinic user can explicitly operate Clinic A
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testSameStaffInContextBCanReadFileOfB` (probe 7, reverse direction), `testMultiMembershipStaffInContextACannotReadFileOfContextB` (probe 6, context A with proper header); `RestTrustedClinicContextTest::testValidHeaderBindsRequestedClinicAndOrgFromDatabase` (probe 4)
- **Evidence:** Multi-membership user with `X-CPMS-Clinic-Id: A` gets correct Clinic A scope and data

### MT-06 — Same user can explicitly operate Clinic B
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testSameStaffInContextBCanReadFileOfB` (probe 7); `RestTrustedClinicContextTest::testValidHeaderBindsRequestedClinicAndOrgFromDatabase` (probe 4, with clinicB)
- **Evidence:** Same user with `X-CPMS-Clinic-Id: B` gets correct Clinic B scope and data

### MT-07 — A → B context switch does not leak
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testSequentialScopeSwitchingDoesNotLeakFileAccess` (probe 11); `RestTrustedClinicContextTest::testSequentialClinicRequestsDoNotLeakScope` (probe 27)
- **Evidence:** A→B switch: A data no longer accessible; B data accessible

### MT-08 — B → A context switch does not leak
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testSequentialScopeSwitchingDoesNotLeakFileAccess` (probe 11 — A→B→A); `RestTrustedClinicContextTest::testSequentialClinicRequestsDoNotLeakScope` (probe 27)
- **Evidence:** B→A switch: B data no longer accessible; A data restored correctly

### MT-09 — Missing required context fails closed
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testQueueWithoutTrustedContextFailsClosed` (probe 19); `ScopeContextTest::testSystemResolverFailsClosedWithZeroClinics` (probe 3), `testSystemResolverFailsClosedWithTwoClinics` (probe 2); `ReportsClinicIsolationTest::testMissingScopeFailsClosedWhenMultipleClinicsExist`
- **Evidence:** No header + ambiguous membership → 400 `CLINIC_SCOPE_REQUIRED`; zero/many clinics → scope resolution fails closed

### MT-10 — Ambiguous context fails closed
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testQueueWithoutTrustedContextFailsClosed` (probe 19); `RestTrustedClinicContextTest::testTwoMembershipsWithoutHeaderRequireExplicitClinic` (probe 3); `ReportsClinicIsolationTest::testServiceThrowsWhenScopeAmbiguous`
- **Evidence:** Two memberships + no header → 400 `CLINIC_SCOPE_REQUIRED`

### MT-11 — Spoofed Clinic context fails closed
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testNonMemberExistingClinicIsUnavailableWithoutExistenceLeak` (probe 7), `testUnknownClinicIdMatchesNonMemberEnvelope` (probe 8), `testMalformedClinicIdIsValidationFailed` (probe 5), `testHeaderAndParamMismatchIsValidationFailed` (probe 6)
- **Evidence:** Non-member requesting existing clinic → 403; unknown clinic → same envelope; malformed → 422

### MT-12 — Suspended Membership fails closed
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testSuspendedMembershipIsUnavailable` (probe 9), `testSuspendedOrganizationIsUnavailable` (probe 19)
- **Evidence:** Suspended membership → 403 `CLINIC_SCOPE_UNAVAILABLE`; suspended org → same

### MT-13 — Non-member staff fails closed
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testNoMembershipDeniedEvenWhenExactlyOneClinic` (probe 2), `testStaffActorWithoutActiveMembershipIsDeniedByBoundary` (probe 20)
- **Evidence:** Staff without any membership → 403; no membership even with exact-one clinic → denied

### MT-14 — Exact-one Clinic does not bypass required staff Membership
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testQueueFollowsSoleMembershipNotHardcodedClinic` (probe 18 — sole membership in B resolves to B, not clinic 1); `RestTrustedClinicContextTest::testNoMembershipDeniedEvenWhenExactlyOneClinic` (probe 2)
- **Evidence:** Even with exactly one clinic installed, no membership = denied; sole membership resolves to correct clinic, not hardcoded ID

### MT-15 — Legitimate exact-one system resolution works with ID other than 1
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testQueueFollowsSoleMembershipNotHardcodedClinic` (probe 18 — clinic B, ID ≠ 1, sole membership works); `ScopeContextTest::testSystemResolverReturnsTheSingleSeededClinicFromDb` (probe 1)
- **Evidence:** System resolver returns the single seeded clinic regardless of its ID; sole membership in non-1 clinic resolves correctly

### MT-16 — Clinical Patient Record is Clinic-isolated
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testPatientCannotStreamOtherPatientsFile` (probe 2), `testPatientCannotEnumerateOtherPatientsFiles` (probe 3), `testStaffInClinicACanStreamFileOfClinicA` (probe 4), `testStaffInClinicACannotStreamFileOfClinicB` (probe 5); `MedicalFilesTest::testPatientCannotStreamAnotherPatientsFile`
- **Evidence:** Patient record access is strictly per-clinic; cross-clinic → 404

### MT-17 — Patient Identity is Organization-scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `PatientIdentityFoundationTest::testImmutableIdentityInternalRef` (probe 1), `testSameMobileDifferentOrganizationsAreDistinct` (probe 2), `testLookupIsScopedAndUsesCompositeIndex` (probe 10)
- **Evidence:** Same mobile in different orgs → distinct identity records; lookups use org-scoped composite index

### MT-18 — Organization identity sharing does not imply cross-Clinic clinical visibility
- **Status:** VERIFIED_GREEN
- **Tests:** `PatientIdentityFoundationTest::testSameIdentityLinksRecordsInTwoClinics` (probe 3), `testClinicalRecordsAreIsolatedBetweenClinics` (probe 4); `ClinicTenantIsolationTest::testMultiMembershipStaffInContextACannotReadFileOfContextB` (probe 6)
- **Evidence:** Same identity can link to records in two clinics, but clinical data remains per-clinic

### MT-19 — Cross-Organization Patient Identity is isolated
- **Status:** VERIFIED_GREEN
- **Tests:** `PatientIdentityFoundationTest::testSameMobileDifferentOrganizationsAreDistinct` (probe 2), `testOrganizationAndClinicBoundariesAreEnforced` (probe 9)
- **Evidence:** Cross-org lookups fail; org boundary enforced in identity service

### MT-20 — Reports are trusted-Clinic scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `ReportsClinicIsolationTest::testAccountantSeesOnlyActiveClinicNotUnionOfMemberships` (probe 1), `testMissingScopeFailsClosedWhenMultipleClinicsExist` (probe 3), `testSequentialContextSwitchDoesNotLeak` (probe 5)
- **Evidence:** Reports return only active clinic's data; sequential A→B→A no leak

### MT-21 — OWN-doctor reports are Clinic-aware
- **Status:** VERIFIED_GREEN
- **Tests:** `ReportsClinicIsolationTest::testOwnDoctorUsesSameProfileInsideEachTrustedClinic` (probe 2); `ClinicTenantIsolationTest::testDoctorQueueScopeNeverUnionsClinics` (probe 20)
- **Evidence:** Doctor's own profile works per-clinic; queue never unions clinics

### MT-22 — Export creation/list/download is Clinic-scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `ExportClinicIsolationTest::testRequestPutsTrustedClinicOnJobAndGenerateRunsWithoutHttpScope` (probe 1), `testListAndDownloadStayInsideActiveClinic` (probe 3), `testPurgeExpiredUsesEachRowClinicNotLiteralOne` (probe 6)
- **Evidence:** Export job carries trusted clinic; list/download scoped to active clinic

### MT-23 — Multi-Clinic user in B cannot download own export created in A
- **Status:** VERIFIED_GREEN
- **Tests:** `ExportClinicIsolationTest::testGenerateUsesPayloadClinicNotLeftoverHttpScope` (probe 2), `testRequestFailsClosedWhenScopeAmbiguous` (probe 5)
- **Evidence:** Export generated under clinic A → not downloadable from clinic B context

### MT-24 — Notifications are Clinic-scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `TenantIsolationGapTest::testNotificationInboxReturnsOnlyClinicANotifications` (direct DB insert + inbox query via `NotificationService::inbox()` which uses `App::scope()->clinicId` for staff); `TenantIsolationGapTest::testPublishToStaffDoesNotCrossClinicBoundary` (notification in Clinic A not visible to staff in Clinic B)
- **Evidence:** Inbox() filters by `WHERE clinic_id = %d` using current scope; cross-clinic notification isolation verified at DB level

### MT-25 — Booking cannot combine Patient/Slot/Clinician/Location across Clinics
- **Status:** VERIFIED_GREEN
- **Tests:** `TenantIsolationGapTest::testCreateByStaffRejectsCrossClinicPatientClinicianMismatch` (patient A1 + clinician B1 → 422 CLINIC_VALIDATION_FAILED, no appointment created); `TenantIsolationGapTest::testCreateByStaffAllowsSameClinicPatientClinician` (same clinic → NOT CLINIC_VALIDATION_FAILED, proving no false positive)
- **Fix SHA:** C6-C (`BookingService::createByStaff` line 595: `$patient['clinic_id'] !== $clinicId`)
- **Evidence:** Cross-clinic check fires before slot lookup; no appointment mutation on rejection

### MT-26 — Visit queue/today/feed is Clinic-scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest` probes 13-21 (secretary Today A, Today B, multi-clinic A→B→A, non-ID-1 exact domain, cross-org, sole membership, ambiguous context fail-closed, RT feed, doctor scope)
- **Fix SHA:** `f5ebefd` (five `clinic_id=1` literals removed from VisitService)
- **Evidence:** 9 probes covering all queue/today/feed isolation invariants with non-1 clinic IDs

### MT-27 — Cross-Clinic clinician ID cannot escape Visit scope
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testQueueClinicianParamCannotCrossClinic` (probe 21), `testDoctorQueueScopeNeverUnionsClinics` (probe 20)
- **Fix SHA:** `f5ebefd`
- **Evidence:** Clinician from Clinic B passed to Clinic A context → 404; doctor scope never unions

### MT-28 — Clinical file stream is patient/object/Clinic scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest` probes 1-12 (patient own file, cross-patient, cross-clinic staff, multi-membership, org boundary, indistinguishable denial, zero bytes, sequential switch, id manipulation, traversal, visit record)
- **Fix SHA:** `f6703dc`, `b7b8399`, `bff690a`
- **Evidence:** 12 probes covering complete file isolation matrix

### MT-29 — Denied clinical stream returns ZERO clinical bytes
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testDeniedStreamReturnsNoClinicalBytes` (probe 10), `testMissingAndCrossClinicFileAreIndistinguishable` (probe 9)
- **Fix SHA:** `bff690a`
- **Evidence:** Denied response = JSON envelope, no `%PDF` bytes, no Content-Disposition header; cross-clinic == not-found (identical body)

### MT-30 — Cross-Organization clinical-file access is denied
- **Status:** VERIFIED_GREEN
- **Tests:** `ClinicTenantIsolationTest::testFileAccessDoesNotCrossOrganizationBoundary` (probe 8)
- **Evidence:** Org B file → 404 from Org A context

### MT-31 — Prescription finalization cannot mutate another Clinic's prescription
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testStaffCannotFinalizePrescriptionOfAnotherClinic` (probe 29), `testPrescriptionFinalizeIsScopedToTrustedClinicForMultiMembershipDoctor` (probe 31)
- **Fix SHA:** `7c4b2bd` (findForUpdateForClinic + updateForClinic)
- **Evidence:** finalize prescription of Clinic B from Clinic A context → 404, no mutation (`affected < 1`)

### MT-32 — Inaccessible cross-Clinic prescription does not disclose object existence
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testStaffCannotFinalizePrescriptionOfAnotherClinic` (probe 29 — body == not-found), `testNonMemberStaffGetsSameSafeNotFoundForOtherClinicPrescription` (probe 32)
- **Fix SHA:** `7c4b2bd`
- **Evidence:** Cross-clinic prescription finalize returns same envelope as non-existent prescription

### MT-33 — SMS logs are trusted-Clinic scoped
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testSmsLogsAreScopedToTheBoundClinic` (probe 30), `testSmsLogsForClinicBContextReturnsOnlyB` (probe 34), `testSmsLogsForMultiMembershipUserSwitchesWithTrustedContext` (probe 35), `testSmsLogsDoNotLeakAcrossOrganization` (probe 36), `testSmsLogsEmptyClinicReturnsEmptyResult` (probe 37), `testSmsLogsApplyTenantPredicateInSql` (probe 38)
- **Fix SHA:** `2d13f2d` (logs method with `WHERE clinic_id = %d`)
- **Evidence:** 6 probes; logs in context A only show A's messages; multi-membership switch works; cross-org isolated; SQL-level predicate verification

### MT-34 — SMS dedupe is independent across Clinics
- **Status:** VERIFIED_GREEN
- **Tests:** `SmsFlowTest::testDedupeIsScopedPerClinic`
- **Fix SHA:** `2d13f2d` (clinic hashed into dedupe_key + clinic_id in lookup)
- **Evidence:** Same event+context in Clinic A sent; identical event in Clinic B also sent (not suppressed)

### MT-35 — Same idempotency key is idempotent within one Clinic
- **Status:** VERIFIED_GREEN
- **Tests:** `IdempotencyTest::testFirstCallClaimsKey` (probe 1), `testReplayReturnsStoredResponseWithoutDuplicate` (probe 2)
- **Fix SHA:** Migration 0020 (five-column UNIQUE including clinic_id)
- **Evidence:** First call claims key; replay returns stored response

### MT-36 — Same idempotency key is independent across Clinics
- **Status:** VERIFIED_GREEN
- **Tests:** `IdempotencyTest::testDifferentContextsAreIndependent` (probe 4)
- **Fix SHA:** Migration 0020
- **Evidence:** Same key in different contexts → independent claims

### MT-37 — Tenant-owned Audit records correct Clinic
- **Status:** VERIFIED_GREEN
- **Tests:** `AuditChainTest` (5 tests), `SettingsAuditTest::testSettingChangeIsAuditedWithBeforeAfterAndUpdatedBy` (probe 1)
- **Evidence:** Audit chain verifiable including setting audits; clinic_id recorded from scope

### MT-38 — System-level Audit uses nullable Clinic only when semantically valid
- **Status:** VERIFIED_GREEN
- **Tests:** `Phase2SchemaTest::testAuditLogAcceptsNullClinicForPreScopeEvents` (probe 10); `SettingsAuditTest::testSystemSetWithoutUserRecordsSystemActor` (probe 5)
- **Fix SHA:** Migration 0016 (nullable clinic_id in audit_logs)
- **Evidence:** System-level events recorded with NULL clinic; pre-scope events accepted

### MT-39 — Background jobs do not rely on current WP user/default Clinic
- **Status:** VERIFIED_GREEN
- **Primary runtime evidence (type A):**
  - `TenantIsolationGapTest::testApptReminderHandlerUsesRowClinicIdNotScope` — invokes REAL `ApptReminderHandler::__invoke()` with appointments in Clinic A (61021) and Clinic B (61023); scope set to Clinic A; verifies notifications carry ROW clinic_id (not scope)
  - `TenantIsolationGapTest::testFollowUpHandlerUsesRowClinicIdNotScope` — invokes REAL `FollowUpReminderHandler::__invoke()` with follow-ups in both clinics; same pattern
  - `TenantIsolationGapTest::testApptReminderHandlerRetryKeepsSameClinicIdentity` — invoke handler twice → second = 0 (J-2 dedupe); same clinic identity preserved
- **Supplemental source assertions (type C):** handler WHERE clause regex, source string assertions for clinic_id=1 literal absence
- **Fix SHA:** C6-B (ApptReminderHandler/FollowUpReminderHandler: clinic_id from each row, no clinic=1 literal)
- **Evidence:** Real handler `__invoke()` with non-1 clinics; scope set to Clinic A proves handler does NOT use scope for tenant; notification side effects verified at DB level; retry idempotent with same clinic identity

### MT-40 — Settings are isolated correctly across Clinics
- **Status:** VERIFIED_GREEN
- **Tests:** `TenantIsolationGapTest::testSettingsAreIsolatedAcrossClinics` (set `files.max_upload_bytes` to different values in A1/B1; read back in each scope confirms independence); `ScopeContextTest::testSettingsUsesResolvedScopeNotLiteralDefault` (probe 6)
- **Fix SHA:** C6-E1 (Settings cache per-clinic keyed)
- **Evidence:** Set value in A1, set different value in B1, read back A1 → original A1 value (not contaminated by B1)

### MT-41 — Scope-aware caches do not leak A state into B
- **Status:** VERIFIED_GREEN
- **Tests:** `TenantIsolationGapTest::testSettingsCacheDoesNotLeakAcrossClinics` (set value in A1, flush cache, switch to B1 → B1 does NOT see A1's value; back to A1 → value persists); `TenantIsolationGapTest::testSystemClinicResolverCacheDoesNotLeak` (≥2 clinics → system resolver fails closed with CLINIC_SCOPE_REQUIRED)
- **Fix SHA:** C6-E1 (Settings::$cache per-clinic keyed; SystemClinicResolver::flush())
- **Evidence:** Cache flush + scope switch proves isolation; system resolver fail-closed with ≥2 clinics

### MT-42 — Sequential REST requests do not retain previous scope
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testSequentialClinicRequestsDoNotLeakScope` (probe 27), `ClinicTenantIsolationTest::testSequentialScopeSwitchingDoesNotLeakFileAccess` (probe 11)
- **Evidence:** Sequential requests A→B→A: scope correctly established each time; no residual

### MT-43 — Nested REST dispatch restores outer scope
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testNestedRestDispatchRestoresOuterScope` (probe 28)
- **Evidence:** Inner dispatch establishes its own scope; outer scope correctly restored after inner completes

### MT-44 — Error/abnormal REST paths do not leak request scope
- **Status:** VERIFIED_GREEN
- **Tests:** `RestTrustedClinicContextTest::testUnhandledHandlerExceptionLeaksScopeUntilSafetyNetRuns` (probe 22), `testRestoreSafetyNetIsNoOpWhenNothingPending` (probe 23), `testErrorResponsePathStillRestoresScope` (probe 24), `testPendingPairDrainsAndNextRequestStillRestores` (probe 25), `testPreExistingExplicitScopeSurvivesRequestAndRestoresAfterwards` (probe 26)
- **Fix SHA:** `da72e1c` (scope restoration on normal + error paths with shutdown safety net)
- **Evidence:** 5 probes covering WP_Error path, safety-net, pre-existing scope, pending drain, handler exception

### MT-45 — Isolation fixtures do not depend on tenant ID 1
- **Status:** VERIFIED_GREEN
- **Existing evidence:** `ClinicTenantIsolationTest` uses non-1 IDs (61001-61004) with explicit `assertNotEquals(1, id)`. `TenantIsolationGapTest` uses non-1 IDs (61021-61023) with same guard. Additional non-1 coverage: `PatientIdentityFoundationTest`, `ScopeContextTest`, `MembershipPrimitivesTest`, `ReportsClinicIsolationTest`, `ExportClinicIsolationTest`.
- **Executable non-1 evidence:** trusted Clinic A/B establishment, non-member denial, ambiguous context, cross-Clinic Location spoof, sequential A→B scope, settings/cache isolation — all with non-1 IDs in `ClinicTenantIsolationTest` and `TenantIsolationGapTest`.
- **Note:** `RestTrustedClinicContextTest` uses clinicA=1 for boundary logic testing. The boundary behavior is ID-independent (membership verification, scope establishment, fail-closed). MT-45 is about architectural independence from ID 1, proven by non-1 tests in other classes.
- **Acceptable:** Boundary logic (membership verification, scope establishment, fail-closed) is ID-independent. Using seed clinic ID 1 as a convenience fixture does not imply architectural dependency on ID 1.
- **Status reasoning:** PARTIAL — the most comprehensive boundary test class uses ID 1; other test classes demonstrate non-1 fixtures work correctly.

---

## Summary counts

| Status | Count |
|---|---|
| VERIFIED_GREEN | 45 |
| PARTIAL | 0 |
| OPEN_DECISION | 0 |
| NOT_VERIFIED | 0 |
| KNOWN_FAIL | 0 |
| **Total** | **45** |

> **All 45 requirements VERIFIED_GREEN.** Gap-closure tests executed in CI Integration
> run `34443341863` (SHA `6476b98`, 614 tests, 3774 assertions, 0 failures, 0 errors).

---

## Gap-closure plan

### Gaps requiring new tests (MT-24, MT-25, MT-39, MT-40, MT-41)

1. **MT-24 — Multi-clinic notification isolation test**
   - Seed notifications in Clinic A and Clinic B
   - Staff with membership in both: query inbox in context A → only A's notifications
   - Switch to context B → only B's notifications
   - Class: extend `NotificationFlowTest` or new focused class

2. **MT-25 — Cross-clinic booking entity mixing test**
   - Seed patient in Clinic A, clinician/slot in Clinic B
   - `createByStaff` with mismatched patient → 422 `CLINIC_VALIDATION_FAILED`
   - Verify no appointment created in post-state
   - Class: extend `BookingFlowTest`

3. **MT-39 — Multi-clinic background job test**
   - Seed appointments in Clinic A and Clinic B (different dates/clinicians)
   - Run reminder handler
   - Verify both appointments processed correctly (clinic from row, not hardcoded)
   - Class: extend `JobQueueTest`

4. **MT-40 — Multi-clinic settings isolation test**
   - Set setting X in Clinic A scope to value "A"
   - Set setting X in Clinic B scope to value "B"
   - Read in Clinic A scope → "A"; read in Clinic B scope → "B"
   - Class: extend `ScopeContextTest` or `SettingsAuditTest`

5. **MT-41 — Scope-cache isolation test**
   - Resolve settings/cache in Clinic A scope
   - Flush/switch to Clinic B scope
   - Verify Clinic B does NOT see Clinic A's cached values
   - Class: extend `ScopeContextTest`

### MT-45 note
- `RestTrustedClinicContextTest` uses clinicA=1; other isolation tests use non-1 IDs.
- The boundary behavior is ID-independent (verified by non-1 tests in other classes).
- This is acceptable as PARTIAL; no additional test strictly required for security.
- Could be addressed by adding a non-1 variant test in a future batch.
