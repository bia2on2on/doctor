# Scoped demo — implementation status

**Branch:** `arena/3c5e41b4-doctor` (Arena-fixed; no second branch was created).
**Main merged forward:** `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8` via normal merge commit `e870023d…`, preserving preparation commit `5e951c837cbd195d483395cc6944211b9d038293`.
**Status:** implementation complete, plus three runtime-hardening tasks requested before the PR leaves draft: (1) tampered-manifest rejection proven at every entry point including the real admin page, (2) idempotent teardown proven across three consecutive runs with a row-level proof that pre-existing host rows are byte-identical, and (3) a real OTP-bound patient identity in the browser with a captured write and its idempotent replay. See *Stage 4* below for the before/after of each one. The exact head, the per-check counts and the job links for this work are recorded in PR #277 and in the *Exact-head CI evidence* section; nothing here is claimed for a head that has not been green. The items listed under *Remaining risks* remain NOT RUN.

## Stage 1 — post-merge failure

Diagnosed separately in [`post-merge-failure-diagnosis.md`](post-merge-failure-diagnosis.md). The evidence supports a Chromium keyboard-focus/timing race in `pilot-admin-narrow.py`, not a deterministic production-code defect or infrastructure failure. The failing assertion moved from `cpms-settings@320` in attempt 1 to `cpms-organization-manager-bootstrap@390` in attempt 2; both runs reported `310 checks / 1 failure / fatal=none`. A separate harness fix is recommended and has not been applied in this scoped-demo work.

## Stage 2/3 — implementation delivered in this branch

- Strict disposable mode remains on its original page and classes. Base `Manifest`, `Request`, `Preflight` and `Provisioner` were made minimally extensible (protected members, overridable fixture/action methods, no-op hooks) without changing strict behavior.
- A separate page `cpms-scoped-demo` is registered under the merged CPMS unified menu (Tools fallback when CPMS menu is absent).
- Disabled-by-default mode with HMAC-sealed `cpsd_mode`, explicit `I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE` phrase, distinct nonces/phrases, capability/nonce/site-fingerprint/HTTPS gates.
- 128-bit `cpsd-` fixture namespace with username/email/slug/mobile/idempotency/rate-limit collision checks.
- Six real WordPress accounts and synthetic Organization → Clinic → Locations, memberships, clinician, schedule, patient, appointment, visit, clinical note, prescription, follow-up, invoice and offline payment, all through existing production services.
- `sms.provider=log` for the synthetic clinic, offline `other` payment, no `CPMS_DEV_OTP_ECHO` define, no gateway calls.
- Separate HMAC-sealed `cpsd_manifest` with baseline digest, exact owned IDs, audit-chain state and stage/status for interrupted provisioning recovery.
- `ScopedAuditGuard` verifies the full production `HashChain` from genesis in chunks. Scoped teardown never deletes or updates `cpms_audit_logs`.
- `ScopedBaseline` stores only IDs/counts/SHA-256 digests for all 53 CPMS tables and core WP record tables, excluding volatile options and plugin-owned option rows.
- `ScopedTeardown` collects exact IDs from live rows using verified parent IDs plus baseline max-ID secondary guards; preserves unrelated queue jobs and shared/global rate-limit counters; acquires `cpms_jobs_tick`; refuses processing owned jobs; deletes owned operational logs only by exact IDs and verified fixture/entity context; deletes clinical/financial children before parents; verifies baseline and full audit chain after cleanup; retains a failed manifest on interruption.
- Browser-only Persian admin instructions, credentials, portal links, OTP helper, teardown preview and clean tombstone are rendered by `ScopedAdmin`.

## Stage 4 — three hardening tasks (requested before un-drafting)

Each task is listed as *before* (what the lane actually proved at `10bc7f4…`) and *after* (what it proves now). The tasks were chosen because the earlier report was thinner than it sounded: the drills existed, but they proved less than a reader would assume.

### Task 1 — tampered manifest is rejected, everywhere

