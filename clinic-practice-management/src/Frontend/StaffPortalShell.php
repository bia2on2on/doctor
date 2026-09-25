<?php
/**
 * Phase 10 — Shared operational Staff Portal shell (canonical container).
 *
 * Owner-approved three-environment architecture:
 *   A. Patient Portal                    — separate environment, untouched here.
 *   B. WordPress Admin / CPMS management — remains in wp-admin, untouched here.
 *   C. Shared operational Staff Portal   — ONE canonical container (this class).
 *
 * A shared portal does NOT mean shared permissions. This class only decides
 * which DELIVERED operational modules are visible; every operation remains
 * authorized server-side (nonce + capability + Clinic membership + trusted
 * Clinic + trusted Location + object ownership) by the existing REST layer.
 *
 * Same WordPress-native mechanism as the Patient and Doctor portals:
 * plugin-owned Page + `template_include` interception + plugin-owned
 * standalone full-document template. No rewrite framework, no SPA router,
 * no frontend build system, no parallel authentication backend.
 *
 * Rendering model (owner contract, docs/adr/0003): HYBRID, not a full SPA.
 * The shell, navigation and main page structure are server-rendered; the
 * delivered daily operational interactions keep using REST/AJAX.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_Post;

/**
 * Canonical shared Staff Portal entry + shell.
 */
final class StaffPortalShell {

	/**
	 * CPMS-owned canonical Page slug (Plain + Pretty permalinks via WordPress core).
	 */
	public const PAGE_SLUG = 'cpms-staff-portal';

	/**
	 * Option storing the published Page id (a selector only, never authority).
	 */
	public const PAGE_OPTION = 'cpms_staff_portal_page_id';

	/**
	 * Plugin-owned standalone shell template.
	 */
	public const TEMPLATE_REL = 'templates/staff-portal-shell.php';

	/**
	 * Delivered doctor operational module, mounted in embed mode so that its
	 * markup, data-role contracts and REST/AJAX behaviour stay identical to the
	 * legacy Doctor Portal entry.
	 */
	public const MODULE_TEMPLATE_REL = 'templates/doctor-portal-shell.php';

	/**
	 * Reused doctor stylesheet handle (no duplicated bundle).
	 */
	public const DOCTOR_CSS_HANDLE = DoctorPortalShell::CSS_HANDLE;

	/**
	 * Reused doctor script handle (no duplicated bundle).
	 */
	public const DOCTOR_JS_HANDLE = DoctorPortalShell::JS_HANDLE;

	/**
	 * The ONLY operational module delivered in this slice. Future modules are
	 * added only after their own product work; no placeholder is registered.
	 */
	public const MODULE_DOCTOR = 'doctor';

	/**
	 * Existing capabilities the delivered doctor clinical module relies on.
	 * No new capability is introduced merely for the shared shell to exist.
	 *
	 * @var list<string>
	 */
	public const DOCTOR_MODULE_CAPS = array(
		RolesAndCapabilities::QUEUE_READ,
		RolesAndCapabilities::MEDICAL_READ,
	);

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure_portal_page' ), 21 );
		add_filter( 'template_include', array( self::class, 'filter_template_include' ), 99 );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar_on_portal' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_handles' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_for_portal' ), 21 );
		add_action( 'template_redirect', array( self::class, 'send_private_cache_headers' ), 0 );
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
				'post_title'   => 'پورتال کارکنان',
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
	 * Absolute canonical frontend Staff Portal URL (never under /wp-admin/).
	 */
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

