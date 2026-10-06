<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 18 — Slice 18-1: سازگاریِ زمان‌اجرا برای کف‌هایِ اعلام‌شدهٔ
 * WordPress و MySQL (تشخیص/گزارش فقط؛ بدون PHI، سطحِ نصب، مستقل از tenant).
 *
 * قراردادِ تحتِ تست:
 *  ۱. گزارش سلامت دو چکِ صریح دارد: `wp.version` و `db.mysql_version`.
 *  ۲. مقدارِ زیرِ کفِ اعلام‌شده ⇒ FAIL؛ روی/بالای کف ⇒ PASS.
 *  ۳. نبودن/ناخوانایی/ناتجزیه‌پذیریِ مدرکِ نسخه هرگز PASS نمی‌شود (fail-closed).
 *  ۴. FAIL/UNKNOWN هرکدام از این دو کلید ⇒ میزبان UNSUPPORTED.
 *  ۵. نسخه‌هایِ پشتیبانی‌شده ⇒ طبقه‌بندیِ پشتیبانی‌شده حفظ می‌شود.
 *  ۶. رفتارِ طبقه‌بندیِ PHP دست‌نخورده می‌ماند.
 *
 * این کلاس Unit است (بدون WP/DB): منطقِ مقایسه خالص و قابل‌تست است و منابعِ
 * نسخه (WordPress/MySQL) در لایهٔ سرویس جدا می‌مانند. از data provider استفاده
 * نمی‌شود چون همین مجموعه تست روی PHPUnit 9.6/10.5/11 اجرا می‌شود.
 */
final class SystemHealthRuntimeCompatibilityTest extends TestCase
{
    private const WP_KEY = 'wp.version';
    private const DB_KEY = 'db.mysql_version';

    /** نسخه‌هایِ وردپرسِ زیرِ کفِ اعلام‌شدهٔ 6.4 */
    private const WP_BELOW_FLOOR = ['6.3.2', '6.3', '6.0.9', '5.9.8'];

    /** نسخه‌هایِ وردپرسِ روی/بالای کفِ اعلام‌شدهٔ 6.4 */
    private const WP_SUPPORTED = ['6.4', '6.4.3', '6.7.2', '7.0'];

    /** نسخه‌هایِ سرورِ MySQLِ زیرِ کفِ اعلام‌شدهٔ 8.0 */
    private const MYSQL_BELOW_FLOOR = ['5.7.44-log', '5.7', '5.6.51', '5.7.99'];

    /** نسخه‌هایِ سرورِ MySQLِ روی/بالای کفِ اعلام‌شدهٔ 8.0 */
    private const MYSQL_SUPPORTED = ['8.0', '8.0.33', '8.0.33-0ubuntu0.22.04.1', '8.0.35-log', '8.4.0'];

    /**
     * مدرکِ نسخه‌ای که وجود ندارد/قابل‌خواندن نیست/تجزیه‌پذیر نیست.
     *
     * @return list<string|null>
     */
    private static function unusableVersions(): array
    {
        return [null, '', '   ', 'banana', 'unknown', '-6.4'];
    }

    /**
     * MySQL خود را گاهی با پیشوند (مثل `-log` یا پسوندِ توزیع) و MariaDB خود را
     * با نشانگرِ اختصاصی معرفی می‌کند. این فهرست فقط رشته‌هایِ MariaDB است.
     *
     * @return list<string>
     */
    private static function mariaDbVersions(): array
    {
        return [
            '10.11.2-MariaDB',
            '10.6.16-MariaDB-1:10.6.16+maria~ubu2204',
            '5.5.5-10.11.2-MariaDB-log',
            '11.4.2-mariadb',
        ];
    }

    /**
     * شکلِ عمومیِ ردیفِ Health که مصرف‌کننده‌ها می‌خوانند — نباید تغییر کند.
     *
     * @param array<mixed, mixed> $row
     */
    private static function assertHealthRowShape(array $row, string $expectedKey): void
    {
        self::assertSame(['key', 'label', 'status', 'detail'], array_keys($row), 'شکلِ ردیف تغییر نکند.');
        self::assertSame($expectedKey, $row['key']);
        self::assertIsString($row['label']);
        self::assertNotSame('', trim($row['label']));
        self::assertIsString($row['status']);
        self::assertContains(
            $row['status'],
            [
                SystemHealthService::PASS,
                SystemHealthService::WARNING,
                SystemHealthService::FAIL,
                SystemHealthService::NOT_CONFIGURED,
                SystemHealthService::UNKNOWN,
            ]
        );
        self::assertIsString($row['detail']);
    }

    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private static function hostRow(string $key, string $status): array
    {
        return ['key' => $key, 'label' => $key, 'status' => $status, 'detail' => ''];
    }

