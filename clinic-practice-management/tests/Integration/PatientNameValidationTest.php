<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

/**
 * ITEM 3 — نامِmade از نویسه‌هایِ پنهان/فاصله رد می‌شود و سقف ستون (۱۲۰) اجرا
 * می‌شود (نه Truncate بی‌صدا). یکی از ورودی‌هایِ ایجاد/ویرایشِ نامِ بیمار:
 * PatientService::create/update (و هرiseksi که از cleanName עובר می‌شود).
 */
final class PatientNameValidationTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $this->secretaryUserId = (int) wp_create_user(
            'pnval_sec',
            'pass-12345',
            'pnval@test.local',
            ['role' => 'cpms_secretary']
        );
    }

    private function service(): PatientService
    {
        return App::patientService();
    }

    /** نامی که تنها از ZWNJ (U+200C) ساخته شده → رد می‌شود. */
    public function testInvisibleOnlyFirstNameRejectedOnCreate(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->service()->create([
                'first_name' => "\u{200C}",
                'last_name' => 'تست',
                'mobile' => '+989121110001',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    /** نامی که تنها از فاصله و نویسه‌های پنهان ساخته شده → رد می‌شود. */
    public function testInvisibleOnlyLastNameRejectedOnCreate(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->service()->create([
                'first_name' => 'تست',
                'last_name' => " \u{200B}\u{200E}\t ",
                'mobile' => '+989121110002',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    /** نام بسیار بلند (>۱۲۰) → رد می‌شود، نه Truncate. */
    public function testTooLongNameRejectedOnCreate(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->service()->create([
                'first_name' => str_repeat('ا', 121),
                'last_name' => 'تست',
                'mobile' => '+989121110003',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    /** ZWNJ «دentroِ» کلمه فارسی معتبر است (س‌ام). */
    public function testZwnjInsidePersianWordAccepted(): void
    {
        $created = $this->service()->create([
            'first_name' => "س\u{200C}ام",
            'last_name' => 'تست',
            'mobile' => '+989121110004',
        ], $this->secretaryUserId);
        self::assertSame("س\u{200C}ام", $created['first_name']);
    }

    /**update با نام نادیده‌گرفته → رد می‌شود. */
    public function testInvisibleNameRejectedOnUpdate(): void
    {
        $created = $this->service()->create([
            'first_name' => 'تست',
            'last_name' => 'اصلی',
            'mobile' => '+989121110005',
        ], $this->secretaryUserId);

        $this->expectException(BookingException::class);
        try {
            $this->service()->update((int) $created['id'], [
                'last_name' => "\u{FEFF}",
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }
}
