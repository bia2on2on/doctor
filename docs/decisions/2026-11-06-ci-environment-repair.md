# Decision + evidence record — CI environment repair (baseline builds, runner OS, browser cache)

> **Document type:** engineering decision + evidence record for one task. It
> changes no test, no assertion, no audit, no allowlist, no ignore list and no
> version/tag/release. It records what was changed, why, what is **proven** and
> what is **unproven**, and when the temporary parts expire.
> **Recorded:** 2026-11-06. **Branch:** `arena/5375738f-doctor`.
> **Base:** `main` = `aa94819c0799a59b8f853f1b3958a5644f73e643`.

## 1. Starting facts (verified locally unless stated otherwise)

Verified by reading the files at `aa94819c…`:

| # | Fact | Where |
|---|---|---|
| A1 | `facility-matrix-acceptance.yml` builds a pinned schema-0030 baseline with `BASE_SHA=d2c9f779df47f45137b5ffe9c8bc2dce2451f404` | `facility-matrix-acceptance.yml:233` |
| A2 | `classic-v1-remediation.yml` uses `BASELINE_SHA: 9bf84304b2b143138c638fa1ca1d71869aa1e244` in **two** jobs (regressions + Real HTTP profile) | `classic-v1-remediation.yml:55`, `:200` |
| A3 | Both baseline commits carry `clinic-practice-management/composer.lock` blob `2d5f4579…`, whose `symfony/filesystem` is `v6.4.45`. `main`'s lock is blob `440c8446…` with `v6.4.50`; the two blobs differ **only** in `symfony/filesystem` (6 insertions / 6 deletions) and share the same `content-hash` | `git cat-file`, `git diff 2d5f4579 440c8446` |
| A4 | Release packaging runs `composer audit --locked --no-dev --abandoned=fail --format=json` (fail-closed) | `clinic-practice-management/bin/build-release.sh:53` |
| A5 | `playwright-browsers/action.yml` key included only `runner.os` (i.e. `Linux`), never the OS release | `action.yml:74`, `:84` |
| A6 | The pinned schema-0030 probe asserts `currentVersion() === '2026_10_27_0030'` for the `baseline` stage | `clinic-practice-management/tests/bin/wp-activation-schema-proof.php:30` |
| A7 | The 9 required contexts are unchanged by this record: `Unit Tests (PHP 8.1…8.4)`, `Integration (WP 7.1 + MySQL 8)`, `Closure — PHP 8.1/8.3/8.4 runtime (WP 7.1.3)`, `Closure — destructive restoreApply (isolated)` | `ci.yml:68`, `:200`; `closure-gate.yml:50`, `:321` (names only) |

Live CI evidence read through the GitHub API on 2026-11-06 (annotations, job
and step lists — **not** job logs, see §6):

| # | Observation | Run / job |
|---|---|---|
| B1 | `Facility matrix (mariadb)` and `Facility matrix (mysql)` fail at step *"Verify upgrade from schema 0030 and repeat activation"* with annotation **"CPMS upgrade proof: Could not build the pinned schema-0030 ZIP"**; step 13 (fresh install) passed. | run `42037824825` (push, main `aa94819c`) |
| B2 | `Real HTTP profile (MySQL 8, same runner)` fails at *"Build baseline and candidate official ZIPs"*, downstream annotation `HTTP comparison unavailable … '/tmp/classic-http/baseline.json'` | run `42105398464`, job `127518761711` |
| B3 | The seven `persian_coexistence: true` lanes fail at **apt**, annotation verbatim: `E: Unable to locate package libapache2-mod-php8.3 … E: Unable to locate package p…` (list: `mysql-client apache2 libapache2-mod-php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd`) | run `42105398521`, 7 jobs |
| B4 | `Responsive smoke (Chromium ×4 viewport)` fails at *"Playwright chromium + firefox + webkit (pinned + cached)"*: `cached browser binaries did not launch on this runner` → `Playwright reports missing system dependencies for chromium firefox webkit` → **`browsers still do not launch after one bounded dependency provisioning`** | run `42105398576`, job `127518973776` |
| B5 | `First-Practice Browser Acceptance` fails at step *"Apache + mod_php"* (apt and Playwright steps **passed**; `mod_php_enabled=php8.5.load`) | run `42105398529`, job `127518762361` |
| B6 | Runner image notice on the same run: *"The ubuntu-latest label will migrate to Ubuntu 26 beginning October 19, 2026."* | job `127518761711` annotation |

