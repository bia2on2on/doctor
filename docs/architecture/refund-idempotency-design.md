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
| **Revision** | **Round 2** (after two independent finance reads of head `37e8a2df…`, both reporting a blocker). Changes are listed in §12.2. Still design only. |
| **Location rationale** | `docs/architecture/` already holds the project's design documents that are explicitly "design only / not yet implemented" (e.g. `phase2-tenant-context-remediation-design.md`). `docs/decisions/` holds *owner decisions*, which this is not; `docs/adr/` holds *accepted* ADRs, which this is not. |
| **Language** | English (the governance docs `engineering-tooling.md` and several recent decision records are English; `ledger.md` §3.2 is the Persian/English source of the requirements). |
| **Evidence labels** | **OBSERVED** = read in the cited file at the base commit, not executed. **INFERENCE** = reasoning from observations, not proven by execution. **LIMITATION** = what this work could not establish. **UNKNOWN** = not proven. **OWNER-DECIDED** = recorded as such in `docs/status/ledger.md`. **OPEN QUESTION (OQ-n)** = not owner-decided; options are in §9. |

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

**Note on the "request fingerprint".** Record A (the repository-verified verbatim
original, `ledger.md:129`) says the key is "unique per clinic, **carrying a request
fingerprint**, enforced by a DB unique constraint, and concurrent-safe". Record B
(owner relay, `:133`) expresses the comparison as "identical parameters". The ledger
(`:135`) says A and B are consistent. The task brief that commissioned this design
did not list the fingerprint among the requirements; this document follows the
ledger and treats a *request fingerprint* as part of R4/R5/R6, while leaving the
exact mechanism (stored hash vs. stored canonical fields) to OQ-1.

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

Line-range notes: the brief cited `FinanceController ~142-161` (route block starts
at the comment `:142`, ends `:163`), `refundPayment ~681-777` (closing brace is
`:778`) and `:1362-1381` (exact). No claim was refuted.

### Additional observations relevant to the design

| # | Observation | Evidence |
|---|---|---|
| A1 | The shared `Idempotency::check()` runs a separate statement with no surrounding business transaction (the two booking callers claim at `:918`/`:1576` **before** their business transaction and call `complete()` at `:1170`/`:1808` **after** it). A crash between commit and `complete()` leaves a `pending` row. | `Idempotency.php:38-82`, `:87-104`; call sites above |
| A2 | `check()` **fails open**: if the insert fails for a non-UNIQUE reason and the re-read finds nothing, it returns a *fresh path* (`is_replay=false`). For refunds that would mean "no protection → apply". | `Idempotency.php:58-64` |
| A3 | A recurring job `cleanup.idem` deletes **every** row older than 90 days from the shared table (any endpoint). A refund key kept there would expire. | `Idempotency.php:121-131`; `IdemCleanupHandler.php:9-26`; `App.php:2627` |
| A4 | **Retracted claim (round 2).** Round 1 said the shared table's `clinic_id` has `DEFAULT 1`. That is true only of the *creating* migration (`0001:133`); migration 0016 removes the default (`0016:12-13`, `:33-40`: `ALTER … MODIFY clinic_id BIGINT UNSIGNED NOT NULL` when the default is `'1'`) and 0017 adds a foreign key to `cpms_clinics` (`0017:28`). On an installation that has run the migrations the column has **no** default, so this is **not** a reason against option A. What remains true: the unique index is five columns, so "unique per clinic" is not what the index expresses. | `0001:133`; `0016:33-40`; `0017:28`; `0020:81` |
| A5 | `refund` first-success returns **HTTP 200** (no status function is passed). The only UI caller treats any status other than 200 as an error. | `FinanceController.php:148-156` (no `$status`/`$statusFn` passed), `staff()` default `200` at `:315`; `SecretaryFinancePage.php:768-769` |
| A6 | Refund `amount` is optional; omitted ⇒ refund the whole remaining amount. The UI sends `amount` only if the operator typed one ("empty = whole remaining"). | `FinanceService.php:700-702`; `SecretaryFinancePage.php:758-767` |
| A7 | Amounts are **whole rials**, not cents: nothing is multiplied or divided by 100; `toMinor()` is only a name. An int is returned as is; a float if integral; a string if `is_numeric` — the string goes **through `(float)`**, is accepted if integral, then cast to int; anything else ⇒ `null` ⇒ 422. No rounding is performed on this path. The UI prompt says rials. Storage is `DECIMAL(12,2)` (10 integer digits, so at most 9,999,999,999 by column definition). PHP's exact behaviour for odd numeric strings and for floats beyond the int range was **not run** (no PHP in the sandbox) — see §4.2. | `FinanceService.php:1210-1225`; `0001:595-600`; `SecretaryFinancePage.php:760` |
| A8 | `reason` is only `trim()`-ed (PHP `trim`, ASCII whitespace set), must be non-empty, and is **truncated to 255** characters **only inside the audit record**; the payment row stores no refund reason. | `FinanceService.php:686-689`, `:770` |
| A9 | The audit record is written **inside** the refund transaction through a nested savepoint. The audit writer **explicitly checks** its own steps and throws: missing guard row (`:85`), tail-read error (`:93`), insert not persisted (`:130`), guard update failed (`:139`) — so an *audit-write* failure rolls back the enclosing transaction. It locks a **global** singleton row (`cpms_audit_seq`, id 1) `FOR UPDATE`. The audit row carries actor, role, `created_at` (server time), before/after/meta JSON and a hash chain. This explicit checking is **not** the general behaviour of the DB wrapper (A13) and is **not** applied to the refund's own `UPDATE`s (A14). | `AuditLogger.php:43-141`; `CpmsDb.php:272-317`; `0001:676-698` |
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
  (`:697-710`) — it never reaches the in-transaction status check. (Round 1
  wrongly said 409.) Not applied twice, but the operator sees an error for an
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
4. If staff hand over cash/card refunds per confirmation, the clinic pays out up to the full remaining balance twice over as many retries as fit under the cap.
5. Daily revenue/refund summaries and any reconciliation built on `refunded_amount` are overstated by the duplicate.

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

### 4.2 Amount: canonical form and "remaining"

* **What is accepted today (OBSERVED by reading `toMinor`, `:1210-1225`; not
  executed).** An int; an integral float; or a string for which PHP `is_numeric`
  is true, converted via `(float)` and accepted if integral, then cast to int. By
  PHP's documented numeric-string rules (**not run — no PHP in the sandbox**) that
  set includes forms such as `"1e3"`, `"1000.0"`, leading/trailing whitespace and
  a leading `+`. The WordPress `type: number` argument
  (`FinanceController.php:160`) runs before the service; how it pre-processes such
  strings was **not examined (UNKNOWN)**. Precision above 2^53 is lost in the
  float step, and the `(int)` cast of a float outside the integer range is
  **UNKNOWN** here. The amount is in **whole rials** (A7), at most 10 digits by the
  column definition; `toMinor` is a name only, nothing is scaled by 100.
