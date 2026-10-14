<?php
/**
 * WordPress-native sign-in for the CPMS Doctor and Staff portal surfaces.
 *
 * Authentication is always WordPress core authentication. Before an auth
 * cookie is issued, the user's existing Clinic-local module eligibility is
 * checked; this class adds no role, capability, Clinic membership or bypass.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Auth\RolesAndCapabilities;
use WP_User;

/**
 * Minimal native username/password sign-in shared by operational portals.
 */
final class PortalLogin {

	/** Register the two public and authenticated admin-post handlers once. */
	public static function register(): void {
		add_action( 'admin_post_nopriv_cpms_portal_login', array( self::class, 'handle' ) );
		add_action( 'admin_post_cpms_portal_login', array( self::class, 'handle' ) );
	}

	/**
	 * Render an HTML form without accepting any destination or Clinic ID from
	 * the request. The scope is a fixed template constant, and authorization is
	 * checked again after WordPress authenticates the credentials.
	 *
	 * @param string $portal Portal scope: 'doctor' or 'staff'.
	 */
	public static function render( string $portal ): void {
		if ( ! in_array( $portal, array( 'doctor', 'staff' ), true ) ) {
			return;
		}

		$title = 'doctor' === $portal ? 'ورود پزشک' : 'ورود کارکنان';
		$target = self::portal_url( $portal );
		$failed = isset( $_GET['cpms-login'] )
			&& is_string( $_GET['cpms-login'] )
			&& 'failed' === sanitize_key( wp_unslash( $_GET['cpms-login'] ) );
		?>
		<section class="cpms-portal-login cpms-portal-login--<?php echo esc_attr( $portal ); ?>" data-role="portal-login" aria-labelledby="cpms-portal-login-title">
			<h2 id="cpms-portal-login-title"><?php echo esc_html( $title ); ?></h2>
			<p>با نام کاربری و گذرواژهٔ وردپرس وارد شوید. دسترسی به هر بخش بر اساس نقش، عضویت فعال و مجوزهای همان کلینیک بررسی می‌شود.</p>
			<?php if ( $failed ) : ?>
				<p class="cpms-portal-login__error" role="alert">اطلاعات ورود معتبر نیست یا دسترسی به این پورتال مجاز نیست. دوباره تلاش کنید.</p>
			<?php endif; ?>
			<form class="cpms-portal-login__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="on">
				<input type="hidden" name="action" value="cpms_portal_login">
				<input type="hidden" name="cpms_portal" value="<?php echo esc_attr( $portal ); ?>">
				<?php wp_nonce_field( 'cpms_portal_login_' . $portal, '_cpms_portal_nonce' ); ?>
				<p class="cpms-portal-login__field">
					<label for="cpms-portal-login-user">نام کاربری یا ایمیل</label>
					<input id="cpms-portal-login-user" name="log" type="text" autocomplete="username" autocapitalize="none" required>
				</p>
				<p class="cpms-portal-login__field">
					<label for="cpms-portal-login-password">گذرواژه</label>
					<input id="cpms-portal-login-password" name="pwd" type="password" autocomplete="current-password" required>
				</p>
				<p class="cpms-portal-login__remember">
					<label><input name="rememberme" type="checkbox" value="1"> مرا به خاطر بسپار</label>
				</p>
				<p class="cpms-portal-login__actions">
					<button class="cpms-doc-btn cpms-doc-btn--primary" type="submit">ورود امن</button>
				</p>
			</form>
			<p class="cpms-portal-login__password-reset"><a href="<?php echo esc_url( wp_lostpassword_url( $target ) ); ?>">گذرواژه را فراموش کرده‌اید؟</a></p>
		</section>
		<?php
	}

	/**
	 * Authenticate using WordPress, authorize against the existing portal
	 * modules before issuing a session cookie, and redirect only to a CPMS-owned
	 * portal URL. There is intentionally no user-controlled redirect_to.
	 */
	public static function handle(): void {
		$portal = isset( $_POST['cpms_portal'] ) && is_string( $_POST['cpms_portal'] )
			? sanitize_key( wp_unslash( $_POST['cpms_portal'] ) )
			: '';
		if ( ! in_array( $portal, array( 'doctor', 'staff' ), true ) ) {
			$portal = 'staff';
		}

		$failure_url = self::portal_url( $portal );
		$nonce = isset( $_POST['_cpms_portal_nonce'] ) && is_string( $_POST['_cpms_portal_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_cpms_portal_nonce'] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'cpms_portal_login_' . $portal ) ) {
			self::fail( $failure_url );
		}

		if ( get_current_user_id() > 0 ) {
			if ( self::is_authorized( get_current_user_id(), $portal ) ) {
				self::redirect_success( $portal );
			}
			self::fail( $failure_url );
		}

		$username = isset( $_POST['log'] ) && is_string( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
		$password = isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';
		if ( '' === trim( $username ) || '' === $password ) {
			self::fail( $failure_url );
		}

		// First authenticate without issuing a cookie. This prevents a valid
		// WordPress account that is outside the existing CPMS module rules from
		// receiving a portal session. wp_signon below preserves core cookie and
		// wp_login behavior for an authorized user.
		$preauthenticated = wp_authenticate( $username, $password );
		if ( ! $preauthenticated instanceof WP_User || ! self::is_authorized( (int) $preauthenticated->ID, $portal ) ) {
			self::fail( $failure_url );
		}

		$remember_value = isset( $_POST['rememberme'] ) && is_string( $_POST['rememberme'] )
			? wp_unslash( $_POST['rememberme'] )
			: '';
		$credentials = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => '1' === $remember_value,
		);
		$authenticated = wp_signon( $credentials, is_ssl() );
		if ( $authenticated instanceof WP_User && self::is_authorized( (int) $authenticated->ID, $portal ) ) {
			self::redirect_success( $portal );
		}
		if ( $authenticated instanceof WP_User ) {
			// Eligibility may have changed between the pre-check and cookie issue.
			wp_logout();
		}
		self::fail( $failure_url );
	}

	/**
	 * Existing eligibility contract for the requested portal surface.
	 *
	 * @param int    $user_id User id.
	 * @param string $portal Portal scope.
	 */
	public static function is_authorized( int $user_id, string $portal ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( 'doctor' === $portal ) {
			return StaffPortalShell::doctor_module_eligible( $user_id );
		}
		if ( 'staff' !== $portal ) {
			return false;
		}

		// A Patient is never admitted to an operational portal, including a
		// mixed Patient/Staff account; staff eligibility must come from the
		// existing module rules, and the patient role itself is not a module.
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || in_array( RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true ) ) {
			return false;
		}

		return array() !== StaffPortalShell::eligible_modules( $user_id );
	}

	/**
	 * Canonical portal URL for safe in-product return navigation.
	 *
	 * @param string $portal Portal scope.
	 */
	private static function portal_url( string $portal ): string {
		return 'doctor' === $portal ? DoctorPortalShell::portal_url() : StaffPortalShell::portal_url();
	}

	/** Redirect after successful login to its canonical operational shell. */
	private static function redirect_success( string $portal ): void {
		wp_safe_redirect( self::portal_url( $portal ) );
		exit;
	}

	/** Redirect with a generic error; never echo authentication details/roles. */
	private static function fail( string $target ): void {
		wp_safe_redirect( add_query_arg( 'cpms-login', 'failed', $target ) );
		exit;
	}
}
