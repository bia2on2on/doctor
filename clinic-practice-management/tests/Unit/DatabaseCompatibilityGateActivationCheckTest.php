<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate;
use PHPUnit\Framework\TestCase;

/**
 * N1 — پیش‌بررسیِ ساختاریافتهٔ موتورِ دیتابیس برای فعال‌سازی.
 *
 * این تست ماتریسِ نسخه‌ها را به {@see DatabaseCompatibilityGate::check()} می‌دهد و
 * سه چیز را ثابت می‌کند:
 *  ۱. تصمیمِ `supported` عیناً همان تصمیمِ {@see DatabaseCompatibilityGate::engine()}
 *     است — یعنی همان دروازه‌ای که مهاجرت 0027 استفاده می‌کند؛ پیش‌بررسیِ
 *     فعال‌سازی نمی‌تواند مجموعهٔ مجاز را بازتر یا بسته‌تر از دروازهٔ واقعی
 *     اعلام کند (MariaDB 11.8.6 / 11.4.12 / 10.6.x و MySQL 5.7 رد می‌شوند).
 *  ۲. فیلدهای engine/version فقط گزارشِ شواهدند (مثلاً MariaDB 11.8 با
 *     supported=false و engine=mariadb) و هیچ اثری روی تصمیم ندارند.
 *  ۳. پیامِ ردِ فعال‌سازی، موتور/نسخهٔ شناسایی‌شده و مجموعهٔ پذیرفته‌شده را با
 *     واژگانِ عینِ مهاجرت 0027 اعلام می‌کند — تستِ هم‌خوانی، متنِ خودِ فایلِ
 *     مهاجرت را با همان ثابت می‌سنجد.
 *
 * مجموعهٔ مجاز اینجا گسترده نمی‌شود و تستِ سیاستیِ موجود
 * (CustomRoleArchivalMigrationTest::test_mariadb_118_is_outside_the_verified_gate_and_fails_closed_before_ddl)
 * دست‌نخورده باقی می‌ماند.
 */
