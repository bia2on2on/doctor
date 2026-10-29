<?php
/**
 * A–J teardown data-safety drill on the real database.
 *
 * Two modes, run around a real authorized teardown so that BOTH halves are
 * proven without weakening anything:
 *
 *   CPSD_TS_MODE=plant   (fixture ACTIVE) plants adversarial rows, asserts what
 *                        the teardown would and would not claim, and records a
 *                        fingerprint of every row that must survive.
 *   CPSD_TS_MODE=teardown runs the AUTHORIZED teardown through the product's
 *                        own service (preview must be clean first), so the
 *                        half that deletes is the real one, not a simulation.
 *   CPSD_TS_MODE=verify  (after the authorized teardown) asserts that every
 *                        foreign row is byte-identical, that host data is
 *                        untouched, that demo data is gone, and that the audit
 *                        chain still verifies.
 *
 * Cases:
 *   A  a row created through the REAL production REST API by a synthetic
 *      persona is registered by the request recorder and attributed
 *   B  an unregistered membership for a REAL administrator inside the demo
 *      Clinic - even one whose inviter is synthetic - is refused
 *   C  a foreign Clinic/patient/visit/invoice/payment that overlaps the demo
 *      Organization and synthetic user ids is never claimed and survives
 *   D  a row in the demo Clinic whose numeric id equals a SEALED id from a
 *      DIFFERENT table gains nothing from that collision
 *   E  a row inside the demo Clinic whose ownership cannot be proven blocks
 *      the teardown before any destructive stage
 *   F  an additional demo Clinic created through the demo's own path is
 *      registered, protected from real users, and its demo rows are claimed
 *   G  real users keep their authority in FOREIGN tenants while the demo runs
 *   H  corrupt ownership metadata fails closed and destroys nothing
 *   I  a refused/corrupt operation leaves the fixture and unrelated data intact
 *   J  (verified by the lane's deactivate/delete step; re-asserted here that the
 *      CPMS tables and host data still exist after the teardown)
 *
 * Every adversarial fixture proves its OWN creation succeeded before any
 * security conclusion is drawn from it.
 *
 * @package CpmsDisposableDemo
 */

use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Platform;
use CpmsDemo\ScopedAuditGuard;
use CpmsDemo\ScopedBaseline;
use CpmsDemo\ScopedClinics;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedOwnership;
use CpmsDemo\ScopedTenantGuard;
use CpmsDemo\ScopedTeardown;

defined( 'ABSPATH' ) || exit;

$cpsd_ts_mode = (string) ( getenv( 'CPSD_TS_MODE' ) ?: 'plant' );
$cpsd_ts_file = (string) ( getenv( 'CPSD_TS_EVIDENCE' ) ?: '/tmp/cpsd-teardown-safety.json' );

/**
 * @param bool         $ok Condition.
 * @param string       $label Label.
 * @param list<string> $checks Accumulator.
 * @param string       $detail Detail.
 * @return void
 */
function cpsd_ts_check( bool $ok, string $label, array &$checks, string $detail = '' ): void {
	$checks[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( '' === $detail ? '' : ' (' . $detail . ')' );
	if ( ! $ok ) {
		throw new RuntimeException( $label . ( '' === $detail ? '' : ' :: ' . $detail ) );
	}
}

/**
 * @param string              $table Full table name.
 * @param array<string,mixed> $data Row.
 * @return int Insert id.
 */
function cpsd_ts_insert( string $table, array $data ): int {
	global $wpdb;
	$ok = $wpdb->insert( $table, $data );
	if ( false === $ok || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'insert failed: ' . $table . ' ' . (string) $wpdb->last_error );
	}

	return (int) $wpdb->insert_id;
}

/**
 * @param string $table Full table name.
 * @param int    $id Row id.
 * @return array<string,mixed>|null
 */
function cpsd_ts_row( string $table, int $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from a fixed list; id prepared.

	return is_array( $row ) ? $row : null;
}

/**
 * Deterministic fingerprint of a row, used to prove it was not touched.
 *
 * @param string $table Full table name.
 * @param int    $id Row id.
 * @return string '' when the row is gone.
 */
function cpsd_ts_hash( string $table, int $id ): string {
	$row = cpsd_ts_row( $table, $id );

	return null === $row ? '' : md5( (string) wp_json_encode( $row ) );
}

/**
 * Is this row part of what the teardown would delete right now?
 *
 * @param array<string,mixed> $preview Preview result.
 * @param string              $short Short table name.
 * @param int                 $id Row id.
 * @return bool
 */