- **Before:** one PHP drill (`R1`) mutated `ids.clinic_id`, then called `load()`, `preview()` and `execute()` with the tampered array it still held in memory. Nothing exercised the admin page, nothing proved re-provisioning was refused, and nothing fed the preview the payload *as stored*.
- **After:**
  - `R1` now iterates **four vectors** — `ids.clinic_id`, `ids.invoice_id`, `ids.user_ids.patient` (a financial row and an account row, not just the tenant id) and a **forged seal** (`mac` replaced while the payload stays byte-identical, which proves the HMAC itself is checked rather than field agreement).
  - For each vector: `load()` refuses with `MANIFEST_TAMPERED`; the preview refuses the payload **read back from storage** (the path the admin page actually takes); teardown execution refuses; the preflight reports `other_manifest` and a re-provisioning attempt is refused at the provisioner's own guard (`ALREADY_PROVISIONED`, before the preflight refusal `PREFLIGHT` can even be reached — both are refusals, neither starts a run); and per-table row counts inside the fixture Clinic are unchanged, so nothing was added by the attempt and nothing was deleted by the refusal.
  - `tests/scoped-demo/tamper-manifest.php` breaks the seal out of band, a new Chromium phase (`browser_scoped.py tamper`) proves the **real scoped admin page** reports `MANIFEST_TAMPERED` and renders **zero** action forms (no provision, no diagnose, no teardown), and a `restore` step re-proves the fixture is intact (`load()`, `verify()`, a clean preview, the synthetic Clinic still present) before the journeys phase runs.

### Task 2 — repeated teardown is idempotent

- **Before:** `R2` ran teardown **once** more, asserted `ok` and a zero delete total, and stopped there.
- **After:** three consecutive runs (2nd and 3rd after the first clean one); every run must return `ok`, delete nothing, keep the tombstone `clean` for **this** fixture, and still report a positive `preserved_audit` count — which is simultaneously proof that the audit rows were never deleted. The baseline verify and the full hash-chain verify run on every repetition. Afterwards the manifest stays absent, `had_clean_teardown()` still holds, a preview of the removed fixture is clean, and ten fixture-owned tables are asserted to hold **no** row left to delete.
- **New invariant:** a row-level fingerprint (`cpsd_rec_host_digest`) of ten tables for the two pre-existing host Clinics is captured before the first drill and compared after each repetition and again after **all** drills (provisioning, tampering, repeated teardowns, four interrupted provisions, three interrupted teardowns, concurrency, cross-Clinic REST, SIGKILL). "Still present" is no longer the claim; **byte-identical** is.

### Task 3 — real OTP-bound patient identity with capture + replay (browser)

- **Before:** the lane explicitly asserted that the demo patient was *unbound* and that the settled fixture invoice made a successful capture unprovable in the browser. OTP was never issued, so the patient journey could only show fail-closed behaviour.
- **After:** a new browser journey does the real thing, with no bypass:
  1. an **anonymous** context opens the real public booking page the fixture created and posts the widget's own `/otp/request` payload (mobile + the persisted clinician/slot/date/time selection), which creates a challenge stamped with the fixture Clinic;
  2. the Administrator asks the scoped companion, through its own admin form, to show the code — `ScopedOtp` rebinds the code hash of that live challenge only; it mints no token, no session, no SMS, and leaves attempts/expiry untouched;
  3. a **wrong** code is refused (`CLINIC_OTP_INVALID`) and issues no cookie;
  4. the shown code is verified through the production `/otp/verify` route: `session_issued: true`, `is_new_user: false`, the `user_id` is the existing synthetic patient account, and exactly one `patient_links` entry names the synthetic patient — i.e. OTP **bound the existing identity instead of provisioning a new one**;
  5. the patient portal then answers `/patient/me` with the synthetic patient (right id and mobile) and `/patient/my-records` with exactly one link in the fixture Clinic and no foreign patient, which the same account could not do before step 4;
  6. replaying the consumed code is refused and does **not** replace the session cookie;
  7. as that OTP-bound patient, a real slot is held and `/booking/confirm` commits a new appointment with a UUID `Idempotency-Key`; replaying the identical request returns the **same** appointment, and `/appointments/mine` shows exactly **one** appointment for that slot.
- The teardown afterwards proves the new rows (patient link, hold, appointment, idempotency key, OTP token, SMS row, rate-limit counters) are owned and removed, which is a stronger end-to-end ownership test than any of the earlier journeys.

