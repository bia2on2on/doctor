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
    private const STEP_SLOT = '۲. انتخاب روز و ساعت';
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

        $clinicId = (int) $trimmed;

        return $clinicId > 0 ? $clinicId : null;
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
    private static function findClinic(int $clinicId): ?array
    {
        try {
            $row = App::clinicRepository()->find($clinicId);
        } catch (Throwable $e) {
            self::logFailure($e);

            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        // Defence in depth: ردیف باید همان Clinicِ درخواستی باشد.
        return (int) ($row['id'] ?? 0) === $clinicId ? $row : null;
    }

    /**
     * پزشکانِ **فعالِ** همان Clinic — به‌ترتیب نام، بدون هیچ Clinic دیگری.
     *
     * `listAll($clinicId, false)` خودش `is_active = 1` را در WHERE می‌گذارد؛
     * بررسیِ مجددِ `clinic_id`/`is_active` در این‌جا defence in depth است تا
     * حتی در صورت تغییرِ آن Repository، این سطح عمومی هرگز پزشکِ Clinicِ دیگر
     * یا پزشکِ غیرفعال را منتشر نکند.
     *
     * @return list<array{id: int, name: string}>
     */
    private static function activeClinicians(int $clinicId): array
    {
        try {
            $rows = App::clinicianRepository()->listAll($clinicId, false);
        } catch (Throwable $e) {
            self::logFailure($e);

            return [];
        }

        $clinicians = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ((int) ($row['clinic_id'] ?? 0) !== $clinicId) {
                continue;
            }
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                continue;
            }

            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name === '') {
                // پزشکِ بدون نامِ قابل‌نمایش روی سطح عمومی قابل ارائه نیست
                // (دکمهٔ بی‌نام، UI غیرقابل‌استفاده می‌سازد).
                continue;
            }

            $clinicians[] = ['id' => $id, 'name' => $name];
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

        $surfaceId = self::surfaceId();
        $lines = [];

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

        return implode('', $lines);
    }

    /**
     * وضعیت `empty` — Clinic **معتبر** است ولی پزشک فعالی برای نوبت‌دهی ندارد.
     *
     * این وضعیت عمداً از `error` جداست: `error` یعنی «پیکربندیِ سطح خراب است»
     * و `empty` یعنی «پیکربندی درست است، چیزی برای رزرو نیست». در هر دو حالت
     * هیچ پزشکی منتشر نمی‌شود.
     */
    private static function renderEmpty(int $clinicId, string $clinicName): string
    {
        PublicBookingAssets::enqueue();

        $surfaceId = self::surfaceId();
        $lines = [];

        $lines[] = sprintf(
            '<div class="%1$s %1$s--empty" dir="rtl" lang="fa" data-clinic-id="%2$d" data-state="%3$s" id="%4$s">',
            esc_attr(self::ROOT_CLASS),
            $clinicId,
            esc_attr(self::STATE_EMPTY),
            esc_attr($surfaceId)
        );
        $lines[] = self::renderHead($clinicName, $surfaceId);
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
    private static function renderBrowse(int $clinicId, string $clinicName, array $clinicians): string
    {
        PublicBookingAssets::enqueue();

        $surfaceId = self::surfaceId();
        $lines = [];

        $lines[] = sprintf(
            '<div class="%1$s" dir="rtl" lang="fa" data-clinic-id="%2$d" data-state="%3$s" id="%4$s">',
            esc_attr(self::ROOT_CLASS),
            $clinicId,
            esc_attr(self::STATE_BOOKABLE),
            esc_attr($surfaceId)
        );
        $lines[] = self::renderHead($clinicName, $surfaceId);

        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__body'));

        // ---- گام ۱: پزشکان (server-rendered؛ هیچ endpoint عمومیِ لیست پزشک) ----
        $legendId = $surfaceId . '-clinicians';
        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__clinicians'));
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__legend'),
            esc_attr($legendId),
            esc_html(self::STEP_CLINICIAN)
        );
        $lines[] = sprintf(
            '<ul class="%1$s" role="list" aria-labelledby="%2$s" data-role="clinicians">',
            esc_attr(self::ROOT_CLASS . '__clinician-list'),
            esc_attr($legendId)
        );
        foreach ($clinicians as $clinician) {
            $lines[] = sprintf('<li class="%s">', esc_attr(self::ROOT_CLASS . '__clinician-item'));
            $lines[] = sprintf(
                '<button type="button" class="%1$s" data-clinician-id="%2$d" aria-pressed="false">'
                . '<span class="%3$s">%4$s</span>'
                . '<span class="%5$s">%6$s</span>'
                . '</button>',
                esc_attr(self::CLINICIAN_CLASS),
                (int) $clinician['id'],
                esc_attr(self::ROOT_CLASS . '__clinician-name'),
                esc_html((string) $clinician['name']),
                esc_attr(self::ROOT_CLASS . '__clinician-hint'),
                esc_html(self::CLINICIAN_HINT)
            );
            $lines[] = '</li>';
        }
        $lines[] = '</ul>';
        $lines[] = '</div>';

        // ---- گام ۲: مرورِ نوبت‌ها (با A1 موجود) ----
        $panelLegendId = $surfaceId . '-calendar';
        $lines[] = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__calendar'));
        $lines[] = sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__legend'),
            esc_attr($panelLegendId),
            esc_html(self::STEP_SLOT)
        );
        $lines[] = sprintf(
            '<div class="%1$s" data-role="panel" data-state="idle" role="group" aria-labelledby="%2$s" aria-busy="false">',
            esc_attr(self::ROOT_CLASS . '__panel'),
            esc_attr($panelLegendId)
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
            $lines[] = self::renderAuthContinuation($surfaceId);
        } elseif ($continuation === self::CONTINUATION_PATIENT) {
            // بیمارِ واردشده: ادامهٔ Hold→Confirm با nonce مسیرهای موجود.
            $lines[] = self::renderPatientContinuation($surfaceId, $clinicId);
        }

        $lines[] = '</div>'; // body

        $lines[] = self::renderConfig($clinicId, $surfaceId);
        $lines[] = '</div>'; // root

        return implode('', $lines);
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
    private static function renderAuthContinuation(string $surfaceId): string
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

        $lines[] = sprintf('<div class="%1$s" data-auth-step="otp-mobile" hidden>', esc_attr($b . '__auth-step'));
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr($b . '__label'),
            esc_attr($mobileInputId),
            esc_html('شماره موبایل')
        );
        $lines[] = sprintf(
            '<input type="tel" class="%1$s" id="%2$s" data-role="otp-mobile" inputmode="tel" autocomplete="tel" dir="ltr">',
            esc_attr($b . '__input'),
            esc_attr($mobileInputId)
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-auth-action="otp-request">%2$s</button>',
            esc_attr($b . '__btn'),
            esc_html('دریافت کد تأیید')
        );
        $lines[] = '</div>';

        $lines[] = sprintf('<div class="%1$s" data-auth-step="otp-code" hidden>', esc_attr($b . '__auth-step'));
        $lines[] = sprintf(
            '<label class="%1$s" for="%2$s">%3$s</label>',
            esc_attr($b . '__label'),
            esc_attr($codeInputId),
            esc_html('کد ۶ رقمی پیامک‌شده')
        );
        $lines[] = sprintf(
            '<input type="text" class="%1$s" id="%2$s" data-role="otp-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr">',
            esc_attr($b . '__input'),
            esc_attr($codeInputId)
        );
        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-auth-action="otp-verify">%2$s</button>',
            esc_attr($b . '__btn'),
            esc_html('تأیید و ادامه')
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
     * nonce و مسیرهای B1/B2 از قرارداد runtime می‌آیند (renderConfig)؛ این
     * بخش فقط اسکلتِ وضعیت‌ها را server-render می‌کند: شمارشِ معکوسِ Hold،
     * فرمِ نامِ بیمارِ جدید، پیشنهادهای نزدیک پس از باختِ race، رسیدِ نهایی
     * (کد رهگیری + تاریخ جلالی). انتخابِ قبلیِ غیر-PHI توسط JS باز-نشانی و
     * با B1 موجود ادامه می‌یابد (B6 resume عمداً استفاده نمی‌شود).
     */
    private static function renderPatientContinuation(string $surfaceId, int $clinicId): string
    {
        $b = self::ROOT_CLASS;
        $firstNameId = $surfaceId . '-first-name';
        $lastNameId = $surfaceId . '-last-name';

        $lines = [];
        $lines[] = sprintf(
            '<div class="%1$s" data-role="booking-continue" hidden>',
            esc_attr($b . '__continue-box')
        );

        $lines[] = sprintf(
            '<p class="%1$s" data-role="continue-status" role="status" aria-live="polite" hidden></p>',
            esc_attr($b . '__status')
        );

        // شمارشِ معکوس TTL Hold — فقط مقدار عددی توسط JS پر می‌شود.
        $lines[] = sprintf(
            '<p class="%1$s" data-role="hold-countdown" hidden>%2$s <b data-role="countdown-value" dir="ltr"></b></p>',
            esc_attr($b . '__countdown'),
            esc_html('زمان باقی‌ماندهٔ نگه‌داشتن نوبت:')
        );

        // Phase 8 Slice 3 — chooser برای N>1 linked active Patients (LINKED-ONLY authority).
        // فقط برای N>1 رندر می‌شود؛ 0 یا 1 هیچ chooserی ندارد. هرگز decoy/same-mobile/cross-clinic/archived را نشان نمی‌دهد.
        $chooserHtml = '';
        try {
            $uid = get_current_user_id();
            if ($uid > 0 && $clinicId > 0) {
                $dbChooser = \ClinicCore\Bootstrap\App::db();
                $linkedPatients = $dbChooser->fetchAll(
                    'SELECT p.id, p.first_name, p.last_name FROM ' . $dbChooser->table('cpms_patient_user_links') . ' l JOIN ' . $dbChooser->table('cpms_patients') . ' p ON p.id = l.patient_id WHERE l.wp_user_id = %d AND l.clinic_id = %d AND p.status = %s AND p.clinic_id = %d ORDER BY l.is_primary DESC, l.id ASC',
                    [$uid, $clinicId, 'active', $clinicId]
                );
                if (is_array($linkedPatients) && count($linkedPatients) > 1) {
                    $chooserParts = [];
                    $chooserParts[] = sprintf('<div class="%s" data-role="patient-chooser">', esc_attr($b . '__chooser'));
                    $chooserParts[] = sprintf('<p class="%s">%s</p>', esc_attr($b . '__chooser-hint'), esc_html('لطفاً بیمار مورد نظر برای رزرو را انتخاب کنید:'));
                    foreach ($linkedPatients as $lp) {
                        if (!is_array($lp)) {
                            continue;
                        }
                        $pid = (int) ($lp['id'] ?? 0);
                        if ($pid <= 0) {
                            continue;
                        }
                        $fn = trim((string) ($lp['first_name'] ?? ''));
                        $ln = trim((string) ($lp['last_name'] ?? ''));
                        $label = trim($fn . ' ' . $ln);
                        if ($label === '') {
                            $label = 'بیمار #' . $pid;
                        }
                        $chooserParts[] = sprintf(
                            '<button type="button" class="%s" data-role="patient-option" data-patient-id="%d">%s</button>',
                            esc_attr($b . '__patient-option'),
                            $pid,
                            esc_html($label)
                        );
                    }
                    $chooserParts[] = '</div>';
                    $chooserHtml = implode('', $chooserParts);
                }
            }
        } catch (\Throwable $e) {
            $chooserHtml = '';
        }
        if ($chooserHtml !== '') {
            $lines[] = $chooserHtml;
        }

        // فرمِ نام — فقط برای بیمارِ «جدید» در Clinicِ نوبت؛ سرور الزام را
        // با CLINIC_VALIDATION_FAILED اعلام می‌کند و فرم همین‌جا باز می‌شود.
        $lines[] = sprintf('<div class="%1$s" data-role="names-form" hidden>', esc_attr($b . '__names'));
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

        $lines[] = sprintf(
            '<button type="button" class="%1$s" data-role="confirm-btn" hidden>%2$s</button>',
            esc_attr($b . '__btn'),
            esc_html('تأیید نهایی نوبت')
        );

        // رسیدِ نهایی — مقادیر (کد رهگیری/تاریخ جلالی/ساعت) توسط JS از پاسخ
        // خودِ B2 با textContent جای‌گذاری می‌شوند.
        $lines[] = sprintf('<div class="%1$s" data-role="receipt" hidden>', esc_attr($b . '__receipt'));
        $lines[] = sprintf(
            '<p class="%1$s">%2$s</p>',
            esc_attr($b . '__receipt-title'),
            esc_html('نوبت شما با موفقیت ثبت شد.')
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s <b data-role="reference-code" dir="ltr"></b></p>',
            esc_attr($b . '__receipt-line'),
            esc_html('کد رهگیری:')
        );
        $lines[] = sprintf(
            '<p class="%1$s">%2$s <b data-role="slot-jalali"></b> — %3$s <b data-role="slot-time" dir="ltr"></b></p>',
            esc_attr($b . '__receipt-line'),
            esc_html('تاریخ نوبت:'),
            esc_html('ساعت:')
        );
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
     * سرتیتر سطح: عنوان + نامِ همان Clinicِ پیوندشده (تنها دادهٔ غیرپزشکیِ
     * نمایشی که از ردیفِ پایدارِ Clinic می‌آید).
     */
    private static function renderHead(string $clinicName, string $surfaceId): string
    {
        $titleId = $surfaceId . '-title';

        $head = sprintf('<div class="%s">', esc_attr(self::ROOT_CLASS . '__head'));
        $head .= sprintf(
            '<p class="%1$s" id="%2$s">%3$s</p>',
            esc_attr(self::ROOT_CLASS . '__title'),
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
