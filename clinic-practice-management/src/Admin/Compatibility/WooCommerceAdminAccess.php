<?php

declare(strict_types=1);

namespace ClinicCore\Admin\Compatibility;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Auth\RolesAndCapabilities;

/**
 * Coexistence integration with a third-party WordPress-admin lock-down policy.
 *
 * WHY THIS EXISTS (bounded, not a CPMS defect):
 * WooCommerce installs `WC_Admin::prevent_admin_access()` on `admin_init` and, for any
 * authenticated user without `edit_posts` / `manage_woocommerce` / `view_admin_dashboard`,
 * it redirects the wp-admin request to the WooCommerce My Account page through the
 * public filter `woocommerce_prevent_admin_access`. CPMS staff roles (doctor/secretary/
 * manager/accountant) deliberately hold none of those capabilities, so their own CPMS
 * wp-admin pages were redirected away — evidence: run `37611352785`, head
 * `a1788f66c14f1d624668cbee10d683b030c887c5` (isolated CPMS + WooCommerce 10.3.8 probe:
 * filter verdict `true`, redirect target = My Account page 9).
 *
 * WooCommerce's own exemption set is NOT the whole of wp-admin: its gate first exempts
 * `admin-post.php` and `admin-ajax.php` themselves (basename of
 * `$_SERVER['SCRIPT_FILENAME']`; `$exempted_paths` in
 * `plugins/woocommerce/includes/admin/class-wc-admin.php`,
 * `WC_Admin::prevent_admin_access()`, verified at WooCommerce 11.2.0). Every other
 * wp-admin screen — including `profile.php` — IS subject to the redirect. That fidelity
 * gap is why this class carries two separate, narrowly scoped predicates (page allowlist
 * + own-profile below) instead of one broad one; `admin-post.php`/`admin-ajax.php` need
 * no handling here because WooCommerce already exempts them before its filter is
 * consulted (a unit test models this exemption so the gap stays documented).
 *
 * HOW IT IS SCOPED (every clause is an invariant, not a preference):
 *  1. The screen being served must be `admin.php` (the only screen that serves CPMS pages)
 *     and the requested page must be present in the CPMS-owned registry below *and* actually
 *     registered for this request under the same capability.
 *  2. The user must be authenticated.
 *  3. The user must already independently hold the capability that the CPMS page itself
 *     requires — the capability its `add_menu_page()`/`add_submenu_page()` registration and
 *     its own `render()` guard enforce. This class grants nothing by itself.
 *  4. In every other case the third party's own verdict is returned untouched, so
 *     WooCommerce admin screens, `edit_posts` screens and all other wp-admin screens keep
 *     exactly the access they had before, and unknown/malformed/spoofed `page` values fail
 *     closed.
 *
 * `page` is a selector, never authority: the closed registry defines which pages CPMS owns,
 * the live menu registration proves the page was really registered for this request, and the
 * page's own capability is the authorization.
 *
 * Deliberately absent: role/capability mutation, removing the third-party callback, a global
 * `woocommerce_prevent_admin_access = false`, any WooCommerce option/`SCRIPT_FILENAME`
 * special-casing, any tenant/Clinic decision (tenant-scoped authorization stays inside each
 * page/service), and any front-end/public-route change. Pure-patient accounts have no
 * wp-admin CPMS surface by design (`PatientPortalPage`), so the patient legacy slug is
 * intentionally not in the registry.
 *
 * @package ClinicCore\Admin\Compatibility
 */
final class WooCommerceAdminAccess {

    /** Public WooCommerce filter that carries its admin-access policy verdict. */
    public const FILTER = 'woocommerce_prevent_admin_access';

    /** The only wp-admin screen on which a registered CPMS page is served. */
    public const CPMS_ADMIN_SCREEN = 'admin.php';

    /**
     * M12 — the wp-admin screen where a user manages their OWN profile
     * (`wp-admin/profile.php`). Distinct from `user-edit.php` (someone else's
     * profile) and untouched by WooCommerce's own exemption set.
     */
    public const PROFILE_SCREEN = 'profile.php';

    /**
     * Explicit allowlist: CPMS-owned wp-admin page slug => the capability that very page requires.
     *
     * Values are *existing* CPMS capabilities — no capability is introduced or granted here.
     * The Integration test asserts this list matches the real page registrations one-to-one,
     * in both directions, so it cannot drift into a widening or keep a stale entry.
     *
     * @var array<string,string>
     */
    public const PAGES = [
        'cpms-dashboard'          => RolesAndCapabilities::CONFIG,
        'cpms-wizard'             => RolesAndCapabilities::CONFIG,
        'cpms-staff'              => RolesAndCapabilities::CONFIG,
        'cpms-locations'          => RolesAndCapabilities::CONFIG,
        'cpms-clinicians'         => RolesAndCapabilities::CONFIG,
        'cpms-system'             => RolesAndCapabilities::CONFIG,
        'cpms-settings'           => RolesAndCapabilities::CONFIG,
        'cpms-sms'                => RolesAndCapabilities::SMS_CONFIG,
        'cpms-roles'              => 'manage_options',
        'cpms-doctor'             => RolesAndCapabilities::QUEUE_READ,
        'cpms-handwriting'        => RolesAndCapabilities::NOTE_CREATE,
        'cpms-prescription-print' => RolesAndCapabilities::RX_READ,
        'cpms-queue'              => RolesAndCapabilities::QUEUE_READ,
        'cpms-finance'            => RolesAndCapabilities::FINANCE_READ,
        'cpms-patients'           => RolesAndCapabilities::PATIENT_READ,
    ];

