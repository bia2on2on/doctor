<?php
/**
 * Fail-closed teardown of exactly one synthetic demo.
 *
 * Ownership is proven from live rows, never from the manifest alone: every
 * object id must still carry the fixture markers, and every row touched must be
 * attributable to the fixture Clinic/Organization/patients/users or to its
 * parent rows via foreign keys. If ownership cannot be proven for any table
 * with data, teardown refuses and reports the artifact instead of guessing.
 * The default bootstrap Organization/Clinic/Location, installation-level
 * migration/license tables and the Owner's Administrator account are never in
 * scope.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Ownership verification + selective deletion.
 */
class Teardown {

	/**
	 * Installation-level baseline tables created on CPMS activation. Verified
	 * for integrity and preserved untouched across teardown.
	 */
	public const BASELINE_TABLES = array(
		'cpms_schema_migrations',
		'cpms_license_install',
		'cpms_license_state',
	);

	/**
	 * Topologically sorted child-first deletion order across all 50 non-baseline
	 * CPMS tables.
	 */
	public const DELETE_ORDER = array(
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
		'cpms_clinical_note_versions',
		'cpms_clinical_notes',
		'cpms_ocr_jobs',
		'cpms_handwriting_page_versions',
		'cpms_handwriting_pages',
		'cpms_handwriting_documents',
		'cpms_medical_attachments',
		'cpms_visit_status_history',
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
		'cpms_rate_limits',
		'cpms_idempotency_keys',
		'cpms_jobs',
		'cpms_operational_logs',
		'cpms_audit_logs',
		'cpms_custom_role_capabilities',
		'cpms_custom_role_defs',
		'cpms_clinician_locations',
		'cpms_membership_locations',
		'cpms_membership_capabilities',
		'cpms_clinic_memberships',
		'cpms_organization_membership_capabilities',
		'cpms_organization_memberships',
		'cpms_clinicians',
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
	}

	/**
	 * Collect live parent IDs belonging to the fixture clinic/organization so
	 * child tables without direct clinic_id/organization_id columns can be
	 * verified and deleted by foreign-key membership.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array<string,list<int>>
	 */
	private function scope_ids( array $manifest ): array {
		$clinic_id  = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id     = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$hw_doc_ids = $this->col_ints( 'cpms_handwriting_documents', 'clinic_id', $clinic_id );
		$hw_pages   = array();
		foreach ( $hw_doc_ids as $doc_id ) {
			foreach ( $this->col_ints( 'cpms_handwriting_pages', 'document_id', $doc_id ) as $page_id ) {
				$hw_pages[] = $page_id;
			}
		}

		return array(
			'patient_ids'           => $this->col_ints( 'cpms_patients', 'clinic_id', $clinic_id ),
			'invoice_ids'           => $this->col_ints( 'cpms_invoices', 'clinic_id', $clinic_id ),
			'prescription_ids'      => $this->col_ints( 'cpms_prescriptions', 'clinic_id', $clinic_id ),
			'visit_ids'             => $this->col_ints( 'cpms_visits', 'clinic_id', $clinic_id ),
			'note_ids'              => $this->col_ints( 'cpms_clinical_notes', 'clinic_id', $clinic_id ),
			'hw_doc_ids'            => $hw_doc_ids,
			'hw_page_ids'           => $hw_pages,
			'clinician_ids'         => $this->col_ints( 'cpms_clinicians', 'clinic_id', $clinic_id ),
			'clinic_membership_ids' => $this->col_ints( 'cpms_clinic_memberships', 'clinic_id', $clinic_id ),
			'org_membership_ids'    => $this->col_ints( 'cpms_organization_memberships', 'organization_id', $org_id ),
			'custom_role_def_ids'   => $this->col_ints( 'cpms_custom_role_defs', 'clinic_id', $clinic_id ),
		);
	}

