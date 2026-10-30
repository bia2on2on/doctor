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

	/**
	 * Explicit, informed administrator acceptance of the ONE consequence the
	 * scoped mode cannot undo on a single-Clinic host site.
	 *
	 * The audit log has a production foreign key to `cpms_clinics`, so the demo's
	 * anchor Clinic is archived in place at teardown and `COUNT(*)` on
	 * `cpms_clinics` stays one higher for ever. Production decides "implicit
	 * single-Clinic install" from exactly that count
	 * (`SystemClinicResolver::resolve()`, `FirstPracticeSetupService`,
	 * `single_clinic_display_row()`), so on a host that had exactly one Clinic
	 * this permanently moves the site from implicit scope to "explicit scope
	 * required" — a real change to pre-existing site behaviour.
	 *
	 * That is acceptable ONLY where the administrator has said, explicitly and
	 * in so many words, that this site is a private test site rather than a
	 * production install. This marker records that statement: HMAC-sealed, bound
	 * to this site's fingerprint and to the host name the administrator typed.
	 * Deleting the marker revokes the acceptance; the preflight reads this record
	 * and nothing else, so no request payload can grant it.
	 */
	public const PRIVATE_HOST_OPTION = 'cpsd_private_host';

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

	/**
	 * Seal one payload with the same HMAC primitive and key as the manifest.
	 *
	 * Used by the append-only ownership ledger (ScopedOwnership) so that every
	 * ownership record is tamper-evident without introducing a second secret.
	 *
	 * @param array<string,mixed> $payload Payload without `mac`.
	 * @return array<string,mixed> Payload with `mac` appended.
	 */
	public function seal( array $payload ): array {
		$payload['mac'] = $this->mac( $payload );

		return $payload;
	}

	/**
	 * Deterministic JSON used by the sealing helpers (exposed for diagnostics).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function canonical_form( $value ): string {
		return $this->canonical( $value );
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
		if ( ! $this->env->persist_verified( self::MODE_OPTION, $payload ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'نشانگر حالت محدودشده ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
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
	 * Record the administrator's explicit statement that this host is a private
	 * test site, which is what makes the single-Clinic anchor consequence an
	 * informed choice instead of a silent one.
	 *
	 * @param int    $actor_id Acting Administrator.
	 * @param string $host     Host name the administrator typed to confirm.
	 * @return void
	 * @throws Exception When the statement cannot be stored and verified.
	 */
	public function enable_private_host( int $actor_id, string $host ): void {
		// Fail closed at the write too, not only at the read: a record that
		// names any host but this one could never be honoured, so refuse it
		// here instead of storing a sealed record that silently does nothing.
		$host = strtolower( trim( $host ) );
		if ( '' === $host || $host !== self::host_of( $this->env->home_url() ) ) {
			throw new Exception( 'PRIVATE_HOST_MISMATCH', 'تأیید سایت آزمایشی خصوصی فقط برای دامنهٔ همین سایت ثبت می‌شود.' );
		}
		$payload = array(
			'enabled'  => true,
			'actor_id' => $actor_id,
			'at'       => $this->env->now_sql(),
			'site'     => $this->site_fingerprint(),
			'host'     => $host,
		);
		$payload['mac'] = $this->mac( $payload );
		if ( ! $this->env->persist_verified( self::PRIVATE_HOST_OPTION, $payload ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'ثبت تأیید سایت آزمایشی خصوصی ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
	}

	/**
	 * The verified private-test-site statement, or null when absent, tampered or
	 * belonging to a different site. Never trusts an unsealed value.
	 *
	 * @return array{actor_id:int,at:string,host:string}|null
	 */
	public function private_host_consent(): ?array {
		$raw = $this->env->option_get( self::PRIVATE_HOST_OPTION, false );
		if ( ! is_array( $raw ) || true !== ( $raw['enabled'] ?? false ) ) {
			return null;
		}
		$host = (string) ( $raw['host'] ?? '' );
		$mac  = (string) ( $raw['mac'] ?? '' );
		unset( $raw['mac'] );
		if ( '' === $mac || ! hash_equals( $this->mac( $raw ), $mac ) ) {
			return null;
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->site_fingerprint() ) {
			return null;
		}
		if ( '' === $host || strtolower( $host ) !== self::host_of( $this->env->home_url() ) ) {
			return null;
		}

		return array(
			'actor_id' => (int) ( $raw['actor_id'] ?? 0 ),
			'at'       => (string) ( $raw['at'] ?? '' ),
			'host'     => $host,
		);
	}

	public function disable_private_host(): void {
		$this->env->option_delete( self::PRIVATE_HOST_OPTION );
	}

	/**
	 * Normalised host of a site URL. The administrator types exactly this to
	 * accept the private-test-site consequence: their own host name, not a
	 * shared machine phrase, so the confirmation cannot be reused on a different
	 * site and needs no transcription of a long token.
	 *
	 * @param string $url Any site URL.
	 * @return string
	 */
	public static function host_of( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
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
		if ( ! $this->env->persist_verified( self::PENDING_BASELINE_OPTION, $payload ) ) {
			// Provisioning must not create a single synthetic row when the recovery
			// evidence it depends on was not durably stored.
			throw new Exception( 'OPTION_WRITE_FAILED', 'پایهٔ مرجع پیش از راه‌اندازی ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
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
