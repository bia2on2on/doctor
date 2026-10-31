# Classic V1 audit and reception investigation — NOT ACCEPTED

Base: `d2c9f779df47f45137b5ffe9c8bc2dce2451f404`. The installer report
`CPMS_CLASSIC_V1_ACCEPTANCE_d2c9f77.md` was not available in this workspace.
The reported five broken links (IDs 88, 103, 140, 141, 143 of 674 rows under
25/50-way booking) are **installer-reported**, not independently reproduced.
No real CPMS database, WordPress site, MySQL 8 or MariaDB 11.4.13 was available
in this workspace. No concurrent requests or clinical/financial regression
suites were executed. This is a draft investigation, not remediation acceptance.

## Audit observations and unresolved cause

* `src/Infrastructure/Audit/AuditLogger.php::log` wraps the write in
  `CpmsDb::transactional`, reads `ORDER BY id DESC LIMIT 1 FOR UPDATE`, then
  computes and inserts the new row. `CpmsDb` implements nested transactions as
  savepoints; the outer transaction retains locks until its final commit or
  rollback. Thus the lock's lifetime can include additional business work.
* The last-row selection is a **plausible** race: a moving tail/empty chain is
  not the same as a fixed serialization point; actual behavior depends on
  engine, isolation, execution plan, concurrent insert and transaction timing.
  No specific lock mechanism or root cause is confirmed without reproduction.
  `fetchRow` also returns null on SQL failure in non-strict mode; this deserves
  separate fail-closed investigation, not an assertion that it caused the report.
* `HashChain::verify` recomputes using the prior actual hash but does not compare
  the stored `prev_hash`. `AuditLogger::verifyChain(limit)` loads only the last
  N rows and starts the computation at genesis, so a valid suffix without the
  preceding anchor can appear broken; `broken_at` is a position, not a DB ID.
  These are code observations, not proof of the installer's five links.
  `tools/audit-chain` supplies a separate read-only snapshot checker, tested on
  synthetic streams only; its exporter remains untested against a real DB.
* No audit history has been deleted, repaired, rehashed or rewritten. No
  production lock change is proposed as accepted without real engine tests.

A candidate design to evaluate is a pre-existing singleton InnoDB guard row
locked **inside the same outer transaction** before reading a current tail and
inserting; its creation/migration, failure handling, empty-chain behavior,
current-read semantics, lock ordering with business rows, nested rollback,
mixed old/new writers during upgrade, and cross-engine behavior all require
explicit tests. Connection-level advisory locks released by an inner call are
not a substitute for an outer transaction lock. Even a correct global guard can
introduce deadlocks or unacceptable contention, so measure and investigate
rather than assuming success.

Required before acceptance, on both specified engines and the exact PR SHA:
run synchronized 10/25/50 booking requests including same-slot and idempotent
retries, capture every status and DB error, timings (baseline and p50/p95/p99),
capacity and duplicate assertions. Add overlapping production clinical writes,
SMS jobs and financial operations, including commit/rollback, empty chain,
nested transactions and repeated audit events. Record lock waits/deadlocks and
rerun the complete snapshot checker over each run; compare the exact count,
each persisted predecessor and every hash. Test failure injection and upgrade
with no historical mutation. Do not equate synthetic tests with this evidence.

## Reception (code evidence; not reproduced)

`ReceptionPortalController::eligible_clinicians` inner-joins
`cpms_clinician_locations` to an active Location in the trusted Clinic, an
active clinician and active Clinic membership, with Location scope enforced.
An empty assignment table necessarily makes that clinician selector empty;
several walk-in/booking paths recheck eligibility. This establishes the selector
failure from code, not that every existing queue endpoint is unusable. It must
not be 'fixed' by bypassing tenant or membership checks.

`FacilitySetupService::assign_clinician` contains an audited, transactional,
explicit assignment path with Organization, Clinic, membership and location
validation; `FacilitySetupPage` describes its multi-clinic journey and says
single practices use the seven-step setup wizard. No simple single-practice
recovery path was implemented or tested here. A proper recovery should let an
explicitly authorized Clinic manager select a verified active clinician and
active Location in the same Clinic, use existing membership validation and
atomic audit, and show Persian guidance to reception without leaking other
clinics. WordPress Administrator status alone must never confer clinical or
Clinic authority. Test empty assignments, 0/1/multiple locations, suspended
memberships, cross-tenant selection, duplicate/retry, audit failure rollback,
and both browser and mobile/RTL workflow.

## Clinical UX assessment (inspection, not usability testing)

`ClinicalService::createPrescription` creates structured items transactionally;
`validateRxItem` requires generic name, dose and frequency, validates form,
route and optional duration, and stores source `manual`. It checks an optional
reference ID exists, but no dependable drug-reference search or curated source
was identified in the inspected code. `cpms_drug_reference` exists; its mere
existence does not establish populated, licensed or clinically reliable data.
Prescription draft/finalize/void and finalized history/print paths exist.
Handwriting documents/pages and their editor exist separately. Notes have
categories such as chief complaint, history, examination and diagnosis;
medication and other allergy JSON are presented in patient data. These are not
verified medication-allergy matching, interaction alerts, structured vital
observations, or prior-prescription *reuse*. No claim of complete absence across
all code is made without a broader clinical review.

Suggested staged V1 plan, requiring clinician safety review:

1. First make existing manual entry safer and clearer: Persian RTL and touch
   usability, explicit units and route, patient identity and allergy information
   visible with unknown vs recorded-none distinguished, clear review before
   finalization, and no claim that interactions are screened. Validate with
   clinicians, including narrow/mobile/tablet and accessibility testing.
2. Add clinician-scoped favorites/templates and prior-prescription copy to a
   **new draft**, never silent reissue: confirm patient/context, show source and
   dates, require deliberate review of each dose, route, duration and allergy.
   Preserve permissions, clinical audit and immutable finalized history.
3. Add structured observations with validated units, time, provenance,
   correction/version history and scope. Establish clinical ranges and display
   policy before any interpretation or alerts.
4. Introduce medication search only with an authoritative licensed, versioned,
   maintained reference and a safe update/rollback process. Allergy or
   interaction decision support requires reviewed mappings, clinically
   validated rules, provenance and ongoing governance; until then clearly
   disclose it is unavailable rather than generating unreliable alerts.
