<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

/**
 * M4 — Patient name entrances must reject empty, invisible-only and
 * bidi-control names, and must preserve valid Persian names with ZWNJ.
 *
 * Through-service coverage of the shared first_name/last_name validator
 * (PatientService::cleanName via validateForUpdate), on the secretary
 * create (D4) and update (D5) paths. Note: on update, `''` means "field not
 * supplied" by contract and is skipped — `' '` (whitespace/invisible) is the
 * "empty name" probe there.
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
            'secretary_name_tests',
            'pass-12345',
            'secname@test.local',
            [ 'role' => 'cpms_secretary' ]
        );
    }

    private function service()
    {
        return App::patientService();
    }

    private function baseFields(): array
    {
        static $n = 0;
        $n++;
        return [
            'first_name' => 'علی',
            'last_name' => 'تست',
            'mobile' => '0912' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
        ];
    }

    private function createPatient(array $overrides): array
    {
        return $this->service()->create(array_merge($this->baseFields(), $overrides), $this->secretaryUserId);
    }

    public function testCreateStoresValidPersianZwnjNameExactly(): void
    {
        $created = $this->createPatient([
            'first_name' => "علی\u{200C}رضا", // ZWNJ inside a Persian word — valid
            'last_name' => "طاهری",
        ]);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame("علی\u{200C}رضا", (string) $stored['first_name']);
        $this->assertSame('طاهری', (string) $stored['last_name']);
    }

    public function testCreateStoresTrimmedValidatedNameNotRawInput(): void
    {
        $created = $this->createPatient([ 'first_name' => "  علی  " ]);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame('علی', (string) $stored['first_name']);
    }

    public function testCreateRejectsEmptyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => '' ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام و نام خانوادگی الزامی است', $e->getMessage());
            throw $e;
        }
    }

    public function testCreateRejectsWhitespaceOnlyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "   \t  " ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testCreateRejectsInvisibleOnlyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "\u{200B}\u{200C}\u{2060}" ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(
                'نام نمی‌تواند فقط از نویسه‌های نامرئی یا فاصله تشکیل شده باشد',
                $e->getMessage()
            );
            throw $e;
        }
    }

    public function testCreateRejectsBidiControlInFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "علی\u{202E}رضا" ]); // RLO
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage());
            throw $e;
        }
    }

    public function testCreateRejectsBidiControlInLastName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'last_name' => "تست\u{2066}" ]); // LRI
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage());
            throw $e;
        }
    }

    public function testStaffUpdateRejectsBidiControlAndPreservesStoredRow(): void
    {
        $created = $this->createPatient([]);
        $before = $this->service()->get((int) $created['id']);

        try {
            $this->service()->update((int) $created['id'], [
                'first_name' => "تست\u{202E}",
            ], $this->secretaryUserId);
            $this->fail('a bidi-control name must be rejected on the update path');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(
                'نام حاوی نویسه‌های کنترلِ جهت نوشتار است',
                $e->getMessage()
            );
        }

        $after = $this->service()->get((int) $created['id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name'],
            'a rejected update must not alter the stored name');
    }

    public function testStaffUpdatePersistsValidZwnjNameExactly(): void
    {
        $created = $this->createPatient([]);
        $this->service()->update((int) $created['id'], [
            'first_name' => "نیما\u{200C}طاهری",
            'last_name' => "زکی\u{200C}زاده",
        ], $this->secretaryUserId);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame("نیما\u{200C}طاهری", (string) $stored['first_name']);
        $this->assertSame("زکی\u{200C}زاده", (string) $stored['last_name']);
    }

    public function testStaffUpdateRejectsWhitespaceOnlyName(): void
    {
        $created = $this->createPatient([]);
        $this->expectException(BookingException::class);
        try {
            $this->service()->update((int) $created['id'], [
                'first_name' => '   ',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }
}
