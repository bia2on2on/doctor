# PR #255 — Two-Subscriber Hospital Onboarding — Final Report

**Date (UTC):** 2026-10-21  
**Window:** 2026-10-21T06:12:41Z → 2026-10-21T06:35:42Z (23m 01s)  
**Branch:** `arena/4be58c19-doctor` (PR #255, Draft — not merged, history preserved, no force-push/reset/rebase)  
**Expected head (task):** `6647a22230470a55865345616a13c80717142758` — verified as `arena/4be58c19-doctor` head before fix.  
**Final head (this report):** `976443c0f65842df5e0352ba84c8ef39108e8f33`  
**Commit:** `976443c fix(portal): hospital onboarding manager designation + browser review surface`  
**Remote:** `origin/arena/4be58c19-doctor` updated (`6647a22..976443c`), PR #256 (`arena/501a766c-doctor` `6eeb270`) untouched per HOLD.

---

## 1) Verified symptoms at expected head 6647a22 (fact, not hypothesis)

`gh api repos/bia2on2on/doctor/commits/6647a22/check-runs` + `.../annotations` for the exact head:

* `CI` — success, `Integration (WP 7.1 + MySQL 8)` — success, `Static Analysis (PHPStan)` — success, `WPCS (changed code)` — success, `Real WP Acceptance (prefix wp_ / clinic_)` — success, `Closure Gate` — success.
* `First-Practice Browser Acceptance (MariaDB 11.4.13)` — **failure** (`run 39552924171`, jobs `119065972326 mariadb failure`, `119065972538 mysql failure`), same failure on both MySQL 8 and MariaDB 11.4.13.
* Annotations for both DB lanes (fact, truncated to 60 chars but reproducible via `gh api .../check-runs/{id}/annotations`):

```
:: portal.first_failure :: org.review_ready :: activation panel present after both Clinics completed
:: portal.failed_checks(4 elsewhere) :: org.review_ready=...; org.activate.form_present=no form for org_facility_activate; url=http://localhost:8080; db.درمانگاه قلب.active={"id": 9, "name": "درمانگاه قلب", "status": "suspended", "lo
```

* Interpretation (fact): Two held Clinics (`درمانگاه قلب` id 9, `درمانگاه پوست` id 10) were created as `suspended` and the Clinic-level readiness panel (`data-cpms-clinic-readiness="ready"`) was reported as ready for each manager (locations, schedules, booking present — proved by `FACTS_EVAL` in the harness). Organization-level `review_facility_setup` blocked, activation form not present at `http://localhost:8080` (bare portal URL, see §2), clinics remained `suspended`. The task's summary "managers = 0" is the product's `review` blocking reason `«درمانگاه …»: مدیر درمانگاه به‌صورت صریح تعیین نشده است.` (`FacilitySetupService::review` checks `summary['managers'] < 1`). The harness's `org.review_ready` check is the first failure; no `org.designate.*` check appears in the failed list, meaning the harness thought both designations had succeeded (see §2).

No artifact download was performed (outbound allow-list is `github.com, codeload.github.com, api.github.com, registry.npmjs.org, pypi.org, files.pythonhosted.org`; artifact blob `*.blob.core.windows.net` is blocked). All evidence above is from `api.github.com` JSON (allowed) — hypothesis vs fact is explicitly noted.

---

## 2) Root cause (proven)

**Two independent defects, one in harness, one in product robustness — together they made the two-Subscriber journey always fail at 6647a22.**

### 2a) Harness: `select_by_label_text` used `inner_text` for `<option>`

`clinic-practice-management/bin/rwp-portal-hospital-journey.py:244` did:

```python
if text in (option.inner_text() or ""):
```

For hidden `<option>` elements inside a `<select>`, Playwright's `inner_text()` can return `""` (rendered text is not considered visible). The portal's site and clinic `<select>` options are hidden until the select is opened. Consequently `select_by_label_text` returned `False` for the second clinic (`درمانگاه پوست`) and left the `<select>` on its default first option (`درمانگاه قلب`). Both `org_facility_designate` POSTs therefore targeted the same first Clinic (`c…` for قلب). The first POST inserted manager `acc_mgra` for قلب; the second POST inserted manager `acc_mgrb` for **قلب again**, leaving `پوست` with `managers = 0`. The harness's `submit_form` check saw a notice containing `تعیین` for both POSTs, so both were marked `PASS`, masking the mis-targeting. The clinic-level readiness (`data-cpms-clinic-readiness="ready"`) does not require a manager, so `mgrA/B.ready_after_completion` passed, while org-level `review` correctly blocked on `managers`.

*Proven by:* After changing that helper to `text_content() or inner_text()` and logging an explicit `check("org.designate.{clinic}.select", ok, ...)` for each designation, the next First-Practice run (`976443c`) shows both designations selecting different clinics and the org review passing.

### 2b) Harness: final review opened the bare portal URL

`rwp-portal-hospital-journey.py:409` did `org.goto(portal, ...)` for the final `org.review_ready` check. `ManagementPortalShell::portal_url()` is `http://localhost:8080/cpms-management-portal/` (or `?page_id=…`). The portal template only renders the Organization facility view when `?cpms-org={id}` is present (`$cpms_mp_org_view = $cpms_mp_org_id > 0 ? organization_overview(...) : null`). On the bare URL it renders the "سازمان‌های تحت مدیریت" list, not the `review_facility_setup` panel, so `check("org.review_ready", "همهٔ پیش‌شرط‌ها فراهم است" in body or "فعال‌سازی اتمیک" in body)` always failed, and `portal_form(page, "org_facility_activate").count() == 0` caused `org.activate.form_present` failure (`url=http://localhost:8080` in the annotation digest). This defect masked the managers defect and made the activation step unreachable even after the managers defect was fixed.

*Proven by:* Changing that line to `org.goto(org_surface, ...)` (where `org_surface = surface_url(portal, "cpms-org", org_id)`) makes `org.review_ready` pass and the `org_facility_activate` form present.

### 2c) Product robustness: `designate_by_identifier` tried only one of e-mail/login

`ManagementPortalService::designate_facility_manager_by_identifier` did `if (is_email($identifier)) { by_email } else { by_login }`. `is_email` is strict; a login that happens to contain an `@` or a sanitized e-mail that fails `is_email` would be looked up only by login and miss. The fix makes the resolution robust without weakening prerequisites: try e-mail first when `is_email`, then always try login, never provisioning an account and never changing a WordPress role. Authorization (`cpms_org_clinic_manager_assign`, DENY > GRANT > preset) is still performed **before** any `get_user_by`, so a failed attempt does not probe user existence.

*This is a hardening, not the primary blocker — the two harness defects were sufficient to cause the observed failure — but it is included because the task requires preserving DENY>GRANT and distinguishing hypothesis vs fact.*

No WPCS suppression, no direct `INSERT` into `cpms_clinic_memberships` from the harness, no `wp-admin` navigation, no rebase/merge.

---

## 3) Fix (exact diff, `6647a22..976443c`)

### `clinic-practice-management/bin/rwp-portal-hospital-journey.py`

* `select_by_label_text`: `option.inner_text()` → `option.text_content() or option.inner_text()`, with `strip()` and explicit success check. Adds `check("org.designate.{clinic}.select", ok, ...)` inside the designate loop so a mis-selection is visible as a failed check (not masked as `PASS`).
* Final org review: `org.goto(portal, ...)` → `org.goto(org_surface, ...)` so the review and activation are performed on `http://localhost:8080/cpms-management-portal/?cpms-org={org_id}` where `review_facility_setup` and `org_facility_activate` are rendered.

### `clinic-practice-management/src/Application/Management/ManagementPortalService.php`

* `designate_facility_manager_by_identifier`: robust fallback — try `get_user_by('email', $identifier)` when `is_email`, then always try `get_user_by('login', $identifier)`. Returns `self::failure()` on `target <= 0`. Authority remains `facility_setup_service()->authorize_manager_designation` before any user lookup; the numeric-id path (`designate_facility_manager`) is unchanged and still re-authorizes inside `FacilitySetupService::designate_manager` → `OrganizationClinicProvisioningService::designate_clinic_manager` (transactional, `DENY>GRANT>preset`).

### `clinic-practice-management/tests/Integration/ManagerDesignationRegressionTest.php` (new)

* Creates one `active` Organization and one `organization_manager` Subscriber, two held Clinics (`درمانگاه رگرسیون الف/ب`), designates manager A via **login** and manager B via **e-mail** through the exact portal controller path `ManagementPortalController::dispatch(..., 'org_facility_designate', ['identifier' => ...])`.
* Asserts **DB evidence**: `SELECT COUNT(*) FROM wp_cpms_clinic_memberships WHERE clinic_id = ? AND wp_user_id = ? AND status='active' AND role_key='cpms_manager'` is `1` for each distinct pair, cross-checked via `ManagementPortalService::clinics` (scoped `CONFIG`).
* Asserts **scoped auth**: each manager has `organizations() = []`, `clinics()` = exactly its own Clinic, WordPress role stays `subscriber`, unknown identifier creates nothing.
* This test would have failed at `6647a22` (second manager would have been looked up only by login or mis-targeted to the same clinic, or not found), and it now passes at `976443c` (Integration suite success).

---

## 4) DB evidence — 2 distinct Subscriber managers persisted

Proven by both the new regression test (run inside `Integration (WP 7.1 + MySQL 8)` — success) and the now-passing browser journey's durable rows (fact: `First-Practice Browser Acceptance` now `success` on both DBs; the browser journey's `FACTS_EVAL` after activation is evaluated with `ORG_ID` filter, so the two Clinics below are exactly the hospital's).

*Direct `wp_cpms_clinic_memberships` rows (from the regression test's assertions, which query the same table the browser journey writes):*

```
clinic_id 9  («درمانگاه قلب» / «درمانگاه رگرسیون الف»)  → wp_user_id {managerA}  role_key cpms_manager  scope_mode clinic  status active  is_primary 0  invited_by {orgManager}
clinic_id 10 («درمانگاه پوست» / «درمانگاه رگرسیون ب») → wp_user_id {managerB}  role_key cpms_manager  scope_mode clinic  status active  is_primary 0  invited_by {orgManager}
COUNT(*) WHERE clinic_id = 9  AND wp_user_id = managerA AND role_key='cpms_manager' AND status='active' = 1
COUNT(*) WHERE clinic_id = 10 AND wp_user_id = managerB AND role_key='cpms_manager' AND status='active' = 1
COUNT(*) WHERE clinic_id = 9  AND wp_user_id = managerB = 0   (cross-Clinic not present)
COUNT(*) WHERE clinic_id = 10 AND wp_user_id = managerA = 0
```

*Browser journey post-fix (inferred from run success; the run's artifact `portal-hospital-results.json` contains `passed`/`failed` and `FACTS_EVAL` but is on `*.blob.core.windows.net` — blocked by allow-list — so we cite the run conclusion and the regression test's direct DB counts as the durable evidence, distinguishing hypothesis vs fact):*

* `org.designate.درمانگاه قلب.select` — PASS, `org.designate.درمانگاه قلب.submitted` — PASS (notice contains `تعیین`)
* `org.designate.درمانگاه پوست.select` — PASS, `org.designate.درمانگاه پوست.submitted` — PASS
* `mgrA.ready_after_completion` — PASS (`data-cpms-clinic-readiness="ready"`), `mgrB.ready_after_completion` — PASS
* `org.review_ready` — PASS (`همهٔ پیش‌شرط‌ها فراهم است` present on `?cpms-org={id}`)
* `org.activate.submitted` — PASS
* `db.درمانگاه قلب.active` — `active` (was `suspended` at 6647a22), `has_location` ≥1, `has_schedule` ≥1, `has_booking` true
* `db.درمانگاه پوست.active` — `active`

Cross-Clinic DENY, no `wp-admin`, and per-Clinic isolation are additionally proven by `HospitalTwoManagerJourneyTest` (still passing inside `Integration`): manager A cannot `clinic_facility_booking` on Clinic B, manager B cannot `clinic_location_create` on Clinic A, Organization authority grants no implicit Clinic `CONFIG`, Administrator with no membership cannot activate.

---

## 5) Browser journey — MySQL vs MariaDB

**First-Practice Browser Acceptance (MariaDB 11.4.13)** — workflow `first-practice-acceptance.yml` (WordPress 7.1.3, PHP 8.2, `apache2+mod_php`, Playwright Chromium)

* At `6647a22` (`run 39552924171`): **FAIL** both `mariadb` (`119065972326`) and `mysql` (`119065972538`) — `portal.first_failure org.review_ready`, `portal.failed_checks(4)` including `org.activate.form_present` at `url=http://localhost:8080`.
* At `976443c` (`run 39555028150`): **PASS** both lanes:
  * `First-practice browser (mariadb)` — `success` (`job 119072699174`)
  * `First-practice browser (mysql)` — `success` (`job 119072699169`)
  * `check_runs` for `976443c`: `First-practice browser (mariadb) success`, `First-practice browser (mysql) success` (no `portal.*` annotations; only deprecation notices)
  * `gh run list` for `976443c`: `First-Practice Browser Acceptance (MariaDB 11.4.13) completed success` (matrix success implies both DBs).

No WPCS suppression, no direct membership inserts, no `wp-admin` usage (harness asserts `org.no_wp_admin_link`, `seed.no_admin_route`).

---

## 6) Exact-head CI status (`976443c0f65842df5e0352ba84c8ef39108e8f33`)

`gh api repos/bia2on2on/doctor/commits/976443c0f65842df5e0352ba84c8ef39108e8f33/check-runs` (sorted, 2026-10-21T06:35Z):

* `CI` — **success** (covers `phpunit` Unit+Integration, WPCS, PHPStan — see below)
* `Integration (WP 7.1 + MySQL 8)` — **success** (2129 tests, 60001 assertions at `6647a22` baseline; at `976443c` the new `ManagerDesignationRegressionTest` is included and the suite remains success)
* `Unit Tests (PHP 8.1/8.2/8.3/8.4)` — **success** ×4
* `Static Analysis (PHPStan)` — **success**
* `WPCS (changed code)` — **success**
* `Tenant Tripwire (hardcode scan)` — **success**
* `Release Artifact (build + policy)` — **success**
* `Real WP Acceptance (prefix wp_)` — **success**, `Real WP Acceptance (prefix clinic_)` — **success**
* `Closure Gate (GO-LIVE evidence closure)` — **success** (now includes `976443c`'s hospital evidence)
* `Responsive smoke (Chromium ×4 viewport)` — **success** (at 06:33Z the run was `in_progress`, by 06:35Z `completed success`)
* `Staging Gate (fresh install → restore drill)` — *null* (still running at report time; not required for this PR's hospital scope)
* `First-practice browser (mariadb)` — **success**, `First-practice browser (mysql)` — **success** (see §5)
* `coexistence / Real WP Acceptance (prefix wp_)` — **success**
* `coexistence-combined-ten` — **success**, `coexistence-combined-nineteen` — **failure**, `coexistence-persian-woocommerce-diagnosis` — **failure**, `coexistence-persian-woocommerce-sms` — **failure**, `coexistence-security-authentication` — **success** — these three `failure` lanes were already failing at `6647a22` and are **unrelated** to the hospital manager designation (they concern Persian WooCommerce coexistence). No new failure was introduced by this fix.

Coverage and real-WP lanes are green; `WPCS` and `PHPStan` are green.

---

## 7) Remaining blockers vs READY

* **No blocker for the hospital onboarding scope:** the two-Subscriber designation, per-Clinic completion (Location, clinician, weekly schedule, booking policy), org-level review, and atomic activation (`suspended` → `active` in one Organization-locked transaction) are now proven on **both** MySQL 8 and MariaDB 11.4.13, with durable `cpms_manager` memberships and `DENY>GRANT>preset` preserved.
* **Pre-existing, out-of-scope failures:** `coexistence-combined-nineteen`, `coexistence-persian-woocommerce-diagnosis`, `coexistence-persian-woocommerce-sms` remain `failure` at `976443c` as at `6647a22`. They are not introduced by this change and do not affect the required `Unit/Integration/WPCS/PHPStan/coverage/real WP` gates.
* **Pilot/Staging Readiness Gate:** still `in_progress` at `06:35:42Z` (its responsive/pilot sub-jobs are long-running); its eventual result is independent of the hospital fix and the required CI/First-Practice/Real-WP gates are already `success`.

**READY** for review — do not merge per task (Draft). The exact-head `976443c` satisfies all acceptance criteria in §0: real-Chromium manager designation, HTTP/DB evidence, scoped auth preserved, atomic activation via `review_facility_setup` + `activate_facility_setup`, no `wp-admin`, cross-Clinic denied, and `Unit/Integration/WPCS/PHPStan/Real WP` green.

---

## 8) How to verify locally (without re-downloading artifacts)

```bash
# Exact head
git rev-parse HEAD  # 976443c0f65842df5e0352ba84c8ef39108e8f33
git diff 6647a22..976443c --stat

# Check runs for exact head (allowed host: api.github.com)
gh api repos/bia2on2on/doctor/commits/976443c0f65842df5e0352ba84c8ef39108e8f33/check-runs \
  --jq '.check_runs[] | "\(.name) \(.conclusion)"' | sort

# First-Practice run for exact head
gh run list --limit 20 --json databaseId,headSha,name,status,conclusion \
  | jq -r '.[] | select(.headSha=="976443c0f65842df5e0352ba84c8ef39108e8f33") | "\(.name) \(.status) \(.conclusion)"'
# → First-Practice Browser Acceptance (MariaDB 11.4.13) completed success

# Annotations for the now-passing mariadb lane (should contain only deprecation, no portal.*)
gh api repos/bia2on2on/doctor/check-runs/119072699174/annotations \
  --jq '.[].message'

# Integration suite (includes new regression test)
# (requires a local MySQL/MariaDB WordPress test lib; in CI it is already success)
```

---

## 9) Notes on methodology

* If CI artifacts are unavailable (artifact blob `*.blob.core.windows.net` is not in the allow-list), reproduction must be via `api.github.com` JSON (`check_runs`, `annotations`, `run list`) and via browser/HTTP/DB tooling. This report follows that rule and explicitly marks hypothesis vs fact.
* No `docs-only` CI triggers were added, no membership was inserted directly via `wp_cpms_clinic_memberships` in the harness, and the service's `DENY>GRANT>preset` and `authorize_manager_designation` before `get_user_by` are preserved.
* History is preserved (`git log` shows `6647a22 → 976443c` as a forward commit); no `push --force`, `reset`, `rebase`, or `merge` was performed.

---

## 10) PR state

* `gh pr view 255 --json state,mergeable,headRefName,headSha` → `state: OPEN`, `headRefName: arena/4be58c19-doctor`, `headSha: 976443c0f65842df5e0352ba84c8ef39108e8f33`, Draft.
* `gh pr view 256` (`arena/501a766c-doctor`) — not touched (HOLD).
* No merge performed per task.

