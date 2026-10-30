<?php
/**
 * Real-WordPress integration acceptance for the scoped demo.
 *
 * Run inside a disposable CI WordPress with:
 *   wp eval-file tools/disposable-demo-installer/tests/scoped-integration.php
 *
 * This is not a Chromium/browser test. It seeds a genuinely non-empty,
 * multi-Clinic site (real-looking users, posts, comments and two pre-existing
 * CPMS organizations/Clinics with patients, appointments, visits, invoices and
 * payments), provisions the scoped demo, verifies real WP authentication per persona,
 * interleaves foreign activity, tears down, then proves:
 *
 *  - the sealed baseline of every pre-existing row is byte-identical,
 *  - the global append-only audit chain only grew and its pre-existing prefix
 *    digest is unchanged,
 *  - the three retained parent rows are inert and unreachable through the
 *    production lifecycle / listing / public-directory surfaces,
 *  - the host site's implicit-Clinic scope semantics did not change.
 *
 * @package CpmsDisposableDemo
 */

use ClinicCore\Application\Clinic\ClinicLifecyclePolicy;
use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use CpmsDemo\Credentials;
use CpmsDemo\Dashboard;
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
 * One pre-existing (non-demo) Clinic with a full clinical/financial trail.
 *
 * @param Platform $platform Adapter.
 * @param string $tag Unique seed tag.
 * @param string $label Human label.
 * @param int $user_id Owning clinician WP user.
 * @param int $patient_user WordPress user behind the patient row.
 * @return array<string,int>
 */