    /**
     * همهٔ چک‌هایِ حیاتی (از جمله دو چکِ این برش) در یک وضعیتِ قابل‌تنظیم.
     *
     * @return list<array{key:string,label:string,status:string,detail:string}>
     */
    private static function criticalRows(string $wpStatus, string $dbStatus): array
    {
        return [
            self::hostRow('php.version', SystemHealthService::PASS),
            self::hostRow(self::WP_KEY, $wpStatus),
            self::hostRow('db.reachable', SystemHealthService::PASS),
            self::hostRow('db.migrated', SystemHealthService::PASS),
            self::hostRow(self::DB_KEY, $dbStatus),
            self::hostRow('storage.files', SystemHealthService::PASS),
            self::hostRow('storage.backups', SystemHealthService::PASS),
            self::hostRow('cron.jobs', SystemHealthService::PASS),
        ];
    }

    // ===================== ۱. کف‌هایِ اعلام‌شده =====================

    /**
     * کف‌ها اختراع نشده‌اند: WP از هدرِ افزونه (`Requires at least`) و MySQL از
     * SRS §2.2 گرفته شده‌اند؛ این تست از انحرافِ آینده جلوگیری می‌کند.
     */
    public function testDeclaredFloorsMatchTheAuthoritativeContract(): void
    {
        self::assertSame('6.4', SystemHealthService::MIN_WORDPRESS_VERSION, 'کفِ WP طبق هدرِ افزونه.');
        self::assertSame('8.0', SystemHealthService::MIN_MYSQL_VERSION, 'کفِ MySQL طبق SRS §2.2.');

        $pluginFile = dirname(__DIR__, 2) . '/clinic-practice-management.php';
        self::assertFileExists($pluginFile);
        $header = (string) file_get_contents($pluginFile);
        self::assertSame(
            1,
            preg_match('/^\s*\*\s*Requires at least:\s*([0-9][0-9.]*)\s*$/mi', $header, $m),
            'هدرِ `Requires at least` یافت نشد.'
        );
        self::assertSame(SystemHealthService::MIN_WORDPRESS_VERSION, $m[1], 'کفِ WP باید با هدرِ افزونه یکی باشد.');
    }

    // ===================== ۲. چکِ نسخهٔ وردپرس =====================

    public function testWordPressCheckEmitsTheHealthRowContract(): void
    {
        $row = SystemHealthService::wordPressVersionCheck('6.7.2');

        self::assertHealthRowShape($row, self::WP_KEY);
    }

    public function testWordPressBelowDeclaredFloorFails(): void
    {
        foreach (self::WP_BELOW_FLOOR as $version) {
            $row = SystemHealthService::wordPressVersionCheck($version);

            self::assertSame(SystemHealthService::FAIL, $row['status'], $version . ' باید زیرِ کف باشد.');
            self::assertStringContainsString($version, $row['detail'], $version);
        }
    }

    public function testWordPressAtOrAboveDeclaredFloorPasses(): void
    {
        foreach (self::WP_SUPPORTED as $version) {
            $row = SystemHealthService::wordPressVersionCheck($version);

            self::assertSame(SystemHealthService::PASS, $row['status'], $version . ' باید روی/بالای کف باشد.');
        }
    }

    public function testUnusableWordPressVersionEvidenceNeverPasses(): void
    {
        foreach (self::unusableVersions() as $version) {
            $row = SystemHealthService::wordPressVersionCheck($version);

            self::assertNotSame(SystemHealthService::PASS, $row['status'], 'مدرکِ نامعتبر هرگز PASS نیست: ' . var_export($version, true));
            self::assertSame(SystemHealthService::UNKNOWN, $row['status'], 'ناتجزیه‌پذیر ⇒ UNKNOWN (ادعایِ کاذب نشود): ' . var_export($version, true));
        }
    }

    // ===================== ۳. چکِ نسخهٔ سرور MySQL =====================

    public function testDatabaseServerCheckEmitsTheHealthRowContract(): void
    {
        $row = SystemHealthService::databaseServerVersionCheck('8.0.33');

        self::assertHealthRowShape($row, self::DB_KEY);
    }

    public function testMySqlBelowDeclaredFloorFails(): void
    {
        foreach (self::MYSQL_BELOW_FLOOR as $version) {
            $row = SystemHealthService::databaseServerVersionCheck($version);

            self::assertSame(SystemHealthService::FAIL, $row['status'], $version . ' باید زیرِ کف باشد.');
        }
    }

