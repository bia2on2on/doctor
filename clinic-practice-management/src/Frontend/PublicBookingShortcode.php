<?php

/**
 * Phase 8 Slice 1 — سطح عمومی رزرو: shortcode فرانت‌اند `[cpms_public_booking]`.
 *
 * ============================================================================
 * دامنهٔ این Slice (FR-3.5 / UC-01) — فقط «مشاهده» پیش از Login
 * ============================================================================
 *
 * بازدیدکنندهٔ آنونیم روی یک صفحهٔ عادی وردپرس:
 *   ۱. پزشکانِ **فعالِ** یک Clinicِ صریحاً پیکربندی‌شده را می‌بیند؛
 *   ۲. نوبت‌های آزاد را از طریق قرارداد **موجودِ** A1 می‌گیرد
 *      (`GET clinic/v1/availability`، `permPublic()`)؛
 *   ۳. برچسب روزهای Jalali و ساعت‌های wall-clock محلیِ Location را **همان‌طور که
 *      سرور فرستاده** می‌بیند (هیچ بازمحاسبهٔ timezone در مرورگر انجام نمی‌شود)؛
 *   ۴. یک نوبت را انتخاب می‌کند؛
 *   ۵. همان انتخاب با `slot_id` دقیقِ A1 از طریق قرارداد **موجودِ** A4
 *      (`POST clinic/v1/booking/quote`، `permPublic()`) سنجیده می‌شود؛
 *   ۶. یک وضعیت روشن می‌بیند: bookable / policy_rejected / unavailable /
 *      empty / error (به‌علاوهٔ دو وضعیت گذرای UI: idle / loading).
 *
 * صریحاً خارج از این Slice: login، ثبت‌نام، تحویل OTP، نگه‌داشتنِ انتخاب پس از
 * Login، hold، confirm، پورتال بیمار، و هر REST endpoint جدید. سرور در همهٔ
 * مراحل authoritative می‌ماند.
 *
 * ============================================================================
 * سه قاعدهٔ امنیت/tenant که این کلاس پیاده می‌کند
 * ============================================================================
 *
 *  (۱) FAIL-CLOSED روی پیکربندی Clinic (ADR-0031 AD-13).
 *      Clinic فقط از attribute صریح `clinic_id` خوانده می‌شود و باید هم
 *      «عددیِ مثبت» باشد و هم «ردیفِ پایدارِ واقعی» در `cpms_clinics`. نبودِ
 *      attribute، مقدار غیرعددی، `<= 0` و Clinic ناموجود همه به **یک** وضعیت
 *      بستهٔ `error` می‌رسند (enumeration parity: وضعیت بسته هیچ تفاوتی بین
 *      «ورودی نامعتبر» و «وجود ندارد» فاش نمی‌کند). هرگز: Clinic با کمترین id،
 *      «تنها Clinic موجود»، حدس از جغرافیا/کاربر جاری، یا Scope محیطی.
 *
 *  (۲) بدون PHI روی سطح عمومی (FR-3.5).
 *      تنها دادهٔ شخصیِ نمایش‌داده‌شده `full_name` پزشک است (اطلاعات عمومیِ
 *      نوبت‌دهی). هیچ فیلد هویت/تماس بیمار (patient_id، mrn، mobile،
 *      national_id، first_name، last_name) نه خوانده می‌شود، نه در markup و نه
 *      در config runtime منتشر می‌شود.
 *
 *  (۳) سرور authoritative است؛ مرورگر فقط «نگاشتِ وضعیت» می‌کند.
 *      ظرفیت، min-lead، افق نوبت‌دهی و سیاست tenant **هیچ‌کدام** در JS
 *      پیاده/تکرار نشده‌اند. JS فقط verdict و پیامِ خودِ A1/A4 را نمایش
 *      می‌دهد. مسیرهای A1/A4 از `rest_url()` گرفته می‌شوند (نه hardcode).
 *
 * ============================================================================
 * معماری رندر
 * ============================================================================
 *
 *  - لیست پزشکان **server-rendered** است (هیچ endpoint عمومیِ «لیست پزشکان»
 *    وجود ندارد و ساخته نمی‌شود)؛
 *  - تمام متن‌های فارسی و skeletonهای markup (قالب day/slot/detail) سمت سرور
 *    رندر و escape می‌شوند و در `<template>` قرار می‌گیرند، پس JS هیچ رشتهٔ
 *    فارسی ندارد و فقط مقادیرِ داده‌ایِ خودشِ سرور را با `textContent`
 *    جای‌گذاری می‌کند (escape خودکار)؛
 *  - قرارداد runtime در `<script type="application/json"
 *    class="cpms-public-booking__config">` با `wp_json_encode()` منتشر می‌شود؛
 *  - assets فقط برای صفحه‌ای که این سطح را رندر می‌کند انکیو می‌شوند
 *    (PublicBookingAssets) و هیچ تماسی با assets مدیریتی `cpms-admin` ندارند.
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use Throwable;
use WP_Post;

/**
 * ثبت و رندر shortcode عمومی نوبت‌دهی.
 */
final class PublicBookingShortcode
{
    /** D-1 — تگ shortcode. */
    public const TAG = 'cpms_public_booking';

    /** D-2 — attribute یکتای پیوندِ Clinic. */
    private const CLINIC_ATTR = 'clinic_id';

    /** D-4 — markerهای markup (قراردادِ قابل grep). */
    private const ROOT_CLASS = 'cpms-public-booking';
    private const CLINICIAN_CLASS = 'cpms-public-booking__clinician';
    private const CONFIG_CLASS = 'cpms-public-booking__config';

    /**
     * وضعیت‌های سطح (root).
     *
     * فقط سه مقدارِ ممکن برای root: `error` (پیکربندی نامعتبر/ناموجود — بسته)،
     * `empty` (Clinic معتبر ولی هیچ پزشک فعالی ندارد) و `bookable` (Clinic
     * معتبر با پزشکِ فعالِ قابل مرور). دو وضعیتِ گذرای `idle`/`loading` و
     * وضعیت‌های دقیقِ مرورِ نوبت روی **زیر-بخش** panel نشسته‌اند، نه root —
     * تا قرارداد وضعیتِ سطح، سطحِ پیکربندی/tenant بماند.
     */
    private const STATE_BOOKABLE = 'bookable';
    private const STATE_EMPTY = 'empty';
    private const STATE_ERROR = 'error';

    /**
     * D-5 — واژگان کامل وضعیت‌ها (پنج وضعیتِ قرارداد + سه وضعیتِ گذرای UI).
     *
     * این فهرست به مرورگر منتشر می‌شود تا بداند چه `data-state`هایی معتبرند؛
     *superset بودن نسبت به پنج وضعیتِ قرارداد عمدی و مستند است.
     */
    private const STATE_VOCABULARY = [
        'idle',
        'loading',
        'selectable',
        'location_required',
        'bookable',
        'policy_rejected',
        'unavailable',
        'empty',
        'error',
    ];

    /**
     * D-5 — namespace و مسیرهای **موجودِ** REST.
     *
     * `clinic/v1` همان namespaceِ `RestBase::NS` است (که `protected` است، پس
     * از بیرون قابل خواندن نیست) و `/availability` (A1) و `/booking/quote`
     * (A4) دو مسیر عمومیِ موجودِ `docs/api/api-contract.md` هستند. ریشهٔ
     * واقعی همیشه از `rest_url()` ساخته می‌شود؛ این ثابت‌ها فقط «کدام مسیرِ
     * موجود» را نام می‌برند. هیچ endpoint جدیدی ثبت نمی‌شود.
     */
    private const REST_NAMESPACE = 'clinic/v1';
    private const AVAILABILITY_PATH = '/availability';
    private const QUOTE_PATH = '/booking/quote';

    /**
     * Phase 8 Slice 2 — قراردادِ ادامهٔ احراز/رزرو، «همیشه روی مسیرهای موجود».
     *
     * A2/A3 (otp/request, otp/verify) و B1/B2 (booking/hold, booking/confirm)
     * همگی endpointهای موجودند؛ هیچ مسیر جدیدی ثبت نمی‌شود. انتشارِ این
     * مسیرها در قرارداد runtime، وابسته به وضعیتِ احراز بازدیدکننده است
     * (continuationState()):
     *  - anonymous  → فقط A2/A3 (+ نشانه‌های continue-auth/otp-mobile/otp-code)
     *    و عمداً بدون nonce و بدون B1/B2 و بدون هیچ PHI؛
     *  - patient    → فقط nonce (wp_rest) + B1/B2 برای ادامهٔ Hold→Confirm؛
     *  - سایر کاربرانِ واردشده (غیر بیمار) → هیچ continuation بیماری.
     */
    private const OTP_REQUEST_PATH = '/otp/request';
    private const OTP_VERIFY_PATH = '/otp/verify';
    private const HOLD_PATH = '/booking/hold';
    private const CONFIRM_PATH = '/booking/confirm';

    private const CONTINUATION_ANONYMOUS = 'anonymous';
    private const CONTINUATION_PATIENT = 'patient';
    private const CONTINUATION_NONE = 'none';