final class DatabaseCompatibilityGateActivationCheckTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     *     version, comment, expected supported, expected reported engine family
     */
    public static function matrix(): array
    {
        return [
            'mysql 8.0'             => ['8.0.36', 'MySQL Community Server - GPL', true, DatabaseCompatibilityGate::ENGINE_MYSQL],
            'mysql 8.4'             => ['8.4.6', 'MySQL Community Server - GPL', true, DatabaseCompatibilityGate::ENGINE_MYSQL],
            'mariadb 10.11.x'       => ['10.11.19-MariaDB-ubu2404', 'mariadb.org binary distribution', true, DatabaseCompatibilityGate::ENGINE_MARIADB],
            'mariadb 11.4.13'       => ['11.4.13-MariaDB', 'mariadb.org binary distribution', true, DatabaseCompatibilityGate::ENGINE_MARIADB],
            'mariadb 11.4.12'       => ['11.4.12-MariaDB', 'mariadb.org binary distribution', false, DatabaseCompatibilityGate::ENGINE_MARIADB],
            'mariadb 11.8.6 live'   => ['11.8.6-MariaDB', 'mariadb.org binary distribution', false, DatabaseCompatibilityGate::ENGINE_MARIADB],
            'mariadb 10.6.x'        => ['10.6.16-MariaDB', 'mariadb.org binary distribution', false, DatabaseCompatibilityGate::ENGINE_MARIADB],
            'mysql 5.7'             => ['5.7.44', 'MySQL Community Server (GPL)', false, DatabaseCompatibilityGate::ENGINE_MYSQL],
            'empty evidence'        => ['', '', false, DatabaseCompatibilityGate::ENGINE_UNKNOWN],
            'garbage version'       => ['garbage', '', false, DatabaseCompatibilityGate::ENGINE_UNKNOWN],
            'garbage with mysql hint' => ['garbage', 'MySQL', false, DatabaseCompatibilityGate::ENGINE_MYSQL],
            '5.5.5-prefixed mariadb' => ['5.5.5-10.11.2-MariaDB-log', 'mariadb.org binary distribution', true, DatabaseCompatibilityGate::ENGINE_MARIADB],
        ];
    }

    /**
     * تصمیمِ supported دقیقاً همان engine() است؛ engine/version گزارشِ شواهدند.
     *
     * @dataProvider matrix
     */
    public function testCheckAgreesWithTheMigrationGateAndReportsEvidence(
        string $version,
        string $comment,
        bool $supported,
        string $engine_family
    ): void {
        $result = DatabaseCompatibilityGate::check( $version, $comment );

        self::assertSame( $supported, $result['supported'], 'supported must mirror the verified-set gate: ' . $version );
        self::assertSame(
            $supported,
            DatabaseCompatibilityGate::engine( $version, $comment ) !== null,
            'check() may never diverge from engine() — the migration-0027 defence in depth stays aligned: ' . $version
        );
        self::assertSame( $engine_family, $result['engine'], 'reported engine family for: ' . $version );
        self::assertSame( trim( $version ), $result['version'], 'the detected version string is reported verbatim: ' . $version );
        self::assertNotSame( '', $result['reason'], 'a reason is always present' );

        if ( $supported ) {
            self::assertSame( 'verified database server', $result['reason'] );
        } else {
            self::assertSame(
                'unsupported database server; ' . DatabaseCompatibilityGate::ACCEPTED_SET_WORDING,
                $result['reason']
            );
        }
    }

    /**
     * D-ادامهٔ ماتریس — موتورِ «شناسایی‌شده» هیچ‌وقت به معنای پذیرش نیست:
     * MariaDB 11.8.6 شناسایی و گزارش می‌شود ولی رد.
     */
    public function testRejectedEnginesAreStillReportedAsEvidence(): void
    {
        $result = DatabaseCompatibilityGate::check( '11.8.6-MariaDB', 'mariadb.org binary distribution' );

        self::assertFalse( $result['supported'] );
        self::assertSame( DatabaseCompatibilityGate::ENGINE_MARIADB, $result['engine'] );
        self::assertSame( '11.8.6-MariaDB', $result['version'] );
    }

    /**
     * شواهدِ ناخوانا (کوئری ناموفق/فیلد غیررشته‌ای) → fail-closed با همان
     * موضعِ مهاجرت 0027: «هویت در دسترس نیست؛ از تغییرِ schema خودداری می‌شود».
     */
    public function testUnavailableEvidenceFailsClosed(): void
    {
        $result = DatabaseCompatibilityGate::unavailable_evidence();

        self::assertFalse( $result['supported'] );
        self::assertSame( DatabaseCompatibilityGate::ENGINE_UNKNOWN, $result['engine'] );
        self::assertSame( '', $result['version'] );
        self::assertSame( 'database server identity is unavailable; refusing schema changes', $result['reason'] );
    }

    /**
     * پیامِ ردِ فعال‌سازی: موتور/نسخهٔ شناسایی‌شده + مجموعهٔ پذیرفته‌شده با
     * واژگانِ مهاجرت 0027 + عبارتِ صریحِ «هیچ‌چیز نصب/نوشته نشده».
     */
    public function testActivationRefusalMessageNamesTheEngineVersionAndAcceptedSet(): void
    {
        $message = DatabaseCompatibilityGate::activation_refusal_message(
            DatabaseCompatibilityGate::check( '11.8.6-MariaDB', 'mariadb.org binary distribution' )
        );

        self::assertStringContainsString( '11.8.6-MariaDB', $message );
        self::assertStringContainsString( DatabaseCompatibilityGate::ENGINE_MARIADB, $message );
        self::assertStringContainsString( DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $message );
        self::assertStringContainsString( 'no CPMS roles, database tables, options or scheduled events have been created', $message );
    }

    public function testActivationRefusalMessageForUnavailableEvidenceStillNamesTheAcceptedSet(): void
    {
        $message = DatabaseCompatibilityGate::activation_refusal_message( DatabaseCompatibilityGate::unavailable_evidence() );

        self::assertStringContainsString( 'database server identity is unavailable', $message );
        self::assertStringContainsString( DatabaseCompatibilityGate::ACCEPTED_SET_WORDING, $message );
    }

    /**
     * هم‌خوانیِ واژگان با مهاجرت 0027 — همان الگوی تستِ پاریتیِ موجود: متنِ
     * خودِ فایلِ مهاجرت باید همان عبارتِ مجموعهٔ پذیرفته‌شده را داشته باشد.
     * اگر کسی یکی از دو طرف را عوض کند، این تست می‌شکند.
     */
    public function testAcceptedSetWordingMatchesMigration0027Source(): void
    {
        $path   = dirname( __DIR__, 2 ) . '/src/Migrations/2026_10_13_0027_custom_role_archival.php';
        $source = (string) file_get_contents( $path );

        self::assertStringContainsString(
            DatabaseCompatibilityGate::ACCEPTED_SET_WORDING,
            $source,
            'the activation refusal must reuse migration 0027\'s exact accepted-set wording'
        );
    }
}
