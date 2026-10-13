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
