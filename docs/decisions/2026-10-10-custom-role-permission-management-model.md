# Product Decision — Custom Role & Permission Management: approved model, security boundaries, and workstream ordering

> **Document type:** Authoritative Product Decision record (owner-approved decisions frozen).
> **Recorded:** 2026-10-10.
> **Scope of this record:** documents the approved model only. Slice 1 implements the backend foundations bounded by this record; this record is not itself a broad documentation checkpoint and rewrites no historical ADR.
> **Historical ADRs remain untouched.** Where this record and an older document overlap, the precedence rules in `docs/decisions/2026-09-05-document-precedence-policy.md` apply.

---

## 1. Approved product model

1. **Custom role definitions are Clinic-local.** A custom role belongs to exactly one Clinic; the same key may exist in different Clinics with different capability sets and resolves independently per Clinic.
2. **Existing built-in CPMS roles remain available and backward-compatible** (`cpms_patient`, `cpms_secretary`, `cpms_doctor`, `cpms_accountant`, `cpms_manager`).
3. **Authorized Clinic management may configure custom roles** (management surface and its authority are future slices).
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

## 4. Workstream ordering and non-claims

- The Custom Role & Permission Management workstream remains **before Phase 20** and is not resequenced.
- **This slice does NOT make custom roles ready for general staff assignment.** Future slices must deliver, at minimum: authorized role management (editor-authority bounding per decision 4), membership assignment, safe archive lifecycle (decision 9), Clinic-correct staff UI, and explicit compatibility with role-name-sensitive workflows (doctor-ownership, private-note, portal surfaces) before the feature can be declared complete.
- Role-sensitive legacy workflows are not modified in Slice 1; a custom role never impersonates a built-in role key in those workflows.
