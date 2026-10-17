<?php
/**
 * Bootstrap تست‌های Integration — توسط WP Test Suite (CI) لود می‌شود.
 * استفاده: phpunit --testsuite Integration --bootstrap tests/integration-bootstrap.php
 */

declare(strict_types=1);

use ClinicCore\Bootstrap\App;

/**
 * Optional CI-only startup markers. These write fixed allowlisted tokens only;
 * exception text, paths, SQL, and PHP output are never copied into this file.
 */
if (!function_exists('cpms_mariadb_startup_diagnostic_mark')) {
    function cpms_mariadb_startup_diagnostic_mark(string $stage): void
    {
        if (getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS') !== '1') {
            return;
        }
        static $allowedStages = [
            'INTEGRATION_BOOTSTRAP_ENTRY',
            'WP_TEST_LIBRARY_CHECK',
            'WP_TEST_LIBRARY_FUNCTIONS_REQUIRE',
            'WP_TEST_LIBRARY_FUNCTIONS_READY',
            'PHPUNIT_POLYFILLS_REQUIRE',
            'PHPUNIT_POLYFILLS_READY',
            'WORDPRESS_TEST_LIBRARY_BOOTSTRAP',
            'CPMS_PLUGIN_BOOTSTRAP',
            'WORDPRESS_TEST_LIBRARY_READY',
            'SQL_FAILURE_LEDGER_REQUIRE',
            'SQL_FAILURE_LEDGER_READY',
            'MIGRATION_BOOTSTRAP',
            'MIGRATION_BOOTSTRAP_READY',
            'BOOTSTRAP_SUPPORT_SETUP',
            'BOOTSTRAP_COMPLETE',
        ];
        if (!in_array($stage, $allowedStages, true)) {
            $stage = 'UNKNOWN_BOOTSTRAP_STAGE';
        }
        $GLOBALS['__cpms_mariadb_startup_stage'] = $stage;
        $path = getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS_FILE');
        if (!is_string($path) || !preg_match('~\A/tmp/mariadb-compat/[A-Za-z0-9._-]+\.private\z~D', $path)) {
            return;
        }
        try {
            @file_put_contents($path, 'STAGE=' . $stage . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $ignored) {
            // Diagnostics must never change bootstrap/test behavior.
        }
    }
}

if (!function_exists('cpms_mariadb_startup_diagnostic_fail')) {
    function cpms_mariadb_startup_diagnostic_fail(string $category): void
    {
        if (getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS') !== '1'
            || isset($GLOBALS['__cpms_mariadb_startup_category'])) {
            return;
        }
        static $allowedCategories = [
            'WP_TEST_LIBRARY_MISSING',
            'PHPUNIT_CONFIG_INVALID',
            'BOOTSTRAP_REQUIRE_FAILURE',
            'WORDPRESS_BOOTSTRAP_FAILURE',
            'MIGRATION_BOOTSTRAP_FAILURE',
            'PHP_FATAL_BEFORE_TESTS',
            'UNKNOWN_STARTUP_FAILURE',
        ];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'UNKNOWN_STARTUP_FAILURE';
        }
        $stage = $GLOBALS['__cpms_mariadb_startup_stage'] ?? 'UNKNOWN_BOOTSTRAP_STAGE';
        $GLOBALS['__cpms_mariadb_startup_category'] = $category;
        $path = getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS_FILE');
        if (!is_string($path) || !preg_match('~\A/tmp/mariadb-compat/[A-Za-z0-9._-]+\.private\z~D', $path)) {
            return;
        }
        try {
            @file_put_contents(
                $path,
                'CATEGORY=' . $category . PHP_EOL . 'FAILURE_STAGE=' . $stage . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $ignored) {
            // Diagnostics must never change bootstrap/test behavior.
        }
    }
}

if (getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS') === '1') {
    $GLOBALS['__cpms_mariadb_bootstrap_complete'] = false;
}
cpms_mariadb_startup_diagnostic_mark('INTEGRATION_BOOTSTRAP_ENTRY');
if (getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS') === '1') {
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['__cpms_mariadb_startup_category'])) {
            return;
        }
        $stage = $GLOBALS['__cpms_mariadb_startup_stage'] ?? 'INTEGRATION_BOOTSTRAP_ENTRY';
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!empty($GLOBALS['__cpms_mariadb_bootstrap_complete'])) {
            if (is_array($lastError) && in_array($lastError['type'] ?? null, $fatalTypes, true)) {
                cpms_mariadb_startup_diagnostic_fail('UNKNOWN_STARTUP_FAILURE');
            }
            return;
        }
        if (is_array($lastError) && in_array($lastError['type'] ?? null, $fatalTypes, true)) {
            cpms_mariadb_startup_diagnostic_fail('PHP_FATAL_BEFORE_TESTS');
            return;
        }
        $categoryByStage = [
            'WP_TEST_LIBRARY_CHECK' => 'WP_TEST_LIBRARY_MISSING',
            'WP_TEST_LIBRARY_FUNCTIONS_REQUIRE' => 'BOOTSTRAP_REQUIRE_FAILURE',
            'PHPUNIT_POLYFILLS_REQUIRE' => 'BOOTSTRAP_REQUIRE_FAILURE',
            'WORDPRESS_TEST_LIBRARY_BOOTSTRAP' => 'WORDPRESS_BOOTSTRAP_FAILURE',
            'CPMS_PLUGIN_BOOTSTRAP' => 'BOOTSTRAP_REQUIRE_FAILURE',
            'SQL_FAILURE_LEDGER_REQUIRE' => 'BOOTSTRAP_REQUIRE_FAILURE',
            'MIGRATION_BOOTSTRAP' => 'MIGRATION_BOOTSTRAP_FAILURE',
            'BOOTSTRAP_SUPPORT_SETUP' => 'BOOTSTRAP_REQUIRE_FAILURE',
        ];
        cpms_mariadb_startup_diagnostic_fail(
            $categoryByStage[$stage] ?? 'UNKNOWN_STARTUP_FAILURE'
        );
    });
}

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wp-tests';
cpms_mariadb_startup_diagnostic_mark('WP_TEST_LIBRARY_CHECK');

