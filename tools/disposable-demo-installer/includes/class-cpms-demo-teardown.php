<?php
/**
 * Scoped, fail-closed teardown of the synthetic demo.
 *
 * Deletion never truncates a table and never touches Organization 1 / Clinic 1 /
 * Location 1 / the Administrator account. Every DELETE carries an explicit
 * ownership predicate (`clinic_id = <demo>`, `organization_id = <demo>`, or a
 * child foreign-key list collected from those two parents), and every table is
 * checked BEFORE any row is deleted so the whole run refuses if a single
 * non-demo row exists anywhere in the operational tables.
 *
 * Security guarantees (S-2, S-3, S-4, S-5):
 * - Runs under the session-bound concurrency guard (`Env::with_lock`).
 * - Never relies on `id > baseline_max_id` or `window_id >= 0`.
 * - Fails closed on any SQL delete error (`$wpdb->query === false`).
 * - Supports safe recovery from partial provisioning or interrupted teardown
 *   without requiring every intended fixture entity to exist.
 * - Preserves `cpdd_manifest` (with `status = 'teardown_failed'`) until
 *   cleanup is 100% proven complete.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Verifies ownership and removes the synthetic fixture in FK-safe order.
 */
class Teardown {

	/**
	 * Deletion order: durable-log/queue tables first (while parent FK rows
	 * still exist for correlation), then child tables before parent tables,
	 * clinic before organization.
	 *
	 * @var list<string>
	 */
	public const DELETE_ORDER = array(
		'cpms_jobs',
		'cpms_operational_logs',
		'cpms_rate_limits',
		'cpms_audit_logs',
		'cpms_payment_adjustments',
		'cpms_payments',
		'cpms_invoice_items',
		'cpms_invoices',
		'cpms_services',
		'cpms_follow_ups',
		'cpms_recommendations',
		'cpms_prescription_items',
		'cpms_prescriptions',
		'cpms_drug_reference',
		'cpms_visit_status_history',
		'cpms_clinical_note_versions',
		'cpms_clinical_notes',
		'cpms_ocr_jobs',
		'cpms_handwriting_page_versions',
		'cpms_handwriting_pages',
		'cpms_handwriting_documents',
		'cpms_medical_attachments',
		'cpms_visits',
		'cpms_appointments',
		'cpms_slot_holds',
		'cpms_schedule_slots',
		'cpms_schedule_exceptions',
		'cpms_schedule',
		'cpms_patient_user_links',
		'cpms_patient_merges',
		'cpms_patients',
		'cpms_patient_identity_links',
		'cpms_patient_identities',
		'cpms_otp_tokens',
		'cpms_sms_messages',
		'cpms_notifications',
		'cpms_idempotency_keys',
		'cpms_clinician_locations',
		'cpms_membership_locations',
		'cpms_membership_capabilities',
		'cpms_custom_role_capabilities',
		'cpms_custom_role_defs',
		'cpms_clinic_memberships',
		'cpms_clinicians',
		'cpms_organization_membership_capabilities',
		'cpms_organization_memberships',
		'cpms_settings',
		'cpms_locations',
		'cpms_clinics',
		'cpms_organizations',
	);

	/**
	 * @var Env
	 */
	private Env $env;

	/**
	 * @var Platform
	 */
	private Platform $platform;

	/**
	 * @var Manifest
	 */
	private Manifest $manifest;

	/**
	 * @param Env      $env Environment.
	 * @param Platform $platform CPMS adapter.
	 * @param Manifest $manifest Manifest store.
	 */
	public function __construct( Env $env, Platform $platform, Manifest $manifest ) {
		$this->env      = $env;
		$this->platform = $platform;
		$this->manifest = $manifest;
		$this->platform->set_env( $this->env );
	}