* **Canonical form for the specification** (design position): the *integer value*
  after the existing interpretation, written as base-10 ASCII digits with no sign,
  no leading zeros, no separator, no exponent and no decimal point, in the range
  **1 … 9,999,999,999** (zero and negatives are already rejected,
  `$refund <= 0`, `:703`; the upper bound is the column's integer capacity — in
  practice the remaining cap is lower). Whatever today's `toMinor` accepts and that
  maps into this range yields the same canonical text, so `1000`, `"1000"`,
  `1000.0`, `"1e3"` are one specification; anything else is rejected **before**
  fingerprinting. This design does **not** change what `toMinor()` accepts;
  whether to tighten the wire grammar (e.g. ASCII digits only) is **OQ-16**.
* **No rounding.** A fractional value is rejected (`toMinor` → `null` → 422),
  not rounded (for integral-valued floats/strings the float step is exact below
  2^53). Hence there is no rounding ambiguity and no unit conversion in the
  specification. The refund currency is the payment's/invoice's currency and is
  not a request field (the payments table has no currency column; the invoice row
  does, `0001:557`, default `IRR`).
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
3. Unicode **NFC** normalization **only if** the `intl` `Normalizer` is available in the supported runtime — **availability is UNKNOWN (not verified in the repo or on any installation) and must be established before adoption.** If it is not available, NFC is skipped and two visually identical but byte-different reasons are *different* specifications. **Requirement:** the exact canonicalization (trim set, normalization form, treatment of invisible controls and — because normalization data can change between Unicode/ICU versions — the Unicode version assumed) is **pinned by `spec_version`**; any change to it is a **new `spec_version`**, and an existing refund keeps comparing under the version it was created with. A fingerprint alone cannot be re-derived under a different canonicalization, which is a further reason OQ-1 may prefer to store the canonical fields as well.
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
  `PAYMENT_REFUND` row, nothing written to the key store. The transaction (if
  opened) is rolled back.
* The body **does not echo** the stored specification or result (no information
  beyond the generic message).
* Authorization and tenant checks run **before** the key lookup (§6.3), so this
  response is only reachable by an actor already allowed to refund in that clinic.

---

## 5. Storage options

All options must satisfy R4-R7: DB-enforced uniqueness per clinic, atomic claim,
same-clinic replay/reject, cross-clinic independence. None of this is
implemented; **options B and C require schema changes and therefore a separate
owner approval of a migration; option A needs no DDL but is not recommended.**

### Option A — reuse the shared table `cpms_idempotency_keys` with a refund-specific scope

*Mechanism:* call the existing `Idempotency` class with `endpoint =
'finance.refund'` and **constants** for `wp_user_id` and `context_id`.

* **Schema/migration required:** none for the key row itself. **However**, the
  table has no column for a request fingerprint or specification (V3,
  `0001:130-144`); without DDL the only place is inside `response_json`, which is
  written only by `complete()` (`Idempotency.php:87-104`) — i.e. **not present
  while the row is `pending`**.
* **Per-clinic uniqueness by DB?** Only **by convention**. The index is
  `(key, endpoint, wp_user_id, context_id, clinic_id)` (`0020:81`). To obtain
  "unique per clinic" the caller must always pass `wp_user_id = 0` and
  `context_id = 0`; the DB does *not* forbid another caller inserting the same
  key with a non-zero user/context. If `context_id = payment_id` (the natural
  choice) or `wp_user_id = actor`, the same key on another payment/actor is a
  **different row** and is **not rejected**, violating R6.
* **Atomicity:** the shared class claims in a separate, auto-committed statement
  (A1). A crash after the refund transaction commits but before `complete()`
  leaves `pending` forever ⇒ later retries get 409 `CLINIC_DUPLICATE_IN_FLIGHT`
  although the refund **was** applied, until the 90-day purge (A3), after which
  the key is gone and a retry **double-applies**. A crash after the claim but
  before the refund leaves a stuck `pending` for the same period. Making the claim
  part of the refund transaction would require changing the shared class (code
  change affecting booking/handwriting), and then the re-read after a duplicate
  would still need the duplicate-key handling of §6.1 step 5.
* **Fail-open:** A2.
* **Retention:** the 90-day purge (A3) must be disabled for this endpoint — a code
  change in a shared handler.
* **Tenancy:** the creating migration gave `clinic_id` a `DEFAULT 1`
  (`0001:133`), but migration 0016 removes that default (`0016:33-40`) and 0017
  adds a foreign key to `cpms_clinics` (`0017:28`); on a migrated installation the
  column is `NOT NULL` with **no default** (round 1 wrongly listed the default as a
  hazard — A4). The remaining tenancy point is the one above: the five-column
  unique index does not by itself express "unique per clinic".
* **Pros:** no migration; reuses a tested replay-body store.
* **Cons:** all of the above; the stored result is the *response*, not a
  refund record, so R3 (history/traceability) is **not** advanced.
* **Verdict:** does not honestly meet R4 ("DB uniqueness **and atomic**") without
  modifying shared infrastructure. Option A stays *not recommended* for the reasons
  that remain true after the 0016 correction: the claim is not atomic with the
  business transaction, the shared check fails open (A2), the 90-day purge (A3),
  the fingerprint has no column and the `response_json` is only written at
  `complete()`, and uniqueness is by caller convention (`wp_user_id`/`context_id`
  are part of the index).

### Option B — a new refunds table (one row per refund) holding the key

*Logical content (illustrative attributes, not DDL, not types, not a migration
number):* clinic, payment, invoice, amount applied, amount intent, reason,
server-assigned refund timestamp, refunding actor, idempotency key, `spec_version`
and fingerprint, and the result snapshot (see §6) or enough facts to re-create it.

* **Schema/migration required: YES** (new table, a uniqueness constraint, foreign
  keys per `ADR-0012`). **Needs separate owner approval (R8).** Not proposed for
  implementation here.
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

### Option C — store the key on the payment row

*Mechanism:* a new column on `cpms_payments` (the existing `idempotency_key`
column is the **capture** key, `0001:598`/`:609`, and must not be reused).

* **Schema/migration required: YES.**
* **Per-clinic uniqueness by DB:** possible as a unique index, **but a payment
  can receive many partial refunds, each with its own key.** One column holds one
  key. Overwriting it forgets the earlier keys (a retry of an earlier refund then
  looks new ⇒ double-applied); a list-valued column cannot be made unique by the
  DB.
* **Verdict: structurally insufficient** for R1 (partial refunds allowed) + R4.
  Listed for completeness; **not viable**.

