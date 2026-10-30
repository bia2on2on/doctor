# Scoped demo on non-empty test sites — preparation checkpoint / continuation report

**Date/time of live state check:** 2026-10-27T01:06:42Z (UTC).
**Historical status:** `BLOCKED_BY_PR_276` at the time this checkpoint was written. That dependency has since merged into `main` at `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8`; implementation continued on the same fixed session branch. See [`implementation-status.md`](implementation-status.md) and [`post-merge-failure-diagnosis.md`](post-merge-failure-diagnosis.md) for the current state. No tag, release, deployment, merge of the scoped PR, or Phase 20 action has been performed.

## 1. Live dependency and writer state

| Item | Verified live value |
|---|---|
| Session writer branch | `arena/3c5e41b4-doctor` at `2ae07abf2e68c8ec7c9c679016915d5be4ce7034` |
| Live `main` | `2ae07abf2e68c8ec7c9c679016915d5be4ce7034` |
| PR #276 | `OPEN`, not draft, `mergeable=true`, `mergeable_state=unstable` |
| PR #276 head | `cd584dc0b920e6d3f8752a006589ad6f3620eb06` on `arena/0da76f07-doctor` |
| PR #276 base | `2ae07abf2e68c8ec7c9c679016915d5be4ce7034` |
| Active writer files | `README.md`, `cpms-disposable-demo.php`, `class-cpms-demo-admin.php`, `readme.txt`, `tests/bootstrap.php`, `tests/browser-acceptance.py`, `tests/run-tests.php` under `tools/disposable-demo-installer/` |
| Failing checks | `WPCS (changed code)` run/job `40425704215/121931200158`; `Responsive smoke (Chromium ×4 viewport)` `40425704114/121931314877` |
| Other open PRs | none at this check |

The mission requires PR #276 to be stable and available on `main` before an independent implementation branch starts. It is neither merged nor green. The seven active-writer files include the companion bootstrap and admin page that a scoped mode must integrate with; editing them now would create two concurrent writers on overlapping semantics. Therefore Stages 2–6 implementation and Stage 5 execution are intentionally **NOT RUN**.

**Arena branch constraint:** this session's branch is fixed to `arena/3c5e41b4-doctor`. It is already separate from PR #276's writer branch `arena/0da76f07-doctor`; no second branch can be created or switched to in this session. After PR #276 merges, the continuation must verify the new live `main` SHA and merge `origin/main` forward into this fixed branch (no rebase, reset, force-push, or history rewrite).

## 2. Required reading completed

- `AGENTS.md`: absolute honesty, evidence vs inference, security by design, tenant isolation, fail-closed authorization, bounded debugging, append-only agent log.
- `docs/agent-guide.md`: live GitHub verification before writes, topical docs, no product-decision fabrication, no history rewrite, no test weakening.
- `docs/engineering/pre-coding-rules.md`: think before coding, simplicity, surgical changes, goal-driven execution.
- `https://github.com/multica-ai/andrej-karpathy-skills`: repository page and current `README.md` / `CLAUDE.md` read via GitHub. No external script or dependency was installed or executed. Its four principles are already summarized in `docs/engineering/pre-coding-rules.md`.
- `docs/governance/engineering-tooling.md`: live state and owner decisions override external tooling; approximate one-hour GitHub action window; on reconnection perform read-only recovery first; no empty CI commits or false PASS.

**Document conflict recorded:** `docs/engineering/pre-coding-rules.md` currently says `tools/disposable-demo-installer/**` is inspection-only for a prior task. The new owner mission explicitly requires adding a scoped mode to that companion. The newer owner instruction controls this task, but strict disposable mode remains unchanged and the PR #276 boundary still applies.

## 3. Stage 1 findings (source evidence)

