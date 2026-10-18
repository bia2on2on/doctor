<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Geography;

use InvalidArgumentException;

/**
 * Offline canonical catalog of Iran's 31 official provinces and their cities.
 *
 * Provenance & invariants:
 *  - Source: Official administrative divisions of Iran (وزارت کشور / مرکز آمار ایران — ۳۱ استان رسمی).
 *  - Offline-only: static in-repository catalog packaged inside the release ZIP (`src/Domain/Geography/`);
 *    zero runtime HTTP/API calls and zero external service dependency.
 *  - UTF-8 & Persian normalization: canonical Persian Yeh (ی U+06CC) and Kaf (ک U+06A9), trimmed and
 *    control-character-free strings.
 *  - No silent default: no method defaults to Tehran or infers an unselected province/city.
 *  - Backwards-compatible: existing free-form stored addresses in `VARCHAR(255)` columns remain valid
 *    and untouched when no province/city selector is submitted.
 */
final class IranProvinceCityCatalog {

    public const DATASET_VERSION = '2026.10-iran-31';

    public const DATASET_SOURCE = 'official-iran-administrative-divisions-31-provinces-offline';

    private const MAX_ADDRESS_LENGTH = 255;

    /**
     * Canonical list of all 31 official Iranian provinces with provincial capital and cities.
     *
     * @var list<array{slug:string, name:string, capital:string, cities:list<string>}>
     */
    private const PROVINCES = [
        [
            'slug'    => 'east-azerbaijan',
            'name'    => 'آذربایجان شرقی',
            'capital' => 'تبریز',
            'cities'  => [ 'تبریز', 'مراغه', 'مرند', 'میانه', 'اهر', 'بناب', 'سراب', 'آذرشهر', 'هادیشهر', 'عجب‌شیر', 'ملکان', 'شبستر', 'بستان‌آباد', 'هشترود', 'اسکو', 'سهند', 'جلفا', 'کلیبر', 'ورزقان', 'هریس' ],
        ],
        [
            'slug'    => 'west-azerbaijan',
            'name'    => 'آذربایجان غربی',
            'capital' => 'ارومیه',
            'cities'  => [ 'ارومیه', 'خوی', 'بوکان', 'مهاباد', 'میاندوآب', 'سلماس', 'پیرانشهر', 'نقده', 'تکاب', 'ماکو', 'سردشت', 'شاهین‌دژ', 'اشنویه', 'قره‌ضیاءالدین', 'شوط', 'پلدشت', 'چالدران' ],
        ],
        [
            'slug'    => 'ardabil',
            'name'    => 'اردبیل',
            'capital' => 'اردبیل',
            'cities'  => [ 'اردبیل', 'پارس‌آباد', 'مشگین‌شهر', 'خلخال', 'گرمی', 'بیله‌سوار', 'نمین', 'نیر', 'سرعین', 'کوثر', 'اصلاندوز' ],
        ],
        [
            'slug'    => 'isfahan',
            'name'    => 'اصفهان',
            'capital' => 'اصفهان',
            'cities'  => [ 'اصفهان', 'کاشان', 'خمینی‌شهر', 'نجف‌آباد', 'شاهین‌شهر', 'شهرضا', 'فولادشهر', 'بهارستان', 'مبارکه', 'آران و بیدگل', 'گلپایگان', 'زرین‌شهر', 'درچه', 'فلاورجان', 'لنجان', 'نائین', 'سمیرم', 'خوانسار', 'اردستان', 'فریدن', 'فریدون‌شهر', 'نطنز', 'تیران', 'چادگان', 'دهاقان' ],
        ],
        [
            'slug'    => 'alborz',
            'name'    => 'البرز',
            'capital' => 'کرج',
            'cities'  => [ 'کرج', 'فردیس', 'کمال‌شهر', 'نظرآباد', 'محمدشهر', 'ماهدشت', 'مشکین‌دشت', 'هشتگرد', 'چهارباغ', 'اشتهارد', 'طالقان', 'گرمدره' ],
        ],
        [
            'slug'    => 'ilam',
            'name'    => 'ایلام',
            'capital' => 'ایلام',
            'cities'  => [ 'ایلام', 'دهلران', 'ایوان', 'آبدانان', 'دره‌شهر', 'مهران', 'سرابله', 'ارکواز', 'بدره', 'چرداول' ],
        ],
        [
            'slug'    => 'bushehr',
            'name'    => 'بوشهر',
            'capital' => 'بوشهر',
            'cities'  => [ 'بوشهر', 'برازجان', 'بندر گناوه', 'بندر کنگان', 'خورموج', 'عسلویه', 'بندر دیر', 'جم', 'بندر دیلم', 'اهرم', 'تنگستان', 'دشتستان' ],
        ],
        [
            'slug'    => 'tehran',
            'name'    => 'تهران',
            'capital' => 'تهران',
            'cities'  => [ 'تهران', 'اسلام‌شهر', 'شهریار', 'قدس', 'ملارد', 'پاکدشت', 'ورامین', 'قرچک', 'ری', 'رباط‌کریم', 'پرند', 'پردیس', 'بومهن', 'دماوند', 'پیشوا', 'باقرشهر', 'چهاردانگه', 'فیروزکوه', 'شمیرانات', 'لواسان' ],
        ],
        [
            'slug'    => 'chaharmahal-and-bakhtiari',
            'name'    => 'چهارمحال و بختیاری',
            'capital' => 'شهرکرد',
            'cities'  => [ 'شهرکرد', 'بروجن', 'فرخ‌شهر', 'فارسان', 'لردگان', 'هفشجان', 'جونقان', 'سامان', 'بن', 'کوهرنگ', 'اردل', 'کیار' ],
        ],
        [
            'slug'    => 'south-khorasan',
            'name'    => 'خراسان جنوبی',
            'capital' => 'بیرجند',
            'cities'  => [ 'بیرجند', 'قائن', 'طبس', 'فردوس', 'نهبندان', 'سرایان', 'سربیشه', 'بشرویه', 'درمیان', 'زیرکوه', 'خوسف' ],
        ],
        [
            'slug'    => 'razavi-khorasan',
            'name'    => 'خراسان رضوی',
            'capital' => 'مشهد',
            'cities'  => [ 'مشهد', 'نیشابور', 'سبزوار', 'تربت حیدریه', 'کاشمر', 'قوچان', 'تربت جام', 'تایباد', 'چناران', 'سرخس', 'گناباد', 'فریمان', 'گلبهار', 'درگز', 'خواف', 'بردسکن', 'طرقبه', 'شاندیز', 'باخرز', 'مه ولات' ],
        ],
        [
            'slug'    => 'north-khorasan',
            'name'    => 'خراسان شمالی',
            'capital' => 'بجنورد',
            'cities'  => [ 'بجنورد', 'شیروان', 'اسفراین', 'آشخانه', 'جاجرم', 'فاروج', 'گرمه', 'راز و جرگلان', 'مانه و سملقان' ],
        ],
        [
            'slug'    => 'khuzestan',
            'name'    => 'خوزستان',
            'capital' => 'اهواز',
            'cities'  => [ 'اهواز', 'دزفول', 'آبادان', 'بندر ماهشهر', 'اندیمشک', 'خرمشهر', 'بهبهان', 'ایذه', 'شوشتر', 'مسجدسلیمان', 'بندر امام خمینی', 'شوش', 'رامهرمز', 'امیدیه', 'شادگان', 'سوسنگرد', 'باغ‌ملک', 'هندیجان', 'لالی', 'گتوند' ],
        ],
        [
            'slug'    => 'zanjan',
            'name'    => 'زنجان',
            'capital' => 'زنجان',
            'cities'  => [ 'زنجان', 'ابهر', 'خرمدره', 'قیدار', 'هیدج', 'صائین‌قلعه', 'آب‌بر', 'ماه‌نشان', 'سلطانیه', 'ایجرود', 'طارم' ],
        ],
        [
            'slug'    => 'semnan',
            'name'    => 'سمنان',
            'capital' => 'سمنان',
            'cities'  => [ 'سمنان', 'شاهرود', 'دامغان', 'گرمسار', 'مهدی‌شهر', 'ایوانکی', 'سرخه', 'آرادان', 'میامی', 'بسطام' ],
        ],
        [
            'slug'    => 'sistan-and-baluchestan',
            'name'    => 'سیستان و بلوچستان',
            'capital' => 'زاهدان',
            'cities'  => [ 'زاهدان', 'زابل', 'ایرانشهر', 'چابهار', 'سراوان', 'خاش', 'کنارک', 'راسک', 'نیک‌شهر', 'زهک', 'سرباز', 'دلگان', 'مهرستان', 'فنوج', 'قصرقند' ],
        ],
        [
            'slug'    => 'fars',
            'name'    => 'فارس',
            'capital' => 'شیراز',
            'cities'  => [ 'شیراز', 'مرودشت', 'جهرم', 'فسا', 'کازرون', 'صدرا', 'داراب', 'فیروزآباد', 'لار', 'آباده', 'نورآباد', 'نی‌ریز', 'اقلید', 'استهبان', 'گراش', 'زرقان', 'کوار', 'لامرد', 'ممسنی', 'سپیدان', 'خنج', 'بوانات' ],
        ],
        [
            'slug'    => 'qazvin',
            'name'    => 'قزوین',
            'capital' => 'قزوین',
            'cities'  => [ 'قزوین', 'الوند', 'تاکستان', 'بوئین‌زهرا', 'اقبالیه', 'محمدیه', 'آبیک', 'شال', 'اسفرورین', 'آوج' ],
        ],
        [
            'slug'    => 'qom',
            'name'    => 'قم',
            'capital' => 'قم',
            'cities'  => [ 'قم', 'قنوات', 'جعفریه', 'کهک', 'دستجرد', 'سلفچگان' ],
        ],
        [
            'slug'    => 'kurdistan',
            'name'    => 'کردستان',
            'capital' => 'سنندج',
            'cities'  => [ 'سنندج', 'سقز', 'مریوان', 'بانه', 'قروه', 'کامیاران', 'بیجار', 'دیواندره', 'دهگلان', 'سروآباد' ],
        ],
        [
            'slug'    => 'kerman',
            'name'    => 'کرمان',
            'capital' => 'کرمان',
            'cities'  => [ 'کرمان', 'سیرجان', 'رفسنجان', 'جیرفت', 'بم', 'زرند', 'کهنوج', 'شهربابک', 'بافت', 'بردسیر', 'بروجردی', 'راور', 'عنبرآباد', 'ماهان', 'قلعه‌گنج', 'منوجان', 'ریگان', 'فهرج' ],
        ],
        [
            'slug'    => 'kermanshah',
            'name'    => 'کرمانشاه',
            'capital' => 'کرمانشاه',
            'cities'  => [ 'کرمانشاه', 'اسلام‌آباد غرب', 'جوانرود', 'کنگاور', 'سرپل ذهاب', 'سنقر', 'هرسین', 'صحنه', 'پاوه', 'روانسر', 'گیلانغرب', 'قصر شیرین', 'ثلاث باباجانی', 'دالاهو' ],
        ],
        [
            'slug'    => 'kohgiluyeh-and-boyer-ahmad',
            'name'    => 'کهگیلویه و بویراحمد',
            'capital' => 'یاسوج',
            'cities'  => [ 'یاسوج', 'دوگنبدان', 'دهدشت', 'لیکک', 'چرام', 'لنده', 'سی‌سخت', 'باشت', 'مارگون' ],
        ],
        [
            'slug'    => 'golestan',
            'name'    => 'گلستان',
            'capital' => 'گرگان',
            'cities'  => [ 'گرگان', 'گنبد کاووس', 'بندر ترکمن', 'علی‌آباد کتول', 'آق‌قلا', 'کردکوی', 'بندر گز', 'مینودشت', 'گالیکش', 'کلاله', 'آزادشهر', 'رامیان', 'گمیشان', 'مراوه‌تپه' ],
        ],
        [
            'slug'    => 'gilan',
            'name'    => 'گیلان',
            'capital' => 'رشت',
            'cities'  => [ 'رشت', 'بندر انزلی', 'لاهیجان', 'لنگرود', 'تالش', 'آستارا', 'صومعه‌سرا', 'آستانه اشرفیه', 'رودسر', 'فومن', 'خمام', 'سیاهکل', 'رضوانشهر', 'ماسال', 'منجیل', 'رودبار', 'شفت', 'املش' ],
        ],
        [
            'slug'    => 'lorestan',
            'name'    => 'لرستان',
            'capital' => 'خرم‌آباد',
            'cities'  => [ 'خرم‌آباد', 'بروجرد', 'دورود', 'کوهدشت', 'الیگودرز', 'نورآباد', 'ازنا', 'الشتر', 'پلدختر', 'سلسله', 'دلفان' ],
        ],
        [
            'slug'    => 'mazandaran',
            'name'    => 'مازندران',
            'capital' => 'ساری',
            'cities'  => [ 'ساری', 'بابل', 'آمل', 'قائم‌شهر', 'بهشهر', 'چالوس', 'نکا', 'بابلسر', 'تنکابن', 'نوشهر', 'فریدونکنار', 'رامسر', 'جویبار', 'محمودآباد', 'نور', 'سوادکوه', 'گلوگاه', 'عباس‌آباد', 'کلاردشت', 'سیمرغ' ],
        ],
        [
            'slug'    => 'markazi',
            'name'    => 'مرکزی',
            'capital' => 'اراک',
            'cities'  => [ 'اراک', 'ساوه', 'خمین', 'محلات', 'دلیجان', 'شازند', 'مأمونیه', 'آشتیان', 'تفرش', 'کمیجان', 'خنداب', 'زرندیه' ],
        ],
        [
            'slug'    => 'hormozgan',
            'name'    => 'هرمزگان',
            'capital' => 'بندرعباس',
            'cities'  => [ 'بندرعباس', 'میناب', 'قشم', 'بندر لنگه', 'کیش', 'رودان', 'حاجی‌آباد', 'جاسک', 'پارسیان', 'بستک', 'بندر خمیر', 'ابوموسی', 'سیریک', 'بشاگرد' ],
        ],
        [
            'slug'    => 'hamedan',
            'name'    => 'همدان',
            'capital' => 'همدان',
            'cities'  => [ 'همدان', 'ملایر', 'نهاوند', 'اسدآباد', 'تویسرکان', 'بهار', 'کبودرآهنگ', 'لالجین', 'رزن', 'فامنین', 'درگزین' ],
        ],
        [
            'slug'    => 'yazd',
            'name'    => 'یزد',
            'capital' => 'یزد',
            'cities'  => [ 'یزد', 'میبد', 'اردکان', 'بافق', 'مهریز', 'ابرکوه', 'اشکذر', 'تفت', 'هرات', 'بهاباد', 'خاتم' ],
        ],
    ];