### Option D — use the audit log as the key store

* The audit table has non-unique indexes only (`0001:695-698`) and a global
  hash-chain serialization lock (A9); there is no unique constraint that could
  carry a key. **Not viable** for R4 (DB uniqueness).

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
approval of a feature-scoped migration (R8). Chosen by owner in **OQ-1**.

---

## 6. Atomic operation, locking, concurrency, crash, retry (for option B; A noted where it differs)

All of §6 is a **proposal**; none of it is implemented. Round 2 replaces the
round-1 text with **one** protocol (§6.1), **one** lock order (§6.5) and **one**
failure classification (§6.4), because round 1 described several that disagreed
with each other.

### 6.0 Precondition that the proposal depends on: how write failures surface

**OBSERVED (A13, A14):** the runtime DB wrapper is soft-mode. A failed `INSERT`
or `UPDATE` does not throw, does not abort the transaction, and the refund's own
writes discard their results. Round 1 wrongly assumed that "any statement" failing
rolls everything back. That is **not true of the code as it stands** and the
statements about rollback below hold **only if the following requirement is met**:

> **REQUIREMENT W (for any future implementation).** Every statement inside the
> refund transaction — key claim, payment lock read, invoice lock read, refund-row
> completion, payment update, invoice update, audit write, commit — must be
> **explicitly checked or made to throw**, and a failure of any of them must end in
> a **rollback of the whole transaction** followed by a "rolled back, retry with
> the same key" outcome. In particular: (1) a failed lock read must not be
> treated as "row not found"; (2) a failed key insert must **not** be treated as
> "duplicate" unless the failure is positively identified as a duplicate-key error;
> (3) an `UPDATE` whose result is "0 rows" must be examined (it can mean failure, no
> matching row, or — in MySQL — unchanged values, which behaviour depends on the
> client flags and is **UNKNOWN** here) rather than ignored.

Mechanisms (the choice is an engineering decision for the implementation design
review, listed as **OQ-21**; this document does not choose):

| | Description | Pros | Cons |
|---|---|---|---|
| **W-1 Explicit result checks** | Check each write's result by hand at the call site (as the capture path does, `FinanceService.php:535-553`, and as `AuditLogger` does, `:85-139`); repositories used in the path would have to return/propagate results (today they return `void`, A14). | No change to shared wrapper behaviour; local and visible. | Easy to miss one statement; repository signatures change; the wrapper hides the SQL errno, so identifying "duplicate key" needs a reliable method (e.g. inspecting `wpdb->last_error` text) — **UNKNOWN** how robust that is. |
| **W-2 Strict mode for this scope** | Switch the wrapper to strict (`CpmsDb.php:25`, `:64-69`) around the refund transaction so SQL errors throw; restore afterwards. | Every wrapper call throws automatically, including nested repository calls. | The flag is **instance-wide**, not per-statement; every other component that runs inside the scope (audit writer, helpers) then changes behaviour (some callers may rely on `false`/`null`); it is used today only by `MigrationRunner` (`:100`, `:145`), so it is untested in request paths; restoring must be guaranteed on every exit path; behaviour of `fetch*` null-on-error vs throw must be re-read. |
| **W-3 Both** | Strict for the scope plus explicit checks on the statements whose failure has special meaning (the key claim, the locked reads). | Defence in depth; the duplicate-key case is handled explicitly. | Most work; two mechanisms to keep consistent. |

Whichever is chosen, a test must prove that an injected failure of **each**
statement (T16) ends in a full rollback.

### 6.1 The single atomic protocol

1. **Pre-checks, no key-store access, no cap check, no writes.** Capability
   (`requireCap`), trusted clinic (`requireTrustedClinicId`), scoped authorization
   (`authorizeScoped`), reason non-empty, payment exists and belongs to the
   trusted clinic (404 otherwise), per-payment ownership
   (`authorize_payment_ownership`) — i.e. what is now at
   `FinanceService.php:682-695`. Then: key present/valid, specification
   canonicalized (§4), fingerprint computed. The payment read here is a plain,
   possibly stale, read used only for early rejection.
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
     further step, outcome "rolled back, retry with the same key" (§6.4). It must
     **never** be treated as "not found, proceed" (that is the shared
     `Idempotency::check()` fail-open behaviour, A2, which refunds must not
     inherit).
   The row is inserted incomplete (result fields not yet known); it becomes
   visible to other sessions **only** when the transaction commits, and the
   transaction commits only after step 9 has completed it. **No committed state
   may exist in which the key row has no result.** (This is why option B has no
   `pending` state.)
5. **Duplicate-key handling: roll back, then re-read fresh, then decide.**
   1. **Roll back** the transaction (it holds nothing worth keeping). The
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
   3. **Row found, same `spec_version` and same specification and same payment**
      ⇒ **replay** the stored result (§7.1); no write. **Found, different
      specification or different payment** ⇒ **422** key-reuse error, no write.
   4. **Row not found** (the other transaction rolled back or timed out) ⇒ go back
      to step 3 — a **bounded** number of times. The bound and what is returned
      when it is exhausted ("rolled back, retry with the same key", never a
      silent apply) are implementation parameters; an unbounded loop is not
      acceptable.
   5. A read error here is an error, not "not found" (Requirement W).
6. **Lock the payment row `FOR UPDATE`** (the existing lock, `:713`). The result is
   checked explicitly: failure ⇒ rollback ("rolled back, retry"); *no row* is
   distinguished from *query failed*. Re-check clinic ownership of the locked row.
7. **Lock the invoice row `FOR UPDATE`** (as today, `:725-731`), same explicit
   checking and clinic re-check.
8. **Re-validate under the locks, exactly as today:** payment `status` must be
   `captured` (`:717-724`, else 409), invoice exists, belongs to the clinic and is
   not `voided` (409), recompute the remaining amount from the **locked** payment
   row, resolve the `REMAINING` intent (§4.2), reject over-cap (`:733-746`, 422).
   Any rejection here **rolls back**, which discards the key claim of step 4: a
   request rejected on its merits leaves no key row (what an operator may then do
   with that key is policy, **OQ-18**).
9. **Write, every statement checked:** complete the key row (applied amount,
   server timestamp, actor, **and the replay snapshot — the exact response facts
   that will be returned, §7.1 — in this same transaction**), update
   `payments.refunded_amount`/`status`, reverse the invoice effect
   (`reverseInvoicePayment`), write the `PAYMENT_REFUND` audit row (which takes
   the global audit serialization row last, A9). Any failed or ambiguous statement
   ⇒ throw ⇒ rollback.
10. **Commit.**
11. **Classify the outcome** (§6.4): *committed* only if the commit statement
    returned success; *rolled back* only if a rollback statement positively
    succeeded; **anything else is "unknown"** and is **never** reported or acted on
    as a rollback.
