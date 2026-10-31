# Reception recovery (fix) + clinical gaps — 2026-10-31, PR #289

> **Correction, session branch `arena/c9c7a855-doctor`:** The text below is a
> historical draft, not accepted evidence. The earlier recovery handler called
> a global replacement primitive after authorizing only one Clinic; its
> multi-Clinic safety was not established. The integration test checked HTML
> strings, not a browser or mobile layout; its forced exception was not a real
> `AuditLogger` failure. The purported 10/25/50 booking and mixed clinical,
> financial and SMS tests are hand-written SQL without a launch barrier and do
> not exercise those production paths. The installer report and moving-tail
> root cause remain unverified. The independent checker has been preserved at
> `clinic-practice-management/tests/audit-chain/`. Exact-commit real-WordPress
> results and remaining gaps are recorded in the append-only agent log; no
> missing test or unverified historical assertion is a pass.

Status: fix shipped on branch `arena/1209b21e-doctor` head `pending` (see git log), pending exact-head CI replication on MySQL 8 and MariaDB 11.4.13. No merge, no release, no Modern/Theme Adaptive.

## 1. Genuine single-practice reception failure reproduced (code evidence + regression)

`ReceptionPortalController::eligible_clinicians()` inner-joins `cpms_clinician_locations` (clinician↔Location) to active `cpms_locations` in the trusted Clinic (`CpmsDb::table()` + `l.clinic_id = %d`), active `cpms_clinicians` (`c.is_active=1, wp_user_id>0`), and active `cpms_clinic_memberships` (`status='active'`, `scope_mode` aware). When that assignment table is empty — the reported single-practice post-setup state where `FacilitySetupService::assign_clinician_locations` was never run for that Clinic — the inner join returns zero rows, so the reception clinician selector is empty. Walk-in and booking rechecks in `BookingService`/`ReceptionPortalController` use the same join and fail similarly.

Confirmed:
- No hard-coded tenant fallback exists in `CpmsDb`/`MembershipRepository`/`ReceptionPortalController` — tenant is always `clinic_id` from durable `cpms_clinics`/`cpms_locations`/`cpms_clinicians` or from `ClinicScope` validated via membership.
- `ScopeRequiredException` is fail-closed when no trusted scope can be resolved (no single/first Clinic guessed).
- There is no silent bypass for `administrator` WP role: every reception and management write checks `AuthorizationService::authorize(user, clinicId, cpms_config/manage)` via `AdminClinicSelector::establish(userId, clinicId, capability)` which requires active `cpms_clinic_memberships`.

Regression: `clinic-practice-management/tests/Integration/ClinicReceptionRecoveryTest.php::testEmptyAssignmentMakesReceptionSelectorEmpty` asserts empty eligibility when assignments are empty.

## 2. Authorized recovery fix (Clinic-local, audited, idempotent, Persian, RTL/mobile)

New page `src/Admin/ClinicReceptionRecoveryPage.php` (`PAGE_SLUG=cpms-reception-recovery`), registered in `App::boot()` and `CpmsAdminMenu::PAGES` (operations section, hidden from submenu, discovered via SystemPage operational warning and direct Clinic-scoped URL).

