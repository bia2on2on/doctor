# Appointment Request — Design Document (DESIGN ONLY)

> # ⛔ DESIGN ONLY — NO CODE, NO SCHEMA, NO MIGRATION, NO TABLE CREATED
>
> **Revision 3 (final round)** — revises revision 2 after eight findings M1–M8 from an independent
> reviewer verified at head `d27c51a5`; revision 2 itself answered F1–F7 from the earlier review of
> revision 1 (`67c57a7e`). Every finding is mapped in §0.1. This is a
> **proposal for review**, produced under a hard two-file scope. It **does not create, reserve or
> number a migration**. Building it needs **two independent security reviews and an explicit owner
> approval**. **Owner acceptance of any implementation: NOT GIVEN.**

| | |
|---|---|
| **Base** | `origin/main` = `aec7306f6f2d7088658f7b3aaf57cb407e97b970` (fetched 2026-11-16, unchanged) |
| **Depends on** | PR **#340** (OTP Safety Slice 1, Option A) — **OPEN, draft, unmerged**; head is now **`fb38793749156cb42025578dcafa5cb1c6ab0080`** (a merge of `origin/main` into the previous head `ff7cec8`). Facts were re-read at that head: `OtpService.php`, `BookingService.php` are **byte-identical** between `ff7cec8` and `fb38793` (`git diff` shows only `DoctorDashboardPage.php`, a JS spec and `agent-log.md`). |
| **Evidence labels** | **OBSERVED** = read at the stated commit, not executed. **INFERENCE** = reasoning, not proven by execution. **LIMITATION** = not established. **UNKNOWN** = not proven. **PROPOSAL** = design position, not an owner decision. **DECIDED (mgr)** = manager decision relayed in the round-2 brief. **OQ-n** = open question (§14). |
| **Local PHP** | LIMITATION: no `php` binary here (PHP-WASM only). No PHP, WordPress, MySQL or browser was executed. CI does not exercise this document. |

## 0.1 What changed in revision 3 (finding → place)

