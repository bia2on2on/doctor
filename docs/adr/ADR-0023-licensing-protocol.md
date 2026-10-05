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
- New documents use schema version **2**: `schema_version` must be the JSON integer `2`, and `key_id` must be a bounded, case-sensitive ASCII identifier of at most **64 bytes**, starting with an ASCII letter (`[A-Za-z]`); remaining characters may be ASCII letters, digits, `.`, `_`, or `-`. The identifier selects one exact public key from the CPMS-configured trusted ring (`LicenseKeys::TRUSTED_PUBLIC_KEYS_B64` / `cpms_license_public_keys`) before detached Ed25519 verification. Both fields remain inside canonical JSON and are covered by the signature.
- The trusted ring may contain multiple public keys concurrently. During rotation, CPMS can trust old and new public keys at once; after issuance moves to the new key, the old public key can be removed when documents requiring it no longer need local verification. `key_id` is an identifier, not key material; the public-key ring comes only from CPMS configuration, never from request parameters or a license document. The signed payload remains stored as the existing JSON document.
- A partial metadata pair, malformed or unknown schema version, malformed or unknown `key_id`, or signature/key mismatch fails closed as `CLINIC_LICENSE_INVALID`; no network lookup, remote key fetching, or fallback to the legacy key occurs. Verification is local and Ed25519-only.
- CPMS contains public verification keys only. Private signing keys stay outside CPMS and never enter the repository/plugin, database, or license payload. The repository's production public-key value remains a deliberate placeholder because no real release public key is available; supplying and validating a real release key remains a commercialization/release blocker. Test keys are generated only in tests.

**Signed activation identity (Phase 16 Slice 4) — client boundary for "one standard License has at most one active production activation at a time".**
- Global uniqueness is decided **only by the external central license service**, which issues and supersedes activation records and alone can know whether another production activation exists. CPMS locally verifies signed state and never counts activations, never claims from local state that "another installation is active", and implements no central-service logic. The central service itself, its one-active enforcement, revocation/suspension policy for superseded activations, activation-count UI and any rebind workflow are **not implemented** by this slice.
- A **v2** document (schema version 2) MAY carry `activation_id`: the central service's activation **record** identifier for this installation. It is not the installation identity — `install_id` stays mandatory and independently verified — it does not replace the signed `domain` binding (independently enforced), it is never tenancy/authorization authority, and it must be an opaque vendor-issued value containing no PHI and no secret (it is stored in plain local state and is not a bearer credential).
- **Compatibility rule (bounded, explicit): OPTIONAL on v2, strictly validated when present.** Absent ⇒ the document is accepted exactly as before (already-accepted v2 documents and fixtures carry no activation record because the central service does not issue any yet; "absent" means *no activation record claimed*, never a grant and never an error). Present ⇒ it must be a string of 1..**64** bytes matching the conservative ASCII identifier grammar `[A-Za-z0-9][A-Za-z0-9._-]*` (`LicenseActivationId::is_valid()` — the same bounded-identifier discipline as `key_id`, but a separate validator: `key_id` is never an activation identity). A present but empty, non-string, oversized or malformed value fails closed as `CLINIC_LICENSE_INVALID` and nothing is persisted. Legacy (unversioned) documents predate the claim: a legacy document carrying `activation_id` is rejected, so any stored handle has v2 key-ring provenance only. No schema v3 is introduced.
- The value sits inside the canonical signed JSON like every other key, so changing, adding or removing it after signing invalidates the document. It is persisted only through the existing stored payload JSON (`cpms_license_state.payload_json`) — **no new table/column and no migration**. Online activation, refresh and offline signed-document activation share the same verification/storage path. CPMS does not echo the handle back to the vendor: the activate/refresh request allowlist (ADR-0028 §2) is unchanged.
- **No enforcement expansion:** no status, entitlement or gate decision reads `activation_id`; its presence or absence creates no clinical lock. Historical data, backup/restore, export/recovery, security functions and safe in-progress care remain exactly as governed by §5.

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

### 7. Central License Service Contract (Phase 16 Slice 5 + Slice 6A + Slice 6B) — **CLIENT CONTRACT / EXTERNAL SERVICE SPECIFICATION**

