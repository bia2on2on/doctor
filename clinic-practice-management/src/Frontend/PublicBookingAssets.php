<?php

/**
 * Phase 8 Slice 1 — Assets فرانت‌اندِ «سطح عمومی رزرو» (FR-3.5 / UC-01).
 *
 * مرز مسئولیت (عمداً باریک):
 *  - این کلاس فقط «ثبت/انکیو» دو فایل محلی است؛ هیچ منطق business، هیچ رندر
 *    markup و هیچ تصمیم tenant در آن نیست (آن‌ها در PublicBookingShortcode است).
 *  - کاملاً جدا از CpmsAssets (assets اسکوپ‌شدهٔ wp-admin با handle `cpms-admin`).
 *    هیچ اشتراک handle/file/hook بین این دو وجود ندارد، پس یک رندر فرانت‌اند
 *    هرگز asset مدیریتی بار نمی‌کند و برعکس.
 *
 * چرا شرطی (و چرا سه نقطهٔ تماس):
 *  1) هوک `wp_enqueue_scripts` (اولویت ۲۰) — مسیر canonical وردپرس: وقتی
 *     shortcode در محتوای Post/Page جاری حاضر باشد، style پیش از
 *     `wp_print_styles` (اولویت ۸ روی `wp_head`) انکیو می‌شود و در <head> چاپ
 *     می‌شود. این یعنی صفحاتی که سطح را ندارند هیچ‌یک از این دو فایل را بار
 *     نمی‌کنند (بدون سربار سراسری، همان سیاست CpmsAssets).
 *  2) فراخوانی مستقیم از داخل رندر shortcode — برای محتواهای پویایی که
 *     `has_shortcode()` روی Post جاری آن‌ها را نمی‌بیند (widget، block پویا،
 *     قالبِ پیش از query).
 *  3) هوک `wp_footer` — چون رندرِ موردِ (۲) می‌تواند پس از `wp_print_styles`
 *     اتفاق بیفتد، در آن حالت styleِ صف‌شده در head چاپ نشده است؛ این هوک
 *     همان handle را با API خودِ وردپرس چاپ می‌کند تا سطح هرگز «بدون استایل»
 *     دیده نشود (بدون markup خام و بدون بارِ دوم).
 *
 * سیاست وابستگی (D-6): vanilla — بدون framework، بدون build step، بدون CDN و
 * بدون هیچ منبع خارجی. هر دو فایل محلی‌اند و script در footer چاپ می‌شود.
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

/**
 * ثبت/انکیو شرطی CSS و JS اختصاصی سطح عمومی رزرو.
 */
final class PublicBookingAssets
{
    /** handle یکتای style و script این سطح (D-6) — عمداً هم‌نام با کلاس root markup. */
    public const HANDLE = 'cpms-public-booking';

    /** مسیرهای نسبیِ فایل‌های محلی نسبت به ریشهٔ افزونه. */
    private const CSS_REL = 'assets/css/cpms-public-booking.css';
    private const JS_REL = 'assets/js/cpms-public-booking.js';

    public static function register(): void
    {
        // اولویت ۵: ثبت handleها پیش از هر انکیو (هم در مسیر canonical و هم در
        // مسیر رندر). اولویت ۲۰: تصمیم شرطی پس از آنکه themeها/افزونه‌های دیگر
        // صف asset خود را بسته‌اند.
        add_action('wp_enqueue_scripts', [self::class, 'registerHandles'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueForCurrentPage'], 20);

        // اولویت ۵ روی wp_footer: پیش از `wp_print_footer_scripts` (اولویت ۲۰)،
        // پس scriptِ footer این سطح دست‌نخورده می‌ماند.
        add_action('wp_footer', [self::class, 'printLateStyle'], 5);
    }

    /**
     * ثبت دو handle محلی — idempotent.
     *
     * `wp_register_style()`/`wp_register_script()` روی handle موجود no-op است،
     * پس فراخوانی چندباره (هوک + رندر) ثبتِ نخست را بازنویسی نمی‌کند. این
     * متد public است چون مسیر رندر shortcode ممکن است پیش از اجرای هوک
     * `wp_enqueue_scripts` اتفاق بیفتد و باید handle را موجود ببیند.
     */
    public static function registerHandles(): void
    {
        $base = self::pluginUrlBase();
        if ($base === '') {
            return;
        }

        $version = \defined('CPMS_VERSION') ? (string) CPMS_VERSION : 'dev';

        wp_register_style(self::HANDLE, $base . '/' . self::CSS_REL, [], $version);
        // in_footer = true — رفتار مرورگر پیش از تعامل با سطح لازم نیست.
        wp_register_script(self::HANDLE, $base . '/' . self::JS_REL, [], $version, true);
    }

    /**
     * مسیر canonical: انکیو فقط وقتی shortcode در محتوای Post/Page جاری هست.
     *
     * `is_admin()` عمداً گیت شده است: `wp_enqueue_scripts` در wp-admin اجرا
     * نمی‌شود، اما در جریان‌های ترکیبی (مثل admin-ajax با template فرانت‌اند)
     * این گیت مانع نشت asset عمومی به درخواست مدیریتی می‌شود.
     */
    public static function enqueueForCurrentPage(): void
    {
        if (is_admin()) {
            return;
        }
        if (!PublicBookingShortcode::isOnCurrentPage()) {
            return;
        }

        self::enqueue();
    }

    /**
     * انکیو قطعیِ دو asset این سطح (idempotent).
     *
     * از داخل رندر shortcode صدا زده می‌شود؛ تضمین می‌کند handleها پیش از
     * `wp_enqueue_*` ثبت شده باشند تا انکیو در هر ترتیبی مؤثر بماند.
     */
    public static function enqueue(): void
    {
        self::registerHandles();

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * چاپِ دیرهنگامِ stylesheet — فقط وقتی رندرِ سطح **پس از** `wp_print_styles`
     * اتفاق افتاده باشد.
     *
     * در مسیر canonical این متد همان ابتدا برمی‌گردد (چون head هنوز چاپ نشده
     * یا style پیش‌تر در head چاپ شده است). در محتواهای پویا (widget/block)
     * رندر shortcode پس از <head> است و styleِ صف‌شده در head چاپ نمی‌شود؛
     * این هوک همان را در footer چاپ می‌کند.
     *
     * چرا با `wp_print_styles()` و نه با markup خام: چاپِ منبع باید کارِ خودِ
     * WordPress باشد (قاعدهٔ `WordPress.WP.EnqueuedResources`). ضمناً
     * `WP_Dependencies::do_items()` آیتم‌های `done` را رد می‌کند، پس این
     * فراخوانی هرگز بارِ دوم نمی‌سازد و اگر handle اصلاً انکیو نشده باشد هیچ
     * چیزی چاپ نمی‌کند.
     */
    public static function printLateStyle(): void
    {
        if (did_action('wp_print_styles') === 0) {
            return;
        }
        if (!wp_style_is(self::HANDLE, 'enqueued') && !wp_style_is(self::HANDLE, 'queue')) {
            return;
        }

        wp_print_styles(self::HANDLE);
    }

    /**
     * ریشهٔ URL افزونه بدون slash انتهایی؛ در نبودِ ثابتِ bootstrap رشتهٔ خالی
     * (fail-closed: هرگز URL حدس زده نمی‌شود).
     */
    private static function pluginUrlBase(): string
    {
        if (!\defined('CPMS_PLUGIN_URL')) {
            return '';
        }

        $url = (string) constant('CPMS_PLUGIN_URL');

        return $url === '' ? '' : rtrim($url, '/');
    }
}