## 2. What changed

### 2.1 Two build-only baseline commits E1/E2 (the historical baselines)

`E1` on top of `d2c9f779` and `E2` on top of `9bf84304`. **Each changes exactly
one file:** `clinic-practice-management/composer.lock`, set to `main`'s blob
`440c8446` (verified with `cmp` against `git cat-file blob
440c8446…`; `composer.json` and every other path is untouched, verified with
`git diff --name-only <base> <commit>`).

- E1 `f92d5d744eb10d3dd04d8224efb42690b6d404b1`, tree `bcd5b7e3fc2dd10681881f13a49e3d81eec2deb8`
- E2 `a17a8d7a70598c959d8d726c664ff48f8a27a882`, tree `ca70a42836842baf6351c9f269309a6828f2fd67`

The requested/expected trees were `bcd5b7e3` and `ca70a428` — both match exactly.

Workflow references were repointed (identification numbers only, no logic
change):

| File | Before | After |
|---|---|---|
| `facility-matrix-acceptance.yml` (`BASE_SHA`) | `d2c9f779…` | `f92d5d74…` (E1) |
| `classic-v1-remediation.yml` (`BASELINE_SHA`, both jobs) | `9bf84304…` | `a17a8d7a…` (E2) |

The upgrade proof still requires schema **`2026_10_27_0030`**: the probe
(A6) is unchanged, E1 adds no migration, and the job still fails closed if the
baseline reports a different version.

### 2.2 Playwright browser cache key and browser dependency proof

- Key: `pw-browsers-v2-${runner.os}-…` → **`pw-browsers-v3-${runner.os}-${ID-VERSION_ID}-…`**.
  The OS release is read from the runner's own `/etc/os-release`
  (`ID`-`VERSION_ID`, e.g. `ubuntu-24.04`), sanitised to a cache-key-safe token.
  `runner.os` alone is `Linux` for every Ubuntu label, so it cannot separate
  24.04 from 26.04 while the browser binaries are linked against that release's
  libraries. If the release cannot be determined the key step **fails closed**
  rather than degrading back to `runner.os`.
- Dependency proof now covers **all requested browsers** and runs on **both**
  paths: `install-deps --dry-run "${BROWSERS[@]}"` is always given the complete
  list, and `ensure_system_deps` runs on a cache hit too (before the launch
  attempt) as well as on a cold miss. Previously a cache hit skipped it
  entirely until a launch failed.
- Still fail-closed: after provisioning, the dry-run must exit 0 for the whole
  set, and every requested browser must really launch
  (`verify_launch.py`, unchanged behaviour).
- New last-resort step, still fail-closed: if the bounded `apt-get install` of
  the *parsed* package list fails (a parsed name can be the pre-`t64` spelling
  on a newer Ubuntu release, e.g. `libasound2` vs `libasound2t64`), the same
  browser set is handed **once** to `playwright install-deps` — Playwright's own
  pinned mapping — and the run still fails if the re-verified dry-run is not
  clean. `install --with-deps` is still never used.

### 2.3 Runner OS pins at the 2026-11-06 decision (all expiring 2026-12-15; see amendment)

| Workflow / job | Pin | Status |
|---|---|---|
| `real-wp-acceptance.yml` / `acceptance` (the seven `persian_coexistence: true` lanes) | `${{ inputs.persian_coexistence && 'ubuntu-24.04' \|\| 'ubuntu-latest' }}` | evidence-backed: B3 names the missing `php8.3-*` packages |
| `first-practice-acceptance.yml` / `first-practice` | `ubuntu-24.04` | **EXPERIMENT** — cause not proven (§6) |
| `pilot-gate.yml` / `responsive` | `ubuntu-24.04` | the three-browser lane that failed at B4 |
| `facility-matrix-acceptance.yml`, `classic-v1-remediation.yml` | **not pinned** | their CI failure is the baseline build (B1/B2), not the runner image |

