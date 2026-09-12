<?php

/**
 * Phase 2 — Slice 1A RED foundation, now the GREEN regression guard for the
 * Slice 1B multi-Clinic background-job / SMS isolation contract.
 *
 * Canonical design: `docs/architecture/phase2-tenant-context-remediation-design.md`
 * (§A-3, §5-D-1..D-3, §9 RT-3 / RT-4 / RT-6 / RT-12 / RT-14).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * LINEAGE (do not lose this)
 * ────────────────────────────────────────────────────────────────────────────
 * RED checkpoint  : `43cf2ef6ee494e206c25130033a48613e461893e`
 * RED CI run      : `34697309922` (attempt 1, `pull_request`, completed/failure)
 * RED observation : `Tests: 682, Assertions: 4705, Failures: 5.` — the 5
 *                   failures were exactly the five tests marked "was RED"
 *                   below; the 3 controls passed; 0 errors, 0 D/C-class
 *                   defects. Executable proof that RT-3 / RT-6 / RT-4 / RT-14 /
 *                   RT-12 were real product gaps, not test bugs.
 * GREEN slice     : Slice 1B — product code that turns those five GREEN.
 *
 * The filename is kept so the RED → GREEN lineage stays traceable in Git.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT EACH TEST NOW ASSERTS
 * ────────────────────────────────────────────────────────────────────────────
 *
 * RT-3 `testSmsConfigAndCredentialForClinicBMessageResolveToClinicB`
 *      (was RED) For a message OWNED BY Clinic B, the configuration that
 *      reaches the transport must be Clinic B's — provider, sender, advanced
 *      and the sealed `sms.auth` credential. Asserted positively
 *      (`assertSame(B)`) with an explicit negative guard against A, because the
 *      installation-level Vault key decrypts any Clinic's credential silently
 *      (§5-D-2): credential IDENTITY is the only meaningful assertion.
 *      Product path: `SmsService::dispatchMessage()` resolves the owner Clinic
 *      from `cpms_sms_messages.clinic_id` via `SettingsFactory`, never from a
 *      process-pinned `Settings`.
 *
 * RT-6 `testSequentialClinicABADoesNotLeakProcessLevelSmsState`
 *      (was RED — leakage observed at leg B; the B → A leg never executed)
 *      Now asserts the COMPLETE `A → B → A` sequence. Every leg asserts sender
 *      AND credential identity AND `sms.advanced`, so no leg can be skipped.
 *      Product path: `SmsService` is scope-neutral (`SettingsFactory` +
 *      injected scope resolver), `App::settings()` is derived per call and the
 *      factory cache is keyed by `clinicId`, and `sms.generic` is resolved
 *      lazily per Clinic instead of being frozen at registry construction.
 *
 * RT-4 `testNoCurrentUserDoesNotDeriveTenantAndAmbiguousScopeFailsClosed`
 *      (control, was PASS — must stay PASS) No current WP user + no explicit
 *      scope ⇒ tenant is NOT derived from the user and NO Clinic is guessed;
 *      resolution fails closed with `CLINIC_SCOPE_REQUIRED`.
 *      RT-4 `testBackgroundTickBoundaryIsReachableWithoutAnyClinicScope`
 *      (was RED) `App::dispatcher()` is constructible with no user and no
 *      Clinic scope, because handler construction moved into the registered
 *      callables.
 *
 * RT-14 `testPerJobScopeFailureDoesNotAbortUnrelatedJobInSameTick`
 *      (was RED) One scope-invalid job fails closed on its own and an unrelated
 *      valid job in the SAME tick is still processed, because the scope failure
 *      now happens inside `JobsDispatcher::tick()`'s existing per-handler
 *      `try/catch`.
 *      RT-14 `testHandlerLevelFailureIsAlreadyIsolatedWithinAConstructedDispatcher`
 *      (control, was PASS — must stay PASS) pins that `JobsDispatcher::tick()`
 *      itself was never the gap; that code was deliberately NOT rewritten.
 *
 * RT-12 `testRegisteredJobTypesAreEnumerableFromProductionDispatcher`
 *      (control, was PASS) Reads the inventory from the PUBLIC production
 *      contracts — `JobsDispatcher::registeredTypes()` and
 *      `App::RECURRING_JOBS` — with no Reflection, and pins the canonical
 *      counts 15 / 13 (§A-3).
 *      RT-12 `testEveryRegisteredJobTypeHasAnExplicitScopeClassification`
 *      (was RED) Consumes the new production contract `JobScopeRegistry`:
 *      every registered type has exactly one of T/S/W, an unknown type is
 *      rejected (never guessed), `NULL` never implicitly means "system", and
 *      the registry cannot drift from the runtime handler registration.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * HARNESS NOTE — Reflection is used for STATE CONTROL only, never as an
 * assertion subject. The process-level App singletons have no public reset and
 * the WP test suite runs every test in ONE PHP process, so without resetting
 * them the outcome would depend on test order. Reflection is therefore used
 * solely to capture/restore those statics (so this class cannot pollute any
 * other suite) and to null them in order to model a genuinely fresh cron
 * process. Every assertion is on a PUBLIC, externally observable behaviour:
 * `SmsService::status()`, `SmsService::dispatchMessage()` observed at the
 * provider boundary, `App::settings()->clinicId()`, `JobScopeRegistry`,
 * `JobsDispatcher::registeredTypes()`, `cpms_jobs` row status, and row side
 * effects.
 * ────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Jobs\JobScopeUnknownException;
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

    /**
     * Process-level statics of App that have no public reset.
     *
     * Slice 1B replaced the pinned `App::$settings` instance with
     * `App::$settingsFactory` (per-Clinic cache), so that is the static this
     * harness now has to capture/restore.
     */
    private const APP_STATICS = ['settingsFactory', 'dispatcher', 'smsService', 'providers'];

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
     * (credential identity + sender + advanced), NOT merely on
     * `cpms_sms_messages.clinic_id`.
     *
     * GREEN since Slice 1B: `dispatchMessage()` resolves the owner Clinic from
     * the message row and takes provider / sender / advanced / sealed
     * `sms.auth` from THAT Clinic's Settings.
     *
     * Assertion strength is deliberately kept at RED level (STEP 9): a
     * positive `assertSame(B)` PLUS an explicit negative guard that A is absent
     * from the whole observed invocation — never merely "not A".
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
            'precondition: the process bootstrapped in a Clinic A context'
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
        self::assertCount(
            1,
            $this->recorder->invocations(),
            'the dispatch path must reach the transport exactly once for this message'
        );

        $observedCred = (string) ($invocation['creds']['api_key'] ?? '');
        $observedSender = (string) ($invocation['opts']['sender'] ?? '');
        $observedTimeout = (int) ($invocation['opts']['timeout_sec'] ?? 0);

        // ---- POSITIVE: the observed configuration IS Clinic B's ----
        self::assertSame(
            ['api_key' => self::FX_CRED_B],
            $invocation['creds'],
            'RT-3: the credential decrypted for a Clinic B message must be exactly Clinic B synthetic credential'
        );
        self::assertSame(
            self::FX_SENDER_B,
            $observedSender,
            'RT-3: sms.sender for a Clinic B message must be Clinic B sender'
        );
        self::assertSame(
            self::FX_TIMEOUT_B,
            $observedTimeout,
            'RT-3: sms.advanced.timeout_sec for a Clinic B message must be Clinic B value'
        );

        // ---- NEGATIVE GUARD: Clinic A is absent from the whole invocation ----
        // The Vault key is installation-level, so a wrong-Clinic credential
        // decrypts silently (§5-D-2). "Not A" alone would be too weak; the
        // positive assertions above plus this whole-payload guard are the
        // meaningful contract.
        $serialized = (string) json_encode($invocation, JSON_UNESCAPED_UNICODE);
        self::assertNotSame(self::FX_CRED_A, $observedCred, 'RT-3: Clinic A credential must never be the observed credential');
        self::assertStringNotContainsString(
            self::FX_CRED_A,
            $serialized,
            'RT-3: no part of what reached the transport may carry Clinic A credential'
        );
        self::assertStringNotContainsString(
            self::FX_SENDER_A,
            $serialized,
            'RT-3: no part of what reached the transport may carry Clinic A sender'
        );

        // The B-configured send genuinely completed — the intended runtime path
        // was reached and finished, not short-circuited.
        self::assertSame(
            \ClinicCore\Domain\Sms\SmsMessageStatus::SENT,
            (string) $this->fxMessageRow($messageIdB)['status'],
            'RT-3: the Clinic B message was actually sent with Clinic B configuration'
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
     * STEP 8: at the RED checkpoint only the leak at leg B was ever observed —
     * PHPUnit stopped at the first failing assertion, so the `B → A` leg never
     * executed. This version asserts the SAME three observables (sender,
     * credential identity, `sms.advanced`) at ALL THREE legs, so a GREEN result
     * is only possible if the entire `A → B → A` sequence ran and each leg
     * resolved its own Clinic.
     *
     * GREEN since Slice 1B.
     */
    public function testSequentialClinicABADoesNotLeakProcessLevelSmsState(): void
    {
        $this->simulateFreshBackgroundProcess();
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();
        $masked = str_repeat("\u{2022}", 8);

        // ---- Leg 1: Clinic A ----
        $this->assertSmsLegResolvesClinic(
            'A',
            $this->fxClinicA,
            self::FX_SENDER_A,
            $masked . '0001',
            self::FX_TIMEOUT_A
        );

        // ---- Leg 2: Clinic B (public reset only — no private poking) ----
        $this->assertSmsLegResolvesClinic(
            'B',
            $this->fxClinicB,
            self::FX_SENDER_B,
            $masked . '0002',
            self::FX_TIMEOUT_B
        );

        // ---- Leg 3: back to Clinic A — this leg is what the RED run never
        // reached. A process that merely "reset to defaults" instead of
        // resolving per Clinic would fail here or on the credential identity.
        $this->assertSmsLegResolvesClinic(
            'A2',
            $this->fxClinicA,
            self::FX_SENDER_A,
            $masked . '0001',
            self::FX_TIMEOUT_A
        );
    }

    /**
     * Switches scope to one Clinic and asserts the full observable SMS
     * configuration identity of that leg. Extracted so all three legs of
     * RT-6 are asserted with identical strength — no leg can be weaker or be
     * silently skipped.
     */
    private function assertSmsLegResolvesClinic(
        string $leg,
        int $expectedClinicId,
        string $expectedSender,
        string $expectedMaskedCredential,
        int $expectedTimeoutSec
    ): void {
        App::replaceExplicitScope(ClinicScope::forClinic($expectedClinicId));

        self::assertSame(
            $expectedClinicId,
            App::settings()->clinicId(),
            'RT-6 leg ' . $leg . ': Settings resolves this Clinic'
        );

        $status = App::smsService()->status();

        self::assertSame(
            $expectedSender,
            $status['sender'],
            'RT-6 leg ' . $leg . ': sms.sender must belong to this Clinic (no process-level pinning)'
        );
        self::assertSame(
            $expectedMaskedCredential,
            (string) ($status['credentials']['api_key'] ?? ''),
            'RT-6 leg ' . $leg . ': the resolved sealed sms.auth identity must belong to this Clinic'
        );
        self::assertSame(
            $expectedTimeoutSec,
            (int) ($status['advanced']['timeout_sec'] ?? 0),
            'RT-6 leg ' . $leg . ': sms.advanced must belong to this Clinic'
        );
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
     * Slice 1B: read through the PUBLIC contracts `JobsDispatcher::registeredTypes()`
     * and `App::RECURRING_JOBS` — Reflection is no longer needed for enumeration.
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
     * Slice 1B GREEN: the classification now comes from the stable production
     * contract `JobScopeRegistry` (public, directly testable, no Reflection and
     * no duplicate test-only registry). The runtime inventory is still READ from
     * production, so this test cannot become self-fulfilling.
     */
    public function testEveryRegisteredJobTypeHasAnExplicitScopeClassification(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($this->fxClinicA));
        $this->installRecorder();

        $registered = self::registeredJobTypesFromProduction();
        self::assertNotEmpty($registered, 'precondition: production registry enumerated');

        // (a) every RUNTIME-registered type carries exactly one valid T/S/W class.
        foreach ($registered as $type) {
            $class = JobScopeRegistry::classFor($type);
            self::assertContains(
                $class,
                JobScopeClass::ALL,
                'RT-12: registered job type "' . $type . '" must carry exactly one of T/S/W'
            );
        }

        // (b) DRIFT GUARD — the classification registry and the runtime handler
        // registration are two production sources; they must not diverge.
        self::assertSame(
            $registered,
            JobScopeRegistry::types(),
            'RT-12: JobScopeRegistry must classify exactly the job types App::dispatcher() registers (no drift)'
        );

        // (c) canonical §A-3 distribution: 2 T / 7 S / 6 W.
        self::assertSame(
            [JobScopeClass::TENANT => 2, JobScopeClass::SYSTEM => 7, JobScopeClass::SWEEP => 6],
            JobScopeRegistry::countsByClass(),
            'RT-12: the T/S/W distribution must match canonical design §A-3'
        );

        // (d) the consistency rule of §A-3 / A-1.12: only T requires a Clinic;
        // NULL is meaningful for S/W *because that type is registered as such*.
        self::assertTrue(JobScopeRegistry::requiresClinicContext('sms.send'), 'sms.send is tenant-scoped (T)');
        self::assertTrue(JobScopeRegistry::requiresClinicContext('report.export'), 'report.export is tenant-scoped (T)');
        self::assertFalse(JobScopeRegistry::permitsNullClinic('sms.send'), 'T must NOT permit a NULL clinic');
        self::assertFalse(JobScopeRegistry::permitsNullClinic('report.export'), 'T must NOT permit a NULL clinic');
        self::assertTrue(JobScopeRegistry::permitsNullClinic('cleanup.otp'), 'registered S permits NULL by explicit registration');
        self::assertTrue(JobScopeRegistry::permitsNullClinic('holds.expire'), 'registered W permits NULL by explicit registration');

        // (e) FAIL-CLOSED: an unknown / unclassified type is rejected, never
        // guessed as "system" and never mapped to a Clinic.
        $thrown = null;
        try {
            JobScopeRegistry::classFor('cpms.does.not.exist');
        } catch (JobScopeUnknownException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(
            JobScopeUnknownException::class,
            $thrown,
            'RT-12: an unclassified job type must be rejected (A-1.10), never guessed'
        );
        self::assertSame('JOB_SCOPE_UNCLASSIFIED', $thrown->errorCode, 'RT-12: explicit fail-closed error code');
        self::assertNull(JobScopeRegistry::tryClassFor('cpms.does.not.exist'), 'RT-12: no classification is invented for an unknown type');

        // (f) the dispatcher enforces the same contract at REGISTRATION time, so
        // a future handler cannot be registered without a scope class.
        $registerThrown = null;
        try {
            App::dispatcher()->register('cpms.never.registered', static function (array $payload): void {});
        } catch (JobScopeUnknownException $e) {
            $registerThrown = $e;
        }
        self::assertInstanceOf(
            JobScopeUnknownException::class,
            $registerThrown,
            'RT-12: JobsDispatcher::register() must reject a job type without a registered scope class'
        );
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
     * READ-ONLY enumeration of the production job registry through its PUBLIC
     * contract. Slice 1B added `JobsDispatcher::registeredTypes()` precisely so
     * this no longer needs Reflection into a private property. The canonical
     * design names `App::dispatcher()` + `RECURRING_JOBS` as the source of truth
     * (§A-3) — explicitly NOT `docs/architecture/background-jobs.md`.
     *
     * @return list<string>
     */
    private static function registeredJobTypesFromProduction(): array
    {
        return App::dispatcher()->registeredTypes();
    }

    /**
     * @return list<string>
     */
    private static function recurringJobTypesFromProduction(): array
    {
        $types = array_map('strval', array_keys(App::RECURRING_JOBS));
        sort($types);

        return array_values($types);
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
