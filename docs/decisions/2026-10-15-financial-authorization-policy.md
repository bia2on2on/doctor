# Product Decision — Financial Authorization Policy (Secretary / Doctor / Accountant / Clinic Manager)

> **Document type:** Authoritative Product Decision record (owner-approved policy, frozen 2026-10-15).
> **Recorded:** 2026-10-15.
> **Scope of this record:** documents the approved financial authorization policy and the bounded
> implementation contract derived from it. It rewrites no historical ADR and erases no prior decision.
> **Historical decisions remain untouched and authoritative for their subject:**
> `docs/permissions/permission-matrix.md` (v1.1 role/capability matrix and its §4 role-authority tables),
> [`2026-10-10-custom-role-permission-management-model.md`](2026-10-10-custom-role-permission-management-model.md)
> (custom-role permission model), ADR-0026 (sensitive capabilities), ADR-0030 (deliberate role-capability
> override), ADR-0031 (Organization/Clinic/Location-scoped authorization). Where this record and an older
> document disagree about **financial** authorization, this record is the newer owner-approved decision and
> the older text is annotated in place, never deleted; precedence in general remains governed by
> [`2026-09-05-document-precedence-policy.md`](2026-09-05-document-precedence-policy.md).

> **Evidence discipline:** everything below that is a *decision* is quoted from the approved policy.
> Everything that is an *engineering interpretation* needed to implement it is explicitly marked
> **[inference]** with its rationale, so a reviewer can accept or reject it independently.

---

## 1. Approved policy (verbatim decisions)

1. **SECRETARY.** Ordinary payment registration remains permitted. Payment void/refund denied by default.
2. **DOCTOR.** Sensitive payment operations require effective permission **and** verified ownership of the
   actual service/transaction. A patient relationship alone is insufficient.
3. **ACCOUNTANT.** Preserve established legitimate authority.
4. **CLINIC MANAGER.** May explicitly delegate bounded sensitive financial capabilities to authorized
   members. Delegation must be revocable, audited, Clinic-scoped and subject to existing GRANT/DENY
   precedence. No default medical-record access is created.

Additional standing constraints stated with the decision: update the existing permission matrix and related
Product Decision documentation **without erasing historical decisions**; exist new financial records are
preserved; existing explicit grants are evaluated before changing effective default permissions; avoid
unnecessary migrations; no parallel permission engine; no unrestricted financial capabilities granted to
solve workflow problems.

## 2. What changes in effective defaults (and what does not)

| Capability | Secretary before | Secretary after | Reason |
|---|---|---|---|
| `cpms_payment_create` | default grant | **default grant (unchanged)** | decision 1 — ordinary registration remains permitted |
| `cpms_payment_void` | default grant | **denied by default** | decision 1 |
| `cpms_payment_refund` | default grant | **denied by default** | decision 1 |
| `cpms_invoice_adjust` / `cpms_invoice_void` | default grant | **default grant (unchanged)** | not named by the policy; changing them would be an unapproved widening or narrowing of a different decision — **[inference]** recording them as out of scope this slice |
| `cpms_payment_void` / `cpms_payment_refund` (Doctor) | default grant, unconditional | **default grant, additional verified-transaction-ownership requirement** | decision 2 |
| `cpms_payment_void` / `cpms_payment_refund` / `cpms_invoice_adjust` / `cpms_invoice_void` (Accountant) | default grant | **default grant (unchanged)** | decision 3 — established legitimate authority preserved |
| Manager preset | no financial capability | **no financial capability (unchanged)** | decision 4 — the manager's path to sensitive finance is explicit delegation, never a default grant |
| Medical record capabilities (`cpms_medical_read`, `cpms_private_note_*`, `cpms_note_*`, `cpms_rx_*`, `cpms_file_*` clinical) | Secretary/Manager: none | **none (unchanged)** | decision 4 — "no default medical-record access is created" |
| Explicit membership GRANT / DENY rows (`cpms_membership_capabilities`) | honored | **honored, unchanged precedence** | existing explicit grants are evaluated before defaults; nothing is erased |

The single default-permission change is therefore: **`cpms_payment_void` and `cpms_payment_refund` are removed
from the built-in `cpms_secretary` role preset.** No capability is added anywhere, no new capability slug is
introduced, and the 46-capability catalogue is unchanged.

## 3. Resolution rule (unchanged engine, one added conjunct)

`AuthorizationService` remains the only authorization engine and its precedence is unchanged:

```
explicit membership DENY  >  explicit membership GRANT  >  role capabilities  >  deny by default
```

**[inference] — the ownership conjunct.** Decision 2 is implemented as an *additional conjunct on the
role-preset branch only*, for the two sensitive payment capabilities:

