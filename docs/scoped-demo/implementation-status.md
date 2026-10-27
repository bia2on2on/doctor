# Scoped demo — implementation status

**Branch:** `arena/3c5e41b4-doctor` (Arena-fixed; no second branch was created).
**Main merged forward:** `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8` via normal merge commit `e870023d…`, preserving preparation commit `5e951c837cbd195d483395cc6944211b9d038293`.
**Status:** implementation complete; dedicated exact-head GitHub Actions evidence exists for the scoped lane on both database engines (details below). Repository-wide green is still blocked by unrelated lanes, and several acceptance items remain NOT RUN.

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

The Chromium script explicitly does **not** yet exercise every role-specific clinical, reception, booking, finance, OTP or cross-Clinic authorization journey. Those remain **NOT RUN** and must not be claimed as passed. Screenshot pixels are saved but not yet inspected.

## Exact-head CI evidence (scoped lane)

**Head:** `b11a672206ca69ef860249bdd3135e7a0fadd0c0` (PR #277, draft, base `main` @ `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8`).

| Check | Result | Evidence |
|---|---|---|
| Static gates, unit tests and reproducible companion ZIP | **success** | [job 122184525693](https://github.com/bia2on2on/doctor/actions/runs/40502823762/job/122184525693) |
| Real WordPress integration (mysql-8) | **success** | [job 122184638814](https://github.com/bia2on2on/doctor/actions/runs/40502823762/job/122184638814) |
| Real WordPress integration (mariadb-11.4.13) | **success** | [job 122184638655](https://github.com/bia2on2on/doctor/actions/runs/40502823762/job/122184638655) |

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

Also green on the same head: `Integration (WP 7.1 + MySQL 8)`. Still running at the time of writing: First-practice browser (both engines), Staging Gate, Responsive smoke, Real WP Acceptance (`clinic_`), Facility matrix (mysql), two coexistence lanes.

### Unrelated failing lane on the same head

`Disposable external tool (prefix clinic_)` failed ([job 122184525983](https://github.com/bia2on2on/doctor/actions/runs/40502823859/job/122184525983)) at `ERROR: RuntimeError: Generated doctor login failed`, in `tests/disposable-site/browser-acceptance.py`. That lane does not load the companion plugin and this branch contains **no** change under `tests/disposable-site/**` or `clinic-practice-management/**`. The same lane passed on `f11e596…` and `5008ccf…`; the only difference between `f11e596…` and `b11a672…` is `tests/scoped-demo/browser_scoped.py`, which that lane never reads. This is therefore classified as an **unrelated intermittent browser-login failure in a pre-existing lane**, not a scoped-mode regression. It is reported, not concealed.

## Remaining risks / honest limitations

- The baseline digest deliberately ignores WordPress runtime user rows (`session_tokens`, `wp_user-settings`, `wp_user-settings-time`, `wp_dashboard_quick_press_last_post_id`, `wp_media_library_mode`) in `wp_usermeta`. They are WP core session/UI state written by the operator's own login and dashboard use, never CPMS or demo data; without the exclusion any real login blocks the safe teardown with `TEARDOWN_BLOCKED`, which the six-persona browser lane surfaced. The invariant still covers every CPMS table and every other core row.
- The demo patient account is **authenticated but not bound** to a patient record. The only production writer of `cpms_patient_user_links` is the mobile-OTP enrollment flow, and this companion never reads, echoes or bypasses an OTP code. The browser journey therefore asserts the fail-closed production behaviour instead of a successful read: `/patient/me` answers the non-enumerating `404 CLINIC_NOT_FOUND` and `/patient/my-records` answers `200` with an empty list. Binding the persona would require either an OTP bypass or a new production API; neither is in scope here, and no journey claims the patient can see clinical data.
- Repository-wide green is not claimed: several unrelated lanes were still running, one pre-existing disposable-site lane failed intermittently, and the `Responsive smoke` harness race described above remains open.
- The production `cpms_audit_logs` table has a foreign key to `cpms_clinics`. Preserving the full audit chain and adding no production schema migration therefore requires retaining the three synthetic parent rows as suspended/inactive audit anchors. This is an explicit architectural limitation, not a silent residual: owned operational, clinical and financial records are removed, but the synthetic Organization/Clinic/Locations rows are archived rather than physically deleted.
- Full six-persona role-specific workflow coverage, OTP browser journey, cross-Clinic REST authorization drill, tampered-manifest runtime test, concurrency test, interrupted provisioning/teardown fault injection, storage/audit failure injection, and repeated-teardown runtime tests are not yet implemented in the executable acceptance suite.
- The post-merge `Responsive smoke` failure on `main` remains a separate harness issue; it may cause unrelated required checks to fail on this PR even if scoped-demo checks pass.
- Universal hosting compatibility is not claimed. The conservative preflight refuses multisite, persistent object cache, schema drift, unknown CPMS tables with data, dev OTP echo, and a busy queue runner.
- Local `php-wasm` checks are syntax/unit evidence only, not real MySQL/MariaDB/WordPress/Chromium evidence.
