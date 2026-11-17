# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 9)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> Merging does **not** authorise a build; it starts only after this PR is merged and two independent readers
> pass. `cpms_patient_logins` is **owner-approved**; **any other new table or column needs separate owner
> approval — stop and ask.** No code, migration, test or workflow here.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` |
| **Prior head** | `9a1fb5b5249548623dc7af9a8a06f90ba93a11a7` (revision 8) |
| **Labels** | **OBSERVED** read in code at this commit, not executed · **INFERENCE** · **UNKNOWN** · **LIMITATION** · **DECIDED** · **DEBT** |
| **Not executed** | No PHP/MySQL/WordPress/browser run; PHP gates NOT APPLICABLE (docs-only). |

## Revision 9 changes

| # | Change |
|---|---|
| **R1** | **Root cause of the revision-8 blockers fixed:** the "verified binding" is now a row **inside `cpms_patient_logins`** (`clinic_id`, `patient_id`, `verified_at`, `verification_method`), settable **only** by link-record two-factor success or reception approval. Registration, first booking, reschedule and identity reuse never set it; existing edges are **NON-PROOF** (§2.3, §6.3). |
| **R2-R7** | One-clinic search with the OTP-time stamp mechanism (§6.2); notifications gated before `lastId`/ETag/304 and binding-filtered (§6.7); one live account per record per clinic by row lock, since no unique key can express it (§6.3); consume at depth 0 (§6.5); audit inventory by search term (§6.6). |
| **R8-R11** | Reschedule **inserts** a replacement row (`BookingService.php:1720-1741`) — contrary claims removed, cap restated (§8); no collision flag is stored (§6.2); restore paragraph (§7.3); rollout and cross-references repaired (§11, §16). |

---

## 1. Data model (D1)

One table, `{wp_prefix}cpms_patient_logins`:

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `wp_user_id` | `BIGINT UNSIGNED NOT NULL` | no FK (none to `wp_users` exists) |
| `mobile` | `CHAR(11) NOT NULL` | normalised `09xxxxxxxxx` |
| `state` | `ENUM('pending','active') NOT NULL DEFAULT 'pending'` | **not** an access level (§2.4) |
| `clinic_id`, `patient_id` | `BIGINT UNSIGNED NULL` | **the binding** (§6.3); NULL = unbound |
| `verified_at`, `verification_method` | `DATETIME(3) NULL` / `ENUM('two_factor','reception') NULL` | set only by §6.3's two setters |
| `given_name`, `family_name`, `national_id` | `VARCHAR(120)` / `VARCHAR(120)` / `VARCHAR(10)` NULL | pre-verification only; scrubbed at decision or 14 d |
| `hold_reason`, `verified_by_wp_user_id` | `ENUM(...) NULL` / `BIGINT NULL` | reception approval only |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |

`UNIQUE u_pl_mobile (mobile)`, `UNIQUE u_pl_user (wp_user_id)`, `KEY (state, created_at)`, `KEY (national_id)`, `KEY (clinic_id, patient_id)`. Revocation = DELETE + one audit row in one transaction; history lives in `cpms_audit_logs` (`0001:676-701`). **UNKNOWN:** not executed on MariaDB 11.4.13.

## 2. Access model

### 2.1 One gate
`PatientAccess::level(int $wp_user_id, ?int $patient_id = null, ?int $clinic_id = null)` → `none` | `booking` | `full`, re-read per request (never a cookie).
`full` needs **all** of: a live login row; `verified_at` non-NULL for that `(clinic_id, patient_id)`; the clinic link; the Organization identity edge.
Anything less is `booking`.

### 2.2 Recount (revision 5 method, unchanged)
137 `register_rest_route` sites / 18 controllers, every `permission_callback` callee resolved ⇒ 153 runtime routes; **18 patient-reachable sites / 20 runtime
routes** (loop expansion: `DoctorPortalController.php:82-91`, `QueueController.php:217-225`, `PatientController.php:64-93`, `FilesController.php:72-95`). A
staff-role user is never patient-only (`Admin/PatientPortalPage.php:92-111`, `:116-124`) nor eligible for SMS login.

### 2.3 Existing edges are NON-PROOF (R1)
`cpms_patient_user_links` (booking/staff-created), `cpms_patients.identity_id` and `cpms_patient_identity_links` (set on **every** create,
`PatientIdentityActivator.php:93-104`, and Organization-wide), and `cpms_patient_identities` (**no** national ID, `0018:27-36`) grant no record access and are
never upgraded by registration, first booking, reschedule or identity reuse. `PatientRepository::clinical_access_identity_predicate()` `:261-269` is the hole
— it admits `p.identity_id IS NULL` **or** a matching identity link — used by `active_linked_records_for_user` `:85-99`, the single-patient selector
`:145-156`, `active_linked_patients_for_user` `:164-177`, `find_active_linked_patient_by_link` `:182-195` (call sites `:95`, `:151`, `:173`, `:191`). The
build replaces that disjunct with an `EXISTS` on a `cpms_patient_logins` binding for **account + patient + clinic**, so a legacy NULL-identity row is readable
only after proof too.

### 2.4 Levels
Three levels, enumerated route-by-route once in §12: **`booking`** (hold, confirm, resume, `/appointments/mine`, reschedule-options, the patient branches of
reschedule/cancel, the minimal `GET /patient/me`, portal appointments); **`full`** (visits, prescriptions, files + stream, `/patient/enroll`,
`/patient/my-records`, `POST /patient/link-record`, full `GET` and `PATCH /patient/me`); **refused writes** (`PATCH /patient/me` for `booking`). Staff
branches and staff routes are unchanged. Enforcement is duplicated at the service layer so a direct service call is covered too: `PatientService::me()`
`:128`, `updateMe()` `:139`, `linked_records()` `:99-112`, `PatientController.php:52-62`, `NotificationService::inbox()` `:155-184`,
`Admin\PatientPortalPage::render()`.

### 2.5 Non-REST surfaces
`admin_post_*`: 51 occurrences / 46 distinct (`git grep -o "admin_post_[a-z_]*"` over `src`); `wp_ajax_*`: 0; `add_shortcode`: 1
(`PublicBookingShortcode.php:219`); `template_redirect`: 9 hits — `RuntimeSchemaGate.php:42`, `DoctorPortalShell.php:30,31`,
`Frontend/ManagementPortalController.php:80`, `ManagementPortalShell.php:64`, `PatientPortalShell.php:50`, `StaffPortalShell.php:194` + 2 comments — **NOT
PROVEN COMPLETE**. The runtime self-check must enumerate `rest_get_server()->get_routes()` and fail on any route with no level.

## 3. Login, transaction depth, cookie

Normalise → limits → verify + atomic consume (`OtpService.php:527-534`), candidate read inside that transaction (`:449` → `:575`). 0 live rows + valid code ⇒
`202 register_required`, no cookie. **Cookie:** nested `transactional` calls become savepoints (`:279-304`), so issuance needs depth 0 or fails closed
(neutral 503). **That accessor does not exist** — `tx_depth` is private (`:31`, `:286`, `:316`); P1 adds a read-only one. `remember=false` (today `true`,
`:595`); 8 h via `auth_cookie_expiration` for patient-only users. **Fresh `verify_mobile` code:** state is keyed by (mobile, **purpose**) (`:232`, `:658-672`)
with `OtpPolicy::canSend` (`:234`), so no `login` cooldown is inherited; the bound is the per-mobile charge (`:274-275`). INFERENCE: concurrency governed by
the guard row `:200-211`.

## 4. Registration

`POST /patient/register` — registered only in P7 (§11). Consume a `verify_mobile` OTP (`:39`); create exactly two rows (WP user, random login + unusable
password, pattern `ManagementPortalService.php:1551-1558`; one `pending` row with **no binding**). **No patient row, no link, no `clinic_id`** — clinic
unknown (`cpms_appointments.patient_id NOT NULL` `0001:226`). Collision ⇒ nothing created; identical `202` on every branch. The queue is the login table,
**not** `cpms_notifications` (`clinic_id NOT NULL` `0001:634`, purged by `NotifDispatchHandler.php:39-41` → `NotificationRepository::purgeArchived`
`:232-242`).

## 5. First booking (R2, B1-B2)

Zero-link failure chain (OBSERVED `BookingService.php:556-557`, `:3097-3122`, `:3014-3038`, `:567-569`). **(a) Clinic** only from the persisted slot:
`requireClinician` `:2898-2909` → `clinic_for_persisted_slot` `:2848-2882` at `:519-522`; a body `clinic_id` is never read (`:515-518`). **(b) Names** from
the **validated request body** (`PatientService::CREATE_FIELDS` `:52-57`, `validate_for_update(..., true)` `:420`); login-row names are never trusted. **(c)
Edges** — `create` wraps the row and its identity edge in one transaction (`:431-455`); `activate_identity()` `:561-576` →
`PatientIdentityActivator::activate_for_new_patient()` `:77-107`, which **reuses** a resolvable Organization identity (`:93-94`) via `link_clinical_record`
(`:101`). **NON-PROOF (§2.3): no `verified_at` row is written, so a first booking yields `booking`, never `full`.** **(d) Collision** — `u_pat_mobile` UNIQUE
(`0001:82`): create nothing and return the **same generic refusal** as the link route (§6.2). **Disclosure (owner-visible risk):** whoever controls the mobile
learns this clinic holds a record for it; no timing claim. **(e)** A second hold creates nothing; the login-row lock is taken first (§8); the cap covers
patient-initiated creation including dependents.

## 6. The two-factor proof and binding

### 6.1 Owner policy (final, first release)
All capabilities ship in the **first release**: self-registration, record creation at first booking, pending state with cap 2 per account including
dependents, dependents/guardian accounts (each extra patient needs its own proof), and link-record two-factor. A patient is authenticated by **both** a mobile
receiving a valid SMS OTP and a national ID matching the record; in-person approval is **not** a precondition. **M1-M7 (owner-approved):** **M1** the record's
mobile must equal the OTP mobile; **M2** a record with no national ID is never auto-linked; **M3** linking only in the clinic whose record matches both;
**M4** limits per account/mobile/IP, **never** on the national ID, which appears only in a request body (never URL, log, audit, rate-limit key, notification,
exception); **M5** every failure returns the identical status/body/message (no oracle, **no timing claim**); **M6** on success an SMS to that mobile plus one
audit row with ids/closed codes; **M7** every additional patient needs its own proof. **Six safeguards:** the five threats (§14) plus the squat rule.

### 6.2 Link-record: one clinic per proof (R2, R3)
`POST /patient/link-record {mobile, code, national_id}` — level `booking`, registered only in the PR that ships the gate. The search runs **only inside the
single clinic of the current request's scope**: resolve that clinic, consume the OTP and charge the attempt (§6.5), then `SELECT … FROM cpms_patients WHERE
clinic_id = %d AND mobile = %s AND national_id = %s AND status='active' FOR UPDATE` (§6.4). `u_pat_mobile` (`0001:82`) makes that **at most one row**; no row
⇒ generic failure. Those three values are the only inputs, so **a match in another clinic is not visible here and cannot produce an "exactly one across
clinics" failure** — it is a separate proof there, reached by switching scope. One row ⇒ write the binding (§6.3); for a legacy `identity_id IS NULL` row,
create/reuse the Organization identity and edge in the same transaction (`PatientIdentityActivator.php:77-107`), verified before commit. **Scope transition
(R3):** `App::replaceExplicitScope()` sets a per-request static only (`ScopeContext.php:19-41`, `App.php:2285-2293`), not `PatientSessionScope`. The marker is
stamped at OTP cookie issue — `begin_otp_cookie_issue()` `:132-142` arms it and `set_logged_in_cookie` writes `cpms_clinic_id` + the link snapshot
(`:534-584`) — then read back at `:238-252`, with `require_clinic_matching` `:347-358` throwing on mismatch. **DECIDED:** the OTP login following a proof
stamps the clinic it proved; an unstamped session with no link resolves `null` (`:261-277`) and **fails closed**. A stamp is only a snapshot and grants
nothing — the §6.3 binding row is still required.

### 6.3 Verified binding — the only two setters (R1, R5)
A binding is **one `cpms_patient_logins` row with `verified_at IS NOT NULL`** for a `(wp_user_id, clinic_id, patient_id)` triple, created **only** by (i) §6.2
two-factor success (`two_factor`) or (ii) reception in-person approval (`reception`: staff capability + explicit patient pick + its own audit row). Every
record, booking-list, notification and prescription predicate requires that row **in addition to** existing checks (§2.3). Because the row is keyed by
`clinic_id`, reuse of an Organization identity gives **no** access in a second clinic without that clinic's own proof. **One live account per record per
clinic (R5):** not enforced today — `u_link_pair (patient_id, wp_user_id)` (`0001:97`) and `u_pil_pair (identity_id, wp_user_id)` (`0019:65`) both include
`wp_user_id`, so a second account can link the same patient. Enforcement: `SELECT … FOR UPDATE` on `cpms_patients`, a live-binding re-read for `(clinic_id,
patient_id)`, **generic** refusal if another account holds one. **A unique key inside `cpms_patient_logins` cannot express it:** many unbound rows per patient
must coexist, and a plain `UNIQUE (clinic_id, patient_id)` would not block a second *bound* row either because MySQL treats NULLs as distinct
(dev.mysql.com/doc/refman/8.4/en/create-index.html). So lock + recheck, **INFERENCE, not executed**.

### 6.4 Concurrency
`FOR UPDATE` on the candidate patient row, then re-check tenant, `status='active'`, `mobile`, `national_id`; only then write the binding. **Sever** and
**reception approval** take the **same** row lock, so link, sever and approval serialise on one row. Tests: concurrent NID edit, concurrent sever, competing
accounts (§15).

### 6.5 Consume runs at depth zero (R6)
The OTP consume lives **inside** `verify()`'s transaction (`OtpService.php:449`…`:527-534`) and would roll back with a failed link. The link route therefore
runs **consume + attempt charge + decision** in a unit that must execute at **transaction depth 0**, checked before it runs; a nested caller **fails closed**
(neutral 503, no consume). Depth 0 means `CpmsDb::transactional` issues `START TRANSACTION` and a real `COMMIT` (`:279-304`), so **the consume commits before
any binding row can exist**. Limits: per account, per mobile **across all accounts**, per IP; keys hashed; the NID is never a key; `cpms_rate_limits` already
exists. Tests T-L8, T-L15. **No schema change** beyond the accessor (§3, P1).

### 6.6 Audit free text — inventory by search term (R7)
Term `git grep -o "audit->log(\|audit()->log("` over `src`: **108 call sites in 37 files**. Free-text carriers, each re-opened in current `main`:
`BookingService.php:1057-1070` (patient-typed `reason` via `appointmentView` `:3367-3383`), reschedule `:1732-1755`, no-show `:2058-2069` (`['reason' =>
$trimmed]` `:2067`), `ClinicalService.php:972-982` (`:980`), `FinanceService.php:647-666` (`:665`) and `:770`, `PatientService.php:472-482` `PATIENT_CREATED`
via `staffView()` raw names/NID (`:779-795`), plus `MedicalFileService.php:1166`, `HandwritingService.php:731,748,774,810`, `LocationService.php:461`,
`StaffMessagingService.php:143`, `ClinicalService.php:2070`. `AuditLogger::sanitize()` **does** recurse into arrays (`:253-255`), so a nested `national_id`
key **is** masked; but masking is exact-key and **case-sensitive** (`MASK_KEYS` `:26`, applied `:249`) while `FORBIDDEN_KEYS` is case-insensitive (`:246`),
and a `reason` string holding an NID passes through. **P2 rule (owner):** replace these payloads with closed codes, booleans and bounded lengths (the existing
≤255 `mb_substr` is **not** sanitising); **no regex sanitising of free text**. Historical rows are immutable and **not** rewritten; their contents are
**UNKNOWN**.

### 6.7 Notifications, per verified binding (R4)
For `pending`, no-row, revoked and **unbound** `active` accounts every notification read **and** write endpoint returns one fixed empty envelope with a
**constant ETag**, computed **before** any `lastId`/ETag/304 work: `GET /notifications` `NotificationsController.php:34-49` (guard `:42-43`); `POST
/notifications/read` `:51-71` (`:64-65`); `GET /rt/notifications` `:74-85` (`:78-79`) — gated **before** `lastId` `:104`, the 304 branch `:106-111` and
`since()` `:113`. When the account **is** bound, the payload is filtered to patients with a binding for the request's clinic:
`NotificationService::active_linked_patient_ids_in_clinic()` `:465-477` collects every booking-linked patient in the clinic (`:470-477`) and must intersect
the binding set for **inbox, cursor, counts and writes**. §12 states the same. Test: `If-None-Match` + real ETag ⇒ fixed envelope.

### 6.8 Shared mobile — DECIDED: one patient per mobile per clinic
`u_pat_mobile` UNIQUE (`0001:82`) and `mobile NOT NULL` (`0001:60`): a shared number serves **one patient per clinic** in the first release, **no schema
change**; the others use reception. Identities carry a non-unique `normalized_mobile` (`0019:44-53`) and no NID (`0018:27-36`), so the matcher cannot run at
Organization level. Several patients per mobile in one clinic is a **next-version** item (§18).

### 6.9 Staff fallback and sever
Reception creates via `ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`, can set a checked national ID, and can **approve a
binding** — the second and last setter (§6.3) — under the §6.3/§6.4 patient-row lock. **Sever** deletes the binding row (optionally the login row) + audit,
takes the same lock, and destroys patient sessions (`WP_Session_Tokens::destroy_all`). Reception views use `searchView` `:245-257`, `:831-844` →
`to_search_view` `:823-825`; add `login_state`; no NID beyond the existing mask. **"Pending registrants must first visit reception" is removed** — a `pending`
account proves itself through §6.2.

## 7. Lifecycle

### 7.1 Table existence
`App::is_runtime_blocked_by_pending_schema()` `App.php:622-639` returns `false` for a supported engine (`:626-628`) and for unreadable evidence (`:630-634`) —
it reports **pending migrations**, not a missing table. The gate does its own cached-per-request existence check and fails closed (`none` + neutral refusal).

### 7.2 Restore barrier
The dump set is `SHOW TABLES LIKE '{prefix}cpms_%'` (`BackupSqlDumper.php:113-126`, `dbPrefix()` `:85-88`), so the **option** `cpms_restore_barrier` is
outside it and survives a restore. Set before the restore; while set every patient level is `none`. **UNKNOWN:** `restoreApply` `:324-390` has **no** pre-drop
hook — the build PR must add one with a kill-between-drop-and-recreate test.

### 7.3 Restore closes every binding (R10)
Clearing the barrier is one manager action that destroys patient-only sessions, writes one audit row, and **deletes every restored binding row** — no restored
binding is trusted, so every account returns to `booking` and needs a fresh §6.2 proof or individual reception approval. A restored revoked account cannot
silently regain access. This is the PR #358 decision D-B coordinate; that PR and `docs/architecture/backup-restore-design.md` **do not exist in this tree at
`c9771403`**, so the requirement is recorded here and not claimed as met.

### 7.4 Backups and uninstall
Backups already contain every patient NID from `cpms_patients.national_id`; **encrypting the backup is a requirement of the backup work (PR #358 D-B), not a
fact of this tree** (UNKNOWN/LIMITATION, §7.3). The 14-day scrub limits the live window only. **Uninstall (`uninstall.php:8-20`):** unset ⇒ `return` at
`:9-11`, nothing deleted, login rows and bindings survive a reinstall; set ⇒ every `{prefix}cpms_%` table dropped (`:15-18`) plus two options (`:19-20`), so
login rows, bindings and identities are destroyed, **patient sessions are not explicitly destroyed** (LIMITATION — the build must add it), and patient
`wp_users` are **not** deleted.

### 7.5 Retention
Live rows are deleted only by revocation. At decision: names and NID → NULL. At 14 days a still-unbound row is scrubbed and deleted, its WP user only when it
has no link and no appointment. Sweep host: a **new** `cleanup.patient_logins` job (`App::RECURRING_JOBS` `:2616-2635`; existing `cleanup.*` `:2625-2628` each
own another table, `OtpCleanupHandler.php:22-25` only `cpms_otp_tokens`). A stalled sweep is a residual (§16).

## 8. Cap and lock order (R8)

Cap = 2 active appointments per account (DECIDED), counted across all patients linked to the account by joining `cpms_patient_user_links` (never
`cpms_appointments.wp_user_id`, `0001:230`, which `createByStaff` `:1935-1954` does not populate). The locking read runs in the **outermost** transaction:
login row `FOR UPDATE` → `COUNT(*)` of future non-cancelled appointments. Depth guard: fail closed if nested (§6.5). **Staff bookings are exempt.**
**Reschedule inserts a row (R8, correcting revision 8):** `reschedule()` calls `appointments->create()` `BookingService.php:1720-1741` — replacement,
`status='confirmed'`, `rescheduled_from` set (`:1735`) — and only then `updateStatus()` on the source (`:1743-1750`). The source leaves the active set and the
**replacement enters it**, so (i) a pending account's reschedule **consumes cap capacity**, counted as **one** by excluding rows with a non-NULL
`rescheduled_to` (`:1747`); (ii) reschedule **does** take the login lock — revision 8 said otherwise and was wrong. **Lock order:** patient hold/confirm login
→ Organization → Clinic → Slot → Location (`:1881-1886`, `:2750-2790`); reschedule appointment-first (`:1568-1574`); `createByStaff` tenant→Slot→Location
(`:1805-1935`). The only new edge is "login before tenant prefix" ⇒ no cycle (INFERENCE).

## 9. Abuse limits

`Settings::DEFAULTS` (`Settings.php:28-40`) are configurable defaults (`get()` `:207`), not ceilings. Per mobile 3/day, 10/hour, 60 s cooldown, 900 s lockout
after 5 bad codes; `/otp/request` per IP 10/hour; `/otp/verify` per IP 20/3600 hard-coded (`OtpService.php:392`). `/patient/register` needs a consumed OTP
plus hashed buckets. `/patient/link-record`: per account, per mobile across all accounts, per IP (§6.5). Existing debt not copied: `OtpService` keys
`otp-day:`/`otp-hour:` on the **raw** mobile (`:274-275`, `:622-629`).

## 10. Dependents

Several patients per account already work (`linkedActivePatientsForClinic` `BookingService.php:3057-3063`); `>1` link makes the hold refuse to guess (`:3121`) with an optional `patient_id` (`BookingController.php:90`) validated by `assert_requested_patient_owned_by_caller` `:3131-3153`. Dependents are **in** the first release. **OQ-D1** — a dependent sharing the guardian's mobile in the same clinic — stays a **LIMITATION**: one patient per mobile per clinic (§6.8). A guardian's proof never inherits; each dependent needs its own (M7). `pending` accounts create no dependents; the cap counts parent + dependents; per-link access; no age limit; never merged with a walk-in. Negative test §15 T-L13.

## 11. Rollout

| PR | Contents | Binding settable? | Record access |
|---|---|---|---|
| **P1** | table `cpms_patient_logins` (+ binding columns), existence check, restore barrier + hook, **public tx-depth accessor**, uninstall session destruction | no | no |
| **P2** | audit redaction (§6.6) | no | no |
| **P3** | gate on **all** routes by actor branch + service-layer checks + runtime-registry test + `:261-269` predicate replacement (§2.3) | no | **no** |
| **P4** | SMS login issuance (cookie at depth 0) + clinic stamping (§6.2) | no | no |
| **P5** | cap + first-booking **creation only** (§5) | no | no |
| **P6** | **binding**: `/patient/link-record` + reception approval + sever (§6.2-6.5, §6.9) | **yes** | **yes**, per binding |
| **P7** | public `/patient/register` | yes | per binding |
| **P8-P10** | UI journeys (incl. dependents, M7), browser lanes, acceptance evidence | yes | per binding |

**Invariants:** sessions (`P4`) and registration (`P7`) both come **after** the gate (`P3`); no binding before `P6`; no `pending` row before `P7`. "Attach SMS
login" ships inside the gate PR. Minutes (INFERENCE incl. review): P1 110 · P2 60 · P3 260 · P4 100 · P5 180 · P6 260 · P7 150 · P8 200 · P9 120 ⇒ **≈1 440
implementation + 800–950 review ≈ 2 250–2 400**.

## 12. State matrix

`✗` refused · `—` not routed · `✓` allowed; `booking` = live row, **no binding**. This is the route enumeration §2.4 refers to.

| Route / branch | no row | pending | active unbound | active bound | deleted | password-only | staff | anon |
|---|---|---|---|---|---|---|---|---|
| `/booking/hold` | ✗ | ✓ + create | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `/booking/confirm` | ✗ | ✓ cap | ✓ cap | ✓ cap | ✗ | ✗ | — | ✗ |
| `/booking/resume`, `/appointments/mine`, `…/reschedule-options` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `…/reschedule`, `…/cancel` | ✗ | ✓ cap | ✓ cap | ✓ cap | ✗ | ✗ | staff branch | ✗ |
| `GET /patient/me` | ✗ | minimal | minimal | full | ✗ | ✗ | — | ✗ |
| `PATCH /patient/me` | ✗ | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `POST /patient/link-record` | ✗ | ✓ own proof | ✓ own proof | ✓ idempotent | ✗ | ✗ | ✗ | ✗ |
| `/patient/enroll`, `/patient/my-records`, `/visits*`, `/prescriptions` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | staff path | ✗ |
| `/patients/{id}/files`, `/files/{id}/stream` | ✗ | ✗ 403 | ✗ 403 | ✓ own | ✗ | ✗ | `MedicalFileService:219-250` | ✗ |
| `/notifications`, `/notifications/read`, `/rt/notifications` | ✗ | fixed empty | fixed empty | ✓ bound only | ✗ | ✗ | staff branch | ✗ |
| portal render | ✗ | appointments | appointments | full | ✗ | ✗ | — | ✗ 403 |
| `/patient/register` (P7+) | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✗ | ✓ 202 |

**The only cell that matters: `active unbound` reaches no record route and no notification endpoint** — a fixed empty envelope, never a 304. **Login oracle**
(`/otp/request` always sends, 200): unknown mobile + valid code ⇒ `202 register_required`, no cookie; live row ⇒ 200 + cookie, still only `booking` unless
bound; staff/ineligible or wrong code ⇒ the generic invalid-code envelope. `register_required` is distinguishable only to a caller who has proved possession
of that mobile (owner-permitted); record access is never signalled at login — the point of R1.

## 13. Self-audit (Phase 2 of revision 9)

- **(a) Leak paths.** The revision-8 blocker — first booking creating the rows that *were* the binding — is
closed: the binding is a `cpms_patient_logins` row with `verified_at`, written only by §6.3's two setters. Checked every writer that can touch a patient row:
`PatientService::create` `:431-455`, `activate_identity` `:561-576`, `PatientIdentityActivator` `:77-107` (reuse at `:93`),
`PatientIdentityService::link_user` `:248-280`, `link_clinical_record` (`:101`), reschedule `:1720-1750`, `createByStaff` `:1935-1954` — **none** writes
`cpms_patient_logins`. Wrong clinic: the query is `WHERE clinic_id = <scope>` (§6.2). Wrong patient: `u_pat_mobile` makes it one row. Notifications: gated
pre-ETag, intersected with the binding set (§6.7).
- **(b) False file:line.** All re-opened with `git show origin/main:` at `c9771403`; corrections this revision:
§2.3 predicates, §6.5 depth facts, §6.6 inventory, §8 reschedule, `AuditLogger` recursion and case.
- **(c)-(e) Ordering, buildability, claim words.** Gate P3 < sessions P4 < binding P6 < registration P7 (§11);
no STOP item, the binding fits the approved table and P1 adds the accessor; `only`/`never`/`cannot` are traced to the cited line, `template_redirect` stays
**NOT PROVEN COMPLETE**, and the one-clinic search is a property of the query, not of the data.

## 14. Security: threats

| Threat | Mitigation | Residual (accepted) |
|---|---|---|
| **Recycled number** | NID must also match (M1/M3) | New subscriber who also knows the old owner's NID can link; reception severs |
| **SIM swap/theft** | NID still required; mismatch links nothing | M6's SMS goes to the attacker's number — a weak warning only |
| **NID known to relatives/documents** | Mobile possession still required | A relative with both can link — the authority documents already give |
| **NID brute force after SIM compromise** | Limits per account/mobile/IP, never the NID (M4); identical failures (M5) | Bounded only by configurable `Settings` (§9); windows UNKNOWN |
| **Squatting a national ID** | `u_pat_nid (clinic_id, national_id)` UNIQUE (`0001:83`) + `assert_unique_national_id` (`PatientService.php:850-857`) | A squat **can** block the real patient in that clinic and M2 refuses auto-link; resolution is manual and audited |

## 15. Tests

| # | Case | Expected |
|---|---|---|
| T-L1 | matching mobile + NID in the scoped clinic | binding written, `full` next request, M6 SMS without NID, audit ids only |
| T-L2 | wrong NID | no binding, identical envelope to T-L3/T-L4 |
| T-L3/T-L4 | NID right but record mobile ≠ OTP mobile (M1); record has no NID (M2) | no binding, identical envelope |
| T-L5 | same mobile, two clinics | each proof binds only in its clinic; **no cross-clinic "exactly one" failure** |
| T-L6 | `active unbound` hits every record route + 3 notification endpoints | 403 / fixed empty; `If-None-Match` with a real ETag ⇒ fixed envelope, no 304 |
| T-L7 | limits: per account, per mobile across accounts, per IP | identical envelope; keys hashed; no NID in `cpms_rate_limits` |
| T-L8 | failed link | OTP **not** restored, counters **not** reset (§6.5) |
| T-L9 | concurrent NID edit; concurrent sever; competing accounts | one outcome, no orphan binding, generic refusal |
| T-L10 | audit payloads after success/failure | no NID, no mobile, no name, no free text |
| T-L11 | reception sever | access drops to `booking`, sessions destroyed, audit row |
| T-L12 | pending account self-proves | succeeds without visiting reception (§6.9) |
| T-L13 | dependent with a distinct mobile; guardian proof | own proof required (M7); guardian's proof never inherits |
| T-L14 | restore then re-establish (§7.3) | before proof `booking`; after proof `full`; restored bindings deleted |
| T-L15 | link called from inside a transaction (§6.5) | fails closed, no consume, no binding |
| T-L16 | first booking / registration / identity reuse | **no** `verified_at` row appears (§2.3) |

## 16. Weakest points, open decisions, STOP items

**Weakest:** no unique key can enforce one live account per patient, so lock + recheck is INFERENCE (§6.3); the depth-0 rule needs a `CpmsDb` accessor that does not exist yet (§3); NID is not a secret and a squat blocks a real patient's NID while M2 refuses auto-link; plaintext pending NID is live **and in backups**, whose encryption is only a requirement of absent work (§7.3/§7.4); `restoreApply` has no pre-drop hook, `uninstall.php` does not destroy sessions, the 14-day sweep has no host, and `identity_id IS NULL` rows stay readable by legacy predicates until P3 lands (DEBT).

**Open:** **OQ-D1** dependent sharing the guardian's mobile (LIMITATION, §10) · **OQ-D2** stalled-sweep warning · **OQ-D3** per-account vs per-link cap (per link assumed) · **OQ-D4** "attach login" mandatory forever · **OQ-D5 closed** · **OQ-D6 DECIDED** (§6.8) · **OQ-D7 closed by deletion** (§6.2).

**STOP / UNKNOWN:** **none blocking the first release.** No schema beyond `cpms_patient_logins` is required; a binding column on `cpms_patient_user_links` later would be **"STOP - needs owner decision: schema"**, not to be designed around.

## 17. What revision 9 changed

The binding moved into the approved table with two setters only (R1); one-clinic search and the scope/stamp mechanism (R2/R3); gating before ETag with binding-filtered payloads (R4); one-live-account by lock and recheck, with the unique-key impossibility stated (R5); depth-zero consume (R6); the audit inventory by search term (R7); the reschedule insert corrected and the cap restated (R8); the collision flag is deleted rather than stored (R9); restore, rollout and cross-references repaired (R10/R11).

## 18. Next version (not this release)

Several patients per mobile in one clinic — blocked by `u_pat_mobile` (`0001:82`) and `mobile NOT NULL` (`0001:60`); needs its own design and owner approval
before any schema change. Dependent self-service beyond §10 follows the same design. No column or table is proposed here.
