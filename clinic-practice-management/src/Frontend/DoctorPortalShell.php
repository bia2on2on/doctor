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
		add_action( 'admin_enqueue_scripts', [ self::class, 'register_handwriting_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_for_portal' ], 20 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_handwriting_for_staff' ], 21 );
		add_action( 'template_redirect', [ self::class, 'send_private_cache_headers' ], 0 );
		add_action( 'template_redirect', [ self::class, 'redirect_legacy_entry_to_staff_portal' ], 1 );
	}

	/**
	 * Phase 10 — ONE-TIME server-side compatibility redirect.
	 *
	 * The legacy Doctor Portal URL is a compatibility ENTRY only, never a
	 * second visual shell: an actor who is eligible for the delivered doctor
	 * module is sent once (302, private/no-cache) to the canonical Staff
	 * Portal, which then serves the one shared shell. The target is the
	 * plugin-owned canonical Page permalink only: no request input, no
	 * authority ids, tokens or nonces in the URL, so no open redirect.
	 * Authorization is unchanged; every REST call re-authorizes as before.
	 */
	public static function redirect_legacy_entry_to_staff_portal(): void {
		$target = self::legacy_redirect_target( get_current_user_id() );
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target, 302, 'CPMS' );
		exit;
	}

	/**
	 * Canonical Staff Portal URL for an eligible doctor on the legacy entry,
	 * or '' when no redirect applies (not the legacy Page, not eligible, or
	 * the canonical Page is unavailable). Non-eligible visitors keep the
	 * existing legacy login / access-denied document. Loop-safe: never
	 * targets the legacy Page and never fires on the canonical Page.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function legacy_redirect_target( int $user_id ): string {
		if ( ! self::is_portal_request() || StaffPortalShell::is_portal_request() ) {
			return '';
		}
		if ( ! StaffPortalShell::doctor_module_eligible( $user_id ) ) {
			return '';
		}
		$staff_page  = StaffPortalShell::ensure_portal_page();
		$legacy_page = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $staff_page <= 0 || $staff_page === $legacy_page ) {
			return '';
		}
		$target = get_permalink( $staff_page );
		if ( ! is_string( $target ) || '' === $target ) {
			return '';
		}
		return $target;
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

		/*
		 * Phase 10 — legacy Doctor Portal URL is a backward-compatible ENTRY to
		 * the shared operational Staff Portal. On a real request an eligible
		 * doctor never reaches this point: redirect_legacy_entry_to_staff_portal()
		 * sends them once to the canonical URL. This branch is only the
		 * fail-safe for paths where that redirect did not run (e.g. canonical
		 * Page unavailable, or template resolution without template_redirect):
		 * it still renders the SAME shared shell, never the old standalone
		 * doctor document.
		 *
		 * Non-eligible visitors (anonymous, patient-only, secretary,
		 * accountant, administrator, suspended/no membership) keep the existing
		 * login / access-denied document byte for byte.
		 */
		if ( StaffPortalShell::doctor_module_eligible( get_current_user_id() ) ) {
			$shared = StaffPortalShell::template_path();
			if ( is_readable( $shared ) ) {
				return $shared;
			}
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

	public const HANDWRITING_HANDLE = 'cpms-doctor-handwriting';

	/** Register the shared editor once; only the two handwriting surfaces enqueue it. */
	public static function register_handwriting_assets(): void {
		$base = self::plugin_url_base();
		if ( '' === $base ) {
			return;
		}
		$root = dirname( __DIR__, 2 );
		if ( ! wp_style_is( self::HANDWRITING_HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDWRITING_HANDLE, $base . '/assets/css/doctor-handwriting.css', [], (string) filemtime( $root . '/assets/css/doctor-handwriting.css' ) );
		}
		if ( ! wp_script_is( self::HANDWRITING_HANDLE, 'registered' ) ) {
			wp_register_script( self::HANDWRITING_HANDLE, $base . '/assets/js/doctor-handwriting.js', [], (string) filemtime( $root . '/assets/js/doctor-handwriting.js' ), true );
		}
	}

	public static function enqueue_handwriting_for_staff(): void {
		if ( ! \ClinicCore\Frontend\StaffPortalShell::is_portal_request() || ! self::isDoctorUser( wp_get_current_user() ) ) {
			return;
		}
		self::register_handwriting_assets();
		wp_enqueue_style( self::HANDWRITING_HANDLE );
		wp_enqueue_script( self::HANDWRITING_HANDLE );
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
