# Evidence publication boundary — field inventory

_Status: current for Round 49 (2026-11-09). Test-only diagnostic infrastructure. This document is an inventory of what the repository's evidence/diagnostic publication boundaries can and cannot publish, how each field is validated, and which tests pin that behaviour. It is not a security audit of the product, not a privacy impact assessment, and not a claim that any runtime evidence is defect-free._

## 1. What this covers

Two independent projection layers stand between raw test/diagnostic output and any audience outside the test process:

```
PHPUnit / WP test process
    │  writes raw lines (never published anywhere)
    ▼
Source-level PHP projectors (fail-closed at the emitter)
    tests/Support/BoundedEvidence.php        — CUSTOM_ROLE_{LIFECYCLE,ARCHIVAL}_EVIDENCE, HEARTBEAT_PERSISTENCE
    tests/Support/ConnectionDiagnostics.php  — CPMS_CONN_DIAG
    tests/Support/WpSqlFailureLedger.php     — CPMS_SQL_FAILURE_LEDGER{,_SUMMARY,_TABLE}
    tests/bin/multi-clinic-rest-bootstrap-probe.php — RED1_PROBE v1 events
    │  fixed-schema lines → raw job log (job log itself is never mirrored raw)
    ▼
Lint / workflow layer
    bin/integration_evidence.py              — JUnit, suite list, PR annotations, terminal summary,
                                               evidence JSON re-projection, RED-1 probe re-projection
    .github/workflows/ci.yml                 — every raw-output site redirects to /tmp captures; only
                                               bounded projections print; contract tests pin the shape
```

The workflow layer is a **second, independent** projection of the same records: a bypass of the source layer is still caught at the workflow layer, and vice versa. The `.github/workflows/test_workflow_contracts.py` suite enforces the workflow layer statically (92 tests; 1 pre-existing environment skip: no YAML library in the bare interpreter — CI installs pyyaml).

## 2. The four validation classes

Every published scalar is protected by at least one class; accounting fields by class D.

