<?php

/**
 * Phase 2 — Slice 1A: EXECUTABLE RED TEST FOUNDATION for the multi-Clinic
 * background-job / SMS isolation gaps.
 *
 * Canonical design: `docs/architecture/phase2-tenant-context-remediation-design.md`
 * (status on this checkpoint: **APPROVED DESIGN DIRECTION — NOT YET IMPLEMENTED**).
 * This file implements ONLY the RED specification items RT-3, RT-4, RT-6,
 * RT-12 and RT-14. It contains **no product fix, no migration, no schema
 * change and no workflow change**.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT EACH TEST ASSERTS, AND THE RESULT EXPECTED ON THIS CHECKPOINT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * RT-3 `testSmsConfigAndCredentialForClinicBMessageResolveToClinicB`
 *      EXPECTED **RED**. `SmsService::dispatchMessage(int $messageId)` receives
 *      only a message id, yet resolves `sender` / `advanced` / the sealed
 *      `sms.auth` credential from the **Settings instance the service was
 *      constructed with** (`SmsService.php:186-190`, `:646-660`, `:667+`), not
 *      from the message row's `clinic_id`. Because the installation-level Vault
 *      key decrypts any Clinic's credential silently, the assertion is on
 *      **credential identity**, per RT-3's explicit correction.
 *
 * RT-6 `testSequentialClinicABADoesNotLeakProcessLevelSmsState`
 *      EXPECTED **RED**. `App::$smsService` (`App.php:149`, `:756`) is a
 *      process-level singleton that keeps the Settings instance of whichever
 *      Clinic bootstrapped first; `App::resetScope()` / `replaceExplicitScope()`
 *      only null `App::$settings` (`App.php:871`, `:884`) — there is **no
 *      public reset for `$smsService` or `$providers`**.
 *
 * RT-4 `testNoCurrentUserDoesNotDeriveTenantAndAmbiguousScopeFailsClosed`
 *      EXPECTED **PASS** — records the CURRENT fail-closed behaviour honestly.
 *      RT-4 `testBackgroundTickBoundaryIsReachableWithoutAnyClinicScope`
 *      EXPECTED **RED** — `App::dispatcher()` reaches `settings()` → `scope()`
 *      (`App.php:1002+`, `:839-849`, `:858`) so the whole tick boundary is
 *      unreachable with no Clinic scope; the design target is per-job
 *      fail-closed, not whole-tick fail-closed.
 *
 * RT-14 `testPerJobScopeFailureDoesNotAbortUnrelatedJobInSameTick`
 *      EXPECTED **RED** — one scope-invalid job currently prevents an unrelated
 *      valid job in the same tick from running, because the abort happens
 *      during dispatcher construction, before any job is claimed.
 *      RT-14 `testHandlerLevelFailureIsAlreadyIsolatedWithinAConstructedDispatcher`
 *      EXPECTED **PASS** — pins the precise boundary: `JobsDispatcher::tick()`
 *      already isolates per-handler failures (`JobsDispatcher.php:56-64`); the
 *      missing isolation is at the tenant-context / tick boundary.
 *
 * RT-12 `testRegisteredJobTypesAreEnumerableFromProductionDispatcher`
 *      EXPECTED **PASS** — the inventory is READ from the production registry
 *      (`App::dispatcher()` + `RECURRING_JOBS`, the design's declared source of
 *      truth, §A-3) and pinned at 15 / 13, so silent registry drift is caught.
 *      RT-12 `testEveryRegisteredJobTypeHasAnExplicitScopeClassification`
 *      EXPECTED **RED** — no production scope-classification contract exists,
 *      so no registered type has a T/S/W classification.
 *      ⚠ This test deliberately does NOT embed a second authoritative registry:
 *      the type list comes from production, and the classification is expected
 *      to come from production too.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * HARNESS NOTE — reflection is used for STATE CONTROL only, never as an
 * assertion subject. `App::$settings/$dispatcher/$smsService/$providers` have no
 * public reset, and the WP test suite runs every test in ONE PHP process, so
 * without resetting them the outcome of these tests would depend on which test
 * ran first. Reflection is therefore used (a) to capture/restore those statics
 * exactly (so this class cannot pollute any other suite) and (b) to null them
 * in order to model a genuinely fresh cron process. Every assertion below is on
 * a PUBLIC, externally observable behaviour: `SmsService::status()`,
 * `SmsService::dispatchMessage()` observed at the provider boundary,
 * `App::settings()->clinicId()`, `cpms_jobs` row status, and row side effects.
 * ────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiClinicSmsFixture;
use ClinicCore\Tests\Integration\Fixtures\RecordingSmsProvider;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/RecordingSmsProvider.php';
require_once __DIR__ . '/Fixtures/Phase2MultiClinicSmsFixture.php';

final class Phase2JobScopeRedFoundationTest extends WP_UnitTestCase
{
    use Phase2MultiClinicSmsFixture;

    /** Process-level statics of App that have no public reset. */
    private const APP_STATICS = ['settings', 'dispatcher', 'smsService', 'providers'];

    private RecordingSmsProvider $recorder;

    /** @var array<string, mixed> */
    private array $savedAppStatics = [];

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

        // Deterministic, order-independent start: the queue must be empty and
        // the tick lock must be free (a leftover lock would be a C-class
        // environment artifact, not the defect under test).
        $this->fxReleaseTickLock();
        $this->fxClearQueue();

        $this->captureAppStatics();
        $this->buildMultiClinicSmsFixture();

        $this->recorder = new RecordingSmsProvider();
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();
    }

    protected function tearDown(): void
    {
        // Restore first: no static of App may outlive this test.
        $this->restoreAppStatics();
        $this->fxPurge();
        $this->fxReleaseTickLock();

        ScopeContext::clear();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::resetScope();

        parent::tearDown();
    }

    // =================================================================
    // RT-3 — SMS credential / config isolation between two Clinics
    // =================================================================

    /**
     * RT-3: for a message OWNED BY CLINIC B, the provider configuration that
     * reaches the transport must be Clinic B's — including the sealed
     * credential that production decrypts for the call.
     *
     * The assertion is on what the fake transport actually observed
     * (credential identity + sender + advanced), NOT on
     * `cpms_sms_messages.clinic_id`.
     *
     * EXPECTED RED on this checkpoint.
     */
    public function testSmsConfigAndCredentialForClinicBMessageResolveToClinicB(): void
    {
        // Model a real process that bootstrapped in a Clinic-A context: the
        // production singletons are built once, from Clinic A's Settings.
        $this->simulateFreshBackgroundProcess();
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();

        $sms = App::smsService();
        self::assertSame(
            self::FX_SENDER_A,
            $sms->status()['sender'],
            'precondition: the service under test resolved Clinic A configuration at construction'
        );

        // A message that belongs to Clinic B.
        $messageIdB = $this->fxSeedQueuedMessage($this->fxClinicB, 'RT-3 message owned by Clinic B');
        $row = $this->fxMessageRow($messageIdB);
        self::assertSame($this->fxClinicB, (int) $row['clinic_id'], 'precondition: the message row is owned by Clinic B');
        self::assertSame(RecordingSmsProvider::ID, (string) $row['provider'], 'precondition: message targets the recording provider');
        self::assertNotSame($this->fxClinicA, $this->fxClinicB, 'fixture: Clinic A and Clinic B are distinct rows');

        // The production runtime path for a queued message: message id only.
        $sms->dispatchMessage($messageIdB);

        $invocation = $this->recorder->lastInvocation();
        self::assertNotNull($invocation, 'the recording transport must have been invoked for the QUEUED Clinic B message');
        self::assertIsArray($invocation);

        $observedCred = (string) ($invocation['creds']['api_key'] ?? '');
        $observedSender = (string) ($invocation['opts']['sender'] ?? '');
        $observedTimeout = (int) ($invocation['opts']['timeout_sec'] ?? 0);

        self::assertNotSame(
            self::FX_CRED_A,
            $observedCred,
            'RT-3 RED: Clinic A credential must NEVER be observed for a Clinic B message'
        );
        self::assertSame(
            self::FX_CRED_B,
            $observedCred,
            'RT-3 RED: the credential decrypted for a Clinic B message must be Clinic B synthetic credential'
        );
        self::assertSame(
            self::FX_SENDER_B,
            $observedSender,
            'RT-3 RED: sms.sender for a Clinic B message must be Clinic B sender'
        );
        self::assertSame(
            self::FX_TIMEOUT_B,
            $observedTimeout,
            'RT-3 RED: sms.advanced.timeout_sec for a Clinic B message must be Clinic B value'
        );
    }

    // =================================================================
    // RT-6 — A → B → A process-state isolation
    // =================================================================

    /**
     * RT-6: three sequential operations in ONE process (A, then B, then A) must
     * each observe only their own Clinic's Settings / provider configuration /
     * sender / credential identity.
     *
     * Observable surface (public API): `SmsService::status()` and
     * `Settings::clinicId()`. No private state is asserted.
     *
     * EXPECTED RED on this checkpoint (step B observes Clinic A's SMS config).
     */
    public function testSequentialClinicABADoesNotLeakProcessLevelSmsState(): void
    {
        $this->simulateFreshBackgroundProcess();
        // The provider registry itself is built from `settings()`, so a scope is
        // needed to construct it at all (that dependency is itself the RT-4
        // finding). Step 1 then re-establishes Clinic A explicitly.
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();
        $masked = str_repeat("\u{2022}", 8);

        // ---- Step 1: Clinic A ----
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        self::assertSame($this->fxClinicA, App::settings()->clinicId(), 'RT-6 step A: Settings resolves Clinic A');
        $stepA = App::smsService()->status();
        self::assertSame(self::FX_SENDER_A, $stepA['sender'], 'RT-6 step A: sender is Clinic A');
        self::assertSame($masked . '0001', (string) ($stepA['credentials']['api_key'] ?? ''), 'RT-6 step A: credential identity is Clinic A');
        self::assertSame(self::FX_TIMEOUT_A, (int) ($stepA['advanced']['timeout_sec'] ?? 0), 'RT-6 step A: advanced is Clinic A');

        // ---- Step 2: Clinic B (public reset only — no private poking) ----
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicB));
        self::assertSame($this->fxClinicB, App::settings()->clinicId(), 'RT-6 step B: Settings resolves Clinic B');
        $stepB = App::smsService()->status();
        self::assertSame(
            self::FX_SENDER_B,
            $stepB['sender'],
            'RT-6 RED: after switching scope to Clinic B, the SMS service must resolve Clinic B sender (no process-level pinning)'
        );
        self::assertSame(
            $masked . '0002',
            (string) ($stepB['credentials']['api_key'] ?? ''),
            'RT-6 RED: after switching scope to Clinic B, the resolved credential identity must be Clinic B'
        );
        self::assertSame(
            self::FX_TIMEOUT_B,
            (int) ($stepB['advanced']['timeout_sec'] ?? 0),
            'RT-6 RED: after switching scope to Clinic B, sms.advanced must be Clinic B'
        );

        // ---- Step 3: back to Clinic A ----
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        self::assertSame($this->fxClinicA, App::settings()->clinicId(), 'RT-6 step A2: Settings resolves Clinic A again');
        $stepA2 = App::smsService()->status();
        self::assertSame(self::FX_SENDER_A, $stepA2['sender'], 'RT-6 step A2: sender is Clinic A again');
        self::assertSame($masked . '0001', (string) ($stepA2['credentials']['api_key'] ?? ''), 'RT-6 step A2: credential identity is Clinic A again');
    }

    // =================================================================
    // RT-4 — cron / no-current-user behaviour
    // =================================================================

    /**
     * RT-4 (current behaviour, recorded honestly): in a multi-Clinic
     * installation with NO current WP user and no explicit scope, tenant is
     * NOT derived from the user and NO Clinic is picked arbitrarily —
     * resolution fails closed with CLINIC_SCOPE_REQUIRED.
     *
     * EXPECTED PASS on this checkpoint.
     */
    public function testNoCurrentUserDoesNotDeriveTenantAndAmbiguousScopeFailsClosed(): void
    {
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'precondition: there is no current WP user');

        $this->simulateFreshBackgroundProcess();

        $clinicCount = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'));
        self::assertGreaterThanOrEqual(2, $clinicCount, 'precondition: this is a multi-Clinic installation');

        $caught = null;
        try {
            App::scope();
        } catch (ScopeRequiredException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(ScopeRequiredException::class, $caught, 'RT-4: no WP user + no explicit scope must fail closed, never guess a Clinic');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $caught->errorCode, 'RT-4: fail-closed error code');
    }

    /**
     * RT-4 (target contract): background/tick processing must not need an
     * arbitrary Clinic just to become reachable. System-wide/sweep work must be
     * dispatchable with no WP user and no Clinic scope; tenant-scoped work must
     * fail closed PER JOB (measured by RT-14), not at the whole-tick boundary.
     *
     * EXPECTED RED on this checkpoint.
     */
    public function testBackgroundTickBoundaryIsReachableWithoutAnyClinicScope(): void
    {
        wp_set_current_user(0);
        $this->simulateFreshBackgroundProcess();

        $dispatcher = null;
        $thrown = null;
        try {
            $dispatcher = App::dispatcher();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNull(
            $thrown,
            'RT-4 RED: the background tick boundary must be constructible with no WP user and no Clinic scope '
                . '(system/sweep work must not require an arbitrary Clinic). Actual: '
                . ($thrown === null ? 'n/a' : get_class($thrown) . ': ' . $thrown->getMessage())
        );
        self::assertInstanceOf(JobsDispatcher::class, $dispatcher);
    }

    // =================================================================
    // RT-14 — per-job failure isolation
    // =================================================================

    /**
     * RT-14 (target contract): a job whose required tenant scope is
     * invalid/missing must fail closed WITHOUT tenant guessing, and an
     * unrelated valid job in the SAME tick must still be processed.
     *
     * Observable queue/handler outcomes: `cpms_jobs.status` of both jobs plus
     * the real side effect of the valid job (an expired OTP row is deleted).
     *
     * EXPECTED RED on this checkpoint (whole-tick abort at dispatcher
     * construction leaves both jobs queued and the valid job unprocessed).
     */
    public function testPerJobScopeFailureDoesNotAbortUnrelatedJobInSameTick(): void
    {
        wp_set_current_user(0);
        $this->simulateFreshBackgroundProcess();

        // Deterministic order: the scope-invalid job is claimed first.
        // `report.export` is the one tenant-scoped type that already validates
        // its payload clinic fail-closed (design §1 row 15) — payload without
        // clinic_id => ScopeRequiredException('CLINIC_SCOPE_REQUIRED').
        $jobInvalid = App::jobs()->enqueue(
            'report.export',
            ['actor_id' => 0, 'type' => 'appointments'],
            null,
            9,
            1 // single attempt => terminal `failed`, deterministic status
        );

        // Unrelated, valid, safely observable job (system-wide sweep).
        $otpId = $this->fxSeedExpiredOtpToken();
        $jobValid = App::jobs()->enqueue('cleanup.otp', [], null, 8, 1);

        self::assertSame('queued', $this->fxJobStatus($jobInvalid), 'precondition: invalid job queued');
        self::assertSame('queued', $this->fxJobStatus($jobValid), 'precondition: valid job queued');

        $result = null;
        $thrown = null;
        try {
            $result = App::runTick(20);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        if ($thrown === null) {
            self::assertNotSame(-1, $result, 'C-class: tick was skipped because another runner holds the tick lock');
        }

        self::assertNull(
            $thrown,
            'RT-14 RED: one scope-invalid job must not abort the whole tick. Actual: '
                . ($thrown === null ? 'n/a' : get_class($thrown) . ': ' . $thrown->getMessage())
        );

        self::assertSame(
            'failed',
            $this->fxJobStatus($jobInvalid),
            'RT-14: the job with an invalid/missing tenant scope fails closed'
        );
        self::assertSame(
            'success',
            $this->fxJobStatus($jobValid),
            'RT-14 RED: the unrelated valid job in the same tick must still be processed'
        );
        self::assertSame(
            0,
            $this->fxOtpRowCount($otpId),
            'RT-14 RED: the valid job side effect must have been applied'
        );
    }

    /**
     * RT-14 (boundary characterisation): once the dispatcher IS constructed
     * with a trustworthy scope, `JobsDispatcher::tick()` already isolates
     * per-handler failures — the invalid job fails and the unrelated valid job
     * still runs.
     *
     * This pins the gap precisely: the missing isolation is at the
     * tenant-context / tick boundary, not inside `JobsDispatcher::tick()`.
     *
     * EXPECTED PASS on this checkpoint.
     */
    public function testHandlerLevelFailureIsAlreadyIsolatedWithinAConstructedDispatcher(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();
        $dispatcher = App::dispatcher();

        $jobInvalid = App::jobs()->enqueue('report.export', ['actor_id' => 0, 'type' => 'appointments'], null, 9, 1);
        $otpId = $this->fxSeedExpiredOtpToken();
        $jobValid = App::jobs()->enqueue('cleanup.otp', [], null, 8, 1);

        $processed = $dispatcher->tick(20);
        self::assertGreaterThanOrEqual(1, $processed, 'precondition: at least the valid job was processed');

        self::assertSame('failed', $this->fxJobStatus($jobInvalid), 'invalid job fails closed inside tick()');
        self::assertSame('success', $this->fxJobStatus($jobValid), 'unrelated valid job still processed inside tick()');
        self::assertSame(0, $this->fxOtpRowCount($otpId), 'valid job side effect applied inside tick()');
    }

    // =================================================================
    // RT-12 — job-scope registry contract
    // =================================================================

    /**
     * RT-12 (part 1): the set of registered job types is derivable from the
     * PRODUCTION registry — the source of truth named by the canonical design
     * (§A-3): `App::dispatcher()` plus `RECURRING_JOBS`.
     *
     * This test intentionally contains NO list of job types of its own; it
     * pins the canonical COUNTS and the structural relationship between the
     * registered set and the recurring set, so silent registry drift is caught.
     *
     * EXPECTED PASS on this checkpoint.
     */
    public function testRegisteredJobTypesAreEnumerableFromProductionDispatcher(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();

        $registered = self::registeredJobTypesFromProduction();
        $recurring = self::recurringJobTypesFromProduction();

        self::assertCount(
            15,
            $registered,
            'canonical design §A-3 records exactly 15 registered job types; actual: ' . implode(', ', $registered)
        );
        self::assertCount(
            13,
            $recurring,
            'canonical design §A-3 records exactly 13 scheduled (recurring) job types; actual: ' . implode(', ', $recurring)
        );
        self::assertSame(
            [],
            array_values(array_diff($recurring, $registered)),
            'every RECURRING_JOBS type must also be registered in App::dispatcher()'
        );

        $eventDriven = array_values(array_diff($registered, $recurring));
        sort($eventDriven);
        self::assertSame(
            ['report.export', 'sms.send'],
            $eventDriven,
            'the two event-driven (non-recurring) registered types per canonical design §A-3'
        );
    }

    /**
     * RT-12 (part 2): every registered job type must have an explicit scope
     * classification in the T/S/W design vocabulary; an unclassified type must
     * be rejected rather than guessed; and NULL must never implicitly mean
     * "system-wide" — meaning comes only from the registered class of that type.
     *
     * The inventory below is READ from production. Nothing here defines a
     * competing registry: the classification is expected to be supplied by
     * product code, which does not exist yet.
     *
     * EXPECTED RED on this checkpoint.
     */
    public function testEveryRegisteredJobTypeHasAnExplicitScopeClassification(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();

        $registered = self::registeredJobTypesFromProduction();
        self::assertNotEmpty($registered, 'precondition: production registry enumerated');

        $probes = self::scopeContractProbes();
        self::assertTrue(
            in_array(true, $probes, true),
            'RT-12 RED: no production scope-classification contract exists for the '
                . count($registered) . ' registered job types [' . implode(', ', $registered) . ']. '
                . 'Required by design A-1.10 / A-1.12 / §A-3: every registered type carries exactly one of T/S/W, '
                . 'an unclassified or unknown type is rejected (never guessed), and NULL never implicitly means system-wide. '
                . 'Probed and absent: ' . implode('; ', array_keys($probes))
        );

        // Structured for the GREEN follow-up: once the contract exists, each
        // registered type is checked individually.
        foreach ($registered as $type) {
            self::assertContains(
                self::scopeClassForType($type),
                ['T', 'S', 'W'],
                'RT-12: registered job type "' . $type . '" has no explicit T/S/W scope classification'
            );
        }
    }

    // =================================================================
    // Harness — process-state control (see the HARNESS NOTE in the docblock)
    // =================================================================

    /**
     * Models a genuinely fresh background process: every process-level App
     * singleton that participates in tenant/SMS resolution starts empty.
     */
    private function simulateFreshBackgroundProcess(): void
    {
        foreach (self::APP_STATICS as $name) {
            self::writeAppStatic($name, null);
        }
        ScopeContext::clear();
        SystemClinicResolver::flush();
        Settings::flushCache();
    }

    private function installRecorder(): void
    {
        $registry = App::providers();
        $registry->register($this->recorder);
        self::assertTrue($registry->has(RecordingSmsProvider::ID), 'precondition: recording transport registered');
    }

    private function captureAppStatics(): void
    {
        $this->savedAppStatics = [];
        foreach (self::APP_STATICS as $name) {
            $this->savedAppStatics[$name] = self::readAppStatic($name);
        }
    }

    private function restoreAppStatics(): void
    {
        foreach (self::APP_STATICS as $name) {
            if (array_key_exists($name, $this->savedAppStatics)) {
                self::writeAppStatic($name, $this->savedAppStatics[$name]);
            }
        }
        $this->savedAppStatics = [];
    }

    private static function readAppStatic(string $name): mixed
    {
        $property = new \ReflectionProperty(App::class, $name);

        return $property->getValue();
    }

    private static function writeAppStatic(string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(App::class, $name);
        $property->setValue($value);
    }

    /**
     * READ-ONLY enumeration of the production job registry. Reflection is used
     * because `JobsDispatcher` exposes no public accessor for its registered
     * types, and the canonical design names `App::dispatcher()` + `RECURRING_JOBS`
     * as the source of truth (§A-3) — explicitly NOT `docs/architecture/background-jobs.md`.
     *
     * @return list<string>
     */
    private static function registeredJobTypesFromProduction(): array
    {
        $dispatcher = App::dispatcher();
        $property = new \ReflectionProperty(JobsDispatcher::class, 'handlers');
        $handlers = $property->getValue($dispatcher);
        self::assertIsArray($handlers, 'precondition: production handler registry readable');

        $types = array_map('strval', array_keys($handlers));
        sort($types);

        return array_values($types);
    }

    /**
     * @return list<string>
     */
    private static function recurringJobTypesFromProduction(): array
    {
        $constant = (new \ReflectionClass(App::class))->getReflectionConstant('RECURRING_JOBS');
        self::assertNotFalse($constant, 'precondition: RECURRING_JOBS readable');
        $recurring = $constant->getValue();
        self::assertIsArray($recurring, 'precondition: RECURRING_JOBS is an array');

        $types = array_map('strval', array_keys($recurring));
        sort($types);

        return array_values($types);
    }

    /**
     * Capability probes for the (currently absent) production scope contract.
     *
     * @return array<string, bool>
     */
    private static function scopeContractProbes(): array
    {
        return [
            'class ClinicCore\Application\Jobs\JobScopeRegistry' => class_exists('ClinicCore\\Application\\Jobs\\JobScopeRegistry'),
            'class ClinicCore\Application\Jobs\JobScopeClass' => class_exists('ClinicCore\\Application\\Jobs\\JobScopeClass'),
            'class ClinicCore\Application\Jobs\JobScope' => class_exists('ClinicCore\\Application\\Jobs\\JobScope'),
            'method JobsDispatcher::scopeClassFor()' => method_exists(JobsDispatcher::class, 'scopeClassFor'),
            'method JobsDispatcher::scopeClass()' => method_exists(JobsDispatcher::class, 'scopeClass'),
            'method JobsDispatcher::registeredTypes()' => method_exists(JobsDispatcher::class, 'registeredTypes'),
        ];
    }

    /**
     * Scope classification for one registered type, as supplied by product
     * code. Returns null while no production contract exists.
     */
    private static function scopeClassForType(string $type): ?string
    {
        if (method_exists(JobsDispatcher::class, 'scopeClassFor')) {
            $value = JobsDispatcher::scopeClassFor($type);

            return is_string($value) ? $value : null;
        }

        return null;
    }

    // =================================================================
    // Harness — queue helpers
    // =================================================================

    private function fxClearQueue(): void
    {
        global $wpdb;
        // Deterministic start WITHOUT depending on what any earlier test in this
        // process left behind. `cpms_jobs` has no tenant column today, so there
        // is no narrower predicate that is still correct. Every assertion in
        // this class targets specific job ids, never a queue count.
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_jobs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_jobs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame(0, $count, 'fixture: the job queue was cleared to a deterministic empty state');
    }

    private function fxReleaseTickLock(): void
    {
        global $wpdb;
        $wpdb->query('SELECT RELEASE_LOCK(\'' . App::TICK_LOCK . '\')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function fxOtpRowCount(int $otpId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_otp_tokens WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $otpId
        ));
    }
}