if (!is_file($_tests_dir . '/includes/functions.php')) {
    cpms_mariadb_startup_diagnostic_fail('WP_TEST_LIBRARY_MISSING');
    fwrite(STDERR, "WP test suite not found.\n");
    exit(1);
}

cpms_mariadb_startup_diagnostic_mark('WP_TEST_LIBRARY_FUNCTIONS_REQUIRE');
try {
    require_once $_tests_dir . '/includes/functions.php';
} catch (\Throwable $error) {
    cpms_mariadb_startup_diagnostic_fail('BOOTSTRAP_REQUIRE_FAILURE');
    throw $error;
}
cpms_mariadb_startup_diagnostic_mark('WP_TEST_LIBRARY_FUNCTIONS_READY');

// PHPUnit Polyfills — الزام WP Test Library (از 6.7_TestCase از طریق Adapter
// به polyfills وابسته است و PHPUnit 10/11 را پشتیبانی می‌کند).
cpms_mariadb_startup_diagnostic_mark('PHPUNIT_POLYFILLS_REQUIRE');
try {
    $_polyfills = dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
    if (is_file($_polyfills)) {
        require_once $_polyfills;
    } elseif (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
        // مسیر صریح برای وقتی vendor جای دیگری نصب شده است
        define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');
    }
    unset($_polyfills);
} catch (\Throwable $error) {
    cpms_mariadb_startup_diagnostic_fail('BOOTSTRAP_REQUIRE_FAILURE');
    throw $error;
}
cpms_mariadb_startup_diagnostic_mark('PHPUNIT_POLYFILLS_READY');

cpms_mariadb_startup_diagnostic_mark('WORDPRESS_TEST_LIBRARY_BOOTSTRAP');
try {
    tests_add_filter('muplugins_loaded', static function (): void {
        cpms_mariadb_startup_diagnostic_mark('CPMS_PLUGIN_BOOTSTRAP');
        try {
            require dirname(__DIR__) . '/clinic-practice-management.php';
        } catch (\Throwable $error) {
            cpms_mariadb_startup_diagnostic_fail('BOOTSTRAP_REQUIRE_FAILURE');
            throw $error;
        }
        cpms_mariadb_startup_diagnostic_mark('WORDPRESS_TEST_LIBRARY_BOOTSTRAP');
    });

    require $_tests_dir . '/includes/bootstrap.php';
} catch (\Throwable $error) {
    cpms_mariadb_startup_diagnostic_fail('WORDPRESS_BOOTSTRAP_FAILURE');
    throw $error;
}
cpms_mariadb_startup_diagnostic_mark('WORDPRESS_TEST_LIBRARY_READY');

