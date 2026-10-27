<?php
/**
 * Scoped-demo runtime safety drills on a real WordPress + real database.
 *
 * Run inside the disposable CI WordPress (after scoped-integration.php):
 *   wp eval-file tools/disposable-demo-installer/tests/scoped-recovery.php
 *
 * Every scenario below is executed for real against MySQL/MariaDB; nothing is
 * simulated except the deliberate fault that interrupts an operation:
 *
 *   R1  tampered manifest is refused on load, on the preview fed the payload as
 *       stored, on teardown execution and on re-provisioning - for four separate
 *       vectors (three identity fields and a forged seal), with a row-count proof
 *       that nothing was added or deleted
 *   R2  repeated teardown is idempotent across three consecutive runs, keeps the
 *       tombstone and the preserved audit rows, and leaves every pre-existing
 *       host row byte-identical
 *   R3  interrupted provisioning leaves a flagged, recoverable state
 *       (fault injected at four different provisioning stages)
 *   R4  interrupted teardown is flagged with its stage and safely resumable
 *   R5  concurrency: a second database connection holding the site mutex, and a
 *       running queue runner, both refuse the operation with no partial state
 *   R6  cross-Clinic isolation through the real REST stack (four directions)
 *   R7  SIGKILL during provisioning: the lock is released, the state is
 *       flagged, and the recovery teardown completes
 *
 * @package CpmsDisposableDemo
 */

use ClinicCore\Bootstrap\App;
use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Exception as DemoException;
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
 * @param list<string> $checks Accumulator.
 * @return void
 */
