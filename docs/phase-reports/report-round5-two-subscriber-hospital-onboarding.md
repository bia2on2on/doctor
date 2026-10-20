# Round 5 — Two-Subscriber hospital onboarding through the frontend portal

- **PR:** #255 `bia2on2on/doctor` (Draft, not merged)
- **Branch:** `arena/4be58c19-doctor`
- **Head SHA of this round (code under test):** `6ff07e8` — this document is committed on top of it
  as a docs-only commit (`195c940`); all CI results quoted below were produced on `6ff07e8`, and no
  code differs between the two.
- **Base:** `main` @ `6c64a91`
- **Receipt (UTC):** 2026-10-20T18:36Z · **report written:** 2026-10-20T20:47Z
- **Verdict: BLOCKED** — the two-Subscriber Chromium journey does **not** pass on the exact head SHA.

---

## 1. What this round was asked for

An existing frontend hospital onboarding wizard must be genuinely completable by **two separate
Subscriber users**:

1. an Organization Manager creates an outpatient hospital, a physical site, and two held Clinics;
2. designates a **different** Subscriber as Clinic Manager for each Clinic;
3. each Clinic Manager completes only their own Clinic (Locations/rooms, clinicians, staff,
   weekly schedules, booking policy);
4. the Organization Manager reviews the real result and activates both Clinics **atomically**;
5. everything through frontend CPMS forms — no `wp-admin`, no privileged WordPress roles.

Security model to preserve: fail-closed scoped authorization, no implicit Clinic rights for
Organization Managers, no cross-Clinic access, no global WP capability fallback, audit + retry
safety, lifecycle protection, atomic activation.

Acceptance: a complete controller-driven integration test with two distinct manager identities, and
a genuine Chromium journey on real WordPress with Subscriber accounts and rendered forms.

---

## 2. Completed work — hospital steps that now work end-to-end

### 2.1 Security/authorization fix (`084fece`)

`FacilitySetupService::assert_clinic_step()` previously resolved plan-Clinic ownership and scope
**before** any lifecycle read, so a Clinic of a **suspended Organization** could still be
configured. The order is now:

1. actor id and organization id must be positive → otherwise `denied`;
2. the Organization must exist and be **active** (lifecycle gate, no Organization *authority*
   required — that is a separate, stricter check);
3. only then plan-Clinic ownership (`clinic_entry()`) and the scoped Clinic membership/CONFIG check.

This restored the suspended-Organization regression fixture without weakening authorization:
Organization **status** gates every Clinic step, Organization **authority** is still never implied.

### 2.2 Controller-driven integration evidence (green on the head SHA)

- `clinic-practice-management/tests/Integration/HospitalTwoManagerJourneyTest.php`
  - two distinct Subscriber identities (Organization Manager + two different Clinic Managers),
  - Organization Manager creates hospital → acknowledgement → site → two held Clinics,
  - designation by login/e-mail (never by numeric id in the frontend path),
  - each Clinic Manager completes location, clinician, weekly schedule and booking policy in **their
    own** Clinic,
  - the Organization Manager reviews readiness and activates both Clinics atomically,
  - cross-Clinic denial assertions: Manager A cannot write in Clinic B and vice versa,
  - the Location-count assertion is now **relative** to the Clinic's own baseline (a plan Clinic is
    created with one primary Location by `create_held_clinic()`), not an absolute count.
- `tests/Integration/FacilitySetupJourneyTest.php` — suspended-Organization fixture (green again
  after `084fece`).
- Booking policy is read through `Settings->get('booking.duration_default_min')`; the raw `value`
  column does not exist (settings are stored in `value_json`).

**Integration result on `6ff07e8`: PASS — 2127 tests, 59960 assertions, 0 failures, 0 errors**
(WP 7.1 + MySQL 8). WPCS (changed code) **PASS**, PHPStan **PASS**.

### 2.3 Real-browser harness (Chromium, real WordPress, Subscriber accounts)