12. **Respond from the stored snapshot** produced in step 9 (not from a re-read of
    current rows), with the first-success status; a later replay returns the same
    stored facts plus the replay marker. If the process dies after step 10, the
    refund and its snapshot are already durable, so a retry replays them.

### 6.2 Concurrency matrix

| Situation | Expected behaviour (design) |
|---|---|
| Two **identical** requests (same key, payment, spec) concurrently | They collide at the key claim (step 4). The winner continues; the loser waits, then gets a duplicate-key error, rolls back, re-reads fresh (step 5), finds the committed row, spec equal ⇒ **replay**. If the winner rolled back instead, the loser's insert succeeds or it retries (bounded). Exactly one refund. |
| Same key, **different spec, same payment** concurrently | Same collision; the loser, after re-read, finds a different specification ⇒ **422** `…KEY_REUSED`, no state change. Which request wins is not deterministic; the outcome (one applied, one rejected) is. |
| Same key, **different payments** | Same collision at the key claim, before any payment lock; the loser ⇒ 422 (different payment). Never two refunds for one key. |
| **Different keys**, same payment, both fit | Each claims its own key, then both queue on the payment lock; each re-checks the cap under the lock (intended: distinct intents). |
| **Different keys**, together exceeding the cap | The first applies; the second fails 422 (`max_refundable` is the locked value) and rolls back its key claim. The total never exceeds the amount paid (R1). |
| **Same key in two clinics** | Independent `(clinic, key)` pairs; both succeed. Every claim and read includes the trusted clinic, so one clinic can never observe, replay or collide with the other's key. |
| Refund vs **void** of the same payment | Both reach the payment lock; whichever is second sees the other's committed state under the lock. Deadlock freedom with capture is **UNKNOWN** (§6.5). |
| Deadlock victim / lock-wait timeout at any step | Rolled back; "retry with the same key" (§6.4); never reported as applied. |

### 6.3 Authorization and tenancy vs replay

* Authorization and tenancy checks run **before** the key lookup, every time. A key
  that was valid before an actor's authority was revoked must not replay for them —
  the same principle the booking path states in its own comment
  (`BookingService.php:1572-1575`: "a previously valid key must never replay after
  authority is revoked").
* The clinic comes only from `ScopeContext`; there is **no fixed clinic id 1**
  anywhere in the design, and the new store must have **no default** clinic.
  `CLINIC_SCOPE_REQUIRED` stays the fail-closed answer when no trusted scope exists.
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
| Deadlock victim / lock-wait timeout | server rolled back the statement or the transaction (InnoDB; **INFERENCE**); the implementation must still issue and confirm a rollback | as above | as above |
| Step 4: duplicate key | rolled back by design (step 5.1) | replay, 422, or bounded retry | n/a (this *is* the retry path) |
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
A *does* have the stuck-pending states of §5.)

**Retry semantics (proposed):**

* A client retries **only with the same key** after a timeout, a 5xx, a lost
  response or an unknown outcome; a **new** key is used **only for a verified new
  refund intent** (an operator has confirmed that the earlier one did not and will
  not apply, or really wants an additional refund). A key mismatch (422) is **not**
  a reason to create a new key — see §4.3 and **OQ-19**.
* A request that is rejected before commit stores nothing (nothing committed).
  Whether the operator may then **correct the request and reuse the same key**, or
  whether a rejected key should be burned, is **policy**, not a design fact: it is
  **OQ-18**. Failures are not stored for replay (policy: **OQ-14**).
* How the existing layer maps lock/deadlock errors to HTTP status was not traced
  (**UNKNOWN**).

### 6.5 Lock order and deadlock analysis

**Proposed single order for the refund path:** (1) key claim (unique-index record of
the key) → (2) payment row → (3) invoice row → (4) audit serialization row
(`cpms_audit_seq`, last, as today). Steps 4, 6, 7, 9 of §6.1 follow it, and nothing
in the protocol acquires a lock out of this order.

| Path | Order | Evidence |
|---|---|---|
| Refund (today) | payment → invoice → audit-seq | `:713`, `:725`, `AuditLogger.php:81` |
| Void (today) | payment → invoice → audit-seq | `:607`, `:630` |
| Capture (today) | invoice → clinic row → insert payment → audit-seq | `:1083`, `lockClinic` `:533`/`:1171`, insert `:536-545` |
| **Refund (proposed)** | key claim → payment → invoice → audit-seq | §6.1 steps 4-9 |

**What can and cannot be said (all INFERENCE; no database was available, nothing
was executed):**

* Void and refund both take payment → invoice. Refund additionally takes the key
  first; void takes no key. Since the key is acquired before everything else and
  void never waits for a key, a cycle *between these two* is not evident from the
  order alone. This is a reading of the order, **not** a proof.
* **Capture takes the invoice first, then inserts a payment row** (which
  references the invoice and, in InnoDB, can involve locks on the parent row and on
  secondary-index records). Refund holds the payment row and then wants the
  invoice. Whether capture-vs-refund (or capture-vs-void) on the same invoice can
  deadlock **was not analysed and is UNKNOWN**. The round-1 statement that "no wait
  cycle is evident" is **withdrawn** as a general claim. Test T14 must probe it on
  a real database.
* **Same key, different payments:** the second transaction waits inside step 4
  for the first one's outcome (that is the whole point), holding **no** other lock
  of its own. If the first commits, the waiter gets a duplicate-key error ⇒ step 5.
  If it rolls back, the waiter's insert proceeds. Concurrent *identical-key*
  inserts can themselves produce lock-wait timeouts or deadlock victims in InnoDB
  when several waiters queue on one index record; that is **expected to be
  possible, not proven absent**.
* The audit serialization row is a **global** lock held until commit by every
  audit-writing transaction; refund, void and capture all queue on it at the end.
  It is last in every order here, so it adds waiting, not an evident cycle; its
  throughput effect is **not measured**.
* Isolation level, lock-wait timeout and deadlock-detection settings are
  **UNKNOWN** (A12). Gap/next-key locking by the key claim and by locking reads of
  a *missing* key is **UNKNOWN per isolation level** (REPEATABLE READ vs READ
  COMMITTED) and must not be assumed either way.
* The wrapper does not retry (`CpmsDb.php:272-317`). A deadlock victim or
  lock-wait timeout is an error that the implementation must **map explicitly** to
  "rolled back, retry with the same key" (how the existing layer maps such errors
  to HTTP was not traced — **UNKNOWN**). It is safe to retry **only because** the
  retry carries the same key (§6.4).

---

## 7. Interactions and replay semantics

### 7.1 Replay response: what exactly is returned

