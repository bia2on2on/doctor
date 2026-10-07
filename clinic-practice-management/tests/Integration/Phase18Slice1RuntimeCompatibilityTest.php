<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 18 — Slice 18-1: قراردادِ سازگاریِ زمان‌اجرا روی وردپرسِ واقعی.
 *
 * فقط آنچه با وردپرس/دیتابیسِ واقعی معنا دارد:
 *  ۱. گزارش سلامتِ نصب دو چکِ صریحِ `wp.version` و `db.mysql_version` دارد و
 *     روی میزبانِ پشتیبانی‌شدهٔ CI (WP 6.7.2 + MySQL 8) مدرکِ واقعی می‌دهد.
 *  ۲. نقطهٔ بدونِ احرازِ `/clinic/v1/health` با این برش گسترش نمی‌یابد: نه
 *     کلیدِ جدید، نه جزئیاتِ نسخهٔ وردپرس/سرورِ دیتابیس.
 *
 * این کلاس هیچ قفلِ بالینی/داده/بکاپ یا رفتارِ مخربی اضافه نمی‌کند —
 * `host.status` صرفاً یک برچسبِ گزارش است.
 */
final class Phase18Slice1RuntimeCompatibilityTest extends WP_UnitTestCase
{
    private const WP_KEY = 'wp.version';
    private const DB_KEY = 'db.mysql_version';

    /**
     * @param list<array{key:string,label:string,status:string,detail:string}> $checks
     *
     * @return array{key:string,label:string,status:string,detail:string}|null
     */
    private static function findCheck(array $checks, string $key): ?array
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        return null;
    }

    public function testHealthReportContainsExplicitWordPressAndDatabaseServerChecks(): void
    {
        $report = App::systemHealthService()->run();

        self::assertArrayHasKey('checks', $report);
        /** @var list<array{key:string,label:string,status:string,detail:string}> $checks */
        $checks = $report['checks'];

        $wp = self::findCheck($checks, self::WP_KEY);
        $db = self::findCheck($checks, self::DB_KEY);

        self::assertNotNull($wp, 'گزارش سلامت باید چکِ صریحِ نسخهٔ وردپرس داشته باشد.');
        self::assertNotNull($db, 'گزارش سلامت باید چکِ صریحِ نسخهٔ سرورِ MySQL داشته باشد.');

        foreach ([$wp, $db] as $row) {
            self::assertSame(['key', 'label', 'status', 'detail'], array_keys($row), 'شکلِ ردیف تغییر نکند.');
            self::assertNotSame('', trim($row['label']));
            self::assertNotSame('', trim($row['detail']), 'جزئیات باید مدرکِ واقعی گزارش کند.');
        }

        // روی این میزبان (WP 6.7.2 + MySQL 8 طبق ci.yml) مدرکِ نسخه در دسترس است
        // و کفِ اعلام‌شده برقرار است؛ UNKNOWN یعنی منبعِ نسخه درست خوانده نشده.
        self::assertSame(SystemHealthService::PASS, $wp['status'], $wp['detail']);
        self::assertSame(SystemHealthService::PASS, $db['status'], $db['detail']);

        // مدرکِ گزارش‌شده باید با منبعِ واقعی هم‌خوان باشد (گزارشِ تخیلی نباشد).
        self::assertStringContainsString((string) get_bloginfo('version'), $wp['detail']);
        $serverVersion = App::db()->fetchValue('SELECT VERSION()');
        self::assertIsString($serverVersion);
        self::assertStringContainsString(trim($serverVersion), $db['detail']);
    }

    public function testPublicHealthEndpointIsNotWidenedWithRuntimeCompatibilityDetails(): void
    {
        wp_set_current_user(0); // بدون احراز — همان سطحِ دسترسیِ عمومی

        $response = rest_do_request(new WP_REST_Request('GET', '/clinic/v1/health'));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());

        /** @var array<string, mixed> $payload */
        $payload = $response->get_data();

        // Envelopeی که RestBase::success() می‌سازد — بدون لایه/کلیدِ جدید.
        self::assertSame(['data'], array_keys($payload));
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['jobs', 'ok', 'plugin', 'schema', 'time_utc', 'version'], $keys, 'قراردادِ عمومی گسترش نیافته است.');

        $json = (string) wp_json_encode($payload);

        self::assertStringNotContainsString(self::WP_KEY, $json, 'کلیدِ wp.version نباید عمومی شود.');
        self::assertStringNotContainsString(self::DB_KEY, $json, 'کلیدِ db.mysql_version نباید عمومی شود.');
        self::assertStringNotContainsString('SUPPORTED', $json, 'طبقه‌بندیِ میزبان نباید عمومی شود.');
        self::assertStringNotContainsString('"checks"', $json, 'جدولِ چک‌ها نباید عمومی شود.');
        self::assertStringNotContainsString('"host"', $json, 'طبقه‌بندیِ میزبان نباید عمومی شود.');

        // خودِ رشته‌هایِ نسخه هم نباید به بیرون درز کنند.
        self::assertStringNotContainsString((string) get_bloginfo('version'), $json);
        $serverVersion = App::db()->fetchValue('SELECT VERSION()');
        self::assertIsString($serverVersion);
        self::assertStringNotContainsString(trim($serverVersion), $json);
    }
}