    public static function register(): void {
        add_filter( self::FILTER, [self::class, 'allow_authorized_cpms_page'], 10, 1 );
        add_filter( self::FILTER, [self::class, 'allow_own_profile_for_cpms_staff'], 10, 1 );
    }

    /**
     * WooCommerce-facing callback: relaxes only an authorized request for a registered CPMS page.
     *
     * @param bool $prevent_access WooCommerce's own verdict for this request.
     */
    public static function allow_authorized_cpms_page( bool $prevent_access ): bool {
        global $pagenow;

        // Network/user admin screens never serve a CPMS page — leave the policy untouched.
        if ( is_network_admin() || is_user_admin() ) {
            return $prevent_access;
        }

        return self::decide(
            $prevent_access,
            is_string( $pagenow ) ? $pagenow : '',
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector; never authority (registry + registration + capability below).
            $_GET['page'] ?? null,
            is_user_logged_in(),
            self::PAGES,
            static fn ( string $capability ): bool => current_user_can( $capability ),
            static fn ( string $slug, string $capability ): bool => self::is_registered_page( $slug, $capability )
        );
    }

    /**
     * M12 — WooCommerce-facing callback: relaxes only a CPMS staff member's
     * request for their OWN profile.php.
     *
     * Separate from allow_authorized_cpms_page() and deliberately narrower than it:
     * profile.php is not a CPMS-owned page and has no CPMS menu registration, so
     * the page allowlist above can never cover it. WooCommerce (11.2.0) exempts
     * admin-post.php/admin-ajax.php itself but redirects profile.php for users
     * without its access capabilities — which every CPMS staff role fails by
     * design, locking them out of their own WordPress profile (password, email,
     * application passwords).
     *
     * Authority is capability-based, never role-name-based: at least one
     * capability from the closed CPMS set (RolesAndCapabilities::ALL_CAPS —
     * namespaced cpms_* capabilities only; `read` and generic WordPress
     * capabilities are never in that list). A patient-only user holds none of
     * them, stays under the policy, and PatientPortalPage::guardWpAdmin keeps
     * redirecting pure patients to the frontend portal.
     *
     * @param bool $prevent_access WooCommerce's own verdict for this request.
     */
    public static function allow_own_profile_for_cpms_staff( bool $prevent_access ): bool {
        global $pagenow;

        // Network/user admin screens are not a site's own profile.php — untouched.
        if ( is_network_admin() || is_user_admin() ) {
            return $prevent_access;
        }

        return self::decide_own_profile(
            $prevent_access,
            is_string( $pagenow ) ? $pagenow : '',
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only self-identity selector; never authority (screen + same-user + CPMS capability below).
            $_GET['user_id'] ?? null,
            get_current_user_id(),
            RolesAndCapabilities::ALL_CAPS,
            static fn ( string $capability ): bool => current_user_can( $capability )
        );
    }

    /**
     * Pure decision — no WordPress globals or functions, so every branch is directly testable.
     *
     * @param bool                     $prevent_access     Third party's own verdict.
     * @param string                   $admin_screen       Basename of the wp-admin screen being served.
     * @param mixed                    $requested_page     Raw `page` selector (validated, never trusted).
     * @param bool                     $is_authenticated   Whether a user is logged in.
     * @param array<string,string>     $pages              Closed CPMS-owned page => capability registry.
     * @param callable(string):bool    $capability_granted Authority probe for the page's own capability.
     * @param callable(string,string):bool $page_is_registered Registration probe for this request.
     *
     * @return bool The verdict to hand back to the third-party policy.
     */
    public static function decide(
        bool $prevent_access,
        string $admin_screen,
        mixed $requested_page,
        bool $is_authenticated,
        array $pages,
        callable $capability_granted,
        callable $page_is_registered
    ): bool {
        // Nothing to relax — the policy already allows this request.
        if ( $prevent_access === false ) {
            return false;
        }

        // An anonymous request must never gain a coexistence exception.
        if ( $is_authenticated === false ) {
            return $prevent_access;
        }

        // Only the screen that serves CPMS pages — nothing else in wp-admin.
        if ( $admin_screen !== self::CPMS_ADMIN_SCREEN ) {
            return $prevent_access;
        }

        // Fail closed for absent/non-string selectors, and for anything CPMS does not own.
        if ( ! is_string( $requested_page ) || $requested_page === '' ) {
            return $prevent_access;
        }

        if ( ! array_key_exists( $requested_page, $pages ) ) {
            return $prevent_access;
        }

        // A registry entry without a real capability can only mean a defect → fail closed.
        $capability = $pages[ $requested_page ];
        if ( ! is_string( $capability ) || $capability === '' ) {
            return $prevent_access;
        }

        // The page's own existing authorization stays authoritative.
        if ( $capability_granted( $capability ) !== true ) {
            return $prevent_access;
        }

        // The page must actually be registered for this request under that same capability.
        if ( $page_is_registered( $requested_page, $capability ) !== true ) {
            return $prevent_access;
        }

        return false;
    }