* if the effective authority for `cpms_payment_void` / `cpms_payment_refund` comes from an **explicit
  membership GRANT**, the actor is authorized by deliberate Clinic-scoped grant (a pre-existing explicit
  grant of an existing installation, or a Clinic-manager delegation made under decision 4). No ownership
  requirement is added — this is exactly "preserve established legitimate authority" and "evaluate existing
  explicit grants before changing effective default permissions".
* if the effective authority comes from the **built-in `cpms_doctor` role preset**, the actor must
  *additionally* be the verified owner of the transaction. This is decision 2.
* the ownership requirement never applies to the Accountant preset (decision 3), to the Manager preset
  (which holds no payment capability), or to any custom role (which cannot contain a sensitive capability
  at all — `CustomRolePolicy::SENSITIVE_CAPS`).
* an explicit DENY still defeats everything, including a delegation.

**Verified ownership** is a durable server-side fact, never a payload field: the payment → invoice →
Visit chain is read from persistence and the Visit's responsible clinician is resolved to its linked
WordPress user (`clinicians.wp_user_id`, structurally unique via `u_clinician_user`). Missing, ambiguous or
unresolvable ownership fails closed. Patient identity, patient relationship, "same Clinic", the WordPress
global role, the acting user's own `received_by_wp_user_id` on the payment, and any request parameter are
**not** ownership evidence.

## 4. Clinic-manager delegation contract

* **Authority to delegate:** effective, Clinic-scoped `cpms_config` on an ACTIVE membership of the exact
  Clinic (`AuthorizationService`), resolved against durable membership — never a WordPress-global role,
  never `manage_options`, never a payload clinic.
* **Bounded set:** only the sensitive **financial** capabilities that are the subject of the policy:
  `cpms_payment_void`, `cpms_payment_refund`, `cpms_invoice_void`, `cpms_invoice_adjust`. Clinical,
  private-note, medical-record, export, audit, settings and role-management capabilities are **not**
  delegable — decision 4's "no default medical-record access" and the least-privilege rule are preserved
  (`CustomRolePolicy::SENSITIVE_CAPS` stays the non-delegable-by-role set).
* **Storage:** the existing per-membership capability override storage
  (`cpms_membership_capabilities` via `MembershipService`) — no new table, no new engine, no new
  authorization path. **No migration is introduced.**
* **Revocable:** revocation removes exactly the one delegation row; the member immediately returns to
  role-preset/default-deny resolution.
* **Audited:** every successful grant and revocation is written to the existing hash-chained audit log
  (`FINANCE_DELEGATION_GRANTED` / `FINANCE_DELEGATION_REVOKED`) with Clinic, actor, target membership,
  capability and before/after effect, inside the same transaction as the change (fail-closed: a failed
  audit rolls the change back).
* **Clinic-scoped:** the target must be an ACTIVE membership of the delegating manager's exact Clinic (the
  membership row itself is the Clinic fact — a foreign/missing/suspended membership is refused and is never
  addressable).
* **GRANT/DENY precedence preserved:** an explicit DENY on the target membership for that capability can
  never be bypassed by a delegation — the delegation write is refused (fail closed) instead of silently
  creating an inert "grant".
* **Self-delegation** is permitted (a manager is a member of the Clinic and a small installation may have no
  other eligible member); it is explicit, bounded, revocable and audited like any other delegation.
  **[inference]** — flagging it as the one boundary a reviewer may want to tighten.
* **No side effects:** delegation never writes a WordPress-global role or capability, never creates a
  custom role, never changes membership status, role key, scope mode or Location assignment, and never
  touches existing financial records.

## 5. Explicitly out of scope / not claimed

* No new capability slug, no new permission vocabulary, no second authorization engine, no second
  permission store.
* No change to Organization/Clinic/Location tenancy, trusted-Clinic establishment, or object-ownership
  rules (those outrank role capabilities and are untouched).
* No change to invoice/visit state machines, void-window rules, refund arithmetic or financial-record
  shape; existing financial rows are read, never rewritten, by this policy.
* No change to the patient relationship model; a patient relationship is not, and never becomes,
  authorization.
* No WordPress-global role mutation (removing a preset capability never mutates the WP role of any user;
  presets are re-synchronized from the same single source of truth, `RolesAndCapabilities`).
* No claim about commercial readiness, hosting, release ZIP semantics, or Phase 20.
* **[inference]** The delegation UI is deliberately the smallest surface that satisfies decision 4: one
  Clinic-scoped page, server-rendered, nonce-protected, explicit capability selection, no bulk operations.

## 6. Rollback

Reverting this policy is a code-only revert of the preset change and the added enforcement path; the
delegation rows live in existing storage and are visible/removable through the same UI, so no data migration
or repair is required in either direction. Historical decisions and this record stay in the repository
append-only.