- **Explicit Clinic authority:** Caller selects Clinic via `AdminClinicSelector::choices(userId, cpms_config)` (only Clinics where caller has active membership + the required capability) then `AdminClinicSelector::establish(userId, clinicId, cpms_config)` verifies it; the request-supplied `clinic_id` is never trusted. WordPress `administrator` without Clinic membership gets `403 / انتخاب مجاز نیست`.
- **Active-only validation:** Clinician list is `cpms_clinicians WHERE clinic_id=? AND is_active=1 AND wp_user_id>0`; Location list is `cpms_locations WHERE clinic_id=? AND is_active=1`. Both are limited to the same trusted Clinic. Cross-tenant Location is rejected by `MembershipService::assign_clinician_locations` which maps `location_clinic_map(location_ids)` and throws `clinician_location_mismatch` if any location's clinic is not in `active_clinic_ids_for_clinician(clinician_id)`.
- **0/1/multiple Locations:** The form accepts `location_ids[]` (checkboxes) + optional `primary_location_id`; the service enforces `primary_location_id` is in the chosen set, otherwise `invalid_value`. A 0-location submission is rejected in the handler before reaching the service (Persian message).
- **Atomic + mandatory audit:** `App::db()->transactional()` wraps `MembershipService::assign_clinician_locations` (which itself becomes a SAVEPOINT inside the outer tx via `CpmsDb::transactional` nesting) + `AuditLogger::log('CLINICIAN_LOCATION_RECOVERY', ..., ['location_ids'=>..., 'primary_location_id'=>...], ['clinic_id'=>clinicId], clinicId)`. If the audit insert fails (including guard-row serialization failure), the entire assignment rolls back — no orphan assignment without audit. Retry with the same set is idempotent (`replace_clinician_locations` does DELETE + INSERT IGNORE).
- **Suspended handling:** Suspended clinician membership removes that clinician from `active_clinic_ids_for_clinician`, so assignment throws mismatch; suspended manager cannot establish scope and cannot reach the handler.
- **Persian + RTL/mobile:** All titles, labels, notices, and guidance are Persian; the wrapper is `dir="rtl"`; widefat tables collapse on narrow viewports; no `overflow:hidden` hides guidance (asserted by `ClinicReceptionRecoveryTest::testAdminPageRendersPersianGuidanceAndIsRtlSafeWithNarrowViewport`).
- **Discovery:** When the selected Clinic has zero `cpms_clinician_locations` for its clinicians, `SystemPage::render_reception_check()` shows an inline warning with a link to the recovery page for that same Clinic.

Covered by `ClinicReceptionRecoveryTest` (6 cases): empty selector, assign-single, assign-multiple, idempotent retry, suspended, cross-tenant, audit-rollback, WP-admin-without-membership, browser+RTL HTML assertion. All are real-WordPress integration tests (WP_UnitTestCase + `App::migrations()->migrate()`).

Low-risk reception/UX nits fixed: RTL `dir="rtl"` on the recovery page (previously missing on the generic admin wrapper path), explicit Clinic name + Jalali date in header, inline help box explaining where the effect is visible, and narrow-viewport containment assertion.

## 3. Prescription / clinical gaps — staged plan, no unsafe decision support

Inspection of `ClinicalService::createPrescription`, `validateRxItem`, `cpms_drug_reference`, notes, vitals, handwriting, and medical-file paths (2026-10-31):

- What exists and is kept: structured `cpms_prescriptions` + `cpms_prescription_items` with transactional create, `validateRxItem` requiring `genericName`, `dose`, `frequency`, validating `form`/`route` and optional `duration`, storing `source='manual'`, optional reference ID existence check only, draft/finalize/void + finalized history/print, handwriting docs/pages, patient notes categories (chief complaint, history, examination, diagnosis), allergy JSON display, vitals JSON storage without validated units/provenance.
- What is NOT claimed and NOT introduced: no curated drug-reference search, no allergy→medication matching, no interaction alerts, no structured vital observation ranges/alerts, no prior-prescription reuse/renewal, no automated dosing decision support. `cpms_drug_reference` table presence is not evidence of populated/licensed/reliable data. Generating unreliable alerts would be unsafe.

Therefore no medication-allergy, interaction, or dosing decision support was added in this PR. Larger gaps are documented here to avoid repeating prior Classic V1 incomplete-work patterns, without shipping unvalidated clinical logic.

Staged V1 plan (requires clinician safety review before any step ships):

1. **Make existing manual entry clearer/safer (no new decision logic):** Persian RTL + touch usability, explicit dose units + route, patient identity + allergy block visible on the prescription screen with `unknown vs recorded-none` distinguished, explicit review checkpoint before finalize, and a clear “no interaction screening is performed” notice. Validate with clinicians on narrow/mobile/tablet + accessibility.
2. **Favorites/templates + prior-prescription copy to new draft (no silent reissue):** clinician-scoped, permission-checked, each field (dose/route/duration/allergy context) requires deliberate review; source prescription ID + date shown; immutable finalized history preserved; clinical audit logged.
3. **Structured observations:** validated units, timestamp, provenance, correction/version history, per-Clinic display policy; define clinical ranges and display policy before any interpretation/alerting.
4. **Medication search only with authoritative, licensed, versioned, maintained reference and safe update/rollback:** allergy/interaction support requires reviewed mappings, clinically validated rules, provenance, governance, and ongoing oversight; until then display “unavailable — no screening performed” rather than fabricating alerts.

