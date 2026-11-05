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

### 2.3 Runner OS pins (all expiring 2026-12-15)

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
`aa94819c…`, E1 and E2. Verified: `git diff aa94819c HEAD` is **empty** (the
merge's tree `cd6ff252…` is identical to `main`'s tree) and both
`git merge-base --is-ancestor E1 HEAD` and `… E2 HEAD` succeed.

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
5. **The advisory range was not independently verified in the GitHub Advisory
   Database.** On 2026-11-06 the advisory API returned an empty list for
   `symfony/filesystem`, and the public advisory search returned no advisory for
   that package; `symfony/filesystem` `v6.4.50` was released 2026-11-04, i.e.
   inside the window. The only first-party evidence for the advisory is
   `composer audit` itself failing on the old lock in CI.
6. **`t64` rename path** (the new `install-deps` fallback) has not been
   exercised on a real 26.04 runner.
7. Responsive smoke on 24.04 with the new key: the cache is a guaranteed miss
   (`v3`), so the next run is cold for every browser lane.

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
