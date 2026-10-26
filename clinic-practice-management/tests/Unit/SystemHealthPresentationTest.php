<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use PHPUnit\Framework\TestCase;

/**
 * CPMS Classic Admin UX (G) — گروه‌بندیِ خالصِ ردیف‌های سلامت.
 *
 * ریشهٔ نقص: صفحهٔ سلامت همهٔ ردیف‌های غیرِ PASS را در یک سبدِ «هشدار» می‌ریخت؛
 * در نتیجه «پیکربندیِ اختیاری» (مثل بکاپِ خاموش)، «هشدارِ قابل‌اقدام» و
 * «مسدودکنندهٔ واقعی» یک‌جا و با یک وزن دیده می‌شدند و مرزِ آن‌ها قابلِ‌داوری
 * نبود. این تست تثبیت می‌کند که تفکیک، صریح، کامل (بدونِ حذف) و بدونِ
 * «سبزکردنِ» نمایشی است.
 */
final class SystemHealthPresentationTest extends TestCase
{
    private const KEY_UNKNOWN_LABEL = 'نسخهٔ سرور دیتابیس (MariaDB)';

    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private static function row( string $key, string $status, string $detail = '' ): array {
        return [ 'key' => $key, 'label' => $key, 'status' => $status, 'detail' => $detail ];
    }

    public function testStatusesAreSplitIntoFourDistinctGroups(): void
    {
        $checks = [
            self::row( 'php.version', SystemHealthService::PASS ),
            self::row( 'db.reachable', SystemHealthService::FAIL, 'down' ),
            self::row( 'wp.version', SystemHealthService::UNKNOWN, 'unknown' ),
            self::row( 'cron.jobs', SystemHealthService::WARNING, 'warn' ),
            self::row( 'backup.enabled', SystemHealthService::NOT_CONFIGURED, 'off' ),
        ];

        $groups = SystemHealthService::group_checks( $checks );

        self::assertSame( [ 'php.version' ], array_column( $groups['healthy'], 'key' ) );
        self::assertSame( [ 'db.reachable', 'wp.version' ], array_column( $groups['blockers'], 'key' ) );
        self::assertSame( [ 'cron.jobs' ], array_column( $groups['warnings'], 'key' ) );
        self::assertSame( [ 'backup.enabled' ], array_column( $groups['optional'], 'key' ) );
    }

    public function testNoRowIsEverDroppedByTheGrouping(): void
    {
        $checks = [
            self::row( 'a', SystemHealthService::PASS ),
            self::row( 'b', SystemHealthService::FAIL, 'x' ),
            self::row( 'c', SystemHealthService::UNKNOWN, 'y' ),
            self::row( 'd', SystemHealthService::WARNING, 'z' ),
            self::row( 'e', SystemHealthService::NOT_CONFIGURED, 'w' ),
            self::row( 'f', 'an-unexpected-status', 'v' ),
        ];

        $groups = SystemHealthService::group_checks( $checks );

        $grouped_keys = array_merge(
            array_column( $groups['blockers'], 'key' ),
            array_column( $groups['warnings'], 'key' ),
            array_column( $groups['optional'], 'key' ),
            array_column( $groups['healthy'], 'key' )
        );

        self::assertCount( count( $checks ), $grouped_keys, 'هیچ ردیفی نباید حذف شود.' );
        self::assertSame( [], array_diff( array_column( $checks, 'key' ), $grouped_keys ) );
    }

    public function testUnexpectedStatusIsTreatedAsABlockerNotHidden(): void
    {
        $groups = SystemHealthService::group_checks( [ self::row( 'x', 'something-new' ) ] );

        self::assertSame( [ 'x' ], array_column( $groups['blockers'], 'key' ), 'وضعیتِ ناشناخته باید محافظه‌کارانه دیده شود، نه پنهان.' );
        self::assertSame( [], $groups['healthy'] );
    }

    public function testEmptyInputProducesFourEmptyGroups(): void
    {
        $groups = SystemHealthService::group_checks( [] );

        self::assertSame(
            [ 'blockers' => [], 'warnings' => [], 'optional' => [], 'healthy' => [] ],
            $groups
        );
    }

    public function testUncertifiedMariaDbRowIsAWarningNotABlocker(): void
    {
        $groups = SystemHealthService::group_checks(
            [
                [
                    'key'    => 'db.mysql_version',
                    'label'  => 'نسخهٔ سرور دیتابیس (MariaDB)',
                    'status' => SystemHealthService::UNKNOWN,
                    'detail' => 'mariadb:unverified | 11.5.2-MariaDB — آزموده‌نشده',
                ],
                [
                    'key'    => 'db.mysql_version',
                    'label'  => 'نسخهٔ سرور دیتابیس',
                    'status' => SystemHealthService::UNKNOWN,
                    'detail' => 'نسخهٔ سرور دیتابیس در دسترس نیست',
                ],
            ]
        );

        self::assertCount( 1, $groups['warnings'], 'MariaDBِ آزموده‌نشین هشدار است.' );
        self::assertSame( self::KEY_UNKNOWN_LABEL, $groups['warnings'][0]['label'] );
        self::assertCount( 1, $groups['blockers'], 'مدرکِ ناخوانا مسدودکننده می‌ماند.' );
        self::assertSame( 'نسخهٔ سرور دیتابیس', $groups['blockers'][0]['label'] );
    }

    public function testOptionalConfigurationIsNotPresentedAsABlocker(): void
    {
        $groups = SystemHealthService::group_checks(
            [
                self::row( 'backup.enabled', SystemHealthService::NOT_CONFIGURED, 'غیرفعال' ),
                self::row( 'license.gateway', SystemHealthService::NOT_CONFIGURED, 'پیکربندی‌نشده' ),
            ]
        );

        self::assertSame( [], $groups['blockers'], 'پیکربندیِ اختیاری مسدودکننده نیست.' );
        self::assertCount( 2, $groups['optional'] );
    }
}