function cpsd_rec_check( bool $ok, string $label, array &$checks ): void {
	$checks[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/**
 * @param string $table Table.
 * @param array<string,mixed> $data Row.
 * @return int Insert id.
 */
function cpsd_rec_insert( string $table, array $data ): int {
	global $wpdb;
	$ok = $wpdb->insert( $table, $data );
	if ( false === $ok || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'seed insert failed: ' . $table . ' ' . (string) $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Ensure the host is multi-Clinic (the scoped preflight refuses a one-Clinic or
 * zero-Clinic host by design) and return two pre-existing Clinic identities.
 *
 * @param Platform $platform Adapter.
 * @return array<string,mixed>
 */
function cpsd_rec_ensure_host( Platform $platform ): array {
	global $wpdb;
	$table = static fn ( string $short ): string => $platform->table( $short );
	$now   = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$tag   = 'recovery-host-' . bin2hex( random_bytes( 3 ) );

	$rows = $wpdb->get_results( 'SELECT id, organization_id, status FROM ' . $table( 'cpms_clinics' ) . ' ORDER BY id ASC LIMIT 4' );
	if ( count( (array) $rows ) >= 2 ) {
		$first  = (array) $rows[0];
		$second = (array) $rows[1];

		return array(
			'seeded'           => false,
			'clinic_a_id'      => (int) $first['id'],
			'clinic_b_id'      => (int) $second['id'],
			'org_a_id'         => (int) $first['organization_id'],
			'org_b_id'         => (int) $second['organization_id'],
		);
	}

	$org_ids = array();
	for ( $i = 1; $i <= 2; ++$i ) {
		$org_ids[] = cpsd_rec_insert(
			$table( 'cpms_organizations' ),
			array( 'name' => 'Recovery Host Org ' . $i, 'slug' => $tag . '-org-' . $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
		);
	}
	$clinic_ids    = array();
	$location_ids  = array();
	foreach ( $org_ids as $index => $org_id ) {
		$clinic_ids[] = cpsd_rec_insert(
			$table( 'cpms_clinics' ),
			array( 'organization_id' => $org_id, 'name' => 'Recovery Host Clinic ' . ( $index + 1 ), 'slug' => $tag . '-clinic-' . ( $index + 1 ), 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
		);
	}
	foreach ( $clinic_ids as $index => $clinic_id ) {
		$location_ids[] = cpsd_rec_insert(
			$table( 'cpms_locations' ),
			array( 'clinic_id' => $clinic_id, 'name' => 'Recovery Host Location ' . ( $index + 1 ), 'slug' => $tag . '-loc-' . ( $index + 1 ), 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
		);
	}

	return array(
		'seeded'      => true,
		'clinic_a_id' => $clinic_ids[0],
		'clinic_b_id' => $clinic_ids[1],
		'org_a_id'    => $org_ids[0],
		'org_b_id'    => $org_ids[1],
	);
}

/**
 * Build a fresh service graph (they are stateless wrappers).
 *
 * @param Env $env Env.
 * @param Platform $platform Adapter.
 * @param ScopedManifest $manifest Manifest.
 * @param ScopedAuditGuard $audit_guard Guard.
 * @param ScopedBaseline $baseline Baseline.
 * @param Credentials $credentials Credentials.
 * @return array<string,mixed>
 */
function cpsd_rec_services( Env $env, Platform $platform, ScopedManifest $manifest, ScopedAuditGuard $audit_guard, ScopedBaseline $baseline, Credentials $credentials ): array {
	$preflight = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );

	return array(
		'preflight'   => $preflight,
		'provisioner' => new ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard ),
		'teardown'    => new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline ),
	);
}

/**
 * Count remaining synthetic accounts (marker of a partial fixture).
 *
 * @param Env $env Env.
 * @return int
 */
function cpsd_rec_synthetic_users( Env $env ): int {
	$count = 0;
	foreach ( $env->all_users() as $user ) {
		if ( 0 === strpos( (string) ( $user['login'] ?? '' ), 'cpsd-' ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Row-level fingerprint of the PRE-EXISTING host Clinics.
 *
 * The baseline proves the tracked global classes are unchanged; this proves the
 * actual clinical/financial rows of the host Clinics are byte-identical, which
 * is the property a User actually cares about after a drill has run.
 *
 * @param Platform $platform Adapter.
 * @param array<string,mixed> $host Host identities.
 * @return string sha256 of every host-owned row, or '' when nothing to compare.
 */
function cpsd_rec_host_digest( Platform $platform, array $host ): string {
	$clinic_ids = array_values( array_unique( array_filter( array( (int) ( $host['clinic_a_id'] ?? 0 ), (int) ( $host['clinic_b_id'] ?? 0 ) ) ) ) );
	if ( array() === $clinic_ids ) {
		return '';
	}
	$placeholders = implode( ',', array_fill( 0, count( $clinic_ids ), '%d' ) );
	$tables       = array(
		'cpms_clinics',
		'cpms_locations',
		'cpms_clinicians',
		'cpms_schedule_slots',
		'cpms_patients',
		'cpms_patient_user_links',
		'cpms_appointments',
		'cpms_visits',
		'cpms_invoices',
		'cpms_payments',
	);
	$digest       = array();
	foreach ( $tables as $short ) {
		if ( ! $platform->table_exists( $short ) ) {
			continue;
		}
		$full = $platform->table( $short );
		$cols = $platform->columns( $full );
		// cpms_clinics has no clinic_id of its own; every other table is tenant-scoped.
		$column = in_array( 'clinic_id', $cols, true ) ? 'clinic_id' : ( 'cpms_clinics' === $short ? 'id' : '' );
		if ( '' === $column || ! in_array( 'id', $cols, true ) ) {
			continue;
		}
		$digest[ $short ] = (array) $platform->fetch_all(
			'SELECT * FROM `' . $full . '` WHERE `' . $column . '` IN (' . $placeholders . ') ORDER BY id ASC',
			$clinic_ids
		);
	}

	return hash( 'sha256', (string) wp_json_encode( $digest ) );
}

/**
 * Deterministic per-table row counts inside the fixture's own Clinic.
 *
 * Used to prove that a refused operation added nothing and deleted nothing,
 * not merely that it returned an error.
 *
 * @param Platform $platform Adapter.
 * @param array<string,mixed> $live Verified manifest.
 * @return array<string,int>
 */
function cpsd_rec_fixture_row_counts( Platform $platform, array $live ): array {
	$clinic_id = (int) ( $live['ids']['clinic_id'] ?? 0 );
	$tables    = array(
		'cpms_clinics',
		'cpms_organizations',
		'cpms_locations',
		'cpms_clinicians',
		'cpms_schedule_slots',
		'cpms_slot_holds',
		'cpms_patients',
		'cpms_patient_user_links',
		'cpms_appointments',
		'cpms_visits',
		'cpms_invoices',
		'cpms_payments',
		'cpms_otp_tokens',
	);
	$counts    = array();
	foreach ( $tables as $short ) {
		if ( ! $platform->table_exists( $short ) ) {
			continue;
		}
		$full = $platform->table( $short );
		$cols = $platform->columns( $full );
		if ( $clinic_id > 0 && in_array( 'clinic_id', $cols, true ) ) {
			$counts[ $short ] = count(
				(array) $platform->fetch_all( 'SELECT id FROM `' . $full . '` WHERE clinic_id = %d', array( $clinic_id ) )
			);
			continue;
		}
		$counts[ $short ] = (int) $platform->count_all( $short );
	}

	return $counts;
}

/**
 * Sum the per-table delete counters of a teardown result.
 *
 * @param array<string,mixed> $result Teardown result.
 * @return int
 */
function cpsd_rec_deleted_total( array $result ): int {
	$total = 0;
	foreach ( (array) ( $result['deleted'] ?? array() ) as $count ) {
		$total += (int) $count;
	}

	return $total;
}

$checks = array();
$report = array();
try {
	if ( ! class_exists( App::class ) || ! class_exists( Platform::class ) ) {
		throw new RuntimeException( 'CPMS/companion not active' );
	}
	global $wpdb;
	wp_set_current_user( 1 );
	$admin_id    = 1;
	$env         = new Env();
	$platform    = new Platform();
	$manifest    = new ScopedManifest( $env );
	$audit_guard = new ScopedAuditGuard( $platform );
	$baseline    = new ScopedBaseline( $env, $platform );
	$credentials = new Credentials( $env );
	$services    = cpsd_rec_services( $env, $platform, $manifest, $audit_guard, $baseline, $credentials );
	$host        = cpsd_rec_ensure_host( $platform );
	$checks[]    = 'INFO host clinics=' . $platform->count_all( 'cpms_clinics' ) . ' seeded=' . ( $host['seeded'] ? 'yes' : 'no' );

	// ---------------------------------------------------------------- R1 tamper
	$host_digest_before = cpsd_rec_host_digest( $platform, $host );
	$checks[]           = 'INFO host row digest=' . substr( $host_digest_before, 0, 16 ) . ' clinics=' . count( (array) array_filter( array( $host['clinic_a_id'], $host['clinic_b_id'] ) ) );

	$manifest->enable_mode( $admin_id );
	$services['provisioner']->provision( $admin_id );
	$live        = $manifest->load();
	cpsd_rec_check( is_array( $live ) && 'active' === (string) $live['status'], 'R1 fixture provisioned for the tamper drill', $checks );
	$fixture_rows = cpsd_rec_fixture_row_counts( $platform, $live );

	// Every id in the sealed payload is HMAC-covered, so each of these must fail
	// closed in every consumer: load(), the teardown preview (fed the payload AS
	// STORED, which is what the admin page does), teardown execution, and the
	// re-provisioning preflight.
	$tamper_vectors = array(
		'ids.clinic_id'        => static function ( array $payload ): array {
			$payload['ids']['clinic_id'] = (int) $payload['ids']['clinic_id'] + 5000;
			return $payload;
		},
		'ids.invoice_id'       => static function ( array $payload ): array {
			$payload['ids']['invoice_id'] = (int) ( $payload['ids']['invoice_id'] ?? 0 ) + 5000;
			return $payload;
		},
		'ids.user_ids.patient' => static function ( array $payload ): array {
			$payload['ids']['user_ids']['patient'] = (int) ( $payload['ids']['user_ids']['patient'] ?? 0 ) + 5000;
			return $payload;
		},
		'mac'                  => static function ( array $payload ): array {
			// The payload stays untouched; only the seal is forged. This proves the
			// HMAC itself is what is checked, not just field-to-field agreement.
			$payload['mac'] = str_repeat( '0', 64 );
			return $payload;
		},
	);
	$tamper_refusals = 0;
	foreach ( $tamper_vectors as $label => $mutate ) {
		$env->option_set( 'cpsd_manifest', $mutate( $live ) );

		$refused_load = false;
		try {
			$manifest->load();
		} catch ( DemoException $error ) {
			$refused_load = 'MANIFEST_TAMPERED' === $error->demo_code();
		}
		cpsd_rec_check( $refused_load, 'R1 tampered ' . $label . ': refused on load (MANIFEST_TAMPERED)', $checks );

		$stored = $env->option_get( 'cpsd_manifest', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored_preview = $services['teardown']->preview( $stored );
		cpsd_rec_check(
			false === (bool) $stored_preview['ok'] && in_array( 'MANIFEST_TAMPERED', (array) $stored_preview['problems'], true ),
			'R1 tampered ' . $label . ': the preview refuses the payload as stored',
			$checks
		);

		$refused_execute = false;
		try {
			$services['teardown']->execute( $stored, $admin_id );
		} catch ( DemoException $error ) {
			$refused_execute = in_array( $error->demo_code(), array( 'TEARDOWN_BLOCKED', 'MANIFEST_TAMPERED' ), true );
		}
		cpsd_rec_check( $refused_execute, 'R1 tampered ' . $label . ': refused by teardown execution', $checks );

		$preflight_state = $services['preflight']->run();
		cpsd_rec_check(
			in_array( 'other_manifest', (array) $preflight_state['blocking'], true ),
			'R1 tampered ' . $label . ': the preflight still refuses a second fixture',
			$checks
		);
		$reprovision_code = '';
		try {
			$services['provisioner']->provision( $admin_id );
		} catch ( DemoException $error ) {
			$reprovision_code = $error->demo_code();
		} catch ( Throwable $error ) {
			$reprovision_code = 'UNEXPECTED:' . get_class( $error );
		}
		// A tampered payload is still an existing manifest, so the provisioner
		// refuses it at its own guard (ALREADY_PROVISIONED) before it can reach the
		// preflight refusal (PREFLIGHT). Both are refusals; the point is that no
		// provisioning run starts.
		cpsd_rec_check(
			in_array( $reprovision_code, array( 'ALREADY_PROVISIONED', 'PREFLIGHT' ), true ),
			'R1 tampered ' . $label . ': re-provisioning is refused (' . $reprovision_code . ')',
			$checks
		);
		cpsd_rec_check(
			cpsd_rec_fixture_row_counts( $platform, $live ) === $fixture_rows,
			'R1 tampered ' . $label . ': neither a refusal nor a re-provision attempt added or deleted a row',
			$checks
		);
		++$tamper_refusals;
	}
	$report['tamper_vectors_refused'] = $tamper_refusals;

	// Restore the sealed manifest and tear down for real.
	$env->option_set( 'cpsd_manifest', $live );
	$restored = $manifest->load();
	cpsd_rec_check( is_array( $restored ) && $manifest->verify( $restored ), 'R1 the sealed manifest is intact again after the tamper drill', $checks );
	$clean = $services['teardown']->execute( $restored, $admin_id );
	cpsd_rec_check( true === (bool) $clean['ok'], 'R1 authorized teardown after the tamper drill succeeds', $checks );
	cpsd_rec_check( 0 === cpsd_rec_synthetic_users( $env ), 'R1 no synthetic account survives the clean teardown', $checks );

	// ------------------------------------------------- R2 repeated teardown
	// Three consecutive runs over an already-torn-down fixture: every run must
	// report success, delete nothing, and leave the preserved audit rows in place.
	foreach ( array( 2, 3 ) as $run ) {
		$repeat = $services['teardown']->execute( $live, $admin_id );
		cpsd_rec_check( true === (bool) $repeat['ok'], 'R2 run ' . $run . ': a repeated teardown completes without error', $checks );
		cpsd_rec_check(
			0 === cpsd_rec_deleted_total( $repeat ),
			'R2 run ' . $run . ': deletes nothing (idempotent): ' . (string) wp_json_encode( $repeat['deleted'] ),
			$checks
		);
		cpsd_rec_check(
			(int) ( $repeat['tombstone']['preserved_audit'] ?? 0 ) > 0,
			'R2 run ' . $run . ': the preserved audit rows still exist (' . (int) ( $repeat['tombstone']['preserved_audit'] ?? 0 ) . ')',
			$checks
		);
		cpsd_rec_check( true === (bool) ( $repeat['tombstone']['clean'] ?? false ), 'R2 run ' . $run . ': the tombstone records a clean teardown', $checks );
		$repeat_baseline = $baseline->verify( (array) $live['baseline'] );
		cpsd_rec_check( true === (bool) $repeat_baseline['ok'], 'R2 run ' . $run . ': pre-existing baseline still intact: ' . implode( ' | ', (array) $repeat_baseline['problems'] ), $checks );
		$repeat_chain = $audit_guard->verify_chain();
		cpsd_rec_check( true === (bool) $repeat_chain['ok'], 'R2 run ' . $run . ': audit chain valid', $checks );
		cpsd_rec_check(
			cpsd_rec_host_digest( $platform, $host ) === $host_digest_before,
			'R2 run ' . $run . ': every pre-existing host row is byte-identical',
			$checks
		);
	}

	$tombstone_after = $manifest->tombstone();
	cpsd_rec_check(
		is_array( $tombstone_after ) && (string) ( $tombstone_after['fixture_id'] ?? '' ) === (string) $live['fixture_id'],
		'R2 the repeated runs leave the tombstone of THIS fixture, not of another one',
		$checks
	);
	cpsd_rec_check( true === $manifest->had_clean_teardown(), 'R2 the site still reports a verified clean teardown', $checks );
	cpsd_rec_check( null === $manifest->load() && false === $manifest->exists(), 'R2 no manifest is resurrected by the repeats', $checks );

	$empty_preview = $services['teardown']->preview( $live );
	cpsd_rec_check( true === (bool) $empty_preview['ok'], 'R2 the preview of the already-removed fixture is clean: ' . implode( ' | ', (array) $empty_preview['problems'] ), $checks );
	$empty_scope = (array) ( $empty_preview['scope']['tables'] ?? array() );
	foreach ( array( 'cpms_patients', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_payments', 'cpms_schedule_slots', 'cpms_slot_holds', 'cpms_patient_user_links', 'cpms_otp_tokens', 'cpms_sms_messages' ) as $short ) {
		cpsd_rec_check(
			array() === (array) ( $empty_scope[ $short ] ?? array() ),
			'R2 no ' . $short . ' row of the fixture is left to delete',
			$checks
		);
	}

	// ------------------------------------- R3 interrupted provisioning (4 points)
	$fault_points = array( 'provision:users_partial', 'provision:organization', 'provision:onboarding', 'provision:clinical' );
	$recovered    = 0;
	foreach ( $fault_points as $point ) {
		$manifest->enable_mode( $admin_id );
		$env->set_fault_injector(
			static function ( string $reached ) use ( $point ): void {
				if ( $reached === $point ) {
					throw new RuntimeException( 'INJECTED_FAULT:' . $point );
				}
			}
		);
		$interrupted = false;
		try {
			$services['provisioner']->provision( $admin_id );
		} catch ( Throwable $error ) {
			$interrupted = true;
		}
		$env->set_fault_injector( null );
		cpsd_rec_check( $interrupted, 'R3 ' . $point . ': provisioning was interrupted', $checks );

		$flagged = $manifest->load();
		cpsd_rec_check(
			is_array( $flagged ) && 'failed' === (string) $flagged['status'] && '' !== (string) ( $flagged['failed_stage'] ?? '' ),
			'R3 ' . $point . ': manifest is clearly flagged failed (stage=' . (string) ( $flagged['failed_stage'] ?? '?' ) . ')',
			$checks
		);
		cpsd_rec_check( $manifest->has_pending_baseline(), 'R3 ' . $point . ': sealed pre-provisioning baseline survives for recovery', $checks );

		$teardown_after_fault = $manifest->load();
		$recovery_preview     = $services['teardown']->preview( $teardown_after_fault );
		cpsd_rec_check( true === (bool) $recovery_preview['ok'], 'R3 ' . $point . ': recovery preview passes without a sealed manifest baseline: ' . implode( ' | ', (array) $recovery_preview['problems'] ), $checks );
		$recovery = $services['teardown']->execute( $manifest->load(), $admin_id );
		cpsd_rec_check( true === (bool) $recovery['ok'], 'R3 ' . $point . ': recovery teardown completes cleanly', $checks );
		cpsd_rec_check( 0 === cpsd_rec_synthetic_users( $env ), 'R3 ' . $point . ': no synthetic account survives recovery', $checks );
		cpsd_rec_check( false === $manifest->has_pending_baseline(), 'R3 ' . $point . ': pending baseline is cleared after recovery', $checks );
		++$recovered;
	}
	$report['interrupted_provisioning_points_recovered'] = $recovered;

	// ------------------------------------------ R4 interrupted teardown (stages)
	$teardown_points = array( 'scoped_teardown:after-global', 'scoped_teardown:after-tables', 'scoped_teardown:before-post-verify' );
	$resumed         = 0;
	foreach ( $teardown_points as $point ) {
		$manifest->enable_mode( $admin_id );
		$env->set_fault_injector( null );
		$services['provisioner']->provision( $admin_id );
		$live_round = $manifest->load();
		cpsd_rec_check( is_array( $live_round ) && 'active' === (string) $live_round['status'], 'R4 ' . $point . ': fixture provisioned', $checks );

		$env->set_fault_injector(
			static function ( string $reached ) use ( $point ): void {
				if ( $reached === $point ) {
					throw new RuntimeException( 'INJECTED_FAULT:' . $point );
				}
			}
		);
		$interrupted = false;
		try {
			$services['teardown']->execute( $manifest->load(), $admin_id );
		} catch ( Throwable $error ) {
			$interrupted = true;
		}
		$env->set_fault_injector( null );
		cpsd_rec_check( $interrupted, 'R4 ' . $point . ': teardown was interrupted', $checks );

		$failed = $manifest->load();
		cpsd_rec_check( is_array( $failed ) && 'teardown_failed' === (string) $failed['status'], 'R4 ' . $point . ': manifest records teardown_failed', $checks );
		cpsd_rec_check( '' !== (string) ( $failed['failed_stage'] ?? '' ), 'R4 ' . $point . ': failed stage is recorded (' . (string) ( $failed['failed_stage'] ?? '?' ) . ')', $checks );
		cpsd_rec_check( true !== ( $failed['ok'] ?? false ) && true !== ( $failed['clean'] ?? false ), 'R4 ' . $point . ': a failed teardown never claims a clean result', $checks );

		$retry = $services['teardown']->execute( $manifest->load(), $admin_id );
		cpsd_rec_check( true === (bool) $retry['ok'], 'R4 ' . $point . ': retry resumes and completes', $checks );
		$retry_baseline = $baseline->verify( (array) $live_round['baseline'] );
		cpsd_rec_check( true === (bool) $retry_baseline['ok'], 'R4 ' . $point . ': retry left every pre-existing row untouched', $checks );
		$retry_chain = $audit_guard->verify_chain();
		cpsd_rec_check( true === (bool) $retry_chain['ok'], 'R4 ' . $point . ': audit chain valid after the resumed teardown', $checks );
		cpsd_rec_check( 0 === cpsd_rec_synthetic_users( $env ), 'R4 ' . $point . ': no synthetic account survives the resumed teardown', $checks );
		++$resumed;
	}
	$report['interrupted_teardown_stages_resumed'] = $resumed;

	// -------------------------------------------------------- R5 concurrency
	$lock_name = $env->lock_name();
	$second    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$held      = $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	cpsd_rec_check( '1' === (string) $held || 1 === $held, 'R5 a second database connection holds the site mutex', $checks );
	$manifest->enable_mode( $admin_id );
	$concurrent_code = '';
	try {
		$services['provisioner']->provision( $admin_id );
	} catch ( DemoException $error ) {
		$concurrent_code = $error->demo_code();
	}
	cpsd_rec_check( 'CONCURRENT_OPERATION' === $concurrent_code, 'R5 concurrent provisioning is serialized out (' . $concurrent_code . ')', $checks );
	cpsd_rec_check( null === $manifest->load() && 0 === cpsd_rec_synthetic_users( $env ), 'R5 the refused concurrent run left no partial state', $checks );
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

	$queue_held = $second->get_var( "SELECT GET_LOCK('cpms_jobs_tick', 0)" );
	cpsd_rec_check( '1' === (string) $queue_held || 1 === $queue_held, 'R5 a queue runner lock is held on the second connection', $checks );
	$queue_state = $services['preflight']->run();
	cpsd_rec_check( in_array( 'queue_lock', (array) $queue_state['blocking'], true ), 'R5 a running queue runner blocks the preflight', $checks );
	$queue_code = '';
	try {
		$services['provisioner']->provision( $admin_id );
	} catch ( DemoException $error ) {
		$queue_code = $error->demo_code();
	}
	cpsd_rec_check( 'PREFLIGHT' === $queue_code, 'R5 provisioning is refused while the queue runner is active (' . $queue_code . ')', $checks );
	cpsd_rec_check( null === $manifest->load() && 0 === cpsd_rec_synthetic_users( $env ), 'R5 the refused queue-blocked run left no partial state', $checks );
	cpsd_rec_check( false === $manifest->has_pending_baseline(), 'R5 a preflight refusal does not leave a pending baseline behind', $checks );
	$second->get_var( "SELECT RELEASE_LOCK('cpms_jobs_tick')" );
	$second->close();

	// ------------------------------------------------ R6 cross-Clinic REST drill
	$manifest->enable_mode( $admin_id );
	$services['provisioner']->provision( $admin_id );
	$live = $manifest->load();
	wp_cache_flush();
	$demo_clinic    = (int) $live['ids']['clinic_id'];
	$demo_invoice   = (int) ( $live['ids']['invoice_id'] ?? 0 );
	$demo_accountant = (int) ( $live['ids']['user_ids']['accountant'] ?? 0 );
	$demo_reception  = (int) ( $live['ids']['user_ids']['receptionist'] ?? 0 );
	$demo_patient    = (int) ( $live['ids']['patient_id'] ?? 0 );
	$demo_patient_wp = (int) ( $live['ids']['user_ids']['patient'] ?? 0 );
	$demo_payment   = (int) ( $live['ids']['payment_id'] ?? 0 );

	$foreign = $platform->fetch_row( 'SELECT id, clinic_id FROM `' . $platform->table( 'cpms_invoices' ) . '` WHERE clinic_id <> %d ORDER BY id ASC LIMIT 1', array( $demo_clinic ) );
	$foreign_patient = $platform->fetch_row( 'SELECT id, clinic_id FROM `' . $platform->table( 'cpms_patients' ) . '` WHERE clinic_id <> %d ORDER BY id ASC LIMIT 1', array( $demo_clinic ) );
	cpsd_rec_check( null !== $foreign && null !== $foreign_patient, 'R6 a pre-existing foreign Clinic invoice and patient exist', $checks );
	cpsd_rec_check( $demo_invoice > 0 && $demo_accountant > 0 && $demo_reception > 0 && $demo_patient > 0 && $demo_patient_wp > 0, 'R6 synthetic invoice/personas are recorded', $checks );
	// `FinanceService::recordPayment()` reports `payment_id`; a reader that asks for
	// `id` gets 0 and the synthetic payment silently leaves the ownership map.
	cpsd_rec_check( $demo_payment > 0, 'R6 the synthetic payment is recorded in the ownership map', $checks );
	if ( $demo_payment > 0 ) {
		$payment_row = $platform->fetch_row( 'SELECT id, invoice_id FROM `' . $platform->table( 'cpms_payments' ) . '` WHERE id = %d', array( $demo_payment ) );
		cpsd_rec_check( null !== $payment_row && (int) ( $payment_row['invoice_id'] ?? 0 ) === $demo_invoice, 'R6 the recorded payment belongs to the synthetic invoice', $checks );
	}

	/**
	 * Dispatch a real REST request as one user and return [status, code].
	 *
	 * @param int $user_id Acting user.
	 * @param string $method HTTP method.
	 * @param string $route Route path.
	 * @param array<string,mixed> $params Query/body params.
	 * @return array{status:int,code:string,route:string}
	 */
	$rest = static function ( int $user_id, string $method, string $route, array $params = array() ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$code     = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';

		return array( 'status' => (int) $response->get_status(), 'code' => $code, 'route' => $route );
	};

	// 6a. Demo accountant reading a foreign Clinic invoice with their own scope.
	$cross_read = $rest( $demo_accountant, 'GET', '/clinic/v1/invoices/' . (int) $foreign['id'], array( 'clinic_id' => $demo_clinic ) );
	cpsd_rec_check( in_array( $cross_read['status'], array( 403, 404 ), true ), 'R6 demo accountant cannot read a foreign Clinic invoice (HTTP ' . $cross_read['status'] . ')', $checks );

	// 6b. Demo accountant claiming the foreign Clinic as their own scope.
	$cross_scope = $rest( $demo_accountant, 'GET', '/clinic/v1/invoices/' . (int) $foreign['id'], array( 'clinic_id' => (int) $foreign['clinic_id'] ) );
	cpsd_rec_check( in_array( $cross_scope['status'], array( 403, 404 ), true ), 'R6 demo accountant cannot claim the foreign Clinic scope (HTTP ' . $cross_scope['status'] . ')', $checks );

	// 6c. Positive control: the same actor can read its own Clinic invoice.
	$own_read = $rest( $demo_accountant, 'GET', '/clinic/v1/invoices/' . $demo_invoice, array( 'clinic_id' => $demo_clinic ) );
	cpsd_rec_check( 200 === $own_read['status'], 'R6 the same actor reads its own Clinic invoice (HTTP ' . $own_read['status'] . ')', $checks );

	// 6d. Demo receptionist cannot read a foreign Clinic patient record.
	$cross_patient = $rest( $demo_reception, 'GET', '/clinic/v1/patients/' . (int) $foreign_patient['id'], array( 'clinic_id' => $demo_clinic ) );
	cpsd_rec_check( in_array( $cross_patient['status'], array( 403, 404 ), true ), 'R6 demo receptionist cannot read a foreign Clinic patient (HTTP ' . $cross_patient['status'] . ')', $checks );

	// 6e. Demo patient cannot read another Clinic's patient record.
	$patient_cross = $rest( $demo_patient_wp, 'GET', '/clinic/v1/patients/' . (int) $foreign_patient['id'], array( 'clinic_id' => (int) $foreign_patient['clinic_id'] ) );
	cpsd_rec_check( in_array( $patient_cross['status'], array( 403, 404 ), true ), 'R6 demo patient cannot read another Clinic patient (HTTP ' . $patient_cross['status'] . ')', $checks );
	$report['cross_clinic'] = array(
		'foreign_invoice' => $cross_read['status'] . '/' . $cross_scope['status'],
		'own_invoice'     => $own_read['status'],
		'foreign_patient' => $cross_patient['status'] . '/' . $patient_cross['status'],
	);

	// Restore a clean state for the last drill.
	$services['teardown']->execute( $manifest->load(), $admin_id );
	cpsd_rec_check( null === $manifest->load(), 'R6 fixture removed after the REST drill', $checks );

	// ------------------------------------------- R7 SIGKILL during provisioning
	if ( ! function_exists( 'proc_open' ) || ! function_exists( 'posix_kill' ) ) {
		$checks[] = 'SKIP R7 (proc_open/posix not available in this PHP build)';
		$report['sigkill'] = 'skipped';
	} else {
		$manifest->enable_mode( $admin_id );
		$child_file  = rtrim( sys_get_temp_dir(), '/\\' ) . '/cpsd-child-' . bin2hex( random_bytes( 4 ) ) . '.php';
		$child_body = '<?php' . "\n"
			. 'require ' . var_export( ABSPATH . 'wp-load.php', true ) . ";\n"
			. 'wp_set_current_user( 1 );' . "\n"
			. '$env = new \CpmsDemo\Env();' . "\n"
			. '$platform = new \CpmsDemo\Platform();' . "\n"
			. '$manifest = new \CpmsDemo\ScopedManifest( $env );' . "\n"
			. '$guard = new \CpmsDemo\ScopedAuditGuard( $platform );' . "\n"
			. '$baseline = new \CpmsDemo\ScopedBaseline( $env, $platform );' . "\n"
			. '$preflight = new \CpmsDemo\ScopedPreflight( $env, $platform, $manifest, $guard, $baseline );' . "\n"
			. '$credentials = new \CpmsDemo\Credentials( $env );' . "\n"
			. '$provisioner = new \CpmsDemo\ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $guard );' . "\n"
			. '$env->set_fault_injector( static function ( string $point ): void {' . "\n"
			. '    if ( "provision:onboarding" === $point ) { posix_kill( getmypid(), 9 ); }' . "\n"
			. '} );' . "\n"
			. '$provisioner->provision( 1 );' . "\n"
			. 'echo "CHILD_SURVIVED";' . "\n";
		file_put_contents( $child_file, $child_body );
		$descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child_file ), $descriptors, $pipes );
		cpsd_rec_check( is_resource( $process ), 'R7 provisioning child process was started', $checks );
		$child_out = (string) stream_get_contents( $pipes[1] );
		$child_err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		@unlink( $child_file );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort temp cleanup
		wp_cache_flush();
		cpsd_rec_check( false === strpos( $child_out, 'CHILD_SURVIVED' ), 'R7 the child process died mid-provisioning (exit=' . (string) $exit_code . ')', $checks );

		$killed = $manifest->load();
		cpsd_rec_check(
			is_array( $killed ) && in_array( (string) $killed['status'], array( 'provisioning', 'failed' ), true ),
			'R7 a killed run leaves a flagged manifest (status=' . ( is_array( $killed ) ? (string) $killed['status'] : 'missing' ) . ')',
			$checks
		);
		cpsd_rec_check( $manifest->has_pending_baseline(), 'R7 the killed run kept its sealed pre-provisioning baseline', $checks );
		$lock_free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) );
		cpsd_rec_check( '1' === (string) $lock_free || 1 === $lock_free, 'R7 the database released the site mutex when the process died', $checks );
		$recovery = $services['teardown']->execute( $manifest->load(), $admin_id );
		cpsd_rec_check( true === (bool) $recovery['ok'], 'R7 recovery teardown completes after the kill', $checks );
		cpsd_rec_check( 0 === cpsd_rec_synthetic_users( $env ), 'R7 no synthetic account survives the kill recovery', $checks );
		$report['sigkill'] = array( 'exit_code' => $exit_code, 'stderr_bytes' => strlen( $child_err ) );
	}

	// ------------------------------------------------------ final host invariant
	// Every drill above (provisioning, four tamper vectors, three teardowns,
	// four interrupted provisions, three interrupted teardowns, concurrency,
	// cross-Clinic REST, SIGKILL) must have left the pre-existing Clinics
	// byte-identical at row level - not merely "still present".
	$host_digest_after = cpsd_rec_host_digest( $platform, $host );
	cpsd_rec_check(
		'' !== $host_digest_before && $host_digest_after === $host_digest_before,
		'R8 every pre-existing host row is byte-identical after all drills',
		$checks
	);
	$report['host_digest'] = array(
		'before' => substr( $host_digest_before, 0, 16 ),
		'after'  => substr( $host_digest_after, 0, 16 ),
	);

	echo (string) wp_json_encode(
		array(
			'ok'       => true,
			'checks'   => $checks,
			'evidence' => $report,
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'       => false,
			'checks'   => $checks,
			'evidence' => $report,
			'error'    => get_class( $error ) . ': ' . $error->getMessage(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}
