<?php
/**
 * HMAC-sealed ownership manifest for the separate scoped (non-empty-site) mode.
 *
 * This is deliberately isolated from the strict disposable manifest: option
 * names, format, fixture namespace and fixture entropy are different. No
 * password, OTP, nonce, session token or PHI is stored here.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped-mode manifest, mode activation marker and clean tombstone.
 */
class ScopedManifest extends Manifest {

	public const MODE_OPTION = 'cpsd_mode';
	public const LOCK_OPTION = 'cpsd_lock_state';

	public function __construct( Env $env ) {
		parent::__construct( $env );
		$this->option           = 'cpsd_manifest';
		$this->tombstone_option = 'cpsd_tombstone';
		$this->clean_option     = 'cpsd_had_clean_teardown';
		$this->format           = 2;
	}

	/**
	 * @param array<string,mixed> $data Manifest payload.
	 * @return array<string,mixed>
	 */
	public function save( array $data ): array {
		$data['mode'] = 'scoped';

		return parent::save( $data );
	}

	/**
	 * @param bool $require_site_match Whether to enforce this exact site.
	 * @return array<string,mixed>|null
	 */
	public function load( bool $require_site_match = true ): ?array {
		$loaded = parent::load( $require_site_match );
		if ( is_array( $loaded ) && 'scoped' !== (string) ( $loaded['mode'] ?? '' ) ) {
			throw new Exception( 'MANIFEST_FORMAT', 'قالب مالکیت متعلق به حالت محدودشده نیست.' );
		}

		return $loaded;
	}

	public function fixture_prefix(): string {
		return 'cpsd-';
	}

	public function fixture_pattern(): string {
		return '/^[a-f0-9]{32}$/D';
	}

	public function fixture_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public function audit_action(): string {
		return 'CPSD_DEMO_PROVISIONED';
	}

	public function otp_audit_action(): string {
		return 'CPSD_DEMO_OTP_REBOUND';
	}

	/**
	 * Enable the separate mode only after an explicit administrator form.
	 *
	 * @param int $actor_id Acting Administrator.
	 * @return void
	 */
	public function enable_mode( int $actor_id ): void {
		$payload = array(
			'enabled'  => true,
			'actor_id' => $actor_id,
			'at'       => $this->env->now_sql(),
			'site'     => $this->site_fingerprint(),
		);
		$payload['mac'] = $this->mac( $payload );
		$this->env->option_set( self::MODE_OPTION, $payload );
	}

	/**
	 * Verify the sealed mode marker. No marker means disabled.
	 *
	 * @return bool
	 */
	public function mode_enabled(): bool {
		$raw = $this->env->option_get( self::MODE_OPTION, false );
		if ( ! is_array( $raw ) || true !== ( $raw['enabled'] ?? false ) ) {
			return false;
		}
		$mac = (string) ( $raw['mac'] ?? '' );
		unset( $raw['mac'] );
		if ( '' === $mac || ! hash_equals( $this->mac( $raw ), $mac ) ) {
			return false;
		}

		return (string) ( $raw['site'] ?? '' ) === $this->site_fingerprint();
	}

	public function disable_mode(): void {
		$this->env->option_delete( self::MODE_OPTION );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function mode_record(): ?array {
		$raw = $this->env->option_get( self::MODE_OPTION, false );

		return is_array( $raw ) ? $raw : null;
	}
}
