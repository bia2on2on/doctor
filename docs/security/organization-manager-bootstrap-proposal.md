# Organization Manager bootstrap — Owner decision and implementation contract

**Decision status:** Approved by the Owner on 2026-10-18 for Draft PR #244. This supersedes the previous proposal requiring an external ownership-verification authority. `manage_options` is authorized only for the narrowly scoped first-Organization-Manager bootstrap described here.

## Approved authority

A currently authenticated WordPress Administrator may explicitly activate the first Organization Manager for one specifically selected, active Organization and one specifically selected, existing WordPress user. No first Organization, user, or manager is inferred or automatically selected. Installation, upgrade, login, and ordinary requests never perform this operation.

The administrator must submit a valid action nonce and an explicit final confirmation. The server rechecks the current actor's `manage_options` capability and identity, exact Organization, Organization lifecycle, target-user existence, existing Organization memberships, and absence of manager assignments that would make the initial designation conflicting or ambiguous.

## Narrow effect and non-effects

The operation creates only one active `organization_manager` Organization membership with the acting Administrator recorded as creator. It must not change WordPress roles, add Clinic membership or Location assignment, create capability overrides, create Clinician records, or grant clinical, financial, PHI, Clinic, or Location capabilities. Administrator status is not an AuthorizationService bypass. In particular, it does not override explicit Organization DENY for ordinary Organization operations or Clinic DENY for Clinic-scoped operations.

## Persistence and audit contract

- Lock the selected Organization row before checking or creating the initial assignment.
- Serialize same-Organization bootstrap attempts. Exactly one valid first assignment can be established.
- The exact retry for the same active manager is an idempotent no-op and does not append another bootstrap audit event. A different target, suspended/inactive assignment, any other manager row, or other conflicting target assignment fails closed; bootstrap does not silently replace or reactivate assignments.
- Insert the membership and `ORGANIZATION_MANAGER_BOOTSTRAPPED` hash-chained audit event in the same transaction. Audit persistence failure rolls back the membership. The audit identifies the Administrator actor, selected Organization, target user, and narrow authority source; it stores no nonce or other request secret.
- After bootstrap, Organization Manager grants/revocations use the existing Organization-scoped capability policy (`DENY > GRANT > Manager preset`) and row-lock-protected last-active-manager invariant. No Administrator override is added to those ordinary operations.

## Classic admin surface

A WordPress Administrator receives a Classic RTL admin form with no default Organization or user selection, explicit selectors, required confirmation, nonce protection, server-side capability checks, and safe Persian success/error messages. Suspended Organizations are visible as unavailable and are rejected by the domain service. The form does not provision Clinics or grant ordinary powers.

## Required evidence before release

DB-backed tests must cover authorized bootstrap, non-Administrator denial and forged actor IDs, forged Organization/user IDs, invalid nonce and missing confirmation, suspended Organizations, exact duplicate idempotency, conflicting/ambiguous assignment refusal, concurrent competing submissions, cross-Organization isolation, absence of implicit Clinic/clinical/financial grants, audit rollback and hash-chain integrity, and last-active-manager protection after bootstrap. Full CI and independent security/product acceptance remain mandatory. PR #244 stays Draft and unmerged until those gates pass.
