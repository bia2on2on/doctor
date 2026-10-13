# Custom Role Lifecycle Slice 4B — frozen archival contract

Prompt receipt / live preflight: **2026-10-13T05:39:22Z**. Safe stop: **06:34:22Z**;
maximum authorization: **06:39:22Z**. An internal continuation does not renew it.

Live root `/home/user/doctor`; clean worktree; legal source branch
`arena/a3e54c26-doctor`; HEAD and authoritative GitHub `main` both
`4c3dbe86ef5e069548d2c54f77b66a3aaa746060`; zero open PRs at preflight;
latest migration `2026_10_11_0026`. Governance and Product Decision
`2026-10-10-custom-role-permission-management-model.md` read before writing.

## Owner-approved contract (this slice only)

- Archive is **distinct from reversible deactivation and terminal**. Definitions,
  capabilities and immutable Clinic-local keys remain persisted/reserved.
- Archived definitions grant **no role-derived authority**; no edit, assignment,
  reactivation or second mutation of an archived definition. A current-version
  archive retry is a reported no-op, never another audit. Stale retries refuse.
- Archive requires **zero same-Clinic membership references of any status**, including
  suspended memberships. No automatic reassignment, hard deletion, WP-global role
  mutation or clinical-data modification. Membership DENY > GRANT > role > DENY
  precedence is unchanged in the existing AuthorizationService.
- TrustedClinicEstablisher + effective Clinic-local CONFIG + exact durable object
  ownership are mandatory. Version/status checks, current definition/reference
  locks, status/version and mandatory archival audit belong to one transaction.
- Definition locks precede membership locks, then audit persistence. Assignment,
  edit, capability replacement and reactivation share definition serialization.
  Waiting operations revalidate current state and fail closed.
- Only a bounded, explicit membership-only reassignment of an existing suspended
  member is added to resolve references. Suspension, GRANT/DENY, WP roles, restore
  metadata, professional identity and Location assignments must remain unchanged.
- Existing server-rendered UI gets a confirmed, action-nonce-protected Archive
  control, clear refusal/feedback and read-only archived definitions. No SPA.
- New forward migration only; no archived_at requirement exists. No applied
  migration is edited. Schema/binary downgrade must not silently reinterpret or
  destroy archived rows; MySQL DDL cannot be represented as transactional rollback.
- Existing Slice 4A race and all validation families remain. Actual independent
  MySQL wait edges, not sequential calls, are required for concurrency claims.
- No Phase 20, merge, history rewrite, empty/retrigger commit, or unrelated feature.

## Official references consulted on 2026-10-13

