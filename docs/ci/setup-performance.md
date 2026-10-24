# CI setup performance — Playwright/WP-CLI/apt provisioning

**خلاصه (FA).** این تغییر فقط لایهٔ *آماده‌سازی* (provisioning) در workflowهای CI را
بهینه می‌کند: یک composite action مشترک، نصب apt با تایم‌اوت/تعداد تلاش محدود و
بدون بازخوانیِ تکراریِ indexها، Playwright با نسخهٔ دقیق pin‌شده و کشِ مرورگر با کلید
دقیق، و WP-CLI با اعتبارسنجی sha512 پیش از اجرا. هیچ سناریو، ماتریس، نام job/check،
تریگر یا assertion حذف یا تضعیف نشده است.

This document records the design of `.github/actions/cpms-tooling` and the
evidence behind each decision. It is written so that a later auditor can check
the claims against the API data instead of trusting prose.

## 1. Measured baseline (before this change)

Source: GitHub Actions API, `main` push run set at commit
`2f675a230563425be3913c8cc9fa9fd34fa0bf4f` (runs 40021999529, 40021999681,
40021999728, 40021999744, 40021999763, 40021999800, 40021999807 — all
`success`). Durations are `started_at`→`completed_at` of the setup steps as
reported by `GET /repos/{owner}/{repo}/actions/runs/{id}/jobs`.

| lane (job) | setup step | step time |
|---|---|---|
| Baseline elementor (TPB) | Tooling (apache2 + mod_php pinned … wp-cli + chromium) | 1041 s |
| Baseline persian-elementor (TPB) | same | 970 s |
| Baseline wp-crontrol (TPB) | same | 264 s |
| coexistence-persian-woocommerce-diagnosis | Tooling (apache + wp-cli + playwright/chromium) | 1396 s |
| all other TPB legs (18 jobs) | same | 68–104 s |
| Responsive smoke (Chromium ×4 viewport) | Tooling (wp-cli + apache + playwright) | 113 s |
| Real WP Acceptance (prefix wp_ / clinic_) | Tooling (apache + wp-cli + playwright/chromium) | 112 s / 92 s |
| First-practice browser (mysql / mariadb) | Tooling (apache + wp-cli + playwright/chromium) | 105 s / 71 s |
| Facility matrix (mysql / mariadb) | Tooling (apache + wp-cli + playwright/chromium) | ~95 s each |
| Staging Gate | Tooling (wp-cli, mysql-client, apache, ab, bc) | 60 s |
| Closure ×4 runtime | Tooling (mysql-client + wp-cli) | 5 s each |

38 setup steps, median 85 s, mean 163 s, sum 6204 s, max 1396 s.

The spread on *identically configured* legs (68 s → 1041 s → 1396 s) is the
signal: the work is the same, so the difference is network time inside apt. The
same shape appears in the historical record: `pilot-gate` run 39991158296 had its
Responsive job cancelled because the Tooling step alone consumed 24 m 36 s of a
25 m job budget (`docs/agent-log.md`, 2026-10-23 entry).

### Why apt was the variable

`shivammathur/setup-php` adds `ppa:ondrej/php` whenever it needs a PHP version
or an extension that the runner image's archive does not provide
(`src/scripts/linux.sh`: `add_extension_helper` and `add_packaged_php` both call
`add_ppa ondrej/php` before `check_package`). Every setup step in this repository
then ran `sudo apt-get update -qq` *again*, which refetches **every** configured
index — including Launchpad. A slow or 503-ing Launchpad therefore turns a
45-second provisioning step into a multi-minute one, without any change in what
the job installs.

## 2. What changed

One shared composite action, `.github/actions/cpms-tooling/action.yml`, replaces
the duplicated `apt-get update` + `apt-get install` + `curl wp-cli` +
`pip install playwright` + `playwright install --with-deps` plumbing in eleven
call sites across eight workflows. Step names, job names, matrices, triggers and
assertions are unchanged.

### 2.1 apt: install against existing indexes, refresh once on failure

* `apt-get install -y <same packages>` with `Acquire::Retries=3`,
  `Acquire::http::Timeout=30`, `Acquire::https::Timeout=30`,
  `DPkg::Lock::Timeout=120`.
* No `apt-get update` on the happy path: the job's `setup-php` step has already
  refreshed indexes in the same job, and the runner image ships populated
  `/var/lib/apt/lists`.
* If the install fails (stale index, superseded package version, transient
  mirror error), the action runs **one** bounded `apt-get update` and **one**
  retry, then fails with the apt tail. A permanent failure is never masked.
