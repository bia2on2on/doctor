<?php
/**
 * Request boundary of the dedicated frontend Management Portal.
 *
 * Why a frontend handler and not admin-post.php: the Owner-approved architecture
 * requires authorized business workflows to complete WITHOUT navigating to
 * /wp-admin. Forms therefore post to the portal's own frontend URL, which this
 * controller handles on `template_redirect` (before any output), verifies a
 * nonce, and completes with a Post/Redirect/Get.
 *
 * Security contract:
 *  - authentication is WordPress core authentication (wp_signon), no parallel
 *    credential store;
 *  - every POST requires a valid nonce for a fixed action string;
 *  - Organization/Clinic identifiers submitted by the browser are selectors
 *    only: ManagementPortalService re-establishes durable membership and
 *    capability, and object ownership is re-read from the database;
 *  - failures are uniform and never disclose tenant topology or membership
 *    state;
 *  - no WordPress role is read anywhere on this path.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Application\Management\ManagementPortalService;
use ClinicCore\Bootstrap\App;
use WP_Error;
use WP_User;

/**
 * Handles portal POSTs (including native sign-in) with PRG semantics.
 */
final class ManagementPortalController {

	/** Nonce action for authenticated management writes. */
	public const NONCE_ACTION = 'cpms_management_portal';

	/** Nonce action for the portal's own sign-in form. */
	public const LOGIN_NONCE_ACTION = 'cpms_management_portal_login';

