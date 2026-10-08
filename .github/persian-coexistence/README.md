# Phase 18 — bounded Persian five-plugin coexistence

Measurement infrastructure only. **No compatibility claim without actual runtime
Stage A and Stage B evidence.** A successful workflow is not product acceptance;
NOT RUN is never PASS. No automatic product fixes or per-plugin-baseline union
expectation is applied.

## Frozen lane

- WordPress **7.1.3**, PHP **8.3** (CLI **and** serving mod_php), MySQL **8.4.11**, `fa_IR`.
- `loco-translate` **2.8.9**, `wp-parsidate` **6.4**, `elementor` **4.3.4**,
  `persian-elementor` **2.8.4**, `woocommerce` **11.2.0**.
- Persian WooCommerce stays in the unchanged overall 19-plugin campaign. It is
  deliberately absent here because its CPMS-free baseline is materially broken.
  No existing Persian WooCommerce tests/results or baseline workflow were changed.

## One workflow, existing infrastructure

Run **Persian five-plugin coexistence (Phase 18)** (`persian-coexistence.yml`),
which also runs on PRs to main and pushes to main. It calls the existing
`real-wp-acceptance.yml` with `persian_coexistence: true`. That opt-in narrows its
matrix to one fresh `wp_` database and selects the frozen lane. Ordinary calls
retain the old pins, two prefixes, old representative-plugin set and control.

The shared Apache setup now precedes the pre-CPMS baseline, and the shared release
builder runs after it. The new lane selects mod_php 8.3 explicitly and uses the
baseline harness's runner-owned / www-data-group-writable wp-content layout;
the standalone lane retains its previous package selection and read-only web
layout. A small out-of-tree PHP identity endpoint on port 8081 measures the actual
serving PHP even if WordPress cannot bootstrap. No `phpinfo()` or credentials are
exposed. No new service architecture or Actions-version upgrades are introduced.

### Stage A — without CPMS

`run.py stage-a` reuses `third-party-baseline/probe.py`'s exact-package retrieval,
ZIP/header/installed-version checks, SHA-256 provenance, activation, WordPress
bootstrap, CPMS-absence, HTTP/login, REST/AJAX, locale/UTF-8/RTL and browser probes.
The five packages are installed, then activated in dependency order (Elementor
before Persian Elementor); no CPMS ZIP is built or installed yet.

The adapter asserts the **exact active group**, all five header versions, full
MySQL patch version, CLI/web PHP minor and WordPress version. It reads the
**findings**, not the measurement commands' zero exit codes: a material failure,
a missing browser or any UNEXECUTED check blocks Stage B. Important same-origin
browser resource failures are material in this bounded slice; other console
messages, notices and PHP warnings remain recorded warnings. The plugin-free
locale control's zero-redirect expectation is **not** imposed on the group:
successful bounded redirects are recorded warnings, while final HTTP status,
authentication and redirect-loop failures still block. PHP fatal/parse/
uncaught/critical errors in either WordPress or Apache logs are material.

Installation or activation failure stops further probes, retaining the first
recorded cause and full command output. Stage A failure is a **pre-CPMS group or
environment finding**, never a CPMS defect. No later error replaces the first
blocking check. Incomplete setup/probes cannot become PASS.

`Asia/Tehran` belongs **only to the site fixture**. Its effective offset is
checked against PHP, not forced into the raw `gmt_offset` option. Neither the
site timezone nor its derived offset is copied into any CPMS Location. No product settings or plugin behavior are
weakened to make the group pass.

### Stage B — only after healthy Stage A

The official `bin/build-release.sh` ZIP is built once, then the boundary step
rechecks the persisted Stage A gate and snapshots logs **before** installation /
activation. A failed boundary blocks installation as well.

Reuse is literal: the existing workflow installs the ZIP, verifies tables and
schema `2026_09_26_0023`, seeds existing role/membership and public-booking fixtures,
and invokes the existing `rwp-acceptance.py`. Administrator, doctor, secretary,
manager and accountant access, authorization denials, public booking A1/A4 and
RTL are required by named evidence anchors and by every existing browser result.
The adapter additionally verifies CPMS REST health, core REST/AJAX, Persian/RTL
served documents and browser admin rendering (including computed CSS direction on front/admin), plugin version/active-set stability
and exact runtime identity after the acceptance run.

