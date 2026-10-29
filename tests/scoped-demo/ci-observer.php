<?php
/**
 * CI-only observer. NEVER shipped in the installable ZIP.
 *
 *  * captures the real Plugin_Upgrader::plugin_info() result during an actual
 *    wp-admin ZIP upload (no WP-CLI substitution);
 *  * rotates the one-time persona passwords AFTER provisioning commits and
 *    writes them to a non-public runner path for the browser harness;
 *  * wraps the existing `log` SMS provider to capture ONLY reserved 0900…
 *    synthetic challenges for the wrong-mobile recovery drill. The production
 *    OTP service, rate limits, hashing, expiry and one-time consumption are
 *    untouched. No real mobile number is accepted by this observer.
 *
 * @package CpmsDisposableDemo
 */

defined( 'ABSPATH' ) || exit;

if ( ! getenv( 'GITHUB_ACTIONS' ) ) {
	return;
}

$cpms_ci_dir = (string) ( getenv( 'CPSD_CI_DIR' ) ?: '/tmp/cpsa-ci' );
$cpms_ci_browser = (string) ( getenv( 'CPSD_CI_BROWSER_DIR' ) ?: $cpms_ci_dir . '/browser' );

$cpms_ci_write = static function ( string $path, array $data ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	file_put_contents( $path, (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), LOCK_EX );
	chmod( $path, 0600 );
};

add_action(
	'upgrader_post_install',
	static function ( $response, $hook_extra, $result ) use ( $cpms_ci_dir, $cpms_ci_write ) {
		if ( ! is_array( $result ) || 'cpms-disposable-demo' !== (string) ( $result['destination_name'] ?? '' ) ) {
			return $response;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// The upload request may have warmed the plugin cache before the new
		// directory existed. Mirror the post-install cache invalidation WordPress
		// performs before the skin asks for plugin_info().
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}
		$upgrader = new Plugin_Upgrader();
		$info     = $upgrader->plugin_info();
		$plugins  = get_plugins( '/cpms-disposable-demo' );
		$valid    = validate_plugin( 'cpms-disposable-demo/cpms-disposable-demo.php' );
		$cpms_ci_write(
			$cpms_ci_dir . '/admin-install-info.json',
			array(
				'ok'               => 'cpms-disposable-demo/cpms-disposable-demo.php' === $info && 1 === count( $plugins ) && 0 === $valid,
				'plugin_info'      => $info,
				'discovered'       => array_keys( $plugins ),
				'discovered_count' => count( $plugins ),
				'validate_plugin'  => is_wp_error( $valid ) ? $valid->get_error_code() : 0,
				'destination'      => (string) $result['destination'],
			)
		);
		return $response;
	},
	10,
	3
);

add_action(
	'cpms_demo_provisioned',
	static function ( $manifest ) use ( $cpms_ci_browser, $cpms_ci_write ): void {
		// Only acceptance fixture capture rotates passwords. Other drills (for
		// example scoped integration) authenticate with the credentials returned
		// by their own provision call; rotating underneath them invalidates them.
		if ( ! getenv( 'CPSD_CI_CAPTURE' ) ) {
			return;
		}
		if ( ! is_array( $manifest ) || 'active' !== (string) ( $manifest['status'] ?? '' ) || ! class_exists( 'CpmsDemo\\Credentials' ) ) {
			return;
		}
		$env         = new CpmsDemo\Env();
		$credentials = new CpmsDemo\Credentials( $env );
		$first_id    = (int) ( $manifest['ids']['user_ids'][ CpmsDemo\Credentials::PERSONA_ORDER[0] ] ?? 0 );
		$first       = get_userdata( $first_id );
		$prefix      = ( $first && 0 === strpos( $first->user_login, 'cpsd-' ) ) ? 'cpsd-' : 'cpdd-';
		$passwords   = $credentials->rotate( $manifest, $prefix );
		$out         = array();
		foreach ( CpmsDemo\Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $manifest['ids']['user_ids'][ $persona ] ?? 0 );
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				throw new RuntimeException( 'CI persona missing' );
			}
			$out[ $persona ] = array( 'username' => $user->user_login, 'password' => $passwords[ $persona ] );
		}
		$cpms_ci_write( $cpms_ci_browser . '/persona-credentials.json', $out );
		$cpms_ci_write(
			$cpms_ci_browser . '/provision-observer.json',
			array( 'ok' => true, 'fixture_id' => (string) $manifest['fixture_id'], 'personas' => count( $out ), 'secrets_published' => false )
		);
	}
);

add_action(
	'cpms_sms_provider',
	static function ( $registry ) use ( $cpms_ci_dir, $cpms_ci_write ): void {
		$inner = $registry->get( 'log' );
		if ( ! $inner ) {
			return;
		}
		$registry->register(
			new class( $inner, $cpms_ci_dir . '/private/synthetic-otp.json', $cpms_ci_write ) implements ClinicCore\Infrastructure\Sms\SmsProviderInterface {
				private $inner;
				private string $path;
				private $write;

				public function __construct( $inner, string $path, callable $write ) {
					$this->inner = $inner;
					$this->path  = $path;
					$this->write = $write;
				}

				private function capture( string $mobile, string $code ): void {
					if ( 1 !== preg_match( '/^0900\d{7}$/D', $mobile ) || 1 !== preg_match( '/^\d{6}$/D', $code ) ) {
						return;
					}
					$data = is_file( $this->path ) ? (array) json_decode( (string) file_get_contents( $this->path ), true ) : array();
					$data[ $mobile ] = $code;
					( $this->write )( $this->path, $data );
				}

				public function id(): string { return $this->inner->id(); }
				public function label(): string { return $this->inner->label(); }
				public function capabilities(): array { return $this->inner->capabilities(); }
				public function authMethods(): array { return $this->inner->authMethods(); }
				public function authFields(): array { return $this->inner->authFields(); }
				public function testConnection( array $creds ): array { return $this->inner->testConnection( $creds ); }
				public function mapStatus( string $providerStatus ): string { return $this->inner->mapStatus( $providerStatus ); }
				public function fetchSenders( array $creds ): ?array { return $this->inner->fetchSenders( $creds ); }
				public function fetchBalance(array $creds ): ?array { return $this->inner->fetchBalance( $creds ); }

				public function sendText( array $creds, string $mobile, string $message, array $opts = array() ): array {
					if ( preg_match( '/\b(\d{6})\b/', $message, $m ) ) {
						$this->capture( $mobile, $m[1] );
					}
					return $this->inner->sendText( $creds, $mobile, $message, $opts );
				}

				public function sendTemplate( array $creds, string $mobile, string $templateId, array $variables, array $opts = array() ): array {
					$this->capture( $mobile, (string) ( $variables['otp_code'] ?? '' ) );
					return $this->inner->sendTemplate( $creds, $mobile, $templateId, $variables, $opts );
				}
			}
		);
	}
);
