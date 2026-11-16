# Appointment Request — Design Document (DESIGN ONLY)

> # ⛔ DESIGN ONLY — NO CODE, NO SCHEMA, NO MIGRATION, NO TABLE CREATED
>
> This is a **proposal for review**, produced under a hard scope limit of **two files**
> (`docs/architecture/appointment-request-design.md`, new; `docs/agent-log.md`, append-only).
> It contains **no PHP, no JS, no migration/schema file, no test and no workflow change**.
> It **does not create, reserve or number a migration**. Building it needs a **separate
> owner approval after two independent security reviews**.
> **Owner acceptance of any implementation: NOT GIVEN.**

| | |
|---|---|
| **Base** | `origin/main` = `aec7306f6f2d7088658f7b3aaf57cb407e97b970` (fetched 2026-11-16; `main` had not moved). Every `file:line` below was read at this commit unless the row says otherwise. |
| **Depends on** | PR **#340** (OTP Safety Slice 1, Option A) — **OPEN, draft, `mergeable=CONFLICTING`, head `ff7cec823212f872ecf0dd3e2b24965adfc862f3`, NOT merged**. Facts taken from #340 are marked **(#340, unmerged)** and were read via `git fetch` + `git show`/`git diff` only (read-only). |
| **Evidence labels** | **OBSERVED** = read in the cited file at the stated commit, not executed. **INFERENCE** = reasoning from observations, not proven by execution. **LIMITATION** = what this work could not establish. **UNKNOWN** = not proven. **PROPOSAL** = a design position, not an owner decision. **OQ-n** = open question (§14). |
| **Local PHP** | LIMITATION: no `php` binary in this environment (PHP-WASM only). No PHP, WordPress, MySQL or browser code was executed. CI does not exercise this document. |

---

## 0. Decisions at a glance (NON-NORMATIVE; the sections win)

| # | Decision | C |
|---|---|---|
| D1 | **One** new table `cpms_appointment_requests`; nothing else is created. | C1 |
| D2 | Minimal columns (no MRN, no birth date, no address, no free-form notes from the requester). | C2 |
| D3 | National ID stored **only** as a keyed HMAC-SHA256 (`national_id_hash`) + version; **no plaintext, no reversible form**. | C5 |
| D4 | Input normalization: Persian/Arabic-Indic digits → ASCII **before** validation; then the existing checksum; invisible/BOM/zero-width **rejected, not stripped**; any non-digit **rejected** (never silently removed). | C5 |
| D5 | Mobile stored plaintext (`CHAR(11)`) so reception can call back; NID never plaintext. Contrast documented (D3 pros/cons). | C2/C5 |
| D6 | The **public creation endpoint never reads `cpms_patients`** — there is no existence oracle to equalize, rather than an equalized one. | C3 |
| D7 | Response is a single fixed `202` envelope for every well-formed request, whether or not the NID exists, the mobile matches, or a request already exists. | C3 |
| D8 | Possession proof = **stateless HMAC token** issued by `/otp/verify`, bound to (mobile, clinic, purpose, expiry, jti); single-use enforced by a **UNIQUE index on the request table** (no second table). | C7 |
| D9 | `/otp/verify` gains **one field** in its already-zero-access envelope; it stays uniform for every valid code, so it is not an enumeration leak. | C7 |
| D10 | **No capacity hold** (option a). Free capacity is checked at creation as a courtesy and re-checked by staff at decision time; nothing is reserved or penalised. | C8 |
| D11 | Tenancy from the **trusted** side only: public creation takes `clinic_id` from the signed proof; staff side from `ScopeContext` fail-closed. No fixed id 1, no client-supplied clinic authority. | C9 |
| D12 | Staff: **new capabilities** `cpms_appt_request_read` / `cpms_appt_request_decide`; default grant to secretary (read+decide) and manager (read), none to doctor/accountant; explicit-deny wins. | C10 |
| D13 | Approval is a **state change only** — it creates no WP user, no session, no patient, no link, no appointment. Conversion is a separate, out-of-scope staff flow. | C4 |
| D14 | Atomic decision via guarded `UPDATE … WHERE status='pending' AND expires_at>now` + affected-rows = 1; the loser gets `409 CLINIC_INVALID_TRANSITION`. | C10 |
| D15 | Only **existing** error codes are used (no new vocabulary), because new codes need an edit to `docs/api/error-codes.md`, which is outside this PR's two-file scope. | scope |
| D16 | Retention: pending rows `expires_at = created_at + 72h`; terminal rows hard-deleted 14 days after their terminal timestamp; cleanup by a new recurring job **plus** an opportunistic bounded sweep in the creation path. | C2 |
| D17 | Table is **automatically** included in backup (`SHOW TABLES LIKE`); it is **not** in any hand-maintained list. Privacy consequence stated. | C12 |
| D18 | The design adds **no** national-ID logging, no NID-keyed rate-limit bucket, no NID in any URL/query. | C5 |

---

## 1. Owner-decided inputs (restated, not re-decided)

From the task brief (owner decisions, final): login is mobile + SMS OTP; a booking identifies the patient by Iranian national ID (10 digits); #340 makes OTP verify possession-only (no WP session, no account, no patient link); the public booking widget cannot create appointments; hold/confirm need `requirePatient`; manager decision **option B** = one new table for requests from people who proved possession of a mobile but have no linked patient; staff (reception) reviews and decides; reception-only booking exists meanwhile; appointments need `patient_id`.

**OBSERVED:** `cpms_appointments.patient_id BIGINT UNSIGNED NOT NULL` (`2026_09_05_0001_initial_schema.php:226`) with `fk_appt_patient` (`:252`) — so an unlinked requester cannot be represented in `cpms_appointments`; this is why a separate persistence object is needed.

