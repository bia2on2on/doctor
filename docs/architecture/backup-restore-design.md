# Design: encrypted backup and restore onto a fresh install

**Status:** draft for owner review; not an implementation or acceptance evidence. National IDs and identity data remain stored and are included. R1–R7 refer to the owner's requirements. OBSERVED denotes evidence on main; INFERENCE denotes design reasoning; LIMITATION/UNKNOWN marks unverified work. References below omit the common `clinic-practice-management/` prefix unless otherwise stated.

## 1. Gap assessment

| Requirement | Status | Evidence / gap |
|---|---|---|
| R1 identity, national ID | PARTIAL | OBSERVED: `src/Migrations/2026_09_05_0001_initial_schema.php:54-100` creates patients and patient links; `src/Infrastructure/Backup/BackupSqlDumper.php:113-125` discovers prefixed CPMS tables. A verified national-ID round trip is UNKNOWN. |
| R2 total-loss recovery on new install | MISSING | OBSERVED: `src/Application/Backup/BackupService.php:324-367` applies source SQL and files; WP users are outside its CPMS table selection. Prefix and account migration are not established. |
| R3 linked clinical, financial and file content | PARTIAL | OBSERVED: initial schema creates visits, attachments and finance (`src/Migrations/2026_09_05_0001_initial_schema.php:221-295,506-630`); file enumeration skips nonnumeric paths (`src/Application/Backup/BackupService.php:654-695`). Completeness beyond those roots is UNKNOWN. |
| R4 independent-install transfer | MISSING | OBSERVED: dump uses source table names (`src/Infrastructure/Backup/BackupSqlDumper.php:53-65`); restore applies statements as supplied (`src/Application/Backup/BackupService.php:341-360`). |
| R5 encryption, custody, integrity, drill | PARTIAL | OBSERVED: local creation writes `db.sql`, copied files and an unsigned manifest (`src/Application/Backup/BackupService.php:95-133`, `src/Domain/Backup/BackupManifest.php:18-26`); S3 key is a host configuration constant (`src/Infrastructure/Backup/S3BackupDeploymentConfig.php:5-10,80-81`). The isolated destructive same-install drill is at `.github/workflows/closure-gate.yml:322-329,500-513`; fresh-install drill is UNKNOWN. |
| R6 tenant isolation after restore | MISSING | OBSERVED: apply copies files after SQL (`src/Application/Backup/BackupService.php:348-367`); it does not perform the proposed post-restore isolation test. INFERENCE: a persistent deny barrier and explicit reauthorization are needed. |
| R7 dependency, ID and integrity verification | PARTIAL | OBSERVED: `src/Application/Backup/BackupService.php:283-318` reports integrity/DB reachability and versions; the current safety decision does not compare target compatibility. `src/Infrastructure/Sms/CredentialVault.php:67-78` depends on a secret or WP salts. |

## 2. User references and classification registry

OBSERVED: migration search on main for `wp_user_id`, `user_id`, `actor`, `created_by`, `updated_by`, `owner`, `author`, `operator` found **37 declared CPMS table/column pairs** with `wp_user_id` in the name (22 in migration 0001, 2 in 0012, 1 in 0019, 3 in 0028, 1 in 0029 and 8 in 0030). Count excludes repeated index/ALTER references, WP core fields, and the unmerged identity design. OBSERVED sources: `src/Migrations/2026_09_05_0001_initial_schema.php:38-717`, `src/Migrations/2026_09_09_0012_memberships.php:27-45`, `src/Migrations/2026_09_09_0019_identity_mobile_and_user_links.php:53-65`, `src/Migrations/2026_10_18_0028_organization_memberships.php:12-40`, `src/Migrations/2026_10_18_0029_clinic_lifecycle.php:20-39`, `src/Migrations/2026_10_27_0030_staff_messaging_visit_pricing.php:30-128`. Table names below have `cpms_` prefix. L = live reference: use verified one-to-one source→target mapping, preserving NULL/zero sentinel, reject missing mappings; H = historical source ID, not a target WP lookup; R = needs review, block release if unresolved.

