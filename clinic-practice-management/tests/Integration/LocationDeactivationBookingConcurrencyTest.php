<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Location\LocationException;
use ClinicCore\Application\Location\LocationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use WP_UnitTestCase;

/**
 * Real two-connection serialization coverage for Location deactivation vs staff booking.
 *
 * The coordinator owns the Organization -> Clinic lifecycle prefix while two
 * independent workers reach the production LocationService and BookingService
 * paths. The test observes both worker connection IDs blocked by the coordinator
 * on the Organization primary-key record before releasing those locks, then
 * verifies either valid serial order, the post-deactivation denial, slot
 * capacity, historical appointment retention, and the global audit hash chain.
 *
 * Test audit rows are removed only as a verified global suffix (id > captured
 * baseline), never by Clinic-scoped deletion. If another writer has appended
 * anything outside this fixture after the baseline, cleanup fails closed and
 * leaves the referenced topology in place rather than rewriting the chain.
 */
final class LocationDeactivationBookingConcurrencyTest extends WP_UnitTestCase
{
    private const TZ = 'Asia/Tehran';
    private const READY_TIMEOUT_SEC = 12.0;
    private const LOCK_WAIT_TIMEOUT_SEC = 8.0;

    private int $organizationId = 0;
    private int $clinicId = 0;
    private int $primaryLocationId = 0;
    private int $targetLocationId = 0;
    private int $doctorUserId = 0;
    private int $clinicianId = 0;
    private int $managerUserId = 0;
    private int $secretaryUserId = 0;
    private int $patientId = 0;
    private int $auditBaselineId = 0;
    private int $auditBaselineCount = 0;
    private string $slotDate = '';
    private string $fileTag = '';

    /** @var list<array{id: int, time: string}> */
    private array $slots = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(function_exists('pcntl_fork'), 'precondition: CI must provide pcntl for real independent workers');

        App::migrations()->migrate();
        App::resetScope();
        ScopeContext::clear();
        Settings::flushCache();
        wp_set_current_user(0);

