<?php
/**
 * Synthetic provisioning for one disposable CPMS demo.
 *
 * All writes go through the production CPMS services, so normal authorization,
 * tenancy, booking and finance gates stay in force. Nothing is written into the
 * default Organization/Clinic/Location; every synthetic object hangs off a new
 * Organization + Clinic whose slug carries the random fixture id.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Creates and seals the demo fixture.
 */
class Provisioner {

	/**
	 * Synthetic mobile. Derived from the fixture id so two demos can never share
	 * an OTP identity, while still matching the production mobile validator.
	 */
	public const MOBILE_PREFIX = '0900';

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
	 * @var Preflight
	 */
	private Preflight $preflight;

	/**
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * @param Env         $env Environment.
	 * @param Platform    $platform CPMS adapter.
	 * @param Manifest    $manifest Manifest store.
	 * @param Preflight   $preflight Preflight.
	 * @param Credentials $credentials Credentials.
	 */
	public function __construct( Env $env, Platform $platform, Manifest $manifest, Preflight $preflight, Credentials $credentials ) {
		$this->env         = $env;
		$this->platform    = $platform;
		$this->manifest    = $manifest;
		$this->preflight   = $preflight;
		$this->credentials = $credentials;
		$this->platform->set_env( $this->env );
	}

	/**
	 * @param string $fixture_id 16 hex chars.
	 * @return string Synthetic mobile for that fixture.
	 */
	public static function mobile_for( string $fixture_id ): string {
		$digits = substr( preg_replace( '/[^0-9]/', '', $fixture_id ) . '0000000', 0, 7 );

		return self::MOBILE_PREFIX . $digits;
	}

	/**
	 * @return array{fixture_id:string,slug:string} New fixture identity.
	 */
	private function new_fixture(): array {
		$fixture = bin2hex( random_bytes( 8 ) );

		return array(
			'fixture_id' => $fixture,
			'slug'       => 'cpdd-' . $fixture,
		);
	}

	/**
	 * Provision the demo.
	 *
	 * @param int $admin_id Acting Administrator (never given CPMS authority).
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 * @throws Exception On any failed check or failed production call.
	 */
	public function provision( int $admin_id ): array {
		return $this->env->with_lock(
			'provision',
			function () use ( $admin_id ): array {
				return $this->do_provision( $admin_id );
			}
		);
	}

