# Staff messaging + doctor-managed visit pricing — implementation plan and threat model

**Status:** design / Stage 1 deliverable (2026-10-27, branch `arena/2e7b14d1-doctor`).
**Scope:** CPMS Classic, Iran V1. No Phase 20, no release/tag, no roadmap change.
**Baseline:** `origin/main` = `22a2cee1d7bfc0ff050aecbaa913b3f2d2d6863c`.
**Open-PR overlap check:** PR #277 (draft, `arena/3c5e41b4-doctor`) changes only
`.github/workflows/scoped-demo.yml`, `docs/scoped-demo/**`, `tests/scoped-demo/**`
and `tools/disposable-demo-installer/**`. No overlapping plugin files or
migrations. It is not modified by this work.

## 1. Evidence read (reusable components, no duplicate engines)

| Existing asset | Reuse in this work |
| --- | --- |
| `FinanceService::issueInvoice/recordPayment`, `InvoiceCalc`, `InvoiceRepository`, `PaymentRepository` | All invoice/payment writes and totals stay here. New pricing code only **builds input items** and delegates. |
| `AuthorizationService::can/authorize`, `RestClinicContext`, `TrustedClinicEstablisher`, `ScopeContext` | Clinic/Organization/Location authority, active membership, explicit DENY precedence. Never a client selector. |
| `AuditLogger::log` (append-only hash chain) | Fee/charge/thread/message mutations are audited; message bodies and PHI are never put in audit payloads. |
| `RateLimiter` (`App::rate()`), `RestBase::rateLimit`, ETag/polling precedent in `NotificationsController` | Authenticated incremental polling; bounded send/read rates. No WebSocket, no WP comments. |
| Staff Portal (`StaffPortalShell` hybrid server-rendered + REST modules), Phase 12 `FinancePortalController` | New modules appended to the existing registry; billing panel added to the delivered finance board. One top-level wp-admin menu «مدیریت مطب» is untouched. |
| `cpms_invoice_items` (`quantity DECIMAL(8,2)`, `unit_price`, `amount`, `discount`) | Treatment charge quantity is supported by the existing finance architecture. |

Money is an **integer Rial** amount (`IRR`), matching `FinanceService::toMinor()`
and `InvoiceCalc`. Every UI label says «ریال»; no toman conversion is performed.

## 2. Implementation plan (incremental commits)

1. **Migration `2026_10_27_0030`** (portable MySQL 8 / MariaDB 11.4 DDL,
   idempotent `SHOW`-style checks, `down()` for dev only):
   - `cpms_message_threads` — `clinic_id`, `organization_id`, nullable
     `location_id`/`patient_id`/`visit_id`, creator, timestamps.
   - `cpms_message_participants` — two rows per thread, `last_read_message_id`.
   - `cpms_messages` — append-only body + sender + timestamp.
   - `cpms_doctor_consultation_fees` — `(clinic_id, clinician_id, location_id)`
     unique; `location_id = 0` is the clinic-wide default (sentinel avoids
     MySQL/MariaDB multiple-NULL unique semantics).
   - `cpms_visit_consultation_fees` — per-visit override (PK `visit_id`).
   - `cpms_visit_charge_items` — draft service charges with `quantity`, `note`,
     `invoiced_invoice_id`, soft removal columns, audit-friendly before/after.
   No existing financial table is altered; existing paid/finalized invoices
   remain byte-identical.
2. **Messaging**: `MessageText` (pure sanitizer), `MessageRepository`,
   `StaffMessagingService`, `MessagingController` (`/clinic/v1/staff/portal/messages/*`),
   `staff-messages` portal module. Incremental `since_id` polling; unread counts
   from `last_read_message_id`; no delete/update API for message bodies.
3. **Pricing/charges**: `MoneyInput` (pure integer validation),
   `VisitPricingRepository`, `VisitPricingService`, `VisitPricingController`
   (`/clinic/v1/staff/portal/pricing/*`), `staff-pricing` portal module.
