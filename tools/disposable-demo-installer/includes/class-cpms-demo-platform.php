<?php
/**
 * CPMS Classic adapter.
 *
 * Everything this plugin knows about the production plugin lives here. The demo
 * installer never re-implements CPMS business rules: it calls the same
 * production services the UI calls (organization provisioning, memberships,
 * clinician/location wiring, schedules, patients, booking, visits, clinical
 * notes/prescriptions/follow-ups, invoicing and payments).
 *
 * Direct SQL is used only for (a) read-only safety inspection and (b) teardown
 * of rows that are proven to belong to the synthetic clinic.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Frontend\StaffPortalShell;

/**
 * Production implementation of the CPMS adapter.
 */
class Platform {

	/**
	 * Human label of the production plugin, for the admin page.
	 */
	public const PRODUCT = 'CPMS Classic';

	/**
	 * Baseline recurring system job types registered by App::activate().
	 *
	 * @var list<string>
	 */
	public const BASELINE_JOB_TYPES = array(
		'holds.expire',
		'slots.generate',
		'visits.no_show',
		'notif.dispatch',
		'appt.reminder',
		'fu.reminder',
		'cleanup.otp',
		'cleanup.rate_limits',
		'cleanup.idem',
		'cleanup.oplog',
		'license.refresh',
		'backup.run',
		'handwriting.gc',
	);

	/**
	 * Baseline operational log messages written during schema migration / activation.
	 *
	 * @var list<string>
	 */
	public const BASELINE_OPLOG_MESSAGES = array(
		'MIGRATION_START',
		'MIGRATION_DONE',
		'MIGRATION_0011_LOCATIONS_BACKFILL',
		'CPMS_ACTIVATED',
		'CPMS_PRIVATE_STORAGE_MIGRATION',
	);

	/**
	 * Optional environment reference for fault injection and IP hash correlation.
	 *
	 * @var Env|null
	 */
	protected ?Env $env = null;

	/**
	 * @param Env|null $env Optional environment adapter.
	 */
	public function __construct( ?Env $env = null ) {
		$this->env = $env;
	}

	/**
	 * Bind environment adapter.
	 *
	 * @param Env $env Environment adapter.
	 */
	public function set_env( Env $env ): void {
		$this->env = $env;
	}

	/**
	 * Normalize a full or short table name to its short `cpms_*` form.
	 *
	 * @param string $table Full or short table name.
	 * @return string
	 */
	protected function short_name( string $table ): string {
		if ( str_starts_with( $table, 'cpms_' ) ) {
			return $table;
		}
		$pos = strpos( $table, 'cpms_' );

		return false !== $pos ? substr( $table, $pos ) : $table;
	}

	/**
	 * @return bool
	 */
	public function available(): bool {
		return class_exists( App::class )
			&& defined( 'CPMS_VERSION' )
			&& method_exists( App::class, 'db' )
			&& method_exists( App::class, 'migrations' );
	}

	/**
	 * @return string
	 */
	public function plugin_version(): string {
		return defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : '';
	}

	/**
	 * @return string|null
	 */
	public function schema_version(): ?string {
		if ( ! $this->available() ) {
			return null;
		}

		return App::migrations()->currentVersion();
	}

	/**
	 * @return \ClinicCore\Infrastructure\Db\CpmsDb
	 */
	private function db() {
		return App::db();
	}

	/**
	 * @param string $short Short table name, e.g. cpms_clinics.
	 * @return string
	 */
	public function table( string $short ): string {
		return $this->db()->table( $short );
	}

	/**
	 * @return list<string> Fully-prefixed CPMS table names that exist right now.
	 */
	public function tables(): array {
		global $wpdb;
		$like = $wpdb->esc_like( (string) $wpdb->prefix . 'cpms_' ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = (string) $row;
		}
		sort( $out );

		return $out;
	}

	/**
	 * @return list<string> CPMS table short names (prefix stripped).
	 */
	public function table_names(): array {
		global $wpdb;
		$prefix = (string) $wpdb->prefix;
		$out    = array();
		foreach ( $this->tables() as $full ) {
			$short = str_starts_with( $full, $prefix ) ? substr( $full, strlen( $prefix ) ) : $full;
			$out[] = $short;
		}

		return $out;
	}

	/**
	 * @param string $short Short table name.
	 * @return bool
	 */
	public function table_exists( string $short ): bool {
		return in_array( $short, $this->table_names(), true ) || in_array( $this->db()->table( $short ), $this->tables(), true );
	}

	/**
	 * @param string $short Short table name.
	 * @return int
	 */
	public function count_all( string $short ): int {
		return (int) $this->db()->fetchValue( 'SELECT COUNT(*) FROM ' . $this->db()->table( $short ) );
	}

	/**
	 * Highest integer `id` currently present in a table, or 0 when empty.
	 *
	 * @param string $short Short table name.
	 * @return int
	 */
	public function max_id( string $short ): int {
		if ( ! $this->table_exists( $short ) ) {
			return 0;
		}

		return (int) $this->db()->fetchValue( 'SELECT COALESCE(MAX(id), 0) FROM ' . $this->db()->table( $short ) );
	}

	/**
	 * Whatever a fresh CPMS activation legitimately leaves behind must be
	 * exactly the documented bootstrap state: one default Organization, one
	 * default Clinic, one primary Location, and at most the system heartbeat
	 * setting. Anything else means the site is not disposable.
	 *
	 * @return array{ok:bool,reason?:string}
	 */
	public function bootstrap_state(): array {
		$organizations = $this->db()->fetchAll( 'SELECT id, slug, status FROM ' . $this->db()->table( 'cpms_organizations' ) . ' ORDER BY id' );
		$clinics       = $this->db()->fetchAll( 'SELECT id, organization_id, slug, status FROM ' . $this->db()->table( 'cpms_clinics' ) . ' ORDER BY id' );
		$locations     = $this->db()->fetchAll( 'SELECT id, clinic_id, slug, is_primary, is_active FROM ' . $this->db()->table( 'cpms_locations' ) . ' ORDER BY id' );

		$ok = count( $organizations ) === 1
			&& (int) $organizations[0]['id'] === 1
			&& 'default' === (string) $organizations[0]['slug']
			&& 'active' === (string) $organizations[0]['status']
			&& count( $clinics ) === 1
			&& (int) $clinics[0]['id'] === 1
			&& (int) $clinics[0]['organization_id'] === 1
			&& 'default' === (string) $clinics[0]['slug']
			&& 'active' === (string) $clinics[0]['status']
			&& count( $locations ) === 1
			&& (int) $locations[0]['clinic_id'] === 1
			&& 'main' === (string) $locations[0]['slug']
			&& (int) $locations[0]['is_primary'] === 1
			&& (int) $locations[0]['is_active'] === 1;
		if ( ! $ok ) {
			return array(
				'ok'     => false,
				'reason' => 'Organization/Clinic/Location پیش‌فرض دست‌نخورده نیست (سازمان/درمانگاه اضافه یا تغییریافته).',
			);
		}

		$settings = $this->db()->fetchAll(
			'SELECT clinic_id, `key`, value_json, updated_by_wp_user_id FROM ' . $this->db()->table( 'cpms_settings' ) . ' ORDER BY id'
		);
		foreach ( $settings as $setting ) {
			$value         = json_decode( (string) ( $setting['value_json'] ?? '' ), true );
			$inert_heartbeat = 1 === (int) ( $setting['clinic_id'] ?? 0 )
				&& 'jobs.last_tick_at' === (string) ( $setting['key'] ?? '' )
				&& in_array( $setting['updated_by_wp_user_id'] ?? null, array( null, 0, '0' ), true )
				&& is_int( $value )
				&& $value > 0;
			if ( ! $inert_heartbeat ) {
				return array(
					'ok'     => false,
					'reason' => 'تنظیمات CPMS شامل مقدار غیر از Heartbeat سیستمی است.',
				);
			}
		}

		return array( 'ok' => true );
	}

