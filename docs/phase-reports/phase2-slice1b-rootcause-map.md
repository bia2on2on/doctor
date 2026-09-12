# Phase 2 — Slice 1B: Root-Cause Map (recorded BEFORE coding, per STEP 2)

Status: working note for the GREEN slice that follows the executable RED
checkpoint on Draft PR #27.

- RED head: `43cf2ef6ee494e206c25130033a48613e461893e`
- RED CI run: `34697309922` (attempt 1, `pull_request`, completed/failure)
- RED evidence: `Tests: 682, Assertions: 4705, Failures: 5.` — 5 EXPECTED RED,
  3 controls PASS, 0 errors.
- Canonical design: `docs/architecture/phase2-tenant-context-remediation-design.md`
  (§A-3, §5-D-1..D-3, §9 RT-3/4/6/12/14).

Every line reference below was read from the working tree at
`43cf2ef6ee494e206c25130033a48613e461893e`.

---

## RT-3 — cross-Clinic SMS credential / config misuse

Trace of `SmsService::dispatchMessage(int $messageId)`:

| step | code | what it resolves from |
|---|---|---|
| load row | `fetchMessage($messageId)` (`SmsService.php:861-870`) | row **has** `clinic_id`, never used for config |
| provider | `:186` `$this->providers->get((string)($row['provider'] ?? 'log'))` | row — correct |
| **credential** | `:187` `$this->plaintextCredentials()` → `:646` `storedAuth()` → `$this->settings->get('sms.auth')` → `:670` `CredentialVault::decrypt()` | **`$this->settings`** — the Settings instance captured at construction |
| **sender** | `:189` `$this->settings->get('sms.sender', '')` | **`$this->settings`** |
| **advanced** | `:190` `$this->settings->get('sms.advanced', [])['timeout_sec']` | **`$this->settings`** |

`$this->settings` is fixed once, in `App::smsService()` (`App.php:756-768`).

**Smallest product root cause:** `dispatchMessage()` resolves per-Clinic SMS
configuration from a *process-pinned* `Settings` object instead of from the
Clinic that durably owns the message row. The installation-level Vault key
(`CredentialVault::key()` — env or WP salts) decrypts any Clinic's sealed
credential silently, so nothing downstream rejects the wrong credential
(§5-D-2). The isolation barrier must be scope resolution, not cryptography.

Same class of defect on the write path: `sendEvent(int $clinic_id, …)` inserts
the row with the caller's `$clinic_id` but reads `sms.advanced` / templates /
active provider from `$this->settings` (`:117`, `:87`, `:620`).

## RT-6 — A → B → A process-level leakage

| process static | captures | reset by `resetScope()` / `replaceExplicitScope()`? |
|---|---|---|
| `App::$settings` (`:144`) | `new Settings(db, scope()->clinicId, audit)` | ✅ yes (`:871`, `:884`) |
| `App::$smsService` (`:149`) | the Clinic-A `Settings` instance + `providers()` | ❌ **no** |
| `App::$providers` (`:148`) | `new GenericApiSmsProvider((array) settings()->get('sms.generic'))` — Clinic-A generic config **frozen** (`:679-691`) | ❌ **no** |
| `App::$dispatcher` (`:146`) | `settings()` + every scope-bearing service | ❌ **no** |

Already-safe caches (do not touch): `Settings::$cache` is keyed by `clinicId`
(`Settings.php:125`, `:307`) — the C6 fix; the leak is **object identity**
captured in the singletons, not the row cache.

**Smallest product root cause:** scope-bearing service singletons are not
scope-neutral and are not invalidated on scope change, so the first Clinic to
bootstrap pins SMS configuration for the whole PHP process.

## RT-4 / RT-14 — background dispatcher construction

`App::dispatcher()` (`:1002-1032`) eagerly evaluates, at *construction* time:

- `$settings = self::settings();` → `Settings::__construct(…, self::scope()->clinicId, …)` → `App::scope()` (`:851-859`) → `SystemClinicResolver::resolve()` (`:36-58`) → throws `CLINIC_SCOPE_REQUIRED` when `clinic_count !== 1`;
- `self::smsService()`, `self::visitService()`, `self::handwritingService()`,
  `self::notificationService()`, `self::exportService()`, `self::backupService()`
  — each of which calls `self::settings()`.

