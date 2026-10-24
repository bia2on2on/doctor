# CI setup performance — Playwright / WP-CLI / apt provisioning

**خلاصه (FA).** این تغییر فقط لایهٔ *آماده‌سازی* (provisioning) در workflowهای CI را
بهینه می‌کند: دو composite action مشترک، nصب apt با تلاش/تایم‌اوت محدود و بدون
بازخوانیِ تکراریِ indexها روی مسیر سالم، Playwright با نسخهٔ دقیق pin‌شده و کشِ
مرورگر با کلید دقیق، و WP-CLI پین‌شده با SHA-256/SHA-512 که پیش از اجرایی‌شدن
بازبینی می‌شود. هیچ سناریو، ماتریس، نام job/check، تریگر یا assertion حذف یا
تضعیف نشده است.

This page records the design of `.github/actions/wp-stack-tooling` and
`.github/actions/playwright-browsers` and the evidence behind each decision, so a
later auditor can re-check the claims against API data instead of trusting prose.

## 1. Measured baseline (before this change)

Source: GitHub Actions API — `GET /repos/{owner}/{repo}/actions/runs/{id}/jobs` —
for the `main` push run set at `2f675a230563425be3913c8cc9fa9fd34fa0bf4f`
(runs 40021999529, 40021999681, 40021999728, 40021999744, 40021999763,
40021999800, 40021999807; all `success`). Times are the
`started_at`→`completed_at` span of each setup step.

| lane / job | setup step | step time |
|---|---|---|
| Baseline elementor (third-party) | Tooling (apache2 + mod_php pinned … wp-cli + chromium) | 1041 s |
| Baseline persian-elementor (third-party) | same | 970 s |
| Baseline wp-crontrol (third-party) | same | 264 s |
| coexistence-persian-woocommerce-diagnosis | Tooling (apache + wp-cli + playwright/chromium) | 1396 s |
| 18 other third-party legs | same | 68–104 s |
| Responsive smoke (Chromium ×4 viewport) | Tooling (wp-cli + apache + playwright) | 113 s |
| Real WP Acceptance (prefix wp_ / clinic_) | Tooling (apache + wp-cli + playwright/chromium) | 112 s / 92 s |
| First-practice browser (mysql / mariadb) | same | 105 s / 71 s |
| Facility matrix (mysql / mariadb) | same | ~95 s each |
| Staging Gate | Tooling (wp-cli, mysql-client, apache, ab, bc) | 60 s |
| Closure ×4 runtime | Tooling (mysql-client + wp-cli) | 5 s each |

38 setup steps: median 85 s, mean 163 s, sum 6204 s, max 1396 s.

The spread on *identically configured* legs (68 s → 1041 s → 1396 s) is the
signal: the work is the same, so the difference is network time inside apt. The
same shape appears in this repository's own history — `pilot-gate` run
39991158296 lost its Responsive job because the Tooling step alone consumed
24 m 36 s of a 25 m budget (`docs/agent-log.md`, 2026-10-23).

### Why apt was the variable

`shivammathur/setup-php` adds `ppa:ondrej/php` whenever a lane PHP version or an
extension has to come from it (`src/scripts/linux.sh`: `add_extension_helper`
and `add_packaged_php` both call `add_ppa ondrej/php` before `check_package`).
Every setup step in this repository then ran its own
`sudo apt-get update -qq`, which refetches **every** configured index,
Launchpad included. A slow or 503-ing Launchpad therefore turns a 45-second
provisioning step into a multi-minute one without changing what the job
installs.

## 2. What changed

Two single-purpose composite actions replace the plumbing that was duplicated at
eleven call sites in eight workflows. Job names, check names, matrices,
triggers, permissions, timeouts and assertions are unchanged; the browser set of
each suite is preserved (the Responsive job still provisions chromium **and**
firefox **and** webkit, because its Firefox/WebKit smoke step is part of its
acceptance contract).

### 2.1 apt — bounded index refresh, bounded install, fail closed

* One refresh, then one install, both with `Acquire::Retries=3`,
  `Acquire::http/https::Timeout=45`, `DPkg::Lock::Timeout=120`,
  `Acquire::Languages=none` and `DEBIAN_FRONTEND=noninteractive`.
* The refresh is wrapped in a **hard wall-clock cap** (default 240 s, input
  `apt-update-cap-seconds`) executed as root around apt itself. Because
  `setup-php` adds `ppa:ondrej/php` whenever a lane PHP version or extension
  comes from it, this refresh is the step that waits on Launchpad; the cap turns
  the observed multi-minute stalls into a bounded failure with an annotation.
* There is no shell retry loop, and a failed install fails the step with the apt
  tail — nothing is masked.

