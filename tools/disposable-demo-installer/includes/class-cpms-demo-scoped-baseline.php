<?php
/**
 * Non-secret baseline snapshots for the scoped demo.
 *
 * Stores only IDs, row counts and SHA-256 digests. It never stores raw PHI or
 * credentials. Auto-increment tables are verified by an immutable ID prefix;
 * tables without a surrogate ID are verified by exact keyed row hashes, so
 * legitimate foreign activity can append rows without invalidating the baseline.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only baseline capture and post-teardown preservation check.
 */
class ScopedBaseline {

	private const CHUNK = 1000;

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	public function __construct( Env $env, Platform $platform ) {
		$this->env      = $env;
		$this->platform = $platform;
	}

	/**
	 * Capture baseline digests for all known CPMS tables and core WP record tables.
	 *
	 * @return array{cpms:array<string,array<string,mixed>>,wp:array<string,array<string,mixed>>}
	 */
	public function snapshot(): array {
		$out = array( 'cpms' => array(), 'wp' => array() );
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( $this->platform->table_exists( $short ) ) {
				$out['cpms'][ $short ] = $this->snapshot_table( $this->platform->table( $short ) );
			}
		}
		global $wpdb;
		$wp_tables = array(
			'users',
			'usermeta',
			'posts',
			'postmeta',
			'comments',
			'commentmeta',
			'terms',
			'termmeta',
			'term_taxonomy',
			'term_relationships',
			'options',
			'links',
		);
		foreach ( $wp_tables as $suffix ) {
			$table = (string) $wpdb->prefix . $suffix;
			if ( $this->wp_table_exists( $table ) ) {
				$out['wp'][ $suffix ] = $this->snapshot_table( $table );
			}
		}

