# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 7)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> A proposal for review. Merging does **not** authorise a build; the build starts only after this PR is
> merged and two independent readers pass it. The one table `cpms_patient_logins` is **owner-approved**
> for that later build, and **any other new table or column needs separate owner approval — stop and ask.**
> No code, migration, test or workflow here.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` |
| **Prior head** | `7fd20d9021345f5ff3fd7046d939db2412627c43` (revision 5; no revision-6 commit exists on this branch) |
| **Supersedes** | `docs/architecture/appointment-request-design.md` (absent; history keeps it) |
| **Labels** | **OBSERVED** read in code at the stated commit, not executed · **INFERENCE** · **UNKNOWN** · **LIMITATION** · **DECIDED** · **DEBT** |
| **Not executed** | No PHP/MySQL/WordPress/browser run; PHP gates NOT APPLICABLE (docs-only). |

## Revision 7 changes (owner policy change)

| # | Change |
|---|---|
| **1** | Link logic **replaced** by the two-factor rule: mobile+OTP **and** national ID matching the stored record (§6.2–6.5). In-person approval is no longer a precondition for record access; the collision-refusal text is deleted and replaced with the first-booking outcome (§5.4). |
| **2** | Shared mobile: **STOP** at Organization level with options and a recommendation (§6.6); clinic level already forbids it. |
| **3** | `collision_clinic_id`: **no longer needed**; recommendation delete, owner decides (§6.7). |
| **4** | New security section: five threats, mitigation each, residual risk, plus the self-declared-record squat check against `u_pat_nid` (§16). |
| **5** | Tests table for the linking flow (§17). |
| **6** | One paragraph: restore closes all logins, access is re-established through the same two-factor flow (§7.2). |

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
| `collision_clinic_id` | `BIGINT UNSIGNED NULL` | **recommended for deletion, §6.7 — owner decides**; kept in the table list so the column count stays explicit |
| `verified_by_wp_user_id`, `verified_at` | `BIGINT UNSIGNED NULL`, `DATETIME(3) NULL` | who approved, when |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |

`UNIQUE u_pl_mobile (mobile)`, `UNIQUE u_pl_user (wp_user_id)`, `KEY (state, created_at)`, `KEY (national_id)`, `KEY (collision_clinic_id)` (drop with the column if §6.7 is accepted).
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
| `full` (**requires the two-factor link, §6.3**) | `/visits` `ClinicalController.php:167`; `/visits/{id}` `:185`; `/prescriptions` `:199`; `/patients/{patient_id}/files` (GET+POST) `FilesController.php:72-95`; `/files/{id}/stream` patient branch `:60`; **`/patient/enroll`** `PatientController.php:38-49` (**reclassified — R2**); `/patient/my-records` `:52-62`; `/notifications`+`/notifications/read`+`/rt/notifications` patient branches `NotificationsController.php:34,51,74` |
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

**Level after this revision (task 1).** `booking` is the level of an account with a live login row that has **not** completed the two-factor link of §6.2 — it may hold appointments it booked and nothing else. `full` requires a **verified two-factor link** (§6.3), not merely an `active` state: the state alone no longer opens records. `pending` maps to `booking` and may call `/patient/link-record` only after its own reception step.

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

## 5. First booking under the new policy (task 1, partial replacement)

The zero-link failure chain is unchanged (OBSERVED `BookingService.php:556-557`, `:3097-3122`, `:3014-3038`, `:567-569`), and the trusted clinic is still the owner of the **persisted slot** (`:519-522`). What changes is what first booking is allowed to *do*: **it may create the clinic patient row, and it may not link it to the account for record access.** Under the owner rule a link for record access needs both factors (§6.2), and the national ID is not part of the booking request.

### 5.1 Creation (kept from revision 5, still required)
One short outermost transaction **before** the scope check at `:553-557` (that check resolves the clinic from links, `PatientSessionScope.php:347-358` / `:261-277`, so it cannot pass for a zero-link account). Every mandatory column per `0001:54-79`: `mrn` via `PatientService::generateMrn()` `:760-773` (`u_pat_mrn` `0001:81`), names and mobile from the login row, `national_id` **NULL** (`0001:61`), `gender='unknown'` (`:63`), `status='active'` (`:75`), timestamps. Then the identity activation that record access later depends on (`PatientService::activate_identity()` `:561-576` → `PatientIdentityActivator::activate_for_new_patient()` `:77-95`), then the clinic link row with `mobile_at_link` and `linked_at` mirroring `PatientService.php:461-464`.

### 5.2 What the link means now
`cpms_patient_user_links` remains a **booking-subject** link. It is **not** evidence for record access any more. This is forced by the code: `PatientRepository::clinical_access_identity_predicate()` `:261-269` grants clinical access whenever `p.identity_id IS NULL`, and first-booking creation leaves `identity_id` NULL unless activation runs — so a link alone would open records for a row whose only proofs were possession of a mobile. The gate (§2.4) therefore requires the **two-factor link** of §6.3 for `full`, and treats a bare clinic link as `booking` only.

### 5.3 Idempotency
A second hold finds the existing clinic link and creates nothing (`u_link_pair` `0001:97`); the login-row lock is taken first (§9).

### 5.4 The clinic already holds this mobile but the national ID does not match (replaces the rev-5 collision refusal)
`u_pat_mobile (clinic_id, mobile)` (`0001:82`) means the clinic already holds this mobile on **some** patient row. Under the new policy that is **not** a refusal and not an automatic link: the row stays unlinked, the account stays `booking`, and the ordinary booking path continues. Record access for that row is obtainable only through §6.2, whose national-ID comparison happens **inside that same clinic** (M3). If the caller's national ID does not match that row, **no link is created**, the response is the single generic failure of M5, and the in-person reception path (§6.5) is the only way forward. Nothing about the existing row (its name, its MRN, whether it exists) is disclosed.

## 6. The two-factor link (task 1, new core)

### 6.1 Owner decision (final, supersedes "no auto-link" and "in-person approval before record access")
A patient is authenticated by **both** (1) a mobile that receives a valid SMS OTP and (2) a national ID matching the stored record. When both match, the patient gets `full` access to **their own** record. In-person approval is **not** a precondition. Mobile alone never grants record access; a mismatch creates **no** link; a shared mobile never merges identities or family members' records; if one mobile belongs to several patients the national ID selects which.

**Manager defaults (overridable):** **M1** the record's own mobile must equal the OTP-verified mobile, otherwise the national ID alone would open any record; **M2** a record with no national ID is never auto-linked; **M3** linking happens only inside the clinic whose own record matches **both** values, never across clinics from this check alone; **M4** attempt limits key on account, mobile and IP — **never on the national ID**, which appears only in a request body, never in a URL, log, audit row, rate-limit key or notification; **M5** every failure returns the same status, body and message, with no oracle for "record exists", "mobile exists" or "NID wrong", and **no timing-equality claim**; **M6** after success, an SMS to that same mobile ("your record was linked to an account", no NID), one audit row with ids and closed codes, and reception can sever the link with its own audit row; **M7** every additional patient needs its own mobile+OTP+national-ID proof — no cross-patient inheritance.

### 6.2 Endpoint and proof
`POST /patient/link-record {mobile, code, national_id}` — a new patient route at level `booking`, registered **only** in the PR that also ships the gate (§12). Proof order, inside **one outermost transaction** with the depth guard (§3, §9):
1. Consume the OTP for `verify_mobile` and require it bound to **this account's live login row** and to `mobile` (single-use consume, `OtpService.php:527-534`).
2. Re-lock the login row `FOR UPDATE`; refuse if `state='pending'` **and** no approval path exists yet (§12) — a `pending` account may link only after its own reception step, and an `active` account may link immediately.
3. `national_id`: strip invisibles/bidi, fold Persian/Arabic digits, then `NationalIdValidator::isValid()`.
4. Candidate row: `SELECT * FROM cpms_patients WHERE clinic_id = %d AND mobile = %s AND national_id = %s AND status='active'`. The clinic comes from the account's **linked** clinic set; if the account is linked in several clinics the loop runs per clinic and must resolve to **exactly one** candidate, else the generic failure (no election, no "first match").
5. Exactly one candidate ⇒ write the link and set the login row `active` (first link means verified), then M6.

### 6.3 What a successful link writes
`cpms_patient_identity_links (organization_id, identity_id, wp_user_id, mobile_at_link, is_primary, linked_at)` — `u_pil_pair (identity_id, wp_user_id)` (`0019:54-73`) — **plus** `cpms_patient_user_links` for the clinic row, so the existing record selector works without change (`require_selected_patient()` `PatientService.php:219-238`). OBSERVED: the identity row is what the clinical predicate consults (`PatientRepository.php:261-269`), and it carries **`normalized_mobile` only — no national ID** (`0018:27-36`, `0019:44-53`). **Consequence, stated plainly: the national ID is never promoted to the Organization identity.** It stays on the clinic patient row, so a second clinic of the same organization requires its **own** two-factor proof (M3/M7), which is the conservative reading and needs no schema change.

### 6.4 Failure behaviour (all reasons, one answer)
Every failing step returns the identical `422` envelope with a fixed code and message ("record could not be verified, please contact reception"), no `Set-Cookie` change, no state change, one audit row with closed codes only (`LINK_RECORD_DENIED`, reason enum, ids — never the NID, never the compared mobile). Rate limits (M4): per account, per mobile and per IP using the existing buckets plus new hashed keys `link-acct:<sha256(wp_user_id)>`, `link-mobile:<sha256(mobile)>`, `link-ip:<sha256(ip)>`; the national ID is **never** a key. After N failures per account the route returns the same envelope with `429` semantics — the only distinguishing signal is the rate-limit status, which is identical for every reason.

### 6.5 In-person fallback, and what replaced approval
Reception keeps three documented actions (unchanged in substance from the previous revision): create the patient through the existing reception path (`ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`), set a checked national ID, or explicitly link an existing walk-in patient — all staff-initiated, audited, never automatic, never merging rows, with sessions reset (`WP_Session_Tokens::destroy_all`). Under the new policy this is **not** the normal path to record access; it is the fallback for M1/M2 failures (wrong mobile on the record, record with no national ID) and for a shared-mobile family.

### 6.6 Shared mobile — **STOP: needs owner decision** (task 2)
OBSERVED, both levels:
- **Clinic level cannot hold it.** `u_pat_mobile (clinic_id, mobile)` is `UNIQUE` (`0001:82`) and `mobile` is `VARCHAR(32) NOT NULL` (`0001:60`), so two patients with one mobile **cannot** coexist in one clinic.
- **Organization level does not hold it either.** `cpms_patient_identities.normalized_mobile` is explicitly **non-unique** with duplicate candidates allowed (`0019` header and `:44-53`), so the identity layer *tolerates* a shared mobile — but the identity carries no national ID, so "one mobile, several patients, national ID selects" cannot be evaluated there. It can only be evaluated on `cpms_patients`, which is per clinic and unique per mobile.

> **STOP — needs an owner decision: schema change.** Options, with recommendation:
> **(A)** Keep one patient row per mobile per clinic and treat a family share as a **booking-subject** relationship only (the guardian books for the dependent; the dependent never gets record access through the shared number). *Recommended:* no schema change, no merge risk, matches M7.
> **(B)** Add a nullable secondary-contact/`mobile_override` concept **or** a dependent table so a clinic can hold several patients under one number. Needs a new column/table — outside the approved scope; also re-opens the merge/leak questions this design avoids.
> **(C)** Relax `u_pat_mobile` — rejected: it removes the only hard guarantee that a mobile maps to one clinic record, and the collision logic in §5.4 depends on it.
> **Risk of (A):** a family that genuinely shares one number cannot get self-service record access for the second person; they use reception. **Supported today without any schema change:** one mobile → one patient per clinic; several patients per account with distinct mobiles; a guardian holding a child's appointments.

### 6.7 `collision_clinic_id` — **recommend delete** (task 3)
Under the new policy the first-booking collision is no longer a refusal that someone must find and resolve (§5.4): the account simply stays unlinked and the patient later self-services through §6.2. The column existed to give reception a work item for a refusal that no longer happens. **Recommendation: delete it** (it was added in this design, never shipped, so removing it costs nothing). Counter-argument recorded: reception may still want visibility of "this account collided"; that is served by the audit row of §5.4. **Owner decides.**

## 7. Lifecycle: existence, restore barrier, retention (R6)

### 7.1 Table existence (R6a)
`App::is_runtime_blocked_by_pending_schema()` `App.php:622-639` **cannot** prove presence: a supported engine returns `false` at `:626-628`, and unreadable evidence also returns `false` at `:630-634`. It reports *pending migrations*, not a missing table. **So the gate does its own cached-per-request existence check** (`SHOW TABLES LIKE` / `information_schema`, one query per request) and fails **closed** — level `none`, login refused with the neutral message — when the table is missing or unreadable. It never falls through to "unknown mobile".

### 7.2 Restore barrier (R6b, R6c)
`BackupService::restoreApply()` `:324-390` drops and re-creates every `cpms_%` table inside one `transactional()` (`:348-363`) and writes only an audit row afterwards (`:369-378`). There is **no** restore marker in code.

- **Barrier storage:** a WordPress **option** (e.g. `cpms_restore_barrier`). Verified: the dump set is `SHOW TABLES LIKE '{prefix}cpms_%'` (`BackupSqlDumper.php:113-126`) and `dbPrefix()` returns `$wpdb->prefix` (`CpmsDb.php:85-88`), so **`wp_options` is outside the dump set** and a restore cannot overwrite the barrier.
- **Set BEFORE restore starts.** Hook point: `BackupService` — **UNKNOWN whether a pre-drop hook exists**; `restoreApply` `:324-363` shows no such call. The build PR must add it (set barrier → run restore), with a test that kills the process between the drop and the re-create and asserts the barrier is still set. Until that hook exists, the only safe statement is: **a manual restore must set the barrier first**, and the runbook says so.
- **While set:** every patient level is `none`, patient login refuses with the neutral message, patient sessions are unusable. An interrupted restore therefore stays **closed**.
- **Clearing:** only an explicit manager action **"accept restored access state"**, which shows counts of restored `active`/`pending` login rows, destroys **all** patient-only sessions, writes one audit row, and clears the option. Consequence stated plainly: a *restored revoked* account (row resurrected from an old backup) can regain access only if the manager accepts — the design cannot distinguish it by itself, because revocation history lives only in the audit log.

### 7.2a Restore and the two-factor flow (task 6)
After a restore the barrier (§7.2) closes **every** patient login, including a restored `active` row and any resurrected link — the barrier is the only thing standing between an old backup and an automatic link, so it is applied unconditionally. A patient then re-establishes access through **this same** mobile+OTP+national-ID flow (§6.2): the restored record still needs both factors, and because the identity row carries no national ID (§6.3) a restored identity link alone does not reopen records. A restored revoked account can therefore regain access only by completing the proof again, or by the manager accepting the restored state (§7.2).

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

Under the two-factor policy a dependent's own record opens only through that dependent's **own** mobile+OTP+national-ID proof (M7) — a guardian's proof never inherits; where the dependent shares the guardian's mobile this is the §6.6 STOP. A `pending` account may not create a dependent; the cap counts parent + dependents; **per-link** access in the gate; no age limit; never merged with a walk-in patient. Negative tests: IDOR by patient id; pending guardian cannot read a dependent's record; a dependent cannot take the parent's NID; two dependents with the same mobile do not leak existence; the cap under concurrency.

## 12. Rollout sequence (R8)

| PR | Contents | Prerequisites | Login-row states possible after deploy | Patient session possible |
|---|---|---|---|---|
| **P1** | table `cpms_patient_logins`, existence check, restore barrier option + hook, `CpmsDb` depth accessor | — | none | no |
| **P2** | audit redaction of `PATIENT_CREATED` (R4) | P1 | none | no |
| **P3** | central gate on **all** routes by actor branch + service-layer checks + runtime-registry test + **"attach SMS login"** | P1, P2 | `active` (staff-attached only) | yes, `full` |
| **P4** | SMS login session issuance (cookie after depth-0 check) | P3 | `active` | yes |
| **P5** | cap + first-booking **creation only** (link is booking-subject, §5.2) | P3, P4 | `active` (unlinked) | yes, `booking` |
| **P6** | **two-factor link** `POST /patient/link-record` (§6.2) + reception fallback actions (§6.5), session reset | P5 | `active`; `pending` only for reception-provisioned accounts | yes |
| **P7** | **public `/patient/register` registered here** | P3–P6 | `pending` may now exist | yes |
| **P8** | UI journeys (login with SMS **and** password, registration, `register_required`, return to slot) | P7 | `pending`, `active` | yes |
| **P9** | browser lanes for the new journeys | P8 | same | yes |
| **P10** | dependents — **blocked on OQ-D1** | P6 | same | yes |

**Invariant, restated for the new policy:** `full` access cannot exist before **P6** (the two-factor link), and the link route is registered in the same PR that depends on the gate, the cap and first-booking creation being in place. **No PR before P6 grants record access.**

**Invariant:** no `pending` row can be **created** before the gate, the cap, the first-booking path, approval and attach all exist (P7 is last). "Attach SMS login" is in the gate PR, so no password patient lacking a row is locked out by rollout.

Minutes (INFERENCE, review rounds included): P1 90 · P2 60 · P3 210 · P4 90 · P5 180 · P6 120 · P7 150 · P8 180 · P9 120 · P10 0 until decided ⇒ **≈ 1 200 implementation + 700–900 review/rework ≈ 1 900–2 100**.

## 13. State matrix (R9, re-derived for the two-factor policy)

`✗` = refused (403 or the neutral envelope) · `—` = not routed here · `✓` = allowed. **`active` is split**: `active unlinked` is level `booking` (a live login row without a completed two-factor link, §2.4); `active linked` is level `full`.

| Route / branch | no row | pending | active unlinked | active linked | deleted | password-only, no row | staff / mixed | anonymous |
|---|---|---|---|---|---|---|---|---|
| `POST /booking/hold` | ✗ | ✓ + first-booking create | ✓ | ✓ | ✗ | ✗ (has session) | — staff path | ✗ 401 |
| `POST /booking/confirm` | ✗ | ✓ cap-checked | ✓ cap-checked | ✓ cap-checked | ✗ | ✗ | — staff path | ✗ |
| `GET /booking/resume` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `GET /appointments/mine` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `GET …/reschedule-options` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `POST …/reschedule` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | staff branch (capability) | ✗ |
| `POST …/cancel` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | staff branch (capability) | ✗ |
| `GET /patient/me` | ✗ | ✓ minimal | ✓ minimal | ✓ full | ✗ | ✗ | — | ✗ |
| `PATCH /patient/me` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `POST /patient/link-record` (§6.2) | ✗ | ✗ 403 until its reception step | ✓ | ✓ (re-link idempotent) | ✗ | ✗ | ✗ ineligible | ✗ |
| `POST /patient/enroll` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | — staff path | ✗ |
| `GET /patient/my-records` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `GET /visits`, `/visits/{id}`, `/prescriptions` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `/patients/{id}/files` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | staff `permCap` | ✗ |
| `GET /files/{id}/stream` | ✗ | ✗ 403 | ✗ 403 | ✓ own, link-checked | ✗ | ✗ | `MedicalFileService:219-250` | ✗ 401 |
| `/notifications`, `/notifications/read`, `/rt/notifications` | ✗ | empty envelope | empty envelope | ✓ | ✗ | ✗ | staff branch | ✗ |
| portal page render | ✗ | appointments only | appointments only | full | ✗ | ✗ | — | ✗ 403 |
| `/patient/register` (P7+) | ✓ 202 + pending row | ✓ 202 | ✓ 202 no new row | ✓ 202 | ✓ 202 | ✓ 202 | ✗ ineligible | ✓ 202 |

Every cell follows from one rule set: `level()` from the live row plus the **two-factor link**, the per-branch level in §2.4, and the service-layer checks in §2.4's table. `deleted` behaves exactly like `no row`. The single new cell that matters: **`active unlinked` must not reach a single record route**, which is the whole point of §5.2 and §6.2.

## 14. Self-audit for this revision (Phase 2, adversarial)

Read as an attacker against the **new** delta only.

- **(a) A path to records/session/notifications for pending, revoked, no-row or anonymous, or a wrong-patient/wrong-clinic link.** The dangerous new surface is `/patient/link-record`. It requires a live login row **and** a consumed `verify_mobile` OTP for that account's mobile (§6.2), so `no-row`, `deleted` and `anonymous` cannot reach it (matrix §13). `pending` is refused until its reception step. The wrong-clinic risk is handled by looping the account's **linked** clinics and demanding exactly one candidate (M3) — no election, no cross-clinic write. The wrong-patient risk is `mobile = %s AND national_id = %s` **and** `status='active'` on the same row, so a partial match cannot link. **Found and fixed while writing:** the first draft set `full` from `state='active'`; that was wrong, because first-booking creation leaves `identity_id` NULL and the clinical predicate admits NULL identities (`PatientRepository.php:261-269`), so an `active unlinked` account would have opened records through its clinic link. §2.4 and §13 now require the **two-factor link** for `full`.
- **(b) Any false file:line.** All new citations re-verified with `git show origin/main:`: `0001:60,61,82,83,97` (mobile NOT NULL, NID nullable, both uniques, link pair), `0018:27-36` (identities: no national ID), `0019:44-53,54-73` (non-unique `normalized_mobile`, identity links), `PatientRepository.php:261-269` (NULL-identity clause), `PatientService.php:850-857` (`assert_unique_national_id`), `PatientIdentityActivator.php:77-95,110-112,156-176`, `PatientIdentityService.php:48-62`, `BookingService.php:556-569,3097-3122,3014-3038,519-522`, `PatientSessionScope.php:261-277,347-358`, `PatientService.php:219-238,370-400,461-464,760-773`, `ReceptionPortalController.php:183-188`, `OtpService.php:527-534`. One earlier claim is corrected in place: revision 5 said first booking could grant record access after the link — **withdrawn** (§5.2). A Portuguese heading slipped into a draft and was replaced (§5.4).
- **(c) Registration or linking open before the gate exists.** No: `/patient/register` is P7 and `/patient/link-record` is registered in P6, both after P3's gate over all routes (§12), with the invariant restated in §5.2/§12 that `full` cannot exist before P6.
- **(d) Unbuildable contradiction.** The revision-5 flow asked for a national ID at first booking while the booking request cannot carry one; §5 no longer does. The remaining build dependency is honest: M3's per-clinic loop needs the account's linked clinic set, which exists only after P5 — the matrix and the PR order reflect that.
- **(e) Untraced CLAIM words.** "Never" is used only where traced: `u_pat_mobile`/`u_pat_nid` uniqueness (`0001:82-83`), the national ID never being an identity attribute (no column exists, `0018:27-36`), and the NID never being a rate-limit key (a design rule, not a code fact — labelled as design). Everything else is **INFERENCE** or **UNKNOWN**, listed in §15.

## 15. Weakest points and open decisions

**Weakest:** (a) the national ID is not a secret, so the whole policy rests on mobile possession plus the matcher — a relative holding both can link (§16); (b) a **squatting row blocks a real patient's national ID inside that clinic** (`u_pat_nid` `0001:83`) and M2 then refuses auto-link, so resolution is manual; (c) `clinical_access_identity_predicate()` still admits `identity_id IS NULL` rows, so the gate — not the repository — is what keeps records closed (DEBT, §16); (d) the SMS after a successful link goes to the same number an attacker would control in a SIM-swap case (M6, weak warning); (e) shared-mobile families are only partly served until §6.6 is decided; (f) the national ID is never promoted to the organization identity, so a second clinic needs its own proof (§6.3) — conservative but more friction; (g) plaintext pending NID in the table and in backups (§7.4); (h) the restore barrier needs a hook that does not exist yet (§7.2); (i) the 14-day sweep has no existing host (§7.3); (j) dependents remain blocked.

**Open:** **OQ-D1** dependents · **OQ-D2** stalled-sweep warning · **OQ-D3** per-account or per-link record access (assumed per link) · **OQ-D4** "attach login" mandatory forever for password accounts · **OQ-D5 closed** (backups, §7.4) · **new OQ-D6** shared mobile at organization level — §6.6 STOP, owner decides · **new OQ-D7** `collision_clinic_id` keep or delete — §6.7, recommendation delete.