        $this->fileTag = 'loc-book-race-' . bin2hex(random_bytes(6));
        $this->auditBaselineId = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_audit_logs')
        );
        $this->auditBaselineCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
        );
        $baselineChain = App::audit()->verifyChain(100000);
        self::assertTrue($baselineChain['ok'], 'precondition: global audit chain is valid before this test');
        self::assertSame($this->auditBaselineCount, $baselineChain['checked'], 'precondition: baseline audit verification covers the full table');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'Location race Org ' . $unique,
            'location-race-org-' . $unique,
            'active',
            $now,
            $now
        ));
        $this->organizationId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->organizationId, 'precondition: dynamic Organization inserted (' . $wpdb->last_error . ')');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->organizationId,
            'Location race Clinic ' . $unique,
            'location-race-clinic-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicId, 'precondition: dynamic Clinic inserted (' . $wpdb->last_error . ')');

        $this->primaryLocationId = $this->insertLocation($unique, 'primary', 1);
        $this->targetLocationId = $this->insertLocation($unique, 'secondary', 0);
        $this->doctorUserId = $this->createUser('doctor', $unique);
        $this->clinicianId = $this->insertClinician($now);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->doctorUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR));

        $this->managerUserId = $this->createUser('manager', $unique);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerUserId, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER));
        $this->secretaryUserId = $this->createUser('secretary', $unique);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->secretaryUserId, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY));

        $this->insertPatient($unique, $now);
        $localDate = (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->modify('+3 days');
        $this->slotDate = $localDate->format('Y-m-d');
        foreach (['09:00:00', '10:00:00', '11:00:00'] as $time) {
            $this->slots[] = ['id' => $this->insertSlot($time, $now), 'time' => $time];
        }

        // Workers use independent connections and must see this complete fixture.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeVerifiedAuditSuffixAndFixture();
        } finally {
            ScopeContext::clear();
            App::resetScope();
            Settings::flushCache();
            wp_set_current_user(0);
            parent::tearDown();
        }
    }

    public function testLocationDeactivationSerializesWithBookingAndPreservesHistory(): void
    {
        self::assertGreaterThan(1, $this->clinicId, 'precondition: non-legacy Clinic');
        self::assertNotSame($this->primaryLocationId, $this->targetLocationId, 'precondition: distinct primary and deactivation-target Locations');
        self::assertTrue(
            App::authorization_service()->can($this->managerUserId, $this->clinicId, RolesAndCapabilities::CONFIG),
            'precondition: manager has Clinic CONFIG authorization'
        );
        self::assertTrue(
            App::authorization_service()->can($this->secretaryUserId, $this->clinicId, RolesAndCapabilities::APPT_CREATE),
            'precondition: secretary has Clinic appointment-create authorization'
        );
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d AND clinic_id = %d',
            [$this->targetLocationId, $this->clinicId]
        ), 'precondition: secondary Location is active');

        $auditBefore = App::audit()->verifyChain(100000);
        self::assertTrue($auditBefore['ok'], 'precondition: audit chain remains valid before service mutations');

        // Positive control: a real fresh-connection service worker books while the Location is active.
        $control = $this->runSingleWorker('book_control');
        self::assertSame('ok', $control['result'] ?? '', 'positive control: active Location booking succeeds; outcome=' . wp_json_encode($control));
        $controlAppointmentId = (int) ($control['appointment_id'] ?? 0);
        self::assertGreaterThan(0, $controlAppointmentId, 'positive control: active Location booking created a durable appointment');

        // Strict overlap: hold Organization -> Clinic until both independent product
        // workers are observed waiting on the lifecycle prefix, then release and assert
        // the serializable outcome.
        $race = $this->runLockedRace();
        self::assertSame('', $race['coordination_error'], 'race harness completed the controlled lock handoff');
        self::assertSame(2, count($race['lock_waits']), 'race evidence: both independent workers waited on the coordinator Organization -> Clinic lifecycle prefix; evidence=' . wp_json_encode($race['lock_waits']));
        foreach ($race['lock_waits'] as $lockWait) {
            self::assertSame(
                (int) $race['coordinator_connection_id'],
                (int) ($lockWait['blocking_thread'] ?? 0),
                'race evidence identifies the coordinator as the blocking connection'
            );
            self::assertStringContainsString(
                'cpms_organizations',
                (string) ($lockWait['lock_table'] ?? ''),
                'race evidence identifies the Organization lock as the blocker'
            );
            self::assertSame('PRIMARY', strtoupper((string) ($lockWait['lock_index'] ?? '')), 'the wait is on the Organization primary-key record');
            self::assertSame('RECORD', strtoupper((string) ($lockWait['lock_type'] ?? '')), 'the wait is a row-record lock, not a table-level inference');
        }

        $deactivation = $race['outcomes']['deactivate'] ?? [];
        self::assertSame('ok', $deactivation['result'] ?? '', 'LocationService deactivation succeeds after lock handoff; outcome=' . wp_json_encode($deactivation));
        self::assertSame(0, (int) ($deactivation['is_active'] ?? -1), 'deactivation worker reports inactive Location');

        $raceBooking = $race['outcomes']['book'] ?? [];
        $raceBookingResult = (string) ($raceBooking['result'] ?? '');
        self::assertTrue(
            $raceBookingResult === 'ok'
                || ($raceBookingResult === 'error' && ($raceBooking['code'] ?? '') === 'CLINIC_NOT_FOUND' && (int) ($raceBooking['http'] ?? 0) === 404),
            'race booking must either serialize before deactivation or fail closed after it; outcome=' . wp_json_encode($raceBooking)
        );
        $raceAppointmentId = $raceBookingResult === 'ok' ? (int) ($raceBooking['appointment_id'] ?? 0) : 0;
        if ($raceBookingResult === 'ok') {
            self::assertGreaterThan(0, $raceAppointmentId, 'successful race booking returns a real appointment id');
        }

        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d AND clinic_id = %d',
            [$this->targetLocationId, $this->clinicId]
        ), 'serialized deactivation is durable');

        // Negative control after deactivation runs on a fresh service connection,
        // not inside the WordPress test suite's outer transaction/savepoint shim.
        $appointmentsBeforeRejectedRequest = $this->appointmentsForFixturePatient();
        $postDeactivation = $this->runSingleWorker('book_after_deactivation');
        self::assertSame('error', $postDeactivation['result'] ?? '', 'negative control: inactive Location booking is rejected; outcome=' . wp_json_encode($postDeactivation));
        self::assertSame('CLINIC_NOT_FOUND', $postDeactivation['code'] ?? '', 'negative control: inactive Location uses the established fail-closed result');
        self::assertSame(404, (int) ($postDeactivation['http'] ?? 0), 'negative control: inactive Location does not disclose its existence');
        self::assertSame($appointmentsBeforeRejectedRequest, $this->appointmentsForFixturePatient(), 'negative control: rejected booking creates no appointment');

        $slotRows = App::db()->fetchAll(
            'SELECT id, location_id, booked_count FROM ' . App::db()->table('cpms_schedule_slots')
            . ' WHERE id IN (%d, %d, %d) ORDER BY id',
            array_column($this->slots, 'id')
        );
        self::assertCount(3, $slotRows, 'all historical/materialized slots remain after Location deactivation');
        $slotById = [];
        foreach ($slotRows as $row) {
            $slotById[(int) $row['id']] = $row;
            self::assertSame($this->targetLocationId, (int) $row['location_id'], 'persisted slot keeps its original Location identity');
        }
        self::assertSame(1, (int) $slotById[$this->slots[0]['id']]['booked_count'], 'positive-control appointment retains its capacity claim');
        self::assertSame(
            $raceBookingResult === 'ok' ? 1 : 0,
            (int) $slotById[$this->slots[1]['id']]['booked_count'],
            'race capacity is claimed if and only if the booking serialized before deactivation'
        );
        self::assertSame(0, (int) $slotById[$this->slots[2]['id']]['booked_count'], 'rejected post-deactivation attempt leaves capacity untouched');

        $appointments = $this->appointmentsForFixturePatient();
        self::assertCount(1 + ($raceBookingResult === 'ok' ? 1 : 0), $appointments, 'only the positive control and optional pre-deactivation race booking persist');
        $controlRows = array_values(array_filter($appointments, fn (array $row): bool => (int) $row['id'] === $controlAppointmentId));
        self::assertCount(1, $controlRows, 'historical positive-control appointment remains after deactivation');
        self::assertSame($this->slots[0]['id'], (int) $controlRows[0]['slot_id'], 'historical appointment retains its original slot');
        self::assertSame('confirmed', (string) $controlRows[0]['status'], 'historical appointment remains confirmed');
        if ($raceBookingResult === 'ok') {
            self::assertContains(
                $raceAppointmentId,
                array_map(static fn (array $row): int => (int) $row['id'], $appointments),
                'race appointment remains durable when booking won the serialization order'
            );
        }

        $auditAfter = App::audit()->verifyChain(100000);
        self::assertTrue($auditAfter['ok'], 'audit chain remains valid after concurrent booking/deactivation writes');
        self::assertGreaterThan($this->auditBaselineCount, $auditAfter['checked'], 'race generated append-only audit evidence');
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
                . ' WHERE clinic_id = %d AND action = %s AND resource_id = %d',
                [$this->clinicId, 'LOCATION_DEACTIVATED', $this->targetLocationId]
            ),
            'exactly one durable lifecycle audit event records the deactivation'
        );
    }

    /**
     * @return array{booking: BookingService, location: LocationService}
     */
    private function services(CpmsDb $db, int $clinicId): array
    {
        $op = new OpLogger($db);
        $audit = new AuditLogger($db, $op);
        $settingsFactory = new SettingsFactory($db, $audit);
        $licenseGate = new ActiveLicenseGate();
        $jobs = new JobQueue($db, $op);
        $sms = new SmsService(
            $db,
            $settingsFactory,
            new SmsProviderRegistry(),
            new CredentialVault(),
            $audit,
            $op,
            $jobs,
            static fn (): int => $clinicId,
            $licenseGate
        );
        $memberships = new MembershipRepository($db);
        $booking = new BookingService(
            $db,
            new SlotRepository($db),
            new AppointmentRepository($db),
            new PatientRepository($db),
            $settingsFactory,
            $licenseGate,
            $audit,
            $op,
            new Idempotency($db),
            $sms,
            null,
            $memberships,
            PatientService::default_identity_activator($db)
        );
        $location = new LocationService(
            $db,
            new LocationRepository($db),
            new AuthorizationService($memberships),
            $audit
        );

        return ['booking' => $booking, 'location' => $location];
    }

    /** Run one real product worker on a fresh independent connection. */
    private function runSingleWorker(string $kind): array
    {
        $goFile = sys_get_temp_dir() . '/' . $this->fileTag . '-' . $kind . '-go';
        $readyFile = sys_get_temp_dir() . '/' . $this->fileTag . '-' . $kind . '-ready.json';
        $outcomeFile = sys_get_temp_dir() . '/' . $this->fileTag . '-' . $kind . '-outcome.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            return ['result' => 'fatal', 'detail' => 'pcntl_fork failed'];
        }
        if ($pid === 0) {
            $this->childWorker($kind, $readyFile, $goFile, $outcomeFile);
        }

        $spec = [$kind => ['ready' => $readyFile, 'outcome' => $outcomeFile]];
        $ready = $this->waitForWorkersReady($spec);
        file_put_contents($goFile, 'release');
        pcntl_waitpid($pid, $status);
        $raw = @file_get_contents($outcomeFile);
        $decoded = $raw === false ? null : json_decode($raw, true);
        @unlink($readyFile);
        @unlink($outcomeFile);
        @unlink($goFile);

        if ($ready === null || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            return ['result' => 'fatal', 'detail' => 'worker did not become ready or exit cleanly'];
        }

        return is_array($decoded) ? $decoded : ['result' => 'fatal', 'detail' => 'worker outcome file missing or invalid'];
    }

    /**
     * Hold the Organization -> Clinic prefix until both service workers are
     * confirmed waiting on the coordinator's exact Organization record lock.
     *
     * @return array{outcomes: array<string, array<string, mixed>>, lock_waits: list<array<string, mixed>>, coordination_error: string, coordinator_connection_id: int}
     */
    private function runLockedRace(): array
    {
        global $wpdb;
        $coordinatorConnectionId = (int) $wpdb->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $goFile = sys_get_temp_dir() . '/' . $this->fileTag . '-go';
        $workerSpecs = [
            'deactivate' => [
                'ready' => sys_get_temp_dir() . '/' . $this->fileTag . '-deactivate-ready.json',
                'outcome' => sys_get_temp_dir() . '/' . $this->fileTag . '-deactivate-outcome.json',
            ],
            'book' => [
                'ready' => sys_get_temp_dir() . '/' . $this->fileTag . '-book-ready.json',
                'outcome' => sys_get_temp_dir() . '/' . $this->fileTag . '-book-outcome.json',
            ],
        ];
        $pids = [];
        $coordinationError = '';
        $lockWaitRows = [];

        $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->query('START TRANSACTION') === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return [
                'outcomes' => [],
                'lock_waits' => [],
                'coordination_error' => 'coordinator START TRANSACTION failed',
                'coordinator_connection_id' => $coordinatorConnectionId,
            ];
        }
        try {
            $organization = App::db()->fetchRowForUpdate(
                'SELECT id FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d LIMIT 1',
                [$this->organizationId]
            );
            $clinic = $organization === null ? null : App::db()->fetchRowForUpdate(
                'SELECT id, organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
                [$this->clinicId]
            );
            if (
                (int) ( $organization['id'] ?? 0 ) !== $this->organizationId
                || (int) ( $clinic['id'] ?? 0 ) !== $this->clinicId
                || (int) ( $clinic['organization_id'] ?? 0 ) !== $this->organizationId
            ) {
                $coordinationError = 'coordinator could not lock the fixture Organization -> Clinic prefix';
            } else {
                foreach ($workerSpecs as $kind => $paths) {
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        $coordinationError = 'pcntl_fork failed for ' . $kind;
                        break;
                    }
                    if ($pid === 0) {
                        $this->childWorker($kind, $paths['ready'], $goFile, $paths['outcome']);
                    }
                    $pids[$kind] = $pid;
                }

                if (count($pids) === count($workerSpecs)) {
                    $ready = $this->waitForWorkersReady($workerSpecs);
                    if ($ready === null) {
                        $coordinationError = 'worker connections did not both become ready before timeout';
                    } else {
                        file_put_contents($goFile, 'release');
                        $lockWaitRows = $this->waitForBothLockWaits(
                            array_column($ready, 'connection_id'),
                            $coordinatorConnectionId
                        );
                        if (count($lockWaitRows) !== 2) {
                            $coordinationError = 'did not observe both worker connections in InnoDB LOCK WAIT';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $coordinationError = get_class($e) . ': ' . $e->getMessage();
        } finally {
            // Never strand a child behind the filesystem barrier or Clinic lock.
            if (!is_file($goFile)) {
                file_put_contents($goFile, 'release');
            }
            if ($wpdb->query('COMMIT') === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $coordinationError = trim($coordinationError . '; coordinator COMMIT failed');
            }
        }

        $outcomes = [];
        foreach ($workerSpecs as $kind => $paths) {
            if (isset($pids[$kind])) {
                pcntl_waitpid($pids[$kind], $status);
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $outcomes[$kind] = ['result' => 'fatal', 'detail' => 'worker process did not exit cleanly'];
                    continue;
                }
            }
            $raw = @file_get_contents($paths['outcome']);
            $decoded = $raw === false ? null : json_decode($raw, true);
            $outcomes[$kind] = is_array($decoded)
                ? $decoded
                : ['result' => 'fatal', 'detail' => 'worker outcome file missing or invalid'];
        }

        foreach ($workerSpecs as $paths) {
            @unlink($paths['ready']);
            @unlink($paths['outcome']);
        }
        @unlink($goFile);

        return [
            'outcomes' => $outcomes,
            'lock_waits' => $lockWaitRows,
            'coordination_error' => $coordinationError,
            'coordinator_connection_id' => $coordinatorConnectionId,
        ];
    }

    /** Worker never returns to the copied PHPUnit runner; it always exits. */
    private function childWorker(string $kind, string $readyFile, string $goFile, string $outcomeFile): void
    {
        try {
            global $wpdb;
            $workerDb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            if (!method_exists($workerDb, 'set_prefix')) {
                throw new \RuntimeException('wpdb::set_prefix is unavailable');
            }
            $workerDb->set_prefix($wpdb->prefix);
            $wpdb = $workerDb;
            remove_all_filters('query');
            $workerDb->has_connected = false;
            $workerDb->init_charset();
            $workerDb->check_connection();
            $workerDb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $workerDb->query('SET SESSION innodb_lock_wait_timeout = 30'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $connectionId = (int) $workerDb->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $db = new CpmsDb($workerDb);
            ScopeContext::set(ClinicScope::forClinic($this->clinicId));
            $services = $this->services($db, $this->clinicId);
            file_put_contents($readyFile, wp_json_encode(['connection_id' => $connectionId]));

            $barrierDeadline = microtime(true) + 20.0;
            while (!is_file($goFile) && microtime(true) < $barrierDeadline) {
                usleep(1000);
            }
            if (!is_file($goFile)) {
                throw new \RuntimeException('worker barrier timed out');
            }

            if ($kind === 'deactivate') {
                $row = $services['location']->set_active(
                    $this->managerUserId,
                    $this->clinicId,
                    $this->targetLocationId,
                    false
                );
                $outcome = ['result' => 'ok', 'is_active' => (int) ($row['is_active'] ?? -1)];
            } else {
                $slotIndex = match ($kind) {
                    'book_control' => 0,
                    'book' => 1,
                    'book_after_deactivation' => 2,
                    default => throw new \InvalidArgumentException('unknown worker kind'),
                };
                $booking = $services['booking']->createByStaff(
                    $this->secretaryUserId,
                    $this->patientId,
                    $this->clinicianId,
                    $this->slotDate,
                    $this->slots[$slotIndex]['time'],
                    $kind === 'book_control' ? 'active-location control' : 'location lifecycle race',
                    $this->slots[$slotIndex]['id']
                );
                $outcome = [
                    'result' => 'ok',
                    'appointment_id' => (int) ($booking['appointment_id'] ?? 0),
                ];
            }
            file_put_contents($outcomeFile, wp_json_encode($outcome));
        } catch (BookingException $e) {
            file_put_contents($outcomeFile, wp_json_encode([
                'result' => 'error',
                'code' => $e->errorCode,
                'http' => $e->httpStatus,
            ]));
        } catch (LocationException $e) {
            file_put_contents($outcomeFile, wp_json_encode([
                'result' => 'error',
                'code' => $e->getErrorCode(),
                'http' => $e->getHttpStatus(),
            ]));
        } catch (\Throwable $e) {
            file_put_contents($outcomeFile, wp_json_encode([
                'result' => 'fatal',
                'class' => get_class($e),
                'detail' => $e->getMessage(),
            ]));
        }
        exit(0);
    }

    /** @param array<string, array{ready: string, outcome: string}> $workerSpecs */
    private function waitForWorkersReady(array $workerSpecs): ?array
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SEC;
        do {
            $ready = [];
            foreach ($workerSpecs as $kind => $paths) {
                $raw = @file_get_contents($paths['ready']);
                $decoded = $raw === false ? null : json_decode($raw, true);
                if (!is_array($decoded) || (int) ($decoded['connection_id'] ?? 0) <= 0) {
                    continue;
                }
                $ready[$kind] = ['connection_id' => (int) $decoded['connection_id']];
            }
            if (count($ready) === count($workerSpecs)) {
                return $ready;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @param list<int> $connectionIds
     * @return list<array<string, mixed>>
     */
    private function waitForBothLockWaits(array $connectionIds, int $coordinatorConnectionId): array
    {
        global $wpdb;
        $deadline = microtime(true) + self::LOCK_WAIT_TIMEOUT_SEC;
        $lastRows = [];
        $placeholders = implode(', ', array_fill(0, count($connectionIds), '%d'));
        $version = (string) $wpdb->get_var('SELECT VERSION()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $isMariaDb = stripos($version, 'mariadb') !== false;
        $sql = $isMariaDb
            ? 'SELECT requesting_trx.trx_mysql_thread_id AS requesting_thread,
                      blocking_trx.trx_mysql_thread_id AS blocking_thread,
                      requested_lock.lock_table AS lock_table,
                      requested_lock.lock_index AS lock_index,
                      requested_lock.lock_type AS lock_type,
                      requested_lock.lock_mode AS lock_mode
                 FROM information_schema.innodb_lock_waits waits
                 JOIN information_schema.innodb_trx requesting_trx ON requesting_trx.trx_id = waits.requesting_trx_id
                 JOIN information_schema.innodb_trx blocking_trx ON blocking_trx.trx_id = waits.blocking_trx_id
                 JOIN information_schema.innodb_locks requested_lock ON requested_lock.lock_id = waits.requested_lock_id
                WHERE requesting_trx.trx_mysql_thread_id IN (' . $placeholders . ')'
            : 'SELECT requesting_thread.PROCESSLIST_ID AS requesting_thread,
                      blocking_thread.PROCESSLIST_ID AS blocking_thread,
                      requested_lock.OBJECT_NAME AS lock_table,
                      requested_lock.INDEX_NAME AS lock_index,
                      requested_lock.LOCK_TYPE AS lock_type,
                      requested_lock.LOCK_MODE AS lock_mode
                 FROM performance_schema.data_lock_waits waits
                 JOIN performance_schema.data_locks requested_lock
                   ON requested_lock.ENGINE = waits.ENGINE
                  AND requested_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.data_locks blocking_lock
                   ON blocking_lock.ENGINE = waits.ENGINE
                  AND blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID
                 JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = requested_lock.THREAD_ID
                 JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = blocking_lock.THREAD_ID
                WHERE requesting_thread.PROCESSLIST_ID IN (' . $placeholders . ')';

        do {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$connectionIds), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $byRequester = [];
            if ('' === $wpdb->last_error && is_array($rows)) {
                foreach ($rows as $row) {
                    $requestingThread = (int) ($row['requesting_thread'] ?? 0);
                    $blockingThread = (int) ($row['blocking_thread'] ?? 0);
                    $lockTable = strtolower((string) ($row['lock_table'] ?? ''));
                    if (
                        in_array($requestingThread, $connectionIds, true)
                        && $blockingThread === $coordinatorConnectionId
                        && str_contains($lockTable, 'cpms_organizations')
                    ) {
                        $byRequester[$requestingThread] = $row;
                    }
                }
            }
            $lastRows = array_values($byRequester);
            if (count($lastRows) === count($connectionIds)) {
                return $lastRows;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return $lastRows;
    }

    private function insertLocation(string $unique, string $label, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            'Location race ' . $label . ' ' . $unique,
            'location-race-' . $label . '-' . $unique,
            self::TZ,
            $primary,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: Location inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function createUser(string $role, string $unique): int
    {
        $login = 'location_race_' . $role . '_' . $unique;
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@cpms.test');
        self::assertGreaterThan(0, $userId, 'precondition: WordPress ' . $role . ' user inserted');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $roleKey = match ($role) {
            'doctor' => RolesAndCapabilities::ROLE_DOCTOR,
            'manager' => RolesAndCapabilities::ROLE_MANAGER,
            'secretary' => RolesAndCapabilities::ROLE_SECRETARY,
            default => throw new \InvalidArgumentException('unknown fixture role'),
        };
        $user->set_role($roleKey);

        return $userId;
    }

    private function insertClinician(string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            'Location race clinician ' . $this->fileTag,
            $this->doctorUserId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertPatient(string $unique, string $now): void
    {
        global $wpdb;
        $mobile = '0912' . random_int(1000000, 9999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            'LR-' . strtoupper(substr($unique, 0, 12)),
            'Concurrency',
            'Fixture',
            $mobile,
            'active',
            $now,
            $now
        ));
        $this->patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->patientId, 'precondition: patient inserted (' . $wpdb->last_error . ')');
    }

    private function insertSlot(string $time, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min,
                  capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            $this->targetLocationId,
            $this->clinicianId,
            $this->slotDate,
            $time,
            'manual',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: slot inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function appointmentsForFixturePatient(): array
    {
        return App::db()->fetchAll(
            'SELECT id, clinic_id, patient_id, slot_id, status FROM ' . App::db()->table('cpms_appointments')
            . ' WHERE clinic_id = %d AND patient_id = %d ORDER BY id',
            [$this->clinicId, $this->patientId]
        );
    }

    /**
     * Remove only the isolated global audit suffix belonging to this fixture,
     * verify the remaining prefix, and then purge topology in FK-safe order.
     */
    private function purgeVerifiedAuditSuffixAndFixture(): void
    {
        if ($this->organizationId <= 0) {
            return;
        }

        global $wpdb;
        $auditTable = App::db()->table('cpms_audit_logs');
        $suffix = App::db()->fetchAll(
            'SELECT id, clinic_id FROM ' . $auditTable . ' WHERE id > %d ORDER BY id',
            [$this->auditBaselineId]
        );
        $globalMax = (int) App::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $auditTable);
        if ($suffix !== []) {
            self::assertGreaterThan(0, $this->clinicId, 'cleanup precondition: fixture Clinic exists for audit suffix');
            self::assertSame($globalMax, (int) $suffix[count($suffix) - 1]['id'], 'cleanup precondition: test audit rows are the global suffix');
            foreach ($suffix as $row) {
                self::assertSame($this->clinicId, (int) $row['clinic_id'], 'cleanup precondition: every audit suffix row belongs to this fixture Clinic');
            }
            $chainBeforeDelete = App::audit()->verifyChain(100000);
            self::assertTrue($chainBeforeDelete['ok'], 'cleanup precondition: append-only audit chain verifies before suffix cleanup');
            self::assertSame($this->auditBaselineCount + count($suffix), $chainBeforeDelete['checked'], 'cleanup precondition: chain verification covers baseline plus test suffix');

            $deleted = $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $auditTable . ' WHERE id > %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->auditBaselineId
            ));
            self::assertNotFalse($deleted, 'cleanup: deleting only the verified global test suffix succeeded (' . $wpdb->last_error . ')');
        }

        $remainingChain = App::audit()->verifyChain(100000);
        self::assertTrue($remainingChain['ok'], 'cleanup: preserved global audit prefix remains valid');
        self::assertSame($this->auditBaselineCount, $remainingChain['checked'], 'cleanup: only the pre-existing audit prefix remains');

        $clinic = (int) $this->clinicId;
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_appointments WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_exceptions WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinic_id = ' . $clinic);
        $this->checkedDelete(
            'DELETE ml FROM ' . $wpdb->prefix . 'cpms_membership_locations ml'
            . ' JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = ml.membership_id'
            . ' WHERE m.clinic_id = ' . $clinic
        );
        $this->checkedDelete(
            'DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc'
            . ' JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id'
            . ' WHERE m.clinic_id = ' . $clinic
        );
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id = ' . $clinic);
        $this->checkedDelete(
            'DELETE cl FROM ' . $wpdb->prefix . 'cpms_clinician_locations cl'
            . ' JOIN ' . $wpdb->prefix . 'cpms_locations l ON l.id = cl.location_id'
            . ' WHERE l.clinic_id = ' . $clinic
        );
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = ' . $clinic);
        $this->checkedDelete('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . (int) $this->organizationId);
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ([$this->doctorUserId, $this->managerUserId, $this->secretaryUserId] as $userId) {
            if ($userId > 0) {
                $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
                $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
            }
        }
    }

    private function checkedDelete(string $sql): void
    {
        global $wpdb;
        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertNotFalse($result, 'cleanup SQL succeeded: ' . $wpdb->last_error);
    }
}
