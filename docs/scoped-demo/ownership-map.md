# Scoped demo — CPMS table ownership map (preparation checkpoint)

**Source of truth:** repository `main` / session base `2ae07abf2e68c8ec7c9c679016915d5be4ce7034`, pinned companion schema `2026_10_27_0030`.

**Status:** implemented in the companion and covered by real-database runtime drills (`tools/disposable-demo-installer/tests/scoped-recovery.php`, R1–R9). The tables below remain a design reference; the enforceable rules are the declarative constants listed in the correction section.

## Important correction to the mission wording

The mission says “all 43 CPMS tables”. That count is from a pre-0030 schema. At schema `2026_10_27_0030`, `Preflight::OPERATIONAL_TABLES` has exactly **50** tables and `Preflight::KNOWN_TABLES` has **60** tables (50 operational + 10 system/global). `Teardown::DELETE_ORDER` has 57 entries. The scoped implementation and its tests must cover all **60**, with special treatment for the shared/global surfaces called out below.

The declarative source of truth for every rule below is `tools/disposable-demo-installer/compatibility.json`, and `.github/scripts/companion-compat/evaluate.py` is the gate that proves the companion still satisfies it.

Notation:

- `C` — direct `clinic_id` ownership.
- `O` — direct `organization_id` ownership.
- `L`, `U`, `P`, `V`, etc. — direct location/user/patient/visit ownership.
- `FK(parent)` — ownership proven through a durable parent ID.
- `JSON` — ownership only through a verified entity ID inside a JSON payload.
- **Never** — table must not be deleted by scoped teardown.

## Normative correction: a WordPress user id is never ownership

An earlier revision of this document listed `U` ("direct user ownership") as an
ownership anchor. That was wrong, and it was the cause of a real defect: the
teardown OR-connected every resolvable column, so a post-baseline row of a
**foreign** Clinic that merely named a synthetic demo account was claimed as
demo-owned. The claimed clinician/membership row then fed the clinician and
membership id-sets, and the next collection pass used those ids to claim foreign
visits, clinical records, invoices and payments, which were deleted.

The rule set is now declarative, exactly one rule per table, and enforced
fail-closed by the teardown itself (`.github/scripts/companion-compat/evaluate.py`
proves the two sides agree):

| Constant (in `class-cpms-demo-scoped-teardown.php`) | Meaning |
|---|---|
| `TENANT_OWNERSHIP` | Mandatory tenant boundary: `clinic_id` = demo Clinic, or `organization_id` = demo Organization. Nothing widens it. |
| `PARENT_OWNERSHIP` | For tables with no tenant column: the declared parent column, whose id-set is itself tenant-derived. |
| `COMPOSITE_PARENT_COLUMN` | Parent column that proves ownership of a composite-key table; the other key columns only address the row for deletion. |
| `USER_REFERENCE_COLUMNS` | The 20 WordPress actor columns. **Dependency evidence only** — a surviving foreign row that names a synthetic account blocks the teardown with `SCOPED_FOREIGN_DEPENDENCY`. |
| `NEVER_OWNED_TABLES` | Install/runner bookkeeping that can never be owned. |

A table that is deletable but unclassified makes the teardown refuse with
`OWNERSHIP_RULE_MISSING` rather than guess. Everywhere this document says `U`,
read "actor reference that is never used to prove ownership".

## The 43 operational tables present before schema 0030