All required step outcomes must be `success`; missing/skipped checks are not
accepted. The finalizer runs even on failure. Errors after CPMS activation are
recorded as **post-CPMS observations**, not automatically attributed to CPMS.

The existing booking fixture resolves the explicit Clinic and reads its trusted
Location timezone for slot generation. Site language/timezone does not replace
that authority. **Clinic A/B isolation is NOT RUN**: this reused fixture contains
one Clinic, and independent persona browser contexts are not two-clinic proof.
The synthetic broken-migration injection and unrelated standalone theme-switching
proof remain in their existing lanes and are NOT RUN in this bounded group.
A timezone override/isolation matrix is likewise not claimed.

## Evidence and security boundaries

Artifact: `persian-coexistence-wp_` (14 days).

| Path inside artifact | Meaning |
| --- | --- |
| `coexistence/summary.json`, `summary.md` | Separate Stage A/B verdicts, pins, checkout/head/base identity, explicit NOT RUN scope |
| `coexistence/A/evidence.json`, `result.json` | Combined third-party baseline, ordered checks, first blocking check and warnings |
| `coexistence/A/provenance.json`, `commands.jsonl`, `browser-events.jsonl` | Exact package provenance, untruncated CLI output, browser errors/stacks and navigation/redirect events |
| `coexistence/A/debug.log`, `apache.log` | Raw logs before any CPMS installation |
| `coexistence/B/*-before-activation.log` | Durable pre-installation/activation log boundary |
| `coexistence/B/debug.log`, `apache.log` | **Only appended bytes** after that boundary; prefix mismatch/rotation fails closed and preserves observed bytes |
| `coexistence/B/evidence.json`, `result.json`, `browser-events.jsonl` | Post-activation checks, warnings and fresh browser observations |
| Root `plugin-install.log`, `schema-migrations.txt`, `actual_count.txt`, `results.json`, `acceptance.log`, `screenshots/`, `logs/` | Unaltered reused Stage B installation/schema/role/booking/browser evidence (root PHP log copies are cumulative, not the phase-delta authority) |

ZIPs are excluded from artifact upload, including partial failed downloads.
There is no wp-config, cookie jar, request body or Authorization capture. Browser
navigation records retain only URL/status/Location; screenshots and error streams
contain this isolated synthetic fixture, not real patient data. Raw CLI logging
never records argv. New raw logging and no-retry flags are opt-in; the existing
baseline campaign's defaults remain unchanged.

The legacy `--expect-mysql` implementation compares major.minor, not the full
patch. This slice does not reinterpret past results or change that campaign: it
omits that optional legacy assertion and independently requires the complete
`SELECT VERSION()` value to equal `8.4.11`.

## Security/authentication group (second pinned group, same harness)

Same frozen lane: WordPress **7.1.3**, PHP **8.3** (CLI and serving mod_php),
MySQL **8.4.11**, `fa_IR`. The approved pins are `wordfence` **9.0.2** and
`really-simple-ssl` **9.8.3**. Wordfence is the subject (installed last);
Really Simple SSL is installed and activated first as its dependency pin.

**Selection (smallest mechanism).** `persian-coexistence.yml` has a second job,
`coexistence-security-authentication`, that calls the same reusable
`real-wp-acceptance.yml` with `coexistence_group: security-authentication`. That
input becomes `COEX_GROUP`. `run.py` `GROUPS` maps it to its pins, subject,
evidence root and artifact. Unset or `persian-five` selects the original group,
whose job and inputs are unchanged. An unknown value fails closed at `init`. The
concurrency key includes the group, so the two jobs never cancel each other.
The workflow name is kept unchanged so the accepted five-plugin check identity
does not change; the security run is the second job in that same workflow run.

**Independent evidence.** Root `coexistence-security-authentication/`
(`A/`, `B/`, `summary.json`), artifact `persian-coexistence-security-authentication-wp_`.
Stage A and Stage B gates are the same ones described above, including refusal of
Stage B on any material Stage A failure. Verdicts for the two groups are separate.

**Scope limits.**
- The reused acceptance denials for `wc-admin` / `woocommerce` screens
  (`*-denied-woo-*`) are measured without WooCommerce installed. They prove that
  the screens are not granted, not that a WooCommerce lock-down exists.
- Wordfence firewall/2FA settings and Really Simple SSL HTTPS enforcement are not
  configured or exercised; default settings only. Nothing is weakened to pass.
