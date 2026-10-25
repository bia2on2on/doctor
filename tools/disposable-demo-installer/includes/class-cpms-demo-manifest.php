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

defined( 'ABSPATH' ) || exit;

/**
 * Manifest storage + integrity.
 */
class Manifest {

	public const OPTION    = 'cpdd_manifest';
	public const TOMBSTONE = 'cpdd_tombstone';
	public const FORMAT    = 1;

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
		return is_array( $this->env->option_get( self::OPTION, false ) );
	}

	/**
	 * Load and fully verify the manifest.
	 *
	 * @param bool $require_site_match Refuse when the manifest belongs to another site.
	 * @return array<string,mixed>|null
	 * @throws Exception On tampering or site mismatch.
	 */
	public function load( bool $require_site_match = true ): ?array {
		$raw = $this->env->option_get( self::OPTION, false );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( ! $this->verify( $raw ) ) {
			throw new Exception( 'MANIFEST_TAMPERED', 'فایل مالکیت دمو دست‌کاری شده یا معتبر نیست؛ عملیات مخرب مسدود شد.' );
		}
		if ( (int) ( $raw['format'] ?? 0 ) !== self::FORMAT ) {
			throw new Exception( 'MANIFEST_FORMAT', 'نسخهٔ قالب مالکیت دمو پشتیبانی نمی‌شود.' );
		}
		$fixture = (string) ( $raw['fixture_id'] ?? '' );
		if ( preg_match( '/^[a-f0-9]{16}$/', $fixture ) !== 1 ) {
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
	private function mac( array $data ): string {
		return hash_hmac( 'sha256', $this->canonical( $data ), $this->env->mac_key() );
	}

	/**
	 * Deterministic JSON: keys sorted, no escaped unicode/slashes.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function canonical( $value ): string {
		return (string) wp_json_encode( $this->sorted( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sorted( $value ) {
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
		$data['format']         = self::FORMAT;
		$data['plugin_version'] = CPDD_VERSION;
		$data['site']           = $this->site_block();
		$data['updated_at']     = $this->env->now_sql();
		$data['mac']            = $this->mac( $data );
		$this->env->option_set( self::OPTION, $data );

		return $data;
	}

	/**
	 * @return void
	 */
	public function delete(): void {
		$this->env->option_delete( self::OPTION );
	}

	/**
	 * Post-teardown audit record. Contains counters and identity only.
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return void
	 */
	public function write_tombstone( array $summary ): void {
		$summary['at']     = $this->env->now_sql();
		$summary['site']   = $this->site_fingerprint();
		$summary['mac']    = $this->mac( $summary );
		$this->env->option_set( self::TOMBSTONE, $summary );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function tombstone(): ?array {
		$raw = $this->env->option_get( self::TOMBSTONE, false );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * @return string Human-readable fixture prefix used in slugs/logins.
	 */
	public function fixture_prefix(): string {
		return 'cpdd-';
	}
}
