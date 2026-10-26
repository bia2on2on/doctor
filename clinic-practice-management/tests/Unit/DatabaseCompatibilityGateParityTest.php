<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate;
use PHPUnit\Framework\TestCase;

/**
 * D1/D6/D8 — هم‌خوانیِ **ساختاری** بین گزارشِ سلامت و دروازهٔ واقعیِ مهاجرت.
 *
 * پیش از این، مجموعهٔ نسخه‌های مجاز دو بار نوشته شده بود: یک regex داخل
 * `src/Migrations/2026_10_13_0027_custom_role_archival.php` و یک منطقِ
 * مقایسهٔ نسخه داخل `SystemHealthService`. هیچ تستی نمی‌توانست ثابت کند آن دو
 * یکی هستند — و در واقع نبودند: سلامت نسخه‌هایی را «هشدار/قابل‌قبول» می‌خواند
 * که دروازه fail-closed رد می‌کرد.
 *
 * اکنون هر دو مسیر از {@see DatabaseCompatibilityGate} استفاده می‌کنند. این
 * تست:
 *  ۱. ثابت می‌کند هر ردیفی که وضعیتِ PASS می‌گیرد، واقعاً از دروازه عبور می‌کند
 *     و برعکس (پاریتیِ دوطرفه روی ماتریسِ نسخه‌ها)؛
 *  ۲. ثابت می‌کند خودِ فایلِ مهاجرت همان کلاس را صدا می‌زند (نه regex محلی)؛
 *  ۳. ثابت می‌کند مجموعهٔ مجاز **گسترده نشده** است (D4): 11.8/12.x رد می‌شوند.
 */
final class DatabaseCompatibilityGateParityTest extends TestCase
{
    /**
     * نقشهٔ نسخهٔ MariaDB ⇒ آیا دروازه آن را می‌پذیرد.
     *
     * @return array<string, bool>
     */
    private static function matrix(): array
    {
        return [
            '11.4.13-MariaDB'   => true,  // آزمودهٔ CI
            '10.11.19-MariaDB'  => true,  // مرزِ مستند/آزمودهٔ هفتگی
            '10.11.2-MariaDB'   => true,  // همان سری
            '11.4.2-MariaDB'    => false, // 11.4.x غیر از 13 آزموده نشده
            '11.4.12-MariaDB'   => false,
            '11.5.2-MariaDB'    => false,
            '11.8.2-MariaDB'    => false, // D4 — بدونِ اجرای واقعی گسترده نمی‌شود
            '12.0.1-MariaDB'    => false,
            '10.6.16-MariaDB'   => false, // کهنه‌تر از مرز
            '10.5.27-MariaDB'   => false,
            'MariaDB'           => false, // نسخهٔ ناخوانا
        ];
    }

    public function testHealthVerdictAndMigrationGateAgreeOnEveryVersion(): void
    {
        foreach ( self::matrix() as $version => $gate_accepts ) {
            $row = SystemHealthService::database_server_version_check( $version );
            $health_ready = SystemHealthService::PASS === $row['status'];

            self::assertSame(
                $gate_accepts,
                DatabaseCompatibilityGate::accepts_mariadb_version( $version ),
                'خودِ دروازه برای این نسخه تصمیمِ غیرمنتظره گرفت: ' . $version
            );
            self::assertSame(
                $gate_accepts,
                $health_ready,
                'گزارشِ سلامت با دروازهٔ مهاجرت هم‌خوان نیست: ' . $version
                    . ' (status=' . $row['status'] . ', detail=' . $row['detail'] . ')'
            );
        }
    }

    /**
     * D8 — مدرکِ MariaDB واقعاً مسیرِ MariaDB را می‌رود (نه شاخهٔ MySQL).
     */
    public function testMariaDbEvidenceNeverTakesTheMySqlBranch(): void
    {
        foreach ( self::matrix() as $version => $_accepts ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertStringNotContainsString( '(MySQL)', (string) $row['label'], 'برچسب نباید MySQL باشد: ' . $version );
            self::assertStringStartsWith( 'mariadb:', (string) $row['detail'], 'مسیرِ MariaDB باید از پیشوندِ mariadb: استفاده کند: ' . $version );
        }
    }

    /**
     * D1 — فایلِ مهاجرت باید از همین کلاس استفاده کند.
     *
     * اگر کسی مجموعهٔ مجاز را در فایلِ مهاجرت مستقیم/جدا تغییر دهد، این تست
     * می‌شکند و هم‌خوانیِ ادعایی دوباره قابل‌راستی‌آزمایی می‌شود.
     */
    public function testMigrationUsesTheSharedGateInsteadOfItsOwnRegex(): void
    {
        $path = dirname( __DIR__, 2 ) . '/src/Migrations/2026_10_13_0027_custom_role_archival.php';
        $source = (string) file_get_contents( $path );

        self::assertStringContainsString( 'DatabaseCompatibilityGate::engine(', $source );
        self::assertStringNotContainsString( '10\\.11\\.\\d+', $source, 'regexِ محلیِ مجاز نباید در فایلِ مهاجرت باقی بماند.' );
    }

    public function testSharedGateRejectsUnknownEngineEvidence(): void
    {
        self::assertNull( DatabaseCompatibilityGate::engine( 'banana', 'MySQL' ) );
        self::assertNull( DatabaseCompatibilityGate::engine( '8.0.36', 'MariaDB' ) );
        self::assertNull( DatabaseCompatibilityGate::engine( '11.8.2-MariaDB', 'MariaDB' ) );
        self::assertSame( DatabaseCompatibilityGate::ENGINE_MYSQL, DatabaseCompatibilityGate::engine( '8.0.36', 'MySQL' ) );
        self::assertSame( DatabaseCompatibilityGate::ENGINE_MARIADB, DatabaseCompatibilityGate::engine( '11.4.13-MariaDB', '' ) );
    }
}
