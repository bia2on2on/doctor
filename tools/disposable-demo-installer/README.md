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

## Teardown

Ownership is proven from **live rows**, never from the manifest alone: clinic
slug/organization binding, every persona login prefix + fixture id, no persona may
hold `administrator`/`manage_options`, booking page slug + shortcode, and every
`cpms_*` table's rows must be attributable via `clinic_id` / `organization_id` /
`patient_id` / `wp_user_id` / `clinician_id`. Tables whose ownership cannot be
proven **block** the whole teardown (nothing is deleted). Deletion is scoped to
those proven predicates, then re-verified; the Owner's Administrator account, the
default bootstrap rows and unrelated users/posts/options/files are out of scope.
Demo activity created after provisioning (extra appointments, patients, invoices)
is covered because the entire fixture clinic is synthetic.

If selective cleanup cannot be proven safe, the documented fallback is to destroy
the whole disposable site/database container.

## Evidence status (this branch)

| Item | Status |
|---|---|
| PHP syntax (`php -l`, PHP 8.5.10 via php-wasm) — 13/13 files | **PASS** (executed) |
| Installable ZIP built, entries + size + SHA-256 recorded | **PASS** (executed) |
| Production subtree unchanged | **PASS** (`git diff --stat` shows no `clinic-practice-management/**`) |
| Real WordPress install / activation / six-persona login / booking / OTP / teardown | **NOT RUN** — this agent environment has no PHP-for-WordPress, MySQL, WP-CLI, Chromium or network access to wordpress.org |
| Chromium 320/390 px RTL review | **NOT RUN** — no browser in this environment |
| Automated unit/integration suite in this branch | **NOT ADDED** — see the Draft PR body for the honest gap |

Known limitations are listed in the PR body; no acceptance claim is made for
anything not executed on this commit.
