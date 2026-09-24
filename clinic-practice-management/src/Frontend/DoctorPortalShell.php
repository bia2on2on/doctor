<?php
/**
 * Phase 10 Slice 1 — independent CPMS Doctor Portal frontend shell.
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use WP_Post;

/**
 * Frontend entry + standalone shell for the independent doctor portal (read-only Today+Live Queue).
 */
final class DoctorPortalShell {
	public const PAGE_SLUG = 'cpms-doctor-portal'; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
	public const PAGE_OPTION = 'cpms_doctor_portal_page_id'; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
	public const TEMPLATE_REL = 'templates/doctor-portal-shell.php';
	public const CSS_HANDLE = 'cpms-doctor-portal'; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
	public const JS_HANDLE = 'cpms-doctor-portal'; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability

	public static function register(): void {
		add_action( 'init', [ self::class, 'ensure_portal_page' ], 20 );
		add_filter( 'template_include', [ self::class, 'filter_template_include' ], 99 );
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_on_portal' ], 20 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'register_handles' ], 5 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_for_portal' ], 20 );
		add_action( 'template_redirect', [ self::class, 'send_private_cache_headers' ], 0 );
	}

	public static function ensure_portal_page(): int {
		$existing = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $existing > 0 ) {
			$post = get_post( $existing );
			if ( $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
				return $existing;
			}
		}

		$by_slug = get_page_by_path( self::PAGE_SLUG );
		if ( $by_slug instanceof WP_Post && 'publish' === $by_slug->post_status ) {
			update_option( self::PAGE_OPTION, (int) $by_slug->ID, false );
			return (int) $by_slug->ID;
		}

		$author = get_current_user_id();
		if ( $author <= 0 ) {
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
				'post_title'   => 'پورتال پزشک',
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

	public static function portal_url(): string {
		$page_id = self::ensure_portal_page();
		if ( $page_id <= 0 ) {
			return home_url( '/' );
		}
		$url = get_permalink( $page_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return home_url( '/?page_id=' . $page_id );
		}
		return $url;
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- alias for discovery, legacy camelCase
	public static function frontendPortalUrl(): string {
		return self::portal_url();
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- alias for discovery
	public static function frontendUrl(): string {
		return self::portal_url();
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- alias for discovery
	public static function doctorPortalFrontendUrl(): string {
		return self::portal_url();
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- alias for discovery
	public static function pageUrl(): string {
		return self::portal_url();
	}

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

	public static function template_path(): string {
		$base = defined( 'CPMS_PLUGIN_DIR' ) ? (string) CPMS_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
		return rtrim( $base, '/\\' ) . '/' . self::TEMPLATE_REL;
	}

	public static function hide_admin_bar_on_portal( bool $show ): bool {
		if ( self::is_portal_request() ) {
			return false;
		}
		return $show;
	}

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

	public static function register_handles(): void {
		$base = self::plugin_url_base();
		if ( '' === $base ) {
			return;
		}
		$version = defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : 'dev';
		wp_register_style( self::CSS_HANDLE, $base . '/assets/css/cpms-doctor-portal.css', [], $version );
		wp_register_script( self::JS_HANDLE, $base . '/assets/js/cpms-doctor-portal.js', [], $version, true );
	}

	public static function enqueue_for_portal(): void {
		if ( ! self::is_portal_request() ) {
			return;
		}
		self::register_handles();
		wp_enqueue_style( self::CSS_HANDLE );
		wp_enqueue_script( self::JS_HANDLE );
	}

	private static function plugin_url_base(): string {
		if ( defined( 'CPMS_PLUGIN_URL' ) && '' !== CPMS_PLUGIN_URL ) {
			return rtrim( (string) CPMS_PLUGIN_URL, '/' );
		}
		return '';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy camelCase, established contract
	public static function isDoctorUser( \WP_User $user ): bool {
		$roles = (array) ( $user->roles ?? [] );
		if ( ! in_array( \ClinicCore\Auth\RolesAndCapabilities::ROLE_DOCTOR, $roles, true ) ) {
			return false;
		}
		$db            = \ClinicCore\Bootstrap\App::db(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		$clinician_id = $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ (int) $user->ID ]
		);
		return null !== $clinician_id && (int) $clinician_id > 0;
	}
}