	/**
	 * Whether the main query is the canonical Staff Portal page.
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
	 * Intercept template_include only on the Staff Portal page.
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
	 * Absolute path of the mounted doctor module template.
	 */
	public static function module_template_path(): string {
		return self::plugin_dir() . '/' . self::MODULE_TEMPLATE_REL;
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
	 * Authenticated operational documents are private and never public-cacheable.
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
	 * Register the reused doctor handles (idempotent; no new bundle).
	 */
	public static function register_handles(): void {
		DoctorPortalShell::register_handles();
	}

	/**
	 * Conditional assets: only the module that is actually mounted.
	 */
	public static function enqueue_for_portal(): void {
		if ( ! self::is_portal_request() ) {
			return;
		}
		if ( ! self::doctor_module_eligible( get_current_user_id() ) ) {
			return;
		}
		self::register_handles();
		wp_enqueue_style( self::DOCTOR_CSS_HANDLE );
		wp_enqueue_script( self::DOCTOR_JS_HANDLE );
	}

	/**
	 * Delivered operational modules. An entry here is a product claim, not a
	 * placeholder: undelivered future modules are deliberately absent.
	 *
	 * @return list<array{id: string, title: string}>
	 */
	public static function registered_modules(): array {
		return array(
			array(
				'id'    => self::MODULE_DOCTOR,
				'title' => 'امروز پزشک — صف زنده',
			),
		);
	}

	/**
	 * Modules the actor may SEE, derived from existing authorization truth.
	 * Visibility only; it never grants authority.
	 *
	 * @param int $user_id WordPress user id.
	 * @return list<array{id: string, title: string}>
	 */
	public static function eligible_modules( int $user_id ): array {
		$eligible = array();
		foreach ( self::registered_modules() as $module ) {
			if ( self::module_eligible( $module['id'], $user_id ) ) {
				$eligible[] = $module;
			}
		}
		return $eligible;
	}

	/**
	 * Fail closed for anything that is not a delivered module.
	 *
	 * @param string $module_id Module identifier.
	 * @param int    $user_id   WordPress user id.
	 */
	public static function module_eligible( string $module_id, int $user_id ): bool {
		if ( self::MODULE_DOCTOR === $module_id ) {
			return self::doctor_module_eligible( $user_id );
		}
		return false;
	}

	/**
	 * Doctor clinical module VISIBILITY. Mirrors the delivered Doctor Portal
	 * boundary (perm_doctor) and never widens it.
	 *
	 * ROLE, CAPABILITY, CLINIC MEMBERSHIP and LOCATION stay separate:
	 *   - WP role: the doctor module is doctor-specific (no generic clinical module).
	 *   - clinician identity: server-derived active clinician row, never client input.
	 *   - membership: an ACTIVE Clinic membership is required; suspended or none hides it.
	 *   - capability: ALL module capabilities must be effective inside the SAME
	 *     Clinic via AuthorizationService (explicit deny, then grant, then preset).
	 *     Evaluated per Clinic and never unioned across Clinics.
	 *   - Location: not decided here; the module keeps its merged 0/1/N policy.
	 *
	 * A page request carries no trusted Clinic, so this only answers whether at
	 * least one ACTIVE Clinic lets this doctor use the module. With several
	 * Clinics the module still requires explicit trusted Clinic selection
	 * before any Clinic data is read, and every REST call re-authorizes.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function doctor_module_eligible( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( false === $user || ! $user->exists() ) {
			return false;
		}
		if ( ! in_array( RolesAndCapabilities::ROLE_DOCTOR, (array) $user->roles, true ) ) {
			return false;
		}
		if ( ! DoctorPortalShell::isDoctorUser( $user ) ) {
			return false;
		}

		$auth = App::authorization_service();
		foreach ( App::membership_service()->active_clinic_ids_for_user( $user_id ) as $clinic_id ) {
			if ( self::clinic_grants_all( $auth, $user_id, (int) $clinic_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether ONE Clinic grants every doctor module capability to the actor.
	 *
	 * @param \ClinicCore\Application\Authorization\AuthorizationService $auth      Authorization service.
	 * @param int                                                        $user_id   WordPress user id.
	 * @param int                                                        $clinic_id Trusted durable Clinic id.
	 */
	private static function clinic_grants_all( $auth, int $user_id, int $clinic_id ): bool {
		if ( $clinic_id <= 0 ) {
			return false;
		}
		foreach ( self::DOCTOR_MODULE_CAPS as $cap ) {
			if ( ! $auth->can( $user_id, $clinic_id, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Plugin root directory without trailing slash.
	 */
	private static function plugin_dir(): string {
		$base = defined( 'CPMS_PLUGIN_DIR' ) ? (string) CPMS_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
		return rtrim( $base, '/\\' );
	}
}
