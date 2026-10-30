# The Owner incident: a failed run that destroyed its own evidence

Scope: the scoped demo on a **populated private WordPress site** where eight
`cpsd-…` synthetic accounts already existed, the manifest read `failed`, the
teardown refused `BASELINE_MISSING`, the preflight refused a second fixture, and
no action anywhere could reach the eight real Classic V1 portals.

This document separates, deliberately:

* **EVIDENCE** — what the shipped source, the local PHP suites and the CI drills
  actually show;
* **INFERENCE** — what is consistent with the reported state but could not be
  observed on the Owner's own database;
* **NOT RUN** — what has not been executed at all.

Nothing in the repair deletes a user, adopts a row without sealed evidence,
bypasses a security refusal, or requires SQL, deleting users, or disabling
another plugin.

---

## 1. The reported state

| Observed | Value |
| --- | --- |
| Fixture identity | `542ad74a804d57d0b369c23519ab507c` (32 hex = scoped mode) |
| Manifest status | `failed` |
| Teardown | refused, `BASELINE_MISSING` |
| Preflight | blocked by the existing, incomplete fixture |
| Accounts | eight `cpsd-…-542ad74a…` accounts alive |
| Admin page | "sealed evidence does not match the live records" |
| Everything else | passing (private-host consent present, core/schema healthy) |

Two facts in that table are the whole story: the **fixture exists** (accounts,
rows, sealed identity) and **its baseline does not**. Everything the package
offered needed one of the two, so the Owner had no move at all.

## 2. Why the baseline disappeared (EVIDENCE)

`Provisioner::do_provision()` builds `$manifest` locally and saves it after each
stage. `after_provision()` — `ScopedProvisioner::seal_fixture()` — then writes
**its own** payload (baseline, `host_before`, `owned` scope map,
`audit_chain_at_seal`, `status: active`), reads it back, and drops the duplicate
pending baseline (`cpsd_baseline_pending`).

Two defects combined:

1. **The failure handler saved a stale snapshot over durable evidence.**
   The `catch` block wrote the local `$manifest` — the snapshot from the *last
   successful stage write*, i.e. the pre-seal one — over the record on disk. Any
   throwable raised **after** the seal was written therefore erased `baseline`,
   `host_before`, `owned` and `audit_chain_at_seal` while the eight accounts and
   every synthetic row survived. That is exactly the reported state: the demo
   exists, but nothing about it can be proven.
2. **The pending baseline was dropped too early.** `clear_pending_baseline()`
   ran *before* `enforce_portal_clinic_policy()`, which reads two capability rows
   back through the production resolver and can throw `PORTAL_POLICY`. With (1)
   also present, that single exception sequence left **no** copy of the baseline
   anywhere.

`tests/scoped-run-tests.php` now tests both halves directly:

* section 14 calls the real `record_failure()` with a stale local snapshot and a
  durable sealed record, and asserts the baseline, `host_before`, `owned`, the
  audit-chain state and the sealed timestamp all survive, that persona ids are a
  **union** (never a smaller copy), that a second failure appends instead of
  replacing, and that a record which does not verify is left byte-identical;
* the same section asserts the source order `enforce_portal_clinic_policy()` →
  `clear_pending_baseline()`, and that the seal is read back before the pending
  copy is dropped.

**INFERENCE (explicitly not observed).** Which exception the Owner's run hit
cannot be recovered from the surviving state; the sealed record that would name
it was the thing that was overwritten. `PORTAL_POLICY` is the most likely
candidate because it is the one check that runs *after* the durable write inside
the seal, but this document does not claim it, and no code path was changed to
make that guess true.

**Distinguishing "missing metadata" from "foreign ownership".** The surviving
manifest still names the exact fixture id, the exact organization/clinic/location
ids, the exact eight WordPress user ids and the exact slugs — all HMAC-covered.
None of that pointed at a foreign record: the accounts were ours, only the
"what existed before" record was gone. Nothing in the repair treats a login name,
an e-mail domain, a slug or an id range as ownership.

## 3. What is now safe, and why

`ScopedRecovery::assess()` no longer answers one question. It answers two:

* **is this fixture provably ours?** (sealed identity shape, sealed tenant and
  Locations, all eight sealed accounts with the sealed logins and no
  administrator authority, the sealed booking page, the sealed ledger chain,
  exact-id completeness of every pre-seal row);
* **is there a baseline?** (the record the *destructive* direction needs).

A complete fixture with no baseline is now the verdict **`usable`**, which
carries `usable: true` and `teardown_blocked: 'BASELINE_MISSING'`. The additive
direction is allowed because it provably creates and deletes nothing; the
destructive direction stays refused because the baseline is what proves
"nothing that pre-existed was touched".