| # | Table | Actual ownership anchor(s) | Scoped teardown rule |
|---:|---|---|---|
| 1 | `cpms_clinicians` | `C` (mandatory boundary); `U` is an actor reference; unique `wp_user_id` | Delete only exact manifest-owned clinician IDs after verifying synthetic clinic and user. |
| 2 | `cpms_clinic_memberships` | `C` (mandatory boundary); `U` is an actor reference; `FK(clinics)` | Delete only exact manifest-owned membership IDs; never alter a pre-existing membership. |
| 3 | `cpms_organization_memberships` | `O` (mandatory boundary); `U` is an actor reference; `FK(organizations)` | Delete only exact manifest-owned membership IDs for the synthetic Organization. |
| 4 | `cpms_organization_membership_capabilities` | `FK(cpms_organization_memberships.membership_id)` | Delete only children of manifest-owned Organization memberships. |
| 5 | `cpms_membership_capabilities` | `FK(cpms_clinic_memberships.membership_id)` | Delete only children of manifest-owned Clinic memberships. |
| 6 | `cpms_membership_locations` | `FK(membership_id)`, `FK(location_id)` | Delete only children of manifest-owned memberships; verify both parents. |
| 7 | `cpms_clinician_locations` | `FK(clinician_id)`, `FK(location_id)` | Delete only children of manifest-owned clinician/location IDs. |
| 8 | `cpms_custom_role_defs` | `C`; `FK(clinics)` | Delete only exact role definitions owned by the synthetic clinic. |
| 9 | `cpms_custom_role_capabilities` | `FK(role_def_id)` | Delete only children of manifest-owned role definitions. |
| 10 | `cpms_schedule` | `C`, `L`, `FK(clinician_id)` | Delete only exact schedule IDs for the synthetic clinic/clinician/locations. |
| 11 | `cpms_schedule_exceptions` | `C`, nullable `L`, `FK(clinician_id)` | Delete only rows verified through synthetic clinic and manifest-owned clinician IDs. |
| 12 | `cpms_schedule_slots` | `C`, `L`, `FK(clinician_id)` | Delete only exact slot IDs recorded in the manifest. |
| 13 | `cpms_slot_holds` | `C`, `FK(slot_id)`, nullable `P`, `U`, mobile | Delete only exact hold IDs with a manifest-owned slot and synthetic mobile/patient. |
| 14 | `cpms_patients` | `C`, `FK(identity_id)`, mobile | Delete only exact patient IDs; verify synthetic clinic, mobile and identity. |
| 15 | `cpms_patient_identities` | `O`, `FK(organizations)` | Delete only exact identity IDs for the synthetic Organization. Preserve all other identities. |
| 16 | `cpms_patient_identity_links` | `O`, `FK(identity_id)`, `U` | Delete only rows linking a manifest-owned identity and persona user. |
| 17 | `cpms_patient_user_links` | `C`, `FK(patient_id)`, `U` | Delete only rows linking a manifest-owned patient and persona user. |
| 18 | `cpms_patient_merges` | `C`, surviving/merged patient IDs | Delete only exact IDs with both patients manifest-owned. |
| 19 | `cpms_appointments` | `C`, `L`, `FK(clinician_id/patient_id/slot_id)`, `U` | Delete only exact appointment IDs and all four verified parents. |
| 20 | `cpms_visits` | `C`, `L`, `FK(clinician_id/patient_id/appointment_id)` | Delete only exact visit IDs and verified parents. |
| 21 | `cpms_visit_status_history` | `FK(visit_id)`, actor user | Delete only children of manifest-owned visits. |
| 22 | `cpms_clinical_notes` | `C`, `FK(visit_id/patient_id/clinician_id)` | Delete only exact clinical note IDs and verified parents. |
| 23 | `cpms_clinical_note_versions` | `FK(note_id)` | Delete only children of manifest-owned notes. |
| 24 | `cpms_prescriptions` | `C`, nullable `L`, `FK(visit_id/patient_id)` | Delete only exact prescription IDs and verified parents. |
| 25 | `cpms_prescription_items` | `FK(prescription_id)`, nullable `FK(drug_ref_id)` | Delete only children of manifest-owned prescriptions. |
| 26 | `cpms_recommendations` | `C`, `FK(visit_id/patient_id/clinician_id)` | Delete only exact IDs and verified parents. |
| 27 | `cpms_follow_ups` | `C`, `FK(visit_id/patient_id/linked_appointment_id)` | Delete only exact IDs and verified parents. |
| 28 | `cpms_medical_attachments` | `C`, `FK(visit_id/patient_id)` | Delete only exact IDs; verify and remove any private file by manifest-owned path. |
| 29 | `cpms_handwriting_documents` | `C`, `FK(visit_id/patient_id/clinician_id)` | Delete only exact IDs and verified parents. |
| 30 | `cpms_handwriting_pages` | `FK(document_id)` | Delete only children of manifest-owned documents. |
| 31 | `cpms_handwriting_page_versions` | `FK(page_id)` | Delete only children of manifest-owned pages. |
| 32 | `cpms_ocr_jobs` | `C`, `FK(source_page_id)` | Delete only exact IDs with a manifest-owned page. |
| 33 | `cpms_services` | `C`, nullable `L`, `FK(clinics)` | Delete only exact service IDs for the synthetic clinic. |
| 34 | `cpms_invoices` | `C`, nullable `L`, `FK(patient_id/visit_id)` | Delete only exact invoice IDs and verified parents; fail closed if any clinical/financial child remains. |
| 35 | `cpms_invoice_items` | `FK(invoice_id)`, `FK(service_id)` | Delete only children of manifest-owned invoices. |
| 36 | `cpms_payments` | `C`, nullable `L`, `FK(invoice_id/patient_id)` | Delete only exact payment IDs and verified parents; offline `other` method only. |
| 37 | `cpms_payment_adjustments` | `FK(invoice_id)`, nullable `FK(payment_id)` | Delete only children of manifest-owned invoices/payments. |
| 38 | `cpms_sms_messages` | `C`, recipient, context IDs | Delete only exact IDs for the synthetic clinic and reserved synthetic recipient; `log` provider only. |
| 39 | `cpms_otp_tokens` | nullable `C` (migration 0021), mobile | Delete only exact challenge IDs with synthetic clinic **and** mobile; preserve all `NULL`/foreign clinic rows. |
| 40 | `cpms_idempotency_keys` | `C` (mandatory boundary); `U` is an actor reference, key/endpoint/context | Delete only exact IDs with verified fixture clinic/user/key scope; preserve all foreign or ambiguous keys. |
| 41 | `cpms_notifications` | `C`, nullable recipient user/patient, dedupe key | Delete only exact IDs for the synthetic clinic and manifest-owned recipients. |
| 42 | `cpms_audit_logs` | append-only global hash chain; nullable `C`, resource/actor/meta JSON | **Never delete, update, or selectively remove any row.** Keep all demo and foreign audit entries; verify full chain and immutable baseline prefix. |
| 43 | `cpms_drug_reference` | `C`; `FK(prescription_items.drug_ref_id)` | Delete only exact synthetic drug-reference IDs after their prescription children are gone. |

