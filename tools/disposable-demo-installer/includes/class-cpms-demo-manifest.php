<?php
/**
 * Tamper-evident ownership manifest for one synthetic demo.
 *
 * The manifest records *identity and ownership*, never credentials: no password,
 * OTP code, pepper, session token or nonce is ever written to it. It is sealed
 * with an HMAC keyed by the site's own auth salt, so a hand-edited or
 * transplanted manifest (another site, another database, another prefix, edited
 * object ids) fails verification and every destructive path refuses.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Manifest storage + integrity.
 */
class Manifest {

	public const OPTION         = 'cpdd_manifest';
	public const TOMBSTONE      = 'cpdd_tombstone';
	public const CLEAN_TEARDOWN = 'cpdd_had_clean_teardown';
	public const FORMAT         = 1;

	/**
	 * @var Env
	 */
	protected Env $env;

	/** Storage option names and manifest format (overridable by scoped mode). */
	protected string $option;
	protected string $tombstone_option;
	protected string $clean_option;
	protected int $format;

	/**
	 * @param Env $env Environment.
	 */
	public function __construct( Env $env ) {
		$this->env             = $env;
		$this->option          = self::OPTION;
		$this->tombstone_option = self::TOMBSTONE;
		$this->clean_option    = self::CLEAN_TEARDOWN;
		$this->format          = self::FORMAT;
	}