| State | Verdict | Additive repair | Teardown |
| --- | --- | --- | --- |
| sealed, active | `active` | not needed | allowed |
| complete, only the seal missing | `resumable` | `finish()` re-seals | allowed after |
| complete, baseline gone | `usable` (**new**) | `activate()` | **refused** `BASELINE_MISSING` |
| incomplete / unprovable | `blocked` | refused, stable code | refused |

### The one action

`ScopedProvisioner::launch()` is the single Persian action —
«آماده‌سازی و ورود به هشت درگاه» — offered on the page the Owner already had
(`wp-admin` → «دموی CPMS»). It reads the sealed evidence, chooses
`active` / `finish()` / `activate()` by itself, then rotates the eight demo
passwords once (they are never stored) and renders the eight real portal links
plus the one-time password card on the same page. No typed phrase, because
nothing is destroyed; `manage_options` + `install_plugins`, the action nonce, the
site fingerprint and the HTTPS/loopback transport gate are unchanged.

**Why one action does not open eight sessions at once.** Cookies are per browser
context; "being logged in as all eight at once" would require an impersonation
primitive, which this package deliberately does not have. One action gives the
Owner *access to all eight* — the rotated password, the exact login, and the
direct link for each persona, on one page — not eight simultaneous sessions.
From that page, §3a opens any one of them with a single click.

`activate()` is **not** a seal and never pretends to be one: it writes no
`owned` map and no `audit_chain_at_seal`, so `sealed` stays false, the teardown
stays refused, and the activation is recorded as such
(`activation.mode = baseline_unavailable`, `row_effect = none`) with one
append-only audit row `CPSD_DEMO_ACTIVATED_WITHOUT_BASELINE`.

### 3a. Eight buttons, no passwords: the persona hand-off

The Owner's second requirement was explicit: *as their own administrator, clicking
a persona must open that real portal immediately, without typing a username or a
password.* The dashboard now renders **eight** Persian buttons («ورود به درگاه …»),
one per persona, and each one opens that persona's real Classic V1 portal.

What happens, in order, on one click:

| Step | What runs | Why it cannot be abused |
| --- | --- | --- |
| 1. POST | `cpdd_action=persona_entry`, form on the demo dashboard, `target="_blank"` | Ordinary `wp-admin` POST; no new endpoint, no REST route |
| 2. Gate | `ScopedRequest::authorize()` | logged in **and** `manage_options` **and** `install_plugins`, action nonce `cpsd_persona_entry`, rendered site fingerprint, HTTPS or loopback |
| 3. Target | `ScopedPersonaSession::target()` | persona key must be one of the eight; id read from the **HMAC-sealed** `ids.user_ids[persona]`; live login must equal the sealed `cpsd-<persona>-<fixture>`; refuses `administrator`, refuses `manage_options`, refuses self, refuses id ≤ 1 |
| 4. Ticket | `issue()` | 24 random bytes, SHA-256 digest stored (never the token), TTL 300 s, bound to site + fixture + persona + user + issuing administrator, sealed with HMAC |
| 5. Answer | `wp_safe_redirect( $ticket['url'] ); exit;` | the token appears in **no** HTML, no cacheable page and no log line the package writes; it is a URL, so it can reach the web server's own access log during its ≤300 s life — which is why it is single-use, digested at rest and short-lived |
| 6. Redeem | `init` priority 2, `?cpsd_persona_entry=<token>` | format → read → **delete unconditionally** → HMAC → site → expiry → digest `hash_equals` → reload the live sealed manifest → re-run `target()`; every failure redirects to the login screen with a stable code |
| 7. Session | `Env::set_auth_cookie()` → `wp_set_auth_cookie( $id, false, is_ssl() )` (the package's only auth-cookie writer) | WordPress's own primitive: a fresh session token, so nothing is fixated and nothing is copied from the administrator |
| 8. Landing | `wp_safe_redirect( sealed portal URL )` | the redirect target comes from the sealed `links` map, never from request input |

There is **no impersonation primitive anywhere**: no `act_as` flag, no
`current_user` override, no capability copy, no client-side role simulation. After
the transition the browser holds *that persona's own* session and the production
`AuthorizationService` decides everything, exactly as for a real member.

**The authority matrix (read-only, on the same page).** Every tile shows four chips
and the page shows a table whose rows are resolved by asking the **production
engine**: `clinical` = `cpms_patient_read`, `financial` = `cpms_finance_read`,
`messaging` = `cpms_visit_read` (via `AuthorizationService::can()`), `tenant` =
`cpms_org_clinic_update` (via `OrganizationClinicProvisioningService::can()`). The
administrator's own row is asked the same four questions and is expected to be
`—` on all four; the unit suite asserts exactly that against the production
invariants, and if a site ever really granted one of them the page reports it
instead of hiding it. Being a WordPress administrator grants **nothing** here.

**Read-only preview vs. a real operation.** Each tile also carries a plain link to
the same portal under the administrator's own account, labelled as changing no
session. That preview shows what the administrator is allowed to see — which is
deliberately less than the persona sees — and is never presented as the persona's
experience.

**The way back.** Personas get a WordPress admin-bar node
«نشست نمایشی: <role>» with a child link «پایان نشست نمایشی و بازگشت». It is a
nonced GET, it admits only a session whose user id is one of the sealed personas of
the *live* fixture (state derived from sealed options, never from a cookie), it
writes an audit row, and it calls `wp_logout()`. It never authenticates anybody and
never hands administrator authority to the persona.

**The unavoidable UX limitation, stated on the page and here.** WordPress
authentication is one site-wide cookie. Two tabs of the *same* browser share the
cookie jar, so a persona session in one tab replaces the administrator session in
the others — this package does not claim tab isolation, because it has not
implemented it. The button opens a new window, the page tells the Owner to use a
private window or a second browser profile, and *that* is real isolation because
it is a different cookie jar. If the Owner instead continues in the same browser,
the only cost is re-entering the administrator password afterwards, which is
exactly what WordPress requires of anyone; no demo row and no authority depends on
it. The CI drill (`browser_scoped.py sessions`) proves the isolated mode with
separate browser contexts, and it also asserts that the honest note is on the page.

### If even the repair is not safe: park, then start fresh

When the evidence does not prove completeness (`blocked`), the page offers
**«نگه‌داشتن دموی قبلی و ساخت دموی تازه»**. It:

1. writes the fixture's **whole** sealed record — manifest, ownership ledger and
   ledger ratchet — into the sealed archive `cpsd_preserved` and verifies it read
   back;
2. only then removes the **live pointers** (`cpsd_manifest`, the ledger options,
   the pending baseline);
3. provisions a fresh demo with its own 128-bit namespace.

No user, post, patient, appointment, invoice or payment row is deleted, adopted
or modified; the parked rows stay exactly where they are, attributed to a fixture
whose evidence is preserved and listed on the page («بایگانی دموهای
کنارگذاشته‌شده»). Resolving a parked fixture is an explicit, human-reviewed act;
no code path here deletes one. `uninstall.php` treats `cpsd_preserved` as
recovery evidence: it blocks and never deletes the archive.

## 4. Residual risks (stated, not hidden)

1. **An activated-but-unsealed fixture cannot be torn down automatically.** Its
   rows remain until a human restores a baseline or resolves the parked record.
   This is the deliberate trade: no proof, no deletion.
2. **Parking leaves the old fixture's rows and accounts in place.** They are
   attributed (sealed archive), listed, and never adopted by the new fixture, but
   they do occupy the `cpsd-` namespace, so `uninstall.php` keeps blocking while
   they exist. That is fail-closed behaviour, not a bug, and it is the honest
   alternative to deleting rows nobody can prove.
3. **The pending baseline is a large serialized option.** On a very large site it
   can approach the option/memory limits; a failed write is refused loudly
   (`OPTION_WRITE_FAILED`) *before* the first synthetic row, which is the safe
   direction, but the size has not been benchmarked here (NOT RUN).
4. **The triggering exception on the Owner's site is unknown** (see §2
   INFERENCE). The repair is designed not to depend on knowing it.
5. **One browser, one session (WordPress limitation).** Persona sessions and the
   administrator session cannot coexist in one cookie jar. The recommended path
   (private window / second profile) is real isolation; the same-browser path
   costs the administrator a re-login. Not fixable without a second authentication
   engine, which the requirement forbids.
6. **A ticket URL is a bearer credential for at most 300 seconds** (§3a step 5).
   It is not rendered, it is stored only as a digest, it is deleted before any
   check can fail, and it authenticates only a sealed demo persona — never an
   administrator. A leaked access log can therefore expose a demo persona
   session that has already expired by the time anyone reads it.
7. **The persona drill runs in CI, not here.** All eight transitions, the replay,
   anonymous and cross-persona attempts, and the exit flow are asserted by
   `browser_scoped.py sessions` against real WordPress on MySQL 8 and MariaDB
   11.4.13. In this sandbox the class is proven only by the fake-environment unit
   suite (NOT RUN on real WordPress).