	/**
	 * Internal provisioning implementation executed under the concurrency lock.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 * @throws Exception On any failed check or failed production call.
	 */
	private function do_provision( int $admin_id ): array {
		if ( $this->manifest->exists() ) {
			throw new Exception( 'ALREADY_PROVISIONED', 'یک دمو از قبل ثبت شده است؛ برای ساخت دموی تازه ابتدا آن را پاک‌سازی کنید.' );
		}

		$preflight = $this->preflight->run();
		if ( ! $preflight['ok'] ) {
			$reasons = array();
			foreach ( $preflight['checks'] as $check ) {
				if ( 'pass' !== $check['status'] ) {
					$reasons[] = $check['label'] . ': ' . $check['detail'];
				}
			}

			throw new Exception( 'PREFLIGHT', 'پیش‌بررسی امنیتی رد شد — ' . implode( ' | ', array_slice( $reasons, 0, 3 ) ) );
		}

		$fixture     = $this->new_fixture();
		$passwords   = $this->credentials->generate_all();
		$personas    = Credentials::personas();
		$mobile      = self::mobile_for( $fixture['fixture_id'] );
		$stage       = 'start';
		$manifest    = array(
			'fixture_id'     => $fixture['fixture_id'],
			'status'         => 'provisioning',
			'created_at'     => $this->env->now_sql(),
			'admin_id'       => $admin_id,
			'patient_mobile' => $mobile,
			'schema_version' => CPMS_DEMO_SCHEMA_VERSION,
			'cpms_version'   => $this->platform->plugin_version(),
			'ids'            => array(
				'user_ids' => array(),
			),
			'slugs'       => array(
				'organization'       => $fixture['slug'],
				'clinic'             => $fixture['slug'] . '-clinic',
				'location_primary'   => $fixture['slug'] . '-main',
				'location_secondary' => $fixture['slug'] . '-branch',
				'page'               => $fixture['slug'] . '-booking',
			),
			'links'       => array(),
			'counts'      => array(),
		);

		try {
			$stage = 'users';
			$index = 0;
			foreach ( Credentials::PERSONA_ORDER as $persona ) {
				$login = 'cpdd-' . $persona . '-' . $fixture['fixture_id'];
				if ( $this->env->username_exists( $login ) ) {
					throw new Exception( 'USER_EXISTS', 'نام کاربری آزمایشی از قبل وجود دارد؛ سایت خالی نیست.' );
				}
				$email = 'patient' === $persona ? $mobile . '@otp.cpms.local' : $login . '@demo.invalid';
				if ( $this->env->email_exists( $email ) ) {
					throw new Exception( 'EMAIL_EXISTS', 'ایمیل آزمایشی از قبل وجود دارد؛ سایت خالی نیست.' );
				}
				$user_id = $this->env->create_user(
					array(
						'user_login'   => $login,
						'user_pass'    => $passwords[ $persona ],
						'user_email'   => $email,
						'display_name' => 'آزمایشی — ' . $personas[ $persona ]['label'],
						'role'         => $personas[ $persona ]['wp_role'],
					)
				);
				$manifest['ids']['user_ids'][ $persona ] = $user_id;
				$manifest = $this->manifest->save( $manifest );
				++$index;
				if ( 3 === $index ) {
					$this->env->maybe_inject_fault( 'provision:users_partial', array( 'stage' => $stage, 'created_users' => $index ) );
				}
			}
			$this->env->maybe_inject_fault( 'provision:users', array( 'stage' => $stage ) );

			$manager_id = (int) $manifest['ids']['user_ids']['clinic_manager'];
			$doctor     = (int) $manifest['ids']['user_ids']['doctor'];
			$reception  = (int) $manifest['ids']['user_ids']['receptionist'];
			$accountant = (int) $manifest['ids']['user_ids']['accountant'];

			$stage                    = 'organization';
			$organization_id          = $this->platform->create_organization( 'سازمان کاملاً آزمایشی CPMS ' . $fixture['fixture_id'], $fixture['slug'] );
			$manifest['ids']['organization_id'] = $organization_id;
			$manifest                 = $this->manifest->save( $manifest );
			$this->env->maybe_inject_fault( 'provision:organization', array( 'stage' => $stage, 'organization_id' => $organization_id ) );

			$stage = 'onboarding';
			$this->platform->bootstrap_owner( $admin_id, $organization_id, (int) $manifest['ids']['user_ids']['organization_manager'] );
			$this->env->maybe_inject_fault( 'provision:before_clinic', array( 'stage' => $stage, 'organization_id' => $organization_id ) );
			$clinic   = $this->platform->provision_clinic(
				(int) $manifest['ids']['user_ids']['organization_manager'],
				$organization_id,
				'درمانگاه کاملاً آزمایشی CPMS',
				$manifest['slugs']['clinic'],
				'Asia/Tehran',
				'شعبهٔ اصلی آزمایشی',
				'نشانی کاملاً فرضی؛ هیچ نشانی واقعی نیست'
			);
			$clinic_id = (int) $clinic['clinic_id'];
			$location_primary = (int) $clinic['location_id'];
			$manifest['ids']['clinic_id']   = $clinic_id;
			$manifest['ids']['location_ids'] = array( $location_primary );
			$manifest = $this->manifest->save( $manifest );
			$this->env->maybe_inject_fault( 'provision:onboarding', array( 'stage' => $stage, 'clinic_id' => $clinic_id ) );

			$stage = 'memberships';
			$this->platform->designate_clinic_manager( (int) $manifest['ids']['user_ids']['organization_manager'], $organization_id, $clinic_id, $manager_id );
			$roles = $this->platform->role_keys();
			$this->platform->create_membership( $clinic_id, $doctor, $roles['doctor'], $admin_id );
			$this->platform->create_membership( $clinic_id, $reception, $roles['receptionist'], $admin_id );
			$this->platform->create_membership( $clinic_id, $accountant, $roles['accountant'], $admin_id );

			$previous = $this->platform->set_scope( $clinic_id, $location_primary, $organization_id );
			try {
				$stage = 'second-location';
				$location_secondary = $this->platform->create_location(
					$manager_id,
					$clinic_id,
					'شعبهٔ دوم آزمایشی',
					$manifest['slugs']['location_secondary'],
					'Asia/Tehran'
				);
				$manifest['ids']['location_ids'] = array( $location_primary, $location_secondary );
				$manifest = $this->manifest->save( $manifest );

				$stage        = 'clinician';
				$clinician_id = $this->platform->create_clinician( $clinic_id, $doctor, 'دکتر کاملاً آزمایشی CPMS' );
				$manifest['ids']['clinician_id'] = $clinician_id;
				$manifest    = $this->manifest->save( $manifest );
				$this->platform->assign_clinician_locations( $clinician_id, $manifest['ids']['location_ids'], $location_primary );

				$stage = 'schedule';
				$manifest['counts']['schedule_rows'] = $this->platform->create_bounded_schedule( $clinic_id, $clinician_id, $location_primary );

				$stage   = 'patient';
				$patient = $this->platform->create_patient(
					$reception,
					array(
						'first_name' => 'بیمار',
						'last_name'  => 'کاملاً آزمایشی',
						'mobile'     => $mobile,
						'gender'     => 'unknown',
						'birth_date' => null,
					)
				);
				$manifest['ids']['patient_id'] = (int) $patient['id'];
				if ( isset( $patient['identity_id'] ) && (int) $patient['identity_id'] > 0 ) {
					$manifest['ids']['identity_id'] = (int) $patient['identity_id'];
				}
				$manifest = $this->manifest->save( $manifest );

				$stage = 'settings';
				$this->platform->set_setting( $clinic_id, 'sms.provider', 'log' );
				$this->platform->set_setting( $clinic_id, 'booking.min_lead_hours', 0 );

				$stage = 'slots';
				$slots = $this->slot_plan();
				foreach ( $slots as $slot ) {
					$slot_id                = $this->platform->insert_slot( $clinic_id, $location_primary, $clinician_id, $slot['date'], $slot['time'] );
					$manifest['ids']['slot_ids'][] = $slot_id;
				}
				$manifest = $this->manifest->save( $manifest );

				$stage       = 'appointment';
				$first       = $slots[0];
				$appointment = $this->platform->create_staff_appointment(
					$reception,
					(int) $manifest['ids']['patient_id'],
					$clinician_id,
					$first['date'],
					$first['time'],
					'SYNTHETIC-' . $fixture['fixture_id'],
					(int) $manifest['ids']['slot_ids'][0]
				);
				$manifest['ids']['appointment_id'] = $appointment;
				$manifest = $this->manifest->save( $manifest );

				$stage    = 'service';
				$service  = $this->platform->create_service(
					$manager_id,
					array(
						'code'  => 'SYN-' . substr( $fixture['fixture_id'], 0, 6 ),
						'name'  => 'ویزیت آزمایشی — بدون ارزش واقعی',
						'price' => 1000,
					)
				);
				$manifest['ids']['service_id'] = $service;
				$manifest = $this->manifest->save( $manifest );

				$stage = 'clinical';
				$visit = $this->platform->walk_in( $reception, (int) $manifest['ids']['patient_id'], $clinician_id, array( 'note' => 'داده کاملاً فرضی' ) );
				$manifest['ids']['visit_id'] = $visit;
				$manifest                     = $this->manifest->save( $manifest );
				$this->env->maybe_inject_fault( 'provision:clinical', array( 'stage' => $stage, 'visit_id' => $visit ) );
				$this->platform->transition( $doctor, $visit, 'call', array( 'room' => 'SYNTHETIC' ) );
				$this->platform->transition( $doctor, $visit, 'start' );
				$manifest['ids']['note_ids'][] = $this->platform->add_note(
					$doctor,
					$visit,
					array(
						'category'       => 'chief_complaint',
						'visibility'     => 'patient_visible',
						'content_text'   => 'شکایت فرضی صرفاً برای آزمون نرم‌افزار؛ هیچ داده پزشکی واقعی نیست.',
					)
				);
				$manifest['ids']['note_ids'][] = $this->platform->add_note(
					$doctor,
					$visit,
					array(
						'category'     => 'examination',
						'visibility'   => 'patient_visible',
						'content_text' => 'شرح‌حال و معاینه فرضی برای نمایش فرایند.',
					)
				);
				$manifest['ids']['prescription_id'] = $this->platform->create_prescription(
					$doctor,
					$visit,
					array(
						array(
							'generic_name'    => 'SYNTHETIC-NOT-A-DRUG',
							'dose'            => '0 demo-unit',
							'frequency'       => 'test only',
							'form'            => 'tablet',
							'route'           => 'oral',
							'duration_days'   => 1,
							'instructions'    => 'نسخه غیرواقعی؛ هرگز مصرف نشود.',
						),
					)
				);
				$manifest['ids']['follow_up_id'] = $this->platform->add_follow_up(
					$doctor,
					$visit,
					array(
						'is_needed'      => true,
						'interval_days'  => 7,
						'reason'         => 'پیگیری فرضی برای نمایش فرایند.',
					)
				);
				$this->platform->complete_consultation( $doctor, $visit );

				$stage   = 'finance';
				$invoice = $this->platform->issue_invoice(
					$accountant,
					array(
						'visit_id' => $visit,
						'items'    => array(
							array(
								'service_id'  => $service,
								'description' => 'ویزیت آزمایشی',
								'quantity'    => 1,
								'unit_price'  => 1000,
								'discount'    => 0,
							),
						),
						'discount' => 0,
						'tax'      => 0,
					)
				);
				$manifest['ids']['invoice_id'] = $invoice;
				$manifest['ids']['payment_id'] = $this->platform->record_payment(
					$accountant,
					$invoice,
					array(
						'amount'          => 1000,
						'method'          => 'other',
						'transaction_ref' => 'SYNTHETIC-NO-PAYMENT',
					),
					'cpdd-synthetic-payment-' . $fixture['fixture_id']
				);
				$manifest = $this->manifest->save( $manifest );
				$this->platform->transition( $reception, $visit, 'check_out' );
			} finally {
				$this->platform->restore_scope( $previous );
			}

			$stage = 'booking-page';
			$this->env->maybe_inject_fault( 'provision:booking-page', array( 'stage' => $stage, 'clinic_id' => $clinic_id ) );
			$page_id  = $this->env->insert_page(
				array(
					'title'   => 'رزرو آزمایشی CPMS — داده فرضی',
					'slug'    => $manifest['slugs']['page'],
					'content' => $this->platform->booking_shortcode( $clinic_id ),
					'author'  => $admin_id,
				)
			);
			$manifest['ids']['booking_page_id'] = $page_id;
			$manifest['links']                  = array(
				'booking' => $this->env->permalink( $page_id ),
			);
			$manifest['links'] = array_merge( $manifest['links'], $this->platform->portal_links() );
			$manifest          = $this->manifest->save( $manifest );

			$stage              = 'seal';
			$manifest['status'] = 'active';
			$manifest['sealed_at'] = $this->env->now_sql();
			$manifest['shortcode'] = $this->platform->booking_shortcode( $clinic_id );
			$manifest['counts']['future_slots'] = count( $manifest['ids']['slot_ids'] );
			$manifest          = $this->manifest->save( $manifest );

			$this->platform->audit(
				'CPDD_DEMO_PROVISIONED',
				$admin_id,
				$clinic_id,
				array(
					'fixture_id' => $fixture['fixture_id'],
					'accounts'   => 6,
					'sms_provider' => 'log',
					'payment_method' => 'other',
				)
			);

			return array(
				'manifest'    => $manifest,
				'credentials' => $passwords,
			);
		} catch ( \Throwable $error ) {
			$manifest['status']          = 'failed';
			$manifest['failed_stage']    = $stage;
			$manifest['failure_code']    = $error instanceof Exception ? $error->demo_code() : 'PROVISION_FAILED';
			$manifest['failure_message'] = $error->getMessage();
			try {
				$this->manifest->save( $manifest );
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
			if ( $error instanceof Exception ) {
				throw $error;
			}
			throw new Exception( 'PROVISION_FAILED', 'ساخت دمو در مرحلهٔ «' . $stage . '» ناموفق بود: ' . $error->getMessage() );
		}
	}

	/**
	 * Future public-booking slots: one block today (if time remains before
	 * Tehran midnight) plus blocks on the next two days. Deliberately generated
	 * with the same production slot table contract.
	 *
	 * @return list<array{date:string,time:string}>
	 */
	public function slot_plan(): array {
		$tz    = new \DateTimeZone( 'Asia/Tehran' );
		$now   = new \DateTimeImmutable( 'now', $tz );
		$today = $now->format( 'Y-m-d' );
		$plan  = array();

		$start = $now->modify( '+45 minutes' );
		$start = $start->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ) - ( (int) $start->format( 'i' ) % 20 ), 0 );
		foreach ( array( 0, 20, 40 ) as $offset ) {
			$slot = $start->modify( '+' . $offset . ' minutes' );
			if ( $slot->format( 'Y-m-d' ) === $today ) {
				$plan[] = array(
					'date' => $slot->format( 'Y-m-d' ),
					'time' => $slot->format( 'H:i:00' ),
				);
			}
		}
		for ( $day = 1; $day <= 2; $day++ ) {
			foreach ( array( '09:00:00', '09:20:00', '09:40:00' ) as $time ) {
				$plan[] = array(
					'date' => $now->modify( '+' . $day . ' days' )->format( 'Y-m-d' ),
					'time' => $time,
				);
			}
		}

		return $plan;
	}
}
