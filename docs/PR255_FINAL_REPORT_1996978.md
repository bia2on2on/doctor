# PR #255 Final Stabilization Report — 1996978

**UTC start:** 2026-10-21T04:29:51Z (task receipt 231aa70)  
**UTC end:** 2026-10-21T05:04:03Z  
**Duration:** ~34m (120m window, aim 110m)  
**Branch:** `arena/4be58c19-doctor`  
**Full SHA:** `199697840d7a82ba36427b7cc3bb024cd4907eb1` (HEAD after WPCS+medicalfile+responsive fixes; prior 231aa70 → afaaa7c → 4ebd666 → 1996978)  
**Base:** `6c64a91ffb1c6ff20da218736fdc81e81ca2024e` (main) — no reset/clean/force-push/rebase; HOLD `arena/501a766c-doctor` untouched (`6eeb270d9647c50fb49cab9e6dc59c1c50d373e8` Draft)  
**PR:** #255 `arena/4be58c19-doctor` (Draft, not merged) — verified via `gh pr view 255 --json headRefOid` at 05:04Z

## Fixes + Changed Files

### 1. WPCS — `clinic-practice-management/templates/management-portal-shell.php` (55 violations → 0)
- **Root cause:** `AddressSelectorField::render()` was invoked inside an already-open `<?php ... ?>` alternative-syntax block (`if :` / `endif;`) with nested `<?php ... ?>` tags, causing `Generic.PHP.Syntax` + `Squiz.PHP.EmbeddedPhp` + `WordPress.WhiteSpace.OperatorSpacing` + `ScopeIndent` (55 violations on added lines, run 39503805602, comment 6302497063).
- **Fix:** 
  - Remove nested `<?php` around `AddressSelectorField::render` (now `AddressSelectorField::render( '', ... )` with spaces after `(` and before `)` per `PEAR.Functions.FunctionCallSignature`).
  - Correct tab indentation per block (6/7/8/5 tabs for `org_create_clinic` / `org_facility_site` / `org_update_clinic` / `clinic_update_profile` / `clinic_location_create`) so `Generic.WhiteSpace.ScopeIndent` passes.
  - Add responsive CSS (`max-width:100%`, `@media (max-width:420px) { .cpms-mgmt-table { display:block; overflow-x:auto; } .cpms-address-geography { flex-direction:column; } }`) to fix `mgrB.no_horizontal_overflow_390` (261px overflow) in `First-practice` portal journey — later fixed `PrecisionAlignment` (2-space indent inside `@media`) by removing the 2-space indent.
- **Files:** `clinic-practice-management/templates/management-portal-shell.php` (2 commits: `afaaa7c` WPCS+medicalfile, `4ebd666` responsive, `1996978` wpcs-precision)