	/**
	 * @return string Stable identity of this WordPress site + database + prefix.
	 */
	public function site_fingerprint(): string {
		return substr(
			hash(
				'sha256',
				$this->env->home_url() . '|' . $this->env->site_url() . '|' . $this->env->db_name() . '|' . $this->env->table_prefix()
			),
			0,
			32
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function site_block(): array {
		return array(
			'home_url'       => $this->env->home_url(),
			'site_url'       => $this->env->site_url(),
			'db_name'        => $this->env->db_name(),
			'table_prefix'   => $this->env->table_prefix(),
			'fingerprint'    => $this->site_fingerprint(),
			'php_version'    => $this->env->php_version(),
			'wp_version'     => $this->env->wp_version(),
		);
	}

	/**
	 * @return bool
	 */
	public function exists(): bool {
		return is_array( $this->env->option_get( $this->option, false ) );
	}

	/**
	 * Load and fully verify the manifest.
	 *
	 * @param bool $require_site_match Refuse when the manifest belongs to another site.
	 * @return array<string,mixed>|null
	 * @throws Exception On tampering or site mismatch.
	 */
	public function load( bool $require_site_match = true ): ?array {
		$raw = $this->env->option_get( $this->option, false );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( ! $this->verify( $raw ) ) {
			throw new Exception( 'MANIFEST_TAMPERED', 'فایل مالکیت دمو دست‌کاری شده یا معتبر نیست؛ عملیات مخرب مسدود شد.' );
		}
		if ( (int) ( $raw['format'] ?? 0 ) !== $this->format ) {
			throw new Exception( 'MANIFEST_FORMAT', 'نسخهٔ قالب مالکیت دمو پشتیبانی نمی‌شود.' );
		}
		$fixture = (string) ( $raw['fixture_id'] ?? '' );
		if ( preg_match( $this->fixture_pattern(), $fixture ) !== 1 ) {
			throw new Exception( 'MANIFEST_FORMAT', 'شناسهٔ دمو در فایل مالکیت معتبر نیست.' );
		}
		if ( $require_site_match && (string) ( $raw['site']['fingerprint'] ?? '' ) !== $this->site_fingerprint() ) {
			throw new Exception( 'MANIFEST_SITE_MISMATCH', 'این فایل مالکیت به سایت/دیتابیس/پیشوند دیگری تعلق دارد؛ عملیات متوقف شد.' );
		}

		return $raw;
	}

	/**
	 * @param array<string,mixed> $data Manifest.
	 * @return bool
	 */
	public function verify( array $data ): bool {
		$mac = isset( $data['mac'] ) && is_string( $data['mac'] ) ? $data['mac'] : '';
		if ( $mac === '' ) {
			return false;
		}
		unset( $data['mac'] );

		try {
			$expected = $this->mac( $data );
		} catch ( Exception $e ) {
			unset( $e );

			return false;
		}

		return hash_equals( $expected, $mac );
	}

	/**
	 * @param array<string,mixed> $data Payload without mac.
	 * @return string
	 */
	protected function mac( array $data ): string {
		return hash_hmac( 'sha256', $this->canonical( $data ), $this->env->mac_key() );
	}

	/**
	 * Deterministic JSON: keys sorted, no escaped unicode/slashes.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function canonical( $value ): string {
		return (string) wp_json_encode( $this->sorted( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sorted( $item );
		}

		return $value;
	}

	/**
	 * Persist + seal a manifest.
	 *
	 * @param array<string,mixed> $data Manifest payload.
	 * @return array<string,mixed> Sealed manifest as stored.
	 */
	public function save( array $data ): array {
		unset( $data['mac'] );
		$data['format']         = $this->format;
		$data['plugin_version'] = CPMS_DEMO_VERSION;
		$data['site']           = $this->site_block();
		$data['updated_at']     = $this->env->now_sql();
		$data['mac']            = $this->mac( $data );
		if ( ! $this->env->persist_verified( $this->option, $data ) ) {
			// Ownership state that was not durably stored is not ownership state.
			// Callers must not proceed to create or delete rows on the strength of
			// a manifest that reads back different from what they just wrote.
			throw new Exception( 'OPTION_WRITE_FAILED', 'فایل مالکیت دمو ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}

		return $data;
	}

	/**
	 * @return void
	 */
	public function delete(): void {
		$this->env->option_delete( $this->option );
	}

	/**
	 * Post-teardown audit record. Contains counters and identity only.
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return void
	 */
	public function write_tombstone( array $summary ): void {
		unset( $summary['mac'] );
		$summary['at']   = $this->env->now_sql();
		$summary['site'] = $this->site_fingerprint();
		$summary['mac']  = $this->mac( $summary );
		if ( ! $this->env->persist_verified( $this->tombstone_option, $summary ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'سند پاک‌سازی ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
	}

	/**
	 * Load and verify the post-teardown tombstone record.
	 *
	 * Verifies both the HMAC seal and the current site fingerprint before
	 * trusting the tombstone. Returns null when absent, tampered, or belonging
	 * to another site/database/table prefix.
	 *
	 * @return array<string,mixed>|null
	 */
	public function tombstone(): ?array {
		$raw = $this->env->option_get( $this->tombstone_option, false );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( ! $this->verify( $raw ) ) {
			return null;
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->site_fingerprint() ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Dismiss the visible tombstone card. When the dismissed tombstone was a
	 * verified clean teardown for this exact site, preserve a sealed,
	 * site-fingerprinted clean-teardown marker so reprovisioning on the same
	 * disposable site remains possible after shared-IP rate-limit preservation.
	 *
	 * @return void
	 */
	public function clear_tombstone(): void {
		$valid = $this->tombstone();
		if ( is_array( $valid ) && true === ( $valid['clean'] ?? $valid['ok'] ?? false ) ) {
			$marker        = array(
				'clean'      => true,
				'fixture_id' => (string) ( $valid['fixture_id'] ?? '' ),
				'at'         => (string) ( $valid['at'] ?? $this->env->now_sql() ),
				'site'       => $this->site_fingerprint(),
			);
			$marker['mac'] = $this->mac( $marker );
			$this->env->option_set( $this->clean_option, $marker );
		}
		$this->env->option_delete( $this->tombstone_option );
	}

	/**
	 * Whether this exact site has a verified, untampered clean-teardown record.
	 *
	 * If a raw `cpdd_tombstone` option is present, it must pass HMAC and
	 * site-fingerprint verification (`tombstone() !== null`) and report clean
	 * completion; an invalid or cross-site tombstone always fails closed.
	 *
	 * @return bool
	 */
	public function had_clean_teardown(): bool {
		$raw_tombstone = $this->env->option_get( $this->tombstone_option, false );
		if ( false !== $raw_tombstone && null !== $raw_tombstone ) {
			$verified = $this->tombstone();

			return is_array( $verified ) && true === ( $verified['clean'] ?? $verified['ok'] ?? false );
		}

		$marker = $this->env->option_get( $this->clean_option, false );
		if ( ! is_array( $marker ) || ! $this->verify( $marker ) ) {
			return false;
		}
		if ( (string) ( $marker['site'] ?? '' ) !== $this->site_fingerprint() ) {
			return false;
		}

		return true === ( $marker['clean'] ?? false );
	}

	/**
	 * @return string Human-readable fixture prefix used in slugs/logins.
	 */
	public function fixture_prefix(): string {
		return 'cpdd-';
	}

	/** Fixture-ID regex; scoped mode overrides this to 32 hex characters. */
	public function fixture_pattern(): string {
		return '/^[a-f0-9]{16}$/D';
	}

	/** CSPRNG fixture identity; scoped mode overrides this to 128 bits. */
	public function fixture_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	public function audit_action(): string {
		return 'CPDD_DEMO_PROVISIONED';
	}

	public function otp_audit_action(): string {
		return 'CPDD_DEMO_OTP_REBOUND';
	}
}
