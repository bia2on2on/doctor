# Organization Manager bootstrap prerequisite — proposal for Owner approval

**Status:** Proposed; not approved; implementation remains fail-closed.

## Why WordPress-local signals are insufficient

The current installation can identify a WP user and an Organization row, but it cannot establish that the user is the beneficial owner or an authorized delegate of that Organization. `manage_options`, first-practice self-attestation, existing Clinic Manager membership, existing Clinic users, license activation, and a submitted Organization ID are not independently verified ownership evidence. No automatic promotion is permitted.

## Proposed trust ceremony

Add an out-of-band, vendor-verified Organization ownership claim. A CPMS trusted identity/operations authority (not the WP installation) verifies the customer and issues a one-time, short-lived, signed bootstrap assertion containing a stable Organization identifier, intended WP account identity, issuance/expiry, nonce, and key identifier. The signed assertion is delivered to the verified customer through the verified channel and entered by an already authenticated WP user. The plugin verifies signature against pinned public keys, expiry, nonce uniqueness, exact Organization binding, and intended account binding, then atomically consumes the nonce, creates the first active Organization Manager membership, records the signing key/assertion digest and actor in tamper-evident audit, and commits. Any check or audit failure rolls back.

The assertion must contain no patient/clinical data or reusable secret. The raw assertion is not written to logs/audit; only a non-reversible digest, issuer/key id, claim id, timestamps, Organization id, actor id, and outcome are recorded. Replays, wrong-Organization claims, expired claims, account mismatch, suspended Organization, bad signature, duplicate claim, and chain-write failure fail closed. Key rotation and revocation are handled by the trusted issuer; local verification accepts only currently trusted keys.

## Required Owner decisions before implementation

1. **Trust owner:** Which legal/product entity verifies customer ownership and issues the assertion? Is that authority operationally available and accountable?
2. **Identity proof:** What customer verification evidence and assurance level are required? How is the receiving WP identity bound (verified email, strong-auth user ID, or another approved binding)?
3. **Organization identity:** Which immutable Organization identifier is authoritative across a migrated legacy install, replacement domain, and database restore? Existing numeric ids/slugs are not globally trusted identifiers.
4. **Signing/key operations:** Who controls signing keys, rotation, emergency revocation, audit retention, and issuance monitoring?
5. **Recovery:** What support process handles lost owner accounts, organization transfer, ownership dispute, and no remaining active manager? Do not permit a WordPress Administrator override as recovery.
6. **Bootstrap timing:** Is bootstrap allowed only for newly verified customers, or may legacy customers receive a new assertion after manual verification?

## Implementation gates

Until these decisions and the trusted issuer exist, `bootstrap_owner()` remains closed and the admin UI must state that Organization setup is pending verification. No signed-token parser or local self-attestation is a substitute for an approved issuer. After approval, add threat-model review, key-management runbook, one-time-claim schema/indexes, server/domain verification service, audit rollback, replay/concurrency tests, and end-to-end verified bootstrap acceptance before enabling the user-facing ceremony.
