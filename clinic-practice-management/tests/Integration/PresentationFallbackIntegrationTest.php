<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\SystemPage;
use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Jobs\FollowUpReminderHandler;
use ClinicCore\Bootstrap\App;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

/** Real database lookups and WordPress rendering for display-only changes. */
final class PresentationFallbackIntegrationTest extends WP_UnitTestCase {

	/**
	 * @param class-string $handler_class Reminder handler under test.
	 * @param array<string, mixed> $row Candidate data.
	 * @return array<string, string>
	 */
	private function reminder_vars( string $handler_class, array $row ): array {
		$handler = ( new ReflectionClass( $handler_class ) )->newInstanceWithoutConstructor();
		$db_prop = new ReflectionProperty( $handler_class, 'db' );
		$db_prop->setValue( $handler, App::db() );
		$method = new ReflectionMethod( $handler_class, 'vars' );

		return $method->invoke( $handler, $row );
	}

	public function test_both_reminders_handle_missing_names_and_keep_dates_and_ids(): void {
		global $wpdb;
		App::migrations()->migrate();
		$db     = App::db();
		$now    = $db->nowUtcSql();
		$table  = $db->table( 'cpms_clinicians' );
		$result = $wpdb->insert(
			$table,
			[
				'clinic_id'  => 1,
				'full_name'  => "\u{00A0}",
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		self::assertSame( 1, $result );
		$clinician_id = (int) $wpdb->insert_id;
		$row          = [
			'clinician_id'  => $clinician_id,
			'clinic_id'     => 1,
			'first_name'    => "\u{00A0}",
			'last_name'     => ' ',
			'slot_date'     => '2026-03-21',
			'slot_time'     => '10:20:00',
			'suggested_date' => '2026-03-21',
		];
		foreach ( [ ApptReminderHandler::class, FollowUpReminderHandler::class ] as $class ) {
			$vars = $this->reminder_vars( $class, $row );
			self::assertSame( 'پزشک', $vars['doctor_name'] );
			self::assertSame( 'بیمار گرامی', $vars['patient_name'] );
			self::assertSame( '۱۴۰۵/۰۱/۰۱', $vars['appointment_date'] );
		}
		self::assertSame( '2026-03-21', $row['slot_date'] );
		self::assertSame( $clinician_id, $row['clinician_id'] );

		$updated = $wpdb->update(
			$table,
			[ 'full_name' => 'دکتر Dr. رضایی' ],
			[ 'id' => $clinician_id ]
		);
		self::assertNotFalse( $updated );
		$row['first_name'] = 'سارا';
		$row['last_name']  = 'احمدی';
		foreach ( [ ApptReminderHandler::class, FollowUpReminderHandler::class ] as $class ) {
			$vars = $this->reminder_vars( $class, $row );
			self::assertSame( 'دکتر رضایی', $vars['doctor_name'] );
			self::assertSame( 'سارا احمدی', $vars['patient_name'] );
		}
		$appointment = $this->reminder_vars( ApptReminderHandler::class, $row );
		self::assertSame( '۱۰:۲۰', $appointment['appointment_time'] );
	}

	public function test_health_counts_render_as_persian_digits(): void {
		$check  = [ 'key' => 'probe', 'label' => 'test', 'status' => 'pass', 'detail' => 'ok' ];
		$render = new ReflectionMethod( SystemPage::class, 'render_healthy_table' );
		ob_start();
		$render->invoke( null, array_fill( 0, 12, $check ) );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '(۱۲)', $html );
		self::assertStringNotContainsString( '(12)', $html );
	}
}