/*
 * ── دفترِ تشخیصیِ خطاهای SQL (فقط تست؛ هیچ اثرِ محصولی) ──────────────────────
 *
 * مسیرِ محصول متن/کدِ خطای واقعیِ MySQL را دور می‌ریزد: `CpmsDb` بیرون از
 * MigrationRunner در حالت غیر-strict است و `CustomRoleRepository` هر INSERT
 * ناموفق را با پیامِ عمومیِ «... already defined ...» گزارش می‌کند. وردپرس خودش
 * منبعِ کاملِ خطا را در `$GLOBALS['EZSQL_ERROR']` نگه می‌دارد (در `print_error()`
 * پیش از بررسی `suppress_errors` اضافه می‌شود)، پس با یک dump در shutdown می‌توان
 * خطای واقعی را در لاگ CI دید. این کلاس هیچ انتظار تست، SQL، اسکیما، مجوزدهی یا
 * رفتار محصولی را تغییر نمی‌دهد؛ فقط می‌خواند و به STDERR می‌نویسد.
 */
cpms_mariadb_startup_diagnostic_mark('SQL_FAILURE_LEDGER_REQUIRE');
try {
    require_once __DIR__ . '/Support/WpSqlFailureLedger.php';
    \ClinicCore\Tests\Support\WpSqlFailureLedger::register();
} catch (\Throwable $error) {
    cpms_mariadb_startup_diagnostic_fail('BOOTSTRAP_REQUIRE_FAILURE');
    throw $error;
}
cpms_mariadb_startup_diagnostic_mark('SQL_FAILURE_LEDGER_READY');

/*
 * جداول افزونه را یک‌بار «واقعی» می‌سازیم — بعد از bootstrap وردپرس.
 *
 * دلیل: WP Test Suite داخل هر تست، فیلترهایی روی query فعال می‌کند که
 * `CREATE TABLE` را به `CREATE TEMPORARY TABLE` بازنویسی می‌کنند
 * (ابزار ایزوله‌سازی خود WP برای جداول core). جدول موقت:
 *   ۱) FOREIGN KEY به آن نمی‌توان زد (MySQL 1215) → مهاجرت‌های دارای FK
 *      به‌صورت خاموش شکست می‌خوردند و جداول اصلاً ساخته نمی‌شدند؛
 *   ۲) فقط روی اتصالِ همان تست دیده می‌شود.
 * با ساخت واقعیِ یک‌بار در این‌جا، migrate داخل setUp هر تست به no-op
 * تبدیل می‌شود و ایزوله‌سازی داده همچنان از طریق rollback تراکنش هر تست
 * برقرار است (الگوی استاندارد تست Integration افزونه‌های WP).
 */
cpms_mariadb_startup_diagnostic_mark('MIGRATION_BOOTSTRAP');
try {
    App::migrations()->migrate();
} catch (\Throwable $error) {
    cpms_mariadb_startup_diagnostic_fail('MIGRATION_BOOTSTRAP_FAILURE');
    throw $error;
}
cpms_mariadb_startup_diagnostic_mark('MIGRATION_BOOTSTRAP_READY');

/*
 * بازنویسی تراکنش‌های Service به SAVEPOINT (الگوی خود WP برای CREATE TABLE):
 * WP Test Suite داخل هر تست یک تراکنش باز می‌کند و در tear_down همه را
 * ROLLBACK می‌کند؛ اما START TRANSACTION سرویس داخل آن = COMMIT ضمنی کل
 * Fixtureهای تست → نشت داده بین تست‌ها (Duplicate keyهای پی‌درپی).
 * راه‌حل: filter روی wpdb (فقط در تست) افعال تراکنشِ علامت‌گذاری‌شده با
 * نشانگر cpms را به SAVEPOINT/RELEASE/ROLLBACK-TO تبدیل می‌کند — تراکنش بیرونی
 * تست دست‌نخورده می‌ماند و Production این filter را ندارد.
 */