**Nature of this section.** This section specifies the boundary between CPMS and the **future external Central License Service**; rows marked CURRENT describe executing behavior. Phase 16 Slice 6A delivered local verification/storage of the optional v2 `update_rights_until` claim; Phase 16 Slice 6B enforces it in the update plane (see §7.3.4) using the signed release-manifest `release_kind`. The external central service, billing/payment, activation counting, new persistence/migration, and production signing-key provisioning are **not** implemented here. Anything else not already delivered in CPMS is marked **FUTURE/OPEN** and must not be read as implemented. This section remains the single source of truth for the CPMS↔central-service contract; no second licensing architecture document is introduced.

#### 7.1 Authority split

**Central License Service is authoritative for:**
- the commercial **Organization** account/reference that owns the License;
- **License** commercial state (term, renewal, entitlement composition);
- **production activation records** (`activation_id` is the opaque record identifier);
- the invariant **"at most one ACTIVE production activation per standard License"** — a fact only the central service can know;
- **activation supersession / rebind** (the central service decides which activation record is the current one);
- **issuance of signed license documents** (Ed25519, private key never in CPMS);
- **renewal decisions**.

**CPMS is authoritative only for:**
- its own local `install_id` (`cpms_license_install`, high-entropy, 32 hex);
- its own local configured domain identity (canonical `home_url()` host — the only derivation in `src/`);
- local cryptographic verification (Ed25519, key selection from the CPMS-configured trusted ring);
- its local signed state (`cpms_license_state.payload_json`);
- local product/entitlement decisions **allowed by the verified document**.

**Hard boundary:** CPMS must **never** claim, from local state, that another installation is globally active, that a global activation slot is free, or that an activation is unique. CPMS never counts activations. Local state is *one installation's view*, not global truth. Conversely the central service is never a patient/medical datastore (ADR-0028).

#### 7.2 Activation model

Conceptual relation (no new CPMS schema, table, or column is implied):

```
Organization  ──owns──▶  License  ──has──▶  Activation  ──identifies──▶  install_id
                                            (activation_id)              ├── domain
                                                                         └── environment class
```

- **Organization** is the commercial License owner. **Organization ≠ Installation.** A Clinic is *not* the primary License owner.
- **License** is the commercial object sold. **Standard** sale ⇒ **at most one ACTIVE production activation at a time**.
- **Activation** is the central activation *record*; `activation_id` is its opaque, vendor-issued identifier (delivered as an optional signed claim on v2 documents — §2, Phase 16 Slice 4).
- **`install_id`** is the technical activation identity (mandatory, independently verified — never replaced by `domain` or `activation_id`).
- **`domain`** is an *additional* signed activation binding (Phase 16 Slice 2), not an identity and not a substitute.
- **environment class** is the `production` / `staging` / `development` / `local` classification carried as request metadata (`WP_ENVIRONMENT_TYPE`, defaulting to `production`).

Rules for standard production Licenses:
- at most **one ACTIVE production activation** at any time;
- activating or rebinding a **replacement** must **supersede** the previous production activation **centrally** (the old record stops being the active one);
- **no requirement to physically delete the old CPMS installation** — supersession is a central record state, not a remote instruction to the old host;
- **the client cannot enforce global uniqueness by itself**; only the central service can.

**Deliberately not frozen here:** staging / development / DR activation policy (including whether non-production environments consume the single production activation) is **OPEN** (§7.9). Enterprise multi-activation policy is **OPEN**.

#### 7.3 ACTIVATE request/response contract

##### 7.3.1 CURRENT — exactly what executing CPMS sends

`LicenseService::activateWithKey()` builds the request; `HttpVendorGateway::allowlisted()` is the hard allowlist. Anything outside it is dropped before transmission.

| Field | Source in executing code | Notes |
|---|---|---|
| `install_id` | `LicenseService::installId()` → `cpms_license_install.install_id` | 32 hex chars; the technical activation identity |
| `environment` | `LicenseService::environment()` = `WP_ENVIRONMENT_TYPE` or `'production'` | metadata only, never a bypass |
| `license_key` | admin-entered key (`SystemPage::licenseActivate`) | presented once at activation |
| `version` | `CPMS_VERSION` or `'dev'` | plugin version |
| `wp_version` | `get_bloginfo('version')` or `''` | compatibility metadata |
| `php_version` | `PHP_VERSION` | compatibility metadata |
| `domain` | `LicenseService::domain()` = canonical `home_url()` host (lower-case, leading `www.` removed) | sent since Phase 16 Slice 2 |