No job name, matrix entry, step name or workflow name was changed: the 9
required contexts keep their names, and the seven Real WP job identities
(`coexistence`, `coexistence-security-authentication`,
`coexistence-combined-ten`, `coexistence-persian-woocommerce-diagnosis`,
`coexistence-persian-woocommerce-acceptance`,
`coexistence-persian-woocommerce-sms`, `coexistence-combined-nineteen`) are
untouched. No audit, test, check, allowlist, ignore list, baseline file,
`continue-on-error` or `composer audit` invocation was removed or weakened.

## 3. Why `-s ours`

The workflow uses `git archive <sha>` / `git fetch --no-tags --depth=1 origin
<sha>` on the runner, so E1/E2 must be **reachable from a ref this repository
fetches**. They are build-only commits on old baseline parents; rebasing or
rewriting history is forbidden, and creating another branch or a tag is
forbidden by the task contract. The only forward-only way to publish them is to
carry them as merged ancestors of this session branch:

```
git merge -s ours --no-ff E1 E2   # octopus, accepted by git
```

Result: merge commit `7400c9059b229390ca8acd50ca63476f9bb0ff9d` with parents
`aa94819c…`, E1 and E2. Verified at that merge commit: its own tree
`cd6ff252…` is identical to `main`'s tree, so `git diff aa94819c <merge>` is
empty; both `git merge-base --is-ancestor E1 <merge>` and `… E2 <merge>`
succeed.

**Correction (2026-11-06, post-run).** An earlier version of this record said
`git diff aa94819c HEAD` is empty *for the branch*. That was true only of the
merge commit itself. The **final branch head is not an empty diff**: it adds the
workflow changes and the docs on top of the merge. The accurate statements are
(i) the merge commit introduces no content change, and (ii) the branch head
differs from `main` by exactly the files listed in §2 plus this record — and by
nothing under `clinic-practice-management/`, which is what the companion
isolation check verifies (§4).

**Consequence that must be stated:** E2's only useful purpose after publication
is to be an ancestor; when this branch is merged to `main`, **E1 and E2 become
ancestors of `main`** (merged-in history), and `main`'s own tree is unchanged by
them. They are not releases, carry no tag, and change no version. Their content
difference from `main` is limited to the historical `composer.lock` blob.

## 4. What is proven, and how

Run locally on this branch at the head recorded in `docs/agent-log.md`:

- `python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py'`
  → **74 tests, OK** (no test added, removed, skipped or weakened).
- `python3 .github/scripts/companion-isolation.py` fed `git diff --name-only HEAD`
  → **PASS** (`production subtree unchanged`; 7 changed files, all `.github/`).
- `python3 bin/check-workflow-yaml.py` → `WORKFLOW_YAML_OK files=17`.
- Inline `run:` scripts of the changed composite action parsed and passed `bash -n`.
- `git diff aa94819c HEAD` empty; E1/E2 reachable as ancestors.

**Not proven by this record:** any CI green. Everything below in §6 is open.
Nothing here converts an unrun check into a pass.

## 5. What is unproven (explicit)

1. **B1/B2 root cause is inferred, not shown.** The annotations say the pinned
   baseline ZIP could not be built; they do **not** contain the composer audit
   output, and (see §6) the job logs could not be retrieved from this sandbox.
   The lock blob difference (A3) and the fail-closed audit (A4) are facts; that
   the audit is what failed *this* build is an inference until the re-run shows
   either a built ZIP or the audit text.