| Finding | Change |
|---|---|
| **M1** restore/reinstall replay | §5.6: the path is **real** (dump emits `DROP`+`CREATE` for `cpms_rate_limits`, `BackupSqlDumper.php:56-65`; restore executes it, `BackupService.php:358-361`). Options A/B/C weighed; **C chosen** — a monotonic `cpms_arreq_proof_epoch_min` option written after restore and before uninstall, proofs with `iat ≤ epoch` rejected. **Build item, OQ-15.** No second table. §9 **T11** added; T-19 tests it. |
| **M2** national ID in a name field | New **§5.14**: names reject **every** `\p{Nd}` digit in any script before storage, uniform 400; every free-text field and every output path tabulated; the GET patient search is **normatively forbidden** from carrying a NID in `q`. T-20. |
| **M3** key strength | §5.7: checkable rule (≥ 32 bytes, ≥ 12 distinct bytes, denylist, mutual inequality, override path) instead of a bare length; LIMITATION states a rule cannot prove randomness and that installer-generated keys are the real fix (OQ-18). T-21. |
| **M4** `wp_salt` | §2 **V20** and §5.7 corrected: valid constants are used **directly**; the DB option is a fallback. Design choice kept for fail-closed determinism only. Same correction in the ledger entry. |
| **M5** cleanup retention | §5.6: margin restated as **48 h after expiry under the default**, explicitly *not* protection during validity; `max(1, …)` clamp noted. T-16 tests the default-retention margin, not T+600. |
| **M6** operational claims | §5.6 (a): `RESTRICT` blocks slot deletion until **hard deletion**, which can stall — operator sees an FK error; OQ-17. §5.11 (b): the manifest pre-loop tests a literal `cpms_` prefix that manifest names do not carry; the drops that happen are the dump's. |
| **M7** accounting | §5.3: replay **does** write (counter increment) and duplicate does **not** run the cap count — both revision-2 statements were false. Replaced by a one-row-per-outcome accounting table; T-22 pins it. |
| **M8** transaction/concurrency | New **§5.15**: boundary, statement order, lock order, per-outcome commit/rollback, same-proof race (T-17) and cap-boundary race (T-18) on MySQL 8 **and** MariaDB 11.4.13; T-4's fixture now exceeds the batch limit; T-23 gives the header-comparison exclusion rule. |
| **C table** | §6 re-derived against the final text with a test per row: 8 MET, 3 UNKNOWN (C2/C10/C11), C6 NOT MET here (owned by #340), C7 MET by design with an unapproved build dependency. |

Revision 2's F1–F7 mapping is retained below for traceability: **F1** §5.6 replay marker · **F2** §5.7 keys + closed reason codes · **F3** §5.3 · **F4** §5.9 · **F5** §5.13 · **F6** §10 · **F7** §6.

## 0. Decisions at a glance (NON-NORMATIVE; sections win)

| # | Decision | C |
|---|---|---|
| D1 | **One** new table `cpms_appointment_requests`; a second table is a STOP/owner question. | C1 |
| D2 | Minimal columns; pending TTL **72 h**; 1 open row per (clinic, mobile) by unique index; 500 open rows per clinic; per-IP bucket on the existing table. | C2 |
| D3 | National ID stored **only** as a keyed HMAC + key version; no plaintext, no reversible form in this table. | C5 |
| D4 | Reject invisibles/BOM/zero-width; then fold Persian/Arabic-Indic digits; then the existing checksum; never use the validator's internal `\D` strip. | C5 |
| D5 | Mobile stored in clear (call-back requirement) — contrast stated, not hidden. | C2/C5 |
| D6 | The public path **never reads a patient table**. | C3 |
| D7 | One fixed `202` body for well-formed requests; **per-observable** uniformity table; residual differences declared. | C3 |
| D8 | Possession proof = stateless HMAC token; single-use enforced by a **marker row in the existing `cpms_rate_limits`** (§5.6). | C7 |
| D9 | `/otp/verify` gains the proof + its TTL, uniformly for every valid code. | C7 |
| D10 | **No capacity hold**; no penalty. | C8 |
| D11 | Tenancy from the trusted side only; FKs; UTC; InnoDB; no fixed id 1. | C9 |
| D12 | New capabilities `cpms_appt_request_read` / `cpms_appt_request_decide`; secretary both, manager read. | C10 |
| D13 | Approval is a state change only — no user/session/patient/link/appointment. | C4 |
| D14 | Atomic decision via guarded `UPDATE`, affected rows = 1, loser `409`. | C10 |
| D15 | Existing error codes only (no new vocabulary). | scope |
| D16 | 72 h pending → `expired`; terminal rows hard-deleted at 14 d; recurring job + opportunistic sweep. | C2 |
| D17 | Backup inclusion is automatic; privacy cost stated. | C12 |
| D18 | 14-day hard delete is the primary privacy control; core eraser/exporter is email-keyed and cannot find mobile-keyed rows. | C12 |
| D19 | One new `POST` under `booking/`, body-only; the existing `/clinic/v1/booking/` scope skip already covers it (to be confirmed at build). | C9 |
| **D20** | **Closed reason-code vocabulary** for staff decisions (§5.7b). | C10 |
| **D21** | **Key material outside the database**, derived from wp-config constants (§5.7). | C5 |
| **D24** | **Requester names carry no digits of any script**; violated ⇒ uniform 400 (§5.14). | C5 |
| **D25** | **One transaction** per request, marker row lock held through read-back and commit; clinic named lock around count+insert (§5.15). | C10/C13 |
| **D26** | **Restore/uninstall writes a monotonic "proofs not valid before" epoch option** (§5.6, M1 — build item, OQ-15). | C7 |

---

## 1. Owner-decided inputs (restated, not re-decided)

Login is mobile + SMS OTP; booking identifies the patient by Iranian national ID (10 digits); #340 makes OTP verify possession-only; the public booking widget cannot create appointments; hold/confirm need `requirePatient`; manager decision **option B** = one new table for requests from people who proved possession of a mobile but have no linked patient; reception reviews and decides; reception-only booking exists meanwhile; appointments need `patient_id`.

**OBSERVED:** `cpms_appointments.patient_id BIGINT UNSIGNED NOT NULL` (`2026_09_05_0001_initial_schema.php:226`) with `fk_appt_patient` (`:252`) — an unlinked requester cannot be represented in `cpms_appointments`.

**OBSERVED (#340 at `fb38793`):** the historical-NULL confirm path no longer creates a Patient: the zero-active-link branch throws one uniform `CLINIC_VALIDATION_FAILED`/`400` (`BookingService.php:902-910`); `verify()` returns `{user_id:0, patient_links:[], is_new_user:false, session_issued:false}` for every valid code (`OtpService.php:556-559`, docblock `:379`); a column-absent install fails closed with `CLINIC_SERVICE_UNAVAILABLE`/503 (`BookingService.php:3075`, `:3099`). These are **unmerged**; C6/C7 remain conditional (§6, OQ-R1).

---

## 2. Verification of the facts I was given (re-read at `aec7306`)

| # | Claim | Result |
|---|---|---|
| V1 | `NationalIdValidator.php:12-35` strips non-ASCII digits | **CONFIRMED, with a named consequence.** `preg_replace('/\D/','')` (`:14`) has no `/u`, so Persian/Arabic-Indic digits are removed byte-wise; an all-Persian input collapses to `''` and fails the length test (`:15`); a **mixed** input can pass and is then stored verbatim (V2). INFERENCE (no PHP executed). Checksum `:22-35`; all-same-digit rejection `:18`; `mask()` `:41-46`. |
| V2 | `PatientService.php:682-689` returns the original | **CONFIRMED** (method spans `:682-690`): `:684` `trim()`, `:685` `isValid()`, `:689` `return $v;` — the trimmed **original**. |
| V3 | `cpms_patients.national_id` nullable `VARCHAR(10)`, unique per clinic | **CONFIRMED**: `:61`, `:83` `u_pat_nid (clinic_id, national_id)`. |
| V4 | "mobile is a non-unique attribute" | **CONTRADICTED for `cpms_patients`**: `:82` `UNIQUE KEY u_pat_mobile (clinic_id, mobile)`. Non-unique only for `cpms_patient_identities.normalized_mobile` (`2026_09_09_0019_…php:10-15`). **This design relies on neither.** |
| V5 | appointments need `patient_id` | **CONFIRMED** (§1). |
| V6 | OTP limits | **CONFIRMED**: `Settings/Settings.php:32-40` (120 s TTL, 5 attempts, 60 s cooldown, 3/day, 900 s lockout, 10/hour, `otp.ip_hourly_max`=10); quota peek `OtpService.php:882-899`, charge `:301-302`; **verify** IP bucket hard-coded 20/3600 at `:404`. |
| V7 | five public callbacks | **CONFIRMED, exactly five** `permPublic()` routes: `/availability`, `/booking/quote` (`BookingController.php:43,47,64,68`), `/health` (`HealthController.php:18,22`), `/otp/request`, `/otp/verify` (`OtpController.php:33,37,53,57`); `permPublic()` returns `true` (`RestBase.php:142-145`). |
| V8 | `/patient/me` returns the full national ID | **CONFIRMED**: route `PatientController.php:64-76` → `PatientService::me()` `:128-130` → `publicView()` `:779-795`, `:787` unmasked. Staff **search** masks instead (`searchView()` `:831-844`, `:839`). Note only, out of scope. |
| V9 | tenancy primitives | **OBSERVED**: `ScopeContext` request-scoped, fail-closed (`Application/Scope/ScopeContext.php:15-45`). |
| V10 | job machinery | **OBSERVED**: `App::RECURRING_JOBS` (`Bootstrap/App.php:2616-2633`), handler registration (`:2475-2490`), `JobScopeRegistry` (`Application/Jobs/JobScopeRegistry.php:44-70`), OS-cron preference (`App.php:413`), drift test (`tests/Integration/Phase2JobScopeRedFoundationTest.php:576`). |
| V11 | backup inclusion | **OBSERVED**: `SHOW TABLES LIKE '{prefix}cpms_%'` (`Infrastructure/Backup/BackupSqlDumper.php:110-129`), called at `Application/Backup/BackupService.php:100`. No hand-written list exists. |
| V12 | uninstall | **OBSERVED**: `uninstall.php:8-20` — nothing dropped unless `cpms_wipe_on_uninstall`; when set, all `{prefix}cpms_%` tables are dropped. |
| V13 | audit masking | **OBSERVED**: `MASK_KEYS` (`AuditLogger.php:26`) masks an **exactly-named** key (`:249`); `FORBIDDEN_KEYS` is lower-cased before comparison (`:246`) but `MASK_KEYS` is not → a key named `National_ID` would **not** be masked. Recursion `:253-256`. |
| V14 | pre-existing NID-existence oracle | **OBSERVED (out of scope)**: `assert_unique_national_id()` throws «این کد ملی متعلق به بیمار دیگری است» (`Application/Patients/PatientService.php:850-857`), reached from `updateMe()` `:147-152` and create `:429,442`. |
| V15 | raw mobiles in a shared table | **OBSERVED**: `RateLimiter::hit()` writes the key verbatim (`Infrastructure/Security/RateLimiter.php:30-40`; PK `window_key,window_id`, `0001:723-728`); OTP passes `'otp-day:'.$mobile` / `'otp-hour:'.$mobile` (`OtpService.php:301-302,883,890`) while the issuance guard hashes it (`:227`). **Rule: every new bucket key in this design is hashed.** |
| V16 | `cpms_otp_tokens` | **OBSERVED**: `0001:116-128`; `clinic_id` NULL + FK, **no backfill** (`2026_09_20_0021_…php:16-40`); `purpose` is a **closed ENUM** → a new purpose is a schema change. |
| V17 | #340 verify envelope | **OBSERVED at `fb38793`**: `:556-559`, docblock `:379`; `clinic_id` from the challenge row (`:447`). |
| V18 | #340 column-absent behaviour | **OBSERVED at `fb38793`**: memoized `information_schema.columns` probe `BookingService.php:3075`, throw at `:3099`; comment `:3089-3092` states the envelope is the same public contract as `RuntimeSchemaGate`. |
| **V19** | **`RuntimeSchemaGate` blocks every route while a migration is pending** | **REFUTED (F5, this is the revision-1 error).** `App::is_runtime_blocked_by_pending_schema()` returns **`false`** when engine evidence is `RUNTIME_EVIDENCE_SUPPORTED` (`App.php:626-628`) and **`false`** again when evidence is `RUNTIME_EVIDENCE_UNREADABLE` (`:630-634`); only the **UNSUPPORTED** branch consults pending migrations (`:636-639`, constant `DatabaseCompatibilityGate.php:56`). So on a normally supported engine a **pending migration does not block anything**. |
| **V20** | **`wp_salt()` always mixes a database-stored secret** | **WRONG, corrected in revision 3 (M4).** Re-read in core `wp_salt()` (`github.com/WordPress/WordPress/blob/master/wp-includes/pluggable.php`): a defined constant is used **directly** when non-empty and not a duplicated/placeholder value; the `get_site_option()` → `wp_generate_password()` fallback applies only to undefined, empty, duplicated or placeholder constants. The **design choice stands** (read constants directly) for **fail-closed determinism**, not because `wp_salt()` is always DB-backed. |
| **V21** | rate-limit cleanup semantics (needed by D8's marker lifetime) | **OBSERVED**: `RateLimiter::cleanup($olderThanSec)` deletes where `window_id * window_sec < now - olderThanSec` (`RateLimiter.php:79-87`); the job passes `2*86400` (`Application/Jobs/RateLimitCleanupHandler.php:20`); `window_sec` added by migration 0009 with that documented predicate (`2026_09_07_0009_rate_limit_window_sec.php:5-15`). `window_id` is `INT UNSIGNED` (`0001:725`). |

**LIMITATION:** V1's mixed-digit consequence and all timing statements are INFERENCE; no engine was run.

---

## 3. The single table (C1) — data dictionary

`{wp_prefix}cpms_appointment_requests`, `InnoDB`, `utf8mb4_unicode_ci` (matching `0001:*`), name via `$db->table()` (`Infrastructure/Db/CpmsDb.php:78-83`).

| # | Column | Type | Null | Key / index | Purpose | Retention |
|---|---|---|---|---|---|---|
| 1 | `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | NO | PK | surrogate id | row life |
| 2 | `clinic_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_clinic` → `cpms_clinics(id)`; `idx_arreq_clinic_status (clinic_id,status,created_at)` | tenancy (D11) | row life |
| 3 | `location_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_location` → `cpms_locations(id)` | branch, server-resolved from the persisted slot (`2026_09_09_0013_…php:10,19-33`) | row life |
| 4 | `clinician_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_clinician` → `cpms_clinicians(id)` | requested doctor | row life |
| 5 | `slot_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_slot` → `cpms_schedule_slots(id)` — **no `ON DELETE` clause ⇒ `NO ACTION`/`RESTRICT`** | requested slot | row life |
| 6 | `slot_date` | `DATE` | NO | `idx_arreq_slot` | snapshot (`0001:228`) | row life |
| 7 | `slot_time` | `TIME` | NO | `idx_arreq_slot` | snapshot (`0001:229`) | row life |
| 8 | `mobile` | `CHAR(11)` | NO | `idx_arreq_mobile (clinic_id,mobile)` | normalised `09xxxxxxxxx` (`MobileValidator::normalize()`, `:22-52`) | with row |
| 9 | `open_marker` | `TINYINT(1)` | **YES** | **`UNIQUE u_arreq_open (clinic_id,mobile,open_marker)`** — `1` while pending, `NULL` after | at most one open request per (clinic, mobile) | n/a |
| 10 | `national_id_hash` | `CHAR(64)` | NO | `idx_arreq_nid (clinic_id,national_id_hash)` | HMAC-SHA256 of the normalised ASCII ID (§5.7) | with row |
| 11 | `national_id_key_version` | `TINYINT UNSIGNED` | NO, default 1 | — | **label** of the derivation generation; not a rebinding key (§5.7) | with row |
| 12 | `given_name` | `VARCHAR(120)` | YES | — | optional; validated as at `PatientService.php:657-679` | with row |
| 13 | `family_name` | `VARCHAR(120)` | YES | — | as #12 | with row |
| 14 | `is_minor_declared` | `TINYINT(1)` | NO, default 0 | — | self-declared "under 18"; no birth date is collected | with row |
| 15 | `status` | `ENUM('pending','approved','rejected','expired','cancelled')` | NO, default `pending` | `idx_arreq_clinic_status` | closed vocabulary | D16 |
| 16 | `decided_by_wp_user_id` | `BIGINT UNSIGNED` | YES | — | who decided | with row |
| 17 | `decided_at` | `DATETIME(3)` | YES | `idx_arreq_terminal (status,decided_at)` | when (UTC) | D16 |
| 18 | `decision_reason_code` | `ENUM('duplicate_request','slot_unavailable','identity_unverifiable','not_our_patient','scope_out_of_area','requester_declined')` | YES | — | **closed** reason vocabulary (D20); no free text | with row |
| 19 | `created_at` | `DATETIME(3)` | NO | `idx_arreq_clinic_status` | UTC (`CpmsDb::nowUtcSql()`, `:384`) | — |
| 20 | `expires_at` | `DATETIME(3)` | NO | `idx_arreq_retention (status,expires_at)` | `created_at + 72 h` | — |

**Column count: 20** (revision 1 had 21). **Removed from revision 1:** `proof_fingerprint` / `u_arreq_proof` — the replay marker moved to the existing `cpms_rate_limits` (§5.6), so the request row now holds **no proof artefact at all**. `decision_reason VARCHAR(255)` → `decision_reason_code` (D20).

**Why `idx_arreq_nid` is deliberately NON-unique (C3):** a `UNIQUE (clinic_id, national_id_hash)` would be an oracle in its own right — the duplicate-key error is thrown **before** the row exists, and it would tell a caller whether that person already has a request in that clinic. It is also wrong semantically: an ID may legitimately have more than one request over time. The index exists only for the staff-side lookup, and no public path reads it.
**Invariants (test-enforced, §10):** (i) `open_marker = 1` ⇔ `status='pending'`; (ii) `pending` ⇒ `decided_at IS NULL`; (iii) terminal ⇒ `decided_at` and `decided_by_wp_user_id` non-NULL and `open_marker IS NULL`; (iv) `decision_reason_code` ∈ the ENUM or NULL; (v) `national_id_hash` is 64 lowercase hex.

**Deliberately absent columns:** `appointment_id`/`converted_at` (D13; a future conversion adds a column to *this* table, keeping C1), `mrn`, `birth_date`, `address`, `passport_no`, any free text from the requester beyond the two names, `ip`, `user_agent`, `proof`, `national_id` plaintext.

**C1 check:** one `CREATE TABLE`, zero `ALTER TABLE`, zero new options-tables' schema. The replay marker uses a **pre-existing** table (`cpms_rate_limits`), which is *not* a second new table; §14 OQ-R2 asks the reviewer to confirm that reading.

---

## 4. Lifecycle (closed state machine)

```
  (public) valid proof ──► ┌───────────┐
                           │  pending  │  created_at + 72 h
                           └──┬──┬──┬──┘
             staff approve    │  │  │   staff reject / cancel
                        ┌─────┘  │  └─────┐
                        ▼        │        ▼
                 ┌──────────┐    │   ┌───────────┐
                 │ approved │    │   │ rejected  │
                 └──────────┘    │   └───────────┘
                                 │ cancelled (same route)
                                 ▼
                            ┌─────────┐
                            │ expired │  job, or lazy transition on read
                            └─────────┘
  all four terminal states ──► hard delete 14 d after decided_at/expires_at (D16)
```

| From | To | Trigger | Guard / side effect |
|---|---|---|---|
| — | `pending` | `POST /booking/request` | proof marker first-use (§5.6) + validation; 1 INSERT; audit `APPOINTMENT_REQUEST_CREATED` |
| `pending` | `approved`/`rejected`/`cancelled` | `POST /staff/…/requests/{id}/decide` | guarded UPDATE, affected rows = 1; `decision_reason_code` (closed); audit `APPOINTMENT_REQUEST_DECIDED`; **no other write** (D13) |
| `pending` | `expired` | job or read-time lazy transition | sets `status='expired'`, `open_marker=NULL`, `decided_at` stays NULL; no capacity decrement, no penalty |
| terminal | — | — | terminal; further decide → `409 CLINIC_INVALID_TRANSITION` |

**Deletion vs replay:** deleting a request row (expiry, 14-day retention, or an FK action) does **not** delete the proof marker, which lives in another table (§5.6). The invariant is structural, not timed.

---

## 5. Decisions

### 5.1 D1 — one table
Exactly one new table. Rejected: `cpms_slot_holds` (capacity-bearing, `0001:204-218`), a `patient_id`-NULL appointment (`:226` is NOT NULL), a WP option/post type. Risk: a new personal-data store (D17).

### 5.2 D2/D16 — minimal data, TTL, caps, cleanup (C2)
72 h pending TTL; terminal hard-delete at 14 d; caps: **1 open row per (clinic,mobile)** by `u_arreq_open` (a `UNIQUE` index permits multiple NULLs — dev.mysql.com/doc/refman/8.4/en/create-index.html, "Unique Indexes", so the NULL marker costs no counter), **500 open rows per clinic** by a counted guard inside the insert transaction serialised with `GET_LOCK('cpms_arreq:'.clinic_id, 2)` — OBSERVED the primitive in use for job ticks at `App.php:2657`, and a per-IP bucket `hash('sha256','arreq-ip:'.ip)` on `cpms_rate_limits`.
**TTL options:** 24 h (a Friday-evening request may expire before a Saturday shift), 48 h (same, one day less), **72 h (recommended, covers a weekend + one working day)**, 7 d (larger data window, no operational gain). **Retention options:** 7 d (tighter, may surprise a monthly review), **14 d (recommended)**, 30 d (against "short retention").
**If cleanup does not run:** (i) nothing is *shown* past `expires_at` — lists filter `status='pending' AND expires_at > UTC_NOW`, and a read that meets an expired pending row performs the same guarded transition lazily (idempotent, affected-rows pattern of `HoldsExpireHandler.php:44-55`); (ii) the **creation path** deletes up to 50 terminal rows for that clinic per successful request; (iii) `bin/cpms jobs tick` is the supported OS-cron path (`App.php:413`), since WP-Cron runs only on page loads (developer.wordpress.org/plugins/cron/). **Residual (kept):** on a site with no traffic and no OS cron, terminal-row deletion waits for the next request; the rows stay invisible and unreadable, and the caps still bound new growth.

### 5.3 D7/D15 — response contract and check order (C3) — **revised for F3**

**Check order (explicit, normative).** Cheap, non-consuming checks run and fail **before** the proof is spent; the proof is consumed at one point. Deliberate: a mistyped national ID must not cost the requester a new SMS.

| Step | Check | On failure | Consumes the proof? |
|---|---|---|---|
| 1 | Envelope/shape (missing fields, wrong types, bad JSON) | `400 CLINIC_VALIDATION_FAILED` | no |
| 2 | national-ID normalisation + checksum (D4) | `400 CLINIC_VALIDATION_FAILED`, same message | no |
| 3 | Name policy (§5.14, no digits in any script) | `400 CLINIC_VALIDATION_FAILED`, same message | no |
| 4 | Slot/clinician/location resolution, slot open (server-derived) | existing vocabulary (`404`/`422`/`409`) | no |
| 5 | Proof verification (signature, expiry, purpose, mobile match, clinic) | `400 CLINIC_VALIDATION_FAILED` | no (a forged/expired proof has no row of its own to write) |
| 6 | **Consume the proof** — marker upsert + read-back (§5.6, §5.15) | **replay** (`hits ≥ 2`): return the standard `202` body **without** touching the request table — not a fall-through (a fall-through would re-create a row when the original one has since been deleted, exactly the F1 hole) | **yes** — the counter is incremented |
| 7 | Duplicate open request for (clinic, mobile) | `202`, no insert | already consumed at 6 |
| 8 | Per-clinic cap, then insert | `429 CLINIC_RATE_LIMITED` / `202` | already consumed at 6 |

**Consequence of that order, stated plainly:** a **duplicate at capacity returns `202`, not `429`** (7 precedes 8). A cap refusal **does** consume the proof, so a requester at the cap needs a fresh OTP (**OQ-14**). A validation failure (steps 1–3) **never** consumes it, so a typo is retryable. **Residual:** steps 1–5 can be probed freely; they read no patient data, are rate-limited per IP (§5.2), and cannot test a national ID because the ID is only ever hashed (§5.4).

**Accounting — one row per outcome (M7).** This table *is* the observable contract; §5.15 gives the transaction each row runs in.

| Outcome | HTTP | Body | Code | Marker write | Request-row write | Audit rows | IP counter | Headers |
|---|---|---|---|---|---|---|---|---|
| **accepted** (insert) | 202 | `{"data":{"status":"received"}}` | — | 1 upsert (+1 hit) + 1 read | 1 INSERT | 1 `APPOINTMENT_REQUEST_CREATED` | 1 | standard |
| **duplicate** | 202 | identical to accepted | — | 1 upsert (+1 hit) + 1 read | **0** | 1 `APPOINTMENT_REQUEST_DUPLICATE` | 1 | standard |
| **cap refused** | 429 | generic rate-limit envelope | `CLINIC_RATE_LIMITED` | 1 upsert (+1 hit) + 1 read | 0 | 1 `APPOINTMENT_REQUEST_CAP_REFUSED` | 1 | standard |
| **replay** | 202 | identical to accepted | — | 1 upsert (**hit → ≥2**) + 1 read | 0 | 1 `APPOINTMENT_REQUEST_REPLAY` | 1 | standard |
| **invalid proof** (step 5) | 400 | validation envelope | `CLINIC_VALIDATION_FAILED` | **0** | 0 | **0** (no audit spam from unauthenticated callers) | 1 | standard |
| **validation failure** (1–4) | 400/404/422/409 | as §5.10 | as §5.10 | 0 | 0 | 0 | 1 | standard |
| **key missing / weak** | 503 | neutral envelope | `CLINIC_SERVICE_UNAVAILABLE` | 0 | 0 | 0 | 1 | standard |
| **SQL error** | 503 | neutral envelope | `CLINIC_SERVICE_UNAVAILABLE` | **rolled back** | rolled back | rolled back | 1 | standard |

**Corrections to revision 2's wording, made explicit because they were wrong:** (i) a replay **does** write — it increments the marker counter; "no write at all" was false. (ii) A **duplicate does not perform the cap count** — step 7 returns before step 8; the earlier "same minus the final insert" implied otherwise. The table above replaces both statements.

**Residual, declared as LIMITATION (not claimed away):** accepted writes **one more row** (the request) than duplicate/cap, and every outcome differs in statement count and therefore in timing. The uniformity claim is scoped to what the table shows: **status, code, body, headers and the absence of any patient/NID read** across accepted/duplicate/cap — *not* to write counts or to constant time. **F3(c):** the duplicate branch is **not** reachable only by the original requester: anyone who proves possession of the same mobile in the proof's 600 s window reaches it. That discloses one fact — "this mobile has an open request" — to someone who controls that mobile (they receive its SMS). It discloses no national-ID, patient or clinic-record fact and cannot test a NID. Accepted on that basis.

**Cap disclosure:** `429` at step 8 reveals that a clinic holds ≥ cap open requests. That is clinic load, not patient existence (**OQ-7**).

### 5.4 D6 — no patient read (C3)
The public route does not query `cpms_patients`, `cpms_patient_user_links` or `cpms_patient_identities`. There is therefore **no existence-dependent branch to equalise**: the three cases in C3 are not distinguished because they are not computed. This is the primary control; §5.3's table is the secondary one.

### 5.5 D4 — national-ID normalisation (C5)
Order: (1) reject invalid UTF-8; (2) reject BOM `U+FEFF`, `U+200B-200D`, `U+2060`, bidi controls (reuse `VisibleText::has_bidi_control()`, `Domain/Validators/VisibleText.php:77`), C0/C1 controls and any Unicode space — **no trimming, no stripping**; (3) fold Persian `U+06F0-06F9` and Arabic-Indic `U+0660-0669` via the existing closed map (`MobileValidator::fold_digits()`, `:77-102` — OBSERVED `strtr` over a closed 20-entry map); (4) require exactly 10 ASCII digits; (5) `NationalIdValidator::isValid()` (`:12-36`). Never rely on `\D` (`:14`) for normalisation. Rationale: the codebase already **rejects rather than silently removes** bidi controls in names (`PatientService.php:661-669`). Consequence not fixed here: `PatientService::cleanNationalId()` keeps its mixed-digit drift (V1/V2, **OQ-5**).

### 5.6 D8 — possession proof and the replay invariant (C7) — **rewritten for F1**
**Token:** `base64url(json).base64url(hmac_sha256(k_proof, json_b64))`, JSON `{"v":1,"jti":"<16 random bytes hex>","mh":"<HMAC(k_proof,'mobile',normalised_mobile)>","cl":<clinic_id>,"p":"appointment_request","iat":<unix>,"exp":<unix>}`; lifetime 600 s, and `iat` is **required** because the M1 epoch check compares against it. It is **not** a session, cookie, capability or user; clinic comes from the OTP challenge row (`OtpService.php:447`), never from the client.
**Single-use marker — one row in the existing `cpms_rate_limits`:**
```
INSERT INTO cpms_rate_limits (window_key, window_id, window_sec, hits)
VALUES (CONCAT('arreq-proof:', H(jti)), <proof exp unix>, 1, 1)
ON DUPLICATE KEY UPDATE hits = hits + 1;      -- then read `hits` back
```
**First use ⇔ `hits = 1`.** Replay ⇔ `hits ≥ 2`.
**Why not affected rows (deliberate, and the reason for this shape):** MySQL documents that with `ON DUPLICATE KEY UPDATE` the affected-rows value is 1 for an insert, 2 for an update, and **0 when the existing row is set to its current values** — and that `CLIENT_FOUND_ROWS` changes 0 to 1 (dev.mysql.com/doc/refman/8.4/en/insert-on-duplicate.html, "INSERT … ON DUPLICATE KEY UPDATE Statement"). The `hits = hits` no-op idiom used by the OTP issuance guard (`OtpService.php:230-238`) therefore reports a value whose meaning depends on a connection flag; it is safe **there** because the guard only needs "did any write fail". A single-use test needs an exact answer, so this design reads back the counter instead, inside the same transaction. INFERENCE: the upsert and the read are atomic under the row lock the insert takes; not measured.
**Cost:** one non-personal row per proof use (the key is a hash of a random `jti`), bounded by the OTP quota. Not a second table; stores no NID, mobile or code.
**The margin, stated correctly (M5).** The only automated delete of `cpms_rate_limits` is `cleanup.rate_limits` → `cleanup(2*86400)`, whose predicate is `window_id * window_sec < now − olderThanSec` (OBSERVED `RateLimiter.php:79-86`, `RateLimitCleanupHandler.php:20`). With `window_id = exp` (the **proof expiry**) and `window_sec = 1`, the marker's own window starts at the moment the proof dies. Two numbers exist and both are stated because they differ: the **method default** is `cleanup(int $olderThanSec = 86400)` (OBSERVED `:79`), while the **only production caller** passes `2 * 86400` (OBSERVED `RateLimitCleanupHandler.php:20`). So the post-expiry buffer is **86 400 s (24 h)** under the method default and **172 800 s (48 h)** as the job actually runs. This design specifies the **job** figure and treats the method default as the floor. Either way the margin is far larger than the 600 s proof life — but it is **not** protection during validity.

> **Invariant I1 (restated, narrower than revision 2):** the marker row exists for the whole 600 s the proof is valid, and afterwards for **at least 86 400 s whatever caller runs** (the method floor), **172 800 s** under the production job. Single-use *during* validity therefore does **not** depend on any margin: a row can only be removed once `window_id * window_sec` is older than the threshold, which can never be true while `now < exp`. Revision 2's "outlives its proof by ≥ 172 800 s" was stated as an invariant of the schema; it is not — it is a property of **one configuration value** and it breaks if that value is lowered (**OQ-16**).

**What a lowered retention would and would not do (M5, correction).** `cleanup()` clamps to `max(1, $olderThanSec)` (OBSERVED `:81`), so the threshold can approach 1 s. Lowering it would **not** break single-use inside the 600 s window — the predicate still cannot match an unexpired marker — but it would destroy the 48 h post-expiry buffer, which is what protects against a proof replayed **just after** a backup restore rolls the counter back (M1). So: retention protects the *margin*, not the *window*.

**Delete paths, enumerated:** (a) request-row deletes — cannot touch the marker (different table); (b) `cleanup.rate_limits` — bound by the predicate above, and the only automated `DELETE` against that table in `src/` (`RateLimiter.php:84`); (c) **manual SQL, `uninstall.php` wipe, or a backup restore** — see M1 below. **T-12 and T-16 pin (b).**

**M1 — restore / reinstall replay (analysed, option chosen).** OBSERVED: the dump emits `DROP TABLE IF EXISTS` + `CREATE TABLE` for **every** table it contains, including `cpms_rate_limits` (`BackupSqlDumper.php:56-65`), and `restoreApply` executes that dump (`BackupService.php:348-361`). OBSERVED: `uninstall.php:8-18` drops all `cpms_%` tables when `cpms_wipe_on_uninstall` is set, and a reinstall creates them empty. **So a restore of a pre-consumption snapshot, or a wipe + reinstall, erases the consumed markers while a proof minted before the snapshot is still inside its 600 s life.** That is a real replay path and revision 2 missed it.

*Options weighed:*
- **(A) Short TTL + declared residual.** Do nothing structural; rely on the 600 s window being shorter than a restore. **Rejected as the sole answer:** a restore is exactly the moment an operator replays stale state quickly, and the window is chosen by the attacker, not the clock.
- **(B) An epoch kept outside the DB dump.** **Rejected:** the only outside-DB store is wp-config, which the plugin cannot write, and any option is inside the dump.
- **(C) A "proofs not valid before" marker written after a restore/uninstall.** **CHOSEN.**

**Chosen option C, precisely.** One integer option `cpms_arreq_proof_epoch_min` (a UTC timestamp, **not** a table — C1 holds) is read on every proof verification; a proof whose `iat` is **≤** that value is rejected as `CLINIC_VALIDATION_FAILED`/400 *before* any marker write. It is written as `epoch = max(current_epoch, now)`, which is what makes it monotonic — the rule is the `max()`, not an assumption about who calls it, so a restore of an **older** snapshot can never lower it. It is written at the end of two operations:
- **restore** — the smallest change is in `BackupService::restoreApply` immediately after the dump executes, i.e. around `BackupService.php:358-362` (inside the existing `transactional` closure, after the last statement);
- **uninstall** — `uninstall.php:8-18`, before the drops (the option itself is **never re-created** by this design, and the reader treats **absent** as `0` — "no invalidation recorded" — which is the documented default rather than a claim about what activation does; OBSERVED the plugin does create at least one option on its own path, `App.php:1049`, but this design requires **no** such write).

Because the value is monotonic and written *after* the restore, a proof minted before the snapshot has `iat ≤ epoch_min` and dies. **This is a BUILD item and it touches two files outside this document's scope** — it is written here as a design requirement, **not** as an approved change (**OQ-15** asks the owner to authorise it; without approval, option A applies and the residual must be accepted in writing). Cost when it fires: a requester whose code was minted seconds before an operator restored a backup must request a new code. That is a rare, operator-initiated event.

**Rejected alternatives:** a marker inside the request table (revision 1 — broken, F1); a new proofs table (C1); a new `otp_tokens.purpose` value (schema change, V16).

**Cascade decision (F1(b)) — corrected for M6a.** `fk_arreq_slot` carries no `ON DELETE` clause, i.e. `NO ACTION`/`RESTRICT` (dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html, "Referential Actions"), matching `fk_appt_slot` (`0001:253`). `CASCADE` is rejected because it would silently delete a pending request when a slot is removed. **Revision 2 said RESTRICT only blocks until the request expires or is decided — that was wrong:** a **terminal** row still references the slot until the 14-day hard delete removes it, and that delete can be delayed indefinitely on a site whose cleanup never runs (§5.2). OBSERVED that slot deletion is real and unreconciled with FKs: `ScheduleRepository::deleteFutureEmptySlots()` (`:341-351`) issues a plain `DELETE` filtered on `booked_count = 0` and does **not** handle `fk_appt_slot` either — so the repo already has this shape of constraint, and adding one more reference does not create a new class of failure. **What an operator sees:** the slot delete fails with a foreign-key error and the slot stays; the operator must cancel/approve the requests and wait for cleanup, or run the cleanup job. That is a poor experience and is recorded as **OQ-17** (acceptable vs. a nullable `slot_id` that detaches terminal rows). No claim of bounded blocking is made.

**Collision, step by step** (F1(a)): same mobile, a **different** `jti`, while an open request row exists → step 5 writes a **new** marker row (different key, so no PK collision) and consumes that proof; step 6 then answers `202` with no insert; the **first** proof's marker is untouched. Replaying the *first* proof later hits its own marker (`hits ≥ 2`) → `202`, no row, regardless of whether the original request row still exists. So the previously-possible "wait for the row to close, then replay the old proof" path is closed — the marker is keyed on the proof, never on the request.
**`/otp/verify` change (D9):** two additive fields — `booking_proof`, `booking_proof_expires_in` — for **every** valid code, with no branch on whether the mobile/user/patient exists (in #340 there is no such branch, V17). Not an enumeration leak: the values depend only on (mobile, challenge clinic, clock, random `jti`). Pre-existing, not introduced here: a historical-NULL challenge fails closed with `CLINIC_SCOPE_REQUIRED` before a proof can be minted (`OtpService.php:439-445`).

### 5.7 D21/D3 — key material and national-ID storage (C5) — **rewritten for F2**
**(a) Where the key lives (DECIDED (mgr)).** Key material is **outside the database**:
`IKM = AUTH_KEY ‖ SECURE_AUTH_KEY ‖ AUTH_SALT ‖ SECURE_AUTH_SALT ‖ LOGGED_IN_KEY ‖ LOGGED_IN_SALT ‖ NONCE_KEY ‖ NONCE_SALT` (wp-config constants, in that fixed order). Requiring **all eight** rather than the three `CredentialVault` uses means a site with a partially-stubbed wp-config fails closed instead of silently landing on a weak key (**OQ-R8** asks whether eight is too strict an install requirement, or whether `CPMS_ARREQ_KEY` should be the documented way out).
`k_nid  = hash_hkdf('sha256', IKM, 32, 'cpms-arreq:nid:v1')`, `k_proof = hash_hkdf('sha256', IKM, 32, 'cpms-arreq:proof:v1')` — distinct domain-separation labels, so a compromise of one does not give the other (php.net/manual/en/function.hash-hkdf.php, `info` parameter). An optional dedicated constant `CPMS_ARREQ_KEY` overrides IKM when defined and ≥ 32 bytes.
**Why not `wp_salt()` — corrected (M4).** Revision 2 (and V20) claimed `wp_salt()` *always* mixes a database-stored secret. **That is false.** OBSERVED in WordPress core `wp_salt()` (`wp-includes/pluggable.php`, `wordpress.org` / github.com/WordPress/WordPress/blob/master/wp-includes/pluggable.php): a defined constant is used **directly** when it is non-empty and not one of the duplicated/placeholder values (`put your unique phrase here`); the `get_site_option()`/`wp_generate_password()` path is a **fallback** only for undefined, empty, duplicated or placeholder constants.
The design choice (read the constants directly) therefore **stands, for a different reason: determinism and fail-closed behaviour.** `wp_salt()` silently substitutes a **DB-stored** value when a constant is missing or duplicated — i.e. exactly the case this design wants to **reject**, not paper over. Reading the eight constants ourselves makes a weak/absent configuration a 503 (§ below) instead of a key that quietly depends on the database. That is a robustness property, not a claim that `wp_salt()` is always DB-backed.
**Precedent in-repo:** `CredentialVault::key()` already derives from `AUTH_KEY|SECURE_AUTH_KEY|AUTH_SALT` with a domain label and a `CPMS_SECRET_KEY` env override (`Infrastructure/Sms/CredentialVault.php:67-79`) — but when the constants are **undefined it falls back to `hash('sha256', …)` over empty strings** (`:74-78`), i.e. it degrades to a fixed, guessable key instead of failing. This design must **not** copy that: undefined/placeholder ⇒ 503.
**Key-strength rule — checkable, not a length test (M3).** Revision 2 required only "≥ 32 bytes", which a 32-character dictionary word passes. Predictable constants would let a **DB-only** attacker build an HMAC dictionary over the ~10^9 national-ID space offline, which defeats the entire purpose of D21. Each of the eight constants must therefore satisfy **all** of these, checked at the point of use:
1. **Length:** ≥ 32 bytes (the value `define`d in wp-config, as a raw string).
2. **Distinct characters:** ≥ 12 distinct bytes (`count(count_chars($v, 1))`).
3. **Denylist:** not equal (case-insensitively, after trim) to any known sample value — at minimum `put your unique phrase here`, `changeme`, `secret`, `password`, `test`, and the literal `wp-config-sample.php` placeholder — and not equal to **any other** of the eight constants (§ `wp_salt` treats duplicates as invalid; so do we).
4. **Override path:** if `CPMS_ARREQ_KEY` is defined it must independently satisfy 1–3 and is then used as the whole IKM.
**Failure ⇒ `503 CLINIC_SERVICE_UNAVAILABLE`** (`RuntimeSchemaGate.php:34-36`) on **every** key-using path (creation and match), with no write and no option created.
**LIMITATION, stated because it matters:** a rule **cannot prove randomness**. Rules 1–3 reject the common accidents (sample file copied verbatim, a constant duplicated, a human-chosen word) but a 40-character phrase from a novel passes all three and is guessable. **The real fix is installer-generated keys** — WordPress's own `wp_generate_password(64, true, true)` is the model (`wp_salt()` fallback above) — and an owner-level decision to require generated secrets is **OQ-18**. Until then this design's DB-only protection is conditional on the site's wp-config actually carrying generated salts, and that condition is recorded as a residual in §13, not hidden.
**No key is ever auto-created in an option** (§10 T-8 asserts this; revision 1's option fallback is deleted).
**Threat model, stated without a broader claim (F2a):** this does **not** make national IDs safe against an attacker who obtains **wp-config.php together with the database**: with both, the attacker derives `k_nid` and can confirm/guess any ID offline (the Iranian 10-digit space is ~10^9 candidates, trivially enumerable). The hash gives **no** protection in that scenario. What it does give: a **database-only** leak (dump, backup copy, mis-scoped read) no longer yields national IDs, which is the dominant incident shape for a self-hosted WP plugin.
**Version contract:** `national_id_key_version TINYINT UNSIGNED` is a **label**, not a rebinding mechanism. The current generation is computed from a **non-secret change detector**: `sha256(k_nid)` truncated to 16 hex, stored in one option (`cpms_arreq_key_fp`); a mismatch means the salt set changed. On rotation, new rows are written with the incremented version and **old rows become unmatchable** — acceptable because the pending TTL is 72 h and the terminal retention is 14 d. Staff see `identity_unverifiable` (§5.9) and act by phone; no silent "no-match" is produced for a rotated-key row. **Exactly what is retained:** the 64-hex HMAC, the version byte, the 16-hex fingerprint of the **key** (not of any ID), and nothing else derived from the ID. **Reconciling with T-8:** the fingerprint option is written only on a **successful** key use, i.e. only after the constants have already validated; when the key is missing the code returns 503 before any write, so no option appears. The fingerprint is a public hash of high-entropy wp-config constants — it is **not** key material and cannot be used to recover a key.

**(b) Audit and decision text (DECIDED (mgr)).** Staff decisions and audit records use a **closed reason-code vocabulary** — the `decision_reason_code` ENUM of §3 #18 — and **no free text anywhere** in audit or decision records. The revision-1 "no 10-digit run" rule is **deleted**, not patched: it missed space-separated and Persian-digit IDs.
**What staff-entered text still exists:** none in the decision or audit path. The only stored human text is **requester-entered** `given_name`/`family_name` (validated: C0/C1 stripped, bidi rejected, visible content required, 120 code points — `PatientService.php:657-679`) plus the mobile; the reception *match* input (a national ID) is **never stored or echoed** (§5.9).
**Escaping:** those two names are untrusted input rendered in the reception portal; every print site must HTML-escape with the platform escaper (`esc_html`) and the build must use escaped, attribute-quoted output (OWASP XSS Prevention Cheat Sheet, "Output Encoding / HTML Contexts", cheatsheetseries.owasp.org). LIMITATION: escaping is a build-phase obligation; validation alone does not prevent a future `html`-context mistake.

### 5.8 D10/D11/D12/D13/D14 — capacity, tenancy, staff side
**Capacity (C8):** option (a) **no hold (recommended)** — creation checks slot existence/belonging/open as a courtesy and writes nothing to `cpms_schedule_slots`; staff re-checks at decision. Option (b) short hold — **rejected**: any phone number could park capacity (3 OTPs/day/mobile, V6), and `holds.expire` would be the only thing protecting a clinic. No automatic penalty for expired/rejected requests.
**Tenancy (C9):** clinic/location/clinician/slot all derived server-side from the persisted challenge and slot rows (the OBSERVED rule at `OtpController.php:228-250`: the **persisted slot** owns the clinic and any client-supplied `clinic_id` is ignored); a client-supplied `clinic_id` is ignored. Staff side: `App::scope()->clinicId` (`ScopeContext.php:15-45`), every query carries `clinic_id = %d`, another clinic's row → `404 CLINIC_NOT_FOUND` (mirroring `PatientService.php:264-273`). No `DEFAULT 1` (contrast `cpms_idempotency_keys.clinic_id`, `0001:133`, removed by `0016`). UTC `DATETIME(3)`. `InnoDB`. `$db->table()`.
**Staff (C10):** present capability presets are `ALL_CAPS` (`Auth/RolesAndCapabilities.php:107-121`), `SECRETARY_CAPS` (`:123-137`), `DOCTOR_CAPS` (`:139-153`), `ACCOUNTANT_CAPS` (`:160-166`), `MANAGER_CAPS` (`:174-180`). Proposal: `cpms_appt_request_read` + `cpms_appt_request_decide` appended to `ALL_CAPS` and to `SECRETARY_CAPS` (both), and **read only** to `MANAGER_CAPS` — OBSERVED: the manager preset today holds `CONFIG`, `SMS_CONFIG`, `PATIENT_READ`, `REPORT_READ`, `SEARCH` and **no** `APPT_*` (`:174-180`). Doctor, accountant and patient get neither. Explicit per-membership deny wins over a preset (the reception boundary does exactly this: `Rest/ReceptionPortalController.php:413-424`). Routes: `GET …/appointment-requests`, `GET …/{id}`, `POST …/{id}/decide`, `POST …/{id}/match` (§5.9), mounted under the existing reception prefix **`/clinic/v1/staff/portal/reception/`** (OBSERVED: `ReceptionPortalController.php:122-125`, e.g. `/staff/portal/reception/upcoming`) — one additional path segment inside that namespace, so the existing route-level authorization applies unchanged. Atomic decision (C10, D14): one guarded `UPDATE … SET status,decision_reason_code,decided_by_wp_user_id,decided_at,open_marker=NULL WHERE id=%d AND clinic_id=%d AND status='pending' AND expires_at > %s`; **affected rows = 1** required, else `409 CLINIC_INVALID_TRANSITION` (`error-codes.md:35`). Two concurrent decides: exactly one matches (INFERENCE from the predicate; T-5 measures it).
**No access (C4):** approval writes nothing else; the boundary at `approved` is where this design stops.

### 5.9 D22 — reception comparison mechanics (C11) — **new for F4**
1. Reception has the in-person document and the request open in the decision screen. They type the 10-digit ID into the **match** field.
2. `POST …/requests/{id}/match {national_id}` — capability `cpms_appt_request_decide`, nonce required (`RestBase.php:30-38`), rate-limited per `wp_user_id` (`hash('sha256','arreq-match:'.uid)`, existing table), audited `APPOINTMENT_REQUEST_MATCHED` with **reason code only** (`identity_match` / `identity_mismatch`).
3. The server normalises (D4), derives `k_nid`, and compares **only against that request row's own stored hash** with `hash_equals()` (php.net `hash_equals`). It returns `{match: true|false}` and **nothing else**: the ID is never stored, never logged, never echoed, and never compared against `cpms_patients`.
4. If matched, staff continue with the **existing** authorized patient search (`GET /clinic/v1/patients/search`, `PatientController.php:96-106`, capability-gated at `:100`) to find or create the clinical record — **no new patient-read path exists on the public side**, and this design adds none.
5. **When the request mobile differs from the record's mobile:** both are shown (request mobile full in the single decision view, masked in lists via `MobileValidator::mask()`, `:62-69` — `0912***5678`; record mobile comes from the existing authorized search), plus a fixed warning label. No automated decision is taken, and nothing is written back to the patient record.
**Why this is not an oracle:** the match action answers a question about a row the staff member is already authorized to read, using a hash it already contains; it cannot be used to test whether an ID exists anywhere else. A staff insider guessing IDs is bounded by the per-user rate limit, the audit trail, and the `decide` capability (**OQ-R3** asks the reviewer to confirm this reasoning).
**Flaw found while writing this:** a "match" that returned anything richer (last 4 digits, or a patient id) would become a patient-enumeration tool for staff; the boolean-only rule is therefore normative here.

### 5.10 D15 — error vocabulary
Existing codes only: `CLINIC_VALIDATION_FAILED`, `CLINIC_RATE_LIMITED`, `CLINIC_NOT_FOUND`, `CLINIC_INVALID_TRANSITION`, `CLINIC_SERVICE_UNAVAILABLE`, `CLINIC_SCOPE_REQUIRED`, `CLINIC_PERMISSION_DENIED`, `CLINIC_INVALID_NONCE`, `CLINIC_OTP_INVALID`/`CLINIC_OTP_EXPIRED` (`docs/api/error-codes.md:20-42,150`). A dedicated "already decided" code would need that file (**OQ-9**).

### 5.11 D17/D18 — backup and privacy (C12)
Backup inclusion is automatic (V11) — no list to edit. Consequences: every backup now holds plaintext mobiles/names plus NID HMACs and is itself personal data; the existing at-rest protection (sodium secretstream, `Infrastructure/Backup/BackupEncryptionEnvelope.php:150-165`) becomes the control that matters. **Restore, corrected (M6b).** Revision 2 said restore "drops every `cpms_` table named in the manifest" and cited `BackupService.php:355`. Reading it again: that pre-loop tests the **literal** prefix `cpms_` against the manifest names (`:352`), but manifest names are the **physical** names from `SHOW TABLES`, which carry the WordPress `$wpdb->prefix` (`BackupSqlDumper.php:113-122`; `$wpdb->prefix` is `wp_` on a default install). So on a default install that pre-loop matches **nothing** and `$dropped` stays 0. The drops that actually happen come from the **dump itself**, which begins each table with `DROP TABLE IF EXISTS` + `CREATE TABLE` (`BackupSqlDumper.php:56-65`) and is executed statement-by-statement at `BackupService.php:358-361`. Two consequences that matter here: (i) a pre-table backup simply does not mention the new table, so **the current table survives** — the safe direction; (ii) a backup **taken after** the table exists contains `DROP`+`CREATE` for it **and for `cpms_rate_limits`**, which is exactly the M1 replay path §5.6 handles with option C. **UNKNOWN:** manifest handling of a table absent from the backup (validation only checks entry shape, `BackupManifest.php:56-58`). **Privacy:** the plugin registers **no** `wp_privacy_*` hook today (no match in `src/`); the core eraser/exporter contract is **email-keyed** (developer.wordpress.org/reference/hooks/wp_privacy_personal_data_erasers/ — "accepts an email address and a page number"), while this table is mobile-keyed, so a core privacy request cannot reach these rows. The 14-day hard delete is therefore the primary control; a staff-driven eraser entry is **OQ-10**.

### 5.12 D19 — the public route
`POST /clinic/v1/booking/request`, `permPublic()` like `/booking/quote` (`BookingController.php:64-68`), proof/mobile/ID/slot in the **body**; no `GET` variant (a URL would put the proof in logs). Must be added to the REST scope skip list: OBSERVED the list at `Rest/RestClinicContext.php:327-341` already skips the whole `/clinic/v1/booking/` prefix (`:331`), so a route under `booking/` inherits the skip — to be **confirmed at build time** rather than assumed, since the match/decide routes take the opposite (staff) path.

### 5.13 D23 — schema gate and "table absent" (C12) — **corrected for F5**
Revision 1 claimed the existing `RuntimeSchemaGate` would answer 503 for every route while this migration is pending. **That was wrong** (V19): on a supported engine with readable evidence the gate returns `false` and does not block; it only blocks when engine evidence is *positively unsupported* **and** migrations are pending.
**Replacement, three layers:**
1. The route carries its **own** memoized `information_schema.tables` probe before its first query (the #340 pattern, `BookingService.php:3075`), and returns the neutral `503 CLINIC_SERVICE_UNAVAILABLE` for **every** caller (so absence is not an oracle about who may act).
2. **If the probe itself errors** (driver failure, `wpdb->last_error` non-empty, or a null result where a count was expected): also `503`, never a 500 and never "assume present". Rationale: `CpmsDb` is soft-mode by default, so a failed query returns `null`/`[]` rather than throwing (`Infrastructure/Db/CpmsDb.php:15-25`, `:64-69`) — a missing probe result must be read as *unavailable*, not as *absent-and-fine*.
3. Activation/migration path stays fail-closed through the existing migration runner (strict mode around `up()`, `Migrations/MigrationRunner.php:91-110`).
**Engine assumption:** `InnoDB`, on **MySQL 8** (`ci.yml:236-237`, `image: mysql:8`) and **MariaDB 11.4.13** (`ci.yml:247-248`), both started as services of the `Integration (WP 7.1 + MySQL 8)` job (`ci.yml:224-225`) — i.e. the engine set the repo already verifies; OBSERVED that the runtime gate accepts `11.4.13` only (`DatabaseCompatibilityGate.php:32`), so MariaDB 11.5+ is out of the allowed set and this design assumes nothing about it. DDL inside the runner's transaction is **not** atomic — MySQL DDL causes an implicit commit (dev.mysql.com, "Statements That Cause an Implicit Commit"); LIMITATION: I did not measure a half-applied `CREATE TABLE`.
**No route may rely on "pending migration" for safety** — stated because that is the exact mistake this revision corrects.

### 5.14 D24 — name policy for the request path (C5) — **new for M2**
**The hole M2 found (OBSERVED).** `PatientService::cleanName()` (`:655-679`) strips C0/C1 controls, rejects bidi controls and requires visible content — but it **accepts ASCII and Persian digits**. A requester who types their national ID into the given-name field would therefore have it stored and later **displayed in plaintext** on the reception screen, which breaks C5 even though the dedicated `national_id_hash` column is safe.
**Policy (DECIDED direction, owner policy "digits only in numeric fields, never names"):** the request path applies a **stricter** rule than `cleanName()`:
1. After the existing `cleanName()` checks (C0/C1 stripped, bidi rejected, visible content, ≤ 120 code points), **reject any character with the Unicode general category `Nd`** — all decimal digits in every script (ASCII `0-9`, Persian `۰-۹` `U+06F0-06F9`, Arabic-Indic `٠-٩` `U+0660-0669`, Devanagari, etc.). Implemented as a `\p{Nd}` match against the **already-folded** string, so digit-folding cannot be used to smuggle them past.
2. Violation ⇒ **`400 CLINIC_VALIDATION_FAILED` with the same uniform message as every other validation failure** (§5.3 step 3) — no hint about *which* field or *why* beyond the shared validation envelope.
3. This is enforced **before storage**, in the request validation path, and **not** by reusing `cleanName()` alone (it is too permissive). The stricter rule is a build-phase helper (the reason `MobileValidator::fold_digits` is private) rather than a change to `PatientService` (**OQ-19**: whether to tighten `cleanName()` globally too — out of scope here).
**Every free-text field of the request row, and what protects it:**

| Field | Content | Digits allowed? | Control |
|---|---|---|---|
| `given_name`, `family_name` | requester-entered | **no** | rule above; stored only after it passes |
| `mobile` | numeric by nature | yes | normalised `09xxxxxxxxx` (`MobileValidator::normalize`) |
| `national_id_hash` | hex digest, not free text | n/a | never the ID itself |
| `decision_reason_code` | closed ENUM | no | D20 |
| everything else (`status`, ids, timestamps) | system | n/a | not free text |

**Every output path of the request row, and what protects it:**

| Output path | What is rendered | Control |
|---|---|---|
| reception **list** | names, masked mobile, slot | output-escaped (`esc_html`); NID never present (only its hash, and the hash is not rendered) |
| reception **detail** | names, full mobile, slot, decision buttons | output-escaped; **no NID field at all** — the stored hash is not displayed |
| **match** response | `{match: bool}` only | §5.9; the typed ID is never echoed |
| **audit** records | reason codes, masked mobile, ids | `AuditLogger` masking (`:26`, `:249`) + D20 closed vocabulary; T-13 |
| **application logs** | operational lines only | no NID, no name; match input never logged (§5.9) |
| **SMS / notification text** | — | **this request path sends no SMS** (INFERENCE from the design: the route in §5.12 has no SMS step; the only SMS in the flow is the OTP code, `OtpService`) — so there is no template to leak into. If a future build adds one, it must carry no NID and must be added to §10 |

**The existing patient search is NOT a NID channel (M2, normative).** `GET /clinic/v1/patients/search` takes `q` **in the query string** (`PatientController.php:96-106`) — a URL, therefore a log/proxy/referer surface. This design requires that **the national ID is never placed in `q` or any other URL parameter**; reception comparison happens **only** through the POST-body match action (§5.9), which returns a boolean. Using search as a NID oracle is explicitly forbidden by this document; a build that did so would also contradict C5's "never in URL" clause.

### 5.15 D25 — transaction boundary and concurrency (C10/C13) — **new for M8**
Revision 2 said the marker upsert and read-back happen "inside the same transaction" without defining the boundary. Defined now:

**Boundary.** One transaction per request (`CpmsDb::transactional()`, `Infrastructure/Db/CpmsDb.php`) opened **after** steps 1–5 (all read-only), wrapping steps 6–8 and the audit write. **Named lock first, then the marker row** — one order used by every caller, so the two lock types cannot be taken in opposite orders (INFERENCE of deadlock-freedom, not measured; T-17/T-18):

| Phase | Statements | Lock behaviour |
|---|---|---|
| 6·L | `GET_LOCK('cpms_arreq:<clinic>', 2)` | **named lock acquired first**; released at commit/rollback |
| 6a | `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1` on the marker row | **exclusive row lock** on that marker (or the gap, for a fresh insert) under the default `REPEATABLE READ` |
| 6b | `SELECT hits … WHERE window_key = ? AND window_id = ?` | same transaction, same row lock held |
| 6c | decide first-use vs replay from `hits` | no statement |
| 7 | duplicate probe: `SELECT 1 FROM cpms_appointment_requests WHERE clinic_id=? AND mobile=? AND open_marker=1` | plain read inside the transaction |
| 8a | cap count for the clinic | already serialised by the lock taken at 6·L |
| 8b | `INSERT` the request row | |
| 8c | audit row | same transaction (the existing "audit atomicity" pattern, `OtpService.php:449-455`) |
| — | `COMMIT` / `ROLLBACK` | releases the row lock, then the named lock |

**Behaviour on each outcome:**
- **accepted** — commit; the marker increment, the request row and the audit land together.
- **duplicate / cap refused** — commit; the marker increment is **intentionally durable** (the proof was spent even though no request was created, §5.3). The audit row commits with it.
- **replay** — the increment is real and committed (`hits` becomes ≥ 2); no request row is touched. There is no rollback, because rolling back would erase the evidence and re-open the F1 hole for a concurrent second call.
- **invalid proof / validation failure** — no transaction opened.
- **SQL error anywhere** — `transactional()` rolls back: the marker increment, the request row and the audit are all discarded together, and the caller gets the neutral `503` (`CpmsDb` is soft-mode by default, so the design requires an explicit error check exactly as `HoldsExpireHandler::guardedWrite` does, `:102-118`).

**Concurrency, stated with its failure mode:** two concurrent calls with the **same** proof serialise on the marker row; the first sees `hits = 1` and proceeds, the second blocks until commit and then sees `hits = 2` ⇒ **replay ⇒ `202`, no second request**. This is the intended, tested outcome (T-17). Two concurrent calls with **distinct** proofs for the same clinic race the cap: the named lock serialises them, so the second re-counts after the first commits and is refused with `429` if the cap is now reached — **a legitimate requester can be refused at the exact boundary**; that is fail-safe (no over-cap insert) and is accepted, tested in T-18. Without the named lock the two could both pass the count and overshoot by one; the lock is what makes over-cap impossible, and it is a `GET_LOCK` (already used in this codebase for ticks, `App.php:2657`), not a table lock.
**Deadlock risk:** the only two locks are the clinic named lock and the marker row, always acquired in that order (6·L then 6a) and held to commit, so the order is consistent for same-proof calls, distinct-proof calls and any mix. INFERENCE, not measured; T-17/T-18 are the tests.

---

## 6. Conditions C1–C14, re-derived from the final text (revision 3)

**Rule applied:** MET only where (a) the text establishes it **and** (b) a §10 case would go red if it were false. Where either is missing the status is UNKNOWN or NOT MET — the reviewer's round-3 reading is adopted wherever I could not produce both.

| C | Status | Backing text | Test that fails if false |
|---|---|---|---|
| C1 one table | **MET** | §3 (one `CREATE TABLE`, zero `ALTER`); replay marker in the pre-existing `cpms_rate_limits` (§5.6); M1's epoch is an **option**, not a table | **T-24** — after `up()` the schema diff shows exactly one new `cpms_` table and `down()` removes it; modelling the marker as its own table or as a column on the request row goes red |
| C2 minimal data, TTL, cleanup, caps | **UNKNOWN** | §5.2 defines them; TTL/retention are still **owner-open** (OQ-2), and the M1 epoch adds a second timing knob | T-3, T-4 — but they cannot fail against an **undecided** TTL, hence UNKNOWN not MET |
| C3 uniform response | **MET at person level only** | §5.3/§5.4: the three person-level cases are never computed; the accounting table is the contract | T-1, T-22, T-23. Cap `429` and write-count differences are **declared**, not equalised |
| C4 no auto-merge/link/access | **MET** | §5.8 D13, §11 | T-2 |
| C5 NID never in URL/log/audit/error; storage; matching; normalisation; names | **NOT MET in revision 2 → MET now, conditional** | §5.5, §5.7, §5.9, **§5.14 (new)** closes the name-field hole; search-in-`URL` forbidden normatively | T-7, T-13, T-20. **Conditional** on the §5.7 key rule actually being implemented (LIMITATION: a rule cannot prove randomness) |
| C6 historical-NULL path closed | **NOT MET on this branch** | Depends entirely on unmerged **#340** (`fb38793`); on `main` (`aec7306`) it is open | none here — belongs to #340's suite; **must be re-verified after #340 merges** |
| C7 possession proof | **MET by design; one build dependency unapproved** | §5.6 (marker + I1 restated + **M1 option C** epoch + T-17) | T-9, T-10, T-11, T-12, T-16, **T-17, T-19**. T-19 cannot pass today because the epoch write is a **build item awaiting OQ-15** — hence "by design", not "delivered" |
| C8 capacity | **MET** | §5.8 (no hold, no penalty) | T-3 |
| C9 tenancy | **MET** | §5.8, §5.12 | T-6 |
| C10 staff side | **UNKNOWN** | §5.8, §5.9, **§5.15** now defines the transaction; but the marker/audit/transition interaction under concurrency has never been executed | T-5, T-17, T-18 — until they run, UNKNOWN |
| C11 edge policies | **UNKNOWN** | §7; the <18 mechanic is **challenged** (OQ-11) and the foreigner/child matrix has no case yet | no dedicated case ⇒ UNKNOWN, not MET |
| C12 operations | **NOT MET in revision 2 → MET now** | §5.11 (**M6b corrected**), §5.13, §5.6 (restore/uninstall epoch) | T-15, T-19. Two UNKNOWNs still carried in §13 |
| C13 abuse model | **NOT MET in revision 2 → MET now** | §9 incl. T4 replay residual, T9/T10 key rows, **T-6**, and the **M1 restore row** added below | T-13, T-19 |
| C14 test plan | **NOT MET in revision 2 (T-12 could not establish its claim; T-4's mutation could not fail) → MET now** | §10: 23 cases, each with a mutation that fails on a fixture sized for it | self-checking: T-4 (seeded over limit), T-16 (default retention), T-23 (header rule) |

**Honest summary (counts must add up, so they are spelled out):** **9 MET** — C1, C3 (person level only), C4, C5, C8, C9, C12, C13, C14. **1 MET by design with an unapproved build dependency** — C7. **3 UNKNOWN** — C2 (TTL undecided), C10, C11. **1 NOT MET on this branch** — C6 (owned by #340). No condition is claimed MET without a test named beside it. The reviewer's round-3 reading (C5, C7, C12, C13, C14 not met) is **adopted for C12/C13/C14 and C5** — those were genuinely unestablished and are fixed in this revision — while C7 remains conditional on OQ-15, which I am not entitled to decide.

## 7. Edge policies (C11)

| Policy | Verdict |
|---|---|
| Foreigners and children via reception only, with in-person documents | **CONFIRM** — the table holds only a 10-digit Iranian ID (D4), so a passport is not representable and no field is added. |
| Request mobile differs from an existing record → private review, no hint | **CONFIRM** — mechanically guaranteed by §5.4 (no patient read); §5.9 step 5 says what staff sees. |
| No age limit; guardian info for <18 collected separately | **CONFIRM policy, CHALLENGE mechanics** — no birth date is stored, so nothing can be enforced; `is_minor_declared` is a self-declared hint only (**OQ-11**: flag, no flag, or collect a birth date). |
| Shared mobile / browser id / existing appointment is NOT ownership | **CONFIRM** — the proof attests only that a code was entered for that mobile; §5.3(c) states that another holder of the mobile reaches the duplicate branch and that this is accepted. |

---

## 8. Operational plan (C12)

**Migration (build phase, not now):** `src/Migrations/2026_11_17_0032_appointment_requests.php` — number/date **not reserved** (**OQ-1**). `up()`: one `CREATE TABLE IF NOT EXISTS` guarded by an `information_schema.tables` check (pattern `2026_09_09_0019_…php:37-48`); `down()`: `DROP TABLE IF EXISTS` with a comment that it destroys queued intents. Idempotent by construction. **Rollback:** the runner wraps `up()` in a transaction (`MigrationRunner.php:91-110`), but MySQL DDL implicitly commits, so that transaction does not make a failed `CREATE` atomic (INFERENCE from the documented engine behaviour). Practical rollback = `down()`, or restore the pre-migration backup (D17).
**Table-absent installs:** §5.13 (own probe; probe error ⇒ 503).
**Uninstall:** covered automatically in both branches (`uninstall.php:8-20`, V12). Privacy note: without the wipe option the table survives with plaintext mobiles/names and NID hashes (**OQ-12**). **Replay note:** a wipe drops `cpms_rate_limits` too, which is the M1 path — §5.6 option C requires `uninstall.php` to write the `cpms_arreq_proof_epoch_min` marker before dropping, and the reader to treat an absent option as `0`.
**Backup/restore:** §5.11.
**Health endpoint:** unchanged — an "open request count" there would be a public disclosure (`HealthController.php:18-33`).

---

## 9. Threat model (C13)

| # | Attacker | Goal | Vector | Mitigation | Residual |
|---|---|---|---|---|---|
| T1 | anonymous | learn whether an ID/patient exists | compare responses | §5.4 (no patient read) + §5.3 table | pre-existing oracle on the patient path (V14), out of scope |
| T2 | many SIMs | fill the table | mass creation | 3 OTPs/day/mobile + 60 s cooldown (V6); `u_arreq_open`; 500/clinic; per-IP bucket; 14-day deletion | a funded attacker still fills 500/clinic per TTL window — bounded, not prevented |
| T3 | anonymous | harvest mobiles | response/error probing | #340 verify envelope is uniform for any valid code (V17); this route adds no signal; no SMS from this route | OTP-path timing is pre-existing and out of scope |
| T4 | holder of a leaked proof | replay it | re-POST | §5.6 marker + I1, including after row deletion, expiry, and slot removal | the proof is a bearer token for 600 s: whoever holds it first can create **one** request (with **their** name, and the requester's mobile/ID). Mitigated, not eliminated — minting requires an SMS to that mobile, so the holder is normally its owner |
| T5 | requester | XSS into staff screens | `given_name`/`family_name` | validators (§5.7b) + mandatory output escaping (OWASP) | a future unescaped print site is not prevented by validation |
| T6 | anonymous | timing oracle | measure response | no patient read; fixed statement shape | **UNKNOWN**: duplicate/cap branches differ by one write (§5.3). Never measured. Disclosed. Note the oracle would only distinguish *states of the caller's own mobile*, never a third party's |
| T7 | attacker on shared NAT | exhaust the bucket | per-IP exhaustion | threshold from settings; existing OTP IP bucket is already shared (`OtpService.php:203-212`) | a NAT-ed clinic can throttle itself; this design adds one more shared bucket (**OQ-13**) |
| T8 | staff insider | enumerate IDs | repeated match | boolean-only, per-user rate limit, audit per attempt, `decide` capability | patience is a governance problem |
| **T9** | **DB-only leak (dump/backup)** | **recover national IDs** | offline guessing of ~10^9 | keyed HMAC whose key is **outside** the DB (§5.7) | recovery from the DB alone is **not possible** only if the constants are genuinely high-entropy; a guessable constant defeats it (§5.7 M3 LIMITATION, OQ-18). Conditional, not absolute |
| **T10** | **wp-config + DB leak** | **recover national IDs** | derive `k_nid`, enumerate | **nothing** — full recovery (§5.7, stated plainly) | inherent to any derived-key scheme on one host; the mobile and name are plaintext there anyway |
| **T11** | **operator restores a snapshot / wipes + reinstalls** | **reuse a still-valid proof** | restore rolls `cpms_rate_limits` back, erasing consumed markers | §5.6 **M1 option C**: monotonic `cpms_arreq_proof_epoch_min` written by the restore and uninstall paths; proofs with `iat ≤ epoch` rejected | the epoch write is a **build item awaiting OQ-15**; without it, a proof minted inside the snapshot's 600 s and replayed within its TTL would succeed once — bounded by `u_arreq_open`, the per-clinic cap and reception review, and it cannot create a patient, link or appointment |

---

## 10. Test plan for the BUILD phase (descriptions only; mutation in the same row)

| # | Test | Mutation that must make it fail |
|---|---|---|
| T-1 | person-level uniformity: NID known / unknown / mobile-matches-record all produce the same status, code, body and headers, and **zero** patient-table queries (query-count assertion); headers compared by the T-23 rule | add any `cpms_patients` read → red; compare the correlation id verbatim → flaky |
| T-2 | no side effect: after a creation, `cpms_patients`, `cpms_patient_user_links`, `wp_users`, `cpms_appointments` row counts unchanged | insert a patient → red |
| T-3 | caps and order: 2nd open request for the same (clinic, mobile) → 202 with **no second row**; 501st open row → 429; **duplicate while at capacity → 202** (step 7 precedes step 8); a malformed national ID does **not** spend the proof (retry with the same token succeeds) | drop `u_arreq_open` → red; move the cap check before the duplicate check → red; move the proof consumption before validation → the typo-retry case goes red |
| T-4 | cleanup: pending past TTL → `expired`; terminal older than 14 d deleted; second run changes nothing. The batch test **seeds more terminal rows than the batch limit** (e.g. limit+25) so the limit is actually reached | remove the `LIMIT`/guard → the bounded-batch count assertion goes red (it cannot fail on a fixture smaller than the limit, which is why the fixture is specified) |
| T-5 | atomic decision: two concurrent decides (real MySQL, `pcntl_fork`) — exactly one approved, one 409 | read-then-write → red |
| T-6 | tenant isolation: clinic-B staff get 404 for clinic-A's request; a client-supplied `clinic_id` is ignored | drop `clinic_id` from WHERE → red |
| T-7 | digit normalisation: Persian/ASCII/Arabic-Indic forms of one ID hash **identically**; BOM/ZWJ/zero-width/inner space rejected; all-same-digit rejected | strip invisibles instead of rejecting → red; hash the raw string → red |
| T-8 | key missing ⇒ 503 on **all** key-using paths (creation **and** match) and **no** option is created; a **partially stubbed** wp-config (one constant empty) also ⇒ 503, never a silent weak key | add an auto-created key option → red; fall back to hashing empty strings (the `CredentialVault` behaviour) → red |
| T-9 | **duplicate-proof collision**: same mobile, two different proofs inside 600 s → two markers, one request row; replaying the **first** proof after the row closes creates **no** row | key the marker on the mobile instead of `jti` → red |
| T-10 | **replay after deletion**: delete the request row (expiry, 14-day, and FK/slot paths) then replay the proof → still no row; marker still present | key the marker on the request row / use `ON DELETE CASCADE` for the marker → red |
| T-11 | **proof replay across clinic/purpose/mobile**: a token minted for clinic A / purpose `appointment_request` / mobile M is rejected for clinic B, another purpose, or another mobile | drop `cl`/`p`/`mh` from the payload check → red |
| T-12 | **no deletion while the proof is live:** with a proof expiring at *T*, run `cleanup.rate_limits` at *T*−300 s (and at *T*−1 s) → the marker is still present, because the predicate `window_id * window_sec < now − olderThan` cannot match while `window_id = exp > now` | set `window_id = 1` (or `window_sec` such that `window_id * window_sec < exp`) instead of the proof expiry → the pre-expiry cleanup deletes the marker and the assertion goes red. Note: with the correct `window_id`, `max(1, …)` in `cleanup()` makes pre-expiry deletion **impossible**, so the only reachable mutation is a wrong window key |
| T-13 | **audit vocabulary**: every audit/decision row carries only reason codes; no audit field (any nesting) contains a 10-digit ID, a Persian-digit ID, the hash, or free text | pass a free-text reason / an unmasked `national_id` / a key named `National_ID` (V13) → red |
| T-14 | **observable-by-observable new vs duplicate**: status, code, body equal; headers compared **by name excluding** `X-CPMS-Correlation-Id`/`Set-Cookie`/`Date` (rule in T-23); **write counts differ by exactly one INSERT**, marker upsert in **both** branches | make the duplicate branch skip the marker write → red; compare all headers verbatim → flaky (red on repeat) |
| T-15 | column/table absent: own probe ⇒ 503 for every caller; **probe error ⇒ 503** (not 500) | let the query run → red; treat error as present → red |
| **T-16** | **margin, both numbers (M5):** proof expires at *T*. With the **job** call (`2*86400`): marker still present at *T*+172 700 s, gone at *T*+172 900 s. With the **method default** (`86400`): still present at *T*+85 900 s, gone at *T*+86 300 s. Revision 2's T-12 checked only *T*+600 and could not see a margin at all | lower either threshold below the proof TTL, or assume the 48 h figure while calling the method default -> the corresponding assertion goes red |
| **T-17** | **same proof, two concurrent calls (M8):** fork two requests with one token; exactly **one** 202-with-insert, one 202-replay, **one** request row, `hits = 2`, two audits | drop the transaction around upsert+read-back → two inserts (red); roll back the replay increment → `hits = 1` (red) |
| **T-18** | **distinct proofs at the cap boundary (M8):** cap−1 rows, two concurrent distinct-proof calls → at most one insert, the other `429`, **never** cap+1 rows. Run on **MySQL 8 and MariaDB 11.4.13** | remove the clinic named lock → both count the same pre-state and insert → cap+1 (red) |
| **T-19** | **restore replay (M1):** mint a proof, consume it, snapshot the DB, restore that snapshot, replay the same token → `400`/rejected, **no** new row (epoch option raised by the restore path). Same for wipe + reinstall | drop the epoch write from the restore path → the replay is accepted (red) |
| **T-20** | **name policy (M2):** `given_name` = a valid national ID → `400` uniform; Persian/Arabic-Indic digit forms → `400`; a name with a digit **disguised** by digit-folding → `400`; a clean name → accepted; and **no** request row path renders a NID (list, detail, audit, logs) | remove the `\p{Nd}` check → red; fold digits before the check → red |
| **T-21** | **key strength (M3):** 32-char dictionary word / duplicate constants / sample placeholder / < 12 distinct chars ⇒ `503` on creation **and** match; a rule-passing value ⇒ 200 | replace the rule with a length-only check → the dictionary-word case passes (red) |
| **T-22** | **accounting table (§5.3):** for each of the eight outcomes, assert status, body, code, marker-write count, request-row write count and audit count **exactly** as the table states | make replay write nothing (revision 2 behaviour) → the `hits = 2` assertion goes red; make the duplicate run the cap count → the statement-count assertion goes red |
| **T-23** | **correlation header comparison rule (M8):** T-1/T-14 compare header **names** and are required to **exclude** `X-CPMS-Correlation-Id` (random per request, `RestBase.php:295`) and any `Set-Cookie`/`Date`, because those vary by design; every other header must match exactly | include the correlation id in the comparison → the "identical headers" assertion becomes flaky (red on repeat runs) |
| **T-24** | **one table (C1):** the migration diff after `up()` contains exactly **one** new `cpms_` table; `down()` leaves zero; no second table is created for the proof marker or the epoch | create a `cpms_arreq_proofs` table, or add `proof_marker` to the request row → red |

**Exact CI invocations (OBSERVED in `.github/workflows/ci.yml`):** `php -d memory_limit=512M vendor/bin/phpunit --testsuite Unit` (`ci.yml:172`); `php -d memory_limit=1G vendor/bin/phpunit --no-configuration --bootstrap tests/integration-bootstrap.php tests/Integration` (`ci.yml:809-812`). Job names: `Unit Tests (PHP ${{ matrix.php }})` (`ci.yml:93`, matrix `:108`), `Integration (WP 7.1 + MySQL 8)` (`ci.yml:225`); T-17/T-18 are required on **both** services those jobs start — MySQL 8 (`ci.yml:236-237`) and MariaDB 11.4.13 (`ci.yml:247-248`). **This PR runs neither** (docs-only).

---

## 11. What this design deliberately does NOT do

- **No** code, table, migration, test, workflow, capability, route, template or JS. Nothing numbered or reserved.
- **No** patient, user, session, link or appointment from any path (C4/D13); the historical-NULL path stays owned by #340 (C6).
- **No** staff-link tool and **no** conversion flow: the boundary is `approved`.
- **No** change to `NationalIdValidator`, `PatientService`, `OtpService` policy lists, `error-codes.md`, `api-contract.md`, the permission matrix or any ADR.
- **No** fix for `/patient/me` returning the unmasked ID (V8) or for the pre-existing NID-existence message (V14) — reported only.
- **No** new error codes (D15); **no** `GET` variant of the route.
- **No** second table and **no** new column for the proof marker or the epoch (C1); the only new persisted artefact outside the request table is **one integer option** (§5.6).
- **No** change to `PatientService::cleanName()`: the stricter digit ban is **scoped to this request path** (OQ-19 asks whether to widen it).
- **No** claim of constant-time behaviour, of equal write counts, or of protection against a combined wp-config + DB leak.

---

## 12. Phase 2 — adversarial re-read of *this* delta (revision 3)

Read hunk by hunk against revisions 2 → 3, looking only for the four blocker classes.

1. **Can the design still leak a replay, session, account, link or national-ID existence?** *Replay:* revision 2's marker is intact and the **restore path is now closed** by the epoch (M1) — but that closure is a **build item**, so the leak is *reduced to a declared residual*, not eliminated (T11). *Session/account/link:* none is ever created (§5.8 D13, §11). *NID existence:* the public path still never reads a patient table (§5.4); the new §5.14 name rule **removes** a channel that revision 2 had (a NID typed as a name was stored in clear), and the new normative ban on putting a NID in the search `q` removes a second. **Net: no new leak; one existing leak (M2) closed.**
2. **Did any protection from revision 2 get lost?** The M1 epoch and M2/M3 rules are additive. Two revision-2 statements were **deleted as false** (replay performs "no write"; duplicate "performs the cap count") — deleting a false claim loses no protection. I1 was **narrowed**, which is a loss of *claim*, not of mechanism: the mechanism (marker outlives the proof) still holds under the code as it is; what did not hold was presenting it as a schema invariant. Recorded in OQ-16 so a future change to the threshold is a decision, not an accident.
3. **Any FALSE or overstated claim left?** Checked the ones this revision touched: `wp_salt()` (corrected, M4), "restore drops every manifest table" (corrected, M6b), "RESTRICT blocks only until expiry" (corrected, M6a), "none for this table" under T9 (downgraded to conditional, M3), "no SMS on this path" (downgraded OBSERVED → INFERENCE). Remaining absolutes in the document were re-checked one by one against §10: each has a named case or is explicitly scoped ("person level", "by design", "under the default job").
4. **Any test that cannot fail?** Fixed two: T-12's original mutation was unreachable because `max(1, …)` makes pre-expiry deletion impossible with the correct window key — it now mutates the **window key**, which is reachable. T-4's mutation could not fire on a fixture smaller than the batch limit, so the fixture is now specified as larger. T-16 previously asserted only *T*+600 for a 48 h claim; it now asserts both thresholds' edges.
5. **Repo-rule compliance.** Two files only; ledger edited in place with `main` as a byte-prefix and 0 deletions (verified programmatically); no schema, code, test or workflow file touched; nothing numbered or reserved.

**Verdict by the stated blocker definition:** (a) no replay/session/link/NID-existence leak introduced — one closed; (b) no revision-2 protection removed; (c) the false claims found are corrected, and the ones that remain absolute are either scoped or test-backed; (d) no known test that cannot fail remains; (e) scope respected. **Not claimed:** that the residual in T11 is acceptable — that is **OQ-15**, an owner decision.

## 13. Limitations

- **LIMITATION:** nothing executed (no PHP/MySQL/WordPress/browser). All timing, engine and arithmetic statements are INFERENCE; I1 is derived from reading the cleanup predicate, not from running it.
- **LIMITATION:** #340 is unmerged (`fb38793`); its mergeable state was `CONFLICTING` when read in revision 1 and was **not re-checked** in this revision. Facts are pinned at that head and may drift.
- **UNKNOWN:** MySQL/MariaDB `UNIQUE`-with-NULL handling *measured* on the CI versions (documented, not measured); `GET_LOCK` contention behaviour; a half-applied DDL; restore handling of a table absent from a manifest.
- **UNKNOWN:** whether the `window_id * window_sec` comparison is computed without overflow for every possible `exp` (INT UNSIGNED reaches ~4.29e9; a 2026 timestamp is ~1.8e9 — INFERENCE that it is safe, not measured).
- **LIMITATION:** no independent review of this revision yet. Two security reviews and an owner build approval remain outstanding.
- **LIMITATION (M3):** the key-strength rule rejects common accidents but **cannot prove randomness**; a human-chosen passphrase of sufficient length and variety passes. The real fix is installer-generated keys (**OQ-18**).
- **LIMITATION (M8):** the transaction and lock behaviour is **designed, not executed**. Deadlock-freedom is INFERENCE from a globally consistent lock order (marker row → clinic named lock); T-17/T-18 are the tests.
- **UNKNOWN (M1):** whether `restoreApply`'s existing transaction can carry the epoch write — the dump executes DDL inside it (`BackupService.php:348-361`) and MySQL DDL implicitly commits, so the epoch write must be re-checked as landing after the last statement. Build-phase verification, not assumed here.
- **LIMITATION:** the `hits`-read-back for single use (§5.6) is a **design**; the concurrency claim (upsert + read under one row lock) is INFERENCE, and T-9/T-14 are the tests that would settle it. If they fail on real MySQL, the fallback is a `SELECT … FOR UPDATE` on the marker row before the upsert — same table, same invariant, one extra statement.
- **UNKNOWN:** whether WordPress core ever defines the eight secret constants to identical default values across installs (the classic "put your unique phrase here" literal is what the deny-list targets; the full list is not enumerated here).

---

## 14. Open questions

### (a) Owner decisions
- **OQ-1** migration identity (`2026_11_17_0032_…` not reserved). **OQ-2** TTL/retention (72 h + 14 d proposed). **OQ-3** key-rotation policy: pending rows become unmatchable on a salt change — confirm acceptable, and whether to purge them early. **OQ-4** masking strictness: no digits at all (proposed) vs last-4 (`NationalIdValidator::mask()`). **OQ-5** the pre-existing mixed-digit NID drift (V1/V2). **OQ-6** mobile in clear (D5). **OQ-7** cap `429` disclosing clinic load (recommend keep). **OQ-8** who may decide (secretary only proposed; manager read-only). **OQ-9** new error code for "already decided". **OQ-10** staff-driven privacy erasure given the email-keyed core contract. **OQ-11** minor flag. **OQ-12** uninstall behaviour for this table. **OQ-13** the pre-existing shared per-IP bucket. **OQ-14 (new)** cap refusal consumes the proof (requester must re-request a code) — confirm, or exempt cap refusals from consumption (which re-opens a probe-until-cap loop, §5.3).

### (b) Reviewer checks
- **OQ-R1** #340 stability: re-verify at the merge commit that verify is still uniform and confirm still returns the uniform `CLINIC_VALIDATION_FAILED`/400 with zero links (pinned at `fb38793`).
- **OQ-R2** confirm that a marker row in the **pre-existing** `cpms_rate_limits` does not violate C1's "one new table".
- **OQ-R3** confirm the match action cannot be used as a patient oracle (§5.9).
- **OQ-R4** `NO ACTION`/`RESTRICT` on `fk_arreq_slot` means a slot with pending requests cannot be deleted for up to 72 h — acceptable, or should the design detach requests (not cascade) on slot removal?
- **OQ-R5** backup/restore when the table is absent from a manifest (UNKNOWN here).
- **OQ-R6** `/patient/me` unmasked ID (V8) and the pre-existing NID-existence message (V14) stay out of scope.
- **OQ-R7** the key-fingerprint option (`cpms_arreq_key_fp`, 16 hex of `sha256(k_nid)`) is a change detector, not key material — confirm that reading.
- **OQ-R8** requiring **all eight** wp-config secret constants is stricter than the in-repo precedent (three); confirm that a partially-stubbed install should fail closed rather than accept a narrower key.
- **OQ-15 (new, M1, blocks C7):** authorise the restore/uninstall epoch write — it touches `BackupService.php` and `uninstall.php`, i.e. **outside this document's scope**. Without approval, option A applies and the residual in T11 must be accepted in writing.
- **OQ-16 (new, M5):** confirm `cleanup.rate_limits` keeps its `2*86400` default; the 48 h post-expiry margin is a property of that value, not of the schema.
- **OQ-17 (new, M6a):** `NO ACTION` on `fk_arreq_slot` blocks slot deletion until terminal rows are hard-deleted, which can stall. Acceptable, or should `slot_id` become nullable so terminal rows detach?
- **OQ-18 (new, M3):** require installer-generated wp-config salts (or `CPMS_ARREQ_KEY`) as a documented install precondition? Without it the DB-only protection is conditional.
- **OQ-19 (new, M2):** tighten `PatientService::cleanName()` globally (reject digits in names for patients too), or keep the stricter rule scoped to this request path?
- **OQ-R9** consuming the proof at step 5 (after cheap validation, before duplicate/cap) means a **cap-refused** and a **duplicate** requester each spend one OTP; confirm that trade against the alternative (probe-until-cap).
