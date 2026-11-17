# Patient Identity, SMS Login and Self-Registration — DESIGN ONLY (revision 8, final)

> # ⛔ DESIGN ONLY — MERGE IS NOT PERMISSION TO BUILD
>
> A proposal. Merging does **not** authorise a build; the build starts only after this PR is merged and two
> independent readers pass. The one table `cpms_patient_logins` is **owner-approved**; **any other new table
> or column needs separate owner approval — stop and ask.** No code, migration, test or workflow here.

| | |
|---|---|
| **Base** | `origin/main` = `c97714034e62b001a313ee6636b25d7bece9d374` |
| **Prior head** | `63469b6935c0017f96b88c092ab8f7945f0d4610` (revision 7) |
| **Labels** | **OBSERVED** read in code at this commit, not executed · **INFERENCE** · **UNKNOWN** · **LIMITATION** · **DECIDED** · **DEBT** |
| **Not executed** | No PHP/MySQL/WordPress/browser run; PHP gates NOT APPLICABLE (docs-only). |

## Revision 8 changes

| # | Change |
|---|---|
| **DOC** | Sections 16 and 17 are **actually present** (revision 7 silently lost them; §18 records the correction). |
| **R1** | Per-record binding replaces "state = full": records readable **only** through a durable verified binding per (account, patient, clinic) created by the two-factor proof or staff approval; all record read paths enforce it (§2.3, §2.4, §6.3, §12). |
| **R2/B1-B2** | First booking: clinic from the persisted slot only, names from the **validated request body**, organization identity edge created in the same transaction; mobile collision → uniform refusal naming the two-factor link (§5). |
| **R3** | Candidate clinics found by mobile+national ID across the caller's Organization, not only already-linked clinics; ≤1 candidate per clinic; NULL-identity legacy rows get an identity row created atomically (§6.2). |
| **R4** | Binding takes `FOR UPDATE` on the candidate patient row and re-checks tenant/status/mobile/NID; sever takes the same lock; two concurrency tests (§6.4). |
| **R5** | Limits per account, per mobile **across all accounts**, and per IP; OTP consumption and failure charging commit **before** the link transaction (§6.5). |
| **R6** | Audit free-text inventory (108 `audit->log` call sites / 27 files) with search terms; P2 redacts booking create/reschedule/cancel and `PATIENT_CREATED`; `AuditLogger` masks only the exact `national_id` key (§6.6). |
| **R7** | One notification policy: all three endpoints gated **before** `lastId`/ETag/304, fixed empty envelope with a constant ETag (§6.7, §13). |
| **R8** | Restore clearing never revives bindings; encrypted backups per the (absent) backup design flagged; uninstall behaviour defined (§7.4). |
| **Owner note** | Shared mobile = **Option A decided deferral** for the first release; multi-patient-per-mobile is a next-version item needing its own design and approval (§6.8, §19). |

---

## 1. Data model (D1)

