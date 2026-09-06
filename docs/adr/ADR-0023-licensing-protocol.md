# ADR-0023 — Licensing Protocol: Seam, Local Signed State, Data-Plane Privacy

وضعیت: **Accepted (F10)** | تاریخ: 2026-09-06 | تأیید کارفرما: F10 spec §14–§18
مراجع: F10 spec §7/§8/§14–§18/§32؛ F3 LicenseGate seam (ActiveLicenseGate)؛ docs/security/threat-model.md؛ engineering-baseline.md §21

## Context

F1–F9 shipped CPMS with a deliberate licensing **seam**: `Domain/Licensing/LicenseGate` (interface) + `App::licenseGate()` (always-ACTIVE `ActiveLicenseGate`) so business services never touch license infrastructure directly. F10 must implement real commercial licensing behind that seam while preserving three hard invariants:

1. **Privacy:** the vendor license service is a *control plane*; it must never receive PHI/medical/financial content (F10 spec §7).
2. **License safety:** expiry/suspension/revocation/vendor outage never deletes, corrupts, hides, or ransoms medical data; historical access + safe export + finishing in-progress care continue (spec §8/§16).
3. **Network discipline:** no license call on ordinary page loads; vendor outage ≠ invalid license; locally cached, previously verified state governs during outages (spec §15/§26).

## Decision

### 1. Boundaries
- **Data Plane** (customer WordPress): all medical/clinical/financial data. **Control Plane** (vendor): commercial account, entitlements, release metadata, update authorization only. Formalized in ADR-0028 (data/control plane). No medical data flows to the control plane in any F10 path.
- Business services keep depending only on `LicenseGate`. The F10 real implementation is `SignedLicenseGate` (name below), substituted solely inside `App::licenseGate()` — exactly as F1–F9 designed. `ActiveLicenseGate` remains as the dev/test/CI fixture.

### 2. Local state, not remote truth
- The gate reads a **local, signed license document** persisted on the customer site (`cpms_license_state`, migration 0008). The document is produced by the vendor server, **Ed25519-signed** by a vendor release-signing key whose **public** key ships in the plugin.
- A background job (`license.refresh`) periodically fetches a fresh document; all state transitions are computed **locally** from the last successfully verified document + wall-clock expiry. Ordinary page loads never hit the network.
- Installation identity: high-entropy random install UUID stored locally (`cpms_license_install`), registered with the server at activation. Domain is metadata only (normalization documented), never the sole identity (spec §18).

### 3. State semantics (spec §15)
Distinct states, stored + exposed:
- `ACTIVE`, `EXPIRING` (within grace boundary, warnings only), `GRACE` (default 7 days, renewal path; read/write of new business allowed with persistent warning — policy), `RESTRICTED` (new independent licensed activity blocked per entitlement; historical access, safe export, and finishing in-progress workflow allowed), plus `SUSPENDED`/`REVOKED` (from a *verified signed* document), `INVALID` (signature/authenticity failure), `UNKNOWN/UNREACHABLE` (network failure with no usable cache).

Critical distinctions enforced in code and tests:
- network unreachable ≠ invalid (bounded grace on last good state);
- signed revoked/suspended ≠ network failure;
- signature/authenticity failure ≠ network failure (treat as INVALID → restricted; never auto-destruct).

### 4. License → Entitlements → Capabilities
- Central `EntitlementRegistry`: a signed entitlement set maps feature keys (`handwriting`, `ocr`, `reports.advanced`, `multi_doctor`, `staff`, `backup.remote`, `updates`) and numeric limits (`doctors`, `staff`, `branches`). Business logic asks the registry; no scattered `if plan == X` (spec §17).
- Unknown future feature keys **fail closed** for that feature without breaking the plugin.
- Downgrades never delete/deactivate historical entities; only *creation/activation* beyond limits is blocked — deterministically and race-safely (UNIQUE constraints + transactional checks; tests required).

### 5. Operation gating
`assert(OP_*)` decisions per existing enum; RESTRICTED/SUSPENDED/REVOKED/INVALID block *new* protected ops with `CLINIC_LICENSE_BLOCKED` (503), while:
- reads/history/export stay open;
- in-progress visit clinical workflow (note/prescription/complete), payment/checkout to finish the current visit remain **allowed** (spec §16) — implemented as exempt operations on the gate, mirroring the F4 walk-in read-only precedent (`VisitLicenseGateTest`).

### 6. Error codes & logs
New codes registered in `docs/api/error-codes.md`: `CLINIC_LICENSE_BLOCKED` (exists), `CLINIC_LICENSE_UNREACHABLE`, `CLINIC_LICENSE_INVALID`, `CLINIC_LICENSE_RESTRICTED`, `CLINIC_LICENSE_ENTITLEMENT`, `CLINIC_LICENSE_LIMIT_REACHED`, `CLINIC_LICENSE_ACTIVATION_FAILED`. Operational logs carry only license/install identifiers — never PHI, never signing secrets, never full tokens.

## Consequences
+ Privacy boundary preserved (control plane sees only install id, license id, normalized domain, version/compat metadata, entitlement state, activation count).
+ Outage behavior bounded and safe; revoked vs unreachable distinguishable via signatures.
+ Commercial plan composition changeable server-side without plugin releases (entitlement document), per spec §17.
− Vendor server itself is outside this repo: contract + client + fixtures defined here; production server ops documented as runbook (BLOCKED_BY_ENVIRONMENT for real end-to-end).
− Customers on permanently-offline intranets must pre-fetch license documents (documented activation path).

## Alternatives
- Always-online license enforcement: rejected (violates outage ≠ invalid + privacy + shared-hosting reality).
- Unsigned local state: rejected (spoofable; cannot distinguish revoke from outage).
- Remote kill switch: out of scope and prohibited (spec §9).
