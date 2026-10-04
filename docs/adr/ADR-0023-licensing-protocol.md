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
- Installation identity: high-entropy random install UUID stored locally (`cpms_license_install`), registered with the server at activation. Domain is metadata — and, **when the signed document carries a `domain` claim, an additional signed activation binding** (Phase 16 Slice 2, normalization documented) — never the sole identity and never a replacement for `install_id` (spec §18).

**Signed-document format and public-key rotation (Phase 16 Slice 3).**
- Existing legacy documents have neither `schema_version` nor `key_id`. They remain verifiable only through the explicitly selected legacy public-key path (`cpms_license_public_key`); the normal product/install/date/signature checks and ordinary expiration behavior still apply. This compatibility path is bounded to that exact metadata shape: if either field is present, the document is not treated as legacy, and there is no fallback from new-format verification.
- New documents use schema version **2**: `schema_version` must be the JSON integer `2`, and `key_id` must be a bounded ASCII identifier (1–64 characters, beginning with a letter or digit; remaining characters may be letters, digits, `.`, `_`, or `-`). The identifier selects one exact public key from the CPMS-configured trusted ring (`LicenseKeys::TRUSTED_PUBLIC_KEYS_B64` / `cpms_license_public_keys`) before detached Ed25519 verification. Both fields remain inside canonical JSON and are covered by the signature.
- The trusted ring may contain multiple public keys concurrently. During rotation, CPMS can trust old and new public keys at once; after issuance moves to the new key, the old public key can be removed when documents requiring it no longer need local verification. `key_id` is an identifier, not key material; the public-key ring comes only from CPMS configuration, never from request parameters or a license document. The signed payload remains stored as the existing JSON document.
- A partial metadata pair, malformed or unknown schema version, malformed or unknown `key_id`, or signature/key mismatch fails closed as `CLINIC_LICENSE_INVALID`; no network lookup, remote key fetching, or fallback to the legacy key occurs. Verification is local and Ed25519-only.
- CPMS contains public verification keys only. Private signing keys stay outside CPMS and never enter the repository/plugin, database, or license payload. The repository's production public-key value remains a deliberate placeholder because no real release public key is available; supplying and validating a real release key remains a commercialization/release blocker. Test keys are generated only in tests.

### 3. State semantics (spec §15) — amended by employer decision 2026-09-06
Distinct states, stored + exposed:
- `ACTIVE`, `EXPIRING` (within grace boundary, warnings only), `GRACE` (default 7 days, renewal path; read/write of new business allowed with persistent warning — policy), `RESTRICTED` (still *represented* as expired/restricted/needs-renewal; **enforcement is cause-aware — amended by Phase 16 Slice 1**: ordinary commercial expiration no longer blocks new independent licensed activity, while every other `RESTRICTED` cause still does — see §5; historical access, safe export, and finishing in-progress workflow always allowed), plus `SUSPENDED`/`REVOKED` (from a *verified signed* document), `INVALID` (signature/authenticity failure), `UNKNOWN/UNREACHABLE` (network failure with no usable cache).

**Pre-activation states (employer decision — never conflate with normal license GRACE):**
- `NOT_CONFIGURED` — defensive only: no install/window row exists yet (pre-migration or broken env). Open, but Health/Admin flag it.
- `ACTIVATION_PENDING` — fresh commercial install inside its **activation window (default 7 days)** from first CPMS initialization. Setup + launch activity permitted; if no valid signed license document exists by window end → `RESTRICTED`.
- `ACTIVATION_GRACE` — pre-F10 installation upgraded to the licensing system gets a **migration grace (default 30 days)** from the first licensing migration; migration never disrupts historical data or in-progress workflows; after 30 days without a valid license → `RESTRICTED`.
- `DEVELOPMENT` — explicit, documented dev/test mode only: `CPMS_DEV_MODE` constant (wp-config.php) or `cpms_license_dev_mode` filter. **No** automatic environment/domain/localhost detection, no hidden or universal developer license in the production package; while active it is clearly visible in Admin (`🧪 DEVELOPMENT`) and the constant is documented.

**Anti-reset & time authority (employer decision):**
- Window start (`activation_window_started_at`) and type (`fresh|migration`) are persisted server-side (migration 0008, row id=1) and **never restarted** by deactivate/reactivate/reinstall while CPMS data remains; Admin UI offers no reset; ordinary license deactivation does not create a fresh trial/window. Destructive DRM is explicitly out of scope (self-hosted PHP is not tamper-proof; integrity of data always outranks anti-tamper).
- Window math uses server time from persisted state; browser clock is never authoritative. A server clock rolled back cannot extend the window beyond its configured duration (start clamped so the window never exceeds the policy length).

Critical distinctions enforced in code and tests:
- network unreachable ≠ invalid (bounded grace on last good state);
- vendor outage after valid activation has nothing to do with pre-activation windows — cached signed entitlement + existing grace/unreachable policy apply;
- signed revoked/suspended ≠ network failure;
- signature/authenticity failure ≠ network failure (treat as INVALID → restricted; never auto-destruct);
- offline activation uses a signed document through the same authenticity/install-binding/expiry validation as the online path; no shared secret or private signing key ships in the plugin.

### 4. License → Entitlements → Capabilities
- Central `EntitlementRegistry`: a signed entitlement set maps feature keys (`handwriting`, `ocr`, `reports.advanced`, `multi_doctor`, `staff`, `backup.remote`, `updates`) and numeric limits (`doctors`, `staff`, `branches`). Business logic asks the registry; no scattered `if plan == X` (spec §17).
- Unknown future feature keys **fail closed** for that feature without breaking the plugin.
- Downgrades never delete/deactivate historical entities; only *creation/activation* beyond limits is blocked — deterministically and race-safely (UNIQUE constraints + transactional checks; tests required).

