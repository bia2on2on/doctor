<?php
/**
 * Unit coverage for the offline clinical-specialty label catalogue and its
 * canonical / user-defined ("Other / سایر") separation.
 *
 * Contract under test:
 *  - offline, versioned, unique labels with stable slugs and no invented code system;
 *  - lookup by Persian label, ASCII slug or Latin alias, with the SAME Persian/Arabic
 *    normalization contract as the geography catalogue;
 *  - a persisted canonical label is classified canonical; anything else is classified
 *    user-defined and is never coerced into a canonical bucket (historical free-text
 *    rows survive untouched);
 *  - resolution validates on the server: length cap, letter requirement, no sentinel,
 *    ambiguous canonical+custom payloads rejected, typed duplicates collapse to canonical.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Clinical\MedicalSpecialtyCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MedicalSpecialtyCatalogTest extends TestCase {

    public function testCatalogueIsOfflineVersionedAndInternallyConsistent(): void {
        self::assertSame( '2026.10-cpms-specialty-labels-1', MedicalSpecialtyCatalog::DATASET_VERSION );
        self::assertSame( '__other__', MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL );
        self::assertLessThanOrEqual( 190, MedicalSpecialtyCatalog::MAX_SPECIALTY_LENGTH, 'must fit cpms_clinicians.specialty VARCHAR(190)' );

        $labels = MedicalSpecialtyCatalog::labels();
        self::assertNotEmpty( $labels );
        self::assertGreaterThan( 40, count( $labels ), 'the catalogue must be useful for real clinics' );

        $slugs = [];
        foreach ( MedicalSpecialtyCatalog::specialties() as $item ) {
            self::assertNotSame( '', $item['label'] );
            self::assertNotSame( '', $item['slug'] );
            self::assertSame( 1, preg_match( '/^[a-z0-9-]+$/', $item['slug'] ), 'slug: ' . $item['slug'] );
            self::assertNotContains( $item['slug'], $slugs, 'duplicate slug: ' . $item['slug'] );
            $slugs[] = $item['slug'];
            // Every label is normalized already (canonical Persian Yeh/Kaf).
            self::assertSame( MedicalSpecialtyCatalog::normalize_text( $item['label'] ), $item['label'] );
            self::assertLessThanOrEqual(
                MedicalSpecialtyCatalog::MAX_SPECIALTY_LENGTH,
                mb_strlen( $item['label'] ),
                'label too long: ' . $item['label']
            );
        }

        self::assertSame( count( $labels ), count( array_unique( $labels ) ), 'labels must be unique' );
        self::assertNotContains( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, $labels );

        // Client map mirrors the grouped catalogue (offline search source).
        $map = MedicalSpecialtyCatalog::client_catalog_map();
        self::assertCount( count( MedicalSpecialtyCatalog::groups() ), $map );
        $flattened = [];
        foreach ( $map as $group_items ) {
            foreach ( $group_items as $label ) {
                $flattened[] = $label;
            }
        }
        self::assertSame( $labels, $flattened );
    }

    public function testCatalogueCoversGeneralMedicalDentalAndAlliedLabels(): void {
        $labels = MedicalSpecialtyCatalog::labels();
        foreach (
            [
                'پزشک عمومی',
                'متخصص قلب و عروق',
                'متخصص اطفال و نوزادان',
                'متخصص زنان و زایمان',
                'متخصص مغز و اعصاب',
                'متخصص روانپزشکی',
                'متخصص چشم',
                'متخصص پوست و مو',
                'متخصص ارتوپدی',
                'متخصص جراحی عمومی',
                'متخصص بیهوشی',
                'متخصص رادیولوژی',
                'دندانپزشک عمومی',
                'متخصص ارتودنسی',
                'فیزیوتراپی',
                'روان‌شناسی بالینی',
            ] as $expected
        ) {
            self::assertContains( $expected, $labels, 'missing label: ' . $expected );
        }
    }

    public function testNoDefaultIsEverInferredForEmptyOrUnknownInput(): void {
        self::assertNull( MedicalSpecialtyCatalog::find( '' ) );
        self::assertNull( MedicalSpecialtyCatalog::find( '   ' ) );
        self::assertNull( MedicalSpecialtyCatalog::find( 'تخصص ناشناختهٔ آتلانتیس' ) );
        self::assertNull( MedicalSpecialtyCatalog::resolve_submitted( null, null ) );
        self::assertNull( MedicalSpecialtyCatalog::resolve_submitted( '', '' ) );

        $empty = MedicalSpecialtyCatalog::classify( null );
        self::assertSame( '', $empty['label'] );
        self::assertNull( $empty['slug'] );
        self::assertFalse( $empty['custom'] );
    }

    public function testLookupByLabelSlugAndLatinAliasWithArabicVariants(): void {
        $by_label = MedicalSpecialtyCatalog::find( 'متخصص قلب و عروق' );
        self::assertNotNull( $by_label );
        self::assertSame( 'cardiology', $by_label['slug'] );

        self::assertSame( 'متخصص قلب و عروق', MedicalSpecialtyCatalog::find( 'cardiology' )['label'] );
        self::assertSame( 'متخصص قلب و عروق', MedicalSpecialtyCatalog::find( 'Cardiology' )['label'] );

        // A lookalike that is not in the catalogue is never matched.
        self::assertFalse( MedicalSpecialtyCatalog::is_known( 'متخصص قلب و عروڄ' ) );
        // Arabic Yeh/Kaf + surrounding whitespace resolve to the canonical Persian label.
        $arabic = MedicalSpecialtyCatalog::find( "  متخصص پوست و مو  " );
        self::assertNotNull( $arabic );
        self::assertSame( 'dermatology', $arabic['slug'] );
        self::assertSame( 'متخصص چشم', MedicalSpecialtyCatalog::find( 'متخصص چشم' )['label'] );
    }

    public function testClassifySeparatesCanonicalFromUserDefinedValues(): void {
        $canonical = MedicalSpecialtyCatalog::classify( 'متخصص اطفال و نوزادان' );
        self::assertFalse( $canonical['custom'] );
        self::assertSame( 'pediatrics', $canonical['slug'] );
        self::assertSame( 'medical', $canonical['group'] );

        // A value typed through "Other / سایر" stays user-defined and readable.
        $custom = MedicalSpecialtyCatalog::classify( 'فلوشیپ ناباروری' );
        self::assertTrue( $custom['custom'] );
        self::assertNull( $custom['slug'] );
        self::assertSame( 'فلوشیپ ناباروری', $custom['label'] );

        // Historical free text written before the catalogue is NEVER dropped or coerced.
        $legacy = MedicalSpecialtyCatalog::classify( 'پزشک خانواده - مرکز بهداشت' );
        self::assertTrue( $legacy['custom'] );
        self::assertSame( 'پزشک خانواده - مرکز بهداشت', $legacy['label'] );
    }

    public function testResolveCanonicalSelectionPersistsTheCanonicalLabel(): void {
        self::assertSame(
            'متخصص گوش و حلق و بینی',
            MedicalSpecialtyCatalog::resolve_submitted( 'متخصص گوش و حلق و بینی', null )
        );
        // A slug or Latin alias submitted by a client also resolves canonically.
        self::assertSame( 'متخصص بیهوشی', MedicalSpecialtyCatalog::resolve_submitted( 'anesthesiology', null ) );
        self::assertSame( 'متخصص بیهوشی', MedicalSpecialtyCatalog::resolve_submitted( 'Anesthesiology', null ) );
    }

    public function testResolveOtherSentinelPersistsTheValidatedManualLabel(): void {
        self::assertSame(
            'فلوشیپ ناباروری',
            MedicalSpecialtyCatalog::resolve_submitted( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, 'فلوشیپ ناباروری' )
        );
        // No-JS form: no selection at all, only the manual field.
        self::assertSame(
            'طب سوزنی',
            MedicalSpecialtyCatalog::resolve_submitted( null, 'طب سوزنی' )
        );
        // Normalization applies to manual input too (Arabic Kaf -> Persian Kaf).
        self::assertSame(
            'کاردرمانی ذهنی',
            MedicalSpecialtyCatalog::resolve_submitted( '__other__', "  كاردرماني ذهني  " )
        );
    }

    public function testTypedDuplicateOfACanonicalLabelCollapsesToTheCatalogue(): void {
        // Typed with Arabic Yeh: the canonical Persian label must win, so no shadow
        // duplicate of a catalogue entry is ever persisted.
        self::assertSame(
            'فیزیوتراپی',
            MedicalSpecialtyCatalog::resolve_submitted( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, 'فيزيوتراپي' )
        );
        self::assertFalse(
            MedicalSpecialtyCatalog::classify(
                (string) MedicalSpecialtyCatalog::resolve_submitted( '__other__', 'فيزيوتراپي' )
            )['custom']
        );
    }

    public function testUnsafeOrUnusableManualSpecialtiesAreRejected(): void {
        // "Other" chosen but nothing typed == no specialty submitted (column stays NULL).
        self::assertNull( MedicalSpecialtyCatalog::resolve_submitted( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, '' ) );
        self::assertNull( MedicalSpecialtyCatalog::resolve_submitted( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, '   ' ) );

        $rejected = [
            '۱۲۳۴',                   // digits only — no letter
            '!!!',                   // punctuation only
            'پ',                      // single character
            '__other__',             // the sentinel itself
            str_repeat( 'تخصص', 40 ), // 160 chars > MAX_SPECIALTY_LENGTH
        ];

        foreach ( $rejected as $bad ) {
            try {
                MedicalSpecialtyCatalog::resolve_submitted( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL, $bad );
                self::fail( 'must reject manual specialty: ' . $bad );
            } catch ( InvalidArgumentException $e ) {
                self::assertNotSame( '', $e->getMessage() );
            }
        }
    }

    public function testAmbiguousCanonicalPlusDifferentManualSpecialtyIsRejected(): void {
        $this->expectException( InvalidArgumentException::class );
        MedicalSpecialtyCatalog::resolve_submitted( 'متخصص چشم', 'فلوشیپ ناباروری' );
    }

    public function testManualSpecialtyCannotCarryMarkup(): void {
        $resolved = (string) MedicalSpecialtyCatalog::resolve_submitted(
            MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL,
            '<script>alert(1)</script> طب سفر'
        );
        foreach ( [ '<', '>', '"', '/' ] as $forbidden ) {
            self::assertStringNotContainsString( $forbidden, $resolved );
        }
        self::assertStringContainsString( 'طب سفر', $resolved );
    }
}
