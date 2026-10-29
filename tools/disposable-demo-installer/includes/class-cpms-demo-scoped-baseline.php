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

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema introspection; table/column names come from a fixed allowlist and all variable values are prepared.

/**
 * Read-only baseline capture and post-teardown preservation check.
 */
class ScopedBaseline {

	private const CHUNK = 1000;

	/**
	 * WP core user rows that the operator's OWN authentication and wp-admin use
	 * rewrite: session tokens rotate on login/logout and the user-settings rows
	 * track dashboard UI state. They are WordPress runtime state, never CPMS or
	 * demo data, so treating their churn as baseline drift would block the safe
	 * teardown on the operator having logged in - the exact opposite of the
	 * invariant this baseline exists to protect (unrelated DATA unchanged).
	 */
	private const VOLATILE_USERMETA = array(
		'session_tokens',
		'wp_user-settings',
		'wp_user-settings-time',
		'wp_dashboard_quick_press_last_post_id',
		'wp_media_library_mode',
	);

	/**
	 * Queue lifecycle columns are RUNTIME state, not data.
	 *
	 * The CPMS queue wakes itself with a loopback tick after every enqueue, so a
	 * job row that already existed when the baseline was sealed legitimately
	 * moves queued -> processing -> success while the demo is being exercised.
	 * Hashing those columns would make the owner preview fail because the site ran
	 * its own maintenance, not because demo data drifted, so the baseline keeps
	 * covering a job's identity (id, type, payload, priority, created_at) only.
	 */
	private const VOLATILE_COLUMNS = array(
		'cpms_jobs' => array( 'status', 'attempts', 'run_after', 'locked_by', 'lock_expires_at', 'last_error', 'started_at', 'completed_at' ),
	);

	/**
	 * Tables that hold ONLY runtime counters.
	 *
	 * `cpms_rate_limits` is a keyed hit counter: the queue tick, the login
	 * throttle and the public OTP limiter increment and expire rows with no
	 * operator action, and none of it is clinical, financial or content data. The
	 * scoped teardown still owns and deletes the fixture's own keys through
	 * ScopedTeardown::allowed_rate_limit_keys(); the baseline simply does not
	 * pretend a counter is site data.
	 */
	private const RUNTIME_TABLES = array( 'cpms_rate_limits' );

	/**
	 * WordPress's own publish-scheduling markers.
	 *
	 * `_pingme` / `_encloseme` exist only so the publish scheduler can finish a
	 * pending post, and `_edit_lock` is an editor TTL. Core adds and drops them on
	 * its own schedule (the live run showed exactly 8 and 10 losing both markers),
	 * so they are runtime state like VOLATILE_USERMETA, not site content.
	 */
	private const VOLATILE_POSTMETA = array( '_pingme', '_encloseme', '_edit_lock' );

	/**
	 * Legitimate runtime churn in `wp_options`, stated one key at a time.
	 *
	 * These are install-level CPMS heartbeats: the queue rewrites
	 * `cpms_jobs_last_tick_at` after every successful tick and the scheduler
	 * rewrites `cpms_backup_last_run_at` after each backup, with no operator
	 * action and no clinical, financial or content meaning. Excluding anything
	 * else would blind the teardown to an unauthorised change of a preexisting
	 * record, so this list is deliberately not a wildcard and every entry carries
	 * its justification.
	 *
	 * @var array<string,string>
	 */
	private const VOLATILE_OPTIONS = array(
		'cpms_jobs_last_tick_at'  => 'Queue heartbeat; rewritten by every successful queue tick (autoload=no).',
		'cpms_backup_last_run_at' => 'Backup heartbeat; rewritten by each scheduled backup run.',
	);

	/**
	 * The companion's own bookkeeping options. They are demo-owned, never shared,
	 * and enumerated explicitly rather than matched by prefix so a new companion
	 * option cannot silently inherit an exemption.
	 *
	 * @var list<string>
	 */
	private const COMPANION_OPTIONS = array(
		'cpsd_manifest',
		'cpsd_tombstone',
		'cpsd_mode',
		'cpsd_lock_state',
		'cpsd_had_clean_teardown',
		'cpsd_baseline_pending',
		// Sealed append-only ownership ledger for additional demo Clinics. It is
		// demo-owned and exists only while the demo manifest does, so it must be
		// enumerated here (never matched by prefix) and must never be reported as
		// host-option residue while the fixture is still live.
		'cpsd_ownership_ledger',
		'cpdd_manifest',
		'cpdd_tombstone',
		'cpdd_had_clean_teardown',
		'cpdd_lock_state',
	);