| Class | Name | Rule | Failure semantics |
|-------|------|------|-------------------|
| A | **Exact fixed vocabulary** | Value must be an exact member of a fixed set lifted from the product source or a declared protocol (stored as a `const`/`frozenset`; CI-checked for size-drift where feasible). | Field dropped (or replaced by the vocabulary's documented sentinel, e.g. `unavailable` / `other`). Never pattern-matched text passes. |
| B | **Bounded shape** | Integers (range-capped), bounded-length tokens with a fully-anchored regex, fixed-arity structures. | Field dropped; structure dropped if a mandatory sub-node fails. |
| C | **Source-verified existence** | Identifier must resolve to something real in this repository: PSR-4 path resolution + declared `function` in the target file, or membership in a checked-out-source set (the Python projector's cached set of checked-out `.php` basenames built by `rglob`, the declared-function-names set built by source scan, `KNOWN_TABLES` names fixed/derived from the migration sources). | Identifier dropped, counted by cause (`chain_dropped_unverified`, `(unknown)`, `other`). |
| D | **Accounting / drop counting** | Every drop is counted, never silent; caps force truncation (family sizes, record counts, line limits); counts themselves are bounded integers. | Absence of evidence is printed as an explicit token (`evidence_absent=1`, `junit_unavailable=1`, `testsuites_unavailable=1`, `red1_projection_unavailable=1`, `terminal_summary_printed=no/unavailable`), never as a bare silence. |

Disclaimers that are load-bearing, not decoration:

- **No class redacts.** When a value fails, it is dropped. There is no "safe-ish" rewrite, no partial masking, no message-scrubbing pass.
- **Shape is never proof.** A syntactically valid identifier can still be dropped by class C. The RED-1 probe and both chain verifiers are deliberately redundant.
- **SQLSTATE is deliberately not published** (ledger): it is a 5-char pattern-only field with no enforceable fixed vocabulary across MySQL 8 / MariaDB 11.4; `errno` + `category` carry the same diagnostic class.
- **Query fingerprints (SHA-256) are deliberately not published** (BoundedEvidence; docblock): hashing is not a privacy boundary and known-statement dictionaries can confirm a digest.
- **Exception messages are never published.** Fixed tokens, classes and basename+line only.
- **Fixture values / row contents / SQL text are never published** — canary fixtures exist in every boundary test to prove this.

## 3. Publisher inventory

### 3.1 `tests/Support/BoundedEvidence.php` (source-level, PHP)

Emits `CUSTOM_ROLE_LIFECYCLE_EVIDENCE <json>`, `CUSTOM_ROLE_ARCHIVAL_EVIDENCE <json>`, `HEARTBEAT_PERSISTENCE <json>`. Key sets are closed (`kv()` only writes listed keys).

| Field group | Fields | Validation |
|---|---|---|
| Version | `mysql_version` | B: `\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z` on the raw value (no trim on the PHP side); anything else dropped. |
| Parent/child connection identity | `connection_id`, `transaction_id`, `definition_lock_id`, `assignment_lock_id`, `requesting_lock_id`, `transaction_id`, `statement_lock_timeout_errno` | B: int, \|v\| ≤ 10^15; `intNull` allows explicit null. |
| Transaction state | `transaction_state_*`, `transaction_state_while_child_waited`, `parent_transaction_state`, `child_transaction_state` | A: `RUNNING`, `LOCK WAIT`, `ROLLING BACK`, `COMMITTING`. |
| Lock facts | `requesting/blocking_lock_id` (B), `requesting/blocking_lock_mode`, `requesting/blocking_lock_status`, `index` | A: `LOCK_MODES` (12 members), `WAITING`/`GRANTED`, `PRIMARY`. |
| Wait edge | `wait_edge.{requesting_connection_id, blocking_connection_id, requesting_lock_id, blocking_lock_id, requesting_lock_mode, blocking_lock_mode, requesting_lock_status, blocking_lock_status, table, index}` | B + A + C (table ∈ `KNOWN_TABLES`). Whole edge dropped if any sub-node fails shape. |
| Outcome / status | `child_outcome_after_parent_commit` | A-ish composition: `'assigned'` or `'denied:' + one of exactly 16 `CUSTOM_ROLE_*` reason codes (the only values `ReasonCode` can return). |
| Definition status | `definition_status_after_*` | A: `active`, `inactive`, `archived` (CustomRoleRepository vocabulary). |
| Archival race case | `case` | A: `archive_before_assign`, `assign_before_archive`, `archive_before_rename`, `archive_before_capabilities`. |
| Worker launch | `worker_mechanism`, `worker_bootstrap` | A: exactly one member each (`proc_open-independent-process`; `wp-config constants + wp-settings.php (no test installer)`). |
| Startup fixture probe / audits / counts | `startup_fixture_probe.{wp_user_rows, membership_rows, definition_rows}`, `assignment_audit_count_after`, `deactivation_audit_count_after`, `archival_audit_count`, `assignment_audit_count`, `definition_version_after_commit`, `worker_pid`, `worker_exit_code`, `parent/child_connection_id` (B), `pinned_fd_count`, `worker_socket_count`, `parent_observed_fd_count`, `pinned_fds[]` | B: int range caps; `pinned_fds` elements ≤ 65535; arrays sliced to 32 entries. |
| Descriptor isolation | `descriptor_isolation.{proc_fd, pinned_isolation_proven, worker_fds_beyond_stdio{1..3 digit fd → FD_KIND}}` | B + A: fd kinds `dev-null`, `pipe`, `socket`, `other`; fd keys `\A[0-9]{1,3}\z`. |
| Environment / privacy sentinels | `environment_allowlist_verified`, `privacy_marker_absent`, `parent_connection_id_live_on_own_session`, `parent_transaction_visible_on_own_session`, `same_wait_edge_*` | B: boolean only. |
| Heartbeat | `sql_mode` (A: every comma-separated token ∈ `SQL_MODES`, else whole field dropped), `insert/update_writer` (B int), `insert/update_php_type` (A: `NULL, integer, string, boolean, double`), `json_type` (A: 9 JSON_VALUE_TYPES) | |
| Statement block proof | `statement_block_proof.errno` | B int. (`statement_sha256` deliberately NOT published.) |

### 3.2 `tests/Support/ConnectionDiagnostics.php` (source-level, PHP)

Emits `CPMS_CONN_DIAG <json>` (`STDOUT` + `/tmp/cpms-conn-diag.jsonl`), capped at `MAX_LINES = 40`.

| Field | Validation |
|---|---|
| `ready`, `dbh_is_mysqli`, `last_error_present`, `cached_first_scalar_is_literal_1` | B bool. |
| `thread_id`, `mysql_errno`, `mysqli_insert_id` | B int (`-1` when no mysqli/dbh; `mysqli_*` never called when the handle is not mysqli). |
| `last_query` → `{verb, table}` | B+A: leading lowercase verb token; table = C: exact match or `_suffix` tail-match against `KNOWN_TABLES`, else `other`; `none`/`other` for empty/other. **SQL text itself is never emitted.** |
| `rows_affected`, `num_rows`, `insert_id`, `cached_result_rows` | B int. |
| `queryFilters()` → `{count, chain[], chain_dropped_shape, chain_dropped_unverified}` | C (source-verified): chain entries are `priority:(bare_fn\|Fqcn::method)` with priority ∈ [0, 9999]; bare fn must be declared in `src/`/`tests/`/root `.php`; `Fqcn::method` must resolve PSR-4 (`ClinicCore\ → src/`, `ClinicCore\Tests\ → tests/`) to a file inside the tree with the method declared. `closure#<3-digit id>` allowed as a bounded int token. Chain capped at 12; drops counted by cause (shape vs unverified). |
| `tableFacts()` / `visitStatusFacts()` (independent connection) | C: short table must be exact member of `KNOWN_TABLES`, else `table='none'` + all facts `-1`. Numeric results only; never row values. |

### 3.3 `tests/Support/WpSqlFailureLedger.php` (source-level, PHP)

Emits `CPMS_SQL_FAILURE_LEDGER_BEGIN`, `CPMS_SQL_FAILURE_LEDGER <json>`, `CPMS_SQL_FAILURE_LEDGER_SUMMARY <json>`, `CPMS_SQL_FAILURE_LEDGER_TABLE <json>`, `CPMS_SQL_FAILURE_LEDGER_END`. `MAX_SIGNATURES = 60` (distinct fingerprints; excess counted in `dropped`), `MAX_PRINTED = 14` (printed signature lines per flush; the rest are only counted).

| Line / field | Validation |
|---|---|
| `BEGIN` counters `failures=`, `distinct=`, `dropped=`, `ezsql=` | B int. |
| `category` | A: `duplicate_entry`, `foreign_key`, `missing_table`, `lock`, `check_constraint`, `other` (classified from the driver message, which itself is never printed). |
| `op` | A: `INSERT, UPDATE, DELETE, REPLACE, SELECT, CREATE, ALTER, DROP, START, COMMIT, ROLLBACK, OTHER`. |
| `table` | C: captured identifier must resolve (exact or `_suffix` tail match) to the 3-member `KNOWN_TABLES` (`cpms_custom_role_defs`, `cpms_custom_role_capabilities`, `cpms_clinics`); else `unknown`. |
| `mysql_errno` | B int 1..4294967295; else `unavailable`. (SQLSTATE deliberately absent.) |
| `test`, `product` | C: `Class::method` resolved PSR-4 + declared method; anything else (incl. canary identifiers) → `(unknown)`. `product` falls back to source-verified `Namespace\Class::method`, else `(unknown)`. |
| `seq`, `count` | B int ≥ 0 / ≥ 1. `focus` bool. |
| `SUMMARY.by_category` | A keys + B counts; `printed`, `kept`, `dropped` B ints. |
| `TABLE` facts | C + B: `table` (3-member set), `real_exists`, `name_visible` bool; emitted only when a mysqli handle is present and only for the 3 known tables. |

### 3.4 `tests/bin/multi-clinic-rest-bootstrap-probe.php` (source-level, PHP)

Emits `RED1_PROBE v1 <key=value…>` fixed-schema events + `register_shutdown_function` fatal handler. Never prints messages, ids, bodies or argv.

| Event | Fields | Validation |
|---|---|---|
| `start` | — | Fixed literal. |
| `step` | `step` — literal S1..S8 at the call site; `result` ∈ literal `pass`/`fail`; on fail: `class` = short exception-class basename matching `\A[A-Za-z_][A-Za-z0-9_]{0,79}\z` else `Error`; `file` = basename matching `\A[A-Za-z0-9_.-]{1,80}\z` else `unknown`; `line` B int. (Shape-only at the probe; the workflow projector re-validates with class C — see §3.5.) |
| `http` | `step` literal S1..S8; `status` B int (call site) — projector re-checks 100–599; `code` ∈ A — **exact member of the same 23-word vocabulary as §4 (`$allow` array, byte-identical list), else `unavailable`** |
| `outcome` | `verdict`/`reason` — literals at the call site; projector re-validates: verdict ∈ A {`pass`,`red`,`invalid`}; reason (invalid only) ∈ A {`precondition`,`plugin_file`,`autoload`,`test_library`,`core`,`database`,`bootstrap`,`migration`}; `steps`/`passed`/`failed` B ints; `first_failure` — literal S1..S8 |
| `fatal` | `class` — fixed by the shutdown handler (`ParseError`/`CompileError`/`Error`); `file` = basename `\A[A-Za-z0-9_.-]{1,80}\z` else `unknown`; `line` B int |

### 3.5 `bin/integration_evidence.py` (workflow-level, Python)

Independent second projection for anything the workflow publishes. Reads at most 20 000 lines; never echoes raw content; every unknown/unsafe value becomes a documented sentinel or the line is dropped with an accounting token. `MAX_IDENTIFIERS=20`, `MAX_SUITES=250`, `MAX_RECORDS=10`, `MAX_CHAIN=12`, `MAX_WALK_LEAVES=40`.

| Sub-command / line | Fields | Validation |
|---|---|---|
| `junit --label L [--phpunit-exit N] [--log F]` → `stage=… tests=… assertions=… failures=… errors=… skipped=…` (+`phpunit_exit=N`) | label B: `[a-z0-9_.-]{1,80}` fullmatch (else the fixed default `integration`); counts B ints parsed from JUnit attributes (invalid → `?`/absent; non-int source chain counters ignored); `phpunit_exit` int |
| `… failing_tests_capped_20=<Class::method;…>` | C: each ident PSR-4-resolved + method declared in the file, provider suffix stripped; unverifiable → per-row `(unknown)`, skipped for `::error::`; at most 20 unique idents |
| `… error_categories=<k=v>` | A: category ∈ PHP/PHPUnit exception vocabulary ∪ source-declared product classes (else `other`); counts B ints |
| `suites` → `testsuite name=… tests=… assertions=…` | B: name `\A[A-Za-z0-9_/\\.-]{1,200}\Z` **and** either `default`, a path that resolves inside the plugin tree to a `.php` file, or a class name verified via PSR-4+declared class; else the suite line is dropped; names never absolute paths from the runner |
| `annotate` → `::error::<Class::method>` | C (as `failing_tests_capped`); source-verified idents only; offset/limit cap 20 |
| `terminal` → `terminal_summary_printed=yes\|no\|unavailable` | B: fixed 3-token result; the log is scanned line-wise, never printed |
| `evidence <prefix> log` → re-projected record/line | C+A: records capped at 10; each projector re-validates every field exactly as the source layer does; `mysql_version` B dotted-int only (§5); `_chain` re-verified (§5); drop accounting merged **once per stage** (source ints + this stage's ints, totals emitted only when > 0; `MAX_CHAIN=12` slice precedes counting); failure → `record_dropped=invalid_json\|schema_mismatch`, zero records → `evidence_absent=1` |
| `conn-diag` | Pass-through of the `CPMS_CONN_DIAG ` prefix through the evidence machinery with `CPMS_CONN_DIAG` projector |
| `red1-probe log` → `red1_step…`, `red1_http…`, `red1_outcome…`, `red1_fatal_event…`, `red1_php_fatal=detected` | A: step ∈ {S1..S8}; verdict ∈ {pass,red,invalid}; reason ∈ 8 INVALID_REASONS; code ∈ `REST_CODES` (§4); B: status 100–599, ints; C: exception class via source-declared classes ∪ PHP/PHPUnit builtin list, file basename must exist in the checked-out tree; unverifiable classes → `other`, unverifiable files → `unknown` |
| Any crash | `evidence_projection_error=1` (fail-closed; never a traceback, never partial data) |

### 3.6 Workflow layer (`.github/workflows/ci.yml` + contracts)

Static guarantees pinned by `test_workflow_contracts.py` (the workflow is the *last* place raw output could escape):

- No step may print a raw log to stdout: phpunit/composer/probe invocations redirect to `/tmp/*.log`; `tee` in library paths is prohibited; `install-wp-tests.sh` output redirected; `2>&1 |` pipes to stdout prohibited (`2>&1 ||` idiom allowed).
- A step that runs PHPUnit and exits non-zero must have a bounded projector invocation **before** the failure exit in the same step block (`junit`/`suites`/`evidence`/`terminal` token or an inline `python3 - <<` heredoc).
- PR mirrors are assembled only from projector output; the raw log never reaches a `pr-evidence-comment.sh` body.
- The RED-1 probe step publishes only `red1-probe` projection; the probe source must itself emit the fixed schema (`RED1_PROBE v1 `, fatal handler, INVALID-exit reason tokens) — this is asserted against the source text.

## 4. `REST_CODES` — the 23-word REST envelope allowlist (decision)

`bin/integration_evidence.py::REST_CODES` is a `frozenset` of exactly **23** members; `multi-clinic-rest-bootstrap-probe.php::$allow` is the same list (kept in sync by hand; CI fails closed on any word outside the intersection — the projector re-validates whatever the probe prints).

WordPress core (7): `rest_no_route`, `rest_invalid_param`, `rest_missing_callback_param`, `rest_invalid_handler`, `rest_forbidden`, `rest_not_logged_in`, `rest_forbidden_context`.

Product booking/scope envelope, source-verified against `clinic-practice-management/src` (16): `CLINIC_SCOPE_REQUIRED`, `CLINIC_SCOPE_UNAVAILABLE`, `CLINIC_NOT_FOUND`, `CLINIC_PERMISSION_DENIED`, `CLINIC_UNAUTHORIZED`, `CLINIC_VALIDATION_FAILED`, `CLINIC_INTERNAL_ERROR`, `CLINIC_HOLD_EXPIRED`, `CLINIC_POLICY_VIOLATION`, `CLINIC_PATIENT_SELECTION_REQUIRED`, `CLINIC_INVALID_TRANSITION`, `CLINIC_SLOT_AMBIGUOUS`, `CLINIC_DUPLICATE_IN_FLIGHT`, `CLINIC_SLOT_TAKEN`, `CLINIC_SUSPENDED`, `CLINIC_DUPLICATE_APPOINTMENT`.

**The list is deliberately not exhaustive.** The wire path is generic passthrough — `RestBase::error()` and `BookingController::wrap()` forward the `BookingException::errorCode()` / `ScopeRequiredException::apiCode()` string straight into the `WP_Error` code — so hundreds of product codes are reachable in principle, and no finite allowlist can or should enumerate them. The boundary here is scoped to what the RED-1 probe and the integration evidence actually surface (WP-core routing/auth failures + the booking/scope envelope classes that are source-verified today). **Any code outside the 23 projects as `unavailable` (probe) / is dropped (projector).** This is fail-closed by design: a reachable-but-unlisted code loses diagnostic granularity, never safety. Expanding the vocabulary is a deliberate, reviewed act — a new word joins the list only after its raising site is source-verified, and `RestCodesSourceDerivationTest` enforces (a) exact set size 23 and (b) every `CLINIC_*` member occurs in `src/`.

## 5. Cross-layer consistency rules

| Vocabulary / rule | Copies | Drift consequence |
|---|---|---|
| `REST_CODES` (23) | `integration_evidence.py`, probe `$allow` | Probe prints only listed words; projector re-enforces → listed word missing from projector: printed, then projected as `unavailable`. Fails closed. |
| `KNOWN_TABLES` | BoundedEvidence (full migration list), ConnectionDiagnostics (full list), WpSqlFailureLedger (3-member business subset) | Divergent copies are intentional and documented; the ledger publishes only the 3 tables its tests exercise. Table must be in the *publishing* copy or it is `other`/`unknown`. |
| Chain verification | `ConnectionDiagnostics::queryFilters()` (PHP, source of truth for emission), `integration_evidence.py::_chain()` (Python, re-verifies at publication), prioritise `closure#N`, PSR-4 `Fqcn::method`, bare fn | Priority outside [0, 9999] → shape drop; unverified → `chain_dropped_unverified`; both layers count drops independently (projector merges its own stage's counts with the source's, emitted only when total > 0). |
| `mysql_version` | BoundedEvidence `version()` and `integration_evidence.py::_version()` | Dotted-integers only (`[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}` fullmatch); the PHP side requires the raw value to match (no trim), the Python side strips first and publishes the stripped value. Distribution-suffixed versions (`8.0.46-0ubuntu0.24.04.1`, `11.4.13-MariaDB`) are dropped, never truncated to a lookalike. |
| `::error::` annotations | `annotate` | Only source-verified `Class::method`; unverifiable idents produce no annotation (bounded count, ≤ 20). |
| Terminal summary | `terminal` | Fixed 3-token answer; absence of a terminal summary in a completed log is what the CI completion guard keys on (premature-exit protection). |

## 6. Test map (what pins what)

| Layer | Test suite | Pins |
|---|---|---|
| Source-level PHP projectors | `tests/Unit/Support/BoundedEvidenceTest.php` (79 canary cases), `tests/Unit/Support/ConnectionDiagnosticsTest.php` (chain source-verification, shape/unverified drop accounting, bounded return shape, mysqli guards), `tests/Unit/Support/WpSqlFailureLedgerTest.php` (canary exclusion, signature caps, `(unknown)` identifier semantics, single-line JSON, no driver text) | Fixed vocabularies, drop accounting, canary non-publication |
| Integration (real DB) | `tests/Integration/WpSqlFailureLedgerIntegrationTest.php` | Ledger boundary exercised against the real MySQL service in CI (skipped=0 required by the ledger gate step) |
| Workflow-level Python projector | `bin/test_integration_evidence.py` (83 tests) — incl. `VersionProjectionTest`, `ChainVerifierTest`, `RestCodesSourceDerivationTest` (this round) | Dotted-int version gate incl. suffixed-version drops; chain OK/drop accounting incl. projector-stage counter merge; exact-set-23 REST_CODES guard + source-occurrence of every `CLINIC_*` member + unlisted codes dropped; JUnit/suite/annotation/red1 projections; canary exclusion |
| Workflow YAML contract | `.github/workflows/test_workflow_contracts.py` (92 tests) — incl. the Integration-job raw-output rules rewritten this round to line-continuation-aware logical lines | No raw output path from any step; bounded projector before every failure exit; RED-1 probe publication rules; rc-var failure-exit coverage |
| Probe source contract | `test_workflow_contracts.py::test_red1_probe_source_emits_fixed_schema_lines_and_fatal_handler` | Probe keeps fixed-schema emission + fatal handler + reason-token vocabulary |

## 7. Known limits (explicit)

- Source-verified means "present in this repository", not "safe" — a declared-but-buggy function still passes. That is intentional: the boundary filters *identity*, not *quality*.
- `closure#N` identifiers carry no identity; they exist so a non-zero chain length is not itself a canary signal.
- The two layers can disagree on a drop cause (PHP says shape, Python says unverified); each counts its own stage and the projector merges once; per-stage attribution is preserved in the emitted counters only in aggregate form.
- `rest_*`/`CLINIC_*` vocabulary is not exhaustive (§4) — `unavailable` is a common and correct result for unlisted codes.
- Projectors run on CI runners; raw logs live and die there. This document inventories *publication*, not the existence of logs.
- php-wasm runs of the Unit suite prove logic, not mysqli behaviour; mysqli-touching paths in CI run on native PHP (ubuntu-latest + setup-php). Local php-wasm results are never claimed as native results.
