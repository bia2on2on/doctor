# Product Decision — Custom Role & Permission Management: approved model, security boundaries, and workstream ordering

> **Document type:** Authoritative Product Decision record (owner-approved decisions frozen).
> **Recorded:** 2026-10-10.
> **Scope of this record:** documents the approved model only. Slice 1 implements the backend foundations bounded by this record; this record is not itself a broad documentation checkpoint and rewrites no historical ADR.
> **Historical ADRs remain untouched.** Where this record and an older document overlap, the precedence rules in `docs/decisions/2026-09-05-document-precedence-policy.md` apply.
>
> **Implementation-status note (documentation-only correction, 2026-10-13):** §§3, 3b, 3c and 3d record the
> contracts of the slices as they were approved and delivered. Sections **3** (Slice 1), **3b** (Slice 2),
> **3c**/**3B** (assignment + private-file ownership) and **3d** (Slice 4A) are implemented and merged, and
> **§3e** records the Slice 4B archival contract that is now implemented and merged. The §1 item 3 wording
> "future slices" and the §4 "future slices must deliver" list are therefore **historical planning prose at
> their recording dates** and are annotated in place rather than rewritten. The approved model, the fail-closed
> security boundaries and the immutability/terminality semantics below are **unchanged** by this note. Current
> main/CI status is recorded only in [`../project-current-state.md`](../project-current-state.md#custom-role-current-state-2026-10-13).

---

## 1. Approved product model

1. **Custom role definitions are Clinic-local.** A custom role belongs to exactly one Clinic; the same key may exist in different Clinics with different capability sets and resolves independently per Clinic.
2. **Existing built-in CPMS roles remain available and backward-compatible** (`cpms_patient`, `cpms_secretary`, `cpms_doctor`, `cpms_accountant`, `cpms_manager`).
3. **Authorized Clinic management may configure custom roles** (management surface and its authority are future slices). *(Status 2026-10-13: delivered — the server-rendered definition-management surface and its Clinic-local authority model shipped in Slice 2, PR #214; see §3b.)*
4. **A role editor must never grant capabilities exceeding the editor's own effective Clinic-scoped authority.**
5. **WordPress administrator status alone does not confer clinical authority.**
6. **Sensitive capabilities are excluded from ordinary custom-role editing in V1** (§2).
7. **Role changes take effect on the next server-side authorization evaluation** (no session mutation required).
8. **Routine role changes do not force logout.**
9. **A role with assigned memberships cannot be deleted or archived until members are explicitly reassigned.**
10. **The same WP user may hold different membership roles in different Clinics.**
11. **Clinic membership, Location assignment, professional identity, and patient identity remain separate concepts.**
12. **Custom roles must not introduce another authorization engine** — resolution stays inside the existing `AuthorizationService`.
13. **The Custom Role & Permission Management workstream remains ordered before Phase 20** (not merged into Phase 20; Phase 20 itself is not started by this work).
14. **Patient booking for other people and guardian/representative medical access remain separate future product work** and are not implied by custom roles.

## 2. Security boundaries (fail closed)

- **One permission vocabulary.** Custom-role capabilities are drawn exclusively from the registered CPMS catalogue (`RolesAndCapabilities::ALL_CAPS`, naming `cpms_{resource}_{action}`). No new capability vocabulary is created.
- **V1-editable set = catalogue minus the explicitly classified sensitive set.** The sensitive classification is the existing one (permission-matrix P-11 / ADR-0026 plus the matrix's sensitive annotations): `cpms_private_note_*`, `cpms_patient_archive`, `cpms_patient_merge`, `cpms_export`, `cpms_audit_read`, `cpms_payment_void`, `cpms_payment_refund`, `cpms_invoice_void`, `cpms_invoice_adjust`, `cpms_consult_reopen`, `cpms_rx_void`, `cpms_config`, `cpms_sms_config`.
- **Permanently rejected inputs:** WordPress core capabilities, WooCommerce capabilities, unknown capability strings, any Organization/Clinic/Location authority capability, and every sensitive capability above. These can never enter a definition through any write path and are dropped by the authorization read path even if raw rows exist.
- **Immutable keys.** Custom role keys use the strict membership `role_key` format (2–64 chars, lowercase letter first — never numeric, so PHP array-key coercion cannot occur) and can never collide with a built-in role key; built-in keys never consult custom definitions.
- **Precedence unchanged:** membership DENY > membership GRANT > role capabilities (built-in preset or Clinic-local custom set) > default DENY.
- **Fail-closed resolution:** missing, inactive, malformed, or foreign-Clinic definitions supply no permissions. No default Clinic, no first-row selection, no cross-Clinic fallback.
- **Object ownership and tenant isolation outrank role capabilities.** Custom capabilities never override durable object ownership or the trusted-Clinic boundary.
- **Existing clinician-ownership and private-note protections remain authoritative** and are untouched by custom roles.
- **No WordPress-global custom role registration** and no global-role mutation. Custom roles are membership attributes resolved per Clinic, never WP roles.
- **No licensing/commercial entitlement authority** is created.

## 3. Persistence contract (Slice 1)

- Versioned, forward-only persistence for Clinic-local definitions and their permitted capability sets (Migration `2026_10_10_0024`): `cpms_custom_role_defs` (UNIQUE `(clinic_id, role_key)`, `status` active/inactive, monotonic `version`) and `cpms_custom_role_capabilities` (UNIQUE `(role_def_id, capability)`).
- Writes go through a validating repository boundary (all-or-nothing capability/key validation); the authorization read path re-filters stored rows, so a future writer cannot bypass capability validation.
- No membership migration, no assignment, no archive lifecycle in this slice.

## 3b. Slice 2 — authorized Clinic-local definition management (implemented)

- **Surface:** list, create, display-name edit and permitted-capability replacement for Clinic-local custom-role *definitions*. No assignment, archive, delete or status management; no WordPress-global role registration; no React/SPA/build dependency.
- **Authority (decisions 3 + 5):** the requested Clinic is a selector only. `TrustedClinicEstablisher` resolves it against durable persistence (ACTIVE membership + real Clinic + ACTIVE Organization), then `AuthorizationService::can(actor, clinic, cpms_config)` decides effective management authority. WordPress `manage_options`/`administrator` status is never consulted, so an administrator without an explicit durable membership in that Clinic is denied — there is no implicit all-Clinic, first-Clinic or `clinic_id = 0` authority. An administrator (or single-doctor installation owner) acquires authority exactly the existing way: an explicit Clinic membership carrying `cpms_config` (the built-in `cpms_manager` preset, managed on the existing Users & Access page). No parallel bootstrap authority mechanism is introduced.
- **Bounded delegation (decision 4 + 6):** the grantable set is `CustomRolePolicy::editable_capabilities()` ∩ the editor's own effective capability set, decided per capability by the existing `AuthorizationService` (so an explicit membership DENY can never be bypassed by writing a custom role). Sensitive capabilities — including `cpms_config`/`cpms_sms_config` themselves — are never delegable, so the management authority cannot be replicated into a custom role.
- **Object ownership:** every mutation reads the durable definition row scoped to the trusted Clinic and re-checks it through `AuthorizationService::authorizeForObject`; a key that exists in another Clinic is not addressable (no cross-Clinic fallback).
- **Display names (internationalization):** persistent, Unicode-general (`display_name VARCHAR(190)` utf8mb4, nullable), validated in code points (≤120) with no Persian-only or single-language rule. Unicode format characters such as ZWNJ are preserved; control characters are stripped; a malformed stored value falls back to the immutable technical key. The technical `role_key` stays immutable and independent of the display name.
- **Audit:** every successful mutation is written to the hash-chained audit log with the explicit Clinic, actor, role key and before/after change set; refused writes are not audited as changes and never partially persist (the durable `version` only advances on a committed write).

## 3c. Slice 3B — responsible-clinician ownership for private files (approved 2026-10-12)

- Every newly uploaded `doctor_private` attachment must reference a persisted Visit belonging to the same Clinic and Patient as the attachment. The responsible clinician is the Visit's durable `clinician_id`, matched to the authenticated user's established ACTIVE clinician identity; uploader, WP-global role, current user context, and patient identity are not substitutes.
- Preserve the existing trusted-Clinic boundary, Clinic-scoped `cpms_file_read` / `cpms_file_upload` and private-note capability checks, visibility rules, and object checks. A same-Clinic doctor is not automatically entitled to another clinician's private file. Listing, Visit-record metadata, streaming, and private-file soft deletion all fail closed for a non-owner; streaming authorization completes before protected bytes are read.
- New visitless `doctor_private` uploads are prohibited. Legacy visitless, missing-Visit, or mismatched Visit/private rows remain untouched and fail closed; never delete, reassign, or fabricate ownership. Visitless `patient_visible` attachments remain supported and visible under the existing rules.
- This is an additive enforcement slice only: no delegation/sharing policy, no custom-role staff assignment, no WordPress-global role mutation, and no schema migration unless an implementation blocker proves one unavoidable. Existing private-note ownership rules remain unchanged.

## 3d. Slice 4A — reversible custom-role deactivation/reactivation

- Lifecycle is a reversible `active`/`inactive` status transition, not archive or deletion. Preserve the immutable role key, definition/capability rows, membership assignments, membership GRANT/DENY overrides, and audit history. An inactive definition supplies no role-derived authority; the existing `AuthorizationService` continues to decide all precedence.
- Every real transition locks the exact Clinic-local definition, checks the expected version, and writes status/version plus its hash-chained audit event in one fail-closed transaction. Same-status requests are true no-ops. Reactivation re-establishes current Clinic management authority and revalidates every stored capability against the actor's current bounded delegation authority.
- Assignment and lifecycle transitions serialize on the same definition row. A waiting assignment must re-read current status after the lock and refuse an inactive definition without changing the existing membership.
- The server-rendered management page exposes nonce-protected, confirmed controls with consequence text and feedback. Its wp-admin entry uses ordinary `read` only so Clinic-local managers do not need WordPress-global `cpms_config`; every read and mutation still re-establishes the trusted Clinic and uses `AuthorizationService`. No WordPress-global role/capability mutation or schema change is introduced.

## 3e. Slice 4B — terminal archival of unassigned roles (implemented 2026-10-13)

- **Merged:** **PR #221** (source `arena/a3e54c26-doctor`, accepted head `c71cd3d9ce335014530bcaafc835a2eb17d88c3f`), MERGED `2026-10-13T21:06:11Z`; merge SHA `8f8ca4bfc8e4cbc828f9689833392a1482a35d1a` = current `main`. Exact merge-SHA gate closure and the evidence-honesty notes for that SHA are recorded in [`../project-current-state.md`](../project-current-state.md#custom-role-current-state-2026-10-13); the frozen contract and the dated execution checkpoints are in [`../testing/custom-role-archival-slice4b.md`](../testing/custom-role-archival-slice4b.md).
- **Lifecycle position:** archival is a third, **terminal** status (`active` → `inactive` → `archived`) distinct from the reversible deactivation of §3d. It is decided by decision 9, not by an editor's convenience.
- **Precondition:** an archive request is accepted only when the Clinic-local definition has **zero membership references of any membership status, suspended included** (`CustomRoleManagementService::archive()` → `CustomRoleRepository::has_membership_references_for_update()` inside the locked transaction). Any reference is refused; nothing is auto-reassigned, auto-suspended or silently dropped.
- **Persistence:** forward-only migration `2026_10_13_0027_custom_role_archival.php` widens the status domain to include `archived`. It adds no `archived_at` column and edits no previously applied migration. Its downgrade path is fail-closed: an enforced CHECK guard refuses a `0027 → 0026` narrowing while any archived row exists, in both default and non-strict SQL modes, because MySQL DDL cannot be represented as a transactional rollback.
- **Terminal semantics that operators and integrators must rely on:** the `role_key` stays reserved and immutable; the definition and capability rows are retained, never deleted; an archived definition confers **no** role-derived authority and is not editable, assignable, reactivatable or re-archivable. The technical key can therefore never be reused to mean something else in that Clinic.
- **Idempotence rule:** an archive retry that carries the **current** version is a reported no-op (`changed: false`, no second audit event); a retry carrying a **stale** version is refused (`stale_version`), and any mutation of an archived definition is refused with the typed terminal-state error.
- **Unknown status values fail closed rather than being "normalized to active":** `CustomRoleRepository::active_capabilities_for()` resolves authority only for a row whose status is exactly `active`, so a foreign/unexpected status confers nothing; the management UI renders such a row read-only (`unknown status — read-only`) and a status transition on it is refused with the typed invalid-state error.
- **Compatibility boundary (explicit, not a bug to paper over):** binary rollback to a pre-4B build **while archived rows exist is unsupported** — older readers normalize `archived` to `active` and older status paths do not enforce terminality. Do not run mixed old writers after archival; quiesce writers and back up before schema maintenance.
- **Suspended-member release path:** the only membership write added by 4B is the explicit, bounded reassignment of an **already suspended** member of the same Clinic to another active custom role (`StaffManagementPage::reassign_suspended_custom_role*` → `CustomRoleAssignmentService::reassign_suspended()`). Suspension state, membership GRANT/DENY rows, WordPress roles, professional identity and Location assignments are untouched by it.

## 4. Workstream ordering and non-claims

- The Custom Role & Permission Management workstream remains **before Phase 20** and is not resequenced.
- **This slice does NOT make custom roles ready for general staff assignment.** Future slices must deliver, at minimum: authorized role management (editor-authority bounding per decision 4), membership assignment, safe archive lifecycle (decision 9), Clinic-correct staff UI, and explicit compatibility with role-name-sensitive workflows (doctor-ownership, private-note, portal surfaces) before the feature can be declared complete.
- This record's approved model remains binding; a later documentation or code change may not silently relax §1 or §2.

### 4b. Status of the §4 "future slices" list (annotated 2026-10-13 — historical prose preserved above, not rewritten)

The item-2 sentence above was written when only Slice 1 existed. The listed requirements have since been delivered as merged slices, so the sentence **no longer describes current state**:

| Requirement in that sentence | Status on `main` (2026-10-13) |
|---|---|
| authorized role management (editor-authority bounding per decision 4) | Delivered — Slice 2, **PR #214** (`CustomRoleManagementService`, delegable-capability bounding) |
| membership assignment | Delivered — Slice 3B, **PR #219** (`CustomRoleAssignmentService`, membership-only write) |
| safe archive lifecycle (decision 9) | Delivered — Slice 4B, **PR #221** (terminal `archived` status + migration `0027`, zero-reference precondition) |
| Clinic-correct staff UI | Delivered for the bounded scope — `cpms-custom-roles` definition page (Slices 2/4A/4B) and the `cpms-staff` assignment + suspended-reassignment forms (Slice 3B/4B); server-rendered wp-admin only, no SPA |
| explicit compatibility with role-name-sensitive workflows (doctor-ownership, private-note, portal surfaces) | Delivered for the bounded scope — private-file responsible-clinician ownership (**PR #217**), suspend/manage of custom-role members (**PR #219**), reversible deactivation preserving assignments (**PR #220**) |

**What is still NOT claimed:** that the workstream as a whole is "complete" in any commercial or release sense; that custom roles are ready to be treated as an audited product guarantee beyond the bounded slices above; multi-Clinic/Organization provisioning UI (a pre-existing gap outside this workstream); any WordPress-global role registration, WP-role mutation or new capability vocabulary; any change to the built-in role presets or to the `DENY > GRANT > role > DENY` precedence. **Phase 20 is NOT started by this workstream, Classic remains the shipped skin, and none of the above is a commercial-readiness statement.**