The **full** `HttpVendorGateway::allowlisted()` set is exactly: `install_id`, `license_id`, `environment`, `license_key`, `version`, `wp_version`, `php_version`, `domain`. On *activation* CPMS currently populates all of them **except `license_id`** (it has none yet — the server assigns it).

**Transport (CURRENT):** `POST {server_url}/activate`, JSON body, `Content-Type: application/json`, `Accept: application/json`, header `X-CPMS-Install: <install_id>`. HTTPS-only (`http://` ⇒ `CLINIC_LICENSE_ENDPOINT_INSECURE`), SSRF-guarded destination, `redirection => 0`, a single default request timeout of **10 s** (`HttpVendorGateway::DEFAULT_TIMEOUT`; no separate connect timeout and **no retry inside the gateway** — retry/backoff belongs to the `license.refresh` job only).

**Response envelope (CURRENT):** `{"payload": {…}, "signature_b64": "…"}`. Any other shape ⇒ `CLINIC_LICENSE_MALFORMED`.

**Response status classification (CURRENT):** `401`/`403` ⇒ `CLINIC_LICENSE_ACTIVATION_FAILED` (permanent); `429` ⇒ `CLINIC_LICENSE_RATE_LIMITED` (retryable); `408`/`5xx` ⇒ `CLINIC_LICENSE_SERVER_ERROR` (retryable); any other non-2xx ⇒ `CLINIC_LICENSE_SERVER_ERROR` (permanent); WP HTTP error ⇒ `CLINIC_LICENSE_UNREACHABLE` (retryable).

##### 7.3.2 CURRENT — what the signed payload must already contain to be accepted

`LicenseService::verifyAndStore()` is the single ingestion seam (shared by online activation, offline document activation, and refresh). It enforces:

- `product` === `'cpms'`;
- `install_id` === the local `install_id` (**install binding**);
- `license_id` non-empty string;
- `expires_at` integer > 0;
- `issued_at` optional integer; when present, `expires_at >= issued_at`;
- a valid detached Ed25519 signature — legacy (unversioned, single configured key) or v2 (`schema_version: 2` + `key_id` selecting exactly one key from the CPMS-configured ring). Partial metadata, malformed/unknown `schema_version` or `key_id`, or signature/key mismatch ⇒ `CLINIC_LICENSE_INVALID`, fail-closed, nothing persisted;
- optional v2 `update_rights_until`: if present, a PHP/JSON integer greater than zero; absent remains accepted. Legacy/unversioned documents must not carry the claim, because it requires v2 key-ring provenance. It remains a field of the canonical signed payload, is stored only inside the existing `payload_json`, and malformed/provenance-invalid documents are rejected as `CLINIC_LICENSE_INVALID` before persistence. When present, the update plane consumes it read-only as the version-rights boundary (`LicenseService::updateRightsUntil()` → `UpdateRightsBoundary`); absent or invalid claims leave update behavior unchanged.

##### 7.3.3 FUTURE — the minimum a successful v2 activation response must carry

A successful **v2** activation response from the central service should carry, at minimum, the already-delivered signed claims:

| Claim | Delivered today | Purpose |
|---|---|---|
| `install_id` | ✅ mandatory | install binding |
| `domain` | ✅ optional, signed | domain binding (`binding_mismatch` when it mismatches) |
| `activation_id` | ✅ optional on v2, validated when present | central activation-record handle (1..64 bytes, `[A-Za-z0-9][A-Za-z0-9._-]*`) |
| `schema_version` | ✅ `2` (JSON integer) | format selection |
| `key_id` | ✅ bounded ASCII id ≤ 64 bytes, `[A-Za-z][A-Za-z0-9._-]*` | selects one trusted public key |
| `license_id` | ✅ mandatory | CPMS license identifier |
| `issued_at` / `expires_at` | ✅ supported | term/expiry fields already enforced |
| `entitlements.features` / `entitlements.limits` | ✅ supported | feature flags + numeric limits (`doctors`, `staff`, `branches`) |
| `update_rights_until` | ✅ optional v2 claim; positive JSON integer; forbidden on legacy | ordinary release-publication rights boundary; stored only in signed `payload_json`; **enforced in the update plane (Slice 6B)** — ordinary releases after the boundary are unavailable as `update_rights_expired`, signed `release_kind=security` stays eligible, and the claim never grants `updates` |
| `revoked` / `suspended` / `reason` | ✅ read from the signed payload by `LicenseStateMachine` | explicit signed commercial verdict (issuance policy = OPEN) |

