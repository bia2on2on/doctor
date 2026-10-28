<?php
/**
 * Export the identifiers a Chromium persona journey needs.
 *
 * Run with:
 *   wp eval-file tests/scoped-demo/persona-targets.php --path=... --allow-root
 *
 * Prints one JSON line. Read-only: it never mutates demo or host data.
 *
 * @package CpmsDisposableDemo
 */

use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use CpmsDemo\Platform;
use CpmsDemo\ScopedManifest;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a CPMS portal page URL WITHOUT the idempotent `ensure_*_page()`
 * helpers: this exporter must never create a row after the baseline was sealed.
 *
 * @param string $option Option holding the published Page id.
 * @param string $slug   Canonical CPMS Page slug.
 * @return array{id:int,url:string}
 */
$cpsd_portal = static function ( string $option, string $slug ): array {
	$page_id = (int) get_option( $option, 0 );
	if ( $page_id <= 0 ) {
		$page = get_page_by_path( $slug );
		$page_id = $page instanceof WP_Post ? (int) $page->ID : 0;
	}
	if ( $page_id <= 0 ) {
		return array( 'id' => 0, 'url' => '' );
	}
	$permalink = get_permalink( $page_id );

	return array(
		'id'  => $page_id,
		'url' => is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/?page_id=' . $page_id ),
	);
};