Separation of the two dependency kinds:

| genuinely installation/system-wide (no Clinic needed) | needs per-Clinic resolution |
|---|---|
| `db()`, `op()`, `audit()`, `jobs()`, `rate()`, `idem()`, `vault()`, `providers()` (adapters), `licenseService()` (its `licenseGateway()` already fail-softs `license.server_url` to `''` in a `catch`) | `settings()`, and therefore `smsService()`, `visitService()`, `handwritingService()`, `notificationService()`, `exportService()`, `backupService()` |

**Smallest product root cause:** handler *construction* (which needs Settings)
happens at dispatcher construction, so a missing Clinic aborts the whole tick
before any `claim()`. `JobsDispatcher::tick()` (`:37-66`) already isolates
per-handler failures with `try/catch (\Throwable)` — that code is correct and
must not be rewritten (confirmed by the passing control
`testHandlerLevelFailureIsAlreadyIsolatedWithinAConstructedDispatcher`).

Consequence: moving handler construction inside the registered callable moves
the scope failure inside `tick()`'s existing per-handler `try/catch` ⇒ per-job
fail-closed (RT-4) **and** per-job isolation (RT-14) with no change to `tick()`.

`App::runTick()`'s `recordTick()` already swallows `\Throwable` (`:296-302`), so
the heartbeat does not re-introduce a whole-tick abort.

## RT-12 — missing job scope-classification contract

No production contract exists. Probed and absent at the RED checkpoint:
`JobScopeRegistry`, `JobScopeClass`, `JobScope`,
`JobsDispatcher::scopeClassFor()`, `::scopeClass()`, `::registeredTypes()`.
`JobsDispatcher::$handlers` is `private` with no accessor, and `RECURRING_JOBS`
is `private`, so the RED test had to use Reflection to enumerate.

Authoritative classification, taken from canonical §A-3 (15 registered types —
not invented here):

- **T (tenant-scoped, 2):** `sms.send`, `report.export`
- **S (installation-scoped, 7):** `cleanup.otp`, `cleanup.rate_limits`,
  `cleanup.idem`, `cleanup.oplog`, `handwriting.gc`, `license.refresh`,
  `backup.run`
- **W (installation-wide sweep, per-row tenant semantics, 6):** `holds.expire`,
  `slots.generate`, `visits.no_show`, `notif.dispatch`, `appt.reminder`,
  `fu.reminder`

Consistency rule (§A-3): `T ⇒ clinic_id` non-null and validated · `S ⇒ NULL` ·
`W ⇒ NULL` with per-row attribution. `NULL` never generically means "system";
validity comes only from the registered class of that type.

## Durable authoritative context available TODAY for the T jobs (no new schema)

- `sms.send` → `cpms_sms_messages.clinic_id` (the message row's ownership).
- `report.export` → `payload_json.clinic_id`, already validated fail-closed by
  `ExportService::clinicIdFromJobPayload()` (`:393-404`) and bound with
  `bindJobClinic()` (`:410-422`) — the precedent this slice follows.

No tenant ownership is invented: both sources already exist and are already
written by production code.

## Boundaries explicitly respected

- `sendEvent(int $clinic_id, …)` keeps accepting an explicit clinic id from its
  trusted internal callers (`OtpService:145`, `BookingService:1026`,
  `ApptReminderHandler:68`, `FollowUpReminderHandler:64`, `SmsService:418/480`),
  and `tests/Integration/SmsFlowTest.php:59` deliberately passes `60011`.
  Adding a "must equal current scope" check there would be an A-class
  regression. The fix is that `$clinic_id` now **drives configuration
  resolution** instead of being ignored for it — strictly more isolated, no
  capability bypass, REST-side scope rules untouched.
- No schema change, no migration (latest remains
  `2026_09_09_0020_idempotency_clinic_scope.php`), no workflow change.