**Explicitly FUTURE/OPEN — NOT implemented, NOT wire fields today:** an Organization wire identifier (`org_ref`/`organization_id`/`org_slug`), plan/capacity values (`plan`, `seats`, broader `version_rights` policy), a renewal/term-extension field, an activation-count field, a rebind/supersede request field, and any `License → Organization` claim inside the signed payload. The commercial *unit* is the Organization (product decision, and the rationale recorded in the Phase 16 Slice 1 note in `SignedLicenseGate`), but **CPMS currently has no Organization claim in the signed document and sends no Organization field in any request**. These are central-service-side concepts to be specified when their policy is decided.

#### 7.3.4 `update_rights_until` — CURRENT representation (Slice 6A) + update-plane enforcement (Slice 6B)

- The claim is **optional on v2** and must be a PHP/JSON integer `> 0`; it is forbidden on a legacy/unversioned document because it requires the locally trusted v2 key-ring provenance. It is covered by the ordinary canonical Ed25519 signature and persists verbatim only inside the existing `payload_json`. No dedicated column/table or outbound activation/refresh field is added.
- Semantics: an **epoch-second publication-rights boundary for ordinary releases**, enforced **only in the update plane**. The claim does not change `LicenseStatus`, reason, `needs_renewal`, entitlements, `SignedLicenseGate`, domain binding, `activation_id`, ordinary-expiration behavior, or any clinical/data/backup/restore/export/recovery/security runtime path. Ordinary expiration still does not disable the legally obtained installed CPMS version, and Version Rights are **not** a clinical-license lock.
- **CURRENT enforcement (Phase 16 Slice 6B).** `UpdateService::checkForUpdates()` reads the claim read-only from the already-verified stored document (`LicenseService::updateRightsUntil()`) and `UpdateRightsBoundary` decides, after signature → structure → channel → applicability and after the base `updates` entitlement (which short-circuits before any network): an ordinary release is offered only when the signed manifest's integer `signed_at` is `<=` the boundary, otherwise `update_rights_expired`; `release_kind=security` **in the signed manifest** remains eligible after the boundary. Absent/invalid claim ⇒ unchanged behavior. No local wall clock participates; a bounded document with a missing or non-integer `signed_at` fails closed as `invalid_manifest`. The decision cache is keyed by the boundary fingerprint, so renewal (a later signed boundary in the same stored payload) is effective without migration or reconciliation.
- `release_kind` (closed enum `normal` | `security`) is delivered and signed inside the release payload; **absent = `normal`** for existing signed manifests; a present unknown/malformed/non-string value invalidates the manifest; changing it after signing breaks the signature. `security` never bypasses signature, structure, channel, applicability or the base `updates` entitlement, and never grants `updates` by itself.
- Still **FUTURE/OPEN**: any central-side version-rights issuance policy, plan/seats values, or billing implication of the boundary.

**Offline activation (CURRENT, unchanged):** `LicenseService::activateWithDocument()` ingests a signed document through the *same* `verifyAndStore()` seam with no network and no `license_key`. Offline and online activation therefore share identical authenticity, install-binding, domain-binding and expiry behavior, plus the v2 claim-validation contract above.

#### 7.4 REFRESH contract

##### 7.4.1 CURRENT — exactly what executing CPMS sends

`LicenseService::refresh()` sends **only**:

| Field | Source |
|---|---|
| `install_id` | stored `cpms_license_state.install_id`, falling back to `LicenseService::installId()` |
| `license_id` | stored `cpms_license_state.license_id` |
| `environment` | `WP_ENVIRONMENT_TYPE` or `'production'` |
| `version` | `CPMS_VERSION` or `'dev'` |
| `domain` | canonical `home_url()` host |