	/**
	 * @param string $table Full table name.
	 * @return list<string> Column names, or [] when unavailable.
	 */
	public function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier sanitized, table from SHOW TABLES.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$out[] = (string) $row['Field'];
			}
		}

		return $out;
	}

	/**
	 * @param string       $table Full table name.
	 * @param string       $where SQL predicate (already prepared).
	 * @param list<mixed>  $params Parameters.
	 * @return int
	 */
	public function count_where( string $table, string $where, array $params = array() ): int {
		$sql = 'SELECT COUNT(*) FROM `' . str_replace( '`', '', $table ) . '` WHERE ' . $where;
		if ( $params !== array() ) {
			$sql = $this->db()->prepare( $sql, $params );
		}

		return (int) $this->db()->fetchValue( $sql );
	}

	/**
	 * @param string      $table Full table name.
	 * @param string      $where Predicate.
	 * @param list<mixed> $params Parameters.
	 * @return int Rows deleted.
	 */
	public function delete_where( string $table, string $where, array $params = array() ): int {
		$short = $this->short_name( $table );
		if ( null !== $this->env ) {
			$this->env->maybe_inject_fault( 'delete_where:' . $short, array( 'table' => $short, 'where' => $where ) );
			$this->env->maybe_inject_fault( 'delete_where', array( 'table' => $short, 'where' => $where ) );
		}
		$sql = 'DELETE FROM `' . str_replace( '`', '', $table ) . '` WHERE ' . $where;
		if ( $params !== array() ) {
			$sql = $this->db()->prepare( $sql, $params );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when params present.
		$affected = $wpdb->query( $sql );
		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			throw new Exception( 'SQL_DELETE_FAILED', 'خطای پایگاه‌داده هنگام حذف ردیف‌های جدول ' . $short . '.' );
		}

		return (int) $affected;
	}

	/**
	 * Baseline recurring system jobs predicate.
	 *
	 * @return array{0:string,1:list<mixed>}
	 */
	public function baseline_jobs_where(): array {
		$placeholders = implode( ',', array_fill( 0, count( self::BASELINE_JOB_TYPES ), '%s' ) );
		$where        = 'type IN (' . $placeholders . ") AND status IN ('queued','processing','success') AND (payload_json IS NULL OR CAST(payload_json AS CHAR) IN ('[]','{}',''))";

		return array( $where, self::BASELINE_JOB_TYPES );
	}

	/**
	 * Count legitimate baseline recurring system jobs in `cpms_jobs`.
	 *
	 * @return int
	 */
	public function baseline_jobs_count(): int {
		if ( ! $this->table_exists( 'cpms_jobs' ) ) {
			return 0;
		}
		list( $where, $params ) = $this->baseline_jobs_where();

		return $this->count_where( $this->table( 'cpms_jobs' ), $where, $params );
	}

	/**
	 * Count non-baseline rows in `cpms_jobs`.
	 *
	 * @return int
	 */
	public function non_baseline_jobs_count(): int {
		if ( ! $this->table_exists( 'cpms_jobs' ) ) {
			return 0;
		}

		return max( 0, $this->count_all( 'cpms_jobs' ) - $this->baseline_jobs_count() );
	}

	/**
	 * Baseline operational logs predicate.
	 *
	 * @return array{0:string,1:list<mixed>}
	 */
	public function baseline_oplogs_where(): array {
		$placeholders = implode( ',', array_fill( 0, count( self::BASELINE_OPLOG_MESSAGES ), '%s' ) );
		$where        = "level = 'info' AND message IN (" . $placeholders . ')';

		return array( $where, self::BASELINE_OPLOG_MESSAGES );
	}

	/**
	 * Count legitimate baseline activation/migration logs in `cpms_operational_logs`.
	 *
	 * @return int
	 */
	public function baseline_oplogs_count(): int {
		if ( ! $this->table_exists( 'cpms_operational_logs' ) ) {
			return 0;
		}
		list( $where, $params ) = $this->baseline_oplogs_where();

		return $this->count_where( $this->table( 'cpms_operational_logs' ), $where, $params );
	}

	/**
	 * Count non-baseline rows in `cpms_operational_logs`.
	 *
	 * @return int
	 */
	public function non_baseline_oplogs_count(): int {
		if ( ! $this->table_exists( 'cpms_operational_logs' ) ) {
			return 0;
		}

		return max( 0, $this->count_all( 'cpms_operational_logs' ) - $this->baseline_oplogs_count() );
	}

	/**
	 * Check whether clinic-scoped (`clinic_id = <demo>`) OTP audit events for
	 * the synthetic `0900` patient mobile are free of 4-digit suffix collisions
	 * with any non-fixture mobile.
	 *
	 * Unscoped (`clinic_id IS NULL`) OTP audit records carry only a masked
	 * 4-digit suffix (`***XXXX`) and cannot be proven to belong exclusively to
	 * the synthetic fixture; they always fail closed and are never matched by
	 * `owned_audit_logs_predicate()`. Even for clinic-scoped OTP audit records,
	 * any foreign mobile sharing the same 4-digit suffix in `cpms_otp_tokens`,
	 * `cpms_patients`, or `cpms_sms_messages` causes this check to return
	 * `false` so teardown fails closed rather than deleting ambiguous records.
	 *
	 * @param int    $clinic_id      Synthetic demo clinic ID.
	 * @param string $patient_mobile Synthetic patient mobile.
	 * @return bool
	 */
	public function otp_suffix_unambiguous( int $clinic_id, string $patient_mobile ): bool {
		if ( $clinic_id <= 1 || 1 !== preg_match( '/^0900\d{7}$/', $patient_mobile ) ) {
			return false;
		}
		$suffix = substr( $patient_mobile, -4 );

		if ( $this->table_exists( 'cpms_otp_tokens' ) ) {
			$colliding_tokens = $this->count_where(
				$this->table( 'cpms_otp_tokens' ),
				'mobile <> %s AND RIGHT(mobile, 4) = %s',
				array( $patient_mobile, $suffix )
			);
			if ( $colliding_tokens > 0 ) {
				return false;
			}
		}

		if ( $this->table_exists( 'cpms_patients' ) ) {
			$colliding_patients = $this->count_where(
				$this->table( 'cpms_patients' ),
				'mobile <> %s AND RIGHT(mobile, 4) = %s',
				array( $patient_mobile, $suffix )
			);
			if ( $colliding_patients > 0 ) {
				return false;
			}
		}

		if ( $this->table_exists( 'cpms_sms_messages' ) ) {
			$colliding_sms = $this->count_where(
				$this->table( 'cpms_sms_messages' ),
				'recipient <> %s AND RIGHT(recipient, 4) = %s',
				array( $patient_mobile, $suffix )
			);
			if ( $colliding_sms > 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build durable ownership predicate for `cpms_audit_logs` (S-3).
	 *
	 * Never uses `id > baseline_max_id` and never authorizes deletion of
	 * unscoped (`clinic_id IS NULL`) `otp` audit records by masked mobile
	 * suffix (`***XXXX`), because a 4-digit suffix cannot establish ownership
	 * against foreign or adversarial suffix collisions. Even clinic-scoped
	 * `otp` audit records require `$scope['otp_suffix_unambiguous'] === true`.
	 *
	 * @param array<string,mixed> $scope Collected fixture scope.
	 * @return array{0:string,1:list<mixed>}
	 */
	public function owned_audit_logs_predicate( array $scope ): array {
		$clinic_id      = (int) ( $scope['clinic_id'] ?? 0 );
		$org_id         = (int) ( $scope['organization_id'] ?? 0 );
		$fixture_id     = (string) ( $scope['fixture_id'] ?? '' );
		$patient_mobile = (string) ( $scope['patient_mobile'] ?? '' );
		$masked_mobile  = 1 === preg_match( '/^0900\d{7}$/', $patient_mobile ) ? '***' . substr( $patient_mobile, -4 ) : '';
		$otp_unambig    = ! array_key_exists( 'otp_suffix_unambiguous', $scope ) || ! empty( $scope['otp_suffix_unambiguous'] );
		$identity_ids   = array_values( array_filter( array_map( 'intval', (array) ( $scope['identity_ids'] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) );
		$user_ids       = array_values( array_filter( array_map( 'intval', (array) ( $scope['user_ids'] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) );

		$clauses = array();
		$params  = array();

		if ( $clinic_id > 1 ) {
			if ( '' !== $masked_mobile && $otp_unambig ) {
				$clauses[] = "(clinic_id = %d AND (resource_type <> %s OR JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.mobile')) = %s))";
				$params[]  = $clinic_id;
				$params[]  = 'otp';
				$params[]  = $masked_mobile;
			} else {
				$clauses[] = '(clinic_id = %d AND resource_type <> %s)';
				$params[]  = $clinic_id;
				$params[]  = 'otp';
			}
		}
		if ( $org_id > 1 ) {
			$clauses[] = '(clinic_id IS NULL AND resource_type = %s AND resource_id = %d)';
			$params[]  = 'organization';
			$params[]  = $org_id;
		}
		if ( array() !== $identity_ids ) {
			$ph        = implode( ',', array_fill( 0, count( $identity_ids ), '%d' ) );
			$clauses[] = '(clinic_id IS NULL AND resource_type = %s AND resource_id IN (' . $ph . '))';
			$params[]  = 'patient_identity';
			foreach ( $identity_ids as $id ) {
				$params[] = $id;
			}
		}
		if ( array() !== $user_ids ) {
			$ph        = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$clauses[] = '(clinic_id IS NULL AND resource_type <> %s AND actor_wp_user_id IN (' . $ph . '))';
			$params[]  = 'otp';
			foreach ( $user_ids as $id ) {
				$params[] = $id;
			}
		}
		if ( '' !== $fixture_id ) {
			$clauses[] = "(clinic_id IS NULL AND resource_type = %s AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.fixture_id')) = %s)";
			$params[]  = 'cpdd_fixture';
			$params[]  = $fixture_id;
		}

		if ( array() === $clauses ) {
			return array( '1 = 0', array() );
		}

		return array( '(' . implode( ' OR ', $clauses ) . ')', $params );
	}

	/**
	 * Build durable ownership predicate for `cpms_jobs` (S-3).
	 *
	 * Never uses `id > baseline_max_id`. Only jobs whose payload references the
	 * synthetic clinic or a synthetic SMS message ID are owned.
	 *
	 * @param array<string,mixed> $scope Collected fixture scope.
	 * @return array{0:string,1:list<mixed>}
	 */
	public function owned_jobs_predicate( array $scope ): array {
		$clinic_id = (int) ( $scope['clinic_id'] ?? 0 );
		$sms_ids   = array_values( array_filter( array_map( 'intval', (array) ( $scope['sms_message_ids'] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) );

		$clauses = array();
		$params  = array();

		if ( $clinic_id > 1 ) {
			$clauses[] = "(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.clinic_id')) AS UNSIGNED) = %d)";
			$params[]  = $clinic_id;
		}
		if ( array() !== $sms_ids ) {
			$ph        = implode( ',', array_fill( 0, count( $sms_ids ), '%d' ) );
			$clauses[] = '(type = %s AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'$.message_id\')) AS UNSIGNED) IN (' . $ph . '))';
			$params[]  = 'sms.send';
			foreach ( $sms_ids as $id ) {
				$params[] = $id;
			}
		}

		if ( array() === $clauses ) {
			return array( '1 = 0', array() );
		}

		list( $base_where, $base_params ) = $this->baseline_jobs_where();

		return array(
			'NOT (' . $base_where . ') AND (' . implode( ' OR ', $clauses ) . ')',
			array_merge( $base_params, $params ),
		);
	}

	/**
	 * Build durable ownership predicate for `cpms_operational_logs` (S-3).
	 *
	 * Never uses `id > baseline_max_id` or shared `request_id` correlation.
	 * Every matched row must carry an explicit immutable fixture entity ID,
	 * synthetic `0900` patient mobile, or synthetic persona `username_hash`
	 * in `context_json`.
	 *
	 * @param array<string,mixed> $scope Collected fixture scope.
	 * @return array{0:string,1:list<mixed>}
	 */
	public function owned_oplogs_predicate( array $scope ): array {
		$clinic_id      = (int) ( $scope['clinic_id'] ?? 0 );
		$patient_mobile = (string) ( $scope['patient_mobile'] ?? '' );

		$clauses = array();
		$params  = array();

		if ( $clinic_id > 1 ) {
			$clauses[] = "(CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.clinic_id')) AS UNSIGNED) = %d)";
			$params[]  = $clinic_id;
		}

		$id_fields = array(
			'patient_ids'            => array( '$.patient_id' ),
			'appointment_ids'        => array( '$.appointment_id', '$.old', '$.new' ),
			'slot_ids'               => array( '$.slot_id' ),
			'hold_ids'               => array( '$.hold_id' ),
			'schedule_ids'           => array( '$.schedule_id' ),
			'schedule_exception_ids' => array( '$.exception_id' ),
			'clinician_ids'          => array( '$.clinician_id' ),
			'follow_up_ids'          => array( '$.follow_up_id' ),
			'sms_message_ids'        => array( '$.message_id' ),
			'otp_token_ids'          => array( '$.token_id' ),
			'user_ids'               => array( '$.wp_user_id', '$.actor', '$.by' ),
		);

		foreach ( $id_fields as $scope_key => $json_paths ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) ( $scope[ $scope_key ] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) );
			if ( array() === $ids ) {
				continue;
			}
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( $json_paths as $path ) {
				$clauses[] = '(CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json, \'' . $path . '\')) AS UNSIGNED) IN (' . $ph . '))';
				foreach ( $ids as $id ) {
					$params[] = $id;
				}
			}
		}

		if ( 1 === preg_match( '/^0900\d{7}$/', $patient_mobile ) ) {
			$clauses[] = "(message = %s AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.to')) = %s)";
			$params[]  = 'SMS_LOG_SENT';
			$params[]  = $patient_mobile;
		}

		$login_hashes = array_values( array_filter( array_map( 'strval', (array) ( $scope['login_user_hashes'] ?? array() ) ) ) );
		if ( array() !== $login_hashes ) {
			$ph        = implode( ',', array_fill( 0, count( $login_hashes ), '%s' ) );
			$clauses[] = "(message IN ('LOGIN_FAILED','LOGIN_THROTTLED') AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.username_hash')) IN (" . $ph . '))';
			foreach ( $login_hashes as $h ) {
				$params[] = $h;
			}
		}

		if ( array() === $clauses ) {
			return array( '1 = 0', array() );
		}

		list( $base_where, $base_params ) = $this->baseline_oplogs_where();

		return array(
			'NOT (' . $base_where . ') AND (' . implode( ' OR ', $clauses ) . ')',
			array_merge( $base_params, $params ),
		);
	}

	/**
	 * SQL predicate matching globally shared IP rate-limit rows in `cpms_rate_limits`.
	 *
	 * Because `otp-ip:*`, `otp-verify-ip:*`, and `login-ip:*` counters are keyed
	 * only by IP address and shared across the entire WordPress installation,
	 * matching an IP is never sufficient proof that a row belongs exclusively to
	 * the demo. These rows are preserved during teardown rather than deleted.
	 *
	 * @return array{0:string,1:list<mixed>}
	 */
	public function shared_ip_rate_limits_where(): array {
		return array(
			"(window_key LIKE 'otp-ip:%' OR window_key LIKE 'otp-verify-ip:%' OR window_key LIKE 'login-ip:%')",
			array(),
		);
	}

	/**
	 * Count globally shared IP rate-limit rows in `cpms_rate_limits`.
	 *
	 * @return int
	 */
	public function shared_ip_rate_limits_count(): int {
		if ( ! $this->table_exists( 'cpms_rate_limits' ) ) {
			return 0;
		}
		list( $where, $params ) = $this->shared_ip_rate_limits_where();

		return $this->count_where( $this->table( 'cpms_rate_limits' ), $where, $params );
	}

	/**
	 * Count entity-scoped (non-shared-IP) rate-limit rows in `cpms_rate_limits`.
	 *
	 * @return int
	 */
	public function non_shared_ip_rate_limits_count(): int {
		if ( ! $this->table_exists( 'cpms_rate_limits' ) ) {
			return 0;
		}

		return max( 0, $this->count_all( 'cpms_rate_limits' ) - $this->shared_ip_rate_limits_count() );
	}

	/**
	 * Compute the exact list of entity-scoped `window_key` values exclusively
	 * owned by the fixture (S-3).
	 *
	 * Never uses `window_id >= 0` and never includes shared-IP counters
	 * (`otp-ip:*`, `otp-verify-ip:*`, `login-ip:*`). Every allowed key is derived
	 * from the fixture's synthetic `0900` patient mobile, synthetic persona user
	 * IDs, or synthetic persona login username hashes.
	 *
	 * @param array<string,mixed> $scope Collected fixture scope.
	 * @return list<string>
	 */
	public function allowed_rate_limit_keys_for_scope( array $scope ): array {
		$keys           = array();
		$patient_mobile = (string) ( $scope['patient_mobile'] ?? '' );
		if ( 1 === preg_match( '/^0900\d{7}$/', $patient_mobile ) ) {
			$keys[] = 'otp-day:' . $patient_mobile;
			$keys[] = 'otp-hour:' . $patient_mobile;
		}

		$user_ids = array_values( array_filter( array_map( 'intval', (array) ( $scope['user_ids'] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) );
		foreach ( $user_ids as $uid ) {
			$keys[] = 'booking-' . $uid;
			$keys[] = 'files:upload:' . $uid;
			$keys[] = 'rt_notif_' . $uid;
			$keys[] = 'rt_queue_' . $uid;
			$keys[] = 'rt_queue_portal_' . $uid;
			$keys[] = 'sms-test-' . $uid;
			$keys[] = 'sms-send-' . $uid;
			$keys[] = 'sms-tmpl-' . $uid;
			// Iran V1 (schema 0030) actor-keyed windows. Every one of these is
			// bound to a synthetic WordPress user id, so it belongs to exactly
			// that fixture actor. Without them the fixture's own browser journeys
			// (staff messaging poll/send, doctor pricing writes) leave windows the
			// teardown could not prove ownership of, and the fail-closed
			// diagnostic would block the very cleanup it is protecting.
			$keys[] = 'msg_read_' . $uid;
			$keys[] = 'msg_send_' . $uid;
			$keys[] = 'pricing_write_' . $uid;
		}

		foreach ( (array) ( $scope['login_user_keys'] ?? array() ) as $login_key ) {
			$login_key = (string) $login_key;
			if ( '' !== $login_key ) {
				$keys[] = $login_key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Build durable ownership predicate for `cpms_rate_limits` (S-3).
	 *
	 * @param array<string,mixed> $scope Collected fixture scope.
	 * @return array{0:string,1:list<mixed>}
	 */
	public function owned_rate_limits_predicate( array $scope ): array {
		$keys = $this->allowed_rate_limit_keys_for_scope( $scope );
		if ( array() === $keys ) {
			return array( '1 = 0', array() );
		}
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		return array( 'window_key IN (' . $ph . ')', $keys );
	}

	/**
	 * @param string $sql SQL.
	 * @param array  $params Parameters.
	 * @return list<array<string,mixed>>
	 */
	public function fetch_all( string $sql, array $params = array() ): array {
		return $this->db()->fetchAll( $params === array() ? $sql : $this->db()->prepare( $sql, $params ) );
	}

	/**
	 * @param string $sql SQL.
	 * @param array  $params Parameters.
	 * @return array<string,mixed>|null
	 */
	public function fetch_row( string $sql, array $params = array() ): ?array {
		return $this->db()->fetchRow( $params === array() ? $sql : $this->db()->prepare( $sql, $params ) );
	}

	/**
	 * @return string
	 */
	public function now_sql(): string {
		return $this->db()->nowUtcSql();
	}

	/**
	 * @param int $clinic_id Clinic.
	 * @param int $location_id Location.
	 * @param int $organization_id Organization.
	 * @return mixed Previous scope handle.
	 */
	public function set_scope( int $clinic_id, int $location_id, int $organization_id ) {
		$previous = ScopeContext::tryGet();
		ScopeContext::set(
			ClinicScope::forClinic( $clinic_id, $location_id )->withOrganization( $organization_id )
		);

		return $previous;
	}

	/**
	 * @param mixed $previous Scope handle returned by set_scope().
	 * @return void
	 */
	public function restore_scope( $previous ): void {
		App::replaceExplicitScope( $previous );
	}

	/**
	 * @param string                $name Name.
	 * @param string                $slug Slug.
	 * @return int Organization id.
	 */
	public function create_organization( string $name, string $slug ): int {
		$now = $this->now_sql();
		if ( ! $this->db()->insert(
			'cpms_organizations',
			array(
				'name'       => $name,
				'slug'       => $slug,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		) ) {
			throw new Exception( 'ORGANIZATION_CREATE', 'ساخت سازمان آزمایشی ناموفق بود.' );
		}

		return (int) $this->db()->wpdb_last_insert_id();
	}

	/**
	 * @param int $actor_id Admin actor.
	 * @param int $organization_id Organization.
	 * @param int $manager_id Synthetic organization manager.
	 * @return array<string,mixed>
	 */
	public function bootstrap_owner( int $actor_id, int $organization_id, int $manager_id ): array {
		return App::organization_clinic_provisioning_service()->bootstrap_owner( $actor_id, $organization_id, $manager_id );
	}

	/**
	 * @param int    $actor_id Synthetic organization manager.
	 * @param int    $organization_id Organization.
	 * @param string $name Clinic name.
	 * @param string $slug Clinic slug.
	 * @param string $timezone Timezone.
	 * @param string $location_name Primary location name.
	 * @param string $address Synthetic address.
	 * @return array{clinic_id:int,location_id:int}
	 */
	public function provision_clinic( int $actor_id, int $organization_id, string $name, string $slug, string $timezone, string $location_name, string $address ): array {
		$result = App::organization_clinic_provisioning_service()->provision(
			$actor_id,
			$organization_id,
			$name,
			$slug,
			$timezone,
			$location_name,
			$address,
			null
		);

		return array(
			'clinic_id'   => (int) $result['clinic_id'],
			'location_id' => (int) $result['location_id'],
		);
	}

	/**
	 * @param int $actor_id Admin actor.
	 * @param int $organization_id Organization.
	 * @param int $clinic_id Clinic.
	 * @param int $target_id Clinic manager.
	 * @return array<string,mixed>
	 */
	public function designate_clinic_manager( int $actor_id, int $organization_id, int $clinic_id, int $target_id ): array {
		return App::organization_clinic_provisioning_service()->designate_clinic_manager( $actor_id, $organization_id, $clinic_id, $target_id );
	}

	/**
	 * @param int    $clinic_id Clinic.
	 * @param int    $wp_user_id User.
	 * @param string $role_key CPMS role key.
	 * @param int    $actor_id Inviting actor.
	 * @return int Membership id.
	 */
	public function create_membership( int $clinic_id, int $wp_user_id, string $role_key, int $actor_id ): int {
		return (int) App::membership_service()->create_membership( $clinic_id, $wp_user_id, $role_key, 'clinic', $actor_id );
	}

	/**
	 * Read back a production capability decision for this exact actor.
	 *
	 * The scoped demo withholds Clinic-creation authority from the portal-facing
	 * synthetic Organization manager. A stored DENY row is not enough evidence:
	 * the decision is read back through the production resolver that the portal
	 * itself uses, so a silently ineffective row can never masquerade as policy.
	 *
	 * @param int    $actor_id        Acting user.
	 * @param int    $organization_id Organization.
	 * @param string $capability      Capability key.
	 * @return bool
	 */
	public function production_can( int $actor_id, int $organization_id, string $capability ): bool {
		return App::organization_clinic_provisioning_service()->can( $actor_id, $organization_id, $capability );
	}

	/**
	 * Designate (or suspend) an Organization manager through the production
	 * service, under the caller's own Organization authority.
	 *
	 * The scoped demo uses this to give a *second*, non-portal synthetic account
	 * Organization manager authority, so that a demo-owned additional Clinic can
	 * be created by the real provisioning service while the portal-facing
	 * synthetic manager stays unable to create Clinics at all.
	 *
	 * @param int  $actor_id        Authorized Organization manager.
	 * @param int  $organization_id Organization.
	 * @param int  $target_id       Target WordPress user.
	 * @param bool $active          Activate or suspend.
	 * @return array<string,mixed>
	 */
	public function set_manager( int $actor_id, int $organization_id, int $target_id, bool $active ): array {
		return App::organization_clinic_provisioning_service()->set_manager( $actor_id, $organization_id, $target_id, $active );
	}

	/**
	 * Insert one row and fail closed when the database refuses it.
	 *
	 * Used for the scoped-mode capability DENY that removes the portal's
	 * create-Clinic authority from the synthetic Organization manager. A row that
	 * was not stored is not a policy: callers read it back and verify.
	 *
	 * @param string              $short Short table name.
	 * @param array<string,mixed> $data  Row.
	 * @return int Insert id.
	 */
	public function insert_row( string $short, array $data ): int {
		global $wpdb;
		$table = $this->table( $short );
		$ok    = $wpdb->insert( $table, $data );
		if ( false === $ok || '' !== (string) $wpdb->last_error || (int) $wpdb->insert_id <= 0 ) {
			throw new Exception( 'ROW_INSERT', 'نوشتن ردیف ' . $short . ' ناموفق بود: ' . (string) $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}


	/**
	 * @param int    $clinic_id Clinic.
	 * @param int    $wp_user_id Doctor user.
	 * @param string $full_name Synthetic doctor name.
	 * @return int Clinician id.
	 */
	public function create_clinician( int $clinic_id, int $wp_user_id, string $full_name ): int {
		$id = (int) App::clinicianRepository()->create(
			$clinic_id,
			array(
				'wp_user_id' => $wp_user_id,
				'full_name'  => $full_name,
				'specialty'  => 'پزشک عمومی — داده فرضی',
				'room'       => 'SYNTHETIC',
				'is_active'  => 1,
			)
		);
		if ( $id <= 0 ) {
			throw new Exception( 'CLINICIAN_CREATE', 'ساخت پزشک آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * @param int             $clinician_id Clinician.
	 * @param list<int>       $location_ids Locations.
	 * @param int             $primary_id Primary location.
	 * @return void
	 */
	public function assign_clinician_locations( int $clinician_id, array $location_ids, int $primary_id ): void {
		App::membership_service()->assign_clinician_locations( $clinician_id, $location_ids, $primary_id );
	}

	/**
	 * LocationService requires an explicit IANA timezone; omitting it raises
	 * VALIDATION (observed in real WordPress acceptance).
	 *
	 * @param int    $actor_id Clinic manager.
	 * @param int    $clinic_id Clinic.
	 * @param string $name Location name.
	 * @param string $slug Location slug.
	 * @param string $timezone IANA timezone identifier.
	 * @return int Location id.
	 */
	public function create_location( int $actor_id, int $clinic_id, string $name, string $slug, string $timezone = 'Asia/Tehran' ): int {
		$result = App::locationService()->createLocation(
			$actor_id,
			$clinic_id,
			array(
				'name'     => $name,
				'slug'     => $slug,
				'timezone' => $timezone,
			)
		);
		$id     = isset( $result['location_id'] ) ? (int) $result['location_id'] : (int) ( $result['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new Exception( 'LOCATION_CREATE', 'ساخت شعبهٔ دوم آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * Weekly availability templates. Deliberately one minute long (shorter than
	 * the 20-minute appointment) so the production `slots.generate` job — which
	 * ScheduleService::create() enqueues — cannot manufacture future slots on
	 * the owner's host. The explicit open slots used by the demo are inserted
	 * separately with `generated_from = 'manual'`, exactly like a manual row.
	 *
	 * @param int $clinic_id Clinic.
	 * @param int $clinician_id Clinician.
	 * @param int $location_id Location.
	 * @return int Rows inserted.
	 */
	public function create_bounded_schedule( int $clinic_id, int $clinician_id, int $location_id ): int {
		$now     = $this->now_sql();
		$inserted = 0;
		for ( $day = 0; $day <= 6; $day++ ) {
			if ( $this->db()->insert(
				'cpms_schedule',
				array(
					'clinic_id'              => $clinic_id,
					'clinician_id'           => $clinician_id,
					'location_id'            => $location_id,
					'day_of_week'            => $day,
					'start_time'             => '00:00:00',
					'end_time'               => '00:01:00',
					'break_start'            => null,
					'break_end'              => null,
					'appointment_duration_min' => 20,
					'slot_capacity'          => 1,
					'is_active'              => 1,
					'created_at'             => $now,
					'updated_at'             => $now,
				)
			) ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * @param int    $clinic_id Clinic.
	 * @param int    $location_id Location.
	 * @param int    $clinician_id Clinician.
	 * @param string $date Y-m-d.
	 * @param string $time H:i:s.
	 * @return int Slot id.
	 */
	public function insert_slot( int $clinic_id, int $location_id, int $clinician_id, string $date, string $time ): int {
		$now = $this->now_sql();
		$this->db()->query(
			'INSERT IGNORE INTO ' . $this->db()->table( 'cpms_schedule_slots' )
			. ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)'
			. ' VALUES (%d, %d, %d, %s, %s, %d, %d, 0, 0, 1, %s, %s, %s)',
			array( $clinic_id, $location_id, $clinician_id, $date, $time, 20, 1, 'manual', $now, $now )
		);
		$id = (int) $this->db()->fetchValue(
			'SELECT id FROM ' . $this->db()->table( 'cpms_schedule_slots' )
			. ' WHERE clinic_id = %d AND location_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s LIMIT 1',
			array( $clinic_id, $location_id, $clinician_id, $date, $time )
		);
		if ( $id <= 0 ) {
			throw new Exception( 'SLOT_CREATE', 'ثبت اسلات آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * @param int    $actor_id Receptionist.
	 * @param array  $fields Patient fields.
	 * @return array<string,mixed>
	 */
	public function create_patient( int $actor_id, array $fields ): array {
		return App::patientService()->create( $fields, $actor_id );
	}

	/**
	 * @param int    $clinic_id Clinic.
	 * @param string $key Setting key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set_setting( int $clinic_id, string $key, $value ): void {
		App::settingsFactory()->forClinic( $clinic_id )->set( $key, $value );
	}

	/**
	 * @param int $clinic_id Clinic.
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get_setting( int $clinic_id, string $key ) {
		return App::settingsFactory()->forClinic( $clinic_id )->get( $key, null );
	}

	/**
	 * @param int    $actor_id Receptionist.
	 * @param int    $patient_id Patient.
	 * @param int    $clinician_id Clinician.
	 * @param string $date Y-m-d.
	 * @param string $time H:i:s.
	 * @param string $reason Reason.
	 * @param int    $slot_id Slot.
	 * @return int Appointment id.
	 */
	public function create_staff_appointment( int $actor_id, int $patient_id, int $clinician_id, string $date, string $time, string $reason, int $slot_id ): int {
		$result = App::bookingService()->createByStaff( $actor_id, $patient_id, $clinician_id, $date, $time, $reason, $slot_id );

		return (int) $result['appointment_id'];
	}

	/**
	 * @param int   $actor_id Clinic manager.
	 * @param array $input Service fields.
	 * @return int Service id.
	 */
	public function create_service( int $actor_id, array $input ): int {
		$result = App::financeService()->createService( $actor_id, $input );

		return (int) $result['id'];
	}

	/**
	 * @param int   $actor_id Receptionist.
	 * @param int   $patient_id Patient.
	 * @param int   $clinician_id Clinician.
	 * @param array $input Extra fields.
	 * @return int Visit id.
	 */
	public function walk_in( int $actor_id, int $patient_id, int $clinician_id, array $input ): int {
		$result = App::visitService()->walkIn( $actor_id, $patient_id, $clinician_id, $input );

		return (int) $result['id'];
	}

	/**
	 * @param int         $actor_id Actor.
	 * @param int         $visit_id Visit.
	 * @param string      $event Event.
	 * @param array       $extra Extra payload.
	 * @return void
	 */
	public function transition( int $actor_id, int $visit_id, string $event, array $extra = array() ): void {
		App::visitService()->transition( $actor_id, $visit_id, $event, $extra );
	}

	/**
	 * @param int   $actor_id Doctor.
	 * @param int   $visit_id Visit.
	 * @param array $note Note payload.
	 * @return int Note id.
	 */
	public function add_note( int $actor_id, int $visit_id, array $note ): int {
		$result = App::clinicalService()->addNote( $actor_id, $visit_id, $note );

		return (int) ( $result['id'] ?? 0 );
	}

	/**
	 * @param int   $actor_id Doctor.
	 * @param int   $visit_id Visit.
	 * @param array $items Prescription items.
	 * @return int Prescription id.
	 */
	public function create_prescription( int $actor_id, int $visit_id, array $items ): int {
		$result = App::clinicalService()->createPrescription( $actor_id, $visit_id, array( 'items' => $items ) );
		$id     = (int) ( $result['id'] ?? 0 );
		App::clinicalService()->finalizePrescription( $actor_id, $id );

		return $id;
	}

	/**
	 * @param int $actor_id Doctor.
	 * @param int $visit_id Visit.
	 * @return void
	 */
	public function complete_consultation( int $actor_id, int $visit_id ): void {
		App::clinicalService()->completeConsultation( $actor_id, $visit_id );
	}

	/**
	 * @param int   $actor_id Doctor.
	 * @param int   $visit_id Visit.
	 * @param array $input Follow-up fields.
	 * @return int Follow-up id.
	 */
	public function add_follow_up( int $actor_id, int $visit_id, array $input ): int {
		$result = App::clinicalService()->addFollowUp( $actor_id, $visit_id, $input );

		return (int) ( $result['id'] ?? 0 );
	}

	/**
	 * @param int   $actor_id Accountant.
	 * @param array $input Invoice.
	 * @return int Invoice id.
	 */
	public function issue_invoice( int $actor_id, array $input ): int {
		$result = App::financeService()->issueInvoice( $actor_id, $input );

		return (int) $result['id'];
	}

	/**
	 * @param int    $actor_id Accountant.
	 * @param int    $invoice_id Invoice.
	 * @param array  $input Payment.
	 * @param string $idempotency_key Idempotency key.
	 * @return int Payment id.
	 */
	public function record_payment( int $actor_id, int $invoice_id, array $input, string $idempotency_key ): int {
		$result = App::financeService()->recordPayment( $actor_id, $invoice_id, $input, $idempotency_key );

		// `paymentResult()` reports `payment_id`; asking for `id` silently yielded 0 and
		// left the synthetic payment out of the ownership map.
		return (int) ( $result['payment_id'] ?? $result['id'] ?? 0 );
	}

	/**
	 * @param int    $clinic_id Clinic.
	 * @param string $mobile Mobile.
	 * @return int Count of patients with that mobile outside the given clinic.
	 */
	public function patients_with_mobile_outside_clinic( int $clinic_id, string $mobile ): int {
		return (int) $this->db()->fetchValue(
			'SELECT COUNT(*) FROM ' . $this->db()->table( 'cpms_patients' ) . ' WHERE mobile = %s AND clinic_id <> %d',
			array( $mobile, $clinic_id )
		);
	}

	/**
	 * @param int    $clinic_id Clinic.
	 * @param string $mobile Mobile.
	 * @param string $purpose Purpose.
	 * @return array<string,mixed>|null Latest live, unconsumed challenge.
	 */
	public function latest_live_challenge( int $clinic_id, string $mobile, string $purpose ): ?array {
		$foreign_tokens = (int) $this->db()->fetchValue(
			'SELECT COUNT(*) FROM ' . $this->db()->table( 'cpms_otp_tokens' ) . ' WHERE mobile = %s AND (clinic_id IS NULL OR clinic_id <> %d)',
			array( $mobile, $clinic_id )
		);
		if ( $foreign_tokens > 0 ) {
			return null;
		}

		return $this->db()->fetchRow(
			'SELECT id, clinic_id, expires_at, attempts, consumed_at FROM ' . $this->db()->table( 'cpms_otp_tokens' )
			. ' WHERE clinic_id = %d AND mobile = %s AND purpose = %s AND consumed_at IS NULL'
			. ' ORDER BY id DESC LIMIT 1',
			array( $clinic_id, $mobile, $purpose )
		);
	}

	/**
	 * @return string OTP pepper exactly as the production service reads it.
	 * @throws Exception When the pepper is not initialised.
	 */
	public function otp_pepper(): string {
		$pepper = defined( 'CPMS_PEPPER' ) ? (string) CPMS_PEPPER : (string) get_option( 'cpms_otp_pepper', '' );
		if ( $pepper === '' ) {
			throw new Exception( 'OTP_PEPPER', 'Pepper سرور OTP مقداردهی نشده است؛ ابتدا از UI واقعی درخواست کد بدهید.' );
		}

		return $pepper;
	}

	/**
	 * Rebind the hash of one existing, live, zero-attempt challenge to a fresh
	 * CSPRNG code. Only the hash is written; the token row (expiry, attempts,
	 * lock, clinic binding, one-time consumption) is preserved untouched.
	 *
	 * @param int    $token_id Token row.
	 * @param string $code Fresh code.
	 * @param string $pepper Server pepper.
	 * @return bool True when exactly one row was updated.
	 */
	public function rebind_challenge_code( int $token_id, string $code, string $pepper ): bool {
		$updated = $this->db()->execute(
			'UPDATE ' . $this->db()->table( 'cpms_otp_tokens' ) . ' SET code_hash = %s'
			. ' WHERE id = %d AND consumed_at IS NULL AND attempts = 0',
			array( OtpPolicy::hashCode( $code, $pepper ), $token_id )
		);

		return $updated === 1;
	}

	/**
	 * @param string $code Code length default 6.
	 * @return string
	 */
	public function generate_otp_code( string $unused = '' ): string {
		unset( $unused );

		return OtpPolicy::generateCode( 6 );
	}

	/**
	 * @param string $action Action.
	 * @param int    $actor_id Actor.
	 * @param int    $clinic_id Clinic.
	 * @param array  $meta Meta (no secrets).
	 * @return void
	 */
	public function audit( string $action, int $actor_id, int $clinic_id, array $meta ): void {
		App::audit()->log(
			$action,
			array(
				'wp_user_id' => $actor_id,
				'role'       => 'wordpress_administrator',
			),
			'clinic',
			$clinic_id > 0 ? $clinic_id : null,
			null,
			null,
			$meta,
			array( 'op' => 'companion_disposable_demo' ),
			$clinic_id > 0 ? $clinic_id : null
		);
	}

	/**
	 * @return array<string,string> Portal URLs resolved from the production page options.
	 */
	public function portal_links(): array {
		$links = array(
			'patient'    => PatientPortalShell::portal_url(),
			'staff'      => StaffPortalShell::portal_url(),
			'management' => ManagementPortalShell::portal_url(),
		);
		$links['reception']    = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_RECEPTION, $links['staff'] );
		$links['receptionist'] = $links['reception'];
		$links['finance']      = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_FINANCE, $links['staff'] );
		$links['accountant']   = $links['finance'];
		$links['doctor']       = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_DOCTOR, $links['staff'] );
		$links['reports']      = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_REPORTS, $links['staff'] );
		$links['messages']     = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_MESSAGES, $links['staff'] );
		$links['pricing']      = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_PRICING, $links['staff'] );

		return $links;
	}

	/**
	 * @param int $clinic_id Clinic.
	 * @return string
	 */
	public function booking_shortcode( int $clinic_id ): string {
		return '[' . PublicBookingShortcode::TAG . ' clinic_id="' . $clinic_id . '"]';
	}

	// =====================================================================
	// Iran V1 (schema 0030): private staff messaging + doctor visit pricing.
	//
	// Every call below goes through the production service the Staff Portal
	// calls. The adapter adds no authority of its own: it only forwards the
	// acting WordPress user, because both services re-derive the Clinic scope,
	// the membership, the capability set and (for pricing) the durable
	// clinician identity from the database on every call.
	// =====================================================================

	/**
	 * Create a strictly-private one-to-one thread through StaffMessagingService.
	 *
	 * @param int      $actor_id     Acting staff WordPress user.
	 * @param int      $recipient_id Counterpart WordPress user.
	 * @param int|null $patient_id   Optional patient (requires $visit_id).
	 * @param int|null $visit_id     Optional visit (requires $patient_id).
	 * @return int Thread id.
	 */
	public function message_thread( int $actor_id, int $recipient_id, ?int $patient_id = null, ?int $visit_id = null ): int {
		$input = array( 'recipient_wp_user_id' => $recipient_id );
		if ( null !== $patient_id && null !== $visit_id ) {
			$input['patient_id'] = $patient_id;
			$input['visit_id']   = $visit_id;
		}
		$thread = App::staff_messaging_service()->create_thread( $actor_id, $input );
		$id     = (int) ( $thread['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new Exception( 'MESSAGE_THREAD_CREATE', 'ساخت گفتگوی آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * @param int    $actor_id  Sender.
	 * @param int    $thread_id Thread.
	 * @param string $body      Plain message text.
	 * @return int Message id.
	 */
	public function send_message( int $actor_id, int $thread_id, string $body ): int {
		$message = App::staff_messaging_service()->send( $actor_id, $thread_id, $body );
		$id      = (int) ( $message['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new Exception( 'MESSAGE_SEND', 'ارسال پیام آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * @param int $actor_id Actor.
	 * @return array<string,mixed>
	 */
	public function message_context( int $actor_id ): array {
		return App::staff_messaging_service()->context( $actor_id );
	}

	/**
	 * @param int $actor_id Actor.
	 * @return array<string,mixed>
	 */
	public function message_threads( int $actor_id ): array {
		return App::staff_messaging_service()->threads( $actor_id );
	}

	/**
	 * @param int $actor_id  Actor.
	 * @param int $thread_id Thread.
	 * @param int $since_id  Incremental cursor.
	 * @return array<string,mixed>
	 */
	public function message_list( int $actor_id, int $thread_id, int $since_id = 0 ): array {
		return App::staff_messaging_service()->messages( $actor_id, $thread_id, $since_id, 50 );
	}

	/**
	 * @param int $actor_id  Actor.
	 * @param int $thread_id Thread.
	 * @return array<string,mixed>
	 */
	public function mark_thread_read( int $actor_id, int $thread_id ): array {
		return App::staff_messaging_service()->mark_read( $actor_id, $thread_id );
	}

	/**
	 * Doctor default consultation fee for the acting doctor at one Location.
	 *
	 * @param int      $actor_id    Doctor WordPress user.
	 * @param int|null $location_id Location, or null for the Clinic-wide default.
	 * @param int      $amount      Amount in IRR.
	 * @return array<string,mixed>
	 */
	public function set_default_consultation_fee( int $actor_id, ?int $location_id, int $amount ): array {
		return App::visit_pricing_service()->set_default_fee( $actor_id, $location_id, $amount );
	}

	/**
	 * @param int $actor_id    Doctor WordPress user.
	 * @param int $location_id Location (0 = Clinic-wide).
	 * @return array<string,mixed>
	 */
	public function pricing_context( int $actor_id, int $location_id = 0 ): array {
		return App::visit_pricing_service()->context( $actor_id, $location_id );
	}

	/**
	 * @param int $actor_id Doctor WordPress user.
	 * @param int $visit_id Visit.
	 * @param int $amount   Amount in IRR.
	 * @return array<string,mixed>
	 */
	public function set_visit_consultation_fee( int $actor_id, int $visit_id, int $amount ): array {
		return App::visit_pricing_service()->set_visit_consultation_fee( $actor_id, $visit_id, $amount );
	}

	/**
	 * @param int                 $actor_id Doctor WordPress user.
	 * @param int                 $visit_id Visit.
	 * @param array<string,mixed> $input    Charge fields.
	 * @return int Charge id.
	 */
	public function add_visit_charge( int $actor_id, int $visit_id, array $input ): int {
		$result = App::visit_pricing_service()->add_charge( $actor_id, $visit_id, $input );
		$id     = (int) ( $result['charge_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new Exception( 'VISIT_CHARGE_CREATE', 'افزودن قلم درمانی آزمایشی ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * @param int                 $actor_id  Doctor WordPress user.
	 * @param int                 $visit_id  Visit.
	 * @param int                 $charge_id Charge.
	 * @param array<string,mixed> $input     Replacement fields.
	 * @return array<string,mixed>
	 */
	public function update_visit_charge( int $actor_id, int $visit_id, int $charge_id, array $input ): array {
		return App::visit_pricing_service()->update_charge( $actor_id, $visit_id, $charge_id, $input );
	}

	/**
	 * Itemized billing projection (doctor or receptionist with invoice read).
	 *
	 * @param int $actor_id Actor WordPress user.
	 * @param int $visit_id Visit.
	 * @return array<string,mixed>
	 */
	public function visit_billing( int $actor_id, int $visit_id ): array {
		return App::visit_pricing_service()->billing_for_visit( $actor_id, $visit_id );
	}

	/**
	 * Issue an invoice from the doctor's pricing through the EXISTING finance
	 * engine (no second billing implementation).
	 *
	 * @param int $actor_id Actor with invoice-create authority.
	 * @param int $visit_id Visit.
	 * @return int Invoice id.
	 */
	public function issue_invoice_from_pricing( int $actor_id, int $visit_id ): int {
		$result = App::financeService()->issue_invoice_from_pricing( $actor_id, $visit_id );
		$id     = (int) ( $result['id'] ?? $result['invoice_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new Exception( 'INVOICE_PRICING_ISSUE', 'صدور فاکتور از تعرفهٔ پزشک ناموفق بود.' );
		}

		return $id;
	}

	/**
	 * Read-only invoice state for fixture assertions.
	 *
	 * @param int $invoice_id Invoice.
	 * @return array<string,mixed>|null
	 */
	public function invoice_row( int $invoice_id ): ?array {
		return $this->fetch_row(
			'SELECT id, visit_id, clinic_id, status, subtotal, total, paid_amount, balance, currency FROM '
			. $this->db()->table( 'cpms_invoices' ) . ' WHERE id = %d',
			array( $invoice_id )
		);
	}

	/**
	 * @param int $invoice_id Invoice.
	 * @return list<array<string,mixed>>
	 */
	public function invoice_items( int $invoice_id ): array {
		return $this->fetch_all(
			'SELECT id, description, quantity, unit_price, amount FROM '
			. $this->db()->table( 'cpms_invoice_items' ) . ' WHERE invoice_id = %d ORDER BY id ASC',
			array( $invoice_id )
		);
	}

	/**
	 * @param int $invoice_id Invoice.
	 * @return list<array<string,mixed>>
	 */
	public function invoice_payments( int $invoice_id ): array {
		return $this->fetch_all(
			'SELECT id, amount, method, status, idempotency_key FROM '
			. $this->db()->table( 'cpms_payments' ) . ' WHERE invoice_id = %d ORDER BY id ASC',
			array( $invoice_id )
		);
	}

	/**
	 * @param int $visit_id Visit.
	 * @return string Visit status.
	 */
	public function visit_status( int $visit_id ): string {
		$row = $this->fetch_row(
			'SELECT status FROM ' . $this->db()->table( 'cpms_visits' ) . ' WHERE id = %d',
			array( $visit_id )
		);

		return null === $row ? '' : (string) ( $row['status'] ?? '' );
	}

	/**
	 * @return list<string> CPMS role keys used by the demo personas.
	 */
	public function role_keys(): array {
		return array(
			'patient'     => RolesAndCapabilities::ROLE_PATIENT,
			'doctor'      => RolesAndCapabilities::ROLE_DOCTOR,
			'receptionist' => RolesAndCapabilities::ROLE_SECRETARY,
			'accountant'  => RolesAndCapabilities::ROLE_ACCOUNTANT,
			'clinic_manager' => RolesAndCapabilities::ROLE_MANAGER,
		);
	}

	/**
	 * @return bool
	 */
	public function otp_echo_defined(): bool {
		return defined( 'CPMS_DEV_OTP_ECHO' );
	}
}
