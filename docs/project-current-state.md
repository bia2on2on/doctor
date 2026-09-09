# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **State based on implementation SHA:** `9e006b0cab96ddeb050d343de3cba6b4e7cb4aa2`

If Git/remote/PR, this file, and the repository tree disagree: **STOP**.

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
| Phase 2 | **IN PROGRESS** — current subphase **C6** |
| Phase 3 | **NOT STARTED** — no `AuthorizationService`; do not start |

**Last verified implementation SHA:** `9e006b0cab96ddeb050d343de3cba6b4e7cb4aa2`  
(C6-E2 follow-up: explicit clinic parameter on repository writes + SetupWizardTest caller.)

**This Arena session**

| | |
|---|---|
| Branch | `arena/01a086ca-doctor` |
| Draft PR | [#12](https://github.com/bia2on2on/doctor/pull/12) — DO NOT MERGE / CI execution |
| Base | `main` (`8087b42`) |

**Previous PRs — keep OPEN + DRAFT; do not merge or close**

| PR | Branch | Role |
|---|---|---|
| [#10](https://github.com/bia2on2on/doctor/pull/10) | `arena/01a0808c-doctor` | Phase 1A |
| [#11](https://github.com/bia2on2on/doctor/pull/11) | `arena/01a082db-doctor` | OD-9 + Phase 2 through C6-E2 @ `9e006b0` |

**Last verified gates on `9e006b0` (via PR #11)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | `34352114628` |
| Real-WP (PR) | `34352114325` |
| Real-WP (push) | `34352109691` |
| Pilot/Staging | `34352109661` |
| Closure | `34352109682` |

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
- Do not merge/close PR #10, #11, or this session’s Draft PR

A future Agent must: (1) read this file (2) verify Git/remote/PR (3) verify linked docs (4) compare to the repo (5) **STOP** on material mismatch.

---

## K. Current C6 state

Canonical inventory: [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)  
Phase 2 queue: [`docs/phase-reports/phase2-state.md`](phase-reports/phase2-state.md) (file text still lags at C5/`315e582` — Git/CI on `9e006b0` is authoritative for C6-E2).

**Verified at `9e006b0` (tripwire, empty allowlist):**

- Baseline (C6-A): 68 production executable hits
- Remaining production runtime: **12** Report/Export hardcodes
  - `ReportService` ×10 SQL `clinic_id = 1`
  - `ExportService` `store(..., 1, ...)` + purge `clinic_id = 1`
- Remaining bin: **3** tripwire hits (`pilot-seed` / `pilot-smoke` `WHERE clinic_id = 1`) plus additional positional `VALUES (1, …)` INSERTs the regex does not all catch
- 7 comment-only occurrences (non-executable)

**Already done (do not redo):** C6-A census · C6-B Notifications/SMS/Jobs · C6-C Booking/Schedule · C6-D Patients/Clinical/Visits/Files · C6-E1 Settings/Audit/Idempotency + **Migration 0020** · C6-E2 repository writes (clinic-first).

**Remaining C6**

1. Reports — one trusted Clinic per run; OWN = that Clinic + one Clinician profile (`u_clinician_user`)
2. Export — same Clinic on request → job payload → storage → notification → list/download/purge
3. Pilot/bin — resolve real Clinic/Location IDs; never encode Clinic ID == 1
4. Trusted REST context (membership-verified `ScopeContext`) — **not** Phase 3
5. Tripwire hardening + CI wiring
6. C6-F real multi-tenant isolation suite
7. C6-G docs sync (`c6-census`, `phase2-state`, this file)

**Migration 0021 is NOT approved.** If new schema is required: STOP and ask Owner.

**Do not start C7, C8, or Phase 3.**

---

## Linked canonical docs

- [`docs/roadmap/roadmap.md`](roadmap/roadmap.md)
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
- [`docs/architecture/phase0.5-target-model.md`](architecture/phase0.5-target-model.md)
- [`docs/security/phase1b-deferred-register.md`](security/phase1b-deferred-register.md)
- [`docs/drift-register.md`](drift-register.md)
- [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)
- Tripwire: `clinic-practice-management/bin/tenant-tripwire.py`