		return $out;
	}

	/**
	 * Verify that every baseline row is still present and unchanged.
	 *
	 * @param array{cpms:array<string,array<string,mixed>>,wp:array<string,array<string,mixed>>} $baseline Baseline.
	 * @return array{ok:bool,problems:list<string>,checked:int}
	 */
	public function verify( array $baseline ): array {
		$problems = array();
		$checked  = 0;
		foreach ( array( 'cpms', 'wp' ) as $group ) {
			foreach ( (array) ( $baseline[ $group ] ?? array() ) as $name => $expected ) {
				++$checked;
				$table = 'cpms' === $group ? $this->platform->table( (string) $name ) : ( (string) $GLOBALS['wpdb']->prefix . $name );
				$actual = $this->snapshot_table( $table, (string) ( $expected['id_col'] ?? '' ), (int) ( $expected['max_id'] ?? 0 ) );
				if ( 'prefix' === (string) ( $expected['strategy'] ?? '' ) ) {
					if ( (int) ( $actual['max_id'] ?? -1 ) < (int) ( $expected['max_id'] ?? 0 ) ) {
						$problems[] = $group . ':' . $name . ' baseline ID prefix shrank';
						continue;
					}
					if ( (string) ( $actual['digest'] ?? '' ) !== (string) ( $expected['digest'] ?? '' ) ) {
						$problems[] = $group . ':' . $name . ' baseline row digest changed';
					}
					continue;
				}
				$expected_keys = (array) ( $expected['keys'] ?? array() );
				$actual_keys   = (array) ( $actual['keys'] ?? array() );
				foreach ( $expected_keys as $key => $digest ) {
					if ( ! isset( $actual_keys[ $key ] ) || ! hash_equals( (string) $digest, (string) $actual_keys[ $key ] ) ) {
						$problems[] = $group . ':' . $name . ' baseline keyed row changed';
						break;
					}
				}
			}
		}

		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'checked'  => $checked,
		);
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function wp_table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return (string) $found === $table;
	}

	/**
	 * @param string $table Full table name.
	 * @param string $force_id Optional known ID column.
	 * @param int $max_id Optional baseline max ID for re-verification.
	 * @return array<string,mixed>
	 */
	private function snapshot_table( string $table, string $force_id = '', int $max_id = 0 ): array {
		global $wpdb;
		$id_col = '' !== $force_id ? $force_id : $this->auto_increment_column( $table );
		if ( '' !== $id_col ) {
			$limit = $max_id > 0 ? $max_id : (int) $wpdb->get_var( 'SELECT COALESCE(MAX(`' . $id_col . '`), 0) FROM `' . $table . '`' );
			$digest = $this->prefix_digest( $table, $id_col, $limit );

			return array(
				'strategy' => 'prefix',
				'id_col'   => $id_col,
				'max_id'   => $limit,
				'count'    => $this->prefix_count( $table, $id_col, $limit ),
				'digest'   => $digest,
			);
		}

		$keys = $this->keyed_rows( $table );
		$hash = hash_init( 'sha256' );
		foreach ( $keys as $key => $digest ) {
			hash_update( $hash, $key . '=' . $digest . "\n" );
		}

		return array(
			'strategy' => 'keys',
			'keys'     => $keys,
			'count'    => count( $keys ),
			'digest'   => hash_final( $hash ),
		);
	}

	/**
	 * @param string $table Full table name.
	 * @return string
	 */
	private function auto_increment_column( string $table ): string {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
		foreach ( (array) $cols as $col ) {
			if ( false !== stripos( (string) ( $col['Extra'] ?? '' ), 'auto_increment' ) ) {
				return (string) $col['Field'];
			}
		}

		return '';
	}

	/**
	 * @param string $table Full table name.
	 * @param string $id_col ID column.
	 * @param int $max_id Max baseline ID.
	 * @return string
	 */
	private function prefix_digest( string $table, string $id_col, int $max_id ): string {
		global $wpdb;
		$where = '`' . $id_col . '` <= %d';
		$params = array( $max_id );
		if ( $this->is_options_table( $table ) ) {
			$where .= " AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'";
			$where .= " AND option_name NOT IN ('cron','recently_activated','user_count','rewrite_rules','db_upgraded','fresh_site','admin_email_lifespan','cpsd_manifest','cpsd_tombstone','cpsd_mode','cpsd_lock_state','cpsd_had_clean_teardown','cpdd_manifest','cpdd_tombstone','cpdd_had_clean_teardown','cpdd_lock_state')";
		}
		$offset = 0;
		$hash   = hash_init( 'sha256' );
		while ( true ) {
			$sql = 'SELECT * FROM `' . $table . '` WHERE ' . $where . ' ORDER BY `' . $id_col . '` ASC LIMIT %d OFFSET %d';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( self::CHUNK, $offset ) ) ), ARRAY_A );
			if ( array() === $rows ) {
				break;
			}
			foreach ( (array) $rows as $row ) {
				hash_update( $hash, $this->row_hash( $row ) . "\n" );
			}
			if ( count( (array) $rows ) < self::CHUNK ) {
				break;
			}
			$offset += self::CHUNK;
		}

		return hash_final( $hash );
	}

	/**
	 * @param string $table Full table name.
	 * @param string $id_col ID column.
	 * @param int $max_id Max baseline ID.
	 * @return int
	 */
	private function prefix_count( string $table, string $id_col, int $max_id ): int {
		global $wpdb;
		$where = '`' . $id_col . '` <= %d';
		if ( $this->is_options_table( $table ) ) {
			$where .= " AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'";
			$where .= " AND option_name NOT IN ('cron','recently_activated','user_count','rewrite_rules','db_upgraded','fresh_site','admin_email_lifespan','cpsd_manifest','cpsd_tombstone','cpsd_mode','cpsd_lock_state','cpsd_had_clean_teardown','cpdd_manifest','cpdd_tombstone','cpdd_had_clean_teardown','cpdd_lock_state')";
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where, array( $max_id ) ) );
	}

	/**
	 * @param string $table Full table name.
	 * @return array<string,string>
	 */
	private function keyed_rows( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '`', ARRAY_A );
		$key_cols = $this->key_columns( $table );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$parts = array();
			foreach ( $key_cols as $col ) {
				$parts[] = (string) ( $row[ $col ] ?? '' );
			}
			$key = implode( '|', $parts );
			$out[ $key ] = $this->row_hash( $row );
		}
		ksort( $out );

		return $out;
	}

	/**
	 * @param string $table Full table name.
	 * @return list<string>
	 */
	private function key_columns( string $table ): array {
		if ( false !== strpos( $table, 'cpms_rate_limits' ) ) {
			return array( 'window_key', 'window_id' );
		}
		if ( false !== strpos( $table, 'cpms_schema_migrations' ) ) {
			return array( 'version' );
		}
		if ( false !== strpos( $table, 'term_relationships' ) ) {
			return array( 'object_id', 'term_taxonomy_id' );
		}
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
		$names = array();
		foreach ( (array) $cols as $col ) {
			$names[] = (string) $col['Field'];
		}

		return array_slice( $names, 0, 2 );
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function is_options_table( string $table ): bool {
		return false !== strpos( $table, 'options' );
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return string
	 */
	private function row_hash( array $row ): string {
		ksort( $row );
		$canonical = (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', $canonical );
	}
}
