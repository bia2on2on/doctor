# CPMS Classic — Test Artifact Distribution Report (2026-10-24)

**Role:** CPMS Classic Test Artifact Distribution Engineer
**Mission:** Prepare the exact CI-built installable ZIP for independent
external WordPress acceptance, **without** publishing an official
product release.
**Report time (UTC):** 2026-10-24T15:24:53Z (final live re-check)
**Branch:** `arena/3249c895-doctor` (session branch; no other branch used)
**Governing rules read:** `AGENTS.md` (all eight Owner engineering
principles), `docs/agent-guide.md` (start section),
`docs/governance/engineering-tooling.md` (publication limits),
`docs/adr/ADR-0029-secure-updates.md` (official distribution channel),
`.github/workflows/pilot-gate.yml` (`release-zip` job).

> **STOPPED PER MISSION §11.** Public hosting requires explicit Owner
> authorization that was not given in this mission. No GitHub Release,
> no tag and no other publication was created. The exact minimal
> authorization required is in §7 of this report.
>
> **Installer acceptance is NOT declared completed.** No WordPress
> install/acceptance was run in this task.

---

## 1. Verdict summary — PASS / FAIL / NOT RUN

| # | Mission step | Verdict | Basis (§) |
|---|---|---|---|
| 1 | Verify live `main` and artifact provenance | **PASS** (metadata-level, live GitHub API) | §2 |
| 2 | Download the exact Actions artifact | **FAIL — blocked in this sandbox** (3 attempts; artifact-bytes host unreachable) | §3 |
| 3 | Verify outer artifact SHA-256 **on downloaded bytes** | **NOT RUN** (no bytes downloaded). Separate metadata comparison of GitHub API `digest` vs mission value: **match** | §3 |
| 4 | Extract inner `clinic-practice-management-1.0.0.zip` | **NOT RUN** (depends on §2) | §3 |
| 5 | Verify inner checksum vs `…zip.sha256` | **NOT RUN** (depends on §2) | §3 |
| 6 | Record inner ZIP exact SHA-256 and byte size | **NOT RUN / unknown** — must not be guessed. Outer artifact size from API is recorded separately and is **not** the inner size | §2, §5 |
| 7 | Identify an approved, public, unauthenticated HTTPS channel | **FAIL — none available within current authorization** (search performed) | §4 |
| 8 | Create no Release/tag/publication without authorization | **PASS** (none created; none pre-existed) | §4 |
| 9 | Do not modify/rebuild/substitute the ZIP | **PASS** (`bin/build-release.sh` read only, never executed; no `dist/` created; no substitute artifact used) | §3 |
| 10 | Do not access Owner production hosting | **PASS** (not accessed; no credentials used or requested) | §4 |
| 11 | If authorization is required, stop and report minimal action | **PASS** (stopped; §7) | §7 |
| 12 | Provide URL / inner SHA / commit / size / HTTP evidence / verdict | **Partial** — commit provided; URL **not available**; inner SHA/size **NOT RUN**; HTTP evidence for the endpoints actually tested provided | §5, §6 |

**Overall mission outcome: FAIL / BLOCKED — NOT COMPLETE.**
The exact CI ZIP has **not** been made publicly downloadable in this
task, and its inner bytes have **not** been independently verified
here. Provenance at the GitHub-API metadata level is confirmed (§2).

---

## 2. Evidence — live `main` and artifact provenance (Step 1: PASS)

All values below were re-read live from GitHub in this session
(`git ls-remote`, `gh api …`), not copied from prior reports.