- Strict disposable mode is a single `manage_options` wp-admin page behind POST, action-specific nonce, exact typed phrase, and site-fingerprint checks. Its `Preflight` refuses non-empty sites, extra users, non-default content, other active plugins, multisite, HTTP, persistent object cache, schema drift, dev OTP echo, unknown `cpms_*` data, and an existing manifest.
- `Env::with_lock()` uses MySQL `GET_LOCK(name, 0)` and is released in `finally`; this is suitable for serializing both strict and scoped provisioning/teardown.
- `Manifest` is HMAC-sealed with the site's auth salt and bound to home/site URL, DB name, and table prefix; it stores identity/IDs, never credentials.
- Provisioning uses production CPMS services (Organization/Clinic provisioning, memberships, clinician/location, schedule, patient, booking, visits, clinical notes/prescription/follow-up, invoice, offline payment). It creates six WordPress accounts with CSPRNG passwords and a unique `cpdd-` fixture namespace.
- SMS is kept on the existing `log` provider for the synthetic clinic; payments are offline `other`; `CPMS_DEV_OTP_ECHO` is not defined.
- Teardown uses a 50-table FK-safe order and live ownership predicates rather than `id > baseline`. It preserves shared IP rate-limit rows and has fault-injection recovery. However, the **existing strict mode deletes selected `cpms_audit_logs` rows**. That is acceptable only inside its original disposable-site contract; the new scoped mode must not inherit that behavior.
- The full pinned schema has 53 known `cpms_*` tables: 43 operational + 10 system/global. The complete per-table ownership map is in [`ownership-map.md`](ownership-map.md).
- `cpms_audit_logs` is append-only and hash-chained by production `AuditLogger` / `HashChain`: `row_hash = SHA-256(prev_hash + canonical(fields))` in ascending ID order. There is no update/delete API. Scoped teardown must preserve every row and verify the full chain.
- `cpms_rate_limits` has globally shared IP counters; `cpms_operational_logs` has no clinic column and shared request IDs/IPs; `cpms_jobs` is installation-wide and its recurring jobs have empty payloads. These require exact-ID/closed-allowlist handling, not broad predicates.
- `App::runTick()` owns `GET_LOCK('cpms_jobs_tick', 0)` and schedules recurring jobs with empty payload. A safe scoped teardown should acquire this production lock (or otherwise prove the runner is stopped) before deleting owned queued jobs; a `processing` owned job must fail closed.
- Existing companion tests provide reusable patterns: a fake WordPress bootstrap, PHP unit/security suite, real-WordPress integration with cross-connection lock contention/fault injection, and Playwright Chromium six-persona acceptance. Their current assertions assume a disposable clean site and a post-teardown audit count of zero; the scoped lane needs its own tests rather than weakening these.

## 4. Stage 2 design — smallest coherent implementation

### 4.1 Non-negotiable boundary

Do **not** change `Preflight`, `Provisioner`, `Teardown`, `Credentials`, `Otp`, `Request`, or the strict-mode dispatch. Add a separate scoped workflow that reuses `Env`, `Platform`, `HashChain`, `AuditLogger`, and selected helpers, but never bypasses production authorization, tenancy, booking, OTP, or finance gates.

### 4.2 Proposed non-overlapping source layout

New files under `tools/disposable-demo-installer/includes/`:

- `class-cpms-demo-scoped-manifest.php` — HMAC-sealed `cpsd_manifest` / `cpsd_tombstone`, format `2`, site fingerprint, mode, stage, baseline digests, exact owned IDs, and no credentials.
- `class-cpms-demo-scoped-preflight.php` — compatibility, collision, baseline, and audit-chain checks; disabled by default; no strict preflight bypass.
- `class-cpms-demo-scoped-baseline.php` — read-only baseline snapshot for global surfaces and verification of baseline preservation.
- `class-cpms-demo-scoped-audit-guard.php` — full-chain verification and immutable-prefix digest; never deletes.
- `class-cpms-demo-scoped-provisioner.php` — staged, recoverable creation of a fresh synthetic Organization → Clinic → Locations and six personas.
- `class-cpms-demo-scoped-teardown.php` — exact-ID preview, global-table handling, FK-safe deletion, post-verification, and fail-closed residual reporting.
- `class-cpms-demo-scoped-request.php` — distinct action names, nonce actions, and phrases for activation/provision/teardown/diagnose.
- `class-cpms-demo-scoped-admin.php` — separate cards/forms and Persian guidance; integrated by `Admin` only after PR #276 is merged.

Existing `Admin`, `cpms-disposable-demo.php`, `README.md`, `readme.txt`, and tests are integration points that **must not be touched until the active writer is finished**. The build script copies the entire `includes/` directory, so new classes need no production-plugin changes or schema migration.

### 4.3 Mode activation and authorization

- Default mode: strict disposable. Scoped mode is off and cannot be auto-enabled by activation, constants, filters, or a hidden URL.
- Activation form requires an authenticated Administrator with `manage_options` **and** `install_plugins`, action nonce, exact phrase `I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE`, HTTPS (or isolated loopback only in CI), and the rendered site fingerprint.
   > **Superseded (see *Mission N* in `implementation-status.md`).** The typed phrase is now required only for the irreversible teardown; activation, provisioning, resume and rotation are one click and are still gated by POST + capability + action nonce + site fingerprint + HTTPS.
