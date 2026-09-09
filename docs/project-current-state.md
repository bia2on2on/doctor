# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **State based on implementation SHA:** `c2bff76d1e21643a66bc0056a29881faaa2f299f`

If Git/remote/PR, this file, and the repository tree disagree: **STOP**.

This file describes verified implementation at `6e5d48c`. It does **not**
self-refer to the SHA of any later documentation-only commit.

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

---

## C. Current state

| Phase | Status |
|---|---|
| Phase 0 | CLOSED |
| Phase 0.5 | CLOSED |
| Phase 1A | CLOSED (`9bc6f7f`; OD-9 CLOSED) |
| Phase 1B | DEFERRED — scoped / object authorization (depends on Phase 2 + 3) |
| Phase 2 | **IN PROGRESS** — current subphase **C6** (**NOT complete**) |
| Phase 3 | **NOT STARTED** — no `AuthorizationService`; do not start |

**Last verified implementation SHA:** `c2bff76` (see §K). Chain: `6e5d48c` → docs sync →
`f88fcdc` boundary → repair batch (`4289d89`/`4606b15`) → **tenant‑hardening batch**
(`ddca8d7 → ba6ed3a → d5072ff → d52d32d → f6703dc → f5ebefd → b7b8399 → dc0022f →
815339f → bff690a → c2bff76`). All five gates GREEN on `c2bff76` — §G table. Legacy
internal label for this batch in some reports: «Phase 9 §5» — **task‑taxonomy wording
only**; the Owner roadmap's Phase 9 is Patient Portal and remains **NOT STARTED**.

**Schema:** current version **`2026_09_09_0020`**. File `0021` does **not** exist. **Migration 0021 is NOT approved.** If new schema is required: STOP and ask Owner.

**This Arena session**

| | |
|---|---|
| Branch | `arena/01a086ca-doctor` (PR #12 tip `f88fcdc`) → ادامه روی `arena/01a086b4-doctor`؛ tip فعلی `c2bff76`؛ خطی، بدون cherry‑pick/merge/force‑push |
| Draft PR | [#12](https://github.com/bia2on2on/doctor/pull/12) — OPEN DRAFT — DO NOT MERGE / CI execution · [#13](https://github.com/bia2on2on/doctor/pull/13) — OPEN DRAFT (base = `arena/01a086ca-doctor`) — head `c2bff76` — DO NOT MERGE / CLOSE |
| Base | `main` (`8087b42`) |

**Previous PRs — keep OPEN + DRAFT; do not merge or close**

Verified Git ancestry (unshallow + `merge-base --is-ancestor`): both heads are ancestors of `6e5d48c`. Histories of #10 and #11 are fully contained in this branch. Do not rewrite those branches.

| PR | Branch | Head | Role |
|---|---|---|---|
| [#10](https://github.com/bia2on2on/doctor/pull/10) | `arena/01a0808c-doctor` | `79cce4b` | Phase 1A |
| [#11](https://github.com/bia2on2on/doctor/pull/11) | `arena/01a082db-doctor` | `9e006b0` | OD-9 + Phase 2 through C6-E2 |

**Last verified gates on `6e5d48c` (via PR #12)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | `34375762362` |
| Real-WP (PR) | `34375762425` |
| Real-WP (push) | `34375756020` |
| Closure | `34375756075` |
| Pilot/Staging | `34375756043` |

**Last verified gates on `c2bff76` (via PR #13)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Unit×4 + PHPStan + WPCS changed‑lines + **Integration** 602 tests / 0E / 0F) | `34406996627` |
| Real-WP Acceptance (wp_ + clinic_ prefixes) | `34406991487` |
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
- Do not merge/close PR #10, #11, or this session’s Draft PR (#12)

A future Agent must: (1) read this file (2) verify Git/remote/PR (3) verify linked docs (4) compare to the repo (5) **STOP** on material mismatch.

---

## K. Current C6 state

Canonical inventory: [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)  
Phase 2 queue: [`docs/phase-reports/phase2-state.md`](phase-reports/phase2-state.md)

**C6 is NOT complete. Do not declare C6 PASS.**

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

**Remaining C6**

1. Trusted REST context (membership-verified `ScopeContext`) — **implemented and repaired in the batch above** (still not Phase 3 / not `AuthorizationService`); its remaining follow‑ups are the open route‑classification decisions, **not** the boundary itself
2. Tripwire hardening + CI wiring — **do not start in the trusted-REST checkpoint**
3. C6-F real multi-tenant isolation suite — **PARTIAL** (Reports/Export/Membership/Identity/Scope tests exist; no comprehensive 14-item suite) — **do not expand fully in the trusted-REST checkpoint**. The two executable specifications that exposed the remaining per-object gap are now **fixed and green**: `7c4b2bd` (prescription) and `2d13f2d` (SMS logs). The historical RED checkpoint is preserved, not erased (`5b3768e`/`41321e2`: `Tests: 568, Assertions: 2951, Failures: 2` — `testStaffCannotFinalizePrescriptionOfAnotherClinic`, `testSmsLogsAreScopedToTheBoundClinic`), classified **Class A (product, High)**: `ClinicalService::finalizePrescription` → `PrescriptionRepository::findForUpdate` was `WHERE id`-only, and `SmsService::logs` had no `clinic_id` filter although `cpms_sms_messages` carries a tenant column. Repairs keep the tenant predicate **inside SQL** (`findForUpdateForClinic`/`updateForClinic`; `logs(int $clinicId, …)` with `WHERE clinic_id = %d` for both `COUNT` and the `SELECT`), take the Clinic only from the trusted scope (no client-supplied ID, no Clinic‑1 fallback), and return the established `CLINIC_NOT_FOUND` 404 for both "missing" and "belongs to another Clinic" (no existence disclosure; `affected < 1` proves no mutation). The **cross-Clinic SMS dedupe suppression** (global `uq_dedupe` over a Clinic-blind `dedupe_key`) was confirmed and fixed schema-free by hashing the Clinic into the key plus a `clinic_id` predicate in the lookup. Remaining C6‑F items are still open (the **comprehensive** 14‑item suite; staff membership onboarding). Two named gaps above are since **closed** by the tenant‑hardening batch (§ below): `files/{id}/stream` clinical read path and the `VisitService::today`/queue/feed `clinic_id = 1` hardcodes — with a focused 25‑probe executable suite (`tests/Integration/ClinicTenantIsolationTest.php`), all green at `c2bff76`. **No Migration 0021 — none was required for correctness.**
4. Keep docs in sync after each verified implementation SHA (this file / census / phase2-state)

### Tenant‑hardening batch (`4606b15` → `c2bff76`, linear; all gates GREEN on `c2bff76`)

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

**Do not start C7, C8, Phase 3, Phase 4, portals, or mobile auth/JWT.**

---

## Linked canonical docs

- [`docs/roadmap/roadmap.md`](roadmap/roadmap.md)
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
- [`docs/architecture/phase0.5-target-model.md`](architecture/phase0.5-target-model.md)
- [`docs/security/phase1b-deferred-register.md`](security/phase1b-deferred-register.md)
- [`docs/drift-register.md`](drift-register.md)
- [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)
- Tripwire: `clinic-practice-management/bin/tenant-tripwire.py`
