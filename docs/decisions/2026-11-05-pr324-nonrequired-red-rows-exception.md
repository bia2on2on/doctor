# Policy + evidence record — PR #324 non-required red rows (technical manager decision)

> **Document type:** acceptance-policy record for ONE pull request (#324), written
> from the technical manager's decision; not an Owner product decision. It changes
> no pin, no workflow, no lock, no baseline, no test, no audit and no assertion —
> it records which non-required red rows were examined and under what conditions a
> merge may be considered.
> **Recorded:** 2026-11-05.

## 1. Header facts (verified, exact)

- `main`: `5e0d0cc63f3b8f7d38849002cfe70299dea35760` (unmoved during this record).
- PR #324 was **OPEN / DRAFT**; head **at the time of writing**:
  `37d3294195d12696f3a78713fc7979818595863b` (this record's own commit advances
  the head; everything below is measured at `37d3294…`).
- Ruleset "Protect main" (id 22393801, active) requires exactly 9 contexts —
  all **green** at `37d3294…` (settled check-run census 2026-11-05T12:50Z):
  Unit Tests (PHP 8.1), Unit Tests (PHP 8.2), Unit Tests (PHP 8.3),
  Unit Tests (PHP 8.4), Integration (WP 7.1 + MySQL 8),
  Closure — destructive restoreApply (isolated),
  Closure — PHP 8.1 runtime (WP 7.1.3), Closure — PHP 8.3 runtime (WP 7.1.3),
  Closure — PHP 8.4 runtime (WP 7.1.3).
- Full census at `37d3294…`: 45 checks, all terminal — **39 success /
  6 failure / 0 skipped / 0 pending.**

## 2. The six non-required red rows at `37d3294…` (names + verbatim annotations)

Four pinned/current-main build rows, identical annotations on every cycle since
the advisory window (checked again 2026-11-05T12:46Z):

- `Real HTTP profile (MySQL 8, same runner)` (classic-v1 pinned archive):

  > HTTP comparison unavailable [Errno 2] No such file or directory: '/tmp/classic-http/baseline.json'

- `Facility matrix (mysql)`:

  > Could not build the pinned schema-0030 ZIP

- `Facility matrix (mariadb)`:

  > Could not build the pinned schema-0030 ZIP

- `Real WP Acceptance (prefix wp_)` (builds **current main** live):

  > Current-main baseline release ZIP build failed; size delta is unavailable.

(Each of the four above is also preceded by the generic "Process completed with
exit code 1." annotation.)

Two rows that turned red **first on the `37d3294…` cycle**:

- `Responsive smoke (Chromium ×4 viewport)`:

  > apt-get install still failed or exceeded its 240s wall-clock cap after one
  > index refresh; no further retry will be attempted.

- `coexistence-combined-ten / Real WP Acceptance (prefix wp_)`:

  > coexistence verdict | group=combined-ten | Stage A (CPMS absent)=FAIL |
  > Stage B (CPMS active)=NOT RUN | first_causal_check=package_retrieved::advanced-custom-fields

  > Stage A blocked; Stage B NOT RUN. See A/result.json and raw logs.

## 3. Cause statements — evidence vs hypothesis (nothing is overstated)

**Four pinned/main rows:** the symfony/filesystem explanation (the three built
trees lock v6.4.45; GHSA-6prc-h956-hxjq / FriendsOfPHP PKSA-g499-cm4q-v35y covers
`>=6.4.0,<6.4.50`; the candidate locking v6.4.50 builds green through the
identical `build-release.sh` chain) is an **UNPROVEN HYPOTHESIS**. Known
counter-evidence and limits:

- The literal failing command and its output were **never directly observed**:
  the Facility steps redirect the build to `/tmp/mtx-0030-build.raw` and neither
  echo nor upload it; the classic-v1 and wp_ steps print (full stream / tail -60)
  into job logs whose blob host is unreachable from the working sandbox;
  artifact download and job-rerun both answer HTTP 403 / 302-blocked there.
- Runs on the **same v6.4.45 lock were green after** the advisory's reportedAt
  (2026-11-04T00:00Z): the PR's all-green census at ~2026-11-04T17:17Z proves the
  advisory had not yet propagated to composer-audit databases then — consistent
  with DB sync lag, and equally consistent with an unrelated cause appearing
  between those runs.
- Alternatives are **not excluded**: runner-image drift, and any archive-content
  input outside the compared surface. (The object-level comparison covered the
  14 locked package versions plus workflow/script sources, not every byte of the
  three trees.)

**Two new rows:** the harness annotations name environment causes (apt wall-clock
cap; third-party package retrieval of `advanced-custom-fields`). Those strings are
the harness's own coded causal placeholders, observed verbatim — but that
classification is **not proven** either (no logs were reachable to confirm the
intermediate steps), and **no rerun was possible or authorized** in the recording
session. Neither row can be caused by the docs/comment-only diff of `37d3294…`;
both were green on the immediately preceding head `7a4764d…`.

## 4. Acceptance policy (technical manager's decision — recorded substance)

Merge of PR #324 is allowed only if ALL of the following hold:

1. All 9 required contexts above are green on the **final head to be merged**.
2. The red rows are **exactly** the four pinned/current-main rows of §2 with
   **identical annotations**. Any additional red row — including the two §2 rows
   still being red — is **NOT pre-authorized** by this record and requires a
   separate explicit decision before merge.
3. Failures are never recorded, relabeled, or summarized as success.
4. This record authorizes **NO** audit skip, **no** advisory allowlist entry,
   **no** dependency overlay, and **no** baseline/pin change. None of those may
   cite this record as permission.
5. **After** any merge, the checks that run on the **actual merge commit SHA**
   (consult `merged=true` + `merge_commit_sha`, not the merge-preview SHA) must be
   fetched and reported, and the four §2 rows examined individually on that SHA.

## 5. Explicit non-generalizations

- This record does **not** extend or generalize the 2026-10-12
  persian-woocommerce 10.0.5 diagnostic-only exception to any baseline, lane, or
  subject; that exception remains bounded to its own record.
- The historical-baseline question (re-pin, or test-only locked-dependency
  overlay, or main-first lock bump) is **deferred to a separate, dedicated PR**
  and is untouched here in every respect.