| Field | Live value observed | Mission expected | Match |
|---|---|---|---|
| Live `origin/main` (`git ls-remote origin refs/heads/main`, re-checked 2026-10-24T15:24:53Z) | `aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5` | `aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5` | ✅ |
| Local HEAD at start | `aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5` — “Merge pull request #264 …” | same | ✅ |
| Workflow run | `40100552895`, “Pilot/Staging Readiness Gate”, `.github/workflows/pilot-gate.yml`, run #2102, attempt 1 | `40100552895` | ✅ |
| Run `head_branch` / `head_sha` / `head_commit.id` | `main` / `aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5` / same | `main` @ `aa6f3b5…` | ✅ |
| Run `event` / `status` / `conclusion` | `push` / `completed` / `success` (2026-10-24T14:00:13Z → 14:19:21Z) | — | recorded |
| Run URL | https://github.com/bia2on2on/doctor/actions/runs/40100552895 | — | recorded |
| Artifact | id `12562254666`, name `release-zip`, `expired: false` | `12562254666` | ✅ |
| Artifact `workflow_run` | run `40100552895`, `head_branch: main`, `head_sha: aa6f3b5…` | same run/commit | ✅ |
| Artifact `size_in_bytes` (**outer** Actions artifact, API metadata) | `8713746` bytes | — | recorded; **not** the inner ZIP size |
| Artifact `digest` (API metadata) | `sha256:0f99f0d755ff42f97bf319fc600b19a5adb07660baa3b15c6b3040b6e3d17518` | `0f99f0d755ff42f97bf319fc600b19a5adb07660baa3b15c6b3040b6e3d17518` | ✅ metadata match (see caveat in §3) |
| Artifact `created_at` / `expires_at` | `2026-10-24T14:02:54Z` / **`2026-11-07T14:02:53Z`** | — | ⚠ time-box, §7 |
| Producing job | `120891648190` “Release Artifact (build + policy)” — `completed/success`, all 9 steps `success` (checkout → setup-php → Build release ZIP → Runtime SDK contract → Policy scan → `upload-artifact` `release-zip` from `clinic-practice-management/dist/`, retention 14 days) | — | recorded from `pilot-gate.yml` at `aa6f3b5…` + Jobs API |
| Repository visibility | `private: false`, `visibility: public` | — | recorded |

The run contained 4 artifacts; only `release-zip` (`12562254666`)
was in scope. The other three (`responsive-screenshots`,
`pilot-benchmark-40100552895`, `gate-logs`) were listed for
provenance completeness and were **not** used or substituted.

**Evidence vs inference:** the table above is direct GitHub API /
`ls-remote` evidence from this session. That the outer artifact
*should* contain `clinic-practice-management-1.0.0.zip`,
`…zip.sha256` and `…-manifest.txt` is an **inference** from
`bin/build-release.sh` + the `upload-artifact` path read at this
commit — the contents were **not observed**, because extraction was
NOT RUN (§3).

---

## 3. Evidence — download, outer hash, extraction (Steps 2–6)

This sandbox has working GitHub credentials for API metadata
(`gh auth status`: logged in as `bia2on2on` via `GH_TOKEN`), but its
outbound network is restricted to an allowlist (`github.com`,
`api.github.com`, `codeload.github.com`, …). GitHub serves artifact
bytes and job logs **only** via a short-lived signed redirect to
`productionresultssa18.blob.core.windows.net`, which is **not** on
that allowlist. This was proven, not assumed:

| Attempt | Command (exact artifact only) | Result |
|---|---|---|
| 1 | `gh api repos/bia2on2on/doctor/actions/artifacts/12562254666/zip > outer-….zip` | exit `1`, output file `0` bytes; error `Get "https://productionresultssa18.blob.core.windows.net/<redacted>": EOF` |
| 2 | `gh run download 40100552895 -n release-zip -D … --repo bia2on2on/doctor` | exit `1`, same blob-host `EOF`; nothing downloaded |
| 3 (retry, excludes transient failure) | `gh api …/artifacts/12562254666/zip` again at 2026-10-24T15:24:46Z | exit `1`, `0` bytes, same blob-host `EOF` |
| Control A | `curl https://api.github.com/repos/bia2on2on/doctor/actions/artifacts/12562254666` | exit `0`, 755-byte JSON — `api.github.com` reachable |
| Control B | `curl -I https://productionresultssa18.blob.core.windows.net/` (no pipe, true exit code) | exit `35` — `OpenSSL SSL_connect: SSL_ERROR_SYSCALL` — blob host unreachable from this sandbox |
| Corroboration | `gh api …/actions/jobs/120891648190/logs` (job logs, same hosting) | exit `1`, same blob-host `EOF`; logs therefore also **NOT RUN** as a source for the inner hash |

Signed blob URLs (path + `sig`/`se` query) are credential-like and
expire within minutes; they are **redacted** in this report and were
not recorded anywhere in the repository.