**Precision note (must not be "documented" away):** refresh does **not** send `license_key`, `wp_version`, or `php_version`, and does **not** send `activation_id`. CPMS never echoes the activation handle back to the central service — the request allowlist is unchanged by Phase 16 Slice 4. The endpoint is `POST {server_url}/refresh`, with the same transport, allowlist, envelope and status classification as activation. Refresh requires an existing stored state row (otherwise `CLINIC_LICENSE_NOT_ACTIVATED`), and is a no-op when no gateway is configured (manual/offline activation path).

##### 7.4.2 Central semantics to be implemented by the future central service

- **Matching active activation** ⇒ issue a freshly signed license document for the same `install_id` / `domain` / `activation_id`, with a new `issued_at`/`expires_at`. CPMS verifies and stores it through the same seam.
- **Superseded or conflicting activation** (e.g. the record was superseded by a rebind, or another production activation took the single slot) ⇒ the central service **may** return a bounded commercial response **or** signed state carrying `revoked`/`suspended`/`reason`. **Which of those, and with what clinical consequence, is a later revocation/suspension policy decision and is OPEN** (§7.9). CPMS already distinguishes a *verified signed* `SUSPENDED`/`REVOKED` from a transport failure (§7.6) and already treats both as fail-closed blocking causes; no additional client restriction is specified here.
- **Temporary network / TLS / DNS / server failure is a TRANSPORT FAILURE, NOT a piracy verdict.** It is recorded as `CLINIC_LICENSE_UNREACHABLE` (retryable) and surfaced through the existing unreachable/stale-cache policy. Network unavailability is never evidence of an invalid license, never proof of piracy, and never grounds for destroying or withholding anything.
- **No PHI is transmitted** on refresh or activation: the allowlist is metadata-only and `VendorPlanePrivacyTest` asserts a medical sentinel never appears in URL, headers, or body.
- **No clinical request waits on refresh.** Refresh runs only from the recurring `license.refresh` job (`LicenseRefreshHandler` → `refreshDue()` → `refresh()`), with exponential backoff (`1h × 2^min(fails,5)` + jitter, capped ~32h). The gate reads local signed state only.

**Not decided here:** the numeric production refresh interval and the numeric offline grace duration. Current *code* defaults exist (`LicensePolicy`: `renewIntervalHours = 24`, `unreachableGraceDays = 3`, `expiryGraceDays = 7`, `throttleIntervalHours = 6`) and remain internal client-side policy, **not** a central-service commitment; the authoritative production values are **OPEN** (§7.9).

#### 7.5 REBIND / MIGRATION (conceptual central operation)

Legitimate migration must remain possible without punishing the customer. The central operation is conceptual only — **no endpoint, CLI, UI, admin action, or support workflow is built in this slice, and no rebind-count/frequency limit is defined.**

| Scenario | What changes | Central operation |
|---|---|---|
| **Same-domain server migration** with preserved database and preserved `install_id` | nothing observable | No rebind needed. The existing activation record remains active; the restored database carries the same `install_id`, so refreshed/issued documents keep matching. |
| **New-install migration** with a new `install_id` | `install_id` changes | Central service **supersedes** the old production activation and issues a newly signed document bound to the **new** `install_id`. The old installation is not required to be deleted. |
| **Production domain change** | signed `domain` claim changes | Central service **rebinds** the activation to the new canonical domain and issues a newly signed document whose `domain` claim matches. Until then the local gate holds `RESTRICTED` / `binding_mismatch` (reversible, non-destructive). |

**Minimum rule for all three:** the central service supersedes/rebinds the activation record and issues a **newly signed document that matches the new legitimate installation/domain state**. CPMS's side of every scenario is unchanged: verify the new document locally and store it. No client-side "migration mode", no local unbinding, no local deletion of prior state, and no bypass of the domain binding.

#### 7.6 OFFLINE / FAILURE semantics — four things that must never be conflated

