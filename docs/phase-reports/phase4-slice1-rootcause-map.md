# Phase 4 — Slice 1: Root-Cause Map (Professional Identity Multi-Clinic Participation)

Status: closed working note — the executable RED checkpoint on Draft PR #59 is
followed by the GREEN change (§2) whose exact-head evidence is recorded in §4.

- RED head: `84b0ae713d6e4d9968652021f949919cbc3137a1` (test-only commit)
- RED CI run: `35137717727` (`pull_request`, `CI`, completed/failure)
- RED evidence: `Tests: 864, Assertions: 10150, Failures: 5.` — exactly the 5
  expected contract failures, 0 errors; all fail-closed/Phase-3 control tests in
  the same file PASSED (bootstrap/fixture/product-path proven before the final
  assertion).
- RED test: `clinic-practice-management/tests/Integration/Phase4Slice1ProfessionalMultiClinicParticipationTest.php`
- Canonical design: `docs/architecture/phase0.5-target-model.md` §د-۶-۳ — `clinicians.clinic_id`
  is the **Home Clinic** («هرگز مرز مجوز/دامنهٔ داده نیست») and «Queryهایی که پزشکانِ
  کلینیک X را از `clinicians.clinic_id` می‌گیرند باید Membership-driven شوند
  (Query-level، نه Schema-level)»; `u_clinician_user` (۱ پروفایل بالینی برای هر
  `wp_user`) **حفظ می‌شود** و M:N از راه `cpms_clinic_memberships` ساخته می‌شود.
- **Migration: NONE** — the existing schema (0012 memberships + 0014 schedule
  uniqueness `(clinic_id, location_id, clinician_id, day_of_week, start_time)`)
  already expresses the relationship. `0021` was neither created nor reserved.

---

## 1. The single product root cause

One assumption, expressed in several places:

> «پزشک به Clinicِ ثبت‌شده در `cpms_clinicians.clinic_id` تعلق دارد، پس هر
> درخواست با Clinic معتبرِ متفاوت باید ۴۰۴ بگیرد.»

With `u_clinician_user` (M-6-3: one clinical profile per WP user) that assumption is
false as soon as a professional legitimately works in more than one Clinic. The SoT
for participation is a **durable ACTIVE `cpms_clinic_memberships` row** for the
profile's `wp_user_id`; `cpms_clinicians.clinic_id` is only the Home Clinic
(compatibility / historical record).

