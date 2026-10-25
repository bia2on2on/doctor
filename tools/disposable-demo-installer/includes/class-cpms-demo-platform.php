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
		$sql = 'DELETE FROM `' . str_replace( '`', '', $table ) . '` WHERE ' . $where;
		if ( $params !== array() ) {
			$sql = $this->db()->prepare( $sql, $params );
		}

		return (int) $this->db()->execute( $sql );
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

		return (int) ( $result['id'] ?? 0 );
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
		return $this->db()->fetchRow(
			'SELECT id, expires_at, attempts, consumed_at FROM ' . $this->db()->table( 'cpms_otp_tokens' )
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
		$links['reception'] = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_RECEPTION, $links['staff'] );
		$links['finance']   = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_FINANCE, $links['staff'] );
		$links['doctor']    = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_DOCTOR, $links['staff'] );
		$links['reports']   = add_query_arg( 'cpms-module', StaffPortalShell::MODULE_REPORTS, $links['staff'] );

		return $links;
	}

	/**
	 * @param int $clinic_id Clinic.
	 * @return string
	 */
	public function booking_shortcode( int $clinic_id ): string {
		return '[' . PublicBookingShortcode::TAG . ' clinic_id="' . $clinic_id . '"]';
	}

	/**
	 * @return list<string> CPMS role keys used by the six personas.
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