| # | Condition | Authority | CURRENT delivered behavior |
|---|---|---|---|
| **A** | **Local signed state still valid** | local, from the last verified signed document + server-side wall clock | `ACTIVE` / `EXPIRING` / `GRACE`; ordinary clinical new business allowed |
| **B** | **Ordinary commercial expiration** (verified signed document simply past its expiry grace) | local, derived cause `expired` | Still *represented* as `RESTRICTED` / `expired` / `needs_renewal`, and the gate still reports read-only — but per Phase 16 Slice 1 the protected **new-business** operations are **not** blocked by the annual term ending alone. Every other `RESTRICTED` cause still blocks, fail-closed. |
| **C** | **Transport / unreachable** (network, TLS, DNS, timeout, 5xx, 429) | *no authority* — absence of an answer | `CLINIC_LICENSE_UNREACHABLE` (retryable) + bounded unreachable/stale-cache policy, then explicit `UNREACHABLE`. **This is not a verdict.** It never proves piracy and never deletes, hides, or ransoms anything. |
| **D** | **Explicit central suspension / revocation / conflict** | central, delivered as a **verified signed** document carrying `revoked`/`suspended`/`reason` | `SUSPENDED` / `REVOKED` — blocking causes, never produced by a network failure, and **not** relaxed by the ordinary-expiration exception. |

Recorded rules that already hold: network unreachable ≠ invalid; a signature/authenticity failure is `INVALID`, distinct from both an outage and a signed verdict; offline activation is validated exactly like online activation; and in **all** of A–D, reads/history/export, backup/restore, export/recovery, security functions, and completion of in-progress clinical workflow remain open — nothing is deleted.

**OPEN (§7.9):** the central *policy* for when a superseded/conflicting activation becomes `SUSPENDED`/`REVOKED`, and any clinical restriction beyond the existing gate posture for those states.

#### 7.7 SECURITY / PRIVACY invariants

- **Ed25519 signed state.** Verification is local and Ed25519-only (`sodium_crypto_sign_verify_detached`); missing sodium ⇒ fail-closed (`false`), never an open gate.
- **Private keys stay outside CPMS**, outside the repository, outside the plugin package, outside the database, and outside the license payload. CPMS ships **public verification material only**; the repository's production value is a deliberate placeholder and the v2 trusted ring is intentionally empty — real release keys remain a commercialization/release blocker.
- **Public-key rotation is delivered** through v2 `key_id` + the CPMS-configured trusted ring (`LicenseKeys::TRUSTED_PUBLIC_KEYS_B64` / `cpms_license_public_keys`, bounded to 32 keys). Old and new keys may be trusted concurrently during rotation. **No remote key fetching, no TOFU**: the ring comes only from CPMS configuration, never from a request parameter or from a license document, and there is no fallback from v2 verification to the legacy key.
- **No PHI / no clinical payload on the control plane.** The vendor-bound allowlist is metadata-only (ADR-0028 §2) and is asserted by test.
- **`install_id`, `domain`, `activation_id` are commercial/technical metadata, not authorization authority.** None of them is tenancy, scope, or capability authority; `activation_id` is opaque, non-secret, non-PHI, stored in plain local state, and is **not** a bearer credential.
- **No always-online clinical dependency.** Ordinary page loads never touch the network; the gate reads local signed state only.
- **Honest tamper boundary:** an attacker with full PHP/DB/filesystem control on a self-hosted installation can patch local enforcement. This architecture therefore makes **no "uncrackable PHP" claim**. Data/history/backup/restore/export/recovery/security are never held hostage by licensing, and destructive DRM is explicitly out of scope.

#### 7.8 ANTI-SHARING claim — precise

**What the architecture CAN enforce:**
- the central service can **refuse** to issue, and can **supersede**, additional production activations, so a standard License has at most one ACTIVE production activation;
- the signed **installation** (`install_id`), **domain** (`domain`) and **activation** (`activation_id`) claims make a casual copy to another domain or another installation **invalid** — it fails local verification (`CLINIC_LICENSE_INVALID` / `binding_mismatch`) until a **newly issued** document is obtained from the central service;
- official **update / support / service value** can be made to depend on legitimate licensing (ADR-0029 gates update offers on verified entitlement + signed manifest), without ever disabling the already-installed version.