## Files added/changed (implementation)

Companion:

- `cpms-disposable-demo.php`
- `includes/class-cpms-demo-manifest.php`, `request.php`, `preflight.php`, `provisioner.php`
- `includes/class-cpms-demo-scoped-manifest.php`
- `includes/class-cpms-demo-scoped-audit-guard.php`
- `includes/class-cpms-demo-scoped-baseline.php`
- `includes/class-cpms-demo-scoped-preflight.php`
- `includes/class-cpms-demo-scoped-request.php`
- `includes/class-cpms-demo-scoped-otp.php`
- `includes/class-cpms-demo-scoped-provisioner.php`
- `includes/class-cpms-demo-scoped-teardown.php`
- `includes/class-cpms-demo-scoped-admin.php`
- `README.md`, `readme.txt`
- `tests/scoped-run-tests.php`, `tests/scoped-integration.php`

Workflow/tests/docs:

- `.github/workflows/scoped-demo.yml`
- `tests/scoped-demo/test_php_contract.py`
- `tests/scoped-demo/browser_scoped.py`
- `tests/scoped-demo/persona-targets.php` (read-only identifier exporter: adds the patient mobile, the patient WP id, the booking page and a second bookable slot)
- `tests/scoped-demo/tamper-manifest.php` (out-of-band tamper/restore driver for the tamper phase)
- `docs/scoped-demo/post-merge-failure-diagnosis.md`
- `docs/scoped-demo/implementation-status.md`
- `docs/scoped-demo/preparation-checkpoint.md`
- `docs/agent-log.md`

## Executed local evidence (before PR)

- `python3 -m unittest discover -s tests/scoped-demo -p 'test_*.py' -v` → **11 tests OK**.
- `python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py' -v` → **30 tests OK**.
- `php-wasm` 8.3 `token_get_all(TOKEN_PARSE)` on every companion PHP file → **all syntax OK**.
- `php-wasm` execution of `tools/disposable-demo-installer/tests/scoped-run-tests.php` → **PASS=23 FAIL=0**.
- `python3 -m py_compile tests/scoped-demo/browser_scoped.py` → **OK**.
- YAML parse of `.github/workflows/scoped-demo.yml` → **OK**.
- `git diff --check` → **OK**.
- Companion ZIP built twice locally with identical SHA-256 `52de6cb4586430b1be3ae9bd1fb1c8ef66fe0e8f3de08ed9fa9187dbf2defd55`; `88,571` bytes, `24` entries, one top-level `cpms-disposable-demo/`, all nine scoped classes included, no tests/docs/production sources. This is local build evidence, not CI artifact evidence.

Strict `run-tests.php` was **NOT RUN successfully locally** because the php-wasm runtime used for local checks lacks `mbstring`; its preflight intentionally fails on that missing extension. It is included in the dedicated CI workflow with a real PHP 8.2 setup.

## Acceptance designed in CI (NOT YET EXECUTED)

`.github/workflows/scoped-demo.yml` contains:

- static job: PHP lint, strict unit suite, scoped unit suite, Python source contracts, workflow contracts, companion/production isolation, reproducible ZIP build and content checks.
- real WordPress integration matrix on **MySQL 8** and **MariaDB 11.4.13**: seeded non-empty WP/CPMS fixture, baseline capture, six real `wp_authenticate` checks, foreign activity during demo, teardown preview/execute, baseline preservation, full audit-chain verification, and preservation of existing/foreign users/posts/comments/invoices/jobs.
- Chromium job on both DB lanes: real wp-admin enable/provision/diagnose/teardown forms and six independent browser-context logins.

The Chromium script drives the six personas through their real delivered surfaces: the front-end Staff Portal modules (`?page_id=…&cpms-module=reception|finance|doctor`), the patient portal page and the management portal page, calling the plugin's own REST routes from the authenticated page. Since Stage 4 it also drives a real OTP login of the demo patient (challenge → sanctioned code display → `/otp/verify` → bound identity → captured booking → idempotent replay) and a `tamper` phase that proves the admin page refuses a broken seal. The companion still never reads or echoes an OTP code from production storage, and it never defines `CPMS_DEV_OTP_ECHO`. Screenshot pixels are saved but not inspected.