2. **Whether E1/E2 are enough.** They remove the advisory-bearing lock from the
   baseline tree; they cannot change anything else about an old tree (e.g. PHP
   8.5 vs. the baseline's own constraints).
3. **The Real HTTP profile baseline may still fail** for reasons unrelated to
   the lock (it also builds the candidate; the annotation only names a missing
   `baseline.json`).
4. **First-practice:** the failing step is `Apache + mod_php` while apt and
   Playwright passed. A plausible cause (mod_php on the newer label is
   `php8.5.load` while the step edits `/etc/php/*/apache2/php.ini` and starts
   Apache) is **not proven**; the pin is recorded as an experiment whose value
   is the evidence it produces.
5. **~~The advisory range was not independently verified.~~ Resolved.** It is
   advisory **GHSA-6prc-h956-hxjq** ("Filesystem Path Treats Any Prefix Before
   `://` as a Root, Letting Paths Escape Their Base Directory", severity *high*,
   published 2026-11-04T11:43:35Z, no CVE assigned): `symfony/filesystem`
   `>=6, <6.4.50` is vulnerable and `6.4.50` is the patch. That is the range the
   original report stated. Sources read directly: the repository security
   advisory `repos/symfony/symfony/security-advisories/GHSA-6prc-h956-hxjq` and
   the composer-audit feed entry `symfony/filesystem/2026-11-04-18.yaml` in
   `FriendsOfPHP/security-advisories`. The advisory ID and range were **not**
   seen in a CPMS job log: job logs remain unreadable from this sandbox (§6), so
   the link from this advisory to the failing builds rests on the blobs, the
   range arithmetic and the now-green baseline builds — see §8.
   Note on why the earlier lookup failed: `GET /advisories?affects=…` and the
   public advisory search returned nothing for this package on 2026-11-06, while
   the repository-advisory endpoint and the composer feed do list it.
6. **`t64` rename path** (the new `install-deps` fallback) has not been
   exercised on a real 26.04 runner.
7. ~~Responsive smoke on 24.04 with the new key: the cache is a guaranteed miss
   (`v3`), so the next run is cold for every browser lane.~~ **Corrected after
   the run — this prediction was wrong.** A `v3` key can only miss where no
   `v3` entry exists *for that key*. Measured on head `fb63a977`: every browser
   lane that ran on the `ubuntu-latest` label missed (`cache_hit=false`) and had
   to provision dependencies, while the demo lane, which had already written a
   `v3` entry for its own key, hit (`cache_hit=true`). "Cold for every lane" was
   an overstatement; the observed rule is "cold for every lane whose key had not
   been saved yet". See §8.

## 6. Limits of this record's evidence

- **Job logs were not retrievable** from this sandbox: `gh run view --log` and
  the jobs-log API returned `EOF` against the Azure blob URL for every job
  attempted. All CI statements above come from the **annotations API, job step
  lists and run/workflow lists**, which were retrieved successfully.
- Therefore *which* command inside "Could not build the pinned schema-0030 ZIP"
  failed is **NOT RETRIEVED**, and the First-practice failing command is
  **NOT RETRIEVED**.

## 7. Expiry / follow-up

- **Expiry 2026-12-15** for every Ubuntu pin in §2.3. Each pin carries an inline
  `EXPIRY 2026-12-15` comment with the removal condition. Do not extend silently:
  extending requires a new decision record with fresh evidence.
- **Follow-up (not done here):** newest-PHP coverage. The versioned `php8.3-*`
  pins in the coexistence lanes are the reason the label migration broke them.
  A durable fix is to stop hard-coding a PHP minor in apt and to prove the
  served PHP version (the `wp-stack-tooling` action already records
  `mod_php_enabled`), plus a lane that runs the acceptance set on the newest
  distro PHP. That is a separate change with its own evidence.
- **Follow-up:** once both Ubuntu releases have current Playwright browser
  dependencies, remove the pins and rely on the OS-release cache key alone.

## 8. Post-run evidence (2026-11-06, appended after the first CI cycle)

Head **`fb63a97713ed9e939ee75f2f4888f436060b7a8b`**. Everything below is read
from **annotations, job/step lists, run lists and repository-advisory APIs**;
job logs stayed unreadable from the authoring sandbox (§6), and that limit
applies to every statement in this section.

**Census, with the counting method stated** (re-measured 2026-11-06 after a
reviewer challenge; the earlier "37 checks — 35 success / 2 failure" sentence in
this section was **wrong** and is corrected here):

- **Method A — latest check-run per distinct name**: `GET
  /commits/{sha}/check-runs` with `per_page=100` and no `filter` returns
  `total_count: 37` and 37 records (the same numbers as `filter=latest`, and
  the view `gh pr checks` shows): **37 records = 36 success / 1 failure**, 0
  pending. The single failure is *Real WordPress acceptance (clean WP → CPMS →
  companion → teardown)*, job `127601112667`.
- **Method B — every stored attempt**: the *same* URL with `filter=all` returns
  `total_count: 42` and 42 records, and `filter=all&page=2` returns the same
  `total_count: 42` with 0 records: **42 records = 39 success / 3 failure**, 5
  names carrying two records each. The three failure records are the demo lane
  at attempt 1 (`127570978339`, 22:34:35Z) and attempt 2 (`127601112667`,
  23:41:55Z), plus *Real HTTP profile* at attempt 1 (`127570801387`, 22:19:41Z)
  which went `success` at attempt 2.
  **Precision correction (2026-11-06, second review):** an earlier version of
  this sentence said the endpoint "reports `total_count: 37` while returning 42
  records", which describes **no single call**. The two numbers come from two
  different calls — the default call is self-consistent at 37/37, and
  `filter=all` is self-consistent at 42/42. Both were re-verified through the
  API on 2026-11-06 before this correction was written.

So the only lane red at that head, under either method, is the demo lane. The
failing sentences this replaces are preserved in the git history, not silently
deleted from the record. All 9 required contexts are green with unchanged
names.

### 8.1 E1 and E2 were sufficient for the baseline builds (proven)

- **Facility matrix (mariadb) and (mysql): pass** (7m34s / 6m48s). The step that
  used to fail — *"Verify upgrade from schema 0030 and repeat activation"* — is
  `success`, and the following *"Verify schema + no PHP fatal"* step is
  `success` too. So the pinned schema-0030 ZIP built from E1 **and** the
  `baseline-not-0030` assertion did not fire. The 0030 requirement is therefore
  still enforced, not bypassed.
- **Classic V1 Regressions (mysql) and (mariadb): pass**, so E2's archived
  baseline ZIP built as well.
- This converts the §5.1 inference into an observed outcome for these three
  lanes: the advisory-bearing lock in the old baseline trees was the blocking
  factor. It does **not** prove that no other defect exists in those trees —
  only that the build gate that was red is now green.

### 8.2 Real HTTP profile: attempt 1 network timeout, attempt 2 green

- **Attempt 1** (same head `fb63a977`): failed at *"Build baseline and candidate
  official ZIPs"*, exit 100, annotation verbatim:
  `curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out after 10006 milliseconds`.
  That is the composer audit download path - a network timeout, not an advisory
  and not a code defect. The downstream
  `'/tmp/classic-http/baseline.json'` annotation is a consequence.
- **Attempt 2** (owner re-run of the same run, `run_attempt=2`): the job is
  `success`; the run's `attempt=2` conclusion is `success`. The job's attempts
  list shows attempt 1 `failure`, attempt 2 `success`.
- Not changed in response: the audit stays fail-closed in both baseline and
  candidate builds. A transient timeout was **not** converted into an ignore
  rule or a `continue-on-error`.
- The advisory ID and range were **not** observed in any CPMS job log (logs
  unreadable); they were established independently in §5.5.

### 8.3 Responsive lane: green on the pinned 24.04 with a cold cache

- *Responsive smoke (Chromium ×4 viewport)*: **pass**, 12m16s.
- Its Playwright annotation, verbatim (trimmed):
  `browsers=chromium firefox webkit cache_hit=false deps=installed (bounded apt for exactly Playwright's missing-package list, verified for chromium firefox webkit) [cache miss] seconds=38 builds=chromium-1248 chromium_headless_shell-1248 ffmpeg-1013 firefox-1555 webkit-2370`.
- So: the lane that had failed closed on all three engines was green on
  `ubuntu-24.04`, with a **cache miss** and a dependency proof covering **all
  three** requested browsers. Both the runner pin and the v3 key were in effect.
- **What this does not show:** that the three-browser set launches on the newer
  label. No lane asked for three browsers on `ubuntu-latest` in this run, so
  that is unproven (§8.4).

### 8.4 install-deps fallback: exercised on the newer label, Chromium only

- On every `ubuntu-latest` runner in this run the ladder behaved as designed:
  `Playwright reports missing system dependencies for chromium` →
  `the first apt-get install failed or hit its 240s wall-clock cap` →
  **`the bounded apt install of the parsed list still failed; handing the same
  browser set (chromium) to 'playwright install-deps' once before failing`** →
  final `notice` reports `verified for chromium` and the job proceeded.
  Observed on the demo lane, both First-practice lanes, both Facility lanes and
  both Disposable-site lanes.
- **Chromium only.** No lane in this run exercised the fallback with firefox or
  webkit, and the three-browser combination on the newer label is therefore
  **unproven**. Do not read the Chromium result as covering three engines.
- Correction to a related assumption: the runner notices say the label **"will
  migrate to Ubuntu 26 beginning October 19, 2026"**, i.e. they are
  future-tense, and `mod_php_enabled=php8.5.load` is reported on runners that
  still carry that notice. This record does therefore **not** claim those
  runners were running Ubuntu 26.04. What is recorded is the observed behaviour
  of the newer label (PHP 8.5 mod_php, missing Playwright system libraries,
  versioned `php8.3-*` packages absent), which is what the pins address.

### 8.5 The demo lane failed twice at the same step

- Workflow: `.github/workflows/disposable-demo-installer.yml`, job
  `real-wordpress`, check name *"Real WordPress acceptance (clean WP → CPMS →
  companion → teardown)"*.
