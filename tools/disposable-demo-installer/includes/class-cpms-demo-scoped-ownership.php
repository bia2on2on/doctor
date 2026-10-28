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

	/** Record kinds the verifier accepts. */
	public const KINDS = array( 'open', 'intent', 'clinic', 'abandon' );

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
	 * @param string $fixture_id Fixture identity from the verified manifest.
	 * @return array{ok:bool,code:string,detail:string,entries:list<array<string,mixed>>}
	 */
	public function inspect( string $fixture_id ): array {
		$raw = $this->env->option_get( self::OPTION, false );
		if ( false === $raw || null === $raw ) {
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
	 * @param string $fixture_id Fixture identity.
	 * @return array{ok:bool,code:string,detail:string,clinic_ids:list<int>,pending:list<array{token:string,slug:string,organization_id:int}>,organization_id:int}
	 */
	public function summary( string $fixture_id ): array {
		$result = $this->inspect( $fixture_id );
		$empty  = array(
			'ok'              => $result['ok'],
			'code'            => $result['code'],
			'detail'          => $result['detail'],
			'clinic_ids'      => array(),
			'pending'         => array(),
			'organization_id' => 0,
		);
		if ( ! $result['ok'] ) {
			return $empty;
		}

		$clinic_ids = array();
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
		$current = $this->inspect( $fixture_id );
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

		return $entry;
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
