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

`Asia/Tehran` and a PHP-derived offset belong **only to the site fixture**. They
are not copied into any CPMS Location. No product settings or plugin behavior are
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

## Validation and prerequisite checkpoint

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

Local validation:

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
