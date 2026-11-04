<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Validators\VisibleText;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item 3 — visually-empty names must be rejected at every name entrance.
 *
 * The ONE shared decision point is VisibleText::has_visible_content(): only a
 * string made solely of invisible characters (U+200B, U+200C, U+200D, U+200E,
 * U+200F, U+2060, U+FEFF) plus whitespace is rejected; a ZWNJ inside a Persian
 * word stays valid. The service cleaners are private but pure (no constructor
 * state), so they are exercised directly without WordPress or a database.
 */
final class VisibleTextTest extends TestCase
{
    public function testInvisibleOnlyStringsHaveNoVisibleContent(): void
    {
        // Each invisible char alone.
        $invisibles = [
            "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{2060}", "\u{FEFF}",
        ];
        foreach ($invisibles as $c) {
            self::assertFalse(VisibleText::has_visible_content($c));
        }

        // Mixed invisibles + whitespace/NBSP still carry nothing visible.
        self::assertFalse(VisibleText::has_visible_content("\u{200C} \u{200B}\u{00A0}\t"));
        self::assertFalse(VisibleText::has_visible_content(''));
        self::assertFalse(VisibleText::has_visible_content("  \n\t "));

        // Invalid UTF-8 fails closed.
        self::assertFalse(VisibleText::has_visible_content("\xC3\x28"));
    }

    public function testRealNamesKeepTheirContent(): void
    {
        // ZWNJ *inside* Persian words stays valid (nothing visible removed).
        self::assertTrue(VisibleText::has_visible_content('می‌روم'));
        self::assertTrue(VisibleText::has_visible_content('نیم‌فاصله‌ها'));
        self::assertTrue(VisibleText::has_visible_content('رضا احمدی'));
        self::assertTrue(VisibleText::has_visible_content('Zahra'));
        self::assertTrue(VisibleText::has_visible_content('آ'));

        // A ZWNJ alone next to nothing visible is still nothing visible.
        self::assertFalse(VisibleText::has_visible_content("\u{200C}"));
    }

    public function testPatientServiceCleanerRejectsInvisibleOnlyNames(): void
    {
        $service = (new ReflectionClass(PatientService::class))->newInstanceWithoutConstructor();
        $method  = new ReflectionMethod(PatientService::class, 'cleanName');

        try {
            $method->invoke($service, "\u{200C}");
            self::fail('a ZWNJ-only name must be rejected');
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
            self::assertSame(
                'نام نمی‌تواند فقط از نویسه‌های نامرئی یا فاصله تشکیل شده باشد',
                $e->getMessage()
            );
        }

        // A Persian name with inner ZWNJ passes unchanged (no rewriting).
        self::assertSame('علی‌رضا', $method->invoke($service, 'علی‌رضا'));

        // Established rules are untouched: empty / over-120 stay the old error.
        foreach (['', str_repeat('ی', 121)] as $bad) {
            try {
                $method->invoke($service, $bad);
                self::fail('empty or overlong names must keep the old rejection');
            } catch (BookingException $e) {
                self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
                self::assertSame('نام نامعتبر است', $e->getMessage());
            }
        }
    }

    public function testBookingIdentityStepCleanerRejectsInvisibleOnlyNames(): void
    {
        $service = (new ReflectionClass(BookingService::class))->newInstanceWithoutConstructor();
        $method  = new ReflectionMethod(BookingService::class, 'cleanBookingName');

        try {
            $method->invoke($service, " \u{200D}\u{2060} ");
            self::fail('an invisible-only name must be rejected at booking');
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }

        self::assertSame('سارا محمدی', $method->invoke($service, 'سارا محمدی'));
    }

    public function testAdminClinicianFormsEnforceTheSameHelper(): void
    {
        // Static wiring guard: both admin clinician-name entrances must call
        // the shared helper and cap at the VARCHAR(190) column limit.
        $root    = dirname(__DIR__, 2);
        $admin   = (string) file_get_contents($root . '/src/Admin/ClinicianAdminPage.php');
        $onboard = (string) file_get_contents(
            $root . '/src/Application/Onboarding/FacilitySetupService.php'
        );

        self::assertStringContainsString('VisibleText::has_visible_content', $admin);
        self::assertStringContainsString('> 190', $admin);
        self::assertStringContainsString('VisibleText::has_visible_content', $onboard);
    }
}