- **Attempt 1** (job `127570978339`) and **attempt 2** (job `127601112667`), same
  head `fb63a977`: identical annotation, verbatim:
  `CPDD_BROWSER | stage=1-2. ZIP installation and activation through wp-admin TimeoutError: Locator.wait_for: Timeout 60000ms exceeded. Call log: - waiting for locator("a[href*='action=activate']").first to be visible`
- **Correction (2026-11-06, after a reviewer challenge; verified through the
  annotations API).** This section previously said attempt 1 missed the browser
  cache. That was **wrong**: **both** failing attempts reported
  `cache_hit=true`. Verbatim from the two jobs' `playwright browsers`
  annotations:
  attempt 1 (`127570978339`) — `browsers=chromium cache_hit=true deps=installed
  (bounded apt for exactly Playwright's missing-package list, verified for
  chromium) [cache hit] seconds=681`;
  attempt 2 (`127601112667`) — same text with `[cache hit] seconds=696`.
  Both jobs also report `labels=['ubuntu-latest']` and
  `mod_php_enabled=php8.5.load`. So **cache temperature is not the
  discriminator**: the failure was identical with the browser binaries restored
  from cache in both attempts, and the dependency provisioning that followed
  still ended with a launch that the lane could use.
- Green reference: `main` push run `41775097740`, same job `success`, same
  workflow, whose annotation reported `mod_php_enabled=php8.3.load` and
  `deps=reused (cached browsers launched; system dependencies already present)`.
  This branch's runs report `php8.5.load` and had to provision dependencies.
  **That contrast is an association on the recorded evidence, not a proven
  cause.**
