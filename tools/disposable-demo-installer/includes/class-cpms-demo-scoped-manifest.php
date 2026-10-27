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

	/**
	 * Sealed copy of the pre-provisioning baseline.
	 *
	 * Provisioning writes the manifest incrementally, so a run interrupted part
	 * way through leaves a flagged `failed` manifest that has no sealed baseline
	 * yet. Without a recorded baseline the teardown would refuse (correctly, it
	 * cannot prove what was there before), which would turn an interrupted run
	 * into permanently orphaned demo rows. This record is written before the
	 * first row is created, is HMAC-sealed and site+fixture bound, and is deleted
	 * on clean completion.
	 */
	public const PENDING_BASELINE_OPTION = 'cpsd_baseline_pending';

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
	 * Seal the pre-provisioning baseline before anything is created.
	 *
	 * @param string $fixture_id Pending fixture identity.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @param array<string,mixed> $host_before Host counters.
	 * @return void
	 */
	public function save_pending_baseline( string $fixture_id, array $baseline, array $host_before ): void {
		$payload = array(
			'fixture_id'  => $fixture_id,
			'baseline'    => $baseline,
			'host_before' => $host_before,
			'at'          => $this->env->now_sql(),
			'site'        => $this->site_fingerprint(),
		);
		$payload['mac'] = $this->mac( $payload );
		$this->env->option_set( self::PENDING_BASELINE_OPTION, $payload );
	}

	/**
	 * Read the sealed baseline of an interrupted provisioning run.
	 *
	 * Returns null for anything that is not a live, untampered record for this
	 * exact site and fixture; every caller fails closed on null.
	 *
	 * @param string $fixture_id Fixture identity from the manifest.
	 * @return array{baseline:array<string,mixed>,host_before:array<string,mixed>}|null
	 */
	public function load_pending_baseline( string $fixture_id ): ?array {
		$raw = $this->env->option_get( self::PENDING_BASELINE_OPTION, false );
		if ( ! is_array( $raw ) || '' === $fixture_id ) {
			return null;
		}
		if ( ! $this->verify( $raw ) ) {
			return null;
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->site_fingerprint() ) {
			return null;
		}
		if ( (string) ( $raw['fixture_id'] ?? '' ) !== $fixture_id ) {
			return null;
		}
		$baseline = (array) ( $raw['baseline'] ?? array() );
		if ( array() === $baseline ) {
			return null;
		}

		return array(
			'baseline'    => $baseline,
			'host_before' => (array) ( $raw['host_before'] ?? array() ),
		);
	}

	/**
	 * @return void
	 */
	public function clear_pending_baseline(): void {
		$this->env->option_delete( self::PENDING_BASELINE_OPTION );
	}

	/**
	 * @return bool
	 */
	public function has_pending_baseline(): bool {
		return is_array( $this->env->option_get( self::PENDING_BASELINE_OPTION, false ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function mode_record(): ?array {
		$raw = $this->env->option_get( self::MODE_OPTION, false );

		return is_array( $raw ) ? $raw : null;
	}
}