	/**
	 * Collect parent IDs belonging to the demo clinic/organization so child
	 * tables without a direct `clinic_id` column can still be deleted by an
	 * explicit `parent_id IN (...)` predicate.
	 *
	 * Merges live database rows with IDs recorded in `$manifest['ids']` and
	 * `$manifest['scope_snapshot']` so interrupted teardowns remain recoverable
	 * even if parent rows were already deleted in an earlier attempt (S-4).
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array<string,mixed>
	 */
	public function scope_ids( array $manifest ): array {
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$mobile    = (string) ( $manifest['patient_mobile'] ?? '' );
		$snapshot  = is_array( $manifest['scope_snapshot'] ?? null ) ? $manifest['scope_snapshot'] : array();
		$m_ids     = is_array( $manifest['ids'] ?? null ) ? $manifest['ids'] : array();

		$merge_ints = static function ( array ...$lists ): array {
			$out = array();
			foreach ( $lists as $list ) {
				foreach ( $list as $val ) {
					$int = (int) $val;
					if ( $int > 0 ) {
						$out[] = $int;
					}
				}
			}

			return array_values( array_unique( $out ) );
		};

		$merge_strs = static function ( array ...$lists ): array {
			$out = array();
			foreach ( $lists as $list ) {
				foreach ( $list as $val ) {
					$str = trim( (string) $val );
					if ( '' !== $str ) {
						$out[] = $str;
					}
				}
			}

			return array_values( array_unique( $out ) );
		};

		$hw_docs = $clinic_id > 1 ? $this->ids_by_clinic( 'cpms_handwriting_documents', $clinic_id ) : array();
		$hw_docs = $merge_ints( $hw_docs, (array) ( $snapshot['hw_doc_ids'] ?? array() ) );

		$user_ids = array();
		foreach ( (array) ( $m_ids['user_ids'] ?? array() ) as $uid ) {
			$uid = (int) $uid;
			if ( $uid > 0 && $uid !== (int) ( $manifest['admin_id'] ?? 0 ) ) {
				$user_ids[] = $uid;
			}
		}
		if ( 1 === preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
			foreach ( Credentials::PERSONA_ORDER as $persona ) {
				$acct = $this->env->user_by_login( 'cpdd-' . $persona . '-' . $fixture );
				if ( is_array( $acct ) && isset( $acct['id'] ) && (int) $acct['id'] > 0 && (int) $acct['id'] !== (int) ( $manifest['admin_id'] ?? 0 ) ) {
					$user_ids[] = (int) $acct['id'];
				}
			}
		}
		$user_ids = $merge_ints( $user_ids, (array) ( $snapshot['user_ids'] ?? array() ) );

		$login_user_hashes = array();
		$login_user_keys   = array();
		if ( 1 === preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
			foreach ( Credentials::PERSONA_ORDER as $persona ) {
				$login               = 'cpdd-' . $persona . '-' . $fixture;
				$hash                = $this->env->username_hash( $login );
				$login_user_hashes[] = $hash;
				$login_user_keys[]   = 'login-user:' . $hash;
			}
		}
		$login_user_hashes = $merge_strs( $login_user_hashes, (array) ( $snapshot['login_user_hashes'] ?? array() ) );
		$login_user_keys   = $merge_strs( $login_user_keys, (array) ( $snapshot['login_user_keys'] ?? array() ) );

		$otp_token_ids = array();
		if ( $clinic_id > 1 && $this->platform->table_exists( 'cpms_otp_tokens' ) && 1 === preg_match( '/^0900\d{7}$/', $mobile ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM ' . $this->platform->table( 'cpms_otp_tokens' ) . ' WHERE clinic_id = %d AND mobile = %s',
				array( $clinic_id, $mobile )
			);
			foreach ( $rows as $row ) {
				$otp_token_ids[] = (int) ( $row['id'] ?? 0 );
			}
		}
		$otp_token_ids = $merge_ints( $otp_token_ids, (array) ( $snapshot['otp_token_ids'] ?? array() ) );

		$synthetic_patients = array();
		if ( $clinic_id > 1 && $this->platform->table_exists( 'cpms_patients' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM ' . $this->platform->table( 'cpms_patients' ) . " WHERE clinic_id = %d AND mobile LIKE '0900%%'",
				array( $clinic_id )
			);
			foreach ( $rows as $row ) {
				$synthetic_patients[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$synthetic_sms = array();
		if ( $clinic_id > 1 && $this->platform->table_exists( 'cpms_sms_messages' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM ' . $this->platform->table( 'cpms_sms_messages' ) . " WHERE clinic_id = %d AND recipient LIKE '0900%%'",
				array( $clinic_id )
			);
			foreach ( $rows as $row ) {
				$synthetic_sms[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$scope = array(
			'fixture_id'             => $fixture,
			'clinic_id'              => $clinic_id,
			'organization_id'        => $org_id,
			'patient_mobile'         => $mobile,
			'user_ids'               => $user_ids,
			'login_user_hashes'      => $login_user_hashes,
			'login_user_keys'        => $login_user_keys,
			'otp_suffix_unambiguous' => $this->platform->otp_suffix_unambiguous( $clinic_id, $mobile ),
			'invoice_ids'                     => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_invoices', $clinic_id ) : array(),
				isset( $m_ids['invoice_id'] ) ? array( (int) $m_ids['invoice_id'] ) : array(),
				(array) ( $snapshot['invoice_ids'] ?? array() )
			),
			'prescription_ids'                => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_prescriptions', $clinic_id ) : array(),
				isset( $m_ids['prescription_id'] ) ? array( (int) $m_ids['prescription_id'] ) : array(),
				(array) ( $snapshot['prescription_ids'] ?? array() )
			),
			'visit_ids'                       => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_visits', $clinic_id ) : array(),
				isset( $m_ids['visit_id'] ) ? array( (int) $m_ids['visit_id'] ) : array(),
				(array) ( $snapshot['visit_ids'] ?? array() )
			),
			'note_ids'                        => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_clinical_notes', $clinic_id ) : array(),
				(array) ( $m_ids['note_ids'] ?? array() ),
				(array) ( $snapshot['note_ids'] ?? array() )
			),
			'hw_doc_ids'                      => $hw_docs,
			'hw_page_ids'                     => $merge_ints(
				$this->ids_by_parent( 'cpms_handwriting_pages', 'document_id', $hw_docs ),
				(array) ( $snapshot['hw_page_ids'] ?? array() )
			),
			'clinician_ids'                   => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_clinicians', $clinic_id ) : array(),
				isset( $m_ids['clinician_id'] ) ? array( (int) $m_ids['clinician_id'] ) : array(),
				(array) ( $snapshot['clinician_ids'] ?? array() )
			),
			'clinic_membership_ids'           => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_clinic_memberships', $clinic_id ) : array(),
				(array) ( $snapshot['clinic_membership_ids'] ?? array() )
			),
			'custom_role_def_ids'             => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_custom_role_defs', $clinic_id ) : array(),
				(array) ( $snapshot['custom_role_def_ids'] ?? array() )
			),
			'org_membership_ids'              => $merge_ints(
				$org_id > 1 ? $this->ids_by_org( 'cpms_organization_memberships', $org_id ) : array(),
				(array) ( $snapshot['org_membership_ids'] ?? array() )
			),
			'patient_ids'                     => $merge_ints(
				$synthetic_patients,
				isset( $m_ids['patient_id'] ) ? array( (int) $m_ids['patient_id'] ) : array(),
				(array) ( $snapshot['patient_ids'] ?? array() )
			),
			'identity_ids'                    => $merge_ints(
				$org_id > 1 ? $this->ids_by_org( 'cpms_patient_identities', $org_id ) : array(),
				isset( $m_ids['identity_id'] ) ? array( (int) $m_ids['identity_id'] ) : array(),
				(array) ( $snapshot['identity_ids'] ?? array() )
			),
			'appointment_ids'                 => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_appointments', $clinic_id ) : array(),
				isset( $m_ids['appointment_id'] ) ? array( (int) $m_ids['appointment_id'] ) : array(),
				(array) ( $snapshot['appointment_ids'] ?? array() )
			),
			'slot_ids'                        => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_schedule_slots', $clinic_id ) : array(),
				(array) ( $m_ids['slot_ids'] ?? array() ),
				(array) ( $snapshot['slot_ids'] ?? array() )
			),
			'hold_ids'                        => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_slot_holds', $clinic_id ) : array(),
				(array) ( $snapshot['hold_ids'] ?? array() )
			),
			'schedule_ids'                    => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_schedule', $clinic_id ) : array(),
				isset( $m_ids['schedule_id'] ) ? array( (int) $m_ids['schedule_id'] ) : array(),
				(array) ( $snapshot['schedule_ids'] ?? array() )
			),
			'schedule_exception_ids'          => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_schedule_exceptions', $clinic_id ) : array(),
				(array) ( $snapshot['schedule_exception_ids'] ?? array() )
			),
			'follow_up_ids'                   => $merge_ints(
				$clinic_id > 1 ? $this->ids_by_clinic( 'cpms_follow_ups', $clinic_id ) : array(),
				isset( $m_ids['follow_up_id'] ) ? array( (int) $m_ids['follow_up_id'] ) : array(),
				(array) ( $snapshot['follow_up_ids'] ?? array() )
			),
			'sms_message_ids'                 => $merge_ints(
				$synthetic_sms,
				(array) ( $snapshot['sms_message_ids'] ?? array() )
			),
			'otp_token_ids'                   => $otp_token_ids,
		);

		return $scope;
	}

	/**
	 * @param string $short Short table name.
	 * @param int    $clinic_id Clinic id.
	 * @return list<int>
	 */
	private function ids_by_clinic( string $short, int $clinic_id ): array {
		if ( $clinic_id <= 1 || ! $this->platform->table_exists( $short ) ) {
			return array();
		}
		$rows = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( $short ) . ' WHERE clinic_id = %d',
			array( $clinic_id )
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = (int) $row['id'];
		}

		return $out;
	}

	/**
	 * @param string $short Short table name.
	 * @param int    $org_id Organization id.
	 * @return list<int>
	 */
	private function ids_by_org( string $short, int $org_id ): array {
		if ( $org_id <= 1 || ! $this->platform->table_exists( $short ) ) {
			return array();
		}
		$rows = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( $short ) . ' WHERE organization_id = %d',
			array( $org_id )
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = (int) $row['id'];
		}

		return $out;
	}

	/**
	 * @param string    $short Short table name.
	 * @param string    $column Foreign-key column.
	 * @param list<int> $parents Parent IDs.
	 * @return list<int>
	 */
	private function ids_by_parent( string $short, string $column, array $parents ): array {
		if ( array() === $parents || ! $this->platform->table_exists( $short ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $parents ), '%d' ) );
		$rows         = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( $short ) . ' WHERE ' . $column . ' IN (' . $placeholders . ')',
			$parents
		);
		$out          = array();
		foreach ( $rows as $row ) {
			$out[] = (int) $row['id'];
		}

		return $out;
	}

	/**
	 * Build the ownership predicate for a single table (S-3).
	 *
	 * @param string              $short Short table name.
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $scope Collected parent IDs.
	 * @return array{0:string,1:list<mixed>}|null `[ $where_sql, $params ]` or null when nothing in this table belongs to the fixture.
	 */
	public function predicate( string $short, array $manifest, array $scope ): ?array {
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$mobile    = (string) ( $manifest['patient_mobile'] ?? '' );

		switch ( $short ) {
			case 'cpms_clinics':
				return $clinic_id > 1 ? array( 'id = %d', array( $clinic_id ) ) : null;
			case 'cpms_organizations':
				return $org_id > 1 ? array( 'id = %d', array( $org_id ) ) : null;
			case 'cpms_payment_adjustments':
			case 'cpms_invoice_items':
				return $this->in_clause( 'invoice_id', (array) ( $scope['invoice_ids'] ?? array() ) );
			case 'cpms_prescription_items':
				return $this->in_clause( 'prescription_id', (array) ( $scope['prescription_ids'] ?? array() ) );
			case 'cpms_visit_status_history':
				return $this->in_clause( 'visit_id', (array) ( $scope['visit_ids'] ?? array() ) );
			case 'cpms_clinical_note_versions':
				return $this->in_clause( 'note_id', (array) ( $scope['note_ids'] ?? array() ) );
			case 'cpms_handwriting_pages':
				return $this->in_clause( 'document_id', (array) ( $scope['hw_doc_ids'] ?? array() ) );
			case 'cpms_handwriting_page_versions':
				return $this->in_clause( 'page_id', (array) ( $scope['hw_page_ids'] ?? array() ) );
			case 'cpms_clinician_locations':
				return $this->in_clause( 'clinician_id', (array) ( $scope['clinician_ids'] ?? array() ) );
			case 'cpms_membership_locations':
			case 'cpms_membership_capabilities':
				return $this->in_clause( 'membership_id', (array) ( $scope['clinic_membership_ids'] ?? array() ) );
			case 'cpms_custom_role_capabilities':
				return $this->in_clause( 'role_def_id', (array) ( $scope['custom_role_def_ids'] ?? array() ) );
			case 'cpms_organization_membership_capabilities':
				return $this->in_clause( 'membership_id', (array) ( $scope['org_membership_ids'] ?? array() ) );
			case 'cpms_jobs':
				$pred = $this->platform->owned_jobs_predicate( $scope );
				return '1 = 0' === $pred[0] ? null : $pred;
			case 'cpms_operational_logs':
				$pred = $this->platform->owned_oplogs_predicate( $scope );
				return '1 = 0' === $pred[0] ? null : $pred;
			case 'cpms_audit_logs':
				$pred = $this->platform->owned_audit_logs_predicate( $scope );
				return '1 = 0' === $pred[0] ? null : $pred;
			case 'cpms_rate_limits':
				$pred = $this->platform->owned_rate_limits_predicate( $scope );
				return '1 = 0' === $pred[0] ? null : $pred;
			case 'cpms_otp_tokens':
				if ( $clinic_id > 1 && 1 === preg_match( '/^0900\d{7}$/', $mobile ) ) {
					return array( 'clinic_id = %d AND mobile = %s', array( $clinic_id, $mobile ) );
				}
				return null;
			case 'cpms_sms_messages':
				return $clinic_id > 1 ? array( "clinic_id = %d AND recipient LIKE '0900%%'", array( $clinic_id ) ) : null;
			case 'cpms_patients':
				return $clinic_id > 1 ? array( "clinic_id = %d AND mobile LIKE '0900%%'", array( $clinic_id ) ) : null;
			default:
				$full = $this->platform->table( $short );
				$cols = $this->platform->columns( $full );
				if ( in_array( 'clinic_id', $cols, true ) ) {
					return $clinic_id > 1 ? array( 'clinic_id = %d', array( $clinic_id ) ) : null;
				}
				if ( in_array( 'organization_id', $cols, true ) ) {
					return $org_id > 1 ? array( 'organization_id = %d', array( $org_id ) ) : null;
				}

				return null;
		}
	}

	/**
	 * @param string    $column Column name.
	 * @param list<int> $ids IDs.
	 * @return array{0:string,1:list<int>}|null
	 */
	private function in_clause( string $column, array $ids ): ?array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $v ): bool => $v > 0 ) );
		if ( array() === $ids ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return array( $column . ' IN (' . $placeholders . ')', $ids );
	}

	/**
	 * Verify that every surviving demo object still matches its manifest
	 * fingerprint AND that no non-demo operational rows have appeared since
	 * provisioning (S-2, S-3, S-4).
	 *
	 * Does not require every intended fixture entity to exist when recovering
	 * partial work or interrupted teardown.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{ok:bool,problems:list<string>,plan:list<array{table:string,rows:int,total:int,foreign_rows:int}>,users:list<int>,page_id:int}
	 */
	public function verify( array $manifest ): array {
		$problems  = array();
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$admin_id  = (int) ( $manifest['admin_id'] ?? 0 );

		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
			$problems[] = 'شناسهٔ دمو در فایل مالکیت نامعتبر است.';
		}
		if ( 1 === $clinic_id || 1 === $org_id ) {
			$problems[] = 'شناسهٔ سازمان/درمانگاه پایه (1) هرگز در محدودهٔ حذف قرار نمی‌گیرد.';
		}

		// Verify that default bootstrap Organization 1 / Clinic 1 / Location 1 still exist and are untouched.
		$default_org = $this->platform->fetch_row(
			'SELECT id, slug, status FROM ' . $this->platform->table( 'cpms_organizations' ) . ' WHERE id = %d',
			array( 1 )
		);
		$default_clinic = $this->platform->fetch_row(
			'SELECT id, organization_id, slug, status FROM ' . $this->platform->table( 'cpms_clinics' ) . ' WHERE id = %d',
			array( 1 )
		);
		if ( null === $default_org || 'default' !== (string) ( $default_org['slug'] ?? '' ) || null === $default_clinic || 'default' !== (string) ( $default_clinic['slug'] ?? '' ) ) {
			$problems[] = 'سازمان یا درمانگاه پیش‌فرض (شناسه 1) دست‌کاری شده است.';
		}

		if ( $org_id > 1 ) {
			$organization = $this->platform->fetch_row(
				'SELECT id, slug FROM ' . $this->platform->table( 'cpms_organizations' ) . ' WHERE id = %d',
				array( $org_id )
			);
			if ( null !== $organization && (string) ( $organization['slug'] ?? '' ) !== (string) ( $manifest['slugs']['organization'] ?? '' ) ) {
				$problems[] = 'سازمان دمو با Slug ثبت‌شده هم‌خوان نیست.';
			}
		}

		if ( $clinic_id > 1 ) {
			$clinic = $this->platform->fetch_row(
				'SELECT id, organization_id, slug FROM ' . $this->platform->table( 'cpms_clinics' ) . ' WHERE id = %d',
				array( $clinic_id )
			);
			if ( null !== $clinic ) {
				if ( (int) ( $clinic['organization_id'] ?? 0 ) !== $org_id || (string) ( $clinic['slug'] ?? '' ) !== (string) ( $manifest['slugs']['clinic'] ?? '' ) ) {
					$problems[] = 'درمانگاه دمو با سازمان یا Slug ثبت‌شده هم‌خوان نیست.';
				}
			}
		}

		$users              = (array) ( $manifest['ids']['user_ids'] ?? array() );
		$surviving_user_ids = array();
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$uid = (int) ( $users[ $persona ] ?? 0 );
			if ( $uid <= 0 && 1 === preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
				$by_login = $this->env->user_by_login( 'cpdd-' . $persona . '-' . $fixture );
				if ( null !== $by_login ) {
					$uid = (int) $by_login['id'];
				}
			}
			if ( $uid <= 0 ) {
				continue;
			}
			if ( $uid === $admin_id ) {
				$problems[] = 'حساب مدیر کل هرگز در محدودهٔ حذف قرار نمی‌گیرد.';
				continue;
			}
			$account = $this->env->user( $uid );
			if ( null === $account ) {
				// Surviving record check (S-4): previously deleted fixture user does not block recovery.
				continue;
			}
			if ( 'cpdd-' . $persona . '-' . $fixture !== (string) $account['login'] ) {
				$problems[] = 'نام کاربری «' . $persona . '» با شناسهٔ دمو هم‌خوان نیست.';
				continue;
			}
			if ( in_array( 'administrator', $account['roles'], true ) || $this->env->user_can( $uid, 'manage_options' ) ) {
				$problems[] = 'حساب «' . $persona . '» دارای اختیار مدیریتی غیرمجاز است؛ حذف متوقف شد.';
				continue;
			}
			$surviving_user_ids[ $persona ] = $uid;
		}

		// Ensure no foreign WordPress user was created outside the fixture and the acting Admin.
		$allowed_wp_users = array_merge( array( $admin_id ), array_values( $surviving_user_ids ) );
		foreach ( $this->env->all_users() as $wp_user ) {
			$wp_uid = (int) ( $wp_user['id'] ?? 0 );
			if ( ! in_array( $wp_uid, $allowed_wp_users, true ) ) {
				$problems[] = 'حساب کاربری غیرآزمایشی «' . (string) ( $wp_user['login'] ?? $wp_uid ) . '» در سایت وجود دارد.';
			}
		}

		$page_id        = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		$surviving_page = 0;
		if ( $page_id > 0 ) {
			$page = $this->env->post( $page_id );
			if ( null !== $page ) {
				if ( 'page' !== $page['type'] || (string) $page['slug'] !== (string) ( $manifest['slugs']['page'] ?? '' ) || ! str_contains( (string) ( $page['content'] ?? '' ), '[cpms_public_booking' ) ) {
					$problems[] = 'برگهٔ رزرو آزمایشی تغییر یافته است؛ حذف خودکار متوقف شد.';
				} else {
					$surviving_page = $page_id;
				}
			}
		}

		$unknown_tables = array();
		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, Preflight::KNOWN_TABLES, true ) && $this->platform->count_all( $short ) > 0 ) {
				$unknown_tables[] = $short;
				$problems[]       = 'جدول ناشناختهٔ «' . $short . '» شامل داده است؛ حذف متوقف شد.';
			}
		}
		if ( $this->platform->table_exists( 'cpms_license_state' ) && $this->platform->count_all( 'cpms_license_state' ) > 0 ) {
			$problems[] = 'سند مجوز تجاری در cpms_license_state ثبت شده است؛ حذف متوقف شد.';
		}
		if ( $this->platform->table_exists( 'cpms_license_install' ) && $this->platform->count_all( 'cpms_license_install' ) > 1 ) {
			$problems[] = 'cpms_license_install شامل بیش از یک رکورد نصب است؛ حذف متوقف شد.';
		}

		$scope = $this->scope_ids( $manifest );
		$plan  = array();
		foreach ( self::DELETE_ORDER as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$full      = $this->platform->table( $short );
			$predicate = $this->predicate( $short, $manifest, $scope );
			$rows      = null === $predicate ? 0 : $this->platform->count_where( $full, $predicate[0], $predicate[1] );
			$total     = $this->platform->count_all( $short );
			$baseline  = $this->expected_baseline( $short );
			$foreign   = max( 0, $total - $rows - $baseline );
			if ( $foreign > 0 ) {
				$problems[] = $short . ' شامل ' . $foreign . ' ردیف خارج از محدودهٔ دمو است؛ برای حفاظت از داده متوقف شد.';
			}
			$plan[] = array(
				'table'        => $short,
				'predicate'    => null === $predicate ? '—' : $predicate[0],
				'rows'         => $rows,
				'total'        => $total,
				'foreign_rows' => $foreign,
			);
		}

		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'unknown'  => $unknown_tables,
			'plan'     => $plan,
			'users'    => array_values( $surviving_user_ids ),
			'page_id'  => $surviving_page,
		);
	}

	/**
	 * Read-only ownership and foreign-row diagnostic report for the admin UI.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $actor_id Acting Administrator.
	 * @return array{ok:bool,problems:list<string>,plan:list<array{table:string,predicate:string,rows:int,total:int,foreign_rows:int}>,users:list<int>,page_id:int}
	 */
	public function diagnose( array $manifest, int $actor_id = 0 ): array {
		unset( $actor_id );

		return $this->verify( $manifest );
	}

	/**
	 * Rows that belong to the untouched CPMS bootstrap in tables shared with the
	 * demo (Organization 1, Clinic 1, Location 1, system heartbeat, recurring
	 * jobs, migration/activation operational logs).
	 *
	 * @param string $short Short table name.
	 * @return int
	 */
	private function expected_baseline( string $short ): int {
		switch ( $short ) {
			case 'cpms_organizations':
				return $this->platform->count_where(
					$this->platform->table( 'cpms_organizations' ),
					'id = %d AND slug = %s',
					array( 1, 'default' )
				);
			case 'cpms_clinics':
				return $this->platform->count_where(
					$this->platform->table( 'cpms_clinics' ),
					'id = %d AND organization_id = %d AND slug = %s',
					array( 1, 1, 'default' )
				);
			case 'cpms_locations':
				return $this->platform->count_where(
					$this->platform->table( 'cpms_locations' ),
					'id = %d AND clinic_id = %d AND slug = %s',
					array( 1, 1, 'main' )
				);
			case 'cpms_settings':
				return $this->platform->count_where(
					$this->platform->table( 'cpms_settings' ),
					'clinic_id = %d AND `key` = %s',
					array( 1, 'jobs.last_tick_at' )
				);
			case 'cpms_jobs':
				return $this->platform->baseline_jobs_count();
			case 'cpms_operational_logs':
				return $this->platform->baseline_oplogs_count();
			case 'cpms_rate_limits':
				return $this->platform->shared_ip_rate_limits_count();
			default:
				return 0;
		}
	}

	/**
	 * Execute the verified teardown under the concurrency lock (S-2, S-4, S-5).
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $actor_id Acting Administrator.
	 * @return array{ok:bool,deleted:array<string,int>,remaining:list<string>,tombstone:array<string,mixed>}
	 * @throws Exception When verification fails or any post-delete residual remains.
	 */
	public function execute( array $manifest, int $actor_id ): array {
		return $this->env->with_lock(
			'teardown',
			function () use ( $manifest, $actor_id ): array {
				return $this->do_execute( $manifest, $actor_id );
			}
		);
	}

	/**
	 * Internal teardown implementation executed under the concurrency lock.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $actor_id Acting Administrator.
	 * @return array{ok:bool,deleted:array<string,int>,remaining:list<string>,tombstone:array<string,mixed>}
	 * @throws Exception When verification fails or any delete/post-check fails.
	 */
	private function do_execute( array $manifest, int $actor_id ): array {
		$report = $this->verify( $manifest );
		if ( ! $report['ok'] ) {
			throw new Exception( 'TEARDOWN_BLOCKED', 'حذف ایمن متوقف شد: ' . implode( ' | ', array_slice( $report['problems'], 0, 3 ) ) );
		}

		$scope                      = $this->scope_ids( $manifest );
		$manifest['status']         = 'tearing_down';
		$manifest['teardown_started'] = true;
		$manifest['scope_snapshot'] = $scope;
		$manifest                   = $this->manifest->save( $manifest );

		$deleted = array();
		$stage   = 'tables';

		try {
			foreach ( $report['plan'] as $step ) {
				$short     = $step['table'];
				$stage     = 'table:' . $short;
				$predicate = $this->predicate( $short, $manifest, $scope );
				if ( null === $predicate || 0 === $step['rows'] ) {
					$deleted[ $short ] = 0;
					continue;
				}
				$full              = $this->platform->table( $short );
				$deleted[ $short ] = $this->platform->delete_where( $full, $predicate[0], $predicate[1] );
			}

			$stage   = 'booking_page';
			$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
			if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
				$this->env->maybe_inject_fault( 'teardown:delete_post', array( 'page_id' => $page_id ) );
				if ( ! $this->env->delete_post( $page_id ) ) {
					throw new Exception( 'POST_DELETE_FAILED', 'حذف برگهٔ رزرو آزمایشی ناموفق بود.' );
				}
				$deleted['booking_page'] = 1;
			} else {
				$deleted['booking_page'] = 0;
			}

			$deleted['users'] = 0;
			$users            = (array) ( $manifest['ids']['user_ids'] ?? array() );
			$fixture          = (string) ( $manifest['fixture_id'] ?? '' );
			foreach ( Credentials::PERSONA_ORDER as $persona ) {
				$stage = 'user:' . $persona;
				$uid   = (int) ( $users[ $persona ] ?? 0 );
				if ( $uid <= 0 && 1 === preg_match( '/^[a-f0-9]{16}$/', $fixture ) ) {
					$by_login = $this->env->user_by_login( 'cpdd-' . $persona . '-' . $fixture );
					if ( null !== $by_login ) {
						$uid = (int) $by_login['id'];
					}
				}
				if ( $uid <= 0 || null === $this->env->user( $uid ) ) {
					continue;
				}
				$this->env->maybe_inject_fault( 'teardown:delete_user:' . $persona, array( 'persona' => $persona, 'user_id' => $uid ) );
				$this->env->maybe_inject_fault( 'teardown:delete_user', array( 'persona' => $persona, 'user_id' => $uid ) );
				$this->env->destroy_sessions( $uid );
				if ( ! $this->env->delete_user( $uid ) ) {
					throw new Exception( 'USER_DELETE_FAILED', 'حذف حساب آزمایشی «' . $persona . '» ناموفق بود.' );
				}
				++$deleted['users'];
			}

			$stage     = 'post_verify';
			$remaining = array();
			$post_scope = $this->scope_ids( $manifest );
			foreach ( self::DELETE_ORDER as $short ) {
				if ( ! $this->platform->table_exists( $short ) ) {
					continue;
				}
				$full      = $this->platform->table( $short );
				$predicate = $this->predicate( $short, $manifest, $post_scope );
				$owned_rem = null === $predicate ? 0 : $this->platform->count_where( $full, $predicate[0], $predicate[1] );
				$total_rem = $this->platform->count_all( $short );
				$base_rem  = $this->expected_baseline( $short );
				if ( $owned_rem > 0 || $total_rem > $base_rem ) {
					$remaining[] = $short;
				}
			}
			foreach ( Credentials::PERSONA_ORDER as $persona ) {
				$uid = (int) ( $users[ $persona ] ?? 0 );
				if ( ( $uid > 0 && null !== $this->env->user( $uid ) )
					|| ( '' !== $fixture && null !== $this->env->user_by_login( 'cpdd-' . $persona . '-' . $fixture ) )
				) {
					$remaining[] = 'user:' . $persona;
				}
			}
			if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
				$remaining[] = 'post:' . $page_id;
			}

			$this->env->maybe_inject_fault( 'teardown:before_complete', array( 'remaining' => $remaining ) );
			if ( array() !== $remaining ) {
				throw new Exception( 'TEARDOWN_INCOMPLETE', 'حذف کامل اثبات نشد؛ اقلام باقی‌مانده: ' . implode( ', ', $remaining ) );
			}
		} catch ( \Throwable $error ) {
			$manifest['status']          = 'teardown_failed';
			$manifest['failed_stage']    = $stage;
			$manifest['failure_code']    = $error instanceof Exception ? $error->demo_code() : 'TEARDOWN_FAILED';
			$manifest['failure_message'] = $error->getMessage();
			$manifest['partial_deleted'] = $deleted;
			try {
				$this->manifest->save( $manifest );
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
			if ( $error instanceof Exception ) {
				throw $error;
			}
			throw new Exception( 'TEARDOWN_FAILED', 'حذف دمو در مرحلهٔ «' . $stage . '» ناموفق بود: ' . $error->getMessage() );
		}

		// Cleanup is 100% proven complete: now and only now record the tombstone
		// and remove cpdd_manifest (S-2).
		$removed_at = $this->env->now_sql();
		$tombstone  = array(
			'fixture_id'                      => (string) $manifest['fixture_id'],
			'removed_at'                      => $removed_at,
			'at'                              => $removed_at,
			'actor_id'                        => $actor_id,
			'deleted'                         => $deleted,
			'preserved_shared_ip_rate_limits' => $this->platform->shared_ip_rate_limits_count(),
			'remaining'                       => array(),
			'clean'                           => true,
			'ok'                              => true,
		);
		$this->env->option_delete( 'cpdd_uninstall_blocked' );
		$this->env->option_set( 'cpdd_had_clean_teardown', '1' );
		$this->manifest->write_tombstone( $tombstone );
		$this->manifest->delete();

		return array(
			'ok'        => true,
			'deleted'   => $deleted,
			'remaining' => array(),
			'tombstone' => $tombstone,
		);
	}
}