## The 10 system/global tables

| Table | Actual ownership / scope | Scoped teardown rule |
|---|---|---|
| `cpms_organizations` | Parent of all clinics; no clinic ID. Slug/status/audit marker. | Delete only the exact synthetic Organization after every child is gone. Never alter an existing Organization. |
| `cpms_clinics` | Parent of all clinic-owned rows; `organization_id`, slug, status, first-setup marker. | Delete only the exact synthetic Clinic. Suspend it during teardown; never alter an existing Clinic. |
| `cpms_locations` | `FK(clinic_id)`, clinic-unique slug. | Delete only exact synthetic Location IDs. Preserve every existing Location. |
| `cpms_settings` | `C`, nullable `L`, unique `(clinic_id,key)`. | Delete only settings for the synthetic clinic; verify `sms.provider=log` and bounded booking settings. |
| `cpms_schema_migrations` | Installation-wide migration registry. | **Never touch.** |
| `cpms_jobs` | Installation-wide queue; `payload_json` is the only ownership hint. | Preserve recurring/global jobs and all foreign jobs. Delete only exact job IDs whose payload references the synthetic clinic or synthetic SMS message IDs. Acquire `GET_LOCK('cpms_jobs_tick', 0)` to block the runner; any `processing` owned job or unavailable lock fails closed. New fixture-owned jobs found at post-verification mean teardown is incomplete. |
| `cpms_operational_logs` | Installation-wide append-oriented log; `context_json`, request ID and IP are shared. | Preserve all baseline and foreign rows. Delete only exact log IDs whose `context_json` carries a verified fixture entity ID or reserved synthetic marker. Never match by timestamp, request ID, IP, or 4-digit mobile suffix. |
| `cpms_rate_limits` | Composite `(window_key,window_id)`; shared IP/global counters. | Preserve all shared `otp-ip:*`, `otp-verify-ip:*`, `login-ip:*` rows and all foreign entity keys. Delete only a closed allowlist of exact fixture-derived keys proven absent from the baseline. |
| `cpms_license_install` | Installation-wide licensing identity. | **Never touch.** Verify byte-for-byte baseline preservation. |
| `cpms_license_state` | Installation-wide signed license document. | **Never touch.** Verify byte-for-byte baseline preservation. |