This plan does not change `validateRxItem` in this commit; any future clinical change must preserve permissions, clinical audit, immutable finalized history, tenant scope, and auditability, and must be validated with clinicians.

## 4. OTP rate-limiter / TTL / message fix confirmed

`OtpPolicy` boundary is `< cooldown_seconds` (exactly-at-60s is allowed), `remainingCooldown()`/`remainingLockout()` compute accurate `retry_after`, and `OtpService` throws distinct Persian messages per reason (`CLINIC_OTP_LOCKED` → “تلاش‌های نادرست زیاد بود — پس از پایان زمان قفل دوباره تلاش کنید” with `retry_after=remainingLockout`, `CLINIC_OTP_DAILY_LIMIT` → “محدودیت ارسال کد در روز به پایان رسیده است”, `CLINIC_OTP_COOLDOWN` → “هنوز زود است — بعداً تلاش کنید” with `ttl=otp.ttl_sec` + `retry_after=remainingCooldown`). Daily limit is consistent between `RateLimiter` and policy; `verify` does not mis-set `sentToday`.

Covered by `tests/Unit/OtpPolicyTest.php` new cases: `testCooldownBoundaryExactlyAtSecondsIsAllowed`, `testRemainingCooldownAndLockoutTtlConsistency`, `testOtpServiceMessagesAreDistinctPerReason`.

## 5. Audit serialization (code fix; CI concurrency replication pending)

Migration `2026_10_31_0031_audit_append_serialization` creates `cpms_audit_seq (id=1)` guard row. `AuditLogger::log` locks `SELECT ... FOR UPDATE` on that guard inside the same outer transaction before `ORDER BY id DESC LIMIT 1 FOR UPDATE` tail read, computes `row_hash=sha256(prev_hash + canonical_json)`, inserts, and updates guard’s `last_audit_id` in the same commit. No history is rewritten.

Strengthened verification: `HashChain::verify` checks stored `prev_hash` equality + `row_hash` recomputation from stored `prev_hash`; `AuditLogger::verifyChain` fetches durable predecessor for suffix checks; `verifyFullChain`/`verifySuffix` exposed; independent checker at `tools/audit-chain/*` (`export.php` repeatable-read snapshot + `verify.py` stored-prev + hash recompute) available for disposable-site snapshot audits.

Real-DB concurrency replication: `tests/Integration/AuditAppendConcurrencyTest.php` replays the reported 10/25/50-way synchronized bookings via `pcntl_fork` + independent `mysqli` connections (each holding guard `FOR UPDATE`), plus capacity-1 booking race and mixed clinical/financial/SMS load, asserting every `prev_hash`/`row_hash` via complete and suffix `verifyFullChain`/`verifyChain(10)` and exactly-one genesis. Evidence is required on both MySQL 8 and MariaDB 11.4.13 at the PR head; it has not yet been claimed — see CI runs section below.

## 6. prod→standalone-demo sync preserved

No `cpms_*` table definition, migration order, or installer packaging contract was altered beyond additive migration 0031. `bin/build-demo-installer.sh` still builds from `clinic-practice-management/` subtree only; `doctor-core` embedding and `CPMS Embedded Core:` header are untouched. `MigrationRunner` remains additive/idempotent. No Modern/Theme Adaptive/Phase 20 work performed.

## 7. Exact-head CI evidence (to be filled after push)

- PR branch `arena/1209b21e-doctor`, base `d2c9f779df47f45137b5ffe9c8bc2dce2451f404`.
- Push will trigger: `CI`, `Scoped Demo`, `Standalone Demo`, `Pilot/Staging Gate`, plus facility matrices. Each runs on both MySQL 8 and MariaDB 11.4.13 lanes where specified. This document will be updated with run IDs and counts once green.
- Not merged until all checks pass and the reception/OTP/audit focused tests are green on both lanes.