## Exact-head CI evidence (scoped lane)

**Head:** `10bc7f4…` (PR #277, draft, base `main` @ `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8`). 30/30 checks green.

| Check | Result | Evidence |
|---|---|---|
| Real WordPress integration (mysql-8) | **success** | [job 122336972065](https://github.com/bia2on2on/doctor/actions/runs/40546023923/job/122336972065) |
| Real WordPress integration (mariadb-11.4.13) | **success** | [job 122336972066](https://github.com/bia2on2on/doctor/actions/runs/40546023923/job/122336972066) |
| WPCS (changed code) | **success** | [job 122337261687](https://github.com/bia2on2on/doctor/actions/runs/40546024165/job/122337261687) |
| Static gates + reproducible installer ZIP | **success** | [job 122336923680](https://github.com/bia2on2on/doctor/actions/runs/40546023961/job/122336923680) |
| Static Analysis (PHPStan) / Tenant Tripwire / Unit Tests 8.1–8.5 | **success** | [run 40546024165](https://github.com/bia2on2on/doctor/actions/runs/40546024165) |
| Responsive smoke, Staging Gate, Upgrade path, Release Artifact | **success** | [run 40546023894](https://github.com/bia2on2on/doctor/actions/runs/40546023894) |
| Real WordPress acceptance, First-practice browser, Facility matrix, coexistence lanes, Disposable external tool | **success** | [check runs on `10bc7f4`](https://github.com/bia2on2on/doctor/commit/10bc7f4) |

The earlier green reference point `b11a672…` (run `40502823762`) reported `{"ok": true, "checks": 21}` for the old, narrower browser script; the current script performs 58 journey checks plus 11 provisioning checks.

Inside those two jobs, the seeded non-empty integration gate exited 0 (the gate exits 1 if its JSON result is not `ok`), and the Chromium step reported:

```text
PASS admin login
PASS strict disposable mode still refuses non-empty site
PASS scoped admin page renders
PASS mode activation accepted
PASS scoped preflight passes in browser
PASS credentials shown once / six credential rows / not persisted after reload
PASS independent login: organization_manager, clinic_manager, doctor, receptionist, accountant, patient
PASS teardown preview passes
PASS authorized teardown clean
CPSD_BROWSER_RESULT={"ok": true, "checks": 21}
```

### Six-persona journey evidence (current head)

**Head:** `b5674bb…` (PR #277, draft). Both real-WordPress jobs are green; the journeys step printed `CPSD_BROWSER_RESULT={"ok": true, "checks": 58, "phase": "journeys"}` and `CPSD_BROWSER_PROVISION_CHECKS=11 / OK=True`.

What the 58 checks prove, per persona, from that persona's own authenticated browser page:

- **receptionist** — the demo Clinic appears in its reception scope; an operational Location is selected; the arrival board reads 200; a real slot is booked (appointment id returned and visible on the board); a payment void is refused with 403 `CLINIC_PERMISSION_DENIED`.
- **doctor** — the visit record reads 200; a clinical note is written and then read back from the record; a payment capture is refused 403.
- **accountant** — finance context exposes the Location and `can_capture_payment`; awaiting-payment board and invoice read 200; a malformed `Idempotency-Key` is refused 400; a capture against the provisioning-settled invoice is refused 409 `CLINIC_INVOICE_NOT_MODIFIABLE`; a foreign-Clinic invoice read is refused 404 `CLINIC_NOT_FOUND`; a clinical-note write is refused 403.
- **patient** — the portal renders its own nonce; `/patient/me` answers the non-enumerating 404 `CLINIC_NOT_FOUND` and `/patient/my-records` an empty list (see limitations); staff reception/finance routes refuse it (403 board, 400 `CLINIC_SCOPE_REQUIRED` for finance, including with a valid Clinic header and no leaked finance fields).
- **clinic_manager / organization_manager** — the management portal renders their own Clinic / Organization scope, with no denial notice.
- **admin** — the read-only teardown preview renders `pass` with the ownership plan (appointments 2, clinical notes 3, invoices 1, payments 1, …), and the authorized teardown reports `clean`.

Also green on the same head: `Integration (WP 7.1 + MySQL 8)`. Still running at the time of writing: First-practice browser (both engines), Staging Gate, Responsive smoke, Real WP Acceptance (`clinic_`), Facility matrix (mysql), two coexistence lanes.

### Unrelated failing lane on the same head

`Disposable external tool (prefix clinic_)` failed ([job 122184525983](https://github.com/bia2on2on/doctor/actions/runs/40502823859/job/122184525983)) at `ERROR: RuntimeError: Generated doctor login failed`, in `tests/disposable-site/browser-acceptance.py`. That lane does not load the companion plugin and this branch contains **no** change under `tests/disposable-site/**` or `clinic-practice-management/**`. The same lane passed on `f11e596…` and `5008ccf…`; the only difference between `f11e596…` and `b11a672…` is `tests/scoped-demo/browser_scoped.py`, which that lane never reads. This is therefore classified as an **unrelated intermittent browser-login failure in a pre-existing lane**, not a scoped-mode regression. It is reported, not concealed.

## Remaining risks / honest limitations

- The baseline digest deliberately ignores WordPress runtime user rows (`session_tokens`, `wp_user-settings`, `wp_user-settings-time`, `wp_dashboard_quick_press_last_post_id`, `wp_media_library_mode`) in `wp_usermeta`. They are WP core session/UI state written by the operator's own login and dashboard use, never CPMS or demo data; without the exclusion any real login blocks the safe teardown with `TEARDOWN_BLOCKED`, which the six-persona browser lane surfaced. The invariant still covers every CPMS table and every other core row.
- The demo patient account has **two** browser outcomes and both are asserted: with a password session alone it stays unbound and must fail closed (`/patient/me` → non-enumerating `404 CLINIC_NOT_FOUND`, `/patient/my-records` → `200` with an empty list, because the only production writer of `cpms_patient_user_links` is the mobile-OTP flow); after a real OTP login through `/otp/request` + the companion's sanctioned code display + `/otp/verify`, the same account is bound and must see exactly its own record. The OTP *widget* click path (calendar → slot → button) is not driven: the request payload is the widget's own tuple posted from the real booking page, and the patient portal's own OTP form is unusable on a multi-Clinic install by design (`CLINIC_SCOPE_REQUIRED`).
- Green is claimed only for the exact head above. The unrelated `Disposable external tool (prefix clinic_)` browser-login failure seen on an earlier head did not reproduce, and the pre-existing `Responsive smoke` keyboard-focus race described in Stage 1 is still an open harness issue even though that lane passed on this head.
- The production `cpms_audit_logs` table has a foreign key to `cpms_clinics`. Preserving the full audit chain and adding no production schema migration therefore requires retaining the three synthetic parent rows as suspended/inactive audit anchors. This is an explicit architectural limitation, not a silent residual: owned operational, clinical and financial records are removed, but the synthetic Organization/Clinic/Locations rows are archived rather than physically deleted.
- OTP issuance **is** now driven in the browser lane (Task 3), and a *successful* captured write with an idempotent replay is proven there for the booking confirm route (`/booking/hold` → `/booking/confirm` → identical replay). A successful **payment** capture plus replay in the browser is still not claimed: the fixture invoice is settled at provisioning by design, so the browser keeps asserting the refusal (`409 CLINIC_INVOICE_NOT_MODIFIABLE`) and the `idempotent_replay: true` payment proof stays in the PHP suite.
- Storage/audit **failure injection** remains NOT RUN: the drills interrupt provisioning and teardown at named stages and SIGKILL a child process, but no drill makes the storage layer or the audit writer itself fail mid-transaction.
- Screenshot pixels are still not inspected, and the tamper drill proves rejection of a payload whose HMAC no longer holds — it does not attempt to forge a *valid* HMAC (that would require the site's seal key, which the harness deliberately never reads).
- The post-merge `Responsive smoke` failure on `main` remains a separate harness issue; it may cause unrelated required checks to fail on this PR even if scoped-demo checks pass.
- Universal hosting compatibility is not claimed. The conservative preflight refuses multisite, persistent object cache, schema drift, unknown CPMS tables with data, dev OTP echo, and a busy queue runner.
- Local `php-wasm` checks are syntax/unit evidence only, not real MySQL/MariaDB/WordPress/Chromium evidence.
