<?php
/**
 * Real-WordPress integration acceptance for the scoped demo.
 *
 * Run inside a disposable CI WordPress with:
 *   wp eval-file tools/disposable-demo-installer/tests/scoped-integration.php
 *
 * This is not a Chromium/browser test. It seeds a non-empty site, provisions,
 * verifies six real WP logins, interleaves foreign activity, tears down, then
 * verifies the sealed baseline and full audit hash chain.
 *
 * @package CpmsDisposableDemo
 */

use ClinicCore\Bootstrap\App;
use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Platform;
use CpmsDemo\ScopedAuditGuard;
use CpmsDemo\ScopedBaseline;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedPreflight;
use CpmsDemo\ScopedProvisioner;
use CpmsDemo\ScopedTeardown;

defined( 'ABSPATH' ) || exit;

/**
 * @param bool $ok Condition.
 * @param string $label Label.
 * @param list<string> $checks Checks accumulator.
 * @return void
 */
function cpsd_int_check( bool $ok, string $label, array &$checks ): void {
	$checks[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/**
 * @param string $table Table name.
 * @param array<string,mixed> $data Row.
 * @return int Insert ID.
 */
function cpsd_int_insert( string $table, array $data ): int {
	global $wpdb;
	$ok = $wpdb->insert( $table, $data );
	if ( false === $ok || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'seed insert failed: ' . $table . ' ' . (string) $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Seed legitimate pre-existing records and global/audit/queue/rate-limit rows.
 *
 * @param Platform $platform CPMS adapter.
 * @return array<string,int>
 */
function cpsd_int_seed( Platform $platform ): array {
	global $wpdb;
	$now   = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$table = static fn ( string $short ): string => $platform->table( $short );
	$tag   = 'existing-int-' . bin2hex( random_bytes( 4 ) );

	$user_a = wp_insert_user( array( 'user_login' => $tag . '-a', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-a@example.test', 'role' => 'subscriber' ) );
	$user_b = wp_insert_user( array( 'user_login' => $tag . '-b', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-b@example.test', 'role' => 'subscriber' ) );
	if ( ! is_int( $user_a ) || ! is_int( $user_b ) ) {
		throw new RuntimeException( 'seed users failed' );
	}
	$post_id = wp_insert_post( array( 'post_title' => 'Existing post', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'pre-existing' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'seed post failed' );
	}
	$comment_id = wp_insert_comment( array( 'comment_post_ID' => $post_id, 'comment_author' => 'Existing', 'comment_content' => 'pre-existing comment', 'comment_approved' => 1, 'user_id' => $user_a ) );

	$org_id = cpsd_int_insert( $table( 'cpms_organizations' ), array( 'name' => 'Existing Org', 'slug' => $tag . '-org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$clinic_id = cpsd_int_insert( $table( 'cpms_clinics' ), array( 'organization_id' => $org_id, 'name' => 'Existing Clinic', 'slug' => $tag . '-clinic', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$location_id = cpsd_int_insert( $table( 'cpms_locations' ), array( 'clinic_id' => $clinic_id, 'name' => 'Existing Location', 'slug' => $tag . '-loc', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$clinician_id = cpsd_int_insert( $table( 'cpms_clinicians' ), array( 'clinic_id' => $clinic_id, 'wp_user_id' => $user_b, 'full_name' => 'Existing Clinician', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$slot_id = cpsd_int_insert( $table( 'cpms_schedule_slots' ), array( 'clinic_id' => $clinic_id, 'clinician_id' => $clinician_id, 'location_id' => $location_id, 'slot_date' => gmdate( 'Y-m-d', time() + 86400 ), 'slot_time' => '10:00:00', 'duration_min' => 20, 'capacity' => 1, 'booked_count' => 0, 'held_count' => 0, 'is_open' => 1, 'generated_from' => 'manual', 'created_at' => $now, 'updated_at' => $now ) );
	$patient_id = cpsd_int_insert( $table( 'cpms_patients' ), array( 'clinic_id' => $clinic_id, 'mrn' => $tag . '-mrn', 'first_name' => 'Existing', 'last_name' => 'Patient', 'mobile' => '09120000001', 'gender' => 'unknown', 'created_at' => $now, 'updated_at' => $now ) );
	$appointment_id = cpsd_int_insert( $table( 'cpms_appointments' ), array( 'clinic_id' => $clinic_id, 'location_id' => $location_id, 'reference_code' => $tag . '-appt', 'clinician_id' => $clinician_id, 'patient_id' => $patient_id, 'slot_id' => $slot_id, 'slot_date' => gmdate( 'Y-m-d', time() + 86400 ), 'slot_time' => '10:00:00', 'wp_user_id' => $user_a, 'reason' => 'pre-existing', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now ) );
	$visit_id = cpsd_int_insert( $table( 'cpms_visits' ), array( 'clinic_id' => $clinic_id, 'location_id' => $location_id, 'clinician_id' => $clinician_id, 'patient_id' => $patient_id, 'appointment_id' => $appointment_id, 'source' => 'scheduled', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d', time() + 86400 ), 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
	$invoice_id = cpsd_int_insert( $table( 'cpms_invoices' ), array( 'clinic_id' => $clinic_id, 'invoice_number' => $tag . '-inv', 'patient_id' => $patient_id, 'visit_id' => $visit_id, 'status' => 'open', 'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000, 'currency' => 'IRR', 'paid_amount' => 0, 'balance' => 1000, 'issued_by_wp_user_id' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$payment_id = cpsd_int_insert( $table( 'cpms_payments' ), array( 'clinic_id' => $clinic_id, 'payment_number' => $tag . '-pay', 'invoice_id' => $invoice_id, 'patient_id' => $patient_id, 'amount' => 1000, 'method' => 'cash', 'transaction_ref' => 'EXISTING', 'idempotency_key' => $tag . '-idem', 'status' => 'captured', 'paid_at' => $now, 'received_by_wp_user_id' => 1, 'created_at' => $now ) );
	$job_id = cpsd_int_insert( $table( 'cpms_jobs' ), array( 'type' => 'foreign.test', 'payload_json' => (string) wp_json_encode( array( 'marker' => 'foreign' ) ), 'status' => 'queued', 'priority' => 5, 'attempts' => 0, 'max_attempts' => 3, 'run_after' => $now, 'created_at' => $now ) );
	$oplog_id = cpsd_int_insert( $table( 'cpms_operational_logs' ), array( 'level' => 'info', 'message' => 'FOREIGN_BASELINE_LOG', 'context_json' => (string) wp_json_encode( array( 'request_id' => 'shared', 'ip' => '127.0.0.1', 'created_at' => $now ) ), 'request_id' => 'shared', 'created_at' => $now ) );
	$wpdb->insert( $table( 'cpms_rate_limits' ), array( 'window_key' => 'otp-ip:foreign-baseline', 'window_id' => 1, 'hits' => 1, 'window_sec' => 3600 ) );
	App::audit()->log( 'CPSD_INTEGRATION_BASELINE', array( 'wp_user_id' => 1, 'role' => 'administrator' ), 'system', null, null, null, null, array( 'marker' => 'foreign' ), null, true );

	return array(
		'user_a'         => $user_a,
		'user_b'         => $user_b,
		'post_id'        => (int) $post_id,
		'comment_id'     => (int) $comment_id,
		'patient_id'     => $patient_id,
		'invoice_id'     => $invoice_id,
		'payment_id'     => $payment_id,
		'job_id'         => $job_id,
		'oplog_id'       => $oplog_id,
		'foreign_marker' => $tag,
	);
}

$checks = array();
try {
	if ( ! class_exists( App::class ) || ! class_exists( Platform::class ) ) {
		throw new RuntimeException( 'CPMS/companion not active' );
	}
	$admin_id = 1;
	wp_set_current_user( $admin_id );
	$env         = new Env();
	$platform    = new Platform();
	$manifest    = new ScopedManifest( $env );
	$audit_guard = new ScopedAuditGuard( $platform );
	$baseline    = new ScopedBaseline( $env, $platform );
	$preflight   = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
	$credentials = new Credentials( $env );
	$provisioner = new ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard );
	$teardown    = new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline );

	$seeded = cpsd_int_seed( $platform );
	cpsd_int_check( true, 'seeded non-empty WordPress/CPMS fixture', $checks );
	$chain_before = $audit_guard->verify_chain();
	cpsd_int_check( (bool) $chain_before['ok'], 'baseline audit chain is valid', $checks );

	$manifest->enable_mode( $admin_id );
	$state = $preflight->run();
	cpsd_int_check( (bool) $state['ok'], 'scoped preflight passes on non-empty site: ' . implode( ',', (array) $state['blocking'] ), $checks );

	$result = $provisioner->provision( $admin_id );
	$active = $manifest->load();
	cpsd_int_check( is_array( $active ) && 'active' === (string) $active['status'], 'scoped fixture is active', $checks );
	cpsd_int_check( 6 === count( (array) $active['ids']['user_ids'] ), 'six personas are recorded', $checks );
	foreach ( Credentials::PERSONA_ORDER as $persona ) {
		$login = $manifest->fixture_prefix() . $persona . '-' . (string) $active['fixture_id'];
		$auth  = wp_authenticate( $login, (string) $result['credentials'][ $persona ] );
		cpsd_int_check( $auth instanceof WP_User, 'real WP authentication: ' . $persona, $checks );
	}
	cpsd_int_check( 'log' === (string) $platform->get_setting( (int) $active['ids']['clinic_id'], 'sms.provider' ), 'synthetic clinic uses log SMS provider', $checks );
	cpsd_int_check( isset( $active['baseline'], $active['owned'], $active['audit_chain_at_seal'] ), 'baseline and ownership map are sealed', $checks );
	cpsd_int_check( (bool) $active['audit_chain_at_seal']['ok'], 'audit chain valid at seal', $checks );

	// Interleave legitimate foreign activity during the demo.
	$foreign_user = wp_insert_user( array( 'user_login' => $seeded['foreign_marker'] . '-foreign', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $seeded['foreign_marker'] . '-foreign@example.test', 'role' => 'subscriber' ) );
	$foreign_post = wp_insert_post( array( 'post_title' => 'Foreign during demo', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'foreign' ), true );
	$foreign_comment = 0;
	if ( is_int( $foreign_post ) ) {
		$foreign_comment = wp_insert_comment( array( 'comment_post_ID' => $foreign_post, 'comment_author' => 'Foreign', 'comment_content' => 'foreign during demo', 'comment_approved' => 1 ) );
	}
	App::audit()->log( 'CPSD_INTEGRATION_FOREIGN', array( 'wp_user_id' => $admin_id, 'role' => 'administrator' ), 'system', null, null, null, null, array( 'marker' => 'foreign' ), null, true );
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' ) . '.000';
	cpsd_int_insert( $platform->table( 'cpms_jobs' ), array( 'type' => 'foreign.during', 'payload_json' => (string) wp_json_encode( array( 'marker' => 'foreign' ) ), 'status' => 'queued', 'priority' => 5, 'attempts' => 0, 'max_attempts' => 3, 'run_after' => $now, 'created_at' => $now ) );
	cpsd_int_insert( $platform->table( 'cpms_operational_logs' ), array( 'level' => 'info', 'message' => 'FOREIGN_DURING_DEMO', 'context_json' => (string) wp_json_encode( array( 'request_id' => 'shared', 'ip' => '127.0.0.1', 'created_at' => $now ) ), 'request_id' => 'shared', 'created_at' => $now ) );
	$wpdb->insert( $platform->table( 'cpms_rate_limits' ), array( 'window_key' => 'otp-ip:foreign-during', 'window_id' => 1, 'hits' => 1, 'window_sec' => 3600 ) );
	cpsd_int_check( is_int( $foreign_user ) && is_int( $foreign_post ) && $foreign_comment > 0, 'foreign activity was created', $checks );

	$reloaded = $manifest->load();
	$preview  = $teardown->preview( $reloaded );
	cpsd_int_check( (bool) $preview['ok'], 'teardown preview passes: ' . implode( ' | ', (array) $preview['problems'] ), $checks );
	$baseline_snapshot = (array) $reloaded['baseline'];
	$teardown_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_int_check( (bool) $teardown_result['ok'], 'authorized teardown succeeds', $checks );

	$preserved = $baseline->verify( $baseline_snapshot );
	cpsd_int_check( (bool) $preserved['ok'], 'baseline rows preserved: ' . implode( ' | ', (array) $preserved['problems'] ), $checks );
	$chain_after = $audit_guard->verify_chain();
	cpsd_int_check( (bool) $chain_after['ok'], 'full audit chain valid after teardown', $checks );
	cpsd_int_check( (int) $chain_after['checked'] >= (int) $chain_before['checked'], 'audit chain only grew (no deletion)', $checks );
	cpsd_int_check( null !== get_userdata( $seeded['user_a'] ) && null !== get_userdata( $seeded['user_b'] ) && null !== get_userdata( $foreign_user ), 'existing and foreign users preserved', $checks );
	cpsd_int_check( get_post( $seeded['post_id'] ) instanceof WP_Post && get_post( $foreign_post ) instanceof WP_Post, 'existing and foreign posts preserved', $checks );
	cpsd_int_check( null !== get_comment( $seeded['comment_id'] ) && null !== get_comment( $foreign_comment ), 'existing and foreign comments preserved', $checks );
	cpsd_int_check( null !== $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $platform->table( 'cpms_invoices' ) . ' WHERE id = %d', $seeded['invoice_id'] ) ), 'existing invoice preserved', $checks );
	cpsd_int_check( null !== $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $platform->table( 'cpms_jobs' ) . ' WHERE id = %d', $seeded['job_id'] ) ), 'existing queue job preserved', $checks );
	cpsd_int_check( null === $manifest->load(), 'scoped manifest removed after clean teardown', $checks );

	echo (string) wp_json_encode( array( 'ok' => true, 'checks' => $checks, 'fixture_id' => (string) $active['fixture_id'], 'audit_checked' => (int) $chain_after['checked'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode( array( 'ok' => false, 'checks' => $checks, 'error' => get_class( $error ) . ': ' . $error->getMessage() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}
