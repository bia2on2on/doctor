<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\FacilitySetupPage;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Location\LocationService;
use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Genuine two-connection concurrency for the Organization facility setup.
 *
 * A coordinator (this process, its own MySQL connection) acts as manager A: it
 * opens a transaction, takes the Organization row lock and is in the middle of
 * its own step. Manager B is an independent forked worker with a NEW MySQL
 * connection running the real product step (add_clinic / add_room /
 * assign_clinician). The test proves — from InnoDB's own lock tables, not by
 * timing — that B is in LOCK WAIT on the Organization PRIMARY record held by the
 * coordinator's connection, lets A finish and commit, and then asserts the
 * serialized outcome: no duplicate Clinic, no duplicate or orphan Location, no
 * second profile, no lost Location assignment, and retry recovery after a lost
 * response (same request key) adopts instead of duplicating.
 *
 * Fixtures are COMMITTED (independent connections only see committed rows);
 * cleanup is manual in tearDown. The coordinator uses a raw START TRANSACTION
 * (the integration bootstrap turns CpmsDb transactions into savepoints of the
 * test transaction), and a worker ends with SIGKILL so PHP's shutdown never sends
 * COM_QUIT over the MySQL socket it inherited from this process.
 */
final class FacilitySetupConcurrencyTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';
    private const READY_TIMEOUT_SEC = 15.0;
    private const LOCK_WAIT_TIMEOUT_SEC = 10.0;

    private int $org = 0;
    private int $actor = 0;
    private int $auditBaseline = 0;
    private string $fileTag = '';

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(function_exists('pcntl_fork'), 'precondition: CI must provide pcntl for real independent workers');
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        $this->fileTag = 'fs-race-' . bin2hex(random_bytes(6));
        $this->auditBaseline = (int) App::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_audit_logs'));

        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        App::db()->insert('cpms_organizations', [
            'name' => 'FS Race ' . $unique,
            'slug' => 'fs-race-' . $unique,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->org = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $this->org, 'precondition: organization inserted');
        $this->actor = $this->makeUser('fs_race_mgr');
        App::db()->insert('cpms_organization_memberships', [
            'organization_id' => $this->org,
            'wp_user_id' => $this->actor,
            'role_key' => 'organization_manager',
            'status' => 'active',
            'created_by_wp_user_id' => $this->actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertGreaterThan(0, App::db()->wpdb_last_insert_id(), 'precondition: organization manager membership');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        ScopeContext::clear();
        App::resetScope();
        $this->purgeOrganization($this->org);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->users as $userId) {
            wp_delete_user($userId);
        }
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach (glob(sys_get_temp_dir() . '/' . $this->fileTag . '-*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testConcurrentManagersSerializeOnTheOrganizationLockWithoutDuplicatesOrOrphans(): void
    {
        $service = FacilitySetupPage::service();
        $service->start($this->actor, $this->org, 'other', 'medical_building', 'مجتمع هم‌زمانی');
        $service->add_site($this->actor, $this->org, 'ساختمان هم‌زمانی', 'شیراز، خیابان آزمون');
        $site = (string) $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $this->commitFixture();
        $provisioned = $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [OrganizationClinicProvisioningService::AUDIT_ACTION]);

        // 1) Same Clinic name, different request keys: B must wait, then refuse the duplicate.
        $keyA = FacilitySetupService::new_request_key();
        $race = $this->raceAgainstWorker(
            ['task' => 'clinic', 'request_key' => FacilitySetupService::new_request_key(), 'name' => 'درمانگاه رقابتی', 'site_key' => $site],
            fn (): array => $service->add_clinic($this->actor, $this->org, $keyA, 'درمانگاه رقابتی', $site, self::TZ)
        );
        $this->assertGenuineLockWait($race, 'clinic');
        self::assertSame('refused', $race['outcome']['result'] ?? '', 'B serialized behind A and refused; outcome=' . wp_json_encode($race['outcome']));
        self::assertSame('duplicate_clinic', $race['outcome']['reason'] ?? '', 'B re-validated the name against the plan A committed');
        $clinicA = (array) $race['parent'];
        self::assertSame(1, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics') . ' WHERE organization_id = %d AND name = %s', [$this->org, 'درمانگاه رقابتی']), 'exactly one Clinic with the contested name');
        self::assertSame(1, $this->planCount(static fn (array $plan): int => count(array_filter($plan['clinics'], static fn (array $c): bool => 'درمانگاه رقابتی' === (string) $c['name']))), 'exactly one plan entry');
        self::assertSame('suspended', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d FOR UPDATE', [(int) $clinicA['clinic_id']]), 'held until activation');
        self::assertSame($provisioned + 1, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [OrganizationClinicProvisioningService::AUDIT_ACTION]), 'B left no provisioning audit row');
        self::assertSame(1, $this->orgLocationCount(), 'only A\'s primary Location exists: B left no orphan Location');

        // 2) Lost response: B retries A's request key while A still holds the lock -> adopts, never duplicates.
        $keyRetry = FacilitySetupService::new_request_key();
        $race = $this->raceAgainstWorker(
            ['task' => 'clinic', 'request_key' => $keyRetry, 'name' => 'درمانگاه ارسال دوباره', 'site_key' => $site],
            fn (): array => $service->add_clinic($this->actor, $this->org, $keyRetry, 'درمانگاه ارسال دوباره', $site, self::TZ)
        );
        $this->assertGenuineLockWait($race, 'retry');
        self::assertSame('ok', $race['outcome']['result'] ?? '', 'the retry recovered; outcome=' . wp_json_encode($race['outcome']));
        self::assertSame((int) $race['parent']['clinic_id'], (int) ($race['outcome']['id'] ?? 0), 'the retry adopted A\'s Clinic');
        self::assertSame(1, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics') . ' WHERE organization_id = %d AND name = %s', [$this->org, 'درمانگاه ارسال دوباره']));
        self::assertSame(2, $this->orgLocationCount(), 'one primary Location per Clinic, nothing orphaned');

        // 3) Same room name in one Clinic.
        $clinicB = (array) $race['parent'];
        $service->designate_manager($this->actor, $this->org, (string) $clinicA['key'], $this->actor);
        $service->designate_manager($this->actor, $this->org, (string) $clinicB['key'], $this->actor);
        $this->commitFixture();
        $roomKeyA = FacilitySetupService::new_request_key();
        $race = $this->raceAgainstWorker(
            ['task' => 'room', 'clinic_key' => (string) $clinicA['key'], 'request_key' => FacilitySetupService::new_request_key(), 'name' => 'اتاق رقابتی', 'site_key' => $site],
            fn (): array => $service->add_room($this->actor, $this->org, (string) $clinicA['key'], $roomKeyA, 'اتاق رقابتی', $site)
        );
        $this->assertGenuineLockWait($race, 'room');
        self::assertSame('refused', $race['outcome']['result'] ?? '', 'outcome=' . wp_json_encode($race['outcome']));
        self::assertSame('duplicate_room', $race['outcome']['reason'] ?? '');
        self::assertSame(1, $this->lockedCount(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' l JOIN ' . App::db()->table('cpms_clinics') . ' c ON c.id = l.clinic_id WHERE c.organization_id = %d AND l.name = %s',
            [$this->org, 'اتاق رقابتی']
        ), 'exactly one room Location with the contested name');
        self::assertSame(3, $this->orgLocationCount(), 'B created no orphan Location');
        $roomA = (array) $race['parent'];

        // 4) One professional assigned to two Clinics at once: no second profile, no lost Location.
        $doctor = $this->makeUser('fs_race_doc');
        $this->commitFixture();
        $created = $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', ['CLINICIAN_CREATED']);
        $assigned = $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [FacilitySetupService::CLINICIAN_AUDIT_ACTION]);
        $race = $this->raceAgainstWorker(
            ['task' => 'clinician', 'clinic_key' => (string) $clinicB['key'], 'user' => (string) $doctor, 'name' => 'دکتر هم‌زمان', 'room_key' => 'primary'],
            fn (): array => $service->assign_clinician($this->actor, $this->org, (string) $clinicA['key'], $doctor, 'دکتر هم‌زمان', [(string) $roomA['key']])
        );
        $this->assertGenuineLockWait($race, 'clinician');
        self::assertSame('ok', $race['outcome']['result'] ?? '', 'B serialized behind A and completed; outcome=' . wp_json_encode($race['outcome']));
        self::assertSame(1, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d', [$doctor]), 'one professional profile');
        self::assertSame(2, $this->lockedCount(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d AND role_key = %s AND status = %s',
            [$doctor, RolesAndCapabilities::ROLE_DOCTOR, 'active']
        ), 'one explicit doctor membership per Clinic');
        $primaryB = (int) $clinicB['location_id'];
        $links = array_map('intval', array_column(App::db()->fetchAll(
            'SELECT cl.location_id FROM ' . App::db()->table('cpms_clinician_locations') . ' cl JOIN ' . App::db()->table('cpms_clinicians') . ' p ON p.id = cl.clinician_id WHERE p.wp_user_id = %d ORDER BY cl.location_id FOR UPDATE',
            [$doctor]
        ), 'location_id'));
        $expected = [(int) $roomA['location_id'], $primaryB];
        sort($expected);
        self::assertSame($expected, $links, 'neither Clinic\'s Location assignment was lost');
        self::assertSame($created + 1, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', ['CLINICIAN_CREATED']));
        self::assertSame($assigned + 2, $this->lockedCount('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [FacilitySetupService::CLINICIAN_AUDIT_ACTION]));
        self::assertTrue(App::audit()->verifyChain(100000)['ok'], 'audit hash chain valid after both connections wrote');
    }

    /**
     * Manager A (this connection) holds the Organization row lock inside its own
     * transaction; manager B (forked worker, new connection) runs the real step and
     * must be observed in InnoDB LOCK WAIT on that record before A proceeds.
     *
     * @param array<string, string> $task
     * @return array{outcome: array<string, mixed>, lock_wait: array<string, mixed>|null, still_waiting: bool, coordinator: int, parent: array<string, mixed>, error: string}
     */
    private function raceAgainstWorker(array $task, callable $parentStep): array
    {
        global $wpdb;
        $coordinator = (int) $wpdb->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $tag = sys_get_temp_dir() . '/' . $this->fileTag . '-' . $task['task'] . '-' . bin2hex(random_bytes(3));
        $ready = $tag . '-ready.json';
        $go = $tag . '-go';
        $outcomeFile = $tag . '-outcome.json';
        $pid = -1;
        $lockWait = null;
        $stillWaiting = false;
        $parent = [];
        $error = '';

        // A REAL transaction on this connection (manager A). CpmsDb transactions of
        // the parent step become savepoints inside it.
        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        try {
            $locked = App::db()->fetchRowForUpdate(
                'SELECT id FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d LIMIT 1',
                [$this->org]
            );
            if ($this->org !== (int) ($locked['id'] ?? 0)) {
                throw new \RuntimeException('coordinator could not lock the Organization row');
            }
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \RuntimeException('pcntl_fork failed');
            }
            if (0 === $pid) {
                $this->childWorker($task, $ready, $go, $outcomeFile);
            }
            $workerConnection = $this->waitForReady($ready);
            if ($workerConnection <= 0 || $workerConnection === $coordinator) {
                throw new \RuntimeException('worker connection not ready or not independent');
            }
            file_put_contents($go, 'go');
            $lockWait = $this->waitForLockWait($workerConnection, $coordinator);
            if (null === $lockWait) {
                throw new \RuntimeException('worker was not observed in InnoDB LOCK WAIT behind the coordinator');
            }
            // Manager A completes its own step while B is still blocked.
            $parent = $parentStep();
            $stillWaiting = null !== $this->waitForLockWait($workerConnection, $coordinator, 0.5);
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } finally {
            if (!is_file($go)) {
                file_put_contents($go, 'go');
            }
        }
        $outcome = ['result' => 'fatal', 'detail' => 'worker not started'];
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
            $raw = @file_get_contents($outcomeFile);
            $decoded = false === $raw ? null : json_decode($raw, true);
            $outcome = is_array($decoded) ? $decoded : ['result' => 'fatal', 'detail' => 'worker outcome missing'];
        }
        self::assertSame('', $error, 'coordinator step completed');

        return [
            'outcome' => $outcome,
            'lock_wait' => $lockWait,
            'still_waiting' => $stillWaiting,
            'coordinator' => $coordinator,
            'parent' => $parent,
            'error' => $error,
        ];
    }

    /** @param array<string, mixed> $race */
    private function assertGenuineLockWait(array $race, string $label): void
    {
        $wait = $race['lock_wait'];
        self::assertIsArray($wait, $label . ': a real InnoDB lock-wait edge is required');
        self::assertSame($race['coordinator'], (int) ($wait['blocking_thread'] ?? 0), $label . ': the coordinator connection is the blocker');
        self::assertStringContainsString('cpms_organizations', strtolower((string) ($wait['lock_table'] ?? '')), $label . ': the wait is on the Organization row');
        self::assertSame('PRIMARY', strtoupper((string) ($wait['lock_index'] ?? '')), $label . ': Organization primary-key record');
        self::assertSame('RECORD', strtoupper((string) ($wait['lock_type'] ?? '')), $label . ': a row-record lock');
        self::assertTrue($race['still_waiting'], $label . ': the worker was still waiting after manager A finished its own step (overlap, not a sequential run)');
    }

    /**
     * Forked worker: new connection, real FacilitySetupService on it. Never returns.
     *
     * @param array<string, string> $task
     */
    private function childWorker(array $task, string $readyFile, string $goFile, string $outcomeFile): void
    {
        try {
            global $wpdb;
            $workerDb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $workerDb->set_prefix($wpdb->prefix);
            $wpdb = $workerDb;
            remove_all_filters('query');
            $workerDb->has_connected = false;
            $workerDb->init_charset();
            $workerDb->check_connection();
            $workerDb->query('SET SESSION innodb_lock_wait_timeout = 30'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            wp_cache_flush();
            wp_set_current_user($this->actor);
            $connection = (int) $workerDb->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $service = $this->serviceOn(new CpmsDb($workerDb));
            file_put_contents($readyFile, wp_json_encode(['connection_id' => $connection]));
            $deadline = microtime(true) + 20.0;
            while (!is_file($goFile) && microtime(true) < $deadline) {
                usleep(1000);
            }
            if (!is_file($goFile)) {
                throw new \RuntimeException('worker barrier timed out');
            }
            $result = match ($task['task']) {
                'clinic' => (int) $service->add_clinic($this->actor, $this->org, $task['request_key'], $task['name'], $task['site_key'], self::TZ)['clinic_id'],
                'room' => (int) $service->add_room($this->actor, $this->org, $task['clinic_key'], $task['request_key'], $task['name'], $task['site_key'])['location_id'],
                'clinician' => (int) $service->assign_clinician($this->actor, $this->org, $task['clinic_key'], (int) $task['user'], $task['name'], [$task['room_key']])['clinician_id'],
                default => throw new \InvalidArgumentException('unknown task'),
            };
            file_put_contents($outcomeFile, wp_json_encode(['result' => 'ok', 'id' => $result]));
        } catch (FacilitySetupException $e) {
            file_put_contents($outcomeFile, wp_json_encode(['result' => 'refused', 'reason' => $e->reason()]));
        } catch (\Throwable $e) {
            file_put_contents($outcomeFile, wp_json_encode(['result' => 'error', 'class' => get_class($e), 'detail' => $e->getMessage()]));
        }
        // Never run PHP shutdown in the fork: destructors would send COM_QUIT over
        // the MySQL socket inherited from the parent and end the parent's session.
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), SIGKILL);
        }
        exit(0);
    }

    /** The production service graph, bound to one explicit connection. */
    private function serviceOn(CpmsDb $db): FacilitySetupService
    {
        $op = new OpLogger($db);
        $audit = new AuditLogger($db, $op);
        $memberships = new MembershipRepository($db);
        $locations = new LocationRepository($db);
        $authz = new AuthorizationService($memberships, new CustomRoleRepository($db));

        return new FacilitySetupService(
            $db,
            new OrganizationClinicProvisioningService($db, $audit),
            new LocationService($db, $locations, $authz, $audit),
            $locations,
            $authz,
            new MembershipService($db, $memberships),
            $memberships,
            new ClinicianRepository($db),
            new ScheduleService($db, new ScheduleRepository($db), $memberships, $locations, new JobQueue($db, $op), $audit, $op),
            new SettingsFactory($db, $audit),
            $audit
        );
    }

    private function waitForReady(string $readyFile): int
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SEC;
        do {
            $raw = @file_get_contents($readyFile);
            $decoded = false === $raw ? null : json_decode($raw, true);
            if (is_array($decoded) && (int) ($decoded['connection_id'] ?? 0) > 0) {
                return (int) $decoded['connection_id'];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return 0;
    }

    /**
     * InnoDB's own lock-wait edge: requesting thread = worker, blocking thread =
     * coordinator, on the Organization table (MySQL performance_schema or the
     * MariaDB information_schema equivalent).
     *
     * @return array<string, mixed>|null
     */
    private function waitForLockWait(int $worker, int $coordinator, float $timeout = self::LOCK_WAIT_TIMEOUT_SEC): ?array
    {
        global $wpdb;
        $isMariaDb = false !== stripos((string) $wpdb->get_var('SELECT VERSION()'), 'mariadb'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $isMariaDb
            ? 'SELECT requesting_trx.trx_mysql_thread_id AS requesting_thread, blocking_trx.trx_mysql_thread_id AS blocking_thread,
                      requested_lock.lock_table AS lock_table, requested_lock.lock_index AS lock_index,
                      requested_lock.lock_type AS lock_type, requested_lock.lock_mode AS lock_mode
                 FROM information_schema.innodb_lock_waits waits
                 JOIN information_schema.innodb_trx requesting_trx ON requesting_trx.trx_id = waits.requesting_trx_id
                 JOIN information_schema.innodb_trx blocking_trx ON blocking_trx.trx_id = waits.blocking_trx_id
                 JOIN information_schema.innodb_locks requested_lock ON requested_lock.lock_id = waits.requested_lock_id
                WHERE requesting_trx.trx_mysql_thread_id = %d'
            : 'SELECT requesting_thread.PROCESSLIST_ID AS requesting_thread, blocking_thread.PROCESSLIST_ID AS blocking_thread,
                      requested_lock.OBJECT_NAME AS lock_table, requested_lock.INDEX_NAME AS lock_index,
                      requested_lock.LOCK_TYPE AS lock_type, requested_lock.LOCK_MODE AS lock_mode
                 FROM performance_schema.data_lock_waits waits
                 JOIN performance_schema.data_locks requested_lock
                   ON requested_lock.ENGINE = waits.ENGINE AND requested_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.data_locks blocking_lock
                   ON blocking_lock.ENGINE = waits.ENGINE AND blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID
                 JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = requested_lock.THREAD_ID
                 JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = blocking_lock.THREAD_ID
                WHERE requesting_thread.PROCESSLIST_ID = %d';
        $deadline = microtime(true) + $timeout;
        do {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $worker), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach (is_array($rows) ? $rows : [] as $row) {
                if ((int) ($row['blocking_thread'] ?? 0) === $coordinator && str_contains(strtolower((string) ($row['lock_table'] ?? '')), 'cpms_organizations')) {
                    return $row;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function commitFixture(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Locking read: rows committed by the worker are outside this connection's snapshot. */
    private function lockedCount(string $sql, array $params): int
    {
        return count(App::db()->fetchAll(str_replace('SELECT COUNT(*)', 'SELECT 1 AS hit', $sql) . ' FOR UPDATE', $params));
    }

    private function orgLocationCount(): int
    {
        return $this->lockedCount(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' l JOIN ' . App::db()->table('cpms_clinics') . ' c ON c.id = l.clinic_id WHERE c.organization_id = %d',
            [$this->org]
        );
    }

    private function planCount(callable $count): int
    {
        $raw = App::db()->fetchValue(
            'SELECT option_value FROM ' . App::db()->wpdb()->options . ' WHERE option_name = %s FOR UPDATE',
            [FacilitySetupService::OPTION_PREFIX . $this->org]
        );
        $plan = null === $raw ? null : maybe_unserialize((string) $raw);

        return is_array($plan) ? (int) $count($plan) : -1;
    }

    private function makeUser(string $login): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@fs.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $this->users[] = $userId;

        return $userId;
    }

    private function purgeOrganization(int $org): void
    {
        global $wpdb;
        if ($org <= 0) {
            return;
        }
        $p = $wpdb->prefix;
        $clinics = 'SELECT id FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org;
        // This test's audit rows are the committed tail of the chain (FK to its Clinics).
        $wpdb->query('DELETE FROM ' . $p . 'cpms_audit_logs WHERE id > ' . (int) $this->auditBaseline); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_clinician_locations t JOIN ' . $p . 'cpms_locations l ON l.id = t.location_id JOIN ' . $p . 'cpms_clinics c ON c.id = l.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE mc FROM ' . $p . 'cpms_membership_capabilities mc JOIN ' . $p . 'cpms_clinic_memberships m ON m.id = mc.membership_id JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE ml FROM ' . $p . 'cpms_membership_locations ml JOIN ' . $p . 'cpms_clinic_memberships m ON m.id = ml.membership_id JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE m FROM ' . $p . 'cpms_clinic_memberships m JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_clinicians t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_settings t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_locations t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE c FROM ' . $p . 'cpms_organization_membership_capabilities c JOIN ' . $p . 'cpms_organization_memberships m ON m.id = c.membership_id WHERE m.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organization_memberships WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
        delete_option(FacilitySetupService::OPTION_PREFIX . $org);
    }
}
