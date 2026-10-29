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
	 * Consultation-fee values used by the synthetic pricing fixture.
	 *
	 * They are deliberately distinct, ordered and non-round: a snapshot that was
	 * silently replaced by a later default is then arithmetically impossible to
	 * mistake for the correct value.
	 */
	public const PRICING_DEFAULT_FEE = 500000;

	public const PRICING_CHANGED_FEE = 750000;

	public const PRICING_OVERRIDE_FEE = 620000;

	public const PRICING_CHARGE_UNIT = 200000;

	public const PRICING_CHARGE_QUANTITY = 2.0;

	/**
	 * @var Env
	 */
	protected Env $env;

	/**
	 * @var Platform
	 */
	protected Platform $platform;

	/**
	 * @var Manifest
	 */
	protected Manifest $manifest;

	/**
	 * @var Preflight
	 */
	protected Preflight $preflight;

	/**
	 * @var Credentials
	 */
	protected Credentials $credentials;

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
	protected function new_fixture(): array {
		$fixture = $this->manifest->fixture_id();
		$prefix  = $this->manifest->fixture_prefix();

		return array(
			'fixture_id' => $fixture,
			'slug'       => $prefix . $fixture,
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

		$this->before_provision( $admin_id );

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
				$login = $this->manifest->fixture_prefix() . $persona . '-' . $fixture['fixture_id'];
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
			// Iran V1: a SECOND doctor and a SECOND receptionist are ordinary
			// active members of the same Clinic. Without them, "the colleague who
			// is not in the thread cannot see it" would only be provable against
			// users who have no authority at all, which is not the property the
			// private-messaging contract claims.
			$this->platform->create_membership( $clinic_id, (int) $manifest['ids']['user_ids']['doctor_second'], $roles['doctor'], $admin_id );
			$this->platform->create_membership( $clinic_id, (int) $manifest['ids']['user_ids']['receptionist_second'], $roles['receptionist'], $admin_id );

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

				$clinician_second_id = $this->platform->create_clinician( $clinic_id, (int) $manifest['ids']['user_ids']['doctor_second'], 'دکتر دوم کاملاً آزمایشی CPMS' );
				$manifest['ids']['clinician_id_second'] = $clinician_second_id;
				$manifest = $this->manifest->save( $manifest );
				$this->platform->assign_clinician_locations( $clinician_second_id, $manifest['ids']['location_ids'], $location_primary );

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
					$this->manifest->fixture_prefix() . 'synthetic-payment-' . $fixture['fixture_id']
				);
				$manifest = $this->manifest->save( $manifest );
				$this->platform->transition( $reception, $visit, 'check_out' );

				// Iran V1 (schema 0030) — private staff messaging. Three real
				// two-person threads are created through StaffMessagingService, so
				// every participant row is written by the production service under
				// its own membership/capability re-check.
				$stage    = 'messaging';
				$manifest = $this->seed_messaging_fixture( $manifest, $doctor, $reception, $accountant );
				$manifest = $this->manifest->save( $manifest );
				$this->env->maybe_inject_fault( 'provision:messaging', array( 'stage' => $stage ) );

				// Iran V1 (schema 0030) — doctor-managed consultation pricing.
				$stage    = 'pricing';
				$manifest = $this->seed_pricing_fixture(
					$manifest,
					(int) $manifest['ids']['user_ids']['doctor_second'],
					$reception,
					(int) $manifest['ids']['patient_id'],
					(int) $manifest['ids']['clinician_id_second']
				);
				$manifest = $this->manifest->save( $manifest );
				$this->env->maybe_inject_fault( 'provision:pricing', array( 'stage' => $stage ) );
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
				$this->manifest->audit_action(),
				$admin_id,
				$clinic_id,
				array(
					'fixture_id' => $fixture['fixture_id'],
					'accounts'   => count( Credentials::PERSONA_ORDER ),
					'sms_provider' => 'log',
					'payment_method' => 'other',
				)
			);

			return $this->after_provision(
				array(
					'manifest'    => $manifest,
					'credentials' => $passwords,
				),
				$admin_id
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
	 * Extension hook executed inside the provisioning lock before any write.
	 * Strict mode deliberately does nothing.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return void
	 */
	protected function before_provision( int $admin_id ): void {
		unset( $admin_id );
	}

	/**
	 * Extension hook executed inside the provisioning lock after the manifest is sealed.
	 *
	 * @param array{manifest:array<string,mixed>,credentials:array<string,string>} $result Result.
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 */
	protected function after_provision( array $result, int $admin_id ): array {
		unset( $admin_id );

		return $result;
	}

	/**
	 * Three strictly-private two-person staff threads, written through the
	 * production StaffMessagingService.
	 *
	 * The pair choices are the ones the demo must be able to disprove:
	 *   doctor <-> receptionist        (one of TWO receptionists)
	 *   doctor <-> finance/accountant
	 *   receptionist <-> finance/accountant
	 *
	 * The second doctor and second receptionist are deliberately NOT participants
	 * of any thread, so a later privacy assertion is a real pairwise check.
	 *
	 * @param array<string,mixed> $manifest Manifest under construction.
	 * @param int                 $doctor Doctor WordPress user.
	 * @param int                 $reception Receptionist WordPress user.
	 * @param int                 $accountant Finance/cashier WordPress user.
	 * @return array<string,mixed> Updated manifest.
	 */
	protected function seed_messaging_fixture( array $manifest, int $doctor, int $reception, int $accountant ): array {
		$bodies = array(
			'doctor_receptionist' => array(
				$doctor      => 'همکار پذیرش، لطفاً نمونهٔ آزمایشی بعدی را آماده کنید؛ داده فرضی است.',
				$reception   => 'دریافت شد؛ پروندهٔ آزمایشی آماده است و در نوبت قرار گرفت.',
			),
			'doctor_finance'      => array(
				$doctor      => 'همکار مالی، فاکتور آزمایشی ویزیت امروز را بررسی می‌کنید؟',
				$accountant  => 'بله؛ فاکتور آزمایشی ثبت شد و مبلغ فرضی دریافت شد.',
			),
			'receptionist_finance' => array(
				$reception   => 'همکار مالی، پرداخت آزمایشی پذیرش ثبت شد.',
				$accountant  => 'تأیید شد؛ تراز مالی آزمایشی به‌روزرسانی شد.',
			),
		);

		$manifest['ids']['message_thread_ids'] = array();
		$manifest['ids']['message_ids']        = array();

		foreach ( $bodies as $key => $conversation ) {
			$participants = array_keys( $conversation );
			$thread_id    = $this->platform->message_thread( (int) $participants[0], (int) $participants[1] );
			$manifest['ids']['message_thread_ids'][ $key ] = $thread_id;
			foreach ( $conversation as $sender => $body ) {
				$manifest['ids']['message_ids'][] = $this->platform->send_message( (int) $sender, (int) $thread_id, (string) $body );
			}
		}

		$manifest['messaging'] = array(
			'thread_keys'              => array_keys( $bodies ),
			'second_doctor_id'         => (int) $manifest['ids']['user_ids']['doctor_second'],
			'second_receptionist_id'   => (int) $manifest['ids']['user_ids']['receptionist_second'],
			'private_thread'           => (int) $manifest['ids']['message_thread_ids']['doctor_receptionist'],
		);

		return $manifest;
	}

	/**
	 * Doctor-managed consultation pricing fixture.
	 *
	 * Proves the fee-snapshot rule at its source: the doctor sets a default, a
	 * NEW visit inherits it at walk-in, and a LATER change of that default does
	 * not reprice the already-established visit. The per-visit override, the
	 * itemized charges, the invoice and the payments are left to the browser
	 * journey so they are exercised through the real UI.
	 *
	 * @param array<string,mixed> $manifest Manifest under construction.
	 * @param int                 $doctor Doctor (second) WordPress user.
	 * @param int                 $reception Receptionist WordPress user.
	 * @param int                 $patient Patient id.
	 * @param int                 $clinician_id Clinician owning the visit.
	 * @return array<string,mixed> Updated manifest.
	 * @throws Exception When the snapshot rule is violated by production.
	 */
	protected function seed_pricing_fixture( array $manifest, int $doctor, int $reception, int $patient, int $clinician_id ): array {
		$this->platform->set_default_consultation_fee( $doctor, null, self::PRICING_DEFAULT_FEE );

		$visit = $this->platform->walk_in( $reception, $patient, $clinician_id, array( 'note' => 'ویزیت تعرفهٔ آزمایشی — داده فرضی' ) );
		$manifest['ids']['visit_id_pricing'] = $visit;

		$this->platform->transition( $doctor, $visit, 'call', array( 'room' => 'SYNTHETIC' ) );
		$this->platform->transition( $doctor, $visit, 'start' );
		// Production refuses to complete a consultation without a chief-complaint
		// note, exactly like the main fixture: the note is synthetic text and is
		// owned by the same Clinic, so teardown covers it.
		$manifest['ids']['pricing_note_id'] = $this->platform->add_note(
			$doctor,
			$visit,
			array(
				'category'     => 'chief_complaint',
				'visibility'   => 'patient_visible',
				'content_text' => 'شکایت اصلی آزمایشی برای ویزیت تعرفه — داده کاملاً فرضی.',
			)
		);
		$this->platform->complete_consultation( $doctor, $visit );

		// A later default change must never reprice the established Visit.
		$this->platform->set_default_consultation_fee( $doctor, null, self::PRICING_CHANGED_FEE );

		$billing      = $this->platform->visit_billing( $doctor, $visit );
		$snapshot_fee = null === ( $billing['snapshot_fee'] ?? null ) ? 0 : (int) $billing['snapshot_fee'];
		if ( self::PRICING_DEFAULT_FEE !== $snapshot_fee ) {
			throw new Exception(
				'FEE_SNAPSHOT_MISMATCH',
				'تعرفهٔ ثبت‌شده هنگام پذیرش با تعرفهٔ پیش‌فرض پزشک هم‌خوان نیست؛ ساخت دمو متوقف شد.'
			);
		}
		// No per-visit override exists yet, so the effective fee must still be the
		// check-in snapshot and NOT the later default. The default itself is
		// expected to have changed: that is what the fixture demonstrates.
		if ( self::PRICING_DEFAULT_FEE !== (int) ( $billing['effective_fee'] ?? 0 ) ) {
			throw new Exception( 'FEE_SNAPSHOT_REPRICED', 'ویزیت ثبت‌شده با تعرفهٔ جدید بازقیمت‌گذاری شد؛ ساخت دمو متوقف شد.' );
		}
		if ( 'checkin_snapshot' !== (string) ( $billing['fee_source'] ?? '' ) ) {
			throw new Exception( 'FEE_SNAPSHOT_SOURCE', 'منبع تعرفهٔ ویزیت باید snapshot پذیرش باشد؛ ساخت دمو متوقف شد.' );
		}

		$manifest['pricing'] = array(
			'default_fee'         => self::PRICING_DEFAULT_FEE,
			'changed_default_fee' => self::PRICING_CHANGED_FEE,
			'override_fee'        => self::PRICING_OVERRIDE_FEE,
			'charge_unit'         => self::PRICING_CHARGE_UNIT,
			'charge_quantity'     => self::PRICING_CHARGE_QUANTITY,
			'snapshot_fee'        => $snapshot_fee,
			'snapshot_source'     => (string) ( $billing['fee_source'] ?? '' ),
			'clinician_id'        => $clinician_id,
			'expected_total'      => self::PRICING_OVERRIDE_FEE + (int) round( self::PRICING_CHARGE_UNIT * self::PRICING_CHARGE_QUANTITY ),
		);

		return $manifest;
	}

	/**
	 * Future public-booking slots: one later-day 09:00 row reserved for the
	 * synthetic staff appointment, one same-day public block, then the remaining
	 * later-day rows. The plan never has fewer than six entries, so the "open
	 * future slots for a NEW public booking" contract cannot be starved by the
	 * wall clock, and the same-day block stays genuinely available for the public
	 * journey instead of being consumed by the staff appointment. Deliberately
	 * generated with the same production slot table contract.
	 *
	 * @return list<array{date:string,time:string}>
	 */
	public function slot_plan(): array {
		// The returned order is fixture ROLE order, not chronological, and that is
		// deliberate. do_provision() books $slots[0] for the synthetic staff
		// appointment, while the browser journey books the earliest still-available
		// slot and then checks that appointment in on the Staff Portal reception
		// board - which is strictly the Location's local "today"
		// (ReceptionPortalController::board -> VisitService::today plus today's
		// booked rows). Reserving a later-day slot for the staff appointment keeps
		// the same-day public offer genuinely available instead of consuming it.
		//
		// The public block is anchored five minutes ahead on a whole-minute grid and
		// every entry carries its OWN local date, so the block can never collapse as
		// local midnight approaches. The previous plan anchored 45 minutes ahead and
		// truncated DOWN to a 20-minute boundary, which silently dropped today's
		// entries one by one (3 -> 2 -> 1 -> 0) and made this lane fail on the wall
		// clock alone every day between ~22:55 and ~24:00 Asia/Tehran: with exactly
		// one same-day slot the staff appointment consumed it and the browser timed
		// out waiting for a same-day arrival row, and with none the "open future
		// slots" bound failed. No date is hardcoded here and no production
		// scheduling rule is bypassed - every entry is still a real strictly-future
		// cpms_schedule_slots row with the normal 20-minute duration and capacity 1,
		// persisted through Platform::insert_slot() and booked through the ordinary
		// production booking service.
		$tz  = new \DateTimeZone( 'Asia/Tehran' );
		$now = new \DateTimeImmutable( 'now', $tz );

		$upcoming = array();
		for ( $day = 1; $day <= 2; $day++ ) {
			foreach ( array( '09:00:00', '09:20:00', '09:40:00' ) as $time ) {
				$upcoming[] = array(
					'date' => $now->modify( '+' . $day . ' days' )->format( 'Y-m-d' ),
					'time' => $time,
				);
			}
		}

		$anchor   = $now->modify( '+5 minutes' );
		$anchor   = $anchor->setTime( (int) $anchor->format( 'H' ), (int) $anchor->format( 'i' ), 0 );
		$journey  = $anchor->format( 'Y-m-d' );
		$public   = array();
		foreach ( array( 0, 20, 40 ) as $offset ) {
			$slot = $anchor->modify( '+' . $offset . ' minutes' );
			if ( $slot->format( 'Y-m-d' ) === $journey ) {
				$public[] = array(
					'date' => $slot->format( 'Y-m-d' ),
					'time' => $slot->format( 'H:i:00' ),
				);
			}
		}

		// array_shift() cannot return null here: the loop above always fills six
		// entries. The reserved slot is a later-day 09:00 row, so it can never
		// collide with the anchored public block (which starts at most a few
		// minutes ahead of "now").
		$plan = array( array_shift( $upcoming ) );

		return array_merge( $plan, $public, $upcoming );
	}
}
