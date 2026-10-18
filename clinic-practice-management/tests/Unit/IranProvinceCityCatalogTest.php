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

    public function testNeverDefaultsToTehranAndPreservesLegacyAddressesUnchanged(): void {
        $empty_parsed = IranProvinceCityCatalog::parse_address( null );
        self::assertSame( '', $empty_parsed['province'] );
        self::assertSame( '', $empty_parsed['city'] );
        self::assertSame( '', $empty_parsed['detail'] );

        $legacy_tehran_street = 'تهران، خیابان آزادی، پلاک ۱۲';
        $parsed_legacy        = IranProvinceCityCatalog::parse_address( $legacy_tehran_street );
        self::assertSame( '', $parsed_legacy['province'], 'Must not treat street name as a city.' );
        self::assertSame( '', $parsed_legacy['city'] );
        self::assertSame( $legacy_tehran_street, $parsed_legacy['raw'] );

        $preserved = IranProvinceCityCatalog::resolve_submitted_address( $legacy_tehran_street, '', '' );
        self::assertSame( $legacy_tehran_street, $preserved );
        self::assertNull( IranProvinceCityCatalog::resolve_submitted_address( '   ', '', '' ) );
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