- Clinic A/B isolation, the synthetic broken migration, timezone matrix, and
  standalone theme switching remain **NOT RUN** (see `NOT_RUN` in `summary.json`).
- No product-code change. No automatic fix. Workflow success is not product PASS.

## Combined ten-plugin group (third pinned group, same harness)

One **combined** scenario: all ten approved plugins live in the **same** clean
isolated WordPress at the same time. Same frozen lane as the other two groups —
WordPress **7.1.3**, PHP **8.3** (CLI and serving mod_php), MySQL **8.4.11**,
`fa_IR`, `Asia/Tehran` site fixture only:

| # | Slug | Pin |
| --- | --- | --- |
| 1 | `wordpress-seo` | 28.6 |
| 2 | `seo-by-rank-math` | 1.0.280 |
| 3 | `litespeed-cache` | 7.9.1 |
| 4 | `autoptimize` | 3.1.16 |
| 5 | `user-role-editor` | 4.66.2 |
| 6 | `advanced-custom-fields` | 6.8.10 |
| 7 | `wp-crontrol` | 1.21.2 |
| 8 | `redirection` | 5.10.1 |
| 9 | `polylang` | 3.8.10 |
| 10 | `contact-form-7` | 6.2 |

**Simultaneous, not simulated.** `probe.cmd_install` retrieves and installs all
ten exact pinned packages first and only then activates the set, one activation
per plugin, in the declared order with `contact-form-7` last as the subject. No
plugin is deactivated along the way, so the measured state is a genuinely
simultaneously active combination rather than a sequence of activate/deactivate
cycles. `identity()` then requires the active set to equal exactly these ten
slugs (plus `clinic-practice-management` in Stage B) and every header version to
equal its pin; nine of ten active, or one drifted version, fails Stage A.

**Competing SEO plugins are measured, not tuned.** Yoast SEO and Rank Math both
install their own SEO stack. Neither is disabled, unhooked or configured to defer
to the other, and no product or plugin setting is weakened. If their coexistence
produces a material failure it is preserved as the Stage A finding and Stage B is
**not** run — the existing `begin-b` interlock refuses CPMS installation without a
materially healthy, complete Stage A.

**LiteSpeed on Apache.** `litespeed-cache` 7.9.1 is installed and activated, but
its server-level page cache requires a LiteSpeed web server and this lane serves
Apache. That is recorded under `unavailable_features` in `summary.json`/`summary.md`
as **FEATURE UNAVAILABLE**, not as a pass and not as a plugin defect; nothing is
reconfigured to hide it.

**Selection (smallest mechanism, unchanged for the other groups).**
`persian-coexistence.yml` gains a third job, `coexistence-combined-ten`, calling
the same reusable `real-wp-acceptance.yml` with `coexistence_group: combined-ten`
→ `COEX_GROUP`. Evidence root `coexistence-combined-ten/`, artifact
`persian-coexistence-combined-ten-wp_`. The five-plugin job, the
security/authentication job, their pins, roots, artifacts and workflow name are
untouched; the concurrency key already includes the group, so the three jobs never
cancel each other.

**Evidence separation.** `summary.json` keeps `stage_a`, `stage_b`, `pins`,
`not_run` and `unavailable_features` as separate machine-readable fields, and
`A/result.json` / `B/result.json` keep `status`, `first_causal_check`,
`blocking_checks` (material) and `warnings` (non-material) apart. Stage B is
reported `NOT RUN` whenever the boundary is not reached — it is never folded into
a Stage A verdict, and a green workflow is not compatibility. This group says
nothing about the other nine plugins of the wider campaign; **no 19-plugin claim
is made**. Persian WooCommerce is deliberately **not** in this group, and its
existing third-party baseline FAIL is unchanged.

**NOT RUN in this group:** authored third-party configuration (redirect rules,
Polylang languages/strings, ACF field groups, User Role Editor role/capability
edits, WP Crontrol cron edits), Contact Form 7 submission handling, and the
four scope limits inherited by every group. No CPMS product code, migration,
authorization or existing test was changed for this group.

## Initial implementation validation and prerequisite checkpoint

At implementation start (2026-10-08): no open PRs; clean assigned branch
`arena/f72cf6e0-doctor`; HEAD and authoritative main were
`49694214385889b2f957df95abe85f13f21710cf`, PR #196's merge SHA. All **41** exact-SHA
check runs were completed/success; the five workflows were completed/success:

