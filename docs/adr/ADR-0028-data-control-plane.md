# ADR-0028 — Data Plane / Control Plane Separation (Self-Hosted Commercial Boundary)

وضعیت: **Accepted (F10)** | تاریخ: 2026-09-06 | تأیید کارفرما: F10 spec §6/§7/§8/§41
مراجع: F10 spec §6–§8؛ ADR-0023 (licensing protocol)؛ ADR-0025 (SMS provider-agnostic)؛ docs/security/threat-model.md

## Context

CPMS is a commercial self-hosted WordPress product: clinic data lives on the customer's infrastructure, while commercial functions (licensing, entitlements, secure updates) need vendor infrastructure. F10 introduces the first vendor-network components (license refresh, update metadata). Without a hard architectural boundary, incidental "diagnostics"/"activation" payloads could leak medical data to the vendor, or vendor code could grow into a hidden clinical datastore.

## Decision

### 1. Two planes, one rule
- **Data Plane** = the customer WordPress installation. Owns and retains: patient data, medical/clinical records, appointments, visits, prescriptions, handwriting/strokes, attachments, patient financial data, operational clinic data, audit. F10 never relocates any of it.
- **Control Plane** = vendor commercial infrastructure. Responsible only for: customer commercial account, subscriptions, licensing, installation identity, entitlements, activation limits, release metadata, secure update authorization/distribution, billing metadata, non-medical commercial services explicitly approved later.
- **The control plane is never a patient/medical database.** No F10 code path may send to vendor infrastructure: patient name/mobile/national ID/MRN/DOB/diagnosis/prescriptions/clinical notes/private notes/handwriting/OCR content/medical attachments/appointment reason/patient financial content/medical exports (spec §7 list).

### 2. Allowed vendor-bound metadata (exhaustive allowlist)
High-entropy license identifier, installation identifier, normalized domain (only where policy requires), environment type (production/staging), plugin version, required WP/PHP compatibility metadata, entitlement/subscription state, activation count, update channel/eligibility, last-validation metadata, non-medical error/status codes. Everything else is **denied by default**; a test asserts payload construction contains none of the forbidden keys/values.

### 3. Enforcement points
- `LicenseClient` (F10) is the **single** object that may talk to the vendor license/update endpoints. Business services never construct vendor HTTP calls.
- Update metadata client similarly isolated; the SMS generic provider (ADR-0025, customer-configured, SSRF-guarded) remains the only *other* outbound network path in the product.
- Outbound HTTP helpers must be SSRF-guarded and time-boxed (connection + request timeouts, bounded retries) — no external call while holding booking/payment DB locks (spec §26).
- Operational logs of vendor calls include only allowed metadata (log sanitizer already masks PHI-shaped keys).

### 4. No remote access
No permanent vendor/developer remote access, no hidden support/admin access, no remote code execution, no kill switch (spec §9/§37). FUTURE remote support must be customer-enabled, time-limited, revocable, audited, least-privilege.

### 5. Vendor server security requirements (documented runbook, not shipped code)
TLS; strong auth; rate limiting; high-entropy identifiers; anti-enumeration; replay protection where relevant; signing-key protection and separation (release-signing authority distinct from the ordinary license web app, per spec §19/§41); minimal retention; audit/monitoring; infrastructure backup; **no medical-data storage**. Subscription webhooks (FUTURE) must verify signatures and be idempotent.

## Consequences
+ Audit-friendly: every vendor-bound payload is constructed in one module and covered by a privacy test.
+ Customers (and regulators) get a single documented list of exactly what metadata leaves the site and what never does.
+ Future vendor-hosted medical processing (SaaS, OCR proxy, SMS proxy) is out of scope and requires a separate privacy/security/legal architecture with explicit customer consent (§7).

## Alternatives
- Letting license/update code embed ad-hoc payloads: rejected (privacy leak risk).
- Self-contained air-gapped commercial model: incompatible with "secure licensed updates" product promise.
