<?php
/**
 * Dedicated frontend Management Portal (Organization Managers + Clinic Managers).
 *
 * Business-access architecture: only WordPress Administrators need wp-admin.
 * This portal is a plugin-owned, published Page rendered by a plugin-owned
 * standalone template — the same WordPress-native mechanism the Patient,
 * Doctor and Staff portals already use (no rewrite framework, no SPA router, no
 * second authentication backend, and no /wp-admin navigation).
 *
 * This class owns ONLY the surface: page creation, template interception,
 * private cache headers, admin-bar hiding, and the explicit selection rules for
 * the Organization and Clinic surfaces. It grants no authority: every read is
 * resolved by ManagementPortalService from durable membership + capability, and
 * every write re-authorizes inside the domain service.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use WP_Post;

/**
 * Canonical Management Portal entry + shell.
 */
final class ManagementPortalShell {

	/** CPMS-owned canonical Page slug. */
	public const PAGE_SLUG = 'cpms-management-portal';

	/** Option storing the published Page id (a selector only, never authority). */
	public const PAGE_OPTION = 'cpms_management_portal_page_id';

	/** Plugin-owned standalone shell template. */
	public const TEMPLATE_REL = 'templates/management-portal-shell.php';

	/** Read-only selector of the Organization surface. */
	public const ORG_PARAM = 'cpms-org';

	/** Read-only selector of the Clinic surface. */
	public const CLINIC_PARAM = 'cpms-clinic';

	/** Asset handle for the management portal JS (province→city cascading). */
	public const JS_HANDLE = 'cpms-management-portal';

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		ManagementPortalController::register();
		add_action( 'init', array( self::class, 'ensure_portal_page' ), 22 );
		add_filter( 'template_include', array( self::class, 'filter_template_include' ), 99 );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar_on_portal' ), 20 );
		add_action( 'template_redirect', array( self::class, 'send_private_cache_headers' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_handles' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_for_portal' ), 20 );
	}

	/**
	 * Register management portal assets (offline catalog JS). No external calls.
	 */
	public static function register_handles(): void {
		$base = defined( 'CPMS_PLUGIN_URL' ) ? rtrim( (string) CPMS_PLUGIN_URL, '/' ) : '';
		if ( '' === $base ) {
			return;
		}
		$ver = defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : '1.0.0';
		wp_register_script(
			self::JS_HANDLE,
			$base . '/assets/js/cpms-management-portal.js',
			array(),
			$ver,
			true
		);
	}

	/**
	 * Enqueue management portal assets only on the portal request.
	 */
	public static function enqueue_for_portal(): void {
		if ( ! self::is_portal_request() ) {
			return;
		}
		self::register_handles();
		wp_enqueue_script( self::JS_HANDLE );
	}

	/**
	 * Ensure a published CPMS-owned Page exists and is recorded. Idempotent.
	 */
	public static function ensure_portal_page(): int {
		$existing = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $existing > 0 ) {
			$post = get_post( $existing );
			if ( $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
				return $existing;
			}
		}

		$by_slug = get_page_by_path( self::PAGE_SLUG );
		if ( $by_slug instanceof WP_Post && 'page' === $by_slug->post_type && 'publish' === $by_slug->post_status ) {
			update_option( self::PAGE_OPTION, (int) $by_slug->ID, false );

			return (int) $by_slug->ID;
		}

		$author = get_current_user_id();
		if ( $author <= 0 ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			$author = isset( $admins[0] ) ? (int) $admins[0] : 0;
		}

		$inserted = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'پورتال مدیریت',
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '',
				'post_author'  => max( 1, $author ),
			),
			true
		);
		if ( is_wp_error( $inserted ) ) {
			return 0;
		}
		$page_id = (int) $inserted;
		if ( $page_id <= 0 ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, $page_id, false );

		return $page_id;
	}

	/**
	 * Absolute canonical frontend Management Portal URL (never /wp-admin).
	 */
	public static function portal_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id <= 0 ) {
			$page_id = self::ensure_portal_page();
		}
		if ( $page_id <= 0 ) {
			return home_url( '/' );
		}
		$url = get_permalink( $page_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return home_url( '/?page_id=' . $page_id );
		}

		return $url;
	}

	/**
	 * Whether the main query is the canonical Management Portal page.
	 */
	public static function is_portal_request(): bool {
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
	 * Intercept template_include only on the Management Portal page.
	 *
	 * @param string $template Template chosen by WordPress/Theme.
	 */
	public static function filter_template_include( string $template ): string {
		if ( ! self::is_portal_request() ) {
			return $template;
		}
		$owned = self::template_path();
		if ( ! is_readable( $owned ) ) {
			return $template;
		}

		return $owned;
	}

	/**
	 * Absolute path of the plugin-owned shell template.
	 */
	public static function template_path(): string {
		return self::plugin_dir() . '/' . self::TEMPLATE_REL;
	}

	/**
	 * Suppress the admin bar on the operational surface.
	 *
	 * @param bool $show Current admin bar visibility.
	 */
	public static function hide_admin_bar_on_portal( bool $show ): bool {
		if ( self::is_portal_request() ) {
			return false;
		}

		return $show;
	}

	/**
	 * Authenticated management documents are private and never public-cacheable.
	 */
	public static function send_private_cache_headers(): void {
		if ( ! self::is_portal_request() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	}

	/**
	 * Plugin root directory without trailing slash.
	 */
	private static function plugin_dir(): string {
		$base = defined( 'CPMS_PLUGIN_DIR' ) ? (string) CPMS_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';

		return rtrim( $base, '/\\' );
	}
}
