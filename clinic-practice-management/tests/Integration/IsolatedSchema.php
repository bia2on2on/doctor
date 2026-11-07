<?php

/**
 * IsolatedSchema — shared infrastructure for tests that need an ISOLATED,
 * DISPOSABLE database with real migrations (CI-only).
 *
 * Why: tests that fork independent connections and commit transactions cannot
 * run on the shared WP test database (the harness wraps everything in one
 * outer transaction per test, and the `query` filter rewrites CREATE/DROP
 * TABLE to TEMPORARY). Each test using this trait gets its own schema
 * `cpms_<slug>_<hex>`, migrated for real, dropped in tearDown's finally.
 *
 * Guarantees:
 *  - No shared data is ever read or modified (schema is per-test).
 *  - Cleanup is best-effort and NEVER outranks safety (DROP DATABASE, not
 *    table scans): tearDown performs a bounded DROP; a shutdown hook drops
 *    only schemas with NO unconfirmed forked child of this process (and no
 *    block marker) — otherwise the schema is RETAINED loudly (fail-closed);
 *    forked children never run shutdown cleanup at all. The per-process
 *    migration mirror directory is wiped at process end (retained, loudly, when
 *    forked children cannot be confirmed gone — see registerFatalCleanup()).
 *  - Production code runs unchanged; the WP test bootstrap rewrites CpmsDb's
 *    marked transaction-control statements to SAVEPOINTs (the marker exists
 *    so the rewriter knows to nest them in the outer transaction). Tests
 *    either open a real outer START TRANSACTION (savepoints nest in it) or
 *    let the implicit transaction of a bare connection be COMMITted by the
 *    caller (forked workers do the latter, exactly like production's real
 *    top-level transactions).
 *
 * Fixture visibility contract:
 *  - Fixture rows are written on the DISPOSABLE schema through its own
 *    connection(s) with autocommit — durable and visible to every other
 *    connection/process from the moment they exist.
 *  - WP-side fixtures (users via wp_create_user(), session tokens via
 *    WP_Session_Tokens) live on the HARNESS connection inside the harness's
 *    per-test outer transaction. They are visible to this process only and
 *    vanish with the rollback at tear_down(). Any independent process or
 *    connection must therefore authenticate through the documented
 *    session-reader seam (see stackOn(..., forChild: true)); it must never
 *    rely on WP_Session_Tokens or on reading the harness connection from
 *    elsewhere.
 *
 * Migration mechanics (why the mirror exists):
 *  - The production MigrationRunner `require()`s each migration file by
 *    design (migration files must return fresh arrays so rollback() re-runs
 *    in the same process see new arrays; plain require is contractual).
 *    src/Migrations/2026_09_05_0002_duration_and_buffers.php additionally
 *    declares a top-level named function, so a second plain `require` of it
 *    in the same process dies with an uncatchable "Cannot redeclare ..."
 *    fatal that also skips tearDown. A per-process shadow directory of
 *    one-line wrapper files is therefore used as MigrationRunner's
 *    constructor directory: each wrapper delegates to a namespaced static
 *    class cache (TestMigrationArrays) that compiles the real migration
 *    exactly once per process — no process global, no $GLOBALS entry, no
 *    file-scope static (those do not survive re-includes; measured). The
 *    migration code itself is loaded and executed unchanged; the runner,
 *    ledger semantics, order and transactionality are untouched. No
 *    product file is changed to accommodate the harness; the mirror
 *    directory lives in sys_get_temp_dir() with 0700 permissions, is wiped
 *    by a shutdown hook, and never enters the repository, the ZIP, or git.
 *  - Migration 2026_09_09_0012_memberships reads the WP `usermeta` table on
 *    the migration connection itself; a bare disposable schema has no such
 *    table and the migration died with "Table ... usermeta doesn't exist"
 *    (observed). The harness replicates an EMPTY, metadata-only skeleton
 *    (`CREATE TABLE ... LIKE`) into the disposable schema — no rows, no data
 *    — and the skeleton is dropped together with the schema.
 *
 * See HoldCapacityTwoConnectionTest / CancelZeroRowGuardTest for usage.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Migrations\MigrationRunner;
use ClinicCore\Settings\SettingsFactory;

require_once __DIR__ . '/RealTableMigrations.php';

trait IsolatedSchema
{
    private string $disposableDb = '';
    private ?\wpdb $fixtureWpdb = null;
    private string $fileTag = '';

    private int $organizationId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $clinicianId = 0;
    private int $patientAId = 0;
    private int $patientBId = 0;
    private int $userAId = 0;
    private int $userBId = 0;

    /** @var array<int, array<string, mixed>> */
    private array $capturedSessions = [];

    /** @var string|null */
    private static ?string $migrationMirror = null;

    /**
     * Schemas created but not yet dropped in this process. A shutdown hook
     * drops whatever is left (e.g. after a fatal that bypassed tearDown) so a
     * failing suite cannot leak databases.
     *
     * @var array<string, true>
     */
    private static array $openSchemas = [];

    /**
     * Fail-closed drop refusal: schemas that must NOT be dropped because a
     * forked child is known or potentially still active. Set via
     * blockDisposableSchemaDrop(); honored by dropDisposableSchema() and by
     * the shutdown-hook backstop. A retained, loudly-reported schema beats a
     * drop issued under a live, potentially mid-transaction process.
     *
     * @var array<string, string> schema => reason
     */
    private static array $dropBlockedSchemas = [];

    /**
     * PIDs of children forked by this process that have NOT been confirmed
     * dead (waitpid returned the pid, or errno ECHILD). Populated right after
     * each successful pcntl_fork() via noteForkedChild(); an entry is removed
     * ONLY by noteChildConfirmedReaped(). A non-empty map means "some fork
     * made by this process may still be active": dropDisposableSchema()
     * refuses the DROP and the shutdown backstop RETAINS open schemas,
     * loudly — independently of whether tearDown managed to set a block.
     * Process-local (copy-on-write across fork): the parent's registry is
     * never shared with children.
     *
     * @var array<int, int> pid => pid
     */
    private static array $pendingChildPids = [];

    /**
     * Count of fork sites currently "in flight": incremented BEFORE
     * pcntl_fork() and decremented only AFTER the parent has either
     * registered the child pid in $pendingChildPids or confirmed the fork
     * produced no parent-side child. Covers the bounded interval in which
     * the parent may hold an UNREGISTERED live child — including a fatal or
     * exit that lands between pcntl_fork() returning and noteForkedChild():
     * at shutdown, a nonzero value RETAINS every open schema and
     * dropDisposableSchema() refuses — fail-closed. A child that ends up
     * inheriting this nonzero value is already cleanup-disabled at birth, so
     * it additionally never wipes the mirror nor issues DROP. Any observer
     * treats nonzero as "child state cannot be established" and must refuse
     * cleanup.
     */
    private static int $pendingForkIntent = 0;

    /** Called immediately BEFORE every pcntl_fork() site. */
    public static function noteForkIntentBegin(): void
    {
        self::$pendingForkIntent++;
    }

    /**
     * Called after the fork site has done everything it can (child registered
     * or not). Never goes below zero.
     */
    public static function noteForkIntentResolved(): void
    {
        if (self::$pendingForkIntent > 0) {
            self::$pendingForkIntent--;
        }
    }

    /** @return int outstanding fork sites whose children are not yet accounted for */
    public static function pendingForkIntent(): int
    {
        return self::$pendingForkIntent;
    }

    /**
     * Set inside a FORKED CHILD at childExit() time: THIS process must not
     * run any shutdown cleanup (schema DROP or migration-mirror wipe). The
     * parent owns the disposable-schema lifecycle; an inherited shutdown hook
     * in a child could otherwise DROP the shared schema while the parent (or
     * a sibling) is still active.
     */
    private static bool $fatalCleanupDisabledInThisProcess = false;

    /** Called by the fork parent right after each successful pcntl_fork(). */
    public static function noteForkedChild(int $pid): void
    {
        if ($pid > 0) {
            self::$pendingChildPids[$pid] = $pid;
        }
    }

    /**
     * Called ONLY when the child is confirmed gone: waitpid returned its pid,
     * or failed with errno ECHILD (the child was already reaped in this
     * process). Any other outcome (still running, EINTR, error we cannot
     * interpret) keeps the entry — silence is never treated as death.
     */
    public static function noteChildConfirmedReaped(int $pid): void
    {
        unset(self::$pendingChildPids[$pid]);
    }

    /** @return array<int, int> child pids noted at fork time and not yet confirmed dead */
    public static function pendingChildPids(): array
    {
        return array_values(self::$pendingChildPids);
    }

    /**
     * Disable ALL shutdown cleanup in THIS process. Forked children call this
     * at childExit() time so the inherited shutdown hook is inert here, no
     * matter which way the child ends (SIGKILL, exit(0), or a failed kill).
     */
    public static function disableFatalCleanupForThisProcess(): void
    {
        self::$fatalCleanupDisabledInThisProcess = true;
    }

    /**
     * Create the disposable schema and skip the test loudly if it cannot be
     * created (the only precondition we can afford to skip; everything else
     * fails hard).
     */
    protected function createDisposableSchema(string $slug): void
    {
        global $wpdb;
        self::assertTrue(1 === preg_match('/^[a-z][a-z0-9]*$/', $slug), 'schema slug must be alphanumeric');
        $name = 'cpms_' . $slug . '_' . bin2hex(random_bytes(6));
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
        $created = $wpdb->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if (false === $created) {
            self::markTestSkipped('disposable database not creatable (privileges?): ' . ($wpdb->last_error ?: 'no error message'));
        }
        self::$openSchemas[$name] = true;
        $this->disposableDb = $name;
        $this->fileTag = $name;

        // Migration 2026_09_09_0012_memberships reads the WP `usermeta` table
        // on the migration connection; a bare disposable schema does not have
        // it and the migration dies with "usermeta doesn't exist" (observed).
        // Replicate an EMPTY, metadata-only skeleton — no rows, no data — on a
        // raw connection (the WP `query` filter would rewrite CREATE TABLE to
        // TEMPORARY on the harness connection). The skeleton is dropped
        // together with the schema.
        $prefix = (string) $wpdb->prefix;
        $source = isset($wpdb->dbname) ? (string) $wpdb->dbname : '';
        if ('' !== $source) {
            $raw = $this->rawMysqli(null);
            $ok = $raw->query('CREATE TABLE `' . $name . '`.`' . $prefix . 'usermeta` LIKE `' . $source . '`.`' . $prefix . 'usermeta`');
            $error = (string) $raw->error;
            $raw->close();
            if (false === $ok) {
                // Fail hard; leave nothing behind.
                $this->dropDisposableSchema();
                self::fail('disposable schema: could not replicate the empty usermeta skeleton: ' . $error);
            }
        }

        self::registerFatalCleanup();
    }

    /** Run the real plugin migrations inside the disposable schema. */
    protected function migrateDisposableSchema(): void
    {
        $this->fixtureWpdb = $this->newDisposableConnection();
        $fixtureDb = new CpmsDb($this->fixtureWpdb);
        $fixtureOp = new OpLogger($fixtureDb);
        $mirror = self::migrationMirrorDir();
        $this->withRealTables(static function () use ($fixtureDb, $fixtureOp, $mirror): void {
            $runner = new MigrationRunner($fixtureDb, $fixtureOp, $mirror);
            $runner->migrate();
        });
    }

    /** Create the WP users on the harness connection (rolled back by the harness). */
    protected function seedWpUsers(): void
    {
        $tag = $this->fileTag ?: bin2hex(random_bytes(5));
        $this->userAId = (int) wp_create_user('cp_a_' . $tag, 'pass-a-12345', $tag . '-a@test.local');
        $this->userBId = (int) wp_create_user('cp_b_' . $tag, 'pass-b-12345', $tag . '-b@test.local');
        self::assertGreaterThan(0, $this->userAId);
        self::assertGreaterThan(0, $this->userBId);
    }

    /** Organization -> Clinic -> Location -> Clinician graph (autocommit). */
    protected function seedOrganizationGraph(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $slug = 'ds-' . bin2hex(random_bytes(5));
        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_organizations'), [
            'name' => 'Disposable Schema Org', 'slug' => $slug . '-org', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->organizationId = (int) $this->fixtureWpdb->insert_id;

        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_clinics'), [
            'organization_id' => $this->organizationId, 'name' => 'Disposable Schema Clinic',
            'slug' => $slug, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->clinicId = (int) $this->fixtureWpdb->insert_id;

        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_locations'), [
            'clinic_id' => $this->clinicId, 'name' => 'Disposable Schema Location',
            'slug' => $slug . '-loc', 'timezone' => 'Asia/Tehran', 'is_primary' => 0, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->locationId = (int) $this->fixtureWpdb->insert_id;

        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_clinicians'), [
            'clinic_id' => $this->clinicId, 'full_name' => 'Dr Disposable', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->clinicianId = (int) $this->fixtureWpdb->insert_id;
    }

    /** Two patients + links; required by some flows, harmless otherwise. */
    protected function seedPatientsAndLinks(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->patientAId = $this->insertPatient('MR-DS-A-' . strtoupper(bin2hex(random_bytes(3))), $now);
        $this->patientBId = $this->insertPatient('MR-DS-B-' . strtoupper(bin2hex(random_bytes(3))), $now);
        $this->linkPatient($this->patientAId, $this->userAId, '09120000001', $now);
        $this->linkPatient($this->patientBId, $this->userBId, '09120000002', $now);
    }

    /**
     * Seed patient sessions for the given users and capture the session arrays
     * so forked workers can authenticate through the documented reader seam.
     */
    protected function seedSessions(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $token = cpms_test_seed_patient_session((int) $userId, $this->clinicId);
            $manager = \WP_Session_Tokens::get_instance((int) $userId);
            $this->capturedSessions[(int) $userId] = (array) $manager->get($token);
        }
    }

    /**
     * Total isolation, reliable cleanup: close the coordinator connection and
     * DROP the whole disposable schema regardless of what any test did. No
     * scanning, no per-table DELETE of anything shared.
     */
    /**
     * Mark this test's disposable schema as NOT safe to DROP (e.g. forked
     * children unconfirmed reaped). Honored by dropDisposableSchema() and by
     * the shutdown-hook backstop; both refuse and report loudly instead of
     * dropping under potentially active processes.
     */
    protected function blockDisposableSchemaDrop(string $reason): void
    {
        $name = $this->disposableDb;
        if ('' === $name) {
            return;
        }
        self::$dropBlockedSchemas[$name] = $reason;
        fwrite(STDERR, 'disposable schema cleanup: drop REFUSED for ' . $name . ' — ' . $reason . PHP_EOL);
    }

    protected function dropDisposableSchema(): void
    {
        if ($this->fixtureWpdb instanceof \wpdb) {
            $this->fixtureWpdb->close();
            $this->fixtureWpdb = null;
        }
        if ('' !== $this->disposableDb) {
            $schema = $this->disposableDb;
            // FAIL CLOSED (fork registry + fork intent): this process may
            // hold forks that were never confirmed dead, or a fork site may
            // be in flight (parent fatal/exit between pcntl_fork() and the
            // child registration). Neither resolves itself; refuse
            // unconditionally, regardless of whether tearDown ran.
            $pending = self::pendingChildPids();
            $intent = self::pendingForkIntent();
            if ($pending !== [] || $intent > 0) {
                $reasons = [];
                if ($pending !== []) {
                    $reasons[] = 'registered unconfirmed pids=' . implode(',', $pending);
                }
                if ($intent > 0) {
                    $reasons[] = 'fork intent in flight (intent=' . $intent . '): a fatal/exit before parent-side registration of a child cannot be ruled out';
                }
                $this->blockDisposableSchemaDrop('unconfirmed forked child process(es) may still be active; ' . implode('; ', $reasons));
            }
            if (isset(self::$dropBlockedSchemas[$schema])) {
                $reason = self::$dropBlockedSchemas[$schema];
                fwrite(STDERR, 'disposable schema cleanup: refusing DROP (fail-closed): ' . $schema . ' — ' . $reason . PHP_EOL);
                throw new \RuntimeException('disposable schema drop refused (fail-closed): ' . $schema . ' — ' . $reason);
            }
            // Dedicated fresh connection for the DDL (never the WP query filter,
            // never the harness transaction) with a bounded metadata-lock wait:
            // if a surviving forked child holds metadata locks on the schema,
            // the DROP fails LOUDLY within ~10s instead of hanging tearDown
            // indefinitely. A failure is an exception, never silent.
            $dropped = false;
            $error   = 'unknown error';
            try {
                $ddl = $this->rawMysqli(null);
                $ddl->query('SET SESSION lock_wait_timeout = 10');
                $dropped = (bool) $ddl->query('DROP DATABASE IF EXISTS `' . $schema . '`');
                $error   = (string) $ddl->error;
                $ddl->close();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            if (!$dropped) {
                // Loud failure: a leaked disposable schema must never pass silently.
                // Keep it registered in self::$openSchemas so the shutdown hook
                // retries the drop, and keep $this->disposableDb set so a second
                // drop attempt can retry. The exception fails the test loudly.
                fwrite(STDERR, 'disposable schema cleanup: DROP DATABASE failed: ' . $error . PHP_EOL);
                throw new \RuntimeException('disposable schema cleanup failed: DROP DATABASE `' . $schema . '` error: ' . $error);
            }
            unset(self::$openSchemas[$schema]);
            $this->disposableDb = '';
        }
    }

    protected function newDisposableConnection(): \wpdb
    {
        global $wpdb;
        $conn = new \wpdb(DB_USER, DB_PASSWORD, $this->disposableDb, DB_HOST);
        $conn->set_prefix($wpdb->prefix);
        $conn->show_errors(false);
        return $conn;
    }

    /**
     * Production service graph bound to one explicit connection.
     * $forChild=true builds everything a fork needs without touching the
     * parent's global singletons; false uses the production wiring
     * (App::licenseGate()/smsService()/patient_session_scope()).
     *
     * @return array{service: BookingService, db: CpmsDb}
     */
    protected function stackOn(\wpdb $conn, bool $forChild, ?int $childUserId = null, ?array $childSession = null): array
    {
        $db = new CpmsDb($conn);
        $op = new OpLogger($db);
        $audit = new AuditLogger($db, $op);
        $settingsFactory = new SettingsFactory($db, $audit);
        $idem = new Idempotency($db);

        if ($forChild) {
            $registry = new SmsProviderRegistry();
            $registry->register(new LogSmsProvider($op));
            $registry->register(new GenericApiSmsProvider(static fn (): array => []));
            $sms = new SmsService(
                $db,
                $settingsFactory,
                $registry,
                new CredentialVault(),
                $audit,
                $op,
                new JobQueue($db, $op),
                static fn (): int => 0
            );
            $license = new ActiveLicenseGate();
            $sessions = new PatientSessionScope(
                $db,
                static fn (int $uid, ?string $token) => (null !== $childSession && $childUserId === $uid) ? $childSession : null,
                static fn (int $uid): array => []
            );
        } else {
            // License gate and session scope from production wiring write
            // nothing (gate is pure; session reads hit the harness connection
            // inside its outer transaction — rolled back at tear_down). The
            // App-booted SMS singleton persists to the SHARED test database
            // though — verified FK 1452 on wptests_cpms_sms_messages when a
            // disposable clinic_id had no clinic row there — so the SMS
            // boundary is built on the disposable connection instead.
            $registry = new SmsProviderRegistry();
            $registry->register(new LogSmsProvider($op));
            $registry->register(new GenericApiSmsProvider(static fn (): array => []));
            $sms = new SmsService(
                $db,
                $settingsFactory,
                $registry,
                new CredentialVault(),
                $audit,
                $op,
                new JobQueue($db, $op),
                static fn (): int => 0
            );
            $license = \ClinicCore\Bootstrap\App::licenseGate();
            $sessions = \ClinicCore\Bootstrap\App::patient_session_scope();
        }

        $service = new BookingService(
            $db,
            new SlotRepository($db),
            new AppointmentRepository($db),
            new PatientRepository($db),
            $settingsFactory,
            $license,
            $audit,
            $op,
            $idem,
            $sms,
            null,
            null,
            null,
            $sessions
        );

        return ['service' => $service, 'db' => $db];
    }

    /**
     * Call $fn inside a real outer transaction: production "commits" become
     * RELEASE SAVEPOINT inside it. On exception, ROLLBACK and rethrow.
     */
    protected function transactionalCommit(\wpdb $conn, callable $fn): mixed
    {
        $conn->query('START TRANSACTION');
        try {
            $result = $fn();
            $conn->query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $conn->query('ROLLBACK');
            throw $e;
        }
    }

    protected function insertRow(\wpdb $conn, string $table, array $row): void
    {
        $ok = $conn->insert($table, $row);
        if (false === $ok) {
            throw new \RuntimeException('fixture insert failed into ' . $table . ': ' . $conn->last_error);
        }
    }

    protected function insertPatient(string $mrn, string $now): int
    {
        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_patients'), [
            'clinic_id' => $this->clinicId,
            'mrn' => $mrn,
            'first_name' => 'Disposable',
            'last_name' => 'Patient',
            'mobile' => '0912' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->fixtureWpdb->insert_id;
        self::assertGreaterThan(0, $id);
        return $id;
    }

    protected function linkPatient(int $patientId, int $userId, string $mobile, string $now): void
    {
        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_patient_user_links'), [
            'clinic_id' => $this->clinicId,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $now,
        ]);
    }

    protected function insertSlot(int $capacity, int $heldCount, string $date): int
    {
        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_schedule_slots'), [
            'clinic_id' => $this->clinicId,
            'location_id' => $this->locationId,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $date,
            'slot_time' => '09:00:00',
            'duration_min' => 20,
            'capacity' => $capacity,
            'booked_count' => 0,
            'held_count' => $heldCount,
            'is_open' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->fixtureWpdb->insert_id;
        self::assertGreaterThan(0, $id, 'slot must persist');
        return $id;
    }

    protected function insertHold(int $slotId, int $holderUserId, string $expiresAt, string $status): int
    {
        $this->insertRow($this->fixtureWpdb, $this->rawTable('cpms_slot_holds'), [
            'clinic_id' => $this->clinicId,
            'slot_id' => $slotId,
            'holder_wp_user_id' => $holderUserId,
            'token' => 'tok-ds-' . bin2hex(random_bytes(8)),
            'expires_at' => $expiresAt,
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->fixtureWpdb->insert_id;
        self::assertGreaterThan(0, $id, 'hold must persist');
        return $id;
    }

    protected function holdToken(int $holdId, \wpdb $conn): string
    {
        $token = (string) $conn->get_var($conn->prepare('SELECT token FROM ' . $conn->prefix . 'cpms_slot_holds WHERE id = %d', $holdId));
        self::assertNotSame('', $token);
        return $token;
    }

    protected function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function rawTable(string $table): string
    {
        return $this->fixtureWpdb->prefix . $table;
    }

    /** Fresh read-only mysqli connection to the disposable schema. */
    protected function freshMysqli(): \mysqli
    {
        return $this->rawMysqli($this->disposableDb);
    }

    /**
     * Fresh mysqli connection on the same server as the harness, optionally
     * scoped to a database. With `null` no database is selected — used for
     * metadata-level DDL that must not go through the WP `query` filter.
     */
    private function rawMysqli(?string $db): \mysqli
    {
        $host = (string) DB_HOST;
        $port = 3306;
        $socket = null;
        if (str_contains($host, ':')) {
            [$h, $rest] = explode(':', $host, 2);
            if (ctype_digit($rest)) {
                $host = $h;
                $port = (int) $rest;
            } else {
                // WP allows 'host:/path/to/socket.sock' — the part after ':' is a socket path, not a port.
                $socket = $rest;
            }
        }
        if (null !== $socket) {
            $mysqli = @new \mysqli('localhost', DB_USER, DB_PASSWORD, $db ?? '', null, $socket);
        } else {
            $mysqli = @new \mysqli($host, DB_USER, DB_PASSWORD, $db ?? '', $port);
        }
        if ($mysqli->connect_error) {
            throw new \RuntimeException('mysqli connect failed: ' . $mysqli->connect_error);
        }
        return $mysqli;
    }

    /**
     * Per-process shadow directory of one-line wrapper files for the plugin's
     * migrations. See the header note for why this exists: the runner's plain
     * `require()` would re-declare cpms_m0002_column_exists() (top-level
     * function in 2026_09_05_0002_duration_and_buffers.php) on every fresh
     * schema of this process. Each wrapper delegates to the namespaced
     * static class cache TestMigrationArrays; the real migration code is
     * compiled once per process and executed unchanged.
     */
    private static function migrationMirrorDir(): string
    {
        if (null !== self::$migrationMirror) {
            return self::$migrationMirror;
        }
        $real = rtrim((string) constant('CPMS_PLUGIN_DIR'), '/') . '/src/Migrations';
        $files = glob($real . '/????_??_??_????_*.php') ?: [];
        sort($files);
        if ([] === $files) {
            throw new \RuntimeException('migration mirror: no migration files found in ' . $real);
        }
        // Runner-compatibility cache directory, required by the runner's
        // plain-require contract (see header). Not part of the product,
        // ships in no artifact, wiped by the shutdown hook; 0700 perms.
        $dir = sys_get_temp_dir() . '/cpms-migration-mirror-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('migration mirror: cannot create ' . $dir);
        }
        // Register the mirror EARLY: a failure mid-way must still leave a
        // shutdown path able to wipe the (possibly partial) mirror.
        self::$migrationMirror = $dir;
        $createdWrappers = [];
        try {
        foreach ($files as $file) {
            $base = basename($file);
            // Each wrapper is a one-line include guard delegating to a namespaced
            // static class cache (TestMigrationArrays, declared at the bottom of
            // this file). No global variable is used, and file-scope statics are
            // NOT used: they do NOT persist across includes of the same file
            // (verified empirically: first include returned 1, second also 1).
            $wrapper = "<?php\n"
                . "// Migration include guard for the ISOLATED test schema (see IsolatedSchema.php).\n"
                . 'return \ClinicCore\Tests\Integration\TestMigrationArrays::load(' . var_export($base, true) . ', ' . var_export($file, true) . ");\n";
            if (false === file_put_contents($dir . '/' . $base, $wrapper)) {
                throw new \RuntimeException('migration mirror: cannot write wrapper for ' . $base);
            }
            $createdWrappers[] = $dir . '/' . $base;
        }
        } catch (\Throwable $e) {
            // A partial mirror must not survive: wipe best-effort, report
            // loudly, then rethrow (never swallow). If the wipe fails, keep
            // self::$migrationMirror set so the shutdown hook retries.
            $failed = 0;
            foreach ($createdWrappers as $wrapperFile) {
                if (!@unlink($wrapperFile)) {
                    $failed++;
                }
            }
            $rmdirOk = @rmdir($dir);
            if ($rmdirOk) {
                self::$migrationMirror = null;
            }
            fwrite(STDERR, 'migration mirror: aborted (' . $e->getMessage() . '); partial mirror ' . ($rmdirOk ? 'wiped' : 'retained at ' . $dir . ' for the shutdown hook') . ($failed > 0 ? '; ' . $failed . ' unlink failure(s)' : '') . PHP_EOL);
            throw $e;
        }
        return $dir;
    }

    /**
     * One process-lifetime shutdown hook: drop any disposable schema that is
     * still registered and unblocked (e.g. after a fatal that bypassed
     * tearDown) and wipe the migration mirror. FAIL-CLOSED: a forked child
     * (cleanup disabled in its own process) performs no cleanup; a parent
     * with any unconfirmed forked child RETAINS every open schema AND the
     * migration mirror loudly. Loud on STDERR for every anomaly — a leaked
     * schema or mirror is never hidden.
     */
    private static function registerFatalCleanup(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function (): void {
            // A forked child at exit must NEVER run cleanup here: the schema
            // belongs to the parent, and the child's inherited registry of
            // open schemas is a stale snapshot. Disable first, always.
            if (self::$fatalCleanupDisabledInThisProcess) {
                fwrite(STDERR, 'disposable schema cleanup: shutdown cleanup disabled in this process (forked child, pid=' . getmypid() . '); the parent owns cleanup' . PHP_EOL);
                return;
            }
            // BOOTSTRAP ORDER (fail-closed): decide fork-retention BEFORE any
            // cleanup side effect, not after. An unconfirmed forked child
            // (or an interrupted fork site) cannot prove what the child
            // still references — the migration mirror is spawn/consume state
            // the child shares with this process, so retaining it under the
            // same condition as open schemas is required for consistency.
            $unconfirmed = self::pendingChildPids();
            $intent = self::pendingForkIntent();
            $retentionNote = null;
            if ($unconfirmed !== [] || $intent > 0) {
                $parts = [];
                if ($unconfirmed !== []) {
                    $parts[] = 'pids=' . implode(',', $unconfirmed);
                }
                if ($intent > 0) {
                    $parts[] = 'fork intent in flight (intent=' . $intent . '): a fatal/exit before parent-side registration of a child cannot be ruled out';
                }
                $retentionNote = 'unconfirmed forked child process(es) may still be active; ' . implode('; ', $parts);
            }
            try {
                if (null !== $retentionNote && is_dir((string) self::$migrationMirror)) {
                    fwrite(STDERR, 'disposable schema cleanup: backstop RETAINING migration mirror at ' . self::$migrationMirror . ' (fail-closed): ' . $retentionNote . PHP_EOL);
                } elseif (is_dir((string) self::$migrationMirror)) {
                    $deleted = 0;
                    $failed  = 0;
                    foreach (glob(self::$migrationMirror . '/*.php') ?: [] as $wrapperFile) {
                        if (@unlink($wrapperFile)) {
                            $deleted++;
                        } else {
                            $failed++;
                        }
                    }
                    if (!@rmdir((string) self::$migrationMirror)) {
                        fwrite(STDERR, 'disposable schema cleanup: mirror dir NOT removed: ' . self::$migrationMirror . ' (' . $failed . ' unlink failure(s))' . PHP_EOL);
                    } elseif ($failed > 0) {
                        fwrite(STDERR, 'disposable schema cleanup: mirror dir removed with ' . $failed . ' unlink failure(s)' . PHP_EOL);
                    }
                }
            } catch (\Throwable $e) {
                // Never silent: a failed mirror wipe leaks (harmless) temp wrappers.
                fwrite(STDERR, 'disposable schema cleanup: mirror wipe error: ' . $e->getMessage() . PHP_EOL);
            }
            // FAIL CLOSED: reuse the bootstrap decision computed before ANY
            // cleanup side effect (see above). We cannot prove no child is
            // mid-transaction on any open schema; retain ALL of them loudly.
            foreach (array_keys(self::$openSchemas) as $name) {
                if (null !== $retentionNote) {
                    fwrite(STDERR, 'disposable schema cleanup: backstop RETAINING ' . $name . ' (fail-closed): ' . $retentionNote . PHP_EOL);
                    continue;
                }
                if (isset(self::$dropBlockedSchemas[$name])) {
                    // FAIL CLOSED at process end as well: a known/potentially
                    // active forked child outranks cleanup. Retain + report.
                    fwrite(STDERR, 'disposable schema cleanup: backstop RETAINING ' . $name . ' (fail-closed): ' . self::$dropBlockedSchemas[$name] . PHP_EOL);
                    continue;
                }
                if (1 !== preg_match('/^cpms_[a-z][a-z0-9_]*$/', $name)) {
                    // Paranoia: never issue DROP for an unexpected name.
                    unset(self::$openSchemas[$name]);
                    continue;
                }
                try {
                    $host = (string) DB_HOST;
                    $port = 3306;
                    $socket = null;
                    if (str_contains($host, ':')) {
                        [$h, $rest] = explode(':', $host, 2);
                        if (ctype_digit($rest)) {
                            $host = $h;
                            $port = (int) $rest;
                        } else {
                            $socket = $rest;
                        }
                    }
                    if (null !== $socket) {
                        $mysqli = @new \mysqli('localhost', DB_USER, DB_PASSWORD, '', null, $socket);
                    } else {
                        $mysqli = @new \mysqli($host, DB_USER, DB_PASSWORD, '', $port);
                    }
                    if ($mysqli->connect_error) {
                        fwrite(STDERR, 'disposable schema cleanup: could not connect to drop ' . $name . PHP_EOL);
                        continue;
                    }
                    // Bounded metadata-lock wait at process end: 10s, notifies loudly otherwise.
                    $mysqli->query('SET SESSION lock_wait_timeout = 10');
                    if (!$mysqli->query('DROP DATABASE IF EXISTS `' . $name . '`')) {
                        fwrite(STDERR, 'disposable schema cleanup: DROP DATABASE failed for ' . $name . ': ' . $mysqli->error . PHP_EOL);
                    }
                    $mysqli->close();
                } catch (\Throwable $e) {
                    // Never silent: the schema is left in place AND reported.
                    fwrite(STDERR, 'disposable schema cleanup: error dropping ' . $name . ': ' . $e->getMessage() . PHP_EOL);
                }
            }
            self::$openSchemas = [];
        });
    }

    protected function rawScalar(string $sql): string
    {
        $mysqli = $this->freshMysqli();
        try {
            $result = $mysqli->query($sql);
            if (false === $result) {
                throw new \RuntimeException('raw observation query failed: ' . $mysqli->error);
            }
            $row = $result->fetch_row();
            $result->free();
            self::assertIsArray($row);
            return (string) $row[0];
        } finally {
            $mysqli->close();
        }
    }

    protected function versionOf(\mysqli $mysqli): string
    {
        $result = $mysqli->query('SELECT VERSION()');
        if (false === $result) {
            return '';
        }
        $row = $result->fetch_row();
        $result->free();
        return (string) ($row[0] ?? '');
    }

    protected function waitForFile(string $file, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (is_file($file)) {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        return false;
    }

    protected function readConnectionIdFromFile(string $file): int
    {
        return (int) trim((string) @file_get_contents($file));
    }

    protected function waitForGo(string $goFile): void
    {
        $deadline = microtime(true) + 20.0;
        while (!is_file($goFile) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (!is_file($goFile)) {
            throw new \RuntimeException('worker barrier timed out waiting for ' . $goFile);
        }
    }

}

/**
 * Process-wide cache for migration arrays, used by the migration include
 * guards (the wrapper files described above). Static class state is used
 * deliberately: the runner re-includes each wrapper on every migrate() call
 * and every include must return the identical array instance without
 * re-executing the migration (whose top-level symbols would then be
 * redeclared). Static class properties persist across includes; file-scope
 * static variables do not.
 *
 * @internal Test infrastructure only; never referenced by production code.
 */
final class TestMigrationArrays
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public static function load(string $base, string $file): array
    {
        if (!array_key_exists($base, self::$cache)) {
            $migration = require $file;
            if (!is_array($migration)) {
                throw new \RuntimeException('migration did not return an array: ' . $file);
            }
            self::$cache[$base] = $migration;
        }

        return self::$cache[$base];
    }
}

/**
 * Test-only wpdb subclass with an optional SQL rewriter used for failure
 * injection. It never fabricates error messages: it rewrites the SQL so the
 * real driver reports a real failure (missing table), and counts how many
 * statements were rewritten.
 */
final class FaultWpdb extends \wpdb
{
    /** @var callable(string):?string|null */
    public $faultCallback = null;

    public int $faultFired = 0;

    /** @return int|bool */
    public function query($query)
    {
        if (null !== $this->faultCallback) {
            $replacement = ($this->faultCallback)((string) $query);
            if (null !== $replacement) {
                ++$this->faultFired;
                $query = $replacement;
            }
        }

        return parent::query($query);
    }
}