- CI: `37725734953`
- Real WordPress Acceptance: `37725735054`
- Closure Gate: `37725734998`
- Pilot/Staging Readiness Gate: `37725735003`
- Third-Party Baseline: `37725734999`

Latest migration remains `2026_09_26_0023_handwriting_prescription_paper.php`.
This closure check is not an assertion that every third-party baseline passed.

Local validation at the initial implementation head:

- **19 focused tests pass**: gate/NOT RUN behavior, first-error preservation,
  zero-exit measurement failures, activation failure, skipped acceptance steps,
  complete acceptance anchors, log boundary/deltas/rotation, exact package ZIP
  identities for all five pins, version drift, untruncated command output and a
  real local HTTP redirect/503 single-attempt test. WordPress/DB/browser results
  in these fault tests are synthetic; this is not product runtime proof.
- Python compile + pyflakes pass; Bash syntax passes for all 24 run blocks.
- PHP **8.3.33 WASM** syntax checks pass for the new runtime probe and five reused
  embedded PHP blocks. This validates syntax, not Apache/MySQL integration.
- actionlint **1.17.0** (kjanat distribution), ShellCheck **0.11.0**: no added
  findings versus main. Nine existing integrated findings remain (two node20
  action deprecations and seven shell diagnostics). Independent ShellCheck
  `--severity=style` reports the same eight pre-existing diagnostics, including
  the standalone `cd` warning. No suppressions or unrelated upgrades added.
- Exact approved pins match the unchanged 19-plugin campaign; baseline workflow
  is byte-identical to main. Scope review: only `.github/` infrastructure changes;
  zero product, migration, authorization, or existing test/result changes.

**NOT RUN locally:** actual official plugin/core downloads, Apache + MySQL 8.4.11,
Chromium, Stage A and Stage B product execution. This sandbox cannot reach the
WordPress distribution hosts. Runtime acceptance must come from the workflow,
not from these local harness tests or from a green workflow badge alone.


## PR #197 — narrow blocker recovery

Historical head `872d3fe2d004818f0fb000782a43384afb8ec83d` had exactly two failed
checks: WPCS changed code (`113240763213`) and coexistence (`113240765742`).
Main/base remained `49694214385889b2f957df95abe85f13f21710cf`. Run `37756057664`
recorded `fa_gmt_offset_agrees_with_php` expected 3.5 / observed 0.0. Its CPMS
builder, boundary, installation, migrations and browser steps were **skipped**;
Stage B actually was **NOT RUN**. Artifact retrieval from this sandbox is blocked
by the blob-storage host restriction; check annotations and job steps were read
through the GitHub API. No new coexistence result is inferred from them.

### WPCS correction (no exemption or relaxed rule)

Use the existing collector's repository-root mode for **both** collection and
PHPCS, with explicit repository-root `--basepath`. This includes `.github/` PHP
in the same added-line domain as product PHP, retaining the original ratchet,
exclusions and every sniff. `-q` removes progress/footer text, not violations,
from the JSON report. A new mixed root-harness/product regression supplements
the four existing collector self-tests.

The standalone runtime probe now uses integer-format JSON version components and
literal engine-SAPI values, avoiding a WordPress-only encoder recommendation
without loading WordPress or suppressing any sniff. It no longer reports the
optional server banner; actual PHP version and SAPI remain measured. Unsupported
SAPIs return 503. Actual PHPCS 3.13.6 + WPCS 3.4.1 inspected this exact file with
zero errors/warnings. An injected unescaped request value in a temporary copy of
the **same path** produced both nonce and escaping findings, which the real
collector's added-line keys matched. The file was not merely discovered/skipped.

### Proven timezone oracle defect, not an inferred plugin modification

The historical sequence was: English core installation → install/activate fa_IR
→ set `timezone_string=Asia/Tehran` → request a PHP-derived raw `gmt_offset=3.5`
through WP-CLI → install all five packages → activate them → locale probe reading
`gmt_offset` directly from MySQL.

WordPress 7.1.3 code evidence (tag commit
`fc9832bef919c2a08541248db23af90d14ffb082`):

