# CPMS Classic — Disposable Demo Installer (companion plugin)

Browser-only companion plugin that creates, inspects and securely removes **one**
synthetic CPMS Classic demo on a provably disposable WordPress site. The Owner
needs no SSH, WP-CLI, terminal or database access.

- **Installable ZIP:** built by `bash bin/build-demo-installer.sh` →
  `dist/cpms-disposable-demo-1.0.0.zip` (single top-level directory
  `cpms-disposable-demo/`, valid `Plugin Name` header,
  `Requires Plugins: clinic-practice-management`).
- **Production is untouched:** nothing under `clinic-practice-management/**` is
  modified by this branch; the companion is a separate plugin directory.

## Surface

One page: **Tools → دموی یک‌بارمصرف CPMS**. No REST route, no `admin-ajax`, no
frontend code, no shortcode, no cron, no CLI entry point.

Every mutating action requires, in order: POST → authenticated Administrator
(`manage_options` **and** `install_plugins`) → action-specific nonce → the exact
confirmation phrase typed by hand → the site-identity fingerprint rendered on the
form. Responses that ever include a credential or OTP code send
`Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`.

## Preflight (fail-closed, re-run immediately before each mutation)

Single site (no multisite) · HTTPS or loopback · PHP ≥ 8.1 · WP ≥ 6.9 ·
no persistent object cache · **exactly one** WordPress account (the acting
Administrator) · only default WordPress starter content · no other active plugin ·
CPMS active with schema `2026_10_18_0029` · `CPMS_DEV_OTP_ECHO` undefined ·
CPMS bootstrap Organization/Clinic/Location untouched · every clinical, booking,
patient, finance, SMS, OTP, rate-limit and idempotency table empty · no unknown
`cpms_*` table holding data · no existing demo manifest.

A checked box is never accepted as proof; all of the above is read from live state.

## Synthetic fixture (via production services only)

One Organization + one Clinic (slug carries a random 16-hex fixture id), two
locations, one participating Doctor with weekly templates and enough future open
slots, a published booking page with the real shortcode
`[cpms_public_booking clinic_id="N"]`, six personas
(Organization Manager, Clinic Manager, Doctor, Receptionist, Accountant, Patient)
with **independent CSPRNG passwords**, plus a synthetic patient, staff
appointment, reception walk-in + call + start, clinical notes, prescription,
follow-up, invoice and an offline `other` payment, and an audit entry.

SMS: the fixture clinic uses the existing `log` provider — no real SMS, no real
mobile numbers, no gateway, no external notification. Weekly templates are one
minute long (shorter than the 20-minute appointment) so the production
`slots.generate` job cannot manufacture slots on the Owner's host; the open slots
the demo uses are explicit `generated_from = 'manual'` rows.

## Credentials

192-bit CSPRNG password per persona, shown **once** in the no-store response of
the request that created them. Never written to options, transients, logs, files,
audit rows, the manifest, or a PR/CI comment. Rotation re-rolls all six, calls
`wp_set_password()` and destroys existing session tokens. No Administrator
persona is ever created.

## Demo OTP without SMS

The Owner clicks «ساخت کد OTP نمایشی» after the synthetic patient has requested a
code through the **real** UI. The plugin rebinds the `code_hash` of exactly one
live, unconsumed, zero-attempt challenge of the fixture clinic + synthetic mobile
(the same fixture method as the repository's audited `tests/disposable-site`
tool) and shows the code once. It does not create tokens, extend expiry, reset
attempts, clear locks, lift cooldowns, mint sessions, or touch any other
clinic/mobile. The patient still submits the code to the normal verify route,
which enforces TTL, attempt limit, lockout, one-time consumption and the
patient-capability gate. `CPMS_DEV_OTP_ECHO` is never defined.

## Teardown & Conservative Record Lifecycle

Ownership is proven from **live rows**, never from the manifest alone: clinic
slug/organization binding, every persona login prefix + fixture id, no persona may
hold `administrator`/`manage_options`, booking page slug + shortcode, and every
`cpms_*` table's rows must be attributable via explicit immutable fixture entity
identifiers (`clinic_id`, `organization_id`, `patient_id`, `wp_user_id`,
`clinician_id`, `appointment_id`, `slot_id`, `follow_up_id`, `message_id`,
`token_id`, synthetic `0900` patient mobile, or 32-char SHA-256 persona
`username_hash`).

