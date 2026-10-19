<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the offline Iranian 31-province / city catalog and backwards-compatible
 * address normalization, composition, and parsing.
 */
final class IranProvinceCityCatalogTest extends TestCase {

    public function testCatalogContainsAllThirtyOneOfficialIranianProvincesWithNormalizedUtf8(): void {
        $provinces = IranProvinceCityCatalog::provinces();
        self::assertCount( 31, $provinces, 'Iran has 31 official provinces.' );

        $slugs = [];
        $names = [];
        foreach ( $provinces as $province ) {
            self::assertTrue( mb_check_encoding( $province['name'], 'UTF-8' ) );
            self::assertTrue( mb_check_encoding( $province['capital'], 'UTF-8' ) );
            self::assertSame(
                IranProvinceCityCatalog::normalize_persian_text( $province['name'] ),
                $province['name'],
                'Province name must be pre-normalized.'
            );
            self::assertStringNotContainsString( "\u{064A}", $province['name'], 'No Arabic Yeh allowed.' );
            self::assertStringNotContainsString( "\u{0649}", $province['name'], 'No Alef Maksura allowed.' );
            self::assertStringNotContainsString( "\u{0643}", $province['name'], 'No Arabic Kaf allowed.' );
            self::assertNotEmpty( $province['cities'], "Province {$province['name']} must list cities." );
            self::assertContains(
                $province['capital'],
                $province['cities'],
                "Provincial capital {$province['capital']} must be in {$province['name']} cities."
            );

            $city_set = [];
            foreach ( $province['cities'] as $city ) {
                self::assertTrue( mb_check_encoding( $city, 'UTF-8' ) );
                self::assertSame(
                    IranProvinceCityCatalog::normalize_persian_text( $city ),
                    $city,
                    "City {$city} in {$province['name']} must be normalized Persian UTF-8."
                );
                self::assertStringNotContainsString( "\u{064A}", $city );
                self::assertStringNotContainsString( "\u{0649}", $city );
                self::assertStringNotContainsString( "\u{0643}", $city );
                self::assertArrayNotHasKey( $city, $city_set, "Duplicate city {$city} in {$province['name']}." );
                $city_set[ $city ] = true;
            }

            $slugs[ $province['slug'] ] = true;
            $names[ $province['name'] ] = true;
        }

        self::assertCount( 31, $slugs, 'All 31 province slugs must be unique.' );
        self::assertCount( 31, $names, 'All 31 province names must be unique.' );
        self::assertCount( 31, IranProvinceCityCatalog::province_names() );
        self::assertCount( 31, IranProvinceCityCatalog::client_catalog_map() );
    }

    public function testNormalizesArabicYehAndKafAndPreservesZeroWidthNonJoiner(): void {
        $arabic_variant = "آذربايجان شرقي - خميني\u{200C}\u{200C}شهر - كاشان";
        $normalized     = IranProvinceCityCatalog::normalize_persian_text( $arabic_variant );

        self::assertSame( "آذربایجان شرقی - خمینی\u{200C}شهر - کاشان", $normalized );
        self::assertTrue( IranProvinceCityCatalog::is_valid_province( 'آذربايجان شرقي' ) );
        self::assertTrue( IranProvinceCityCatalog::is_valid_city_for_province( 'اصفهان', 'كاشان' ) );
        self::assertTrue( IranProvinceCityCatalog::is_valid_city_for_province( 'اصفهان', "خميني\u{200C}شهر" ) );
        self::assertSame( '', IranProvinceCityCatalog::normalize_persian_text( "\xFF\xFE" ) );
    }

    public function testNeverDefaultsToTehranAndPreservesHistoricalFreeTextAddresses(): void {
        $empty_parsed = IranProvinceCityCatalog::parse_address( null );
        self::assertSame( '', $empty_parsed['province'] );
        self::assertSame( '', $empty_parsed['city'] );
        self::assertSame( '', $empty_parsed['detail'] );

        // Historical free-text address without any province prefix:
        // neither province nor city is selected, and raw text is preserved verbatim.
        $historical_free_text = 'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲';
        $parsed_free_text     = IranProvinceCityCatalog::parse_address( $historical_free_text );
        self::assertSame( '', $parsed_free_text['province'] );
        self::assertSame( '', $parsed_free_text['city'] );
        self::assertSame( $historical_free_text, $parsed_free_text['detail'] );
        self::assertSame( $historical_free_text, $parsed_free_text['raw'] );

        $preserved_free_text = IranProvinceCityCatalog::resolve_submitted_address( $historical_free_text, '', '' );
        self::assertSame( $historical_free_text, $preserved_free_text );

        // Province-prefixed historical address without a selected city:
        // province is recognized for round-trip editing, city remains '' (never defaults to Tehran),
        // and when submitted with empty selectors, the stored string is preserved untouched.
        $province_street = 'تهران، خیابان آزادی، پلاک ۱۲';
        $parsed_prov     = IranProvinceCityCatalog::parse_address( $province_street );
        self::assertSame( 'تهران', $parsed_prov['province'] );
        self::assertSame( '', $parsed_prov['city'], 'Must never silently default to a city.' );
        self::assertSame( 'خیابان آزادی، پلاک ۱۲', $parsed_prov['detail'] );
        self::assertSame( $province_street, $parsed_prov['raw'] );

        $preserved_prov = IranProvinceCityCatalog::resolve_submitted_address( $province_street, '', '' );
        self::assertSame( $province_street, $preserved_prov );
        self::assertNull( IranProvinceCityCatalog::resolve_submitted_address( '   ', '', '' ) );
    }

