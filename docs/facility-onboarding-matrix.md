# 12-facility frontend onboarding — browser matrix slice

Receipt: 2026-10-21T19:00Z · Base: `048b1ba4aff8839ff7067de5d1cc7e4932e8d237` (verified equal to `main`
at start; no open PRs, branch clean) · Branch: `arena/e0970f70-doctor` · Draft PR: #259.

> **Status:** the matrix **executed and passed on both engines** at head
> `30c630829afed13fa70f62a30a26915ed2b2f7da` — `Facility matrix (mysql)` 7m13s and
> `Facility matrix (mariadb)` 5m43s, both `success`, in run
> [39662236441](https://github.com/bia2on2on/doctor/actions/runs/39662236441). Because the harness
> exits non-zero if **any** per-type check fails, a green job means every check of every scenario
> passed. The per-check JSON artifact could **not be downloaded from the authoring sandbox**
> (Actions artifact/log blob storage returns `EOF` there); reviewers can download
> `facility-matrix-mysql-*` / `facility-matrix-mariadb-*` from that run.

## 1. What existed before this slice (verified, not assumed)

`docs/onboarding-facility-wizard.md` and the repository's harnesses were inspected first:

| Surface | Evidence of prior coverage |
|---|---|
| Catalogue of 12 types | `FacilityTypeCatalog::all()` — 12 ids, unchanged by this slice |
| Adaptive flow rules | `FacilityTypeCatalog::plan_rules()` (required clinicians, booking, ack, auto-site) |
| Plan service | `FacilitySetupService` (sites, held Clinics, designation, rooms, clinicians, schedules, booking, review, atomic activation) |
| Frontend portal | `templates/management-portal-shell.php` + `ManagementPortalService` (Organization and Clinic surfaces, no wp-admin) |
| Browser E2E, per type | **doctor_practice, general_clinic** (`rwp-portal-delegated-journey.py`), **hospital** (`rwp-portal-hospital-journey.py`), **medical building (structure)** (`rwp-first-practice.py`, wp-admin wizard) |
| Browser E2E, other 9 types | **absent** — only service-level Integration (`FacilityTypeAdaptivePlanTest`) |

So "all 12 types onboard through the frontend" was an inference from the catalogue plus service
tests, not evidence. This slice closes that gap with a dedicated matrix.

## 2. Delivered

1. **`clinic-practice-management/bin/rwp-facility-matrix-journey.py`** — real-browser,
   real-HTTP-submission journey for **all 12 approved types** + the **medical-building
   structure** (structure of an approved type; no 13th type is introduced).
   Per scenario: select type → facility identity → site(s) → held Clinics → explicit
   Subscriber-manager designation → delegated Location / clinicians / weekly schedules /
   booking policy → consolidated review → atomic activation → persisted-scope readback.
   Adaptivity is asserted per type: 1 vs 2 scheduled clinicians, booking required/not,
   hospital acknowledgment, 2 sites + 2 Clinics + **2 distinct** managers, compact auto-site,
   exactly 12 type options.
2. **`.github/workflows/facility-matrix-acceptance.yml`** — MySQL 8 **and** MariaDB 11.4.13,
   official release ZIP, isolated WordPress, pristine-tenant gate, per-type digest + artifacts.
   Steps are granular because Actions logs could not be read from the authoring sandbox.
3. **Product fix + regression test** — the portal no longer renders the explicit Clinic-manager
   designation form as a dead end with an empty Clinic `<select>`; it discloses the real
   prerequisite and returns as soon as a held Clinic exists.
   `DelegatedClinicCompletionTest::testDesignationFormIsNotOfferedBeforeAPlanClinicExists`.
4. **Evidence-integrity fix** — harness outcomes are classified from the notice element
   (`is-success` / `is-error`), never from Persian keywords. «ثبت نشد» contains «ثبت»; a
   substring classifier can read a refusal as success.

## 3. Security invariants preserved (no change requested or made)

- Only WordPress Administrators use wp-admin; the harness drives business Subscribers through
  the frontend portal and never opens wp-admin except **one negative check** that a Subscriber
  is refused there.
- Trusted target Clinic scope is resolved before object lookup; cross-Clinic reads are refused
  without disclosure (asserted across Organizations in the matrix).
- DENY > GRANT stays in `AuthorizationService` / `OrganizationClinicProvisioningService`;
  no product authorization file was modified.
- No new WordPress role/capability, no new migration, no change to licensing, suspension,
  readiness, booking or atomic activation.
- MariaDB 11.8 compatibility gates untouched; Persian WooCommerce Stage B untouched;
  Modern Skin not started.

## 4. Local validation actually executed in the sandbox

| Check | Result |
|---|---|
| `php-parser` syntax parse of every changed PHP file | PASS |
| `python3 -m py_compile` of every harness under `bin/` | PASS |
| `acceptance-isolation-check.py` on the new harness (BrowserContext per persona) | PASS |
| `git diff --check` | PASS |
| `test_mariadb_compat_report.py` (27 tests) | PASS |
| Persian coexistence suite (75 tests, 1 skipped) | PASS |
| YAML parse of the new workflow | PASS |

**Not executable here:** PHP, MySQL/MariaDB, Apache, Chromium, Playwright, Composer. The sandbox
has no PHP binary, no database server and no browser; outbound hosts are restricted to GitHub and
package registries. Actions logs were also unreachable (`EOF` from the logs blob endpoint), so CI
diagnosis had to proceed from job/step names only.

## 5. CI evidence observed for this slice (exact heads)

| Head | Check | Result |
|---|---|---|
| `cd35e44` | WPCS (changed code) | PASS |
| `cd35e44` | Static Analysis (PHPStan) | PASS |
| `cd35e44` | Unit Tests (PHP 8.1 / 8.2 / 8.3 / 8.4) | PASS |
| `cd35e44` | Tenant Tripwire | PASS |
| `e4c4109` | WPCS (changed code) | PASS |
| `e4c4109` | Static Analysis (PHPStan) | PASS |
| `30c6308` | **Facility matrix (mysql)** | **PASS** (7m13s) |
| `30c6308` | **Facility matrix (mariadb)** | **PASS** (5m43s) |
| `30c6308` | Integration (WP 7.1 + MySQL 8) — includes the new portal regression test | PASS (6m13s) |
| `30c6308` | Real WP Acceptance (prefix `wp_` / `clinic_`) | PASS |
| `30c6308` | Release Artifact (build + policy) · Upgrade path | PASS |
| `30c6308` | Closure gate (PHP 8.1/8.3/8.4, destructive restoreApply) | PASS |
| `30c6308` | Persian coexistence matrix (all jobs reported) | PASS |
| `30c6308` | First-practice browser (mysql / mariadb) · Responsive smoke · Staging Gate | NOT RUN at handoff (queued) |

### Per-type browser result (both engines)

Both matrix jobs passed, so the harness's own per-type digest (printed as
`PASS <type> (N checks)` and in `facility-matrix-results.json`) reported zero failures for:
`doctor_practice`, `general_clinic`, `specialized_clinic`, `dental_clinic`,
`diagnostic_center`, `rehabilitation_center`, `mental_health_center`,
`specialized_outpatient_center`, `limited_surgery_center`, `hospital`,
`comprehensive_health_center`, `other`, plus `structure_medical_building`.

