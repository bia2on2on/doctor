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
- `BackupEngineTest` and `BackupMultiClinicFilesRestoreGreenTest`: application backup generation/integrity/preflight, the non-destructive `restoreApply(..., false)` confirmation guard, and safely isolated multi-clinic file restoration.

A separate workflow step also uses `mariadb-dump --single-transaction` to copy the synthetic WordPress/CPMS database into a new disposable MariaDB database, imports that copy, and checks migration/table state. It does not run a destructive restore against the installation database. No confirmed/destructive `restoreApply(..., true)` is invoked. The application-level table-replacement restore path remains out of scope; do not infer it was tested from the safe clone import or the non-destructive confirmation-guard test.

## Explicit limits

- The scheduled lane is focused, not the full Integration directory or a duplication of the existing PR matrix.
- This is not a MariaDB 10.11 browser/E2E acceptance run. Existing MariaDB 11.4.13 browser acceptance remains its own evidence stream.
- Actual-server evidence is for the pinned MariaDB 10.11.19 patch. The migration's accepted engine-family check covers MariaDB 10.11.x; future patches require updating the image pin and exact runtime assertion before they are measured.
- MariaDB JSON is text-backed, not MySQL's binary JSON. The tests exercise CPMS JSON validity and round-trip behavior but do not claim cross-engine physical JSON dump compatibility.
- The automated dump/import is a same-engine, synthetic-data clone, not a production backup restore or a destructive application restore drill.

## Run cost estimate

One scheduled run creates one standard `ubuntu-latest` job and one short-lived MariaDB service. Until measured in GitHub Actions, budget approximately **15–25 runner-minutes per weekly run** (design estimate, not observed runtime). The repository is public and standard GitHub-hosted runners are free for public repositories under GitHub's published policy: [GitHub Actions billing and usage](https://docs.github.com/en/actions/concepts/billing-and-usage). This is runner usage, not a promise about external artifact/network/storage charges.
