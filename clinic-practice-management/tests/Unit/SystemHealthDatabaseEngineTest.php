<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use PHPUnit\Framework\TestCase;

/**
 * CPMS Classic Admin UX (D) — تفکیکِ موتورِ دیتابیس و سیاستِ MariaDB.
 *
 * ریشهٔ نقصِ پیشین: هر مدرکِ MariaDB به `UNKNOWN` نگاشت می‌شد و برچسبِ ردیف
 * همیشه «(MySQL)» بود؛ یعنی MariaDB به‌صورتِ MySQL 8 بازنمایی می‌شد و نسخه‌هایِ
 * واقعاً آزموده‌شدهٔ MariaDB هم هرگز «سازگار» گزارش نمی‌شدند. همچنین رقمِ نسخهٔ
 * مدرنِ MariaDB برای بیلدهای کلاینتِ قدیمی (`5.5.5-10.11.2-MariaDB`) اشتباه
 * خوانده می‌شد.
 *
 * شواهدِ واقعیِ سیاستِ جدید (هیچ نسخهٔ دیگری «پشتیبانی‌شده» ادعا نمی‌شود):
 *  - `.github/workflows/ci.yml` → سرویسِ `mariadb:11.4.13` (آزموده)
 *  - `.github/workflows/mariadb-10.11-compat.yml` → MariaDB 10.11.19 (هفتگی)
 *
 * این کلاس Unit است (بدون WP/DB): منطق خالص است و از data provider استفاده
 * نمی‌شود تا همان مجموعه روی PHPUnit 9.6/10.5/11 اجرا شود.
 */
final class SystemHealthDatabaseEngineTest extends TestCase
{
    private const KEY = 'db.mysql_version';

    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private static function row( string $status, string $detail ): array {
        return [ 'key' => self::KEY, 'label' => self::KEY, 'status' => $status, 'detail' => $detail ];
    }

    /**
     * @return list<array{key:string,label:string,status:string,detail:string}>
     */
    private static function hostRows( string $database_status, string $database_detail ): array {
        return [
            self::row( SystemHealthService::PASS, 'php' ),
            [ 'key' => 'wp.version', 'label' => 'wp.version', 'status' => SystemHealthService::PASS, 'detail' => '7.1.3' ],
            [ 'key' => 'db.reachable', 'label' => 'db.reachable', 'status' => SystemHealthService::PASS, 'detail' => '' ],
            [ 'key' => 'db.migrated', 'label' => 'db.migrated', 'status' => SystemHealthService::PASS, 'detail' => '' ],
            [ 'key' => self::KEY, 'label' => self::KEY, 'status' => $database_status, 'detail' => $database_detail ],
            [ 'key' => 'storage.files', 'label' => 'storage.files', 'status' => SystemHealthService::PASS, 'detail' => '' ],
            [ 'key' => 'storage.backups', 'label' => 'storage.backups', 'status' => SystemHealthService::PASS, 'detail' => '' ],
            [ 'key' => 'cron.jobs', 'label' => 'cron.jobs', 'status' => SystemHealthService::PASS, 'detail' => '' ],
        ];
    }

    // ============ ۱. تشخیصِ موتور از مدرکِ نسخهٔ سرور ============

    public function testMariaDbServerEvidenceIsDetectedAsMariaDb(): void
    {
        $versions = [
            '11.4.13-MariaDB',
            '10.11.19-MariaDB-log',
            '5.5.5-10.11.2-MariaDB-log',
            '10.6.16-MariaDB-1:10.6.16+maria~ubu2204',
            '11.4.2-mariadb',
        ];

        foreach ( $versions as $version ) {
            self::assertSame(
                SystemHealthService::DB_ENGINE_MARIADB,
                SystemHealthService::database_engine( $version ),
                'مدرکِ MariaDB باید MariaDB تشخیص داده شود: ' . $version
            );
        }
    }

    public function testMySqlServerEvidenceIsDetectedAsMySql(): void
    {
        $versions = [ '8.0.33', '8.0.35-log', '8.0.33-0ubuntu0.22.04.1', '8.4.0', '5.7.44-log', '9.1.0' ];

        foreach ( $versions as $version ) {
            self::assertSame(
                SystemHealthService::DB_ENGINE_MYSQL,
                SystemHealthService::database_engine( $version ),
                'مدرکِ MySQL باید MySQL تشخیص داده شود: ' . $version
            );
        }
    }

