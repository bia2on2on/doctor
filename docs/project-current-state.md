# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **Integrated main checkpoint:** `248ca1049b49ea8f82b622744e39cf5b391a6838`
> (PR #17 MERGED 2026-09-10T20:55:57Z — post-closure C6 finance corrective).
> **Previous integrated checkpoint (historical):** `099b6449362ce16be185aa811ff1f7da7dec269e`
> (PR #14 MERGED 2026-09-10; parents: pre-merge main `8087b42` + PR #14 head `a49b182`).
> **Pre-merge implementation evidence (historical):** `3fc5a54c3a340f8d6881048ff299e6700a7fb99e`
> **Historical baseline:** `c2bff76d1e21643a66bc0056a29881faaa2f299f` (tenant-hardening batch)

If Git/remote/PR, this file, and the repository tree disagree: **STOP**.

This file describes the integrated main checkpoint `248ca10` plus preserved
pre-merge and pre-corrective evidence SHAs below. It does **not** self-refer to
the SHA of any later documentation-only commit.

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
| Phase 2 | **IN PROGRESS** — subphase **C6 CLOSED** + post-closure corrective integrated (PR #17) |
| C7 | **NOT STARTED** (implementation). C7-0 evidence foundation only — see `docs/phase-reports/c7-0-census.md` |
| Phase 3 | **NOT STARTED** — no `AuthorizationService`; do not start |

**Integrated main checkpoint:** `248ca10` (PR #17 MERGED 2026-09-10T20:55:57Z —
post-closure C6 finance corrective). Previous integrated checkpoint: `099b644`
(PR #14 MERGED 2026-09-10; parents `8087b42` + `a49b182`). **Pre-merge
implementation evidence (historical):** `3fc5a54`. Historical baseline:
`c2bff76` (tenant-hardening batch). Gap-closure + Tripwire session: `d429f5a` →
`3fc5a54`. All four post-merge main gates GREEN on `248ca10` (table below); the
`099b644` and `3fc5a54` gate tables are retained below as historical evidence.

**Schema:** current version **`2026_09_09_0020`** (re-verified on the tree at `248ca10`: latest file is `src/Migrations/2026_09_09_0020_idempotency_clinic_scope.php`). File `0021` does **not** exist. **Migration 0021 is NOT approved and was NOT created.** If new schema is required: STOP and ask Owner.

**Post-merge integration state (verified from live remote 2026-09-11)**

| | |
|---|---|
| `origin/main` | `248ca10` — "Merge pull request #17 from bia2on2on/arena/01a08b96-doctor" — post-closure C6 finance corrective |
| PR #17 | [#17](https://github.com/bia2on2on/doctor/pull/17) — **MERGED** 2026-09-10T20:55:57Z — base `main` — head `arena/01a08b96-doctor` |
| PR #18 | [#18](https://github.com/bia2on2on/doctor/pull/18) — **CLOSED WITHOUT MERGE** 2026-09-11T06:00:12Z (`mergedAt` = `null`) — competing C6 corrective, superseded by merged #17 — branch `arena/01a08b72-doctor` (head `68cde82`) **retained** as historical evidence, deletion not authorized |
| PR #16 | **CLOSED** (historical; earlier competing corrective) — do not reopen |
| PR #14 | [#14](https://github.com/bia2on2on/doctor/pull/14) — **MERGED** 2026-09-10 — base `main` — head `a49b182` |
| PR #10 / #11 / #12 / #15 | **MERGED** (historical; superseded) — do not reopen |
| PR #13 | **OPEN + DRAFT** — C6 repair diagnostic (`arena/01a086b4-doctor`, base `arena/01a086ca-doctor`) — **do not touch, do not merge, do not close** (re-verified unchanged 2026-09-11) |
| Pre-merge branch (historical) | `arena/01a08828-doctor` — implementation `3fc5a54` — closure docs `becc82f` — integrated via #14 |

**Post-merge gates on `248ca10` (`origin/main`, push event — all SUCCESS, verified live 2026-09-11):**

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
- Geography foundation → primarily C8 / Phase 4 Master Data
- Jalali is UX/display; internal timestamps stay UTC

---

## I. Quality

WPCS (changed-lines) · PHPStan · Unit · Integration · Real-WP · Pilot · Closure · security · performance · compatibility · i18n.

CODE + TEST + DOC + MIGRATION together where relevant.

User-facing strings: WordPress i18n-ready. Machine `CLINIC_*` codes stay stable.

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
  **C7 implementation remains NOT STARTED.** Location authorization untouched.

**Do not start C7, C8, Phase 3, Phase 4, portals, or mobile auth/JWT.**

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
- Tripwire: `bin/tenant-tripwire.py` (CI-wired, 59 self-tests)
- [`docs/handoff/phase2-c6-to-next-agent.md`](handoff/phase2-c6-to-next-agent.md)
