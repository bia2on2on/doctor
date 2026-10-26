<?php
/**
 * Independent CSPRNG credentials for the six synthetic personas.
 *
 * Passwords exist only in the response of the request that generated them.
 * They are never written to the manifest, an option, a transient, a log, a file,
 * an audit row, or the HTML that follows.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Persona catalogue + credential lifecycle.
 */
class Credentials {

	public const PERSONA_ORDER = array(
		'organization_manager',
		'clinic_manager',
		'doctor',
		'receptionist',
		'accountant',
		'patient',
	);

	/**
	 * Persona definitions. `wp_role` is the WordPress role; CPMS authority comes
	 * from memberships created through the production membership service.
	 *
	 * @return array<string,array{label:string,wp_role:string,scope:string}>
	 */
	public static function personas(): array {
		return array(
			'organization_manager' => array(
				'label'   => 'مدیر سازمان',
				'wp_role' => 'subscriber',
				'scope'   => 'عضویت Organization برای سازمان ساختگی (بدون اختیار سایت)',
			),
			'clinic_manager' => array(
				'label'   => 'مدیر درمانگاه',
				'wp_role' => 'subscriber',
				'scope'   => 'عضویت cpms_manager در درمانگاه ساختگی',
			),
			'doctor' => array(
				'label'   => 'پزشک',
				'wp_role' => 'subscriber',
				'scope'   => 'Clinician + عضویت پزشک و دسترسی هر دو شعبهٔ ساختگی',
			),
			'receptionist' => array(
				'label'   => 'پذیرش',
				'wp_role' => 'subscriber',
				'scope'   => 'عضویت cpms_secretary در درمانگاه/شعبهٔ ساختگی',
			),
			'accountant' => array(
				'label'   => 'حسابدار',
				'wp_role' => 'subscriber',
				'scope'   => 'عضویت cpms_accountant در درمانگاه ساختگی',
			),
			'patient' => array(
				'label'   => 'بیمار',
				'wp_role' => 'cpms_patient',
				'scope'   => 'نقش cpms_patient + پیوند بیمار پس از OTP به بیمار ساختگی',
			),
		);
	}

	/**
	 * @var Env
	 */
	private Env $env;

	/**
	 * @param Env $env Environment.
	 */
	public function __construct( Env $env ) {
		$this->env = $env;
	}

	/**
	 * A single CSPRNG password: 192 bits of entropy, hex alphabet.
	 *
	 * @return string
	 */
	public static function generate_password(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * @return array<string,string> persona => password
	 */
	public function generate_all(): array {
		$out = array();
		foreach ( self::PERSONA_ORDER as $persona ) {
			$out[ $persona ] = self::generate_password();
		}

		return $out;
	}

	/**
	 * Rotate all six passwords: WP user password is replaced and every existing
	 * session of that persona is invalidated. Nothing is returned to storage.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array<string,string> persona => new password
	 * @throws Exception When a manifest account is missing or out of scope.
	 */
	public function rotate( array $manifest ): array {
		return $this->env->with_lock(
			'rotate_credentials',
			function () use ( $manifest ): array {
				if ( 'active' !== (string) ( $manifest['status'] ?? '' ) ) {
					throw new Exception( 'ROTATE_INACTIVE', 'چرخش رمز فقط برای دموی فعال مجاز است.' );
				}
				$fixture  = (string) ( $manifest['fixture_id'] ?? '' );
				$admin_id = (int) ( $manifest['admin_id'] ?? 0 );
				if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
					throw new Exception( 'ROTATE_SCOPE', 'شناسهٔ دمو نامعتبر است؛ چرخش رمز متوقف شد.' );
				}
				$users    = (array) ( $manifest['ids']['user_ids'] ?? array() );
				$verified = array();
				foreach ( self::PERSONA_ORDER as $persona ) {
					$user_id = (int) ( $users[ $persona ] ?? 0 );
					$account = $user_id > 0 ? $this->env->user( $user_id ) : null;
					if ( null === $account ) {
						throw new Exception( 'ROTATE_SCOPE', 'حساب آزمایشی «' . $persona . '» یافت نشد؛ چرخش رمز متوقف شد.' );
					}
					if ( $user_id === $admin_id || $this->env->user_can( $user_id, 'manage_options' ) || in_array( 'administrator', $account['roles'], true ) ) {
						throw new Exception( 'ROTATE_SCOPE', 'حساب آزمایشی نباید Administrator باشد؛ چرخش رمز متوقف شد.' );
					}
					if ( 'cpdd-' . $persona . '-' . $fixture !== (string) $account['login'] ) {
						throw new Exception( 'ROTATE_SCOPE', 'نام کاربری «' . $persona . '» با شناسهٔ دمو هم‌خوان نیست؛ چرخش رمز متوقف شد.' );
					}
					$verified[ $persona ] = $user_id;
				}
				$out = array();
				foreach ( self::PERSONA_ORDER as $persona ) {
					$user_id         = $verified[ $persona ];
					$password        = self::generate_password();
					$out[ $persona ] = $password;
					$this->env->set_user_password( $user_id, $password );
					$this->env->destroy_sessions( $user_id );
				}

				return $out;
			}
		);
	}
}