Consequences, stated without overclaim:

- **Step 2 (download): FAIL in this environment.** This sandbox is
  therefore *not* “an authorized environment with the necessary
  … network access” for artifact bytes, even though its GitHub
  credentials work for metadata. An environment that can reach
  `*.blob.core.windows.net` is required (§7, §8).
- **Step 3 (outer SHA-256 on bytes): NOT RUN.** No `sha256sum` of a
  downloaded outer ZIP exists. The only outer-hash evidence is the
  GitHub API `digest` field matching the mission’s expected value
  (§2) — metadata corroboration, **not** byte-level verification.
  The `0`-byte files produced by the failed downloads are explicitly
  **not** evidence and were not hashed or presented as the artifact.
- **Steps 4–6 (extract inner ZIP; `sha256sum -c …zip.sha256`;
  inner SHA-256 + inner byte size): NOT RUN.** The inner ZIP’s exact
  SHA-256 and byte size are **unknown** from this session and are
  deliberately left blank in §5 rather than inferred from the outer
  size, CI prose, or a rebuild.
- **No rebuild / no substitution:** `bin/build-release.sh` was read
  for provenance only and never executed; `clinic-practice-management/dist/`
  does not exist in this checkout (it is gitignored CI output);
  `git status` was clean before this report was written; no artifact
  from any other run was downloaded or considered.

---

## 4. Evidence — distribution-channel search (Steps 7, 8, 10)

Repository + live-GitHub state actually checked in this session:
no GitHub Releases exist (`gh api …/releases` → empty), no tags
exist, GitHub Pages is not enabled (`…/pages` → `404 Not Found`),
repository is public. A text search of `docs/`, `README.md`,
`AGENTS.md` and all workflows found exactly one “official
distribution channel”: ADR-0029’s vendor server over HTTPS
(out-of-repo; runbook/mock only in-repo). Each candidate channel:

| Candidate | Public + unauthenticated + stable? | Approved / allowed now? | Verdict |
|---|---|---|---|
| Actions artifact URL itself (run/artifact web or API `…/zip`) | **No** — resolves only via authenticated request → minutes-lived signed blob URL; artifact itself expires 2026-11-07. A truly unauthenticated test was **NOT RUN**: this sandbox’s egress injects GitHub auth (an “unauthenticated” `curl` to `api.github.com` returned a `github-authentication-token-expiration` response header), so no honest unauthenticated claim can be made from here | It is the *source*, not an approved public channel | **Rejected** |
| GitHub Release asset (pre-release) | Yes, technically (public repo ⇒ public asset HTTPS URL) | **No** — creating a Release/tag is explicitly forbidden without explicit authorization (mission §8; `docs/governance/engineering-tooling.md`: no tool may independently “Create tags, releases, or versions”), and the mission is “without publishing an official product release” | **Blocked on Owner authorization** (§7-A) |
| Vendor server (ADR-0029 official channel) | Unknown from repo (out-of-repo; “end-to-end distribution = BLOCKED_BY_ENVIRONMENT until vendor infra exists”) | **No** — mission §10 forbids accessing Owner production hosting; no URL/credentials were provided | **Blocked on Owner provisioning** (§7-B) |
| GitHub Pages | Would be | Not enabled (`404`); enabling it is a settings change + publication requiring authorization, and would need the ZIP committed to a served branch | **Rejected** |
| ZIP committed to a git branch + raw-file URL | Technically downloadable (public repo) | **Not approved** anywhere in repo docs; de-facto publication of an 8.7 MB binary into git history; also impossible here (no bytes, §3) | **Rejected — not done** |
| Third-party file hosting / personal cloud (e.g. Dropbox/Drive connectors) | Potentially | **No approval** to place a product test artifact on a third-party/personal account; connectors are off for this conversation | **Rejected — not used** |
| This sandbox’s ephemeral preview URL | Session-bound only | Not an approved or stable distribution channel; no bytes to serve in any case | **Rejected** |

**Conclusion (honest): no approved, publicly downloadable,
unauthenticated HTTPS distribution channel is available within the
authorization given.** Per mission §11 the correct action is to
stop here — which this report does.

---

