# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 5, final)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> A proposal for review. Merging does **not** authorise a build; the build starts only after this PR is
> merged and two independent readers pass it. The one table `cpms_patient_logins` is **owner-approved**
> for that later build (including the nullable column added in R3), and **any other new table or column
> needs separate owner approval — stop and ask.** No code, migration, test or workflow here.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` |
| **Prior head** | rev 4 `a7bc23edaa0482db6225512c4d3373a6c0df1a62` — two readers, 8 findings, BLOCKER |
| **Supersedes** | `docs/architecture/appointment-request-design.md` (absent; history keeps it) |
| **Labels** | **OBSERVED** read in code at the stated commit, not executed · **INFERENCE** · **UNKNOWN** · **DECIDED** owner/manager decision · **DEBT** existing, untouched |
| **Not executed** | No PHP/MySQL/WordPress/browser run; PHP gates NOT APPLICABLE (docs-only). |

## Revision 5 changes (R1–R10)

| # | Change |
|---|---|
| **R1** | Pending users get a **minimal login-row self view** only; `me`, `updateMe`, `linked_records` and the inbox are level-gated **in the service layer** (§2.4). |
| **R2** | `/patient/enroll` reclassified `full`; routes counted **by actor branch**, not by file; recount = **18 sites / 20 runtime routes** reachable by a patient-only account (§2.2-2.3); self-check must enumerate the **runtime registry**, not source regex; non-REST surfaces enumerated (§2.5). |
| **R3** | Complete atomic first-booking creation: every mandatory column, MRN generator, identity activation, link `linked_at`, placed **before** the scope check; collision recorded in the approved table via `collision_clinic_id` (§5). |
| **R4** | **Prerequisite PR**: `PATIENT_CREATED` audit payload redacted to ids and closed vocabulary, because `staffView` currently writes raw names + NID (§6.1). |
| **R5** | Cap is a **locking current read in an outermost transaction** with a depth guard; staff bookings explicitly exempt; the rev-4 `:1716-1718` attribution is corrected (§9). |
| **R6** | Restore safety no longer relies on `App.php`; table existence is checked directly, and a **persistent restore barrier option** fails closed across an interrupted restore (§7). |
| **R7** | Cookie issuance requires **transaction depth 0** via a new `CpmsDb` accessor; the flat "60 s cooldown" claim is corrected to purpose-specific policy state (§3). |
| **R8** | PR sequence rebuilt so **no pending row can exist** before gate + cap + first booking + approval + attach (§12). |
| **R9** | Full state matrix re-derived from the recount (§13). |
| **R10** | Every file:line re-verified; overstated claims deleted, not explained (§14). |

---

## 1. Data model (D1)

One table, `{wp_prefix}cpms_patient_logins` (`utf8mb4_unicode_ci`):

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `wp_user_id` | `BIGINT UNSIGNED NOT NULL` | no FK (none to `wp_users` exists anywhere) |
| `mobile` | `CHAR(11) NOT NULL` | normalised `09xxxxxxxxx` |
| `state` | `ENUM('pending','active') NOT NULL DEFAULT 'pending'` | two states only |
| `given_name`, `family_name` | `VARCHAR(120) NULL` | scrubbed on decision / at 14 d |
| `national_id` | `VARCHAR(10) NULL` | scrubbed on decision / at 14 d |
| `hold_reason` | `ENUM('mobile_reuse','clinic_mobile_exists','nid_exists','other') NULL` | why an automatic path stopped |
| `collision_clinic_id` | `BIGINT UNSIGNED NULL` | **R3**: which clinic held the colliding walk-in mobile |
| `verified_by_wp_user_id`, `verified_at` | `BIGINT UNSIGNED NULL`, `DATETIME(3) NULL` | who approved, when |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |

`UNIQUE u_pl_mobile (mobile)`, `UNIQUE u_pl_user (wp_user_id)`, `KEY (state, created_at)`, `KEY (national_id)`, `KEY (collision_clinic_id)`.
Revocation = **DELETE + one mandatory audit row** in one transaction (history lives in `cpms_audit_logs`: `clinic_id`, `actor_wp_user_id`, `actor_role`, `action`, `patient_id`, `ip_hash`, `before_json`/`after_json`/`meta_json`, `0001:676-701`). The delete/insert race serialises on `u_pl_mobile` inside one transaction; InnoDB releases row locks at COMMIT (dev.mysql.com/doc/refman/8.4/en/innodb-locking.html). **UNKNOWN:** not executed on MariaDB 11.4.13.

## 2. The gate, the recount, and the non-REST surfaces (R1, R2)

### 2.1 One gate
`PatientAccess::level(int $wp_user_id): string` → `none` | `booking` | `full`, from the live row on **every** request, never from a cookie. No row ⇒ `none`; `pending` ⇒ `booking`; `active` ⇒ `full`.

### 2.2 My recount (not revision 4's numbers)
Method — `git ls-tree` the 18 files under `clinic-practice-management/src/Rest`, then for each of the **137** `register_rest_route` call sites parse the **whole registration block** (brace/paren balanced) and resolve every `permission_callback`'s callee to its method body: `requirePatient`, `permPublic`, `permAuthenticated`, `permAnyRole`, `permCap`, `reschedulePermission` (`BookingController.php:511-543`), `cancelPermission` (`:544-...`).

| Class | Sites | Runtime routes |
|---|---|---|
| Public | 5 | 5 |
| **Patient-reachable, patient-only callbacks** | **12** | **14** |
| **Patient-reachable, shared with staff roles** | **5** | **5** |
| Authenticated at callback, staff-authorised downstream | 2 (`FilesController.php:60`; `FinanceController.php:222`) | 2 |
| Staff | 113 | 123 |
| **Total** | **137** | **153** |

Two loop sites expand 1 → 4 routes each: `DoctorPortalController.php:82-91` and `QueueController.php:217-225`; `PatientController.php:64-93` expands 1 → 2 (GET + PATCH); `FilesController.php:72-95` expands 1 → 2. **My numbers: 18 patient-reachable sites / 20 runtime routes** (14 patient-only + 5 shared + `FilesController.php:60`, staff-authorised by `MedicalFileService` `:219-250` where `$isPatient` is decided from the durable membership `:229-230` and then the identity-aware link `:240-243`). Both reviewers counted "about 18"; my reconstruction gives the same order and I state the exact split above rather than reproducing their label counts.

### 2.3 Role-branch rule (R2)
A user holding **any** staff role is not patient-only, and is **never** eligible for patient SMS login (`isPatientOnly()` `PatientPortalPage.php:92-111` plus `holds_any_cpms_capability()` `:116-124`). On a shared route the patient branch evaluates the same gate (no login row ⇒ `none`), so a mixed user gets staff authority or nothing — no bypass. Routes with **two branches** are gated per branch: `NotificationsController.php:34,51,74` (patient branch resolves the patient inbox; staff branch `App::scope()->clinicId`), `BookingController.php:156,198` (patient role **or** `APPT_RESCHEDULE`/`APPT_CANCEL`), `FilesController.php:72-95` (patient branch vs staff `permCap`), `ClinicalController.php:167-212` (patient-only callbacks, ownership via `require_selected_patient`).

### 2.4 Levels per route/branch

| Level | Routes/branches |
|---|---|
| `booking` | `/booking/hold` `BookingController.php:80`; `/booking/confirm` `:96`; `/booking/resume` `:115`; `/appointments/mine` `:126`; `/appointments/{id}/reschedule-options` `:138`; `/appointments/{id}/reschedule` (patient branch) `:156`; `/appointments/{id}/cancel` (patient branch) `:198`; `PatientPortalPage::render()` `:261-265` |
| `full` | `/visits` `ClinicalController.php:167`; `/visits/{id}` `:185`; `/prescriptions` `:199`; `/patients/{patient_id}/files` (GET+POST) `FilesController.php:72-95`; `/files/{id}/stream` patient branch `:60`; **`/patient/enroll`** `PatientController.php:38-49` (**reclassified — R2**); `/patient/my-records` `:52-62`; `/notifications`+`/notifications/read`+`/rt/notifications` patient branches `NotificationsController.php:34,51,74` |
| `booking`, **minimal view** | `GET /patient/me` `PatientController.php:64-72` returns **only** `{given_name, family_name, state}` from the login row — no patient row, no NID, no MRN; `PATCH /patient/me` `:73-92` is **rejected** (403) for `booking` |
| unchanged | `/appointments` staff branch `BookingController.php:172`; `/patient/enroll`'s staff semantics; every staff route |

**Why `booking` is minimal (R1).** `PatientService::me()` `:128-130` returns `publicView()` which exposes the **raw** `national_id` (`:787`) and `updateMe()` `:139-152` can **edit** it; `PatientController.php:73-92` declares `national_id` as an editable argument; and `PatientPortalPage` renders `notifications_section($inbox,...)` `:399`, `profile_section` `:411`, `visits_section` `:412`, `prescriptions_section` `:413`, `files_section` `:414` plus `linked_records()` `:323`. So a pending cookie on the old design saw the full profile and wrote its NID. **Decision: the check lives in the service layer, not in the controllers**, so a direct service call is covered too:

| Service entry point | `booking` behaviour |
|---|---|
| `PatientService::me()` `:128` | return `LoginSelfView::minimal()` (login row only) |
| `PatientService::updateMe()` `:139` | throw `CLINIC_PERMISSION_DENIED` 403 |
| `PatientService::linked_records()` `:99-112` | return `[]` |
| `PatientService::my_records` path (`PatientController.php:52-62`) | `[]` |
| `NotificationService::inbox()` `:155-184` | return empty envelope for `booking` |
| `NotificationService::since()` `:191-...` | return empty envelope |
| `PatientPortalPage::render()` | render appointments section only |

Test: one output assertion per method per level (`none`/`booking`/`full`). **DEBT (not touched):** `active` users can still self-edit `national_id` and receive it unmasked in `publicView` — recorded here, scheduled with the later identity batch, not changed by this design.

### 2.5 Non-REST surfaces
| Surface | Search term | Count | Verdict |
|---|---|---|---|
| `admin_post_*` handlers | `git grep -o "admin_post_[a-z_]*"` over `src` | 51 occurrences / 46 distinct names | staff pages; each handler must assert a capability — the registry test lists them and the build PR proves each is staff-gated or unreachable for `cpms_patient` |
| `wp_ajax_*` handlers | `git grep -c "wp_ajax_"` | 0 | none exist |
| shortcodes | `add_shortcode` | 1 (`PublicBookingShortcode.php:219`) | public by design; patient branch reads `get_current_user_id()` `:1171-1189` — no level gate needed, it books as a public visitor |
| `template_redirect` | `git grep -n template_redirect` | `RuntimeSchemaGate.php:42` (portal guard), `DoctorPortalShell.php:30-31` (staff) | portal guard must consult the level; staff shell unchanged |

**Registry self-check (R2):** enumerate `rest_get_server()->get_routes()` at runtime, filter by namespace, and assert every route has an entry in the level registry; the test fails on an unlisted route and on a listed route that no longer exists. A source regex cannot see loop-expanded or dynamically registered routes — this is why the runtime registry is required.

## 3. Login, transaction depth, and the cookie (R7)

1. Normalise; limits before any branch; verify + atomic consume unchanged (`OtpService.php:527-534`).
2. Candidate read and eligibility inside the existing transaction (`transactional(` `:449` → closure end `:575`); candidate `SELECT … WHERE mobile = %s` — 0 or 1 row by `u_pl_mobile`.
3. 0 rows + valid code ⇒ `202 register_required`, no cookie. 1 row ⇒ eligibility by `isPatientOnly()` `:92-111`; ineligible ⇒ generic invalid-code envelope.
4. **Cookie placement (R7).** `CpmsDb::transactional` uses `SAVEPOINT` when `tx_depth > 0` (`:279-285`) and `RELEASE SAVEPOINT` on success (`:300-304`), so returning from `verify()` proves **released**, not **committed**. The design adds a public read-only depth accessor to `CpmsDb` (build PR; `tx_depth` already exists as a private field `:31`) and the issuing layer does: `if (db->transaction_depth() !== 0) { audit; throw neutral 503 }` before `wp_set_auth_cookie($user_id, false)`. **Fail closed, no cookie.** Test: an outer `transactional()` that throws **after** the controller returns ⇒ no `Set-Cookie`, state unchanged.
5. Cookie: `remember=false` (today `true`, `OtpService.php:595`); 8 h via `auth_cookie_expiration` for `isPatientOnly()` users only; two-user test.

**Fresh `verify_mobile` code after a consumed login code (R7 correction).** The claim "60 s cooldown" was wrong. What actually exists: `OtpService::request()` takes a **per-mobile issuance guard** row (`:200-211`, a hashed key), then reads the **purpose-scoped** latest state `loadState($mobile, $purpose)` (`:232`, `:658-672`) and applies `OtpPolicy::canSend($state, $now)` (`:234`), whose denial reasons include cooldown/lockout/daily/hourly (`:235-245`). Because the state lookup is keyed by **(mobile, purpose)** (`:662-665`), requesting a fresh `verify_mobile` code does **not** inherit the `login` purpose's last-send time — the cooldown for it starts from the **most recent `verify_mobile` send for that mobile**, which may be old or absent. Two consequences stated honestly: (a) a fresh code may be issuable immediately after a login code, so the UI must not promise a wait; (b) the per-mobile **daily/hourly** quotas are charged at `:274-275` under the guard and are the real bound on SMS cost, not the cooldown. INFERENCE: exact interleaving under concurrency is governed by the guard row `:200-211`; not executed.

## 4. Registration (A4)

`POST /patient/register {mobile, code, given_name, family_name, national_id}` — registered **only** in the last functional PR (§12).

1. Consume a `verify_mobile` OTP exactly as login (`verify_mobile` already an allowed purpose, `OtpService.php:39`).
2. Create exactly two rows: one WP user (role `cpms_patient`, random non-derivable login, unusable password — pattern `ManagementPortalService.php:1551-1558`) and one `pending` login row. **No patient row, no link** (clinic unknown; `cpms_appointments.patient_id` is `NOT NULL`, `0001:226`).
3. Collisions: live mobile ⇒ create nothing, `hold_reason='mobile_reuse'` on the existing row. Per-clinic `u_pat_mobile`/`u_pat_nid` (`0001:82-83`) cannot be evaluated without a clinic — handled at first booking (§5) and approval (§6).
4. Identical `202` on every branch; every branch leaves a queue entry (a `pending` row or a `hold_reason`). **Queue = the login table.** Not `cpms_notifications`: `clinic_id` is `NOT NULL` (`0001:634`) and no clinic exists. (Rev 4 already withdrew the false claim that archived rows are never purged; `NotifDispatchHandler.php:39-41` → `NotificationRepository::purgeArchived` `:232-242` do purge them.)

## 5. First booking — the complete atomic creation (R3)

Hold fails a zero-link caller before any hold exists: trusted clinic asserted `BookingService.php:556-557` (`assert_patient_session_matches_clinic` → `PatientSessionScope::require_clinic_matching` `PatientSessionScope.php:347-358`), then `resolveBookingPatientForHold` `:3097-3122` returns `null`, then `mobile_for_user_in_clinic` `:3014-3038` returns `null` ⇒ `CLINIC_VALIDATION_FAILED` `:567-569`.

**Trusted clinic** = owner of the **persisted slot**, derived at `:519-522`: `requireClinician` `:2898-2909` → `clinic_for_persisted_slot` `:2848-2882` (cross-clinic slots additionally require the slot's clinician and an active participation). A body `clinic_id` is never read (`:515-518`).

**Placement (R3):** the creation runs **before** the scope check at `:553-557`, because `PatientSessionScope::require_clinic_matching` `:347-358` resolves the session clinic from **links** (`resolve_clinic_id` `:261-277`: a stamped marker, else exactly one distinct linked clinic, else `null`) — a zero-link account would fail there before it could ever be created. So:

1. **Outermost short transaction**, opened by the booking layer (`booking_transaction` `:2442-2453`; depth must be 0 at entry — else fail closed).
2. `SELECT … FROM cpms_patient_logins WHERE wp_user_id = %d FOR UPDATE` (login-row lock first, §9).
3. If a link for (user, clinic) exists ⇒ continue (idempotent: a second hold creates nothing).
4. Else insert the patient row with **every mandatory column** (`0001:54-79`): `clinic_id` = trusted slot clinic, `mrn` = `PatientService::generateMrn()` (`:760-773`; `MR-yymmdd-XXXXX`, 5 bounded attempts, `u_pat_mrn` `0001:81`), `first_name`/`last_name` = login row names, `mobile` = login row mobile, `national_id` = **NULL** (`:61` nullable), `gender` = `'unknown'` (ENUM default `:63`), `status='active'` (`:75`), `created_at`/`updated_at` = `nowUtcSql()`.
5. **Identity activation + organization identity link** through the existing path: `PatientService::activate_identity()` `:561-576` → `identities->activate_for_new_patient()`; the row that satisfies `PatientRepository::clinical_access_identity_predicate()` `:261-269` (`p.identity_id` + a `cpms_patient_identity_links` row for this user in the clinic's organization) is what makes later record access possible. Skipping it would produce a patient row with `identity_id IS NULL` — legacy-shaped and readable without proof, so it is **mandatory**, and a failure rolls the whole creation back (the pattern at `PatientService.php:431-455`).
6. Link insert with `linked_at` and the rest of the row: `cpms_patient_user_links (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)` — compare the existing insert at `PatientService.php:461-464`; `u_link_pair (patient_id, wp_user_id)` `0001:97`.
7. Commit → **then** the scope check proceeds; the session clinic is the **explicit slot clinic**, never a guess.

**What `PatientSessionScope` returns afterwards:** zero-link account **before** creation would be `null` ⇒ `CLINIC_SCOPE_REQUIRED`; after step 6 it has exactly one distinct linked clinic ⇒ that clinic; a multi-clinic account resolves from the stamped marker (`:297-305`) and only falls back to the single-link rule; if the marker is stale it throws. The design uses the **explicit slot clinic** and never infers it from links, so a multi-clinic account cannot be routed to the wrong clinic.

**Collision (R3b).** If `u_pat_mobile(clinic_id, mobile)` (`0001:82`) already holds this mobile — a walk-in patient created by reception (`ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`) — then: insert nothing, link nothing, one fixed generic message ("online booking is not possible, please contact reception"), and record `hold_reason='clinic_mobile_exists'` **plus `collision_clinic_id` = that clinic** on the login row. Reception of **that** clinic sees the row in its pending queue showing only `given_name` and the mobile — a mobile that clinic already stores on its own walk-in row, so no new exposure — never the NID, never another clinic's rows. Resolution is approval case (iii), an explicit staff link. Double-click: the second attempt finds the same row and does nothing new (unique `u_pl_user`).

**Why the caller learns nothing extra:** reaching step 4 requires a session minted from an OTP sent to that mobile; the message is identical for every collision cause; no id, MRN, clinic or patient count is returned.

## 6. Reception: audit prerequisite, queue, approval, attach (R4, R6)

### 6.1 Prerequisite PR — audit redaction (R4)
Approval case (ii) reuses `PatientService::create`, whose `PATIENT_CREATED` audit writes `$this->staffView($created_row)` (`PatientService.php:472-482`) — and `staffView` `:801-820` extends `publicView` `:779-795`, which contains **raw** `first_name`, `last_name`, `national_id` (`:787`), `mobile` (`:786`) and address. `AuditLogger` masks only `mobile`, `phone`, `national_id`, `card_number`, `email` (`AuditLogger.php:26`, applied `:249-251`) and forbids OTP/password keys (`:24`) — **names are not masked anywhere**. So a pending registrant's name and NID would land in `before_json` in the clear.

**Therefore a small PR precedes any PR that creates patients for pending users:** change the existing `PATIENT_CREATED` payload to ids and closed-vocabulary fields only (`patient_id`, `clinic_id`, `actor role`, `source=reception|first_booking|approval`) — no names, no NID, no mobile — with a reception-creation test. The new first-booking and approval paths use the same redacted payload. **Assumption stated:** the plugin is unreleased, so there are no historical audit rows to migrate.

### 6.2 Queue visibility
Only a clinic where the account already has a `cpms_patients` row sees the entry, **plus** the collision case (R3), which shows `given_name` + mobile to the clinic named in `collision_clinic_id`. Pending registrants with neither are invisible to every clinic and are scrubbed at 14 days (§7.3).

### 6.3 Output flag (R4/F7 carry-over)
Reception search and create return `searchView` (`PatientService.php:245-257`, `:831-844`) via `to_search_view` `:823-825`, called at `ReceptionPortalController.php:544` and `:576` — **not** `staffView` `:801`. `searchView` has no verification flag; add `login_state` only.

### 6.4 Approval — three cases (staff, in person, documents)
- **(i) patient row exists for the account:** write the checked `national_id` under `assert_unique_national_id` `:850-857`, `state='active'`, `verified_*`, clear `hold_reason`/`collision_clinic_id`; scrub the login row's NID in the same transaction.
- **(ii) no patient row yet:** create through the existing reception path (`ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`, which enforces scoped secretary authority and the license gate), then link, then `active`.
- **(iii) walk-in exists with the same mobile:** staff explicitly pick that patient and link; never automatic, never merging rows.
- **NID collision message to staff:** the authenticated operator may see existence — the existing product message (`:855`) plus the masked `searchView` row; never returned to a patient session.
- **Audit + sessions:** one redacted audit row per action; `WP_Session_Tokens::destroy_all()` on any state/mobile/link change.

### 6.5 Legacy / password users (R2 carry-over)
No real legacy users (unreleased plugin). A `cpms_patient` without a login row has level `none` and sees a fixed message with no data; password login **authenticates** but the level comes from the login row. "Attach SMS login" (one transaction: insert `active`, `destroy_all`, one audit row) ships in the **same PR as the gate**.

## 7. Lifecycle: existence, restore barrier, retention (R6)

### 7.1 Table existence (R6a)
`App::is_runtime_blocked_by_pending_schema()` `App.php:622-639` **cannot** prove presence: a supported engine returns `false` at `:626-628`, and unreadable evidence also returns `false` at `:630-634`. It reports *pending migrations*, not a missing table. **So the gate does its own cached-per-request existence check** (`SHOW TABLES LIKE` / `information_schema`, one query per request) and fails **closed** — level `none`, login refused with the neutral message — when the table is missing or unreadable. It never falls through to "unknown mobile".

### 7.2 Restore barrier (R6b, R6c)
`BackupService::restoreApply()` `:324-390` drops and re-creates every `cpms_%` table inside one `transactional()` (`:348-363`) and writes only an audit row afterwards (`:369-378`). There is **no** restore marker in code.

- **Barrier storage:** a WordPress **option** (e.g. `cpms_restore_barrier`). Verified: the dump set is `SHOW TABLES LIKE '{prefix}cpms_%'` (`BackupSqlDumper.php:113-126`) and `dbPrefix()` returns `$wpdb->prefix` (`CpmsDb.php:85-88`), so **`wp_options` is outside the dump set** and a restore cannot overwrite the barrier.
- **Set BEFORE restore starts.** Hook point: `BackupService` — **UNKNOWN whether a pre-drop hook exists**; `restoreApply` `:324-363` shows no such call. The build PR must add it (set barrier → run restore), with a test that kills the process between the drop and the re-create and asserts the barrier is still set. Until that hook exists, the only safe statement is: **a manual restore must set the barrier first**, and the runbook says so.
- **While set:** every patient level is `none`, patient login refuses with the neutral message, patient sessions are unusable. An interrupted restore therefore stays **closed**.
- **Clearing:** only an explicit manager action **"accept restored access state"**, which shows counts of restored `active`/`pending` login rows, destroys **all** patient-only sessions, writes one audit row, and clears the option. Consequence stated plainly: a *restored revoked* account (row resurrected from an old backup) can regain access only if the manager accepts — the design cannot distinguish it by itself, because revocation history lives only in the audit log.

### 7.3 Retention
Live rows are never deleted except by revocation. At decision: `given_name`, `family_name`, `national_id` → NULL. At 14 days: any still-`pending` row is scrubbed and deleted (revocation path), its WP user deleted only when it has no link and no appointment. Sweep host: a **new** `cleanup.patient_logins` recurring job (`App::RECURRING_JOBS` `:2616-2635`); the four existing `cleanup.*` jobs (`:2625-2628`) each own a different table and `OtpCleanupHandler.php:22-25` touches only `cpms_otp_tokens`. A stalled sweep is a **residual**: no health surface exists that I read.

### 7.4 Backups and pending NIDs (R6, OQ-D5 DECIDED)
`BackupSqlDumper` dumps every column (`:146-156`, `:178-197`) of every `{prefix}cpms_%` table, so pending names and NIDs are inside backup files. **DECIDED:** this adds **no new exposure class** — backups already contain every patient's NID from `cpms_patients.national_id`; keep plaintext inside the protected backup store, keep the 14-day live scrub. OQ-D5 is therefore **closed**, not an open question.

## 8. Oracle

| Mobile | `/otp/request` | `/otp/verify` (valid code) |
|---|---|---|
| unknown | send, 200 | `202 register_required`, no cookie |
| live pending/active, eligible | send, 200 | 200 + `Set-Cookie` |
| no row (revoked deleted) | send, 200 | `202 register_required`, no cookie |
| staff / ineligible | send, 200 | generic invalid-code envelope |
| wrong code | send, 200 | generic invalid-code envelope |

`register_required` is distinguishable **only** to a caller who has proved possession of that mobile (owner-permitted).

## 9. Cap and lock order (R5)

**Cap = 2 active appointments per account** (DECIDED), counted across **all patients linked to the account** — by joining links, never by trusting `cpms_appointments.wp_user_id` (`0001:230` is nullable and `createByStaff` does not populate it).

- **Locking current read:** in the **outermost** transaction, first statement = `SELECT … FROM cpms_patient_logins WHERE wp_user_id=%d FOR UPDATE`; then `SELECT COUNT(*) … FROM cpms_appointments a JOIN cpms_patient_user_links l ON l.patient_id=a.patient_id WHERE l.wp_user_id=%d AND a.status IN ('pending','confirmed') AND a.slot_date >= today FOR UPDATE`. A locking read reads the **latest committed** rows, unlike a plain `SELECT` in an existing REPEATABLE READ transaction or under an outer transaction (`CpmsDb.php:272-304`; `booking_transaction` `:2442-2453`) which may see a stale snapshot — dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html (INFERENCE, unexecuted).
- **Depth guard:** if `transaction_depth() !== 0` when the cap is evaluated, fail closed with the neutral message (§3).
- **Scope:** the cap applies **only to patient-initiated creation** (hold/confirm). **`createByStaff` is exempt** and may push the count above 2 — accepted explicitly: staff are trusted and audited, and their write is what the count then reflects.
- **Corrected attribution:** the rev-4 claim that `BookingService.php:1716-1718` shows staff ownership is **wrong** — that is reschedule (`:1705-1720`); `createByStaff` (`:1805-1935`) supplies `patient_id`, `clinician_id`, `slot_*`, `status`, timestamps, and **no `wp_user_id`** (`:1935-1954`).
- **Lock orders and no cycle:** patient hold/confirm: login → Organization → Clinic → Slot → Location (`:1881-1886`, `:2750-2790`). Reschedule: appointment **first**, then the tenant prefix (`:1568-1574`) — it takes no login lock and creates no row, so it cannot cycle with the patient order (which never touches appointments before the login row). `createByStaff`: tenant prefix → Slot → Location, no login row. The only new edge is "login before tenant prefix"; nothing acquires the login row after a tenant lock, so no cycle exists. INFERENCE: argued from the cited sites, not executed.

## 10. Abuse limits

`Settings::DEFAULTS` `Settings.php:28-40` are **configurable defaults** (`get()` `:207`), not ceilings: per mobile 3/day, 10/hour, 60 s cooldown, 900 s lockout after 5 bad codes; per IP for `/otp/request` 10/hour; `/otp/verify` per IP 20/3600 hard-coded (`OtpService.php:392`). `/patient/register` requires a consumed OTP **plus** new buckets keyed `sha256(ip)` and `sha256(mobile)` — never a raw mobile. Existing debt not copied: `OtpService` keys `otp-day:`/`otp-hour:` on the **raw** mobile (`:274-275`, `:622-629`).

## 11. Dependents (last, still blocked)

Several patients per account already work (`u_link_pair` `0001:97`; `linkedActivePatientsForClinic` `BookingService.php:3057-3063`), and `>1` link makes the hold **refuse to guess** (`:3121`) with an optional `patient_id` (`BookingController.php:90`) validated by `assert_requested_patient_owned_by_caller` `:3131-3153`.

> **⛔ OQ-D1 — unchanged blocker.** `cpms_patients.mobile` is `VARCHAR(32) NOT NULL` (`0001:60`) and `UNIQUE (clinic_id, mobile)` (`:82`), so a dependent sharing the guardian's mobile cannot have a row in that clinic. Reusing the guardian's row is a merge; a fake mobile corrupts the call-back field. Neither is invented: **no column or table is added for dependents.** Until the owner decides, dependents work only where the mobile differs (or in another clinic), and the UI says so.

Creation/approval only at reception in person; a `pending` account may not create a dependent; the cap counts parent + dependents; **per-link** access in the gate; no age limit; no auto-link; never merged with a walk-in patient. Negative tests: IDOR by patient id; pending guardian cannot read a dependent's record; a dependent cannot take the parent's NID; two dependents with the same mobile do not leak existence; the cap under concurrency.

## 12. Rollout sequence (R8)

| PR | Contents | Prerequisites | Login-row states possible after deploy | Patient session possible |
|---|---|---|---|---|
| **P1** | table `cpms_patient_logins` (incl. `collision_clinic_id`), existence check, restore barrier option + hook, `CpmsDb` depth accessor | — | none | no |
| **P2** | audit redaction of `PATIENT_CREATED` (R4) | P1 | none | no |
| **P3** | central gate on **all** routes by actor branch + service-layer checks + runtime-registry test + **"attach SMS login"** | P1, P2 | `active` (staff-attached only) | yes, `full` |
| **P4** | SMS login session issuance (cookie after depth-0 check) | P3 | `active` | yes |
| **P5** | cap + first-booking creation + collision queue | P3, P4 | `active` (+ collision `hold_reason`) | yes |
| **P6** | reception queue + approval cases (i)(ii)(iii), session reset | P5 | `active`; `pending` only for reception-provisioned accounts | yes |
| **P7** | **public `/patient/register` registered here** | P3–P6 | `pending` may now exist | yes |
| **P8** | UI journeys (login with SMS **and** password, registration, `register_required`, return to slot) | P7 | `pending`, `active` | yes |
| **P9** | browser lanes for the new journeys | P8 | same | yes |
| **P10** | dependents — **blocked on OQ-D1** | P6 | same | yes |

**Invariant:** no `pending` row can be **created** before the gate, the cap, the first-booking path, approval and attach all exist (P7 is last). "Attach SMS login" is in the gate PR, so no password patient lacking a row is locked out by rollout.

Minutes (INFERENCE, review rounds included): P1 90 · P2 60 · P3 210 · P4 90 · P5 180 · P6 120 · P7 150 · P8 180 · P9 120 · P10 0 until decided ⇒ **≈ 1 200 implementation + 700–900 review/rework ≈ 1 900–2 100**.

## 13. State matrix (R9)

Rows = every patient-reachable route/branch (§2.4). Columns = account states. `✗` = refused (403 or the neutral envelope), `—` = not routed here, `✓` = allowed.

| Route / branch | no row | pending | active | deleted | password-only, no row | staff / mixed | anonymous |
|---|---|---|---|---|---|---|---|
| `POST /booking/hold` | ✗ (login impossible) | ✓ + first-booking create | ✓ | ✗ | ✗ (has session) | — staff path | ✗ 401 |
| `POST /booking/confirm` | ✗ | ✓ cap-checked | ✓ cap-checked | ✗ | ✗ | — staff path | ✗ |
| `GET /booking/resume` | ✗ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `GET /appointments/mine` | ✗ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `GET …/reschedule-options` | ✗ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `POST …/reschedule` | ✗ | ✓ | ✓ | ✗ | ✗ | staff branch (capability) | ✗ |
| `POST …/cancel` | ✗ | ✓ | ✓ | ✗ | ✗ | staff branch (capability) | ✗ |
| `GET /patient/me` | ✗ | ✓ minimal view | ✓ full | ✗ | ✗ | — | ✗ |
| `PATCH /patient/me` | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `POST /patient/enroll` | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — staff path | ✗ |
| `GET /patient/my-records` | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `GET /visits`, `/visits/{id}`, `/prescriptions` | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `/patients/{id}/files` | ✗ | ✗ 403 | ✓ | ✗ | ✗ | staff `permCap` | ✗ |
| `GET /files/{id}/stream` | ✗ | ✗ 403 | ✓ (own, link-checked) | ✗ | ✗ | `MedicalFileService:219-250` | ✗ 401 |
| `/notifications`, `/notifications/read`, `/rt/notifications` | ✗ | empty envelope | ✓ | ✗ | ✗ | staff branch | ✗ |
| portal page render | ✗ | appointments only | full | ✗ | ✗ | — | ✗ 403 |
| `/patient/register` (P7+) | ✓ 202 + pending row | ✓ 202 (existing row) | ✓ 202 no new row | ✓ 202 | ✓ 202 | ✗ ineligible | ✓ 202 |

Every cell follows from one rule set: `level()` from the live row, the per-branch level in §2.4, and the service-layer checks in §2.4's table. `deleted` behaves exactly like `no row`.

## 14. Self-audit (R10)

- **Every file:line re-verified** against `origin/main` = `c9771403`. Corrections applied: `App.php:622-639` cannot detect a missing table (§7.1, rev-4 claim withdrawn); `:1716-1718` is reschedule, not staff ownership (§9); the "60 s cooldown" claim replaced by purpose-scoped policy state (§3); `MedicalFileService.php:219-250` cited only for what it does (a `$isPatient` branch decided from durable membership, then link); `/patient/enroll` is **not** staff-only (§2.4).
- **Overstated claims deleted, not explained:** "137 routes ⇒ 14 patient routes" (now 18 sites / 20 runtime routes with the method stated); "nothing purges notifications" (already withdrawn); "the controller placement proves the outermost commit" (now a depth check that fails closed).
- **NID never in a URL, log, audit row, rate-limit key or notification:** enforced by §6.1's redacted payload, §10's hashed keys, and §1's scrub rule. **Residual, declared:** pending NIDs sit in plaintext in the live table (≤14 days) and in every backup (§7.4, DECIDED).
- **No auto-merge, auto-link or auto-access** anywhere; the three approval cases are all staff-initiated (§6.4).
- **UNKNOWNs kept:** (1) MariaDB plain-unique behaviour; (2) locking-read visibility on MariaDB 11.4.13. **New UNKNOWNs:** (3) whether a pre-restore hook can be added cleanly at `BackupService::restoreApply` `:324-363`; (4) exact `verify_mobile` cooldown interleaving under concurrency; (5) whether any `admin_post_*` handler is reachable by a `cpms_patient` user (46 distinct names to be proven by the build PR's registry test).

## 15. Weakest points and open decisions

**Weakest:** (a) plaintext pending NID in the live table and in backups — reduced, not eliminated; (b) the restore barrier needs a hook that does not exist yet, so until then it is a runbook step; (c) the 14-day sweep has no existing host and no health surface; (d) the runtime registry test cannot see routes registered outside the REST server; (e) the cap is per account and staff bookings are exempt by decision; (f) dependents remain blocked; (g) `active` users keep the existing NID self-edit and unmasked self-read (DEBT, §2.4).

**Open:** **OQ-D1** dependents (blocking) · **OQ-D2** should a stalled sweep warn (needs a health surface) · **OQ-D3** record access per account or per verified link (assumed per link) · **OQ-D4** is "attach login" mandatory forever for password accounts. **Closed:** OQ-D5 (backups, §7.4).
