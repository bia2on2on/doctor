# Compatibility Decision — one diagnostic-only, non-blocking exception: persian-woocommerce 10.0.5

> **Document type:** Authoritative compatibility governance record (owner-approved).
> **Recorded:** 2026-10-12.
> **Scope of this record:** the disposition of ONE already-recorded third-party
> baseline failure, and nothing else. It changes no pin, no probe, no matrix leg,
> no assertion and no product code.
> Where this record and an older document overlap, the precedence rules in
> [`2026-09-05-document-precedence-policy.md`](2026-09-05-document-precedence-policy.md) apply.

---

## 1. The recorded failure (unchanged evidence)

`Third-Party Compatibility Baseline (Phase 18 Lane A)`, subject
`persian-woocommerce` **10.0.5** (declared dependency `woocommerce=11.2.0`), lane
WordPress 7.1.3 / PHP 8.3 / MySQL 8.4.11 / Apache, one subject per job:

- Subject state `FAIL`, counts `{"PASS": 57, "INFO": 9, "FAIL": 8}`, taxonomy
  `THIRD-PARTY BASELINE FAILURE - no CPMS classification applicable`.
- Seven material, product-class, post-activation material failures:
  `admin_ajax_wp_layer_reachable_post`, `admin_login_session_post`,
  `front_page_http_200_post`, `no_redirect_loop_front_post`,
  `rest_index_reachable_post`, `wp_admin_reachable_post`, `wp_cli_bootstrap_post`.
  The recorded front-page chain is a revisit of
  `.../wp-admin/admin.php?page=persian-wc-about`.
- One recorded-only, non-material finding: `php_warnings_after_activation`.
- Reproduced with identical check identifiers and identical counts in two
  independent fresh environments (the `push` run on the `main` merge SHA
  `b925f873c1eb822b394531a8e2906c204c0850da`, and the correction head
  `903e962641519f18b7ba9457b16e54cfab4200fb`).
- The lane's dependency control `dep-control-woocommerce` reports no material
  failure, so attribution is to the subject and not to its dependency.

The failure is recorded history; this record does not re-adjudicate it, does not
reclassify it, and does not delete any part of it.

## 2. Owner-approved disposition

The **exact** recorded failure above is accepted as a **diagnostic-only,
non-blocking exception** for the required CI gate.

**It is explicitly NOT a compatibility PASS.** The subject's raw result stays
`FAIL`; its counts, taxonomy, individual checks, annotations and artifacts are
preserved verbatim. Only the *gate disposition* derived for this subject changes:
`ci_gate.blocking` is `false` while `state` remains `FAIL`.

## 3. Conditions — all of them, every run

The exception is evaluated per run from the delivered evidence and applies only
when **all** of the following hold:

1. **Identity (exact):** slug `persian-woocommerce`, version `10.0.5`, declared
   dependency `woocommerce=11.2.0`.
2. **Failure signature (exact set, by stable check identifier):** exactly the
   seven material failures listed in §1, each still product-class and
   post-activation. A missing one, an added one, a different class, or any
   other material failure blocks.
3. **Positive probe-execution evidence:** the `phase_post_executed` fact, the
   `browser_post` fact, and one recorded verdict for each required probe
   identifier pinned in `probe.py`. A missing phase, a missing browser-probe
   fact or a missing verdict blocks.

A matching *count* of failures is never accepted as evidence of equivalence, and
neither message text nor any free-form detail is used for identification.

## 4. Explicit non-effects

- No other plugin and no other version is covered — including a different
  version of the same plugin, and a different plugin reproducing the same
  failure identifiers.
- The general rule is unchanged: every other material `FAIL` (product,
  infrastructure/environment class C, harness/fixture class D, or a missing
  evidence phase) fails the subject job and the aggregate.
- No probe is disabled, no assertion weakened, no matrix leg removed, no plugin
  pin changed, no CPMS product code touched. No blanket `continue-on-error` and
  no workflow-level waiver exists anywhere in the implementation.
- The aggregate re-derives the exception from the delivered evidence; it never
  accepts a producer-supplied boolean. It reports FAIL subjects, accepted
  diagnostic exceptions, blocking failures and PARTIAL subjects separately.

## 5. Implementation and evidence of record

- `probe.py` — `DIAGNOSTIC_EXCEPTION` and `diagnostic_exception()`; the raw state
  derivation is untouched, and the disposition is carried in `ci_gate`.
- `report.py` — the aggregate applies the same policy and exits non-zero for any
  blocking failure.
- `third-party-baseline.yml` — the propagation contract documents this single
  exception; the fail-closed wiring is unchanged.
- `test_result_propagation.py` — deterministic tests for the accepted signature
  and for every way it can differ (other version, other plugin, other dependency,
  incomplete or extended signature, class C, class D, missing phase, missing
  probe evidence), plus the aggregate re-derivation cases.
- `docs/agent-log.md` — append-only record of the change and its validation.

## 6. Known limitation of signature matching (stated, not hidden)

A future failure of persian-woocommerce 10.0.5 caused by a *different* root cause
that produced exactly the same seven failing check identifiers, the same classes
and a complete set of probe-execution evidence would satisfy this policy. The
policy therefore requires the exact pinned version and dependency, and it keeps
the raw `FAIL`, the counts, the taxonomy and the per-check evidence in every
artifact and summary — so the accepted failure is always inspectable, is never
reported as a PASS, and is re-evaluated on every run. The exception is bounded to
one version of one plugin and cannot be extended without a new owner decision.
