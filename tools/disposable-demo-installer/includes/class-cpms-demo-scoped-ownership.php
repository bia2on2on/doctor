<?php
/**
 * Sealed, append-only ownership ledger for the scoped (non-empty-site) demo.
 *
 * WHY THIS EXISTS
 * ---------------
 * A scoped demo lives on a site that already had data, and it creates one
 * Organization with one Clinic. The management portal legitimately lets an
 * Organization manager create MORE Clinics under that Organization. Before this
 * ledger existed, the teardown claimed such a Clinic as demo-owned because it
 * named the demo Organization and had an id above the sealed baseline `max_id`.
 * That inference is unsound: a genuinely non-demo Clinic created under the demo
 * Organization after the seal looks exactly the same, and it would have been
 * deleted (or suspended and stripped of its actors) as if it were synthetic.
 *
 * The rule is therefore an explicit record, never an inference:
 *
 *   a Clinic is demo-owned when, and only when, the sealed manifest or this
 *   ledger names its exact id, or a sealed intent pre-committed its exact slug
 *   BEFORE the row could exist.
 *
 * PROPERTIES
 * ----------
 *  - Append-only: every record carries `seq`, the previous record's MAC and its
 *    own MAC, so inserting, reordering, editing or truncating any record breaks
 *    verification of the whole chain from a site+fixture-bound root.
 *  - No new secret: it reuses the manifest's HMAC key, site fingerprint and
 *    fixture identity, so a ledger copied from another site or another fixture
 *    does not verify.
 *  - Pre-committed intent: the exact slug of an additional Clinic is sealed
 *    BEFORE the row is created. If a run is interrupted between creation and
 *    registration, the pending intent says what to look for, and the teardown
 *    refuses (it never guesses) until an administrator resolves it explicitly.
 *  - Fail-closed: an absent ledger means "no additional Clinic is owned"; a
 *    present ledger that does not verify, or a pending intent, blocks the
 *    destructive teardown before anything is deleted.
 *
 * Nothing here stores a password, OTP, nonce, session token or PHI.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Sealed append-only ownership ledger.
 */
final class ScopedOwnership {

	/** Option holding the chain. Distinct from every manifest option. */
	public const OPTION = 'cpsd_ownership_ledger';

	/** Ledger format. A different value is refused, never migrated in place. */
	public const FORMAT = 1;

	/**
	 * Monotonic seal ratchet.
	 *
	 * The ledger alone cannot notice that the WHOLE option was replaced by an
	 * older, still perfectly sealed copy (a replay), or that it was deleted while
	 * the manifest still claims an active fixture. This small companion record -
	 * sealed with the same key and bound to the same site and fixture - stores the
	 * latest `count`/`head`. It is written after every append and checked before
	 * any append or teardown, so a shorter or different chain is refused instead
	 * of being mistaken for a legitimate history.
	 */
	public const SEAL_OPTION = 'cpsd_ownership_seal';

	/** Record kinds the verifier accepts. */
	public const KINDS = array( 'open', 'intent', 'clinic', 'abandon', 'rows' );

	/** @var Env */
	private Env $env;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	/**
	 * @param Env            $env      Environment.
	 * @param ScopedManifest $manifest Scoped manifest (HMAC key + site identity).
	 */
	public function __construct( Env $env, ScopedManifest $manifest ) {
		$this->env      = $env;
		$this->manifest = $manifest;
	}

	/**
	 * @return bool Whether any ledger record exists (valid or not).
	 */
	public function exists(): bool {
		return is_array( $this->env->option_get( self::OPTION, false ) );
	}