| # | occurrence (before this slice) | role of the comparison | classification |
|---|---|---|---|
| 1 | `ScheduleService::requireClinicianForTrustedClinic()` (`ScheduleService.php:406`) compared `clinicians.clinic_id` with the trusted Clinic and threw `CLINIC_NOT_FOUND` 404 | write-path participation gate (create / createException / regenerate) | **MUST CHANGE (this slice)** — verified RED #1, #2, #5 |
| 2 | `ScheduleService::requireClinicianWithinTrustedClinic()` (`ScheduleService.php:435`) same comparison via `requireClinician()` | read-path participation gate (list / listExceptions) | **MUST CHANGE (this slice)** — verified RED #3 |
| 3 | `ScheduleRepository::findByClinicianDay()` (`ScheduleRepository.php:70`) — no `clinic_id` in the predicate | «one row per weekday» pre-check applied **globally across the professional's identity** although 0014 redefined uniqueness **inside** `(clinic_id, location_id)` («چند شعبه در یک روز») | **MUST CHANGE (this slice)** — verified RED #2 (same weekday in another Clinic) |
| 4 | `ScheduleRepository::listByClinician()` / `listExceptions()` (`:115` / `:192`) — no `clinic_id` in the predicate | scoped read returned the professional's rows from **every** Clinic | **MUST CHANGE (this slice)** — verified RED #3 (cross-Clinic leak) |
| 5 | `SlotsGenerateHandler` (`__invoke` ~`:111`) skipped every schedule row whose `clinic_id` differed from `clinicians.clinic_id` (`SLOTS_GEN_SKIP_CLINIC_MISMATCH`), and `generateDaySlots()` matched exceptions by `(clinician_id, date)` only | operational generation of the row the durable schedule owns | **MUST CHANGE (this slice)** — verified RED #4 (zero Clinic-B slots; one Clinic's holiday would close the other Clinic's day) |
| 6 | `BookingService::requireClinician()` (`:1002`) + `assertClinicianWithinExplicitScope($homeClinicId)` (`:629`, `:732`, helper `:1024`) | participation gate on booking paths (availability / quote / hold / reschedule / staff create / list) that also **derives the flow's Clinic** from the home column | **same family — explicitly NOT changed in this slice.** Changing only the guard (without re-pointing slot/patient/appointment attribution to the trusted Clinic) would mix tenants; the smallest coherent fix for those flows is its own RED slice. Behavior is unchanged and therefore still fail-closed. |
| 7 | `VisitService::requireClinician()` (`:996`) + `guardClinicianWithinExplicitScope($homeClinicId)` (`:209`, helper `:1043`) | same shape on the walk-in path | **same family — explicitly NOT changed in this slice** (same reasoning as #6). |
| 8 | `VisitService::guardVisitWithinExplicitScope()` / `guardAppointmentWithinExplicitScope()` (`:1027` / `:1035`) | **object**-ownership check (visit/appointment row vs trusted Clinic) | **UNRELATED / correct as-is** — object ownership must stay 404-parity, independent of professional participation. |
| 9 | `VisitService::guardDoctorTransitionOwnership()` (`:1392`) | actor-is-the-visit's-doctor policy (403 + audit, intentionally different) | **UNRELATED / correct as-is.** |
| 10 | `ClinicianRepository::listAll()` / `findForClinic()` / `updateForClinic()` (`:31` / `:59` / `:145`) — `WHERE c.clinic_id = …` | wp-admin **administration** of a professional's profile inside one Clinic (single-Clinic staff surface; `ClinicianAdminPage` + setup wizard) | **compatibility query that remains** — not an authorization/participation boundary of the REST product paths; changing it needs its own product decision (which Clinic owns the profile edit form) and is out of this slice's minimum. |
| 11 | `ClinicianRepository::listAll()`'s `schedule_days` sub-query (no `clinic_id`) | wp-admin list **display counter** | **same family, display-only — deferred, documented** (no isolation/authorization effect; under multi-Clinic it counts the professional's rows in all Clinics). |
| 12 | `ScheduleService::regenerate()` → `ScheduleRepository::deleteFutureEmptySlots($clinicianId, …)` | ADR-0004 regeneration of the professional's *empty* future slots (booked/held rows are never touched) | **compatibility behavior that remains** — for a professional who participates in two Clinics this is idempotent churn of their own inventory (regenerated by the same enqueued sweep); it deletes rows it is entitled to (same professional) and discloses nothing. Cannot reach another Clinic's data for a professional without rows there. Follow-up if Clinic-scoped regeneration is wanted. |

No mechanical mass change: every occurrence was classified, and only the gates that
the executable RED proves to be the wrong boundary were changed.

---

## 2. GREEN change (smallest coherent fix, reusing existing durable membership infra)

1. **New primitive (one place, no parallel tenant logic)**
   `MembershipRepository::clinician_participates_in(int $clinician_id, int $clinic_id): bool`
   (`src/Infrastructure/Repository/MembershipRepository.php:255`)
   - profile must exist and be active;
   - `clinicians.clinic_id === $clinic_id` ⇒ true (**compat path**: preserves the
     existing single-Clinic behavior where a profile has no linked WP user or no
     membership row — evidence: `RestScheduleTest`, `C7PreIntegrationBoundaryTest`,
     `C9RestMessageI18nTest` fixtures);
   - otherwise an **ACTIVE** `cpms_clinic_memberships` row for the profile's
     `wp_user_id` in that Clinic is required ⇒ `MembershipRepository::find_active()`
     (suspended rows are not active ⇒ false).
   No `clinic_id = 1`, no `clinic_id = 0`, no first-row/first-Clinic fallback, no
   payload trust, no `LIMIT 1`-tenant selection.

