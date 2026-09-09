# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **State based on implementation SHA:** `6e5d48c801e86779c0daf350d68c9df49e49a6c8`

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

**Last verified implementation SHA:** see §K — the trusted‑REST boundary landed after `6e5d48c`
(`6e5d48c` → docs sync → `f88fcdc` boundary → repair batch). C6‑E3 Reports + Export +
Pilot/bin tenant‑literal cleanup were verified at `6e5d48c801e86779c0daf350d68c9df49e49a6c8`.

**Schema:** current version **`2026_09_09_0020`**. File `0021` does **not** exist. **Migration 0021 is NOT approved.** If new schema is required: STOP and ask Owner.

**This Arena session**

| | |
|---|---|
| Branch | `arena/01a086ca-doctor` (PR #12 tip `f88fcdc`) → این batch ترمیم روی `arena/01a086b4-doctor` ادامهٔ **خطی** همان تاریخچه است (FF از `main`؛ بدون cherry‑pick/merge/force‑push؛ شاخهٔ راه دورِ PR جابه‌جا نشد) |
| Draft PR | [#12](https://github.com/bia2on2on/doctor/pull/12) — OPEN DRAFT — DO NOT MERGE / CI execution · [#13](https://github.com/bia2on2on/doctor/pull/13) — OPEN DRAFT، تشخیصی (base = `arena/01a086ca-doctor`) فقط برای اجرای گیت‌ها روی ترمیم |
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
3. C6-F real multi-tenant isolation suite — **PARTIAL** (Reports/Export/Membership/Identity/Scope tests exist; no comprehensive 14-item suite) — **do not expand fully in the trusted-REST checkpoint**
4. Keep docs in sync after each verified implementation SHA (this file / census / phase2-state)

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