cpms_mariadb_startup_diagnostic_mark('BOOTSTRAP_SUPPORT_SETUP');
$GLOBALS['__cpms_sp_stack'] = [];
$GLOBALS['__cpms_ddl_seen_since_start'] = false;
tests_add_filter(
    'query',
    static function ( $query ) {
        $q = trim( (string) $query );
        // Strip leading SQL comments (/* ... */) for DDL detection — callers
        // may prefix DDL with comments (e.g. WordPress or test helpers).
        $q_nocomments = preg_replace( '/\\A\\s*(?:\\/\\*.*?\\*\\/\\s*)*/s', '', $q );
        $q_nocomments = trim( (string) $q_nocomments );
        // Real (non-TEMPORARY) DDL performs an implicit COMMIT that ends the
        // enclosing transaction and destroys its savepoints. The WP test harness
        // normally hides CREATE/DROP TABLE behind CREATE TEMPORARY TABLE /
        // DROP TEMPORARY TABLE (no implicit commit). When tests use
        // withRealTables() the temporary rewrite is suspended and real DDL is
        // executed inside transactional(). We must distinguish real DDL from
        // temporary-table operations, preserve the original DDL execution, and
        // only treat an empty-stack COMMIT/ROLLBACK as a harmless no-op when
        // a real DDL was observed since the last START — otherwise an
        // unexpected empty stack must remain observable (fail loudly) and must
        // not be silently hidden or claim rollback of committed DDL.
        if ( preg_match( '/\\A\\s*(CREATE|DROP|ALTER|TRUNCATE|RENAME)\\s+/i', $q_nocomments ) ) {
            // For TEMPORARY check, normalize inline comments to space so
            // `CREATE /* comment */ TEMPORARY`, `CREATE -- comment\nTEMPORARY`,
            // and `CREATE # comment\nTEMPORARY` are correctly recognised as
            // temporary (not real). Real DDL `CREATE /* comment */ TABLE` remains
            // real because it lacks TEMPORARY. Only valid MySQL comment syntax
            // is handled (-- requires following whitespace, # to end of line).
            $q_temp_normalized = preg_replace( '/\\/\\*.*?\\*\\//s', ' ', $q_nocomments );
            $q_temp_normalized = preg_replace( '/--[ \t].*?(?:\\r\\n|\\n|\\r|$)/', ' ', (string) $q_temp_normalized );
            $q_temp_normalized = preg_replace( '/#[^\\r\\n]*/', ' ', (string) $q_temp_normalized );
            $q_temp_normalized = preg_replace( '/\\s+/', ' ', (string) $q_temp_normalized );
            $q_temp_normalized = trim( (string) $q_temp_normalized );
            $is_temporary      = (bool) preg_match( '/\\A\\s*(CREATE|DROP)\\s+TEMPORARY\\s+/i', $q_temp_normalized );
            if ( ! $is_temporary ) {
                // Mark that a real DDL was seen since the last START; do not
                // clear the stack yet — wait until the next COMMIT/ROLLBACK
                // where we can prove the DDL boundary. This preserves the
                // original DDL execution and keeps genuine RELEASE/ROLLBACK
                // failures observable when no DDL was involved.
                $GLOBALS['__cpms_ddl_seen_since_start'] = true;

                return $query;
            }
        }
        if ( ! str_starts_with( $q, '/*cpms*/' ) ) {
            return $query;
        }
        $verb  = trim( substr( $q, strlen( '/*cpms*/' ) ) );
        $stack = &$GLOBALS['__cpms_sp_stack'];
        if ( 'START TRANSACTION' === $verb ) {
            $name = 'cpms_sp_' . count( $stack );
            $stack[] = $name;
            $GLOBALS['__cpms_ddl_seen_since_start'] = false;

            return 'SAVEPOINT ' . $name;
        }
        if ( 'COMMIT' === $verb ) {
            // Real DDL performs an implicit COMMIT that destroys all savepoints.
            // If a real DDL was observed since the last START, the stack is stale
            // (savepoints no longer exist) — treat COMMIT as a harmless no-op
            // and clear the entire stack, regardless of whether the stack is
            // empty or still contains entries.
            if ( ! empty( $GLOBALS['__cpms_ddl_seen_since_start'] ) ) {
                $GLOBALS['__cpms_ddl_seen_since_start'] = false;
                $GLOBALS['__cpms_sp_stack'] = [];

                return 'SELECT 1';
            }
            $name = array_pop( $stack );
            if ( null === $name ) {
                // No DDL boundary proven — unexpected empty stack must fail loudly.
                return 'RELEASE SAVEPOINT cpms_sp_does_not_exist';
            }

            return 'RELEASE SAVEPOINT ' . $name;
        }
        if ( 'ROLLBACK' === $verb ) {
            if ( ! empty( $GLOBALS['__cpms_ddl_seen_since_start'] ) ) {
                $GLOBALS['__cpms_ddl_seen_since_start'] = false;
                $GLOBALS['__cpms_sp_stack'] = [];

                return 'SELECT 1';
            }
            $name = array_pop( $stack );
            if ( null === $name ) {
                return 'ROLLBACK TO SAVEPOINT cpms_sp_does_not_exist';
            }

            return 'ROLLBACK TO SAVEPOINT ' . $name;
        }

        return $query;
    },
    20
);

