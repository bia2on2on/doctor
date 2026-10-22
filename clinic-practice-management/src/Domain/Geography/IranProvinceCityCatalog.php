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
     * Sentinel a client submits for the city `<select>` when the operator chose
     * "Other / سایر" and typed the city manually. It is never a city name and is
     * never persisted.
     */
    public const OTHER_CITY_SENTINEL = '__other__';

    /**
     * Explicit, reversible in-address marker that separates a USER-DEFINED city
     * from a canonical catalogue city inside the composed address string.
     *
     * Data-model boundary (canonical vs user-defined):
     *  - canonical city  -> stored as `<Province>، <City>، <Detail>`
     *  - user-defined    -> stored as `<Province>، سایر: <City>، <Detail>`
     *
     * The marker exists because the composed address has no separate city column:
     * without it, `parse_address()` could not tell a custom city apart from a
     * street detail, and guessing would silently re-label historical free-text
     * addresses (for example "تهران، خیابان ولیعصر" must NEVER become a city).
     * Legacy rows carry no marker, so they keep parsing exactly as before.
     */
    public const CUSTOM_CITY_MARKER = 'سایر:';

    /** Hard cap for a manually entered city name (column stays VARCHAR(255)). */
    public const MAX_CUSTOM_CITY_LENGTH = 80;

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
     * Sanitize a manually entered ("Other / سایر") city name.
     *
     * Deterministic and side-effect free: UTF-8/Persian normalization (shared with
     * the canonical catalogue, so Arabic ي/ك and ZWNJ variants collapse), removal of
     * a pasted-in marker, removal of every character that could corrupt the composed
     * address (the «،» separator, punctuation, brackets, markup) and whitespace
     * collapsing. It never throws; `validate_custom_city()` decides acceptance.
     */
    public static function sanitize_custom_city( string $value ): string {
        $normalized = self::normalize_persian_text( $value );
        if ( $normalized === '' ) {
            return '';
        }

        // A value pasted back from a stored address must not nest markers.
        while ( str_starts_with( $normalized, self::CUSTOM_CITY_MARKER ) ) {
            $normalized = self::normalize_persian_text(
                (string) mb_substr( $normalized, mb_strlen( self::CUSTOM_CITY_MARKER ) )
            );
            if ( $normalized === '' ) {
                return '';
            }
        }

        if ( strtolower( $normalized ) === self::OTHER_CITY_SENTINEL ) {
            return '';
        }

        // Whitelist, not blacklist: only letters, digits, ZWNJ, dashes and
        // whitespace survive, so the composition separator, punctuation,
        // brackets, quotes and any markup are structurally impossible. The
        // custom value therefore can never forge an extra address segment.
        $cleaned = preg_replace( '/[^\p{L}\p{N}\x{200C}\x{2010}-\x{2015}\s\-]+/u', ' ', $normalized );
        if ( ! is_string( $cleaned ) ) {
            return '';
        }

        return self::normalize_persian_text( $cleaned );
    }

    /**
     * Validate a manually entered city against the SELECTED province and return the
     * value that must be persisted in the city slot of the composed address.
     *
     * Rules (server-side, never delegated to JavaScript):
     *  - a province must be selected: a custom city is always tied to a province;
     *  - the sanitized value must contain at least one letter and be 2..80 chars;
     *  - digits-only, separator-only or empty input is rejected;
     *  - if the typed name IS a canonical city of that province it collapses to the
     *    canonical value (no marker), so the catalogue stays the single source of
     *    truth and no shadow duplicate is created.
     *
     * @param array{slug:string, name:string, capital:string, cities:list<string>}|string $province_or_slug
     *
     * @throws InvalidArgumentException On a missing/unknown province or an unusable city name.
     */
    public static function validate_custom_city( $province_or_slug, string $raw_custom_city ): string {
        $province = is_array( $province_or_slug )
            ? ( self::find_province( (string) ( $province_or_slug['name'] ?? '' ) ) ?? null )
            : self::find_province( $province_or_slug );
        if ( $province === null ) {
            throw new InvalidArgumentException( 'استان انتخاب‌شده در فهرست رسمی استان‌های ایران معتبر نیست.' );
        }

        $custom = self::sanitize_custom_city( $raw_custom_city );
        if ( $custom === '' ) {
            throw new InvalidArgumentException( 'نام شهر واردشده معتبر نیست؛ لطفاً نام شهر را بدون علامت نگارشی وارد کنید.' );
        }

        if ( mb_strlen( $custom ) > self::MAX_CUSTOM_CITY_LENGTH ) {
            throw new InvalidArgumentException( 'نام شهر واردشده بیش از حد طولانی است.' );
        }

        if ( mb_strlen( $custom ) < 2 || preg_match( '/\p{L}/u', $custom ) !== 1 ) {
            throw new InvalidArgumentException( 'نام شهر واردشده باید حداقل دو نویسه و شامل حروف باشد.' );
        }

        // Canonical catalogue wins over a typed duplicate of the same province.
        $candidate = self::normalize_city_token( $custom );
        if ( in_array( $candidate, $province['cities'], true ) ) {
            return $candidate;
        }

        return $custom;
    }

    public static function is_valid_custom_city( string $province_or_slug, string $raw_custom_city ): bool {
        try {
            return self::validate_custom_city( $province_or_slug, $raw_custom_city ) !== '';
        } catch ( InvalidArgumentException $e ) {
            return false;
        }
    }

    /**
     * Whether a persisted city slot holds a USER-DEFINED city (marker present).
     */
    public static function city_slot_is_custom( string $city_slot ): bool {
        return str_starts_with(
            self::normalize_persian_text( $city_slot ),
            self::CUSTOM_CITY_MARKER
        );
    }

    /**
     * Compose the persisted city slot for a validated custom city.
     */
    public static function compose_custom_city_slot( string $validated_custom_city ): string {
        return self::CUSTOM_CITY_MARKER . ' ' . $validated_custom_city;
    }

    /**
     * Parse an existing stored address into optional recognized (province, city) prefix + detail.
     *
     * Backwards compatibility:
     *  - Recognizes "<Province>، <City>، <Detail>", "<Province>، <City>", "<Province>، <Detail>",
     *    and "<Province>" when "<Province>" is a canonical Persian province name from the catalog.
     *  - Never silently selects a default city or province: if a stored address has a valid
     *    province without a recognized city of that province, `city` remains `""` and the
     *    remaining text is preserved in `detail`.
     *  - If the stored address does not start with a recognized Persian province name from the
     *    catalog, `province` and `city` remain empty strings and the entire stored address is
     *    preserved in `detail` and `raw`.
     *
     *  - A city written through the "Other / سایر" path carries the explicit
     *    `CUSTOM_CITY_MARKER` and is returned in `city_custom` (with `city` empty),
     *    so a user-defined city is always distinguishable from a canonical one and
     *    from a street detail. An unmarked, unrecognized second segment is NEVER
     *    promoted to `city_custom` (historical free text stays a detail).
     *
     * @return array{province:string, city:string, city_custom:string, detail:string, raw:string}
     */
    public static function parse_address( ?string $stored_address ): array {
        $raw = self::normalize_persian_text( (string) ( $stored_address ?? '' ) );
        if ( $raw === '' ) {
            return [ 'province' => '', 'city' => '', 'city_custom' => '', 'detail' => '', 'raw' => '' ];
        }

        $first_split        = preg_split( '/\s*(?:،|,)\s*/u', $raw, 2 );
        $first_token        = is_array( $first_split ) && isset( $first_split[0] ) ? $first_split[0] : $raw;
        $remainder          = is_array( $first_split ) && isset( $first_split[1] ) ? self::normalize_persian_text( $first_split[1] ) : '';
        $candidate_province = self::find_province_by_persian_name( $first_token );

        if ( $candidate_province !== null ) {
            if ( $remainder === '' ) {
                return [
                    'province'    => $candidate_province['name'],
                    'city'        => '',
                    'city_custom' => '',
                    'detail'      => '',
                    'raw'         => $raw,
                ];
            }

            $second_split   = preg_split( '/\s*(?:،|,)\s*/u', $remainder, 2 );
            $second_token   = is_array( $second_split ) && isset( $second_split[0] ) ? $second_split[0] : $remainder;
            $candidate_city = self::normalize_city_token( $second_token );
            $tail           = is_array( $second_split ) && isset( $second_split[1] )
                ? self::normalize_persian_text( $second_split[1] )
                : '';

            // USER-DEFINED city: recognized ONLY by the explicit persisted marker,
            // never by guessing. Historical rows have no marker and keep parsing
            // exactly as before (unrecognized second segment stays a detail).
            if ( self::city_slot_is_custom( $second_token ) ) {
                $custom = self::sanitize_custom_city( $second_token );

                return [
                    'province'    => $candidate_province['name'],
                    'city'        => '',
                    'city_custom' => $custom,
                    'detail'      => $tail,
                    'raw'         => $raw,
                ];
            }

            if (
                $candidate_city !== ''
                && in_array( $candidate_city, $candidate_province['cities'], true )
            ) {
                return [
                    'province'    => $candidate_province['name'],
                    'city'        => $candidate_city,
                    'city_custom' => '',
                    'detail'      => $tail,
                    'raw'         => $raw,
                ];
            }

            return [
                'province'    => $candidate_province['name'],
                'city'        => '',
                'city_custom' => '',
                'detail'      => $remainder,
                'raw'         => $raw,
            ];
        }

        return [
            'province'    => '',
            'city'        => '',
            'city_custom' => '',
            'detail'      => $raw,
            'raw'         => $raw,
        ];
    }

    /**
     * Resolve and validate a submitted address with optional province/city selectors.
     *
     * - When both `$raw_province` and `$raw_city` are empty/null, the raw address is preserved
     *   backwards-compatibly (normalized UTF-8, or null when empty).
     * - When `$raw_province`, `$raw_city` or `$raw_city_custom` is supplied, validates against the
     *   offline 31-province catalog (rejecting unknown provinces, cities without province, or
     *   cross-province cities with `InvalidArgumentException`) and composes a canonical Persian
     *   address string within the 255-character column limit.
     * - `$raw_city` may carry `OTHER_CITY_SENTINEL` ("Other / سایر"); the manual name is then read
     *   from `$raw_city_custom`, validated (2..80 chars, at least one letter, separator/markup
     *   impossible) and persisted with `CUSTOM_CITY_MARKER` so it stays distinguishable from a
     *   canonical catalogue city on every later read. A manual name that duplicates a canonical
     *   city of the same province collapses to the canonical value.
     *
     * @throws InvalidArgumentException On invalid/mismatched province or city, an unusable manual
     *                                  city, an ambiguous city+custom payload, or an overlong address.
     */
    public static function resolve_submitted_address(
        ?string $raw_address,
        ?string $raw_province = null,
        ?string $raw_city = null,
        ?string $raw_city_custom = null
    ): ?string {
        $address  = self::normalize_persian_text( (string) ( $raw_address ?? '' ) );
        $province = self::normalize_persian_text( (string) ( $raw_province ?? '' ) );

        // "Other / سایر" is a sentinel, never a city name, and is never persisted.
        $other_selected = strtolower( trim( (string) ( $raw_city ?? '' ) ) ) === self::OTHER_CITY_SENTINEL;
        $city           = $other_selected ? '' : self::normalize_city_token( (string) ( $raw_city ?? '' ) );
        $custom         = self::sanitize_custom_city( (string) ( $raw_city_custom ?? '' ) );
        $wants_custom   = $other_selected || $custom !== '';

        // No selector submitted at all -> untouched backwards-compatible passthrough.
        if ( $province === '' && $city === '' && ! $wants_custom ) {
            if ( $address === '' ) {
                return null;
            }
            if ( mb_strlen( $address ) > self::MAX_ADDRESS_LENGTH ) {
                throw new InvalidArgumentException( 'نشانی بیش از حد طولانی است.' );
            }

            return $address;
        }

        // A user-defined city is ALWAYS tied to a selected province: it is stored
        // inside that province's address slot and can never float free.
        if ( $province === '' && ( $city !== '' || $custom !== '' ) ) {
            throw new InvalidArgumentException( 'انتخاب شهر بدون انتخاب استان معتبر نیست.' );
        }

        $matched_province = self::find_province( $province );
        if ( $matched_province === null ) {
            throw new InvalidArgumentException( 'استان انتخاب‌شده در فهرست رسمی استان‌های ایران معتبر نیست.' );
        }

        $province_name = $matched_province['name'];
        $city_slot     = '';

        if ( $city !== '' ) {
            if ( ! in_array( $city, $matched_province['cities'], true ) ) {
                throw new InvalidArgumentException( 'شهر انتخاب‌شده متعلق به استان انتخاب‌شده نیست.' );
            }

            // Fail closed on an ambiguous payload: a listed city and a different
            // manual city cannot both win. An identical manual value is a no-op.
            if ( $custom !== '' && self::normalize_city_token( $custom ) !== $city ) {
                throw new InvalidArgumentException( 'هم‌زمان نمی‌توان یک شهر از فهرست و یک شهر دستی وارد کرد؛ یکی را انتخاب کنید.' );
            }

            $city_slot = $city;
        } elseif ( $other_selected && trim( (string) ( $raw_city_custom ?? '' ) ) !== '' ) {
            // "Other / سایر" was explicitly chosen and something was typed, but nothing
            // usable survived sanitization: reject instead of silently dropping input.
            self::validate_custom_city( $matched_province, (string) $raw_city_custom );
        } elseif ( $custom !== '' ) {
            $validated = self::validate_custom_city( $matched_province, $custom );
            // A typed name that IS canonical for this province collapses to the
            // catalogue value, so no shadow duplicate of a canonical city exists.
            $city_slot = in_array( $validated, $matched_province['cities'], true )
                ? $validated
                : self::compose_custom_city_slot( $validated );
        }

        $parsed = self::parse_address( $address );
        if ( $parsed['province'] !== '' ) {
            $detail = $parsed['detail'];
        } else {
            $detail = $address;
        }

        $prefix   = $city_slot !== '' ? $province_name . '، ' . $city_slot : $province_name;
        $composed = $detail !== '' ? $prefix . '، ' . $detail : $prefix;

        if ( mb_strlen( $composed ) > self::MAX_ADDRESS_LENGTH ) {
            throw new InvalidArgumentException( 'نشانی بیش از حد طولانی است.' );
        }

        return $composed;
    }

    /**
     * Match a province by canonical Persian name (or "استان <نام>"), never by ASCII slug,
     * so free-text stored addresses are only parsed when prefixed by an official Persian province.
     *
     * @return array{slug:string, name:string, capital:string, cities:list<string>}|null
     */
    private static function find_province_by_persian_name( string $candidate ): ?array {
        $needle = self::normalize_persian_text( $candidate );
        if ( $needle === '' ) {
            return null;
        }

        if ( str_starts_with( $needle, 'استان ' ) ) {
            $without_prefix = self::normalize_persian_text( (string) mb_substr( $needle, 6 ) );
            if ( $without_prefix !== '' ) {
                $needle = $without_prefix;
            }
        }

        foreach ( self::PROVINCES as $province ) {
            if ( $province['name'] === $needle ) {
                return $province;
            }
        }

        return null;
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
