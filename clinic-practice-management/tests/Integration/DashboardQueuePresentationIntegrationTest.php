<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use WP_UnitTestCase;

/** Real WordPress config and Clinic-scoped queue notification presentation. */
final class DashboardQueuePresentationIntegrationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		App::resetScope();
		App::migrations()->migrate();
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		App::resetScope();
		parent::tearDown();
	}

	/** @param array<string, mixed> $data Database fixture. */
	private function insert_row( string $table, array $data ): int {
		global $wpdb;
		$result = $wpdb->insert( App::db()->table( $table ), $data );
		self::assertSame( 1, $result, $table . ': ' . $wpdb->last_error );
		$id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $id );

		return $id;
	}

	public function test_doctor_config_carries_one_title_or_generic_fallback(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user    = get_userdata( $user_id );
		self::assertNotFalse( $user );
		$user->add_cap( RolesAndCapabilities::QUEUE_READ );
		wp_set_current_user( $user_id );
		self::assertTrue( current_user_can( RolesAndCapabilities::QUEUE_READ ) );

		$now          = App::db()->nowUtcSql();
		$clinician_id = $this->insert_row(
			'cpms_clinicians',
			[
				'clinic_id'  => 1,
				'wp_user_id' => $user_id,
				'full_name'  => 'رضایی',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$names = [
			'رضایی'             => 'دکتر رضایی',
			'Dr. رضایی'         => 'دکتر رضایی',
			'دکتر دکتر رضایی'  => 'دکتر رضایی',
			"\u{00A0}"          => 'پزشک',
		];
		foreach ( $names as $stored => $expected ) {
			$updated = $wpdb->update(
				App::db()->table( 'cpms_clinicians' ),
				[ 'full_name' => $stored ],
				[ 'id' => $clinician_id ]
			);
			self::assertNotFalse( $updated );
			$old_get = $_GET;
			$_GET    = [];
			ob_start();
			try {
				DoctorDashboardPage::render();
				$html = (string) ob_get_contents();
			} finally {
				ob_end_clean();
				$_GET = $old_get;
			}
			$found = preg_match( '/window\.CPMS_DOC\s*=\s*(\{[^\n]+\});/', $html, $matches );
			self::assertSame( 1, $found );
			$config = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( $expected, $config['clinician_name'] );
			self::assertSame( $clinician_id, $config['clinician_id'] );
			$still_stored = App::db()->fetchValue(
				'SELECT full_name FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE id = %d',
				[ $clinician_id ]
			);
			self::assertSame( $stored, $still_stored );
		}
	}

	/** @param array<string, int> $query Read-only admin selectors. */
	private function render_clinician_page( array $query ): string {
		$old_get = $_GET;
		$_GET    = $query;
		ob_start();
		try {
			ClinicianAdminPage::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = $old_get;
		}
	}

	public function test_clinician_schedule_display_does_not_change_stored_values(): void {
		$manager = self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_MANAGER ] );
		cpms_test_seed_membership( $manager, 1, RolesAndCapabilities::ROLE_MANAGER );
		wp_set_current_user( $manager );
		self::assertTrue( current_user_can( RolesAndCapabilities::CONFIG ) );
		$now       = App::db()->nowUtcSql();
		$clinician = $this->insert_row(
			'cpms_clinicians',
			[
				'clinic_id'  => 1,
				'full_name'  => 'Schedule fixture',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$list = $this->render_clinician_page( [ 'clinic_id' => 1 ] );
		self::assertMatchesRegularExpression(
			'/<td data-label="روزهای برنامه">\s*۰ روز\s*<\/td>/u',
			$list
		);

		$date         = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$exception_id = $this->insert_row(
			'cpms_schedule_exceptions',
			[
				'clinic_id'             => 1,
				'clinician_id'          => $clinician,
				'date'                  => $date,
				'type'                  => 'blocked',
				'start_time'            => '09:15:00',
				'end_time'              => '12:30:00',
				'created_by_wp_user_id' => $manager,
				'created_at'            => $now,
			]
		);
		$html = $this->render_clinician_page( [ 'clinic_id' => 1, 'clinician_id' => $clinician ] );
		$heading = strpos( $html, '<h2 class="title" style="margin-top:18px">استثناها' );
		self::assertNotFalse( $heading );
		$create = strpos( $html, 'value="cpms_exception_create"', $heading );
		self::assertNotFalse( $create );
		$listing = substr( $html, $heading, $create - $heading );
		self::assertStringContainsString( Jalali::format_display_ymd( $date ), $listing );
		self::assertStringNotContainsString( $date, $listing );
		self::assertStringContainsString( '۰۹:۱۵ تا ۱۲:۳۰', $listing );
		self::assertStringNotContainsString( '09:15', $listing );
		self::assertStringContainsString( 'name="exception_id" value="' . $exception_id . '"', $listing );
		$row = App::db()->fetchRow(
			'SELECT date, start_time, end_time FROM ' . App::db()->table( 'cpms_schedule_exceptions' )
				. ' WHERE id = %d',
			[ $exception_id ]
		);
		self::assertNotNull( $row );
		self::assertSame( $date, $row['date'] );
		self::assertSame( '09:15:00', $row['start_time'] );
		self::assertSame( '12:30:00', $row['end_time'] );
	}

	public function test_foreign_patient_name_never_reaches_queue_notifications(): void {
		$doctor = self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_DOCTOR ] );
		$sender = self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_SECRETARY ] );
		$recipient = self::factory()->user->create(
			[ 'role' => RolesAndCapabilities::ROLE_SECRETARY ]
		);
		self::assertTrue( user_can( $recipient, RolesAndCapabilities::QUEUE_READ ) );
		cpms_test_seed_membership( $doctor, 1, RolesAndCapabilities::ROLE_DOCTOR );
		cpms_test_seed_membership( $sender, 1, RolesAndCapabilities::ROLE_SECRETARY );
		cpms_test_seed_membership( $recipient, 1, RolesAndCapabilities::ROLE_SECRETARY );

		$db           = App::db();
		$now          = $db->nowUtcSql();
		$organization = (int) $db->fetchValue(
			'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d',
			[ 1 ]
		);
		self::assertGreaterThan( 0, $organization );
		$other_clinic = $this->insert_row(
			'cpms_clinics',
			[
				'organization_id' => $organization,
				'name'            => 'Foreign fixture clinic',
				'slug'            => 'queue-name-' . bin2hex( random_bytes( 6 ) ),
				'timezone'        => 'Asia/Tehran',
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
		$location = (int) $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_locations' )
				. ' WHERE clinic_id = %d AND is_primary = 1 AND is_active = 1 LIMIT 1',
			[ 1 ]
		);
		self::assertGreaterThan( 0, $location );
		$clinician = $this->insert_row(
			'cpms_clinicians',
			[
				'clinic_id'  => 1,
				'wp_user_id' => $doctor,
				'full_name'  => 'Local Doctor',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$local_patient   = $this->patient( 1, 'Inside', $now );
		$foreign_patient = $this->patient( $other_clinic, 'Outside', $now );
		App::replaceExplicitScope( ClinicScope::forClinic( 1, $location ) );

		foreach ( [ 'call', 'invoice_ready' ] as $event ) {
			$status = 'call' === $event ? 'waiting' : 'consultation_completed';
			$actor  = 'call' === $event ? $doctor : $sender;
			$names = [ $local_patient => 'Inside Patient', $foreign_patient => 'بیمار' ];
			foreach ( $names as $id => $name ) {
				$visit_id = $this->visit( $id, $clinician, $location, $status, $now );
				wp_set_current_user( $actor );
				$result = App::visitService()->transition( $actor, $visit_id, $event, [ 'room' => '3' ] );
				self::assertSame( 'call' === $event ? 'called' : 'awaiting_payment', $result['status'] );
				self::assertSame( $id === $foreign_patient ? '' : 'Inside Patient', $result['patient_name'] );
				$dedupe = 'call' === $event
					? 'queue:called:v' . $visit_id . ':r0:u' . $recipient
					: 'queue:pay:v' . $visit_id . ':u' . $recipient;
				$row = $db->fetchRow(
					'SELECT clinic_id, recipient_wp_user_id, payload_json FROM '
						. $db->table( 'cpms_notifications' ) . ' WHERE dedupe_key = %s',
					[ $dedupe ]
				);
				self::assertNotNull( $row, 'The scoped recipient actually receives a notification.' );
				self::assertSame( 1, (int) $row['clinic_id'] );
				self::assertSame( $recipient, (int) $row['recipient_wp_user_id'] );
				$payload = json_decode( (string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR );
				self::assertSame( $name, $payload['vars']['patient_name'] );
				self::assertStringContainsString( $name, $payload['body'] );
				self::assertStringNotContainsString( 'Outside', (string) $row['payload_json'] );
			}
		}
	}

	private function patient( int $clinic_id, string $first, string $now ): int {
		return $this->insert_row(
			'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => 'REG-' . bin2hex( random_bytes( 6 ) ),
				'first_name' => $first,
				'last_name'  => 'Patient',
				'mobile'     => '09' . str_pad( (string) random_int( 0, 999999999 ), 9, '0', STR_PAD_LEFT ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
	}

	private function visit(
		int $patient_id,
		int $clinician_id,
		int $location_id,
		string $status,
		string $now
	): int {
		return $this->insert_row(
			'cpms_visits',
			[
				'clinic_id'    => 1,
				'location_id'  => $location_id,
				'clinician_id' => $clinician_id,
				'patient_id'   => $patient_id,
				'source'       => 'walk_in',
				'status'       => $status,
				'visit_date'   => '2026-03-21',
				'check_in_at'  => $now,
				'waiting_since' => $now,
				'active'       => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
	}
}
