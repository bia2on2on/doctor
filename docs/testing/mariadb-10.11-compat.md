# MariaDB 10.11 compatibility lane

## Purpose and evidence boundaries

The scheduled/manual workflow `.github/workflows/mariadb-10.11-compat.yml` is a separate, database-backed CPMS compatibility lane. It installs WordPress 7.1.3, activates the release ZIP built from the checked-out SHA against the pinned MariaDB image, verifies the migration head, then runs a focused WP Test Suite integration selection against that real MariaDB server.

This is **not** the MariaDB 11.4.13 browser-acceptance workflow and is **not** the existing MySQL 8 required integration check. It has only `schedule` and `workflow_dispatch` triggers: no new routine PR check, no required-check name change, and no alteration of the existing PR-check architecture. The new `tests/Compatibility/MariaDb1011CompatibilityTest.php` is invoked explicitly by this lane and is outside the normal `tests/Integration` directory discovery.

## Reference baseline (checked 2026-10-17)

- WordPress currently recommends PHP 8.3+ and MariaDB 10.11+ or MySQL 8.0+: [WordPress Requirements](https://wordpress.org/about/requirements/).
- MariaDB lists the 10.11 community-maintenance end date as 2028-02-16: [MariaDB maintenance policy](https://mariadb.org/about/#maintenance-policy).
- MariaDB announced 10.11.19 as its Q3 2026 10.11 maintenance release: [Q3 2026 maintenance releases](https://mariadb.org/mariadb-server-12-3-11-8-11-4-and-10-11-q3-2026-maintenance-releases-and-goodbye-10-6/).
- The pinned OCI index digest and platform manifests were read from the [official Docker Hub Library/MariaDB 10.11.19 tag API](https://hub.docker.com/v2/repositories/library/mariadb/tags/10.11.19).
- MariaDB documents JSON as a `LONGTEXT` alias with an automatic `JSON_VALID` CHECK, unlike MySQL's native JSON representation: [MariaDB JSON data type](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).
- MariaDB documents CHECK enforcement and the `check_constraint_checks` switch: [CONSTRAINT / CHECK constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint).
- For InnoDB, MariaDB documents that `SELECT ... FOR UPDATE` takes effect inside a transaction (or with autocommit disabled): [FOR UPDATE](https://mariadb.com/kb/en/for-update/).

The lane therefore uses PHP 8.3, WordPress 7.1.3, and the immutable `linux/amd64` MariaDB child-manifest reference `mariadb:10.11.19@sha256:bb27bde261794d326398a9c998ddf9f35db45296d829123bff829cd076f68107`. Docker Hub's official Library/MariaDB tag API reported that the `10.11.19` tag was last pushed at `2026-10-17T05:35:32.829517Z`, with OCI index digest `sha256:0d0945c57f75b3a3b9ebe71aedaaad005fa5305054e8fca3d2e42a8c92b43b5e` and this `amd64/linux` child digest. Pinning the platform-specific manifest avoids relying on the mutable tag or a host-dependent index selection. On every run, a runtime query must return a 10.11.19 MariaDB server identity; a different MariaDB patch, MySQL, or a proxy with a different version fails before installation/tests.

## Covered contracts

### Real WordPress installation and schema lifecycle

- Install an official WordPress 7.1.3 core distribution into a fresh runner directory and a dedicated `utf8mb4` database.
- Build CPMS's release ZIP from the checked-out SHA; use WP-CLI to install and activate that ZIP (not a source mount).
- Verify WordPress installation, plugin activation, migration version `2026_10_13_0027`, and a repeated `migrate()` no-op.
- Run `MigrationTest`, `CustomRoleArchivalMigrationTest`, and `CustomRoleDisplayNameMigrationTest` against the separate WP Test Suite database on the same MariaDB runtime. These exercise upgrade/repeated migration safety, failed-migration/version-recording behavior, archival CHECK guard metadata and disabled-check fail-closed handling, and utf8mb4 display-name preservation.

### Database behavior and domain boundaries

The focused real database suite additionally selects:

- `MariaDb1011CompatibilityTest`: MariaDB 10.11 identity; MariaDB JSON alias metadata; actual `JSON_VALID` CHECK rejection (MariaDB error 4025); valid JSON and non-BMP Unicode round-trip over WordPress's utf8mb4 connection.
- `ConcurrencyTest`, `CustomRoleArchivalConcurrencyTest`, `CustomRoleLifecycleTest`, and `VisitConcurrencyTest`: independent connection/process concurrency and live locking / `SELECT ... FOR UPDATE` paths where the existing harness supports them.
- `CustomRoleRepositoryTransactionTest`, `CustomRoleAuditAtomicityTest`, and `FinanceClinicIsolationTest`: transaction/rollback atomicity and failed-write behavior.
- `ClinicTenantIsolationTest`, `PatientIdentityFoundationTest`, and `ClinicalFilesScopedAuthorizationTest`: Organization/Clinic boundaries, patient identity linkage, clinical-record/file visibility, and cross-tenant denial.
- `FinanceAuthorizationPolicyRegressionTest` and `FinanceClinicIsolationTest`: financial authorization, transaction ownership, clinic scoping, and rollback/audit behavior.
- `BackupEngineTest` and `BackupMultiClinicFilesRestoreGreenTest`: application backup generation/integrity/preflight, the non-destructive `restoreApply(..., false)` confirmation guard, and safely isolated multi-clinic file restoration. `BackupMultiClinicFilesRestoreGreenTest` invokes the private file-restoration helper by reflection; it does not call the public destructive `restoreApply(..., true)` entry point.

A separate workflow step also uses `mariadb-dump --single-transaction` to copy the synthetic WordPress/CPMS database into a new disposable MariaDB database, imports that copy, and checks migration/table state. It does not run a destructive restore against the installation database. No confirmed/destructive `restoreApply(..., true)` is invoked by this compatibility lane. Do not infer public application-level table replacement from the safe clone import or the confirmation-guard/file-helper tests.

## Observed pre-merge run (2026-10-17)

To obtain pre-merge execution, a `pull_request: branches: [main]` trigger was temporarily added to this workflow in commit `08c1aac0007a1fbea98aa8d1990990dc11dddc44`. GitHub scheduled workflow run **39012015265** on that exact PR head; the run concluded **failure**. The temporary trigger is removed in the final branch state; only `schedule` and `workflow_dispatch` remain.

Observed through the GitHub Actions job/step API:

- Service container initialization, PHP 8.3 setup, MariaDB client/WP-CLI install, release ZIP build, clean WordPress installation and CPMS ZIP activation, migration-head/repeat-migrate verification, safe dump/import clone, and PHPUnit 9.6 installation all completed successfully.
- The fail-closed identity step completed successfully. Its assertions require `VERSION()` to begin `10.11.19-MariaDB`, `@@version_comment` to contain MariaDB, and session CHECK enforcement to be `1` or `ON`. This establishes the version family/prefix and gates passed, but the exact version suffix/comment text could not be recovered from the run logs/artifact.
- Step **“Run focused database-backed CPMS compatibility suites”** failed with exit code 1. The command specified all 18 suite files below, and the evidence-upload step succeeded (artifact `mariadb-10.11-compat-39012015265-1`, 4,093 bytes). The PHPUnit/JUnit artifact and full step logs could not be downloaded in this sandbox: GitHub log retrieval returned EOF from `results-receiver.actions.githubusercontent.com`, and artifact download returned EOF from `productionresultssa6.blob.core.windows.net`.
- Subsequent independent diagnosis supplied for this task established that PHPUnit 9.6 treated the 18 positional paths as a single test target and actually ran only the first file: **2 test cases / 20 assertions**. The JUnit report used dot-separated `classname` values, while the old gate split only on backslashes. This is the independently reported root cause; the original log/JUnit artifact is still not retrievable in this sandbox (log and artifact endpoints return EOF), so I could not inspect its raw XML or independently verify its individual failure/error/skip counts.
- The old run's reported 2 cases are far below the required 120, and the single-file execution failed the intended all-classes completeness requirement. The historical test-level failure/error/skip counts and outcome of any individual assertion remain unavailable. Do not claim that JSON/CHECK, utf8mb4, migration-suite, concurrency, tenant/clinical, finance/ownership, or backup tests passed. No test was intentionally skipped or gate bypass introduced, but the positional invocation failed to select 17 intended files.
- No confirmed/destructive `restoreApply(..., true)` was part of this lane. The separate repository Closure Gate is unrelated to this MariaDB run.

The intended selection contains these 18 files: `MariaDb1011CompatibilityTest.php`, `MigrationTest.php`, `CustomRoleArchivalMigrationTest.php`, `CustomRoleDisplayNameMigrationTest.php`, `FirstPracticeSetupTest.php`, `ClinicTenantIsolationTest.php`, `PatientIdentityFoundationTest.php`, `ClinicalFilesScopedAuthorizationTest.php`, `FinanceAuthorizationPolicyRegressionTest.php`, `FinanceClinicIsolationTest.php`, `CustomRoleRepositoryTransactionTest.php`, `CustomRoleAuditAtomicityTest.php`, `ConcurrencyTest.php`, `VisitConcurrencyTest.php`, `CustomRoleArchivalConcurrencyTest.php`, `CustomRoleLifecycleTest.php`, `BackupEngineTest.php`, and `BackupMultiClinicFilesRestoreGreenTest.php`. PHPUnit's positional argument list did **not** execute all 18 in the failed run.

## PHPUnit discovery and evidence-reporting correction

- `phpunit.mariadb-10.11.xml` now defines the 18 selected paths as explicit `<file>` entries in one PHPUnit 9.6 configuration. The lane passes only this single `--configuration`; it no longer passes 18 positional file paths.
- `tests/bin/mariadb_compat_report.py` normalizes both dot- and backslash-separated JUnit class names, validates the configuration against the exact 18-file manifest, checks declared JUnit counts against testcase records, and fails closed for missing/malformed/inconsistent XML, missing expected classes, fewer than 120 cases, a nonzero PHPUnit exit, failures, errors, or skips.
- Its `if: always()` step writes a tightly sanitized GitHub step summary containing the allowlisted failure category, PHPUnit exit code, test/assertion/failure/error/skip counts (or explicit `UNKNOWN`), executed/missing expected class names, and the first safely extractable failing test identifier. It emits exactly one bounded GitHub workflow-command annotation with the same core fields. Raw PHPUnit output, JUnit failure messages, WordPress debug logs, SQL, credentials, and fixture values are excluded from the summary, annotation, and uploaded JSON; the artifact otherwise contains only bounded non-test metadata.
- `tests/bin/test_mariadb_compat_report.py` now has **16** focused regression tests, including PHPUnit failing before JUnit is written, JUnit failure/error records, malicious XML text, missing expected classes, annotation escaping, and nonzero PHPUnit exit despite clean JUnit. Its output is captured privately in the workflow and scanned for workflow-command syntax before the step can pass. These are reporter/selection validation only, **not** database compatibility evidence.
- For one controlled diagnostic execution, `pull_request: branches: [main]` was temporarily enabled only in this MariaDB workflow, preserving schedule/manual triggers; it was removed again immediately after that single run. The final workflow is schedule + `workflow_dispatch` only.

## Corrected pre-merge attempt (2026-10-17)

Commit `ec499f01007225fdc08627bb0a21089be812173b` contained the XML selection, dot/backslash reporter, sanitized always-run summary, regression tests, and temporary PR trigger. GitHub scheduled run **39014819145** on that exact SHA; it concluded **failure** (job `117344646142`).

- These steps passed: runtime identity gate; WordPress 7.1.3 installation and CPMS release ZIP activation; migration head/repeated no-op; safe clone import; PHPUnit 9.6 installation; and focused selection/reporter regression validation.
- “Run focused database-backed CPMS compatibility suites” and “Publish sanitized JUnit summary and enforce completeness” both failed with exit code 1. The subsequent artifact-upload step and separate “Compatibility run summary” step completed successfully. The run artifact `mariadb-10.11-compat-39014819145-1` (1,917 bytes) was uploaded; the reporter-step summary contents could not be retrieved for inspection.
- The check-run API provides only generic exit-code annotations. `gh run view --log-failed` again returned EOF from `results-receiver.actions.githubusercontent.com`; artifact ZIP retrieval returned EOF from `productionresultssa2.blob.core.windows.net`; the GitHub check-run output has no embedded step-summary text. Therefore the sanitized summary's numeric contents and the PHPUnit/JUnit payload could not be retrieved in this sandbox. We cannot establish whether PHPUnit executed any cases on this attempt, the test/assertion counts, or whether the failure arose in PHPUnit config/runner, test assertions, or the completeness reporter. Do not classify it as a product failure or a compatibility pass without those details.
- No speculative patch or retry followed. Per task constraints, the temporary PR trigger was removed, preserving only schedule and `workflow_dispatch` before stopping. The actual case-level result remains **unresolved** and MariaDB 10.11 acceptance is **not established**.

## One controlled observability run (2026-10-17)

The sanitized reporter/annotation changes and temporary trigger were pushed together in commit `104f5c160abfc29cdb734cf96fa4b8eae531d069`. Exactly one MariaDB workflow run was observed for this diagnostic change: [run 39017190427](https://github.com/bia2on2on/doctor/actions/runs/39017190427), event `pull_request`, on that exact SHA and PR #238. It concluded **failure**; job/check-run ID `117351636814` ran from `2026-10-17T08:23:43Z` through `2026-10-17T08:25:22Z`.

- **Passed:** container initialization; PHP 8.3/MariaDB client/WP-CLI setup; the fail-closed MariaDB image/version/CHECK identity gate; release ZIP build; clean WordPress 7.1.3 install and CPMS activation; migration-head/repeat-migrate check; safe database clone import; PHPUnit 9.6 setup; and all 16 local reporter/selection regressions (with no GitHub workflow-command pattern emitted by their captured output).
- **Failed:** the focused database-backed PHPUnit step returned exit code `1`. At the always-run reporter step, the JUnit file was absent. The completeness/report step also returned failure, as required. The upload and workflow-summary steps completed.
- **Measured diagnostics:** the GitHub Checks REST annotations endpoint for check-run `117351636814` returned one custom, bounded failure annotation titled `MariaDB 10.11 sanitized PHPUnit diagnostic`, in addition to generic step-exit annotations and platform notices. The exact custom message was: `category=junit_missing+test_count_unknown+class_inventory_unknown+phpunit_nonzero_exit; exit=1; tests=UNKNOWN; assertions=UNKNOWN; failures=UNKNOWN; errors=UNKNOWN; skips=UNKNOWN; classes=UNKNOWN/18; executed=UNKNOWN; missing=UNKNOWN; first=UNKNOWN`. This confirms the annotation is retrievable through Checks REST, and shows that no case-level result was measurable because JUnit was missing.
- **Not measured / unresolved:** actual test cases, assertions, failures, errors, skips, executed or missing expected class names, and first failing identifier are all `UNKNOWN`. The exact reason PHPUnit exited `1` and did not leave JUnit XML cannot be classified from the sanitized evidence; no product-code or fixture diagnosis is justified. No raw logs/JUnit payload were used to guess a cause, no application code/assertions were changed, and no retry was made. This run is **not a MariaDB compatibility pass**.
- This lane still does not call the destructive public `restoreApply(..., true)` entry point. The separate Closure Gate's destructive restore check is unrelated evidence; the selected multi-clinic test uses reflection on a file helper.
- Immediately after this sole diagnostic run, the temporary `pull_request` trigger was removed again. The final checked-in workflow has only `schedule` and `workflow_dispatch`; PR #238 remains Draft and unmerged.

## Explicit limits

- The scheduled lane is focused, not the full Integration directory or a duplication of the existing PR matrix.
- This is not a MariaDB 10.11 browser/E2E acceptance run. Existing MariaDB 11.4.13 browser acceptance remains its own evidence stream.
- The original discovery attempt, corrected attempt, and both controlled observability attempts all failed before establishing a full MariaDB 10.11 compatibility result; none is a **compatibility PASS**. The immutable image is MariaDB 10.11.19 and the runtime identity gate passed in all four runs. Independent diagnosis identified the original 2-test/20-assertion discovery defect. The corrected attempt's counts remain unretrieved; the first observability attempt reported missing JUnit with unknown case-level counts, and the final bounded diagnostic located the failure before integration-bootstrap entry but still reported an unknown cause and unknown case-level counts.
- MariaDB JSON is text-backed, not MySQL's binary JSON. The lane is not cross-engine physical JSON dump compatibility evidence.
- The automated dump/import is a same-engine, synthetic-data clone, not a production backup restore or a destructive application restore drill.

## Run cost estimate

A successful scheduled run is estimated at **15–25 runner-minutes per week**; this has not been measured. The first two failed attempts lasted about 1m 15s each; the first controlled observability run lasted 1m 39s and the final bounded bootstrap diagnostic 1m 25s, both stopping at the focused-test/reporting stage. None is a successful-run cost measurement. One scheduled run creates one standard `ubuntu-latest` job and one short-lived MariaDB service. The repository is public and standard GitHub-hosted runners are free for public repositories under GitHub's published policy: [GitHub Actions billing and usage](https://docs.github.com/en/actions/concepts/billing-and-usage). This is runner usage, not a promise about external artifact/network/storage charges.

## Final bounded PHPUnit bootstrap diagnostic — 2026-10-17

### Authorization and static trace

- **Measured authorization receipt:** `2026-10-17T08:54:15Z`; safe stop approximately `09:49:15Z`, hard stop `09:54:15Z`. Repository `bia2on2on/doctor`, fixed branch `arena/c2bb7f15-doctor`. At intake PR #238 was open/Draft/unmerged at `d009d54c0517cb4fda5080bc25a137b906c2a3fb`, base `176e854c7ff92b052fcc3c9d2ea4af7281b74a76`. Ruleset `22393801` remained active with the same nine required contexts.
- Rechecked the exact prior-failure revisions before the diagnostic: on failed-run SHA `104f5c160abfc29cdb734cf96fa4b8eae531d069`, the bootstrap blob was `2f1bb6d15e7bade4b6414a6586bfd78f3e63d7a6` and the PHPUnit XML blob was `297d50d75b1b40e189e56d459a2b61bfdbab02cf`; those blobs matched the trigger-restored baseline at `d009d54`. The new diagnostic changed only test bootstrap/reporting/tests and the temporary trigger; the approved selection XML remained unchanged.
- Static trace completed before the diagnostic run. `tests/bin/install-wp-tests.sh` requires curl/tar/mysql, obtains the WordPress 7.1.3 test-library/config sample, creates the dedicated test database and writes its test config. WordPress 7.1.3's test bootstrap requires readable `wp-tests-config.php`, PHPUnit at least 5.7.21, PHPUnit Polyfills at least 1.1.0, and its required test constants; it can exit on those checks or on a nonzero `install.php` subprocess. The selected run uses PHPUnit 9.6, and the installation/selection steps passed. With `WP_TESTS_SKIP_INSTALL` unset, `install.php` selects the configured test DB, drops WordPress tables under the test prefix, and calls `wp_install()`; the test `wp_die` handler exits 1. This is a reset of the isolated test database, not a public application restore.
- After that subprocess, the test bootstrap loads WordPress core (`wp-settings.php`) and test helpers, then the plugin is loaded on `muplugins_loaded`. `App::boot()` registers plugin hooks/services and migration callbacks; `tests/integration-bootstrap.php` subsequently registers the private SQL ledger and calls `App::migrations()->migrate()` directly. `MigrationRunner` ensures its schema table, runs unapplied migrations in strict transactional mode, and throws on failure. No product/application behavior was modified.
- Added CI-only fixed stage/category markers around the integration bootstrap, WP test-library checks/bootstrap, plugin load, SQL ledger, migrations, and bootstrap completion. The reporter accepts only exact allowlisted tokens; it never reads PHPUnit stdout/stderr. Raw PHPUnit output, JUnit, SQL ledger text, exception messages, raw paths, credentials, and PHI remain private/unpublished. Missing/malformed markers, missing JUnit, nonzero PHPUnit, or a missing completion marker remain red; a marker cannot satisfy or override any existing selection/assertion gate.
- Local Python reporter/selection suite passed **26 tests**. Python compilation, Actionlint WASM, `bash -n` for all 11 workflow run blocks, `git diff --check`, and a PHP 8.1 grammar parse of the changed bootstrap passed. The grammar parse is not PHP execution or `php -l`; PHP, Composer, and a capable MariaDB environment are unavailable locally.

### Sole additional real MariaDB run and REST evidence

- Temporarily enabled a path-limited `pull_request` trigger for only `.github/workflows/mariadb-10.11-compat.yml`; it retained schedule/dispatch while enabled. The one and only additional MariaDB 10.11.19 run was [39020031044](https://github.com/bia2on2on/doctor/actions/runs/39020031044), attempt 1, `pull_request`, exact head `77762f925710c4eb1dbb5d285ef22678c70ba604`; job/check-run `117359841645`. It started `2026-10-17T09:09:10Z`, completed `09:10:35Z`, and concluded **failure**. No retry or second MariaDB run was started.
- REST job-step evidence: container/service setup, PHP 8.3, database identity, release build, clean WordPress 7.1.3 installation and CPMS activation, migration-head/repeat check, isolated clone import, PHPUnit 9.6 install, and the reporter-selection regression step all passed. Focused PHPUnit and its JUnit/completeness reporter failed; evidence upload and final summary steps passed.
- `GET /repos/bia2on2on/doctor/check-runs/117359841645/annotations` returned the sanitized custom annotation (title `MariaDB 10.11 sanitized PHPUnit diagnostic`): `category=junit_missing+test_count_unknown+class_inventory_unknown+phpunit_nonzero_exit+startup_failure+startup_completion_missing; startup_stage=PHPUNIT_BEFORE_BOOTSTRAP; startup_category=UNKNOWN_STARTUP_FAILURE; exit=1; tests=UNKNOWN; assertions=UNKNOWN; failures=UNKNOWN; errors=UNKNOWN; skips=UNKNOWN; classes=UNKNOWN/18; executed=UNKNOWN; missing=UNKNOWN; first=UNKNOWN`.
- The wrapper's fixed `PHPUNIT_PROCESS_STARTED` marker was present, but no `INTEGRATION_BOOTSTRAP_ENTRY` marker was observed. Therefore the first identified boundary is **before the integration bootstrap**, with category **`UNKNOWN_STARTUP_FAILURE`**. This does **not** identify why the process exited 1; it cannot distinguish a PHPUnit startup/configuration/loader problem from another failure before the bootstrap entry. JUnit is missing; actual test/assertion/failure/error/skip counts, class inventory, and first test are all `UNKNOWN`. This is not a MariaDB compatibility pass and is not evidence that any selected database test ran.
- No raw log, JUnit, exception, SQL, or first-N output was inspected or published. The exact cause remains unresolved; no product fix is justified. The run used the temporary-trigger SHA above, not the final trigger-restored SHA, so its failure is evidence for that exact SHA only.

### Stop decision and remaining blockers

- **Recommendation:** defer MariaDB 10.11 compatibility sign-off and any product fix until a capable local environment reproduces PHPUnit startup with the same PHP 8.3 / PHPUnit 9.6 / WordPress 7.1.3 test-library inputs and MariaDB 10.11.19. Diagnose from private local process output, then seek fresh authorization before any further CI experiment. This completes the one-run limit; do not retry or start another MariaDB run in this mission.
- Blockers: the first failure is still before `integration-bootstrap.php`; JUnit and all case-level evidence remain absent; this sandbox has no PHP/Composer/MariaDB for local reproduction. The lane did not call public destructive `restoreApply(..., true)`; the Closure Gate's destructive check is separate.
- Restored the workflow to `schedule` + `workflow_dispatch` only before stopping. PR #238 remains open/Draft/unmerged; required check names/ruleset and Owner-hosting/security controls were untouched. No Phase 20 work was performed. The final restored workflow and evidence notes are saved on the fixed branch; remote PR/check state is verified before handoff.

## PHPUnit startup root cause and controlled run — 2026-10-17 (session 3)

### Root cause of the `PHPUNIT_BEFORE_BOOTSTRAP` / `UNKNOWN_STARTUP_FAILURE` boundary

The startup blocker was a PHPUnit CLI-version mismatch, established by isolated reproduction (not inference):

- The focused step invoked `vendor/bin/phpunit --configuration phpunit.mariadb-10.11.xml --log-junit ... --no-progress`. The lane pins PHPUnit 9.6 (`composer require --dev phpunit/phpunit:^9.6`), while `--no-progress` is a PHPUnit 10+ CLI option. PHPUnit 9.6's `CliArguments\Builder` option list contains `no-configuration`, `no-coverage`, `no-logging`, `no-interaction`, `no-extensions` but no `no-progress`.
- Code path (PHPUnit 9.6.31 source): `SebastianBergmann\CliParser\Parser` throws `UnknownOptionException ("Unknown option \"--no-progress\"")` → `Command::handleArguments` catches `ArgumentsException` → `exitWithErrorMessage()` → `exit(TestRunner::FAILURE_EXIT)` (exit code 1). This happens during argument parsing, **before** the XML configuration is loaded and **before** the bootstrap file is executed.
- Isolated reproduction: a PHPUnit 9.6.31 runtime tree was assembled from the upstream GitHub sources (packagist unreachable in the sandbox) and executed with the exact CI command shape, exact XML, exact bootstrap, and the same `CPMS_MARIADB_STARTUP_DIAGNOSTICS*` marker contract. With `--no-progress`, the only output was the version banner plus `Unknown option "--no-progress"`, and the marker file contained only the wrapper's `PHPUNIT_PROCESS_STARTED` line — byte-for-byte the CI signature. With the flag removed, the same inputs produced `INTEGRATION_BOOTSTRAP_ENTRY` and `WP_TEST_LIBRARY_CHECK` markers and then failed closed at `WP_TEST_LIBRARY_MISSING` (no WordPress test library in the offline sandbox — expected).
- Cross-run consistency: run 39012015265 (head `08c1aac0`), whose invocation had no `--no-progress`, reached test execution (2 cases / 20 assertions). Every run carrying `--no-progress` (39014819145 `ec499f01`, 39017190427 `104f5c16`, 39020031044 `77762f92`) failed before `INTEGRATION_BOOTSTRAP_ENTRY` with JUnit missing and exit code 1.

### Fix

One-line workflow correction (commit `b5b082a`): drop the unsupported `--no-progress` flag from the focused-test invocation. No application code, no `failOnWarning`, no 18-file selection, no 120-case minimum, and no fail-closed completeness gate was changed. Local reporter/selection regression suite: 26 tests, all passing.

### Controlled real MariaDB 10.11 run (exactly one; no retry)

`workflow_dispatch` returns HTTP 403 for this integration token, so the single authorized run used a temporary `push` trigger limited to the exact session branch `arena/09114e37-doctor`, removed again in the same delivery (final workflow state is `schedule` + `workflow_dispatch` only).

- Run [39024590449](https://github.com/bia2on2on/doctor/actions/runs/39024590449), event `push`, exact head `1c0152dba56ec326287a198b8d13a9c8712faec8`, job/check-run `117372966838`, 2026-10-17 10:21:34Z–10:23:22Z, conclusion **failure** (1m48s).
- Passed: container/service setup, PHP 8.3, exact MariaDB 10.11.19 identity gate, release ZIP build, clean WordPress 7.1.3 install + CPMS activation, migration head `2026_10_13_0027` + repeat-migrate no-op, safe dump/clone import, PHPUnit 9.6 install, selection/reporter regression step.
- Failed: "Run focused database-backed CPMS compatibility suites" and "Publish sanitized JUnit summary and enforce completeness" (both exit-code red). Artifact upload and the workflow summary step succeeded.
- Sanitized Checks-REST annotation (title `MariaDB 10.11 sanitized PHPUnit diagnostic`): `category=test_error+phpunit_nonzero_exit; startup_stage=BOOTSTRAP_COMPLETE; startup_category=NONE; exit=2; tests=216; assertions=3619; failures=0; errors=5; skips=0; classes=18/18; executed=<all 18 expected classes>; missing=none; first=CustomRoleArchivalConcurrencyTest.testActualDefinitionWaitSerializesTerminalOperations`.

### Measured interpretation (honest boundaries)

- The **startup blocker is resolved**, measured directly: `startup_stage=BOOTSTRAP_COMPLETE`, all 18/18 selected classes executed, 216 test cases (≥120 gate) and 3,619 assertions ran against the pinned MariaDB 10.11.19 server with the identity gate green.
- The lane is **still red**, and correctly so: 5 test **errors** (0 failures, 0 skips) violate the zero-error completeness gate; PHPUnit exit code 2. This is **not a MariaDB 10.11 compatibility PASS**.
- The first failing identifier is `CustomRoleArchivalConcurrencyTest.testActualDefinitionWaitSerializesTerminalOperations`. The identities of the remaining 4 erroring cases and the error causes are **not** established here: the raw JUnit/step logs remain inaccessible from this sandbox (artifact and log endpoints return EOF), and no raw-log guessing or retry was performed per mandate.
- Reproduction limitations: offline sandbox (packagist/GitHub release assets unreachable); the PHPUnit 9.6.31 tree was source-assembled and executed under a PHP 8.x WASM runtime (exit codes not propagated by that CLI); the CI counts come from the reporter's regression-tested JUnit parser, via the sanitized annotation channel.

## PR #238 consolidation and five-error diagnosis — 2026-10-17 (session 4)

### Authorization and branch ownership

- Measured receipt `2026-10-17T13:50:12Z`. Live `main` was `84fe07bc625ac9cf69607ea1283036868c136ec5`; Draft PR #238 was open at head `arena/c2bb7f15-doctor` = `a516abce28c861c3f6d3ba13377b955d08a2c0f4`, base `main`, `maintainerCanModify=false`; the proven fix branch `arena/09114e37-doctor` = `dc4f4520ec1190ff6292ad8a2abd461e3ffcb288`.
- The session branch `arena/ae733fdc-doctor` was not allowed to push to PR #238's branch. It therefore received a normal `--no-ff` merge of `arena/09114e37-doctor` (merge commit `a17163b562f1ae796360eae42c8ccfd2595c91bc`); only the append-only `docs/agent-log.md` conflicted, and both histories were kept in chronological order. No reset, clean, rebase, force push, history rewrite or empty commit was used. PR #238 remains open, Draft and untouched.
- Replacement Draft PR: [#242](https://github.com/bia2on2on/doctor/pull/242), base `main`, head `arena/ae733fdc-doctor`.

### Preserved contracts

The merge preserves PR #238's 11-file implementation, `phpunit.mariadb-10.11.xml` with exactly 18 explicit files, the >=120-case gate, `failOnWarning="true"`, the fail-closed JUnit/selection/reporting gate, and the final `schedule` + `workflow_dispatch`-only triggers. The PHPUnit 9.6 correction from `dc4f4520` (no `--no-progress`) is unchanged. No application, migration, security, tenant, finance or backup behavior was modified.

### Measured executions

Every run below used the pinned `mariadb:10.11.19` image and the identity gate; all preconditions (WordPress 7.1.3 + CPMS ZIP, migration head, safe clone import, PHPUnit 9.6 install, reporter regressions) passed.

| Run | Head | Measured result |
| --- | --- | --- |
| [39024590449](https://github.com/bia2on2on/doctor/actions/runs/39024590449) | `1c0152d` (proven fix branch) | 216 tests / 3,619 assertions / 18 of 18 classes / 0 failures / **5 errors** / 0 skips |
| [39038448525](https://github.com/bia2on2on/doctor/actions/runs/39038448525) | `6fa39d42` | identical 5 errors after the MariaDB lock-introspection dialect landed |
| [39039803860](https://github.com/bia2on2on/doctor/actions/runs/39039803860) | `112bb314` | sanitized signatures: all 5 errors at `CustomRoleLockWaitFixture.php:256`, throwable token `runtime`, category `lock_wait_timeout` |
| [39039942475](https://github.com/bia2on2on/doctor/actions/runs/39039942475) | `6462a4bd` | 216 / 3,852 / 18 of 18 / 0 errors / 4 failures / 0 skips |
| [39040092485](https://github.com/bia2on2on/doctor/actions/runs/39040092485) | `579336d6` | same 4 failures, failure signatures published |
| [39040250013](https://github.com/bia2on2on/doctor/actions/runs/39040250013) | `96aa748e` | **216 / 3,937 / 18 of 18 / 0 errors / 1 failure / 0 skips** |

### Established causes (evidence, not inference)

1. **Missing MariaDB Performance Schema tables.** MariaDB 10.11 has no `performance_schema.data_locks` / `data_lock_waits`; [MDEV-31165](https://jira.mariadb.org/browse/MDEV-31165) is still open. The selected tests now detect the live server family with `VERSION()` and read `information_schema.INNODB_LOCKS` / `INNODB_LOCK_WAITS` on MariaDB. Because those views cannot expose a granted row lock before a waiter exists, ownership is proven behaviourally by an independent `FOR UPDATE NOWAIT` probe on the exact primary key.
2. **MariaDB NOWAIT denial code.** Run 39039803860 isolated all five errors to the probe. MariaDB 10.11.19 reports a `NOWAIT` denial as `ER_LOCK_WAIT_TIMEOUT` (1205), not MySQL 8's `ER_LOCK_NOWAIT` (3572); treating 1205 as fatal turned a correct lock denial into five errors. Both codes now count as proof; every other error stays fatal.
3. **Cross-view transaction identity.** Run 39039942475 left three archival failures at `CustomRoleArchivalConcurrencyTest.php:441`, comparing `INNODB_TRX.trx_id` with `INNODB_LOCK_WAITS.blocking_trx_id` across two MariaDB views. The MariaDB wait-edge query now binds the blocking transaction by the winner connection thread id, keeps the blocking lock pinned to `PRIMARY` with `lock_data` equal to the definition id, and only the waiting lock may sit on the index the production statement actually used.

### Remaining failure (precise)

Run 39040250013 leaves exactly one failure: `CustomRoleLifecycleTest.testAssignmentWaitsForLockedDefinitionAndRevalidatesAfterDeactivation` at line 610 — the forked-child case does not observe a MariaDB wait edge within its bounded deadline. The four `CustomRoleArchivalConcurrencyTest` data rows pass. No speculative change was made to the forked-session case. The lane is therefore **still red and not a MariaDB 10.11 compatibility pass**; the fail-closed gate correctly remains non-zero.

### Evidence boundaries

- MariaDB 10.11.19 results are distinct from MySQL 8 and MariaDB 11.4.13 evidence. The MySQL 8 Integration check on `main` `84fe07bc` passed in CI run 39033834896; that is not MariaDB evidence.
- Run logs and the uploaded JUnit artifact remain unreachable from this sandbox (endpoints return EOF). All per-test facts above come from the sanitized Checks-REST annotation added in this branch; its reporter suite (26 local tests) verifies that messages, SQL, paths, fixture values and credentials are never published.
- The temporary `push` trigger used for the five measurements is removed; the final workflow state is `schedule` + `workflow_dispatch` only. Ruleset 22393801 and its nine required contexts are unchanged. No Owner-hosting, ruleset or Phase 20 change was made.

## Final forked-child resolution and green MariaDB 10.11.19 run — 2026-10-17 (session 5)

### Authorization and scope

- Measured receipt `2026-10-17T15:48:01Z`. PR #242 open/Draft at `b2d2d87`, base `main` `84fe07bc625ac9cf69607ea1283036868c136ec5` (unchanged). Only the session branch `arena/ae733fdc-doctor` was written.
- The contract was **not** weakened: 18 selected classes, >=120 cases, `failOnWarning`, fail-closed reporting and sanitized output are unchanged. No sleep, no elapsed-time substitution, no removed assertion, no skip, no timeout inflation as evidence.

### Root cause of the last failure (measured, then fixed)

- MariaDB 10.11 never registers this forked, read-only waiter in `INNODB_TRX` / `INNODB_LOCK_WAITS` / `INNODB_LOCKS` (runs 39041714039 and 39045431008: `reqtrx=0`, `w=0`, `pw=0`, while `req_cmd=query` and `req_sec=ge10` showed the child's production statement active server-side for more than ten seconds). The view-based edge cannot appear on this engine, so the previous assertion could never pass.
- Two instrumentation defects were found and fixed by measurement, not guesswork: run 39046979678 returned `Undefined variable $db2` (the nested transactional callback did not close over the repository handle), and run 39047116345 reached the new path but reported code `0` because wpdb's error bookkeeping had already been reset.

### Implemented evidence path (MariaDB only)

1. The parent proves it holds the exact row before the fork (`FOR UPDATE NOWAIT` denied on the same primary key) — unchanged.
2. The child executes the real production assignment; the existing filter signals the exact `SELECT ... FOR UPDATE` with its SHA-256.
3. The child activates strict mysqli reporting and captures the failing production statement's own driver code before any rollback, emitting `RESULT:blocked:<errno>:<statement sha256>`.
4. The parent requires `1205` (MariaDB `ER_LOCK_WAIT_TIMEOUT`, measured) and the exact statement hash, then re-proves that A still owns the row and that B is alive and parked.
5. After A commits the deactivation, A sends an explicit `RELEASE`; B retries the **same production assignment**, which must read the committed inactive state and deny it (`denied:CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE`). WordPress roles, membership role and audit counters are asserted exactly as before.

MySQL 8 takes none of this path: `CPMS_RACE_AWAIT_RELEASE` is only set on MariaDB, and every `performance_schema` assertion still runs there unchanged.

### Measured result

- Run [39047401299](https://github.com/bia2on2on/doctor/actions/runs/39047401299), head `3b0b0629977d1fd3f4e2dbd670dac1a2c92bc95d`, **success**: 216 tests, 3,962 assertions, 18 of 18 classes, 0 failures, 0 errors, 0 skips, PHPUnit exit 0, `category=none`.
- The sanitized annotation is green because the reporter's fail-closed gate (exit 0, >=120 cases, all 18 classes, zero failures/errors/skips) was satisfied by the real suite, and the MariaDB branch's own assertions (statement hash, errno 1205, parent lock, post-commit denial) executed inside it.

### Final head versus verified head

- MariaDB-verified SHA: `3b0b0629977d1fd3f4e2dbd670dac1a2c92bc95d`.
- The following commit removes the temporary `push` trigger and adds this documentation. It changes no test or product code, so the final PR head is not itself a MariaDB-measured SHA; the final workflow state is `schedule` + `workflow_dispatch` only.
- Evidence boundaries: this is MariaDB 10.11.19 evidence only. It is not MySQL 8 evidence, not MariaDB 11.4.13 evidence, and not a substitute for any required PR check. Run logs and JUnit artifacts remain unreachable; every count above comes from the sanitized Checks-REST annotation.
