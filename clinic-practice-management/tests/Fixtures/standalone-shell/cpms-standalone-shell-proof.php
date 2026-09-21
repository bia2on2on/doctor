<?php

/**
 * TEST FIXTURE — NON-PRODUCTION. Phase 9 Slice 3 technical proof (theme-independent
 * standalone CPMS shell). This file is NEVER part of the release ZIP
 * (`bin/build-release.sh` whitelist policy) and NEVER loaded by the production
 * plugin bootstrap. It is loaded only as proof infrastructure:
 *   - Integration: `require_once` from `tests/Integration/StandaloneShellProofTest.php`;
 *   - Real WordPress Acceptance: copied into `wp-content/mu-plugins/` for the
 *     bounded browser proof run (`bin/rwp-shell-proof.py`), then discarded with
 *     the disposable environment.
 *
 * ============================================================================
 * مکانیسم اثبات‌شده (WordPress-native, بدون rewrite framework / client router)
 * ============================================================================
 *
 *  روتینگ: یک «Page» معمولی وردپرس با slug ثابت `cpms-shell-proof` — در هر دو
 *  حالت Plain (`?page_id=N` / `?pagename=slug`) و Pretty (`/cpms-shell-proof/`)
 *  توسط خودِ coreِ وردپرس (main query) resolve می‌شود؛ هیچ rewrite rule سفارشی،
 *  هیچ query-var سفارشی و هیچ router کلاینتی وجود ندارد.
 *
 *  رندر: فیلتر `template_include` — تنها مکانیسم رسمی و پایدارِ WordPress برای
 *  جایگزینی قالبِ لودشده بدون خروج زودهنگام (`exit`) و بدون دست زدن به lifecycle
 *  (کوکی/نشست/هوکهای نرمال در دسترس‌اند). قالبِ متعلق به CPMS کل سند HTML را
 *  می‌سازد و عمداً `get_header()`/`get_footer()`/`wp_head()`/`wp_footer()` را
 *  صدا نمی‌زند — پس تم فعال هیچ کنترلی روی header/footer/layout/pوسته ندارد.
 *
 *  این فیکسچر هیچ محتوای واقعی Patient Portal ندارد — فقط نشانگرهای قرارداد
 *  پوسته (shell marker) برای اثبات.
 */

defined('ABSPATH') || exit;

if (!function_exists('cpms_shell_proof_register')) {

    /** slug ثابت صفحهٔ fixture (فقط تست). */
    function cpms_shell_proof_slug(): string
    {
        return 'cpms-shell-proof';
    }

    /** مسیر قالبِ مستقل — کنار همین فایل (repo fixture dir) یا زیرپوشهٔ کنارش (استقرار mu-plugins). */
    function cpms_shell_proof_template_path(): string
    {
        // بارگذارِ mu-plugins فقط فایلهایِ `.php` «ریشهٔ» wp-content/mu-plugins را خودکار
        // اجرا می‌کند؛ پس در استقرارِ Acceptance، قالب باید داخلِ زیرپوشه بنشیند تا
        // هرگز به‌عنوان «افزونهٔ must-use» اجرا نشود (درسِ گیتِ اول — خطای کلاس D).
        $candidates = [
            __DIR__ . '/cpms-standalone-shell-proof-template.php',
            __DIR__ . '/cpms-shell-proof-fixture/cpms-standalone-shell-proof-template.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * رهگیری `template_include` فقط برای صفحهٔ fixture؛ هر درخواست دیگری
     * (صفحات عادی، خانه، 404 و ...) دست‌نخورده از کنار می‌گذرد (بدون سربار).
     */
    function cpms_shell_proof_template_include(string $template): string
    {
        if (!is_page(cpms_shell_proof_slug())) {
            return $template;
        }
        $owned = cpms_shell_proof_template_path();

        // Fail-safe: اگر قالبِ مالکِ CPMS نبود، همان قالبِ معمولی برگردد (fail-closed نیست —
        // صفحهٔ fixture فقط تست است و fail-safe آن بازگشت به روال نرمال وردپرس است).
        return is_readable($owned) ? $owned : $template;
    }

    /** پنهان‌سازی Admin Bar فقط روی صفحهٔ fixture — بدون اثر جانبی روی صفحات دیگر. */
    function cpms_shell_proof_hide_admin_bar($show)
    {
        if (!is_admin() && is_page(cpms_shell_proof_slug())) {
            return false;
        }

        return $show;
    }

    /**
     * اثر هوکیِ سراسریِ این fixture دقیقاً همین دو فیلتر است:
     * `template_include` + `show_admin_bar` — هیچ asset سراسری، هیچ `wp_head`/
     * `wp_footer`، هیچ polling و هیچ بارِ frontend سراسری (قرارداد performance).
     */
    function cpms_shell_proof_register(): void
    {
        add_filter('template_include', 'cpms_shell_proof_template_include', 99);
        add_filter('show_admin_bar', 'cpms_shell_proof_hide_admin_bar', 20);
    }

    cpms_shell_proof_register();
}