    /**
     * M12 — pure decision for the own-profile exception. No WordPress globals or
     * functions, so every branch is directly testable.
     *
     * Relaxation requires ALL of:
     *  - the third party's verdict is still "prevent" (false stays false — a verdict
     *    already allowing the request is returned untouched);
     *  - a user is logged in ($current_user_id > 0);
     *  - the screen being served is exactly profile.php (nothing else — not
     *    user-edit.php, users.php, async-upload.php, options or third-party screens,
     *    which all keep WooCommerce's verdict);
     *  - the `user_id` request parameter is absent (own profile by default) or equals
     *    the current user's id — anyone else's profile keeps the policy verdict;
     *  - the user holds at least one capability from the closed CPMS set — capability,
     *    never role name, so a staff+patient user is treated by capability and a
     *    patient-only user (no cpms_* capability at all) is not relaxed.
     *
     * @param bool                  $prevent_access      Third party's own verdict.
     * @param string                $admin_screen        Basename of the wp-admin screen being served.
     * @param mixed                 $requested_user_id   Raw `user_id` selector (validated, never trusted).
     * @param int                   $current_user_id     The current user's id (0 when logged out).
     * @param list<string>          $cpms_capabilities   Closed CPMS capability set (RolesAndCapabilities::ALL_CAPS).
     * @param callable(string):bool $capability_granted  Authority probe for one CPMS capability.
     *
     * @return bool The verdict to hand back to the third-party policy.
     */
    public static function decide_own_profile(
        bool $prevent_access,
        string $admin_screen,
        mixed $requested_user_id,
        int $current_user_id,
        array $cpms_capabilities,
        callable $capability_granted
    ): bool {
        // Nothing to relax — the policy already allows this request.
        if ( $prevent_access === false ) {
            return false;
        }

        // An anonymous request must never gain a coexistence exception.
        if ( $current_user_id <= 0 ) {
            return $prevent_access;
        }

        // Only the own-profile screen — everything else in wp-admin stays untouched.
        if ( $admin_screen !== self::PROFILE_SCREEN ) {
            return $prevent_access;
        }

        // Own profile only: absent selector, or exactly the current user's id.
        // Fail closed for any other id and for malformed selectors.
        if ( $requested_user_id !== null ) {
            if ( ! is_numeric( $requested_user_id ) || (int) $requested_user_id !== $current_user_id ) {
                return $prevent_access;
            }
        }

        // Capability, never role name: at least one CPMS-namespaced capability.
        foreach ( $cpms_capabilities as $capability ) {
            if ( is_string( $capability ) && $capability !== '' && $capability_granted( $capability ) === true ) {
                return false;
            }
        }

        return $prevent_access;
    }

    /**
     * Is this slug registered for the current request as a CPMS page requiring $capability?
     *
     * `wp-admin/admin.php` fires `admin_menu` (wp-admin/menu.php) before `admin_init`, so the
     * menu structures are available at the point the third-party policy runs. Reading them adds
     * a second, independent proof of ownership; note that top-level entries exist regardless of
     * capability, which is why the capability check above is still mandatory.
     *
     * Fail-closed: missing or malformed menu globals, and no matching entry, both mean "not registered".
     */
    public static function is_registered_page( string $slug, string $capability ): bool {
        global $menu, $submenu;

        if ( ! is_array( $menu ) && ! is_array( $submenu ) ) {
            return false;
        }

        foreach ( (array) $menu as $item ) {
            if ( self::entry_matches( $item, $slug, $capability ) ) {
                return true;
            }
        }

        foreach ( (array) $submenu as $group ) {
            foreach ( (array) $group as $item ) {
                if ( self::entry_matches( $item, $slug, $capability ) ) {
                    return true;
                }
            }
        }

        // B-1: detail pages stay registered beneath «مدیریت مطب» but are hidden from
        // the visible submenu array. Registration is not visibility: the row captured
        // at registration time proves this request really registered the page, and the
        // capability stored on that row must still match. Nothing is widened here.
        foreach ( CpmsAdminMenu::hidden_submenu_rows() as $item ) {
            if ( self::entry_matches( $item, $slug, $capability ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * WordPress menu row shape: [0] => menu title, [1] => capability, [2] => slug.
     */
    private static function entry_matches( mixed $item, string $slug, string $capability ): bool {
        return is_array( $item )
            && isset( $item[1], $item[2] )
            && is_string( $item[1] )
            && is_string( $item[2] )
            && $item[2] === $slug
            && $item[1] === $capability;
    }
}
