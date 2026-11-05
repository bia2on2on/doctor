<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate;
use WP_UnitTestCase;

/**
 * N1 — پیش‌بررسیِ موتورِ دیتابیس در App::activate() و App::ensureMigrated().
 *
 * قراردادِ اثبات‌شده در این فایل:
 *  - فعال‌سازی روی موتورِ پشتیبانی‌نشده (شبیه‌سازی با probe درون‌تستی، مثلاً
 *    همان MariaDB 11.8.6 زنده) پیش از هر اثرِ جانبی با wp_die خوانا رد می‌شود:
 *    هیچ نقش، ردیفِ ledger، رویدادِ cron یا Optionی نوشته نمی‌شود (ردِ صفر).
 *  - پیامِ wp_die موتور/نسخهٔ شناسایی‌شده و مجموعهٔ پذیرفته‌شده (واژگانِ عینِ
 *    مهاجرت 0027) را دارد و back link می‌خواهد.
 *  - فعال‌سازی روی موتورِ مجاز دقیقاً مثلِ قبل کار می‌کند (نقش‌ها، مهاجرت‌های
 *    کامل، cron، CPMS_ACTIVATED) و «فعال‌سازی مجدد پس از رد» هم همین‌طور است.
 *  - ensureMigrated() روی موتورِ پشتیبانی‌نشده نه exception می‌دهد و نه
 *    migrate() صدا می‌زند؛ یک OpLogِ throttled و یک اعلانِ مدیریتی می‌سازد.
 *
 * محدودیتِ اعلام‌شده: گاردِ WP_SANDBOX_SCRAPING در boot() (که نقش‌ها را در همان
 * include فعال‌سازی به activate() موکول می‌کند تا ردِ فعال‌سازی هیچ نقشی روی
 * سایت نگذارد) اینجا فقط به‌صورت source-level اثبات می‌شود — ثابتِ PHP در
 * طولِ یک process تست قابلِ تعریف/حذفِ انتخابی نیست؛ اثباتِ runtime آن روی
 * فعال‌سازیِ واقعی (installer) باقی می‌ماند.
 */
final class AppActivationDatabaseGateTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        App::resetDatabaseCompatibilityStateForTests();
        delete_transient('cpms_migrate_lock');

        foreach (['pagenow'] as $key) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        App::setDbCompatibilityProbeForTests(null);
        App::resetDatabaseCompatibilityStateForTests();
        delete_transient('cpms_migrate_lock');
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
        App::setDbCompatibilityProbeForTests(
            static fn (): array => DatabaseCompatibilityGate::check('11.8.6-MariaDB', 'mariadb.org binary distribution')
        );
    }

    private function supportedProbe(): void
    {
        App::setDbCompatibilityProbeForTests(
            static fn (): array => DatabaseCompatibilityGate::check('8.0.36', 'MySQL Community Server - GPL')
        );
    }

    private function invokeEnsureMigrated(): void
    {
        $method = new \ReflectionMethod(App::class, 'ensureMigrated');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    private function unsupportedOpLogRows(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_op_log') . ' WHERE message = %s',
            ['CPMS_DB_ENGINE_UNSUPPORTED']
        );
    }

    // ============================ activation ============================

    public function testActivationIsRefusedOnAnUnsupportedEngineWithZeroResidue(): void
    {
        $rolesBefore   = $this->roleNames();
        $optionsBefore = $this->optionNames();
        $ledgerBefore  = $this->ledgerVersions();
        $cronBefore    = $this->cronEvents();
        $tablesBefore  = $this->cpmsTables();

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
        self::assertSame(0, $this->unsupportedOpLogRows(), 'the refused activation itself logs nothing (OpLog table may not even exist on a fresh site)');
    }

    public function testActivationOnASupportedEngineBehavesAsToday(): void
    {
        // No probe: the real engine-evidence path (SELECT VERSION() through CpmsDb).
        // On the CI integration lanes (MySQL 8 / MariaDB 11.4.13) this is supported.
        // Same discovery pattern as MigrationRunner::migrationFiles() — the runner
        // itself lives in the same directory and must not be counted.
        $migrationFiles = glob(CPMS_PLUGIN_DIR . 'src/Migrations/????_??_??_????_*.php') ?: [];

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
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_op_log') . ' WHERE message = %s',
            ['CPMS_ACTIVATED']
        ), 'CPMS_ACTIVATED is logged exactly as before');
    }

    public function testReactivationAfterARefusedActivationBehavesExactlyAsToday(): void
    {
        $optionsBefore = $this->optionNames();
        $cronBefore    = $this->cronEvents();
        $ledgerBefore  = $this->ledgerVersions();

        // First attempt: refused on an unsupported engine — zero residue.
        $this->unsupportedProbe();
        $die = $this->captureWpDie(static function (): void {
            App::activate();
        });
        self::assertStringContainsString(DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $die['message']);
        self::assertSame($optionsBefore, $this->optionNames());
        self::assertSame($cronBefore, $this->cronEvents());
        self::assertSame($ledgerBefore, $this->ledgerVersions());

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
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_op_log') . ' WHERE message = %s',
            ['CPMS_ACTIVATED']
        ), 'CPMS_ACTIVATED is logged for the successful activation');
    }

    // ============================ ensureMigrated ============================

    public function testEnsureMigratedOnAnUnsupportedEngineDoesNotThrowAndDoesNotMigrate(): void
    {
        // Make one migration genuinely pending (0031's up() is idempotent by
        // design: CREATE TABLE IF NOT EXISTS + INSERT IGNORE), so "did
        // ensureMigrated call migrate()?" is observable through the ledger.
        $latest = App::migrations()->currentVersion();
        self::assertNotNull($latest);
        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_schema_migrations') . ' WHERE version = %s',
            [$latest]
        );
        self::assertNotContains($latest, $this->ledgerVersions());

        $this->unsupportedProbe();

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
        self::assertSame(1, $this->unsupportedOpLogRows(), 'exactly one throttled OpLog row on first sight');

        // Throttle: a second run within the window logs nothing more.
        delete_transient('cpms_migrate_lock');
        $this->invokeEnsureMigrated();
        self::assertSame(1, $this->unsupportedOpLogRows(), 'the OpLog write is throttled per window');
        self::assertNotContains($latest, $this->ledgerVersions());

        // Positive control: with a supported engine the SAME pending migration is
        // applied through ensureMigrated() — only the gate skips migrate().
        App::setDbCompatibilityProbeForTests(null);
        App::resetDatabaseCompatibilityStateForTests();
        delete_transient('cpms_migrate_lock');
        $this->invokeEnsureMigrated();
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
        App::resetDatabaseCompatibilityStateForTests();
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
        self::assertLessThan(
            strpos($activateBody, 'RolesAndCapabilities::register();'),
            strpos($activateBody, 'databaseCompatibility()'),
            'the engine pre-check must run before roles in activate()'
        );
    }
}
