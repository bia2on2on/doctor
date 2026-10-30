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
		$result = $this->env->with_lock(
			'provision',
			function () use ( $admin_id ): array {
				return $this->do_provision( $admin_id );
			}
		);
		// Non-secret completion signal, emitted AFTER the provisioning lock is
		// released. Integrations (including CI-only credential capture) may rotate
		// the one-time passwords here without re-entering the lock. No password,
		// OTP, session or nonce is ever passed to observers.
		do_action( 'cpms_demo_provisioned', $result['manifest'] );

		return $result;
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
				$manifest['counts']['schedule_rows'] = $this->platform->create_bounded_schedule( $clinic_id, $clinician_id, $location_primary, '09:00:00' );
				$manifest['counts']['schedule_rows'] += $this->platform->create_bounded_schedule( $clinic_id, $clinician_second_id, $location_primary, '14:00:00' );

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
					$slot_clinician = 'doctor_second' === ( $slot['doctor'] ?? '' ) ? $clinician_second_id : $clinician_id;
					$slot_id                = $this->platform->insert_slot( $clinic_id, $location_primary, $slot_clinician, $slot['date'], $slot['time'] );
					$manifest['ids']['slot_ids'][] = $slot_id;
				}
				// The reception-arrival drill needs an appointment on the Location's
				// operational day, while public booking must offer realistic future
				// clinic hours. The reservation therefore follows the clock on the
				// demo's own 20-minute grid (see staff_arrival_at()) instead of a
				// hard-coded 09:00, which the production BookingWindow refuses for the
				// rest of the day once 09:00 has passed — that made the demo
				// unprovisionable for every install between 09:00 and midnight local.
				$staff_arrival = $this->staff_arrival();
				$local_today   = (string) $staff_arrival['date'];
				$staff_slot_id = $this->platform->insert_slot( $clinic_id, $location_primary, $clinician_id, $local_today, (string) $staff_arrival['time'] );
				$manifest['ids']['slot_ids'][]      = $staff_slot_id;
				$manifest['ids']['staff_slot_id']   = $staff_slot_id;
				$manifest['ids']['staff_slot_date'] = $local_today;
				$manifest = $this->manifest->save( $manifest );

				$stage       = 'appointment';
				$appointment = $this->platform->create_staff_appointment(
					$reception,
					(int) $manifest['ids']['patient_id'],
					$clinician_id,
					$local_today,
					(string) $staff_arrival['time'],
					'SYNTHETIC-' . $fixture['fixture_id'],
					$staff_slot_id
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
			$this->record_failure( $manifest, $stage, $error );
			if ( $error instanceof Exception ) {
				throw $error;
			}
			throw new Exception( 'PROVISION_FAILED', 'ساخت دمو در مرحلهٔ «' . $stage . '» ناموفق بود: ' . $error->getMessage() );
		}
	}

	/**
	 * Record a failed run WITHOUT destroying the ownership evidence that is
	 * already durable.
	 *
	 * WHY THIS IS NOT A THREE-LINE ASSIGNMENT
	 * ---------------------------------------
	 * The local `$manifest` array is a snapshot taken at the LAST successful
	 * write of that stage. The record on disk can legitimately be *ahead* of it,
	 * because the stages that finish the fixture (the seal) write their own
	 * payload and only then hand control back:
	 *
	 *   1. `after_provision()` (scoped: `seal_fixture()`) saves a manifest that
	 *      carries the sealed pre-provisioning `baseline`, `host_before`, the
	 *      `owned` scope map and `audit_chain_at_seal`;
	 *   2. only after that write reads back verified does it drop the sealed
	 *      pending-baseline copy (the same evidence, kept twice on purpose);
	 *   3. an exception thrown anywhere after (1) — for example by the portal
	 *      policy read-back — is caught here.
	 *
	 * Saving the stale local copy over the durable record in that situation
	 * ERASES the baseline and the ownership map while keeping the eight
	 * synthetic accounts, which is the worst possible state: the demo exists,
	 * but neither "finish it" nor "prove it unchanged, then remove it" can be
	 * proven any more. That is exactly the observed Owner incident
	 * (manifest `failed`, teardown `BASELINE_MISSING`, preflight blocked by the
	 * incomplete demo, eight `cpsd-…` accounts alive).
	 *
	 * So the failure record is written from the DURABLE payload when one exists:
	 * identity and evidence fields are kept, and only the failure metadata is
	 * added. A manifest that does not verify is never overwritten — that would
	 * destroy the evidence a human needs to interpret the tamper.
	 *
	 * @param array<string,mixed> $local Manifest snapshot of the failing stage.
	 * @param string              $stage Stage name.
	 * @param \Throwable          $error Failure.
	 * @return void
	 */
	protected function record_failure( array $local, string $stage, \Throwable $error ): void {
		$code    = $error instanceof Exception ? $error->demo_code() : 'PROVISION_FAILED';
		$now     = $this->env->now_sql();
		$failure = array(
			'stage' => $stage,
			'code'  => $code,
			'at'    => $now,
		);

		$durable = null;
		try {
			$durable = $this->manifest->load();
		} catch ( \Throwable $refused ) {
			// MANIFEST_TAMPERED / MANIFEST_SITE_MISMATCH / MANIFEST_FORMAT: the
			// record on disk is evidence, not something to overwrite. Leave it
			// exactly as it is and let the refusal surface to the operator.
			unset( $refused );

			return;
		}

		$payload = is_array( $durable ) ? $this->preserve_evidence( $local, $durable ) : $local;
		$payload['status']          = 'failed';
		$payload['failed_stage']    = $stage;
		$payload['failure_code']    = $code;
		$payload['failure_message'] = $error->getMessage();
		// Append-only, bounded history: a second failure must not hide the first
		// one, and the sealed evidence a repair path needs must survive both.
		$history   = (array) ( $payload['failure_history'] ?? array() );
		$history[] = $failure;
		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}
		$payload['failure_history'] = $history;

		try {
			$this->manifest->save( $payload );
		} catch ( \Throwable $ignored ) {
			// A failure path never throws a second error over the original one.
			unset( $ignored );
		}
	}

	/**
	 * Merge the durable record's ownership evidence into a stale local snapshot.
	 *
	 * Only fields that prove what the fixture owns (and what existed before it)
	 * are taken from the durable record; the local snapshot keeps its own
	 * progress fields because they are a superset of what was persisted for the
	 * current stage.
	 *
	 * @param array<string,mixed> $local   Local snapshot.
	 * @param array<string,mixed> $durable Verified record on disk.
	 * @return array<string,mixed>
	 */
	protected function preserve_evidence( array $local, array $durable ): array {
		// Evidence fields: the durable value wins whenever it carries something.
		foreach ( array( 'baseline', 'host_before', 'owned', 'audit_chain_at_seal', 'sealed_at', 'shortcode', 'activation' ) as $key ) {
			$value = $durable[ $key ] ?? null;
			if ( null !== $value && array() !== $value && '' !== $value ) {
				$local[ $key ] = $value;
			}
		}
		// Progress fields the durable record may carry beyond the local snapshot:
		// never shrink an id list that was already persisted.
		if ( isset( $durable['ids'] ) && is_array( $durable['ids'] ) ) {
			$local_ids = isset( $local['ids'] ) && is_array( $local['ids'] ) ? $local['ids'] : array();
			foreach ( $durable['ids'] as $key => $value ) {
				if ( ! array_key_exists( $key, $local_ids ) || array() === $local_ids[ $key ] || 0 === $local_ids[ $key ] ) {
					$local_ids[ $key ] = $value;
					continue;
				}
				if ( is_array( $value ) && is_array( $local_ids[ $key ] ) ) {
					// Two shapes live in `ids`: ordered lists (`slot_ids`,
					// `location_ids`) and persona maps (`user_ids`, keyed by persona,
					// which every consumer reads by name). Re-indexing the map would
					// destroy the persona keys, so only a list is de-duplicated and
					// re-indexed.
					$local_ids[ $key ] = self::is_id_list( $local_ids[ $key ] ) && self::is_id_list( $value )
						? array_values( array_unique( array_merge( $local_ids[ $key ], $value ) ) )
						: array_merge( $local_ids[ $key ], $value );
				}
			}
			$local['ids'] = $local_ids;
		}
		foreach ( array( 'counts', 'links', 'slugs', 'messaging', 'pricing' ) as $key ) {
			if ( isset( $durable[ $key ] ) && is_array( $durable[ $key ] ) ) {
				$local[ $key ] = array_merge( (array) ( $local[ $key ] ?? array() ), $durable[ $key ] );
			}
		}

		return $local;
	}

	/**
	 * Whether an `ids` value is an ordered list (as opposed to a map keyed by
	 * persona or by table name).
	 *
	 * `array_is_list()` is PHP 8.1+; this package supports older runtimes, so the
	 * same property is expressed directly.
	 *
	 * @param array<mixed> $value Value.
	 * @return bool
	 */
	protected static function is_id_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
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
	 * The reserved reception-arrival instant, computed from the real clock.
	 *
	 * @return array{date:string,time:string}
	 */
	public function staff_arrival(): array {
		return self::staff_arrival_at( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Tehran' ) ) );
	}

	/**
	 * First slot on the demo's 20-minute grid that the production BookingWindow
	 * will still accept, expressed in the timezone of `$now`.
	 *
	 * The rule (pure, so it is testable at any hour):
	 *
	 *   1. never before the clinic's 09:00 opening;
	 *   2. never earlier than `now + 2 minutes`, which keeps a booking that is
	 *      created a moment later inside `minLeadHours = 0` instead of on its
	 *      boundary;
	 *   3. snapped UP to the next :00 / :20 / :40, never down — a floored time
	 *      would be in the past and refused;
	 *   4. if that instant would fall on the next calendar day (only possible
	 *      in the last 20 minutes of the day), the arrival becomes 23:59 of the
	 *      SAME day — the reception board only ever shows one operational day,
	 *      so an arrival on "tomorrow" would not be visible when the drill looks
	 *      for it. In the final minute of the day there is no future instant
	 *      left, and the arrival becomes 09:00 of the next operational day.
	 *
	 * Nothing here loosens a production rule: the resulting slot is a normal
	 * open slot that `BookingService::createByStaff()` validates like any other.
	 *
	 * @param \DateTimeImmutable $now Current instant in the Location timezone.
	 * @return array{date:string,time:string}
	 */
	public static function staff_arrival_at( \DateTimeImmutable $now ): array {
		$opening  = $now->setTime( 9, 0, 0 );
		$earliest = $now->modify( '+2 minutes' );
		$base     = $earliest > $opening ? $earliest : $opening;
		$minute   = (int) $base->format( 'i' );
		$step     = ( 20 - ( $minute % 20 ) ) % 20;
		$arrival  = $base->setTime( (int) $base->format( 'H' ), $minute, 0 )->modify( '+' . $step . ' minutes' );
		if ( $arrival->format( 'Y-m-d' ) !== $now->format( 'Y-m-d' ) ) {
			$last_minute = $now->setTime( 23, 59, 0 );
			$arrival     = $now < $last_minute ? $last_minute : $now->modify( '+1 day' )->setTime( 9, 0, 0 );
		}

		return array(
			'date' => $arrival->format( 'Y-m-d' ),
			'time' => $arrival->format( 'H:i:s' ),
		);
	}

	/**
	 * Deterministic, realistic Iranian clinic hours for BOTH synthetic doctors.
	 *
	 * Public slots are on the next two LOCAL calendar days (Asia/Tehran), never
	 * anchored to "now + five minutes", so the Owner never sees artefacts such as
	 * 23:47. Doctor one works mornings (09:00–09:40), doctor two afternoons
	 * (14:00–14:40). These rows are open to the real public booking flow. The
	 * synthetic staff-arrival appointment uses a separate reserved 09:00 slot on
	 * the LOCAL today, so reception check-in remains provable on the board's local
	 * day without publishing past or wall-clock-derived public times. Local dates
	 * are carried per row and DST is handled by DateTimeImmutable in the Location
	 * timezone, not by UTC arithmetic or browser clocks.
	 *
	 * @return list<array{date:string,time:string,doctor:string}>
	 */
	public function slot_plan(): array {
		$tz   = new \DateTimeZone( 'Asia/Tehran' );
		$now  = new \DateTimeImmutable( 'now', $tz );
		$plan = array();
		for ( $day = 1; $day <= 2; $day++ ) {
			$date = $now->modify( '+' . $day . ' days' )->format( 'Y-m-d' );
			foreach ( array( '09:00:00', '09:20:00', '09:40:00' ) as $time ) {
				$plan[] = array( 'date' => $date, 'time' => $time, 'doctor' => 'doctor' );
			}
			foreach ( array( '14:00:00', '14:20:00', '14:40:00' ) as $time ) {
				$plan[] = array( 'date' => $date, 'time' => $time, 'doctor' => 'doctor_second' );
			}
		}

		return $plan;
	}
}
