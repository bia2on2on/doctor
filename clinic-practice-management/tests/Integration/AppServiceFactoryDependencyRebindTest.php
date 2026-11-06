<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/** Independent regression proof for function-local static App factories after App singleton reset. */
final class AppServiceFactoryDependencyRebindTest extends WP_UnitTestCase
{
    public function testBookingAndOtpFactoriesRebuildWhenTheirDatabaseSingletonIsReset(): void
    {
        $bookingBefore = App::bookingService();
        $otpBefore     = App::otpService();
        self::assertInstanceOf(BookingService::class, $bookingBefore);
        self::assertInstanceOf(OtpService::class, $otpBefore);

        $databaseBefore = App::db();
        $reflection = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'smsService', 'licenseGate', 'visitService',
        ] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        $bookingAfter = App::bookingService();
        $otpAfter     = App::otpService();

        self::assertNotSame($databaseBefore, App::db(), 'the App database singleton was replaced for the reset simulation');
        self::assertNotSame($bookingBefore, $bookingAfter, 'bookingService must rebind its cached graph to reset App singletons');
        self::assertNotSame($otpBefore, $otpAfter, 'otpService must rebind its cached graph to reset App singletons');
    }
}