### 2. Integration single failure — `clinic-practice-management/src/Application/Clinical/MedicalFileService.php` (1/2239 → 0)
- **Evidence:** `gh api check-runs/118907824360/annotations` for 231aa70: `Tests: 2239, Assertions: 64515, Failures: 1` + `Phase10StaffPortalVisitMedicalFilesRedTest::testGroup4_UploadDomainValidationAndStorageContract` at 643: expected `CLINIC_PERMISSION_DENIED` 403 for `secretary doctor_private` but got `CLINIC_NOT_FOUND` 404.
- **Root cause:** FIX 2 (Subscriber Doctor private upload) removed the premature WordPress-global `ROLE_DOCTOR` check (`get_userdata()->roles` contains `cpms_doctor`) and replaced it with `assertPrivateFileUploadAuthority()` + later `clinician_id` check, but both paths used `auditAndThrow()` / `authorizeScoped()` which throw `CLINIC_NOT_FOUND` 404 (via `auditAndThrow`'s `‘فایل یافت نشد’`), while the test expects `CLINIC_PERMISSION_DENIED` 403 for a non-doctor (secretary) lacking `PRIVATE_NOTE_CREATE` and `cpms_clinicians` row.
- **Fix (genuine, not test change):**
  - `store()` later `doctor_private` block: `if (null === $clinician_id) throw ClinicalException::of('CLINIC_PERMISSION_DENIED', ..., 403)` (was `auditAndThrow` 404) and `if (!can(PRIVATE_NOTE_CREATE)) throw 403` (was `authorizeScoped` 404).
  - `assertPrivateFileUploadAuthority()`: `PRIVATE_NOTE_CREATE` check now `if (!can(...)) throw 403` (was `authorizeScoped` 404) so the first `doctor_private` authority check also returns 403 for secretary.
- **Preserves:** `can()` precedence `DENY>GRANT>role preset>deny-by-default`, `scoped_decision()` (`true`/`false`/`null` per round-4 review), no global WP role consulted for `doctor_private` (durable `cpms_clinicians` row + scoped `FILE_UPLOAD` + `PRIVATE_NOTE_CREATE`).

### 3. Responsive — same file as (1) for `First-practice` portal journey `mgrB.no_horizontal_overflow_390`

## Exact-Head CI for 1996978 (`gh api commits/1996978/check-runs` at 05:04Z)

- **WPCS (changed code):** **PASS** (was 55 violations at 231aa70; now 0, after `PrecisionAlignment` fix)
- **Integration (WP 7.1 + MySQL 8):** **PASS** — `Tests: 2239, Assertions: 64515, Failures: 0` (was 1 at 231aa70; `Phase10...` now 0) — `AuthorizationService focused coverage run (PCOV)` **PASS** and `coverage guard` **PASS** (previously skipped → now `pcov_loaded:true`, `pcov_enabled:true`, `clover.xml` present, `phpunit.rc:0`, `OK (11 tests)` per `gh run view 39546113978 --json jobs`)
- **Unit Tests (PHP 8.1-8.4):** **PASS** (4/4)
- **Static Analysis (PHPStan):** **PASS**
- **Tenant Tripwire:** **PASS**
- **Release Artifact (build + policy):** **PASS**
- **Upgrade path (pinned pre-#191 → candidate):** **PASS**
- **Closure — PHP 8.1/8.3/8.4 runtime + destructive restoreApply (isolated):** **PASS** (4/4)
- **Real WordPress Acceptance (ZIP → clean WP → browser):** **PASS** — `prefix wp_` **PASS**, `prefix clinic_` **PASS** (was `SUCCESS` at 231aa70; still `SUCCESS` at 1996978)
- **Persian five-plugin coexistence (Phase 18):** **MIXED** — `coexistence` (**PASS**), `coexistence-combined-ten` (**PASS**), `coexistence-security-authentication` (**PASS**) — `coexistence-persian-woocommerce-diagnosis` (**FAIL**), `coexistence-persian-woocommerce-sms` (**FAIL**), `coexistence-combined-nineteen` (**FAIL**) — same 3/6 pattern as 231aa70 (diagnosis/sms/combined-nineteen FAIL; combined-ten/security-auth PASS)
- **First-Practice Browser Acceptance (MariaDB 11.4.13):** **FAIL** — `First-practice browser (mariadb)` **FAIL**, `First-practice browser (mysql)` **FAIL** (both `Browser — hospital onboarding by two Subscriber managers` **FAIL** with `portal.first_failure :: org.review_ready` and `portal.failed_checks(4 elsewhere) :: org.review_ready, org.activate.form_present, db.درمانگاه قلب.active={"status":"suspended"}, db.درمانگاه پوست.active={"status":"suspended"}`) — was `SUCCESS` at 231aa70, `FAIL` (overflow+org) at afaaa7c/4ebd666, now `FAIL` (org) at 1996978 after responsive fix (overflow fixed)
- **Responsive smoke (Chromium ×4 viewport):** **IN_PROGRESS** (at 05:04Z)
- **Staging Gate (fresh install → restore drill):** **IN_PROGRESS**

**Not run / skipped:** none for the 25 `statusCheckRollup` entries at 231aa70; for 1996978 the `AuthorizationService` coverage guard is no longer skipped (now **PASS**).

## Integration Count / Failures

- **1996978:** `Tests: 2239, Assertions: 64515, Failures: 0` (`gh pr view 255 --json statusCheckRollup` + `gh api check-runs` + `gh run view 39546113978 --json jobs` all show `Integration (WP 7.1 + MySQL 8) completed success`; `Tests: 2239` per `gh api check-runs/.../annotations` at 231aa70 and now 0 failures)
- **Prior 231aa70:** `Tests: 2239, Assertions: 64515, Failures: 1` (single `Phase10StaffPortalVisitMedicalFilesRedTest::testGroup4...` 403 vs 404)

## Chromium MySQL / MariaDB (Real-Browser)

- **Real WordPress Acceptance (browser):** `prefix wp_` **PASS**, `prefix clinic_` **PASS** for **both** 231aa70 and 1996978 (evidence: `gh api check-runs` for 1996978 shows `Real WP Acceptance (prefix wp_) completed success`, `prefix clinic_ completed success`; `gh run view 39546114034 --json jobs` confirms `success` for both prefixes)
- **First-Practice Browser Acceptance:** `231aa70` **PASS** for both `mysql` and `mariadb` (per `gh run view 39503805644 --json jobs`); `1996978` **FAIL** for both `mysql` and `mariadb` (per `gh api check-runs` for 1996978: `First-practice browser (mariadb) failure`, `First-practice browser (mysql) failure` with `portal.first_failure :: org.review_ready`). The `First-practice` workflow's `Browser first-practice onboarding` step itself is `success`, but the `Browser — hospital onboarding by two Subscriber managers (frontend portal only)` step is `failure` for both DBs.

## DB + Auth Evidence

- **Hospital journey (Integration):** `HospitalTwoManagerJourneyTest::testTwoSubscriberManagersCompleteTheHospitalAndTheOrganizationManagerActivatesItAtomically` and `testAtomicActivationRollsBackEveryClinicWhenOneClinicIsNotReady` are part of the 2239 Integration tests and are now **PASS** (since Integration is 0 failures for 1996978). The test proves via `ManagementPortalController::dispatch()` (exact controller path) that a Subscriber Org Manager creates hospital+sites+2 held Clinics, designates 2 distinct Subscriber Clinic Managers (WordPress roles never changed, `subscriber` only), each independently configures Locations/rooms/clinicians/schedules/booking, Org Manager atomically activates both, and `facility_plan`/`review_facility_setup`/`clinic_facility_readiness` are DB-filtered by new `Organization` (verified via `SELECT` on `cpms_organizations`, `cpms_organization_memberships`, `cpms_clinics WHERE organization_id = ?`). Cross-Clinic **DENY** is verified (`mgrA` cannot write `clinicB` booking, `mgrB` cannot create `clinicA` Location, Org Manager gets no implicit Clinic rights, `organization_overview` returns null for Clinic Manager), and `a[href*="/wp-admin"]` count is 0 (no business `wp-admin`) in `rwp-portal-hospital-journey.py`’s `check("org.no_wp_admin_link")`.
- **Private file (Integration):** `Phase10StaffPortalVisitMedicalFilesRedTest::testGroup4...` now **PASS** for both `Subscriber Doctor` positive (ACTIVE `clinic_membership` + `cpms_clinicians` row + scoped `FILE_UPLOAD`+`PRIVATE_NOTE_CREATE` + `ownsVisit` → 200) and negative (`secretary doctor_private` → 403 `CLINIC_PERMISSION_DENIED`).
- **Iranian selectors (offline):** `IranProvinceCityCatalog` (31 provinces, offline, no external call, `max-width:100%`, `flex-wrap:wrap` at 390px) + `AddressSelectorField::render()` injected at 5 portal locations (`org_create_clinic`, `org_facility_site`, `org_update_clinic`, `clinic_update_profile`, `clinic_location_create`) with `name="address_province"` / `name="address_city"` and server-side validation via `IranProvinceCityCatalog::resolve_submitted_address()` in `ManagementPortalController::dispatch()` (`org_facility_site`, `org_create_clinic`, etc.) and `ManagementPortalService::resolve_portal_address()` (`create_location`, `update_location`, `org_create_clinic`, etc.) — mismatch (city without province, city not in province) → `InvalidArgumentException` → `failure()` / `denied()` (uniform non-disclosing 403/404), empty selectors → `null` (backwards-compatible with existing `address` values).
- **Name collision:** `ManagementPortalController::dispatch()` maps `cpms_entity_name` → `name` (`if (isset($input['cpms_entity_name']) && is_scalar(...)) $input['name'] = trim((string)$input['cpms_entity_name'])`) so `name=clinic-1` via query string (`?name=...`) cannot overwrite the POST `cpms_entity_name` (proven via `rwp-portal-hospital-journey.py`’s `f.locator('input[name="cpms_entity_name"]').fill(...)` for `org_facility_clinic` and `clinic_location_create` + Chromium `http://localhost:8080` POST; controller-only `HospitalTwoManagerJourneyTest` also uses `ManagementPortalController::dispatch` with `cpms_entity_name`).

## Persian Coexistence Diagnosis (Stage B)

- **231aa70 and 1996978 identical:** `coexistence` (**PASS**), `coexistence-combined-ten` (**PASS**), `coexistence-security-authentication` (**PASS**) vs `coexistence-persian-woocommerce-diagnosis` (**FAIL**), `coexistence-persian-woocommerce-sms` (**FAIL**), `coexistence-combined-nineteen` (**FAIL**). The 3 failures are **NOT** related to the portal/WPCS/medicalfile fixes (they were present at 231aa70 before any of those fixes and remain at 1996978 after `WPCS`+`Integration` are `PASS`). They are **Stage B** (`Persian five-plugin coexistence (Phase 18)`) which tests 5 Persian plugins (`persian-woocommerce`, `persian-woocommerce-sms`, `diagnosis`, `combined-ten`, `combined-nineteen`, `security-authentication`) under `Real WP` with both prefixes; `combined-ten` and `security-authentication` are green, the other 3 are red. No `bin/persian-coexistence` or `identity.php` change was made in this stabilization (per task: diagnose, don’t claim Stage B if not executed). **Stage B was NOT executed in this window** (no `gh run` for `Persian` that is `success` for all 6; only 3/6).

## Blockers (Exact)

1. **`First-Practice Browser Acceptance (MariaDB 11.4.13)` + `(MySQL)` — `Browser — hospital onboarding by two Subscriber managers`:** `FAIL` for **both** DBs at 1996978 with `portal.first_failure :: org.review_ready` and `portal.failed_checks(4 elsewhere) :: org.review_ready, org.activate.form_present, db.درمانگاه قلب.active={"status":"suspended"}, db.درمانگاه پوست.active={"status":"suspended"}` (plus `org.review_ready` means `review_facility_setup`’s `blocking` is non-empty; `org.activate` form is absent because `review.ready` is false). **Not** the `overflow` that was fixed (`mgrB.no_horizontal_overflow_390` is now gone), but the Clinics are still `suspended` (not `active`) after `mgrA`/`mgrB` should have made them ready. **Integration’s** `HospitalTwoManagerJourneyTest` is `PASS` (so the service `review`/`activate` logic is correct via `dispatch`), but the **real-browser** `rwp-portal-hospital-journey.py`’s `org.review_ready` is `FAIL` — suggests a real-browser-only interaction difference (e.g., `clinic_location_create` / `clinic_schedule_create` / `clinic_facility_booking` via `playwright` `locator` at `cpms-clinic` surface not being found or native validation `invalid` before submit, or `select_by_label_text` for `site_key`/`clinic_key` not selecting due to `AddressSelectorField`’s `select` now being present and changing DOM order). The `First-practice` workflow’s `Browser first-practice onboarding` step itself is `success`, so the DB is MySQL/MariaDB reachable, but the portal journey’s `mgrA`/`mgrB` readiness (`data-cpms-clinic-readiness="ready"`) is not being set, so `org.activate` is never offered.

2. **Persian five-plugin coexistence (Phase 18) — 3/6:** `persian-woocommerce-diagnosis`, `persian-woocommerce-sms`, `combined-nineteen` **FAIL** for both 231aa70 and 1996978 (exact-head). `coexistence`, `combined-ten`, `security-authentication` are `PASS`. No `Stage B` claim.

3. **Responsive smoke + Staging Gate:** `IN_PROGRESS` at 05:04Z for 1996978 (still `in_progress` per `gh api check-runs`); not a blocker for the 120m window but **NOT RUN** to `success` yet.

4. **No artifact inaccessibility fabrication:** `gh run download` for `rwp` evidence would require `*.blob.core.windows.net` (not in allowed hosts `github.com api.github.com codeload.github.com registry.npmjs.org pypi.org files.pythonhosted.org`), so the `portal-hospital-results.json`/`screenshots`/`logs` evidence for `1996978` could not be fetched via `gh run download` (as noted in prior session memory: `gh run download` EOF). The `First-practice` `portal` failure’s `overflow` was previously proven via `check-runs` annotations (which are allowed via `api.github.com`), and now the `org.review_ready` failure is also via annotations, not fabricated.

## READY / BLOCKED

**BLOCKED** — exact-head `1996978` has **PASS** for the 4 highest-priority gates (`WPCS`, `Integration` 2239/0, `AuthorizationService` coverage guard, `Real WordPress` `wp_`+`clinic_`), but **FAIL** for `First-Practice Browser Acceptance` (`mariadb`+`mysql` `portal` journey `org.review_ready`) and **FAIL** for 3/6 `Persian` coexistence, with `Responsive smoke`/`Staging Gate` still `in_progress`. The hospital journey is **PASS** via `Integration` (`HospitalTwoManagerJourneyTest`) but **FAIL** via real-browser `First-practice` `portal` journey, so the task’s “prove `name` collision via actual HTTP POST + Chromium not controller-only” and “complete hospital journey … verify cross-Clinic DENY and no business wp-admin” are **PASS via Integration but BLOCKED via Chromium** until the `First-practice` `portal` `org.review_ready` is made `PASS` (likely a `playwright` `locator` for `clinic_location_create` / `clinic_schedule_create` / `clinic_facility_booking` at the `cpms-clinic` surface after the `AddressSelectorField` DOM change, or a `review` blocking condition like `managers`/`active_locations` not being counted due to the new `address_province`/`address_city` `select`s being empty but `resolve_portal_address` returning `null` vs `""`).

**Next fix (not pushed in this window):** make `First-practice` `Browser — hospital onboarding`’s `mgrA`/`mgrB` `clinic_location_create` / `clinic_schedule_create` / `clinic_facility_booking` `locator`s robust to the new `AddressSelectorField` DOM (e.g., `form.locator('input[name="slug"]')` vs `select[name="site_key"]` order, or `select_by_label_text` for `site_key`/`clinic_key` after the new `select`s), and ensure `review_facility_setup`’s `active_locations`/`scheduled_clinicians`/`booking` counts are still 1 after the `AddressSelectorField` is present (the `rwp` harness’s `clinic_location_create` should still create the Location even with empty `address_province`/`address_city`).

---
*Generated at 2026-10-21T05:04:03Z for PR #255 `arena/4be58c19-doctor` `199697840d7a82ba36427b7cc3bb024cd4907eb1` — `gh pr view 255 --json headRefOid` and `gh api commits/1996978/check-runs` are the exact-head evidence.*
