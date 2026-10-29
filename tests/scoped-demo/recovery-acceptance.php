<?php
/**
 * DB-side proof for the real wrong-mobile browser booking drill.
 *
 * The browser creates an anonymous public booking with a reserved synthetic
 * mobile that is NOT the sealed patient mobile. This driver proves the row is
 * real, cleanup refuses before deletion, the refusal is repeatable without
 * changing data, and the append-only audit chain still verifies. It never
 * claims ownership of the unknown rows and never deletes them.
 *
 * @package CpmsDisposableDemo
 */

use CpmsDemo\Env;
use CpmsDemo\Platform;
use CpmsDemo\ScopedAuditGuard;
use CpmsDemo\ScopedBaseline;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedTeardown;

defined( 'ABSPATH' ) || exit;

$checks   = array();
$evidence = array();
$check    = static function ( bool $ok, string $label, array &$checks, string $detail = '' ): void {
	$checks[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( '' === $detail ? '' : ' — ' . $detail );
};

try {
	$browser_file = (string) ( getenv( 'CPSD_RECOVERY_BROWSER' ) ?: '/tmp/cpsa-ci/browser/recovery-browser.json' );
	$browser      = json_decode( (string) file_get_contents( $browser_file ), true );
	if ( ! is_array( $browser ) || empty( $browser['ok'] ) ) {
		throw new RuntimeException( 'browser recovery evidence missing or failed' );
	}
	$reference = (string) ( $browser['evidence']['reference_code'] ?? '' );
	$mobile    = (string) ( $browser['evidence']['mobile'] ?? '' );
	if ( '' === $reference || '09009999999' !== $mobile ) {
		throw new RuntimeException( 'invalid synthetic recovery evidence' );
	}

	global $wpdb;
	$env      = new Env();
	$platform = new Platform();
	$manifest = new ScopedManifest( $env );
	$live     = $manifest->load();
	if ( ! is_array( $live ) || 'active' !== (string) ( $live['status'] ?? '' ) ) {
		throw new RuntimeException( 'no active recovery fixture' );
	}
	$clinic_id = (int) $live['ids']['clinic_id'];
	$appt      = $wpdb->get_row( $wpdb->prepare( 'SELECT id, patient_id, clinic_id, status FROM ' . $platform->table( 'cpms_appointments' ) . ' WHERE reference_code = %s', $reference ), ARRAY_A );
	$check( is_array( $appt ) && (int) $appt['clinic_id'] === $clinic_id, 'the browser booking is a real appointment in the demo Clinic', $checks, $reference );
	$patient_mobile = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT mobile FROM ' . $platform->table( 'cpms_patients' ) . ' WHERE id = %d', (int) ( $appt['patient_id'] ?? 0 ) ) );
	$check( $patient_mobile === $mobile && $mobile !== (string) $live['patient_mobile'], 'the appointment belongs to the WRONG reserved synthetic mobile, not the sealed patient', $checks );
	$evidence['appointment_id'] = (int) ( $appt['id'] ?? 0 );
	$evidence['patient_id']     = (int) ( $appt['patient_id'] ?? 0 );

	$audit     = new ScopedAuditGuard( $platform );
	$baseline  = new ScopedBaseline( $env, $platform );
	$teardown  = new ScopedTeardown( $env, $platform, $manifest, $audit, $baseline );
	$preview   = $teardown->preview( $live );
	$problems  = implode( ' | ', (array) ( $preview['problems'] ?? array() ) );
	$check( empty( $preview['ok'] ) && false !== strpos( $problems, 'SCOPED_FOREIGN_TENANT_ROW' ), 'preview names the unattributable tenant rows', $checks, mb_substr( $problems, 0, 240 ) );

	$count_rows = static function () use ( $wpdb, $platform ): int {
		$total = 0;
		foreach ( array( 'cpms_patients', 'cpms_appointments', 'cpms_otp_tokens', 'cpms_patient_user_links', 'cpms_patient_identity_links' ) as $short ) {
			$total += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $platform->table( $short ) );
		}
		return $total;
	};
	$before = $count_rows();
	$code   = '';
	try {
		$teardown->execute( $live, (int) $live['admin_id'] );
	} catch ( CpmsDemo\Exception $error ) {
		$code = $error->demo_code();
	}
	$after = $count_rows();
	$check( in_array( $code, array( 'SCOPED_FOREIGN_TENANT_ROW', 'TEARDOWN_BLOCKED' ), true ), 'execute refuses with a stable blocked code', $checks, $code );
	$check( $before === $after, 'the refused cleanup changed no clinical/identity/OTP row counts', $checks, $before . ' -> ' . $after );
	$still = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $platform->table( 'cpms_appointments' ) . ' WHERE reference_code = %s', $reference ) );
	$check( 1 === $still, 'the unknown synthetic booking is preserved, not deleted', $checks );
	$chain = $audit->verify_chain();
	$check( ! empty( $chain['ok'] ), 'the append-only audit hash chain still verifies', $checks, (string) ( $chain['error'] ?? '' ) );
	$evidence['blocked_code']   = $code;
	$evidence['rows_before']    = $before;
	$evidence['rows_after']     = $after;
	$evidence['audit_checked']  = (int) ( $chain['checked'] ?? 0 );
	$evidence['safe_auto_recovery'] = false;
	$evidence['disposition']    = 'preserve unknown rows; human review / restore from backup; never claim by tenant or mobile alone';

	$failed = array_values( array_filter( $checks, static fn ( $line ) => 0 === strpos( $line, 'FAIL' ) ) );
	echo (string) wp_json_encode( array( 'ok' => array() === $failed, 'checks' => $checks, 'evidence' => $evidence ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode( array( 'ok' => false, 'checks' => $checks, 'error' => get_class( $error ) . ': ' . $error->getMessage() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}
