<?php

declare(strict_types=1);

namespace ClinicCore\Application\Demo;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Explicit, bounded demonstration facility for disposable installations.
 *
 * No WordPress business role is granted to the five staff users. Their only
 * authority is the durable Organization/Clinic membership created below. The
 * patient keeps the existing Patient role required by OtpService.
 */
final class DemoService {

    public const STATE_OPTION      = 'cpms_demo_state';
    public const OUTBOX_OPTION     = 'cpms_demo_otp_outbox';
    public const JOB_OPTION_PREFIX = 'cpms_demo_job_';
    public const PROVIDER_ID       = 'cpms_demo';
    public const LIFETIME_SECONDS  = 2592000; // 30 days.

    private const PATIENT_MOBILE = '09000000000';
    private const STAFF_KEYS     = ['organization_manager', 'clinic_manager', 'doctor', 'receptionist', 'accountant'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly CredentialVault $vault
    ) {
    }

    /** @return array<string, mixed> */
    public function state(): array {
        $state = get_option( self::STATE_OPTION, [] );
        return is_array( $state ) ? $state : [];
    }

    public function is_active(): bool {
        $state = $this->state();
        return (string) ( $state['status'] ?? '' ) === 'active'
            && (int) ( $state['expires_at'] ?? 0 ) > time();
    }

    public function is_demo_clinic( int $clinic_id ): bool {
        return $this->is_active() && $clinic_id > 0 && $clinic_id === (int) ( $this->state()['clinic_id'] ?? 0 );
    }

    /**
     * Track only jobs persisted inside this exact demo's trusted Clinic scope.
     * One atomic option per job avoids lost state updates under concurrency.
     * Values contain only the Clinic ID; payloads and secrets stay in the queue.
     */
    public function track_scoped_job( int $job_id, string $type ): void {
        unset( $type );
        if ( $job_id <= 0 ) {
            return;
        }
        $scope = ScopeContext::tryGet();
        if ( $scope === null ) {
            return;
        }
        $state           = $this->state();
        $status          = (string) ( $state['status'] ?? '' );
        $demo_id         = (string) ( $state['demo_id'] ?? '' );
        $clinic_id       = (int) ( $state['clinic_id'] ?? 0 );
        $scope_clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Established ClinicScope public property contract.
        if ( ! in_array( $status, ['provisioning', 'active'], true )
            || ( $status === 'active' && (int) ( $state['expires_at'] ?? 0 ) <= time() )
            || $clinic_id <= 0
            || $scope_clinic_id !== $clinic_id
            || preg_match( '/^[a-f0-9]{16}$/', $demo_id ) !== 1
        ) {
            return;
        }
        $option = self::JOB_OPTION_PREFIX . $demo_id . '_' . $job_id;
        if ( ! add_option( $option, (string) $clinic_id, '', false )
            && (string) get_option( $option, '' ) !== (string) $clinic_id
        ) {
            throw new RuntimeException( 'Demo job ownership marker persistence failed.' );
        }
    }