- **`cpms_operational_logs`:** Matched strictly by explicit entity keys in
  `context_json` (`$.clinic_id`, `$.patient_id`, `$.appointment_id`/`$.old`/`$.new`,
  `$.slot_id`, `$.hold_id`, `$.schedule_id`, `$.exception_id`, `$.clinician_id`,
  `$.follow_up_id`, `$.message_id`, `$.token_id`, `$.wp_user_id`/`$.actor`/`$.by`,
  `SMS_LOG_SENT` `$.to` for the synthetic `0900` mobile, and
  `LOGIN_FAILED`/`LOGIN_THROTTLED` `$.username_hash` for the 6 synthetic persona
  logins). Shared PHP-process `request_id` (`cpms_request_id()`) correlation is
  **never** used.
- **`cpms_audit_logs`:** Clinic-scoped rows (`clinic_id = N`) are matched only when
  `resource_type <> 'otp'` or `after_json.mobile` matches the masked synthetic
  `0900` patient mobile (`***XXXX`). Unscoped public OTP audit rows
  (`clinic_id IS NULL AND resource_type = 'otp'`) are matched only when an unscoped
  `cpms_otp_tokens` row exists for the synthetic `0900` patient mobile and zero
  foreign mobiles in `cpms_otp_tokens`, `cpms_patients`, or `cpms_sms_messages`
  share the same 4-digit suffix. Any foreign or suffix-colliding OTP audit row
  blocks teardown.
- **`cpms_rate_limits`:** Only entity-scoped keys exclusively owned by the fixture
  (`otp-day:<0900-mobile>`, `otp-hour:<0900-mobile>`, `login-user:<32-hex-persona>`,
  and per-fixture-user keys such as `booking-<uid>`) are deleted during teardown.
  Globally shared per-IP counters (`otp-ip:<ip>`, `otp-verify-ip:<ip>`,
  `login-ip:<ip>`) cannot be proven to belong exclusively to the demo from an IP
  match alone and are **preserved** across teardown (left for production CPMS's
  `cleanup.rate_limits` recurring job) and permitted on subsequent reprovisioning
  after a verified teardown tombstone (`cpdd_had_clean_teardown`). Any foreign
  entity rate-limit row blocks both preflight and teardown.

Tables whose ownership cannot be proven **block** the whole teardown (nothing is
deleted). Deletion is scoped to those proven predicates, then re-verified; the
Owner's Administrator account, the default bootstrap rows and unrelated
users/posts/options/files are out of scope. If selective cleanup cannot be proven
safe, the documented fallback is to destroy the whole disposable site/database
container.

## Verification & test suites

- **Unit & security negative test suite:** `php tools/disposable-demo-installer/tests/run-tests.php`
  (197 assertions covering preflight refusals, capability/nonce/confirmation/site-fingerprint
  enforcement, manifest HMAC tamper & site-transplant detection, CSPRNG credential generation
  & rotation with complete username rendering, OTP scope/attempt/expiry/replay/provider guardrails,
  concurrency lock contention, partial provisioning and interrupted teardown fault-injection recovery,
  safe uninstall preservation when a fixture is active, shared `request_id` isolation, shared-IP
  rate-limit preservation, unambiguous unscoped OTP audit attribution, repeated provisioning and
  second teardown, and durable ownership-proofed teardown across all 43 `cpms_*` tables).
- **Real-WordPress integration acceptance:** `tools/disposable-demo-installer/tests/integration-wp.php`
  (executed in `.github/workflows/disposable-demo-installer.yml` on a clean WordPress 7.1.3 +
  MySQL 8 + production CPMS 1.0.0 ZIP + companion `cpms-disposable-demo-1.0.0.zip`, including
  cross-connection MySQL `GET_LOCK` concurrency contention, mid-teardown fault injection, and
  partial provisioning recovery).
- **Real-Chromium browser acceptance:** `tools/disposable-demo-installer/tests/browser-acceptance.py`
  (executed in `.github/workflows/disposable-demo-installer.yml` with Playwright Chromium against
  Apache + WordPress 7.1.3 + MySQL 8 covering `wp-admin` ZIP upload & activation, preflight UI,
  provisioning, one-time credentials, password rotation, 6 independent persona contexts, role
  portals, authorization denials, public booking, UI OTP issuance & verification, clinical and
  finance journeys, 320px/390px RTL responsiveness, diagnostics, teardown, DB verification, and
  safe plugin removal).