	/**
	 * @param string $short     Short table name.
	 * @param string $where_col Column name.
	 * @param int    $where_val Column integer value.
	 * @return list<int>
	 */
	private function col_ints( string $short, string $where_col, int $where_val ): array {
		if ( $where_val <= 0 || ! $this->platform->table_exists( $short ) ) {
			return array();
		}
		$rows = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( $short ) . ' WHERE ' . $where_col . ' = %d',
			array( $where_val )
		);
		$out  = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) ) {
				$out[] = (int) $row['id'];
			}
		}

		return $out;
	}

	/**
	 * Build an `IN (...)` predicate for a foreign-key column, or an impossible
	 * `= -1` predicate when the parent table has zero rows in the fixture scope.
	 *
	 * @param string    $col   Column name.
	 * @param list<int> $ids   Parent row IDs.
	 * @param string    $label Diagnostic label.
	 * @return array{where:string,params:list<mixed>,label:string}
	 */
	private function in_predicate( string $col, array $ids, string $label ): array {
		if ( array() === $ids ) {
			return array(
				'where'  => $col . ' = %d',
				'params' => array( -1 ),
				'label'  => $label,
			);
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return array(
			'where'  => $col . ' IN (' . $in . ')',
			'params' => $ids,
			'label'  => $label,
		);
	}

	/**
	 * Read-only ownership verification + deletion plan.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $admin_id Acting Administrator.
	 * @return array{ok:bool,problems:list<string>,plan:list<array{table:string,predicate:string,rows:int}>,unknown:list<string>}
	 */
	public function verify( array $manifest, int $admin_id ): array {
		$problems = array();
		$unknown  = array();
		$plan     = array();

		$fixture   = (string) $manifest['fixture_id'];
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org_id    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		if ( $clinic_id <= 0 || $org_id <= 0 ) {
			return array(
				'ok'       => false,
				'problems' => array( 'شناسهٔ درمانگاه/سازمان در فایل مالکیت موجود نیست.' ),
				'plan'     => array(),
				'unknown'  => array(),
			);
		}

		$clinic = $this->platform->fetch_row(
			'SELECT id, organization_id, slug FROM ' . $this->platform->table( 'cpms_clinics' ) . ' WHERE id = %d',
			array( $clinic_id )
		);
		if ( null === $clinic || (int) $clinic['organization_id'] !== $org_id || $clinic['slug'] !== $manifest['slugs']['clinic'] ) {
			$problems[] = 'درمانگاه ساختگی با شناسهٔ ثبت‌شده هم‌خوان نیست.';
		}
		$organization = $this->platform->fetch_row(
			'SELECT id, slug FROM ' . $this->platform->table( 'cpms_organizations' ) . ' WHERE id = %d',
			array( $org_id )
		);
		if ( null === $organization || $organization['slug'] !== $manifest['slugs']['organization'] ) {
			$problems[] = 'سازمان ساختگی با شناسهٔ ثبت‌شده هم‌خوان نیست.';
		}

		$all_clinics = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( 'cpms_clinics' ) . ' ORDER BY id'
		);
		foreach ( $all_clinics as $c_row ) {
			$cid = (int) ( $c_row['id'] ?? 0 );
			if ( 1 !== $cid && $clinic_id !== $cid ) {
				$problems[] = 'درمانگاه دیگری (id=' . $cid . ') خارج از دمو در سایت وجود دارد.';
			}
		}

		$all_orgs = $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( 'cpms_organizations' ) . ' ORDER BY id'
		);
		foreach ( $all_orgs as $o_row ) {
			$oid = (int) ( $o_row['id'] ?? 0 );
			if ( 1 !== $oid && $org_id !== $oid ) {
				$problems[] = 'سازمان دیگری (id=' . $oid . ') خارج از دمو در سایت وجود دارد.';
			}
		}

		$users = (array) ( $manifest['ids']['user_ids'] ?? array() );
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $users[ $persona ] ?? 0 );
			$account = $user_id > 0 ? $this->env->user( $user_id ) : null;
			if ( null === $account ) {
				$problems[] = 'حساب «' . $persona . '» یافت نشد.';
				continue;
			}
			if ( $user_id === $admin_id ) {
				$problems[] = 'حساب مدیر جاری در فهرست حذف قرار گرفته است؛ عملیات مسدود شد.';
			}
			if ( in_array( 'administrator', $account['roles'], true ) || $this->env->user_can( $user_id, 'manage_options' ) ) {
				$problems[] = 'حساب «' . $persona . '» اختیار مدیریتی دارد و هرگز حذف نمی‌شود.';
			}
			if ( ! str_starts_with( $account['login'], 'cpdd-' . $persona . '-' . $fixture ) ) {
				$problems[] = 'نام کاربری «' . $persona . '» با شناسهٔ دمو هم‌خوان نیست.';
			}
		}

		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		$page    = $page_id > 0 ? $this->env->post( $page_id ) : null;
		if ( null === $page || 'page' !== $page['type'] || $page['slug'] !== $manifest['slugs']['page'] ) {
			$problems[] = 'برگهٔ عمومی رزرو با شناسهٔ ثبت‌شده هم‌خوان نیست.';
		} elseif ( ! str_contains( $page['content'], $this->platform->booking_shortcode( $clinic_id ) ) ) {
			$problems[] = 'برگهٔ عمومی رزرو، Shortcode همان درمانگاه ساختگی را ندارد.';
		}

		$scope = $this->scope_ids( $manifest );

		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, Preflight::KNOWN_TABLES, true ) ) {
				$count = $this->platform->count_all( $short );
				if ( $count > 0 ) {
					$unknown[] = $short . ' (' . $count . ' ردیف)';
				}
				continue;
			}
			if ( in_array( $short, self::BASELINE_TABLES, true ) ) {
				if ( 'cpms_schema_migrations' === $short && CPMS_DEMO_SCHEMA_VERSION !== $this->platform->schema_version() ) {
					$problems[] = 'نسخهٔ Schema جدول cpms_schema_migrations تغییر کرده است.';
				} elseif ( 'cpms_license_install' === $short && $this->platform->count_all( $short ) > 1 ) {
					$problems[] = 'جدول cpms_license_install بیش از یک ردیف نصب دارد.';
				} elseif ( 'cpms_license_state' === $short && $this->platform->count_all( $short ) > 0 ) {
					$problems[] = 'سند مجوز فعال در cpms_license_state وجود دارد.';
				}
				continue;
			}

			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $scope );
			if ( null === $predicate ) {
				$count = $this->platform->count_all( $short );
				if ( $count > 0 ) {
					$unknown[] = $short . ' (بدون ستون مالکیت؛ ' . $count . ' ردیف)';
				}
				continue;
			}
			$rows = $this->platform->count_where( $this->platform->table( $short ), $predicate['where'], $predicate['params'] );
			if ( in_array( $short, Preflight::OPERATIONAL_TABLES, true ) ) {
				$total = $this->platform->count_all( $short );
				if ( $total > $rows ) {
					$unknown[] = $short . ' (' . ( $total - $rows ) . ' ردیف خارج از مالکیت دمو)';
					continue;
				}
			}
			if ( $rows > 0 ) {
				$plan[] = array(
					'table'     => $short,
					'predicate' => $predicate['label'],
					'rows'      => $rows,
				);
			}
		}

		if ( array() !== $unknown ) {
			$problems[] = 'مالکیت برخی جدول‌های دارای داده قابل اثبات نیست: ' . implode( ', ', $unknown );
		}

		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'plan'     => $plan,
			'unknown'  => $unknown,
		);
	}

	/**
	 * @param string                  $short    Table short name.
	 * @param list<string>            $columns  Columns.
	 * @param array<string,mixed>     $manifest Manifest.
	 * @param array<string,list<int>> $scope    Parent IDs in fixture scope.
	 * @return array{where:string,params:list<mixed>,label:string}|null
	 */
	private function predicate( string $short, array $columns, array $manifest, array $scope ): ?array {
		$clinic_id = (int) $manifest['ids']['clinic_id'];
		$org_id    = (int) $manifest['ids']['organization_id'];
		$users     = array_values( array_map( 'intval', (array) $manifest['ids']['user_ids'] ) );
		$clinician = (int) ( $manifest['ids']['clinician_id'] ?? 0 );

		// Explicit child tables linked via foreign key to a parent table.
		switch ( $short ) {
			case 'cpms_invoice_items':
			case 'cpms_payment_adjustments':
				return $this->in_predicate( 'invoice_id', $scope['invoice_ids'] ?? array(), 'invoice_id∈fixture' );

			case 'cpms_prescription_items':
				return $this->in_predicate( 'prescription_id', $scope['prescription_ids'] ?? array(), 'prescription_id∈fixture' );

			case 'cpms_visit_status_history':
				return $this->in_predicate( 'visit_id', $scope['visit_ids'] ?? array(), 'visit_id∈fixture' );

			case 'cpms_clinical_note_versions':
				return $this->in_predicate( 'note_id', $scope['note_ids'] ?? array(), 'note_id∈fixture' );

			case 'cpms_handwriting_pages':
				return $this->in_predicate( 'document_id', $scope['hw_doc_ids'] ?? array(), 'document_id∈fixture' );

			case 'cpms_handwriting_page_versions':
				return $this->in_predicate( 'page_id', $scope['hw_page_ids'] ?? array(), 'page_id∈fixture' );

			case 'cpms_clinician_locations':
				return $this->in_predicate( 'clinician_id', $scope['clinician_ids'] ?? array(), 'clinician_id∈fixture' );

			case 'cpms_membership_capabilities':
			case 'cpms_membership_locations':
				return $this->in_predicate( 'membership_id', $scope['clinic_membership_ids'] ?? array(), 'membership_id∈fixture' );

			case 'cpms_custom_role_capabilities':
				return $this->in_predicate( 'role_def_id', $scope['custom_role_def_ids'] ?? array(), 'role_def_id∈fixture' );

			case 'cpms_organization_membership_capabilities':
				return $this->in_predicate( 'membership_id', $scope['org_membership_ids'] ?? array(), 'org_membership_id∈fixture' );

			case 'cpms_jobs':
				$max_job_id = (int) ( $manifest['ids']['baseline_max_job_id'] ?? 0 );

				return array(
					'where'  => 'id > %d',
					'params' => array( $max_job_id ),
					'label'  => 'id>' . $max_job_id,
				);

			case 'cpms_operational_logs':
				$max_oplog_id = (int) ( $manifest['ids']['baseline_max_oplog_id'] ?? 0 );

				return array(
					'where'  => 'id > %d',
					'params' => array( $max_oplog_id ),
					'label'  => 'id>' . $max_oplog_id,
				);

			case 'cpms_audit_logs':
				$max_audit_id = (int) ( $manifest['ids']['baseline_max_audit_id'] ?? 0 );

				return array(
					'where'  => 'id > %d',
					'params' => array( $max_audit_id ),
					'label'  => 'id>' . $max_audit_id,
				);

			case 'cpms_rate_limits':
				return array(
					'where'  => 'window_id >= %d',
					'params' => array( 0 ),
					'label'  => 'window_id>=0',
				);
		}

		if ( 'cpms_clinics' === $short && in_array( 'id', $columns, true ) ) {
			return array(
				'where'  => 'id = %d',
				'params' => array( $clinic_id ),
				'label'  => 'clinic=' . $clinic_id,
			);
		}
		if ( 'cpms_organizations' === $short && in_array( 'id', $columns, true ) ) {
			return array(
				'where'  => 'id = %d',
				'params' => array( $org_id ),
				'label'  => 'organization=' . $org_id,
			);
		}
		if ( in_array( 'clinic_id', $columns, true ) ) {
			return array(
				'where'  => 'clinic_id = %d',
				'params' => array( $clinic_id ),
				'label'  => 'clinic_id=' . $clinic_id,
			);
		}
		if ( in_array( 'organization_id', $columns, true ) ) {
			return array(
				'where'  => 'organization_id = %d',
				'params' => array( $org_id ),
				'label'  => 'organization_id=' . $org_id,
			);
		}
		$patient_ids = $scope['patient_ids'] ?? array();
		if ( in_array( 'patient_id', $columns, true ) && array() !== $patient_ids ) {
			return $this->in_predicate( 'patient_id', $patient_ids, 'patient_id∈fixture' );
		}
		foreach ( array( 'wp_user_id', 'user_id', 'actor_wp_user_id' ) as $column ) {
			if ( in_array( $column, $columns, true ) && array() !== $users ) {
				return $this->in_predicate( $column, $users, $column . '∈fixture' );
			}
		}
		if ( in_array( 'clinician_id', $columns, true ) && $clinician > 0 ) {
			return array(
				'where'  => 'clinician_id = %d',
				'params' => array( $clinician ),
				'label'  => 'clinician_id=' . $clinician,
			);
		}

		return null;
	}

	/**
	 * Delete only proven-owned objects, then prove the result.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $admin_id Acting Administrator.
	 * @return array{ok:bool,deleted:array<string,int>,remaining:list<string>,problems:list<string>}
	 * @throws Exception When ownership is not proven (nothing is deleted).
	 */
	public function execute( array $manifest, int $admin_id ): array {
		$verification = $this->verify( $manifest, $admin_id );
		if ( ! $verification['ok'] ) {
			throw new Exception(
				'TEARDOWN_BLOCKED',
				'پاک‌سازی مسدود شد؛ مالکیت کامل اثبات نشد: ' . implode( ' | ', array_slice( $verification['problems'], 0, 4 ) )
			);
		}

		$scope   = $this->scope_ids( $manifest );
		$deleted = array();
		foreach ( self::DELETE_ORDER as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $scope );
			if ( null === $predicate ) {
				continue;
			}
			$count = $this->platform->delete_where( $this->platform->table( $short ), $predicate['where'], $predicate['params'] );
			if ( $count > 0 ) {
				$deleted[ $short ] = $count;
			}
		}

		$users = (array) ( $manifest['ids']['user_ids'] ?? array() );
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $users[ $persona ] ?? 0 );
			$account = $user_id > 0 ? $this->env->user( $user_id ) : null;
			if ( null === $account ) {
				continue;
			}
			if ( $user_id === $admin_id || in_array( 'administrator', $account['roles'], true ) ) {
				throw new Exception( 'TEARDOWN_BLOCKED', 'حساب مدیر در محدودهٔ حذف قرار گرفت؛ عملیات متوقف شد.' );
			}
			if ( $this->env->delete_user( $user_id ) ) {
				$deleted['wp_users'] = ( $deleted['wp_users'] ?? 0 ) + 1;
			}
		}

		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
			if ( $this->env->delete_post( $page_id ) ) {
				$deleted['wp_posts'] = 1;
			}
		}

		$this->manifest->delete();

		$remaining = array();
		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, Preflight::KNOWN_TABLES, true ) ) {
				if ( $this->platform->count_all( $short ) > 0 ) {
					$remaining[] = $short;
				}
				continue;
			}
			if ( in_array( $short, self::BASELINE_TABLES, true ) ) {
				continue;
			}
			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $scope );
			if ( null === $predicate ) {
				if ( $this->platform->count_all( $short ) > 0 ) {
					$remaining[] = $short;
				}
				continue;
			}
			if ( $this->platform->count_where( $this->platform->table( $short ), $predicate['where'], $predicate['params'] ) > 0 ) {
				$remaining[] = $short;
			}
		}
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $users[ $persona ] ?? 0 );
			if ( $user_id > 0 && null !== $this->env->user( $user_id ) ) {
				$remaining[] = 'wp_user:' . $persona;
			}
		}
		if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
			$remaining[] = 'wp_page';
		}

		if ( array() === $remaining && (int) $manifest['ids']['organization_id'] > 0 ) {
			$clinic_left = $this->platform->fetch_row(
				'SELECT id FROM ' . $this->platform->table( 'cpms_clinics' ) . ' WHERE id = %d',
				array( (int) $manifest['ids']['clinic_id'] )
			);
			if ( null !== $clinic_left ) {
				$remaining[] = 'cpms_clinics';
			}
		}

		$summary = array(
			'fixture_id' => (string) $manifest['fixture_id'],
			'ok'         => array() === $remaining,
			'deleted'    => $deleted,
			'remaining'  => $remaining,
		);
		$this->manifest->write_tombstone( $summary );

		return $summary;
	}

	/**
	 * Read-only diagnostic for tables that still hold fixture rows. Never
	 * deletes anything; used when teardown must refuse.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @param int                 $admin_id Actor.
	 * @return array<string,mixed>
	 */
	public function diagnose( array $manifest, int $admin_id ): array {
		$verification = $this->verify( $manifest, $admin_id );

		return array(
			'ok'       => $verification['ok'],
			'problems' => $verification['problems'],
			'plan'     => $verification['plan'],
			'unknown'  => $verification['unknown'],
		);
	}
}