**A variant that was tried on this branch and reverted, with the evidence:**
skipping the refresh and installing against the indexes the job already had
(`setup-php` refreshed them, the image ships `/var/lib/apt/lists`). On the real
runner that install failed at fetch time in *every* lane —
`E: Failed to fetch mirror+file:/etc/apt/apt-mirrors.txt/pool/main/p/php8.3/libapache2-mod-php8.3…`
— so the refresh ran anyway and the job paid one extra failed attempt
(run 40063885047/40063885049/40063885036 annotations). The hypothesis that the
image's lists are fresh enough is therefore false on `ubuntu-latest`, and the
install-first path was removed again.
* Sources, pins and preferences are untouched, so apt resolves exactly the same
  candidates as before — which matters for the unversioned
  `libapache2-mod-php` / `php-*` metapackages.
* Fail-closed verification: every requested package must report dpkg status
  `install ok installed` afterwards, and which `mod_php` module is enabled is
  recorded as a check annotation.

### 2.2 WP-CLI — pinned release asset, checksum-verified, cache re-verified

* The phar is the immutable versioned GitHub release asset
  `wp-cli-<version>.phar` (pin: 2.12.0), not the mutable
  `gh-pages/phar/wp-cli.phar` alias, and its SHA-256 **and** SHA-512 are pinned
  in the action. The bytes are hashed before `install`, and `wp --version` must
  report the pinned release.
* The phar is cached under an exact key
  (`wp-cli-<os>-<version>-<sha256>`, no `restore-keys`), restored and saved with
  explicit `actions/cache/restore` / `actions/cache/save` steps, and the cached
  file is re-hashed on every run before use. A mismatch discards the entry and
  re-downloads.
* Previously the phar was fetched with `curl -sS` (no `-f`, no checksum) and
  immediately made executable.

### 2.3 Playwright — exact pin, exact-key browser cache, deps asked for not assumed

* Pin: `playwright==1.64.1`, verified after install via
  `python3 -m playwright --version`; the run fails if another version is active.
  1.64.1 is the release that the previously unpinned install resolved to on the
  last green `main` runs (PyPI: 1.64.0 → 2026-10-13, 1.64.1 → 2026-10-22; no
  later release exists), so the pin freezes the browser revisions the suites
  were last proven on instead of drifting per release.
* Cache: `~/.cache/ms-playwright`, exact key
  `pw-browsers-v2-<os>-<arch>-<version>-<browser-set>`, no `restore-keys`, so a
  cache can never cross versions or browser sets (v2 marks the switch to
  explicit restore/save and the launch-verified dependency path). Restore and save are explicit
  steps, and the save runs only on a miss and only after the install/launch
  verification in the same run succeeded.
  Microsoft's CI documentation warns that restoring browsers can cost as much as
  downloading them (`microsoft/playwright` `docs/src/ci.md` § "Caching
  browsers"); that is why the effect is measured (§ 4) rather than assumed.
* System dependencies are **asked for, not assumed**: `playwright
  install-deps --dry-run <browsers>` simulates the apt install for exactly this
  browser set and exits non-zero when any required package would still have to
  be installed. When it exits 0 nothing is installed at all; otherwise only the
  reported package names are installed, with the bounded options above, and the
  answer is re-verified before the suite runs. `install --with-deps` is not used
  anywhere, so the dependency set is never re-installed when it is already
  satisfied and apt never repeats a full index refetch on a happy path.
* Every provisioned engine is launched once (`verify_launch.py`), so a missing
  system library fails at provisioning time with a clear message instead of
  halfway through an acceptance journey.

### 2.4 Third-party baseline

Its archive-first / `ppa:ondrej/php`-fallback PHP provisioning is kept as it was
(it carries lane-specific evidence and annotations), with the same bounded apt
options; its WP-CLI and Chromium work moved to the two shared actions behind the
same `matrix.source == 'wordpress_org'` condition.

## 3. Supply-chain notes

* No new third-party code: only GitHub-owned actions are used
  (`actions/cache/restore@v6`, `actions/cache/save@v6`), consistent with the
  repository's existing major-tag convention (`actions/checkout@v4`,
  `actions/upload-artifact@v4`). SHA-pinning those tags is a separate,
  repository-wide decision and is not changed here.
* No new permissions or token scopes are requested anywhere.
* Browser binaries are executable content, so: the cache key is exact and
  version-derived, GitHub cache entries are immutable per key, caches written
  from a fork PR are not readable by this repository's default branch
  (`actions/cache` README § "Cache scoping"), the save step is skipped for fork
  PRs, `playwright install` re-validates the pinned revisions of a restored
  cache, and every browser is launched before the suite runs.
* WP-CLI is now integrity-checked; it previously was not.

## 4. How this change is measured

1. Cold run — the first run on a head: the cache keys do not exist yet.
2. Warm run — a re-run of the same workflows on the same head, which restores
   what the cold run saved.
3. Comparison — the `Tooling …` and `Playwright …` step durations per job from
   the jobs API, plus the per-phase numbers the actions emit as check
   annotations (`apt phase`, `WP-CLI`, `playwright browsers` with
   `cache_hit`/`deps`/`seconds`). Raw job logs are not retrievable from the
   review sandbox (`*.blob.core.windows.net`); annotations are.

