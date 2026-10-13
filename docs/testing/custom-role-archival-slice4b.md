# Custom Role Lifecycle Slice 4B — frozen archival contract

Prompt receipt / live preflight: **2026-10-13T05:39:22Z**. Safe stop: **06:34:22Z**;
maximum authorization: **06:39:22Z**. An internal continuation does not renew it.

> **Status note (appended 2026-10-13T21:41Z, documentation-only):** this is a **frozen
> contract + dated evidence log**, not the current-state record. Everything below the
> note is preserved verbatim as historical checkpoints, including the "PR is DRAFT /
> NOT merged" and "READY FOR INDEPENDENT ACCEPTANCE: NO" lines, which were accurate at
> their own timestamps. The slice itself has since been **merged**: PR #221 →
> `main` at `8f8ca4bfc8e4cbc828f9689833392a1482a35d1a`. See the final section
> "[Merged state and closure (2026-10-13)](#merged-state-and-closure-2026-10-13)" and
> [`../project-current-state.md`](../project-current-state.md#custom-role-current-state-2026-10-13).
> No line above this note was edited, re-ordered or deleted.
>
> The preflight lines below describe the state **at 05:39Z on 2026-10-13** and are now
> historical: at that moment `main` = `4c3dbe86…` (Slice 4A merge), the schema head was
> `2026_10_11_0026`, and PR #221 was the only open (DRAFT) PR. The current schema head
> is `2026_10_13_0027_custom_role_archival.php`.

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

### Runtime confirmation and evidence-framing correction — 2026-10-13T07:58:41Z

The correction committed at `107d4982` did pass its focused steps on CI run
38357339882 (head `107d498237af18d78a9f0515ceab58e780c61e2c`):

- focused terminal-role archival security contract: **OK (25 tests, 327 assertions)**;
- focused archival schema/downgrade safety: **OK (4 tests, 37 assertions)** — fresh
  schema, `0026 → 0027` down/up round trip with row, capability, attribute and index
  preservation, guarded refusal of an archived-row downgrade in default and explicit
  non-strict SQL mode, and a retained 0027 marker with unchanged data;
- focused independent MySQL races: **OK (4 tests, 190 assertions)** — all four cases
  executed on distinct connections with exact `performance_schema` wait-edge evidence.

The CI check nevertheless failed because three of the four emitted evidence records
shared a line with PHPUnit's progress dot, while the line-oriented collector in
`.github/workflows/ci.yml` accepts only records that begin a line; it therefore saw
one of four and reported `Missing/duplicate archival race evidence.` This is a
test/CI evidence-framing defect (class D), not a migration, concurrency or product
defect; the migration correction itself is runtime-proven.

Correction in this commit: each record now starts with `PHP_EOL`, so it always begins
on its own line. No assertion, lock-edge requirement, four-case requirement, JUnit
check or fail-closed collector rule was changed or weakened, and the collector itself
is untouched. Migration, authorization, archival, membership and lifecycle code paths
are unchanged by this commit.

<a id="merged-state-and-closure-2026-10-13"></a>

## Merged state and closure (2026-10-13) — appended documentation-only checkpoint

Appended `2026-10-13T21:41Z` by a later, documentation-only session. Nothing above this
heading is edited. This section closes the loop between the frozen contract and the state
that is now on `main`; it does not re-adjudicate the security contract itself.

**Merged:** PR #221 (source `arena/a3e54c26-doctor`, base `main`) is **MERGED**, accepted
head `c71cd3d9ce335014530bcaafc835a2eb17d88c3f`, `mergedAt = 2026-10-13T21:06:11Z`, merge
SHA **`8f8ca4bfc8e4cbc828f9689833392a1482a35d1a`** = current authoritative `main` (re-read
live; `origin/main` and this session's `HEAD` are byte-identical to it; open PRs = 0).
The "one DRAFT PR, no merge" and "READY FOR INDEPENDENT ACCEPTANCE: NO" lines above remain
the record of their own checkpoints and are **not** reclassified retroactively as approval.

**Commit sequence after the 07:58Z checkpoint (forward commits only — no reset, rebase or
force-push):** `668316a0` (race evidence line-start framing), `c143a01e`…`24f41a50` and
`58a0d8bf` (test-only diagnostics + archival-race fixture-ownership / descriptor-isolation
corrections, the escaped-delimiter migration-guard recognition, and WPCS alignment), then
`9f190d3f` (Reception fixture same-day collision) and `c71cd3d9` (bounded Reception fixture
failure category published to the existing channels). Verified via the GitHub commit API in
this session: `9f190d3f` touched only test/harness files plus `docs/agent-log.md`, and
`c71cd3d9` only `.github/workflows/pilot-gate.yml` plus `docs/agent-log.md`. No migration,
authorization, archival, membership or lifecycle code path was altered by that final pair.

**Exact merge-SHA closure (read live from the GitHub API in this documentation session):**
six required workflow families, each a `push → main` run whose `head_sha` is
`8f8ca4bfc8e4cbc828f9689833392a1482a35d1a`, all `completed / success` — CI `38468036296` ·
Real WordPress Acceptance `38468036367` · Closure Gate `38468036428` · Pilot/Staging
Readiness Gate `38468036189` · Third-Party Compatibility Baseline `38468036238` · Persian
five-plugin coexistence `38468036426`. Check-runs API on the same SHA: **47/47
`completed`/`success`**. The check-runs API on the accepted head `c71cd3d9…` likewise reports
**47/47 `completed`/`success`**, including CI `38460269890`, whose `Integration (WP 6.7 +
MySQL 8)` job `115513969996` concluded `success` together with WPCS, PHPStan, Tenant Tripwire
and the four PHP 8.1–8.4 Unit legs.

**What this closure does and does not establish:**
- It establishes that the full CI + acceptance gate families (including the full Integration
  suite that previously failed at `94d26438` and the focused schema family that failed at
  `bd4c1b68`) are green at the accepted head and at the merge SHA. The downgrade-guard and
  evidence-framing blockers recorded above are therefore closed **by gate evidence**, not by
  a new local run.
- It does **not** restate numeric PHPUnit totals for those runs: the job logs were
  **NOT retrievable** from this documentation environment
  (`gh api …/actions/jobs/115513969996/logs` returned no content), so no "N tests / M
  assertions" figure is claimed here.
- The four independent-session race contracts remain evidenced by their own `performance_schema`
  wait-edge records as described in the 07:58Z checkpoint; this section adds no new concurrency
  claim beyond the green CI at the merge SHA.
- Schema reality on `main` today: the versioned head is **`2026_10_13_0027_custom_role_archival.php`**
  (0024 → 0025 → 0026 → 0027). No applied migration was edited.
- Operational consequence (unchanged, restated because it is now on `main`): archival is
  **terminal** — key reserved, definition and capability rows retained, no edit/assignment/
  reactivation, `active`-version retry is a reported no-op, stale-version retry refused; and
  **binary rollback to a pre-4B build while archived rows exist remains unsupported**, so
  writers must be quiesced and a backup taken before any schema maintenance.
- Gate success is exact-SHA CI closure only. It is **not** a commercial-readiness, release,
  security-audit or "feature complete for all customers" claim, and it does not start Phase 20.
