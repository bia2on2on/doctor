<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Bootstrap\RuntimeSchemaGate;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate;
use ClinicCore\Infrastructure\Storage\PrivateStorageInitializer;
use WP_UnitTestCase;

/**
 * N1 / F1 / F2 — پیش‌بررسیِ موتورِ دیتابیس در فعال‌سازی و گیتِ زمانِ اجرا روی سایتِ فعال.
 *
 * قراردادِ اثبات‌شده در این فایل:
 *  - مسیرِ واقعیِ `activate_plugin()` وردپرس در پردازهٔ ایزوله (پیش از بارگذاریِ افزونه)
 *    روی موتورِ پشتیبانی‌نشده هم `plugin_sandbox_scrape()` و هم `App::activate()` را
 *    اجرا می‌کند و بدونِ هیچ ردی (`cpms_jobs_tick`، نقش، جدول، ledger، Option) رد می‌شود؛
 *    و در همان پردازهٔ ایزوله روی موتورِ مجاز همهٔ ۵ نقش و `cpms_jobs_tick` را می‌سازد.
 *  - روی سایتِ از-پیش-فعال، گیتِ متمرکزِ زمانِ اجرا فقط وقتی مسدود می‌کند که هم موتور
 *    با شواهدِ خوانا پشتیبانی‌نشده باشد و هم مهاجرتِ اعمال‌نشده در انتظار باشد (REST با
 *    503 پایدار و بدون داده، پورتال‌ها/شورت‌کد با پیامِ خنثی، توقفِ cron/tick، و دسترس‌پذیر
 *    ماندنِ `plugins.php` و غیرفعال‌سازی).
 *  - اگر schema از قبل به‌روز باشد، مسیرهای زمانِ اجرا باز می‌مانند و فقط اعلانِ مدیریتی
 *    نمایش داده می‌شود؛ اگر شواهدِ نسخه ناخوانا/مخدوش باشد، زمانِ اجرا مسدود نمی‌شود
 *    (لاگِ throttled عملیاتی) در حالی که فعال‌سازی همچنان fail-closed می‌ماند.
 */
final class AppActivationDatabaseGateTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Repo pattern (CustomRoleDisplayNameMigrationTest): a pending version
        // left by an earlier test class is re-applied with REAL tables, so the
        // migrations this class exercises never run through the harness's
        // temporary-table rewrite (no shadow tables on the shared connection).
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        App::reset_database_compatibility_state_for_tests();
        delete_transient('cpms_migrate_lock');

        foreach (['pagenow', 'wp_rest_server'] as $key) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        try {
            App::set_db_compatibility_probe_for_tests(null);
            App::reset_database_compatibility_state_for_tests();
            delete_transient('cpms_migrate_lock');
            $this->withRealTables(static fn (): array => App::migrations()->migrate());
        } finally {
            foreach ($this->globalsBackup as $key => $value) {
                if ($value === null) {
                    unset($GLOBALS[$key]);
                    continue;
                }
                $GLOBALS[$key] = $value;
            }
            wp_set_current_user(0);
            parent::tearDown();
        }
    }

    // ============================ helpers ============================

    /** @return array<string, bool> — نامِ همهٔ نقش‌های ثبت‌شده */
    private function roleNames(): array
    {
        $names = [];
        foreach (wp_roles()->get_names() as $role => $_label) {
            $names[(string) $role] = true;
        }

        return $names;
    }

    /** @return list<string> — نامِ همهٔ Optionهای فعلی */
    private function optionNames(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options}");
        $names = array_map('strval', (array) $rows);
        sort($names);

        return $names;
    }

    /** @return list<string> — نسخه‌های ledger مهاجرت */
    private function ledgerVersions(): array
    {
        return App::migrations()->applied();
    }

    /** @return array<int, array<string, mixed>> */
    private function cronEvents(): array
    {
        return _get_cron_array();
    }

    /** @return list<string> — جداول cpms_% فعلی */
    private function cpmsTables(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
                $wpdb->prefix . 'cpms_%'
            )
        );
        $tables = array_map('strval', (array) $rows);
        sort($tables);

        return $tables;
    }

    /**
     * الگوی جاافتادهٔ مخزن برای گرفتنِ wp_die بدون کشتنِ PHPUnit.
     *
     * @return array{message: string, title: string, args: array<string, mixed>}
     */
    private function captureWpDie(callable $fn): array
    {
        $captured = [];
        $handler  = static function () use (&$captured): callable {
            return static function ($message, $title = '', $args = []) use (&$captured): void {
                $captured[] = [
                    'message' => is_string($message) ? $message : '',
                    'title'   => is_string($title) ? $title : '',
                    'args'    => is_array($args) ? $args : [],
                ];
                throw new \RuntimeException('CPMS_TEST_WP_DIE');
            };
        };
        add_filter('wp_die_handler', $handler, PHP_INT_MAX);

        try {
            $fn();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'CPMS_TEST_WP_DIE') {
                throw $e;
            }
        } finally {
            remove_filter('wp_die_handler', $handler, PHP_INT_MAX);
        }

        self::assertCount(1, $captured, 'wp_die must have been called exactly once');
        return $captured[0];
    }

    private function unsupportedProbe(): void
    {
        App::set_db_compatibility_probe_for_tests(
            static fn (): array => DatabaseCompatibilityGate::check('11.8.6-MariaDB', 'mariadb.org binary distribution')
        );
    }

    private function supportedProbe(): void
    {
        App::set_db_compatibility_probe_for_tests(
            static fn (): array => DatabaseCompatibilityGate::check('8.0.36', 'MySQL Community Server - GPL')
        );
    }

    private function invokeEnsureMigrated(): void
    {
        $method = new \ReflectionMethod(App::class, 'ensureMigrated');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    /**
     * شمارشِ ردیف‌های OpLog بر اساس پیام — جدولِ واقعیِ OpLogger
     * (`cpms_operational_logs`، ساخته‌شده در مهاجرت 0001)؛ نه نامِ سرویس.
     */
    private function opLogRowCount(string $message): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs') . ' WHERE message = %s',
            [$message]
        );
    }

    // ============================ activation ============================

    public function testActivationIsRefusedOnAnUnsupportedEngineWithZeroResidue(): void
    {
        $rolesBefore       = $this->roleNames();
        $optionsBefore     = $this->optionNames();
        $ledgerBefore      = $this->ledgerVersions();
        $cronBefore        = $this->cronEvents();
        $tablesBefore      = $this->cpmsTables();
        $activatedBefore   = $this->opLogRowCount('CPMS_ACTIVATED');
        $unsupportedBefore = $this->opLogRowCount('CPMS_DB_ENGINE_UNSUPPORTED');

        $this->unsupportedProbe();
        $die = $this->captureWpDie(static function (): void {
            App::activate();
        });

        // The refusal is readable and uses migration 0027's accepted-set wording.
        self::assertStringContainsString('11.8.6-MariaDB', $die['message']);
        self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $die['message']);
        self::assertStringContainsString('no CPMS roles, database tables, options or scheduled events have been created', $die['message']);
        self::assertArrayHasKey('back_link', $die['args']);
        self::assertTrue((bool) $die['args']['back_link'], 'the refusal must offer a back link');

        // Zero residue: nothing was written by the refused activation.
        self::assertSame($rolesBefore, $this->roleNames(), 'a refused activation must not add or remove any role');
        self::assertSame($optionsBefore, $this->optionNames(), 'a refused activation must not write any option');
        self::assertSame($ledgerBefore, $this->ledgerVersions(), 'a refused activation must not touch the migration ledger');
        self::assertSame($cronBefore, $this->cronEvents(), 'a refused activation must not schedule any cron event');
        self::assertSame($tablesBefore, $this->cpmsTables(), 'a refused activation must not create any table');
        self::assertSame($activatedBefore, $this->opLogRowCount('CPMS_ACTIVATED'), 'the refused activation must not log CPMS_ACTIVATED');
        self::assertSame($unsupportedBefore, $this->opLogRowCount('CPMS_DB_ENGINE_UNSUPPORTED'), 'the refused activation itself logs nothing');
    }

    public function testActivationOnASupportedEngineBehavesAsToday(): void
    {
        // No probe: the real engine-evidence path (SELECT VERSION() through CpmsDb).
        // On the CI integration lanes (MySQL 8 / MariaDB 11.4.13) this is supported.
        // Same discovery pattern as MigrationRunner::migrationFiles() — the runner
        // itself lives in the same directory and must not be counted.
        $migrationFiles  = glob(CPMS_PLUGIN_DIR . 'src/Migrations/????_??_??_????_*.php') ?: [];
        $activatedBefore = $this->opLogRowCount('CPMS_ACTIVATED');

        App::activate();

        foreach ([
            RolesAndCapabilities::ROLE_PATIENT,
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            RolesAndCapabilities::ROLE_MANAGER,
        ] as $role) {
            self::assertNotNull(get_role($role), "supported activation still registers {$role}");
        }

        self::assertCount(count($migrationFiles), $this->ledgerVersions(), 'every shipped migration is applied');
        self::assertNotFalse(wp_next_scheduled('cpms_jobs_tick'), 'the recurring queue tick is scheduled');
        self::assertSame($activatedBefore + 1, $this->opLogRowCount('CPMS_ACTIVATED'), 'CPMS_ACTIVATED is logged exactly as before');
    }

    public function testReactivationAfterARefusedActivationBehavesExactlyAsToday(): void
    {
        $optionsBefore   = $this->optionNames();
        $cronBefore      = $this->cronEvents();
        $ledgerBefore    = $this->ledgerVersions();
        $activatedBefore = $this->opLogRowCount('CPMS_ACTIVATED');

        // First attempt: refused on an unsupported engine — zero residue.
        $this->unsupportedProbe();
        $die = $this->captureWpDie(static function (): void {
            App::activate();
        });
        self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $die['message']);
        self::assertSame($optionsBefore, $this->optionNames());
        self::assertSame($cronBefore, $this->cronEvents());
        self::assertSame($ledgerBefore, $this->ledgerVersions());
        self::assertSame($activatedBefore, $this->opLogRowCount('CPMS_ACTIVATED'), 'refused attempt logs nothing');

        // The engine is fixed (supported evidence now) — activation must behave
        // exactly like a fresh, first-time activation on a supported engine.
        $this->supportedProbe();
        App::activate();

        $ledger = $this->ledgerVersions();
        self::assertSame($ledgerBefore, $ledger, 're-activation after refusal re-applies nothing extra (schema was already complete here)');
        foreach ([
            RolesAndCapabilities::ROLE_PATIENT,
            RolesAndCapabilities::ROLE_DOCTOR,
        ] as $role) {
            self::assertNotNull(get_role($role), "re-activation registers {$role}");
        }
        self::assertNotFalse(wp_next_scheduled('cpms_jobs_tick'), 're-activation schedules the queue tick');
        self::assertSame($activatedBefore + 1, $this->opLogRowCount('CPMS_ACTIVATED'), 'CPMS_ACTIVATED is logged for the successful activation');
    }

    // ============================ ensureMigrated ============================

    public function testEnsureMigratedOnAnUnsupportedEngineDoesNotThrowAndDoesNotMigrate(): void
    {
        // Make one migration genuinely pending (0031's up() is idempotent by
        // design: CREATE TABLE IF NOT EXISTS + INSERT IGNORE), so "did
        // ensureMigrated call migrate()?" is observable through the ledger.
        $latest = App::migrations()->currentVersion();
        self::assertNotNull($latest);
        $opLogWatermark    = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_operational_logs')
        );
        $unsupportedBefore = $this->opLogRowCount('CPMS_DB_ENGINE_UNSUPPORTED');

        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );
        self::assertNotContains($latest, $this->ledgerVersions());

        $this->unsupportedProbe();
        PrivateStorageInitializer::forget();

        try {
            $this->invokeEnsureMigrated();
        } catch (\Throwable $e) {
            self::fail('ensureMigrated() must not throw on an unsupported engine: ' . $e->getMessage());
        }

        self::assertNotContains(
            $latest,
            $this->ledgerVersions(),
            'the pending migration must NOT be applied on an unsupported engine'
        );
        self::assertSame($unsupportedBefore + 1, $this->opLogRowCount('CPMS_DB_ENGINE_UNSUPPORTED'), 'exactly one throttled OpLog row on first sight');
        self::assertNotNull(
            PrivateStorageInitializer::state(),
            'unsupported engine skips schema migrations but still initializes private storage as on main'
        );

        // Throttle: a second run within the window logs nothing more.
        delete_transient('cpms_migrate_lock');
        $this->invokeEnsureMigrated();
        self::assertSame($unsupportedBefore + 1, $this->opLogRowCount('CPMS_DB_ENGINE_UNSUPPORTED'), 'the OpLog write is throttled per window');
        self::assertNotContains($latest, $this->ledgerVersions());

        // Clean up this test's OpLog row before the positive control runs real
        // DDL (which implicitly commits the outer test transaction in MySQL).
        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_operational_logs') . ' WHERE id > %d',
            [$opLogWatermark]
        );

        // Positive control: with a supported engine the SAME pending migration is
        // applied through ensureMigrated() — only the gate skips migrate().
        // Run with real tables so WP's temporary-table rewrite never creates a
        // shadow temp table for 0031's `CREATE TABLE IF NOT EXISTS`.
        App::set_db_compatibility_probe_for_tests(null);
        App::reset_database_compatibility_state_for_tests();
        delete_transient('cpms_migrate_lock');
        $this->withRealTables(function (): void {
            $this->invokeEnsureMigrated();
        });
        self::assertContains($latest, $this->ledgerVersions(), 'supported engine still migrates the pending version');
    }

    public function testUnsupportedEngineAdminNoticeIsVisibleToAdministratorsOnly(): void
    {
        $this->unsupportedProbe();
        $this->invokeEnsureMigrated();

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $html = '';
        $level = ob_get_level();
        ob_start();
        try {
            App::render_unsupported_engine_notice();
        } finally {
            $html = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('11.8.6-MariaDB', $html);
        self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $html);

        // A non-administrator (staff) never sees the operational notice.
        $secretary = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        wp_set_current_user($secretary);

        $html = '';
        $level = ob_get_level();
        ob_start();
        try {
            App::render_unsupported_engine_notice();
        } finally {
            $html = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        self::assertSame('', $html, 'the notice is administrator-only');

        // And with no unsupported engine observed in the request, nothing renders.
        App::reset_database_compatibility_state_for_tests();
        wp_set_current_user($admin);
        $none = '';
        ob_start();
        try {
            App::render_unsupported_engine_notice();
        } finally {
            $none = (string) ob_get_clean();
        }
        self::assertSame('', $none);
    }

    // ============================ boot deferral (source-level) ============================

    public function testBootDefersRoleRegistrationWhileWordPressScrapesTheActivationInclude(): void
    {
        $source    = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Bootstrap/App.php');
        $bootStart = strpos($source, 'public static function boot(): void');
        $bootEnd   = strpos($source, 'public static function activate(');
        self::assertNotFalse($bootStart);
        self::assertNotFalse($bootEnd);
        $bootBody = substr($source, $bootStart, $bootEnd - $bootStart);

        $guardOffset = strpos($bootBody, 'WP_SANDBOX_SCRAPING');
        $rolesOffset = strpos($bootBody, 'RolesAndCapabilities::register();');
        self::assertNotFalse($guardOffset, 'boot() must consult WP_SANDBOX_SCRAPING around the roles registration');
        self::assertNotFalse($rolesOffset, 'boot() keeps registering roles on normal requests');
        self::assertLessThan(
            $rolesOffset,
            $guardOffset,
            'the WP_SANDBOX_SCRAPING guard must wrap the roles registration in boot()'
        );

        // activate() itself still registers roles on the supported path, so the
        // deferral never loses the roles for a genuine activation.
        $activateStart = strpos($source, 'public static function activate(');
        $activateEnd   = strpos($source, 'public static function deactivate(');
        $activateBody  = substr($source, $activateStart, $activateEnd - $activateStart);
        self::assertStringContainsString('RolesAndCapabilities::register();', $activateBody);
        // …but only AFTER the engine pre-check refuses unsupported servers first.
        $preCheckOffset        = strpos($activateBody, 'database_compatibility()');
        $rolesInActivateOffset = strpos($activateBody, 'RolesAndCapabilities::register();');
        $cronInActivateOffset  = strpos($activateBody, 'CronScheduleRepair::ensure_scheduled();');
        self::assertNotFalse($preCheckOffset, 'activate() must call database_compatibility()');
        self::assertNotFalse($rolesInActivateOffset, 'activate() must call RolesAndCapabilities::register()');
        self::assertNotFalse($cronInActivateOffset, 'activate() must schedule cpms_jobs_tick on supported engines');
        self::assertLessThan(
            $rolesInActivateOffset,
            $preCheckOffset,
            'the engine pre-check must run before roles in activate()'
        );
        self::assertLessThan(
            $cronInActivateOffset,
            $preCheckOffset,
            'the engine pre-check must run before cron scheduling in activate()'
        );

        // F1: CronScheduleRepair::ensure_scheduled() in boot() must also be
        // guarded by WP_SANDBOX_SCRAPING so plugin_sandbox_scrape() never leaves
        // cpms_jobs_tick scheduled on a refused activation.
        $cronInBootOffset     = strpos($bootBody, 'CronScheduleRepair::ensure_scheduled();');
        $lastSandboxGuardPos  = strrpos($bootBody, 'WP_SANDBOX_SCRAPING');
        self::assertNotFalse($cronInBootOffset, 'boot() keeps ensuring cron schedule on normal requests');
        self::assertNotFalse($lastSandboxGuardPos);
        self::assertLessThan(
            $cronInBootOffset,
            $lastSandboxGuardPos,
            'WP_SANDBOX_SCRAPING must guard CronScheduleRepair::ensure_scheduled() in boot()'
        );
    }

    // ============================ F1: real activate_plugin() worker ============================

    /**
     * @return array<string, mixed>
     */
    private function runActivationWorker(string $mode): array
    {
        $tests_dir = rtrim((string) getenv('WP_TESTS_DIR'), '/');
        $env       = [
            'PATH'                      => (string) getenv('PATH'),
            'HOME'                      => (string) getenv('HOME'),
            'WP_TESTS_DIR'              => $tests_dir,
            'WP_TESTS_CONFIG_FILE_PATH' => (string) (getenv('WP_TESTS_CONFIG_FILE_PATH') ?: $tests_dir . '/wp-tests-config.php'),
            'WP_CORE_DIR'               => rtrim(ABSPATH, '/'),
            'CPMS_ACTIVATION_WORKER_MODE' => $mode,
        ];

        $cmd         = [PHP_BINARY, dirname(__DIR__, 2) . '/tests/bin/refused-activation-worker.php'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2), $env);
        self::assertIsResource($process, 'activation worker process must start');
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            "activation worker ({$mode}) failed with exit={$exitCode}; stdout={$stdout}; stderr={$stderr}"
        );

        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "activation worker ({$mode}) must emit JSON: {$stdout}");
        self::assertTrue((bool) ($decoded['ok'] ?? false), "activation worker ({$mode}) reported error: {$stdout}");

        return $decoded;
    }

    public function testRealActivatePluginFlowLeavesZeroCronRoleOptionOrLedgerResidueAndPreservesSupportedControl(): void
    {
        // Release parent transaction row locks on wp_options before spawning child DB connection.
        self::commit_transaction();

        // 1. Refused activation through real WordPress activate_plugin() -> plugin_sandbox_scrape() -> App::boot() -> App::activate().
        $refused = $this->runActivationWorker('unsupported');
        self::assertTrue($refused['cpms_loaded'], 'plugin_sandbox_scrape() must actually include clinic-practice-management.php');
        self::assertTrue($refused['sandbox_scraping_defined'], 'WP_SANDBOX_SCRAPING must be active during real activate_plugin()');
        self::assertNull($refused['wp_error_code'], 'activate_plugin() must reach CPMS activation rather than failing early');
        self::assertIsArray($refused['wp_die']);
        self::assertStringContainsString('11.8.6-MariaDB', (string) ($refused['wp_die']['message'] ?? ''));
        self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, (string) ($refused['wp_die']['message'] ?? ''));
        self::assertFalse($refused['plugin_active'], 'plugin must remain inactive after refusal');
        self::assertFalse($refused['jobs_tick_scheduled'], 'refused activate_plugin() must NOT leave cpms_jobs_tick scheduled');
        self::assertSame([], $refused['present_cpms_roles'], 'refused activate_plugin() must leave zero CPMS roles');
        self::assertTrue($refused['roles_unchanged'], 'roles must match pre-activation snapshot');
        self::assertTrue($refused['options_unchanged'], 'wp_options must match pre-activation snapshot');
        self::assertTrue($refused['ledger_unchanged'], 'migration ledger must match pre-activation snapshot');
        self::assertTrue($refused['cron_unchanged'], 'cron array must match pre-activation snapshot');
        self::assertSame(0, $refused['activated_log_delta']);
        self::assertSame(0, $refused['unsupported_log_delta']);

        // 2. Positive control through the exact same activate_plugin() worker on a supported engine.
        $supported = $this->runActivationWorker('supported');
        self::assertTrue($supported['cpms_loaded']);
        self::assertTrue($supported['sandbox_scraping_defined']);
        self::assertNull($supported['wp_error_code']);
        self::assertNull($supported['wp_die']);
        self::assertTrue($supported['plugin_active'], 'supported activate_plugin() activates CPMS');
        self::assertTrue($supported['jobs_tick_scheduled'], 'supported activate_plugin() schedules cpms_jobs_tick');
        self::assertCount(5, $supported['present_cpms_roles'], 'supported activate_plugin() registers all 5 CPMS roles');
        self::assertSame(1, $supported['activated_log_delta']);
    }

    // ============================ F2: runtime gate ============================

    public function testRuntimeGateKeepsServingWhenSchemaIsAlreadyCurrentOnUnsupportedEngineAndShowsAdminNotices(): void
    {
        self::assertFalse(App::migrations()->has_pending_migrations(), 'precondition: schema is current');

        $this->unsupportedProbe();

        self::assertFalse(
            App::is_runtime_blocked_by_pending_schema(),
            'already-current schema on an unsupported engine must NOT block runtime'
        );

        // REST keeps serving normally (200, not 503).
        $restResponse = rest_get_server()->dispatch(new \WP_REST_Request('GET', '/clinic/v1/health'));
        self::assertSame(200, $restResponse->get_status());
        $payload = $restResponse->get_data();
        self::assertTrue((bool) ($payload['data']['ok'] ?? false));

        // Shortcode is not replaced with the runtime-unavailable banner.
        $shortcodeHtml = do_shortcode('[cpms_public_booking clinic_id="999999"]');
        self::assertStringNotContainsString('data-role="cpms-runtime-unavailable"', $shortcodeHtml);

        // Both admin_notices and network_admin_notices render the warning for administrators.
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        foreach (['admin_notices', 'network_admin_notices'] as $noticeHook) {
            self::assertNotFalse(
                has_action($noticeHook, [App::class, 'render_unsupported_engine_notice']),
                "{$noticeHook} must be wired to render_unsupported_engine_notice"
            );
            ob_start();
            App::render_unsupported_engine_notice();
            $noticeHtml = (string) ob_get_clean();
            self::assertStringContainsString('11.8.6-MariaDB', $noticeHtml);
            self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $noticeHtml);
        }
    }

    public function testRuntimeGateBlocksRestPortalsShortcodeAndCronWhenUnsupportedAndMigrationsArePendingWhileKeepingAdminReachable(): void
    {
        $latest = App::migrations()->currentVersion();
        self::assertNotNull($latest);
        $latestRow = App::db()->fetchRow(
            'SELECT version, description, applied_at FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );
        self::assertIsArray($latestRow);

        // Create portal pages and a queued job BEFORE arming the block so we observe real interception.
        $patientPageId    = PatientPortalShell::ensure_portal_page();
        $staffPageId      = StaffPortalShell::ensure_portal_page();
        $managementPageId = ManagementPortalShell::ensure_portal_page();
        self::assertGreaterThan(0, $patientPageId);
        self::assertGreaterThan(0, $staffPageId);
        self::assertGreaterThan(0, $managementPageId);

        $jobId = App::jobs()->enqueue('slots.generate', ['horizon_days' => 1, 'source' => 'f2-gate-test']);
        self::assertGreaterThan(0, $jobId);

        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );

        try {
            self::assertTrue(App::migrations()->has_pending_migrations(), 'non-vacuous precondition: migration is pending');

            $this->unsupportedProbe();
            self::assertTrue(App::is_runtime_blocked_by_pending_schema());

            // (1) Anonymous Clinical REST requests return only the stable, neutral 503 envelope.
            wp_set_current_user(0);
            self::assertFalse(is_user_logged_in(), 'the leakage check must exercise an unauthenticated caller');
            $server = rest_get_server();
            foreach (
                [
                    ['GET', '/clinic/v1/health'],
                    ['GET', '/clinic/v1/patients'],
                    ['POST', '/clinic/v1/otp/request'],
                ] as [$method, $route]
            ) {
                $response = $server->dispatch(new \WP_REST_Request($method, $route));
                self::assertSame(503, $response->get_status(), "{$method} {$route} must return HTTP 503 when blocked");
                $body = $response->get_data();
                self::assertSame(RuntimeSchemaGate::PUBLIC_ERROR_CODE, $body['code'] ?? null, 'anonymous callers receive a non-environment-specific code');
                self::assertSame(RuntimeSchemaGate::NEUTRAL_MESSAGE, $body['message'] ?? null);
                self::assertSame(['status' => 503], $body['data'] ?? null, '503 envelope must carry no clinical or patient data');
                $encodedBody = (string) wp_json_encode($body);
                self::assertStringNotContainsString('11.8.6', $encodedBody, 'unauthenticated 503 does not reveal the database version');
                self::assertStringNotContainsString('MariaDB', $encodedBody, 'unauthenticated 503 does not reveal the database engine');
                self::assertStringNotContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $encodedBody, 'unauthenticated 503 does not reveal accepted environment details');
            }

            // The detailed engine/schema code is reserved for the manage_options capability.
            $diagnosticAdmin = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($diagnosticAdmin);
            self::assertTrue(current_user_can('manage_options'));
            $adminResponse = $server->dispatch(new \WP_REST_Request('GET', '/clinic/v1/health'));
            self::assertSame(503, $adminResponse->get_status());
            self::assertSame(RuntimeSchemaGate::ERROR_CODE, $adminResponse->get_data()['code'] ?? null);
            self::assertSame(RuntimeSchemaGate::NEUTRAL_MESSAGE, $adminResponse->get_data()['message'] ?? null);

            $nonAdminCallers = [
                ['patient-only', RolesAndCapabilities::ROLE_PATIENT],
                ['subscriber', 'subscriber'],
                ['staff without manage_options', RolesAndCapabilities::ROLE_DOCTOR],
            ];
            foreach ($nonAdminCallers as [$label, $role]) {
                $userId = self::factory()->user->create(['role' => $role]);
                wp_set_current_user($userId);
                self::assertTrue(is_user_logged_in(), "{$label} test caller must be logged in");
                self::assertFalse(current_user_can('manage_options'), "{$label} must not have manage_options");

                $response = $server->dispatch(new \WP_REST_Request('GET', '/clinic/v1/health'));
                self::assertSame(503, $response->get_status(), "{$label} receives HTTP 503");
                $body = $response->get_data();
                self::assertSame(RuntimeSchemaGate::PUBLIC_ERROR_CODE, $body['code'] ?? null, "{$label} receives the neutral code");
                self::assertSame(RuntimeSchemaGate::NEUTRAL_MESSAGE, $body['message'] ?? null);
                $encodedBody = (string) wp_json_encode($body);
                self::assertStringNotContainsString('ENGINE', $encodedBody, "{$label} response must not disclose engine details");
                self::assertStringNotContainsString('UNSUPPORTED', $encodedBody, "{$label} response must not disclose unsupported status");
            }

            wp_set_current_user(0);
            $anonymousResponse = $server->dispatch(new \WP_REST_Request('GET', '/clinic/v1/health'));
            self::assertSame(503, $anonymousResponse->get_status());
            self::assertSame(RuntimeSchemaGate::PUBLIC_ERROR_CODE, $anonymousResponse->get_data()['code'] ?? null);
            self::assertSame(RuntimeSchemaGate::NEUTRAL_MESSAGE, $anonymousResponse->get_data()['message'] ?? null);
            $anonymousBody = (string) wp_json_encode($anonymousResponse->get_data());
            self::assertStringNotContainsString('ENGINE', $anonymousBody);
            self::assertStringNotContainsString('UNSUPPORTED', $anonymousBody);

            // Core WordPress REST routes outside /clinic/v1 remain untouched.
            $coreResponse = $server->dispatch(new \WP_REST_Request('GET', '/wp/v2/types'));
            self::assertNotSame(503, $coreResponse->get_status(), 'non-CPMS WordPress REST routes must remain reachable');

            // (2) Frontend portals and [cpms_public_booking] shortcode render a short neutral message with no patient data.
            foreach ([$patientPageId, $staffPageId, $managementPageId] as $portalPageId) {
                $this->go_to((string) get_permalink($portalPageId));
                $resolvedTemplate = (string) apply_filters('template_include', 'index.php');
                self::assertSame(RuntimeSchemaGate::template_path(), $resolvedTemplate);

                ob_start();
                include $resolvedTemplate;
                $portalHtml = (string) ob_get_clean();

                self::assertStringContainsString('data-role="cpms-runtime-unavailable"', $portalHtml);
                self::assertStringContainsString(RuntimeSchemaGate::NEUTRAL_MESSAGE, $portalHtml);
                self::assertStringNotContainsString('داشبورد بیمار', $portalHtml);
            }
            $this->go_to('/');

            $shortcodeHtml = do_shortcode('[cpms_public_booking clinic_id="1"]');
            self::assertStringContainsString('data-role="cpms-runtime-unavailable"', $shortcodeHtml);
            self::assertStringContainsString(RuntimeSchemaGate::NEUTRAL_MESSAGE, $shortcodeHtml);

            // (3) Cron / job workers do NOT claim or run clinical jobs against old schema.
            self::assertSame(0, App::runTick(20), 'runTick() must short-circuit with 0 processed jobs when blocked');
            do_action('cpms_jobs_tick');
            App::scheduleRecurringJobs();

            $jobRow = App::db()->fetchRow(
                'SELECT status, attempts FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
                [$jobId]
            );
            self::assertIsArray($jobRow);
            self::assertSame('queued', (string) $jobRow['status'], 'queued clinical job must remain untouched');
            self::assertSame(0, (int) $jobRow['attempts'], 'queued clinical job must have 0 execution attempts');

            // (4) Administrator plugin management (plugins.php, admin notice, deactivation) stays reachable.
            $admin = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin);
            $GLOBALS['pagenow'] = 'plugins.php';

            delete_transient('cpms_migrate_lock');
            $this->invokeEnsureMigrated();

            ob_start();
            App::render_unsupported_engine_notice();
            $adminNotice = (string) ob_get_clean();
            self::assertStringContainsString('notice-error', $adminNotice);
            self::assertStringContainsString('11.8.6-MariaDB', $adminNotice);

            if (wp_next_scheduled('cpms_jobs_tick') === false) {
                wp_schedule_event(time() + 60, 'hourly', 'cpms_jobs_tick');
            }
            App::deactivate();
            self::assertFalse(wp_next_scheduled('cpms_jobs_tick'), 'plugin deactivation stays functional while blocked');
        } finally {
            // Re-insert the ledger row directly so tearDown() runs zero DDL and
            // preserves WP_UnitTestCase's outer transaction rollback.
            $stillMissing = App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
                [$latest]
            );
            if ((int) $stillMissing === 0) {
                App::db()->insert('cpms_schema_migrations', [
                    'version'     => (string) $latestRow['version'],
                    'description' => (string) $latestRow['description'],
                    'applied_at'  => (string) $latestRow['applied_at'],
                ]);
            }
        }
    }

    public function testUnreadableDatabaseEvidenceDoesNotBlockRuntimeEvenWithPendingMigrationsAndLogsThrottledWhileActivationStaysFailClosed(): void
    {
        $latest = App::migrations()->currentVersion();
        self::assertNotNull($latest);
        $latestRow = App::db()->fetchRow(
            'SELECT version, description, applied_at FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );
        self::assertIsArray($latestRow);

        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );

        try {
            self::assertTrue(App::migrations()->has_pending_migrations());

            $unreadableBefore = $this->opLogRowCount('CPMS_DB_ENGINE_EVIDENCE_UNREADABLE');

            // Unreadable query evidence -> runtime is NOT blocked, logs once per throttle window.
            App::set_db_compatibility_probe_for_tests(
                static fn (): array => DatabaseCompatibilityGate::unavailable_evidence()
            );
            self::assertFalse(App::is_runtime_blocked_by_pending_schema());
            self::assertSame($unreadableBefore + 1, $this->opLogRowCount('CPMS_DB_ENGINE_EVIDENCE_UNREADABLE'));

            // Repeat check within window is throttled.
            self::assertFalse(App::is_runtime_blocked_by_pending_schema());
            self::assertSame($unreadableBefore + 1, $this->opLogRowCount('CPMS_DB_ENGINE_EVIDENCE_UNREADABLE'));

            // Garbled string evidence -> also NOT blocked at runtime, while activation remains fail-closed.
            App::set_db_compatibility_probe_for_tests(
                static fn (): array => DatabaseCompatibilityGate::check('garbage', 'MySQL')
            );
            self::assertFalse(App::is_runtime_blocked_by_pending_schema());

            $restResponse = rest_get_server()->dispatch(new \WP_REST_Request('GET', '/clinic/v1/health'));
            self::assertNotSame(503, $restResponse->get_status(), 'garbled DB version evidence must not trigger the 503 gate');

            $die = $this->captureWpDie(static function (): void {
                App::activate();
            });
            self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $die['message']);
        } finally {
            $stillMissing = App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
                [$latest]
            );
            if ((int) $stillMissing === 0) {
                App::db()->insert('cpms_schema_migrations', [
                    'version'     => (string) $latestRow['version'],
                    'description' => (string) $latestRow['description'],
                    'applied_at'  => (string) $latestRow['applied_at'],
                ]);
            }
        }
    }

    public function testDatabaseCompatibilityProbeIsMemoizedPerRequestOnlyWithoutCrossRequestCaching(): void
    {
        $calls = 0;
        App::set_db_compatibility_probe_for_tests(static function () use (&$calls): array {
            ++$calls;
            return DatabaseCompatibilityGate::check('11.8.6-MariaDB', 'mariadb.org binary distribution');
        });

        // Multiple gate evaluations in the same request read the probe once.
        App::is_runtime_blocked_by_pending_schema();
        App::is_runtime_blocked_by_pending_schema();
        App::is_database_engine_supported();
        self::assertSame(1, $calls, 'probe must be memoized within a single request');

        // Verify no option or transient caches the probe result across requests
        // (only the log-noise throttle transient is written).
        foreach ($this->optionNames() as $optName) {
            self::assertStringNotContainsString(
                'db_compatibility',
                $optName,
                'probe result must never be persisted in wp_options/transients'
            );
        }

        // Simulate a fresh request by resetting in-memory request state: the probe runs again.
        App::reset_database_compatibility_state_for_tests();
        App::set_db_compatibility_probe_for_tests(static function () use (&$calls): array {
            ++$calls;
            return DatabaseCompatibilityGate::check('8.0.36', 'MySQL Community Server - GPL');
        });
        self::assertTrue(App::is_database_engine_supported());
        self::assertSame(2, $calls, 'a fresh request re-evaluates the probe instead of reading a cross-request cache');
    }
}
