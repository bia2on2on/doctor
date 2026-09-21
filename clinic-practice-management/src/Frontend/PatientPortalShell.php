<?php

/**
 * Phase 9 Slice 3 — independent CPMS Patient Portal frontend shell.
 *
 * Mechanism (owner architecture / PR #98 proof → production):
 *   WordPress Page
 *   + plugin-owned `template_include` interception
 *   + plugin-owned standalone full-document CPMS template.
 *
 * Presentation independence only: reuses CPMS Core, PatientPortalPage content
 * (Slices 1–2), WordPress session + `wp_rest` nonce. No rewrite framework,
 * no new REST, no new auth, no Staff Portal.
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Admin\PatientPortalPage;
use WP_Post;

/**
 * Frontend entry + standalone shell for the pure-patient portal.
 */
final class PatientPortalShell
{
	/** CPMS-owned Page slug (Plain + Pretty via core main query — no custom rewrite). */
	public const PAGE_SLUG = 'cpms-patient-portal';

	/** Option storing the published Page id (selector only — not tenant authority). */
	public const PAGE_OPTION = 'cpms_patient_portal_page_id';

	/** Plugin-owned standalone template (never a theme file). */
	public const TEMPLATE_REL = 'templates/patient-portal-shell.php';

	/** Dedicated portal style handle — not `cpms-admin`. */
	public const CSS_HANDLE = 'cpms-patient-portal';

	/** Existing portal JS handle (cancel + mark-all) — reused, portal-scoped. */
	public const JS_HANDLE = 'cpms-patient-portal';

	public static function register(): void
	{
		add_action( 'init', [ self::class, 'ensurePortalPage' ], 20 );
		add_filter( 'template_include', [ self::class, 'filterTemplateInclude' ], 99 );
		add_filter( 'show_admin_bar', [ self::class, 'hideAdminBarOnPortal' ], 20 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'registerHandles' ], 5 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueForPortal' ], 20 );
		// Cache-Control for authenticated portal documents (private, never public CDN).
		add_action( 'template_redirect', [ self::class, 'sendPrivateCacheHeaders' ], 0 );
	}

	/**
	 * Ensure a published CPMS-owned Page exists and is recorded.
	 * Idempotent; safe under Plain/Pretty; no rewrite rules.
	 */
	public static function ensurePortalPage(): int
	{
		$existing = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $existing > 0 ) {
			$post = get_post( $existing );
			if ( $post instanceof WP_Post && $post->post_type === 'page' && $post->post_status === 'publish' ) {
				return $existing;
			}
		}

		$by_slug = get_page_by_path( self::PAGE_SLUG );
		if ( $by_slug instanceof WP_Post && $by_slug->post_status === 'publish' ) {
			update_option( self::PAGE_OPTION, (int) $by_slug->ID, false );

			return (int) $by_slug->ID;
		}

		$author = get_current_user_id();
		if ( $author <= 0 ) {
			// Prefer a stable existing administrator when creating the Page in boot/CLI.
			$admins = get_users(
				[
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				]
			);
			$author = isset( $admins[0] ) ? (int) $admins[0] : 0;
		}

		$page_id = (int) wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'پورتال بیمار',
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '',
				'post_author'  => $author > 0 ? $author : 1,
			],
			true
		);
		if ( $page_id <= 0 || is_wp_error( $page_id ) ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, $page_id, false );

		return $page_id;
	}

	/** Absolute frontend portal URL (never under /wp-admin/). */
	public static function portalUrl(): string
	{
		$page_id = self::ensurePortalPage();
		if ( $page_id <= 0 ) {
			return home_url( '/' );
		}
		$url = get_permalink( $page_id );
		if ( ! is_string( $url ) || $url === '' ) {
			return home_url( '/?page_id=' . $page_id );
		}

		return $url;
	}

	/** Whether the main query is the CPMS Patient Portal page. */
	public static function isPortalRequest(): bool
	{
		if ( is_admin() ) {
			return false;
		}
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		return is_page( self::PAGE_SLUG );
	}

	/**
	 * Intercept template_include only on the portal Page.
	 * Other requests pass through unchanged (zero global presentation cost).
	 *
	 * @param string $template Theme-selected template path.
	 */
	public static function filterTemplateInclude( string $template ): string
	{
		if ( ! self::isPortalRequest() ) {
			return $template;
		}
		$owned = self::templatePath();
		if ( ! is_readable( $owned ) ) {
			return $template;
		}

		return $owned;
	}

	public static function templatePath(): string
	{
		$base = \defined( 'CPMS_PLUGIN_DIR' ) ? (string) CPMS_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';

		return rtrim( $base, '/\\' ) . '/' . self::TEMPLATE_REL;
	}

	/** Hide WP admin bar on the frontend portal surface only. */
	public static function hideAdminBarOnPortal( bool $show ): bool
	{
		if ( self::isPortalRequest() ) {
			return false;
		}

		return $show;
	}

	/** Authenticated portal documents are private — never public-cacheable. */
	public static function sendPrivateCacheHeaders(): void
	{
		if ( ! self::isPortalRequest() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	}

	public static function registerHandles(): void
	{
		$base = self::pluginUrlBase();
		if ( $base === '' ) {
			return;
		}
		$version = \defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : 'dev';
		wp_register_style( self::CSS_HANDLE, $base . '/assets/css/cpms-patient-portal.css', [], $version );
		wp_register_script( self::JS_HANDLE, $base . '/assets/js/cpms-patient-portal.js', [], $version, true );
	}

	/** Enqueue portal CSS/JS only on the Patient Portal frontend surface. */
	public static function enqueueForPortal(): void
	{
		if ( ! self::isPortalRequest() ) {
			return;
		}
		self::registerHandles();
		wp_enqueue_style( self::CSS_HANDLE );
		// JS only for pure patients (cancel + mark-all). Guest/staff shells need no portal script.
		if ( is_user_logged_in() && PatientPortalPage::isPatientOnly( wp_get_current_user() ) ) {
			wp_enqueue_script( self::JS_HANDLE );
		}
	}

	private static function pluginUrlBase(): string
	{
		if ( \defined( 'CPMS_PLUGIN_URL' ) && CPMS_PLUGIN_URL !== '' ) {
			return rtrim( (string) CPMS_PLUGIN_URL, '/' );
		}

		return '';
	}
}