2. **Schedule write path** — `ScheduleService::requireClinicianForTrustedClinic()`
   now checks participation in the **trusted Clinic** and returns that trusted
   Clinic (never the home column) for the row to be written.
   Reachable only through the established Phase 3 path (`RestBase::requireClinicPermission`
   → `App::scope()`; wp-admin after `replaceExplicitScope`); payload `clinic_id`
   handling is untouched (conflict ⇒ 422 `CLINIC_VALIDATION_FAILED`).

3. **Schedule read path** — `ScheduleService::requireClinicianWithinTrustedClinic()`
   returns the trusted Clinic (or `null` for the no-explicit-scope legacy callers),
   and `list()` / `listExceptions()` fetch through the Clinic-scoped finders
   (`listByClinicianInClinic` / `listExceptionsInClinic`) so a shared professional's
   other-Clinic rows are never disclosed.

4. **Weekday uniqueness inside the Clinic** — `create()` pre-checks
   `findByClinicianDayInClinic($clinicianId, $day, $trustedClinicId)`, matching the
   0014 uniqueness contract; the within-Clinic duplicate rule (400
   `CLINIC_VALIDATION_FAILED` + `errors.day_of_week = duplicate_schedule_day`) is
   preserved byte-for-byte.

5. **Operational generation** — `SlotsGenerateHandler`:
   - the sweep row's Clinic is the **schedule's** `clinic_id`;
   - a `LEFT JOIN cpms_clinic_memberships (wp_user_id, clinic_id, status='active')`
     brings participation in per row (no N+1);
   - the row is generated when the Clinic is the profile's home Clinic (compat) or
     the membership exists; otherwise the existing `SLOTS_GEN_SKIP_CLINIC_MISMATCH`
     warning + skip (fail-closed, per row);
   - from there down the **row's** Clinic is authoritative: Location ownership,
     per-Clinic horizon, exception scoping (`clinician_id AND clinic_id AND date`)
     and the persisted `cpms_schedule_slots.clinic_id`.

Unchanged on purpose: `AuthorizationService` and the capability policy; `ScopeContext`
as the only trusted-scope source; `RestClinicContext`/`TrustedClinicEstablisher`;
404/403 parity envelopes and Persian messages (`'پزشک یافت نشد'`,
`'عملیات برنامهٔ هفتگی بدون زمینهٔ کلینیک معتبر مجاز نیست'` — locked by
`C9RestMessageI18nTest`); `clinicians.clinic_id` values (never re-written);
membership rows (never created/modified by these paths).

---

## 3. Regression coverage carried by the RED test (now the GREEN contract)

| id | requirement | test |
|---|---|---|
| A | same professional + ACTIVE memberships A and B ⇒ usable in Clinic B (real REST create), still exactly one profile, home column untouched, control create in Clinic A still works | `testProfessionalWithActiveMembershipInClinicBIsUsableThroughScheduleCreatePath` |
| A′ | same professional may hold the same weekday in another Clinic; duplicate **within** the same Clinic still 400 with the stable reason | `testSameProfessionalMayHoldTheSameWeekdayInAnotherClinic` |
| F | scoped read exposes only the trusted Clinic's rows for the shared professional | `testScopedScheduleReadReturnsOnlyTheTrustedClinicRowsForTheSharedProfessional` |
| — | operational use through the real sweep (`enqueue → runTick → dispatcher → SlotsGenerateHandler`): Clinic-B slots are generated, and a Clinic-A holiday closes only Clinic A | `testSlotsGenerateHonorsParticipatingProfessionalInClinicBAndScopesExceptions` |
| B | no durable membership in the trusted Clinic ⇒ 404 `CLINIC_NOT_FOUND`, zero side effects (actor fully authorized in B) | `testProfessionalWithoutMembershipInTrustedClinicFailsClosedWithExistingParity` |
| B′ | suspended membership ⇒ same 404 parity; the home Clinic keeps working | `testSuspendedMembershipInTrustedClinicFailsClosed` |
| E | Phase 3 still denies: scoped `cpms_config` DENY ⇒ 403; global capability without membership ⇒ 403; zero rows | `testPhase3DeniesUnauthorizedActorsEvenWhenTheProfessionalParticipates` |
| F′ | payload `clinic_id` is never trusted: disagreement ⇒ 422 + no row anywhere; consistent ⇒ trusted Clinic only | `testRawPayloadClinicIdIsNeverTrustedAsTheRequestScope` |