Numbers are reported as measured, including any lane where the change is
neutral or slower.

### 4.1 Measured result (three full matrices on the delivered code)

Three complete matrices of all eight workflows were measured; between them the
action code is identical (the last head differs only by a no-op commit):

| head | role | runs | cache |
| --- | --- | --- | --- |
| `2f675a2` (main) | baseline | 7 push runs, all `success` | no browser cache at all |
| `e9642e1` | cold | 8/8 `success` | 11 of 13 browser lanes miss, 2 hit |
| `f2cc05e` | warm | 8/8 `success` | 13 of 13 browser lanes hit |

Per-job *provisioning* time = `setup-php` + every `Tooling …` step + every
`Playwright …` step, over the 27 lanes present in all three matrices:

| metric | main `2f675a2` | cold `e9642e1` | warm `f2cc05e` |
| --- | --- | --- | --- |
| sum, all 27 lanes | 2509 s | 1240 s | 1117 s |
| sum, excluding the one 1402 s lane | 1107 s | 1136 s (+29 s, +2.6%) | 1040 s (−67 s, −6.1%) |
| median lane | 8 s | 12 s | 10 s |
| mean lane | 92.9 s | 45.9 s | 41.4 s |
| worst lane | 1402 s | 127 s | 131 s |
| lanes faster / neutral (±2 s) / slower | — | 4 / 14 / 9 | 8 / 14 / 5 |

What that means, stated without rounding it up:

* **The tail is what changed, not the median.** The worst provisioning lane on
  main was 1402 s; on the delivered code the worst lane is 127 s (cold) and
  131 s (warm). The single-lane saving of 1298–1325 s *is* that outlier, so it
  is reported as a bound, not as a speed-up: the apt index refresh is still
  executed every run, it is now capped by `apt-update-cap-seconds` (240 s, run
  as root via `sudo timeout`) instead of being allowed to sit on a stalled
  Launchpad mirror. Measured `apt phase` annotations: cold 4–82 s (n=20, median
  61 s), warm 5–90 s (n=20, median 62 s), against 68 / 264 / 970 / 1041 / 1396 s
  samples on main and one 24m36s run that was cancelled at the job timeout.
* **A warm browser cache is worth ~15–25 s per chromium lane.** `Playwright …`
  step: 26–35 s cold, 8–18 s warm (the warm step still pays pip install, the
  cache restore, `playwright install` build-revision validation and a real
  launch). The `playwright browsers` annotation reports `cache_hit=true
  deps=reused seconds=2` on those lanes.
* **The three-browser lane does not get the same saving, and that is expected.**
  `Responsive smoke` hits the cache warm (`browsers=chromium firefox webkit
  cache_hit=true`) but the cached binaries do not launch on a fresh runner
  because the firefox/webkit OS packages are not part of the cache; the
  fail-closed fallback installs exactly Playwright's reported package list
  (`deps=installed (bounded, after a launch failure on cached binaries)`,
  48–58 s) and re-verifies the launch. Net: 54 s cold / 58 s warm against one
  113 s combined step on main — i.e. slightly slower than the single
  `install --with-deps` step it replaced, because that step is now bounded apt
  for an exact list plus an explicit launch proof. This is the price of the
  dependency gate and it is not hidden.
* **Lanes that are slower** (provisioning delta vs main): cold — First-practice
  browser (mariadb) +38 s, Closure PHP 8.1 +12 s, Responsive smoke +11 s,
  Closure PHP 8.3 +5 s, two coexistence lanes +5 s, security-authentication
  +4 s; warm — First-practice browser (mariadb) +18 s, Staging Gate +16 s,
  Responsive smoke +15 s, Real WP (prefix `clinic_`) +15 s, Upgrade path +4 s.
  Causes that are actually visible in the data: the cold closure lanes pay one
  WP-CLI download-and-verify (16 s) where main downloaded through a mutable
  `gh-pages` alias inside a 5 s step — warm they are +0…+2 s because the phar
  comes from cache (`source=cache seconds=0`); the three-browser lane is
  explained above; and the rest track run-to-run variance of the bounded apt
  phase for the *same* package list (e.g. Staging Gate: `apt phase` 59 s cold /
  74 s warm for the 11 packages main installed inside a 60–63 s step). No
  slower lane is caused by a missing cache key, a retry loop or an extra
  download that the cache could have avoided.

## 5. Observed but deliberately not changed

The three lanes that declare `PHP_VERSION: '8.2'` install unversioned
`libapache2-mod-php` / `php-*` metapackages, which resolve to the distro PHP
(8.3 on ubuntu-24.04) while the CLI runs 8.2. The apt phase now records the
enabled module as an annotation (`mod_php_enabled=php8.3.load`), so this is
visible evidence rather than an assumption. Changing it would change the
environment the acceptance suites are measured on, so it is reported for a
separate, witness-run change rather than folded into a performance PR.