	/**
	 * Verified view of the ledger.
	 *
	 * An absent ledger is NOT a failure: it means no additional Clinic was ever
	 * registered, so no additional Clinic can be proven demo-owned. A present
	 * ledger that does not verify IS a failure and must block deletions.
	 *
	 * @param string $fixture_id     Fixture identity from the verified manifest.
	 * @param bool   $require_ledger Refuse a MISSING ledger (an active fixture must
	 *                               always have one; losing it loses ownership).
	 * @return array{ok:bool,code:string,detail:string,entries:list<array<string,mixed>>}
	 */
	public function inspect( string $fixture_id, bool $require_ledger = false ): array {
		$raw  = $this->env->option_get( self::OPTION, false );
		$seal = $this->env->option_get( self::SEAL_OPTION, false );
		if ( false === $raw || null === $raw ) {
			if ( $require_ledger || is_array( $seal ) ) {
				// The ledger is the only proof of which additional Clinics and which
				// demo-created rows belong to this fixture. If the manifest is active
				// and the record is gone, ownership is lost - refusing is the only
				// safe answer, and the seal (when it survived) proves it used to exist.
				return $this->invalid( 'دفتر مالکیت دمو ناپدید شده است؛ تا بازگردانی آن هیچ داده‌ای حذف نمی‌شود.' );
			}

			return array( 'ok' => true, 'code' => '', 'detail' => '', 'entries' => array() );
		}
		if ( ! is_array( $raw ) ) {
			return $this->invalid( 'شکل دادهٔ دفتر مالکیت نامعتبر است.' );
		}
		if ( (int) ( $raw['format'] ?? 0 ) !== self::FORMAT ) {
			return $this->invalid( 'نسخهٔ دفتر مالکیت پشتیبانی نمی‌شود.' );
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->manifest->site_fingerprint() ) {
			return $this->invalid( 'دفتر مالکیت به سایت دیگری تعلق دارد.' );
		}
		if ( '' === $fixture_id || (string) ( $raw['fixture_id'] ?? '' ) !== $fixture_id ) {
			return $this->invalid( 'دفتر مالکیت به این دمو تعلق ندارد.' );
		}
		if ( ! $this->manifest->verify( $raw ) ) {
			return $this->invalid( 'مهر دفتر مالکیت معتبر نیست (دست‌کاری شده است).' );
		}
		$entries = (array) ( $raw['entries'] ?? array() );
		if ( (int) ( $raw['count'] ?? -1 ) !== count( $entries ) ) {
			return $this->invalid( 'تعداد ردیف‌های دفتر مالکیت با مهر آن هم‌خوان نیست.' );
		}
		if ( (string) ( $raw['head'] ?? '' ) !== ( array() === $entries ? '' : (string) ( $entries[ count( $entries ) - 1 ]['mac'] ?? '' ) ) ) {
			return $this->invalid( 'سر زنجیرهٔ دفتر مالکیت با مهر آن هم‌خوان نیست.' );
		}
		$verified = $this->verify_seal( $fixture_id, $raw );
		if ( '' !== $verified ) {
			return $this->invalid( $verified );
		}
		$prev    = $this->root_mac( $fixture_id );
		$seq     = 1;
		$clean   = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				return $this->invalid( 'ردیف دفتر مالکیت نامعتبر است.' );
			}
			if ( (int) ( $entry['seq'] ?? 0 ) !== $seq ) {
				return $this->invalid( 'ترتیب ردیف‌های دفتر مالکیت ناپیوسته است.' );
			}
			if ( ! in_array( (string) ( $entry['kind'] ?? '' ), self::KINDS, true ) ) {
				return $this->invalid( 'نوع ردیف دفتر مالکیت ناشناخته است.' );
			}
			if ( (string) ( $entry['prev'] ?? '' ) !== $prev ) {
				return $this->invalid( 'زنجیرهٔ دفتر مالکیت گسسته است.' );
			}
			if ( ! $this->manifest->verify( $entry ) ) {
				return $this->invalid( 'مهر ردیف دفتر مالکیت معتبر نیست.' );
			}
			$prev    = (string) ( $entry['mac'] ?? '' );
			$clean[] = $entry;
			++$seq;
		}

		return array( 'ok' => true, 'code' => '', 'detail' => '', 'entries' => $clean );
	}

	/**
	 * Ownership decision derived from the verified ledger.
	 *
	 * @param string $fixture_id     Fixture identity.
	 * @param bool   $require_ledger Refuse a missing ledger (active fixture).
	 * @return array{ok:bool,code:string,detail:string,clinic_ids:list<int>,row_keys:array<string,array<int,bool>>,pending:list<array{token:string,slug:string,organization_id:int}>,organization_id:int}
	 */
	public function summary( string $fixture_id, bool $require_ledger = false ): array {
		$result = $this->inspect( $fixture_id, $require_ledger );
		$empty  = array(
			'ok'              => $result['ok'],
			'code'            => $result['code'],
			'detail'          => $result['detail'],
			'clinic_ids'      => array(),
			'row_keys'        => array(),
			'pending'         => array(),
			'organization_id' => 0,
		);
		if ( ! $result['ok'] ) {
			return $empty;
		}

		$clinic_ids = array();
		$row_keys   = array();
		$pending    = array();
		$resolved   = array();
		$org        = 0;
		foreach ( $result['entries'] as $entry ) {
			$payload = (array) ( $entry['payload'] ?? array() );
			$kind    = (string) ( $entry['kind'] ?? '' );
			if ( 'open' === $kind ) {
				$org = max( $org, (int) ( $payload['organization_id'] ?? 0 ) );
				$primary = (int) ( $payload['clinic_id'] ?? 0 );
				if ( $primary > 1 ) {
					$clinic_ids[] = $primary;
				}
				continue;
			}
			$token = (string) ( $payload['token'] ?? '' );
			if ( 'intent' === $kind ) {
				$pending[ $token ] = array(
					'token'           => $token,
					'slug'            => (string) ( $payload['slug'] ?? '' ),
					'organization_id' => (int) ( $payload['organization_id'] ?? 0 ),
				);
				continue;
			}
			if ( 'clinic' === $kind ) {
				$clinic_id = (int) ( $payload['clinic_id'] ?? 0 );
				if ( $clinic_id > 1 ) {
					$clinic_ids[] = $clinic_id;
				}
				$resolved[ $token ] = true;
				continue;
			}
			if ( 'rows' === $kind ) {
				// Explicitly registered demo-created rows: the durable ownership
				// evidence a controlled demo workflow (or the demo's own request
				// recorder) writes for records that no structural rule can prove.
				$table = (string) ( $payload['table'] ?? '' );
				if ( '' !== $table ) {
					foreach ( (array) ( $payload['ids'] ?? array() ) as $row_id ) {
						$row_keys[ $table ][ (int) $row_id ] = true;
					}
				}
				continue;
			}
			// 'abandon' closes an intent without naming a Clinic.
			$resolved[ $token ] = true;
		}
		foreach ( array_keys( $resolved ) as $token ) {
			unset( $pending[ (string) $token ] );
		}

		return array(
			'ok'              => true,
			'code'            => '',
			'detail'          => '',
			'clinic_ids'      => array_values( array_unique( array_filter( array_map( 'intval', $clinic_ids ) ) ) ),
			'row_keys'        => $row_keys,
			'pending'         => array_values( $pending ),
			'organization_id' => $org,
		);
	}

	/**
	 * Open the ledger for a freshly provisioned fixture.
	 *
	 * @param string $fixture_id      Fixture identity.
	 * @param int    $organization_id Demo Organization.
	 * @param int    $clinic_id       Demo Clinic.
	 * @return array<string,mixed> Sealed record.
	 */
	public function open( string $fixture_id, int $organization_id, int $clinic_id ): array {
		$current = $this->inspect( $fixture_id, false );
		if ( ! $current['ok'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت دمو معتبر نیست؛ عملیات متوقف شد. ' . $current['detail'] );
		}
		if ( array() !== $current['entries'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت این دمو از قبل باز شده است.' );
		}
		if ( $organization_id <= 1 || $clinic_id <= 1 ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'شناسه‌های دمو برای ثبت مالکیت معتبر نیستند.' );
		}

		return $this->push(
			$fixture_id,
			'open',
			array(
				'organization_id' => $organization_id,
				'clinic_id'       => $clinic_id,
			)
		);
	}

	/**
	 * Append one verified record.
	 *
	 * @param string              $fixture_id Fixture identity.
	 * @param string              $kind       One of self::KINDS.
	 * @param array<string,mixed> $payload    Record payload.
	 * @return array<string,mixed> Sealed record.
	 */
	public function append( string $fixture_id, string $kind, array $payload ): array {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'نوع ردیف دفتر مالکیت ناشناخته است.' );
		}

		return $this->push( $fixture_id, $kind, $payload );
	}

	/**
	 * Remove the ledger (only after a verified clean teardown).
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->env->option_delete( self::OPTION );
		$this->env->option_delete( self::SEAL_OPTION );
	}

	/**
	 * Append an explicit ownership registration for concrete demo-created rows.
	 *
	 * This is the durable evidence for records that no structural rule can prove
	 * (a patient a demo persona registered at the front desk, a capability row the
	 * demo itself wrote). It is written BY the demo workflow that created the
	 * rows, before any teardown can run, so the teardown never has to guess.
	 *
	 * @param string                $fixture_id Fixture identity.
	 * @param string                $table      Short table name.
	 * @param list<int>             $ids        Row ids.
	 * @param array<string,mixed>   $context    Optional context (why/who).
	 * @return array<string,mixed>|\WP_Error|null Sealed record, or null when there is nothing to add.
	 */
	public function register_rows( string $fixture_id, string $table, array $ids, array $context = array() ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( '' === $table || array() === $ids ) {
			return null;
		}
		$current = $this->summary( $fixture_id, true );
		if ( ! $current['ok'] ) {
			throw new Exception( (string) $current['code'], $current['detail'] );
		}
		$known = array();
		foreach ( $ids as $id ) {
			if ( isset( $current['row_keys'][ $table ][ $id ] ) ) {
				$known[] = $id;
			}
		}
		$ids = array_values( array_diff( $ids, $known ) );
		if ( array() === $ids ) {
			return null;
		}

		return $this->append(
			$fixture_id,
			'rows',
			array_merge(
				$context,
				array(
					'table' => $table,
					'ids'   => $ids,
				)
			)
		);
	}

	/**
	 * Root of the chain: bound to this site AND this fixture, sealed with the
	 * manifest HMAC key.
	 *
	 * @param string $fixture_id Fixture identity.
	 * @return string
	 */
	private function root_mac( string $fixture_id ): string {
		return hash_hmac(
			'sha256',
			'cpsd-ownership-root|' . $this->manifest->site_fingerprint() . '|' . $fixture_id,
			$this->env->mac_key()
		);
	}

	/**
	 * @param string              $fixture_id Fixture identity.
	 * @param string              $kind       Record kind.
	 * @param array<string,mixed> $payload    Payload.
	 * @return array<string,mixed> Sealed record.
	 */
	private function push( string $fixture_id, string $kind, array $payload ): array {
		$current = $this->inspect( $fixture_id );
		if ( ! $current['ok'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت دمو معتبر نیست؛ عملیات متوقف شد. ' . $current['detail'] );
		}
		$entries = $current['entries'];
		$prev    = array() === $entries
			? $this->root_mac( $fixture_id )
			: (string) ( $entries[ count( $entries ) - 1 ]['mac'] ?? '' );
		$entry = $this->manifest->seal(
			array(
				'seq'     => count( $entries ) + 1,
				'kind'    => $kind,
				'at'      => $this->env->now_sql(),
				'payload' => $payload,
				'prev'    => $prev,
			)
		);
		$entries[] = $entry;
		$record    = $this->manifest->seal(
			array(
				'format'     => self::FORMAT,
				'site'       => $this->manifest->site_fingerprint(),
				'fixture_id' => $fixture_id,
				'entries'    => $entries,
				// Chain head and length are committed by the record's own MAC, so
				// truncating or extending the tail is detected as tampering instead
				// of being mistaken for a shorter, still-consistent history.
				'count'      => count( $entries ),
				'head'       => array() === $entries ? '' : (string) ( $entries[ count( $entries ) - 1 ]['mac'] ?? '' ),
			)
		);
		// Ownership state that was not durably stored is not ownership state: the
		// caller must not create (or delete) a row on the strength of a record that
		// reads back different from what was just written.
		if ( ! $this->env->persist_verified( self::OPTION, $record ) ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_WRITE', 'دفتر مالکیت دمو ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
		// The ratchet is written second and verified too: a ledger that advanced
		// while its seal did not would let an older sealed copy be swapped in
		// unnoticed, so the pair must always agree.
		$seal = $this->manifest->seal(
			array(
				'format'     => self::FORMAT,
				'fixture_id' => $fixture_id,
				'count'      => count( $entries ),
				'head'       => (string) ( $record['head'] ?? '' ),
			)
		);
		if ( ! $this->env->persist_verified( self::SEAL_OPTION, $seal ) ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_WRITE', 'مهر پیشرفت دفتر مالکیت دمو ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}

		return $entry;
	}

	/**
	 * Verify the seal ratchet against a ledger record.
	 *
	 * @param string              $fixture_id Fixture identity.
	 * @param array<string,mixed> $raw        Stored ledger record.
	 * @return string Persian reason when it does not verify, '' otherwise.
	 */
	private function verify_seal( string $fixture_id, array $raw ): string {
		$seal = $this->env->option_get( self::SEAL_OPTION, false );
		if ( false === $seal || null === $seal ) {
			// No ratchet yet: only a ledger written before this format is allowed,
			// and only when it is the very first record (nothing to roll back to).
			return (int) ( $raw['count'] ?? 0 ) <= 1
				? ''
				: 'مهر پیشرفت دفتر مالکیت دمو موجود نیست؛ مالکیت قابل اثبات نیست.';
		}
		if ( ! is_array( $seal ) || ! $this->manifest->verify( $seal ) ) {
			return 'مهر پیشرفت دفتر مالکیت دمو معتبر نیست (دست‌کاری شده است).';
		}
		if ( (string) ( $seal['fixture_id'] ?? '' ) !== $fixture_id ) {
			return 'مهر پیشرفت دفتر مالکیت به این دمو تعلق ندارد.';
		}
		if ( (int) ( $seal['count'] ?? -1 ) !== (int) ( $raw['count'] ?? -2 ) ) {
			return 'شمار ردیف‌های دفتر مالکیت با مهر پیشرفت آن هم‌خوان نیست (نسخهٔ قدیمی یا دست‌کاری‌شده).';
		}
		if ( (string) ( $seal['head'] ?? '' ) !== (string) ( $raw['head'] ?? '' ) ) {
			return 'سر زنجیرهٔ دفتر مالکیت با مهر پیشرفت آن هم‌خوان نیست (نسخهٔ قدیمی یا دست‌کاری‌شده).';
		}

		return '';
	}

	/**
	 * @param string $detail Human-readable reason (Persian).
	 * @return array{ok:bool,code:string,detail:string,entries:list<array<string,mixed>>}
	 */
	private function invalid( string $detail ): array {
		return array(
			'ok'     => false,
			'code'   => 'SCOPED_OWNERSHIP_LEDGER_INVALID',
			'detail' => $detail,
			'entries' => array(),
		);
	}
}