No existing test was weakened to obtain GREEN.

---

## 4. Evidence (exact-head)

### 4.1 RED (test-only commit)

- head `84b0ae713d6e4d9968652021f949919cbc3137a1`, CI run `35137717727`
- `Tests: 864, Assertions: 10150, Failures: 5.` — the five expected contract failures:
  1. `testProfessionalWithActiveMembershipInClinicBIsUsableThroughScheduleCreatePath`
     — `Failed asserting that 404 is identical to 200` (`:196`)
  2. `testSameProfessionalMayHoldTheSameWeekdayInAnotherClinic` — `404 != 200` (`:271`)
  3. `testScopedScheduleReadReturnsOnlyTheTrustedClinicRowsForTheSharedProfessional`
     — Clinic-A read leaked the Clinic-B row (`assertNotContains` at `:333`)
  4. `testSlotsGenerateHonorsParticipatingProfessionalInClinicBAndScopesExceptions`
     — `Failed asserting that 0 is greater than 0` (`:409`)
  5. `testRawPayloadClinicIdIsNeverTrustedAsTheRequestScope` — `404 != 200` on the
     consistent-identifiers path (`:586`)
- Same run: WPCS, Unit (8.1–8.4), PHPStan, Tripwire green; the fail-closed and
  Phase-3 control tests of the same file passed (bootstrap/fixture/product path
  proven before the final assertion ⇒ VALID RED, not a fixture failure).
- Other workflows at that head: Closure Gate `success`, Real WP Acceptance `success`,
  Pilot/Staging Readiness Gate `success`.

### 4.2 GREEN (production commit)

- code head `fa82de32e2c2295833f36d834e18458e4bda807a` (CI run `35138546908`):
  4 of the 5 RED tests green; the 5th failed only on a **test-side accessor**
  (`errors.day_of_week` read at the wrong envelope level) — 863 passed / 1 failed.
- code head **`dd2ea4e89147a18c6abaef31a23694ae57220a96`** (CI run `35138907013`,
  attempt 1): **all 8 CI jobs success** —
  Integration: terminal summary `Time: 00:52.894, Memory: 88.50 MB`,
  `OK (864 tests, 10163 assertions)`; junit root suite
  `tests="864" assertions="10163" errors="0" warnings="0" failures="0" skipped="0"`
  (the same 864-test file set that produced the 5 RED failures).
- At that same SHA: **19/19 check runs success** (Integration, WPCS, PHPStan,
  Tripwire, Unit 8.1–8.4, Closure ×5, Release Artifact, Upgrade path,
  Staging Gate, Responsive smoke, Real WP Acceptance ×2).
- NOT RETRIEVED: per-testcase junit lines for the eight Phase-4 tests (the CI
  comment selects the Phase-3 RED-class suites; the artifact download is blocked
  from this environment). The aggregate junit root suite over the same file set
  (864 tests / 0 failures / 0 errors) is the evidence used, plus the RED list above.

### 4.3 Known residual (NOT changed in this slice)

`BookingService`/`VisitService` participation gates (occurrences #6/#7 in §1) still
derive the request's Clinic from `clinicians.clinic_id`; a participating professional
is therefore **not yet** bookable in their second Clinic (unchanged, fail-closed 404 —
no new hole). This is the concrete remaining blocker for full multi-Clinic
participation and requires its own RED slice.