	/** Allowlisted write actions. */
	public const ACTIONS = array(
		'org_create_clinic',
		'org_update_clinic',
		'org_clinic_status',
		'org_designate_clinic_manager',
		'org_set_manager',
		'clinic_update_profile',
		'clinic_location_create',
		'clinic_location_update',
		'clinic_location_active',
		'clinic_staff_role',
		'clinic_staff_custom_role',
	);

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_handle_request' ), 1 );
	}

	/**
	 * Handle a POST to the portal URL, then redirect (never render on POST).
	 */
	public static function maybe_handle_request(): void {
		if ( ! ManagementPortalShell::is_portal_request() ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified below, before any state change.
		$action = isset( $_POST['cpms_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['cpms_action'] ) ) : '';

		if ( 'login' === $action ) {
			self::handle_login();

			return;
		}

		if ( ! is_user_logged_in() ) {
			self::redirect( array( 'notice' => 'auth_required' ) );
		}

		if ( ! self::nonce_is_valid( self::NONCE_ACTION ) ) {
			self::redirect( array( 'notice' => 'invalid' ) );
		}

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			self::redirect( array( 'notice' => 'invalid' ) );
		}

		$actor = (int) get_current_user_id();
		$input = self::collect_input( $action );
		$result = self::dispatch( $actor, $action, $input );

		self::redirect(
			array_merge(
				self::selection_from_input( $input ),
				array( 'notice' => $result['ok'] ? 'done' : 'denied' )
			)
		);
	}

	/**
	 * Authorized write dispatch. Pure with respect to HTTP: safe to call from
	 * tests with an explicit actor id and an input array.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public static function dispatch( int $actor_wp_user_id, string $action, array $input ): array {
		if ( $actor_wp_user_id <= 0 || ! in_array( $action, self::ACTIONS, true ) ) {
			return self::denied();
		}

		$service = self::service();

		switch ( $action ) {
			case 'org_create_clinic':
				return $service->create_clinic(
					$actor_wp_user_id,
					self::int_value( $input, 'organization_id' ),
					$input
				);

			case 'org_update_clinic':
				return $service->update_clinic_profile(
					$actor_wp_user_id,
					self::int_value( $input, 'organization_id' ),
					self::int_value( $input, 'clinic_id' ),
					$input
				);

			case 'org_clinic_status':
				return $service->set_clinic_status(
					$actor_wp_user_id,
					self::int_value( $input, 'organization_id' ),
					self::int_value( $input, 'clinic_id' ),
					self::bool_value( $input, 'active' )
				);

			case 'org_designate_clinic_manager':
				return $service->designate_clinic_manager(
					$actor_wp_user_id,
					self::int_value( $input, 'organization_id' ),
					self::int_value( $input, 'clinic_id' ),
					self::int_value( $input, 'target_user_id' )
				);

			case 'org_set_manager':
				return $service->set_organization_manager(
					$actor_wp_user_id,
					self::int_value( $input, 'organization_id' ),
					self::int_value( $input, 'target_user_id' ),
					self::bool_value( $input, 'active' )
				);

			case 'clinic_update_profile':
				return $service->update_clinic_settings(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					$input
				);

			case 'clinic_location_create':
				return $service->create_location(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					$input
				);

			case 'clinic_location_update':
				return $service->update_location(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					self::int_value( $input, 'location_id' ),
					$input
				);

			case 'clinic_location_active':
				return $service->set_location_active(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					self::int_value( $input, 'location_id' ),
					self::bool_value( $input, 'active' )
				);

			case 'clinic_staff_role':
				return $service->assign_built_in_role(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					self::int_value( $input, 'target_user_id' ),
					self::string_value( $input, 'role_key' )
				);

			case 'clinic_staff_custom_role':
				return $service->assign_custom_role(
					$actor_wp_user_id,
					self::int_value( $input, 'clinic_id' ),
					self::int_value( $input, 'target_user_id' ),
					self::string_value( $input, 'role_key' )
				);
		}

		return self::denied();
	}

	/**
	 * Native sign-in. On success WordPress issues the auth cookie and the actor
	 * is redirected to the portal; the surfaces are then resolved from durable
	 * membership. No role or capability is granted here.
	 */
	private static function handle_login(): void {
		if ( ! self::nonce_is_valid( self::LOGIN_NONCE_ACTION ) ) {
			self::redirect( array( 'notice' => 'invalid' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately above.
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['log'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately above.
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( (string) $_POST['pwd'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately above.
		$remember = isset( $_POST['rememberme'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['rememberme'] ) );

		if ( '' === $login || '' === $password ) {
			self::redirect( array( 'notice' => 'login_failed' ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( $user instanceof WP_Error || ! $user instanceof WP_User || (int) $user->ID <= 0 ) {
			self::redirect( array( 'notice' => 'login_failed' ) );
		}

		$service = self::service();
		if ( ! $service->has_any_surface( (int) $user->ID ) ) {
			// Authenticated but no durable CPMS management authority: fail closed
			// without disclosing why (no membership, suspended, or no capability).
			wp_logout();
			self::redirect( array( 'notice' => 'login_failed' ) );
		}

		self::redirect( array() );
	}

	/**
	 * Read the allowlisted request fields for one action.
	 *
	 * @return array<string,mixed>
	 */
	private static function collect_input( string $action ): array {
		$fields = array( 'organization_id', 'clinic_id', 'location_id', 'target_user_id', 'active', 'role_key', 'name', 'slug', 'timezone', 'location_name', 'address', 'phone' );
		$input  = array( 'action' => $action );
		foreach ( $fields as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified the nonce before dispatch.
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified the nonce before dispatch.
			$raw = wp_unslash( $_POST[ $field ] );
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$value              = sanitize_text_field( (string) $raw );
			$input[ $field ]    = $value;
		}

		return $input;
	}

	/**
	 * Preserve the explicit surface selection across the redirect.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,string>
	 */
	private static function selection_from_input( array $input ): array {
		$selection = array();
		$org       = self::int_value( $input, 'organization_id' );
		$clinic    = self::int_value( $input, 'clinic_id' );
		if ( $org > 0 ) {
			$selection[ ManagementPortalShell::ORG_PARAM ] = (string) $org;
		}
		if ( $clinic > 0 ) {
			$selection[ ManagementPortalShell::CLINIC_PARAM ] = (string) $clinic;
		}

		return $selection;
	}

	/**
	 * Verify a nonce from the POST body.
	 */
	private static function nonce_is_valid( string $action ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this method IS the nonce verification.
		$nonce = isset( $_POST['_cpms_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_cpms_nonce'] ) ) : '';

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Redirect to the portal with allowlisted query arguments and stop.
	 *
	 * @param array<string,string> $args
	 */
	private static function redirect( array $args ): void {
		$url = ManagementPortalShell::portal_url();
		if ( $args !== array() ) {
			$url = add_query_arg( $args, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private static function int_value( array $input, string $key ): int {
		$value = $input[ $key ] ?? 0;

		return is_scalar( $value ) ? absint( (string) $value ) : 0;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private static function bool_value( array $input, string $key ): bool {
		$value = $input[ $key ] ?? '0';

		return is_scalar( $value ) && '1' === (string) $value;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private static function string_value( array $input, string $key ): string {
		$value = $input[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * @return array{ok:bool, message:string}
	 */
	private static function denied(): array {
		return array(
			'ok'      => false,
			'message' => 'عملیات انجام نشد؛ دسترسی یا داده‌های ارسالی معتبر نیستند و هیچ تغییری ثبت نشد.',
		);
	}

	private static function service(): ManagementPortalService {
		return App::management_portal_service();
	}
}