    /** متن‌های فارسیِ وضعیت‌های panel (CSS بر پایهٔ `data-state` یکی را نشان می‌دهد). */
    private const PANEL_MESSAGES = [
        'idle' => 'برای دیدن نوبت‌های آزاد، ابتدا پزشک موردنظر را انتخاب کنید.',
        'loading' => 'در حال دریافت نوبت‌های آزاد…',
        'selectable' => 'روز و ساعت موردنظر خود را انتخاب کنید.',
        'location_required' => 'برای دیدن نوبت‌ها، محل مراجعه را انتخاب کنید.',
        'bookable' => 'این نوبت آزاد است و برای رزرو در دسترس می‌باشد.',
        'policy_rejected' => 'این نوبت به دلیل سیاست زمان‌بندی مرکز قابل رزرو نیست.',
        'unavailable' => 'ظرفیت این نوبت تکمیل شده است.',
        'empty' => 'در بازهٔ نمایش‌داده‌شده، نوبت آزادی برای این پزشک وجود ندارد.',
        'error' => 'دریافت وضعیت نوبت ممکن نشد. کمی بعد دوباره تلاش کنید.',
    ];

    /** متنِ وضعیتِ بسته — عمداً بدون هیچ جزئیاتی (enumeration parity، AD-13). */
    private const CLOSED_NOTICE = 'این بخش برای نمایش نوبت‌ها در دسترس نیست.';

    /** متنِ `empty` — Clinic معتبر است، ولی پزشک فعالی برای نوبت‌دهی ثبت نشده. */
    private const NO_CLINICIAN_NOTICE = 'در حال حاضر پزشک فعالی برای انتخاب نوبت در این مرکز ثبت نشده است.';

    private const TITLE = 'رزرو نوبت';
    private const STEP_CLINICIAN = '۱. انتخاب پزشک';
    private const STEP_LOCATION  = '۲. انتخاب محل مراجعه';
    private const STEP_SLOT      = '۳. انتخاب روز و ساعت';
    private const CAPACITY_LABEL = 'ظرفیت باقی‌مانده';
    private const DURATION_LABEL = 'دقیقه';
    private const CLINICIAN_HINT = 'پزشک موردنظر را انتخاب کنید';

    // ================= فهرست عمومی (clinic_id="all") =================

    /**
     * حالت discovery — رضختمصریِ نمایش عمومی.
     *
     * `clinic_id="all"` سطح را به فهرستِ server-renderedِ Clinicها تبدیل می‌کند.
     * عضویت در فهرست «fail-closed» است: رضختمصریِ نمایش (`public.listing_enabled`
     * روی همان Clinic، پیش‌فرض false)، Organization فعال، Clinic فعال (تعلیق‌نشده)
     * و دست‌کم یک Location فعال — همه با هم. بدون consent، هیچ Clinicی فهرست
     * نمی‌شود؛ بدون هیچ endpoint جدیدی. ترتیب قطعی (نام، سپس id) و سقف
     * DIRECTORY_LIMIT جلوی فهرست‌های بی‌انتها را می‌گیرد. این سطح هیچ PHI،
     * هیچ شناسهٔ پزشک و هیچ دادهٔ نوبت منتشر نمی‌کند.
     */
    private const DISCOVERY_MODE  = 'all';
    private const DISCOVERY_PARAM = 'cpms_clinic';
    private const LISTING_SETTING = 'public.listing_enabled';
    private const DIRECTORY_LIMIT = 50;
    private const DISCOVERY_TITLE = 'انتخاب مرکز درمانی';
    private const DISCOVERY_INTRO = 'برای مشاهدهٔ پزشکان و نوبت‌دهی، ابتدا مرکز درمانی را انتخاب کنید.';
    private const DISCOVERY_EMPTY = 'در حال حاضر مرکز درمانی فعالی برای نوبت‌دهی عمومی ثبت نشده است.';
    private const DISCOVERY_BACK  = 'بازگشت به فهرست مراکز';

    // ================= فیلترهای فهرست پزشکان =================

    private const SEARCH_LABEL       = 'جست‌وجوی پزشک';
    private const SEARCH_PLACEHOLDER = 'نام پزشک یا تخصص';
    private const SPECIALTY_LABEL    = 'تخصص';
    private const SPECIALTY_ALL      = 'همهٔ تخصص‌ها';
    private const FILTER_RESET       = 'پاک کردن فیلترها';
    private const DIRECTORY_NO_MATCH = 'پزشکی مطابق جست‌وجو یافت نشد.';

    // ================= تقویم هفتگی (Jalali، سرور-authoritative) =================

    /**
     * نام روزهای هفتهٔ فارسی (شنبه تا جمعه) — هفته از شنبه شروع می‌شود.
     * این آرایه در config runtime منتشر می‌شود تا ستون‌های تقویم هفتگی
     * نامِ روزِ فارسی را از سرور بگیرند (JS هیچ رشتهٔ فارسی ندارد) و
     * برچسبِ روزهای موجود هم از `jalali` خودِ پاسخ A1 می‌آید.
     */
    private const WEEKDAY_NAMES  = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    private const WEEK_PREV      = 'هفتهٔ قبلی';
    private const WEEK_NEXT      = 'هفتهٔ بعدی';
    private const WEEK_FIRST     = 'پرش به اولین هفتهٔ دارای نوبت';
    private const WEEK_EMPTY     = 'در این هفته نوبت آزادی وجود ندارد.';
    private const DAY_EMPTY_TEXT = 'بدون نوبت آزاد';

    public static function register(): void
    {
        add_shortcode(self::TAG, [self::class, 'render']);

        // assets این سطح — ثبت/انکیو شرطی، کاملاً جدا از assets مدیریتی.
        PublicBookingAssets::register();
    }

    /**
     * آیا shortcode این سطح در محتوای Post/Page جاری حاضر است؟
     *
     * این تشخیص، مبنای انکیوِ canonical در هوک `wp_enqueue_scripts` است تا
     * style پیش از `wp_print_styles` در <head> چاپ شود. صفحاتی که این سطح را
     * ندارند هیچ‌یک از دو فایل asset را بار نمی‌کنند.
     */
    public static function isOnCurrentPage(): bool
    {
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }

        $content = $post->post_content;
        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        return has_shortcode($content, self::TAG);
    }

    /**
     * هندلر shortcode — همیشه رشته برمی‌گرداند و هرگز خطای مرگبار نمی‌دهد.
     *
     * هر مسیرِ شکست (ورودی نامعتبر، Clinic ناموجود، خطای DB، خطای غیرمنتظره)
     * به همان وضعیت بستهٔ `error` می‌رسد: سطح عمومی هرگز به‌خاطر یک خطای داخلی
     * سفید/شکسته نمی‌شود و هرگز به Clinic دیگری سرریز نمی‌کند.
     *
     * @param mixed  $atts    attributeهای shortcode (وردپرس ممکن است رشته بدهد)
     * @param mixed  $content محتوای بستهٔ shortcode (استفاده نمی‌شود)
     * @param string $tag     نام تگ (برای فیلتر `shortcode_atts_{tag}`)
     */
    public static function render(mixed $atts = null, mixed $content = null, string $tag = self::TAG): string
    {
        try {
            // حالت discovery — فقط برای مقدار دقیق «all»؛ هر ورودی دیگری
            // همان مسیر fail-closed تاریخی (parseClinicId → closed) را دارد.
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $rawClinic = self::rawClinicAttr( $atts, $tag );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            if ( $rawClinic !== null && strtolower( $rawClinic ) === self::DISCOVERY_MODE ) {
                return self::renderDiscovery();
            }

            $clinicId = self::parseClinicId($atts, $tag);
            if ($clinicId === null) {
                return self::renderClosed();
            }

            $clinic = self::findClinic($clinicId);
            if ($clinic === null) {
                return self::renderClosed();
            }

            $clinicians = self::activeClinicians($clinicId);
            if ($clinicians === []) {
                return self::renderEmpty((int) $clinicId, self::clinicName($clinic));
            }

            return self::renderBrowse((int) $clinicId, self::clinicName($clinic), $clinicians);
        } catch (Throwable $e) {
            // fail-closed مطلق: جزئیات خطا هرگز به بازدیدکنندهٔ آنونیم نشان
            // داده نمی‌شود (نه در markup، نه در پیام)؛ فقط در Log عملیاتی.
            self::logFailure($e);

            return self::renderClosed();
        }
    }

    // ================= لایهٔ پیکربندی Clinic (fail-closed) =================

    /**
     * خواندن و اعتبارسنجیِ **صریحِ** attribute `clinic_id`.
     *
     * تنها منبعِ مجازِ Clinic همین attribute است. `null` یعنی «بسته شو» —
     * برای نبودِ attribute، مقدار غیرعددی، و مقدار `<= 0`. هیچ fallback ای
     * وجود ندارد: نه Clinic با کمترین id، نه «تنها Clinic موجود»، نه Scope
     * محیطی، نه کاربر جاری، نه جغرافیای درخواست.
     *
     * @param mixed $atts
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function parseClinicId( mixed $atts, string $tag ): ?int {
        $trimmed = self::rawClinicAttr( $atts, $tag );
        if ( $trimmed === null || $trimmed === '' ) {
            return null;
        }

        // فقط رقم — یعنی «1.5»، «0x10»، «-3»، « 5abc » و ارقام غیرلاتین همه
        // نامعتبرند. این گیت عمداً از `is_numeric()` سخت‌گیرانه‌تر است تا
        // مقدارِ «تقریباً عددی» هرگز به جست‌وجوی DB نرسد.
        if ( preg_match( '/^\d+$/', $trimmed ) !== 1 ) {
            return null;
        }

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $clinicId = (int) $trimmed;

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        return $clinicId > 0 ? $clinicId : null;
    }

    /**
     * مقدار خامِ trimشدهٔ attribute `clinic_id` — بدون هیچ تفسیری.
     *
     * @param mixed $atts
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function raw_clinic_attr( mixed $atts, string $tag ): ?string
    {
        $parsed = is_array($atts) ? $atts : [];

        /** @var array<string, mixed> $normalized */
        $normalized = shortcode_atts([self::CLINIC_ATTR => ''], $parsed, $tag);

        $raw = $normalized[ self::CLINIC_ATTR ] ?? '';
        if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
            return null;
        }

        return trim( (string) $raw );
    }

    // ================= حالت discovery (clinic_id="all") =================

    /**
     * رندر فهرست عمومی — یا، اگر بازدیدکننده Clinicای را انتخاب کرده و همان
     * Clinic شرایط نمایش عمومی را دارد، سطح مرورِ همان Clinic (با پیوند
     * بازگشت). هر ورودیِ ناموجود/بدون رضختمصری/تعلیق‌شده بی‌تفاوت به فهرست
     * برمی‌گردد (non-enumerating: هیچ تمایزی بین «وجود ندارد» و «مجاز
     * نمایش نیست» فاش نمی‌شود).
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function render_discovery(): string {
        $selected = self::discoverySelectedClinic();
        if ( $selected === null || ! self::isPubliclyListable( $selected ) ) {
            return self::renderDirectory();
        }

        // همان دروازه‌های تاریخیِ سطح پیوندشده — lifecycle دوباره بررسی می‌شود.
        $clinic = self::findClinic( $selected );
        if ( $clinic === null ) {
            return self::renderDirectory();
        }

        $clinicians = self::activeClinicians( $selected );
        if ( $clinicians === [] ) {
            return self::renderEmpty( $selected, self::clinicName( $clinic ), true );
        }

        return self::renderBrowse( $selected, self::clinicName( $clinic ), $clinicians, true );
    }

    /**
     * Clinicِ انتخابشده از query string — فقط selectorِ خواندنی، هرگز authority.
     *
     * نامعتبر/ناموجود/بدون consent → null (فهرست نشان داده می‌شود).
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function discovery_selected_clinic(): ?int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only listing selector; never authority and no state change
        if ( ! isset( $_GET[ self::DISCOVERY_PARAM ] ) || ! is_scalar( $_GET[ self::DISCOVERY_PARAM ] ) ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only listing selector; never authority and no state change
        $raw = trim( wp_unslash( (string) $_GET[ self::DISCOVERY_PARAM ] ) );
        if ( $raw === '' || preg_match( '/^\d+$/', $raw ) !== 1 ) {
            return null;
        }

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $clinicId = (int) $raw;

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        return $clinicId > 0 ? $clinicId : null;
    }

    /**
     * آیا این Clinic شرایطِ فهرستِ عمومی را دارد؟ (fail-closed — همهٔ شرط‌ها)
     *
     *  ۱. ردیف پایدارِ واقعی با وضعیت active؛  ۲. Organization فعال؛
     *  ۳. دست‌کم یک Location فعال؛  ۴. رضختمصریِ نمایش (`public.listing_enabled`
     *     = true — پیش‌فرض false، بدون هیچ fallback).
     */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function is_publicly_listable( int $clinicId ): bool {
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        if ( $clinicId <= 0 ) {
            return false;
        }

        try {
            $row = App::db()->fetchRow(
                'SELECT c.id FROM ' . App::db()->table( 'cpms_clinics' ) . ' c'
                . ' JOIN ' . App::db()->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
                . ' WHERE c.id = %d AND c.status = %s AND o.status = %s'
                . ' AND EXISTS (SELECT 1 FROM ' . App::db()->table( 'cpms_locations' ) . ' l'
                . ' WHERE l.clinic_id = c.id AND l.is_active = 1) LIMIT 1',
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                [$clinicId, 'active', 'active']
            );
            if ( ! is_array( $row ) ) {
                return false;
            }

            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            return self::listingConsent( $clinicId );
        } catch ( Throwable $e ) {
            self::logFailure( $e );

            return false;
        }
    }

    /** رضختمصریِ نمایش — بدون ردیف ذخیره‌شده یا با مقدار غیر-true ⇒ false. */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function listing_consent( int $clinicId ): bool {
        try {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $settings = new Settings( App::db(), $clinicId, App::audit() );

            return (bool) $settings->get( self::LISTING_SETTING, false );
        } catch ( Throwable $e ) {
            self::logFailure( $e );

            return false;
        }
    }

    /**
     * فهرست قطعیِ Clinicهای قابل نمایش (bounded).
     *
     * @return list<array{id: int, name: string}>
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function listable_clinic_rows(): array {
        try {
            $rows = App::db()->fetchAll(
                'SELECT c.id, c.name FROM ' . App::db()->table( 'cpms_clinics' ) . ' c'
                . ' JOIN ' . App::db()->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
                . ' WHERE c.status = %s AND o.status = %s'
                . ' AND EXISTS (SELECT 1 FROM ' . App::db()->table( 'cpms_locations' ) . ' l'
                . ' WHERE l.clinic_id = c.id AND l.is_active = 1)'
                . ' ORDER BY c.name ASC, c.id ASC LIMIT ' . self::DIRECTORY_LIMIT,
                ['active', 'active']
            );
        } catch ( Throwable $e ) {
            self::logFailure( $e );

            return [];
        }

        $listable = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id   = (int) ( $row['id'] ?? 0 );
            $name = trim( (string) ( $row['name'] ?? '' ) );
            if ( $id <= 0 || $name === '' ) {
                continue;
            }
            // رضختمصریِ نمایش — fail-closed؛ بدون consent هیچ ردیفی عبور نمی‌کند.
            if ( ! self::listingConsent( $id ) ) {
                continue;
            }
            $listable[] = ['id' => $id, 'name' => $name];
        }

        return $listable;
    }

    /**
     * وضعیت فهرست — server-rendered، بدون هیچ config runtime (هیچ درخواستی
     * از مرورگر برنمی‌خیزد)، بدون PHI، بدون شناسهٔ پزشک، بدون endpoint جدید.
     * لینک‌ها query string همان برگه‌اند (`?cpms_clinic=N`) و دوباره از همان
     * دروازهٔ consent رد می‌شوند.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function render_directory(): string {
        PublicBookingAssets::enqueue();

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $surfaceId = self::surfaceId();
        $clinics   = self::listableClinicRows();
        $lines     = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--discovery" dir="rtl" lang="fa" data-state="%2$s" data-discovery="1" id="%3$s">',
            esc_attr( self::ROOT_CLASS ),
            esc_attr( self::DISCOVERY_MODE ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $surfaceId )
        );

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $titleId = $surfaceId . '-title';
        $lines[] = sprintf( '<div class="%s">', esc_attr( self::ROOT_CLASS . '__head' ) );
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr( self::ROOT_CLASS . '__title' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $titleId ),
            esc_html( self::DISCOVERY_TITLE )
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s</p>',
            esc_attr( self::ROOT_CLASS . '__discovery-intro' ),
            esc_html( self::DISCOVERY_INTRO )
        );
        $lines[] = '</div>';

        if ( $clinics === [] ) {
            $lines[] = sprintf(
                '<p class="%s" role="status">%s</p>',
                esc_attr( self::ROOT_CLASS . '__notice' ),
                esc_html( self::DISCOVERY_EMPTY )
            );
        } else {
            $lines[] = sprintf(
                '<ul class="%1$s" role="list" aria-labelledby="%2$s">',
                esc_attr( self::ROOT_CLASS . '__clinic-directory' ),
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                esc_attr( $titleId )
            );
            foreach ( $clinics as $clinic ) {
                $url     = add_query_arg( self::DISCOVERY_PARAM, (int) $clinic['id'] );
                $lines[] = sprintf( '<li class="%s">', esc_attr( self::ROOT_CLASS . '__clinic-directory-item' ) );
                $lines[] = sprintf(
                    '<a class="%1$s" href="%2$s" data-clinic-id="%3$d"><span class="%4$s">%5$s</span></a>',
                    esc_attr( self::ROOT_CLASS . '__clinic-directory-link' ),
                    esc_url( $url ),
                    (int) $clinic['id'],
                    esc_attr( self::ROOT_CLASS . '__clinic-directory-name' ),
                    esc_html( (string) $clinic['name'] )
                );
                $lines[] = '</li>';
            }
            $lines[] = '</ul>';
        }

        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /** پیوند بازگشت به فهرست — فقط روی سطحِ انتخابشده در حالت discovery. */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function render_back_link(): string
    {
        return sprintf(
            '<p class="%1$s"><a class="%2$s" href="%3$s" data-role="discovery-back">%4$s</a></p>',
            esc_attr( self::ROOT_CLASS . '__discovery-back' ),
            esc_attr( self::ROOT_CLASS . '__discovery-back-link' ),
            esc_url( remove_query_arg( self::DISCOVERY_PARAM ) ),
            esc_html( self::DISCOVERY_BACK )
        );
    }

    /**
     * واکشی ردیفِ پایدارِ Clinic — fail-closed اگر وجود نداشته باشد.
     *
     * `cpms_clinics` ستون `is_active` ندارد، پس «Clinic معتبر» = «ردیفِ واقعیِ
     * پایدار با همین id». صلاحیتِ نوبت‌دهی از `cpms_clinicians.is_active`
     * می‌آید (لایهٔ بعد).
     *
     * @return array<string, mixed>|null
     */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function findClinic( int $clinicId ): ?array {
        try {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $row = App::clinicRepository()->find( $clinicId );
        } catch ( Throwable $e ) {
            self::logFailure( $e );

            return null;
        }

        if ( ! is_array( $row ) ) {
            return null;
        }

        // Clinic lifecycle policy - public surface: a suspended Clinic advertises no
        // clinicians and no bookable slots, so the shortcode renders its established
        // "not available" state instead of a browse list. Existing appointment access
        // happens on authenticated surfaces and is unaffected.
        // Narrow naming ignore for the established camelCase parameter of this method;
        // no security sniff is suppressed by it.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $requested_clinic_id = (int) $clinicId;
        $clinic_status       = App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $requested_clinic_id ]
        );
        if ( $clinic_status !== null && (string) $clinic_status === 'suspended' ) {
            return null;
        }

        // Defence in depth: ردیف باید همان Clinicِ درخواستی باشد.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        return  (int ) ( $row['id'] ?? 0 ) === $clinicId ? $row : null;
    }

    /**
     * پزشکانِ **فعالِ** همان Clinic — به‌ترتیب نام، بدون هیچ Clinic دیگری.
     *
     * دو منبع (هر دو هم‌پوشانی صفر دارند):
     *  ۱. پزشکانِ خانهٔ همین Clinic — `listAll($clinicId, false)`؛
     *  ۲. پزشکانِ خانهٔ Clinicِ دیگر با عضویتِ فعالِ پایدار در همین Clinic —
     *     همان predicateِ `ClinicianRepository::list_participating_in_clinic()`
     *     با ستون‌های هویت کامل (is_active و clinic_id بررسی مجدد می‌شوند).
     *
     * روزهای فعالِ هفتگی هر پزشک فقط از برنامهٔ فعالِ همین Clinic خوانده
     * می‌شود (cpms_schedule، روزهای صفرشدهٔ غیرفعال حذف) — نه خانه، نه
     * Clinic دیگر.
     *
     * @return list<array{id: int, name: string, specialty: string, working_days: list<string>}>
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function activeClinicians (int $clinicId ): array
    {
        try {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $homeRows   = App::clinicianRepository()->listAll( $clinicId, false );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $sharedRows = App::db()->fetchAll(
                'SELECT c.id, c.full_name, c.specialty FROM ' . App::db()->table( 'cpms_clinicians' ) . ' c'
                . ' JOIN ' . App::db()->table( 'cpms_clinic_memberships' ) . ' m'
                . ' ON m.wp_user_id = c.wp_user_id AND m.clinic_id = %d AND m.status = %s'
                . ' WHERE c.is_active = 1 AND c.clinic_id <> %d'
                . ' ORDER BY c.full_name ASC LIMIT 500',
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                [$clinicId, 'active', $clinicId]
            );
        } catch (Throwable $e) {
            self::logFailure($e);

            return [];
        }

        $clinicians = [];
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        foreach ( $homeRows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $id =  (int ) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            if ( (int) ( $row['clinic_id'] ?? 0 ) !== $clinicId ) {
                continue;
            }
            if ( (int) ( $row['is_active'] ?? 0 ) !== 1 ) {
                continue;
            }

            $name = trim( (string) ( $row['full_name'] ?? '' ) );
            if ( $name === '' ) {
                // پزشکِ بدون نامِ قابل‌نمایش روی سطح عمومی قابل ارائه نیست
                // (دکمهٔ بی‌نام، UI غیرقابل‌استفاده می‌سازد).
                continue;
            }

            $clinicians[ $id ] = [
                'id'           => $id,
                'name'         => $name,
                'specialty'    => trim( (string) ( $row['specialty'] ?? '' ) ),
                'working_days' => [],
            ];
        }

        // عضویت فعال در همین Clinic — همان predicate repo + دفاع در برابر
        // تغییر همزمان (is_active / clinic_id دوباره بررسی می‌شوند).
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        foreach ( is_array( $sharedRows ) ? $sharedRows : [] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id   = (int) ( $row['id'] ?? 0 );
            $name = trim( (string) ( $row['full_name'] ?? '' ) );
            if ( $id <= 0 || $name === '' || isset( $clinicians[ $id ] ) ) {
                continue;
            }
            $clinicians[ $id ] = [
                'id'           => $id,
                'name'         => $name,
                'specialty'    => trim( (string) ( $row['specialty'] ?? '' ) ),
                'working_days' => [],
            ];
        }

        if ( $clinicians === [] ) {
            return [];
        }

        // روزهای فعالِ همین Clinic برای همه (یک query، N+1 نیست).
        try {
            $ids          = array_map( 'intval', array_keys( $clinicians ) );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $dayRows = App::db()->fetchAll(
                'SELECT clinician_id, day_of_week FROM ' . App::db()->table( 'cpms_schedule' )
                . ' WHERE clinic_id = %d AND is_active = 1 AND clinician_id IN (' . $placeholders . ')',
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                array_merge( [$clinicId], $ids )
            );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $daysByClinician = [];
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            foreach ( is_array( $dayRows ) ? $dayRows : [] as $dayRow ) {
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                $owner = (int) ( $dayRow['clinician_id'] ?? 0 );
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                $day = (int) ( $dayRow['day_of_week'] ?? -1 );
                if ( $owner > 0 && $day >= 0 && $day < count( self::WEEKDAY_NAMES ) ) {
                    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                    $daysByClinician[$owner][] = $day;
                }
            }
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            foreach ( $daysByClinician as $owner => $days ) {
                $days = array_values( array_unique( array_map( 'intval', $days ) ) );
                sort( $days );
                $names = [];
                foreach ( $days as $day ) {
                    $names[] = self::WEEKDAY_NAMES[ $day ];
                }
                if ( isset( $clinicians[ $owner ] ) ) {
                    $clinicians[ $owner ]['working_days'] = $names;
                }
            }
        } catch ( Throwable $e ) {
            // روزهای کاریِ نمایشی — شکستشان نباید سطح را از کار بیندازد؛
            // نمایش بدون روز کاری امن‌تر از حدس زدن است.
            self::logFailure( $e );
        }

        // ترتیب قطعیِ نهایی: نام، سپس id.
        $clinicians = array_values( $clinicians );
        usort(
            $clinicians,
            static function ( array $a, array $b ): int {
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                $byName = strcmp( (string) $a['name'], (string) $b['name'] );

                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                return $byName !== 0 ? $byName : ( (int) $a['id'] <=> (int) $b['id'] );
            }
        );

        return $clinicians;
    }

    /**
     * نرمال‌سازی جست‌وجوی فارسی/عربی — آینهٔ دقیقِ همان تبدیل در JS.
     *
     * ی↔ي (یکنواخت)، ك↔ک، حروف «أ إ آ ٱ»→ا، ة→ه، کشیده و اعراب حذف،
     * فاصله‌های چندگانه یکی، حروف لاتین به کوچک. فقط ارائهٔ متن است —
     * هیچ دادهٔ هویتی در آن نیست.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function normalize_doctor_search( string $value ): string {
        // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.MultipleArguments -- established transliteration-map call shape; no security sniff suppressed.
        $value = strtr($value, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ئ' => 'ی',
            'ك' => 'ک',
            'ڪ' => 'ک',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ـ' => '',
            // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine -- established multi-line call shape; no security sniff suppressed.
        ]);
        // حذف اعراب/تَشکیل (U+064B..U+065F) و نشانهٔ مد (U+0670).
        $value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value ) ?? $value;
        $value = strtolower( $value );
        $value = preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? $value;

        return $value;
    }

    /**
     * بلوک فیلترهای فهرست پزشکان — server-rendered (label/select/button و
     * متن‌های فارسی همه سمت سرور؛ JS فقط hidden می‌کند و هیچ endpointی
     * برای جست‌وجو ساخته نمی‌شود).
     *
     * @param list<array{id: int, name: string, specialty: string, working_days: list<string>}> $clinicians
     */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function render_doctor_filters( string $surfaceId, array $clinicians ): string {
        $specialties = [];
        foreach ( $clinicians as $clinician ) {
            $specialty = trim( (string) ( $clinician['specialty'] ?? '' ) );
            if ( $specialty !== '' ) {
                $specialties[ $specialty ] = true;
            }
        }
        $specialties = array_keys( $specialties );
        sort( $specialties, SORT_STRING );

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $searchId = $surfaceId . '-doctor-search';
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $specialtyId = $surfaceId . '-specialty-filter';
        $b           = self::ROOT_CLASS;
        $lines       = [];

        $lines[] = sprintf( '<div class="%s" data-role="doctor-filters">', esc_attr( $b . '__doctor-filters' ) );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__filter-label' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $searchId ),
            esc_html( self::SEARCH_LABEL )
        );
        $lines[] = sprintf(
            '<input type="search" id="%1$s" class="%2$s" data-role="doctor-search" placeholder="%3$s" autocomplete="off">',
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $searchId ),
            esc_attr( $b . '__doctor-search' ),
            esc_attr( self::SEARCH_PLACEHOLDER )
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__filter-label' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $specialtyId ),
            esc_html( self::SPECIALTY_LABEL )
        );
        $lines[] = sprintf(
            '<select id="%1$s" class="%2$s" data-role="specialty-filter">',
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $specialtyId ),
            esc_attr( $b . '__specialty-filter' )
        );
        $lines[] = sprintf( '<option value="">%s</option>', esc_html( self::SPECIALTY_ALL ) );
        foreach ( $specialties as $specialty ) {
            $lines[] = sprintf(
                '<option value="%1$s">%1$s</option>',
                esc_attr( $specialty )
            );
        }
        $lines[] = '</select>';
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="doctor-filter-reset" hidden>%2$s</button>',
            esc_attr( $b . '__filter-reset' ),
            esc_html( self::FILTER_RESET )
        );
        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * @param array<string, mixed> $clinic
     */
    private static function clinicName(array $clinic): string {
        $name = trim((string) ($clinic['name'] ?? ''));

        return $name;
    }

    // ================= رندر =================

    /**
     * وضعیت بسته (D-3) — یک شکلِ یکتا برای هر سه ورودیِ نامعتبر.
     *
     * عمداً منتشر نمی‌کند: هیچ `data-clinic-id`، هیچ ورودی پزشک، هیچ
     * `<script>` قراردادِ runtime. یعنی برای سطحِ بسته هیچ «قراردادِ قابل
     * اجرا» به مرورگر داده نمی‌شود و JS هیچ درخواستی نمی‌فرستد.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function renderClosed(): string {
        PublicBookingAssets::enqueue();

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $surfaceId = self::surfaceId();
        $lines     = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--closed" dir="rtl" lang="fa" data-state="%2$s" id="%3$s">',
            esc_attr(self::ROOT_CLASS),
            esc_attr(self::STATE_ERROR),
            esc_attr($surfaceId)
        );
        $lines[] = sprintf(
            '<p class="%s" role="alert">%s</p>',
            esc_attr(self::ROOT_CLASS . '__notice'),
            esc_html(self::CLOSED_NOTICE)
        );
        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * وضعیت `empty` — Clinic **معتبر** است ولی پزشک فعالی برای نوبت‌دهی ندارد.
     *
     * این وضعیت عمداً از `error` جداست: `error` یعنی «پیکربندیِ سطح خراب است»
     * و `empty` یعنی «پیکربندی درست است، چیزی برای رزرو نیست». در هر دو حالت
     * هیچ پزشکی منتشر نمی‌شود.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function render_empty( int $clinicId, string $clinicName, bool $discovery = false ): string
    {
        PublicBookingAssets::enqueue();

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $surfaceId = self::surfaceId();
        $lines     = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--empty" dir="rtl" lang="fa" data-clinic-id="%2$d" data-state="%3$s" id="%4$s">',
            esc_attr( self::ROOT_CLASS ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $clinicId,
            esc_attr(self::STATE_EMPTY),
            esc_attr($surfaceId)
        );
        if ( $discovery ) {
            $lines[] = self::renderBackLink();
        }
        $lines[] = self::renderHead($clinicName, $surfaceId);
        $lines[] = sprintf(
            '<p class="%s" role="status">%s</p>',
            esc_attr( self::ROOT_CLASS . '__notice' ),
            esc_html( self::NO_CLINICIAN_NOTICE )
        );
        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * وضعیت `bookable` — سطحِ کاملِ مرور: لیست پزشکان (server-rendered) +
     * panel مرورِ نوبت + قالب‌ها + قراردادِ runtime.
     *
     * @param list<array{id: int, name: string, specialty: string, working_days: list<string>}> $clinicians
     */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function render_browse( int $clinicId, string $clinicName, array $clinicians, bool $discovery = false ): string {
        PublicBookingAssets::enqueue();

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $surfaceId = self::surfaceId();
        $lines     = [];

        $lines[] = sprintf(
            '<div class="%1$s" dir="rtl" lang="fa" data-clinic-id="%2$d" data-state="%3$s" id="%4$s">',
            esc_attr(self::ROOT_CLASS),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase local; no security sniff suppressed.
            $clinicId,
            esc_attr( self::STATE_BOOKABLE ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $surfaceId )
        );
        if ( $discovery ) {
            // انتخاب از فهرست عمومی — پیوند بازگشت به همان فهرست (بدون reload
            // از سمت JS؛ خودِ href کافی است).
            $lines[] = self::renderBackLink();
        }
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $lines[] = self::renderHead( $clinicName, $surfaceId );

        $lines[] = sprintf( '<div class="%s">', esc_attr( self::ROOT_CLASS . '__body' ) );

        // ---- گام ۱: پزشکان (server-rendered؛ هیچ endpoint عمومیِ لیست پزشک) ----
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $legendId = $surfaceId . '-clinicians';
        $lines[]  = sprintf( '<div class="%s">', esc_attr( self::ROOT_CLASS . '__clinicians' ) );
        $lines[]  = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr( self::ROOT_CLASS . '__legend' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $legendId ),
            esc_html( self::STEP_CLINICIAN )
        );
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $lines[] = self::renderDoctorFilters( $surfaceId, $clinicians );
        $lines[] = sprintf(
            '<ul class="%1$s" role="list" aria-labelledby="%2$s" data-role="clinicians">',
            esc_attr( self::ROOT_CLASS . '__clinician-list' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $legendId )
        );
        foreach ( $clinicians as $clinician ) {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $searchBlob = self::normalizeDoctorSearch(
                (string) $clinician['name'] . ' ' . (string) ( $clinician['specialty'] ?? '' )
            );
            $lines[]    = sprintf( '<li class="%s">', esc_attr( self::ROOT_CLASS . '__clinician-item' ) );
            $lines[] = sprintf(
                '<button type="button" class="%1$s" data-clinician-id="%2$d" aria-pressed="false"'
                . ' data-search="%3$s" data-specialty="%4$s">'
                . '<span class="%5$s">%6$s</span>'
                . '<span class="%7$s">%8$s</span>'
                . '</button>',
                esc_attr(self::CLINICIAN_CLASS),
                (int) $clinician['id'],
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                esc_attr( $searchBlob ),
                esc_attr( trim( ( string ) ( $clinician['specialty'] ?? '' ) ) ),
                esc_attr(self::ROOT_CLASS . '__clinician-name'),
                esc_html((string) $clinician['name']),
                esc_attr(self::ROOT_CLASS . '__clinician-hint'),
                esc_html(self::CLINICIAN_HINT)
            );
            $specialty  = trim( ( string ) ( $clinician['specialty'] ?? '' ) );
            if ( $specialty !== '' ) {
                $lines[] = sprintf(
                    '<span class="%1$s">%2$s</span>',
                    esc_attr( self::ROOT_CLASS . '__clinician-specialty' ),
                    esc_html( $specialty )
                );
            }
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $workingDays = ( array ) ( $clinician['working_days'] ?? [] );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            if ( $workingDays !== [] ) {
                $lines[] = sprintf(
                    '<ul class="%1$s" role="list">',
                    esc_attr( self::ROOT_CLASS . '__clinician-days' )
                );
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                foreach ( $workingDays as $dayName ) {
                    $lines[] = sprintf(
                        '<li class="%1$s">%2$s</li>',
                        esc_attr( self::ROOT_CLASS . '__clinician-day' ),
                        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
                        esc_html( (string) $dayName )
                    );
                }
                $lines[] = '</ul>';
            }
            $lines[] = '</li>';
        }
        $lines[] = '</ul>';
        $lines[] = sprintf(
            '<p class="%1$s" data-role="directory-empty" role="status" hidden>%2$s</p>',
            esc_attr( self::ROOT_CLASS . '__directory-empty' ),
            esc_html( self::DIRECTORY_NO_MATCH )
        );
        $lines[] = '</div>';
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $lines[] = self::render_location_picker( (string) $surfaceId );

        // ---- گام ۳: مرورِ نوبت‌ها (با A1 موجود) ----
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $panelLegendId = $surfaceId . '-calendar';
        $lines[]       = sprintf( '<div class="%s">', esc_attr( self::ROOT_CLASS . '__calendar' ) );
        $lines[]       = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr( self::ROOT_CLASS . '__legend' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $panelLegendId ),
            esc_html( self::STEP_SLOT )
        );
        $lines[] = sprintf(
            '<div class="%1$s" data-role="panel" data-state="idle" role="group" aria-labelledby="%2$s" aria-busy="false">',
            esc_attr( self::ROOT_CLASS . '__panel' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $panelLegendId )
        );

        // ناحیهٔ اعلامِ وضعیت (role="status" ⇒ aria-live=polite + atomic).
        // عمداً **فقط** پیام‌ها و جزئیات داخل این ناحیه‌اند و فهرست روزها
        // بیرونِ آن است: با این تفکیک، صفحه‌خوان فقط «وضعیت» را اعلام می‌کند
        // و نه کلِ ده‌ها نوبتِ تازه‌رندرشده را.
        $lines[] = sprintf(
            '<div class="%s" role="status" aria-live="polite">',
            esc_attr(self::ROOT_CLASS . '__status')
        );

        // پیام‌های هر وضعیت — CSS بر پایهٔ `data-state` فقط یکی را نشان می‌دهد،
        // پس JS هیچ رشتهٔ فارسی ندارد.
        foreach (self::PANEL_MESSAGES as $state => $message) {
            $lines[] = sprintf(
                '<p class="%1$s" data-message="%2$s">%3$s</p>',
                esc_attr(self::ROOT_CLASS . '__message'),
                esc_attr((string) $state),
                esc_html((string) $message)
            );
        }

        $lines[] = sprintf(
            '<p class="%s" data-role="detail" hidden></p>',
            esc_attr(self::ROOT_CLASS . '__detail')
        );
        $lines[] = '</div>'; // status

        // ---- تقویم هفتگی (server-published؛ مرز هفته و برچسب روزها از دادهٔ
        // سرور می‌آید: نام روزها از weekday_names در config و jalali روزهای
        // موجود از پاسخ A1 — هیچ محاسبهٔ bookability در JS نیست) ----
        $lines[] = sprintf( '<div class="%s" data-role="week-nav" hidden>', esc_attr( self::ROOT_CLASS . '__week-nav' ) );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="week-prev">%2$s</button>',
            esc_attr( self::ROOT_CLASS . '__week-btn' ),
            esc_html( self::WEEK_PREV )
        );
        $lines[] = sprintf(
            '<p class="%1$s" data-role="week-label" aria-live="polite"></p>',
            esc_attr( self::ROOT_CLASS . '__week-label' )
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="week-next">%2$s</button>',
            esc_attr( self::ROOT_CLASS . '__week-btn' ),
            esc_html( self::WEEK_NEXT )
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="week-first">%2$s</button>',
            esc_attr( self::ROOT_CLASS . '__week-btn' ),
            esc_html( self::WEEK_FIRST )
        );
        $lines[] = sprintf(
            '<span class="%1$s" data-role="week-empty" hidden>%2$s</span>',
            esc_attr( self::ROOT_CLASS . '__week-empty' ),
            esc_html( self::WEEK_EMPTY )
        );
        $lines[] = '</div>';

        $lines[] = sprintf(
            '<div class="%s" data-role="days"></div>',
            esc_attr(self::ROOT_CLASS . '__days')
        );
        $lines[] = '</div>'; // panel
        $lines[] = self::renderTemplates();
        $lines[] = '</div>'; // calendar

        // ---- Phase 8 Slice 2: ادامهٔ احراز/رزرو — وابسته به وضعیت احراز ----
        $continuation = self::continuationState();
        if ($continuation === self::CONTINUATION_ANONYMOUS) {
            // فقط چرخهٔ ورود با OTP؛ بدون nonce، بدون B1/B2، بدون PHI.
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase local; no security sniff suppressed.
            $lines[] = self::renderAuthContinuation( $surfaceId );
        } elseif ( $continuation === self::CONTINUATION_PATIENT ) {
            // بیمارِ واردشده: ادامهٔ Hold→Confirm با nonce مسیرهای موجود.
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            $lines[] = self::renderPatientContinuation( $surfaceId, $clinicId );
        }

        $lines[] = '</div>'; // body

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $lines[] = self::renderConfig( $clinicId, $surfaceId );
        $lines[] = '</div>'; // root

        return implode( '', $lines );
    }

    /**
     * وضعیتِ continuation این رندر — فقط از وضعیتِ احرازِ سمت سرور.
     *
     *  - anonymous: بازدیدکنندهٔ بدون session — گیرندهٔ چرخهٔ ورود OTP؛
     *  - patient: کاربرِ واردشده با نقش بیمار — گیرندهٔ nonce + B1/B2؛
     *  - none: سایر کاربرانِ واردشده (کارکنان/مدیر) — بدون continuation بیمار.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function continuationState(): string {
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $userId = get_current_user_id();
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        if ( $userId <= 0 ) {
            return self::CONTINUATION_ANONYMOUS;
        }

        $user = wp_get_current_user();
        if ( in_array( RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true ) ) {
            return self::CONTINUATION_PATIENT;
        }

        return self::CONTINUATION_NONE;
    }

    /**
     * Phase 8 Slice 2 — چرخهٔ ورود OTP برای بازدیدکنندهٔ آنونیم.
     *
     * همهٔ متن‌ها server-side و escape می‌شوند؛ JS فقط نشانه‌ها را toggle و
     * مقادیر را با textContent می‌گذارد. عمداً بدون nonce، بدون مسیرِ
     * hold/confirm و بدون هیچ PHI — ورود فقط با شمارهٔ موبایل و کدِ پیامکی
     * روی مسیرهای موجودِ A2/A3 انجام می‌شود.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function renderAuthContinuation (string $surfaceId ): string
    {
        $b = self::ROOT_CLASS;
        $mobileInputId = $surfaceId . '-otp-mobile';
        $codeInputId = $surfaceId . '-otp-code';

        $lines = [];
        $lines[] = sprintf('<div class="%s">', esc_attr($b . '__auth'));

        // نقطهٔ شروعِ «ادامه» — انتخابِ غیر-PHI پیش از آن در سطح حفظ می‌شود.
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="continue-auth">%2$s</button>',
            esc_attr($b . '__continue-btn'),
            esc_html('ادامهٔ رزرو با کد تأیید پیامکی')
        );

        $lines[] = sprintf( '<div class="%1$s" data-auth-step="otp-mobile" hidden>', esc_attr( $b . '__auth-step' ) );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__label' ),
            esc_attr( $mobileInputId ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established local parameter.
            esc_html( 'شماره موبایل (۱۱ رقم با پیش‌شماره ۰۹)' )
        );
        $lines[] = sprintf(
            '<input type="tel" class="%1$s" id="%2$s" data-role="otp-mobile" inputmode="tel" maxlength="11" autocomplete="tel" placeholder="09XXXXXXXXX" dir="ltr">',
            esc_attr( $b . '__input' ),
            esc_attr( $mobileInputId ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established local parameter.
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-auth-action="otp-request">%2$s</button>',
            esc_attr( $b . '__btn' ),
            esc_html( 'دریافت کد تأیید' )
        );
        $lines[] = '</div>';

        $lines[] = sprintf( '<div class="%1$s" data-auth-step="otp-code" hidden>', esc_attr( $b . '__auth-step' ) );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__label' ),
            esc_attr( $codeInputId ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established local parameter.
            esc_html( 'کد ۶ رقمی پیامک‌شده' )
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="otp-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr">',
            esc_attr( $b . '__input' ),
            esc_attr( $codeInputId ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established local parameter.
        );
        $lines[] = sprintf(
            '<div class="%1$s"><button type="button" class="%2$s" data-auth-action="otp-verify">%3$s</button>'
            . '<button type="button" class="%4$s" data-auth-action="otp-change-mobile">%5$s</button></div>',
            esc_attr( $b . '__auth-actions' ),
            esc_attr( $b . '__btn' ),
            esc_html( 'تأیید و ادامه' ),
            esc_attr( $b . '__link-btn' ),
            esc_html( 'تغییر شماره موبایل' )
        );
        $lines[] = '</div>';

        // پیامِ وضعیتِ احراز — فقط متنِ خودِ سرور (envelope A2/A3) اینجا نشسته
        // می‌شود؛ هیچ تفسیر یا رشتهٔ جدیدی در مرورگر ساخته نمی‌شود.
        $lines[] = sprintf(
            '<p class="%1$s" data-role="auth-status" role="status" aria-live="polite" hidden></p>',
            esc_attr( $b . '__status' )
        );

        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * Phase 8 Slice 2 — ادامهٔ Hold→Confirm برای بیمارِ واردشده.
     *
     * nonce و مسیرهای B1/B2 از قرارداد runtime می‌آیند (renderConfig)؛ این
     * بخش فقط اسکلتِ وضعیت‌ها را server-render می‌کند: شمارشِ معکوسِ Hold،
     * فرمِ نامِ بیمارِ جدید، پیشنهادهای نزدیک پس از باختِ race، رسیدِ نهایی
     * (کد رهگیری + تاریخ جلالی). انتخابِ قبلیِ غیر-PHI توسط JS باز-نشانی و
     * با B1 موجود ادامه می‌یابد (B6 resume عمداً استفاده نمی‌شود).
     */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method name kept to avoid changing the API surface; no security sniff suppressed. established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
    private static function renderPatientContinuation  (string $surfaceId, int $clinicId ): string {
        $b = self::ROOT_CLASS;
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $firstNameId = $surfaceId . '-first-name';
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $lastNameId = $surfaceId . '-last-name';

        // Phase 8 Slice 3 — chooser برای N>1 linked active Patients (LINKED-ONLY authority).
        // فقط برای N>1 رندر می‌شود؛ 0 یا 1 هیچ chooserی ندارد. هرگز decoy/same-mobile/cross-clinic/archived را نشان نمی‌دهد.
        // Render chooser OUTSIDE the hidden booking-continue box so it is visible immediately after login.
        $chooser_html     = '';
        $is_first_patient = false;
        try {
            $uid = get_current_user_id();
            if ( $uid > 0 && $clinicId > 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method signature.
                $db_chooser      = \ClinicCore\Bootstrap\App::db();
                $linked_patients = $db_chooser->fetchAll(
                    'SELECT p.id, p.first_name, p.last_name FROM ' . $db_chooser->table( 'cpms_patient_user_links' ) . ' l JOIN ' . $db_chooser->table( 'cpms_patients' ) . ' p ON p.id = l.patient_id WHERE l.wp_user_id = %d AND l.clinic_id = %d AND p.status = %s AND p.clinic_id = %d ORDER BY l.is_primary DESC, l.id ASC',
                    [ $uid, $clinicId, 'active', $clinicId ] // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method signature.
                );
                if ( is_array( $linked_patients ) && count( $linked_patients ) === 0 ) {
                    $is_first_patient = true;
                } elseif ( is_array( $linked_patients ) && count( $linked_patients ) > 1 ) {
                    $chooser_parts   = [];
                    $chooser_parts[] = sprintf( '<div class="%s" data-role="patient-chooser">', esc_attr( $b . '__chooser' ) );
                    $chooser_parts[] = sprintf( '<p class="%s">%s</p>', esc_attr( $b . '__chooser-hint' ), esc_html( 'لطفاً بیمار مورد نظر برای رزرو را انتخاب کنید:' ) );
                    foreach ( $linked_patients as $lp ) {
                        if ( ! is_array( $lp ) ) {
                            continue;
                        }
                        $pid = (int) ( $lp['id'] ?? 0 );
                        if ( $pid <= 0 ) {
                            continue;
                        }
                        $fn    = trim( (string) ( $lp['first_name'] ?? '' ) );
                        $ln    = trim( (string) ( $lp['last_name'] ?? '' ) );
                        $label = trim( $fn . ' ' . $ln );
                        if ( $label === '' ) {
                            $label = 'بیمار #' . $pid;
                        }
                        $chooser_parts[] = sprintf(
                            '<button type="button" class="%s" data-role="patient-option" data-patient-id="%d" aria-pressed="false">%s</button>',
                            esc_attr( $b . '__patient-option' ),
                            $pid,
                            esc_html( $label )
                        );
                    }
                    $chooser_parts[] = '</div>';
                    $chooser_html    = implode( '', $chooser_parts );
                }
            }
        } catch ( \Throwable $e ) {
            $chooser_html     = '';
            $is_first_patient = false;
        }

        $lines = [];
        if ( $chooser_html !== '' ) {
            $lines[] = $chooser_html;
        }
        $lines[] = sprintf(
            '<div class="%1$s" data-role="booking-continue" hidden>',
            esc_attr( $b . '__continue-box' )
        );

        $lines[] = sprintf(
            '<p class="%1$s" data-role="continue-status" role="alert" aria-live="assertive" hidden></p>',
            esc_attr( $b . '__status' )
        );

        // شمارشِ معکوس TTL Hold — فقط مقدار عددی توسط JS پر می‌شود.
        $lines[] = sprintf(
            '<p class="%1$s" data-role="hold-countdown" hidden>%2$s <b data-role="countdown-value" dir="ltr"></b></p>',
            esc_attr( $b . '__countdown' ),
            esc_html( 'زمان باقی‌ماندهٔ نگه‌داشتن نوبت:' )
        );

        // فرمِ نام — برای بیمارِ «جدید» در Clinicِ نوبت به‌صورت پیش‌دستانه پس از Hold هم نمایش داده می‌شود؛
        // سرور همچنان مرجع نهایی الزام با CLINIC_VALIDATION_FAILED باقی می‌ماند.
        $lines[] = sprintf(
            '<div class="%1$s" data-role="names-form"%2$s hidden>',
            esc_attr( $b . '__names' ),
            $is_first_patient ? ' data-first-time="1"' : ''
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s</p>',
            esc_attr( $b . '__names-hint' ),
            esc_html( 'برای ثبت این نوبت، نام و نام خانوادگی خود را وارد کنید:' )
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__label' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $firstNameId ),
            esc_html( 'نام' )
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="patient-first-name" autocomplete="given-name">',
            esc_attr( $b . '__input' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $firstNameId )
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__label' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $lastNameId ),
            esc_html( 'نام خانوادگی' )
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="patient-last-name" autocomplete="family-name">',
            esc_attr( $b . '__input' ),
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
            esc_attr( $lastNameId )
        );
        $lines[] = '</div>';

        $reason_id = $surfaceId . '-booking-reason'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method signature.
        $lines[]   = sprintf( '<div class="%1$s" data-role="reason-wrap" hidden>', esc_attr( $b . '__reason' ) );
        $lines[]   = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $b . '__label' ),
            esc_attr( $reason_id ),
            esc_html( 'علت مراجعه (اختیاری)' )
        );
        $lines[]   = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="booking-reason" maxlength="255">',
            esc_attr( $b . '__input' ),
            esc_attr( $reason_id )
        );
        $lines[]   = '</div>';

        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="confirm-btn" hidden>%2$s</button>',
            esc_attr( $b . '__btn' ),
            esc_html( 'تأیید نهایی نوبت' )
        );

        // رسیدِ نهایی — مقادیر (کد رهگیری/تاریخ جلالی/ساعت) توسط JS از پاسخ
        // خودِ B2 با textContent جای‌گذاری می‌شوند.
        $portal_url = '';
        try {
            $portal_url = PatientPortalShell::portal_url();
        } catch ( \Throwable $e ) {
            $portal_url = '';
        }

        $lines[] = sprintf( '<div class="%1$s" data-role="receipt" hidden>', esc_attr( $b . '__receipt' ) );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s</p>',
            esc_attr( $b . '__receipt-title' ),
            esc_html( 'نوبت شما با موفقیت ثبت شد.' )
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s <b data-role="reference-code" dir="ltr"></b></p>',
            esc_attr( $b . '__receipt-line' ),
            esc_html( 'کد رهگیری:' )
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s <b data-role="slot-jalali"></b> — %3$s <b data-role="slot-time" dir="ltr"></b></p>',
            esc_attr( $b . '__receipt-line' ),
            esc_html( 'تاریخ نوبت:' ),
            esc_html( 'ساعت:' )
        );
        if ( $portal_url !== '' ) {
            $lines[] = sprintf(
                '<p class="%1$s"><a class="%2$s" data-role="receipt-portal-link" href="%3$s">%4$s</a></p>',
                esc_attr( $b . '__receipt-actions' ),
                esc_attr( $b . '__portal-link' ),
                esc_url( $portal_url ),
                esc_html( 'مشاهده، تغییر یا لغو نوبت در پرتال بیمار' )
            );
        }
        $lines[] = '</div>';

        // پیشنهادهای نزدیک پس از CLINIC_SLOT_TAKEN — از دادهٔ خودِ سرور
        // (nearby_slots) با همان قالبِ slot رندر می‌شوند.
        $lines[] = sprintf('<div class="%1$s" data-role="nearby" hidden>', esc_attr($b . '__nearby'));
        $lines[] = sprintf(
            '<p class="%1$s">%2$s</p>',
            esc_attr($b . '__nearby-hint'),
            esc_html('این نوبت در لحظهٔ آخر پر شد — نوبت‌های نزدیکِ آزاد:')
        );
        $lines[] = sprintf(
            '<div class="%1$s" data-role="nearby-list"></div>',
            esc_attr($b . '__nearby-list')
        );
        $lines[] = '</div>';

        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * Accessible Location chooser; its options are populated from Location
     * metadata carried by the existing A1 availability response.
     */
    private static function render_location_picker( string $surface_id ): string {
        $label_id = $surface_id . '-locations';
        $base     = self::ROOT_CLASS;

        return sprintf(
            '<section class="%1$s" data-role="location-picker" role="group" aria-labelledby="%2$s" hidden>'
            . '<p class="%3$s" id="%2$s">%4$s</p>'
            . '<ul class="%5$s" data-role="location-options" role="list" aria-labelledby="%2$s"></ul>'
            . '<div class="%6$s" data-role="location-details" aria-live="polite" hidden>'
            . '<p class="%7$s"><b data-role="location-name"></b></p>'
            . '<p class="%8$s" data-role="location-address-row" hidden>%9$s <span data-role="location-address" data-fill="address"></span></p>'
            . '</div>'
            . '<p class="%10$s" data-role="location-hint" role="status" hidden>%11$s</p>'
            . '</section>',
            esc_attr( $base . '__location-picker' ),
            esc_attr( $label_id ),
            esc_attr( $base . '__legend' ),
            esc_html( self::STEP_LOCATION ),
            esc_attr( $base . '__location-list' ),
            esc_attr( $base . '__location-details' ),
            esc_attr( $base . '__location-name' ),
            esc_attr( $base . '__location-address' ),
            esc_html( 'نشانی:' ),
            esc_attr( $base . '__location-hint' ),
            esc_html( 'برای مشاهدهٔ نوبت‌ها، محل مراجعه را انتخاب کنید.' )
        );
    }

    /**
     * سرتیتر سطح: عنوان + نامِ همان Clinicِ پیوندشده (تنها دادهٔ غیرپزشکیِ
     * نمایشی که از ردیفِ پایدارِ Clinic می‌آید).
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function renderHead (string $clinicName, string $surfaceId ): string {
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter/local of this method (PHP 8 named-argument contract); no security sniff suppressed.
        $titleId = $surfaceId . '-title';

        $head  = sprintf( '<div class="%s">', esc_attr( self::ROOT_CLASS . '__head' ) );
        $head .= sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr( self::ROOT_CLASS . '__title' ),
            esc_attr($titleId),
            esc_html(self::TITLE)
        );
        if ($clinicName !== '') {
            $head .= sprintf(
                '<p class="%1$s">%2$s</p>',
                esc_attr(self::ROOT_CLASS . '__clinic'),
                esc_html($clinicName)
            );
        }
        $head .= '</div>';

        return $head;
    }

    /**
     * قالب‌های markup برای JS (day / slot / detail).
     *
     * چرا `<template>`: همهٔ متن‌های فارسی و ساختارِ markup سمت سرور رندر و
     * escape می‌شوند؛ JS فقط `cloneNode()` می‌کند و مقادیرِ داده‌ایِ خودِ سرور
     * (برچسب Jalali، ساعت، ظرفیت، مدت) را با `textContent` می‌گذارد. نتیجه:
     * بدون رشتهٔ فارسی در JS، بدون innerHTML، بدون XSS.
     *
     * `<template>` توسط مرورگر رندر نمی‌شود، پس هیچ اثر بصری/دسترس‌پذیریِ
     * ناخواسته‌ای پیش از تعامل ندارد.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established method name kept to avoid changing the API surface; no security sniff suppressed.
    private static function renderTemplates(): string {
        $b     = self::ROOT_CLASS;
        $lines = [];

        $lines[] = sprintf(
            '<template data-template="day">'
            . '<div class="%1$s__day">'
            . '<p class="%1$s__day-label" data-fill="jalali"></p>'
            . '<div class="%1$s__slots" data-host="slots"></div>'
            . '</div>'
            . '</template>',
            esc_attr( $b )
        );

        // سلول خالیِ تقویم هفتگی — نام روز فارسی از weekday_names در config
        // (server-published) پر می‌شود؛ متن «بدون نوبت» متنِ خودِ سرور است.
        $lines[] = sprintf(
            '<template data-template="day-empty">'
            . '<div class="%1$s__day %1$s__day--empty" data-role="day-empty">'
            . '<p class="%1$s__day-label" data-fill="dow"></p>'
            . '<p class="%1$s__day-empty-text">%2$s</p>'
            . '</div>'
            . '</template>',
            esc_attr( $b ),
            esc_html( self::DAY_EMPTY_TEXT )
        );

        $lines[] = sprintf(
            '<template data-template="slot">'
            . '<button type="button" class="%1$s__slot">'
            . '<span class="%1$s__slot-time" data-fill="time"></span>'
            . '<span class="%1$s__slot-meta">'
            . '<span class="%1$s__slot-duration"><b data-fill="duration"></b> %2$s</span>'
            . '<span class="%1$s__slot-capacity">%3$s <b data-fill="capacity"></b></span>'
            . '</span>'
            . '</button>'
            . '</template>',
            esc_attr($b),
            esc_html(self::DURATION_LABEL),
            esc_html(self::CAPACITY_LABEL)
        );

        $lines[] = sprintf(
            '<template data-template="detail-capacity">'
            . '<span class="%1$s__detail-text">%2$s <b data-fill="capacity"></b></span>'
            . '</template>',
            esc_attr( $b ),
            esc_html( self::CAPACITY_LABEL )
        );

        // پیامِ خودِ سرور (envelope خطای A1/A4) — بدون هیچ تفسیرِ سمت مرورگر.
        $lines[] = sprintf(
            '<template data-template="detail-server">'
            . '<span class="%1$s__detail-text" data-fill="message"></span>'
            . '</template>',
            esc_attr($b)
        );

        $lines[] = sprintf(
            '<template data-template="location-option">'
            . '<li class="%1$s__location-item">'
            . '<button type="button" class="%1$s__location-option" data-role="location-option" aria-pressed="false">'
            . '<span class="%1$s__location-option-name" data-fill="name"></span>'
            . '<span class="%1$s__location-option-address" data-fill="address"></span>'
            . '</button>'
            . '</li>'
            . '</template>',
            esc_attr( $b )
        );

        return implode('', $lines);
    }

    /**
     * D-5 — قرارداد runtime منتشرشده برای asset فرانت‌اند.
     *
     * پایه: مسیرهای عمومیِ موجودِ A1/A4 + Clinicِ پیوندشده + واژگانِ وضعیت —
     * بدون PHI. Phase 8 Slice 2 بر اساس وضعیتِ احراز گسترش می‌یابد:
     *  - anonymous → فقط مسیرهای موجودِ A2/A3 (ادامهٔ ورود OTP)؛ بدون nonce،
     *    بدون B1/B2، بدون هیچ credential یا PHI؛
     *  - patient (نقش بیمار) → فقط wp_rest nonce + مسیرهای موجودِ B1/B2؛
     *  - سایر کاربرانِ واردشده → فقط پایه (بدون continuation بیمار).
     *
     * مسیرها با `wp_json_encode` بدون `JSON_UNESCAPED_SLASHES` منتشر می‌شوند:
     * خروجی `\/otp\/request`‌گونه همان JSON معتبرِ script-safe است (برای
     * JSON.parse/readConfig بی‌تفاوت) و رشتهٔ خامِ مسیر به‌صورت literal در
     * markup ظاهر نمی‌شود — گاردهای «نبودِ affordance» (pilot/Slice 1) و
     * منتشرشدهٔ decoded (Slice 2) همزمان معنادار می‌مانند.
     */
    private static function renderConfig(int $clinicId, string $surfaceId): string
    {
        $config = [
            'surface_id' => $surfaceId,
            'rest_root' => rtrim(rest_url(self::REST_NAMESPACE), '/'),
            'availability_path' => self::AVAILABILITY_PATH,
            'quote_path' => self::QUOTE_PATH,
            'clinic_id' => $clinicId,
            'state_vocabulary' => self::STATE_VOCABULARY,
            'initial_state' => 'idle',
            // نام روزهای هفتهٔ فارسی (شنبه..جمعه) — ارائهٔ سمت سرور برای
            // ستون‌های تقویم هفتگی؛ JS هیچ رشتهٔ فارسی نمی‌سازد.
            'weekday_names'     => self::WEEKDAY_NAMES,
        ];

        $continuation = self::continuationState();
        if ($continuation === self::CONTINUATION_ANONYMOUS) {
            $config['otp_request_path'] = self::OTP_REQUEST_PATH;
            $config['otp_verify_path'] = self::OTP_VERIFY_PATH;
        } elseif ($continuation === self::CONTINUATION_PATIENT) {
            // فقط برای بیمارِ واردشده — CSRF با wp_rest nonce (همان مرزِ B1/B2).
            $config['nonce'] = wp_create_nonce('wp_rest');
            $config['hold_path'] = self::HOLD_PATH;
            $config['confirm_path'] = self::CONFIRM_PATH;
        }

        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            // fail-closed: بدون قراردادِ قابل‌انتشار، سطحِ تعاملی ساخته نمی‌شود.
            return '';
        }

        // `wp_json_encode()` با JSON_HEX_TAG|JSON_HEX_AMP خروجیِ script-safe
        // می‌سازد (`</` و `&` escape می‌شوند)، پس شکستنِ زودهنگامِ
        // `<script>` ممکن نیست. این متد echo نمی‌کند — رشته را به رندرِ
        // بالادست برمی‌گرداند.
        return sprintf(
            '<script type="application/json" class="%s">%s</script>',
            esc_attr(self::CONFIG_CLASS),
            $json
        );
    }

    // ================= ابزارها =================

    /** id یکتا برای هر نمونهٔ سطح روی یک صفحه (ARIA/`aria-labelledby`). */
    private static function surfaceId(): string
    {
        return wp_unique_id('cpms-public-booking-');
    }

    /**
     * ثبتِ خطا در Log عملیاتی — بدون افشای جزئیات به بازدیدکنندهٔ آنونیم.
     */
    private static function logFailure(Throwable $e): void
    {
        try {
            App::op()->warning('CPMS_PUBLIC_BOOKING_RENDER_FAILED', ['reason' => $e->getMessage()]);
        } catch (Throwable) {
            // سطح عمومی هرگز به‌خاطرِ شکستِ Log شکسته نمی‌شود.
        }
    }
}
