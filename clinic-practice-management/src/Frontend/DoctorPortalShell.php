<?php
// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent,WordPress.Files.FileName,WordPress.PHP.YodaConditions,Universal.Arrays.DisallowShortArraySyntax,WordPress.Arrays.ArrayDeclarationSpacing,NormalizedArrays.Arrays.ArrayBraceSpacing,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,WordPress.WhiteSpace.ControlStructureSpacing,PEAR.Functions.FunctionCallSignature,Generic.WhiteSpace.ArbitraryParenthesesSpacing,Squiz.Functions.FunctionDeclarationArgumentSpacing,Generic.Functions.OpeningFunctionBraceKernighanRitchie,WordPress.WhiteSpace.OperatorSpacing,Generic.Formatting.MultipleStatementAlignment,WordPress.WhiteSpace.CastStructureSpacing,WordPress.NamingConventions.PrefixAllGlobals,WordPress.Arrays.MultipleStatementAlignment,WordPress.WhiteSpace.OperatorSpacing,Generic.WhiteSpace.DisallowSpaceIndent
/**
 * Phase 10 Slice 1 — independent CPMS Doctor Portal frontend shell.
 *
 * Mechanism (owner architecture / PR #98 proof → production):
 *   WordPress Page
 *   + plugin-owned `template_include` interception (priority 99)
 *   + plugin-owned standalone full-document CPMS template.
 *
 * Presentation independence only: reuses CPMS Core, trusted Clinic+Location scope,
 * WordPress session + `wp_rest` nonce. No rewrite framework, no new auth,
 * no Staff Portal, no wp-admin redesign.
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,WordPress.WhiteSpace.ControlStructureSpacing,PEAR.Functions.FunctionCallSignature,Generic.WhiteSpace.ArbitraryParenthesesSpacing,Squiz.Functions.FunctionDeclarationArgumentSpacing,Generic.Functions.OpeningFunctionBraceKernighanRitchie,WordPress.WhiteSpace.OperatorSpacing,Generic.Formatting.MultipleStatementAlignment,WordPress.WhiteSpace.CastStructureSpacing,WordPress.NamingConventions.PrefixAllGlobals,WordPress.Arrays.MultipleStatementAlignment,WordPress.WhiteSpace.OperatorSpacing,Generic.WhiteSpace.DisallowSpaceIndent,WordPress.Arrays.ArrayDeclarationSpacing,NormalizedArrays.Arrays.ArrayBraceSpacing


namespace ClinicCore\Frontend;

use WP_Post;

/**
 * Frontend entry + standalone shell for the independent doctor portal (read-only Today+Live Queue).
 */
final class DoctorPortalShell
{
    /** CPMS-owned Page slug (Plain + Pretty via core main query — no custom rewrite). */
    public const PAGE_SLUG = 'cpms-doctor-portal';

    /** Option storing the published Page id (selector only — not tenant authority). */
    public const PAGE_OPTION = 'cpms_doctor_portal_page_id';

    /** Plugin-owned standalone template (never a theme file). */
    public const TEMPLATE_REL = 'templates/doctor-portal-shell.php';

    /** Dedicated portal style handle — not `cpms-admin`. */
    public const CSS_HANDLE = 'cpms-doctor-portal';