## Global invariants to enforce in code and tests

1. **Audit chain:** `AuditLogger` only appends. `HashChain::verify` proves `row_hash = SHA-256(prev_hash + canonical(fields))` in ascending `id` order. Scoped teardown must omit `cpms_audit_logs` from every delete path. A full-chain verifier must check all rows in chunks, not just the last 100,000 rows. The pre-demo prefix (IDs and full-row digests) must be unchanged after teardown.
2. **No ID-range assumptions:** never use `id > baseline_max_id`, `window_id >= 0`, timestamps, or insertion order as ownership proof. Use exact IDs, slugs, user identities, mobile values, and JSON entity references recorded in the sealed manifest, then re-verify against live rows.
3. **Foreign activity is expected:** a non-empty site may add rows in the same tables during the demo. Such rows are preserved, not treated as a global teardown blocker; rows whose ownership cannot be proven are blockers.
4. **FK-safe deletion:** durable logs/jobs/rate limits are evaluated first; clinical and financial children are deleted before parents; the synthetic Clinic is deleted before the synthetic Organization; WP users and the synthetic page are deleted last.
5. **No cascade into existing data:** no parent row is deleted until all children are accounted for. A failed or interrupted teardown retains the sealed manifest with `status=teardown_failed` and an explicit residual list; repeated teardown is idempotent.
6. **No schema migration:** record ownership in HMAC-sealed WordPress options, user meta, and post meta only. The current schema already carries the necessary `clinic_id`, `organization_id`, location, user, and JSON anchors.

## Schema `2026_10_27_0030` additions — staff messaging and visit pricing

