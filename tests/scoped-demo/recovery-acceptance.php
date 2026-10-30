<?php
/**
 * DB-side proof for the wrong-mobile recovery drill (real MySQL 8 / MariaDB).
 *
 * CORRECTION OF PR #283's ASSERTIONS (not of the product)
 * ------------------------------------------------------
 * The previous version required an APPOINTMENT created by the browser with the
 * wrong mobile. That can never happen: `OtpService::verify()` provisions a real
 * WordPress user and issues a session, so the following public-booking Hold is a
 * write into the demo tenant by a non-fixture actor and
 * `ScopedTenantGuard::write_verdict()` refuses it with 403
 * `SCOPED_DEMO_TENANT_WRITE_REFUSED` (proved on a real WordPress runtime by
 * `tests/scoped-demo/tenant-barrier-drill.php`).
 *
 * What the browser journey DOES leave behind is exactly what blocks the
 * teardown: one `cpms_otp_tokens` row for the foreign mobile, bound to the demo
 * Clinic, and its `log`-provider `cpms_sms_messages` rows. This driver proves
 * those rows are real, that no appointment or patient row was created, that the
 * preview names the unattributable rows, that the execution refuses, that no
 * clinical, financial, identity or audit row is deleted or edited, and that the
 * append-only audit hash chain still verifies.
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
	$mobile       = (string) ( $browser['evidence']['mobile'] ?? '' );
	$hold_status  = (int) ( $browser['evidence']['hold_status'] ?? 0 );
	$hold_code    = (string) ( $browser['evidence']['hold_code'] ?? '' );
	$check( '09009999999' === $mobile, 'the drill used the reserved synthetic wrong mobile (never the sealed patient mobile)', $checks, $mobile );
	$check( 403 === $hold_status, 'the browser recorded that the tenant write barrier refused the Hold with HTTP 403', $checks, (string) $hold_status );
	$check( 'SCOPED_DEMO_TENANT_WRITE_REFUSED' === $hold_code, 'the refusal carries the stable tenant-barrier code', $checks, $hold_code );
	$check( empty( $browser['evidence']['appointment_created'] ), 'the browser recorded that no appointment was created', $checks );
	$evidence['hold_status'] = $hold_status;
	$evidence['hold_code']   = $hold_code;

	global $wpdb;
	$env      = new Env();
	$platform = new Platform();
	$manifest = new ScopedManifest( $env );
	$live     = $manifest->load();
	if ( ! is_array( $live ) || 'active' !== (string) ( $live['status'] ?? '' ) ) {
		throw new RuntimeException( 'no active recovery fixture' );
	}
	$clinic_id            = (int) $live['ids']['clinic_id'];
	$evidence['clinic_id'] = $clinic_id;

	// ---- 1. The residue that actually blocks the teardown -------------------
	$otp_table = $platform->table( 'cpms_otp_tokens' );
	$sms_table = $platform->table( 'cpms_sms_messages' );
	$otp_rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$otp_table} WHERE clinic_id = %d AND mobile = %s", $clinic_id, $mobile ) );
	$sms_rows  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$sms_table} WHERE clinic_id = %d AND recipient = %s AND event = 'otp' AND context_type = 'otp_token'",
			$clinic_id,
			$mobile
		)
	);
	// If a row were ever created outside the demo Clinic, the detail shows both
	// counts so the failure is diagnosable instead of merely red.
	$otp_any = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$otp_table} WHERE mobile = %s", $mobile ) );
	$sms_any = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sms_table} WHERE recipient = %s AND event = 'otp'", $mobile ) );
	$evidence['otp_rows']       = $otp_rows;
	$evidence['sms_rows']       = $sms_rows;
	$evidence['otp_rows_any']   = $otp_any;
	$evidence['sms_rows_any']   = $sms_any;
	$check( $otp_rows >= 1, 'a real OTP challenge exists for the wrong mobile, bound to the demo Clinic', $checks, $otp_rows . ' (all clinics: ' . $otp_any . ')' );
	$check( $sms_rows >= 1, 'its log-provider SMS echo exists (no SMS left the site)', $checks, $sms_rows . ' (all clinics: ' . $sms_any . ')' );

	$patient_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $platform->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND mobile = %s', $clinic_id, $mobile ) );
	$check( 0 === $patient_rows, 'the refused booking created no patient record for that mobile', $checks, (string) $patient_rows );
	$appointment_rows = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $platform->table( 'cpms_appointments' ) . ' a INNER JOIN ' . $platform->table( 'cpms_patients' ) . ' p ON p.id = a.patient_id WHERE a.clinic_id = %d AND p.mobile = %s',
			$clinic_id,
			$mobile
		)
	);
	$check( 0 === $appointment_rows, 'no appointment exists for the wrong mobile in the demo Clinic', $checks, (string) $appointment_rows );

	// ---- 2. The preview and the execution both refuse ----------------------
	$audit    = new ScopedAuditGuard( $platform );
	$baseline = new ScopedBaseline( $env, $platform );
	$teardown = new ScopedTeardown( $env, $platform, $manifest, $audit, $baseline );
	$preview  = $teardown->preview( $live );
	$problems = implode( ' | ', (array) ( $preview['problems'] ?? array() ) );
	$check( empty( $preview['ok'] ) && false !== strpos( $problems, 'SCOPED_FOREIGN_TENANT_ROW' ), 'the preview names the unattributable rows', $checks, mb_substr( $problems, 0, 240 ) );

	// Clinical, financial, identity and audit tables are counted on BOTH sides of
	// the refused cleanup: "nothing was destroyed" has to be measured.
	$watched = array(
		'cpms_patients',
		'cpms_appointments',
		'cpms_otp_tokens',
		'cpms_sms_messages',
		'cpms_patient_user_links',
		'cpms_patient_identities',
		'cpms_patient_identity_links',
		'cpms_visits',
		'cpms_clinical_notes',
		'cpms_prescriptions',
		'cpms_invoices',
		'cpms_payments',
		'cpms_audit_logs',
	);
	$snapshot_rows = static function () use ( $wpdb, $platform, $watched ): array {
		$counts = array();
		foreach ( $watched as $short ) {
			$table = $platform->table( $short );
			// A table that does not exist in this build must not abort the drill; it
			// is reported as absent and simply cannot lose rows.
			$exists             = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$counts[ $short ] = null === $exists ? -1 : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		return $counts;
	};
	$before_counts = $snapshot_rows();
	$before        = array_sum( array_filter( $before_counts, static fn ( int $n ): bool => $n >= 0 ) );
	$check( (int) ( $before_counts['cpms_invoices'] ?? 0 ) > 0, 'the demo fixture owns financial rows before the refusal', $checks, (string) ( $before_counts['cpms_invoices'] ?? -1 ) );
	$check( (int) ( $before_counts['cpms_visits'] ?? 0 ) > 0, 'the demo fixture owns clinical visits before the refusal', $checks, (string) ( $before_counts['cpms_visits'] ?? -1 ) );

	$code = '';
	try {
		$teardown->execute( $live, (int) ( $live['admin_id'] ?? 1 ) );
	} catch ( \CpmsDemo\Exception $error ) {
		$code = $error->demo_code();
	}
	$after_counts = $snapshot_rows();
	$after        = array_sum( array_filter( $after_counts, static fn ( int $n ): bool => $n >= 0 ) );
	$check( in_array( $code, array( 'SCOPED_FOREIGN_TENANT_ROW', 'TEARDOWN_BLOCKED' ), true ), 'the execution refuses with a stable blocked code', $checks, $code );
	$check( $before === $after, 'the refused cleanup changed no clinical/financial/identity/OTP row count', $checks, $before . ' -> ' . $after );
	foreach ( $watched as $short ) {
		$check(
			$before_counts[ $short ] === $after_counts[ $short ],
			'the refused cleanup left the row count unchanged: ' . $short,
			$checks,
			(int) $before_counts[ $short ] . ' -> ' . (int) $after_counts[ $short ]
		);
	}

	$otp_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$otp_table} WHERE clinic_id = %d AND mobile = %s", $clinic_id, $mobile ) );
	$sms_after = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$sms_table} WHERE clinic_id = %d AND recipient = %s AND event = 'otp' AND context_type = 'otp_token'",
			$clinic_id,
			$mobile
		)
	);
	$check( $otp_after === $otp_rows && $sms_after === $sms_rows, 'the unattributable OTP/SMS rows are PRESERVED, never silently claimed', $checks, $otp_after . '/' . $sms_after );

	$chain = $audit->verify_chain();
	$check( ! empty( $chain['ok'] ), 'the append-only audit hash chain still verifies', $checks, (string) ( $chain['error'] ?? '' ) );

	$evidence['blocked_code']       = $code;
	$evidence['rows_before']        = $before;
	$evidence['rows_after']         = $after;
	$evidence['row_counts']         = array( 'before' => $before_counts, 'after' => $after_counts );
	$evidence['audit_checked']      = (int) ( $chain['checked'] ?? 0 );
	$evidence['safe_auto_recovery'] = false;
	$evidence['disposition']        = 'preserve unknown rows; a human decides (the demo never claims a mobile or a tenant as ownership)';

	$failed = array_values( array_filter( $checks, static fn ( string $line ): bool => 0 === strpos( $line, 'FAIL' ) ) );
	echo (string) wp_json_encode(
		array(
			'ok'       => array() === $failed,
			'checks'   => $checks,
			'evidence' => $evidence,
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} catch ( \Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'     => false,
			'checks' => $checks,
			'error'  => get_class( $error ) . ': ' . $error->getMessage(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}
