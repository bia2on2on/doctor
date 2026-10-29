# Scoped demo — implementation status

**Branch:** `arena/19f8e063-doctor` (Arena-fixed; no second branch was created).
**Main merged forward:** `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8` via normal merge commit `e870023d…`, preserving preparation commit `5e951c837cbd195d483395cc6944211b9d038293`.
**Status:** implementation complete, plus three runtime-hardening tasks requested before the PR leaves draft: (1) tampered-manifest rejection proven at every entry point including the real admin page, (2) idempotent teardown proven across three consecutive runs with a row-level proof that pre-existing host rows are byte-identical, and (3) a real OTP-bound patient identity in the browser with a captured write and its idempotent replay. See *Stage 4* below for the before/after of each one. The exact head, the per-check counts and the job links for this work are recorded in PR #277 and in the *Exact-head CI evidence* section; nothing here is claimed for a head that has not been green. The items listed under *Remaining risks* remain NOT RUN.

## Mission E — provable ownership of additional Clinics

**Defect.** `owned_tenants()` claimed every `cpms_clinics` row that named the demo Organization **and** had an `id` above the sealed baseline `max_id`. A genuine non-demo Clinic that a real Organization manager created under the demo Organization after the baseline was captured satisfies exactly those conditions, so it could have been deleted, or suspended and stripped of its synthetic actors, as if it were demo data.

**Policy (implemented, code head `2215060`).** A Clinic is demo-owned when, and only when, sealed evidence names it: the sealed manifest names it as the fixture Clinic, the sealed append-only ownership ledger (`cpsd_ownership_ledger`) names its exact id, or a sealed ledger `intent` pre-committed its exact slug **before** the row could exist. Organization reference, auto-increment id, creation timestamp and synthetic actor ids are never ownership. Everything else under the demo Organization is reported as `SCOPED_OWNERSHIP_UNPROVEN` and the teardown refuses **before any destructive stage** — in the preview and in the execution, from the same single decision in `collect_scope()`.

**Ledger.** `ScopedOwnership` seals each record with the manifest HMAC key bound to this site's fingerprint and this fixture id, chains records by `seq`/`prev`/`mac`, and commits `count` and `head` inside the record's own seal, so editing, inserting, reordering, extending or **truncating** the chain all fail verification (`SCOPED_OWNERSHIP_LEDGER_INVALID`, zero deletions). An absent ledger claims nothing. A record that does not read back identically after the write is refused (`SCOPED_OWNERSHIP_LEDGER_WRITE`) — ownership that was not durably stored is not ownership.

**Portal policy (server-side, twice).** In scoped demo mode the Management Portal may not create Clinics: (1) the synthetic Organization manager's membership carries an explicit `DENY` for the production `cpms_org_clinic_create`, written by the provisioner and read back through the production resolver (`can()`), so the portal does not render the create form and a direct service call is refused by production code — `cpms_org_clinic_update` and `cpms_org_clinic_lifecycle` are untouched, so the lifecycle journeys keep working; (2) `ScopedAdmin::guard_portal_clinic_creation()` refuses any POST of `cpms_action=org_create_clinic` on `template_redirect` priority 0, ahead of the portal handler, with a Persian explanation and HTTP 403. Both are inert when scoped mode is off. There is no UI-only restriction: hiding the form is a consequence of the production resolver refusing, not the mechanism.

**The only path to an additional demo Clinic.** `ScopedClinics::create()` (rendered on the scoped demo page, `manage_options` + nonce + typed confirmation) seals the exact slug as an `intent`, creates the Clinic through the **real** production provisioning service on behalf of the authenticated administrator designated as an Organization manager, and seals the resulting id and Locations back. An interrupted run leaves a pending intent; the teardown refuses with `SCOPED_OWNERSHIP_PENDING` (reported before the unproven set it explains), and an administrator either registers the live row that matches the sealed Organization **and** slug, or abandons the intent after proving no row carries that slug. The intent is never guessed at.

**Drills (`tests/scoped-portal.php`, P1–P7, real MySQL 8 + MariaDB 11.4 in CI).** P1 portal policy + lifecycle; P2 a registered additional Clinic is claimed, cleaned with its dependents, retained as an inert anchor, and the ledger is discarded only after a proven-clean teardown; P3 a pre-baseline unproven Clinic is refused; **P4 a foreign Clinic created after the baseline under the demo Organization is refused and survives byte-identical**; **P5 overlapping synthetic user references are never ownership**; **P6 an edited or truncated ledger is rejected**; **P7 both interruption points are recoverable, preview equals execution, and claimed Clinics end suspended**. The browser journey proves the create form is absent for the manager **and** that a crafted POST is refused with the Persian text, and drives the demo page's registered creation before the teardown.

