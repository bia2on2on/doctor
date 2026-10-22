<?php
/**
 * Unit coverage for the "Other / سایر" manual city path of the offline Iranian
 * province/city catalogue.
 *
 * Contract under test (server-side authority — JavaScript is never trusted):
 *  - a manual city is ALWAYS bound to a selected canonical province;
 *  - the manual value is normalized (Arabic ي/ك, ZWNJ, control chars, whitespace),
 *    length-capped and stripped of every character that could forge an extra
 *    address segment or carry markup;
 *  - the persisted form carries an explicit marker so a user-defined city is
 *    distinguishable from a canonical catalogue city on every later read;
 *  - historical free-text addresses keep parsing EXACTLY as before (no marker,
 *    no promotion of a street detail into a city);
 *  - a typed name that duplicates a canonical city of the same province collapses
 *    to the canonical value (no shadow duplicate).
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IranCustomCityEntryTest extends TestCase {

    public function testCatalogueExposesTheOtherSentinelAndMarker(): void {
        self::assertSame( '__other__', IranProvinceCityCatalog::OTHER_CITY_SENTINEL );
        self::assertSame( 'سایر:', IranProvinceCityCatalog::CUSTOM_CITY_MARKER );
        self::assertSame( 80, IranProvinceCityCatalog::MAX_CUSTOM_CITY_LENGTH );
        // The sentinel is never a city of any province.
        foreach ( IranProvinceCityCatalog::provinces() as $province ) {
            self::assertNotContains( IranProvinceCityCatalog::OTHER_CITY_SENTINEL, $province['cities'] );
        }
    }

    public function testManualCityIsPersistedBoundToItsProvinceWithAnExplicitMarker(): void {
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            'خیابان آزادی، پلاک ۱۲',
            'تهران',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'شهرک نوین'
        );

        self::assertSame( 'تهران، سایر: شهرک نوین، خیابان آزادی، پلاک ۱۲', $composed );

        $parsed = IranProvinceCityCatalog::parse_address( $composed );
        self::assertSame( 'تهران', $parsed['province'] );
        self::assertSame( '', $parsed['city'], 'a user-defined city is never reported as a canonical city' );
        self::assertSame( 'شهرک نوین', $parsed['city_custom'] );
        self::assertSame( 'خیابان آزادی، پلاک ۱۲', $parsed['detail'] );
    }

    public function testManualCityRoundTripsIdempotently(): void {
        $first = IranProvinceCityCatalog::resolve_submitted_address(
            'بلوار امام، کوچه ۳',
            'خراسان رضوی',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'گلم بهار'
        );
        self::assertSame( 'خراسان رضوی، سایر: گلم بهار، بلوار امام، کوچه ۳', $first );

        // Re-submitting the persisted value with the same selectors changes nothing.
        $second = IranProvinceCityCatalog::resolve_submitted_address(
            $first,
            'خراسان رضوی',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'گلم بهار'
        );
        self::assertSame( $first, $second );

        // Switching to a canonical city of the same province replaces the slot cleanly
        // and never duplicates the province prefix or leaks the marker into the detail.
        $switched = IranProvinceCityCatalog::resolve_submitted_address( $first, 'خراسان رضوی', 'مشهد' );
        self::assertSame( 'خراسان رضوی، مشهد، بلوار امام، کوچه ۳', $switched );
        self::assertStringNotContainsString( 'سایر:', (string) $switched );
    }

    public function testManualCityAcceptsCustomValueWithoutSentinelToo(): void {
        // A no-JS form posts the manual field together with an empty city select.
        $composed = IranProvinceCityCatalog::resolve_submitted_address( null, 'البرز', '', 'محمدشهر جدید' );
        self::assertSame( 'البرز، سایر: محمدشهر جدید', $composed );
    }

    public function testManualCityNormalizesPersianArabicVariantsAndStripsUnsafeInput(): void {
        // Arabic Yeh/Kaf + ZWNJ noise + surrounding whitespace collapse to canonical Persian.
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            null,
            'اصفهان',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            "  كاشان\u{200C}جدید  "
        );
        self::assertSame( 'اصفهان، سایر: کاشان‌جدید', $composed );

        // The address separator and any markup are structurally impossible, so a
        // manual city can never forge an extra segment or inject HTML.
        $injected = IranProvinceCityCatalog::resolve_submitted_address(
            null,
            'تهران',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            '<script>alert(1)</script>، تهران'
        );
        foreach ( [ '<', '>', '/', '(', ')', '"' ] as $forbidden ) {
            self::assertStringNotContainsString(
                $forbidden,
                (string) $injected,
                'a manual city can never carry markup'
            );
        }
        // The pasted separator is stripped, so it can never forge a third segment:
        // exactly two remain (province، city) and nothing else.
        self::assertCount( 2, explode( '، ', (string) $injected ) );
        self::assertSame( 1, substr_count( (string) $injected, '،' ) );
    }

    public function testManualCityNamingARealCityOfThatProvinceCollapsesToTheCatalogue(): void {
        // «پردیس» really is a city of Tehran province: typing it through "Other" must
        // persist the canonical value, never a marked user-defined duplicate.
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            null,
            'تهران',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'پرديس' // Arabic Yeh variant
        );
        self::assertSame( 'تهران، پردیس', $composed );
        self::assertStringNotContainsString( 'سایر:', $composed );
    }

    public function testManualCityThatDuplicatesACanonicalCityCollapsesToTheCatalogue(): void {
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            null,
            'اصفهان',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'كاشان' // Arabic Kaf variant of the canonical «کاشان»
        );
        self::assertSame( 'اصفهان، کاشان', $composed );
        self::assertStringNotContainsString( 'سایر:', (string) $composed );

        $parsed = IranProvinceCityCatalog::parse_address( $composed );
        self::assertSame( 'کاشان', $parsed['city'] );
        self::assertSame( '', $parsed['city_custom'] );
    }

    public function testManualCityWithoutProvinceIsRejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'استان' );
        IranProvinceCityCatalog::resolve_submitted_address(
            'خیابان آزمون',
            '',
            IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
            'پردیس'
        );
    }

    public function testManualCityWithUnknownProvinceIsRejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'استان' );
        IranProvinceCityCatalog::resolve_submitted_address( null, 'آتلانتیس', IranProvinceCityCatalog::OTHER_CITY_SENTINEL, 'پردیس' );
    }

    public function testCrossProvinceCanonicalCityIsStillRejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'شهر' );
        IranProvinceCityCatalog::resolve_submitted_address( 'خیابان آزمون', 'تهران', 'تبریز' );
    }

    public function testAmbiguousCanonicalCityPlusDifferentManualCityIsRejected(): void {
        $this->expectException( InvalidArgumentException::class );
        IranProvinceCityCatalog::resolve_submitted_address( null, 'تهران', 'تهران', 'پردیس' );
    }

    public function testUnusableManualCityValuesAreRejected(): void {
        $rejected = [
            '',                      // empty
            '   ',                   // whitespace only
            '۱۲۳۴۵',                   // digits only — no letter
            '!!! ؟؟؟',                // punctuation only
            'ک',                      // single character
            '__other__',             // the sentinel itself
            'سایر:',                  // a bare marker
            str_repeat( 'شهر', 40 ),  // 160 chars > MAX_CUSTOM_CITY_LENGTH
        ];

        foreach ( $rejected as $bad ) {
            self::assertFalse(
                IranProvinceCityCatalog::is_valid_custom_city( 'تهران', $bad ),
                'must reject: ' . $bad
            );
        }

        self::assertTrue( IranProvinceCityCatalog::is_valid_custom_city( 'تهران', 'پردیس' ) );
        self::assertTrue( IranProvinceCityCatalog::is_valid_custom_city( 'تهران', 'چهاردانگه' ) );
        self::assertFalse( IranProvinceCityCatalog::is_valid_custom_city( 'آتلانتیس', 'پردیس' ) );
    }

    public function testOverlongManualCityCannotOverflowTheAddressColumn(): void {
        $long_detail = str_repeat( 'آ', 250 );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'طولانی' );
        IranProvinceCityCatalog::resolve_submitted_address( $long_detail, 'تهران', IranProvinceCityCatalog::OTHER_CITY_SENTINEL, 'پردیس' );
    }

    public function testHistoricalAddressesWithoutMarkerParseExactlyAsBefore(): void {
        // A street detail after a province must NEVER be promoted to a custom city.
        $legacy = 'تهران، خیابان ولیعصر، پلاک ۱';
        $parsed = IranProvinceCityCatalog::parse_address( $legacy );
        self::assertSame( 'تهران', $parsed['province'] );
        self::assertSame( '', $parsed['city'] );
        self::assertSame( '', $parsed['city_custom'], 'no guessing: unmarked text is never a user-defined city' );
        self::assertSame( 'خیابان ولیعصر، پلاک ۱', $parsed['detail'] );

        $free_text = 'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲';
        $parsed_free = IranProvinceCityCatalog::parse_address( $free_text );
        self::assertSame( '', $parsed_free['province'] );
        self::assertSame( '', $parsed_free['city'] );
        self::assertSame( '', $parsed_free['city_custom'] );
        self::assertSame( $free_text, $parsed_free['detail'] );

        // Passthrough with no selector at all is unchanged.
        self::assertSame( $free_text, IranProvinceCityCatalog::resolve_submitted_address( $free_text, '', '' ) );
        self::assertNull( IranProvinceCityCatalog::resolve_submitted_address( '  ', '', '' ) );
    }

    public function testCitySlotHelpersAreMarkerDriven(): void {
        self::assertTrue( IranProvinceCityCatalog::city_slot_is_custom( 'سایر: پردیس' ) );
        self::assertFalse( IranProvinceCityCatalog::city_slot_is_custom( 'پردیس' ) );
        self::assertSame( 'سایر: پردیس', IranProvinceCityCatalog::compose_custom_city_slot( 'پردیس' ) );
    }
}
