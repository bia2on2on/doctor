<?php
/**
 * Full-chain verification for the global append-only CPMS audit log.
 *
 * The scoped teardown never deletes or updates audit rows. This guard reads the
 * entire chain in bounded chunks and verifies the production HashChain contract
 * from genesis, rather than relying on a manifest-owned subset.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

use ClinicCore\Infrastructure\Audit\HashChain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Read-only audit-chain verifier.
 */
class ScopedAuditGuard {

	private const CHUNK = 1000;

	/** @var Platform */
	private Platform $platform;

	public function __construct( Platform $platform ) {
		$this->platform = $platform;
	}

	/**
	 * Verify every audit row in ascending ID order.
	 *
	 * @return array{ok:bool,checked:int,broken_at:int|null,tail:string,error:string}
	 */
	public function verify_chain(): array {
		if ( ! class_exists( HashChain::class ) ) {
			return array(
				'ok'        => false,
				'checked'   => 0,
				'broken_at' => null,
				'tail'      => '',
				'error'     => 'AUDIT_CHAIN_UNAVAILABLE',
			);
		}

		$table   = $this->platform->table( 'cpms_audit_logs' );
		$last_id = 0;
		$prev    = HashChain::GENESIS;
		$checked = 0;
		$offset  = 0;

		while ( true ) {
			$rows = $this->platform->fetch_all(
				'SELECT * FROM `' . $table . '` WHERE id > %d ORDER BY id ASC LIMIT %d',
				array( $last_id, self::CHUNK )
			);
			if ( array() === $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				++$offset;
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id <= 0 || $id <= $last_id ) {
					return $this->broken( $offset );
				}
				if ( ! hash_equals( $prev, (string) ( $row['prev_hash'] ?? '' ) ) ) {
					return $this->broken( $offset );
				}
				$expected = HashChain::computeRowHash( $prev, HashChain::fieldsFor( $row ) );
				$actual   = (string) ( $row['row_hash'] ?? '' );
				if ( ! hash_equals( $expected, $actual ) ) {
					return $this->broken( $offset );
				}
				$prev    = $actual;
				$last_id = $id;
				++$checked;
			}
			if ( count( $rows ) < self::CHUNK ) {
				break;
			}
		}

		return array(
			'ok'        => true,
			'checked'   => $checked,
			'broken_at' => null,
			'tail'      => $prev,
			'error'     => '',
		);
	}

	/**
	 * SHA-256 over the canonical full rows of the immutable baseline prefix.
	 *
	 * @param int $max_id Highest audit ID that existed before provisioning.
	 * @return array{ok:bool,count:int,digest:string,error:string}
	 */
	public function baseline_prefix( int $max_id ): array {
		$table = $this->platform->table( 'cpms_audit_logs' );
		$rows  = $this->platform->fetch_all(
			'SELECT * FROM `' . $table . '` WHERE id <= %d ORDER BY id ASC',
			array( $max_id )
		);
		$hash  = hash_init( 'sha256' );
		foreach ( $rows as $row ) {
			hash_update( $hash, $this->canonical_row( $row ) . "\n" );
		}

		return array(
			'ok'     => true,
			'count'  => count( $rows ),
			'digest' => hash_final( $hash ),
			'error'  => '',
		);
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return string
	 */
	public function canonical_row( array $row ): string {
		ksort( $row );

		return (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param int $offset One-based row position.
	 * @return array{ok:bool,checked:int,broken_at:int,tail:string,error:string}
	 */
	private function broken( int $offset ): array {
		return array(
			'ok'        => false,
			'checked'   => $offset,
			'broken_at' => $offset,
			'tail'      => '',
			'error'     => 'AUDIT_CHAIN_BROKEN',
		);
	}
}