`clinic-practice-management/bin/rwp-portal-hospital-journey.py` (new, ~470 lines) drives the
frontend portal only (`wp-admin` is never opened):

- seeds **only** the tenant root (one Organization row + one ACTIVE `organization_manager`
  membership); every wizard step afterwards is a rendered-form interaction;
- three independent `BrowserContext`s (one per Subscriber) with isolated cookie jars;
- desktop run plus a 390 px RTL overflow check;
- assertions prove no WP business role is granted and no wp-admin link is rendered;
- `FACTS=` JSON read through `wp eval-file` proves the database end-state (Clinic status, Location
  count, schedule count, booking policy).

CI wiring (`.github/workflows/first-practice-acceptance.yml`):

- lint step compiles the new script;
- `ORG_MANAGER_*` / `CLINIC_MANAGER_A_*` / `CLINIC_MANAGER_B_*` credentials;
- step 15 "Isolated Subscriber personas for the portal journey" — creates/looks up three
  Subscribers, asserts WP role `subscriber`, asserts zero global CPMS capabilities in usermeta;
- step 16 "Browser — hospital onboarding by two Subscriber managers (frontend portal only)";
- failure diagnostics: an ordered failure digest + the first (root-cause) failure are emitted as
  check-run annotations, because the job log is not retrievable from this environment.

### 2.4 Steps proven by the Chromium journey on `6ff07e8`

| Step | Evidence | Result |
|---|---|---|
| Organization Manager Subscriber logs into the frontend portal | `org.login` | PASS |
| No wp-admin link on the portal | `org.no_wp_admin_link` | PASS |
| Facility type = hospital + `hospital_network` structure | `org.plan_is_hospital` | PASS |
| Outpatient-only acknowledgement | `org.ack.submitted` | PASS |
| Two physical sites | `org.site.<label>.submitted` | PASS |
| Clinic A created (held) | `org.clinic.درمانگاه قلب.submitted` | **FAIL** |
| Clinic B created (held) | `org.clinic.درمانگاه پوست.submitted` | **FAIL** |
| Designation of a different Subscriber per Clinic | `org.designate.*` | FAIL (not reached) |
| Each Clinic Manager completes their own Clinic | `mgrA.*`, `mgrB.*` | FAIL (login: no surface) |
| Organization review + atomic activation | `org.review_ready`, `org.activate.*` | FAIL (not reached) |
| DB end-state (`FACTS=`) | `db.*` | FAIL (not reached) |

---

## 3. Browser acceptance: **FAIL** (blocking defect, not a harness artifact)

Failure point: the **first held-Clinic creation** (`org_facility_clinic`).

Captured evidence from the check-run annotations on `6ff07e8`:

```
portal.first_failure :: org.clinic.درمانگاه قلب.submitted
    :: notice=; url=http://localhost:8080/?page_id=10 actions=[] notice=
portal.failed_checks(9 elsewhere) :: ...
    mgrA.login=http://localhost:8080/?page_id=10&notice=login_failed
    mgrB.login=http://localhost:8080/?page_id=10&notice=login_failed
    org.activate.form_present=no form for org_facility_activate
    browser.platform_managers_ready=A=False B=False
    browser.no_console_errors=count=2
```

What this means, precisely:

- the form **was** found and the browser **did** submit it (`native_validation` never fired, so no
  HTML5 constraint blocked the POST);
- after the POST the browser sits on the **bare** portal URL — all query arguments are gone —
  and the rendered document contains **zero** `cpms_action` inputs: neither the wizard, nor the
  activation panel, nor even the portal login form;
- two browser console errors are recorded on that page;
- it is **not** the "critical error" page (the harness would have reported
  `*.no_critical_error`), and **not** an authorization denial (a denial redirects with
  `notice=denied`, which renders a notice with text — the notice element is absent entirely).