    /**
     * Return all 31 official Iranian provinces with their cities.
     *
     * @return list<array{slug:string, name:string, capital:string, cities:list<string>}>
     */
    public static function provinces(): array {
        return self::PROVINCES;
    }

    /**
     * Return canonical Persian names of all 31 provinces.
     *
     * @return list<string>
     */
    public static function province_names(): array {
        $names = [];
        foreach ( self::PROVINCES as $province ) {
            $names[] = $province['name'];
        }

        return $names;
    }

    /**
     * Return province => cities map for offline client-side progressive enhancement.
     *
     * @return array<string, list<string>>
     */
    public static function client_catalog_map(): array {
        $map = [];
        foreach ( self::PROVINCES as $province ) {
            $map[ $province['name'] ] = $province['cities'];
        }

        return $map;
    }

    /**
     * Normalize Persian text: UTF-8 validation, Arabic Yeh/Kaf -> Persian Yeh/Kaf,
     * control-char stripping, ZWNJ cleanup, and whitespace collapsing.
     */
    public static function normalize_persian_text( string $value ): string {
        if ( $value === '' || ! mb_check_encoding( $value, 'UTF-8' ) ) {
            return '';
        }

        $normalized = str_replace(
            [ "\u{064A}", "\u{0649}", "\u{0643}", "\u{06CC}", "\u{06A9}" ],
            [ 'ی', 'ی', 'ک', 'ی', 'ک' ],
            $value
        );

        $cleaned = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $normalized );
        if ( ! is_string( $cleaned ) ) {
            return '';
        }