4. **Billing handoff**: `FinanceService::issue_invoice_from_pricing()` builds
   consultation + charge items and calls the existing `issueInvoice()` inside
   one transaction; draft charges are stamped with the resulting invoice id.
   `FinancePortalController` gains a read-only itemized billing projection and
   an auto-issuance route; the finance board gains the matching UI panel.
   Payment capture/void stay on the existing routes and capabilities.
5. **Tests/docs**: unit (pure PHP), WP/MySQL integration (service + REST +
   migration upgrade), Python real-Chromium acceptance at 1280px and 360/390px
   RTL; CI step for the new migration on MariaDB 11.4.13; API contract + this
   design doc + append-only agent log.

## 3. Authorization truth (server-side, on every read and write)

- Trusted Clinic comes **only** from `ScopeContext`/`RestClinicContext` (active
  membership + active Organization + validated Location). No payload clinic,
  no role name, no UI selection is authority.
- Messaging staff class is derived from **effective scoped capabilities** of an
  active membership (`cpms_consult_complete` = doctor class,
  `cpms_queue_checkin` = receptionist class), never from a WP role alone.
  An actor must additionally hold `cpms_visit_read` in the same Clinic.
- A thread is readable only by its two participants, inside the stored Clinic
  **and** Organization; if it has a Location, both participants and the actor
  must be eligible for that Location (clinic-wide membership or an explicit
  `cpms_membership_locations` row).
- Patient/visit-linked threads require **both** ids, a persisted Visit in the
  trusted Clinic, `visit.patient_id === patient_id`, a patient row in the same
  Clinic, and (for doctor actors) the server-derived clinician owning that
  Visit. The thread's Location is copied from the Visit, never from the client.
- Pricing writes require an active membership, `cpms_invoice_create` in the
  trusted Clinic, and a server-derived active clinician profile that equals
  the Visit's clinician. Reads require `cpms_invoice_read`.
- Charges/overrides are mutable only while the Visit has **no** active invoice
  and **no** captured payment, and the specific charge has not been stamped
  into an invoice. Settled invoices/payments are never mutated.
- Doctors are not granted `cpms_payment_create`/`cpms_payment_void`; the
  existing finance portal capture route keeps its `PAYMENT_CREATE` guard and
  the existing idempotency + `SELECT ... FOR UPDATE` transaction.

## 4. Threat model (STRIDE-lite) and mitigations

| # | Threat | Mitigation | Test evidence |
| --- | --- | --- | --- |
| T1 | Cross-clinic / cross-org thread id enumeration | Stored `clinic_id` + `organization_id` equality with trusted scope; participant-only reads; 404 parity for unknown/foreign ids. | `StaffMessagingSecurityTest` |
| T2 | Cross-location access by location-scoped staff | Thread Location derived from the persisted Visit; location membership verified for actor and counterpart on every operation. | `StaffMessagingSecurityTest` |
| T3 | Patient/visit reference forgery, PHI disclosure | Both ids required, patient↔visit↔clinic chain verified server-side; messaging payload contains no clinical note/record/medical file fields; audit logs store ids/lengths, never bodies or clinical text. | `StaffMessagingSecurityTest`, API contract |
| T4 | Stored XSS / oversized payload | Pure `MessageText` sanitizer strips tags + control chars and enforces a 2000-character bound; JS renders with `textContent`; REST output is plain text. | `MessageTextTest`, `StaffMessagingSecurityTest` |
| T5 | Spam / polling abuse | `RateLimiter` per actor: 20 sends/min, 120 reads/min, 120 polls/min; bounded `limit` (≤100) and index-backed `since_id` reads; no WebSocket. | `StaffMessagingSecurityTest` |
| T6 | Silent history loss / repudiation | Append-only messages, no destructive REST route; retention policy = keep for the Clinic lifetime under the existing backup/DR retention; no automatic purge; audit events for thread creation and each send. | `StaffMessagingSecurityTest`, API contract |
| T7 | Settled invoice / recorded payment mutation | Draft-only charge/override writes, invoice/payment rows untouched by pricing services, invoice-active guard, soft removal with audit. | `VisitPricingBillingTest` |
| T8 | Fee bleed across doctor/clinic/location/visit | Default keyed by `(clinic, clinician, location)`; override keyed by `visit_id`; Visit's own clinician/location/clinic always re-read from DB. | `VisitPricingBillingTest` |
| T9 | Double payment / doctor capture | Reuse `recordPayment` (UUID idempotency + unique key + row lock); no new capture path; doctor `PAYMENT_CREATE` denial asserted. | `VisitPricingBillingTest` |
| T10 | CSRF / unauthenticated REST | `X-WP-Nonce` + login + scoped capability in every route's `permission_callback`; no public route. | `StaffMessagingSecurityTest`, `VisitPricingBillingTest` |
| T11 | Currency ambiguity / float drift | Integer Rial only (`MoneyInput`), `IRR` label «ریال» on every amount, `InvoiceCalc` integer totals. | `MoneyInputTest`, `VisitPricingBillingTest` |