## 5. Required deliverables (Step 12)

| Deliverable | Value |
|---|---|
| Public direct-download URL | **NOT AVAILABLE** — none created (§4); do not use the authenticated Actions URL as if it were one |
| Exact inner ZIP SHA-256 (`clinic-practice-management-1.0.0.zip`) | **NOT RUN — unknown** (§3). Any value stated here without the bytes would be fabrication |
| Source commit SHA | **`aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5`** (live `main`, run head and artifact `head_sha` all agree, §2) |
| Inner ZIP file size | **NOT RUN — unknown**. For provenance only: **outer** Actions artifact `size_in_bytes` (API) = `8713746` bytes — this is the outer container, *not* the inner ZIP, and must not be quoted as the inner size |
| HTTP accessibility evidence | §6 (endpoints actually tested, from this sandbox) |
| PASS / FAIL / NOT RUN | §1 — overall **FAIL / BLOCKED, NOT COMPLETE**; provenance **PASS**; byte-level steps **NOT RUN** |
| Installer acceptance | **NOT RUN and NOT declared completed** |

---

## 6. HTTP accessibility evidence (what was actually tested)

From this sandbox on 2026-10-24 (UTC), with true exit codes:

| URL | Method | Observed | Meaning / limits |
|---|---|---|---|
| `https://api.github.com/repos/bia2on2on/doctor/actions/artifacts/12562254666` | `curl` GET | exit `0`, JSON metadata (755 B via `curl`; same via `gh api`) | Metadata reachable **with sandbox-injected GitHub auth**; not an unauthenticated-access proof |
| `https://api.github.com/repos/bia2on2on/doctor` | `curl -I` (no explicit token) | `HTTP/2 200`, incl. `github-authentication-token-expiration` header | Proves the sandbox **cannot** perform a trustworthy unauthenticated test against GitHub — auth is injected upstream |
| `https://github.com/bia2on2on/doctor/actions/runs/40100552895/artifacts/12562254666` | `curl -I` | `HTTP/2 307` redirect (authenticated context) toward signed artifact storage | Confirms the web artifact link is a redirect into signed storage, **not** a stable public file URL; response seen only with injected auth |
| `https://productionresultssa18.blob.core.windows.net/` | `curl -I` | exit `35`, `SSL_ERROR_SYSCALL` | Artifact-bytes host unreachable from this sandbox (§3) |
| Public direct-download URL for the inner ZIP | — | **NOT RUN** | No such URL exists yet; nothing to probe. It must be probed (status, `Content-Type`, `Content-Length`, full-body hash) only after an authorized upload |

**Inference, labelled as such:** because the repository is public,
a *future authorized* GitHub (pre-)release asset URL or vendor-server
URL would be expected to be fetchable without authentication — but
that expectation is **not evidence** and is not claimed as tested.

---

## 7. Exact minimal action required from the Owner (Step 11)

Nothing further can be done in this session without one explicit
authorization below. **Time-box:** the exact artifact expires
**2026-11-07T14:02:53Z** (14-day retention). After that instant this
mission cannot be completed as written — a re-run would be a
*different* run/artifact and, per mission §9, must not be substituted
without a new mission with new provenance values.

**Option A — GitHub pre-release as the test channel (smallest change).**
Owner replies with explicit authorization equivalent to:

> “I authorize creating ONE GitHub **Pre-release** (not marked
> Latest, not an official product release) with a new tag on
> `aa6f3b527b2b5c11f9d9fc33ae5b2e6d539373f5` (suggested tag:
> `cpms-classic-test-1.0.0-aa6f3b5`), titled for external WordPress
> acceptance testing, containing ONLY the exact inner
> `clinic-practice-management-1.0.0.zip` and its
> `clinic-practice-management-1.0.0.zip.sha256` from run
> `40100552895`, artifact `12562254666`, after the §8 checksum chain
> passes — and no other file, tag or release.”

 plus one of: **A1** the upload is performed by the Owner/operator in
 an environment that can reach `*.blob.core.windows.net` following
 §8 verbatim; or **A2** a future agent session is provided such an
 environment. (This sandbox cannot perform the download half of A,
 §3 — authorizing A does not change that network fact.)

