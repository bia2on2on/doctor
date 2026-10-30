# Scoped demo — executed safety acceptance plan

This document defines the acceptance evidence required before a scoped-demo PR can be called complete. The original preparation checkpoint had no runtime execution. Since then, local static/unit/PHP-wasm evidence and a dedicated CI workflow have been added; see `implementation-status.md` for the current exact status. Most real WordPress, MySQL/MariaDB and full Chromium workflow items remain designed but **NOT RUN** until exact-head CI completes.

## 1. Source-level static contract (executable now)

A standard-library Python test under `tests/scoped-demo/` must parse the companion constants and validate:

- `Preflight::OPERATIONAL_TABLES` has exactly 43 entries.
- `Preflight::SYSTEM_TABLES` has exactly 10 entries.
- `Preflight::KNOWN_TABLES` has exactly 60 entries (schema `2026_10_27_0030`) and is the union of operational + system.
- `Teardown::DELETE_ORDER` has 50 entries, all known.
- `CPMS_DEMO_SCHEMA_VERSION` equals the latest migration `2026_10_18_0029`.
- `docs/scoped-demo/ownership-map.md` mentions every one of the 60 tables.
- The production `HashChain` and `AuditLogger` contracts still exist.
- The strict disposable preflight constants are unchanged.

This is a source contract, not runtime proof. It must be run locally and in CI.

## 2. Seeded existing-site fixture

Each standalone database lane starts with a seeded non-empty WordPress and NO production CPMS. The inner standalone ZIP is uploaded and activated through real wp-admin in Chromium; WP-CLI may seed/inspect but must not substitute for the Owner install/activate path. Companion lanes continue to test the production+companion combination separately.

Minimum seed:

- 5 WordPress users: one Administrator, one editor, one subscriber, one existing CPMS staff user, one existing patient user.
- 12 posts/pages, 10 comments, 3 attachments, 2 custom post meta rows, 2 user meta rows.
- 2 Organizations, 3 Clinics, 5 Locations, 4 clinicians, 6 existing patients.
- 4 appointments, 3 visits, 3 clinical notes, 2 prescriptions with items, 2 invoices with items, 2 payments, 1 payment adjustment, 1 follow-up.
- Existing queued, processing, success, and failed `cpms_jobs` rows; recurring jobs with empty payload.
- Existing `cpms_operational_logs` rows that deliberately share timestamps, request IDs, IP hashes, and 4-digit mobile suffixes with expected demo rows.
- Existing `otp-ip:*`, `otp-verify-ip:*`, `login-ip:*`, and entity-scoped rate-limit rows.
- At least 25 valid append-only `cpms_audit_logs` rows, ending with a known tail hash.
- Existing license install/state, schema migrations, settings, notifications, SMS messages, OTP tokens, and idempotency keys.
- No `CPMS_DEV_OTP_ECHO`; HTTPS or an explicitly isolated loopback CI origin.

## 3. Baseline snapshot

Before installing/activating the companion in the acceptance run, take a non-secret baseline:

For each WordPress core table (`users`, `usermeta`, `posts`, `postmeta`, `comments`, `commentmeta`, `terms`, `term_taxonomy`, `term_relationships`, `options`, and any custom WP prefix equivalents) and each of the 60 `cpms_*` tables:

- row count;
- stable primary/composite key list, in deterministic order;
- per-row SHA-256 over canonical JSON of all columns with sorted keys;
- table-level SHA-256 over ordered per-row digests;
- maximum numeric ID where applicable.

Additionally:

- full `cpms_audit_logs` chain verification from genesis and the exact tail `row_hash`;
- a digest of the first `N` audit rows including every column (not only hash-chain fields);
- exact list of shared/global rate-limit keys and a digest of those rows;
- exact baseline job IDs/statuses, operational-log IDs, and CPMS Organization/Clinic/Location IDs;
- private-storage file tree digest (paths, sizes, SHA-256), excluding secrets from published evidence.

The baseline file lives outside the web root, contains IDs/hashes, never raw PHI or credentials, and is HMAC-sealed with a test-only site key.

## 4. Positive journey (real browser + real WordPress)

1. Upload and activate production CPMS and companion ZIPs through `wp-admin/plugin-install.php` in Chromium.
2. Open the companion page and verify strict disposable mode remains the default and still refuses the non-empty seeded site.
3. Activate scoped mode with a distinct typed phrase `I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE`; verify disabled-by-default before submission.
4. Run the scoped preflight; record every check result.
5. Provision six personas; capture the six one-time passwords from the no-store response and verify no password appears in any subsequent render, option, log, or artifact.
6. Open six independent Chromium browser contexts and authenticate through `wp-login.php`; verify each receives a distinct session and exact persona identity.
7. Execute real journeys:
   - reception: create appointment, walk-in, call/start, check-out;
   - doctor: clinical notes, prescription, follow-up, completion;
   - accountant: invoice and offline `other` payment only;
   - clinic/organization manager: administration within the synthetic Organization/Clinic;
   - patient: public booking and real OTP verification through the normal UI;
   - cross-Clinic authorization: valid session and nonce for a foreign patient/visit/invoice must receive the canonical denial without data leakage.