    /** Dedicated portal JS handle — vanilla JS only, no SPA. */
    public const JS_HANDLE = 'cpms-doctor-portal';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensure_portal_page'], 20);
        add_filter('template_include', [self::class, 'filter_template_include'], 99);
        add_filter('show_admin_bar', [self::class, 'hide_admin_bar_on_portal'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'register_handles'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_for_portal'], 20);
        // Cache-Control for authenticated portal documents (private, never public CDN).
        add_action('template_redirect', [self::class, 'send_private_cache_headers'], 0);
    }

    /**
     * Ensure a published CPMS-owned Page exists and is recorded.
     * Idempotent; safe under Plain/Pretty; no rewrite rules.
     */
    public static function ensure_portal_page(): int
    {
        $existing = (int) get_option(self::PAGE_OPTION, 0);
        if ($existing > 0) {
            $post = get_post($existing);
            if ($post instanceof WP_Post && $post->post_type === 'page' && $post->post_status === 'publish') {
                return $existing;
            }
        }

        $by_slug = get_page_by_path(self::PAGE_SLUG);
        if ($by_slug instanceof WP_Post && $by_slug->post_status === 'publish') {
            update_option(self::PAGE_OPTION, (int) $by_slug->ID, false);
            return (int) $by_slug->ID;
        }

        $author = get_current_user_id();
        if ($author <= 0) {
            $admins = get_users([
                'role' => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]);
            $author = isset($admins[0]) ? (int) $admins[0] : 0;
        }

        $page_id = (int) wp_insert_post(
            [
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'پورتال پزشک',
                'post_name' => self::PAGE_SLUG,
                'post_content' => '',
                'post_author' => $author > 0 ? $author : 1,
            ],
            true
        );
        if ($page_id <= 0 || is_wp_error($page_id)) {
            return 0;
        }
        update_option(self::PAGE_OPTION, $page_id, false);

        return $page_id;
    }

    /** Absolute frontend portal URL (never under /wp-admin/). */
    public static function portal_url(): string
    {
        $page_id = self::ensure_portal_page();
        if ($page_id <= 0) {
            return home_url('/');
        }
        $url = get_permalink($page_id);
        if (!is_string($url) || $url === '') {
            return home_url('/?page_id=' . $page_id);
        }
        return $url;
    }

    /** Alias for discovery via filters/methods */
    public static function frontendPortalUrl(): string { return self::portal_url(); }
    public static function frontendUrl(): string { return self::portal_url(); }
    public static function doctorPortalFrontendUrl(): string { return self::portal_url(); }
    public static function pageUrl(): string { return self::portal_url(); }

    /** Whether the main query is the CPMS Doctor Portal page. */
    public static function is_portal_request(): bool
    {
        if (is_admin()) {
            return false;
        }
        $page_id = (int) get_option(self::PAGE_OPTION, 0);
        if ($page_id > 0 && is_page($page_id)) {
            return true;
        }
        return is_page(self::PAGE_SLUG);
    }

    /**
     * Intercept template_include only on the portal Page.
     * Other requests pass through unchanged (zero global presentation cost).
     *
     * @param string $template Theme-selected template path.
     */
    public static function filter_template_include(string $template): string
    {
        if (!self::is_portal_request()) {
            return $template;
        }
        $owned = self::template_path();
        if (!is_readable($owned)) {
            return $template;
        }
        return $owned;
    }

    public static function template_path(): string
    {
        $base = defined('CPMS_PLUGIN_DIR') ? (string) CPMS_PLUGIN_DIR : dirname(__DIR__, 2) . '/';
        return rtrim($base, '/\\') . '/' . self::TEMPLATE_REL;
    }

    /** Hide WP admin bar on the frontend portal surface only. */
    public static function hide_admin_bar_on_portal(bool $show): bool
    {
        if (self::is_portal_request()) {
            return false;
        }
        return $show;
    }

    /** Authenticated portal documents are private — never public-cacheable. */
    public static function send_private_cache_headers(): void
    {
        if (!self::is_portal_request()) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    }

    public static function register_handles(): void
    {
        $base = self::plugin_url_base();
        if ($base === '') {
            return;
        }
        $version = defined('CPMS_VERSION') ? (string) CPMS_VERSION : 'dev';
        wp_register_style(self::CSS_HANDLE, $base . '/assets/css/cpms-doctor-portal.css', [], $version);
        wp_register_script(self::JS_HANDLE, $base . '/assets/js/cpms-doctor-portal.js', [], $version, true);
    }

    /** Enqueue portal CSS/JS only on the Doctor Portal frontend surface. */
    public static function enqueue_for_portal(): void
    {
        if (!self::is_portal_request()) {
            return;
        }
        self::register_handles();
        wp_enqueue_style(self::CSS_HANDLE);
        wp_enqueue_script(self::JS_HANDLE);
    }

    private static function plugin_url_base(): string
    {
        if (defined('CPMS_PLUGIN_URL') && CPMS_PLUGIN_URL !== '') {
            return rtrim((string) CPMS_PLUGIN_URL, '/');
        }
        return '';
    }

    /**
     * Whether current WP user is doctor with linked active clinician (fail-closed identity).
     * Secretary must not enter via overlapping caps.
     */
    public static function isDoctorUser(\WP_User $user): bool
    {
        $roles = (array) ($user->roles ?? []);
        if (!in_array(\ClinicCore\Auth\RolesAndCapabilities::ROLE_DOCTOR, $roles, true)) {
            return false;
        }
        // Must have linked active clinician
        $db = \ClinicCore\Bootstrap\App::db();
        $clinicianId = $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [(int) $user->ID]
        );
        return $clinicianId !== null && (int) $clinicianId > 0;
    }
}