/*
 * ── Fixture عضویت (C6 — Trusted REST Context) ─────────────────────────────
 *
 * تزریق سراسری خودکار عضویت از طریق hook روی `set_user_role` (که در
 * f88fcdc موقتاً افزوده شده بود) حذف شد. دلایل:
 *  ۱) وابستگی به ترتیب اجرای تست‌ها می‌ساخت (نتیجهٔ ساخت کاربر به «تعداد
 *     Clinicها در همان لحظه» گره می‌خورد)؛
 *  ۲) coupling پنهانِ fixture — تست‌ها بدون هیچ خطِ دیدنی عضویت می‌گرفتند؛
 *  ۳) با `create_membership` صریحِ خودِ تست‌ها collision می‌داد؛
 *  ۴) مهم‌تر از همه: حسِ اعتماد‌به‌نفس کاذب دربارهٔ provisioning محصول
 *     می‌ساخت، درحالی‌که Production هیچ‌چیز از این دست ندارد و نباید داشته
 *     باشد (نقش سراسری WP رابطهٔ tenant را تعریف نمی‌کند).
 *
 * در مقابل، یک helper **صریح** ارائه می‌شود: هر تستی که «کاربر staff با
 * عضویت فعال» را مدل می‌کند، همان‌جا و با شناسهٔ واقعی Clinic صداش می‌زند.
 * تست‌های patient / non-member / suspended عمداً صداش نمی‌زنند و صریح
 * می‌مانند.
 */
if (!function_exists('cpms_test_seed_membership')) {
    /**
     * عضویت فعالِ صریح و idempotent روی یک Clinic مشخص.
     *
     * @param int         $userId  کاربر WP (کاربرِ واقعیِ ساخته‌شده در تست)
     * @param int         $clinicId شناسهٔ واقعی Clinic (هیچ پیش‌فرضی ندارد)
     * @param string      $roleKey نقش عضویت در همان Clinic
     *
     * @return int شناسهٔ عضویت
     */
    function cpms_test_seed_membership(int $userId, int $clinicId, string $roleKey = 'cpms_secretary'): int
    {
        if ($userId <= 0 || $clinicId <= 0) {
            throw new \InvalidArgumentException('seed_membership: user و clinic باید صریح و مثبت باشند.');
        }
        $service = App::membership_service();
        $existing = $service->membership_for($clinicId, $userId);
        if ($existing !== null) {
            // idempotency — هرگز duplicate نینداز؛ وضعیت قبلی را نگه‌دار.
            return (int) $existing['id'];
        }

        return $service->create_membership($clinicId, $userId, $roleKey);
    }
}

cpms_mariadb_startup_diagnostic_mark('BOOTSTRAP_COMPLETE');
if (getenv('CPMS_MARIADB_STARTUP_DIAGNOSTICS') === '1') {
    $GLOBALS['__cpms_mariadb_bootstrap_complete'] = true;
}