## 5. Retention policy (explicit)

Messaging history is **append-only**. There is no delete, edit, or purge REST
route; a removed charge is a soft-deleted row (never an `UPDATE` of an invoice).
Messages are retained for the life of the Clinic and are covered by the
existing installation backup/restore and audit retention policy. A future
purge, if ever required by law or the owner, must be a separate audited job
with an explicit Clinic-scoped decision — it is deliberately **not** invented
here (pre-coding rule 2: simplicity, no speculative abstraction).

## 5b. Migration head bump (coordinated, mechanical)

`2026_10_27_0030` becomes the new schema head. The repository pins that head in
unit/integration tests (`MigrationTest`, `Phase2SchemaTest`,
`CustomRole*MigrationTest`, `Phase10*`, `FinanceAuthorizationPolicyRegressionTest`,
`TempTableIsolationTest`, `MariaDb1011CompatibilityTest`), in the PHP upgrade
fixture (`tests/bin/upgrade-path-fixture.php`), in the external disposable-site
provisioner and in the PR workflows (`ci.yml`, `closure-gate.yml`,
`pilot-gate.yml`, `first-practice-acceptance.yml`, `real-wp-acceptance.yml`,
`disposable-site-acceptance.yml`, `mariadb-10.11-compat.yml`). Those pins were
updated to `2026_10_27_0030` together with the migration; rollback sequences
that enumerate versions gained the one new rollback step. A focused MariaDB
11.4.13 CI step runs `StaffMessagingPricingMigrationTest` on a real server.

**Known overlap (reported, not edited):** PR #277 (draft scoped-demo work)
pins `2026_10_18_0029` in `.github/workflows/disposable-demo-installer.yml`,
`tools/disposable-demo-installer/*` and its own `tests/bootstrap.php`. Those
are another agent's files and that lane is path-scoped, so they were left
untouched; that agent/owner must bump its supported CPMS schema pin when it
rebases onto this migration. No file of PR #277 was modified here.

The six new tables declare **no FOREIGN KEY**. Tenant integrity is enforced by
the services (trusted Clinic/Organization/Location, participant membership,
visit/patient chain) and by every query's own `clinic_id` predicate. This also
keeps the DDL safe under the WordPress test-suite `CREATE TEMPORARY TABLE`
rewrite, where InnoDB refuses foreign keys — a documented constraint in
`tests/Integration/RealTableMigrations.php`.

## 6. Explicit non-goals

No WebSocket, no WordPress comments, no new top-level admin menu, no new
WP role/capability, no second invoice/payment engine, no rial↔toman
conversion, no clinical-record attachment to messages, no POS/device
integration, no changes to PR #277 or any other agent's branch.
