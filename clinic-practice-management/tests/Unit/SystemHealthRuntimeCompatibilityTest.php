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
 *  ۷. نصبِ کهنه راهنمایِ فارسیِ ارتقا می‌گیرد و آن راهنما دسترسی به داده را
 *     محدود نمی‌کند (بدون قفلِ شبه‌مجوز).
 *
 * این کلاس Unit است (بدون WP/DB): منطقِ مقایسه خالص و قابل‌تست است و منابعِ
 * نسخه (WordPress/MySQL) در لایهٔ سرویس جدا می‌مانند. از data provider استفاده
 * نمی‌شود چون همین مجموعه تست روی PHPUnit 9.6/10.5/11 اجرا می‌شود.
 */
final class SystemHealthRuntimeCompatibilityTest extends TestCase
{
    private const WP_KEY = 'wp.version';
    private const DB_KEY = 'db.mysql_version';

    /** نسخه‌هایِ وردپرسِ زیرِ کفِ اعلام‌شدهٔ 6.9 (شاملِ نسخه‌هایِ کنارگذاشته‌شدهٔ 6.4–6.8) */
    private const WP_BELOW_FLOOR = ['6.8.11', '6.8', '6.7.10', '6.7.2', '6.4.13', '6.3.2', '5.9.8'];

    /**
     * نسخه‌هایِ وردپرسِ روی/بالای کفِ اعلام‌شدهٔ 6.9 — بازهٔ پشتیبانی‌شده همان
     * سه نسخهٔ اصلیِ پایدارِ آخر است: ۶.۹، ۷.۰، ۷.۱ (با وصله‌هایشان).
     */
    private const WP_SUPPORTED = ['6.9', '6.9.10', '7.0', '7.0.7', '7.1', '7.1.3'];

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
        self::assertSame('6.9', SystemHealthService::MIN_WORDPRESS_VERSION, 'کفِ WP طبق هدرِ افزونه.');
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
        $row = SystemHealthService::wordpress_version_check('6.7.2');