- Store a sealed `cpsd_mode_enabled` marker with actor ID and timestamp; re-check it on each mutation. Deactivate/reactivate does not silently re-enable.
- Reuse `Env::with_lock()` for every mutation so strict and scoped modes cannot run concurrently.
- Teardown uses a separate phrase `REMOVE_ONLY_SCOPED_DEMO_DATA` (now the only typed phrase) and a read-only preview first.

### 4.4 Collision resistance and fixture identity

- Use a CSPRNG 128-bit fixture ID (32 lowercase hex) and a dedicated `cpsd-` namespace distinct from strict `cpdd-`.
- Before any write, prove that no existing manifest/tombstone, WordPress username/email, post slug, Organization/Clinic/Location slug, patient/identity/OTP/SMS mobile, idempotency key, rate-limit key, or private path contains or collides with the fixture namespace/ID.
- Use the existing reserved synthetic `0900…` mobile shape only after checking `cpms_patients`, `cpms_otp_tokens`, `cpms_sms_messages`, and `cpms_patient_identities` for collisions. No 4-digit suffix is accepted as ownership proof.
- All display names, slugs, service codes, invoice references, payment references, and post content carry the explicit fixture ID and `SYNTHETIC` marker.
- No schema migration: ownership is recorded in HMAC-sealed WP options, synthetic user meta, synthetic post meta, and exact row IDs.

### 4.5 Provisioning and recovery

- The manifest is saved after every stage (`provisioning`, then per-stage exact IDs, finally `active`). A crash leaves a sealed partial manifest and a recovery card; re-provisioning is refused until diagnosis/teardown completes.
- A final sweep across all 53 `cpms_*` tables and relevant WP core tables must reconcile discovered fixture-owned rows with the manifest. Any untracked or ambiguous row fails closed and enters recovery teardown.
- Six independent WP accounts use real `wp_create_user` / `wp_set_password` and are verified by `wp_authenticate`; no Administrator persona is created.
- Persona authority comes only from the synthetic Organization/Clinic memberships and the production authorization service. WordPress roles remain low privilege (`subscriber` or `cpms_patient`). No site-wide capability or custom role preset is added.
- Use only synthetic Clinic settings (`sms.provider=log`, bounded booking settings), offline `other` payment, and no real SMS/payment gateway. Do not change global settings.
- Avoid private file uploads in the core journey. If any service creates a file, record its exact fixture-scoped path and fail closed if the path is outside that namespace.

## 5. Stage 3/4 teardown design

- Teardown preview lists exact row IDs per table, six users, one page, parent Organization/Clinic/Location IDs, owned job/log IDs, rate-limit keys, and preserved audit rows. No raw PHI or credential is shown.
- Delete only exact IDs that are re-verified live against the sealed manifest plus their parent chain. Foreign rows in the same tables are expected and preserved.
- `cpms_audit_logs`: **never delete or update**. Verify full chain before provisioning and after teardown; verify the immutable pre-demo prefix digest. Demo audit rows and legitimately appended foreign rows remain.
- `cpms_rate_limits`: preserve all shared IP/global counters and all baseline/foreign entity keys. Delete only a closed allowlist of exact fixture-derived keys that were proven absent before provisioning.
- `cpms_operational_logs`: preserve all baseline/foreign rows. Delete only exact log IDs whose `context_json` contains a verified fixture entity ID or the 32-hex fixture marker. Never use timestamp, request ID, IP, or masked mobile suffix.
- `cpms_jobs`: preserve all recurring/global and foreign jobs. Acquire `GET_LOCK('cpms_jobs_tick', 0)`; if unavailable or an owned job is `processing`, refuse teardown. Destroy persona sessions and suspend the synthetic clinic before deletion. Delete only exact owned job IDs; any new fixture-owned job found in post-verification means incomplete teardown.
- WordPress users/pages: verify exact IDs, logins, emails, roles, post type/slug/content, and fixture user/post meta before deletion. Existing users, posts, comments, attachments, and metadata remain. Comments authored by synthetic accounts are preserved with a non-destructive author reassignment or explicit residual warning; no pre-existing comment is deleted.
- Clinical/financial cleanup is fail-closed: delete children before parents, verify each step, then verify zero owned rows remain. If any owned clinical or financial row remains, status is `teardown_failed`, the manifest is retained, and the UI shows a precise warning — never success.
- Repeated teardown after a sealed clean tombstone is an idempotent no-op.