    public function testUnusableEngineEvidenceStaysUnknown(): void
    {
        foreach ( [ null, '', '   ', 'banana', 'unknown', '-6.4' ] as $version ) {
            self::assertSame(
                SystemHealthService::DB_ENGINE_UNKNOWN,
                SystemHealthService::database_engine( $version ),
                'مدرکِ ناخوانا نباید به MySQL/MariaDB تفسیر شود: ' . var_export( $version, true )
            );
        }
    }

    // ============ ۲. استخراجِ شمارهٔ نسخه (نرمال‌سازی) ============

    public function testMariaDbLegacyProtocolPrefixIsNotTreatedAsTheServerVersion(): void
    {
        // بیلدهای قدیمی کلاینت `5.5.5-` را پیشوند می‌کنند؛ نسخهٔ واقعیِ سرور
        // 10.11.2 است. خواندنِ 5.5.5 سرورِ 10.11 را «کهنه» گزارش می‌کرد.
        self::assertSame(
            '10.11.2',
            SystemHealthService::database_version_number( '5.5.5-10.11.2-MariaDB-log' )
        );
        self::assertSame(
            '11.4.13',
            SystemHealthService::database_version_number( '11.4.13-MariaDB' )
        );
        self::assertSame(
            '8.0.33',
            SystemHealthService::database_version_number( '8.0.33-0ubuntu0.22.04.1' )
        );
        self::assertNull( SystemHealthService::database_version_number( 'banana' ) );
    }

    // ============ ۳. سیاستِ MariaDB بر پایهٔ شواهدِ واقعی ============

