<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

/**
 * One-shot, safe "first launch" redirect to the setup wizard after a
 * genuine single-site activation of THIS plugin by an Administrator.
 *
 * Hard requirements (do not weaken):
 *  - Administrator-only: gated on current_user_can('manage_options') at
 *    redirect time (never inferred from who clicked "Activate").
 *  - One-time: the flag is a short-lived transient, consumed (deleted)
 *    the first time admin_init observes it on a normal page load — win
 *    or lose, it can never fire twice and can never cause a redirect loop.
 *  - State-aware: even when the one-shot flag is present, the actual
 *    redirect only happens while CpmsSetupWizard::onboarding_panel_applies()
 *    is true — the SAME predicate the wizard itself uses to decide whether
 *    first-run attention is still genuinely needed. An already configured
 *    or multi-Clinic installation is never force-redirected, including on
 *    a later reactivation (deactivate -> reactivate) of an existing site.
 *  - Never set for network-wide (multisite bulk network) activation, and
 *    never set for a WordPress bulk "activate selected plugins" request —
 *    both are silently skipped at flag time, not merely at redirect time.
 *  - Scoped to the exact post-activation landing: the redirect only fires
 *    while $pagenow === 'plugins.php' — i.e. the literal page WordPress
 *    itself returns the admin to right after clicking "Activate" (and the
 *    page where CpmsAdminMenu::onboardingNotice() deliberately never
 *    renders its own notice, see its `$screen->id === 'plugins'` guard).
 *    This is a real regression fix: an earlier revision fired this redirect
 *    on ANY wp-admin page (admin_init runs long before admin_notices), so it
 *    silently ran on the dashboard landing too and prevented the existing,
 *    already-tested onboarding notice from ever rendering there. Scoping to
 *    plugins.php guarantees the two features never compete for the same
 *    page: the notice is never shown on plugins.php anyway, and this
 *    redirect never fires anywhere else.
 *  - The redirect itself only ever runs on a normal wp-admin page load
 *    (admin_init): it explicitly bails out of AJAX, REST, cron, WP-CLI and
 *    autosave contexts, and bails if the current admin is not the exact
 *    activating Administrator or is already on the wizard page.
 *  - A clear route back to the wizard always remains available from the
 *    CPMS admin menu regardless of this one-shot redirect's outcome.
 */
final class CpmsActivationRedirect {

    private const TRANSIENT = 'cpms_activation_redirect_uid';

    public static function register(): void {
        add_action( 'admin_init', [ self::class, 'maybe_redirect' ], 20 );
    }

    /**
     * Called from the plugin's register_activation_hook callback.
     * Deliberately as small and side-effect-free as possible: it only
     * arms a short-lived, single-admin flag; it never decides to redirect.
     */
    public static function flag_activation( bool $network_wide ): void {
        if ( $network_wide ) {
            // Network-wide activation has no single "the admin who activated
            // it" — never force any one site admin into the wizard for it.
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection of WordPress's own bulk-activation request shape, not a trusted input value.
        if ( isset( $_GET['activate-multi'] ) ) {
            // WordPress bulk "activate selected plugins": no single plugin's
            // onboarding should hijack that shared admin screen.
            return;
        }
        if ( \function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return;
        }
        if ( \defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        if ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        $uid = (int) get_current_user_id();
        if ( $uid <= 0 ) {
            // No identifiable human admin in this request (e.g. programmatic
            // activation from a deploy script) — nothing safe to redirect.
            return;
        }

        set_transient( self::TRANSIENT, $uid, 60 );
    }

    /**
     * admin_init: consume the one-shot flag (if any) and redirect only when
     * every safety condition holds. Always deletes the transient so a
     * single observation — redirect or not — can never repeat or loop.
     */
    public static function maybe_redirect(): void {
        if ( \function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return;
        }
        if ( \function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return;
        }
        if ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        if ( \defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( \defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) {
            return;
        }

        global $pagenow;
        if ( ( $pagenow ?? '' ) !== 'plugins.php' ) {
            // Not the literal post-activation landing page: never consume
            // or act on the flag here, so the real plugins.php observation
            // (the very next admin_init after activation) still gets its
            // one chance. This is what keeps every other admin page —
            // including the dashboard landing the onboarding notice uses —
            // completely untouched by this redirect.
            return;
        }

        $flagged_uid = get_transient( self::TRANSIENT );
        if ( false === $flagged_uid ) {
            return;
        }
        // One-shot: delete immediately, before any further check, so no
        // later request (redirect taken or not) can ever observe it again.
        delete_transient( self::TRANSIENT );

        // No redundant is_admin() gate: admin_init only ever fires inside
        // wp-admin itself (same reasoning as CpmsAssets::isCpmsPage()), and
        // an explicit is_admin() check here would make this method untestable
        // under WP_UnitTestCase, where WP_ADMIN is never defined.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( (int) get_current_user_id() !== (int) $flagged_uid ) {
            // Only the exact Administrator who activated the plugin is ever
            // redirected; a different concurrently logged-in admin is not.
            return;
        }

        // Single source of truth shared with the wizard's own embedded panel:
        // only redirect while first-run attention is genuinely still needed.
        if ( ! CpmsSetupWizard::onboarding_panel_applies() ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . CpmsSetupWizard::PAGE_SLUG ) );
        exit;
    }
}