**Option lifecycle.** `cpsd_ownership_ledger` is enumerated in `ScopedBaseline::COMPANION_OPTIONS` (never prefix-matched), is discarded by the teardown only after the tombstone is durable and the pending baseline is gone, and is treated by `uninstall.php` like the manifest and the pending baseline: while it exists it is recovery evidence and blocks the uninstall instead of being deleted behind an unresolved fixture.

## Mission F — real-user data inside demo tenants

**Defect.** Ownership was decided by the *tenant boundary*: a post-baseline row naming the demo Clinic was demo-owned. A real patient can book into the demo Clinic through the public booking page, and a real member of staff can be given access to it; both create rows that satisfy exactly that predicate and nothing else, so the teardown would have deleted them.

**Stage 1 — positive attribution, fail closed.** Every candidate row must be explained by a positive fixture artifact (`ScopedTeardown::ATTRIBUTION` + the sealed manifest's ids + the sealed ledger): an attributable patient/visit/thread, the sealed mobile, one of the fixture's synthetic accounts, the fixture id, an explicit ledger registration, or — for pure Clinic configuration — the tenant boundary, which is itself a positive claim only because a real actor cannot write into a demo tenant (below). Any row that matches none of these, **including every row naming a real WordPress user** other than the fixture accounts and the administrator who created the demo, refuses the teardown before any destructive stage (`SCOPED_FOREIGN_TENANT_ROW`, plus a per-table total).

**Explicit registration of demo-created rows.** A persona's front-desk data cannot be proven by a structural rule, so it is registered: `ScopedOwnership::register_rows()` seals a `rows` record, written either by a demo workflow (the extra-Clinic Organization membership) or by `ScopedTenantGuard`, which snapshots the demo tenant before every state-changing request by a synthetic fixture account and seals exactly the rows that appeared.

**Stage 2 — server-side tenant write barrier.** While a fixture is active, a state-changing REST/portal request that targets the demo tenant is refused (403, `SCOPED_DEMO_TENANT_WRITE_REFUSED`, Persian message) unless the actor is a fixture account or a site administrator. Anonymous public requests stay allowed — that is the demo's public journey — and what they create for a real patient is refused by the attribution gate. Inert outside scoped mode, so production behaviour is untouched.

**Stage 2 — the portal refusal is scoped to the fixture.** `guard_portal_clinic_creation()` now requires all three: scoped mode on, a verified **active** manifest, the POST naming the demo Organization, and the actor **being** the fixture's synthetic Organization manager. A legitimate manager of a foreign Organization keeps normal clinic-creation behaviour during a demo.

**Stage 3 — the ledger cannot disappear silently.** `cpsd_ownership_ledger` is mandatory for a sealed active fixture; a monotonic companion record `cpsd_ownership_seal` commits its `count`/`head` after every append and is verified before every append and every teardown. Deletion, truncation, rollback to an older sealed copy, and deletion of the seal are all refused with zero deletions; interrupted registrations remain repairable through the pending intent and `ScopedClinics::resolve()`.

**Drills.** `tests/scoped-portal.php` P8 proves a real patient's post-baseline booking inside the demo Clinic is never deleted (preview and execution refuse, the rows are untouched, no demo row and no synthetic account is lost, the audit chain verifies), and drives the real REST boundary through `rest_do_request()`: a real account is refused for the demo Clinic (403), not refused for its own Clinic, and a synthetic persona is not refused. P9 proves ledger deletion, truncation, rollback and seal loss all refuse, and that restoring the exact pair still completes the teardown.

**Stage 4 — an unverifiable manifest stands down; it never breaks the site.** The recorder, the barrier and the portal refusal all run on ordinary requests, and a login POST is a write request. All three therefore read the manifest through one total reader (`ScopedTenantGuard::verified_manifest()`), which yields `null` — no exception — for a payload that does not verify, a mode marker that does not verify, a non-active manifest, or an empty fixture id; the portal refusal catches the same load. Refusing to *guess* is the fail-closed answer: with no verified fixture there is no identity to register rows against and no demo tenant this process can prove, so nothing becomes deletable (the teardown refuses every unattributable row and refuses to run while the manifest does not verify). The first implementation let `MANIFEST_TAMPERED` escape, which fatalled every form POST of the site — the login included — and the Chromium tamper phase of the lane caught it.

**Evidence (real MySQL 8 + MariaDB 11.4.13).** The REST boundary is probed through the **real** production route `POST /clinic/v1/booking/hold`: a real non-fixture account naming the demo Clinic is refused with HTTP 403 `SCOPED_DEMO_TENANT_WRITE_REFUSED`, the same account writing into its **own** Clinic is not restricted, and a synthetic persona is not refused. The probe reads the machine-readable `code` from the REST envelope, because `WP_REST_Server::dispatch()` converts a `WP_Error` into a `WP_REST_Response` before returning. The tamper driver additionally proves, on the real database and with the real tampered payload, that the write recorder and `write_verdict()` stand down without throwing. Lane result at `7a97eb4`: **11/11 workflows, 38/38 jobs**, Scoped demo green on both database lanes.

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

**Current head:** `cd3c2a6…` — **30/30 checks green**, `mergeStateStatus: clean`, and both real-WordPress jobs pass end to end on the same head. This head carries two changes on top of the first green scoped-lane head `93897cf`:

1. `main` merged in (through `735a9c6`, i.e. the apt budget from #279 and the clock-independent fixtures from #280). The merge resolved `slot_plan()` in favour of `main`'s body — the reserved later-day 09:00 staff row, the `now + 5 min` whole-minute public block and the per-slot local date — and kept the scoped-lane contract intact, because the reservation removes a later-day row here too, so six or more open slots remain after the staff appointment.
2. A merge-resolution regression was caught and fixed in `cd3c2a6`: the conflict resolution had taken `main`'s whole provisioner file, which also reverted the prefix-driven `new_fixture()`. The scoped manifest requires a 32-hex fixture id with the `cpsd-` prefix, so the strict 16-hex generator sealed a manifest that `load()` rejected with `MANIFEST_FORMAT` (`شناسهٔ دمو در فایل مالکیت معتبر نیست`) — the failure both engines showed at the *seeded integration* step. The fix restores this branch's file and grafts in only `main`'s `slot_plan()` body.

Both engines then reported the full step-18 evidence: the seeded non-empty gate `ok`, the runtime drills, the tamper/restore pair, the provision and tamper phases at 11 and 5 checks, the journeys phase with the real OTP login (wrong code refused, real verify issuing a session for the existing synthetic identity, replay refused), the captured `/booking/hold` → `/booking/confirm` write with its identical replay and exactly one appointment on the slot, and the authorized teardown rendering `clean`.

Getting the scoped lane green in the first place needed three fixes, each with its cause named in the log rather than guessed:

1. **The baseline treated the site's own runtime as data.** MySQL 8 blocked the preview with `cpms:cpms_jobs baseline row digest changed`, `cpms:cpms_rate_limits baseline keyed row changed` and `wp:postmeta baseline row digest changed`. The queue wakes itself with a loopback tick after every enqueue, so a job row that already existed at seal time legitimately moves `queued → processing → success`; `cpms_rate_limits` is a keyed hit counter that the tick, the login throttle and the public OTP limiter increment and expire on their own; and core adds and drops its own publish markers. MariaDB had happened to finish its tick before the re-seal, which is why the failure looked engine-specific. The baseline now hashes a job's identity (`id`, `type`, `payload`, `priority`, `created_at`) instead of its lifecycle columns, skips the pure-counter table, and ignores `_pingme` / `_encloseme` / `_edit_lock`. It also stores per-row digests for tables up to 500 rows, so a real mismatch names the changed rows by id / `post_id:meta_key` / `option_name` — never a value.
2. **The public OTP request was posted through `/wp-json/` on an install with plain permalinks.** `POST /wp-json/clinic/v1/otp/request` returned the front page with HTTP 200, so the harness read "HTTP 200, no error code" and created no challenge. The anonymous phase now takes `rest_root` and `otp_request_path` / `otp_verify_path` from the booking widget's own `script.cpms-public-booking__config`, which is exactly what the widget uses.
3. **The strict `slot_plan()` could offer only five open slots between ~23:15 and Tehran midnight** (the "today" block needed `now + 45 min` to stay on the same Tehran day, and the visit appointment books one of the six), so `open future slots available for a NEW public booking (count=5)` failed. An interim patch on this branch added a third day; `main` @ `c218947` then replaced the whole anchoring scheme (reserved later-day staff row + `now + 5 min` public block + per-slot local dates), which this branch now uses — the merge resolved in favour of `main`'s body and kept only the docblock correction.

The superseded head `304ddbe…` showed the engine split that led there: MariaDB green end to end ([job 122441569554](https://github.com/bia2on2on/doctor/actions/runs/40575696740/job/122441569554)) while MySQL 8 died in *Tooling* on `apt-get update did not complete inside 240s` ([job 122441569076](https://github.com/bia2on2on/doctor/actions/runs/40575696740/job/122441569076)) and carried no evidence either way.

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

- The baseline digest additionally ignores three kinds of site **runtime** state, each with the reason it is not data: the queue lifecycle columns of `cpms_jobs` (`status`, `attempts`, `run_after`, locks, `last_error`, `started_at`, `completed_at` — the queue wakes itself with a loopback tick, so a job row that already existed at seal time legitimately moves `queued → processing → success` while the demo is exercised), the whole `cpms_rate_limits` hit-counter table (the tick, the login throttle and the public OTP limiter increment and expire rows with no operator action; the teardown still owns and deletes the fixture's own keys), and WordPress's own publish-scheduling markers `_pingme`/`_encloseme` plus the `_edit_lock` editor TTL in `wp_postmeta`. Without those exclusions the authorized teardown preview blocked with `TEARDOWN_BLOCKED` on the site doing its own maintenance rather than on demo drift — the MySQL 8 lane surfaced all three at once, while MariaDB happened to finish its tick before the re-seal and stayed green, which is why the failure looked engine-specific. The baseline still covers every CPMS table's identity columns, every unrelated core row, and every fixture-owned clinical and financial row. To keep that honest, the baseline now also stores per-row digests for tables up to 500 rows and a mismatch names the changed rows by id / `post_id:meta_key` / `option_name` — never a row value, and never PHI.
- The baseline digest deliberately ignores WordPress runtime user rows (`session_tokens`, `wp_user-settings`, `wp_user-settings-time`, `wp_dashboard_quick_press_last_post_id`, `wp_media_library_mode`) in `wp_usermeta`. They are WP core session/UI state written by the operator's own login and dashboard use, never CPMS or demo data; without the exclusion any real login blocks the safe teardown with `TEARDOWN_BLOCKED`, which the six-persona browser lane surfaced. The invariant still covers every CPMS table and every other core row.
- The demo patient account has **two** browser outcomes and both are asserted: with a password session alone it stays unbound and must fail closed (`/patient/me` → non-enumerating `404 CLINIC_NOT_FOUND`, `/patient/my-records` → `200` with an empty list, because the only production writer of `cpms_patient_user_links` is the mobile-OTP flow); after a real OTP login through `/otp/request` + the companion's sanctioned code display + `/otp/verify`, the same account is bound and must see exactly its own record. The OTP *widget* click path (calendar → slot → button) is not driven: the request payload is the widget's own tuple posted from the real booking page, and the patient portal's own OTP form is unusable on a multi-Clinic install by design (`CLINIC_SCOPE_REQUIRED`).
- Green is claimed only for the exact head above. The unrelated `Disposable external tool (prefix clinic_)` browser-login failure seen on an earlier head did not reproduce, and the pre-existing `Responsive smoke` keyboard-focus race described in Stage 1 is still an open harness issue even though that lane passed on this head.
- The production `cpms_audit_logs` table has a foreign key to `cpms_clinics`. Preserving the full audit chain and adding no production schema migration therefore requires retaining the three synthetic parent rows as suspended/inactive audit anchors. This is an explicit architectural limitation, not a silent residual: owned operational, clinical and financial records are removed, but the synthetic Organization/Clinic/Locations rows are archived rather than physically deleted.
- OTP issuance **is** now driven in the browser lane (Task 3), and a *successful* captured write with an idempotent replay is proven there for the booking confirm route (`/booking/hold` → `/booking/confirm` → identical replay). A successful **payment** capture plus replay in the browser is still not claimed: the fixture invoice is settled at provisioning by design, so the browser keeps asserting the refusal (`409 CLINIC_INVOICE_NOT_MODIFIABLE`) and the `idempotent_replay: true` payment proof stays in the PHP suite.
- Storage/audit **failure injection** remains NOT RUN: the drills interrupt provisioning and teardown at named stages and SIGKILL a child process, but no drill makes the storage layer or the audit writer itself fail mid-transaction.
- Screenshot pixels are still not inspected, and the tamper drill proves rejection of a payload whose HMAC no longer holds — it does not attempt to forge a *valid* HMAC (that would require the site's seal key, which the harness deliberately never reads).
- The strict (unscoped) demo's `slot_plan()` used to create its "today" block only while `now + 45 min` still fell on the same Tehran day, so between roughly 23:15 and midnight it produced six slots where the suite's contract needs seven (the visit appointment books one) and `Real WordPress acceptance` failed `open future slots available for a NEW public booking (count=5)`. That was a pre-existing time-of-day flake, not a scoped-mode regression — it reproduced on a head that changes no production file, and it disappeared in the same hour that `main` itself was green. The plan now adds a third day when the today block is unavailable, so the documented "at least six open slots" holds at every hour.
- The strict lane's `STAGE 13. Reception arrival check-in` needs the appointment the browser books to be on **today's** arrival board, and before `main` @ `c218947` (PR #280) the first available slot after ~23:15 Tehran was tomorrow's, so that stage timed out inside a daily window. That root cause is fixed upstream of this branch, not here: `main` now anchors the public block five minutes ahead on a whole-minute grid, reserves the later-day 09:00 row for the synthetic staff appointment so the same-day offer stays genuinely available, and gives both lanes a bounded local-midnight guard. This branch inherited it by merging `main` and no longer carries a `slot_plan()` of its own. The scoped lane's own contract — at least six **open** future slots after the staff appointment — still holds with the merged plan, because the reservation removes a later-day row there too.
- The post-merge `Responsive smoke` failure on `main` remains a separate harness issue; it may cause unrelated required checks to fail on this PR even if scoped-demo checks pass.
- Universal hosting compatibility is not claimed. The conservative preflight refuses multisite, persistent object cache, schema drift, unknown CPMS tables with data, dev OTP echo, and a busy queue runner.
- Local `php-wasm` checks are syntax/unit evidence only, not real MySQL/MariaDB/WordPress/Chromium evidence.

## Mission H — the standalone package (independently installable)

**What changed.** The demo used to be a *companion*: a `Requires Plugins:` header plus an
activation guard meant WordPress refused to activate it unless the production plugin was
already installed and active. The Product Owner evaluates the product on their own
non-empty test site and does not want to install production CPMS to do it, so the demo is
now built in two modes from the **same source tree** by one script:

* `bin/build-demo-installer.sh` (default, or `--standalone`) assembles the real production
  source — `clinic-practice-management.php`, `src/`, `templates/`, `assets/` at the current
  revision — into `core/` **at build time**. Nothing is copied into the repository, so the
  production source stays single and reviewable. Result: `cpms-disposable-demo-1.0.0.zip`,
  **1,617,100 bytes**, 358 entries, one top-level directory, exactly one plugin header, no
  `vendor/`, no `tests|docs|.github|bin|node_modules|phpunit|composer.*|*.dist`, and no
  `uninstall.php` from the core. Two builds of the same revision are byte-identical.
* `--companion` builds the previous artefact (`cpms-disposable-demo-companion-1.0.0.zip`)
  with no `core/`, for sites that do have production CPMS.

**Bootstrap (`CpmsDemo\Standalone`).** The embedded `core/` sits two levels below
`wp-content/plugins`, where WordPress's plugin scanner never sees it, so the core's own
activation hook cannot fire and its classes cannot be loaded twice by accident. The demo
boots it once, explicitly, from its own entry file and calls `App::activate( false )` once.
Boot happens only when no *foreign* CPMS is present; "foreign" is decided by **where the
loaded class came from** (`ReflectionClass::getFileName()`, compared by `realpath()` against
`core/`), not by the mere existence of a core class — a package that asked only
"is a core loaded?" refused to activate itself, which the first standalone lane caught.
A conflict is a Persian fault with no fatal: `embedded_conflict_production_active`,
`embedded_conflict_core_loaded`, `embedded_core_unreadable`, `companion_missing_production`.
The production update channel cannot replace the embedded copy: the slug is stripped from
`pre_set_site_transient_update_plugins`, `plugins_api` answers with
`WP_Error CPMS_DEMO_EMBEDDED_CORE`, and `auto_update_plugin` is false.

**Nothing about a non-empty site became a requirement.** Activation no longer probes the
site for emptiness; the demo provisions only its own sealed tenant, and the preflight asks
the production services what they can prove instead of assuming an empty installation.

**Measured lane (`standalone-demo.yml`, MySQL 8 + MariaDB 11.4.13).** A clean WordPress with
**no production CPMS anywhere** installs only the standalone ZIP, then: the embedded core
reports a real `CPMS_VERSION` and a real migration head; a genuinely non-empty site is
seeded (two real users, a page, a post, a comment, an attachment, a third-party plugin) and
digested byte-for-byte; the scoped demo is provisioned through the production services and
left **live**; the digest is re-checked with the demo active; the plugin is deactivated and
deleted and the digest is re-checked again, with every `cpms_*` table still present and the
synthetic accounts left in place (harmless rows over risky deletion); then production CPMS
is installed **alongside** the standalone build and the conflict is proven to be a Persian
refusal with no fatal and no second core boot; finally the production plugin is removed and
the standalone build proves it still boots. The driver never exits non-zero itself — it
prints one JSON line per mode, and the lane annotates the failing check by name.

## Disposition of the independent review's findings

| Finding | Disposition |
| --- | --- |
| **P0** sealed ids were untyped, so an auto-increment value could be read as an id in another table | **Fixed.** `SEALED_KEY_TABLES` binds each sealed key to exactly one table; a lookup is `(table, id)` or nothing. |
| **P0** ownership-ledger rows were registered before the actor was known, so registration silently never happened | **Fixed.** `ScopedTenantGuard::begin_request()` is idempotent and is invoked from `init@0`, `admin_init@0`, `admin_post@1`, `rest_pre_dispatch@10/3` and `shutdown@0`; the snapshot is taken after authentication, and `guard_rest_request()` re-snapshots post-auth. |
| **P1** transitive attribution (`@recipient` and the implicit `@tenant` default) could claim rows nothing proved | **Fixed.** `@recipient` returns `false`; the default is `@none` (refuse). 17 child tables have explicit `PARENT_RULES`, 9 configuration tables are allowlisted as `CONFIGURATION_TABLES`, and every other table must carry a positive claim. |
| **P1** incomplete demo-tenant write barriers | **Narrowed and stated.** Production CPMS exposes exactly two write surfaces — 137 `register_rest_route()` calls and 50 `admin_post_*` handlers, with **no** `wp_ajax_*` action and no XML-RPC method — and the guard covers both plus admin screens. A write that bypasses both (direct service call, WP-CLI, another plugin) is not blocked by the barrier; it is caught later by attribution, which refuses the whole teardown with `SCOPED_FOREIGN_TENANT_ROW` rather than deleting a row it cannot prove. The barrier makes `@tenant` a positive claim; the attribution gate is what protects the site if the barrier is bypassed. |
| **P2** a corrupt manifest could make the guard stand down (or, before that, fatal on every POST) | **Fixed in Stage 4** and pinned by `tests/scoped-demo/tamper-manifest.php`: `verified_manifest()` returns `null` for an unverifiable payload, marker, status or empty fixture id; nothing new becomes deletable and the teardown refuses to run. |
| **P2** interrupted provisioning | **Fixed in Stage 3/Stage 4.** The pending baseline and the sealed `intent` records are written before the first row exists and are repaired through `ScopedClinics::resolve()`; drills interrupt both provisioning and teardown at named stages. |
| **P3** a synthetic actor could be attributed a real administrator's membership | **Fixed.** `OPERATOR_GRANT_TABLES` + `operator_grant_is_bounded()`: a membership row is deleted only when it names the demo Organization/Clinic **and** the sealed operator, both re-proved at teardown; otherwise it refuses. |

## Defects this mission fixed in its own work

* The package refused to activate itself, because `evaluate()` ran again after
  `boot_core()` and saw its own core as a conflict. `another_core_is_loaded()` now
  discriminates by file path, and a booted request is recorded as the owner.
* `Standalone::core_available()` read `active_plugins` behind the environment
  abstraction's back; it now takes the list the environment already exposes, so the
  companion rule stays testable and the embedded rule stays build-based.
* The reproducibility contract compared its second build against whatever older archive
  happened to sit in `/tmp`; it now hashes the archive that build actually produced.