    public function testTestedMariaDbReleaseIsSupported(): void
    {
        foreach ( [ '11.4.13-MariaDB', '11.4.13', '10.11.19-MariaDB-log' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertSame( SystemHealthService::PASS, $row['status'], 'نسخهٔ آزموده‌شده باید PASS باشد: ' . $version );
            self::assertStringContainsString( 'MariaDB', $row['label'] );
        }

        $tested = SystemHealthService::database_server_version_check( '11.4.13-MariaDB' );
        self::assertStringStartsWith( 'mariadb:tested', $tested['detail'] );

        $boundary = SystemHealthService::database_server_version_check( '10.11.19-MariaDB' );
        self::assertStringStartsWith( 'mariadb:compatibility_boundary', $boundary['detail'] );
    }

    public function testDocumentedMariaDbBoundarySeriesIsSupported(): void
    {
        foreach ( [ '10.11.2-MariaDB', '10.11.19-MariaDB', '5.5.5-10.11.2-MariaDB-log', '10.11' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertSame( SystemHealthService::PASS, $row['status'], 'سریِ مرزِ 10.11 باید PASS باشد: ' . $version );
            self::assertStringContainsString( SystemHealthService::MARIADB_COMPAT_BOUNDARY, (string) $row['detail'] );
        }
    }

    public function testMariaDbOlderThanTheDocumentedBoundaryIsAFailure(): void
    {
        foreach ( [ '10.6.16-MariaDB', '10.5.25-MariaDB', '10.3.39-MariaDB' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertSame( SystemHealthService::FAIL, $row['status'], 'کهنه‌تر از مرز باید FAIL باشد: ' . $version );
            self::assertStringStartsWith( 'mariadb:below_boundary', $row['detail'] );
        }
    }

    public function testUntestedMariaDbReleasesAreWarnedNotClaimed(): void
    {
        // نسخه‌های 11.4.x غیر از 13، 11.0–11.3 و نسخه‌های جدیدتر از آزموده.
        foreach ( [ '11.4.2-mariadb', '11.4.12-MariaDB', '11.0.6-MariaDB', '11.2.2-MariaDB', '11.5.2-MariaDB', '12.0.1-MariaDB' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertSame( SystemHealthService::WARNING, $row['status'], 'نسخهٔ آزموده‌نشده باید WARNING باشد: ' . $version );
            self::assertStringStartsWith( 'mariadb:unverified', $row['detail'] );
            self::assertStringContainsString( 'پشتیبانی', (string) $row['detail'], 'ادعایِ پشتیبانی برای نسخهٔ آزموده‌نشده نباید تولید شود.' );
        }
    }

    public function testMariaDbIsNeverReportedAsMySqlEight(): void
    {
        foreach ( [ '11.4.13-MariaDB', '10.11.19-MariaDB', '11.4.2-MariaDB', '10.6.16-MariaDB' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertStringNotContainsString( '(MySQL)', $row['label'], 'برچسبِ MariaDB نباید MySQL باشد: ' . $version );
            self::assertStringNotContainsString( 'کفِ اعلام‌شده: MySQL', (string) $row['detail'], 'کفِ MySQL نباید مبنای MariaDB باشد: ' . $version );
            self::assertStringContainsString( 'MariaDB', (string) $row['detail'] );
        }
    }

    // ============ ۴. MySQL بدونِ تغییرِ سیاست ============

    public function testMySqlFloorBehaviourIsUnchanged(): void
    {
        foreach ( [ '8.0', '8.0.33', '8.4.0' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );
            self::assertSame( SystemHealthService::PASS, $row['status'], 'MySQL ≥ 8.0 باید PASS باشد: ' . $version );
            self::assertStringContainsString( '(MySQL)', $row['label'] );
            self::assertStringStartsWith( 'mysql:detected', $row['detail'] );
        }

        foreach ( [ '5.7.44-log', '5.6.51' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );
            self::assertSame( SystemHealthService::FAIL, $row['status'], 'MySQL < 8.0 باید FAIL باشد: ' . $version );
        }
    }

    public function testUnusableEvidenceNeverPassesForAnyEngine(): void
    {
        foreach ( [ null, '', '   ', 'banana', 'unknown' ] as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );
            self::assertSame( SystemHealthService::UNKNOWN, $row['status'], 'مدرکِ نامعتبر ⇒ UNKNOWN: ' . var_export( $version, true ) );
        }
    }

    // ============ ۵. طبقه‌بندیِ میزبان و وضعیتِ کاربرپسند ============

    public function testTestedMariaDbHostIsSupportedNotUnsupported(): void
    {
        $row  = SystemHealthService::database_server_version_check( '10.11.19-MariaDB' );
        $host = SystemHealthService::classifyHost( self::hostRows( (string) $row['status'], (string) $row['detail'] ) );

        self::assertSame( SystemHealthService::HOST_SUPPORTED, $host['status'], 'MariaDB آزموده‌شده نباید UNSUPPORTED شود.' );
    }

    public function testUntestedMariaDbHostIsSupportedWithWarnings(): void
    {
        $row  = SystemHealthService::database_server_version_check( '11.5.2-MariaDB' );
        $host = SystemHealthService::classifyHost( self::hostRows( (string) $row['status'], (string) $row['detail'] ) );

        self::assertSame( SystemHealthService::HOST_SUPPORTED_WITH_WARNINGS, $host['status'] );
        self::assertContains( self::KEY, $host['issues'] );
    }

    public function testTooOldMariaDbHostIsUnsupported(): void
    {
        $row  = SystemHealthService::database_server_version_check( '10.6.16-MariaDB' );
        $host = SystemHealthService::classifyHost( self::hostRows( (string) $row['status'], (string) $row['detail'] ) );

        self::assertSame( SystemHealthService::HOST_UNSUPPORTED, $host['status'] );
    }

    public function testUserFacingStatusReflectsMariaDbEvidence(): void
    {
        $supported = SystemHealthService::database_server_version_check( '11.4.13-MariaDB' );
        self::assertSame(
            SystemHealthService::USER_STATUS_READY,
            SystemHealthService::user_facing_status( (string) $supported['status'], self::KEY, (string) $supported['detail'] )
        );

        $unverified = SystemHealthService::database_server_version_check( '11.5.2-MariaDB' );
        self::assertSame(
            SystemHealthService::USER_STATUS_NEEDS_SETUP,
            SystemHealthService::user_facing_status( (string) $unverified['status'], self::KEY, (string) $unverified['detail'] )
        );

        $too_old = SystemHealthService::database_server_version_check( '10.6.16-MariaDB' );
        self::assertSame(
            SystemHealthService::USER_STATUS_CRITICAL,
            SystemHealthService::user_facing_status( (string) $too_old['status'], self::KEY, (string) $too_old['detail'] )
        );
    }

    public function testDeclaredEvidenceConstantsAreTheOnesActuallyExecutedInCi(): void
    {
        self::assertSame( '11.4.13', SystemHealthService::MARIADB_TESTED_VERSION );
        self::assertSame( '10.11', SystemHealthService::MARIADB_COMPAT_BOUNDARY );
        self::assertSame( '10.11.19', SystemHealthService::MARIADB_COMPAT_BOUNDARY_TESTED_PATCH );
        self::assertSame( '8.0', SystemHealthService::MIN_MYSQL_VERSION );
    }
}