    public function is_blocked_user( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }
        $state = $this->state();
        if ( $state === [] || (string) ( $state['status'] ?? '' ) === 'cleaned' ) {
            return false;
        }
        if ( $this->is_active() ) {
            return false;
        }
        foreach ( (array) ( $state['users'] ?? [] ) as $entry ) {
            if ( is_array( $entry ) && (int) ( $entry['id'] ?? 0 ) === $user_id ) {
                return true;
            }
        }
        return false;
    }

    public function assert_administrator( int $actor_id ): void {
        $user     = $actor_id > 0 ? get_userdata( $actor_id ) : false;
        $is_admin = $user !== false
            && (int) get_current_user_id() === $actor_id
            && user_can( $actor_id, 'manage_options' )
            && ( in_array( 'administrator', (array) $user->roles, true ) || ( function_exists( 'is_super_admin' ) && is_super_admin( $actor_id ) ) );
        if ( ! $is_admin ) {
            throw new RuntimeException( 'Demo administration requires a WordPress Administrator.' );
        }
    }

    /**
     * Create one fresh tenant. Repeated invocation is a no-op and never reveals
     * replacement credentials. Credentials exist only in this return value.
     *
     * @return array{created: bool, credentials: array<string, array{username: string, password: string}>}
     */
    public function provision( int $actor_id, bool $acknowledged ): array {
        $this->assert_administrator( $actor_id );
        if ( $this->state() !== [] ) {
            return ['created' => false, 'credentials' => []];
        }
        if ( ! $acknowledged ) {
            throw new RuntimeException( 'Explicit disposable-site acknowledgement is required.' );
        }

        $now     = time();
        $demo_id = bin2hex( random_bytes( 8 ) );
        $state   = [
            'version'         => 1,
            'demo_id'         => $demo_id,
            'status'          => 'provisioning',
            'acknowledged_at' => $now,
            'expires_at'      => $now + self::LIFETIME_SECONDS,
            'users'           => [],
            'patient_mobile'  => self::PATIENT_MOBILE,
        ];
        // Atomic option claim: two concurrent Administrators cannot both provision.
        if ( ! add_option( self::STATE_OPTION, $state, '', true ) ) {
            return ['created' => false, 'credentials' => []];
        }

        try {
            $credentials = $this->provision_claimed( $actor_id, $state );
            $state       = $this->state();
            $clinic_id   = (int) ( $state['clinic_id'] ?? 0 );
            $expires_at  = (int) ( $state['expires_at'] ?? 0 );
            if ( $clinic_id <= 0 || $expires_at <= 0 ) {
                throw new RuntimeException( 'Demo provisioning completed without a valid tenant or expiry.' );
            }
            $state['status']         = 'active';
            $state['provisioned_at'] = time();
            $this->save_state( $state );
            App::audit()->log(
                'DEMO_PROVISIONED',
                ['wp_user_id' => $actor_id, 'role' => 'administrator'],
                'clinic',
                $clinic_id,
                null,
                null,
                ['demo_id' => $demo_id, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires_at )],
                ['op' => 'demo_provision'],
                $clinic_id
            );
            return ['created' => true, 'credentials' => $credentials];
        } catch ( Throwable $e ) {
            // Keep the non-active claim and all IDs needed for an explicit,
            // owned cleanup. Provisioning never silently retries or reuses rows.
            App::op()->error(
                'DEMO_PROVISION_FAILED',
                [
					'demo_id' => $demo_id,
					'error'   => $e->getMessage(),
				]
            );
            throw $e;
        }
    }

    /** @return array<string, array{username: string, password: string}> */
    private function provision_claimed( int $actor_id, array $state ): array {
        $demo_id     = (string) $state['demo_id'];
        $credentials = [];
        $labels      = [
            'organization_manager' => 'Demo Organization Manager',
            'clinic_manager'       => 'Demo Clinic Manager',
            'doctor'               => 'Demo Doctor',
            'receptionist'         => 'Demo Receptionist',
            'accountant'           => 'Demo Accountant',
            'patient'              => 'Demo Patient',
        ];
        foreach ( $labels as $key => $label ) {
            [$user_id, $username, $password] = $this->create_user( $key, $label, $demo_id, $key === 'patient' );
            $credentials[ $key ]             = ['username' => $username, 'password' => $password];
            $state['users'][ $key ]          = ['id' => $user_id, 'username' => $username];
            $this->save_state( $state );
        }

        $created_jobs      = [];
        $created_job_types = [];
        $capture_job       = static function ( int $job_id, string $type ) use ( &$created_jobs, &$created_job_types ): void {
            $created_jobs[]               = $job_id;
            $created_job_types[ $job_id ] = $type;
        };
        add_action( 'cpms_job_enqueued', $capture_job, 10, 2 );
        try {
            $now = $this->db->nowUtcSql();
            if ( ! $this->db->insert(
                'cpms_organizations',
                [
					'name'       => 'CPMS Synthetic Demo ' . $demo_id,
					'slug'       => 'cpms-demo-' . $demo_id,
					'status'     => 'active',
					'created_at' => $now,
					'updated_at' => $now,
				]
            ) ) {
                throw new RuntimeException( 'Demo Organization insert failed.' );
            }
            $state['organization_id'] = $this->db->wpdb_last_insert_id();
            $this->save_state( $state );

            $onboarding = App::organization_clinic_provisioning_service();
            $onboarding->bootstrap_owner(
                $actor_id,
                (int) $state['organization_id'],
                (int) $state['users']['organization_manager']['id']
            );
            $clinic               = $onboarding->provision(
                (int) $state['users']['organization_manager']['id'],
                (int) $state['organization_id'],
                'مطب آزمایشی CPMS',
                'cpms-demo-clinic-' . $demo_id,
                'Asia/Tehran',
                'محل آزمایشی',
                'نشانی کاملاً فرضی',
                null
            );
            $state['clinic_id']   = (int) $clinic['clinic_id'];
            $state['location_id'] = (int) $clinic['location_id'];
            $this->save_state( $state );

            $onboarding->designate_clinic_manager(
                (int) $state['users']['organization_manager']['id'],
                (int) $state['organization_id'],
                (int) $state['clinic_id'],
                (int) $state['users']['clinic_manager']['id']
            );
            foreach ( ['doctor' => RolesAndCapabilities::ROLE_DOCTOR, 'receptionist' => RolesAndCapabilities::ROLE_SECRETARY, 'accountant' => RolesAndCapabilities::ROLE_ACCOUNTANT] as $key => $role ) {
                App::membership_service()->create_membership(
                    (int) $state['clinic_id'],
                    (int) $state['users'][ $key ]['id'],
                    $role,
                    'clinic',
                    $actor_id
                );
            }

            $previous = ScopeContext::tryGet();
            ScopeContext::set( ClinicScope::forClinic( (int) $state['clinic_id'], (int) $state['location_id'] ) );
            try {
                $clinician_id = App::clinicianRepository()->create(
                    (int) $state['clinic_id'],
                    [
						'wp_user_id' => (int) $state['users']['doctor']['id'],
						'full_name'  => 'دکتر آزمایشی CPMS',
						'specialty'  => 'پزشک عمومی (داده فرضی)',
						'room'       => 'DEMO',
						'is_active'  => 1,
					]
                );
                if ( $clinician_id <= 0 ) {
                    throw new RuntimeException( 'Demo Clinician insert failed.' );
                }
                $state['clinician_id'] = $clinician_id;
                $this->save_state( $state );

                // ScheduleService::create() enqueues the global slots.generate
                // handler. That unsafe side effect is deliberately avoided here:
                // insert only seven constant, validated rows for this exact demo
                // Clinician/Location, then create the bounded slots below directly.
                for ( $day = 0; $day <= 6; $day++ ) {
                    if ( ! $this->db->insert(
                        'cpms_schedule',
                        [
							'clinic_id'                => (int) $state['clinic_id'],
							'clinician_id'             => $clinician_id,
							'location_id'              => (int) $state['location_id'],
							'day_of_week'              => $day,
							'start_time'               => '00:00:00',
							'end_time'                 => '23:59:00',
							'break_start'              => null,
							'break_end'                => null,
							'appointment_duration_min' => 20,
							'slot_capacity'            => 1,
							'is_active'                => 1,
							'created_at'               => $now,
							'updated_at'               => $now,
						]
                    ) ) {
                        throw new RuntimeException( 'Demo schedule persistence failed.' );
                    }
                }

                $patient              = App::patientService()->create(
                    [
						'first_name' => 'بیمار',
						'last_name'  => 'کاملاً آزمایشی',
						'mobile'     => self::PATIENT_MOBILE,
						'gender'     => 'unknown',
						'birth_date' => null,
					],
                    (int) $state['users']['receptionist']['id']
                );
                $state['patient_id']  = (int) $patient['id'];
                $state['identity_id'] = (int) ( $patient['identity_id'] ?? 0 );
                $this->save_state( $state );

                // The demo provider is selected only after its exact Clinic exists.
                // Zero lead is scoped to this synthetic Clinic so its same-day
                // booking can reach reception; every other Clinic keeps its policy.
                $demo_settings = App::settingsFactory()->forClinic( (int) $state['clinic_id'] );
                $demo_settings->set( 'sms.provider', self::PROVIDER_ID );
                $demo_settings->set( 'booking.min_lead_hours', 0 );
                $this->seed_operational_data( $state, $credentials );
                $page_id = wp_insert_post(
                    [
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_author'  => $actor_id,
						'post_name'    => 'cpms-demo-booking-' . $demo_id,
						'post_title'   => 'رزرو آزمایشی CPMS (داده فرضی)',
						'post_content' => '[cpms_public_booking clinic_id="' . (int) $state['clinic_id'] . '"]',
					],
                    true
                );
                if ( is_wp_error( $page_id ) || (int) $page_id <= 0 ) {
                    throw new RuntimeException( 'Demo booking page creation failed.' );
                }
                $state['booking_page_id'] = (int) $page_id;
                $this->save_state( $state );
            } finally {
                App::replaceExplicitScope( $previous );
            }
        } finally {
            remove_action( 'cpms_job_enqueued', $capture_job, 10 );
            $state['job_ids']   = array_values( array_unique( array_map( 'intval', $created_jobs ) ) );
            $state['job_types'] = $created_job_types;
            $this->save_state( $state );
        }

        return $credentials;
    }

    /** @param array<string, mixed> $state @param array<string, array{username: string, password: string}> $credentials */
    private function seed_operational_data( array &$state, array $credentials ): void {
        unset( $credentials ); // Secrets are never copied into fixture/audit state.
        $clinic_id       = (int) $state['clinic_id'];
        $location_id     = (int) $state['location_id'];
        $doctor_id       = (int) $state['users']['doctor']['id'];
        $receptionist_id = (int) $state['users']['receptionist']['id'];
        $accountant_id   = (int) $state['users']['accountant']['id'];
        $patient_id      = (int) $state['patient_id'];
        $clinician_id    = (int) $state['clinician_id'];

        // Up to three bounded, same-day future slots for the real reception
        // journey. No global slot generator or other tenant is touched.
        $local      = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Tehran' ) );
        $first_slot = $local->modify( '+30 minutes' );
        if ( $first_slot->format( 'Y-m-d' ) !== $local->format( 'Y-m-d' ) ) {
            $first_slot = $local->modify( '+1 minute' );
        }
        if ( $first_slot->format( 'Y-m-d' ) !== $local->format( 'Y-m-d' ) ) {
            throw new RuntimeException( 'A same-day demo journey requires a future local slot.' );
        }
        $slot_date       = $first_slot->format( 'Y-m-d' );
        $now             = $this->db->nowUtcSql();
        $slot_id         = 0;
        $staff_slot_id   = 0;
        $staff_slot_time = '';
        foreach ( [0, 20, 40] as $offset ) {
            $slot = $first_slot->modify( '+' . $offset . ' minutes' );
            if ( $slot->format( 'Y-m-d' ) !== $slot_date ) {
                continue;
            }
            $slot_time = $slot->format( 'H:i:00' );
            $this->db->query(
                'INSERT IGNORE INTO ' . $this->db->table( 'cpms_schedule_slots' )
                . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)'
                . ' VALUES (%d, %d, %d, %s, %s, %d, %d, 0, 0, 1, %s, %s, %s)',
                [$clinic_id, $location_id, $clinician_id, $slot_date, $slot_time, 20, 1, 'manual', $now, $now]
            );
            $current_slot_id = (int) $this->db->fetchValue(
                'SELECT id FROM ' . $this->db->table( 'cpms_schedule_slots' )
                . ' WHERE clinic_id = %d AND location_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s LIMIT 1',
                [$clinic_id, $location_id, $clinician_id, $slot_date, $slot_time]
            );
            if ( $current_slot_id <= 0 ) {
                throw new RuntimeException( 'Demo slot persistence failed.' );
            }
            if ( $offset === 0 ) {
                $slot_id = $current_slot_id;
            }
            if ( $offset === 20 ) {
                $staff_slot_id   = $current_slot_id;
                $staff_slot_time = $slot_time;
            }
        }
        if ( $slot_id <= 0 || $staff_slot_id <= 0 || $staff_slot_time === '' ) {
            throw new RuntimeException( 'A same-day demo journey requires two future local slots.' );
        }
        $state['slot_id'] = $slot_id;
        $this->save_state( $state );
        $appointment             = App::bookingService()->createByStaff(
            $receptionist_id,
            $patient_id,
            $clinician_id,
            $slot_date,
            $staff_slot_time,
            'DEMO-' . (string) $state['demo_id'],
            $staff_slot_id
        );
        $state['appointment_id'] = (int) $appointment['appointment_id'];
        $this->save_state( $state );

        $service             = App::financeService()->createService(
            (int) $state['users']['clinic_manager']['id'],
            [
				'code'  => 'DEMO-CONSULT',
				'name'  => 'ویزیت آزمایشی (بدون ارزش واقعی)',
				'price' => 1000,
			]
        );
        $state['service_id'] = (int) $service['id'];
        $this->save_state( $state );

        // Completed + paid + checked-out evidence for management review.
        $visit    = App::visitService()->walkIn( $receptionist_id, $patient_id, $clinician_id, ['note' => 'داده کاملاً فرضی'] );
        $visit_id = (int) $visit['id'];
        App::visitService()->transition( $doctor_id, $visit_id, 'call', ['room' => 'DEMO'] );
        App::visitService()->transition( $doctor_id, $visit_id, 'start' );
        App::clinicalService()->addNote(
            $doctor_id,
            $visit_id,
            [
				'category'     => 'chief_complaint',
				'visibility'   => 'patient_visible',
				'content_text' => 'شکایت فرضی صرفاً برای آزمون نرم‌افزار؛ هیچ داده پزشکی واقعی نیست.',
			]
        );
        App::clinicalService()->addNote(
            $doctor_id,
            $visit_id,
            [
				'category'     => 'examination',
				'visibility'   => 'patient_visible',
				'content_text' => 'شرح‌حال و معاینه فرضی برای نمایش فرایند.',
			]
        );
        $rx = App::clinicalService()->createPrescription(
            $doctor_id,
            $visit_id,
            [
				'items' => [
					[
						'generic_name'  => 'SYNTHETIC-NOT-A-DRUG',
						'dose'          => '0 demo-unit',
						'frequency'     => 'test only',
						'form'          => 'tablet',
						'route'         => 'oral',
						'duration_days' => 1,
						'instructions'  => 'نسخه غیرواقعی؛ هرگز مصرف نشود.',
					],
				],
			]
        );
        App::clinicalService()->finalizePrescription( $doctor_id, (int) $rx['id'] );
        App::clinicalService()->completeConsultation( $doctor_id, $visit_id );
        $invoice = App::financeService()->issueInvoice(
            $accountant_id,
            [
				'visit_id' => $visit_id,
				'items'    => [
					[
						'service_id'  => (int) $state['service_id'],
						'description' => 'ویزیت آزمایشی',
						'quantity'    => 1,
						'unit_price'  => 1000,
						'discount'    => 0,
					],
				],
				'discount' => 0,
				'tax'      => 0,
			]
        );
        App::financeService()->recordPayment(
            $accountant_id,
            (int) $invoice['id'],
            [
				'amount'          => 1000,
				'method'          => 'other',
				'transaction_ref' => 'SYNTHETIC-NO-PAYMENT',
			],
            'demo-payment-' . (string) $state['demo_id']
        );
        App::visitService()->transition( $receptionist_id, $visit_id, 'check_out' );
        $state['completed_visit_id'] = $visit_id;
        $state['invoice_id']         = (int) $invoice['id'];
        $this->save_state( $state );

        // A second, still-waiting visit lets the Owner exercise the live queue.
        $active                   = App::visitService()->walkIn( $receptionist_id, $patient_id, $clinician_id, ['note' => 'ویزیت فعال آزمایشی'] );
        $active_id                = (int) $active['id'];
        $state['active_visit_id'] = $active_id;
        $this->save_state( $state );
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function create_user( string $key, string $label, string $demo_id, bool $patient ): array {
        $username = 'cpms-demo-' . $key . '-' . $demo_id;
        $password = bin2hex( random_bytes( 24 ) );
        $user_id  = wp_insert_user(
            [
				'user_login'   => $username,
				'user_pass'    => $password,
				// The Patient uses OtpService's established deterministic identity
				// so first verification links this exact account, never a duplicate.
				'user_email'   => $patient ? self::PATIENT_MOBILE . '@otp.cpms.local' : $username . '@demo.invalid',
				'display_name' => $label,
				'role'         => $patient ? RolesAndCapabilities::ROLE_PATIENT : 'subscriber',
			]
        );
        if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) {
            throw new RuntimeException( 'Demo user creation failed for ' . $key . '.' );
        }
        return [ (int) $user_id, $username, $password];
    }

    /** @return array{username: string, password: string} */
    public function rotate_password( int $actor_id, string $key ): array {
        $this->assert_administrator( $actor_id );
        $this->assert_active();
        $state = $this->state();
        $entry = $state['users'][ $key ] ?? null;
        if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['username'] ) ) {
            throw new RuntimeException( 'Unknown demo account.' );
        }
        $user_id = (int) $entry['id'];
        if ( get_userdata( $user_id ) === false ) {
            throw new RuntimeException( 'Demo account no longer exists; cleanup is required.' );
        }
        $password = bin2hex( random_bytes( 24 ) );
        wp_set_password( $password, $user_id );
        \WP_Session_Tokens::get_instance( $user_id )->destroy_all();
        clean_user_cache( $user_id );
        App::audit()->log(
            'DEMO_CREDENTIAL_ROTATED',
            ['wp_user_id' => $actor_id, 'role' => 'administrator'],
            'demo_user',
            $user_id,
            null,
            null,
            ['account' => $key, 'sessions_destroyed' => true],
            ['op' => 'demo_rotate'],
            (int) $state['clinic_id']
        );
        return ['username' => (string) $entry['username'], 'password' => $password];
    }

    public function latest_otp( int $actor_id ): string {
        $this->assert_administrator( $actor_id );
        $this->assert_active();
        $state  = $this->state();
        $outbox = get_option( self::OUTBOX_OPTION, [] );
        if ( ! is_array( $outbox )
            || (int) ( $outbox['clinic_id'] ?? 0 ) !== (int) $state['clinic_id']
            || (string) ( $outbox['mobile'] ?? '' ) !== self::PATIENT_MOBILE
            || ! isset( $outbox['sealed'] ) || ! is_array( $outbox['sealed'] )
        ) {
            throw new RuntimeException( 'No demo OTP is available. Request a new code first.' );
        }
        $code = $this->vault->decrypt( $outbox['sealed'] );
        if ( $code === null || preg_match( '/^\d{6}$/', $code ) !== 1 ) {
            throw new RuntimeException( 'Demo OTP could not be decrypted. Request a new code.' );
        }
        return $code;
    }

    /** Internal demo-provider sink; not an Administrator/public read API. */
    public function capture_otp( int $clinic_id, string $mobile, string $code ): void {
        $state = $this->state();
        if ( ! $this->is_active()
            || $clinic_id !== (int) ( $state['clinic_id'] ?? 0 )
            || $mobile !== self::PATIENT_MOBILE
            || preg_match( '/^\d{6}$/', $code ) !== 1
        ) {
            throw new RuntimeException( 'Demo SMS provider rejected the recipient or inactive demo.' );
        }
        update_option(
            self::OUTBOX_OPTION,
            [
				'clinic_id'  => $clinic_id,
				'mobile'     => $mobile,
				'sealed'     => $this->vault->encrypt( $code ),
				'created_at' => time(),
			],
            false
        );
    }

    public function end( int $actor_id ): void {
        $this->assert_administrator( $actor_id );
        $state = $this->state();
        if ( $state === [] ) {
            return;
        }
        $was_active        = (string) ( $state['status'] ?? '' ) === 'active';
        $state['status']   = 'ended';
        $state['ended_at'] = time();
        // Revocation is durable BEFORE session cleanup or lifecycle side effects.
        $this->save_state( $state );
        delete_option( self::OUTBOX_OPTION );
        $this->destroy_demo_sessions( $state );
        $this->suspend_demo_clinic( $state );
        if ( $was_active ) {
            App::audit()->log(
                'DEMO_ENDED',
                ['wp_user_id' => $actor_id, 'role' => 'administrator'],
                'clinic',
                (int) $state['clinic_id'],
                null,
                ['demo.status' => 'active'],
                ['demo.status' => 'ended'],
                ['op' => 'demo_end'],
                (int) $state['clinic_id']
            );
        }
    }

    /** Request-driven expiry, never a WP-Cron-only check. */
    public function expire_if_due(): void {
        $state = $this->state();
        if ( (string) ( $state['status'] ?? '' ) !== 'active' || (int) ( $state['expires_at'] ?? 0 ) > time() ) {
            return;
        }
        $state['status']     = 'expired';
        $state['expired_at'] = time();
        $this->save_state( $state );
        delete_option( self::OUTBOX_OPTION );
        $this->destroy_demo_sessions( $state );
        try {
            $this->suspend_demo_clinic( $state );
            App::audit()->log(
                'DEMO_EXPIRED',
                ['wp_user_id' => (int) $state['users']['organization_manager']['id'], 'role' => 'organization_manager'],
                'clinic',
                (int) $state['clinic_id'],
                null,
                ['demo.status' => 'active'],
                ['demo.status' => 'expired'],
                ['op' => 'demo_expire'],
                (int) $state['clinic_id']
            );
        } catch ( Throwable $e ) {
            // Authentication remains revoked even if lifecycle maintenance fails.
            App::op()->error(
                'DEMO_EXPIRY_MAINTENANCE_FAILED',
                [
					'demo_id' => (string) $state['demo_id'],
					'error'   => $e->getMessage(),
				]
            );
        }
    }

    /**
     * Delete demo-owned operational rows/users. Suspended Organization/Clinic
     * tombstones and append-only audit/operational logs are retained deliberately.
     */
    public function cleanup( int $actor_id ): void {
        $this->assert_administrator( $actor_id );
        $state = $this->state();
        if ( $state === [] ) {
            delete_option( self::OUTBOX_OPTION );
            return;
        }
        if ( (string) ( $state['status'] ?? '' ) !== 'ended' && (string) ( $state['status'] ?? '' ) !== 'expired' ) {
            $this->end( $actor_id );
            $state = $this->state();
        }
        $clinic_id = (int) ( $state['clinic_id'] ?? 0 );
        if ( $clinic_id > 0 ) {
            $this->assert_demo_tenant( $state );
            App::audit()->log(
                'DEMO_CLEANUP_STARTED',
                ['wp_user_id' => $actor_id, 'role' => 'administrator'],
                'clinic',
                $clinic_id,
                null,
                null,
                ['demo_id' => (string) $state['demo_id']],
                ['op' => 'demo_cleanup'],
                $clinic_id
            );
            $this->purge_operational_rows( $state );
        } else {
            // A failed claim may predate Clinic creation. Only tracked users,
            // jobs and the fresh Organization membership can then exist.
            $organization_id = (int) ( $state['organization_id'] ?? 0 );
            if ( $organization_id > 0 ) {
                $this->execute_cleanup( 'DELETE FROM ' . $this->db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d', [$organization_id] );
            }
        }
        $this->purge_demo_jobs( $state );
        foreach ( (array) ( $state['users'] ?? [] ) as $entry ) {
            $user_id = is_array( $entry ) ? (int) ( $entry['id'] ?? 0 ) : 0;
            if ( $user_id > 0 && get_userdata( $user_id ) !== false ) {
                if ( ! function_exists( 'wp_delete_user' ) ) {
                    // @phpstan-ignore requireOnce.fileNotFound
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                if ( ! wp_delete_user( $user_id ) ) {
                    throw new RuntimeException( 'Demo user deletion failed; no other users were targeted.' );
                }
            }
        }
        $page_id = (int) ( $state['booking_page_id'] ?? 0 );
        if ( $page_id > 0 ) {
            $page = get_post( $page_id );
            if ( $page !== null && (string) $page->post_name === 'cpms-demo-booking-' . (string) $state['demo_id'] ) {
                wp_delete_post( $page_id, true );
            }
        }
        delete_option( self::OUTBOX_OPTION );
        delete_option( self::STATE_OPTION );
    }

    /** @param array<string, mixed> $state */
    private function purge_demo_jobs( array $state ): void {
        $markers = $this->tracked_job_markers( $state );
        $job_ids = array_map( 'intval', (array) ( $state['job_ids'] ?? [] ) );
        foreach ( $markers as $job_id ) {
            $job_ids[] = $job_id;
        }
        foreach ( array_unique( $job_ids ) as $job_id ) {
            if ( $job_id > 0 ) {
                $this->execute_cleanup( 'DELETE FROM ' . $this->db->table( 'cpms_jobs' ) . ' WHERE id = %d', [$job_id] );
            }
        }
        foreach ( array_keys( $markers ) as $option ) {
            if ( ! delete_option( $option ) && get_option( $option, false ) !== false ) {
                throw new RuntimeException( 'Demo job marker cleanup failed.' );
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, int> Exact option name => exact job ID.
     */
    private function tracked_job_markers( array $state ): array {
        $demo_id   = (string) ( $state['demo_id'] ?? '' );
        $clinic_id = (int) ( $state['clinic_id'] ?? 0 );
        if ( $clinic_id <= 0 || preg_match( '/^[a-f0-9]{16}$/', $demo_id ) !== 1 ) {
            return [];
        }
        $prefix  = self::JOB_OPTION_PREFIX . $demo_id . '_';
        $rows    = $this->db->fetchAll(
            'SELECT option_name, option_value FROM ' . $this->db->wpdb()->options . ' WHERE option_name LIKE %s',
            [$this->db->wpdb()->esc_like( $prefix ) . '%']
        );
        $markers = [];
        foreach ( $rows as $row ) {
            $option = (string) ( $row['option_name'] ?? '' );
            if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d+)$/', $option, $matches ) !== 1
                || (string) ( $row['option_value'] ?? '' ) !== (string) $clinic_id
            ) {
                throw new RuntimeException( 'Demo job marker ownership proof failed; cleanup refused.' );
            }
            $markers[ $option ] = (int) $matches[1];
        }
        return $markers;
    }

    /** @param array<string, mixed> $state */
    private function assert_demo_tenant( array $state ): void {
        $clinic_id       = (int) ( $state['clinic_id'] ?? 0 );
        $organization_id = (int) ( $state['organization_id'] ?? 0 );
        $row             = $this->db->fetchRow(
            'SELECT id, organization_id, slug FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [$clinic_id]
        );
        if ( $clinic_id <= 0 || $organization_id <= 0 || $row === null
            || (int) $row['organization_id'] !== $organization_id
            || (string) $row['slug'] !== 'cpms-demo-clinic-' . (string) ( $state['demo_id'] ?? '' )
        ) {
            throw new RuntimeException( 'Demo tenant ownership proof failed; cleanup refused.' );
        }
    }

    /** @param array<string, mixed> $state */
    private function purge_operational_rows( array $state ): void {
        $clinic_id = (int) $state['clinic_id'];
        // Child-before-parent. Tables without clinic_id are removed by their
        // existing ON DELETE CASCADE relation. Audit logs are NOT in this list.
        // Child tables without their own clinic_id are deleted through the
        // already-proven owning parent, never through a request-supplied ID.
        $this->execute_cleanup(
            'DELETE FROM ' . $this->db->table( 'cpms_clinical_note_versions' )
            . ' WHERE note_id IN (SELECT id FROM ' . $this->db->table( 'cpms_clinical_notes' ) . ' WHERE clinic_id = %d)',
            [$clinic_id]
        );
        $this->execute_cleanup(
            'DELETE FROM ' . $this->db->table( 'cpms_payment_adjustments' )
            . ' WHERE invoice_id IN (SELECT id FROM ' . $this->db->table( 'cpms_invoices' ) . ' WHERE clinic_id = %d)',
            [$clinic_id]
        );
        $this->execute_cleanup(
            'DELETE FROM ' . $this->db->table( 'cpms_visit_status_history' )
            . ' WHERE visit_id IN (SELECT id FROM ' . $this->db->table( 'cpms_visits' ) . ' WHERE clinic_id = %d)',
            [$clinic_id]
        );
        $tables = [
            'cpms_ocr_jobs',
            'cpms_handwriting_documents',
            'cpms_medical_attachments',
            'cpms_clinical_notes',
            'cpms_follow_ups',
            'cpms_recommendations',
            'cpms_prescriptions',
            'cpms_drug_reference',
            'cpms_payments',
            'cpms_invoices',
            'cpms_visits',
            'cpms_appointments',
            'cpms_slot_holds',
            'cpms_schedule_slots',
            'cpms_schedule_exceptions',
            'cpms_schedule',
            'cpms_notifications',
            'cpms_idempotency_keys',
            'cpms_otp_tokens',
            'cpms_sms_messages',
            'cpms_custom_role_defs',
            'cpms_settings',
            'cpms_services',
            'cpms_patient_merges',
            'cpms_patient_user_links',
            'cpms_patients',
            'cpms_clinic_memberships',
            'cpms_clinicians',
            'cpms_locations',
        ];
        foreach ( $tables as $table ) {
            // All names are fixed above, never request-controlled.
            $this->execute_cleanup( 'DELETE FROM ' . $this->db->table( $table ) . ' WHERE clinic_id = %d', [$clinic_id] );
            $remaining = (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM ' . $this->db->table( $table ) . ' WHERE clinic_id = %d', [$clinic_id] );
            $this->assert_cleanup_query();
            if ( $remaining !== 0 ) {
                throw new RuntimeException( 'Demo cleanup verification failed for ' . $table . '.' );
            }
        }
        $organization_id = (int) $state['organization_id'];
        foreach ( ['cpms_patient_identity_links', 'cpms_patient_identities', 'cpms_organization_memberships'] as $table ) {
            $this->execute_cleanup( 'DELETE FROM ' . $this->db->table( $table ) . ' WHERE organization_id = %d', [$organization_id] );
        }
        foreach ( ['otp-day:' . self::PATIENT_MOBILE, 'otp-hour:' . self::PATIENT_MOBILE] as $key ) {
            $this->execute_cleanup( 'DELETE FROM ' . $this->db->table( 'cpms_rate_limits' ) . ' WHERE window_key = %s', [$key] );
        }
    }

    /** @param list<mixed> $params */
    private function execute_cleanup( string $sql, array $params ): void {
        $this->db->execute( $sql, $params );
        $this->assert_cleanup_query();
    }

    private function assert_cleanup_query(): void {
        $error = (string) $this->db->wpdb()->last_error;
        if ( $error !== '' ) {
            throw new RuntimeException( 'Demo cleanup SQL failed: ' . $error );
        }
    }

    /** @param array<string, mixed> $state */
    private function suspend_demo_clinic( array $state ): void {
        if ( ! isset( $state['organization_id'], $state['clinic_id'], $state['users']['organization_manager']['id'] ) ) {
            return;
        }
        $this->assert_demo_tenant( $state );
        App::organization_clinic_provisioning_service()->set_clinic_status(
            (int) $state['users']['organization_manager']['id'],
            (int) $state['organization_id'],
            (int) $state['clinic_id'],
            false
        );
    }

    /** @param array<string, mixed> $state */
    private function destroy_demo_sessions( array $state ): void {
        foreach ( (array) ( $state['users'] ?? [] ) as $entry ) {
            $user_id = is_array( $entry ) ? (int) ( $entry['id'] ?? 0 ) : 0;
            if ( $user_id > 0 ) {
                \WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                clean_user_cache( $user_id );
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function save_state( array $state ): void {
        if ( ! update_option( self::STATE_OPTION, $state, true ) ) {
            $readback = get_option( self::STATE_OPTION, [] );
            if ( ! is_array( $readback ) || $readback !== $state ) {
                throw new RuntimeException( 'Demo state persistence failed.' );
            }
        }
    }

    private function assert_active(): void {
        if ( ! $this->is_active() ) {
            throw new RuntimeException( 'Demo is not active or has expired.' );
        }
    }
}
