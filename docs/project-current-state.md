# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **Integrated main checkpoint:** `bdb135e9b3eff9db9fbe104c33bc6c30850c5263`
> (PR #25 MERGED 2026-09-12T06:42:07Z — documentation-only C10 performance evidence
> package integrated; approved head `e2d9ce74`; merge parents `bd2634a` (main before merge,
> PR #24 docs merge) + `e2d9ce74` (PR #25 head); post-merge gates success: CI `34678813474` ·
> Real WP `34678813477` · Pilot/Staging `34678813488` · Closure `34678813479`).
> **C10 FORMALLY CLOSED by explicit Owner decision 2026-09-12** (bounded evidence review
> only — see §K). Phase 2 IN PROGRESS; Phase 3 / Phase 17 NOT STARTED.
> **Previous integrated checkpoint (historical):** `7146d5bb4167d2ac333000188d404c2aa977b817`
> (PR #23 MERGED 2026-09-11T19:35:54Z — bounded C9 i18n remediation integrated;
> approved head `c92737bb0a30fbdf13d804a8dcdffa522fd556ae`;
> merge parents `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54` (main before merge,
> itself the PR #22 documentation merge) + `c92737bb…` (PR #23 head)).
> **Previous integrated checkpoint (historical):** `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54`
> (PR #22 MERGED 2026-09-11T15:56:52Z — documentation-only Owner C7 acceptance +
> C8 closure sync; merge parents `b19930fe` + `c5f98ab9`).
> **Earlier integrated checkpoint (historical):** `b19930fe95a7d64b69f5ab9b5ac8a924261c48b8`
> (PR #21 MERGED 2026-09-11T14:27:14Z — post-C7 documentation-only continuity sync;
> head `3589b15d`; merge parents `a385d868` + `3589b15d`).
> **Previous integrated checkpoint (historical):** `a385d8681d5c37386407360b5b3c86e9b4af4e05`
> (PR #20 MERGED 2026-09-11T13:09:16Z — C7 remediation integration;
> merge parents: `4871f842d95732add73b972b7bed69d6da1eff89` (main before merge,
> itself the PR #19 documentation merge) + `6b438238228d2609fe47e069987bc91a7be4cdfa` (PR #20 head)).
> **Earlier integrated checkpoint (historical):** `248ca1049b49ea8f82b622744e39cf5b391a6838`
> (PR #17 MERGED 2026-09-10T20:55:57Z — post-closure C6 finance corrective).
> **Earlier integrated checkpoint (historical):** `099b6449362ce16be185aa811ff1f7da7dec269e`
> (PR #14 MERGED 2026-09-10; parents: pre-merge main `8087b42` + PR #14 head `a49b182`).
> **Pre-merge implementation evidence (historical):** `3fc5a54c3a340f8d6881048ff299e6700a7fb99e`
> **Historical baseline:** `c2bff76d1e21643a66bc0056a29881faaa2f299f` (tenant-hardening batch)

If Git/remote/PR, this file, and the repository tree disagree: **STOP**.

This file describes the integrated main checkpoint `7146d5bb` plus preserved
previous-checkpoint and pre-merge/pre-corrective evidence SHAs below. It does
**not** self-refer to the SHA of any later documentation-only commit.

---

## A. Product goal

Commercial clinic/practice platform (CPMS) on one Core / one Schema:

- Single Doctor
- Multi Doctor
- Multi Location
- Multi Clinic
- Organization with multiple Clinics

Authoritative domain model: `Organization → Clinic → Location` ([ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)).

---

## B. Authoritative roadmap

**Only** Owner-approved Phase 0..20 in [`docs/roadmap/roadmap.md`](roadmap/roadmap.md).

Legacy labels (`F0..F10`, `Doc-Phase`, `V1` / `V1.5` / `V2`) are historical. They do not override the Owner roadmap.

> **Interpretation governance:** for the full phase/taxonomy hierarchy, ambiguity warnings, and crosswalk
> see [`docs/governance/project-phase-taxonomy.md`](governance/project-phase-taxonomy.md).
> Rule of thumb: a bare "Phase N" = Owner Roadmap; `C6` = a work package **inside** Owner Phase 2;
> Owner Roadmap Phase 9 = **Patient Portal (NOT STARTED)**, which is **not** the same as any
> internal/historical "Phase 9" reference.

---

## C. Current state

| Phase | Status |
|---|---|
| Phase 0 | CLOSED |
| Phase 0.5 | CLOSED |
| Phase 1A | CLOSED (`9bc6f7f`; OD-9 CLOSED) |
| Phase 1B | DEFERRED — scoped / object authorization (depends on Phase 2 + 3) |
| Phase 2 | **IN PROGRESS** — subphase **C6 CLOSED** + post-closure corrective integrated (PR #17); subphase **C7 CLOSED** (remediation integrated via PR #20 at merge `a385d868`; **formal Owner acceptance recorded 2026-09-11**); **C8 / C9 CLOSED**; **C10 CLOSED (explicit Owner decision 2026-09-12; bounded evidence review only — no NFR/load/scalability claim)**. Internal queue C1..C10 complete; **Phase 2 End Gate NOT defined / NOT passed** |
| C7 | **CLOSED — formally accepted by explicit Owner decision on 2026-09-11.** Technical closure was already evidenced (implementation merged into `main` via PR #20, merge `a385d868`, 2026-09-11T13:09:16Z; slices C7-0→C7-S6; all gates GREEN). The Owner acceptance covers the defined/completed C7 scope only — it is **not** a claim that all conceivable tenant isolation throughout the product is perfect, **not** commercial-readiness approval, and does not approve any deferred item, migration, or later phase — see `docs/phase-reports/c7-0-census.md` §۱۱ |
| C8 | **CLOSED as a Phase-2 Location-foundation evidence/documentation closure package (2026-09-11) — NO implementation work performed or authorized.** Reviewed evidence found no verified Phase-2 Location implementation gap. C8 closure means "the Phase-2 Location foundation is closed based on current evidence", not "all future Location/timezone behavior is complete" — see `docs/phase-reports/phase2-state.md` §C8 |
| Phase 3 | **NOT STARTED** — no `AuthorizationService`; do not start |

**Integrated main checkpoint:** `b19930fe` (PR #21 MERGED 2026-09-11T14:27:14Z —
post-C7 documentation-only continuity sync; **Owner formal C7 acceptance + C8
closure package recorded in this documentation update's lineage**). Previous
integrated checkpoint: `a385d868` (PR #20 MERGED 2026-09-11T13:09:16Z — C7
remediation integration). Earlier: `248ca10` (PR #17 MERGED
2026-09-10T20:55:57Z — post-closure C6 finance corrective). Earlier: `099b644`
(PR #14 MERGED 2026-09-10; parents `8087b42` + `a49b182`). **Pre-merge
implementation evidence (historical):** `3fc5a54`. Historical baseline:
`c2bff76` (tenant-hardening batch). All four post-merge main gates GREEN on
`b19930fe` (table below); the `a385d868`, `248ca10`, `099b644`, and `3fc5a54`
gate tables are retained below as historical evidence.

**Schema:** current version **`2026_09_09_0020`** (re-verified on the tree at `7146d5bb`: latest file is `src/Migrations/2026_09_09_0020_idempotency_clinic_scope.php`; 20 migration files `0001`..`0020`). File `0021` does **not** exist. **Migration 0021 is NOT approved and was NOT created** (including by the C8 documentation closure and by the bounded C9 integration/closure — neither authorizes a migration). If new schema is required: STOP and ask Owner.

**Post-merge integration state (verified from live remote 2026-09-11)**

| | |
|---|---|
| `origin/main` | `7146d5bb4167d2ac333000188d404c2aa977b817` — "Merge pull request #23 from bia2on2on/arena/01a09182-doctor" — bounded C9 i18n remediation integrated (10 files: 2 REST controllers + 1 integration test + 7 docs) |
| PR #23 | [#23](https://github.com/bia2on2on/doctor/pull/23) — **MERGED** 2026-09-11T19:35:54Z — merge commit `7146d5bb`; parents `0fd5c27` (main) + `c92737bb` (approved head, branch `arena/01a09182-doctor`) — merged by `app/arena-ai-coding-agent` (bot). Post-merge gates on `7146d5bb` **all success**: CI `34639699703`, Real-WP `34639699751`, Pilot `34639699635`, Closure `34639699639` (19 check-runs, none pending). **Explicit Owner formal acceptance/closure of the bounded C9 scope was subsequently provided on 2026-09-11** (recorded in §K below). The historical PR title still reads "DRAFT — DO NOT MERGE"; that is pre-merge wording and Git history is deliberately **not** rewritten |
| PR #22 | [#22](https://github.com/bia2on2on/doctor/pull/22) — **MERGED** 2026-09-11T15:56:52Z — merge commit `0fd5c27`; parents `b19930fe` (main) + `c5f98ab9` (head `arena/01a0910a-doctor`) — documentation-only (8 docs files): recorded Owner C7 acceptance + C8 documentation-only closure |
| PR #21 | [#21](https://github.com/bia2on2on/doctor/pull/21) — **MERGED** 2026-09-11T14:27:14Z — merge commit `b19930fe`; parents `a385d868` (main) + `3589b15d` (head `arena/01a090a0-doctor`) — documentation-only (verified changed-file list: 8 docs files, no code/test/workflow/migration) |
| PR #20 | [#20](https://github.com/bia2on2on/doctor/pull/20) — **MERGED** 2026-09-11T13:09:16Z — merge commit `a385d868`; parents `4871f84` (main) + `6b438238` (head `arena/01a08f64-doctor`) — merged by `app/arena-ai-coding-agent` (bot); the merge act itself carried no recorded Owner approval at merge time, and **explicit Owner formal acceptance of C7 was subsequently provided on 2026-09-11** (recorded in this file + census §۱۱) |
| PR #19 | [#19](https://github.com/bia2on2on/doctor/pull/19) — **MERGED** 2026-09-11T07:10:36Z — docs/continuity sync to `248ca10` + C7-0 census open-question correction (merge parent of #20 via `4871f84`) |
| `origin/main` (previous, historical) | `a385d868` — "Merge pull request #20 from bia2on2on/arena/01a08f64-doctor" — C7 remediation integration |
| PR #17 | [#17](https://github.com/bia2on2on/doctor/pull/17) — **MERGED** 2026-09-10T20:55:57Z — merge commit `248ca10` — base `main` — head `arena/01a08b96-doctor` |
| PR #18 | [#18](https://github.com/bia2on2on/doctor/pull/18) — **CLOSED WITHOUT MERGE** 2026-09-11T06:00:12Z (`mergedAt` = `null`) — competing C6 corrective, superseded by merged #17 — branch `arena/01a08b72-doctor` (head `68cde82`) **retained** as historical evidence, deletion not authorized |
| PR #16 | **CLOSED** (historical; earlier competing corrective) — do not reopen |
| PR #14 | [#14](https://github.com/bia2on2on/doctor/pull/14) — **MERGED** 2026-09-10 — base `main` — head `a49b182` |
| PR #10 / #11 / #12 / #15 | **MERGED** (historical; superseded) — do not reopen |
| PR #13 | **OPEN + DRAFT** — C6 repair diagnostic (`arena/01a086b4-doctor`, head `09d505b`, base `arena/01a086ca-doctor`) — **do not touch, do not merge, do not close** (re-verified unchanged 2026-09-11). Ancestry relevance: `09d505b` **is an ancestor of `origin/main`** (its linear repair line was integrated through PR #14); that fact is reported only — cleanup is a later Owner decision |
| Pre-merge branch (historical) | `arena/01a08828-doctor` — implementation `3fc5a54` — closure docs `becc82f` — integrated via #14 |

**Post-merge gates on `b19930fe` (`origin/main`, push event — all SUCCESS, verified live 2026-09-11; 19 check runs total, 0 pending, 0 failed):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34610213715` | 1 |
| Real WordPress Acceptance | `34610213751` | 1 |
| Pilot/Staging Readiness | `34610213717` | 1 |
| Closure Gate | `34610213674` | 1 |

**Historical post-merge gates on `a385d868` (previous checkpoint — all SUCCESS, attempt 1, verified live 2026-09-11):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34602712029` | 1 |
| Real WordPress Acceptance | `34602711983` | 1 |
| Pilot/Staging Readiness | `34602711956` | 1 |
| Closure Gate | `34602711962` | 1 |

**Historical post-merge gates on `248ca10` (previous checkpoint — all SUCCESS, verified live 2026-09-11):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34529280235` | 1 |
| Real WordPress Acceptance | `34529280196` | 1 |
| Closure Gate | `34529280176` | 1 |
| Pilot/Staging Readiness | `34529280164` | **2** |

Pilot attempt 1 on `248ca10` failed as a **Class C** (infrastructure / tooling
transient) event, not a product regression; attempt 2 re-ran the substantive
staging steps and succeeded. The Class C classification is recorded rather than
erased — the retry is the evidence, not a rewrite of the first attempt.

**Previous PRs — historical, integrated (do not reopen, rewrite, or close #13)**

Verified pre-merge ancestry (`merge-base --is-ancestor`): heads of #10 and #11
were ancestors of the #14 line; their histories are fully contained in the
merged main `099b644`. Do not rewrite those branches.

| PR | Branch | Head | Role | State |
|---|---|---|---|---|
| [#10](https://github.com/bia2on2on/doctor/pull/10) | `arena/01a0808c-doctor` | `79cce4b` | Phase 1A | MERGED |
| [#11](https://github.com/bia2on2on/doctor/pull/11) | `arena/01a082db-doctor` | `9e006b0` | OD-9 + Phase 2 through C6-E2 | MERGED |
| [#12](https://github.com/bia2on2on/doctor/pull/12) | `arena/01a086ca-doctor` | `f88fcdc` | C6 tenant hardcode removal (CI execution) | MERGED |
| [#13](https://github.com/bia2on2on/doctor/pull/13) | `arena/01a086b4-doctor` | `09d505b` | C6 repair diagnostic | OPEN + DRAFT — **untouched** |

**Historical gates on `099b644` (previous integrated checkpoint, push event — all SUCCESS):**

| Gate | Run |
|---|---|
| CI | `34460364222` |
| Real WordPress Acceptance | `34460364238` |
| Pilot/Staging Readiness | `34460364243` |
| Closure Gate | `34460364219` |

**Historical gates on `6e5d48c` (via PR #12)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | `34375762362` |
| Real-WP (PR) | `34375762425` |
| Real-WP (push) | `34375756020` |
| Closure | `34375756075` |
| Pilot/Staging | `34375756043` |

**Historical gates on `3fc5a54` (pre-merge branch evidence)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Tripwire + Unit×4 + PHPStan + WPCS + Integration 616 tests/0E/0F) | `34450386921` |
| Real-WP Acceptance (push) | `34450382616` |
| Real-WP Acceptance (PR) | `34450386918` |
| Pilot/Staging Readiness | `34450382549` |
| Closure Gate | `34450382522` |
| Pilot/Staging (Release Artifact + Responsive smoke + Upgrade path + Staging Gate) | `34406991520` |
| Closure Gate (GO‑LIVE evidence) | `34406991334` |

PHP was **not** on PATH in the audit sandbox; do not claim local PHPUnit/`php -l` there. Remote GitHub Actions is the executable evidence.

Lost commit `6000712`: **NOT RECOVERABLE**. Do not claim it exists.

---

## D. Architecture invariants

- Organization is mandatory (no `Organization = NULL` path).
- Every Clinic belongs to exactly one Organization.
- Every Clinic has at least one real Location (AD-15).
- A User may participate in multiple Clinics via **Membership / Assignment**.
- **One Clinician Professional Profile per WP User** (`u_clinician_user` stays). Multi-clinic doctor work is membership, not duplicate clinician rows. Do **not** plan to drop `u_clinician_user` in C7.
- Home/default Clinic (if ever UX) is not an authorization boundary.
- Patient Identity = Organization-level; Clinical Patient Record = Clinic-level.
- No implicit cross-clinic medical visibility.
- Location timezone is the operational source of truth (UTC storage).
- No implicit `clinic_id = 1` / `organization_id = 1` / `location_id = 1` (AD-13).
- No `current-user == Doctor` assumption.

**Context transport vs trust**

Client may *request* a Clinic context (any transport: param/header/route/selector/future app).  
Server must *establish/verify* it (membership/relationship).  
Never: `client clinic_id == trusted clinic_id` without verification.  
Application services stay transport-agnostic (`App::scope()` / domain relation / explicit clinic parameter).

```
Client-requested context
  → Authentication
  → Server-side Membership/Relationship Verification
  → Trusted ScopeContext
  → Application Service
  → Repository
```

At `6e5d48c` this pipe is **not** implemented on the REST boundary. `ScopeContext::set` in production exists only inside `ExportService` job bind. REST staff still falls through to `SystemClinicResolver` (exactly-one Clinic) or throws `CLINIC_SCOPE_REQUIRED`.

---

## E. Security invariants

- Server-side authorization; UI hiding is not authorization.
- Fail-closed tenant isolation. Multi-clinic without explicit scope → `CLINIC_SCOPE_REQUIRED`.
- Private clinical storage outside DocumentRoot (OD-7).
- Active backup storage outside DocumentRoot; unsafe config is Fail-Closed + legacy read-only (OD-9).
- OTP `verify_mobile` does not provision users (OD-8).
- WP `administrator` has no blanket clinical-data bypass (AD-10/AD-11).

---

## F. Owner-approved UX direction

WordPress = hidden backend/platform. Operational users should not need to know it exists.  
`wp-admin` ≈ technical/system administration.

**Future (do not build in C6):**

- Staff Portal: Doctor / Reception / Finance / Clinic Manager workspaces
- Separate Patient Portal
- Common branded login may route by membership; a user may hold different roles in different Clinics

---

## G. Future clients

Core must stay client-agnostic for Web Staff Portal, Patient Portal, Android, iOS, PWA.  
Business logic must not be buried in wp-admin.  
Do not implement mobile auth/JWT now.

---

## H. Iran

Supports all provinces and cities of Iran. No Tehran-centric architecture.  
No `province_id=1` / `city_id=1` / Tehran-as-location-default.  
`Asia/Tehran` is only a timezone fallback.

- Iranian mobile normalization (`MobileValidator`); Persian/Arabic/Latin digits
- Unicode Persian data
- Geography foundation → Phase 4 Master Data (Owner Roadmap) — the C8
  documentation closure (2026-09-11) authorizes **no** Iran
  province/city master-data dataset, nationwide geography seeding, or
  master-data management UX; that implementation remains deferred to Phase 4
  unless separately Owner-approved
- Jalali is UX/display; internal timestamps stay UTC

---

## I. Quality

WPCS (changed-lines) · PHPStan · Unit · Integration · Real-WP · Pilot · Closure · security · performance · compatibility · i18n.

CODE + TEST + DOC + MIGRATION together where relevant.

User-facing strings: WordPress i18n-ready. Machine `CLINIC_*` codes stay stable.

### I-1. Localization layering rule (C9 — forward architecture rule, permanent)

Recorded by the bounded C9 work package (PR #23 — **MERGED** into `7146d5bb`; base `0fd5c27`):

- **Domain** must not gain any NEW direct WordPress i18n/presentation dependency. New
  Domain code must not call `__()` or an equivalent WordPress i18n API.
- **Application business/service code** must not add new direct WordPress
  presentation/i18n coupling as the default pattern.
- **Human localization belongs at presentation/adapter boundaries** (REST controllers,
  wp-admin) — the already-established pattern of `RestClinicContext::toError()` (since C6)
  and `ClinicianAdminPage` (since C7-S4).
- **Stable machine-readable `code` / HTTP status / structured `data` remain authoritative**
  for clients. The bounded C9 change preserved all three exactly, and preserved the default
  Persian `message` byte-for-byte, so **no API-contract change was needed**
  (`api-contract.md` §0 and `ADR-0019` do not pin `message` bytes; `SRS` NFR-UI-4 asks for
  "i18n-ready, fa default").
- Existing direct Domain/Application `__()` usage is **tolerated historical debt, NOT
  precedent to expand**. Enumerated and deliberately left untouched: **13** Domain sites in
  **2 of 42** Domain files (`Domain/Membership/MembershipException.php` — 7,
  `Domain/Patients/PatientIdentityException.php` — 6) and **19** Application sites in 2 files
  (`Application/Membership/MembershipService.php` — 13,
  `Application/Patients/PatientIdentityService.php` — 6). No mass legacy cleanup was
  performed and none is claimed.
- The plugin still ships **no** `.po`/`.mo`/`.pot` catalog and calls **no**
  `load_plugin_textdomain()`. Because the `cpms` domain therefore resolves to WordPress'
  empty `NOOP_Translations`, `__($msgid, 'cpms')` returns the source string unchanged —
  which is exactly why default Persian output is preserved byte-for-byte while the two
  messages became translation-ready.
- **Guard decision:** no new CI guard tooling was created for this rule (no Domain i18n
  allowlist scanner, no new workflow/step) and WPCS configuration was not modified —
  `WordPress.WP.I18n` validates *how* `__()` is called and cannot express a layering rule or
  detect an unwrapped literal. The rule is therefore **documentation/review-based** for now,
  for both Domain and Application.
- **Bounded deferred debt (confirmed still present, out of the declared C9 REST scope):**
  `src/Admin/ClinicianAdminPage.php:436` and `:494` render the Schedule service message raw
  (`'خطا: ' . $e->getMessage()`).

> **Transitional technique — not the preferred long-term architecture.** The two literal
> msgids added at the REST boundary are byte-identical copies of the corresponding service
> source strings. This duplication is a **deliberately bounded TRANSITIONAL compatibility
> technique** for exactly these two C7-introduced messages, forced by an existing tooling
> constraint: `WordPress.WP.I18n.NonSingularStringLiteralText` (enforced on added lines
> against the WPCS baseline) reports a non-literal msgid — including
> `__($e->getMessage(), 'cpms')` and even a class constant — as an ERROR, so a literal msgid
> was the only way to keep the existing gate green with **no** `phpcs:ignore` and **no**
> weakening of WPCS. Duplication between Service and Controller must **not** be documented or
> treated as the preferred long-term architecture; the long-term architecture is the layering
> rule above, and future consolidation may replace this transitional duplication when
> justified by broader evidence. For this bounded task no new abstraction was created solely
> to eliminate two duplicated literals — the REST boundary already had enough information to
> distinguish the variants safely (static controller identity + stable code + empty `data`),
> so a generic error mapper or a `message_key` framework would have been disproportionate.
> Divergence is locked by an **external-behavior** assertion (the default-envelope tests
> compare the REST `message` with the real service exception's `getMessage()`), not by
> comparing two source literals.

---

## J. Git / recovery

- Atomic commits; safe frequent push of **this Arena branch only**
- No merge without Owner; no force push; no tag/release/version bump
- `main` untouched; `/tmp` and Arena sandbox disposable
- Do not touch PR #13 (OPEN + DRAFT diagnostic); PR #10/#11/#12/#14 are MERGED history — do not reopen

A future Agent must: (1) read this file (2) verify Git/remote/PR (3) verify linked docs (4) compare to the repo (5) **STOP** on material mismatch.

---

## K. Current C6 state

Canonical inventory: [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)  
Phase 2 queue: [`docs/phase-reports/phase2-state.md`](phase-reports/phase2-state.md)

**C6 is CLOSED** (Owner/Architect decision; integrated via PR #14 into main
`099b644`; deferred boundaries recorded in [`c6-deferred-boundaries.md`](phase-reports/c6-deferred-boundaries.md)).
The checkpoint notes below are **historical pre-closure evidence** (pinned to
their SHAs), not current state.

**Verified at `6e5d48c` (local tripwire, empty allowlist — not a CI job):**

- Baseline (C6-A @ `4152387`): 68 production executable hits
- Production runtime tripwire at `6e5d48c`: **0** (7 comment-only, non-executable)
- Tripwire is **NOT** wired into CI
- Tripwire regex still misses some positional `VALUES (1, …)` INSERT clinic columns

**Trusted REST Clinic context — C6 boundary**

- Implementation checkpoint: `f88fcdc3fb6479782b140a2d7034a3205a52edad` (`TrustedClinicEstablisher` + `RestClinicContext` bound on `rest_request_before_callbacks` / `rest_request_after_callbacks`, `ScopeRequiredException` with explicit HTTP status).
- That checkpoint was **CI RED** (Integration + Real‑WP PR + Real‑WP push; WPCS/PHPStan/Unit/Pilot/Closure green). Evidence, not erased:
  - **Class D (test harness)** — `RestTrustedClinicContextTest::$locB` typed `int` read before initialization: two security tests never reached `rest_do_request`.
  - **Class A (product error contract)** — the boundary applied to *patient* traffic on `/clinic/v1/patients`, `/patients/search`, `/queue`, `/search`, turning the stable `CLINIC_PERMISSION_DENIED` into `CLINIC_SCOPE_UNAVAILABLE` (5 Integration failures).
  - **Class A (product/ops gap)** — Real‑WP `browser.no_console_errors`: 4 staff REST calls from wp‑admin returned 403 because `wp user create --role=…` staff actors never receive an active Membership (Migration 0012 seed runs before them).
  - **Class D (fixture contamination)** — `NotificationFlowTest::testInvoiceReadyNotifiesOtherSecretaries` (2 rows vs 1): the then-new global `set_user_role` membership fixture seeded the doctor, who holds `cpms_queue_read`, so the broadcast gained a recipient.
- Repair batch (this branch, on top of `f88fcdc`, linear — no rewrite, no force‑push):

| SHA | Scope |
|---|---|
| `adecd21` | test: `$locA`/`$locB` explicit init; **global membership injection removed**; explicit idempotent `cpms_test_seed_membership(user, clinic, roleKey)`; staff tests arrange their own Membership |
| `a23b509` | fix(rest): trusted scope applies to **authenticated staff use** only (narrow, via the existing `currentUserIsStaff()` predicate — no route added to / removed from the skip list) |
| `b7a3a6b` | test(acceptance): Real‑WP fixture seeds **active Membership on the Clinic actually resolved in that environment** (from the acceptance clinician link) — TEST INFRASTRUCTURE only |
| `da72e1c` | fix(rest): Scope restoration on the normal and error paths (LIFO pairs + conditional restore); `shutdown` safety net for filter‑level throws/fatals — after an executable test proved the leak. Wording refined after the lifecycle review on `4606b15`: WordPress converts an escaping handler exception to `WP_Error` **before** `rest_request_after_callbacks`, so the `shutdown` net is not what covers the handler‑exception path (executable evidence: `testErrorResponsePathStillRestoresScope`) |
| `4289d89` | test: corrected precondition of the new non‑member‑staff evidence test (Class D, its own assertion) |

- **Production automatic Membership provisioning was NOT introduced**: no `user_register`, no `set_user_role`, no global‑role→tenant mapping, no “there is only one Clinic”, no first/default Clinic. Staff onboarding remains an **OPEN architecture item** for an explicit, scope‑aware product workflow.
- Security invariant intact and now executed: authenticated staff REST access requires an **active verified Clinic Membership**; even in an exact‑one‑Clinic install no Membership means no staff Clinic access (`testNoMembershipDeniedEvenWhenExactlyOneClinic`, `testExactOneClinicIdNotOneStillRequiresMembership`, `testStaffActorWithoutActiveMembershipIsDeniedByBoundary`).
- Real‑WP acceptance fixture models Membership explicitly; the “staff **without** Membership is denied” invariant is proven by the focused Integration tests above (not by the fixture).
- **OPEN DECISION — route classification (deliberately NOT resolved in this repair):** `/prescriptions` skip, `/appointments/{id}/reschedule` vs `/cancel` asymmetry, `GET /visits{,/{id}}` skip, `/files/{id}/stream` + `/patients/{id}/files` ownership-only, `/config/services*`, `/sms/*` — needs the next architecture review.
- Gate evidence for the repair tip: recorded in [`phase2-state.md`](phase2-state.md) (exact Run IDs).

**Already done (do not redo):**

- C6-A census · C6-B Notifications/SMS/Jobs · C6-C Booking/Schedule
- C6-D Patients/Clinical/Visits/Files · C6-E1 Settings/Audit/Idempotency + **Migration 0020**
- C6-E2 repository writes (clinic-first) @ `9e006b0`
- **C6-E3 Reports** @ `1c82d26` (+ Class D test follow-up `a120a68`) — trusted clinic filter, not `clinic_id=1`; OWN = that Clinic + one Clinician profile (`u_clinician_user`)
- **C6 Export** @ `f2c0ca6` — same Clinic on request → job payload → storage → notification → list/download/purge (per-row clinic)
- **Pilot/bin** @ `6e5d48c` — resolve real Clinic/Location IDs; never encode Clinic ID == 1

**Remaining C6** (at `3fc5a54` — all below DONE)

1. Trusted REST context — **implemented and repaired**. Route-classification follow-ups deferred to Phase 3 policy.
2. Tripwire hardening + CI wiring — **DONE**. 34 self-tests PASS, 173 production files CLEAN, CI GREEN.
3. C6-F multi-tenant isolation suite — **DONE**. Matrix 45/45 VERIFIED_GREEN. Real handler runtime tests (MT-39). Non-1 clinic IDs (MT-45).
4. Keep docs in sync after each verified implementation SHA.
5. Route classification — tenant isolation verified; deferred to Phase 3.
6. Staff onboarding (UI/API) — domain primitives complete; deferred to LATER_STAFF_ADMIN_UX.
7. SMS resend key — KNOWN_MEDIUM_DEBT; Migration 0021 NOT APPROVED.

Internal task label in some reports: «Phase 9 §5» — legacy task‑taxonomy wording, **not**
roadmap Phase 9 (Patient Portal — still NOT STARTED).

| SHA | Scope |
|---|---|
| `ddca8d7`…`d52d32d` | tests: `ClinicTenantIsolationTest` — 25 probes; lazy‑registry warm; FK‑safe purge; isolation witness; per‑test tag fixtures |
| `f6703dc` | **fix(files):** C6‑F per‑object tenant guards on `MedicalFileService` (stream/staffFiles/softDelete/store) + `ClinicalService::record()` visit/patient tenant guard; cross‑tenant ⇒ 404 before any disk read/write |
| `f5ebefd` | **fix(visits):** five `clinic_id = 1` literals removed from `VisitService` — trusted clinic via `queueClinicId()` (explicit Scope → exactly‑one resolver → `CLINIC_SCOPE_REQUIRED` 400); `queueScopeClinicianId()` clinic‑scoped; cross‑clinic `clinician_id` → 404 |
| `b7b8399` | **fix(files):** skip‑listed routes resolve trusted Clinic for staff through explicit Scope → system resolver → unique active membership (`TrustedClinicEstablisher`) — never “first Clinic”, never Clinic 1 |
| `bff690a` | **fix(files):** denial envelope byte‑identical to not‑found (existence non‑disclosure; reason only in Audit); fixture hygiene: no writes into `clinic_id=1` (reserved by other suites — see pollution below), `CLINIC_D=61004` + exact‑domain witness, `CPMS_FIXTURE_RESIDUE` tearDown guard |
| `c2bff76` | **test(harness):** cross‑class pollution root cause closed (details below). No product change |

**Pollution root cause (Class D — this class's harness; repaired here, not transferred):**
`App::boot()` is one‑shot and `rest_api_init` captures service instances into route closures
once per process (`App::otpService()` static, `self::$smsService`, `medicalFileService()`,
`exportService()`/`visitService()` as seen by the controllers — all built with the *ambient*
scope/`App::settings()` at first‑REST‑touch). Alphabetically `ClinicTenantIsolationTest` precedes
`ClinicalFlowTest`, so this class was the process's first REST toucher: warming under the fixture
scope pinned every later class to services bound to fake clinic 61001 → later writes failed FK
(clinic deleted; `sms_sent=false`, links `0`), later setting reads returned defaults (OTP cooldown
/`queue.max_recalls`/`sms.provider`), later downloads 404 (row clinic ≠ fresh scope). Fix:
neutral warm **before** fixture clinics exist ⇒ pin == baseline; plus disk‑artifact unlink in
purge (DB rollback never touches disk) and `makeUser` unique‑email fix (real fixture bug that
produced probe‑11's `(int) WP_Error` error).

- Evidence chain: `f5ebefd` CI `34403805829` (queue probes red→green — hardcode defect proven,
  then fixed) · `bff690a` CI `34404449199` (8/11 file probes green; remaining reds diagnosed) ·
  `c2bff76` CI `34406996627` — **all 7 jobs success** (Integration 602 tests, 0E/0F; every victim
  class green again) · Real‑WP `34406991487` · Pilot `34406991520` · Closure `34406991334`.
- Historical RED checkpoints preserved, not erased: `d5072ff` run `34403028668` (20 probe
  failures = defect evidence) and `bff690a` run `34404449199` (6 cross‑class failures = pollution
  evidence).
- No security test was hidden/skipped/quarantined/weakened; `RestClinicContext` skip list
  unchanged in both directions; no schema change; **no Migration 0021** (none required —
  `(id, clinic_id)` reads ride PRIMARY; no clinic‑leading index needed for the new predicates).
- Touched‑path census re‑verified at `c2bff76` (manual grep, production runtime): **0** semantic
  clinic‑1 / first‑clinic / current‑user‑as‑tenant hits in `VisitService`, `MedicalFileService`,
  `ClinicalService::record`, `QueueController`, `FilesController`, `VisitRepository`,
  `MedicalFileRepository`. CI tripwire wiring remains an untouched open item.

### Post‑closure C6 corrective — finance Clinic scope (PR #17 — **MERGED**, integrated at `248ca10`)

Historical truth preserved: **C6 was formally closed**; a finance‑scope omission
(plus detector blind spots) was discovered afterwards and corrected here.
Nothing below rewrites the C6 acceptance record — the omission happened, was
found after closure, and is recorded as such.

**Integration status:** PR #17 is **MERGED** (2026-09-10T20:55:57Z) and is
contained in `origin/main` at `248ca10`. The competing PR #18 carrying the same
corrective was **closed without merge** on 2026-09-11 as superseded; see the
integration-state table above. Scope of the merged corrective:

- seven finance Clinic-ID-1 defects corrected (explicit required Clinic contracts),
- Tenant Tripwire blind spots corrected (bound-parameter tenant literal + 3 suppression defects),
- explicit Clinic scope on all seven runtime paths, no fallback to Clinic 1,
- non-1 / two-Clinic regression protection,
- direct lock-target test (observes the real SQL of the numbering row lock).

- **Class B production defects (pre‑existing, blame F6 `ef59e0cb`):** 7 finance
  runtime paths pinned to Clinic ID 1 — `ServiceRepository::all`,
  `PaymentRepository::{revenueSummary, forRange, nextPaymentNumber}`,
  `InvoiceRepository::{openInvoices, nextInvoiceNumber}`,
  `FinanceService::lockClinic`. Fixed with explicit trusted `int $clinicId`
  contracts (reads via `trustedClinicId()` → `CLINIC_SCOPE_REQUIRED` 400
  fail‑closed; numbering/lock within the visit/invoice Clinic). Impact:
  cross‑Clinic tariff/revenue/payment/invoice reads incl. patient name+MRN,
  wrong per‑Clinic INV/PAY sequences (duplicate‑key failures), wrong‑scope
  row lock.
- **Class D detector defects:** Tenant Tripwire missed bound‑parameter tenant
  literals and had 3 suppression defects (`select_first_clinic` shadowing,
  qualified‑ID swallowing, same‑line benign exemption). Hardened in
  `bin/tenant-tripwire.py` (59 self‑tests, tenant‑aware, empty allowlist).
  Scan now reports **0 hardcodes** (+1 legitimate first‑clinic *suspect* under
  review: `SystemClinicResolver` single‑install resolution, AD‑04).
- **Insert‑failure safety:** the stale‑nonzero‑`insert_id` corruption theory was
  contradicted for standard wpdb (helper resets + clear‑on‑failure, verified
  WP 6.3–6.7+trunk); deterministic sabotaged‑INSERT regression proves current
  behavior already rolls back with no cross‑invoice attach and no payment
  effects — **no insert‑handling product change included**. (Minor robustness
  debt recorded: ignored `CpmsDb::insert` bools + misleading 404 mapping on
  the invoice path — small, deferred.)
- **Tests added:** `tests/Integration/FinanceClinicIsolationTest.php` (18 tests:
  Clinic‑1 compat, non‑1 Clinic, two‑Clinic coexistence, 4 read isolations,
  name/MRN non‑crossing, per‑Clinic INV+PAY numbering, absent‑row operation,
  3 fail‑closed, 4 insert‑safety). Proven meaningful: 13 red‑on‑old
  (run `34487855456`), then green with the fix.
- **Pre-merge branch evidence:** `ac1404f` — CI `34488982706` (all 8 jobs incl.
  Integration 634 tests + tripwire), Real‑WP `34488982705`+`34488978486`,
  Closure `34488978510`, Pilot `34488978503` — **all success**. No test
  weakened/skipped/quarantined.
- **Final post-merge evidence on `248ca10`:** CI `34529280235`, Real‑WP
  `34529280196`, Closure `34529280176`, Pilot `34529280164` **attempt 2** — all
  **success**. Pilot attempt 1 was a **Class C** tooling/environment transient;
  attempt 2 executed the substantive staging steps successfully. Attempt 1 is
  recorded, not erased.
- **No migration** (count unchanged; 0021 neither approved nor created).
  **C7 remediation was implemented on DRAFT PR #20 and subsequently merged
  (PR #20 MERGED 2026-09-11T13:09:16Z, merge `a385d868` — see the C7 section
  below).** Location authorization untouched.

**Do not start C8 *implementation*, Phase 3, Phase 4, portals, or mobile auth/JWT.**
**C8 closure status (Owner-authorized documentation-only closure, 2026-09-11):**
the existence of the internal/canonical `C8` label did **not** by itself prove
that C8 required implementation work; reviewed evidence verifies **no verified
Phase-2 Location implementation gap**, so C8 is **CLOSED as a
Location-foundation evidence/documentation closure package** — it must **not**
be turned into implementation work merely because its queue label exists. Iran
province/city master-data datasets and their management remain deferred to
Owner Roadmap Phase 4; final scoped authorization remains Phase 3; operational
Location-timezone consumption remains a recorded boundary/deferred
reconciliation item, not authorization to implement scheduling/reminder/
timezone changes here. Do not reopen merged history
(PR #10/#11/#12/#14/#17/#19/#20/#21); do not touch PR #13.

### C7-0 — evidence foundation (no product change)

A read-only census/characterization pass was opened to decide which remaining
repository/service isolation candidates are **real executable defects** before
any contract change. It changes **no production behavior**, adds **no
migration**, and does **not** start C7 implementation.

Full matrix and per-path evidence: [`docs/phase-reports/c7-0-census.md`](phase-reports/c7-0-census.md).

Headline: a repository method lacking a Clinic parameter is a **candidate**, not
a defect. Several such methods are safe because the calling service enforces
ownership fail-closed (e.g. `MedicalFileService::assertStaffClinic`,
`ClinicalService::assertVisitInActiveClinic`, `PrescriptionRepository::
findForUpdateForClinic`). Others are **confirmed defects by deterministic code
path** and are listed with severity and a proposed implementation slice in the
census. **No C7 fix is applied in this pass.**

The census records **open questions as open**, not as decisions. In particular it
does **not** assert that C7 requires a JobQueue tenant column, that Migration
0021 is required, that prescription numbering must become per-Clinic, or that any
S2/S3 architecture has been approved. Those remain unresolved design/domain
questions pending Owner decision and further evidence. **No migration is
approved or proposed; `0021` does not exist.**

### C7 — remediation state (MERGED into `main` via PR #20 at `a385d868`)

Full slice-by-slice RED→GREEN history with run IDs:
[`docs/phase-reports/c7-0-census.md` §۱۱](phase-reports/c7-0-census.md).

- **Integration status (verified live 2026-09-11):** PR #20 **MERGED**
  2026-09-11T13:09:16Z by `app/arena-ai-coding-agent` (bot). Merge commit
  `a385d868`; parents `4871f84` (main) + `6b438238` (PR head). `origin/main`
  has since advanced **only** via documentation-only PR #21 (merge `b19930fe`,
  head `3589b15d`). The PR title/body retained its original "DO NOT MERGE /
  EXPECTED RED" C7-0 wording while the head had become fully GREEN; at merge
  time **Owner approval for the merge was not evidenced in the repository**
  (historical governance fact, preserved); **the Owner has since provided
  explicit formal acceptance of C7 on 2026-09-11** (see the closure bullet
  below).
- **Scope completed (evidence-driven, all from confirmed cross-Clinic
  characterization defects):** finance object-ID isolation on the seven
  ID-based finance operations; mandatory trusted Clinic context on those
  seven (fail-closed `CLINIC_SCOPE_REQUIRED` 400 when missing);
  `issueInvoice` Visit ownership + invoice-item Service/Tariff ownership;
  Service/Tariff update/deactivate ownership (404 parity, no silent no-op
  success); Schedule update/delete/deleteException isolation; Schedule
  create + createException Clinician ownership; wp-admin boundary
  (ClinicianAdminPage) establishing trusted Clinic context from the
  approved Membership primitives (no/ambiguous membership ⇒ fail closed;
  multi-clinic selection UX not built).
- **Final pre-merge head:** `6b438238` — GREEN on all five canonical
  workflows (attempt 1): CI `34598981613` (PR), Real-WP `34598981627` (PR) +
  `34598978084` (push), Pilot `34598978102`, Closure `34598978147`. Preserved
  final RED evidence: CI `34598629321` at `f2786c5` (Integration annotation:
  Tests 668, Assertions 4504, Failures 1 — job-log body not retrievable in the
  audit sandbox; the fix commit immediately after, `6b438238`, addresses the
  wp-admin `createException` trusted-scope boundary), plus the earlier RED runs
  listed in the census §۱۱.
- **Post-merge gates on `a385d868`:** CI `34602712029`, Real-WP
  `34602711983`, Pilot `34602711956`, Closure `34602711962` — all
  **success** (attempt 1, push event, verified live 2026-09-11).
- **What the merge contains:** product code (`FinanceService`,
  `ScheduleService`, `ScheduleRepository`, `ClinicianAdminPage`), Integration
  characterization/regression tests, pilot tooling (`bin/pilot-smoke.php`),
  two workflow files (`closure-gate.yml`, `real-wp-acceptance.yml` — explicit
  trusted scope around synthetic probe operations required by the new
  fail-closed contracts; no assertion removed, no gate weakened), and two
  documentation files. **No migration, no schema change.**
- **Permanent trust rule (enforced in every changed path):** a
  client/attacker-selected object row (Invoice/Payment/Visit/Schedule/
  Exception/Clinician/Service) is **never** a source of tenant trust; its
  `clinic_id` is only compared against the independently established
  trusted Clinic context. No Clinic-ID-1 fallback.
- **Canonical error semantics:** missing trusted scope ⇒
  `CLINIC_SCOPE_REQUIRED` 400; valid scope + foreign object ⇒
  non-disclosing `CLINIC_NOT_FOUND` 404 byte-parity with a nonexistent id.
- **No Migration** (`0021` does not exist; no schema change). **Phase 3
  NOT STARTED** (no `AuthorizationService`, no Location policy). Deferred
  items remain deferred and are NOT silently approved (S2 JobQueue
  tenant-context, S3 prescription numbering, Jobs/SMS/timezone, wp-admin
  multi-clinic selection UX).
- **Defect statement (precise):** the completed C7 scope recorded here has
  **no known open Critical/High defect based on current evidence**. This is
  neither a claim of mathematically bug-free software nor a claim that all
  conceivable repository/service isolation in the entire product is perfect;
  unresolved/deferred design questions stay unresolved.
- **Formal closure status (updated 2026-09-11):** technical C7 closure was
  already evidenced (RED→GREEN chain + all gates GREEN + integration into
  `main`). **C7 is now formally accepted/closed by an explicit Owner decision
  on 2026-09-11.** The acceptance covers the defined/completed C7 scope only:
  it is **not** a claim that all conceivable tenant isolation throughout the
  product is perfect, **not** commercial-readiness approval, and existing
  deferred items (S2 JobQueue tenant-context, S3 prescription numbering,
  Jobs/SMS/timezone, wp-admin multi-clinic selection UX) remain deferred
  unless separately authorized. It does not approve a migration or any
  later-phase implementation.
- **Next queue item (Phase 2):** **C8 is CLOSED** (2026-09-11) as a Phase-2
  Location-foundation evidence/documentation closure package — reviewed
  evidence found **no verified Phase-2 Location implementation gap**; no
  implementation was performed or authorized (see `phase2-state.md` §C8 for
  the closure boundary). The internal queue label order continues with C9
  (i18n audit) and C10 (Performance review), but **neither is authorized for
  implementation by this closure**: C9's canonical acceptance scope is not yet
  sufficiently defined and requires a **bounded evidence/scope determination
  before any implementation** (known previous scan findings about
  Persian/i18n strings are evidence to be reviewed, not authorization for
  mass remediation); C10 has no newly approved implementation scope and
  **performance remains NOT MEASURED** unless actual benchmark evidence
  exists. *(Subsequent development, recorded below and **not** a rewrite of the
  C8 boundary: the C9 bounded evidence/scope determination **was** performed, the
  bounded scope was implemented, integrated through PR #23, and C9 was then formally
  closed by explicit Owner decision. The C8 closure itself still authorized none of that.)*
  The historical "End Gate (26 items)" queue label is **not** a
  defined 26-item acceptance checklist — no such canonical definition exists
  in the repository. After the C10 closure decision (the only remaining queue item),
  Phase 2 End Gate → STOP.
- **C9 bounded implementation (2026-09-11 — historical record of what was delivered):** the required
  bounded evidence/scope determination was performed and the approved bounded scope was then
  implemented and delivered on **PR #23 (base `0fd5c27`)**. The scope was exactly the
  **two A/Low findings that C7 itself introduced** — `ScheduleService.php:389` (C7-S5,
  `04a7a79e`) and `FinanceService.php:1111` (C7-S3, `c4cf9024`), both
  `CLINIC_SCOPE_REQUIRED` / HTTP 400 / `data: []` — made translation-ready at the existing
  REST presentation boundary (`ScheduleController::wrap()`, `FinanceController::staff()`),
  with code/status/data and the default Persian message preserved byte-for-byte. No
  Domain/Application production file changed, no migration/schema change, no generic mapper or
  `message_key` framework, no translator port, no Domain/Application error-architecture
  refactor, no mass legacy i18n cleanup, and no new CI guard tooling. See §I-1 for the forward
  layering rule, the tolerated historical debt (13 Domain / 19 Application sites), and the
  explicitly **transitional** nature of the boundary literal duplication.
  **Historical pre-merge state (preserved, not rewritten):** before integration, the maximum
  permitted state was *"C9 bounded implementation complete / READY FOR ARCHITECT MERGE
  REVIEW"* and **C9 was NOT closed** — formal closure was explicitly reserved as a
  **post-merge continuity decision** to be based on the actual integrated `main` SHA and
  successful post-merge gates on that SHA. No document in this repository claimed C9 closure
  before the Owner decision recorded next.
- **C9 formal closure (2026-09-11 — explicit Owner decision, after successful integration):
  C9 is CLOSED.** The conditions the pre-merge record required were then satisfied and
  independently re-verified: **PR #23 is MERGED** (2026-09-11T19:35:54Z) and the bounded C9
  implementation is **integrated into `main`**; the **integrated checkpoint is the verified
  merge SHA `7146d5bb4167d2ac333000188d404c2aa977b817`**, whose merge parents are exactly
  `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54` (main before merge) + `c92737bb0a30fbdf13d804a8dcdffa522fd556ae`
  (approved PR #23 head); and **all four post-merge gates on that exact SHA succeeded**
  (CI `34639699703`, Real WordPress Acceptance `34639699751`, Pilot/Staging Readiness Gate
  `34639699635`, Closure Gate `34639699639`; 19 check-runs total on `7146d5bb`, all
  `conclusion: success`, none pending). **On that basis the Owner explicitly approved and
  formally accepted/closed C9.** The acceptance covers **only** the bounded scope already
  integrated through PR #23: (1) the two verified C7-introduced A/Low i18n findings;
  (2) the REST/presentation-boundary remediation; (3) preservation of stable machine-readable
  code/status/data and of the default Persian behavior; (4) the accepted forward
  localization-layering rule (§I-1). It is **not** a claim that all legacy i18n debt is fixed,
  that full English support exists, that globalization is complete, that all wp-admin strings
  are translation-ready, or that Domain/Application historical i18n debt is fixed — and it
  does **not** close Phase 2. The exact-source-message match used for the two C7 messages
  remains a bounded **transitional** compatibility technique, **not** preferred long-term
  architecture. C10 implementation remained NOT STARTED / not authorized at that moment;
  its subsequent documentation-only evidence package is recorded below.
- **Performance evidence status (corrected 2026-09-11 — the flat "NOT MEASURED" wording used
  elsewhere in this closure record was inaccurate):** performance has **limited real staging
  benchmark evidence**, but **commercial / reference-environment performance remains
  NOT MEASURED / not validated**. Verified repository evidence: an executable `ab` benchmark
  step ("Performance benchmark — ab (p50/p95/p99 + RPS + error rate)") lives inside the
  `staging-gate` job of `.github/workflows/pilot-gate.yml`, and executed results are recorded in
  `docs/phase-reports/report-pilot-gate.md` §8 with actual p50/p95/p99/RPS/error-rate figures.
  That staging environment is **not** the canonical commercial/reference performance
  environment, and that report **explicitly did not claim** the approved thresholds were
  satisfied there (§8: «اهداف مصوب (هیچ‌کدام پاس‌شده اعلام نمی‌شوند)»; performance quality-gate
  adjudication is deferred to a reference-server benchmark — Runbook §12.3,
  BLOCKED_BY_ENVIRONMENT). Still **NOT MEASURED**: reference-environment performance against the
  intended NFR thresholds; the public-page/plugin overhead target (p95 < 100ms);
  runtime query-count/N+1 measurement; memory; and meaningful multi-Clinic load scale.
  **No staging number is evidence of production performance**, and this correction does not
  start or authorize C10.
- **C10 evidence/closure package (2026-09-11 — documentation-only; at that time C10 was
  technically eligible for closure with Owner formal closure pending — superseded by the
  Owner closure decision of 2026-09-12 recorded below):** the bounded C10 performance
  evidence review is complete and recorded in
  `docs/phase-reports/c10-performance-evidence.md` (+ `phase2-state.md` §C10). C10 is
  an internal LEVEL-2 Phase-2 performance review/evidence package limited to the
  Multi-Clinic foundation; it is **not** Owner Roadmap Phase 17 (Performance — NOT
  STARTED), not broad commercial performance optimization, and grants no permission
  for speculative optimization, for weakening tenant isolation/security, or for a
  schema migration. Recorded findings: (1) existing executed staging benchmark evidence
  is real (`performance-baseline.md`; `report-pilot-gate.md` §8; the executable `ab`
  step in the `staging-gate` job of `pilot-gate.yml`), but the recorded §8 numeric
  results are **historical (2026-09-06 runs) and pre-date the major Phase-2
  Multi-Clinic C4..C7 changes** — they establish that benchmark tooling existed and
  executed, **not** current Multi-Clinic performance; successful current integrated
  Pilot/Staging gates (latest verified on `bd2634a`, run `34651627290`) establish
  **successful execution/gate behavior only** — the current staging benchmark step
  completed successfully without triggering its configured non-2xx failure condition;
  exact current benchmark metrics (including any numeric error-rate value) were not
  retrievable from Arena and are recorded as NOT MEASURED / NOT RETRIEVED, and
  workflow success does **not** mean NFR latency thresholds passed (the step enforces
  no latency threshold); no
  current reference-environment/commercial performance validation exists; (2) the
  not-measured list (reference-environment NFR thresholds; public-page/plugin overhead
  p95 < 100ms; runtime query-count/N+1; memory; meaningful multi-Clinic load scale) is
  recorded as **future performance/release evidence aligned primarily with Owner
  Phase 17 / release validation — not as C10 blockers**; (3) the bounded Phase-2
  static review found **no VERIFIED performance defect requiring remediation** — code
  inspection is not measured performance, and no global absence of N+1 or all-product
  performance perfection is claimed; (4) for the historical "End Gate (26 items)" label, **no canonical defined
  26-item Phase-2 End Gate checklist was found in the current repository or inspected
  tracked Git history**; the origin of the number is not recorded as provenance fact. **C10 status (historical at that
  point): evidence review complete / technically eligible for closure — owner formal
  closure pending.** Phase 2 remains IN PROGRESS; the Phase 2 End Gate
  and Phase 3 are NOT started. No new reference-server/load-test campaign is required
  merely to close the internal C10 evidence package; broad commercial performance
  engineering remains Owner Roadmap Phase 17.
- **C10 — FORMALLY CLOSED by explicit Owner decision on 2026-09-12.** The C10 evidence
  package was integrated into `origin/main` at **`bdb135e9b3eff9db9fbe104c33bc6c30850c5263`**
  (PR #25 MERGED 2026-09-12T06:42:07Z; parents `bd2634a` + approved head `e2d9ce74`); all
  four post-merge gates on that SHA succeeded (CI `34678813474` · Real WordPress Acceptance
  `34678813477` · Pilot/Staging `34678813488` · Closure Gate `34678813479`). **Scope of this
  closure: only the bounded Phase-2 performance evidence/review.** It does **not** establish
  current latency-NFR compliance, current post-Multi-Clinic load performance, scalability,
  commercial performance readiness, Phase 17 completion, or Phase 2 completion. The
  historical 2026-09-06 §8 benchmark numbers remain historical (pre-C4..C7); workflow
  success remains non-numeric evidence; NOT RETRIEVED / NOT MEASURED items remain exactly
  as recorded in `c10-performance-evidence.md` §3. **Phase 2 remains IN PROGRESS; Phase 3
  and Phase 17 remain NOT STARTED; the Phase 2 End Gate is not defined/passed.**


---

## Linked canonical docs

- [`docs/roadmap/roadmap.md`](roadmap/roadmap.md)
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
- [`docs/architecture/phase0.5-target-model.md`](architecture/phase0.5-target-model.md)
- [`docs/security/phase1b-deferred-register.md`](security/phase1b-deferred-register.md)
- [`docs/drift-register.md`](drift-register.md)
- [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)
- [`docs/phase-reports/c6-isolation-matrix.md`](phase-reports/c6-isolation-matrix.md)
- [`docs/phase-reports/c7-0-census.md`](phase-reports/c7-0-census.md) — C7-0 evidence foundation (census only, no product change)
- [`docs/architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md) — **🔴 APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**: tenant context for background jobs, system-wide job classification, Location operational timezone, per-Clinic SMS configuration resolution, operational-log tenant attribution, legacy-job backfill rules, the **6 open questions that must be closed before Migration `0021`**, and the **12-item RED test specification** (recorded only — no test written, no code changed, End Gate not started)
- Tripwire: `bin/tenant-tripwire.py` (CI-wired, 59 self-tests)
- [`docs/handoff/phase2-c6-to-next-agent.md`](handoff/phase2-c6-to-next-agent.md)