- WordPress [Nonces](https://developer.wordpress.org/apis/security/nonces/): CSRF
  protection is not authentication/authorization; use a specific action string.
- PHP [mysqli::begin_transaction](https://www.php.net/manual/en/mysqli.begin-transaction.php):
  InnoDB transactions and checked failures; reuse CpmsDb rather than add a wrapper.
- MySQL 8 [locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html):
  FOR UPDATE is a current read, not a consistent snapshot, and requires a transaction.
- MySQL 8 [implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html)
  and [ALTER TABLE](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html): DDL commits
  implicitly; MODIFY must retain column attributes.
- PHPUnit [9.6 assertions](https://docs.phpunit.de/en/9.6/assertions.html), matching
  the official WordPress Integration harness pin.

## Evidence checkpoint

Test-first contract added; execution initially **NOT RUN**. This sandbox has no
PHP, Composer or MySQL binaries; the existing real WordPress/MySQL GitHub CI is
used for execution. Missing APIs are asserted explicitly (the existing Slice 4A
pattern), not hidden as infrastructure errors. The unknown-status regression
exercises existing behavior independently of the missing archive API.

## Preserved implementation / failure checkpoint (2026-10-13)

- Exactly one DRAFT PR: [#221](https://github.com/bia2on2on/doctor/pull/221),
  source `arena/a3e54c26-doctor`, base `main`; no merge.
- VALID RED: `ee163c3a4a591da934009f0f60efc8b7f2853797`, CI
  [38352981951](https://github.com/bia2on2on/doctor/actions/runs/38352981951):
  focused archival **25 tests / 199 assertions / 24 failures**. Raw mirrored
  evidence is preserved in `evidence/custom-role-archival-valid-red.md`.
  The unknown-status normalization is an executed behavioral regression,
  independently of the missing archival API. Full Integration did NOT RUN at
  this RED checkpoint; its downstream coverage guard correctly failed.
- First implementation `94d264382fdf8d9dccee88ba877caa4779cce828`, CI
  [38354086771](https://github.com/bia2on2on/doctor/actions/runs/38354086771):
  focused security **OK (25 tests, 327 assertions)**, including authorization,
  references, terminal state, audit rollback, suspension/restore preservation,
  unknown status and server-rendered controls/nonce. All four Unit legs,
  PHPStan and Tenant Tripwire passed. **WPCS FAILED**. Full Integration also
  **FAILED**: first downgrade fixture failed and left its fail-closed CHECK
  guard in place; subsequent tests cascaded (**1850 tests, 13891 assertions,
  1415 errors, 5 failures**). Those are not additional valid feature RED.
- The downgrade root cause is NOT yet established from execution. New focused
  schema diagnostics publish the previous exception chain and live CHECK
  metadata to the PR. The guard intentionally fails closed, but a partial
  downgrade is not a transactional rollback and can block archival until
  verified forward recovery. This is an acceptance blocker, not a PASS.
- Four independent-session race contracts are added using the proven Slice 4A
  observer/connection/socket queries. They require exact PRIMARY-record
  `performance_schema.data_lock_waits` edges and post-commit refusal in both
  winner orders and both edit paths. **New Slice 4B concurrency execution is
  PENDING/NOT ESTABLISHED at this checkpoint**. Slice 4A is unchanged.
- PHP AST parsing and local Tenant Tripwire were actually run. AST parsing is
  NOT PHP runtime/Unit/Integration evidence. No local PHP/MySQL runtime exists.
- Applied migrations were not modified. Migration 0027 appends `archived`,
  preserves status attributes/indexes/data, and adds no archived_at. Downgrade
  uses an enforced CHECK across implicit DDL commits rather than a TOCTOU row
  check; strict/non-strict refusal is tested, but safe down/up remains blocked
  by the execution failure above. **Binary rollback to pre-4B with archived
  rows is UNSUPPORTED**: old definition readers normalize archived to active,
  and old edit/status paths do not enforce terminality. Do not run mixed old
  writers after archival; quiesce writers/back up before schema maintenance.
- No clinical or WP-global role write is introduced in production archival or
  reassignment. Explicit membership GRANT/DENY precedence remains in the same
  AuthorizationService; its optional current-read mode is used by serialized
  role writes, not a second authorization engine.
- Final exact-head six-family conclusions must be read live. Historical
  success cannot substitute for them. NOT RUN/PENDING/SKIPPED/CANCELLED is
  never PASS. **READY FOR INDEPENDENT ACCEPTANCE: NO** at this checkpoint.

### Subsequent actual schema diagnostic — 06:32 UTC

CI 38356935641 at `bd4c1b68b18f9647f39e48b8fc78fec71397054c`
repeated focused security PASS and failed focused schema (**4 tests / 10
assertions / 1 error / 2 failures**). New race and downstream full-suite steps
were **SKIPPED**, not PASS. The exact exception chain and live CHECK metadata
are preserved in `evidence/custom-role-archival-downgrade-failure.md`.
The enforced `YES` active/inactive guard returned escaped quote delimiters
(`_utf8mb4\'active\'`) which the exact normalizer rejected. A narrow fix
recognizes that delimiter representation before charset normalization; it still
requires enforcement and the complete two-state expression, not an arbitrary
constraint. The only remaining WPCS added-line violation was `$column`
alignment; corrected. **These fixes have NOT yet passed runtime validation.**
Root cause of this observed first guard-verification failure is established;
subsequent enum narrowing/forward recovery and the four races remain unproven.