**What it CANNOT guarantee:**
- a determined attacker with full PHP/DB/filesystem control can patch client-side enforcement — there is no tamper-proof self-hosted client;
- **same-domain full clones are not globally distinguishable by the CPMS client alone** (identical `install_id` and identical canonical `domain` verify identically); only central-side evidence (activation-record history, issuance patterns, rate/audit signals) can speak to that, and that analysis is out of scope here;
- nothing in this contract is a piracy *verdict*: absence of connectivity, a transport error, or a locally expired document is not proof of infringement.

#### 7.9 FUTURE / OPEN decisions and approved deferred requirements (not implemented in this slice)

1. **production refresh interval** (numeric);
2. **offline grace duration** (numeric);
3. **staging / development / DR activation policy** — including whether non-production environments consume the single production activation;
4. **Enterprise multi-activation policy**;
5. **rebind limits** (count / frequency / cooldown);
6. **suspension / revocation clinical semantics** — central issuance policy plus any restriction beyond the existing gate posture;
7. **Delivered in Phase 16 Slice 6B (no longer future):** the ordinary-release publication time is compared to the signed `update_rights_until` boundary (signed integers only), and releases explicitly classified `release_kind=security` in the signed manifest remain eligible beyond it. Central-side *issuance policy* for the boundary remains open;
8. **Organization wire identifier** — the claim/field by which the central service names the owning Organization to CPMS;
9. **Plan / capacity values** (`plan`, seats, broader `version_rights` policy);
10. **billing / payment implementation** — entirely outside CPMS.

#### 7.10 CONSISTENCY — cross-checked against executing code

Verified against the working tree, not against memory:

| Claim in this section | Executing evidence |
|---|---|
| activation request fields | `LicenseService::activateWithKey()` + `HttpVendorGateway::allowlisted()` |
| refresh request fields (`install_id, license_id, environment, version, domain` only) | `LicenseService::refresh()` |
| no `activation_id` echoed to the vendor | `LicenseService::refresh()` / `activateWithKey()` + unchanged `HttpVendorGateway::allowlisted()` |
| response envelope `{payload, signature_b64}` + status classification | `HttpVendorGateway::call()` |
| mandatory payload fields (`product`, `install_id`, `license_id`, `expires_at`, `issued_at`) | `LicenseService::verifyAndStore()` |
| signed schema v2 / `key_id` ring / legacy separation | `LicenseSignature::verify_license_document()` + `LicenseKeys` |
| signed domain binding + `binding_mismatch` | `LicenseService::applyDomainBinding()` + `LicenseDomain` |
| optional `activation_id` grammar | `LicenseActivationId::is_valid()` + `LicenseSignature::verify_versioned_document()` |
| optional v2 `update_rights_until` (integer `> 0`, legacy forbidden, payload_json only) | `LicenseSignature::verify_license_document()` + `LicenseUpdateRightsClaimTest` |
| update-plane version-rights enforcement + signed `release_kind` closed enum (absent = normal) | `ReleaseManifest::releaseKind()/signedAt()`, `UpdateRightsBoundary`, `UpdateService::evaluateManifest()/checkForUpdates()`, `LicenseService::updateRightsUntil()` + `UpdateRightsEnforcementTest` |
| no local clock / no new storage / no request metadata / no clinical coupling for Version Rights | `UpdateRightsBoundary` (integer comparison only) + `LicenseService::updateRightsUntil()` (`payload_json` only) + unchanged `SignedLicenseGate` |
| ordinary expiration does not freeze new business | `SignedLicenseGate::assert()` (exact reason `expired` only) |
| unreachable ≠ invalid; signed revoked/suspended ≠ outage | `LicenseStateMachine::compute()` |
| refresh only from the job, never from a request path | `LicenseRefreshHandler` + `LicenseService::refreshDue()` |
| no PHI on the control plane | `HttpVendorGateway::allowlisted()` + `VendorPlanePrivacyTest` |
| no migration, no dedicated update-rights table/column; claim uses existing `payload_json` | `LicenseRepository::saveVerified()` + `LicenseUpdateRightsClaimTest` (latest migration remains `2026_09_26_0023_handwriting_prescription_paper.php`) |

**No field, endpoint, status, or behavior above is claimed as implemented unless it appears in the executing code listed here.** Everything else in §7 is either already-delivered behavior or explicitly marked FUTURE/OPEN.

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
