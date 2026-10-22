<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\SystemHealthService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the D3 upload-limit diagnosis.
 *
 * The release ZIP can exceed PHP's default post_max_size; WordPress then shows
 * a generic "link expired" error. CPMS must (a) report the real host limits and
 * (b) never silently raise them. Pure function, no WordPress, no DB.
 */
final class UploadLimitsDiagnosisTest extends TestCase {

    public function testDefaultHostLimitsAreReportedAsWarningWithActionableGuidance(): void {
        $check = SystemHealthService::upload_limits_check( '8M', '2M' );

        self::assertSame( 'php.upload_limits', $check['key'] );
        self::assertSame( SystemHealthService::WARNING, $check['status'], 'default 8M/2M cannot carry the release ZIP' );
        self::assertStringContainsString( 'post_max_size=8M', $check['detail'] );
        self::assertStringContainsString( 'upload_max_filesize=2M', $check['detail'] );
        self::assertStringContainsString( 'پیوند منقضی شده', $check['detail'], 'the misleading WordPress message is named' );
        self::assertStringContainsString( '۲۰M', $check['detail'], 'the host-level remediation threshold is stated (Persian digits, matching the UI)' );
        self::assertStringContainsString( 'wp plugin install', $check['detail'], 'a no-limit-change installation path is offered' );
    }

    public function testAdequateLimitsPass(): void {
        $check = SystemHealthService::upload_limits_check( '64M', '64M' );
        self::assertSame( SystemHealthService::PASS, $check['status'] );

        // post_max_size alone is enough when upload_max_filesize is unlimited.
        self::assertSame( SystemHealthService::PASS, SystemHealthService::upload_limits_check( '32M', '0' )['status'] );
    }

    public function testOneInsufficientLimitStillWarns(): void {
        self::assertSame(
            SystemHealthService::WARNING,
            SystemHealthService::upload_limits_check( '32M', '2M' )['status'],
            'post_max_size adequate but upload_max_filesize too small is still a real failure'
        );
        self::assertSame(
            SystemHealthService::WARNING,
            SystemHealthService::upload_limits_check( '8M', '32M' )['status'],
            'upload_max_filesize adequate but post_max_size too small overflows the request'
        );
    }

    public function testCheckNeverMutatesConfiguration(): void {
        $before_post   = (string) ini_get( 'post_max_size' );
        $before_upload = (string) ini_get( 'upload_max_filesize' );

        SystemHealthService::upload_limits_check();

        self::assertSame( $before_post, (string) ini_get( 'post_max_size' ), 'CPMS must never raise host limits silently' );
        self::assertSame( $before_upload, (string) ini_get( 'upload_max_filesize' ) );
    }

    public function testGigabyteAndUnknownValuesAreHandledSafely(): void {
        self::assertSame( SystemHealthService::PASS, SystemHealthService::upload_limits_check( '1G', '1G' )['status'] );
        // Unparseable values are unknown, never a false PASS.
        self::assertSame( SystemHealthService::WARNING, SystemHealthService::upload_limits_check( 'banana', '2M' )['status'] );
    }
}