    public function testProvinceOnlyAddressRoundTripAndEditing(): void {
        // 1. Compose a province-only address with detail.
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            'بلوار وکیل‌آباد، پلاک ۱۵',
            'خراسان رضوی',
            ''
        );
        self::assertSame( 'خراسان رضوی، بلوار وکیل‌آباد، پلاک ۱۵', $composed );

        // 2. Parse back: province is recognized, city stays empty (never defaults to Mashhad).
        $parsed = IranProvinceCityCatalog::parse_address( $composed );
        self::assertSame( 'خراسان رضوی', $parsed['province'] );
        self::assertSame( '', $parsed['city'] );
        self::assertSame( 'بلوار وکیل‌آباد، پلاک ۱۵', $parsed['detail'] );

        // 3. Re-submitting the province-only address with the same province is idempotent.
        $resubmitted = IranProvinceCityCatalog::resolve_submitted_address(
            $composed,
            'خراسان رضوی',
            ''
        );
        self::assertSame( $composed, $resubmitted );

        // 4. Changing province on a province-only address replaces the old province without duplication.
        $re_provinced = IranProvinceCityCatalog::resolve_submitted_address(
            $composed,
            'مازندران',
            ''
        );
        self::assertSame( 'مازندران، بلوار وکیل‌آباد، پلاک ۱۵', $re_provinced );

        // 5. Adding a city to a province-only address inserts the city cleanly.
        $with_city = IranProvinceCityCatalog::resolve_submitted_address(
            $composed,
            'خراسان رضوی',
            'نیشابور'
        );
        self::assertSame( 'خراسان رضوی، نیشابور، بلوار وکیل‌آباد، پلاک ۱۵', $with_city );

        // 6. Clearing the city on a province+city address leaves province + detail cleanly.
        $cleared_city = IranProvinceCityCatalog::resolve_submitted_address(
            $with_city,
            'خراسان رضوی',
            ''
        );
        self::assertSame( 'خراسان رضوی، بلوار وکیل‌آباد، پلاک ۱۵', $cleared_city );

        // 7. Bare province without detail round-trips cleanly.
        $bare = IranProvinceCityCatalog::resolve_submitted_address( '', 'یزد', '' );
        self::assertSame( 'یزد', $bare );
        $parsed_bare = IranProvinceCityCatalog::parse_address( $bare );
        self::assertSame( 'یزد', $parsed_bare['province'] );
        self::assertSame( '', $parsed_bare['city'] );
        self::assertSame( '', $parsed_bare['detail'] );
        self::assertSame( 'یزد', IranProvinceCityCatalog::resolve_submitted_address( $bare, 'یزد', '' ) );
    }

    public function testComposesParsesAndUpdatesAddressIdempotently(): void {
        $composed = IranProvinceCityCatalog::resolve_submitted_address(
            'خیابان امیرکبیر، پلاک ۱۰',
            'اصفهان',
            'کاشان'
        );
        self::assertSame( 'اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰', $composed );

        $parsed = IranProvinceCityCatalog::parse_address( $composed );
        self::assertSame( 'اصفهان', $parsed['province'] );
        self::assertSame( 'کاشان', $parsed['city'] );
        self::assertSame( 'خیابان امیرکبیر، پلاک ۱۰', $parsed['detail'] );

        // Re-submitting the already-composed value with the same selectors is idempotent.
        $resubmitted = IranProvinceCityCatalog::resolve_submitted_address(
            $composed,
            'اصفهان',
            'کاشان'
        );
        self::assertSame( $composed, $resubmitted );

        // Changing province/city replaces the prefix cleanly without duplicating it.
        $switched = IranProvinceCityCatalog::resolve_submitted_address(
            $composed,
            'فارس',
            'شیراز'
        );
        self::assertSame( 'فارس، شیراز، خیابان امیرکبیر، پلاک ۱۰', $switched );
    }

    public function testRejectsForgedProvinceOrCrossProvinceCityOrCityWithoutProvince(): void {
        try {
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان اول', 'آتلانتیس', '' );
            self::fail( 'Unknown province must be rejected.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringContainsString( 'استان', $e->getMessage() );
        }

        try {
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان اول', '', 'شیراز' );
            self::fail( 'City without province must be rejected.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringContainsString( 'شهر', $e->getMessage() );
        }

        try {
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان اول', 'تهران', 'شیراز' );
            self::fail( 'Mismatched province/city must be rejected.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringContainsString( 'شهر', $e->getMessage() );
        }

        try {
            IranProvinceCityCatalog::resolve_submitted_address( str_repeat( 'الف', 90 ), 'تهران', 'تهران' );
            self::fail( 'Overlong composed address (>255 chars) must be rejected.' );
        } catch ( InvalidArgumentException $e ) {
            self::assertStringContainsString( 'طولانی', $e->getMessage() );
        }
    }
}