## 6. Stage 5 acceptance design

The complete matrix, baseline algorithm, positive browser journey, negative cases, and evidence fields are in [`acceptance-plan.md`](acceptance-plan.md). All runtime tests are **NOT RUN** in this checkpoint. Local environment lacks PHP, Composer, MySQL/MariaDB, WP-CLI, and Chromium; no CI run has been triggered for scoped mode.

The only executable evidence in this checkpoint is a source-level static contract (`tests/scoped-demo/test_ownership_contract.py`) plus ordinary file/JSON/YAML checks. A static PASS must never be represented as runtime WordPress or browser PASS.

## 7. Stage 6 packaging and review plan

- Keep companion ZIP separate from production ZIP. `bin/build-demo-installer.sh` already copies all `includes/`, `assets/`, main file, readme, and uninstall, while excluding tests/docs.
- Build twice and compare SHA-256; verify exactly one top-level `cpms-disposable-demo/`, valid plugin header, `Requires Plugins: clinic-practice-management`, no production sources, no tests/docs, no secrets, and no dev OTP echo.
- Add scoped tests and a dedicated workflow lane without weakening the existing strict disposable lane, companion/production isolation rule, or mandatory CI checks.
- Open one dedicated PR from `arena/3c5e41b4-doctor` to `main` only after implementation and exact-head evidence; do not merge it. Do not tag, release, deploy, or initiate Phase 20.

## 8. Open architectural risks / merge blockers

1. **PR #276 dependency (current hard blocker):** open/unstable; active writer owns overlapping bootstrap/admin/test files. Do not edit them.
2. **Audit-chain semantics:** scoped teardown intentionally leaves demo audit rows. The final report must state that “all demo-created operational data” excludes immutable audit history by owner requirement; otherwise the phrase is misleading.
3. **Job race:** need executed proof that taking `cpms_jobs_tick` lock plus session destruction and synthetic clinic suspension prevents new fixture jobs during teardown. If not, the implementation must fail closed rather than claim complete cleanup.
4. **Manifest size/option limits:** exact per-row ownership may exceed a single WP option. Design must cap/chunk sealed ownership data or refuse provisioning before writes; no new schema is authorized.
5. **Private storage:** need a complete inventory of files created by production services. Any untracked file outside a fixture-specific path blocks teardown.
6. **Foreign activity vs teardown guard:** broad table-level foreign-row checks from strict mode are unsuitable. Need exact-ID ownership for every row; unproven rows must block.
7. **Persistent object cache / DB drop-ins:** live state must be re-read under the concurrency lock. Conservative preflight should refuse persistent object cache, read replicas, or unknown DB drop-ins rather than claim universal hosting compatibility.
8. **Six-persona browser proof:** separate independent browser contexts and real `wp-login.php` authentication must be executed; unit tests cannot substitute.
9. **MariaDB/MySQL JSON differences:** exact JSON-path matching must be verified on both engines; no unverified `JSON_EXTRACT` assumption.
10. **Fixed session branch:** the mission asks to “start your own independent branch”; Arena fixes this session to `arena/3c5e41b4-doctor`. This branch is independent of PR #276's writer, but a new branch cannot be created here. This must be disclosed in the final report.

## 9. Precise continuation steps

1. Perform read-only recovery first: live `main`, PR #276 state/head/base/mergeability/checks, all open PRs, session branch remote head, exact-head checks, and complete `git status`.
2. Wait until PR #276 is merged, its merge is on live `main`, and relevant required checks are green. Do not fix or merge PR #276 from this writer.
3. Record the new authoritative `main` SHA; merge `origin/main` forward into `arena/3c5e41b4-doctor`. Do not rebase/reset/force-push.
4. Re-read the merged `Admin::register_menu()` / legacy URL behavior and integrate the scoped admin cards with the new CPMS unified menu without creating a competing menu relocation.
5. Implement the scoped classes, tests, workflow, and Persian UI using the ownership map and acceptance plan. Add tests first for collision, manifest tamper, audit preservation, job race, and baseline preservation.
6. Execute the full MySQL 8 + MariaDB 11.4.13 + Chromium matrix on exact-head CI. Inspect screenshots; do not call artifact existence “inspection”.
7. Build the companion ZIP twice and record exact reproducibility evidence.
8. Open exactly one dedicated PR from `arena/3c5e41b4-doctor`; do not merge. Report PR URL, base/head SHAs, architecture, files, executed evidence, baseline/audit/browser results, ZIP evidence, remaining risks, and precise merge blockers.