## 5. Evidence

### Local (this sandbox, PHP 8.2 via php-wasm)

| Suite | Result |
| --- | --- |
| `tools/disposable-demo-installer/tests/run-tests.php` | **333 PASS / 0 FAIL** (strict mode unchanged) |
| `tools/disposable-demo-installer/tests/scoped-run-tests.php` | **326 PASS / 0 FAIL** (was 174) |
| `python3 -m unittest discover -s tests/scoped-demo -p "test_*.py"` | **155 OK** |
| ↳ of which `test_php_contract` | **35 OK** (was 23) |
| `.github/workflows/test_workflow_contracts.py` | **67 OK** (was 64) |
| `python3 .github/scripts/companion-compat/evaluate.py --gate all` | **PASS**, 0 blockers, 0 gaps |
| `token_get_all(..., TOKEN_PARSE)` syntax check | clean on every edited PHP file |

Section 15 of `scoped-run-tests.php` covers the hand-off itself (26 assertions):
the eight personas, a successful redeem, replay, expiry, tampered MAC, foreign
site, malformed token, a torn fixture, administrator/self/unsafe/renamed targets,
the ticket-store boundary, the matrix, the launcher HTML and the absence of
forbidden primitives. `test_php_contract` adds seven contracts on the same class,
and `test_workflow_contracts.py` asserts the lane really stages the incident before
repairing it (`stage` → `repair` → `sessions` → `finish`), really runs both
databases, and uploads neither `persona-credentials.json` nor any ticket URL as CI
evidence.

New coverage: the incident state itself (section 13 of `scoped-run-tests.php`),
`activate()` idempotence and its refusal of unprovable evidence, `launch()` for a
`usable` fixture end to end (eight credentials, real logins, no row created or
deleted, repeated click a no-op), parking (`cpsd_preserved` sealed, idempotent,
tamper-refusing, never deleting), the dashboard rendering of the `usable` state,
and `record_failure()`/`preserve_evidence()` with the ordering rule.

### Real database + browser (CI, `scoped-demo.yml`)

* `tests/scoped-demo/owner-incident.php` `stage` reproduces the incident on the
  real site (this is a **reproduction of the defect**, explicitly not a claim
  about the Owner's trigger), and asserts the dead end: `failed`, baseline gone,
  no pending baseline, eight accounts, `BASELINE_MISSING`, `other_manifest`.
* `browser_scoped.py repair` (Chromium) clicks the one Persian action, asserts
  the success state, the eight portal links, the one-time password card, that a
  rotated password really opens the **doctor portal**, and that a second click
  changes nothing.
* `owner-incident.php` `finish` proves the repair created and deleted no counted
  row, that the teardown is still refused, that parking archives the record and
  deletes nothing, and that a fresh demo provisions and tears down cleanly while
  the parked eight accounts and its Clinic remain.
* `tests/scoped-recovery.php` gains **R11** (the incident, repaired, parked,
  fresh demo, host rows byte-identical) and **R12** (a fault injected *after* the
  seal is durable no longer destroys the baseline — the fixed handler).

**NOT RUN here:** real WordPress, MySQL 8, MariaDB 11.4.13, Chromium and WPCS.
Those run only in CI on the exact head; this document does not claim them green.

## 6. What the Owner does now (no SQL, no user deletion, no plugin disabled)

1. WordPress admin → **«دموی CPMS»** (Tools → «دموی CPMS», or under «مدیریت مطب»
   when the production menu is present).
2. If the page says **«یک کار باقی مانده: آماده‌سازی و ورود به هشت درگاه»**, press
   that button once. The page then shows **eight buttons**, «ورود به درگاه …»; the
   eight portal links and the one-time password card are on the same page.
   **You do not need the passwords any more** — they stay as a fallback.
3. Click any of the eight buttons. That persona's real portal opens in a new
   window, already signed in. Best practice, stated on the page too: open it in a
   **private window or a second browser profile**, so your own administrator
   session in this window stays open. In the persona window, the admin bar shows
   «نشست نمایشی: <role>» — click «پایان نشست نمایشی و بازگشت» to end it.
4. If the page says the interruption is **not** safe to finish, press
   **«نگه‌داشتن دموی قبلی و ساخت دموی تازه»**. The old demo is archived, nothing
   is deleted, and a fresh demo is created with its own eight portals (the eight
   buttons work there too).
5. Automatic cleanup of an unsealed fixture stays blocked (`BASELINE_MISSING`) on
   purpose. That is a statement about provability, not a fault to work around.