        self::assertHealthRowShape($row, self::WP_KEY);
    }

    public function testWordPressBelowDeclaredFloorFails(): void
    {
        foreach (self::WP_BELOW_FLOOR as $version) {
            $row = SystemHealthService::wordpress_version_check($version);

            self::assertSame(SystemHealthService::FAIL, $row['status'], $version . ' باید زیرِ کف باشد.');
            self::assertStringContainsString($version, $row['detail'], $version);
        }
    }

    /**
     * نصبِ کهنه باید راهنمایِ فارسیِ ارتقا بگیرد — و همان راهنما باید صریح بگوید
     * که دسترسی به داده محدود نمی‌شود (بدون قفلِ شبه‌مجوز و بدون محدودیتِ
     * دادهٔ بالینی/مالیِ تاریخی).
     */
    public function testOutdatedWordPressGetsPersianUpgradeGuidanceWithoutGating(): void
    {
        $row = SystemHealthService::wordpress_version_check('6.7.2');

        self::assertSame(SystemHealthService::FAIL, $row['status']);
        self::assertStringContainsString(
            SystemHealthService::WORDPRESS_UPGRADE_GUIDANCE_FA,
            $row['detail'],
            'راهنمایِ ارتقا باید در ردیفِ FAIL باشد.'
        );
        self::assertStringContainsString('به‌روزرسانی‌ها', $row['detail'], 'مسیرِ عملیِ ارتقا در راهنما باشد.');
        self::assertStringContainsString('بکاپ', $row['detail'], 'گرفتن بکاپ پیش از ارتقا توصیه شود.');
        self::assertStringContainsString(
            'دسترسی‌ای گرفته نمی‌شود',
            $row['detail'],
            'راهنما نباید دسترسی به داده را محدود جلوه دهد.'
        );

        // راهنما فقط متن است: وضعیتِ روی/بالای کف هیچ راهنماییِ ارتقا نمی‌گیرد.
        self::assertStringNotContainsString(
            SystemHealthService::WORDPRESS_UPGRADE_GUIDANCE_FA,
            SystemHealthService::wordpress_version_check('7.1.3')['detail']
        );
    }

    /**
     * راهنما باید شاملِ کفِ اعلام‌شده باشد تا با تغییرِ بازهٔ پشتیبانی از آن جدا نشود.
     */
    public function testUpgradeGuidanceStatesTheDeclaredFloor(): void
    {
        self::assertStringContainsString(
            SystemHealthService::MIN_WORDPRESS_VERSION,
            SystemHealthService::WORDPRESS_UPGRADE_GUIDANCE_FA
        );
    }

    public function testWordPressAtOrAboveDeclaredFloorPasses(): void
    {
        foreach (self::WP_SUPPORTED as $version) {
            $row = SystemHealthService::wordpress_version_check($version);

            self::assertSame(SystemHealthService::PASS, $row['status'], $version . ' باید روی/بالای کف باشد.');
        }
    }

    public function testUnusableWordPressVersionEvidenceNeverPasses(): void
    {
        foreach (self::unusableVersions() as $version) {
            $row = SystemHealthService::wordpress_version_check($version);

            self::assertNotSame(SystemHealthService::PASS, $row['status'], 'مدرکِ نامعتبر هرگز PASS نیست: ' . var_export($version, true));
            self::assertSame(SystemHealthService::UNKNOWN, $row['status'], 'ناتجزیه‌پذیر ⇒ UNKNOWN (ادعایِ کاذب نشود): ' . var_export($version, true));
        }
    }

    // ===================== ۳. چکِ نسخهٔ سرور MySQL =====================

    public function testDatabaseServerCheckEmitsTheHealthRowContract(): void
    {
        $row = SystemHealthService::database_server_version_check('8.0.33');

        self::assertHealthRowShape($row, self::DB_KEY);
    }

    public function testMySqlBelowDeclaredFloorFails(): void
    {
        foreach (self::MYSQL_BELOW_FLOOR as $version) {
            $row = SystemHealthService::database_server_version_check($version);

            self::assertSame(SystemHealthService::FAIL, $row['status'], $version . ' باید زیرِ کف باشد.');
        }
    }

    public function testMySqlAtOrAboveDeclaredFloorPasses(): void
    {
        foreach (self::MYSQL_SUPPORTED as $version) {
            $row = SystemHealthService::database_server_version_check($version);

            self::assertSame(SystemHealthService::PASS, $row['status'], $version . ' باید روی/بالای کف باشد.');
        }
    }

    public function testUnusableDatabaseServerVersionEvidenceNeverPasses(): void
    {
        foreach (self::unusableVersions() as $version) {
            $row = SystemHealthService::database_server_version_check($version);

            self::assertNotSame(SystemHealthService::PASS, $row['status'], 'مدرکِ نامعتبر هرگز PASS نیست: ' . var_export($version, true));
            self::assertSame(SystemHealthService::UNKNOWN, $row['status'], 'ناتجزیه‌پذیر ⇒ UNKNOWN (ادعایِ کاذب نشود): ' . var_export($version, true));
        }
    }

    /**
     * CPMS Classic Admin UX (D) — سیاستِ MariaDB اکنون صریح و مبتنی بر شواهدِ
     * واقعیِ مخزن است (CI: `mariadb:11.4.13`؛ لِینِ هفتگی: MariaDB 10.11.19).
     *
     * قراردادِ تغییر‌یافته (پیش از این همیشه UNKNOWN بود):
     *  - موتور همیشه MariaDB تشخیص داده می‌شود (هرگز MySQL)؛
     *  - برچسب ردیف صریحاً MariaDB است و نه «(MySQL)»؛
     *  - جزئیات با شناسهٔ ساختاریِ `mariadb:` شروع می‌شود؛
     *  - کفِ MySQL 8.0 هرگز مبنای قضاوتِ MariaDB نیست.
     */
    public function testMariaDbEvidenceIsNeverJudgedAgainstTheMySqlFloor(): void
    {
        foreach (self::mariaDbVersions() as $version ) {
            $row = SystemHealthService::database_server_version_check( $version );

            self::assertSame( SystemHealthService::DB_ENGINE_MARIADB, SystemHealthService::database_engine( $version ), 'موتور باید MariaDB تشخیص داده شود: ' . $version );
            self::assertStringContainsString( 'MariaDB', $row['label'], 'برچسبِ ردیف باید صریحاً MariaDB باشد: ' . $version );
            self::assertStringNotContainsString( '(MySQL)', $row['label'], 'MariaDB نباید با برچسبِ MySQL بازنمایی شود: ' . $version );
            self::assertStringStartsWith( 'mariadb:', $row['detail'], 'شناسهٔ ساختاریِ موتور در detail لازم است: ' . $version );
            self::assertStringNotContainsString( 'کفِ اعلام‌شده: MySQL', $row['detail'], 'کفِ MySQL نباید مبنای قضاوتِ MariaDB باشد: ' . $version );
            self::assertContains(
                $row['status'],
                [ SystemHealthService::PASS, SystemHealthService::WARNING, SystemHealthService::FAIL, SystemHealthService::UNKNOWN ],
                'وضعیتِ MariaDB باید یکی از وضعیت‌های تعریف‌شده باشد: ' . $version
            );
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
