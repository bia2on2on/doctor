<?php
/**
 * Exact-ID, fail-closed teardown for the separate scoped demo mode.
 *
 * Unlike the original disposable teardown, this never deletes audit-log rows.
 * It verifies a sealed baseline, collects exact fixture-owned IDs from live
 * rows, preserves shared/global counters and unrelated queue jobs, and refuses
 * to claim success while any owned clinical or financial row remains.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped teardown and read-only ownership preview.
 */
class ScopedTeardown {

	private const GLOBAL_TABLES = array(
		'cpms_jobs',
		'cpms_operational_logs',
		'cpms_rate_limits',
		'cpms_audit_logs',
	);

	/**
	 * The append-only audit log has a foreign key to cpms_clinics. Because the
	 * scoped mode must preserve every audit row and cannot change the production
	 * schema, these three synthetic parent rows are archived in place as inert
	 * audit anchors rather than physically deleted.
	 */
	private const PRESERVED_PARENT_TABLES = array(
		'cpms_organizations',
		'cpms_clinics',
		'cpms_locations',
	);

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	/** @var ScopedAuditGuard */
	private ScopedAuditGuard $audit_guard;

	/** @var ScopedBaseline */
	private ScopedBaseline $baseline;

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedAuditGuard $audit_guard, ScopedBaseline $baseline ) {
		$this->env         = $env;
		$this->platform    = $platform;
		$this->manifest    = $manifest;
		$this->audit_guard = $audit_guard;
		$this->baseline    = $baseline;
		$this->platform->set_env( $env );
	}

	/**
	 * Collect exact owned IDs from live rows, with baseline max ID as a
	 * secondary guard. Ownership is proven by verified parent IDs, never by
	 * timestamp, request ID, IP, or masked mobile suffix.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @return array<string,mixed>
	 */
	public function collect_scope( array $manifest, array $baseline ): array {
		$ids       = (array) ( $manifest['ids'] ?? array() );
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$clinic_id = (int) ( $ids['clinic_id'] ?? 0 );
		$org_id    = (int) ( $ids['organization_id'] ?? 0 );
		$mobile    = (string) ( $manifest['patient_mobile'] ?? '' );
		$user_ids  = array_values( array_filter( array_map( 'intval', (array) ( $ids['user_ids'] ?? array() ) ) ) );
		$locations = array_values( array_filter( array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) ) ) );

		$sets = array(
			'clinic_ids'        => $clinic_id > 0 ? array( $clinic_id ) : array(),
			'org_ids'           => $org_id > 0 ? array( $org_id ) : array(),
			'location_ids'      => $locations,
			'user_ids'          => $user_ids,
			'patient_ids'       => isset( $ids['patient_id'] ) ? array( (int) $ids['patient_id'] ) : array(),
			'identity_ids'      => isset( $ids['identity_id'] ) ? array( (int) $ids['identity_id'] ) : array(),
			'clinician_ids'     => isset( $ids['clinician_id'] ) ? array( (int) $ids['clinician_id'] ) : array(),
			'schedule_ids'      => array(),
			'slot_ids'          => array_values( array_filter( array_map( 'intval', (array) ( $ids['slot_ids'] ?? array() ) ) ) ),
			'appointment_ids'   => isset( $ids['appointment_id'] ) ? array( (int) $ids['appointment_id'] ) : array(),
			'visit_ids'         => isset( $ids['visit_id'] ) ? array( (int) $ids['visit_id'] ) : array(),
			'note_ids'          => array_values( array_filter( array_map( 'intval', (array) ( $ids['note_ids'] ?? array() ) ) ) ),
			'prescription_ids'  => isset( $ids['prescription_id'] ) ? array( (int) $ids['prescription_id'] ) : array(),
			'invoice_ids'       => isset( $ids['invoice_id'] ) ? array( (int) $ids['invoice_id'] ) : array(),
			'payment_ids'       => isset( $ids['payment_id'] ) ? array( (int) $ids['payment_id'] ) : array(),
			'service_ids'       => isset( $ids['service_id'] ) ? array( (int) $ids['service_id'] ) : array(),
			'follow_up_ids'     => isset( $ids['follow_up_id'] ) ? array( (int) $ids['follow_up_id'] ) : array(),
			'membership_ids'    => array(),
			'role_def_ids'      => array(),
			'document_ids'      => array(),
			'page_ids'          => array(),
			'sms_message_ids'   => array(),
			'otp_token_ids'     => array(),
			'drug_ref_ids'      => array(),
			'attachment_ids'    => array(),
			'ocr_job_ids'       => array(),
			'hold_ids'          => array(),
			'exception_ids'     => array(),
		);

		$column_map = array(
			'clinic_id'                  => 'clinic_ids',
			'organization_id'            => 'org_ids',
			'location_id'                => 'location_ids',
			'patient_id'                 => 'patient_ids',
			'identity_id'                => 'identity_ids',
			'clinician_id'               => 'clinician_ids',
			'schedule_id'                => 'schedule_ids',
			'slot_id'                    => 'slot_ids',
			'appointment_id'             => 'appointment_ids',
			'linked_appointment_id'      => 'appointment_ids',
			'rescheduled_from'           => 'appointment_ids',
			'rescheduled_to'             => 'appointment_ids',
			'visit_id'                   => 'visit_ids',
			'note_id'                    => 'note_ids',
			'prescription_id'            => 'prescription_ids',
			'invoice_id'                 => 'invoice_ids',
			'payment_id'                 => 'payment_ids',
			'service_id'                 => 'service_ids',
			'follow_up_id'               => 'follow_up_ids',
			'membership_id'              => 'membership_ids',
			'role_def_id'                => 'role_def_ids',
			'document_id'                => 'document_ids',
			'page_id'                    => 'page_ids',
			'source_page_id'             => 'page_ids',
			'message_id'                 => 'sms_message_ids',
			'token_id'                   => 'otp_token_ids',
			'drug_ref_id'                => 'drug_ref_ids',
			'background_attachment_id'   => 'attachment_ids',
			'ocr_job_id'                 => 'ocr_job_ids',
			'hold_id'                    => 'hold_ids',
			'exception_id'               => 'exception_ids',
			'surviving_patient_id'       => 'patient_ids',
			'merged_patient_id'          => 'patient_ids',
			'wp_user_id'                 => 'user_ids',
			'actor_wp_user_id'           => 'user_ids',
			'created_by_wp_user_id'      => 'user_ids',
			'updated_by_wp_user_id'      => 'user_ids',
			'approved_by_wp_user_id'     => 'user_ids',
			'merged_by_wp_user_id'       => 'user_ids',
			'invited_by_wp_user_id'      => 'user_ids',
			'holder_wp_user_id'          => 'user_ids',
			'recipient_wp_user_id'       => 'user_ids',
			'status_updated_by_wp_user_id' => 'user_ids',
		);

		$table_to_set = array(
			'cpms_organizations'                     => 'org_ids',
			'cpms_clinics'                           => 'clinic_ids',
			'cpms_locations'                         => 'location_ids',
			'cpms_patients'                          => 'patient_ids',
			'cpms_patient_identities'                => 'identity_ids',
			'cpms_clinicians'                        => 'clinician_ids',
			'cpms_schedule'                          => 'schedule_ids',
			'cpms_schedule_slots'                    => 'slot_ids',
			'cpms_appointments'                      => 'appointment_ids',
			'cpms_visits'                            => 'visit_ids',
			'cpms_clinical_notes'                    => 'note_ids',
			'cpms_prescriptions'                     => 'prescription_ids',
			'cpms_invoices'                          => 'invoice_ids',
			'cpms_payments'                          => 'payment_ids',
			'cpms_services'                          => 'service_ids',
			'cpms_follow_ups'                        => 'follow_up_ids',
			'cpms_clinic_memberships'                => 'membership_ids',
			'cpms_custom_role_defs'                  => 'role_def_ids',
			'cpms_handwriting_documents'             => 'document_ids',
			'cpms_handwriting_pages'                 => 'page_ids',
			'cpms_sms_messages'                      => 'sms_message_ids',
			'cpms_otp_tokens'                        => 'otp_token_ids',
			'cpms_drug_reference'                    => 'drug_ref_ids',
			'cpms_medical_attachments'               => 'attachment_ids',
			'cpms_ocr_jobs'                          => 'ocr_job_ids',
			'cpms_slot_holds'                        => 'hold_ids',
			'cpms_schedule_exceptions'               => 'exception_ids',
		);

		$baseline_max = array();
		foreach ( (array) ( $baseline['cpms'] ?? array() ) as $short => $info ) {
			$baseline_max[ $short ] = (int) ( $info['max_id'] ?? 0 );
		}

		$owned_tables = array();
		for ( $pass = 0; $pass < 7; $pass++ ) {
			$changed = false;
			foreach ( Preflight::KNOWN_TABLES as $short ) {
				if ( in_array( $short, self::GLOBAL_TABLES, true ) || ! $this->platform->table_exists( $short ) ) {
					continue;
				}
				$full = $this->platform->table( $short );
				$cols = $this->platform->columns( $full );
				if ( ! in_array( 'id', $cols, true ) ) {
					continue;
				}
				$clauses = array();
				$params  = array();
				foreach ( $column_map as $column => $set_key ) {
					if ( ! in_array( $column, $cols, true ) || array() === ( $sets[ $set_key ] ?? array() ) ) {
						continue;
					}
					$values = array_values( array_unique( array_filter( array_map( 'intval', $sets[ $set_key ] ) ) ) );
					if ( array() === $values ) {
						continue;
					}
					$ph         = implode( ',', array_fill( 0, count( $values ), '%d' ) );
					$clauses[]  = '`' . $column . '` IN (' . $ph . ')';
					$params     = array_merge( $params, $values );
				}
				if ( 'cpms_organizations' === $short && $org_id > 1 ) {
					$clauses[] = 'id = %d';
					$params[]  = $org_id;
				}
				if ( 'cpms_clinics' === $short && $clinic_id > 1 ) {
					$clauses[] = 'id = %d';
					$params[]  = $clinic_id;
				}
				if ( 'cpms_locations' === $short && array() !== $locations ) {
					$ph        = implode( ',', array_fill( 0, count( $locations ), '%d' ) );
					$clauses[] = 'id IN (' . $ph . ')';
					$params    = array_merge( $params, $locations );
				}
				if ( array() === $clauses ) {
					continue;
				}
				$max    = (int) ( $baseline_max[ $short ] ?? 0 );
				$params[] = $max;
				$sql = 'SELECT id FROM `' . $full . '` WHERE (' . implode( ' OR ', $clauses ) . ') AND id > %d ORDER BY id ASC';
				$rows = $this->platform->fetch_all( $sql, $params );
				foreach ( $rows as $row ) {
					$row_id = (int) ( $row['id'] ?? 0 );
					if ( $row_id <= 0 || isset( $owned_tables[ $short ][ $row_id ] ) ) {
						continue;
					}
					$owned_tables[ $short ][ $row_id ] = true;
					$changed = true;
					$set_key = $table_to_set[ $short ] ?? '';
					if ( '' !== $set_key ) {
						$sets[ $set_key ][] = $row_id;
						$sets[ $set_key ] = array_values( array_unique( $sets[ $set_key ] ) );
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}

		$tables = array();
		foreach ( $owned_tables as $short => $map ) {
			$tables[ $short ] = array_map( 'intval', array_keys( $map ) );
			sort( $tables[ $short ] );
		}

		$entity_ids = array();
		foreach ( $sets as $values ) {
			foreach ( $values as $value ) {
				$entity_ids[ (int) $value ] = true;
			}
		}
		foreach ( $tables as $ids_list ) {
			foreach ( $ids_list as $value ) {
				$entity_ids[ (int) $value ] = true;
			}
		}

		$jobs              = array();
		$processing_jobs   = array();
		$job_baseline      = (int) ( $baseline_max['cpms_jobs'] ?? 0 );
		$rows              = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_jobs' ) . '` WHERE id > %d ORDER BY id ASC', array( $job_baseline ) );
		foreach ( $rows as $row ) {
			$payload_raw = (string) ( $row['payload_json'] ?? '' );
			$payload     = json_decode( $payload_raw, true );
			$is_owned    = false;
			if ( is_array( $payload ) ) {
				if ( isset( $payload['clinic_id'] ) && (int) $payload['clinic_id'] === $clinic_id ) {
					$is_owned = true;
				}
				if ( isset( $payload['message_id'] ) && in_array( (int) $payload['message_id'], $sets['sms_message_ids'], true ) ) {
					$is_owned = true;
				}
				if ( '' !== $fixture && false !== strpos( $payload_raw, $fixture ) ) {
					$is_owned = true;
				}
			}
			if ( ! $is_owned ) {
				continue;
			}
			$job_id = (int) ( $row['id'] ?? 0 );
			if ( 'processing' === (string) ( $row['status'] ?? '' ) ) {
				$processing_jobs[] = $job_id;
			} else {
				$jobs[] = $job_id;
			}
		}

		$oplogs         = array();
		$oplog_baseline = (int) ( $baseline_max['cpms_operational_logs'] ?? 0 );
		$rows           = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_operational_logs' ) . '` WHERE id > %d ORDER BY id ASC', array( $oplog_baseline ) );
		foreach ( $rows as $row ) {
			$context = json_decode( (string) ( $row['context_json'] ?? '' ), true );
			if ( is_array( $context ) && $this->context_matches( $context, $fixture, $entity_ids, $mobile ) ) {
				$oplogs[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$audit_logs    = array();
		$audit_baseline = (int) ( $baseline_max['cpms_audit_logs'] ?? 0 );
		$rows          = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_audit_logs' ) . '` WHERE id > %d ORDER BY id ASC', array( $audit_baseline ) );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['clinic_id'] ?? 0 ) !== $clinic_id ) {
				continue;
			}
			$json = (string) ( $row['meta_json'] ?? '' ) . (string) ( $row['after_json'] ?? '' ) . (string) ( $row['before_json'] ?? '' );
			if ( '' !== $fixture && false !== strpos( $json, $fixture ) ) {
				$audit_logs[] = (int) ( $row['id'] ?? 0 );
				continue;
			}
			if ( isset( $entity_ids[ (int) ( $row['resource_id'] ?? 0 ) ] ) || in_array( (int) ( $row['actor_wp_user_id'] ?? 0 ), $user_ids, true ) ) {
				$audit_logs[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$rate_keys    = $this->allowed_rate_limit_keys( $fixture, $mobile, $user_ids, $manifest, $baseline );
		$rate_keys    = array_values( array_unique( array_filter( $rate_keys ) ) );
		$shared_keys  = array();
		foreach ( $rate_keys as $key ) {
			if ( 0 === strpos( $key, 'otp-ip:' ) || 0 === strpos( $key, 'otp-verify-ip:' ) || 0 === strpos( $key, 'login-ip:' ) ) {
				$shared_keys[] = $key;
			}
		}

		return array(
			'fixture_id'       => $fixture,
			'clinic_id'        => $clinic_id,
			'organization_id'  => $org_id,
			'user_ids'         => $user_ids,
			'tables'           => $tables,
			'jobs'             => $jobs,
			'processing_jobs'  => $processing_jobs,
			'operational_logs' => $oplogs,
			'audit_logs'       => $audit_logs,
			'rate_limit_keys'  => $rate_keys,
			'shared_keys'      => $shared_keys,
			'entity_ids'       => array_map( 'intval', array_keys( $entity_ids ) ),
		);
	}

	/**
	 * Read-only teardown preview.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{ok:bool,problems:list<string>,plan:list<array<string,mixed>>,scope:array<string,mixed>,baseline:array<string,mixed>,audit:array<string,mixed>}
	 */
	public function preview( array $manifest ): array {
		$problems = array();
		if ( ! $this->manifest->verify( $manifest ) ) {
			return $this->failed_preview( array( 'MANIFEST_TAMPERED' ) );
		}
		$baseline = $this->resolve_baseline( $manifest );
		if ( array() === $baseline ) {
			return $this->failed_preview( array( 'BASELINE_MISSING' ) );
		}
		$baseline_result = $this->baseline->verify( $baseline );
		if ( ! $baseline_result['ok'] ) {
			$problems = array_merge( $problems, $baseline_result['problems'] );
		}
		$audit = $this->audit_guard->verify_chain();
		if ( ! $audit['ok'] ) {
			$problems[] = $audit['error'];
		}
		$scope = $this->collect_scope( $manifest, $baseline );
		if ( array() !== $scope['processing_jobs'] ) {
			$problems[] = 'JOBS_ACTIVE: ' . implode( ',', $scope['processing_jobs'] );
		}
		if ( array() !== $scope['shared_keys'] ) {
			$problems[] = 'SHARED_RATE_LIMIT_KEY';
		}
		global $wpdb;
		$free = $wpdb->get_var( "SELECT IS_FREE_LOCK('cpms_jobs_tick')" );
		if ( '1' !== (string) $free && 1 !== $free ) {
			$problems[] = 'QUEUE_RUNNER_ACTIVE';
		}
		$problems = array_merge( $problems, $this->scan_untracked_fixture_rows( $manifest, $baseline, $scope, false ) );

		$plan = array();
		foreach ( $scope['tables'] as $short => $ids ) {
			if ( in_array( $short, self::PRESERVED_PARENT_TABLES, true ) ) {
				continue;
			}
			$plan[] = array( 'table' => $short, 'rows' => count( $ids ) );
		}
		foreach ( self::PRESERVED_PARENT_TABLES as $short ) {
			$plan[] = array( 'table' => $short . ' (preserved audit anchor)', 'rows' => count( (array) ( $scope['tables'][ $short ] ?? array() ) ) );
		}
		$plan[] = array( 'table' => 'cpms_jobs', 'rows' => count( $scope['jobs'] ) );
		$plan[] = array( 'table' => 'cpms_operational_logs', 'rows' => count( $scope['operational_logs'] ) );
		$plan[] = array( 'table' => 'cpms_rate_limits', 'rows' => count( $scope['rate_limit_keys'] ) );
		$plan[] = array( 'table' => 'cpms_audit_logs (preserved)', 'rows' => count( $scope['audit_logs'] ) );
		$plan[] = array( 'table' => 'wp_users', 'rows' => count( $scope['user_ids'] ) );
		$plan[] = array( 'table' => 'wp_posts', 'rows' => isset( $manifest['ids']['booking_page_id'] ) ? 1 : 0 );

		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'plan'     => $plan,
			'scope'    => $scope,
			'baseline' => $baseline_result,
			'audit'    => $audit,
		);
	}

	/**
	 * Execute verified teardown under both concurrency guards.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	public function execute( array $manifest, int $actor_id ): array {
		return $this->env->with_lock(
			'scoped_teardown',
			function () use ( $manifest, $actor_id ): array {
				return $this->do_execute( $manifest, $actor_id );
			}
		);
	}

	/**
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	private function do_execute( array $manifest, int $actor_id ): array {
		$manifest = $this->adopt_recovered_baseline( $manifest );
		$preview  = $this->preview( $manifest );
		if ( ! $preview['ok'] ) {
			throw new Exception( 'TEARDOWN_BLOCKED', 'پاکسازی ایمن متوقف شد: ' . implode( ' | ', array_slice( $preview['problems'], 0, 3 ) ) );
		}
		global $wpdb;
		$tick = $wpdb->get_var( "SELECT GET_LOCK('cpms_jobs_tick', 0)" );
		if ( '1' !== (string) $tick && 1 !== $tick ) {
			throw new Exception( 'JOBS_ACTIVE', 'قفل صف CPMS در اختیار اجراکننده دیگری است.' );
		}
		try {
			$manifest['status']         = 'tearing_down';
			$manifest['scope_snapshot'] = $preview['scope'];
			$manifest                   = $this->manifest->save( $manifest );
			$deleted                    = array();
			$stage                      = 'start';
			try {
				$stage   = 'sessions';
				$user_ids = (array) ( $preview['scope']['user_ids'] ?? array() );
				foreach ( $user_ids as $user_id ) {
					$this->env->destroy_sessions( (int) $user_id );
					$this->env->set_user_password( (int) $user_id, Credentials::generate_password() );
				}
				$stage = 'suspend-clinic';
				$this->suspend_clinic( $manifest );
				$stage = 'global';
				$deleted = array_merge( $deleted, $this->delete_global_rows( $preview['scope'] ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-global', array( 'stage' => $stage ) );
				$stage = 'cpms-tables';
				$deleted = array_merge( $deleted, $this->delete_table_rows( $preview['scope']['tables'] ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-tables', array( 'stage' => $stage ) );
				$stage = 'wp-core';
				$deleted = array_merge( $deleted, $this->delete_wp_records( $manifest, $user_ids ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-wp', array( 'stage' => $stage ) );
				$stage = 'post-verify';
				$this->env->maybe_inject_fault( 'scoped_teardown:before-post-verify', array( 'stage' => $stage ) );
				$remaining = $this->post_verify( $manifest );
				if ( array() !== $remaining ) {
					throw new Exception( 'TEARDOWN_INCOMPLETE', 'حذف کامل اثبات نشد: ' . implode( ', ', array_slice( $remaining, 0, 5 ) ) );
				}
			} catch ( \Throwable $error ) {
				$manifest['status']          = 'teardown_failed';
				$manifest['failed_stage']    = $stage;
				$manifest['failure_code']    = $error instanceof Exception ? $error->demo_code() : 'TEARDOWN_FAILED';
				$manifest['failure_message'] = $error->getMessage();
				$manifest['partial_deleted'] = $deleted;
				$this->manifest->save( $manifest );
				throw $error;
			}

			$tombstone = array(
				'fixture_id'       => (string) $manifest['fixture_id'],
				'removed_at'       => $this->env->now_sql(),
				'at'               => $this->env->now_sql(),
				'actor_id'         => $actor_id,
				'deleted'          => $deleted,
				'preserved_audit'  => count( (array) ( $preview['scope']['audit_logs'] ?? array() ) ),
				'preserved_parent_anchors' => self::PRESERVED_PARENT_TABLES,
				'baseline'         => $preview['baseline'],
				'audit_chain'      => $preview['audit'],
				'host_before'      => (array) ( $manifest['host_before'] ?? array() ),
				'host_after'       => $this->host_evidence(),
				'remaining'        => array(),
				'clean'            => true,
				'ok'               => true,
			);
			$this->env->option_delete( ScopedManifest::LOCK_OPTION );
			$this->env->option_delete( 'cpsd_had_clean_teardown' );
			$this->manifest->clear_pending_baseline();
			$this->manifest->disable_mode();
			$this->manifest->write_tombstone( $tombstone );
			$this->manifest->delete();

			return array(
				'ok'        => true,
				'deleted'   => $deleted,
				'remaining' => array(),
				'tombstone' => $tombstone,
			);
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('cpms_jobs_tick')" );
		}
	}

	/**
	 * Adopt the sealed pre-provisioning baseline into a manifest that never got one.
	 *
	 * A run interrupted during provisioning stops before the baseline is sealed
	 * into the manifest, so the recovery teardown has to take it from the sealed
	 * pending record. Any change to the payload invalidates the HMAC, so the
	 * adopted manifest is re-sealed (and persisted) before it is verified again.
	 *
	 * @param array<string,mixed> $manifest Manifest as loaded.
	 * @return array<string,mixed> Manifest with a sealed baseline.
	 */
	private function adopt_recovered_baseline( array $manifest ): array {
		if ( array() !== (array) ( $manifest['baseline'] ?? array() ) ) {
			return $manifest;
		}
		$recovered = $this->resolve_baseline( $manifest );
		if ( array() === $recovered ) {
			return $manifest;
		}
		$manifest['baseline'] = $recovered;
		if ( array() === (array) ( $manifest['host_before'] ?? array() ) ) {
			$pending = $this->manifest->load_pending_baseline( (string) ( $manifest['fixture_id'] ?? '' ) );
			if ( is_array( $pending ) ) {
				$manifest['host_before'] = (array) ( $pending['host_before'] ?? array() );
			}
		}

		return $this->manifest->save( $manifest );
	}

	/**
	 * Baseline for this fixture: the manifest's sealed copy, or — for a run that
	 * was interrupted during provisioning — the sealed pre-provisioning record.
	 *
	 * An absent, tampered, cross-site or cross-fixture record yields an empty
	 * array, which every caller treats as a refusal.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array<string,mixed>
	 */
	private function resolve_baseline( array $manifest ): array {
		$baseline = (array) ( $manifest['baseline'] ?? array() );
		if ( array() !== $baseline ) {
			return $baseline;
		}
		$pending = $this->manifest->load_pending_baseline( (string) ( $manifest['fixture_id'] ?? '' ) );

		return is_array( $pending ) ? (array) ( $pending['baseline'] ?? array() ) : array();
	}

	/**
	 * Read-only host counters recorded in the tombstone as before/after evidence.
	 *
	 * @return array<string,int>
	 */
	private function host_evidence(): array {
		return array(
			'clinic_count'        => $this->platform->count_all( 'cpms_clinics' ),
			'organization_count'  => $this->platform->count_all( 'cpms_organizations' ),
			'location_count'      => $this->platform->count_all( 'cpms_locations' ),
			'active_clinic_count' => $this->platform->count_where( $this->platform->table( 'cpms_clinics' ), 'status = %s', array( 'active' ) ),
			'audit_max_id'        => $this->platform->max_id( 'cpms_audit_logs' ),
		);
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @return void
	 */
	private function suspend_clinic( array $manifest ): void {
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$slug      = (string) ( $manifest['slugs']['clinic'] ?? '' );
		if ( $clinic_id <= 1 || $org_id <= 1 || '' === $slug ) {
			return;
		}
		global $wpdb;
		$table = $this->platform->table( 'cpms_clinics' );
		$wpdb->update(
			$table,
			array( 'status' => 'suspended' ),
			array( 'id' => $clinic_id, 'organization_id' => $org_id, 'slug' => $slug ),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $scope Ownership scope.
	 * @return array<string,int>
	 */
	private function delete_global_rows( array $scope ): array {
		$deleted = array();
		$deleted['cpms_jobs'] = $this->delete_ids( 'cpms_jobs', (array) ( $scope['jobs'] ?? array() ) );
		$deleted['cpms_operational_logs'] = $this->delete_ids( 'cpms_operational_logs', (array) ( $scope['operational_logs'] ?? array() ) );
		$deleted['cpms_rate_limits'] = $this->delete_rate_keys( (array) ( $scope['rate_limit_keys'] ?? array() ) );
		$deleted['cpms_audit_logs'] = 0;
		return $deleted;
	}

	/**
	 * @param array<string,list<int>> $tables Owned table IDs.
	 * @return array<string,int>
	 */
	private function delete_table_rows( array $tables ): array {
		$deleted = array();
		$skip    = array_merge( self::GLOBAL_TABLES, self::PRESERVED_PARENT_TABLES );
		$order   = array_values( array_diff( Teardown::DELETE_ORDER, $skip ) );
		foreach ( $order as $short ) {
			$ids = (array) ( $tables[ $short ] ?? array() );
			$deleted[ $short ] = $this->delete_ids( $short, $ids );
		}
		$this->archive_parent_anchors( $tables );
		foreach ( self::PRESERVED_PARENT_TABLES as $short ) {
			$deleted[ $short . ' (preserved audit anchor)' ] = 0;
		}
		return $deleted;
	}

	/**
	 * Mark the synthetic parent rows inert without deleting audit FK targets.
	 *
	 * @param array<string,list<int>> $tables Owned table IDs.
	 * @return void
	 */
	private function archive_parent_anchors( array $tables ): void {
		global $wpdb;
		$org_ids     = (array) ( $tables['cpms_organizations'] ?? array() );
		$clinic_ids  = (array) ( $tables['cpms_clinics'] ?? array() );
		$location_ids = (array) ( $tables['cpms_locations'] ?? array() );
		foreach ( $org_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_organizations' ), array( 'status' => 'suspended' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		}
		foreach ( $clinic_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_clinics' ), array( 'status' => 'suspended' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		}
		foreach ( $location_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_locations' ), array( 'is_active' => 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
		}
	}

	/**
	 * @param string $short Short table name.
	 * @param list<int> $ids Exact IDs.
	 * @return int
	 */
	private function delete_ids( string $short, array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids || ! $this->platform->table_exists( $short ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->platform->delete_where( $this->platform->table( $short ), 'id IN (' . $ph . ')', $ids );
	}

	/**
	 * @param list<string> $keys Exact fixture-owned rate-limit keys.
	 * @return int
	 */
	private function delete_rate_keys( array $keys ): int {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
		$filter = static function ( string $key ): bool {
			return 0 !== strpos( $key, 'otp-ip:' ) && 0 !== strpos( $key, 'otp-verify-ip:' ) && 0 !== strpos( $key, 'login-ip:' );
		};
		$keys = array_values( array_filter( $keys, $filter ) );
		if ( array() === $keys || ! $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return $this->platform->delete_where( $this->platform->table( 'cpms_rate_limits' ), 'window_key IN (' . $ph . ')', $keys );
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @param list<int> $user_ids Persona user IDs.
	 * @return array<string,int>
	 */
	private function delete_wp_records( array $manifest, array $user_ids ): array {
		$deleted = array( 'wp_users' => 0, 'wp_posts' => 0 );
		global $wpdb;
		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		if ( $page_id > 0 ) {
			$page = $this->env->post( $page_id );
			if ( is_array( $page ) && 'page' === (string) ( $page['type'] ?? '' ) && (string) ( $page['slug'] ?? '' ) === (string) ( $manifest['slugs']['page'] ?? '' ) ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $page_id ), array( '%d' ) );
				if ( $this->env->delete_post( $page_id ) ) {
					$deleted['wp_posts'] = 1;
				}
			}
		}
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$account = $this->env->user( $user_id );
			if ( ! is_array( $account ) ) {
				continue;
			}
			$login = (string) ( $account['login'] ?? '' );
			if ( 0 !== strpos( $login, 'cpsd-' ) || false === strpos( $login, (string) $manifest['fixture_id'] ) ) {
				throw new Exception( 'OWNERSHIP_UNPROVEN', 'یک حساب هدف با شناسه دمو همخوان نیست.' );
			}
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $this->env->delete_user( $user_id ) ) {
				++$deleted['wp_users'];
			}
		}
		return $deleted;
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @return list<string>
	 */
	private function post_verify( array $manifest ): array {
		$remaining = array();
		$baseline  = $this->resolve_baseline( $manifest );
		$verify    = $this->baseline->verify( $baseline );
		if ( ! $verify['ok'] ) {
			$remaining = array_merge( $remaining, $verify['problems'] );
		}
		$chain = $this->audit_guard->verify_chain();
		if ( ! $chain['ok'] ) {
			$remaining[] = 'AUDIT_CHAIN_BROKEN';
		}
		$scope = $this->collect_scope( $manifest, $baseline );
		foreach ( $scope['tables'] as $short => $ids ) {
			if ( in_array( $short, self::PRESERVED_PARENT_TABLES, true ) ) {
				continue;
			}
			if ( array() !== $ids ) {
				$remaining[] = $short . ':' . count( $ids );
			}
		}
		if ( array() !== $scope['jobs'] || array() !== $scope['processing_jobs'] ) {
			$remaining[] = 'cpms_jobs';
		}
		if ( array() !== $scope['operational_logs'] ) {
			$remaining[] = 'cpms_operational_logs';
		}
		if ( array() !== $scope['rate_limit_keys'] ) {
			$remaining[] = 'cpms_rate_limits';
		}
		foreach ( $scope['user_ids'] as $user_id ) {
			if ( null !== $this->env->user( (int) $user_id ) ) {
				$remaining[] = 'wp_user:' . $user_id;
			}
		}
		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
			$remaining[] = 'wp_post:' . $page_id;
		}
		$remaining = array_merge( $remaining, $this->scan_untracked_fixture_rows( $manifest, $baseline, $scope, true ) );
		$remaining = array_merge( $remaining, $this->verify_preserved_anchors( $manifest ) );
		return array_values( array_unique( $remaining ) );
	}

	/**
	 * Prove the three retained parent rows are inert and that the host site's
	 * implicit-Clinic scope invariant still holds.
	 *
	 * The audit-log foreign key makes physical deletion impossible without
	 * deleting audit rows, so teardown must be able to prove the leftovers are
	 * unusable: Organization and Clinic `status` must be `suspended` (which is
	 * what every production lifecycle gate reads), locations must be inactive,
	 * and `COUNT(*)` on `cpms_clinics` must still be multi-Clinic so no
	 * `=== 1` implicit-scope decision changed.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return list<string>
	 */
	private function verify_preserved_anchors( array $manifest ): array {
		global $wpdb;
		$problems   = array();
		$org_id     = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$clinic_id  = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$location   = (array) ( $manifest['ids']['location_ids'] ?? array() );
		if ( $org_id > 0 ) {
			$org_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $this->platform->table( 'cpms_organizations' ) . ' WHERE id = %d', $org_id ) );
			if ( 'suspended' !== (string) $org_status ) {
				$problems[] = 'ANCHOR_ORGANIZATION_NOT_INERT';
			}
		}
		if ( $clinic_id > 0 ) {
			$clinic_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $this->platform->table( 'cpms_clinics' ) . ' WHERE id = %d', $clinic_id ) );
			if ( 'suspended' !== (string) $clinic_status ) {
				$problems[] = 'ANCHOR_CLINIC_NOT_INERT';
			}
		}
		foreach ( $location as $location_id ) {
			$active = $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM ' . $this->platform->table( 'cpms_locations' ) . ' WHERE id = %d', (int) $location_id ) );
			if ( null !== $active && 0 !== (int) $active ) {
				$problems[] = 'ANCHOR_LOCATION_STILL_ACTIVE';
			}
		}
		if ( $this->platform->count_all( 'cpms_clinics' ) < 2 ) {
			// The preflight refuses a one-Clinic host, so reaching this means the
			// host changed underneath us (rows deleted elsewhere). Report it rather
			// than silently accepting a changed implicit-scope install.
			$problems[] = 'HOST_SINGLE_CLINIC_SEMANTICS';
		}

		return $problems;
	}

	/**
	 * Find rows carrying the fixture marker that are not in the proven scope.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @param array<string,mixed> $scope Ownership scope.
	 * @param bool $after_teardown Whether audit-only remnants are allowed.
	 * @return list<string>
	 */
	private function scan_untracked_fixture_rows( array $manifest, array $baseline, array $scope, bool $after_teardown ): array {
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$problems  = array();
		if ( '' === $fixture ) {
			return array( 'FIXTURE_ID_MISSING' );
		}
		$baseline_max = array();
		foreach ( (array) ( $baseline['cpms'] ?? array() ) as $short => $info ) {
			$baseline_max[ $short ] = (int) ( $info['max_id'] ?? 0 );
		}
		$owned = array();
		foreach ( (array) ( $scope['tables'] ?? array() ) as $short => $ids ) {
			$owned[ $short ] = array_fill_keys( array_map( 'intval', (array) $ids ), true );
		}
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( 'cpms_audit_logs' === $short && $after_teardown ) {
				continue;
			}
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$full = $this->platform->table( $short );
			$cols = $this->platform->columns( $full );
			if ( ! in_array( 'id', $cols, true ) ) {
				continue;
			}
			$max = (int) ( $baseline_max[ $short ] ?? 0 );
			$rows = $this->platform->fetch_all( 'SELECT * FROM `' . $full . '` WHERE id > %d ORDER BY id ASC', array( $max ) );
			foreach ( $rows as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( isset( $owned[ $short ][ $id ] ) ) {
					continue;
				}
				if ( 'cpms_jobs' === $short && in_array( $id, (array) ( $scope['jobs'] ?? array() ), true ) ) {
					continue;
				}
				if ( 'cpms_operational_logs' === $short && in_array( $id, (array) ( $scope['operational_logs'] ?? array() ), true ) ) {
					continue;
				}
				if ( 'cpms_audit_logs' === $short && in_array( $id, (array) ( $scope['audit_logs'] ?? array() ), true ) ) {
					continue;
				}
				foreach ( $row as $column => $value ) {
					if ( is_string( $value ) && false !== strpos( $value, $fixture ) ) {
						$problems[] = 'UNTRACKED_FIXTURE_ROW:' . $short . ':' . $id;
						break;
					}
				}
			}
		}
		return $problems;
	}

	/**
	 * @param mixed $value Context value.
	 * @param string $fixture Fixture ID.
	 * @param array<int,bool> $entities Entity IDs.
	 * @param string $mobile Synthetic mobile.
	 * @param string $key Current key.
	 * @return bool
	 */
	private function context_matches( $value, string $fixture, array $entities, string $mobile, string $key = '' ): bool {
		$ignored = array( 'request_id', 'ip', 'ip_hash', 'created_at', 'timestamp', 'time', 'date' );
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child_value ) {
				if ( $this->context_matches( $child_value, $fixture, $entities, $mobile, (string) $child_key ) ) {
					return true;
				}
			}
			return false;
		}
		if ( in_array( strtolower( $key ), $ignored, true ) ) {
			return false;
		}
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			$string = (string) $value;
			if ( '' !== $fixture && false !== strpos( $string, $fixture ) ) {
				return true;
			}
			if ( '' !== $mobile && $string === $mobile ) {
				return true;
			}
			if ( ctype_digit( $string ) && isset( $entities[ (int) $string ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Closed allowlist of fixture-derived rate-limit keys, proven absent from baseline.
	 *
	 * @param string $fixture Fixture ID.
	 * @param string $mobile Synthetic mobile.
	 * @param list<int> $user_ids Persona user IDs.
	 * @param array<string,mixed> $manifest Manifest.
	 * @param array<string,mixed> $baseline Baseline.
	 * @return list<string>
	 */
	private function allowed_rate_limit_keys( string $fixture, string $mobile, array $user_ids, array $manifest, array $baseline ): array {
		$keys = array();
		if ( 1 === preg_match( '/^09\d{9}$/D', $mobile ) ) {
			$keys[] = 'otp-day:' . $mobile;
			$keys[] = 'otp-hour:' . $mobile;
		}
		foreach ( $user_ids as $user_id ) {
			$keys[] = 'booking-' . $user_id;
			$keys[] = 'files:upload:' . $user_id;
			$keys[] = 'rt_notif_' . $user_id;
			$keys[] = 'rt_queue_' . $user_id;
			$keys[] = 'rt_queue_portal_' . $user_id;
			$keys[] = 'sms-test-' . $user_id;
			$keys[] = 'sms-send-' . $user_id;
			$keys[] = 'sms-tmpl-' . $user_id;
		}
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$login = $this->manifest->fixture_prefix() . $persona . '-' . $fixture;
			$hash  = substr( hash( 'sha256', strtolower( trim( $login ) ) ), 0, 32 );
			$keys[] = 'login-user:' . $hash;
		}
		if ( '' !== $fixture && $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			$rows = $this->platform->fetch_all( 'SELECT window_key FROM `' . $this->platform->table( 'cpms_rate_limits' ) . '` WHERE window_key LIKE %s', array( '%' . $fixture . '%' ) );
			foreach ( $rows as $row ) {
				$keys[] = (string) ( $row['window_key'] ?? '' );
			}
		}
		$keys = array_values( array_unique( array_filter( $keys ) ) );
		if ( array() !== $keys && $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows = $this->platform->fetch_all( 'SELECT window_key FROM `' . $this->platform->table( 'cpms_rate_limits' ) . '` WHERE window_key IN (' . $ph . ')', $keys );
			$live = array();
			foreach ( $rows as $row ) {
				$live[ (string) ( $row['window_key'] ?? '' ) ] = true;
			}
			$live_filter = static function ( string $key ) use ( $live ): bool {
				return isset( $live[ $key ] );
			};
			$keys = array_values( array_filter( $keys, $live_filter ) );
		}
		$baseline_keys = array_keys( (array) ( $baseline['cpms']['cpms_rate_limits']['keys'] ?? array() ) );
		$baseline_filter = static function ( string $key ) use ( $baseline_keys ): bool {
			if ( '' === $key || 0 === strpos( $key, 'otp-ip:' ) || 0 === strpos( $key, 'otp-verify-ip:' ) || 0 === strpos( $key, 'login-ip:' ) ) {
				return false;
			}
			foreach ( $baseline_keys as $baseline_key ) {
				if ( 0 === strpos( (string) $baseline_key, $key . '|' ) ) {
					return false;
				}
			}
			return true;
		};
		$keys = array_values( array_filter( $keys, $baseline_filter ) );
		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param list<string> $problems Problems.
	 * @return array{ok:bool,problems:list<string>,plan:list<array<string,mixed>>,scope:array<string,mixed>,baseline:array<string,mixed>,audit:array<string,mixed>}
	 */
	private function failed_preview( array $problems ): array {
		return array(
			'ok'       => false,
			'problems' => $problems,
			'plan'     => array(),
			'scope'    => array(),
			'baseline' => array(),
			'audit'    => array(),
		);
	}
}
