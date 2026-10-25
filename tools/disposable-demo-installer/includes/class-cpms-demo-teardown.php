<?php
/**
 * Fail-closed teardown of exactly one synthetic demo.
 *
 * Ownership is proven from live rows, never from the manifest alone: every
 * object id must still carry the fixture markers, and every row touched must be
 * attributable to the fixture Clinic/Organization/patients/users. If ownership
 * cannot be proven for any table with data, teardown refuses and reports the
 * artifact instead of guessing. The default bootstrap Organization/Clinic/
 * Location and the Owner's Administrator account are never in scope.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership verification + selective deletion.
 */
class Teardown {

	/**
	 * Child-first deletion order. Tables without an ownership column are
	 * rejected before any DELETE runs.
	 */
	public const DELETE_ORDER = array(
		'cpms_payment_adjustments',
		'cpms_payments',
		'cpms_invoice_items',
		'cpms_invoices',
		'cpms_follow_ups',
		'cpms_recommendations',
		'cpms_prescription_items',
		'cpms_prescriptions',
		'cpms_clinical_note_versions',
		'cpms_clinical_notes',
		'cpms_visit_status_history',
		'cpms_visits',
		'cpms_appointments',
		'cpms_slot_holds',
		'cpms_schedule_slots',
		'cpms_schedule_exceptions',
		'cpms_schedule',
		'cpms_medical_attachments',
		'cpms_handwriting_page_versions',
		'cpms_handwriting_pages',
		'cpms_handwriting_documents',
		'cpms_ocr_jobs',
		'cpms_patient_user_links',
		'cpms_patient_merges',
		'cpms_patient_identities',
		'cpms_patients',
		'cpms_otp_tokens',
		'cpms_sms_messages',
		'cpms_notifications',
		'cpms_services',
		'cpms_rate_limits',
		'cpms_idempotency_keys',
		'cpms_clinician_locations',
		'cpms_membership_locations',
		'cpms_membership_capabilities',
		'cpms_clinic_memberships',
		'cpms_organization_membership_capabilities',
		'cpms_organization_memberships',
		'cpms_clinicians',
		'cpms_locations',
		'cpms_settings',
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

		$patient_rows = $this->platform->fetch_all(
			'SELECT id, mobile FROM ' . $this->platform->table( 'cpms_patients' ) . ' WHERE clinic_id = %d',
			array( $clinic_id )
		);
		$patient_ids  = array();
		foreach ( $patient_rows as $row ) {
			$patient_ids[] = (int) $row['id'];
		}

		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, Preflight::KNOWN_TABLES, true ) ) {
				$count = $this->platform->count_all( $short );
				if ( $count > 0 ) {
					$unknown[] = $short . ' (' . $count . ' ردیف)';
				}
				continue;
			}
			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $patient_ids );
			if ( null === $predicate ) {
				$count = $this->platform->count_all( $short );
				if ( $count > 0 ) {
					$unknown[] = $short . ' (بدون ستون مالکیت؛ ' . $count . ' ردیف)';
				}
				continue;
			}
			$rows = $this->platform->count_where( $this->platform->table( $short ), $predicate['where'], $predicate['params'] );
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
	 * @param string             $short Table short name.
	 * @param list<string>       $columns Columns.
	 * @param array<string,mixed> $manifest Manifest.
	 * @param list<int>          $patient_ids Patients currently inside the fixture clinic.
	 * @return array{where:string,params:list<mixed>,label:string}|null
	 */
	private function predicate( string $short, array $columns, array $manifest, array $patient_ids ): ?array {
		$clinic_id = (int) $manifest['ids']['clinic_id'];
		$org_id    = (int) $manifest['ids']['organization_id'];
		$users     = array_values( array_map( 'intval', (array) $manifest['ids']['user_ids'] ) );
		$clinician = (int) ( $manifest['ids']['clinician_id'] ?? 0 );

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
		if ( in_array( 'patient_id', $columns, true ) && array() !== $patient_ids ) {
			$in = implode( ',', array_fill( 0, count( $patient_ids ), '%d' ) );

			return array(
				'where'  => 'patient_id IN (' . $in . ')',
				'params' => $patient_ids,
				'label'  => 'patient_id∈fixture',
			);
		}
		foreach ( array( 'wp_user_id', 'user_id', 'actor_wp_user_id' ) as $column ) {
			if ( in_array( $column, $columns, true ) && array() !== $users ) {
				$in = implode( ',', array_fill( 0, count( $users ), '%d' ) );

				return array(
					'where'  => $column . ' IN (' . $in . ')',
					'params' => $users,
					'label'  => $column . '∈fixture',
				);
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

		$patient_ids = array();
		foreach ( $this->platform->fetch_all(
			'SELECT id FROM ' . $this->platform->table( 'cpms_patients' ) . ' WHERE clinic_id = %d',
			array( (int) $manifest['ids']['clinic_id'] )
		) as $row ) {
			$patient_ids[] = (int) $row['id'];
		}

		$deleted = array();
		foreach ( self::DELETE_ORDER as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $patient_ids );
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
			$columns   = $this->platform->columns( $this->platform->table( $short ) );
			$predicate = $this->predicate( $short, $columns, $manifest, $patient_ids );
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

		try {
			$this->platform->audit( 'CPDD_DEMO_TORN_DOWN', $admin_id, 0, $summary );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}

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