function cpsd_int_seed_clinic( Platform $platform, string $tag, string $label, int $user_id, int $patient_user ): array {
	$now   = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$table = static fn ( string $short ): string => $platform->table( $short );
	$short = substr( $tag, 0, 12 );

	$org_id = cpsd_int_insert(
		$table( 'cpms_organizations' ),
		array( 'name' => 'Existing Org ' . $label, 'slug' => $tag . '-org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$clinic_id = cpsd_int_insert(
		$table( 'cpms_clinics' ),
		array( 'organization_id' => $org_id, 'name' => 'Existing Clinic ' . $label, 'slug' => $tag . '-clinic', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$location_id = cpsd_int_insert(
		$table( 'cpms_locations' ),
		array( 'clinic_id' => $clinic_id, 'name' => 'Existing Location ' . $label, 'slug' => $tag . '-loc', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	$clinician_id = cpsd_int_insert(
		$table( 'cpms_clinicians' ),
		array( 'clinic_id' => $clinic_id, 'wp_user_id' => $user_id, 'full_name' => 'Existing Clinician ' . $label, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	$slot_id = cpsd_int_insert(
		$table( 'cpms_schedule_slots' ),
		array( 'clinic_id' => $clinic_id, 'clinician_id' => $clinician_id, 'location_id' => $location_id, 'slot_date' => gmdate( 'Y-m-d', time() + 86400 ), 'slot_time' => '10:00:00', 'duration_min' => 20, 'capacity' => 1, 'booked_count' => 0, 'held_count' => 0, 'is_open' => 1, 'generated_from' => 'manual', 'created_at' => $now, 'updated_at' => $now )
	);
	$patient_id = cpsd_int_insert(
		$table( 'cpms_patients' ),
		array( 'clinic_id' => $clinic_id, 'mrn' => $tag . '-mrn', 'first_name' => 'Existing', 'last_name' => 'Patient ' . $label, 'mobile' => '0912000000' . (string) ( 10 + (int) $clinic_id % 80 ), 'gender' => 'unknown', 'created_at' => $now, 'updated_at' => $now )
	);
	$appointment_id = cpsd_int_insert(
		$table( 'cpms_appointments' ),
		array( 'clinic_id' => $clinic_id, 'location_id' => $location_id, 'reference_code' => $short . '-ap' . (string) $clinic_id, 'clinician_id' => $clinician_id, 'patient_id' => $patient_id, 'slot_id' => $slot_id, 'slot_date' => gmdate( 'Y-m-d', time() + 86400 ), 'slot_time' => '10:00:00', 'wp_user_id' => $patient_user, 'reason' => 'pre-existing', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now )
	);
	$visit_id = cpsd_int_insert(
		$table( 'cpms_visits' ),
		array( 'clinic_id' => $clinic_id, 'location_id' => $location_id, 'clinician_id' => $clinician_id, 'patient_id' => $patient_id, 'appointment_id' => $appointment_id, 'source' => 'scheduled', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d', time() + 86400 ), 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now )
	);
	$invoice_id = cpsd_int_insert(
		$table( 'cpms_invoices' ),
		array( 'clinic_id' => $clinic_id, 'invoice_number' => $short . '-iv' . (string) $clinic_id, 'patient_id' => $patient_id, 'visit_id' => $visit_id, 'status' => 'open', 'subtotal' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000, 'currency' => 'IRR', 'paid_amount' => 0, 'balance' => 1000, 'issued_by_wp_user_id' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	$payment_id = cpsd_int_insert(
		$table( 'cpms_payments' ),
		array( 'clinic_id' => $clinic_id, 'payment_number' => $short . '-py' . (string) $clinic_id, 'invoice_id' => $invoice_id, 'patient_id' => $patient_id, 'amount' => 1000, 'method' => 'cash', 'transaction_ref' => 'EXISTING', 'idempotency_key' => $tag . '-idem-' . (string) $clinic_id, 'status' => 'captured', 'paid_at' => $now, 'received_by_wp_user_id' => 1, 'created_at' => $now )
	);

	return array(
		'organization_id' => $org_id,
		'clinic_id'       => $clinic_id,
		'location_id'     => $location_id,
		'clinician_id'    => $clinician_id,
		'patient_id'      => $patient_id,
		'appointment_id'  => $appointment_id,
		'visit_id'        => $visit_id,
		'invoice_id'      => $invoice_id,
		'payment_id'      => $payment_id,
	);
}

/**
 * Seed legitimate pre-existing records, including a second (non-demo) Clinic so
 * the host already has multi-Clinic semantics, plus global/audit/queue/rate
 * limit rows that must survive untouched.
 *
 * @param Platform $platform CPMS adapter.
 * @return array<string,mixed>
 */
function cpsd_int_seed( Platform $platform ): array {
	global $wpdb;
	$now   = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$table = static fn ( string $short ): string => $platform->table( $short );
	$tag   = 'existing-int-' . bin2hex( random_bytes( 4 ) );

	$user_a = wp_insert_user( array( 'user_login' => $tag . '-a', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-a@example.test', 'role' => 'subscriber' ) );
	$user_b = wp_insert_user( array( 'user_login' => $tag . '-b', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-b@example.test', 'role' => 'subscriber' ) );
	$user_c = wp_insert_user( array( 'user_login' => $tag . '-c', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-c@example.test', 'role' => 'subscriber' ) );
	if ( ! is_int( $user_a ) || ! is_int( $user_b ) || ! is_int( $user_c ) ) {
		throw new RuntimeException( 'seed users failed' );
	}
	$post_id = wp_insert_post( array( 'post_title' => 'Existing post', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'pre-existing' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'seed post failed' );
	}
	$comment_id = wp_insert_comment( array( 'comment_post_ID' => $post_id, 'comment_author' => 'Existing', 'comment_content' => 'pre-existing comment', 'comment_approved' => 1, 'user_id' => $user_a ) );

	$clinic_a = cpsd_int_seed_clinic( $platform, $tag . '-a', 'A', $user_b, $user_a );
	$clinic_b = cpsd_int_seed_clinic( $platform, $tag . '-b', 'B', $user_c, $user_a );

	$job_id   = cpsd_int_insert( $table( 'cpms_jobs' ), array( 'type' => 'foreign.test', 'payload_json' => (string) wp_json_encode( array( 'marker' => 'foreign' ) ), 'status' => 'queued', 'priority' => 5, 'attempts' => 0, 'max_attempts' => 3, 'run_after' => $now, 'created_at' => $now ) );
	$oplog_id = cpsd_int_insert( $table( 'cpms_operational_logs' ), array( 'level' => 'info', 'message' => 'FOREIGN_BASELINE_LOG', 'context_json' => (string) wp_json_encode( array( 'request_id' => 'shared', 'ip' => '127.0.0.1', 'created_at' => $now ) ), 'request_id' => 'shared', 'created_at' => $now ) );
	$wpdb->insert( $table( 'cpms_rate_limits' ), array( 'window_key' => 'otp-ip:foreign-baseline', 'window_id' => 1, 'hits' => 1, 'window_sec' => 3600 ) );
	App::audit()->log( 'CPSD_INTEGRATION_BASELINE', array( 'wp_user_id' => 1, 'role' => 'administrator' ), 'system', null, null, null, null, array( 'marker' => 'foreign' ), null, true );

	return array(
		'user_a'         => $user_a,
		'user_b'         => $user_b,
		'user_c'         => $user_c,
		'post_id'        => (int) $post_id,
		'comment_id'     => (int) $comment_id,
		'job_id'         => $job_id,
		'oplog_id'       => $oplog_id,
		'clinic_a'       => $clinic_a,
		'clinic_b'       => $clinic_b,
		'foreign_marker' => $tag,
	);
}

/**
 * Production implicit-Clinic outcome, as a stable string.
 *
 * @return string
 */
function cpsd_int_system_scope_outcome(): string {
	SystemClinicResolver::flush();
	try {
		SystemClinicResolver::resolve( App::db() );
	} catch ( Throwable $error ) {
		return 'explicit:' . get_class( $error );
	}

	return 'implicit_single_clinic';
}

$checks = array();
$report = array();
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
	cpsd_int_check( true, 'seeded non-empty multi-Clinic WordPress/CPMS fixture', $checks );
	cpsd_int_check( 2 <= $platform->count_all( 'cpms_clinics' ), 'host already has multi-Clinic semantics before provisioning', $checks );

	// ---------- Stage 2: pre-provision evidence capture ----------
	$chain_before     = $audit_guard->verify_chain();
	cpsd_int_check( (bool) $chain_before['ok'], 'baseline audit chain is valid', $checks );
	$audit_max_before = $platform->max_id( 'cpms_audit_logs' );
	$prefix_before    = $audit_guard->baseline_prefix( $audit_max_before );
	$host_before      = array(
		'clinics'        => $platform->count_all( 'cpms_clinics' ),
		'organizations'  => $platform->count_all( 'cpms_organizations' ),
		'locations'      => $platform->count_all( 'cpms_locations' ),
		'active_clinics' => $platform->count_where( $platform->table( 'cpms_clinics' ), 'status = %s', array( 'active' ) ),
	);
	$scope_outcome_before = cpsd_int_system_scope_outcome();
	$report['audit'] = array(
		'rows_before'      => (int) $chain_before['checked'],
		'max_id_before'    => $audit_max_before,
		'prefix_rows'      => (int) $prefix_before['count'],
		'prefix_digest'    => (string) $prefix_before['digest'],
	);
	$report['host_before']       = $host_before;
	$report['system_scope_before'] = $scope_outcome_before;
	cpsd_int_check( 'implicit_single_clinic' !== $scope_outcome_before, 'host does not rely on implicit single-Clinic scope (pretest)', $checks );

	$manifest->enable_mode( $admin_id );
	$state = $preflight->run();
	cpsd_int_check( (bool) $state['ok'], 'scoped preflight passes on non-empty multi-Clinic site: ' . implode( ',', (array) $state['blocking'] ), $checks );

	$result = $provisioner->provision( $admin_id );
	$active = $manifest->load();
	cpsd_int_check( is_array( $active ) && 'active' === (string) $active['status'], 'scoped fixture is active', $checks );
	cpsd_int_check( count( Credentials::PERSONA_ORDER ) === count( (array) $active['ids']['user_ids'] ), 'one account per persona is recorded', $checks );
	cpsd_int_check( isset( $active['host_before']['clinic_count'] ) && (int) $active['host_before']['clinic_count'] === $host_before['clinics'], 'manifest seals host counters read before provisioning', $checks );
	foreach ( Credentials::PERSONA_ORDER as $persona ) {
		$login = $manifest->fixture_prefix() . $persona . '-' . (string) $active['fixture_id'];
		$auth  = wp_authenticate( $login, (string) $result['credentials'][ $persona ] );
		cpsd_int_check( $auth instanceof WP_User, 'real WP authentication: ' . $persona, $checks );
	}
	cpsd_int_check( 'log' === (string) $platform->get_setting( (int) $active['ids']['clinic_id'], 'sms.provider' ), 'synthetic clinic uses log SMS provider', $checks );
	cpsd_int_check( isset( $active['baseline'], $active['owned'], $active['audit_chain_at_seal'] ), 'baseline and ownership map are sealed', $checks );
	cpsd_int_check( (bool) $active['audit_chain_at_seal']['ok'], 'audit chain valid at seal', $checks );

	// ---------- The one-click Owner dashboard and the recovery verdict ----------
	// Both are new surfaces with a single job: say what is true and offer the one
	// next safe action. They are exercised here on the real database, on a real
	// sealed fixture, so "the dashboard says ready" is evidence and not a mock.
	cpsd_int_check( class_exists( Dashboard::class ) && class_exists( ScopedRecovery::class ), 'one-click dashboard and recovery classes are loaded', $checks );
	$assessment = ( new ScopedRecovery( $env, $platform, $manifest ) )->assess( $manifest->load() );
	cpsd_int_check( ScopedRecovery::VERDICT_ACTIVE === (string) $assessment['verdict'], 'recovery verdict: the sealed fixture is active', $checks );
	cpsd_int_check( true === (bool) $assessment['sealed'], 'recovery verdict: the seal is proven from real rows and the ledger', $checks );
	Dashboard::reset_state();
	ob_start();
	Dashboard::render();
	$dash_html = (string) ob_get_clean();
	$report['dashboard'] = array(
		'state'   => 'ready',
		'bytes'   => strlen( $dash_html ),
		'portals' => substr_count( $dash_html, 'data-cpsd-persona-portal=' ),
	);
	cpsd_int_check( str_contains( $dash_html, 'data-cpms-demo-state="ready"' ), 'one-click dashboard renders the ready state', $checks );
	cpsd_int_check( count( Credentials::PERSONA_ORDER ) === (int) $report['dashboard']['portals'], 'one-click dashboard maps every persona', $checks );
	cpsd_int_check( ! str_contains( $dash_html, 'name="cpdd_confirm"' ), 'one-click dashboard asks for no typed phrase', $checks );
	cpsd_int_check( ! str_contains( $dash_html, 'data-cpsd-form="teardown"' ), 'one-click dashboard never offers the destructive action', $checks );

	// Two different Clinics coexist: the synthetic one must not shadow the real ones.
	$anchor_clinic = (int) $active['ids']['clinic_id'];
	$anchor_org    = (int) $active['ids']['organization_id'];
	cpsd_int_check( $anchor_clinic !== (int) $seeded['clinic_a']['clinic_id'] && $anchor_clinic !== (int) $seeded['clinic_b']['clinic_id'], 'synthetic Clinic is a distinct row from both pre-existing Clinics', $checks );
	cpsd_int_check( $platform->count_where( $platform->table( 'cpms_clinics' ), 'status = %s', array( 'active' ) ) === $host_before['active_clinics'] + 1, 'exactly one active Clinic was added during the demo', $checks );

	// Iran V1 (schema 0030) demo-required features, exercised on the sealed
	// fixture BEFORE teardown so their rows are subject to the ownership proof.
	require_once __DIR__ . '/features-messaging-pricing.php';
	try {
		$report['features'] = cpdd_feature_suite( $platform, $active, 'scoped' );
		cpsd_int_check( true, 'iran-v1 feature suite passed (messaging privacy + visit pricing)', $checks );
	} catch ( Throwable $feature_error ) {
		cpsd_int_check( false, 'iran-v1 feature suite: ' . $feature_error->getMessage(), $checks );
	}

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

	// ---------- Stage 2: post-teardown proofs ----------
	$preserved = $baseline->verify( $baseline_snapshot );
	cpsd_int_check( (bool) $preserved['ok'], 'every pre-existing row is byte-identical after teardown (' . (int) $preserved['checked'] . ' tables): ' . implode( ' | ', (array) $preserved['problems'] ), $checks );

	$chain_after = $audit_guard->verify_chain();
	cpsd_int_check( (bool) $chain_after['ok'], 'full audit chain valid after teardown', $checks );
	cpsd_int_check( (int) $chain_after['checked'] > (int) $chain_before['checked'], 'audit chain only grew (no audit row deleted): before=' . (int) $chain_before['checked'] . ' after=' . (int) $chain_after['checked'], $checks );
	$prefix_after = $audit_guard->baseline_prefix( $audit_max_before );
	cpsd_int_check( (int) $prefix_after['count'] === (int) $prefix_before['count'], 'pre-existing audit row count unchanged', $checks );
	cpsd_int_check( hash_equals( (string) $prefix_before['digest'], (string) $prefix_after['digest'] ), 'pre-existing audit prefix digest identical (no mutation, no reordering)', $checks );
	$report['audit']['rows_after']     = (int) $chain_after['checked'];
	$report['audit']['prefix_digest_after'] = (string) $prefix_after['digest'];

	// Inert anchors.
	$clinic_anchor = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . $platform->table( 'cpms_clinics' ) . ' WHERE id = %d', $anchor_clinic ) );
	$org_anchor    = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . $platform->table( 'cpms_organizations' ) . ' WHERE id = %d', $anchor_org ) );
	cpsd_int_check( null !== $clinic_anchor && 'suspended' === (string) $clinic_anchor->status, 'synthetic clinic preserved as suspended audit anchor', $checks );
	cpsd_int_check( null !== $org_anchor && 'suspended' === (string) $org_anchor->status, 'synthetic organization preserved as suspended audit anchor', $checks );
	$anchor_locations = (array) ( $active['ids']['location_ids'] ?? array() );
	$inactive_locations = 0;
	foreach ( $anchor_locations as $location_id ) {
		$is_active = $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM ' . $platform->table( 'cpms_locations' ) . ' WHERE id = %d', (int) $location_id ) );
		if ( null === $is_active || 0 === (int) $is_active ) {
			++$inactive_locations;
		}
	}
	cpsd_int_check( $inactive_locations === count( $anchor_locations ), 'all synthetic locations are inactive (' . count( $anchor_locations ) . ' rows)', $checks );

	// Production lifecycle gate: a suspended Clinic without lifecycle evidence is
	// UNVERIFIED and refuses new participation (fail-closed), so the anchor can
	// never be used to enrol staff or accept new demand.
	$anchor_state = ClinicLifecyclePolicy::state_of( App::db(), $anchor_clinic );
	cpsd_int_check( ClinicLifecyclePolicy::STATE_UNVERIFIED === $anchor_state || ClinicLifecyclePolicy::STATE_SUSPENDED_OPERATIONAL === $anchor_state, 'production lifecycle policy classifies the anchor as non-accepting: ' . $anchor_state, $checks );
	cpsd_int_check( false === ClinicLifecyclePolicy::accepts_new_membership( $anchor_state ), 'production lifecycle policy refuses new membership on the anchor', $checks );
	try {
		ClinicLifecyclePolicy::assert_accepts_new_membership( App::db(), $anchor_clinic );
		cpsd_int_check( false, 'production lifecycle assertion refuses the anchor', $checks );
	} catch ( Throwable $lifecycle_error ) {
		cpsd_int_check( true, 'production lifecycle assertion refuses the anchor (' . get_class( $lifecycle_error ) . ')', $checks );
	}

	// Production admin/REST listing surfaces cannot reach the anchor.
	$provisioning = App::organization_clinic_provisioning_service();
	$manager_orgs = $provisioning->organizations_for_manager( $admin_id );
	$org_ids      = array_map( static fn ( array $org ): int => (int) $org['id'], $manager_orgs );
	cpsd_int_check( ! in_array( $anchor_org, $org_ids, true ), 'suspended anchor Organization is absent from organizations_for_manager()', $checks );
	$org_listing = $provisioning->clinics_for_organization( $admin_id, $anchor_org );
	cpsd_int_check( array() === $org_listing, 'clinics_for_organization() returns nothing for the suspended anchor Organization', $checks );
	$manager_clinics = $provisioning->clinics_for_manager( $admin_id );
	$listed_clinic_ids = array_map( static fn ( array $clinic ): int => (int) $clinic['id'], $manager_clinics );
	cpsd_int_check( ! in_array( $anchor_clinic, $listed_clinic_ids, true ), 'anchor Clinic is absent from clinics_for_manager()', $checks );

	// Reactivation through the single production status writer must be refused.
	$reactivation_blocked = false;
	try {
		$provisioning->set_clinic_status( $admin_id, $anchor_org, $anchor_clinic, true );
	} catch ( Throwable $status_error ) {
		$reactivation_blocked = true;
	}
	cpsd_int_check( $reactivation_blocked, 'existing admin reactivation path is refused for the anchor', $checks );
	$status_after_attempt = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $platform->table( 'cpms_clinics' ) . ' WHERE id = %d', $anchor_clinic ) );
	cpsd_int_check( 'suspended' === (string) $status_after_attempt, 'anchor Clinic is still suspended after the reactivation attempt', $checks );

	// Public directory (front-end/API listing surface) does not expose it.
	$directory = new PublicClinicDirectory();
	cpsd_int_check( false === $directory->is_listed( $anchor_clinic ), 'anchor Clinic is not listed by the public Clinic directory', $checks );

	// Host counters and implicit-scope semantics.
	$host_after = array(
		'clinics'        => $platform->count_all( 'cpms_clinics' ),
		'organizations'  => $platform->count_all( 'cpms_organizations' ),
		'locations'      => $platform->count_all( 'cpms_locations' ),
		'active_clinics' => $platform->count_where( $platform->table( 'cpms_clinics' ), 'status = %s', array( 'active' ) ),
	);
	cpsd_int_check( $host_after['active_clinics'] === $host_before['active_clinics'], 'active Clinic count returned to its pre-provision value', $checks );
	cpsd_int_check( $host_after['clinics'] === $host_before['clinics'] + 1, 'host Clinic count grew by exactly the one inert anchor', $checks );
	cpsd_int_check( $host_after['clinics'] > 1, 'host never falls back to single-Clinic implicit scope', $checks );
	$scope_outcome_after = cpsd_int_system_scope_outcome();
	cpsd_int_check( $scope_outcome_after === $scope_outcome_before, 'production implicit-scope outcome is unchanged: ' . $scope_outcome_before, $checks );
	$report['host_after']        = $host_after;
	$report['system_scope_after'] = $scope_outcome_after;

	// Pre-existing data survives at the WordPress and CPMS level too.
	cpsd_int_check( null !== get_userdata( $seeded['user_a'] ) && null !== get_userdata( $seeded['user_b'] ) && null !== get_userdata( $seeded['user_c'] ) && null !== get_userdata( $foreign_user ), 'existing and foreign users preserved', $checks );
	cpsd_int_check( get_post( $seeded['post_id'] ) instanceof WP_Post && get_post( $foreign_post ) instanceof WP_Post, 'existing and foreign posts preserved', $checks );
	cpsd_int_check( null !== get_comment( $seeded['comment_id'] ) && null !== get_comment( $foreign_comment ), 'existing and foreign comments preserved', $checks );
	foreach ( array( 'a', 'b' ) as $suffix ) {
		$seed = $seeded[ 'clinic_' . $suffix ];
		foreach ( array( 'invoice_id' => 'cpms_invoices', 'payment_id' => 'cpms_payments', 'visit_id' => 'cpms_visits', 'appointment_id' => 'cpms_appointments', 'patient_id' => 'cpms_patients' ) as $key => $short ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $platform->table( $short ) . ' WHERE id = %d', (int) $seed[ $key ] ) );
			cpsd_int_check( null !== $row, 'pre-existing ' . $short . ' #' . (int) $seed[ $key ] . ' preserved (clinic ' . $suffix . ')', $checks );
		}
	}
	cpsd_int_check( null !== $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $platform->table( 'cpms_jobs' ) . ' WHERE id = %d', $seeded['job_id'] ) ), 'existing queue job preserved', $checks );
	cpsd_int_check( null !== $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $platform->table( 'cpms_operational_logs' ) . ' WHERE id = %d', $seeded['oplog_id'] ) ), 'existing operational log preserved', $checks );
	cpsd_int_check( null === $manifest->load(), 'scoped manifest removed after clean teardown', $checks );

	$tombstone = $manifest->tombstone();
	cpsd_int_check( is_array( $tombstone ) && true === (bool) ( $tombstone['clean'] ?? false ), 'sealed clean tombstone recorded', $checks );
	cpsd_int_check( isset( $tombstone['host_before']['clinic_count'], $tombstone['host_after']['clinic_count'] ), 'tombstone records host before/after counters', $checks );
	$report['tombstone_host'] = array(
		'before' => (array) ( $tombstone['host_before'] ?? array() ),
		'after'  => (array) ( $tombstone['host_after'] ?? array() ),
	);

	echo (string) wp_json_encode(
		array(
			'ok'          => true,
			'checks'      => $checks,
			'fixture_id'  => (string) $active['fixture_id'],
			'audit_checked' => (int) $chain_after['checked'],
			'evidence'    => $report,
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'     => false,
			'checks' => $checks,
			'evidence' => $report,
			'error'  => get_class( $error ) . ': ' . $error->getMessage(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}
