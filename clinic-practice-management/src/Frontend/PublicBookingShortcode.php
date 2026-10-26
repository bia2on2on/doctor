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

use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Bootstrap\App;
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

    /**
     * Phase 8 Slice 4 — مقدارِ ویژهٔ attribute برای «حالتِ کشف» (Discovery).
     *
     * `[cpms_public_booking clinic_id="all"]` تنها مقدارِ غیرعددیِ مجاز است؛ هر
     * مقدارِ غیرعددیِ دیگر همان وضعیت بستهٔ قبلی را می‌دهد (fail-closed).
     */
    private const CLINIC_ALL = 'all';

    private const MODE_SINGLE = 'single';

    /** سقفِ سختِ پزشکانِ رندرشده در یک سطحِ عمومی ( bounded rendering ). */
    private const MAX_PUBLIC_DOCTORS = 200;
    private const MODE_DISCOVERY     = 'discovery';

    /** برچسب‌های حالت کشف ( همه server-side و escape‌شده ). */
    private const DISCOVERY_TITLE            = 'رزرو نوبت — انتخاب مرکز';
    private const STEP_CLINIC                = '۱. انتخاب مرکز درمانی';
    private const DISCOVERY_NO_CLINIC_NOTICE = 'در حال حاضر مرکز درمانی برای رزرو عمومی نوبت معرفی نشده است.';
    private const SEARCH_LABEL               = 'جست‌وجوی نام پزشک';
    private const SPECIALTY_LABEL            = 'فیلتر تخصص';
    private const SPECIALTY_ALL              = 'همهٔ تخصص‌ها';
    private const RESET_LABEL                = 'حذف فیلترها';
    private const NO_MATCH_NOTICE            = 'پزشکی با این نام یا تخصص در این مرکز پیدا نشد.';
    private const WEEK_PREV                  = 'هفتهٔ قبل';
    private const WEEK_NEXT                  = 'هفتهٔ بعد';
    private const WEEK_FIRST_AVAILABLE       = 'نزدیک‌ترین هفتهٔ دارای نوبت';
    private const WEEK_EMPTY_NOTICE          = 'در این هفته نوبت آزادی برای این پزشک ثبت نشده است.';
    private const TIMEZONE_PREFIX            = 'منطقهٔ زمانی مرکز:';
    private const WORKING_DAYS_PREFIX        = 'روزهای کاری:';
    private const NO_WORKING_DAYS            = 'بدون برنامهٔ هفتگی فعال';

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
            $request = self::parse_request( $atts, $tag );
            if ( $request === null ) {
                return self::renderClosed();
            }

            if ( $request['mode'] === self::MODE_DISCOVERY ) {
                return self::render_discovery();
            }

            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase local of this method.
            $clinic_id = (int) $request['clinic_id'];
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase local of this method.
            $clinic = self::findClinic( $clinic_id );
            if ($clinic === null) {
                return self::renderClosed();
            }

            $clinicians = self::activeClinicians( $clinic_id );
            if ($clinicians === []) {
                return self::renderEmpty( (int) $clinic_id, self::clinicName( $clinic ) );
            }

            return self::renderBrowse( (int) $clinic_id, self::clinicName( $clinic ), $clinicians );
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
    private static function parseClinicId(mixed $atts, string $tag): ?int
    {
        $parsed = is_array($atts) ? $atts : [];

        /** @var array<string, mixed> $normalized */
        $normalized = shortcode_atts([self::CLINIC_ATTR => ''], $parsed, $tag);

        $raw = $normalized[self::CLINIC_ATTR] ?? '';
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        // فقط رقم — یعنی «1.5»، «0x10»، «-3»، « 5abc » و ارقام غیرلاتین همه
        // نامعتبرند. این گیت عمداً از `is_numeric()` سخت‌گیرانه‌تر است تا
        // مقدارِ «تقریباً عددی» هرگز به جست‌وجوی DB نرسد.
        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            return null;
        }

        $clinic_id = (int) $trimmed;

        return $clinic_id > 0 ? $clinic_id : null;
    }

    /**
     * Phase 8 Slice 4 — تجزیهٔ درخواستِ سطح به «حالت».
     *
     * دو حالتِ ممکن و هیچ حالتِ سوم:
     *  - `single`    → `clinic_id` عددیِ مثبت (رفتارِ تثبیت‌شدهٔ Phase 8 Slice 1)؛
     *  - `discovery` → تنها مقدارِ متنیِ مجاز `all` (حالتِ کشفِ جدید).
     *
     * هر چیزِ دیگر (نبودِ attribute، رشتهٔ خالی، عددِ صفر/منفی، مقدارِ غیرعددیِ
     * غیر از `all`، مقدارِ مخلوط مانند «all 5») `null` می‌دهد ⇒ وضعیت بسته.
     * مقایسه case-insensitive است تا «ALL» هم همان حالت باشد، ولی هیچ الگوی
     * دیگری پذیرفته نمی‌شود.
     *
     * @param mixed $atts
     * @return array{mode: string, clinic_id?: int}|null
     */
    private static function parse_request( mixed $atts, string $tag ): ?array {
        // phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established parameter style of this file's Clinic parsing helpers.
        $parsed = is_array( $atts ) ? $atts : [];
        /** @var array<string, mixed> $normalized */
        $normalized = shortcode_atts( [self::CLINIC_ATTR => ''], $parsed, $tag );
        $raw        = $normalized[self::CLINIC_ATTR] ?? '';

        if ( is_string( $raw ) && strtolower( trim( $raw ) ) === self::CLINIC_ALL ) {
            return ['mode' => self::MODE_DISCOVERY];
        }

        $clinic_id = self::parseClinicId( $atts, $tag );
        if ( $clinic_id === null ) {
            return null;
        }

        // phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        return ['mode' => self::MODE_SINGLE, 'clinic_id' => $clinic_id];
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
    private static function findClinic( int $clinic_id ): ?array
    {
        try {
            $row = App::clinicRepository()->find( $clinic_id );
        } catch (Throwable $e) {
            self::logFailure($e);

            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        // Clinic lifecycle policy - public surface: a suspended Clinic advertises no
        // clinicians and no bookable slots, so the shortcode renders its established
        // "not available" state instead of a browse list. Existing appointment access
        // happens on authenticated surfaces and is unaffected.
        // Narrow naming ignore for the established camelCase parameter of this method;
        // no security sniff is suppressed by it.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $requested_clinic_id = (int) $clinic_id;
        $clinic_status       = App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $requested_clinic_id ]
        );
        if ( $clinic_status !== null && (string) $clinic_status === 'suspended' ) {
            return null;
        }

        // Defence in depth: ردیف باید همان Clinicِ درخواستی باشد.
        return (int) ( $row['id'] ?? 0 ) === $clinic_id ? $row : null;
    }

    /**
     * پزشکانِ **فعالِ** همان Clinic — به‌ترتیب نام، بدون هیچ Clinic دیگری.
     *
     * `listAll($clinic_id, false)` خودش `is_active = 1` را در WHERE می‌گذارد؛
     * بررسیِ مجددِ `clinic_id`/`is_active` در این‌جا defence in depth است تا
     * حتی در صورت تغییرِ آن Repository، این سطح عمومی هرگز پزشکِ Clinicِ دیگر
     * یا پزشکِ غیرفعال را منتشر نکند.
     *
     * @return list<array{id: int, name: string}>
     */
    private static function activeClinicians( int $clinic_id ): array
    {
        try {
            // Phase 8 Slice 4 — participation-aware: an ACTIVE clinician whose
            // HOME Clinic differs but who holds an ACTIVE durable membership in
            // THIS Clinic is bookable here (the same rule ScheduleService and
            // MembershipRepository::clinician_participates_in enforce). Home
            // Clinic rows are included by the same query; inactivation, empty
            // names and foreign-only clinicians are excluded inside it.
            $rows = ( new PublicClinicDirectory() )->clinicians_for_clinic( $clinic_id );
        } catch (Throwable $e) {
            self::logFailure($e);

            return [];
        }

        $clinicians = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim( (string)    ( $row['name'] ?? '' ) );
            if ( $id <= 0 || $name === '' ) {
                // پزشکِ بدون نامِ قابل‌نمایش روی سطح عمومی قابل ارائه نیست
                // (دکمهٔ بی‌نام، UI غیرقابل‌استفاده می‌سازد).
                continue;
            }
            $weekdays = [];
            foreach ( (array)    ( $row['weekdays'] ?? [] ) as $day ) {
                $weekdays[] = (int) $day;
            }
            $clinicians[] = [
                'id'        => $id,
                'name'      => $name,
                'specialty' => trim( (string)    ( $row['specialty'] ?? '' ) ),
                'weekdays'  => $weekdays,
        ];
        }

        return $clinicians;
    }

    /**
     * @param array<string, mixed> $clinic
     */
    private static function clinicName(array $clinic): string
    {
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
    private static function renderClosed(): string
    {
        PublicBookingAssets::enqueue();

        $surface_id = self::surfaceId();
        $lines = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--closed" dir="rtl" lang="fa" data-state="%2$s" id="%3$s">',
            esc_attr(self::ROOT_CLASS),
            esc_attr(self::STATE_ERROR),
            esc_attr( $surface_id )
        );
        $lines[] = sprintf(
            '<p class="%s" role="alert">%s</p>',
            esc_attr(self::ROOT_CLASS . '__notice'),
            esc_html(self::CLOSED_NOTICE)
        );
        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * وضعیت `empty` — Clinic **معتبر** است ولی پزشک فعالی برای نوبت‌دهی ندارد.
     *
     * این وضعیت عمداً از `error` جداست: `error` یعنی «پیکربندیِ سطح خراب است»
     * و `empty` یعنی «پیکربندی درست است، چیزی برای رزرو نیست». در هر دو حالت
     * هیچ پزشکی منتشر نمی‌شود.
     */
    private static function renderEmpty( int $clinic_id, string $clinic_name ): string
    {
        PublicBookingAssets::enqueue();

        $surface_id = self::surfaceId();
        $lines = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--empty" dir="rtl" lang="fa" data-clinic-id="%2$d" data-state="%3$s" id="%4$s">',
            esc_attr(self::ROOT_CLASS),
            $clinic_id,
            esc_attr(self::STATE_EMPTY),
            esc_attr( $surface_id )
        );
        $lines[] = self::renderHead( $clinic_name, $surface_id );
        $lines[] = sprintf(
            '<p class="%s" role="status">%s</p>',
            esc_attr(self::ROOT_CLASS . '__notice'),
            esc_html(self::NO_CLINICIAN_NOTICE)
        );
        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * وضعیت `bookable` — سطحِ کاملِ مرور: لیست پزشکان (server-rendered) +
     * panel مرورِ نوبت + قالب‌ها + قراردادِ runtime.
     *
     * @param list<array{id: int, name: string}> $clinicians
     */
    private static function renderBrowse( int $clinic_id, string $clinic_name, array $clinicians ): string {
        return self::render_surface( $clinic_id, $clinic_name, $clinicians, null );
    }

    /**
     * Phase 8 Slice 4 — سطحِ کشف: یک لیستِ server-rendered و bounded از
     * Clinicهایی که **صریحاً** برای نمایش عمومی opt-in کرده‌اند، همراه با
     * پزشکانِ واجدِ شرایطِ هر Clinic.
     *
     * اگر هیچ Clinicِ واجدی وجود نداشته باشد، سطح به وضعیت «خالی» می‌رسد —
     * نه خطا: پیکربندی معتبر است، چیزی برای معرفی نیست. هیچ‌گاه «همهٔ
     * Clinicهای فعال» به‌طور پیش‌فرض منتشر نمی‌شود.
     */
    private static function render_discovery(): string {
        $directory = new PublicClinicDirectory();
        $listing   = $directory->listed_clinics();

        if ( $listing['clinics'] === [] ) {
            return self::render_discovery_empty();
        }

        $primary = $listing['clinics'][0];

        return self::render_surface(
            (int) $primary['id'],
            '',
            $primary['clinicians'],
            [
                'clinics'     => $listing['clinics'],
                'specialties' => $listing['specialties'],
                'timezones'   => array_column( $listing['clinics'], 'timezone', 'id' ),
        ]
        );
    }

    /**
     * وضعیتِ «خالی» حالتِ کشف — Clinicهای opt-in‌شده‌ای برای معرفی نیست.
     */
    private static function render_discovery_empty(): string
    {
        PublicBookingAssets::enqueue();

        $surface_id = self::surfaceId();

        return sprintf(
            '<div class="%1$s %1$s--discovery %1$s--empty" dir="rtl" lang="fa" data-mode="%2$s" data-state="%3$s" id="%4$s">'
            . '<p class="%5$s" role="status">%6$s</p>'
            . '</div>',
            esc_attr( self::ROOT_CLASS ),
            esc_attr( self::MODE_DISCOVERY ),
            esc_attr( self::STATE_EMPTY ),
            esc_attr( $surface_id ),
            esc_attr( self::ROOT_CLASS . '__notice' ),
            esc_html( self::DISCOVERY_NO_CLINIC_NOTICE )
        );
    }

    /**
     * رندرِ مشترکِ سطح (حالت تک‌Clinic و حالت کشف) — یک موتورِ رزرو، دو
     * پیکربندی. مسیرهای A1/A2/A3/A4/B1/B2 هر دو حالت یکی است؛ تفاوت فقط در
     * «چگونه Clinic و پزشک انتخاب می‌شوند» است.
     *
     * @param list<array{id:int,name:string,specialty?:string,weekdays?:list<int>}> $clinicians
     * @param array{clinics: list<array<string,mixed>>, specialties: list<string>, timezones: array<int,string>}|null $discovery
     */
    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, Squiz.Commenting.FunctionComment.IncorrectParamVarName -- established compact render kernel.
    private static function render_surface( int $clinic_id, string $clinic_name, array $clinicians, ?array $discovery ): string {
        PublicBookingAssets::enqueue();

        $surface_id   = self::surfaceId();
        $is_discovery = $discovery !== null;
        $clinic_ids   = [];
        if ( $is_discovery ) {
            foreach ( $discovery['clinics'] as $row ) {
                $clinic_ids[] = (int) $row['id'];
            }
        } else {
            $clinic_ids[] = $clinic_id;
        }

        $lines   = [];
        $lines[] = sprintf(
            '<div class="%1$s%2$s" dir="rtl" lang="fa" data-clinic-id="%3$d" data-mode="%4$s" data-state="%5$s" id="%6$s">',
            esc_attr(self::ROOT_CLASS),
            $is_discovery ? ' ' . esc_attr( self::ROOT_CLASS . '--discovery' ) : '',
            $is_discovery ? 0 : $clinic_id,
            esc_attr( $is_discovery ? self::MODE_DISCOVERY : self::MODE_SINGLE ),
            esc_attr(self::STATE_BOOKABLE),
            esc_attr( $surface_id )
        );

        if ( $is_discovery ) {
            $lines[] = self::render_discovery_head( $discovery['clinics'], $surface_id );
        } else {
            $lines[] = self::renderHead( $clinic_name, $surface_id );
        }

        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__body'));

        // ---- گام ۱: پزشکان (server-rendered؛ هیچ endpoint عمومیِ لیست پزشک) ----
        $legend_id        = $surface_id . '-clinicians';
        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__clinicians'));
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__legend'),
            esc_attr( $legend_id ),
            esc_html(self::STEP_CLINICIAN)
        );
        $lines[]          = self::render_filters( $surface_id, $discovery['specialties'] ?? [] );
        $lines[] = sprintf(
            '<ul class="%1$s" role="list" aria-labelledby="%2$s" data-role="clinicians">',
            esc_attr(self::ROOT_CLASS . '__clinician-list'),
            esc_attr( $legend_id )
        );
        $rendered_doctors = 0;
        foreach ( self::doctor_rows( $clinicians, $discovery, $clinic_id ) as $row ) {
            if ( $rendered_doctors >= self::MAX_PUBLIC_DOCTORS ) {
                // Bounded page: the public directory never renders an unbounded list.
                break;
            }
            $lines[] = self::render_clinician_item( $row, (int)   $row['clinic_id'] );
            ++$rendered_doctors;
        }
        $lines[] = '</ul>';
        $lines[] = sprintf(
            '<p class="%s" data-role="filter-empty" role="status" hidden>%s</p>',
            esc_attr( self::ROOT_CLASS . '__notice' ),
            esc_html( self::NO_MATCH_NOTICE )
        );
        $lines[] = '</div>';

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $lines[] = self::render_location_picker( (string) $surface_id );

        // ---- گام ۳: تقویم هفتگی Jalali (منبعِ حقیقت: A1 موجود) ----
        $panel_legend_id = $surface_id . '-calendar';
        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__calendar'));
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__legend'),
            esc_attr( $panel_legend_id ),
            esc_html(self::STEP_SLOT)
        );
        $lines[]         = sprintf(
            '<p class="%1$s" data-role="clinic-timezone" data-label="%2$s" dir="auto" hidden></p>',
            esc_attr( self::ROOT_CLASS . '__meta' ),
            esc_attr( self::TIMEZONE_PREFIX )
        );
        $lines[]         = self::render_week_chrome( $panel_legend_id );
        $lines[] = sprintf(
            '<div class="%1$s" data-role="panel" data-state="idle" role="group" aria-labelledby="%2$s" aria-busy="false">',
            esc_attr(self::ROOT_CLASS . '__panel'),
            esc_attr( $panel_legend_id )
        );

        $lines[] = sprintf(
            '<div class="%s" role="status" aria-live="polite">',
            esc_attr(self::ROOT_CLASS . '__status')
        );
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

        $lines[] = sprintf(
            '<div class="%s" data-role="days"></div>',
            esc_attr(self::ROOT_CLASS . '__days')
        );
        $lines[] = '</div>'; // panel
        $lines[] = self::renderTemplates();
        $lines[] = '</div>'; // calendar

        // ---- Phase 8 Slice 2/3: ادامهٔ احراز/رزرو — وابسته به وضعیت احراز ----
        $continuation = self::continuationState();
        if ($continuation === self::CONTINUATION_ANONYMOUS) {
            $lines[] = self::renderAuthContinuation( $surface_id );
        } elseif ($continuation === self::CONTINUATION_PATIENT) {
            $lines[] = self::render_patient_continuation( $surface_id, $clinic_ids );
        }

        $lines[] = '</div>'; // body

        $lines[] = self::render_config(
            $is_discovery ? 0 : $clinic_id,
            $surface_id,
            $is_discovery ? $discovery : null
        );
        $lines[] = '</div>'; // root

        return implode('', $lines);
    }

    /**
     * ردیف‌های پزشک با Clinicِ صاحبِ هر ردیف.
     *
     * در حالت کشف، پزشکانِ همهٔ Clinicهای opt-in‌شده رندر می‌شوند و JS فقط
     * گروهِ Clinicِ انتخاب‌شده را نشان می‌دهد؛ در حالت تک‌Clinic همه به همان
     * Clinic تعلق دارند.
     *
     * @param list<array<string, mixed>> $clinicians
     * @param array{clinics: list<array<string,mixed>>, specialties: list<string>}|null $discovery
     * @return list<array<string, mixed>>
     */
    private static function doctor_rows( array $clinicians, ?array $discovery, int $clinic_id ): array {
        $rows = [];
        if ( $discovery === null ) {
            foreach ( $clinicians as $clinician ) {
                $rows[] = ['clinic_id' => $clinic_id] + $clinician;
            }

            return $rows;
        }

        foreach ( $discovery['clinics'] as $clinic ) {
            $owner = (int)   ( $clinic['id'] ?? 0 );
            if ( $owner <= 0 ) {
                continue;
            }
            foreach ( (array)    ( $clinic['clinicians'] ?? [] ) as $clinician ) {
                $rows[] = ['clinic_id' => $owner] + (array) $clinician;
            }
        }

        return $rows;
    }

    /**
     * یک آیتمِ پزشک — با تمام فیلدهای لازم برای فیلترِ سمت مرورگر.
     *
     * `data-search` شامل نامِ **نرمال‌شده** (PHP-side) است، پس مرورگر فقط
     * عبارتِ کاربر را نرمال می‌کند و روی دادهٔ خودِ سرور تطبیق می‌دهد؛ هیچ
     * محتوایی در JS تولید نمی‌شود.
     *
     * @param array{id:int,name:string,specialty?:string,weekdays?:list<int>} $clinician
     */
    private static function render_clinician_item( array $clinician, int $clinic_id ): string {
        $id        = (int)   ( $clinician['id'] ?? 0 );
        $name      = trim( (string)    ( $clinician['name'] ?? '' ) );
        $specialty = trim( (string)    ( $clinician['specialty'] ?? '' ) );
        $weekdays  = array_map( 'intval', (array)     ( $clinician['weekdays'] ?? [] ) );

        $lines   = [];
        $lines[] = sprintf(
            '<li class="%1$s %2$s" data-clinic-id="%3$d" data-clinician-id="%4$d" data-specialty="%5$s" data-search="%6$s">',
            esc_attr( self::ROOT_CLASS . '__clinician-item' ),
            esc_attr( self::ROOT_CLASS . '__doctor' ),
            $clinic_id,
            $id,
            esc_attr( $specialty ),
            esc_attr( self::normalize_search_text( $name ) )
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-clinician-id="%2$d" data-clinic-id="%3$d" aria-pressed="false">'
            . '<span class="%4$s">%5$s</span>%6$s%7$s'
            . '</button>',
            esc_attr( self::CLINICIAN_CLASS ),
            $id,
            $clinic_id,
            esc_attr( self::ROOT_CLASS . '__clinician-name' ),
            esc_html( $name ),
            $specialty === '' ? '' : sprintf(
                '<span class="%1$s">%2$s</span>',
                esc_attr( self::ROOT_CLASS . '__clinician-specialty' ),
                esc_html( $specialty )
            ),
            sprintf(
                '<span class="%1$s" data-role="clinician-days">%2$s</span>',
                esc_attr( self::ROOT_CLASS . '__clinician-days' ),
                esc_html( self::weekday_summary( $weekdays ) )
            )
        );
        $lines[] = '</li>';

        return implode( '', $lines );
    }

    /**
     * خلاصهٔ روزهای کاری هفتگی (برچسبِ فارسی، بدون هیچ دلیلی از استثناها).
     *
     * @param list<int> $weekdays ISO-8601 weekday numbers (0..6 as stored)
     */
    private static function weekday_summary( array $weekdays ): string {
        $labels = [];
        foreach ( $weekdays as $day ) {
            // cpms_schedule.day_of_week is 0..6 (0 = Sunday) — map to a stable
            // Persian label without assuming where the week starts.
            $label = self::weekday_label_from_db( (int)  $day );
            if ( $label !== '' ) {
                $labels[ $label ] = $label;
            }
        }

        if ( $labels === [] ) {
            return self::WORKING_DAYS_PREFIX . ' ' . self::NO_WORKING_DAYS;
        }

        return self::WORKING_DAYS_PREFIX . ' ' . implode( '، ', array_values( $labels ) );
    }

    /**
     * Persian label for the `day_of_week` encoding used by `cpms_schedule`.
     *
     * The schema stores a TINYINT weekday and the admin UI presents the Persian
     * week starting at Saturday; the exact ordinal convention is legacy. To stay
     * truthful on a public surface we never guess: the set is published as the
     * ordered list of *persisted* days and only the label of a day index is
     * resolved through this single table.
     */
    private static function weekday_label_from_db( int $day_of_week ): string {
        $map = [
            0 => 'یک‌شنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه',
    ];

        return $map[ $day_of_week ] ?? '';
    }

    /**
     * نرمال‌سازی متنِ جست‌وجو (فارسی/عربی) — یک تابع، دو مصرف‌کننده.
     *
     * چرا server-side هم لازم است: مقدارِ `data-search` که به مرورگر می‌رود
     * باید همان شکلِ نرمال‌شده‌ای باشد که JS روی عبارتِ کاربر اعمال می‌کند؛
     * در غیر این صورت «ي/ك»، نیم‌فاصله، اعراب و ارقامِ فارسی/عربی باعث
     * نتیجهٔ نادرست می‌شوند. JS فقط عبارتِ کاربر را نرمال می‌کند، نه داده را.
     */
    public static function normalize_search_text( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        // Arabic/Persian letter unification.
        $value = str_replace(
            ['ي', 'ى', 'ئ', 'ك', 'ة', 'أ', 'إ', 'آ', 'ؤ', 'ٱ'],
            ['ی', 'ی', 'ی', 'ک', 'ه', 'ا', 'ا', 'ا', 'و', 'ا'],
            $value
        );
        // Arabic-Indic and Persian digits -> ASCII digits.
        $value = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
        // Drop diacritics (harakat), tatweel and zero-width joiners (ZWNJ/ZWJ).
        $value = (string)   preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200B}-\x{200F}]/u', '', $value );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    /**
     * @param list<array<string, mixed>> $clinics
     */
    private static function render_discovery_head( array $clinics, string $surface_id ): string {
        $title_id  = $surface_id . '-title';
        $select_id = $surface_id . '-clinic';
        $base      = self::ROOT_CLASS;

        $lines   = [];
        $lines[] = sprintf( '<div class="%s">', esc_attr( $base . '__head' ) );
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr( $base . '__title' ),
            esc_attr( $title_id ),
            esc_html( self::DISCOVERY_TITLE )
        );
        $lines[] = sprintf(
            '<div class="%1$s">',
            esc_attr( $base . '__clinic-picker' )
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $base . '__label' ),
            esc_attr( $select_id ),
            esc_html( self::STEP_CLINIC )
        );
        $lines[] = sprintf(
            '<select class="%1$s" id="%2$s" data-role="clinic-select">',
            esc_attr( $base . '__select' ),
            esc_attr( $select_id )
        );
        foreach ( $clinics as $clinic ) {
            $lines[] = sprintf(
                '<option value="%1$d" data-timezone="%2$s">%3$s</option>',
                (int) $clinic['id'],
                esc_attr( (string)    ( $clinic['timezone'] ?? '' ) ),
                esc_html( (string)  $clinic['name'] )
            );
        }
        $lines[] = '</select>';
        $lines[] = '</div>';
        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * کنترل‌های فیلتر (نامِ پزشک + تخصص) — server-rendered، accessible، و
     * بدون هیچ منطقِ bookability.
     *
     * @param list<string> $specialties
     */
    private static function render_filters( string $surface_id, array $specialties ): string {
        $base         = self::ROOT_CLASS;
        $search_id    = $surface_id . '-doctor-search';
        $specialty_id = $surface_id . '-specialty';

        $lines   = [];
        $lines[] = sprintf( '<div class="%s">', esc_attr( $base . '__filters' ) );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr( $base . '__label' ),
            esc_attr( $search_id ),
            esc_html( self::SEARCH_LABEL )
        );
        $lines[] = sprintf(
            '<input type="search" class="%1$s" id="%2$s" data-role="doctor-search" autocomplete="off" spellcheck="false">',
            esc_attr( $base . '__input' ),
            esc_attr( $search_id )
        );

        if ( $specialties !== [] ) {
            $lines[] = sprintf(
                '<label class="%1$s" for="%2$s">%3$s</label>',
                esc_attr( $base . '__label' ),
                esc_attr( $specialty_id ),
                esc_html( self::SPECIALTY_LABEL )
            );
            $lines[] = sprintf(
                '<select class="%1$s" id="%2$s" data-role="specialty-filter">',
                esc_attr( $base . '__select' ),
                esc_attr( $specialty_id )
            );
            $lines[] = sprintf( '<option value="">%s</option>', esc_html( self::SPECIALTY_ALL ) );
            foreach ( $specialties as $specialty ) {
                $lines[] = sprintf(
                    '<option value="%1$s">%2$s</option>',
                    esc_attr( (string)  $specialty ),
                    esc_html( (string)  $specialty )
                );
            }
            $lines[] = '</select>';
        }

        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="doctor-reset">%2$s</button>',
            esc_attr( $base . '__link-btn' ),
            esc_html( self::RESET_LABEL )
        );
        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * کنترل‌های ناوبری هفته — اسکلتِ server-rendered؛ دادهٔ روزها از A1 و
     * تقویمِ منتشرشدهٔ سرور می‌آید (هیچ محاسبهٔ bookability در JS).
     */
    private static function render_week_chrome( string $legend_id ): string {
        $base     = self::ROOT_CLASS;
        $label_id = $legend_id . '-week';

        $lines   = [];
        $lines[] = sprintf(
            '<div class="%1$s" data-role="week" role="group" aria-labelledby="%2$s">',
            esc_attr( $base . '__week' ),
            esc_attr( $label_id )
        );
        $lines[] = sprintf(
            '<div class="%1$s">',
            esc_attr( $base . '__week-toolbar' )
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="prev-week" aria-label="%2$s">%3$s</button>',
            esc_attr( $base . '__week-btn' ),
            esc_attr( self::WEEK_PREV ),
            esc_html( self::WEEK_PREV )
        );
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s" data-role="week-label" aria-live="polite"></p>',
            esc_attr( $base . '__week-label' ),
            esc_attr( $label_id )
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="next-week" aria-label="%2$s">%3$s</button>',
            esc_attr( $base . '__week-btn' ),
            esc_attr( self::WEEK_NEXT ),
            esc_html( self::WEEK_NEXT )
        );
        $lines[] = '</div>';
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="first-available-week" hidden>%2$s</button>',
            esc_attr( $base . '__link-btn' ),
            esc_html( self::WEEK_FIRST_AVAILABLE )
        );
        $lines[] = sprintf(
            '<p class="%1$s" data-role="week-empty" role="status" hidden>%2$s</p>',
            esc_attr( $base . '__notice' ),
            esc_html( self::WEEK_EMPTY_NOTICE )
        );
        $lines[] = '</div>';

        return implode( '', $lines );
    }

    /**
     * وضعیتِ continuation این رندر — فقط از وضعیتِ احرازِ سمت سرور.
     *
     *  - anonymous: بازدیدکنندهٔ بدون session — گیرندهٔ چرخهٔ ورود OTP؛
     *  - patient: کاربرِ واردشده با نقش بیمار — گیرندهٔ nonce + B1/B2؛
     *  - none: سایر کاربرانِ واردشده (کارکنان/مدیر) — بدون continuation بیمار.
     */
    private static function continuationState(): string
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::CONTINUATION_ANONYMOUS;
        }

        $user = wp_get_current_user();
        if (in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true)) {
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
    private static function renderAuthContinuation( string $surface_id ): string
    {
        $b = self::ROOT_CLASS;
        $mobileInputId = $surface_id . '-otp-mobile';
        $codeInputId = $surface_id . '-otp-code';

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
            esc_attr($b . '__status')
        );

        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * Phase 8 Slice 2 — ادامهٔ Hold→Confirm برای بیمارِ واردشده.
     *
     * nonce و مسیرهای B1/B2 از قرارداد runtime می‌آیند (render_config)؛ این
     * بخش فقط اسکلتِ وضعیت‌ها را server-render می‌کند: شمارشِ معکوسِ Hold،
     * فرمِ نامِ بیمارِ جدید، پیشنهادهای نزدیک پس از باختِ race، رسیدِ نهایی
     * (کد رهگیری + تاریخ جلالی). انتخابِ قبلیِ غیر-PHI توسط JS باز-نشانی و
     * با B1 موجود ادامه می‌یابد (B6 resume عمداً استفاده نمی‌شود).
     */
    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- published camelCase parameter names of this method.
    private static function render_patient_continuation( string $surface_id, int|array $clinic_id ): string
    {
        // Phase 8 Slice 4 — discovery mode passes every listed Clinic so the
        // N>1 linked-patient chooser stays available per Clinic; single mode
        // keeps the established one-Clinic behaviour.
        $clinic_ids        = is_array( $clinic_id ) ? array_map( 'intval', $clinic_id ) : [ (int) $clinic_id ];
        $primary_clinic_id = $clinic_ids[0] ?? (int) $clinic_id;

        $b = self::ROOT_CLASS;
        $firstNameId = $surface_id . '-first-name';
        $lastNameId = $surface_id . '-last-name';

        // Phase 8 Slice 3 — chooser برای N>1 linked active Patients (LINKED-ONLY authority).
        // فقط برای N>1 رندر می‌شود؛ 0 یا 1 هیچ chooserی ندارد. هرگز decoy/same-mobile/cross-clinic/archived را نشان نمی‌دهد.
        // Render chooser OUTSIDE the hidden booking-continue box so it is visible immediately after login.
        $chooser_html     = '';
        $is_first_patient = false;
        try {
            $uid = get_current_user_id();
            if ( $uid > 0 ) {
                $db_chooser = \ClinicCore\Bootstrap\App::db();
                foreach ( $clinic_ids as $choice_clinic_id ) {
                    if ( $choice_clinic_id <= 0 ) {
                        continue;
                    }
                    $linked_patients = $db_chooser->fetchAll(
                        'SELECT p.id, p.first_name, p.last_name FROM ' . $db_chooser->table( 'cpms_patient_user_links' ) . ' l JOIN ' . $db_chooser->table( 'cpms_patients' ) . ' p ON p.id = l.patient_id WHERE l.wp_user_id = %d AND l.clinic_id = %d AND p.status = %s AND p.clinic_id = %d ORDER BY l.is_primary DESC, l.id ASC',
                        [ $uid, $choice_clinic_id, 'active', $choice_clinic_id ]
                    );
                    if ( ! is_array( $linked_patients ) ) {
                        continue;
                    }
                    if ( count( $linked_patients ) === 0 ) {
                        if ( $choice_clinic_id === $primary_clinic_id ) {
                            $is_first_patient = true;
                        }
                        continue;
                    }
                    if ( count( $linked_patients ) <= 1 ) {
                        continue;
                    }
                    $chooser_parts   = [];
                    $chooser_parts[] = sprintf(
                        '<div class="%s" data-role="patient-chooser" data-clinic-id="%d"%s>',
                        esc_attr( $b . '__chooser' ),
                        $choice_clinic_id,
                        count( $clinic_ids ) > 1 ? ' hidden' : ''
                    );
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
                    $chooser_html   .= implode( '', $chooser_parts );
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
            esc_attr($b . '__names-hint'),
            esc_html('برای ثبت این نوبت، نام و نام خانوادگی خود را وارد کنید:')
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr($b . '__label'),
            esc_attr($firstNameId),
            esc_html('نام')
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="patient-first-name" autocomplete="given-name">',
            esc_attr($b . '__input'),
            esc_attr($firstNameId)
        );
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr($b . '__label'),
            esc_attr($lastNameId),
            esc_html('نام خانوادگی')
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="patient-last-name" autocomplete="family-name">',
            esc_attr($b . '__input'),
            esc_attr($lastNameId)
        );
        $lines[] = '</div>';

        $reason_id = $surface_id . '-booking-reason'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established method signature.
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
    private static function renderHead( string $clinic_name, string $surface_id ): string
    {
        $titleId = $surface_id . '-title';

        $head = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__head'));
        $head .= sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__title'),
            esc_attr($titleId),
            esc_html(self::TITLE)
        );
        if ( $clinic_name !== '' ) {
            $head .= sprintf(
                '<p class="%1$s">%2$s</p>',
                esc_attr(self::ROOT_CLASS . '__clinic'),
                esc_html( $clinic_name )
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
    private static function renderTemplates(): string
    {
        $b = self::ROOT_CLASS;
        $lines = [];

        $lines[] = sprintf(
            '<template data-template="day">'
            . '<div class="%1$s__day">'
            . '<p class="%1$s__day-label" data-fill="jalali"></p>'
            . '<div class="%1$s__slots" data-host="slots"></div>'
            . '</div>'
            . '</template>',
            esc_attr($b)
        );

        // Phase 8 Slice 4 — روزِ تقویم هفتگی: سرتیتر (روزِ هفته + تاریخ جلالی)
        // + میزبانِ نوبت‌ها. وقتی هفتهٔ جاری نوبتی ندارد، همین قالب با
        // `data-empty="true"` رندر می‌شود تا روزِ خالی هم دیده و اعلام شود.
        $lines[] = sprintf(
            '<template data-template="week-day">'
            . '<div class="%1$s__day" data-role="week-day">'
            . '<p class="%1$s__day-label"><span data-fill="weekday"></span> <span data-fill="jalali"></span></p>'
            . '<div class="%1$s__slots" data-host="slots"></div>'
            . '</div>'
            . '</template>',
            esc_attr( $b )
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
            esc_attr($b),
            esc_html(self::CAPACITY_LABEL)
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
    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- published camelCase parameter names of this method.
    private static function render_config( int $clinic_id, string $surface_id, ?array $discovery = null ): string
    {
        // phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- published method contract keeps its established camelCase parameters.
        $config = [
            'surface_id' => $surface_id,
            'mode'              => $discovery === null ? self::MODE_SINGLE : self::MODE_DISCOVERY,
            // Phase 8 Slice 4 — presentation-only Jalali week scaffold. The
            // server computes every label (same Jalali implementation as A1);
            // JS only selects a seven-day window and never decides bookability.
            'calendar'          => self::calendar_scaffold( $clinic_id, $discovery ),
            'week_length'       => 7,
            'rest_root' => rtrim(rest_url(self::REST_NAMESPACE), '/'),
            'availability_path' => self::AVAILABILITY_PATH,
            'quote_path' => self::QUOTE_PATH,
            'clinic_id' => $clinic_id,
            'state_vocabulary' => self::STATE_VOCABULARY,
            'initial_state' => 'idle',
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
        // phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
    }

    // ================= ابزارها =================

    /**
     * Phase 8 Slice 4 — اسکلتِ تقویمِ هفتگی (منتقد: فقط Presentation).
     *
     * هر ردیف `{date, jalali, weekday}` است و **سرور** آن را با همان پیاده‌سازیِ
     * Jalali می‌سازد که A1 استفاده می‌کند. JS هیچ تاریخ‌شمسی‌ای محاسبه نمی‌کند و
     * هیچ bookabilityای نمی‌سازد؛ فقط پنجرهٔ هفت‌روزه را روی همین فهرست
     * جابه‌جا می‌کند و نوبت‌های A1 را با `date` تطبیق می‌دهد.
     *
     * بازه: از «امروزِ محلیِ زودترین Clinic» تا افقِ بیشترین
     * `booking.max_future_days` (کران‌دار ۷..۶۲ روز) — bounded و قطعی.
     *
     * @param array{clinics?: list<array<string,mixed>>}|null $discovery
     * @return list<array{date:string,jalali:string,weekday:string}>
     */
    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established camelCase parameter of this method.
    private static function calendar_scaffold( int $clinic_id, ?array $discovery ): array {
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- same established local.
        $timezones  = [];
        $clinic_ids = [];
        if ( $discovery !== null ) {
            foreach ( (array)    ( $discovery['clinics'] ?? [] ) as $clinic ) {
                $id = (int)   ( $clinic['id'] ?? 0 );
                if ( $id > 0 ) {
                    $clinic_ids[] = $id;
                    $timezones[]  = (string)   ( $clinic['timezone'] ?? '' );
                }
            }
        } elseif ( $clinic_id > 0 ) {
            $clinic_ids[] = $clinic_id;
        }

        $start   = null;
        $horizon = 7;
        foreach ( $clinic_ids as $index => $id ) {
            try {
                $timezone = ( $timezones[ $index ] ?? '' ) !== '' ? (string)   $timezones[ $index ] : 'Asia/Tehran';
                $today    = new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) );
                if ( $start === null || $today->format( 'Y-m-d' ) < $start ) {
                    $start = $today->format( 'Y-m-d' );
                }
            } catch ( Throwable ) {
                $start = $start ?? gmdate( 'Y-m-d' );
            }
            try {
                $days    = (int)     App::settingsFactory()->forClinic( $id )->get( 'booking.max_future_days', 60 );
                $horizon = max( $horizon, $days + 2 );
            } catch ( Throwable ) {
                // The row is skipped; the caller keeps its own fallback.
                // Unreadable policy never widens the scaffold.
            }
        }
        if ( $start === null ) {
            $start = gmdate( 'Y-m-d' );
        }
        $horizon = max( 7, min( 62, $horizon ) );

        $calendar = [];
        try {
            $cursor = new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) );
        } catch ( Throwable ) {
            return [];
        }

        for ( $i       = 0; $i < $horizon; $i++ ) {
            $date = $cursor->format( 'Y-m-d' );
            $calendar[] = [
                'date'  => $date,
                'jalali' => Jalali::formatYmd( $date ),
                'weekday' => self::weekday_label_from_db( (int) $cursor->format( 'w' ) ),
        ];
            $cursor = $cursor->modify( '+1 day' );
        }

        return $calendar;
    }

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
