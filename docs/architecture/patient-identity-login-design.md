# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> This document is a **proposal for review**. Merging it does **not** authorise a build. The build
> starts **only after** this PR is merged **and** two independent readers have passed it. The **one**
> table it specifies (`cpms_patient_logins`) is **owner-approved in principle** (2026-11-16) and needs
> **no further owner approval for exactly that table**; **any other schema change, any migration
> number, and any behaviour change still need explicit owner approval.** No code, migration, test or
> workflow accompanies this document.

| | |
|---|---|
| **Base** | `origin/main` = `6d4320d605465ed638eb4a2a4e1407312972b999` (merge of PR #355; fetched 2026-11-16) |
| **Supersedes** | `docs/architecture/appointment-request-design.md` (removed in this PR; history keeps it) |
| **Depends on** | PR **#340**, now **MERGED into main** (verified: `OtpService.php` is 791 lines and `verify()` returns `user_id=0, session_issued=false` at `:556-573`) |
| **Labels** | **OBSERVED** = read at the stated commit, not executed. **INFERENCE** = reasoning. **LIMITATION** = not established. **UNKNOWN** = not proven. **DECIDED (owner)** = owner decision recorded for this design. |
| **Nothing executed** | LIMITATION: no PHP, WordPress, MySQL or browser was run; no CI was re-run for this document. PHP gates are **NOT APPLICABLE** (docs-only diff). |

## 0. Decisions at a glance (NON-NORMATIVE; sections win)

| # | Decision |
|---|---|
| D1 | **One** new table `cpms_patient_logins`; **no other schema change** (§1). |
| D2 | Access level is **derived per request** from the live login row: `none` / `booking` / `full` (§2, A2). |
| D3 | `pending` may book and see its own appointments; **no** records, files or prescriptions (§2, A1). |
| D4 | National ID **never leaves the login table** until reception verifies; the patient row is created **without** it (§5, A3). |
| D5 | Verification marker is `cpms_patient_logins.state='active'`, set **only** by a staff route; **no back-fill** — every existing account is unverified (§1, A8). |
| D6 | Unverified cap = **2 active appointments in total per account** (DECIDED (owner)). |
| D7 | Record access opens **immediately** after in-person approval (DECIDED (owner)). |
| D8 | A guardian account **may hold a child's appointments** (DECIDED (owner)) — §11. |
| D9 | No named locks (`GET_LOCK` survives `COMMIT`); concurrency is the UNIQUE index plus one transaction (§3, A5). |
| D10 | 8-hour cookies apply to **patient-only** users and never shorten staff sessions (§8, A6). |

---

## 1. Data model — one table, zero other schema change

`{wp_prefix}cpms_patient_logins`, `InnoDB`, `utf8mb4_unicode_ci`.

| Column | Type | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `wp_user_id` | `BIGINT UNSIGNED NOT NULL` | the account; no FK to `wp_users` (none exists anywhere in this repo — OBSERVED the whole `0001` schema and `0019`) |
| `mobile` | `CHAR(11) NOT NULL` | normalised `09xxxxxxxxx`, ASCII only |
| `state` | `ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending'` | the verification marker (D5) |
| `given_name`, `family_name` | `VARCHAR(120) NULL` | pre-verification data (scrubbed, §6) |
| `national_id` | `VARCHAR(10) NULL` | pre-verification data; **only** copy until verification (§5) |
| `hold_reason` | `ENUM('mobile_reuse','nid_exists','clinic_mobile_exists','nid_mismatch','other') NULL` | why a registration could not proceed automatically |
| `verified_by_wp_user_id`, `verified_at` | `BIGINT UNSIGNED NULL`, `DATETIME(3) NULL` | who approved, when |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |
| `live_slot` | `TINYINT GENERATED ALWAYS AS (IF(state='revoked',NULL,1)) STORED` | see below |

**Uniqueness:** `UNIQUE u_pl_live_mobile (mobile, live_slot)`, `UNIQUE u_pl_live_user (wp_user_id, live_slot)`, plus `KEY (state, created_at)` and `KEY (national_id)`.
A `UNIQUE` index permits multiple `NULL`s (dev.mysql.com/doc/refman/8.4/en/create-index.html, "Unique Indexes"), so a revoked row leaves the slot free **and stays as history**. Exactly one live row per mobile and per account; unlimited revoked rows.
INFERENCE: MariaDB 11.4.13 honours the same rule (mariadb.com/kb/en/create-index/) — **UNKNOWN**, not executed. The generated-column + multi-column UNIQUE combination is likewise INFERENCE on both engines; the P1 test must prove it before any code depends on it.

**Total schema change: one table, zero columns added to existing tables, zero migrations in this PR.**

---

## 2. Access level and the route matrix (A2, A1, D2, D3)

One function, `PatientAccess::level(int $wp_user_id): string`, returns:

| Live login row | Level | What it unlocks |
|---|---|---|
| none, or `state='revoked'` | `none` | nothing; cookie dies on first use |
| `state='pending'` | `booking` | hold, confirm, resume, own appointments (read/cancel/reschedule), guardian's verified dependents (§11) |
| `state='active'` | `full` | `booking` + records, prescriptions, files |

It is evaluated **on every request** from the database (never from the cookie or a session flag), so a revocation or demotion takes effect on the next request even with a valid cookie.

**Route × required level** (every patient route, with the file:line of its current guard — OBSERVED):

| Route | Current guard | Required |
|---|---|---|
| `POST /booking/hold` | `BookingController::requirePatient` `:478-501` (role only) | `booking` |
| `POST /booking/confirm` | same `:100` → `:478` | `booking` |
| `GET /booking/resume` | same `:119-120` | `booking` |
| `GET /appointments/mine` | same `:130` | `booking` |
| `GET /appointments/{id}/reschedule-options` | same `:145` | `booking` |
| `POST /appointments/{id}/reschedule` | `reschedulePermission` `:160` | `booking` |
| `POST /appointments/{id}/cancel` | `:198` | `booking` |
| `GET /patient/me` | `PatientController::requirePatient` `:64-68` → `:223-249` (role only) | `full` |
| `PATCH /patient/me` | same `:76` | `full` |
| `GET /patient/my-records` | same `:54-59` | `full` |
| `POST /patient/enroll` | same `:38-43` | `full` (and staff) |
| `POST /appointments/{id}/no-show` | `:210` (staff-only by capability) | unchanged (staff) |

Both `requirePatient` implementations check **only** `ROLE_PATIENT` (OBSERVED `PatientController.php:233`, `BookingController.php:488-501`); neither consults a login row. The central gate replaces the role check at these ten call sites. **Test:** a route × state matrix (3 states × 10 routes) asserting the exact status for each cell (§9, T-M1).

---

## 3. Login algorithm (purpose=`login`)

1. Normalise the mobile (`MobileValidator::normalize`, EN digits only); invalid ⇒ the same 400 as a bad code.
2. Rate limits, unchanged and always applied **before** any branch (§10, A10).
3. Code verification and the atomic consume — **reused untouched** (`OtpService.php:527-534`: `SET consumed_at … WHERE id = %d AND consumed_at IS NULL`, affected rows must be 1).
4. **One transaction, already existing:** `verify()` opens `$this->db->transactional(` at `:449` and the closure closes at `:575`; the consume (`:527`) and its audit (`:562`) are inside it. The candidate read and the eligibility check go **into that same closure**, after the consume. The cookie is minted only **after** commit (`:591-599`) — already correct.
5. Candidates: `SELECT wp_user_id FROM cpms_patient_logins WHERE mobile=%s AND state IN ('pending','active')` — 0 or 1 row, guaranteed by `u_pl_live_mobile`. **No `LIMIT 1` guessing** (that was the pre-#340 defect).
6. **0 live rows + valid code** ⇒ `202 {"status":"register_required"}` and **no cookie** (A1). The person already proved possession, so this is admissible (owner default). **1 live row** ⇒ re-check eligibility with the same predicate as `PatientPortalPage::isPatientOnly()` (OBSERVED `:92-111`: single role, no staff role, no `manage_options`, plus `holds_any_cpms_capability()` `:116-124` over `ALL_CAPS` — this closes the "role-name-only guard misses custom capabilities" gap). Ineligible ⇒ the **generic invalid-code envelope**, no cookie, existing `FORBIDDEN_ACCESS_ATTEMPT` audit.
7. Session: `wp_set_auth_cookie(user_id, false)` — today the code passes `true` (OBSERVED `:595`) and must change; lifetime 8 h via an `auth_cookie_expiration` filter (OBSERVED: none exists in `src/`), scoped per A6 (§8).
8. Audit `OTP_VERIFY_OK` / `LOGIN_SUCCESS` with `ip_hash` only — `cpms_audit_logs` has `ip_hash CHAR(64)` and **no** mobile column (OBSERVED `0001:674-698`, `ip_hash` at `:685`), so a raw mobile cannot be stored there unless someone puts it in `meta_json`.
9. **Residual window:** eligibility commits at `:575`, the cookie is minted at `:595`. A revocation in between yields one cookie, which dies on its first request because the gate re-reads (§2). Accepted and stated.

---

## 4. Self-registration

`POST /patient/register {mobile, code, given_name, family_name, national_id}`.

1. Consume the OTP exactly as login (step 3 above) — purpose `verify_mobile` is already allowed (OBSERVED `OtpService.php:51`), so **no schema change**.
2. Normalise the national ID: strip nothing, reject invisibles/bidi, fold Persian/Arabic digits to ASCII, then the existing checksum (`MobileValidator::fold_digits` `:77-102` is private ⇒ a small public helper), then `NationalIdValidator::isValid()`.
3. Create **exactly two rows**: one WP user (role `cpms_patient`, random non-derivable login, unusable password — the pattern already used at `ManagementPortalService.php:1551-1558`) and **one** `cpms_patient_logins` row with `state='pending'`. **No patient row, no link** — the clinic is unknown and `cpms_appointments.patient_id` is `NOT NULL` (OBSERVED `0001:226`).
4. **Collision branches (A4).** Only two can exist at registration, because no clinic is known yet:
   - **mobile already live** (`u_pl_live_mobile`) ⇒ create nothing, set `hold_reason='mobile_reuse'` on the **existing** row, and enqueue.
   - **anything else** ⇒ nothing to collide with yet; the row is created `pending` and enqueued.
   Collisions on `u_pat_mobile` / `u_pat_nid` are **per clinic** (OBSERVED `0001:82-83`) and therefore **cannot be evaluated** at registration — they are evaluated at first booking (§5) and at reception verification. Any branch that previously claimed otherwise is removed.
5. **Neutral response (A4/A3):** every branch returns the identical `202 "request received; reception will contact you"`, and **every** branch leaves a queue entry — either a new `pending` row or a `hold_reason` on the existing live row. The promise is therefore true in all cases.
6. **The queue** is the `cpms_patient_logins` table itself (`state='pending'` or `hold_reason IS NOT NULL`), read by one staff route. **Not** `cpms_notifications`: `clinic_id` is `NOT NULL` there (OBSERVED `0001:634`) while no clinic exists yet, `NotifDispatchHandler` flips internal rows to `sent` (OBSERVED `:14`), `payload_json` is exported by reports (`ExportService.php:377`), and `purgeExpired` deletes **only** `REPORT_EXPORT_READY` rows (`:379`, `:411`) so nothing would ever remove them.

---

## 5. First booking, and why the national ID stays in the login table (A3)

At first booking in clinic **X**, inside the confirm transaction:

1. Resolve the caller's linked active patients via the existing query `BookingService::linkedActivePatientsForClinic()` (OBSERVED `:3057-3063`). If the account has **no** link in X, create, in this order: `cpms_patients(clinic_id=X, mobile, names, national_id=NULL)` then `cpms_patient_user_links(clinic_id=X, patient_id, wp_user_id, mobile_at_link)` (`u_link_pair` `0001:97`). The `national_id` column is nullable (OBSERVED `0001:61`), so leaving it NULL is valid.
2. **Why NULL removes an oracle:** `u_pat_nid` is `UNIQUE (clinic_id, national_id)` (OBSERVED `:83`). Writing the ID at booking would turn a duplicate-key error, or the absence of one, into a signal that a person with that ID is already a patient in X. Leaving it NULL means booking **never** touches the ID, so no such signal exists. The ID is written once, by reception, after documents are seen (§6).
3. **Clinic collision** on `(clinic_id, mobile)` (a walk-in patient with the same mobile, created by reception — OBSERVED `ReceptionPortalController.php:183-188` (`patient_create` at `:564`) → `PatientService::create_record` `:360-400`, which writes a link only in the enrollment branch `:461-464`): if `u_pat_mobile` already holds that mobile in X, the design does **not** create a second row and does **not** attach the pending account to the existing walk-in patient. The caller gets the **generic** `CLINIC_VALIDATION_FAILED` already used for a zero-link holder (OBSERVED `BookingService.php:902-910` — one uniform message for every such caller), a `hold_reason='clinic_mobile_exists'` is set for reception, and the walk-in patient is never named, counted or otherwise revealed.
4. **Staff views that must show the unverified flag:** `PatientService::staffView()` (OBSERVED `:801`) and the reception list/search responses built from it (`ReceptionPortalController.php:160`, `:183`). Each patient row rendered while the account is `pending` carries `login_state:'pending'` and a fixed label; no ID is shown in any list.

---

## 6. Reception provisioning, verification, mobile change and retention

**Provisioning a walk-in:** create the patient as today, create the WP user the same way as §4.3, one `pending` row, verify in the same screen ⇒ `state='active'`.

**Verification (D7):** staff open the pending row, compare the in-person document with `given_name`/`family_name`, then either **approve** — `state='active'`, `verified_by_wp_user_id`/`verified_at` set, `hold_reason` cleared, **`national_id` copied to `cpms_patients.national_id`**, and the login row's `national_id` **scrubbed to NULL** — or **reject** — `state='revoked'`, scrub, one audit row. Record access opens immediately (DECIDED).

**Mobile change** (in person only): one transaction — old row `state='revoked'`, new row inserted, `WP_Session_Tokens::destroy_all()` for that user (developer.wordpress.org/reference/classes/wp_session_tokens/), one audit row. Revocation is an `UPDATE`, never a `DELETE`, so history and the live UNIQUE both survive.

**Retention without orphans (A5):** live rows are **never deleted**.
- At decision (approve/reject) the three personal fields are **scrubbed to NULL**; the non-personal row, state and `hold_reason` remain.
- At **14 days** after `created_at`, any still-`pending` row is scrubbed **and** set to `state='revoked'`. Its WP user is deleted **only if** it has no `cpms_patient_user_links` row and no `cpms_appointments` row via its patients; otherwise the account is kept, revoked, and can only be re-enabled in person.
- **Sweep host:** there is no suitable existing handler to reuse — the cleanup jobs are `cleanup.otp` (only `cpms_otp_tokens`, OBSERVED `OtpCleanupHandler.php:22-25`), `cleanup.rate_limits`, `cleanup.idem`, `cleanup.oplog` (OBSERVED `App.php:2625-2628`), each bound to its own table. **Smallest new hook:** one new recurring job `cleanup.patient_logins` (interval 1, same table-driven pattern as `App::RECURRING_JOBS` `:2616-2635`) doing a bounded `LIMIT 50` sweep.
- **Stalled sweep:** UNKNOWN — there is no health surface for a stalled job that I read. Proposed: the same admin health panel that already reports pending schema (UNKNOWN that such a panel exists); until then this is a **declared residual** (personal data of an abandoned registration can outlive 14 days).

**Existing accounts (A8):** a patient with a password account has **no** login row (no back-fill, D5). Reception uses one action, **"attach SMS login"**: one transaction — insert the row `active` (in person, so verified), `destroy_all()` sessions, one audit row. A conflicting mobile is reported with the **generic** message (never "that number belongs to another account"). Password login keeps working untouched; SMS login is additive.

---

## 7. Oracle and timing (A1, A4)

| Mobile situation | `/otp/request` | `/otp/verify` (valid code) |
|---|---|---|
| unknown | send, 200 | `202 register_required`, **no cookie** |
| live `pending` or `active`, eligible | send, 200 | 200 success + `Set-Cookie` |
| `revoked`, or no live row but a revoked history row | send, 200 | `202 register_required`, no cookie |
| two or more candidates | **cannot occur** (UNIQUE) | — |
| staff / ineligible account | send, 200 | **generic invalid-code envelope**, no cookie |
| wrong code, any mobile | send, 200 | generic invalid-code envelope |

**Honest oracle statement:** `register_required` is distinguishable from success — but only by a caller who **already proved possession of that mobile**. That is the owner's explicit allowance. Ambiguous, staff-colliding and ineligible cases stay generic and never set a cookie. Timing: one indexed equality probe for 0 and 1 rows (INFERENCE, unmeasured).

---

## 8. Session lifetime (A6)

`auth_cookie_expiration` returns **8 h for patient-only users**, and **`$expiration` unchanged** for everyone else. Predicate: the same `PatientPortalPage::isPatientOnly()` (OBSERVED `:92-111`), so staff, doctor, manager, accountant and admin lifetimes are never shortened. `remember=false` (today `true`, OBSERVED `OtpService.php:595`). **Test:** two users (patient-only and staff), two lifetimes, asserted from the filter's return value and from `Set-Cookie` expiry (§9, T-M2).

---

## 9. Test plan (descriptions only; the mutation that must fail it)

| # | Test | Mutation that must fail it |
|---|---|---|
| T-M1 | **route × state matrix**: 3 states (`none`/`booking`/`full`) × 10 patient routes, exact status per cell | gate any route on the role only → the `none`/`revoked` cells return 200 |
| T-M2 | **8-hour, two users**: patient-only ⇒ 8 h; staff ⇒ unchanged lifetime | drop the `isPatientOnly` predicate → staff lifetime shortens |
| T-M3 | **atomic consume**: two concurrent verifies of one code ⇒ one session | remove `AND consumed_at IS NULL` → two sessions |
| T-M4 | **`register_required`**: unknown mobile ⇒ 202, zero cookies, zero rows created | issue a cookie on the 0-candidate branch → a session exists |
| T-M5 | **custom capability**: a patient-ish role holding any `ALL_CAPS` capability ⇒ no cookie | replace `holds_any_cpms_capability` with a role-name check → cookie issued |
| T-M6 | **revocation kills a live cookie**: valid cookie, then `state='revoked'` ⇒ next request denied | trust a session flag instead of re-reading the row |
| T-M7 | **national ID never in the patient row before verification**: after first booking `cpms_patients.national_id IS NULL`; after approval it equals the login row's value and the login row is NULL | write the ID at booking → column non-NULL |
| T-M8 | **no ID anywhere else**: audit rows, `cpms_operational_logs`, notification payloads, rate-limit keys and every URL contain no 10-digit/Persian-digit ID | log the ID, or key a rate limit on it |
| T-M9 | **cap race**: pending account with cap−1 active appointments, two concurrent confirms ⇒ at most cap | count without `SELECT … FOR UPDATE` on the live login row → cap+1 |
| T-M10 | **expired-pending sweep**: a `pending` row 15 days old ⇒ scrubbed + revoked; a user with an appointment ⇒ not deleted | delete unconditionally → an appointment loses its owner |
| T-M11 | **registration collisions**: mobile already live ⇒ identical 202 **and** `hold_reason` set; nothing created | return a different message, or create a second row |
| T-M12 | **reception verify**: approve ⇒ `active`, ID moved, login row scrubbed; reject ⇒ `revoked`, scrubbed | leave the ID in both places |
| T-M13 | **dependents** (§11): guardian cannot read another family's patient by id; pending guardian cannot read a dependent's records; a dependent row cannot carry the parent's ID; two dependents with the same mobile do not produce an existence-revealing error; the cap counts parent + dependents under concurrency | gate records per account instead of per link |
| T-M14 | **mobile change**: revoke + new row + `destroy_all` ⇒ the old cookie is dead | skip `destroy_all` |

---

## 10. Abuse limits (A10)

| Surface | Limit | Source |
|---|---|---|
| `/otp/request` per mobile | 3/day, 10/hour, 60 s cooldown, 900 s lockout after 5 bad codes | OBSERVED `Settings.php:32-37` |
| `/otp/request` per IP | 10/hour (`otp.ip_hourly_max`) | OBSERVED `Settings.php:40` |
| `/otp/verify` per IP | 20/3600 hard-coded | OBSERVED `OtpService.php:392` |
| `/patient/register` | inherits the OTP quotas (a registration needs a consumed code) **plus** a new per-IP bucket `arreq-register-ip:<sha256(ip)>` and a per-mobile bucket keyed on `sha256(mobile)` — **never** a raw mobile |
| SMS cost | bounded by the same 3/day + 10/hour per mobile and 10/hour per IP; a registration consumes one code, so it cannot amplify cost |

Note the existing debt this design does **not** copy: `OtpService` already writes **raw** mobiles into `cpms_rate_limits` window keys (`:274-275`, `:622-629`), which is exactly why every **new** bucket below is hashed. Every **new** bucket in this design is hashed.

---

## 11. Dependents / guardian accounts (A11, A12, D8)

**(a) Several patients per account.** Already supported: `cpms_patient_user_links` has `UNIQUE (patient_id, wp_user_id)` (OBSERVED `0001:97`) — one user may hold many patients, and `linkedActivePatientsForClinic()` returns **all** of them (OBSERVED `:3057-3063`). The booking path already **refuses to guess** when there is more than one: `>1` without a selection throws `CLINIC_PATIENT_SELECTION_REQUIRED` (OBSERVED `BookingService.php:913-916`), and `patient_id` is an optional request parameter (OBSERVED `BookingController.php:90`). So the booking request must **carry `patient_id`**, and ownership is checked by looking that id up in `linkedActivePatientsForClinic()` for the trusted clinic — the same authority the confirm path already uses. No new mechanism.

**(b) The collision that cannot be solved without schema.** `cpms_patients.mobile` is `VARCHAR(32) NOT NULL` (OBSERVED `0001:60`, inside the `cpms_patients` CREATE at `:54-84`) and `UNIQUE (clinic_id, mobile)` (OBSERVED `:82`). A child sharing the parent's mobile therefore **cannot** have its own patient row in the same clinic: the second insert violates `u_pat_mobile`. Options: reuse the parent's row (**forbidden** — that is a merge, and "never merge with a walk-in patient" is absolute here); invent a fake mobile (**forbidden** — it would corrupt the call-back field); or **relax the constraint**.

> **⛔ OPEN OWNER QUESTION OQ-D1 — STOPPED HERE AS INSTRUCTED.** Storing a dependent in the same clinic as the guardian requires **either** a new column **or** a second table (e.g. a nullable `mobile_override`, or a `cpms_patient_guardians` table). Both are outside the approved one-table scope, so this design **does not invent one**. Until the owner decides, the first release supports dependents **only** where the dependent's own mobile differs from the guardian's (or in a different clinic), and this is stated in the UI as a reception-only path.

**(c) Who creates and who verifies.** A **pending** account may **not** create a dependent. Creation and approval happen **only at reception, in person, with documents** — the same verification act as §6. The guardian relationship is verified there, not asserted online. **The cap of 2 counts parent + dependents together** (DECIDED).

**(d) Records per link, not per account.** Access level becomes a **pair**: account level (`none`/`booking`/`full`, §2) **and** a per-link flag. A verified guardian sees a dependent's records **only** for dependents verified at reception; for every other link the `full` level applies to the account's **own** patient row only. The gate therefore takes `(wp_user_id, patient_id)` and checks the link, not just the account — this is the only change to §2's contract, and T-M13 proves it.

**(e)** No age limit; guardian data stays separate; no auto-link; never merge with an existing walk-in patient.

---

## 12. PR split (author minutes are INFERENCE — no build was performed)

| PR | Content | Minutes |
|---|---|---|
| **P1** | `cpms_patient_logins` migration only (schema **owner-approved** for exactly this table) | 60 |
| **P2** | `PatientAccess::level()` + the ten call sites (A2) | 90 |
| **P3** | login inside the existing transaction + 8 h filter (A6) + audit | 120 |
| **P4** | self-registration + neutral response + collision branches (A4) | 120 |
| **P5** | reception queue, verify/approve, mobile change (A5/A8) | 120 |
| **P6** | first-booking patient creation **without** the national ID (A3) | 90 |
| **P7** | cap `FOR UPDATE` + pending gate (A1/D6) | 60 |
| **P8** | dependents (A11) — **blocked on OQ-D1** | 0 until decided |
| **P9** | **UI/journeys (A9)**: login screen with **both** SMS and password, registration screen, the `register_required` step, and return-to-slot after login. Slot selection already survives a login boundary: it is kept in `sessionStorage` (`cpms-public-booking.js:241`, `:300-318`) and `/booking/resume` takes the hold token (`BookingController.php:115-123`) — UNKNOWN whether the current page flow actually re-enters it; the JS must be verified at build time | 180 |
| **P10** | dependents UI + browser-lane coverage of the new journeys | 120 |

**Realistic total: ≈ 1 900–2 400 author minutes**, not the ~600 in report 2. That earlier figure **ignored review rounds**: this design has already taken three review rounds, and each build PR will take at least one more, so review and rework typically add 40–60 %. The 960 above is implementation only.

---

## 13. Self-audit result (Phase 2, mandatory)

Read as an attacker, hunk by hunk. **Found and fixed:**

1. **A pending user could reach records.** Report 2 made `pending` a *booking* state but never said so normatively, and left `requirePatient` as a role check — so a pending cookie would have reached `/patient/my-records` and `/patient/me` (OBSERVED both guard on the role only, `PatientController.php:233`). **Fixed:** §2 makes the level explicit per route and lists all ten call sites.
2. **A national-ID oracle at booking time.** Report 2 wrote the ID from the login row into `cpms_patients` at first booking; `u_pat_nid` uniqueness (OBSERVED `0001:83`) would then have leaked "this person is already a patient here". **Fixed:** §5 writes `NULL` and the ID only moves at reception verification.
3. **A collision branch that promised contact and created nothing.** **Fixed:** every branch now leaves a queue entry (§4.4-4.5); the queue is the login table, not notifications (with the `NOT NULL clinic_id` and `purgeExpired` reasons stated).
4. **The dependent case was silently impossible.** `cpms_patients.mobile` is `NOT NULL` and unique per clinic, so a child sharing a parent's mobile cannot exist in the same clinic. **Fixed by stopping:** OQ-D1 instead of inventing a column.
5. **A cap race with no lock.** **Fixed:** `SELECT … FOR UPDATE` on the live login row inside the confirm transaction (InnoDB row locks release on COMMIT — dev.mysql.com/doc/refman/8.4/en/innodb-locking.html — unlike `GET_LOCK`, the #354 lesson), T-M9.

**Checked and found clean:** no response distinguishes a mobile or a national ID for a caller who has **not** proved possession (§7); no national ID reaches a URL, log, audit row, rate-limit key or notification payload (T-M8); every claim in §1-§12 carries a file:line or a label; the one contradiction I found — §2 said "per account" while §11(d) needs "per link" — is resolved in §11(d).

---

## 14. Weakest points of this design (self-adversarial)

a) The national ID still sits in **plaintext** in the pending table — same class as the existing clinical column (OBSERVED `0001:61`) but a new place it can leak; scrubbing is a rule, not a mechanism.
b) The 14-day sweep has **no existing host** and no health surface; a stalled sweep is a declared residual, not a solved problem.
c) `u_pl_live_user` + a **STORED generated column** is UNKNOWN on MariaDB 11.4.13; P1 must prove it before anything depends on it.
d) The `:575`→`:595` window is real; the mitigation is per-request re-reading, which is a rule enforced by a test, not by code.
e) **No back-fill** means every existing patient re-verifies in person once — an operational cost I have not sized.
f) Dependents are **blocked** on OQ-D1 and the first release is therefore narrower than the owner's decision; that gap must be visible in the UI, not just here.
g) The cap of 2 is counted per **account**, so a guardian with two verified dependents has one appointment left for all three people.

## 15. Open owner decisions and UNKNOWN

- **OQ-D1 (blocking dependents):** the one-table scope cannot store a dependent sharing the guardian's mobile in the same clinic. Approve a new column **or** a second table, or accept the narrower first release.
- **OQ-D2:** is the 14-day pending scrub acceptable, and should a stalled sweep raise an admin warning (needs a health surface that does not exist yet)?
- **OQ-D3:** on approval, should the record access open for the **whole** account or only the verified link (this document assumes per link, §11(d))?
- **OQ-D4:** must a password account be migrated to SMS login at all, or is "attach SMS login" optional forever?
- **UNKNOWN:** MariaDB behaviour of the generated-column UNIQUE; whether `sessionStorage` survives the real login redirect in the shipped page flow; how many legacy accounts exist; real timing of the candidate probe.
- **NOT RUN:** no PHP, MySQL, WordPress or browser was executed; no CI re-run for this document.