**OBSERVED payment (D13) behaviour** (V6): replay = current payment row +
current invoice view + `idempotent_replay` + `code`, HTTP 200 vs 201 on first
success. `SRS.md:281` says "same response"; `error-codes.md:38` says "stored
response". The code does not do either exactly.

**Options for refunds** (decided in **OQ-2**):

| | Description | Pros | Cons |
|---|---|---|---|
| **R-1 Stored snapshot** | Return the response body exactly as produced at first success (payment projection as of the refund, invoice status/balance as of the refund, applied amount, reason, refund timestamp, actor), plus the replay marker. | Genuinely "the previous result" (R5); immune to later changes (void, later refunds, invoice change); same status code as the first response. | Needs the snapshot stored (more data); the invoice numbers in it can be **stale** relative to now (that is the point, but UIs must not treat it as current). |
| **R-2 Rebuilt current view** (what payment replay does) | Re-read payment/invoice now. | No snapshot storage. | **Not** "the previous result"; after a later void or refund the replay shows different numbers than the original; for an exhausted payment the "result" can look like nothing happened. |
| **R-3 Hybrid** | Stored refund facts (immutable) in the primary body + a separate `current` block rebuilt now. | Both fidelity and freshness. | Larger/more complex contract; two sources to keep consistent. |

**Recommendation:** R-1 (R-3 if the UI needs current balances). R-1 and R-3 require the replay snapshot to be written **in the same transaction** as the refund (§6.1 step 9) and the first response to be built from it (step 12); with R-2 nothing is stored, and the first response and a later replay can legitimately differ.

**Status code and marker (design position):** keep the first-success status
**200** (A5: the only UI caller accepts exactly 200, `SecretaryFinancePage.php:769`)
and return **200** on replay, so the *status is identical* to the original. Mark
the replay inside the success body with `idempotent_replay: true` and
`code: CLINIC_IDEMPOTENCY_REPLAY`, the same shape D13 already uses (a marker in a
success body, **not** the `{code,message,data}` error envelope). Changing the first
refund response to 201 would break the UI caller and make "same response" false by
status alone.

**Correcting the "same response" wording — what this PR does and does not do.**
`SRS.md:281` concerns **payment capture** replay. Whether that statement should be
corrected (to "stored or rebuilt?") or the **code** should change depends on the
owner's choice in OQ-2(b). Since that is **not decided**, `SRS.md:281` is
**deliberately left unchanged in this PR** and is listed as an **open item** (§12).
The correct *descriptive* wording, once the owner confirms that payment replay is
meant to stay a rebuilt view, would be: "a repeat of the same key does not create a
second transaction; the response is rebuilt from the stored payment and the
*current* invoice view, with HTTP 200 and `idempotent_replay: true` /
`CLINIC_IDEMPOTENCY_REPLAY`; the first success is 201."

### 7.2 Partial refunds

Each partial refund is its own idempotent operation with its own key; a key never
covers "all refunds of a payment". The cap (R1) is evaluated under the payment
lock from the locked `refunded_amount` (`:733-746`) and stays the single authority.

### 7.3 Refund exceeding the remaining balance

Rejected 422 `CLINIC_VALIDATION_FAILED` with `data.max_refundable` (today's
behaviour, `:739-746`; note `data.max_refundable` is undocumented in
`error-codes.md`, as already recorded in `agent-log.md`). The failure is **not**
stored; the key is not consumed. A replay of a refund that *previously succeeded*
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

The brief states that the round-11 install reported these as **NOT TESTED**; **I
could not locate that report in the repository** (a `grep` for `NOT TESTED` near
refund/receipt found nothing), so it is **user-reported, not verified**. From the
code (**OBSERVED, not executed**), see findings F1 and F2 (§8). Idempotency does
not fix either; the design only requires that replay results not depend on them
and that §10 tests cover them.

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

**OBSERVED in code at `d1d49c5`; not executed; effects are INFERENCE.**

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
* **F5 — Admin refund dialog: amount parsing and no confirmation.**
  (OBSERVED by reading; the `parseInt` behaviour reproduced in Node, not in a
  browser.) The amount is requested through `CpmsDialog.prompt`, whose input is a
  plain `type='text'` field (`assets/js/cpms-dialog.js:128-130`; `inputMode` is only
  a keyboard hint, `:135-137`) and whose value is only `trim()`-ed
  (`:198`). `SecretaryFinancePage.php:765-767` then sends `amount` **only if**
  `amt !== '' && !isNaN(parseInt(amt, 10))`. Consequences, reproduced with
  `parseInt` in Node: Persian or Arabic-Indic digits (`۳۰۰۰۰۰`) give `NaN`, so
  `amount` is **omitted** and the server refunds the **whole remaining amount**
  (A6); `"300,000"` or `"300 000"` give `300`; `"3e5"` gives `3`;
  `"300000.5"` gives `300000`. There is **no confirmation step** between the amount
  prompt and the request (`:762-768`). The UI also sends no key. Not fixed here;
  how a key is generated by the UI is **OQ-20**.
* **F6 — `SRS.md:281` payment-replay wording** says "same response" while the code
  rebuilds the current view (V6); depends on **OQ-2(b)**; left unchanged on purpose.

Items F1 (receipts), F2 (void after partial refund), F5 (UI) and F6 (SRS wording)
are the adjacent existing defects reported by the round-1/round-2 readers; **none is
fixed or designed here** (OQ-11).

---

## 9. Open questions for the owner (NOT decided here)

Each lists options, a recommendation, pros/cons and risk. **The recommendation is
not a decision.**

### OQ-1 — Storage option (and fingerprint form)
* **Options:** (A) reuse shared table; (B) new refunds table; (C) key on payment row (not viable); fingerprint: (i) fingerprint only, (ii) canonical fields only, (iii) both.
* **Recommendation:** **B**, with (iii) both (fields give history/R3; fingerprint matches Record A).
* **Pros (B):** exact DB uniqueness, crash-atomic, closes the per-refund-history gap. **Cons (B):** migration, two finance reviews, owner approval, dual bookkeeping with `refunded_amount`.
* **Pros (A):** no DDL. **Cons (A):** not atomic, convention-only uniqueness, 90-day purge, fail-open, edits shared booking/handwriting code to fix.
* **Risk:** B — schema change on a finance table (needs the R8 approval path); A — silent double-apply or stuck keys; a wrong choice here is hard to reverse once data exists.

### OQ-2 — Replay semantics: (a) refund replay body/status; (b) payment replay wording
* **Options (a):** R-1 stored snapshot / R-2 rebuilt / R-3 hybrid; status 200 vs 201. **Options (b):** (b1) keep payment replay as is (rebuilt, 200/201) and **correct the docs** (`SRS.md:281`, `error-codes.md:38`) to say so; (b2) change payment replay to return a stored snapshot (code change, behaviour change).
* **Recommendation:** (a) R-1 with 200; (b) b1 (documentation-only, no behaviour change) — but the owner must say so before `SRS.md:281` is edited.
* **Pros (b1):** no code risk. **Cons (b1):** payment "same response" promise is formally weakened. **Pros (b2):** the promise becomes literally true. **Cons (b2):** needs payment snapshot storage (maybe schema), touches proven code.
* **Risk:** (a) R-2 misleads reconcilers after later changes; (b) editing the SRS without the decision would record an unapproved behaviour.

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

