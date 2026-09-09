<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use RuntimeException;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Phase 2 — اثبات isolation راه‌حل real-table (RealTableMigrations).
 *
 * سه ادعای مالک، سه تست:
 *
 *  1) خارج از MigrationTest، رفتار temporary-table وردپرس دست‌نخورده است —
 *     CREATE TABLE از طریق wpdb همچنان به TEMPORARY تبدیل می‌شود.
 *  2) داخل پنجرهٔ migration lifecycle، جداول cpms با InnoDB واقعی ساخته
 *     می‌شوند (FK دارند؛ جدول موتی FK نمی‌پذیرد) و پس از کل lifecycle،
 *     همچنان جدول واقعی‌اند (سایهٔ موقت = شکست تست).
 *  3) تعلیق حتی در صورت exception در callback، در finally دقیقاً restore
 *     می‌شود (اثبات رفتاری + ساختاری).
 *
 * ترتیب اجرا: این کلاس بعد از MigrationTest/Phase2SchemaTest اجرا می‌شود
 * (ترتیب الفبایی) — یعنی ادعای (2) دقیقاً بعد از کل lifecycle بررسی می‌شود.
 */
final class TempTableIsolationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testWpTemporaryRewritesStayActiveOutsideMigrationTests(): void
    {
        global $wpdb;

        // فیلترهای خود WP (start_transaction) باید فعال باشند
        self::assertNotFalse(
            has_filter('query', [$this, '_create_temporary_tables']),
            'فیلتر temporary-table وردپرس باید در تست‌های غیر-migration فعال باشد.'
        );
        self::assertNotFalse(
            has_filter('query', [$this, '_drop_temporary_tables']),
            'فیلتر drop-temporary وردپرس باید در تست‌های غیر-migration فعال باشد.'
        );

        // اثبات رفتاری: CREATE TABLE → TEMPORARY (رفتار بکر WP Test Suite)
        $probe = $wpdb->prefix . 'tt_isolation_probe';
        try {
            $wpdb->query("CREATE TABLE {$probe} (id INT) ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row("SHOW CREATE TABLE {$probe}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertStringContainsString(
                'TEMPORARY',
                (string) ($row['Create Table'] ?? ''),
                'CREATE TABLE خارج از پنجرهٔ real-table باید TEMPORARY شود (رفتار WP).'
            );
        } finally {
            // DROP هم rewrite می‌شود (DROP TEMPORARY) — جدول واقعی‌ای وجود ندارد
            $wpdb->query("DROP TABLE {$probe}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    public function testMigrationTablesRemainRealInnoDBWithForeignKeysAfterLifecycle(): void
    {
        global $wpdb;

        // پس از کل lifecycle تست‌های migration، نسخهٔ نهایی اعمال شده...
        self::assertSame('2026_09_09_0018', App::migrations()->currentVersion());

        // ...و جداول دارای FK، جدولِ واقعی‌اند — نه سایهٔ موقتِ حاصل از فیلتر WP
        foreach (['cpms_locations', 'cpms_schedule_slots'] as $short) {
            $table = App::db()->table($short);
            $row = $wpdb->get_row('SHOW CREATE TABLE ' . $table, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $create = (string) ($row['Create Table'] ?? '');
            self::assertNotSame('', $create, $table . ' باید وجود داشته باشد.');
            self::assertStringNotContainsString(
                'TEMPORARY',
                $create,
                $table . ' جدول واقعی است؛ سایهٔ موقت یعنی باگِ isolation.'
            );
            self::assertStringContainsString(
                'FOREIGN KEY',
                $create,
                $table . ' باید FK واقعی داشته باشد (جدول موتی FK نمی‌پذیرد — errno 150).'
            );
        }
    }

    public function testSuspensionRestoresRewritesEvenWhenCallbackThrows(): void
    {
        global $wpdb;

        $probe = $wpdb->prefix . 'tt_isolation_probe';

        try {
            try {
                $this->withRealTables(static function () use ($probe): void {
                    // داخل پنجره: CREATE نباید TEMPORARY شود — جدول واقعی است
                    global $wpdb;
                    $wpdb->query("CREATE TABLE {$probe} (id INT) ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    try {
                        $row = $wpdb->get_row("SHOW CREATE TABLE {$probe}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                        $create = (string) ($row['Create Table'] ?? '');
                        if (!str_contains($create, 'CREATE TABLE') || str_contains($create, 'TEMPORARY')) {
                            throw new RuntimeException('داخل پنجرهٔ real-table، CREATE نباید TEMPORARY شود.');
                        }
                    } finally {
                        // پاک‌سازی جدول واقعی باید خودش داخل پنجره باشد (وگرنه
                        // DROP بیرون پنجره به DROP TEMPORARY تبدیل و no-op می‌شود)
                        $wpdb->query("DROP TABLE {$probe}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    }

                    // حالا استثنا → finally بیرونی باید فیلترها را restore کند
                    throw new RuntimeException('deliberate');
                });
                self::fail('callback باید exception می‌انداخت.');
            } catch (RuntimeException $e) {
                self::assertSame('deliberate', $e->getMessage(), 'استثنای عمدی callback باید منتقل شود.');
            }

            // پس از finally: فیلترها restore شده‌اند — ساختاری و رفتاری
            self::assertNotFalse(
                has_filter('query', [$this, '_create_temporary_tables']),
                'فیلتر temporary-table باید پس از exception در callback، restore شده باشد.'
            );
            $wpdb->query("CREATE TABLE {$probe} (id INT) ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row("SHOW CREATE TABLE {$probe}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertStringContainsString(
                'TEMPORARY',
                (string) ($row['Create Table'] ?? ''),
                'پس از restore، CREATE باید دوباره TEMPORARY شود (رفتار WP).'
            );
        } finally {
            $wpdb->query("DROP TABLE {$probe}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }
}