| Table | Column(s) | Class / operation |
|---|---|---|
| clinicians | wp_user_id | L: map |
| patient_user_links | wp_user_id | L: map |
| patient_merges | merged_by_wp_user_id | L: map |
| idempotency_keys | wp_user_id | L: map; expire transient operations |
| schedule_exceptions | created_by_wp_user_id | L: map |
| slot_holds | holder_wp_user_id | L: map; expire holds |
| appointments | wp_user_id, cancelled_by_wp_user_id | L: map |
| visits | cancelled_by_wp_user_id | L: map |
| visit_status_history | actor_wp_user_id | L: map |
| clinical_notes | created_by_wp_user_id, updated_by_wp_user_id | L: map |
| clinical_note_versions | changed_by_wp_user_id | L: map |
| ocr_jobs | reviewed_by_wp_user_id | L: map |
| medical_attachments | uploaded_by_wp_user_id | L: map |
| invoices | issued_by_wp_user_id | L: map |
| payments | received_by_wp_user_id, voided_by_wp_user_id | L: map |
| payment_adjustments | approved_by_wp_user_id | L: map |
| notifications | recipient_wp_user_id | L: map |
| audit_logs | actor_wp_user_id | H: preserve byte-identical source ID; never authorize or resolve as a target user |
| settings | updated_by_wp_user_id | L: map |
| clinic_memberships | wp_user_id, invited_by_wp_user_id | L: map; manager reconfirms |
| patient_identity_links | wp_user_id | L: map |
| organization_memberships | wp_user_id, created_by_wp_user_id | L: map; manager reconfirms |
| organization_membership_capabilities | created_by_wp_user_id | L: map |
| clinics | status_updated_by_wp_user_id | L: map |
| message_threads | created_by_wp_user_id | L: map |
| message_participants | wp_user_id | L: map |
| messages | sender_wp_user_id | L: map |
| doctor_consultation_fees | updated_by_wp_user_id | L: map |
| visit_consultation_fees | set_by_wp_user_id | L: map |
| visit_charge_items | created_by_wp_user_id, updated_by_wp_user_id, removed_by_wp_user_id | L: map |
| patient_logins (conditional PR #354, not on main) | wp_user_id, verified_by_wp_user_id | L: map; deny pending individual manager review. PR #354 is an unmerged design, not current schema. |
| JSON/serialized payloads, options, usermeta, audit before/after/meta | embedded user references | R: classify per field; audit payload stays byte-identical and historical. Known live payloads must have versioned parsers and mapping; unclassified structured payloads block restore. No arbitrary search-and-replace in serialized data. |

OBSERVED search method: `rg -i -l` on `clinic-practice-management/src`: `wp_user_id` 87 files, `user_id` 112, `actor` 88, `created_by` 9, `updated_by` 14, `json_encode|json_decode|serialize|unserialize` 57; these are file-hit counts, **not** counts of embedded references. UNKNOWN: number and semantics of actual embedded references; this draft does not assert zero. Inventory every JSON/meta producer and storage column, WP capabilities/meta and runtime schema before implementation; make unknown structures fail closed rather than silently omit them. Registry CI must instantiate each supported migrated schema, compare information_schema column census and declared FK/semantic references with a versioned classification registry, scan migration/source changes and serialized-field adapters, and fail on a new unclassified reference. Mutation fixture adds a differently named user reference and must fail. No registry table is required.

## 3. Backup content and consistency

| Content | Contract |
|---|---|
| CPMS database | Discover actual tables by escaped literal `{prefix}cpms_` plus wildcard suffix, then exact-prefix filter; compare against a versioned migration/schema census including migration ledger; missing/unexpected tables block backup until classified. OBSERVED: current LIKE pattern has unescaped underscores (`src/Infrastructure/Backup/BackupSqlDumper.php:113-118`). Include identities/national IDs, patient–Organization relationships, clinic/location, records, appointments, histories, finance, memberships, jobs and integrity metadata. Keep CPMS row IDs and links. |
| WP dependencies | Include CPMS-related users, including historical actors and disabled users needed for live references; profiles, required usermeta, CPMS role definitions and capabilities, password hashes as decided by the owner, inside the encrypted backup; raw passwords are not stored. Record WP user source IDs. Exclude sessions, reset/activation tokens and unrelated plugin privileges; classify all related meta, block unknown live references. Include plugin options by versioned allowlist, not arbitrary `wp_options`. |
| Secrets | Exclude vault master key, WP salts, OTP pepper, backup private keys, signing secret, plaintext credentials and tokens. Preserve table census and row identities while redacting credential and token values through a versioned field policy; record redactions/counts in the manifest and validate them on restore. `CredentialVault.php:67-78` uses an old-host key/salts: restore vault settings disabled and require manager to re-enter SMS and cloud credentials; do not attempt decrypt with new salts. OTP pepper is a constant or option (`src/Application/Auth/OtpService.php:750-785`); invalidate old tokens and generate new pepper. Backup key material and phrases do not belong in logs, database or backup payload; signing secret is a protected site file/config outside DB. |
| Files | Capture each referenced attachment/document, handwriting object and registered storage root, including legitimate paths outside numeric clinic roots. Resolve ownership and stable logical root/relative path; reject absent files, symlinks, traversal, collisions, unknown external storage, unsupported links and unclassified file references. Restore to new protected roots outside webroot, rewrite stored location settings/paths, verify every digest and service authorization. Do not trust old absolute paths. |
| Manifest | Inside encrypted stream: backup id, sequence/time, format, plugin/schema/ledger/WP/PHP/DB engine and versions, table census, schema fingerprints and counts, logical file names/sizes/SHA-256, root mapping, dependency classifications. Bind container header and encrypted bytes by detached signature. Manifest is authenticated by stream and signature. No PHI in cleartext header. |
| Snapshot | Quiesce CPMS writes, jobs and file writes under a persisted write barrier; drain in-flight requests and obtain a consistent DB snapshot with checked errors, capture files while frozen, validate references and release. Refuse nontransactional/unsupported engines or uncoordinated writers. Stream bounded chunks; check disk capacity, quotas and configurable timeout, abort incomplete output and clean restricted staging. No whole SQL/file read into memory. |

## 4. Encryption, signatures and custody

| Element | Design |
|---|---|
| Setup | Generate encryption keypair and Ed25519 signing keypair. Site retains encryption public key and protected signing secret; doctor's offline, versioned key bundle holds encryption private/public key and signing public key, key IDs and format. The signing secret is deliberately host-resident under D-C, not included in backups. Encrypt private material in the bundle with an independently held recovery phrase, Argon2id-derived wrapping key and authenticated encryption; store salt/KDF parameters/nonce/version with bundle. See [PHP Argon2](https://www.php.net/manual/en/function.sodium-crypto-pwhash.php). Securely deliver and test the bundle/phrase off-host; no phrase or key in logs. |
| Backup | Per backup generate a random data key, seal it to the encryption public key; stream authenticated XChaCha20-Poly1305 secretstream chunks, requiring final tag and no trailing bytes. Hash the exact canonical versioned header plus ciphertext incrementally with SHA-256; sign a domain-separated header+digest using Ed25519 detached signature. Signature is over ciphertext, not a loaded whole archive. [Sealed box](https://www.php.net/manual/en/function.sodium-crypto-box-seal.php) gives confidentiality/integrity, not sender identity; [secretstream](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php), [sign](https://www.php.net/manual/en/function.sodium-crypto-sign-detached.php), [verify](https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php). |
| Restore | Manager supplies independently trusted bundle and phrase. Verify the signature over the bounded, streamed archive first with the bundle's signing public key, before decrypting. Do not trust an embedded signing key. Then unwrap data key, authenticate every chunk and final tag and manifest/digests. Require sodium functions at both ends; absent extension = fail closed, install supported sodium, no plaintext fallback. |
| Lifecycle | Create local encrypted output directly; temporary plaintext needed by database/files staging must be access-restricted outside webroot, short-lived and cleaned on success/error/startup; secure deletion on SSD/snapshots is not guaranteed. Prefer no plaintext staging. Reuse secretstream concepts from existing envelope but migrate S3 to mirror the same signed encrypted container, not a host-key-encrypted plaintext local backup. Legacy import requires separate explicitly approved migration and old key; no silent downgrade. |
| Loss and rotation | Loss of bundle or phrase makes protected private key unavailable unless separately secured recovery copies exist. Loss of signing public key prevents trusted verification; loss of signing secret prevents new signatures, not old verification. Rotate keys with explicit manager enrollment and new bundle; retain prior bundles to read historical backups. Losing only the host must not lose the doctor's backup and bundle. Live-host compromise can forge future signatures; show signed sequence/time and request manager confirmation against independent records. Anti-rollback is manual, not automatic. |

## 5. Restore, barrier, audit and rollback

| Phase | Required behavior |
|---|---|
| Preflight | Fresh independent WP + compatible CPMS install, no clinical data and no conflicting CPMS users beyond a designated recovery manager; reject collisions, unsupported prefix, multisite or incompatible schema/engines. Validate full signature, decrypt/verify entire archive and manifest, paths, per-file digests, schema census, mappings, disk space and version matrix before any target data change. Fresh WP requires an empty CPMS dataset, not an absent WP administrator. Recheck emptiness after locking. Reject unrecognized SQL/identifiers; use structured table records and allowlisted identifiers, not blind text prefix replacement. |
| Close | Set durable non-autoloaded WP option **before** writes, confirm read-back, lock restore, drain active requests; option is outside the CPMS dump selection (`BackupSqlDumper.php:113-118`) and excluded from plugin-option import. A missing/unreadable barrier state during recovery fails closed. Deny patient access levels, login, staff/API/CLI/cron/jobs and file delivery; allow only separately authenticated, capability-checked recovery manager route. Protect files outside webroot and enforce barrier at early entry points. Restart/failure retains barrier. Bootstrap and server-level bypass coverage is a release blocker. |
| Stage | Create isolated target-prefix staging tables, check each query and result (including metadata, DDL, writes and commit); strict errors, never increment success after false. OBSERVED: `src/Infrastructure/Db/CpmsDb.php:17-25,109-119` defaults to soft false; `BackupService.php:348-360` ignores results. Create target WP users with fresh IDs and explicit mapping, remap L columns/known live embedded references. Preserve the imported password hash exactly for its mapped user, without treating it as a plaintext password or hashing it again; the verified import mechanism is a release prerequisite. On a later sign-in, WordPress [wp_check_password](https://developer.wordpress.org/reference/functions/wp_check_password/) checks the entered password against that hash in memory, without storing the raw password. [wp_insert_user](https://developer.wordpress.org/reference/functions/wp_insert_user/) documents plaintext input for new users, so passing a saved hash as the new-user password would rehash it; do not do that. Test supported WP versions and pluggable hash implementations; reject unsupported hash formats rather than silently lock users out. Fail unresolved/deleted users, collisions or unsupported data; do not bind source IDs to unrelated target users. Preserve H IDs. Import role capabilities carefully without importing foreign sessions or privileges. Stage files and rewrite root configuration. |
| Publish | Run staging integrity checks then one multi-table `RENAME TABLE` swap for the supported engine/configuration, after validating FK names/constraints and privileges. MySQL documents atomic rename under its stated conditions: [RENAME TABLE](https://dev.mysql.com/doc/refman/8.0/en/rename-table.html). DDL is not treated as transactional across filesystem, WP users and options; barrier spans every phase. On any failure leave closed, retain journal and stage, offer explicit retry or teardown of disposable fresh target; no claim of automatic rollback. A crash during swap is diagnosed by persisted journal and object census before retry. |
| Audit | OBSERVED: `src/Infrastructure/Audit/HashChain.php:22-27,50-61` hashes previous hash and canonical clinic_id, actor_wp_user_id, actor_role, action, resource_type/id, patient_id, ip_hash, session_id, request_id, created_at; before/after/meta are excluded (`:30-32`). Restore existing audit rows byte-identically, including source actor IDs; verify full chain before and after import. Never treat historical actor IDs as live authority. Append exactly one `restore performed` event with backup ID/date, plugin version, mapping digest and counts. Store mapping in an access-controlled, encrypted restore-report artifact, not a new table. Verify appended chain. |
| Reauthorization | Manager decision D-B: force patient login rows, including future `cpms_patient_logins`, to closed/needs-manager-review semantics; invalidate WP sessions, OTP, reset tokens and outstanding credentials. Staff memberships are disabled pending individual manager reconfirmation. Reconfirm staff and patient accounts independently against trustworthy current evidence; do not infer later revocations from a snapshot. Barrier remains until verification passes and explicit manager acceptance. Acceptance does not bulk-enable accounts: unreviewed or revoked users stay denied. After restore, patient logins start closed. Following the barrier clearance and required individual review against current revocation evidence, patients re-establish access through the new PR #354 mobile + SMS OTP + national-ID flow: owner policy grants full access to their own record when both factors match that record. OTP possession alone grants no full access, a mismatch grants no full access, and a revoked account stays denied unless independent current evidence and authorized review establish that it is eligible for re-establishment; a confirmed current revocation remains denied. No previously issued session, OTP or token is revived. If a schema has no closed state, use a fail-closed option-backed review registry initialized to deny before publish and consulted at every login and access decision even after the global barrier clears, or block release pending identity coordination; no silent activation. The registry must survive restarts and failed option reads must deny access. |

INFERENCE (adversarial review): manager action is security-sensitive; require an independently provisioned recovery manager and avoid granting a restored staff user recovery authority merely because source IDs collide. The recovery route must permit individual review while the barrier is set, but cannot serve patient records/files to other callers; failed or incomplete user review stays denied after global acceptance. Archive signature and plaintext staging must be protected from replacement between validation and apply: hold an immutable private file descriptor/copy and revalidate its digest immediately before use. Restore acceptance must not depend on successful cleanup of a temporary fixture granting privileges; synthetic isolation checks run in a separate disposable install or a tightly controlled transaction with verified cleanup. A failure in cleanup keeps the barrier closed.

## 6. Blocking post-restore acceptance

| Automatic check | Blocking assertion |
|---|---|
| Counts / links | Compare snapshot counts of Organizations, Clinics, Locations, patients, visits, finance, tables and files to manifest; account for explicitly documented token/hold invalidation separately. Check patient→Organization, visit→Clinic, location/membership and user orphans, including mandatory mapping uniqueness. |
| Integrity | Verify file hashes at destination, source audit rows byte-identical and full audit chain through the single restore event, mapping artifact digest, versions and schema census. |
| Authorization | Verify CPMS roles/capabilities and scope, patient access denied pending individual review. Exercise real API/service and file paths using synthetic clinic A staff and clinic B patient/visit/file; A must be denied B and permitted its own fixture. No test fixtures or bypass accounts are left in production. |
| Acceptance | Display results and failures to recovery manager. Failed/unknown check, interruption, or an unreviewed access grant means barrier remains; explicit manager acceptance after checks opens the site while individual pending users remain denied. LIMITATION: fixture-based isolation test is not proof of every possible path; release requires an authorization entry-point inventory and independent review. |

## 7. Later CI plan (new job in a separately approved workflow PR)

All tests use synthetic identities and disposable installs. Each asserts a positive control and a negative outcome; no current CI result is claimed.

| Test | Setup → assertion; how it can fail |
|---|---|
| T1 | Two clinics, fresh WP, different prefixes and user IDs → exact data/links/files and role scopes after mapping; restored hash accepts the synthetic original password and rejects a wrong password after staff review, while source session, OTP and tokens are rejected. Fail if source ID reused, hash rehashed, old session remains usable, or test compares counts alone. |
| T2 | Wrong bundle/private key or phrase → no data writes, closed; fail if preflight skipped or correct key accidentally supplied. |
| T3 | Truncate signed archive → signature/final tag fails; fail if truncation does not touch consumed bytes. |
| T4 | Modify a ciphertext/file byte → reject; fail if test changes an unused file. |
| T5 | Modify manifest in archive; separately create ciphertext using public key alone and fake signature → reject; fail if verification uses embedded attacker key or mutation happens outside signed content. |
| T6 | Kill restore before/during/after swap, restart → barrier blocks patient/staff/files, recovery manager works; fail if no process actually dies at target boundary. |
| T7 | Clinic A and B fixtures with real staff credentials → A reads A and is denied B patient, visit, file; fail if B fixtures absent or calls use admin. |
| T8 | Snapshot active patient, revoke after snapshot, restore old archive → account stays closed despite a matching mobile/OTP and national ID, until individual review and independent current revocation evidence authorize re-establishment; repeat with PR #354 row when available. After authorized review, matching both factors grants full access to own record; wrong OTP, wrong ID, and another patient’s record are denied. Fail if test starts closed, skips the revocation, omits positive control or exercises a stub rather than actual access path. |
| T9 | Multi-GB synthetic attachment, constrained memory → bounded memory, digest matches; fail if fixture is small/sparse or measurement absent. |
| T10 | Unsupported older/newer plugin/schema, WP/PHP/DB versions → reject before writes; matching version succeeds. Fail if mismatch is not actually applied. |
| T11 | Restore after source shut down with backup + bundle/phrase and fresh software → complete; fail if any network/source mount remains reachable. |
| T12 | Synthetic non-real national-ID marker and patient→Organization plus clinic links → exact round trip; fail if value is null or comparison only checks count. |
| T13 | Verify source audit chain and byte-identical rows, append one event, verify chain; inject SQL failure at staging and swap → abort and closed; fail if injected statement is not executed or error ignored. |

## 8. Hazard remediation

| Hazard | Required fix |
|---|---|
| Soft false / unchecked results | Strict DB and checked query/IO results; test T13. |
| Literal `cpms_` drop | Replace `BackupService.php:350-355` with validated logical table mapping to target prefix; no destructive source-name SQL. |
| False failure text | `src/Admin/SystemPage.php:503-504` says no changes except safety backup; replace with truthful closed/recovery state because DDL/file changes may have happened. |
| LIKE underscores | Escape pattern and exact-filter; compare census (`BackupSqlDumper.php:113-118`). |
| Whole-file memory | `BackupService.php:341` reads whole dump; stream structured records and chunks. |
| Plaintext and host key | Replace local `BackupService.php:95-104` plaintext and S3 host key (`S3BackupDeploymentConfig.php:80-81`) as in §4. Existing missing manifest sidecar can be warning (`src/Application/Backup/LocalBackupVerifier.php:75-90`); new format rejects absent signature/manifest. |

## 9. Build split and coordination

Each PR requires **separate explicit owner approval** before build; author-minute estimates include review rounds, exclude CI wait. No new table/column planned; any schema addition requires OWNER APPROVAL REQUIRED.

| PR | Prerequisite → scope | Author minutes |
|---|---|---:|
| 1 | Approved design → shared early durable barrier/recovery access and crash tests; coordinate identity P1 first | 240–360 |
| 2 | PR1 → schema/reference and file registry, consistent snapshot, census and backup format | 360–540 |
| 3 | PR2 → key bundle, signed encrypted streaming and manual export, rotation/S3 migration | 360–540 |
| 4 | PR1–3 → fresh-install preflight, staged restore, ID mapping, byte-identical audit | 480–720 |
| 5 | PR4 + identity P1–P10 agreement → individual reauthorization, post-restore isolation and real drills; new CI job in separately approved workflow PR | 360–540 |

Identity design PR #354 proposes `cpms_patient_logins` and a barrier in P1. Coordinate shared option semantics, closed states and mapping before either implementation; its proposed login table is outside main and its schema approval is separate. After restore, patients re-establish access through the mobile + SMS OTP + national-ID flow; per owner policy both factors matching the record give full access to the own record, subject to the unchanged D-B barrier and individual revocation review. Coordinate the identity build P1–P10 so neither enrollment nor a restored link bypasses these gates. INFERENCE: estimated effort may grow after embedded-reference inventory and DB-engine testing.

## 10. Owner decisions and UNKNOWNs

| Question | Decided by owner | Rationale / tradeoff |
|---|---|---|
| Q1 custody | Recovery/decryption key also in the doctor’s possession, not solely on the server; doctor-held key file + recovery phrase and off-host copies. | Independent custody survives host loss; protecting and recovering the bundle introduces operational responsibility. |
| Q2 location | Manual download of the encrypted backup must be available to the doctor. UI warns same-host copies die with the host; retain an off-host copy and perform a restore drill. | Manual export keeps recovery independent of cloud services but requires regular doctor action. |
| Q3 passwords | Preserve password hashes in the encrypted backup; never store raw passwords. Invalidate earlier sessions, OTPs and tokens after restore. | Hashes preserve password continuity after review but remain sensitive even when encrypted. |
| Q4 target | Restore by default onto an empty, prepared independent install; do not mix existing-install data with backup data. | This avoids implicit merges and reduces destructive ambiguity. |

**Future, out of scope:** Existing-install data migration requires its own design and explicit owner approval. Minimum requirements: independently authenticated sources and backups, tenant-scoped identity/ID collision resolution, explicit mapping and conflict review, consistent data/files snapshot, fail-closed access barrier, audit continuity, rollback/recovery plan and tested post-migration isolation. This is not an option in the present restore flow.

UNKNOWN: complete structured/embedded-reference census; external storage adapters and file ownership; portable FK/rename behavior on supported MySQL/MariaDB versions; exact supported compatibility matrix; sodium availability on target installations; secure bundle provisioning UX and parameter limits; fresh-WP roles/user hash import mechanics; barrier enforcement across every entry point, direct file serving and interrupted bootstrap; restoring a pre-existing OTP/login schema without new columns; consistent snapshot across third-party writers; real recovery performance and usable timeouts. These are release blockers, not evidence of success. LIMITATION: no restore, mutation or isolation test has been executed for this design. Manager review of revocations after snapshot depends on independent current records; absent evidence, access remains denied.
