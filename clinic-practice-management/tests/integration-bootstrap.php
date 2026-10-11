<?php
/**
 * Bootstrap تست‌های Integration — توسط WP Test Suite (CI) لود می‌شود.
 * استفاده: phpunit --testsuite Integration --bootstrap tests/integration-bootstrap.php
 */

declare(strict_types=1);

use ClinicCore\Bootstrap\App;

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wp-tests';

if (!is_file($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WP test suite not found in {$_tests_dir}\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

// PHPUnit Polyfills — الزام WP Test Library (از 6.7_TestCase از طریق Adapter
// به polyfills وابسته است و PHPUnit 10/11 را پشتیبانی می‌کند).
$_polyfills = dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if (is_file($_polyfills)) {
    require_once $_polyfills;
} elseif (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    // مسیر صریح برای وقتی vendor جای دیگری نصب شده است
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');
}
unset($_polyfills);

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/clinic-practice-management.php';
});

require $_tests_dir . '/includes/bootstrap.php';

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
App::migrations()->migrate();

/*
 * بازنویسی تراکنش‌های Service به SAVEPOINT (الگوی خود WP برای CREATE TABLE):
 * WP Test Suite داخل هر تست یک تراکنش باز می‌کند و در tear_down همه را
 * ROLLBACK می‌کند؛ اما START TRANSACTION سرویس داخل آن = COMMIT ضمنی کل
 * Fixtureهای تست → نشت داده بین تست‌ها (Duplicate keyهای پی‌درپی).
 * راه‌حل: filter روی wpdb (فقط در تست) افعال تراکنشِ علامت‌گذاری‌شده با
 * نشانگر cpms را به SAVEPOINT/RELEASE/ROLLBACK-TO تبدیل می‌کند — تراکنش بیرونی
 * تست دست‌نخورده می‌ماند و Production این filter را ندارد.
 */
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
