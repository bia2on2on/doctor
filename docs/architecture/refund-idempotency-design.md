# Refund Idempotency — Design Document (DESIGN ONLY)

> # ⛔ DESIGN ONLY — NOTHING IMPLEMENTED — NO SCHEMA OR MIGRATION APPROVED
>
> This document is a **proposal for review**. It contains **no code, no schema/DDL,
> no migration, no tests and no workflow change**, and nothing in it has been built
> or executed. It **does not approve, reserve or number any migration** (any option
> below that needs one is listed as such and needs a **separate explicit owner
> approval**). It **does not build on, and did not read,** any earlier "version two"
> refund design or migration: that design is **not approved**, and no such document
> or migration exists in `main` at the base commit below (a `git grep` for
> `cpms_refunds` and `refund … v2` over the tree found only `agent-log.md`
> sentences saying that "anything refund v2" was left untouched, plus two
> unrelated product-"V2" mentions in other docs).
>
> **Status: DRAFT — awaiting two independent finance reviewers (ledger §3.1 item
> 2.6) and owner approval.** Owner acceptance of any implementation: **NOT GIVEN.**

| | |
|---|---|
| **Base** | `origin/main` = `d1d49c5010260cb94a185a00e3a92e3e5d85ad92` (merge of PR #342), fetched 2026-11-14 UTC; every `file:line` below was read at this commit |
| **Revision** | **Round 5** (rounds 2-5 each followed independent finance reads of the previous head). Revision history and superseded options: Appendix A-B (non-normative). Still design only. |
| **Location rationale** | `docs/architecture/` already holds the project's design documents that are explicitly "design only / not yet implemented" (e.g. `phase2-tenant-context-remediation-design.md`). `docs/decisions/` holds *owner decisions*, which this is not; `docs/adr/` holds *accepted* ADRs, which this is not. |
| **Language** | English (the governance docs `engineering-tooling.md` and several recent decision records are English; `ledger.md` §3.2 is the Persian/English source of the requirements). |
| **Evidence labels** | **OBSERVED** = read in the cited file at the base commit, not executed. **INFERENCE** = reasoning from observations, not proven by execution. **LIMITATION** = what this work could not establish. **UNKNOWN** = not proven. **OWNER-DECIDED** = recorded as such in `docs/status/ledger.md`. **OPEN QUESTION (OQ-n)** = not owner-decided; options are in §9. |

---

## Invariants and decisions summary (NON-NORMATIVE)

*This summary adds no rule; it repeats what the sections below say, and **the detailed sections win** on any difference. PROPOSAL = a proposal, not a decision.*

**Invariants** (*O* = recorded owner decision, *D* = design position):
* *O* Refund is based on the amount actually paid after discount; partial refunds allowed; total refunded never above paid; fees separate, never auto-deducted; refund date and traceability required.
* *O* The key is unique per clinic (DB-enforced, atomic claim); same key + same specification returns the previous result; same key + different specification is rejected; the same key in another clinic is allowed.
* *D* Clinic comes only from the trusted scope; authorization runs before any key lookup and, for a key hit, also against the **stored** payment before any outcome derived from the row is visible (§4.4, §6.3).
* *D* A replay is judged by the **stored** `spec_version`; the REST route must not reject a still-replayable wire form first (§4.2, §4.4); a committed key stays bound.
* *D* Key claim, refund effect, replay snapshot and audit row share one transaction (storage option B) (§6.1).
* *D* "Rolled back" needs evidence of a whole-transaction rollback; an unknown commit/rollback outcome is reconciled by a **same-key** retry, never a new key (§6.4, §4.3).
* *D* Nothing is implemented; no migration is approved; Requirement W (§6.0) is a precondition.

**Protocol in brief (§6.1, §6.1a):** pre-checks → plain lookup by (clinic, key); hit ⇒ authorize on the stored payment, then `DECIDE` ⇒ replay / 422 / spec-unavailable → miss ⇒ validate under the current version; a failure is rejected only after a *temporary* confirmatory claim (rolled back, confirmed; concurrent claimants wait) → outermost transaction → claim key → duplicate ⇒ rollback, fresh read, `DECIDE` → lock payment, invoice; re-check status and cap → write, verified snapshot, audit → commit → respond from the snapshot.

**Open questions** (all recommendations are PROPOSALs):
* **(a) Owner decisions:** OQ-1 storage (B; migration needs separate approval); 2 replay body (R-1; R-2 excluded); 3 refund date; 4 omitted amount; 9 other actor / stored-payment authorization; 10 no backfill; 11 adjacent defects; 14 failed-result/tombstones/retention; 15 voided payment; 16 amount wire form (API contract); 17 permissions; 18 rejected-key reuse; 19 recovery after mismatch; 20 persisted UI key and fencing.
* **(b) Engineering decisions:** OQ-5 reason normalization; 8 mismatch code naming; 13 replay log; 21 write-failure mechanism; §6.1a race options (O2).
* **(c) Partly settled** (binding constraint recorded, remainder open): OQ-6 retention (binding: same key + same spec returns the previous result, no expiry without owner decision; open: legal retention, deletion); OQ-7 rollout (binding: key required, no unprotected window; open: rollout mechanism); OQ-12 later overpayment (binding: cap = paid after discount, total ≤ paid; open: treatment of the excess).

---

## 0. Scope and non-goals

In scope: how a refund request carrying an idempotency key must behave, how
"same specification" is defined and compared, where the key could be stored,
atomicity/concurrency/crash/retry behaviour, replay response semantics,
interaction with neighbouring finance behaviour, a test plan (descriptions only),
and open owner questions.

Out of scope (not decided, not proposed for implementation here): any code or
schema change; changing refund arithmetic or authorization policy; payment-capture
(D13) behaviour; the void rules; receipts. Where an adjacent behaviour looks wrong
it is reported as a **finding** (§8) and routed to an open question, not fixed.

---

## 1. Owner-decided requirements (inputs, not re-decided here)

Source: `docs/status/ledger.md:125-135` (§3.2 "Refund idempotency key",
**DECIDED — implementation NOT started**), plus §3.1 item 2.4 and 2.6.

| # | Requirement | Source |
|---|---|---|
| R1 | Refund amount is based on the **actual amount paid after discount**. Partial refunds are allowed. Total refunded can **never exceed** the amount paid. | ledger §3.1 2.4 |
| R2 | **Fees are separate and NEVER auto-deducted.** | ledger §3.1 2.4 |
| R3 | **Date and traceability are required for every refund** ("idempotency, history and traceability are mandatory"). | ledger §3.1 2.4 |
| R4 | A refund **idempotency key is REQUIRED**, **unique per clinic**, enforced by **DB uniqueness** and an **atomic** operation. | ledger Record A (`:129`, verbatim from `agent-log.md` 2026-11-06) and Record B (`:133`) |
| R5 | **Same key + same specification ⇒ the previous result is returned.** | Record B |
| R6 | **Same key + different specification ⇒ rejected.** | Record B |
| R7 | **The same key in a different clinic is allowed** (independent). | Record B |
| R8 | No schema or migration without a **presented design and owner approval**; finance changes need **two independent finance reviews** before changes. | Record B; §3.1 2.6 |

**Note on the "request fingerprint".** Record A (`ledger.md:129`, verbatim) says the key
is "unique per clinic, **carrying a request fingerprint**"; Record B (`:133`, owner
relay) says "identical parameters"; the ledger (`:135`) says they are consistent. This
document treats a *request fingerprint* as part of R4/R5/R6 and leaves the mechanism
(stored hash vs. stored canonical fields) to OQ-1.

---

## 2. Verification of the reader-observed facts

All re-read at `d1d49c5`. Result column: **CONFIRMED**, or **CONFIRMED with
correction**.

| # | Claim | Result and evidence (file:line) |
|---|---|---|
| V1 | Refund route takes only `reason`/`amount`, no key | **CONFIRMED.** Route `FinanceController.php:145-163` (comment block from `:142`); declared args are only `reason` (required, `:159`) and `amount` (optional, `:160`); the handler passes `reason` plus `$this->body($r)` (`:155`). No `Idempotency-Key` header is read on this route (the header helper `RestBase.php:304-311` is called only by the payment route, `FinanceController.php:96`). |
| V2 | `refundPayment` has no key lookup and no different-spec rejection; caps are checked before the transaction and again under row locks | **CONFIRMED.** `FinanceService.php:681-778`. Pre-transaction read and cap: `:697-710` (`$maxRefundable` `:699`, rejection `:703-710`). Transaction opens `:712`; payment row locked `:713`; invoice row locked `:725`; cap re-derived from the locked row and re-checked `:733-746`; update `:748-751`; invoice reversal `:752`; audit `:754-771`. Nothing reads or compares any key or prior request. |
| V3 | Shared table has `u_idem_scope(key, endpoint, wp_user_id, context_id, clinic_id)`, used only by booking confirm, reschedule and handwriting | **CONFIRMED.** Index created `2026_09_09_0020_idempotency_clinic_scope.php:81`. Callers of `Idempotency::check()`: `BookingService.php:918` (confirm), `:1576` (reschedule), `HandwritingService.php:273` (handwriting); no other caller exists (`grep` over `src/`). |
| V4 | Payments have `UNIQUE(invoice_id, idempotency_key)` = `u_pay_idem` | **CONFIRMED.** `2026_09_05_0001_initial_schema.php:609`. |
| V5 | There is **no refunds table**; refunds are `status` + `refunded_amount` on the payment row | **CONFIRMED.** `0001:599` (`status ENUM('captured','voided','refunded')`) and `:600` (`refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0`). `git grep cpms_refunds` over code, tests and migrations finds nothing. The only trace of a "Refund record" is prose in `docs/state-machines/payment.md:51`. |
| V6 | Payment replay returns 200 (not 201) and rebuilds the response from the current invoice view, contradicting `SRS.md:281` ("same response") | **CONFIRMED.** Lookup `FinanceService.php:508-515`, builder `:1362-1381`: the replay path passes the *currently stored* payment row plus `$this->invoiceView($invoiceId)` (a fresh read) into `paymentResult(..., true)` (`:514`, `:581`), adds `idempotent_replay: true` and `code: CLINIC_IDEMPOTENCY_REPLAY`, and the controller maps replay to 200 and first success to 201 (`FinanceController.php:106`). `SRS.md:281` says "تکرار POST Payment = همان پاسخ، بدون تراکنش دوم". The payment replay path also **does not compare** amount/method with the stored payment (`:508-515` reads only `(invoice_id, key)`). |

### Additional observations relevant to the design

| # | Observation | Evidence |
|---|---|---|
| A1 | The shared `Idempotency::check()` runs a separate statement with no surrounding business transaction (the two booking callers claim at `:918`/`:1576` **before** their business transaction and call `complete()` at `:1170`/`:1808` **after** it). A crash between commit and `complete()` leaves a `pending` row. | `Idempotency.php:38-82`, `:87-104`; call sites above |
| A2 | `check()` **fails open**: if the insert fails for a non-UNIQUE reason and the re-read finds nothing, it returns a *fresh path* (`is_replay=false`). For refunds that would mean "no protection → apply". | `Idempotency.php:58-64` |
| A3 | A recurring job `cleanup.idem` deletes **every** row older than 90 days from the shared table (any endpoint). A refund key kept there would expire. | `Idempotency.php:121-131`; `IdemCleanupHandler.php:9-26`; `App.php:2627` |
| A4 | Shared table `clinic_id`: `DEFAULT 1` only in the creating migration (`0001:133`); migration 0016 removes it (`:33-40`) and 0017 adds a foreign key (`:28`), so on a migrated installation there is **no default** — not a reason against option A. What stays true: the unique index is five columns, so "unique per clinic" is not what it expresses. | `0001:133`; `0016:33-40`; `0017:28`; `0020:81` |
| A5 | `refund` first-success returns **HTTP 200** (no status function is passed). The only UI caller treats any status other than 200 as an error. | `FinanceController.php:148-156` (no `$status`/`$statusFn` passed), `staff()` default `200` at `:315`; `SecretaryFinancePage.php:768-769` |
| A6 | Refund `amount` is optional; omitted ⇒ refund the whole remaining amount. The UI sends `amount` only if the operator typed one ("empty = whole remaining"). | `FinanceService.php:700-702`; `SecretaryFinancePage.php:758-767` |
| A7 | Amounts are **whole rials**, not cents (nothing is scaled by 100; `toMinor()` is only a name). It returns an int as is; a float if integral; a numeric string via **`(float)`**, accepted if the double is integral, then cast to int; anything else ⇒ `null` ⇒ 422. The REST layer converts the value to a float **before** the service (§4.2), so an over-precise decimal such as `300000.00000000001` most likely reaches the service as `300000` (JS double check; PHP/WordPress not run): "fractional input is rejected, no rounding" is **not true in general**. Storage is `DECIMAL(12,2)` (10 integer digits). | `FinanceService.php:1210-1225`; `FinanceController.php:160`; `0001:595-600`; `SecretaryFinancePage.php:760` |
| A8 | `reason` is only `trim()`-ed (PHP `trim`, ASCII whitespace set), must be non-empty, and is **truncated to 255** characters **only inside the audit record**; the payment row stores no refund reason. | `FinanceService.php:686-689`, `:770` |
| A9 | The audit record is written **inside** the refund transaction through a nested savepoint. The audit writer **explicitly checks** its own steps and throws: missing guard row (`:85`), tail-read error (`:93`), insert not persisted (`:130`), guard update failed (`:139`) — so an *audit-write* failure aborts the enclosing transaction (rollback, confirmed per §6.4). It locks a **global** singleton row (`cpms_audit_seq`, id 1) `FOR UPDATE`. The audit row carries actor, role, `created_at` (server time), before/after/meta JSON and a hash chain. This explicit checking is **not** the general behaviour of the DB wrapper (A13) and is **not** applied to the refund's own `UPDATE`s (A14). | `AuditLogger.php:43-141`; `CpmsDb.php:272-317`; `0001:676-698` |
| A10 | Only the payment row records *how much* has been refunded in total. There is **no per-refund record, refund date or refunding actor on a business table**; per-refund facts exist only in the audit log (`PAYMENT_REFUND`, `meta.refund_amount`, `meta.reason`). | `0001:595-613`; `FinanceService.php:754-771` |
| A11 | A partial refund leaves `status = captured` (`'refunded'` only when `refunded_amount >= amount`). This differs from `docs/state-machines/payment.md:44`, which calls `refunded` "full/partial". | `FinanceService.php:748-751`; `payment.md:44` |
| A12 | The configured InnoDB isolation level is **not set in the code** that was read (`CpmsDb.php` has no `SET TRANSACTION ISOLATION`). It is therefore the server default — **UNKNOWN** on any given installation. | `grep` over `src/` |
| A13 | **The runtime DB wrapper is soft-mode.** By default (`$strict = false`) an SQL error becomes `false` / `0` / `null` / `[]` instead of an exception: stated in the class header (`CpmsDb.php:17-21`), default at `:25`; `ensureNoSqlError()` throws only when strict (`:64-69`); `insert()` returns `false` (also for a duplicate key, `:195-205`); `update()` returns 0 on failure unless the caller passes `throw_on_error` (`:211-229`); `fetchRow` returns `null` on error, so **"no row" and "query failed" are indistinguishable** unless the caller inspects `wpdb->last_error` (the audit writer does, `AuditLogger.php:92-94`). Strict mode is switched on only by `MigrationRunner` (`:100`, `:145`; off again `:111`, `:155`). Only transaction-control statements always throw (`CpmsDb.php:248-256`), and `transactional()` rolls back **only if the callback throws** (`:272-317`). Consequence: a failed write inside a refund transaction does **not** abort it by itself. | as cited |
| A14 | The refund's own writes discard their results: `PaymentRepository::update()` and `InvoiceRepository::update()` return `void` and call `CpmsDb::update()` without `throw_on_error` (`PaymentRepository.php:81-84`; `InvoiceRepository.php:50-54`). `refundPayment` calls them at `FinanceService.php:748-751` and, through `reverseInvoicePayment`, `:1148-1152` and never checks. `PaymentRepository::insert()` likewise discards the insert result and returns the last insert id. The capture path, by contrast, checks its insert **by hand** and falls back to a re-read (`FinanceService.php:535-553`). | as cited |

**Limitation:** none of A1-A14 was executed. In particular no MySQL/MariaDB was
available (see §11), so all lock/isolation statements below are **INFERENCE**.

---

## 3. Problem statement and financial impact

### 3.1 Observed gap

**OBSERVED:** the refund route and `refundPayment` carry no key, store no
request identity and compare nothing against earlier requests (V1, V2). The
ceiling check is the only protection: the cap is enforced twice (`:697-710` and,
under the payment row lock, `:733-746`), so `refunded_amount` cannot exceed the
captured amount (that part is correct, and is what `RefundLostUpdateTest` covers
with a fake `wpdb` that has no real locking — a limitation recorded in
`docs/agent-log.md` for PR #329).

**INFERENCE (consistent with `ledger.md:135` and `agent-log.md` 2026-11-06):** a
*repeated* identical **partial** refund that still fits the remaining balance is
applied **again**, because the second request is indistinguishable from a new,
legitimate one. Typical triggers are a client retry after a timeout or a lost
response, a double click, or two staff members acting on the same slip. It is a
**conditional risk in the reviewed implementation**; the ledger records that no
installation-level confirmation exists.

Narrower cases, **OBSERVED** from the code:

* Omitted `amount` (whole remainder) retried after the first call used up the
  balance: the first call sets `status='refunded'` and `refunded_amount = amount`;
  the retry computes a remaining amount of 0 in the **pre-transaction** check and
  fails with **422** `CLINIC_VALIDATION_FAILED`, `data.max_refundable = 0`
  (`:697-710`) — it never reaches the in-transaction status check. Not applied twice, but the operator sees an error for an
  operation that succeeded. The 409 `CLINIC_INVALID_TRANSITION` (`:717-724`) is
  reached only when the pre-transaction read still looks refundable but the
  locked row is no longer `captured` (e.g. a void or a concurrent change between
  the two reads).
* Explicit partial amount retried while it still fits: applied twice (the gap).
* Two concurrent identical partial refunds: serialized by the payment row lock
  (`:713`); the second re-derives the remaining amount and, if it still fits,
  applies as well.

### 3.2 Financial impact (worked example; illustrative numbers)

Payment of 1,000,000 rials; operator refunds 300,000; the response is lost; the
client retries the same request.

1. `payments.refunded_amount` becomes 600,000 instead of 300,000 (still ≤ the cap, so nothing blocks it).
2. `reverseInvoicePayment` (`:1142-1155`) reduces `invoices.paid_amount` and raises `invoices.balance` **twice** — the invoice shows the patient owing 300,000 more than reality.
3. Two `PAYMENT_REFUND` audit rows exist with identical reason and amount; nothing marks the second as a duplicate.
4. If staff hand over money per confirmation, the clinic pays out up to the full remaining balance, once per retry that fits under the cap; revenue/refund summaries built on `refunded_amount` are overstated.

The loss is **bounded by the payment amount** (cap holds) but is **undetectable as a
duplicate** from the stored data, and it multiplies per payment. No claim is made
about how often it occurs.

---

## 4. Definition of "same specification" and how it is compared

### 4.1 Fields

A refund request has these inputs. Classification is a **design position** (items
needing owner input are cross-referenced to §9).

| Input | In the specification? | Notes |
|---|---|---|
| **Clinic** | **No — it is the scope, not a field.** | Always taken from the trusted `ScopeContext` (`requireTrustedClinicId`, `FinanceService.php:1188-1200`; fail-closed `CLINIC_SCOPE_REQUIRED` 400). Never from the body, the key, or the row. Uniqueness is `(clinic, key)` (R4, R7). |
| **Payment** (`{id}` in the URL) | **Yes.** | Same key on a different payment ⇒ different specification ⇒ rejected (this is what "unique per clinic" means: a key identifies one intended refund, not one per payment). |
| **Operation** | Implicit (refund). | The key space is refund-only; a refund key must never be matched against a capture/void/adjustment. |
| **Amount** | **Yes — as the operator's *intent***. | See §4.2 (explicit integer vs "remaining"). |
| **Reason** | **Yes.** | Normalized per §4.3. |
| **Refund date** | **Open.** | Today no client date exists; the "date" is server time (A9, A10). If the owner allows a client-supplied/back-dated refund date it **must** be in the specification. Recommendation: server-assigned only, **not** client-supplied, **not** in the specification. **OQ-3.** |
| **Actor** | **No (recommended).** | Identity comes from authentication, not the request. Authorization is always re-run (§6.3). Whether a *different* authorized actor replaying the same key gets the stored result is **OQ-9**. |
| **Fees** | **No.** | R2: fees are a separate matter and never part of the refund amount; any `fee`-like body field is outside the allow-list (§4.4). |
| Unknown body fields | **Never** in the specification. | See §4.4. |

### 4.2 Amount: canonical form, transport boundary and "remaining"

* **Today (OBSERVED by reading; PHP and WordPress NOT run).** The route declares
  `'amount' => ['type' => 'number']` (`FinanceController.php:160`). In WordPress source
  read from `wordpress-develop` branch `6.9` (the plugin requires WP ≥ 6.9; the
  installed version is **UNKNOWN**) `dispatch` runs `has_valid_params()` and
  `sanitize_params()` (`class-wp-rest-server.php:1120-1124`) **before** the permission
  callback and the handler (`:1264`). For a typed argument the default
  `rest_parse_request_arg` validates with `is_numeric` (`rest-api.php:2557-2565`) and
  sanitizes with **`(float) $value`** (`:2885-2887`); the controller reads the params
  afterwards (`FinanceController.php:362-371`). **INFERENCE:** the service receives a
  float and `toMinor` casts an integral float to int (`:1210-1225`), so client text that
  rounds to an integral double is **silently accepted as that integer**. JS doubles
  (Node, not PHP): `300000.00000000001` → `300000`; `9007199254740993` →
  `9007199254740992`; `1e400` → `Infinity`. PHP's `(int)` of `INF`/out-of-range floats
  is **UNKNOWN**.
* **Raw-token boundary (OBSERVED in WordPress 6.9 source; not run).** WordPress decodes
  a JSON body with `json_decode($body, true)` in `parse_json_params`
  (`class-wp-rest-request.php:702`) **before** any argument is read, so an over-precise
  JSON number is already an int/float there and **its original lexeme is gone**;
  removing `type: number` alone does not bring it back. The raw body stays available
  (`get_body()`, `:642`); query and form values are strings. Parameter sources, first
  match wins: JSON (JSON content type only), POST, GET, URL, defaults
  (`get_parameter_order`, `:361`); the controller uses the JSON params if the body
  decoded to an array, else all sources merged. PHP's `json_decode` handling of
  duplicate members, big integers and exponent forms is **UNKNOWN**.
* **Requirement T — transport boundary (design position).** WordPress checks route
  arguments **before** the handler, so a route constraint can reject a retry before the
  service has read the stored key row; keeping old canonicalizers in the service does not
  help at this boundary. Therefore the refund route declares **no** `required`, `type`,
  `pattern`, `enum`, `validate_callback` or `sanitize_callback` for any field whose rules a
  `spec_version` may change — `amount` and `reason` (today `reason` is
  `required` + `type: string`, `:159`). For an argument without them WordPress applies
  no validation or sanitization (`class-wp-rest-request.php:815-880`, `:885-…`;
  INFERENCE from the 6.9 source). The handler passes the **wire values as decoded (option
  (b): the raw token), with their PHP types**, to the service, where the versioned grammar
  runs: a **hit** is judged by the *stored* version's grammar, which accepts exactly the
  wire forms that version accepted, so a still-replayable old form reaches `DECIDE`
  (§4.4); a **new claim** is held to the *current* version's strict grammar. Only
  version-independent checks stay at the route (key header format, numeric payment id,
  malformed JSON ⇒ 400). Consequences: an invalid amount for a new claim is rejected by
  the service's validation error, not by WordPress's `rest_invalid_param` (API-visible,
  OQ-8); the grammar code is no longer backed by WordPress and must be hardened
  (Requirement A). *Alternative:* a strict route grammar is acceptable **only** if each
  later version's route accepts a superset of every earlier version's wire forms
  (widening only), which forbids tightening and rests on discipline (T27 cases 6-7).
* **Requirement A — explicit full-string parser.** The grammar is an explicit parser,
  never the schema `pattern` alone: WordPress applies `pattern` as
  `preg_match('#' . $pattern . '#u', $value)` with **no anchoring added**
  (`rest-api.php:1813-1816`, used at `:2728`), so `[1-9][0-9]{0,9}` also matches inside
  `1e3`, ` 5` or `5\n`; and an anchored `^…$` accepts one trailing newline in PCRE
  (INFERENCE, not run). Required: check the wire type, then length 1…10, first character
  `1`-`9`, every character ASCII `0`-`9` byte-wise, then the range; no trimming, no
  locale, no float step. Tests: T7.
* **Amount options** (the range is **1 … 9,999,999,999**; zero/negative already fail,
  `$refund <= 0`, `:703`; decided in **OQ-16**; real-dispatch test T7a):

  | Option | Mechanism | Consequences |
  |---|---|---|
  | **(a) exact decimal string only** | New claims accept only a JSON string that Requirement A's parser accepts; the service refuses JSON numbers. Older versions' numeric forms still replay (Requirement T). | Exact, simple. **API contract change** (the UI sends a JSON integer today): **owner decision**; the UI must change anyway to send the key; external callers UNKNOWN. The handler reads one source and rejects disagreeing duplicate sources. |
  | **(b) token-preserving raw-body parse** | A strict scan of `get_body()` for the top-level `amount` member: accept only an integer lexeme; reject duplicate members (including escaped spellings of the name), `amount` in query/form when the body is JSON, fraction/exponent/sign/leading zero; require WordPress's decoded value to equal the lexeme (parser-differential guard). | JSON integers keep working; UI unchanged. A custom parser in a finance path; BOM, escape and nesting edge cases UNKNOWN; needs differential/fuzz tests. |
  | **(c) keep a numeric type** | The service receives the decoded int/float as today (no route coercion, Requirement T). | **Indistinguishable from integer N:** decimal text within half a unit-in-the-last-place of N (≈2.9e-11 at 300,000; ≈9.5e-7 at 9,999,999,999 — IEEE-754 doubles, computed in Node; PHP not run) and the forms `1000.0`, `1e3`. A larger fraction (e.g. `9999999999.000002`) stays non-integral and is rejected (Node). So "no silent rounding" is **not met in the strict sense**: differences below about 1e-6 rial are accepted as the integer, and two different request texts can be *one* specification (replay instead of 422). PHP's handling of `INF`/huge values UNKNOWN. |

  **PROPOSAL:** (a) if the owner accepts the API change; (c) only if the owner explicitly
  accepts the residual above; (b) only if JSON-integer compatibility is mandatory *and*
  exactness is required.
* **Canonical form:** the integer value as base-10 ASCII digits (no sign, leading
  zero, separator, exponent or decimal point), built from the exact parse; which wire
  forms map to it is decided by the chosen option (§4.2 table), not by today's `toMinor`. Amounts are
  **whole rials** (A7); nothing is scaled by 100. The currency is the payment's/
  invoice's, not a request field (`0001:557`, default `IRR`).
* **Omitted amount = intent "REMAINING", not a number.** The specification stores
  the *intent* (`REMAINING` or the explicit integer). Comparing resolved numbers
  would be wrong: after a successful whole-remainder refund the resolved remaining
  is 0, so a legitimate replay would look like a different request. The **applied**
  amount is stored separately as part of the result (§6).
* Whether an explicit amount equal to the remainder is the *same* specification as
  an omitted amount is **OQ-4** (recommendation: **different** — fail-safe).

### 4.3 Reason: normalization of free text

Today the reason is only `trim()`-ed (A8). For comparison the design proposes, in
order:

1. Must be valid UTF-8; otherwise reject (422), never compare raw bytes of an invalid string.
2. Trim leading/trailing whitespace. PHP `trim()` with its default list strips only ASCII space, tab, LF, CR, NUL and vertical tab; it does **not** strip e.g. U+00A0 NO-BREAK SPACE. **U+200F RIGHT-TO-LEFT MARK is not whitespace:** it is a *bidirectional format control* (general category Cf), invisible, and not stripped by `trim()`. Whether such invisible format controls (U+200E/U+200F, U+202A-U+202E, U+2066-U+2069, zero-width characters) are kept, stripped or rejected is not an implementation detail — it decides which strings count as "the same" — and is part of **OQ-5**. Until decided they are kept as typed (so they make specifications *different*, the fail-safe direction).
3. Unicode **NFC** normalization **only if** the `intl` `Normalizer` is available in the supported runtime — **availability is UNKNOWN (not verified in the repo or on any installation) and must be established before adoption.** If it is not available, NFC is skipped and two visually identical but byte-different reasons are *different* specifications. **Requirement:** the exact canonicalization (trim set, normalization form, treatment of invisible controls and — because normalization data can change between Unicode/ICU versions — the Unicode version assumed) is **pinned by `spec_version`**; any change to it is a **new `spec_version`**, and an existing refund keeps comparing under the version it was created with (selection procedure: §4.4). A fingerprint alone cannot be re-derived under a different canonicalization, which is a further reason OQ-1 may prefer to store the canonical fields as well.
4. **No** internal whitespace collapsing, **no** case folding, **no** folding of Arabic/Persian letter variants (ي/ی, ك/ک) or digit systems (۱۲۳/123/١٢٣). The stored/displayed reason stays exactly what the operator typed (after steps 1-3).
5. Length: compare the **full** normalized string. Because the audit record truncates at 255 (A8), two reasons differing only after character 255 would be different specifications that persist identically. Recommendation: **reject** over-length reasons rather than truncate silently — a behaviour change, hence **OQ-5**.

**Fail-safe principle (design position).** A wrongly "different" comparison costs
a loud rejection; a wrongly "same" comparison silently returns the *previous*
refund for a request that was meant to be different, so a refund the operator
believes was recorded is not. Therefore every ambiguity is resolved toward
**different**. **A rejection must not be answered by generating a new key:** if the
first request in fact committed (e.g. its response was lost), a new key would make
the same refund apply a second time, because the system cannot know that the two
requests are one intent. The original key is kept while the operator reconciles
what was recorded; the recovery workflow is **OQ-19**, and a new key is for a
verified **new** refund intent only.

The ledger's patient-name rule (empty / invisible-only rejected, `ledger.md` §3.3)
applies to patient editing only and is stated not to extend automatically; whether
to reuse it for refund reasons is part of **OQ-5**.

### 4.4 Canonical form and fingerprint

* Specification tuple: `spec_version`, `payment_id`, `amount_intent`, `reason`
  (and `refund_date` only if OQ-3 makes it client-supplied). **Allow-list only:**
  unknown body fields (today they reach the service but are ignored,
  `FinanceController.php:155`, `FinanceService.php:690`) are excluded, so requests
  that differ only in an ignored field are *the same* specification — correct,
  because the ignored field has no effect.
* Serialization: **length-prefixed fields in a fixed order** in UTF-8, not
  `json_encode` (whose output depends on flags, escaping and PHP version). A
  `spec_version` prefix lets the canonical form evolve without silently changing
  how old keys compare.
* Fingerprint: SHA-256 over those bytes, stored with `spec_version`. Whether the
  fingerprint alone is stored or *also* the canonical fields (which makes a
  fingerprint redundant in a refunds-table design, because the row carries the
  fields) is **OQ-1**.
* **Spec version and authorization on a hit (one rule; §6.1 steps 1a and 5.3).**
  `spec_version` belongs to the **stored** refund, so a duplicate lookup must not
  canonicalize the request under the *current* version first. `DECIDE(wire values,
  stored row)`: (0) **authorize the actor against the stored payment** (capability,
  scope/clinic, doctor ownership; nothing new if it is the URL payment that step 1
  already authorized); failure ⇒ the single non-disclosing deny response of §6.3,
  before anything derived from the row is observable; (1) `V_s` = the stored
  `spec_version`; (2) parse **and validate** the *original wire values* with `V_s`'s own
  grammar and rules (amount grammar, reason normalization, limits) — including wire
  forms the current version would reject, which the route must have let through
  (§4.2 Requirement T); (3) fingerprint / compare with `V_s`'s algorithm; (4) equal ⇒
  **replay**, different spec or payment ⇒ **422** key-reuse (generic, nothing echoed);
  (5) **no canonicalizer for `V_s`** ⇒ **fail closed**: nothing applied or replayed by
  this instance, and **not** the key-reuse 422 (it would falsely say the parameters
  differ) — a distinct, logged error (candidate `CLINIC_IDEMPOTENCY_SPEC_UNAVAILABLE`,
  5xx class, "cannot evaluate this key's specification; do not use a new key"; naming
  with OQ-8). A **new** claim uses the *current* version and records it. So an
  **identical valid retry after an upgrade is replayed**, even if the new rules would
  reject it, as the owner decision requires. **Requirement:** every released grammar,
  canonicalizer and rule set is retained unchanged, with fixed fixtures, while a refund
  row of that version can exist (OQ-6: indefinitely); retiring one needs owner approval.
  *Options:* **V-1** per-version canonicalizers (**PROPOSAL**); **V-2** one frozen
  canonicalization (any change needs a new endpoint); **V-3** re-canonicalize under the
  current version ("same" changes retroactively; impossible with fingerprint-only
  storage, OQ-1(i)). Chosen with OQ-1.
* The key itself is validated like the payment key: UUID, lowercased, from the
  `Idempotency-Key` header (`RestBase.php:304-311`); `idempotency_key` columns
  elsewhere are `VARCHAR(128)` (`0001:598`). Missing/invalid ⇒ 400
  `CLINIC_VALIDATION_FAILED`, as for payments (`FinanceController.php:96-99`).
  Whether to accept the same format is minor; the key being **required** is R4.

### 4.5 Response when the key matches but the specification differs

*Design position* (names are candidates; `ADR-0019` requires the implementing PR
to register any new code in `docs/api/error-codes.md`; this PR registers nothing):

* **HTTP 422**, code **`CLINIC_IDEMPOTENCY_KEY_REUSED`** (candidate), standard
  error envelope, message that the key was already used with different
  parameters. 422 matches the repo's "business-rule" class; 409 is already used
  for "in-flight" (`CLINIC_DUPLICATE_IN_FLIGHT`). Final status/code naming is
  **OQ-8**.
* **No state change:** no refund row, no payment/invoice update, no audit
  `PAYMENT_REFUND` row, nothing written to the key store. No commit is issued on this
  path; a transaction (if opened) is ended by a rollback (confirmed per §6.4).
* The body **does not echo** the stored specification or result (no information
  beyond the generic message).
* Authorization and tenant checks run **before** the key lookup (§6.3), so this
  response is only reachable by an actor already allowed to refund in that clinic (and, on a key hit, for the *stored* payment: §4.4 step 0).

---

## 5. Storage options

All options must satisfy R4-R7: DB-enforced uniqueness per clinic, atomic claim,
same-clinic replay/reject, cross-clinic independence. None of this is
implemented; **options B and C require schema changes and therefore a separate
owner approval of a migration; option A needs no DDL but is not recommended.**

### Options A, C and D — not viable (summary; details in Appendix A.1)

* **A** shared table `cpms_idempotency_keys`: claim not atomic with the refund, fail-open check, 90-day purge, no fingerprint column, uniqueness by caller convention — does not honestly meet R4.
* **C** key column on the payment row: one column cannot hold the keys of many partial refunds — structurally insufficient.
* **D** audit log as key store: no unique constraint possible — not viable.

### Option B — a new refunds table (one row per refund) holding the key

*Logical content (illustrative attributes, not DDL, not types, not a migration
number):* clinic, payment, invoice, amount applied, amount intent, reason,
server-assigned refund timestamp, refunding actor, idempotency key, `spec_version`
and fingerprint, and the result snapshot (see §6) or enough facts to re-create it.

* **Schema/migration required: YES** (new table, a uniqueness constraint, foreign
  keys per `ADR-0012`). **Needs separate owner approval (R8).** Not proposed for
  implementation here. **Locking side effect of
  the foreign keys (INFERENCE/UNKNOWN, no database):** InnoDB may take **shared** locks on
  parent rows at the refund-row insert, before the exclusive locks of steps 6-7 (§6.5
  item 1).
* **Per-clinic uniqueness by DB:** a unique constraint on `(clinic, key)` with
  **both columns NOT NULL and no default for clinic** (MySQL unique indexes treat
  NULLs as distinct, which would silently remove uniqueness; the existing shared
  table needed a NULL→0 normalization for exactly this reason,
  `2026_09_07_0006_idempotency_scope.php:52-53`). The same key in another clinic
  is a different `(clinic, key)` pair ⇒ allowed (R7).
* **Atomicity:** the key claim **is** the refund record insert, in the **same
  transaction** as the payment/invoice updates and the audit row (§6.1). There is
  no `pending` state to strand: the transaction commits everything or nothing.
* **Retention:** the row is a financial record; retention is the record's
  retention, not a purge window (see **OQ-6**).
* **Pros:** satisfies R3 fully (per-refund date/actor/reason/amount on a business
  table, queryable, not only in the audit log); crash-atomic; exact `(clinic,
  key)` uniqueness; replay result comes from the same row.
* **Cons:** migration + owner approval + two finance reviews; dual bookkeeping
  (`payments.refunded_amount` remains the cap authority, see §7.2); legacy refunds
  have no rows (no backfill, §7.9); tenancy tripwire/FK/ERD/data-dictionary
  updates in the implementing PR.

### Comparison

| Criterion | A shared table | B refunds table | C payment column |
|---|---|---|---|
| DDL / migration | none for key; fingerprint home is weak | **yes** | **yes** |
| DB-enforced `(clinic, key)` uniqueness | only by convention | **yes** | not for many refunds |
| Atomic with refund effect | no (separate statement) unless shared class is changed | **yes** | n/a |
| Crash leaves stuck/lost key | yes | no | n/a |
| 90-day purge conflict | yes | no | no |
| Per-refund date/actor/reason on a business table (R3) | no | **yes** | no |
| Touches shared booking/handwriting code | yes (if made atomic) | no | no |
| Honest fit to R1-R7 | partial | **full, if Requirement W (§6.0) is met** | fails R1 |

**Engineering recommendation (not a decision):** option **B**, subject to owner
approval of a feature-scoped migration (R8). **To be chosen** by the owner in **OQ-1**; the owner has **not** chosen option B.

---

## 6. Atomic operation, locking, concurrency, crash, retry (for option B; A noted where it differs)

All of §6 is a **proposal**; none of it is implemented. It has **one** protocol
(§6.1), **one** lock order (§6.5) and **one** failure classification (§6.4).

**Wording rule for the whole document.** "Rolled back" means **evidence that the whole
transaction was rolled back**: a `ROLLBACK` issued by the application that positively
succeeded (§6.4). A server-reported deadlock or lock-wait timeout is **not** by itself
that evidence — a lock-wait timeout may roll back only the statement (InnoDB behaviour,
INFERENCE, not tested) — so the application still issues and confirms a rollback.
Without that evidence the state is **UNKNOWN** and the outcome is "outcome unknown —
retry with the same key" (§6.4), never "rolled back".

### 6.0 Precondition that the proposal depends on: how write failures surface

**OBSERVED (A13, A14):** the runtime DB wrapper is soft-mode. A failed `INSERT`
or `UPDATE` does not throw, does not abort the transaction, and the refund's own
writes discard their results. A failing statement therefore does **not** roll everything back in the code as it
stands, and the
statements about rollback below hold **only if the following requirement is met**:

> **REQUIREMENT W (for any future implementation).** Every statement inside the
> refund transaction **up to, but not including, the commit statement** — key claim,
> payment lock read, invoice lock read, refund-row completion, payment update,
> invoice update, audit write — must be **explicitly checked or made to throw**, and
> a failure of any of them must end in a **rollback of the whole transaction**;
> only a *confirmed* rollback is reported as "rolled back, retry with the same key",
> otherwise the outcome is UNKNOWN (§6.4). In particular:
> (1) a failed lock read must not be treated as "row not found"; (2) a failed key
> insert must **not** be treated as "duplicate" unless positively identified as a
> duplicate-key error; (3) an `UPDATE` whose result is "0 rows" must be examined (it
> can mean failure, no matching row, or — in MySQL — unchanged values, which depends
> on client flags and is **UNKNOWN** here) rather than ignored.
>
> **Commit is excluded from the rollback rule.** A commit that returns success is
> *committed*. A commit that errors, times out or is not acknowledged is **UNKNOWN**
> (it may have been applied, §6.4): it must **not** be answered with "rolled back"
> or "failed", it is **never** retried with a new key, and the client is told
> "outcome unknown — retry with the same key". Reconciliation is the same-key retry
> (step 1a/5 finds the row ⇒ replay, or finds none ⇒ applies once). No
> implementation text, message or log may say that a possibly committed refund
> "rolled back".

Mechanisms (the choice is an engineering decision for the implementation design
review, listed as **OQ-21**; this document does not choose):

| | Description | Pros | Cons |
|---|---|---|---|
| **W-1 Explicit result checks** | Check each write's result by hand at the call site (as the capture path does, `FinanceService.php:535-553`, and as `AuditLogger` does, `:85-139`); repositories used in the path would have to return/propagate results (today they return `void`, A14). | No change to shared wrapper behaviour; local and visible. | Easy to miss one statement; repository signatures change; the wrapper hides the SQL errno, so identifying "duplicate key" needs a reliable method (e.g. inspecting `wpdb->last_error` text) — **UNKNOWN** how robust that is. |
| **W-2 Strict mode for this scope** | Switch the wrapper to strict (`CpmsDb.php:25`, `:64-69`) around the refund transaction so SQL errors throw; restore afterwards. | Every wrapper call throws automatically, including nested repository calls. | The flag is **instance-wide**, not per-statement; every other component that runs inside the scope (audit writer, helpers) then changes behaviour (some callers may rely on `false`/`null`); it is used today only by `MigrationRunner` (`:100`, `:145`), so it is untested in request paths; restoring must be guaranteed on every exit path; behaviour of `fetch*` null-on-error vs throw must be re-read. |
| **W-3 Both** | Strict for the scope plus explicit checks on the statements whose failure has special meaning (the key claim, the locked reads). | Defence in depth; the duplicate-key case is handled explicitly. | Most work; two mechanisms to keep consistent. |

Whichever is chosen, a test must prove that an injected failure of **each**
statement before the commit (T16) ends in a confirmed full rollback (else UNKNOWN); the commit itself is
covered by T17a (unknown outcome, never "rolled back").

### 6.1 The single atomic protocol

1. **Pre-checks, no key-store access, no cap check, no writes.** Capability
   (`requireCap`), trusted clinic (`requireTrustedClinicId`), scoped authorization
   (`authorizeScoped`), reason non-empty, payment exists and belongs to the
   trusted clinic (404 otherwise), per-payment ownership
   (`authorize_payment_ownership`) — i.e. what is now at
   `FinanceService.php:682-695`. Then: key present and well-formed (an unversioned
   check). The specification is **not** canonicalized here: that depends on a
   `spec_version` known only after the key lookup (§4.4). The payment read is a
   plain, possibly stale, read used only for early rejection. Route-level argument
   checks are limited to version-independent ones (§4.2 Requirement T).
1a. **Plain read of `(trusted clinic, key)`, no transaction.** *Found* ⇒ `DECIDE`
   (§4.4): authorize the actor against the **stored** payment first (§6.3), then judge
   with the **stored** `spec_version` ⇒ replay / 422 / spec-unavailable error; finished.
   *Not found* ⇒ parse and validate the wire values under the **current**
   `spec_version` (service-level grammar, §4.2 Requirement T) and compute the
   fingerprint. **A validation failure on a miss is not a final rejection** (§6.1a): go
   to steps 3-4 in *confirm mode* and reject only after step 4 shows that no committed
   or in-flight row exists. A committed row is immutable, so acting on a hit is safe; a
   **miss is never trusted** (steps 4-5 decide races); a read *error* is an error, not a
   miss (Requirement W).
2. **The pre-transaction cap check (`:697-710`) must not run before the replay
   decision.** If it did, a replay of a refund that exhausted the balance would
   get 422 (today's observable behaviour, §3.1) instead of the stored result. The
   cap check stays, but only **under the locks** (step 8; the existing second
   check, `:733-746`).
3. **Begin the transaction.** It must be the **outermost** transaction (the
   wrapper turns a nested call into a savepoint, `CpmsDb.php:279-285`; with an
   outer transaction open the "rollback, then fresh read" of step 5 cannot obtain
   a fresh snapshot). An implementation must assert this.
4. **Claim the key (first lock).** `INSERT` the refund row for `(trusted clinic,
   key)` carrying the *intent* fields (payment, `spec_version`, fingerprint /
   canonical fields, reason, amount intent, actor). The result is classified
   **explicitly** (Requirement W) into exactly one of:
   * **inserted** (one row, new id) ⇒ go to step 6;
   * **duplicate key** (positively identified) ⇒ step 5;
   * **anything else** (any other SQL error, ambiguous result) ⇒ **rollback**, no
     further step, outcome "rolled back, retry with the same key" if the rollback
     is confirmed, else "outcome unknown" (§6.4). It must
     **never** be treated as "not found, proceed" (that is the shared
     `Idempotency::check()` fail-open behaviour, A2, which refunds must not
     inherit).
   **Confirm mode** (an invalid request that missed in 1a — **PROPOSAL O2**, §6.1a):
   *inserted* ⇒ no committed row and no in-flight claim exists for this key, so
   roll back and return the validation rejection (stores nothing — proposed default,
   OQ-18; if the rollback is not confirmed the outcome is UNKNOWN, §6.4); *duplicate*
   ⇒ step 5 (replay or 422 key-reuse by the **stored** version — never the
   validation rejection). Whether a row with unfilled specification columns can be
   inserted is a schema question (UNKNOWN; nothing of it is committed).
   The row is inserted incomplete (result fields not yet known); it becomes
   visible to other sessions **only** when the transaction commits, and the
   transaction commits only after step 9 has completed it. **No committed state
   may exist in which the key row has no result.** (This is why option B has no
   `pending` state.)
5. **Duplicate-key handling: roll back, then re-read fresh, then decide.**
   1. **Roll back** the transaction (it holds nothing worth keeping; the rollback must be
      confirmed, else the outcome is UNKNOWN, §6.4). The
      transaction is *not* continued and no row is read inside it.
   2. In a **new** outermost transaction (or a single auto-commit read), read the
      row by `(trusted clinic, key)` — always scoped by the trusted clinic — so
      that the read reflects every transaction committed so far. A plain read is
      adequate **because** the snapshot is created by this new transaction, not by
      the one that failed. (Reading inside the failed transaction under
      REPEATABLE READ could return a snapshot older than the winner's commit;
      locking reads and gap/next-key lock behaviour in that case are **UNKNOWN**
      per isolation level and are deliberately **not** relied on. Test T15
      establishes the facts on a real database.)
   3. **Row found** ⇒ `DECIDE` (§4.4; stored-payment authorization first) using the **stored** row's `spec_version`
       (never the current one) on the original raw request: equal ⇒ **replay** the
      stored result (§7.1); different specification or payment ⇒ **422**
      key-reuse; no canonicalizer for that version ⇒ the distinct fail-closed
      error. No write in any of the three cases.
   4. **Row not found** (no committed row is visible: the other transaction rolled back, timed out or is still open) ⇒ go back
      to step 3 — a **bounded** number of times. The bound and what is returned
      when it is exhausted ("retry with the same key"; "rolled back" only if confirmed; never a
      silent apply) are implementation parameters; an unbounded loop is not
      acceptable.
   5. A read error here is an error, not "not found" (Requirement W).
6. **Lock the payment row `FOR UPDATE`** (the existing lock, `:713`). The result is
   checked explicitly: failure ⇒ rollback ("rolled back, retry" only once confirmed, else UNKNOWN); *no row* is
   distinguished from *query failed*. Re-check clinic ownership of the locked row.
7. **Lock the invoice row `FOR UPDATE`** (as today, `:725-731`), same explicit
   checking and clinic re-check.
8. **Re-validate under the locks, exactly as today:** payment `status` must be
   `captured` (`:717-724`, else 409), invoice exists, belongs to the clinic and is
   not `voided` (409), recompute the remaining amount from the **locked** payment
   row, resolve the `REMAINING` intent (§4.2), reject over-cap (`:733-746`, 422).
   Any rejection here ends in a rollback, which (once confirmed; else the outcome is
   UNKNOWN, §6.4) discards the key claim of step 4.
   **Proposed default, pending OQ-14/OQ-18 (not a fact):** a request rejected
   before commit leaves no key row (after a confirmed rollback). This never loosens the recorded decision for a
   **committed** refund: that key stays bound to its specification for good (same
   spec ⇒ replay, different spec ⇒ rejected).
9. **Write, every statement checked:** complete the key row (applied amount,
   server timestamp, actor, **and the replay snapshot — the exact response facts
   that will be returned, §7.1 — in this same transaction**), update
   `payments.refunded_amount`/`status`, reverse the invoice effect
   (`reverseInvoicePayment`), write the `PAYMENT_REFUND` audit row (which takes
   the global audit serialization row last, A9). Any failed or ambiguous statement
   ⇒ throw ⇒ rollback. **How the snapshot is built:** after the two updates and the
   audit write, still inside the transaction, re-read the payment and invoice rows
   (the transaction holds their exclusive locks and sees its own writes) and
   **verify** them against the intended post-update facts (`refunded_amount` = locked
   value + applied amount; payment `status`; invoice `paid_amount`/`balance`/`status`
   equal to the `reverseInvoicePayment` result; audit id > 0). Any mismatch ⇒ throw ⇒
   rollback. The snapshot is serialized from **those verified facts** plus applied
   amount, reason as stored, server timestamp, actor and audit id, and written to the
   key row (checked) before step 10 — never from a re-read after commit. (Today's
   code already builds its response inside the transaction,
   `FinanceService.php:772-774`.)
10. **Commit.**
11. **Classify the outcome** (§6.4): *committed* only if the commit statement
    returned success; *rolled back* only if a rollback statement positively
    succeeded; **anything else is "unknown"** and is **never** reported or acted on
    as a rollback.
12. **Respond from the stored snapshot** produced in step 9 (not from a re-read of
    current rows), with the first-success status; a later replay returns the same
    stored facts plus the replay marker. If the process dies after step 10, the
    refund and its snapshot are already durable, so a retry replays them.

### 6.1a Mixed-version race on a lookup miss

**Problem (INFERENCE from the protocol; not run).** A plain read cannot see an
uncommitted claim. If an instance on V1 holds an uncommitted claim for key K and an
instance on V2 reads K, V2 gets a miss; validating under V2 and rejecting would answer an
**identical retry** with a rejection before the unique-key collision could select V1's
row — contradicting R5. A rejection on a miss may be final only **after** a claim
attempt. Claiming first conflicts with the proposed default "an invalid request does not
consume the key" (OQ-18) unless the claim is rolled back (confirmed). The same race
exists one layer earlier at the REST route (§4.2 Requirement T).

| Option | Consequences |
|---|---|
| **O1** claim first, always | Correct; every invalid request costs a transaction, an insert and a key lock. |
| **O2** validate first; on failure at a miss, a **confirmatory claim** (step 4, confirm mode) before the final rejection — **PROPOSAL** | Valid requests unchanged. The claim is a **temporary, never-committed** row; concurrent claimants of K wait on it, so an invalid request can delay a valid one by up to one lock wait; a lock-wait timeout yields the retryable/unknown outcome of §6.4, **not** the rejection. |
| **O3** forbid mixed versions operationally | Not enforceable here; old code can serve requests during a deploy (INFERENCE). Insufficient alone. |
| **O4** monotonic validation (V(n+1) accepts all V(n) accepted) | Race cannot become a rejection; forbids tightening; rests on discipline. Complements O2. |

**UNKNOWN (no database; none tested):** whether a duplicate-key `INSERT` waits for an
uncommitted conflicting row or errors at once; whether a locking read of a missing key
(an alternative) takes gap locks and whether claims on *different* keys block each
other, per isolation level; deadlocks among several waiters on one key; the lock-wait
timeout. The reverse case (V1 instance meets a V2 row) ends in the fail-closed
spec-unavailable error (§4.4), never an apply.

### 6.2 Concurrency matrix

Results below assume no timeout, deadlock or unknown outcome; with one, the affected
request first gets the retryable/unknown outcome of §6.4, and a **same-key retry**
then gets the stated result.

| Situation | Expected behaviour (design) |
|---|---|
| Two **identical** requests (same key, payment, spec) concurrently | They collide at the key claim (step 4). The winner continues; the loser waits, then gets a duplicate-key error, rolls back (confirmed), re-reads fresh (step 5), finds the committed row, spec equal ⇒ **replay**. If the winner rolled back instead, the loser's insert succeeds or it retries (bounded). Exactly one refund. |
| Same key, **different spec, same payment** concurrently | Same collision; the loser, after re-read, finds a different specification ⇒ **422** `…KEY_REUSED`, no state change. Which request wins is not deterministic; the outcome (one applied, one rejected) is. |
| Same key, **different payments** | Same collision at the key claim, before any payment lock; the loser ⇒ 422 (different payment). Never two refunds for one key. |
| **Different keys**, same payment, both fit | Each claims its own key, then both queue on the payment lock; each re-checks the cap under the lock (intended: distinct intents). With foreign keys on the refund row, the shared-lock → exclusive-lock sequence may **deadlock** (INFERENCE/UNKNOWN, §6.5): the victim's transaction ends in a confirmed rollback (else UNKNOWN) and it retries with the **same** key; the total still never exceeds the amount paid. |
| **Different keys**, together exceeding the cap | The first applies; the second fails 422 (`max_refundable` is the locked value) and its rollback (once confirmed; else UNKNOWN) discards its key claim. The total never exceeds the amount paid (R1). |
| **Same key in two clinics** | Independent `(clinic, key)` pairs; both succeed. Every claim and read includes the trusted clinic, so one clinic can never observe, replay or collide with the other's key. |
| Refund vs **void** of the same payment | Both reach the payment lock; whichever is second sees the other's committed state under the lock. Deadlock freedom with capture is **UNKNOWN** (§6.5). |
| Deadlock victim / lock-wait timeout at any step | Retryable outcome: "rolled back" only once the rollback is confirmed, else "outcome unknown"; retry with the same key (§6.4); never reported as applied. |

### 6.3 Authorization and tenancy vs replay

* Authorization and tenancy checks run **before** the key lookup, every time. A key
  that was valid before an actor's authority was revoked must not replay for them —
  the same principle the booking path states in its own comment
  (`BookingService.php:1572-1575`: "a previously valid key must never replay after
  authority is revoked").
* The clinic comes only from `ScopeContext`; there is **no fixed clinic id 1**
  anywhere in the design, and the new store must have **no default** clinic.
  `CLINIC_SCOPE_REQUIRED` stays the fail-closed answer when no trusted scope exists.
* **Stored-payment authorization.** Step 1 authorizes the **URL** payment, but a key hit
  may be bound to **another payment of the same clinic**. Before any outcome derived from a
  stored row is externally observable (replay, 422 key-reuse, spec-unavailable error), the
  actor must be authorized (capability, scope, ownership) for the **stored** payment
  (§4.4 step 0); otherwise the response is one **uniform deny** — the route's existing
  authorization-failure status and body, naming neither key nor payment, identical in
  every unauthorized case. Replay for authorized actors is unchanged. Timing differences
  and exploitability: **UNKNOWN**. Policy and residual: **OQ-9**.
* A payment owned by another clinic keeps returning 404 `CLINIC_NOT_FOUND`
  (`:691-694`), before the key is consulted; the stored result of a foreign
  clinic's key is unreachable by construction.

### 6.4 Failure classification, crash behaviour and retry semantics

**Two different things must never be confused:** a failure that is *known* to have
rolled back, and an outcome that is *unknown*. In the code as it stands they are
confusable: if the `COMMIT` statement itself fails, `transactional()` then tries a
`ROLLBACK` and rethrows (`CpmsDb.php:299-312`). A `COMMIT` error does **not** prove
the commit did not happen (for example the server applied it and the connection
died before the acknowledgement); a following `ROLLBACK` on a dead connection
fails or is a no-op. So a thrown commit/rollback error is **UNKNOWN**, not
"rolled back".

| Point of failure | Known state | Client-visible outcome | Retry with the **same key** |
|---|---|---|---|
| Before step 3 (checks, canonicalization) | nothing written | rejection (400/403/404/422) | fresh request |
| Steps 4-9: a statement fails/throws **and the rollback succeeds** (Requirement W holds) | **rolled back**: no key row, no refund effect, no audit row | "rolled back, retry with the same key" (5xx class; exact mapping UNKNOWN) | fresh request, applies once |
| Deadlock victim / lock-wait timeout | server rolled back the statement or the transaction (InnoDB; **INFERENCE**); the implementation must still issue and confirm a rollback | "rolled back, retry" only after that confirmation, else "outcome unknown — retry with the same key" | fresh request if rolled back; else the same-key reconciliation |
| Step 4: duplicate key | rollback by design (step 5.1), confirmed before the re-read; unconfirmed ⇒ UNKNOWN, "outcome unknown" | replay, 422, or bounded retry | n/a (this *is* the retry path) |
| Rollback itself fails, or connection lost **before** the commit statement was sent | **UNKNOWN to the app**; the server rolls back an uncommitted transaction when the session ends (**INFERENCE**, not tested) | "outcome unknown — retry with the same key" | step 4 finds the row (replay) or not (applies once) — correct either way |
| **Step 10: the commit statement errors or its acknowledgement is lost** | **UNKNOWN** — may be committed | "outcome unknown — retry with the same key"; must **not** say the refund failed | same key ⇒ replay if committed, apply once if not |
| After commit, before the response reaches the client | refund fully applied, snapshot stored | (lost) | **replay** returns the stored result; nothing re-applied |

Rules that follow:

* An uncertain commit is treated like a lost response, **never** as a rollback,
  and never triggers a *new* key (B3, §4.3).
* "Rolled back" may be reported only after a rollback statement positively
  succeeded; otherwise the outcome is "unknown".
* All of the above rely on Requirement W; without it a failed statement could be
  skipped and the transaction committed with a half-applied refund.

Because the key claim, the refund effect, the replay snapshot and the audit row
share **one** transaction, there is no intermediate durable state, hence **no
`pending` key state and no stuck-key recovery job** is needed in option B. (Option
A *does* have the stuck-pending states of Appendix A.1.)

**Retry semantics (proposed):**

* A client retries **only with the same key** after a timeout, a 5xx, a lost
  response or an unknown outcome; a **new** key is used **only for a verified new
  refund intent** (an operator has confirmed that the earlier one did not and will
  not apply, or really wants an additional refund). A key mismatch (422) is **not**
  a reason to create a new key — see §4.3 and **OQ-19**.
* **Proposed default (policy, pending OQ-14 and OQ-18):** a request rejected before
  commit stores nothing, so a corrected request may use the same key; failures are
  not stored for replay. Neither is a design fact. They never apply to a key whose
  refund committed (that key stays bound, see §6.1 step 8).
* How the existing layer maps lock/deadlock errors to HTTP status was not traced
  (**UNKNOWN**).

### 6.5 Lock order and deadlock analysis

**Proposed order of the *explicit* locks of the refund path:** (1) key claim
(unique-index record of the key) → (2) payment row → (3) invoice row → (4) audit
serialization row (`cpms_audit_seq`, last, as today). Steps 4, 6, 7, 9 of §6.1
follow it. **That is all the order guarantees:** locks that InnoDB takes
*implicitly* (shared locks on foreign-key parent rows, gap/next-key locks, locks on
secondary-index records) are **not** ordered by it, are **UNKNOWN** without a
database, and can run against it (items 1-2 below).

| Path | Order | Evidence |
|---|---|---|
| Refund (today) | payment → invoice → audit-seq | `:713`, `:725`, `AuditLogger.php:81` |
| Void (today) | payment → invoice → audit-seq | `:607`, `:630` |
| Capture (today) | invoice → clinic row → insert payment → audit-seq | `:1083`, `lockClinic` `:533`/`:1171`, insert `:536-545` |
| **Refund (proposed)** | key claim → payment → invoice → audit-seq | §6.1 steps 4-9 |

**What can and cannot be said (all INFERENCE; no database was available and no
PHP was executed):**

* Void and refund both take payment → invoice. Refund additionally takes the key
  first; void takes no key. Since the key is acquired before everything else and
  void never waits for a key, a cycle *between these two* is not evident from the
  order of the explicit locks alone. This is a reading, **not** a proof, and it
  ignores the implicit locks of items (1)-(2).
* **Capture takes the invoice first, then inserts a payment row** (which
  references the invoice and, in InnoDB, can involve locks on the parent row and on
  secondary-index records). Refund holds the payment row and then wants the
  invoice. Whether capture-vs-refund (or capture-vs-void) on the same invoice can
  deadlock **was not analysed and is UNKNOWN**. Test T14 must probe it on
  a real database.
* **(1) Foreign-key shared locks (INFERENCE/UNKNOWN).** The refund-row insert, with
  foreign keys to payment, invoice and clinic (Option B; 0017 already has such keys
  on audit/invoice/payment, `0017:23, 29, 35`), may take **shared** locks on those
  parent rows at step 4, *before* the exclusive `FOR UPDATE` of steps 6-7. Two
  refunds with **different keys on the same payment** could then each hold a shared
  lock on the payment and wait for the other's to clear → **deadlock** (InnoDB picks
  a victim). Whether this happens, and under which isolation level, is **UNKNOWN**.
  **Money safety rests on** (given Requirement W) deadlock ⇒ confirmed full rollback (else UNKNOWN) ⇒
  retry with the **same** key (§6.4), with the cap re-checked under the locks — not
  on the deadlock being absent.
* **(2) Audit vs clinic lock-order cycle (UNKNOWN; may already exist in today's
  code).** The refund's audit row takes the clinic from the scope fallback
  (`AuditLogger.php:58-60`; the refund call passes no clinic,
  `FinanceService.php:754-771`), is inserted at `AuditLogger.php:127` **after** the
  global audit lock (`:81`), and `cpms_audit_logs` has a clinic foreign key (0017:23),
  so that insert may need a **shared lock on the clinic row**. Capture takes the
  **exclusive** clinic row lock first (`lockClinic`, `FinanceService.php:533`,
  `:1171-1177`) and only later the audit lock. Refund: audit lock → clinic row;
  capture: clinic row → audit lock — a possible **cycle in today's code**,
  independent of this feature. Not analysed further; **UNKNOWN**; test T14a.
* **Same key, different payments:** the second transaction waits inside step 4
  for the first one's outcome (that is the whole point), holding **no** other lock
  of its own. If the first commits, the waiter gets a duplicate-key error ⇒ step 5.
  If it rolls back, the waiter's insert proceeds. Concurrent *identical-key*
  inserts can themselves produce lock-wait timeouts or deadlock victims in InnoDB
  when several waiters queue on one index record; that is **expected to be
  possible, not proven absent**.
* The audit serialization row is a **global** lock held until commit by every
  audit-writing transaction; refund, void and capture all queue on it at the end.
  It is last among the *explicit* locks of every path, but see item (2): it is
  **not** last once the clinic foreign key is counted. Its throughput effect is
  **not measured**.
* Isolation level, lock-wait timeout and deadlock-detection settings are
  **UNKNOWN** (A12). Gap/next-key locking by the key claim and by locking reads of
  a *missing* key is **UNKNOWN per isolation level** (REPEATABLE READ vs READ
  COMMITTED) and must not be assumed either way.
* The wrapper does not retry (`CpmsDb.php:272-317`). A deadlock victim or
  lock-wait timeout is an error that the implementation must **map explicitly** to
  the retryable outcome of §6.4 — "rolled back" only once confirmed (how the existing layer maps such errors
  to HTTP was not traced — **UNKNOWN**). It is safe to retry **only because** the
  retry carries the same key (§6.4).

---

## 7. Interactions and replay semantics

### 7.1 Replay response: what exactly is returned

**OBSERVED payment (D13) behaviour** (V6): replay = current payment row +
current invoice view + `idempotent_replay` + `code`, HTTP 200 vs 201 on first
success. `SRS.md:281` says "same response"; `error-codes.md:38` says "stored
response". The code does not do either exactly.

**Options for refunds** (**to be decided** in **OQ-2**):

| | Description | Pros | Cons |
|---|---|---|---|
| **R-1 Stored snapshot** | Return the response body exactly as produced at first success (payment projection as of the refund, invoice status/balance as of the refund, applied amount, reason, refund timestamp, actor), plus the replay marker. | Genuinely "the previous result" (R5); immune to later changes (void, later refunds, invoice change); same status code as the first response. | Needs the snapshot stored (more data); the invoice numbers in it can be **stale** relative to now (that is the point, but UIs must not treat it as current). |
| **R-2 Rebuilt current view** (what payment replay does) — **NONCONFORMING, excluded** | Re-read payment/invoice now. | No snapshot storage. | **Not** "the previous result", which the recorded owner decision requires; the owner has **not** reopened it (not documented). After a later void or refund the replay shows different numbers than the original; for an exhausted payment it can look like nothing happened. Listed only so that it is not adopted by accident. |
| **R-3 Hybrid** | Stored refund facts (immutable) in the primary body + a separate `current` block rebuilt now. | Both fidelity and freshness. | Larger/more complex contract; two sources to keep consistent. |

**PROPOSAL:** R-1 (R-3 if the UI needs current balances; R-2 is excluded). R-1 and R-3 require the replay snapshot to be written **in the same transaction** as the refund (§6.1 step 9) and the first response to be built from it (step 12); with R-2 nothing is stored, and the first response and a later replay can legitimately differ.

**Status code and marker (design position):** keep the first-success status
**200** (A5: the only UI caller accepts exactly 200, `SecretaryFinancePage.php:769`)
and return **200** on replay, so the *status is identical* to the original. Mark
the replay inside the success body with `idempotent_replay: true` and
`code: CLINIC_IDEMPOTENCY_REPLAY`, the same shape D13 already uses (a marker in a
success body, **not** the `{code,message,data}` error envelope). Changing the first
refund response to 201 would break the UI caller and make "same response" false by
status alone.

**"Same response" wording.** `SRS.md:281` concerns **payment capture** replay. Whether it
should be corrected (to "stored or rebuilt?") or the **code** changed depends on OQ-2(b),
which is **not decided**, so `SRS.md:281` is **deliberately left unchanged** and listed as
an open item (§12). If the owner confirms that payment replay stays a rebuilt view, the
descriptive wording would be: "a repeat of the same key does not create a second
transaction; the response is rebuilt from the stored payment and the *current* invoice
view, with HTTP 200 and `idempotent_replay: true` / `CLINIC_IDEMPOTENCY_REPLAY`; the first
success is 201."

### 7.2 Partial refunds

Each partial refund is its own idempotent operation with its own key; a key never
covers "all refunds of a payment". The cap (R1) is evaluated under the payment
lock from the locked `refunded_amount` (`:733-746`) and stays the single authority.

### 7.3 Refund exceeding the remaining balance

Rejected 422 `CLINIC_VALIDATION_FAILED` with `data.max_refundable` (today's
behaviour, `:739-746`; note `data.max_refundable` is undocumented in
`error-codes.md`, as already recorded in `agent-log.md`). The failure is **not**
stored — **proposed default, pending OQ-14/OQ-18, not a fact**: the key is then not consumed. A replay of a refund that *previously succeeded*
and exhausted the cap returns the stored result, never this 422 (§6.1 step 2).

### 7.4 Payments already voided

A first-time refund on a non-`captured` payment ⇒ 409 `CLINIC_INVALID_TRANSITION`
(`:717-724`). A replay of a refund that succeeded **before** a (same-day, `:620-628`)
void returns the **stored historical result** (R-1) and does not re-evaluate the
payment status. The void-after-partial-refund hazard is a finding (F2, §8). Whether a *voided* payment should ever be refundable is policy (**OQ-15**); today it is a 409.

### 7.5 Discounts

The cap is the **payment's actual amount** minus already refunded (`:696-699`);
a payment row holds what was actually captured, so the basis is "actual amount paid
after discount" (R1) by construction. Invoice discounts and D15 credit/debit
adjustments change the invoice's effective total (`:1102-1109`), not the payment's
amount, so they do not enter the refund specification and idempotency does not
change them. **Not decided here:** whether a credit/discount adjustment *after* payment can
leave an invoice overpaid (paid more than the effective total) and whether and how
that excess may be refunded through this endpoint, which only knows the payment's
own remaining amount — **OQ-12**.

### 7.6 Fees

R2: fees are separate and never auto-deducted. The specification has **no fee
field** and the applied amount equals the requested amount (or the remaining
balance when omitted) — the service never subtracts anything (`:700-710`). No fee
concept exists in `FinanceService` refund code (`grep` found only an unrelated
doctor-fee adapter, `:435`). If fees are ever recorded they are a **separate
operation with their own key**, not a refund modifier.

### 7.7 Multi-clinic scope

See §6.3: trusted `ScopeContext` only, fail-closed `CLINIC_SCOPE_REQUIRED` 400, no
default clinic, `(clinic, key)` uniqueness, same key in another clinic is
independent. Tests in §10 include a second clinic.

### 7.8 Receipts and void after a partial refund (reported NOT TESTED)

The brief says the round-11 install reported these as **NOT TESTED**; that report was
**not located** (user-reported, not verified). From the code (**OBSERVED, not executed**)
see F1 and F2 (§8). Idempotency fixes neither; replay results must not depend on them and
§10 covers them.

### 7.9 Audit, traceability, no-backfill

* **Date:** server-assigned UTC timestamp at commit (as `paid_at`/`voided_at` are,
  `FinanceService.php:543`, `:642`); the `PAYMENT_REFUND` audit row already carries
  server `created_at`. Client-supplied date: **OQ-3**.
* **Actor:** the authenticated user (as `received_by_wp_user_id`/`voided_by_wp_user_id`
  do for capture/void, `0001:602`, `:605`); audit already records actor and role.
* **Reason:** stored in full (subject to OQ-5) in the refund record, not only
  truncated in the audit meta (A8).
* **Key and `spec_version`** recorded with the refund and in the audit meta.
* **Replays are not financial events** (design position, pending **OQ-13**): no new `PAYMENT_REFUND` audit row. Whether a replay leaves any trace at all (non-chained operational log line, a separate audit action, a counter) is policy and a traceability trade-off — **OQ-13**.
* **No backfill (design assumption — see OQ-10):** refunds that happened before
  deployment have no key, no refund record and cannot be replayed. For such
  payments `payments.refunded_amount` **remains the authority for the cap**; the
  sum of refund records is only a *lower bound*, so any reconciliation must not
  assert equality for payments with pre-deployment refunds.

---

## 8. Adjacent existing defects and findings (reported — NOT fixed, NOT in scope of this PR)

**OBSERVED in code at `d1d49c5`; read, not executed (except the named Node checks); effects are INFERENCE.**

* **F1 — Receipt after a partial refund.** `receipt()` (`FinanceService.php:885-944`)
  lists only payments with `status = captured` and prints each payment's **full
  original** `amount` (`:935-942`) with no refunded amount, while `totals.paid_amount`
  comes from the invoice, which refunds have already reduced. A receipt for a
  partially refunded invoice can therefore show payments summing to more than
  `paid_amount`. A fully refunded payment (`status = refunded`) disappears from the
  list.
* **F2 — Void after a partial refund.** After a partial refund the payment stays
  `captured` (A11), so `voidPayment` is allowed within the void window
  (`:612-628`), and it reverses the **full** payment amount from the invoice
  (`:638-645`, via `reverseInvoicePayment`) without subtracting what the
  partial refund already reversed. The invoice `paid_amount` is floored at 0
  (`:1144`), which can hide the double reversal. Whether this produces a wrong
  `paid_amount`/`balance` in practice was **not executed**; the round-11 install
  also reports it NOT TESTED (user-reported).
* **F3 — Payment replay does not compare specifications** (`:508-515`): the same
  capture key with a different amount/method returns the old payment as a 200
  replay. Out of scope (payments are not the owner's refund decision).
* **F4 — `payment.md:44/51` vs code:** the doc says `refunded` covers partial and
  mentions a "Refund record" (neither exists: A11, V5).
* **F7 — `error-codes.md` does not document `data.max_refundable`.** The refund 422
  carries `['max_refundable' => $maxRefundable]` (`FinanceService.php:697-710`,
  `:739-746`) but `docs/api/error-codes.md` has no entry for it (`CLINIC_VALIDATION_FAILED`
  is documented at `:32` without that field; a `grep` for `max_refundable` in the file
  finds nothing).
* **F8 — No null check after the locked payment read.** `refundPayment` passes
  `findForUpdate($paymentId)` (`:713`; returns `?array`, `PaymentRepository.php:56`) to
  `rowBelongsToTrustedClinic(array $row)` (`:714`) without a null check; the wrapper returns
  `null` both for "no row" and for a failed query (A13), and `null` for an `array`
  parameter raises a PHP `TypeError` (PHP semantics, **not run**) instead of a controlled
  404/5xx; the REST mapping was not traced (**UNKNOWN**). The invoice read has a null
  check (`:725-731`).
* **F5 — Admin refund dialog: amount parsing and no confirmation** (OBSERVED by reading; the
  `parseInt` behaviour reproduced in Node, not in a browser). The amount comes from
  `CpmsDialog.prompt`, a plain `type='text'` input (`assets/js/cpms-dialog.js:128-130`),
  only `trim()`-ed (`:198`); `SecretaryFinancePage.php:765-767` sends `amount` **only if**
  `amt !== '' && !isNaN(parseInt(amt, 10))`. With `parseInt` in Node: Persian/Arabic-Indic
  digits (`۳۰۰۰۰۰`) give `NaN`, so `amount` is **omitted** and the server refunds the
  **whole remaining amount** (A6); `"300,000"`/`"300 000"` give `300`; `"3e5"` gives `3`;
  `"300000.5"` gives `300000`. No confirmation step (`:762-768`); the UI sends no key
  (OQ-20). Not fixed here.
* **F6 — `SRS.md:281` payment-replay wording** says "same response" while the code
  rebuilds the current view (V6); depends on **OQ-2(b)**; left unchanged on purpose.

Items F1 (receipts), F2 (void after partial refund), F5 (UI), F6 (SRS wording),
F7 (`max_refundable` undocumented) and F8 (no null check) are adjacent existing
defects reported by readers or found while checking; **none is fixed or designed
here** (OQ-11).

---

## 9. Open questions for the owner (NOT decided here)

Each lists options, a recommendation, pros/cons and risk. **The recommendation is
not a decision.**

### OQ-1 — Storage option (and fingerprint form)
* **Options:** (A) reuse shared table; (B) new refunds table; (C) key on payment row (not viable); fingerprint: (i) fingerprint only, (ii) canonical fields only, (iii) both.
* **Recommendation:** **B**, with (iii) both (fields give history/R3; fingerprint matches Record A).
* **Pros (B):** exact DB uniqueness, crash-atomic, closes the per-refund-history gap. **Cons (B):** migration, two finance reviews, owner approval, dual bookkeeping with `refunded_amount`.
* **Pros (A):** no DDL. **Cons (A):** not atomic, convention-only uniqueness, 90-day purge, fail-open, edits shared booking/handwriting code to fix.
* **Version handling:** §4.4 (V-1 per-version canonicalizers — PROPOSAL; V-2 frozen; V-3 re-canonicalize, impossible with fingerprint-only storage (i)).
* **Risk:** B — schema change on a finance table (needs the R8 approval path); A — silent double-apply or stuck keys; a wrong choice here is hard to reverse once data exists.

### OQ-2 — Replay semantics: (a) refund replay body/status; (b) payment replay wording
* **Options (a):** R-1 stored snapshot / R-3 hybrid (conforming); **R-2 rebuilt view is excluded as nonconforming** with the recorded decision "same key + same specification returns the previous result" unless the owner explicitly reopens it; status 200 vs 201. **Options (b):** (b1) keep payment replay as is (rebuilt, 200/201) and **correct the docs** (`SRS.md:281`, `error-codes.md:38`) to say so; (b2) change payment replay to return a stored snapshot (code change, behaviour change).
* **PROPOSAL:** (a) R-1 with 200; (b) b1 (documentation-only, no behaviour change) — but the owner must say so before `SRS.md:281` is edited.
* **Pros (b1):** no code risk. **Cons (b1):** payment "same response" promise is formally weakened. **Pros (b2):** the promise becomes literally true. **Cons (b2):** needs payment snapshot storage (maybe schema), touches proven code.
* **Risk:** (a) the excluded R-2 would mislead reconcilers after later changes; (b) editing the SRS without the decision would record an unapproved behaviour.

### OQ-3 — Refund date: server-assigned only, or client-supplied/back-dated?
* **Options:** server-assigned UTC only; operator-supplied effective date (validated range) in addition to server time.
* **Recommendation:** server-assigned only.
* **Pros:** no back-dating, spec unaffected, audit-aligned. **Cons:** a refund physically paid yesterday cannot be dated yesterday.
* **Risk:** if back-dating is later required it becomes a spec/contract change (the date would have to join the specification).

### OQ-4 — Is "amount omitted" the same specification as "explicit amount equal to the remainder"?
* **Options:** different intents (recommended) / equivalent / require an explicit amount (remove the omit-means-all shortcut).
* **Recommendation:** different intents; keep the omit shortcut.
* **Pros:** fail-safe, simple. **Cons:** a client switching form on retry gets a 422 instead of a replay. **Risk (equivalent):** state-dependent comparison — fragile after the first refund exhausts the balance.

### OQ-5 — Reason normalization and length
* **Options:** (i) trim + NFC, reject over-255, reject invisible-only; (ii) trim only (today), truncate at 255 in audit as today; (iii) extend the patient-name rule (§3.3 of the ledger) wholesale.
* **Recommendation:** (i), subject to `intl` availability (UNKNOWN).
* **Pros:** stored reason equals compared reason; no silent truncation. **Cons:** behaviour change (long reasons now fail); NFC needs `intl`.
* **Risk:** (ii) two reasons differing after char 255 are different specs yet persist identically.

### OQ-6 — Key retention (partly settled)
* **Binding (recorded):** the key is DB-unique per clinic; same key + same specification returns the previous result; same key + different specification is rejected. No expiry or reuse may be introduced without an owner decision (none is recorded).
* **Still open (owner):** legal retention duration of refund/key records and the consequences of any deletion.
* **PROPOSAL:** keep the key and its record for as long as the refund record exists — no expiry, no reuse. **Excluded unless the owner reopens the decision in writing:** a bounded window after which the key may be reused for a different refund (the same key would silently mean a *new* refund; risk: silent double refund).
* **Pros:** a very late retry can never double-apply. **Cons:** the table grows with refunds (not measured).

### OQ-7 — Rollout of the required key for existing clients (partly settled)
* **Binding (recorded):** a refund MUST get an idempotency key (Record A), so there is no unprotected window.
* **Still open:** the exact rollout mechanism for existing callers.
* **Options:** (a) required at once, the UI sending a UUID in the same release — **PROPOSAL** (the only observed caller is the admin finance UI, `SecretaryFinancePage.php:768`, which sends no key and requires status 200); (c) required at once, API clients informed in advance. **Excluded unless reopened:** (b) a transitional period with an optional key (requests without a key would apply unprotected).
* **Pros (a):** no unprotected window. **Cons (a):** breaking for any unobserved external client (none observed in the repository; external ones **UNKNOWN**).

### OQ-8 — Mismatch response contract
* **Options:** 422 `CLINIC_IDEMPOTENCY_KEY_REUSED` (candidate); 409 with a new code; 400.
* **Recommendation:** 422 + new registered code.
* **Pros:** distinguishes from 409 in-flight. **Cons:** a new code to document. **Risk:** low (API naming); needs the `ADR-0019` registry entry in the implementing PR.

### OQ-9 — Different authorized actor, same key; stored-payment authorization
* **Options (actor):** replay for any authorized actor in the clinic (R4: unique per clinic); only for the original actor; reject.
* **PROPOSAL (actor):** per clinic, authorization re-run first, original actor recorded. **Pros:** matches R4, supports a double click by two staff. **Cons:** a second user sees the first user's result (both authorized). **Risk:** the doctor ownership rule (`authorize_payment_ownership`, `:1323-1345`) must stay enforced first.
* **Oracle (OBSERVED/INFERENCE).** Authorization runs against the **URL** payment, then the key is read by (clinic, key). An actor authorized for payment P can send a key bound to another same-clinic payment: a used key answers 422 key-reuse (or the distinct spec-unavailable 5xx) where a fresh key proceeds, so the actor learns that K is in use, possibly on a payment they may not own. The body discloses nothing else (§4.5). Exposure: same clinic only, actors already allowed to refund, and only useful if keys can be guessed — the server accepts **any UUID-shaped text** (`RestBase.php:304-311`, no version/variant check). A probe with a fresh key applies a refund when the request is valid (INFERENCE), which limits cheap probing. Timing differences and real exploitability: **UNKNOWN**.
* **Design requirement (§4.4 step 0, §6.3):** authorize against the **stored** payment before any outcome derived from the row is observable, with one uniform non-disclosing deny response for unauthorized actors; replay for authorized actors is unchanged. **Alternatives:** a uniform generic 422 without stored-payment authorization (an actor with no right to the stored payment still sees a different path, and the spec-unavailable 5xx stays distinguishable) — not enough; per-actor or per-payment key namespaces — **excluded** (contradict "unique per clinic"). **Residual (accepted, to be confirmed by the owner):** a used-by-another-payment key still differs from a fresh key (deny vs proceed); mitigated by key entropy (complementary engineering items: require UUID v4; log mismatches, OQ-13).

### OQ-10 — No backfill of legacy refunds (a design assumption, not a recorded decision)
* **Options:** no backfill (recommended); synthesize refund records from `PAYMENT_REFUND` audit rows.
* **Recommendation:** no backfill.
* **Pros:** no data migration on finance history; the audit remains the legacy source. **Cons:** history is split (refund table for new, audit for old); reconciliation must tolerate `refunded_amount ≥ sum(rows)`.
* **Risk (backfill):** audit `meta.reason` is truncated at 255 and amounts may be inconsistent; reconstruction could invent history.

### OQ-11 — Scope of the two adjacent findings (F1 receipt, F2 void-after-partial-refund)
* **Options:** separate PRs/decisions (recommended); fold into this feature.
* **Recommendation:** separate; this design must not depend on them.
* **Pros:** keeps the idempotency change reviewable. **Cons:** the defects stay open meanwhile. **Risk:** F2 can mis-state invoice balances (INFERENCE) and deserves its own decision (block void when `refunded_amount > 0`, void only the remainder, or leave) — not decided here.

### OQ-12 — Refund when an invoice later becomes overpaid (partly settled)
* **Context:** the cap is the *payment's* remaining amount (§7.5). A later discount or credit adjustment (`:1102-1109`, D15) can make an invoice's effective total lower than what was paid; the excess is not represented in the payment row.
* **Binding (recorded):** the refund is based on the actual amount paid after discount, and the total refunded never exceeds what was paid — the cap stays binding in every option.
* **Still open (owner):** how an overpayment created by a later adjustment is treated (§7.5 records it as undecided).
* **Options:** (a) refunds stay limited to the payment's remaining amount; the overpayment is handled outside this feature — **PROPOSAL**; (b) refunding an invoice-level excess through this endpoint (a second cap authority; must still keep the total ≤ paid); (c) forbidding such adjustments after payment (a discount-policy decision).
* **Pros (a):** no change to cap authority. **Cons (a):** the overpayment has no refund path in the system.

### OQ-13 — Does a replay leave an audit trace?
* **Options:** (a) none (replays are not financial events); (b) a non-chained operational log line; (c) a separate audit action (e.g. replay) in the hash-chained audit log; (d) a counter on the refund row.
* **Recommendation:** (b) — lets staff diagnose retries without touching the financial audit chain.
* **Pros (a):** simplest, no extra audit-seq contention. **Cons (a):** no evidence that a retry happened. **Pros/Cons (c):** full trace but each replay takes the global audit lock and grows the chain. **Risk:** (a) hides client retry storms; (c) lets a retry loop flood the audit table.

### OQ-14 — Failed-result replay, tombstones, audit retention
* **Questions:** should a *rejected* request (422 cap, 409 status) be stored and replayed for the same key? Should a "tombstone" prevent reuse of a key whose request ended in a confirmed rollback (nothing committed)? How long must refund rows and the `PAYMENT_REFUND` audit rows be kept (no purge job exists for the refunds table by design; audit retention not examined)?
* **Options:** (a) store nothing for failures (the proposed default, not a decision); (b) store failures as tombstones with the fingerprint; (c) store only certain failures.
* **Recommendation:** (a) with indefinite retention of successful refund rows.
* **Pros (a):** simple, no unbounded junk. **Cons (a):** a retry of a rejected request is re-evaluated against current state (may now succeed). **Pros (b):** deterministic replay of failures. **Cons (b):** more storage and states; a transient failure becomes permanent unless handled. **Risk:** inconsistent retention between refund rows and the audit log.

### OQ-15 — Refund of a voided payment
* **Observed:** today a non-`captured` payment ⇒ 409 (`:717-724`); a voided payment cannot be refunded. A same-day void after a partial refund is possible (F2).
* **Options:** (a) keep: voided payments are never refundable; (b) allow refunding a remaining amount after a void, under defined accounting; (c) block void when `refunded_amount > 0`.
* **Recommendation:** (a) for this feature; (c) belongs with the F2 decision.
* **Pros (a):** no change. **Cons (a):** cash already refunded before a void is not reconciled by the system. **Risk (b):** double reversal against the invoice.

### OQ-16 — Currency precision and the amount wire grammar
* **Observed:** whole rials only; no currency column on payments; the invoice has a currency, default `IRR` (`0001:557`); storage `DECIMAL(12,2)`; WordPress decodes JSON before any callback and converts a `type: number` argument with `(float)` (§4.2; source read, not run; installed version UNKNOWN); `toMinor` then accepts numeric strings through float (A7).
* **Options:** the (a) string / (b) raw-token parse / (c) numeric type table of §4.2, with its consequences. Keeping today's behaviour unchanged is (c) without the stated acceptance.
* **PROPOSAL:** (a) if the owner accepts the API contract change; (c) only with the owner's explicit acceptance of the residual; (b) only if JSON-integer compatibility is mandatory and exactness required. All options need Requirements T and A (§4.2). Fractional currencies later = a new `spec_version`.
* **Pros/cons and risk:** §4.2 table. **Owner decision** (API contract; whether exactness beyond half a unit-in-the-last-place is required); the mechanism inside the chosen option is an engineering decision.

### OQ-17 — Permissions for refunds
* **Observed:** capability `payment.refund`, scoped authorization and a doctor-ownership rule (`FinanceService.php:682-695`).
* **Options:** (a) unchanged; (b) amount threshold requiring a second approver; (c) refund only by the original capturing actor/role; (d) separate capability for replay-visible results.
* **Recommendation:** (a) for this feature; thresholds/approvals are a finance policy decision.
* **Pros (a):** no new policy. **Cons (a):** any holder of the capability may refund up to the cap. **Risk:** (b)/(c) change who can do daily work; not designed here.

### OQ-18 — May a rejected key be corrected and reused?
* **Proposed default (not a fact, pending this question):** a request rejected before commit stores nothing (§6.1 step 8). A key whose refund committed stays bound whatever is decided here (recorded decision).
* **Options:** (a) the operator may correct the request and reuse the same key; (b) a rejected key is consumed/burned (needs tombstones, OQ-14); (c) reuse allowed only for validation-class rejections (400/422 before any key claim), not after a claim was made.
* **Recommendation:** (a), consistent with the proposed default of storing nothing; (c) if the owner prefers stricter intent tracking.
* **Pros (a):** simple; no stranded keys. **Cons (a):** the same key can mean different specifications at different times (only one can ever commit). **Risk:** if the first request actually committed and its response was lost, a *corrected* retry hits 422 mismatch — safe, but confusing without OQ-19.

### OQ-19 — Recovery workflow after a key mismatch or an unknown outcome
* **Context:** a 422 key-reuse can be an operator error **or** the signal that the first request committed and its response was lost; it never proves that the first request failed. While the intent is unresolved a **new key must not be the answer**: it can apply the same refund twice (§4.3, T25).
* **Rule (design position):** a replacement key for an intent is allowed only after **definitive reconciliation** — the stored result for K was read — or after fencing that excludes any in-flight request (OQ-20 rule 3), or an explicit authorized override (owner workflow). A key-less refund-history view may **assist** an investigation but never authorizes replacing an unresolved intent: it can be empty while the original request is still in flight (INFERENCE).
* **Options:** (a) the UI shows the stored refund for K (a read endpoint, scoped and authorized per §6.3) — **PROPOSAL**; (b) the operator checks the payment's refund history/receipt (assist only; F1); (c) an authorized "lookup by key" support tool (needs the key).
* **Pros (a):** removes the double-refund path. **Cons (a):** a new read contract and UI. **Risk (b):** human reconciliation of the F1 receipt problem; an empty history proves nothing.

### OQ-20 — UI: one key per refund intent, kept until resolved
* **Observed:** the admin UI sends no key today (F5).
* **Requirements (design position; owner/design decision):**
  1. **One key per refund intent**, created when the operator starts the refund and stored with the intent's specification (payment, amount, reason). The same key and specification are reused for double clicks, timeouts, retries, **page reload and abort/cancel**; the button is disabled while a request is in flight. Editing the specification is a **different** intent, blocked while the earlier one is unresolved.
  2. **Resolved** (the key may be cleared) only by a first-success or replay response (the refund exists), or by a rejection of a **new claim** (validation, cap, status) for the **first** request ever sent with that key. A 422 key-reuse, a timeout, an unknown outcome, a lost response, or any rejection after an earlier send with that key are **unresolved**; a mismatch after a lost success does not prove the original failed.
  3. **Fence:** while an intent is unresolved, no new key for that payment — the intent stays visible and blocks a new refund until resolved or an authorized override.
  4. **Key lost while in-flight cannot be excluded** (profile cleared, another terminal): there is no safe automatic path; the case goes to the authorized reconciliation workflow (OQ-19). History assists and never authorizes a replacement.
  5. **Browser persistence (option c):** the record is bound to (clinic, user, payment, intent); a key is never offered to another user, clinic or payment; it is kept until resolved (no automatic expiry while unresolved, also across logout); creation is atomic per (clinic, user, payment) across tabs — a second tab loads the existing intent instead of creating one (mechanism UNKNOWN, browser behaviour not tested). Shared workstations and profile loss are **not** covered (residual; reason to prefer (d)).
* **Options:** (a) memory only — **does not meet** the requirements; (b) per click — **not acceptable**; (c) browser storage under rules 1-5; (d) key issued by the server in a "begin refund" call with a stored intent record (survives client loss, allows server-side fencing; new table/endpoint: schema, owner approval); (e) no persistence, history check only — assist only, cannot authorize a replacement.
* **PROPOSAL:** (c) with rules 1-5 for the first implementation; (d) if the owner wants server-side fencing/no loss; (e) only as an investigation aid. No UI code is changed by this PR.
* **Pros (c):** no API change beyond the header. **Cons (c):** browser-local. **Risk (a):** every reload during an unknown outcome invites a second key. **Risk (b):** every double click becomes a new refund.

### OQ-21 — Mechanism for guaranteeing write failure handling (engineering decision, §6.0)
* **Options:** W-1 explicit result checks, W-2 strict mode for the scope, W-3 both.
* **Recommendation:** none made; W-1 or W-3 are the lower-surprise candidates because W-2 changes a shared wrapper flag for everything running in scope.
* **Pros/Cons:** §6.0 table. **Risk:** any gap means a statement can fail silently while the transaction commits.

---

## 10. Test plan (descriptions only — no tests are written by this PR)

The existing `RefundLostUpdateTest` uses a fake `wpdb` with **no MVCC and no real
row locks**; it cannot prove lock behaviour (`docs/agent-log.md`, PR #329 entry).
Items marked **[real DB]** need two real connections to MySQL/MariaDB (CI
Integration suite); none exists today for refunds.

**Same key, same specification**
* T1 Sequential: first request applies; second returns the **stored** result, HTTP 200, `idempotent_replay` marker; counts of refund rows, `refunded_amount`, invoice fields and audit rows unchanged after the replay.
* T2 Replay after the refund exhausted the balance (status `refunded`): returns the stored result, not 409/422 (verifies the §6.1 step-2 reordering).
* T3 Replay after a later *different-key* refund, after a same-day void, after the invoice is voided: still the original snapshot.
* T4 Replay with the actor's capability revoked / a doctor who does not own the payment: authorization error, not the stored result (§6.3).
* T4a **Stored-payment authorization (§6.3, OQ-9):** key K bound to payment P1 (same clinic) that the actor may not access; the actor sends K for a payment P2 they may access with (i) P1's specification, (ii) a different specification, (iii) K's version unavailable ⇒ one identical deny response (status, code, body) in all three, no state change; an actor authorized for P1 gets replay / 422 / spec-unavailable; response timing is **recorded** (UNKNOWN today).

**Same key, different specification**
* T5 Different amount; different reason; different payment; omitted vs explicit amount (OQ-4 outcome) ⇒ 422 `…KEY_REUSED`, **no** state change (row counts, balances, audit chain `prev_hash`/`row_hash` continuity unchanged).
* T6 Body differing only in an ignored field ⇒ treated as the same specification.

**Canonicalization (table-driven)**
* T7 Amount grammar, **option-specific** (per OQ-16) and table-driven; first **characterize today's behaviour**. For every option the service-level parser (Requirement A, not the schema `pattern`) rejects for a new claim: zero, negatives, signs, spaces, a newline (leading, trailing, embedded, e.g. `123\n`), thousands separators, leading zeros, non-ASCII digits (Persian/Arabic-Indic, full-width), 11 or more digits, values above 9,999,999,999. **(a)** additionally rejects every JSON number; accepts strings of 1-10 ASCII digits. **(b)** accepts only integer lexemes; rejects `1000.5`, `1000.0`, `1e3`, `1e400`, over-precise decimals, values above 2^53. **(c)** records which of `1000.0`, `1e3`, `300000.00000000001`, `9007199254740993` become the same integer; those the owner accepted are asserted as accepted, all others rejected; `INF`/`1e400` behaviour recorded (UNKNOWN today).
* T7a **Amount at the real REST dispatch** (not by calling the service; record the WordPress and PHP versions). First *characterize today*: for each input record the PHP type and value the handler receives — JSON integer `300000`, `300000.0`, `300000.00000000001`, `1e3`, `1e400`, `9007199254740993`; the same as JSON strings; as query and form values; `{"amount":1000,"amount":2000}`; an escaped member name. Assert that the route declares no `type`/`pattern`/callback for `amount` or `reason` (Requirement T) and that the handler receives the wire value unchanged. Then per chosen option: **(a)** a JSON number is refused **by the service** for a new claim, strings are exact; **(b)** only integer lexemes are accepted and duplicates, other sources and over-precise input are refused; **(c)** record exactly which inputs became identical. A rejected input leaves no committed state.
* T8 Reasons: leading/trailing ASCII and Unicode spaces; NFC vs NFD forms of the same text (if NFC is adopted); bidi controls; zero-width characters; Persian/Arabic letter variants and digit systems (must compare **different**); 255/256-character boundary; invalid UTF-8.
* T27 **Replay across a spec-version upgrade — explicit schedule, all through the real REST dispatch** (T7a harness; two app instances, one database: A runs V1, B runs V2; V2 rejects reasons above 100 characters, V1 allows 255). Refund R1 = key K, payment P, a 150-character reason.
  1. *Committed.* A applies R1 and commits. Identical R1 to B: B's step 1a finds the row, `DECIDE` runs under V1 ⇒ replay (200 + marker, stored snapshot), **not** a V2 validation error; refund rows, `refunded_amount` and audit rows unchanged. A different amount to B ⇒ 422 key-reuse. With V1's canonicalizer made unavailable on B ⇒ the distinct spec-unavailable error, no state change, not the 422.
  2. *Race.* A test hook pauses A after step 4 (claim inserted, uncommitted). Identical R1 to B: plain read misses, V2 validation fails ⇒ confirm mode, B's temporary claim waits on A's row (record whether it waits or errors at once). Release A: A commits; B gets the duplicate-key error, rolls back (confirmed), re-reads fresh, `DECIDE` under V1 ⇒ replay. Assert B **never** returns the V2 rejection; one refund row, one audit row.
  3. *Race, first request fails.* As 2, but A fails before commit with a confirmed rollback: B's temporary claim is inserted, B rolls back (confirmed) and returns the V2 rejection; no committed key row, no refund. If B's rollback cannot be confirmed the outcome is UNKNOWN, not the rejection.
  4. *Race, timeout.* As 2 with B's lock wait forced to time out ⇒ B returns the retryable/unknown outcome, **not** the rejection; a same-key retry after A commits ⇒ replay.
  5. *Reverse (same intent).* B holds an uncommitted V2 claim for K; the identical request goes to A (V1): after B commits, A finds a V2 row ⇒ spec-unavailable error; assert that **A applied nothing new** (the refund B committed is the only one). K is kept and resolved by retrying on a compatible instance (V2) ⇒ replay; assert still exactly one refund for the intent. A **new key** for the same intent is **not** a resolution (it applies a second refund while the cap permits — the T25 hazard); a new key is used only for a verified *different* intent.
  6. *Transport, committed (numeric → string).* V1 accepts a JSON-number amount; V2's new-claim grammar is string-only. A (V1) applies R1 with `"amount":300000` and commits; the response is lost. The identical numeric request to B: assert **no** route-level rejection (`rest_invalid_param`) and that B's `DECIDE` runs under V1's grammar ⇒ replay, counts unchanged. A **new** numeric request with a new key on B ⇒ rejected by V2's service grammar, no committed key row, no refund. The same K with a different amount ⇒ 422 key-reuse.
  7. *Transport, in flight.* As 6 with A paused after the claim: B's numeric retry misses the plain read, fails V2's grammar ⇒ confirm mode, waits, then replays after A commits — never a V2 validation or route error; if A fails with a confirmed rollback, B returns the V2 grammar rejection and no committed key row remains.
* T9 Key format: missing ⇒ 400; non-UUID ⇒ 400; uppercase UUID equals its lowercase form.

**Concurrency [real DB]**
* T10 N parallel identical requests ⇒ exactly one refund. Each request ends as the first success, a replay, or — if a lock-wait timeout, a deadlock victim or the bounded duplicate-retry (§6.1 step 5.4) is hit — "rolled back, retry with the same key" (confirmed) or "outcome unknown — retry with the same key"; the test then retries with the **same** key until every request has converged (a timeout may need a later retry) and asserts one refund row, one `refunded_amount` increment and one audit row. Never two refunds.
* T11 Parallel same key, different spec ⇒ exactly one applied, the rest 422 **or** (after a timeout/deadlock/unknown outcome) a retryable outcome whose same-key retry then gets the 422; no partial state.
* T12 Parallel same key on two different payments (same invoice and different invoices) ⇒ one applied, the other 422; **records** whether a deadlock or lock-wait timeout occurs (UNKNOWN today; none is assumed absent); every deadlock/timeout that does occur must end in a confirmed full rollback (or be reported UNKNOWN) and a retry with the **same key** must converge to exactly one refund.
* T13 Parallel different keys whose sum exceeds the cap ⇒ total refunded never exceeds the amount paid; the surplus request gets 422 with the locked `max_refundable` (after any retryable outcome, via a same-key retry).
* T14 Refund vs void and refund vs capture on the same invoice (lock-order probe; records whether a deadlock is ever observed — currently UNKNOWN).
* T14a **Audit/clinic cycle (§6.5 item 2):** capture and refund (and capture and void) on the same clinic concurrently, first against **today's code** (baseline: does the cycle exist without this feature?) and then with the feature; records deadlocks; every victim ends in a confirmed full rollback (or is reported UNKNOWN) and a same-key retry converges.
* T12a **Foreign-key shared locks (§6.5 item 1):** two refunds with **different keys on the same payment** in parallel, with the real foreign keys and the real isolation level; records whether a deadlock occurs; the victim ends in a confirmed full rollback (or UNKNOWN), retries with its own same key, and the final `refunded_amount` ≤ amount paid with exactly the committed refunds recorded.
* T15 Establish on a real database, under REPEATABLE READ and READ COMMITTED, what a duplicate-key insert and a read inside the *failed* transaction can see, what locks a key claim and a locking read of a *missing* key take (gap/next-key), and that the fresh-transaction re-read of §6.1 step 5 sees the winner's committed row. These facts are UNKNOWN today.

**Crash / rollback**
* T16 **Failure injection per statement** under the real wrapper in its default soft mode: make each statement fail in turn (key claim insert, payment lock read, invoice lock read, key-row completion, payment update, invoice update, audit insert, audit-seq update) ⇒ the outcome is a confirmed full rollback (or, if unconfirmed, the unknown outcome), **never** a committed half-applied refund; a failed key insert is not treated as duplicate; a failed lock read is not treated as "row not found"; a retry with the same key then applies exactly once. (The commit statement is tested separately in T17a.)
* T17 Lost response **after a confirmed commit** ⇒ the retry replays the stored original; counts of refund rows, `refunded_amount`, invoice fields and audit rows unchanged by the replay.
* T17a **Commit outcome unknown** (the commit statement errors or its acknowledgement is dropped, with the commit both having and not having reached the server) ⇒ the system reports "outcome unknown — retry with the same key", **not** "rolled back"; the retry converges to exactly one refund in both variants.
* T18 Audit failure (guard row missing) ⇒ whole refund aborted by a confirmed rollback, no key row left behind.
* T25 **Lost response + mismatch:** first refund with key K commits and its response is lost; the client sends a **different** specification with the same K ⇒ 422, no state change, and the stored result for K is unchanged. Then the client (wrongly) uses a **new** key for the same intent ⇒ documented as the double-apply hazard it is (the test pins that the system does *not* detect it), which is why the UI/workflow (OQ-19, OQ-20) must prevent it.
* T26 Duplicate-key path: the loser of a same-key race rolls back (confirmed) and re-reads in a fresh transaction (not a continued snapshot) and finds the winner's committed row under REPEATABLE READ and READ COMMITTED; if the winner rolled back, the loser retries a bounded number of times and never applies silently after the bound.

**Cap never exceeded**
* T19 Property-style: random interleavings of refunds (same/different keys, retries) on one payment ⇒ `refunded_amount ≤ amount` always and `refunded_amount == legacy + Σ applied amounts`.

**Multi-clinic**
* T20 Same key in clinic A and clinic B ⇒ both succeed independently; clinic A cannot replay/see clinic B's result; a payment of another clinic ⇒ 404 before any key lookup.
* T21 No trusted scope ⇒ `CLINIC_SCOPE_REQUIRED`, nothing written; `bin/tenant-tripwire.py` passes (no clinic id literal 1, no default clinic).

**Legacy and adjacent behaviour**
* T22 Payment with a pre-existing refund (non-zero `refunded_amount`, no refund record): new refund respects the cap; no error from the absence of earlier records.
* T23 Receipt and void after a partial refund (F1, F2): characterize the **current** behaviour first, because the round-11 report says NOT TESTED; no behaviour is asserted as correct until the owner decides (OQ-11).
* T24 UI contract (OQ-19, OQ-20; browser behaviour **recorded**, none assumed): one key per refund intent, reused for double clicks, timeouts, retries, **page reload and abort** while unresolved; the key is bound to (clinic, user, payment, intent) and is not offered to another user, clinic or payment; after a 422 key-reuse, a timeout or an unknown outcome the key is **not** cleared and **no new key** is created for that payment (fence) until a stored result was read or an authorized override; a key is cleared only after a first-success or replay response, or a rejection of the first request ever sent with it; two tabs open on the same payment share one intent (no second key, no second intent); logout and another user on the same browser do not expose or reuse the key; loss of the key with an unresolved intent ends in the reconciliation workflow, never an automatic new key; it handles 200 for first success and replay, shows the 422 message **without** offering a new key, and its amount handling is characterized first (F5: Persian digits, thousands separators).

---

## 11. Limitations and what was not done

* **No PHP, WordPress, database or browser code was executed.** The only executed checks are Node/JavaScript (not PHP, not a browser): `parseInt` on the dialog strings (F5); `Number()` of `300000.00000000001`, `300000.5`, `1e400`, `1e3`, `9007199254740993`; the spacing of IEEE-754 doubles near 1,000, 300,000 and 9,999,999,999 and `Number()` of `9999999999.0000009` / `9999999999.000002` (§4.2). Local PHP is **PHP-WASM only** (no `php` binary); there is no MySQL/MariaDB in the authoring sandbox.
* Every lock, isolation, crash, REST-coercion, `json_decode`, `pattern` and PCRE-anchoring statement is **INFERENCE** from reading code (WordPress source from `wordpress-develop` branch `6.9`; the installed WordPress version is UNKNOWN, and CI runs another WordPress line whose source was not read) and from general InnoDB/PCRE semantics; none was measured.
* The earlier "version two" refund design/migration was **not read and not used**; none is present in `main`. The round-11 "NOT TESTED" report was **not located**. External standards/vendor documentation could not be consulted (network limited to GitHub and package registries).
* **UNKNOWN:** `intl` (`Normalizer`) availability; DB isolation level and lock-wait settings; how `CpmsDb` maps lock errors to HTTP; capture-vs-refund lock ordering (deadlock freedom is **not** claimed); InnoDB duplicate-key/gap-lock behaviour per isolation level; PHP numeric-string behaviour and `(int)` of `INF`/out-of-range floats; browser storage behaviour.
* This document cannot establish that the defect has occurred on any installation (`ledger.md:135`).

## 12. Documentation corrections made in the same PR, and open items

### 12.1 Corrections to other docs

Documentation only; no line added or removed in the edited files.

| File | Change |
|---|---|
| `docs/srs/SRS.md:296` | Names the **shared table** and **refunds** explicitly and states that the code-gap description is about the shared table, not evidence about the owner's refund target — removing the contradiction with `SRS.md:294`. |
| `docs/srs/SRS.md:294` and `docs/api/api-contract.md:28` | Both now carry the same decision wording, including the **request fingerprint** from ledger Record A (previously `api-contract.md:28` had it, `SRS.md:294` omitted it), with a note that Record B states the same comparison as "identical parameters". |
| `docs/agent-log.md` (append-only) | A correction entry for an earlier statement about the request fingerprint (only `api-contract.md:28` had it); earlier entries untouched. |

**Open items deliberately not changed:** `SRS.md:281` (payment-replay "same response", OQ-2(b)); `error-codes.md:38`; `payment.md:44/51` (F4); `api-contract.md:29-30` and `data-dictionary.md:125` (still describe the shared table's gap in wording like the old `SRS.md:296`) — reported, not edited, to stay in scope.

---

## Appendix (NON-NORMATIVE): superseded options and revision history

*Nothing here adds or changes a rule; it keeps what was moved out of the normative sections.*

### A.1 Rejected storage options (moved from §5; reasons unchanged)
* **A — shared table `cpms_idempotency_keys`** (`endpoint='finance.refund'`, constants for user/context): no DDL for the key row, but no column for the fingerprint (`response_json` is written only at `complete()`, `Idempotency.php:87-104`); uniqueness by caller convention (five-column index, `0020:81`; `context_id = payment_id` or `wp_user_id = actor` makes the same key on another payment/actor a different row, violating R6); the claim is a separate auto-committed statement (A1), so a crash leaves `pending` (409 in-flight although applied) until the 90-day purge (A3), after which a retry double-applies; the shared check fails open (A2); the purge must be disabled by a change in a shared handler; making the claim transactional changes booking/handwriting code; the stored result is a response, not a refund record (R3 not advanced). Verdict: does not honestly meet R4.
* **C — key column on the payment row:** one column cannot hold the keys of many partial refunds; overwriting forgets earlier keys (a retry looks new ⇒ double-apply); a list column cannot be DB-unique. Not viable.
* **D — audit log as key store:** non-unique indexes only (`0001:695-698`) and a global hash-chain lock (A9). Not viable.

### A.2 Retracted statements (kept so they are not reintroduced)
Exhausted-balance retry returns 422, not 409 (§3.1). Clinic `DEFAULT 1` is not a hazard on a migrated installation (A4). "Fractional amount rejected, no rounding" was wrong: WordPress coerces with `(float)` (§4.2). "Any failing statement rolls everything back" was wrong: the wrapper is soft-mode (§6.0). "No wait cycle is evident" is withdrawn (§6.5). "Removing `type: number` preserves the number text" was wrong: WordPress decodes JSON first (§4.2).

### B.1 Revision history (details: `docs/agent-log.md`, PR description)
| Round | After | Main changes |
|---|---|---|
| 2 | two finance reads of `37e8a2df…` | soft-mode wrapper (A13/A14, Requirement W); one protocol, lock order and failure matrix; unknown commit ≠ rollback; no new key after a mismatch; 422 not 409; OQ-12…21. |
| 3 | two finance reads of `802a97d1…` | stored `spec_version` selects the canonicalizer; REST float coercion; commit excluded from Requirement W; FK/audit lock caveats; key consumption as a proposed default; F7/F8. |
| 4 | one finance read of `86fdc873…` | mixed-version race (§6.1a); raw JSON-token boundary; same-clinic key oracle; wording rule; R-2 excluded; persistent UI key; summary. |
| 5 | two finance reads of `8e8a83b9…` | transport boundary and full-string parser (Requirements T, A); stored-payment authorization; reverse-version and lost-key rules (T27, OQ-19/20); "rolled back" needs whole-transaction evidence; partly-settled OQ-6/7/12; document compacted (superseded options and history moved here). |
