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

use CpmsDemo\Platform;
use CpmsDemo\ScopedManifest;

defined( 'ABSPATH' ) || exit;

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
			'SELECT id, slot_date, slot_time FROM ' . $table( 'cpms_schedule_slots' )
			. ' WHERE clinic_id = %d AND is_open = 1 AND booked_count < capacity AND slot_date >= %s'
			. ' ORDER BY slot_date ASC, slot_time ASC LIMIT 1',
			$clinic_id,
			gmdate( 'Y-m-d' )
		)
	);
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
			'slot'         => null === $slot ? null : array(
				'id'     => (int) $slot->id,
				'date'   => (string) $slot->slot_date,
				'time'   => (string) $slot->slot_time,
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