### 5. Operation gating
`assert(OP_*)` decisions per existing enum; SUSPENDED/REVOKED/INVALID block *new* protected ops with `CLINIC_LICENSE_BLOCKED` (503).

**`RESTRICTED` is cause-aware (amended by Phase 16 Slice 1).** The gate distinguishes the *cause* of `RESTRICTED` from the existing current-state `status` + `reason`:
- **Ordinary commercial expiration** — a previously valid signed document that is simply past expiry grace, i.e. the internally derived cause `expired` — **no longer blocks** the protected new-business operations. The annual term ending alone does not freeze ordinary clinical new business.
- **Every other `RESTRICTED` cause still blocks, fail-closed:** activation-window exhaustion (`activation_window_expired`), migration-grace exhaustion (`migration_grace_expired`), vendor-unreachable/stale cache (`expired_unreachable`), and any absent/empty/unknown/malformed cause.
- The cause match is exact; `SUSPENDED`/`REVOKED`/`INVALID`/`UNREACHABLE` and unknown states are **not** relaxed by this amendment.
- Representation is unchanged: an ordinarily-expired license is still reported as `RESTRICTED` with reason `expired` and `needs_renewal`, and the gate still reports itself read-only, so status and renewal visibility are unaffected.
- `CLINIC_LICENSE_BLOCKED` remains the code wherever the gate actually denies an operation.

**Signed domain binding (Phase 16 Slice 2).** A verified signed document that carries a `domain` claim is valid only for the canonical local site domain, derived from WordPress `home_url()` with the single documented rule (lower-case + leading `www.` removed; no IDN/punycode/path/port policy, and never the request host/`HTTP_HOST`). The same canonicalization is applied to both sides of the comparison.
- **Mismatch — or a present but empty/non-string/unusable claim — fails closed:** the stored document is kept, the state is `RESTRICTED` with the bounded internal reason `binding_mismatch`, and the protected new-business operations are denied through the existing gate (only the exact `expired` cause keeps the ordinary-expiration exception — `binding_mismatch` never does). Reads/history, patient update, cancel/hygiene, in-progress clinical workflow completion, backup/restore, export/recovery and security functions remain open; nothing is deleted.
- **Reversible:** with the document untouched, restoring the bound local domain restores the normal state; no local persistence or migration is added and no `WP_ENVIRONMENT_TYPE`-style bypass exists.
- **A document without a `domain` claim is legacy/unbound** and keeps the pre-slice behavior. `install_id` verification is unchanged.
- Refresh sends the current canonical domain through the existing `VendorGateway` contract (already an allowed metadata category per ADR-0028 §2).

In all of these cases:
- reads/history/export stay open;
- in-progress visit clinical workflow (note/prescription/complete), payment/checkout to finish the current visit remain **allowed** (spec §16) — implemented as exempt operations on the gate, mirroring the F4 walk-in read-only precedent (`VisitLicenseGateTest`).

### 6. Error codes & logs
New codes registered in `docs/api/error-codes.md`: `CLINIC_LICENSE_BLOCKED` (exists), `CLINIC_LICENSE_UNREACHABLE`, `CLINIC_LICENSE_INVALID`, `CLINIC_LICENSE_RESTRICTED`, `CLINIC_LICENSE_ENTITLEMENT`, `CLINIC_LICENSE_LIMIT_REACHED`, `CLINIC_LICENSE_ACTIVATION_FAILED`. Phase 16 Slice 2 adds **no new API error code**: `binding_mismatch` is a bounded *internal state reason* surfaced through the existing `CLINIC_LICENSE_BLOCKED` (503). Operational logs carry only license/install identifiers — never PHI, never signing secrets, never full tokens.

### Distinction table (must never be conflated)

| Concept | Trigger | Effect while it lasts | Ends how |
|---|---|---|---|
| **ACTIVATION_PENDING** | Fresh install, no signed doc yet, inside 7-day window (from migration 0008) | Setup/launch allowed; Admin/Health show countdown | Signed doc stored, or window ends → RESTRICTED |
| **ACTIVATION_GRACE** | pre-F10 install upgraded to licensing, no doc yet, inside 30-day migration grace | Uninterrupted operation (no sudden disruption of existing customer) | Signed doc stored, or grace ends → RESTRICTED |
| **GRACE (license)** | Valid cached doc *expired* within 7-day renewal grace | New business allowed with persistent warning (renewal path) | Renewed doc, or grace ends → RESTRICTED |
| **UNREACHABLE** | Vendor server unreachable **after** a valid cached doc | Cached signed state governs (bounded 3-day post-expiry window), then explicit UNREACHABLE; network failure ≠ invalid | Server reachable again / doc refresh |
| **RESTRICTED** | Entitlement expired beyond grace, or activation window/migration grace ended without a doc | **Cause-aware (Phase 16 Slice 1):** ordinary commercial expiration (verified signed doc past grace — internal cause `expired`) does *not* block new independent activity; every other cause (activation-window / migration-grace exhaustion, vendor-unreachable/stale, absent/unknown cause) does, fail-closed. History/export/safe completion/hygiene always open | Valid signed doc stored |
| **SUSPENDED / REVOKED** | *Verified signed* document flags it (never from network failure) | Same operational posture as the *blocking* RESTRICTED causes plus explicit reason; distinct from outage | New signed doc from vendor |
| **DEVELOPMENT** | Explicit `CPMS_DEV_MODE`/`cpms_license_dev_mode` only | Everything open; visible 🧪 badge; no auto-detection | Constant/filter removed |

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
