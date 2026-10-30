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
	 * Sealed archive of fixtures that were PARKED instead of resolved.
	 *
	 * WHY A PARKED RECORD EXISTS
	 * --------------------------
	 * A fixture can be complete enough to be unusable: its synthetic rows exist
	 * but its sealed baseline is gone, or its run stopped before the last write
	 * stage, so neither the additive nor the destructive direction can be proven
	 * from live state. The two dishonest answers are "delete it anyway" (which
	 * may take a real row with it) and "refuse everything for ever" (which leaves
	 * the Owner with no reachable demo). The third answer is to stop treating it
	 * as the live fixture: copy its ENTIRE sealed evidence - manifest plus the
	 * ownership ledger chain and its ratchet - into this record, drop only the
	 * live pointers, and provision a fresh fixture with its own 128-bit
	 * namespace. Nothing is adopted, nothing is modified, nothing is deleted, and
	 * the parked evidence is exactly what a later, explicit, human-reviewed
	 * resolution needs.
	 *
	 * The record is HMAC-sealed with the same key and bound to this site, so a
	 * copied or edited archive is refused on read, and no read path ever treats
	 * a parked fixture as live.
	 */
	public const PRESERVED_OPTION = 'cpsd_preserved';

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
	 * Park one fixture: archive its whole sealed evidence, then drop the live
	 * pointers. Never touches a user, a post or a CPMS data row.
	 *
	 * Order matters and is the same rule as everywhere else in this package: the
	 * archive is written and read back BEFORE anything live is released, so a
	 * failed archive write leaves the fixture exactly as it was. Parking the same
	 * fixture twice is idempotent.
	 *
	 * @param array<string,mixed> $manifest  Verified live manifest payload.
	 * @param string              $reason    Stable reason code from the assessment.
	 * @param int                 $actor_id  Acting Administrator.
	 * @return array<string,mixed> The archived record.
	 * @throws Exception When the payload does not verify, or the archive write fails.
	 */
	public function preserve( array $manifest, string $reason, int $actor_id ): array {
		if ( ! $this->verify( $manifest ) ) {
			// A record that does not verify is evidence of a tamper, not a fixture
			// that may be filed away as "ours but unusable".
			throw new Exception( 'MANIFEST_TAMPERED', 'سند مالکیت دمو معتبر نیست؛ کنارگذاشتن آن مجاز نیست.' );
		}
		$fixture = (string) ( $manifest['fixture_id'] ?? '' );
		if ( 1 !== preg_match( $this->fixture_pattern(), $fixture ) ) {
			throw new Exception( 'MANIFEST_FORMAT', 'شناسهٔ دمو برای کنارگذاشتن معتبر نیست.' );
		}
		$archive = $this->preserved_archive();
		foreach ( $archive['records'] as $index => $record ) {
			if ( (string) ( $record['fixture_id'] ?? '' ) === $fixture ) {
				// Already parked: return what is on disk, unchanged. A second click
				// must never append a duplicate.
				return $record;
			}
		}
		$archive['records'][] = array(
			'fixture_id'  => $fixture,
			'reason_code' => $reason,
			'actor_id'    => $actor_id,
			'at'          => $this->env->now_sql(),
			// The complete sealed payload: identity, ids, slugs, links, counts, the
			// sealed baseline when one survived, the ownership map and the failure
			// history. This is what a later, explicit, human-reviewed resolution
			// needs - and the reason nothing has to be deleted now.
			'manifest'    => $manifest,
			// The ledger chain is per-fixture and single-valued, so it moves with
			// the fixture. A fresh fixture must be able to own its own chain.
			'ledger'      => $this->env->option_get( ScopedOwnership::OPTION, null ),
			'ledger_seal' => $this->env->option_get( ScopedOwnership::SEAL_OPTION, null ),
		);
		$this->write_preserved( $archive );

		// Only now are the live pointers redundant. Removing them touches records,
		// never data.
		parent::delete();
		$this->env->option_delete( ScopedOwnership::OPTION );
		$this->env->option_delete( ScopedOwnership::SEAL_OPTION );
		$this->clear_pending_baseline();

		return $archive['records'][ count( $archive['records'] ) - 1 ];
	}

	/**
	 * Verified archive records. An absent archive is empty; a present archive that
	 * does not verify is an exception, never an empty list.
	 *
	 * @return list<array<string,mixed>>
	 * @throws Exception When the archive is tampered with, foreign or malformed.
	 */
	public function preserved(): array {
		return $this->preserved_archive()['records'];
	}

	/**
	 * @param string $fixture_id Fixture identity.
	 * @return array<string,mixed>|null
	 */
	public function preserved_record( string $fixture_id ): ?array {
		if ( '' === $fixture_id ) {
			return null;
		}
		foreach ( $this->preserved() as $record ) {
			if ( (string) ( $record['fixture_id'] ?? '' ) === $fixture_id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @return bool
	 */
	public function has_preserved(): bool {
		return is_array( $this->env->option_get( self::PRESERVED_OPTION, false ) );
	}

	/**
	 * Read + verify the archive.
	 *
	 * @return array{format:int,site:string,records:list<array<string,mixed>>,mac:string}
	 * @throws Exception On any tamper or format problem.
	 */
	private function preserved_archive(): array {
		$raw = $this->env->option_get( self::PRESERVED_OPTION, false );
		if ( false === $raw || null === $raw ) {
			return array(
				'format'  => 1,
				'site'    => $this->site_fingerprint(),
				'records' => array(),
				'mac'     => '',
			);
		}
		if ( ! is_array( $raw ) || 1 !== (int) ( $raw['format'] ?? 0 ) ) {
			throw new Exception( 'PRESERVED_INVALID', 'بایگانی دموهای کنارگذاشته‌شده معتبر نیست.' );
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->site_fingerprint() ) {
			throw new Exception( 'PRESERVED_INVALID', 'بایگانی دموهای کنارگذاشته‌شده به این سایت تعلق ندارد.' );
		}
		if ( ! $this->verify( $raw ) ) {
			throw new Exception( 'PRESERVED_INVALID', 'مهر بایگانی دموهای کنارگذاشته‌شده معتبر نیست (دست‌کاری شده است).' );
		}
		$records = (array) ( $raw['records'] ?? array() );
		$clean   = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || 1 !== preg_match( $this->fixture_pattern(), (string) ( $record['fixture_id'] ?? '' ) ) || ! is_array( $record['manifest'] ?? null ) ) {
				throw new Exception( 'PRESERVED_INVALID', 'ردیف بایگانی دموهای کنارگذاشته‌شده نامعتبر است.' );
			}
			$clean[] = $record;
		}
		$raw['records'] = $clean;

		return $raw;
	}

	/**
	 * Seal and durably store the archive, then prove it read back identical.
	 *
	 * @param array<string,mixed> $archive Archive without a fresh `mac`.
	 * @return void
	 * @throws Exception When the write is not durable.
	 */
	private function write_preserved( array $archive ): void {
		$archive['format'] = 1;
		$archive['site']   = $this->site_fingerprint();
		unset( $archive['mac'] );
		$archive['mac'] = $this->mac( $archive );
		if ( ! $this->env->persist_verified( self::PRESERVED_OPTION, $archive ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'بایگانی دموی کنارگذاشته‌شده ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
	}

	public function mode_record(): ?array {
		$raw = $this->env->option_get( self::MODE_OPTION, false );

		return is_array( $raw ) ? $raw : null;
	}
}