        $cleaned = preg_replace( '/\x{200C}{2,}/u', "\u{200C}", $cleaned );
        if ( ! is_string( $cleaned ) ) {
            return '';
        }

        $cleaned = preg_replace( '/^[\s\x{200C}]+|[\s\x{200C}]+$/u', '', $cleaned );
        if ( ! is_string( $cleaned ) ) {
            return '';
        }

        $cleaned = preg_replace( '/[^\S\x{200C}]+/u', ' ', $cleaned );

        return is_string( $cleaned ) ? trim( $cleaned ) : '';
    }

    /**
     * Look up a province by canonical Persian name or ASCII slug.
     *
     * @return array{slug:string, name:string, capital:string, cities:list<string>}|null
     */
    public static function find_province( string $province_or_slug ): ?array {
        $needle = self::normalize_persian_text( $province_or_slug );
        if ( $needle === '' ) {
            return null;
        }

        $slug_needle = strtolower( $needle );
        if ( str_starts_with( $needle, 'استان ' ) ) {
            $without_prefix = self::normalize_persian_text( (string) mb_substr( $needle, 6 ) );
            if ( $without_prefix !== '' ) {
                $needle = $without_prefix;
            }
        }

        foreach ( self::PROVINCES as $province ) {
            if ( $province['slug'] === $slug_needle || $province['name'] === $needle ) {
                return $province;
            }
        }

        return null;
    }

    /**
     * Return cities for the given province name or slug, or empty list if unknown.
     *
     * @return list<string>
     */
    public static function cities_for_province( string $province_or_slug ): array {
        $province = self::find_province( $province_or_slug );

        return $province !== null ? $province['cities'] : [];
    }

    public static function is_valid_province( string $province_or_slug ): bool {
        return self::find_province( $province_or_slug ) !== null;
    }

    public static function is_valid_city_for_province( string $province_or_slug, string $city ): bool {
        $province = self::find_province( $province_or_slug );
        if ( $province === null ) {
            return false;
        }

        $normalized_city = self::normalize_city_token( $city );
        if ( $normalized_city === '' ) {
            return false;
        }

        return in_array( $normalized_city, $province['cities'], true );
    }

    /**
     * Parse an existing stored address into optional recognized (province, city) prefix + detail.
     *
     * Backwards compatibility: if the stored address does not start with a recognized
     * "<Province>، <City>" pair from the catalog, `province` and `city` remain empty strings
     * and the entire stored address is preserved in `detail` and `raw`.
     *
     * @return array{province:string, city:string, detail:string, raw:string}
     */
    public static function parse_address( ?string $stored_address ): array {
        $raw = self::normalize_persian_text( (string) ( $stored_address ?? '' ) );
        if ( $raw === '' ) {
            return [ 'province' => '', 'city' => '', 'detail' => '', 'raw' => '' ];
        }

        $parts = preg_split( '/\s*(?:،|,)\s*/u', $raw, 3 );
        if ( is_array( $parts ) && count( $parts ) >= 2 ) {
            $candidate_province = self::find_province( $parts[0] );
            $candidate_city     = self::normalize_city_token( $parts[1] );
            if (
                $candidate_province !== null
                && $candidate_city !== ''
                && in_array( $candidate_city, $candidate_province['cities'], true )
            ) {
                $detail = isset( $parts[2] ) ? self::normalize_persian_text( $parts[2] ) : '';

                return [
                    'province' => $candidate_province['name'],
                    'city'     => $candidate_city,
                    'detail'   => $detail,
                    'raw'      => $raw,
                ];
            }
        }

        return [
            'province' => '',
            'city'     => '',
            'detail'   => $raw,
            'raw'      => $raw,
        ];
    }

    /**
     * Resolve and validate a submitted address with optional province/city selectors.
     *
     * - When both `$raw_province` and `$raw_city` are empty/null, the raw address is preserved
     *   backwards-compatibly (normalized UTF-8, or null when empty).
     * - When `$raw_province` or `$raw_city` is supplied, validates against the offline 31-province
     *   catalog (rejecting unknown provinces, cities without province, or cross-province cities
     *   with `InvalidArgumentException`) and composes a canonical Persian address string within
     *   the 255-character column limit.
     *
     * @throws InvalidArgumentException On invalid/mismatched province or city or overlong address.
     */
    public static function resolve_submitted_address(
        ?string $raw_address,
        ?string $raw_province = null,
        ?string $raw_city = null
    ): ?string {
        $address  = self::normalize_persian_text( (string) ( $raw_address ?? '' ) );
        $province = self::normalize_persian_text( (string) ( $raw_province ?? '' ) );
        $city     = self::normalize_city_token( (string) ( $raw_city ?? '' ) );

        if ( $province === '' && $city === '' ) {
            if ( $address === '' ) {
                return null;
            }
            if ( mb_strlen( $address ) > self::MAX_ADDRESS_LENGTH ) {
                throw new InvalidArgumentException( 'نشانی بیش از حد طولانی است.' );
            }

            return $address;
        }

        if ( $province === '' && $city !== '' ) {
            throw new InvalidArgumentException( 'انتخاب شهر بدون انتخاب استان معتبر نیست.' );
        }

        $matched_province = self::find_province( $province );
        if ( $matched_province === null ) {
            throw new InvalidArgumentException( 'استان انتخاب‌شده در فهرست رسمی استان‌های ایران معتبر نیست.' );
        }

        $province_name = $matched_province['name'];
        $city_name     = '';
        if ( $city !== '' ) {
            if ( ! in_array( $city, $matched_province['cities'], true ) ) {
                throw new InvalidArgumentException( 'شهر انتخاب‌شده متعلق به استان انتخاب‌شده نیست.' );
            }
            $city_name = $city;
        }

        $parsed = self::parse_address( $address );
        if ( $parsed['province'] !== '' && $parsed['city'] !== '' ) {
            $detail = $parsed['detail'];
        } else {
            $detail = $address;
            if ( $city_name !== '' ) {
                $prefix_with_comma = $province_name . '، ' . $city_name . '،';
                $prefix_exact      = $province_name . '، ' . $city_name;
                if ( str_starts_with( $detail, $prefix_with_comma ) ) {
                    $detail = self::normalize_persian_text( (string) mb_substr( $detail, mb_strlen( $prefix_with_comma ) ) );
                } elseif ( $detail === $prefix_exact ) {
                    $detail = '';
                }
            } else {
                $province_with_comma = $province_name . '،';
                if ( str_starts_with( $detail, $province_with_comma ) ) {
                    $detail = self::normalize_persian_text( (string) mb_substr( $detail, mb_strlen( $province_with_comma ) ) );
                } elseif ( $detail === $province_name ) {
                    $detail = '';
                }
            }
        }

        $prefix   = $city_name !== '' ? $province_name . '، ' . $city_name : $province_name;
        $composed = $detail !== '' ? $prefix . '، ' . $detail : $prefix;

        if ( mb_strlen( $composed ) > self::MAX_ADDRESS_LENGTH ) {
            throw new InvalidArgumentException( 'نشانی بیش از حد طولانی است.' );
        }

        return $composed;
    }

    private static function normalize_city_token( string $city ): string {
        $normalized = self::normalize_persian_text( $city );
        if ( str_starts_with( $normalized, 'شهرستان ' ) ) {
            $stripped = self::normalize_persian_text( (string) mb_substr( $normalized, 8 ) );
            if ( $stripped !== '' ) {
                return $stripped;
            }
        }
        if ( str_starts_with( $normalized, 'شهر ' ) ) {
            $stripped = self::normalize_persian_text( (string) mb_substr( $normalized, 4 ) );
            if ( $stripped !== '' ) {
                return $stripped;
            }
        }

        return $normalized;
    }
}
