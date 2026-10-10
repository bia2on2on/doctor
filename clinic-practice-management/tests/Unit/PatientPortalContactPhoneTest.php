<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Admin\PatientPortalPage;
use PHPUnit\Framework\TestCase;

/**
 * Cross-phase correction — Patient Portal «تماس با مطب» phone.
 *
 * Contract: the Patient Portal contact phone must come from the canonical
 * persisted Clinic Profile (`cpms_clinics.phone`), resolved through the trusted
 * Patient Portal context (the current user's active linked records) — never
 * from the obsolete installation-wide `clinic.phone` settings key.
 *
 * Invariants under test:
 *  - `cpms_clinics.phone` is the only canonical source (no legacy fallback).
 *  - Clinic resolution goes through the trusted linked records; a raw Clinic ID
 *    is never an authorization input.
 *  - Unknown (0 records) or ambiguous (>1 records) context fails closed; no
 *    Clinic is ever selected implicitly (never the first).
 *  - Missing canonical phone => existing safe empty presentation.
 *  - No cross-Clinic contact information leakage.
 */
final class PatientPortalContactPhoneTest extends TestCase
{
    /**
     * @return array<string, mixed> A linked-record row as produced by PatientService::linked_records().
     */
    private static function linkedRecord( int $clinicId ): array
    {
        return [
            'link_id'              => 100 + $clinicId,
            'clinic_id'            => $clinicId,
            'clinic_name'          => 'Clinic ' . $clinicId,
            'patient_id'           => 500 + $clinicId,
            'patient_display_name' => 'Patient ' . $clinicId,
            'mrn'                  => 'MR-' . $clinicId,
            'is_primary'           => true,
        ];
    }

    public function testSoleLinkedRecordYieldsCanonicalClinicPhone(): void
    {
        $calls = [];

        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ) use ( &$calls ): ?string {
                $calls[] = $clinicId;

                return $clinicId === 7 ? '+98-21-555-0100' : null;
            }
        );

        self::assertSame( '+98-21-555-0100', $phone, 'Sole trusted linked record must yield the canonical cpms_clinics.phone.' );
        self::assertSame( [ 7 ], $calls, 'Clinic must resolve through the linked record — not a fixed/first Clinic.' );
    }

    public function testStaleLegacyPhoneCannotOverrideCanonicalClinicPhone(): void
    {
        // The obsolete installation-wide `clinic.phone` value may exist in the
        // installation, but the resolver's only input is the canonical
        // `cpms_clinics` lookup — the legacy value has no channel into the result.
        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ): ?string {
                return $clinicId === 7 ? '+98-21-555-0100' : null;
            }
        );

        self::assertSame( '+98-21-555-0100', $phone );
        self::assertStringNotContainsString( 'LEGACY', $phone, 'No legacy installation-wide phone may leak into the portal contact line.' );
    }

    public function testMissingCanonicalPhoneNeverFallsBackToLegacyPhone(): void
    {
        // cpms_clinics.phone is NULL (missing canonical) — the safe empty
        // presentation must win even though a legacy settings phone "exists".
        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ): ?string {
                return null;
            }
        );

        self::assertSame( '', $phone, 'Missing canonical phone must produce the safe empty presentation — no legacy fallback.' );
    }

    public function testEmptyOrWhitespaceCanonicalPhoneProducesSafeEmptyPresentation(): void
    {
        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ): ?string {
                return '   ';
            }
        );

        self::assertSame( '', $phone );
    }

    public function testUnknownClinicContextFailsClosed(): void
    {
        $calls = [];

        $phone = PatientPortalPage::contact_phone(
            [],
            static function ( int $clinicId ) use ( &$calls ): ?string {
                $calls[] = $clinicId;

                return '+98-21-555-0100';
            }
        );

        self::assertSame( '', $phone, 'Unknown Clinic context (no linked record) must fail closed.' );
        self::assertSame( [], $calls, 'No Clinic lookup may happen without a trusted context.' );
    }

    public function testTwoLinkedClinicsFailClosedWithoutImplicitFirstSelection(): void
    {
        $calls = [];

        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ), self::linkedRecord( 9 ) ],
            static function ( int $clinicId ) use ( &$calls ): ?string {
                $calls[] = $clinicId;

                return '+98-21-555-0' . $clinicId;
            }
        );

        self::assertSame( '', $phone, 'Ambiguous Clinic context must fail closed; the explicit selection policy lives in the portal sections.' );
        self::assertSame( [], $calls, 'Ambiguity must never resolve to the first Clinic implicitly.' );
    }

    public function testCanonicalLookupFailureFailsClosed(): void
    {
        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ): ?string {
                throw new \RuntimeException( 'simulated query failure' );
            }
        );

        self::assertSame( '', $phone, 'A failing canonical lookup must fail closed (never a legacy fallback).' );
    }

    public function testInvalidRecordClinicIdFailsClosedWithoutLookup(): void
    {
        $calls = [];

        $phone = PatientPortalPage::contact_phone(
            [ [ 'link_id' => 5, 'clinic_name' => 'broken-record' ] ],
            static function ( int $clinicId ) use ( &$calls ): ?string {
                $calls[] = $clinicId;

                return '+98-21-555-0100';
            }
        );

        self::assertSame( '', $phone, 'A record without a positive clinic_id must fail closed.' );
        self::assertSame( [], $calls, 'No lookup may run on an invalid trusted identifier.' );
    }

    public function testTwoClinicIsolationUsesOnlyTheOwnLinkedClinicPhone(): void
    {
        // Clinic A (linked to this patient) and Clinic B (not linked) both have
        // canonical phones. Only A's phone may be resolved and returned.
        $canonical = [
            7 => '+98-21-555-AAAA',
            9 => '+98-21-555-BBBB',
        ];
        $calls = [];

        $phone = PatientPortalPage::contact_phone(
            [ self::linkedRecord( 7 ) ],
            static function ( int $clinicId ) use ( $canonical, &$calls ): ?string {
                $calls[] = $clinicId;

                return $canonical[ $clinicId ] ?? null;
            }
        );

        self::assertSame( '+98-21-555-AAAA', $phone );
        self::assertSame( [ 7 ], $calls, 'Only the patient\'s own linked Clinic may be queried.' );
        self::assertStringNotContainsString( 'BBBB', $phone, 'No cross-Clinic contact information leakage.' );
    }
}