- Therefore the lane is now pinned to `ubuntu-24.04` as an **EXPERIMENT**
  (`EXPIRY 2026-12-15`, cause unproven). Its value is the evidence the next run
  produces: green here and still red on the newer label implicates the image;
  red here too means the image is not the cause and the pin is removed.
  No job, step or matrix name was changed.

### 8.6 Merge strategy: a merge commit, never squash or rebase

The workflows resolve their baselines **by SHA**
(`facility-matrix-acceptance.yml` `BASE_SHA=<E1>`,
`classic-v1-remediation.yml` `BASELINE_SHA=<E2>`). E1 and E2 exist only as
merged ancestors of this branch, so the branch must reach `main` with those
commits preserved:

- **A GitHub "Squash and merge" or a rebase-merge would collapse/rewrite the
  history and E1/E2 would not be ancestors of `main`**; the next run on `main`
  would then fail to resolve `f92d5d74…`/`a17a8d7a…` and the Facility and
  Classic V1 baseline proofs would break again.
- Required: a **merge commit** (`gh pr merge --merge`), keeping this branch's
  commits. This is recorded as a merge constraint, not as an authorization to
  merge: this task does not merge.
- Also required afterwards: **do not delete branch `arena/5375738f-doctor`**
  until `main` has run both baseline lanes green at least once after the merge.