### OQ-6 — Key retention — **conflicts with an owner-decided requirement**
* **Owner-decided (ledger §3.2, R4):** the key is required, DB-unique per clinic, and the same key with the same specification returns the previous result. Nothing in that record limits how long that holds.
* **Options:** (a) keep as long as the refund record — no expiry; (b) a bounded window after which the key may be **reused** for a different refund.
* **Recommendation:** (a) — consistent with the owner decision.
* **Explicit statement:** option (b) is a **deviation** from the owner-decided requirement ("same key ⇒ previous result", "different spec ⇒ rejected"): after the window the same key would silently mean a *new* refund. It must **not** be implemented unless the **owner approves that deviation in writing**.
* **Pros (a):** a very late retry can never double-apply. **Cons (a):** table grows with refunds (not measured).
* **Risk (b):** a retry after the window is applied as a new refund — silent double refund.

### OQ-7 — Rollout of the required key for existing clients — **conflicts with an owner-decided requirement**
* **Owner-decided (R4):** the key is **REQUIRED**, unconditionally.
* **Options:** (a) required immediately, with the UI sending a UUID in the same release; (b) transitional: key optional for a stated period; (c) required, API clients informed in advance.
* **Recommendation:** (a): the only observed caller is the admin finance UI (`SecretaryFinancePage.php:768`), which today requires status 200 and sends no key.
* **Explicit statement:** option (b) — a temporarily **optional** key — is a **deviation** from the owner-decided unconditional requirement: during the period a request without a key is applied with **no** protection. It must **not** be implemented unless the **owner approves that deviation in writing**.
* **Pros (a):** no unprotected window. **Cons (a):** breaking for any unobserved external client (none observed in the repository; external ones **UNKNOWN**).
* **Risk (b):** the gap stays open during the transition.

### OQ-8 — Mismatch response contract
* **Options:** 422 `CLINIC_IDEMPOTENCY_KEY_REUSED` (candidate); 409 with a new code; 400.
* **Recommendation:** 422 + new registered code.
* **Pros:** distinguishes from 409 in-flight. **Cons:** a new code to document. **Risk:** low (API naming); needs the `ADR-0019` registry entry in the implementing PR.

### OQ-9 — Different authorized actor, same key
* **Options:** replay for any authorized actor in the clinic (key is per clinic, R4); replay only for the original actor; reject.
* **Recommendation:** per clinic (R4 says unique **per clinic**), after authorization is re-run, and record the original actor in the result.
* **Pros:** matches R4, supports a double-click by two staff. **Cons:** a second user sees the first user's result (both are authorized). **Risk:** the doctor ownership rule (`authorize_payment_ownership`, `:1323-1345`) must stay enforced first.

### OQ-10 — No backfill of legacy refunds (a design assumption, not a recorded decision)
* **Options:** no backfill (recommended); synthesize refund records from `PAYMENT_REFUND` audit rows.
* **Recommendation:** no backfill.
* **Pros:** no data migration on finance history; the audit remains the legacy source. **Cons:** history is split (refund table for new, audit for old); reconciliation must tolerate `refunded_amount ≥ sum(rows)`.
* **Risk (backfill):** audit `meta.reason` is truncated at 255 and amounts may be inconsistent; reconstruction could invent history.

### OQ-11 — Scope of the two adjacent findings (F1 receipt, F2 void-after-partial-refund)
* **Options:** separate PRs/decisions (recommended); fold into this feature.
* **Recommendation:** separate; this design must not depend on them.
* **Pros:** keeps the idempotency change reviewable. **Cons:** the defects stay open meanwhile. **Risk:** F2 can mis-state invoice balances (INFERENCE) and deserves its own decision (block void when `refunded_amount > 0`, void only the remainder, or leave) — not decided here.

### OQ-12 — Refund when an invoice later becomes overpaid (discount / credit after payment)
* **Context:** the cap is the *payment's* remaining amount (§7.5). A later discount or credit adjustment (`:1102-1109`, D15) can make an invoice's effective total lower than what was paid; the excess is not represented in the payment row.
* **Options:** (a) refunds stay limited to the payment's remaining amount, excess handled outside this feature; (b) allow refunding the invoice-level excess through this endpoint (new cap rule, new spec field); (c) forbid such adjustments after payment.
* **Recommendation:** (a) for this feature; decide (b)/(c) in a separate finance decision.
* **Pros (a):** no change to cap authority. **Cons (a):** the overpayment has no refund path in the system. **Risk (b):** a second cap authority (invoice vs payment) and a larger specification.

### OQ-13 — Does a replay leave an audit trace?
* **Options:** (a) none (replays are not financial events); (b) a non-chained operational log line; (c) a separate audit action (e.g. replay) in the hash-chained audit log; (d) a counter on the refund row.
* **Recommendation:** (b) — lets staff diagnose retries without touching the financial audit chain.
* **Pros (a):** simplest, no extra audit-seq contention. **Cons (a):** no evidence that a retry happened. **Pros/Cons (c):** full trace but each replay takes the global audit lock and grows the chain. **Risk:** (a) hides client retry storms; (c) lets a retry loop flood the audit table.