8. Verify `sms.provider=log` for the synthetic clinic, no gateway call, and no real payment-gateway call.
9. Interleave legitimate foreign activity during the demo: publish a post, add a comment, create a new WP user, create an unrelated Clinic appointment and finance row, enqueue a foreign job, append an operational log sharing request ID/IP/timestamp, increment a shared IP rate-limit counter, and append a valid foreign audit event.
10. Open teardown preview; verify it lists exact owned rows by table, six users, one page, the synthetic Organization/Clinic/Locations, and explicitly says audit rows are preserved.
11. Submit wrong phrase and wrong nonce; verify zero writes.
12. Submit authorized teardown with `REMOVE_ONLY_SCOPED_DEMO_DATA`; verify nonce, capability, and site fingerprint gates.
13. Repeat teardown and verify an idempotent no-op without baseline mutation.
14. Compare baseline: every pre-existing row's key and full-row digest must be unchanged; no baseline audit, user, post, comment, clinical, financial, queue, rate-limit, or license row may be missing or altered.
15. Verify full audit chain from genesis, baseline prefix digest, and valid tail. Allow only legitimately appended foreign/demo audit rows; no row may be deleted.
16. Verify all synthetic operational rows are gone; preserve all shared/global rows and all foreign activity.
17. Verify no synthetic user, page, user meta, post meta, private file, or option remains (except a sealed, non-secret tombstone).
18. Safely remove the companion plugin through wp-admin only after clean teardown.

## 5. Negative and recovery matrix

Each case must have an exact pre/post baseline comparison and an expected failure code. No destructive test may run outside an isolated CI database.

| Case | Expected outcome |
|---|---|
| Unauthorized/non-admin user, missing capability, wrong nonce, wrong phrase, wrong site fingerprint | `AUTH_REQUIRED` / `CAPABILITY` / `NONCE` / `CONFIRMATION` / `SITE_IDENTITY`; zero writes. |
| Existing username, email, Organization/Clinic/Location slug, post slug, patient mobile, OTP mobile, SMS recipient, identity `internal_ref`, or idempotency key collision | `COLLISION`; zero writes. |
| Tampered scoped manifest, cross-site manifest, stale format, or incorrect ownership metadata | `MANIFEST_TAMPERED` / `MANIFEST_SITE_MISMATCH` / `OWNERSHIP_UNPROVEN`; teardown refuses. |
| Unknown `cpms_*` table with data, schema drift, `CPMS_DEV_OTP_ECHO`, multisite, HTTP, unsupported PHP/WP, persistent object cache, or invalid existing audit chain | `PREFLIGHT`; zero writes. |
| Owned job in `processing`, unavailable `cpms_jobs_tick` lock, or fixture job appearing during teardown | `JOBS_ACTIVE` / `TEARDOWN_INCOMPLETE`; no false success. |
| Foreign operational log sharing timestamp/request ID/IP/mobile suffix | Preserved; teardown must not match it. |
| Foreign rate-limit key or shared IP counter | Preserved. |
| Foreign activity rows in clinical/financial tables during demo | Preserved; not treated as owned. |
| Interrupted provisioning at each major stage | Sealed partial manifest remains; recovery teardown removes only proven rows; no orphan synthetic entity. |
| Interrupted teardown before/after logs, jobs, clinical rows, finance rows, WP users, or page deletion | Manifest stays `teardown_failed`; retry succeeds; no success claim while owned clinical/financial rows remain. |
| Concurrent provisioning across two MySQL connections | One succeeds, one gets `CONCURRENT_OPERATION`; no duplicate fixture. |
| Concurrent teardown/provisioning | Serialized by the same `GET_LOCK` namespace; no double deletion. |
| Storage write failure or private path outside the fixture namespace | `STORAGE_FAILURE`; provisioning or teardown fails closed. |
| Audit insert failure during provisioning | Production transaction rolls back; partial manifest remains; no false success. |
| Audit-chain corruption before teardown | `AUDIT_CHAIN_BROKEN`; teardown is blocked. |
| Repeated teardown after clean tombstone | Idempotent no-op, no baseline mutation. |
| Browser access by a persona to companion admin page or another Clinic's records | Denied; no data leakage. |

## 6. Required execution matrix

At minimum, exact-head CI must execute:

- **MySQL 8** full positive journey + negative matrix.
- **MariaDB 11.4.13** full positive journey + negative matrix.
- WordPress 7.1.3; PHP 8.1, 8.2, 8.3, 8.4 for unit/static checks.
- Real Chromium wp-admin ZIP upload/activation, eight independent persona contexts, public booking/OTP click path, wrong-mobile blocked cleanup, and RTL at 320px/390px.
- Real WordPress integration with cross-connection `GET_LOCK` contention and fault injection.
- Twice-built standalone ZIP with identical SHA-256, one top-level `cpms-disposable-demo/`, exactly ONE WordPress-discoverable header, no tests/docs/secrets, no `Requires Plugins`, and an executable embedded bootstrap whose non-header bytes match production.
- Full mandatory repository checks on the exact PR head: CI, WPCS, PHPStan, unit, integration, Real WP Acceptance, Closure Gate, Pilot/Staging, Disposable Demo Installer, and new Scoped Demo Acceptance.

Screenshot pixels must actually be opened before any `INSPECTED` claim; artifact existence is not inspection. No universal hosting compatibility claim is allowed.

## 7. Required evidence fields for the final report

- workflow run URLs and exact head SHA for both DB lanes;
- baseline table digests before and after;
- audit chain `checked`, `broken_at`, baseline tail, final tail, and full-chain pass;
- counts of synthetic rows removed by table;
- counts of foreign rows preserved by table;
- test case results with exact failure codes;
- browser journey result per persona;
- baseline preservation verdict and any unexplained delta;
- ZIP filename, bytes, SHA-256, entry list, and reproducibility verdict;
- all remaining risks and any `NOT RUN` item.