function cpsd_ts_claimed( array $preview, string $short, int $id ): bool {
	$ids = (array) ( $preview['scope']['tables'][ $short ] ?? array() );

	return in_array( $id, array_map( 'intval', $ids ), true );
}

/**
 * Does any preview problem contain this needle?
 *
 * @param array<string,mixed> $preview Preview result.
 * @param string              $needle Needle.
 * @return bool
 */
function cpsd_ts_problem( array $preview, string $needle ): bool {
	foreach ( (array) ( $preview['problems'] ?? array() ) as $problem ) {
		if ( false !== strpos( (string) $problem, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Plant a complete foreign tenant, deliberately overlapping the demo's own
 * Organization and synthetic user references: none of that is ownership.
 *
 * @param Platform $platform Adapter.
 * @param string   $table_prefix Table name resolver.
 * @param int      $org_id Demo Organization id.
 * @param int      $overlap_user Synthetic user id to overlap with.
 * @param string   $role_key A real CPMS role key.
 * @return array<string,int>
 */
function cpsd_ts_plant_foreign( Platform $platform, callable $table_prefix, int $org_id, int $overlap_user, string $role_key ): array {
	unset( $table_prefix );
	$t    = static fn ( string $short ): string => $platform->table( $short );
	$now  = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$slug = 'ts-foreign-' . strtolower( bin2hex( random_bytes( 3 ) ) );
	$ids  = array();

	// Its OWN Organization. A Clinic that merely names the DEMO Organization is a
	// different case: it cannot be proven demo-owned and the teardown refuses it
	// (scenario E below, and the portal drill's P3/P4). A genuinely foreign
	// tenant is one the demo never touched, and it must not even be a candidate.
	$ids['organization'] = cpsd_ts_insert(
		$t( 'cpms_organizations' ),
		array( 'name' => 'سازمان غیردمو ' . $slug, 'slug' => $slug . '-org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$org_id = $ids['organization'];

	$ids['clinic'] = cpsd_ts_insert(
		$t( 'cpms_clinics' ),
		array( 'organization_id' => $org_id, 'name' => 'غیردمو ' . $slug, 'slug' => $slug, 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['location'] = cpsd_ts_insert(
		$t( 'cpms_locations' ),
		array( 'clinic_id' => $ids['clinic'], 'name' => 'شعبه غیردمو', 'slug' => $slug . '-loc', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['patient'] = cpsd_ts_insert(
		$t( 'cpms_patients' ),
		array( 'clinic_id' => $ids['clinic'], 'mrn' => $slug . '-mrn', 'first_name' => 'بیمار', 'last_name' => 'غیردمو', 'mobile' => '0936' . (string) random_int( 1000000, 9999999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['visit'] = cpsd_ts_insert(
		$t( 'cpms_visits' ),
		array( 'clinic_id' => $ids['clinic'], 'location_id' => $ids['location'], 'clinician_id' => null, 'patient_id' => $ids['patient'], 'source' => 'walk_in', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['invoice'] = cpsd_ts_insert(
		$t( 'cpms_invoices' ),
		array( 'clinic_id' => $ids['clinic'], 'invoice_number' => 'TSINV' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'patient_id' => $ids['patient'], 'visit_id' => $ids['visit'], 'status' => 'open', 'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'currency' => 'IRR', 'paid_amount' => 0, 'balance' => 10, 'issued_by_wp_user_id' => $overlap_user, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['payment'] = cpsd_ts_insert(
		$t( 'cpms_payments' ),
		array( 'clinic_id' => $ids['clinic'], 'payment_number' => 'TSPAY' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'invoice_id' => $ids['invoice'], 'patient_id' => $ids['patient'], 'amount' => 5, 'method' => 'cash', 'idempotency_key' => $slug . '-idem', 'status' => 'captured', 'paid_at' => $now, 'received_by_wp_user_id' => $overlap_user, 'created_at' => $now )
	);
	$membership = array(
		'clinic_id'  => $ids['clinic'],
		'wp_user_id' => $overlap_user,
		'role_key'   => $role_key,
		'scope_mode' => 'clinic',
		'status'     => 'active',
		'is_primary' => 0,
		'created_at' => $now,
		'updated_at' => $now,
	);
	$ids['membership'] = cpsd_ts_insert( $t( 'cpms_clinic_memberships' ), $membership );

	return $ids;
}

$checks  = array();
$report  = array();
$planted = array();

try {
	if ( ! class_exists( ScopedTeardown::class ) ) {
		throw new RuntimeException( 'companion/standalone plugin inactive' );
	}
	if ( ! in_array( $cpsd_ts_mode, array( 'plant', 'teardown', 'verify' ), true ) ) {
		throw new RuntimeException( 'usage: CPSD_TS_MODE=plant|teardown|verify' );
	}
	global $wpdb;
	wp_set_current_user( 1 );
	$env         = new Env();
	$platform    = new Platform();
	$manifest    = new ScopedManifest( $env );
	$audit_guard = new ScopedAuditGuard( $platform );
	$baseline    = new ScopedBaseline( $env, $platform );
	$teardown    = new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline );
	$ownership   = new ScopedOwnership( $env, $manifest );
	$t           = static fn ( string $short ): string => $platform->table( $short );

	if ( 'verify' === $cpsd_ts_mode ) {
		// ------------------------------------------------------------- verify
		$recorded = is_file( $cpsd_ts_file ) ? json_decode( (string) file_get_contents( $cpsd_ts_file ), true ) : null;
		if ( ! is_array( $recorded ) ) {
			throw new RuntimeException( 'no plant evidence at ' . $cpsd_ts_file );
		}
		$survivors = (array) ( $recorded['planted'] ?? array() );
		$intact    = 0;
		foreach ( $survivors as $key => $entry ) {
			$table = (string) ( $entry['table'] ?? '' );
			$id    = (int) ( $entry['id'] ?? 0 );
			$hash  = (string) ( $entry['hash'] ?? '' );
			$now   = cpsd_ts_hash( $table, $id );
			cpsd_ts_check( '' !== $now, 'C: planted foreign row still exists: ' . $key, $checks, $table . '#' . $id );
			cpsd_ts_check( $now === $hash, 'C: planted foreign row is byte-identical: ' . $key, $checks, $table . '#' . $id );
			++$intact;
		}
		$report['survivors'] = $intact;
		cpsd_ts_check( $intact >= 8, 'C: the whole planted foreign tenant survived the teardown', $checks, 'rows=' . $intact );
		// The demo's own data is gone, and the tables are still there (J).
		$demo_clinic = (int) ( $recorded['demo_clinic'] ?? 0 );
		cpsd_ts_check( $demo_clinic > 0, 'J: the drill recorded the demo Clinic', $checks );
		$demo_anchor = cpsd_ts_row( $t( 'cpms_clinics' ), $demo_clinic );
		cpsd_ts_check( is_array( $demo_anchor ), 'J: the demo Clinic anchor is RETAINED (never dropped) after the authorized teardown', $checks, (string) $demo_clinic );
		cpsd_ts_check( 'active' !== (string) ( $demo_anchor['status'] ?? '' ), 'J: the retained demo Clinic anchor is inert, not active', $checks, (string) ( $demo_anchor['status'] ?? '' ) );
		$demo_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $t( 'cpms_patients' ) . '` WHERE clinic_id = ' . $demo_clinic ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; integer literal.
		cpsd_ts_check( 0 === $demo_left, 'J: the demo Clinic holds no leftover patient rows', $checks, (string) $demo_left );
		$tables = array();
		foreach ( array( 'cpms_patients', 'cpms_invoices', 'cpms_payments', 'cpms_visits', 'cpms_clinic_memberships' ) as $short ) {
			$tables[ $short ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $t( $short ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table list.
		}
		cpsd_ts_check( count( $tables ) === 5 && $tables['cpms_patients'] >= 1, 'J: every CPMS table still exists and holds the foreign rows', $checks, (string) wp_json_encode( $tables ) );
		$report['tables'] = $tables;
		$audit            = $audit_guard->verify_chain();
		cpsd_ts_check( true === (bool) ( $audit['ok'] ?? false ), 'the append-only audit chain still verifies', $checks, (string) ( $audit['error'] ?? '' ) );
		// The synthetic accounts and the real host users are untouched.
		$host_user = (int) ( $recorded['host_user'] ?? 0 );
		if ( $host_user > 0 ) {
			cpsd_ts_check( null !== get_userdata( $host_user ), 'a pre-existing real user survived the teardown', $checks, (string) $host_user );
		}
		echo (string) wp_json_encode( array( 'ok' => true, 'mode' => $cpsd_ts_mode, 'checks' => $checks, 'evidence' => $report ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
		exit( 0 );
	}

	if ( 'teardown' === $cpsd_ts_mode ) {
		// ------------------------------------------------------------ teardown
		// The REAL service, with the REAL actor, after a clean preview: this is
		// the half of the drill that deletes, and it must delete only what the
		// drill just proved attributable.
		$live = $manifest->load();
		if ( ! is_array( $live ) || 'active' !== (string) ( $live['status'] ?? '' ) ) {
			throw new RuntimeException( 'no ACTIVE scoped fixture to tear down' );
		}
		$before = $teardown->preview( $live );
		cpsd_ts_check( true === (bool) $before['ok'], 'the fixture previews clean before the authorized teardown', $checks, implode( ' | ', (array) ( $before['problems'] ?? array() ) ) );
		$host_user_check = (int) ( $live['ids']['admin_id'] ?? 1 );
		cpsd_ts_check( null !== get_userdata( $host_user_check > 0 ? $host_user_check : 1 ), 'precondition: the acting host user exists', $checks, (string) $host_user_check );
		$result = $teardown->execute( $live, $host_user_check > 0 ? $host_user_check : 1 );
		cpsd_ts_check( true === (bool) ( $result['ok'] ?? false ), 'the authorized teardown completed', $checks, (string) wp_json_encode( $result['remaining'] ?? array() ) );
		cpsd_ts_check( array() === (array) ( $result['remaining'] ?? array() ), 'the authorized teardown reports no remaining demo rows', $checks, (string) wp_json_encode( $result['remaining'] ?? array() ) );
		$deleted = (array) ( $result['deleted'] ?? array() );
		cpsd_ts_check( (int) array_sum( array_map( 'intval', $deleted ) ) > 0, 'the authorized teardown really deleted the demo rows', $checks, (string) wp_json_encode( $deleted ) );
		$report['teardown'] = array( 'ok' => true, 'deleted' => $deleted, 'tombstone' => (array) ( $result['tombstone'] ?? array() ) );
		file_put_contents( $cpsd_ts_file . '.teardown', (string) wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		echo (string) wp_json_encode( array( 'ok' => true, 'mode' => $cpsd_ts_mode, 'checks' => $checks, 'evidence' => $report['teardown'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
		exit( 0 );
	}

	// ---------------------------------------------------------------- plant
	$live = $manifest->load();
	if ( ! is_array( $live ) || 'active' !== (string) ( $live['status'] ?? '' ) ) {
		throw new RuntimeException( 'no ACTIVE scoped fixture; run the browser/PHP provisioning step first' );
	}
	$demo_clinic = (int) ( $live['ids']['clinic_id'] ?? 0 );
	$demo_org    = (int) ( $live['ids']['organization_id'] ?? 0 );
	$user_map    = (array) ( $live['ids']['user_ids'] ?? array() );
	$reception   = (int) ( $user_map['receptionist'] ?? 0 );
	$manager     = (int) ( $user_map['clinic_manager'] ?? 0 );
	$patient     = (int) ( $live['ids']['patient_id'] ?? 0 );
	cpsd_ts_check( $demo_clinic > 1 && $demo_org > 0, 'precondition: the fixture names a demo Clinic and Organization', $checks );
	cpsd_ts_check( $reception > 0 && $manager > 0 && $patient > 0, 'precondition: the fixture names its personas', $checks );

	$clean = $teardown->preview( $live );
	cpsd_ts_check( true === (bool) $clean['ok'], 'precondition: the fixture previews clean before the drill', $checks, implode( ' | ', (array) ( $clean['problems'] ?? array() ) ) );

	// ---- A: a REAL production REST write by a synthetic persona ------------
	$mobile = '0937' . (string) random_int( 1000000, 9999999 );
	wp_set_current_user( $reception );
	$_SERVER['REQUEST_METHOD']        = 'POST';
	$_SERVER['HTTP_X_CPMS_CLINIC_ID'] = (string) $demo_clinic;
	ScopedTenantGuard::begin_request();
	$request = new WP_REST_Request( 'POST', '/clinic/v1/patients' );
	$request->set_header( 'X-CPMS-Clinic-Id', (string) $demo_clinic );
	// A real REST request carries the logged-in user's REST nonce; the
	// permission callback rejects the write without it (CSRF), which is the
	// production behaviour this drill must not bypass.
	$request->set_header( 'X-WP-Nonce', (string) wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params(
		array(
			'first_name' => 'دوم',
			'last_name'  => 'بیمار نمایشی',
			'mobile'     => $mobile,
		)
	);
	$response = rest_do_request( $request );
	$status   = is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0;
	$payload  = is_object( $response ) && method_exists( $response, 'get_data' ) ? (array) $response->get_data() : array();
	ScopedTenantGuard::finish_request();
	unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CPMS_CLINIC_ID'] );
	cpsd_ts_check( in_array( $status, array( 200, 201 ), true ), 'A: the synthetic receptionist created a patient through the real REST API', $checks, 'HTTP ' . $status . ' ' . mb_substr( (string) wp_json_encode( $payload ), 0, 200 ) );
	$data       = (array) ( $payload['data'] ?? $payload );
	$patient_row = (array) ( $data['patient'] ?? $data );
	$new_patient = (int) ( $patient_row['id'] ?? 0 );
	cpsd_ts_check( $new_patient > 0, 'A: the real REST response names the new patient', $checks );
	$created = cpsd_ts_row( $t( 'cpms_patients' ), $new_patient );
	cpsd_ts_check( is_array( $created ) && (string) ( $created['mobile'] ?? '' ) === $mobile, 'A: the new patient exists with a DIFFERENT mobile', $checks, $mobile );
	$after_write = $teardown->preview( $manifest->load() );
	cpsd_ts_check( cpsd_ts_claimed( $after_write, 'cpms_patients', $new_patient ), 'A: the recorder registered the row inside the demo tenant', $checks, 'patient=' . $new_patient );
	cpsd_ts_check( true === (bool) $after_write['ok'], 'A: the recorded write is ATTRIBUTED - the preview stays clean', $checks, implode( ' | ', array_slice( (array) ( $after_write['problems'] ?? array() ), 0, 3 ) ) );
	$summary = $ownership->summary( (string) $live['fixture_id'] );
	cpsd_ts_check( true === (bool) ( $summary['ok'] ?? false ), 'A: the ownership ledger still verifies after the recorded write', $checks, (string) ( $summary['error'] ?? '' ) );
	$report['registered_patient'] = $new_patient;

	// ---- B: an UNREGISTERED grant to a REAL administrator ------------------
	$membership_table = $t( 'cpms_clinic_memberships' );
	$columns          = $platform->columns( $membership_table );
	$role_key         = (string) $wpdb->get_var( 'SELECT role_key FROM `' . $membership_table . '` WHERE clinic_id = ' . $demo_clinic . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; integer literal.
	if ( '' === $role_key ) {
		$role_key = 'cpms_manager';
	}
	// A user this drill creates itself, so "real" is not a guess: it is not a
	// manifest persona, not a fixture account, and nothing in the demo created it.
	$real_admin = (int) wp_insert_user(
		array(
			'user_login'   => 'ts-admin-' . strtolower( bin2hex( random_bytes( 3 ) ) ),
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'ts-admin-' . strtolower( bin2hex( random_bytes( 3 ) ) ) . '@example.test',
			'display_name' => 'real administrator',
			'role'         => 'administrator',
		)
	);
	cpsd_ts_check( $real_admin > 0, 'B: a real administrator was really created for the grant test', $checks );
	$b_rows = array(
		'clinic_id'  => $demo_clinic,
		'wp_user_id' => $real_admin,
		'role_key'   => $role_key,
		'scope_mode' => 'clinic',
		'status'     => 'active',
		'is_primary' => 0,
		'created_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
		'updated_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
	);
	if ( in_array( 'invited_by_wp_user_id', $columns, true ) ) {
		$b_rows['invited_by_wp_user_id'] = $manager;
	}
	if ( in_array( 'created_by_wp_user_id', $columns, true ) ) {
		$b_rows['created_by_wp_user_id'] = $manager;
	}
	$b_id = cpsd_ts_insert( $membership_table, (array) $b_rows );
	cpsd_ts_check( null !== cpsd_ts_row( $membership_table, $b_id ), 'B: the adversarial real-administrator membership was really created', $checks, 'id=' . $b_id );
	$b_preview = $teardown->preview( $manifest->load() );
	$b_diag = array(
		'row'         => cpsd_ts_row( $membership_table, $b_id ),
		'claimed_ids' => (array) ( $b_preview['scope']['tables']['cpms_clinic_memberships'] ?? array() ),
		'fixture_users' => (array) ( $live['ids']['user_ids'] ?? array() ),
		'manifest_admin' => (array) ( $live['admin_id'] ?? 0 ),
		'real_admin'  => $real_admin,
	);
	// The tenant scope lists every membership row that sits inside the demo
	// Clinic; the DELETABLE set is what attribution proved. The invariant is
	// therefore not "absent from scope" but "the preview refuses", and the
	// refusal must name this row rather than a generic total.
	cpsd_ts_check(
		false === (bool) $b_preview['ok'],
		'B: the preview REFUSES while an unregistered real administrator holds a grant',
		$checks,
		implode( ' | ', array_slice( (array) ( $b_preview['problems'] ?? array() ), 0, 3 ) )
	);
	cpsd_ts_check(
		cpsd_ts_problem( $b_preview, 'cpms_clinic_memberships:' . $b_id ) || cpsd_ts_problem( $b_preview, 'SCOPED_FOREIGN_TENANT_ROW' ),
		'B: the refusal names the real administrator\'s grant',
		$checks,
		mb_substr( (string) wp_json_encode( $b_diag ), 0, 900 )
	);
	cpsd_ts_check( cpsd_ts_problem( $b_preview, 'SCOPED_FOREIGN_TENANT_ROW:cpms_clinic_memberships:' . $b_id ), 'B: the teardown refuses and names the row', $checks, implode( ' | ', array_slice( (array) $b_preview['problems'], 0, 3 ) ) );
	$wpdb->delete( $membership_table, array( 'id' => $b_id ), array( '%d' ) );
	cpsd_ts_check( null === cpsd_ts_row( $membership_table, $b_id ) && true === (bool) $teardown->preview( $manifest->load() )['ok'], 'B: removing the adversarial grant restores a clean preview', $checks );
	$report['real_admin'] = $real_admin;

	// ---- C: a foreign tenant that overlaps everything ----------------------
	$foreign = cpsd_ts_plant_foreign( $platform, $t, $demo_org, $manager, $role_key );
	foreach ( $foreign as $key => $id ) {
		cpsd_ts_check( $id > 0, 'C: the foreign ' . $key . ' was really created', $checks, (string) $id );
	}
	$c_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( true === (bool) $c_preview['ok'], 'C: a foreign tenant does not block the demo teardown', $checks, implode( ' | ', (array) ( $c_preview['problems'] ?? array() ) ) );
	foreach ( array( 'organization', 'clinic', 'location', 'patient', 'visit', 'invoice', 'payment', 'membership' ) as $key ) {
		$short = array(
			'organization' => 'cpms_organizations',
			'clinic'     => 'cpms_clinics',
			'location'   => 'cpms_locations',
			'patient'    => 'cpms_patients',
			'visit'      => 'cpms_visits',
			'invoice'    => 'cpms_invoices',
			'payment'    => 'cpms_payments',
			'membership' => 'cpms_clinic_memberships',
		)[ $key ];
		cpsd_ts_check( ! cpsd_ts_claimed( $c_preview, $short, (int) $foreign[ $key ] ), 'C: the foreign ' . $key . ' is never claimed', $checks, $short . '#' . $foreign[ $key ] );
		$planted[ 'foreign_' . $key ] = array( 'table' => $t( $short ), 'id' => (int) $foreign[ $key ], 'hash' => cpsd_ts_hash( $t( $short ), (int) $foreign[ $key ] ) );
	}

	// ---- D: a cross-table numeric collision grants nothing -----------------
	$sealed_patient = cpsd_ts_claimed( $c_preview, 'cpms_patients', $new_patient ) ? $new_patient : $patient;
	cpsd_ts_check( $sealed_patient > 0, 'D: a sealed patient id is available for the collision', $checks, (string) $sealed_patient );
	$invoice_table = $t( 'cpms_invoices' );
	$collision_id  = 0;
	$candidate     = $sealed_patient;
	for ( $i = 0; $i < 50; $i++ ) {
		if ( null === cpsd_ts_row( $invoice_table, $candidate ) ) {
			$collision_id = $candidate;
			break;
		}
		++$candidate;
	}
	cpsd_ts_check( $collision_id > 0, 'D: a free invoice id equal to the sealed patient id exists', $checks, 'id=' . $candidate );
	$now_d = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$d_id  = cpsd_ts_insert(
		$invoice_table,
		array( 'id' => $collision_id, 'clinic_id' => $demo_clinic, 'invoice_number' => 'TSCOL' . strtoupper( bin2hex( random_bytes( 3 ) ) ), 'patient_id' => $sealed_patient, 'visit_id' => null, 'status' => 'open', 'subtotal' => 1, 'discount' => 0, 'tax' => 0, 'total' => 1, 'currency' => 'IRR', 'paid_amount' => 0, 'balance' => 1, 'created_at' => $now_d, 'updated_at' => $now_d )
	);
	cpsd_ts_check( $d_id === $collision_id && null !== cpsd_ts_row( $invoice_table, $d_id ), 'D: the colliding invoice really exists with the sealed patient id', $checks, 'invoice=' . $d_id );
	$d_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( false === (bool) $d_preview['ok'], 'D: the preview refuses a row that only shares a numeric id', $checks, implode( ' | ', array_slice( (array) ( $d_preview['problems'] ?? array() ), 0, 3 ) ) );
	cpsd_ts_check( cpsd_ts_problem( $d_preview, 'cpms_invoices:' . $d_id ) || cpsd_ts_problem( $d_preview, 'SCOPED_FOREIGN_TENANT_ROW' ), 'D: the collision is refused instead of assumed', $checks, implode( ' | ', array_slice( (array) $d_preview['problems'], 0, 3 ) ) );
	$wpdb->delete( $invoice_table, array( 'id' => $d_id ), array( '%d' ) );

	// ---- E: unknown ownership blocks destructive work ----------------------
	$visit_table = $t( 'cpms_visits' );
	$now_e       = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$e_id        = cpsd_ts_insert(
		$visit_table,
		array( 'clinic_id' => $demo_clinic, 'location_id' => (int) ( $live['ids']['location_ids'][0] ?? 0 ), 'clinician_id' => null, 'patient_id' => (int) $foreign['patient'], 'source' => 'walk_in', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now_e, 'created_at' => $now_e, 'updated_at' => $now_e )
	);
	cpsd_ts_check( null !== cpsd_ts_row( $visit_table, $e_id ), 'E: the unprovable visit inside the demo Clinic really exists', $checks, 'id=' . $e_id );
	$e_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( false === (bool) $e_preview['ok'], 'E: unknown ownership blocks the teardown before any destructive stage', $checks );
	cpsd_ts_check( cpsd_ts_problem( $e_preview, 'cpms_visits:' . $e_id ) || cpsd_ts_problem( $e_preview, 'SCOPED_FOREIGN_TENANT_ROW' ), 'E: the unprovable row is named by the refusal', $checks, implode( ' | ', array_slice( (array) ( $e_preview['problems'] ?? array() ), 0, 3 ) ) );
	cpsd_ts_check( null !== cpsd_ts_row( $t( 'cpms_patients' ), $patient ), 'E: the refused preview deleted nothing (the demo patient still exists)', $checks );
	$wpdb->delete( $visit_table, array( 'id' => $e_id ), array( '%d' ) );
	cpsd_ts_check( true === (bool) $teardown->preview( $manifest->load() )['ok'], 'E: removing the unprovable row restores a clean preview', $checks );

	// ---- F: an additional demo Clinic through the demo's own path ----------
	$clinics_service = new ScopedClinics( $env, $platform, $manifest, $ownership );
	$extra           = $clinics_service->create( $manifest->load(), 1 );
	$second_clinic   = (int) ( $extra['clinic_id'] ?? 0 );
	cpsd_ts_check( $second_clinic > 1 && $second_clinic !== $demo_clinic, 'F: the demo created a real additional Clinic', $checks, (string) $second_clinic );
	$summary_f = $ownership->summary( (string) $live['fixture_id'] );
	cpsd_ts_check( in_array( $second_clinic, array_map( 'intval', (array) ( $summary_f['clinic_ids'] ?? array() ) ), true ), 'F: the additional Clinic is sealed in the ownership ledger', $checks );
	// G (first half): a real user may not write into either demo Clinic.
	$real_user = wp_insert_user(
		array(
			'user_login'   => 'ts-real-' . strtolower( bin2hex( random_bytes( 3 ) ) ),
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'ts-real-' . strtolower( bin2hex( random_bytes( 3 ) ) ) . '@example.test',
			'display_name' => 'real staff',
			'role'         => 'subscriber',
		)
	);
	cpsd_ts_check( ! is_wp_error( $real_user ) && (int) $real_user > 0, 'G: a real WordPress user was really created for the boundary test', $checks, (string) ( is_wp_error( $real_user ) ? $real_user->get_error_message() : $real_user ) );
	wp_set_current_user( (int) $real_user );
	$verdict_demo = ScopedTenantGuard::write_verdict( 'POST', array( 'clinic_id' => $second_clinic ) );
	cpsd_ts_check( is_string( $verdict_demo ) && '' !== $verdict_demo, 'F: a real user is refused a write into the additional demo Clinic', $checks, (string) $verdict_demo );
	$verdict_demo_main = ScopedTenantGuard::write_verdict( 'POST', array( 'clinic_id' => $demo_clinic ) );
	cpsd_ts_check( is_string( $verdict_demo_main ) && '' !== $verdict_demo_main, 'G: a real user is refused a write into the demo Clinic', $checks, (string) $verdict_demo_main );
	// G (second half): the same real user keeps authority in a foreign tenant.
	$verdict_foreign = ScopedTenantGuard::write_verdict( 'POST', array( 'clinic_id' => (int) $foreign['clinic'] ) );
	cpsd_ts_check( true === $verdict_foreign, 'G: a real user keeps normal authority in a foreign Clinic', $checks, var_export( $verdict_foreign, true ) );
	wp_set_current_user( 1 );

	// ---- H: corrupt ownership metadata fails closed ------------------------
	$ledger_option = ScopedOwnership::OPTION;
	$seal_option   = ScopedOwnership::SEAL_OPTION;
	$ledger_backup = get_option( $ledger_option );
	$seal_backup   = get_option( $seal_option );
	cpsd_ts_check( is_array( $ledger_backup ), 'H: the ownership ledger exists to corrupt', $checks );
	$corrupt = $ledger_backup;
	if ( isset( $corrupt['entries'][0]['payload'] ) && is_array( $corrupt['entries'][0]['payload'] ) ) {
		$corrupt['entries'][0]['payload']['clinic_id'] = 999999;
	} else {
		$corrupt['tampered'] = 1;
	}
	update_option( $ledger_option, $corrupt, false );
	$h_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( false === (bool) $h_preview['ok'], 'H: a corrupted ledger fails closed', $checks );
	cpsd_ts_check( cpsd_ts_problem( $h_preview, 'SCOPED_OWNERSHIP' ), 'H: the refusal names the ownership problem', $checks, implode( ' | ', array_slice( (array) $h_preview['problems'], 0, 3 ) ) );
	cpsd_ts_check( array() === array_filter( (array) ( $h_preview['scope']['tables']['cpms_patients'] ?? array() ) ), 'H: nothing is claimed while the ledger is corrupt', $checks );
	cpsd_ts_check( null !== cpsd_ts_row( $t( 'cpms_patients' ), $patient ), 'H: the corrupt preview deleted nothing', $checks );
	update_option( $ledger_option, $ledger_backup, false );
	if ( false !== $seal_backup ) {
		update_option( $seal_option, $seal_backup, false );
	}
	$restored = $teardown->preview( $manifest->load() );
	cpsd_ts_check( true === (bool) $restored['ok'], 'H: restoring the ledger restores a clean preview', $checks, implode( ' | ', (array) ( $restored['problems'] ?? array() ) ) );

	// ---- I: the refused/corrupt episodes preserved unrelated data ----------
	cpsd_ts_check( ( count( (array) ( $restored['scope']['tables']['cpms_patients'] ?? array() ) ) ) >= 2, 'I: the demo still owns its rows after every refusal', $checks );
	foreach ( $planted as $key => $entry ) {
		cpsd_ts_check( cpsd_ts_hash( (string) $entry['table'], (int) $entry['id'] ) === (string) $entry['hash'], 'I: ' . $key . ' is untouched by the refused operations', $checks );
	}
	$audit_i = $audit_guard->verify_chain();
	cpsd_ts_check( true === (bool) ( $audit_i['ok'] ?? false ), 'I: the audit chain still verifies after the adversarial episodes', $checks, (string) ( $audit_i['error'] ?? '' ) );

	$report['demo_clinic']  = $demo_clinic;
	$report['second_clinic'] = $second_clinic;
	$report['host_user']    = (int) $real_user;
	$report['planted']      = $planted;
	file_put_contents( $cpsd_ts_file, (string) wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	echo (string) wp_json_encode( array( 'ok' => true, 'mode' => $cpsd_ts_mode, 'checks' => $checks, 'evidence' => array( 'demo_clinic' => $demo_clinic, 'second_clinic' => $second_clinic, 'planted' => count( $planted ) ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'     => false,
			'mode'   => $cpsd_ts_mode,
			'checks' => $checks,
			'error'  => get_class( $error ) . ': ' . $error->getMessage(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}
