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

**Last verified implementation SHA:** `6e5d48c801e86779c0daf350d68c9df49e49a6c8`  
(C6-E3 Reports + Export + Pilot/bin tenant-literal cleanup. Trusted REST `ScopeContext` is **not** implemented at this SHA.)

**Schema:** current version **`2026_09_09_0020`**. File `0021` does **not** exist. **Migration 0021 is NOT approved.** If new schema is required: STOP and ask Owner.

**This Arena session**

| | |
|---|---|
| Branch | `arena/01a086ca-doctor` |
| Draft PR | [#12](https://github.com/bia2on2on/doctor/pull/12) — OPEN DRAFT — DO NOT MERGE / CI execution |
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

**Already done (do not redo):**

- C6-A census · C6-B Notifications/SMS/Jobs · C6-C Booking/Schedule
- C6-D Patients/Clinical/Visits/Files · C6-E1 Settings/Audit/Idempotency + **Migration 0020**
- C6-E2 repository writes (clinic-first) @ `9e006b0`
- **C6-E3 Reports** @ `1c82d26` (+ Class D test follow-up `a120a68`) — trusted clinic filter, not `clinic_id=1`; OWN = that Clinic + one Clinician profile (`u_clinician_user`)
- **C6 Export** @ `f2c0ca6` — same Clinic on request → job payload → storage → notification → list/download/purge (per-row clinic)
- **Pilot/bin** @ `6e5d48c` — resolve real Clinic/Location IDs; never encode Clinic ID == 1

**Remaining C6**

1. Trusted REST context (membership-verified `ScopeContext`) — **not** Phase 3 / not `AuthorizationService`
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