- [`wp-admin/includes/schema.php`, lines 387–410](https://github.com/WordPress/WordPress/blob/fc9832bef919c2a08541248db23af90d14ffb082/wp-admin/includes/schema.php#L387)
  initializes an English installation's empty timezone and zero offset.
- [`default-filters.php`, line 499](https://github.com/WordPress/WordPress/blob/fc9832bef919c2a08541248db23af90d14ffb082/wp-includes/default-filters.php#L499)
  installs `wp_timezone_override_offset` on `pre_option_gmt_offset`.
- [`functions.php`, lines 6669–6692](https://github.com/WordPress/WordPress/blob/fc9832bef919c2a08541248db23af90d14ffb082/wp-includes/functions.php#L6669)
  documents and implements the named-timezone-derived override;
  [`get_option()`](https://github.com/WordPress/WordPress/blob/fc9832bef919c2a08541248db23af90d14ffb082/wp-includes/option.php#L123)
  returns the filter value **before reading the stored row**.
- [`WP-CLI Option_Command::update`, lines 433–463](https://github.com/wp-cli/entity-command/blob/2e68f985fd3173edf5f806c3c57e0082b3b79c2e/src/Option_Command.php#L433)
  sanitizes the requested value and the effective `get_option()` value. If equal,
  it reports success/unchanged without a write. Core's `sanitize_option()` makes
  both values the same numeric string here. Locale activation updates `WPLANG`,
  not these timezone options (`Core_Language_Command::activate_language`).

Small runtime reproduction: unmodified WordPress 7.1.3 APIs/default filters and
object cache under PHP 8.3.33 WASM, no plugins or MySQL. Only the two persisted-row
values were seeded in `alloptions`; option/filter/sanitization code was real.
Executing the upstream WP-CLI update method with command-I/O shims also produced
`Value passed for 'gmt_offset' option is unchanged.`

| Moment | Raw stored offset | Effective get_option | PHP-derived |
| --- | --- | --- | --- |
| Initial core defaults | 0 | 0 | 3.5 (reference Asia/Tehran) |
| Named timezone configured | 0 | 3.5 | 3.5 |
| Historical WP-CLI offset update, no plugins | 0 | 3.5 | 3.5 |

Thus **raw 0 is legitimate WordPress option semantics**. Comparing that stored
fallback to the active named zone was the defect in this slice's test oracle.
This proves the mismatch without plugins; it does **not** prove that the five
plugins never modify settings in a real run.

The redundant raw-offset write is removed. `A/timezone.json` now records UTC
capture time, raw/effective timezone string, raw stored offset, effective
`get_option('gmt_offset')`, PHP DateTimeZone-derived offset and read errors:
after core install, after fa_IR setup, after timezone configuration, after all
packages are installed/before activation, after **each** plugin activation, and
immediately before the locale probe, and after the Stage A HTTP/browser probes.
Only these two option rows are queried;
no credentials or unrelated database contents are collected.

The opt-in group oracle compares the **effective** offset to PHP. Raw values
remain evidence; changes to raw offset or raw/effective timezone after fixture
configuration, wrong effective offset, missing samples and unreadable diagnostics
still materially fail Stage A and prevent Stage B. Historical baseline defaults,
including their existing raw-value oracle and no observer calls, are unchanged.
No CPMS code, migrations, pins, authorization or Location authority changed.

Recovery validation: **27 focused tests pass**, including a real core-semantics
reproduction; **5/5 collector self-tests pass**; positive/negative actual WPCS
inspection described above passes. Run the core reproduction test with
`CPMS_WP_SOURCE=/path/to/wordpress-7.1.3 CPMS_TEST_PHP=/path/to/php` when running
`python3 -m unittest discover -s .github/persian-coexistence -p 'test_*.py'`.
Without that external source the one core-runtime test is explicitly skipped,
not passed. This is still **not** an actual five-plugin Stage A/B acceptance run.

Recovery syntax/static review: Python compile + pyflakes pass; PHP 8.3 syntax
passes for both the standalone probe and core reproduction, and for the embedded
read-only timezone eval. Bash syntax passes for all 46 run blocks in the relevant
workflows. Actionlint 1.17.0 reports the same 18 pre-existing diagnostics as the
historical head; standalone ShellCheck 0.11.0 reports the same 10 pre-existing
diagnostics. No suppressions, ruleset changes or Actions upgrades were added.
The correction touches infrastructure/tests/docs only; actual five-plugin
coexistence after this correction remains unproven until new runtime evidence.