One table, `{wp_prefix}cpms_patient_logins`:

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` PK | |
| `wp_user_id` | `BIGINT UNSIGNED NOT NULL` | no FK (none to `wp_users` exists) |
| `mobile` | `CHAR(11) NOT NULL` | normalised `09xxxxxxxxx` |
| `state` | `ENUM('pending','active') NOT NULL DEFAULT 'pending'` | **not** an access level (§2.4) |
| `given_name`, `family_name` | `VARCHAR(120) NULL` | scrubbed on decision / at 14 d |
| `national_id` | `VARCHAR(10) NULL` | pre-verification only; scrubbed |
| `hold_reason` | `ENUM('mobile_reuse','clinic_mobile_exists','nid_exists','other') NULL` | |
| `collision_clinic_id` | `BIGINT UNSIGNED NULL` | **recommend delete, owner decides** (§6.9) |
| `verified_by_wp_user_id`, `verified_at` | nullable | staff approval only |
| `created_at`, `updated_at` | `DATETIME(3) NOT NULL` | UTC |

`UNIQUE u_pl_mobile (mobile)`, `UNIQUE u_pl_user (wp_user_id)`, `KEY (state, created_at)`, `KEY (national_id)`.
Revocation = DELETE + one audit row in one transaction; history lives in `cpms_audit_logs` (`clinic_id`, `actor_wp_user_id`, `actor_role`, `action`, `patient_id`, `ip_hash`, `before_json`/`after_json`/`meta_json`, `0001:676-701`). Delete/insert serialises on `u_pl_mobile` in one transaction (dev.mysql.com/doc/refman/8.4/en/innodb-locking.html). **UNKNOWN:** not executed on MariaDB 11.4.13.

**The binding is NOT a new table** (R1): it is expressed on existing rows — the clinic link `cpms_patient_user_links` (`clinic_id`, `patient_id`, `wp_user_id`, `mobile_at_link`, `is_primary`, `linked_at`; `u_link_pair (patient_id, wp_user_id)` `0001:91-97`) **plus** the Organization edge `cpms_patient_identity_links` (`organization_id`, `identity_id`, `wp_user_id`, `mobile_at_link`, `is_primary`, `linked_at`; `u_pil_pair (identity_id, wp_user_id)` `0019:54-73`) **plus** `cpms_patients.identity_id` set. No column is added; a "binding verified" flag would be a **STOP-for-owner** item and is **not needed**: the binding exists iff the patient row has a non-NULL `identity_id` **and** both link rows exist (§2.4).

## 2. Access model

### 2.1 One gate
`PatientAccess::level(int $wp_user_id, ?int $patient_id = null): string` → `none` | `booking` | `full`, re-read from the database on **every** request (never a cookie). No live login row ⇒ `none`. `booking` = a live row **without** a verified binding for the target patient. `full` = a verified binding for **that** patient (§2.4). **An `active` account with no binding is `booking`** — state is never an access level.

### 2.2 Recount (revision 5 method, unchanged)
137 `register_rest_route` sites / 18 controllers; blocks parsed whole and every `permission_callback` callee resolved to its body ⇒ 153 runtime routes; **18 patient-reachable sites / 20 runtime routes** (14 patient-only + 5 shared + `FilesController.php:60`). Loop expansion: `DoctorPortalController.php:82-91`, `QueueController.php:217-225`, `PatientController.php:64-93`, `FilesController.php:72-95`. A staff-role user is never patient-only (`Admin/PatientPortalPage.php:92-111`, `:116-124`) and is never eligible for patient SMS login; shared routes are gated per **actor branch**.

### 2.3 Which predicates change (R1)
`PatientRepository::clinical_access_identity_predicate()` `:261-269` currently reads `AND (p.identity_id IS NULL OR EXISTS (… identity link …))`. **`p.identity_id IS NULL` is the hole**: a legacy-shaped row is readable with a clinic link alone. The build changes it to require the identity edge unconditionally, i.e. drop the `IS NULL` disjunct, and adds the binding requirement to every caller — `active_linked_records_for_user` `:85-99`, `active_linked_patients_for_user` `:164-177`, `find_active_linked_patient_by_link` `:182-...`, and the single-patient selector at `:145-156` (the four call sites at `:95`, `:151`, `:173`, `:191`). **DEBT:** until that change ships, the gate (§2.4) is the only thing keeping such rows closed, which is why no PR before P6 grants records (§12).

### 2.4 Levels per route/branch
| Level | Routes |
|---|---|
| `booking` | `/booking/hold` `BookingController.php:80`; `/booking/confirm` `:96`; `/booking/resume` `:115`; `/appointments/mine` `:126`; `/appointments/{id}/reschedule-options` `:138`; `/appointments/{id}/reschedule` patient branch `:156`; `/appointments/{id}/cancel` patient branch `:198`; `GET /patient/me` `PatientController.php:64-71` (**minimal login-row view only**); portal page render (`Admin/PatientPortalPage.php:261-265`) appointments only |
| `full` (**requires a verified binding for that patient**) | `/visits` `ClinicalController.php:167`; `/visits/{id}` `:185`; `/prescriptions` `:199`; `/patients/{patient_id}/files` `FilesController.php:72-95`; `/files/{id}/stream` patient branch `:60`; `/patient/enroll` `PatientController.php:38-49`; `/patient/my-records` `:52-62`; `/patient/me` full view; `PATCH /patient/me` |
| `booking`, refused writes | `PATCH /patient/me` `PatientController.php:73-92` → 403 for `booking` |
| unchanged | every staff branch and staff route |

Service-layer enforcement (so direct service calls are covered too): `PatientService::me()` `:128`, `updateMe()` `:139`, `linked_records()` `:99-112`, the `my_records` path (`PatientController.php:52-62`), `NotificationService::inbox()` `:155-184` and `since()` `:191-...`, `Admin\PatientPortalPage::render()`. For `booking`: minimal view, 403 on writes, `[]` records, empty notification envelope. **DEBT (untouched):** `active` users can still self-edit `national_id` and read it unmasked via `publicView` `:779-795`; scheduled with the identity batch.

### 2.5 Non-REST surfaces
| Surface | Search term | Count |
|---|---|---|
| `admin_post_*` | `git grep -o "admin_post_[a-z_]*"` over `src` | 51 occurrences / 46 distinct |
| `wp_ajax_*` | `git grep -c "wp_ajax_"` | 0 |
| shortcodes | `add_shortcode` | 1 (`PublicBookingShortcode.php:219`) |
| `template_redirect` | `git grep -n template_redirect` | 9 hits: `RuntimeSchemaGate.php:42`, `DoctorPortalShell.php:30,31`, `ManagementPortalController.php:80`, `ManagementPortalShell.php:64`, `PatientPortalShell.php:50`, `StaffPortalShell.php:194`, plus 2 comments (`DoctorPortalShell.php:185`, `ManagementPortalController.php:8`) — **NOT PROVEN COMPLETE** |

Registry self-check must enumerate `rest_get_server()->get_routes()` at runtime (a source regex cannot see loop-expanded routes) and fail on any route with no level.

## 3. Login, transaction depth, cookie

Normalise → limits → verify + atomic consume (`OtpService.php:527-534`). Candidate read inside the existing transaction (`:449` → `:575`). 0 live rows + valid code ⇒ `202 register_required`, no cookie. **Cookie:** `CpmsDb::transactional` turns nested calls into savepoints (`:279-285`, release `:300-304`), so issuance requires `transaction_depth() === 0` (new accessor; private field `:31`) or it fails closed with a neutral 503 and no cookie. `remember=false` (today `true`, `:595`); 8 h via `auth_cookie_expiration` for patient-only users only.

**Fresh `verify_mobile` code after a consumed login code:** the claim "60 s cooldown" is **wrong**. State is keyed by (mobile, **purpose**) (`:232`, `:658-672`) and `OtpPolicy::canSend` applies (`:234`), so a fresh `verify_mobile` code does not inherit the `login` cooldown; the real bound is the per-mobile daily/hourly charge (`:274-275`). INFERENCE: concurrency interleaving governed by the guard row `:200-211`.

## 4. Registration

`POST /patient/register` — registered **only** in the last functional PR (§12). Consume a `verify_mobile` OTP (`:39`); create exactly two rows (WP user with random login + unusable password, pattern `ManagementPortalService.php:1551-1558`; one `pending` login row). **No patient row, no link** (clinic unknown; `cpms_appointments.patient_id NOT NULL` `0001:226`). Live-mobile collision ⇒ nothing created, `hold_reason` set. Identical `202` on every branch; every branch leaves a queue entry. **Queue = the login table**, not `cpms_notifications` (`clinic_id NOT NULL` `0001:634`; that table is purged by `NotifDispatchHandler.php:39-41` → `NotificationRepository::purgeArchived` `:232-242`).

## 5. First booking (R2, B1-B2)

Zero-link failure chain (OBSERVED `BookingService.php:556-557`, `:3097-3122`, `:3014-3038`, `:567-569`).

**(a) Clinic.** Only the owner of the **persisted slot**: `requireClinician` `:2898-2909` → `clinic_for_persisted_slot` `:2848-2882`; a body `clinic_id` is never read (`:515-518`). For **this request only** the server sets the scope context from that clinic (`App::replaceExplicitScope(ClinicScope::forClinic(...))`, the pattern at `PatientService.php:351`), which is **different from** `PatientSessionScope::require_clinic_matching` `:347-358` → `resolve_clinic_id` `:261-277`, which derives the clinic from a stamped marker **or from existing links** and therefore cannot serve a zero-link account. The request-scoped value is reverted in a `finally`.

**(b) Names (B1).** From the **validated request body**, not from login fields: `PatientService::CREATE_FIELDS` `:52-57` with `validate_for_update(..., true)` `:420` (trim, bidi/invisible rejection). The login row's names are only a fallback for a reception-provisioned account and are never trusted as patient identity.

**(c) Identity edge (B2).** `PatientService::activate_identity()` `:561-576` → `PatientIdentityActivator::activate_for_new_patient()` `:77-95` creates/finds the Organization identity and the `cpms_patient_identity_links` row, and sets `cpms_patients.identity_id`; **any failure rolls the whole creation back** (pattern `PatientService.php:431-455`). This is mandatory because `PatientRepository.php:261-269` depends on it.

**(d) Mobile collision.** `u_pat_mobile (clinic_id, mobile)` UNIQUE (`0001:82`): if the clinic already holds this mobile on another row, create **nothing**, link nothing, and return the **same generic refusal** as the link route (§6.2): "to reach your record, verify it with your mobile code and national ID". **Disclosure, stated honestly (owner-visible risk):** someone who controls the mobile learns that this clinic holds a record for that number. There is no timing claim. All generic refusals share one status, body and message (§6.4).

**(e) Idempotency / cap.** A second hold finds the link and creates nothing; the login-row lock is taken first (§9). The cap applies only to patient-initiated creation (§9).

**What first booking does NOT do:** it never grants record access. The link it creates is booking-only (§2.4); records need the binding of §6.3.

## 6. The two-factor proof and binding

### 6.1 Owner policy (final)
A patient is authenticated by **both** (1) a mobile receiving a valid SMS OTP and (2) a national ID matching the record. Both match ⇒ `full` for **that** record; in-person approval is **not** a precondition. Mobile alone never grants records; mismatch creates nothing; shared mobile never merges; bounded by Organization/Clinic/Location.

**Defaults M1-M7 (owner-approved):** **M1** the record's own mobile must equal the OTP mobile; **M2** a record with no national ID is never auto-linked; **M3** linking only inside the clinic whose record matches both; **M4** limits on account/mobile/IP, **never** on the national ID, which appears only in a request body (never URL, log, audit, rate-limit key, notification); **M5** every failure returns the identical status/body/message (no oracle, **no timing claim**); **M6** on success an SMS to that mobile ("your record was linked to an account", no NID), one audit row with ids/closed codes, and reception can sever (audited); **M7** every additional patient needs its own proof.

**Six safeguards:** five threats with mitigations (§15) plus the squat rule (§15).

### 6.2 Endpoint, candidates, and legacy rows (R3)
`POST /patient/link-record {mobile, code, national_id}` — level `booking`, registered only in the PR that ships the gate.

1. Consume the OTP (§6.5) and require the login row to exist for the caller.
2. **Candidate clinics (R3):** across the **caller's Organization(s)** — resolved from the account's clinic links **and** from the identity rows already linked to the account (`cpms_patient_identity_links`, `0019:54-73`) — search `cpms_patients WHERE clinic_id = c AND mobile = %s AND national_id = %s AND status='active'`. Not only already-linked clinics. Each clinic yields **at most one** candidate (`u_pat_mobile` `0001:82`); more than one surviving candidate across clinics ⇒ the generic failure.
3. **Legacy NULL-identity row (R3):** if the single candidate has `identity_id IS NULL`, create the Organization identity and the `cpms_patient_identity_links` row **inside the same transaction** via `PatientIdentityActivator::activate_for_new_patient()` `:77-95` with `verified_wp_user_id` set, and set `cpms_patients.identity_id`; every step is verified before commit, and any failure rolls all of it back. No new table/column; the activator already supports this (`resolve_verified_identity` `:156-176`, `create_identity` `PatientIdentityService.php:48-62`).
4. Exactly one candidate ⇒ write the binding (§6.3) and promote the login row to `active` if it was `pending` **and** staff-attached.

### 6.3 The binding (R1)
A binding for (account, patient, clinic) exists iff all three hold: `cpms_patients.identity_id` is non-NULL, a `cpms_patient_identity_links` row exists for (identity, account) in that clinic's Organization, and the `cpms_patient_user_links` row exists for (patient, clinic, account). `full` requires it; a booking-only clinic link has no identity edge and therefore stays `booking`. **Consequence:** the national ID is never promoted to the Organization identity (identities carry no NID: `0018:27-36`, `0019:44-53`), so a second clinic needs its own proof (M3/M7) — conservative and needs no schema change.

### 6.4 Concurrency (R4)
Inside one transaction: `SELECT … FROM cpms_patients WHERE id = %d FOR UPDATE` on the candidate, then re-check tenant (`clinic_id` + Organization), `status='active'`, `mobile`, `national_id`; only then insert the links. **Sever** (`DELETE` of both link rows + audit) takes the **same** row lock first. Tests: (i) a concurrent national-ID edit between candidate read and link ⇒ link aborts, generic envelope; (ii) a concurrent sever during link ⇒ exactly one outcome, no orphan link.

### 6.5 Limits and rollback survival (R5)
The OTP consume lives **inside** `verify()`'s transaction (`:449`…`:527-534`) and would therefore be **rolled back** if the link transaction aborted — that is the trap this revision closes. Mechanism: the link route consumes the code and charges the failure counters in **their own committed step before** opening the link transaction (a separate `transactional()` that must commit; or an explicit `COMMIT` of the consume unit). Consequences, tested: a failed link **does not restore** the code and **does not reset** the counters; a fresh account cannot bypass a **per-mobile ceiling shared across all accounts** (`link-mobile:<sha256(mobile)>`), plus per-account and per-IP keys; the NID is never a key. **STOP-for-owner check:** this needs **no schema** — `cpms_rate_limits` (window key/counters) already exists and the consume step is already committed independently of the caller.

### 6.6 Audit free text (R6)
Inventory, search term `git grep -o "audit->log(\|audit()->log("` over `src`: **108 call sites in 27 files** (no `grep -v` exclusions). Free-text carriers found: `BookingService::appointmentView()` `:3367-3383` includes `'reason' => $row['reason']` (patient-typed), used as the `after_json` of `APPOINTMENT_CREATED` `:1057-1070` and `:1963`, `APPOINTMENT_RESCHEDULED` `:1756`; `PatientService` `PATIENT_CREATED` `:472-482` carries `staffView()` (raw `first_name`, `last_name`, `national_id` at `:779-795`). `AuditLogger` masks **only the exact key** `national_id`/`mobile`/`phone`/`card_number`/`email` (`AuditLogger.php:26`, applied `:249-251`) — a nested or renamed key passes through. **P2** therefore: remove free text from booking create/reschedule/cancel payloads (closed code + boolean + length only) and redact `PATIENT_CREATED` to ids; keep `reason` only in `cpms_appointments`. **Historical rows are immutable and are NOT scrubbed; their contents are UNKNOWN.**

### 6.7 Notifications, one policy (R7)
For `pending`, no-row, revoked and `booking`-only accounts, **every** notification read **and** write endpoint returns one fixed empty envelope before any `lastId`/ETag/304 computation:

| Endpoint | Guard now | Gate placement |
|---|---|---|
| `GET /notifications` `NotificationsController.php:34-49` | `permAnyRole` `:42-43` | before the service call |
| `POST /notifications/read` `:51-71` | `:64-65` | before any mark |
| `GET /rt/notifications` `:74-85` | `:78-79` | **before** ETag `:104`, the 304 branch `:106-111` and `since()` `:113` |

The fixed envelope carries a **constant ETag**, so `If-None-Match` with a real ETag still yields the fixed body, never a 304 that reveals a cursor moved. §2.4 and §13 must state the same thing (they do). Test: `If-None-Match` + real ETag ⇒ fixed envelope, and a `markRead` by a `booking` user writes nothing.

### 6.8 Shared mobile — **DECIDED DEFERRAL: Option A** (owner)
Clinic level cannot hold it: `u_pat_mobile` UNIQUE (`0001:82`), `mobile NOT NULL` (`0001:60`). Organization level cannot resolve it: identities have a deliberately **non-unique** `normalized_mobile` (`0019:44-53`) but **no national ID** (`0018:27-36`), so the matcher cannot run there. **Owner decision: Option A for the first release — no schema change; a shared number serves one patient per clinic, the others use reception. Multi-patient-per-mobile is a NEXT-VERSION item (§19) needing its own design and owner approval.** Options B (new column/table — outside approved scope) and C (relax the unique — rejected: it removes the only hard "one mobile, one record per clinic" guarantee) are recorded and **not** chosen.

### 6.9 `collision_clinic_id` — recommend delete
Under this policy the collision is a uniform refusal resolved by the link route, not a reception work item. The column never shipped. **Recommendation: delete. Owner decides.**

### 6.10 Staff fallback
Reception keeps: create via `ReceptionPortalController.php:183-188` → `PatientService::create` `:370-400`; set a checked national ID; or explicitly link an existing walk-in patient. All staff-initiated, audited, never automatic, never merging, sessions reset (`WP_Session_Tokens::destroy_all`). Under the new policy this is the **fallback** (M1/M2 failures, shared-mobile family), not the normal path. Reception views use `searchView` (`PatientService.php:245-257`, `:831-844`) via `to_search_view` `:823-825` — add `login_state`; no NID beyond the existing mask.

**"Pending registrants must first visit reception" is removed**: a `pending` registrant proves the binding themselves through §6.2; reception is not a gate.

## 7. Lifecycle

### 7.1 Table existence
`App::is_runtime_blocked_by_pending_schema()` `App.php:622-639` returns `false` for a supported engine (`:626-628`) and for unreadable evidence (`:630-634`) — it reports **pending migrations**, not a missing table. The gate does its own cached-per-request existence check and fails closed (level `none`, neutral refusal).

### 7.2 Restore barrier
Dump set is `SHOW TABLES LIKE '{prefix}cpms_%'` (`BackupSqlDumper.php:113-126`, `dbPrefix()` `CpmsDb.php:85-88`), so a **WordPress option** (`cpms_restore_barrier`) is outside it and survives a restore. Set **before** restore; while set every patient level is `none`. **UNKNOWN:** `restoreApply` `:324-390` has **no** pre-drop hook — the build PR must add one with a kill-between-drop-and-recreate test.

### 7.3 Restore clearing never revives bindings (R8)
Clearing the barrier is one explicit manager action that: destroys all patient-only sessions, writes one audit row, and **closes every restored binding** — each restored `cpms_patient_user_links`/`identity_links` pair is not trusted, so every account returns to `booking` and must complete §6.2 again, or the manager approves individually. A restored revoked account cannot silently regain access.

### 7.4 Backups and uninstall (R8)
Backups already contain every patient NID from `cpms_patients.national_id`; encrypted backup storage is specified by the backup design **`docs/architecture/backup-restore-design.md` and PR #358 — UNKNOWN: neither exists in this tree at `c9771403`**, so this document only records the **requirement** (backups must be encrypted and access-controlled) and does **not** claim it is met. The 14-day live scrub (§7.5) limits the live window only.
**Uninstall (`uninstall.php:8-20`):** with `cpms_wipe_on_uninstall` unset, `return` at `:9-11` — nothing is deleted, login rows and bindings survive, and a reinstall reuses them. With it set, every `{prefix}cpms_%` table is dropped (`:15-18`) plus the two options (`:19-20`): login rows, bindings and identities are all destroyed, **patient sessions are not explicitly destroyed** (LIMITATION: `uninstall.php` never calls session destruction — the build must add it), and a fresh install starts with no identity state. `wp_users` created for patients are **not** deleted by `uninstall.php` (it only drops `cpms_*` tables).

### 7.5 Retention
Live rows are deleted only by revocation. At decision: names and NID → NULL. At 14 days: a still-`pending`, unbound row is scrubbed and deleted; its WP user is deleted only when it has no link and no appointment. Sweep host: a **new** `cleanup.patient_logins` job (`App::RECURRING_JOBS` `:2616-2635`; existing `cleanup.*` at `:2625-2628` each own another table, `OtpCleanupHandler.php:22-25` only `cpms_otp_tokens`). A stalled sweep is a **residual** (no health surface read).

## 8. Oracle

| Mobile | `/otp/request` | `/otp/verify` (valid code) |
|---|---|---|
| unknown | send, 200 | `202 register_required`, no cookie |
| live row, any state | send, 200 | 200 + cookie (**level still `booking`**) |
| staff / ineligible | send, 200 | generic invalid-code envelope |
| wrong code | send, 200 | generic invalid-code envelope |

`register_required` is distinguishable only to a caller who has proved possession of that mobile (owner-permitted). Record access is **not** signalled at login at all — that is the point of R1.

## 9. Cap and lock order

Cap = 2 active appointments per account (DECIDED), counted across all patients linked to the account by joining `cpms_patient_user_links` (never `cpms_appointments.wp_user_id`, `0001:230`, which `createByStaff` `:1935-1954` does not populate). Locking current read in the **outermost** transaction: login row `FOR UPDATE` → `COUNT(*)` of future non-cancelled appointments for the account. Depth guard: fail closed if `transaction_depth() !== 0`. **Staff bookings (`createByStaff`) are exempt** and may exceed the cap (accepted: staff are trusted and audited). Reschedule takes no login lock and adds no row.

**Lock order:** patient hold/confirm login → Organization → Clinic → Slot → Location (`:1881-1886`, `:2750-2790`); reschedule appointment-first (`:1568-1574`); `createByStaff` tenant→Slot→Location (`:1805-1935`). The only new edge is "login before tenant prefix"; nothing takes the login row after a tenant lock ⇒ no cycle (INFERENCE, not executed). Revision 4's claim that `:1716-1718` showed staff ownership was **wrong** (that is reschedule).

## 10. Abuse limits

`Settings::DEFAULTS` (`Settings.php:28-40`) are configurable defaults (`get()` `:207`), not ceilings. Per mobile 3/day, 10/hour, 60 s cooldown, 900 s lockout after 5 bad codes; `/otp/request` per IP 10/hour; `/otp/verify` per IP 20/3600 hard-coded (`OtpService.php:392`). `/patient/register` needs a consumed OTP plus hashed buckets. `/patient/link-record`: per account, per mobile **across all accounts**, per IP — all hashed, NID never a key (§6.5). Existing debt not copied: `OtpService` keys `otp-day:`/`otp-hour:` on the **raw** mobile (`:274-275`, `:622-629`).

## 11. Dependents (last, blocked)

Several patients per account already work (`u_link_pair` `0001:97`; `linkedActivePatientsForClinic` `BookingService.php:3057-3063`); `>1` link makes the hold refuse to guess (`:3121`) with an optional `patient_id` (`BookingController.php:90`) validated by `assert_requested_patient_owned_by_caller` `:3131-3153`.

> **OQ-D1 (unchanged):** a dependent sharing the guardian's mobile cannot have a row in the same clinic (`0001:60`, `:82`) — this is the same limitation now **deferred** by §6.8. No schema is invented. Until the next-version design, dependents work only with distinct mobiles, and a guardian may hold a child's **appointments** (owner decision).

Under the two-factor policy a dependent's records open only through that dependent's own proof (M7); a guardian's proof never inherits. `pending` accounts cannot create dependents; the cap counts parent + dependents; per-link access; no age limit; never merged with a walk-in patient. Negative tests: §17 T-L5, T-L13.

## 12. Rollout

| PR | Contents | Login-row states after deploy | Record access possible |
|---|---|---|---|
| **P1** | table `cpms_patient_logins`, existence check, restore barrier + hook, `CpmsDb` depth accessor | none | no |
| **P2** | audit redaction (§6.6) | none | no |
| **P3** | gate on **all** routes by actor branch + service-layer checks + runtime-registry test + "attach SMS login" | `active` (staff-attached) | **no** (nothing bound yet; §2.3 predicate change lands here too) |
| **P4** | SMS login issuance (cookie after depth-0) | `active` | no |
| **P5** | cap + first-booking **creation only** (§5) | `active`, unbound | no |
| **P6** | **binding**: `/patient/link-record` (§6.2-6.5) + staff fallback (§6.10) + sever | `active` bound | **yes**, per binding |
| **P7** | public `/patient/register` | `pending` may now exist | per binding |
| **P8** | UI journeys | same | yes |
| **P9** | browser lanes | same | yes |
| **P10** | dependents / multi-patient-per-mobile — needs the next-version design (§19) | same | same |

**Invariants:** no `pending` row can exist before **P7** (cheap non-blocking fix, stated here); no record access before **P6**; "attach login" ships inside the gate PR so no password patient lacking a row is locked out. Minutes (INFERENCE incl. review): P1 90 · P2 60 · P3 240 · P4 90 · P5 180 · P6 240 · P7 150 · P8 180 · P9 120 ⇒ **≈1 350 implementation + 750–900 review ≈ 2 100–2 250**.

## 13. State matrix

`✗` refused · `—` not routed · `✓` allowed. `booking` = live row, **no binding**. `full` = verified binding for that patient.

| Route / branch | no row | pending | active, unbound | active, bound | deleted | password-only | staff | anonymous |
|---|---|---|---|---|---|---|---|---|
| `/booking/hold` | ✗ | ✓ + create | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `/booking/confirm` | ✗ | ✓ cap | ✓ cap | ✓ cap | ✗ | ✗ | — | ✗ |
| `/booking/resume`, `/appointments/mine`, `…/reschedule-options` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | — | ✗ |
| `…/reschedule`, `…/cancel` | ✗ | ✓ | ✓ | ✓ | ✗ | ✗ | staff branch | ✗ |
| `GET /patient/me` | ✗ | minimal | minimal | full | ✗ | ✗ | — | ✗ |
| `PATCH /patient/me` | ✗ | ✗ | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `POST /patient/link-record` | ✗ | ✓ (own proof) | ✓ | ✓ idempotent | ✗ | ✗ | ✗ | ✗ |
| `POST /patient/enroll`, `/patient/my-records` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | staff path | ✗ |
| `/visits`, `/visits/{id}`, `/prescriptions` | ✗ | ✗ 403 | ✗ 403 | ✓ | ✗ | ✗ | — | ✗ |
| `/patients/{id}/files`, `/files/{id}/stream` | ✗ | ✗ 403 | ✗ 403 | ✓ own | ✗ | ✗ | `MedicalFileService:219-250` | ✗ |
| `/notifications`, `/notifications/read`, `/rt/notifications` | ✗ | fixed empty | fixed empty | ✓ | ✗ | ✗ | staff branch | ✗ |
| portal render | ✗ | appointments | appointments | full | ✗ | ✗ | — | ✗ 403 |
| `/patient/register` (P7+) | ✓ 202 + row | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✓ 202 | ✗ | ✓ 202 |

The only cell that matters: **`active, unbound` reaches no record route and no notification endpoint** — §6.7's fixed envelope, not a 304.

## 14. Self-audit (Phase 2, this revision)

- **(a) Record/session/notification leak, wrong-patient/wrong-clinic, or clinic link granting records.** The dangerous change is R1: `state='active'` no longer means `full`, so the gate reads the **binding** (§6.3). Checked the four predicate call sites (`PatientRepository.php:95,151,173,191`) — all funnel through `:261-269`, whose `identity_id IS NULL` disjunct is exactly the clinic-link-grants-records path; §2.3 names it as the change and keeps the gate authoritative until it lands. Wrong-clinic: candidates are searched per clinic with `mobile` **and** `national_id` on the same row, and a binding is written only for that clinic. Wrong-patient: `u_pat_mobile` makes a clinic return at most one candidate. Notifications: gated before ETag/`lastId` (§6.7), so `booking` never learns a cursor moved.
- **(b) False file:line.** All new citations verified with `git show origin/main:`: `PatientRepository.php:95,151,164-177,182,191,261-269`; `AuditLogger.php:26,249-251`; `NotificationsController.php:34-49,51-71,74-85,104-113`; `uninstall.php:8-20`; `0019:54-73`, `0018:27-36`; `BookingService.php:1057-1070,1756,1963,3367-3383`; `PatientService.php:52-57,128,139,245-257,351,420,472-482,561-576,779-795,823-825,831-844`; `ManagementPortalController.php:80` (**Frontend**, not Rest — corrected); `template_redirect` 9 hits. Two **withdrawn** claims: that `docs/architecture/backup-restore-design.md` and PR #358 exist (they do not in this tree) and that revision 7 shipped §16/§17 (it did not; §18).
- **(c) Registration/linking before the gate.** `/patient/register` is P7; `/patient/link-record` is P6; the gate is P3. No pending row exists before P7.
- **(d) Unbuildable contradiction.** The rev-7 flow asked the booking request for a national ID — removed; §5 uses body names + slot clinic only. §6.5's "consume before the link transaction" is satisfiable without schema (`cpms_rate_limits` exists).
- **(e) CLAIM words.** `never`/`only`/`cannot` are used only where traced: `u_pat_mobile`/`u_pat_nid` uniqueness (`0001:82-83`), no NID on identities (`0018:27-36`), the 9-hit `template_redirect` list is explicitly **not proven complete**.

## 15. Security: threats (condensed from the required five)

| Threat | Mitigation | Residual (accepted) |
|---|---|---|
| **Recycled number** | NID must also match (M1/M3) | New subscriber who also knows the old owner's NID can link; reception severs (M6) |
| **SIM swap/theft** | NID still required; mismatch links nothing | M6's SMS goes to the attacker-controlled number, so it is a weak warning; manual sever |
| **NID known to relatives/documents** | Mobile possession still required; shared mobile never merges (§6.8) | A relative with both can link — same authority documents already give |
| **NID brute force after SIM compromise** | Limits on account/mobile/IP, never the NID (M4); identical failures (M5) | Enumeration bounded only by configurable `Settings` values (§10); windows UNKNOWN |
| **Squatting a national ID** | `u_pat_nid (clinic_id, national_id)` UNIQUE (`0001:83`) + `assert_unique_national_id` (`PatientService.php:850-857`) prevents a duplicate row | **A squat CAN block the real patient inside that clinic**, and M2 then refuses auto-link; resolution is manual/audited. At Organization level squatting is unconstrained (no NID on identities) — accepted |

## 16. Tests

| # | Case | Expected |
|---|---|---|
| T-L1 | matching mobile+NID | binding written, `full` next request, M6 SMS without NID, audit ids only |
| T-L2 | wrong NID | no link, **identical** envelope to T-L3/T-L4 |
| T-L3 | NID right, record mobile ≠ OTP mobile (M1) | no link, identical envelope |
| T-L4 | record has no NID (M2) | no link, identical envelope |
| T-L5 | two patients, one mobile, **different** clinics | each links only in its own clinic (M3); same-clinic is §6.8 Option A |
| T-L6 | `active` but unbound account hits every record route + 3 notification endpoints | 403 / fixed empty; `If-None-Match` with a real ETag ⇒ fixed envelope, no 304 |
| T-L7 | rate limits: per account, per mobile across accounts, per IP | identical envelope; keys hashed; no NID in `cpms_rate_limits` |
| T-L8 | failed link | OTP **not** restored, counters **not** reset (§6.5) |
| T-L9 | concurrent NID edit during link; concurrent sever (§6.4) | one outcome, no orphan link |
| T-L10 | audit payloads after success/failure | no NID, no mobile, no name, no free text |
| T-L11 | reception sever | access drops to `booking` next request, sessions destroyed, audit row |
| T-L12 | pending account self-proves (§6.10 removal of the reception gate) | succeeds without visiting reception |
| T-L13 | dependent with a distinct mobile | own proof required (M7); guardian's proof never inherits |
| T-L14 | restore then re-establish (§7.3) | before proof `booking`; after proof `full`; restored bindings not trusted |

## 17. Weakest points and open decisions

**Weakest:** (a) NID is not a secret — the policy rests on mobile possession plus the matcher; (b) a squat blocks a real patient's NID in that clinic and M2 refuses auto-link, so resolution is manual; (c) `identity_id IS NULL` is a read hole until §2.3 ships (DEBT); (d) M6's SMS is a weak SIM-swap warning; (e) shared mobile is deferred (§6.8) so families are only partly served; (f) per-clinic proof is more friction; (g) plaintext pending NID in the table and in backups, whose encryption requirement is **not verifiable** in this tree (§7.4); (h) the restore barrier needs a hook that does not exist; (i) the 14-day sweep has no host; (j) `uninstall.php` does not destroy patient sessions.

**Open:** **OQ-D1** dependents · **OQ-D2** stalled-sweep warning · **OQ-D3** per-account vs per-link (per link assumed) · **OQ-D4** "attach login" mandatory forever · **OQ-D5 closed** · **OQ-D6** shared mobile — **now DECIDED (Option A, deferred)** · **OQ-D7** `collision_clinic_id` keep/delete — recommendation delete, owner decides.

## 18. Correction to revision 7

Revision 7's document **ended at §15**: the §14/§15 replacement truncated the file and silently dropped the threats and tests tables (now §15/§16) that the ledger and the revision-7 report claimed. The ledger entry claiming "§16/§17 written" is **wrong**; it is corrected by an appended entry, and this revision contains both sections **for real** (§15/§16 here).

## 19. Next version (not this release)

Multi-patient-per-mobile (several patients, one mobile, one clinic) — needs its own design and owner approval before any schema change; blocked by `u_pat_mobile` (`0001:82`) and `mobile NOT NULL` (`0001:60`). Dependent self-service beyond §11 follows the same design. No column or table is proposed here.