Because the Organization Manager's earlier steps (start, acknowledgement, both sites) navigate
through the same submit/redirect path and render correctly, the failure is specific to the state
that exists **after a held Clinic has been provisioned** (a held Clinic plus its primary Location
now exist in the Organization). The most likely cause is a render-time failure in the portal
sections that are evaluated once a plan Clinic exists
(`facility_selectors()` → `can_configure` filtering, `review_facility_setup()`, or the
`$cpms_mp_facility['clinics']` rendering block), which would leave the page without its forms even
though `is_portal_request()` remained true. **This is inference, not proof** — I could not read the
job log (artifact download is blocked in this environment; see §6) and therefore cannot show the
PHP-side stack.

Secondary consequences of the same defect: the Clinic Managers never receive a surface
(`login_failed` is correct fail-closed behaviour for a Subscriber with no Clinic membership yet, but
here it is a *consequence* of the missing designation), and the review/activation/DB assertions are
never reached.

---

## 4. CI state on the head SHA `6ff07e8`

| Workflow | Result |
|---|---|
| Integration (WP 7.1 + MySQL 8) | **PASS** — 2127 tests, 59960 assertions, 0F / 0E |
| WPCS (changed code) | **PASS** |
| Static Analysis (PHPStan) | **PASS** |
| First-practice browser (MariaDB 11.4.13) | **FAIL** — step 16 portal journey only; steps 14, 15, 17–22 PASS |
| First-practice browser (MySQL 8) | **FAIL** — step 16 portal journey only; steps 14, 15, 17–22 PASS |

No test was weakened or deleted to obtain green CI. The only test-side changes are: a legacy fixture
corrected (Settings API instead of a non-existent raw column), a relative Location-count assertion,
the suspended-Organization regression restored through the **service** fix rather than through the
tests, and diagnostic instrumentation in the new harness.

---

## 5. Security posture (unchanged and re-verified by the integration suite)

- Clinic-level writes require **ACTIVE** Clinic membership + `CONFIG` in that Clinic;
- designate / review / activate / site creation / Clinic creation are Organization-only;
- designation never changes a WordPress role (asserted in both integration and browser steps);
- a Subscriber with no durable CPMS surface cannot log into the portal (fail-closed, no probing
  information: the same generic `login_failed` notice is shown);
- Church-of-lifecycle ordering: Organization `status = active` gates every Clinic step, but
  Organization authority alone never grants it;
- no `read`-level loosening of any wp-admin menu/page; business users are never given wp-admin.

---

## 6. Remaining blockers and gaps

1. **BLOCKER — portal renders an empty document after the first held Clinic is created** (§3). The
   wizard therefore cannot be completed by two Subscribers in a real browser. Needs the PHP-side
   stack; the workflow must publish the step log (artifact or PR comment) so this is not blind
   debugging. The in-step `gh pr comment` mirror does not produce a comment in this environment.
2. **Agent-side job log retrieval is blocked** here (`actions/jobs/<id>/logs` returns an EOF/blob
   error; the artifact API host is not reachable), so all diagnosis relies on check-run
   annotations. The harness now emits an ordered failure digest, the first failure in full, the
   rendered `cpms_action` inventory, and native-validation details precisely to work around this.
3. Designation, per-Clinic completion by two managers, atomic activation and the `FACTS=` DB
   assertions are **NOT RUN** in the browser — they are only proven by the controller-driven
   integration test.
4. Mobile/RTL evidence (390 px) is generated by the harness but was never reached.
5. Out of scope this round, unchanged: MariaDB baseline pins, PR #256.

*(A harness-seeded Organization is still required because this product has no self-service
Organization creation; that is the only pre-seed and it is asserted to carry exactly one ACTIVE
`organization_manager` membership for the Organization Manager Subscriber.)*

---

## 7. Verdict

**BLOCKED.**

- Controller-driven two-manager hospital journey: **PASS** on `6ff07e8` (Integration, 0F/0E).
- Real Chromium, real WordPress, Subscriber accounts and rendered forms: **FAIL** at the first held
  Clinic creation; the remaining hospital steps are **NOT RUN**.
- Exact-head CI is not fully green, independent security/product acceptance is not complete, and
  real browser acceptance evidence does **not** exist.

PR #255 therefore stays **Draft**. No merge.