try {
	if ( ! class_exists( ScopedManifest::class ) ) {
		throw new RuntimeException( 'companion plugin inactive' );
	}
	global $wpdb;
	$platform = new Platform();
	$manifest = new ScopedManifest( new CpmsDemo\Env() );
	$live     = $manifest->load();
	if ( ! is_array( $live ) ) {
		throw new RuntimeException( 'no scoped fixture' );
	}
	$clinic_id = (int) ( $live['ids']['clinic_id'] ?? 0 );
	$table     = static fn ( string $short ): string => $platform->table( $short );

	$slot = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, slot_date, slot_time, clinician_id, location_id FROM ' . $table( 'cpms_schedule_slots' )
			. ' WHERE clinic_id = %d AND is_open = 1 AND booked_count < capacity AND slot_date >= %s'
			. ' ORDER BY slot_date ASC, slot_time ASC LIMIT 1',
			$clinic_id,
			gmdate( 'Y-m-d' )
		)
	);
	// A second, untouched open slot: the receptionist journey books the earliest
	// one, so the patient's own OTP-bound booking (hold -> confirm) needs its own
	// slot rather than a race against that write.
	$patient_slot = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, slot_date, slot_time, clinician_id, location_id FROM ' . $table( 'cpms_schedule_slots' )
			. ' WHERE clinic_id = %d AND is_open = 1 AND booked_count < capacity AND slot_date >= %s'
			. ' ORDER BY slot_date DESC, slot_time DESC, id DESC LIMIT 1',
			$clinic_id,
			gmdate( 'Y-m-d' )
		)
	);
	$booking_page_id = (int) ( $live['ids']['booking_page_id'] ?? 0 );
	// Prefer the permalink the provisioner itself sealed into the manifest; fall
	// back to WordPress only when that link is missing.
	$booking_url = (string) ( $live['links']['booking'] ?? '' );
	if ( '' === $booking_url && $booking_page_id > 0 ) {
		$booking_link = get_permalink( $booking_page_id );
		$booking_url  = is_string( $booking_link ) && '' !== $booking_link ? $booking_link : home_url( '/?page_id=' . $booking_page_id );
	}
	$visit_row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, clinician_id, location_id FROM ' . $table( 'cpms_visits' ) . ' WHERE id = %d',
			(int) ( $live['ids']['visit_id'] ?? 0 )
		)
	);
	$clinic_row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT id, name FROM ' . $table( 'cpms_clinics' ) . ' WHERE id = %d', $clinic_id )
	);
	$organization_row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, name FROM ' . $table( 'cpms_organizations' ) . ' WHERE id = %d',
			(int) ( $live['ids']['organization_id'] ?? 0 )
		)
	);
	$staff_portal      = $cpsd_portal( StaffPortalShell::PAGE_OPTION, StaffPortalShell::PAGE_SLUG );
	$patient_portal    = $cpsd_portal( PatientPortalShell::PAGE_OPTION, PatientPortalShell::PAGE_SLUG );
	$management_portal = $cpsd_portal( ManagementPortalShell::PAGE_OPTION, ManagementPortalShell::PAGE_SLUG );
	$module_url        = static function ( string $base, string $module ) use ( $staff_portal ): string {
		return '' === $base ? '' : (string) add_query_arg( 'cpms-module', $module, $base );
	};
	$foreign_invoice = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, clinic_id FROM ' . $table( 'cpms_invoices' ) . ' WHERE clinic_id <> %d ORDER BY id ASC LIMIT 1',
			$clinic_id
		)
	);
	$foreign_patient = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, clinic_id FROM ' . $table( 'cpms_patients' ) . ' WHERE clinic_id <> %d ORDER BY id ASC LIMIT 1',
			$clinic_id
		)
	);

	echo (string) wp_json_encode(
		array(
			'ok'           => true,
			'fixture_id'   => (string) $live['fixture_id'],
			'clinic_id'    => $clinic_id,
			'organization_id' => (int) ( $live['ids']['organization_id'] ?? 0 ),
			'clinician_id' => (int) ( $live['ids']['clinician_id'] ?? 0 ),
			'patient_id'   => (int) ( $live['ids']['patient_id'] ?? 0 ),
			'appointment_id' => (int) ( $live['ids']['appointment_id'] ?? 0 ),
			'visit_id'     => (int) ( $live['ids']['visit_id'] ?? 0 ),
			'invoice_id'   => (int) ( $live['ids']['invoice_id'] ?? 0 ),
			'payment_id'   => (int) ( $live['ids']['payment_id'] ?? 0 ),
			'portal_page_id' => (int) ( $live['ids']['booking_page_id'] ?? 0 ),
			'clinic_name'  => null === $clinic_row ? '' : (string) $clinic_row->name,
			'organization_name' => null === $organization_row ? '' : (string) $organization_row->name,
			'visit'        => array(
				'id'           => null === $visit_row ? 0 : (int) $visit_row->id,
				'clinician_id' => null === $visit_row ? 0 : (int) $visit_row->clinician_id,
				'location_id'  => null === $visit_row ? 0 : (int) $visit_row->location_id,
			),
			'slot'         => null === $slot ? null : array(
				'id'           => (int) $slot->id,
				'date'         => (string) $slot->slot_date,
				'time'         => (string) $slot->slot_time,
				'clinician_id' => (int) $slot->clinician_id,
				'location_id'  => (int) ( $slot->location_id ?? 0 ),
			),
			'patient_slot' => null === $patient_slot ? null : array(
				'id'           => (int) $patient_slot->id,
				'date'         => (string) $patient_slot->slot_date,
				'time'         => (string) $patient_slot->slot_time,
				'clinician_id' => (int) $patient_slot->clinician_id,
				'location_id'  => (int) ( $patient_slot->location_id ?? 0 ),
			),
			'patient_mobile' => (string) ( $live['patient_mobile'] ?? '' ),
			'patient_wp_user_id' => (int) ( $live['ids']['user_ids']['patient'] ?? 0 ),
			'booking'      => array(
				'id'  => $booking_page_id,
				'url' => $booking_url,
			),
			'portals'      => array(
				'staff'      => $staff_portal,
				'patient'    => $patient_portal,
				'management' => $management_portal,
				'modules'    => array(
					'reception' => $module_url( $staff_portal['url'], StaffPortalShell::MODULE_RECEPTION ),
					'finance'   => $module_url( $staff_portal['url'], StaffPortalShell::MODULE_FINANCE ),
					'doctor'    => $module_url( $staff_portal['url'], StaffPortalShell::MODULE_DOCTOR ),
					'messages'  => $module_url( $staff_portal['url'], StaffPortalShell::MODULE_MESSAGES ),
					'pricing'   => $module_url( $staff_portal['url'], StaffPortalShell::MODULE_PRICING ),
				),
			),
			'iran_v1'      => array(
				'private_thread'      => (int) ( $live['ids']['message_thread_ids']['doctor_receptionist'] ?? 0 ),
				'threads'             => array_map( 'intval', (array) ( $live['ids']['message_thread_ids'] ?? array() ) ),
				'pricing_visit_id'    => (int) ( $live['ids']['visit_id_pricing'] ?? 0 ),
				'doctor_second_id'    => (int) ( $live['ids']['user_ids']['doctor_second'] ?? 0 ),
				'reception_second_id' => (int) ( $live['ids']['user_ids']['receptionist_second'] ?? 0 ),
			),
			'foreign'      => array(
				'invoice_id' => null === $foreign_invoice ? null : (int) $foreign_invoice->id,
				'clinic_id'  => null === $foreign_invoice ? null : (int) $foreign_invoice->clinic_id,
				'patient_id' => null === $foreign_patient ? null : (int) $foreign_patient->id,
			),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode( array( 'ok' => false, 'error' => get_class( $error ) . ': ' . $error->getMessage() ) ) . PHP_EOL;
}