### 8.7 Nothing enforces the expiry, and the demo-lane green is three same-configuration observations

**Nothing enforces the 2026-12-15 expiry automatically.** The date is a recorded
marker, not a mechanism: no workflow, test or scheduled job compares it to the
current date, and none of the contract tests in this repository would fail when
the date passes. The pins will keep running exactly as they are until a human
opens this record and removes them; the only automated guard is that a pin
without an `EXPIRY YYYY-MM-DD` marker next to it fails the workflow contract
test (existence of the marker, never the date). Revisiting on or before
2026-12-15 is therefore a manual obligation.

**The demo lane has been green three times under the same configuration, and its
cause remains unproven.** Measured through the API (annotations and job
records), the demo lane passed at **three heads**: `8cc0bb55` (job
`127623459458`, attempt 1), `54c48231` (job `127637661167`, attempt 1) and
`9859ee3a` (job `127700197381`, attempt 1) — all `labels=['ubuntu-24.04']`, all
`mod_php_enabled=php8.3.load`, all `cache_hit=true` with browser steps of 9 s,
9 s and 8 s. The configuration is literally the same file bytes at all three
heads: `disposable-demo-installer.yml` blob `980f30be…`,
`playwright-browsers/action.yml` blob `606d48f7…` and
`bin/build-release.sh` blob `91e6b378…` are identical in all three commits.

**These three observations are not independent.** They share the same runner
pin, the same code path, the same workflow/action blobs and the same runner
family, so they are repeats of one configuration rather than three independent
tests of a hypothesis; three correlated runs do not turn one observation into a
controlled experiment. Compared with the red runs
(`fb63a977`, `labels=['ubuntu-latest']`, `php8.5.load`), the OS image and the
PHP version changed together (`php8.3.load` on 24.04 versus `php8.5.load` on
the newer label), and there is no controlled run that changes only one of them.
Repeats under one configuration cannot attribute the result to any variable, so
the pin stays labelled EXPERIMENT and the cause stays UNPROVEN. It is certainly
not evidenced that the pin *fixed* anything.
A **third** variable also differed between the red and green demo runs: the
browser cache entry is keyed per OS release
(`pw-browsers-v3-${runner.os}-${ID-VERSION_ID}-…`, §2.2), so the 24.04 run
restored a **different** cache entry than the newer-label run — measured
`cache_hit=true` with a 9 s browser step on 24.04 versus `cache_hit=true` with
681 s / 696 s on the newer label, i.e. a hit whose restored binaries still
needed full dependency provisioning. Three variables moved together (OS image,
PHP version, cache entry), so the cause of the green remains unproven.

### 8.8 What changed in the check census

- Green where the starting list was red: Facility mysql + mariadb; First-practice
  mysql + mariadb; Responsive smoke; all seven Real WP coexistence lanes;
  Classic V1 Regressions mysql + mariadb; and (attempt 2) Real HTTP profile.
- Still red at head `fb63a977`: the demo lane (§8.5). Nothing else.
- Newly added by this batch: the contract test in §9, which changes no runtime
  behaviour.