**OBSERVED (main, pre-#340):** the historical-NULL confirm path still resolves a subject and can create a Patient (`BookingService.php:897-911`, `createMinimalPatient()` at `:3181-3190`, link insert `:3191+`). **OBSERVED (#340, unmerged):** that branch is replaced by one uniform `CLINIC_VALIDATION_FAILED`/`400` for a holder with zero active links (`BookingService.php` at `ff7cec8`:902-910). C6 therefore depends on #340 landing as written; if #340 changes before merge, C6 must be re-checked → **OQ-R1**.

---

## 2. Verification of the facts given to me

| # | Claim in the brief | Result and evidence |
|---|---|---|
| V1 | `NationalIdValidator.php:12-35` strips non-ASCII digits | **CONFIRMED, with a consequence.** `isValid()` runs `preg_replace('/\D/','',$input)` (`:14`), rejects length≠10 (`:15`), rejects all-same-digit (`:18`), then the mod-11 checksum (`:22-35`). Because `\D` has no `/u`, every Persian/Arabic-Indic digit **byte** is removed, so an all-Persian-digit input collapses to `''` and is rejected at `:15`; a **mixed** input can pass and is then stored verbatim (V2). *(INFERENCE: not executed — no PHP binary.)* `mask()` is `'***'.substr($clean,-4)` (`:41-46`). |
| V2 | `PatientService.php:682-689` `cleanNationalId` returns the original | **CONFIRMED.** `:684` `$v = trim($v);` → `:685` `isValid()` → `:689` `return $v;` — the **trimmed original**, not the ASCII-stripped form. (Line range in the brief said 682-689; the method spans **682-690**.) |
| V3 | `cpms_patients.national_id` nullable `VARCHAR(10)`, unique per clinic | **CONFIRMED.** `:61` `national_id VARCHAR(10) NULL`; `:83` `UNIQUE KEY u_pat_nid (clinic_id, national_id)`. |
| V4 | "mobile is a non-unique attribute" | **CONTRADICTED at the clinical table; true only at the identity table.** `:82` `UNIQUE KEY u_pat_mobile (clinic_id, mobile)` — mobile **is** unique per clinic in `cpms_patients`. It is non-unique in `cpms_patient_identities.normalized_mobile` (`2026_09_09_0019_...php:33-43` adds it explicitly **without** UNIQUE; the file says so at `:13`). **This design assumes neither**: it stores its own `mobile` and does not rely on any uniqueness of `cpms_patients.mobile`. |
| V5 | appointments need `patient_id` | **CONFIRMED** (see §1). |
| V6 | OTP limits: 3/day per mobile, 10/hour, 60 s cooldown, verify 20/IP/hour | **CONFIRMED.** Defaults `Settings.php:32-40` (`otp.ttl_sec`=120, `otp.max_attempts`=5, `otp.cooldown_sec`=60, `otp.daily_max`=3, `otp.lockout_sec`=900, `otp.hourly_max`=10, `otp.ip_hourly_max`=10). Day/hour quota peeked at `OtpService.php:882-899`, charged at `:301-302`. Verify IP bucket is **hard-coded** 20/3600 at `:404` (not a setting). |
| V7 | Five public REST callbacks | **CONFIRMED as exactly five** `permPublic()` routes: `/availability` (`BookingController.php:43,47`), `/booking/quote` (`:64,68`), `/health` (`HealthController.php:18,22`), `/otp/request` (`OtpController.php:33,37`), `/otp/verify` (`:53,57`). `permPublic()` returns `true` unconditionally (`RestBase.php:142-145`). |
| V8 | `/patient/me` returns the full national ID | **CONFIRMED.** Route `PatientController.php:64-76`; `me()` `:149-154` → `PatientService::me()` `:128-130` → `publicView()` `:779-795` whose `:787` returns `(string)$row['national_id']` **unmasked**. Contrast: the staff **search** projection masks it (`searchView()` `:831-844`, masked at `:839` → `NationalIdValidator::mask()`). **Note only — out of scope** (§11). |
| V9 | Tenancy primitives | **OBSERVED:** `ScopeContext` is a request-scoped holder with a fail-closed resolver (`Application/Scope/ScopeContext.php:16-45`); `RuntimeSchemaGate` blocks every `/clinic/v1/*` route with `503 CLINIC_SERVICE_UNAVAILABLE` when the engine is unsupported **and** migrations are pending (`Bootstrap/RuntimeSchemaGate.php:32-36,67-90`; `Bootstrap/App.php:622-639`). |
| V10 | Cleanup/job machinery | **OBSERVED:** `App::RECURRING_JOBS` (`Bootstrap/App.php:2616-2633`), handler registration (`:2475-2490`), scope taxonomy `JobScopeRegistry` (`Application/Jobs/JobScopeRegistry.php:44-70`), OS-cron preference over WP-Cron (`Bootstrap/App.php:413`). A drift test requires every `RECURRING_JOBS` type to be registered (`tests/Integration/Phase2JobScopeRedFoundationTest.php:576`). |
| V11 | Backup inclusion | **OBSERVED:** the dumper enumerates tables with `SHOW TABLES LIKE '{prefix}cpms_%'` (`Infrastructure/Backup/BackupSqlDumper.php:110-129`, called from `Application/Backup/BackupService.php:100`). **No hand-maintained list exists**, therefore no list needs editing (D17). |
| V12 | Uninstall behaviour | **OBSERVED:** `uninstall.php:8-20` — nothing is dropped unless the option `cpms_wipe_on_uninstall` is truthy; when it is, **all** `{prefix}cpms_%` tables are dropped via `SHOW TABLES LIKE`. The new table is therefore covered by both branches automatically. |
| V13 | Existing audit masking | **OBSERVED:** `AuditLogger::MASK_KEYS` includes `national_id`, `mobile`, `phone`, `email`, `card_number` (`:26`) and masks any **exactly-named** key whose value is a string of length ≥ 4 to `'***'.substr($v,-4)` (`:249-252`); recursion covers nested arrays (`:253-256`); `FORBIDDEN_KEYS` is matched case-insensitively (`:246`), while the `MASK_KEYS` test at `:249` compares `$key` verbatim → a key named `National_ID` would **not** be masked → a key named `National_ID` would **not** be masked. **Design rule: the service must mask before calling the logger and must use the exact key names.** |
| V14 | Existing NID-existence leak on the *patient* path | **OBSERVED (pre-existing, out of scope):** `assert_unique_national_id()` throws `CLINIC_VALIDATION_FAILED` with the message «این کد ملی متعلق به بیمار دیگری است» (`Application/Patients/PatientService.php:850-857`), reached from `updateMe()` `:147-152` and create `:429,442`. That is an existence oracle for an authenticated staff/patient caller. This design **does not add another**; it must not be reused as a matching primitive (§5 D3). |
| V15 | A second place mobiles are stored in clear | **OBSERVED:** `RateLimiter` writes its key verbatim into `cpms_rate_limits.window_key` (`Infrastructure/Security/RateLimiter.php:30-40`; table `0001:723-729`), and OTP passes the **raw** mobile as `'otp-day:'.$mobile` / `'otp-hour:'.$mobile` (`OtpService.php:301-302,883,890`), while the issuance guard deliberately hashes it (`:227`). **Design rule: every new bucket key this design uses is a SHA-256 of the mobile, never the mobile.** |
| V16 | `cpms_otp_tokens` shape | **OBSERVED:** `0001:116-128` — `mobile VARCHAR(32)`, `purpose ENUM('login','verify_mobile')`, `code_hash`, `expires_at`, `attempts`, `locked_until`, `consumed_at`; `clinic_id` added nullable with FK and **no backfill** by `2026_09_20_0021_...php:16-40`. The `purpose` ENUM is a **closed set** — a new purpose is a schema change (**OQ-R2**). |
| V17 | #340 verify envelope | **OBSERVED (#340, unmerged, `ff7cec8`):** `verify()` returns `{user_id:0, patient_links:[], is_new_user:false, session_issued:false}` for **every** valid code (`src/Application/Auth/OtpService.php:530-580`; docblock `:370-380`); no patient lookup, no `LOGIN_SUCCESS`. `clinic_id` comes from the challenge row (`:447`). |
| V18 | #340 column-absent behaviour | **OBSERVED (#340, unmerged):** a memoized `information_schema.columns` probe (`src/Application/Booking/BookingService.php` at `ff7cec8`:3075) precedes capacity claim and throws `CLINIC_SERVICE_UNAVAILABLE`/503 (`:3089-3099`). This is the pattern §8's inner column-absent gate mirrors. |

**LIMITATION:** row V1's mixed-digit consequence and every timing statement in this document are **INFERENCE** — no PHP/MySQL was executed here.

---

## 3. The single table (C1) — data dictionary

`{wp_prefix}cpms_appointment_requests`. Engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci` (matching `0001:*`). Name built with `$db->table()` so the WordPress prefix is honoured (`Infrastructure/Db/CpmsDb.php:78-84`).

| # | Column | Type | Null | Key / index | Purpose | Retention |
|---|---|---|---|---|---|---|
| 1 | `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | NO | PK | surrogate id | as row |
| 2 | `clinic_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_clinic` → `cpms_clinics(id)`; `KEY idx_arreq_clinic_status (clinic_id,status,created_at)` | tenancy (D9/C9) | as row |
| 3 | `location_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_location` → `cpms_locations(id)` | operational branch, resolved **server-side** from the persisted slot (pattern: `0013_operational_location_ids.php:10,19-33` makes `location_id` NOT NULL on operational tables) | as row |
| 4 | `clinician_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_clinician` → `cpms_clinicians(id)` | requested doctor | as row |
| 5 | `slot_id` | `BIGINT UNSIGNED` | NO | FK `fk_arreq_slot` → `cpms_schedule_slots(id)` **ON DELETE CASCADE** | requested slot | as row |
| 6 | `slot_date` | `DATE` | NO | part of `idx_arreq_slot` | snapshot, like `cpms_appointments.slot_date` (`0001:228`) | as row |
| 7 | `slot_time` | `TIME` | NO | part of `idx_arreq_slot` | snapshot (`0001:229`) | as row |
| 8 | `mobile` | `CHAR(11)` | NO | `KEY idx_arreq_mobile (clinic_id,mobile)` | normalised `09xxxxxxxxx` (`MobileValidator::normalize()`, `Domain/Validators/MobileValidator.php:22-52`) — needed for the callback (D5) | hard-deleted with the row |
| 9 | `open_marker` | `TINYINT(1)` | **YES** | **`UNIQUE u_arreq_open (clinic_id,mobile,open_marker)`** | `1` while `status='pending'`, `NULL` otherwise ⇒ **at most one open request per (clinic, mobile)**, enforced by the DB with no counter (MySQL permits many NULLs in a unique index) | n/a |
| 10 | `national_id_hash` | `CHAR(64)` | NO | `KEY idx_arreq_nid (clinic_id,national_id_hash)` | HMAC-SHA256 hex of the **normalised ASCII** 10-digit NID (D3) | hard-deleted with the row |
| 11 | `national_id_hash_version` | `TINYINT UNSIGNED` | NO, default 1 | — | key rotation bookkeeping (D3) | as row |
| 12 | `given_name` | `VARCHAR(120)` | YES | — | optional, so reception can greet/call; validated exactly like the patient name path (`Application/Patients/PatientService.php:657-679`: C0/C1 stripped and length-checked `:657-659`, bidi controls rejected `:664`, visible-content required `:672`, `mb_substr(…,0,120)` `:679`) | hard-deleted with the row |
| 13 | `family_name` | `VARCHAR(120)` | YES | — | as #12 | as #12 |
| 14 | `is_minor_declared` | `TINYINT(1)` | NO, default 0 | — | self-declared "I am under 18" flag; **no birth date is collected** (§7) | as row |
| 15 | `proof_fingerprint` | `CHAR(64)` | NO | **`UNIQUE u_arreq_proof (proof_fingerprint)`** | single-use/replay enforcement of the possession proof (D8) | as row |
| 16 | `status` | `ENUM('pending','approved','rejected','expired','cancelled')` | NO, default `pending` | part of `idx_arreq_clinic_status` | **closed** vocabulary (C10) | see D16 |
| 17 | `decided_by_wp_user_id` | `BIGINT UNSIGNED` | YES | — | who decided | as row |
| 18 | `decided_at` | `DATETIME(3)` | YES | `KEY idx_arreq_terminal (status,decided_at)` | when (UTC) | D16 |
| 19 | `decision_reason` | `VARCHAR(255)` | YES | — | staff free text; validated (D4/§7) — **no national ID** | as row |
| 20 | `created_at` | `DATETIME(3)` | NO | part of `idx_arreq_clinic_status` | UTC (`CpmsDb::nowUtcSql()`) | — |
| 21 | `expires_at` | `DATETIME(3)` | NO | `KEY idx_arreq_retention (status,expires_at)` | `created_at + 72h` (D16) | — |

**Invariants (must be test-enforced, §10 T-3/T-5):** (i) `open_marker = 1` ⇔ `status='pending'`; (ii) `status='pending'` ⇒ `decided_at IS NULL`; (iii) `status IN ('approved','rejected','cancelled')` ⇒ `decided_at IS NOT NULL AND decided_by_wp_user_id IS NOT NULL`; (iv) `national_id_hash = HMAC(key_version, 'nid', ascii_nid)` — recomputable from a supplied NID, never reversible.

**Rejected columns (deliberate):** `appointment_id` / `converted_at` (D13 — approval creates nothing; a future conversion flow adds the link **as a column of this same table**, which keeps C1 intact), `mrn`, `birth_date`, `address`, `passport_no`, `free_text`, `ip`, `user_agent`, `proof` (raw token), `national_id` plaintext (D3).

**C1 check:** one `CREATE TABLE`, zero `ALTER TABLE` on existing tables, zero new options-tables, zero new columns elsewhere. Proof state lives in the token + a unique index (D8); counters live in the **existing** `cpms_rate_limits` (V15). → **C1 met.**

---

## 4. Request lifecycle (closed state machine)

```
                    (public) proof valid, slot bookable-or-not
                                     │
                                     ▼
                            ┌─────────────────┐
                            │     pending     │◄── created_at + 72h window
                            └────┬───┬───┬────┘
        staff approve (C10)      │   │   │      staff reject (C10)
                    ┌────────────┘   │   └────────────┐
                    ▼                │                ▼
             ┌───────────┐           │        ┌───────────┐
             │ approved  │           │        │ rejected  │
             └───────────┘           │        └───────────┘
                    staff cancel     │  expiry job / lazy read-time transition
                    ┌────────────────┘                │
                    ▼                                 ▼
             ┌───────────┐                     ┌───────────┐
             │ cancelled │                     │  expired  │
             └───────────┘                     └───────────┘
                    ▲                                 ▲
                    └──── all three terminal states ──┘──► deleted 14 d after the terminal timestamp (D16)
```

| From | To | Trigger | Actor | Guard / side effect |
|---|---|---|---|---|
| — | `pending` | `POST /booking/request` | requester (possession proof) | proof HMAC valid + unexpired; 1 INSERT; `open_marker=1`; audit `APPOINTMENT_REQUEST_CREATED` |
| `pending` | `approved` | `POST /staff/…/requests/{id}/decide` | staff with `cpms_appt_request_decide` | guarded UPDATE, affected rows = 1; audit `APPOINTMENT_REQUEST_DECIDED`; **no other write** (D13) |
| `pending` | `rejected` | same | same | same; `decision_reason` required |
| `pending` | `cancelled` | same route, `cancel` | staff | same; reason required |
| `pending` | `expired` | job `cleanup.appointment_requests` **or** any read that sees `expires_at<=now` | system | sets `status='expired'`, `open_marker=NULL`; **no counter, no penalty** |
| `approved/rejected/cancelled/expired` | *none* | — | — | terminal; any further decide attempt → `409 CLINIC_INVALID_TRANSITION` |

Terminal states never transition (no "re-open"); a new intent needs a new proof and a new row.

---

## 5. Decisions, alternatives, pros/cons, risk

### D1 — storage object (C1)
**Decision:** exactly one new table, `cpms_appointment_requests` (§3). **Alternatives rejected:** (a) reuse `cpms_slot_holds` — it has `slot_id`, `holder_wp_user_id`, `holder_mobile`, `token`, `status` (`0001:204-218`) but **no NID, no clinic-scoped staff decision vocabulary, no location**, and it is capacity-bearing: writing requests there would mix an intent queue with capacity accounting; (b) a `cpms_appointments` row with `patient_id` NULL — the column is `NOT NULL` (V5) and loosening it rewrites the booking invariant; (c) a WP option/post-type — no tenancy, no indexes, no retention story. **Pros:** atomic caps via unique indexes, one retention job, one FK graph. **Cons/risk:** the table is a new personal-data store (§12 backup). **Owner question if a second table becomes necessary → STOP.**

### D2 — minimal data, short retention, hard caps (C2)
**Decision:** the 21 columns of §3 only; TTL 72 h pending; terminal rows hard-deleted after 14 days (D16). Caps: **1 open row per (clinic, mobile)** by DB unique index (§3 #9) and **N=500 open rows per clinic** by a counted guard inside the insert transaction, serialised with `GET_LOCK('cpms_arreq:'.clinic_id, 2)` (the lock primitive the repo already uses for ticks, `Bootstrap/App.php:2657`) and released in `finally`; plus a per-IP bucket on the **existing** `cpms_rate_limits` keyed `hash('sha256', 'arreq-ip:'.ip)`.
**Alternatives:** per-mobile cap = N (needs a counter → racy, or a second table → violates C1); no per-clinic cap (unbounded spam within the OTP quota).
**Pros:** the per-mobile cap is exact and costs zero extra queries; the per-clinic cap bounds a single clinic's blast radius. **Cons/risk:** `GET_LOCK` is not transactional — a crash mid-transaction holds it until the connection closes (bounded by PHP request lifetime, INFERENCE); a lock timeout must fail **closed** with the neutral 503, which the requester cannot distinguish from an outage (accepted). **TTL options and why 72 h:** (a) 24 h — risks expiring Friday-evening requests before a Saturday-shift review; (b) 48 h — same risk at one day less; (c) **72 h (recommended)** — covers a weekend plus one working day; (d) 7 d — increases the personal-data window for no operational gain. Retention window options: **7 d** (tighter, may surprise a clinic that reviews monthly), **14 d (recommended)**, 30 d (against the "short retention" requirement).
**What happens if cleanup does not run:** (i) nothing is *shown* past `expires_at` — staff lists filter `status='pending' AND expires_at > UTC_NOW`, and any read that meets an expired pending row performs the same guarded transition lazily (idempotent, affected-rows=1 pattern from `Application/Jobs/HoldsExpireHandler.php:42-52`); (ii) terminal rows accumulate, so the **creation path deletes up to 50 expired/terminal rows for that clinic** per successful request (`DELETE … WHERE clinic_id=%d AND (…expires_at < now - 14d …) LIMIT 50`) — a bounded, opportunistic sweep; (iii) `bin/cpms jobs tick` is the supported OS-cron path when WP-Cron is starved (`Bootstrap/App.php:413`), and WP-Cron only fires on page loads (developer.wordpress.org/plugins/cron/). **Residual (stated):** on a site with **no traffic at all** and no OS cron, deletion waits for the next request; the rows stay invisible and unreadable to the public endpoint, and the 1-open-per-mobile cap plus the per-clinic cap still bound *new* growth — but terminal-row deletion is then unbounded in time. This is a **real limitation**, not a solved problem.

### D3 — national-ID storage form (C5)
**Options weighed:**

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| (a) plaintext `VARCHAR(10)` | trivially searchable, human-readable for staff | a DB read, a backup copy (§8/D17) or a log accident exposes 10-digit identifiers; search prefixes (V14/`PatientRepository.php:218-226` uses `LIKE` on `national_id`) become an enumeration tool; it is the single worst artefact to keep for a *transient* intent | **rejected** |
| (b) keyed hash — `HMAC-SHA256(server_secret, domain‖ascii_nid)` | equality search still works (reception types the ID, we hash it, we compare); no reversible artefact; a leaked table alone does not yield IDs (10-digit space = 10^10, so an *unkeyed* hash would be brute-forceable, a keyed one is not); `hash_equals()` is available for constant-time compare (php.net `hash_hmac`, `hash_equals`) | staff cannot read digits back (a *feature* here); key management is now load-bearing; rotation must be planned | **recommended** |
| (c) encrypted at rest (`sodium_crypto_secretbox`, php.net) | reversible for staff display | a reversible store is exactly what C4/C5 want to avoid for a queue row; nonce+ciphertext per row; key handling is the same problem as (b) plus a decrypt path in the staff UI; equality search becomes impossible without a companion hash column — i.e. (b) **plus** ciphertext | **rejected for this table** (`CredentialVault` already uses AES-256-GCM for SMS credentials, `Infrastructure/Sms/CredentialVault.php:17-30` — precedent exists, but that data must be replayable; an appointment intent must not) |

**Missing or rotated secret:** the key is **not** read from a code constant. Resolution order: `CPMS_ARREQ_KEY` constant if defined (per-installation, outside the repo) → else a random 32-byte option `cpms_arreq_key` created with `autoload=no` (exact pattern of the OTP pepper, `Application/Auth/OtpService.php:1041-1050`) → else **fail closed**: creation and staff matching both return the neutral `503 CLINIC_SERVICE_UNAVAILABLE` (`Bootstrap/RuntimeSchemaGate.php:34-36`), never an unkeyed SHA-256 fallback. **Do not** copy `AuditLogger::pepper()`'s in-code fallback `'cpms-dev-pepper-change-me'` (`Infrastructure/Audit/AuditLogger.php:291-296`) — that would make every hash globally precomputable. **Rotation:** new writes use the new key and record `national_id_hash_version`; matching tries versions 1..current (bounded, current key first). Rows written under a **dropped** key become unmatchable — the documented consequence is that they must be purged or flagged before the old key is discarded (**OQ-3**). **Risk:** key loss = the NID on existing rows becomes unverifiable (still not leaked). The mobile, name and slot remain readable, so reception can still act by phone.
**Reception matching:** the staff UI has a "match" action: reception types the 10-digit ID → server normalises (D4) → **one** indexed equality query on `(clinic_id, national_id_hash)` → boolean `matches | no_match` plus, on match, the request row. Every match attempt is audited (`APPOINTMENT_REQUEST_MATCH`, NID masked as `••••••••••`, never the hash either) and rate-limited (`hash('sha256','arreq-match:'.wp_user_id)`), because otherwise a staff account becomes a patient enumerator.
**Masking in the staff UI:** the national ID is **never rendered**, in any digit form, in any list or detail view; the placeholder is a fixed 10-dot string. *Optional deletion of this strictness* (show last 4 like `NationalIdValidator::mask()` `:41-46`) is presented as **OQ-4** with its cost: 10^4 of the identifier becomes visible to staff.

### D4 — input normalisation for the national ID (C5)
**Decision, in this order:** (1) reject if the raw value is not valid UTF-8; (2) reject if it contains any of: BOM `U+FEFF`, zero-width `U+200B`/`U+200C`/`U+200D`/`U+2060`, bidi controls (reuse `Domain/Validators/VisibleText::has_bidi_control()`, `:77+`), C0/C1 controls, or any Unicode space other than none — i.e. the value must be exactly the digits, no trimming, no stripping; (3) fold Persian `U+06F0-06F9` and Arabic-Indic `U+0660-0669` to ASCII by the **existing closed map** (`MobileValidator::fold_digits()`, `:77-102` — the same map already blessed for mobiles); (4) now require exactly 10 ASCII digits; (5) apply `NationalIdValidator::isValid()` (`:12-36`) — which includes the all-same-digit rejection (`:18`). **Never** rely on `isValid()`'s internal `preg_replace('/\D/','')` (`:14`) for normalisation: that strip is why a mixed Persian/ASCII string can pass validation today and be stored verbatim (V1/V2).
**Why reject rather than strip:** it matches the codebase's existing owner-decided stance for names — bidi controls are **rejected, "never silently removed"** (`Application/Patients/PatientService.php:661-669`, comment `:661-663`). **Risk:** a user pasting a NID with an invisible character gets a validation error rather than silent success; that is the intended trade (C5 requires "reject, not strip").
**Consequence to state in the build (do not fix here):** changing *this* path does not change `PatientService::cleanNationalId()`; the pre-existing mixed-digit storage drift (V1/V2) stays and is listed for the owner (**OQ-5**).

### D5 — the mobile is stored in clear (C2/C5 contrast)
**Decision:** `mobile CHAR(11)` normalised via `MobileValidator::normalize()` (`:22-52`). **Why:** reception must call the person back; a hash cannot be dialled, and an SMS-only contact path would need a template, a provider budget and an owner decision that does not exist. **Precedent:** mobiles are already stored in clear in `cpms_otp_tokens.mobile` (`0001:118`) and in `cpms_rate_limits.window_key` (V15). **Pros:** usable, cheap. **Cons/risk:** the table is personal data even after the NID is hashed; TTL is the only control. **Alternative:** store `mobile_hash` only and have reception contact via an SMS initiated by staff (needs a template + OQ). **OQ-6.**

### D6/D7 — identical response, and *how* (C3)
**Decision:** the public creation endpoint **does not read `cpms_patients`, `cpms_patient_user_links` or `cpms_patient_identities` at all.** It therefore has no existence-dependent branch to equalise: the three cases named in C3 are not distinguished because they are not *computed*. This is strictly stronger than a timing-equalised lookup, and it is cheap.
**Response (always, for a well-formed request):** `HTTP 202`, body `{"data":{"status":"received"}}`, no id, no reference code, no counts. **Every observable:**

| Observable | Case A: NID exists in clinic | Case B: NID unknown | Case C: same request already open | How it is made uniform |
|---|---|---|---|---|
| status line / headers | 202 | 202 | 202 | single return site; no per-case header |
| response body | `{"data":{"status":"received"}}` | identical | identical | one literal; no id/reference/timestamp/count (the `X-CPMS-Correlation-Id` header from `RestBase.php:290-298` is a random per-request id for every case) |
| error text | none | none | none | format errors all share one message (D4/D7) |
| rate-limit counters | per-mobile bucket charged once | same | same; the dedupe path **does not** charge a second bucket | bucket key is `hash('sha256','arreq:'.mobile)` — exists for every mobile, never for a NID (no NID-keyed counter exists at all) |
| side effects | INSERT | INSERT | `INSERT … ON DUPLICATE KEY UPDATE id=id` (no-op) | the second case is a **different row count, not a different response** |
| audit | `APPOINTMENT_REQUEST_CREATED` | same | same action, same masked fields | audit is not client-visible |
| SMS | none | none | none | the request path sends **no** SMS (the OTP SMS was already sent) |
| timing | HMAC verify + normalise + 1 capped DELETE + 1 INSERT | identical sequence | identical sequence + a duplicate-key branch on an index | no patient-table access; the duplicate-key branch is only reachable by the same requester replaying their own request |
| validation failure | `400 CLINIC_VALIDATION_FAILED`, one message | identical | identical | one message covers bad checksum, wrong length, invisible character and unknown slot shape; it never names *which* patient-level fact was wrong (there is none) |

**Deliberate exception (must be stated in the contract):** "slot not bookable" is **not** part of the uniform set. `/availability` is already public (`BookingController.php:43-63`) and discloses slot occupancy; the request path returns the existing `409 CLINIC_SLOT_TAKEN` / `422 CLINIC_VALIDATION_FAILED` / `400 CLINIC_SCOPE_REQUIRED` vocabulary for slot/clinic problems and never for person problems. **Cap exceeded** returns `429 CLINIC_RATE_LIMITED` — this discloses "this clinic already holds ≥N open requests" (**residual**, **OQ-7**), which is not patient existence. → **C3 met for every person-level fact.**

### D8/D9 — possession proof (C7)
**Decision:** `/otp/verify` returns, **in addition to** the #340 zero-access envelope (V17), one field:
`"booking_proof": "<base64url(payload)>.<base64url(hmac_sha256(key, payload_b64))>"`, and `"booking_proof_expires_in": 600`.
`payload` (JSON, ASCII, ≤200 bytes): `{"v":1,"jti":"<16 random bytes hex>","mh":"<HMAC(mobile)>","cl":<clinic_id>,"p":"appointment_request","exp":<unix>}`.
**Properties:** it is **not** a WP session, cookie, capability or user; it is single-purpose (`p`), single-use (see below), bound to the **mobile** (via `mh`, compared against the mobile the client sends in the same POST), bound to the **clinic** that the OTP challenge was stamped with (V17 `:447` — the client cannot choose it), and short-lived (600 s; the OTP row's own TTL is 120 s — `Settings.php:32` — so the proof deliberately outlives the code but not the shift).
**Single-use without a second table:** the token carries `jti`; the request row stores `proof_fingerprint = HMAC(key,'proof',jti)` under `UNIQUE u_arreq_proof`. A replay collides on that index, creates no second row, and returns the **same** 202 (idempotent, not an error). A replay from a *different* mobile fails the `mh` comparison first.
**What changes in `/otp/verify`:** the two additive fields, for **every** valid code, unconditionally (no branch on whether the mobile/user/patient exists — in #340 there is no such branch at all, V17). **Is the change itself an enumeration leak?** No: the envelope is already uniform across existing/non-existing numbers (V17), and the new fields depend only on (mobile, clinic from the challenge, clock, `jti`) — none of which is existence-dependent. **Caveat that is *not* introduced by this design:** on a multi-clinic install a **historical NULL** challenge fails closed with `CLINIC_SCOPE_REQUIRED` before any proof can be minted (`OtpService.php:437-447`) — a pre-existing response difference unrelated to existence. → **C7 met; the one dependency is OQ-R1 (#340 must keep the uniform envelope).**
**Rejected alternatives:** (a) a new proofs table (violates C1); (b) extending `cpms_otp_tokens` with proof columns (an `ALTER` on an existing table, outside a one-table design, and the OTP row is deleted by `cleanup.otp`); (c) a new `purpose` value in the `otp_tokens.purpose` ENUM (a schema change **and** it would re-open the policy-splitting problem the closed `PURPOSES` list exists to prevent — `OtpService.php:38-52`); (d) a WP cookie/session (forbidden by C4/#340).

### D10 — slot capacity (C8)
**Option (a) no hold — recommended.** Creation checks that the slot exists, belongs to the clinic/clinician/location and `is_open=1`, and reads `capacity`, `booked_count`, `held_count` (`0001:184-203`) as a *courtesy*; it reserves nothing and writes nothing to `cpms_schedule_slots`. Staff re-checks at decision time; if the slot has filled, staff rejects or offers an alternative (a normal reception action).
**Option (b) short hold — rejected.** It would have to decrement `held_count` (or insert into `cpms_slot_holds`) from a route reachable by anyone with a phone number: with 3 OTPs/day per mobile (V6) and cheap SIMs, an attacker can park capacity repeatedly; `holds.expire` would then be the thing standing between an attacker and a full clinic (`Application/Jobs/HoldsExpireHandler.php:31-71`). Reserving capacity for up to 72 h (the request TTL) is obviously worse; a 10-minute hold merely converts the queue into a treadmill.
**Race analysis:** two requesters may request the last slot; both rows are `pending`; the first staff decision confers no capacity (D13), so the clinic can only over-book by acting twice. That is the **same** failure mode as two receptionists booking twice today, and it is visible to staff, who own the decision. **No automatic penalty** (owner rule): an expired or rejected request debits no counter, blocks no future request beyond the 1-open cap while it is open, and produces no notification the requester did not ask for.

### D11 — tenancy (C9)
Public creation: `clinic_id`, `location_id`, `clinician_id`, `slot_id` are all **derived server-side** — clinic from the signed proof (which came from the OTP challenge row), location/clinician/slot from the persisted `cpms_schedule_slots`/`cpms_clinicians`/`cpms_locations` rows, exactly the derivation rule OTP A2 uses for its selector (`Rest/OtpController.php:172-215`). Any client-supplied `clinic_id` is ignored, not validated. Staff side: `App::scope()->clinicId` from `ScopeContext` (fail-closed, `Application/Scope/ScopeContext.php:15-45`) and every query carries `clinic_id = %d`; a row from another clinic is `404 CLINIC_NOT_FOUND`, mirroring the existing anti-enumeration stance (`Application/Patients/PatientService.php:264-273`). **No fixed id:** the table has no `DEFAULT 1` and no seed (contrast `cpms_idempotency_keys.clinic_id DEFAULT 1`, `0001:133`, later removed by `0016`). Dates: all `DATETIME(3)` in UTC via `CpmsDb::nowUtcSql()`; the *display* Jalali conversion stays a presentation concern. Engine/charset: §3. Timestamps never come from the client.

### D12/D13/D14 — staff side (C10)
**Capabilities (new, to be added in the build phase):** `cpms_appt_request_read`, `cpms_appt_request_decide`. They must be appended to `RolesAndCapabilities::ALL_CAPS` (`Auth/RolesAndCapabilities.php:107-121`, which the file itself calls the automatic reference for test TP-10) and to the role templates: **`SECRETARY_CAPS`** gets both (`:123-137`), **`MANAGER_CAPS`** gets read only (`:174-183` — manager today has no `APPT_*` at all: `CONFIG`, `SMS_CONFIG`, `PATIENT_READ`, `REPORT_READ`, `SEARCH`), **doctor** gets neither (`:139-158`), **accountant** neither (`:160-172`), **patient** none. Explicit per-membership deny wins over the template (established reception boundary, `Rest/ReceptionPortalController.php:414-440`). **OQ-8** covers whether the manager should also *decide*.
**Routes** (three, all under the existing reception namespace so the tenancy/skip machinery already applies — `Rest/RestClinicContext.php:452-454` marks `/clinic/v1/staff/portal/reception/*` as a role-then-scope boundary): `GET …/appointment-requests` (list, `cpms_appt_request_read`), `GET …/appointment-requests/{id}` (detail), `POST …/appointment-requests/{id}/decide` (`cpms_appt_request_decide`) with `{decision: approve|reject|cancel, reason?}`. Nonce required (`RestBase::requireNonce()`, `:30-35`).
**Atomic decision:** one guarded statement, the pattern already used for holds (`HoldsExpireHandler.php:42-55`) and for appointment status writes (`BookingService.php:1046-1057`, `:2214-2229`):
`UPDATE … SET status=%s, decision_reason=%s, decided_by_wp_user_id=%d, decided_at=%s, open_marker=NULL WHERE id=%d AND clinic_id=%d AND status='pending' AND expires_at > %s`; **require affected rows = 1**, else `409 CLINIC_INVALID_TRANSITION` (`docs/api/error-codes.md:35`). Two staff deciding concurrently: exactly one update matches; the loser sees 409 and the row unchanged (deadlock-free: single-row, primary-key-first predicate).
**Audit:** `APPOINTMENT_REQUEST_CREATED` (actor = system/null, resource_type `appointment_request`, resource_id = row id, meta: `clinic_id`, `slot_id`, `mobile` (**masked by key name**, V13), `national_id` **absent**), `APPOINTMENT_REQUEST_DECIDED` (actor wp user, role, `decision`, `reason` — the reason is passed through `VisibleText` and **must contain no run of 10 digits**, §10), `APPOINTMENT_REQUEST_MATCH` (NID never included: the meta only carries `matched: true|false`). Audit writes use `AuditLogger::log()` (`:43-54`), whose `sanitize()` masks exact key names and recurses (§V13); the service **must not** pass the hash either (a hash is not a secret worth persisting twice).
**Masking:** national ID → fixed 10 dots everywhere (D3); mobile → `0912***4567` in lists via `MobileValidator::mask()` (`:62-69`) and the full number only in the single decision view.
**Status vocabulary:** closed, the five values in §3 #16. Unknown value → treated as not-pending → 409.

### D15 — error vocabulary (scope consequence)
Only existing codes are used: `CLINIC_VALIDATION_FAILED` (`error-codes.md:32`), `CLINIC_RATE_LIMITED` (`:31`), `CLINIC_NOT_FOUND` (`:34`), `CLINIC_INVALID_TRANSITION` (`:35`), `CLINIC_SERVICE_UNAVAILABLE` (`:42`), `CLINIC_SCOPE_REQUIRED` (`:150`), `CLINIC_PERMISSION_DENIED`, `CLINIC_INVALID_NONCE`, `CLINIC_OTP_INVALID`/`CLINIC_OTP_EXPIRED` (`:20-21`). A dedicated "already decided" code would read better but requires `docs/api/error-codes.md`, outside this PR's two files (**OQ-9**).

### D16 — retention mechanics (C2)
New recurring job type `cleanup.appointment_requests` (SYSTEM scope: it sweeps all clinics with no request context) that must be registered in **all three** sources or the drift test fails (`tests/Integration/Phase2JobScopeRedFoundationTest.php:576`): `App::dispatcher()` (`Bootstrap/App.php:2475-2490`), `App::RECURRING_JOBS` (`:2616-2633`), `JobScopeRegistry::CLASSES` (`Application/Jobs/JobScopeRegistry.php:44-70`, alongside `cleanup.otp` `:47`). Bounded batches (`LIMIT 500`, the `holds.expire` shape, `Application/Jobs/HoldsExpireHandler.php:36-40`), idempotent (guarded transitions), and it must run **before** any backup that would otherwise preserve the rows. **Risk:** a SYSTEM-scoped job that deletes rows across tenants must not accept a client-supplied clinic; it ignores payload and computes from the table.

### D17 — backup/restore (C12)
The dumper's `SHOW TABLES LIKE '{prefix}cpms_%'` (V11) means the table is **included automatically** — **no list to edit, and no code change to make it appear**. Consequences the owner should see: (i) every backup now contains plaintext mobiles and names plus NID HMACs, so a backup is a personal-data store and the existing at-rest protection (sodium secretstream envelope, `Infrastructure/Backup/BackupEncryptionEnvelope.php:150-165`; S3 mirror) becomes the control that matters; (ii) **restore drops every `cpms_` table named in the manifest** (`Application/Backup/BackupService.php:350-356`) — restoring a backup taken **before** this table existed leaves the current table in place (it is not in that manifest) with its rows intact, which is the safe direction, while restoring a *newer* backup **recreates** it. I did **not** verify the manifest's behaviour for tables absent from the backup — **UNKNOWN** (`BackupManifest` validation `Domain/Backup/BackupManifest.php:56-58` only checks entry shape).

### D18 — export/privacy (C12)
WordPress privacy tooling is hook-based: `wp_privacy_personal_data_erasers` (developer.wordpress.org/reference/hooks/wp_privacy_personal_data_erasers/) and its exporter twin. **OBSERVED:** the plugin registers **neither** today (no `wp_privacy_*` registration anywhere in `src/`), so no privacy request can reach these rows. **LIMITATION that shapes the design:** both hook callbacks receive an **email address** (the eraser contract says "accepts an email address and a page number"), and this table is keyed by mobile — so a core privacy request cannot find these rows *unless* the site has an email↔mobile mapping. Therefore: (i) the **14-day hard delete is the primary control** (D16); (ii) the build phase adds an eraser entry that deletes rows for a mobile **supplied by staff** (the WP eraser UI passes only email, so this is a staff-network action, not a self-service one) — **OQ-10**; (iii) an exporter entry would return mobile/name/slot/status and the *fact* of a NID, never the hash or digits.

### D19 — the public route shape (and why it is not a second table)
`POST /clinic/v1/booking/request` — public in the WordPress sense (`permPublic()`, `RestBase.php:142-145`) exactly like `/booking/quote` (`BookingController.php:64-68`), requiring the possession proof in the **body** (`booking_proof`, `mobile`, `national_id`, `slot_id`, `given_name?`, `family_name?`, `is_minor_declared?`). It must be added to the REST scope skip list (`Rest/RestClinicContext.php:327-341`) or staff-scope resolution will reject it — a build-phase detail worth naming because the list is a hard-coded `preg_match` array. **Never in a URL:** the proof, the mobile and the NID are body-only; there is **no** `GET` variant of this route (a `GET` would put the proof in a URL and therefore in logs).

---

## 6. Conditions C1–C14 — status

| C | Status | Where / why |
|---|---|---|
| C1 one table | **met** | §3; no `ALTER` on existing tables; proof state in a token + unique index; counters in the existing `cpms_rate_limits` |
| C2 minimal data, TTL, cleanup, caps | **met, with a stated residual** | D2/D16 — TTL options given, mechanism named, WP-Cron starvation behaviour stated, caps DB-enforced (1 open/mobile) + counted (500/clinic) |
| C3 identical response | **met** | D6/D7 — no patient read at all; full observable table |
| C4 no auto-merge/link/access | **met** | D13 + §11; approval is a state change only |
| C5 NID never in URL/log/audit; storage options; matching; masking; normalisation | **met** | D3/D4; masking rule; `hash_equals` compare |
| C6 historical-NULL path stays closed | **met, dependent on #340** | §1, OQ-R1 |
| C7 possession proof | **met** | D8/D9 |
| C8 capacity | **met** | D10 (no hold, with race/abuse analysis) |
| C9 tenancy | **met** | D11 (FKs, no default 1, UTC, InnoDB, `$db->table()`) |
| C10 staff side | **met** | D12/D14 (roles, caps, audit, masking, atomic transition, closed vocabulary) |
| C11 edge policies | **met with two challenges** | §7 |
| C12 operations | **met** | §8 (migration, column-absent fail-closed, uninstall, backup, privacy) |
| C13 abuse model | **met** | §9 |
| C14 test plan | **met (descriptions only)** | §10 |

---

## 7. Edge policies (C11) — confirm or challenge

| Policy | Verdict | Reason |
|---|---|---|
| Foreigners and children via reception only, with in-person documents | **CONFIRM** | This table can only hold a 10-digit Iranian NID (D4). A passport number is not representable and no field is added, so a foreigner has no path here at all — which is exactly "reception only". |
| Request with a mobile different from an existing record → private reception review, no hint | **CONFIRM**, and mechanically guaranteed | The public path does not read `cpms_patients` (D6), so it **cannot** compare mobiles; there is no branch to leak. Reception sees both numbers in the decision view. |
| No age limit; guardian info for <18 collected separately by reception | **CONFIRM policy, CHALLENGE the mechanics** | No birth date is stored, so the system can neither enforce an age limit nor warn. `is_minor_declared` (§3 #14) is a **self-declared** hint so reception asks on the phone; it is not verification. Options: (i) the flag (recommended), (ii) no flag (reception always asks), (iii) collect a birth date (violates minimal data). **OQ-11.** |
| Shared mobile / browser id / existing appointment is NOT ownership | **CONFIRM** | The proof attests only that the SMS code was entered for that mobile at that time; no row, cookie or link confers anything. A shared family mobile therefore produces a request **from a person who controls that mobile** — reception decides. Existing appointments convey nothing. |

---

## 8. Operational plan (C12)

**Migration (build phase, not now):** `src/Migrations/2026_11_17_0032_appointment_requests.php` (number/date **not reserved** – OQ-1). `up()`: a single `CREATE TABLE IF NOT EXISTS` (§3) guarded by an `information_schema.tables` existence check (pattern `2026_09_09_0019_...php:37-48`), `down()`: `DROP TABLE IF EXISTS` with an explicit comment that it destroys queued intents. **Idempotent** by construction. **Rollback story:** the runner wraps `up()` in a transaction (`Migrations/MigrationRunner.php:91-110`) but MySQL DDL causes an implicit commit (dev.mysql.com, implicit commit) — so the transaction does **not** make a failed `CREATE` atomic (INFERENCE from the documented engine behaviour; UNKNOWN whether this specific statement ever half-applies). Practical rollback = the `down()` drop, or restore from the pre-migration backup (D17). **Column-absent installs fail closed — two layers:** (i) the outer gate: while this migration is pending, `App::is_runtime_blocked_by_pending_schema()` makes `RuntimeSchemaGate` answer `503 CLINIC_SERVICE_UNAVAILABLE` for **every** `/clinic/v1/*` route (`Bootstrap/App.php:622-639`, `Bootstrap/RuntimeSchemaGate.php:67-90`) — the whole surface, not just this one; (ii) the inner gate, mirroring #340 (V18): a memoized `information_schema.tables` probe before the first query, returning the same neutral 503 for **every** caller (so the absence is not itself an oracle for who may request).
**Uninstall:** `uninstall.php:8-20` covers the table in both branches (V12) because it matches `{prefix}cpms_%`. **Privacy implication if the operator did not opt into wiping:** the table survives with plaintext mobiles/names and NID hashes. Recommendation for the build phase: delete expired/terminal rows on deactivate, and treat any surviving rows as personal data (**OQ-12**).
**Backup/restore:** see D17 — no code change needed for inclusion; the privacy cost and the restore asymmetry are stated there.
**Export/privacy:** D18; **OBSERVED** absence of any `wp_privacy_*` registration today, and the email-vs-mobile contract gap that limits self-service erasure (LIMITATION).
**Observability:** the health endpoint (`HealthController.php:18-33`) is public and returns `schema` version + jobs health; this design adds **nothing** to it (an "open request count" there would be a public disclosure).

---

## 9. Threat model (C13)

| # | Attacker | Goal | Vector | Mitigation | Residual |
|---|---|---|---|---|---|
| T1 | Anonymous | learn whether a national ID / patient exists in a clinic | submit requests with chosen IDs and compare responses | the public path never queries patients (D6); body/status/timing uniform (D7); no NID-keyed counter (V15 rule) | none identified for this route; **pre-existing** oracle on the *patient* path (V14) is untouched and out of scope |
| T2 | Anonymous with several SIMs | fill the table | mass creations | per-mobile OTP quota 3/day + 60 s cooldown (V6); 1 open row per (clinic, mobile) unique (§3 #9); 500 open rows/clinic (D2); per-IP bucket; 14-day terminal deletion | a well-funded attacker with many prepaid numbers can still fill 500 slots per clinic per TTL window — the cap bounds the *damage*, not the *attempt* |
| T3 | Anonymous | harvest mobiles (whose numbers exist in the system) | probe responses / errors | no response distinguishes numbers (#340 verify is uniform for any valid code, V17); the request path adds no new signal; no SMS is sent by the request path (so no "you are registered" text) | timing differences in the *OTP* path are pre-existing and out of scope |
| T4 | Holder of a leaked proof | replay it to create many rows / impersonate | re-POST the token | token bound to mobile + clinic + purpose + 600 s; `UNIQUE u_arreq_proof` makes the second use a no-op with the same 202 (D8) | an attacker who also holds the mobile can create at most one open row |
| T5 | Requester (untrusted input) | XSS into staff screens | `given_name`/`family_name`/`decision_reason` rendered in the reception UI | same validators as the patient name path (bidi/visible-content/120 chars, `PatientService.php:664-679`); output must be HTML-escaped at the print site (`esc_html`) — OWASP XSS Prevention Cheat Sheet, "Output Encoding" | escaping is a build-phase obligation; a future `html`-context mistake is not prevented by validation |
| T6 | Anonymous | timing oracle on existence | measure response time | no patient read; fixed statement shape; audit insert is unconditional | DB-level variance on the duplicate-key branch (reachable only by the same requester) is unmeasured — **UNKNOWN** |
| T7 | Attacker on a shared NAT | lock legitimate users out of the bucket | exhaust the per-IP bucket | bucket size from settings/hard cap; the *existing* OTP IP bucket is already shared across clinics (`OtpService.php:203-212` comment) | a clinic behind one NAT can throttle its own patients; the design **adds** one more shared bucket and does not fix the existing one (**OQ-13**) |
| T8 | Curious staff account | enumerate patients via the match action | repeated ID guesses | `cpms_appt_request_decide`/read caps, per-user rate limit, audit per match, boolean-only answer | an insider with patience is a governance problem, not a table problem |

---

## 10. Test plan for the BUILD phase (descriptions only — no tests are written here)

Each line names the mutation that **must** turn it red.

| # | Test | Mutation that must make it fail |
|---|---|---|
| T-1 | Identical-response: three cases (NID exists / unknown / open request already exists) produce byte-identical status+body+headers (ignoring the correlation id) | add a branch that returns a different `data` when a patient row exists → red |
| T-2 | No-side-effect: after a creation, `cpms_patients`, `cpms_patient_user_links`, `cpms_users`, and `cpms_appointments` row counts and `updated_at`s are unchanged | insert a patient in the creation path → red |
| T-3 | Cap: the 2nd open request for the same (clinic, mobile) creates no row and still returns 202; the 501st open row for a clinic returns 429 | drop `u_arreq_open` → red; remove the clinic counter → red |
| T-4 | Cleanup: rows past TTL are `expired` and terminal rows older than 14 d are gone after one job run; a second run changes nothing | remove the `LIMIT`/guard → flaky-red; make the job skip `clinic_id IS NOT NULL` → red |
| T-5 | Atomic transition: two concurrent decides (real MySQL, `pcntl_fork` as in the existing two-connection tests) — exactly one `approved`, one 409 | replace the guarded UPDATE with read-then-write → red |
| T-6 | Tenant isolation: a staff user of clinic B gets 404 for clinic A's request; the creation route ignores a client-supplied `clinic_id` | drop `clinic_id` from the WHERE → red |
| T-7 | Digit normalisation: Persian/ASCII/Arabic-Indic forms of the same ID hash **identically**; BOM/ZWJ/zero-width/inner space are **rejected**; all-same-digit rejected | strip invisible characters instead of rejecting → red; hash the raw string → red |
| T-8 | Secret-missing fail-closed: with no key option and no constant, creation and match both return the neutral 503 and write nothing | fall back to unkeyed `sha256` → red |
| T-9 | Replay: the same proof twice → one row, two identical 202s | drop `u_arreq_proof` → red |
| T-10 | Audit: no audit row (any field, any nesting) contains a 10-digit NID or the hash; the mobile is masked | pass `national_id` unmasked / name the key `National_ID` (V13 case sensitivity) → red |
| T-11 | Column absent: a probe-failure install returns 503 for this route for every caller | let the query run and 500 → red |

**Exact CI invocations the build phase must rely on (OBSERVED in `.github/workflows/ci.yml`):**
`php -d memory_limit=512M vendor/bin/phpunit --testsuite Unit` (`ci.yml:172`) and
`php -d memory_limit=1G vendor/bin/phpunit --no-configuration --bootstrap tests/integration-bootstrap.php tests/Integration` (`ci.yml:810-812`). The `Integration` job is `Integration (WP 7.1 + MySQL 8)` (`ci.yml:225`); the unit matrix is PHP 8.1–8.5 (`ci.yml:108`). **This PR runs neither** (see §19 of the report; this document runs no PHP).

---

## 11. What this design deliberately does NOT do

- **No** code, table, migration, test, workflow, capability, route, template or JS. Nothing is numbered or reserved (OQ-1).
- **No** patient creation, **no** WP user, **no** session, **no** patient⇄user link, **no** appointment — from any path of this feature (C4/D13). It does not re-open, modify or work around the historical-NULL confirm path; that stays owned by #340 (C6).
- **No** staff-link tool ("Slice 2") and **no** conversion flow: how a staff member later links a patient is **out of scope**, and the design stops at `approved`.
- **No** change to `NationalIdValidator`, `PatientService`, `OtpService`'s policy lists, `error-codes.md`, `api-contract.md`, the permission matrix, or any ADR. The gaps found in §2 (V1, V2, V4, V14, V15) are **reported**, not fixed.
- **No** fix for the pre-existing full-NID response of `/patient/me` (V8) or for the pre-existing NID-existence message (V14), although both are in the same problem family. Fixing either is a separate owner-approved change.
- **No** exposure of the requester to the staff system as an identity: a request is *labour for reception*, not an account.
- **No** new error codes (D15), **no** new public endpoint beyond the one POST (D19), **no** `GET` variant.
- **No** claim that any of this is implemented, tested, reviewed or approved: two independent security reviews and an explicit owner build approval are still owed.

---

## 12. Phase 2 — adversarial read of this design (self-review, before pushing)

Five most dangerous ways an attacker or a careless implementer could still (a) learn existence, (b) gain access, (c) fill the table, (d) leak the NID, (e) race two decisions — each with the text that blocks it.

1. **(a) Existence — via a "helpful" pre-check.** The most likely implementation error is a well-meaning `find_by_national_id()` call inside the creation path ("skip duplicates"), which instantly turns the route into the oracle `PatientService.php:850-857` already is (V14). **Blocking text:** D6 ("does not read `cpms_patients`, `cpms_patient_user_links` or `cpms_patient_identities` at all") + T-1/T-2's mutation list. **Still open:** nothing in the text *forbids* a future reviewer from adding it — the test is the only enforcement.
2. **(b) Access — via the proof being treated as a login.** `booking_proof` is a bearer token; a careless implementation could put it in a cookie or accept a WP session as an alternative, resurrecting the #340 hole. **Blocking text:** D8 ("not a WP session, cookie, capability or user") + C4/D13 + the rule that the token is body-only (D19) + the `purpose` list *not* being extended (§D8 rejected (c)). **Residual:** the token is replayable within 600 s for exactly one row; it confers no read.
3. **(c) Filling the table — via the cap being counted in PHP.** A naive "count, then insert" is racy and, worse, invites a reviewer to add a proofs/attempts table. **Blocking text:** D2's DB-enforced `u_arreq_open` + the counted clinic cap under `GET_LOCK` + the explicit note that a second table is a **STOP** condition (D1). **Residual:** a multi-SIM attacker fills 500 per clinic; that is bounded, not prevented (T2).
4. **(d) Leaking the NID — via logs, audit or an index.** Every path that persists a string is a leak candidate: the exception text on a failed HMAC, the audit `meta`, a `LIKE` search, `cpms_rate_limits.window_key` (V15 shows the codebase already writes raw mobiles there), and the `MASK_KEYS` **case-sensitive** key match (V13). **Blocking text:** D3 (hash only), D4 (reject, never strip), V13's design rule, V15's rule (hash every new bucket key), T-10, and the explicit "the service must not pass the hash to the logger either".
5. **(e) Racing two staff decisions — via read-then-write.** The existing codebase is disciplined here (`HoldsExpireHandler.php:42-55`, `BookingService.php:1046-1057`) but new code drifts. **Blocking text:** D14's single guarded `UPDATE` with the affected-rows=1 requirement and T-5's two-connection test; note also that approval performs **no** capacity write (D10/D13), so there is no second resource to race on.

**Self-imposed blocker definition:** any unmet C1–C14, any existence oracle, any unlabelled assumption stated as fact, any scope violation. Applied here: **no blocker found** in the document's own terms; the *residuals* listed in D2 (cron starvation), D7 (`429` reveals clinic load), D3 (key rotation), D18 (email-vs-mobile erasure), T5 (escaping is a build obligation) are disclosed, not hidden.

---

## 13. Limitations of this document

- **LIMITATION:** nothing was executed. Local PHP is PHP-WASM only; no MySQL, WordPress or browser ran; every timing and engine statement is INFERENCE. CI does not exercise this file.
- **LIMITATION:** #340 is unmerged and `CONFLICTING` with `main`; its head may change. Facts V17/V18 are pinned to `ff7cec823212f872ecf0dd3e2b24965adfc862f3` and may drift (OQ-R1).
- **LIMITATION:** the repository is a **shallow** clone grafted at `aec7306`; cross-history comparison of #340 against its own base was limited to `git diff aec7306…FETCH_HEAD` and `git show FETCH_HEAD:path` (both read-only).
- **UNKNOWN:** MySQL/MariaDB behaviour of `UNIQUE` with NULLs on the exact server versions in CI is documented but not measured here; the CHECK-constraint enforcement on 5.7-era servers is UNKNOWN (not used in §3 — the invariant is test-enforced instead).
- **UNKNOWN:** whether `GET_LOCK` timeouts under real contention behave as INFERRED; not measured.
- **LIMITATION:** no independent review has happened. Two security reviews and owner approval are outstanding; this document is a proposal, not an approval.

---

## 14. Open questions

### (a) Owner decisions (product policy — I cannot decide these)

- **OQ-1 — Migration identity.** May the build phase create `2026_11_17_0032_appointment_requests.php` (name/date/number not reserved here)? **PROPOSAL:** yes, single migration, no data backfill.
- **OQ-2 — TTL and terminal retention.** 72 h pending + 14 d terminal (**PROPOSAL**), or 24 h/48 h/7 d, and 7 d/30 d for terminal? Any legal-retention floor that contradicts these (`Settings.php:76-82` already carries `retention.audit_years`=10, `retention.record_years`=15 — a queue row is **not** a clinical record, so those should not apply).
- **OQ-3 — Key rotation policy.** When may the old NID hash key be dropped, knowing rows hashed with it become unmatchable? **PROPOSAL:** keep exactly two generations; before dropping, mark affected rows `national_id_hash_version = 0` (unverifiable) and let them expire.
- **OQ-4 — Masking strictness.** Fixed 10 dots (no digits), or last-4 like `NationalIdValidator::mask()`? **PROPOSAL:** no digits at all.
- **OQ-5 — The pre-existing mixed-digit national-ID drift (V1/V2).** Out of scope here; does the owner want a separate corrective change so that what is *stored* is the normalised form?
- **OQ-6 — Mobile in clear (D5).** Accept for callback purposes, or hash-only plus a staff-initiated SMS (new template)?
- **OQ-7 — Does `429` on the per-clinic cap leak acceptable information** (clinic load), or should the cap answer carry the same 202 and simply drop the row? **PROPOSAL:** keep `429` — a silent drop would let a real patient believe they have a request.
- **OQ-8 — Who may decide.** Secretary only (**PROPOSAL**), or secretary + manager? Should a doctor see requests for their own slots?
- **OQ-9 — New error code** for "already decided" (would need `docs/api/error-codes.md`), or reuse `CLINIC_INVALID_TRANSITION`/409 (**PROPOSAL**)?
- **OQ-10 — Privacy erasure path** given the email-based core contract (D18): staff tool, TTL only, or both?
- **OQ-11 — Minor flag** (`is_minor_declared`), or nothing and reception always asks?
- **OQ-12 — Uninstall behaviour for this table.** Keep (like everything else) unless `cpms_wipe_on_uninstall` (V12), or delete/de-identify on deactivate as a special case?
- **OQ-13 — The shared per-IP bucket** (pre-existing, `OtpService.php:203-212`): the design adds one more (T7). Fix now, later, or never?

### (b) Reviewer checks (verification items, not product choices)

- **OQ-R1 — #340 stability.** C6 and D9 are pinned to `ff7cec8`. Re-check on the merge commit that (i) verify still returns the uniform zero-access envelope for every valid code, (ii) confirm still returns the uniform `CLINIC_VALIDATION_FAILED`/400 with zero links.
- **OQ-R2 — No `purpose` extension.** Confirm a new `purpose` value would be a schema change (ENUM, V16) and that the design correctly avoids it (D8 rejected (c)).
- **OQ-R3 — `CHECK`-less invariant.** Confirm that enforcing "`open_marker=1` ⇔ `pending`" in application code + tests (rather than a `CHECK`) is acceptable on the supported engines.
- **OQ-R4 — FK deletion semantics.** `clinician_id`/`clinic_id` are plain FKs (RESTRICT-style, matching `0001`), so deleting a clinician with open requests fails until they are purged. Acceptable, or should clinician deletion cascade?
- **OQ-R5 — Backup/restore asymmetry** (D17): confirm the restore path's handling of a table absent from the manifest (**UNKNOWN** here).
- **OQ-R6 — The `GET /patient/me` full-NID response** (V8) and the NID-existence message (V14) are pre-existing and untouched; confirm they stay out of this change's scope.
