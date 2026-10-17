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
- The reporter's `if: always()` step writes sanitized aggregate counts, executed-class count, and missing-class names to the GitHub step summary even after PHPUnit or the gate fails. Raw PHPUnit output, JUnit failure messages, WordPress debug logs, SQL, credentials, and fixture values are not emitted in that summary or uploaded; the artifact includes only sanitized JSON and bounded non-test metadata.
- `tests/bin/test_mariadb_compat_report.py` contains focused regression checks, including a 2-test/20-assertion JUnit fixture proving the old incomplete selection fails the 120-case and 18-class gate, dot/backslash name normalization, malformed/missing/inconsistent XML, dropped config files, and red failure/error/skip counts. These are reporter/selection validation only, **not** database compatibility evidence.
- The PR trigger is temporary for the upcoming real-server verification. The final workflow must return to schedule + `workflow_dispatch` only after the lane concludes.

## Explicit limits

- The scheduled lane is focused, not the full Integration directory or a duplication of the existing PR matrix.
- This is not a MariaDB 10.11 browser/E2E acceptance run. Existing MariaDB 11.4.13 browser acceptance remains its own evidence stream.
- The only pre-fix pre-merge MariaDB 10.11 execution reached the focused PHPUnit step but failed; it is **not a compatibility PASS**. The configured immutable image is MariaDB 10.11.19 and its runtime identity gate passed. Independent diagnosis identified the 2-test/20-assertion discovery defect, but individual test outcomes remain unavailable because the original log/artifact still cannot be retrieved. The corrected lane has not yet produced a new real-server result.
- MariaDB JSON is text-backed, not MySQL's binary JSON. The lane is not cross-engine physical JSON dump compatibility evidence.
- The automated dump/import is a same-engine, synthetic-data clone, not a production backup restore or a destructive application restore drill.

## Run cost estimate

A successful scheduled run is estimated at **15–25 runner-minutes per week**; this has not been measured. The failed pre-merge attempt lasted about 1m 15s but stopped at the focused test step, so that is not a successful-run cost measurement. One scheduled run creates one standard `ubuntu-latest` job and one short-lived MariaDB service. The repository is public and standard GitHub-hosted runners are free for public repositories under GitHub's published policy: [GitHub Actions billing and usage](https://docs.github.com/en/actions/concepts/billing-and-usage). This is runner usage, not a promise about external artifact/network/storage charges.