* Package lists, source lines, pins and preferences are untouched, so apt
  resolves exactly the same candidates as before (this matters for the
  `libapache2-mod-php` / `php-mysql` metapackages that the PPA re-points).

### 2.2 Playwright: exact pin + exact-key cache + dependency guard

* Pin: `playwright==1.64.1`, verified after install with
  `python3 -m playwright --version`; the run fails if a different version is
  active. 1.64.1 is the release that the last green `main` runs installed
  (PyPI upload times: 1.64.0 → 2026-10-13, 1.64.1 → 2026-10-22; no later
  release exists, so a 2026-10-23 run of `pip install playwright` could only
  have installed 1.64.1). Pinning therefore preserves the browser revisions the
  suites were last proven on instead of drifting with each release.
* Cache: `~/.cache/ms-playwright` with the exact key
  `playwright-<version>-<browsers>-<os>-<arch>-v1` and no `restore-keys`, so a
  cache can never cross Playwright versions. Microsoft's own CI documentation
  warns that restoring browsers can cost as much as downloading them
  (`microsoft/playwright`, `docs/src/ci.md` § "Caching browsers"), which is why
  this change is measured in § 4 rather than assumed to be a win.
* Dependency guard: `playwright install-deps --dry-run <browsers>` is the
  Playwright-native question "which required OS packages are missing?" — it
  simulates `apt-get install -s` for exactly that browser set and exits non-zero
  when anything would still have to be installed. When it exits 0 the action
  skips apt entirely; otherwise it installs the reported package names with the
  bounded options above, then re-runs the check and fails if anything is still
  missing. `--with-deps` is consequently never repeated on a correctly
  provisioned runner.
* Browsers per suite are preserved exactly: chromium for facility matrix,
  first practice, real WP acceptance and the third-party baseline; chromium +
  firefox + webkit for the Responsive job, whose "Firefox/WebKit compatibility
  smoke" step is part of its acceptance contract.
* A launch check starts every provisioned engine once, so a missing system
  library fails at provisioning time with a clear message instead of halfway
  through an acceptance journey.

### 2.3 WP-CLI: integrity-validated before it becomes executable

The phar is fetched together with the publisher's `wp-cli.phar.sha512`, the
downloaded bytes are hashed and compared, and only a matching phar is installed
to `/usr/local/bin/wp`. A missing or malformed digest, a download failure or a
mismatch fails the step; nothing unverified is ever made executable.

WP-CLI is deliberately **not** cached between runs: the digest is only known at
run time, so a cross-run cache key would have to be static (and would go stale
on every WP-CLI release) while the object itself is ~7 MB from
`raw.githubusercontent.com`. Verification was worth adding; a cache was not
justified.

### 2.4 Third-party baseline

Its archive-first / `ppa:ondrej/php`-fallback PHP provisioning is left
byte-for-byte intact (it carries lane-specific evidence and annotations); only
its apt calls gain the bounded timeout options, and its WP-CLI + chromium tail
moves to the shared action in a separate step with the same `if:` condition.

## 3. Supply-chain notes

* New third-party code: none. Only GitHub-owned actions are added
  (`actions/cache/restore@v4`, `actions/cache/save@v4`), consistent with the
  repository's existing major-tag convention (`actions/checkout@v4`,
  `actions/upload-artifact@v4`).
* No new permissions and no new token scopes are requested anywhere.
* The browser cache is executable content, so: the key is exact and
  version-derived (a poisoned entry cannot survive a Playwright upgrade),
  GitHub caches are immutable per key, caches written from a fork PR are not
  readable by this repository's default branch (`actions/cache` README §
  "Cache scoping"), the save step is skipped for fork PRs, and Playwright
  re-validates its installation markers on every `playwright install`, so a
  partial or corrupt restore is re-downloaded rather than used.
* WP-CLI is now integrity-checked, which it was not before.

## 4. How this change is measured

1. Cold run: the first run on the PR head. The cache keys do not exist yet.
2. Warm run: a re-run of the same workflows on the same head, which restores the
   entries the cold run saved.
3. Comparison: the `Tooling …` step durations per job from the jobs API, plus
   the per-phase timings the action emits as a check annotation
   (`CPMS tooling timings`: `apt=`, `wp-cli=`, `pip=`, `playwright-browsers=`,
   `playwright-deps=`, `playwright-launch=`). Raw job logs are not retrievable
   from the review sandbox (`*.blob.core.windows.net`), annotations are.

Numbers are reported as measured, including any lane where the change is
neutral or slower.