| Facility type | MySQL 8 | MariaDB 11.4.13 |
|---|---|---|
| doctor_practice (compact solo, auto site, 1 clinician) | PASS | PASS |
| general_clinic (2 clinicians) | PASS | PASS |
| specialized_clinic | PASS | PASS |
| dental_clinic | PASS | PASS |
| diagnostic_center | PASS | PASS |
| rehabilitation_center | PASS | PASS |
| mental_health_center | PASS | PASS |
| specialized_outpatient_center | PASS | PASS |
| limited_surgery_center | PASS | PASS |
| hospital (ack · 2 sites · 2 held Clinics · 2 distinct managers · outpatient only) | PASS | PASS |
| comprehensive_health_center | PASS | PASS |
| other | PASS | PASS |
| medical-building **structure** (2 independent Clinics, no booking/clinician prerequisite) | PASS | PASS |

**Evidence boundary:** PASS here means the browser journey completed, every per-type assertion in
the harness held, and the persisted-scope readback matched on live rows. It does **not** mean the
per-check JSON was read by a human in this sandbox — that file is a downloadable CI artifact.

## 6. Defects found in this slice's own CI wiring (fixed, disclosed)

1. ZIP build/install were one step; failure attribution was impossible without logs → split into
   *build* / *install+activate* / *schema+fatal* steps.
2. `wp-content` was chowned to `www-data`, so the runner user could not write
   `wp-content/plugins` for a CLI ZIP install → ownership stays with the runner; Apache only needs
   read access plus a writable `wp-content` for logs/uploads.
3. The pristine-tenant gate required `cpms_clinics == 0`, but migration `0001` deliberately seeds
   the legacy default Clinic. The gate now asserts **zero memberships** (the real "no pre-seeded
   Clinic authority" contract) and **records** the migration baseline.

These are test-infrastructure defects, not product defects. They are recorded rather than hidden
because three red runs were caused by them.

## 7. Remaining work (explicit)

- First-practice browser (mysql/mariadb), Responsive smoke and Staging Gate were still queued at
  handoff; they must be green on the final head before merge review.
- The matrix does not cover: hospital third+ Clinics, staff/role assignment per type (covered by
  `ManagementPortalTest`), patient-facing booking after activation per type.
- If any per-type failure appears on a later head, fix the product defect it exposes (or the
  harness, if it is a harness defect) — do not weaken an assertion to reach green.
