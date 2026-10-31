# Classic V1 critical remediation — evidence report

Date: 2026-10-31  
Baseline: `9bf84304b2b143138c638fa1ca1d71869aa1e244`  
PR: <https://github.com/bia2on2on/doctor/pull/288>
Implementation head: `ca8079044e7ed21ee2677f29ef07dd32db4a953b` (the final PR head may add documentation-only commits; exact-head checks are on the PR)

The independent installer report was unavailable. The supplied findings were treated as leads and tested against the actual production plugin. No claim is made to have inspected that report.

## Confirmed and fixed

- **OTP false success.** Baseline returned HTTP 200 with `sms_sent=false`, `retry_enqueued=false`. The REST boundary now returns `503 CLINIC_OTP_DELIVERY_FAILED` with both false flags and `retry_after`; the challenge remains hash-only, cooldown/rate limits remain enforced, and neither patient login nor public booking advances to the code step without affirmative `sms_sent=true`.
- **Unsafe SMS endpoint/test errors.** Baseline allowed an uncaught `SmsSendException` to escape the settings/test REST boundary. Generic endpoint configuration is now SSRF-preflighted before settings writes; unsafe saves/tests return bounded Persian HTTP 400 responses; failed tests persist an error indicator; provider/credential/endpoint changes invalidate stale verification; the settings UI reloads status after failed save/test. The generic provider no longer treats HTTP 500 as a successful connection test.
- **Visit Location disclosure.** Baseline ignored `location_id` on the full record read and could return a Location A record to a Location-restricted doctor selecting Location B. The selector is now a consistency hint, never authority. The persisted Visit Location and active membership Location assignment are enforced; omission cannot bypass a Location restriction. Cross-Clinic and cross-Organization denial remains non-enumerating.
- **Staff cancellation notification.** Baseline had no durable staff-cancellation SMS outbox. Cancellation now enqueues `appointment.cancel_notify` inside the cancellation transaction. The worker binds the persisted appointment Clinic, writes one SMS row and one internal notification transactionally, and relies on existing SMS/internal dedupe keys for retries. Provider failure leaves durable retry state and does not roll back cancellation. `SENT` means provider acceptance, never delivery.
- **Repeated migration ledger reads.** The migration runner now reads the applied-version ledger once per recheck instead of once per shipped migration. The focused in-process probe fell from 31 to 2 queries per recheck on both engines.

## Executed runtime evidence

Focused workflow run at the exact PR head: <https://github.com/bia2on2on/doctor/actions/runs/41180393141>

- MySQL 8: **120 tests, 0 failures, 0 errors, 0 skipped**.
- MariaDB 11.4.13: **120 tests, 0 failures, 0 errors, 0 skipped**.
- Archived baseline with the same 12 new regression/probe tests: **3 failures, 4 errors** on each engine. These are expected RED evidence, not a passing baseline.
- Candidate coverage includes the new critical regressions, OTP dispatch/security/flow/audit, SMS Clinic authorization, clinical flow, reception cancellation, job-scope drift, migrations, and the performance probe.
- Local PHP-wasm frontend source tests: **13 tests, 78 assertions, OK**. PHP lint and JavaScript syntax checks passed. The earlier broad local wasm suite was not green and is not used as database evidence.

### Same-runner authenticated HTTP profile (MySQL 8, synthetic data)

Baseline and candidate official ZIPs were installed sequentially on the same WordPress/MySQL instance. Medians of 12 samples (queue includes one warm-up sample); POSTs used distinct equivalent pre-seeded visits. No real SMS provider was contacted.

| Operation | Baseline queries | Candidate queries | Baseline median ms | Candidate median ms |
|---|---:|---:|---:|---:|
| Staff Portal HTML | 115 | 115 | 91.040 | 89.944 |
| Doctor queue REST | 98 | 69 | 79.785 | 70.843 |
| Visit record REST | 101 | 73 | 80.715 | 72.187 |
| Clinical note POST | 91 | 62 | 77.743 | 70.313 |
| Prescription POST | 98 | 69 | 80.191 | 72.598 |
| Complete consultation POST | 108 | 79 | 82.528 | 74.916 |

The 29-query REST reduction is the measured effect of removing repeated migration-ledger reads. The reported production 214–235 queries/portal request and 2.2–4.0 s clinical POSTs were **not reproduced** in this synthetic CI environment; HTML query count did not improve. Timing differences are small CI medians, not production SLA claims.

## Boundaries and unresolved items

- The Location fix covers the confirmed full-record read. Sibling clinical writes remain Clinic-scoped under the existing contract; no broader Location-write policy was invented.
- The audit logger's global last-row lock was inspected but not changed without evidence that it is the dominant production cost.
- SMS settings validation prevents the unsafe-URL partial write; the existing multi-setting save is not a general schema-wide transaction.
- Queue/provider acceptance is never reported as delivery. Real-provider delivery receipts remain provider-dependent.
- No Modern or Theme Adaptive skin work, merge, release, tag, or deployment was performed.

## Propagation and CI

Production changes are under `clinic-practice-management/**`, which already triggers the standalone-demo workflow on pull requests to `main`; that lane rebuilds the embedded core and verifies the standalone package. The PR triggers the repository's remaining pull-request gates. Exact-head PR CI at implementation head `ca80790`: all 48 checks passed, including CI (`41180393127`), standalone-demo (`41180393282`), Classic V1 evidence (`41180393141`), closure, compatibility, external disposable-site, and coexistence lanes. The full MySQL integration suite executed 2465 tests with 0 failures/errors. A seasonal New York DST fixture was made horizon-aware (it skips only when no fall-back exists inside the supported 365-day generation horizon); the Day-2 public-booking JS spec was aligned with the affirmative `sms_sent` gate.