    public function testMySqlAtOrAboveDeclaredFloorPasses(): void
    {
        foreach (self::MYSQL_SUPPORTED as $version) {
            $row = SystemHealthService::databaseServerVersionCheck($version);

            self::assertSame(SystemHealthService::PASS, $row['status'], $version . ' باید روی/بالای کف باشد.');
        }
    }

    public function testUnusableDatabaseServerVersionEvidenceNeverPasses(): void
    {
        foreach (self::unusableVersions() as $version) {
            $row = SystemHealthService::databaseServerVersionCheck($version);

            self::assertNotSame(SystemHealthService::PASS, $row['status'], 'مدرکِ نامعتبر هرگز PASS نیست: ' . var_export($version, true));
            self::assertSame(SystemHealthService::UNKNOWN, $row['status'], 'ناتجزیه‌پذیر ⇒ UNKNOWN (ادعایِ کاذب نشود): ' . var_export($version, true));
        }
    }

    /**
     * سیاستِ MariaDB در این برش گسترش/اعلام نمی‌شود. مدرکِ MariaDB مدرکِ
     * «MySQL ≥ 8.0» نیست، پس هرگز PASS تولید نمی‌کند (fail-closed) و در عین
     * حال ادعایِ نادرستِ «زیرِ کف» (FAIL) هم نمی‌سازد.
     */
    public function testMariaDbEvidenceNeverClaimsTheMySqlFloor(): void
    {
        foreach (self::mariaDbVersions() as $version) {
            $row = SystemHealthService::databaseServerVersionCheck($version);

            self::assertNotSame(SystemHealthService::PASS, $row['status'], 'MariaDB مجوزِ PASS روی کفِ MySQL نمی‌گیرد: ' . $version);
            self::assertSame(SystemHealthService::UNKNOWN, $row['status'], 'سیاستِ MariaDB اعلام نشده ⇒ UNKNOWN: ' . $version);
        }
    }

    // ===================== ۴/۵. طبقه‌بندیِ میزبان =====================

    public function testFailingWordPressCompatibilityMakesHostUnsupported(): void
    {
        $host = SystemHealthService::classifyHost(
            self::criticalRows(SystemHealthService::FAIL, SystemHealthService::PASS)
        );

        self::assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
        self::assertNotEmpty($host['issues']);
        self::assertStringContainsString(self::WP_KEY, implode(' | ', $host['issues']));
    }

    public function testFailingMySqlCompatibilityMakesHostUnsupported(): void
    {
        $host = SystemHealthService::classifyHost(
            self::criticalRows(SystemHealthService::PASS, SystemHealthService::FAIL)
        );

        self::assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
        self::assertNotEmpty($host['issues']);
        self::assertStringContainsString(self::DB_KEY, implode(' | ', $host['issues']));
    }

    public function testUnknownCompatibilityEvidenceAlsoMakesHostUnsupported(): void
    {
        $host = SystemHealthService::classifyHost(
            self::criticalRows(SystemHealthService::UNKNOWN, SystemHealthService::UNKNOWN)
        );

        self::assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status'], 'مدرکِ نامشخص ⇒ UNSUPPORTED.');
    }

    public function testSupportedWordPressAndMySqlPreserveSupportedHost(): void
    {
        $host = SystemHealthService::classifyHost(
            self::criticalRows(SystemHealthService::PASS, SystemHealthService::PASS)
        );

        self::assertSame(SystemHealthService::HOST_SUPPORTED, $host['status']);
        self::assertSame([], $host['issues']);
    }

    /**
     * رگرسیون: رفتارِ طبقه‌بندیِ PHP (و بقیهٔ چک‌هایِ حیاتی) دست‌نخورده است.
     */
    public function testExistingPhpFailureStillMakesHostUnsupported(): void
    {
        $rows = self::criticalRows(SystemHealthService::PASS, SystemHealthService::PASS);
        foreach ($rows as $i => $row) {
            if ($row['key'] === 'php.version') {
                $rows[$i]['status'] = SystemHealthService::FAIL;
            }
        }

        $host = SystemHealthService::classifyHost($rows);

        self::assertSame(SystemHealthService::HOST_UNSUPPORTED, $host['status']);
        self::assertStringContainsString('php.version', implode(' | ', $host['issues']));
    }

    public function testExistingPhpPassKeepsHostSupported(): void
    {
        $host = SystemHealthService::classifyHost(
            self::criticalRows(SystemHealthService::PASS, SystemHealthService::PASS)
        );

        self::assertSame(SystemHealthService::HOST_SUPPORTED, $host['status'], 'رفتارِ PHP تغییر نکرده است.');
    }
}
