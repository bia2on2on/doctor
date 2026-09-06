# ADR-0029 — Secure Update Delivery & Authorization (Self-Hosted)

وضعیت: **Accepted (F10)** | تاریخ: 2026-09-06 | تأیید کارفرما: F10 spec §19
مراجع: F10 spec §19/§41؛ ADR-0023 (licensing)؛ ADR-0028 (data/control plane)؛ docs/api/error-codes.md

## Context

Self-hosted CPMS needs a way to ship fixes while keeping the customer in control. Requirements (spec §19): updates are authorized by the vendor's license/entitlement, artifacts are signed and integrity-verified, and the plugin must never `eval`/execute remote PHP or blindly apply remote content.

## Decision

### 1. Release metadata is signed; the installer is local
- A **release manifest** (JSON) declares: `product`, `version`, `channel` (stable|beta), `package_url` (HTTPS), `package_sha256`, `min_wp_version`, `min_php_version`, `min_cpms_version`, `release_notes`, `signed_at`, `entitlement` (feature key required, default `updates`).
- Manifest is **Ed25519-signed** by the vendor **release-signing key**, whose *public* key ships in the plugin (`ReleaseKeys`; placeholder until official release — fail-closed). This is a **separate key** from the license/entitlement signing key (ADR-0023) so a compromise of one doesn't forge the other (spec §41).
- Canonicalization identical to ADR-0023 (k-sort + JSON_UNESCAPED_UNICODE) so both sides sign/verify the same bytes.

### 2. Delivery (V1)
- Official distribution channel: vendor server exposes the manifest over HTTPS (out-of-repo; contract here + mock in tests). The WordPress update screen sees CPMS as an update *only after*: license is not restricting updates (`entitlements.updates` true) AND manifest verifies AND `package_sha256` matches the artifact.
- `pre_set_site_transient_update_plugins` injection is limited to CPMS's own slug, cached (transient, TTL `update.check_interval_hours`, default 24), with bounded HTTP timeout and **no per-request network** on clinic pages.
- Artifact integrity: package sha256 checked **before** WP's installer touches it; unsigned/unverifiable manifests are never offered.

### 3. No remote code, no backdoor
- No `eval`, no remote PHP execution, no executable downloads auto-run. The installer is WordPress's standard, user-initiated update (or CLI with explicit command) — we only authorize + integrity-verify.
- No call-home on ordinary page loads; update check runs from cron/job/Admin button only.

### 4. Entitlement & downgrade
- `update.channel` setting chooses stable (default) vs beta; admin capability `cpms_config` gates changes.
- Refusal to update never disables the clinic; an expired license disables *auto-offer* of new updates but existing installs keep working (historical/current-workflow rights preserved, ADR-0023 §5).

## Consequences
+ Fix delivery without weakening the customer boundary; authenticity verifiable by anyone with the shipped public key.
+ Compromise of the release key doesn't grant license entitlements (key separation).
− Full vendor distribution server + signed release pipeline are out-of-repo (Runbook + mock contract here; end-to-end distribution = BLOCKED_BY_ENVIRONMENT until vendor infra exists).
− Auto-update UI polish (background download) is later (V1.1); V1 offers updates in standard WP update screen after verification.

## Alternatives
- Bundled self-update script that fetches remote PHP: rejected (spec §19 — no remote code).
- Unsigned updates over HTTPS-only: rejected (integrity/authenticity requirement).