### OQ-14 — Failed-result replay, tombstones, audit retention
* **Questions:** should a *rejected* request (422 cap, 409 status) be stored and replayed for the same key? Should a "tombstone" prevent reuse of a key whose refund was rolled back? How long must refund rows and the `PAYMENT_REFUND` audit rows be kept (no purge job exists for the refunds table by design; audit retention not examined)?
* **Options:** (a) store nothing for failures (this design's assumption); (b) store failures as tombstones with the fingerprint; (c) store only certain failures.
* **Recommendation:** (a) with indefinite retention of successful refund rows.
* **Pros (a):** simple, no unbounded junk. **Cons (a):** a retry of a rejected request is re-evaluated against current state (may now succeed). **Pros (b):** deterministic replay of failures. **Cons (b):** more storage and states; a transient failure becomes permanent unless handled. **Risk:** inconsistent retention between refund rows and the audit log.

### OQ-15 — Refund of a voided payment
* **Observed:** today a non-`captured` payment ⇒ 409 (`:717-724`); a voided payment cannot be refunded. A same-day void after a partial refund is possible (F2).
* **Options:** (a) keep: voided payments are never refundable; (b) allow refunding a remaining amount after a void, under defined accounting; (c) block void when `refunded_amount > 0`.
* **Recommendation:** (a) for this feature; (c) belongs with the F2 decision.
* **Pros (a):** no change. **Cons (a):** cash already refunded before a void is not reconciled by the system. **Risk (b):** double reversal against the invoice.

### OQ-16 — Currency precision and the amount wire grammar
* **Observed:** whole rials only; no currency column on payments; the invoice has a currency, default `IRR` (`0001:557`); storage `DECIMAL(12,2)`; `toMinor` accepts numeric strings through float (A7, §4.2).
* **Options:** (a) keep whole-unit integers, canonical form as in §4.2, wire grammar unchanged; (b) restrict the wire grammar to ASCII digits (and/or JSON integers) only; (c) support fractional currencies later via a new `spec_version`.
* **Recommendation:** (b) at the API boundary, in the implementation PR, with the specification fixed by `spec_version`.
* **Pros (b):** removes float-derived ambiguity (`"1e3"`, `"1000.0"`). **Cons (b):** behaviour change for any client sending such forms (none observed). **Risk (a):** precision/format ambiguity enters the fingerprint.

### OQ-17 — Permissions for refunds
* **Observed:** capability `payment.refund`, scoped authorization and a doctor-ownership rule (`FinanceService.php:682-695`).
* **Options:** (a) unchanged; (b) amount threshold requiring a second approver; (c) refund only by the original capturing actor/role; (d) separate capability for replay-visible results.
* **Recommendation:** (a) for this feature; thresholds/approvals are a finance policy decision.
* **Pros (a):** no new policy. **Cons (a):** any holder of the capability may refund up to the cap. **Risk:** (b)/(c) change who can do daily work; not designed here.

### OQ-18 — May a rejected key be corrected and reused? (previously stated in round 1 as design text)
* **Observed fact:** a request rejected before commit stores nothing (§6.1 step 8).
* **Options:** (a) the operator may correct the request and reuse the same key; (b) a rejected key is consumed/burned (needs tombstones, OQ-14); (c) reuse allowed only for validation-class rejections (400/422 before any key claim), not after a claim was made.
* **Recommendation:** (a) is consistent with "nothing stored"; (c) if the owner prefers stricter intent tracking.
* **Pros (a):** simple; no stranded keys. **Cons (a):** the same key can mean different specifications at different times (only one can ever commit). **Risk:** if the first request actually committed and its response was lost, a *corrected* retry hits 422 mismatch — safe, but confusing without OQ-19.

### OQ-19 — Recovery workflow after a key mismatch
* **Context:** a 422 mismatch can be a true operator error, **or** the signal that the first request committed and the response was lost. A **new key must not be the answer**: it can apply the same refund twice (§4.3).
* **Options:** (a) the UI shows the stored refund for that key (needs a read endpoint, scoped and authorized) and the operator confirms what was recorded; (b) the operator checks the payment's refund history/receipt manually; (c) an authorized "lookup by key" support tool.
* **Recommendation:** (a), with the original key kept until the operator has seen the stored result; a new key only for a verified **new** intent.
* **Pros (a):** removes the double-refund path. **Cons (a):** a new read contract and UI. **Risk (b):** relies on human reconciliation of the F1 receipt problem.

### OQ-20 — UI: one key per refund intent
* **Observed:** the admin UI sends no key today (F5).
* **Requirement proposed for the UI (policy needs the owner):** the UI creates **one key per refund intent** (when the operator opens the refund action) and **reuses the same key** for double clicks, timeouts and retries of that intent; a new key only after the operator starts a **new** refund. The button is disabled while a request is in flight.
* **Options:** (a) key generated when the dialog opens and kept in memory until the request is confirmed finished or the operator cancels; (b) generated per click (**not acceptable**: defeats idempotency); (c) generated server-side and handed out by a separate "begin refund" call.
* **Recommendation:** (a); (c) if client memory loss on reload must be covered.
* **Pros (a):** no API change beyond the header. **Cons (a):** a page reload loses the key (then the operator is in OQ-19). **Risk (b):** every double click becomes a new refund.

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

**Same key, different specification**
* T5 Different amount; different reason; different payment; omitted vs explicit amount (OQ-4 outcome) ⇒ 422 `…KEY_REUSED`, **no** state change (row counts, balances, audit chain `prev_hash`/`row_hash` continuity unchanged).
* T6 Body differing only in an ignored field ⇒ treated as the same specification.

**Canonicalization (table-driven)**
* T7 Amounts `1000`, `"1000"`, `1000.0`, `"1e3"` equal; `"1000.5"` and negatives/zero rejected, never rounded.
* T8 Reasons: leading/trailing ASCII and Unicode spaces; NFC vs NFD forms of the same text (if NFC is adopted); bidi controls; zero-width characters; Persian/Arabic letter variants and digit systems (must compare **different**); 255/256-character boundary; invalid UTF-8.
* T9 Key format: missing ⇒ 400; non-UUID ⇒ 400; uppercase UUID equals its lowercase form.

**Concurrency [real DB]**
* T10 N parallel identical requests ⇒ exactly one refund; all others replay.
* T11 Parallel same key, different spec ⇒ exactly one applied, the rest 422, no partial state.
* T12 Parallel same key on two different payments (same invoice and different invoices) ⇒ one applied, the other 422; **records** whether a deadlock or lock-wait timeout occurs (UNKNOWN today; none is assumed absent); every deadlock/timeout that does occur must end in a full rollback and a retry with the **same key** must converge to exactly one refund.
* T13 Parallel different keys whose sum exceeds the cap ⇒ total refunded never exceeds the amount paid; the surplus request gets 422 with the locked `max_refundable`.
* T14 Refund vs void and refund vs capture on the same invoice (lock-order probe; records whether a deadlock is ever observed — currently UNKNOWN).
* T15 Establish on a real database, under REPEATABLE READ and READ COMMITTED, what a duplicate-key insert and a read inside the *failed* transaction can see, what locks a key claim and a locking read of a *missing* key take (gap/next-key), and that the fresh-transaction re-read of §6.1 step 5 sees the winner's committed row. These facts are UNKNOWN today.

**Crash / rollback**
* T16 **Failure injection per statement** under the real wrapper in its default soft mode: make each statement fail in turn (key claim insert, payment lock read, invoice lock read, key-row completion, payment update, invoice update, audit insert, audit-seq update, commit) ⇒ the outcome is a full rollback (or "unknown" for the commit), **never** a committed half-applied refund; a failed key insert is not treated as duplicate; a failed lock read is not treated as "row not found"; a retry with the same key then applies exactly once.
* T17 Lost response **after a confirmed commit** ⇒ the retry replays the stored original; counts of refund rows, `refunded_amount`, invoice fields and audit rows unchanged by the replay.
* T17a **Commit outcome unknown** (the commit statement errors or its acknowledgement is dropped, with the commit both having and not having reached the server) ⇒ the system reports "outcome unknown — retry with the same key", **not** "rolled back"; the retry converges to exactly one refund in both variants.
* T18 Audit failure (guard row missing) ⇒ whole refund rolled back, no key row left behind.
* T25 **Lost response + mismatch:** first refund with key K commits and its response is lost; the client sends a **different** specification with the same K ⇒ 422, no state change, and the stored result for K is unchanged. Then the client (wrongly) uses a **new** key for the same intent ⇒ documented as the double-apply hazard it is (the test pins that the system does *not* detect it), which is why the UI/workflow (OQ-19, OQ-20) must prevent it.
* T26 Duplicate-key path: the loser of a same-key race rolls back and re-reads in a fresh transaction (not a continued snapshot) and finds the winner's committed row under REPEATABLE READ and READ COMMITTED; if the winner rolled back, the loser retries a bounded number of times and never applies silently after the bound.

**Cap never exceeded**
* T19 Property-style: random interleavings of refunds (same/different keys, retries) on one payment ⇒ `refunded_amount ≤ amount` always and `refunded_amount == legacy + Σ applied amounts`.

**Multi-clinic**
* T20 Same key in clinic A and clinic B ⇒ both succeed independently; clinic A cannot replay/see clinic B's result; a payment of another clinic ⇒ 404 before any key lookup.
* T21 No trusted scope ⇒ `CLINIC_SCOPE_REQUIRED`, nothing written; `bin/tenant-tripwire.py` passes (no clinic id literal 1, no default clinic).

**Legacy and adjacent behaviour**
* T22 Payment with a pre-existing refund (non-zero `refunded_amount`, no refund record): new refund respects the cap; no error from the absence of earlier records.
* T23 Receipt and void after a partial refund (F1, F2): characterize the **current** behaviour first, because the round-11 report says NOT TESTED; no behaviour is asserted as correct until the owner decides (OQ-11).
* T24 UI contract: the admin UI sends **one key per refund intent**, reuses it for double clicks/timeouts/retries (never regenerates on retry), handles 200 for first success and replay, shows the 422 mismatch message **without** offering a new key, and its amount handling is characterized first (F5: Persian digits, thousands separators).

---

## 11. Limitations and what was not done

* **Nothing was executed.** Local PHP is **PHP-WASM only**; there is no MySQL/MariaDB in the authoring sandbox, so every lock, isolation and crash statement above is **INFERENCE** from reading code and general InnoDB semantics; none was measured.
* The earlier "version two" refund design/migration was **not read and not used**; none is present in `main`.
* The round-11 "NOT TESTED" report was **not located** in the repository.
* External standards/vendor documentation could not be consulted (network limited to GitHub and package registries); no external behaviour is asserted.
* `intl` (`Normalizer`) availability, the DB isolation level, lock-wait settings and how `CpmsDb` maps lock errors to HTTP are **UNKNOWN**.
* Capture-vs-refund lock ordering was **not analysed**; deadlock freedom is **not claimed**. InnoDB duplicate-key/gap-lock behaviour per isolation level is **UNKNOWN**; no external documentation could be consulted.
* `toMinor()`/PHP numeric-string behaviour and the Node reproduction of `parseInt` (F5): PHP was not run; the Node run reproduces JavaScript only, not a browser session.
* Round 2 was verified by **reading** code at `d1d49c5`; nothing was executed against the wrapper, so Requirement W describes what the code *does* (A13/A14 by reading), not a measured failure.
* This document cannot establish that the defect has occurred on any installation (`ledger.md:135`).

## 12. Documentation corrections made in the same PR, and open items

### 12.1 Corrections to other docs (round 1, unchanged in round 2)

Documentation only; no line added or removed in the edited files.

| File | Change |
|---|---|
| `docs/srs/SRS.md:296` | Names the **shared table** and **refunds** explicitly and states that the code-gap description is about the shared table, not evidence about the owner's refund target — removing the contradiction with `SRS.md:294`. |
| `docs/srs/SRS.md:294` and `docs/api/api-contract.md:28` | Both now carry the same decision wording, including the **request fingerprint** from ledger Record A (previously `api-contract.md:28` had it, `SRS.md:294` omitted it), with a note that Record B states the same comparison as "identical parameters". |
| `docs/agent-log.md` (end of file, append-only) | A correction entry for the earlier statement that all four edited lines "keep … request fingerprint" (only `api-contract.md:28` did). The earlier entry is untouched. |

**Open items deliberately not changed:** `SRS.md:281` ("same response" for payment
replay) — depends on **OQ-2(b)**; `error-codes.md:38`; `payment.md:44/51` (F4);
`api-contract.md:29-30` and `data-dictionary.md:125` still describe the shared
table's gap in wording similar to the old `SRS.md:296` — reported, not edited, to
stay inside the requested scope.

### 12.2 What changed in round 2 of this design document

Triggered by two independent finance readers (both: "finance blocker found") on head `37e8a2df…`. Every claim was re-checked in the code before editing.

| Item | Verdict | Change |
|---|---|---|
| B1 soft-mode DB wrapper | **Confirmed** (A13, A14) | New §6.0 Requirement W + options W-1/W-2/W-3 (OQ-21); crash table, T16 and the "no wait cycle is evident" claim corrected (§6.5). |
| B2 inconsistent protocol | **Confirmed** | §6 rewritten as one protocol (§6.1), one lock order (§6.5), rollback + fresh scoped re-read + bounded retry, snapshot stored atomically, unknown-commit ≠ rollback (§6.4); gap/next-key behaviour marked UNKNOWN. |
| B3 "use a new key" after mismatch | **Confirmed** | Removed; §4.3/§6.4 say keep the original key; OQ-19; new test T25. |
| R4 "reuse after rejection" | **Confirmed as policy** | Moved to OQ-18. |
| D1 shared-table `DEFAULT 1` | **Confirmed wrong** (`0016:33-40`) | A4 and Option A corrected; option A still not recommended, for the reasons that remain true. |
| D2 409 vs 422 | **Confirmed wrong** (`:697-710`) | §3.1 corrected. |
| D3 `toMinor` | **Confirmed** | "minor-unit" wording removed (whole rials); numeric strings go through float; exact canonical range specified; U+200F described as a bidi format control; `intl` UNKNOWN; canonicalization pinned by `spec_version`. |
| OQ-6, OQ-7 | **Confirmed in tension** | Marked as deviations needing owner approval. |
| New OQs | — | OQ-12 … OQ-21. |
| Adjacent defects | **Verified by reading** | §8 retitled; F5 (UI amount parsing/no confirmation) and F6 (SRS:281) added; none fixed. |