Migration `2026_10_27_0030_staff_messaging_visit_pricing` adds seven operational
tables. It declares **no** `FOREIGN KEY` constraints (tenant relationships are
enforced by the services and by each query's own `clinic_id` predicate), so
ownership for these tables is proven by the same id-sets as everywhere else and
never by a column name alone.

| Table | Primary key | Ownership anchors used by scoped teardown | Teardown rule |
|---|---|---|---|
| `cpms_message_threads` | `id` | `clinic_id`, `organization_id`, `location_id`, `patient_id`, `visit_id`, `created_by_wp_user_id` | Delete only exact manifest-owned thread IDs; feeds `thread_ids`. |
| `cpms_message_participants` | `(thread_id, wp_user_id)` — **no `id` column** | `thread_id`, `wp_user_id` | Deleted by its **composite key** only. Previously skipped entirely because it has no `id` column, so fixture rows survived teardown undetected. |
| `cpms_messages` | `id` | `clinic_id`, `thread_id`, `sender_wp_user_id` | Delete only exact manifest-owned message IDs; feeds `staff_message_ids`. Append-only messaging history is preserved for the demo Clinic's lifetime and removed only by this fixture-owned teardown. |
| `cpms_doctor_consultation_fees` | `id`; unique `(clinic_id, clinician_id, location_id)` | `clinic_id`, `clinician_id`, `location_id`, `updated_by_wp_user_id` | Delete only exact manifest-owned fee-default IDs; feeds `doctor_fee_ids`. |
| `cpms_visit_consultation_fees` | `(visit_id)` — **no `id` column** | `visit_id`, `clinic_id`, `clinician_id`, `set_by_wp_user_id` | Deleted by its **composite key** (`visit_id`) only. |
| `cpms_visit_charge_items` | `id` | `clinic_id`, `visit_id`, `clinician_id`, `invoiced_invoice_id`, `created_by_wp_user_id`, `updated_by_wp_user_id`, `removed_by_wp_user_id` | Delete only exact manifest-owned charge-item IDs; feeds `charge_item_ids`. |
| `cpms_visit_fee_snapshot_failures` | `(visit_id)` — **no `id` column** | `visit_id`, `clinic_id` | Deleted by its **composite key** (`visit_id`) only. |

### Why the membership id-sets were split

`membership_id` is the only column name in the schema with **two distinct
foreign-key parents**:

- `cpms_membership_capabilities.membership_id` → `cpms_clinic_memberships.id`
- `cpms_membership_locations.membership_id` → `cpms_clinic_memberships.id`
- `cpms_organization_membership_capabilities.membership_id` → `cpms_organization_memberships.id`

A single global `membership_id => membership_ids` rule fed by
`cpms_clinic_memberships.id` therefore selected **organization** capability rows
whose unrelated auto-increment id happened to overlap, and the teardown deleted
them. The sets are now `clinic_membership_ids` and `org_membership_ids`, and the
ambiguous column is resolved per table in
`ScopedTeardown::TABLE_OWNERSHIP_COLUMNS`. `membership_id` is deliberately absent
from the global map.

### Identity links are tenant-scoped, never user-scoped

`cpms_patient_user_links` (Clinic-scoped) and `cpms_patient_identity_links`
(Organization-scoped) must not be claimed merely because they reference one of
the demo's synthetic users: a foreign row can do that. `ScopedTeardown::
TABLE_OWNERSHIP_ONLY` restricts ownership to the tenant columns, and
`assert_user_not_foreign_linked()` refuses to delete a synthetic user while any
foreign-owned link still points at it.

### Preserved anchors are owned by identity, not by absence

`cpms_organizations`, `cpms_clinics` and `cpms_locations` are archived in place:
they are **not** part of the deletable row sets, so "is this row in the collected
set?" can never classify them. Deciding that way was a real defect. A synthetic
Organization manager can legitimately change the demo Clinic's lifecycle status
through the production management portal
(`ManagementPortalController::dispatch( …, 'org_clinic_status', … )` →
`OrganizationClinicProvisioningService::set_clinic_status()`), which stamps
`cpms_clinics.status_updated_by_wp_user_id` with that synthetic account. The
post-delete per-user scan then saw a Clinic row naming a synthetic user, could not
find it in the deletable sets, and declared the demo anchor foreign →
`SCOPED_FOREIGN_DEPENDENCY` halfway through a teardown that had already deleted
rows.

`ScopedTeardown::owned_tenants()` now decides the tenant roots **once**, by
identity, and the same decision feeds `collect_scope()`, `preview()`, the delete
phase and `assert_user_not_foreign_dependent()`:

| Anchor | Proof of ownership |
|---|---|
| `cpms_organizations` | the manifest's demo Organization id, and only when it postdates the sealed baseline |
| `cpms_clinics` | the manifest's demo Clinic **plus** every Clinic that names a provably demo-created Organization *and* postdates the sealed baseline |
| `cpms_locations` | every Location of an owned Clinic (plus the manifest's declared Locations) |
| `cpms_audit_logs` | rows whose `clinic_id` is an owned Clinic — preserved, never deleted, never treated as a dependency |

Everything else in the live-only scan is foreign: a row that is not a preserved
anchor must not name a synthetic account. A WordPress user reference still proves
nothing, and sharing an Organization is not proof of ownership either — the
Organization itself must be post-baseline.

### A Clinic that cannot be proven is refused, never guessed

A Clinic that names the demo Organization but predates the sealed baseline cannot
be shown to be demo-created (for example an id recycled below the baseline
`max_id`). It is neither claimed nor silently left behind: `preview()` reports
`SCOPED_OWNERSHIP_UNPROVEN: cpms_clinics=<ids>` and `execute()` raises
`SCOPED_OWNERSHIP_UNPROVEN` **before any destructive stage**, deleting nothing and
leaving the fixture active and recoverable. Removing or re-homing that Clinic makes
the same teardown pass, which is what
`tools/disposable-demo-installer/tests/scoped-portal.php` (P1/P2/P3) proves against
a real database on MySQL 8 and MariaDB 11.4.

### Ownership of an additional Clinic is a sealed record, not an inference

The previous rule for a *second* Clinic was: it names the demo Organization, the
Organization itself postdates the seal, and the Clinic's `id` is above the sealed
baseline `max_id` — therefore it is demo-owned. That rule is **removed**. It could
not distinguish a demo Clinic from a genuine non-demo Clinic that a real
Organization manager created under the same Organization *after* the baseline was
taken, and it would have deleted (or suspended and stripped of its actors) real
data on a populated site.

Ownership of every additional Clinic is now an explicit, verifiable record in
`ScopedOwnership` (option `cpsd_ownership_ledger`, a distinct namespace from every
manifest option):

| Property | How it is proven |
|---|---|
| Sealed | every record is HMAC-sealed with the manifest key, bound to this site fingerprint **and** this fixture id; a record copied from another site or fixture does not verify |
| Append-only | each record carries `seq`, the previous record's MAC and its own MAC, and the option also commits `count` and `head`, so an edit, an insertion **and a truncation** are all detected |
| Creation-time | the exact slug of a new Clinic is sealed as an `intent` record **before** the row can exist (after a collision check), and the created id + its Locations are sealed back as a `clinic` record |
| Fail-closed | an absent ledger means "no additional Clinic is owned"; an invalid ledger (`SCOPED_OWNERSHIP_LEDGER_INVALID`) or an unresolved intent (`SCOPED_OWNERSHIP_PENDING`) blocks the destructive teardown before anything is deleted |
| Interruption-safe | an unresolved intent is never guessed at: an administrator either registers the live row that matches the pre-committed Organization **and** slug, or abandons the intent after proving no row carries that slug |

Genuine foreign Clinics cannot enter the record through ordinary management
actions, because no ordinary management action writes it: the portal's create
action is refused (below), and the only writer is the scoped demo page's own
form behind capability + nonce + confirmation phrase + site identity.

### The Management Portal may not mint Clinics in scoped demo mode

Enforced twice, both server-side, and only while scoped demo mode is enabled:

1. **Capability.** The synthetic Organization manager's membership receives an
   explicit `DENY` row for the production `cpms_org_clinic_create` capability,
   written by the provisioner and read back through the production resolver
   (`OrganizationClinicProvisioningService::can()`). The portal therefore does not
   even render the create form, and a direct service call is refused by production
   code. `cpms_org_clinic_update` and `cpms_org_clinic_lifecycle` are untouched, so
   the lifecycle journeys keep working.
2. **HTTP boundary.** `ScopedAdmin::guard_portal_clinic_creation()` runs on
   `template_redirect` at priority 0 — ahead of the portal's own handler — and
   refuses any POST of `cpms_action=org_create_clinic` with a Persian explanation
   and HTTP 403. It returns immediately when scoped mode is off, so normal
   production behaviour outside a demo is unchanged.

Neither restriction is a UI change. The Persian explanation is server-rendered
(`wp_die` on the refused request and a policy card on the scoped demo page).

### Preview and execution share one ownership decision

`owned_tenants()` resolves the owned Clinic set once (manifest Clinic + sealed
ledger ids that are live rows still naming the demo Organization), and that single
decision feeds `collect_scope()`, the `anchors` used by the dependency scan, the
delete phase, `suspend_clinic()` and `assert_user_not_foreign_dependent()`. The
scope carries `ownership_ledger.{code,detail,pending}`, so `preview()` and
`do_execute()` cannot disagree. `tools/disposable-demo-installer/tests/scoped-portal.php`
P4–P7 prove each case against a real MySQL 8 / MariaDB 11.4 database: a foreign
post-baseline Clinic under the demo Organization, a foreign tenant whose rows carry
synthetic user ids, a manipulated/truncated ledger, and an interrupted
creation/registration that is repaired from sealed evidence.

## Mission F — real-user data inside demo tenants

The tenant boundary answers *where* a row lives. It never answered *who* created
it, and that gap is a data-safety hole: a real patient can book into the demo
Clinic through the public booking page, and a real member of staff can be given
access to it. Both create rows that name the demo Clinic and nothing else, and the
old rule would have deleted them as "demo data".

### Positive attribution (Stage 1)

Every candidate row — one that sits inside the demo tenant — must now be explained
by a POSITIVE fixture artifact from `ScopedTeardown::ATTRIBUTION`:

| Artifact | Meaning |
|---|---|
| sealed id | the manifest names this exact row (provisioning sealed it) |
| `@patient` | the row names a patient that is itself attributable |
| `@visit` / `@thread` | the row names an attributable visit / demo message thread |
| `@mobile` | the row carries the fixture's sealed mobile number |
| `@user` | the row names one of the fixture's synthetic accounts |
| `@recipient` | a queue row bound to an attributable patient (or none) |
| `@fixture` | the row carries the fixture id in a text column |
| ledger registration | the demo registered this exact row in the sealed ledger |
| `@tenant` | pure configuration of the demo Clinic (settings, services, opening hours, generated slots, taxonomy rows). This is a *positive* claim only because a real actor cannot write into a demo tenant at all — see the barrier below |

A row that matches none of them — including **every row that names a real
WordPress user** other than the fixture's own accounts and the administrator who
created the demo — is refused:

```
SCOPED_FOREIGN_TENANT_ROW:cpms_patients:41
SCOPED_FOREIGN_TENANT_ROW_TOTAL: cpms_patients=1, cpms_appointments=1
```

and `execute()` throws `SCOPED_FOREIGN_TENANT_ROW` **before any destructive
stage**. Refusing is always safe; guessing is not.

### Explicit registration of demo-created rows

Structural rules cannot prove a row a demo persona typed at the front desk. Those
rows get durable, explicit ownership: `ScopedOwnership::register_rows()` appends a
sealed `rows` record. Two producers:

1. the demo's own workflows (for example the Organization membership the extra-Clinic
   path adds for the acting administrator);
2. `ScopedTenantGuard` — for every state-changing request made by a synthetic
   fixture account it snapshots the demo tenant before dispatch and diffs it at
   `shutdown`, sealing exactly the rows that appeared. The teardown therefore never
   has to guess what a demo persona created.

### Server-side tenant write barrier (Stage 2)

While a fixture is active, `ScopedTenantGuard` refuses a state-changing REST
request (and the equivalent portal write) that targets the demo tenant when the
actor is an authenticated user who is neither a fixture account nor a site
administrator: HTTP 403, `SCOPED_DEMO_TENANT_WRITE_REFUSED`, Persian message.
Anonymous public requests stay allowed — that is the demo's public journey — and
anything they create for a real patient is refused by the attribution gate instead.

Consequence, stated plainly: the barrier is what makes `@tenant` a positive claim,
and the attribution gate is what protects the site if the barrier is ever bypassed
(direct service call, CLI, a plugin that writes without REST). Neither relies on
the other being perfect.

### The portal refusal is scoped to the fixture (Stage 2)

`ScopedAdmin::guard_portal_clinic_creation()` now refuses `org_create_clinic` only
when **all** of these hold: scoped mode is on, a *verified active* manifest exists,
the POST names the demo Organization, and the actor **is** the fixture's synthetic
Organization manager. A legitimate manager of a foreign Organization — or any other
account — keeps normal clinic-creation behaviour while a demo runs.

### The ledger cannot disappear silently (Stage 3)

`cpsd_ownership_ledger` is now mandatory for a sealed, active fixture: absent,
it is `SCOPED_OWNERSHIP_LEDGER_INVALID` and nothing is deleted. A monotonic
companion record, `cpsd_ownership_seal`, commits the ledger's `count` and `head`;
it is written after every append and verified before every append and every
teardown. Deleting the ledger, truncating it, replacing it with an older (still
perfectly sealed) copy, or deleting the seal are all detected. Interrupted
operations remain recoverable: the pending `intent` record and the sealed slug are
untouched by any of this, and `ScopedClinics::resolve()` still repairs them.

### An unverifiable manifest stands down; it never breaks the site (Stage 4)

The recorder, the barrier and the portal refusal all run on ordinary requests — a
login POST is a *write* request too. So all three read the manifest through one
total reader, `ScopedTenantGuard::verified_manifest()`, which returns `null` for a
payload that does not verify, for a mode marker that does not verify, for a
manifest that is not `active`, and for one with an empty fixture id. The portal
refusal catches the same load.

This is fail-closed, not a weakening:

* with no verified fixture there is no fixture identity to register rows against
  (`register_rows()` needs one) and no demo tenant this process can *prove*, so
  the boundary cannot be enforced and nothing new becomes deletable;
* the teardown refuses every row it cannot attribute, so anything written in that
  window is `SCOPED_FOREIGN_TENANT_ROW` and needs an explicit human decision;
* and the teardown refuses to run at all while the manifest does not verify.

Letting the exception escape instead — the first implementation — turned a
tampered manifest into a fatal error on **every form POST of the site**, including
the login the operator needs to repair the fixture. The Chromium tamper phase of
the scoped demo lane found exactly that; `tests/scoped-demo/tamper-manifest.php`
now pins both entry points on the real database with the real tampered payload.

### Evidence (real MySQL 8 and MariaDB 11.4.13 lanes)

* **P8 (attribution).** A real patient booking planted inside the demo Clinic
  makes `preview()` and `execute()` refuse with `SCOPED_FOREIGN_TENANT_ROW`
  naming `cpms_patients:<id>`, with zero deletions, byte-identical host rows and
  an intact audit chain.
* **P8 (REST boundary).** A real, non-fixture account POSTing the **real**
  production route `POST /clinic/v1/booking/hold` with the demo Clinic in the
  `X-CPMS-Clinic-Id` header and in the body is refused with HTTP 403
  `SCOPED_DEMO_TENANT_WRITE_REFUSED`. Controls in the same drill: the same
  account writing into its **own** Clinic is not restricted by the demo, and a
  synthetic fixture persona is not refused by the barrier. The probe reads the
  machine-readable `code` from the REST **envelope**: `WP_REST_Server::dispatch()`
  converts a `WP_Error` into a `WP_REST_Response` before returning, so a probe
  that only inspects `is_wp_error()` reports every refusal as "not refused".
* **Tamper.** With the manifest tampered out of band, `load()`, the write
  recorder and `write_verdict()` all refuse or stand down without throwing, and a
  real Chromium session can still log in and see the Persian
  `MANIFEST_TAMPERED` refusal with no scoped action offered.
* **P9 (ledger).** Deleted, truncated, rolled-back, seal-lost and ledger+seal-lost
  ledgers all refuse with `SCOPED_OWNERSHIP_LEDGER_INVALID` and zero deletions; an
  exact restore proceeds and the teardown completes. A clean teardown discards the
  ledger; a *repeated* teardown of an already-removed fixture does not require it.
