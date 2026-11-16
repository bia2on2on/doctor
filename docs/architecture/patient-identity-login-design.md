# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 4)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> This is a **proposal for review**. Merging it does **not** authorise a build. The build starts **only
> after** this PR is merged **and** two independent readers have passed it. The **one** table
> `cpms_patient_logins` is **owner-approved** for that later build (no further owner approval for exactly
> that table); **every other schema change and every migration number still needs separate owner
> approval.** No code, migration, test or workflow accompanies this document.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` (merge of #356; fetched 2026-11-16) |
| **Prior head** | revision 3 at `9d3cf117b4a138ab6dd01ed3851fd3b150e9eb6b` — two readers returned BLOCKER |
| **Supersedes** | `docs/architecture/appointment-request-design.md` (absent from this tree; history keeps it) |
| **Depends on** | PR **#340** (merged): `verify()` returns no identity (`OtpService.php:556-573`), no cookie, no account |
| **Labels** | **OBSERVED** = read at the stated commit, not executed · **INFERENCE** = reasoning · **UNKNOWN** = not proven · **DECIDED (owner)** = owner decision · **LIMITATION** = not established |
| **Nothing executed** | No PHP/MySQL/WordPress/browser was run. PHP gates are NOT APPLICABLE (docs-only diff). |

## Revision 4 changes (one line each)

| # | Change |
|---|---|
| **F1** | Route gate completed: **137** `register_rest_route` call sites enumerated and classified, plus server-rendered pages; every patient/shared route has a level with file:line, staff access and per-record ownership stated, and a registry self-check test is required (§2). |
| **F2** | First booking made buildable: `cpms_patients` + link are created **before** the hold inside one short transaction, in the clinic that owns the **persisted slot** (derived at `BookingService.php:519-522`), with a fixed neutral collision message (§5). |
| **F3** | Legacy/password users: every `cpms_patient` user needs a live row; no row ⇒ level `none` with a fixed message; "attach login" ships in the **same PR** as the gate; the false sentence "password login keeps working untouched" is removed (§6). |
| **F4** | Table lifecycle decided: generated column **dropped**, plain `UNIQUE(mobile)` + `UNIQUE(wp_user_id)`, states `pending|active` only, revocation = **DELETE + one mandatory audit row**; schema gate, backups/restores, uninstall and per-clinic visibility specified (§1, §7). |
| **F5** | Cookie only after the **outermost** commit: `CpmsDb::transactional` turns nested calls into savepoints (`CpmsDb.php:272-304`), so the cookie is issued by the REST layer after `depth === 0`; rollback test specified (§3). |
| **F6** | Approval made implementable: three explicit staff cases (own patient row / create via the existing reception path / link an existing walk-in), staff-visible NID collision message, audit rows, sessions reset (§6). |
| **F7** | Wrong citations corrected: reception search/create return **`searchView`/`to_search_view`** (`PatientService.php:245-257, 823-844`; `ReceptionPortalController.php:541-552, 564-580`), and archived internal notifications **are** purged (`NotifDispatchHandler.php:39-41`; `NotificationRepository.php:232-242`) — the real reason to avoid that table is `clinic_id NOT NULL` (§4). |
| **F8** | Rollout order: public registration is registered only in the **last** functional PR; split restated with realistic minutes including review (§12). |
| **F9** | Folded in: a fresh `verify_mobile` code after login (UX, cooldown, SMS cost), and the cap as a **locking current read** with the full writer list and lock order (§9). |

---

## 1. Data model — one table, plain unique keys (D1, F4)

`{wp_prefix}cpms_patient_logins`, InnoDB, `utf8mb4_unicode_ci`.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `wp_user_id` | `BIGINT UNSIGNED NOT NULL` | no FK to `wp_users` (none exists anywhere in this repo: OBSERVED `0001` and `0019`) |
| `mobile` | `CHAR(11) NOT NULL` | normalised `09xxxxxxxxx`, ASCII |
| `state` | `ENUM('pending','active') NOT NULL DEFAULT 'pending'` | **only two states** |
| `given_name`, `family_name` | `VARCHAR(120) NULL` | pre-verification, scrubbed (§7) |
| `national_id` | `VARCHAR(10) NULL` | pre-verification, scrubbed on decision (§6) |
| `hold_reason` | `ENUM(...) NULL` | why an automatic path stopped |
| `verified_by_wp_user_id`, `verified_at` | `BIGINT UNSIGNED NULL`, `DATETIME(3) NULL` | who approved, when |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |

**Uniqueness — plain, no generated column:** `UNIQUE u_pl_mobile (mobile)`, `UNIQUE u_pl_user (wp_user_id)`, plus `KEY (state, created_at)`, `KEY (national_id)`.
No row may exist for a revoked account, because **revocation deletes the row** (§6). The audit log, not this table, holds the history.

**G7 re-justified (what history lives where, and why the delete/insert race is safe):**
- History lives in `cpms_audit_logs` (columns OBSERVED `0001:676-701`: `clinic_id`, `actor_wp_user_id`, `actor_role`, `action`, `resource_type`, `resource_id`, `patient_id`, `ip_hash`, `before_json`, `after_json`, `meta_json`) — one mandatory row per revoke/attach/approve carrying action, actor, user id; **never a mobile and never a national ID**.
- The race is safe because a revoke and a subsequent attach for the same mobile serialise on `u_pl_mobile`: the DELETE and the INSERT run in **one** transaction, so a concurrent registration for that mobile either sees the row (and takes the `hold_reason` branch) or the deleted state (and inserts); InnoDB releases the row lock at COMMIT (dev.mysql.com/doc/refman/8.4/en/innodb-locking.html), and a duplicate key rolls the second one back as a clean, generic error.
- One row per mobile **and** per user is exactly what the *live* contract needs, and `UNIQUE` on two separate single columns is the simplest thing MariaDB and MySQL both guarantee. **INFERENCE (UNKNOWN):** not executed on MariaDB 11.4.13.

## 2. The access gate and the complete route map (F1, A1, A2)

**One function:** `PatientAccess::level(int $wp_user_id): string` → `none` | `booking` | `full`, computed from the live login row on **every** request (never from the cookie): no row ⇒ `none`; `pending` ⇒ `booking`; `active` ⇒ `full`.

**Search method and result (so the enumeration is reproducible):**
`git grep -c register_rest_route origin/main -- clinic-practice-management/src` over 18 REST controllers ⇒ **137 call sites**. Classification by the route's own `permission_callback`: **123 staff**, **7 patient**, **5 public**, **2 authenticated-only**. Plus the server-rendered patient page described below.

| Class | Routes | Level |
|---|---|---|
| **public** (5) | `/availability` `BookingController.php:43`; `/booking/quote` `:64`; `/otp/request` `OtpController.php:33`; `/otp/verify` `:53`; `/health` `HealthController.php:18` | no login row (unchanged) |
| **patient, booking** (7) | `/booking/hold` `BookingController.php:80`; `/booking/confirm` `:96`; `/booking/resume` `:115`; `/appointments/mine` `:126`; `/appointments/{id}/reschedule-options` `:138`; `/appointments/{id}/reschedule` `:156` (`reschedulePermission` `:511-543` accepts the patient role **or** `APPT_RESCHEDULE`); `/appointments/{id}/cancel` `:198` (`cancelPermission` `:544-...`, same shape) | `booking` |
| **patient, full** (7) | `/visits` `ClinicalController.php:167`; `/visits/{id}` `:185`; `/prescriptions` `:199`; `/patients/{patient_id}/files` (GET+POST) `FilesController.php:72-95`; `/notifications` `NotificationsController.php:34`; `/notifications/read` `:51`; `/rt/notifications` `:74` | `full` |
| **mixed** (1) | `/files/{id}/stream` `FilesController.php:60` — `permission_callback` is `permAuthenticated` only (`:64`), authorisation is per resource in `FilesController::stream` `:103-123` → `FileService` | `full` **iff** the caller is a patient actor; staff unchanged |
| **patient account routes** (2) | `/patient/me` (GET/PATCH) `PatientController.php:64-76`; `/patient/my-records` `:52-59`; `/patient/enroll` `:38-43` | `/patient/me` = `booking` (profile only); `my-records` = `full`; `enroll` = staff-only (already gated by `enroll_current_patient`, `PatientService.php:283-300`) |
| **staff** (123) | everything else, incl. the **patient branches of shared staff routes**: `/appointments` `BookingController.php:172` and the staff create path `BookingService::createByStaff()` `:1805-1935`, `/search` `ClinicalController.php:217`, receipt/finance/queue/reception/reports/SMS/admin lanes | **staff authority unchanged** — the gate is additive and returns `none` for a user without a login row, but a staff route never consults it |
| **server-rendered** | `PatientPortalPage::render()` `PatientPortalPage.php:261-265` (`wp_die` 403 unless logged-in + `isPatientOnly()` `:92-111`), menu `:173-187`, `guardWpAdmin()` `:194-...` | `booking`: the page is otherwise reachable by a pending cookie. It renders `listMine` `:279`, `inbox` `:306`, and `linked_records` `:323` (records) ⇒ the page must render **without** the records block when `level === 'booking'` |

**How staff access and per-record ownership survive:**
- **Staff**: the gate is a *patient* gate. Staff routes keep their capability checks (`permCap`) and scoped memberships (`authorizeScoped`, `ClinicalService` `:1909-1924`); a staff user has no login row and is never routed through `PatientAccess`.
- **Per record**: the patient lane already resolves an **owned** patient and then filters by it — `ClinicalController::patient()` `:297-323` (role + nonce), then `ClinicalService::patientVisits` `:1382-1409` → `selected_visit_patient` `:1883-1890` → `PatientService::require_selected_patient` `:219-238` (a `link_id` that is not the caller's active link ⇒ `CLINIC_SELECTION_REQUIRED`/not-found; several links ⇒ 422). `FilesController` `:72-95` uses the same selector. **The gate adds an account-level precondition; the link selector remains the record-level authority.** Both must pass.
- **Dependents** therefore need **one** extra check (§11) — per-link, not per-account.

**Registry self-check test (required):** a unit test enumerates every `register_rest_route` call site from the source (same regex the enumeration uses), asserts the count equals the registry constant, and asserts each route has an entry in the level table. Adding a route without a level **fails the test**, and a route whose registered path is not in the registry fails too.

## 3. Login, the transaction, and when the cookie may be issued (F5, G9)

1. Normalise; rate-limit **before** any branch; verify + atomic consume unchanged (`OtpService.php:527-534`, `AND consumed_at IS NULL`, affected rows must be 1).
2. Candidate read and eligibility run **inside the existing transaction** (`transactional(` opens `:449`, closure ends `:575`).
3. **F5 — the cookie is not issued inside `verify()` at all.** `CpmsDb::transactional` converts a nested call into `SAVEPOINT` (`CpmsDb.php:272-304`: `tx_depth > 0` ⇒ savepoint, `:279-285`), so a successful `verify()` proves only "this level committed or was released to the parent". The design issues the cookie in the **REST layer**, from a code path that owns the outermost transaction: `OtpController::verifyCode` (`:135-163`) reads `PatientAccess::level()` after `verify()` returns and only then calls `wp_set_auth_cookie($user_id, false)`. If any future caller wraps `verify()` in an outer transaction, the cookie is still outside it because it is minted by the controller, after the call. **Test:** wrap `verify()` in an outer `transactional()` that throws after it ⇒ assert **no** `Set-Cookie` and `state` unchanged.
4. Candidates: `SELECT wp_user_id, state FROM cpms_patient_logins WHERE mobile = %s` — 0 or 1 row by `u_pl_mobile`. **0 rows + valid code** ⇒ `202 register_required`, **no cookie** (§4). **1 row** ⇒ eligibility with the existing predicate `PatientPortalPage::isPatientOnly()` `:92-111` + `holds_any_cpms_capability()` `:116-124`; ineligible ⇒ the generic invalid-code envelope.
5. Cookie: `remember = false` (today `true`, `OtpService.php:595`); lifetime 8 h via `auth_cookie_expiration`, **patient-only users only** (predicate above), staff lifetimes untouched; two-user test.

## 4. Self-registration, queue, and why not `cpms_notifications` (A4, F7)

`POST /patient/register {mobile, code, given_name, family_name, national_id}` — registered **only** in the last functional PR (§12).

1. Consume the OTP exactly as login; `verify_mobile` is already an allowed purpose (`OtpService.php:39`) ⇒ no schema change.
2. **F9 — a fresh code, not the login code.** A registration needs a `verify_mobile` code issued **after** the login code was consumed: the UI asks for one more code, the existing 60 s cooldown (`Settings.php:34`) makes it a short wait, and the cost is bounded by the same daily/hourly per-mobile limits (`:35-37`) and the per-IP limit (`:40`). Reusing a consumed code is impossible by design (single-use consume) and re-opening it would weaken the consume contract.
3. Create **exactly two rows**: one WP user (role `cpms_patient`, random login, unusable password — pattern at `ManagementPortalService.php:1551-1558`) and one `pending` login row. **No patient row, no link** — the clinic is unknown, `cpms_appointments.patient_id` is `NOT NULL` (`0001:226`).
4. **Collisions:** only one can exist — the mobile is live ⇒ create nothing, set `hold_reason` on the existing row. Per-clinic `u_pat_mobile`/`u_pat_nid` (`0001:82-83`) **cannot** be evaluated without a clinic and are handled at first booking (§5) and at approval (§6).
5. **Neutral answer:** every branch returns the identical `202 "request received; reception will contact you"` and every branch leaves a queue entry.
6. **Queue = the login table** (`state='pending'` or `hold_reason`). **Not `cpms_notifications`**, and the reason is **`clinic_id NOT NULL`** (`0001:634`) — there is no clinic yet. Correction to revision 3: archived internal rows **are** purged with `channel='internal'` and `status IN ('sent','delivered')` (`NotifDispatchHandler.php:39-41` → `NotificationRepository::purgeArchived` `:232-242`), and `ExportService` reads payloads only for `REPORT_EXPORT_READY` (`:377-379`); so "nothing would ever purge them" was wrong and is withdrawn.

## 5. First booking — buildable, in the clinic that owns the slot (F2)

`hold()` rejects a zero-link caller **before** a hold exists: the trusted clinic is asserted at `BookingService.php:556-557`, then `resolveBookingPatientForHold` `:3097-3122` returns `null` for 0 links and the mobile lookup `:566` → `mobile_for_user_in_clinic` `:3014-3038` returns `null` ⇒ `CLINIC_VALIDATION_FAILED` (`:567-569`); confirm likewise fails closed at `:898-910`. **So the patient row must exist before the hold.**

**Trusted clinic (DECIDED, not re-debated):** the clinic that **owns the requested slot**. It is derived server-side at `:519-522`: `requireClinician($clinician_id)` reads `cpms_clinicians.clinic_id` (`:2898-2909`), then `clinic_for_persisted_slot` `:2848-2882` reads `cpms_schedule_slots.clinic_id` and, on a cross-clinic slot, additionally requires the slot's own `clinician_id` to match **and** an active durable participation (`memberships->clinician_participates_in`). A body `clinic_id` is never read (`:515-518` states that contract). **It cannot be forged** because every input to that derivation is a persisted row read by id, and every mismatch throws the same non-enumerating 404.

**The one short transaction, before the hold:** inside `hold()`, after the trusted clinic and slot are resolved and before the capacity claim:

1. `SELECT … FROM cpms_patient_logins WHERE wp_user_id = %d FOR UPDATE` — the cap and lifecycle lock (§9).
2. If a link for (user, clinic) already exists ⇒ **do nothing** (idempotency: a second hold must not create a second patient).
3. Else `INSERT cpms_patients (clinic_id = trusted, mobile = login.mobile, first_name/family_name = login names, national_id = NULL)` and `INSERT cpms_patient_user_links (clinic_id, patient_id, wp_user_id, mobile_at_link)` — the `national_id` column is nullable (`0001:61`) so no national ID leaves the login row here (§6).
4. **Collision on `u_pat_mobile(clinic_id, mobile)`** (`0001:82`, an existing walk-in patient — created by reception, `ReceptionPortalController.php:183-188` → `PatientService::create` `:370-...`): insert nothing, link nothing, set `hold_reason='clinic_mobile_exists'` on the login row, and return the fixed generic message **"online booking is not possible, please contact reception"**; reception gets a visible flag on the pending queue (§6). The `u_pat_nid` collision cannot occur here because the column is written as `NULL`.
5. Then the hold proceeds exactly as today (`:571-...`).

**Why this reveals nothing without possession:** the caller must already hold a session created from an OTP that consumed a code sent to *that* mobile (the consume is single-use and audited). The message is identical whether the colliding walk-in patient exists for this clinic or any other reason failed, it never names or counts a patient, and no id, mrn or clinic is returned. Someone who has not proved possession of the mobile cannot reach step 4 at all — they cannot obtain the session that `requirePatient` demands, and `hold` is not public.

**Interaction with the cap:** the patient/link creation happens **before** the capacity claim and **before** the cap check, and it is not a terminal state: a caller who then fails the cap still owns the row, which is correct (they are a patient of that clinic) and idempotent for the next attempt.

## 6. Reception: pending queue, approval, attach, revocation (F3, F4, F6, F7)

**Who sees the pending queue (DECIDED):** only the clinic where the account already has a `cpms_patients` row — i.e. **after** first booking. Pending registrants with no patient row are **invisible to every clinic** until first booking and are scrubbed at 14 days (§7). `national_id` is shown only in that clinic's patient context.

**Output change needed (F7):** reception search and create return `searchView` / `to_search_view` (`PatientService.php:245-257`, `:823-844`; called at `ReceptionPortalController.php:544` and `:576`), **not** `staffView` (`:801`). `searchView` currently returns id, mrn, names, mobile, masked national id (`NationalIdValidator::mask` `:839`), birth date, gender, status — **there is no unverified flag**. The change: add `login_state` (from the login row; `pending` ⇒ label) to `searchView`, and nothing else (no NID, no mobile of anyone else).

**Approval — three explicit cases (in person, documents checked):**
- **(i) the account already has a patient row in this clinic** (from first booking): write the checked `national_id` to that patient row under the existing uniqueness rule (`assert_unique_national_id` `:850-857`), set `state='active'`, `verified_by_wp_user_id`/`verified_at`, clear `hold_reason`; the login row's `national_id` is **scrubbed to NULL** in the same transaction.
- **(ii) no patient row yet:** reception creates the patient through the **existing** reception creation path (`ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`, which enforces the scoped secretary authority and license gate), with the document-checked `national_id`, then links the account (one row in `cpms_patient_user_links`, `u_link_pair` `0001:97`), then `state='active'`.
- **(iii) a walk-in patient already exists with the same mobile:** reception **explicitly picks** that patient and links the account to it — staff-initiated, audited, **never automatic, never merging rows**; `national_id` is written only if the document matches (otherwise the walk-in row is left untouched and the case is escalated).

**National-ID collision message for staff:** staff are authenticated and are checking a document, so they **may** see existence: `CLINIC_VALIDATION_FAILED` with the product message already used at `:855` ("this national ID belongs to another patient"), plus the existing patient's masked view for the operator. That message is **never** returned to a patient session.

**Audit + sessions:** one audit row per approval/attach/link (`action`, `actor_wp_user_id`, target user id, `patient_id`, `before_json`/`after_json` without mobile/NID), and `WP_Session_Tokens::destroy_all()` for the user whenever state, mobile or link changes, so a stale cookie cannot outlive the decision.

**Legacy / password users (F3):** the plugin is unreleased, so no real legacy users exist. Every `cpms_patient`-role user needs a live row; a password account **without** a row has level `none`. Password login still **authenticates**, but the access level comes from the login row (the sentence "password login keeps working untouched" is removed — it was wrong). A no-row password patient sees a fixed message and **no data** ("your account needs reception activation"). Reception's **"attach SMS login"** action — one transaction: insert the row `active` (in person), `destroy_all()` sessions, one audit row — ships in the **same PR** as the gate, so nobody is locked out by rollout.
**Revocation = DELETE** of the row plus one mandatory audit row, in one transaction; the account falls to level `none` immediately.

## 7. Lifecycle: schema gate, backups, restores, uninstall (F4/A5)

- **(a) Missing table ⇒ fail closed.** The runtime gate `App::is_runtime_blocked_by_pending_schema()` (`App.php:622-639`) already blocks on pending migrations; `RuntimeSchemaGate::is_blocked()` is checked by the patient portal (`PatientPortalPage.php:267-273`). This design adds the table to the same migration set, so a missing table is a **pending migration** and the gate blocks; the login route itself must also treat a missing table as `503` with the neutral message — never "unknown mobile".
- **(b) Backups dump it automatically.** `BackupSqlDumper::tables()` enumerates `{prefix}cpms_%` (`BackupSqlDumper.php:113-126`) and dumps every column (`:146-156`, `:178-197`), so pending **plaintext national IDs and names appear in every backup file**. Consequence stated plainly: backups are PHI-bearing artefacts with the same retention/access rules as the database (the existing backup store and its access path), and the 14-day scrub only shrinks the window for **live** rows, not for old dumps.
- **(b) Restoring an older backup** can resurrect a row that was revoked, or resurrect a `pending` row with an NID. That is why **after any restore, all patient sessions are invalidated and every patient must log in again**: the restore path (`BackupService::restoreApply` `:324-363`) drops and re-creates every `cpms_*` table inside one transaction and **cannot** be intercepted by this design, so the requirement is stated as a **restore-runbook step plus a post-restore job**: on the first request after a restore (detected from a restore marker written by the restore job), `WP_Session_Tokens::destroy_all()` is called for all users holding `cpms_patient`. **Interrupted restore:** the drop+recreate runs in one `transactional()` (`:348-363`) — LIMITATION: DDL is not transactional in MySQL/MariaDB, so an interruption can leave the schema partially restored; the response is the same fail-closed gate (a) plus the marker, and a re-run of the restore.
- **(c) Uninstall.** `uninstall.php:8-18` deletes nothing unless `cpms_wipe_on_uninstall` is set; when set, it drops **every** `{prefix}cpms_%` table (`:14-18`). The new table falls under that prefix, so the statement is explicit: **wipe-on-uninstall destroys login rows, and their national IDs, with no separate consent**, and **without** the option the table is retained like every other CPMS table. **Test:** uninstall with the option off retains the table; with it on, drops it.
- **(d) One installation-wide table, per-clinic staff (DECIDED):** the login table is **installation-wide** (it has no `clinic_id`), so a clinic can never query it directly; only the reception of a clinic where the account has a patient row sees the pending entry, and `national_id` is only ever read through that clinic's patient context. Cross-clinic existence is not exposed.

## 8. Oracle and timing (A1)

| Mobile situation | `/otp/request` | `/otp/verify` (valid code) |
|---|---|---|
| unknown | send, 200 | `202 register_required`, **no cookie** |
| live `pending`/`active`, eligible | send, 200 | 200 + `Set-Cookie` |
| no row (revoked ⇒ row deleted) | send, 200 | `202 register_required`, no cookie |
| staff / ineligible account | send, 200 | generic invalid-code envelope, no cookie |
| wrong code | send, 200 | generic invalid-code envelope |

`register_required` is distinguishable from success — **only to a caller who already proved possession of that mobile** (owner-permitted). Ambiguous, staff-colliding and ineligible cases stay generic and never set a cookie.

## 9. Cap and concurrency (F9, A1, D6)

**Cap = 2 active appointments in total per account** (DECIDED). Counted across all of the account's patients and clinics.

- **Current read under the lock:** in the confirm transaction, (1) `SELECT … FROM cpms_patient_logins WHERE wp_user_id=%d FOR UPDATE` — one lock per account, taken **first**; (2) then `SELECT COUNT(*) FROM cpms_appointments WHERE wp_user_id=%d AND status IN ('pending','confirmed')`. Under InnoDB REPEATABLE READ, a locking read (`FOR UPDATE`) reads the **latest committed** version, not the transaction snapshot (dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html ⇒ INFERENCE, unexecuted), and the lock serialises every writer for that user for the rest of the transaction.
- **Lock order:** login row → Organization → Clinic → Slot → Location, matching the canonical order documented at `BookingService.php:1869-1886` and `2750-2790`; the login row is taken before `lock_booking_tenant_prefix` so it can never invert the existing order.
- **Every other writer of an appointment for that account** (OBSERVED): patient confirm `BookingService.php:1015-1018`; patient reschedule (creates a replacement) `:1720`; **staff** booking `createByStaff` `:1805-1935` (the staff path writes `wp_user_id` from the patient's row, `:1716-1718`); the appointment repository `AppointmentRepository::create` `:77-110` is the single INSERT point, so any new writer must go through the same lock to satisfy the cap. Cancel/no-show reduce the count, so they need no lock for correctness. **LIMITATION:** a *staff* booking that links a patient to an account with no login row is out of the cap's scope by definition (no row ⇒ level `none`), and is stated here as a known boundary rather than hidden.

## 10. Abuse limits (A10, F9, F7)

`Settings::DEFAULTS` (`Settings.php:28-40`) is a set of **configurable defaults, not ceilings** — they are read through `Settings::get()` (`:207`) and can be overridden per installation, so this section states *where* the numbers come from, not that they are maxima. Per mobile: 3/day, 10/hour, 60 s cooldown, 900 s lockout after 5 bad codes; per IP for `/otp/request`: 10/hour; `/otp/verify` per IP: 20/3600 hard-coded (`OtpService.php:392`). `/patient/register` needs a consumed OTP **plus** new buckets keyed on `sha256(ip)` and `sha256(mobile)` — **never a raw mobile**. Existing debt not to copy: `OtpService` already keys `otp-day:`/`otp-hour:` on the **raw** mobile (`:274-275`, `:622-629`).

## 11. Dependents / guardian accounts (A11, A12, D8) — LAST, still blocked

**(a) Several patients per account** already work: `UNIQUE (patient_id, wp_user_id)` (`0001:97`), and `linkedActivePatientsForClinic()` returns **all** of them (`BookingService.php:3057-3063`). With more than one, the hold already **refuses to guess** (`:3121`) and `patient_id` is an optional request parameter (`BookingController.php:90`), validated against the caller's links (`assert_requested_patient_owned_by_caller` `:3131-3153`). The request must carry `patient_id`; ownership is that same lookup.

**(b) The blocker is unchanged.** `cpms_patients.mobile` is `VARCHAR(32) NOT NULL` (`0001:60`) with `UNIQUE (clinic_id, mobile)` (`0001:82`), so a child sharing the guardian's mobile **cannot** have a row in the same clinic. Reusing the guardian's row is a merge (forbidden); a fake mobile corrupts the call-back field (forbidden).

> **⛔ OPEN OWNER QUESTION OQ-D1 — no column or table is invented here.** Solving this needs a new column **or** a second table, i.e. outside the approved one-table scope. Until the owner decides: dependents are supported **only** where the dependent's own mobile differs (or a different clinic), and that narrower scope is visible in the UI.

**(c)–(e):** creation and approval happen **only at reception, in person, with documents** (same act as §6); a `pending` account may **not** create a dependent; the cap of 2 counts parent + dependents together; **per-link** access in the gate (a `full` account sees a dependent's records only for links verified at reception); no age limit; guardian data separate; no auto-link; never merged with a walk-in patient.

**(f) Dependent negative tests (A12):** IDOR by patient id; pending guardian cannot read a dependent's record; a dependent row cannot take the parent's NID; two dependents with the same mobile do not leak existence; the cap under concurrency across dependents.

## 12. Rollout order and PR split (F8, A9)

**Rule:** the public `/patient/register` route is registered **only in the last functional PR**, after the gate covers **all** routes, the cap, "attach login" and the first-booking path exist. No intermediate PR may expose a path that bypasses the cap or the gate.

| PR | Content | Minutes |
|---|---|---|
| **P1** | migration: `cpms_patient_logins` (approved table) + registry test skeleton | 60 |
| **P2** | `PatientAccess::level()` + the gate on **every** patient/shared route (§2) + route×state matrix + registry self-check | 120 |
| **P3** | login: candidate read inside the existing transaction, cookie issued by the REST layer, 8 h patient-only filter | 120 |
| **P4** | **"attach login"** + revocation/audit + reception `searchView` flag (needed *before* the gate is reachable in production) | 90 |
| **P5** | first-booking patient+link creation, collision message, cap `FOR UPDATE` | 120 |
| **P6** | **registration** (`/patient/register` registered here, last) + fresh-code UX + neutral answer | 120 |
| **P7** | reception approval cases (i)(ii)(iii) + sessions reset | 90 |
| **P8** | UI journeys: login screen with SMS **and** password, registration, `register_required` step, return to the selected slot (selection already crosses login in `sessionStorage`, `cpms-public-booking.js:241,300-318`; `/booking/resume` `BookingController.php:115-123`) | 180 |
| **P9** | dependents — **blocked on OQ-D1** | 0 until decided |
| **P10** | browser-lane coverage of the new journeys | 120 |

**Realistic total ≈ 2 300–2 900 author minutes including review rounds**, not the 960 of implementation-only arithmetic and not the ~600 claimed in report 2 (which ignored rounds). This document alone has had four review rounds; each build PR should be costed with at least one.

## 13. Self-audit result (attacker's eye, this revision)

1. **Pending/no-row/revoked user reaching records through ANY route.** Revision 3 gated 10 routes and missed `/visits`, `/visits/{id}`, `/prescriptions`, `/patients/{id}/files`, `/files/{id}/stream`, the three notification routes and the server-rendered page. **Fixed:** all 137 call sites classified, 14 patient/shared routes and 1 page enumerated, each with a level; the registry self-check fails if a new route has no level. The server-rendered page is the subtlest one — it renders `linked_records` at `PatientPortalPage.php:323`, so `booking` level must skip that block, not merely allow the page.
2. **Mobile/NID existence for someone without possession.** Fixed by construction for the NID (`NULL` at first booking, §5) and by a uniform message for the mobile collision (§5). Residual: `register_required` for a mobile that has **no** row is still an answer to a possession-proof caller only.
3. **NID in URL/log/audit/rate-limit key/backup.** No URL carries it; audit rows carry `patient_id` and `meta_json` without it (T-M8); rate-limit keys are hashes; **backups do carry it** and that is now stated as a declared consequence (§7b) instead of being implied clean.
4. **Unbuildable steps.** First booking (§5) and approval (§6) were the two that could not be built as written; both now name the exact call sites and the exact insert order.
5. **Contradictions.** "Password login keeps working untouched" contradicted the gate — removed. "Nothing purges notifications" contradicted `NotifDispatchHandler.php:39-41` — withdrawn, with the correct reason recorded. §2 "per account" vs §11 "per link" — resolved (§11c-e).
6. **Claims without file:line.** Every claim in §1-§12 carries a path:line or an explicit UNKNOWN/INFERENCE. Two remain UNKNOWN by nature: MariaDB behaviour of the plain unique indexes (`§1`) and whether InnoDB's locking read sees the latest commit on MariaDB 11.4.13 (`§9`).

## 14. Weakest points

a) Pending national IDs and names sit in **plaintext**, and now also in **every backup** — the 14-day scrub only limits the live window.
b) **Restores cannot be hooked**: the invalidation is a runbook step plus a post-restore job, not an enforced invariant; and an interrupted restore can leave a partial schema (DDL is not transactional).
c) The 14-day sweep still has **no existing host** (the `cleanup.*` jobs are `App.php:2625-2628`, each bound to its own table; `OtpCleanupHandler.php:22-25` touches only `cpms_otp_tokens`), so it is a new `cleanup.patient_logins` job and a stalled sweep is a residual with no health surface.
d) The registry self-check is only as good as its regex; a route registered dynamically outside `register_rest_route` would escape it (UNKNOWN that none exists — the enumeration found none).
e) The cap counts per **account**, so a guardian with two dependents leaves one slot for three people; and a staff booking for a patient with no login row is outside the cap by construction (§9).
f) Dependents remain **blocked** on OQ-D1; the shipped scope is narrower than the owner's decision.

## 15. Open owner decisions

- **OQ-D1 (blocking dependents):** new column **or** second table, or accept the narrower first release.
- **OQ-D2:** accept the 14-day pending scrub, and should a stalled sweep raise an admin warning (needs a health surface that does not exist yet)?
- **OQ-D3:** after approval, record access for the **whole** account or only the verified link (assumed per link, §11).
- **OQ-D4:** must a password account be migrated at all, or is "attach login" optional forever (it is mandatory until then, §6)?
- **OQ-D5:** backups containing pending national IDs — same retention as clinical data, or a shorter backup retention for this table (a product decision, not a schema one)?