**Option B — Vendor/Owner HTTPS hosting (ADR-0029 channel).**
Owner provides the approved upload location + access method and
explicit authorization to upload the same two exact files there,
with the transfer performed by an operator/environment that can
download the artifact (§3). This agent must not access production
hosting credentials beyond what is explicitly provisioned for this
single upload.

**Option C — Owner-performed transfer, agent re-verification.**
Owner downloads `release-zip` from the run’s Actions page while
logged in (before expiry), runs §8, uploads the verified inner ZIP
to a channel of the Owner’s choice, and returns the public URL +
the §8 outputs. The agent can then (host permitting) re-verify HTTP
accessibility — and, only if the bytes themselves are reachable
from the verifying environment, the published file’s hash — and
close the remaining NOT RUN items or report any mismatch as FAIL.

Explicitly **not** requested and **not** to be inferred from this
report: permission to rebuild the ZIP, use another run’s artifact,
mark anything as an official/stable release, touch production
hosting beyond a provisioned Option-B upload, or declare installer
acceptance complete.

---

## 8. Runbook for the authorized environment (NOT executed here)

For whoever performs the transfer in an environment that can reach
GitHub artifact storage. Every step is fail-closed; stop on any
mismatch and report it — do not “fix” the ZIP.

```bash
# 0) Provenance re-check (must still be: main/run/artifact @ aa6f3b5…)
git ls-remote https://github.com/bia2on2on/doctor.git refs/heads/main
gh api repos/bia2on2on/doctor/actions/runs/40100552895 \
  --jq '{head_branch,head_sha,status,conclusion}'
gh api repos/bia2on2on/doctor/actions/artifacts/12562254666 \
  --jq '{id,name,size_in_bytes,expired,digest,expires_at,workflow_run}'

# 1) Download the RAW outer artifact (do not let tooling pre-extract it)
mkdir cpms-40100552895 && cd cpms-40100552895
gh api repos/bia2on2on/doctor/actions/artifacts/12562254666/zip \
  > release-zip-12562254666.zip

# 2) Outer byte-level verification — must print exactly:
#    0f99f0d755ff42f97bf319fc600b19a5adb07660baa3b15c6b3040b6e3d17518
sha256sum release-zip-12562254666.zip

# 3) Extract the outer container ONLY (never modify the inner ZIP)
mkdir outer && unzip -q release-zip-12562254666.zip -d outer
ls -la outer   # expect the inner .zip, its .zip.sha256, manifest

# 4) Inner verification chain (all three must agree)
cd outer
sha256sum -c clinic-practice-management-1.0.0.zip.sha256   # must say OK
sha256sum clinic-practice-management-1.0.0.zip            # record exact SHA-256
wc -c     clinic-practice-management-1.0.0.zip            # record exact bytes

# 5) Upload ONLY those two verified files via the Owner-authorized
#    channel (§7). Then, from a TRULY unauthenticated network:
#    curl -sS -I  <public-url>            # expect 200 + length == (4)
#    curl -sS -L  <public-url> -o re.zip && sha256sum re.zip  # == (4)
```

---

## 9. Compliance & scope statement

- Read `AGENTS.md` + the eight Owner principles; evidence and
  inference are separated throughout; every `NOT RUN` is explicit.
- No commit, file or setting in the plugin was changed for this
  task; the only repository writes are this report and the
  append-only `docs/agent-log.md` entry. No branch other than
  `arena/3249c895-doctor` was used; nothing was merged.
- No GitHub Release, tag, Pages site or other publication was
  created; verified live that none pre-existed either (§4).
- No rebuild, no modification, no substitution of the artifact.
- No Owner production hosting, no patient data, no credentials
  (beyond the sandbox’s own GitHub metadata token) were accessed.
  Short-lived signed artifact URLs were redacted, never stored.
- Known residual risks for the Owner’s decision: (i) artifact
  expiry 2026-11-07T14:02:53Z; (ii) until §8 is executed somewhere,
  the inner ZIP’s bytes rest on GitHub’s metadata digest + the
  producing job’s success — strong provenance, but not the
  byte-level chain this mission requires; (iii) any public channel
  makes this build world-downloadable — Owner should confirm that
  is intended for the external tester.