## 9. Second batch (appended): the demo lane pin and the new contract test

- `disposable-demo-installer.yml` `real-wordpress` → `runs-on: ubuntu-24.04`
  with `EXPERIMENT — EXPIRY 2026-12-15, cause UNPROVEN` and the reasoning in
  §8.5. **Allowed under the companion-isolation contract**: the script's own
  docstring and `WORKFLOW_PREFIXES` classify
  `.github/workflows/disposable-demo-installer.yml` as *lane plumbing*, and its
  decision function fails only on production↔companion/compatibility mixing. The
  check was re-run after the edit (§10).
- Test
  `test_every_runner_pin_records_an_expiry_and_the_cache_key_separates_os_releases`
  in `.github/workflows/test_workflow_contracts.py`:
  (a) every `runs-on:` value in `.github/workflows/*.yml` that **names**
  `ubuntu-24.04` must have an `EXPIRY YYYY-MM-DD` marker in its own comment
  block — whether the pin is literal or a **conditional expression**;
  (b) the composite action's cache key must contain the runner OS release token
  and the architecture, read from the runner, with the fail-closed text present.
  It contains **no date comparison**, so it can never start failing because time
  passed; the date is checked for presence, not compared to today.
  **Historical correction after reviewer challenge (2026-11-06; superseded by the 2026-11-12 amendment):** the first version of this
  test matched only the literal string `runs-on: ubuntu-24.04`, so the seven
  coexistence lanes pinned through
  `${{ inputs.persian_coexistence && 'ubuntu-24.04' || 'ubuntu-latest' }}` in
  `real-wp-acceptance.yml:102` were **not covered** — the test passed while that
  pin had no guard at all. The test now matches any `runs-on:` value naming
  `ubuntu-24.04`, and additionally asserts that exactly one such conditional pin
  is discovered, so that one specific surface cannot silently drop out of the
  check again. No workflow behaviour changed: the marker was already present next
  to that expression, so no comment had to be added and no value was touched.
  **Limit of the check (stated so it is not over-read):** it only inspects the
  text of a `runs-on:` line. A pin that reaches `ubuntu-24.04` indirectly — for
  example `runs-on: ${{ matrix.os }}` with `ubuntu-24.04` in a
  `strategy.matrix` entry, or a pin defined in a reusable workflow *input*
  consumed as `runs-on: ${{ inputs.runner }}` — contains no `ubuntu-24.04` on
  the `runs-on:` line and is therefore **not seen, not validated and not
  counted** by this test. The `exactly one` assertion also asserts one such
  *expression* pin only: if a second legitimately-pinned expression is added
  later, the test fails until it is reviewed and the count updated. The earlier
  wording that the test "cannot silently skip that surface again" was too broad
  and is narrowed here.
  Verified by **two negative controls**: (i) deleting the `EXPIRY` line from
  `real-wp-acceptance.yml` makes the test fail at `real-wp-acceptance.yml:102`;
  (ii) a scratch workflow copy with that marker removed is also caught, which
  proves the conditional pin is really discovered rather than skipped. The test
  earlier flagged a missing marker on the pre-existing `third-party-baseline.yml`
  pin, now annotated there without changing that pin.

## 10. Known side effect of this batch

`third-party-baseline.yml` gained only a comment block (the `EXPIRY 2026-12-15`
marker) on a pin whose reasoning predates this change. That job was green at
head `fb63a977` and its behaviour is unchanged; the comment exists so the
long-lived pin is revisited with the others rather than by inattention.


### 2026-11-12 amendment — Tooling runner mitigation

On the PR branch, all direct and wrapper-reachable Tooling jobs are literal
`ubuntu-24.04` pins; no conditional pin remains. This is a mitigation, not a
root-cause fix: the root cause of the failures on Ubuntu 26.04 is not established.
Removal condition: root cause found and fixed, or a new recorded decision, before
the EXPIRY date 2026-12-15. Expiry is not enforced automatically.