	/** WordPress core options that core rewrites on its own schedule. */
	private const CORE_VOLATILE_OPTIONS = array(
		'cron',
		'recently_activated',
		'user_count',
		'rewrite_rules',
		'db_upgraded',
		'fresh_site',
		'admin_email_lifespan',
	);

	/** Per-row digests are stored for tables up to this many rows, so a mismatch can name rows. */
	private const ROW_MAP_LIMIT = 500;

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
				if ( 'cpms' === $group && in_array( (string) $name, self::RUNTIME_TABLES, true ) ) {
					continue;
				}
				++$checked;
				$table = 'cpms' === $group ? $this->platform->table( (string) $name ) : ( (string) $GLOBALS['wpdb']->prefix . $name );
				$actual = $this->snapshot_table( $table, (string) ( $expected['id_col'] ?? '' ), (int) ( $expected['max_id'] ?? 0 ) );
				if ( 'prefix' === (string) ( $expected['strategy'] ?? '' ) ) {
					if ( (int) ( $actual['max_id'] ?? -1 ) < (int) ( $expected['max_id'] ?? 0 ) ) {
						$problems[] = $group . ':' . $name . ' baseline ID prefix shrank';
						continue;
					}
					if ( (string) ( $actual['digest'] ?? '' ) !== (string) ( $expected['digest'] ?? '' ) ) {
						$problems[] = $group . ':' . $name . ' baseline row digest changed' . $this->changed_rows_note( $expected, $actual );
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
	private function snapshot_table( string $table, string $force_id = '', int $max_id = -1 ): array {
		global $wpdb;
		$id_col = '' !== $force_id ? $force_id : $this->auto_increment_column( $table );
		if ( '' !== $id_col ) {
			$limit = $max_id >= 0 ? $max_id : (int) $wpdb->get_var( 'SELECT COALESCE(MAX(`' . $id_col . '`), 0) FROM `' . $table . '`' );
			$count  = $this->prefix_count( $table, $id_col, $limit );
			$print  = $this->prefix_digest( $table, $id_col, $limit, $count <= self::ROW_MAP_LIMIT );

			return array(
				'strategy' => 'prefix',
				'id_col'   => $id_col,
				'max_id'   => $limit,
				'count'    => $count,
				'digest'   => $print['digest'],
				'rows'     => $print['rows'],
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
	 * @param bool $with_rows Also return a per-row digest map so a mismatch can name rows.
	 * @return array{digest:string,rows:array<string,string>}
	 */
	private function prefix_digest( string $table, string $id_col, int $max_id, bool $with_rows = false ): array {
		global $wpdb;
		$where = '`' . $id_col . '` <= %d';
		$params = array( $max_id );
		if ( $this->is_options_table( $table ) ) {
			$where .= " AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'";
			$where .= $this->option_exclusion_where();
		}
		$where .= $this->volatile_usermeta_where( $table );
		$where .= $this->volatile_postmeta_where( $table );
		$offset = 0;
		$hash   = hash_init( 'sha256' );
		$map    = array();
		while ( true ) {
			$sql = 'SELECT * FROM `' . $table . '` WHERE ' . $where . ' ORDER BY `' . $id_col . '` ASC LIMIT %d OFFSET %d';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( self::CHUNK, $offset ) ) ), ARRAY_A );
			if ( array() === $rows ) {
				break;
			}
			foreach ( (array) $rows as $row ) {
				$row_digest = $this->row_hash( $row, $table );
				hash_update( $hash, $row_digest . "\n" );
				if ( $with_rows ) {
					$map[ $this->row_label( $row, $table, $id_col ) ] = $row_digest;
				}
			}
			if ( count( (array) $rows ) < self::CHUNK ) {
				break;
			}
			$offset += self::CHUNK;
		}

		return array( 'digest' => hash_final( $hash ), 'rows' => $map );
	}

	/**
	 * PHI-free row label: identifiers and column/key NAMES only, never a value.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string $table Full table name.
	 * @param string $id_col ID column.
	 * @return string
	 */
	private function row_label( array $row, string $table, string $id_col ): string {
		if ( false !== strpos( $table, 'postmeta' ) || false !== strpos( $table, 'usermeta' ) ) {
			return (string) ( $row['post_id'] ?? $row['user_id'] ?? '' ) . ':' . (string) ( $row['meta_key'] ?? '' );
		}
		if ( $this->is_options_table( $table ) ) {
			return (string) ( $row['option_name'] ?? '' );
		}

		return (string) ( $row[ $id_col ] ?? '' );
	}

	/**
	 * Name up to five rows whose digest moved, so the operator sees WHAT drifted
	 * (ids and key names) without any row value or PHI.
	 *
	 * @param array<string,mixed> $expected Baseline snapshot of one table.
	 * @param array<string,mixed> $actual Live snapshot of the same table.
	 * @return string
	 */
	private function changed_rows_note( array $expected, array $actual ): string {
		$before = (array) ( $expected['rows'] ?? array() );
		$after  = (array) ( $actual['rows'] ?? array() );
		if ( array() === $before || array() === $after ) {
			return '';
		}
		$changed = array();
		foreach ( $before as $label => $digest ) {
			if ( ! isset( $after[ $label ] ) || ! hash_equals( (string) $digest, (string) $after[ $label ] ) ) {
				$changed[] = (string) $label;
			}
			if ( count( $changed ) >= 5 ) {
				break;
			}
		}
		if ( array() === $changed ) {
			return '';
		}

		return ' (changed: ' . implode( ', ', $changed ) . ')';
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
			$where .= $this->option_exclusion_where();
		}
		$where .= $this->volatile_usermeta_where( $table );
		$where .= $this->volatile_postmeta_where( $table );

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
			$out[ $key ] = $this->row_hash( $row, $table );
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
	 * Build the `option_name NOT IN (...)` clause from the declarative lists.
	 *
	 * @return string
	 */
	private function option_exclusion_where(): string {
		$names = array_merge(
			self::CORE_VOLATILE_OPTIONS,
			self::COMPANION_OPTIONS,
			array_keys( self::VOLATILE_OPTIONS )
		);
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
		if ( array() === $names ) {
			return '';
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		return ' AND option_name NOT IN (' . $wpdb->prepare( $placeholders, $names ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated above and every value is prepared.
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function is_options_table( string $table ): bool {
		return false !== strpos( $table, 'options' );
	}

	/**
	 * Optional `AND`-fragment excluding WP runtime user rows from the prefix
	 * digest. Shared by the digest and the count so both stay identical.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private function volatile_usermeta_where( string $table ): string {
		global $wpdb;
		if ( ! isset( $wpdb->usermeta ) || $table !== (string) $wpdb->usermeta ) {
			return '';
		}
		$quoted = array();
		foreach ( self::VOLATILE_USERMETA as $meta_key ) {
			$quoted[] = "'" . $meta_key . "'";
		}

		return ' AND meta_key NOT IN (' . implode( ',', $quoted ) . ')';
	}

	/**
	 * Optional `AND`-fragment excluding WordPress's publish-scheduling markers
	 * and editor locks. Shared by the digest and the count so both stay identical.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private function volatile_postmeta_where( string $table ): string {
		global $wpdb;
		if ( ! isset( $wpdb->postmeta ) || $table !== (string) $wpdb->postmeta ) {
			return '';
		}
		$quoted = array();
		foreach ( self::VOLATILE_POSTMETA as $meta_key ) {
			$quoted[] = "'" . $meta_key . "'";
		}

		return ' AND meta_key NOT IN (' . implode( ',', $quoted ) . ')';
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return string
	 */
	private function row_hash( array $row, string $table = '' ): string {
		// WordPress maintains term_taxonomy.count as a cache-like counter when
		// posts are published. It is not an immutable site record; excluding it
		// prevents legitimate foreign activity from looking like baseline damage.
		if ( false !== strpos( $table, 'term_taxonomy' ) ) {
			unset( $row['count'] );
		}
		foreach ( self::VOLATILE_COLUMNS as $marker => $columns ) {
			if ( false === strpos( $table, $marker ) ) {
				continue;
			}
			foreach ( $columns as $column ) {
				unset( $row[ $column ] );
			}
		}
		ksort( $row );
		$canonical = (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', $canonical );
	}
}
