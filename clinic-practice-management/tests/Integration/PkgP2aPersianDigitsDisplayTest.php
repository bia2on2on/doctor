<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsUi;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Time\PersianDigits;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Package P2A — end-to-end integration verification of Persian display digits
 * across Admin/Portal/Service/REST producers while locking machine fields in Latin.
 */
final class PkgP2aPersianDigitsDisplayTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		App::migrations()->migrate();
		Settings::flushCache();
		App::resetScope();
		ScopeContext::clear();
	}

	protected function tearDown(): void {
		Settings::flushCache();
		ScopeContext::clear();
		App::resetScope();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_ui_messaging_reports_and_finance_use_persian_display_digits(): void {
		self::assertSame(
			'۱۴۰۵/۰۱/۰۱ ۱۳:۳۰',
			CpmsUi::jalali_date_time( '2026-03-21 10:00:00', 'Asia/Tehran' )
		);

		$clinic_id = (int) App::scope()->clinicId;
		App::replaceExplicitScope( ClinicScope::forClinic( $clinic_id ) );

		$secretary_id  = $this->make_user( 'p2a_sec', 'cpms_secretary' );
		$doctor_id     = $this->make_user( 'p2a_doc', 'cpms_doctor' );
		$manager_id    = $this->make_user( 'p2a_mgr', 'cpms_manager' );
		$accountant_id = $this->make_user( 'p2a_acc', 'subscriber' );
		cpms_test_seed_membership( $secretary_id, $clinic_id, 'cpms_secretary' );
		cpms_test_seed_membership( $doctor_id, $clinic_id, 'cpms_doctor' );
		cpms_test_seed_membership( $manager_id, $clinic_id, 'cpms_manager' );
		cpms_test_seed_membership( $accountant_id, $clinic_id, 'cpms_accountant' );

		$location_id = (int) App::db()->fetchValue(
			'SELECT id FROM ' . App::db()->table( 'cpms_locations' )
			. ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1',
			[ $clinic_id ]
		);
		self::assertGreaterThan( 0, $location_id );

		global $wpdb;
		$now = App::db()->nowUtcSql();
		$wpdb->insert(
			$wpdb->prefix . 'cpms_clinicians',
			[
				'clinic_id'  => $clinic_id,
				'full_name'  => 'Dr P2A Clinical',
				'wp_user_id' => $doctor_id,
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%d', '%d', '%s', '%s' ]
		);
		$clinician_id = (int) $wpdb->insert_id;
		App::membership_service()->assign_clinician_locations(
			$clinician_id,
			[ $location_id ],
			$location_id
		);

		$wpdb->insert(
			$wpdb->prefix . 'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => 'MR-P2A-0001',
				'first_name' => 'پویا',
				'last_name'  => 'آزمایشی',
				'mobile'     => '09125550199',
				'birth_date' => '1915-05-10',
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		$patient_id = (int) $wpdb->insert_id;

		// 1) StaffMessagingService::present_time converts digits and keeps " UTC" suffix.
		$msg_service = App::staff_messaging_service();
		$thread      = $msg_service->create_thread(
			$doctor_id,
			[ 'recipient_wp_user_id' => $secretary_id ]
		);
		$sent        = $msg_service->send( $doctor_id, (int) $thread['id'], 'پیام آزمایشی P2A' );
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2} — [۰-۹]{2}:[۰-۹]{2} UTC$/u',
			(string) $sent['jalali_time']
		);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}/',
			(string) $sent['created_at']
		);

		// 2) Visit, print data, ReportService, FinancePortalController, DoctorPortalController.
		App::replaceExplicitScope( ClinicScope::forClinic( $clinic_id, $location_id ) );
		$visit    = App::visitService()->walkIn( $secretary_id, $patient_id, $clinician_id );
		$visit_id = (int) $visit['id'];
		App::visitService()->transition( $doctor_id, $visit_id, 'call' );
		App::visitService()->transition( $doctor_id, $visit_id, 'start' );
		App::clinicalService()->addNote(
			$doctor_id,
			$visit_id,
			[
				'category'     => 'chief_complaint',
				'content_text' => 'گلودرد',
			]
		);
		$rx = App::clinicalService()->createPrescription(
			$doctor_id,
			$visit_id,
			[
				'items' => [
					[
						'generic_name'  => 'آموکسی‌سیلین',
						'dose'          => '500mg',
						'frequency'     => 'هر ۸ ساعت',
						'duration_days' => 7,
					],
				],
			]
		);
		App::clinicalService()->finalizePrescription( $doctor_id, (int) $rx['id'] );
		App::clinicalService()->addFollowUp(
			$doctor_id,
			$visit_id,
			[
				'is_needed'      => true,
				'suggested_date' => '2026-03-21',
				'reason'         => 'پیگیری',
			]
		);
		App::visitService()->transition( $doctor_id, $visit_id, 'complete' );

		$print_data = App::clinicalService()->prescriptionForPrint(
			$doctor_id,
			$visit_id,
			(int) $rx['id']
		);
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u',
			(string) $print_data['visit_jalali']
		);
		self::assertSame( 'MR-P2A-0001', (string) $print_data['patient']['mrn'] );
		self::assertMatchesRegularExpression(
			'/^RX-\d+/',
			(string) $print_data['prescription']['prescription_number']
		);

		// VisitService's display contract (presentVisit) exposes no visit_date key;
		// the operational date is the stored cpms_visits column (same convention as
		// PkgB3VisitNamesTest::persistedVisitDate()).
		$visit_ymd = (string) App::db()->fetchValue(
			'SELECT visit_date FROM ' . App::db()->table( 'cpms_visits' )
			. ' WHERE id = %d AND clinic_id = %d',
			[ $visit_id, $clinic_id ]
		);
		$report    = App::reportService()->run( $manager_id, 'visits', $visit_ymd, $visit_ymd );
		self::assertSame( $visit_ymd, $report['from'] );
		self::assertSame( $visit_ymd, $report['to'] );
		self::assertSame( Jalali::format_display_ymd( $visit_ymd ), $report['from_jalali'] );
		self::assertSame( Jalali::format_display_ymd( $visit_ymd ), $report['to_jalali'] );
		self::assertSame(
			Jalali::format_display_ymd( $visit_ymd ),
			$report['rows'][0]['date_jalali']
		);
		self::assertSame( $visit_ymd, $report['rows'][0]['date'] );
		self::assertSame( 'MR-P2A-0001', $report['rows'][0]['mrn'] );

		// Finance machine fields/IDs stay Latin; reconciliation *_jalali use Persian digits.
		$invoice = App::financeService()->issueInvoice(
			$secretary_id,
			[
				'visit_id' => $visit_id,
				'items'    => [
					[
						'description' => 'ویزیت',
						'quantity'    => 1,
						'unit_price'  => 350000,
					],
				],
			]
		);
		$payment = App::financeService()->recordPayment(
			$secretary_id,
			(int) $invoice['id'],
			[
				'amount' => 350000,
				'method' => 'cash',
			],
			'p2a-idem-' . uniqid( '', false )
		);
		App::visitService()->transition( $secretary_id, $visit_id, 'check_out' );
		self::assertMatchesRegularExpression(
			'/^INV-\d{6}-\d+$/',
			(string) $invoice['invoice_number']
		);
		self::assertMatchesRegularExpression(
			'/^PAY-\d{6}-\d+$/',
			(string) $payment['payment_number']
		);

		ScopeContext::clear();
		App::resetScope();
		wp_set_current_user( $accountant_id );
		$req = new WP_REST_Request( 'GET', '/clinic/v1/staff/portal/finance/reconciliation' );
		$req->set_param( 'from', $visit_ymd );
		$req->set_param( 'to', $visit_ymd );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$req->set_header( 'X-CPMS-Clinic-Id', (string) $clinic_id );
		$req->set_header( 'X-CPMS-Location-Id', (string) $location_id );
		$res = rest_do_request( $req );
		self::assertSame( 200, $res->get_status() );
		$recon = $res->get_data()['data'];
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u',
			(string) $recon['jalali_from']
		);
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u',
			(string) $recon['jalali_to']
		);
		self::assertSame( '350000.00', (string) $recon['totals']['captured_total'] );
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u',
			(string) $recon['payments'][0]['jalali_date']
		);

		// DoctorPortalController medical-history prior_visits uses Persian *_jalali.
		App::replaceExplicitScope( ClinicScope::forClinic( $clinic_id, $location_id ) );
		$second_visit = App::visitService()->walkIn( $secretary_id, $patient_id, $clinician_id );
		$second_id    = (int) $second_visit['id'];
		App::visitService()->transition( $doctor_id, $second_id, 'call' );
		App::visitService()->transition( $doctor_id, $second_id, 'start' );

		ScopeContext::clear();
		App::resetScope();
		wp_set_current_user( $doctor_id );
		$hist_route = '/clinic/v1/doctor/portal/visits/' . $second_id . '/medical-history';
		$hist_req   = new WP_REST_Request( 'GET', $hist_route );
		$hist_req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$hist_req->set_header( 'X-CPMS-Clinic-Id', (string) $clinic_id );
		$hist_req->set_header( 'X-CPMS-Location-Id', (string) $location_id );
		$hist_res = rest_do_request( $hist_req );
		self::assertSame( 200, $hist_res->get_status() );
		$prior = $hist_res->get_data()['data']['prior_visits'][0];
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}$/',
			(string) $prior['visit_date']
		);
		self::assertMatchesRegularExpression(
			'/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u',
			(string) $prior['jalali_date']
		);
		self::assertSame( '۱۴۰۵/۰۱/۰۱', (string) $prior['follow_ups'][0]['suggested_date_jalali'] );
		self::assertSame( '2026-03-21', (string) $prior['follow_ups'][0]['suggested_date'] );
	}

	public function test_patient_portal_uses_persian_display_and_latin_machine_attrs(): void {
		$clinic_id  = (int) App::scope()->clinicId;
		$patient_id = $this->seed_portal_patient( $clinic_id, '09197770111', '1915-05-10' );
		$user_id    = $this->make_user( 'p2a_pat', RolesAndCapabilities::ROLE_PATIENT );

		global $wpdb;
		$now = App::db()->nowUtcSql();
		$wpdb->insert(
			$wpdb->prefix . 'cpms_patient_user_links',
			[
				'clinic_id'      => $clinic_id,
				'patient_id'     => $patient_id,
				'wp_user_id'     => $user_id,
				'mobile_at_link' => '09197770111',
				'is_primary'     => 1,
				'linked_at'      => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ]
		);

		App::notificationService()->publishToPatient(
			$clinic_id,
			$patient_id,
			NotificationEvents::APPT_CONFIRMED,
			[
				'doctor_name'      => 'دکتر آزمون',
				'appointment_date' => Jalali::format_display_ymd( '2026-03-21' ),
				'appointment_time' => PersianDigits::digits( '10:30' ),
			],
			'p2a-notif-1'
		);
		App::notificationService()->publishToPatient(
			$clinic_id,
			$patient_id,
			NotificationEvents::APPT_REMINDER,
			[
				'doctor_name'      => 'دکتر آزمون',
				'appointment_date' => Jalali::format_display_ymd( '2026-03-22' ),
				'appointment_time' => PersianDigits::digits( '11:00' ),
			],
			'p2a-notif-2'
		);

		$wpdb->insert(
			$wpdb->prefix . 'cpms_medical_attachments',
			[
				'clinic_id'              => $clinic_id,
				'patient_id'             => $patient_id,
				'visit_id'               => null,
				'category'               => 'document',
				'original_filename'      => 'lab-p2a.pdf',
				'stored_filename'        => bin2hex( random_bytes( 16 ) ) . '.pdf',
				'mime_type'              => 'application/pdf',
				'file_size'              => 2048,
				'storage_path'           => 'p2a/lab-p2a.pdf',
				'visibility'             => 'patient_visible',
				'uploaded_by_wp_user_id' => $user_id,
				'created_at'             => '2026-03-21 10:00:00',
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ]
		);

		wp_set_current_user( $user_id );
		ob_start();
		try {
			PatientPortalPage::render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		// Visible badge text uses Persian digits; data-unread-count stays Latin.
		self::assertStringContainsString( 'data-unread-count="2"', $html );
		self::assertStringContainsString( '۲ خوانده‌نشده', $html );

		// Notification <time> keeps ISO datetime attribute while visible text uses Persian digits.
		$time_re = '/<time class="description" datetime="\d{4}-\d{2}-\d{2}T[^"]+">'
			. '<span dir="ltr">[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2} [۰-۹]{2}:[۰-۹]{2}<\/span><\/time>/u';
		self::assertMatchesRegularExpression( $time_re, $html );

		// File size label uses Persian digits ("۲ کیلوبایت").
		self::assertStringContainsString( '۲ کیلوبایت', $html );

		// Initial profile config preserves historical ISO birth_date 1915-05-10 untouched.
		self::assertStringContainsString( '"birth_date":"1915-05-10"', $html );
	}

	public function test_notification_time_renders_for_repository_written_database_shape(): void {
		$clinic_id  = (int) App::scope()->clinicId;
		$patient_id = $this->seed_portal_patient( $clinic_id, '09197770222', '1915-05-10' );
		$user_id    = $this->make_user( 'p2a_time', RolesAndCapabilities::ROLE_PATIENT );

		global $wpdb;
		$now = App::db()->nowUtcSql();
		$wpdb->insert(
			$wpdb->prefix . 'cpms_patient_user_links',
			[
				'clinic_id'      => $clinic_id,
				'patient_id'     => $patient_id,
				'wp_user_id'     => $user_id,
				'mobile_at_link' => '09197770222',
				'is_primary'     => 1,
				'linked_at'      => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ]
		);

		// Real write path: NotificationRepository::insert() fills created_at only when the
		// caller omits it, so this row carries CpmsDb::nowUtcSql() — the production shape.
		$repository = new NotificationRepository( App::db() );
		$payload    = (string) json_encode(
			[ 'title' => 'عنوان', 'body' => 'متن' ],
			JSON_UNESCAPED_UNICODE
		);
		$shape_row  = $repository->insert(
			$clinic_id,
			[
				'recipient_patient_id' => $patient_id,
				'channel'              => 'internal',
				'template'             => NotificationEvents::APPT_CONFIRMED,
				'payload_json'         => $payload,
				'status'               => 'queued',
			]
		);
		$fixed_row  = $repository->insert(
			$clinic_id,
			[
				'recipient_patient_id' => $patient_id,
				'channel'              => 'internal',
				'template'             => NotificationEvents::APPT_REMINDER,
				'payload_json'         => $payload,
				'status'               => 'queued',
				'created_at'           => '2026-03-21 10:00:00',
			]
		);
		self::assertGreaterThan( 0, $shape_row );
		self::assertGreaterThan( 0, $fixed_row );

		$stored = (string) App::db()->fetchValue(
			'SELECT created_at FROM ' . App::db()->table( 'cpms_notifications' ) . ' WHERE id = %d',
			[ $shape_row ]
		);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/',
			$stored,
			'fixture: DATETIME(3) read-back carries milliseconds'
		);

		wp_set_current_user( $user_id );
		ob_start();
		try {
			PatientPortalPage::render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		$time_re = '/<time class="description" datetime="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00">'
			. '<span dir="ltr">[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2} [۰-۹]{2}:[۰-۹]{2}<\/span><\/time>/u';
		self::assertSame(
			2,
			preg_match_all( $time_re, $html ),
			'every queued row renders one localized <time>'
		);

		$shape_li = $this->notification_row_markup( $html, $shape_row );
		self::assertMatchesRegularExpression(
			$time_re,
			$shape_li,
			'production DATETIME(3) shape renders a <time>'
		);

		$fixed_li = $this->notification_row_markup( $html, $fixed_row );
		self::assertStringContainsString( 'datetime="2026-03-21T10:00:00+00:00"', $fixed_li );
		self::assertStringContainsString( '۱۴۰۵/۰۱/۰۱ ۱۳:۳۰', $fixed_li );
	}

	public function test_notification_time_parses_plain_values_and_rejects_garbage(): void {
		$method = new \ReflectionMethod( PatientPortalPage::class, 'notification_time' );
		$method->setAccessible( true );
		$tz = new \DateTimeZone( 'Asia/Tehran' );

		$fraction = $method->invoke( null, '2026-03-21 10:00:00.000', $tz );
		$plain    = $method->invoke( null, '2026-03-21 10:00:00', $tz );

		self::assertIsString( $fraction );
		self::assertSame(
			$plain,
			$fraction,
			'millisecond precision must not change the label'
		);
		self::assertStringContainsString( 'datetime="2026-03-21T10:00:00+00:00"', (string) $fraction );
		self::assertStringContainsString( '۱۴۰۵/۰۱/۰۱ ۱۳:۳۰', (string) $fraction );
		self::assertSame(
			'',
			$method->invoke( null, 'not-a-date', $tz ),
			'fail-closed: junk renders no <time>'
		);
	}

	private function notification_row_markup( string $html, int $notification_id ): string {
		$needle = 'data-notification-id="' . $notification_id . '"';
		$start  = strpos( $html, $needle );
		self::assertNotFalse( $start, 'notification row must render: ' . $needle );
		$end = strpos( $html, '</li>', (int) $start );
		self::assertNotFalse( $end, 'notification row must be closed' );

		return substr( $html, (int) $start, (int) $end - (int) $start );
	}

	private function make_user( string $prefix, string $role ): int {
		$uid  = (int) wp_create_user(
			$prefix . '_' . bin2hex( random_bytes( 3 ) ),
			'pass-123456',
			$prefix . '_' . bin2hex( random_bytes( 3 ) ) . '@test.local'
		);
		$user = get_userdata( $uid );
		self::assertNotFalse( $user );
		$user->set_role( $role );

		return $uid;
	}

	private function seed_portal_patient(
		int $clinic_id,
		string $mobile,
		string $birth_date
	): int {
		global $wpdb;
		$now = App::db()->nowUtcSql();
		$wpdb->insert(
			$wpdb->prefix . 'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => 'MR-P2A-' . strtoupper( bin2hex( random_bytes( 4 ) ) ),
				'first_name' => 'بیمار',
				'last_name'  => 'پورتال',
				'mobile'     => $mobile,
				'birth_date' => $birth_date,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}
}
